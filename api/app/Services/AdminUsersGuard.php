<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AdminUsersGuard
 * ─────────────────────────────────────────────────────────────────────────
 * User-management specific authorisation layer. Sits on top of PheocScope.
 *
 *   · applyListFilter()           → scopes /admin/users list to actor's geo
 *   · assertCanManageTarget()     → read/mutate guard on a target user id
 *   · assertAssignmentInScope()   → validate incoming assignment payload
 *   · assertCanAssignRole()       → prevent upward privilege escalation
 *   · assertNotSelfDestructive()  → block suspend/delete on self
 *
 * Every method throws ValidationException (422) or aborts with 403, so the
 * controller layer is a single call-site and the JSON shape stays uniform.
 */
final class AdminUsersGuard
{
    /** Roles that ONLY a NATIONAL_ADMIN may create / assign / demote to. */
    public const NATIONAL_ONLY_ROLES = ['NATIONAL_ADMIN', 'SERVICE'];

    /** Roles a PHEOC officer is permitted to manage within their province. */
    public const PHEOC_ASSIGNABLE_ROLES = [
        'PHEOC_OFFICER', 'DISTRICT_SUPERVISOR', 'POE_ADMIN',
        'POE_OFFICER', 'POE_DATA_OFFICER', 'SCREENER', 'OBSERVER',
    ];

    /** Roles a district supervisor is permitted to manage within their district. */
    public const DISTRICT_ASSIGNABLE_ROLES = [
        'DISTRICT_SUPERVISOR', 'POE_ADMIN', 'POE_OFFICER',
        'POE_DATA_OFFICER', 'SCREENER', 'OBSERVER',
    ];

    /** Roles a POE admin is permitted to manage within their POE. */
    public const POE_ASSIGNABLE_ROLES = [
        'POE_OFFICER', 'POE_DATA_OFFICER', 'SCREENER', 'OBSERVER',
    ];

    public function __construct(private readonly PheocScope $scope) {}

    /** Resolve the actor's scope once per request. */
    public function scope(User $actor): array
    {
        return $this->scope->forUser($actor);
    }

    /**
     * Scope a users-list builder by the actor's geography.
     *
     * Applies on the primary active user_assignments row of EACH user, joined
     * via `ua_scope`. Super-users bypass. If the actor has no scope data we
     * deny rather than leak (consistent with PheocScope::applyToQuery).
     */
    public function applyListFilter(Builder $q, User $actor): void
    {
        $scope = $this->scope($actor);
        if ($scope['is_super']) {
            return;
        }

        $q->leftJoin('user_assignments as ua_scope', function ($j) {
            $j->on('ua_scope.user_id', '=', 'u.id')
              ->where('ua_scope.is_primary', 1)
              ->where('ua_scope.is_active', 1)
              ->whereNull('ua_scope.ends_at');
        });

        if (! empty($scope['poes'])) {
            $q->whereIn('ua_scope.poe_code', $scope['poes']);
            return;
        }
        if (! empty($scope['districts'])) {
            $q->whereIn('ua_scope.district_code', $scope['districts']);
            return;
        }
        if (! empty($scope['provinces'])) {
            $q->where(function ($w) use ($scope) {
                $w->whereIn('ua_scope.pheoc_code', $scope['provinces'])
                  ->orWhereIn('ua_scope.province_code', $scope['provinces']);
            });
            return;
        }
        if (! empty($scope['countries'])) {
            $q->whereIn('ua_scope.country_code', $scope['countries']);
            return;
        }

        $q->whereRaw('1 = 0');
    }

    /**
     * May actor read/mutate the target user?
     *
     * @throws ValidationException on scope miss (422).
     */
    public function assertCanManageTarget(User $actor, int $targetUserId): void
    {
        $scope = $this->scope($actor);
        if ($scope['is_super']) {
            return;
        }
        if ((int) $actor->id === $targetUserId) {
            // Self-read always ok; mutation guarded separately by assertNotSelfDestructive().
            return;
        }

        $target = DB::table('user_assignments')
            ->where('user_id', $targetUserId)
            ->where('is_primary', 1)
            ->where('is_active', 1)
            ->whereNull('ends_at')
            ->first();

        $targetRole = (string) DB::table('users')->where('id', $targetUserId)->value('role_key');
        if (in_array($targetRole, self::NATIONAL_ONLY_ROLES, true)) {
            throw ValidationException::withMessages([
                'scope' => 'Only NATIONAL_ADMIN may manage users at this tier.',
            ])->status(403);
        }

        if (! $target) {
            throw ValidationException::withMessages([
                'scope' => 'Target user has no active primary assignment — only NATIONAL_ADMIN may manage unassigned accounts.',
            ])->status(403);
        }

        $ok = match (true) {
            ! empty($scope['poes'])      => in_array((string) $target->poe_code,      $scope['poes'],      true),
            ! empty($scope['districts']) => in_array((string) $target->district_code, $scope['districts'], true),
            ! empty($scope['provinces']) => in_array((string) ($target->pheoc_code ?: $target->province_code), $scope['provinces'], true),
            ! empty($scope['countries']) => in_array((string) $target->country_code,  $scope['countries'],  true),
            default                      => false,
        };

        if (! $ok) {
            throw ValidationException::withMessages([
                'scope' => 'Target user is outside your administrative scope.',
            ])->status(403);
        }
    }

    /**
     * The incoming assignment (create/update) must fit inside the actor's geo.
     * Admins bypass. No-op for empty payloads (upstream validator handles nulls).
     */
    public function assertAssignmentInScope(User $actor, array $assignment): void
    {
        $scope = $this->scope($actor);
        if ($scope['is_super'] || empty($assignment)) {
            return;
        }

        $province = $assignment['pheoc_code'] ?? $assignment['province_code'] ?? null;
        $district = $assignment['district_code'] ?? null;
        $poe      = $assignment['poe_code']      ?? null;
        $country  = $assignment['country_code']  ?? null;

        if (! empty($scope['poes']) && $poe && ! in_array($poe, $scope['poes'], true)) {
            throw ValidationException::withMessages([
                'assignment.poe_code' => 'POE is outside your scope.',
            ])->status(403);
        }
        if (! empty($scope['districts']) && $district && ! in_array($district, $scope['districts'], true)) {
            throw ValidationException::withMessages([
                'assignment.district_code' => 'District is outside your scope.',
            ])->status(403);
        }
        if (! empty($scope['provinces']) && $province && ! in_array($province, $scope['provinces'], true)) {
            throw ValidationException::withMessages([
                'assignment.province_code' => 'Provincial PHEOC is outside your scope.',
            ])->status(403);
        }
        if (! empty($scope['countries']) && $country && ! in_array($country, $scope['countries'], true)) {
            throw ValidationException::withMessages([
                'assignment.country_code' => 'Country is outside your scope.',
            ])->status(403);
        }
    }

    /**
     * Only admins may assign NATIONAL_ADMIN / SERVICE. PHEOC / DISTRICT / POE
     * admins are each restricted to a subset of roles at or below their tier.
     */
    public function assertCanAssignRole(User $actor, string $roleKey): void
    {
        $scope = $this->scope($actor);
        if ($scope['is_super']) {
            return;
        }
        if (in_array($roleKey, self::NATIONAL_ONLY_ROLES, true)) {
            throw ValidationException::withMessages([
                'role_key' => 'Only NATIONAL_ADMIN may assign this role.',
            ])->status(403);
        }

        $allowed = match ($scope['scope_level']) {
            'PHEOC'    => self::PHEOC_ASSIGNABLE_ROLES,
            'DISTRICT' => self::DISTRICT_ASSIGNABLE_ROLES,
            'POE'      => self::POE_ASSIGNABLE_ROLES,
            default    => [],
        };

        if (! in_array($roleKey, $allowed, true)) {
            throw ValidationException::withMessages([
                'role_key' => 'You are not permitted to assign the role '.$roleKey.'.',
            ])->status(403);
        }
    }

    /**
     * Block suspend / delete / role-change on self to prevent lock-out and
     * stop a compromised admin from demoting themselves for cover.
     */
    public function assertNotSelfDestructive(User $actor, int $targetId, string $action): void
    {
        if ((int) $actor->id !== $targetId) {
            return;
        }
        if (in_array($action, ['suspend', 'delete', 'role_change', 'force_mfa_reset'], true)) {
            throw ValidationException::withMessages([
                'target' => 'You cannot perform this action on your own account.',
            ])->status(403);
        }
    }
}
