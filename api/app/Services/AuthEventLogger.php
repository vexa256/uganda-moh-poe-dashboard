<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * AuthEventLogger
 * ─────────────────────────────────────────────────────────────────────────
 * Fire-and-forget append to auth_events. Never throws — a logging failure
 * must never block a login or a security-sensitive mutation. Every auth
 * controller calls this on each branch so the audit trail is complete.
 */
final class AuthEventLogger
{
    public static function log(
        string $eventType,
        ?int $userId = null,
        ?string $emailAttempted = null,
        string $severity = 'INFO',
        ?array $payload = null,
        int $riskDelta = 0,
        ?Request $request = null,
    ): void {
        try {
            DB::table('auth_events')->insert([
                'user_id'         => $userId,
                'email_attempted' => $emailAttempted,
                'event_type'      => $eventType,
                'severity'        => $severity,
                'ip'              => $request?->ip(),
                'user_agent'      => mb_substr((string) $request?->userAgent(), 0, 300),
                'payload_json'    => $payload ? json_encode($payload) : null,
                'risk_delta'      => $riskDelta,
                'created_at'      => now(),
            ]);
        } catch (Throwable $e) {
            // swallow — we never want logging to fail the request
        }
    }
}
