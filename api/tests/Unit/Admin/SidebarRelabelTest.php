<?php

/**
 * Phase SB · Unit SB-1 · Sidebar Relabel
 * ---------------------------------------------------------------------------
 * Directive L24: the sidebar speaks WHO / public-health vocabulary. The
 * 13 rebrand targets are enumerated in the master directive.
 *
 * This test is the SB-1 crawler promised in the directive:
 *   (a) Renders the sidebar partial and asserts every new section name
 *       + every new item label is present.
 *   (b) Reads the layout partial and asserts NAV_CATALOG preserves the
 *       legacy labels as aliases so ⌘K still fuzzy-matches old terms.
 *   (c) Scans every admin/**.blade.php for lingering legacy labels
 *       (excluding admin/layout.blade.php, which legitimately retains
 *       them inside NAV_CATALOG aliases).
 *
 * Lives in tests/Unit/ on purpose: the base schema lives in app.sql (not
 * Laravel migrations), so Feature's RefreshDatabase against :memory: sqlite
 * aborts on unknown tables. This test only reads Blade sources and renders
 * one partial — no database needed — so Unit is the honest home.
 */

use Illuminate\Support\Facades\File;

uses(Tests\TestCase::class);

// ─────────────────────────────────────────────────────────────────────────
// NEW LABELS — what every admin surface must now show
// ─────────────────────────────────────────────────────────────────────────
const SB1_NEW_SECTIONS = [
    'Situation Awareness',
    'Alert & Response',
    'Epidemic Intelligence',
    'Case Management',
    'Risk Communication',
    'Surveillance Data',
    'Workforce & Geography',
    'Governance',
];

const SB1_NEW_ITEMS = [
    'Situation Room',
    'Event & Alert Management',
    'Early Response Performance 7-1-7',
    'Epidemic Intelligence Brief',
    'Suspected Case Register',
    'Communications Inbox',
    'Public Health Communications',
    'Stakeholders & Responders',
    'Surveillance Reporting Forms',
    'Surveillance Submissions',
    'Surveillance Workforce',
    'Jurisdictions & Contact Roster',
    'Audit & Oversight',
];

// ─────────────────────────────────────────────────────────────────────────
// LEGACY LABELS — must NOT appear anywhere except layout.blade.php
// (where they live inside NAV_CATALOG aliases for ⌘K fuzzy match).
// ─────────────────────────────────────────────────────────────────────────
const SB1_LEGACY_LABELS = [
    'Alert Hub',
    'Notification Inbox',
    'Personnel Directory',
    'Case Files Register',
    'Submissions Register',
    'Operations Dashboard',
    '7-1-7 Compliance Board',
    'National Intelligence Brief',
    'Outbound Communications',
    'External Responders',
    'Report Templates',
    'Audit Trail',
    'Assignments & Contact Roster',
];

it('sidebar renders every new section name', function () {
    $html = view('admin.partials.sidebar')->render();
    foreach (SB1_NEW_SECTIONS as $section) {
        // Blade escapes &, so compare against the HTML-encoded form.
        $needle = e($section);
        expect(str_contains($html, $needle))
            ->toBeTrue("sidebar missing section '{$section}' (looked for '{$needle}')");
    }
});

it('sidebar renders every new item label', function () {
    $html = view('admin.partials.sidebar')->render();
    foreach (SB1_NEW_ITEMS as $label) {
        $needle = e($label);
        expect(str_contains($html, $needle))
            ->toBeTrue("sidebar missing item label '{$label}' (looked for '{$needle}')");
    }
});

it('sidebar contains no legacy labels', function () {
    $html = view('admin.partials.sidebar')->render();
    foreach (SB1_LEGACY_LABELS as $legacy) {
        $needle = e($legacy);
        expect(str_contains($html, $needle))
            ->toBeFalse("sidebar still carries legacy label '{$legacy}'");
    }
});

it('NAV_CATALOG preserves legacy labels as aliases for palette fuzzy match', function () {
    // layout.blade.php embeds the NAV_CATALOG JS. We don't render the full
    // layout (it expects auth + session), so we read the file contents and
    // scope to the catalog block.
    $layout = file_get_contents(resource_path('views/admin/layout.blade.php'));
    $catalogStart = strpos($layout, 'const NAV_CATALOG');
    expect($catalogStart)->not->toBeFalse('NAV_CATALOG block missing from layout.blade.php');
    $catalogBlock = substr($layout, $catalogStart, strpos($layout, '];', $catalogStart) - $catalogStart);

    foreach (SB1_LEGACY_LABELS as $legacy) {
        $needle = strtolower($legacy);
        expect(str_contains(strtolower($catalogBlock), $needle))
            ->toBeTrue("NAV_CATALOG missing legacy alias for '{$legacy}' — ⌘K will not resolve old term");
    }

    // And every NEW label must be the primary label in the catalog.
    foreach (SB1_NEW_ITEMS as $label) {
        expect(str_contains($catalogBlock, "label:'{$label}'"))
            ->toBeTrue("NAV_CATALOG missing primary label '{$label}'");
    }
});

it('no admin view (except layout.blade.php) carries legacy labels', function () {
    $viewsDir = resource_path('views/admin');
    $files = collect(File::allFiles($viewsDir))
        ->filter(fn ($f) => $f->getExtension() === 'php')
        ->filter(fn ($f) => ! str_ends_with($f->getRelativePathname(), 'layout.blade.php'));

    $violations = [];
    foreach ($files as $file) {
        $content = file_get_contents($file->getRealPath());
        foreach (SB1_LEGACY_LABELS as $legacy) {
            if (str_contains($content, $legacy)) {
                $violations[] = "{$file->getRelativePathname()}: legacy label '{$legacy}'";
            }
        }
    }

    expect($violations)->toBeEmpty(
        "Legacy labels still present in admin views:\n" . implode("\n", $violations)
    );
});
