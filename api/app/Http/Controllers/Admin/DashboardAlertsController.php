<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\AlertsController as MobileAlertsController;
use App\Http\Controllers\Controller;
use App\Services\PheocCopilot;
use App\Services\PheocScope;
use App\Support\DiseaseIntel;
use App\Support\EnumTranslator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * DashboardAlertsController
 * ─────────────────────────────────────────────────────────────────────────
 * Renders the Alert Hub (kanban) and the per-alert War Room for the admin
 * panel. First-paint data is server-rendered; an Alpine loop fetches the
 * snapshot endpoint every 30 s for live updates.
 *
 *   GET /admin/alerts                  — hub (kanban + filters + SSR feed)
 *   GET /admin/alerts/{id}             — war room
 *   GET /admin/alerts/snapshot.json    — snapshot feed (same filters)
 *
 * Mobile-consumption check (L4): this controller is admin-only. It reads
 * from the `alerts` table and related child tables with DB:: directly. It
 * never calls MobileAlertsController::index so the mobile response shape
 * is not at risk.
 */
final class DashboardAlertsController extends Controller
{
    public const KANBAN_COLUMNS = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW'];
    public const COLUMN_LIMIT   = 40;

    public function __construct(
        protected PheocScope $scope,
        protected EnumTranslator $enum,
        protected PheocCopilot $copilot,
    ) {
    }

    /* ══════════════════════════════════════════════════════════════════
       HUB · kanban + filter bar
       ══════════════════════════════════════════════════════════════════ */

    public function hub(Request $request): View
    {
        $viewScope = $this->resolveScope($request);
        $filters   = $this->readFilters($request);

        $data = $this->composeHub($viewScope, $filters);

        return view('admin.alerts.hub', [
            'actorScope'      => $viewScope,
            'filters'         => $filters,
            'columns'         => $data['columns'],
            'counts'          => $data['counts'],
            'totals'          => $data['totals'],
            'sla'             => $data['sla'],
            'closeCategories' => MobileAlertsController::CLOSE_CATEGORIES,
            'diseaseOptions'  => $this->diseaseOptions(),
            'poeOptions'      => $this->poeOptions((string) ($viewScope['country_code'] ?? config('country.code'))),
            'brief'           => $this->copilot->triageBrief(['country_code' => $viewScope['country_code'] ?? config('country.code')]),
            'recommendations' => $this->filterShippedRecs($this->copilot->recommend(['scope' => $viewScope])),
        ]);
    }

    /** Filter recommendations to URLs targeting shipped admin routes (Phase 0). */
    protected function filterShippedRecs(array $recs): array
    {
        $shipped = [
            url('/admin/dashboard'),
            url('/admin/alerts'),
            url('/admin/compliance/717'),
            url('/admin/comms/inbox'),
            url('/admin/users'),
        ];
        return array_values(array_filter($recs, function ($r) use ($shipped) {
            $u = (string) ($r['url'] ?? '');
            if ($u === '') return false;
            foreach ($shipped as $prefix) {
                if (str_starts_with($u, $prefix)) return true;
            }
            return false;
        }));
    }

    public function hubSnapshot(Request $request): JsonResponse
    {
        $viewScope = $this->resolveScope($request);
        $filters   = $this->readFilters($request);
        $data      = $this->composeHub($viewScope, $filters);

        return response()->json([
            'ok'   => true,
            'data' => [
                'columns' => $data['columns'],
                'counts'  => $data['counts'],
                'totals'  => $data['totals'],
                'sla'     => $data['sla'],
                'as_of'   => now()->toIso8601String(),
            ],
        ]);
    }

    /* ══════════════════════════════════════════════════════════════════
       WAR ROOM · deep-view (tabbed)
       ══════════════════════════════════════════════════════════════════ */

    public function warRoom(Request $request, int $id): View
    {
        $viewScope = $this->resolveScope($request);

        $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
        $alertView = $alert ? $this->shape($alert) : null;

        // Bundle context for first paint: follow-up summary, collaborator count,
        // comment count, evidence count, breach count. These populate the tab badges.
        $tabCounts = $alert ? [
            'timeline'       => (int) DB::table('alert_timeline_events')->where('alert_id', $id)->count(),
            'comments'       => (int) DB::table('alert_comments')->where('alert_id', $id)->whereNull('deleted_at')->count(),
            'evidence'       => (int) DB::table('alert_evidence')->where('alert_id', $id)->whereNull('deleted_at')->count(),
            'collaborators'  => (int) DB::table('alert_collaborators')->where('alert_id', $id)->count(),
            'handoffs'       => (int) DB::table('alert_handoffs')->where('alert_id', $id)->count(),
            'followups'      => (int) DB::table('alert_followups')->where('alert_id', $id)->whereNull('deleted_at')->count(),
            'breaches'       => (int) DB::table('alert_breach_reports')->where('alert_id', $id)->count(),
            'external'       => (int) DB::table('responder_info_requests')->where('alert_id', $id)->count(),
        ] : array_fill_keys(['timeline','comments','evidence','collaborators','handoffs','followups','breaches','external'], 0);

        // Recent timeline (first 5) for the Overview tab
        $recentTimeline = $alert ? $this->recentTimeline($id) : [];

        // Linked secondary screening for the Case Sheet tab
        $caseSheet = ($alert && $alert->secondary_screening_id)
            ? $this->buildCaseSheet((int) $alert->secondary_screening_id)
            : null;

        // Copilot: narrate + suggest-close + escalation rationale
        $narrate      = $alert ? $this->copilot->narrate($alert) : ['prose' => '', 'sentences' => [], 'reasoning' => []];
        $suggestClose = $alert ? $this->copilot->suggestCloseReason($id) : null;
        $escalation   = $alert ? $this->copilot->escalationRationale($id) : null;

        // Open follow-ups that would block closure
        $blockers = [];
        if ($alert) {
            try {
                $blockers = DB::table('alert_followups')
                    ->where('alert_id', $id)
                    ->where('blocks_closure', 1)
                    ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                    ->whereNull('deleted_at')
                    ->get()->map(fn ($r) => (array) $r)->all();
            } catch (\Throwable) { $blockers = []; }
        }

        return view('admin.alerts.war-room', [
            'alertId'         => $id,
            'alert'           => $alertView,
            'alertRaw'        => $alert,
            'actorScope'      => $viewScope,
            'closeCategories' => MobileAlertsController::CLOSE_CATEGORIES,
            'tabCounts'       => $tabCounts,
            'recentTimeline'  => $recentTimeline,
            'caseSheet'       => $caseSheet,
            'narrate'         => $narrate,
            'suggestClose'    => $suggestClose,
            'escalation'      => $escalation,
            'blockers'        => $blockers,
        ]);
    }

    protected function recentTimeline(int $alertId): array
    {
        try {
            $rows = DB::table('alert_timeline_events')
                ->where('alert_id', $alertId)
                ->orderByDesc('created_at')
                ->limit(8)
                ->get()->map(fn ($r) => (array) $r)->all();

            $tb = app(\App\Support\TimelineBuilder::class);
            return $tb->build($rows);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function buildCaseSheet(int $secondaryId): array
    {
        try {
            $screening = DB::table('secondary_screenings')->where('id', $secondaryId)->whereNull('deleted_at')->first();
            if (! $screening) return ['screening' => null];

            // Child tables don't carry deleted_at — sync upstream is the mobile app.
            $symptoms   = DB::table('secondary_symptoms')->where('secondary_screening_id', $secondaryId)->get()->map(fn ($r) => (array) $r)->all();
            $exposures  = DB::table('secondary_exposures')->where('secondary_screening_id', $secondaryId)->get()->map(fn ($r) => (array) $r)->all();
            $travel     = DB::table('secondary_travel_countries')->where('secondary_screening_id', $secondaryId)->get()->map(fn ($r) => (array) $r)->all();
            $actions    = DB::table('secondary_actions')->where('secondary_screening_id', $secondaryId)->get()->map(fn ($r) => (array) $r)->all();
            $samples    = DB::table('secondary_samples')->where('secondary_screening_id', $secondaryId)->get()->map(fn ($r) => (array) $r)->all();
            $suspected  = DB::table('secondary_suspected_diseases')
                ->where('secondary_screening_id', $secondaryId)
                ->orderByDesc('confidence')
                ->orderBy('rank_order')
                ->get()->map(fn ($r) => (array) $r)->all();

            $diseases = app(\App\Support\DiseaseResolver::class)->rankSuspected($suspected);

            return [
                'screening' => (array) $screening,
                'symptoms'  => $symptoms,
                'exposures' => $exposures,
                'travel'    => $travel,
                'actions'   => $actions,
                'samples'   => $samples,
                'diseases'  => $diseases,
                'counts'    => [
                    'symptoms'  => count($symptoms),
                    'exposures' => count($exposures),
                    'travel'    => count($travel),
                    'actions'   => count($actions),
                    'samples'   => count($samples),
                    'diseases'  => count($diseases),
                ],
            ];
        } catch (\Throwable) {
            return ['screening' => null];
        }
    }

    /* ══════════════════════════════════════════════════════════════════
       COMPOSITION
       ══════════════════════════════════════════════════════════════════ */

    protected function composeHub(array $scope, array $filters): array
    {
        $country = (string) ($scope['country_code'] ?? config('country.code'));

        $columns = [];
        $counts  = [];
        foreach (self::KANBAN_COLUMNS as $risk) {
            $rows = $this->fetchAlerts($country, array_merge($filters, ['risk_level' => $risk]));
            $columns[$risk] = $rows['items'];
            $counts[$risk]  = $rows['total'];
        }

        $totals = [
            'all'          => array_sum($counts),
            'open'         => $this->countAlerts($country, $filters + ['status_in' => ['OPEN', 'ACKNOWLEDGED']]),
            'closed_7d'    => $this->countAlerts($country, $filters + ['status' => 'CLOSED', 'since' => now()->subDays(7)]),
            'sla_ok'       => 0,
            'sla_breached' => 0,
        ];

        $sla = $this->computeSlaBuckets($country, $filters);
        $totals['sla_ok']       = $sla['ok']       ?? 0;
        $totals['sla_breached'] = $sla['breached'] ?? 0;

        return compact('columns', 'counts', 'totals', 'sla');
    }

    protected function fetchAlerts(string $country, array $filters): array
    {
        $q = DB::table('alerts as a')
            ->where('a.country_code', $country)
            ->whereNull('a.deleted_at');

        if (! empty($filters['status_in'])) {
            $q->whereIn('a.status', (array) $filters['status_in']);
        } elseif (! empty($filters['status'])) {
            $q->where('a.status', strtoupper((string) $filters['status']));
        } elseif (empty($filters['include_closed'])) {
            $q->whereIn('a.status', ['OPEN', 'ACKNOWLEDGED']);
        }

        if (! empty($filters['risk_level'])) {
            $q->where('a.risk_level', strtoupper((string) $filters['risk_level']));
        }
        if (! empty($filters['routed_to_level'])) {
            $q->where('a.routed_to_level', strtoupper((string) $filters['routed_to_level']));
        }
        if (! empty($filters['poe_code'])) {
            $q->where('a.poe_code', (string) $filters['poe_code']);
        }
        if (! empty($filters['district_code'])) {
            $q->where('a.district_code', (string) $filters['district_code']);
        }
        if (! empty($filters['since'])) {
            $q->where('a.created_at', '>=', $filters['since'] instanceof Carbon ? $filters['since']->format('Y-m-d H:i:s') : (string) $filters['since']);
        }
        if (! empty($filters['q'])) {
            $needle = '%' . addcslashes((string) $filters['q'], '%_\\') . '%';
            $q->where(function ($w) use ($needle) {
                $w->where('a.alert_code', 'like', $needle)
                  ->orWhere('a.alert_title', 'like', $needle)
                  ->orWhere('a.alert_details', 'like', $needle);
            });
        }

        $total = (clone $q)->count();

        $items = $q->orderByDesc('a.created_at')
            ->limit(self::COLUMN_LIMIT)
            ->get()
            ->map(fn ($r) => $this->shape($r))
            ->all();

        return ['items' => $items, 'total' => $total];
    }

    protected function countAlerts(string $country, array $filters): int
    {
        $q = DB::table('alerts as a')
            ->where('a.country_code', $country)
            ->whereNull('a.deleted_at');

        if (! empty($filters['status_in'])) {
            $q->whereIn('a.status', (array) $filters['status_in']);
        } elseif (! empty($filters['status'])) {
            $q->where('a.status', strtoupper((string) $filters['status']));
        }
        if (! empty($filters['since'])) {
            $q->where('a.created_at', '>=', $filters['since'] instanceof Carbon ? $filters['since']->format('Y-m-d H:i:s') : (string) $filters['since']);
        }
        return (int) $q->count();
    }

    protected function computeSlaBuckets(string $country, array $filters): array
    {
        try {
            $since = now()->subDays(30);
            $base  = DB::table('alerts')
                ->where('country_code', $country)
                ->whereNull('deleted_at')
                ->where('created_at', '>=', $since);

            $openTotal    = (clone $base)->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])->count();
            $openBreached = (clone $base)->where('status', 'OPEN')
                ->whereRaw('TIMESTAMPDIFF(HOUR, created_at, NOW()) > 24')
                ->count();

            return [
                'open_total'   => $openTotal,
                'breached'     => $openBreached,
                'ok'           => max(0, $openTotal - $openBreached),
                'on_track_pct' => $openTotal > 0 ? round((($openTotal - $openBreached) / $openTotal) * 100, 1) : 100.0,
            ];
        } catch (\Throwable) {
            return ['open_total' => 0, 'breached' => 0, 'ok' => 0, 'on_track_pct' => 0.0];
        }
    }

    protected function shape(\stdClass $r): array
    {
        $diseaseCode = null;
        if (! empty($r->secondary_screening_id)) {
            try {
                $top = DB::table('secondary_suspected_diseases')
                    ->where('secondary_screening_id', $r->secondary_screening_id)
                    ->orderByDesc('confidence')
                    ->orderBy('rank_order')
                    ->first();
                if ($top) $diseaseCode = (string) $top->disease_code;
            } catch (\Throwable) { /* */ }
        }

        $created = $r->created_at ? Carbon::parse((string) $r->created_at) : null;
        $status  = (string) ($r->status ?? 'OPEN');

        $slaLabel = 'Within SLA';
        $slaTone  = 'success';
        if ($status === 'OPEN' && $created) {
            $hours = $created->diffInHours(now());
            if ($hours >= 24) { $slaLabel = 'Notify SLA breached'; $slaTone = 'critical'; }
            elseif ($hours >= 18) { $slaLabel = sprintf('Ack due in %d h', max(0, 24 - (int) $hours)); $slaTone = 'warning'; }
            else { $slaLabel = 'Within SLA'; $slaTone = 'info'; }
        } elseif ($status === 'ACKNOWLEDGED') {
            $slaLabel = 'Acknowledged'; $slaTone = 'success';
        } elseif ($status === 'CLOSED') {
            $slaLabel = $this->enum->closeCategory((string) ($r->close_category ?? ''));
            $slaTone  = 'default';
        }

        return [
            'id'             => (int) $r->id,
            'alert_code'     => (string) ($r->alert_code ?? '#' . $r->id),
            'alert_title'    => (string) ($r->alert_title ?? ''),
            'alert_details'  => (string) ($r->alert_details ?? ''),
            'risk_level'     => (string) ($r->risk_level ?? 'MEDIUM'),
            'status'         => $status,
            'status_label'   => $this->enum->alertStatus($status),
            'status_tone'    => $this->enum->alertStatusTone($status),
            'routed_to_level'=> (string) ($r->routed_to_level ?? 'DISTRICT'),
            'routed_label'   => $this->enum->routedToLevel((string) ($r->routed_to_level ?? 'DISTRICT')),
            'ihr_tier'       => (string) ($r->ihr_tier ?? ''),
            'ihr_label'      => $r->ihr_tier ? $this->enum->ihrTier((string) $r->ihr_tier) : null,
            'disease_code'   => $diseaseCode ?? '',
            'disease_name'   => $diseaseCode ? DiseaseIntel::nameFor($diseaseCode) : null,
            'poe_code'       => (string) ($r->poe_code ?? ''),
            'district_code'  => (string) ($r->district_code ?? ''),
            'created_at'     => $r->created_at,
            'created_rel'    => $created ? $created->diffForHumans() : '—',
            'acknowledged_at'=> $r->acknowledged_at,
            'closed_at'      => $r->closed_at,
            'close_category' => (string) ($r->close_category ?? ''),
            'sla_label'      => $slaLabel,
            'sla_tone'       => $slaTone,
            'generated_from' => $this->enum->generatedFrom((string) ($r->generated_from ?? 'RULE_BASED')),
            'url'            => url('/admin/alerts/' . (int) $r->id),
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
       HELPERS
       ══════════════════════════════════════════════════════════════════ */

    protected function resolveScope(Request $request): array
    {
        return $request->user() ? $this->scope->forUser($request->user()) : [
            'is_super'     => true,
            'scope_level'  => 'NATIONAL',
            'role_key'     => 'NATIONAL_ADMIN',
            'label'        => 'Preview · ' . config('country.name', 'Uganda'),
            'country_code' => config('country.code'),
            'poes'         => [],
            'districts'    => [],
            'provinces'    => [],
        ];
    }

    protected function readFilters(Request $request): array
    {
        return array_filter([
            'status'          => $request->query('status'),
            'routed_to_level' => $request->query('level'),
            'poe_code'        => $request->query('poe'),
            'district_code'   => $request->query('district'),
            'q'               => $request->query('q'),
            'include_closed'  => $request->boolean('include_closed'),
            'since'           => $request->query('since'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    protected function diseaseOptions(): array
    {
        $out = [];
        foreach (DiseaseIntel::REGISTRY as $code => $meta) {
            $out[] = ['code' => strtoupper($code), 'name' => $meta['name'] ?? $code];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        return array_slice($out, 0, 60);
    }

    protected function poeOptions(string $country): array
    {
        try {
            return DB::table('primary_screenings')
                ->selectRaw('poe_code, COUNT(*) as n')
                ->where('country_code', $country)
                ->where('captured_at', '>=', now()->subDays(30))
                ->whereNull('deleted_at')
                ->groupBy('poe_code')
                ->orderByDesc('n')
                ->limit(40)
                ->get()
                ->map(fn ($r) => ['code' => (string) $r->poe_code, 'count' => (int) $r->n])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
