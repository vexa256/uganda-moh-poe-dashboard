<?php

declare(strict_types=1);

use App\Services\Reports\ExportWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Opt out of RefreshDatabase — see RouteResolutionTest.php for reason. We do
// need the DB connection (sqlite :memory:) so the export-log insert works;
// that is bootstrapped by TestCase without running the full migration graph.
uses(\Tests\TestCase::class);

beforeEach(function () {
    if (! Schema::hasTable('report_export_log')) {
        Schema::create('report_export_log', function ($t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->string('report_key', 40);
            $t->json('filters_json')->nullable();
            $t->string('format', 10);
            $t->unsignedInteger('row_count')->default(0);
            $t->unsignedInteger('file_size')->default(0);
            $t->dateTime('triggered_at')->useCurrent();
            $t->dateTime('completed_at')->nullable();
            $t->unsignedInteger('download_count')->default(0);
        });
    }
});

test('CSV export streams non-empty content and logs the dispatch', function () {
    $writer = new ExportWriter();
    $resp = $writer->send(
        'rpt-volume', 'CSV',
        ['POE', 'Primary', 'Notifiable'],
        [['Mwami', 42, 5], ['Kazungula', 12, 1]],
        ['year' => 2026], 1, 'Screening Volume',
    );
    expect($resp->getStatusCode())->toBe(200);
    expect($resp->headers->get('Content-Type'))->toContain('text/csv');

    ob_start(); $resp->sendContent(); $body = ob_get_clean();
    expect(strlen($body))->toBeGreaterThan(10);
    expect($body)->toContain('Mwami');
    expect($body)->toContain('Primary');

    expect(DB::table('report_export_log')->where('report_key', 'rpt-volume')->where('format', 'CSV')->count())->toBe(1);
});

test('XLSX fallback export streams tab-delimited content', function () {
    $writer = new ExportWriter();
    $resp = $writer->send('rpt-geo', 'XLSX', ['Origin', 'Arrivals'], [['UG', 10]], [], 1);
    expect($resp->headers->get('Content-Type'))->toContain('application/vnd.ms-excel');
    ob_start(); $resp->sendContent(); $body = ob_get_clean();
    expect($body)->toContain("UG\t10");
});

test('PDF fallback export returns printable HTML', function () {
    $writer = new ExportWriter();
    $resp = $writer->send('rpt-registry', 'PDF', ['Case #', 'Traveller'], [['1', 'Anonymous-1']], [], 1, 'Registry');
    expect($resp->headers->get('Content-Type'))->toContain('text/html');
    expect($resp->headers->get('X-Print-Hint'))->toBe('pdf');
    $body = $resp->getContent();
    expect($body)->toContain('Anonymous-1');
    expect($body)->toContain('window.print()');
});
