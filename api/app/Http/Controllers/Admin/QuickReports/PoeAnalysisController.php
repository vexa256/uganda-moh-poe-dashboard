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
 * Quick Report · POE Analysis.
 *
 * URL:    /admin/quick-reports/poe-analysis
 *
 * Question: "Which points of entry are busy, which are producing alerts,
 * which are dark, and where should we put more boots?"
 *
 * Cohort: all POEs in the user's scope (ref_poes joined to scoped
 * secondary_screenings + alerts + primary_screenings in window). One row
 * per POE in the table.
 *
 * Adaptive chart:
 *   A. Alerts per POE (top 12)
 *   B. Secondary screenings per POE
 *   C. Primary screenings per POE
 *   D. Daily total POE activity
 */
final class PoeAnalysisController extends BaseQuickReportController
{
    protected string $reportKey   = 'qr-poe';
    protected string $reportTitle = 'POE Analysis';

    private const TABLE_LIMIT = 20;
    private const CHART_TOP_N = 12;

    private const MATERIAL_PALETTE = [
        '#E53935','#1E88E5','#43A047','#FB8C00','#8E24AA','#00ACC1',
        '#F4511E','#3949AB','#7CB342','#D81B60','#FFB300','#00897B',
    ];

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.quick.poe.index', [
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

        $headers = ['Point of entry','POE code','Primary screenings','Secondary screenings','Alerts opened','Alert rate %','Last activity','Status'];
        $rows = [];
        foreach ($payload['table_full'] as $r) {
            $rows[] = [
                $r['poe_name'] ?? $r['poe_code'], $r['poe_code'],
                (int) ($r['primary'] ?? 0),
                (int) ($r['secondary'] ?? 0),
                (int) ($r['alerts'] ?? 0),
                $r['alert_rate_pct'] !== null ? $r['alert_rate_pct'].'%' : '—',
                $r['last_activity_label'] ?? 'No activity',
                $r['is_dark'] ? 'Dark (0 screenings)' : 'Active',
            ];
        }
        return $this->writer->send($this->reportKey, (string) $request->input('format', 'CSV'),
            $headers, $rows, $filters, (int) ($scope['user_id'] ?? 0), $this->reportTitle);
    }

    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($filters);
        $windowLabel = $this->windowLabel($from, $to);

        // ── Allowed POE universe (scope-aware ref_poes set) ────────────────
        $allowedPoes = $this->scope->allowedPoes($scope); // [code => name]
        $allowedCodes = array_keys($allowedPoes);

        // Optional single-POE filter
        if (! empty($filters['poe'])) {
            $code = (string) $filters['poe'];
            if (! in_array($code, $allowedCodes, true)) { $allowedCodes = []; }
            else { $allowedCodes = [$code]; $allowedPoes = [$code => $allowedPoes[$code] ?? $code]; }
        }

        // ── Activity counts in the window ──────────────────────────────────
        $primaryByPoe = []; $secondaryByPoe = []; $alertsByPoe = [];
        $lastActivity = []; // poe_code => latest ISO timestamp seen
        $dayBuckets   = []; // 'M j' => total events

        if ($allowedCodes) {
            $primaryQ = DB::table('primary_screenings')
                ->whereNull('deleted_at')
                ->whereBetween('captured_at', [$from, $to])
                ->whereIn('poe_code', $allowedCodes);
            $this->scope->apply($primaryQ, $scope);
            foreach ($primaryQ->select('poe_code', 'captured_at')->cursor() as $r) {
                $code = (string) $r->poe_code;
                $primaryByPoe[$code] = ($primaryByPoe[$code] ?? 0) + 1;
                $this->bump($lastActivity, $code, (string) $r->captured_at);
                $this->bumpDay($dayBuckets, (string) $r->captured_at);
            }

            $secQ = DB::table('secondary_screenings')
                ->whereNull('deleted_at')
                ->whereBetween('opened_at', [$from, $to])
                ->whereIn('poe_code', $allowedCodes);
            $this->scope->apply($secQ, $scope);
            foreach ($secQ->select('poe_code', 'opened_at')->cursor() as $r) {
                $code = (string) $r->poe_code;
                $secondaryByPoe[$code] = ($secondaryByPoe[$code] ?? 0) + 1;
                $this->bump($lastActivity, $code, (string) $r->opened_at);
                $this->bumpDay($dayBuckets, (string) $r->opened_at);
            }

            $alertQ = DB::table('alerts')
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$from, $to])
                ->whereIn('poe_code', $allowedCodes);
            $this->scope->apply($alertQ, $scope);
            foreach ($alertQ->select('poe_code', 'created_at')->cursor() as $r) {
                $code = (string) $r->poe_code;
                $alertsByPoe[$code] = ($alertsByPoe[$code] ?? 0) + 1;
                $this->bump($lastActivity, $code, (string) $r->created_at);
                $this->bumpDay($dayBuckets, (string) $r->created_at);
            }
        }

        // ── Per-POE row build ──────────────────────────────────────────────
        $rows = [];
        $kpiActive = 0; $kpiDark = 0; $kpiTotalPri = 0; $kpiTotalSec = 0; $kpiTotalAlerts = 0;
        foreach ($allowedPoes as $code => $name) {
            $pri = $primaryByPoe[$code]    ?? 0;
            $sec = $secondaryByPoe[$code]  ?? 0;
            $alr = $alertsByPoe[$code]     ?? 0;
            $total = $pri + $sec;
            $rate  = $pri > 0 ? round(($alr / $pri) * 100, 1) : null;

            $isDark = $total === 0 && $alr === 0;
            if ($isDark) { $kpiDark++; } else { $kpiActive++; }
            $kpiTotalPri += $pri; $kpiTotalSec += $sec; $kpiTotalAlerts += $alr;

            $rows[] = [
                'poe_code'            => $code,
                'poe_name'            => $name,
                'primary'             => $pri,
                'secondary'           => $sec,
                'alerts'              => $alr,
                'alert_rate_pct'      => $rate,
                'last_activity_iso'   => $lastActivity[$code] ?? null,
                'last_activity_label' => isset($lastActivity[$code]) ? $this->humanDate($lastActivity[$code]) : null,
                'is_dark'             => $isDark,
            ];
        }

        // Free-text search
        if (! empty($filters['q'])) {
            $needle = strtolower((string) $filters['q']);
            $rows = array_values(array_filter($rows, function ($r) use ($needle) {
                $hay = strtolower(($r['poe_name'] ?? '') . ' ' . ($r['poe_code'] ?? ''));
                return strpos($hay, $needle) !== false;
            }));
        }

        // Sort: active first, then most alerts, then most screenings
        usort($rows, function ($a, $b) {
            if ($a['is_dark'] !== $b['is_dark']) { return $a['is_dark'] ? 1 : -1; }
            if ($a['alerts']  !== $b['alerts'])  { return $b['alerts'] <=> $a['alerts']; }
            return ($b['primary'] + $b['secondary']) <=> ($a['primary'] + $a['secondary']);
        });

        $tableVisible = array_slice($rows, 0, self::TABLE_LIMIT);

        $kpis = [
            'active_poes'   => $kpiActive,
            'dark_poes'     => $kpiDark,
            'primary_total' => $kpiTotalPri,
            'secondary_total' => $kpiTotalSec,
            'alerts_total'  => $kpiTotalAlerts,
            'alert_rate_pct'=> $kpiTotalPri > 0 ? round(($kpiTotalAlerts / $kpiTotalPri) * 100, 1) : null,
        ];

        $chart = $this->pickChart($alertsByPoe, $secondaryByPoe, $primaryByPoe, $dayBuckets, $allowedPoes, $windowLabel);

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
            'meta'       => ['poes' => $allowedPoes],
        ];
    }

    private function pickChart(array $alerts, array $sec, array $pri, array $day, array $poeNames, string $windowLabel): array
    {
        // A — alerts per POE (most decision-relevant)
        if (array_filter($alerts)) {
            arsort($alerts);
            $labels = []; $values = []; $i = 0;
            foreach ($alerts as $code => $count) {
                if ($i >= self::CHART_TOP_N) { break; }
                $labels[] = $poeNames[$code] ?? $code; $values[] = (int) $count; $i++;
            }
            return [
                'kind'     => 'alerts',
                'title'    => 'Alerts per point of entry',
                'subtitle' => 'Which entry points generated the most alerts. Tall bars = supervisor outreach + staffing review.',
                'labels'   => $labels, 'values' => $values, 'colors' => $this->cycle(count($labels)), 'unit' => 'alerts',
            ];
        }

        // B — secondary screenings per POE
        if (array_filter($sec)) {
            arsort($sec);
            $labels = []; $values = []; $i = 0;
            foreach ($sec as $code => $count) {
                if ($i >= self::CHART_TOP_N) { break; }
                $labels[] = $poeNames[$code] ?? $code; $values[] = (int) $count; $i++;
            }
            return [
                'kind'     => 'secondary',
                'title'    => 'Secondary screenings per POE',
                'subtitle' => 'Where screening officers are escalating to a clinician. No alerts opened yet.',
                'labels'   => $labels, 'values' => $values, 'colors' => $this->cycle(count($labels)), 'unit' => 'screenings',
            ];
        }

        // C — primary screenings per POE
        if (array_filter($pri)) {
            arsort($pri);
            $labels = []; $values = []; $i = 0;
            foreach ($pri as $code => $count) {
                if ($i >= self::CHART_TOP_N) { break; }
                $labels[] = $poeNames[$code] ?? $code; $values[] = (int) $count; $i++;
            }
            return [
                'kind'     => 'primary',
                'title'    => 'Primary screenings per POE',
                'subtitle' => 'Where the throughput is. No escalations to secondary or alerts yet.',
                'labels'   => $labels, 'values' => $values, 'colors' => $this->cycle(count($labels)), 'unit' => 'screenings',
            ];
        }

        // D — daily activity
        if (array_filter($day)) {
            ksort($day);
            return [
                'kind'     => 'day',
                'title'    => 'POE activity per day',
                'subtitle' => 'Combined daily volume across every event type.',
                'labels'   => array_keys($day), 'values' => array_values($day),
                'colors'   => $this->cycle(count($day)), 'unit' => 'events',
            ];
        }

        return [
            'kind' => 'empty',
            'title' => 'No POE activity',
            'subtitle' => 'Every POE in scope is dark for this window. Widen the date range.',
            'labels' => [], 'values' => [], 'colors' => [], 'unit' => 'events',
        ];
    }

    private function bump(array &$last, string $code, string $iso): void
    {
        if (! isset($last[$code]) || strcmp($iso, (string) $last[$code]) > 0) { $last[$code] = $iso; }
    }

    private function bumpDay(array &$buckets, string $iso): void
    {
        try {
            $d = Carbon::parse($iso)->setTimezone(config('app.timezone','Africa/Kampala'))->format('M j');
            $buckets[$d] = ($buckets[$d] ?? 0) + 1;
        } catch (\Throwable $e) { /* skip */ }
    }

    private function cycle(int $n): array
    {
        $out = []; $p = self::MATERIAL_PALETTE; $len = count($p);
        for ($i = 0; $i < $n; $i++) { $out[] = $p[$i % $len]; }
        return $out;
    }

    private function humanDate(string $iso): string
    {
        if ($iso === '') { return '—'; }
        try { return Carbon::parse($iso)->setTimezone(config('app.timezone','Africa/Kampala'))->format('M j, H:i'); }
        catch (\Throwable $e) { return $iso; }
    }
}
