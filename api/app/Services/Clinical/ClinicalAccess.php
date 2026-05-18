<?php

declare(strict_types=1);

namespace App\Services\Clinical;

/**
 * ClinicalAccess — central authorization decision for the Clinical Library.
 *
 * Per Paranoid v2 brief §9.7, the Clinical Library is "PHEOC-level and
 * above for read access". Observers are hard-denied. POE / DISTRICT /
 * SELF scopes are denied: clinical reference is the wrong audience.
 *
 * The current routes (routes/web.php:687–826) gate every clin-* endpoint
 * with `role:NATIONAL_ADMIN`. That is more restrictive than the brief and
 * is owned by the routes engineer; widening the route middleware is out
 * of lane for this module. This class enforces the brief's intent at the
 * controller layer, so when (and only when) the route gate loosens, the
 * controller continues to apply the brief's PHEOC+ rule.
 *
 * All decisions are pure functions of a scope descriptor — no DB calls.
 */
final class ClinicalAccess
{
    public const SECTION_KEYS = [
        'clin-diseases',
        'clin-symptoms',
        'clin-exposures',
        'clin-boosts',
        'clin-endemic',
        'clin-vaccines',
    ];

    public function canSee(array $scope, string $sectionKey): bool
    {
        if (! in_array($sectionKey, self::SECTION_KEYS, true)) {
            return false;
        }
        $role        = strtoupper((string) ($scope['role_key']     ?? ''));
        $accountType = strtoupper((string) ($scope['account_type'] ?? ''));
        $level       = strtoupper((string) ($scope['scope_level']  ?? 'SELF'));
        $isSuper     = (bool) ($scope['is_super'] ?? false);

        // Observer hard-denied.
        if ($role === 'OBSERVER' || $accountType === 'OBSERVER') {
            return false;
        }
        // Super or PHEOC-or-above only.
        if ($isSuper) return true;
        return in_array($level, ['NATIONAL', 'PHEOC'], true);
    }

    /** Visible section keys for the current scope, in display order. */
    public function visibleKeys(array $scope): array
    {
        return array_values(array_filter(
            self::SECTION_KEYS,
            fn (string $k) => $this->canSee($scope, $k),
        ));
    }
}
