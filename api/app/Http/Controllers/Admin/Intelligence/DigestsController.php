<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Intelligence;

use App\Http\Controllers\Controller;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin · Intelligence · Digest Builder (intel-digests)
 * ---------------------------------------------------------------------------
 * Operational console for the two scheduled digest flows plus a manual
 * trigger:
 *
 *   · DAILY_REPORT          — 07:00 Africa/Kampala · notifications:daily-digest
 *   · NATIONAL_INTELLIGENCE — 08:00 every 3 days · notifications:national-digest
 *
 * Surfaces cron history (notification_log.triggered_by LIKE 'CRON:%'),
 * template preview (subject + body rendered with today's live variables),
 * and a manual-trigger endpoint that invokes the dispatcher with
 * triggered_by='MANUAL:<user_id>' so audit trails remain disambiguated.
 *
 * Mobile contract: NONE.
 * Gate: NATIONAL_ADMIN only. Manual sends write to notification_log with
 * the full triggering user id logged.
 */
final class DigestsController extends Controller
{
    private const DIGESTS = [
        'DAILY_REPORT' => [
            'label'       => 'Daily national digest',
            'cadence'     => '07:00 daily (Africa/Kampala)',
            'dispatcher'  => 'sendDailyDigest',
            'vars'        => 'buildDailyDigestVars',
            'expected_h'  => 24,
        ],
        'NATIONAL_INTELLIGENCE' => [
            'label'       => 'National intelligence',
            'cadence'     => '08:00 every 3 days',
            'dispatcher'  => 'sendNationalIntelligenceDigest',
            'vars'        => 'buildNationalIntelligenceVars',
            'expected_h'  => 72,
        ],
    ];

    public function index(Request $request)
    {
        return view('admin.intelligence.digests.index', [
            'page_title'    => 'Digest Builder',
            'page_eyebrow'  => 'Intelligence',
            'page_subtitle' => 'Daily 07:00 + 3-day national 08:00 · preview · manual trigger · cron history.',
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $now = now();
            $days = max(1, min(60, (int) $request->query('days', 14)));
            $since = (clone $now)->subDays($days);

            $digests = [];
            foreach (self::DIGESTS as $code => $cfg) {
                $lastSent = null;
                $sentN = 0; $failedN = 0; $skippedN = 0;
                if (Schema::hasTable('notification_log')) {
                    $lastSent = DB::table('notification_log')
                        ->where('template_code', $code)->where('status', 'SENT')
                        ->orderByDesc('created_at')->value('created_at');
                    $sentN = (int) DB::table('notification_log')
                        ->where('template_code', $code)->where('status', 'SENT')
                        ->where('created_at', '>=', $since)->count();
                    $failedN = (int) DB::table('notification_log')
                        ->where('template_code', $code)->where('status', 'FAILED')
                        ->where('created_at', '>=', $since)->count();
                    $skippedN = (int) DB::table('notification_log')
                        ->where('template_code', $code)->where('status', 'SKIPPED')
                        ->where('created_at', '>=', $since)->count();
                }
                $hoursSince = $lastSent ? (int) \Carbon\Carbon::parse($lastSent)->diffInHours($now) : null;
                $digests[] = [
                    'template_code'   => $code,
                    'label'           => $cfg['label'],
                    'cadence'         => $cfg['cadence'],
                    'last_sent_at'    => $lastSent,
                    'hours_since'     => $hoursSince,
                    'expected_hours'  => $cfg['expected_h'],
                    'overdue'         => $hoursSince !== null && $hoursSince > $cfg['expected_h'] * 1.5,
                    'sent_window'     => $sentN,
                    'failed_window'   => $failedN,
                    'skipped_window'  => $skippedN,
                    'success_pct'     => ($sentN + $failedN) > 0
                        ? round(100 * $sentN / ($sentN + $failedN), 1) : 0.0,
                ];
            }

            // ── Recent CRON runs (every CRON:daily / CRON:national-intel row) ──
            $history = [];
            if (Schema::hasTable('notification_log')) {
                $history = DB::table('notification_log')
                    ->whereIn('template_code', array_keys(self::DIGESTS))
                    ->where('created_at', '>=', $since)
                    ->orderByDesc('id')->limit(100)
                    ->get(['id', 'template_code', 'status', 'triggered_by',
                           'country_code', 'poe_code', 'district_code',
                           'error_message', 'created_at'])
                    ->map(fn ($r) => [
                        'id'            => (int) $r->id,
                        'template_code' => (string) $r->template_code,
                        'status'        => (string) $r->status,
                        'triggered_by'  => (string) $r->triggered_by,
                        'scope'         => (string) ($r->poe_code ?: $r->district_code ?: $r->country_code ?: 'NATIONAL'),
                        'error_message' => (string) ($r->error_message ?? ''),
                        'created_at'    => (string) $r->created_at,
                    ])
                    ->all();
            }

            // Daily count series for trend chart (14d)
            $trend = [];
            if (Schema::hasTable('notification_log')) {
                $cursor = (clone $since)->startOfDay();
                for ($i = 0; $i < $days; $i++) {
                    $trend[$cursor->format('Y-m-d')] = [
                        'day' => $cursor->format('Y-m-d'),
                        'daily' => 0, 'national' => 0,
                    ];
                    $cursor->addDay();
                }
                $rs = DB::table('notification_log')
                    ->whereIn('template_code', array_keys(self::DIGESTS))
                    ->where('created_at', '>=', $since)
                    ->where('status', 'SENT')
                    ->selectRaw('DATE(created_at) AS d, template_code, COUNT(*) AS n')
                    ->groupBy('d', 'template_code')->get();
                foreach ($rs as $r) {
                    $k = (string) $r->d;
                    if (! isset($trend[$k])) continue;
                    if ($r->template_code === 'DAILY_REPORT')          $trend[$k]['daily']    = (int) $r->n;
                    if ($r->template_code === 'NATIONAL_INTELLIGENCE') $trend[$k]['national'] = (int) $r->n;
                }
            }

            return response()->json(['ok' => true, 'data' => [
                'server_time' => $now->toIso8601String(),
                'window_days' => $days,
                'digests'     => $digests,
                'history'     => $history,
                'trend'       => array_values($trend),
            ]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function preview(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'template_code' => ['required', 'string', 'in:DAILY_REPORT,NATIONAL_INTELLIGENCE'],
                'country_code'  => ['nullable', 'string', 'max:10'],
            ]);
            $code = (string) $validated['template_code'];
            $cc   = (string) ($validated['country_code'] ?? config('country.code'));

            // Render live variables via dispatcher public helpers.
            $varsFn = self::DIGESTS[$code]['vars'];
            $vars   = NotificationDispatcher::{$varsFn}($cc);

            $tpl = DB::table('notification_templates')->where('template_code', $code)->first();
            if (! $tpl) {
                return response()->json(['ok' => false, 'error' => 'template_not_found'], 404);
            }
            $subject = $this->renderMustache((string) $tpl->subject_template, $vars);
            $bodyH   = $this->renderMustache((string) $tpl->body_html_template, $vars);
            $bodyT   = $this->renderMustache((string) ($tpl->body_text_template ?? ''), $vars);

            return response()->json(['ok' => true, 'data' => [
                'template_code' => $code,
                'country_code'  => $cc,
                'subject'       => $subject,
                'body_html'     => $bodyH,
                'body_text'     => $bodyT,
                'variables'     => $vars,
            ]]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'validation', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function trigger(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'template_code' => ['required', 'string', 'in:DAILY_REPORT,NATIONAL_INTELLIGENCE'],
                'confirm'       => ['required', 'boolean', 'accepted'],
            ]);
            $code = (string) $validated['template_code'];
            $fn   = self::DIGESTS[$code]['dispatcher'];
            $user = $request->user();
            $triggeredBy = 'MANUAL:' . (int) $user->id;

            $result = NotificationDispatcher::{$fn}($triggeredBy);

            \App\Services\AuthEventLogger::log(
                'ADMIN_UPDATED', (int) $user->id, null, 'WARN',
                [
                    'entity'        => 'digest_manual_trigger',
                    'template_code' => $code,
                    'triggered_by'  => $triggeredBy,
                    'result'        => $result,
                ],
                5, $request,
            );

            return response()->json(['ok' => true, 'data' => $result]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'validation', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function renderMustache(string $template, array $vars): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            function ($m) use ($vars): string {
                $k = $m[1];
                if (! array_key_exists($k, $vars)) return '{{' . $k . '}}';
                $v = $vars[$k];
                if (is_scalar($v)) return (string) $v;
                return json_encode($v);
            },
            $template,
        ) ?? $template;
    }
}
