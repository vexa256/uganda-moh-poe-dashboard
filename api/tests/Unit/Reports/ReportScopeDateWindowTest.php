<?php

declare(strict_types=1);

use App\Services\PheocScope;
use App\Services\Reports\ReportScope;

function rsScope(): ReportScope
{
    return new ReportScope(new PheocScope());
}

test('quarter+year beats month+year beats year beats range', function () {
    $rs = rsScope();

    [$from, $to] = $rs->resolveDateWindow([
        'year' => 2026, 'quarter' => 2, 'month' => 7,
        'start_date' => '2020-01-01', 'end_date' => '2020-12-31',
    ]);
    expect($from->toDateString())->toBe('2026-04-01');
    expect($to->toDateString())->toBe('2026-06-30');

    [$from, $to] = $rs->resolveDateWindow(['year' => 2026, 'month' => 4]);
    expect($from->toDateString())->toBe('2026-04-01');
    expect($to->toDateString())->toBe('2026-04-30');

    [$from, $to] = $rs->resolveDateWindow(['year' => 2025]);
    expect($from->toDateString())->toBe('2025-01-01');
    expect($to->toDateString())->toBe('2025-12-31');

    [$from, $to] = $rs->resolveDateWindow(['start_date' => '2026-03-15', 'end_date' => '2026-04-01']);
    expect($from->toDateString())->toBe('2026-03-15');
    expect($to->toDateString())->toBe('2026-04-01');
});

test('explicit default_days still honoured (R4 Contact Tracing pins 30d)', function () {
    $rs = rsScope();
    [$from, $to] = $rs->resolveDateWindow(['default_days' => 30]);
    $diff = $from->diffInDays($to);
    expect($diff)->toBeGreaterThanOrEqual(28);
    expect($diff)->toBeLessThanOrEqual(30);
});

test('with no filters at all, default window is the current year', function () {
    $rs = rsScope();
    [$from, $to] = $rs->resolveDateWindow([]);
    $year = (int) \Illuminate\Support\Carbon::now()->year;
    expect($from->toDateString())->toBe($year . '-01-01');
    expect($to->toDateString())->toBe($year . '-12-31');
});

test('filtersHash is stable under key reordering', function () {
    $rs = rsScope();
    $a = $rs->filtersHash(['year' => 2026, 'poe' => 'Mwami', 'sex' => 'MALE']);
    $b = $rs->filtersHash(['sex' => 'MALE', 'poe' => 'Mwami', 'year' => 2026]);
    expect($a)->toBe($b);

    $c = $rs->filtersHash(['year' => 2025, 'poe' => 'Mwami', 'sex' => 'MALE']);
    expect($c)->not->toBe($a);
});
