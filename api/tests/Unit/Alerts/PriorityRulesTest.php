<?php

declare(strict_types=1);

uses(\Tests\TestCase::class);

use App\Support\Alerts\PriorityRules;

it('puts lab samples first for cholera', function () {
    $order = PriorityRules::orderFor('cholera');

    expect($order[0])->toBe('LAB_SPECIMENS')
        ->and($order)->toContain('RISK_COMMS')
        ->and($order)->toContain('CONTACT_LISTING');
});

it('promotes WHO notification to position 2 for tier-1 always-notifiable diseases', function () {
    $order = PriorityRules::orderFor('smallpox');

    // Position 0 is the group head (ISOLATION for novel respiratory).
    expect($order[0])->toBe('ISOLATION')
        ->and($order[1])->toBe('WHO_NOTIFICATION');
});

it('jumps stabilisation steps to the front when vitals are critical', function () {
    $order = PriorityRules::orderFor('ebola_virus_disease', criticalVitals: true);

    expect($order[0])->toBe('CASE_INVESTIGATION')
        ->and($order[1])->toBe('ISOLATION');
});

it('auto-marks vector control as not applicable for VHFs except RVF', function () {
    expect(PriorityRules::notApplicableFor('ebola_virus_disease'))->toContain('VECTOR_CONTROL')
        ->and(PriorityRules::notApplicableFor('marburg_virus_disease'))->toContain('VECTOR_CONTROL')
        ->and(PriorityRules::notApplicableFor('rift_valley_fever'))->not->toContain('VECTOR_CONTROL');
});

it('drops contact tracing from the queue for vector-borne diseases', function () {
    $order = PriorityRules::orderFor('yellow_fever');
    expect($order)->not->toContain('CONTACT_TRACING')
        ->and($order)->toContain('VECTOR_CONTROL');
});

it('handles unknown disease codes with the syndromic fallback', function () {
    $order = PriorityRules::orderFor('xyz_unknown_code');

    expect($order[0])->toBe('CASE_INVESTIGATION')
        ->and($order)->toContain('LAB_SPECIMENS');
});

it('flags tier-1 diseases via isTopTier', function () {
    expect(PriorityRules::isTopTier('smallpox'))->toBeTrue()
        ->and(PriorityRules::isTopTier('cholera'))->toBeFalse();
});

it('flags Annex-2 diseases via isAnnex2', function () {
    expect(PriorityRules::isAnnex2('ebola_virus_disease'))->toBeTrue()
        ->and(PriorityRules::isAnnex2('influenza_seasonal'))->toBeFalse();
});

it('groups every registered disease into a known bucket', function () {
    foreach (PriorityRules::DISEASE_GROUP as $code => $group) {
        expect(PriorityRules::groupFor($code))->toBe($group);
    }
});

it('returns a final order containing only RTSL-14 codes', function () {
    $allowed = PriorityRules::DEFAULT_ORDER;

    foreach (['cholera', 'ebola_virus_disease', 'yellow_fever', 'unknown_xyz'] as $code) {
        foreach (PriorityRules::orderFor($code) as $action) {
            expect($action)->toBeIn($allowed);
        }
    }
});
