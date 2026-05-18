<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\Insights\ScreeningOutcomesInsightEngine;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R8 · rpt-screening-outcomes — Screening Outcomes after Alert Cycles
 *
 * Closure rule (Wave 2 §4.1, locked from live schema):
 *   Cycle closed iff `alerts.status='CLOSED' AND alerts.closed_at IS NOT NULL`.
 *   `close_category='DUPLICATE'` rows are tracked in a separate "merged"
 *   counter and excluded from the outcome funnel.
 *
 * Country-level exclusions applied here too (Wave 2 §4.4):
 *   • deleted_at IS NULL on alerts and secondary_screenings
 *   • alerts.sync_status != 'FAILED'
 */
final class ScreeningOutcomesController extends BaseReportController
{
    protected string $reportKey   = 'rpt-screening-outcomes';
    protected string $reportTitle = 'Screening Outcomes after Alert Cycles';

    /** Closure-window guideline: cycles older than this with status != CLOSED are flagged "overdue". */
    private const CLOSURE_WINDOW_HOURS = 72;

    public function __construct(
        ReportScope $scope,
        ReportAccess $access,
        ExportWriter $writer,
        protected ScreeningOutcomesInsightEngine $engine,
    ) {
        parent::__construct($scope, $access, $writer);
    }

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.reports.rpt-screening-outcomes.index', [
            'scope' => $scope, 'reportKey' => $this->reportKey,
            'reportTitle' => $this->reportTitle, 'dataNotes' => $this->dataNotes(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->readFilters($request);
        // Default window: past 7 days when no other temporal filter is set.
        if (empty($filters['start_date']) && empty($filters['end_date']) && empty($filters['year']) && empty($filters['quarter']) && empty($filters['month'])) {
            $filters['default_days'] = 7;
        }
        $payload = $this->memoise((int) ($scope['user_id'] ?? 0), $filters + ['__r' => 'r8'], fn () => $this->buildPayload($scope, $filters));
        $payload['insights']   = $this->engine->evaluate($payload);
        $payload['filters']    = $filters;
        $payload['scope']      = ['label' => $scope['label'] ?? '—', 'level' => $scope['scope_level'] ?? 'SELF'];
        $payload['data_notes'] = $this->dataNotes();
        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->readFilters($request);
        $payload = $this->buildPayload($scope, $filters);

        $headers = [
            'Rank', 'POE Name', 'POE Code', 'Type', 'Province', 'Border',
            'Total cycles', 'Closed', 'Closure %', 'Overdue open',
            'Acknowledged', 'Ack %', 'Median (min)', 'P90 (min)',
        ];
        $rows = [];
        foreach ($payload['by_poe_table'] as $i => $r) {
            $rows[] = [
                $i + 1,
                $r['poe_name'], $r['poe_code'], $r['poe_type'], $r['province'], $r['border_country'] ?: '—',
                $r['total'], $r['closed'], $r['closure_rate'], $r['overdue'],
                $r['acknowledged'], $r['ack_rate'],
                $r['median_minutes'], $r['p90_minutes'],
            ];
        }
        return $this->writer->send(
            $this->reportKey, (string) $request->input('format', 'CSV'),
            $headers, $rows, $filters,
            (int) ($scope['user_id'] ?? 0), $this->reportTitle,
        );
    }

    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($filters);
        $now         = Carbon::now();

        $alertQ = DB::table('alerts')
            ->whereNull('deleted_at')
            ->where('sync_status', '!=', 'FAILED')
            ->whereBetween('created_at', [$from, $to]);
        $this->scope->apply($alertQ, $scope);
        if (! empty($filters['poe'])) {
            $alertQ->whereIn('poe_code', is_array($filters['poe']) ? $filters['poe'] : explode(',', (string) $filters['poe']));
        }

        $alerts = $alertQ
            ->select(
                'id', 'secondary_screening_id', 'poe_code', 'district_code', 'province_code',
                'status', 'risk_level', 'close_category', 'created_at', 'acknowledged_at', 'closed_at',
                'alert_code', 'alert_title',
            )
            ->get();

        $secIds = array_filter($alerts->pluck('secondary_screening_id')->all());
        $sec = $secIds ? DB::table('secondary_screenings')
            ->whereIn('id', $secIds)
            ->whereNull('deleted_at')
            ->where('sync_status', '!=', 'FAILED')
            ->select('id', 'final_disposition', 'followup_required', 'case_status', 'closed_at',
                     'traveler_gender', 'traveler_anonymous_code')
            ->get()->keyBy('id') : collect();

        $totalCycles      = $alerts->count();
        $closedCycles     = 0;
        $duplicates       = 0;
        $overdueOpen      = 0;
        $acknowledged     = 0;
        $closeMinutes     = [];
        $disposition      = [
            'REFERRED_ARRIVED' => 0, 'REFERRED_NO_SHOW' => 0, 'ISOLATED' => 0,
            'RELEASED' => 0, 'TRANSFERRED' => 0, 'OTHER' => 0,
        ];
        $reasonsNoClose   = ['LOST_TO_FOLLOWUP' => 0, 'AWAITING_LAB' => 0, 'AWAITING_FACILITY' => 0, 'AWAITING_DECISION' => 0, 'OTHER' => 0];
        $weeklySeries     = [];
        $weeklyClosed     = [];
        $weeklyTotal      = [];
        $byPoe            = [];
        $facilityArrival  = ['ARRIVED' => 0, 'NO_SHOW' => 0];
        $followup         = ['REQUIRED' => 0, 'COMPLETED' => 0];
        $oldestOpen       = [];

        foreach ($alerts as $a) {
            $isClosed   = $a->status === 'CLOSED' && $a->closed_at !== null;
            $isDup      = $isClosed && $a->close_category === 'DUPLICATE';
            $countable  = $isClosed && ! $isDup;
            $createdAt  = $a->created_at ? Carbon::parse((string) $a->created_at) : null;
            $closedAtC  = $a->closed_at ? Carbon::parse((string) $a->closed_at) : null;
            $isAck      = $a->acknowledged_at !== null;

            if ($isAck) { $acknowledged++; }
            if ($isDup) {
                $duplicates++;
            } elseif ($isClosed) {
                $closedCycles++;
                if ($createdAt && $closedAtC) {
                    $closeMinutes[] = max(0, $createdAt->diffInMinutes($closedAtC));
                }
            }

            if (! $isClosed && $createdAt && $createdAt->diffInHours($now) > self::CLOSURE_WINDOW_HOURS) {
                $overdueOpen++;
            }

            // Map disposition (from linked secondary).
            $linked = $sec->get($a->secondary_screening_id);
            if ($countable && $linked) {
                $key = $this->mapDisposition($linked->final_disposition);
                $disposition[$key] = ($disposition[$key] ?? 0) + 1;
                if ($linked->final_disposition === 'REFERRED') {
                    $facilityArrival[$linked->case_status === 'CLOSED' ? 'ARRIVED' : 'NO_SHOW']++;
                }
                if ((int) $linked->followup_required === 1) {
                    $followup['REQUIRED']++;
                    if ($linked->case_status === 'CLOSED') {
                        $followup['COMPLETED']++;
                    }
                }
            }
            if (! $isClosed && $createdAt) {
                $reason = $this->classifyOpenReason($a, $linked);
                $reasonsNoClose[$reason] = ($reasonsNoClose[$reason] ?? 0) + 1;
            }

            // Weekly buckets — ISO week ending Sunday, formatted as Y-m-d so the
            // view can render real dates rather than "2026-W17".
            if ($createdAt) {
                $weekKey = $createdAt->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
                $weeklyTotal[$weekKey]  = ($weeklyTotal[$weekKey]  ?? 0) + 1;
                if ($countable) {
                    $weeklyClosed[$weekKey] = ($weeklyClosed[$weekKey] ?? 0) + 1;
                }
                // Legacy series — kept for compatibility with existing consumers.
                $legacyWeek = $createdAt->copy()->startOfWeek()->format('Y-\WW');
                $weeklySeries[$legacyWeek]['total']  = ($weeklySeries[$legacyWeek]['total']  ?? 0) + 1;
                $weeklySeries[$legacyWeek]['closed'] = ($weeklySeries[$legacyWeek]['closed'] ?? 0) + ($countable ? 1 : 0);
            }

            // Per-POE accumulators
            $poe = $a->poe_code ?: 'UNASSIGNED';
            $byPoe[$poe] ??= [
                'poe_code' => $poe,
                'total' => 0, 'closed' => 0, 'minutes' => [],
                'overdue' => 0, 'acknowledged' => 0,
                'sparkline_total' => [], 'sparkline_closed' => [],
            ];
            $byPoe[$poe]['total']++;
            if ($isAck) { $byPoe[$poe]['acknowledged']++; }
            if ($countable) {
                $byPoe[$poe]['closed']++;
                if ($createdAt && $closedAtC) {
                    $byPoe[$poe]['minutes'][] = max(0, $createdAt->diffInMinutes($closedAtC));
                }
            }
            if (! $isClosed && $createdAt && $createdAt->diffInHours($now) > self::CLOSURE_WINDOW_HOURS) {
                $byPoe[$poe]['overdue']++;
            }

            // Oldest still-open list
            if (! $isClosed && $createdAt) {
                $hours = max(0, $createdAt->diffInHours($now, false));
                $oldestOpen[] = [
                    'alert_id'       => (int) $a->id,
                    'poe_code'       => (string) ($a->poe_code ?: 'UNASSIGNED'),
                    'risk_level'     => (string) ($a->risk_level ?: 'UNCLASSIFIED'),
                    'status'         => (string) $a->status,
                    'alert_title'    => (string) ($a->alert_title ?: '—'),
                    'created_at'     => $createdAt->toDateTimeString(),
                    'age_hours'      => (int) $hours,
                    'reason'         => $this->classifyOpenReason($a, $linked),
                ];
            }
        }

        // Build the ordered weekly_trend array (last 8 weeks within window or all)
        ksort($weeklyTotal);
        $weekKeys = array_keys($weeklyTotal);
        $tailKeys = array_slice($weekKeys, -8);
        $weeklyTrend = [];
        foreach ($tailKeys as $wk) {
            $tot = $weeklyTotal[$wk] ?? 0;
            $cl  = $weeklyClosed[$wk] ?? 0;
            $weeklyTrend[] = [
                'week_ending'  => $wk,
                'total'        => $tot,
                'closed'       => $cl,
                'closure_rate' => $tot > 0 ? round(($cl / $tot) * 100, 1) : 0.0,
            ];
        }

        ksort($weeklySeries);

        // PoE metadata join
        $poeCodes = array_keys($byPoe);
        $poeMeta  = [];
        if (! empty($poeCodes)) {
            $rows = DB::table('ref_poes')
                ->whereNull('deleted_at')
                ->whereIn('poe_code', $poeCodes)
                ->get(['poe_code', 'poe_name', 'admin_level_1', 'poe_type', 'border_country', 'is_active']);
            foreach ($rows as $r) {
                $poeMeta[$r->poe_code] = [
                    'poe_name'       => (string) ($r->poe_name ?: $r->poe_code),
                    'province'       => (string) ($r->admin_level_1 ?: '—'),
                    'poe_type'       => (string) ($r->poe_type ?: 'land_border'),
                    'border_country' => (string) ($r->border_country ?: ''),
                ];
            }
        }

        // Per-POE sparkline of closed cycles per ISO week (one entry per tail week)
        // Computed in a second loop now that tailKeys are known.
        foreach ($alerts as $a) {
            if (! $a->created_at) { continue; }
            $createdAt = Carbon::parse((string) $a->created_at);
            $week = $createdAt->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
            if (! in_array($week, $tailKeys, true)) { continue; }
            $poe = $a->poe_code ?: 'UNASSIGNED';
            $isClosed = $a->status === 'CLOSED' && $a->closed_at !== null;
            $isDup    = $isClosed && $a->close_category === 'DUPLICATE';
            $countable = $isClosed && ! $isDup;
            $byPoe[$poe]['sparkline_total'][$week]  = ($byPoe[$poe]['sparkline_total'][$week]  ?? 0) + 1;
            if ($countable) {
                $byPoe[$poe]['sparkline_closed'][$week] = ($byPoe[$poe]['sparkline_closed'][$week] ?? 0) + 1;
            }
        }

        // Aggregate by_poe → flat presentation.
        $byPoeFlat = [];
        $byPoeTable = [];
        foreach ($byPoe as $code => $r) {
            $median   = self::median($r['minutes']);
            $p90      = self::percentile($r['minutes'], 90);
            $rate     = $r['total'] > 0 ? round(($r['closed'] / $r['total']) * 100, 1) : 0.0;
            $ackRate  = $r['total'] > 0 ? round(($r['acknowledged'] / $r['total']) * 100, 1) : 0.0;
            $meta     = $poeMeta[$code] ?? ['poe_name' => $code, 'province' => '—', 'poe_type' => 'land_border', 'border_country' => ''];
            $sparkClosed = [];
            $sparkTotal  = [];
            foreach ($tailKeys as $wk) {
                $sparkClosed[] = (int) ($r['sparkline_closed'][$wk] ?? 0);
                $sparkTotal[]  = (int) ($r['sparkline_total'][$wk]  ?? 0);
            }

            $byPoeFlat[] = [
                'poe_code'        => $code,
                'total'           => $r['total'],
                'closed'          => $r['closed'],
                'closure_rate'    => $rate,
                'median_minutes'  => $median,
                'overdue'         => $r['overdue'],
            ];
            $byPoeTable[] = [
                'poe_code'        => $code,
                'poe_name'        => $meta['poe_name'],
                'province'        => $meta['province'],
                'poe_type'        => $meta['poe_type'],
                'border_country'  => $meta['border_country'],
                'total'           => $r['total'],
                'closed'          => $r['closed'],
                'closure_rate'    => $rate,
                'overdue'         => $r['overdue'],
                'acknowledged'    => $r['acknowledged'],
                'ack_rate'        => $ackRate,
                'median_minutes'  => $median,
                'p90_minutes'     => $p90,
                'sparkline'       => $sparkClosed,
                'sparkline_total' => $sparkTotal,
            ];
        }
        usort($byPoeFlat,  fn ($a, $b) => $b['total'] <=> $a['total']);
        usort($byPoeTable, fn ($a, $b) => $b['total'] <=> $a['total']);

        $median = self::median($closeMinutes);
        $p90    = self::percentile($closeMinutes, 90);

        $countableTotal  = max(1, $totalCycles - $duplicates);
        $closureRatePct  = $totalCycles > 0 ? round(($closedCycles / $countableTotal) * 100, 1) : 0.0;
        $ackRatePct      = $totalCycles > 0 ? round(($acknowledged / $totalCycles) * 100, 1) : 0.0;
        $arrivedTotal    = $facilityArrival['ARRIVED'] + $facilityArrival['NO_SHOW'];
        $arrivedRatePct  = $arrivedTotal > 0 ? round(($facilityArrival['ARRIVED'] / $arrivedTotal) * 100, 1) : 0.0;
        $followupReqd    = $followup['REQUIRED'];
        $followupDonePct = $followupReqd > 0 ? round(($followup['COMPLETED'] / $followupReqd) * 100, 1) : 0.0;
        $pendingCycles   = max(0, $totalCycles - $closedCycles - $duplicates);

        // Time-to-close human-friendly buckets (hour bands).
        $hourBuckets = self::hourBuckets($closeMinutes);

        // Trim oldest-open to 25 worst by age
        usort($oldestOpen, fn ($a, $b) => $b['age_hours'] <=> $a['age_hours']);
        $oldestOpenTop = array_slice($oldestOpen, 0, 25);
        // Enrich with poe_name
        foreach ($oldestOpenTop as &$row) {
            $meta = $poeMeta[$row['poe_code']] ?? null;
            $row['poe_name'] = $meta['poe_name'] ?? $row['poe_code'];
        }
        unset($row);

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => [
                'total_cycles'         => $totalCycles,
                'closed_cycles'        => $closedCycles,
                'pending_cycles'       => $pendingCycles,
                'duplicates'           => $duplicates,
                'closure_rate'         => $closureRatePct,
                'ack_rate'             => $ackRatePct,
                'acknowledged'         => $acknowledged,
                'median_close_minutes' => $median,
                'p90_close_minutes'    => $p90,
                'overdue_open'         => $overdueOpen,
                'arrived_rate'         => $arrivedRatePct,
                'followup_required'    => $followupReqd,
                'followup_completed'   => $followup['COMPLETED'],
                'followup_complete_pct'=> $followupDonePct,
            ],
            'disposition'      => $disposition,
            'reasons_no_close' => $reasonsNoClose,
            'facility_arrival' => $facilityArrival,
            'followup'         => $followup,
            'time_to_close'    => [
                'minutes_buckets' => self::histogram($closeMinutes, [0, 60, 240, 720, 1440, 2880, 4320, 7200]),
                'hour_buckets'    => $hourBuckets,
                'median'          => $median,
                'p90'             => $p90,
            ],
            'weekly_series' => $weeklySeries,   // legacy
            'weekly_trend'  => $weeklyTrend,    // new — ordered, week-ending YYYY-mm-dd
            'by_poe'        => $byPoeFlat,      // legacy contract
            'by_poe_table'  => $byPoeTable,     // enriched with names + sparkline + ack rate
            'oldest_open'   => $oldestOpenTop,
            'quality'       => [
                'cycles_without_disposition' => $closedCycles - array_sum($disposition),
                'duplicates_excluded'        => $duplicates,
                'closure_window_hours'       => self::CLOSURE_WINDOW_HOURS,
                'unassigned_poe_count'       => isset($byPoe['UNASSIGNED']) ? $byPoe['UNASSIGNED']['total'] : 0,
            ],
        ];
    }

    private function mapDisposition(?string $d): string
    {
        return match ($d) {
            'REFERRED'                       => 'REFERRED_ARRIVED',
            'ISOLATED', 'QUARANTINED'        => 'ISOLATED',
            'RELEASED', 'DELAYED'            => 'RELEASED',
            'TRANSFERRED', 'DENIED_BOARDING' => 'TRANSFERRED',
            default                          => 'OTHER',
        };
    }

    private function classifyOpenReason(object $alert, ?object $linked): string
    {
        if ($linked && $linked->case_status === 'IN_PROGRESS') {
            return $linked->followup_required ? 'AWAITING_FACILITY' : 'AWAITING_DECISION';
        }
        if ($linked && $linked->case_status === 'DISPOSITIONED') {
            return 'AWAITING_FACILITY';
        }
        if ($alert->status === 'OPEN') {
            return 'AWAITING_DECISION';
        }
        if ($alert->status === 'ACKNOWLEDGED') {
            return 'AWAITING_LAB';
        }
        return 'OTHER';
    }

    private static function median(array $values): float
    {
        if (! $values) return 0.0;
        sort($values);
        $n = count($values);
        $mid = (int) floor($n / 2);
        return $n % 2 ? (float) $values[$mid] : (float) (($values[$mid - 1] + $values[$mid]) / 2);
    }

    private static function percentile(array $values, int $p): float
    {
        if (! $values) return 0.0;
        sort($values);
        $idx = (int) ceil(($p / 100) * count($values)) - 1;
        return (float) $values[max(0, min(count($values) - 1, $idx))];
    }

    private static function histogram(array $values, array $boundaries): array
    {
        $out = [];
        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            $out[$boundaries[$i] . '-' . $boundaries[$i + 1]] = 0;
        }
        $out['>' . end($boundaries)] = 0;
        foreach ($values as $v) {
            $placed = false;
            for ($i = 0; $i < count($boundaries) - 1; $i++) {
                if ($v >= $boundaries[$i] && $v < $boundaries[$i + 1]) {
                    $out[$boundaries[$i] . '-' . $boundaries[$i + 1]]++;
                    $placed = true;
                    break;
                }
            }
            if (! $placed) {
                $out['>' . end($boundaries)]++;
            }
        }
        return $out;
    }

    /**
     * Human-friendly hour buckets used by the chart on the Time-to-Close tab.
     * Returns an ordered associative array preserving label order.
     */
    private static function hourBuckets(array $minutes): array
    {
        $buckets = [
            '< 1h'    => 0,
            '1–4h'    => 0,
            '4–12h'   => 0,
            '12–24h'  => 0,
            '1–2 d'   => 0,
            '2–3 d'   => 0,
            '3–5 d'   => 0,
            '> 5 d'   => 0,
        ];
        foreach ($minutes as $m) {
            $h = $m / 60.0;
            if ($h < 1)        { $buckets['< 1h']++; }
            elseif ($h < 4)    { $buckets['1–4h']++; }
            elseif ($h < 12)   { $buckets['4–12h']++; }
            elseif ($h < 24)   { $buckets['12–24h']++; }
            elseif ($h < 48)   { $buckets['1–2 d']++; }
            elseif ($h < 72)   { $buckets['2–3 d']++; }
            elseif ($h < 120)  { $buckets['3–5 d']++; }
            else               { $buckets['> 5 d']++; }
        }
        return $buckets;
    }
}
