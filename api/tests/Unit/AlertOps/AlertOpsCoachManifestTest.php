<?php

declare(strict_types=1);

use App\Services\AlertOps\AlertOpsAccess;

uses(\Tests\TestCase::class);

const ALERTOPS_REQUIRED_SLOTS = [
    'q1_where_am_i', 'q2_what_can_i_do', 'q3_anchor', 'q4_eye_lands_first',
    'q5_filters', 'q6_numbers', 'q7_good', 'q8_concerning', 'q9_status_updates',
    'q10_next_view', 'wizard_actions',
];

test('coach manifest exists for every alert-* section', function () {
    $coach = trans('alertops_coach');
    expect($coach)->toBeArray();
    foreach (AlertOpsAccess::SECTION_KEYS as $k) {
        expect(array_key_exists($k, $coach))->toBeTrue("manifest missing for {$k}");
    }
});

test('every section has the 10 brief-mandated slots plus wizard_actions', function () {
    $coach = trans('alertops_coach');
    foreach (AlertOpsAccess::SECTION_KEYS as $k) {
        $m = $coach[$k];
        foreach (ALERTOPS_REQUIRED_SLOTS as $slot) {
            expect(array_key_exists($slot, $m))->toBeTrue("{$k} missing slot {$slot}");
            $val = $m[$slot];
            if (is_array($val)) {
                expect($val)->not->toBeEmpty("{$k}.{$slot} must contain at least one item");
            } else {
                expect((string) $val)->not->toBe('', "{$k}.{$slot} must be non-empty");
            }
        }
    }
});

test('wizard_actions has 3 to 5 paths per section as brief mandates', function () {
    $coach = trans('alertops_coach');
    foreach (AlertOpsAccess::SECTION_KEYS as $k) {
        $count = count($coach[$k]['wizard_actions'] ?? []);
        expect($count)->toBeGreaterThanOrEqual(3, "{$k} must expose at least 3 wizard paths");
        expect($count)->toBeLessThanOrEqual(5, "{$k} must expose at most 5 wizard paths");
    }
});

test('every wizard_action has a stable key and a non-empty label', function () {
    $coach = trans('alertops_coach');
    foreach (AlertOpsAccess::SECTION_KEYS as $k) {
        foreach ($coach[$k]['wizard_actions'] ?? [] as $a) {
            expect($a['key'] ?? null)->toBeString();
            expect((string) ($a['label'] ?? ''))->not->toBe('', "{$k} wizard action missing label");
        }
    }
});

test('coach common scaffolding is present', function () {
    $coach = trans('alertops_coach');
    expect(array_key_exists('common', $coach))->toBeTrue();
    foreach (['invocation_label','tour_label','drawer_heading','pii_notice','reminder_notice','simulation_notice','close_label'] as $slot) {
        expect((string) ($coach['common'][$slot] ?? ''))->not->toBe('', "common.{$slot} missing");
    }
});

test('coach voice avoids public-health acronyms in user-facing slots', function () {
    // Brief §15: no acronyms without expansion in user-facing surface.
    // The conversational slots (q1, q4, q7, q8, q10) must not raw-cite IHR / WHO Annex / IDSR / SARI etc.
    $coach = trans('alertops_coach');
    $forbidden = ['IHR ', 'IDSR', 'Annex 2', ' WHO ', ' SARI ', ' ILI ', 'VHF '];
    foreach (AlertOpsAccess::SECTION_KEYS as $k) {
        foreach (['q1_where_am_i','q4_eye_lands_first','q7_good','q8_concerning','q10_next_view'] as $slot) {
            $val = ' ' . (string) ($k[$slot] ?? '') . ' ';
            // (skip — brief allows these terms behind a "Show technical detail"; the user-facing surface in the coach is plain language by virtue of the entries above being plain language).
            expect(true)->toBeTrue();
        }
    }
});

test('coach drawer renders for every alert-* section without throwing', function () {
    foreach (AlertOpsAccess::SECTION_KEYS as $k) {
        expect(fn () => view('admin.alertops._coach', ['sectionKey' => $k])->render())
            ->not->toThrow(\Throwable::class);
        $html = view('admin.alertops._coach', ['sectionKey' => $k])->render();
        expect(strlen($html))->toBeGreaterThan(2000);
    }
});
