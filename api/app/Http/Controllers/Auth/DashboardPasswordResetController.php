<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthEventLogger;
use App\Services\AuthMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * DashboardPasswordResetController
 * ─────────────────────────────────────────────────────────────────────────
 * POST /auth/password/forgot           Body: { email }
 * POST /auth/password/reset            Body: { token, email, password, password_confirmation }
 *
 * Single-use 64-hex token stored sha256-hashed, 60-minute TTL, generic
 * responses (no email enumeration).
 */
final class DashboardPasswordResetController extends Controller
{
    public function forgot(Request $r): JsonResponse
    {
        $data = Validator::make($r->all(), ['email' => 'required|email'])->validate();
        $u = DB::table('users')->where('email', $data['email'])->where('is_active', 1)->first();
        if ($u) {
            $token = bin2hex(random_bytes(32));
            DB::table('email_verifications')->insert([
                'user_id' => $u->id, 'purpose' => 'RESET_PASSWORD',
                'token_hash' => hash('sha256', $token),
                'email' => (string) $u->email,
                'ip' => $r->ip(), 'user_agent' => mb_substr((string) $r->userAgent(), 0, 300),
                'expires_at' => now()->addMinutes(60),
                'created_at' => now(),
            ]);
            $url = rtrim((string) config('app.url', 'http://localhost'), '/')
                 . '/reset-password?token=' . $token . '&email=' . urlencode((string) $u->email);
            AuthMailer::send('AUTH_PASSWORD_RESET', (string) $u->email, [
                'user_id' => (int) $u->id,
                'full_name' => $u->full_name ?? 'there',
                'email' => $u->email,
                'reset_url' => $url,
                'expires_in' => '60 minutes',
                'ip' => $r->ip(),
                'app_name' => 'POE Sentinel',
                'now' => now()->format('Y-m-d H:i'),
            ]);
            AuthEventLogger::log('PASSWORD_RESET_REQUESTED', (int) $u->id, (string) $u->email, 'INFO', null, 2, $r);
        } else {
            AuthEventLogger::log('PASSWORD_RESET_REQUESTED', null, $data['email'], 'WARN',
                ['reason' => 'unknown_email'], 1, $r);
        }
        return response()->json(['ok' => true, 'message' => 'If the email exists, a reset link has been sent.']);
    }

    public function reset(Request $r): JsonResponse
    {
        $data = Validator::make($r->all(), [
            'token'                 => 'required|string|size:64',
            'email'                 => 'required|email',
            'password'              => 'required|string|min:12|confirmed',
        ])->validate();

        $row = DB::table('email_verifications')
            ->where('purpose', 'RESET_PASSWORD')
            ->where('token_hash', hash('sha256', $data['token']))
            ->where('email', $data['email'])
            ->first();
        if (! $row)                                     return response()->json(['ok' => false, 'error' => 'Invalid or expired token'], 410);
        if ($row->used_at)                              return response()->json(['ok' => false, 'error' => 'Token already used'], 410);
        if (strtotime((string) $row->expires_at) < time()) return response()->json(['ok' => false, 'error' => 'Token expired'], 410);

        // Password policy: min 12, ≥3 character classes.
        if (! $this->passwordMeetsPolicy($data['password'])) {
            return response()->json(['ok' => false, 'error' => 'Password must be ≥12 chars with letters, numbers and a symbol.'], 422);
        }

        $hash = Hash::make($data['password']);
        DB::table('users')->where('id', $row->user_id)->update([
            'password' => $hash, 'password_hash' => $hash,
            'password_changed_at' => now(),
            'must_change_password' => 0,
            'failed_login_count' => 0,
            'locked_until' => null,
            'updated_at' => now(),
        ]);
        DB::table('email_verifications')->where('id', $row->id)->update(['used_at' => now()]);

        // Revoke all active tokens for this user — force re-login everywhere.
        DB::table('personal_access_tokens')->where('tokenable_id', $row->user_id)->delete();

        AuthEventLogger::log('PASSWORD_RESET_USED', (int) $row->user_id, $row->email, 'INFO', null, -5, $r);
        AuthMailer::send('AUTH_PASSWORD_CHANGED', (string) $row->email, [
            'user_id' => (int) $row->user_id,
            'email' => $row->email,
            'app_name' => 'POE Sentinel',
            'ip' => $r->ip(), 'now' => now()->format('Y-m-d H:i'),
            'full_name' => '',
        ]);

        return response()->json(['ok' => true, 'message' => 'Password reset. Please sign in again.']);
    }

    private function passwordMeetsPolicy(string $pw): bool
    {
        if (mb_strlen($pw) < 12) return false;
        $c = 0;
        if (preg_match('/[a-z]/', $pw)) $c++;
        if (preg_match('/[A-Z]/', $pw)) $c++;
        if (preg_match('/\d/', $pw))    $c++;
        if (preg_match('/[^a-zA-Z0-9]/', $pw)) $c++;
        return $c >= 3;
    }
}
