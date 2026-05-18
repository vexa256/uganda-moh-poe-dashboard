<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Workforce;

use App\Http\Controllers\Controller;
use App\Support\Scope\ScopeFilter;
use App\Support\Workforce\CoachManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin · Workforce · Users.
 *
 * Surveillance workforce CRUD with scope-aware listing, invite + temporary
 * password issuance, suspend / reactivate, force-password-change, MFA reset.
 *
 * Auth + scope enforced by route middleware. Writes additionally gated to
 * NATIONAL_ADMIN by `role:NATIONAL_ADMIN` in routes/web.php.
 *
 * Audit:  every mutation is appended to user_audit_log.
 */
final class UsersController extends Controller
{
    private const ACCOUNT_TYPES = [
        'NATIONAL_ADMIN', 'PHEOC_ADMIN', 'DISTRICT_ADMIN', 'POE_ADMIN', 'POE_OFFICER', 'OBSERVER', 'SERVICE',
    ];
    /** Default tenant country (full name; matches ref_poes.country_code). */
    private static function defaultCountry(): string
    {
        return (string) (config('country.legacy_code') ?: 'Uganda');
    }
    private const INVITE_MODES   = ['credential', 'email'];
    private const INVITE_TTL_DAYS = 7;

    /* ─────────────────────────── views ─────────────────────────── */

    public function index(Request $r)
    {
        return view('admin.workforce.users.index', [
            'coach' => CoachManifest::forView('users'),
        ]);
    }

    /* ─────────────────────────── reads ─────────────────────────── */

    public function data(Request $r): JsonResponse
    {
        try {
            $scope    = ScopeFilter::fromRequest($r);
            $tab      = strtolower((string) $r->query('status', 'active'));
            $role     = trim((string) $r->query('role_key', ''));
            $province = trim((string) $r->query('province', ''));
            $district = trim((string) $r->query('district', ''));
            $poe      = trim((string) $r->query('poe', ''));
            $q        = trim((string) $r->query('q', ''));

            $base = function () use ($scope) {
                return ScopeFilter::applyToUsers(DB::table('users'), $scope, 'users');
            };

            $query = $base();
            $this->applyTab($query, $tab);
            if ($role !== '')     { $query->where('role_key', $role); }

            if ($province !== '' || $district !== '' || $poe !== '') {
                $query->whereExists(function ($w) use ($province, $district, $poe): void {
                    $w->select(DB::raw(1))
                      ->from('user_assignments as ua_f')
                      ->whereColumn('ua_f.user_id', 'users.id')
                      ->where('ua_f.is_active', 1)
                      ->whereNull('ua_f.ends_at');
                    if ($province !== '') { $w->where('ua_f.province_code', $province); }
                    if ($district !== '') { $w->where('ua_f.district_code', $district); }
                    if ($poe !== '')      { $w->where('ua_f.poe_code',      $poe); }
                });
            }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $query->where(function ($w) use ($like): void {
                    $w->where('full_name', 'like', $like)
                      ->orWhere('email',   'like', $like)
                      ->orWhere('username','like', $like)
                      ->orWhere('phone',   'like', $like);
                });
            }

            $rows = $query
                ->select([
                    'id','full_name','username','email','phone','role_key','account_type',
                    'is_active','suspended_at','suspension_reason','last_login_at','last_login_ip',
                    'must_change_password','two_factor_confirmed_at','locked_until',
                    'risk_score','risk_score_updated_at','created_at',
                    'invitation_token_hash','invitation_accepted_at',
                ])
                ->orderByDesc('id')
                ->limit(500)
                ->get();

            $userIds = $rows->pluck('id')->all();
            $assignments = $userIds
                ? DB::table('user_assignments')
                    ->whereIn('user_id', $userIds)
                    ->where('is_active', 1)
                    ->whereNull('ends_at')
                    ->orderByDesc('is_primary')
                    ->orderBy('id')
                    ->get()
                    ->groupBy('user_id')
                : collect();

            $tabs = [
                'active'    => (clone $base())->where('is_active', 1)->whereNull('suspended_at')->count(),
                'suspended' => (clone $base())->whereNotNull('suspended_at')->count(),
                'inactive'  => (clone $base())->where('is_active', 0)->whereNull('suspended_at')->count(),
                'invited'   => (clone $base())->whereNotNull('invitation_token_hash')->whereNull('invitation_accepted_at')->count(),
                'all'       => (clone $base())->count(),
            ];

            return $this->ok([
                'rows'  => $rows->map(fn ($u) => $this->castRow($u, $assignments[$u->id] ?? collect()))->all(),
                'total' => $rows->count(),
            ], 'Users.', ['tabs' => $tabs, 'scope_label' => $scope['label'] ?? null]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    public function show(Request $r, int $id): JsonResponse
    {
        try {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err(404, 'User not found.');

            // Scope guard — non-super callers must share a scope row with the target.
            $scope = ScopeFilter::fromRequest($r);
            if (! ScopeFilter::isSuper($scope) && ! $this->visible($id, $scope)) {
                return $this->err(403, 'User outside your scope.');
            }

            $assigns = DB::table('user_assignments')->where('user_id', $id)->orderByDesc('is_active')->orderByDesc('is_primary')->orderBy('id')->get();
            $audit   = DB::table('user_audit_log')->where('target_user_id', $id)->orderByDesc('id')->limit(25)->get();
            $auth    = DB::table('auth_events')->where('user_id', $id)->orderByDesc('id')->limit(25)->get();

            return $this->ok([
                'user'        => $this->castRow($u, $assigns->where('is_active', 1)->where('ends_at', null)->values()),
                'assignments' => $assigns->map(fn ($a) => (array) $a)->values()->all(),
                'audit'       => $audit->map(fn ($a) => (array) $a)->all(),
                'auth_events' => $auth->map(fn ($a) => (array) $a)->all(),
            ], 'User retrieved.');
        } catch (Throwable $e) { return $this->serverError($e, 'show'); }
    }

    public function meta(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $roles = DB::table('role_registry')->where('is_active', 1)->orderBy('display_name')->get(['role_key','display_name','scope_level','description']);

            $provQ = DB::table('ref_provinces')->whereNull('deleted_at');
            $provQ = ScopeFilter::applyToProvinces($provQ, $scope);
            $provinces = $provQ->orderBy('name')->pluck('name')->all();

            $distQ = DB::table('ref_districts')->whereNull('deleted_at');
            $distQ = ScopeFilter::applyToDistricts($distQ, $scope);
            $districts = $distQ->orderBy('name')->pluck('name')->all();

            $poeQ = DB::table('ref_poes')->where('country_code', self::defaultCountry())->whereNull('deleted_at');
            $poeQ = ScopeFilter::applyToPoes($poeQ, $scope);
            $poes = $poeQ->orderBy('display_order')->orderBy('poe_name')->get(['poe_code','poe_name','admin_level_1','district'])
                ->map(fn ($p) => [
                    'poe_code' => (string) $p->poe_code,
                    'poe_name' => (string) $p->poe_name,
                    'province' => (string) $p->admin_level_1,
                    'district' => (string) $p->district,
                ])->all();

            return $this->ok([
                'roles'           => $roles,
                'account_types'   => self::ACCOUNT_TYPES,
                'provinces'       => $provinces,
                'districts'       => $districts,
                'poes'            => $poes,
                'country'         => self::defaultCountry(),
                'invite_modes'    => self::INVITE_MODES,
                'invite_ttl_days' => self::INVITE_TTL_DAYS,
            ], 'Meta.');
        } catch (Throwable $e) { return $this->serverError($e, 'meta'); }
    }

    /* ─────────────────────────── writes ────────────────────────── */

    public function store(Request $r): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        $data  = $r->all();

        $payload = $this->validatePayload($data, false);
        if ($payload instanceof JsonResponse) return $payload;

        try {
            // Uniqueness — username + email
            if (! empty($payload['username']) && DB::table('users')->where('username', $payload['username'])->exists()) {
                return $this->err(409, 'Username already taken.');
            }
            if (! empty($payload['email']) && DB::table('users')->where('email', $payload['email'])->exists()) {
                return $this->err(409, 'Email already registered.');
            }

            $mode = strtolower((string) ($data['invite_mode'] ?? 'credential'));
            if (! in_array($mode, self::INVITE_MODES, true)) {
                return $this->err(422, 'Invalid invite_mode.', ['allowed' => self::INVITE_MODES]);
            }

            $now           = Carbon::now();
            $tempPassword  = null;
            $invitePlain   = null;
            $inviteUrl     = null;

            if ($mode === 'credential') {
                // Direct credential issue — admin shares the temp password out-of-band.
                $tempPassword = $this->generateTempPassword();
                $hash         = Hash::make($tempPassword);
                $insert = array_merge($payload, [
                    'password'             => $hash,
                    'password_hash'        => $hash,
                    'must_change_password' => 1,
                    'is_active'            => $payload['is_active'] ?? 1,
                    'invitation_token_hash'=> null,
                    'invitation_expires_at'=> null,
                    'created_by_user_id'   => $admin,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                    'password_changed_at'  => $now,
                ]);
            } else {
                // Email-link invite — issue token, lock the account until they accept.
                $invitePlain = self::issueInviteToken();
                $insert = array_merge($payload, [
                    'password'             => Hash::make(Str::random(64)), // unguessable; cannot login until accept
                    'password_hash'        => null,
                    'must_change_password' => 1,
                    'is_active'            => 0,
                    'invitation_token_hash'=> hash('sha256', $invitePlain),
                    'invitation_expires_at'=> $now->copy()->addDays(self::INVITE_TTL_DAYS),
                    'created_by_user_id'   => $admin,
                    'created_at'           => $now,
                    'updated_at'           => $now,
                ]);
            }

            $id = DB::table('users')->insertGetId($insert);

            $this->audit($admin, $id, 'USER_INVITE_' . strtoupper($mode), null, $insert, $r);

            if ($invitePlain !== null) {
                $inviteUrl = url('/invite/' . $invitePlain);
            }

            $row = DB::table('users')->where('id', $id)->first();
            $cast = $this->castRow($row, collect());
            $cast['_invite_mode']     = $mode;
            $cast['_temp_password']   = $tempPassword;     // null for email mode
            $cast['_invite_token']    = $invitePlain;      // shown once
            $cast['_invite_url']      = $inviteUrl;        // shown once
            $cast['_invite_expires']  = $insert['invitation_expires_at'] ?? null;
            return $this->ok($cast, $mode === 'email' ? 'Invitation link issued.' : 'User invited with temporary credentials.');
        } catch (Throwable $e) { return $this->serverError($e, 'store'); }
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $before = DB::table('users')->where('id', $id)->first();
            if (! $before) return $this->err(404, 'User not found.');

            $payload = $this->validatePayload($r->all(), true);
            if ($payload instanceof JsonResponse) return $payload;

            // Re-check uniqueness only if changed.
            if (isset($payload['username']) && $payload['username'] !== $before->username) {
                if (DB::table('users')->where('username', $payload['username'])->where('id', '!=', $id)->exists()) {
                    return $this->err(409, 'Username already taken.');
                }
            }
            if (isset($payload['email']) && $payload['email'] !== $before->email) {
                if (DB::table('users')->where('email', $payload['email'])->where('id', '!=', $id)->exists()) {
                    return $this->err(409, 'Email already registered.');
                }
            }

            $payload['updated_at'] = Carbon::now();
            DB::table('users')->where('id', $id)->update($payload);

            $this->audit($admin, $id, 'USER_UPDATE', (array) $before, $payload, $r);

            $fresh = DB::table('users')->where('id', $id)->first();
            $assigns = DB::table('user_assignments')->where('user_id', $id)->where('is_active', 1)->whereNull('ends_at')->get();
            return $this->ok($this->castRow($fresh, $assigns), 'User updated.');
        } catch (Throwable $e) { return $this->serverError($e, 'update'); }
    }

    /** Soft-deactivate. */
    public function destroy(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        if ($id === $admin) return $this->err(409, 'You cannot deactivate your own account.');
        try {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err(404, 'User not found.');
            DB::table('users')->where('id', $id)->update([
                'is_active'  => 0,
                'updated_at' => Carbon::now(),
            ]);
            $this->audit($admin, $id, 'USER_DEACTIVATE', null, ['is_active' => 0], $r);
            return $this->ok(['id' => $id, 'is_active' => false], 'User deactivated.');
        } catch (Throwable $e) { return $this->serverError($e, 'destroy'); }
    }

    public function restore(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err(404, 'User not found.');
            DB::table('users')->where('id', $id)->update([
                'is_active'         => 1,
                'suspended_at'      => null,
                'suspension_reason' => null,
                'updated_at'        => Carbon::now(),
            ]);
            $this->audit($admin, $id, 'USER_REACTIVATE', null, ['is_active' => 1, 'suspended_at' => null], $r);
            return $this->ok(['id' => $id, 'is_active' => true], 'User reactivated.');
        } catch (Throwable $e) { return $this->serverError($e, 'restore'); }
    }

    public function suspend(Request $r, int $id): JsonResponse
    {
        $admin  = (int) (auth()->id() ?? 0);
        if ($id === $admin) return $this->err(409, 'You cannot suspend your own account.');
        $reason = trim((string) $r->input('reason', ''));
        if ($reason === '') return $this->err(422, 'Suspension reason is required.');
        try {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err(404, 'User not found.');
            DB::table('users')->where('id', $id)->update([
                'suspended_at'      => Carbon::now(),
                'suspension_reason' => $reason,
                'is_active'         => 0,
                'updated_at'        => Carbon::now(),
            ]);
            $this->audit($admin, $id, 'USER_SUSPEND', null, ['reason' => $reason], $r);
            return $this->ok(['id' => $id, 'suspended' => true, 'reason' => $reason], 'User suspended.');
        } catch (Throwable $e) { return $this->serverError($e, 'suspend'); }
    }

    public function unsuspend(Request $r, int $id): JsonResponse
    {
        return $this->restore($r, $id);
    }

    public function forcePasswordReset(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err(404, 'User not found.');
            $temp = $this->generateTempPassword();
            $hash = Hash::make($temp);
            DB::table('users')->where('id', $id)->update([
                'password'             => $hash,
                'password_hash'        => $hash,
                'must_change_password' => 1,
                'password_changed_at'  => Carbon::now(),
                'failed_login_count'   => 0,
                'locked_until'         => null,
                'updated_at'           => Carbon::now(),
            ]);
            $this->audit($admin, $id, 'USER_PASSWORD_RESET', null, ['must_change_password' => 1], $r);
            return $this->ok(['id' => $id, '_temp_password' => $temp], 'Temporary password issued.');
        } catch (Throwable $e) { return $this->serverError($e, 'forcePasswordReset'); }
    }

    /**
     * Issue a fresh email-invite token for a user. Works whether the user was
     * originally credential- or email-invited; the previous token (if any) is
     * invalidated. The user is left inactive until they accept.
     */
    public function regenerateInvite(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err(404, 'User not found.');

            $plain = self::issueInviteToken();
            $now   = Carbon::now();
            DB::table('users')->where('id', $id)->update([
                'password'              => Hash::make(Str::random(64)),
                'password_hash'         => null,
                'must_change_password'  => 1,
                'is_active'             => 0,
                'invitation_token_hash' => hash('sha256', $plain),
                'invitation_expires_at' => $now->copy()->addDays(self::INVITE_TTL_DAYS),
                'invitation_accepted_at'=> null,
                'updated_at'            => $now,
            ]);
            $this->audit($admin, $id, 'USER_INVITE_REGENERATE', null, ['invitation_expires_at' => $now->copy()->addDays(self::INVITE_TTL_DAYS)], $r);
            return $this->ok([
                'id'             => $id,
                '_invite_mode'   => 'email',
                '_invite_token'  => $plain,
                '_invite_url'    => url('/invite/' . $plain),
                '_invite_expires'=> $now->copy()->addDays(self::INVITE_TTL_DAYS)->toIso8601String(),
            ], 'New invitation link issued.');
        } catch (Throwable $e) { return $this->serverError($e, 'regenerateInvite'); }
    }

    /** Cancel an outstanding invitation — purges the token and leaves the user inactive. */
    public function revokeInvite(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err(404, 'User not found.');
            DB::table('users')->where('id', $id)->update([
                'invitation_token_hash' => null,
                'invitation_expires_at' => null,
                'updated_at'            => Carbon::now(),
            ]);
            $this->audit($admin, $id, 'USER_INVITE_REVOKE', null, [], $r);
            return $this->ok(['id' => $id, 'invite_revoked' => true], 'Invitation revoked.');
        } catch (Throwable $e) { return $this->serverError($e, 'revokeInvite'); }
    }

    public function resetMfa(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err(404, 'User not found.');
            DB::table('users')->where('id', $id)->update([
                'two_factor_secret'           => null,
                'two_factor_recovery_codes_hash' => null,
                'two_factor_confirmed_at'     => null,
                'updated_at'                  => Carbon::now(),
            ]);
            $this->audit($admin, $id, 'USER_MFA_RESET', null, [], $r);
            return $this->ok(['id' => $id, 'mfa_reset' => true], 'MFA reset.');
        } catch (Throwable $e) { return $this->serverError($e, 'resetMfa'); }
    }

    public function unlock(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $u = DB::table('users')->where('id', $id)->first();
            if (! $u) return $this->err(404, 'User not found.');
            DB::table('users')->where('id', $id)->update([
                'failed_login_count' => 0,
                'locked_until'       => null,
                'updated_at'         => Carbon::now(),
            ]);
            $this->audit($admin, $id, 'USER_UNLOCK', null, [], $r);
            return $this->ok(['id' => $id, 'unlocked' => true], 'Account unlocked.');
        } catch (Throwable $e) { return $this->serverError($e, 'unlock'); }
    }

    /* ─────────────────────────── helpers ───────────────────────── */

    private function applyTab($q, string $tab): void
    {
        switch ($tab) {
            case 'active':    $q->where('is_active', 1)->whereNull('suspended_at'); break;
            case 'suspended': $q->whereNotNull('suspended_at'); break;
            case 'inactive':  $q->where('is_active', 0)->whereNull('suspended_at'); break;
            case 'invited':   $q->whereNotNull('invitation_token_hash')->whereNull('invitation_accepted_at'); break;
            case 'all':
            default:          break;
        }
    }

    private function visible(int $userId, array $scope): bool
    {
        $level = ScopeFilter::level($scope);
        $col = match ($level) {
            'PHEOC'    => 'province_code',
            'DISTRICT' => 'district_code',
            'POE'      => 'poe_code',
            default    => null,
        };
        if ($col === null) return false;
        $values = match ($level) {
            'PHEOC'    => $scope['provinces'] ?? [],
            'DISTRICT' => $scope['districts'] ?? [],
            'POE'      => $scope['poes'] ?? [],
        };
        if (empty($values)) return false;
        return DB::table('user_assignments')
            ->where('user_id', $userId)
            ->where('is_active', 1)
            ->whereNull('ends_at')
            ->whereIn($col, $values)
            ->exists();
    }

    private function validatePayload(array $data, bool $partial): array|JsonResponse
    {
        $required = ['full_name', 'username', 'email', 'role_key', 'account_type'];
        if (! $partial) {
            foreach ($required as $f) {
                if (! isset($data[$f]) || trim((string) $data[$f]) === '') {
                    return $this->err(422, "Field '{$f}' is required.");
                }
            }
        }
        if (isset($data['account_type']) && ! in_array($data['account_type'], self::ACCOUNT_TYPES, true)) {
            return $this->err(422, 'Invalid account_type.', ['allowed' => self::ACCOUNT_TYPES]);
        }
        if (isset($data['role_key'])) {
            $exists = DB::table('role_registry')->where('role_key', $data['role_key'])->where('is_active', 1)->exists();
            if (! $exists) return $this->err(422, 'Invalid role_key.');
        }
        if (isset($data['email']) && trim((string) $data['email']) !== '' && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->err(422, 'Invalid email address.');
        }

        $payload = [];
        foreach (['full_name','username','email','phone','role_key','account_type','country_code','locale','timezone','avatar_url'] as $f) {
            if (array_key_exists($f, $data)) {
                $payload[$f] = $data[$f] === '' ? null : trim((string) $data[$f]);
            }
        }
        // 'name' column is NOT NULL in some Laravel breeze installs — mirror full_name.
        if (isset($payload['full_name']) && $payload['full_name'] !== null) {
            $payload['name'] = $payload['full_name'];
        }
        if (array_key_exists('is_active', $data)) {
            $payload['is_active'] = (int) (bool) $data['is_active'];
        }
        if (array_key_exists('must_change_password', $data)) {
            $payload['must_change_password'] = (int) (bool) $data['must_change_password'];
        }
        // Default country_code if missing on create.
        if (! $partial && empty($payload['country_code'])) {
            $payload['country_code'] = self::defaultCountry();
        }
        return $payload;
    }

    /** 64-char URL-safe invite token. Plaintext returned; store SHA-256 hash. */
    public static function issueInviteToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    private function generateTempPassword(): string
    {
        // 12 chars, mixed — easy to read aloud once.
        return Str::lower(Str::random(2)) . Str::upper(Str::random(2)) . random_int(1000, 9999) . Str::random(4);
    }

    private function audit(int $admin, int $target, string $action, ?array $before, array $after, Request $r): void
    {
        try {
            DB::table('user_audit_log')->insert([
                'actor_user_id'  => $admin ?: null,
                'target_user_id' => $target,
                'action'         => $action,
                'before_json'    => $before ? json_encode($this->scrub($before)) : null,
                'after_json'     => $after  ? json_encode($this->scrub($after))  : null,
                'ip'             => $r->ip(),
                'user_agent'     => substr((string) $r->userAgent(), 0, 500),
                'created_at'     => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('[Workforce\\Users][audit] ' . $e->getMessage());
        }
    }

    private function scrub(array $a): array
    {
        unset($a['password'], $a['password_hash'], $a['two_factor_secret'], $a['two_factor_recovery_codes_hash'], $a['invitation_token_hash']);
        return $a;
    }

    private function castRow(object $u, $assignments): array
    {
        $assigns = collect($assignments)->map(fn ($a) => (array) $a)->values()->all();
        $primary = collect($assigns)->firstWhere('is_primary', 1) ?? ($assigns[0] ?? null);

        $now = Carbon::now();
        $isLocked    = isset($u->locked_until) && $u->locked_until && Carbon::parse($u->locked_until)->isFuture();
        $isSuspended = isset($u->suspended_at) && $u->suspended_at !== null;
        $isInvited   = (isset($u->invitation_token_hash)  && $u->invitation_token_hash !== null)
                    && (! isset($u->invitation_accepted_at) || $u->invitation_accepted_at === null);
        $dormantDays = isset($u->last_login_at) && $u->last_login_at ? Carbon::parse($u->last_login_at)->diffInDays($now) : null;

        return [
            'id'                    => (int) $u->id,
            'full_name'             => (string) $u->full_name,
            'username'              => (string) $u->username,
            'email'                 => (string) $u->email,
            'phone'                 => $u->phone,
            'role_key'              => (string) $u->role_key,
            'account_type'          => (string) $u->account_type,
            'is_active'             => (bool) $u->is_active,
            'is_suspended'          => $isSuspended,
            'suspended_at'          => $u->suspended_at,
            'suspension_reason'     => $u->suspension_reason,
            'is_locked'             => (bool) $isLocked,
            'locked_until'          => $u->locked_until,
            'is_invited'            => $isInvited,
            'invitation_expires_at' => $u->invitation_expires_at ?? null,
            'invitation_accepted_at'=> $u->invitation_accepted_at ?? null,
            'must_change_password'  => (bool) $u->must_change_password,
            'mfa_enabled'           => $u->two_factor_confirmed_at !== null,
            'risk_score'            => $u->risk_score !== null ? (int) $u->risk_score : null,
            'last_login_at'         => $u->last_login_at,
            'last_login_ip'         => $u->last_login_ip,
            'dormant_days'          => $dormantDays,
            'primary_assignment'    => $primary,
            'assignments_count'     => count($assigns),
            'created_at'            => $u->created_at,
        ];
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if(!empty($meta)){$b['meta']=$meta;} return response()->json($b); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Workforce\\Users][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
