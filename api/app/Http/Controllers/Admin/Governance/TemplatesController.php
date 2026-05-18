<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Governance;

use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Admin · Governance · Notification Templates (gov-templates)
 * ---------------------------------------------------------------------------
 * Read-mostly inventory of the 15 mustache-variable email templates seeded
 * by the notification pipeline. Pairs each template with its suppression
 * window (from NotificationDispatcher) and real send stats (from
 * notification_log).
 *
 * `is_ai_enhanced` is currently inert — surfaced so operators can see which
 * templates will opt in when the AI body enrichment lands (§B.5).
 *
 * Mobile contract: NONE. Templates are rendered by the server-side
 * dispatcher; mobile never reads notification_templates.
 *
 * Gate: NATIONAL_ADMIN only (templates include PII-shaped variables).
 *
 * Writes: toggle is_active only (safe, idempotent). Body / subject edits
 * are deliberately not surfaced on the v1 admin panel — those templates
 * are part of the calibrated WHO-aligned response and must not drift.
 */
final class TemplatesController extends BaseGovernanceController
{
    private const SAMPLE_VARS = [
        'alert_title'         => 'Suspected VHF cluster at Chirundu',
        'alert_code'          => 'ALT-2026-04-24-0019',
        'alert_details'       => '3 travellers arriving from Kasumbalesa with fever, haemorrhagic signs. Isolation in progress.',
        'poe_code'            => 'ZM-LUS-LUSAKA-KKIA-001',
        'district_code'       => 'ZM-LUS-LUSAKA',
        'risk_level'          => 'CRITICAL',
        'routed_to_level'     => 'NATIONAL',
        'escalate_to_level'   => 'WHO',
        'ihr_tier'            => 'TIER_1',
        'ack_hours'           => '1',
        'annex2_yes'          => '3',
        'bottleneck_phase'    => 'NOTIFY',
        'elapsed_hours'       => '26',
        'target_hours'        => '24',
        'action_label'        => 'Notify WHO IHR Focal Point',
        'due_in_hours'        => '4',
        'due_at'              => '2026-04-24 18:00:00',
        'report_date'         => '2026-04-24',
        'open_alerts'         => '7',
        'critical_alerts'     => '2',
        'breach_alerts'       => '1',
        'screened_today'      => '843',
        'symptomatic_today'   => '11',
        'week_number'         => '17',
        'escalation_reason'   => 'Unresolved after 24h; cross-border dimension.',
        'close_reason'        => 'No evidence of ongoing transmission after 21 days of follow-up.',
        'close_reason_short'  => 'Ruled out',
        'closed_by_name'      => 'Dr. Mwansa',
        'closed_at'           => '2026-04-24 14:30:00',
    ];

    protected function viewKey(): string
    {
        return 'templates';
    }

    public function index(Request $request)
    {
        return view('admin.governance.templates.index', [
            'page_title'    => 'Notif Templates',
            'page_eyebrow'  => 'Governance',
            'page_subtitle' => '15 templates · Mustache vars · preview · suppression windows · is_ai_enhanced (currently inert).',
            'coach'         => $this->coach(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        try {
            $windows = NotificationDispatcher::suppressionMinutesMap();
            $default = NotificationDispatcher::defaultSuppressionMinutes();
            $since   = now()->subDays(30);

            $usage = DB::table('notification_log')
                ->where('created_at', '>=', $since)
                ->selectRaw('template_code, '
                    . 'COUNT(*) AS n, '
                    . 'SUM(CASE WHEN status="SENT"    THEN 1 ELSE 0 END) AS sent_n, '
                    . 'SUM(CASE WHEN status="FAILED"  THEN 1 ELSE 0 END) AS failed_n, '
                    . 'SUM(CASE WHEN status="SKIPPED" THEN 1 ELSE 0 END) AS skipped_n, '
                    . 'MAX(created_at) AS last_used')
                ->groupBy('template_code')->get()->keyBy('template_code');

            $rows = DB::table('notification_templates')
                ->orderBy('template_code')->get()
                ->map(function ($r) use ($windows, $default, $usage) {
                    $u = $usage[$r->template_code] ?? null;
                    $levels = $this->decodeJson((string) ($r->applicable_levels ?? ''));
                    return [
                        'id'                 => (int) $r->id,
                        'template_code'      => (string) $r->template_code,
                        'channel'            => (string) $r->channel,
                        'subject_template'   => (string) $r->subject_template,
                        'body_html_template' => (string) $r->body_html_template,
                        'body_text_template' => (string) ($r->body_text_template ?? ''),
                        'applicable_levels'  => is_array($levels) ? $levels : [],
                        'is_ai_enhanced'     => (bool) $r->is_ai_enhanced,
                        'is_active'          => (bool) $r->is_active,
                        'vars'               => $this->extractVars((string) $r->subject_template . ' ' . (string) $r->body_html_template),
                        'suppression_min'    => (int) ($windows[$r->template_code] ?? $default),
                        'last_used'          => $u ? (string) $u->last_used : null,
                        'usage_30d'          => $u ? (int) $u->n       : 0,
                        'sent_30d'           => $u ? (int) $u->sent_n  : 0,
                        'failed_30d'         => $u ? (int) $u->failed_n: 0,
                        'skipped_30d'        => $u ? (int) $u->skipped_n : 0,
                        'success_pct'        => $u && ($u->sent_n + $u->failed_n) > 0
                            ? round(100 * $u->sent_n / ($u->sent_n + $u->failed_n), 1) : 0.0,
                        'subject_len'        => mb_strlen((string) $r->subject_template),
                        'body_len'           => mb_strlen((string) $r->body_html_template),
                    ];
                })->values()->all();

            $byActive = ['active' => 0, 'inactive' => 0];
            $byAi     = ['ai' => 0, 'plain' => 0];
            foreach ($rows as $row) {
                $byActive[$row['is_active'] ? 'active'   : 'inactive']++;
                $byAi[    $row['is_ai_enhanced'] ? 'ai'  : 'plain']++;
            }

            // Audit: template body and subject are not strictly PII but
            // they reveal calibrated WHO-aligned wording and are a regulated
            // surface; record the view explicitly.
            $this->auditView($request, [], ['row_count' => count($rows)]);

            return response()->json(['ok' => true, 'data' => [
                'rows'      => $rows,
                'by_active' => $byActive,
                'by_ai'     => $byAi,
            ]]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'data');
        }
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        try {
            $row = DB::table('notification_templates')->where('id', $id)->first();
            if (! $row) {
                return response()->json(['ok' => false, 'error' => 'not_found'], 404);
            }

            $userVars = (array) $request->input('vars', []);
            $merged = array_merge(self::SAMPLE_VARS, array_filter($userVars, 'is_scalar'));

            $subject = $this->renderMustache((string) $row->subject_template, $merged);
            $bodyH   = $this->renderMustache((string) $row->body_html_template, $merged);
            $bodyT   = $this->renderMustache((string) ($row->body_text_template ?? ''), $merged);

            // Audit: preview reveals the rendered template body (which can
            // resemble a real message containing names of operational
            // entities). Record the view.
            $this->auditView($request, ['template_id' => $id], ['row_count' => 1]);

            return response()->json(['ok' => true, 'data' => [
                'subject'     => $subject,
                'body_html'   => $bodyH,
                'body_text'   => $bodyT,
                'used_vars'   => $merged,
                'resolved_vars' => $this->extractVars((string) $row->subject_template . ' ' . (string) $row->body_html_template),
            ]]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'preview');
        }
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'is_active' => ['required', 'boolean'],
            ]);

            $row = DB::table('notification_templates')->where('id', $id)->first();
            if (! $row) {
                return response()->json(['ok' => false, 'error' => 'not_found'], 404);
            }

            $updated = DB::table('notification_templates')->where('id', $id)->update([
                'is_active'  => $validated['is_active'] ? 1 : 0,
                'updated_at' => now(),
            ]);

            if ($updated) {
                \App\Services\AuthEventLogger::log(
                    'ADMIN_UPDATED', (int) $request->user()->id, null, 'INFO',
                    ['entity' => 'notification_template', 'id' => $id,
                     'template_code' => (string) $row->template_code,
                     'is_active' => (bool) $validated['is_active']],
                    0, $request,
                );
            }

            // Audit: the toggle is a write event; record it in the
            // access-audit log alongside the AuthEventLogger entry above.
            $this->auditView($request, ['template_id' => $id, 'is_active' => (bool) $validated['is_active']], ['row_count' => (int) $updated]);

            return response()->json(['ok' => true, 'data' => ['updated' => (int) $updated]]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'validation', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return $this->serverError($e, 'toggle');
        }
    }

    private function renderMustache(string $template, array $vars): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            function ($m) use ($vars): string {
                return isset($vars[$m[1]]) ? (string) $vars[$m[1]] : '{{' . $m[1] . '}}';
            },
            $template,
        ) ?? $template;
    }

    private function extractVars(string $text): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $text, $m);
        return array_values(array_unique($m[1]));
    }

    private function decodeJson(?string $raw): mixed
    {
        if ($raw === null || $raw === '') return null;
        try { return json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return null; }
    }
}
