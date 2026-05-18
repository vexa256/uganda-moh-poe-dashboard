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

/**
 * R3 · rpt-alert-intel — Alert Intelligence.
 *
 * Answers: how many alerts, what status, what risk level, what outcome —
 * are we generating signal or noise?
 */
final class AlertIntelligenceController extends BaseReportController
{
    protected string $reportKey   = 'rpt-alert-intel';
    protected string $reportTitle = 'Alert Intelligence';

    public function index(Request $request): View
    {
        $this->ensureAccess($request);
        return view('admin.reports.v2.rpt-alert-intel', [
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
        ]);
    }

    public function meta(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        return $this->ok([
            'poes'  => $this->scope->allowedPoes($scope),
            'scope' => ['label' => $scope['label'] ?? '—', 'level' => $scope['scope_level'] ?? 'SELF'],
        ]);
    }

    public function kpis(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $q = DB::table('alerts AS a')->whereNull('a.deleted_at')
            ->whereBetween('a.created_at', [$from, $to]);
        $this->scopeAlerts($q, $scope, $f);

        $agg = (clone $q)->selectRaw("
            COUNT(*) AS total,
            SUM(a.status='OPEN')         AS open_,
            SUM(a.status='ACKNOWLEDGED') AS acknowledged,
            SUM(a.status='CLOSED')       AS closed,
            SUM(a.status='CLOSED' AND a.close_category='RESOLVED')        AS resolved,
            SUM(a.status='CLOSED' AND a.close_category='FALSE_POSITIVE')  AS false_positive,
            SUM(a.risk_level='CRITICAL') AS r_critical,
            SUM(a.risk_level='HIGH')     AS r_high,
            SUM(a.reopen_count > 0)      AS reopened
        ")->first();

        // Outcome classifications via JOIN. Wrap the soft-delete guard in a
        // closure so the OR doesn't break the outer WHERE precedence.
        $outcomeAgg = (clone $q)->leftJoin('alert_case_outcomes AS o', 'o.alert_id', '=', 'a.id')
            ->where(fn ($w) => $w->whereNull('o.deleted_at')->orWhereNull('o.id'))
            ->selectRaw("
                SUM(o.case_classification='CONFIRMED') AS confirmed_,
                SUM(o.case_classification='PROBABLE')  AS probable_,
                SUM(o.case_classification='SUSPECTED') AS suspected_,
                SUM(o.case_classification='NON_CASE')  AS noncase_,
                SUM(o.id IS NULL)                      AS no_outcome
            ")->first();

        $total       = (int) ($agg->total ?? 0);
        $open        = (int) (($agg->open_ ?? 0) + ($agg->acknowledged ?? 0));
        $closed      = (int) ($agg->closed ?? 0);
        $fp          = (int) ($agg->false_positive ?? 0);
        $realCases   = (int) (($outcomeAgg->confirmed_ ?? 0) + ($outcomeAgg->probable_ ?? 0) + ($outcomeAgg->suspected_ ?? 0));
        $signalRate  = $total > 0 ? round((($total - $fp) / max(1, $total)) * 100, 1) : null;

        return $this->ok([
            'window' => [
                'from'  => $from->toDateString(),
                'to'    => $to->toDateString(),
                'label' => $from->format('d M Y') . ' – ' . $to->format('d M Y'),
            ],
            'kpis' => [
                ['key' => 'total',        'label' => 'Total Alerts',     'value' => number_format($total),       'tone' => 'brand',   'hint' => 'All alerts created in the window.'],
                ['key' => 'open',         'label' => 'Still Open',       'value' => number_format($open),        'tone' => $open > 0 ? 'warning' : 'success', 'hint' => 'OPEN + ACKNOWLEDGED, not yet closed.'],
                ['key' => 'real_cases',   'label' => 'Real Cases',       'value' => number_format($realCases),   'tone' => 'critical', 'hint' => 'Confirmed + Probable + Suspected.'],
                ['key' => 'false_pos',    'label' => 'False Positives',  'value' => number_format($fp),          'tone' => 'info',    'hint' => 'Closed with reason FALSE_POSITIVE.'],
                ['key' => 'signal_rate',  'label' => 'Signal Rate',      'value' => $signalRate === null ? '—' : ($signalRate . '%'), 'tone' => $signalRate !== null && $signalRate >= 80 ? 'success' : 'warning', 'hint' => 'Alerts that were not false-positives.'],
            ],
            'extra' => [
                'reopened' => (int) ($agg->reopened ?? 0),
                'critical' => (int) ($agg->r_critical ?? 0),
                'high'     => (int) ($agg->r_high ?? 0),
                'no_outcome' => (int) ($outcomeAgg->no_outcome ?? 0),
            ],
        ]);
    }

    public function chart(Request $request, string $chart): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        return match ($chart) {
            'volume_by_risk' => $this->ok($this->chartVolumeByRisk($scope, $f, $from, $to)),
            'outcome_mix'    => $this->ok($this->chartOutcomeMix($scope, $f, $from, $to)),
            default          => $this->fail(404, 'Unknown chart key.'),
        };
    }

    public function chartCsv(Request $request, string $chart): StreamedResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $payload = match ($chart) {
            'volume_by_risk' => $this->chartVolumeByRisk($scope, $f, $from, $to),
            'outcome_mix'    => $this->chartOutcomeMix($scope, $f, $from, $to),
            default          => abort(404),
        };

        return $this->streamCsv("rpt-alert-intel__{$chart}", $payload['csv_headers'], $payload['csv_rows']);
    }

    public function records(Request $request): JsonResponse
    {
        $scope    = $this->ensureAccess($request);
        $f        = $this->readFilters($request);
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = 10;
        $q        = trim((string) $request->input('q', ''));
        $sort     = (string) $request->input('sort', 'created_at');
        $dir      = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $cat      = (string) $request->input('cat', 'all');
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $qb = DB::table('alerts AS a')->whereNull('a.deleted_at')
            ->whereBetween('a.created_at', [$from, $to])
            ->leftJoin('alert_case_outcomes AS o', function ($j) {
                $j->on('o.alert_id', '=', 'a.id')->whereNull('o.deleted_at');
            })
            ->select('a.id', 'a.alert_code', 'a.alert_title', 'a.poe_code', 'a.risk_level', 'a.status', 'a.close_category', 'a.created_at', 'a.acknowledged_at', 'a.closed_at', 'a.reopen_count', 'o.case_classification');
        $this->scopeAlerts($qb, $scope, $f, 'a');

        if ($cat === 'open')        $qb->whereIn('a.status', ['OPEN', 'ACKNOWLEDGED']);
        elseif ($cat === 'closed')  $qb->where('a.status', 'CLOSED');
        elseif ($cat === 'critical')$qb->where('a.risk_level', 'CRITICAL');
        elseif ($cat === 'fp')      $qb->where('a.close_category', 'FALSE_POSITIVE');
        elseif ($cat === 'real')    $qb->whereIn('o.case_classification', ['CONFIRMED', 'PROBABLE', 'SUSPECTED']);

        if ($q !== '') {
            $qb->where(function ($w) use ($q) {
                $w->where('a.alert_code', 'like', '%' . $q . '%')
                  ->orWhere('a.alert_title', 'like', '%' . $q . '%')
                  ->orWhere('a.poe_code', 'like', '%' . $q . '%');
            });
        }

        $sortMap = ['created_at' => 'a.created_at', 'risk_level' => 'a.risk_level', 'status' => 'a.status', 'poe_code' => 'a.poe_code', 'alert_code' => 'a.alert_code'];
        $sortCol = $sortMap[$sort] ?? 'a.created_at';
        $qb->orderBy($sortCol, $dir);

        // Total via separate COUNT — never count an in-memory collection.
        $totalQb = DB::table('alerts AS a')->whereNull('a.deleted_at')
            ->whereBetween('a.created_at', [$from, $to])
            ->leftJoin('alert_case_outcomes AS o', function ($j) {
                $j->on('o.alert_id', '=', 'a.id')->whereNull('o.deleted_at');
            });
        $this->scopeAlerts($totalQb, $scope, $f, 'a');
        if ($cat === 'open')        $totalQb->whereIn('a.status', ['OPEN', 'ACKNOWLEDGED']);
        elseif ($cat === 'closed')  $totalQb->where('a.status', 'CLOSED');
        elseif ($cat === 'critical')$totalQb->where('a.risk_level', 'CRITICAL');
        elseif ($cat === 'fp')      $totalQb->where('a.close_category', 'FALSE_POSITIVE');
        elseif ($cat === 'real')    $totalQb->whereIn('o.case_classification', ['CONFIRMED', 'PROBABLE', 'SUSPECTED']);
        if ($q !== '') {
            $totalQb->where(function ($w) use ($q) {
                $w->where('a.alert_code', 'like', '%' . $q . '%')
                  ->orWhere('a.alert_title', 'like', '%' . $q . '%')
                  ->orWhere('a.poe_code', 'like', '%' . $q . '%');
            });
        }
        $total      = (int) $totalQb->count('a.id');
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);

        $rows = $qb->forPage($page, $perPage)->get()->map(fn ($r) => [
            'id'                 => (int) $r->id,
            'alert_code'         => $r->alert_code,
            'alert_title'        => $r->alert_title ?: ($r->alert_code ?: ('Alert #' . $r->id)),
            'poe_code'           => $r->poe_code,
            'risk_level'         => $r->risk_level,
            'status'             => $r->status,
            'close_category'     => $r->close_category,
            'classification'     => $r->case_classification,
            'created_at'         => $r->created_at,
            'acknowledged_at'    => $r->acknowledged_at,
            'closed_at'          => $r->closed_at,
            'reopen_count'       => (int) ($r->reopen_count ?? 0),
        ]);

        // Category counts (separate aggregated query — bounded).
        $catQ = DB::table('alerts AS a')->whereNull('a.deleted_at')->whereBetween('a.created_at', [$from, $to])
            ->leftJoin('alert_case_outcomes AS o', function ($j) {
                $j->on('o.alert_id', '=', 'a.id')->whereNull('o.deleted_at');
            });
        $this->scopeAlerts($catQ, $scope, $f, 'a');
        $catRow = (clone $catQ)->selectRaw("
            COUNT(DISTINCT a.id) AS all_,
            SUM(a.status IN ('OPEN','ACKNOWLEDGED')) AS open_,
            SUM(a.status='CLOSED') AS closed,
            SUM(a.risk_level='CRITICAL') AS critical_,
            SUM(a.close_category='FALSE_POSITIVE') AS fp,
            SUM(o.case_classification IN ('CONFIRMED','PROBABLE','SUSPECTED')) AS real_
        ")->first();

        return $this->ok([
            'rows' => $rows,
            'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to'   => min($page * $perPage, $total),
            ],
            'controls' => ['sort' => $sort, 'dir' => $dir, 'q' => $q, 'cat' => $cat],
            'category_counts' => [
                'all'      => (int) ($catRow->all_ ?? 0),
                'open'     => (int) ($catRow->open_ ?? 0),
                'closed'   => (int) ($catRow->closed ?? 0),
                'critical' => (int) ($catRow->critical_ ?? 0),
                'fp'       => (int) ($catRow->fp ?? 0),
                'real'     => (int) ($catRow->real_ ?? 0),
            ],
        ]);
    }

    public function recordDetail(Request $request, int $id): JsonResponse
    {
        $scope = $this->ensureAccess($request);

        $a = DB::table('alerts')->where('id', $id)->first();
        abort_if(! $a, 404, 'Alert not found.');

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

        // Collation between secondary_suspected_diseases.disease_code and
        // ref_diseases.disease_code differs (utf8mb4_unicode_ci vs
        // utf8mb4_0900_ai_ci) — joining on them throws SQLSTATE[HY000] 1267.
        // Two-query approach is small-N anyway (≤ 8 rows by spec) and dodges
        // the issue entirely.
        $diseases = collect();
        if ($sec) {
            $sd = DB::table('secondary_suspected_diseases')
                ->where('secondary_screening_id', $sec->id)
                ->orderBy('rank_order')
                ->limit(8)
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

        $minutesOpen = $a->closed_at
            ? (int) round(Carbon::parse((string) $a->created_at)->diffInMinutes(Carbon::parse((string) $a->closed_at)))
            : (int) round(Carbon::parse((string) $a->created_at)->diffInMinutes(Carbon::now()));
        $hoursOpen = intdiv($minutesOpen, 60);
        $sla = match ((string) $a->risk_level) { 'CRITICAL' => 4, 'HIGH' => 24, default => 48 };

        return $this->ok([
            'alert' => [
                'id'              => $a->id,
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
                'reopen_count'    => $a->reopen_count,
                'pheic_declared_at' => $a->pheic_declared_at,
                'minutes_open'    => $minutesOpen,
                'hours_open'      => $hoursOpen,
                'sla_hours'       => $sla,
                'sla_breached'    => $hoursOpen > $sla,
            ],
            'traveller' => $sec ? [
                'name'        => $sec->traveler_full_name,
                'gender'      => $sec->traveler_gender,
                'age'         => $sec->traveler_age_years,
                'nationality' => $sec->traveler_nationality_country_code,
                'origin'      => $sec->journey_start_country_code,
                'document'    => $sec->travel_document_number,
                'arrival'     => $sec->arrival_datetime,
                'disposition' => $sec->final_disposition,
                'risk_level'  => $sec->risk_level,
                'triage'      => $sec->triage_category,
                'temperature' => $sec->temperature_value,
                'oxygen_sat'  => $sec->oxygen_saturation,
            ] : null,
            'outcome' => $outcome ? [
                'classification'   => $outcome->case_classification,
                'reason'           => $outcome->case_classification_reason,
                'lab_status'       => $outcome->lab_status,
                'lab_disease_code' => $outcome->lab_disease_code,
                'lab_test_method'  => $outcome->lab_test_method,
                'clinical_outcome' => $outcome->clinical_outcome,
                'ph_action'        => $outcome->ph_action,
                'outbreak_status'  => $outcome->outbreak_status,
                'ihr_notified'     => (bool) $outcome->ihr_notified,
                'ihr_reference'    => $outcome->ihr_reference,
                'recorded_at'      => $outcome->recorded_at,
            ] : null,
            'followups'    => $followups,
            'followup_agg' => $followupAgg,
            'timeline'     => $timeline,
            'diseases'     => $diseases,
        ]);
    }

    /* ───── chart builders ───── */

    private function chartVolumeByRisk(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $q = DB::table('alerts AS a')->whereNull('a.deleted_at')
            ->whereBetween('a.created_at', [$from, $to])
            ->selectRaw("DATE(a.created_at) AS d,
                SUM(a.risk_level='LOW')      AS r_low,
                SUM(a.risk_level='MEDIUM')   AS r_medium,
                SUM(a.risk_level='HIGH')     AS r_high,
                SUM(a.risk_level='CRITICAL') AS r_critical
            ")
            ->groupBy(DB::raw('DATE(a.created_at)'));
        $this->scopeAlerts($q, $scope, $f, 'a');
        $rows = $q->get()->keyBy('d');

        $labels = $low = $med = $high = $crit = $csv = [];
        $cur = $from->copy();
        while ($cur <= $to) {
            $d = $cur->toDateString();
            $r = $rows[$d] ?? null;
            $labels[] = $cur->format('d M');
            $low[]    = (int) ($r->r_low ?? 0);
            $med[]    = (int) ($r->r_medium ?? 0);
            $high[]   = (int) ($r->r_high ?? 0);
            $crit[]   = (int) ($r->r_critical ?? 0);
            $csv[]    = [$cur->format('d M'), (int) ($r->r_low ?? 0), (int) ($r->r_medium ?? 0), (int) ($r->r_high ?? 0), (int) ($r->r_critical ?? 0)];
            $cur->addDay();
        }

        return [
            'labels'   => $labels,
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

    private function chartOutcomeMix(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $q = DB::table('alerts AS a')->whereNull('a.deleted_at')
            ->whereBetween('a.created_at', [$from, $to])
            ->leftJoin('alert_case_outcomes AS o', function ($j) {
                $j->on('o.alert_id', '=', 'a.id')->whereNull('o.deleted_at');
            });
        $this->scopeAlerts($q, $scope, $f, 'a');

        $row = (clone $q)->selectRaw("
            SUM(o.case_classification='CONFIRMED') AS confirmed_,
            SUM(o.case_classification='PROBABLE')  AS probable_,
            SUM(o.case_classification='SUSPECTED') AS suspected_,
            SUM(o.case_classification='NON_CASE')  AS noncase_,
            SUM(o.id IS NULL AND a.close_category='FALSE_POSITIVE') AS fp,
            SUM(o.id IS NULL AND a.close_category<>'FALSE_POSITIVE' OR a.close_category IS NULL) AS unclassified
        ")->first();

        $buckets = [
            'Confirmed'    => (int) ($row->confirmed_ ?? 0),
            'Probable'     => (int) ($row->probable_ ?? 0),
            'Suspected'    => (int) ($row->suspected_ ?? 0),
            'Non-case'     => (int) (($row->noncase_ ?? 0) + ($row->fp ?? 0)),
            'Unclassified' => (int) ($row->unclassified ?? 0),
        ];

        return [
            'labels'   => array_keys($buckets),
            'datasets' => [['label' => 'Alerts', 'data' => array_values($buckets)]],
            'csv_headers' => ['Outcome', 'Alerts'],
            'csv_rows'    => array_map(null, array_keys($buckets), array_values($buckets)),
        ];
    }

    /* ───── helpers ───── */

    private function scopeAlerts($q, array $scope, array $f, string $alias = ''): void
    {
        $this->scope->apply($q, $scope, $alias ?: null);
        $col = $alias ? "{$alias}.poe_code" : 'poe_code';
        if (! empty($f['poe'])) $q->where($col, $f['poe']);
    }

    private function streamCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $r) fputcsv($out, $r);
            fclose($out);
        }, $filename . '__' . now()->format('Ymd-Hi') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
