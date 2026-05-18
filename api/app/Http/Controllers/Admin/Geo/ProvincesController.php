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
 * Admin · Geo · Provinces (PHEOCs) CRUD.
 *
 * Web twin of GeoHierarchyController province endpoints. Mobile is never
 * touched. Writes the same `ref_provinces` columns; cascades name changes
 * to ref_poes (admin_level_1, regional_cluster) and re-builds payloads.
 *
 * Province name change is a high-impact operation — it cascades into the
 * mobile bundle. Every write bumps ref_geo_version so mobile clients
 * refresh on next sync.
 */
final class ProvincesController extends Controller
{
    /** Default tenant country (full name; matches ref_poes.country_code). */
    private static function defaultCountry(): string
    {
        return (string) (config('country.legacy_code') ?: 'Uganda');
    }
    private const ADMIN_TYPES = ['PHEOC', 'PROVINCE', 'REGION', 'OTHER'];

    public function index(Request $request)
    {
        return view('admin.geo.provinces.index', [
            'page_title'    => 'Provincial PHEOCs',
            'page_eyebrow'  => 'Geography · Provinces',
            'page_subtitle' => 'Zambia\'s ten provincial Public Health Emergency Operations Centres.',
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        try {
            $country = (string) $request->query('country', self::defaultCountry());
            $status  = strtolower((string) $request->query('status', 'active'));
            $q       = trim((string) $request->query('q', ''));

            $scope = ScopeFilter::fromRequest($request);
            $query = DB::table('ref_provinces')->where('country_code', $country);
            $query = ScopeFilter::applyToProvinces($query, $scope);
            if ($status === 'active')  { $query->whereNull('deleted_at'); }
            if ($status === 'retired') { $query->whereNotNull('deleted_at'); }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $query->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)->orWhere('code', 'like', $like);
                });
            }

            $rows = $query->orderBy('display_order')->orderBy('id')->get();

            // Augment with district + PoE counts (cheap aggregate per row).
            $provinceIds = $rows->pluck('id')->all();
            $distCounts  = DB::table('ref_districts')->whereIn('province_id', $provinceIds)->whereNull('deleted_at')->groupBy('province_id')->selectRaw('province_id, COUNT(*) as c')->pluck('c', 'province_id');
            $poeCounts   = DB::table('ref_poes')->whereIn('province_id', $provinceIds)->whereNull('deleted_at')->groupBy('province_id')->selectRaw('province_id, COUNT(*) as c')->pluck('c', 'province_id');

            $countActive  = ScopeFilter::applyToProvinces(DB::table('ref_provinces')->where('country_code', $country)->whereNull('deleted_at'), $scope)->count();
            $countRetired = ScopeFilter::applyToProvinces(DB::table('ref_provinces')->where('country_code', $country)->whereNotNull('deleted_at'), $scope)->count();

            return $this->ok([
                'rows' => $rows->map(fn ($r) => [
                    'id'                 => (int) $r->id,
                    'country_code'       => (string) $r->country_code,
                    'code'               => (string) $r->code,
                    'name'               => (string) $r->name,
                    'admin_level_1_type' => (string) $r->admin_level_1_type,
                    'is_active'          => (bool) $r->is_active,
                    'is_retired'         => $r->deleted_at !== null,
                    'display_order'      => (int) $r->display_order,
                    'updated_at'         => $r->updated_at,
                    'district_count'     => (int) ($distCounts[$r->id] ?? 0),
                    'poe_count'          => (int) ($poeCounts[$r->id] ?? 0),
                ])->all(),
                'total' => $rows->count(),
            ], 'Provinces.', [
                'country' => $country,
                'tabs'    => ['active' => $countActive, 'retired' => $countRetired, 'all' => $countActive + $countRetired],
                'version' => $this->currentVersion($country),
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $row = DB::table('ref_provinces')->where('id', $id)->first();
            if (!$row) { return $this->err(404, 'Province not found.'); }
            return $this->ok($this->cast($row), 'Province retrieved.');
        } catch (Throwable $e) { return $this->serverError($e, 'show'); }
    }

    public function meta(Request $request): JsonResponse
    {
        return $this->ok([
            'country'      => self::defaultCountry(),
            'admin_types'  => self::ADMIN_TYPES,
            'rules'        => [
                'name_is_canonical' => 'Province name appears verbatim in ref_poes.admin_level_1 — rename cascades to all PoEs in the province.',
                'code_format'       => 'Slug; e.g. lusaka-province-pheoc; auto-derived from name if blank.',
            ],
        ], 'Meta.');
    }

    public function version(Request $request): JsonResponse
    {
        $country = (string) $request->query('country', self::defaultCountry());
        return $this->ok(['country' => $country, 'version' => $this->currentVersion($country)], 'Version.');
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        $data = $request->all();
        $country = trim((string) ($data['country_code'] ?? self::defaultCountry()));
        $name    = trim((string) ($data['name'] ?? ''));
        if ($country === '' || $name === '') { return $this->err(422, 'country_code and name are required.'); }
        $type = (string) ($data['admin_level_1_type'] ?? 'PHEOC');
        if (!in_array($type, self::ADMIN_TYPES, true)) {
            return $this->err(422, 'Invalid admin_level_1_type.', ['allowed' => self::ADMIN_TYPES]);
        }
        $code = isset($data['code']) && $data['code'] !== '' ? trim((string) $data['code']) : $this->slug($name);
        try {
            if (DB::table('ref_provinces')->where('country_code', $country)
                ->where(fn ($w) => $w->where('code', $code)->orWhere('name', $name))
                ->whereNull('deleted_at')->exists()) {
                return $this->err(409, 'Province with same code or name exists.');
            }
            $now = Carbon::now();
            $id = DB::table('ref_provinces')->insertGetId([
                'country_code'       => $country,
                'code'               => $code,
                'name'               => $name,
                'admin_level_1_type' => $type,
                'is_active'          => (int) (bool) ($data['is_active'] ?? true),
                'display_order'      => (int) ($data['display_order'] ?? 999),
                'created_by_user_id' => $admin,
                'updated_by_user_id' => $admin,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $this->bumpVersion($country);
            $row = DB::table('ref_provinces')->where('id', $id)->first();
            return $this->ok($this->cast($row), 'Province created.', ['version' => $this->currentVersion($country)]);
        } catch (Throwable $e) { return $this->serverError($e, 'store'); }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_provinces')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) { return $this->err(404, 'Province not found.'); }
            $data = $request->all();

            $patch = ['updated_at' => Carbon::now(), 'updated_by_user_id' => $admin];
            foreach (['code', 'name', 'display_order'] as $f) {
                if (array_key_exists($f, $data)) { $patch[$f] = $data[$f]; }
            }
            if (array_key_exists('admin_level_1_type', $data)) {
                $t = (string) $data['admin_level_1_type'];
                if (!in_array($t, self::ADMIN_TYPES, true)) {
                    return $this->err(422, 'Invalid admin_level_1_type.', ['allowed' => self::ADMIN_TYPES]);
                }
                $patch['admin_level_1_type'] = $t;
            }
            if (array_key_exists('is_active', $data)) { $patch['is_active'] = (int) (bool) $data['is_active']; }

            DB::table('ref_provinces')->where('id', $row->id)->update($patch);

            // Cascade name change to ref_poes.admin_level_1 + regional_cluster + payload.
            $newName = $patch['name'] ?? $row->name;
            if ($newName !== $row->name) {
                DB::table('ref_poes')->where('province_id', $row->id)->update([
                    'admin_level_1'    => $newName,
                    'regional_cluster' => $newName,
                ]);
                $this->rebuildPayloadsForProvince((int) $row->id);
            }

            $this->bumpVersion((string) $row->country_code);
            $fresh = DB::table('ref_provinces')->where('id', $row->id)->first();
            return $this->ok($this->cast($fresh), 'Province updated.', ['version' => $this->currentVersion((string) $row->country_code)]);
        } catch (Throwable $e) { return $this->serverError($e, 'update'); }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_provinces')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) { return $this->err(404, 'Province not found.'); }
            $children = DB::table('ref_poes')->where('province_id', $id)->whereNull('deleted_at')->count()
                      + DB::table('ref_districts')->where('province_id', $id)->whereNull('deleted_at')->count()
                      + DB::table('ref_hospitals')->where('province_id', $id)->whereNull('deleted_at')->count();
            if ($children > 0) {
                return $this->err(409, 'Province has active districts / PoEs / hospitals. Retire those first.', ['children' => $children]);
            }
            DB::table('ref_provinces')->where('id', $row->id)->update([
                'deleted_at' => Carbon::now(),
                'updated_by_user_id' => $admin,
                'updated_at' => Carbon::now(),
            ]);
            $this->bumpVersion((string) $row->country_code);
            return $this->ok(['id' => (int) $row->id, 'soft_deleted' => true], 'Province retired.', ['version' => $this->currentVersion((string) $row->country_code)]);
        } catch (Throwable $e) { return $this->serverError($e, 'destroy'); }
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_provinces')->where('id', $id)->whereNotNull('deleted_at')->first();
            if (!$row) { return $this->err(404, 'Province not found or already active.'); }
            DB::table('ref_provinces')->where('id', $row->id)->update([
                'deleted_at' => null,
                'updated_by_user_id' => $admin,
                'updated_at' => Carbon::now(),
            ]);
            $this->bumpVersion((string) $row->country_code);
            $fresh = DB::table('ref_provinces')->where('id', $row->id)->first();
            return $this->ok($this->cast($fresh), 'Province restored.', ['version' => $this->currentVersion((string) $row->country_code)]);
        } catch (Throwable $e) { return $this->serverError($e, 'restore'); }
    }

    /* ── helpers ─────────────────────────────────────────────────────── */

    private function rebuildPayloadsForProvince(int $provinceId): void
    {
        $rows = DB::table('ref_poes')->where('province_id', $provinceId)->get();
        foreach ($rows as $r) {
            $province = DB::table('ref_provinces')->where('id', $r->province_id)->first();
            $district = DB::table('ref_districts')->where('id', $r->district_id)->first();
            if (!$province || !$district) { continue; }
            $payload = $r->payload ? (json_decode((string) $r->payload, true) ?: []) : [];
            $payload = array_merge($payload, [
                'province'                   => $province->name,
                'admin_level_1'              => $province->name,
                'admin_level_1_type'         => $province->admin_level_1_type,
                'regional_cluster_or_rpheoc' => $province->name,
                'source_province_group'      => $province->name,
            ]);
            // Re-key in canonical order.
            $payload = $this->reorderPoePayload($payload);
            DB::table('ref_poes')->where('id', $r->id)->update([
                'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => Carbon::now(),
            ]);
        }
    }

    private function reorderPoePayload(array $src): array
    {
        $order = ['id', 'country', 'province', 'admin_level_1', 'admin_level_1_type',
                  'district', 'district_raw', 'poe_name', 'poe_code', 'poe_type',
                  'transport_mode', 'border_country', 'is_major_entry',
                  'is_recommended_osbp', 'is_national_level',
                  'regional_cluster_or_rpheoc', 'critical_details',
                  'source_province_group', 'source_url', 'source_origin'];
        $out = [];
        foreach ($order as $k) {
            $out[$k] = $src[$k] ?? (in_array($k, ['is_major_entry','is_recommended_osbp','is_national_level'], true) ? false : null);
        }
        foreach (['is_major_entry','is_recommended_osbp','is_national_level'] as $b) { $out[$b] = (bool) $out[$b]; }
        return $out;
    }

    private function cast(object $r): array
    {
        return [
            'id'                 => (int) $r->id,
            'country_code'       => (string) $r->country_code,
            'code'               => (string) $r->code,
            'name'               => (string) $r->name,
            'admin_level_1_type' => (string) $r->admin_level_1_type,
            'is_active'          => (bool) $r->is_active,
            'is_retired'         => $r->deleted_at !== null,
            'display_order'      => (int) $r->display_order,
            'created_at'         => $r->created_at,
            'updated_at'         => $r->updated_at,
            'deleted_at'         => $r->deleted_at,
        ];
    }

    private function currentVersion(string $country): int
    {
        return (int) (DB::table('ref_geo_version')->where('country_code', $country)->value('version') ?? 0);
    }
    private function bumpVersion(string $country): void
    {
        DB::table('ref_geo_version')->updateOrInsert(['country_code' => $country], [
            'version' => DB::raw('COALESCE(version,0) + 1'),
            'etag' => null, 'last_built_at' => Carbon::now(),
            'updated_at' => Carbon::now(), 'created_at' => Carbon::now(),
        ]);
    }
    private function slug(string $s): string
    {
        $s = strtolower(trim($s));
        return trim((string) preg_replace('/[^a-z0-9]+/u', '-', $s), '-');
    }

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
    { Log::error("[Admin\\Geo\\Provinces][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
