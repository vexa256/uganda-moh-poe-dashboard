<?php

/**
 * Admin · Governance · gov-auth · contract tests
 * ---------------------------------------------------------------------------
 * The base schema (users, auth_events, user_anomaly_flags) lives in raw SQL
 * seed files — not Laravel migrations — so Feature tests against the
 * :memory: sqlite harness cannot hit the DB here. These tests live in Unit/
 * by design and verify the non-DB parts of the contract:
 *
 *   (a) every controller action is registered under the expected route name
 *   (b) the NATIONAL_ADMIN role gate is wired on every gov-auth route
 *   (c) the Blade view compiles to valid PHP
 *   (d) the sidebar entry for `gov-auth` resolves to the registered URL
 *
 * Mirrors the SidebarRelabelTest pattern already in Unit/Admin/.
 */

use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class);

const GOV_AUTH_ROUTES = [
    'admin.governance.auth-events.index'     => ['GET',  'admin/governance/auth-events'],
    'admin.governance.auth-events.data'      => ['GET',  'admin/governance/auth-events/data'],
    'admin.governance.auth-events.summary'   => ['GET',  'admin/governance/auth-events/summary'],
    'admin.governance.auth-events.lockouts'  => ['GET',  'admin/governance/auth-events/lockouts'],
    'admin.governance.auth-events.anomalies' => ['GET',  'admin/governance/auth-events/anomalies'],
    'admin.governance.auth-events.export'    => ['GET',  'admin/governance/auth-events/export'],
    'admin.governance.auth-events.anomalies.clear' => ['POST', 'admin/governance/auth-events/anomalies/{id}/clear'],
];

it('registers every gov-auth named route with the expected URI', function () {
    foreach (GOV_AUTH_ROUTES as $name => [$method, $uri]) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' is not registered");
        expect($route->uri())->toBe($uri);
        expect(in_array($method, $route->methods(), true))
            ->toBeTrue("route '{$name}' does not accept {$method}");
    }
});

it('gates every gov-auth route with the NATIONAL_ADMIN role middleware', function () {
    foreach (array_keys(GOV_AUTH_ROUTES) as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' missing");
        $mw = $route->gatherMiddleware();
        $hasRoleGate = collect($mw)->contains(fn ($m) => is_string($m)
            && str_starts_with($m, 'role:')
            && str_contains($m, 'NATIONAL_ADMIN'));
        expect($hasRoleGate)->toBeTrue("route '{$name}' missing role:NATIONAL_ADMIN middleware (got " . implode(',', $mw) . ')');
        expect(in_array('web', $mw, true))->toBeTrue("route '{$name}' must run in 'web' group");
        expect(in_array('auth', $mw, true))->toBeTrue("route '{$name}' must require auth");
        expect(in_array('scope', $mw, true))->toBeTrue("route '{$name}' must resolve scope");
    }
});

it('binds each gov-auth route to AuthEventsController', function () {
    $expected = [
        'admin.governance.auth-events.index'     => 'index',
        'admin.governance.auth-events.data'      => 'data',
        'admin.governance.auth-events.summary'   => 'summary',
        'admin.governance.auth-events.lockouts'  => 'lockouts',
        'admin.governance.auth-events.anomalies' => 'anomalies',
        'admin.governance.auth-events.export'    => 'export',
        'admin.governance.auth-events.anomalies.clear' => 'clearAnomaly',
    ];
    foreach ($expected as $name => $method) {
        $route  = Route::getRoutes()->getByName($name);
        $action = $route->getAction('controller');
        expect($action)->toBe(\App\Http\Controllers\Admin\Governance\AuthEventsController::class . '@' . $method);
    }
});

it('compiles the gov-auth Blade view without syntax errors', function () {
    $path = resource_path('views/admin/governance/auth-events/index.blade.php');
    expect(file_exists($path))->toBeTrue('view file is missing');

    $compiler = app('view.engine.resolver')->resolve('blade')->getCompiler();
    $compiled = $compiler->compileString(file_get_contents($path));

    $tmp = tempnam(sys_get_temp_dir(), 'blade_gov_auth_') . '.php';
    file_put_contents($tmp, $compiled);
    $lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);

    expect($lint)->toContain('No syntax errors');
});

it('flips the gov-auth sidebar entry to live with the registered admin URL', function () {
    $sidebar = file_get_contents(resource_path('views/admin/partials/sidebar.blade.php'));
    expect($sidebar)->toContain("'gov-auth'");
    expect($sidebar)->toContain("url('/admin/governance/auth-events')");
    // The sidebar helper: $nav(id, label, hint, iconPath, href='#', live=false)
    // The entry must be marked live (true) so it renders with the live dot.
    // We rely on the project convention that `url(...)` + literal `, true` marks live.
    $pattern = "/'gov-auth'[\\s\\S]+?url\\('\\/admin\\/governance\\/auth-events'\\)\\s*,\\s*true\\s*\\)/";
    expect(preg_match($pattern, $sidebar))->toBe(1, 'gov-auth entry is not marked live=true');
});

it('renders the gov-auth view shell without hitting the DB', function () {
    // A minimal render proof — passes $known_events (required by the view) and
    // asserts key primitives + every section heading are present.
    $html = view('admin.governance.auth-events.index', [
        'page_title'    => 'Auth Events',
        'page_eyebrow'  => 'Governance',
        'page_subtitle' => 'Login · MFA · lockouts · suspended users · auth_events feed.',
        'known_events'  => ['LOGIN_OK', 'LOGIN_FAIL', 'LOGOUT'],
    ])->render();

    // Scaffolding + viz anchors.
    foreach ([
        'authEventsPage()',
        'Severity mix',
        'Login funnel',
        'Top source IPs',
        'Top event types',
        'Logins by role',
        'Activity · hourly',
        'When do auth events happen',
        'Event feed',
        'Lockouts',
        'Anomalies',
        'ring-gauge',
        'kpi',
        'tabs-trigger',
    ] as $needle) {
        expect($html)->toContain($needle);
    }
});

it('produces well-formed HTML with no duplicate element IDs in the gov-auth view', function () {
    $html = view('admin.governance.auth-events.index', [
        'page_title'    => 'Auth Events',
        'page_eyebrow'  => 'Governance',
        'page_subtitle' => 'Login · MFA · lockouts · suspended users · auth_events feed.',
        'known_events'  => ['LOGIN_OK', 'LOGIN_FAIL', 'LOGOUT'],
    ])->render();

    // Extract all id="…" values (the view is a fragment, not a full document).
    preg_match_all('/\sid="([^"]+)"/', $html, $m);
    $ids = $m[1];

    // IDs inside Alpine templates are fine as duplicates at rendered time
    // because templates are not instantiated until x-for iterates; but at
    // render time every static id attribute in the Blade source should be
    // unique. Our inventory: gov-auth-search, gov-auth-filter-event,
    // gov-auth-filter-sev, clear-note, clear-title, all <label for="…"> IDs.
    $duplicates = array_filter(array_count_values($ids), fn ($n) => $n > 1);
    expect($duplicates)->toBeEmpty('duplicate element IDs: ' . implode(', ', array_keys($duplicates)));

    // Spot-check that each <label for="..."> points at a real static id in the
    // same fragment (a11y: label→control association).
    preg_match_all('/<label[^>]+for="([^"]+)"/', $html, $lm);
    foreach ($lm[1] as $forId) {
        if (str_starts_with($forId, 'gov-auth-') || $forId === 'clear-note') {
            expect(in_array($forId, $ids, true))
                ->toBeTrue("<label for=\"{$forId}\"> has no matching id in rendered view");
        }
    }
});
