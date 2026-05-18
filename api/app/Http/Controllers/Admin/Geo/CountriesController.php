<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Geo;

use App\Http\Controllers\Controller;
use App\Support\Scope\ScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin · Geo · Countries.
 *
 * Single-tenant per dashboard.txt §D.5 — Zambia is the only row. The view
 * is mostly read-only (ISO codes, display name, geo dataset metadata).
 * Edit allowed for ISO codes / display order / metadata; delete blocked
 * if active PoEs exist.
 */
final class CountriesController extends Controller
{
    /** Default tenant country (full name; matches ref_poes.country_code). */
    private static function defaultCountry(): string
    {
        return (string) (config('country.legacy_code') ?: 'Uganda');
    }

    public function index(Request $request)
    {
        return view('admin.geo.countries.index', [
            'page_title'    => 'Countries',
            'page_eyebrow'  => 'Geography · Countries',
            'page_subtitle' => 'Country scope for this build. Single-tenant per install.',
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($request);
            $q     = DB::table('ref_countries')->whereNull('deleted_at');
            $q     = ScopeFilter::applyToCountries($q, $scope);
            $rows  = $q->orderBy('display_order')->orderBy('id')->get();
            $countries = $rows->pluck('country_code')->all();
            $poeCounts      = DB::table('ref_poes')->whereIn('country_code', $countries)->whereNull('deleted_at')->groupBy('country_code')->selectRaw('country_code, COUNT(*) as c')->pluck('c', 'country_code');
            $provinceCounts = DB::table('ref_provinces')->whereIn('country_code', $countries)->whereNull('deleted_at')->groupBy('country_code')->selectRaw('country_code, COUNT(*) as c')->pluck('c', 'country_code');
            $districtCounts = DB::table('ref_districts')->whereIn('country_code', $countries)->whereNull('deleted_at')->groupBy('country_code')->selectRaw('country_code, COUNT(*) as c')->pluck('c', 'country_code');
            $hospitalCounts = DB::table('ref_hospitals')->whereIn('country_code', $countries)->whereNull('deleted_at')->groupBy('country_code')->selectRaw('country_code, COUNT(*) as c')->pluck('c', 'country_code');
            $versions       = DB::table('ref_geo_version')->whereIn('country_code', $countries)->pluck('version', 'country_code');

            return $this->ok([
                'rows' => $rows->map(fn ($r) => [
                    'id'             => (int) $r->id,
                    'country_code'   => (string) $r->country_code,
                    'iso_alpha2'     => $r->iso_alpha2,
                    'iso_alpha3'     => $r->iso_alpha3,
                    'name'           => (string) $r->name,
                    'is_active'      => (bool) $r->is_active,
                    'display_order'  => (int) $r->display_order,
                    'metadata_json'  => $r->metadata_json ? json_decode((string) $r->metadata_json, true) : null,
                    'created_at'     => $r->created_at,
                    'updated_at'     => $r->updated_at,
                    'province_count' => (int) ($provinceCounts[$r->country_code] ?? 0),
                    'district_count' => (int) ($districtCounts[$r->country_code] ?? 0),
                    'poe_count'      => (int) ($poeCounts[$r->country_code]      ?? 0),
                    'hospital_count' => (int) ($hospitalCounts[$r->country_code] ?? 0),
                    'bundle_version' => (int) ($versions[$r->country_code]       ?? 0),
                ])->all(),
                'total' => $rows->count(),
            ], 'Countries.');
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    public function show(Request $request, string $code): JsonResponse
    {
        try {
            $row = DB::table('ref_countries')->where('country_code', $code)->first();
            if (!$row) { return $this->err(404, 'Country not found.'); }
            return $this->ok($this->cast($row), 'Country retrieved.');
        } catch (Throwable $e) { return $this->serverError($e, 'show'); }
    }

    public function update(Request $request, string $code): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_countries')->where('country_code', $code)->whereNull('deleted_at')->first();
            if (!$row) { return $this->err(404, 'Country not found.'); }
            $data = $request->all();
            $patch = ['updated_at' => Carbon::now(), 'updated_by_user_id' => $admin];
            foreach (['name', 'iso_alpha2', 'iso_alpha3', 'display_order'] as $f) {
                if (array_key_exists($f, $data)) { $patch[$f] = $data[$f]; }
            }
            if (array_key_exists('is_active', $data)) { $patch['is_active'] = (int) (bool) $data['is_active']; }
            if (array_key_exists('metadata_json', $data)) {
                $patch['metadata_json'] = $data['metadata_json'] === null ? null : json_encode($data['metadata_json']);
            }
            DB::table('ref_countries')->where('id', $row->id)->update($patch);
            $this->bumpVersion($code);
            $fresh = DB::table('ref_countries')->where('id', $row->id)->first();
            return $this->ok($this->cast($fresh), 'Country updated.', ['version' => $this->currentVersion($code)]);
        } catch (Throwable $e) { return $this->serverError($e, 'update'); }
    }

    private function cast(object $r): array
    {
        return [
            'id'             => (int) $r->id,
            'country_code'   => (string) $r->country_code,
            'iso_alpha2'     => $r->iso_alpha2,
            'iso_alpha3'     => $r->iso_alpha3,
            'name'           => (string) $r->name,
            'is_active'      => (bool) $r->is_active,
            'display_order'  => (int) $r->display_order,
            'metadata_json'  => $r->metadata_json ? json_decode((string) $r->metadata_json, true) : null,
            'created_at'     => $r->created_at,
            'updated_at'     => $r->updated_at,
        ];
    }
    private function currentVersion(string $country): int
    { return (int) (DB::table('ref_geo_version')->where('country_code', $country)->value('version') ?? 0); }
    private function bumpVersion(string $country): void
    { DB::table('ref_geo_version')->updateOrInsert(['country_code' => $country], [
        'version' => DB::raw('COALESCE(version,0) + 1'), 'etag' => null,
        'last_built_at' => Carbon::now(), 'updated_at' => Carbon::now(), 'created_at' => Carbon::now(),
    ]); }

    /** Auth + NATIONAL_ADMIN enforced by route middleware (web/auth/scope/role). */
    private function requireNationalAdmin(): int|JsonResponse
    {
        return (int) (auth()->id() ?? 0);
    }
    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if(!empty($meta)){$b['meta']=$meta;} return response()->json($b, 200); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Geo\\Countries][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
