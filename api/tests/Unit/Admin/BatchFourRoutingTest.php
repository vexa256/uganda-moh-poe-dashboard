<?php

/**
 * Admin · Clinical Library (exposures, boosts, endemic, vaccines) +
 * Intelligence (rank, geo, tripwires) contract tests — batch four.
 */

use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class);

const BATCH4_ROUTES = [
    // clin-exposures
    'admin.clinical.exposures.index'  => ['GET', 'admin/clinical/exposures',
        \App\Http\Controllers\Admin\Clinical\ExposuresController::class, 'index'],
    'admin.clinical.exposures.data'   => ['GET', 'admin/clinical/exposures/data',
        \App\Http\Controllers\Admin\Clinical\ExposuresController::class, 'data'],
    'admin.clinical.exposures.toggle' => ['PATCH', 'admin/clinical/exposures/{id}/toggle',
        \App\Http\Controllers\Admin\Clinical\ExposuresController::class, 'toggle'],
    // clin-boosts
    'admin.clinical.boosts.index'  => ['GET', 'admin/clinical/boosts',
        \App\Http\Controllers\Admin\Clinical\BoostsController::class, 'index'],
    'admin.clinical.boosts.data'   => ['GET', 'admin/clinical/boosts/data',
        \App\Http\Controllers\Admin\Clinical\BoostsController::class, 'data'],
    'admin.clinical.boosts.toggle' => ['PATCH', 'admin/clinical/boosts/{id}/toggle',
        \App\Http\Controllers\Admin\Clinical\BoostsController::class, 'toggle'],
    // clin-endemic
    'admin.clinical.endemic.index'  => ['GET', 'admin/clinical/endemic',
        \App\Http\Controllers\Admin\Clinical\EndemicController::class, 'index'],
    'admin.clinical.endemic.data'   => ['GET', 'admin/clinical/endemic/data',
        \App\Http\Controllers\Admin\Clinical\EndemicController::class, 'data'],
    'admin.clinical.endemic.level'  => ['PATCH', 'admin/clinical/endemic/{id}/level',
        \App\Http\Controllers\Admin\Clinical\EndemicController::class, 'updateLevel'],
    // clin-vaccines
    'admin.clinical.vaccines.index' => ['GET', 'admin/clinical/vaccines',
        \App\Http\Controllers\Admin\Clinical\VaccinesController::class, 'index'],
    'admin.clinical.vaccines.data'  => ['GET', 'admin/clinical/vaccines/data',
        \App\Http\Controllers\Admin\Clinical\VaccinesController::class, 'data'],
    // intel-rank
    'admin.intelligence.rank.index'   => ['GET', 'admin/intelligence/rank',
        \App\Http\Controllers\Admin\Intelligence\RankController::class, 'index'],
    'admin.intelligence.rank.summary' => ['GET', 'admin/intelligence/rank/summary',
        \App\Http\Controllers\Admin\Intelligence\RankController::class, 'summary'],
    // intel-geo
    'admin.intelligence.geo.index'    => ['GET', 'admin/intelligence/geo',
        \App\Http\Controllers\Admin\Intelligence\GeoController::class, 'index'],
    'admin.intelligence.geo.summary'  => ['GET', 'admin/intelligence/geo/summary',
        \App\Http\Controllers\Admin\Intelligence\GeoController::class, 'summary'],
    // intel-trip
    'admin.intelligence.tripwires.index'   => ['GET', 'admin/intelligence/tripwires',
        \App\Http\Controllers\Admin\Intelligence\TripwiresController::class, 'index'],
    'admin.intelligence.tripwires.summary' => ['GET', 'admin/intelligence/tripwires/summary',
        \App\Http\Controllers\Admin\Intelligence\TripwiresController::class, 'summary'],
];

it('registers every batch-four route with the expected URI + controller method', function () {
    foreach (BATCH4_ROUTES as $name => [$method, $uri, $ctrl, $fn]) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' missing");
        expect($route->uri())->toBe($uri);
        expect(in_array($method, $route->methods(), true))->toBeTrue();
        expect($route->getAction('controller'))->toBe($ctrl . '@' . $fn);
    }
});

it('gates every batch-four route with NATIONAL_ADMIN + web + auth + scope', function () {
    foreach (array_keys(BATCH4_ROUTES) as $name) {
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

it('compiles every batch-four Blade view without syntax errors', function () {
    $compiler = app('view.engine.resolver')->resolve('blade')->getCompiler();
    foreach ([
        'admin/clinical/exposures/index',
        'admin/clinical/boosts/index',
        'admin/clinical/endemic/index',
        'admin/clinical/vaccines/index',
        'admin/intelligence/rank/index',
        'admin/intelligence/geo/index',
        'admin/intelligence/tripwires/index',
    ] as $p) {
        $full = resource_path('views/' . $p . '.blade.php');
        expect(file_exists($full))->toBeTrue("missing: {$p}");
        $compiled = $compiler->compileString(file_get_contents($full));
        $tmp = tempnam(sys_get_temp_dir(), 'blade_b4_') . '.php';
        file_put_contents($tmp, $compiled);
        $lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);
        expect($lint)->toContain('No syntax errors');
    }
});

it('flips every batch-four sidebar entry to live', function () {
    $sidebar = file_get_contents(resource_path('views/admin/partials/sidebar.blade.php'));
    $pairs = [
        'clin-exposures' => 'clinical/exposures',
        'clin-boosts'    => 'clinical/boosts',
        'clin-endemic'   => 'clinical/endemic',
        'clin-vaccines'  => 'clinical/vaccines',
        'intel-rank'     => 'intelligence/rank',
        'intel-geo'      => 'intelligence/geo',
        'intel-trip'     => 'intelligence/tripwires',
    ];
    foreach ($pairs as $key => $path) {
        expect($sidebar)->toContain("'{$key}'");
        expect($sidebar)->toContain("url('/admin/{$path}')");
        $escapedPath = preg_quote($path, '/');
        $pattern = "/'{$key}'[\\s\\S]+?url\\('\\/admin\\/{$escapedPath}'\\)\\s*,\\s*true\\s*\\)/";
        expect(preg_match($pattern, $sidebar))
            ->toBe(1, "sidebar entry '{$key}' not live at /admin/{$path}");
    }
});

it('renders clin-exposures shell with anchors', function () {
    $html = view('admin.clinical.exposures.index',
        ['page_title'=>'Exposures','page_eyebrow'=>'Clinical','page_subtitle'=>'t'])->render();
    foreach (['exposuresPage()','Engine-code hit parade','Response shapes','High-risk','kpi','tabs-trigger']
      as $n) expect($html)->toContain($n);
});

it('renders clin-boosts shell with anchors', function () {
    $html = view('admin.clinical.boosts.index',
        ['page_title'=>'Scoring Rules','page_eyebrow'=>'Clinical','page_subtitle'=>'t'])->render();
    foreach (['boostsPage()','Top disease boosts','By section','Highest boost','kpi'] as $n) expect($html)->toContain($n);
});

it('renders clin-endemic shell with anchors', function () {
    $html = view('admin.clinical.endemic.index',
        ['page_title'=>'Endemic Map','page_eyebrow'=>'Clinical','page_subtitle'=>'t',
         'levels'=>['ENDEMIC','OUTBREAK_ACTIVE','OUTBREAK_RECENT','SPORADIC','IMPORTED_ONLY']])->render();
    foreach (['endemicPage()','Endemicity mix','Top diseases by outbreak pressure','kpi'] as $n) expect($html)->toContain($n);
});

it('renders clin-vaccines shell with anchors', function () {
    $html = view('admin.clinical.vaccines.index',
        ['page_title'=>'Vaccines','page_eyebrow'=>'Clinical','page_subtitle'=>'t'])->render();
    foreach (['vaccinesPage()','Documentation rules','By vaccine','Yellow-fever endemic','kpi'] as $n) expect($html)->toContain($n);
});

it('renders intel-rank shell with anchors', function () {
    $html = view('admin.intelligence.rank.index',
        ['page_title'=>'Disease Ranking','page_eyebrow'=>'Intelligence','page_subtitle'=>'t'])->render();
    foreach (['rankPage()','Top-5 disease trend','Confidence bands','30-day disease ranking','kpi'] as $n) expect($html)->toContain($n);
});

it('renders intel-geo shell with anchors', function () {
    $html = view('admin.intelligence.geo.index',
        ['page_title'=>'Heatmap &amp; PoEs','page_eyebrow'=>'Intelligence','page_subtitle'=>'t'])->render();
    foreach (['geoPage()','Throughput trend','By province','Transport mix','PoE benchmark','kpi'] as $n) expect($html)->toContain($n);
});

it('renders intel-trip shell with anchors', function () {
    $html = view('admin.intelligence.tripwires.index',
        ['page_title'=>'Tripwires','page_eyebrow'=>'Intelligence','page_subtitle'=>'t'])->render();
    foreach (['tripwiresPage()','Tripwire health','Stuck Alerts','Silent PoEs','Case Spikes','Unsubmitted','ring-gauge','kpi'] as $n) expect($html)->toContain($n);
});

it('has no duplicate static IDs in any batch-four view', function () {
    $views = [
        'admin.clinical.exposures.index'      => ['page_title'=>'T','page_eyebrow'=>'C','page_subtitle'=>'t'],
        'admin.clinical.boosts.index'         => ['page_title'=>'T','page_eyebrow'=>'C','page_subtitle'=>'t'],
        'admin.clinical.endemic.index'        => ['page_title'=>'T','page_eyebrow'=>'C','page_subtitle'=>'t','levels'=>['ENDEMIC']],
        'admin.clinical.vaccines.index'       => ['page_title'=>'T','page_eyebrow'=>'C','page_subtitle'=>'t'],
        'admin.intelligence.rank.index'       => ['page_title'=>'T','page_eyebrow'=>'I','page_subtitle'=>'t'],
        'admin.intelligence.geo.index'        => ['page_title'=>'T','page_eyebrow'=>'I','page_subtitle'=>'t'],
        'admin.intelligence.tripwires.index'  => ['page_title'=>'T','page_eyebrow'=>'I','page_subtitle'=>'t'],
    ];
    foreach ($views as $v => $data) {
        $html = view($v, $data)->render();
        preg_match_all('/\sid="([^"]+)"/', $html, $m);
        $ids = $m[1];
        $dupes = array_filter(array_count_values($ids), fn ($n) => $n > 1);
        expect($dupes)->toBeEmpty("{$v} · duplicate IDs: " . implode(', ', array_keys($dupes)));
    }
});
