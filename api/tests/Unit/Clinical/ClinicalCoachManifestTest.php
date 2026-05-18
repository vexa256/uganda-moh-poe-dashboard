<?php

declare(strict_types=1);

use App\Services\Clinical\ClinicalAccess;

uses(\Tests\TestCase::class);

const CLIN_REQUIRED_SLOTS = [
    'q1_where_am_i', 'q2_what_can_i_do', 'q3_tabs', 'q4_charts',
    'q5_eye_lands_first', 'q6_filters', 'q7_numbers', 'q8_concerning',
    'q9_data_quality', 'q10_next_view',
];

test('coach manifest exists for every clinical section key', function () {
    $coach = trans('clinical_coach');
    expect($coach)->toBeArray();
    foreach (ClinicalAccess::SECTION_KEYS as $k) {
        expect(array_key_exists($k, $coach))->toBeTrue("manifest missing for {$k}");
    }
});

test('every section has all 10 brief-mandated coach slots non-empty', function () {
    $coach = trans('clinical_coach');
    foreach (ClinicalAccess::SECTION_KEYS as $k) {
        $m = $coach[$k];
        foreach (CLIN_REQUIRED_SLOTS as $slot) {
            expect(array_key_exists($slot, $m))->toBeTrue("{$k} missing slot {$slot}");
            $val = $m[$slot];
            if (is_array($val)) {
                expect($val)->not->toBeEmpty("{$k}.{$slot} must contain at least one item");
                foreach ($val as $sub) {
                    expect((string) $sub)->not->toBe('', "{$k}.{$slot} must not contain empty items");
                }
            } else {
                expect((string) $val)->not->toBe('', "{$k}.{$slot} must be non-empty");
            }
        }
    }
});

test('coach common scaffolding contains the read-only and simulation notices', function () {
    $coach = trans('clinical_coach');
    expect($coach)->toHaveKey('common');
    foreach (['invocation_label','drawer_heading','read_only_notice','simulation_notice','fallback_notice','close_label'] as $slot) {
        expect(array_key_exists($slot, $coach['common']))->toBeTrue("common.{$slot} missing");
        expect((string) $coach['common'][$slot])->not->toBe('');
    }
});

test('coach voice does not leak raw column names into user-facing slots', function () {
    $coach = trans('clinical_coach');
    $forbiddenInUserFacing = [
        'transport_mode',
        'is_recommended_osbp',
    ];
    foreach (ClinicalAccess::SECTION_KEYS as $k) {
        foreach (['q1_where_am_i','q5_eye_lands_first','q8_concerning','q10_next_view'] as $slot) {
            $val = (string) ($coach[$k][$slot] ?? '');
            foreach ($forbiddenInUserFacing as $col) {
                expect($val)->not->toContain($col, "{$k}.{$slot} must not expose raw column names");
            }
        }
    }
});

test('coach blade partial renders for every clinical section without throwing', function () {
    foreach (ClinicalAccess::SECTION_KEYS as $k) {
        expect(fn () => view('admin.clinical._coach', ['sectionKey' => $k])->render())
            ->not->toThrow(\Throwable::class, "_coach.blade.php must render for {$k}");
        $html = view('admin.clinical._coach', ['sectionKey' => $k])->render();
        expect(strlen($html))->toBeGreaterThan(2000, "_coach for {$k} must render a non-trivial drawer");
    }
});
