<?php

/**
 * Admin · Governance · gov-reminders · contract tests
 * Mirrors AuthEventsRoutingTest / NotificationLogRoutingTest.
 */

use App\Services\NotificationDispatcher;
use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class);

const GOV_REM_ROUTES = [
    'admin.governance.reminders.index'        => ['GET', 'admin/governance/reminders'],
    'admin.governance.reminders.summary'      => ['GET', 'admin/governance/reminders/summary'],
    'admin.governance.reminders.followups'    => ['GET', 'admin/governance/reminders/followups'],
    'admin.governance.reminders.retry'        => ['GET', 'admin/governance/reminders/retry'],
    'admin.governance.reminders.suppressions' => ['GET', 'admin/governance/reminders/suppressions'],
];

it('registers every gov-reminders named route with the expected URI', function () {
    foreach (GOV_REM_ROUTES as $name => [$method, $uri]) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' missing");
        expect($route->uri())->toBe($uri);
        expect(in_array($method, $route->methods(), true))->toBeTrue();
    }
});

it('gates every gov-reminders route with NATIONAL_ADMIN + auth + scope', function () {
    foreach (array_keys(GOV_REM_ROUTES) as $name) {
        $route = Route::getRoutes()->getByName($name);
        $mw = $route->gatherMiddleware();
        $roleGated = collect($mw)->contains(fn ($m) => is_string($m)
            && str_starts_with($m, 'role:') && str_contains($m, 'NATIONAL_ADMIN'));
        expect($roleGated)->toBeTrue();
        expect(in_array('web',   $mw, true))->toBeTrue();
        expect(in_array('auth',  $mw, true))->toBeTrue();
        expect(in_array('scope', $mw, true))->toBeTrue();
    }
});

it('binds each gov-reminders route to RemindersController', function () {
    $expected = [
        'admin.governance.reminders.index'        => 'index',
        'admin.governance.reminders.summary'      => 'summary',
        'admin.governance.reminders.followups'    => 'followups',
        'admin.governance.reminders.retry'        => 'retryQueue',
        'admin.governance.reminders.suppressions' => 'suppressions',
    ];
    foreach ($expected as $name => $method) {
        $route = Route::getRoutes()->getByName($name);
        $action = $route->getAction('controller');
        expect($action)->toBe(\App\Http\Controllers\Admin\Governance\RemindersController::class . '@' . $method);
    }
});

it('exposes a non-empty suppression-window map from NotificationDispatcher', function () {
    $map = NotificationDispatcher::suppressionMinutesMap();
    expect($map)->toBeArray();
    expect(count($map))->toBeGreaterThanOrEqual(10);
    foreach ($map as $code => $min) {
        expect($code)->toBeString();
        expect($min)->toBeInt()->toBeGreaterThan(0);
    }
    expect(NotificationDispatcher::defaultSuppressionMinutes())->toBeInt()->toBeGreaterThan(0);
});

it('compiles the gov-reminders Blade view without syntax errors', function () {
    $path = resource_path('views/admin/governance/reminders/index.blade.php');
    expect(file_exists($path))->toBeTrue();
    $compiler = app('view.engine.resolver')->resolve('blade')->getCompiler();
    $compiled = $compiler->compileString(file_get_contents($path));
    $tmp = tempnam(sys_get_temp_dir(), 'blade_gov_rem_') . '.php';
    file_put_contents($tmp, $compiled);
    $lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
    @unlink($tmp);
    expect($lint)->toContain('No syntax errors');
});

it('flips the gov-reminders sidebar entry to live', function () {
    $sidebar = file_get_contents(resource_path('views/admin/partials/sidebar.blade.php'));
    expect($sidebar)->toContain("'gov-reminders'");
    expect($sidebar)->toContain("url('/admin/governance/reminders')");
    $pattern = "/'gov-reminders'[\\s\\S]+?url\\('\\/admin\\/governance\\/reminders'\\)\\s*,\\s*true\\s*\\)/";
    expect(preg_match($pattern, $sidebar))->toBe(1);
});

it('renders the gov-reminders view shell with every viz anchor', function () {
    $html = view('admin.governance.reminders.index', [
        'page_title'    => 'Reminders & Retry',
        'page_eyebrow'  => 'Governance',
        'page_subtitle' => 'test',
    ])->render();

    foreach ([
        'remindersPage()',
        'Overdue followups',
        'Closure blockers',
        'Retry pyramid',
        'Next 24 hours',
        'Pressure by action code',
        'Contact freshness',
        'Suppression windows',
        'Followups',
        'Retry queue',
        'Suppressions',
        'kpi',
        'tabs-trigger',
    ] as $needle) {
        expect($html)->toContain($needle);
    }
});

it('produces well-formed HTML with no duplicate static IDs in the gov-reminders view', function () {
    $html = view('admin.governance.reminders.index', [
        'page_title' => 'Reminders & Retry', 'page_eyebrow' => 'Governance', 'page_subtitle' => 'test',
    ])->render();
    preg_match_all('/\sid="([^"]+)"/', $html, $m);
    $ids = $m[1];
    $dupes = array_filter(array_count_values($ids), fn ($n) => $n > 1);
    expect($dupes)->toBeEmpty('duplicate IDs: ' . implode(', ', array_keys($dupes)));

    preg_match_all('/<label[^>]+for="([^"]+)"/', $html, $lm);
    foreach ($lm[1] as $forId) {
        if (str_starts_with($forId, 'gov-rem-')) {
            expect(in_array($forId, $ids, true))
                ->toBeTrue("<label for=\"{$forId}\"> has no matching id");
        }
    }
});
