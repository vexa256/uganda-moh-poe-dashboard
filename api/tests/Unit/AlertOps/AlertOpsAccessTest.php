<?php

declare(strict_types=1);

use App\Services\AlertOps\AlertOpsAccess;

uses(\Tests\TestCase::class);

function aoaScope(array $override = []): array
{
    return array_merge([
        'user_id' => 1, 'role_key' => 'NATIONAL_ADMIN', 'account_type' => 'NATIONAL_ADMIN',
        'scope_level' => 'NATIONAL', 'is_super' => true,
    ], $override);
}

test('NATIONAL admin sees every Alert Ops section', function () {
    $a = new AlertOpsAccess();
    foreach (AlertOpsAccess::SECTION_KEYS as $k) {
        expect($a->canSee(aoaScope(), $k))->toBeTrue("NATIONAL must see {$k}");
    }
});

test('PHEOC sees every section', function () {
    $a = new AlertOpsAccess();
    $s = aoaScope(['role_key' => 'PHEOC_OFFICER', 'scope_level' => 'PHEOC', 'is_super' => false]);
    foreach (AlertOpsAccess::SECTION_KEYS as $k) {
        expect($a->canSee($s, $k))->toBeTrue("PHEOC must see {$k}");
    }
});

test('DISTRICT sees every section (scoped server-side by ScopeFilter)', function () {
    $a = new AlertOpsAccess();
    $s = aoaScope(['role_key' => 'DISTRICT_SUPERVISOR', 'scope_level' => 'DISTRICT', 'is_super' => false]);
    foreach (AlertOpsAccess::SECTION_KEYS as $k) {
        expect($a->canSee($s, $k))->toBeTrue("DISTRICT must see {$k}");
    }
});

test('POE sees every section (scoped to its own port)', function () {
    $a = new AlertOpsAccess();
    $s = aoaScope(['role_key' => 'POE_OFFICER', 'scope_level' => 'POE', 'is_super' => false]);
    foreach (AlertOpsAccess::SECTION_KEYS as $k) {
        expect($a->canSee($s, $k))->toBeTrue("POE must see {$k}");
    }
});

test('OBSERVER hard-denied every section', function () {
    $a = new AlertOpsAccess();
    foreach (
        [
            ['role_key' => 'OBSERVER', 'account_type' => 'NATIONAL', 'scope_level' => 'NATIONAL', 'is_super' => true],
            ['role_key' => 'NATIONAL_ADMIN', 'account_type' => 'OBSERVER', 'scope_level' => 'NATIONAL', 'is_super' => true],
        ] as $override
    ) {
        $s = aoaScope($override);
        foreach (AlertOpsAccess::SECTION_KEYS as $k) {
            expect($a->canSee($s, $k))->toBeFalse("OBSERVER must be denied {$k}");
        }
    }
});

test('SELF scope is denied', function () {
    $a = new AlertOpsAccess();
    $s = aoaScope(['role_key' => 'SCREENER', 'scope_level' => 'SELF', 'is_super' => false]);
    foreach (AlertOpsAccess::SECTION_KEYS as $k) {
        expect($a->canSee($s, $k))->toBeFalse("SELF must be denied {$k}");
    }
});

test('unknown section key denied even for super', function () {
    expect((new AlertOpsAccess())->canSee(aoaScope(), 'alert-fake'))->toBeFalse();
});
