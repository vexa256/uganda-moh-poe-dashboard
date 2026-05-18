<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\UserController as MobileUserController;
use App\Services\AuthEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * UserAssignmentsController
 * ─────────────────────────────────────────────────────────────────────────
 * Per-user country → province → pheoc → district → POE scoping.
 *
 * Invariants this controller guarantees:
 *   · SSOT name validation — every province/pheoc/district/poe string
 *     must be present in MobileUserController::VALID_* (the POES.JS mirror).
 *   · SINGLE ACTIVE POE per user — a user is at ONE Point-of-Entry at a time.
 *     A second active row with a non-null poe_code is a 409 CONFLICT unless
 *     the client opts into `force=true`, which auto-ends the previous active
 *     POE row (ends_at = now, is_active = 0) and keeps it in history.
 *   · Role-geo alignment — a SCREENER always has Provincial PHEOC+District+POE,
 *     a DISTRICT_SUPERVISOR needs Provincial PHEOC+District, a PHEOC_OFFICER needs
 *     only Provincial PHEOC. Mirrors the same rule enforced in UsersAdminController.
 *   · pheoc_code is always kept in lockstep with province_code (same domain).
 *   · At least one primary row — the first active row becomes primary
 *     automatically; setting is_primary=true on another row demotes siblings.
 *   · Full audit trail — every mutation writes user_audit_log (before/after
 *     diff) AND auth_events (ASSIGNMENT_CHANGED).
 *
 * Endpoint map (all under /api/v2/admin, auth:sanctum):
 *   GET    /users/{id}/assignments
 *   POST   /users/{id}/assignments                body incl. force?:bool
 *   PATCH  /user-assignments/{id}                 scope / is_primary / is_active
 *   DELETE /user-assignments/{id}                 soft end (is_active=0, ends_at=now)
 *   GET    /assignments                           cross-user search
 */
final class UserAssignmentsController extends Controller
{
    public function indexForUser(int $id): JsonResponse
    {
        try {
            $user = DB::table('users')->where('id', $id)->first();
            if (! $user) return $this->err('User not found', 404);

            $rows = DB::table('user_assignments')->where('user_id', $id)
                ->orderByDesc('is_primary')->orderByDesc('is_active')
                ->orderBy('country_code')->orderByDesc('updated_at')->get();

            $activePoe = DB::table('user_assignments')->where('user_id', $id)
                ->where('is_active', 1)->whereNotNull('poe_code')
                ->whereNull('ends_at')->orderByDesc('is_primary')->first();

            $history = DB::table('auth_events')->where('user_id', $id)
                ->where('event_type', 'ASSIGNMENT_CHANGED')
                ->orderByDesc('created_at')->limit(20)->get();

            return $this->ok([
                'user' => [
                    'id' => (int) $user->id,
                    'full_name' => $user->full_name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'role_key' => $user->role_key,
                    'country_code' => $user->country_code,
                ],
                'assignments' => $rows,
                'active_poe'  => $activePoe,          // null or the one active POE row
                'history'     => $history,            // last 20 ASSIGNMENT_CHANGED
                'invariants'  => [
                    'single_active_poe' => true,
                    'role_geo_enforced' => true,
                    'ssot_names_only'   => true,
                ],
            ]);
        } catch (Throwable $e) { return $this->fail($e, 'indexForUser'); }
    }

    public function searchAll(Request $r): JsonResponse
    {
        try {
            $q = DB::table('user_assignments as a')
                ->join('users as u', 'u.id', '=', 'a.user_id')
                ->select('a.*',
                    'u.full_name', 'u.email', 'u.username', 'u.role_key',
                    'u.is_active as user_active', 'u.suspended_at');
            foreach (['country_code', 'district_code', 'poe_code', 'province_code'] as $col) {
                if ($v = $r->query($col)) $q->where("a.$col", $v);
            }
            if ($r->query('active') === '1') $q->where('a.is_active', 1)->whereNull('a.ends_at');
            if ($r->query('primary') === '1') $q->where('a.is_primary', 1);
            if ($s = $r->query('search')) {
                $q->where(function ($x) use ($s) {
                    $x->where('u.full_name', 'like', "%$s%")
                      ->orWhere('u.email', 'like', "%$s%")
                      ->orWhere('u.username', 'like', "%$s%");
                });
            }
            $limit = min(500, max(10, (int) $r->query('limit', 200)));
            $rows = $q->orderByDesc('a.is_primary')->orderBy('a.country_code')
                ->limit($limit)->get();
            return $this->ok(['assignments' => $rows, 'count' => $rows->count()]);
        } catch (Throwable $e) { return $this->fail($e, 'searchAll'); }
    }

    public function store(Request $r, int $userId): JsonResponse
    {
        return $this->tx(function () use ($r, $userId) {
            $user = DB::table('users')->where('id', $userId)->first();
            if (! $user) return $this->err('User not found', 404);

            $data = Validator::make($r->all(), [
                'country_code'  => 'required|string|max:10',
                'province_code' => ['sometimes','nullable','string','max:30', Rule::in(MobileUserController::VALID_PHEOC_NAMES)],
                'pheoc_code'    => ['sometimes','nullable','string','max:30', Rule::in(MobileUserController::VALID_PHEOC_NAMES)],
                'district_code' => ['sometimes','nullable','string','max:30', Rule::in(MobileUserController::VALID_DISTRICT_NAMES)],
                'poe_code'      => ['sometimes','nullable','string','max:40', Rule::in(MobileUserController::VALID_POE_NAMES)],
                'is_primary'    => 'sometimes|boolean',
                'is_active'     => 'sometimes|boolean',
                'starts_at'     => 'sometimes|nullable|date',
                'ends_at'       => 'sometimes|nullable|date',
                'force'         => 'sometimes|boolean',
            ], [
                'province_code.in' => 'Invalid Provincial PHEOC — must exactly match POES.JS.',
                'pheoc_code.in'    => 'Invalid PHEOC — must exactly match POES.JS.',
                'district_code.in' => 'Invalid district — must exactly match POES.JS.',
                'poe_code.in'      => 'Invalid POE — must exactly match POES.JS.',
            ])->validate();

            // pheoc_code always rides with province_code
            $province = $data['province_code'] ?? ($data['pheoc_code'] ?? null);
            $pheoc    = $data['pheoc_code']    ?? $province;

            // Role-geo enforcement against the user's current role_key
            $this->enforceGeographyForRole((string) $user->role_key, [
                'province_code' => $province,
                'pheoc_code'    => $pheoc,
                'district_code' => $data['district_code'] ?? null,
                'poe_code'      => $data['poe_code']      ?? null,
            ]);

            $isActive  = array_key_exists('is_active',  $data) ? (bool) $data['is_active']  : true;
            $isPrimary = array_key_exists('is_primary', $data) ? (bool) $data['is_primary'] : false;
            $force     = (bool) ($data['force'] ?? false);

            // ─── SINGLE-ACTIVE-POE INVARIANT ───────────────────────────────
            // If the incoming row carries a POE and would be active, refuse
            // (409) if any other active-POE row exists for this user.
            if ($isActive && ! empty($data['poe_code'])) {
                $conflict = DB::table('user_assignments')
                    ->where('user_id', $userId)
                    ->where('is_active', 1)
                    ->whereNotNull('poe_code')
                    ->whereNull('ends_at')
                    ->first();
                if ($conflict) {
                    if (! $force) {
                        return response()->json([
                            'ok' => false,
                            'error' => 'This user is already assigned to POE "'.$conflict->poe_code.'". A user can only be active at one POE at a time. Pass `force:true` to transfer (automatically ends the previous POE assignment).',
                            'code'  => 'SINGLE_ACTIVE_POE',
                            'blocking' => [
                                'id'            => (int) $conflict->id,
                                'country_code'  => $conflict->country_code,
                                'province_code' => $conflict->province_code,
                                'district_code' => $conflict->district_code,
                                'poe_code'      => $conflict->poe_code,
                                'starts_at'     => $conflict->starts_at,
                            ],
                        ], 409);
                    }
                    // force=true → end the previous active POE row
                    DB::table('user_assignments')->where('id', $conflict->id)->update([
                        'is_active'  => 0,
                        'is_primary' => 0,
                        'ends_at'    => now(),
                        'updated_at' => now(),
                    ]);
                    AuthEventLogger::log('ASSIGNMENT_CHANGED', $userId, null, 'WARN',
                        ['action' => 'AUTO_END_ON_TRANSFER', 'assignment_id' => $conflict->id,
                         'replaced_poe' => $conflict->poe_code], 0, $r);
                    $this->audit((int) $r->user()->id, $userId, 'ASSIGNMENT_AUTO_END',
                        (array) $conflict, null, $r);
                }
            }

            // ─── PRIMARY INVARIANT ─────────────────────────────────────────
            // If no active-primary row exists, this one becomes primary by default.
            // If caller set is_primary=true, demote siblings.
            $hasPrimary = DB::table('user_assignments')
                ->where('user_id', $userId)->where('is_primary', 1)->where('is_active', 1)
                ->whereNull('ends_at')->exists();
            if (! $hasPrimary) $isPrimary = true;
            if ($isPrimary) {
                DB::table('user_assignments')->where('user_id', $userId)
                    ->update(['is_primary' => 0]);
            }

            $insert = [
                'user_id'       => $userId,
                'country_code'  => $data['country_code'],
                'province_code' => $province,
                'pheoc_code'    => $pheoc,
                'district_code' => $data['district_code'] ?? null,
                'poe_code'      => $data['poe_code']      ?? null,
                'is_primary'    => $isPrimary ? 1 : 0,
                'is_active'     => $isActive  ? 1 : 0,
                'starts_at'     => $data['starts_at'] ?? now(),
                'ends_at'       => $data['ends_at']   ?? null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ];
            $id = DB::table('user_assignments')->insertGetId($insert);

            $this->audit((int) $r->user()->id, $userId, 'ASSIGNMENT_CREATE',
                null, array_merge(['id' => $id], $insert), $r);
            AuthEventLogger::log('ASSIGNMENT_CHANGED', $userId, null, 'INFO',
                ['action' => 'CREATE', 'assignment_id' => $id,
                 'force' => $force, 'is_primary' => $isPrimary], 0, $r);

            $row = DB::table('user_assignments')->where('id', $id)->first();
            return $this->ok(['id' => $id, 'assignment' => $row], 201);
        }, 'store');
    }

    public function update(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $row = DB::table('user_assignments')->where('id', $id)->first();
            if (! $row) return $this->err('Assignment not found', 404);

            $data = Validator::make($r->all(), [
                'country_code' => 'sometimes|string|max:10',
                'province_code'=> ['sometimes','nullable','string','max:30', Rule::in(MobileUserController::VALID_PHEOC_NAMES)],
                'pheoc_code'   => ['sometimes','nullable','string','max:30', Rule::in(MobileUserController::VALID_PHEOC_NAMES)],
                'district_code'=> ['sometimes','nullable','string','max:30', Rule::in(MobileUserController::VALID_DISTRICT_NAMES)],
                'poe_code'     => ['sometimes','nullable','string','max:40', Rule::in(MobileUserController::VALID_POE_NAMES)],
                'is_primary'   => 'sometimes|boolean',
                'is_active'    => 'sometimes|boolean',
                'ends_at'      => 'sometimes|nullable|date',
                'force'        => 'sometimes|boolean',
            ])->validate();

            $force = (bool) ($data['force'] ?? false);

            // Compose the would-be row so we can validate geo + POE conflict
            $nextCountry  = $data['country_code']  ?? $row->country_code;
            $nextProvince = array_key_exists('province_code', $data) ? $data['province_code'] : $row->province_code;
            $nextPheoc    = array_key_exists('pheoc_code', $data)    ? $data['pheoc_code']    : ($nextProvince ?? $row->pheoc_code);
            if ($nextProvince && ! $nextPheoc) $nextPheoc = $nextProvince;
            $nextDistrict = array_key_exists('district_code', $data) ? $data['district_code'] : $row->district_code;
            $nextPoe      = array_key_exists('poe_code', $data)      ? $data['poe_code']      : $row->poe_code;
            $nextActive   = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : (bool) $row->is_active;
            $nextPrimary  = array_key_exists('is_primary', $data) ? (bool) $data['is_primary'] : (bool) $row->is_primary;

            $user = DB::table('users')->where('id', $row->user_id)->first();
            if ($user) {
                $this->enforceGeographyForRole((string) $user->role_key, [
                    'province_code' => $nextProvince,
                    'pheoc_code'    => $nextPheoc,
                    'district_code' => $nextDistrict,
                    'poe_code'      => $nextPoe,
                ]);
            }

            // Single-active-POE guard (compare against sibling rows)
            if ($nextActive && ! empty($nextPoe)) {
                $conflict = DB::table('user_assignments')
                    ->where('user_id', $row->user_id)
                    ->where('id', '!=', $id)
                    ->where('is_active', 1)
                    ->whereNotNull('poe_code')
                    ->whereNull('ends_at')
                    ->first();
                if ($conflict) {
                    if (! $force) {
                        return response()->json([
                            'ok' => false,
                            'error' => 'User already has an active POE assignment ("'.$conflict->poe_code.'"). Pass `force:true` to transfer.',
                            'code' => 'SINGLE_ACTIVE_POE',
                            'blocking' => (array) $conflict,
                        ], 409);
                    }
                    DB::table('user_assignments')->where('id', $conflict->id)->update([
                        'is_active' => 0, 'is_primary' => 0, 'ends_at' => now(), 'updated_at' => now(),
                    ]);
                    AuthEventLogger::log('ASSIGNMENT_CHANGED', (int) $row->user_id, null, 'WARN',
                        ['action' => 'AUTO_END_ON_UPDATE', 'assignment_id' => $conflict->id], 0, $r);
                }
            }

            if ($nextPrimary) {
                DB::table('user_assignments')->where('user_id', $row->user_id)
                    ->where('id', '!=', $id)->update(['is_primary' => 0]);
            }

            $update = [
                'country_code'  => $nextCountry,
                'province_code' => $nextProvince,
                'pheoc_code'    => $nextPheoc,
                'district_code' => $nextDistrict,
                'poe_code'      => $nextPoe,
                'is_primary'    => $nextPrimary ? 1 : 0,
                'is_active'     => $nextActive  ? 1 : 0,
                'ends_at'       => $data['ends_at'] ?? $row->ends_at,
                'updated_at'    => now(),
            ];
            DB::table('user_assignments')->where('id', $id)->update($update);

            // Guarantee user has exactly one primary row (the first active row wins).
            $primaryExists = DB::table('user_assignments')->where('user_id', $row->user_id)
                ->where('is_primary', 1)->where('is_active', 1)->exists();
            if (! $primaryExists) {
                $firstActive = DB::table('user_assignments')->where('user_id', $row->user_id)
                    ->where('is_active', 1)->whereNull('ends_at')->orderBy('id')->first();
                if ($firstActive) {
                    DB::table('user_assignments')->where('id', $firstActive->id)
                        ->update(['is_primary' => 1, 'updated_at' => now()]);
                }
            }

            $this->audit((int) $r->user()->id, (int) $row->user_id, 'ASSIGNMENT_UPDATE',
                (array) $row, array_merge(['id' => $id], $update), $r);
            AuthEventLogger::log('ASSIGNMENT_CHANGED', (int) $row->user_id, null, 'INFO',
                ['action' => 'UPDATE', 'assignment_id' => $id, 'force' => $force], 0, $r);

            $fresh = DB::table('user_assignments')->where('id', $id)->first();
            return $this->ok(['updated' => true, 'assignment' => $fresh]);
        }, 'update');
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        return $this->tx(function () use ($r, $id) {
            $row = DB::table('user_assignments')->where('id', $id)->first();
            if (! $row) return $this->err('Assignment not found', 404);

            DB::table('user_assignments')->where('id', $id)->update([
                'is_active'  => 0,
                'is_primary' => 0,
                'ends_at'    => now(),
                'updated_at' => now(),
            ]);

            // Promote another active row to primary if needed
            $primaryExists = DB::table('user_assignments')->where('user_id', $row->user_id)
                ->where('is_primary', 1)->where('is_active', 1)->exists();
            if (! $primaryExists) {
                $firstActive = DB::table('user_assignments')->where('user_id', $row->user_id)
                    ->where('is_active', 1)->whereNull('ends_at')->orderBy('id')->first();
                if ($firstActive) {
                    DB::table('user_assignments')->where('id', $firstActive->id)
                        ->update(['is_primary' => 1, 'updated_at' => now()]);
                }
            }

            $this->audit((int) $r->user()->id, (int) $row->user_id, 'ASSIGNMENT_END',
                (array) $row, null, $r);
            AuthEventLogger::log('ASSIGNMENT_CHANGED', (int) $row->user_id, null, 'INFO',
                ['action' => 'END', 'assignment_id' => $id], 0, $r);

            return $this->ok(['ended' => true]);
        }, 'destroy');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Role-geo enforcement — mirrors MobileUserController + UsersAdminController. */
    private function enforceGeographyForRole(string $roleKey, array $a): void
    {
        $reqs = MobileUserController::ROLE_GEO_REQUIREMENTS[$roleKey] ?? null;
        if ($reqs === null || empty($reqs)) return;

        if (in_array('province_or_pheoc', $reqs, true)
            && empty($a['province_code']) && empty($a['pheoc_code'])) {
            throw ValidationException::withMessages([
                'province_code' => 'Provincial PHEOC / Province required for role '.$roleKey.'.',
            ]);
        }
        if (in_array('district_code', $reqs, true) && empty($a['district_code'])) {
            throw ValidationException::withMessages([
                'district_code' => 'District required for role '.$roleKey.'.',
            ]);
        }
        if (in_array('poe_code', $reqs, true) && empty($a['poe_code'])) {
            throw ValidationException::withMessages([
                'poe_code' => 'POE required for role '.$roleKey.'.',
            ]);
        }
    }

    private function audit(int $actor, int $target, string $action, $before, $after, Request $r): void
    {
        DB::table('user_audit_log')->insert([
            'actor_user_id'  => $actor,
            'target_user_id' => $target,
            'action'         => $action,
            'before_json'    => $before !== null ? json_encode($before) : null,
            'after_json'     => $after  !== null ? json_encode($after)  : null,
            'ip'             => $r->ip(),
            'user_agent'     => mb_substr((string) $r->userAgent(), 0, 300),
            'created_at'     => now(),
        ]);
    }

    private function ok(array $d = [], int $c = 200): JsonResponse { return response()->json(['ok' => true, 'data' => $d], $c); }
    private function err(string $m, int $c = 400): JsonResponse  { return response()->json(['ok' => false, 'error' => $m], $c); }
    private function fail(Throwable $e, string $ctx): JsonResponse {
        \Log::error("[UserAssignments::$ctx] ".$e->getMessage());
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
