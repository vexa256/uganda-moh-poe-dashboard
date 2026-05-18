<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\Insights\SuspectedDiseaseAnalyticsInsightEngine;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R9 · rpt-suspected-disease-analytics — Suspected Disease Analytics
 *
 * Premium chassis (rebuild 2026-04-26): the payload preserves every key the
 * SuspectedDiseaseAnalyticsInsightEngine reads (kpis.total_suspected,
 * kpis.unique_diseases, tripwires.vhf_cluster, tripwires.cholera_doubling)
 * and adds only the fields the lean 3-tab view actually renders:
 *   • per-disease who_syndrome tag (for VHF ring)
 *   • per-disease 12-week sparkline + top_poe + top_age_band
 *   • per-disease confirmed count (alerts join — closes the lab loop)
 *   • POE × top-disease concentration matrix
 *   • vhf_summary block + lab_loop block
 * Per the discipline rule: nothing speculative is exposed. The view answers
 * 4 questions: did anything fire / what is hot / where is it / lab status.
 */
final class SuspectedDiseaseAnalyticsController extends BaseReportController
{
    protected string $reportKey   = 'rpt-suspected-disease-analytics';
    protected string $reportTitle = 'Suspected Disease Analytics';

    /** Stable cap on the disease leaderboard (rest grouped into "Other"). */
    private const TOP_DISEASES = 15;

    /** Number of weeks rendered in each per-disease sparkline. */
    private const SPARKLINE_WEEKS = 12;

    /** POE × disease heatmap is bounded so the matrix stays scannable. */
    private const HEATMAP_TOP_POES    = 8;
    private const HEATMAP_TOP_DISEASE = 8;

    public function __construct(
        ReportScope $scope,
        ReportAccess $access,
        ExportWriter $writer,
        protected SuspectedDiseaseAnalyticsInsightEngine $engine,
    ) {
        parent::__construct($scope, $access, $writer);
    }

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);

        return view('admin.reports.rpt-suspected-disease-analytics.index', [
            'scope'       => $scope,
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
            'dataNotes'   => $this->dataNotes(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->readFilters($request);

        // Default window: past 7 days when no other temporal filter is set.
        if (empty($filters['start_date']) && empty($filters['end_date']) && empty($filters['year']) && empty($filters['quarter']) && empty($filters['month'])) {
            $filters['default_days'] = 7;
        }

        $payload = $this->memoise(
            (int) ($scope['user_id'] ?? 0),
            $filters + ['__r' => 'r9'],
            fn () => $this->buildPayload($scope, $filters),
        );

        $payload['insights']   = $this->engine->evaluate($payload);
        $payload['filters']    = $filters;
        $payload['scope']      = [
            'label' => $scope['label']       ?? '—',
            'level' => $scope['scope_level'] ?? 'SELF',
        ];
        $payload['data_notes'] = $this->dataNotes();

        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->readFilters($request);
        $payload = $this->buildPayload($scope, $filters);

        $headers = ['Disease', 'Syndrome', 'Suspected', 'Confirmed', 'Confirmation %', 'POEs', 'Districts', 'Top POE', 'Latest detected'];
        $rows = [];
        foreach ($payload['top_diseases'] as $r) {
            $rows[] = [
                $r['disease_code'],
                $r['who_syndrome']     ?? '—',
                $r['count']            ?? 0,
                $r['confirmed']        ?? 0,
                $r['confirmation_pct'] ?? 0,
                $r['poes']             ?? 0,
                $r['districts']        ?? 0,
                $r['top_poe']          ?? '—',
                $r['latest']           ?? '—',
            ];
        }

        return $this->writer->send(
            $this->reportKey,
            (string) $request->input('format', 'CSV'),
            $headers, $rows, $filters,
            (int) ($scope['user_id'] ?? 0),
            $this->reportTitle,
        );
    }

    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($filters);

        // ── Cohort (single hit, then derive in PHP) ───────────────────────
        $secQ = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->where('sync_status', '!=', 'FAILED')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($secQ, $scope);

        if (! empty($filters['poe'])) {
            $poes = is_array($filters['poe']) ? $filters['poe'] : explode(',', (string) $filters['poe']);
            $secQ->whereIn('poe_code', array_filter($poes));
        }

        $sec = $secQ->select([
            'id', 'poe_code', 'province_code', 'district_code',
            'opened_at', 'case_status', 'traveler_age_years',
        ])->get();
        $secIds = $sec->pluck('id')->all();

        // ── Related rows (one query each, indexed in PHP) ─────────────────
        $diseases = $secIds ? DB::table('secondary_suspected_diseases')
            ->whereIn('secondary_screening_id', $secIds)
            ->select('secondary_screening_id', 'disease_code', 'rank_order', 'confidence')
            ->get() : collect();

        $alertRows = $secIds ? DB::table('alerts')
            ->whereIn('secondary_screening_id', $secIds)
            ->whereNull('deleted_at')
            ->select('secondary_screening_id', 'status', 'ihr_tier')
            ->get() : collect();

        // ref_diseases lookup for VHF/syndrome metadata used by VHF ring + tripwires.
        $diseaseRef = DB::table('ref_diseases')
            ->select('disease_code', 'who_syndrome')
            ->get()->keyBy('disease_code');

        $poeNames = [];
        $poeCodes = $sec->pluck('poe_code')->filter()->unique()->values()->all();
        if ($poeCodes) {
            $poeNames = DB::table('ref_poes')
                ->whereIn('poe_code', $poeCodes)
                ->pluck('poe_name', 'poe_code')->all();
        }

        // ── Confirmation set (notifiable + closed alerts) ─────────────────
        $confirmedSet = [];
        foreach ($alertRows as $a) {
            if ($a->ihr_tier !== null && $a->status === 'CLOSED') {
                $confirmedSet[(int) $a->secondary_screening_id] = true;
            }
        }

        // ── Index helpers ────────────────────────────────────────────────
        $screeningById = $sec->keyBy('id');

        $diseasesByScreening = [];
        foreach ($diseases as $d) {
            $diseasesByScreening[(int) $d->secondary_screening_id][] = $d->disease_code;
        }

        // Build week ladder + per-disease weekly counts (full window for trend
        // series; the per-disease sparkline pulls only the trailing 12 weeks).
        $weeks = [];
        $weekLabels = [];
        $cur = $from->copy()->startOfWeek();
        while ($cur->lte($to)) {
            $weeks[]      = $cur->format('Y-\WW');
            $weekLabels[] = $cur->copy()->endOfWeek()->toDateString();
            $cur->addWeek();
        }

        $weeklyByDisease  = [];
        $weeklyByDistrict = [];
        $byDisease        = [];
        $byPoe            = [];
        $byDistrict       = [];
        $cooccurrence     = [];
        $ageByDisease     = [];

        $totalSuspected = 0;
        $totalConfirmed = 0;
        $totalPending   = 0;

        foreach ($diseasesByScreening as $sid => $codes) {
            $codes = array_values(array_unique($codes));
            $row   = $screeningById->get($sid);
            $week  = $row?->opened_at
                ? Carbon::parse((string) $row->opened_at)->startOfWeek()->format('Y-\WW')
                : null;
            $confirmed = isset($confirmedSet[$sid]);
            $pending   = $row && in_array($row->case_status, ['OPEN', 'IN_PROGRESS'], true);

            $totalSuspected++;
            if ($confirmed) { $totalConfirmed++; }
            if ($pending)   { $totalPending++; }

            foreach ($codes as $code) {
                $byDisease[$code] ??= [
                    'disease_code' => $code,
                    'who_syndrome' => $diseaseRef->get($code)->who_syndrome ?? null,
                    'count'        => 0,
                    'confirmed'    => 0,
                    'pending'      => 0,
                    'poes'         => [],
                    'districts'    => [],
                    'latest'       => null,
                    'poe_counts'   => [],
                    'age_bands'    => [],
                ];
                $byDisease[$code]['count']++;
                if ($confirmed) { $byDisease[$code]['confirmed']++; }
                if ($pending)   { $byDisease[$code]['pending']++; }
                if ($row?->poe_code) {
                    $byDisease[$code]['poes'][$row->poe_code] = true;
                    $byDisease[$code]['poe_counts'][$row->poe_code] =
                        ($byDisease[$code]['poe_counts'][$row->poe_code] ?? 0) + 1;
                }
                if ($row?->district_code) {
                    $byDisease[$code]['districts'][$row->district_code] = true;
                }
                if ($row?->opened_at && (! $byDisease[$code]['latest'] || (string) $row->opened_at > $byDisease[$code]['latest'])) {
                    $byDisease[$code]['latest'] = (string) $row->opened_at;
                }
                $age = (int) ($row?->traveler_age_years ?? 0);
                $band = $this->ageBand($age);
                $byDisease[$code]['age_bands'][$band] =
                    ($byDisease[$code]['age_bands'][$band] ?? 0) + 1;

                // Geographic rollups.
                $poeKey = $row?->poe_code ?? 'UNASSIGNED';
                $byPoe[$poeKey][$code] = ($byPoe[$poeKey][$code] ?? 0) + 1;
                $districtKey = $row?->district_code ?? 'UNASSIGNED';
                $byDistrict[$districtKey][$code] = ($byDistrict[$districtKey][$code] ?? 0) + 1;

                // Weekly buckets.
                if ($week) {
                    $weeklyByDisease[$code][$week] = ($weeklyByDisease[$code][$week] ?? 0) + 1;
                    if ($row?->district_code) {
                        $weeklyByDistrict[$row->district_code][$code][$week] =
                            ($weeklyByDistrict[$row->district_code][$code][$week] ?? 0) + 1;
                    }
                }

                // Age × disease (kept for drill-down).
                $ageByDisease[$code][$band] = ($ageByDisease[$code][$band] ?? 0) + 1;
            }

            // Co-suspicion pairs (only same-case combinations).
            $n = count($codes);
            for ($i = 0; $i < $n; $i++) {
                for ($j = $i + 1; $j < $n; $j++) {
                    $pair = [$codes[$i], $codes[$j]];
                    sort($pair);
                    $key = implode('|', $pair);
                    $cooccurrence[$key] = ($cooccurrence[$key] ?? 0) + 1;
                }
            }
        }

        // ── Resolve top-POE + top-age band per disease, derive sparkline ──
        $sparklineWeeks = array_slice($weeks, -self::SPARKLINE_WEEKS);
        foreach ($byDisease as $code => &$row) {
            // top POE chip
            arsort($row['poe_counts']);
            $topPoeCode = array_key_first($row['poe_counts']) ?: null;
            $row['top_poe']       = $topPoeCode ? ($poeNames[$topPoeCode] ?? $topPoeCode) : null;
            $row['top_poe_count'] = $topPoeCode ? $row['poe_counts'][$topPoeCode]        : 0;

            // top age band chip
            arsort($row['age_bands']);
            $topBand = array_key_first($row['age_bands']) ?: null;
            $row['top_age_band']       = $topBand;
            $row['top_age_band_count'] = $topBand ? $row['age_bands'][$topBand] : 0;

            // sparkline — last 12 weeks
            $row['sparkline'] = [];
            foreach ($sparklineWeeks as $w) {
                $row['sparkline'][] = (int) ($weeklyByDisease[$code][$w] ?? 0);
            }

            // confirmation rate (suppress if denominator < 5 for stability)
            $row['confirmation_pct'] = $row['count'] >= 5
                ? round(($row['confirmed'] / max(1, $row['count'])) * 100, 1)
                : null;

            // collapse maps to counts for transport
            $row['poes']      = count($row['poes']);
            $row['districts'] = count($row['districts']);
            unset($row['poe_counts']);
            unset($row['age_bands']);
        }
        unset($row);

        $byDisease = array_values($byDisease);
        usort($byDisease, fn ($a, $b) => $b['count'] <=> $a['count']);
        $top = array_slice($byDisease, 0, self::TOP_DISEASES);

        // ── VHF burden ───────────────────────────────────────────────────
        $vhfTotal       = 0;
        $vhfDiseases    = [];
        $vhfBreakdown   = [];
        foreach ($byDisease as $d) {
            $isVhf = $d['who_syndrome'] && stripos((string) $d['who_syndrome'], 'VHF') !== false;
            if (! $isVhf) { continue; }
            $vhfTotal += (int) $d['count'];
            $vhfDiseases[] = $d['disease_code'];
            $vhfBreakdown[] = [
                'disease_code' => $d['disease_code'],
                'count'        => $d['count'],
                'top_poe'      => $d['top_poe'],
            ];
        }
        usort($vhfBreakdown, fn ($a, $b) => $b['count'] <=> $a['count']);

        // ── Trend series (top 5 diseases — kept for drill-down) ──────────
        $topCodes = array_slice(array_column($top, 'disease_code'), 0, 5);
        $trend = [];
        foreach ($topCodes as $c) {
            $trend[$c] = array_fill_keys($weeks, 0);
            foreach ($weeks as $w) {
                $trend[$c][$w] = (int) ($weeklyByDisease[$c][$w] ?? 0);
            }
        }

        // Per-disease 12-week trend (for the per-disease drill-down panel)
        $diseaseTrend = [];
        foreach ($top as $d) {
            $code = $d['disease_code'];
            $diseaseTrend[$code] = [];
            foreach ($sparklineWeeks as $w) {
                $diseaseTrend[$code][] = (int) ($weeklyByDisease[$code][$w] ?? 0);
            }
        }

        // ── POE × Disease heatmap (concentration view) ───────────────────
        $heatPoeCodes = array_keys($byPoe);
        usort($heatPoeCodes, function ($a, $b) use ($byPoe) {
            return array_sum($byPoe[$b]) <=> array_sum($byPoe[$a]);
        });
        $heatPoeCodes = array_slice($heatPoeCodes, 0, self::HEATMAP_TOP_POES);
        $heatDiseaseCodes = array_slice(array_column($top, 'disease_code'), 0, self::HEATMAP_TOP_DISEASE);

        $heatmapPoes = [];
        foreach ($heatPoeCodes as $pc) {
            $heatmapPoes[] = [
                'code' => $pc,
                'name' => $poeNames[$pc] ?? $pc,
                'total'=> array_sum($byPoe[$pc] ?? []),
            ];
        }
        $heatmap = [];
        foreach ($heatPoeCodes as $pc) {
            foreach ($heatDiseaseCodes as $dc) {
                $heatmap[$pc][$dc] = (int) ($byPoe[$pc][$dc] ?? 0);
            }
        }

        // ── Co-suspicion pairs (≥3 occurrences only — drop noise) ────────
        $cosuspect = [];
        foreach ($cooccurrence as $pair => $count) {
            if ($count < 3) { continue; }
            [$a, $b] = explode('|', $pair);
            $cosuspect[] = ['a' => $a, 'b' => $b, 'count' => $count];
        }
        usort($cosuspect, fn ($x, $y) => $y['count'] <=> $x['count']);

        // ── Tripwires (engine-contract input) ────────────────────────────
        $tripwires = $this->runTripwires($diseasesByScreening, $screeningById, $diseaseRef, $weeklyByDistrict);

        // ── Aggregated KPIs ──────────────────────────────────────────────
        $confirmationPct = $totalSuspected > 0
            ? round($totalConfirmed / $totalSuspected * 100, 1)
            : 0.0;

        // Lead disease (top of leaderboard) for the "Top disease" KPI tile.
        $leadDisease     = $top[0]['disease_code'] ?? null;
        $leadCount       = $top[0]['count']        ?? 0;
        $leadIsVhf       = $top[0]['who_syndrome'] ?? null;
        $leadIsVhf       = $leadIsVhf && stripos((string) $leadIsVhf, 'VHF') !== false;
        $tripwireTotal   = count($tripwires['vhf_cluster']) + count($tripwires['cholera_doubling']);

        $kpis = [
            // Engine contract — DO NOT REMOVE
            'total_suspected'  => $totalSuspected,
            'unique_diseases'  => count($byDisease),

            // Premium-view extensions
            'tripwire_count'   => $tripwireTotal,
            'vhf_total'        => $vhfTotal,
            'vhf_diseases'     => count($vhfDiseases),
            'lead_disease'     => $leadDisease,
            'lead_count'       => $leadCount,
            'lead_is_vhf'      => $leadIsVhf,
            'confirmed'        => $totalConfirmed,
            'pending'          => $totalPending,
            'confirmation_pct' => $confirmationPct,
            'reporting_poes'   => count($byPoe),
        ];

        return [
            'window' => [
                'from'      => $from->toDateString(),
                'to'        => $to->toDateString(),
                'generated' => now()->toIso8601String(),
            ],
            'kpis'                 => $kpis,

            // Premium analytics
            'top_diseases'         => $top,
            'disease_trend'        => $diseaseTrend,
            'sparkline_week_labels'=> array_map(
                function ($w) {
                    // $w looks like "2026-W18" — split rather than createFromFormat
                    // because PHP's date parser does not reliably reverse the
                    // escaped literal in 'Y-\WW' across versions.
                    $parts = explode('-W', (string) $w);
                    $y = (int) ($parts[0] ?? 0);
                    $n = (int) ($parts[1] ?? 1);
                    if (! $y) { return $w; }
                    return Carbon::now()->setISODate($y, $n)->endOfWeek()->toDateString();
                },
                $sparklineWeeks,
            ),
            'heatmap'              => [
                'poes'     => $heatmapPoes,
                'diseases' => $heatDiseaseCodes,
                'matrix'   => $heatmap,
            ],
            'cosuspect_pairs'      => $cosuspect,
            'vhf_summary'          => [
                'total'      => $vhfTotal,
                'diseases'   => $vhfBreakdown,
                'distinct'   => count($vhfBreakdown),
            ],
            'lab_loop'             => [
                'suspected'   => $totalSuspected,
                'confirmed'   => $totalConfirmed,
                'pending'     => $totalPending,
                'unresolved'  => max(0, $totalSuspected - $totalConfirmed - $totalPending),
                'pct'         => $confirmationPct,
            ],

            // Legacy keys (kept stable for compatibility with insight engine + earlier consumers)
            'by_poe'         => $byPoe,
            'by_district'    => $byDistrict,
            'trend'          => [
                'weeks'       => $weeks,
                'week_labels' => $weekLabels,
                'series'      => $trend,
            ],
            'cooccurrence'   => $cooccurrence,
            'age_by_disease' => $ageByDisease,
            'tripwires'      => $tripwires,
            'quality'        => [
                'screenings_no_disease'    => $sec->count() - count($diseasesByScreening),
                'screenings_multi_disease' => count(array_filter($diseasesByScreening, fn ($x) => count(array_unique($x)) > 1)),
            ],
        ];
    }

    /**
     * Deterministic tripwire library — v1, hardcoded.
     *   • vhf_cluster     → ≥5 suspicions of any VHF-tagged disease at one POE within a 14-day window.
     *   • cholera_doubling→ cholera suspicions doubled week-over-week in a single district.
     */
    private function runTripwires(array $diseasesByScreening, $screeningById, $diseaseRef, array $weeklyByDistrict): array
    {
        $vhfHits   = [];
        $vhfBucket = [];
        foreach ($diseasesByScreening as $sid => $codes) {
            $row = $screeningById->get($sid);
            if (! $row?->poe_code || ! $row->opened_at) { continue; }
            $day = Carbon::parse((string) $row->opened_at)->startOfDay();
            foreach (array_unique($codes) as $c) {
                $ref = $diseaseRef->get($c);
                $isVhf = $ref && stripos((string) $ref->who_syndrome, 'VHF') !== false;
                if (! $isVhf) { continue; }
                $vhfBucket[$row->poe_code][$c][] = $day;
            }
        }
        foreach ($vhfBucket as $poe => $byDisease) {
            foreach ($byDisease as $code => $days) {
                sort($days);
                for ($i = 0; $i < count($days); $i++) {
                    $window = array_filter($days, fn ($d) => $d->diffInDays($days[$i]) <= 14 && $d->gte($days[$i]));
                    if (count($window) >= 5) {
                        $vhfHits[] = [
                            'poe_code'     => $poe,
                            'disease_code' => $code,
                            'count'        => count($window),
                        ];
                        break;
                    }
                }
            }
        }

        $choleraHits = [];
        foreach ($weeklyByDistrict as $district => $byDisease) {
            foreach ($byDisease as $code => $weeks) {
                if (stripos($code, 'cholera') === false) { continue; }
                $weekKeys = array_keys($weeks);
                sort($weekKeys);
                for ($i = 1; $i < count($weekKeys); $i++) {
                    $prev = (int) $weeks[$weekKeys[$i - 1]];
                    $curr = (int) $weeks[$weekKeys[$i]];
                    if ($prev >= 2 && $curr >= 2 * $prev) {
                        $choleraHits[] = [
                            'district_code' => $district,
                            'prev'          => $prev,
                            'curr'          => $curr,
                        ];
                        break;
                    }
                }
            }
        }

        return ['vhf_cluster' => $vhfHits, 'cholera_doubling' => $choleraHits];
    }

    private function ageBand(int $age): string
    {
        if ($age <= 0) { return 'UNKNOWN'; }
        if ($age < 5)  { return '<5'; }
        if ($age < 18) { return '5-17'; }
        if ($age < 45) { return '18-44'; }
        if ($age < 65) { return '45-64'; }
        return '65+';
    }
}
