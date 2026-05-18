<?php

namespace App\Http\Middleware;

use App\Services\PheocScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RequirePoeAssignment
 * ---------------------------------------------------------------------------
 * Enforces the product invariant:
 *
 *     EVERY user — no matter what role — must have at least one ACTIVE
 *     user_assignments row pointing at a POE (poe_code IS NOT NULL,
 *     is_active=1, ends_at IS NULL).
 *
 * This includes NATIONAL_ADMINs: the POE tether exists so audits can always
 * answer "what POE is this person physically responsible for today?". Admins
 * still see the whole country (PheocScope.is_super), the POE link is purely
 * attributive.
 *
 * Behaviour:
 *   - No authenticated user → pass through (downstream auth handles 401).
 *   - User has ≥1 active POE in user_assignments → pass through.
 *   - User has zero → HTML request: 303 to /admin/assignments/onboarding
 *                     JSON request: 409 with structured error.
 *
 * Exceptions: a tiny allow-list of paths that the user MUST be able to reach
 * even when they have no POE (the onboarding page itself, logout, etc.).
 */
class RequirePoeAssignment
{
    /** Paths that skip the POE check (login, logout, onboarding, static). */
    protected array $bypass = [
        'admin/logout',
        'admin/assignments/onboarding',
        'admin/self/assign-poe',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        foreach ($this->bypass as $p) {
            if ($request->is($p) || $request->is($p . '/*')) {
                return $next($request);
            }
        }

        $scope = app(PheocScope::class)->forUser($user);

        if (! empty($scope['poes'])) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'error'        => 'POE_ASSIGNMENT_REQUIRED',
                'message'      => 'Your account is not attached to a POE. An administrator must assign one before you can access the dashboard.',
                'role_key'     => $scope['role_key'],
                'scope_level'  => $scope['scope_level'],
                'redirect'     => url('/admin/assignments/onboarding'),
            ], 409);
        }

        return redirect('/admin/assignments/onboarding')
            ->with('warning', 'Your account is not yet attached to a Point of Entry. Contact a National Admin to assign one.');
    }
}
