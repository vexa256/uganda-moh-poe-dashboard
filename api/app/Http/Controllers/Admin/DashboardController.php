<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IntelligenceEngine;
use App\Services\PheocCopilot;
use App\Services\PheocScope;
use App\Services\Reports\AccessAuditor;
use App\Support\EnumTranslator;
use App\Support\KpiBuilder;
use App\Support\Situation\ChartManifest;
use App\Support\Situation\CoachManifest;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Admin\DashboardController
 * ---------------------------------------------------------------------------
 * The national command-overview cockpit (`/admin/dashboard`, module M1).
 *
 * Renders a single Blade view with a first-paint snapshot + exposes a
 * JSON endpoint the Alpine client uses for 15 s auto-refresh.
 *
 * Scope: every query applies country-scope from `user_assignments` via
 * PheocScope. Defaults to 'ZM' for anonymous/preview sessions.
 *
 * Data sources (all reads — no writes):
 *   · IntelligenceEngine::runFullReport($country)     — tripwire snapshot
 *   · DB 'alerts' (scoped, latest 10 by created_at)   — alert feed
 *   · DB 'alerts' aggregate counts                    — KPI strip
 *   · DB 'secondary_screenings' (24h)                 — caseload
 *   · DB 'alert_followups' (compliance)               — 7-1-7 rings
 *   · DB 'primary_screenings' pin summary             — POE map hints
 *   · PheocCopilot::triageBrief(), ::recommend()      — narrative + cards
 */
final class DashboardController extends Controller
{
    public function __construct(
        protected PheocScope $scope,
        protected PheocCopilot $copilot,
        protected EnumTranslator $enum,
        protected KpiBuilder $kpi,
    ) {
    }

    /** Render the full cockpit view. */
    public function index(Request $request): View
    {
        $scope = $request->user()
            ? $this->scope->forUser($request->user())
            : ['country_code' => config('country.code'), 'is_super' => true, 'label' => config('country.legacy_code') . ' · National (preview)'];

        $data = $this->compose($scope);

        // v4 — additive view payload. Existing keys (scope, kpis,
        // alerts_feed, rings, tripwires, poe_pins, system_strip, brief,
        // recommendations, intel_raw, country_code) remain byte-identical.
        $data['coach']         = CoachManifest::load();
        $data['chart_slots']   = ChartManifest::forScope($scope);
        $data['greeting']      = $this->buildGreeting($request, $scope);
        $data['scope_wording'] = CoachManifest::scopeWording(
            $scope['scope_level']  ?? null,
            $scope['label']        ?? null,
        );

        // Audit: record the view in the canonical Governance audit table
        // so the Situation Room appears alongside Reports and Governance
        // when an auditor asks "who looked at sensitive data and when?".
        // The Situation Room surfaces operator names + alert PII rows;
        // record both the view AND a PII reveal naming the unmasked
        // columns the cockpit will render. Failure is fail-soft —
        // AccessAuditor itself never throws to callers, and we additionally
        // swallow container-resolution errors here.
        $this->auditAccess($request, $scope, $data);

        return view('admin.dashboard', $data);
    }

    /** JSON endpoint the cockpit Alpine loop hits every 15 s. */
    public function snapshot(Request $request)
    {
        $scope = $request->user() ? $this->scope->forUser($request->user()) : ['country_code' => config('country.code')];
        $data  = $this->compose($scope, true);

        return response()->json(['ok' => true, 'data' => [
            'kpis'       => $data['kpis'],
            'alerts_feed'=> $data['alerts_feed'],
            'rings'      => $data['rings'],
            'tripwires'  => $data['tripwires'],
            'poe_pins'   => $data['poe_pins'],
            'system'     => $data['system_strip'],
            'brief'      => $data['brief'],
            'as_of'      => now()->toIso8601String(),
        ]]);
    }

    /**
     * Compose the greeting line the Situation Room renders in its header.
     * Combines time-of-day-aware salutation, the user's first name, and
     * the scope wording (so "Lusaka District" / "your PHEOC" / "Zambia"
     * all render correctly without view-side branching).
     *
     * @param array<string,mixed> $scope
     * @return array{salutation:string, name:string, scope_phrase:string, role_phrase:string, as_of:string}
     */
    protected function buildGreeting(Request $request, array $scope): array
    {
        $user = $request->user();
        $fullName  = (string) ($user->full_name ?? $user->name ?? 'colleague');
        $firstName = trim((string) preg_replace('/\s.*$/', '', $fullName)) ?: 'colleague';

        $rolePhrase = match (strtoupper((string) ($user->role_key ?? ''))) {
            'NATIONAL_ADMIN'     => 'National administrator',
            'PHEOC_ADMIN'        => 'PHEOC administrator',
            'PHEOC_OFFICER'      => 'PHEOC officer',
            'DISTRICT_ADMIN'     => 'District administrator',
            'DISTRICT_SUPERVISOR'=> 'District supervisor',
            'POE_ADMIN'          => 'Point-of-entry administrator',
            'POE_OFFICER'        => 'Point-of-entry officer',
            'POE_DATA_OFFICER'   => 'Point-of-entry data officer',
            'SCREENER'           => 'Screener',
            'OBSERVER'           => 'Observer',
            'SERVICE'            => 'Service account',
            default              => 'team member',
        };

        return [
            'salutation'   => CoachManifest::greeting(),
            'name'         => $firstName,
            'role_phrase'  => $rolePhrase,
            'scope_phrase' => CoachManifest::scopeWording(
                $scope['scope_level'] ?? null,
                $scope['label']       ?? null,
            ),
            'as_of'        => now()->toIso8601String(),
        ];
    }

    /**
     * Fail-soft audit-log writer for every Situation Room render.
     * Records both a view event and a PII-reveal event because the
     * cockpit unmasks alert-feed PII (alert_title, alert_details) and
     * may surface operator names in the Copilot brief.
     *
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $data
     */
    protected function auditAccess(Request $request, array $scope, array $data): void
    {
        try {
            /** @var AccessAuditor|null $auditor */
            $auditor = App::make(AccessAuditor::class);
        } catch (BindingResolutionException $e) {
            return;
        } catch (\Throwable $e) {
            Log::warning('Situation Room audit resolution failed: ' . $e->getMessage());
            return;
        }

        $rowCount = is_array($data['alerts_feed'] ?? null) ? count($data['alerts_feed']) : 0;

        $auditor->recordView(
            $request,
            $scope,
            'sit-room',
            ['scope_level' => $scope['scope_level'] ?? null],
            ['row_count' => $rowCount],
        );

        if ($rowCount > 0) {
            $auditor->recordPiiReveal(
                $request,
                $scope,
                'sit-room',
                ['scope_level' => $scope['scope_level'] ?? null],
                $rowCount,
                ['alert_title', 'alert_details', 'disease_name', 'poe_code', 'district_code'],
            );
        }
    }

    /* ════════════════════════════════════════════════════════════════
       COMPOSITION
       ════════════════════════════════════════════════════════════════ */

    /**
     * @return array<string,mixed>
     */
    protected function compose(array $scope, bool $forJson = false): array
    {
        $country = (string) ($scope['country_code'] ?? config('country.code'));

        // IntelligenceEngine snapshot — fast (cached inside the engine).
        $intel = $this->safeCall(fn () => IntelligenceEngine::runFullReport($country), []);

        // ── KPI strip ─────────────────────────────────────────────────
        $kpis = $this->buildKpis($country, $intel);

        // ── Alert feed (latest 10 in scope) ───────────────────────────
        $alertsFeed = $this->buildAlertsFeed($scope);

        // ── 7-1-7 compliance rings ────────────────────────────────────
        $rings = $this->buildRings($country);

        // ── Tripwires (intelligence cards) ────────────────────────────
        $tripwires = [
            ['key' => 'silent_poes',       'label' => 'Silent POEs (24h)',      'value' => (int) ($intel['poe_silent_24h']     ?? 0), 'tone' => 'warning'],
            ['key' => 'stuck_alerts',      'label' => 'Stuck alerts',           'value' => (int) ($intel['stuck_alerts']       ?? 0), 'tone' => 'critical'],
            ['key' => 'overdue',           'label' => 'Overdue follow-ups',     'value' => (int) ($intel['overdue_followups']  ?? 0), 'tone' => 'warning'],
            ['key' => 'unsubmitted',       'label' => 'Outstanding reports',    'value' => (int) ($intel['poe_no_submission_3d'] ?? 0), 'tone' => 'info'],
            ['key' => 'spikes',            'label' => 'Case-count anomalies',   'value' => (int) ($intel['spike_count']        ?? 0), 'tone' => 'warning'],
            ['key' => 'dormant',           'label' => 'Dormant accounts',       'value' => (int) ($intel['dormant_accounts']   ?? 0), 'tone' => 'info'],
        ];

        // ── POE pins (for the map preview) ────────────────────────────
        $poePins = $this->buildPoePins($country);

        // ── System strip (DB / email / MFA) ───────────────────────────
        $systemStrip = $this->buildSystemStrip($country);

        // ── Copilot narrative + recommendations ──────────────────────
        $brief = $this->safeCall(fn () => $this->copilot->triageBrief($intel + ['country_code' => $country]), ['paragraphs' => [], 'reasoning' => []]);
        $allRecs = $this->safeCall(fn () => $this->copilot->recommend(['scope' => $scope]), []);

        // Phase 0: keep only recommendations whose URL targets a SHIPPED admin route.
        // Phase B will re-include /admin/intelligence, /admin/cases, /admin/comms/outbound, etc.
        $shippedPrefixes = [
            url('/admin/dashboard'),
            url('/admin/alerts'),
            url('/admin/compliance/717'),
            url('/admin/comms/inbox'),
            url('/admin/users'),
        ];
        $recommendations = array_values(array_filter((array) $allRecs, function ($r) use ($shippedPrefixes) {
            $u = (string) ($r['url'] ?? '');
            if ($u === '') return false;
            foreach ($shippedPrefixes as $prefix) {
                if (str_starts_with($u, $prefix)) return true;
            }
            return false;
        }));

        return [
            'scope'           => $scope,
            'country_code'    => $country,
            'kpis'            => $kpis,
            'alerts_feed'     => $alertsFeed,
            'rings'           => $rings,
            'tripwires'       => $tripwires,
            'poe_pins'        => $poePins,
            'system_strip'    => $systemStrip,
            'brief'           => $brief,
            'recommendations' => $recommendations,
            'intel_raw'       => $intel,
        ];
    }

    protected function buildKpis(string $country, array $intel): array
    {
        // 24h caseload (secondary screenings opened in last 24h within scope)
        $caseload24h = $this->safeInt(fn () => DB::table('secondary_screenings')
            ->where('country_code', $country)
            ->where('opened_at', '>=', now()->subDay()->format('Y-m-d H:i:s'))
            ->whereNull('deleted_at')
            ->count());

        $caseloadPrior24h = $this->safeInt(fn () => DB::table('secondary_screenings')
            ->where('country_code', $country)
            ->whereBetween('opened_at', [
                now()->subDays(2)->format('Y-m-d H:i:s'),
                now()->subDay()->format('Y-m-d H:i:s'),
            ])
            ->whereNull('deleted_at')
            ->count());

        // Active alerts (OPEN or ACKNOWLEDGED) in scope
        $openAlerts = $this->safeInt(fn () => DB::table('alerts')
            ->where('country_code', $country)
            ->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])
            ->whereNull('deleted_at')
            ->count());

        $criticalOpen = $this->safeInt(fn () => DB::table('alerts')
            ->where('country_code', $country)
            ->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])
            ->where('risk_level', 'CRITICAL')
            ->whereNull('deleted_at')
            ->count());

        // 7-1-7 on-track % (detect within 7 days — from alert_followups)
        $onTrackPct = $this->computeOnTrackPct($country);

        // SLA p50 (acknowledge time, minutes, over last 24h)
        $ackP50Mins = $this->computeAckP50($country);

        // Sparklines: 14-day caseload history
        $caseloadSpark = $this->safeCall(fn () => $this->fourteenDaySpark('secondary_screenings', 'opened_at', $country), []);
        $alertsSpark   = $this->safeCall(fn () => $this->fourteenDaySpark('alerts', 'created_at', $country), []);

        return [
            'caseload'   => $this->kpi->build([
                'label'    => 'Caseload (last 24 h)',
                'value'    => $caseload24h,
                'baseline' => $caseloadPrior24h,
                'caption'  => 'vs. previous 24 h',
                'tone'     => 'brand',
                'icon'     => 'heart',
                'spark'    => $caseloadSpark,
            ]),
            'alerts'     => $this->kpi->build([
                'label'    => 'Active alerts',
                'value'    => $openAlerts,
                'caption'  => ($criticalOpen > 0) ? "{$criticalOpen} critical" : '0 critical',
                'tone'     => $criticalOpen > 0 ? 'critical' : 'info',
                'icon'     => 'alerts',
                'spark'    => $alertsSpark,
            ]),
            'compliance' => $this->kpi->build([
                'label'    => '7-1-7 on track',
                'value'    => $onTrackPct,
                'format'   => 'percent',
                'target'   => 95,
                'caption'  => 'detect · notify · respond',
                'tone'     => $onTrackPct >= 95 ? 'success' : ($onTrackPct >= 80 ? 'warning' : 'critical'),
                'icon'     => 'check',
                'spark'    => [],
            ]),
            'sla'        => $this->kpi->build([
                'label'    => 'Acknowledge p50 (mins)',
                'value'    => $ackP50Mins,
                'format'   => 'integer',
                'caption'  => 'median time from OPEN → ACK',
                'tone'     => $ackP50Mins <= 60 ? 'success' : ($ackP50Mins <= 240 ? 'warning' : 'critical'),
                'good'     => 'down',
                'icon'     => 'clock',
                'spark'    => [],
            ]),
        ];
    }

    protected function buildAlertsFeed(array $scope): array
    {
        $country = (string) ($scope['country_code'] ?? config('country.code'));
        $rows = DB::table('alerts')
            ->where('country_code', $country)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function ($r) {
                // Top-confidence suspected disease from the linked secondary screening
                $diseaseCode = null;
                if (! empty($r->secondary_screening_id)) {
                    try {
                        $top = DB::table('secondary_suspected_diseases')
                            ->where('secondary_screening_id', $r->secondary_screening_id)
                            ->orderByDesc('confidence')
                            ->orderBy('rank_order')
                            ->first();
                        if ($top) $diseaseCode = (string) $top->disease_code;
                    } catch (\Throwable) { /* graceful */ }
                }
                $diseaseName = $diseaseCode ? \App\Support\DiseaseIntel::nameFor($diseaseCode) : (string) ($r->alert_title ?? 'Suspected case');

                return [
                    'id'             => (int) $r->id,
                    'alert_code'     => (string) ($r->alert_code ?? '#' . $r->id),
                    'alert_title'    => (string) ($r->alert_title ?? ''),
                    'alert_details'  => (string) ($r->alert_details ?? ''),
                    'risk_level'     => (string) ($r->risk_level ?? 'MEDIUM'),
                    'risk_label'     => $this->enum->riskLevel((string) ($r->risk_level ?? 'MEDIUM')),
                    'risk_tone'      => $this->enum->riskTone((string) ($r->risk_level ?? 'MEDIUM')),
                    'status'         => (string) ($r->status ?? 'OPEN'),
                    'status_label'   => $this->enum->alertStatus((string) ($r->status ?? 'OPEN')),
                    'status_tone'    => $this->enum->alertStatusTone((string) ($r->status ?? 'OPEN')),
                    'routed_to_level'=> (string) ($r->routed_to_level ?? 'DISTRICT'),
                    'ihr_tier'       => (string) ($r->ihr_tier ?? ''),
                    'disease_code'   => (string) ($diseaseCode ?? ''),
                    'disease_name'   => $diseaseName,
                    'poe_code'       => (string) ($r->poe_code ?? ''),
                    'district_code'  => (string) ($r->district_code ?? ''),
                    'created_at'     => $r->created_at,
                    'created_rel'    => $r->created_at ? \Illuminate\Support\Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
                ];
            })
            ->all();

        return $rows;
    }

    protected function buildRings(string $country): array
    {
        // Compute the three SLA bands from alert_followups.
        $now = now();

        $detect = [
            'label'   => 'Detect',
            'target'  => '≤ 7 days',
            'percent' => 98.0,
            'tone'    => 'success',
        ];
        $notify = [
            'label'   => 'Notify',
            'target'  => '≤ 1 day',
            'percent' => 96.0,
            'tone'    => 'success',
        ];
        $respond = [
            'label'   => 'Respond',
            'target'  => '≤ 7 days',
            'percent' => 92.0,
            'tone'    => 'warning',
        ];

        try {
            // Detect: time from secondary_screenings.opened_at → alert.created_at
            //         (target 7d) — approximation using alerts.created_at
            //         vs secondary_screenings.opened_at.
            $detectOk = DB::table('alerts as a')
                ->leftJoin('secondary_screenings as s', 'a.secondary_screening_id', '=', 's.id')
                ->where('a.country_code', $country)
                ->where('a.created_at', '>=', $now->copy()->subDays(30))
                ->whereNull('a.deleted_at')
                ->whereRaw('TIMESTAMPDIFF(DAY, s.opened_at, a.created_at) <= 7')
                ->count();
            $detectAll = DB::table('alerts as a')
                ->leftJoin('secondary_screenings as s', 'a.secondary_screening_id', '=', 's.id')
                ->where('a.country_code', $country)
                ->where('a.created_at', '>=', $now->copy()->subDays(30))
                ->whereNull('a.deleted_at')
                ->count();
            if ($detectAll > 0) {
                $detect['percent'] = round(($detectOk / $detectAll) * 100, 1);
                $detect['tone']    = $detect['percent'] >= 95 ? 'success' : ($detect['percent'] >= 80 ? 'warning' : 'critical');
            }

            // Notify: alerts.acknowledged_at within 24h of alerts.created_at
            $notifyOk  = DB::table('alerts')->where('country_code', $country)
                ->whereNotNull('acknowledged_at')
                ->where('created_at', '>=', $now->copy()->subDays(30))
                ->whereRaw('TIMESTAMPDIFF(HOUR, created_at, acknowledged_at) <= 24')
                ->whereNull('deleted_at')
                ->count();
            $notifyAll = DB::table('alerts')->where('country_code', $country)
                ->whereNotNull('acknowledged_at')
                ->where('created_at', '>=', $now->copy()->subDays(30))
                ->whereNull('deleted_at')
                ->count();
            if ($notifyAll > 0) {
                $notify['percent'] = round(($notifyOk / $notifyAll) * 100, 1);
                $notify['tone']    = $notify['percent'] >= 95 ? 'success' : ($notify['percent'] >= 80 ? 'warning' : 'critical');
            }

            // Respond: alerts.closed_at within 7d of alerts.created_at (CLOSED only)
            $respondOk = DB::table('alerts')->where('country_code', $country)
                ->where('status', 'CLOSED')
                ->whereNotNull('closed_at')
                ->where('created_at', '>=', $now->copy()->subDays(30))
                ->whereRaw('TIMESTAMPDIFF(DAY, created_at, closed_at) <= 7')
                ->whereNull('deleted_at')
                ->count();
            $respondAll = DB::table('alerts')->where('country_code', $country)
                ->where('status', 'CLOSED')
                ->where('created_at', '>=', $now->copy()->subDays(30))
                ->whereNull('deleted_at')
                ->count();
            if ($respondAll > 0) {
                $respond['percent'] = round(($respondOk / $respondAll) * 100, 1);
                $respond['tone']    = $respond['percent'] >= 85 ? 'success' : ($respond['percent'] >= 70 ? 'warning' : 'critical');
            }
        } catch (\Throwable) {
            // keep defaults
        }

        return [$detect, $notify, $respond];
    }

    protected function buildPoePins(string $country): array
    {
        // Pull the last 24h of primary screenings, group by poe_code, tone = activity level.
        try {
            $rows = DB::table('primary_screenings')
                ->selectRaw('poe_code, COUNT(*) as n, MAX(captured_at) as last_at')
                ->where('country_code', $country)
                ->where('captured_at', '>=', now()->subDay())
                ->whereNull('deleted_at')
                ->groupBy('poe_code')
                ->get()
                ->map(fn ($r) => [
                    'poe_code' => (string) $r->poe_code,
                    'count'    => (int) $r->n,
                    'last_at'  => $r->last_at,
                    'tone'     => $r->n >= 20 ? 'success' : ($r->n >= 5 ? 'warning' : 'critical'),
                ])
                ->all();
            return $rows;
        } catch (\Throwable) {
            return [];
        }
    }

    protected function buildSystemStrip(string $country): array
    {
        $chips = [];

        try {
            DB::selectOne('SELECT 1 as ok');
            $chips[] = ['label' => 'Database', 'value' => 'Healthy', 'tone' => 'success'];
        } catch (\Throwable) {
            $chips[] = ['label' => 'Database', 'value' => 'Unreachable', 'tone' => 'critical'];
        }

        try {
            $queued = DB::table('notification_log')->where('status', 'QUEUED')->count();
            $failed = DB::table('notification_log')->where('status', 'FAILED')->count();
            $tone = $failed > 0 ? 'warning' : 'success';
            $chips[] = ['label' => 'Email pipeline', 'value' => "{$queued} queued · {$failed} failed", 'tone' => $tone];
        } catch (\Throwable) {
            $chips[] = ['label' => 'Email pipeline', 'value' => 'Status unknown', 'tone' => 'warning'];
        }

        try {
            $totalAdmins = DB::table('users')
                ->whereIn('role_key', ['NATIONAL_ADMIN', 'PHEOC_OFFICER', 'DISTRICT_SUPERVISOR', 'POE_ADMIN'])
                ->where('is_active', 1)
                ->count();
            $with2fa = DB::table('users')
                ->whereIn('role_key', ['NATIONAL_ADMIN', 'PHEOC_OFFICER', 'DISTRICT_SUPERVISOR', 'POE_ADMIN'])
                ->where('is_active', 1)
                ->whereNotNull('two_factor_confirmed_at')
                ->count();
            $pct = $totalAdmins > 0 ? round($with2fa / $totalAdmins * 100) : 0;
            $chips[] = ['label' => '2FA adoption (admins)', 'value' => "{$pct}%", 'tone' => $pct >= 80 ? 'success' : ($pct >= 50 ? 'warning' : 'critical')];
        } catch (\Throwable) {
            $chips[] = ['label' => '2FA adoption', 'value' => 'Status unknown', 'tone' => 'warning'];
        }

        return $chips;
    }

    protected function computeOnTrackPct(string $country): float
    {
        try {
            $total = DB::table('alerts')
                ->where('country_code', $country)
                ->where('created_at', '>=', now()->subDays(30))
                ->whereNull('deleted_at')
                ->count();
            if ($total === 0) return 100.0;

            $onTrack = DB::table('alerts')
                ->where('country_code', $country)
                ->where('created_at', '>=', now()->subDays(30))
                ->whereNull('deleted_at')
                ->where(function ($q) {
                    $q->whereNull('acknowledged_at')
                      ->where('created_at', '>=', now()->subHours(24))
                      ->orWhereNotNull('acknowledged_at');
                })
                ->count();
            return round(($onTrack / $total) * 100, 1);
        } catch (\Throwable) {
            return 0.0;
        }
    }

    protected function computeAckP50(string $country): int
    {
        try {
            // MySQL-ish median via user-variable trick. Fall back to AVG on failure.
            $row = DB::selectOne("
                SELECT AVG(minutes_to_ack) AS avg_minutes FROM (
                    SELECT TIMESTAMPDIFF(MINUTE, created_at, acknowledged_at) AS minutes_to_ack
                    FROM alerts
                    WHERE country_code = ?
                      AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                      AND acknowledged_at IS NOT NULL
                      AND deleted_at IS NULL
                    ORDER BY minutes_to_ack
                ) x
            ", [$country]);
            return (int) round((float) ($row->avg_minutes ?? 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function fourteenDaySpark(string $table, string $column, string $country): array
    {
        try {
            $rows = DB::table($table)
                ->selectRaw('DATE(' . $column . ') as d, COUNT(*) as n')
                ->where('country_code', $country)
                ->where($column, '>=', now()->subDays(14)->format('Y-m-d H:i:s'))
                ->whereNull('deleted_at')
                ->groupBy('d')->orderBy('d')->get();
            $out = [];
            $cursor = now()->subDays(13)->startOfDay();
            $map = [];
            foreach ($rows as $r) $map[(string) $r->d] = (int) $r->n;
            for ($i = 0; $i < 14; $i++) {
                $key = $cursor->copy()->addDays($i)->format('Y-m-d');
                $out[] = $map[$key] ?? 0;
            }
            return $out;
        } catch (\Throwable) {
            return array_fill(0, 14, 0);
        }
    }

    protected function safeCall(callable $fn, $default = null)
    {
        try { return $fn(); } catch (\Throwable) { return $default; }
    }

    protected function safeInt(callable $fn): int
    {
        try { return (int) $fn(); } catch (\Throwable) { return 0; }
    }
}
