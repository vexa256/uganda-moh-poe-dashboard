<?php

declare(strict_types=1);

use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;

function ragScope(array $override = []): array
{
    return array_merge([
        'user_id' => 42, 'role_key' => 'NATIONAL_ADMIN', 'account_type' => 'NATIONAL_ADMIN',
        'scope_level' => 'NATIONAL', 'is_super' => true,
        'country_code' => 'ZM', 'countries' => ['ZM'], 'provinces' => [],
        'districts' => [], 'poes' => [],
        'primary_poe' => null, 'assignments' => [], 'label' => 'Uganda · National PHEOC',
    ], $override);
}

test('national admin sees every report', function () {
    $access = new ReportAccess();
    $scope  = ragScope();
    foreach (ReportScope::REPORT_KEYS as $key) {
        expect($access->canSee($scope, $key))->toBeTrue("{$key} must be visible to NATIONAL_ADMIN");
    }
});

test('observer is denied everything except the country-analytics carve-out', function () {
    // Wave 2 §5: WHO observers see country-level aggregates only. The
    // explicit allow-list lives in ReportAccess::OBSERVER_VISIBLE; every
    // other key must be denied for an OBSERVER role/account_type.
    $access = new ReportAccess();
    $scope  = ragScope(['role_key' => 'OBSERVER', 'account_type' => 'OBSERVER', 'scope_level' => 'SELF', 'is_super' => false]);
    foreach (ReportScope::REPORT_KEYS as $key) {
        $expected = in_array($key, ReportAccess::OBSERVER_VISIBLE, true);
        expect($access->canSee($scope, $key))->toBe(
            $expected,
            $expected
                ? "{$key} must be visible to OBSERVER (carve-out)"
                : "{$key} must NOT be visible to OBSERVER"
        );
    }
});

test('POE role hides rpt-geo and rpt-registry', function () {
    $access = new ReportAccess();
    $scope  = ragScope(['role_key' => 'POE_OFFICER', 'scope_level' => 'POE', 'is_super' => false, 'poes' => ['Mwami']]);
    expect($access->canSee($scope, 'rpt-volume'))->toBeTrue();
    expect($access->canSee($scope, 'rpt-suspected'))->toBeTrue();
    expect($access->canSee($scope, 'rpt-contact-tracing'))->toBeTrue();
    expect($access->canSee($scope, 'rpt-age-gender'))->toBeTrue();
    expect($access->canSee($scope, 'rpt-symptom-exposure'))->toBeTrue();
    expect($access->canSee($scope, 'rpt-geo'))->toBeFalse();
    expect($access->canSee($scope, 'rpt-registry'))->toBeFalse();
});

test('district role sees every report except the country-analytics surfaces', function () {
    // DISTRICT does not get the Wave 2 country-analytics carve-out — only
    // PHEOC + super (NATIONAL_ADMIN / SERVICE) and the OBSERVER allow-list
    // see those keys.
    $access = new ReportAccess();
    $scope  = ragScope(['role_key' => 'DISTRICT_SUPERVISOR', 'scope_level' => 'DISTRICT', 'is_super' => false]);
    foreach (ReportScope::REPORT_KEYS as $key) {
        $expected = ! in_array($key, ReportAccess::COUNTRY_ANALYTICS_KEYS, true);
        expect($access->canSee($scope, $key))->toBe(
            $expected,
            $expected
                ? "{$key} must be visible to DISTRICT"
                : "{$key} (country-analytics) must NOT be visible to DISTRICT"
        );
    }
});

test('unknown report key denied', function () {
    $access = new ReportAccess();
    expect($access->canSee(ragScope(), 'rpt-fake'))->toBeFalse();
});

test('maskPii leaves NATIONAL rows intact', function () {
    $access = new ReportAccess();
    $row    = ['phone_number' => '+260123456789', 'email' => 'alice@example.org'];
    $masked = $access->maskPii($row, ragScope());
    expect($masked)->toBe($row);
});

test('maskPii obscures phone/email for DISTRICT / POE tiers', function () {
    $access = new ReportAccess();
    $row    = ['phone_number' => '+260123456789', 'email' => 'alice@example.org', 'travel_document_number' => 'PP1234567'];
    $scope  = ragScope(['role_key' => 'DISTRICT_SUPERVISOR', 'scope_level' => 'DISTRICT', 'is_super' => false]);
    $masked = $access->maskPii($row, $scope);
    expect($masked['phone_number'])->not->toBe($row['phone_number']);
    expect($masked['email'])->not->toBe($row['email']);
    expect($masked['travel_document_number'])->not->toBe($row['travel_document_number']);
    expect($masked['phone_number'])->toContain('•');
});

test('visibleKeys returns matrix-consistent list for POE', function () {
    // POE is denied:
    //   - POE_HIDDEN              (rpt-geo, rpt-registry)
    //   - COUNTRY_ANALYTICS_KEYS  (rpt-country-analytics — needs PHEOC+ or super)
    // Everything else in REPORT_KEYS is visible. This list is derived from
    // those two carve-outs so the test follows REPORT_KEYS as it grows.
    $access = new ReportAccess();
    $scope  = ragScope(['role_key' => 'POE_OFFICER', 'scope_level' => 'POE', 'is_super' => false]);

    $denied   = array_merge(ReportAccess::POE_HIDDEN, ReportAccess::COUNTRY_ANALYTICS_KEYS);
    $expected = array_values(array_filter(
        ReportScope::REPORT_KEYS,
        fn (string $k) => ! in_array($k, $denied, true),
    ));

    expect($access->visibleKeys($scope))->toBe($expected);
});

/* ----------------------------------------------------------------
 * Wave 2 positive coverage — country-analytics + observer carve-outs.
 * ---------------------------------------------------------------- */

test('PHEOC role sees the country-analytics surface', function () {
    $access = new ReportAccess();
    $scope  = ragScope(['role_key' => 'PHEOC_OFFICER', 'scope_level' => 'PHEOC', 'is_super' => false]);
    foreach (ReportAccess::COUNTRY_ANALYTICS_KEYS as $key) {
        expect($access->canSee($scope, $key))->toBeTrue("PHEOC must see {$key}");
    }
});

test('NATIONAL admin sees the country-analytics surface', function () {
    $access = new ReportAccess();
    $scope  = ragScope(); // NATIONAL_ADMIN, is_super=true
    foreach (ReportAccess::COUNTRY_ANALYTICS_KEYS as $key) {
        expect($access->canSee($scope, $key))->toBeTrue("NATIONAL must see {$key}");
    }
});

test('OBSERVER carve-out: only the OBSERVER_VISIBLE keys are returned by visibleKeys', function () {
    $access = new ReportAccess();
    $scope  = ragScope(['role_key' => 'OBSERVER', 'account_type' => 'OBSERVER', 'scope_level' => 'SELF', 'is_super' => false]);
    expect($access->visibleKeys($scope))->toBe(ReportAccess::OBSERVER_VISIBLE);
});

test('OBSERVER carve-out fires whether OBSERVER is set on role_key OR account_type', function () {
    $access = new ReportAccess();
    $allow  = ReportAccess::OBSERVER_VISIBLE[0] ?? 'rpt-country-analytics';

    $byRole    = ragScope(['role_key' => 'OBSERVER', 'account_type' => 'WHO_OBSERVER', 'scope_level' => 'NATIONAL', 'is_super' => false]);
    $byAccount = ragScope(['role_key' => 'WHO_OBSERVER', 'account_type' => 'OBSERVER', 'scope_level' => 'NATIONAL', 'is_super' => false]);

    expect($access->canSee($byRole, $allow))->toBeTrue('OBSERVER role must see allow-list key');
    expect($access->canSee($byAccount, $allow))->toBeTrue('OBSERVER account_type must see allow-list key');
    expect($access->canSee($byRole, 'rpt-volume'))->toBeFalse('OBSERVER role must not see non-allow-list key');
    expect($access->canSee($byAccount, 'rpt-volume'))->toBeFalse('OBSERVER account_type must not see non-allow-list key');
});

test('SELF scope (no PoE/district assignment) is denied every report unless OBSERVER carve-out fires', function () {
    $access = new ReportAccess();
    $scope  = ragScope(['role_key' => 'SCREENER', 'account_type' => 'SCREENER', 'scope_level' => 'SELF', 'is_super' => false]);
    foreach (ReportScope::REPORT_KEYS as $key) {
        expect($access->canSee($scope, $key))->toBeFalse("SELF scope must be denied {$key}");
    }
});

test('canSee returns false for keys not in REPORT_KEYS regardless of scope', function () {
    $access = new ReportAccess();
    foreach (['rpt-volume', 'rpt-suspected', 'rpt-fake', 'rpt-also-fake'] as $key) {
        $expected = in_array($key, ReportScope::REPORT_KEYS, true);
        expect($access->canSee(ragScope(), $key))->toBe($expected, "canSee({$key}) failed for super-user");
    }
});
