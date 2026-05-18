<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R8 · rpt-poe-operations — Point of Entry Operations
 *
 * Operational view for the staff actually running the counters. Surfaces
 * holding-queue state, hourly velocity, processing time, screener
 * productivity and the per-POE league.
 *
 * Schema mapping (ours):
 *   primary_screenings     · captured_at / captured_by_user_id (booth screenings)
 *   secondary_screenings   · opened_at / closed_at / opened_by_user_id
 *                            case_status, final_disposition
 *   ref_poes               · poe_name + admin_level_1 + district
 *
 * Default window: past 7 days (R10 protocol).
 */
final class PoeOperationsController extends BaseReportController
{
    /** Holding queue is "flagged" when a case has been open ≥ 20 minutes. */
    private const HOLDING_THRESHOLD_MINUTES = 20;

    /** Default filter window when nothing is supplied. */
    private const DEFAULT_DAYS = 7;

    protected string $reportKey   = 'rpt-poe-operations';
    protected string $reportTitle = 'Point of Entry Operations';

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.reports.rpt-poe-operations.index', [
            'scope'       => $scope,
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
            'dataNotes'   => $this->dataNotes(),
            'defaultDays' => self::DEFAULT_DAYS,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->normalizeFilters($this->readFilters($request));

        $payload = $this->memoise(
            (int) ($scope['user_id'] ?? 0),
            $filters + ['__r' => 'r8v2'],
            fn () => $this->buildPayload($scope, $filters),
        );

        $payload['filters']    = $filters;
        $payload['scope']      = [
            'label' => $scope['label']       ?? '—',
            'level' => $scope['scope_level'] ?? 'SELF',
        ];
        $payload['data_notes'] = $this->dataNotes();

        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->normalizeFilters($this->readFilters($request));
        $format  = strtoupper((string) $request->input('format', 'CSV'));
        $payload = $this->buildPayload($scope, $filters);

        $headers = [
            'Point of Entry',
            'Code',
            'Province',
            'District',
            'Booth screenings',
            'Full checks opened',
            'Full checks closed',
            'Currently waiting',
            'Waiting over 20 min',
            'Avg processing minutes',
        ];
        $rows = [];
        foreach ($payload['poe_league'] as $r) {
            $rows[] = [
                $r['poe_name'],
                $r['poe_code'],
                $r['province'],
                $r['district'],
                $r['primary'],
                $r['secondary_opened'],
                $r['secondary_closed'],
                $r['holding'],
                $r['holding_over_20'],
                $r['avg_minutes'] === null ? '—' : $r['avg_minutes'],
            ];
        }
        return $this->writer->send(
            $this->reportKey, $format, $headers, $rows, $filters,
            (int) ($scope['user_id'] ?? 0), $this->reportTitle,
        );
    }

    private function normalizeFilters(array $f): array
    {
        $hasExplicit =
            ! empty($f['year'])       ||
            ! empty($f['quarter'])    ||
            ! empty($f['month'])      ||
            ! empty($f['start_date']) ||
            ! empty($f['end_date']);
        if (! $hasExplicit) {
            $f['default_days'] = self::DEFAULT_DAYS;
        }
        return $f;
    }

    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($filters);
        $now         = Carbon::now();

        /* ------------------- PRIMARY (booth) ------------------- */
        $pq = DB::table('primary_screenings')
            ->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->whereBetween('captured_at', [$from, $to]);
        $this->scope->apply($pq, $scope);
        $this->applyShared($pq, $filters);
        $primaryRows = (clone $pq)
            ->selectRaw('id, poe_code, captured_at, captured_by_user_id')
            ->get();

        /* ------------------- SECONDARY (full check) ------------------- */
        $sq = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($sq, $scope);
        $this->applyShared($sq, $filters);
        $secondaryRows = (clone $sq)
            ->selectRaw('id, poe_code, opened_at, closed_at, case_status, opened_by_user_id, final_disposition')
            ->get();

        /* ------------------- HOLDING (live, not date-bounded) ------------------- */
        $hq = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereIn('case_status', ['OPEN', 'IN_PROGRESS']);
        $this->scope->apply($hq, $scope);
        $this->applyShared($hq, $filters);
        $holdingRows = (clone $hq)
            ->selectRaw('id, poe_code, opened_at, created_at')
            ->get();

        // Holding split (under / over 20 min) + longest wait.
        $holdingTotal   = $holdingRows->count();
        $holdingOver20  = 0;
        $holdingUnder20 = 0;
        $longestMins    = 0;
        foreach ($holdingRows as $r) {
            $ref  = $r->opened_at ?: $r->created_at;
            $mins = 0;
            if ($ref) {
                try { $mins = max(0, Carbon::parse((string) $ref)->diffInMinutes($now, false)); }
                catch (\Throwable $e) { $mins = 0; }
            }
            if ($mins >= self::HOLDING_THRESHOLD_MINUTES) { $holdingOver20++; } else { $holdingUnder20++; }
            if ($mins > $longestMins) { $longestMins = $mins; }
        }
        $pctFlagged = $holdingTotal > 0
            ? round(($holdingOver20 / $holdingTotal) * 100, 1)
            : 0.0;

        /* ------------------- PROCESSING TIME ------------------- */
        $closedRows = $secondaryRows->filter(fn ($r) => $r->closed_at && $r->opened_at);
        $processingMinutes = $closedRows->map(function ($r) {
            try {
                return max(0, Carbon::parse((string) $r->opened_at)
                    ->diffInMinutes(Carbon::parse((string) $r->closed_at), false));
            } catch (\Throwable $e) { return 0; }
        })->filter(fn ($n) => $n >= 0)->values();

        $completedCount = $processingMinutes->count();
        $avgMinutes = $completedCount > 0 ? round($processingMinutes->avg(), 1) : null;
        $minMinutes = $completedCount > 0 ? (int) $processingMinutes->min() : null;
        $maxMinutes = $completedCount > 0 ? (int) $processingMinutes->max() : null;
        $medianMinutes = $completedCount > 0
            ? (int) round($this->median($processingMinutes->all()))
            : null;

        /* ------------------- VELOCITY (24-hour curve, today) ------------------- */
        $startOfDay = $now->copy()->startOfDay();
        $endOfDay   = $now->copy()->endOfDay();
        $entriesByHour = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereBetween('opened_at', [$startOfDay, $endOfDay])
            ->selectRaw('HOUR(opened_at) AS h, COUNT(*) AS c')
            ->groupBy('h')->pluck('c', 'h');
        $exitsByHour = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereNotNull('closed_at')
            ->whereBetween('closed_at', [$startOfDay, $endOfDay])
            ->selectRaw('HOUR(closed_at) AS h, COUNT(*) AS c')
            ->groupBy('h')->pluck('c', 'h');

        $hourly = [];
        for ($h = 0; $h <= 23; $h++) {
            $hourly[] = [
                'hour'    => $h,
                'label'   => sprintf('%02d:00', $h),
                'entries' => (int) ($entriesByHour[$h] ?? 0),
                'exits'   => (int) ($exitsByHour[$h]   ?? 0),
            ];
        }
        $totalEntriesToday = array_sum(array_column($hourly, 'entries'));
        $totalExitsToday   = array_sum(array_column($hourly, 'exits'));
        $hoursElapsed      = max(1, $now->hour + 1);
        $entriesPerHour    = round($totalEntriesToday / $hoursElapsed, 2);
        $exitsPerHour      = round($totalExitsToday / $hoursElapsed, 2);
        $netPerHour        = round($entriesPerHour - $exitsPerHour, 2);

        /* ------------------- POE LEAGUE ------------------- */
        $poeCodes = $primaryRows->pluck('poe_code')
            ->merge($secondaryRows->pluck('poe_code'))
            ->merge($holdingRows->pluck('poe_code'))
            ->unique()->filter()->values()->all();

        $poeMeta = [];
        if (! empty($poeCodes)) {
            DB::table('ref_poes')
                ->whereNull('deleted_at')
                ->whereIn('poe_code', $poeCodes)
                ->get(['poe_code', 'poe_name', 'admin_level_1', 'district', 'poe_type'])
                ->each(function ($r) use (&$poeMeta) {
                    $poeMeta[$r->poe_code] = [
                        'poe_name' => (string) ($r->poe_name ?: $r->poe_code),
                        'province' => (string) ($r->admin_level_1 ?: '—'),
                        'district' => (string) ($r->district ?: '—'),
                        'poe_type' => (string) ($r->poe_type ?: '—'),
                    ];
                });
        }

        $primaryByPoe   = $primaryRows->groupBy('poe_code')->map->count();
        $secondaryByPoe = $secondaryRows->groupBy('poe_code')->map->count();
        $closedByPoe    = $closedRows->groupBy('poe_code')->map->count();
        $avgMinByPoe    = $closedRows->groupBy('poe_code')->map(function ($rows) {
            $vals = [];
            foreach ($rows as $r) {
                try { $vals[] = max(0, Carbon::parse((string) $r->opened_at)->diffInMinutes(Carbon::parse((string) $r->closed_at), false)); }
                catch (\Throwable $e) {}
            }
            return $vals ? round(array_sum($vals) / count($vals), 1) : null;
        });
        $holdingByPoe       = $holdingRows->groupBy('poe_code')->map->count();
        $holdingOver20ByPoe = $holdingRows->groupBy('poe_code')->map(function ($rows) use ($now) {
            $c = 0;
            foreach ($rows as $r) {
                $ref = $r->opened_at ?: $r->created_at;
                if (! $ref) continue;
                try {
                    if (Carbon::parse((string) $ref)->diffInMinutes($now, false) >= self::HOLDING_THRESHOLD_MINUTES) $c++;
                } catch (\Throwable $e) {}
            }
            return $c;
        });

        $poeLeague = [];
        foreach ($poeCodes as $code) {
            $meta = $poeMeta[$code] ?? ['poe_name' => $code, 'province' => '—', 'district' => '—', 'poe_type' => '—'];
            $poeLeague[] = [
                'poe_code'          => $code,
                'poe_name'          => $meta['poe_name'],
                'province'          => $meta['province'],
                'district'          => $meta['district'],
                'poe_type'          => $meta['poe_type'],
                'primary'           => (int) ($primaryByPoe[$code]       ?? 0),
                'secondary_opened'  => (int) ($secondaryByPoe[$code]     ?? 0),
                'secondary_closed'  => (int) ($closedByPoe[$code]        ?? 0),
                'holding'           => (int) ($holdingByPoe[$code]       ?? 0),
                'holding_over_20'   => (int) ($holdingOver20ByPoe[$code] ?? 0),
                'avg_minutes'       => $avgMinByPoe[$code] ?? null,
            ];
        }
        usort($poeLeague, fn ($a, $b) => $b['primary'] <=> $a['primary']);

        /* ------------------- SCREENER PRODUCTIVITY ------------------- */
        $primaryByUser   = $primaryRows->groupBy('captured_by_user_id')->map->count();
        $secondaryByUser = $secondaryRows->groupBy('opened_by_user_id')->map(fn ($rows) => [
            'opened' => $rows->count(),
            'closed' => $rows->filter(fn ($r) => $r->closed_at)->count(),
            'avg'    => (function ($rows) {
                $vals = [];
                foreach ($rows as $r) {
                    if (! $r->closed_at) continue;
                    try { $vals[] = max(0, Carbon::parse((string) $r->opened_at)->diffInMinutes(Carbon::parse((string) $r->closed_at), false)); }
                    catch (\Throwable $e) {}
                }
                return $vals ? round(array_sum($vals) / count($vals), 1) : null;
            })($rows),
        ]);

        $userIds = array_filter(array_unique(array_merge(
            array_keys($primaryByUser->all()),
            array_keys($secondaryByUser->all()),
        )));
        $userNames = [];
        if (! empty($userIds)) {
            DB::table('users')->whereIn('id', $userIds)
                ->get(['id', 'full_name', 'name', 'email'])
                ->each(function ($u) use (&$userNames) {
                    $userNames[(int) $u->id] = (string) ($u->full_name ?: $u->name ?: $u->email ?: ('Officer #' . $u->id));
                });
        }

        $primaryScreeners = [];
        foreach ($primaryByUser as $uid => $cnt) {
            if (! $uid) continue;
            $primaryScreeners[] = [
                'user_id'  => (int) $uid,
                'name'     => $userNames[(int) $uid] ?? ('Officer #' . $uid),
                'count'    => $cnt,
                'per_hour' => $this->perHour($cnt, $from, $to),
            ];
        }
        usort($primaryScreeners, fn ($a, $b) => $b['count'] <=> $a['count']);

        $secondaryScreeners = [];
        foreach ($secondaryByUser as $uid => $row) {
            if (! $uid) continue;
            $secondaryScreeners[] = [
                'user_id'    => (int) $uid,
                'name'       => $userNames[(int) $uid] ?? ('Officer #' . $uid),
                'opened'     => $row['opened'],
                'closed'     => $row['closed'],
                'avg_minutes' => $row['avg'],
            ];
        }
        usort($secondaryScreeners, fn ($a, $b) => $b['opened'] <=> $a['opened']);

        /* ------------------- DAILY TREND ------------------- */
        $dayKey = fn ($v) => $v ? (function () use ($v) {
            try { return Carbon::parse((string) $v)->toDateString(); } catch (\Throwable $e) { return null; }
        })() : null;

        $primaryByDay   = $primaryRows->groupBy(fn ($r) => $dayKey($r->captured_at))->map->count();
        $secondaryByDay = $secondaryRows->groupBy(fn ($r) => $dayKey($r->opened_at))->map->count();
        $closuresByDay  = $closedRows->groupBy(fn ($r) => $dayKey($r->closed_at))->map->count();

        $cur = $from->copy()->startOfDay();
        $trend = [];
        while ($cur->lte($to)) {
            $key = $cur->toDateString();
            $trend[] = [
                'date'      => $key,
                'label'     => $cur->format('M j'),
                'primary'   => (int) ($primaryByDay[$key]   ?? 0),
                'secondary' => (int) ($secondaryByDay[$key] ?? 0),
                'closures'  => (int) ($closuresByDay[$key]  ?? 0),
            ];
            $cur->addDay();
        }

        /* ------------------- DROPDOWN META ------------------- */
        return [
            'window' => [
                'from'  => $from->toDateString(),
                'to'    => $to->toDateString(),
                'label' => $this->windowLabel($filters, $from, $to),
            ],
            'holding' => [
                'total'                => $holdingTotal,
                'under_20'             => $holdingUnder20,
                'over_20'              => $holdingOver20,
                'pct_flagged'          => $pctFlagged,
                'longest_wait_minutes' => $longestMins,
                'threshold_minutes'    => self::HOLDING_THRESHOLD_MINUTES,
            ],
            'velocity' => [
                'entries_per_hour' => $entriesPerHour,
                'exits_per_hour'   => $exitsPerHour,
                'net_per_hour'     => $netPerHour,
                'today_entries'    => $totalEntriesToday,
                'today_exits'      => $totalExitsToday,
                'hourly_curve'     => $hourly,
            ],
            'processing' => [
                'avg_minutes'         => $avgMinutes,
                'min_minutes'         => $minMinutes,
                'max_minutes'         => $maxMinutes,
                'median_minutes'      => $medianMinutes,
                'completed_in_window' => $completedCount,
            ],
            'poe_league'   => $poeLeague,
            'screeners'    => [
                'primary'   => $primaryScreeners,
                'secondary' => $secondaryScreeners,
            ],
            'daily_trend' => $trend,
            'meta' => [
                'poes'         => $this->scope->allowedPoes($scope),
                'default_days' => self::DEFAULT_DAYS,
            ],
        ];
    }

    private function applyShared(\Illuminate\Database\Query\Builder $q, array $f): void
    {
        if (! empty($f['poe'])) {
            $list = is_array($f['poe']) ? $f['poe'] : array_filter(explode(',', (string) $f['poe']));
            if (! empty($list)) { $q->whereIn('poe_code', $list); }
        }
        if (! empty($f['eoc']))      { $q->where('pheoc_code',   $f['eoc']); }
        if (! empty($f['district'])) { $q->where('district_code', $f['district']); }
    }

    private function perHour(int $count, Carbon $from, Carbon $to): float
    {
        $hours = max(1, $from->diffInHours($to));
        return round($count / $hours, 2);
    }

    private function median(array $values): float
    {
        if (empty($values)) { return 0.0; }
        sort($values);
        $n = count($values);
        $mid = (int) ($n / 2);
        return $n % 2 === 0
            ? (($values[$mid - 1] + $values[$mid]) / 2.0)
            : (float) $values[$mid];
    }

    private function windowLabel(array $f, Carbon $from, Carbon $to): string
    {
        $year    = (int) ($f['year']    ?? 0);
        $quarter = (int) ($f['quarter'] ?? 0);
        $month   = (int) ($f['month']   ?? 0);
        if ($year && $quarter) { return "Quarter {$quarter}, {$year}"; }
        if ($year && $month)   { return Carbon::create($year, $month, 1)->format('F Y'); }
        if ($year)             { return (string) $year; }
        if (isset($f['default_days'])) { return 'Past ' . (int) $f['default_days'] . ' days'; }
        return $from->format('M j, Y') . ' – ' . $to->format('M j, Y');
    }
}
