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
 * Admin · Geo · Districts CRUD.
 *
 * Web twin of GeoHierarchyController district endpoints. Mobile is never
 * touched. Writes the same `ref_districts` columns; cascades name changes
 * to ref_poes (district + payload) and ref_hospitals.
 *
 * district_raw is auto-stripped of " District" suffix to match the legacy
 * POEs.js convention (poe.district_raw is the bare town name).
 */
final class DistrictsController extends Controller
{
    /** Default tenant country (full name; matches ref_poes.country_code). */
    private static function defaultCountry(): string
    {
        return (string) (config('country.legacy_code') ?: 'Uganda');
    }

    public function index(Request $request)
    {
        return view('admin.geo.districts.index', [
            'page_title'    => 'Districts',
            'page_eyebrow'  => 'Geography · Districts',
            'page_subtitle' => 'Border-adjacent districts that anchor every Point of Entry.',
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        try {
            $country    = (string) $request->query('country', self::defaultCountry());
            $provinceId = (int) $request->query('province_id', 0);
            $status     = strtolower((string) $request->query('status', 'active'));
            $q          = trim((string) $request->query('q', ''));

            $scope = ScopeFilter::fromRequest($request);
            $query = DB::table('ref_districts')->where('country_code', $country);
            $query = ScopeFilter::applyToDistricts($query, $scope);
            if ($status === 'active')  { $query->whereNull('deleted_at'); }
            if ($status === 'retired') { $query->whereNotNull('deleted_at'); }
            if ($provinceId > 0)       { $query->where('province_id', $provinceId); }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('name_raw', 'like', $like));
            }

            $rows        = $query->orderBy('display_order')->orderBy('id')->get();
            $districtIds = $rows->pluck('id')->all();
            $poeCounts   = DB::table('ref_poes')->whereIn('district_id', $districtIds)->whereNull('deleted_at')
                ->groupBy('district_id')->selectRaw('district_id, COUNT(*) as c')->pluck('c', 'district_id');
            $provinces   = DB::table('ref_provinces')->whereIn('id', $rows->pluck('province_id'))->pluck('name', 'id');

            $countActive  = ScopeFilter::applyToDistricts(DB::table('ref_districts')->where('country_code', $country)->whereNull('deleted_at'), $scope)->count();
            $countRetired = ScopeFilter::applyToDistricts(DB::table('ref_districts')->where('country_code', $country)->whereNotNull('deleted_at'), $scope)->count();

            return $this->ok([
                'rows' => $rows->map(fn ($r) => [
                    'id'             => (int) $r->id,
                    'country_code'   => (string) $r->country_code,
                    'province_id'    => (int) $r->province_id,
                    'province_name'  => (string) ($provinces[$r->province_id] ?? '—'),
                    'code'           => (string) $r->code,
                    'name'           => (string) $r->name,
                    'name_raw'       => $r->name_raw,
                    'is_active'      => (bool) $r->is_active,
                    'is_retired'     => $r->deleted_at !== null,
                    'display_order'  => (int) $r->display_order,
                    'updated_at'     => $r->updated_at,
                    'poe_count'      => (int) ($poeCounts[$r->id] ?? 0),
                ])->all(),
                'total' => $rows->count(),
            ], 'Districts.', [
                'country' => $country,
                'tabs'    => ['active' => $countActive, 'retired' => $countRetired, 'all' => $countActive + $countRetired],
                'version' => $this->currentVersion($country),
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $row = DB::table('ref_districts')->where('id', $id)->first();
            if (!$row) { return $this->err(404, 'District not found.'); }
            return $this->ok($this->cast($row), 'District retrieved.');
        } catch (Throwable $e) { return $this->serverError($e, 'show'); }
    }

    public function meta(Request $request): JsonResponse
    {
        try {
            $country = (string) $request->query('country', self::defaultCountry());
            $provinces = DB::table('ref_provinces')->where('country_code', $country)->whereNull('deleted_at')
                ->orderBy('display_order')->orderBy('id')->get(['id','name','code'])
                ->map(fn ($p) => ['id'=>(int)$p->id,'name'=>(string)$p->name,'code'=>(string)$p->code])->all();
            return $this->ok([
                'country'   => $country,
                'provinces' => $provinces,
                'rules'     => [
                    'name_format'     => 'Full name e.g. "Chililabombwe District"; appears verbatim in ref_poes.district.',
                    'name_raw_format' => 'Bare stem e.g. "Chililabombwe"; auto-derived from name (strip " District").',
                    'code_format'     => 'Slug; auto-derived from name if blank.',
                ],
            ], 'Meta.');
        } catch (Throwable $e) { return $this->serverError($e, 'meta'); }
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
        $country    = trim((string) ($data['country_code'] ?? self::defaultCountry()));
        $provinceId = (int) ($data['province_id'] ?? 0);
        $name       = trim((string) ($data['name'] ?? ''));
        if ($country === '' || $provinceId <= 0 || $name === '') {
            return $this->err(422, 'country_code, province_id, and name are required.');
        }
        $province = DB::table('ref_provinces')->where('id', $provinceId)->whereNull('deleted_at')->first();
        if (!$province || $province->country_code !== $country) {
            return $this->err(422, 'Province invalid for country.');
        }
        $code    = isset($data['code']) && $data['code'] !== '' ? trim((string) $data['code']) : $this->slug($name);
        $nameRaw = (string) ($data['name_raw'] ?? preg_replace('/\s+District\s*$/u', '', $name));
        try {
            if (DB::table('ref_districts')->where('country_code', $country)
                ->where(fn ($w) => $w->where('code', $code)->orWhere('name', $name))
                ->whereNull('deleted_at')->exists()) {
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
                'created_by_user_id' => $admin,
                'updated_by_user_id' => $admin,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $this->bumpVersion($country);
            $row = DB::table('ref_districts')->where('id', $id)->first();
            return $this->ok($this->cast($row), 'District created.', ['version' => $this->currentVersion($country)]);
        } catch (Throwable $e) { return $this->serverError($e, 'store'); }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_districts')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) { return $this->err(404, 'District not found.'); }
            $data = $request->all();
            $patch = ['updated_at' => Carbon::now(), 'updated_by_user_id' => $admin];
            foreach (['code', 'name', 'name_raw', 'display_order'] as $f) {
                if (array_key_exists($f, $data)) { $patch[$f] = $data[$f]; }
            }
            if (array_key_exists('province_id', $data)) {
                $newPid = (int) $data['province_id'];
                $p = DB::table('ref_provinces')->where('id', $newPid)->whereNull('deleted_at')->first();
                if (!$p) { return $this->err(422, 'Province not found.'); }
                $patch['province_id'] = $newPid;
            }
            if (array_key_exists('is_active', $data)) { $patch['is_active'] = (int) (bool) $data['is_active']; }

            DB::table('ref_districts')->where('id', $row->id)->update($patch);

            // Cascade name change to ref_poes.district + payload.
            $newName = $patch['name'] ?? $row->name;
            if ($newName !== $row->name) {
                DB::table('ref_poes')->where('district_id', $row->id)->update(['district' => $newName]);
                $this->rebuildPayloadsForDistrict((int) $row->id);
            }
            $this->bumpVersion((string) $row->country_code);
            $fresh = DB::table('ref_districts')->where('id', $row->id)->first();
            return $this->ok($this->cast($fresh), 'District updated.', ['version' => $this->currentVersion((string) $row->country_code)]);
        } catch (Throwable $e) { return $this->serverError($e, 'update'); }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_districts')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) { return $this->err(404, 'District not found.'); }
            $children = DB::table('ref_poes')->where('district_id', $id)->whereNull('deleted_at')->count()
                      + DB::table('ref_hospitals')->where('district_id', $id)->whereNull('deleted_at')->count();
            if ($children > 0) {
                return $this->err(409, 'District has active PoEs / hospitals. Retire those first.', ['children' => $children]);
            }
            DB::table('ref_districts')->where('id', $row->id)->update([
                'deleted_at' => Carbon::now(), 'updated_by_user_id' => $admin, 'updated_at' => Carbon::now(),
            ]);
            $this->bumpVersion((string) $row->country_code);
            return $this->ok(['id' => (int) $row->id, 'soft_deleted' => true], 'District retired.', ['version' => $this->currentVersion((string) $row->country_code)]);
        } catch (Throwable $e) { return $this->serverError($e, 'destroy'); }
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_districts')->where('id', $id)->whereNotNull('deleted_at')->first();
            if (!$row) { return $this->err(404, 'District not found or already active.'); }
            DB::table('ref_districts')->where('id', $row->id)->update([
                'deleted_at' => null, 'updated_by_user_id' => $admin, 'updated_at' => Carbon::now(),
            ]);
            $this->bumpVersion((string) $row->country_code);
            $fresh = DB::table('ref_districts')->where('id', $row->id)->first();
            return $this->ok($this->cast($fresh), 'District restored.', ['version' => $this->currentVersion((string) $row->country_code)]);
        } catch (Throwable $e) { return $this->serverError($e, 'restore'); }
    }

    /* ── helpers ─────────────────────────────────────────────────────── */

    private function rebuildPayloadsForDistrict(int $districtId): void
    {
        $rows = DB::table('ref_poes')->where('district_id', $districtId)->get();
        foreach ($rows as $r) {
            $province = DB::table('ref_provinces')->where('id', $r->province_id)->first();
            $district = DB::table('ref_districts')->where('id', $r->district_id)->first();
            if (!$province || !$district) { continue; }
            $payload = $r->payload ? (json_decode((string) $r->payload, true) ?: []) : [];
            $payload = array_merge($payload, [
                'district'     => $district->name,
                'district_raw' => $district->name_raw ?? preg_replace('/\s+District\s*$/u', '', $district->name),
            ]);
            $payload = $this->reorderPoePayload($payload);
            DB::table('ref_poes')->where('id', $r->id)->update([
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
            'id'            => (int) $r->id,
            'country_code'  => (string) $r->country_code,
            'province_id'   => (int) $r->province_id,
            'code'          => (string) $r->code,
            'name'          => (string) $r->name,
            'name_raw'      => $r->name_raw,
            'is_active'     => (bool) $r->is_active,
            'is_retired'    => $r->deleted_at !== null,
            'display_order' => (int) $r->display_order,
            'created_at'    => $r->created_at,
            'updated_at'    => $r->updated_at,
            'deleted_at'    => $r->deleted_at,
        ];
    }

    private function currentVersion(string $country): int
    { return (int) (DB::table('ref_geo_version')->where('country_code', $country)->value('version') ?? 0); }
    private function bumpVersion(string $country): void
    { DB::table('ref_geo_version')->updateOrInsert(['country_code' => $country], [
        'version' => DB::raw('COALESCE(version,0) + 1'), 'etag' => null,
        'last_built_at' => Carbon::now(), 'updated_at' => Carbon::now(), 'created_at' => Carbon::now(),
    ]); }
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
    { Log::error("[Admin\\Geo\\Districts][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
