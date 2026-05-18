<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\Insights\AlertAcknowledgementInsightEngine;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R11 · rpt-alert-acknowledgement — Acknowledged vs Unacknowledged Alerts
 *
 * Acknowledgement rule (Wave 2 §4.3 — schema confirms attribution column):
 *   ack iff `alerts.acknowledged_at IS NOT NULL`. The named-user requirement
 *   is satisfied via `alerts.acknowledged_by_user_id` (live DESCRIBE confirmed).
 *
 * SLA matrix (v1, hardcoded — see AlertAcknowledgementInsightEngine::SLA_MINUTES):
 *   CRITICAL=60 · HIGH=240 · MEDIUM=1440 · LOW=1440.
 *
 * Responder names visible only to is_super (Wave 2 §5). Below national the
 * leaderboard shows role + scope label, never the user name.
 */
final class AlertAcknowledgementController extends BaseReportController
{
    protected string $reportKey   = 'rpt-alert-acknowledgement';
    protected string $reportTitle = 'Alert Acknowledgement';

    public function __construct(
        ReportScope $scope,
        ReportAccess $access,
        ExportWriter $writer,
        protected AlertAcknowledgementInsightEngine $engine,
    ) {
        parent::__construct($scope, $access, $writer);
    }

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.reports.rpt-alert-acknowledgement.index', [
            'scope' => $scope, 'reportKey' => $this->reportKey,
            'reportTitle' => $this->reportTitle, 'dataNotes' => $this->dataNotes(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->readFilters($request);
        $payload = $this->memoise((int) ($scope['user_id'] ?? 0), $filters + ['__r' => 'r11'], fn () => $this->buildPayload($scope, $filters));
        $payload['insights']   = $this->engine->evaluate($payload);
        $payload['filters']    = $filters;
        $payload['scope']      = ['label' => $scope['label'] ?? '—', 'level' => $scope['scope_level'] ?? 'SELF'];
        $payload['data_notes'] = $this->dataNotes();
        $payload['_named_responders'] = $this->access->canSeeNamedResponders($scope);
        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->readFilters($request);
        $payload = $this->buildPayload($scope, $filters);
        $headers = ['Risk', 'SLA (min)', 'Total alerts', 'Acknowledged', 'Median (min)', 'P90 (min)', 'Breaches', 'Breach %'];
        $rows = [];
        foreach ($payload['by_risk'] as $r) {
            $rows[] = [
                $r['risk_level'], $r['sla_minutes'],
                $r['total'], $r['acknowledged'],
                $r['median_minutes'], $r['p90_minutes'],
                $r['breaches'], $r['breach_pct'],
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
        $namedOk = $this->access->canSeeNamedResponders($scope);

        $alertQ = DB::table('alerts')
            ->whereNull('deleted_at')
            ->where('sync_status', '!=', 'FAILED')
            ->whereBetween('created_at', [$from, $to]);
        $this->scope->apply($alertQ, $scope);
        if (! empty($filters['poe'])) {
            $alertQ->whereIn('poe_code', is_array($filters['poe']) ? $filters['poe'] : explode(',', (string) $filters['poe']));
        }
        $alerts = $alertQ
            ->select('id', 'risk_level', 'status', 'poe_code', 'district_code',
                'created_at', 'acknowledged_at', 'acknowledged_by_user_id',
                'closed_at', 'current_owner_user_id', 'current_owner_level',
                'alert_title', 'alert_code')
            ->get();

        $userIds = array_filter(array_unique($alerts->pluck('acknowledged_by_user_id')->merge($alerts->pluck('current_owner_user_id'))->all()));
        $users = $userIds ? DB::table('users')->whereIn('id', $userIds)->select('id', 'full_name', 'name', 'role_key')->get()->keyBy('id') : collect();

        // POE name lookup for the unacknowledged action queue + per-POE drill.
        $poeCodes = array_filter(array_unique($alerts->pluck('poe_code')->all()));
        $poeMeta  = $poeCodes ? DB::table('ref_poes')
            ->whereNull('deleted_at')
            ->whereIn('poe_code', $poeCodes)
            ->get(['poe_code', 'poe_name', 'admin_level_1'])
            ->keyBy('poe_code') : collect();

        $now = Carbon::now();
        $totalAlerts = $alerts->count();
        $ackCount = 0; $unackCount = 0; $slaBreaches = 0;
        $ackMinutes = [];
        $byRisk     = [];
        $byHour     = array_fill(0, 24, ['total' => 0, 'acked' => 0]);
        $unackList  = [];
        $responderCounts = [];
        $breachReasons = ['NO_RESPONDER_ON_SHIFT' => 0, 'NO_NETWORK' => 0, 'ROLE_MISMATCH' => 0, 'MIS_ROUTED' => 0, 'OTHER' => 0];
        $compliance = ['notify_24h' => ['met' => 0, 'total' => 0], 'respond_7d' => ['met' => 0, 'total' => 0]];

        foreach ($alerts as $a) {
            $risk = $a->risk_level ?: 'UNKNOWN';
            $sla  = AlertAcknowledgementInsightEngine::SLA_MINUTES[$risk] ?? 1440;

            $byRisk[$risk] ??= ['risk_level' => $risk, 'total' => 0, 'acknowledged' => 0, 'minutes' => [], 'breaches' => 0];
            $byRisk[$risk]['total']++;

            $createdAt = $a->created_at ? Carbon::parse((string) $a->created_at) : null;
            $ackAt     = $a->acknowledged_at ? Carbon::parse((string) $a->acknowledged_at) : null;
            $closedAt  = $a->closed_at ? Carbon::parse((string) $a->closed_at) : null;

            if ($ackAt) {
                $ackCount++;
                $byRisk[$risk]['acknowledged']++;
                if ($createdAt) {
                    $mins = max(0, $createdAt->diffInMinutes($ackAt));
                    $ackMinutes[] = $mins;
                    $byRisk[$risk]['minutes'][] = $mins;
                    if ($mins > $sla) {
                        $slaBreaches++;
                        $byRisk[$risk]['breaches']++;
                        $breachReasons[$this->classifyBreachReason($a, (int) $mins, $sla)]++;
                    }
                    if ($mins <= 1440) $compliance['notify_24h']['met']++;
                    $compliance['notify_24h']['total']++;
                }
                if ($a->acknowledged_by_user_id) {
                    $key = (int) $a->acknowledged_by_user_id;
                    $responderCounts[$key] ??= ['user_id' => $key, 'count' => 0, 'breaches' => 0, 'minutes' => []];
                    $responderCounts[$key]['count']++;
                    if ($createdAt) $responderCounts[$key]['minutes'][] = max(0, $createdAt->diffInMinutes($ackAt));
                    if ($mins > $sla) $responderCounts[$key]['breaches']++;
                }
            } else {
                $unackCount++;
                $overdueMin = $createdAt ? $createdAt->diffInMinutes($now) : 0;
                $unackList[] = [
                    'alert_id'        => (int) $a->id,
                    'risk_level'      => $risk,
                    'poe_code'        => (string) ($a->poe_code ?: 'UNASSIGNED'),
                    'alert_title'     => (string) ($a->alert_title ?: '—'),
                    'created_at'      => (string) $a->created_at,
                    'overdue_minutes' => (int) $overdueMin,
                    'overdue_hours'   => (int) floor($overdueMin / 60),
                    'sla_minutes'     => $sla,
                    'sla_breached'    => $overdueMin > $sla,
                ];
            }

            // 7-1-7 respond-7d — closure within 7 days of created_at.
            if ($closedAt && $createdAt && $createdAt->diffInMinutes($closedAt) <= 7 * 1440) {
                $compliance['respond_7d']['met']++;
            }
            $compliance['respond_7d']['total']++;

            // Hour-of-day heatmap.
            if ($createdAt) {
                $h = (int) $createdAt->format('G');
                $byHour[$h]['total']++;
                if ($ackAt) $byHour[$h]['acked']++;
            }
        }

        usort($unackList, fn ($a, $b) => $b['overdue_minutes'] <=> $a['overdue_minutes']);

        // Enrich the action queue with POE name + province (read-only).
        foreach ($unackList as &$row) {
            $meta = $poeMeta->get($row['poe_code']);
            $row['poe_name'] = $meta?->poe_name ?: $row['poe_code'];
            $row['province'] = $meta?->admin_level_1 ?: '—';
        }
        unset($row);

        // Risk league tidy-up — keep deterministic ordering CRITICAL→LOW so
        // the breach bar reads risk-descending regardless of cardinality.
        $byRisk = array_map(fn ($r) => [
            'risk_level'     => $r['risk_level'],
            'total'          => $r['total'],
            'acknowledged'   => $r['acknowledged'],
            'breaches'       => $r['breaches'],
            'breach_pct'     => $r['acknowledged'] > 0
                ? round(($r['breaches'] / max(1, $r['acknowledged'])) * 100, 1)
                : 0.0,
            'sla_minutes'    => AlertAcknowledgementInsightEngine::SLA_MINUTES[$r['risk_level']] ?? 1440,
            'median_minutes' => $this->median($r['minutes']),
            'p90_minutes'    => $this->percentile($r['minutes'], 90),
        ], $byRisk);
        $riskOrder = ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3];
        usort($byRisk, fn ($a, $b) => ($riskOrder[$a['risk_level']] ?? 99) <=> ($riskOrder[$b['risk_level']] ?? 99));

        // Responder leaderboard (anonymise below national).
        $leaderboard = [];
        foreach ($responderCounts as $row) {
            $u = $users->get($row['user_id']);
            $label = $namedOk
                ? ((string) ($u->full_name ?? $u->name ?? ('User ' . $row['user_id'])))
                : sprintf('%s — %s', (string) ($u->role_key ?? 'Responder'), $scope['label'] ?? 'scope');
            $leaderboard[] = [
                'label'          => $label,
                'count'          => $row['count'],
                'breaches'       => $row['breaches'],
                'median_minutes' => $this->median($row['minutes']),
            ];
        }
        usort($leaderboard, fn ($a, $b) => $b['count'] <=> $a['count']);

        $median = $this->median($ackMinutes);

        $ackPct  = $totalAlerts > 0 ? round(($ackCount / $totalAlerts) * 100, 1) : 0.0;
        $breachPct = $ackCount  > 0 ? round(($slaBreaches / $ackCount) * 100, 1) : 0.0;

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => [
                'total_alerts'       => $totalAlerts,
                'acknowledged'       => $ackCount,
                'acknowledged_pct'   => $ackPct,
                'unacknowledged'     => $unackCount,
                'median_ack_minutes' => $median,
                'sla_breaches'       => $slaBreaches,
                'sla_breach_pct'     => $breachPct,
            ],
            'by_risk'         => $byRisk,
            'by_hour'         => $byHour,
            'breach_reasons'  => $breachReasons,
            'compliance_7_1_7' => [
                'notify_24h_rate' => $compliance['notify_24h']['total'] > 0 ? round($compliance['notify_24h']['met'] / $compliance['notify_24h']['total'] * 100, 1) : 0.0,
                'respond_7d_rate' => $compliance['respond_7d']['total'] > 0 ? round($compliance['respond_7d']['met'] / $compliance['respond_7d']['total'] * 100, 1) : 0.0,
            ],
            'unacknowledged_list' => array_slice($unackList, 0, 50),
            'responder_leaderboard' => array_slice($leaderboard, 0, 25),
            'sla' => AlertAcknowledgementInsightEngine::SLA_MINUTES,
            'quality' => [
                'alerts_without_responder'    => $unackCount,
                'alerts_named_attribution_ok' => $alerts->where('acknowledged_by_user_id', '!=', null)->count(),
                'named_responders_visible'    => $namedOk,
            ],
        ];
    }

    private function classifyBreachReason(object $a, int $mins, int $sla): string
    {
        if ($a->current_owner_user_id === null) return 'NO_RESPONDER_ON_SHIFT';
        if ($a->current_owner_level && $a->current_owner_level !== 'POE' && $mins < 2 * $sla) return 'ROLE_MISMATCH';
        if ($mins > 4 * $sla) return 'NO_NETWORK';
        return 'OTHER';
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
}
