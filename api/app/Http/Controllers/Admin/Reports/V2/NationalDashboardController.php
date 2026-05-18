<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports\V2;

use App\Http\Controllers\Admin\Reports\BaseReportController;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * National Dashboard · `rpt-national-dashboard`.
 *
 * Landing page for every authenticated admin. Cross-cutting executive
 * overview that answers: "Right now: is everything healthy, where is risk
 * concentrating, and where should I drill?"
 *
 * Performance contract: 8 small selectRaw + groupBy queries, never one mega
 * join. No payload caching. Records table paginates server-side on alerts
 * with bounded date window + scope. Drill-down uses the Smart Wizard payload
 * shape (alert + traveller + outcome + followups + timeline).
 */
final class NationalDashboardController extends BaseReportController
{
    protected string $reportKey   = 'rpt-national-dashboard';
    protected string $reportTitle = 'National Dashboard';

    private const RECORDS_LIMIT = 10;
    private const TOP_LIMIT     = 10;
    private const DARK_DAYS     = 7;

    /* ──────────────────────────────────────────────────────────────────
     * 1.  index — Blade shell.
     * ────────────────────────────────────────────────────────────────── */
    public function index(Request $request): View
    {
        $this->ensureAccess($request);
        return view('admin.reports.v2.rpt-national-dashboard', [
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 2.  meta — filter dropdowns + scope label.
     * ────────────────────────────────────────────────────────────────── */
    public function meta(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        return $this->ok([
            'poes'  => $this->scope->allowedPoes($scope),
            'scope' => [
                'label'    => $scope['label'] ?? '—',
                'level'    => $scope['scope_level'] ?? 'SELF',
                'is_super' => (bool) ($scope['is_super'] ?? false),
            ],
            'data_notes' => $this->dataNotes(),
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 3.  kpis — 6 tiles.
     * ────────────────────────────────────────────────────────────────── */
    public function kpis(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        // Screenings in window (primary).
        $sq = DB::table('primary_screenings')->whereNull('deleted_at')
            ->whereBetween('captured_at', [$from, $to]);
        $this->scope->apply($sq, $scope);
        if (! empty($f['poe'])) { $sq->where('poe_code', $f['poe']); }
        $screenings = (int) $sq->count();

        // Open alerts.
        $aq = DB::table('alerts')->whereNull('deleted_at')
            ->whereIn('status', ['OPEN', 'ACKNOWLEDGED']);
        $this->scope->apply($aq, $scope);
        if (! empty($f['poe'])) { $aq->where('poe_code', $f['poe']); }
        $alertsOpen = (int) $aq->count();

        // SLA breach % — alerts in window with hours_open > sla.
        $slaQ = DB::table('alerts')->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to]);
        $this->scope->apply($slaQ, $scope);
        if (! empty($f['poe'])) { $slaQ->where('poe_code', $f['poe']); }
        $slaRow = (clone $slaQ)->selectRaw("
            COUNT(*) AS total,
            SUM(
                (risk_level='CRITICAL' AND TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, NOW())) > 4) OR
                (risk_level='HIGH'     AND TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, NOW())) > 24) OR
                (risk_level NOT IN ('CRITICAL','HIGH') AND TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, NOW())) > 48)
            ) AS breached
        ")->first();
        $slaTotal = (int) ($slaRow->total ?? 0);
        $slaBreach = (int) ($slaRow->breached ?? 0);
        $slaPct = $this->safePct($slaBreach, $slaTotal);

        // Confirmed cases in window.
        $confQ = DB::table('alert_case_outcomes AS o')
            ->join('alerts AS a', 'a.id', '=', 'o.alert_id')
            ->whereNull('o.deleted_at')->whereNull('a.deleted_at')
            ->whereBetween('a.created_at', [$from, $to])
            ->where('o.case_classification', 'CONFIRMED');
        $this->scope->apply($confQ, $scope, 'a');
        if (! empty($f['poe'])) { $confQ->where('a.poe_code', $f['poe']); }
        $confirmed = (int) $confQ->count();

        // Dark POEs (7d) — bounded by scope's allowed set.
        $allowed = $this->scope->allowedPoes($scope);
        $allowedCodes = array_keys($allowed);
        $darkCount = 0;
        if (! empty($allowedCodes)) {
            $threshold = Carbon::now()->subDays(self::DARK_DAYS);
            $screen7d = DB::table('primary_screenings')->whereNull('deleted_at')
                ->where('captured_at', '>=', $threshold)
                ->whereIn('poe_code', $allowedCodes)
                ->selectRaw('poe_code, COUNT(*) AS c')
                ->groupBy('poe_code');
            $this->scope->apply($screen7d, $scope);
            $active = $screen7d->pluck('c', 'poe_code')->all();
            foreach ($allowedCodes as $code) {
                $name = $allowed[$code] ?? $code;
                $any = (int) (($active[$code] ?? 0) + ($active[$name] ?? 0));
                if ($any === 0) { $darkCount++; }
            }
        }

        // Endemic-origin share % over window.
        $secQ = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($secQ, $scope);
        if (! empty($f['poe'])) { $secQ->where('poe_code', $f['poe']); }
        $secTotal = (int) (clone $secQ)->count();
        $endemicShare = null;
        if ($secTotal > 0) {
            $codes = (clone $secQ)->whereNotNull('journey_start_country_code')
                ->select('journey_start_country_code')
                ->distinct()->pluck('journey_start_country_code')->all();
            $endemicCodes = $this->resolveEndemicCodes($codes);
            $endemicTrav = empty($endemicCodes) ? 0 : (int) (clone $secQ)
                ->whereIn('journey_start_country_code', $endemicCodes)
                ->count();
            $endemicShare = $this->safePct($endemicTrav, $secTotal);
        }

        return $this->ok([
            'kpis' => [
                ['key' => 'screenings_window', 'label' => 'Screenings (window)', 'value' => number_format($screenings), 'tone' => 'brand', 'hint' => 'Primary screenings captured in window.', 'href_report' => 'rpt-screening-overview'],
                ['key' => 'alerts_open',       'label' => 'Open Alerts',         'value' => number_format($alertsOpen), 'tone' => $alertsOpen > 0 ? 'warning' : 'success', 'hint' => 'OPEN + ACKNOWLEDGED, not yet closed.', 'href_report' => 'rpt-alert-intel'],
                ['key' => 'sla_breach_pct',    'label' => 'SLA Breach %',        'value' => $slaPct === null ? '—' : ($slaPct . '%'), 'tone' => $this->slaTone($slaPct), 'hint' => 'Alerts past their SLA window.', 'href_report' => 'rpt-response-time'],
                ['key' => 'confirmed_cases',   'label' => 'Confirmed Cases',     'value' => number_format($confirmed),  'tone' => $confirmed > 0 ? 'critical' : 'success', 'hint' => 'Alerts with classification=CONFIRMED.', 'href_report' => 'rpt-resolution-db'],
                ['key' => 'dark_poes',         'label' => 'Dark POEs (' . self::DARK_DAYS . 'd)', 'value' => number_format($darkCount), 'tone' => $darkCount > 0 ? 'warning' : 'success', 'hint' => 'POEs without a screening in last ' . self::DARK_DAYS . ' days.', 'href_report' => 'rpt-poe-performance'],
                ['key' => 'endemic_share',     'label' => 'Endemic-origin Share', 'value' => $endemicShare === null ? '—' : ($endemicShare . '%'), 'tone' => ($endemicShare !== null && $endemicShare >= 25) ? 'warning' : 'success', 'hint' => '% of secondary travellers from endemic origin.', 'href_report' => 'rpt-country-travel'],
            ],
            'window' => [$from->toDateString(), $to->toDateString()],
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 4.  chart / chart CSV
     * ────────────────────────────────────────────────────────────────── */
    public function chart(Request $request, string $chart): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        return match ($chart) {
            'screenings_30d'   => $this->ok($this->chartScreenings30d($scope, $f)),
            'alerts_by_risk'   => $this->ok($this->chartAlertsByRisk($scope, $f)),
            'top_poes'         => $this->ok($this->chartTopPoes($scope, $f)),
            'top_origins'      => $this->ok($this->chartTopOrigins($scope, $f)),
            'outcome_mix'      => $this->ok($this->chartOutcomeMix($scope, $f)),
            'gender_mix'       => $this->ok($this->chartGenderMix($scope, $f)),
            'sla_status'       => $this->ok($this->chartSlaStatus($scope, $f)),
            'officer_activity' => $this->ok($this->chartOfficerActivity($scope, $f)),
            default            => $this->fail(404, 'Unknown chart key.'),
        };
    }

    public function chartCsv(Request $request, string $chart): StreamedResponse|Response
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        $payload = match ($chart) {
            'screenings_30d'   => $this->chartScreenings30d($scope, $f),
            'alerts_by_risk'   => $this->chartAlertsByRisk($scope, $f),
            'top_poes'         => $this->chartTopPoes($scope, $f),
            'top_origins'      => $this->chartTopOrigins($scope, $f),
            'outcome_mix'      => $this->chartOutcomeMix($scope, $f),
            'gender_mix'       => $this->chartGenderMix($scope, $f),
            'sla_status'       => $this->chartSlaStatus($scope, $f),
            'officer_activity' => $this->chartOfficerActivity($scope, $f),
            default            => abort(404, 'Unknown chart key.'),
        };
        return $this->writer->send(
            $this->reportKey, 'CSV',
            $payload['csv_headers'], $payload['csv_rows'],
            $f, (int) ($request->user()->id ?? 0),
            $this->reportTitle . ' · ' . $chart,
        );
    }

    /* ──────────────────────────────────────────────────────────────────
     * 5.  records — 10-row paginated "Cases Needing Attention".
     * ────────────────────────────────────────────────────────────────── */
    public function records(Request $request): JsonResponse
    {
        $scope    = $this->ensureAccess($request);
        $f        = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $page    = max(1, (int) $request->input('page', 1));
        $perPage = self::RECORDS_LIMIT;
        $sort    = (string) $request->input('sort', 'risk');
        $dir     = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        $base = DB::table('alerts AS a')->whereNull('a.deleted_at')
            ->whereIn('a.status', ['OPEN', 'ACKNOWLEDGED'])
            ->whereBetween('a.created_at', [$from, $to]);
        $this->scope->apply($base, $scope, 'a');
        if (! empty($f['poe'])) { $base->where('a.poe_code', $f['poe']); }

        // Total via separate COUNT.
        $total = (int) (clone $base)->count('a.id');
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        // Sort map: priority = CRITICAL>HIGH>MEDIUM>LOW; oldest first when same risk.
        // Use FIELD() for risk ordering — MySQL-specific but our target is MySQL.
        $orderClause = match ($sort) {
            'age'  => "a.created_at " . $dir,
            'sla'  => "TIMESTAMPDIFF(HOUR, a.created_at, COALESCE(a.closed_at, NOW())) " . $dir,
            default => "FIELD(a.risk_level,'CRITICAL','HIGH','MEDIUM','LOW') " . ($dir === 'asc' ? 'asc' : 'asc') . ", a.created_at asc",
        };

        $rows = (clone $base)
            ->leftJoin('secondary_screenings AS s', 's.id', '=', 'a.secondary_screening_id')
            ->select([
                'a.id', 'a.alert_code', 'a.alert_title', 'a.poe_code',
                'a.risk_level', 'a.status', 'a.created_at', 'a.acknowledged_at',
                'a.closed_at',
                's.journey_start_country_code AS origin',
            ])
            ->orderByRaw($orderClause)
            ->forPage($page, $perPage)
            ->get()
            ->map(function ($r) {
                $created = $r->created_at ? Carbon::parse((string) $r->created_at) : null;
                $end     = $r->closed_at ? Carbon::parse((string) $r->closed_at) : Carbon::now();
                $hours   = $created ? $created->diffInHours($end) : 0;
                $sla     = match ((string) $r->risk_level) { 'CRITICAL' => 4, 'HIGH' => 24, default => 48 };
                return [
                    'id'              => (int) $r->id,
                    'alert_code'      => $r->alert_code,
                    'alert_title'     => $r->alert_title ?: ($r->alert_code ?: ('Alert #' . $r->id)),
                    'risk_level'      => $r->risk_level,
                    'status'          => $r->status,
                    'poe_code'        => $r->poe_code,
                    'origin'          => $r->origin,
                    'created_at'      => $r->created_at,
                    'acknowledged_at' => $r->acknowledged_at,
                    'age_hours'       => $hours,
                    'sla_hours'       => $sla,
                    'sla_breached'    => $hours > $sla,
                ];
            });

        return $this->ok([
            'rows' => $rows,
            'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to'   => min($page * $perPage, $total),
            ],
            'controls' => ['sort' => $sort, 'dir' => $dir],
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 6.  recordDetail — Smart Wizard payload (case-bearing).
     * ────────────────────────────────────────────────────────────────── */
    public function recordDetail(Request $request, string $key): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $id    = (int) $key;
        if ($id <= 0) { return $this->fail(404, 'Alert not found.'); }

        $a = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $a) { return $this->fail(404, 'Alert not found.'); }

        // Scope check: re-run the scope filter against this single row.
        $scopedCheck = DB::table('alerts')->where('id', $id)->whereNull('deleted_at');
        $this->scope->apply($scopedCheck, $scope);
        if (! $scopedCheck->exists()) {
            return $this->fail(403, 'Alert not in scope.');
        }

        $sec = $a->secondary_screening_id
            ? DB::table('secondary_screenings')->where('id', $a->secondary_screening_id)->first()
            : null;

        $outcome = DB::table('alert_case_outcomes')->where('alert_id', $id)->whereNull('deleted_at')->first();

        $followups = DB::table('alert_followups')->where('alert_id', $id)
            ->orderBy('due_at')->limit(20)
            ->get(['action_label', 'status', 'due_at', 'completed_at', 'completed_by_user_id', 'blocks_closure']);

        $followupAgg = DB::table('alert_followups')->where('alert_id', $id)->selectRaw("
            COUNT(*) AS total,
            SUM(status='COMPLETED') AS completed,
            SUM(status='PENDING')   AS pending,
            SUM(status='BLOCKED')   AS blocked,
            SUM(blocks_closure=1 AND status<>'COMPLETED') AS blocking
        ")->first();

        $timeline = DB::table('alert_timeline_events')->where('alert_id', $id)
            ->orderByDesc('created_at')->limit(25)
            ->get(['event_code', 'event_category', 'actor_name', 'summary', 'severity', 'created_at']);

        // Suspected diseases — two-query whereIn for cross-collation safety.
        $diseases = collect();
        if ($sec) {
            $sd = DB::table('secondary_suspected_diseases')
                ->where('secondary_screening_id', $sec->id)
                ->orderBy('rank_order')->limit(8)
                ->get(['disease_code', 'rank_order', 'confidence', 'reasoning']);
            $codes = $sd->pluck('disease_code')->all();
            $names = empty($codes) ? [] : DB::table('ref_diseases')
                ->whereIn('disease_code', $codes)
                ->pluck('display_name', 'disease_code')->all();
            $diseases = $sd->map(fn ($r) => (object) [
                'disease_code' => $r->disease_code,
                'display_name' => $names[$r->disease_code] ?? null,
                'rank_order'   => $r->rank_order,
                'confidence'   => $r->confidence,
                'reasoning'    => $r->reasoning,
            ]);
        }

        $created = Carbon::parse((string) $a->created_at);
        $end     = $a->closed_at ? Carbon::parse((string) $a->closed_at) : Carbon::now();
        $minutesOpen = (int) round($created->diffInMinutes($end));
        $hoursOpen   = intdiv($minutesOpen, 60);
        $sla = match ((string) $a->risk_level) { 'CRITICAL' => 4, 'HIGH' => 24, default => 48 };

        $travellerRow = $sec ? [
            'name'        => $sec->traveler_full_name,
            'gender'      => $sec->traveler_gender,
            'age'         => $sec->traveler_age_years,
            'nationality' => $sec->traveler_nationality_country_code,
            'origin'      => $sec->journey_start_country_code,
            'document'    => $sec->travel_document_number,
            'phone'       => $sec->phone_number ?? null,
            'arrival'     => $sec->arrival_datetime,
            'disposition' => $sec->final_disposition,
            'risk_level'  => $sec->risk_level,
            'triage'      => $sec->triage_category,
            'temperature' => $sec->temperature_value ?? null,
            'oxygen_sat'  => $sec->oxygen_saturation ?? null,
        ] : null;
        if ($travellerRow) {
            $travellerRow = $this->access->maskPii(array_merge($travellerRow, [
                'phone_number'           => $travellerRow['phone'] ?? null,
                'travel_document_number' => $travellerRow['document'] ?? null,
            ]), $scope);
            $travellerRow['phone']    = $travellerRow['phone_number'];
            $travellerRow['document'] = $travellerRow['travel_document_number'];
        }

        return $this->ok([
            'alert' => [
                'id'              => (int) $a->id,
                'code'            => $a->alert_code,
                'title'           => $a->alert_title,
                'details'         => $a->alert_details,
                'risk_level'      => $a->risk_level,
                'status'          => $a->status,
                'routed_to_level' => $a->routed_to_level,
                'poe_code'        => $a->poe_code,
                'created_at'      => $a->created_at,
                'acknowledged_at' => $a->acknowledged_at,
                'closed_at'       => $a->closed_at,
                'close_category'  => $a->close_category,
                'close_note'      => $a->close_note,
                'reopen_count'    => $a->reopen_count ?? 0,
                'minutes_open'    => $minutesOpen,
                'hours_open'      => $hoursOpen,
                'sla_hours'       => $sla,
                'sla_breached'    => $hoursOpen > $sla,
            ],
            'traveller' => $travellerRow,
            'outcome'   => $outcome ? [
                'classification'   => $outcome->case_classification,
                'reason'           => $outcome->case_classification_reason,
                'lab_status'       => $outcome->lab_status,
                'lab_disease_code' => $outcome->lab_disease_code,
                'lab_test_method'  => $outcome->lab_test_method,
                'clinical_outcome' => $outcome->clinical_outcome,
                'ph_action'        => $outcome->ph_action,
                'outbreak_status'  => $outcome->outbreak_status,
                'ihr_notified'     => (bool) ($outcome->ihr_notified ?? false),
                'ihr_reference'    => $outcome->ihr_reference,
                'recorded_at'      => $outcome->recorded_at,
            ] : null,
            'followups'    => $followups,
            'followup_agg' => $followupAgg,
            'timeline'     => $timeline,
            'diseases'     => $diseases,
        ]);
    }

    /* ──────────────────────────────────────────────────────────────────
     * 7.  filterRules — extends parent.
     * ────────────────────────────────────────────────────────────────── */
    protected function filterRules(): array
    {
        return parent::filterRules() + [
            'sort' => ['nullable', 'in:risk,age,sla'],
            'dir'  => ['nullable', 'in:asc,desc'],
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     * Internal — chart builders. Each is one targeted aggregated query.
     * ────────────────────────────────────────────────────────────────── */
    private function chartScreenings30d(array $scope, array $f): array
    {
        $from = Carbon::now()->subDays(29)->startOfDay();
        $to   = Carbon::now()->endOfDay();

        $pq = DB::table('primary_screenings')->whereNull('deleted_at')
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw('DATE(captured_at) AS d, COUNT(*) AS c')
            ->groupBy(DB::raw('DATE(captured_at)'));
        $this->scope->apply($pq, $scope);
        if (! empty($f['poe'])) { $pq->where('poe_code', $f['poe']); }
        $primary = $pq->pluck('c', 'd')->all();

        $sq = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to])
            ->selectRaw('DATE(opened_at) AS d, COUNT(*) AS c')
            ->groupBy(DB::raw('DATE(opened_at)'));
        $this->scope->apply($sq, $scope);
        if (! empty($f['poe'])) { $sq->where('poe_code', $f['poe']); }
        $secondary = $sq->pluck('c', 'd')->all();

        $labels = $pri = $sec = $csv = [];
        $cur = $from->copy();
        while ($cur <= $to) {
            $d = $cur->toDateString();
            $labels[] = $cur->format('d M');
            $pri[] = (int) ($primary[$d] ?? 0);
            $sec[] = (int) ($secondary[$d] ?? 0);
            $csv[] = [$cur->format('d M'), (int) ($primary[$d] ?? 0), (int) ($secondary[$d] ?? 0)];
            $cur->addDay();
        }
        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Primary',   'data' => $pri],
                ['label' => 'Secondary', 'data' => $sec],
            ],
            'csv_headers' => ['Date', 'Primary', 'Secondary'],
            'csv_rows'    => $csv,
        ];
    }

    private function chartAlertsByRisk(array $scope, array $f): array
    {
        $from = Carbon::now()->subDays(29)->startOfDay();
        $to   = Carbon::now()->endOfDay();
        $q = DB::table('alerts')->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("DATE(created_at) AS d,
                SUM(risk_level='LOW')      AS r_low,
                SUM(risk_level='MEDIUM')   AS r_medium,
                SUM(risk_level='HIGH')     AS r_high,
                SUM(risk_level='CRITICAL') AS r_critical
            ")
            ->groupBy(DB::raw('DATE(created_at)'));
        $this->scope->apply($q, $scope);
        if (! empty($f['poe'])) { $q->where('poe_code', $f['poe']); }
        $rows = $q->get()->keyBy('d');

        $labels = $low = $med = $high = $crit = $csv = [];
        $cur = $from->copy();
        while ($cur <= $to) {
            $d = $cur->toDateString();
            $r = $rows[$d] ?? null;
            $labels[] = $cur->format('d M');
            $low[]  = (int) ($r->r_low ?? 0);
            $med[]  = (int) ($r->r_medium ?? 0);
            $high[] = (int) ($r->r_high ?? 0);
            $crit[] = (int) ($r->r_critical ?? 0);
            $csv[]  = [$cur->format('d M'), (int) ($r->r_low ?? 0), (int) ($r->r_medium ?? 0), (int) ($r->r_high ?? 0), (int) ($r->r_critical ?? 0)];
            $cur->addDay();
        }
        return [
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Low',      'data' => $low],
                ['label' => 'Medium',   'data' => $med],
                ['label' => 'High',     'data' => $high],
                ['label' => 'Critical', 'data' => $crit],
            ],
            'csv_headers' => ['Date', 'Low', 'Medium', 'High', 'Critical'],
            'csv_rows'    => $csv,
        ];
    }

    private function chartTopPoes(array $scope, array $f): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($f);
        $q = DB::table('primary_screenings')->whereNull('deleted_at')
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw('poe_code, COUNT(*) AS c')
            ->groupBy('poe_code')
            ->orderByDesc('c')
            ->limit(self::TOP_LIMIT);
        $this->scope->apply($q, $scope);
        if (! empty($f['poe'])) { $q->where('poe_code', $f['poe']); }
        $rows = $q->get();

        $codes = $rows->pluck('poe_code')->filter()->unique()->values()->all();
        $names = empty($codes) ? [] : DB::table('ref_poes')
            ->whereIn('poe_code', $codes)
            ->whereNull('deleted_at')
            ->pluck('poe_name', 'poe_code')->all();

        $labels = $data = $csv = [];
        foreach ($rows as $r) {
            $code = (string) ($r->poe_code ?? '');
            if ($code === '') { continue; }
            $name = $names[$code] ?? $code;
            $labels[] = $name;
            $data[]   = (int) $r->c;
            $csv[]    = [$name, (int) $r->c];
        }
        return [
            'labels' => $labels,
            'datasets' => [['label' => 'Screenings', 'data' => $data]],
            'csv_headers' => ['POE', 'Screenings'],
            'csv_rows' => $csv,
        ];
    }

    private function chartTopOrigins(array $scope, array $f): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($f);
        $q = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to])
            ->whereNotNull('journey_start_country_code')
            ->selectRaw('journey_start_country_code AS cc, COUNT(*) AS c')
            ->groupBy('journey_start_country_code')
            ->orderByDesc('c')
            ->limit(self::TOP_LIMIT);
        $this->scope->apply($q, $scope);
        if (! empty($f['poe'])) { $q->where('poe_code', $f['poe']); }
        $rows = $q->get();

        $codes = $rows->pluck('cc')->filter()->unique()->values()->all();
        $names = empty($codes) ? [] : DB::table('ref_countries')
            ->whereIn('country_code', $codes)
            ->where('is_active', 1)
            ->pluck('name', 'country_code')->all();

        $labels = $data = $csv = [];
        foreach ($rows as $r) {
            $cc = (string) $r->cc;
            $name = $names[$cc] ?? $cc;
            $labels[] = $name;
            $data[]   = (int) $r->c;
            $csv[]    = [$name, (int) $r->c];
        }
        return [
            'labels' => $labels,
            'datasets' => [['label' => 'Travellers', 'data' => $data]],
            'csv_headers' => ['Country', 'Travellers'],
            'csv_rows' => $csv,
            'footnote' => 'Based on secondary-screened travellers (primary tier has no origin country).',
        ];
    }

    private function chartOutcomeMix(array $scope, array $f): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($f);
        $q = DB::table('alerts AS a')->whereNull('a.deleted_at')
            ->whereBetween('a.created_at', [$from, $to])
            ->leftJoin('alert_case_outcomes AS o', function ($j) {
                $j->on('o.alert_id', '=', 'a.id')->whereNull('o.deleted_at');
            });
        $this->scope->apply($q, $scope, 'a');
        if (! empty($f['poe'])) { $q->where('a.poe_code', $f['poe']); }

        $row = (clone $q)->selectRaw("
            SUM(o.case_classification='CONFIRMED') AS confirmed_,
            SUM(o.case_classification='PROBABLE')  AS probable_,
            SUM(o.case_classification='SUSPECTED') AS suspected_,
            SUM(o.case_classification='NON_CASE')  AS noncase_,
            SUM(o.id IS NULL)                      AS unclassified
        ")->first();

        $buckets = [
            'Confirmed'    => (int) ($row->confirmed_ ?? 0),
            'Probable'     => (int) ($row->probable_ ?? 0),
            'Suspected'    => (int) ($row->suspected_ ?? 0),
            'Non-case'     => (int) ($row->noncase_ ?? 0),
            'Unclassified' => (int) ($row->unclassified ?? 0),
        ];
        return [
            'labels' => array_keys($buckets),
            'datasets' => [['label' => 'Alerts', 'data' => array_values($buckets)]],
            'csv_headers' => ['Outcome', 'Alerts'],
            'csv_rows'    => array_map(null, array_keys($buckets), array_values($buckets)),
        ];
    }

    private function chartGenderMix(array $scope, array $f): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($f);
        $q = DB::table('primary_screenings')->whereNull('deleted_at')
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw("
                SUM(gender='MALE')                           AS male_,
                SUM(gender='FEMALE')                         AS female_,
                SUM(gender='OTHER')                          AS other_,
                SUM(gender='UNKNOWN' OR gender IS NULL)      AS unknown_
            ");
        $this->scope->apply($q, $scope);
        if (! empty($f['poe'])) { $q->where('poe_code', $f['poe']); }
        $row = $q->first();

        $buckets = [
            'Male'    => (int) ($row->male_ ?? 0),
            'Female'  => (int) ($row->female_ ?? 0),
            'Other'   => (int) ($row->other_ ?? 0),
            'Unknown' => (int) ($row->unknown_ ?? 0),
        ];
        return [
            'labels' => array_keys($buckets),
            'datasets' => [['label' => 'Travellers', 'data' => array_values($buckets)]],
            'csv_headers' => ['Gender', 'Travellers'],
            'csv_rows'    => array_map(null, array_keys($buckets), array_values($buckets)),
        ];
    }

    private function chartSlaStatus(array $scope, array $f): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($f);
        $q = DB::table('alerts')->whereNull('deleted_at')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw("
                SUM(
                    (risk_level='CRITICAL' AND TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, NOW())) > 4) OR
                    (risk_level='HIGH'     AND TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, NOW())) > 24) OR
                    (risk_level NOT IN ('CRITICAL','HIGH') AND TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, NOW())) > 48)
                ) AS breached,
                SUM(
                    (risk_level='CRITICAL' AND TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, NOW())) BETWEEN 3 AND 4) OR
                    (risk_level='HIGH'     AND TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, NOW())) BETWEEN 18 AND 24) OR
                    (risk_level NOT IN ('CRITICAL','HIGH') AND TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, NOW())) BETWEEN 36 AND 48)
                ) AS at_risk,
                SUM(
                    (risk_level='CRITICAL' AND TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, NOW())) < 3) OR
                    (risk_level='HIGH'     AND TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, NOW())) < 18) OR
                    (risk_level NOT IN ('CRITICAL','HIGH') AND TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, NOW())) < 36)
                ) AS within_
            ");
        $this->scope->apply($q, $scope);
        if (! empty($f['poe'])) { $q->where('poe_code', $f['poe']); }
        $row = $q->first();
        $buckets = [
            'Within'   => (int) ($row->within_ ?? 0),
            'At risk'  => (int) ($row->at_risk ?? 0),
            'Breached' => (int) ($row->breached ?? 0),
        ];
        return [
            'labels' => array_keys($buckets),
            'datasets' => [['label' => 'Alerts', 'data' => array_values($buckets)]],
            'csv_headers' => ['SLA Bucket', 'Alerts'],
            'csv_rows'    => array_map(null, array_keys($buckets), array_values($buckets)),
        ];
    }

    private function chartOfficerActivity(array $scope, array $f): array
    {
        $now    = Carbon::now();
        $thresh = $now->copy()->subDays(14);

        // For super: query users directly. For non-super: bound via user_assignments scope.
        $base = DB::table('users AS u')->where('u.is_active', 1);
        if (empty($scope['is_super'])) {
            $base->whereExists(function ($sub) use ($scope) {
                $sub->select(DB::raw(1))->from('user_assignments AS uax')
                    ->whereColumn('uax.user_id', 'u.id')
                    ->where('uax.is_active', 1);
                $this->scope->apply($sub, $scope, 'uax');
            });
        }

        $row = (clone $base)->selectRaw('
            SUM(u.last_activity_at >= ?) AS active_,
            SUM(u.last_activity_at IS NULL OR u.last_activity_at < ?) AS dormant_,
            SUM(u.locked_until > NOW()) AS locked_
        ', [$thresh, $thresh])->first();

        $buckets = [
            'Active'  => (int) ($row->active_ ?? 0),
            'Dormant' => (int) ($row->dormant_ ?? 0),
            'Locked'  => (int) ($row->locked_ ?? 0),
        ];
        return [
            'labels' => array_keys($buckets),
            'datasets' => [['label' => 'Officers', 'data' => array_values($buckets)]],
            'csv_headers' => ['Bucket', 'Officers'],
            'csv_rows'    => array_map(null, array_keys($buckets), array_values($buckets)),
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     * Internal helpers.
     * ────────────────────────────────────────────────────────────────── */
    private function resolveEndemicCodes(array $codes): array
    {
        if (empty($codes)) { return []; }
        $direct = DB::table('ref_endemic_countries')
            ->whereIn('country_code', $codes)
            ->where('is_active', 1)
            ->pluck('country_code')->unique()->values()->all();
        $matched = array_flip($direct);
        $unmatched = array_values(array_diff($codes, array_keys($matched)));
        if (empty($unmatched)) { return $direct; }

        $refRows = DB::table('ref_countries')
            ->whereIn('country_code', $unmatched)
            ->where('is_active', 1)
            ->get(['country_code', 'iso_alpha2', 'iso_alpha3']);
        $isoCodes = [];
        foreach ($refRows as $r) {
            if ($r->iso_alpha3) { $isoCodes[strtoupper((string) $r->iso_alpha3)] = (string) $r->country_code; }
            if ($r->iso_alpha2) { $isoCodes[strtoupper((string) $r->iso_alpha2)] = (string) $r->country_code; }
        }
        if (empty($isoCodes)) { return $direct; }
        $isoEndemic = DB::table('ref_endemic_countries')
            ->whereIn('country_code', array_keys($isoCodes))
            ->where('is_active', 1)
            ->pluck('country_code')->unique()->values()->all();
        foreach ($isoEndemic as $iso) {
            $iso = strtoupper((string) $iso);
            if (isset($isoCodes[$iso])) { $matched[$isoCodes[$iso]] = true; }
        }
        return array_keys($matched);
    }

    private function slaTone(?float $pct): string
    {
        if ($pct === null) { return 'neutral'; }
        if ($pct > 20) { return 'danger'; }
        if ($pct > 10) { return 'warning'; }
        return 'success';
    }
}
