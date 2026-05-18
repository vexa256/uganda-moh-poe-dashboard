<?php

/**
 * Admin · Governance (templates, data-quality, retention) + System (cron)
 * contract tests — batch two. Same Unit/ rationale as the earlier files.
 */

use App\Services\NotificationDispatcher;
use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class);

const BATCH2_ROUTES = [
    // gov-templates
    'admin.governance.templates.index'        => ['GET',   'admin/governance/templates',
        \App\Http\Controllers\Admin\Governance\TemplatesController::class, 'index'],
    'admin.governance.templates.data'         => ['GET',   'admin/governance/templates/data',
        \App\Http\Controllers\Admin\Governance\TemplatesController::class, 'data'],
    'admin.governance.templates.preview'      => ['GET',   'admin/governance/templates/{id}/preview',
        \App\Http\Controllers\Admin\Governance\TemplatesController::class, 'preview'],
    'admin.governance.templates.preview.post' => ['POST',  'admin/governance/templates/{id}/preview',
        \App\Http\Controllers\Admin\Governance\TemplatesController::class, 'preview'],
    'admin.governance.templates.toggle'       => ['PATCH', 'admin/governance/templates/{id}/toggle',
        \App\Http\Controllers\Admin\Governance\TemplatesController::class, 'toggle'],
    // gov-dq
    'admin.governance.data-quality.index'      => ['GET', 'admin/governance/data-quality',
        \App\Http\Controllers\Admin\Governance\DataQualityController::class, 'index'],
    'admin.governance.data-quality.summary'    => ['GET', 'admin/governance/data-quality/summary',
        \App\Http\Controllers\Admin\Governance\DataQualityController::class, 'summary'],
    'admin.governance.data-quality.stragglers' => ['GET', 'admin/governance/data-quality/stragglers',
        \App\Http\Controllers\Admin\Governance\DataQualityController::class, 'stragglers'],
    // gov-retention
    'admin.governance.retention.index'          => ['GET',  'admin/governance/retention',
        \App\Http\Controllers\Admin\Governance\RetentionController::class, 'index'],
    'admin.governance.retention.summary'        => ['GET',  'admin/governance/retention/summary',
        \App\Http\Controllers\Admin\Governance\RetentionController::class, 'summary'],
    'admin.governance.retention.breached'       => ['GET',  'admin/governance/retention/breached',
        \App\Http\Controllers\Admin\Governance\RetentionController::class, 'breached'],
    'admin.governance.retention.exports.record' => ['POST', 'admin/governance/retention/exports',
        \App\Http\Controllers\Admin\Governance\RetentionController::class, 'recordExport'],
    // sys-cron
    'admin.system.cron.index'   => ['GET', 'admin/system/cron',
        \App\Http\Controllers\Admin\System\CronController::class, 'index'],
    'admin.system.cron.summary' => ['GET', 'admin/system/cron/summary',
        \App\Http\Controllers\Admin\System\CronController::class, 'summary'],
];

it('registers every batch-two route with the expected URI + controller method', function () {
    foreach (BATCH2_ROUTES as $name => [$method, $uri, $ctrl, $fn]) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' is not registered");
        expect($route->uri())->toBe($uri);
        expect(in_array($method, $route->methods(), true))
            ->toBeTrue("route '{$name}' does not accept {$method}");
        expect($route->getAction('controller'))->toBe($ctrl . '@' . $fn);
    }
});

it('gates every batch-two route with NATIONAL_ADMIN + web + auth + scope', function () {
    foreach (array_keys(BATCH2_ROUTES) as $name) {
        $route = Route::getRoutes()->getByName($name);
        $mw = $route->gatherMiddleware();
        $roleGated = collect($mw)->contains(fn ($m) => is_string($m)
            && str_starts_with($m, 'role:') && str_contains($m, 'NATIONAL_ADMIN'));
        expect($roleGated)->toBeTrue("route '{$name}' is missing role:NATIONAL_ADMIN");
        expect(in_array('web',   $mw, true))->toBeTrue();
        expect(in_array('auth',  $mw, true))->toBeTrue();
        expect(in_array('scope', $mw, true))->toBeTrue();
    }
});

it('compiles every batch-two Blade view without syntax errors', function () {
    $compiler = app('view.engine.resolver')->resolve('blade')->getCompiler();
    $paths = [
        'admin/governance/templates/index',
        'admin/governance/data-quality/index',
        'admin/governance/retention/index',
        'admin/system/cron/index',
    ];
    foreach ($paths as $p) {
        $full = resource_path('views/' . $p . '.blade.php');
        expect(file_exists($full))->toBeTrue("missing view: {$p}");
        $compiled = $compiler->compileString(file_get_contents($full));
        $tmp = tempnam(sys_get_temp_dir(), 'blade_b2_') . '.php';
        file_put_contents($tmp, $compiled);
        $lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);
        expect($lint)->toContain('No syntax errors');
    }
});

it('flips every batch-two sidebar entry to live', function () {
    $sidebar = file_get_contents(resource_path('views/admin/partials/sidebar.blade.php'));
    $pairs = [
        'gov-templates' => 'governance/templates',
        'gov-dq'        => 'governance/data-quality',
        'gov-retention' => 'governance/retention',
        'sys-cron'      => 'system/cron',
    ];
    foreach ($pairs as $key => $path) {
        expect($sidebar)->toContain("'{$key}'");
        expect($sidebar)->toContain("url('/admin/{$path}')");
        $escapedPath = preg_quote($path, '/');
        $pattern = "/'{$key}'[\\s\\S]+?url\\('\\/admin\\/{$escapedPath}'\\)\\s*,\\s*true\\s*\\)/";
        expect(preg_match($pattern, $sidebar))
            ->toBe(1, "sidebar entry '{$key}' not marked live at /admin/{$path}");
    }
});

it('renders gov-templates shell with preview + suppression dial anchors', function () {
    $html = view('admin.governance.templates.index', [
        'page_title' => 'Notif Templates', 'page_eyebrow' => 'Governance', 'page_subtitle' => 'test',
    ])->render();
    foreach ([
        'templatesPage()', 'Notification Templates', 'Suppression windows',
        '30-day usage', 'Preview', 'Source', 'Stats',
        'ring-gauge', 'kpi', 'tabs-trigger',
    ] as $needle) {
        expect($html)->toContain($needle);
    }
});

it('renders gov-dq shell with health-score + scorecard + trend anchors', function () {
    $html = view('admin.governance.data-quality.index', [
        'page_title' => 'Data Quality', 'page_eyebrow' => 'Governance', 'page_subtitle' => 'test',
    ])->render();
    foreach ([
        'dqPage()', 'Health score', 'Per-table scorecard', 'Creation vs void', 'Stragglers',
        'Duplicate UUIDs', 'ring-gauge', 'kpi', 'tabs-trigger',
    ] as $needle) {
        expect($html)->toContain($needle);
    }
});

it('renders gov-retention shell with age-histogram + export-log anchors', function () {
    $html = view('admin.governance.retention.index', [
        'page_title' => 'Retention & PII', 'page_eyebrow' => 'Governance', 'page_subtitle' => 'test',
        'retention_days' => 2555,
    ])->render();
    foreach ([
        'retentionPage()', 'Retention clock', 'PII coverage', 'Top nationalities',
        'New PII rows', 'Breached rows', 'Export log', 'kpi', 'tabs-trigger',
    ] as $needle) {
        expect($html)->toContain($needle);
    }
});

it('renders sys-cron shell with scheduler introspection anchors', function () {
    $html = view('admin.system.cron.index', [
        'page_title' => 'Cron Status', 'page_eyebrow' => 'System Health', 'page_subtitle' => 'test',
    ])->render();
    foreach ([
        'cronPage()', 'Scheduler status', 'Registered jobs', 'Overdue now',
        'CRON sends', 'Digest success', 'Next due', 'kpi',
    ] as $needle) {
        expect($html)->toContain($needle);
    }
});

it('has no duplicate static IDs in any batch-two view', function () {
    $viewData = [
        'admin.governance.templates.index'    => ['page_title' => 'T','page_eyebrow' => 'G','page_subtitle' => 't'],
        'admin.governance.data-quality.index' => ['page_title' => 'T','page_eyebrow' => 'G','page_subtitle' => 't'],
        'admin.governance.retention.index'    => ['page_title' => 'T','page_eyebrow' => 'G','page_subtitle' => 't', 'retention_days' => 2555],
        'admin.system.cron.index'             => ['page_title' => 'T','page_eyebrow' => 'S','page_subtitle' => 't'],
    ];
    foreach ($viewData as $view => $data) {
        $html = view($view, $data)->render();
        preg_match_all('/\sid="([^"]+)"/', $html, $m);
        $ids = $m[1];
        $dupes = array_filter(array_count_values($ids), fn ($n) => $n > 1);
        expect($dupes)->toBeEmpty("{$view} · duplicate IDs: " . implode(', ', array_keys($dupes)));
    }
});

it('exposes preview-ready suppression map for gov-templates', function () {
    $map = NotificationDispatcher::suppressionMinutesMap();
    expect($map)->toBeArray()->toHaveKeys(['ALERT_CRITICAL', 'FOLLOWUP_DUE', 'DAILY_REPORT']);
});
