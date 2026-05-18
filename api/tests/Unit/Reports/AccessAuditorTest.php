<?php

declare(strict_types=1);

use App\Services\Reports\AccessAuditor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(\Tests\TestCase::class);

/**
 * AccessAuditorTest — proves the append-only audit writer used by every
 * Reports controller. Mirrors ExportWriterTest's pattern: bootstrap a
 * minimal version of the audit table on the in-memory connection so the
 * test is self-contained, then exercise the writer.
 *
 * Append-only contract (no updated_at column) is verified separately.
 */

beforeEach(function () {
    if (! Schema::hasTable('reports_access_audit')) {
        Schema::create('reports_access_audit', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->string('role_key', 40);
            $t->string('account_type', 40)->nullable();
            $t->string('scope_level', 20);
            $t->boolean('is_super')->default(false);
            $t->json('scope_json')->nullable();
            $t->string('report_key', 40);
            $t->string('action', 20);
            $t->json('filters_json')->nullable();
            $t->unsignedInteger('row_count')->default(0);
            $t->unsignedInteger('suppressed_count')->default(0);
            $t->json('pii_columns_revealed')->nullable();
            $t->unsignedSmallInteger('http_status')->default(200);
            $t->char('request_id', 36)->nullable();
            $t->dateTime('created_at')->useCurrent();
        });
    }
});

function aatScope(array $override = []): array
{
    return array_merge([
        'user_id'      => 9_999_001,
        'role_key'     => 'NATIONAL_ADMIN',
        'account_type' => 'NATIONAL_ADMIN',
        'scope_level'  => 'NATIONAL',
        'is_super'     => true,
        'country_code' => 'ZM',
        'countries'    => ['ZM'],
        'provinces'    => [],
        'districts'    => [],
        'poes'         => [],
        'primary_poe'  => null,
        'assignments'  => [],
        'label'        => 'Test scope',
    ], $override);
}

test('recordView writes one row with the expected shape', function () {
    $auditor = app(AccessAuditor::class);
    $request = Request::create('/admin/reports/rpt-volume/data', 'GET');

    $auditor->recordView(
        $request,
        aatScope(),
        'rpt-volume',
        ['year' => 2026, 'poe' => 'Mwami'],
        ['row_count' => 12, 'suppressed_count' => 3],
    );

    $row = DB::table('reports_access_audit')->where('user_id', 9_999_001)->latest('id')->first();
    expect($row)->not->toBeNull();
    expect($row->action)->toBe('VIEW');
    expect($row->report_key)->toBe('rpt-volume');
    expect($row->scope_level)->toBe('NATIONAL');
    expect((int) $row->is_super)->toBe(1);
    expect((int) $row->row_count)->toBe(12);
    expect((int) $row->suppressed_count)->toBe(3);
    expect($row->filters_json)->not->toBeNull();
    expect($row->request_id)->not->toBeNull();
    $filters = json_decode((string) $row->filters_json, true);
    expect($filters)->toBe(['year' => 2026, 'poe' => 'Mwami']);
});

test('recordPiiReveal writes a row and shares request_id with the prior view in the same request', function () {
    $auditor = app(AccessAuditor::class);
    $request = Request::create('/admin/reports/rpt-registry/data', 'GET');

    $auditor->recordView($request, aatScope(['user_id' => 9_999_002]), 'rpt-registry', ['year' => 2026], ['row_count' => 0]);
    $auditor->recordPiiReveal(
        $request,
        aatScope(['user_id' => 9_999_002]),
        'rpt-registry',
        ['year' => 2026],
        25,
        ['traveler_full_name', 'phone_number', 'email'],
    );

    $rows = DB::table('reports_access_audit')->where('user_id', 9_999_002)->orderBy('id')->get();
    expect($rows)->toHaveCount(2);
    expect($rows[0]->action)->toBe('VIEW');
    expect($rows[1]->action)->toBe('PII_REVEAL');
    expect($rows[0]->request_id)->toBe($rows[1]->request_id);
    expect((int) $rows[1]->row_count)->toBe(25);
    $cols = json_decode((string) $rows[1]->pii_columns_revealed, true);
    expect($cols)->toBe(['traveler_full_name', 'phone_number', 'email']);
});

test('recordDenied writes a 403 row with no filters', function () {
    $auditor = app(AccessAuditor::class);
    $request = Request::create('/admin/reports/rpt-geo/data', 'GET');

    $auditor->recordDenied(
        $request,
        aatScope(['user_id' => 9_999_003, 'role_key' => 'POE_OFFICER', 'scope_level' => 'POE', 'is_super' => false]),
        'rpt-geo',
    );

    $row = DB::table('reports_access_audit')->where('user_id', 9_999_003)->where('action', 'DENIED')->first();
    expect($row)->not->toBeNull();
    expect($row->report_key)->toBe('rpt-geo');
    expect((int) $row->http_status)->toBe(403);
    expect((int) $row->is_super)->toBe(0);
    expect($row->scope_level)->toBe('POE');
});

test('audit insert never throws to caller', function () {
    $auditor = app(AccessAuditor::class);
    $request = Request::create('/admin/reports/rpt-volume/data', 'GET');

    $bigFilters = ['poe' => array_fill(0, 200, 'Mwami')];

    expect(fn () => $auditor->recordView(
        $request,
        aatScope(['user_id' => 9_999_004]),
        'rpt-volume',
        $bigFilters,
    ))->not->toThrow(\Throwable::class);
});

test('table is append-only — there is no updated_at column on the production schema', function () {
    // The production migration adds NO updated_at column. The sqlite mirror
    // we create in beforeEach also has none. This test pins both.
    $cols = collect(Schema::getColumnListing('reports_access_audit'));
    expect($cols)->not->toContain('updated_at', 'reports_access_audit must be append-only — no updated_at');
    expect($cols)->toContain('created_at');

    // Confirm the production migration likewise has no updated_at.
    $migration = file_get_contents(__DIR__ . '/../../../database/migrations/2026_04_26_010000_create_reports_access_audit_table.php');
    expect($migration)->not->toContain('timestamps()');
    expect($migration)->not->toContain("dateTime('updated_at')");
});
