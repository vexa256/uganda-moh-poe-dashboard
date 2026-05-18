<?php

/**
 * Admin · Governance · gov-notif-log · contract tests
 * Mirrors AuthEventsRoutingTest — same rationale for Unit-not-Feature.
 */

use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class);

const GOV_NOTIF_ROUTES = [
    'admin.governance.notification-log.index'   => ['GET', 'admin/governance/notification-log'],
    'admin.governance.notification-log.data'    => ['GET', 'admin/governance/notification-log/data'],
    'admin.governance.notification-log.summary' => ['GET', 'admin/governance/notification-log/summary'],
    'admin.governance.notification-log.meta'    => ['GET', 'admin/governance/notification-log/meta'],
    'admin.governance.notification-log.export'  => ['GET', 'admin/governance/notification-log/export'],
];

it('registers every gov-notif-log named route with the expected URI', function () {
    foreach (GOV_NOTIF_ROUTES as $name => [$method, $uri]) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' is not registered");
        expect($route->uri())->toBe($uri);
        expect(in_array($method, $route->methods(), true))
            ->toBeTrue("route '{$name}' does not accept {$method}");
    }
});

it('gates every gov-notif-log route with NATIONAL_ADMIN + auth + scope', function () {
    foreach (array_keys(GOV_NOTIF_ROUTES) as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull();
        $mw = $route->gatherMiddleware();
        $roleGated = collect($mw)->contains(fn ($m) => is_string($m)
            && str_starts_with($m, 'role:') && str_contains($m, 'NATIONAL_ADMIN'));
        expect($roleGated)->toBeTrue("route '{$name}' is missing role:NATIONAL_ADMIN");
        expect(in_array('web',   $mw, true))->toBeTrue();
        expect(in_array('auth',  $mw, true))->toBeTrue();
        expect(in_array('scope', $mw, true))->toBeTrue();
    }
});

it('binds each gov-notif-log route to NotificationLogController', function () {
    $expected = [
        'admin.governance.notification-log.index'   => 'index',
        'admin.governance.notification-log.data'    => 'data',
        'admin.governance.notification-log.summary' => 'summary',
        'admin.governance.notification-log.meta'    => 'meta',
        'admin.governance.notification-log.export'  => 'export',
    ];
    foreach ($expected as $name => $method) {
        $route  = Route::getRoutes()->getByName($name);
        $action = $route->getAction('controller');
        expect($action)->toBe(\App\Http\Controllers\Admin\Governance\NotificationLogController::class . '@' . $method);
    }
});

it('compiles the gov-notif-log Blade view without syntax errors', function () {
    $path = resource_path('views/admin/governance/notification-log/index.blade.php');
    expect(file_exists($path))->toBeTrue();
    $compiler = app('view.engine.resolver')->resolve('blade')->getCompiler();
    $compiled = $compiler->compileString(file_get_contents($path));
    $tmp = tempnam(sys_get_temp_dir(), 'blade_gov_notif_') . '.php';
    file_put_contents($tmp, $compiled);
    $lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    expect($lint)->toContain('No syntax errors');
});

it('flips the gov-notif-log sidebar entry to live', function () {
    $sidebar = file_get_contents(resource_path('views/admin/partials/sidebar.blade.php'));
    expect($sidebar)->toContain("'gov-notif-log'");
    expect($sidebar)->toContain("url('/admin/governance/notification-log')");
    $pattern = "/'gov-notif-log'[\\s\\S]+?url\\('\\/admin\\/governance\\/notification-log'\\)\\s*,\\s*true\\s*\\)/";
    expect(preg_match($pattern, $sidebar))->toBe(1);
});

it('renders the gov-notif-log view shell with every required viz anchor', function () {
    $html = view('admin.governance.notification-log.index', [
        'page_title'    => 'Delivery Audit',
        'page_eyebrow'  => 'Governance',
        'page_subtitle' => 'test',
        'statuses'      => ['QUEUED', 'SENT', 'FAILED', 'BOUNCED', 'SKIPPED'],
        'channels'      => ['EMAIL', 'SMS', 'PUSH'],
    ])->render();

    foreach ([
        'notifLogPage()',
        'Delivery ratio',
        'Status mix',
        'Channel mix',
        'Top templates',
        'Top failure reasons',
        'When do sends happen',
        'ring-gauge',
        'kpi',
        'tabs-trigger',
        'Failures only',
    ] as $needle) {
        expect($html)->toContain($needle);
    }
});

it('produces well-formed HTML with no duplicate static IDs and label→control links', function () {
    $html = view('admin.governance.notification-log.index', [
        'page_title'    => 'Delivery Audit',
        'page_eyebrow'  => 'Governance',
        'page_subtitle' => 'test',
        'statuses'      => ['QUEUED', 'SENT', 'FAILED', 'BOUNCED', 'SKIPPED'],
        'channels'      => ['EMAIL', 'SMS', 'PUSH'],
    ])->render();

    preg_match_all('/\sid="([^"]+)"/', $html, $m);
    $ids = $m[1];
    $dupes = array_filter(array_count_values($ids), fn ($n) => $n > 1);
    expect($dupes)->toBeEmpty('duplicate static IDs: ' . implode(', ', array_keys($dupes)));

    preg_match_all('/<label[^>]+for="([^"]+)"/', $html, $lm);
    foreach ($lm[1] as $forId) {
        if (str_starts_with($forId, 'gov-notif-')) {
            expect(in_array($forId, $ids, true))
                ->toBeTrue("<label for=\"{$forId}\"> has no matching id");
        }
    }
});
