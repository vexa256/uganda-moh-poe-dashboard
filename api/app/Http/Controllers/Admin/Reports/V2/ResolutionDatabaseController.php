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
 * R5 · rpt-resolution-db — Alert Resolution Database.
 *
 * For every handled alert: who did what, when, and how it ended.
 * Audience split:
 *   Executive (main view) — resolution volume, signal vs noise, top resolvers.
 *   Technical (drill modal) — full alert lifecycle: handoffs, follow-ups, lab,
 *   outcome, samples, audit timeline.
 */
final class ResolutionDatabaseController extends BaseReportController
{
    protected string $reportKey   = 'rpt-resolution-db';
    protected string $reportTitle = 'Alert Resolution Database';

    public function index(Request $request): View
    {
        $this->ensureAccess($request);
        return view('admin.reports.v2.rpt-resolution-db', [
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

        $closed = DB::table('alerts')->whereNull('deleted_at')
            ->where('status', 'CLOSED')
            ->whereBetween('created_at', [$from, $to]);
        $this->scope->apply($closed, $scope);
        if (! empty($f['poe'])) $closed->where('poe_code', $f['poe']);

        $agg = (clone $closed)->selectRaw("
            COUNT(*) AS total,
            SUM(close_category='RESOLVED')        AS resolved_,
            SUM(close_category='FALSE_POSITIVE')  AS fp,
            SUM(close_category IS NULL)           AS no_reason
        ")->first();

        $closedIds = (clone $closed)->pluck('id')->all();

        // Avg follow-ups per alert via single aggregate.
        $fuAgg = empty($closedIds) ? null : DB::table('alert_followups')
            ->whereIn('alert_id', $closedIds)
            ->selectRaw('COUNT(*) AS total, COUNT(DISTINCT alert_id) AS alerts_with_fu')
            ->first();

        // % with sample collected (via secondary_screening_id → secondary_samples).
        $secIds = empty($closedIds) ? [] : DB::table('alerts')->whereIn('id', $closedIds)
            ->whereNotNull('secondary_screening_id')->pluck('secondary_screening_id')->unique()->values()->all();
        $sampleAgg = empty($secIds) ? null : DB::table('secondary_samples')
            ->whereIn('secondary_screening_id', $secIds)
            ->selectRaw('COUNT(DISTINCT secondary_screening_id) AS uniq, SUM(sample_collected=1) AS collected')
            ->first();

        // Real cases: alert_case_outcomes.case_classification IN (CONFIRMED, PROBABLE, SUSPECTED).
        $realCases = empty($closedIds) ? 0 : (int) DB::table('alert_case_outcomes')
            ->whereIn('alert_id', $closedIds)
            ->whereNull('deleted_at')
            ->whereIn('case_classification', ['CONFIRMED', 'PROBABLE', 'SUSPECTED'])
            ->count();

        $total       = (int) ($agg->total ?? 0);
        $fp          = (int) ($agg->fp ?? 0);
        $avgFu       = $total > 0 && $fuAgg ? round((int) $fuAgg->total / max(1, $total), 1) : 0;
        $sampledPct  = ($total > 0 && $sampleAgg && (int) $sampleAgg->uniq > 0)
            ? round(((int) $sampleAgg->collected / max(1, count($secIds))) * 100, 1)
            : null;

        return $this->ok([
            'window' => [
                'from'  => $from->toDateString(),
                'to'    => $to->toDateString(),
                'label' => $from->format('d M Y') . ' – ' . $to->format('d M Y'),
            ],
            'kpis' => [
                ['key' => 'resolved',   'label' => 'Alerts Resolved',  'value' => number_format($total),   'tone' => 'brand',   'hint' => 'Closed in window.'],
                ['key' => 'real_cases', 'label' => 'Real Cases',       'value' => number_format($realCases), 'tone' => 'critical', 'hint' => 'Confirmed + Probable + Suspected.'],
                ['key' => 'fp',         'label' => 'False Positives',  'value' => number_format($fp),       'tone' => 'info',    'hint' => 'Closed as FALSE_POSITIVE.'],
                ['key' => 'avg_fu',     'label' => 'Avg Follow-ups',   'value' => $avgFu,                   'tone' => 'success', 'hint' => 'Tasks per resolved alert.'],
                ['key' => 'sampled',    'label' => 'Sample Collected', 'value' => $sampledPct === null ? '—' : ($sampledPct . '%'), 'tone' => $sampledPct !== null && $sampledPct < 50 ? 'warning' : 'success', 'hint' => 'Of resolved alerts with linked screenings.'],
            ],
        ]);
    }

    public function chart(Request $request, string $chart): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        return match ($chart) {
            'resolutions_by_reason' => $this->ok($this->chartByReason($scope, $f, $from, $to)),
            'top_resolvers'         => $this->ok($this->chartTopResolvers($scope, $f, $from, $to)),
            default                 => $this->fail(404, 'Unknown chart key.'),
        };
    }

    public function chartCsv(Request $request, string $chart): StreamedResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $payload = match ($chart) {
            'resolutions_by_reason' => $this->chartByReason($scope, $f, $from, $to),
            'top_resolvers'         => $this->chartTopResolvers($scope, $f, $from, $to),
            default                 => abort(404),
        };
        return $this->streamCsv("rpt-resolution-db__{$chart}", $payload['csv_headers'], $payload['csv_rows']);
    }

    public function records(Request $request): JsonResponse
    {
        $scope    = $this->ensureAccess($request);
        $f        = $this->readFilters($request);
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = 10;
        $q        = trim((string) $request->input('q', ''));
        $sort     = (string) $request->input('sort', 'closed_at');
        $dir      = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $cat      = (string) $request->input('cat', 'all');
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $base = function () use ($scope, $f, $from, $to) {
            $q = DB::table('alerts AS a')->whereNull('a.deleted_at')
                ->where('a.status', 'CLOSED')
                ->whereBetween('a.created_at', [$from, $to])
                ->leftJoin('users AS u', 'u.id', '=', 'a.acknowledged_by_user_id')
                ->leftJoin('alert_case_outcomes AS o', function ($j) {
                    $j->on('o.alert_id', '=', 'a.id')->whereNull('o.deleted_at');
                });
            $this->scope->apply($q, $scope, 'a');
            if (! empty($f['poe'])) $q->where('a.poe_code', $f['poe']);
            return $q;
        };

        $qb = $base();
        if ($cat === 'real') $qb->whereIn('o.case_classification', ['CONFIRMED', 'PROBABLE', 'SUSPECTED']);
        elseif ($cat === 'fp') $qb->where('a.close_category', 'FALSE_POSITIVE');
        elseif ($cat === 'resolved') $qb->where('a.close_category', 'RESOLVED');

        if ($q !== '') {
            $qb->where(function ($w) use ($q) {
                $w->where('a.alert_code', 'like', '%' . $q . '%')
                  ->orWhere('a.alert_title', 'like', '%' . $q . '%')
                  ->orWhere('a.poe_code', 'like', '%' . $q . '%')
                  ->orWhere('u.full_name', 'like', '%' . $q . '%');
            });
        }

        $sortMap = [
            'closed_at'   => 'a.closed_at',
            'created_at'  => 'a.created_at',
            'risk_level'  => 'a.risk_level',
            'poe_code'    => 'a.poe_code',
            'close_category' => 'a.close_category',
        ];
        $sortCol = $sortMap[$sort] ?? 'a.closed_at';
        $qb->orderBy($sortCol, $dir);

        // Total count.
        $totalQb = $base();
        if ($cat === 'real') $totalQb->whereIn('o.case_classification', ['CONFIRMED', 'PROBABLE', 'SUSPECTED']);
        elseif ($cat === 'fp') $totalQb->where('a.close_category', 'FALSE_POSITIVE');
        elseif ($cat === 'resolved') $totalQb->where('a.close_category', 'RESOLVED');
        if ($q !== '') {
            $totalQb->where(function ($w) use ($q) {
                $w->where('a.alert_code', 'like', '%' . $q . '%')
                  ->orWhere('a.alert_title', 'like', '%' . $q . '%')
                  ->orWhere('a.poe_code', 'like', '%' . $q . '%')
                  ->orWhere('u.full_name', 'like', '%' . $q . '%');
            });
        }
        $total      = (int) $totalQb->count(DB::raw('DISTINCT a.id'));
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);

        $rows = $qb->forPage($page, $perPage)->get([
            'a.id', 'a.alert_code', 'a.alert_title', 'a.poe_code', 'a.risk_level',
            'a.close_category', 'a.created_at', 'a.closed_at', 'a.reopen_count',
            'u.full_name AS owner_name',
            'o.case_classification',
        ])->map(function ($r) {
            $fu = DB::table('alert_followups')->where('alert_id', $r->id)
                ->selectRaw("SUM(status='COMPLETED') AS done, COUNT(*) AS total")->first();
            return [
                'id'              => (int) $r->id,
                'alert_code'      => $r->alert_code,
                'alert_title'     => $r->alert_title ?: ($r->alert_code ?: ('Alert #' . $r->id)),
                'poe_code'        => $r->poe_code,
                'risk_level'      => $r->risk_level,
                'close_category'  => $r->close_category,
                'classification'  => $r->case_classification,
                'owner_name'      => $r->owner_name,
                'created_at'      => $r->created_at,
                'closed_at'       => $r->closed_at,
                'reopen_count'    => (int) ($r->reopen_count ?? 0),
                'fu_completed'    => (int) ($fu->done ?? 0),
                'fu_total'        => (int) ($fu->total ?? 0),
            ];
        });

        // Category counts.
        $catRow = (clone $base())->selectRaw("
            COUNT(DISTINCT a.id) AS all_,
            SUM(o.case_classification IN ('CONFIRMED','PROBABLE','SUSPECTED')) AS real_,
            SUM(a.close_category='FALSE_POSITIVE') AS fp,
            SUM(a.close_category='RESOLVED') AS resolved_
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
                'real'     => (int) ($catRow->real_ ?? 0),
                'fp'       => (int) ($catRow->fp ?? 0),
                'resolved' => (int) ($catRow->resolved_ ?? 0),
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

        $owner = $a->acknowledged_by_user_id
            ? DB::table('users')->where('id', $a->acknowledged_by_user_id)->first(['id', 'full_name', 'username', 'role_key'])
            : null;

        $outcome = DB::table('alert_case_outcomes')->where('alert_id', $id)->whereNull('deleted_at')->first();

        $followups = DB::table('alert_followups AS f')
            ->leftJoin('users AS u', 'u.id', '=', 'f.completed_by_user_id')
            ->where('f.alert_id', $id)
            ->orderBy('f.due_at')
            ->limit(30)
            ->get(['f.action_label', 'f.status', 'f.due_at', 'f.completed_at', 'f.notes', 'f.blocks_closure', 'u.full_name AS completed_by_name']);

        $followupAgg = DB::table('alert_followups')->where('alert_id', $id)->selectRaw("
            COUNT(*) AS total,
            SUM(status='COMPLETED') AS completed,
            SUM(status='PENDING')   AS pending,
            SUM(status='BLOCKED')   AS blocked,
            SUM(blocks_closure=1 AND status<>'COMPLETED') AS blocking
        ")->first();

        $handoffs = DB::table('alert_handoffs AS h')
            ->leftJoin('users AS uf', 'uf.id', '=', 'h.from_user_id')
            ->leftJoin('users AS ut', 'ut.id', '=', 'h.to_user_id')
            ->where('h.alert_id', $id)
            ->orderBy('h.created_at')
            ->get(['h.from_level', 'h.to_level', 'h.status', 'h.reason', 'h.created_at', 'h.decided_at', 'uf.full_name AS from_name', 'ut.full_name AS to_name']);

        $samples = $sec ? DB::table('secondary_samples')
            ->where('secondary_screening_id', $sec->id)
            ->get(['sample_collected', 'sample_type', 'sample_identifier', 'lab_destination', 'collected_at']) : collect();

        $timeline = DB::table('alert_timeline_events')
            ->where('alert_id', $id)
            ->orderByDesc('created_at')
            ->limit(30)
            ->get(['event_code', 'event_category', 'actor_name', 'summary', 'severity', 'created_at']);

        // Diseases (collation-safe two-query).
        $diseases = collect();
        if ($sec) {
            $sd = DB::table('secondary_suspected_diseases')
                ->where('secondary_screening_id', $sec->id)
                ->orderBy('rank_order')->limit(8)
                ->get(['disease_code', 'rank_order', 'confidence', 'reasoning']);
            $codes = $sd->pluck('disease_code')->all();
            $names = empty($codes) ? [] : DB::table('ref_diseases')->whereIn('disease_code', $codes)->pluck('display_name', 'disease_code')->all();
            $diseases = $sd->map(fn ($r) => (object) [
                'disease_code' => $r->disease_code,
                'display_name' => $names[$r->disease_code] ?? null,
                'rank_order'   => $r->rank_order,
                'confidence'   => $r->confidence,
                'reasoning'    => $r->reasoning,
            ]);
        }

        $hoursOpen = $a->closed_at
            ? Carbon::parse((string) $a->created_at)->diffInHours(Carbon::parse((string) $a->closed_at))
            : Carbon::parse((string) $a->created_at)->diffInHours(Carbon::now());
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
                'hours_open'      => $hoursOpen,
                'sla_hours'       => $sla,
                'sla_breached'    => $hoursOpen > $sla,
            ],
            'owner' => $owner,
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
            'handoffs'     => $handoffs,
            'samples'      => $samples,
            'timeline'     => $timeline,
            'diseases'     => $diseases,
        ]);
    }

    /* ───── chart builders ───── */

    private function chartByReason(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $q = DB::table('alerts')->whereNull('deleted_at')
            ->where('status', 'CLOSED')
            ->whereBetween('closed_at', [$from, $to])
            ->selectRaw("DATE(closed_at) AS d,
                SUM(close_category='RESOLVED')       AS resolved_,
                SUM(close_category='FALSE_POSITIVE') AS fp,
                SUM(close_category IS NULL)          AS unspecified
            ")
            ->groupBy(DB::raw('DATE(closed_at)'));
        $this->scope->apply($q, $scope);
        if (! empty($f['poe'])) $q->where('poe_code', $f['poe']);
        $rows = $q->get()->keyBy('d');

        $labels = $resolved = $fp = $unspec = $csv = [];
        $cur = $from->copy();
        while ($cur <= $to) {
            $d = $cur->toDateString();
            $r = $rows[$d] ?? null;
            $labels[] = $cur->format('d M');
            $resolved[] = (int) ($r->resolved_ ?? 0);
            $fp[] = (int) ($r->fp ?? 0);
            $unspec[] = (int) ($r->unspecified ?? 0);
            $csv[] = [$cur->format('d M'), (int) ($r->resolved_ ?? 0), (int) ($r->fp ?? 0), (int) ($r->unspecified ?? 0)];
            $cur->addDay();
        }

        return [
            'labels'   => $labels,
            'datasets' => [
                ['label' => 'Resolved',       'data' => $resolved],
                ['label' => 'False Positive', 'data' => $fp],
                ['label' => 'Unspecified',    'data' => $unspec],
            ],
            'csv_headers' => ['Date', 'Resolved', 'False Positive', 'Unspecified'],
            'csv_rows'    => $csv,
        ];
    }

    private function chartTopResolvers(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        // Count distinct alerts where the user acknowledged and the alert is closed.
        $q = DB::table('alerts AS a')
            ->leftJoin('users AS u', 'u.id', '=', 'a.acknowledged_by_user_id')
            ->whereNull('a.deleted_at')
            ->where('a.status', 'CLOSED')
            ->whereBetween('a.created_at', [$from, $to])
            ->whereNotNull('a.acknowledged_by_user_id')
            ->selectRaw('u.id, u.full_name, u.username, COUNT(*) AS resolved')
            ->groupBy('u.id', 'u.full_name', 'u.username')
            ->orderByDesc('resolved')
            ->limit(10);
        $this->scope->apply($q, $scope, 'a');
        if (! empty($f['poe'])) $q->where('a.poe_code', $f['poe']);
        $rows = $q->get();

        $labels = $data = $csv = [];
        foreach ($rows as $r) {
            $labels[] = $r->full_name ?: $r->username ?: ('User #' . $r->id);
            $data[]   = (int) $r->resolved;
            $csv[]    = [$labels[count($labels) - 1], (int) $r->resolved];
        }

        return [
            'labels'   => $labels,
            'datasets' => [['label' => 'Alerts Resolved', 'data' => $data]],
            'csv_headers' => ['Officer', 'Alerts Resolved'],
            'csv_rows'    => $csv,
        ];
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
