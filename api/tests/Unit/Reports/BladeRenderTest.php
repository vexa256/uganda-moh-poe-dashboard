<?php

declare(strict_types=1);

// Opt out of RefreshDatabase — see RouteResolutionTest.php for reason.
uses(\Tests\TestCase::class);

test('each My Reports Blade compiles without error', function () {
    $paths = [
        'admin.reports._insights',
        'admin.reports._data_notes',
        'admin.reports._filter_wizard',
        'admin.reports.rpt-volume.index',
        'admin.reports.rpt-suspected.index',
        'admin.reports.rpt-geo.index',
        'admin.reports.rpt-contact-tracing.index',
        'admin.reports.rpt-registry.index',
        'admin.reports.rpt-age-gender.index',
        'admin.reports.rpt-symptom-exposure.index',
    ];
    $compiler = app('view.engine.resolver')->resolve('blade')->getCompiler();
    foreach ($paths as $p) {
        $file = resource_path('views/' . str_replace('.', '/', $p) . '.blade.php');
        expect(file_exists($file))->toBeTrue("Blade view must exist: {$file}");
        expect(fn () => $compiler->compileString(file_get_contents($file)))->not->toThrow(\Throwable::class, "{$p} must compile");
    }
});
