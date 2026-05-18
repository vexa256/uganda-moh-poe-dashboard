<?php

declare(strict_types=1);

use App\Services\Clinical\ClinicalTranslator;

uses(\Tests\TestCase::class);

beforeEach(function () {
    $this->t = new ClinicalTranslator();
});

test('tier 1/2/3 translate to plain language with non-empty consequence', function () {
    foreach ([1, 2, 3] as $tier) {
        $r = $this->t->tier($tier);
        expect($r['fallback'])->toBeFalse();
        expect((string) $r['label'])->not->toBe('');
        expect((string) $r['consequence'])->not->toBe('');
        expect($r['short'])->toContain('Tier');
    }
});

test('unknown tier falls back honestly without inventing meaning', function () {
    $r = $this->t->tier(99);
    expect($r['fallback'])->toBeTrue();
    expect($r['label'])->toBe('Tier 99');
});

test('null tier returns the no-tier fallback', function () {
    $r = $this->t->tier(null);
    expect($r['fallback'])->toBeTrue();
});

test('every endemicity level translates with a non-empty consequence', function () {
    foreach (['OUTBREAK_ACTIVE','OUTBREAK_RECENT','ENDEMIC','SPORADIC','IMPORTED_ONLY'] as $level) {
        $r = $this->t->endemicity($level);
        expect($r['fallback'])->toBeFalse("expected {$level} to translate");
        expect((string) $r['consequence'])->not->toBe('');
        expect((string) $r['badge'])->toStartWith('badge-');
    }
});

test('unknown endemicity level falls back honestly', function () {
    $r = $this->t->endemicity('GHOST_LEVEL');
    expect($r['fallback'])->toBeTrue();
});

test('weight bands match the v1 specification', function () {
    expect($this->t->weightStrength(24)['label'])->toBe('Very strong indicator');
    expect($this->t->weightStrength(18)['label'])->toBe('Very strong indicator');
    expect($this->t->weightStrength(12)['label'])->toBe('Strong indicator');
    expect($this->t->weightStrength(7)['label'])->toBe('Moderate indicator');
    expect($this->t->weightStrength(1)['label'])->toBe('Weak indicator');
    expect($this->t->weightStrength(0)['label'])->toBe('No effect');
    expect($this->t->weightStrength(-1)['label'])->toBe('Slightly lowers likelihood');
    expect($this->t->weightStrength(-12)['label'])->toBe('Strongly rules this disease out');
});

test('null weight returns fallback shape', function () {
    $r = $this->t->weightStrength(null);
    expect($r['fallback'])->toBeTrue();
});

test('sensitivity bands cover the 0..1 range', function () {
    expect($this->t->sensitivity(0.95)['label'])->toContain('High');
    expect($this->t->sensitivity(0.5)['label'])->toContain('Moderate');
    expect($this->t->sensitivity(0.3)['label'])->toContain('Low');
    expect($this->t->sensitivity(0.05)['label'])->toContain('Very low');
    expect($this->t->sensitivity(null)['fallback'])->toBeTrue();
});

test('response_type translates known enum values', function () {
    foreach (['YES_NO', 'YES_NO_UNKNOWN', 'MULTI_SELECT', 'TEXT', 'NUMERIC'] as $code) {
        expect($this->t->responseType($code))->not->toBe($code);
    }
});

test('disease/symptom/exposure name lookups resolve from the live DB', function () {
    if (! \Illuminate\Support\Facades\Schema::hasTable('ref_diseases')) {
        $this->markTestSkipped('ref_diseases not available on test connection');
    }
    expect($this->t->diseaseName('smallpox'))->toBe('Smallpox');
    expect($this->t->symptomName('fever'))->toBeString();
    // unknown codes pass through honestly
    expect($this->t->diseaseName('not_a_disease'))->toBe('not_a_disease');
});

test('country name lookup uses the canonical ref_countries.name column', function () {
    if (! \Illuminate\Support\Facades\Schema::hasTable('ref_endemic_countries')) {
        $this->markTestSkipped('ref_endemic_countries not available on test connection');
    }
    // ZM is in ref_countries.
    $name = $this->t->countryName('ZM');
    expect((string) $name)->not->toBe('');
    // Unknown codes pass through.
    expect($this->t->countryName('XX'))->toBe('XX');
});
