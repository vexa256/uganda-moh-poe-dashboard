<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\QuickReports;

use App\Http\Controllers\Admin\Reports\BaseReportController;
use Illuminate\Http\Request;

/**
 * BaseQuickReportController — shared chassis for /admin/quick-reports/*.
 *
 * Quick Reports are 1-chart / 1-table minimalistic surfaces. They share the
 * RBAC + memoise + filter machinery of the rpt-* reports but add:
 *
 *   • Default 7-day date window (rpt-* defaults to 30).
 *   • A small, additive filter vocabulary (risk, status, days).
 *   • A short cache TTL so the page is effectively "real time" — staleness
 *     is capped at 30 seconds while the burst-protection still holds.
 *
 * Subclasses are responsible for the SQL and the payload shape. This base
 * does not own any business logic.
 */
abstract class BaseQuickReportController extends BaseReportController
{
    /** Lower TTL than the rpt-* reports so dashboards stay near-live. */
    protected string $cacheTtl = '30';

    /**
     * Filter validation — superset of the rpt-* vocabulary. Unknown keys
     * still get silently dropped (Validator::valid()), so the surface stays
     * forgiving of legacy query-strings while we add new dimensions here.
     */
    protected function filterRules(): array
    {
        // NB. `classification` stays loose — Suspected Cases binds it to
        //     `secondary_screenings.syndrome_classification` (free-form), so
        //     a tight enum would lock that report out of its own data.
        //     Confirmed Cases uses a distinct key (`class`) for the lab
        //     case-classification vocabulary to avoid the collision.
        return array_merge(parent::filterRules(), [
            'risk'         => ['nullable', 'in:LOW,MEDIUM,HIGH,CRITICAL'],
            'status'       => ['nullable', 'in:OPEN,IN_PROGRESS,DISPOSITIONED,CLOSED,REOPENED,ACKNOWLEDGED'],
            'disposition'  => ['nullable', 'string', 'max:32'],
            'with_disease' => ['nullable', 'in:0,1'],
            // Confirmed Cases vocabulary
            'class'        => ['nullable', 'in:SUSPECTED,PROBABLE,CONFIRMED,DISCARDED,LOST_TO_FOLLOWUP,UNKNOWN,PENDING'],
            'disease'      => ['nullable', 'string', 'max:80'],
            'ihr_tier'     => ['nullable', 'integer', 'min:1', 'max:3'],
            'ihr_notified' => ['nullable', 'in:0,1'],
            'clinical'     => ['nullable', 'string', 'max:32'],
        ]);
    }

    /**
     * Inject the default 7-day window when the user has not supplied any
     * date filter. Subclasses call this before resolveDateWindow().
     */
    protected function applyDefaultWindow(array $filters): array
    {
        $hasExplicitWindow = ! empty($filters['year'])
            || ! empty($filters['start_date'])
            || ! empty($filters['end_date']);

        if (! $hasExplicitWindow) {
            $userDays = (int) ($filters['days'] ?? 0);
            $filters['default_days'] = $userDays > 0 ? min(365, max(1, $userDays)) : 7;
        }
        return $filters;
    }

    /**
     * Human-friendly label for the resolved window — surfaced in dynamic titles
     * so every chart/table/heading honestly reports the active range.
     */
    protected function windowLabel(\DateTimeInterface $from, \DateTimeInterface $to): string
    {
        $diff = (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400);
        if ($diff <= 1) {
            return 'Today';
        }
        // Include the FROM year explicitly when it differs from the TO year —
        // otherwise "May 20 → May 19, 2026" reads as an inverted range when
        // the FROM is actually in the prior year.
        $sameYear = $from->format('Y') === $to->format('Y');
        $f = $sameYear ? $from->format('M j') : $from->format('M j, Y');
        $t = $to->format('M j, Y');
        return "{$f} → {$t}";
    }
}
