<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * GeographyController
 * ─────────────────────────────────────────────────────────────────────────
 * Read-only roll-up of the country → district → POE hierarchy used by
 * every scoped endpoint. Sources are the existing operational tables
 * (primary_screenings, secondary_screenings, alerts, user_assignments)
 * plus poe_notification_contacts for POE human-facing names.
 *
 *   GET /admin/geography/countries
 *   GET /admin/geography/districts?country_code=UG
 *   GET /admin/geography/poes?country_code=UG&district_code=XXX
 *   GET /admin/geography/tree?country_code=UG     — full nested tree (country > district > POE)
 */
final class GeographyController extends Controller
{
    public function countries(): JsonResponse
    {
        try {
            // Aggregate every table that stores a country_code so we don't miss one.
            $codes = collect()
                ->merge(DB::table('users')->distinct()->pluck('country_code'))
                ->merge(DB::table('primary_screenings')->distinct()->pluck('country_code'))
                ->merge(DB::table('secondary_screenings')->distinct()->pluck('country_code'))
                ->merge(DB::table('alerts')->distinct()->pluck('country_code'))
                ->merge(DB::table('poe_notification_contacts')->distinct()->pluck('country_code'))
                ->filter()->unique()->sort()->values();
            $rows = $codes->map(fn ($c) => [
                'country_code' => $c,
                'country_name' => self::countryName($c),
                'user_count'   => (int) DB::table('users')->where('country_code', $c)->count(),
                'alert_count'  => (int) DB::table('alerts')->where('country_code', $c)->whereNull('deleted_at')->count(),
                'poe_count'    => (int) DB::table('poe_notification_contacts')->where('country_code', $c)
                    ->whereNotNull('poe_code')->distinct()->count('poe_code'),
            ]);
            return response()->json(['ok' => true, 'data' => ['countries' => $rows]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function districts(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            $rows = DB::table('primary_screenings')->where('country_code', $cc)
                ->whereNotNull('district_code')
                ->selectRaw('district_code, COUNT(*) AS screenings_total, MAX(captured_at) AS last_screening')
                ->groupBy('district_code')
                ->orderBy('district_code')->get();
            return response()->json(['ok' => true, 'data' => ['districts' => $rows]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function poes(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            $q = DB::table('poe_notification_contacts')->where('country_code', $cc)
                ->whereNotNull('poe_code')
                ->select('poe_code', 'district_code', 'country_code',
                    DB::raw('COUNT(*) AS contacts_count'))
                ->groupBy('poe_code', 'district_code', 'country_code');
            if ($d = $r->query('district_code')) $q->where('district_code', $d);
            $rows = $q->orderBy('district_code')->orderBy('poe_code')->get();
            return response()->json(['ok' => true, 'data' => ['poes' => $rows]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function tree(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            $tree = DB::table('poe_notification_contacts')->where('country_code', $cc)
                ->select('district_code', 'poe_code')->distinct()->get();
            $byDistrict = [];
            foreach ($tree as $t) {
                $k = (string) $t->district_code;
                if (! isset($byDistrict[$k])) $byDistrict[$k] = ['district_code' => $k, 'poes' => []];
                if ($t->poe_code) $byDistrict[$k]['poes'][] = $t->poe_code;
            }
            $out = array_values($byDistrict);
            foreach ($out as &$d) { $d['poes'] = array_values(array_unique($d['poes'])); sort($d['poes']); }
            return response()->json(['ok' => true, 'data' => [
                'country_code' => $cc,
                'country_name' => self::countryName($cc),
                'districts'    => $out,
            ]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // PRESERVED: multi-country name lookup (Category B reference, not system scope).
    private static function countryName(string $code): string
    {
        return match (strtoupper($code)) {
            'UG' => 'Uganda', 'RW' => 'Rwanda', 'ZM' => 'Zambia',
            'MW' => 'Malawi', 'ST', 'STP' => 'São Tomé and Príncipe',
            default => $code,
        };
    }
}
