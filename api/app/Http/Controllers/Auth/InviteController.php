<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Public · invite-acceptance flow.
 *
 *   GET  /invite/{token}   — validate token → render accept form (or expired page)
 *   POST /invite/{token}   — set username (optional) + password → activate + login
 *
 * Token storage:  users.invitation_token_hash = sha256(plaintext)
 * Expiry:         users.invitation_expires_at
 * On accept:      invitation_accepted_at=now, invitation_token_hash=null,
 *                 is_active=1, must_change_password=0, password rotated.
 *
 * Rate-limited to 10 attempts / minute / ip+token to deter token spraying.
 */
final class InviteController extends Controller
{
    /** GET /invite/{token} */
    public function show(Request $r, string $token)
    {
        $user = $this->resolveByToken($token);

        if (! $user) {
            return response()->view('auth.invite-error', [
                'reason' => 'invalid',
                'message' => 'This invitation link is invalid or has already been accepted.',
            ], 410);
        }
        if ($this->isExpired($user)) {
            return response()->view('auth.invite-error', [
                'reason' => 'expired',
                'message' => 'This invitation has expired. Ask your administrator for a new link.',
            ], 410);
        }

        return view('auth.invite', [
            'token'      => $token,
            'user'       => $user,
            'expires_at' => $user->invitation_expires_at,
            'role_label' => $this->roleLabel((string) $user->role_key),
        ]);
    }

    /** POST /invite/{token} */
    public function accept(Request $r, string $token): Response|RedirectResponse
    {
        $key = 'invite:' . sha1(($r->ip() ?? '') . '|' . $token);
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $sec = RateLimiter::availableIn($key);
            throw ValidationException::withMessages(['password' => "Too many attempts. Try again in {$sec}s."]);
        }
        RateLimiter::hit($key, 60);

        $user = $this->resolveByToken($token);
        if (! $user || $this->isExpired($user)) {
            return redirect()->route('login')->withErrors(['email' => 'Your invitation link is invalid or expired.']);
        }

        $data = $r->validate([
            'password'              => ['required', 'string', 'min:10', 'max:200', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            'accept_terms'          => ['accepted'],
        ], [
            'password.min'       => 'Password must be at least 10 characters.',
            'password.confirmed' => 'Passwords do not match.',
            'accept_terms.accepted' => 'You must accept the terms of use to continue.',
        ]);

        // Strength gate — reject the obvious weak set.
        if (! $this->strongEnough($data['password'], $user)) {
            throw ValidationException::withMessages([
                'password' => 'Password is too weak. Use a mix of upper/lower-case, numbers, and a symbol — and avoid your name or email.',
            ]);
        }

        try {
            $hash = Hash::make($data['password']);
            $now  = Carbon::now();

            DB::transaction(function () use ($user, $hash, $now): void {
                DB::table('users')->where('id', $user->id)->update([
                    'password'              => $hash,
                    'password_hash'         => $hash,
                    'must_change_password'  => 0,
                    'is_active'             => 1,
                    'invitation_token_hash' => null,
                    'invitation_accepted_at'=> $now,
                    'password_changed_at'   => $now,
                    'failed_login_count'    => 0,
                    'locked_until'          => null,
                    'updated_at'            => $now,
                ]);

                DB::table('user_audit_log')->insert([
                    'actor_user_id'  => $user->id, // self-acceptance
                    'target_user_id' => $user->id,
                    'action'         => 'INVITE_ACCEPTED',
                    'before_json'    => null,
                    'after_json'     => json_encode(['accepted_at' => $now->toIso8601String()]),
                    'ip'             => request()->ip(),
                    'user_agent'     => substr((string) request()->userAgent(), 0, 500),
                    'created_at'     => $now,
                ]);
            });

            // Reload and log the user in (web guard).
            $fresh = \App\Models\User::find($user->id);
            Auth::guard('web')->login($fresh, false);
            $r->session()->regenerate();

            try {
                DB::table('auth_events')->insert([
                    'user_id'         => $user->id,
                    'email_attempted' => $user->email,
                    'event_type'      => 'INVITE_LOGIN',
                    'severity'        => 'INFO',
                    'ip'              => $r->ip(),
                    'user_agent'      => substr((string) $r->userAgent(), 0, 500),
                    'city'            => null,
                    'country'         => null,
                    'payload_json'    => json_encode(['source' => 'invite_accept']),
                    'risk_delta'      => 0,
                    'created_at'      => $now,
                ]);
            } catch (Throwable $e) { /* table may not require all cols; ignore */ }

            return redirect()->intended('/admin/dashboard')
                ->with('flash_success', 'Welcome aboard. Your account is active.');
        } catch (Throwable $e) {
            Log::error('[Invite\\accept] '.$e->getMessage(), ['file' => $e->getFile().':'.$e->getLine()]);
            return redirect()->back()->withErrors(['password' => 'We could not activate your account. Try again or contact your administrator.']);
        }
    }

    /* ─────── helpers ─────── */

    /** Lookup user by sha256(token) — only matches if invite is still pending. */
    private function resolveByToken(string $token): ?object
    {
        if (strlen($token) < 20 || strlen($token) > 256) return null;
        $hash = hash('sha256', $token);
        return DB::table('users')
            ->where('invitation_token_hash', $hash)
            ->whereNull('invitation_accepted_at')
            ->first([
                'id','full_name','username','email','role_key','account_type',
                'invitation_expires_at','invitation_accepted_at',
            ]);
    }

    private function isExpired(object $user): bool
    {
        if (! $user->invitation_expires_at) return false;
        return Carbon::parse($user->invitation_expires_at)->isPast();
    }

    private function strongEnough(string $pw, object $user): bool
    {
        if (mb_strlen($pw) < 10) return false;
        $hasLower  = (bool) preg_match('/[a-z]/', $pw);
        $hasUpper  = (bool) preg_match('/[A-Z]/', $pw);
        $hasDigit  = (bool) preg_match('/\d/', $pw);
        $hasSymbol = (bool) preg_match('/[^\w\s]/', $pw);
        $classes = (int) $hasLower + (int) $hasUpper + (int) $hasDigit + (int) $hasSymbol;
        if ($classes < 3) return false;
        // Avoid trivially-derived passwords (own name / email local-part / username).
        $needles = array_filter([
            mb_strtolower((string) ($user->username ?? '')),
            mb_strtolower(strstr((string) ($user->email ?? ''), '@', true) ?: ''),
            mb_strtolower((string) ($user->full_name ?? '')),
        ]);
        $pwLower = mb_strtolower($pw);
        foreach ($needles as $n) {
            if ($n !== '' && mb_strlen($n) >= 4 && str_contains($pwLower, $n)) return false;
        }
        return true;
    }

    private function roleLabel(string $key): string
    {
        $row = DB::table('role_registry')->where('role_key', $key)->first(['display_name']);
        return $row->display_name ?? $key;
    }
}
