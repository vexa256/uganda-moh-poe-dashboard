<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\PheocScope;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ReportScope — single point of truth for "what can this user see in /admin/reports?".
 *
 * Wraps PheocScope so that every report controller applies the same row filter,
 * the same filter-dropdown narrowing, and the same date-window resolution.
 *
 * Scoping matrix (from §5 of the My Reports build spec, mirrored in
 * docs/reports-build/decisions.md):
 *   ADMIN/SERVICE          → all rows, no filter
 *   PHEOC_*                → country (super read)
 *   DISTRICT_*             → districts in scope
 *   POE_*, SCREENER        → poes in scope
 *   OBSERVER, anyone else  → denied
 */
final class ReportScope
{
    public const COLUMNS = [
        'country'  => 'country_code',
        'province' => 'province_code',
        'district' => 'district_code',
        'poe'      => 'poe_code',
    ];

    public const REPORT_KEYS = [
        // Wave 1 — operational reports.
        'rpt-volume',
        'rpt-suspected',
        'rpt-geo',
        'rpt-contact-tracing',
        'rpt-registry',
        'rpt-age-gender',
        'rpt-symptom-exposure',
        // Wave 2 — analytical National Reports.
        'rpt-screening-outcomes',
        'rpt-suspected-disease-analytics',
        'rpt-case-confirmation',
        'rpt-alert-acknowledgement',
        'rpt-symptom-distribution',
        'rpt-country-analytics',
        // Wave 3 — Executive Reporting Module rebuild (2026-04-27).
        'rpt-screening-overview',    // R1
        'rpt-ops-risk',              // R11
        'rpt-gender',                // R2 (R9 'rpt-symptom-distribution' already declared in Wave 2)
        'rpt-alert-intel',           // R3
        'rpt-response-time',         // R4
        'rpt-resolution-db',         // R5
        'rpt-case-files',            // R6
        'rpt-poe-performance',       // R7
        'rpt-user-activity',         // R8
        'rpt-country-travel',        // R10
        'rpt-national-dashboard',    // National Dashboard (Phase 5 stub → Phase 7 real)
        'rpt-poe-operations',        // R14 — PoE Operations (was missing → 403 for everyone)

        // ── Quick Reports (added 2026-05-19) ─────────────────────────────
        // Minimalistic 1-chart / 1-table reports under /admin/quick-reports.
        // Same RBAC contract as the rpt-* surfaces. Default 7-day window.
        'qr-suspected',
        'qr-confirmed',
    ];

    public function __construct(protected PheocScope $pheoc)
    {
    }

    /**
     * Resolve the descriptor on a Request — falls back to PheocScope if absent.
     */
    public function descriptor(Request $request): array
    {
        $existing = $request->attributes->get('scope');
        if (is_array($existing) && isset($existing['scope_level'])) {
            return $existing;
        }
        $user = $request->user();
        return $user ? $this->pheoc->forUser($user) : $this->emptyDescriptor();
    }

    /**
     * Apply the scope as WHERE clauses on a query targeting one of the
     * surveillance tables (primary_screenings, secondary_screenings, alerts).
     *
     * Usage: $scope = $this->reportScope->descriptor($request);
     *        $q    = DB::table('secondary_screenings');
     *        $this->reportScope->apply($q, $scope);
     */
    public function apply(Builder $q, array $scope, ?string $tableAlias = null): Builder
    {
        $cols = self::COLUMNS;
        if ($tableAlias) {
            $cols = array_map(fn ($c) => "{$tableAlias}.{$c}", $cols);
        }
        $this->pheoc->applyToQuery($q, $scope, $cols);
        return $q;
    }

    /**
     * Returns the list of poe_codes the user is allowed to filter on.
     * Super users get the full national set.
     */
    public function allowedPoes(array $scope, ?string $countryCode = null): array
    {
        $q = DB::table('ref_poes')->whereNull('deleted_at')->where('is_active', 1);
        if ($countryCode) {
            $q->where('country_code', $countryCode);
        }
        if (! ($scope['is_super'] ?? false)) {
            if (! empty($scope['poes'])) {
                $q->where(function ($w) use ($scope) {
                    $w->whereIn('poe_name', $scope['poes'])->orWhereIn('poe_code', $scope['poes']);
                });
            } elseif (! empty($scope['districts'])) {
                $provIds = DB::table('ref_districts')->whereIn('code', $scope['districts'])->pluck('id')->all();
                $q->where(function ($w) use ($scope, $provIds) {
                    $w->whereIn('district', $scope['districts']);
                    if ($provIds) { $w->orWhereIn('district_id', $provIds); }
                });
            } elseif (! empty($scope['provinces'])) {
                $provIds = DB::table('ref_provinces')->whereIn('code', $scope['provinces'])->pluck('id')->all();
                if ($provIds) {
                    $q->whereIn('province_id', $provIds);
                } else {
                    return [];
                }
            } else {
                return [];
            }
        }
        return $q->orderBy('poe_name')->pluck('poe_name', 'poe_code')->all();
    }

    public function allowedDistricts(array $scope): array
    {
        $q = DB::table('ref_districts')->where('is_active', 1);
        if (! ($scope['is_super'] ?? false)) {
            if (! empty($scope['districts'])) {
                $q->whereIn('code', $scope['districts']);
            } elseif (! empty($scope['provinces'])) {
                $provIds = DB::table('ref_provinces')->whereIn('code', $scope['provinces'])->pluck('id')->all();
                if ($provIds) { $q->whereIn('province_id', $provIds); } else { return []; }
            } else {
                return [];
            }
        }
        return $q->orderBy('name')->pluck('name', 'code')->all();
    }

    public function allowedProvinces(array $scope): array
    {
        $q = DB::table('ref_provinces')->where('is_active', 1);
        if (! ($scope['is_super'] ?? false)) {
            if (! empty($scope['provinces'])) {
                $q->whereIn('code', $scope['provinces']);
            } else {
                return [];
            }
        }
        return $q->orderBy('name')->pluck('name', 'code')->all();
    }

    /**
     * Restrict a user-supplied poe filter to those they may query.
     * Returns an array of allowed names actually requested.
     */
    public function intersectPoeFilter(array $scope, $requested): array
    {
        $requested = is_array($requested) ? $requested : array_filter(explode(',', (string) $requested));
        $requested = array_map('strval', $requested);
        $allowed = array_keys($this->allowedPoes($scope));
        return array_values(array_intersect($requested, $allowed));
    }

    /**
     * Filter-priority resolver per §7.R1 master spec:
     *   Quarter+Year > Month+Year > Year > start_date+end_date.
     * Returns [Carbon $from, Carbon $to] inclusive of both bounds.
     */
    public function resolveDateWindow(array $f): array
    {
        $year    = (int) ($f['year']    ?? 0);
        $quarter = (int) ($f['quarter'] ?? 0);
        $month   = (int) ($f['month']   ?? 0);

        if ($year && $quarter >= 1 && $quarter <= 4) {
            $startMonth = ($quarter - 1) * 3 + 1;
            $from = Carbon::create($year, $startMonth, 1, 0, 0, 0);
            $to   = $from->copy()->addMonths(3)->subSecond();
            return [$from, $to];
        }
        if ($year && $month >= 1 && $month <= 12) {
            $from = Carbon::create($year, $month, 1, 0, 0, 0);
            $to   = $from->copy()->endOfMonth();
            return [$from, $to];
        }
        if ($year) {
            $from = Carbon::create($year, 1, 1, 0, 0, 0);
            $to   = Carbon::create($year, 12, 31, 23, 59, 59);
            return [$from, $to];
        }

        $start = $f['start_date'] ?? null;
        $end   = $f['end_date']   ?? null;
        if ($start && $end) {
            return [
                Carbon::parse((string) $start)->startOfDay(),
                Carbon::parse((string) $end)->endOfDay(),
            ];
        }
        if ($start) {
            return [Carbon::parse((string) $start)->startOfDay(), Carbon::now()->endOfDay()];
        }
        // Caller may pin a specific default-days window (e.g. R4 Contact Tracing
        // Readiness defaults to 30 days per spec §7.R4). Default window
        // is now 30 days for ALL reports (national-scope full-year was the
        // primary OOM trigger — full-year cohort materialisation pulled
        // tens of thousands of rows into PHP). Callers can still opt in to
        // a wider window by supplying `default_days` (max 365) or explicit
        // from/to filters.
        $days = array_key_exists('default_days', $f)
            ? max(1, min(365, (int) $f['default_days']))
            : 30;
        return [Carbon::now()->subDays($days - 1)->startOfDay(), Carbon::now()->endOfDay()];
    }

    /**
     * Hash filter set for cache keys.
     */
    public function filtersHash(array $filters): string
    {
        ksort($filters);
        return md5((string) json_encode($filters));
    }

    /**
     * Empty descriptor — never grants access.
     */
    private function emptyDescriptor(): array
    {
        return [
            'user_id' => 0, 'role_key' => 'OBSERVER', 'account_type' => 'OBSERVER',
            'scope_level' => 'SELF', 'is_super' => false,
            'country_code' => null, 'countries' => [], 'provinces' => [],
            'districts' => [], 'poes' => [],
            'primary_poe' => null, 'assignments' => [], 'label' => 'Unassigned',
        ];
    }
}
