<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DigestsController
 * ─────────────────────────────────────────────────────────────────────────
 * Admin-facing endpoints that mirror the scheduled digest cron jobs. Used
 * for preview (without sending) and manual trigger.
 *
 *   GET  /digests/daily/preview              Render daily digest vars for a country
 *   GET  /digests/national/preview           Render national intelligence vars
 *   POST /digests/daily/send                 Manually trigger daily fan-out
 *   POST /digests/national/send              Manually trigger national-intel fan-out
 *   POST /digests/followups/send             Manually trigger followup reminders
 *   POST /digests/retry-failed               Retry FAILED notification_log rows
 *   GET  /digests/history                    Recent digest send history
 */
final class DigestsController extends Controller
{
    public function previewDaily(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            return $this->ok(NotificationDispatcher::buildDailyDigestVars($cc));
        } catch (Throwable $e) { return $this->fail($e, 'previewDaily'); }
    }

    public function previewNational(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            return $this->ok(NotificationDispatcher::buildNationalIntelligenceVars($cc));
        } catch (Throwable $e) { return $this->fail($e, 'previewNational'); }
    }

    public function sendDaily(Request $r): JsonResponse
    {
        try {
            $res = NotificationDispatcher::sendDailyDigest('ADMIN:' . ((int) $r->header('X-User-Id', 0)));
            return $this->ok($res);
        } catch (Throwable $e) { return $this->fail($e, 'sendDaily'); }
    }

    public function sendNational(Request $r): JsonResponse
    {
        try {
            $res = NotificationDispatcher::sendNationalIntelligenceDigest('ADMIN:' . ((int) $r->header('X-User-Id', 0)));
            return $this->ok($res);
        } catch (Throwable $e) { return $this->fail($e, 'sendNational'); }
    }

    public function sendFollowups(Request $r): JsonResponse
    {
        try {
            $res = NotificationDispatcher::sendFollowupReminders('ADMIN:' . ((int) $r->header('X-User-Id', 0)));
            return $this->ok($res);
        } catch (Throwable $e) { return $this->fail($e, 'sendFollowups'); }
    }

    public function retryFailed(Request $r): JsonResponse
    {
        try {
            $res = NotificationDispatcher::retryFailed('ADMIN:' . ((int) $r->header('X-User-Id', 0)));
            return $this->ok($res);
        } catch (Throwable $e) { return $this->fail($e, 'retryFailed'); }
    }

    public function history(Request $r): JsonResponse
    {
        try {
            $tpls = ['DAILY_REPORT', 'WEEKLY_REPORT', 'NATIONAL_INTELLIGENCE', 'FOLLOWUP_DUE', 'FOLLOWUP_OVERDUE'];
            $rows = DB::table('notification_log')
                ->whereIn('template_code', $tpls)
                ->selectRaw('template_code, status, DATE(created_at) AS d, COUNT(*) AS n')
                ->groupBy('template_code', 'status', 'd')
                ->orderByDesc('d')->limit(200)->get();
            return $this->ok(['history' => $rows]);
        } catch (Throwable $e) { return $this->fail($e, 'history'); }
    }

    private function ok(array $d = [], int $c = 200): JsonResponse { return response()->json(['ok' => true, 'data' => $d], $c); }
    private function fail(Throwable $e, string $ctx): JsonResponse {
        Log::error("[Digests::{$ctx}] " . $e->getMessage());
        return response()->json(['ok' => false, 'error' => $e->getMessage(), 'ctx' => $ctx], 500);
    }
}
