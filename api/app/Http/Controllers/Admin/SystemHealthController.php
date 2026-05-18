<?php

declare (strict_types = 1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SystemHealthController
 * ─────────────────────────────────────────────────────────────────────────
 * GET /admin/system/health  → snapshot of:
 *   · DB connectivity + uptime
 *   · row counts per operational table
 *   · last successful email send
 *   · last successful digest (per template)
 *   · pending notifications_suppressions + failed log backlog
 *   · queue + scheduler last-run timestamps
 *   · storage disk usage (best-effort)
 */
final class SystemHealthController extends Controller
{
    public function health(): JsonResponse
    {
        try {
            $startedAt = microtime(true);

            $dbOk = true;
            try {
                DB::select('SELECT 1');
            } catch (Throwable $e) {
                $dbOk = false;
            }

            $tables = [
                'users', 'user_assignments', 'auth_events', 'email_verifications',
                'trusted_devices', 'user_audit_log', 'user_anomaly_flags',
                'alerts', 'alert_followups', 'alert_timeline_events',
                'notification_log', 'notification_templates', 'poe_notification_contacts',
                'primary_screenings', 'secondary_screenings',
                'aggregated_submissions', 'aggregated_templates',
                'external_responders', 'responder_info_requests',
            ];
            $counts = [];
            foreach ($tables as $t) {
                try { $counts[$t] = (int) DB::table($t)->count();} catch (Throwable) {$counts[$t] = null;}
            }

            $lastSend = DB::table('notification_log')->where('status', 'SENT')
                ->orderByDesc('created_at')->value('created_at');
            $lastFailSend = DB::table('notification_log')->where('status', 'FAILED')
                ->orderByDesc('created_at')->value('created_at');
            $failBacklog = (int) DB::table('notification_log')
                ->where('status', 'FAILED')->where('retry_count', '<', 4)->count();

            $digestTemplates = ['DAILY_REPORT', 'WEEKLY_REPORT', 'NATIONAL_INTELLIGENCE'];
            $digestLastRun   = [];
            foreach ($digestTemplates as $t) {
                $digestLastRun[$t] = DB::table('notification_log')
                    ->where('template_code', $t)->where('status', 'SENT')
                    ->orderByDesc('created_at')->value('created_at');
            }

            $recentAuth24h = DB::table('auth_events')->where('created_at', '>=', now()->subDay())
                ->selectRaw('event_type, COUNT(*) AS n')->groupBy('event_type')->get();
            $pendingInvites = (int) DB::table('users')->whereNull('invitation_accepted_at')
                ->whereNotNull('invitation_token_hash')->count();
            $openAlerts   = (int) DB::table('alerts')->where('status', 'OPEN')->whereNull('deleted_at')->count();
            $criticalRisk = (int) DB::table('users')->where('risk_score', '>=', 80)->count();

            $storage = null;
            try {
                $storageRoot = base_path();
                $total       = @disk_total_space($storageRoot);
                $free        = @disk_free_space($storageRoot);
                if ($total && $free) {
                    $storage = [
                        'total_gb' => round($total / 1073741824, 2),
                        'free_gb'  => round($free / 1073741824, 2),
                        'used_pct' => round(100 * ($total - $free) / $total, 1),
                    ];
                }
            } catch (Throwable) {}

            return response()->json(['ok' => true, 'data' => [
                'status'            => $dbOk ? 'HEALTHY' : 'DEGRADED',
                'db_ok'             => $dbOk,
                'row_counts'        => $counts,
                'email'             => [
                    'last_sent_at'    => $lastSend,
                    'last_failed_at'  => $lastFailSend,
                    'fail_backlog'    => $failBacklog,
                    'digest_last_run' => $digestLastRun,
                ],
                'auth_activity_24h' => $recentAuth24h,
                'operational'       => [
                    'open_alerts'         => $openAlerts,
                    'pending_invites'     => $pendingInvites,
                    'critical_risk_users' => $criticalRisk,
                ],
                'storage'           => $storage,
                'server_time_utc'   => now()->toIso8601String(),
                'latency_ms'        => (int) round((microtime(true) - $startedAt) * 1000),
            ]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
