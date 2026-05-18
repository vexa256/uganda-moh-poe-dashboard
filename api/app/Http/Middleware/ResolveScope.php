<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\PheocScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ResolveScope middleware
 * ---------------------------------------------------------------------------
 * Reads the authenticated user's geographic/role scope (via `PheocScope`)
 * and binds it to the current request so every downstream controller, form
 * request, and view composer can consume it without re-resolving.
 *
 *   Route::middleware(['auth:sanctum', 'scope'])
 *     ->group(function () { ... });
 *
 * After the middleware runs:
 *   $request->attributes->get('scope')        // full descriptor array
 *   $request->user()->scope                   // set as a dynamic attribute
 *
 * RBAC rule S9 (UI_STANDARDS): Blade NEVER decides authorisation. This
 * middleware does not gate anything — it only computes and publishes the
 * scope. Gating is the job of `RoleGate` middleware + FormRequest::authorize()
 * + controller-level policy checks, all of which consume `$scope` to decide.
 *
 * Graceful degradation: if no user is authenticated, `scope` is set to null.
 * Callers must guard.
 */
final class ResolveScope
{
    public function __construct(protected PheocScope $scope)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $descriptor = $this->scope->forUser($user);
            $request->attributes->set('scope', $descriptor);

            // Also publish on the user model so `$user->scope` is available
            // anywhere the user is in scope. Setting a dynamic property is
            // harmless on an Eloquent model when we don't persist.
            try {
                $user->scope = $descriptor;
            } catch (\Throwable) {
                // If Eloquent disallows dynamic attribute set, ignore.
            }
        } else {
            $request->attributes->set('scope', null);
        }

        return $next($request);
    }
}
