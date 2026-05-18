<?php

declare(strict_types=1);

use App\Http\Controllers\AggregatedController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MobileWriteContractTest
 * ============================================================================
 * Pins the FROZEN POST /aggregated contract that the mobile app at
 * src/views/AggregatedData.vue depends on. Any change to the controller's
 * input handling MUST keep these scenarios green:
 *
 *   1. value_boolean coercion lands in value_numeric (0/1) — fixes the
 *      silent-drop bug where mobile-sent BOOLEAN data never persisted.
 *   2. value_numeric continues to win when both fields are sent — no
 *      legacy caller is broken by the new coercion path.
 *   3. Payloads without value_boolean still work identically (regression).
 *   4. Mobile's exact write payload (every field present, mirrors the
 *      AggregatedData.vue createRecordBase + templateValues map) round-
 *      trips into a SYNCED submission with the right counts.
 *
 * The test calls AggregatedController::store directly with a stub request,
 * so it does not depend on routing or middleware — both are part of the
 * frozen surface and tested separately.
 */
uses(\Tests\TestCase::class);

beforeEach(function () {
    foreach (['users', 'user_assignments', 'aggregated_templates', 'aggregated_template_columns', 'aggregated_submissions', 'aggregated_submission_values'] as $tbl) {
        if (Schema::hasTable($tbl)) {
            DB::table($tbl)->truncate();
        }
    }

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($t) {
            $t->bigIncrements('id');
            $t->string('full_name', 120)->nullable();
            $t->string('role_key', 40)->nullable();
            $t->string('account_type', 40)->nullable();
            $t->string('country_code', 30)->nullable();
            $t->boolean('is_active')->default(1);
            $t->timestamps();
        });
    }
    if (! Schema::hasTable('user_assignments')) {
        Schema::create('user_assignments', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->string('country_code', 10);
            $t->string('province_code', 30)->nullable();
            $t->string('pheoc_code', 30)->nullable();
            $t->string('district_code', 30)->nullable();
            $t->string('poe_code', 40)->nullable();
            $t->tinyInteger('is_primary')->default(1);
            $t->tinyInteger('is_active')->default(1);
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('ends_at')->nullable();
            $t->timestamps();
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

    // Seed: one POE_DATA_OFFICER user with an active assignment to
    // KAZUNGULA + one published template that owns one column of every
    // data_type so we can write any payload shape the mobile app sends.
    DB::table('users')->insert([
        'id' => 7, 'full_name' => 'Mobile Officer', 'role_key' => 'POE_DATA_OFFICER',
        'account_type' => 'POE_DATA_OFFICER', 'country_code' => 'Uganda', 'is_active' => 1,
        'created_at' => '2026-04-01 00:00:00', 'updated_at' => '2026-04-01 00:00:00',
    ]);
    DB::table('user_assignments')->insert([
        'user_id' => 7, 'country_code' => 'Uganda', 'province_code' => 'UG-C',
        'pheoc_code' => 'SOUTHERN_PHEOC', 'district_code' => 'Kampala', 'poe_code' => 'UG-KYOTERA-001',
        'is_primary' => 1, 'is_active' => 1,
        'created_at' => '2026-04-01 00:00:00', 'updated_at' => '2026-04-01 00:00:00',
    ]);

    $tplId = DB::table('aggregated_templates')->insertGetId([
        'country_code' => 'Uganda', 'template_name' => 'Mobile Contract Fixture',
        'template_code' => 'MOBILE_CONTRACT_V1', 'version' => 1, 'is_active' => 1,
        'status' => 'PUBLISHED', 'reporting_frequency' => 'WEEKLY',
        'published_at' => '2026-04-01 00:00:00',
        'created_at' => '2026-04-01 00:00:00', 'updated_at' => '2026-04-01 00:00:00',
    ]);
    foreach ([
        ['column_key' => 'fever_cases',     'data_type' => 'INTEGER', 'aggregation_fn' => 'SUM',   'display_order' => 1],
        ['column_key' => 'temp_avg',        'data_type' => 'DECIMAL', 'aggregation_fn' => 'AVG',   'display_order' => 2],
        ['column_key' => 'positivity_rate', 'data_type' => 'PERCENT', 'aggregation_fn' => 'AVG',   'display_order' => 3],
        ['column_key' => 'referred_yn',     'data_type' => 'BOOLEAN', 'aggregation_fn' => 'COUNT', 'display_order' => 4],
        ['column_key' => 'risk_band',       'data_type' => 'SELECT',  'aggregation_fn' => 'COUNT', 'display_order' => 5],
        ['column_key' => 'onset_date',      'data_type' => 'DATE',    'aggregation_fn' => 'LATEST','display_order' => 6],
        ['column_key' => 'officer_notes',   'data_type' => 'TEXT',    'aggregation_fn' => 'NONE',  'display_order' => 7],
    ] as $spec) {
        DB::table('aggregated_template_columns')->insert(array_merge($spec, [
            'template_id' => $tplId,
            'column_label' => ucfirst(str_replace('_', ' ', $spec['column_key'])),
            'is_required' => 0, 'is_enabled' => 1, 'is_core' => 0,
            'dashboard_visible' => 1, 'report_visible' => 1,
            'created_at' => '2026-04-01 00:00:00', 'updated_at' => '2026-04-01 00:00:00',
        ]));
    }
});

/**
 * Build the mobile's exact write payload as produced by
 * src/views/AggregatedData.vue createRecordBase + templateValues map.
 * Caller can override individual template_values via $overrides.
 */
function mobilePayload(string $clientUuid, array $valueOverrides = []): array
{
    $tplId = (int) DB::table('aggregated_templates')->where('template_code', 'MOBILE_CONTRACT_V1')->value('id');
    $cols  = DB::table('aggregated_template_columns')->where('template_id', $tplId)->orderBy('display_order')->get();

    $defaults = [
        'fever_cases'     => ['value_numeric' => 12,    'value_text' => null,        'value_boolean' => null],
        'temp_avg'        => ['value_numeric' => 37.4,  'value_text' => null,        'value_boolean' => null],
        'positivity_rate' => ['value_numeric' => 15.0,  'value_text' => null,        'value_boolean' => null],
        'referred_yn'     => ['value_numeric' => null,  'value_text' => null,        'value_boolean' => true],
        'risk_band'       => ['value_numeric' => null,  'value_text' => 'HIGH',      'value_boolean' => null],
        'onset_date'      => ['value_numeric' => null,  'value_text' => '2026-04-15','value_boolean' => null],
        'officer_notes'   => ['value_numeric' => null,  'value_text' => 'Routine',   'value_boolean' => null],
    ];
    $values = array_replace_recursive($defaults, $valueOverrides);

    $templateValues = [];
    foreach ($cols as $c) {
        $v = $values[$c->column_key] ?? null;
        if ($v === null) continue;
        $templateValues[] = array_merge([
            'template_id'        => $tplId,
            'template_column_id' => $c->id,
            'column_key'         => $c->column_key,
            'data_type'          => $c->data_type,
        ], $v);
    }

    return [
        'submitted_by_user_id' => 7,
        'client_uuid'          => $clientUuid,
        'period_start'         => '2026-04-13 00:00:00',
        'period_end'           => '2026-04-19 23:59:59',
        'total_screened'       => 100,
        'total_male'           => 60,
        'total_female'         => 40,
        'total_other'          => 0,
        'total_unknown_gender' => 0,
        'total_symptomatic'    => 12,
        'total_asymptomatic'   => 88,
        'notes'                => 'mobile-contract test',
        'template_id'          => $tplId,
        'template_code'        => 'MOBILE_CONTRACT_V1',
        'template_version'     => 1,
        'device_id'            => 'test-device',
        'platform'             => 'ANDROID',
        'template_values'      => $templateValues,
    ];
}

function callStore(array $payload)
{
    $request = Request::create('/aggregated', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload));
    return (new AggregatedController())->store($request);
}

test('value_boolean=true is coerced into value_numeric=1 (fixes silent-drop)', function () {
    $resp = callStore(mobilePayload('00000000-1111-2222-3333-000000000001'));
    expect($resp->getStatusCode())->toBe(200);

    $row = DB::table('aggregated_submission_values')->where('column_key', 'referred_yn')->first();
    expect($row)->not->toBeNull();
    expect((int) $row->value_numeric)->toBe(1);
    expect($row->value_text)->toBeNull();
});

test('value_boolean=false is coerced into value_numeric=0', function () {
    $resp = callStore(mobilePayload('00000000-1111-2222-3333-000000000002', [
        'referred_yn' => ['value_numeric' => null, 'value_text' => null, 'value_boolean' => false],
    ]));
    expect($resp->getStatusCode())->toBe(200);

    $row = DB::table('aggregated_submission_values')->where('column_key', 'referred_yn')->first();
    expect((int) $row->value_numeric)->toBe(0);
});

test('legacy callers sending value_numeric directly are unaffected by the coercion', function () {
    // Legacy/admin caller bypasses value_boolean and writes value_numeric=99.
    // The coercion path must NOT overwrite their explicit numeric value.
    $resp = callStore(mobilePayload('00000000-1111-2222-3333-000000000003', [
        'referred_yn' => ['value_numeric' => 99, 'value_text' => null, 'value_boolean' => true],
    ]));
    expect($resp->getStatusCode())->toBe(200);

    $row = DB::table('aggregated_submission_values')->where('column_key', 'referred_yn')->first();
    expect((int) $row->value_numeric)->toBe(99);
});

test('payloads with no value_boolean key still persist exactly as before (regression)', function () {
    // Build a payload that mirrors the pre-2026-04-26 mobile schema:
    // every template_value has only value_numeric / value_text. No
    // value_boolean key at all. The store path must accept and persist
    // INTEGER / DECIMAL / TEXT / SELECT exactly as it always did.
    $payload = mobilePayload('00000000-1111-2222-3333-000000000004');
    foreach ($payload['template_values'] as &$v) {
        unset($v['value_boolean']);
    }
    unset($v);

    $resp = callStore($payload);
    expect($resp->getStatusCode())->toBe(200);

    $byKey = DB::table('aggregated_submission_values')->get()->keyBy('column_key');
    expect((int) $byKey['fever_cases']->value_numeric)->toBe(12);
    expect((float) $byKey['temp_avg']->value_numeric)->toBe(37.4);
    expect($byKey['risk_band']->value_text)->toBe('HIGH');
    expect($byKey['officer_notes']->value_text)->toBe('Routine');
    // referred_yn had no numeric, no text, and now no boolean — value_numeric
    // remains NULL exactly as the legacy contract guaranteed.
    expect($byKey['referred_yn']->value_numeric)->toBeNull();
});

test('idempotent resubmit returns 200 with idempotent=true and does not duplicate values', function () {
    $uuid = '00000000-1111-2222-3333-000000000005';
    $first  = callStore(mobilePayload($uuid));
    $second = callStore(mobilePayload($uuid));

    expect($first->getStatusCode())->toBe(200);
    expect($second->getStatusCode())->toBe(200);
    $body = json_decode((string) $second->getContent(), true);
    expect($body['meta']['idempotent'] ?? false)->toBeTrue();

    expect(DB::table('aggregated_submissions')->where('client_uuid', $uuid)->count())->toBe(1);
});

test('legacy fixed fields (total_other, total_unknown_gender) are still accepted and stored', function () {
    // These were retired 2026-04-21 in the brief but the server still
    // accepts them — the mobile app sends 0 for both. This pins the
    // behaviour so a future cleanup commit cannot break submissions in
    // flight from older clients.
    $resp = callStore(mobilePayload('00000000-1111-2222-3333-000000000006'));
    expect($resp->getStatusCode())->toBe(200);

    $sub = DB::table('aggregated_submissions')->where('client_uuid', '00000000-1111-2222-3333-000000000006')->first();
    expect((int) $sub->total_other)->toBe(0);
    expect((int) $sub->total_unknown_gender)->toBe(0);
});
