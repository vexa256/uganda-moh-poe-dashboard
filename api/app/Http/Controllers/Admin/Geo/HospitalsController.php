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
 * Admin · Geo · Hospitals CRUD.
 *
 * Greenfield surface — `ref_hospitals` is currently empty (0 rows seeded).
 * No mobile dependents yet; safe to populate progressively as the lab /
 * sample integration approaches.
 */
final class HospitalsController extends Controller
{
    /** Default tenant country (full name; matches ref_poes.country_code). */
    private static function defaultCountry(): string
    {
        return (string) (config('country.legacy_code') ?: 'Uganda');
    }
    private const HOSPITAL_TYPES = ['TEACHING', 'GENERAL', 'DISTRICT', 'RURAL', 'CLINIC', 'PRIVATE', 'MILITARY', 'OTHER'];

    public function index(Request $request)
    {
        return view('admin.geo.hospitals.index', [
            'page_title'    => 'Hospitals',
            'page_eyebrow'  => 'Geography · Hospitals',
            'page_subtitle' => 'Referral hospitals supporting the Points of Entry.',
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        try {
            $country    = (string) $request->query('country', self::defaultCountry());
            $provinceId = (int) $request->query('province_id', 0);
            $districtId = (int) $request->query('district_id', 0);
            $type       = trim((string) $request->query('hospital_type', ''));
            $status     = strtolower((string) $request->query('status', 'active'));
            $q          = trim((string) $request->query('q', ''));

            $scope = ScopeFilter::fromRequest($request);
            $query = DB::table('ref_hospitals')->where('country_code', $country);
            $query = ScopeFilter::applyToHospitals($query, $scope);
            if ($status === 'active')  { $query->whereNull('deleted_at'); }
            if ($status === 'retired') { $query->whereNotNull('deleted_at'); }
            if ($provinceId > 0)       { $query->where('province_id', $provinceId); }
            if ($districtId > 0)       { $query->where('district_id', $districtId); }
            if ($type !== '')          { $query->where('hospital_type', strtoupper($type)); }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('phone', 'like', $like));
            }

            $rows = $query->orderBy('display_order')->orderBy('id')->get();
            $provinces = DB::table('ref_provinces')->whereIn('id', $rows->pluck('province_id'))->pluck('name', 'id');
            $districts = DB::table('ref_districts')->whereIn('id', $rows->pluck('district_id')->filter())->pluck('name', 'id');

            $countActive  = ScopeFilter::applyToHospitals(DB::table('ref_hospitals')->where('country_code', $country)->whereNull('deleted_at'), $scope)->count();
            $countRetired = ScopeFilter::applyToHospitals(DB::table('ref_hospitals')->where('country_code', $country)->whereNotNull('deleted_at'), $scope)->count();

            return $this->ok([
                'rows' => $rows->map(fn ($r) => [
                    'id'                => (int) $r->id,
                    'country_code'      => (string) $r->country_code,
                    'province_id'       => (int) $r->province_id,
                    'province_name'     => (string) ($provinces[$r->province_id] ?? '—'),
                    'district_id'       => $r->district_id !== null ? (int) $r->district_id : null,
                    'district_name'     => $r->district_id ? (string) ($districts[$r->district_id] ?? '—') : null,
                    'code'              => (string) $r->code,
                    'name'              => (string) $r->name,
                    'hospital_type'     => (string) $r->hospital_type,
                    'is_national_level' => (bool) $r->is_national_level,
                    'is_active'         => (bool) $r->is_active,
                    'is_retired'        => $r->deleted_at !== null,
                    'phone'             => $r->phone,
                    'address'           => $r->address,
                    'display_order'     => (int) $r->display_order,
                    'updated_at'        => $r->updated_at,
                ])->all(),
                'total' => $rows->count(),
            ], 'Hospitals.', [
                'country' => $country,
                'tabs'    => ['active' => $countActive, 'retired' => $countRetired, 'all' => $countActive + $countRetired],
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $row = DB::table('ref_hospitals')->where('id', $id)->first();
            if (!$row) { return $this->err(404, 'Hospital not found.'); }
            return $this->ok($this->cast($row), 'Hospital retrieved.');
        } catch (Throwable $e) { return $this->serverError($e, 'show'); }
    }

    public function meta(Request $request): JsonResponse
    {
        try {
            $country = (string) $request->query('country', self::defaultCountry());
            $provinces = DB::table('ref_provinces')->where('country_code', $country)->whereNull('deleted_at')
                ->orderBy('display_order')->orderBy('id')->get(['id','name'])
                ->map(fn ($p) => ['id'=>(int)$p->id,'name'=>(string)$p->name])->all();
            $districts = DB::table('ref_districts')->where('country_code', $country)->whereNull('deleted_at')
                ->orderBy('display_order')->orderBy('id')->get(['id','province_id','name'])
                ->map(fn ($d) => ['id'=>(int)$d->id,'province_id'=>(int)$d->province_id,'name'=>(string)$d->name])->all();
            return $this->ok([
                'country'        => $country,
                'provinces'      => $provinces,
                'districts'      => $districts,
                'hospital_types' => self::HOSPITAL_TYPES,
            ], 'Meta.');
        } catch (Throwable $e) { return $this->serverError($e, 'meta'); }
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        $data = $request->all();
        $country    = trim((string) ($data['country_code'] ?? self::defaultCountry()));
        $provinceId = (int) ($data['province_id'] ?? 0);
        $districtId = isset($data['district_id']) && $data['district_id'] !== '' ? (int) $data['district_id'] : null;
        $name       = trim((string) ($data['name'] ?? ''));
        $type       = strtoupper((string) ($data['hospital_type'] ?? 'GENERAL'));
        if ($country === '' || $provinceId <= 0 || $name === '') {
            return $this->err(422, 'country_code, province_id, name are required.');
        }
        if (!in_array($type, self::HOSPITAL_TYPES, true)) {
            return $this->err(422, 'Invalid hospital_type.', ['allowed' => self::HOSPITAL_TYPES]);
        }
        $province = DB::table('ref_provinces')->where('id', $provinceId)->whereNull('deleted_at')->first();
        if (!$province || $province->country_code !== $country) { return $this->err(422, 'Province invalid.'); }
        if ($districtId) {
            $district = DB::table('ref_districts')->where('id', $districtId)->whereNull('deleted_at')->first();
            if (!$district || (int) $district->province_id !== $provinceId) { return $this->err(422, 'District invalid for province.'); }
        }
        $code = isset($data['code']) && $data['code'] !== '' ? trim((string) $data['code']) : $this->slug($name);
        try {
            if (DB::table('ref_hospitals')->where('country_code', $country)->where('code', $code)->whereNull('deleted_at')->exists()) {
                return $this->err(409, 'Hospital code exists.');
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
                'latitude'           => $data['latitude']  ?? null,
                'longitude'          => $data['longitude'] ?? null,
                'phone'              => $data['phone']     ?? null,
                'address'            => $data['address']   ?? null,
                'created_by_user_id' => $admin,
                'updated_by_user_id' => $admin,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $row = DB::table('ref_hospitals')->where('id', $id)->first();
            return $this->ok($this->cast($row), 'Hospital created.');
        } catch (Throwable $e) { return $this->serverError($e, 'store'); }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_hospitals')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) { return $this->err(404, 'Hospital not found.'); }
            $data = $request->all();
            $patch = ['updated_at' => Carbon::now(), 'updated_by_user_id' => $admin];
            foreach (['code','name','display_order','latitude','longitude','phone','address'] as $f) {
                if (array_key_exists($f, $data)) { $patch[$f] = $data[$f]; }
            }
            if (array_key_exists('hospital_type', $data)) {
                $t = strtoupper((string) $data['hospital_type']);
                if (!in_array($t, self::HOSPITAL_TYPES, true)) {
                    return $this->err(422, 'Invalid hospital_type.', ['allowed' => self::HOSPITAL_TYPES]);
                }
                $patch['hospital_type'] = $t;
            }
            foreach (['province_id', 'district_id'] as $f) {
                if (array_key_exists($f, $data)) { $patch[$f] = $data[$f] === null || $data[$f] === '' ? null : (int) $data[$f]; }
            }
            foreach (['is_national_level', 'is_active'] as $f) {
                if (array_key_exists($f, $data)) { $patch[$f] = (int) (bool) $data[$f]; }
            }
            DB::table('ref_hospitals')->where('id', $row->id)->update($patch);
            $fresh = DB::table('ref_hospitals')->where('id', $row->id)->first();
            return $this->ok($this->cast($fresh), 'Hospital updated.');
        } catch (Throwable $e) { return $this->serverError($e, 'update'); }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_hospitals')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) { return $this->err(404, 'Hospital not found.'); }
            DB::table('ref_hospitals')->where('id', $row->id)->update([
                'deleted_at' => Carbon::now(), 'updated_by_user_id' => $admin, 'updated_at' => Carbon::now(),
            ]);
            return $this->ok(['id' => (int) $row->id, 'soft_deleted' => true], 'Hospital retired.');
        } catch (Throwable $e) { return $this->serverError($e, 'destroy'); }
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_hospitals')->where('id', $id)->whereNotNull('deleted_at')->first();
            if (!$row) { return $this->err(404, 'Hospital not found or already active.'); }
            DB::table('ref_hospitals')->where('id', $row->id)->update([
                'deleted_at' => null, 'updated_by_user_id' => $admin, 'updated_at' => Carbon::now(),
            ]);
            $fresh = DB::table('ref_hospitals')->where('id', $row->id)->first();
            return $this->ok($this->cast($fresh), 'Hospital restored.');
        } catch (Throwable $e) { return $this->serverError($e, 'restore'); }
    }

    /* ── helpers ─────────────────────────────────────────────────────── */

    private function cast(object $r): array
    {
        return [
            'id'                => (int) $r->id,
            'country_code'      => (string) $r->country_code,
            'province_id'       => (int) $r->province_id,
            'district_id'       => $r->district_id !== null ? (int) $r->district_id : null,
            'code'              => (string) $r->code,
            'name'              => (string) $r->name,
            'hospital_type'     => (string) $r->hospital_type,
            'is_national_level' => (bool) $r->is_national_level,
            'is_active'         => (bool) $r->is_active,
            'is_retired'        => $r->deleted_at !== null,
            'display_order'     => (int) $r->display_order,
            'latitude'          => $r->latitude,
            'longitude'         => $r->longitude,
            'phone'             => $r->phone,
            'address'           => $r->address,
            'created_at'        => $r->created_at,
            'updated_at'        => $r->updated_at,
            'deleted_at'        => $r->deleted_at,
        ];
    }
    private function slug(string $s): string
    { $s = strtolower(trim($s)); return trim((string) preg_replace('/[^a-z0-9]+/u', '-', $s), '-'); }

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
    { Log::error("[Admin\\Geo\\Hospitals][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
