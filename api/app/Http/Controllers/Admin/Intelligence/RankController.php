<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Intelligence;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin · Intelligence · Disease Ranking (intel-rank) — REBUILT 2026-04-26.
 * ---------------------------------------------------------------------------
 * The previous controller read EXCLUSIVELY from `secondary_suspected_diseases`.
 * In production that table is populated by an intelligence engine that does
 * not always run — meaning the page rendered blank even with hundreds of
 * secondary screenings carrying syndromic signal. We now read from two
 * sources, in priority order:
 *
 *   1. `secondary_screenings.syndrome_classification` — always populated by
 *      the screener, holds the WHO-aligned syndrome code (VHF, SARI, ILI,
 *      AWD, JAUNDICE, NEUROLOGICAL, RASH_FEVER, OTHER). This is the
 *      primary leaderboard.
 *   2. `secondary_suspected_diseases` (rank_order = 1) — the ranked output
 *      of the intelligence engine. Activates the "Disease drill-down"
 *      panel + confidence bands only when there are rows in the window.
 *
 * Mobile contract: NONE. Read-only analytics.
 * Gate: NATIONAL_ADMIN (enforced in routes/web.php).
 */
final class RankController extends Controller
{
    /** Buckets we expose: rolling windows ending now. */
    private const WINDOWS = [
        '7d'  => 7,
        '14d' => 14,
        '30d' => 30,
    ];

    /** Display labels for syndrome codes — same vocabulary the screener uses. */
    private const SYNDROME_LABELS = [
        'VHF'          => 'Viral Haemorrhagic Fever',
        'SARI'         => 'Severe Acute Respiratory Infection',
        'ILI'          => 'Influenza-like Illness',
        'AWD'          => 'Acute Watery Diarrhoea',
        'JAUNDICE'     => 'Acute Jaundice Syndrome',
        'NEUROLOGICAL' => 'Acute Neurological Syndrome',
        'RASH_FEVER'   => 'Rash & Fever',
        'OTHER'        => 'Other / Unclassified',
    ];

    public function index(Request $request)
    {
        return view('admin.intelligence.rank.index', [
            'page_title'    => 'Disease Ranking',
            'page_eyebrow'  => 'Intelligence',
            'page_subtitle' => 'Rolling 7 / 14 / 30-day signal · syndromes & ranked diseases · trend.',
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $now = Carbon::now();

            // Soft-fail when the screening table is missing — should never happen
            // in prod but keeps the page renderable in test environments.
            if (! Schema::hasTable('secondary_screenings')) {
                return response()->json(['ok' => true, 'data' => $this->emptyPayload($now)]);
            }

            $hasSsd       = Schema::hasTable('secondary_suspected_diseases');
            $ssdHasRows   = $hasSsd && DB::table('secondary_suspected_diseases')->limit(1)->exists();

            // ── KPI buckets ────────────────────────────────────────────────
            $buckets     = [];
            $prevBuckets = [];
            foreach (self::WINDOWS as $label => $days) {
                $buckets[$label]     = $this->bucket($now->copy()->subDays($days),         $now);
                $prevBuckets[$label] = $this->bucket($now->copy()->subDays($days * 2),     $now->copy()->subDays($days));
            }

            // ── Syndrome leaderboard (PRIMARY · always populated) ─────────
            $syndromes = $this->syndromeLeaderboard($now);

            // ── Disease leaderboard (SECONDARY · only when engine has run) ─
            $diseases     = $ssdHasRows ? $this->diseaseLeaderboard($now) : [];
            $bandsTotal   = $ssdHasRows ? $this->confidenceBands($now)    : ['high' => 0, 'medium' => 0, 'low' => 0];
            $diseaseTopCodes = array_column($diseases, 'disease_code');
            $diseaseTopCodes = array_slice($diseaseTopCodes, 0, 5);

            // ── Trend (last 30 days · top 5 syndromes) ─────────────────────
            // The trend is always for syndromes — that's the source we trust to
            // be populated. If the disease engine has run, an additional disease
            // trend is exposed alongside.
            $topSyndromes = array_slice(array_column($syndromes, 'code'), 0, 5);
            $syndromeTrend = $this->dailyTrend(
                $now->copy()->subDays(30), $now,
                $topSyndromes, 'syndrome_classification', 'ss'
            );
            $diseaseTrend = $ssdHasRows && $diseaseTopCodes
                ? $this->diseaseDailyTrend($now->copy()->subDays(30), $now, $diseaseTopCodes)
                : [];

            return response()->json(['ok' => true, 'data' => [
                'server_time'       => $now->toIso8601String(),
                'engine_populated'  => $ssdHasRows,
                'buckets'           => $buckets,
                'prev_buckets'      => $prevBuckets,
                'syndromes'         => $syndromes,
                'top_syndromes'     => $topSyndromes,
                'syndrome_trend'    => $syndromeTrend,
                'diseases'          => $diseases,
                'top_diseases'      => $diseaseTopCodes,
                'disease_trend'     => $diseaseTrend,
                'bands'             => $bandsTotal,
            ]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /* =================================================================
     * Bucket helpers
     * ================================================================= */

    /**
     * Window-level rollup over secondary_screenings — one round-trip rather
     * than three (the previous controller hit the DB three times per bucket).
     */
    private function bucket(Carbon $from, Carbon $to): array
    {
        $row = DB::table('secondary_screenings as ss')
            ->whereNull('ss.deleted_at')
            ->whereBetween('ss.created_at', [$from, $to])
            ->selectRaw(
                'COUNT(*) as n, '
                . 'COUNT(DISTINCT ss.syndrome_classification) as distinct_syndromes, '
                . 'SUM(CASE WHEN ss.risk_level = "CRITICAL" THEN 1 ELSE 0 END) as critical_n, '
                . 'SUM(CASE WHEN ss.risk_level = "HIGH"     THEN 1 ELSE 0 END) as high_n'
            )
            ->first();

        return [
            'n'                  => (int) ($row->n ?? 0),
            'distinct_syndromes' => (int) ($row->distinct_syndromes ?? 0),
            'critical_n'         => (int) ($row->critical_n ?? 0),
            'high_n'             => (int) ($row->high_n ?? 0),
        ];
    }

    /**
     * Syndrome leaderboard over the last 30 days, with delta vs prior 30 days
     * and risk-level mix per syndrome. Always populated when there are
     * secondary screenings in scope.
     */
    private function syndromeLeaderboard(Carbon $now): array
    {
        $from30 = $now->copy()->subDays(30);
        $from60 = $now->copy()->subDays(60);

        $current = DB::table('secondary_screenings as ss')
            ->whereNull('ss.deleted_at')
            ->whereBetween('ss.created_at', [$from30, $now])
            ->whereNotNull('ss.syndrome_classification')
            ->where('ss.syndrome_classification', '!=', '')
            ->selectRaw(
                'ss.syndrome_classification as code, '
                . 'COUNT(*) as n, '
                . 'SUM(CASE WHEN ss.risk_level = "CRITICAL" THEN 1 ELSE 0 END) as critical_n, '
                . 'SUM(CASE WHEN ss.risk_level = "HIGH"     THEN 1 ELSE 0 END) as high_n, '
                . 'SUM(CASE WHEN ss.risk_level = "MEDIUM"   THEN 1 ELSE 0 END) as medium_n, '
                . 'SUM(CASE WHEN ss.risk_level = "LOW"      THEN 1 ELSE 0 END) as low_n'
            )
            ->groupBy('ss.syndrome_classification')
            ->get();

        $previous = DB::table('secondary_screenings as ss')
            ->whereNull('ss.deleted_at')
            ->whereBetween('ss.created_at', [$from60, $from30])
            ->whereNotNull('ss.syndrome_classification')
            ->where('ss.syndrome_classification', '!=', '')
            ->selectRaw('ss.syndrome_classification as code, COUNT(*) as n')
            ->groupBy('ss.syndrome_classification')
            ->pluck('n', 'code')->all();

        $rows = [];
        foreach ($current as $r) {
            $code  = (string) $r->code;
            $prev  = (int) ($previous[$code] ?? 0);
            $delta = $prev > 0
                ? round((((int) $r->n - $prev) / $prev) * 100, 1)
                : ((int) $r->n > 0 ? 100.0 : 0.0);
            $rows[] = [
                'code'         => $code,
                'display_name' => self::SYNDROME_LABELS[$code] ?? $code,
                'n'            => (int) $r->n,
                'critical'     => (int) $r->critical_n,
                'high'         => (int) $r->high_n,
                'medium'       => (int) $r->medium_n,
                'low'          => (int) $r->low_n,
                'prev_30d'     => $prev,
                'delta_pct'    => $delta,
                'is_priority'  => in_array($code, ['VHF', 'SARI', 'NEUROLOGICAL', 'JAUNDICE'], true),
            ];
        }
        usort($rows, fn ($a, $b) => $b['n'] <=> $a['n']);
        return $rows;
    }

    /**
     * Disease-code leaderboard from secondary_suspected_diseases (rank_order = 1)
     * over the last 30 days, joined to ref_diseases for display name and IHR tier.
     * Only invoked when secondary_suspected_diseases has rows.
     */
    private function diseaseLeaderboard(Carbon $now): array
    {
        $from30 = $now->copy()->subDays(30);
        $from60 = $now->copy()->subDays(60);

        // Note: ref_diseases.disease_code and secondary_suspected_diseases.disease_code
        // were created with different default collations (utf8mb4_0900_ai_ci vs
        // utf8mb4_unicode_ci). Force a single collation in the join predicate so
        // MySQL does not throw "Illegal mix of collations".
        $ranking = DB::table('secondary_suspected_diseases as ssd')
            ->join('secondary_screenings as ss', 'ss.id', '=', 'ssd.secondary_screening_id')
            ->leftJoin('ref_diseases as d', function ($j) {
                $j->on(
                    DB::raw('d.disease_code COLLATE utf8mb4_unicode_ci'),
                    '=',
                    DB::raw('ssd.disease_code COLLATE utf8mb4_unicode_ci'),
                );
            })
            ->whereNull('ss.deleted_at')
            ->whereBetween('ss.created_at', [$from30, $now])
            ->where('ssd.rank_order', 1)
            ->selectRaw(
                'ssd.disease_code, '
                . 'd.display_name, d.ihr_tier, d.who_syndrome, '
                . 'COUNT(*) AS n, '
                . 'AVG(ssd.confidence) AS avg_conf, '
                . 'SUM(CASE WHEN ssd.confidence >= 80 THEN 1 ELSE 0 END) AS high_n, '
                . 'SUM(CASE WHEN ssd.confidence >= 50 AND ssd.confidence < 80 THEN 1 ELSE 0 END) AS medium_n, '
                . 'SUM(CASE WHEN ssd.confidence < 50 THEN 1 ELSE 0 END) AS low_n'
            )
            ->groupBy('ssd.disease_code', 'd.display_name', 'd.ihr_tier', 'd.who_syndrome')
            ->orderByDesc('n')
            ->limit(20)
            ->get();

        $previous = DB::table('secondary_suspected_diseases as ssd')
            ->join('secondary_screenings as ss', 'ss.id', '=', 'ssd.secondary_screening_id')
            ->whereNull('ss.deleted_at')
            ->whereBetween('ss.created_at', [$from60, $from30])
            ->where('ssd.rank_order', 1)
            ->selectRaw('ssd.disease_code, COUNT(*) as n')
            ->groupBy('ssd.disease_code')
            ->pluck('n', 'disease_code')->all();

        return $ranking->map(function ($r) use ($previous) {
            $code  = (string) $r->disease_code;
            $prev  = (int) ($previous[$code] ?? 0);
            $delta = $prev > 0
                ? round((((int) $r->n - $prev) / $prev) * 100, 1)
                : ((int) $r->n > 0 ? 100.0 : 0.0);
            return [
                'disease_code' => $code,
                'display_name' => (string) ($r->display_name ?? $code),
                'who_syndrome' => (string) ($r->who_syndrome ?? ''),
                'ihr_tier'     => $r->ihr_tier !== null ? (int) $r->ihr_tier : null,
                'n'            => (int) $r->n,
                'avg_conf'     => $r->avg_conf !== null ? round((float) $r->avg_conf, 1) : null,
                'high'         => (int) $r->high_n,
                'medium'       => (int) $r->medium_n,
                'low'          => (int) $r->low_n,
                'prev_30d'     => $prev,
                'delta_pct'    => $delta,
            ];
        })->all();
    }

    private function confidenceBands(Carbon $now): array
    {
        $from30 = $now->copy()->subDays(30);
        $row = DB::table('secondary_suspected_diseases as ssd')
            ->join('secondary_screenings as ss', 'ss.id', '=', 'ssd.secondary_screening_id')
            ->whereNull('ss.deleted_at')
            ->whereBetween('ss.created_at', [$from30, $now])
            ->where('ssd.rank_order', 1)
            ->selectRaw(
                'SUM(CASE WHEN ssd.confidence >= 80 THEN 1 ELSE 0 END) as high_n, '
                . 'SUM(CASE WHEN ssd.confidence >= 50 AND ssd.confidence < 80 THEN 1 ELSE 0 END) as medium_n, '
                . 'SUM(CASE WHEN ssd.confidence < 50 THEN 1 ELSE 0 END) as low_n'
            )
            ->first();
        return [
            'high'   => (int) ($row->high_n ?? 0),
            'medium' => (int) ($row->medium_n ?? 0),
            'low'    => (int) ($row->low_n ?? 0),
        ];
    }

    /**
     * Daily trend over a window for a list of code values from a given column.
     * Returns an array of { day: 'YYYY-mm-dd', <code>: int, ... } objects,
     * one entry per day in the window even where no rows landed.
     */
    private function dailyTrend(Carbon $from, Carbon $to, array $codes, string $column, string $alias): array
    {
        if (empty($codes)) return [];

        $rows = DB::table("secondary_screenings as {$alias}")
            ->whereNull("{$alias}.deleted_at")
            ->whereBetween("{$alias}.created_at", [$from, $to])
            ->whereIn("{$alias}.{$column}", $codes)
            ->selectRaw("DATE({$alias}.created_at) as d, {$alias}.{$column} as code, COUNT(*) as n")
            ->groupBy('d', 'code')
            ->get();

        $byDay = [];
        $cursor = $from->copy()->startOfDay();
        $end    = $to->copy()->endOfDay();
        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m-d');
            $byDay[$key] = ['day' => $key];
            foreach ($codes as $c) { $byDay[$key][$c] = 0; }
            $cursor->addDay();
        }
        foreach ($rows as $r) {
            $k = (string) $r->d;
            if (! isset($byDay[$k])) continue;
            $byDay[$k][(string) $r->code] = (int) $r->n;
        }
        return array_values($byDay);
    }

    private function diseaseDailyTrend(Carbon $from, Carbon $to, array $codes): array
    {
        if (empty($codes)) return [];

        $rows = DB::table('secondary_suspected_diseases as ssd')
            ->join('secondary_screenings as ss', 'ss.id', '=', 'ssd.secondary_screening_id')
            ->whereNull('ss.deleted_at')
            ->whereBetween('ss.created_at', [$from, $to])
            ->where('ssd.rank_order', 1)
            ->whereIn('ssd.disease_code', $codes)
            ->selectRaw('DATE(ss.created_at) as d, ssd.disease_code as code, COUNT(*) as n')
            ->groupBy('d', 'code')
            ->get();

        $byDay = [];
        $cursor = $from->copy()->startOfDay();
        $end    = $to->copy()->endOfDay();
        while ($cursor->lte($end)) {
            $key = $cursor->format('Y-m-d');
            $byDay[$key] = ['day' => $key];
            foreach ($codes as $c) { $byDay[$key][$c] = 0; }
            $cursor->addDay();
        }
        foreach ($rows as $r) {
            $k = (string) $r->d;
            if (! isset($byDay[$k])) continue;
            $byDay[$k][(string) $r->code] = (int) $r->n;
        }
        return array_values($byDay);
    }

    private function emptyPayload(Carbon $now): array
    {
        $emptyBucket = ['n' => 0, 'distinct_syndromes' => 0, 'critical_n' => 0, 'high_n' => 0];
        return [
            'server_time'       => $now->toIso8601String(),
            'engine_populated'  => false,
            'buckets'           => ['7d' => $emptyBucket, '14d' => $emptyBucket, '30d' => $emptyBucket],
            'prev_buckets'      => ['7d' => $emptyBucket, '14d' => $emptyBucket, '30d' => $emptyBucket],
            'syndromes'         => [],
            'top_syndromes'     => [],
            'syndrome_trend'    => [],
            'diseases'          => [],
            'top_diseases'      => [],
            'disease_trend'     => [],
            'bands'             => ['high' => 0, 'medium' => 0, 'low' => 0],
        ];
    }
}
