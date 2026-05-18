<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Intelligence;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin · Intelligence · Heatmap & PoEs (intel-geo)
 * ---------------------------------------------------------------------------
 * Case density · screening throughput · POE-to-POE benchmarking.
 *
 * Aggregates across the last N days (default 30):
 *   · primary_screenings count per PoE (throughput)
 *   · secondary_screenings count per PoE (case density)
 *   · alerts (OPEN + ACKNOWLEDGED) per PoE
 *   · province rollup + border-transport-mode mix
 *
 * Mobile contract: NONE. Read-only analytics.
 * Gate: NATIONAL_ADMIN.
 */
final class GeoController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.intelligence.geo.index', [
            'page_title'    => 'Heatmap & PoEs',
            'page_eyebrow'  => 'Intelligence',
            'page_subtitle' => 'Case density · screening throughput · POE-to-POE benchmarking.',
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $now  = now();
            $days = max(1, min(180, (int) $request->query('days', 30)));
            $since = (clone $now)->subDays($days);

            if (! Schema::hasTable('ref_poes')) {
                return response()->json(['ok' => true, 'data' => $this->emptyPayload($now, $days)]);
            }

            // PoE registry (active only).
            // ref_poes uses `admin_level_1` for the province/region label (live schema).
            $poes = DB::table('ref_poes')
                ->whereNull('deleted_at')
                ->orderBy('admin_level_1')->orderBy('poe_name')
                ->get(['id','poe_name','poe_code','admin_level_1','district','poe_type','transport_mode','border_country'])
                ->map(fn ($r) => (array) $r)->all();

            $counts = [];
            foreach ($poes as $p) {
                $counts[$p['poe_code']] = [
                    'poe_code'    => $p['poe_code'],
                    'poe_name'    => $p['poe_name'],
                    'province'    => $p['admin_level_1'],
                    'district'    => $p['district'],
                    'poe_type'    => $p['poe_type'],
                    'transport'   => $p['transport_mode'],
                    'border'      => $p['border_country'],
                    'primary'     => 0, 'secondary' => 0, 'alerts' => 0, 'open_alerts' => 0,
                ];
            }

            if (Schema::hasTable('primary_screenings')) {
                $rs = DB::table('primary_screenings')
                    ->whereNull('deleted_at')->where('created_at', '>=', $since)
                    ->selectRaw('poe_code, COUNT(*) AS n')->groupBy('poe_code')->get();
                foreach ($rs as $r) { if (isset($counts[$r->poe_code])) $counts[$r->poe_code]['primary'] = (int) $r->n; }
            }
            if (Schema::hasTable('secondary_screenings')) {
                $rs = DB::table('secondary_screenings')
                    ->whereNull('deleted_at')->where('created_at', '>=', $since)
                    ->selectRaw('poe_code, COUNT(*) AS n')->groupBy('poe_code')->get();
                foreach ($rs as $r) { if (isset($counts[$r->poe_code])) $counts[$r->poe_code]['secondary'] = (int) $r->n; }
            }
            if (Schema::hasTable('alerts')) {
                $rs = DB::table('alerts')
                    ->whereNull('deleted_at')->where('created_at', '>=', $since)
                    ->selectRaw('poe_code, COUNT(*) AS n, '
                        . "SUM(CASE WHEN status IN ('OPEN','ACKNOWLEDGED') THEN 1 ELSE 0 END) AS open_n")
                    ->groupBy('poe_code')->get();
                foreach ($rs as $r) {
                    if (! isset($counts[$r->poe_code])) continue;
                    $counts[$r->poe_code]['alerts']      = (int) $r->n;
                    $counts[$r->poe_code]['open_alerts'] = (int) $r->open_n;
                }
            }

            // Conversion (secondary / primary)
            foreach ($counts as &$c) {
                $c['conversion_pct'] = $c['primary'] > 0
                    ? round(100 * $c['secondary'] / $c['primary'], 1) : 0.0;
            }
            unset($c);
            $countsArr = array_values($counts);
            usort($countsArr, fn ($a, $b) => $b['primary'] <=> $a['primary']);

            // Province rollup
            $byProvince = [];
            foreach ($countsArr as $c) {
                $p = $c['province'] ?: '—';
                $byProvince[$p] ??= ['province' => $p, 'poes' => 0, 'primary' => 0, 'secondary' => 0, 'alerts' => 0, 'open_alerts' => 0];
                $byProvince[$p]['poes']++;
                $byProvince[$p]['primary']    += $c['primary'];
                $byProvince[$p]['secondary']  += $c['secondary'];
                $byProvince[$p]['alerts']     += $c['alerts'];
                $byProvince[$p]['open_alerts']+= $c['open_alerts'];
            }
            $byProvinceArr = array_values($byProvince);
            usort($byProvinceArr, fn ($a, $b) => $b['primary'] <=> $a['primary']);

            // Transport mode mix
            $byTransport = [];
            foreach ($countsArr as $c) {
                $t = $c['transport'] ?: '—';
                $byTransport[$t] ??= ['transport' => $t, 'n' => 0, 'primary' => 0, 'secondary' => 0];
                $byTransport[$t]['n']++;
                $byTransport[$t]['primary']   += $c['primary'];
                $byTransport[$t]['secondary'] += $c['secondary'];
            }
            $byTransportArr = array_values($byTransport);
            usort($byTransportArr, fn ($a, $b) => $b['primary'] <=> $a['primary']);

            // Border country mix
            $byBorder = [];
            foreach ($countsArr as $c) {
                $b = $c['border'] ?: '—';
                $byBorder[$b] ??= ['border' => $b, 'n' => 0, 'primary' => 0];
                $byBorder[$b]['n']++;
                $byBorder[$b]['primary'] += $c['primary'];
            }
            $byBorderArr = array_values($byBorder);
            usort($byBorderArr, fn ($a, $b) => $b['primary'] <=> $a['primary']);

            // Silent PoEs = zero primary + zero secondary in window
            $silent = array_values(array_filter($countsArr,
                fn ($c) => $c['primary'] === 0 && $c['secondary'] === 0));

            // Throughput trend · 30 days across all PoEs
            $trend = [];
            if (Schema::hasTable('primary_screenings')) {
                $rs = DB::table('primary_screenings')
                    ->whereNull('deleted_at')->where('created_at', '>=', $since)
                    ->selectRaw('DATE(created_at) AS d, COUNT(*) AS n')
                    ->groupBy('d')->get();
                $map = [];
                for ($i = $days - 1; $i >= 0; $i--) {
                    $k = (clone $now)->subDays($i)->format('Y-m-d');
                    $map[$k] = ['day' => $k, 'primary' => 0, 'secondary' => 0];
                }
                foreach ($rs as $r) { if (isset($map[(string) $r->d])) $map[(string) $r->d]['primary'] = (int) $r->n; }
                if (Schema::hasTable('secondary_screenings')) {
                    $rs2 = DB::table('secondary_screenings')
                        ->whereNull('deleted_at')->where('created_at', '>=', $since)
                        ->selectRaw('DATE(created_at) AS d, COUNT(*) AS n')
                        ->groupBy('d')->get();
                    foreach ($rs2 as $r) { if (isset($map[(string) $r->d])) $map[(string) $r->d]['secondary'] = (int) $r->n; }
                }
                $trend = array_values($map);
            }

            $totals = [
                'poes'       => count($countsArr),
                'silent'     => count($silent),
                'primary'    => array_sum(array_column($countsArr, 'primary')),
                'secondary'  => array_sum(array_column($countsArr, 'secondary')),
                'alerts'     => array_sum(array_column($countsArr, 'alerts')),
                'open_alerts'=> array_sum(array_column($countsArr, 'open_alerts')),
            ];

            return response()->json(['ok' => true, 'data' => [
                'server_time' => $now->toIso8601String(),
                'window_days' => $days,
                'totals'      => $totals,
                'poes'        => array_slice($countsArr, 0, 40),
                'silent'      => array_slice($silent, 0, 40),
                'by_province' => $byProvinceArr,
                'by_transport'=> $byTransportArr,
                'by_border'   => $byBorderArr,
                'trend'       => $trend,
            ]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function emptyPayload($now, int $days): array
    {
        return [
            'server_time' => $now->toIso8601String(),
            'window_days' => $days,
            'totals'      => ['poes'=>0,'silent'=>0,'primary'=>0,'secondary'=>0,'alerts'=>0,'open_alerts'=>0],
            'poes' => [], 'silent' => [], 'by_province' => [], 'by_transport' => [], 'by_border' => [], 'trend' => [],
        ];
    }
}
