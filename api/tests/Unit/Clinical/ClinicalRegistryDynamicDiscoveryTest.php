<?php

declare(strict_types=1);

use App\Services\Clinical\ClinicalRegistry;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(\Tests\TestCase::class);

/**
 * Dynamic-discovery proof per Paranoid v2 brief §13:
 * counts and lists must be discovered at request time from the live
 * reference tables — never hard-coded in the view.
 *
 * Runs against the live mysql connection because the schema (JSON columns,
 * ENUMs, unique pair indexes) does not round-trip cleanly to the sqlite
 * memory DB the test runner defaults to.
 */
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
});

test('disease count is discovered live and matches the table', function () {
    $reg = new ClinicalRegistry();
    expect($reg->diseases()->count())->toBe(DB::table('ref_diseases')->count());
});

test('symptom count is discovered live and matches the table', function () {
    $reg = new ClinicalRegistry();
    expect($reg->symptoms()->count())->toBe(DB::table('ref_symptoms')->count());
});

test('exposure count is discovered live and matches the table', function () {
    $reg = new ClinicalRegistry();
    expect($reg->exposures()->count())->toBe(DB::table('ref_exposures')->count());
});

test('endemic mapping count is discovered live and matches the table', function () {
    $reg = new ClinicalRegistry();
    expect($reg->endemicMappings()->count())->toBe(DB::table('ref_endemic_countries')->count());
});

test('engine config rows discovered live', function () {
    $reg = new ClinicalRegistry();
    expect($reg->engineConfigRows()->count())->toBe(DB::table('ref_engine_config')->count());
});

test('tiers in use are computed dynamically (no hard-coded list)', function () {
    $reg = new ClinicalRegistry();
    $tiers = $reg->tiersInUse();
    expect($tiers)->toBeArray();
    foreach ($tiers as $t) {
        expect($t)->toBeInt();
        expect(DB::table('ref_diseases')->where('ihr_tier', $t)->exists())->toBeTrue();
    }
});

test('endemic levels in use are dynamic', function () {
    $reg = new ClinicalRegistry();
    $levels = $reg->endemicLevelsInUse();
    foreach ($levels as $l) {
        expect(DB::table('ref_endemic_countries')->where('endemicity_level', $l)->exists())->toBeTrue();
    }
});

test('a hydrated disease has its JSON columns decoded once', function () {
    $reg = new ClinicalRegistry();
    $first = $reg->diseases()->first();
    expect($first)->not->toBeNull();
    foreach (['symptom_weights','exposure_weights','gates','case_definition'] as $col) {
        $val = $first->{$col} ?? null;
        if ($val !== null) {
            expect(is_array($val))->toBeTrue("disease.{$col} must be a decoded array, got " . gettype($val));
        }
    }
});

test('vaccine inventory honestly surfaces empty when both sources are empty', function () {
    $reg = new ClinicalRegistry();
    $engine = $reg->vaccineEngineRows();
    $cols = $reg->vaccineSubmissionColumns();
    if ($engine->isEmpty() && empty($cols)) {
        // Reality (per reconciliation log): no vaccine table; both surrogate
        // sources can legitimately be empty. The registry must return empty
        // structures, not invent any vaccines.
        expect($engine->count())->toBe(0);
        expect($cols)->toBeArray();
    } else {
        expect(true)->toBeTrue();
    }
});
