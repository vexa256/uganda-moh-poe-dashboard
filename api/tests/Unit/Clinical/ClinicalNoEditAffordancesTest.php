<?php

declare(strict_types=1);

uses(\Tests\TestCase::class);

/**
 * No-edit-affordances assertion per Paranoid v2 brief §15.23 + §14.
 *
 * The clinical library rebuild is read-only. Every UI affordance that
 * implies a write (toggle, publish, retire, edit, delete, flip, save)
 * must be absent from every rendered Blade.
 */
test('no clin-* view contains an edit / mutation affordance', function () {
    $forbidden = [
        'toggleActive',
        'updateLevel',
        '/toggle',
        'flip(',
        '@click="save',
        '@click="delete',
        '@click="publish',
        '@click="retire',
    ];
    foreach (['diseases','symptoms','exposures','boosts','endemic','vaccines'] as $section) {
        $path = resource_path("views/admin/clinical/{$section}/index.blade.php");
        expect(file_exists($path))->toBeTrue("missing view: {$path}");
        $src = file_get_contents($path);
        foreach ($forbidden as $needle) {
            expect(str_contains($src, $needle))->toBeFalse("{$section}/index.blade.php must not contain {$needle} (edit affordance forbidden)");
        }
    }
});

test('legacy mutation endpoints return 403 with the canonical read-only error code', function () {
    $controllers = [
        \App\Http\Controllers\Admin\Clinical\DiseasesController::class  => 'toggle',
        \App\Http\Controllers\Admin\Clinical\SymptomsController::class  => 'toggle',
        \App\Http\Controllers\Admin\Clinical\ExposuresController::class => 'toggle',
        \App\Http\Controllers\Admin\Clinical\BoostsController::class    => 'toggle',
        \App\Http\Controllers\Admin\Clinical\EndemicController::class   => 'updateLevel',
    ];
    foreach ($controllers as $cls => $method) {
        $req = \Illuminate\Http\Request::create('/admin/clinical/x/' . $method, 'PATCH');
        $resp = app($cls)->{$method}($req, 1);
        expect($resp->getStatusCode())->toBe(403, "{$cls}::{$method} must return 403");
        $body = json_decode((string) $resp->getContent(), true);
        expect($body['code'] ?? null)->toBe('CLINICAL_READ_ONLY', "{$cls}::{$method} must surface CLINICAL_READ_ONLY");
    }
});

test('every clin-* view includes the coach drawer trigger and at least one interpretation modal', function () {
    foreach (['diseases','symptoms','exposures','boosts','endemic','vaccines'] as $section) {
        $src = file_get_contents(resource_path("views/admin/clinical/{$section}/index.blade.php"));
        expect(str_contains($src, "admin.clinical._coach"))->toBeTrue("{$section} must include the coach partial");
    }
    // Tabs that should host an interpretation modal — at least one chart
    // tab per view needs to expose the "How to read this" affordance.
    foreach (['diseases','symptoms','exposures','boosts','endemic'] as $section) {
        $src = file_get_contents(resource_path("views/admin/clinical/{$section}/index.blade.php"));
        expect(str_contains($src, "admin.clinical._interpretation_modal"))->toBeTrue("{$section} must include at least one interpretation modal");
    }
});

test('no clin-* view hard-codes a disease, symptom, exposure, or endemic count', function () {
    foreach (['diseases','symptoms','exposures','boosts','endemic','vaccines'] as $section) {
        $src = file_get_contents(resource_path("views/admin/clinical/{$section}/index.blade.php"));
        foreach (['42 pathogens', '42 diseases', '89 symptoms', '29 exposures', '12 rules', '1154', '1155'] as $needle) {
            expect(str_contains($src, $needle))->toBeFalse("{$section}/index.blade.php must not hard-code count: {$needle}");
        }
    }
});
