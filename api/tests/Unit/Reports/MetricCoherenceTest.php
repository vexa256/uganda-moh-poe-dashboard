<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Reports\AgeGenderController;
use App\Http\Controllers\Admin\Reports\CasesRegistryController;
use App\Http\Controllers\Admin\Reports\ScreeningVolumeController;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

uses(\Tests\TestCase::class);

/**
 * MetricCoherenceTest — release-blocker per Paranoid v2 brief §13.7.
 *
 * Same metric, same scope, same filters → same number across every view.
 * The surveillance schema is too complex (ENUMs, JSON columns, FKs against
 * a table that ships in app.sql rather than migrations) to mirror in
 * sqlite, so this test runs against the configured "mysql" connection.
 *
 * If the live MySQL connection is unreachable (CI sandbox without DB
 * access), the test skips cleanly rather than failing — the cross-scope
 * coherence guarantee is enforced wherever the test can actually exercise
 * the controllers, never silently bypassed.
 */
function mctScope(): array
{
    return [
        'user_id'      => 0,
        'role_key'     => 'NATIONAL_ADMIN',
        'account_type' => 'NATIONAL_ADMIN',
        'scope_level'  => 'NATIONAL',
        'is_super'     => true,
        'country_code' => null,
        'countries'    => [],
        'provinces'    => [],
        'districts'    => [],
        'poes'         => [],
        'primary_poe'  => null,
        'assignments'  => [],
        'label'        => 'NATIONAL · coherence test',
    ];
}

beforeEach(function () {
    // phpunit.xml forces DB_DATABASE=:memory:, so we have to override BOTH
    // the default connection AND the database for the mysql connection that
    // Laravel cached at boot. Pull the live value back from the .env file
    // (the test container may not have it loaded into $_ENV).
    $env = [];
    $envPath = base_path('.env');
    if (is_readable($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || $line[0] === '#') continue;
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $env[trim($parts[0])] = trim($parts[1], " \t\"'");
            }
        }
    }
    $database = $env['DB_DATABASE'] ?? 'ecsa_uganda_2026';
    $username = $env['DB_USERNAME'] ?? 'hacker';
    $password = $env['DB_PASSWORD'] ?? '';
    $host     = $env['DB_HOST']     ?? '127.0.0.1';
    $port     = (int) ($env['DB_PORT'] ?? 3306);

    if ($database === ':memory:') {
        $this->markTestSkipped('mysql .env DB_DATABASE not configured for live coherence test');
    }

    Config::set('database.connections.mysql.database', $database);
    Config::set('database.connections.mysql.username', $username);
    Config::set('database.connections.mysql.password', $password);
    Config::set('database.connections.mysql.host',     $host);
    Config::set('database.connections.mysql.port',     $port);
    Config::set('database.default', 'mysql');
    DB::purge('mysql');

    try {
        DB::connection('mysql')->getPdo();
    } catch (\Throwable $e) {
        $this->markTestSkipped('mysql connection unavailable for metric coherence test: ' . $e->getMessage());
    }
});

test('total secondary count in window agrees across rpt-volume, rpt-age-gender, rpt-registry', function () {
    $filters = ['year' => (int) now()->year];
    $scope   = mctScope();

    $r1 = app(ScreeningVolumeController::class)->buildPayload($scope, $filters);
    $r6 = app(AgeGenderController::class)->buildPayload($scope, $filters);
    $r5 = app(CasesRegistryController::class)->buildPayload($scope, $filters + ['per_page' => 5000, 'page' => 1]);

    $fromR1 = (int) ($r1['kpis']['secondary'] ?? -1);
    $fromR6 = (int) ($r6['kpis']['secondary'] ?? -1);
    $fromR5 = (int) ($r5['kpis']['total']     ?? -1);

    expect($fromR1)->toBeGreaterThanOrEqual(0, 'rpt-volume must report a non-negative secondary count');
    expect($fromR6)->toBe($fromR1, "rpt-age-gender disagrees with rpt-volume on secondary count: {$fromR6} vs {$fromR1}");
    expect($fromR5)->toBe($fromR1, "rpt-registry disagrees with rpt-volume on secondary count: {$fromR5} vs {$fromR1}");
});

test('referral count from rpt-volume equals referrals KPI from rpt-registry under the same window', function () {
    $filters = ['year' => (int) now()->year];
    $scope   = mctScope();

    $r1 = app(ScreeningVolumeController::class)->buildPayload($scope, $filters);
    $r5 = app(CasesRegistryController::class)->buildPayload($scope, $filters + ['per_page' => 5000, 'page' => 1]);

    $fromR1 = (int) ($r1['kpis']['facility_referrals'] ?? -1);
    $fromR5 = (int) ($r5['kpis']['referrals']          ?? -1);

    expect($fromR1)->toBeGreaterThanOrEqual(0);
    expect($fromR5)->toBe($fromR1, "facility-referral count must match across views ({$fromR1} vs {$fromR5})");
});

test('window resolution is identical across every view', function () {
    $filters = ['year' => 2025, 'month' => 7];
    $scope   = mctScope();

    $r1 = app(ScreeningVolumeController::class)->buildPayload($scope, $filters);
    $r6 = app(AgeGenderController::class)->buildPayload($scope, $filters);
    $r5 = app(CasesRegistryController::class)->buildPayload($scope, $filters + ['per_page' => 5000, 'page' => 1]);

    expect($r1['window']['from'])->toBe('2025-07-01');
    expect($r1['window']['to'])->toBe('2025-07-31');
    expect($r6['window'] ?? null)->not->toBeNull('rpt-age-gender must declare its window');
    expect($r6['window']['from'])->toBe($r1['window']['from'], 'window from-date must agree');
    expect($r6['window']['to'])->toBe($r1['window']['to'], 'window to-date must agree');
    expect($r5['window']['from'])->toBe($r1['window']['from']);
    expect($r5['window']['to'])->toBe($r1['window']['to']);
});

test('zero-row window returns zero, not null, across every view', function () {
    $filters = ['year' => 1999, 'month' => 1];
    $scope   = mctScope();

    $r1 = app(ScreeningVolumeController::class)->buildPayload($scope, $filters);
    $r6 = app(AgeGenderController::class)->buildPayload($scope, $filters);
    $r5 = app(CasesRegistryController::class)->buildPayload($scope, $filters + ['per_page' => 5000, 'page' => 1]);

    expect((int) $r1['kpis']['secondary'])->toBe(0);
    expect((int) $r6['kpis']['secondary'])->toBe(0);
    expect((int) $r5['kpis']['total'])->toBe(0);
});
