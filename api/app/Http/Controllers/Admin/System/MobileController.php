<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\System;

use App\Support\System\MobileStatusTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin · System Health · Mobile App Health (sys-mobile)
 * ---------------------------------------------------------------------------
 * v4 — premium read-only view of the field-app fleet.
 *
 * What this controller does:
 *   · index()   — renders the Blade view + injects the coach manifest
 *   · summary() — top-level health pill, pending uploads, sparkline,
 *                 platform mix, app-version distribution, quiet devices
 *   · pending() — paginated list of devices with pending uploads
 *   · quiet()   — paginated list of devices that have not synced in >N days
 *
 * Discipline (per the System Health brief):
 *   · DYNAMIC DISCOVERY — mobile-write tables are listed in MOBILE_TABLES
 *     and probed for the existence of `sync_status`, `device_id`,
 *     `synced_at`, and `app_version` columns. A new mobile-write table
 *     can be added by extending this list — no other change required.
 *   · STATUS TRANSLATOR — every status enum, every platform value, every
 *     "last seen" relative phrase comes from MobileStatusTranslator.
 *     No raw enum values surface.
 *   · NO QUEUE / WORKER LANGUAGE — body copy says "messages waiting to
 *     upload from operators' phones".
 *   · READ-ONLY — this controller writes nothing to any mobile table.
 *     It only reads. The mobile API contract is preserved byte-for-byte.
 *   · AUDIT — every read records an audit row; quiet-device and
 *     pending-uploads tabs additionally record a PII reveal because
 *     they unmask the device identifier.
 *
 * Mobile-API impact: NONE. Routes live under /admin/system/mobile/*.
 */
final class MobileController extends BaseSystemController
{
    protected function viewKey(): string
    {
        return 'mobile';
    }

    /**
     * Mobile-write tables we observe. Extending this list is the way
     * to add a new mobile-write table to the dashboard — no other code
     * change is required because every column probe is dynamic.
     */
    private const MOBILE_TABLES = [
        'primary_screenings',
        'secondary_screenings',
        'aggregated_submissions',
        'alerts',
        'alert_followups',
    ];

    public function index(Request $request)
    {
        return view('admin.system.mobile.index', [
            'page_title'    => 'Mobile app health',
            'page_eyebrow'  => 'System Health',
            'page_subtitle' => 'Field-app fleet — uploads, app versions, platforms, devices that have gone quiet.',
            'coach'         => $this->coach(),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $now    = now();
            $days   = max(1, min(90, (int) $request->query('days', 30)));
            $since  = (clone $now)->subDays($days);

            $tableHealth = $this->perTableQueue($since);
            [$versions, $platforms, $devices] = $this->fleetSnapshot($since);

            $unsynced = array_sum(array_column($tableHealth, 'unsynced'));
            $failed   = array_sum(array_column($tableHealth, 'failed'));
            $synced   = array_sum(array_column($tableHealth, 'synced'));
            $totalRecorded = $unsynced + $failed + $synced;
            $healthPct = $totalRecorded > 0 ? round(100 * $synced / $totalRecorded, 1) : 100.0;

            // Health pill — written for the operator, not the engineer.
            if (count($devices) === 0) {
                $health = ['level' => 'unknown', 'plain' => 'No devices have reported yet in the chosen window.'];
            } elseif ($healthPct >= 99.0 && $unsynced + $failed < 50) {
                $health = ['level' => 'green', 'plain' => 'The field app is healthy. Almost everything captured has uploaded.'];
            } elseif ($healthPct >= 95.0) {
                $health = ['level' => 'amber', 'plain' => 'A small backlog of uploads is waiting. The phones will catch up.'];
            } else {
                $health = ['level' => 'red', 'plain' => 'A meaningful share of captured rows has not uploaded. Investigate the network or the field rollout.'];
            }

            $sparkline = $this->pendingSparkline($since, $days);

            // Top quiet devices — by idle days desc, top 12.
            $quietTop = $devices;
            usort($quietTop, fn ($a, $b) => $b['idle_days'] <=> $a['idle_days']);
            $quietTop = array_slice($quietTop, 0, 12);

            $this->auditView($request, ['view' => 'mobile.summary', 'days' => $days], ['row_count' => count($devices)]);

            return $this->ok([
                'server_time'   => $now->toIso8601String(),
                'window_days'   => $days,
                'health'        => $health,
                'totals'        => [
                    'mobile_tables'   => count(self::MOBILE_TABLES),
                    'present_tables'  => count(array_filter($tableHealth, fn ($t) => ! ($t['missing'] ?? false))),
                    'unsynced_total'  => $unsynced,
                    'failed_total'    => $failed,
                    'synced_total'    => $synced,
                    'distinct_devices'=> count($devices),
                    'health_pct'      => $healthPct,
                ],
                'tables'        => $tableHealth,
                'versions'      => $versions,
                'platforms'     => $platforms,
                'quiet_devices' => $quietTop,
                'sparkline'     => $sparkline,
                'translator_version' => MobileStatusTranslator::VERSION,
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'summary');
        }
    }

    /**
     * Per-table list of pending uploads. Returns one row per
     * (mobile-write-table, sync_status bucket) and a list of
     * top-pending devices.
     */
    public function pending(Request $request): JsonResponse
    {
        try {
            $rows = [];
            foreach (self::MOBILE_TABLES as $t) {
                if (! Schema::hasTable($t) || ! Schema::hasColumn($t, 'sync_status')) {
                    continue;
                }
                $byStatus = DB::table($t)
                    ->selectRaw('sync_status, COUNT(*) AS n')
                    ->groupBy('sync_status')->get();
                foreach ($byStatus as $r) {
                    $bucket = MobileStatusTranslator::syncBucket((string) $r->sync_status);
                    if ($bucket === 'uploaded') continue;
                    $rows[] = [
                        'table'      => $t,
                        'status'     => (string) $r->sync_status,
                        'plain'      => MobileStatusTranslator::syncStatusLabel((string) $r->sync_status),
                        'bucket'     => $bucket,
                        'count'      => (int) $r->n,
                    ];
                }
            }

            // Top pending devices across mobile tables (last 30 days).
            $topDevices = [];
            foreach (self::MOBILE_TABLES as $t) {
                if (! Schema::hasTable($t)) continue;
                if (! Schema::hasColumn($t, 'sync_status') || ! Schema::hasColumn($t, 'device_id')) continue;
                $rs = DB::table($t)
                    ->whereIn('sync_status', ['UNSYNCED', 'FAILED', 'PENDING'])
                    ->whereNotNull('device_id')
                    ->selectRaw('device_id, COUNT(*) AS pending_n, MAX(created_at) AS most_recent')
                    ->groupBy('device_id')
                    ->orderByDesc('pending_n')
                    ->limit(50)->get();
                foreach ($rs as $r) {
                    $key = (string) $r->device_id;
                    $topDevices[$key] ??= ['device_id' => $key, 'pending' => 0, 'most_recent' => null];
                    $topDevices[$key]['pending']     += (int) $r->pending_n;
                    $cur = $topDevices[$key]['most_recent'];
                    $cand = (string) $r->most_recent;
                    if (! $cur || strcmp($cand, (string) $cur) > 0) $topDevices[$key]['most_recent'] = $cand;
                }
            }
            $topArr = array_values($topDevices);
            usort($topArr, fn ($a, $b) => $b['pending'] <=> $a['pending']);
            $topArr = array_slice($topArr, 0, 30);

            $this->auditView($request, ['view' => 'mobile.pending'], ['row_count' => count($topArr)]);
            if (! empty($topArr)) {
                $this->auditPiiReveal($request, ['view' => 'mobile.pending'], count($topArr), ['device_id']);
            }

            return $this->ok([
                'server_time' => now()->toIso8601String(),
                'rows'        => $rows,
                'top_devices' => $topArr,
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'pending');
        }
    }

    /**
     * Devices that have not been seen in longer than N days. The
     * threshold (default 7) is configurable in the URL.
     */
    public function quiet(Request $request): JsonResponse
    {
        try {
            $thresholdDays = max(1, min(60, (int) $request->query('days', 7)));
            $cutoff        = now()->subDays($thresholdDays);

            $devices = [];
            foreach (self::MOBILE_TABLES as $t) {
                if (! Schema::hasTable($t)) continue;
                if (! Schema::hasColumn($t, 'device_id')) continue;
                $rs = DB::table($t)
                    ->whereNotNull('device_id')
                    ->selectRaw('device_id, MAX(platform) AS platform, MAX(app_version) AS app_version, MAX(created_at) AS last_seen')
                    ->groupBy('device_id')->get();
                foreach ($rs as $r) {
                    $key  = (string) $r->device_id;
                    $seen = (string) $r->last_seen;
                    $devices[$key] ??= [
                        'device_id'   => $key,
                        'platform'    => $r->platform ?: 'ANDROID',
                        'app_version' => $r->app_version ?: '—',
                        'last_seen'   => $seen,
                    ];
                    if (strcmp($seen, (string) $devices[$key]['last_seen']) > 0) {
                        $devices[$key]['last_seen'] = $seen;
                        if ($r->platform)    $devices[$key]['platform']    = (string) $r->platform;
                        if ($r->app_version) $devices[$key]['app_version'] = (string) $r->app_version;
                    }
                }
            }

            $now = now();
            $rows = [];
            foreach ($devices as $d) {
                if (Carbon::parse($d['last_seen'])->lt($cutoff)) {
                    $idle = (int) Carbon::parse($d['last_seen'])->diffInDays($now);
                    $rows[] = [
                        'device_id'    => $d['device_id'],
                        'platform'     => MobileStatusTranslator::platformLabel((string) $d['platform']),
                        'app_version'  => (string) $d['app_version'],
                        'last_seen'    => $d['last_seen'],
                        'idle_days'    => $idle,
                        'last_seen_plain' => MobileStatusTranslator::lastSyncPhrase($d['last_seen'], new \DateTimeImmutable($now->toIso8601String())),
                    ];
                }
            }
            usort($rows, fn ($a, $b) => $b['idle_days'] <=> $a['idle_days']);
            $rows = array_slice($rows, 0, 100);

            $this->auditView($request, ['view' => 'mobile.quiet', 'days' => $thresholdDays], ['row_count' => count($rows)]);
            if (! empty($rows)) {
                $this->auditPiiReveal($request, ['view' => 'mobile.quiet', 'days' => $thresholdDays], count($rows), ['device_id']);
            }

            return $this->ok([
                'server_time' => now()->toIso8601String(),
                'threshold_days' => $thresholdDays,
                'rows'        => $rows,
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'quiet');
        }
    }

    /* ──────────────────────── helpers ──────────────────────── */

    /**
     * @return array<int,array<string,mixed>>
     */
    private function perTableQueue(Carbon $since): array
    {
        $out = [];
        foreach (self::MOBILE_TABLES as $t) {
            if (! Schema::hasTable($t)) {
                $out[] = ['table' => $t, 'missing' => true,
                    'synced' => 0, 'unsynced' => 0, 'failed' => 0, 'total' => 0,
                    'health_pct' => 0.0, 'avg_sync_seconds' => null];
                continue;
            }
            $hasSync = Schema::hasColumn($t, 'sync_status');
            $synced   = $hasSync ? (int) DB::table($t)->where('sync_status', 'SYNCED')->count() : 0;
            $unsynced = $hasSync ? (int) DB::table($t)->where('sync_status', 'UNSYNCED')->count() : 0;
            $failed   = $hasSync ? (int) DB::table($t)->where('sync_status', 'FAILED')->count() : 0;
            $total    = (int) DB::table($t)->count();
            $avg = null;
            if (Schema::hasColumn($t, 'synced_at') && Schema::hasColumn($t, 'created_at')) {
                $avg = (int) DB::table($t)
                    ->whereNotNull('synced_at')
                    ->where('created_at', '>=', $since)
                    ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, created_at, synced_at)) AS avg_sec')
                    ->value('avg_sec');
            }
            $hp = ($synced + $unsynced + $failed) > 0
                ? round(100 * $synced / max($synced + $unsynced + $failed, 1), 1) : 100.0;
            $out[] = [
                'table'           => $t,
                'missing'         => false,
                'synced'          => $synced,
                'unsynced'        => $unsynced,
                'failed'          => $failed,
                'total'           => $total,
                'health_pct'      => $hp,
                'avg_sync_seconds'=> $avg,
            ];
        }
        return $out;
    }

    /**
     * @return array{0:array<int,array<string,mixed>>, 1:array<int,array<string,mixed>>, 2:array<int,array<string,mixed>>}
     */
    private function fleetSnapshot(Carbon $since): array
    {
        $versions  = [];
        $platforms = ['ANDROID' => 0, 'IOS' => 0, 'WEB' => 0];
        $devicesByPlatform = ['ANDROID' => [], 'IOS' => [], 'WEB' => []];
        $deviceMeta = [];

        foreach (self::MOBILE_TABLES as $t) {
            if (! Schema::hasTable($t)) continue;
            if (! Schema::hasColumn($t, 'device_id')) continue;
            $hasPlatform = Schema::hasColumn($t, 'platform');
            $hasVersion  = Schema::hasColumn($t, 'app_version');
            $rows = DB::table($t)
                ->where('created_at', '>=', $since)
                ->whereNotNull('device_id')
                ->selectRaw(
                    'device_id, '
                    . ($hasPlatform ? 'platform' : "'ANDROID' AS platform")
                    . ', '
                    . ($hasVersion ? 'app_version' : "'—' AS app_version")
                    . ', MAX(created_at) AS last_seen'
                )
                ->groupBy('device_id', $hasPlatform ? 'platform' : DB::raw("'ANDROID'"), $hasVersion ? 'app_version' : DB::raw("'—'"))
                ->get();

            foreach ($rows as $r) {
                $p = strtoupper((string) ($r->platform ?: 'ANDROID'));
                if (! isset($devicesByPlatform[$p])) {
                    $devicesByPlatform[$p] = [];
                    $platforms[$p] = 0;
                }
                $devicesByPlatform[$p][(string) $r->device_id] = (string) $r->last_seen;
                $deviceMeta[(string) $r->device_id] = [
                    'platform_raw' => $p,
                    'app_version'  => (string) ($r->app_version ?: '—'),
                    'last_seen'    => (string) $r->last_seen,
                ];
                $v = (string) ($r->app_version ?: '—');
                $versions[$v] = ($versions[$v] ?? 0) + 1;
            }
        }
        foreach ($platforms as $k => $_) {
            $platforms[$k] = count($devicesByPlatform[$k] ?? []);
        }

        $highest = '';
        foreach (array_keys($versions) as $v) {
            if ($v === '—') continue;
            if ($highest === '' || version_compare($v, $highest) > 0) $highest = $v;
        }

        $verArr = [];
        foreach ($versions as $v => $n) {
            $verArr[] = [
                'version'      => $v,
                'devices'      => $n,
                'plain_status' => MobileStatusTranslator::versionStatus($v, $highest),
            ];
        }
        usort($verArr, fn ($a, $b) => version_compare((string) $b['version'], (string) $a['version']));

        $platArr = [];
        foreach ($platforms as $k => $n) {
            $platArr[] = [
                'platform'      => $k,
                'plain_label'   => MobileStatusTranslator::platformLabel($k),
                'devices'       => $n,
            ];
        }

        $now = now();
        $deviceArr = [];
        foreach ($deviceMeta as $id => $m) {
            $idle = (int) Carbon::parse($m['last_seen'])->diffInDays($now);
            $deviceArr[] = [
                'device_id'      => $id,
                'platform'       => MobileStatusTranslator::platformLabel((string) $m['platform_raw']),
                'app_version'    => (string) $m['app_version'],
                'last_seen'      => (string) $m['last_seen'],
                'idle_days'      => $idle,
                'last_seen_plain'=> MobileStatusTranslator::lastSyncPhrase((string) $m['last_seen'], new \DateTimeImmutable($now->toIso8601String())),
            ];
        }

        return [$verArr, $platArr, $deviceArr];
    }

    /**
     * Pending-uploads sparkline — total count per day across all
     * mobile-write tables, derived from the ratio of UNSYNCED/FAILED
     * rows on each day.
     *
     * @return array<int,array<string,mixed>>
     */
    private function pendingSparkline(Carbon $since, int $days): array
    {
        $sparkline = [];
        $cursor = (clone $since)->startOfDay();
        for ($i = 0; $i < $days; $i++) {
            $sparkline[$cursor->format('Y-m-d')] = [
                'day' => $cursor->format('Y-m-d'),
                'pending' => 0,
                'failed'  => 0,
                'synced'  => 0,
            ];
            $cursor->addDay();
        }

        foreach (self::MOBILE_TABLES as $t) {
            if (! Schema::hasTable($t)) continue;
            if (! Schema::hasColumn($t, 'sync_status')) continue;
            $rs = DB::table($t)
                ->where('created_at', '>=', $since)
                ->selectRaw('DATE(created_at) AS d, sync_status, COUNT(*) AS n')
                ->groupBy('d', 'sync_status')->get();
            foreach ($rs as $r) {
                $k = (string) $r->d;
                if (! isset($sparkline[$k])) continue;
                if ($r->sync_status === 'UNSYNCED') $sparkline[$k]['pending'] += (int) $r->n;
                if ($r->sync_status === 'FAILED')   $sparkline[$k]['failed']  += (int) $r->n;
                if ($r->sync_status === 'SYNCED')   $sparkline[$k]['synced']  += (int) $r->n;
            }
        }
        return array_values($sparkline);
    }
}
