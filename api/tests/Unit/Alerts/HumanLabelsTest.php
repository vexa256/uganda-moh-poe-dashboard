<?php

declare(strict_types=1);

uses(\Tests\TestCase::class);

use App\Support\Alerts\HumanLabels;

it('translates action codes to plain English with no raw enum leaking', function () {
    $a = HumanLabels::action('ISOLATION');

    expect($a['code'])->toBe('ISOLATION')
        ->and($a['title'])->toBe('Has the patient been kept apart from other people?')
        ->and($a['why'])->not->toBeEmpty()
        ->and($a['icon'])->toBe('shield-alert');
});

it('translates statuses with a tone hint', function () {
    expect(HumanLabels::status('PENDING'))
        ->toMatchArray(['code' => 'PENDING', 'tone' => 'urgent'])
        ->and(HumanLabels::status('PENDING')['label'])->toBe('Not started yet');

    expect(HumanLabels::status('NOT_APPLICABLE'))
        ->toMatchArray(['code' => 'NOT_APPLICABLE', 'tone' => 'skipped']);
});

it('reconciles numeric and string tier representations', function () {
    expect(HumanLabels::tier(1)['bucket'])->toBe('top')
        ->and(HumanLabels::tier(1)['dot'])->toBe('red')
        ->and(HumanLabels::tier('TIER_1_ALWAYS_NOTIFIABLE')['bucket'])->toBe('top')
        ->and(HumanLabels::tier('ANNEX2_EPIDEMIC_PRONE')['bucket'])->toBe('high')
        ->and(HumanLabels::tier('SYNDROMIC')['bucket'])->toBe('normal')
        ->and(HumanLabels::tier(null)['bucket'])->toBe('normal');
});

it('builds disease headlines without exposing codes', function () {
    $d = HumanLabels::disease('cholera');

    expect($d['code'])->toBe('cholera')
        ->and($d['headline'])->toStartWith('Suspected ')
        ->and($d['group'])->toBe('cholera_diarrhoeal')
        ->and($d['group_label'])->toBe('Suspected cholera or severe diarrhoea');
});

it('falls back gracefully for unknown disease codes', function () {
    $d = HumanLabels::disease('xyz_made_up_code_123');

    expect($d['group'])->toBe('syndromic_unknown')
        ->and($d['headline'])->toStartWith('Suspected ');
});

it('humanises due times into plain language', function () {
    $now    = \Carbon\Carbon::create(2026, 4, 26, 12, 0, 0);
    $past   = $now->copy()->subHours(6)->toDateTimeString();
    $future = $now->copy()->addDays(2)->toDateTimeString();

    expect(HumanLabels::dueHuman($past, $now))->toContain('overdue')
        ->and(HumanLabels::dueHuman($past, $now))->toContain('hours')
        ->and(HumanLabels::dueHuman($future, $now))->toContain('in ')
        ->and(HumanLabels::dueHuman(null))->toBe('no deadline set');
});

it('wraps a raw followup row with a human block', function () {
    $row = [
        'id'             => 99,
        'alert_id'       => 1,
        'action_code'    => 'WHO_NOTIFICATION',
        'status'         => 'PENDING',
        'due_at'         => null,
        'blocks_closure' => 1,
    ];

    $wrapped = HumanLabels::wrapFollowup($row);

    expect($wrapped['action_code'])->toBe('WHO_NOTIFICATION')
        ->and($wrapped['human']['title'])->toContain('international')
        ->and($wrapped['human']['status_label'])->toBe('Not started yet')
        ->and($wrapped['human']['status_tone'])->toBe('urgent')
        ->and($wrapped['human']['blocks_close'])->toBeTrue();
});

it('translates close categories with their helper sentence', function () {
    $cat = HumanLabels::closeCategory('FALSE_POSITIVE');

    expect($cat['code'])->toBe('FALSE_POSITIVE')
        ->and($cat['label'])->toBe('It was not a real case (false alarm)')
        ->and($cat['help'])->not->toBeEmpty();
});
