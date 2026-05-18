<?php

declare(strict_types=1);

uses(\Tests\TestCase::class);

/**
 * CoachManifestTest — pins lang/en/reports_coach.php to the brief §8 contract.
 *
 * Scoped to the Wave 1 reports (the seven National Reports the brief covers).
 * Wave 2 surfaces under app/Http/Controllers/Admin/Reports/ are owned by other
 * engineers and add their own coach entries; this test does not assert on
 * keys outside the Wave 1 set.
 */
const COACH_WAVE1_KEYS = [
    'rpt-volume',
    'rpt-suspected',
    'rpt-geo',
    'rpt-contact-tracing',
    'rpt-registry',
    'rpt-age-gender',
    'rpt-symptom-exposure',
];

test('coach manifest exists for every Wave 1 report key', function () {
    $coach = trans('reports_coach');
    expect($coach)->toBeArray();
    foreach (COACH_WAVE1_KEYS as $key) {
        expect(array_key_exists($key, $coach))->toBeTrue("manifest missing for {$key}");
        expect($coach[$key])->toBeArray();
    }
});

test('every Wave 1 report has the ten brief-mandated coach slots and none is empty', function () {
    $coach = trans('reports_coach');
    $required = [
        'q1_where_am_i',
        'q2_what_can_i_do',
        'q3_tabs',
        'q4_charts',
        'q5_eye_lands_first',
        'q6_filters',
        'q7_numbers',
        'q8_suppressed',
        'q9_data_quality',
        'q10_next_view',
    ];
    foreach (COACH_WAVE1_KEYS as $key) {
        $manifest = $coach[$key];
        foreach ($required as $slot) {
            expect(array_key_exists($slot, $manifest))->toBeTrue("{$key} missing slot {$slot}");
            $val = $manifest[$slot];
            if (is_array($val)) {
                expect($val)->not->toBeEmpty("{$key}.{$slot} must contain at least one item");
                foreach ($val as $sub) {
                    expect((string) $sub)->not->toBe('', "{$key}.{$slot} must not contain empty items");
                }
            } else {
                expect((string) $val)->not->toBe('', "{$key}.{$slot} must be a non-empty string");
            }
        }
    }
});

test('coach common scaffolding is present', function () {
    $coach = trans('reports_coach');
    expect(array_key_exists('common', $coach))->toBeTrue();
    foreach (['invocation_label', 'drawer_heading', 'small_n_disclosure', 'denominator_note', 'time_zone_note', 'close_label'] as $slot) {
        expect(array_key_exists($slot, $coach['common']))->toBeTrue("common.{$slot} missing");
        expect((string) $coach['common'][$slot])->not->toBe('');
    }
});

test('coach voice does not leak technical column names into user-facing slots', function () {
    // Allowed: q4_charts and q7_numbers may name columns (audience for "what
    // does this number mean" expects the source-of-truth column name).
    // Forbidden in conversational slots: q1, q5, q9, q10.
    $coach = trans('reports_coach');
    $forbiddenColumns = [
        'transport_mode',
        'is_recommended_osbp',
    ];
    foreach (COACH_WAVE1_KEYS as $key) {
        foreach (['q1_where_am_i', 'q5_eye_lands_first', 'q9_data_quality', 'q10_next_view'] as $slot) {
            $val = (string) ($coach[$key][$slot] ?? '');
            foreach ($forbiddenColumns as $col) {
                expect($val)->not->toContain($col, "{$key}.{$slot} must not expose raw column names");
            }
        }
    }
});

test('coach blade partial renders for every Wave 1 report key without throwing', function () {
    foreach (COACH_WAVE1_KEYS as $key) {
        expect(fn () => view('admin.reports._coach', ['reportKey' => $key])->render())
            ->not->toThrow(\Throwable::class, "_coach.blade.php must render for {$key}");
        $html = view('admin.reports._coach', ['reportKey' => $key])->render();
        expect(strlen($html))->toBeGreaterThan(2000, "_coach for {$key} must render a non-trivial drawer");
    }
});
