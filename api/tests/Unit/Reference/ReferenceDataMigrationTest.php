<?php

declare(strict_types=1);

/**
 * Phase REF · Unit REF-1 · Reference-Data Migration
 * ---------------------------------------------------------------------------
 * Boots the REF-1 migration on a throwaway in-memory SQLite connection,
 * then asserts the seven reference tables exist with the columns / unique
 * constraints / row-write behaviour the later REF units rely on.
 *
 * Lives in tests/Unit because the project's MySQL baseline schema lives in
 * app.sql, not in Laravel migrations.  RefreshDatabase against :memory:
 * SQLite would abort on unknown tables (see SB-1's ``test live in Unit''
 * note).  This test wires its own connection, runs only the REF-1
 * migration file, and never touches the host MySQL DB.
 *
 * Coverage (per master directive L19):
 *   • happy            — every table + column exists, every unique works
 *   • idempotent       — migration up()/down()/up() is replay-safe
 *   • error            — duplicate inserts violate the documented uniques
 *   • payload          — JSON columns round-trip
 */

uses(Tests\TestCase::class);

// ─────────────────────────────────────────────────────────────────────────
// Helpers — apply the REF-1 migration's up()/down() to whatever the
// Schema facade currently resolves to.  beforeEach() switches the default
// connection to a fresh in-memory SQLite for each test so MySQL is never
// touched.
// ─────────────────────────────────────────────────────────────────────────
function ref1_apply_migration_up(): void
{
    $migration = require base_path('database/migrations/2026_04_23_000001_create_reference_data_tables.php');
    $migration->up();
}

function ref1_apply_migration_down(): void
{
    $migration = require base_path('database/migrations/2026_04_23_000001_create_reference_data_tables.php');
    $migration->down();
}

// ─────────────────────────────────────────────────────────────────────────
// Boot helper — used by every test in this file.
// ─────────────────────────────────────────────────────────────────────────
beforeEach(function () {
    // Reconfigure Laravel's DB layer to point the default connection at a
    // fresh :memory: SQLite for this test only.  We do this by editing the
    // config and purging the prior connection.  The Schema facade then
    // resolves through the application container as normal.
    config(['database.default' => 'sqlite_ref1']);
    config(['database.connections.sqlite_ref1' => [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ]]);

    \Illuminate\Support\Facades\DB::purge('sqlite_ref1');
    \Illuminate\Support\Facades\DB::reconnect('sqlite_ref1');

    ref1_apply_migration_up();
});

afterEach(function () {
    try {
        ref1_apply_migration_down();
    } catch (\Throwable $e) {
        // best effort
    }
    \Illuminate\Support\Facades\DB::purge('sqlite_ref1');
});

// ─────────────────────────────────────────────────────────────────────────
// Schema presence — every one of the seven tables exists.
// ─────────────────────────────────────────────────────────────────────────
test('REF-1 creates all seven reference-data tables', function () {
    $expected = [
        'ref_poes',
        'ref_diseases',
        'ref_symptoms',
        'ref_exposures',
        'ref_exposure_mappings',
        'ref_engine_config',
        'ref_endemic_countries',
    ];

    foreach ($expected as $table) {
        expect(\Illuminate\Support\Facades\Schema::hasTable($table))
            ->toBeTrue("expected table {$table} to exist after REF-1 migration");
    }
});

// ─────────────────────────────────────────────────────────────────────────
// Per-table column contracts — these are the columns later REF units
// (seeders, /v2/reference/*, parity test) will read / write.
// ─────────────────────────────────────────────────────────────────────────
test('ref_poes has the columns POE-Master needs', function () {
    $cols = [
        'id', 'country_code', 'poe_code', 'poe_name', 'admin_level_1',
        'admin_level_1_type', 'district', 'poe_type', 'transport_mode',
        'regional_cluster', 'is_national_level', 'is_major_entry',
        'is_recommended_osbp', 'border_country', 'latitude', 'longitude',
        'gazette_source', 'payload', 'is_active', 'created_at', 'updated_at',
    ];
    foreach ($cols as $c) {
        expect(\Illuminate\Support\Facades\Schema::hasColumn('ref_poes', $c))
            ->toBeTrue("ref_poes is missing column {$c}");
    }
});

test('ref_diseases has the WHO/IHR columns the engine relies on', function () {
    $cols = [
        'id', 'disease_code', 'display_name', 'ihr_tier', 'who_syndrome',
        'incubation_days_min', 'incubation_days_max', 'case_definition',
        'gates', 'symptom_weights', 'exposure_weights', 'triage_overrides',
        'absent_penalties', 'sources', 'payload', 'is_active',
    ];
    foreach ($cols as $c) {
        expect(\Illuminate\Support\Facades\Schema::hasColumn('ref_diseases', $c))
            ->toBeTrue("ref_diseases is missing column {$c}");
    }
});

test('ref_symptoms has hallmark / sensitivity / red-flag columns', function () {
    $cols = [
        'id', 'symptom_code', 'display_name', 'category', 'syndrome_tags',
        'sensitivity', 'is_red_flag', 'is_hallmark', 'payload', 'is_active',
    ];
    foreach ($cols as $c) {
        expect(\Illuminate\Support\Facades\Schema::hasColumn('ref_symptoms', $c))
            ->toBeTrue("ref_symptoms is missing column {$c}");
    }
});

test('ref_exposures has the response-type + high-risk columns', function () {
    $cols = [
        'id', 'exposure_code', 'display_name', 'category', 'prompt_text',
        'response_type', 'is_high_risk', 'triggers_diseases', 'payload', 'is_active',
    ];
    foreach ($cols as $c) {
        expect(\Illuminate\Support\Facades\Schema::hasColumn('ref_exposures', $c))
            ->toBeTrue("ref_exposures is missing column {$c}");
    }
});

test('ref_exposure_mappings carries the DB-code to engine-code pair', function () {
    $cols = ['id', 'exposure_code', 'engine_code', 'priority', 'is_active'];
    foreach ($cols as $c) {
        expect(\Illuminate\Support\Facades\Schema::hasColumn('ref_exposure_mappings', $c))
            ->toBeTrue("ref_exposure_mappings is missing column {$c}");
    }
});

test('ref_engine_config is keyed by config_key with a JSON value column', function () {
    $cols = ['id', 'config_key', 'description', 'config_value', 'version', 'section', 'is_active'];
    foreach ($cols as $c) {
        expect(\Illuminate\Support\Facades\Schema::hasColumn('ref_engine_config', $c))
            ->toBeTrue("ref_engine_config is missing column {$c}");
    }
});

test('ref_endemic_countries is keyed by disease_code + country_code', function () {
    $cols = [
        'id', 'disease_code', 'country_code', 'country_name',
        'endemicity_level', 'since_year', 'source', 'last_verified_at',
        'payload', 'is_active',
    ];
    foreach ($cols as $c) {
        expect(\Illuminate\Support\Facades\Schema::hasColumn('ref_endemic_countries', $c))
            ->toBeTrue("ref_endemic_countries is missing column {$c}");
    }
});

// ─────────────────────────────────────────────────────────────────────────
// Unique constraints — the rules later REF units depend on.
// ─────────────────────────────────────────────────────────────────────────
test('ref_poes enforces (country_code, poe_code) uniqueness', function () {
    $row = [
        'country_code' => 'UGA',
        'poe_code'     => 'BUSIA',
        'poe_name'     => 'Busia OSBP',
        'created_at'   => now(),
        'updated_at'   => now(),
    ];
    \Illuminate\Support\Facades\DB::table('ref_poes')->insert($row);

    expect(fn () => \Illuminate\Support\Facades\DB::table('ref_poes')->insert($row))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('ref_diseases enforces disease_code uniqueness', function () {
    $row = [
        'disease_code' => 'cholera',
        'display_name' => 'Cholera',
        'ihr_tier'     => 2,
        'created_at'   => now(),
        'updated_at'   => now(),
    ];
    \Illuminate\Support\Facades\DB::table('ref_diseases')->insert($row);

    expect(fn () => \Illuminate\Support\Facades\DB::table('ref_diseases')->insert($row))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

test('ref_exposure_mappings enforces (exposure_code, engine_code) uniqueness', function () {
    $row = [
        'exposure_code' => 'CONTACT_SICK_PERSON',
        'engine_code'   => 'close_contact_case',
        'created_at'    => now(),
        'updated_at'    => now(),
    ];
    \Illuminate\Support\Facades\DB::table('ref_exposure_mappings')->insert($row);

    expect(fn () => \Illuminate\Support\Facades\DB::table('ref_exposure_mappings')->insert($row))
        ->toThrow(\Illuminate\Database\QueryException::class);

    // Same exposure mapping to a DIFFERENT engine code is allowed.
    \Illuminate\Support\Facades\DB::table('ref_exposure_mappings')->insert([
        'exposure_code' => 'CONTACT_SICK_PERSON',
        'engine_code'   => 'close_contact_traveler',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    expect(\Illuminate\Support\Facades\DB::table('ref_exposure_mappings')->count())->toBe(2);
});

test('ref_endemic_countries enforces (disease_code, country_code) uniqueness', function () {
    $row = [
        'disease_code' => 'ebola_zaire',
        'country_code' => 'COD',
        'created_at'   => now(),
        'updated_at'   => now(),
    ];
    \Illuminate\Support\Facades\DB::table('ref_endemic_countries')->insert($row);

    expect(fn () => \Illuminate\Support\Facades\DB::table('ref_endemic_countries')->insert($row))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

// ─────────────────────────────────────────────────────────────────────────
// JSON column round-trip — payload columns survive serialise / read.
// ─────────────────────────────────────────────────────────────────────────
test('ref_engine_config round-trips a JSON config_value', function () {
    $payload = [
        'formula' => 'gate_pass * (signature * 1.5 + generic * 0.6) - contradictions * 0.8',
        'thresholds' => ['ALERT' => 0.65, 'WATCH' => 0.45],
    ];

    \Illuminate\Support\Facades\DB::table('ref_engine_config')->insert([
        'config_key'   => 'engine.formula',
        'description'  => 'WHO-POE Unified Explainable Matcher v3 formula',
        'config_value' => json_encode($payload),
        'version'      => '3.0.0',
        'section'      => 'engine',
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    $row = \Illuminate\Support\Facades\DB::table('ref_engine_config')
        ->where('config_key', 'engine.formula')->first();

    expect($row)->not->toBeNull();
    expect(json_decode((string) $row->config_value, true))->toBe($payload);
});

test('ref_diseases round-trips JSON gates / weights / sources', function () {
    $gates = ['hemorrhage_required' => true, 'min_signature_count' => 2];
    $weights = ['fever' => 1.4, 'bleeding' => 2.1];
    $sources = [['id' => 'WHO_IHR_2005_ANNEX2', 'title' => 'IHR 2005 Annex 2']];

    \Illuminate\Support\Facades\DB::table('ref_diseases')->insert([
        'disease_code'    => 'ebola_zaire',
        'display_name'    => 'Ebola virus disease (Zaire)',
        'ihr_tier'        => 1,
        'who_syndrome'    => 'VHF',
        'gates'           => json_encode($gates),
        'symptom_weights' => json_encode($weights),
        'sources'         => json_encode($sources),
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $row = \Illuminate\Support\Facades\DB::table('ref_diseases')
        ->where('disease_code', 'ebola_zaire')->first();

    expect(json_decode((string) $row->gates, true))->toBe($gates);
    expect(json_decode((string) $row->symptom_weights, true))->toBe($weights);
    expect(json_decode((string) $row->sources, true))->toBe($sources);
});

// ─────────────────────────────────────────────────────────────────────────
// Idempotency — re-running up() does NOT throw.  This is the safety
// guarantee the migration's `if (!Schema::hasTable(...))` guards exist for.
// ─────────────────────────────────────────────────────────────────────────
test('REF-1 migration up() is idempotent — replay does not throw', function () {
    expect(fn () => ref1_apply_migration_up())
        ->not->toThrow(\Throwable::class);

    foreach (['ref_poes', 'ref_diseases', 'ref_engine_config'] as $table) {
        expect(\Illuminate\Support\Facades\Schema::hasTable($table))->toBeTrue();
    }
});

// ─────────────────────────────────────────────────────────────────────────
// Down rollback — every table cleanly disappears.
// ─────────────────────────────────────────────────────────────────────────
test('REF-1 migration down() drops every table it created', function () {
    ref1_apply_migration_down();

    foreach ([
        'ref_poes', 'ref_diseases', 'ref_symptoms', 'ref_exposures',
        'ref_exposure_mappings', 'ref_engine_config', 'ref_endemic_countries',
    ] as $table) {
        expect(\Illuminate\Support\Facades\Schema::hasTable($table))
            ->toBeFalse("expected {$table} to be dropped");
    }

    // Re-up so afterEach() does not double-drop.
    ref1_apply_migration_up();
});

// ─────────────────────────────────────────────────────────────────────────
// Empty-state contract — every table starts at zero rows.
// ─────────────────────────────────────────────────────────────────────────
test('every reference table is empty immediately after REF-1 migration', function () {
    foreach ([
        'ref_poes', 'ref_diseases', 'ref_symptoms', 'ref_exposures',
        'ref_exposure_mappings', 'ref_engine_config', 'ref_endemic_countries',
    ] as $table) {
        expect(\Illuminate\Support\Facades\DB::table($table)->count())
            ->toBe(0, "{$table} should be empty pre-seed");
    }
});
