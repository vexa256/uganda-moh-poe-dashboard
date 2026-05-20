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
 * Quick Report · Alert Outcomes.
 *
 * URL:    /admin/quick-reports/alert-outcomes
 *
 * Question: "How fast are we acknowledging and closing alerts, and how do
 * they end — confirmed cases, ruled out, no case, or still in limbo?"
 *
 * Performance / SLA view. KPIs are the metrics the PHEOC reports on:
 *   • Median time to acknowledge (minutes)
 *   • Median time to close (hours)
 *   • % closed within 24 hours
 *   • % closed within 7 days
 *   • Reopened count
 *
 * Adaptive chart cascade:
 *   A. Closures by close_category (semantic — CONFIRMED red, RULED_OUT green,
 *      NO_CASE blue-grey)
 *   B. Open vs Closed vs Reopened mix
 *   C. Time-to-acknowledge bucketed (<1h, 1–4h, 4–24h, >24h)
 *   D. Per-day closure series
 */
final class AlertOutcomesController extends BaseQuickReportController
{
    protected string $reportKey   = 'qr-alert-out';
    protected string $reportTitle = 'Alert Outcomes';

    private const TABLE_LIMIT = 20;
    private const CHART_TOP_N = 12;

    private const CLOSE_LABELS = [
        'CONFIRMED_CASE'   => 'Confirmed case',
        'CONFIRMED'        => 'Confirmed case',
        'PROBABLE'         => 'Probable',
        'DISCARDED'        => 'Ruled out',
        'NO_CASE'          => 'No case',
        'NOT_A_CASE'       => 'No case',
        'FALSE_POSITIVE'   => 'False positive',
        'LOST_TO_FOLLOWUP' => 'Lost to follow-up',
        'REFERRED'         => 'Referred elsewhere',
        'DUPLICATE'        => 'Duplicate alert',
        'OTHER'            => 'Other',
    ];
    private const CLOSE_COLORS = [
        'CONFIRMED_CASE'   => '#C62828', // red 800 — actually a case
        'CONFIRMED'        => '#C62828',
        'PROBABLE'         => '#E64A19', // deep-orange 700
        'DISCARDED'        => '#43A047', // green 600
        'NO_CASE'          => '#43A047',
        'NOT_A_CASE'       => '#43A047',
        'FALSE_POSITIVE'   => '#1E88E5', // blue 600
        'LOST_TO_FOLLOWUP' => '#8E24AA', // purple 600
        'REFERRED'         => '#FB8C00', // orange 600
        'DUPLICATE'        => '#546E7A', // blue-grey 600
        'OTHER'            => '#546E7A',
    ];

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.quick.alert-out.index', [
            'scope' => $scope, 'reportKey' => $this->reportKey, 'reportTitle' => $this->reportTitle,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));
        $payload = $this->memoise(
            (int) ($scope['user_id'] ?? 0), $filters,
            fn () => $this->buildPayload($scope, $filters),
        );
        $payload['filters'] = $filters;
        $payload['scope']   = ['label' => $scope['label'] ?? '—', 'level' => $scope['scope_level'] ?? 'SELF'];
        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));
        $payload = $this->buildPayload($scope, $filters);

        $headers = [
            'Opened (Africa/Kampala)', 'Acknowledged at', 'Closed at',
            'Time to acknowledge (min)', 'Time to close (hr)',
            'Alert code', 'Traveller', 'Closure outcome',
            'Risk', 'Status', 'Point of entry', 'Case file URL',
        ];
        $rows = [];
        foreach ($payload['table_full'] as $r) {
            $rows[] = [
                $r['opened_at_label'], $r['acknowledged_at_label'] ?? '—', $r['closed_at_label'] ?? '—',
                $r['ack_min'] ?? '—', $r['close_hr'] ?? '—',
                $r['alert_code'] ?? '—', $r['traveller_name'],
                self::CLOSE_LABELS[$r['close_category']] ?? ($r['close_category'] ?? '—'),
                $r['risk'] ?? '—', $r['status'] ?? '—', $r['poe_name'] ?? '—',
                $r['case_file_url'] ?? '',
            ];
        }
        return $this->writer->send(
            $this->reportKey, (string) $request->input('format', 'CSV'),
            $headers, $rows, $filters, (int) ($scope['user_id'] ?? 0), $this->reportTitle,
        );
    }

    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to]  = $this->scope->resolveDateWindow($filters);
        $windowLabel  = $this->windowLabel($from, $to);

        $q = DB::table('alerts')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to]);
        $this->scope->apply($q, $scope);

        if (! empty($filters['poe']))            { $q->where('poe_code', (string) $filters['poe']); }
        if (! empty($filters['risk']))           { $q->where('risk_level', (string) $filters['risk']); }
        if (! empty($filters['status']))         { $q->where('status', (string) $filters['status']); }
        if (! empty($filters['close_category'])) { $q->where('close_category', (string) $filters['close_category']); }

        $alerts = $q->select([
                'id','client_uuid','alert_code','status','risk_level','ihr_tier',
                'poe_code','secondary_screening_id','close_category','close_note',
                'created_at','acknowledged_at','closed_at','reopen_count',
            ])
            ->orderBy('created_at','desc')->orderBy('id','desc')
            ->get();

        // De-dup
        $byUuid = []; $dedup = [];
        foreach ($alerts as $a) {
            if (! $a->client_uuid) { $dedup[] = $a; continue; }
            if (! isset($byUuid[$a->client_uuid]) || (int) $a->id > (int) $byUuid[$a->client_uuid]->id) {
                $byUuid[$a->client_uuid] = $a;
            }
        }
        foreach ($byUuid as $a) { $dedup[] = $a; }
        $alerts = collect($dedup)->sortByDesc(fn ($r) => [(string) $r->created_at, (int) $r->id])->values();

        $secIds   = $alerts->pluck('secondary_screening_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $poeCodes = $alerts->pluck('poe_code')->filter()->unique()->values()->all();

        $sec = $secIds ? DB::table('secondary_screenings')->whereIn('id', $secIds)
            ->get(['id','traveler_full_name','traveler_initials','traveler_anonymous_code',
                   'traveler_age_years','traveler_gender','traveler_nationality_country_code']) : collect();
        $secById = [];
        foreach ($sec as $s) { $secById[(int) $s->id] = $s; }

        $poeNames = $poeCodes ? DB::table('ref_poes')->whereIn('poe_code', $poeCodes)
            ->pluck('poe_name', 'poe_code')->all() : [];

        // SLA metrics
        $ackMinutes = [];
        $closeHours = [];
        $closedWithin24h = 0;
        $closedWithin7d  = 0;
        $totalClosed     = 0;
        $reopened        = 0;
        $unack           = 0;
        $ackBuckets      = ['<1h'=>0,'1–4h'=>0,'4–24h'=>0,'>24h'=>0,'Not yet'=>0];
        $closeCats       = [];
        $statusBuckets   = ['OPEN'=>0,'ACKNOWLEDGED'=>0,'IN_PROGRESS'=>0,'CLOSED'=>0,'REOPENED'=>0];

        $rows = [];
        foreach ($alerts as $a) {
            $sid = (int) ($a->secondary_screening_id ?? 0);
            $s   = $sid && isset($secById[$sid]) ? $secById[$sid] : null;

            $ackMin = null; $closeHr = null;
            try {
                $opened = Carbon::parse((string) $a->created_at);
                if ($a->acknowledged_at) {
                    $ackMin = (int) $opened->diffInMinutes(Carbon::parse((string) $a->acknowledged_at));
                    $ackMinutes[] = $ackMin;
                    if     ($ackMin < 60)     { $ackBuckets['<1h']++; }
                    elseif ($ackMin < 240)    { $ackBuckets['1–4h']++; }
                    elseif ($ackMin < 1440)   { $ackBuckets['4–24h']++; }
                    else                      { $ackBuckets['>24h']++; }
                } else {
                    $ackBuckets['Not yet']++;
                    $unack++;
                }
                if ($a->closed_at) {
                    $closeHr = round($opened->diffInMinutes(Carbon::parse((string) $a->closed_at)) / 60, 1);
                    $closeHours[] = $closeHr;
                    $totalClosed++;
                    if ($closeHr <= 24)  { $closedWithin24h++; }
                    if ($closeHr <= 168) { $closedWithin7d++; }
                }
            } catch (\Throwable $e) { /* skip */ }

            $statusKey = strtoupper((string) ($a->status ?? 'OPEN'));
            if (! isset($statusBuckets[$statusKey])) { $statusBuckets[$statusKey] = 0; }
            $statusBuckets[$statusKey]++;

            if ((int) ($a->reopen_count ?? 0) > 0) { $reopened++; }

            $cc = (string) ($a->close_category ?? '');
            if ($cc !== '') { $closeCats[$cc] = ($closeCats[$cc] ?? 0) + 1; }

            $rows[] = [
                'alert_id'              => (int) $a->id,
                'alert_code'            => $a->alert_code,
                'opened_at_iso'         => (string) $a->created_at,
                'opened_at_label'       => $this->humanDate((string) $a->created_at),
                'acknowledged_at_label' => $a->acknowledged_at ? $this->humanDate((string) $a->acknowledged_at) : null,
                'closed_at_label'       => $a->closed_at ? $this->humanDate((string) $a->closed_at) : null,
                'ack_min'               => $ackMin,
                'close_hr'              => $closeHr,
                'traveller_name'        => $this->displayName($s),
                'age'                   => $s?->traveler_age_years !== null ? (int) $s?->traveler_age_years : null,
                'sex'                   => $s?->traveler_gender,
                'nationality'           => $s?->traveler_nationality_country_code,
                'risk'                  => $a->risk_level,
                'status'                => $a->status,
                'close_category'        => $a->close_category,
                'close_note'            => $a->close_note,
                'reopens'               => (int) ($a->reopen_count ?? 0),
                'poe_name'              => $poeNames[$a->poe_code] ?? $a->poe_code,
                'case_file_url'         => url("/admin/alerts/{$a->id}/case-file"),
            ];
        }

        $total = count($rows);
        $kpis = [
            'total_in_window'     => $total,
            'median_ack_min'      => $ackMinutes ? (int) $this->median($ackMinutes) : null,
            'median_close_hr'     => $closeHours ? round($this->median($closeHours), 1) : null,
            'closed'              => $totalClosed,
            'closed_within_24h_pct' => $totalClosed > 0 ? round(($closedWithin24h / $totalClosed) * 100, 1) : null,
            'closed_within_7d_pct'  => $totalClosed > 0 ? round(($closedWithin7d  / $totalClosed) * 100, 1) : null,
            'reopened'            => $reopened,
            'unacked'             => $unack,
        ];

        // Sort table: still-open + breached SLAs first, then by recency
        usort($rows, function ($a, $b) {
            $aOpen = (string) ($a['status'] ?? '') === 'OPEN';
            $bOpen = (string) ($b['status'] ?? '') === 'OPEN';
            if ($aOpen !== $bOpen) { return $aOpen ? -1 : 1; }
            return strcmp((string) $b['opened_at_iso'], (string) $a['opened_at_iso']);
        });
        $tableVisible = array_slice($rows, 0, self::TABLE_LIMIT);

        $chart = $this->pickChart($closeCats, $ackBuckets, $statusBuckets, $windowLabel, $total);

        return [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
                         'days' => (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400) + 1,
                         'label' => $windowLabel],
            'kpis'       => $kpis,
            'chart'      => $chart,
            'table'      => $tableVisible,
            'table_full' => $rows,
            'total_rows' => $total,
            'shown_rows' => count($tableVisible),
            'meta' => [
                'poes'             => $this->scope->allowedPoes($scope),
                'risks'            => ['LOW','MEDIUM','HIGH','CRITICAL'],
                'statuses'         => ['OPEN','ACKNOWLEDGED','IN_PROGRESS','CLOSED','REOPENED'],
                'close_categories' => array_keys(self::CLOSE_LABELS),
            ],
        ];
    }

    /** Adaptive chart picker — closure outcomes first, then ack-time, then status. */
    private function pickChart(array $closeCats, array $ackBuckets, array $status, string $windowLabel, int $total): array
    {
        // A — closure breakdown
        if ($closeCats) {
            arsort($closeCats);
            $labels = []; $values = []; $colors = []; $i = 0;
            foreach ($closeCats as $cc => $count) {
                if ($i >= self::CHART_TOP_N) { break; }
                $labels[] = self::CLOSE_LABELS[$cc] ?? $cc;
                $values[] = (int) $count;
                $colors[] = self::CLOSE_COLORS[$cc] ?? '#546E7A';
                $i++;
            }
            return [
                'kind'     => 'closure',
                'title'    => 'Closure outcomes',
                'subtitle' => 'How alerts ended. Red bars = confirmed cases. Green = ruled-out / no case. Blue = false positive. Click any row to read the closure note.',
                'labels'   => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'alerts',
            ];
        }

        // B — acknowledgement-time buckets
        if (array_sum($ackBuckets) > 0) {
            $labels = []; $values = []; $colors = [];
            $palette = ['<1h' => '#43A047', '1–4h' => '#FB8C00', '4–24h' => '#E64A19', '>24h' => '#C62828', 'Not yet' => '#546E7A'];
            foreach (['<1h','1–4h','4–24h','>24h','Not yet'] as $k) {
                $labels[] = $k;
                $values[] = $ackBuckets[$k] ?? 0;
                $colors[] = $palette[$k];
            }
            return [
                'kind'     => 'ack',
                'title'    => 'Time to acknowledge',
                'subtitle' => 'How quickly officers picked up the alert. Green is fast, red is slow, blue-grey is still waiting.',
                'labels'   => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'alerts',
            ];
        }

        // C — status mix
        if (array_filter($status)) {
            $labels = []; $values = []; $colors = [];
            $palette = ['OPEN' => '#E53935', 'ACKNOWLEDGED' => '#FB8C00', 'IN_PROGRESS' => '#1E88E5', 'CLOSED' => '#43A047', 'REOPENED' => '#8E24AA'];
            $pretty  = ['OPEN' => 'Open', 'ACKNOWLEDGED' => 'Acknowledged', 'IN_PROGRESS' => 'In progress', 'CLOSED' => 'Closed', 'REOPENED' => 'Reopened'];
            foreach ($pretty as $k => $lbl) {
                if (($status[$k] ?? 0) === 0) { continue; }
                $labels[] = $lbl; $values[] = $status[$k]; $colors[] = $palette[$k];
            }
            return [
                'kind'     => 'status',
                'title'    => sprintf('Alert status mix · %d %s', $total, $total === 1 ? 'alert' : 'alerts'),
                'subtitle' => 'Where alerts sit right now. No closures have happened yet in this window.',
                'labels'   => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'alerts',
            ];
        }

        return [
            'kind' => 'empty',
            'title' => 'No alerts',
            'subtitle' => 'Nothing to report on. Widen the date range or clear a filter.',
            'labels' => [], 'values' => [], 'colors' => [], 'unit' => 'alerts',
        ];
    }

    /** @param array<int,int|float> $vals */
    private function median(array $vals): float
    {
        sort($vals);
        $n = count($vals);
        if ($n === 0) { return 0.0; }
        $m = (int) floor($n / 2);
        return $n % 2 ? (float) $vals[$m] : (($vals[$m - 1] + $vals[$m]) / 2.0);
    }

    private function displayName(?object $s): string
    {
        if (! $s) { return 'Unknown traveller'; }
        $full = trim((string) ($s->traveler_full_name ?? ''));
        if ($full !== '') { return $full; }
        $init = trim((string) ($s->traveler_initials ?? ''));
        if ($init !== '') { return $init; }
        $anon = trim((string) ($s->traveler_anonymous_code ?? ''));
        if ($anon !== '') { return $anon; }
        return 'Unknown traveller';
    }

    private function humanDate(string $iso): string
    {
        if ($iso === '') { return '—'; }
        try { return Carbon::parse($iso)->setTimezone(config('app.timezone','Africa/Kampala'))->format('M j, H:i'); }
        catch (\Throwable $e) { return $iso; }
    }
}
