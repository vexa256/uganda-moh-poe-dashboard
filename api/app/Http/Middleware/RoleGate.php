<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RoleGate middleware
 * ─────────────────────────────────────────────────────────────────────────
 * Gates a route group to a set of role_key values on the authenticated user.
 *
 *   Route::middleware(['auth:sanctum', 'role:NATIONAL_ADMIN,PHEOC_OFFICER'])
 *     ->group(function () { ... });
 *
 * Contract:
 *   · If the Sanctum-authed user is missing, returns 401.
 *   · If the user's role_key OR account_type is not in the allowed list,
 *     returns 403 with a generic message (no role leakage).
 *   · Always writes a failure into auth_events with severity=WARN so the
 *     audit feed catches privilege-escalation probing.
 */
final class RoleGate
{
    public function handle(Request $request, Closure $next, string ...$allowedRoles): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['ok' => false, 'error' => 'Unauthenticated'], 401);
        }
        $roles = array_map('strtoupper', $allowedRoles);
        $candidates = array_filter([
            strtoupper((string) ($user->role_key ?? '')),
            strtoupper((string) ($user->account_type ?? '')),
        ]);
        $ok = ! empty(array_intersect($candidates, $roles));
        if (! $ok) {
            \App\Services\AuthEventLogger::log(
                'FORBIDDEN', (int) $user->id, null, 'WARN',
                [
                    'route' => $request->path(),
                    'allowed' => $roles,
                    'user_role' => $user->role_key,
                    'account_type' => $user->account_type,
                ],
                5, $request,
            );
            return response()->json(['ok' => false, 'error' => 'Forbidden'], 403);
        }
        return $next($request);
    }
}
