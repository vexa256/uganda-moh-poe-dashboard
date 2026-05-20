<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * GeoHierarchyController — mobile-facing read bundle + admin CRUD
 * over the normalised geo hierarchy (countries → provinces → districts →
 * POEs + hospitals + dataset metadata + traveler notes).
 *
 * The bundle endpoint (GET /api/poes/bundle) assembles a payload that is
 * byte-equivalent to the legacy hardcoded src/POEs.js blob.  Any mutation
 * (CRUD write) bumps ref_geo_version and invalidates the cached ETag.
 *
 * Routes:
 *   GET    /poes/bundle
 *   GET    /poes/bundle/version
 *
 *   GET    /geo/countries              POST /geo/countries
 *   GET    /geo/countries/{code}       PATCH /geo/countries/{code}
 *                                      DELETE /geo/countries/{code}
 *
 *   GET    /geo/provinces              POST /geo/provinces
 *   GET    /geo/provinces/{id}         PATCH /geo/provinces/{id}
 *                                      DELETE /geo/provinces/{id}
 *
 *   GET    /geo/districts              POST /geo/districts
 *   GET    /geo/districts/{id}         PATCH /geo/districts/{id}
 *                                      DELETE /geo/districts/{id}
 *
 *   GET    /geo/poes                   POST /geo/poes
 *   GET    /geo/poes/{id}              PATCH /geo/poes/{id}
 *                                      DELETE /geo/poes/{id}
 *
 *   GET    /geo/hospitals              POST /geo/hospitals
 *   GET    /geo/hospitals/{id}         PATCH /geo/hospitals/{id}
 *                                      DELETE /geo/hospitals/{id}
 *
 * Write operations require user_id of a NATIONAL_ADMIN (same convention
 * used across the rest of the mobile API surface — role enforced in-line).
 */
final class GeoHierarchyController extends Controller
{
    // Country tenant for this deployment. Driven by config('app.country_tenant').
    // Falls back to 'Uganda' for this Uganda POE Sentinel deployment.
    private static function defaultCountry(): string
    {
        return (string) config('app.country_tenant', 'Uganda');
    }

    private const POE_PAYLOAD_KEY_ORDER = [
        'id', 'country', 'province', 'admin_level_1', 'admin_level_1_type',
        'district', 'district_raw', 'poe_name', 'poe_code', 'poe_type',
        'transport_mode', 'border_country', 'is_major_entry',
        'is_recommended_osbp', 'is_national_level',
        'regional_cluster_or_rpheoc', 'critical_details',
        'source_province_group', 'source_url', 'source_origin',
    ];

    private const POE_TYPES = ['airport', 'airstrip', 'port', 'island_entry', 'land_border', 'rail', 'other'];
    private const TRANSPORT_MODES = ['air', 'water', 'land', 'rail', 'other'];
    private const HOSPITAL_TYPES = ['TEACHING', 'GENERAL', 'DISTRICT', 'RURAL', 'CLINIC', 'PRIVATE', 'MILITARY', 'OTHER'];

    /**
     * transport_mode is deterministically derived from poe_type so the
     * payload shape can never drift from the legacy POEs.js convention.
     * Only poe_type = 'other' leaves the mode to the caller.
     */
    private const POE_TYPE_TO_MODE = [
        'airport'      => 'air',
        'airstrip'     => 'air',
        'port'         => 'water',
        'island_entry' => 'water',
        'land_border'  => 'land',
        'rail'         => 'rail',
    ];

    /**
     * Fields the server ALWAYS owns — user-supplied values on these keys
     * are ignored on store/update to protect the bundle shape.
     */
    private const POE_SERVER_OWNED = [
        'country', 'province', 'admin_level_1', 'admin_level_1_type',
        'district', 'district_raw', 'regional_cluster_or_rpheoc',
        'source_province_group', 'transport_mode',
    ];

    private static function defaultSourceOrigin(): string
    {
        return (string) config(
            'app.country_source_origin',
            'Uganda Directorate of Citizenship & Immigration Control - Gazetted Border Posts 2026'
        );
    }

    /* ═══════════════════════════════════════════════════════════════
       BUNDLE
    ═══════════════════════════════════════════════════════════════ */

    // GET /poes/bundle?country=Uganda
    public function bundle(Request $request): JsonResponse
    {
        $country = (string) $request->query('country', self::defaultCountry());
        try {
            $bundle = $this->assembleBundle($country);
            if ($bundle === null) {
                return $this->err(404, 'No bundle for country.', ['country' => $country]);
            }
            $json = json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $etag = 'W/"' . sha1($json) . '"';

            // Persist ETag for cheap version-check endpoint reuse.
            DB::table('ref_geo_version')->updateOrInsert(
                ['country_code' => $country],
                ['etag' => substr(sha1($json), 0, 40), 'last_built_at' => Carbon::now(), 'updated_at' => Carbon::now()]
            );

            $ifNoneMatch = (string) $request->header('If-None-Match', '');
            if ($ifNoneMatch !== '' && $this->etagMatches($ifNoneMatch, $etag)) {
                return response()->json(null, 304)
                    ->header('ETag', $etag)
                    ->header('Cache-Control', 'no-cache');
            }

            $version = (int) (DB::table('ref_geo_version')->where('country_code', $country)->value('version') ?? 1);

            return response()->json([
                'success' => true,
                'message' => 'POE bundle.',
                'data'    => $bundle,
                'meta'    => [
                    'country'       => $country,
                    'version'       => $version,
                    'etag'          => $etag,
                    'counts'        => [
                        'poes'                  => count($bundle['poes'] ?? []),
                        'administrative_groups' => count($bundle['administrative_groups'] ?? []),
                        'traveler_notes'        => count($bundle['traveler_notes'] ?? []),
                    ],
                    'generated_at'  => Carbon::now()->toIso8601String(),
                ],
            ], 200)->header('ETag', $etag)->header('Cache-Control', 'no-cache');
        } catch (Throwable $e) {
            return $this->serverError($e, 'bundle');
        }
    }

    // GET /poes/bundle/version?country=Uganda
    public function bundleVersion(Request $request): JsonResponse
    {
        $country = (string) $request->query('country', self::defaultCountry());
        try {
            $row = DB::table('ref_geo_version')->where('country_code', $country)->first();
            if (!$row) {
                return $this->err(404, 'No version row for country.', ['country' => $country]);
            }
            return $this->ok([
                'country'       => $country,
                'version'       => (int) $row->version,
                'etag'          => $row->etag ? 'W/"' . $row->etag . '"' : null,
                'last_built_at' => $row->last_built_at,
            ], 'Version.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'bundleVersion');
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       COUNTRIES — CRUD
    ═══════════════════════════════════════════════════════════════ */

    public function indexCountries(Request $request): JsonResponse
    {
        try {
            $rows = DB::table('ref_countries')->whereNull('deleted_at')->orderBy('display_order')->orderBy('id')->get();
            return $this->ok($rows->map(fn ($r) => $this->castCountry($r))->all(), 'Countries retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'indexCountries');
        }
    }

    public function showCountry(Request $request, string $code): JsonResponse
    {
        try {
            $row = DB::table('ref_countries')->where('country_code', $code)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'Country not found.');
            }
            return $this->ok($this->castCountry($row), 'Country retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'showCountry');
        }
    }

    public function storeCountry(Request $request): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        $data = $request->all();
        $countryCode = trim((string) ($data['country_code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($countryCode === '' || $name === '') {
            return $this->err(422, 'country_code and name are required.');
        }
        try {
            $existing = DB::table('ref_countries')->where('country_code', $countryCode)->first();
            if ($existing) {
                return $this->err(409, 'Country already exists.', ['country_code' => $countryCode]);
            }
            $now = Carbon::now();
            $id = DB::table('ref_countries')->insertGetId([
                'country_code'       => $countryCode,
                'iso_alpha2'         => $data['iso_alpha2'] ?? null,
                'iso_alpha3'         => $data['iso_alpha3'] ?? null,
                'name'               => $name,
                'is_active'          => (int) (bool) ($data['is_active'] ?? true),
                'display_order'      => (int) ($data['display_order'] ?? 999),
                'metadata_json'      => isset($data['metadata_json']) ? json_encode($data['metadata_json']) : null,
                'created_by_user_id' => $admin,
                'updated_by_user_id' => $admin,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            DB::table('ref_geo_version')->updateOrInsert(
                ['country_code' => $countryCode],
                ['version' => 1, 'last_built_at' => $now, 'updated_at' => $now, 'created_at' => $now]
            );
            $row = DB::table('ref_countries')->where('id', $id)->first();
            return $this->ok($this->castCountry($row), 'Country created.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'storeCountry');
        }
    }

    public function updateCountry(Request $request, string $code): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_countries')->where('country_code', $code)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'Country not found.');
            }
            $data = $request->all();
            $patch = ['updated_at' => Carbon::now(), 'updated_by_user_id' => $admin];
            foreach (['name', 'iso_alpha2', 'iso_alpha3', 'display_order'] as $f) {
                if (array_key_exists($f, $data)) {
                    $patch[$f] = $data[$f];
                }
            }
            if (array_key_exists('is_active', $data)) {
                $patch['is_active'] = (int) (bool) $data['is_active'];
            }
            if (array_key_exists('metadata_json', $data)) {
                $patch['metadata_json'] = $data['metadata_json'] === null ? null : json_encode($data['metadata_json']);
            }
            DB::table('ref_countries')->where('id', $row->id)->update($patch);
            $this->bumpVersion($code);
            $fresh = DB::table('ref_countries')->where('id', $row->id)->first();
            return $this->ok($this->castCountry($fresh), 'Country updated.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'updateCountry');
        }
    }

    public function destroyCountry(Request $request, string $code): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_countries')->where('country_code', $code)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'Country not found.');
            }
            // Hard-delete — blocked only if active dependents exist.
            $childPoe = DB::table('ref_poes')->where('country_code', $code)->whereNull('deleted_at')->count();
            if ($childPoe > 0) {
                return $this->err(409, 'Country has active POEs. Delete them first.', ['poes' => $childPoe]);
            }
            DB::table('ref_countries')->where('id', $row->id)->delete();
            $this->bumpVersion($code);
            return $this->ok(['country_code' => $code, 'deleted' => true], 'Country deleted.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'destroyCountry');
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       PROVINCES — CRUD
    ═══════════════════════════════════════════════════════════════ */

    public function indexProvinces(Request $request): JsonResponse
    {
        try {
            $q = DB::table('ref_provinces')->whereNull('deleted_at');
            if ($country = $request->query('country')) {
                $q->where('country_code', $country);
            }
            $rows = $q->orderBy('display_order')->orderBy('id')->get();
            return $this->ok($rows->map(fn ($r) => $this->castProvince($r))->all(), 'Provinces retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'indexProvinces');
        }
    }

    public function showProvince(Request $request, int $id): JsonResponse
    {
        try {
            $row = DB::table('ref_provinces')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'Province not found.');
            }
            return $this->ok($this->castProvince($row), 'Province retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'showProvince');
        }
    }

    public function storeProvince(Request $request): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        $data = $request->all();
        $country = trim((string) ($data['country_code'] ?? self::defaultCountry()));
        $name = trim((string) ($data['name'] ?? ''));
        if ($country === '' || $name === '') {
            return $this->err(422, 'country_code and name are required.');
        }
        $code = isset($data['code']) && $data['code'] !== '' ? trim((string) $data['code']) : $this->slug($name);
        try {
            if (DB::table('ref_provinces')->where('country_code', $country)->where(function ($w) use ($code, $name) {
                $w->where('code', $code)->orWhere('name', $name);
            })->whereNull('deleted_at')->exists()) {
                return $this->err(409, 'Province with same code or name exists.');
            }
            $now = Carbon::now();
            $id = DB::table('ref_provinces')->insertGetId([
                'country_code'       => $country,
                'code'               => $code,
                'name'               => $name,
                'admin_level_1_type' => (string) ($data['admin_level_1_type'] ?? 'PHEOC'),
                'is_active'          => (int) (bool) ($data['is_active'] ?? true),
                'display_order'      => (int) ($data['display_order'] ?? 999),
                'metadata_json'      => isset($data['metadata_json']) ? json_encode($data['metadata_json']) : null,
                'created_by_user_id' => $admin,
                'updated_by_user_id' => $admin,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $this->bumpVersion($country);
            $row = DB::table('ref_provinces')->where('id', $id)->first();
            return $this->ok($this->castProvince($row), 'Province created.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'storeProvince');
        }
    }

    public function updateProvince(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_provinces')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'Province not found.');
            }
            $data = $request->all();
            $patch = ['updated_at' => Carbon::now(), 'updated_by_user_id' => $admin];
            foreach (['code', 'name', 'admin_level_1_type', 'display_order'] as $f) {
                if (array_key_exists($f, $data)) {
                    $patch[$f] = $data[$f];
                }
            }
            if (array_key_exists('is_active', $data)) {
                $patch['is_active'] = (int) (bool) $data['is_active'];
            }
            if (array_key_exists('metadata_json', $data)) {
                $patch['metadata_json'] = $data['metadata_json'] === null ? null : json_encode($data['metadata_json']);
            }
            DB::table('ref_provinces')->where('id', $row->id)->update($patch);
            // If province name changed, cascade to ref_districts + ref_poes + payload.
            $newName = $patch['name'] ?? $row->name;
            if ($newName !== $row->name) {
                DB::table('ref_poes')->where('province_id', $row->id)->update([
                    'admin_level_1'    => $newName,
                    'regional_cluster' => $newName,
                ]);
                $this->rebuildPoePayloadsForProvince((int) $row->id);
            }
            $this->bumpVersion((string) $row->country_code);
            $fresh = DB::table('ref_provinces')->where('id', $row->id)->first();
            return $this->ok($this->castProvince($fresh), 'Province updated.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'updateProvince');
        }
    }

    public function destroyProvince(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_provinces')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'Province not found.');
            }
            $children = DB::table('ref_poes')->where('province_id', $id)->whereNull('deleted_at')->count()
                      + DB::table('ref_districts')->where('province_id', $id)->whereNull('deleted_at')->count()
                      + DB::table('ref_hospitals')->where('province_id', $id)->whereNull('deleted_at')->count();
            if ($children > 0) {
                return $this->err(409, 'Province has active districts/POEs/hospitals. Remove them first.', ['children' => $children]);
            }
            DB::table('ref_provinces')->where('id', $row->id)->delete();
            $this->bumpVersion((string) $row->country_code);
            return $this->ok(['id' => $row->id, 'deleted' => true], 'Province deleted.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'destroyProvince');
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       DISTRICTS — CRUD
    ═══════════════════════════════════════════════════════════════ */

    public function indexDistricts(Request $request): JsonResponse
    {
        try {
            $q = DB::table('ref_districts')->whereNull('deleted_at');
            if ($country = $request->query('country')) {
                $q->where('country_code', $country);
            }
            if ($pid = (int) $request->query('province_id', 0)) {
                $q->where('province_id', $pid);
            }
            $rows = $q->orderBy('display_order')->orderBy('id')->get();
            return $this->ok($rows->map(fn ($r) => $this->castDistrict($r))->all(), 'Districts retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'indexDistricts');
        }
    }

    public function showDistrict(Request $request, int $id): JsonResponse
    {
        try {
            $row = DB::table('ref_districts')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'District not found.');
            }
            return $this->ok($this->castDistrict($row), 'District retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'showDistrict');
        }
    }

    public function storeDistrict(Request $request): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        $data = $request->all();
        $country = trim((string) ($data['country_code'] ?? self::defaultCountry()));
        $provinceId = (int) ($data['province_id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        if ($country === '' || $provinceId <= 0 || $name === '') {
            return $this->err(422, 'country_code, province_id and name are required.');
        }
        $province = DB::table('ref_provinces')->where('id', $provinceId)->whereNull('deleted_at')->first();
        if (!$province || $province->country_code !== $country) {
            return $this->err(422, 'Province not found or country mismatch.');
        }
        $code = isset($data['code']) && $data['code'] !== '' ? trim((string) $data['code']) : $this->slug($name);
        $nameRaw = (string) ($data['name_raw'] ?? preg_replace('/\s+District\s*$/u', '', $name));
        try {
            if (DB::table('ref_districts')->where('country_code', $country)->where(function ($w) use ($code, $name) {
                $w->where('code', $code)->orWhere('name', $name);
            })->whereNull('deleted_at')->exists()) {
                return $this->err(409, 'District with same code or name exists.');
            }
            $now = Carbon::now();
            $id = DB::table('ref_districts')->insertGetId([
                'country_code'       => $country,
                'province_id'        => $provinceId,
                'code'               => $code,
                'name'               => $name,
                'name_raw'           => $nameRaw,
                'is_active'          => (int) (bool) ($data['is_active'] ?? true),
                'display_order'      => (int) ($data['display_order'] ?? 999),
                'metadata_json'      => isset($data['metadata_json']) ? json_encode($data['metadata_json']) : null,
                'created_by_user_id' => $admin,
                'updated_by_user_id' => $admin,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $this->bumpVersion($country);
            $row = DB::table('ref_districts')->where('id', $id)->first();
            return $this->ok($this->castDistrict($row), 'District created.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'storeDistrict');
        }
    }

    public function updateDistrict(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_districts')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'District not found.');
            }
            $data = $request->all();
            $patch = ['updated_at' => Carbon::now(), 'updated_by_user_id' => $admin];
            foreach (['code', 'name', 'name_raw', 'display_order'] as $f) {
                if (array_key_exists($f, $data)) {
                    $patch[$f] = $data[$f];
                }
            }
            if (array_key_exists('province_id', $data)) {
                $patch['province_id'] = (int) $data['province_id'];
            }
            if (array_key_exists('is_active', $data)) {
                $patch['is_active'] = (int) (bool) $data['is_active'];
            }
            if (array_key_exists('metadata_json', $data)) {
                $patch['metadata_json'] = $data['metadata_json'] === null ? null : json_encode($data['metadata_json']);
            }
            DB::table('ref_districts')->where('id', $row->id)->update($patch);
            // Cascade name change to ref_poes + payload
            $newName = $patch['name'] ?? $row->name;
            if ($newName !== $row->name) {
                DB::table('ref_poes')->where('district_id', $row->id)->update(['district' => $newName]);
                $this->rebuildPoePayloadsForDistrict((int) $row->id);
            }
            $this->bumpVersion((string) $row->country_code);
            $fresh = DB::table('ref_districts')->where('id', $row->id)->first();
            return $this->ok($this->castDistrict($fresh), 'District updated.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'updateDistrict');
        }
    }

    public function destroyDistrict(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_districts')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'District not found.');
            }
            $children = DB::table('ref_poes')->where('district_id', $id)->whereNull('deleted_at')->count()
                      + DB::table('ref_hospitals')->where('district_id', $id)->whereNull('deleted_at')->count();
            if ($children > 0) {
                return $this->err(409, 'District has active POEs/hospitals. Remove them first.', ['children' => $children]);
            }
            DB::table('ref_districts')->where('id', $row->id)->delete();
            $this->bumpVersion((string) $row->country_code);
            return $this->ok(['id' => $row->id, 'deleted' => true], 'District deleted.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'destroyDistrict');
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       POES — CRUD
    ═══════════════════════════════════════════════════════════════ */

    public function indexPoes(Request $request): JsonResponse
    {
        try {
            $q = DB::table('ref_poes')->whereNull('deleted_at');
            if ($country = $request->query('country')) {
                $q->where('country_code', $country);
            }
            if ($pid = (int) $request->query('province_id', 0)) {
                $q->where('province_id', $pid);
            }
            if ($did = (int) $request->query('district_id', 0)) {
                $q->where('district_id', $did);
            }
            $rows = $q->orderBy('display_order')->orderBy('id')->get();
            return $this->ok($rows->map(fn ($r) => $this->castPoe($r))->all(), 'POEs retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'indexPoes');
        }
    }

    public function showPoe(Request $request, int $id): JsonResponse
    {
        try {
            $row = DB::table('ref_poes')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'POE not found.');
            }
            return $this->ok($this->castPoe($row), 'POE retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'showPoe');
        }
    }

    /**
     * Create a POE.  The server owns every derived field — the client
     * supplies only the minimum set and the payload shape is assembled
     * from the FK rows so it can never drift from the legacy POEs.js
     * convention.
     *
     * ACCEPTED (required):  poe_name, province_id, district_id, poe_type
     * ACCEPTED (optional):  border_country, is_major_entry,
     *                       is_recommended_osbp, is_national_level,
     *                       critical_details, source_url,
     *                       source_origin (defaults to the gazette string),
     *                       is_active, display_order, latitude, longitude,
     *                       transport_mode  (only honoured when
     *                                        poe_type='other'),
     *                       poe_code        (defaults to poe_name),
     *                       external_id     (auto-generated if absent).
     *
     * IGNORED (server-owned, always derived from FK rows):
     *   country, province, admin_level_1, admin_level_1_type,
     *   district, district_raw, regional_cluster_or_rpheoc,
     *   source_province_group, transport_mode (for typed POEs).
     */
    public function storePoe(Request $request): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        $data = $request->all();

        $country    = self::defaultCountry();           // locked to the active tenant
        $name       = trim((string) ($data['poe_name'] ?? ''));
        $provinceId = (int) ($data['province_id'] ?? 0);
        $districtId = (int) ($data['district_id'] ?? 0);
        $poeType    = (string) ($data['poe_type'] ?? 'land_border');

        if ($name === '' || $provinceId <= 0 || $districtId <= 0) {
            return $this->err(422, 'poe_name, province_id, district_id are required.');
        }
        if (!in_array($poeType, self::POE_TYPES, true)) {
            return $this->err(422, 'Invalid poe_type.', ['allowed' => self::POE_TYPES]);
        }

        $province = DB::table('ref_provinces')->where('id', $provinceId)->whereNull('deleted_at')->first();
        $district = DB::table('ref_districts')->where('id', $districtId)->whereNull('deleted_at')->first();
        if (!$province || $province->country_code !== $country) {
            return $this->err(422, 'Province invalid for country.');
        }
        if (!$district || $district->country_code !== $country || (int) $district->province_id !== $provinceId) {
            return $this->err(422, 'District invalid for province/country.');
        }

        // transport_mode is derived from poe_type; only 'other' lets the caller override.
        $mode = $this->deriveTransportMode($poeType, $data['transport_mode'] ?? null);
        if (!in_array($mode, self::TRANSPORT_MODES, true)) {
            return $this->err(422, 'Invalid transport_mode.', ['allowed' => self::TRANSPORT_MODES]);
        }

        $poeCode = isset($data['poe_code']) && $data['poe_code'] !== '' ? trim((string) $data['poe_code']) : $name;
        $externalId = isset($data['external_id']) && $data['external_id'] !== ''
            ? (string) $data['external_id']
            : $this->nextExternalId($country, $province->name, $district->name, $name);

        try {
            if (DB::table('ref_poes')->where('external_id', $externalId)->exists()) {
                return $this->err(409, 'POE with that external_id exists.');
            }

            $districtRaw = (string) ($district->name_raw ?? preg_replace('/\s+District\s*$/u', '', $district->name));

            // Payload shape is assembled in the exact POE_PAYLOAD_KEY_ORDER
            // so json_encode preserves it byte-for-byte with legacy POEs.js.
            $payload = $this->buildPayload([
                'id'                         => $externalId,
                'country'                    => $country,
                'province'                   => $province->name,
                'admin_level_1'              => $province->name,
                'admin_level_1_type'         => $province->admin_level_1_type,
                'district'                   => $district->name,
                'district_raw'               => $districtRaw,
                'poe_name'                   => $name,
                'poe_code'                   => $poeCode,
                'poe_type'                   => $poeType,
                'transport_mode'             => $mode,
                'border_country'             => $this->nullableString($data['border_country'] ?? null),
                'is_major_entry'             => (bool) ($data['is_major_entry'] ?? false),
                'is_recommended_osbp'        => (bool) ($data['is_recommended_osbp'] ?? false),
                'is_national_level'          => (bool) ($data['is_national_level'] ?? false),
                'regional_cluster_or_rpheoc' => $province->name,
                'critical_details'           => (string) ($data['critical_details'] ?? ''),
                'source_province_group'      => $province->name,
                'source_url'                 => (string) ($data['source_url'] ?? ''),
                'source_origin'              => (string) ($data['source_origin'] ?? self::defaultSourceOrigin()),
            ]);

            $now = Carbon::now();
            $nextOrder = array_key_exists('display_order', $data)
                ? (int) $data['display_order']
                : (((int) DB::table('ref_poes')->max('display_order')) + 1);

            $id = DB::table('ref_poes')->insertGetId([
                'external_id'         => $externalId,
                'country_code'        => $country,
                'poe_code'            => $poeCode,
                'poe_name'            => $name,
                'admin_level_1'       => $province->name,
                'admin_level_1_type'  => $province->admin_level_1_type,
                'province_id'         => $provinceId,
                'district'            => $district->name,
                'district_id'         => $districtId,
                'poe_type'            => $poeType,
                'transport_mode'      => $mode,
                'regional_cluster'    => $province->name,
                'is_national_level'   => (int) (bool) ($data['is_national_level'] ?? false),
                'is_major_entry'      => (int) (bool) ($data['is_major_entry'] ?? false),
                'is_recommended_osbp' => (int) (bool) ($data['is_recommended_osbp'] ?? false),
                'border_country'      => $payload['border_country'],
                'latitude'            => $data['latitude'] ?? null,
                'longitude'           => $data['longitude'] ?? null,
                'gazette_source'      => $payload['source_url'] ?: null,
                'payload'             => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_active'           => (int) (bool) ($data['is_active'] ?? true),
                'display_order'       => $nextOrder,
                'created_by_user_id'  => $admin,
                'updated_by_user_id'  => $admin,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
            $this->bumpVersion($country);
            $fresh = DB::table('ref_poes')->where('id', $id)->first();
            return $this->ok($this->castPoe($fresh), 'POE created.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'storePoe');
        }
    }

    /**
     * Update a POE.  Same derivation rules as storePoe — any user-supplied
     * value on a server-owned key (country, province, admin_level_*,
     * district, district_raw, regional_cluster_or_rpheoc,
     * source_province_group, transport_mode for typed POEs) is ignored.
     * The payload is rebuilt from the authoritative FK rows + user input
     * on the narrow set of mutable fields.
     */
    public function updatePoe(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_poes')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'POE not found.');
            }
            $data = $request->all();

            $province = DB::table('ref_provinces')->where('id', $row->province_id)->first();
            $district = DB::table('ref_districts')->where('id', $row->district_id)->first();

            if (array_key_exists('province_id', $data)) {
                $p = DB::table('ref_provinces')->where('id', (int) $data['province_id'])->whereNull('deleted_at')->first();
                if (!$p) { return $this->err(422, 'Province not found.'); }
                $province = $p;
            }
            if (array_key_exists('district_id', $data)) {
                $d = DB::table('ref_districts')->where('id', (int) $data['district_id'])->whereNull('deleted_at')->first();
                if (!$d) { return $this->err(422, 'District not found.'); }
                $district = $d;
            }
            // Province/district must stay inside the same country.
            if ($province->country_code !== $row->country_code || $district->country_code !== $row->country_code
                || (int) $district->province_id !== (int) $province->id) {
                return $this->err(422, 'Province/district scope mismatch.');
            }

            $poeType = (string) ($data['poe_type'] ?? $row->poe_type);
            if (!in_array($poeType, self::POE_TYPES, true)) {
                return $this->err(422, 'Invalid poe_type.', ['allowed' => self::POE_TYPES]);
            }
            $mode = $this->deriveTransportMode($poeType, $data['transport_mode'] ?? $row->transport_mode);
            if (!in_array($mode, self::TRANSPORT_MODES, true)) {
                return $this->err(422, 'Invalid transport_mode.', ['allowed' => self::TRANSPORT_MODES]);
            }

            $existingPayload = $row->payload ? (json_decode((string) $row->payload, true) ?: []) : [];
            $districtRaw = (string) ($district->name_raw ?? preg_replace('/\s+District\s*$/u', '', $district->name));

            $newName = trim((string) ($data['poe_name'] ?? $row->poe_name));
            // poe_code follows poe_name unless explicitly overridden (legacy convention).
            $newCode = array_key_exists('poe_code', $data) && $data['poe_code'] !== ''
                ? (string) $data['poe_code']
                : $newName;

            $payload = $this->buildPayload([
                'id'                         => $row->external_id,
                'country'                    => (string) $row->country_code,
                'province'                   => $province->name,
                'admin_level_1'              => $province->name,
                'admin_level_1_type'         => $province->admin_level_1_type,
                'district'                   => $district->name,
                'district_raw'               => $districtRaw,
                'poe_name'                   => $newName,
                'poe_code'                   => $newCode,
                'poe_type'                   => $poeType,
                'transport_mode'             => $mode,
                'border_country'             => array_key_exists('border_country', $data)
                    ? $this->nullableString($data['border_country'])
                    : ($existingPayload['border_country'] ?? null),
                'is_major_entry'             => (bool) ($data['is_major_entry'] ?? $row->is_major_entry),
                'is_recommended_osbp'        => (bool) ($data['is_recommended_osbp'] ?? $row->is_recommended_osbp),
                'is_national_level'          => (bool) ($data['is_national_level'] ?? $row->is_national_level),
                'regional_cluster_or_rpheoc' => $province->name,
                'critical_details'           => (string) ($data['critical_details'] ?? ($existingPayload['critical_details'] ?? '')),
                'source_province_group'      => $province->name,
                'source_url'                 => (string) ($data['source_url'] ?? ($existingPayload['source_url'] ?? '')),
                'source_origin'              => (string) ($data['source_origin'] ?? ($existingPayload['source_origin'] ?? self::defaultSourceOrigin())),
            ]);

            $patch = [
                'updated_at'          => Carbon::now(),
                'updated_by_user_id'  => $admin,
                'poe_code'            => (string) $payload['poe_code'],
                'poe_name'            => (string) $payload['poe_name'],
                'admin_level_1'       => (string) $province->name,
                'admin_level_1_type'  => (string) $province->admin_level_1_type,
                'province_id'         => (int) $province->id,
                'district'            => (string) $district->name,
                'district_id'         => (int) $district->id,
                'poe_type'            => $poeType,
                'transport_mode'      => $mode,
                'regional_cluster'    => (string) $province->name,
                'is_national_level'   => (int) (bool) $payload['is_national_level'],
                'is_major_entry'      => (int) (bool) $payload['is_major_entry'],
                'is_recommended_osbp' => (int) (bool) $payload['is_recommended_osbp'],
                'border_country'      => $payload['border_country'],
                'gazette_source'      => $payload['source_url'] ?: null,
                'payload'             => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            if (array_key_exists('is_active', $data))     { $patch['is_active']     = (int) (bool) $data['is_active']; }
            if (array_key_exists('display_order', $data)) { $patch['display_order'] = (int) $data['display_order']; }
            if (array_key_exists('latitude', $data))      { $patch['latitude']      = $data['latitude']; }
            if (array_key_exists('longitude', $data))     { $patch['longitude']     = $data['longitude']; }

            DB::table('ref_poes')->where('id', $row->id)->update($patch);
            $this->bumpVersion((string) $row->country_code);
            $fresh = DB::table('ref_poes')->where('id', $row->id)->first();
            return $this->ok($this->castPoe($fresh), 'POE updated.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'updatePoe');
        }
    }

    public function destroyPoe(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_poes')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'POE not found.');
            }
            DB::table('ref_poes')->where('id', $row->id)->update([
                'deleted_at'         => Carbon::now(),
                'updated_by_user_id' => $admin,
                'updated_at'         => Carbon::now(),
            ]);
            $this->bumpVersion((string) $row->country_code);
            return $this->ok(['id' => $row->id, 'soft_deleted' => true], 'POE soft-deleted.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'destroyPoe');
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       HOSPITALS — CRUD
    ═══════════════════════════════════════════════════════════════ */

    public function indexHospitals(Request $request): JsonResponse
    {
        try {
            $q = DB::table('ref_hospitals')->whereNull('deleted_at');
            if ($country = $request->query('country')) {
                $q->where('country_code', $country);
            }
            if ($pid = (int) $request->query('province_id', 0)) {
                $q->where('province_id', $pid);
            }
            if ($did = (int) $request->query('district_id', 0)) {
                $q->where('district_id', $did);
            }
            $rows = $q->orderBy('display_order')->orderBy('id')->get();
            return $this->ok($rows->map(fn ($r) => $this->castHospital($r))->all(), 'Hospitals retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'indexHospitals');
        }
    }

    public function showHospital(Request $request, int $id): JsonResponse
    {
        try {
            $row = DB::table('ref_hospitals')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'Hospital not found.');
            }
            return $this->ok($this->castHospital($row), 'Hospital retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'showHospital');
        }
    }

    public function storeHospital(Request $request): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        $data = $request->all();
        $country = trim((string) ($data['country_code'] ?? self::defaultCountry()));
        $provinceId = (int) ($data['province_id'] ?? 0);
        $districtId = isset($data['district_id']) ? (int) $data['district_id'] : null;
        $name = trim((string) ($data['name'] ?? ''));
        $type = strtoupper((string) ($data['hospital_type'] ?? 'GENERAL'));
        if ($country === '' || $provinceId <= 0 || $name === '') {
            return $this->err(422, 'country_code, province_id and name are required.');
        }
        if (!in_array($type, self::HOSPITAL_TYPES, true)) {
            return $this->err(422, 'Invalid hospital_type.', ['allowed' => self::HOSPITAL_TYPES]);
        }
        $province = DB::table('ref_provinces')->where('id', $provinceId)->whereNull('deleted_at')->first();
        if (!$province || $province->country_code !== $country) {
            return $this->err(422, 'Province invalid for country.');
        }
        if ($districtId) {
            $district = DB::table('ref_districts')->where('id', $districtId)->whereNull('deleted_at')->first();
            if (!$district || (int) $district->province_id !== $provinceId) {
                return $this->err(422, 'District invalid for province.');
            }
        }
        $code = isset($data['code']) && $data['code'] !== '' ? trim((string) $data['code']) : $this->slug($name);
        try {
            if (DB::table('ref_hospitals')->where('country_code', $country)->where('code', $code)->whereNull('deleted_at')->exists()) {
                return $this->err(409, 'Hospital code exists for country.');
            }
            $now = Carbon::now();
            $id = DB::table('ref_hospitals')->insertGetId([
                'country_code'       => $country,
                'province_id'        => $provinceId,
                'district_id'        => $districtId,
                'code'               => $code,
                'name'               => $name,
                'hospital_type'      => $type,
                'is_national_level'  => (int) (bool) ($data['is_national_level'] ?? false),
                'is_active'          => (int) (bool) ($data['is_active'] ?? true),
                'display_order'      => (int) ($data['display_order'] ?? 999),
                'latitude'           => $data['latitude'] ?? null,
                'longitude'          => $data['longitude'] ?? null,
                'phone'              => $data['phone'] ?? null,
                'address'            => $data['address'] ?? null,
                'metadata_json'      => isset($data['metadata_json']) ? json_encode($data['metadata_json']) : null,
                'created_by_user_id' => $admin,
                'updated_by_user_id' => $admin,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $row = DB::table('ref_hospitals')->where('id', $id)->first();
            return $this->ok($this->castHospital($row), 'Hospital created.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'storeHospital');
        }
    }

    public function updateHospital(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_hospitals')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'Hospital not found.');
            }
            $data = $request->all();
            $patch = ['updated_at' => Carbon::now(), 'updated_by_user_id' => $admin];
            foreach (['code', 'name', 'display_order', 'latitude', 'longitude', 'phone', 'address'] as $f) {
                if (array_key_exists($f, $data)) {
                    $patch[$f] = $data[$f];
                }
            }
            if (array_key_exists('hospital_type', $data)) {
                $t = strtoupper((string) $data['hospital_type']);
                if (!in_array($t, self::HOSPITAL_TYPES, true)) {
                    return $this->err(422, 'Invalid hospital_type.', ['allowed' => self::HOSPITAL_TYPES]);
                }
                $patch['hospital_type'] = $t;
            }
            foreach (['province_id', 'district_id'] as $f) {
                if (array_key_exists($f, $data)) {
                    $patch[$f] = $data[$f] === null ? null : (int) $data[$f];
                }
            }
            foreach (['is_national_level', 'is_active'] as $f) {
                if (array_key_exists($f, $data)) {
                    $patch[$f] = (int) (bool) $data[$f];
                }
            }
            if (array_key_exists('metadata_json', $data)) {
                $patch['metadata_json'] = $data['metadata_json'] === null ? null : json_encode($data['metadata_json']);
            }
            DB::table('ref_hospitals')->where('id', $row->id)->update($patch);
            $fresh = DB::table('ref_hospitals')->where('id', $row->id)->first();
            return $this->ok($this->castHospital($fresh), 'Hospital updated.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'updateHospital');
        }
    }

    public function destroyHospital(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin($request);
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_hospitals')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'Hospital not found.');
            }
            DB::table('ref_hospitals')->where('id', $row->id)->delete();
            return $this->ok(['id' => $row->id, 'deleted' => true], 'Hospital deleted.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'destroyHospital');
        }
    }

    /* ═══════════════════════════════════════════════════════════════
       INTERNAL — bundle assembly + payload normalisation
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Build the legacy window.POE_MAIN bundle for a country, preserving
     * the exact key order and types the rest of the app relies on.
     *
     * @return array<string,mixed>|null
     */
    private function assembleBundle(string $country): ?array
    {
        // metadata — table may not exist on minimal deployments; fail gracefully
        $metaMap = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('ref_geo_metadata')) {
            $metaRows = DB::table('ref_geo_metadata')
                ->where('country_code', $country)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();
            foreach ($metaRows as $mr) {
                $metaMap[$mr->meta_key] = json_decode((string) $mr->meta_value, true);
            }
        }
        $metadataOrder = [
            'dataset_name', 'schema_version', 'created_from_user_supplied_text_on',
            'countries', 'country_entry_counts', 'primary_filter_fields',
            'cross_country_mapping_note', 'data_quality_notes',
        ];
        $metadata = [];
        foreach ($metadataOrder as $k) {
            if (array_key_exists($k, $metaMap)) {
                $metadata[$k] = $metaMap[$k];
            }
        }
        foreach ($metaMap as $k => $v) {
            if (!array_key_exists($k, $metadata)) {
                $metadata[$k] = $v;
            }
        }

        // traveler_notes — table may not exist on minimal deployments
        $noteRows = \Illuminate\Support\Facades\Schema::hasTable('ref_traveler_notes')
            ? DB::table('ref_traveler_notes')
                ->where('country_code', $country)
                ->where('is_active', 1)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get()
            : collect();
        $travelerNotes = [];
        foreach ($noteRows as $nr) {
            $recommended = json_decode((string) $nr->recommended_poes_json, true) ?: [];
            if ($nr->note_type === 'MULTI') {
                $travelerNotes[$nr->note_key] = [
                    'recommended_poes' => $recommended,
                    'note'             => (string) $nr->note_text,
                ];
            } else {
                $travelerNotes[$nr->note_key] = [
                    'recommended_poe' => $recommended[0] ?? '',
                    'note'            => (string) $nr->note_text,
                ];
            }
        }

        // administrative_groups — from provinces + districts
        $provinces = DB::table('ref_provinces')
            ->where('country_code', $country)
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
        $administrativeGroups = [];
        foreach ($provinces as $p) {
            $districts = DB::table('ref_districts')
                ->where('country_code', $country)
                ->where('province_id', $p->id)
                ->whereNull('deleted_at')
                ->where('is_active', 1)
                ->orderBy('display_order')
                ->orderBy('id')
                ->pluck('name')
                ->all();
            $administrativeGroups[] = [
                'country'            => $country,
                'admin_level_1'      => (string) $p->name,
                'admin_level_1_type' => (string) $p->admin_level_1_type,
                'districts'          => $districts,
            ];
        }

        // poes — from ref_poes.payload (byte-preserved)
        $poeRows = DB::table('ref_poes')
            ->where('country_code', $country)
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();
        $poes = [];
        foreach ($poeRows as $pr) {
            $payload = $pr->payload ? json_decode((string) $pr->payload, true) : null;
            if (!is_array($payload)) {
                continue;
            }
            $poes[] = $this->buildPayload($payload);
        }

        return [
            'metadata'              => $metadata,
            'traveler_notes'        => $travelerNotes,
            'administrative_groups' => $administrativeGroups,
            'poes'                  => $poes,
        ];
    }

    /**
     * Force POE payload key order to match legacy POEs.js shape.
     * Missing keys are emitted as null (never undefined / missing).
     */
    private function buildPayload(array $src): array
    {
        $out = [];
        foreach (self::POE_PAYLOAD_KEY_ORDER as $k) {
            if (array_key_exists($k, $src)) {
                $out[$k] = $src[$k];
            } else {
                $out[$k] = in_array($k, ['is_major_entry', 'is_recommended_osbp', 'is_national_level'], true)
                    ? false
                    : null;
            }
        }
        // Normalise booleans — payload booleans must be true/false, not 0/1.
        foreach (['is_major_entry', 'is_recommended_osbp', 'is_national_level'] as $b) {
            $out[$b] = (bool) $out[$b];
        }
        return $out;
    }

    private function rebuildPoePayloadsForProvince(int $provinceId): void
    {
        $rows = DB::table('ref_poes')->where('province_id', $provinceId)->get();
        foreach ($rows as $r) {
            $this->rebuildOnePoePayload($r);
        }
    }

    private function rebuildPoePayloadsForDistrict(int $districtId): void
    {
        $rows = DB::table('ref_poes')->where('district_id', $districtId)->get();
        foreach ($rows as $r) {
            $this->rebuildOnePoePayload($r);
        }
    }

    private function rebuildOnePoePayload(object $r): void
    {
        $province = DB::table('ref_provinces')->where('id', $r->province_id)->first();
        $district = DB::table('ref_districts')->where('id', $r->district_id)->first();
        if (!$province || !$district) {
            return;
        }
        $existing = $r->payload ? (json_decode((string) $r->payload, true) ?: []) : [];
        $payload = $this->buildPayload(array_merge($existing, [
            'province'                   => $province->name,
            'admin_level_1'              => $province->name,
            'admin_level_1_type'         => $province->admin_level_1_type,
            'district'                   => $district->name,
            'district_raw'               => preg_replace('/\s+District\s*$/u', '', $district->name),
            'regional_cluster_or_rpheoc' => $province->name,
            'source_province_group'      => $province->name,
        ]));
        DB::table('ref_poes')->where('id', $r->id)->update([
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => Carbon::now(),
        ]);
    }

    private function bumpVersion(string $country): void
    {
        DB::table('ref_geo_version')->updateOrInsert(
            ['country_code' => $country],
            [
                'version'       => DB::raw('COALESCE(version,0) + 1'),
                'etag'          => null,
                'last_built_at' => Carbon::now(),
                'updated_at'    => Carbon::now(),
                'created_at'    => Carbon::now(),
            ]
        );
    }

    private function etagMatches(string $ifNoneMatch, string $etag): bool
    {
        $strip = fn ($s) => ltrim(trim($s, "\" \t"), 'W/');
        return $strip($ifNoneMatch) === $strip($etag);
    }

    /**
     * Deterministic external_id generator matching the legacy POEs.js
     * pattern (TENANT_ISO2-PROV3-DIST3-NAME3-NNN). Falls back on a random
     * suffix only if the deterministic 3-digit sequence collides 999 times —
     * which is impossible in practice for a single country.
     *
     * 2026-05-20: prefix sourced from config('country.iso2'). Previously
     * hardcoded to 'ZM-' (Zambia residue from upstream codebase). Must
     * stay in sync with Admin\Geo\PoesController::nextExternalId so both
     * write paths produce identical ids.
     */
    private function nextExternalId(string $country, string $province, string $district, string $name): string
    {
        $seg = function (string $s): string {
            // Strip whitespace + hyphens + punctuation, take the first three ASCII letters.
            $clean = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $s));
            $clean = substr($clean, 0, 3);
            return $clean !== '' ? $clean : 'XXX';
        };
        $iso2   = strtoupper((string) (config('country.iso2') ?: 'UG'));
        $prefix = $iso2 . '-' . $seg($province) . '-' . $seg($district) . '-' . $seg($name) . '-';
        for ($n = 1; $n <= 999; $n++) {
            $candidate = $prefix . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
            if (!DB::table('ref_poes')->where('external_id', $candidate)->exists()) {
                return $candidate;
            }
        }
        // Vanishingly rare fallback — random 3-hex so insert can still succeed.
        return $prefix . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
    }

    /**
     * transport_mode is derived from poe_type.  Explicit user input is
     * only honoured for poe_type='other'; every other type has a fixed
     * mapping so the bundle shape can't drift.
     */
    private function deriveTransportMode(string $poeType, mixed $requested = null): string
    {
        if (array_key_exists($poeType, self::POE_TYPE_TO_MODE)) {
            return self::POE_TYPE_TO_MODE[$poeType];
        }
        // poe_type === 'other' — honour the caller's hint, defaulting to 'other'.
        $cand = is_string($requested) && $requested !== '' ? $requested : 'other';
        return in_array($cand, self::TRANSPORT_MODES, true) ? $cand : 'other';
    }

    /**
     * Collapse blank-ish input ("" / null / whitespace) to null so the
     * payload's `border_country` keeps its legacy semantics: present as
     * a country string, or literally null when the POE has none.
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null) return null;
        $v = trim((string) $value);
        return $v === '' ? null : $v;
    }

    /* ═══════════════════════════════════════════════════════════════
       ROW CASTERS — normalise types for mobile
    ═══════════════════════════════════════════════════════════════ */

    private function castCountry(object $r): array
    {
        return [
            'id'                 => (int) $r->id,
            'country_code'       => (string) $r->country_code,
            'iso_alpha2'         => $r->iso_alpha2,
            'iso_alpha3'         => $r->iso_alpha3,
            'name'               => (string) $r->name,
            'is_active'          => (bool) $r->is_active,
            'display_order'      => (int) $r->display_order,
            'metadata_json'      => $r->metadata_json ? json_decode((string) $r->metadata_json, true) : null,
            'created_at'         => $r->created_at,
            'updated_at'         => $r->updated_at,
        ];
    }

    private function castProvince(object $r): array
    {
        return [
            'id'                 => (int) $r->id,
            'country_code'       => (string) $r->country_code,
            'code'               => (string) $r->code,
            'name'               => (string) $r->name,
            'admin_level_1_type' => (string) $r->admin_level_1_type,
            'is_active'          => (bool) $r->is_active,
            'display_order'      => (int) $r->display_order,
            'metadata_json'      => $r->metadata_json ? json_decode((string) $r->metadata_json, true) : null,
            'created_at'         => $r->created_at,
            'updated_at'         => $r->updated_at,
        ];
    }

    private function castDistrict(object $r): array
    {
        return [
            'id'             => (int) $r->id,
            'country_code'   => (string) $r->country_code,
            'province_id'    => (int) $r->province_id,
            'code'           => (string) $r->code,
            'name'           => (string) $r->name,
            'name_raw'       => $r->name_raw,
            'is_active'      => (bool) $r->is_active,
            'display_order'  => (int) $r->display_order,
            'metadata_json'  => $r->metadata_json ? json_decode((string) $r->metadata_json, true) : null,
            'created_at'     => $r->created_at,
            'updated_at'     => $r->updated_at,
        ];
    }

    private function castPoe(object $r): array
    {
        // MySQL's JSON column normalises object keys alphabetically on read,
        // so we re-apply the legacy key order before returning.  This keeps
        // single-row responses shape-compatible with the bundle endpoint.
        $payloadRaw = $r->payload ? json_decode((string) $r->payload, true) : null;
        $payload = is_array($payloadRaw) ? $this->buildPayload($payloadRaw) : null;
        return [
            'id'                  => (int) $r->id,
            'external_id'         => $r->external_id,
            'country_code'        => (string) $r->country_code,
            'province_id'         => $r->province_id !== null ? (int) $r->province_id : null,
            'district_id'         => $r->district_id !== null ? (int) $r->district_id : null,
            'poe_code'            => (string) $r->poe_code,
            'poe_name'            => (string) $r->poe_name,
            'admin_level_1'       => $r->admin_level_1,
            'admin_level_1_type'  => $r->admin_level_1_type,
            'district'            => $r->district,
            'poe_type'            => (string) $r->poe_type,
            'transport_mode'      => (string) $r->transport_mode,
            'regional_cluster'    => $r->regional_cluster,
            'is_national_level'   => (bool) $r->is_national_level,
            'is_major_entry'      => (bool) $r->is_major_entry,
            'is_recommended_osbp' => (bool) $r->is_recommended_osbp,
            'border_country'      => $r->border_country,
            'latitude'            => $r->latitude,
            'longitude'           => $r->longitude,
            'gazette_source'      => $r->gazette_source,
            'is_active'           => (bool) $r->is_active,
            'display_order'       => (int) $r->display_order,
            'payload'             => $payload,
            'created_at'          => $r->created_at,
            'updated_at'          => $r->updated_at,
        ];
    }

    private function castHospital(object $r): array
    {
        return [
            'id'                 => (int) $r->id,
            'country_code'       => (string) $r->country_code,
            'province_id'        => (int) $r->province_id,
            'district_id'        => $r->district_id !== null ? (int) $r->district_id : null,
            'code'               => (string) $r->code,
            'name'               => (string) $r->name,
            'hospital_type'      => (string) $r->hospital_type,
            'is_national_level'  => (bool) $r->is_national_level,
            'is_active'          => (bool) $r->is_active,
            'display_order'      => (int) $r->display_order,
            'latitude'           => $r->latitude,
            'longitude'          => $r->longitude,
            'phone'              => $r->phone,
            'address'            => $r->address,
            'metadata_json'      => $r->metadata_json ? json_decode((string) $r->metadata_json, true) : null,
            'created_at'         => $r->created_at,
            'updated_at'         => $r->updated_at,
        ];
    }

    /* ═══════════════════════════════════════════════════════════════
       AUTH GUARD — user_id query param → role check
    ═══════════════════════════════════════════════════════════════ */

    /**
     * Returns the admin user id on success, or a JsonResponse (422/403)
     * to short-circuit the caller. Matches the rest of the mobile API:
     * the client passes user_id in the query/body; role is verified here.
     */
    private function requireNationalAdmin(Request $request): int|JsonResponse
    {
        $uid = (int) ($request->input('user_id') ?? $request->query('user_id', 0));
        if ($uid <= 0) {
            return $this->err(422, 'user_id is required.');
        }
        $user = DB::table('users')
            ->where('id', $uid)
            ->where('is_active', 1)
            ->first();
        if (!$user) {
            return $this->err(403, 'User not found or inactive.');
        }
        $role = strtoupper((string) ($user->role_key ?? ''));
        if ($role !== 'NATIONAL_ADMIN') {
            return $this->err(403, 'NATIONAL_ADMIN role required for this operation.', ['role_key' => $role]);
        }
        return $uid;
    }

    private function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s);
        return trim($s, '-');
    }

    /* ═══════════════════════════════════════════════════════════════
       RESPONSE HELPERS
    ═══════════════════════════════════════════════════════════════ */

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => $data];
        if (!empty($meta)) {
            $body['meta'] = $meta;
        }
        return response()->json($body, 200);
    }

    private function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error' => $detail], $status);
    }

    private function serverError(Throwable $e, string $ctx): JsonResponse
    {
        Log::error("[GeoHierarchy][ERROR] {$ctx}", [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]);
        return response()->json(['success' => false, 'message' => "Server error: {$ctx}"], 500);
    }
}
