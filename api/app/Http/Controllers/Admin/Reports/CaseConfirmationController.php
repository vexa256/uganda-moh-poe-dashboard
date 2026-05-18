<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\Insights\CaseConfirmationInsightEngine;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R10 · rpt-case-confirmation — Case Confirmation Reports
 *
 * Authoritative source for confirmation classification (Wave 2 §4.2):
 *   `alert_case_outcomes.case_classification`. Six-state vocabulary mapped:
 *     SUSPECTED         ↦ Suspected (funnel head)
 *     PROBABLE          ↦ Probable
 *     CONFIRMED         ↦ Confirmed
 *     DISCARDED         ↦ Ruled-out
 *     LOST_TO_FOLLOWUP  ↦ Pending
 *     UNKNOWN           ↦ Pending
 *     no row in aco     ↦ Pending
 *
 * Funnel = anchored on the alert; aco soft-deletes preserve audit history
 * (we read deleted_at IS NULL only).
 */
final class CaseConfirmationController extends BaseReportController
{
    protected string $reportKey   = 'rpt-case-confirmation';
    protected string $reportTitle = 'Case Confirmation';

    /** Disease-specific guideline window (hours) for "pending beyond guideline" — v1. */
    private const PENDING_WINDOW_HOURS = 168;

    public function __construct(
        ReportScope $scope,
        ReportAccess $access,
        ExportWriter $writer,
        protected CaseConfirmationInsightEngine $engine,
    ) {
        parent::__construct($scope, $access, $writer);
    }

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.reports.rpt-case-confirmation.index', [
            'scope' => $scope, 'reportKey' => $this->reportKey,
            'reportTitle' => $this->reportTitle, 'dataNotes' => $this->dataNotes(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->readFilters($request);
        $payload = $this->memoise((int) ($scope['user_id'] ?? 0), $filters + ['__r' => 'r10'], fn () => $this->buildPayload($scope, $filters));
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
        $headers = ['Disease', 'Disease code', 'Suspected', 'Probable', 'Confirmed', 'Ruled-out', 'Pending', 'Total', 'Confirmation %', 'False-positive %'];
        $rows = [];
        foreach ($payload['by_disease'] as $r) {
            $rows[] = [
                $r['disease_name'], $r['disease_code'],
                $r['suspected'], $r['probable'], $r['confirmed'], $r['ruled_out'], $r['pending'],
                $r['total'], $r['confirmation_rate'], $r['false_positive_rate'],
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

        $alertQ = DB::table('alerts')
            ->whereNull('deleted_at')
            ->where('sync_status', '!=', 'FAILED')
            ->whereBetween('created_at', [$from, $to]);
        $this->scope->apply($alertQ, $scope);
        if (! empty($filters['poe'])) {
            $alertQ->whereIn('poe_code', is_array($filters['poe']) ? $filters['poe'] : explode(',', (string) $filters['poe']));
        }
        $alerts = $alertQ
            ->select('id', 'secondary_screening_id', 'poe_code', 'created_at', 'closed_at', 'status')
            ->get();
        $alertIds = $alerts->pluck('id')->all();
        $secIds   = array_filter($alerts->pluck('secondary_screening_id')->all());

        $aco = $alertIds ? DB::table('alert_case_outcomes')
            ->whereNull('deleted_at')
            ->whereIn('alert_id', $alertIds)
            ->select('alert_id', 'case_classification', 'lab_status', 'lab_disease_code', 'lab_test_method', 'lab_confirmed_at', 'recorded_at')
            ->get()->keyBy('alert_id') : collect();

        $sec = $secIds ? DB::table('secondary_screenings')
            ->whereIn('id', $secIds)
            ->select('id', 'syndrome_classification')
            ->get()->keyBy('id') : collect();
        $suspected = $secIds ? DB::table('secondary_suspected_diseases')
            ->whereIn('secondary_screening_id', $secIds)
            ->select('secondary_screening_id', 'disease_code', 'rank_order')
            ->get()->groupBy('secondary_screening_id') : collect();

        $now = Carbon::now();
        $stages = ['suspected' => 0, 'probable' => 0, 'confirmed' => 0, 'ruled_out' => 0, 'pending' => 0];
        $confirmMinutes = [];
        $pathwayByDisease = [];
        $pathwayByPoe     = [];
        $labRouting = ['by_method' => [], 'rejected' => 0, 'turnaround_minutes' => []];
        $pendingList = [];
        $falsePositive = []; // disease => [discarded, total]

        foreach ($alerts as $a) {
            $row = $aco->get($a->id);
            $stage = $this->classifyStage($row);
            $stages[$stage]++;

            $disease = $this->topSuspected($a->secondary_screening_id, $suspected) ?? ($row->lab_disease_code ?? null);
            if ($disease) {
                $pathwayByDisease[$disease][$stage] = ($pathwayByDisease[$disease][$stage] ?? 0) + 1;
                if ($stage === 'ruled_out') {
                    $falsePositive[$disease]['ruled_out'] = ($falsePositive[$disease]['ruled_out'] ?? 0) + 1;
                }
                $falsePositive[$disease]['total'] = ($falsePositive[$disease]['total'] ?? 0) + 1;
            }
            $poe = $a->poe_code ?: 'UNASSIGNED';
            $pathwayByPoe[$poe][$stage] = ($pathwayByPoe[$poe][$stage] ?? 0) + 1;

            if ($row && $stage === 'confirmed') {
                $alertCreated = $a->created_at ? Carbon::parse((string) $a->created_at) : null;
                $confirmedAt  = $row->lab_confirmed_at ? Carbon::parse((string) $row->lab_confirmed_at) : ($row->recorded_at ? Carbon::parse((string) $row->recorded_at) : null);
                if ($alertCreated && $confirmedAt) {
                    $confirmMinutes[] = max(0, $alertCreated->diffInMinutes($confirmedAt));
                }
            }
            if ($row?->lab_test_method) {
                $labRouting['by_method'][$row->lab_test_method] = ($labRouting['by_method'][$row->lab_test_method] ?? 0) + 1;
            }
            if ($row?->lab_status === 'INSUFFICIENT_SAMPLE') {
                $labRouting['rejected']++;
            }

            $createdAt = $a->created_at ? Carbon::parse((string) $a->created_at) : null;
            if ($stage === 'pending' && $createdAt && $createdAt->diffInHours($now) > self::PENDING_WINDOW_HOURS) {
                $pendingList[] = [
                    'alert_id'  => (int) $a->id,
                    'poe_code'  => $a->poe_code,
                    'disease'   => $disease,
                    'created_at' => (string) $a->created_at,
                    'overdue_hours' => $createdAt->diffInHours($now),
                ];
            }
        }

        usort($pendingList, fn ($x, $y) => $y['overdue_hours'] <=> $x['overdue_hours']);

        $median = $this->median($confirmMinutes);
        $p90    = $this->percentile($confirmMinutes, 90);

        // Disease + POE display-name lookups for the action queue and league.
        $diseaseCodes = array_unique(array_filter(array_keys($pathwayByDisease)));
        foreach ($pendingList as $r) {
            if (! empty($r['disease'])) { $diseaseCodes[] = $r['disease']; }
        }
        $diseaseCodes = array_values(array_unique($diseaseCodes));
        $diseaseMeta  = $diseaseCodes ? DB::table('ref_diseases')
            ->whereIn('disease_code', $diseaseCodes)
            ->get(['disease_code', 'display_name', 'who_syndrome', 'ihr_tier'])
            ->keyBy('disease_code') : collect();

        $poeCodes = array_unique(array_filter($alerts->pluck('poe_code')->all()));
        $poeMeta  = $poeCodes ? DB::table('ref_poes')
            ->whereNull('deleted_at')
            ->whereIn('poe_code', $poeCodes)
            ->get(['poe_code', 'poe_name', 'admin_level_1'])
            ->keyBy('poe_code') : collect();

        // Enrich the pending action queue with display labels.
        foreach ($pendingList as &$row) {
            $dm = $diseaseMeta->get($row['disease']);
            $pm = $poeMeta->get($row['poe_code'] ?: '');
            $row['disease_name']  = $dm?->display_name ?: ($row['disease'] ?: '—');
            $row['who_syndrome']  = $dm?->who_syndrome ?: '';
            $row['ihr_tier']      = (int) ($dm?->ihr_tier ?? 0);
            $row['poe_name']      = $pm?->poe_name ?: ($row['poe_code'] ?: '—');
            $row['province']      = $pm?->admin_level_1 ?: '—';
        }
        unset($row);

        $byDisease = [];
        foreach ($pathwayByDisease as $code => $row) {
            $totalForDisease = array_sum($row);
            $ruled = $row['ruled_out'] ?? 0;
            $confirmed = $row['confirmed'] ?? 0;
            $resolvedDen = $confirmed + $ruled;
            $dm = $diseaseMeta->get($code);
            $byDisease[] = [
                'disease_code'         => $code,
                'disease_name'         => $dm?->display_name ?: $code,
                'who_syndrome'         => $dm?->who_syndrome ?: '',
                'ihr_tier'             => (int) ($dm?->ihr_tier ?? 0),
                'suspected'            => $row['suspected'] ?? 0,
                'probable'             => $row['probable']  ?? 0,
                'confirmed'            => $confirmed,
                'ruled_out'            => $ruled,
                'pending'              => $row['pending']   ?? 0,
                'total'                => $totalForDisease,
                'confirmation_rate'    => $resolvedDen > 0 ? round(($confirmed / $resolvedDen) * 100, 1) : 0.0,
                'false_positive_rate'  => $totalForDisease > 0 ? round(($ruled / $totalForDisease) * 100, 1) : 0.0,
            ];
        }
        usort($byDisease, fn ($a, $b) => $b['confirmed'] <=> $a['confirmed']);

        // Lab pathway one-line footer summary.
        $topMethod = null; $topMethodCount = 0;
        foreach ($labRouting['by_method'] as $method => $count) {
            if ($count > $topMethodCount) { $topMethod = $method; $topMethodCount = $count; }
        }
        $labSummary = [
            'top_method'           => $topMethod,
            'top_method_count'     => $topMethodCount,
            'methods_total'        => array_sum($labRouting['by_method']),
            'insufficient_samples' => $labRouting['rejected'],
        ];

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => [
                'total_alerts'           => $alerts->count(),
                'suspected'              => $stages['suspected'],
                'probable'               => $stages['probable'],
                'confirmed'              => $stages['confirmed'],
                'ruled_out'              => $stages['ruled_out'],
                'pending'                => $stages['pending'],
                'median_confirm_minutes' => $median,
                'p90_confirm_minutes'    => $p90,
                'overdue_pending'        => count($pendingList),
            ],
            'pathway' => $stages,
            'time_to_confirm' => [
                'minutes_buckets' => $this->histogram($confirmMinutes, [0, 240, 1440, 4320, 10080, 20160]),
                'median' => $median,
                'p90'    => $p90,
            ],
            'lab_routing' => $labRouting,
            'lab_summary' => $labSummary,
            'pending_list' => array_slice($pendingList, 0, 50),
            'by_disease'   => $byDisease,
            'by_poe'       => $pathwayByPoe,
            'quality'      => [
                'alerts_no_classification' => $stages['pending'],
                'lab_pathway_gaps'         => $alerts->count() - $aco->count(),
                'pending_window_hours'     => self::PENDING_WINDOW_HOURS,
            ],
        ];
    }

    private function classifyStage(?object $aco): string
    {
        if ($aco === null) return 'pending';
        return match (strtoupper((string) $aco->case_classification)) {
            'SUSPECTED' => 'suspected',
            'PROBABLE'  => 'probable',
            'CONFIRMED' => 'confirmed',
            'DISCARDED' => 'ruled_out',
            default     => 'pending', // LOST_TO_FOLLOWUP, UNKNOWN, anything else → pending
        };
    }

    private function topSuspected(?int $sid, $grouped): ?string
    {
        if (! $sid) return null;
        $rows = $grouped->get($sid);
        if (! $rows || $rows->isEmpty()) return null;
        $sorted = $rows->sortBy('rank_order');
        return (string) $sorted->first()->disease_code;
    }

    private function median(array $values): float
    {
        if (! $values) return 0.0;
        sort($values);
        $n = count($values); $mid = (int) floor($n / 2);
        return $n % 2 ? (float) $values[$mid] : (float) (($values[$mid - 1] + $values[$mid]) / 2);
    }

    private function percentile(array $values, int $p): float
    {
        if (! $values) return 0.0;
        sort($values);
        $idx = (int) ceil(($p / 100) * count($values)) - 1;
        return (float) $values[max(0, min(count($values) - 1, $idx))];
    }

    private function histogram(array $values, array $boundaries): array
    {
        $out = [];
        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            $out[$boundaries[$i] . '-' . $boundaries[$i + 1]] = 0;
        }
        $out['>' . end($boundaries)] = 0;
        foreach ($values as $v) {
            $placed = false;
            for ($i = 0; $i < count($boundaries) - 1; $i++) {
                if ($v >= $boundaries[$i] && $v < $boundaries[$i + 1]) { $out[$boundaries[$i] . '-' . $boundaries[$i + 1]]++; $placed = true; break; }
            }
            if (! $placed) $out['>' . end($boundaries)]++;
        }
        return $out;
    }
}
