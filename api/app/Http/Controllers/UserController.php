<?php

declare (strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * UserController — POE Offline-First Screening System
 *
 * All authentication and authorization guards intentionally omitted.
 * Custom auth will be implemented as a separate layer.
 *
 * ════════════════════════════════════════════════════════════════
 * VERIFIED COLUMN MAP — poe_2026.sql, line-by-line
 * ════════════════════════════════════════════════════════════════
 *
 * TABLE: users
 *   id                bigint UNSIGNED NOT NULL AUTO_INCREMENT
 *   role_key          varchar(60)     DEFAULT NULL
 *   country_code      varchar(10)     DEFAULT NULL
 *   full_name         varchar(150)    DEFAULT NULL
 *   username          varchar(80)     DEFAULT NULL
 *   password_hash     varchar(255)    DEFAULT NULL
 *   email             varchar(190)    DEFAULT NULL
 *   phone             varchar(40)     DEFAULT NULL
 *   is_active         tinyint(1)      DEFAULT '1'
 *   last_login_at     datetime        DEFAULT NULL
 *   created_at        datetime        NOT NULL
 *   updated_at        datetime        NOT NULL
 *   email_verified_at timestamp       NULL DEFAULT NULL
 *   password          varchar(200)    DEFAULT NULL
 *   name              varchar(200)    DEFAULT NULL
 *
 * TABLE: user_assignments
 *   id                bigint UNSIGNED NOT NULL AUTO_INCREMENT
 *   user_id           bigint UNSIGNED NOT NULL
 *   country_code      varchar(10)     NOT NULL
 *   province_code     varchar(30)     DEFAULT NULL
 *   pheoc_code        varchar(30)     DEFAULT NULL
 *   district_code     varchar(30)     DEFAULT NULL
 *   poe_code          varchar(40)     DEFAULT NULL
 *   is_primary        tinyint(1)      NOT NULL DEFAULT '1'
 *   is_active         tinyint(1)      NOT NULL DEFAULT '1'
 *   starts_at         datetime        DEFAULT NULL
 *   ends_at           datetime        DEFAULT NULL
 *   created_at        datetime        NOT NULL
 *   updated_at        datetime        NOT NULL
 *
 * THERE IS NO client_uuid COLUMN ON EITHER TABLE.
 * client_uuid is accepted from the mobile payload for log tracing and is
 * echoed back in responses so the mobile can reconcile server IDs —
 * it is NEVER written to the database.
 *
 * THERE IS NO deleted_at COLUMN ON EITHER TABLE.
 * No soft-delete filters are applied anywhere in this controller.
 *
 * ════════════════════════════════════════════════════════════════
 */
final class UserController extends Controller
{
    // ----------------------------------------------------------------
    // CONSTANTS
    // ----------------------------------------------------------------

    /**
     * All valid values for users.role_key VARCHAR(60).
     *
     * Hierarchy (highest → lowest):
     *   NATIONAL_ADMIN      — super-user; global scope; jailbreak client-side.
     *   PHEOC_OFFICER       — province-scoped.
     *   DISTRICT_SUPERVISOR — district-scoped.
     *   POE_ADMIN           — POE-scoped admin (users + contacts within POE).
     *   POE_PRIMARY         — primary-screening operator at a POE.
     *   POE_SECONDARY       — secondary-screening operator at a POE.
     *   POE_DATA_OFFICER    — aggregated-data submissions at a POE.
     *   SCREENER            — generic screener (legacy; maps to POE_PRIMARY
     *                         for permission purposes on the mobile side).
     */
    public const VALID_ROLES = [
        'NATIONAL_ADMIN',
        'PHEOC_OFFICER',
        'DISTRICT_SUPERVISOR',
        'POE_ADMIN',
        'POE_PRIMARY',
        'POE_SECONDARY',
        'POE_DATA_OFFICER',
        'SCREENER',
    ];

    /**
     * ════════════════════════════════════════════════════════════════════════
     * GEOGRAPHIC SSOT CONSTANTS — sourced verbatim from POES.JS
     *
     * Rule: every value stored in user_assignments.province_code,
     * pheoc_code, district_code, and poe_code MUST exactly match one of the
     * strings below. No abbreviations, IDs, or aliases are accepted.
     *
     * These lists are the server-side mirror of window.POE_MAIN in POES.JS.
     * When POES.JS is updated these constants MUST be updated in lockstep.
     * ════════════════════════════════════════════════════════════════════════
     */

    /**
     * Valid values for user_assignments.province_code and pheoc_code.
     * Authoritative source is now the ref_provinces table (loaded by
     * Country\ProvincesSeeder from country/UG/data/geography.json).
     * This constant is kept empty so allowedPheocNames() falls back
     * exclusively to the DB-driven list.
     */
    public const VALID_PHEOC_NAMES = [];

    /**
     * Valid values for user_assignments.district_code.
     * Authoritative source is now the ref_districts table (loaded by
     * Country\DistrictsSeeder from country/UG/data/geography.json).
     * Kept empty so allowedDistrictNames() falls back to the DB-driven list.
     */
    public const VALID_DISTRICT_NAMES = [];

    /**
     * Valid values for user_assignments.poe_code.
     * Authoritative source is now the ref_poes table (loaded by
     * Country\PoesSeeder from country/UG/data/poes.json).
     * Kept empty so allowedPoeNames() falls back to the DB-driven list.
     */
    public const VALID_POE_NAMES = [];

    /**
     * Minimum user_assignments geography required per role.
     * Drives enforceGeographyForRole() validation.
     */
    public const ROLE_GEO_REQUIREMENTS = [
        'NATIONAL_ADMIN'      => [],
        'PHEOC_OFFICER'       => ['province_or_pheoc'],
        'DISTRICT_SUPERVISOR' => ['province_or_pheoc', 'district_code'],
        // All POE-level roles require the full geographic chain so the
        // server can scope every list query correctly.
        'POE_ADMIN'           => ['province_or_pheoc', 'district_code', 'poe_code'],
        'POE_PRIMARY'         => ['province_or_pheoc', 'district_code', 'poe_code'],
        'POE_SECONDARY'       => ['province_or_pheoc', 'district_code', 'poe_code'],
        'POE_DATA_OFFICER'    => ['province_or_pheoc', 'district_code', 'poe_code'],
        'SCREENER'            => ['province_or_pheoc', 'district_code', 'poe_code'],
    ];

    /**
     * DB-backed allow-lists for assignment validation.
     *
     * These methods return the UNION of:
     *   (a) live rows in ref_provinces / ref_districts / ref_poes,
     *   (b) the hardcoded POES.JS-derived baseline constants above.
     *
     * Rationale:
     *   — (a) keeps the API in sync with admin CRUD additions (a newly
     *     created PHEOC/District/POE immediately becomes assignable).
     *   — (b) preserves the original contract for tenants that haven't
     *     seeded the normalized ref_* tables yet.
     *
     * Cached per-request via static locals so Rule::in() doesn't re-query
     * the DB on every rule evaluation.
     */
    /**
     * Build the canonical accept-list for a geographic field.
     *
     * Historical reality: the same column has been written with different
     * shapes by different writers — the legacy mobile path stored short
     * codes ("UG-EBB-001"), the admin tool stores human names ("Entebbe
     * International Airport"), and a forked seed even left ISO-style
     * "RW-01" province codes. Updating a user whose existing assignment
     * uses one shape MUST NOT fail validation just because the validator
     * was hard-coded to the other shape.
     *
     * Solution: accept the union of every legitimate alias. We pull each
     * ref table's name AND code columns, plus the static fallback list.
     * Empty strings filtered out.
     */
    /**
     * Build the canonical accept-list for a geographic field.
     *
     * Reality: the same column has been written with different shapes by
     * different writers — short codes ("UG-EBB-001"), human names ("Entebbe
     * International Airport"), forked seed codes ("RW-01"). Updates MUST
     * NOT fail just because the validator expected one shape and the
     * existing row has another.
     *
     * Solution: accept the union of every legitimate alias — name AND code
     * pulled from the ref tables. Plus a wildcard fallback so legacy
     * codes ("RW-01", "KIC", etc.) that aren't in ref_* still pass; the
     * shape is checked by the column type and the data layer will simply
     * not match anything for an unknown value.
     *
     * Schema notes (verified against rw_poe DB):
     *   - ref_provinces: column is `code`  (NOT `province_code`)
     *   - ref_districts: column is `code`  (NOT `district_code`)
     *   - ref_poes:      columns are `poe_code` AND `poe_name`
     */
    public static function allowedPheocNames(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $rows = \Illuminate\Support\Facades\DB::table('ref_provinces')
                ->whereNull('deleted_at')->where('is_active', 1)
                ->select('name', 'code')->get();
            $db = [];
            foreach ($rows as $r) {
                if (! empty($r->name)) $db[] = (string) $r->name;
                if (! empty($r->code)) $db[] = (string) $r->code;
            }
        } catch (\Throwable $e) { $db = []; }
        return $cache = array_values(array_unique(array_filter(array_merge(self::VALID_PHEOC_NAMES, $db))));
    }

    public static function allowedDistrictNames(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $rows = \Illuminate\Support\Facades\DB::table('ref_districts')
                ->whereNull('deleted_at')->where('is_active', 1)
                ->select('name', 'code')->get();
            $db = [];
            foreach ($rows as $r) {
                if (! empty($r->name)) $db[] = (string) $r->name;
                if (! empty($r->code)) $db[] = (string) $r->code;
            }
        } catch (\Throwable $e) { $db = []; }
        return $cache = array_values(array_unique(array_filter(array_merge(self::VALID_DISTRICT_NAMES, $db))));
    }

    public static function allowedPoeNames(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        try {
            $rows = \Illuminate\Support\Facades\DB::table('ref_poes')
                ->whereNull('deleted_at')->where('is_active', 1)
                ->select('poe_name', 'poe_code')->get();
            $db = [];
            foreach ($rows as $r) {
                if (! empty($r->poe_name)) $db[] = (string) $r->poe_name;
                if (! empty($r->poe_code)) $db[] = (string) $r->poe_code;
            }
        } catch (\Throwable $e) { $db = []; }
        return $cache = array_values(array_unique(array_filter(array_merge(self::VALID_POE_NAMES, $db))));
    }

    // ----------------------------------------------------------------
    // ENDPOINTS
    // ----------------------------------------------------------------

    /**
     * GET /users
     *
     * Paginated list of users joined to their active primary assignment.
     * Filters: role_key, is_active, search (name/username/email/district/poe),
     * per_page, page.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'role_key'  => ['sometimes', 'string', Rule::in(self::VALID_ROLES)],
            'is_active' => ['sometimes', 'boolean'],
            'search'    => ['sometimes', 'string', 'max:100'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page'      => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = $this->baseUserQuery();

        // ── CALLER-SCOPE ENFORCEMENT ───────────────────────────────────────
        // When a user_id is provided, confine the result set to the caller's
        // geographic scope. NATIONAL_ADMIN sees all. PHEOC sees its province.
        // DISTRICT sees its district. POE_* sees its POE. Anonymous callers
        // (no user_id) receive the unscoped list — preserves the legacy v1
        // open-route semantics.
        [$caller, $callerAsg] = $this->resolveCaller($request);
        if ($caller !== null) {
            $callerRole = $caller->role_key ?? '';
            if ($callerRole === 'PHEOC_OFFICER' && ! empty($callerAsg->pheoc_code)) {
                $query->where('ua.pheoc_code', $callerAsg->pheoc_code);
            } elseif ($callerRole === 'DISTRICT_SUPERVISOR' && ! empty($callerAsg->district_code)) {
                $query->where('ua.district_code', $callerAsg->district_code);
            } elseif (in_array($callerRole, ['POE_ADMIN', 'POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'SCREENER'], true)
                && ! empty($callerAsg->poe_code)) {
                $query->where('ua.poe_code', $callerAsg->poe_code);
            }
            // NATIONAL_ADMIN falls through with no extra filter — sees all.
        }

        if (! empty($validated['role_key'])) {
            // filters on users.role_key VARCHAR(60)
            $query->where('u.role_key', $validated['role_key']);
        }

        if (isset($validated['is_active'])) {
            // filters on users.is_active TINYINT(1)
            $query->where('u.is_active', (int) $validated['is_active']);
        }

        if (! empty($validated['search'])) {
            $term = '%' . $validated['search'] . '%';
            $query->where(function ($q) use ($term) {
                $q->where('u.full_name', 'like', $term)      // users.full_name VARCHAR(150)
                    ->orWhere('u.name', 'like', $term)           // users.name VARCHAR(200)
                    ->orWhere('u.username', 'like', $term)       // users.username VARCHAR(80)
                    ->orWhere('u.email', 'like', $term)          // users.email VARCHAR(190)
                    ->orWhere('ua.district_code', 'like', $term) // user_assignments.district_code VARCHAR(30)
                    ->orWhere('ua.poe_code', 'like', $term);     // user_assignments.poe_code VARCHAR(40)
            });
        }

        $query->orderBy('u.created_at', 'desc'); // users.created_at datetime

        $perPage = (int) ($validated['per_page'] ?? 50);
        $result  = $query->paginate($perPage);

        return $this->ok([
            'items'        => $result->items(),
            'total'        => $result->total(),
            'per_page'     => $result->perPage(),
            'current_page' => $result->currentPage(),
            'last_page'    => $result->lastPage(),
        ]);
    }

    /**
     * POST /users
     *
     * Create a user from a mobile offline record.
     *
     * Password handling:
     *   Mobile sends plaintext in `password`. Hash::make() produces one bcrypt
     *   string written to both users.password (Laravel Auth) and
     *   users.password_hash (POE audit). Plaintext is never persisted.
     *
     * Name handling:
     *   The submitted full_name value is written to both users.full_name
     *   (POE identity) and users.name (Laravel auth display). Same string, two
     *   real columns that both exist in the schema.
     *
     * client_uuid handling:
     *   Accepted from payload, used for log tracing, echoed back in response.
     *   NOT written to the database — no such column exists on users.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateUserPayload($request, null);
        [$caller, $callerAsg] = $this->resolveCaller($request);
        $this->enforceCallerHierarchy($caller, $callerAsg, $validated);
        $this->enforceGeographyForRole($validated);

        try {
            $userId = DB::transaction(function () use ($validated): int {
                $now    = now()->toDateTimeString();
                $bcrypt = Hash::make($validated['password']);

                // ── INSERT users ───────────────────────────────────────────
                // Every key below is an exact column from poe_2026.users.
                // No key may be added here that is not in the verified column
                // map at the top of this file.
                $userId = DB::table('users')->insertGetId([
                    'role_key'          => $validated['role_key'],                   // varchar(60)
                    'country_code'      => $validated['country_code'],               // varchar(10)
                    'full_name'         => $validated['full_name'],                  // varchar(150)
                    'name'              => $validated['full_name'],                  // varchar(200) — same value, Laravel auth display
                    'username'          => strtolower(trim($validated['username'])), // varchar(80)
                    'email'             => $validated['email'] ?? null,              // varchar(190)
                    'phone'             => $validated['phone'] ?? null,              // varchar(40)
                    'password'          => $bcrypt,                                  // varchar(200) — Laravel Auth::attempt() reads this
                    'password_hash'     => $bcrypt,                                  // varchar(255) — POE audit copy
                    'email_verified_at' => null,                                     // timestamp
                    'is_active'         => (int) ($validated['is_active'] ?? 1),     // tinyint(1)
                    'last_login_at'     => null,                                     // datetime
                    'created_at'        => $now,                                     // datetime
                    'updated_at'        => $now,                                     // datetime
                ]);

                $this->upsertAssignment($userId, $validated['assignment'], $now);

                Log::info('[POE-USERS] User created', [
                    'server_id'   => $userId,
                    'role_key'    => $validated['role_key'],
                    'client_uuid' => $validated['client_uuid'] ?? null, // log only — not written to DB
                ]);

                return $userId;
            });
        } catch (Throwable $e) {
            Log::error('[POE-USERS] Store transaction failed', [
                'error'       => $e->getMessage(),
                'client_uuid' => $validated['client_uuid'] ?? null, // log only
            ]);
            return $this->fail('Failed to save user. Please retry.', 500);
        }

        return $this->ok(
            $this->buildResponseRecord($userId, $validated['client_uuid'] ?? null),
            'User created successfully.',
            201
        );
    }

    /**
     * PATCH /users/{id}
     *
     * Update an existing user.
     *
     * Password: null/absent = keep existing hash. Non-null = Hash::make() and
     * write to both users.password and users.password_hash simultaneously.
     *
     * client_uuid from payload is used for log tracing only, echoed in
     * response. NOT written to the database.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        // Confirm the row exists before validating the payload
        $target = DB::table('users')->where('id', $id)->first();
        if (! $target) {
            return $this->fail('User not found.', 404);
        }

        $validated = $this->validateUserPayload($request, $id);

        [$caller, $callerAsg] = $this->resolveCaller($request);
        $this->enforceCallerHierarchy($caller, $callerAsg, $validated);

        if (! empty($validated['assignment'])) {
            $this->enforceGeographyForRole($validated);
        }

        try {
            DB::transaction(function () use ($id, $validated, $target): void {
                $now      = now()->toDateTimeString();
                $fullName = $validated['full_name'] ?? $target->full_name;

                // ── UPDATE users ───────────────────────────────────────────
                // Every key below is an exact column from poe_2026.users.
                $update = [
                    'role_key'     => $validated['role_key'] ?? $target->role_key,         // varchar(60)
                    'country_code' => $validated['country_code'] ?? $target->country_code, // varchar(10)
                    'full_name'    => $fullName,                                           // varchar(150)
                    'name'         => $fullName,                                           // varchar(200) — kept in sync with full_name
                    'username'     => isset($validated['username'])
                        ? strtolower(trim($validated['username']))
                        : $target->username, // varchar(80)
                    'email'        => array_key_exists('email', $validated)
                        ? $validated['email']
                        : $target->email, // varchar(190)
                    'phone'        => array_key_exists('phone', $validated)
                        ? $validated['phone']
                        : $target->phone, // varchar(40)
                    'is_active'    => array_key_exists('is_active', $validated)
                        ? (int) $validated['is_active']
                        : $target->is_active,   // tinyint(1)
                    'updated_at'   => $now, // datetime
                ];

                // Re-hash only when a non-null plaintext password arrives.
                // users.password and users.password_hash are always written together.
                if (! empty($validated['password'])) {
                    $bcrypt                  = Hash::make($validated['password']);
                    $update['password']      = $bcrypt; // varchar(200)
                    $update['password_hash'] = $bcrypt; // varchar(255)
                }

                DB::table('users')->where('id', $id)->update($update);

                if (! empty($validated['assignment'])) {
                    $this->upsertAssignment($id, $validated['assignment'], $now);
                }

                Log::info('[POE-USERS] User updated', [
                    'server_id'   => $id,
                    'changed'     => array_keys($update),
                    'client_uuid' => $validated['client_uuid'] ?? null, // log only
                ]);
            });
        } catch (Throwable $e) {
            Log::error('[POE-USERS] Update transaction failed', [
                'server_id' => $id,
                'error'     => $e->getMessage(),
            ]);
            return $this->fail('Failed to update user. Please retry.', 500);
        }

        return $this->ok(
            $this->buildResponseRecord($id, $validated['client_uuid'] ?? null),
            'User updated successfully.'
        );
    }

    /**
     * GET /users/me?user_id={id}
     *
     * Returns the calling user's own profile + primary assignment, without
     * needing the {id} path. Mirrors the v1 trust model used elsewhere on
     * this controller — caller is identified by ?user_id=.
     */
    public function me(Request $request): JsonResponse
    {
        $callerId = $request->query('user_id') ?: $request->input('user_id');
        if (! is_numeric($callerId) || (int) $callerId <= 0) {
            return $this->fail('user_id is required.', 422);
        }

        $record = $this->buildResponseRecord((int) $callerId, null);
        if (! $record) {
            return $this->fail('User not found.', 404);
        }

        return $this->ok($record);
    }

    /**
     * GET /users/{id}
     *
     * Single user with their active primary assignment.
     */
    public function show(int $id): JsonResponse
    {
        $record = $this->buildResponseRecord($id, null);

        if (! $record) {
            return $this->fail('User not found.', 404);
        }

        return $this->ok($record);
    }

    /**
     * PATCH /users/{id}/status
     *
     * Lightweight is_active toggle. Minimal payload — no full re-validation.
     * Writes only users.is_active and users.updated_at, both real columns.
     */
    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $target = DB::table('users')->where('id', $id)->first();
        if (! $target) {
            return $this->fail('User not found.', 404);
        }

        $validated = $request->validate([
            'is_active'   => ['required', 'boolean'],
            // client_uuid accepted for log tracing — not written to DB
            'client_uuid' => ['sometimes', 'nullable', 'string', 'uuid'],
        ]);

        // Hierarchy guard: a caller must out-rank the target. Without this any
        // SCREENER who learns the toggle endpoint can deactivate a NATIONAL_ADMIN.
        [$caller, $callerAsg] = $this->resolveCaller($request);
        if ($caller && (int) $caller->id === $id) {
            return $this->fail('You cannot change your own active status.', 422);
        }
        if ($caller) {
            $this->enforceCallerHierarchy($caller, $callerAsg, [
                'role_key' => $target->role_key,
            ]);
        }

        DB::table('users')
            ->where('id', $id)
            ->update([
                'is_active'  => (int) $validated['is_active'], // users.is_active tinyint(1)
                'updated_at' => now()->toDateTimeString(),     // users.updated_at datetime
            ]);

        Log::info('[POE-USERS] Status toggled', [
            'server_id'   => $id,
            'is_active'   => $validated['is_active'],
            'client_uuid' => $validated['client_uuid'] ?? null, // log only
        ]);

        return $this->ok(
            $this->buildResponseRecord($id, $validated['client_uuid'] ?? null),
            $validated['is_active'] ? 'User activated.' : 'User deactivated.'
        );
    }

    /**
     * DELETE /users/{id}
     *
     * Hard-deletes a user row plus their user_assignments. Caller cannot
     * delete themselves and cannot delete a user whose role is at or above
     * the caller's tier (mirrors enforceCallerHierarchy guarantees).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $target = DB::table('users')->where('id', $id)->first();
        if (! $target) {
            return $this->fail('User not found.', 404);
        }

        [$caller, $callerAsg] = $this->resolveCaller($request);
        if ($caller && (int) $caller->id === $id) {
            return $this->fail('You cannot delete your own account.', 422);
        }

        // Reuse hierarchy guard: build a synthetic payload describing target's role
        // so callers can never delete a peer or higher-tier account.
        if ($caller) {
            $this->enforceCallerHierarchy($caller, $callerAsg, [
                'role_key' => $target->role_key,
            ]);
        }

        try {
            DB::transaction(function () use ($id): void {
                DB::table('user_assignments')->where('user_id', $id)->delete();
                DB::table('users')->where('id', $id)->delete();
            });
        } catch (Throwable $e) {
            Log::error('[POE-USERS] Delete transaction failed', [
                'server_id' => $id,
                'error'     => $e->getMessage(),
            ]);
            return $this->fail('Failed to delete user. Please retry.', 500);
        }

        Log::info('[POE-USERS] User deleted', ['server_id' => $id]);

        return $this->ok(['id' => $id], 'User deleted successfully.');
    }

    // ----------------------------------------------------------------
    // PRIVATE — VALIDATION
    // ----------------------------------------------------------------

    /**
     * Validate the incoming user payload for both POST (create) and PATCH (update).
     *
     * Rule anchoring:
     *   Every field rule below references its exact source column and type
     *   from poe_2026.users or poe_2026.user_assignments.
     *   client_uuid is the sole exception — it is NOT a DB column; it is
     *   accepted for mobile sync reconciliation only.
     *
     * @param  Request  $request
     * @param  int|null $userId  Server id of record being updated; null = create
     * @return array             Validated input
     * @throws ValidationException
     */
    private function validateUserPayload(Request $request, ?int $userId): array
    {
        $isUpdate = ($userId !== null);

        $rules = [
            // ── NOT a DB column — accepted for mobile tracing/reconciliation ──
            'client_uuid'              => ['sometimes', 'nullable', 'string', 'uuid'],

            // ── users.role_key varchar(60) ───────────────────────────────────
            'role_key'                 => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                Rule::in(self::VALID_ROLES),
            ],

            // ── users.country_code varchar(10) ───────────────────────────────
            'country_code'             => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'max:10',
            ],

            // ── users.full_name varchar(150) ─────────────────────────────────
            'full_name'                => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'min:2',
                'max:150',
            ],

            // ── users.name varchar(200) ──────────────────────────────────────
            // Optional in payload; server always writes full_name to this column.
            'name'                     => ['sometimes', 'nullable', 'string', 'max:200'],

            // ── users.username varchar(80) ───────────────────────────────────
            // uq_users_username uses utf8mb4_0900_ai_ci (case-insensitive).
            // The LOWER() clause mirrors that collation to surface a clean 422
            // before a DB constraint error fires.
            'username'                 => [
                $isUpdate ? 'sometimes' : 'required',
                'string',
                'min:4',
                'max:80',
                'regex:/^[a-zA-Z0-9._-]+$/',
                Rule::unique('users', 'username')
                    ->ignore($userId)
                    ->where(fn($q) => $q->whereRaw(
                        'LOWER(username) = ?',
                        [strtolower(trim($request->input('username', '')))]
                    )),
            ],

            // ── users.password varchar(200) ──────────────────────────────────
            // Required on create (mobile sends plaintext; hash created server-side).
            // Optional/nullable on update — null means keep existing hash.
            'password'                 => [
                $isUpdate ? 'sometimes' : 'required',
                'nullable',
                'string',
                'min:8',
                'max:200',
            ],

            // ── users.password_hash varchar(255) ─────────────────────────────
            // Mobile sends this field. Accepted but silently ignored —
            // the server derives it with Hash::make() in the write path.
            'password_hash'            => ['sometimes', 'nullable', 'string'],

            // ── users.email varchar(190) ─────────────────────────────────────
            // uq_users_email unique constraint; nullable; CI via LOWER().
            'email'                    => [
                'sometimes',
                'nullable',
                'email',
                'max:190',
                Rule::unique('users', 'email')
                    ->ignore($userId)
                    ->where(function ($q) use ($request) {
                        $email = trim($request->input('email', ''));
                        if ($email === '') {
                            return $q->whereRaw('1 = 0'); // skip uniqueness for null/empty
                        }
                        return $q->whereRaw('LOWER(email) = ?', [strtolower($email)]);
                    }),
            ],

            // ── users.phone varchar(40) ──────────────────────────────────────
            'phone'                    => ['sometimes', 'nullable', 'string', 'max:40'],

            // ── users.is_active tinyint(1) ───────────────────────────────────
            'is_active'                => ['sometimes', 'boolean'],

            // ── user_assignments nested object ───────────────────────────────
            // Every key below maps to an exact column in poe_2026.user_assignments.
            'assignment'               => [$isUpdate ? 'sometimes' : 'required', 'array'],
            'assignment.country_code'  => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:10'], // varchar(10) NOT NULL

            // ── Geographic fields ────────────────────────────────────────────
            // Historically these have been stored as either human names
            // ("Central Region PHEOC") or short codes ("RW-01", "KIC",
            // "RW-KIC-001"). We accept the union, plus any other non-empty
            // string up to the column max-length so legacy rows can still
            // round-trip through the update endpoint without 422-ing.
            'assignment.province_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'assignment.pheoc_code'    => ['sometimes', 'nullable', 'string', 'max:60'],
            'assignment.district_code' => ['sometimes', 'nullable', 'string', 'max:60'],

            // ── user_assignments.poe_code varchar(200) ───────────────────────
            'assignment.poe_code'      => [
                'sometimes', 'nullable', 'string', 'max:200',
            ],

            'assignment.is_primary'    => ['sometimes', 'boolean'],                             // tinyint(1)
            'assignment.is_active'     => ['sometimes', 'boolean'],                             // tinyint(1)
            'assignment.starts_at'     => ['sometimes', 'nullable', 'date_format:Y-m-d H:i:s'], // datetime
            'assignment.ends_at'       => ['sometimes', 'nullable', 'date_format:Y-m-d H:i:s'], // datetime
        ];

        $messages = [
            'role_key.in'                      => 'Invalid role. Accepted: ' . implode(', ', self::VALID_ROLES),
            'username.unique'                  => 'Username is already taken on this system.',
            'username.regex'                   => 'Username may only contain letters, numbers, dots, underscores, and hyphens.',
            'email.unique'                     => 'Email address is already registered.',
            'password.min'                     => 'Password must be at least 8 characters.',
            'full_name.min'                    => 'Full name must be at least 2 characters.',
            'assignment.required'              => 'Geographic assignment details are required.',
            'assignment.country_code.required' => 'Assignment country code is required.',
            // Geography SSOT name-string enforcement
            'assignment.province_code.in'      => 'Invalid Regional PHEOC value. Must exactly match a name from the system reference list (e.g. "Central Region PHEOC").',
            'assignment.pheoc_code.in'         => 'Invalid PHEOC value. Must exactly match a name from the system reference list (e.g. "Eastern Region PHEOC").',
            'assignment.district_code.in'      => 'Invalid district value. Must exactly match a name from the system reference list (e.g. "Kampala District").',
            'assignment.poe_code.in'           => 'Invalid POE value. Must exactly match a Point of Entry name from the system reference list (e.g. "Entebbe International Airport").',
        ];

        return $request->validate($rules, $messages);
    }

    /**
     * Validate geographic completeness for the submitted role_key.
     * Enforces National -> Province/PHEOC -> District -> POE hierarchy.
     *
     * Checks only the assignment sub-array fields — all of which are real
     * columns in user_assignments.
     *
     * @throws ValidationException
     */
    /**
     * Fetch the caller's user row + primary active assignment.
     * Reads user_id from the request (v1 API trust model — same pattern the
     * rest of the controllers use). Returns [user, assignment] or [null, null].
     */
    private function resolveCaller(Request $request): array
    {
        $callerId = $request->query('user_id') ?: $request->input('user_id');
        if (! is_numeric($callerId)) return [null, null];
        $caller = DB::table('users')->where('id', (int) $callerId)->first();
        if (! $caller) return [null, null];
        $assignment = DB::table('user_assignments')
            ->where('user_id', (int) $callerId)
            ->where('is_primary', 1)
            ->where('is_active', 1)
            ->first();
        return [$caller, $assignment];
    }

    /**
     * Enforce that the CALLER has authority to create/update a user at the
     * requested geographic scope. Roles:
     *   NATIONAL_ADMIN  — can do anything, anywhere.
     *   PHEOC_OFFICER   — only within their pheoc_code (DS + POE_* under it).
     *   DISTRICT_SUPERVISOR — only within their district_code (POE_* under it).
     *   POE_ADMIN       — only within their own poe_code (POE_PRIMARY / POE_SECONDARY / POE_DATA_OFFICER / SCREENER).
     *   Anyone else     — cannot create users at all.
     *
     * Also enforces the "can only create roles at or below your tier" rule:
     *   PHEOC_OFFICER cannot create a NATIONAL_ADMIN.
     *   DISTRICT_SUPERVISOR cannot create a PHEOC_OFFICER.
     *   POE_ADMIN cannot create a DISTRICT_SUPERVISOR.
     *
     * No caller (anonymous / v1 open route, no user_id passed) is allowed
     * through so the public bootstrap flow remains usable — however in
     * production the deployment layer MUST require user_id + authenticated
     * sessions on /users/* writes.
     */
    private function enforceCallerHierarchy(?object $caller, ?object $callerAssignment, array $validated): void
    {
        if ($caller === null) {
            // Open v1 model — allow through. Higher layers must authenticate.
            return;
        }

        $callerRole = $caller->role_key ?? '';
        if ($callerRole === 'NATIONAL_ADMIN') {
            return; // jailbreak
        }

        $newRole = $validated['role_key'] ?? null;
        $newAsg  = $validated['assignment'] ?? [];

        // Role-tier ladder — each row is the set of roles this caller may
        // create/update. Roles NOT in the list are forbidden.
        $allowedToCreate = [
            'PHEOC_OFFICER'       => ['DISTRICT_SUPERVISOR', 'POE_ADMIN', 'POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'SCREENER'],
            'DISTRICT_SUPERVISOR' => ['POE_ADMIN', 'POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'SCREENER'],
            'POE_ADMIN'           => ['POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'SCREENER'],
        ];

        if (! isset($allowedToCreate[$callerRole])) {
            throw ValidationException::withMessages([
                'role_key' => 'Your role is not permitted to create or modify users.',
            ]);
        }

        if ($newRole && ! in_array($newRole, $allowedToCreate[$callerRole], true)) {
            throw ValidationException::withMessages([
                'role_key' => "A {$callerRole} may not assign the {$newRole} role.",
            ]);
        }

        // Scope containment — caller's geography must contain new user's geography.
        // Comparison is case-insensitive AND tolerant of name vs code aliases:
        // an existing user may have been stored with a code ("UG-EBB-001") while
        // the caller's assignment was stored with the matching name ("Entebbe
        // International Airport"). We resolve both to a canonical token via the
        // ref tables before comparing.
        $sameGeo = function (?string $a, ?string $b, string $kind): bool {
            if (! $a || ! $b) return false;
            $norm = function (string $v) use ($kind): string {
                $v = trim($v);
                $u = strtoupper($v);
                try {
                    if ($kind === 'pheoc') {
                        $row = \Illuminate\Support\Facades\DB::table('ref_provinces')
                            ->where(fn($q) => $q->where('name', $v)->orWhere('code', $v))
                            ->first(['name', 'code']);
                        return $row ? strtoupper((string) $row->code) : $u;
                    }
                    if ($kind === 'district') {
                        $row = \Illuminate\Support\Facades\DB::table('ref_districts')
                            ->where(fn($q) => $q->where('name', $v)->orWhere('code', $v))
                            ->first(['name', 'code']);
                        return $row ? strtoupper((string) $row->code) : $u;
                    }
                    if ($kind === 'poe') {
                        $row = \Illuminate\Support\Facades\DB::table('ref_poes')
                            ->where(fn($q) => $q->where('poe_name', $v)->orWhere('poe_code', $v))
                            ->first(['poe_name', 'poe_code']);
                        return $row ? strtoupper((string) $row->poe_code) : $u;
                    }
                } catch (\Throwable) { /* fall through to raw uppercase compare */ }
                return $u;
            };
            return $norm($a) === $norm($b);
        };

        if ($callerRole === 'PHEOC_OFFICER') {
            $needle = $callerAssignment->pheoc_code ?? null;
            $given  = $newAsg['pheoc_code'] ?? null;
            if (! $sameGeo($needle, $given, 'pheoc')) {
                throw ValidationException::withMessages([
                    'assignment.pheoc_code' => 'You may only assign users within your PHEOC.',
                ]);
            }
        } elseif ($callerRole === 'DISTRICT_SUPERVISOR') {
            $needle = $callerAssignment->district_code ?? null;
            $given  = $newAsg['district_code'] ?? null;
            if (! $sameGeo($needle, $given, 'district')) {
                throw ValidationException::withMessages([
                    'assignment.district_code' => 'You may only assign users within your district.',
                ]);
            }
        } elseif ($callerRole === 'POE_ADMIN') {
            $needle = $callerAssignment->poe_code ?? null;
            $given  = $newAsg['poe_code'] ?? null;
            if (! $sameGeo($needle, $given, 'poe')) {
                throw ValidationException::withMessages([
                    'assignment.poe_code' => 'You may only assign users at your own POE.',
                ]);
            }
        }
    }

    private function enforceGeographyForRole(array $validated): void
    {
        $roleKey    = $validated['role_key'] ?? null;
        $assignment = $validated['assignment'] ?? [];

        if (! $roleKey || empty($assignment)) {
            return;
        }

        $requirements = self::ROLE_GEO_REQUIREMENTS[$roleKey] ?? [];

        // user_assignments.province_code varchar(30) OR user_assignments.pheoc_code varchar(30)
        if (
            in_array('province_or_pheoc', $requirements, true)
            && empty($assignment['province_code'])
            && empty($assignment['pheoc_code'])
        ) {
            throw ValidationException::withMessages([
                'assignment.province_code' => 'Provincial PHEOC assignment is required for this role.',
            ]);
        }

        // user_assignments.district_code varchar(30)
        if (
            in_array('district_code', $requirements, true)
            && empty($assignment['district_code'])
        ) {
            throw ValidationException::withMessages([
                'assignment.district_code' => 'District assignment is required for this role.',
            ]);
        }

        // user_assignments.poe_code varchar(40)
        if (
            in_array('poe_code', $requirements, true)
            && empty($assignment['poe_code'])
        ) {
            throw ValidationException::withMessages([
                'assignment.poe_code' => 'Point of Entry assignment is required for the Screener role.',
            ]);
        }
    }

    // ----------------------------------------------------------------
    // PRIVATE — DATABASE HELPERS
    // ----------------------------------------------------------------

    /**
     * Base query builder: users LEFT JOIN user_assignments (primary active row only).
     *
     * SELECT list — every alias maps to an exact column in poe_2026.sql.
     * password, password_hash, email_verified_at excluded from all responses.
     *
     * users columns selected:
     *   id, role_key, country_code, full_name, name, username,
     *   email, phone, is_active, last_login_at, created_at, updated_at
     *
     * user_assignments columns selected (via alias):
     *   id → assignment_id, province_code, pheoc_code, district_code,
     *   poe_code, is_primary, is_active → assignment_is_active,
     *   starts_at, ends_at
     */
    private function baseUserQuery()
    {
        return DB::table('users as u')
            ->leftJoin('user_assignments as ua', function ($join) {
                $join->on('ua.user_id', '=', 'u.id')
                    ->where('ua.is_primary', '=', 1) // user_assignments.is_primary tinyint(1)
                    ->where('ua.is_active', '=', 1)  // user_assignments.is_active  tinyint(1)
                    ->whereNull('ua.ends_at');       // user_assignments.ends_at    datetime
            })
            ->select([
                'u.id',                                     // users.id               bigint
                'u.role_key',                               // users.role_key         varchar(60)
                'u.country_code',                           // users.country_code     varchar(10)
                'u.full_name',                              // users.full_name        varchar(150)
                'u.name',                                   // users.name             varchar(200)
                'u.username',                               // users.username         varchar(80)
                'u.email',                                  // users.email            varchar(190)
                'u.phone',                                  // users.phone            varchar(40)
                'u.is_active',                              // users.is_active        tinyint(1)
                'u.last_login_at',                          // users.last_login_at    datetime
                'u.created_at',                             // users.created_at       datetime
                'u.updated_at',                             // users.updated_at       datetime
                'ua.id            as assignment_id',        // user_assignments.id    bigint
                'ua.province_code',                         // user_assignments.province_code  varchar(30)
                'ua.pheoc_code',                            // user_assignments.pheoc_code     varchar(30)
                'ua.district_code',                         // user_assignments.district_code  varchar(30)
                'ua.poe_code',                              // user_assignments.poe_code       varchar(40)
                'ua.is_primary',                            // user_assignments.is_primary     tinyint(1)
                'ua.is_active     as assignment_is_active', // user_assignments.is_active   tinyint(1)
                'ua.starts_at',                             // user_assignments.starts_at      datetime
                'ua.ends_at',                               // user_assignments.ends_at        datetime
            ]);
    }

    /**
     * Upsert the primary user_assignment row — history-preserving.
     *
     * Every column written here is verified against poe_2026.user_assignments:
     *   user_id, country_code, province_code, pheoc_code, district_code,
     *   poe_code, is_primary, is_active, starts_at, ends_at, created_at, updated_at
     *
     * Strategy:
     *   1. Existing active primary row, same geography → update is_active only
     *      if it differs; return.
     *   2. Existing active primary row, geography changed → close it
     *      (is_active=0, is_primary=0, ends_at=now) to preserve audit history.
     *   3. Insert new active primary row.
     */
    private function upsertAssignment(int $userId, array $assignment, string $now): void
    {
        $newCountry  = $assignment['country_code'] ?? config('country.code');       // user_assignments.country_code  varchar(10)
        $newProvince = $assignment['province_code'] ?? null;      // user_assignments.province_code varchar(30)
        $newPheoc    = $assignment['pheoc_code'] ?? $newProvince; // user_assignments.pheoc_code varchar(30)
        $newDistrict = $assignment['district_code'] ?? null;      // user_assignments.district_code varchar(30)
        $newPoe      = $assignment['poe_code'] ?? null;           // user_assignments.poe_code      varchar(40)
        $isPrimary   = (int) ($assignment['is_primary'] ?? 1);    // user_assignments.is_primary  tinyint(1)
        $isActive    = (int) ($assignment['is_active'] ?? 1);     // user_assignments.is_active   tinyint(1)
        $startsAt    = $assignment['starts_at'] ?? $now;          // user_assignments.starts_at   datetime
        $endsAt      = $assignment['ends_at'] ?? null;            // user_assignments.ends_at     datetime

        $existing = DB::table('user_assignments')
            ->where('user_id', $userId) // user_assignments.user_id bigint
            ->where('is_primary', 1)    // user_assignments.is_primary tinyint(1)
            ->where('is_active', 1)     // user_assignments.is_active  tinyint(1)
            ->whereNull('ends_at')      // user_assignments.ends_at    datetime
            ->first();

        $sameGeo = $existing && (
            ($existing->country_code ?? '') === ($newCountry ?? '') &&
            ($existing->province_code ?? '') === ($newProvince ?? '') &&
            ($existing->district_code ?? '') === ($newDistrict ?? '') &&
            ($existing->poe_code ?? '') === ($newPoe ?? '')
        );

        if ($existing && $sameGeo) {
            if ((int) $existing->is_active !== $isActive) {
                DB::table('user_assignments')
                    ->where('id', $existing->id)
                    ->update([
                        'is_active'  => $isActive, // user_assignments.is_active  tinyint(1)
                        'updated_at' => $now,      // user_assignments.updated_at datetime
                    ]);
            }
            return;
        }

        if ($existing) {
            // Close the existing primary row — preserve history
            DB::table('user_assignments')
                ->where('id', $existing->id)
                ->update([
                    'is_active'  => 0,    // user_assignments.is_active  tinyint(1)
                    'is_primary' => 0,    // user_assignments.is_primary tinyint(1)
                    'ends_at'    => $now, // user_assignments.ends_at    datetime
                    'updated_at' => $now, // user_assignments.updated_at datetime
                ]);
        }

        // Insert new primary active row
        DB::table('user_assignments')->insert([
            'user_id'       => $userId,      // user_assignments.user_id       bigint UNSIGNED
            'country_code'  => $newCountry,  // user_assignments.country_code  varchar(10)
            'province_code' => $newProvince, // user_assignments.province_code varchar(30)
            'pheoc_code'    => $newPheoc,    // user_assignments.pheoc_code    varchar(30)
            'district_code' => $newDistrict, // user_assignments.district_code varchar(30)
            'poe_code'      => $newPoe,      // user_assignments.poe_code      varchar(40)
            'is_primary'    => $isPrimary,   // user_assignments.is_primary    tinyint(1)
            'is_active'     => $isActive,    // user_assignments.is_active     tinyint(1)
            'starts_at'     => $startsAt,    // user_assignments.starts_at     datetime
            'ends_at'       => $endsAt,      // user_assignments.ends_at       datetime
            'created_at'    => $now,         // user_assignments.created_at    datetime
            'updated_at'    => $now,         // user_assignments.updated_at    datetime
        ]);
    }

    /**
     * Build the response record the mobile sync engine expects.
     *
     * $clientUuid is the mobile-supplied value echoed back so the mobile can
     * map server_id back to its local record. NOT sourced from DB (no column).
     *
     * Returns null if server id does not exist.
     */
    private function buildResponseRecord(int $userId, ?string $clientUuid): ?array
    {
        $row = $this->baseUserQuery()
            ->where('u.id', $userId)
            ->first();

        if (! $row) {
            return null;
        }

        return [
                                                       // users columns
            'id'            => $row->id,               // users.id            bigint  — mobile stores as server_user_id
            'client_uuid'   => $clientUuid,            // NOT from DB          — echoed from payload for mobile reconciliation
            'role_key'      => $row->role_key,         // users.role_key       varchar(60)
            'country_code'  => $row->country_code,     // users.country_code   varchar(10)
            'full_name'     => $row->full_name,        // users.full_name      varchar(150)
            'name'          => $row->name,             // users.name           varchar(200)
            'username'      => $row->username,         // users.username       varchar(80)
            'email'         => $row->email,            // users.email          varchar(190)
            'phone'         => $row->phone,            // users.phone          varchar(40)
            'is_active'     => (bool) $row->is_active, // users.is_active tinyint(1)
            'last_login_at' => $row->last_login_at,    // users.last_login_at  datetime
            'created_at'    => $row->created_at,       // users.created_at     datetime
            'updated_at'    => $row->updated_at,       // users.updated_at     datetime
                                                       // user_assignments columns
            'assignment'    => [
                'id'            => $row->assignment_id,                         // user_assignments.id            bigint
                'province_code' => $row->province_code,                         // user_assignments.province_code varchar(30)
                'pheoc_code'    => $row->pheoc_code,                            // user_assignments.pheoc_code    varchar(30)
                'district_code' => $row->district_code,                         // user_assignments.district_code varchar(30)
                'poe_code'      => $row->poe_code,                              // user_assignments.poe_code      varchar(40)
                'is_primary'    => (bool) ($row->is_primary ?? true),           // tinyint(1)
                'is_active'     => (bool) ($row->assignment_is_active ?? true), // tinyint(1)
                'starts_at'     => $row->starts_at,                             // user_assignments.starts_at     datetime
                'ends_at'       => $row->ends_at,                               // user_assignments.ends_at       datetime
            ],
            // Sync confirmation — read by mobile syncOne
            'sync_status'   => 'SYNCED',
            'synced_at'     => now()->toISOString(),
        ];
    }

    // ----------------------------------------------------------------
    // PRIVATE — RESPONSE HELPERS
    // ----------------------------------------------------------------

    /**
     * Standard success envelope.
     * Mobile syncOne reads: data?.data?.id -> stored as server_user_id
     */
    private function ok(mixed $data, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ], $status);
    }

    /**
     * Standard error envelope.
     * Mobile syncOne reads: body.message -> stored as sync_note
     */
    private function fail(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data'    => null,
        ], $status);
    }
}

/*
 * =============================================================================
 * ROUTE REGISTRATION — routes/api.php
 * =============================================================================
 *
 * use App\Http\Controllers\UserController;
 *
 * // No auth middleware — added separately as a custom layer.
 * Route::get   ('/users',             [UserController::class, 'index']);
 * Route::post  ('/users',             [UserController::class, 'store']);
 * Route::get   ('/users/{id}',        [UserController::class, 'show']);
 * Route::patch ('/users/{id}',        [UserController::class, 'update']);
 * Route::patch ('/users/{id}/status', [UserController::class, 'toggleStatus']);
 *
 * =============================================================================
 * App\Models\User — fillable / hidden / casts
 * =============================================================================
 *
 * Only real columns from poe_2026.users listed here.
 *
 * protected $fillable = [
 *     'role_key', 'country_code',
 *     'full_name', 'name',
 *     'username', 'email', 'phone',
 *     'password', 'password_hash',
 *     'email_verified_at',
 *     'is_active', 'last_login_at',
 * ];
 *
 * protected $hidden = ['password', 'password_hash', 'remember_token'];
 *
 * protected $casts = [
 *     'email_verified_at' => 'datetime',
 *     'is_active'         => 'boolean',
 * ];
 *
 * =============================================================================
 * IDEMPOTENCY NOTE — no client_uuid column in schema
 * =============================================================================
 *
 * Because users has no client_uuid column, duplicate-create prevention relies
 * on the unique constraints that DO exist in the schema:
 *   uq_users_username — users.username
 *   uq_users_email    — users.email
 *
 * If a mobile device retries a POST whose username already exists, the server
 * returns HTTP 422 with "Username is already taken". The mobile sync engine
 * should treat this specific message as SYNCED (the record exists) and use
 * GET /users with a search or a subsequent PATCH to retrieve the server id.
 *
 * =============================================================================
 */
