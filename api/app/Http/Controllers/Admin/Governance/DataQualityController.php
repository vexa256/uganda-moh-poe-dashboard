<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Governance;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin · Governance · Data Quality (gov-dq)
 * ---------------------------------------------------------------------------
 * Cross-table DQ scorecard over every mobile-write table:
 *
 *   · Void rate            — rows with deleted_at NOT NULL
 *   · Duplicate client_uuid — uniqueness violations (should be 0)
 *   · Late syncs           — synced_at - created_at > 1h
 *   · Sync failure backlog — sync_status = FAILED
 *   · Idempotency hits     — sync_attempt_count > 0 OR existing client_uuid matched
 *   · Record age profile   — creation date distribution
 *
 * Mobile contract: NONE. Read-only against already-persisted rows;
 * writes NEVER happen here.
 *
 * Gate: NATIONAL_ADMIN only (cross-cutting operational read surface).
 */
final class DataQualityController extends BaseGovernanceController
{
    protected function viewKey(): string
    {
        return 'dq';
    }

    /** Mobile-write tables that carry the DQ contract columns. */
    private const TABLES = [
        'primary_screenings'     => ['client_uuid' => true,  'sync' => true, 'void' => true],
        'secondary_screenings'   => ['client_uuid' => true,  'sync' => true, 'void' => true],
        'aggregated_submissions' => ['client_uuid' => true,  'sync' => true, 'void' => true],
        'alerts'                 => ['client_uuid' => true,  'sync' => true, 'void' => true],
        'alert_followups'        => ['client_uuid' => true,  'sync' => true, 'void' => true],
        'notifications'          => ['client_uuid' => false, 'sync' => false,'void' => false],
    ];

    private const LATE_SYNC_HOURS = 1;

    public function index(Request $request)
    {
        return view('admin.governance.data-quality.index', [
            'page_title'    => 'Data Quality',
            'page_eyebrow'  => 'Governance',
            'page_subtitle' => 'Void rates · duplicate client_uuid · late syncs · sync failure backlog · idempotency hits.',
            'coach'         => $this->coach(),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $now = now();
            $windowDays = max(1, min(90, (int) $request->query('days', 30)));
            $since = (clone $now)->subDays($windowDays);

            $scorecard = [];
            foreach (self::TABLES as $table => $cfg) {
                if (! Schema::hasTable($table)) {
                    $scorecard[] = [
                        'table' => $table, 'missing' => true,
                        'total' => 0, 'voided' => 0, 'void_pct' => 0.0,
                        'duplicate_uuids' => 0, 'late_syncs' => 0, 'late_pct' => 0.0,
                        'sync_failed' => 0, 'sync_unsynced' => 0, 'idempotency_hits' => 0,
                        'p95_sync_hours' => null, 'last_create_at' => null,
                    ];
                    continue;
                }

                $total = (int) DB::table($table)->count();
                $voided = $cfg['void'] && Schema::hasColumn($table, 'deleted_at')
                    ? (int) DB::table($table)->whereNotNull('deleted_at')->count() : 0;
                $voidPct = $total > 0 ? round(100 * $voided / $total, 2) : 0.0;

                $dupeUuids = 0;
                if ($cfg['client_uuid'] && Schema::hasColumn($table, 'client_uuid')) {
                    $dupeUuids = (int) DB::table($table)
                        ->selectRaw('client_uuid')
                        ->whereNotNull('client_uuid')
                        ->groupBy('client_uuid')
                        ->havingRaw('COUNT(*) > 1')
                        ->get()->count();
                }

                $late = 0; $latePct = 0.0; $p95 = null;
                if ($cfg['sync'] && Schema::hasColumn($table, 'synced_at') && Schema::hasColumn($table, 'created_at')) {
                    $syncedBase = DB::table($table)
                        ->whereNotNull('synced_at')
                        ->where('created_at', '>=', $since);
                    $late = (int) (clone $syncedBase)
                        ->whereRaw('TIMESTAMPDIFF(MINUTE, created_at, synced_at) > ?', [self::LATE_SYNC_HOURS * 60])
                        ->count();
                    $latePct = $total > 0 ? round(100 * $late / max((clone $syncedBase)->count(), 1), 2) : 0.0;
                }

                $failed = 0; $unsynced = 0;
                if ($cfg['sync'] && Schema::hasColumn($table, 'sync_status')) {
                    $failed   = (int) DB::table($table)->where('sync_status', 'FAILED')->count();
                    $unsynced = (int) DB::table($table)->where('sync_status', 'UNSYNCED')->count();
                }

                $idempotency = 0;
                if ($cfg['sync'] && Schema::hasColumn($table, 'sync_attempt_count')) {
                    $idempotency = (int) DB::table($table)
                        ->where('sync_attempt_count', '>', 0)->count();
                }

                $lastCreate = Schema::hasColumn($table, 'created_at')
                    ? DB::table($table)->max('created_at') : null;

                $scorecard[] = [
                    'table'            => $table,
                    'missing'          => false,
                    'total'            => $total,
                    'voided'           => $voided,
                    'void_pct'         => $voidPct,
                    'duplicate_uuids'  => $dupeUuids,
                    'late_syncs'       => $late,
                    'late_pct'         => $latePct,
                    'sync_failed'      => $failed,
                    'sync_unsynced'    => $unsynced,
                    'idempotency_hits' => $idempotency,
                    'p95_sync_hours'   => $p95,
                    'last_create_at'   => $lastCreate,
                ];
            }

            // ── Daily create trend across all mobile-write tables ──────
            $trend = [];
            for ($i = $windowDays - 1; $i >= 0; $i--) {
                $d = (clone $now)->subDays($i)->startOfDay();
                $trend[$d->format('Y-m-d')] = [
                    'day' => $d->format('Y-m-d'),
                    'created' => 0,
                    'voided'  => 0,
                    'failed'  => 0,
                ];
            }
            foreach (self::TABLES as $table => $cfg) {
                if (! Schema::hasTable($table)) continue;
                if (! Schema::hasColumn($table, 'created_at')) continue;
                $rows = DB::table($table)
                    ->selectRaw('DATE(created_at) AS d, COUNT(*) AS n')
                    ->where('created_at', '>=', $since)
                    ->groupBy('d')->get();
                foreach ($rows as $r) {
                    $k = (string) $r->d;
                    if (isset($trend[$k])) $trend[$k]['created'] += (int) $r->n;
                }
                if ($cfg['void'] && Schema::hasColumn($table, 'deleted_at')) {
                    $vRows = DB::table($table)
                        ->selectRaw('DATE(deleted_at) AS d, COUNT(*) AS n')
                        ->whereNotNull('deleted_at')
                        ->where('deleted_at', '>=', $since)
                        ->groupBy('d')->get();
                    foreach ($vRows as $r) {
                        $k = (string) $r->d;
                        if (isset($trend[$k])) $trend[$k]['voided'] += (int) $r->n;
                    }
                }
            }
            $trendArr = array_values($trend);

            // ── Global rollups ─────────────────────────────────────────
            $totalRows    = array_sum(array_column($scorecard, 'total'));
            $totalVoided  = array_sum(array_column($scorecard, 'voided'));
            $totalLate    = array_sum(array_column($scorecard, 'late_syncs'));
            $totalFailed  = array_sum(array_column($scorecard, 'sync_failed'));
            $totalUnsync  = array_sum(array_column($scorecard, 'sync_unsynced'));
            $totalDupes   = array_sum(array_column($scorecard, 'duplicate_uuids'));
            $totalIdem    = array_sum(array_column($scorecard, 'idempotency_hits'));
            $healthScore  = $this->healthScore($scorecard);

            // Audit: aggregate-only payload, no PII reveal.
            $this->auditView($request, ['days' => $windowDays], ['row_count' => (int) $totalRows]);

            return response()->json(['ok' => true, 'data' => [
                'server_time'  => $now->toIso8601String(),
                'window_days'  => $windowDays,
                'scorecard'    => $scorecard,
                'trend'        => $trendArr,
                'totals' => [
                    'rows'             => $totalRows,
                    'voided'           => $totalVoided,
                    'void_pct'         => $totalRows > 0 ? round(100 * $totalVoided / $totalRows, 2) : 0.0,
                    'late_syncs'       => $totalLate,
                    'sync_failed'      => $totalFailed,
                    'sync_unsynced'    => $totalUnsync,
                    'duplicate_uuids'  => $totalDupes,
                    'idempotency_hits' => $totalIdem,
                    'health_score'     => $healthScore,
                ],
            ]]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'summary');
        }
    }

    public function stragglers(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'kind'  => ['nullable', 'string', 'in:FAILED,UNSYNCED,LATE'],
                'table' => ['nullable', 'string', 'in:' . implode(',', array_keys(self::TABLES))],
            ]);
            $kind  = $validated['kind']  ?? 'FAILED';
            $table = $validated['table'] ?? 'primary_screenings';

            if (! Schema::hasTable($table)) {
                return response()->json(['ok' => true, 'data' => ['rows' => [], 'table' => $table, 'kind' => $kind]]);
            }

            $q = DB::table($table);
            $pickCols = array_values(array_filter(
                ['id', 'client_uuid', 'sync_status', 'sync_attempt_count', 'synced_at',
                 'last_sync_error', 'created_at', 'updated_at',
                 'poe_code', 'district_code', 'country_code', 'deleted_at'],
                fn ($c) => Schema::hasColumn($table, $c),
            ));

            if ($kind === 'FAILED')   { $q->where('sync_status', 'FAILED'); }
            if ($kind === 'UNSYNCED') { $q->where('sync_status', 'UNSYNCED'); }
            if ($kind === 'LATE')     {
                $q->whereNotNull('synced_at')
                  ->whereRaw('TIMESTAMPDIFF(MINUTE, created_at, synced_at) > ?', [self::LATE_SYNC_HOURS * 60]);
            }

            $rows = $q->orderByDesc('id')->limit(100)->get($pickCols)->map(fn ($r) => (array) $r)->all();

            // Audit: stragglers can include client_uuid + last_sync_error.
            // No named PII columns are picked but the rows do hint at
            // operational identifiers — record the view explicitly.
            $this->auditView($request, $validated, ['row_count' => count($rows)]);

            return response()->json(['ok' => true, 'data' => [
                'rows'  => $rows,
                'table' => $table,
                'kind'  => $kind,
            ]]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'validation', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return $this->serverError($e, 'stragglers');
        }
    }

    /** Higher is better · 0..100 overall DQ health. */
    private function healthScore(array $scorecard): int
    {
        $score = 100;
        foreach ($scorecard as $row) {
            if ($row['missing']) continue;
            if ($row['duplicate_uuids'] > 0) { $score -= min(20, $row['duplicate_uuids']); }
            if ($row['void_pct'] > 5)        { $score -= 5; }
            if ($row['void_pct'] > 15)       { $score -= 10; }
            if ($row['late_pct'] > 10)       { $score -= 5; }
            if ($row['sync_failed'] > 0)     { $score -= min(10, (int) ceil($row['sync_failed'] / 5)); }
        }
        return max(0, min(100, $score));
    }
}
