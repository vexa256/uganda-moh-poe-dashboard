<?php

declare (strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║  NotificationsController                                                 ║
 * ║  Enterprise notification engine — alerts, escalations, reminders,        ║
 * ║  daily reports, PHEIC advisories. Uses poe_notification_contacts for     ║
 * ║  addressing and notification_templates for copy. Logs every send         ║
 * ║  attempt in notification_log (audit + retry).                            ║
 * ╠══════════════════════════════════════════════════════════════════════════╣
 * ║  CALLABLE FROM:                                                           ║
 * ║    Views (authenticated user triggers)                                    ║
 * ║    Cron jobs (console commands scheduled hourly / daily / weekly)         ║
 * ║    AlertsController / AlertFollowupsController side-effects               ║
 * ║                                                                           ║
 * ║  ROUTES:                                                                  ║
 * ║    POST /notifications/alert-broadcast                                    ║
 * ║    POST /notifications/escalation                                         ║
 * ║    POST /notifications/followup-reminder                                  ║
 * ║    POST /notifications/pheic-advisory                                     ║
 * ║    POST /notifications/daily-report                                       ║
 * ║    POST /notifications/weekly-report                                      ║
 * ║    POST /notifications/send  (ad-hoc)                                     ║
 * ║    POST /notifications/retry-failed                                       ║
 * ║    GET  /notifications/log                                                ║
 * ║    GET  /notifications/stats                                              ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class NotificationsController extends Controller
{
    private const TRIGGER_ROLES = ['NATIONAL_ADMIN', 'POE_ADMIN', 'DISTRICT_SUPERVISOR', 'PHEOC_OFFICER', 'POE_SECONDARY', 'POE_DATA_OFFICER'];

    // ═══════════════════════════════════════════════════════════════════════
    //  PUBLIC ENDPOINTS
    // ═══════════════════════════════════════════════════════════════════════

    // POST /notifications/alert-broadcast
    // Body: { alert_id, user_id, triggered_by? }
    public function alertBroadcast(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');

        $alertId = (int) $request->input('alert_id', 0);
        $alert   = DB::table('alerts')->where('id', $alertId)->whereNull('deleted_at')->first();
        if (! $alert) return $this->err(404, 'Alert not found.');

        try {
            $result = $this->broadcastAlert($alert, 'USER:' . $user->id);
            return $this->ok($result, 'Alert broadcast dispatched.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'alert-broadcast');
        }
    }

    // POST /notifications/escalation
    // Body: { alert_id, escalate_to_level, reason?, user_id }
    public function escalation(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');

        $alertId = (int) $request->input('alert_id', 0);
        $escalateTo = strtoupper((string) $request->input('escalate_to_level', ''));
        $reason = (string) $request->input('reason', '');

        if (! in_array($escalateTo, ['PHEOC', 'NATIONAL', 'WHO'], true)) {
            return $this->err(422, 'escalate_to_level must be PHEOC, NATIONAL or WHO.');
        }

        $alert = DB::table('alerts')->where('id', $alertId)->whereNull('deleted_at')->first();
        if (! $alert) return $this->err(404, 'Alert not found.');

        $contacts = $this->resolveContactsForAlert($alert, $escalateTo);
        $sent = 0; $skipped = 0; $failed = 0;
        foreach ($contacts as $c) {
            $out = $this->sendNotification(
                $c,
                'ESCALATION',
                [
                    'alert_code'         => $alert->alert_code,
                    'alert_title'        => $alert->alert_title,
                    'routed_to_level'    => $alert->routed_to_level,
                    'escalate_to_level'  => $escalateTo,
                    'escalation_reason'  => $reason ?: 'Operational escalation',
                    'poe_code'           => $alert->poe_code,
                ],
                $alert,
                'USER:' . $user->id,
            );
            if ($out['status'] === 'SENT')      $sent++;
            elseif ($out['status'] === 'SKIPPED') $skipped++;
            else                                 $failed++;
        }
        return $this->ok(compact('sent', 'skipped', 'failed'), 'Escalation dispatched.');
    }

    // POST /notifications/followup-reminder
    // Body: { followup_id, user_id }
    public function followupReminder(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');

        $fId = (int) $request->input('followup_id', 0);
        $f = DB::table('alert_followups')->where('id', $fId)->whereNull('deleted_at')->first();
        if (! $f) return $this->err(404, 'Follow-up not found.');

        $alert = DB::table('alerts')->where('id', $f->alert_id)->first();
        if (! $alert) return $this->err(404, 'Linked alert not found.');

        $now = now();
        $dueAt = $f->due_at ? strtotime((string) $f->due_at) : null;
        $isOverdue = $dueAt && $now->timestamp > $dueAt && $f->status !== 'COMPLETED' && $f->status !== 'NOT_APPLICABLE';
        $code = $isOverdue ? 'FOLLOWUP_OVERDUE' : 'FOLLOWUP_DUE';

        $contacts = $this->resolveContactsForFollowup($f, $alert);
        $sent = 0; $skipped = 0; $failed = 0;
        foreach ($contacts as $c) {
            $out = $this->sendNotification(
                $c,
                $code,
                [
                    'action_label' => $f->action_label,
                    'alert_code'   => $alert->alert_code,
                    'due_at'       => $f->due_at,
                    'due_in_hours' => $dueAt ? max(0, (int) round(($dueAt - $now->timestamp) / 3600)) : 0,
                    'poe_code'     => $alert->poe_code,
                ],
                $alert,
                'USER:' . $user->id,
                'FOLLOWUP',
                $f->id,
            );
            if ($out['status'] === 'SENT')      $sent++;
            elseif ($out['status'] === 'SKIPPED') $skipped++;
            else                                 $failed++;
        }
        return $this->ok(compact('sent', 'skipped', 'failed'), 'Reminder dispatched.');
    }

    // POST /notifications/pheic-advisory
    // Body: { alert_id, user_id }
    public function pheicAdvisory(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');

        $alert = DB::table('alerts')->where('id', (int) $request->input('alert_id'))->whereNull('deleted_at')->first();
        if (! $alert) return $this->err(404, 'Alert not found.');

        // PHEIC advisories go to NATIONAL + WHO level only
        $contacts = DB::table('poe_notification_contacts')
            ->whereIn('level', ['NATIONAL', 'WHO'])
            ->where('country_code', $alert->country_code)
            ->where('is_active', 1)->whereNull('deleted_at')
            ->where('receives_tier1', 1)
            ->orderByRaw("FIELD(level,'WHO','NATIONAL')")
            ->orderBy('priority_order')->get();

        $sent = 0; $skipped = 0; $failed = 0;
        foreach ($contacts as $c) {
            $out = $this->sendNotification(
                $c,
                'PHEIC_ADVISORY',
                [
                    'alert_code'  => $alert->alert_code,
                    'alert_title' => $alert->alert_title,
                    'poe_code'    => $alert->poe_code,
                ],
                $alert,
                'USER:' . $user->id,
            );
            if ($out['status'] === 'SENT')      $sent++;
            elseif ($out['status'] === 'SKIPPED') $skipped++;
            else                                 $failed++;
        }
        return $this->ok(compact('sent', 'skipped', 'failed'), 'PHEIC advisory dispatched.');
    }

    // POST /notifications/daily-report  — cron-triggerable
    // Body: { poe_code?, country_code?, user_id, triggered_by? }
    public function dailyReport(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');

        $poeCode = (string) $request->input('poe_code', '');
        $countryCode = (string) $request->input('country_code', $user->country_code ?? '');
        $reportDate = now()->format('Y-m-d');
        $windowStart = now()->subDay()->format('Y-m-d H:i:s');

        // Aggregate today's stats for the scope
        $alertsQ = DB::table('alerts')->where('created_at', '>=', $windowStart)->whereNull('deleted_at');
        $screenQ = DB::table('primary_screenings')->where('captured_at', '>=', $windowStart)->whereNull('deleted_at');
        if ($poeCode) {
            $alertsQ->where('poe_code', $poeCode);
            $screenQ->where('poe_code', $poeCode);
        } elseif ($countryCode) {
            $alertsQ->where('country_code', $countryCode);
            $screenQ->where('country_code', $countryCode);
        }

        $stats = [
            'report_date'      => $reportDate,
            'poe_code'         => $poeCode ?: 'ALL',
            'open_alerts'      => (clone $alertsQ)->where('status', 'OPEN')->count(),
            'critical_alerts'  => (clone $alertsQ)->where('risk_level', 'CRITICAL')->count(),
            'breach_alerts'    => (clone $alertsQ)->whereRaw('TIMESTAMPDIFF(HOUR, created_at, NOW()) > 24')->where('status', 'OPEN')->count(),
            'screened_today'   => (clone $screenQ)->count(),
            'symptomatic_today' => (clone $screenQ)->where('symptoms_present', 1)->count(),
        ];

        $contactsQ = DB::table('poe_notification_contacts')
            ->where('receives_daily_report', 1)
            ->where('is_active', 1)->whereNull('deleted_at');
        if ($poeCode)       $contactsQ->where('poe_code', $poeCode);
        elseif ($countryCode) $contactsQ->where('country_code', $countryCode);
        $contacts = $contactsQ->get();

        $sent = 0; $skipped = 0; $failed = 0;
        foreach ($contacts as $c) {
            $out = $this->sendNotification(
                $c, 'DAILY_REPORT', $stats, null,
                (string) ($request->input('triggered_by', 'USER:' . $user->id)),
                'REPORT', null,
            );
            if ($out['status'] === 'SENT')      $sent++;
            elseif ($out['status'] === 'SKIPPED') $skipped++;
            else                                 $failed++;
        }

        return $this->ok(array_merge($stats, compact('sent', 'skipped', 'failed')), 'Daily report dispatched.');
    }

    // POST /notifications/weekly-report
    public function weeklyReport(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');

        $poeCode = (string) $request->input('poe_code', '');
        $countryCode = (string) $request->input('country_code', $user->country_code ?? '');
        $weekNumber = (int) now()->format('W');
        $windowStart = now()->subDays(7)->format('Y-m-d H:i:s');

        $alertsQ = DB::table('alerts')->where('created_at', '>=', $windowStart)->whereNull('deleted_at');
        if ($poeCode)        $alertsQ->where('poe_code', $poeCode);
        elseif ($countryCode) $alertsQ->where('country_code', $countryCode);

        $vars = [
            'week_number'   => $weekNumber,
            'poe_code'      => $poeCode ?: 'ALL',
            'total_alerts'  => $alertsQ->count(),
        ];

        $contactsQ = DB::table('poe_notification_contacts')
            ->where('receives_weekly_report', 1)
            ->where('is_active', 1)->whereNull('deleted_at');
        if ($poeCode)        $contactsQ->where('poe_code', $poeCode);
        elseif ($countryCode) $contactsQ->where('country_code', $countryCode);
        $contacts = $contactsQ->get();

        $sent = 0; $skipped = 0; $failed = 0;
        foreach ($contacts as $c) {
            $out = $this->sendNotification($c, 'WEEKLY_REPORT', $vars, null, 'USER:' . $user->id, 'REPORT');
            if ($out['status'] === 'SENT')      $sent++;
            elseif ($out['status'] === 'SKIPPED') $skipped++;
            else                                 $failed++;
        }
        return $this->ok(array_merge($vars, compact('sent', 'skipped', 'failed')), 'Weekly report dispatched.');
    }

    // POST /notifications/send  — ad-hoc single send
    public function send(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');
        $role = $user->role_key ?? '';
        if (! in_array($role, self::TRIGGER_ROLES, true)) {
            return $this->err(403, 'Your role is not authorised to trigger notifications.');
        }

        $contactId    = (int) $request->input('contact_id', 0);
        $templateCode = strtoupper((string) $request->input('template_code', ''));
        $vars         = (array) $request->input('vars', []);

        if ($contactId <= 0 || empty($templateCode)) {
            return $this->err(422, 'contact_id and template_code are required.');
        }
        $contact = DB::table('poe_notification_contacts')->where('id', $contactId)->whereNull('deleted_at')->first();
        if (! $contact) return $this->err(404, 'Contact not found.');

        $out = $this->sendNotification($contact, $templateCode, $vars, null, 'USER:' . $user->id);
        return $this->ok($out, $out['status']);
    }

    // POST /notifications/retry-failed — cron-triggerable
    public function retryFailed(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');

        $rows = DB::table('notification_log')
            ->where('status', 'FAILED')
            ->where('retry_count', '<', 4)
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        $retried = 0;
        foreach ($rows as $row) {
            try {
                $this->sendMail($row->to_email, (string) $row->subject, (string) $row->body_full);
                DB::table('notification_log')->where('id', $row->id)->update([
                    'status'       => 'SENT',
                    'sent_at'      => now(),
                    'error_message' => null,
                    'retry_count'  => (int) $row->retry_count + 1,
                    'updated_at'   => now(),
                ]);
                $retried++;
            } catch (Throwable $e) {
                DB::table('notification_log')->where('id', $row->id)->update([
                    'retry_count'  => (int) $row->retry_count + 1,
                    'error_message' => substr($e->getMessage(), 0, 500),
                    'updated_at'   => now(),
                ]);
            }
        }
        return $this->ok(['retried' => $retried, 'candidates' => $rows->count()], 'Retry cycle complete.');
    }

    // GET /notifications/log
    public function log(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');
        $q = DB::table('notification_log');
        foreach (['status', 'template_code', 'poe_code', 'related_entity_type'] as $k) {
            if ($request->filled($k)) $q->where($k, $request->query($k));
        }
        $perPage = min(200, max(10, (int) $request->query('per_page', 50)));
        $rows = $q->orderByDesc('id')->limit($perPage)->get();
        return $this->ok($rows->map(fn ($r) => (array) $r)->values()->all(), 'Log retrieved.');
    }

    // GET /notifications/stats
    public function stats(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');
        $since = now()->subDays(30)->format('Y-m-d H:i:s');
        $q = DB::table('notification_log')->where('created_at', '>=', $since);
        $stats = [
            'window_days' => 30,
            'total'       => (clone $q)->count(),
            'sent'        => (clone $q)->where('status', 'SENT')->count(),
            'failed'      => (clone $q)->where('status', 'FAILED')->count(),
            'bounced'     => (clone $q)->where('status', 'BOUNCED')->count(),
            'skipped'     => (clone $q)->where('status', 'SKIPPED')->count(),
            'by_template' => (clone $q)->selectRaw('template_code, COUNT(*) as n')->groupBy('template_code')->orderByDesc('n')->get()->map(fn ($r) => (array) $r)->all(),
        ];
        return $this->ok($stats, 'Notification stats (30d).');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  CORE ENGINE — internal helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Broadcast a new alert to contacts at the alert's routed_to_level,
     * honouring receives_* flags based on risk_level + IHR tier.
     */
    private function broadcastAlert(object $alert, string $triggeredBy): array
    {
        // Determine template based on IHR tier + risk
        $tierOne = is_string($alert->ihr_tier) && str_contains($alert->ihr_tier, 'TIER_1');
        $tierTwo = is_string($alert->ihr_tier) && str_contains($alert->ihr_tier, 'TIER_2');
        $templateCode = $tierOne ? 'TIER1_ADVISORY'
                      : ($alert->risk_level === 'CRITICAL' ? 'ALERT_CRITICAL'
                      : ($alert->risk_level === 'HIGH'     ? 'ALERT_HIGH' : 'ALERT_HIGH'));

        $contacts = $this->resolveContactsForAlert($alert, $alert->routed_to_level);
        $sent = 0; $skipped = 0; $failed = 0;
        foreach ($contacts as $c) {
            $vars = [
                'alert_code'      => $alert->alert_code,
                'alert_title'     => $alert->alert_title,
                'alert_details'   => $alert->alert_details,
                'risk_level'      => $alert->risk_level,
                'routed_to_level' => $alert->routed_to_level,
                'ihr_tier'        => $alert->ihr_tier ?? 'none',
                'poe_code'        => $alert->poe_code,
                'district_code'   => $alert->district_code,
                'ack_hours'       => $alert->risk_level === 'CRITICAL' ? 4 : 24,
            ];
            $out = $this->sendNotification($c, $templateCode, $vars, $alert, $triggeredBy);
            if ($out['status'] === 'SENT')      $sent++;
            elseif ($out['status'] === 'SKIPPED') $skipped++;
            else                                 $failed++;
        }
        return compact('sent', 'skipped', 'failed', 'templateCode');
    }

    /**
     * Resolve the eligible contact list for an alert at a given level.
     * Honours the receives_* flags matching the alert's risk + tier.
     */
    private function resolveContactsForAlert(object $alert, string $level): \Illuminate\Support\Collection
    {
        $risk = strtolower($alert->risk_level ?? 'high');
        $flag = "receives_{$risk}";
        $tierOne = is_string($alert->ihr_tier) && str_contains($alert->ihr_tier, 'TIER_1');
        $tierTwo = is_string($alert->ihr_tier) && str_contains($alert->ihr_tier, 'TIER_2');

        $q = DB::table('poe_notification_contacts')
            ->where('level', $level)
            ->where('is_active', 1)->whereNull('deleted_at');

        // Geographic scoping matches the alert
        if ($level === 'POE' || $level === 'DISTRICT') {
            $q->where(function ($qq) use ($alert, $level) {
                if ($level === 'POE') $qq->where('poe_code', $alert->poe_code);
                else                  $qq->where('district_code', $alert->district_code);
            });
        } elseif ($level === 'PHEOC') {
            $q->where('country_code', $alert->country_code);
        } elseif ($level === 'NATIONAL' || $level === 'WHO') {
            $q->where('country_code', $alert->country_code);
        }

        // Respect receives_* filter
        if (in_array($flag, ['receives_critical', 'receives_high', 'receives_medium', 'receives_low'], true)) {
            $q->where($flag, 1);
        }
        if ($tierOne) $q->where('receives_tier1', 1);
        if ($tierTwo) $q->where('receives_tier2', 1);

        return $q->orderBy('priority_order')->orderBy('id')->get();
    }

    private function resolveContactsForFollowup(object $f, object $alert): \Illuminate\Support\Collection
    {
        return DB::table('poe_notification_contacts')
            ->where('poe_code', $alert->poe_code)
            ->where('receives_followup_reminders', 1)
            ->where('is_active', 1)->whereNull('deleted_at')
            ->orderBy('priority_order')->get();
    }

    /**
     * Send a single notification. Never throws — always logs.
     */
    private function sendNotification(
        object $contact,
        string $templateCode,
        array $vars,
        ?object $relatedAlert = null,
        string $triggeredBy = 'SYSTEM',
        string $entityType = 'ALERT',
        ?int $entityId = null,
    ): array {
        // Template lookup
        $tpl = DB::table('notification_templates')
            ->where('template_code', $templateCode)
            ->where('channel', 'EMAIL')->where('is_active', 1)->first();
        if (! $tpl) {
            return $this->logSend($contact, $templateCode, '(missing template)', '', 'SKIPPED',
                "Template '$templateCode' not found", $relatedAlert, $triggeredBy, $entityType, $entityId);
        }

        // Address check
        $to = $contact->email ?: $contact->alternate_email;
        if (! $to) {
            return $this->logSend($contact, $templateCode, (string) $tpl->subject_template, '', 'SKIPPED',
                'Contact has no email address', $relatedAlert, $triggeredBy, $entityType, $entityId);
        }

        // Render subject + body
        $subject = $this->render((string) $tpl->subject_template, $vars);
        $body    = $this->render((string) $tpl->body_html_template, $vars);

        // AI-style enhancement for flagged templates — prepend a custom, context-aware
        // lead paragraph assembled from the vars + WHO/IHR citations. This is
        // hardcoded intelligence (no external LLM) so behaviour is deterministic
        // and offline-ready.
        if ((int) $tpl->is_ai_enhanced === 1) {
            $body = $this->aiEnhance($templateCode, $vars, $body);
        }

        try {
            $this->sendMail($to, $subject, $body);
            $row = $this->logSend($contact, $templateCode, $subject, $body, 'SENT', null,
                $relatedAlert, $triggeredBy, $entityType, $entityId);
            // Touch last_notified_at on the contact
            DB::table('poe_notification_contacts')->where('id', $contact->id)
                ->update(['last_notified_at' => now(), 'updated_at' => now()]);
            return $row;
        } catch (Throwable $e) {
            return $this->logSend($contact, $templateCode, $subject, $body, 'FAILED',
                substr($e->getMessage(), 0, 500), $relatedAlert, $triggeredBy, $entityType, $entityId);
        }
    }

    /**
     * Render a Mustache-style template: replaces {{key}} with the string value.
     * Missing keys resolve to empty string. Values are HTML-escaped.
     */
    private function render(string $tpl, array $vars): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', function ($m) use ($vars) {
            $k = $m[1];
            $v = $vars[$k] ?? '';
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        }, $tpl) ?? $tpl;
    }

    /**
     * Deterministic "AI-style" enrichment. Prepends a context-appropriate
     * lead paragraph with WHO/IHR citations. No external LLM used.
     */
    private function aiEnhance(string $code, array $vars, string $body): string
    {
        $lead = '';
        switch ($code) {
            case 'TIER1_ADVISORY':
                $lead = '<p><em>Operational note — The alert you are being notified of matches an IHR 2005 Annex 2 Tier 1 always-notifiable event. A single confirmed or probable case of the subject disease is sufficient trigger for WHO notification within 24 hours, irrespective of the 4-criteria Annex 2 assessment. Confirm your National IHR Focal Point has been briefed and escalate to the Minister of Health in parallel.</em></p>';
                break;
            case 'ANNEX2_HIT':
                $lead = '<p><em>Operational note — This event has triggered the ≥2 of 4 criteria threshold under the IHR 2005 Annex 2 decision instrument. The State Party is obligated to notify WHO within 24 hours via the National IHR Focal Point (IHR Art. 6). Please document the YES determinations in the audit log.</em></p>';
                break;
            case 'ALERT_CRITICAL':
                $lead = '<p><em>Operational note — CRITICAL-risk events at a POE require immediate clinical response, full contact tracing, and activation of the POE emergency protocol. Per IDSR 3rd Ed., acknowledge within 4 hours and coordinate with the district health officer.</em></p>';
                break;
            case 'BREACH_717':
                $lead = '<p><em>Operational note — 7-1-7 targets (Detect ≤7d, Notify ≤1d, Respond ≤7d) exist to make bottlenecks visible. A breach at the current stage requires root-cause analysis per RTSL / WHO guidance: capacity, training, laboratory, coordination, legal — one of these is typically the dominant cause.</em></p>';
                break;
            case 'FOLLOWUP_OVERDUE':
                $lead = '<p><em>Operational note — Outstanding follow-up actions prevent closure of the linked alert and jeopardise the 7-day response target. Update the status in the Intelligence view or escalate to a colleague if you cannot complete within the next 24 hours.</em></p>';
                break;
            case 'PHEIC_ADVISORY':
                $lead = '<p><em>Operational note — Preliminary PHEIC indicators have been identified. A Public Health Emergency of International Concern is declared by the WHO Director-General per IHR Art. 12 on advice of an Emergency Committee. Ensure the National IHR Focal Point has all current information.</em></p>';
                break;
            case 'ESCALATION':
                $lead = '<p><em>Operational note — Escalation has been triggered as the prior-level response window has elapsed or the severity threshold has shifted. The receiving level has full authority to acknowledge, close, or further escalate.</em></p>';
                break;
            case 'DAILY_REPORT':
                $lead = '<p><em>Daily surveillance digest — this automated email summarises the previous 24 hours of alerts and screenings in your scope. Full dashboards with historical trend are available in the POE Sentinel application.</em></p>';
                break;
            default:
                // No enhancement
                return $body;
        }
        return $lead . $body;
    }

    /**
     * Persist a send attempt to notification_log. Always returns a shape that
     * callers can bubble up.
     */
    private function logSend(
        object $contact,
        string $templateCode,
        string $subject,
        string $body,
        string $status,
        ?string $error,
        ?object $relatedAlert,
        string $triggeredBy,
        string $entityType = 'ALERT',
        ?int $entityId = null,
    ): array {
        $now = now()->format('Y-m-d H:i:s');
        $id = DB::table('notification_log')->insertGetId([
            'contact_id'          => $contact->id ?? null,
            'to_email'            => $contact->email ?? null,
            'to_phone'            => $contact->phone ?? null,
            'channel'             => 'EMAIL',
            'template_code'       => $templateCode,
            'subject'             => substr($subject, 0, 240),
            'body_preview'        => substr(strip_tags($body), 0, 500),
            'body_full'           => $body,
            'related_entity_type' => $entityType,
            'related_entity_id'   => $entityId ?? ($relatedAlert->id ?? null),
            'country_code'        => $relatedAlert->country_code ?? ($contact->country_code ?? null),
            'district_code'       => $relatedAlert->district_code ?? ($contact->district_code ?? null),
            'poe_code'            => $relatedAlert->poe_code ?? ($contact->poe_code ?? null),
            'status'              => $status,
            'error_message'       => $error,
            'retry_count'         => 0,
            'sent_at'             => $status === 'SENT' ? $now : null,
            'failed_at'           => $status === 'FAILED' ? $now : null,
            'triggered_by'        => substr($triggeredBy, 0, 40),
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);
        return [
            'log_id' => $id,
            'status' => $status,
            'to'     => $contact->email ?? null,
            'subject' => $subject,
            'error'  => $error,
        ];
    }

    /**
     * Deliver the actual email. Uses Laravel Mail if configured; falls back to
     * Log channel so the system stays functional in dev/offline environments
     * without a real SMTP — the notification_log still captures the full body.
     */
    private function sendMail(string $to, string $subject, string $bodyHtml): void
    {
        try {
            Mail::html($bodyHtml, function ($m) use ($to, $subject) {
                $m->to($to)->subject($subject);
            });
        } catch (Throwable $e) {
            // In dev / offline, log instead of throwing so the log row still records
            Log::info("[Mail:fallback] To: {$to} · {$subject}");
            Log::info(strip_tags($bodyHtml));
            // Only re-throw in production (let Laravel's mail queue/retry handle it)
            if (config('app.env') === 'production') {
                throw $e;
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    private function authUser(Request $request): ?object
    {
        $userId = (int) ($request->input('user_id') ?? $request->query('user_id') ?? 0);
        if ($userId <= 0) return null;
        return DB::table('users')->where('id', $userId)->first() ?: null;
    }
    private function ok(array $data, string $message, array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => $data];
        if (! empty($meta)) $body['meta'] = $meta;
        return response()->json($body, 200);
    }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error' => $detail], $status);
    }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    {
        Log::error("[Notifications][ERROR] {$ctx}", ['exception' => get_class($e), 'message' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
        return response()->json(['success' => false, 'message' => "Server error: {$ctx}"], 500);
    }
}
