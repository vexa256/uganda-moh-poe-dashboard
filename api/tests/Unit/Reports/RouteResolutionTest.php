<?php

declare(strict_types=1);

// The project's global Pest.php binds RefreshDatabase to every Feature test,
// but one pre-existing migration (add_close_taxonomy_to_alerts) assumes the
// alerts table exists — it is shipped in database/app.sql, not as a migration.
// This test has no schema dependency, so we opt out of RefreshDatabase and
// still boot the framework via the standard TestCase.
uses(\Tests\TestCase::class);

test('every admin.reports named route resolves to a URL', function () {
    $names = ['admin.reports.meta'];
    foreach (['rpt-volume', 'rpt-suspected', 'rpt-geo', 'rpt-contact-tracing', 'rpt-registry', 'rpt-age-gender', 'rpt-symptom-exposure'] as $key) {
        foreach (['index', 'data', 'export'] as $action) {
            $names[] = "admin.reports.{$key}.{$action}";
        }
    }
    foreach ($names as $name) {
        expect(fn () => route($name))->not->toThrow(\Throwable::class, "route {$name} must resolve");
    }
});

test('admin/reports prefix has the expected named routes registered', function () {
    $urls = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri())->filter(fn ($u) => str_starts_with($u, 'admin/reports'))->values()->all();
    foreach ([
        'admin/reports/meta',
        'admin/reports/rpt-volume',
        'admin/reports/rpt-volume/data',
        'admin/reports/rpt-volume/export',
        'admin/reports/rpt-suspected',
        'admin/reports/rpt-geo',
        'admin/reports/rpt-contact-tracing',
        'admin/reports/rpt-registry',
        'admin/reports/rpt-age-gender',
        'admin/reports/rpt-symptom-exposure',
    ] as $expected) {
        expect($urls)->toContain($expected);
    }
});
