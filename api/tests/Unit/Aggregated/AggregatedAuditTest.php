<?php

declare(strict_types=1);

use App\Services\AggregatedAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AggregatedAuditTest
 * ============================================================================
 * Pins two contracts of the AggregatedAudit writer:
 *
 *   1. Happy path — every state-change verb the controller emits lands as
 *      one row in aggregated_audit with the right action, entity_type,
 *      template_id denormalisation, and JSON-encoded before/after snapshots.
 *
 *   2. Failure isolation — if the audit table is missing, dropped mid-deploy,
 *      or the insert throws for any reason, the writer must NOT propagate the
 *      exception. The user-facing admin write must always succeed.
 *
 * The writer is exercised in isolation here (no controller); the controller's
 * own integration with the writer is covered by AdminWriteIdempotencyTest +
 * the existing live-MySQL smoke path.
 */
uses(\Tests\TestCase::class);

beforeEach(function () {
    if (Schema::hasTable('aggregated_audit')) {
        DB::table('aggregated_audit')->truncate();
    } else {
        Schema::create('aggregated_audit', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->string('role_key', 40)->nullable();
            $t->string('scope_level', 20)->nullable();
            $t->string('country_code', 30)->nullable();
            $t->string('action', 40);
            $t->string('entity_type', 20);
            $t->unsignedBigInteger('entity_id');
            $t->unsignedBigInteger('template_id')->nullable();
            $t->text('before_json')->nullable();
            $t->text('after_json')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->char('request_id', 36)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }
});

function nationalAdminScope(): array
{
    return [
        'user_id'      => 42,
        'role_key'     => 'NATIONAL_ADMIN',
        'scope_level'  => 'NATIONAL',
        'country_code' => 'Uganda',
        'is_super'     => true,
    ];
}

test('record persists one row with action, entity, template_id and snapshots', function () {
    $audit = new AggregatedAudit();
    $req   = Request::create('/admin/aggregated/studio/template', 'POST', [], [], [], ['HTTP_USER_AGENT' => 'TestRunner/1.0']);

    $audit->record(
        $req,
        nationalAdminScope(),
        'CREATE',
        'TEMPLATE',
        77,
        null,
        ['id' => 77, 'template_code' => 'NEW_V1', 'status' => 'DRAFT'],
        77,
    );

    $rows = DB::table('aggregated_audit')->get();
    expect($rows)->toHaveCount(1);
    $r = $rows->first();
    expect($r->action)->toBe('CREATE');
    expect($r->entity_type)->toBe('TEMPLATE');
    expect((int) $r->entity_id)->toBe(77);
    expect((int) $r->template_id)->toBe(77);
    expect($r->before_json)->toBeNull();
    expect($r->after_json)->toContain('NEW_V1');
    expect($r->user_agent)->toBe('TestRunner/1.0');
    // Per-request id should be a UUID (8-4-4-4-12 hex).
    expect($r->request_id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i');
});

test('record encodes role_key, scope_level, country_code from the scope', function () {
    $audit = new AggregatedAudit();
    $audit->record(Request::create('/x', 'POST'), [
        'user_id' => 1, 'role_key' => 'NATIONAL_ADMIN',
        'scope_level' => 'NATIONAL', 'country_code' => 'Uganda',
    ], 'PUBLISH', 'TEMPLATE', 9, ['status' => 'DRAFT'], ['status' => 'PUBLISHED'], 9);

    $r = DB::table('aggregated_audit')->first();
    expect($r->role_key)->toBe('NATIONAL_ADMIN');
    expect($r->scope_level)->toBe('NATIONAL');
    expect($r->country_code)->toBe('Uganda');
    expect($r->before_json)->toContain('DRAFT');
    expect($r->after_json)->toContain('PUBLISHED');
});

test('multiple calls within one request share the same request_id (correlation)', function () {
    $audit = new AggregatedAudit();
    $req   = Request::create('/x', 'POST');

    $audit->record($req, nationalAdminScope(), 'PUBLISH',  'TEMPLATE', 9, null, null, 9);
    $audit->record($req, nationalAdminScope(), 'COLUMN_BULK', 'COLUMN', 0, null, null, 9);

    $rids = DB::table('aggregated_audit')->pluck('request_id')->unique();
    expect($rids)->toHaveCount(1);
});

test('audit failure does NOT throw — caller is shielded so foreground action survives', function () {
    // Drop the table to simulate a half-deployed environment where the
    // audit write would explode. The writer must swallow it.
    Schema::dropIfExists('aggregated_audit');

    $audit = new AggregatedAudit();
    expect(fn () => $audit->record(
        Request::create('/x', 'POST'),
        nationalAdminScope(),
        'CREATE',
        'TEMPLATE',
        1,
        null,
        ['id' => 1],
        1,
    ))->not->toThrow(Throwable::class);
});

test('action and entity_type strings are normalised and length-clamped', function () {
    $audit = new AggregatedAudit();
    // entity_type comes through strtoupper + 20-char clamp; action 40-char clamp.
    $audit->record(
        Request::create('/x', 'POST'),
        nationalAdminScope(),
        'A_VERY_LONG_ACTION_NAME_THAT_EXCEEDS_THE_FORTY_CHAR_BUDGET',
        'submission',
        7,
        null,
        null,
        null,
    );
    $r = DB::table('aggregated_audit')->first();
    expect(strlen($r->action))->toBeLessThanOrEqual(40);
    expect($r->entity_type)->toBe('SUBMISSION');
});
