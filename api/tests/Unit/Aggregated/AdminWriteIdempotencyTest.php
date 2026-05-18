<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/**
 * AdminWriteIdempotencyTest
 * ============================================================================
 * Pins the route-level middleware contract: every mutating admin/aggregated
 * endpoint MUST be guarded by the `idempotent` middleware so a double-clicked
 * admin or an automated retry re-uses the original response within 24h.
 *
 * The middleware itself is well-tested in app/Http/Middleware/IdempotencyKey.php
 * — it short-circuits when no Idempotency-Key header is present, so routes
 * can be wrapped without breaking legacy callers. This test pins the wiring.
 *
 * If a future refactor pulls a route out of the middleware group, this test
 * fails loud. That keeps "admin double-click duplicates a template" from
 * silently regressing.
 */
uses(\Tests\TestCase::class);

$writeRoutes = [
    'admin.aggregated.studio.template.store',
    'admin.aggregated.studio.template.update',
    'admin.aggregated.studio.template.destroy',
    'admin.aggregated.studio.template.lifecycle',
    'admin.aggregated.studio.template.column.store',
    'admin.aggregated.studio.template.columns.bulk',
    'admin.aggregated.studio.column.update',
    'admin.aggregated.studio.column.destroy',
    'admin.aggregated.sync.resync',
];

foreach ($writeRoutes as $name) {
    test("route {$name} carries the idempotent middleware", function () use ($name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull();
        $middleware = $route->gatherMiddleware();
        // idempotent → catches admin double-clicks via Idempotency-Key header
        expect($middleware)->toContain('idempotent');
        // role:NATIONAL_ADMIN → write privilege gate, must never regress
        expect($middleware)->toContain('role:NATIONAL_ADMIN');
    });
}
