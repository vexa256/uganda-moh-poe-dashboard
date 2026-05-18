<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * ReportAccess — central authorization decision for the My Reports module.
 *
 * Wave 1 reports follow the scoping matrix in docs/reports-build/decisions.md.
 * Wave 2 (National Reports analytical surfaces) extends that matrix with two
 * carve-outs documented inline below:
 *
 *   • COUNTRY_ANALYTICS_KEYS — visible only to PHEOC, super (NATIONAL_ADMIN /
 *     SERVICE), and the WHO observer carve-out. Hidden from the sidebar
 *     (not greyed) below that.
 *
 *   • OBSERVER_VISIBLE — the OBSERVER account_type is otherwise denied every
 *     report. The Wave 2 brief calls for WHO observers to see country-level
 *     aggregates with PII suppressed. This list is the explicit allow-list
 *     for that role; additions require domain sign-off.
 *
 * All decisions are pure functions of the scope descriptor — no DB calls.
 */
final class ReportAccess
{
    /** Reports forbidden to POE-scoped users (sensitive geographic / PII surfaces). */
    public const POE_HIDDEN = ['rpt-geo', 'rpt-registry'];

    /** Reports forbidden to DISTRICT-scoped users (none — district sees everything in district). */
    public const DISTRICT_HIDDEN = [];

    /** Country-level analytical surfaces — PHEOC + super + observer carve-out only. */
    public const COUNTRY_ANALYTICS_KEYS = ['rpt-country-analytics'];

    /** Allow-list for the OBSERVER account_type (WHO observer). Aggregates only. */
    public const OBSERVER_VISIBLE = ['rpt-country-analytics'];

    public function canSee(array $scope, string $reportKey): bool
    {
        if (! in_array($reportKey, ReportScope::REPORT_KEYS, true)) {
            return false;
        }

        $role        = strtoupper((string) ($scope['role_key']     ?? ''));
        $accountType = strtoupper((string) ($scope['account_type'] ?? ''));
        $level       = strtoupper((string) ($scope['scope_level']  ?? 'SELF'));
        $isSuper     = (bool) ($scope['is_super'] ?? false);

        // OBSERVER carve-out — Wave 2 §5: WHO observers see country aggregates only.
        if ($role === 'OBSERVER' || $accountType === 'OBSERVER') {
            return in_array($reportKey, self::OBSERVER_VISIBLE, true);
        }

        if ($level === 'SELF') {
            return false;
        }

        // Country-analytics surfaces require PHEOC or above (or the OBSERVER carve-out above).
        if (in_array($reportKey, self::COUNTRY_ANALYTICS_KEYS, true)) {
            if (! $isSuper && $level !== 'PHEOC') {
                return false;
            }
        }

        if ($level === 'POE' && in_array($reportKey, self::POE_HIDDEN, true)) {
            return false;
        }

        if ($level === 'DISTRICT' && in_array($reportKey, self::DISTRICT_HIDDEN, true)) {
            return false;
        }

        return true;
    }

    /**
     * Apply role-driven masking to a registry row (R5).
     * Non-NATIONAL users get phone/email obscured.
     */
    public function maskPii(array $row, array $scope): array
    {
        if ($scope['is_super'] ?? false) {
            return $row;
        }
        $level = strtoupper((string) ($scope['scope_level'] ?? ''));
        if ($level === 'PHEOC') {
            return $row;
        }
        $mask = static function (?string $value): ?string {
            if (! $value) {
                return $value;
            }
            $len = strlen($value);
            if ($len <= 4) {
                return str_repeat('•', $len);
            }
            return substr($value, 0, 2) . str_repeat('•', max(2, $len - 4)) . substr($value, -2);
        };
        foreach (['phone_number', 'alternative_phone', 'email', 'emergency_contact_phone', 'travel_document_number'] as $col) {
            if (array_key_exists($col, $row)) {
                $row[$col] = $mask((string) ($row[$col] ?? ''));
            }
        }
        return $row;
    }

    /**
     * Visible report keys for the current scope, in display order.
     */
    public function visibleKeys(array $scope): array
    {
        return array_values(array_filter(
            ReportScope::REPORT_KEYS,
            fn (string $k) => $this->canSee($scope, $k),
        ));
    }

    /**
     * Whether the caller can see *named* responders / users on the dashboard.
     * National admins and service accounts only — everyone else gets the
     * role-plus-scope label per Wave 2 §5 (rpt-alert-acknowledgement).
     */
    public function canSeeNamedResponders(array $scope): bool
    {
        return (bool) ($scope['is_super'] ?? false);
    }
}
