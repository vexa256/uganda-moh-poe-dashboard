<?php

declare(strict_types=1);

use App\Services\CountryResolver;
use App\Services\ReportEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ReportEngineDeterminismTest
 * ============================================================================
 * The Reports console for the Aggregated IDSR domain depends on a single
 * type-aware engine — App\Services\ReportEngine — that must give identical
 * output for identical input no matter how many times it runs, and must
 * handle ANY future template purely from its column metadata (data_type +
 * aggregation_fn) without a hardcoded `template_code` branch.
 *
 * This test pins the engine to a fixture covering every supported data_type
 * (INTEGER, DECIMAL, PERCENT, BOOLEAN, SELECT, DATE, TEXT) across two POEs
 * over three reporting periods, runs the engine twice, strips the wall-clock
 * `generated_at` field, and asserts byte-equal output. A second test
 * confirms the engine produces a column entry for every data_type — proving
 * the dispatcher is fully type-driven.
 *
 * Pattern follows tests/Unit/Reports/ExportWriterTest.php — opt out of the
 * full migration graph (sqlite :memory: cannot run every migration cleanly)
 * and create the four `aggregated_*` tables + a stub `users` table inline.
 */
uses(\Tests\TestCase::class);

beforeEach(function () {
    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($t) {
            $t->bigIncrements('id');
            $t->string('full_name', 120)->nullable();
            $t->timestamps();
        });
    }
    if (! Schema::hasTable('ref_countries')) {
        Schema::create('ref_countries', function ($t) {
            $t->bigIncrements('id');
            $t->string('country_code', 60);
            $t->string('iso_alpha2', 2)->nullable();
            $t->string('iso_alpha3', 3)->nullable();
            $t->string('name', 120)->nullable();
        });
    }
    if (! Schema::hasTable('aggregated_templates')) {
        Schema::create('aggregated_templates', function ($t) {
            $t->bigIncrements('id');
            $t->string('country_code', 30);
            $t->string('template_name', 120);
            $t->string('template_code', 60);
            $t->string('description', 500)->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->boolean('is_active')->default(0);
            $t->boolean('is_default')->default(0);
            $t->string('status', 20)->default('DRAFT');
            $t->string('reporting_frequency', 20)->default('WEEKLY');
            $t->dateTime('published_at')->nullable();
            $t->string('icon', 40)->nullable();
            $t->string('colour', 16)->nullable();
            $t->boolean('locked')->default(0);
            $t->dateTime('deleted_at')->nullable();
            $t->timestamps();
        });
    }
    if (! Schema::hasTable('aggregated_template_columns')) {
        Schema::create('aggregated_template_columns', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('template_id');
            $t->string('column_key', 60);
            $t->string('column_label', 160);
            $t->string('category', 40)->default('CUSTOM');
            $t->string('data_type', 20)->default('INTEGER');
            $t->boolean('is_required')->default(0);
            $t->boolean('is_enabled')->default(1);
            $t->boolean('is_core')->default(0);
            $t->string('default_value', 120)->nullable();
            $t->decimal('min_value', 14, 4)->nullable();
            $t->decimal('max_value', 14, 4)->nullable();
            $t->json('select_options')->nullable();
            $t->json('validation_rules')->nullable();
            $t->unsignedInteger('display_order')->default(0);
            $t->string('placeholder', 160)->nullable();
            $t->string('help_text', 500)->nullable();
            $t->boolean('dashboard_visible')->default(1);
            $t->boolean('report_visible')->default(1);
            $t->string('aggregation_fn', 20)->default('SUM');
            $t->dateTime('deleted_at')->nullable();
            $t->timestamps();
        });
    }
    if (! Schema::hasTable('aggregated_submissions')) {
        Schema::create('aggregated_submissions', function ($t) {
            $t->bigIncrements('id');
            $t->string('client_uuid', 36);
            $t->string('idempotency_key', 64)->nullable();
            $t->string('reference_data_version', 40)->default('rda-test');
            $t->dateTime('server_received_at')->nullable();
            $t->string('country_code', 30);
            $t->string('province_code', 30)->nullable();
            $t->string('pheoc_code', 30)->nullable();
            $t->string('district_code', 30);
            $t->string('poe_code', 40);
            $t->unsignedBigInteger('submitted_by_user_id');
            $t->dateTime('period_start');
            $t->dateTime('period_end');
            $t->unsignedInteger('total_screened')->default(0);
            $t->unsignedInteger('total_male')->default(0);
            $t->unsignedInteger('total_female')->default(0);
            $t->unsignedInteger('total_other')->default(0);
            $t->unsignedInteger('total_unknown_gender')->default(0);
            $t->unsignedInteger('total_symptomatic')->default(0);
            $t->unsignedInteger('total_asymptomatic')->default(0);
            $t->string('notes', 255)->nullable();
            $t->unsignedBigInteger('template_id')->nullable();
            $t->string('template_code', 60)->nullable();
            $t->unsignedInteger('template_version')->nullable();
            $t->string('device_id', 80)->default('test-device');
            $t->string('app_version', 40)->nullable();
            $t->string('platform', 10)->default('WEB');
            $t->unsignedInteger('record_version')->default(1);
            $t->dateTime('deleted_at')->nullable();
            $t->string('sync_status', 20)->default('SYNCED');
            $t->dateTime('synced_at')->nullable();
            $t->unsignedInteger('sync_attempt_count')->default(0);
            $t->string('last_sync_error', 500)->nullable();
            $t->timestamps();
        });
    }
    if (! Schema::hasTable('ref_districts')) {
        Schema::create('ref_districts', function ($t) {
            $t->bigIncrements('id');
            $t->string('country_code', 30);
            $t->string('province_code', 30)->nullable();
            $t->string('code', 30);
            $t->string('name', 120);
            $t->boolean('is_active')->default(1);
            $t->dateTime('deleted_at')->nullable();
            $t->timestamps();
        });
    }
    if (! Schema::hasTable('ref_poes')) {
        Schema::create('ref_poes', function ($t) {
            $t->bigIncrements('id');
            $t->string('country_code', 30);
            $t->unsignedBigInteger('district_id')->nullable();
            $t->string('district', 60)->nullable();
            $t->string('poe_code', 40);
            $t->string('poe_name', 120);
            $t->boolean('is_active')->default(1);
            $t->dateTime('deleted_at')->nullable();
            $t->timestamps();
        });
    }
    if (! Schema::hasTable('aggregated_submission_values')) {
        Schema::create('aggregated_submission_values', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('submission_id');
            $t->unsignedBigInteger('template_id');
            $t->unsignedBigInteger('template_column_id');
            $t->string('column_key', 60);
            $t->decimal('value_numeric', 14, 4)->nullable();
            $t->string('value_text', 500)->nullable();
            $t->json('value_json')->nullable();
            $t->timestamps();
        });
    }
});

/**
 * Build the fixture: one template, one column of every data_type, six
 * submissions across two POEs over three weeks. Returns the template id.
 *
 * Fixed values throughout — no randomness — so the snapshot is stable.
 */
function aggregatedFixture(): int
{
    DB::table('users')->insertOrIgnore([
        ['id' => 99, 'full_name' => 'Test Officer', 'created_at' => '2026-04-01 00:00:00', 'updated_at' => '2026-04-01 00:00:00'],
    ]);

    // Reference geography — coverage analysis joins these to compute the
    // expected POE roster for the scope. Two POEs in two districts mirror
    // the submission fixture below.
    DB::table('ref_districts')->insertOrIgnore([
        ['id' => 1, 'country_code' => 'Uganda', 'province_code' => 'UG-N',  'code' => 'Busia',     'name' => 'Busia',     'is_active' => 1, 'created_at' => '2026-04-01 00:00:00', 'updated_at' => '2026-04-01 00:00:00'],
        ['id' => 2, 'country_code' => 'Uganda', 'province_code' => 'UG-C',  'code' => 'Kampala', 'name' => 'Kampala', 'is_active' => 1, 'created_at' => '2026-04-01 00:00:00', 'updated_at' => '2026-04-01 00:00:00'],
    ]);
    DB::table('ref_poes')->insertOrIgnore([
        ['id' => 1, 'country_code' => 'Uganda', 'district_id' => 1, 'district' => 'Busia',     'poe_code' => 'UG-BUSIA-001',   'poe_name' => 'Busia POE',   'is_active' => 1, 'created_at' => '2026-04-01 00:00:00', 'updated_at' => '2026-04-01 00:00:00'],
        ['id' => 2, 'country_code' => 'Uganda', 'district_id' => 2, 'district' => 'Kampala', 'poe_code' => 'UG-KYOTERA-001', 'poe_name' => 'Mutukula POE', 'is_active' => 1, 'created_at' => '2026-04-01 00:00:00', 'updated_at' => '2026-04-01 00:00:00'],
    ]);

    $tplId = DB::table('aggregated_templates')->insertGetId([
        'country_code'        => 'Uganda',
        'template_name'       => 'Determinism Fixture',
        'template_code'       => 'DETERMINISM_FIXTURE_V1',
        'description'         => 'Fixture covering every data_type for engine pinning.',
        'version'             => 1,
        'is_active'           => 1,
        'is_default'          => 0,
        'status'              => 'PUBLISHED',
        'reporting_frequency' => 'WEEKLY',
        'published_at'        => '2026-04-01 00:00:00',
        'colour'              => '#10B981',
        'locked'              => 0,
        'created_at'          => '2026-04-01 00:00:00',
        'updated_at'          => '2026-04-01 00:00:00',
    ]);

    $columnSpecs = [
        ['column_key' => 'fever_cases',       'column_label' => 'Fever cases',       'data_type' => 'INTEGER', 'aggregation_fn' => 'SUM',    'display_order' => 1],
        ['column_key' => 'temp_avg',          'column_label' => 'Average temp',      'data_type' => 'DECIMAL', 'aggregation_fn' => 'AVG',    'display_order' => 2],
        ['column_key' => 'positivity_rate',   'column_label' => 'Positivity %',      'data_type' => 'PERCENT', 'aggregation_fn' => 'AVG',    'display_order' => 3],
        ['column_key' => 'referred_yn',       'column_label' => 'Referred?',         'data_type' => 'BOOLEAN', 'aggregation_fn' => 'COUNT',  'display_order' => 4],
        ['column_key' => 'risk_band',         'column_label' => 'Risk band',         'data_type' => 'SELECT',  'aggregation_fn' => 'COUNT',  'display_order' => 5],
        ['column_key' => 'onset_date',        'column_label' => 'Onset date',        'data_type' => 'DATE',    'aggregation_fn' => 'LATEST', 'display_order' => 6],
        ['column_key' => 'officer_notes',     'column_label' => 'Officer notes',     'data_type' => 'TEXT',    'aggregation_fn' => 'NONE',   'display_order' => 7],
    ];
    $colIdsByKey = [];
    foreach ($columnSpecs as $spec) {
        $colIdsByKey[$spec['column_key']] = DB::table('aggregated_template_columns')->insertGetId(array_merge($spec, [
            'template_id'      => $tplId,
            'category'         => $spec['data_type'] === 'SELECT' ? 'CUSTOM' : 'CUSTOM',
            'is_required'      => 0,
            'is_enabled'       => 1,
            'is_core'          => 0,
            'select_options'   => $spec['data_type'] === 'SELECT' ? json_encode(['LOW' => 'Low', 'MEDIUM' => 'Medium', 'HIGH' => 'High']) : null,
            'dashboard_visible'=> 1,
            'report_visible'   => 1,
            'created_at'       => '2026-04-01 00:00:00',
            'updated_at'       => '2026-04-01 00:00:00',
        ]));
    }

    // Six submissions: 2 POEs × 3 weeks. Numbers chosen to give predictable
    // sums, distinct-period buckets, a SELECT distribution, and a boolean
    // mix that won't collapse to all-yes or all-no.
    $rows = [
        ['poe' => 'UG-BUSIA-001',  'period_start' => '2026-04-06 00:00:00', 'period_end' => '2026-04-12 23:59:59', 'screened' => 100, 'male' => 60, 'female' => 40, 'symp' => 12, 'asymp' => 88, 'fever' => 12, 'temp' => 37.2, 'positivity' => 12.0, 'referred' => 1, 'risk' => 'HIGH',   'onset' => '2026-04-08'],
        ['poe' => 'UG-BUSIA-001',  'period_start' => '2026-04-13 00:00:00', 'period_end' => '2026-04-19 23:59:59', 'screened' => 120, 'male' => 70, 'female' => 50, 'symp' => 18, 'asymp' => 102,'fever' => 18, 'temp' => 37.4, 'positivity' => 15.0, 'referred' => 0, 'risk' => 'MEDIUM', 'onset' => '2026-04-15'],
        ['poe' => 'UG-BUSIA-001',  'period_start' => '2026-04-20 00:00:00', 'period_end' => '2026-04-26 23:59:59', 'screened' => 80,  'male' => 50, 'female' => 30, 'symp' => 6,  'asymp' => 74, 'fever' => 6,  'temp' => 36.9, 'positivity' => 7.5,  'referred' => 0, 'risk' => 'LOW',    'onset' => '2026-04-22'],
        ['poe' => 'UG-KYOTERA-001','period_start' => '2026-04-06 00:00:00', 'period_end' => '2026-04-12 23:59:59', 'screened' => 200, 'male' => 110,'female' => 90, 'symp' => 30, 'asymp' => 170,'fever' => 30, 'temp' => 37.6, 'positivity' => 15.0, 'referred' => 1, 'risk' => 'HIGH',   'onset' => '2026-04-09'],
        ['poe' => 'UG-KYOTERA-001','period_start' => '2026-04-13 00:00:00', 'period_end' => '2026-04-19 23:59:59', 'screened' => 180, 'male' => 100,'female' => 80, 'symp' => 22, 'asymp' => 158,'fever' => 22, 'temp' => 37.3, 'positivity' => 12.2, 'referred' => 1, 'risk' => 'MEDIUM', 'onset' => '2026-04-16'],
        ['poe' => 'UG-KYOTERA-001','period_start' => '2026-04-20 00:00:00', 'period_end' => '2026-04-26 23:59:59', 'screened' => 150, 'male' => 90, 'female' => 60, 'symp' => 9,  'asymp' => 141,'fever' => 9,  'temp' => 37.0, 'positivity' => 6.0,  'referred' => 0, 'risk' => 'LOW',    'onset' => '2026-04-23'],
    ];
    foreach ($rows as $i => $r) {
        $subId = DB::table('aggregated_submissions')->insertGetId([
            'client_uuid'        => sprintf('11111111-2222-3333-4444-%012d', $i + 1),
            'reference_data_version' => 'rda-test',
            'server_received_at' => $r['period_end'],
            'country_code'       => 'Uganda',
            'district_code'      => $r['poe'] === 'UG-BUSIA-001' ? 'Busia' : 'Kampala',
            'poe_code'           => $r['poe'],
            'submitted_by_user_id' => 99,
            'period_start'       => $r['period_start'],
            'period_end'         => $r['period_end'],
            'total_screened'     => $r['screened'],
            'total_male'         => $r['male'],
            'total_female'       => $r['female'],
            'total_symptomatic'  => $r['symp'],
            'total_asymptomatic' => $r['asymp'],
            'template_id'        => $tplId,
            'template_code'      => 'DETERMINISM_FIXTURE_V1',
            'template_version'   => 1,
            'device_id'          => 'fixture',
            'platform'           => 'WEB',
            'sync_status'        => 'SYNCED',
            'synced_at'          => $r['period_end'],
            'created_at'         => $r['period_end'],
            'updated_at'         => $r['period_end'],
        ]);
        $valuePayload = [
            ['column_key' => 'fever_cases',     'value_numeric' => $r['fever'],      'value_text' => null],
            ['column_key' => 'temp_avg',        'value_numeric' => $r['temp'],       'value_text' => null],
            ['column_key' => 'positivity_rate', 'value_numeric' => $r['positivity'], 'value_text' => null],
            ['column_key' => 'referred_yn',    'value_numeric' => $r['referred'],   'value_text' => null],
            ['column_key' => 'risk_band',      'value_numeric' => null,             'value_text' => $r['risk']],
            ['column_key' => 'onset_date',     'value_numeric' => null,             'value_text' => $r['onset']],
            ['column_key' => 'officer_notes',  'value_numeric' => null,             'value_text' => 'Routine'],
        ];
        foreach ($valuePayload as $vp) {
            DB::table('aggregated_submission_values')->insert([
                'submission_id'      => $subId,
                'template_id'        => $tplId,
                'template_column_id' => $colIdsByKey[$vp['column_key']],
                'column_key'         => $vp['column_key'],
                'value_numeric'      => $vp['value_numeric'],
                'value_text'         => $vp['value_text'],
                'value_json'         => null,
                'created_at'         => $r['period_end'],
                'updated_at'         => $r['period_end'],
            ]);
        }
    }

    return (int) $tplId;
}

/** Strip the wall-clock field so two runs at different microseconds can match. */
function stripVolatile(array $payload): array
{
    unset($payload['generated_at']);
    return $payload;
}

function nationalScope(): array
{
    return [
        'user_id'      => 99,
        'role_key'     => 'NATIONAL_ADMIN',
        'account_type' => 'NATIONAL_ADMIN',
        'scope_level'  => 'NATIONAL',
        'is_super'     => true,
        'country_code' => 'Uganda',
        'countries'    => ['Uganda'],
        'provinces'    => [],
        'districts'    => [],
        'poes'         => [],
        'primary_poe'  => null,
        'assignments'  => [],
        'label'        => 'Uganda · National',
    ];
}

test('ReportEngine analyze is byte-deterministic across consecutive runs', function () {
    $templateId = aggregatedFixture();
    $engine = new ReportEngine(new CountryResolver());
    $scope = nationalScope();

    $first  = stripVolatile($engine->analyze($templateId, $scope, []));
    $second = stripVolatile($engine->analyze($templateId, $scope, []));

    expect($first)->toBe($second);
    expect($first)->toHaveKeys(['template', 'summary', 'core_columns', 'columns', 'coverage', 'anomalies']);
    expect($first['summary']['submissions'])->toBe(6);
    expect($first['summary']['poes_reporting'])->toBe(2);
});

test('ReportEngine produces a column entry for every data_type with no template-specific branch', function () {
    $templateId = aggregatedFixture();
    $engine = new ReportEngine(new CountryResolver());

    $report = $engine->analyze($templateId, nationalScope(), []);

    $kindByKey = collect($report['columns'])->keyBy('column_key')->map(fn ($c) => $c['kind'] ?? null)->all();
    expect($kindByKey)->toMatchArray([
        'fever_cases'     => 'numeric',
        'temp_avg'        => 'numeric',
        'positivity_rate' => 'numeric',
        'referred_yn'     => 'boolean',
        'risk_band'       => 'select',
        'onset_date'      => 'date',
        'officer_notes'   => 'text',
    ]);
});

test('ReportEngine BOOLEAN column dispatches purely on data_type, counting yes/no from value_numeric', function () {
    $templateId = aggregatedFixture();
    $engine = new ReportEngine(new CountryResolver());

    $report = $engine->analyze($templateId, nationalScope(), []);
    $bool = collect($report['columns'])->firstWhere('column_key', 'referred_yn');

    // Three referred=1 + three referred=0 in fixture.
    expect($bool['kind'])->toBe('boolean');
    expect($bool['yes'])->toBe(3);
    expect($bool['no'])->toBe(3);
    expect($bool['total'])->toBe(6);
});

test('ReportEngine SELECT column tallies distribution from value_text without column-key special cases', function () {
    $templateId = aggregatedFixture();
    $engine = new ReportEngine(new CountryResolver());

    $report = $engine->analyze($templateId, nationalScope(), []);
    $sel = collect($report['columns'])->firstWhere('column_key', 'risk_band');

    expect($sel['kind'])->toBe('select');
    $byLabel = collect($sel['distribution'])->pluck('count', 'label')->all();
    // Order is by descending count; with equal counts the secondary order
    // is engine-internal and not part of the contract — only the totals are.
    ksort($byLabel);
    expect($byLabel)->toBe(['HIGH' => 2, 'LOW' => 2, 'MEDIUM' => 2]);
});
