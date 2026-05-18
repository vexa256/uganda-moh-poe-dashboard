<?php

/**
 * Admin · System (mail, mobile, who) + Clinical (diseases, symptoms)
 * contract tests — batch three.
 */

use Illuminate\Support\Facades\Route;

uses(Tests\TestCase::class);

const BATCH3_ROUTES = [
    // sys-mail
    'admin.system.mail.index'   => ['GET',  'admin/system/mail',         \App\Http\Controllers\Admin\System\MailController::class,   'index'],
    'admin.system.mail.summary' => ['GET',  'admin/system/mail/summary', \App\Http\Controllers\Admin\System\MailController::class,   'summary'],
    // sys-mobile
    'admin.system.mobile.index'   => ['GET', 'admin/system/mobile',         \App\Http\Controllers\Admin\System\MobileController::class, 'index'],
    'admin.system.mobile.summary' => ['GET', 'admin/system/mobile/summary', \App\Http\Controllers\Admin\System\MobileController::class, 'summary'],
    // sys-who
    'admin.system.who.index'    => ['GET',  'admin/system/who',          \App\Http\Controllers\Admin\System\WhoConnectorController::class, 'index'],
    'admin.system.who.contract' => ['GET',  'admin/system/who/contract', \App\Http\Controllers\Admin\System\WhoConnectorController::class, 'contract'],
    // clin-diseases
    'admin.clinical.diseases.index'   => ['GET',   'admin/clinical/diseases',            \App\Http\Controllers\Admin\Clinical\DiseasesController::class, 'index'],
    'admin.clinical.diseases.data'    => ['GET',   'admin/clinical/diseases/data',       \App\Http\Controllers\Admin\Clinical\DiseasesController::class, 'data'],
    'admin.clinical.diseases.show'    => ['GET',   'admin/clinical/diseases/{id}',       \App\Http\Controllers\Admin\Clinical\DiseasesController::class, 'show'],
    'admin.clinical.diseases.toggle'  => ['PATCH', 'admin/clinical/diseases/{id}/toggle',\App\Http\Controllers\Admin\Clinical\DiseasesController::class, 'toggle'],
    // clin-symptoms
    'admin.clinical.symptoms.index'   => ['GET',   'admin/clinical/symptoms',            \App\Http\Controllers\Admin\Clinical\SymptomsController::class, 'index'],
    'admin.clinical.symptoms.data'    => ['GET',   'admin/clinical/symptoms/data',       \App\Http\Controllers\Admin\Clinical\SymptomsController::class, 'data'],
    'admin.clinical.symptoms.show'    => ['GET',   'admin/clinical/symptoms/{id}',       \App\Http\Controllers\Admin\Clinical\SymptomsController::class, 'show'],
    'admin.clinical.symptoms.toggle'  => ['PATCH', 'admin/clinical/symptoms/{id}/toggle',\App\Http\Controllers\Admin\Clinical\SymptomsController::class, 'toggle'],
];

it('registers every batch-three route with the expected URI + controller method', function () {
    foreach (BATCH3_ROUTES as $name => [$method, $uri, $ctrl, $fn]) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' is not registered");
        expect($route->uri())->toBe($uri);
        expect(in_array($method, $route->methods(), true))->toBeTrue();
        expect($route->getAction('controller'))->toBe($ctrl . '@' . $fn);
    }
});

it('gates every batch-three route with NATIONAL_ADMIN + web + auth + scope', function () {
    foreach (array_keys(BATCH3_ROUTES) as $name) {
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

it('compiles every batch-three Blade view without syntax errors', function () {
    $compiler = app('view.engine.resolver')->resolve('blade')->getCompiler();
    foreach ([
        'admin/system/mail/index',
        'admin/system/mobile/index',
        'admin/system/who/index',
        'admin/clinical/diseases/index',
        'admin/clinical/symptoms/index',
    ] as $p) {
        $full = resource_path('views/' . $p . '.blade.php');
        expect(file_exists($full))->toBeTrue("missing: {$p}");
        $compiled = $compiler->compileString(file_get_contents($full));
        $tmp = tempnam(sys_get_temp_dir(), 'blade_b3_') . '.php';
        file_put_contents($tmp, $compiled);
        $lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
        @unlink($tmp);
        expect($lint)->toContain('No syntax errors');
    }
});

it('flips every batch-three sidebar entry to live', function () {
    $sidebar = file_get_contents(resource_path('views/admin/partials/sidebar.blade.php'));
    $pairs = [
        'sys-mail'       => 'system/mail',
        'sys-mobile'     => 'system/mobile',
        'sys-who'        => 'system/who',
        'clin-diseases'  => 'clinical/diseases',
        'clin-symptoms'  => 'clinical/symptoms',
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

it('renders sys-mail shell with transport + bounce anchors', function () {
    $html = view('admin.system.mail.index', [
        'page_title' => 'Mail Status', 'page_eyebrow' => 'System Health', 'page_subtitle' => 'test',
    ])->render();
    foreach (['mailPage()','Delivery ratio','Transport config','Top recipient domains',
              'Bounce &amp; fail register','Hourly','ring-gauge','kpi','tabs-trigger'] as $n) {
        expect($html)->toContain($n);
    }
});

it('renders sys-mobile shell with queue + platform + version anchors', function () {
    $html = view('admin.system.mobile.index', [
        'page_title' => 'Mobile Health', 'page_eyebrow' => 'System Health', 'page_subtitle' => 'test',
    ])->render();
    foreach (['mobilePage()','Overall sync health','Unsynced queue','Sync queue by table',
              'app_version','Platform mix','Sync throughput','Stalest devices',
              'ring-gauge','kpi','tabs-trigger'] as $n) {
        expect($html)->toContain($n);
    }
});

it('renders sys-who placeholder with readiness + interfaces + security anchors', function () {
    $html = view('admin.system.who.index', [
        'page_title' => 'WHO Connector', 'page_eyebrow' => 'System Health', 'page_subtitle' => 'test',
    ])->render();
    foreach (['whoPage()','NOT&nbsp;CONNECTED','Readiness checklist','Interfaces',
              'Security posture','Next actions'] as $n) {
        expect($html)->toContain($n);
    }
});

it('renders clin-diseases shell with tier donut + syndrome + detail anchors', function () {
    $html = view('admin.clinical.diseases.index', [
        'page_title' => 'Diseases', 'page_eyebrow' => 'Clinical Library', 'page_subtitle' => 'test',
    ])->render();
    foreach (['diseasesPage()','IHR tier mix','WHO syndrome distribution','Hallmark-gated',
              'Tier 1','Tier 2','Tier 3','kpi','tabs-trigger'] as $n) {
        expect($html)->toContain($n);
    }
});

it('renders clin-symptoms shell with categories + syndrome + detail anchors', function () {
    $html = view('admin.clinical.symptoms.index', [
        'page_title' => 'Symptoms', 'page_eyebrow' => 'Clinical Library', 'page_subtitle' => 'test',
    ])->render();
    foreach (['symptomsPage()','Red-flag','Hallmarks','By category','WHO syndrome tags',
              'Avg sensitivity','kpi','tabs-trigger'] as $n) {
        expect($html)->toContain($n);
    }
});

it('has no duplicate static IDs in any batch-three view', function () {
    $views = [
        'admin.system.mail.index'           => ['page_title'=>'T','page_eyebrow'=>'S','page_subtitle'=>'t'],
        'admin.system.mobile.index'         => ['page_title'=>'T','page_eyebrow'=>'S','page_subtitle'=>'t'],
        'admin.system.who.index'            => ['page_title'=>'T','page_eyebrow'=>'S','page_subtitle'=>'t'],
        'admin.clinical.diseases.index'     => ['page_title'=>'T','page_eyebrow'=>'C','page_subtitle'=>'t'],
        'admin.clinical.symptoms.index'     => ['page_title'=>'T','page_eyebrow'=>'C','page_subtitle'=>'t'],
    ];
    foreach ($views as $v => $data) {
        $html = view($v, $data)->render();
        preg_match_all('/\sid="([^"]+)"/', $html, $m);
        $ids = $m[1];
        $dupes = array_filter(array_count_values($ids), fn ($n) => $n > 1);
        expect($dupes)->toBeEmpty("{$v} · duplicate IDs: " . implode(', ', array_keys($dupes)));
    }
});
