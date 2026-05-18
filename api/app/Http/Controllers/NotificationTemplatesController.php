<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\CaseContextBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NotificationTemplatesController
 * ─────────────────────────────────────────────────────────────────────────
 * Admin CRUD on notification_templates. Production senders read this table
 * by template_code, so edits are picked up live without a deploy.
 *
 *   GET    /notification-templates                list + filter
 *   GET    /notification-templates/{code}         fetch by code (with usage stats)
 *   POST   /notification-templates                create (admin only)
 *   PATCH  /notification-templates/{code}         update (subject/html/text/levels/active)
 *   DELETE /notification-templates/{code}         soft-disable (is_active=0)
 *   POST   /notification-templates/{code}/preview Render a dry run against an alert
 *     Body: { alert_id? | sample?: true } → returns rendered html + text
 *   GET    /notification-templates/{code}/usage   count by status (SENT/SKIPPED/FAILED)
 *
 * Tokens reference for PWA editor autocompletion:
 *   GET    /notification-templates/token-reference → list of known tokens with
 *          descriptions (generated from CaseContextBuilder + digests).
 */
final class NotificationTemplatesController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        try {
            $q = DB::table('notification_templates');
            if ($active = $r->query('active')) $q->where('is_active', (int) ((string) $active === '1'));
            if ($ch = $r->query('channel'))    $q->where('channel', $ch);
            if ($s = $r->query('search')) {
                $q->where(function ($x) use ($s) {
                    $x->where('template_code', 'like', "%$s%")
                      ->orWhere('subject_template', 'like', "%$s%");
                });
            }
            $rows = $q->orderBy('template_code')->get()->map(function ($r) {
                return [
                    'id' => $r->id, 'template_code' => $r->template_code, 'channel' => $r->channel,
                    'subject_template' => $r->subject_template,
                    'body_html_bytes' => mb_strlen((string) $r->body_html_template),
                    'body_text_bytes' => mb_strlen((string) $r->body_text_template),
                    'applicable_levels' => $r->applicable_levels,
                    'is_ai_enhanced' => $r->is_ai_enhanced, 'is_active' => $r->is_active,
                    'updated_at' => $r->updated_at,
                ];
            });
            return $this->ok(['templates' => $rows, 'count' => $rows->count()]);
        } catch (Throwable $e) { return $this->fail($e, 'index'); }
    }

    public function show(string $code): JsonResponse
    {
        try {
            $row = DB::table('notification_templates')->where('template_code', $code)->first();
            if (! $row) return $this->err('Template not found', 404);
            $usage = DB::table('notification_log')
                ->where('template_code', $code)
                ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->get();
            return $this->ok(['template' => $row, 'usage' => $usage]);
        } catch (Throwable $e) { return $this->fail($e, 'show'); }
    }

    public function store(Request $r): JsonResponse
    {
        try {
            $data = $r->validate([
                'template_code'      => 'required|string|max:60|unique:notification_templates,template_code',
                'channel'            => 'required|in:EMAIL,SMS,PUSH',
                'subject_template'   => 'required|string|max:200',
                'body_html_template' => 'required|string',
                'body_text_template' => 'nullable|string',
                'applicable_levels'  => 'nullable|array',
                'is_ai_enhanced'     => 'nullable|boolean',
                'is_active'          => 'nullable|boolean',
            ]);
            $data['applicable_levels'] = isset($data['applicable_levels']) ? json_encode($data['applicable_levels']) : null;
            $id = DB::table('notification_templates')->insertGetId(array_merge($data, [
                'is_active' => $data['is_active'] ?? 1,
                'is_ai_enhanced' => $data['is_ai_enhanced'] ?? 0,
                'created_at' => now(), 'updated_at' => now(),
            ]));
            return $this->ok(['id' => $id], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        } catch (Throwable $e) { return $this->fail($e, 'store'); }
    }

    public function update(Request $r, string $code): JsonResponse
    {
        try {
            $row = DB::table('notification_templates')->where('template_code', $code)->first();
            if (! $row) return $this->err('Template not found', 404);
            $data = $r->validate([
                'subject_template'   => 'nullable|string|max:200',
                'body_html_template' => 'nullable|string',
                'body_text_template' => 'nullable|string',
                'applicable_levels'  => 'nullable|array',
                'is_ai_enhanced'     => 'nullable|boolean',
                'is_active'          => 'nullable|boolean',
            ]);
            if (isset($data['applicable_levels'])) {
                $data['applicable_levels'] = json_encode($data['applicable_levels']);
            }
            DB::table('notification_templates')->where('template_code', $code)
                ->update(array_merge($data, ['updated_at' => now()]));
            return $this->ok(['updated' => true]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        } catch (Throwable $e) { return $this->fail($e, 'update'); }
    }

    public function destroy(string $code): JsonResponse
    {
        try {
            DB::table('notification_templates')->where('template_code', $code)->update([
                'is_active' => 0, 'updated_at' => now(),
            ]);
            return $this->ok(['disabled' => true]);
        } catch (Throwable $e) { return $this->fail($e, 'destroy'); }
    }

    /**
     * POST /notification-templates/{code}/preview
     * Body: { alert_id?: int, sample?: true }
     * Returns the rendered subject + html + text so a template designer can
     * see exactly what will fly.
     */
    public function preview(Request $r, string $code): JsonResponse
    {
        try {
            $tpl = DB::table('notification_templates')->where('template_code', $code)->first();
            if (! $tpl) return $this->err('Template not found', 404);
            $vars = [];
            if ($alertId = (int) $r->input('alert_id', 0)) {
                $alert = DB::table('alerts')->where('id', $alertId)->first();
                if ($alert) $vars = CaseContextBuilder::forAlert($alert);
            }
            if (empty($vars) || $r->boolean('sample')) {
                $vars = array_merge($vars, $this->sampleVars());
            }
            $render = function (string $tplText) use ($vars): string {
                $out = preg_replace_callback('/\{\{\{\s*([a-z0-9_]+)\s*\}\}\}/i',
                    fn($m) => (string) ($vars[$m[1]] ?? ''), $tplText) ?? $tplText;
                return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
                    fn($m) => htmlspecialchars((string) ($vars[$m[1]] ?? ''), ENT_QUOTES, 'UTF-8'),
                    $out) ?? $out;
            };
            return $this->ok([
                'subject' => $render((string) $tpl->subject_template),
                'html'    => $render((string) $tpl->body_html_template),
                'text'    => $render((string) ($tpl->body_text_template ?? '')),
                'vars_used_count' => count($vars),
            ]);
        } catch (Throwable $e) { return $this->fail($e, 'preview'); }
    }

    public function usage(string $code): JsonResponse
    {
        try {
            $byStatus = DB::table('notification_log')->where('template_code', $code)
                ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->get();
            $last24 = DB::table('notification_log')->where('template_code', $code)
                ->where('created_at', '>=', now()->subDay())->count();
            $last7d = DB::table('notification_log')->where('template_code', $code)
                ->where('created_at', '>=', now()->subDays(7))->count();
            return $this->ok(['by_status' => $byStatus, 'last_24h' => $last24, 'last_7d' => $last7d]);
        } catch (Throwable $e) { return $this->fail($e, 'usage'); }
    }

    /**
     * GET /notification-templates/token-reference
     * Known tokens with descriptions — fuels the autocomplete / legend in
     * the PWA template editor.
     */
    public function tokenReference(): JsonResponse
    {
        return $this->ok(['tokens' => [
            ['group' => 'Alert', 'token' => 'alert_code', 'desc' => 'Unique alert identifier'],
            ['group' => 'Alert', 'token' => 'alert_title', 'desc' => 'One-line human title'],
            ['group' => 'Alert', 'token' => 'alert_details', 'desc' => 'Longer narrative from officer'],
            ['group' => 'Alert', 'token' => 'risk_level', 'desc' => 'LOW / MEDIUM / HIGH / CRITICAL'],
            ['group' => 'Alert', 'token' => 'risk_level_label', 'desc' => 'Prettified risk label'],
            ['group' => 'Alert', 'token' => 'routed_to_level', 'desc' => 'DISTRICT / PHEOC / NATIONAL'],
            ['group' => 'Alert', 'token' => 'ihr_tier', 'desc' => 'IHR_TIER_1 / ANNEX2_EPIDEMIC_PRONE / etc.'],
            ['group' => 'Alert', 'token' => 'alert_status', 'desc' => 'OPEN / ACKNOWLEDGED / CLOSED'],
            ['group' => 'Alert', 'token' => 'alert_created_at', 'desc' => 'When the alert was raised'],
            ['group' => 'Alert', 'token' => 'alert_created_ago', 'desc' => 'Humanised "3 hours ago"'],
            ['group' => 'Alert', 'token' => 'ack_sla_hours', 'desc' => 'SLA in hours'],
            ['group' => 'Alert', 'token' => 'ack_deadline', 'desc' => 'Target acknowledge-by timestamp'],
            ['group' => 'Geography', 'token' => 'country_code', 'desc' => 'ISO-2 code (UG/RW/ZM/MW/ST)'],
            ['group' => 'Geography', 'token' => 'country_name', 'desc' => 'Human country name'],
            ['group' => 'Geography', 'token' => 'district_code', 'desc' => 'District code'],
            ['group' => 'Geography', 'token' => 'poe_code', 'desc' => 'Point of Entry code'],
            ['group' => 'Traveller', 'token' => 'traveler_name', 'desc' => 'Masked name (initials)'],
            ['group' => 'Traveller', 'token' => 'traveler_age', 'desc' => 'Age in years'],
            ['group' => 'Traveller', 'token' => 'traveler_gender', 'desc' => 'FEMALE / MALE / UNKNOWN'],
            ['group' => 'Traveller', 'token' => 'traveler_nationality', 'desc' => 'Country of nationality'],
            ['group' => 'Traveller', 'token' => 'traveler_occupation', 'desc' => 'Occupation string'],
            ['group' => 'Traveller', 'token' => 'traveler_phone', 'desc' => 'Masked phone'],
            ['group' => 'Traveller', 'token' => 'traveler_email', 'desc' => 'Masked email'],
            ['group' => 'Travel', 'token' => 'travel_direction', 'desc' => 'ENTRY / EXIT / TRANSIT'],
            ['group' => 'Travel', 'token' => 'journey_start', 'desc' => 'Country of origin'],
            ['group' => 'Travel', 'token' => 'embarkation_port', 'desc' => 'Embarkation city/port'],
            ['group' => 'Travel', 'token' => 'conveyance_type', 'desc' => 'AIR / LAND / SEA'],
            ['group' => 'Travel', 'token' => 'conveyance_id', 'desc' => 'Flight / bus / vessel ID'],
            ['group' => 'Travel', 'token' => 'seat_number', 'desc' => 'Seat / berth identifier'],
            ['group' => 'Travel', 'token' => 'arrival_datetime', 'desc' => 'Arrival date-time'],
            ['group' => 'Travel', 'token' => 'purpose_of_travel', 'desc' => 'Purpose of travel'],
            ['group' => 'Clinical', 'token' => 'triage_category', 'desc' => 'NON_URGENT / URGENT / EMERGENCY'],
            ['group' => 'Clinical', 'token' => 'syndrome_classification', 'desc' => 'Syndrome label'],
            ['group' => 'Clinical', 'token' => 'emergency_signs', 'desc' => 'PRESENT / absent'],
            ['group' => 'Clinical', 'token' => 'final_disposition', 'desc' => 'Disposition code'],
            ['group' => 'Clinical', 'token' => 'disposition_details', 'desc' => 'Disposition narrative'],
            ['group' => 'Clinical', 'token' => 'officer_notes', 'desc' => 'Officer notes'],
            ['group' => 'Disease', 'token' => 'disease_name', 'desc' => 'Full disease name'],
            ['group' => 'Disease', 'token' => 'disease_ihr_tier', 'desc' => 'IHR tier classification'],
            ['group' => 'Disease', 'token' => 'disease_cfr_pct', 'desc' => 'Case fatality rate %'],
            ['group' => 'Disease', 'token' => 'disease_incubation', 'desc' => 'Incubation range'],
            ['group' => 'Disease', 'token' => 'disease_transmission', 'desc' => 'Transmission route'],
            ['group' => 'Disease', 'token' => 'disease_ppe', 'desc' => 'Required PPE'],
            ['group' => 'Disease', 'token' => 'disease_isolation', 'desc' => 'Required isolation'],
            ['group' => 'Disease', 'token' => 'disease_ihr_notification', 'desc' => 'WHO notification window'],
            ['group' => 'Disease', 'token' => 'disease_case_definition', 'desc' => 'WHO case definition'],
            ['group' => 'Disease', 'token' => 'disease_specimens', 'desc' => 'Specimens to collect'],
            ['group' => 'HTML (triple-brace {{{…}}})', 'token' => 'vitals_html', 'desc' => 'Rendered vitals table rows'],
            ['group' => 'HTML', 'token' => 'symptoms_html', 'desc' => 'Rendered symptoms list'],
            ['group' => 'HTML', 'token' => 'exposures_html', 'desc' => 'Rendered exposures list'],
            ['group' => 'HTML', 'token' => 'suspected_html', 'desc' => 'Rendered differential diagnoses'],
            ['group' => 'HTML', 'token' => 'samples_html', 'desc' => 'Rendered samples list'],
            ['group' => 'HTML', 'token' => 'travel_html', 'desc' => 'Rendered travel history'],
            ['group' => 'HTML', 'token' => 'followups_html', 'desc' => 'Rendered RTSL-14 follow-ups'],
            ['group' => 'HTML', 'token' => 'disease_intel_html', 'desc' => 'Disease intelligence hero card'],
            ['group' => 'HTML', 'token' => 'immediate_actions_html', 'desc' => 'Immediate action list'],
            ['group' => 'HTML', 'token' => 'recommended_tests_html', 'desc' => 'Recommended tests list'],
            ['group' => 'HTML', 'token' => 'key_distinguishers_html', 'desc' => 'Bedside red flags'],
            ['group' => 'HTML', 'token' => 'prior_alerts_html', 'desc' => 'Prior alerts at this POE'],
            ['group' => 'Follow-up', 'token' => 'followup_action_code', 'desc' => 'Action code'],
            ['group' => 'Follow-up', 'token' => 'followup_action_label', 'desc' => 'Action label'],
            ['group' => 'Follow-up', 'token' => 'followup_due_at', 'desc' => 'Due date-time'],
            ['group' => 'Follow-up', 'token' => 'followup_overdue_hours', 'desc' => 'Overdue hours'],
            ['group' => 'Follow-up', 'token' => 'followup_assignee', 'desc' => 'Assignee name / role'],
            ['group' => 'Digest', 'token' => 'primary_screenings_24h', 'desc' => 'Daily count'],
            ['group' => 'Digest', 'token' => 'alerts_24h', 'desc' => 'Alerts raised today'],
            ['group' => 'Digest', 'token' => 'silent_poes_html', 'desc' => 'Silent POEs list'],
            ['group' => 'Digest', 'token' => 'stuck_alerts_html', 'desc' => 'Stuck alerts list'],
            ['group' => 'Digest', 'token' => 'narrative', 'desc' => 'Human narrative summary'],
            ['group' => 'App', 'token' => 'console_url', 'desc' => 'Link into the PWA console'],
            ['group' => 'App', 'token' => 'now', 'desc' => 'Current timestamp'],
        ]]);
    }

    private function sampleVars(): array
    {
        return [
            'alert_code' => 'SAMPLE_0001', 'alert_title' => 'Sample · template preview',
            'alert_details' => 'This is a sample preview rendered for the template editor.',
            'risk_level' => 'HIGH', 'risk_level_label' => 'HIGH — public-health priority',
            'routed_to_level' => 'DISTRICT', 'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'alert_status' => 'OPEN', 'alert_created_at' => now()->format('Y-m-d H:i'),
            'alert_created_ago' => 'moments ago', 'ack_sla_hours' => 24,
            'ack_deadline' => now()->addHours(24)->format('Y-m-d H:i'),
            'country_code' => config('country.code'), 'country_name' => config('country.legacy_code'), 'district_code' => 'Chililabombwe District', 'poe_code' => 'Kasumbalesa',
            'traveler_name' => 'A. N.', 'traveler_age' => '34 y', 'traveler_gender' => 'FEMALE',
            'traveler_nationality' => 'DRC', 'traveler_occupation' => 'Cross-border trader',
            'disease_name' => 'Ebola Virus Disease (EVD)', 'disease_who_category' => 'IHR Annex 2',
            'disease_cfr_pct' => '50.0%', 'disease_incubation' => '2–21 days (typically 4–10)',
            'disease_transmission' => 'Body-fluid contact; nosocomial; funeral practices',
            'disease_ppe' => 'Full VHF PPE', 'disease_isolation' => 'Dedicated ETU',
            'disease_ihr_notification' => 'IMMEDIATE within 24h', 'disease_specimens' => 'EDTA blood, BSL-4 courier',
            'disease_case_definition' => 'Acute fever with unexplained bleeding + epidemiological link.',
            'console_url' => (string) config('app.url', 'http://localhost') . '/#/active-alerts',
            'now' => now()->format('Y-m-d H:i'), 'now_date' => now()->format('Y-m-d'),
            'vitals_html' => '<tr><td style="padding:6px 12px;">Temperature</td><td style="padding:6px 12px;text-align:right;">39.4 °C</td></tr>',
            'symptoms_html' => '<p style="margin:0;">Fever, fatigue, gum bleeding</p>',
            'exposures_html' => '<p style="margin:0;">Contact with suspected VHF case</p>',
            'travel_html' => '<p style="margin:0;">DRC → Zambia → Zimbabwe transit</p>',
            'suspected_html' => '<p style="margin:0;">#1 Ebola · #2 Marburg · #3 Severe malaria</p>',
            'samples_html' => '<p style="margin:0;">EDTA blood × 2 — UVRI</p>',
            'followups_html' => '<p style="margin:0;">CASE_INVESTIGATION due in 4h</p>',
            'disease_intel_html' => '<p style="margin:0;">Filovirus — full PPE required</p>',
            'immediate_actions_html' => '<ul><li>Isolate</li><li>Notify WHO</li></ul>',
            'recommended_tests_html' => '<ul><li>PCR at UVRI</li></ul>',
            'key_distinguishers_html' => '<ul><li>Haemorrhagic signs + recent VHF-area travel</li></ul>',
            'prior_alerts_html' => '<p style="margin:0;">No prior alerts at this POE.</p>',
            'followup_action_code' => 'CASE_INVESTIGATION', 'followup_action_label' => 'Case investigation started',
            'followup_due_at' => now()->addHours(4)->format('Y-m-d H:i'),
            'followup_overdue_hours' => '0', 'followup_assignee' => 'Dr. Ayebare · DISTRICT_SUPERVISOR',
        ];
    }

    private function ok(array $d = [], int $c = 200): JsonResponse { return response()->json(['ok' => true, 'data' => $d], $c); }
    private function err(string $m, int $c = 400): JsonResponse { return response()->json(['ok' => false, 'error' => $m], $c); }
    private function fail(Throwable $e, string $ctx): JsonResponse {
        Log::error("[Templates::{$ctx}] " . $e->getMessage());
        return response()->json(['ok' => false, 'error' => $e->getMessage(), 'ctx' => $ctx], 500);
    }
}
