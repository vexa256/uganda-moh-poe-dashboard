<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Admin · Auth · Session login + logout.
 *
 * Session-based auth for the web admin. Users identified by email OR
 * username (the `users` table carries both). Password is checked against
 * `password` and, if absent, `password_hash` — supporting both the Laravel
 * standard column and the Uganda app.sql legacy column (see migration
 * 000004).
 *
 * Rate-limited (5 attempts / minute / ip+identifier) to deter brute force.
 *
 * On success: regenerate session, stamp last_login_at, redirect to the
 * intended URL or /admin/dashboard. Scope resolution happens downstream
 * via ResolveScope middleware on every /admin/* request.
 */
final class LoginController extends Controller
{
    /** GET /login — renders the premium login view. */
    public function showLoginForm(): View
    {
        return view('admin.auth.login');
    }

    /** POST /login — attempt authentication. */
    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:190'],
            'password'   => ['required', 'string', 'min:4', 'max:200'],
            'remember'   => ['nullable', 'boolean'],
        ], [
            'identifier.required' => 'Enter your email or username.',
            'password.required'   => 'Enter your password.',
        ]);

        $ident     = trim($data['identifier']);
        $throttle  = 'admin-login|' . strtolower($ident) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            $seconds = RateLimiter::availableIn($throttle);
            throw ValidationException::withMessages([
                'identifier' => "Too many attempts. Try again in {$seconds}s.",
            ]);
        }

        $user = $this->findByIdentifier($ident);
        if (! $user) {
            RateLimiter::hit($throttle, 60);
            throw ValidationException::withMessages([
                'identifier' => 'No active account matches that identifier.',
            ]);
        }

        if ((int) ($user->is_active ?? 1) !== 1) {
            throw ValidationException::withMessages([
                'identifier' => 'Account is suspended. Contact the National Admin.',
            ]);
        }

        // Accept either `password` (Laravel standard) or `password_hash` (legacy app.sql).
        $hashed = (string) ($user->password ?: $user->password_hash ?: '');
        if ($hashed === '' || ! Hash::check($data['password'], $hashed)) {
            RateLimiter::hit($throttle, 60);
            throw ValidationException::withMessages([
                'password' => 'Those credentials do not match any account.',
            ]);
        }

        // All good — log in. Regenerate to guard against session fixation.
        Auth::guard('web')->login($user, (bool) ($data['remember'] ?? false));
        $request->session()->regenerate();
        RateLimiter::clear($throttle);

        // Stamp last_login_at (best effort).
        try {
            DB::table('users')->where('id', $user->id)->update(['last_login_at' => now()]);
        } catch (\Throwable) {
            // table may lack the column in exotic deployments — ignore.
        }

        // If identifier was an email, remember it for next time (cookie, 30 days).
        $cookie = cookie('admin_last_email', (string) ($user->email ?? $ident), 60 * 24 * 30);

        // 2026-05-20: post-login default landing changed to Screening Volume
        // per directive — it's the "what's happening today" view. `intended()`
        // still honours any URL the user was trying to reach before the auth
        // bounce, so deep links keep working. /admin/dashboard URL itself is
        // unchanged — bookmarks, PheocCopilot links, sidebar entry all stay.
        return redirect()->intended('/admin/quick-reports/screening-volume?days=7')->withCookie($cookie);
    }

    /** POST /logout — tear down the session. */
    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect('/login')->with('status', 'You have been signed out.');
    }

    /**
     * Resolve a user by email OR username. Case-insensitive. Only active rows.
     */
    private function findByIdentifier(string $ident): ?User
    {
        $q = User::query()
            ->where('is_active', 1);

        if (Str::contains($ident, '@')) {
            $q->where('email', $ident);
        } else {
            $q->where(function ($w) use ($ident) {
                $w->where('username', $ident)->orWhere('email', $ident);
            });
        }

        return $q->first();
    }
}
