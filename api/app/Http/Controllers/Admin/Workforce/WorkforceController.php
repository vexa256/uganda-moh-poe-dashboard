<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Workforce;

use App\Http\Controllers\Controller;
use App\Support\Scope\ScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin · Workforce — UNIFIED surface.
 *
 * Replaces the three split surfaces (Users / Roles / Assignments) with one
 * page + one atomic create wizard. The legacy per-surface controllers are
 * retained so per-row operations (suspend, reset password, end assignment,
 * etc.) still work; this controller is the consolidation entry point.
 *
 * Locked to Uganda: the wizard never writes any country_code other than the
 * tenant's configured ISO-2 (config('country.iso2') = 'UG') for users +
 * user_assignments. ref_poes lookup uses the legacy full-name encoding
 * (config('country.legacy_code') = 'Uganda') because that's what's actually
 * in the table — we read in legacy form, write in ISO-2 form. This is the
 * single boundary where the two encodings meet.
 *
 * Design tenets:
 *   - One round trip per render: `data()` returns the full payload (users +
 *     role registry + scoped jurisdiction picker meta + tabs counts) so the
 *     view boots instantly with no chained fetches.
 *   - One transaction per create: `wizard()` runs user + user_assignments
 *     insert + audit append in a single DB::transaction. Roll back on any
 *     failure — never leave an orphan user with no jurisdiction or an
 *     assignment pointing at a nonexistent user.
 *   - role_key is the single source of truth. users.account_type is mirrored
 *     from role_key for backward compatibility with code that still reads
 *     it; the wizard never exposes account_type as a separate decision.
 *   - Guidance is engineering, not prose. The view embeds one-line "what
 *     happens next" hints next to each control; no walls of text.
 */
final class WorkforceController extends Controller
{
    private const INVITE_TTL_DAYS = 7;
    private const INVITE_MODES    = ['credential', 'email'];

    /** ISO-2 tenant country — used for every NEW users.country_code + user_assignments.country_code write. */
    private static function tenantCountryIso2(): string
    {
        return (string) (config('country.iso2') ?: 'UG');
    }

    /** Legacy full-name encoding — used ONLY for reading ref_poes.country_code. */
    private static function tenantCountryLegacy(): string
    {
        return (string) (config('country.legacy_code') ?: 'Uganda');
    }

    /* ─────────────────────────── views ─────────────────────────── */

    public function index(Request $r)
    {
        return view('admin.workforce.index', [
            'pageTitle' => 'Workforce',
        ]);
    }

    /* ─────────────────────────── data ─────────────────────────── */

    /**
     * Single-shot payload for the unified view. Returns users + roles +
     * jurisdictions + tab counts in one response so the page does not need
     * chained fetches. All queries are scope-aware via ScopeFilter; a
     * PHEOC officer never sees users / POEs outside their province.
     */
    public function data(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $tab   = strtolower((string) $r->query('status', 'active'));
            $q     = trim((string) $r->query('q', ''));
            $role  = trim((string) $r->query('role_key', ''));
            $poe   = trim((string) $r->query('poe', ''));

            // ── USERS (scoped) ─────────────────────────────────────────
            $base = function () use ($scope) {
                return ScopeFilter::applyToUsers(DB::table('users'), $scope, 'users');
            };

            $usersQ = $base();
            $this->applyTab($usersQ, $tab);
            if ($role !== '') { $usersQ->where('role_key', $role); }
            if ($poe !== '') {
                $usersQ->whereExists(function ($w) use ($poe) {
                    $w->select(DB::raw(1))->from('user_assignments as ua_f')
                      ->whereColumn('ua_f.user_id', 'users.id')
                      ->where('ua_f.is_active', 1)->whereNull('ua_f.ends_at')
                      ->where('ua_f.poe_code', $poe);
                });
            }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $usersQ->where(function ($w) use ($like) {
                    $w->where('full_name', 'like', $like)
                      ->orWhere('email',   'like', $like)
                      ->orWhere('username','like', $like)
                      ->orWhere('phone',   'like', $like);
                });
            }
            $userRows = $usersQ
                ->select([
                    'id','full_name','username','email','phone','role_key','account_type',
                    'is_active','suspended_at','suspension_reason','last_login_at',
                    'must_change_password','two_factor_confirmed_at','locked_until',
                    'invitation_token_hash','invitation_accepted_at','created_at',
                ])
                ->orderByDesc('id')->limit(500)->get();

            $userIds = $userRows->pluck('id')->all();
            $assigns = $userIds
                ? DB::table('user_assignments')->whereIn('user_id', $userIds)
                    ->where('is_active', 1)->whereNull('ends_at')
                    ->orderByDesc('is_primary')->orderBy('id')->get()->groupBy('user_id')
                : collect();

            $tabs = [
                'active'    => (clone $base())->where('is_active', 1)->whereNull('suspended_at')->count(),
                'invited'   => (clone $base())->whereNotNull('invitation_token_hash')->whereNull('invitation_accepted_at')->count(),
                'suspended' => (clone $base())->whereNotNull('suspended_at')->count(),
                'inactive'  => (clone $base())->where('is_active', 0)->whereNull('suspended_at')->count(),
                'all'       => (clone $base())->count(),
            ];

            // ── ROLES (full registry — small set) ──────────────────────
            $roles = DB::table('role_registry')->where('is_active', 1)
                ->orderBy('display_name')
                ->get(['role_key','display_name','scope_level','description']);

            // ── JURISDICTION META for wizard picker ────────────────────
            $provQ = ScopeFilter::applyToProvinces(DB::table('ref_provinces')->whereNull('deleted_at'), $scope);
            $provinces = $provQ->orderBy('name')->pluck('name')->all();

            $distQ = ScopeFilter::applyToDistricts(DB::table('ref_districts')->whereNull('deleted_at'), $scope);
            $districts = $distQ->select('name','province_id')->orderBy('name')->get()
                ->map(fn ($d) => ['name' => (string) $d->name, 'province_id' => (int) $d->province_id])->all();

            $poeQ = DB::table('ref_poes')->where('country_code', self::tenantCountryLegacy())->whereNull('deleted_at');
            $poeQ = ScopeFilter::applyToPoes($poeQ, $scope);
            $poes = $poeQ->orderBy('display_order')->orderBy('poe_name')
                ->get(['poe_code','poe_name','admin_level_1','district'])
                ->map(fn ($p) => [
                    'poe_code' => (string) $p->poe_code,
                    'poe_name' => (string) $p->poe_name,
                    'province' => (string) $p->admin_level_1,
                    'district' => (string) $p->district,
                ])->all();

            return $this->ok([
                'users'         => $userRows->map(fn ($u) => $this->castUser($u, $assigns[$u->id] ?? collect()))->all(),
                'roles'         => $roles,
                'provinces'     => $provinces,
                'districts'     => $districts,
                'poes'          => $poes,
                'country_iso2'  => self::tenantCountryIso2(),
                'country_label' => self::tenantCountryLegacy(),
                'invite_modes'  => self::INVITE_MODES,
                'invite_ttl_days' => self::INVITE_TTL_DAYS,
            ], 'Workforce payload.', [
                'tabs'        => $tabs,
                'scope_label' => $scope['label'] ?? null,
                'scope_super' => ScopeFilter::isSuper($scope),
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    /* ─────────────────────────── wizard ─────────────────────────── */

    /**
     * ATOMIC create: user row + primary user_assignments row + audit append
     * in a single transaction. Mirrors the legacy split flow but eliminates
     * the orphan-user window where a user existed without an assignment
     * (which broke ScopeFilter-based authorization on every subsequent
     * request from that user).
     *
     * Payload contract:
     *   full_name        string  required
     *   username         string  required, unique
     *   email            string  required, unique, valid email
     *   phone            string  optional
     *   role_key         string  required, must exist in role_registry.is_active=1
     *   invite_mode      string  'credential' (default) | 'email'
     *   jurisdiction     object  required when role.scope_level != NATIONAL|SELF
     *     - poe_code     string  required for POE-scoped roles (POE_*)
     *     - district     string  required for DISTRICT roles (auto-derived from poe_code if present)
     *     - province     string  required for PHEOC roles    (auto-derived from district / poe if present)
     *
     * Returns: the created user (cast) + assignment + one-time temp_password
     * (credential mode) OR invite_url (email mode). Caller dismisses modal
     * and re-fetches data().
     */
    public function wizard(Request $r): JsonResponse
    {
        $admin   = (int) (auth()->id() ?? 0);
        $payload = $r->all();

        // ── Validate identity ─────────────────────────────────────────
        foreach (['full_name', 'username', 'email', 'role_key'] as $f) {
            if (! isset($payload[$f]) || trim((string) $payload[$f]) === '') {
                return $this->err(422, "Field '{$f}' is required.", ['hint' => 'The wizard collected this on Step 1 or 2 — re-open and retry.']);
            }
        }

        $fullName = trim((string) $payload['full_name']);
        $username = trim((string) $payload['username']);
        $email    = trim((string) $payload['email']);
        $phone    = trim((string) ($payload['phone'] ?? '')) ?: null;
        $roleKey  = trim((string) $payload['role_key']);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->err(422, 'Email address is malformed.');
        }

        $role = DB::table('role_registry')->where('role_key', $roleKey)->where('is_active', 1)->first();
        if (! $role) {
            return $this->err(422, 'Role does not exist or is inactive.', [
                'role_key'    => $roleKey,
                'valid_roles' => DB::table('role_registry')->where('is_active', 1)->pluck('role_key')->all(),
            ]);
        }

        // ── Uniqueness ────────────────────────────────────────────────
        if (DB::table('users')->where('username', $username)->exists()) {
            return $this->err(409, 'That username is already taken.', ['username' => $username]);
        }
        if (DB::table('users')->where('email', $email)->exists()) {
            return $this->err(409, 'That email is already registered.', ['email' => $email]);
        }

        // ── Resolve + validate jurisdiction by role scope_level ───────
        $jurisdiction = (array) ($payload['jurisdiction'] ?? []);
        $assignmentSpec = $this->resolveJurisdictionForRole((string) $role->scope_level, $jurisdiction);
        if ($assignmentSpec instanceof JsonResponse) {
            return $assignmentSpec;
        }

        // ── Invite mode + credentials prep ────────────────────────────
        $mode = strtolower((string) ($payload['invite_mode'] ?? 'credential'));
        if (! in_array($mode, self::INVITE_MODES, true)) {
            return $this->err(422, 'Invalid invite mode.', ['allowed' => self::INVITE_MODES]);
        }

        $now           = Carbon::now();
        $tempPassword  = null;
        $invitePlain   = null;
        $inviteUrl     = null;
        $countryIso2   = self::tenantCountryIso2();

        $userInsert = [
            'full_name'    => $fullName,
            'name'         => $fullName,                // some Laravel breeze installs require non-null
            'username'     => $username,
            'email'        => $email,
            'phone'        => $phone,
            'role_key'     => $roleKey,
            'account_type' => $roleKey,                  // 2026-05-20: mirror role_key — account_type is legacy redundant
            'country_code' => $countryIso2,
            'created_by_user_id' => $admin ?: null,
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        if ($mode === 'credential') {
            $tempPassword = $this->generateTempPassword();
            $hash         = Hash::make($tempPassword);
            $userInsert['password']             = $hash;
            $userInsert['password_hash']        = $hash;
            $userInsert['must_change_password'] = 1;
            $userInsert['is_active']            = 1;
            $userInsert['password_changed_at']  = $now;
        } else {
            $invitePlain = UsersController::issueInviteToken();
            $userInsert['password']              = Hash::make(Str::random(64));
            $userInsert['password_hash']         = null;
            $userInsert['must_change_password']  = 1;
            $userInsert['is_active']             = 0;
            $userInsert['invitation_token_hash'] = hash('sha256', $invitePlain);
            $userInsert['invitation_expires_at'] = $now->copy()->addDays(self::INVITE_TTL_DAYS);
        }

        // ── ATOMIC CREATE ─────────────────────────────────────────────
        try {
            $userId = DB::transaction(function () use ($userInsert, $assignmentSpec, $admin, $countryIso2, $now, $r) {
                $userId = DB::table('users')->insertGetId($userInsert);

                // Primary assignment row. country_code locked to tenant ISO-2.
                DB::table('user_assignments')->insert(array_merge($assignmentSpec, [
                    'user_id'      => $userId,
                    'country_code' => $countryIso2,
                    'is_primary'   => 1,
                    'is_active'    => 1,
                    'starts_at'    => $now,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]));

                // Audit append — best-effort; never roll back the create over
                // an audit-log failure (the table may not exist on a fresh
                // install). Catch + log inside.
                try {
                    DB::table('user_audit_log')->insert([
                        'actor_user_id'  => $admin ?: null,
                        'target_user_id' => $userId,
                        'action'         => 'USER_WIZARD_CREATE',
                        'before_json'    => null,
                        'after_json'     => json_encode($this->scrub(array_merge($userInsert, ['assignment' => $assignmentSpec]))),
                        'ip'             => $r->ip(),
                        'user_agent'     => substr((string) $r->userAgent(), 0, 500),
                        'created_at'     => $now,
                    ]);
                } catch (Throwable $auditErr) {
                    Log::warning('[Workforce\\wizard][audit] '.$auditErr->getMessage());
                }

                return $userId;
            });

            if ($invitePlain !== null) {
                $inviteUrl = url('/invite/' . $invitePlain);
            }

            $userRow    = DB::table('users')->where('id', $userId)->first();
            $assignment = DB::table('user_assignments')->where('user_id', $userId)->where('is_active', 1)->first();

            return $this->ok([
                'user'           => $this->castUser($userRow, collect([$assignment])),
                'assignment'     => (array) $assignment,
                '_invite_mode'   => $mode,
                '_temp_password' => $tempPassword,   // shown once, credential mode only
                '_invite_token'  => $invitePlain,    // shown once, email mode only
                '_invite_url'    => $inviteUrl,      // shown once, email mode only
                '_invite_expires'=> $userInsert['invitation_expires_at'] ?? null,
            ], $mode === 'email' ? 'Invitation link issued.' : 'User created. Share the temporary password below.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'wizard');
        }
    }

    /* ─────────────────────────── helpers ─────────────────────────── */

    /**
     * Compute the user_assignments row spec for a given role + jurisdiction
     * input. Returns the prepared row (without user_id / country_code / is_*
     * which the caller adds) OR a 422 JsonResponse if the input violates
     * the role's scope_level requirements.
     *
     *   NATIONAL → everything null
     *   PHEOC    → province_code (+ pheoc_code mirror) required
     *   DISTRICT → district_code + province_code required
     *   POE      → poe_code required; district + province derived from ref_poes
     *   SELF     → everything null (e.g. SERVICE accounts, OBSERVER)
     */
    private function resolveJurisdictionForRole(string $scopeLevel, array $jur): array|JsonResponse
    {
        $scopeLevel = strtoupper($scopeLevel);
        $province = trim((string) ($jur['province_code'] ?? $jur['province'] ?? '')) ?: null;
        $district = trim((string) ($jur['district_code'] ?? $jur['district'] ?? '')) ?: null;
        $poe      = trim((string) ($jur['poe_code']      ?? $jur['poe']      ?? '')) ?: null;

        // NATIONAL scope ignores any provided jurisdiction — short-circuit
        // here BEFORE hitting ref_poes (the lookup would be pointless and
        // the test environment may not have the table seeded).
        if ($scopeLevel === 'NATIONAL') {
            return [
                'province_code' => null,
                'pheoc_code'    => null,
                'district_code' => null,
                'poe_code'      => null,
            ];
        }

        // If a POE was picked, derive district/province from ref_poes so the
        // wizard cannot create an inconsistent triple (e.g. a Kampala POE
        // bound to a Mbarara district). Single source of truth: ref_poes.
        if ($poe !== null) {
            $poeRow = DB::table('ref_poes')
                ->where('country_code', self::tenantCountryLegacy())
                ->where('poe_code', $poe)->whereNull('deleted_at')
                ->first(['admin_level_1','district']);
            if (! $poeRow) {
                return $this->err(422, 'POE does not exist or is outside the tenant country.', ['poe_code' => $poe]);
            }
            $district = $district ?: (string) ($poeRow->district ?? '') ?: null;
            $province = $province ?: (string) ($poeRow->admin_level_1 ?? '') ?: null;
        }

        switch ($scopeLevel) {

            case 'PHEOC':
                if (! $province) {
                    return $this->err(422, 'A province is required for a PHEOC-scoped role.', [
                        'hint' => 'Pick a province on the Jurisdiction step.',
                    ]);
                }
                return [
                    'province_code' => $province,
                    'pheoc_code'    => $province,    // pheoc mirror — see AssignmentsController parity
                    'district_code' => null,
                    'poe_code'      => null,
                ];

            case 'DISTRICT':
                if (! $district) {
                    return $this->err(422, 'A district is required for a district-scoped role.', [
                        'hint' => 'Pick a district on the Jurisdiction step (or pick a POE — the district is auto-derived).',
                    ]);
                }
                return [
                    'province_code' => $province,
                    'pheoc_code'    => $province,
                    'district_code' => $district,
                    'poe_code'      => null,
                ];

            case 'POE':
                if (! $poe) {
                    return $this->err(422, 'A point of entry is required for a POE-scoped role.', [
                        'hint' => 'Pick a POE on the Jurisdiction step. District + province are derived automatically.',
                    ]);
                }
                return [
                    'province_code' => $province,
                    'pheoc_code'    => $province,
                    'district_code' => $district,
                    'poe_code'      => $poe,
                ];

            case 'SELF':
            default:
                // Self-scoped roles (OBSERVER, etc.) don't require a jurisdiction.
                // If one was provided, persist it so observer reports filter sensibly.
                return [
                    'province_code' => $province,
                    'pheoc_code'    => $province,
                    'district_code' => $district,
                    'poe_code'      => $poe,
                ];
        }
    }

    private function applyTab($q, string $tab): void
    {
        match ($tab) {
            'active'    => $q->where('is_active', 1)->whereNull('suspended_at'),
            'invited'   => $q->whereNotNull('invitation_token_hash')->whereNull('invitation_accepted_at'),
            'suspended' => $q->whereNotNull('suspended_at'),
            'inactive'  => $q->where('is_active', 0)->whereNull('suspended_at'),
            'all'       => $q,
            default     => $q->where('is_active', 1)->whereNull('suspended_at'),
        };
    }

    private function castUser(object $u, $assignments): array
    {
        $assigns = collect($assignments)->filter()->map(fn ($a) => (array) $a)->values()->all();
        $primary = collect($assigns)->firstWhere('is_primary', 1) ?? ($assigns[0] ?? null);

        $now         = Carbon::now();
        $isLocked    = isset($u->locked_until) && $u->locked_until && Carbon::parse($u->locked_until)->isFuture();
        $isSuspended = isset($u->suspended_at) && $u->suspended_at !== null;
        $isInvited   = (! empty($u->invitation_token_hash)) && empty($u->invitation_accepted_at);
        $dormantDays = isset($u->last_login_at) && $u->last_login_at
            ? (int) round(Carbon::parse($u->last_login_at)->diffInDays($now))
            : null;

        return [
            'id'                    => (int) $u->id,
            'full_name'             => (string) $u->full_name,
            'username'              => (string) $u->username,
            'email'                 => (string) $u->email,
            'phone'                 => $u->phone,
            'role_key'              => (string) $u->role_key,
            'is_active'             => (bool) $u->is_active,
            'is_suspended'          => $isSuspended,
            'suspension_reason'     => $u->suspension_reason,
            'is_locked'             => (bool) $isLocked,
            'is_invited'            => $isInvited,
            'must_change_password'  => (bool) $u->must_change_password,
            'mfa_enabled'           => isset($u->two_factor_confirmed_at) && $u->two_factor_confirmed_at !== null,
            'last_login_at'         => $u->last_login_at,
            'dormant_days'          => $dormantDays,
            'primary_assignment'    => $primary,
            'assignments_count'     => count($assigns),
            'created_at'            => $u->created_at,
        ];
    }

    private function generateTempPassword(): string
    {
        // 12-char, mixed — short enough to read aloud once over a phone.
        return Str::lower(Str::random(2)) . Str::upper(Str::random(2)) . random_int(1000, 9999) . Str::random(4);
    }

    private function scrub(array $a): array
    {
        unset($a['password'], $a['password_hash'], $a['two_factor_secret'], $a['two_factor_recovery_codes_hash'], $a['invitation_token_hash']);
        return $a;
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    {
        $b = ['success' => true, 'message' => $message, 'data' => $data];
        if (! empty($meta)) { $b['meta'] = $meta; }
        return response()->json($b);
    }

    private function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error' => $detail], $status);
    }

    private function serverError(Throwable $e, string $ctx): JsonResponse
    {
        Log::error("[Admin\\Workforce][{$ctx}] " . $e->getMessage(), [
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
        return response()->json(['success' => false, 'message' => "Server error: {$ctx}"], 500);
    }
}
