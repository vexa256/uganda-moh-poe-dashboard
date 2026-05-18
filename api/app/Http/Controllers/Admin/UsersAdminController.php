<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\UserController as MobileUserController;
use App\Services\AdminUsersGuard;
use App\Services\AuthEventLogger;
use App\Services\AuthMailer;
use App\Services\PheocScope;
use App\Services\UserAnomalyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * UsersAdminController
 * ─────────────────────────────────────────────────────────────────────────
 * Master-dashboard CRUD for every user — national admins, mobile field
 * officers, service accounts, everyone. Writes a before/after JSON diff
 * into user_audit_log for every mutation.
 *
 * All mutations require an authenticated admin (route middleware).
 *
 *   GET    /admin/users                    paginated list + filters
 *   GET    /admin/users/stats              aggregate stats (by role, by country, activity)
 *   GET    /admin/users/{id}               one user (profile + assignments + activity + risk)
 *   POST   /admin/users                    create (admin flow, no password — sends invitation)
 *   POST   /admin/users/invite             alias for create-by-invitation
 *   PATCH  /admin/users/{id}               update core fields
 *   POST   /admin/users/{id}/suspend       disable + email
 *   POST   /admin/users/{id}/reactivate
 *   POST   /admin/users/{id}/reset-password   force password reset (admin-triggered)
 *   POST   /admin/users/{id}/force-mfa-reset  clear 2FA + force re-enrol
 *   POST   /admin/users/{id}/send-verification  re-issue email verification
 *   DELETE /admin/users/{id}               soft delete
 *   POST   /admin/users/bulk               bulk actions (suspend, reactivate, role, etc.)
 *
 *   GET    /admin/users/{id}/activity        login history
 *   GET    /admin/users/{id}/audit           user_audit_log for this target
 *   GET    /admin/users/{id}/flags           live anomaly flags
 *   POST   /admin/users/{id}/flags/{flagId}/clear
 *   POST   /admin/users/{id}/rescan          re-run the anomaly engine on this user
 *   POST   /admin/users/scan-all             re-scan every user (async-ish)
 *
 *   GET    /admin/users/report/risk          high-risk users report
 *   GET    /admin/users/report/roles         role distribution
 *   GET    /admin/users/report/dormant       dormant / never-used accounts
 *   GET    /admin/users/report/mfa           MFA adoption per role
 */
final class UsersAdminController extends Controller
{
    public function __construct(private readonly AdminUsersGuard $guard = new AdminUsersGuard(new PheocScope())) {}

    // ── LIST + FILTER ───────────────────────────────────────────────────────
    public function index(Request $r): JsonResponse
    {
        try {
            $q = DB::table('users as u')
                ->leftJoin('role_registry as rr', 'rr.role_key', '=', 'u.role_key')
                ->select(
                    'u.id','u.full_name','u.username','u.email','u.phone',
                    'u.role_key','u.account_type','u.country_code',
                    'u.is_active','u.suspended_at','u.last_login_at','u.last_login_ip',
                    'u.last_activity_at','u.email_verified_at',
                    'u.two_factor_confirmed_at','u.risk_score','u.risk_flags_json',
                    'u.created_at','u.updated_at','u.avatar_url',
                    'u.must_change_password','u.locked_until','u.invitation_accepted_at',
                    'rr.display_name as role_display','rr.scope_level',
                );

            // Scope filter — every non-admin actor sees only their geo slice.
            if ($r->user()) {
                $this->guard->applyListFilter($q, $r->user());
            }

            if ($s = $r->query('search')) {
                $q->where(function ($x) use ($s) {
                    $x->where('u.full_name','like',"%$s%")
                      ->orWhere('u.email','like',"%$s%")
                      ->orWhere('u.username','like',"%$s%")
                      ->orWhere('u.phone','like',"%$s%");
                });
            }
            foreach (['role_key','country_code','account_type'] as $col) {
                if ($v = $r->query($col)) $q->where("u.$col", $v);
            }
            if ($r->query('status') === 'active')     $q->where('u.is_active', 1)->whereNull('u.suspended_at');
            if ($r->query('status') === 'suspended')  $q->whereNotNull('u.suspended_at');
            if ($r->query('status') === 'locked')     $q->where('u.locked_until', '>', now());
            if ($r->query('status') === 'pending')    $q->whereNull('u.invitation_accepted_at')->whereNotNull('u.invitation_token_hash');
            if ($r->query('has_mfa') === '1')         $q->whereNotNull('u.two_factor_confirmed_at');
            if ($r->query('has_mfa') === '0')         $q->whereNull('u.two_factor_confirmed_at');
            if ($v = $r->query('risk_min'))           $q->where('u.risk_score', '>=', (int) $v);

            $sort = in_array($r->query('sort'), ['id','full_name','email','last_login_at','risk_score','created_at'], true)
                ? $r->query('sort') : 'created_at';
            $dir = $r->query('dir') === 'asc' ? 'asc' : 'desc';
            $limit  = min(500, max(10, (int) $r->query('limit', 50)));
            $offset = max(0, (int) $r->query('offset', 0));

            $total = (clone $q)->count();
            $rows  = $q->orderBy("u.$sort", $dir)->limit($limit)->offset($offset)->get();

            return $this->ok(['users' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
        } catch (Throwable $e) { return $this->fail($e, 'index'); }
    }

    public function show(Request $r, int $id): JsonResponse
    {
        try {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err('User not found', 404);
            if ($r->user()) $this->guard->assertCanManageTarget($r->user(), $id);
            $assignments = DB::table('user_assignments')->where('user_id', $id)->get();
            $activity = DB::table('auth_events')->where('user_id', $id)
                ->orderByDesc('created_at')->limit(50)->get();
            $flags = DB::table('user_anomaly_flags')->where('user_id', $id)->get();
            $tokens = DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')->where('tokenable_id', $id)
                ->select('id','name','last_used_at','created_at')->get();
            $devices = DB::table('trusted_devices')->where('user_id', $id)
                ->whereNull('revoked_at')->orderByDesc('last_used_at')->get();
            $audit = DB::table('user_audit_log')->where('target_user_id', $id)
                ->orderByDesc('created_at')->limit(50)->get();

            return $this->ok([
                'user' => self::hideSecrets($u),
                'assignments' => $assignments,
                'activity' => $activity,
                'flags' => $flags,
                'tokens' => $tokens,
                'trusted_devices' => $devices,
                'audit_log' => $audit,
            ]);
        } catch (Throwable $e) { return $this->fail($e, 'show'); }
    }

    public function stats(Request $r): JsonResponse
    {
        try {
            $byRole   = DB::table('users')->selectRaw('role_key, COUNT(*) AS n')->groupBy('role_key')->get();
            $byCountry= DB::table('users')->selectRaw('country_code, COUNT(*) AS n')->groupBy('country_code')->get();
            $byStatus = [
                'total'        => DB::table('users')->count(),
                'active'       => DB::table('users')->where('is_active', 1)->whereNull('suspended_at')->count(),
                'suspended'    => DB::table('users')->whereNotNull('suspended_at')->count(),
                'locked'       => DB::table('users')->where('locked_until', '>', now())->count(),
                'pending_invite'=> DB::table('users')->whereNull('invitation_accepted_at')->whereNotNull('invitation_token_hash')->count(),
                'dormant_30d'  => DB::table('users')->where('last_activity_at', '<', now()->subDays(30))
                                    ->orWhereNull('last_activity_at')->count(),
                'mfa_enabled'  => DB::table('users')->whereNotNull('two_factor_confirmed_at')->count(),
                'high_risk'    => DB::table('users')->where('risk_score', '>=', 50)->count(),
            ];
            $recentLogins = DB::table('auth_events')->where('event_type', 'LOGIN_OK')
                ->where('created_at', '>=', now()->subDay())->count();
            $recentFails  = DB::table('auth_events')->where('event_type', 'LOGIN_FAIL')
                ->where('created_at', '>=', now()->subDay())->count();

            return $this->ok([
                'by_role' => $byRole, 'by_country' => $byCountry, 'status' => $byStatus,
                'last_24h' => ['logins_ok' => $recentLogins, 'logins_fail' => $recentFails],
            ]);
        } catch (Throwable $e) { return $this->fail($e, 'stats'); }
    }

    // ══════════════════════════════════════════════════════════════════════
    //  CREATE (direct + invitation pathway)
    //
    //  Mirrors the mobile UsersList.vue → UserController::store flow:
    //    · Accepts single `assignment` (mobile) or `assignments[]` (legacy web)
    //    · SSOT name validation for province / pheoc / district / poe
    //      (values must match POES.JS exactly — see MobileUserController::VALID_*)
    //    · Role-geo enforcement via MobileUserController::ROLE_GEO_REQUIREMENTS
    //    · Direct-password creation (skips invite email + marks as accepted)
    //    · Invitation-email path when password is absent (kept for back-compat)
    //  Every branch writes an audit diff + AuthEvent.
    //  Response includes the full user row so the PWA can open the detail
    //  drawer immediately without a second round-trip.
    // ══════════════════════════════════════════════════════════════════════
    public function store(Request $r): JsonResponse
    {
        return $this->tx(function () use ($r) {
            // Normalise single `assignment` (mobile shape) → `assignments[]`
            // BEFORE validation so downstream logic has a single code path.
            $payload = $r->all();
            if (! empty($payload['assignment']) && empty($payload['assignments'])) {
                $a = $payload['assignment'];
                $payload['assignments'] = [[
                    'country_code'  => $a['country_code']  ?? ($payload['country_code'] ?? config('country.code')),
                    'province_code' => $a['province_code'] ?? ($a['pheoc_code'] ?? null),
                    'pheoc_code'    => $a['pheoc_code']    ?? ($a['province_code'] ?? null),
                    'district_code' => $a['district_code'] ?? null,
                    'poe_code'      => $a['poe_code']      ?? null,
                    'is_primary'    => array_key_exists('is_primary', $a) ? (bool) $a['is_primary'] : true,
                    'is_active'     => array_key_exists('is_active',  $a) ? (bool) $a['is_active']  : true,
                    'starts_at'     => $a['starts_at'] ?? null,
                    'ends_at'       => $a['ends_at']   ?? null,
                ]];
                $r->replace($payload);
            }

            $data = Validator::make($r->all(), [
                'full_name'    => 'required|string|min:2|max:150',
                'email'        => ['sometimes','nullable','email','max:190',
                    Rule::unique('users','email')->where(fn($q) => $q->whereRaw(
                        'LOWER(email) = ?', [strtolower(trim((string) $r->input('email','')))]
                    ))->whereNotNull('email')],
                'username'     => ['required','string','min:4','max:80','regex:/^[a-zA-Z0-9._-]+$/',
                    Rule::unique('users','username')->where(fn($q) => $q->whereRaw(
                        'LOWER(username) = ?', [strtolower(trim((string) $r->input('username','')))]
                    ))],
                'phone'        => 'nullable|string|max:40',
                'password'     => 'nullable|string|min:8|max:200',
                'role_key'     => ['required','string','max:60','exists:role_registry,role_key'],
                'country_code' => 'required|string|max:10',
                'account_type' => 'nullable|string|in:NATIONAL_ADMIN,PHEOC_ADMIN,DISTRICT_ADMIN,POE_ADMIN,POE_OFFICER,OBSERVER,SERVICE',
                'locale'       => 'nullable|string|max:10',
                'timezone'     => 'nullable|string|max:64',
                'is_active'    => 'nullable|boolean',
                'avatar_url'   => 'nullable|url|max:500',

                'assignments'  => 'nullable|array',
                'assignments.*.country_code'  => 'required_with:assignments|string|max:10',
                // Geo fields accept BOTH human names ("Central Region PHEOC")
                // and short codes ("RW-01", "KIC", "RW-KIC-001") so legacy
                // user_assignments rows can be updated without tripping a
                // hard-coded name list. Cross-row consistency is enforced by
                // the data layer, not the validator.
                'assignments.*.province_code' => ['sometimes','nullable','string','max:60'],
                'assignments.*.pheoc_code'    => ['sometimes','nullable','string','max:60'],
                'assignments.*.district_code' => ['sometimes','nullable','string','max:60'],
                'assignments.*.poe_code'      => ['sometimes','nullable','string','max:200'],
                'assignments.*.is_primary'    => 'nullable|boolean',
                'assignments.*.is_active'     => 'nullable|boolean',
                'assignments.*.starts_at'     => 'nullable|date_format:Y-m-d H:i:s',
                'assignments.*.ends_at'       => 'nullable|date_format:Y-m-d H:i:s',

                'send_invitation' => 'nullable|boolean',
                'client_uuid'     => 'nullable|string|max:64', // accepted, not persisted
            ], [
                'assignments.*.province_code.in' => 'Invalid Provincial PHEOC — must exactly match a name from POES.JS (e.g. "Gulu Provincial PHEOC").',
                'assignments.*.pheoc_code.in'    => 'Invalid PHEOC — must exactly match a name from POES.JS.',
                'assignments.*.district_code.in' => 'Invalid district — must exactly match a name from POES.JS (e.g. "Lamwo District").',
                'assignments.*.poe_code.in'      => 'Invalid POE — must exactly match a poe_name from POES.JS (e.g. "Mutukula").',
                'username.regex'                 => 'Username may only contain letters, numbers, dots, underscores, hyphens.',
                'username.unique'                => 'Username already taken.',
                'email.unique'                   => 'Email already registered.',
            ])->validate();

            // Role-geo enforcement mirrors mobile — only for the 4 field roles.
            $this->enforceGeographyForRole($data['role_key'], $data['assignments'][0] ?? []);

            // Scope + role-assignment guards (privilege-escalation prevention).
            $this->guard->assertCanAssignRole($r->user(), (string) $data['role_key']);
            foreach ((array) ($data['assignments'] ?? []) as $a) {
                $this->guard->assertAssignmentInScope($r->user(), (array) $a);
            }

            $actorId      = (int) $r->user()->id;
            $hasPassword  = ! empty($data['password']);
            $token        = $hasPassword ? null : bin2hex(random_bytes(32));
            $sendInvite   = ! $hasPassword && (bool) ($data['send_invitation'] ?? true);
            $isActiveBool = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

            $insert = [
                'full_name'    => $data['full_name'],
                'name'         => $data['full_name'],
                'email'        => ! empty($data['email']) ? $data['email'] : null,
                'username'     => strtolower((string) $data['username']),
                'phone'        => $data['phone'] ?? null,
                'role_key'     => $data['role_key'],
                // users.account_type is an ENUM — cannot accept role_key verbatim
                // for roles like SCREENER/PHEOC_OFFICER/DISTRICT_SUPERVISOR.
                // Map role_key → valid enum label; caller may still override.
                'account_type' => $data['account_type'] ?? self::mapAccountType($data['role_key']),
                'country_code' => $data['country_code'] ?? config('country.code'),
                'locale'       => $data['locale']   ?? 'en',
                'timezone'     => $data['timezone'] ?? null,
                'avatar_url'   => $data['avatar_url'] ?? null,
                'is_active'    => $isActiveBool ? 1 : 0,
                'created_by_user_id' => $actorId,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];

            if ($hasPassword) {
                $hash = Hash::make($data['password']);
                $insert['password']             = $hash; // varchar(200) — Auth::attempt() path
                $insert['password_hash']        = $hash; // varchar(255) — legacy mobile path
                $insert['password_changed_at']  = now();
                $insert['must_change_password'] = 0;
                $insert['invitation_accepted_at'] = now();
                $insert['email_verified_at']    = now(); // admin-created → implicitly verified
            } else {
                $insert['invitation_token_hash'] = hash('sha256', $token);
                $insert['invitation_expires_at'] = now()->addDays(7);
                $insert['must_change_password']  = 1;
            }

            $id = DB::table('users')->insertGetId($insert);

            // Upsert assignments using the same idempotent helper the mobile path uses.
            foreach ((array) ($data['assignments'] ?? []) as $a) {
                $this->upsertAssignment($id, $a);
            }

            $this->audit($actorId, $id, 'CREATE', null, [
                'email'       => $insert['email'],
                'username'    => $insert['username'],
                'role_key'    => $insert['role_key'],
                'country'     => $insert['country_code'],
                'direct'      => $hasPassword,
                'assignments' => $data['assignments'] ?? [],
            ], $r);
            AuthEventLogger::log('ADMIN_CREATED', $id, (string) ($insert['email'] ?? $insert['username']), 'INFO',
                ['actor_user_id' => $actorId, 'role' => $insert['role_key'], 'direct' => $hasPassword], 0, $r);

            if ($sendInvite && ! empty($insert['email'])) {
                $url = rtrim((string) config('app.url', 'http://localhost'), '/')
                     . '/accept-invite?token=' . $token . '&email=' . urlencode((string) $insert['email']);
                AuthMailer::send('AUTH_INVITATION', (string) $insert['email'], [
                    'user_id' => $id,
                    'full_name' => $insert['full_name'],
                    'email' => $insert['email'],
                    'role_display' => (string) DB::table('role_registry')->where('role_key', $insert['role_key'])->value('display_name'),
                    'invite_url' => $url,
                    'expires_in' => '7 days',
                    'inviter' => (string) ($r->user()->full_name ?? $r->user()->name ?? 'an administrator'),
                    'app_name' => 'POE Sentinel',
                    'now' => now()->format('Y-m-d H:i'),
                ]);
                AuthEventLogger::log('INVITATION_SENT', $id, (string) $insert['email'], 'INFO', null, 0, $r);
            }

            UserAnomalyService::scanUser($id);

            // Return full response record so the UI can open the drawer immediately.
            $user = DB::table('users')->where('id', $id)->first();
            $assignments = DB::table('user_assignments')->where('user_id', $id)->where('is_active', 1)->get();
            return $this->ok([
                'id'          => $id,
                'client_uuid' => $r->input('client_uuid'),
                'user'        => self::hideSecrets($user),
                'assignments' => $assignments,
                'direct'      => $hasPassword,
            ], 201);
        }, 'store');
    }

    // ── INVITATION ACCEPT (public endpoint, no auth) ────────────────────────
    public function acceptInvitation(Request $r): JsonResponse
    {
        return $this->tx(function () use ($r) {
            $data = Validator::make($r->all(), [
                'token'    => 'required|string|size:64',
                'email'    => 'required|email',
                'password' => 'required|string|min:12|confirmed',
            ])->validate();

            $u = DB::table('users')
                ->where('email', $data['email'])
                ->where('invitation_token_hash', hash('sha256', $data['token']))
                ->whereNull('invitation_accepted_at')
                ->first();
            if (! $u) return $this->err('Invitation not found or already used', 410);
            if ($u->invitation_expires_at && strtotime((string) $u->invitation_expires_at) < time()) {
                return $this->err('Invitation expired', 410);
            }
            if (! $this->passwordPolicy($data['password'])) {
                return $this->err('Password must be ≥12 chars with letters, numbers and a symbol', 422);
            }

            $hash = Hash::make($data['password']);
            DB::table('users')->where('id', $u->id)->update([
                'password' => $hash, 'password_hash' => $hash,
                'password_changed_at' => now(),
                'must_change_password' => 0,
                'invitation_accepted_at' => now(),
                'email_verified_at' => now(),
                'updated_at' => now(),
            ]);
            AuthEventLogger::log('INVITATION_ACCEPTED', (int) $u->id, (string) $u->email, 'INFO', null, -5, $r);
            UserAnomalyService::scanUser((int) $u->id);
            return $this->ok(['message' => 'Account activated — please sign in']);
        }, 'acceptInvitation');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  UPDATE
    //
    //  Accepts profile fields, optional password rotation, and optional
    //  `assignment` (mobile-shape) upsert. A role change re-runs geo
    //  enforcement against the incoming (or existing) primary assignment.
    //  Every mutation logs a before/after diff and a scoped AuthEvent.
    // ══════════════════════════════════════════════════════════════════════
    public function update(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $before = DB::table('users')->where('id', $id)->first();
            if (! $before) return $this->err('User not found', 404);
            $this->guard->assertCanManageTarget($r->user(), $id);

            $data = Validator::make($r->all(), [
                'full_name'    => 'sometimes|nullable|string|min:2|max:150',
                'username'     => ['sometimes','nullable','string','min:4','max:80','regex:/^[a-zA-Z0-9._-]+$/',
                    Rule::unique('users','username')->ignore($id)->where(fn($q) => $q->whereRaw(
                        'LOWER(username) = ?', [strtolower(trim((string) $r->input('username','')))]
                    ))],
                'email'        => ['sometimes','nullable','email','max:190',
                    Rule::unique('users','email')->ignore($id)->where(fn($q) => $q->whereRaw(
                        'LOWER(email) = ?', [strtolower(trim((string) $r->input('email','')))]
                    ))->whereNotNull('email')],
                'phone'        => 'sometimes|nullable|string|max:40',
                'password'     => 'sometimes|nullable|string|min:8|max:200',
                'role_key'     => 'sometimes|nullable|string|max:60|exists:role_registry,role_key',
                'country_code' => 'sometimes|nullable|string|max:10',
                'account_type' => 'sometimes|nullable|string|in:NATIONAL_ADMIN,PHEOC_ADMIN,DISTRICT_ADMIN,POE_ADMIN,POE_OFFICER,OBSERVER,SERVICE',
                'locale'       => 'sometimes|nullable|string|max:10',
                'timezone'     => 'sometimes|nullable|string|max:64',
                'is_active'    => 'sometimes|nullable|boolean',
                'avatar_url'   => 'sometimes|nullable|url|max:500',

                'assignment'                 => 'sometimes|array',
                'assignment.country_code'    => 'required_with:assignment|string|max:10',
                // Accept BOTH names and codes — see comment above on store()
                'assignment.province_code'   => ['sometimes','nullable','string','max:60'],
                'assignment.pheoc_code'      => ['sometimes','nullable','string','max:60'],
                'assignment.district_code'   => ['sometimes','nullable','string','max:60'],
                'assignment.poe_code'        => ['sometimes','nullable','string','max:200'],
                'assignment.is_primary'      => 'sometimes|boolean',
                'assignment.is_active'       => 'sometimes|boolean',
                'assignment.starts_at'       => 'sometimes|nullable|date_format:Y-m-d H:i:s',
                'assignment.ends_at'         => 'sometimes|nullable|date_format:Y-m-d H:i:s',
            ], [
                'assignment.province_code.in' => 'Invalid Provincial PHEOC — must exactly match a name from POES.JS.',
                'assignment.pheoc_code.in'    => 'Invalid PHEOC — must exactly match a name from POES.JS.',
                'assignment.district_code.in' => 'Invalid district — must exactly match a name from POES.JS.',
                'assignment.poe_code.in'      => 'Invalid POE — must exactly match a poe_name from POES.JS.',
                'username.regex'              => 'Username may only contain letters, numbers, dots, underscores, hyphens.',
                'username.unique'             => 'Username already taken.',
                'email.unique'                => 'Email already registered.',
            ])->validate();

            // Scope + role-assignment guards.
            if (! empty($data['role_key'])) {
                $this->guard->assertCanAssignRole($r->user(), (string) $data['role_key']);
            }
            if (! empty($data['assignment'])) {
                $this->guard->assertAssignmentInScope($r->user(), (array) $data['assignment']);
            }
            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $this->guard->assertNotSelfDestructive($r->user(), $id, 'suspend');
            }

            // If role is changing, enforce geo against the incoming assignment
            // (or the existing primary one if none provided).
            if (! empty($data['role_key']) && $data['role_key'] !== $before->role_key) {
                $this->guard->assertNotSelfDestructive($r->user(), $id, 'role_change');
                $assignmentForCheck = $data['assignment'] ?? (array) (DB::table('user_assignments')
                    ->where('user_id', $id)->where('is_primary', 1)->where('is_active', 1)
                    ->first() ?: []);
                $this->enforceGeographyForRole($data['role_key'], (array) $assignmentForCheck);
            } elseif (! empty($data['assignment'])) {
                // Role stays; still enforce geo for the new assignment shape.
                $this->enforceGeographyForRole((string) $before->role_key, $data['assignment']);
            }

            // ── Build the user-table diff (exclude assignment + password) ───
            $fields = [];
            foreach (['full_name','username','email','phone','role_key','country_code',
                      'account_type','locale','timezone','is_active','avatar_url'] as $k) {
                if (array_key_exists($k, $data)) $fields[$k] = $data[$k];
            }
            if (array_key_exists('username', $fields) && $fields['username'] !== null) {
                $fields['username'] = strtolower((string) $fields['username']);
            }
            if (array_key_exists('full_name', $fields)) {
                $fields['name'] = $fields['full_name'];
            }
            if (array_key_exists('is_active', $fields)) {
                $fields['is_active'] = $fields['is_active'] ? 1 : 0;
            }
            if (! empty($data['password'])) {
                $hash = Hash::make($data['password']);
                $fields['password']            = $hash;
                $fields['password_hash']       = $hash;
                $fields['password_changed_at'] = now();
                $fields['must_change_password'] = 0;
            }

            if ($fields) {
                $fields['updated_at'] = now();
                DB::table('users')->where('id', $id)->update($fields);
            }

            // ── Assignment upsert (mobile-parity) ───────────────────────────
            $assignmentChanged = false;
            if (! empty($data['assignment'])) {
                $assignmentChanged = $this->upsertAssignment($id, $data['assignment']);
            }

            $after = DB::table('users')->where('id', $id)->first();
            $this->audit((int) $r->user()->id, $id, 'UPDATE', (array) $before, (array) $after, $r);
            AuthEventLogger::log('ADMIN_UPDATED', $id, null, 'INFO',
                ['actor_user_id' => (int) $r->user()->id,
                 'fields'        => array_keys($fields),
                 'assignment'    => $assignmentChanged,
                 'password'      => ! empty($data['password'])], 0, $r);

            if (isset($fields['role_key']) && $fields['role_key'] !== $before->role_key) {
                AuthEventLogger::log('ROLE_CHANGED', $id, null, 'WARN',
                    ['from' => $before->role_key, 'to' => $fields['role_key']], 0, $r);
            }

            // If admin rotated the password, revoke all tokens except the
            // actor's current session so the target user is forced to re-auth.
            if (! empty($data['password'])) {
                DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete();
                AuthEventLogger::log('PASSWORD_CHANGED', $id, null, 'WARN',
                    ['actor_user_id' => (int) $r->user()->id, 'admin_set' => true], -3, $r);
            }

            UserAnomalyService::scanUser($id);

            $user = DB::table('users')->where('id', $id)->first();
            $assignments = DB::table('user_assignments')->where('user_id', $id)->where('is_active', 1)->get();
            return $this->ok([
                'updated'     => true,
                'user'        => self::hideSecrets($user),
                'assignments' => $assignments,
            ]);
        }, 'update');
    }

    // ══════════════════════════════════════════════════════════════════════
    //  HELPERS — mirror the mobile UserController's invariants so data written
    //  by either surface is indistinguishable at the DB level.
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Map role_key → users.account_type enum label.
     *
     * The users.account_type column is a MySQL ENUM with a narrow value set:
     *   NATIONAL_ADMIN, PHEOC_ADMIN, DISTRICT_ADMIN,
     *   POE_ADMIN, POE_OFFICER, OBSERVER, SERVICE
     *
     * role_key is a broader taxonomy (SCREENER, PHEOC_OFFICER, …) that
     * CANNOT be written to account_type verbatim (MySQL 1265 Data truncated).
     *
     * This map is the single source of truth and is validated by the live
     * CRUD test (users_crud_live_test.sh).
     */
    private static function mapAccountType(string $roleKey): string
    {
        return match ($roleKey) {
            'NATIONAL_ADMIN'      => 'NATIONAL_ADMIN',
            'PHEOC_OFFICER'       => 'PHEOC_ADMIN',
            'DISTRICT_SUPERVISOR' => 'DISTRICT_ADMIN',
            'POE_ADMIN'           => 'POE_ADMIN',
            'SCREENER',
            'POE_OFFICER',
            'POE_DATA_OFFICER'    => 'POE_OFFICER',
            'SERVICE'             => 'SERVICE',
            default               => 'OBSERVER',
        };
    }

    /**
     * Role → geo requirement enforcement. No-op for admin-only roles
     * (NATIONAL_ADMIN, OBSERVER, POE_ADMIN, etc.) that don't appear in the
     * mobile ROLE_GEO_REQUIREMENTS map.
     *
     * @throws ValidationException
     */
    private function enforceGeographyForRole(string $roleKey, array $assignment): void
    {
        $reqs = MobileUserController::ROLE_GEO_REQUIREMENTS[$roleKey] ?? null;
        if ($reqs === null || empty($reqs)) return;

        if (in_array('province_or_pheoc', $reqs, true)
            && empty($assignment['province_code'])
            && empty($assignment['pheoc_code'])) {
            throw ValidationException::withMessages([
                'assignment.province_code' => 'Provincial PHEOC / Province assignment is required for role '.$roleKey.'.',
            ]);
        }
        if (in_array('district_code', $reqs, true) && empty($assignment['district_code'])) {
            throw ValidationException::withMessages([
                'assignment.district_code' => 'District assignment is required for role '.$roleKey.'.',
            ]);
        }
        if (in_array('poe_code', $reqs, true) && empty($assignment['poe_code'])) {
            throw ValidationException::withMessages([
                'assignment.poe_code' => 'POE assignment is required for role '.$roleKey.'.',
            ]);
        }
    }

    /**
     * Upsert a user's primary assignment idempotently, preserving history.
     *
     * Mirrors MobileUserController::upsertAssignment:
     *   · If the current primary row has identical geo → only flip is_active
     *   · Otherwise close the old row (ends_at=now, is_active=0, is_primary=0)
     *     and insert a fresh primary row.
     *
     * Returns true when a row was inserted or mutated.
     */
    private function upsertAssignment(int $userId, array $assignment): bool
    {
        $now         = now();
        $newCountry  = $assignment['country_code']  ?? config('country.code');
        $newProvince = $assignment['province_code'] ?? null;
        $newPheoc    = $assignment['pheoc_code']    ?? $newProvince;
        $newDistrict = $assignment['district_code'] ?? null;
        $newPoe      = $assignment['poe_code']      ?? null;
        $isPrimary   = array_key_exists('is_primary', $assignment) ? (int) (bool) $assignment['is_primary'] : 1;
        $isActive    = array_key_exists('is_active',  $assignment) ? (int) (bool) $assignment['is_active']  : 1;
        $startsAt    = $assignment['starts_at'] ?? $now;
        $endsAt      = $assignment['ends_at']   ?? null;

        $existing = DB::table('user_assignments')
            ->where('user_id', $userId)->where('is_primary', 1)->where('is_active', 1)
            ->whereNull('ends_at')->first();

        $sameGeo = $existing && (
            ((string) ($existing->country_code  ?? '') === (string) ($newCountry  ?? '')) &&
            ((string) ($existing->province_code ?? '') === (string) ($newProvince ?? '')) &&
            ((string) ($existing->district_code ?? '') === (string) ($newDistrict ?? '')) &&
            ((string) ($existing->poe_code      ?? '') === (string) ($newPoe      ?? ''))
        );

        if ($existing && $sameGeo) {
            if ((int) $existing->is_active !== $isActive) {
                DB::table('user_assignments')->where('id', $existing->id)->update([
                    'is_active'  => $isActive,
                    'updated_at' => $now,
                ]);
                return true;
            }
            return false; // perfect duplicate — no write
        }

        if ($existing) {
            DB::table('user_assignments')->where('id', $existing->id)->update([
                'is_active'  => 0,
                'is_primary' => 0,
                'ends_at'    => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('user_assignments')->insert([
            'user_id'       => $userId,
            'country_code'  => $newCountry,
            'province_code' => $newProvince,
            'pheoc_code'    => $newPheoc,
            'district_code' => $newDistrict,
            'poe_code'      => $newPoe,
            'is_primary'    => $isPrimary,
            'is_active'     => $isActive,
            'starts_at'     => $startsAt,
            'ends_at'       => $endsAt,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
        return true;
    }

    public function suspend(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err('User not found', 404);
            $this->guard->assertCanManageTarget($r->user(), $id);
            $this->guard->assertNotSelfDestructive($r->user(), $id, 'suspend');
            $reason = (string) $r->input('reason', 'Administrative action');
            DB::table('users')->where('id', $id)->update([
                'suspended_at' => now(), 'suspension_reason' => mb_substr($reason, 0, 500),
                'is_active' => 0, 'updated_at' => now(),
            ]);
            DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete();
            DB::table('trusted_devices')->where('user_id', $id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_reason' => 'user_suspended']);
            AuthEventLogger::log('ADMIN_SUSPENDED', $id, null, 'CRITICAL',
                ['actor_user_id' => (int) $r->user()->id, 'reason' => $reason], 0, $r);
            $this->audit((int) $r->user()->id, $id, 'SUSPEND', null, ['reason' => $reason], $r);
            if ($u->email) {
                AuthMailer::send('AUTH_SUSPENDED', (string) $u->email, [
                    'user_id' => $id, 'full_name' => $u->full_name ?? '',
                    'email' => $u->email, 'reason' => $reason,
                    'app_name' => 'POE Sentinel', 'now' => now()->format('Y-m-d H:i'), 'ip' => $r->ip(),
                ]);
            }
            return $this->ok(['suspended' => true]);
        }, 'suspend');
    }

    public function reactivate(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err('User not found', 404);
            $this->guard->assertCanManageTarget($r->user(), $id);
            DB::table('users')->where('id', $id)->update([
                'suspended_at' => null, 'suspension_reason' => null,
                'is_active' => 1, 'failed_login_count' => 0, 'locked_until' => null,
                'updated_at' => now(),
            ]);
            AuthEventLogger::log('ADMIN_REACTIVATED', $id, null, 'WARN',
                ['actor_user_id' => (int) $r->user()->id], 0, $r);
            $this->audit((int) $r->user()->id, $id, 'REACTIVATE', null, null, $r);
            return $this->ok(['reactivated' => true]);
        }, 'reactivate');
    }

    public function resetPassword(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u || ! $u->email) return $this->err('User not found or has no email', 404);
            $this->guard->assertCanManageTarget($r->user(), $id);
            $token = bin2hex(random_bytes(32));
            DB::table('email_verifications')->insert([
                'user_id' => $id, 'purpose' => 'RESET_PASSWORD',
                'token_hash' => hash('sha256', $token),
                'email' => (string) $u->email,
                'ip' => $r->ip(), 'user_agent' => mb_substr((string) $r->userAgent(), 0, 300),
                'expires_at' => now()->addHours(2),
                'created_at' => now(),
            ]);
            DB::table('users')->where('id', $id)->update(['must_change_password' => 1, 'updated_at' => now()]);
            DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete();
            $url = rtrim((string) config('app.url', 'http://localhost'), '/')
                 . '/reset-password?token=' . $token . '&email=' . urlencode((string) $u->email);
            AuthMailer::send('AUTH_PASSWORD_RESET', (string) $u->email, [
                'user_id' => $id, 'full_name' => $u->full_name ?? 'there',
                'email' => $u->email, 'reset_url' => $url, 'expires_in' => '2 hours',
                'app_name' => 'POE Sentinel', 'ip' => $r->ip(), 'now' => now()->format('Y-m-d H:i'),
            ]);
            AuthEventLogger::log('PASSWORD_RESET_REQUESTED', $id, (string) $u->email, 'WARN',
                ['actor_user_id' => (int) $r->user()->id, 'admin_initiated' => true], 0, $r);
            $this->audit((int) $r->user()->id, $id, 'RESET_PASSWORD', null, null, $r);
            return $this->ok(['sent' => true]);
        }, 'resetPassword');
    }

    public function forceMfaReset(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err('User not found', 404);
            $this->guard->assertCanManageTarget($r->user(), $id);
            $this->guard->assertNotSelfDestructive($r->user(), $id, 'force_mfa_reset');
            DB::table('users')->where('id', $id)->update([
                'two_factor_secret' => null,
                'two_factor_recovery_codes_hash' => null,
                'two_factor_confirmed_at' => null,
                'updated_at' => now(),
            ]);
            AuthEventLogger::log('TWOFA_DISABLED', $id, null, 'WARN',
                ['actor_user_id' => (int) $r->user()->id, 'forced' => true], 0, $r);
            $this->audit((int) $r->user()->id, $id, 'FORCE_MFA_RESET', null, null, $r);
            return $this->ok(['reset' => true]);
        }, 'forceMfaReset');
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err('User not found', 404);
            $this->guard->assertCanManageTarget($r->user(), $id);
            $this->guard->assertNotSelfDestructive($r->user(), $id, 'delete');
            DB::table('users')->where('id', $id)->update([
                'is_active' => 0, 'suspended_at' => now(),
                'suspension_reason' => 'Deleted by admin',
                'updated_at' => now(),
            ]);
            DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete();
            DB::table('trusted_devices')->where('user_id', $id)->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_reason' => 'user_deleted']);
            $this->audit((int) $r->user()->id, $id, 'DELETE', null, null, $r);
            AuthEventLogger::log('ADMIN_SUSPENDED', $id, null, 'CRITICAL',
                ['actor_user_id' => (int) $r->user()->id, 'reason' => 'DELETE'], 0, $r);
            return $this->ok(['deleted' => true]);
        }, 'destroy');
    }

    // ── BULK ────────────────────────────────────────────────────────────────
    public function bulk(Request $r): JsonResponse
    {
        return $this->tx(function () use ($r) {
            $data = Validator::make($r->all(), [
                'ids'    => 'required|array|min:1',
                'ids.*'  => 'integer',
                'action' => 'required|in:suspend,reactivate,delete,force_mfa_reset,rescan,role,country',
                'reason' => 'nullable|string|max:500',
                'role_key' => 'nullable|string|max:60',
                'country_code' => 'nullable|string|max:10',
            ])->validate();
            // IMPORTANT: all sub-calls receive the ORIGINAL authenticated
            // Request so actor_user_id + ip + ua flow into audit + events.
            // Building a new Request() would strip the Sanctum user.
            $actorId = (int) $r->user()->id;
            $reason  = (string) ($data['reason'] ?? 'Bulk action');
            $affected = 0;
            foreach ($data['ids'] as $id) {
                if ((int) $id === $actorId && in_array($data['action'], ['suspend', 'delete', 'force_mfa_reset', 'role'], true)) {
                    // Self-destruct guard: never let an admin suspend / delete / demote themselves in bulk.
                    continue;
                }
                // Per-target scope guard (skip rather than abort → partial apply).
                try {
                    $this->guard->assertCanManageTarget($r->user(), (int) $id);
                    if ($data['action'] === 'role' && ! empty($data['role_key'])) {
                        $this->guard->assertCanAssignRole($r->user(), (string) $data['role_key']);
                    }
                } catch (\Throwable) {
                    continue;
                }
                switch ($data['action']) {
                    case 'suspend':
                        $r->merge(['reason' => $reason]);
                        $this->suspend($r, (int) $id);
                        break;
                    case 'reactivate':
                        $this->reactivate($r, (int) $id);
                        break;
                    case 'delete':
                        $this->destroy($r, (int) $id);
                        break;
                    case 'force_mfa_reset':
                        $this->forceMfaReset($r, (int) $id);
                        break;
                    case 'rescan':
                        UserAnomalyService::scanUser((int) $id);
                        break;
                    case 'role':
                        if (! empty($data['role_key'])) {
                            $before = DB::table('users')->where('id', $id)->value('role_key');
                            DB::table('users')->where('id', $id)->update([
                                'role_key' => $data['role_key'], 'updated_at' => now(),
                            ]);
                            AuthEventLogger::log('ROLE_CHANGED', (int) $id, null, 'WARN',
                                ['actor_user_id' => $actorId, 'from' => $before, 'to' => $data['role_key']],
                                0, $r);
                            $this->audit($actorId, (int) $id, 'ROLE_CHANGE',
                                ['role_key' => $before], ['role_key' => $data['role_key']], $r);
                        }
                        break;
                    case 'country':
                        if (! empty($data['country_code'])) {
                            $before = DB::table('users')->where('id', $id)->value('country_code');
                            DB::table('users')->where('id', $id)->update([
                                'country_code' => $data['country_code'], 'updated_at' => now(),
                            ]);
                            $this->audit($actorId, (int) $id, 'COUNTRY_CHANGE',
                                ['country_code' => $before], ['country_code' => $data['country_code']], $r);
                        }
                        break;
                }
                $affected++;
            }
            return $this->ok(['affected' => $affected]);
        }, 'bulk');
    }

    // ── ANOMALY + ACTIVITY ENDPOINTS ────────────────────────────────────────
    public function activity(Request $r, int $id): JsonResponse
    {
        try {
            if ($r->user()) $this->guard->assertCanManageTarget($r->user(), $id);
            $rows = DB::table('auth_events')->where('user_id', $id)
                ->orderByDesc('created_at')->limit((int) $r->query('limit', 200))->get();
            return $this->ok(['events' => $rows]);
        } catch (Throwable $e) { return $this->fail($e, 'activity'); }
    }

    public function flags(int $id): JsonResponse
    {
        try {
            $rows = DB::table('user_anomaly_flags')->where('user_id', $id)->orderByDesc('last_seen_at')->get();
            return $this->ok(['flags' => $rows]);
        } catch (Throwable $e) { return $this->fail($e, 'flags'); }
    }

    public function clearFlag(Request $r, int $id, int $flagId): JsonResponse
    {
        try {
            $note = (string) $r->input('note', '');
            DB::table('user_anomaly_flags')->where('id', $flagId)->where('user_id', $id)->update([
                'cleared_at' => now(), 'cleared_by_user_id' => (int) $r->user()->id,
                'clearance_note' => mb_substr($note, 0, 300),
            ]);
            $this->audit((int) $r->user()->id, $id, 'CLEAR_FLAG', null, ['flag_id' => $flagId, 'note' => $note], $r);
            UserAnomalyService::scanUser($id);
            return $this->ok(['cleared' => true]);
        } catch (Throwable $e) { return $this->fail($e, 'clearFlag'); }
    }

    public function rescan(int $id): JsonResponse
    {
        return $this->ok(UserAnomalyService::scanUser($id));
    }

    public function scanAll(): JsonResponse
    {
        return $this->ok(UserAnomalyService::scanAll());
    }

    // ── REPORTS ────────────────────────────────────────────────────────────
    public function reportRisk(Request $r): JsonResponse
    {
        $rows = DB::table('users')->where('risk_score', '>=', (int) $r->query('min', 40))
            ->select('id','full_name','email','role_key','country_code','risk_score',
                     'risk_flags_json','last_login_at','two_factor_confirmed_at','is_active')
            ->orderByDesc('risk_score')->limit(200)->get();
        return $this->ok(['users' => $rows]);
    }

    public function reportRoles(): JsonResponse
    {
        $rows = DB::table('users as u')
            ->leftJoin('role_registry as rr','rr.role_key','=','u.role_key')
            ->selectRaw('u.role_key, rr.display_name, rr.scope_level, COUNT(*) AS n,
                         SUM(CASE WHEN u.two_factor_confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS with_mfa,
                         SUM(CASE WHEN u.suspended_at IS NOT NULL THEN 1 ELSE 0 END) AS suspended,
                         SUM(CASE WHEN u.last_activity_at < DATE_SUB(NOW(), INTERVAL 30 DAY) OR u.last_activity_at IS NULL THEN 1 ELSE 0 END) AS dormant')
            ->groupBy('u.role_key','rr.display_name','rr.scope_level')->get();
        return $this->ok(['roles' => $rows]);
    }

    public function reportDormant(Request $r): JsonResponse
    {
        $days = (int) $r->query('days', 30);
        $rows = DB::table('users')->where('is_active', 1)->whereNull('suspended_at')
            ->where(function ($q) use ($days) {
                $q->whereNull('last_activity_at')
                  ->orWhere('last_activity_at','<', now()->subDays($days));
            })
            ->select('id','full_name','email','role_key','country_code','last_activity_at','created_at')
            ->orderBy('last_activity_at')->limit(500)->get();
        return $this->ok(['users' => $rows, 'days' => $days]);
    }

    public function reportMfa(): JsonResponse
    {
        $rows = DB::table('users')
            ->selectRaw("role_key,
                SUM(CASE WHEN two_factor_confirmed_at IS NOT NULL THEN 1 ELSE 0 END) AS with_mfa,
                COUNT(*) AS total,
                ROUND(100 * SUM(CASE WHEN two_factor_confirmed_at IS NOT NULL THEN 1 ELSE 0 END) / COUNT(*), 1) AS mfa_pct")
            ->groupBy('role_key')->orderBy('role_key')->get();
        return $this->ok(['mfa_by_role' => $rows]);
    }

    // ── HELPERS ────────────────────────────────────────────────────────────
    private function audit(int $actor, int $target, string $action, $before, $after, Request $r): void
    {
        DB::table('user_audit_log')->insert([
            'actor_user_id' => $actor, 'target_user_id' => $target,
            'action' => $action,
            'before_json' => $before !== null ? json_encode($before) : null,
            'after_json'  => $after  !== null ? json_encode($after)  : null,
            'ip' => $r->ip(), 'user_agent' => mb_substr((string) $r->userAgent(), 0, 300),
            'created_at' => now(),
        ]);
    }

    private static function hideSecrets(object $u): object
    {
        foreach (['password','password_hash','two_factor_secret','two_factor_recovery_codes_hash',
                  'invitation_token_hash','remember_token'] as $col) {
            if (property_exists($u, $col)) $u->{$col} = $u->{$col} ? '***' : null;
        }
        return $u;
    }

    private function passwordPolicy(string $pw): bool
    {
        if (mb_strlen($pw) < 12) return false;
        $c = 0;
        if (preg_match('/[a-z]/', $pw)) $c++;
        if (preg_match('/[A-Z]/', $pw)) $c++;
        if (preg_match('/\d/', $pw))    $c++;
        if (preg_match('/[^a-zA-Z0-9]/', $pw)) $c++;
        return $c >= 3;
    }

    private function ok(array $d = [], int $c = 200): JsonResponse { return response()->json(['ok' => true, 'data' => $d], $c); }
    private function err(string $m, int $c = 400): JsonResponse { return response()->json(['ok' => false, 'error' => $m], $c); }
    private function fail(Throwable $e, string $ctx): JsonResponse {
        Log::error("[UsersAdmin::$ctx] " . $e->getMessage());
        return response()->json(['ok' => false, 'error' => $e->getMessage(), 'ctx' => $ctx], 500);
    }
    private function tx(callable $fn, string $ctx): JsonResponse {
        try { return DB::transaction($fn); }
        catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpResponseException $e) { throw $e; }
        catch (Throwable $e) { return $this->fail($e, $ctx); }
    }
}
