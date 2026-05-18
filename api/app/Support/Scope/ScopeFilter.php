<?php

declare(strict_types=1);

namespace App\Support\Scope;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ScopeFilter — applies the active user's PheocScope to query builders
 * for the geo reference tables.
 *
 * Scope source: ResolveScope middleware publishes the descriptor at
 *   $request->attributes->get('scope')
 * where the descriptor matches the array shape of PheocScope::forUser().
 *
 * Foundational rule (from feedback_scope_rule.md):
 *   NATIONAL_ADMIN sees everything (is_super=true → bypass).
 *   PHEOC_OFFICER sees its province + everything beneath.
 *   DISTRICT_SUPERVISOR sees its district + everything beneath.
 *   PoE roles see only their PoE.
 *
 * Every list endpoint MUST run its query through one of the apply* methods
 * BEFORE applying user-supplied filters; never trust client-supplied scope.
 *
 * Use:
 *   $scope = ScopeFilter::fromRequest($request);
 *   $q = DB::table('ref_poes')->where('country_code', 'Uganda');
 *   $q = ScopeFilter::applyToPoes($q, $scope);
 */
final class ScopeFilter
{
    /** Pull the resolved descriptor off the request. */
    public static function fromRequest(Request $request): array
    {
        $scope = $request->attributes->get('scope');
        return is_array($scope) ? $scope : [];
    }

    /** NATIONAL_ADMIN / SUPER_ADMIN / SERVICE bypass all geo filters. */
    public static function isSuper(array $scope): bool
    {
        return (bool) ($scope['is_super'] ?? false);
    }

    /** Sentinel value that matches no row — used to short-circuit empty scopes. */
    private const SENTINEL = '__none__';

    /**
     * Resolve the active scope LEVEL — strictly enforces the rule:
     *   PHEOC    → only province-wide visibility (drops district/poe sub-scoping)
     *   DISTRICT → only district visibility (ignores province expansion)
     *   POE      → only the specific PoEs in the assignment
     *   SELF     → nothing (sentinel — should never reach here in practice)
     * NATIONAL is handled separately via is_super short-circuit.
     */
    public static function level(array $scope): string
    {
        return strtoupper((string) ($scope['scope_level'] ?? 'POE'));
    }

    /* ─── ref_poes ──────────────────────────────────────────────────── */
    public static function applyToPoes(Builder $q, array $scope): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        return match (self::level($scope)) {
            'PHEOC'    => $q->whereIn('admin_level_1', self::nonEmpty($scope['provinces'] ?? []) ?: [self::SENTINEL]),
            'DISTRICT' => $q->whereIn('district',      self::nonEmpty($scope['districts'] ?? []) ?: [self::SENTINEL]),
            'POE'      => $q->whereIn('poe_code',      self::nonEmpty($scope['poes']      ?? []) ?: [self::SENTINEL]),
            default    => $q->whereRaw('1=0'),
        };
    }

    /* ─── ref_provinces ─────────────────────────────────────────────── */
    public static function applyToProvinces(Builder $q, array $scope): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        $provinces = self::nonEmpty($scope['provinces'] ?? []);
        return $q->whereIn('name', $provinces ?: [self::SENTINEL]);
    }

    /* ─── ref_districts ─────────────────────────────────────────────── */
    public static function applyToDistricts(Builder $q, array $scope): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        $level = self::level($scope);

        if ($level === 'PHEOC') {
            // PHEOC sees ALL districts within its province(s), even ones not
            // explicitly listed in user_assignments.district_code.
            $provinces = self::nonEmpty($scope['provinces'] ?? []);
            $provinceIds = $provinces
                ? DB::table('ref_provinces')->whereIn('name', $provinces)->whereNull('deleted_at')->pluck('id')->all()
                : [];
            return $q->whereIn('province_id', $provinceIds ?: [-1]);
        }

        if ($level === 'DISTRICT') {
            $districts = self::nonEmpty($scope['districts'] ?? []);
            return $q->whereIn('name', $districts ?: [self::SENTINEL]);
        }

        if ($level === 'POE') {
            // PoE-only roles see the district their PoE sits in (no further).
            $poes = self::nonEmpty($scope['poes'] ?? []);
            $districts = $poes
                ? DB::table('ref_poes')->whereIn('poe_code', $poes)->whereNull('deleted_at')->pluck('district')->unique()->all()
                : [];
            return $q->whereIn('name', $districts ?: [self::SENTINEL]);
        }

        return $q->whereRaw('1=0');
    }

    /* ─── ref_hospitals ─────────────────────────────────────────────── */
    public static function applyToHospitals(Builder $q, array $scope): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        $level = self::level($scope);

        if ($level === 'PHEOC') {
            $provinceIds = DB::table('ref_provinces')
                ->whereIn('name', self::nonEmpty($scope['provinces'] ?? []))
                ->whereNull('deleted_at')->pluck('id')->all();
            return $q->whereIn('province_id', $provinceIds ?: [-1]);
        }

        if ($level === 'DISTRICT' || $level === 'POE') {
            // DISTRICT scopes by district_id; POE scopes by district_id of its PoE.
            $districts = $level === 'DISTRICT'
                ? self::nonEmpty($scope['districts'] ?? [])
                : DB::table('ref_poes')->whereIn('poe_code', self::nonEmpty($scope['poes'] ?? []))
                    ->whereNull('deleted_at')->pluck('district')->unique()->all();
            $districtIds = $districts
                ? DB::table('ref_districts')->whereIn('name', $districts)->whereNull('deleted_at')->pluck('id')->all()
                : [];
            return $q->whereIn('district_id', $districtIds ?: [-1]);
        }

        return $q->whereRaw('1=0');
    }

    /* ─── ref_countries ─────────────────────────────────────────────── */
    public static function applyToCountries(Builder $q, array $scope): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        $countries = self::nonEmpty($scope['countries'] ?? []);
        return $q->whereIn('country_code', $countries ?: [self::SENTINEL]);
    }

    /* ─── poe_notification_contacts ─────────────────────────────────── */
    public static function applyToPoeContacts(Builder $q, array $scope): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        $level = self::level($scope);

        if ($level === 'PHEOC') {
            // PHEOC sees every contact for every district in its province(s).
            $provinces = self::nonEmpty($scope['provinces'] ?? []);
            $districts = $provinces
                ? DB::table('ref_districts')
                    ->whereIn('province_id', DB::table('ref_provinces')->whereIn('name', $provinces)->whereNull('deleted_at')->pluck('id'))
                    ->whereNull('deleted_at')->pluck('name')->all()
                : [];
            return $q->whereIn('district_code', $districts ?: [self::SENTINEL]);
        }
        if ($level === 'DISTRICT') {
            return $q->whereIn('district_code', self::nonEmpty($scope['districts'] ?? []) ?: [self::SENTINEL]);
        }
        if ($level === 'POE') {
            return $q->whereIn('poe_code', self::nonEmpty($scope['poes'] ?? []) ?: [self::SENTINEL]);
        }
        return $q->whereRaw('1=0');
    }

    /* ─── poe_status_events / poe_capacity_assessments ──────────────── */
    public static function applyToPoeOpsByPoeCode(Builder $q, array $scope, string $col = 'poe_code'): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        $level = self::level($scope);

        if ($level === 'PHEOC') {
            $provinces = self::nonEmpty($scope['provinces'] ?? []);
            $poes = $provinces
                ? DB::table('ref_poes')->whereIn('admin_level_1', $provinces)->whereNull('deleted_at')->pluck('poe_code')->all()
                : [];
            return $q->whereIn($col, $poes ?: [self::SENTINEL]);
        }
        if ($level === 'DISTRICT') {
            $districts = self::nonEmpty($scope['districts'] ?? []);
            $poes = $districts
                ? DB::table('ref_poes')->whereIn('district', $districts)->whereNull('deleted_at')->pluck('poe_code')->all()
                : [];
            return $q->whereIn($col, $poes ?: [self::SENTINEL]);
        }
        if ($level === 'POE') {
            return $q->whereIn($col, self::nonEmpty($scope['poes'] ?? []) ?: [self::SENTINEL]);
        }
        return $q->whereRaw('1=0');
    }

    /* ─── users (workforce) ─────────────────────────────────────────── */
    /**
     * A user is "in scope" iff at least one of their ACTIVE assignment rows
     * matches the active scope's province / district / poe set. NATIONAL_ADMIN
     * sees everyone (including users with no assignments yet).
     */
    public static function applyToUsers(Builder $q, array $scope, string $alias = 'users'): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        $level = self::level($scope);
        $col   = match ($level) {
            'PHEOC'    => 'province_code',
            'DISTRICT' => 'district_code',
            'POE'      => 'poe_code',
            default    => null,
        };
        if ($col === null) {
            return $q->whereRaw('1=0');
        }
        $values = match ($level) {
            'PHEOC'    => self::nonEmpty($scope['provinces'] ?? []),
            'DISTRICT' => self::nonEmpty($scope['districts'] ?? []),
            'POE'      => self::nonEmpty($scope['poes']      ?? []),
        };
        $values = $values ?: [self::SENTINEL];

        return $q->whereExists(function ($w) use ($alias, $col, $values): void {
            $w->select(DB::raw(1))
              ->from('user_assignments as ua_scope')
              ->whereColumn('ua_scope.user_id', $alias . '.id')
              ->where('ua_scope.is_active', 1)
              ->whereNull('ua_scope.ends_at')
              ->whereIn('ua_scope.' . $col, $values);
        });
    }

    /* ─── user_assignments ──────────────────────────────────────────── */
    public static function applyToUserAssignments(Builder $q, array $scope, string $alias = 'user_assignments'): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        return match (self::level($scope)) {
            'PHEOC'    => $q->whereIn($alias . '.province_code', self::nonEmpty($scope['provinces'] ?? []) ?: [self::SENTINEL]),
            'DISTRICT' => $q->whereIn($alias . '.district_code', self::nonEmpty($scope['districts'] ?? []) ?: [self::SENTINEL]),
            'POE'      => $q->whereIn($alias . '.poe_code',      self::nonEmpty($scope['poes']      ?? []) ?: [self::SENTINEL]),
            default    => $q->whereRaw('1=0'),
        };
    }

    /* ─── user_training_records (scope by referenced user) ──────────── */
    public static function applyToTrainings(Builder $q, array $scope, string $alias = 'user_training_records'): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        $level = self::level($scope);
        $col   = match ($level) {
            'PHEOC'    => 'province_code',
            'DISTRICT' => 'district_code',
            'POE'      => 'poe_code',
            default    => null,
        };
        if ($col === null) {
            return $q->whereRaw('1=0');
        }
        $values = match ($level) {
            'PHEOC'    => self::nonEmpty($scope['provinces'] ?? []),
            'DISTRICT' => self::nonEmpty($scope['districts'] ?? []),
            'POE'      => self::nonEmpty($scope['poes']      ?? []),
        };
        $values = $values ?: [self::SENTINEL];
        return $q->whereExists(function ($w) use ($alias, $col, $values): void {
            $w->select(DB::raw(1))
              ->from('user_assignments as ua_t')
              ->whereColumn('ua_t.user_id', $alias . '.user_id')
              ->where('ua_t.is_active', 1)
              ->whereNull('ua_t.ends_at')
              ->whereIn('ua_t.' . $col, $values);
        });
    }

    /* ─── alerts (Section 03) ───────────────────────────────────────── */
    /**
     * Scope `alerts` directly via its denormalised province/district/poe codes.
     * Pass an alias when joining (e.g. 'a' if you used `alerts as a`).
     */
    public static function applyToAlerts(Builder $q, array $scope, string $alias = 'alerts'): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        return match (self::level($scope)) {
            'PHEOC'    => $q->whereIn($alias . '.province_code', self::nonEmpty($scope['provinces'] ?? []) ?: [self::SENTINEL]),
            'DISTRICT' => $q->whereIn($alias . '.district_code', self::nonEmpty($scope['districts'] ?? []) ?: [self::SENTINEL]),
            'POE'      => $q->whereIn($alias . '.poe_code',      self::nonEmpty($scope['poes']      ?? []) ?: [self::SENTINEL]),
            default    => $q->whereRaw('1=0'),
        };
    }

    /* ─── alert_followups ───────────────────────────────────────────── */
    public static function applyToAlertFollowups(Builder $q, array $scope, string $alias = 'alert_followups'): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        return match (self::level($scope)) {
            // followups have no province_code — reach through to alerts for PHEOC scope
            'PHEOC'    => $q->whereExists(function ($w) use ($alias, $scope): void {
                $w->select(DB::raw(1))->from('alerts as a_pf')
                  ->whereColumn('a_pf.id', $alias . '.alert_id')
                  ->whereIn('a_pf.province_code', self::nonEmpty($scope['provinces'] ?? []) ?: [self::SENTINEL]);
            }),
            'DISTRICT' => $q->whereIn($alias . '.district_code', self::nonEmpty($scope['districts'] ?? []) ?: [self::SENTINEL]),
            'POE'      => $q->whereIn($alias . '.poe_code',      self::nonEmpty($scope['poes']      ?? []) ?: [self::SENTINEL]),
            default    => $q->whereRaw('1=0'),
        };
    }

    /* ─── alert_timeline_events ─────────────────────────────────────── */
    /** Timeline events scope by reaching through alert_id → alerts. */
    public static function applyToTimelineEvents(Builder $q, array $scope, string $alias = 'alert_timeline_events'): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        $level = self::level($scope);
        $col = match ($level) {
            'PHEOC'    => 'province_code',
            'DISTRICT' => 'district_code',
            'POE'      => 'poe_code',
            default    => null,
        };
        if ($col === null) {
            return $q->whereRaw('1=0');
        }
        $values = match ($level) {
            'PHEOC'    => self::nonEmpty($scope['provinces'] ?? []),
            'DISTRICT' => self::nonEmpty($scope['districts'] ?? []),
            'POE'      => self::nonEmpty($scope['poes']      ?? []),
        };
        $values = $values ?: [self::SENTINEL];
        return $q->whereExists(function ($w) use ($alias, $col, $values): void {
            $w->select(DB::raw(1))->from('alerts as a_te')
              ->whereColumn('a_te.id', $alias . '.alert_id')
              ->whereIn('a_te.' . $col, $values);
        });
    }

    /* ─── alert_breach_reports ──────────────────────────────────────── */
    public static function applyToBreachReports(Builder $q, array $scope, string $alias = 'alert_breach_reports'): Builder
    {
        if (self::isSuper($scope)) {
            return $q;
        }
        $level = self::level($scope);
        $col = match ($level) {
            'PHEOC'    => 'province_code',
            'DISTRICT' => 'district_code',
            'POE'      => 'poe_code',
            default    => null,
        };
        if ($col === null) {
            return $q->whereRaw('1=0');
        }
        $values = match ($level) {
            'PHEOC'    => self::nonEmpty($scope['provinces'] ?? []),
            'DISTRICT' => self::nonEmpty($scope['districts'] ?? []),
            'POE'      => self::nonEmpty($scope['poes']      ?? []),
        };
        $values = $values ?: [self::SENTINEL];
        return $q->whereExists(function ($w) use ($alias, $col, $values): void {
            $w->select(DB::raw(1))->from('alerts as a_br')
              ->whereColumn('a_br.id', $alias . '.alert_id')
              ->whereIn('a_br.' . $col, $values);
        });
    }

    /**
     * Single-row visibility check: can the active scope SEE this alert?
     * Used as a guard before any per-alert read or write.
     */
    public static function canSeeAlert(array $scope, object $alert): bool
    {
        if (self::isSuper($scope)) return true;
        return match (self::level($scope)) {
            'PHEOC'    => in_array($alert->province_code ?? null, self::nonEmpty($scope['provinces'] ?? []), true),
            'DISTRICT' => in_array($alert->district_code ?? null, self::nonEmpty($scope['districts'] ?? []), true),
            'POE'      => in_array($alert->poe_code      ?? null, self::nonEmpty($scope['poes']      ?? []), true),
            default    => false,
        };
    }

    /* ─── helpers ───────────────────────────────────────────────────── */

    private static function nonEmpty(array $a): array
    {
        return array_values(array_filter($a, fn ($v) => $v !== null && $v !== ''));
    }
}
