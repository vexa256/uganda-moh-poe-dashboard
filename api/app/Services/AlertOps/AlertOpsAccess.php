<?php

declare(strict_types=1);

namespace App\Services\AlertOps;

/**
 * AlertOpsAccess — central authorization decision for the rebuilt
 * Alert Operations sub-section. Six views, RBAC matrix mirrors the
 * current routes/web.php gates so the controller's gate matches the
 * route's gate (defence in depth):
 *
 *   alert-followups · alert-sla · alert-ownership · alert-caseroom ·
 *   alert-external  · alert-timeline
 *
 * Read access is granted to everyone in the alerts middleware group:
 * NATIONAL_ADMIN, PHEOC_*, DISTRICT_*, POE_*, SCREENER. The scoper
 * narrows what each one actually sees inside their scope. OBSERVER is
 * hard-denied because reads here include free-text comments + handoff
 * notes that the OBSERVER carve-out was never granted.
 *
 * Write actions (acknowledge / close / escalate / reassign / comment /
 * upload evidence / handoff / breach report / external request)
 * follow the route-level role gates already declared in routes/web.php
 * — this class does not duplicate those, it complements them with a
 * uniform "is the user even allowed to read this section" check.
 */
final class AlertOpsAccess
{
    public const SECTION_KEYS = [
        'alert-followups',
        'alert-sla',
        'alert-ownership',
        'alert-caseroom',
        'alert-external',
        'alert-timeline',
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

        // OBSERVER hard-denied — reads contain free-text comments + notes.
        if ($role === 'OBSERVER' || $accountType === 'OBSERVER') {
            return false;
        }

        if ($isSuper) return true;
        return in_array($level, ['NATIONAL', 'PHEOC', 'DISTRICT', 'POE'], true);
    }

    public function visibleKeys(array $scope): array
    {
        return array_values(array_filter(
            self::SECTION_KEYS,
            fn (string $k) => $this->canSee($scope, $k),
        ));
    }
}
