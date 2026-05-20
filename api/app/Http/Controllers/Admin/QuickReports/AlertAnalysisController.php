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
 * Quick Report · Alert Analysis.
 *
 * URL:    /admin/quick-reports/alert-analysis
 *
 * Question: "What pattern is the alert stream showing — risk distribution,
 * IHR tier mix, false-positive rate, and where are the surges coming from?"
 *
 * Pattern-detection view. Same cohort as Alert Database but the lens is
 * aggregated rather than per-row. The table shows only the most-recent
 * tier-1 / CRITICAL signals so the eye is drawn to high-stakes alerts.
 *
 * Adaptive chart cascade:
 *   A. Alerts per day × risk (stacked bar over time)
 *   B. Alerts by risk level
 *   C. Alerts by IHR tier
 *   D. Per-day series
 */
final class AlertAnalysisController extends BaseQuickReportController
{
    protected string $reportKey   = 'qr-alert-intel';
    protected string $reportTitle = 'Alert Analysis';

    private const TABLE_LIMIT = 20;
    private const CHART_TOP_N = 12;

    private const RISK_COLORS = [
        'LOW'      => '#43A047',
        'MEDIUM'   => '#FB8C00',
        'HIGH'     => '#E64A19',
        'CRITICAL' => '#C62828',
        'UNKNOWN'  => '#546E7A',
    ];

    private const TIER_COLORS = [
        1 => '#C62828', // red 800 — always notifiable
        2 => '#FB8C00', // orange 600 — Annex 2 review
        3 => '#43A047', // green 600 — routine
    ];

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.quick.alert-intel.index', [
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
            'Opened (Africa/Kampala)', 'Alert code', 'Traveller', 'Risk', 'IHR tier',
            'Status', 'Point of entry', 'Case file URL',
        ];
        $rows = [];
        foreach ($payload['table_full'] as $r) {
            $rows[] = [
                $r['opened_at_label'], $r['alert_code'] ?? '', $r['traveller_name'],
                $r['risk'] ?? '—', $r['ihr_tier'] ?? '—',
                $r['status'] ?? '—', $r['poe_name'] ?? '—', $r['case_file_url'] ?? '',
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
        $days         = (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400) + 1;

        $q = DB::table('alerts')
            ->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to]);
        $this->scope->apply($q, $scope);

        if (! empty($filters['poe']))      { $q->where('poe_code', (string) $filters['poe']); }
        if (! empty($filters['risk']))     { $q->where('risk_level', (string) $filters['risk']); }
        if (! empty($filters['status']))   { $q->where('status', (string) $filters['status']); }
        if (! empty($filters['ihr_tier'])) { $q->where('ihr_tier', (int) $filters['ihr_tier']); }

        $alerts = $q->select([
                'id','client_uuid','alert_code','status','risk_level','ihr_tier',
                'poe_code','secondary_screening_id','close_category','created_at','closed_at',
            ])
            ->orderBy('created_at','desc')->orderBy('id','desc')
            ->get();

        // De-dup by client_uuid
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

        // Aggregations
        $riskBuckets = ['CRITICAL'=>0,'HIGH'=>0,'MEDIUM'=>0,'LOW'=>0,'UNKNOWN'=>0];
        $tierBuckets = [1=>0, 2=>0, 3=>0, 0=>0]; // 0 = no tier
        $dayBuckets  = [];                       // 'M j' => total
        $dayRisk     = [];                       // 'M j' => ['CRITICAL'=>n, ...]
        $falsePosCount = 0;                      // close_category = NO_CASE / DISCARDED
        $totalClosed   = 0;
        $kpi24h        = 0;
        $now24h        = Carbon::now()->subDay();

        // Spotlight rows (HIGH/CRITICAL or IHR tier-1, sorted by recency)
        $spotlight = [];
        foreach ($alerts as $a) {
            $riskKey = strtoupper((string) ($a->risk_level ?? 'UNKNOWN'));
            if (! isset($riskBuckets[$riskKey])) { $riskKey = 'UNKNOWN'; }
            $riskBuckets[$riskKey]++;

            $tierKey = (int) ($a->ihr_tier ?? 0);
            $tierBuckets[$tierKey] = ($tierBuckets[$tierKey] ?? 0) + 1;

            try {
                $c = Carbon::parse((string) $a->created_at);
                $d = $c->setTimezone(config('app.timezone','Africa/Kampala'))->format('M j');
                $dayBuckets[$d] = ($dayBuckets[$d] ?? 0) + 1;
                if (! isset($dayRisk[$d])) { $dayRisk[$d] = ['CRITICAL'=>0,'HIGH'=>0,'MEDIUM'=>0,'LOW'=>0,'UNKNOWN'=>0]; }
                $dayRisk[$d][$riskKey]++;
                if ($c->greaterThanOrEqualTo($now24h)) { $kpi24h++; }
            } catch (\Throwable $e) { /* skip */ }

            $cc = strtoupper((string) ($a->close_category ?? ''));
            if ($cc !== '') {
                $totalClosed++;
                if (in_array($cc, ['NO_CASE','DISCARDED','NOT_A_CASE','FALSE_POSITIVE'], true)) {
                    $falsePosCount++;
                }
            }

            // Spotlight if HIGH/CRITICAL or IHR tier-1
            $isSpot = $riskKey === 'HIGH' || $riskKey === 'CRITICAL' || $tierKey === 1;
            if ($isSpot && count($spotlight) < self::TABLE_LIMIT * 2) {
                $sid = (int) ($a->secondary_screening_id ?? 0);
                $s   = $sid && isset($secById[$sid]) ? $secById[$sid] : null;
                $spotlight[] = [
                    'alert_id'        => (int) $a->id,
                    'alert_code'      => $a->alert_code,
                    'opened_at_iso'   => (string) $a->created_at,
                    'opened_at_label' => $this->humanDate((string) $a->created_at),
                    'traveller_name'  => $this->displayName($s),
                    'age'             => $s?->traveler_age_years !== null ? (int) $s?->traveler_age_years : null,
                    'sex'             => $s?->traveler_gender,
                    'nationality'     => $s?->traveler_nationality_country_code,
                    'risk'            => $a->risk_level,
                    'ihr_tier'        => $a->ihr_tier !== null ? (int) $a->ihr_tier : null,
                    'status'          => $a->status,
                    'poe_name'        => $poeNames[$a->poe_code] ?? $a->poe_code,
                    'case_file_url'   => url("/admin/alerts/{$a->id}/case-file"),
                ];
            }
        }

        // Order the day buckets ascending for time-series
        ksort($dayBuckets);
        $orderedDayRisk = [];
        foreach (array_keys($dayBuckets) as $d) { $orderedDayRisk[$d] = $dayRisk[$d]; }

        $kpis = [
            'total'         => $alerts->count(),
            'critical_high' => ($riskBuckets['CRITICAL'] ?? 0) + ($riskBuckets['HIGH'] ?? 0),
            'ihr_tier1'     => $tierBuckets[1] ?? 0,
            'false_positive_pct' => $totalClosed > 0 ? round(($falsePosCount / $totalClosed) * 100, 1) : null,
            'closed'        => $totalClosed,
            'last_24h'      => $kpi24h,
        ];

        $chart = $this->pickChart($alerts->count(), $orderedDayRisk, $riskBuckets, $tierBuckets, $dayBuckets, $windowLabel, $days);

        return [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
                         'days' => $days, 'label' => $windowLabel],
            'kpis'       => $kpis,
            'chart'      => $chart,
            'table'      => array_slice($spotlight, 0, self::TABLE_LIMIT),
            'table_full' => $spotlight,
            'total_rows' => $alerts->count(),
            'shown_rows' => count(array_slice($spotlight, 0, self::TABLE_LIMIT)),
            'meta' => [
                'poes'     => $this->scope->allowedPoes($scope),
                'risks'    => ['LOW','MEDIUM','HIGH','CRITICAL'],
                'statuses' => ['OPEN','ACKNOWLEDGED','IN_PROGRESS','CLOSED','REOPENED'],
                'ihr_tiers'=> [1, 2, 3],
            ],
        ];
    }

    /** Adaptive chart picker — daily risk trend first (most actionable), then risk, tier, day. */
    private function pickChart(int $total, array $dayRisk, array $risk, array $tier, array $day, string $windowLabel, int $days): array
    {
        // A — time series only when window has enough days AND multiple days have signal
        if ($days >= 3 && count(array_filter($day)) >= 2) {
            // For one chart we sum the day totals (single bar per day),
            // with the bar coloured by the dominant risk on that day so
            // the eye reads "where the spike was" + "how serious it was".
            $labels = array_keys($day);
            $values = array_values($day);
            $colors = [];
            foreach ($labels as $d) {
                $dominant = 'UNKNOWN';
                $dominantN = 0;
                foreach ($dayRisk[$d] ?? [] as $k => $n) {
                    if ($n > $dominantN) { $dominantN = $n; $dominant = $k; }
                }
                $colors[] = self::RISK_COLORS[$dominant] ?? '#546E7A';
            }
            return [
                'kind'     => 'day_risk',
                'title'    => 'Alerts per day',
                'subtitle' => 'Bar height is the count for that day. Bar colour is the dominant risk on that day — red for Critical, deep orange for High, orange for Medium, green for Low.',
                'labels'   => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'alerts',
            ];
        }

        // B — risk level
        if (array_filter($risk)) {
            $labels = []; $values = []; $colors = [];
            foreach (['CRITICAL','HIGH','MEDIUM','LOW','UNKNOWN'] as $k) {
                if (($risk[$k] ?? 0) === 0) { continue; }
                $labels[] = $k === 'UNKNOWN' ? 'Not set' : ucfirst(strtolower($k));
                $values[] = $risk[$k]; $colors[] = self::RISK_COLORS[$k];
            }
            return [
                'kind'     => 'risk',
                'title'    => sprintf('Alerts by risk level · %d %s', $total, $total === 1 ? 'alert' : 'alerts'),
                'subtitle' => 'Critical and High share = same-day clinical attention. Low / Not-set lean toward routine review.',
                'labels'   => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'alerts',
            ];
        }

        // C — IHR tier
        if (array_filter($tier)) {
            $labels = []; $values = []; $colors = [];
            foreach ([1, 2, 3] as $k) {
                if (($tier[$k] ?? 0) === 0) { continue; }
                $labels[] = ['1' => 'Tier 1 · Always notifiable', '2' => 'Tier 2 · Annex 2 review', '3' => 'Tier 3 · Routine'][(string) $k];
                $values[] = $tier[$k]; $colors[] = self::TIER_COLORS[$k];
            }
            if (! empty($tier[0])) { $labels[] = 'Not classified'; $values[] = $tier[0]; $colors[] = '#546E7A'; }
            return [
                'kind'     => 'tier',
                'title'    => 'Alerts by IHR tier',
                'subtitle' => 'How the WHO classifies each alert. Tier 1 always notifiable; Tier 2 review against Annex 2; Tier 3 routine.',
                'labels'   => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'alerts',
            ];
        }

        return [
            'kind' => 'empty',
            'title' => 'No alerts',
            'subtitle' => 'Nothing to analyse in this window. Widen the date range or clear a filter.',
            'labels' => [], 'values' => [], 'colors' => [], 'unit' => 'alerts',
        ];
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
