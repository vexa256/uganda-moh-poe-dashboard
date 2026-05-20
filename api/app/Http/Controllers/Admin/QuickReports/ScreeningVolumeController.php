<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\QuickReports;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quick Report · Screening Volume.
 *
 * URL: /admin/quick-reports/screening-volume
 *
 * Question: "What's the throughput and mix — primary vs secondary, gender
 * split, age band — across the window?"
 *
 * Cohort: primary_screenings in window. Joined-by-lookup to
 * secondary_screenings via primary_screening_id.
 */
final class ScreeningVolumeController extends BaseQuickReportController
{
    protected string $reportKey   = 'qr-volume';
    protected string $reportTitle = 'Screening Volume';

    private const TABLE_LIMIT = 20;
    private const CHART_TOP_N = 12;

    private const MATERIAL_PALETTE = [
        '#E53935','#1E88E5','#43A047','#FB8C00','#8E24AA','#00ACC1',
        '#F4511E','#3949AB','#7CB342','#D81B60','#FFB300','#00897B',
    ];

    private const AGE_BUCKETS = ['<5','5-17','18-30','31-60','60+','unknown'];

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.quick.volume.index', [
            'scope' => $scope, 'reportKey' => $this->reportKey, 'reportTitle' => $this->reportTitle,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));
        $payload = $this->memoise((int) ($scope['user_id'] ?? 0), $filters,
            fn () => $this->buildPayload($scope, $filters));
        $payload['filters'] = $filters;
        $payload['scope']   = $this->scopeBlock($scope);
        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));
        $payload = $this->buildPayload($scope, $filters);

        $headers = ['Point of entry','POE code','Primary','Secondary','Escalation rate %','Male','Female','Other','Unknown','Median age','Last screening'];
        $rows = [];
        foreach ($payload['table_full'] as $r) {
            $g = $r['gender'] ?? ['M'=>0,'F'=>0,'O'=>0,'U'=>0];
            $rows[] = [
                $r['poe_name'] ?? $r['poe_code'], $r['poe_code'],
                (int) $r['primary'], (int) $r['secondary'],
                $r['escalation_rate_pct'] !== null ? $r['escalation_rate_pct'].'%' : '—',
                (int) ($g['M'] ?? 0), (int) ($g['F'] ?? 0), (int) ($g['O'] ?? 0), (int) ($g['U'] ?? 0),
                $r['median_age'] !== null ? (string) $r['median_age'] : '—',
                $r['last_screening_label'] ?? 'No activity',
            ];
        }
        return $this->writer->send($this->reportKey, (string) $request->input('format', 'CSV'),
            $headers, $rows, $filters, (int) ($scope['user_id'] ?? 0), $this->reportTitle);
    }

    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($filters);
        $windowLabel = $this->windowLabel($from, $to);
        $tz = config('app.timezone', 'Africa/Kampala');

        $allowedPoes  = $this->scope->allowedPoes($scope);
        $allowedCodes = array_keys($allowedPoes);
        if (! empty($filters['poe'])) {
            $code = (string) $filters['poe'];
            if (in_array($code, $allowedCodes, true)) {
                $allowedCodes = [$code]; $allowedPoes = [$code => $allowedPoes[$code] ?? $code];
            } else { $allowedCodes = []; $allowedPoes = []; } // out-of-scope filter → empty register, not all-zero
        }

        // Defensive: also accept POE name in poe_code (mobile EMBEDDED_FALLBACK bug,
        // see migration 2026_05_20_000002). Canonicalise in-loop so byPoe merges correctly.
        $allowedCodesQ = $this->scope->expandPoeFilterToIncludeNames($allowedCodes);
        $nameToCode = [];
        foreach ($allowedPoes as $code => $name) { if ($name) { $nameToCode[(string) $name] = $code; } }

        $byPoe = []; // code => row aggregate
        foreach ($allowedPoes as $code => $name) {
            $byPoe[$code] = [
                'poe_code' => $code, 'poe_name' => $name,
                'primary' => 0, 'secondary' => 0,
                'gender'  => ['M' => 0, 'F' => 0, 'O' => 0, 'U' => 0],
                'ages'    => [],
                'last_iso' => null,
            ];
        }

        $genderTotals = ['Male' => 0, 'Female' => 0, 'Other' => 0, 'Unknown' => 0];
        $ageTotals    = array_fill_keys(self::AGE_BUCKETS, 0);
        $totalPrimary = 0; $totalSecondary = 0;
        $last24hPrimary = 0;
        $now24hAgo = Carbon::now($tz)->subDay();

        if ($allowedCodes) {
            $pq = DB::table('primary_screenings')
                ->whereNull('deleted_at')
                ->whereBetween('captured_at', [$from, $to])
                ->whereIn('poe_code', $allowedCodesQ);
            $this->scope->apply($pq, $scope);
            // Optional gender filter
            if (! empty($filters['gender'])) {
                $g = strtoupper((string) $filters['gender']);
                $pq->whereRaw('UPPER(gender) = ?', [$g]);
            }
            foreach ($pq->select('id','captured_at','gender','poe_code')->cursor() as $r) {
                $code = (string) $r->poe_code;
                if (isset($nameToCode[$code])) { $code = $nameToCode[$code]; }
                if (! isset($byPoe[$code])) { continue; }
                $byPoe[$code]['primary']++;
                $totalPrimary++;
                $gb = $this->genderBucket($r->gender);
                $byPoe[$code]['gender'][$gb]++;
                $this->bumpIso($byPoe[$code]['last_iso'], (string) $r->captured_at);
                try {
                    if (Carbon::parse((string) $r->captured_at)->gte($now24hAgo)) { $last24hPrimary++; }
                } catch (\Throwable $e) { /* skip */ }
            }

            $sq = DB::table('secondary_screenings')
                ->whereNull('deleted_at')
                ->whereBetween('opened_at', [$from, $to])
                ->whereIn('poe_code', $allowedCodesQ);
            $this->scope->apply($sq, $scope);
            if (! empty($filters['gender'])) {
                $g = strtoupper((string) $filters['gender']);
                $sq->whereRaw('UPPER(traveler_gender) = ?', [$g]);
            }
            $ageBandFilter = ! empty($filters['age_band']) ? (string) $filters['age_band'] : null;
            foreach ($sq->select('id','opened_at','poe_code','traveler_age_years')->cursor() as $r) {
                $code = (string) $r->poe_code;
                if (isset($nameToCode[$code])) { $code = $nameToCode[$code]; }
                if (! isset($byPoe[$code])) { continue; }
                $age = $r->traveler_age_years === null ? null : (int) $r->traveler_age_years;
                $band = $this->ageBand($age);
                if ($ageBandFilter !== null && $band !== $ageBandFilter) { continue; }
                $byPoe[$code]['secondary']++;
                $totalSecondary++;
                if ($age !== null) { $byPoe[$code]['ages'][] = $age; }
                $ageTotals[$band]++;
                $this->bumpIso($byPoe[$code]['last_iso'], (string) $r->opened_at);
            }
        }

        // Compose row-derived fields + gender totals
        $rows = [];
        foreach ($byPoe as $code => &$r) {
            $r['escalation_rate_pct'] = $r['primary'] > 0
                ? round(($r['secondary'] / $r['primary']) * 100, 1)
                : null;
            $r['median_age'] = $this->median($r['ages']);
            $r['last_screening_iso']   = $r['last_iso'];
            $r['last_screening_label'] = $r['last_iso'] ? $this->humanDate($r['last_iso']) : null;
            unset($r['ages'], $r['last_iso']);

            $genderTotals['Male']    += $r['gender']['M'] ?? 0;
            $genderTotals['Female']  += $r['gender']['F'] ?? 0;
            $genderTotals['Other']   += $r['gender']['O'] ?? 0;
            $genderTotals['Unknown'] += $r['gender']['U'] ?? 0;

            $rows[] = $r;
        }
        unset($r);

        // Free-text search
        if (! empty($filters['q'])) {
            $needle = strtolower((string) $filters['q']);
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                $hay = strtolower(($r['poe_name'] ?? '') . ' ' . ($r['poe_code'] ?? ''));
                return strpos($hay, $needle) !== false;
            }));
        }

        // Sort: most primary first; ties broken by secondary
        usort($rows, function ($a, $b) {
            if ($a['primary'] !== $b['primary']) { return $b['primary'] <=> $a['primary']; }
            return $b['secondary'] <=> $a['secondary'];
        });
        $tableVisible = array_slice($rows, 0, self::TABLE_LIMIT);

        // KPIs
        $kpis = [
            'total_primary'    => $totalPrimary,
            'total_secondary'  => $totalSecondary,
            'escalation_rate'  => $totalPrimary > 0
                ? round(($totalSecondary / $totalPrimary) * 100, 1) : null,
            'top_gender'       => $this->topKey($genderTotals),
            'top_age_band'     => $this->topKey($ageTotals),
            'last_24h_primary' => $last24hPrimary,
        ];

        $chart = $this->pickChart($genderTotals, $ageTotals, $totalPrimary, $totalSecondary, $byPoe, $windowLabel);

        return [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
                         'days' => (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400) + 1,
                         'label' => $windowLabel],
            'kpis'       => $kpis,
            'chart'      => $chart,
            'table'      => $tableVisible,
            'table_full' => $rows,
            'total_rows' => count($rows),
            'shown_rows' => count($tableVisible),
            'meta'       => ['poes' => $allowedPoes, 'age_bands' => self::AGE_BUCKETS],
        ];
    }

    private function pickChart(array $gender, array $age, int $primary, int $secondary, array $byPoe, string $windowLabel): array
    {
        // A — primary vs secondary (most direct expression of "throughput")
        if ($primary > 0 && $secondary > 0) {
            return [
                'kind'     => 'pri_sec',
                'title'    => 'Primary vs Secondary screenings',
                'subtitle' => 'How many primary screenings escalated to a clinician-led secondary.',
                'labels'   => ['Primary', 'Secondary'],
                'values'   => [$primary, $secondary],
                'colors'   => ['#43A047', '#FB8C00'], 'unit' => 'screenings',
            ];
        }

        // B — gender mix
        if (array_filter($gender)) {
            $labels = array_keys($gender); $values = array_values($gender);
            return [
                'kind'     => 'gender',
                'title'    => 'Gender mix',
                'subtitle' => 'Primary-screening gender breakdown for the window.',
                'labels'   => $labels, 'values' => $values, 'colors' => $this->cycle(count($labels)), 'unit' => 'screenings',
            ];
        }

        // C — age bands
        if (array_filter($age)) {
            $labels = array_keys($age); $values = array_values($age);
            return [
                'kind'     => 'age',
                'title'    => 'Age band distribution',
                'subtitle' => 'Secondary-screening age bucket breakdown (primary screenings do not capture age).',
                'labels'   => $labels, 'values' => $values, 'colors' => $this->cycle(count($labels)), 'unit' => 'screenings',
            ];
        }

        // D — by POE
        $poeCounts = [];
        foreach ($byPoe as $r) { if (($r['primary'] ?? 0) > 0) { $poeCounts[$r['poe_name'] ?? $r['poe_code']] = (int) $r['primary']; } }
        if ($poeCounts) {
            arsort($poeCounts);
            $poeCounts = array_slice($poeCounts, 0, self::CHART_TOP_N, true);
            return [
                'kind'     => 'poe',
                'title'    => 'Primary screenings by POE',
                'subtitle' => 'Top entry points by primary throughput.',
                'labels'   => array_keys($poeCounts), 'values' => array_values($poeCounts),
                'colors'   => $this->cycle(count($poeCounts)), 'unit' => 'screenings',
            ];
        }

        return [
            'kind' => 'empty',
            'title' => 'No screenings',
            'subtitle' => 'No screenings in window for this scope. Widen the date range.',
            'labels' => [], 'values' => [], 'colors' => [], 'unit' => 'screenings',
        ];
    }

    private function ageBand(?int $age): string
    {
        if ($age === null || $age < 0) { return 'unknown'; }
        if ($age < 5)   { return '<5'; }
        if ($age < 18)  { return '5-17'; }
        if ($age < 31)  { return '18-30'; }
        if ($age < 61)  { return '31-60'; }
        return '60+';
    }

    private function median(array $values): ?int
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null && $v >= 0));
        if (! $values) { return null; }
        sort($values);
        $n = count($values); $mid = intdiv($n, 2);
        return $n % 2 ? (int) $values[$mid] : (int) round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    private function genderBucket(?string $raw): string
    {
        $v = strtoupper(trim((string) $raw));
        if ($v === 'M' || $v === 'MALE')   { return 'M'; }
        if ($v === 'F' || $v === 'FEMALE') { return 'F'; }
        if ($v === 'O' || $v === 'OTHER')  { return 'O'; }
        return 'U';
    }

    private function bumpIso(?string &$cur, string $iso): void
    {
        if ($cur === null || strcmp($iso, (string) $cur) > 0) { $cur = $iso; }
    }

    private function humanDate(string $iso): string
    {
        if ($iso === '') { return '—'; }
        try { return Carbon::parse($iso)->setTimezone(config('app.timezone','Africa/Kampala'))->format('M j, H:i'); }
        catch (\Throwable $e) { return $iso; }
    }

    private function topKey(array $counts): ?string
    {
        $top = null; $best = -1;
        foreach ($counts as $k => $v) {
            if ((int) $v > $best) { $best = (int) $v; $top = $k; }
        }
        return $best > 0 ? $top : null;
    }

    private function cycle(int $n): array
    {
        $out = []; $p = self::MATERIAL_PALETTE; $len = count($p);
        for ($i = 0; $i < $n; $i++) { $out[] = $p[$i % $len]; }
        return $out;
    }
}
