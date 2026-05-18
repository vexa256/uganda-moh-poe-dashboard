<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthEventLogger;
use App\Services\AuthMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * DashboardEmailVerificationController
 * ─────────────────────────────────────────────────────────────────────────
 * POST /auth/verify-email/send          — (authed) resend verification email
 * POST /auth/verify-email/send-for      — (unauthed) re-trigger by email address
 * POST /auth/verify-email/confirm       — confirm a signed token
 *
 * Uses our own email_verifications table with sha256 token hashes instead of
 * Laravel's signed-URL helper so we can show operators the full token lifecycle
 * in the audit view.
 *
 * Tokens:
 *   - 64 hex chars (32 bytes)
 *   - 24-hour TTL
 *   - single-use (used_at is stamped on confirm)
 */
final class DashboardEmailVerificationController extends Controller
{
    public function send(Request $r): JsonResponse
    {
        $u = $r->user();
        if ($u->email_verified_at) return response()->json(['ok' => true, 'message' => 'Already verified']);
        $this->issueAndSend((int) $u->id, (string) $u->email, $u, $r);
        return response()->json(['ok' => true, 'message' => 'Verification email dispatched']);
    }

    public function sendFor(Request $r): JsonResponse
    {
        $data = Validator::make($r->all(), ['email' => 'required|email'])->validate();
        // Generic response regardless of whether the account exists.
        $u = DB::table('users')->where('email', $data['email'])->first();
        if ($u && ! $u->email_verified_at) {
            $this->issueAndSend((int) $u->id, (string) $u->email, $u, $r);
        }
        return response()->json(['ok' => true, 'message' => 'If the email is registered and unverified, a link was sent.']);
    }

    public function confirm(Request $r): JsonResponse
    {
        $data = Validator::make($r->all(), ['token' => 'required|string|size:64'])->validate();
        $row = DB::table('email_verifications')
            ->where('token_hash', hash('sha256', $data['token']))
            ->where('purpose', 'VERIFY_EMAIL')
            ->first();
        if (! $row) return response()->json(['ok' => false, 'error' => 'Invalid or expired token'], 410);
        if ($row->used_at)                             return response()->json(['ok' => false, 'error' => 'Token already used'], 410);
        if (strtotime((string) $row->expires_at) < time()) return response()->json(['ok' => false, 'error' => 'Token expired'], 410);

        DB::table('email_verifications')->where('id', $row->id)->update(['used_at' => now()]);
        DB::table('users')->where('id', $row->user_id)->update([
            'email_verified_at' => now(), 'updated_at' => now(),
        ]);
        AuthEventLogger::log('EMAIL_VERIFIED', (int) $row->user_id, null, 'INFO', null, -3, $r);
        return response()->json(['ok' => true, 'message' => 'Email verified']);
    }

    private function issueAndSend(int $userId, string $email, object $user, Request $r): void
    {
        $token = bin2hex(random_bytes(32));
        DB::table('email_verifications')->insert([
            'user_id' => $userId, 'purpose' => 'VERIFY_EMAIL',
            'token_hash' => hash('sha256', $token), 'email' => $email,
            'ip' => $r->ip(), 'user_agent' => mb_substr((string) $r->userAgent(), 0, 300),
            'expires_at' => now()->addHours(24),
            'created_at' => now(),
        ]);
        AuthEventLogger::log('EMAIL_VERIFY_SENT', $userId, null, 'INFO', null, 0, $r);

        $url = rtrim((string) config('app.url', 'http://localhost'), '/') . '/verify-email?token=' . $token;
        AuthMailer::send('AUTH_VERIFY_EMAIL', $email, [
            'user_id'    => $userId,
            'full_name'  => $user->full_name ?? $user->name ?? 'there',
            'email'      => $email,
            'app_name'   => 'POE Sentinel',
            'verify_url' => $url,
            'expires_in' => '24 hours',
            'now'        => now()->format('Y-m-d H:i'),
        ]);
    }
}
