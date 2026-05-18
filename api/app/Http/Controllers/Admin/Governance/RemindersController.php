<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Governance;

use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Admin · Governance · Reminders & Retry (gov-reminders)
 * ---------------------------------------------------------------------------
 * Future-facing counterpart to gov-notif-log. Answers: *what will send /
 * is suppressed / will retry?*
 *
 *   · Upcoming FOLLOWUP_DUE / FOLLOWUP_OVERDUE from `alert_followups.due_at`
 *   · Currently-active suppression windows from `notification_suppressions`
 *   · Retry queue from `notification_log` (status=FAILED, retry_count < 4)
 *   · Per-contact freshness from `poe_notification_contacts.last_notified_at`
 *   · Per-template suppression-window dial (15 templates)
 *
 * Mobile contract: NONE. Everything here is server-side dispatcher state.
 * Gate: NATIONAL_ADMIN only (contact PII + alert references).
 *
 * Read-only; the retry-failed cron runs every 15 min and pulls from the
 * same notification_log retry queue — we expose its state, not its
 * trigger.
 */
final class RemindersController extends BaseGovernanceController
{
    protected function viewKey(): string
    {
        return 'reminders';
    }

    private const PAGE_MAX = 200;

    public function index(Request $request)
    {
        return view('admin.governance.reminders.index', [
            'page_title'    => 'Reminders & Retry',
            'page_eyebrow'  => 'Governance',
            'page_subtitle' => 'Scheduled per-alert reminders · suppression windows · retry-failed cron (q15m) · FOLLOWUP_DUE / FOLLOWUP_OVERDUE.',
            'coach'         => $this->coach(),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $now = now();

            // ── Followup pipeline ───────────────────────────────────────
            $fuPending = (int) DB::table('alert_followups')
                ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                ->whereNull('deleted_at')->count();
            $fuOverdue = (int) DB::table('alert_followups')
                ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                ->whereNotNull('due_at')->where('due_at', '<', $now)
                ->whereNull('deleted_at')->count();
            $fuDue24h = (int) DB::table('alert_followups')
                ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                ->whereNotNull('due_at')
                ->whereBetween('due_at', [$now, (clone $now)->addHours(24)])
                ->whereNull('deleted_at')->count();
            $fuBlocksClosure = (int) DB::table('alert_followups')
                ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                ->where('blocks_closure', 1)
                ->whereNull('deleted_at')->count();
            $fuCompleted7d = (int) DB::table('alert_followups')
                ->where('status', 'COMPLETED')
                ->where('completed_at', '>=', (clone $now)->subDays(7))->count();

            // ── Followup pressure by action_code (overdue + due<24h) ────
            $pressureRows = DB::table('alert_followups')
                ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                ->whereNotNull('due_at')
                ->whereNull('deleted_at')
                ->selectRaw('action_code, '
                    . 'SUM(CASE WHEN due_at < NOW() THEN 1 ELSE 0 END) AS overdue, '
                    . 'SUM(CASE WHEN due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS due_24h, '
                    . 'COUNT(*) AS total')
                ->groupBy('action_code')
                ->orderByDesc('overdue')->orderByDesc('due_24h')
                ->limit(12)->get()
                ->map(fn ($r) => [
                    'action_code' => (string) $r->action_code,
                    'overdue'     => (int) $r->overdue,
                    'due_24h'     => (int) $r->due_24h,
                    'total'       => (int) $r->total,
                ])
                ->all();

            // ── 24h-forward horizon: due_at hourly buckets ──────────────
            $horizonRows = DB::table('alert_followups')
                ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
                ->whereNotNull('due_at')
                ->whereBetween('due_at', [$now, (clone $now)->addHours(24)])
                ->whereNull('deleted_at')
                ->selectRaw("DATE_FORMAT(due_at, '%Y-%m-%d %H:00:00') AS h, "
                    . 'COUNT(*) AS n, '
                    . 'SUM(blocks_closure) AS blockers')
                ->groupBy('h')->get()->keyBy('h');
            $horizon = [];
            $cursor  = (clone $now)->startOfHour();
            for ($i = 0; $i < 24; $i++) {
                $key = $cursor->format('Y-m-d H:00:00');
                $row = $horizonRows[$key] ?? null;
                $horizon[] = [
                    'hour'     => $cursor->toIso8601String(),
                    'n'        => $row ? (int) $row->n        : 0,
                    'blockers' => $row ? (int) $row->blockers : 0,
                ];
                $cursor->addHour();
            }

            // ── Retry queue (notification_log, FAILED + retry_count < 4) ─
            $retryRows = DB::table('notification_log')
                ->where('status', 'FAILED')
                ->where('retry_count', '<', 4)
                ->selectRaw('retry_count, COUNT(*) AS n')
                ->groupBy('retry_count')->orderBy('retry_count')->get()
                ->mapWithKeys(fn ($r) => [(int) $r->retry_count => (int) $r->n])->all();
            $retryBuckets = [];
            for ($i = 0; $i < 4; $i++) {
                $retryBuckets[] = ['retry_count' => $i, 'n' => (int) ($retryRows[$i] ?? 0)];
            }
            $retryTotal = array_sum(array_column($retryBuckets, 'n'));
            $retryExhausted = (int) DB::table('notification_log')
                ->where('status', 'FAILED')->where('retry_count', '>=', 4)->count();
            $queuedDepth = (int) DB::table('notification_log')
                ->where('status', 'QUEUED')->count();

            // ── Suppression state (active windows right now) ────────────
            $suppressionMinutes = NotificationDispatcher::suppressionMinutesMap();
            $suppressRows = DB::table('notification_suppressions')
                ->selectRaw('template_code, COUNT(*) AS n, MAX(last_sent_at) AS most_recent')
                ->groupBy('template_code')
                ->get()
                ->map(fn ($r) => [
                    'template_code' => (string) $r->template_code,
                    'n'             => (int) $r->n,
                    'most_recent'   => (string) $r->most_recent,
                    'window_min'    => (int) ($suppressionMinutes[$r->template_code]
                        ?? NotificationDispatcher::defaultSuppressionMinutes()),
                ])
                ->sortByDesc('n')->values()->all();

            $templateWindows = [];
            foreach ($suppressionMinutes as $code => $min) {
                $templateWindows[] = ['template_code' => $code, 'window_min' => (int) $min];
            }
            usort($templateWindows, fn ($a, $b) => $b['window_min'] <=> $a['window_min']);

            // ── Contact freshness (per-level last_notified_at) ──────────
            $freshnessRows = DB::table('poe_notification_contacts')
                ->whereNull('deleted_at')->where('is_active', 1)
                ->selectRaw("level, COUNT(*) AS total, "
                    . "SUM(CASE WHEN last_notified_at IS NULL THEN 1 ELSE 0 END) AS never, "
                    . "SUM(CASE WHEN last_notified_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS fresh_24h, "
                    . "SUM(CASE WHEN last_notified_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND last_notified_at < DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS fresh_7d, "
                    . "SUM(CASE WHEN last_notified_at < DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS stale")
                ->groupBy('level')->get()
                ->map(fn ($r) => [
                    'level'     => (string) $r->level,
                    'total'     => (int) $r->total,
                    'never'     => (int) $r->never,
                    'fresh_24h' => (int) $r->fresh_24h,
                    'fresh_7d'  => (int) $r->fresh_7d,
                    'stale'     => (int) $r->stale,
                ])
                ->all();

            // Audit: summary aggregates do not surface PII directly.
            $this->auditView($request, [], ['row_count' => $retryTotal]);

            return response()->json(['ok' => true, 'data' => [
                'server_time' => $now->toIso8601String(),
                'followups' => [
                    'pending'         => $fuPending,
                    'overdue'         => $fuOverdue,
                    'due_24h'         => $fuDue24h,
                    'blocks_closure'  => $fuBlocksClosure,
                    'completed_7d'    => $fuCompleted7d,
                ],
                'pressure'         => $pressureRows,
                'horizon_24h'      => $horizon,
                'retry' => [
                    'buckets'    => $retryBuckets,
                    'total'      => $retryTotal,
                    'exhausted'  => $retryExhausted,
                    'queued'     => $queuedDepth,
                ],
                'suppressions'     => $suppressRows,
                'template_windows' => $templateWindows,
                'freshness'        => $freshnessRows,
            ]]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'summary');
        }
    }

    public function followups(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'q'           => ['nullable', 'string', 'max:120'],
                'status'      => ['nullable', 'string', 'in:ALL,PENDING,IN_PROGRESS,OVERDUE,DUE_24H,BLOCKERS'],
                'action_code' => ['nullable', 'string', 'max:60'],
                'poe_code'    => ['nullable', 'string', 'max:40'],
                'page'        => ['nullable', 'integer', 'min:1', 'max:10000'],
                'per_page'    => ['nullable', 'integer', 'min:10', 'max:' . self::PAGE_MAX],
            ]);

            $perPage = (int) ($validated['per_page'] ?? 50);
            $page    = (int) ($validated['page']     ?? 1);
            $status  = (string) ($validated['status'] ?? 'PENDING');

            $q = DB::table('alert_followups as af')
                ->leftJoin('alerts as a', 'a.id', '=', 'af.alert_id')
                ->whereNull('af.deleted_at')
                ->select([
                    'af.id', 'af.alert_id', 'af.action_code', 'af.action_label',
                    'af.status', 'af.due_at', 'af.assigned_to_role',
                    'af.assigned_to_user_id', 'af.blocks_closure',
                    'af.poe_code', 'af.district_code', 'af.country_code',
                    'af.notes', 'af.created_at',
                    DB::raw('a.alert_code AS alert_code'),
                    DB::raw('a.risk_level AS alert_risk'),
                    DB::raw('a.status AS alert_status'),
                ]);

            if ($status === 'OVERDUE') {
                $q->whereIn('af.status', ['PENDING', 'IN_PROGRESS'])
                  ->whereNotNull('af.due_at')->where('af.due_at', '<', now());
            } elseif ($status === 'DUE_24H') {
                $q->whereIn('af.status', ['PENDING', 'IN_PROGRESS'])
                  ->whereNotNull('af.due_at')
                  ->whereBetween('af.due_at', [now(), now()->addHours(24)]);
            } elseif ($status === 'BLOCKERS') {
                $q->whereIn('af.status', ['PENDING', 'IN_PROGRESS'])
                  ->where('af.blocks_closure', 1);
            } elseif ($status === 'ALL') {
                // no extra filter
            } else {
                // PENDING + IN_PROGRESS default
                $q->whereIn('af.status', ['PENDING', 'IN_PROGRESS']);
            }

            if (! empty($validated['action_code'])) { $q->where('af.action_code', $validated['action_code']); }
            if (! empty($validated['poe_code']))    { $q->where('af.poe_code',    $validated['poe_code']); }
            if (! empty($validated['q'])) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $validated['q']) . '%';
                $q->where(function ($w) use ($like): void {
                    $w->where('af.action_label', 'like', $like)
                      ->orWhere('af.notes',      'like', $like)
                      ->orWhere('a.alert_code',  'like', $like)
                      ->orWhere('af.poe_code',   'like', $like);
                });
            }

            $total = (clone $q)->count();
            $rows  = $q->orderByRaw('CASE WHEN af.due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('af.due_at')
                ->forPage($page, $perPage)->get()
                ->map(fn ($r) => [
                    'id'            => (int) $r->id,
                    'alert_id'      => $r->alert_id ? (int) $r->alert_id : null,
                    'alert_code'    => $r->alert_code,
                    'alert_risk'    => $r->alert_risk,
                    'alert_status'  => $r->alert_status,
                    'action_code'   => (string) $r->action_code,
                    'action_label'  => (string) $r->action_label,
                    'status'        => (string) $r->status,
                    'due_at'        => $r->due_at,
                    'overdue'       => $r->due_at && Carbon::parse($r->due_at)->lt(now()),
                    'assigned_to_role' => $r->assigned_to_role,
                    'blocks_closure' => (bool) $r->blocks_closure,
                    'poe_code'      => $r->poe_code,
                    'district_code' => $r->district_code,
                    'notes'         => $r->notes,
                ])
                ->all();

            // Audit: follow-ups expose recipient identity (assigned_to_role,
            // notes that may name people).
            $this->auditView($request, $validated, ['row_count' => count($rows)]);
            if (! empty($rows)) {
                $this->auditPiiReveal($request, $validated, count($rows), ['notes', 'assigned_to_role']);
            }

            return response()->json(['ok' => true, 'data' => [
                'rows' => $rows, 'page' => $page, 'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil(max($total, 1) / $perPage),
            ]]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'validation', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return $this->serverError($e, 'followups');
        }
    }

    public function retryQueue(Request $request): JsonResponse
    {
        try {
            $rows = DB::table('notification_log as nl')
                ->leftJoin('poe_notification_contacts as c', 'c.id', '=', 'nl.contact_id')
                ->where('nl.status', 'FAILED')
                ->where('nl.retry_count', '<', 4)
                ->select([
                    'nl.id', 'nl.template_code', 'nl.channel', 'nl.to_email', 'nl.to_phone',
                    'nl.subject', 'nl.error_message', 'nl.retry_count', 'nl.failed_at',
                    'nl.poe_code', 'nl.district_code',
                    'c.full_name as contact_name', 'c.level as contact_level',
                ])
                ->orderBy('nl.retry_count')
                ->orderByDesc('nl.failed_at')
                ->limit(200)->get()
                ->map(fn ($r) => [
                    'id'            => (int) $r->id,
                    'template_code' => (string) $r->template_code,
                    'channel'       => (string) $r->channel,
                    'recipient'     => $r->contact_name ?? $r->to_email ?? $r->to_phone,
                    'to_email'      => $r->to_email,
                    'to_phone'      => $r->to_phone,
                    'level'         => $r->contact_level,
                    'poe_code'      => $r->poe_code,
                    'district_code' => $r->district_code,
                    'subject'       => $r->subject,
                    'error_message' => $r->error_message,
                    'retry_count'   => (int) $r->retry_count,
                    'failed_at'     => $r->failed_at,
                ])
                ->all();

            // Audit: retry queue exposes recipient email + phone + subject.
            $this->auditView($request, [], ['row_count' => count($rows)]);
            if (! empty($rows)) {
                $this->auditPiiReveal($request, [], count($rows), ['to_email', 'to_phone', 'subject', 'error_message', 'contact_name']);
            }

            return response()->json(['ok' => true, 'data' => ['rows' => $rows]]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'retryQueue');
        }
    }

    public function suppressions(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'template_code' => ['nullable', 'string', 'max:60'],
            ]);
            $now   = now();
            $map   = NotificationDispatcher::suppressionMinutesMap();
            $defMin = NotificationDispatcher::defaultSuppressionMinutes();

            $q = DB::table('notification_suppressions as s')
                ->leftJoin('poe_notification_contacts as c', 'c.id', '=', 's.contact_id')
                ->select([
                    's.id', 's.template_code', 's.related_entity_type', 's.related_entity_id',
                    's.contact_id', 's.last_sent_at', 's.send_count',
                    'c.full_name as contact_name', 'c.level as contact_level', 'c.email as contact_email',
                ]);

            if (! empty($validated['template_code'])) {
                $q->where('s.template_code', $validated['template_code']);
            }

            $rows = $q->orderByDesc('s.last_sent_at')->limit(300)->get()
                ->map(function ($r) use ($now, $map, $defMin): array {
                    $windowMin = (int) ($map[$r->template_code] ?? $defMin);
                    $sent      = Carbon::parse($r->last_sent_at);
                    $expiresAt = (clone $sent)->addMinutes($windowMin);
                    $isActive  = $expiresAt->gt($now);
                    return [
                        'id'                 => (int) $r->id,
                        'template_code'      => (string) $r->template_code,
                        'related_entity_type'=> $r->related_entity_type,
                        'related_entity_id'  => $r->related_entity_id ? (int) $r->related_entity_id : null,
                        'contact_id'         => $r->contact_id ? (int) $r->contact_id : null,
                        'contact_name'       => $r->contact_name,
                        'contact_level'      => $r->contact_level,
                        'last_sent_at'       => (string) $r->last_sent_at,
                        'send_count'         => (int) $r->send_count,
                        'window_min'         => $windowMin,
                        'expires_at'         => $expiresAt->toIso8601String(),
                        'minutes_remaining'  => $isActive ? max(0, (int) $now->diffInMinutes($expiresAt, false)) : 0,
                        'is_active'          => $isActive,
                    ];
                })
                ->all();

            // Audit: suppressions name the contact (full_name, email).
            $this->auditView($request, $validated, ['row_count' => count($rows)]);
            if (! empty($rows)) {
                $this->auditPiiReveal($request, $validated, count($rows), ['contact_name', 'contact_email']);
            }

            // Currently active only vs full history — let client filter
            return response()->json(['ok' => true, 'data' => [
                'rows'        => $rows,
                'server_time' => $now->toIso8601String(),
            ]]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'validation', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return $this->serverError($e, 'suppressions');
        }
    }
}
