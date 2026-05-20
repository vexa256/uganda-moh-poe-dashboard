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
 * ════════════════════════════════════════════════════════════════════════════
 * Admin · Geo · Points of Entry (PoE) Registry CRUD
 * ════════════════════════════════════════════════════════════════════════════
 *
 * The web admin counterpart to App\Http\Controllers\GeoHierarchyController
 * (mobile-facing, /api/poes/bundle + /api/geo/poes/*).  All mobile contracts
 * are preserved by writing to the same `ref_poes` columns and producing the
 * same legacy POEs.js payload shape (byte-equivalent to
 * src/POEs.js.hardcoded.bak — 20 keys per POE in fixed order).
 *
 * Mobile is NEVER touched.  This controller mirrors the mobile shape rules
 * via the constants at the top of the file — these MUST stay in sync with
 * GeoHierarchyController.  A divergence breaks the byte-equivalence contract
 * the mobile loader (src/POEs.js) depends on.
 *
 * Smart admin-only endpoints (no mobile equivalent):
 *   GET  /admin/geo/poes/data         — paginated list with filters/search
 *   GET  /admin/geo/poes/meta         — dropdown bundle (provinces, districts, neighbours, presets)
 *   POST /admin/geo/poes/suggest      — auto-derive fields from minimal input
 *   POST /admin/geo/poes/dupe-check   — fuzzy duplicate detection by name
 *   GET  /admin/geo/poes/version      — current ref_geo_version (toast confirmation)
 *
 * Mirrored CRUD (writes session-auth gated, identical column writes to mobile):
 *   GET    /admin/geo/poes/{id}                — single PoE detail
 *   POST   /admin/geo/poes                     — create
 *   PATCH  /admin/geo/poes/{id}                — update
 *   DELETE /admin/geo/poes/{id}                — soft-delete
 *   POST   /admin/geo/poes/{id}/restore        — restore soft-deleted
 *
 * Auth posture: read endpoints are open during the rebuild preview phase.
 * Writes require `auth()->check()` and the user's role_key must be
 * NATIONAL_ADMIN — same gate as the mobile-facing controller.  When Phase 0
 * lands the full RoleGate middleware, drop the inline guard.
 * ════════════════════════════════════════════════════════════════════════════
 */
final class PoesController extends Controller
{
    /* ════════════════════════════════════════════════════════════════════
       SHAPE CONSTANTS · MUST STAY IN SYNC WITH GeoHierarchyController
       ════════════════════════════════════════════════════════════════════
       Any change here without the mirror change there silently breaks the
       byte-equivalent mobile bundle.  Cover this with a parity test when
       the geo CRUD landed in tests/integration/poes_parity.test.cjs is
       extended to cover the admin-write path. */

    /** Default tenant country (full name; matches ref_poes.country_code). */
    private static function defaultCountry(): string
    {
        return (string) (config('country.legacy_code') ?: 'Uganda');
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

    private const POE_TYPE_TO_MODE = [
        'airport'      => 'air',
        'airstrip'     => 'air',
        'port'         => 'water',
        'island_entry' => 'water',
        'land_border'  => 'land',
        'rail'         => 'rail',
    ];

    private const DEFAULT_SOURCE_ORIGIN = 'Uganda Directorate of Citizenship and Immigration Control';
    private const AIRPORT_SOURCE_ORIGIN = 'Uganda Civil Aviation Authority (UCAA) - Designated International Airports';
    private const DEFAULT_SOURCE_URL    = 'https://www.immigration.go.ug/';
    private const AIRPORT_SOURCE_URL    = 'https://www.caa.co.ug/';

    /** Uganda border neighbours. */
    private const NEIGHBOURS = ['Kenya', 'South Sudan', 'DRC', 'Rwanda', 'Tanzania'];

    /** Known Uganda One-Stop Border Posts. */
    private const KNOWN_OSBPS = ['Busia', 'Malaba', 'Mutukula', 'Mirama Hills', 'Elegu'];

    /* ════════════════════════════════════════════════════════════════════
       ENTRY POINT · renders the admin view
       ════════════════════════════════════════════════════════════════════ */

    public function index(Request $request)
    {
        // The view never queries the DB directly — it pulls data via the
        // JSON endpoints below (data, meta, version).  This keeps the page
        // shell cheap and the data refresh cycle independent of page render.
        return view('admin.geo.poes.index', [
            'page_title'   => 'Designated Points of Entry',
            'page_eyebrow' => 'PoEs · Annex-1A',
            'page_subtitle'=> "Manage Uganda's gazetted ports of entry. Mobile clients refresh on save.",
        ]);
    }

    /* ════════════════════════════════════════════════════════════════════
       READ · paginated list with filters + search + sort
       ════════════════════════════════════════════════════════════════════ */

    public function data(Request $request): JsonResponse
    {
        try {
            $country     = (string) $request->query('country', self::defaultCountry());
            $provinceId  = (int) $request->query('province_id', 0);
            $districtId  = (int) $request->query('district_id', 0);
            $poeType     = trim((string) $request->query('poe_type', ''));
            $transport   = trim((string) $request->query('transport_mode', ''));
            $border      = trim((string) $request->query('border_country', ''));
            $search      = trim((string) $request->query('q', ''));
            $statusTab   = strtolower((string) $request->query('status', 'active')); // active | retired | all
            $perPage     = max(10, min(200, (int) $request->query('per_page', 50)));
            $page        = max(1, (int) $request->query('page', 1));
            $sort        = trim((string) $request->query('sort', 'display_order'));
            $dir         = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

            $allowedSort = ['display_order', 'poe_name', 'poe_type', 'transport_mode', 'border_country', 'updated_at', 'created_at'];
            if (!in_array($sort, $allowedSort, true)) { $sort = 'display_order'; }

            // Foundational scope rule: NATIONAL_ADMIN sees all; others limited
            // to their PHEOC/district/PoE per ResolveScope middleware.
            $scope = ScopeFilter::fromRequest($request);

            $q = DB::table('ref_poes')->where('country_code', $country);
            $q = ScopeFilter::applyToPoes($q, $scope);
            if ($statusTab === 'active')  { $q->whereNull('deleted_at'); }
            if ($statusTab === 'retired') { $q->whereNotNull('deleted_at'); }
            if ($provinceId > 0)          { $q->where('province_id', $provinceId); }
            if ($districtId > 0)          { $q->where('district_id', $districtId); }
            if ($poeType !== '')          { $q->where('poe_type', $poeType); }
            if ($transport !== '')        { $q->where('transport_mode', $transport); }
            if ($border !== '')           { $q->where('border_country', $border); }
            if ($search !== '') {
                $like = '%' . $search . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('poe_name', 'like', $like)
                      ->orWhere('poe_code', 'like', $like)
                      ->orWhere('external_id', 'like', $like)
                      ->orWhere('district', 'like', $like)
                      ->orWhere('admin_level_1', 'like', $like)
                      ->orWhere('border_country', 'like', $like);
                });
            }

            $total = (clone $q)->count();
            $rows  = $q->orderBy($sort, $dir)->orderBy('id', 'asc')
                       ->forPage($page, $perPage)->get();

            // Tab counts ALSO scope-filtered so the badges reflect what the
            // user can actually see, not the global count.
            $countActive  = ScopeFilter::applyToPoes(
                DB::table('ref_poes')->where('country_code', $country)->whereNull('deleted_at'),
                $scope
            )->count();
            $countRetired = ScopeFilter::applyToPoes(
                DB::table('ref_poes')->where('country_code', $country)->whereNotNull('deleted_at'),
                $scope
            )->count();

            // Envelope shape contract — matches PoeContacts/Capacity/Status:
            // data.rows, data.total + meta.{tabs,version,page,per_page,sort,dir,country}
            // The blade view reads `j.data.rows` + `j.meta.tabs` + `j.meta.version`;
            // prior to 2026-05-20 the controller put tabs/version under data.* and
            // used `items` (not `rows`) — the registry page rendered empty + threw
            // `Cannot read 'tabs' of undefined` on j.meta access.
            return $this->ok([
                'rows'  => $rows->map(fn ($r) => $this->castListRow($r))->all(),
                'total' => $total,
            ], 'PoEs.', [
                'tabs'     => ['active' => $countActive, 'retired' => $countRetired, 'all' => $countActive + $countRetired],
                'version'  => $this->currentVersion($country),
                'country'  => $country,
                'page'     => $page,
                'per_page' => $perPage,
                'sort'     => $sort,
                'dir'      => $dir,
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'data');
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       META · dropdown bundle for the form
       ════════════════════════════════════════════════════════════════════
       Returns everything the create / edit wizard needs in ONE call so
       there's no waterfall of dependent requests when the sheet opens. */

    public function meta(Request $request): JsonResponse
    {
        try {
            $country = (string) $request->query('country', self::defaultCountry());

            $provinces = DB::table('ref_provinces')
                ->where('country_code', $country)
                ->whereNull('deleted_at')
                ->orderBy('display_order')->orderBy('id')
                ->get(['id', 'name', 'code', 'admin_level_1_type'])
                ->map(fn ($p) => [
                    'id'                 => (int) $p->id,
                    'name'               => (string) $p->name,
                    'code'               => (string) $p->code,
                    'admin_level_1_type' => (string) $p->admin_level_1_type,
                ])->all();

            $districts = DB::table('ref_districts')
                ->where('country_code', $country)
                ->whereNull('deleted_at')
                ->orderBy('display_order')->orderBy('id')
                ->get(['id', 'province_id', 'name', 'name_raw', 'code'])
                ->map(fn ($d) => [
                    'id'          => (int) $d->id,
                    'province_id' => (int) $d->province_id,
                    'name'        => (string) $d->name,
                    'name_raw'    => $d->name_raw,
                    'code'        => (string) $d->code,
                ])->all();

            return $this->ok([
                'country'         => $country,
                'provinces'       => $provinces,
                'districts'       => $districts,
                'poe_types'       => self::POE_TYPES,
                'transport_modes' => self::TRANSPORT_MODES,
                'neighbours'      => self::NEIGHBOURS,
                'known_osbps'     => self::KNOWN_OSBPS,
                'source_origins'  => [
                    'land' => self::DEFAULT_SOURCE_ORIGIN,
                    'air'  => self::AIRPORT_SOURCE_ORIGIN,
                ],
                'source_urls'     => [
                    'land' => self::DEFAULT_SOURCE_URL,
                    'air'  => self::AIRPORT_SOURCE_URL,
                ],
                'rules'           => [
                    'poe_code_follows_poe_name' => true,
                    'derived_keys'              => self::POE_PAYLOAD_KEY_ORDER,
                    'border_country_required'   => 'land_border only',
                ],
            ], 'Meta bundle.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'meta');
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       SMART SUGGEST · deterministic auto-fill from minimal input
       ════════════════════════════════════════════════════════════════════
       The form calls this every time the user types into poe_name OR
       changes province/district.  Server returns the derived fields the
       wizard's "Review" step will display read-only before submit.  No
       LLM call — purely rule-based per dashboard.txt §B.5 (deterministic). */

    public function suggest(Request $request): JsonResponse
    {
        try {
            $name       = trim((string) ($request->input('poe_name') ?? $request->input('name', '')));
            $provinceId = (int) $request->input('province_id', 0);
            $districtId = (int) $request->input('district_id', 0);

            $province = $provinceId > 0
                ? DB::table('ref_provinces')->where('id', $provinceId)->whereNull('deleted_at')->first()
                : null;
            $district = $districtId > 0
                ? DB::table('ref_districts')->where('id', $districtId)->whereNull('deleted_at')->first()
                : null;

            // Cross-FK sanity check
            if ($province && $district && (int) $district->province_id !== (int) $province->id) {
                return $this->err(422, 'District does not belong to province.');
            }

            $poeType        = $this->derivePoeTypeFromName($name);
            $transportMode  = self::POE_TYPE_TO_MODE[$poeType] ?? 'land';
            $isAirport      = $poeType === 'airport' || $poeType === 'airstrip';
            $isOSBP         = in_array($name, self::KNOWN_OSBPS, true);
            $borderGuess    = $this->guessBorderCountry($poeType, $province?->name, $district?->name);

            // Try a tentative external_id so the wizard can show it on Review.
            $externalIdGuess = ($name !== '' && $province && $district)
                ? $this->nextExternalId(self::defaultCountry(), (string) $province->name, (string) $district->name, $name)
                : null;

            $sourceUrl    = $isAirport ? self::AIRPORT_SOURCE_URL    : self::DEFAULT_SOURCE_URL;
            $sourceOrigin = $isAirport ? self::AIRPORT_SOURCE_ORIGIN : self::DEFAULT_SOURCE_ORIGIN;

            $criticalTemplate = $this->criticalDetailsTemplate(
                $poeType,
                $name,
                $district?->name_raw ?? ($district?->name ? preg_replace('/\s+District\s*$/u', '', $district->name) : null),
                $province?->name,
                $borderGuess
            );

            return $this->ok([
                'derived' => [
                    'poe_type'                   => $poeType,
                    'transport_mode'             => $transportMode,
                    'border_country'             => $borderGuess,
                    'poe_code'                   => $name,
                    'is_recommended_osbp'        => $isOSBP,
                    'external_id_guess'          => $externalIdGuess,
                    'source_url'                 => $sourceUrl,
                    'source_origin'              => $sourceOrigin,
                    'critical_details_template'  => $criticalTemplate,
                    // mirror keys for completeness
                    'admin_level_1'              => $province?->name,
                    'admin_level_1_type'         => $province?->admin_level_1_type ?? 'PHEOC',
                    'regional_cluster_or_rpheoc' => $province?->name,
                    'source_province_group'      => $province?->name,
                    'district'                   => $district?->name,
                    'district_raw'               => $district?->name_raw ?? ($district?->name ? preg_replace('/\s+District\s*$/u', '', $district->name) : null),
                ],
                'reasoning' => [
                    'poe_type_rule'       => $this->poeTypeReasoning($name),
                    'transport_mode_rule' => "Derived from poe_type='{$poeType}' (server-owned per shape contract).",
                    'border_country_rule' => $isAirport || $poeType === 'port' ? 'null for airport/port' : 'guessed from neighbouring frontier patterns; verify',
                    'osbp_rule'           => $isOSBP ? 'Name matches known commissioned OSBP list.' : 'Not in known OSBP list — leave false unless confirmed.',
                    'external_id_rule'    => 'Deterministic ' . strtoupper((string) (config('country.iso2') ?: 'UG')) . '-PROV3-DIST3-NAME3-NNN; collision-incremented.',
                ],
            ], 'Suggestions.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'suggest');
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       FUZZY DUPE CHECK
       ════════════════════════════════════════════════════════════════════
       Substring + soundex match against existing PoEs.  Returns max 5
       candidates for the wizard to surface as "Did you mean?". */

    public function dupeCheck(Request $request): JsonResponse
    {
        try {
            $name = trim((string) ($request->input('poe_name') ?? $request->input('name', '')));
            if ($name === '' || mb_strlen($name) < 3) {
                return $this->ok(['candidates' => []], 'Name too short for dupe check.');
            }
            $like = '%' . $name . '%';
            $sx   = soundex($name);

            $rows = DB::table('ref_poes')
                ->where('country_code', self::defaultCountry())
                ->where(function ($q) use ($like, $sx) {
                    $q->where('poe_name', 'like', $like)
                      ->orWhere('poe_code', 'like', $like)
                      ->orWhereRaw("SOUNDEX(poe_name) = ?", [$sx]);
                })
                ->whereNull('deleted_at')
                ->limit(5)
                ->get(['id', 'external_id', 'poe_name', 'poe_type', 'admin_level_1', 'district']);

            // Key is `candidates` — the wizard JS reads `dJ.data.candidates`.
            return $this->ok([
                'candidates' => $rows->map(fn ($r) => [
                    'id'           => (int) $r->id,
                    'external_id'  => (string) ($r->external_id ?? ''),
                    'poe_name'     => (string) $r->poe_name,
                    'poe_type'     => (string) ($r->poe_type ?? ''),
                    'admin_level_1'=> (string) ($r->admin_level_1 ?? ''),
                    'district'     => (string) ($r->district ?? ''),
                ])->all(),
            ], 'Dupe candidates.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'dupeCheck');
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       SHOW · single PoE
       ════════════════════════════════════════════════════════════════════ */

    public function show(Request $request, int $id): JsonResponse
    {
        try {
            $row = DB::table('ref_poes')->where('id', $id)->first();
            if (!$row) {
                return $this->err(404, 'PoE not found.');
            }
            return $this->ok($this->castDetail($row), 'PoE retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'show');
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       VERSION · current ref_geo_version (used in confirmation toast)
       ════════════════════════════════════════════════════════════════════ */

    public function version(Request $request): JsonResponse
    {
        try {
            $country = (string) $request->query('country', self::defaultCountry());
            return $this->ok([
                'country' => $country,
                'version' => $this->currentVersion($country),
            ], 'Version.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'version');
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       CREATE · session-auth gated, NATIONAL_ADMIN role required
       ════════════════════════════════════════════════════════════════════
       Mirrors GeoHierarchyController::storePoe column-for-column so
       /api/poes/bundle output is byte-equivalent regardless of write path. */

    public function store(Request $request): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }

        $data       = $request->all();
        $country    = self::defaultCountry();
        $name       = trim((string) ($data['poe_name'] ?? ''));
        $provinceId = (int) ($data['province_id'] ?? 0);
        $districtId = (int) ($data['district_id'] ?? 0);
        $poeType    = (string) ($data['poe_type'] ?? $this->derivePoeTypeFromName($name));

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

        $mode = $this->deriveTransportMode($poeType, $data['transport_mode'] ?? null);
        if (!in_array($mode, self::TRANSPORT_MODES, true)) {
            return $this->err(422, 'Invalid transport_mode.', ['allowed' => self::TRANSPORT_MODES]);
        }

        // Border country: required for land_border, must be null for airport/port.
        $borderCountry = $this->nullableString($data['border_country'] ?? null);
        if ($poeType === 'land_border' && $borderCountry === null) {
            return $this->err(422, 'border_country is required for land_border PoEs.', ['neighbours' => self::NEIGHBOURS]);
        }
        if (in_array($poeType, ['airport', 'airstrip', 'port', 'island_entry'], true)) {
            $borderCountry = null;
        }

        // poe_code follows poe_name unless explicitly overridden (legacy convention).
        $poeCode = isset($data['poe_code']) && $data['poe_code'] !== '' ? trim((string) $data['poe_code']) : $name;

        // external_id: client may pass; otherwise deterministic + collision-incremented.
        $externalId = isset($data['external_id']) && $data['external_id'] !== ''
            ? (string) $data['external_id']
            : $this->nextExternalId($country, (string) $province->name, (string) $district->name, $name);

        // Source defaults pivot on type.
        $isAirport    = in_array($poeType, ['airport', 'airstrip'], true);
        $sourceUrl    = (string) ($data['source_url']    ?? ($isAirport ? self::AIRPORT_SOURCE_URL    : self::DEFAULT_SOURCE_URL));
        $sourceOrigin = (string) ($data['source_origin'] ?? ($isAirport ? self::AIRPORT_SOURCE_ORIGIN : self::DEFAULT_SOURCE_ORIGIN));

        try {
            if (DB::table('ref_poes')->where('external_id', $externalId)->exists()) {
                return $this->err(409, 'PoE with that external_id exists.', ['external_id' => $externalId]);
            }

            // 2026-05-20: also pre-flight check poe_code uniqueness against the
            // DB's composite unique index (country_code, poe_code). Without this
            // pre-flight, MySQL throws SQLSTATE 23000 and Laravel surfaces it
            // as a 500 — bad UX for what is really a validation failure.
            if (DB::table('ref_poes')->where('country_code', $country)->where('poe_code', $poeCode)->exists()) {
                return $this->err(409, 'A PoE with that code already exists in this country.', [
                    'poe_code' => $poeCode,
                    'hint'     => 'Pick a different name (poe_code follows poe_name) or override the poe_code field on Step 3 / Advanced.',
                ]);
            }

            $districtRaw = (string) ($district->name_raw ?? preg_replace('/\s+District\s*$/u', '', $district->name));

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
                'border_country'             => $borderCountry,
                'is_major_entry'             => (bool) ($data['is_major_entry']      ?? false),
                'is_recommended_osbp'        => (bool) ($data['is_recommended_osbp'] ?? false),
                'is_national_level'          => (bool) ($data['is_national_level']   ?? false),
                'regional_cluster_or_rpheoc' => $province->name,
                'critical_details'           => (string) ($data['critical_details']  ?? ''),
                'source_province_group'      => $province->name,
                'source_url'                 => $sourceUrl,
                'source_origin'              => $sourceOrigin,
            ]);

            $now = Carbon::now();
            $nextOrder = array_key_exists('display_order', $data)
                ? (int) $data['display_order']
                : (((int) DB::table('ref_poes')->max('display_order')) + 1);

            $insertId = DB::table('ref_poes')->insertGetId([
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
                'is_national_level'   => (int) (bool) ($data['is_national_level']   ?? false),
                'is_major_entry'      => (int) (bool) ($data['is_major_entry']      ?? false),
                'is_recommended_osbp' => (int) (bool) ($data['is_recommended_osbp'] ?? false),
                'border_country'      => $borderCountry,
                'latitude'            => $data['latitude']  ?? null,
                'longitude'           => $data['longitude'] ?? null,
                'gazette_source'      => $sourceUrl ?: null,
                'payload'             => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_active'           => (int) (bool) ($data['is_active'] ?? true),
                'display_order'       => $nextOrder,
                'created_by_user_id'  => $admin,
                'updated_by_user_id'  => $admin,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
            $this->bumpVersion($country);
            $fresh = DB::table('ref_poes')->where('id', $insertId)->first();
            return $this->ok($this->castDetail($fresh), 'PoE created.', [
                'version' => $this->currentVersion($country),
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'store');
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       UPDATE · same shape rules as store; server re-derives every field
       ════════════════════════════════════════════════════════════════════ */

    public function update(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_poes')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'PoE not found.');
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
            if (!$province || !$district
                || $province->country_code !== $row->country_code
                || $district->country_code !== $row->country_code
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
            $newCode = array_key_exists('poe_code', $data) && $data['poe_code'] !== ''
                ? (string) $data['poe_code']
                : $newName;

            $borderCountry = array_key_exists('border_country', $data)
                ? $this->nullableString($data['border_country'])
                : ($existingPayload['border_country'] ?? $row->border_country);
            if ($poeType === 'land_border' && $borderCountry === null) {
                return $this->err(422, 'border_country is required for land_border PoEs.', ['neighbours' => self::NEIGHBOURS]);
            }
            if (in_array($poeType, ['airport', 'airstrip', 'port', 'island_entry'], true)) {
                $borderCountry = null;
            }

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
                'border_country'             => $borderCountry,
                'is_major_entry'             => (bool) ($data['is_major_entry']      ?? $row->is_major_entry),
                'is_recommended_osbp'        => (bool) ($data['is_recommended_osbp'] ?? $row->is_recommended_osbp),
                'is_national_level'          => (bool) ($data['is_national_level']   ?? $row->is_national_level),
                'regional_cluster_or_rpheoc' => $province->name,
                'critical_details'           => (string) ($data['critical_details']  ?? ($existingPayload['critical_details']  ?? '')),
                'source_province_group'      => $province->name,
                'source_url'                 => (string) ($data['source_url']        ?? ($existingPayload['source_url']        ?? '')),
                'source_origin'              => (string) ($data['source_origin']     ?? ($existingPayload['source_origin']     ?? self::DEFAULT_SOURCE_ORIGIN)),
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
            if (array_key_exists('is_active',     $data)) { $patch['is_active']     = (int) (bool) $data['is_active']; }
            if (array_key_exists('display_order', $data)) { $patch['display_order'] = (int) $data['display_order']; }
            if (array_key_exists('latitude',      $data)) { $patch['latitude']      = $data['latitude']; }
            if (array_key_exists('longitude',     $data)) { $patch['longitude']     = $data['longitude']; }

            // 2026-05-20: pre-flight poe_code uniqueness against the composite
            // (country_code, poe_code) unique index. Skip the check when the
            // poe_code is unchanged. Without this, MySQL throws 23000 and
            // Laravel surfaces a 500 — better to return a clean 409.
            if ((string) $patch['poe_code'] !== (string) $row->poe_code) {
                $clash = DB::table('ref_poes')
                    ->where('country_code', $row->country_code)
                    ->where('poe_code',     $patch['poe_code'])
                    ->where('id', '!=', $row->id)
                    ->exists();
                if ($clash) {
                    return $this->err(409, 'Another PoE in this country already uses that code.', [
                        'poe_code' => $patch['poe_code'],
                        'hint'     => 'Change poe_name (poe_code follows it) or set a distinct poe_code override.',
                    ]);
                }
            }

            DB::table('ref_poes')->where('id', $row->id)->update($patch);
            $this->bumpVersion((string) $row->country_code);
            $fresh = DB::table('ref_poes')->where('id', $row->id)->first();
            return $this->ok($this->castDetail($fresh), 'PoE updated.', [
                'version' => $this->currentVersion((string) $row->country_code),
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'update');
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       SOFT DELETE · sets deleted_at; bundle excludes soft-deleted rows
       ════════════════════════════════════════════════════════════════════ */

    public function destroy(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_poes')->where('id', $id)->whereNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'PoE not found or already retired.');
            }
            DB::table('ref_poes')->where('id', $row->id)->update([
                'deleted_at'         => Carbon::now(),
                'updated_by_user_id' => $admin,
                'updated_at'         => Carbon::now(),
            ]);
            $this->bumpVersion((string) $row->country_code);
            return $this->ok(['id' => (int) $row->id, 'soft_deleted' => true], 'PoE retired.', [
                'version' => $this->currentVersion((string) $row->country_code),
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'destroy');
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       RESTORE · clears deleted_at; back into bundle on next refresh
       ════════════════════════════════════════════════════════════════════ */

    public function restore(Request $request, int $id): JsonResponse
    {
        $admin = $this->requireNationalAdmin();
        if ($admin instanceof JsonResponse) { return $admin; }
        try {
            $row = DB::table('ref_poes')->where('id', $id)->whereNotNull('deleted_at')->first();
            if (!$row) {
                return $this->err(404, 'PoE not found or already active.');
            }
            DB::table('ref_poes')->where('id', $row->id)->update([
                'deleted_at'         => null,
                'updated_by_user_id' => $admin,
                'updated_at'         => Carbon::now(),
            ]);
            $this->bumpVersion((string) $row->country_code);
            $fresh = DB::table('ref_poes')->where('id', $row->id)->first();
            return $this->ok($this->castDetail($fresh), 'PoE restored.', [
                'version' => $this->currentVersion((string) $row->country_code),
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'restore');
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       INTERNAL · payload normaliser (mirrors GeoHierarchyController)
       ════════════════════════════════════════════════════════════════════ */

    /**
     * Force PoE payload key order to match legacy POEs.js shape.
     * Missing keys are emitted as null (or false for boolean keys).
     * This MUST stay byte-equivalent to GeoHierarchyController::buildPayload.
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
        // Booleans must be true/false in the payload, not 0/1 (per fixture contract).
        foreach (['is_major_entry', 'is_recommended_osbp', 'is_national_level'] as $b) {
            $out[$b] = (bool) $out[$b];
        }
        return $out;
    }

    /**
     * Deterministic external_id generator matching legacy POEs.js
     * (TENANT_ISO2-PROV3-DIST3-NAME3-NNN). Uses the same first-3-alphanumeric
     * convention as GeoHierarchyController so any path produces identical ids.
     *
     * 2026-05-20: prefix sourced from config('country.iso2') instead of a
     * hardcoded literal. Previously hardcoded to 'RW-' (Rwanda residue) which
     * silently stamped every NEW Uganda POE with a Rwanda-shaped id. Existing
     * seeded rows have external_id IS NULL, so no historical data corruption.
     */
    private function nextExternalId(string $country, string $province, string $district, string $name): string
    {
        $seg = static function (string $s): string {
            $clean = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', $s) ?? '');
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
        return $prefix . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
    }

    /**
     * transport_mode is server-derived from poe_type; only 'other' lets
     * the caller hint a value. Mirrors mobile logic exactly.
     */
    private function deriveTransportMode(string $poeType, mixed $requested = null): string
    {
        if (array_key_exists($poeType, self::POE_TYPE_TO_MODE)) {
            return self::POE_TYPE_TO_MODE[$poeType];
        }
        $cand = is_string($requested) && $requested !== '' ? $requested : 'other';
        return in_array($cand, self::TRANSPORT_MODES, true) ? $cand : 'other';
    }

    /**
     * Smart "AI" derivation of poe_type from name.
     *   - Contains "International Airport"|"Airport"  → airport
     *   - Contains "Airstrip"                         → airstrip
     *   - Contains "Port"|"Harbour"|"Harbor"          → port
     *   - Contains "Rail"|"Railway"|"Station"         → rail (only if "rail")
     *   - Otherwise                                   → land_border
     */
    private function derivePoeTypeFromName(string $name): string
    {
        $n = strtolower($name);
        if ($n === '') { return 'land_border'; }
        if (str_contains($n, 'airport'))  return 'airport';
        if (str_contains($n, 'airstrip')) return 'airstrip';
        if (str_contains($n, 'harbour') || str_contains($n, 'harbor') || preg_match('/\bport\b/u', $n)) return 'port';
        if (str_contains($n, 'island')) return 'island_entry';
        if (preg_match('/\b(rail|railway)\b/u', $n)) return 'rail';
        return 'land_border';
    }

    private function poeTypeReasoning(string $name): string
    {
        $type = $this->derivePoeTypeFromName($name);
        return match ($type) {
            'airport'      => "Name contains 'Airport' → poe_type=airport, transport_mode=air, border_country=null.",
            'airstrip'     => "Name contains 'Airstrip' → poe_type=airstrip, transport_mode=air, border_country=null.",
            'port'         => "Name contains 'Port'/'Harbour' → poe_type=port, transport_mode=water, border_country=null.",
            'island_entry' => "Name contains 'Island' → poe_type=island_entry, transport_mode=water.",
            'rail'         => "Name contains 'Rail'/'Railway' → poe_type=rail, transport_mode=rail.",
            default        => "No airport/port markers → poe_type=land_border, transport_mode=land, border_country required.",
        };
    }

    /**
     * Best-effort border_country guess for land_border PoEs.
     * Looks at the most common border_country among existing PoEs in the
     * same district first, then the same province. Returns null if nothing
     * to lean on — the wizard then prompts the admin to pick from the eight.
     */
    private function guessBorderCountry(string $poeType, ?string $provinceName, ?string $districtName): ?string
    {
        if ($poeType !== 'land_border') {
            return null;
        }
        if (!$provinceName && !$districtName) {
            return null;
        }
        $q = DB::table('ref_poes')
            ->where('country_code', self::defaultCountry())
            ->whereNull('deleted_at')
            ->where('poe_type', 'land_border')
            ->whereNotNull('border_country');
        if ($districtName) {
            $hit = (clone $q)->where('district', $districtName)
                ->groupBy('border_country')
                ->orderByRaw('COUNT(*) DESC')
                ->limit(1)
                ->value('border_country');
            if ($hit) { return (string) $hit; }
        }
        if ($provinceName) {
            $hit = $q->where('admin_level_1', $provinceName)
                ->groupBy('border_country')
                ->orderByRaw('COUNT(*) DESC')
                ->limit(1)
                ->value('border_country');
            if ($hit) { return (string) $hit; }
        }
        return null;
    }

    /**
     * Critical-details starter template per type. Admin can edit; this just
     * removes the blank-page problem so the wizard's last step has prose to
     * trim instead of a TODO.
     */
    private function criticalDetailsTemplate(string $poeType, string $name, ?string $districtRaw, ?string $provinceName, ?string $border): string
    {
        $district = $districtRaw ?? '[district]';
        $province = $provinceName ?? '[province]';
        return match ($poeType) {
            'airport', 'airstrip' => "Gazetted airport in {$district} District, managed by Uganda Civil Aviation Authority (UCAA). IHR-designated international point of entry. {$province} - [hospital reference].",
            'port'                => "Gazetted lake port in {$district} District. Primary water-mode point of entry on Lake Victoria. {$province} - [hospital reference].",
            'island_entry'        => "Gazetted island entry in {$district} District. {$province} - [hospital reference].",
            'rail'                => "Gazetted rail crossing in {$district} District. {$province} - [hospital reference].",
            default               => "Gazetted border post in {$district} District on the " . ($border ?? '[neighbour]') . " frontier. {$province} - [hospital reference].",
        };
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) return null;
        $v = trim((string) $value);
        return $v === '' ? null : $v;
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

    private function currentVersion(string $country): int
    {
        return (int) (DB::table('ref_geo_version')->where('country_code', $country)->value('version') ?? 0);
    }

    /* ════════════════════════════════════════════════════════════════════
       ROW CASTERS
       ════════════════════════════════════════════════════════════════════ */

    private function castListRow(object $r): array
    {
        return [
            'id'                  => (int) $r->id,
            'external_id'         => (string) $r->external_id,
            'poe_name'            => (string) $r->poe_name,
            'poe_code'            => (string) $r->poe_code,
            'poe_type'            => (string) $r->poe_type,
            'transport_mode'      => (string) $r->transport_mode,
            'admin_level_1'       => (string) ($r->admin_level_1 ?? ''),
            'district'            => (string) ($r->district ?? ''),
            'province_id'         => $r->province_id !== null ? (int) $r->province_id : null,
            'district_id'         => $r->district_id !== null ? (int) $r->district_id : null,
            'border_country'      => $r->border_country,
            'is_major_entry'      => (bool) $r->is_major_entry,
            'is_recommended_osbp' => (bool) $r->is_recommended_osbp,
            'is_national_level'   => (bool) $r->is_national_level,
            'is_active'           => (bool) $r->is_active,
            'is_retired'          => $r->deleted_at !== null,
            'display_order'       => (int) $r->display_order,
            'updated_at'          => $r->updated_at,
        ];
    }

    private function castDetail(object $r): array
    {
        // MySQL JSON columns sort keys alphabetically on read; rebuild in
        // legacy order so the detail response matches the bundle byte-shape.
        $payloadRaw = $r->payload ? json_decode((string) $r->payload, true) : null;
        $payload    = is_array($payloadRaw) ? $this->buildPayload($payloadRaw) : null;

        return [
            'id'                  => (int) $r->id,
            'external_id'         => (string) $r->external_id,
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
            'is_retired'          => $r->deleted_at !== null,
            'display_order'       => (int) $r->display_order,
            'payload'             => $payload,
            'created_at'          => $r->created_at,
            'updated_at'          => $r->updated_at,
            'deleted_at'          => $r->deleted_at,
        ];
    }

    /* ════════════════════════════════════════════════════════════════════
       AUTH GUARD · session-based; mobile uses query param user_id
       ════════════════════════════════════════════════════════════════════
       Until Phase 0 lands the RoleGate middleware for /admin/*, writes
       require auth()->check() AND role_key='NATIONAL_ADMIN'. Reads stay
       open (matching the rest of the rebuilt panel's preview posture). */

    /**
     * Returns the active admin user id. Auth + NATIONAL_ADMIN role are now
     * enforced by route middleware (web · auth · scope · role:NATIONAL_ADMIN);
     * this method exists only to capture the user id for created_by /
     * updated_by audit columns.
     */
    private function requireNationalAdmin(): int|JsonResponse
    {
        return (int) (auth()->id() ?? 0);
    }

    /* ════════════════════════════════════════════════════════════════════
       RESPONSE HELPERS · matching mobile envelope
       ════════════════════════════════════════════════════════════════════ */

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => $data];
        if (!empty($meta)) { $body['meta'] = $meta; }
        return response()->json($body, 200);
    }

    private function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error' => $detail], $status);
    }

    private function serverError(Throwable $e, string $ctx): JsonResponse
    {
        Log::error("[Admin\\Geo\\Poes][{$ctx}] " . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]);
        return response()->json(['success' => false, 'message' => "Server error: {$ctx}"], 500);
    }
}
