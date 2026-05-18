<?php

/**
 * Admin · Intelligence (digests, copilot) contract tests — batch five (final).
 */

use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class);

const BATCH5_ROUTES = [
    // intel-digests
    'admin.intelligence.digests.index'   => ['GET',  'admin/intelligence/digests',
        \App\Http\Controllers\Admin\Intelligence\DigestsController::class, 'index'],
    'admin.intelligence.digests.summary' => ['GET',  'admin/intelligence/digests/summary',
        \App\Http\Controllers\Admin\Intelligence\DigestsController::class, 'summary'],
    'admin.intelligence.digests.preview' => ['POST', 'admin/intelligence/digests/preview',
        \App\Http\Controllers\Admin\Intelligence\DigestsController::class, 'preview'],
    'admin.intelligence.digests.trigger' => ['POST', 'admin/intelligence/digests/trigger',
        \App\Http\Controllers\Admin\Intelligence\DigestsController::class, 'trigger'],
    // intel-copilot
    'admin.intelligence.copilot.index'   => ['GET',  'admin/intelligence/copilot',
        \App\Http\Controllers\Admin\Intelligence\CopilotController::class, 'index'],
    'admin.intelligence.copilot.summary' => ['GET',  'admin/intelligence/copilot/summary',
        \App\Http\Controllers\Admin\Intelligence\CopilotController::class, 'summary'],
    'admin.intelligence.copilot.narrate' => ['GET',  'admin/intelligence/copilot/alerts/{id}/narrate',
        \App\Http\Controllers\Admin\Intelligence\CopilotController::class, 'narrate'],
    'admin.intelligence.copilot.ask'     => ['POST', 'admin/intelligence/copilot/ask',
        \App\Http\Controllers\Admin\Intelligence\CopilotController::class, 'ask'],
];

it('registers every batch-five route with the expected URI + controller method', function () {
    foreach (BATCH5_ROUTES as $name => [$method, $uri, $ctrl, $fn]) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' missing");
        expect($route->uri())->toBe($uri);
        expect(in_array($method, $route->methods(), true))->toBeTrue();
        expect($route->getAction('controller'))->toBe($ctrl . '@' . $fn);
    }
});

it('gates every batch-five route with NATIONAL_ADMIN + web + auth + scope', function () {
    foreach (array_keys(BATCH5_ROUTES) as $name) {
        $route = Route::getRoutes()->getByName($name);
        $mw = $route->gatherMiddleware();
        $roleGated = collect($mw)->contains(fn ($m) => is_string($m)
            && str_starts_with($m, 'role:') && str_contains($m, 'NATIONAL_ADMIN'));
        expect($roleGated)->toBeTrue("route '{$name}' missing role:NATIONAL_ADMIN");
        expect(in_array('web',   $mw, true))->toBeTrue();
        expect(in_array('auth',  $mw, true))->toBeTrue();
        expect(in_array('scope', $mw, true))->toBeTrue();
    }
});

it('compiles every batch-five Blade view without syntax errors', function () {
    $compiler = app('view.engine.resolver')->resolve('blade')->getCompiler();
    foreach ([
        'admin/intelligence/digests/index',
        'admin/intelligence/copilot/index',
    ] as $p) {
        $full = resource_path('views/' . $p . '.blade.php');
        expect(file_exists($full))->toBeTrue("missing: {$p}");
        $compiled = $compiler->compileString(file_get_contents($full));
        $tmp = tempnam(sys_get_temp_dir(), 'blade_b5_') . '.php';
        file_put_contents($tmp, $compiled);
        $lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);
        expect($lint)->toContain('No syntax errors');
    }
});

it('flips every batch-five sidebar entry to live', function () {
    $sidebar = file_get_contents(resource_path('views/admin/partials/sidebar.blade.php'));
    foreach ([
        'intel-digests' => 'intelligence/digests',
        'intel-copilot' => 'intelligence/copilot',
    ] as $key => $path) {
        expect($sidebar)->toContain("'{$key}'");
        expect($sidebar)->toContain("url('/admin/{$path}')");
        $escapedPath = preg_quote($path, '/');
        $pattern = "/'{$key}'[\\s\\S]+?url\\('\\/admin\\/{$escapedPath}'\\)\\s*,\\s*true\\s*\\)/";
        expect(preg_match($pattern, $sidebar))
            ->toBe(1, "sidebar entry '{$key}' not live at /admin/{$path}");
    }
});

it('renders intel-digests shell with preview + trigger + history anchors', function () {
    $html = view('admin.intelligence.digests.index',
        ['page_title'=>'Digest Builder','page_eyebrow'=>'Intelligence','page_subtitle'=>'t'])->render();
    foreach (['digestsPage()','Cron history','Sends · 14-day trend','Manual trigger',
              'Preview','kpi','tabs-trigger'] as $n) {
        expect($html)->toContain($n);
    }
});

it('renders intel-copilot shell with recommendations + ask + narrator anchors', function () {
    $html = view('admin.intelligence.copilot.index',
        ['page_title'=>'Copilot','page_eyebrow'=>'Intelligence','page_subtitle'=>'t'])->render();
    foreach (['copilotPage()','Next-best actions','National triage brief','Copilot rules',
              'Ask the copilot','Open alerts','Narrative + differentials','kpi'] as $n) {
        expect($html)->toContain($n);
    }
});

it('has no duplicate static IDs in any batch-five view', function () {
    foreach ([
        'admin.intelligence.digests.index' => ['page_title'=>'T','page_eyebrow'=>'I','page_subtitle'=>'t'],
        'admin.intelligence.copilot.index' => ['page_title'=>'T','page_eyebrow'=>'I','page_subtitle'=>'t'],
    ] as $v => $data) {
        $html = view($v, $data)->render();
        preg_match_all('/\sid="([^"]+)"/', $html, $m);
        $ids = $m[1];
        $dupes = array_filter(array_count_values($ids), fn ($n) => $n > 1);
        expect($dupes)->toBeEmpty("{$v} · duplicate IDs: " . implode(', ', array_keys($dupes)));
    }
});

it('confirms the digest trigger uses a write-method + audit-safe middleware', function () {
    $route = Route::getRoutes()->getByName('admin.intelligence.digests.trigger');
    expect($route)->not->toBeNull();
    expect(in_array('POST', $route->methods(), true))->toBeTrue();
    // The manual trigger is the single non-read write in this batch.
    $mw = $route->gatherMiddleware();
    expect(in_array('web', $mw, true))->toBeTrue();
    expect(in_array('auth', $mw, true))->toBeTrue();
});

it('uses the existing PheocCopilot service through the intel-copilot controller', function () {
    $ctrl = app(\App\Http\Controllers\Admin\Intelligence\CopilotController::class);
    $ref = new \ReflectionClass($ctrl);
    $ctor = $ref->getConstructor();
    expect($ctor)->not->toBeNull();
    $params = array_map(fn ($p) => (string) $p->getType(), $ctor->getParameters());
    expect(in_array(\App\Services\PheocCopilot::class, $params, true))->toBeTrue();
    expect(in_array(\App\Services\PheocScope::class,  $params, true))->toBeTrue();
});
