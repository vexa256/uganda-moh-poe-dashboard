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
 * Quick Report · Daily Screening.
 *
 * URL: /admin/quick-reports/daily-screening
 *
 * Question: "How many travellers did we screen yesterday, today, and how is
 * the trend tracking day-by-day across the window?"
 *
 * Cohort: primary_screenings rows in scope/window, joined-by-lookup to
 * secondary_screenings (via primary_screening_id) and alerts (via secondary
 * screening) for escalation context.
 */
final class DailyScreeningController extends BaseQuickReportController
{
    protected string $reportKey   = 'qr-daily';
    protected string $reportTitle = 'Daily Screening';

    private const TABLE_LIMIT = 20;

    private const MATERIAL_PALETTE = [
        '#E53935','#1E88E5','#43A047','#FB8C00','#8E24AA','#00ACC1',
        '#F4511E','#3949AB','#7CB342','#D81B60','#FFB300','#00897B',
    ];

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.quick.daily.index', [
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
        $payload['scope']   = ['label' => $scope['label'] ?? '—', 'level' => $scope['scope_level'] ?? 'SELF'];
        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));
        $payload = $this->buildPayload($scope, $filters);

        $headers = ['Day','Primary screened','Secondary (escalated)','Alerts opened','Top POE','Male','Female','Other','Unknown','Escalation rate %'];
        $rows = [];
        foreach ($payload['table_full'] as $r) {
            $g = $r['gender'] ?? ['M'=>0,'F'=>0,'O'=>0,'U'=>0];
            $rows[] = [
                $r['day_label'], (int) $r['primary'], (int) $r['secondary'], (int) $r['alerts'],
                $r['top_poe_name'] ?? '—',
                (int) ($g['M'] ?? 0), (int) ($g['F'] ?? 0), (int) ($g['O'] ?? 0), (int) ($g['U'] ?? 0),
                $r['escalation_rate_pct'] !== null ? $r['escalation_rate_pct'].'%' : '—',
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

        $allowedPoes  = $this->scope->allowedPoes($scope); // [code => name]
        $allowedCodes = array_keys($allowedPoes);
        if (! empty($filters['poe'])) {
            $code = (string) $filters['poe'];
            if (in_array($code, $allowedCodes, true)) {
                $allowedCodes = [$code]; $allowedPoes = [$code => $allowedPoes[$code] ?? $code];
            } else { $allowedCodes = []; }
        }

        // Build per-day buckets across the window (inclusive). Empty days count as 0.
        $days = [];
        $cursor = (clone $from)->setTimezone($tz)->startOfDay();
        $endDay = (clone $to)->setTimezone($tz)->startOfDay();
        while ($cursor->lte($endDay)) {
            $key = $cursor->format('Y-m-d');
            $days[$key] = [
                'key'        => $key,
                'day_label'  => $cursor->format('D, M j'),
                'primary'    => 0,
                'secondary'  => 0,
                'alerts'     => 0,
                'gender'     => ['M' => 0, 'F' => 0, 'O' => 0, 'U' => 0],
                'poe_counts' => [],
            ];
            $cursor->addDay();
        }

        $primaryIdsByDay = []; // YYYY-MM-DD => [primary_id, …] (used to link alerts → primary day)
        $primaryDayByPid = []; // primary_id  => YYYY-MM-DD

        if ($allowedCodes) {
            // Primary rows
            $pq = DB::table('primary_screenings')
                ->whereNull('deleted_at')
                ->whereBetween('captured_at', [$from, $to])
                ->whereIn('poe_code', $allowedCodes);
            $this->scope->apply($pq, $scope);
            if (! empty($filters['gender'])) {
                $pq->whereRaw('UPPER(gender) = ?', [strtoupper((string) $filters['gender'])]);
            }
            foreach ($pq->select('id','captured_at','gender','poe_code')->cursor() as $r) {
                $key = $this->dayKey((string) $r->captured_at, $tz);
                if (! isset($days[$key])) { continue; }
                $days[$key]['primary']++;
                $g = $this->genderBucket($r->gender);
                $days[$key]['gender'][$g]++;
                $code = (string) $r->poe_code;
                $days[$key]['poe_counts'][$code] = ($days[$key]['poe_counts'][$code] ?? 0) + 1;
                $primaryIdsByDay[$key][] = (int) $r->id;
                $primaryDayByPid[(int) $r->id] = $key;
            }

            // Secondary rows — bucketed by the linked primary's day (per spec)
            $sq = DB::table('secondary_screenings')
                ->whereNull('deleted_at')
                ->whereBetween('opened_at', [$from, $to])
                ->whereIn('poe_code', $allowedCodes);
            $this->scope->apply($sq, $scope);
            if (! empty($filters['gender'])) {
                $sq->whereRaw('UPPER(traveler_gender) = ?', [strtoupper((string) $filters['gender'])]);
            }
            $secondaryIdToPrimaryDay = []; // sec_id => YYYY-MM-DD (for alerts linking)
            foreach ($sq->select('id','opened_at','primary_screening_id')->cursor() as $r) {
                $pid = $r->primary_screening_id ? (int) $r->primary_screening_id : null;
                $key = $pid !== null && isset($primaryDayByPid[$pid])
                    ? $primaryDayByPid[$pid]
                    : $this->dayKey((string) $r->opened_at, $tz);
                if (! isset($days[$key])) { continue; }
                $days[$key]['secondary']++;
                $secondaryIdToPrimaryDay[(int) $r->id] = $key;
            }

            // Alerts — bucketed by the linked secondary's primary day
            $aq = DB::table('alerts')
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$from, $to])
                ->whereIn('poe_code', $allowedCodes);
            $this->scope->apply($aq, $scope);
            foreach ($aq->select('id','created_at','secondary_screening_id')->cursor() as $r) {
                $sid = $r->secondary_screening_id ? (int) $r->secondary_screening_id : null;
                $key = $sid !== null && isset($secondaryIdToPrimaryDay[$sid])
                    ? $secondaryIdToPrimaryDay[$sid]
                    : $this->dayKey((string) $r->created_at, $tz);
                if (! isset($days[$key])) { continue; }
                $days[$key]['alerts']++;
            }
        }

        // Derive per-row fields
        foreach ($days as &$d) {
            $top = null; $topCount = -1;
            foreach ($d['poe_counts'] as $code => $n) {
                if ($n > $topCount) { $top = $code; $topCount = $n; }
            }
            $d['top_poe_code'] = $top;
            $d['top_poe_name'] = $top !== null ? ($allowedPoes[$top] ?? $top) : null;
            $d['escalation_rate_pct'] = $d['primary'] > 0
                ? round(($d['secondary'] / $d['primary']) * 100, 1)
                : null;
        }
        unset($d);

        // Free-text search (matches against day label or POE name)
        $rows = array_values($days);
        if (! empty($filters['q'])) {
            $needle = strtolower((string) $filters['q']);
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                $hay = strtolower(($r['day_label'] ?? '') . ' ' . ($r['top_poe_name'] ?? ''));
                return strpos($hay, $needle) !== false;
            }));
        }

        // Sort most-recent-first
        usort($rows, fn ($a, $b) => strcmp($b['key'], $a['key']));
        $tableVisible = array_slice($rows, 0, self::TABLE_LIMIT);

        // KPIs
        $todayKey     = Carbon::now($tz)->format('Y-m-d');
        $yesterdayKey = Carbon::now($tz)->subDay()->format('Y-m-d');
        $kpiToday     = $days[$todayKey]['primary']     ?? 0;
        $kpiYesterday = $days[$yesterdayKey]['primary'] ?? 0;

        // Last-7-day average — total over the last 7 calendar days / 7
        $last7Total = 0; $cursor = Carbon::now($tz)->startOfDay();
        for ($i = 0; $i < 7; $i++) {
            $k = $cursor->copy()->subDays($i)->format('Y-m-d');
            $last7Total += $days[$k]['primary'] ?? 0;
        }
        $kpi7Avg = (int) round($last7Total / 7);

        // Busiest day in window
        $busiestDay = null; $busiestCount = -1;
        foreach ($days as $d) {
            if ($d['primary'] > $busiestCount) { $busiestCount = $d['primary']; $busiestDay = $d; }
        }
        $kpiBusiest = $busiestDay && $busiestDay['primary'] > 0
            ? ['label' => $busiestDay['day_label'], 'value' => $busiestDay['primary']]
            : null;

        $totalPrimary   = array_sum(array_column($days, 'primary'));
        $totalSecondary = array_sum(array_column($days, 'secondary'));
        $kpiEscalation  = $totalPrimary > 0 ? round(($totalSecondary / $totalPrimary) * 100, 1) : null;

        // Today's busiest POE
        $kpiTodayPoe = null;
        if (isset($days[$todayKey])) {
            $tp = null; $tc = -1;
            foreach (($days[$todayKey]['poe_counts'] ?? []) as $code => $n) {
                if ($n > $tc) { $tp = $code; $tc = $n; }
            }
            if ($tp !== null) {
                $kpiTodayPoe = ['label' => $allowedPoes[$tp] ?? $tp, 'value' => $tc];
            }
        }

        $kpis = [
            'today'              => $kpiToday,
            'yesterday'          => $kpiYesterday,
            'avg_7d'             => $kpi7Avg,
            'busiest'            => $kpiBusiest,
            'escalation_rate'    => $kpiEscalation,
            'today_busiest_poe'  => $kpiTodayPoe,
            'total_primary'      => $totalPrimary,
            'total_secondary'    => $totalSecondary,
        ];

        $chart = $this->pickChart($days, $allowedPoes, $windowLabel, $filters);

        return [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
                         'days' => count($days), 'label' => $windowLabel],
            'kpis'       => $kpis,
            'chart'      => $chart,
            'table'      => $tableVisible,
            'table_full' => $rows,
            'total_rows' => count($rows),
            'shown_rows' => count($tableVisible),
            'meta'       => ['poes' => $allowedPoes],
        ];
    }

    private function pickChart(array $days, array $poeNames, string $windowLabel, array $filters): array
    {
        $totalPrimary = array_sum(array_column($days, 'primary'));

        // A — per-day volume (preferred when window covers >1 day)
        if (count($days) > 1 && $totalPrimary > 0) {
            $labels = []; $values = []; $colors = [];
            $ordered = $days;
            ksort($ordered);
            $i = 0;
            foreach ($ordered as $d) {
                $labels[] = $d['day_label'];
                $values[] = (int) $d['primary'];
                // Orange highlight for days with escalations
                $colors[] = $d['secondary'] > 0
                    ? '#FB8C00'
                    : self::MATERIAL_PALETTE[$i % count(self::MATERIAL_PALETTE)];
                $i++;
            }
            return [
                'kind'     => 'daily',
                'title'    => 'Daily screening volume',
                'subtitle' => 'Primary screenings per calendar day. Orange = at least one escalation to secondary.',
                'labels'   => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'screenings',
            ];
        }

        // B — single day window: fall back to per-POE for "today"
        if (count($days) === 1) {
            $only = reset($days);
            $poe = $only['poe_counts'] ?? [];
            if (array_filter($poe)) {
                arsort($poe);
                $labels = []; $values = []; $i = 0;
                foreach ($poe as $code => $n) {
                    if ($i >= 12) { break; }
                    $labels[] = $poeNames[$code] ?? $code;
                    $values[] = (int) $n;
                    $i++;
                }
                return [
                    'kind'     => 'today_poe',
                    'title'    => 'Screenings by POE',
                    'subtitle' => 'Distribution of today\'s primary screenings across points of entry.',
                    'labels'   => $labels, 'values' => $values, 'colors' => $this->cycle(count($labels)), 'unit' => 'screenings',
                ];
            }
        }

        // C — gender split fallback (window aggregate)
        $g = ['Male' => 0, 'Female' => 0, 'Other' => 0, 'Unknown' => 0];
        $map = ['M' => 'Male', 'F' => 'Female', 'O' => 'Other', 'U' => 'Unknown'];
        foreach ($days as $d) {
            foreach (($d['gender'] ?? []) as $k => $v) { $g[$map[$k] ?? 'Unknown'] += (int) $v; }
        }
        if (array_filter($g)) {
            $labels = array_keys($g); $values = array_values($g);
            return [
                'kind'     => 'gender',
                'title'    => 'Gender mix of primary screenings',
                'subtitle' => 'No per-day distribution to show — defaulting to gender split.',
                'labels'   => $labels, 'values' => $values, 'colors' => $this->cycle(count($labels)), 'unit' => 'screenings',
            ];
        }

        return [
            'kind' => 'empty',
            'title' => 'No screenings',
            'subtitle' => 'No primary screenings were captured in this window. Widen the date range.',
            'labels' => [], 'values' => [], 'colors' => [], 'unit' => 'screenings',
        ];
    }

    private function dayKey(string $iso, string $tz): string
    {
        try { return Carbon::parse($iso)->setTimezone($tz)->format('Y-m-d'); }
        catch (\Throwable $e) { return ''; }
    }

    private function genderBucket(?string $raw): string
    {
        $v = strtoupper(trim((string) $raw));
        if ($v === 'M' || $v === 'MALE')   { return 'M'; }
        if ($v === 'F' || $v === 'FEMALE') { return 'F'; }
        if ($v === 'O' || $v === 'OTHER')  { return 'O'; }
        return 'U';
    }

    private function cycle(int $n): array
    {
        $out = []; $p = self::MATERIAL_PALETTE; $len = count($p);
        for ($i = 0; $i < $n; $i++) { $out[] = $p[$i % $len]; }
        return $out;
    }
}
