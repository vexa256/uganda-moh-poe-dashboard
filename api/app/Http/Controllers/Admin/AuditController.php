<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PheocScope;
use App\Support\EnumTranslator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * AuditController
 * ─────────────────────────────────────────────────────────────────────────
 * Unified read view over every audit surface:
 *   · auth_events               — every login / 2FA / lockout / MFA change
 *   · user_audit_log            — admin mutations (CREATE/UPDATE/SUSPEND/…)
 *   · alert_timeline_events     — war-room + response events
 *   · notification_log          — every email sent/skipped/failed
 *
 *   GET /admin/audit/feed      — merged chronological feed
 *   GET /admin/audit/auth
 *   GET /admin/audit/users
 *   GET /admin/audit/alerts
 *   GET /admin/audit/notifications
 *   GET /admin/audit/stats
 */
final class AuditController extends Controller
{
    /* ══════════════════════════════════════════════════════════════════
       Web / Blade · Admin Audit Trail view (sidebar #13 · Phase B)
       ══════════════════════════════════════════════════════════════════ */

    public function index(Request $request): View
    {
        $scope = $request->user()
            ? app(PheocScope::class)->forUser($request->user())
            : ['country_code' => config('country.code'), 'is_super' => true, 'label' => config('country.legacy_code') . ' · National (preview)'];
        $country = (string) ($scope['country_code'] ?? config('country.code'));
        $enum = app(EnumTranslator::class);

        $stats7 = [
            'auth_events'    => (int) DB::table('auth_events')->where('created_at', '>=', now()->subDays(7))->count(),
            'user_mutations' => (int) DB::table('user_audit_log')->where('created_at', '>=', now()->subDays(7))->count(),
            'alert_events'   => (int) DB::table('alert_timeline_events')->where('created_at', '>=', now()->subDays(7))->count(),
            'emails_sent'    => (int) DB::table('notification_log')->where('status', 'SENT')->where('created_at', '>=', now()->subDays(7))->count(),
        ];

        // Unified feed — merge recent auth, user-mutation, alert-timeline, notification rows.
        $unified = $this->buildUnified($country, 80, $enum);

        $authRows = DB::table('auth_events as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->orderByDesc('a.created_at')
            ->limit(60)
            ->get(['a.*', 'u.full_name', 'u.email'])
            ->map(fn ($r) => [
                'event_type'  => (string) $r->event_type,
                'event_label' => $enum->authEventType((string) $r->event_type),
                'severity'    => (string) ($r->severity ?? 'INFO'),
                'severity_tone' => $enum->severityTone((string) ($r->severity ?? 'INFO')),
                'user'        => (string) ($r->full_name ?? $r->email ?? '—'),
                'ip'          => (string) ($r->ip ?? ''),
                'created_rel' => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
            ])
            ->all();

        $userRows = DB::table('user_audit_log')
            ->leftJoin('users as actor',  'actor.id',  '=', 'user_audit_log.actor_user_id')
            ->leftJoin('users as target', 'target.id', '=', 'user_audit_log.target_user_id')
            ->orderByDesc('user_audit_log.created_at')
            ->limit(60)
            ->get([
                'user_audit_log.*',
                'actor.full_name as actor_name',
                'target.full_name as target_name',
            ])
            ->map(fn ($r) => [
                'action'      => (string) $r->action,
                'actor'       => (string) ($r->actor_name ?? '—'),
                'target'      => (string) ($r->target_name ?? '—'),
                'ip'          => (string) ($r->ip ?? ''),
                'created_rel' => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
            ])
            ->all();

        $alertRows = DB::table('alert_timeline_events as e')
            ->leftJoin('alerts as a', 'a.id', '=', 'e.alert_id')
            ->where('a.country_code', $country)
            ->whereNull('a.deleted_at')
            ->orderByDesc('e.created_at')
            ->limit(60)
            ->get(['e.*', 'a.alert_code', 'a.risk_level'])
            ->map(fn ($r) => [
                'alert_id'    => (int) $r->alert_id,
                'alert_code'  => (string) ($r->alert_code ?? ('#' . $r->alert_id)),
                'risk_level'  => (string) ($r->risk_level ?? ''),
                'event_code'  => (string) $r->event_code,
                'category'    => (string) $r->event_category,
                'actor'       => (string) ($r->actor_name ?? '—'),
                'severity'    => (string) $r->severity,
                'severity_tone' => $enum->severityTone((string) $r->severity),
                'summary'     => (string) ($r->summary ?? ''),
                'created_rel' => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
                'url'         => url('/admin/alerts/' . (int) $r->alert_id),
            ])
            ->all();

        $notifRows = DB::table('notification_log')
            ->where('country_code', $country)
            ->orderByDesc('created_at')
            ->limit(60)
            ->get()
            ->map(fn ($r) => [
                'template_code' => (string) $r->template_code,
                'template_name' => $enum->notificationTemplate((string) $r->template_code),
                'channel'       => (string) $r->channel,
                'to_email'      => (string) ($r->to_email ?? ''),
                'status_label'  => $enum->notificationStatus((string) $r->status),
                'status_tone'   => $enum->notificationStatusTone((string) $r->status),
                'created_rel'   => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
            ])
            ->all();

        $suppressRows = DB::table('notification_suppressions')
            ->orderByDesc('last_sent_at')
            ->limit(40)
            ->get()
            ->map(fn ($r) => [
                'template_code' => (string) $r->template_code,
                'template_name' => $enum->notificationTemplate((string) $r->template_code),
                'entity'        => ($r->related_entity_type ?? '') . ($r->related_entity_id ? ' #' . $r->related_entity_id : ''),
                'send_count'    => (int) ($r->send_count ?? 0),
                'last_sent_rel' => $r->last_sent_at ? Carbon::parse((string) $r->last_sent_at)->diffForHumans() : '—',
            ])
            ->all();

        return view('admin.audit.index', [
            'scope'        => $scope,
            'stats7'       => $stats7,
            'unified'      => $unified,
            'authRows'     => $authRows,
            'userRows'     => $userRows,
            'alertRows'    => $alertRows,
            'notifRows'    => $notifRows,
            'suppressRows' => $suppressRows,
        ]);
    }

    /** CSV download — unified feed · includes SHA-256 checksum line. */
    public function export(Request $request): StreamedResponse
    {
        $scope = $request->user()
            ? app(PheocScope::class)->forUser($request->user())
            : ['country_code' => config('country.code')];
        $country = (string) ($scope['country_code'] ?? config('country.code'));
        $enum = app(EnumTranslator::class);

        $rows = $this->buildUnified($country, 2000, $enum);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['timestamp', 'category', 'action', 'actor', 'target', 'severity', 'summary']);
            $hash = hash_init('sha256');
            foreach ($rows as $r) {
                $line = [$r['timestamp'], $r['category'], $r['action'], $r['actor'], $r['target'], $r['severity'], $r['summary']];
                fputcsv($out, $line);
                hash_update($hash, implode('|', $line) . "\n");
            }
            // Final line: checksum so receivers can verify integrity.
            fputcsv($out, ['# sha256', '', '', '', '', '', hash_final($hash)]);
            fclose($out);
        }, 'pheoc-audit-' . now()->format('Y-m-d-His') . '.csv', [
            'Content-Type' => 'text/csv; charset=utf-8',
        ]);
    }

    protected function buildUnified(string $country, int $limit, EnumTranslator $enum): array
    {
        $out = [];

        try {
            foreach (DB::table('alert_timeline_events as e')
                ->leftJoin('alerts as a', 'a.id', '=', 'e.alert_id')
                ->where('a.country_code', $country)
                ->whereNull('a.deleted_at')
                ->orderByDesc('e.created_at')
                ->limit((int) ceil($limit / 3))
                ->get(['e.*', 'a.alert_code']) as $r) {
                $out[] = [
                    'timestamp' => (string) $r->created_at,
                    'category'  => 'ALERT',
                    'action'    => (string) $r->event_code,
                    'actor'     => (string) ($r->actor_name ?? '—'),
                    'target'    => (string) ($r->alert_code ?? ('#' . $r->alert_id)),
                    'severity'  => (string) ($r->severity ?? 'INFO'),
                    'summary'   => (string) ($r->summary ?? ''),
                ];
            }
        } catch (Throwable) {}

        try {
            foreach (DB::table('auth_events as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                ->orderByDesc('a.created_at')
                ->limit((int) ceil($limit / 3))
                ->get(['a.*', 'u.full_name']) as $r) {
                $out[] = [
                    'timestamp' => (string) $r->created_at,
                    'category'  => 'AUTH',
                    'action'    => (string) $r->event_type,
                    'actor'     => (string) ($r->full_name ?? ('#' . ($r->user_id ?? '—'))),
                    'target'    => (string) ($r->ip ?? ''),
                    'severity'  => (string) ($r->severity ?? 'INFO'),
                    'summary'   => $enum->authEventType((string) $r->event_type),
                ];
            }
        } catch (Throwable) {}

        try {
            foreach (DB::table('user_audit_log')
                ->leftJoin('users as actor', 'actor.id', '=', 'user_audit_log.actor_user_id')
                ->leftJoin('users as target', 'target.id', '=', 'user_audit_log.target_user_id')
                ->orderByDesc('user_audit_log.created_at')
                ->limit((int) ceil($limit / 3))
                ->get([
                    'user_audit_log.*',
                    'actor.full_name as actor_name',
                    'target.full_name as target_name',
                ]) as $r) {
                $out[] = [
                    'timestamp' => (string) $r->created_at,
                    'category'  => 'USER',
                    'action'    => (string) $r->action,
                    'actor'     => (string) ($r->actor_name ?? '—'),
                    'target'    => (string) ($r->target_name ?? '—'),
                    'severity'  => 'INFO',
                    'summary'   => (string) ($r->ip ?? ''),
                ];
            }
        } catch (Throwable) {}

        usort($out, fn ($a, $b) => strcmp((string) $b['timestamp'], (string) $a['timestamp']));
        return array_slice($out, 0, $limit);
    }

    /* ══════════════════════════════════════════════════════════════════
       Legacy JSON API (existing /api/* surface, unchanged)
       ══════════════════════════════════════════════════════════════════ */

    public function feed(Request $r): JsonResponse
    {
        try {
            $limit = min(500, max(10, (int) $r->query('limit', 100)));
            $since = $r->query('since');

            $auth = DB::table('auth_events as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
                ->select(
                    DB::raw("'AUTH' as source"),
                    'a.id','a.event_type as event','a.severity',
                    'a.user_id','u.full_name','u.email',
                    'a.ip','a.user_agent','a.payload_json','a.created_at',
                );
            if ($since) $auth->where('a.created_at', '>', $since);

            $users = DB::table('user_audit_log as l')
                ->leftJoin('users as u', 'u.id', '=', 'l.target_user_id')
                ->select(
                    DB::raw("'USER_MUTATION' as source"),
                    'l.id','l.action as event',
                    DB::raw("'INFO' as severity"),
                    'l.target_user_id as user_id','u.full_name','u.email',
                    'l.ip','l.user_agent',
                    DB::raw("JSON_OBJECT('actor_user_id',l.actor_user_id,'before',l.before_json,'after',l.after_json) as payload_json"),
                    'l.created_at',
                );
            if ($since) $users->where('l.created_at', '>', $since);

            $merged = $auth->unionAll($users);
            $rows = DB::query()->fromSub($merged, 'x')
                ->orderByDesc('created_at')->limit($limit)->get();
            return response()->json(['ok' => true, 'data' => ['events' => $rows, 'count' => $rows->count()]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function auth(Request $r): JsonResponse
    {
        $q = DB::table('auth_events');
        foreach (['event_type','severity','user_id'] as $f) if ($v = $r->query($f)) $q->where($f, $v);
        if ($r->query('since')) $q->where('created_at', '>', $r->query('since'));
        return response()->json(['ok' => true, 'data' => ['events' => $q->orderByDesc('created_at')
            ->limit((int) $r->query('limit', 200))->get()]]);
    }

    public function users(Request $r): JsonResponse
    {
        $q = DB::table('user_audit_log');
        foreach (['actor_user_id','target_user_id','action'] as $f) if ($v = $r->query($f)) $q->where($f, $v);
        return response()->json(['ok' => true, 'data' => ['events' => $q->orderByDesc('created_at')
            ->limit((int) $r->query('limit', 200))->get()]]);
    }

    public function alerts(Request $r): JsonResponse
    {
        $q = DB::table('alert_timeline_events');
        foreach (['event_category','event_code','alert_id'] as $f) if ($v = $r->query($f)) $q->where($f, $v);
        return response()->json(['ok' => true, 'data' => ['events' => $q->orderByDesc('created_at')
            ->limit((int) $r->query('limit', 200))->get()]]);
    }

    public function notifications(Request $r): JsonResponse
    {
        $q = DB::table('notification_log');
        foreach (['template_code','status','to_email'] as $f) if ($v = $r->query($f)) $q->where($f, $v);
        return response()->json(['ok' => true, 'data' => ['events' => $q->orderByDesc('created_at')
            ->limit((int) $r->query('limit', 200))->get()]]);
    }

    public function stats(): JsonResponse
    {
        try {
            $since = now()->subDays(7);
            return response()->json(['ok' => true, 'data' => [
                'last_7d' => [
                    'auth_events' => DB::table('auth_events')->where('created_at','>=',$since)->count(),
                    'login_ok'    => DB::table('auth_events')->where('event_type','LOGIN_OK')->where('created_at','>=',$since)->count(),
                    'login_fail'  => DB::table('auth_events')->where('event_type','LOGIN_FAIL')->where('created_at','>=',$since)->count(),
                    'lockouts'    => DB::table('auth_events')->where('event_type','LOCKED')->where('created_at','>=',$since)->count(),
                    'user_mutations' => DB::table('user_audit_log')->where('created_at','>=',$since)->count(),
                    'alert_events'   => DB::table('alert_timeline_events')->where('created_at','>=',$since)->count(),
                    'emails_sent' => DB::table('notification_log')->where('status','SENT')->where('created_at','>=',$since)->count(),
                ],
                'by_event_7d' => DB::table('auth_events')->where('created_at','>=',$since)
                    ->selectRaw('event_type, COUNT(*) AS n')->groupBy('event_type')->orderByDesc('n')->get(),
                'by_severity_7d' => DB::table('auth_events')->where('created_at','>=',$since)
                    ->selectRaw('severity, COUNT(*) AS n')->groupBy('severity')->get(),
            ]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
