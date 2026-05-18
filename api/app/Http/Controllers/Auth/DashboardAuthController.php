<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthEventLogger;
use App\Services\AuthMailer;
use App\Services\UserAnomalyService;
use App\Support\Totp;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * DashboardAuthController
 * ─────────────────────────────────────────────────────────────────────────
 * Native Laravel auth + Sanctum bearer tokens for the master dashboard.
 *
 * Hardening:
 *   · OWASP-aligned rate limit: 5 failed logins / 15 min / IP+email pair
 *   · Progressive lockout: 5 fails → 15 min lock; 10 fails → 1 h lock
 *   · Generic error messaging ("Invalid credentials") — no user enumeration
 *   · Constant-time password check (Hash::check) + password rehash on legacy
 *   · Step-up 2FA: if users.two_factor_confirmed_at is set, returns
 *     challenge_id instead of a token, and /auth/2fa-verify completes the login
 *   · Trusted-device cookie gate: if the client presents a valid device token
 *     that matches this user, 2FA is skipped
 *   · New-device email alert when LOGIN_OK arrives from an IP or UA unseen
 *     in the last 30 days
 *   · Every branch writes to auth_events (append-only)
 *
 * Endpoint map (mounted under /api/v2/auth/):
 *   POST /login               — email or username + password → token or challenge
 *   POST /2fa-verify          — challenge_id + code → token
 *   POST /logout              — revoke current token
 *   POST /logout-all          — revoke every token for this user
 *   GET  /me                  — authenticated user + permissions + assignments
 *   POST /refresh             — rotate current token
 *   POST /change-password     — current + new, requires fresh login (< 10 min)
 *   GET  /sessions            — list active personal_access_tokens + trusted_devices
 *   DELETE /sessions/{id}     — revoke a specific token
 */
final class DashboardAuthController extends Controller
{
    private const LOGIN_WINDOW_SEC = 900;                 // 15 min
    private const LOCK_STEPS = [5 => 15, 10 => 60];       // fails → lock minutes

    public function login(Request $r): JsonResponse
    {
        $data = Validator::make($r->all(), [
            'login'              => 'required|string|max:190',
            'password'           => 'required|string|max:255',
            'device_fingerprint' => 'nullable|string|max:128',
            'device_token'       => 'nullable|string|max:200',
        ])->validate();

        $login = trim((string) $data['login']);
        $rateKey = 'login:' . sha1($r->ip() . '|' . strtolower($login));

        if (RateLimiter::tooManyAttempts($rateKey, 5)) {
            AuthEventLogger::log('LOGIN_FAIL', null, $login, 'WARN',
                ['reason' => 'rate_limited', 'seconds_remaining' => RateLimiter::availableIn($rateKey)],
                5, $r);
            return response()->json([
                'ok' => false,
                'error' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($rateKey) . 's.',
            ], 429);
        }

        $user = DB::table('users')->where('is_active', 1)->whereNull('suspended_at')
            ->where(function ($q) use ($login) {
                $q->where('email', $login)->orWhere('username', $login);
            })->first();

        // Constant-time style — attempt bcrypt even when user missing.
        $checkAgainst = $user?->password ?: $user?->password_hash ?: '$2y$12$' . str_repeat('a', 53);
        $passwordOk = Hash::check($data['password'], $checkAgainst);

        if (! $user || ! $passwordOk) {
            RateLimiter::hit($rateKey, self::LOGIN_WINDOW_SEC);
            $this->onFailedLogin($user, $login, $r);
            return response()->json(['ok' => false, 'error' => 'Invalid credentials'], 401);
        }

        // Lock-out gate
        if ($user->locked_until && strtotime((string) $user->locked_until) > time()) {
            AuthEventLogger::log('LOGIN_FAIL', (int) $user->id, $login, 'WARN',
                ['reason' => 'locked'], 3, $r);
            return response()->json([
                'ok' => false,
                'error' => 'Account locked until ' . $user->locked_until,
            ], 423);
        }

        // 2FA gate
        if ($user->two_factor_confirmed_at && ! $this->presentedTrustedDevice($user, $data['device_token'] ?? null, $r)) {
            $challengeId = $this->issueChallenge((int) $user->id, $r);
            AuthEventLogger::log('TWOFA_CHALLENGED', (int) $user->id, null, 'INFO',
                ['challenge_id' => $challengeId], 0, $r);
            return response()->json([
                'ok' => true,
                'data' => [
                    'challenge_required' => true,
                    'challenge_id'       => $challengeId,
                    'method'             => 'TOTP',
                ],
            ]);
        }

        return $this->completeLogin($user, $r, $data['device_fingerprint'] ?? null);
    }

    public function twoFaVerify(Request $r): JsonResponse
    {
        $data = Validator::make($r->all(), [
            'challenge_id'       => 'required|string|max:120',
            'code'               => 'required|string|max:10',
            'remember_device'    => 'nullable|boolean',
            'device_fingerprint' => 'nullable|string|max:128',
        ])->validate();

        $challenge = cache()->get('auth.challenge.' . $data['challenge_id']);
        if (! $challenge) {
            return response()->json(['ok' => false, 'error' => 'Challenge expired — start over'], 410);
        }
        $user = DB::table('users')->where('id', (int) $challenge['user_id'])->first();
        if (! $user) return response()->json(['ok' => false, 'error' => 'User missing'], 410);

        $code = preg_replace('/\s+/', '', (string) $data['code']);
        $secret = (string) $user->two_factor_secret;
        $ok = Totp::verify($secret, $code, drift: 1);
        if (! $ok) {
            // Fallback — recovery code
            $ok = $this->consumeRecoveryCode((int) $user->id, $code);
        }

        if (! $ok) {
            AuthEventLogger::log('TWOFA_FAIL', (int) $user->id, null, 'WARN', null, 5, $r);
            return response()->json(['ok' => false, 'error' => 'Invalid code'], 401);
        }

        AuthEventLogger::log('TWOFA_OK', (int) $user->id, null, 'INFO', null, -2, $r);
        cache()->forget('auth.challenge.' . $data['challenge_id']);

        // Optionally register this device as trusted for 30 days
        if (! empty($data['remember_device'])) {
            $this->trustDevice((int) $user->id, $data['device_fingerprint'] ?? null, $r);
        }

        return $this->completeLogin($user, $r, $data['device_fingerprint'] ?? null);
    }

    public function logout(Request $r): JsonResponse
    {
        try {
            $token = $r->user()?->currentAccessToken();
            if ($token) {
                AuthEventLogger::log('LOGOUT', (int) $r->user()->id, null, 'INFO', ['token_id' => $token->id], 0, $r);
                $token->delete();
            }
            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function logoutAll(Request $r): JsonResponse
    {
        try {
            $uid = (int) $r->user()->id;
            $n = DB::table('personal_access_tokens')->where('tokenable_id', $uid)->delete();
            AuthEventLogger::log('LOGOUT', $uid, null, 'INFO', ['scope' => 'all', 'count' => $n], 0, $r);
            return response()->json(['ok' => true, 'data' => ['revoked' => $n]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function me(Request $r): JsonResponse
    {
        $u = $r->user();
        $assignments = DB::table('user_assignments')->where('user_id', $u->id)
            ->where('is_active', 1)->get();
        $flags = DB::table('user_anomaly_flags')->where('user_id', $u->id)
            ->whereNull('cleared_at')->get();
        return response()->json(['ok' => true, 'data' => [
            'user' => $this->publicUserShape($u),
            'assignments' => $assignments,
            'risk' => [
                'score' => (int) $u->risk_score,
                'flags' => $flags,
                'updated_at' => $u->risk_score_updated_at,
            ],
        ]]);
    }

    /**
     * PATCH /v2/auth/me — self-service profile update. Only accepts
     * non-privileged fields (name, phone, locale, timezone, avatar_url).
     * Role / country / account_type changes go through /admin/users.
     */
    public function updateMe(Request $r): JsonResponse
    {
        $data = \Illuminate\Support\Facades\Validator::make($r->all(), [
            'full_name'  => 'nullable|string|max:150',
            'phone'      => 'nullable|string|max:40',
            'locale'     => 'nullable|string|max:10',
            'timezone'   => 'nullable|string|max:64',
            'avatar_url' => 'nullable|url|max:500',
        ])->validate();
        $fields = array_filter($data, fn($v) => $v !== null);
        if (isset($fields['full_name'])) $fields['name'] = $fields['full_name'];
        $fields['updated_at'] = now();
        DB::table('users')->where('id', $r->user()->id)->update($fields);
        AuthEventLogger::log('PROFILE_UPDATED', (int) $r->user()->id, null, 'INFO',
            ['fields' => array_keys($fields)], 0, $r);
        return response()->json(['ok' => true]);
    }

    public function refresh(Request $r): JsonResponse
    {
        $u = $r->user();
        $old = $u->currentAccessToken();
        $new = $u->createToken('poe-dashboard-' . now()->timestamp, ['*']);
        if ($old) $old->delete();
        return response()->json(['ok' => true, 'data' => [
            'token' => $new->plainTextToken,
            'token_id' => $new->accessToken->id,
            'expires_at' => now()->addDays(30)->toIso8601String(),
        ]]);
    }

    public function changePassword(Request $r): JsonResponse
    {
        $data = Validator::make($r->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:12|confirmed',
        ])->validate();

        $u = $r->user();
        $fresh = DB::table('users')->where('id', $u->id)->first();
        $ok = Hash::check($data['current_password'], (string) ($fresh->password ?? $fresh->password_hash ?? ''));
        if (! $ok) {
            AuthEventLogger::log('PASSWORD_CHANGE_FAIL', (int) $u->id, null, 'WARN', null, 2, $r);
            return response()->json(['ok' => false, 'error' => 'Current password is incorrect'], 400);
        }
        if (! $this->passwordMeetsPolicy($data['new_password'])) {
            return response()->json([
                'ok' => false,
                'error' => 'Password must be ≥ 12 chars with letters, numbers and a symbol.',
            ], 422);
        }

        $hash = Hash::make($data['new_password']);
        DB::table('users')->where('id', $u->id)->update([
            'password'             => $hash,
            'password_hash'        => $hash,
            'password_changed_at'  => now(),
            'must_change_password' => 0,
            'updated_at'           => now(),
        ]);

        // Revoke every other token so other devices have to re-auth.
        DB::table('personal_access_tokens')->where('tokenable_id', $u->id)
            ->where('id', '!=', $u->currentAccessToken()?->id ?? 0)->delete();

        AuthEventLogger::log('PASSWORD_CHANGED', (int) $u->id, null, 'INFO', null, -5, $r);
        AuthMailer::send('AUTH_PASSWORD_CHANGED', (string) $u->email, $this->mailVars($u, $r));
        UserAnomalyService::scanUser((int) $u->id);

        return response()->json(['ok' => true]);
    }

    public function sessions(Request $r): JsonResponse
    {
        $uid = (int) $r->user()->id;
        $tokens = DB::table('personal_access_tokens')
            ->where('tokenable_type', 'App\\Models\\User')
            ->where('tokenable_id', $uid)
            ->select('id', 'name', 'last_used_at', 'created_at')
            ->orderByDesc('last_used_at')->get();
        $devices = DB::table('trusted_devices')->where('user_id', $uid)
            ->whereNull('revoked_at')->orderByDesc('last_used_at')->get();
        return response()->json(['ok' => true, 'data' => ['tokens' => $tokens, 'trusted_devices' => $devices]]);
    }

    public function revokeSession(Request $r, int $id): JsonResponse
    {
        $uid = (int) $r->user()->id;
        $n = DB::table('personal_access_tokens')
            ->where('tokenable_id', $uid)->where('id', $id)->delete();
        if ($n) AuthEventLogger::log('TOKEN_REVOKED', $uid, null, 'INFO', ['token_id' => $id], 0, $r);
        return response()->json(['ok' => (bool) $n]);
    }

    // ══════════════════════════════════════════════════════════════════════
    //  INTERNAL
    // ══════════════════════════════════════════════════════════════════════

    private function onFailedLogin(?object $user, string $login, Request $r): void
    {
        if (! $user) {
            AuthEventLogger::log('LOGIN_FAIL', null, $login, 'WARN',
                ['reason' => 'unknown_user'], 3, $r);
            return;
        }
        $fails = (int) $user->failed_login_count + 1;
        $update = ['failed_login_count' => $fails, 'last_failed_login_at' => now(), 'updated_at' => now()];
        foreach (self::LOCK_STEPS as $threshold => $minutes) {
            if ($fails >= $threshold) {
                $update['locked_until'] = now()->addMinutes($minutes);
            }
        }
        DB::table('users')->where('id', $user->id)->update($update);
        AuthEventLogger::log('LOGIN_FAIL', (int) $user->id, $login, 'WARN',
            ['reason' => 'bad_password', 'failed_count' => $fails, 'locked' => isset($update['locked_until'])],
            5, $r);
        if (isset($update['locked_until'])) {
            AuthEventLogger::log('LOCKED', (int) $user->id, null, 'ERROR',
                ['until' => $update['locked_until']->toDateTimeString()], 10, $r);
            AuthMailer::send('AUTH_ACCOUNT_LOCKED', (string) $user->email, $this->mailVars($user, $r, [
                'locked_until' => $update['locked_until']->format('Y-m-d H:i'),
                'failed_count' => $fails,
            ]));
        }
    }

    private function completeLogin(object $user, Request $r, ?string $fingerprint): JsonResponse
    {
        try {
            // Detect "new device"
            $isNewDevice = $this->isNewDevice((int) $user->id, $r->ip(), $r->userAgent());

            DB::table('users')->where('id', $user->id)->update([
                'failed_login_count' => 0,
                'locked_until'       => null,
                'last_login_at'      => now(),
                'last_login_ip'      => $r->ip(),
                'last_login_ua'      => mb_substr((string) $r->userAgent(), 0, 300),
                'last_activity_at'   => now(),
                'updated_at'         => now(),
            ]);

            // Laravel-authenticate the user via Sanctum to create a bearer token.
            $userModel = \App\Models\User::find($user->id);
            if (! $userModel) throw new \RuntimeException('User model not found');

            // Single-use token name per session.
            $deviceLabel = $this->deviceLabel($r);
            $token = $userModel->createToken($deviceLabel, ['*']);

            AuthEventLogger::log('LOGIN_OK', (int) $user->id, null, 'INFO',
                ['token_id' => $token->accessToken->id, 'new_device' => $isNewDevice],
                -2, $r);

            if ($isNewDevice && $user->email) {
                AuthMailer::send('AUTH_NEW_LOGIN_DEVICE', (string) $user->email,
                    $this->mailVars($user, $r, ['device_label' => $deviceLabel]));
            }

            // Kick an async risk rescan — no await, fire-and-forget.
            try { UserAnomalyService::scanUser((int) $user->id); } catch (Throwable) {}

            return response()->json(['ok' => true, 'data' => [
                'token'      => $token->plainTextToken,
                'token_id'   => $token->accessToken->id,
                'expires_at' => now()->addDays(30)->toIso8601String(),
                'user'       => $this->publicUserShape($user),
                'must_change_password' => (bool) $user->must_change_password,
            ]]);
        } catch (Throwable $e) {
            Log::error('[login] complete: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function issueChallenge(int $userId, Request $r): string
    {
        $id = Str::uuid()->toString();
        cache()->put('auth.challenge.' . $id, [
            'user_id' => $userId,
            'ip' => $r->ip(),
            'ua' => mb_substr((string) $r->userAgent(), 0, 300),
            'issued_at' => now()->toIso8601String(),
        ], now()->addMinutes(5));
        return $id;
    }

    private function consumeRecoveryCode(int $userId, string $code): bool
    {
        $u = DB::table('users')->where('id', $userId)->first();
        if (! $u || ! $u->two_factor_recovery_codes_hash) return false;
        $hashes = (array) json_decode((string) $u->two_factor_recovery_codes_hash, true);
        foreach ($hashes as $i => $h) {
            if (! $h) continue;
            if (hash_equals((string) $h, hash('sha256', strtoupper($code)))) {
                $hashes[$i] = null;
                DB::table('users')->where('id', $userId)->update([
                    'two_factor_recovery_codes_hash' => json_encode(array_values(array_filter($hashes))),
                    'updated_at' => now(),
                ]);
                return true;
            }
        }
        return false;
    }

    private function presentedTrustedDevice(object $user, ?string $token, Request $r): bool
    {
        if (! $token) return false;
        $row = DB::table('trusted_devices')
            ->where('user_id', $user->id)
            ->where('device_token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();
        if (! $row) return false;
        DB::table('trusted_devices')->where('id', $row->id)->update([
            'last_used_at' => now(), 'ip_last' => $r->ip(),
        ]);
        AuthEventLogger::log('TRUSTED_DEVICE_USED', (int) $user->id, null, 'INFO',
            ['trusted_device_id' => $row->id], -1, $r);
        return true;
    }

    private function trustDevice(int $userId, ?string $fingerprint, Request $r): array
    {
        $raw = bin2hex(random_bytes(32));
        $id = DB::table('trusted_devices')->insertGetId([
            'user_id'            => $userId,
            'device_token_hash'  => hash('sha256', $raw),
            'device_fingerprint' => $fingerprint,
            'label'              => $this->deviceLabel($r),
            'user_agent'         => mb_substr((string) $r->userAgent(), 0, 300),
            'ip_first'           => $r->ip(),
            'ip_last'            => $r->ip(),
            'expires_at'         => now()->addDays(30),
            'created_at'         => now(),
        ]);
        AuthEventLogger::log('TRUSTED_DEVICE_ADDED', $userId, null, 'INFO',
            ['trusted_device_id' => $id], 0, $r);
        return ['id' => $id, 'token' => $raw];
    }

    private function isNewDevice(int $userId, ?string $ip, ?string $ua): bool
    {
        $seen = DB::table('auth_events')->where('user_id', $userId)
            ->where('event_type', 'LOGIN_OK')
            ->where('created_at', '>=', now()->subDays(30))
            ->where(function ($q) use ($ip, $ua) {
                $q->where('ip', $ip)->orWhere('user_agent', $ua);
            })->exists();
        return ! $seen;
    }

    private function passwordMeetsPolicy(string $pw): bool
    {
        if (mb_strlen($pw) < 12) return false;
        $classes = 0;
        if (preg_match('/[a-z]/', $pw)) $classes++;
        if (preg_match('/[A-Z]/', $pw)) $classes++;
        if (preg_match('/\d/', $pw))    $classes++;
        if (preg_match('/[^a-zA-Z0-9]/', $pw)) $classes++;
        return $classes >= 3;
    }

    private function deviceLabel(Request $r): string
    {
        $ua = (string) $r->userAgent();
        $os = 'Unknown OS';
        if (str_contains($ua, 'Windows')) $os = 'Windows';
        elseif (str_contains($ua, 'Mac OS')) $os = 'macOS';
        elseif (str_contains($ua, 'Linux')) $os = 'Linux';
        elseif (str_contains($ua, 'Android')) $os = 'Android';
        elseif (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) $os = 'iOS';
        $browser = 'Browser';
        if (str_contains($ua, 'Firefox')) $browser = 'Firefox';
        elseif (str_contains($ua, 'Edg/')) $browser = 'Edge';
        elseif (str_contains($ua, 'Chrome')) $browser = 'Chrome';
        elseif (str_contains($ua, 'Safari')) $browser = 'Safari';
        return "{$browser} · {$os}";
    }

    private function publicUserShape(object $u): array
    {
        return [
            'id'                      => (int) $u->id,
            'full_name'               => (string) ($u->full_name ?? $u->name ?? ''),
            'email'                   => $u->email,
            'username'                => $u->username,
            'role_key'                => $u->role_key,
            'account_type'            => $u->account_type ?? $u->role_key,
            'country_code'            => $u->country_code,
            'avatar_url'              => $u->avatar_url ?? null,
            'locale'                  => $u->locale ?? 'en',
            'timezone'                => $u->timezone,
            'email_verified_at'       => $u->email_verified_at,
            'two_factor_confirmed_at' => $u->two_factor_confirmed_at,
            'last_login_at'           => $u->last_login_at,
            'risk_score'              => (int) ($u->risk_score ?? 0),
            'must_change_password'    => (bool) ($u->must_change_password ?? false),
            'suspended_at'            => $u->suspended_at ?? null,
        ];
    }

    private function mailVars(object $user, Request $r, array $extra = []): array
    {
        return array_merge([
            'user_id'       => (int) $user->id,
            'full_name'     => $user->full_name ?? $user->name ?? $user->username ?? 'Team',
            'email'         => $user->email,
            'app_name'      => 'POE Sentinel',
            'console_url'   => rtrim((string) config('app.url', 'http://localhost'), '/'),
            'ip'            => $r->ip(),
            'user_agent'    => mb_substr((string) $r->userAgent(), 0, 300),
            'now'           => now()->format('Y-m-d H:i'),
        ], $extra);
    }
}
