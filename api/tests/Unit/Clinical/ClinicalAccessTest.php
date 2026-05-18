<?php

declare(strict_types=1);

use App\Services\Clinical\ClinicalAccess;

uses(\Tests\TestCase::class);

function caScope(array $override = []): array
{
    return array_merge([
        'user_id' => 1, 'role_key' => 'NATIONAL_ADMIN', 'account_type' => 'NATIONAL_ADMIN',
        'scope_level' => 'NATIONAL', 'is_super' => true,
    ], $override);
}

test('national_admin sees every clinical section', function () {
    $a = new ClinicalAccess();
    foreach (ClinicalAccess::SECTION_KEYS as $k) {
        expect($a->canSee(caScope(), $k))->toBeTrue("NATIONAL_ADMIN must see {$k}");
    }
});

test('PHEOC sees every clinical section', function () {
    $a = new ClinicalAccess();
    $s = caScope(['role_key' => 'PHEOC_OFFICER', 'scope_level' => 'PHEOC', 'is_super' => false]);
    foreach (ClinicalAccess::SECTION_KEYS as $k) {
        expect($a->canSee($s, $k))->toBeTrue("PHEOC must see {$k}");
    }
});

test('DISTRICT scope is denied every clinical section', function () {
    $a = new ClinicalAccess();
    $s = caScope(['role_key' => 'DISTRICT_SUPERVISOR', 'scope_level' => 'DISTRICT', 'is_super' => false]);
    foreach (ClinicalAccess::SECTION_KEYS as $k) {
        expect($a->canSee($s, $k))->toBeFalse("DISTRICT must NOT see {$k}");
    }
});

test('POE scope is denied every clinical section', function () {
    $a = new ClinicalAccess();
    $s = caScope(['role_key' => 'POE_OFFICER', 'scope_level' => 'POE', 'is_super' => false]);
    foreach (ClinicalAccess::SECTION_KEYS as $k) {
        expect($a->canSee($s, $k))->toBeFalse("POE must NOT see {$k}");
    }
});

test('OBSERVER is hard-denied every clinical section', function () {
    $a = new ClinicalAccess();
    foreach ([['role_key'=>'OBSERVER','account_type'=>'NATIONAL_ADMIN','scope_level'=>'NATIONAL','is_super'=>true],
              ['role_key'=>'NATIONAL_ADMIN','account_type'=>'OBSERVER','scope_level'=>'NATIONAL','is_super'=>true]] as $override) {
        $s = caScope($override);
        foreach (ClinicalAccess::SECTION_KEYS as $k) {
            expect($a->canSee($s, $k))->toBeFalse("OBSERVER must be denied {$k} (override " . json_encode($override) . ')');
        }
    }
});

test('unknown section key denied even for super-user', function () {
    $a = new ClinicalAccess();
    expect($a->canSee(caScope(), 'clin-fake'))->toBeFalse();
});

test('visibleKeys returns matrix-consistent list', function () {
    $a = new ClinicalAccess();
    expect($a->visibleKeys(caScope()))->toBe(ClinicalAccess::SECTION_KEYS);
    expect($a->visibleKeys(caScope(['role_key'=>'POE_OFFICER','scope_level'=>'POE','is_super'=>false])))->toBe([]);
});
