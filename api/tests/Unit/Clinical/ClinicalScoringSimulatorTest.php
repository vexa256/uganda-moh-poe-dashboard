<?php

declare(strict_types=1);

use App\Services\Clinical\ClinicalRegistry;
use App\Services\Clinical\ClinicalScoringSimulator;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(\Tests\TestCase::class);

beforeEach(function () {
    $env = [];
    $envPath = base_path('.env');
    if (is_readable($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || $line[0] === '#') continue;
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) $env[trim($parts[0])] = trim($parts[1], " \t\"'");
        }
    }
    if (($env['DB_DATABASE'] ?? ':memory:') === ':memory:') {
        $this->markTestSkipped('mysql .env DB_DATABASE not configured');
    }
    Config::set('database.connections.mysql.database', $env['DB_DATABASE']);
    Config::set('database.connections.mysql.username', $env['DB_USERNAME'] ?? 'hacker');
    Config::set('database.connections.mysql.password', $env['DB_PASSWORD'] ?? '');
    Config::set('database.connections.mysql.host',     $env['DB_HOST'] ?? '127.0.0.1');
    Config::set('database.connections.mysql.port',     (int) ($env['DB_PORT'] ?? 3306));
    Config::set('database.default', 'mysql');
    DB::purge('mysql');
    try { DB::connection('mysql')->getPdo(); } catch (\Throwable $e) {
        $this->markTestSkipped('mysql connection unavailable: ' . $e->getMessage());
    }
    $this->reg = new ClinicalRegistry();
    $this->sim = new ClinicalScoringSimulator($this->reg);
});

test('simulator output is always labelled is_simulation=true and engine_called=false', function () {
    $r = $this->sim->simulate('smallpox', ['fever'], [], [], 'ZM');
    expect($r['is_simulation'])->toBeTrue();
    expect($r['engine_called'])->toBeFalse();
    expect($r['engine_note'])->toContain('client-side');
    expect($r['simulator_version'])->toBe(ClinicalScoringSimulator::VERSION);
});

test('unknown disease returns honest empty result, not an invented number', function () {
    $r = $this->sim->simulate('not_a_disease', ['fever'], [], [], 'ZM');
    expect($r['final_score'])->toBe(0);
    expect($r['engine_note'])->toContain('Unknown disease');
});

test('symptom and exposure scores match the disease.symptom_weights and exposure_weights JSON', function () {
    $disease = $this->reg->diseaseByCode('smallpox');
    expect($disease)->not->toBeNull();

    $sw = (array) $disease->symptom_weights;
    $ew = (array) $disease->exposure_weights;
    $picks = array_slice(array_keys($sw), 0, 2);
    $expicks = array_slice(array_keys($ew), 0, 1);
    $r = $this->sim->simulate('smallpox', $picks, [], $expicks, null);

    $expectedSymptomScore = array_sum(array_map(fn ($k) => (float) $sw[$k], $picks));
    $expectedExposureScore = array_sum(array_map(fn ($k) => (float) $ew[$k], $expicks));
    expect($r['breakdown']['symptom_score'])->toBe(round($expectedSymptomScore, 2));
    expect($r['breakdown']['exposure_score'])->toBe(round($expectedExposureScore, 2));
});

test('action band thresholds: ≥55 HIGH, ≥35 MEDIUM, ≥15 LOW, otherwise NONE', function () {
    expect($this->sim->actionBand(55)['key'])->toBe('HIGH');
    expect($this->sim->actionBand(56)['key'])->toBe('HIGH');
    expect($this->sim->actionBand(54)['key'])->toBe('MEDIUM');
    expect($this->sim->actionBand(35)['key'])->toBe('MEDIUM');
    expect($this->sim->actionBand(34)['key'])->toBe('LOW');
    expect($this->sim->actionBand(15)['key'])->toBe('LOW');
    expect($this->sim->actionBand(14)['key'])->toBe('NONE');
    expect($this->sim->actionBand(0)['key'])->toBe('NONE');
});

test('final_score is clamped to [0, 100]', function () {
    $disease = $this->reg->diseaseByCode('smallpox');
    $every = array_keys((array) $disease->symptom_weights);
    $r = $this->sim->simulate('smallpox', $every, [], [], 'ZM');
    expect($r['final_score'])->toBeGreaterThanOrEqual(0);
    expect($r['final_score'])->toBeLessThanOrEqual(100);
});

test('simulator output is deterministic — same inputs → same outputs', function () {
    $a = $this->sim->simulate('smallpox', ['fever','high_fever'], [], ['close_contact_case'], 'ZM');
    $b = $this->sim->simulate('smallpox', ['fever','high_fever'], [], ['close_contact_case'], 'ZM');
    expect($a['final_score'])->toBe($b['final_score']);
    expect($a['breakdown'])->toBe($b['breakdown']);
});

test('omitted_components are surfaced honestly', function () {
    $r = $this->sim->simulate('smallpox', ['fever'], [], [], null);
    expect($r['omitted_components'])->toBeArray();
    foreach (['syndrome_bonus','vaccination_modifier','onset_modifier','contradiction_penalty'] as $key) {
        expect($r['omitted_components'])->toHaveKey($key);
        expect((string) $r['omitted_components'][$key])->not->toBe('');
    }
});

test('maxAttainableScore is non-negative and clamped to 100', function () {
    foreach ($this->reg->diseases() as $d) {
        $cap = $this->sim->maxAttainableScore($d);
        expect($cap)->toBeGreaterThanOrEqual(0, "disease {$d->disease_code} score cap negative");
        expect($cap)->toBeLessThanOrEqual(100, "disease {$d->disease_code} score cap above 100");
    }
});
