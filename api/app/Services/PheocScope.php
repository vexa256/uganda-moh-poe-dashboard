<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * PheocScope
 * ---------------------------------------------------------------------------
 * Single source of truth for "what geography can this user see?"
 *
 * Every admin list query and every data-bound view MUST funnel its geographic
 * filters through this service. The scope object returned is immutable and
 * memoised per-request so it is cheap to pull from anywhere (controllers,
 * view composers, middleware, policies).
 *
 * NATIONAL_ADMIN is elevated: is_super=true means no geographic restriction.
 * Every other role is scoped to the ACTIVE rows in user_assignments.
 *
 * INVARIANT (enforced in RequirePoeAssignment middleware):
 *   Every authenticated user — INCLUDING NATIONAL_ADMIN — must have at least
 *   one active user_assignments row with poe_code IS NOT NULL. Admins still
 *   see the whole country; the POE link exists so audits can always answer
 *   "what POE is this person physically tethered to?".
 */
class PheocScope
{
    /** Single request-scoped cache keyed by user id. */
    protected static array $cache = [];

    /**
     * Compute the scope descriptor for a user.
     *
     * @return array{
     *     user_id:int,
     *     role_key:string,
     *     account_type:string,
     *     scope_level:string,          // NATIONAL|PHEOC|DISTRICT|POE|SELF
     *     is_super:bool,               // true → bypass geo filters everywhere
     *     country_code:?string,        // primary country (usually UG)
     *     countries:array<int,string>, // visible countries
     *     provinces:array<int,string>, // visible province_code (== pheoc_code)
     *     districts:array<int,string>, // visible district_code
     *     poes:array<int,string>,      // visible poe_code
     *     primary_poe:?string,         // active primary POE name (for tenant chip)
     *     assignments:array,           // raw active rows
     *     label:string,                // human label e.g. "Kasese District · Mpondwe POE"
     * }
     */
    public function forUser(User $user): array
    {
        $uid = (int) $user->getKey();
        if (isset(self::$cache[$uid])) {
            return self::$cache[$uid];
        }

        $roleKey     = strtoupper((string) ($user->role_key ?? $user->account_type ?? ''));
        $accountType = strtoupper((string) ($user->account_type ?? ''));
        $isSuper     = in_array($roleKey, ['NATIONAL_ADMIN', 'SUPER_ADMIN', 'SERVICE'], true);

        $assignments = DB::table('user_assignments')
            ->where('user_id', $uid)
            ->where('is_active', 1)
            ->whereNull('ends_at')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();

        $countries = $this->uniqueField($assignments, 'country_code');
        $provinces = $this->uniqueField($assignments, 'province_code')
                     ?: $this->uniqueField($assignments, 'pheoc_code');
        $districts = $this->uniqueField($assignments, 'district_code');
        $poes      = $this->uniqueField($assignments, 'poe_code');

        // Fall back to users.country_code if no assignment rows yet
        if (empty($countries) && ! empty($user->country_code)) {
            $countries = [(string) $user->country_code];
        }

        $primary = collect($assignments)->firstWhere('is_primary', 1)
                   ?? ($assignments[0] ?? null);

        $scopeLevel = $this->resolveScopeLevel($roleKey, $accountType);

        $label = $this->buildLabel($primary, $isSuper, $scopeLevel);

        $scope = [
            'user_id'      => $uid,
            'role_key'     => $roleKey ?: 'OBSERVER',
            'account_type' => $accountType ?: 'OBSERVER',
            'scope_level'  => $scopeLevel,
            'is_super'     => $isSuper,
            'country_code' => $primary['country_code'] ?? ($countries[0] ?? null),
            'countries'    => array_values($countries),
            'provinces'    => array_values($provinces),
            'districts'    => array_values($districts),
            'poes'         => array_values($poes),
            'primary_poe'  => $primary['poe_code'] ?? null,
            'assignments'  => $assignments,
            'label'        => $label,
        ];

        return self::$cache[$uid] = $scope;
    }

    /**
     * Resolve the scope descriptor on a Request — mirrors ReportScope::descriptor.
     * Used by Governance/Clinical/AlertOps base controllers so every read-side
     * surface gets the same shape.
     */
    public function descriptor(Request $request): array
    {
        $existing = $request->attributes->get('scope');
        if (is_array($existing) && isset($existing['scope_level'])) {
            return $existing;
        }
        $user = $request->user();
        return $user ? $this->forUser($user) : [
            'user_id' => 0, 'role_key' => 'OBSERVER', 'account_type' => 'OBSERVER',
            'scope_level' => 'SELF', 'is_super' => false,
            'country_code' => null, 'countries' => [], 'provinces' => [],
            'districts' => [], 'poes' => [],
            'primary_poe' => null, 'assignments' => [], 'label' => 'Unassigned',
        ];
    }

    /**
     * Return an array of the unique non-null values for a field across rows.
     */
    protected function uniqueField(array $rows, string $field): array
    {
        $vals = [];
        foreach ($rows as $r) {
            $v = $r[$field] ?? null;
            if ($v !== null && $v !== '') {
                $vals[$v] = true;
            }
        }
        return array_keys($vals);
    }

    /**
     * Map a role key to its scope level — mirrors role_registry.scope_level.
     */
    protected function resolveScopeLevel(string $roleKey, string $accountType): string
    {
        return match ($roleKey ?: $accountType) {
            'NATIONAL_ADMIN', 'SERVICE'                   => 'NATIONAL',
            'PHEOC_OFFICER', 'PHEOC_ADMIN'                => 'PHEOC',
            'DISTRICT_SUPERVISOR', 'DISTRICT_ADMIN'       => 'DISTRICT',
            'POE_ADMIN', 'POE_OFFICER', 'POE_DATA_OFFICER',
            'SCREENER'                                    => 'POE',
            default                                       => 'SELF',
        };
    }

    /**
     * Build a human label for the tenant chip in the top-left of the layout.
     */
    protected function buildLabel(?array $primary, bool $isSuper, string $scopeLevel): string
    {
        if ($isSuper) {
            $poe   = $primary['poe_code'] ?? null;
            $label = config('country.name') . ' · National PHEOC';
            return $poe ? "{$label} · {$poe}" : $label;
        }

        if (! $primary) {
            return 'Unassigned';
        }

        $parts = array_filter([
            $primary['district_code'] ?? null,
            $primary['poe_code'] ?? null,
        ]);

        return implode(' · ', $parts ?: [$primary['country_code'] ?? config('country.code')]);
    }

    // -------------------------------------------------------------------
    // QUERY HELPERS — use from controllers to scope list endpoints.
    // -------------------------------------------------------------------

    /**
     * Apply a scope as WHERE clauses onto a query builder.
     *
     * The caller tells us which columns on their table hold the geographic
     * codes. Super-users bypass. Partial coverage (e.g. DISTRICT user with
     * districts but no POEs) falls back to the most-specific filter present.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query
     * @param  array{country?:string, province?:string, district?:string, poe?:string} $columns
     *         Column names on the target table, keyed by scope tier.
     */
    public function applyToQuery($query, array $scope, array $columns)
    {
        if ($scope['is_super'] ?? false) {
            return $query;
        }

        if (($c = $columns['poe'] ?? null) && ! empty($scope['poes'])) {
            return $query->whereIn($c, $scope['poes']);
        }
        if (($c = $columns['district'] ?? null) && ! empty($scope['districts'])) {
            return $query->whereIn($c, $scope['districts']);
        }
        if (($c = $columns['province'] ?? null) && ! empty($scope['provinces'])) {
            return $query->whereIn($c, $scope['provinces']);
        }
        if (($c = $columns['country'] ?? null) && ! empty($scope['countries'])) {
            return $query->whereIn($c, $scope['countries']);
        }

        // No scope data at all — deny by default (scope misconfigured).
        return $query->whereRaw('1 = 0');
    }

    /**
     * Does this scope include a given POE name?
     */
    public function allowsPoe(array $scope, ?string $poeCode): bool
    {
        if (! $poeCode) {
            return false;
        }
        return ($scope['is_super'] ?? false) || in_array($poeCode, $scope['poes'] ?? [], true);
    }

    public function allowsDistrict(array $scope, ?string $districtCode): bool
    {
        if (! $districtCode) {
            return false;
        }
        return ($scope['is_super'] ?? false) || in_array($districtCode, $scope['districts'] ?? [], true);
    }

    /**
     * Convenience factory used by view composer — never throws.
     */
    public static function flush(?int $userId = null): void
    {
        if ($userId === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$userId]);
        }
    }
}
