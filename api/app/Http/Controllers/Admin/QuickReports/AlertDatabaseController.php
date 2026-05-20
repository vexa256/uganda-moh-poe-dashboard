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
 * Quick Report · Alert Database.
 *
 * URL:    /admin/quick-reports/alert-database
 *
 * Question: "What alerts exist right now — who opened them, who owns them,
 * what's the severity, and which are still on fire?"
 *
 * Pure register view. One row = one alert. The adaptive chart picks the
 * most-informative dimension:
 *   A. Alerts by risk level (semantic colours — CRITICAL/HIGH glow)
 *   B. Alerts by status
 *   C. Alerts by point of entry
 *   D. Per-day time series
 */
final class AlertDatabaseController extends BaseQuickReportController
{
    protected string $reportKey   = 'qr-alert-db';
    protected string $reportTitle = 'Alert Database';

    private const TABLE_LIMIT = 20;
    private const CHART_TOP_N = 12;

    // alerts.status DB enum is OPEN / ACKNOWLEDGED / CLOSED only.
    // Reopens are NOT a status — they're tracked via alerts.reopen_count.
    private const STATUS_LABELS = [
        'OPEN' => 'Open', 'ACKNOWLEDGED' => 'Acknowledged', 'CLOSED' => 'Closed',
    ];
    private const STATUS_COLORS = [
        'OPEN'         => '#E53935', // red 600 — needs attention
        'ACKNOWLEDGED' => '#FB8C00', // orange 600
        'CLOSED'       => '#43A047', // green 600 — closed = good
    ];
    private const RISK_COLORS = [
        'LOW'      => '#43A047',
        'MEDIUM'   => '#FB8C00',
        'HIGH'     => '#E64A19',
        'CRITICAL' => '#C62828',
        'UNKNOWN'  => '#546E7A',
    ];
    private const MATERIAL_PALETTE = [
        '#E53935','#1E88E5','#43A047','#FB8C00','#8E24AA','#00ACC1',
        '#F4511E','#3949AB','#7CB342','#D81B60','#FFB300','#00897B',
    ];

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.quick.alert-db.index', [
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
            'Opened (Africa/Kampala)','Alert code','Alert title','Traveller','Age','Sex','Nationality',
            'Risk','IHR tier','Status','Owner','Acknowledged at','Closed at','Reopens',
            'Point of entry','Case file URL',
        ];
        $rows = [];
        foreach ($payload['table_full'] as $r) {
            $rows[] = [
                $r['opened_at_label'], $r['alert_code'] ?? '', $r['alert_title'] ?? '',
                $r['traveller_name'], $r['age'] ?? '', $r['sex'] ?? '', $r['nationality'] ?? '',
                $r['risk'] ?? '—',
                $r['ihr_tier'] ?? '—',
                self::STATUS_LABELS[$r['status']] ?? $r['status'] ?? '—',
                $r['owner_name'] ?? '—',
                $r['acknowledged_at_label'] ?? '—',
                $r['closed_at_label'] ?? '—',
                (int) ($r['reopen_count'] ?? 0),
                $r['poe_name'] ?? '—',
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

        if (! empty($filters['poe']))      { $q->where('poe_code', (string) $filters['poe']); }
        if (! empty($filters['risk']))     { $q->where('risk_level', (string) $filters['risk']); }
        if (! empty($filters['status']))   { $q->where('status', (string) $filters['status']); }
        if (! empty($filters['ihr_tier'])) { $q->where('ihr_tier', (int) $filters['ihr_tier']); }
        if (! empty($filters['owner']))    { $q->where('current_owner_user_id', (int) $filters['owner']); }

        $alerts = $q->select([
                'id','client_uuid','alert_code','alert_title','status','risk_level','ihr_tier',
                'poe_code','secondary_screening_id','current_owner_user_id','acknowledged_at',
                'closed_at','reopen_count','created_at',
            ])
            ->orderBy('created_at', 'desc')->orderBy('id', 'desc')
            ->get();

        // De-dup by client_uuid (mobile sync paranoia)
        $byUuid = []; $dedup = [];
        foreach ($alerts as $a) {
            if (! $a->client_uuid) { $dedup[] = $a; continue; }
            if (! isset($byUuid[$a->client_uuid]) || (int) $a->id > (int) $byUuid[$a->client_uuid]->id) {
                $byUuid[$a->client_uuid] = $a;
            }
        }
        foreach ($byUuid as $a) { $dedup[] = $a; }
        $alerts = collect($dedup)->sortByDesc(fn ($r) => [(string) $r->created_at, (int) $r->id])->values();

        // Optional free-text search across alert + traveller fields
        $needle = ! empty($filters['q']) ? strtolower((string) $filters['q']) : null;

        $secIds   = $alerts->pluck('secondary_screening_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $ownerIds = $alerts->pluck('current_owner_user_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $poeCodes = $alerts->pluck('poe_code')->filter()->unique()->values()->all();

        // Enrichments (no JOINs — collation-safe)
        $sec = $secIds ? DB::table('secondary_screenings')
            ->whereIn('id', $secIds)
            ->get(['id','traveler_full_name','traveler_initials','traveler_anonymous_code',
                   'traveler_age_years','traveler_gender','traveler_nationality_country_code'])
            : collect();
        $secById = [];
        foreach ($sec as $s) { $secById[(int) $s->id] = $s; }

        $ownerNames = $ownerIds ? DB::table('users')->whereIn('id', $ownerIds)
            ->pluck('full_name', 'id')->all() : [];

        $poeNames = $poeCodes ? DB::table('ref_poes')->whereIn('poe_code', $poeCodes)
            ->pluck('poe_name', 'poe_code')->all() : [];

        // Build rows + facets in one pass
        $rows = [];
        $riskBuckets   = ['CRITICAL'=>0,'HIGH'=>0,'MEDIUM'=>0,'LOW'=>0,'UNKNOWN'=>0];
        $statusBuckets = ['OPEN'=>0,'ACKNOWLEDGED'=>0,'CLOSED'=>0];
        $poeBuckets    = [];
        $dayBuckets    = [];
        $kpi24h = 0;
        $now24h = Carbon::now()->subDay();
        $kpiIhr1 = 0;
        $kpiReopened = 0;

        foreach ($alerts as $a) {
            $sid = (int) ($a->secondary_screening_id ?? 0);
            $s   = $sid && isset($secById[$sid]) ? $secById[$sid] : null;
            $ownerId = (int) ($a->current_owner_user_id ?? 0);

            $row = [
                'alert_id'         => (int) $a->id,
                'alert_code'       => $a->alert_code,
                'alert_title'      => $a->alert_title,
                'opened_at_iso'    => (string) $a->created_at,
                'opened_at_label'  => $this->humanDate((string) $a->created_at),
                'age_label'        => $this->ageSince((string) $a->created_at),
                'traveller_name'   => $this->displayName($s),
                'age'              => $s?->traveler_age_years !== null ? (int) $s?->traveler_age_years : null,
                'sex'              => $s?->traveler_gender,
                'nationality'      => $s?->traveler_nationality_country_code,
                'risk'             => $a->risk_level,
                'ihr_tier'         => $a->ihr_tier !== null ? (int) $a->ihr_tier : null,
                'status'           => $a->status,
                'owner_id'         => $ownerId ?: null,
                'owner_name'       => $ownerId ? ($ownerNames[$ownerId] ?? '—') : null,
                'acknowledged_at_label' => $a->acknowledged_at ? $this->humanDate((string) $a->acknowledged_at) : null,
                'closed_at_label'  => $a->closed_at ? $this->humanDate((string) $a->closed_at) : null,
                'reopen_count'     => (int) ($a->reopen_count ?? 0),
                'poe_name'         => $poeNames[$a->poe_code] ?? $a->poe_code,
                'case_file_url'    => url("/admin/alerts/{$a->id}/case-file"),
            ];

            if ($needle) {
                $hay = strtolower(implode(' ', array_filter([
                    $row['traveller_name'], $row['alert_code'], $row['alert_title'],
                    $row['risk'], $row['owner_name'], $row['poe_name'],
                ])));
                if (strpos($hay, $needle) === false) { continue; }
            }

            $rows[] = $row;

            $riskKey = strtoupper((string) ($a->risk_level ?? 'UNKNOWN'));
            if (! isset($riskBuckets[$riskKey])) { $riskKey = 'UNKNOWN'; }
            $riskBuckets[$riskKey]++;

            $statusKey = strtoupper((string) ($a->status ?? 'OPEN'));
            if (! isset($statusBuckets[$statusKey])) { $statusBuckets[$statusKey] = 0; }
            $statusBuckets[$statusKey]++;

            $poeKey = (string) ($a->poe_code ?? '');
            if ($poeKey !== '') { $poeBuckets[$poeKey] = ($poeBuckets[$poeKey] ?? 0) + 1; }

            try {
                $c = Carbon::parse((string) $a->created_at);
                $dKey = $c->setTimezone(config('app.timezone','Africa/Kampala'))->format('M j');
                $dayBuckets[$dKey] = ($dayBuckets[$dKey] ?? 0) + 1;
                if ($c->greaterThanOrEqualTo($now24h)) { $kpi24h++; }
            } catch (\Throwable $e) { /* skip */ }

            if ((int) ($a->ihr_tier ?? 0) === 1) { $kpiIhr1++; }
            if ((int) ($a->reopen_count ?? 0) > 0) { $kpiReopened++; }
        }

        $totalShown = count($rows);
        $tableVisible = array_slice($rows, 0, self::TABLE_LIMIT);

        $kpis = [
            'total'        => $totalShown,
            'open'         => $statusBuckets['OPEN'] ?? 0,
            'acknowledged' => $statusBuckets['ACKNOWLEDGED'] ?? 0,
            'closed'       => $statusBuckets['CLOSED'] ?? 0,
            'reopened'     => $kpiReopened,
            'ihr_tier1'    => $kpiIhr1,
            'last_24h'     => $kpi24h,
        ];

        $chart = $this->pickChart($riskBuckets, $statusBuckets, $poeBuckets, $dayBuckets, $poeNames, $windowLabel, $totalShown);

        // Build owner dropdown list (scope-aware via the same users we already enriched)
        $ownerOptions = [];
        foreach ($ownerNames as $id => $name) { $ownerOptions[(int) $id] = $name; }
        asort($ownerOptions);

        return [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
                         'days' => (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400) + 1,
                         'label' => $windowLabel],
            'kpis'       => $kpis,
            'chart'      => $chart,
            'table'      => $tableVisible,
            'table_full' => $rows,
            'total_rows' => $totalShown,
            'shown_rows' => count($tableVisible),
            'meta' => [
                'poes'     => $this->scope->allowedPoes($scope),
                'risks'    => ['LOW','MEDIUM','HIGH','CRITICAL'],
                'statuses' => array_keys(self::STATUS_LABELS),
                'owners'   => $ownerOptions,
            ],
        ];
    }

    /** Adaptive chart picker — risk first (most decision-relevant), then status, POE, day. */
    private function pickChart(array $risk, array $status, array $poe, array $day, array $poeNames, string $windowLabel, int $total): array
    {
        $riskNonZero = array_filter($risk);
        if ($riskNonZero) {
            $labels = []; $values = []; $colors = [];
            foreach (['CRITICAL','HIGH','MEDIUM','LOW','UNKNOWN'] as $k) {
                if (($risk[$k] ?? 0) === 0) { continue; }
                $labels[] = $k === 'UNKNOWN' ? 'Not set' : ucfirst(strtolower($k));
                $values[] = $risk[$k];
                $colors[] = self::RISK_COLORS[$k];
            }
            return [
                'kind'     => 'risk',
                'title'    => sprintf('Alerts by risk level · %d %s', $total, $total === 1 ? 'alert' : 'alerts'),
                'subtitle' => 'Severity tier the alert was opened with. Critical and High demand same-day attention.',
                'labels'   => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'alerts',
            ];
        }

        $statusNonZero = array_filter($status);
        if ($statusNonZero) {
            $labels = []; $values = []; $colors = [];
            foreach (self::STATUS_LABELS as $k => $lbl) {
                if (($status[$k] ?? 0) === 0) { continue; }
                $labels[] = $lbl; $values[] = $status[$k]; $colors[] = self::STATUS_COLORS[$k] ?? '#546E7A';
            }
            return [
                'kind'     => 'status',
                'title'    => 'Alerts by status',
                'subtitle' => 'Pipeline position right now: Open / Acknowledged / Closed / Reopened.',
                'labels'   => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'alerts',
            ];
        }

        if ($poe) {
            arsort($poe);
            $labels = []; $values = []; $i = 0;
            foreach ($poe as $code => $count) {
                if ($i >= self::CHART_TOP_N) { break; }
                $labels[] = $poeNames[$code] ?? $code;
                $values[] = $count; $i++;
            }
            return [
                'kind'     => 'poe',
                'title'    => 'Alerts by point of entry',
                'subtitle' => 'Where the alerts came from.',
                'labels'   => $labels, 'values' => $values, 'colors' => $this->cyclePalette(count($labels)), 'unit' => 'alerts',
            ];
        }

        if ($day) {
            return [
                'kind'     => 'day',
                'title'    => 'Alerts per day',
                'subtitle' => 'When the alerts were opened.',
                'labels'   => array_keys($day), 'values' => array_values($day),
                'colors'   => $this->cyclePalette(count($day)), 'unit' => 'alerts',
            ];
        }

        return [
            'kind' => 'empty',
            'title' => 'No alerts',
            'subtitle' => 'Widen the date range or clear a filter.',
            'labels' => [], 'values' => [], 'colors' => [], 'unit' => 'alerts',
        ];
    }

    private function cyclePalette(int $n): array
    {
        $out = []; $p = self::MATERIAL_PALETTE; $len = count($p);
        for ($i = 0; $i < $n; $i++) { $out[] = $p[$i % $len]; }
        return $out;
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

    private function ageSince(string $iso): string
    {
        try {
            $diff = Carbon::parse($iso)->diffForHumans(['short' => true, 'parts' => 1, 'syntax' => Carbon::DIFF_RELATIVE_TO_NOW]);
            return str_replace([' ago','before','after'], '', $diff) . ' ago';
        } catch (\Throwable $e) { return '—'; }
    }
}
