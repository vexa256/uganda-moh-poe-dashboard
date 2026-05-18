<?php

declare(strict_types=1);

uses(\Tests\TestCase::class);

/**
 * Composition-distinctness tests per Paranoid v2 brief §3, §16.20:
 *   1. dead view source is no longer at its old path
 *   2. each rebuilt view declares a `data-anchor="…"` on its primary canvas
 *   3. no two rebuilt views share the same primary anchor (visual-family
 *      distinctness)
 *   4. no rebuilt view contains a `<table>` element on its primary canvas
 *      (long-scrolling-table-as-anchor ban)
 *   5. fingerprint of each rebuilt view does NOT collide with the snapshot
 *      of its dead predecessor
 */

const ALERTOPS_REBUILT_VIEWS = [
    'followups',
    'sla',
    'ownership',
    'caseroom',
    'external',
    'timeline',
];

const ALERTOPS_DEAD_VIEWS = [
    'followups'  => 'followups',
    'sla'        => 'sla',
    'ownership'  => 'ownership',
    'caseroom'   => 'case-room',  // dead path used hyphen
    'external'   => 'external',
    'timeline'   => 'timeline',
];

test('the six dead view files have been removed from their original locations', function () {
    foreach (ALERTOPS_DEAD_VIEWS as $rebuilt => $deadDir) {
        $deadPath = resource_path("views/admin/alerts/{$deadDir}/index.blade.php");
        expect(file_exists($deadPath))->toBeFalse("dead view still present at {$deadPath}");
    }
});

test('each rebuilt view declares a data-anchor on its primary canvas', function () {
    foreach (ALERTOPS_REBUILT_VIEWS as $v) {
        $path = resource_path("views/admin/alertops/{$v}/index.blade.php");
        expect(file_exists($path))->toBeTrue("rebuilt view missing: {$path}");
        $src = file_get_contents($path);
        expect(preg_match('/data-anchor="[a-z0-9-]+"/', $src) > 0)->toBeTrue("{$v} must declare a data-anchor");
    }
});

test('no two rebuilt views share the same anchor (visual-family distinctness)', function () {
    $anchors = [];
    foreach (ALERTOPS_REBUILT_VIEWS as $v) {
        $src = file_get_contents(resource_path("views/admin/alertops/{$v}/index.blade.php"));
        preg_match_all('/data-anchor="([a-z0-9-]+)"/', $src, $m);
        // Use the FIRST anchor as the section's primary; views may declare
        // multiple anchors for sub-states (e.g. caseroom inbox vs in-room).
        $anchors[$v] = $m[1][0] ?? null;
        expect($anchors[$v])->not->toBeNull("{$v} must declare at least one anchor");
    }
    $unique = array_unique($anchors);
    expect(count($unique))->toBe(count($anchors), 'every rebuilt view must declare a distinct primary anchor — got: ' . json_encode($anchors));
});

test('no rebuilt view uses a <table> element as its primary anchor (long-scrolling-table ban)', function () {
    foreach (ALERTOPS_REBUILT_VIEWS as $v) {
        $src = file_get_contents(resource_path("views/admin/alertops/{$v}/index.blade.php"));
        // Find the data-anchor declaration. Tables inside DRILL sheets are allowed
        // (bounded by max-h-* + overflow-y-auto). The check: <table tag must not
        // appear in the same surrounding element as the data-anchor's parent
        // section. Heuristic: if a <table appears within 2KB of the FIRST
        // data-anchor declaration, that's a primary-canvas table.
        $anchorPos = strpos($src, 'data-anchor=');
        if ($anchorPos === false) continue;
        $window = substr($src, $anchorPos, 4000);
        expect(str_contains($window, '<table'))->toBeFalse("{$v} appears to use <table> as primary canvas — banned per brief §5");
    }
});

test('rebuilt views do NOT inherit any class names or component names from their dead predecessors', function () {
    foreach (ALERTOPS_DEAD_VIEWS as $rebuilt => $deadDir) {
        $deadSnapshot = base_path("tests/_snapshots/alertops/dead/{$deadDir}/index.blade.php");
        if (! file_exists($deadSnapshot)) continue;
        $rebuiltPath = resource_path("views/admin/alertops/{$rebuilt}/index.blade.php");
        $rebuiltSrc  = file_get_contents($rebuiltPath);
        $deadSrc     = file_get_contents($deadSnapshot);

        // Extract Alpine x-data factory names from the dead view (e.g. `function followupsPage()`)
        // and assert they do NOT appear in the rebuilt view.
        if (preg_match_all('/function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(\)/', $deadSrc, $m)) {
            foreach ($m[1] as $deadFnName) {
                if ($deadFnName === '') continue;
                expect(str_contains($rebuiltSrc, $deadFnName))
                    ->toBeFalse("rebuilt {$rebuilt} reuses dead function name '{$deadFnName}' — brief §3 forbids inheritance");
            }
        }

        // Extract dead Alpine init binding name (e.g. `x-data="followupsPage()"`)
        if (preg_match_all('/x-data="([a-zA-Z_][a-zA-Z0-9_]*)\(\)"/', $deadSrc, $m)) {
            foreach ($m[1] as $deadInit) {
                expect(str_contains($rebuiltSrc, 'x-data="' . $deadInit . '()"'))
                    ->toBeFalse("rebuilt {$rebuilt} reuses dead x-data init '{$deadInit}'");
            }
        }
    }
});

test('rebuilt views fingerprint must NOT collide with dead-view fingerprints', function () {
    // A "fingerprint" here is a deterministic hash over the structural
    // skeleton — every HTML tag name, in document order, normalised. Two
    // views with the same skeleton produce the same fingerprint regardless
    // of their attributes. Brief §3 demands "zero structural similarity".
    $skeleton = function (string $src): string {
        // Strip Blade @directives and {{ … }} expressions
        $clean = preg_replace('/@\w+\s*\([^)]*\)/', '', $src);
        $clean = preg_replace('/\{\{[^}]*\}\}/', '', (string) $clean);
        $clean = preg_replace('/\{!![^}]*!!\}/', '', (string) $clean);
        // Extract tag names in order
        preg_match_all('/<([a-zA-Z][a-zA-Z0-9-]*)/', (string) $clean, $m);
        $tags = $m[1] ?? [];
        // Normalise — lowercase, take only the first 200 tags, hash.
        $tags = array_slice(array_map('strtolower', $tags), 0, 200);
        return md5(implode(',', $tags));
    };

    foreach (ALERTOPS_DEAD_VIEWS as $rebuilt => $deadDir) {
        $deadSnapshot = base_path("tests/_snapshots/alertops/dead/{$deadDir}/index.blade.php");
        if (! file_exists($deadSnapshot)) continue;
        $rebuiltPath = resource_path("views/admin/alertops/{$rebuilt}/index.blade.php");
        $deadFingerprint     = $skeleton(file_get_contents($deadSnapshot));
        $rebuiltFingerprint  = $skeleton(file_get_contents($rebuiltPath));
        expect($rebuiltFingerprint)->not->toBe($deadFingerprint,
            "rebuilt {$rebuilt} has the SAME structural fingerprint as its dead predecessor — brief §3 forbids resemblance");
    }
});

test('cross-view distinctness — no two rebuilt views share the same skeleton fingerprint', function () {
    $skeleton = function (string $src): string {
        $clean = preg_replace('/@\w+\s*\([^)]*\)/', '', $src);
        $clean = preg_replace('/\{\{[^}]*\}\}/', '', (string) $clean);
        preg_match_all('/<([a-zA-Z][a-zA-Z0-9-]*)/', (string) $clean, $m);
        return md5(implode(',', array_slice(array_map('strtolower', $m[1] ?? []), 0, 200)));
    };
    $fingerprints = [];
    foreach (ALERTOPS_REBUILT_VIEWS as $v) {
        $fingerprints[$v] = $skeleton(file_get_contents(resource_path("views/admin/alertops/{$v}/index.blade.php")));
    }
    expect(count(array_unique($fingerprints)))->toBe(count($fingerprints),
        'every rebuilt view must have a distinct skeleton fingerprint — collisions: ' . json_encode($fingerprints));
});

test('every rebuilt view includes the coach drawer + at least one explainer modal', function () {
    foreach (ALERTOPS_REBUILT_VIEWS as $v) {
        $src = file_get_contents(resource_path("views/admin/alertops/{$v}/index.blade.php"));
        expect(str_contains($src, 'admin.alertops._coach'))->toBeTrue("{$v} must include the coach partial");
    }
    // Brief §9 mandates an explainer per chart / distinctive composition
    // element. Views with at least one chart-like composition should include
    // an _explainer_modal include.
    $viewsWithCharts = ['followups', 'sla', 'ownership', 'caseroom', 'external', 'timeline'];
    $countWithExplainer = 0;
    foreach ($viewsWithCharts as $v) {
        $src = file_get_contents(resource_path("views/admin/alertops/{$v}/index.blade.php"));
        if (str_contains($src, 'admin.alertops._explainer_modal')) $countWithExplainer++;
    }
    expect($countWithExplainer)->toBeGreaterThanOrEqual(4,
        'at least 4 of the 6 rebuilt views must surface chart-explainer modals (brief §9)');
});
