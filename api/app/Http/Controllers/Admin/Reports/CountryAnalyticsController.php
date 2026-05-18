<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\Insights\CountryAnalyticsInsightEngine;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R13 · rpt-country-analytics — Country Analytics
 *
 * Premium chassis (rebuild 2026-04-27): preserves every key the
 * CountryAnalyticsInsightEngine consumes (kpis.total_screened,
 * kpis.total_alerts, kpis.ack_rate, kpis.cycle_closure_rate,
 * kpis.compliance_7_1_7, kpis.total_suspected, kpis.total_confirmed) and
 * adds only what the lean 3-tab executive view actually renders:
 *   • confirmation_rate KPI (the WHO outbreak tripwire — glows >10%)
 *   • leader_pheoc / laggard_pheoc / reporting_pheocs for narrative tiles
 *   • weekly trends with parallel week_labels (date strings) so the front
 *     end does not have to re-parse YEARWEEK keys
 *   • pheoc_drill — per-PHEOC district + POE rollup for the league drilldown
 *   • compliance_detail — per-tier 7-1-7 breach counts + breach list per PHEOC
 *   • reach_sparkline — last-8-week screening counts (no national target
 *     exists in ref_poes, so we do NOT pretend to render a target ring)
 *
 * Country-level exclusions (Wave 2 §4.4 — locked):
 *   • deleted_at IS NULL
 *   • sync_status != 'FAILED'
 *   • primary_screenings.record_status != 'VOIDED'
 *   • alerts.close_category NOT IN ('FALSE_ALARM','DUPLICATE') for outcome rollups
 *
 * Hardcoded national event annotations are versioned (v1) and surfaced for
 * the trends panel. They never collapse on overlap.
 */
final class CountryAnalyticsController extends BaseReportController
{
    protected string $reportKey   = 'rpt-country-analytics';
    protected string $reportTitle = 'Country Analytics';

    /** Reach sparkline depth (weeks). Aligned with the executive habit
     *  of comparing "the last two months" at a glance. */
    private const REACH_SPARK_WEEKS = 8;

    /**
     * National event annotations (v1 — domain sign-off pending).
     * Each entry: ['date' => 'YYYY-MM-DD', 'label' => string, 'methodology' => string]
     */
    private const ANNOTATIONS = [
        ['date' => '2026-01-15', 'label' => 'National flu season start',  'methodology' => 'Hardcoded v1 — replaced when surveillance baseline gates go live.'],
        ['date' => '2026-03-01', 'label' => 'Border-screening protocol v3', 'methodology' => 'Aligned with Ministry of Health POE rollout calendar.'],
    ];

    public function __construct(
        ReportScope $scope,
        ReportAccess $access,
        ExportWriter $writer,
        protected CountryAnalyticsInsightEngine $engine,
    ) {
        parent::__construct($scope, $access, $writer);
    }

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);

        return view('admin.reports.rpt-country-analytics.index', [
            'scope'       => $scope,
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
            'dataNotes'   => $this->dataNotes() + ['exclusions' => $this->exclusionsDoc()],
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->readFilters($request);

        $payload = $this->memoise(
            (int) ($scope['user_id'] ?? 0),
            $filters + ['__r' => 'r13'],
            fn () => $this->buildPayload($scope, $filters),
        );

        $payload['insights']   = $this->engine->evaluate($payload);
        $payload['filters']    = $filters;
        $payload['scope']      = [
            'label' => $scope['label']       ?? '—',
            'level' => $scope['scope_level'] ?? 'SELF',
        ];
        $payload['data_notes'] = $this->dataNotes() + ['exclusions' => $this->exclusionsDoc()];

        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->readFilters($request);
        $payload = $this->buildPayload($scope, $filters);

        $headers = ['PHEOC', 'Screened', 'Suspected', 'Confirmed', 'Alerts', 'Ack rate %', 'Closure rate %', '7-1-7 %', 'IHR readiness'];
        $rows = [];
        $readinessByCode = [];
        foreach ($payload['ihr_readiness'] as $r) { $readinessByCode[$r['code']] = $r['score']; }
        foreach ($payload['by_pheoc'] as $r) {
            $rows[] = [
                $r['code'],
                $r['screened'],
                $r['suspected'],
                $r['confirmed'],
                $r['alerts'],
                $r['ack_rate'],
                $r['closure_rate'],
                $r['compliance_7_1_7'],
                $readinessByCode[$r['code']] ?? null,
            ];
        }

        return $this->writer->send(
            $this->reportKey,
            (string) $request->input('format', 'CSV'),
            $headers, $rows, $filters,
            (int) ($scope['user_id'] ?? 0),
            $this->reportTitle,
        );
    }

    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($filters);

        // ── Primary screening volumes ─────────────────────────────────────
        $primQ = DB::table('primary_screenings')
            ->whereNull('deleted_at')
            ->where('sync_status', '!=', 'FAILED')
            ->where('record_status', '!=', 'VOIDED')
            ->whereBetween('captured_at', [$from, $to]);
        $this->scope->apply($primQ, $scope);

        $primTotal      = (clone $primQ)->count();
        $primByProvince = (clone $primQ)->select('pheoc_code',    DB::raw('COUNT(*) as c'))->groupBy('pheoc_code')->pluck('c', 'pheoc_code')->all();
        $primByDistrict = (clone $primQ)->select('district_code', DB::raw('COUNT(*) as c'))->groupBy('district_code')->pluck('c', 'district_code')->all();
        $primByPoe      = (clone $primQ)->select('poe_code',      DB::raw('COUNT(*) as c'))->groupBy('poe_code')->pluck('c', 'poe_code')->all();
        $primWeekly     = (clone $primQ)->select(DB::raw('YEARWEEK(captured_at, 1) yw, COUNT(*) c'))
            ->groupBy('yw')->orderBy('yw')->get();

        // Per-PHEOC × per-week × per-district / POE — used for the league drilldown.
        $primByPheocDistrict = (clone $primQ)
            ->select('pheoc_code', 'district_code', DB::raw('COUNT(*) as c'))
            ->groupBy('pheoc_code', 'district_code')->get();
        $primByPheocPoe = (clone $primQ)
            ->select('pheoc_code', 'poe_code', DB::raw('COUNT(*) as c'))
            ->groupBy('pheoc_code', 'poe_code')->get();

        // ── Secondary cohort + suspected set ──────────────────────────────
        $secQ = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->where('sync_status', '!=', 'FAILED')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($secQ, $scope);
        $secTotal     = (clone $secQ)->count();
        $suspectedSet = (clone $secQ)->whereExists(function ($q) {
            $q->select(DB::raw(1))->from('secondary_suspected_diseases as ssd')
              ->whereColumn('ssd.secondary_screening_id', 'secondary_screenings.id');
        })->count();

        // ── Alerts ───────────────────────────────────────────────────────
        $alertQ = DB::table('alerts')
            ->whereNull('deleted_at')
            ->where('sync_status', '!=', 'FAILED')
            ->whereBetween('created_at', [$from, $to]);
        $this->scope->apply($alertQ, $scope);
        $alerts = $alertQ
            ->select('id', 'pheoc_code', 'district_code', 'poe_code', 'risk_level', 'status',
                'close_category', 'created_at', 'acknowledged_at', 'closed_at')
            ->get();

        $alertIds = $alerts->pluck('id')->all();
        $aco = $alertIds ? DB::table('alert_case_outcomes')
            ->whereNull('deleted_at')
            ->whereIn('alert_id', $alertIds)
            ->select('alert_id', 'case_classification')
            ->get()->keyBy('alert_id') : collect();

        // PoE name lookup for drilldown labels.
        $poeNames = [];
        $allPoeCodes = collect($primByPoe ?? [])->keys()
            ->merge($alerts->pluck('poe_code')->filter())
            ->filter()->unique()->values()->all();
        if ($allPoeCodes) {
            $poeNames = DB::table('ref_poes')
                ->whereIn('poe_code', $allPoeCodes)
                ->pluck('poe_name', 'poe_code')->all();
        }

        // District name lookup for drilldown labels.
        $districtNames = [];
        $allDistrictCodes = collect($primByDistrict ?? [])->keys()
            ->merge($alerts->pluck('district_code')->filter())
            ->filter()->unique()->values()->all();
        if ($allDistrictCodes) {
            $districtNames = DB::table('ref_districts')
                ->whereIn('code', $allDistrictCodes)
                ->pluck('name', 'code')->all();
        }

        $totalAlerts    = $alerts->count();
        $ackCount       = 0;
        $closedCount    = 0;
        $confirmedCount = 0;
        $excluded       = 0;
        $compliance     = ['notify_24h_met' => 0, 'notify_24h_total' => 0, 'respond_7d_met' => 0, 'respond_7d_total' => 0];
        $breaches       = ['notify_24h' => [], 'respond_7d' => []];

        $byPheoc    = [];
        $byDistrict = [];
        $byPoe      = [];

        $bucketShape = ['screened' => 0, 'suspected' => 0, 'confirmed' => 0, 'alerts' => 0, 'acked' => 0, 'closed' => 0, 'breach_24h' => 0];

        foreach ($alerts as $a) {
            if (in_array($a->close_category, ['FALSE_ALARM', 'DUPLICATE'], true)) {
                $excluded++;
                continue;
            }
            if ($a->acknowledged_at) { $ackCount++; }
            $countableClose = $a->status === 'CLOSED' && $a->closed_at !== null;
            if ($countableClose) { $closedCount++; }

            $aco_row = $aco->get($a->id);
            $isConfirmed = $aco_row && strtoupper((string) $aco_row->case_classification) === 'CONFIRMED';
            if ($isConfirmed) { $confirmedCount++; }

            $createdAt = $a->created_at      ? Carbon::parse((string) $a->created_at)      : null;
            $ackAt     = $a->acknowledged_at ? Carbon::parse((string) $a->acknowledged_at) : null;
            $closedAt  = $a->closed_at       ? Carbon::parse((string) $a->closed_at)       : null;

            // 7-1-7 compliance — notify-24h + respond-7d. Track breaches per PHEOC.
            if ($createdAt && $ackAt) {
                $compliance['notify_24h_total']++;
                $hours = $createdAt->diffInMinutes($ackAt) / 60;
                if ($hours <= 24) {
                    $compliance['notify_24h_met']++;
                } else {
                    $breaches['notify_24h'][] = [
                        'alert_id'    => (int) $a->id,
                        'pheoc_code'  => $a->pheoc_code,
                        'risk_level'  => $a->risk_level,
                        'created_at'  => $createdAt->toIso8601String(),
                        'acked_at'    => $ackAt->toIso8601String(),
                        'hours'       => round($hours, 1),
                    ];
                }
            }
            if ($createdAt) {
                $compliance['respond_7d_total']++;
                if ($closedAt && $createdAt->diffInMinutes($closedAt) <= 7 * 1440) {
                    $compliance['respond_7d_met']++;
                } elseif ($closedAt) {
                    $breaches['respond_7d'][] = [
                        'alert_id'   => (int) $a->id,
                        'pheoc_code' => $a->pheoc_code,
                        'risk_level' => $a->risk_level,
                        'created_at' => $createdAt->toIso8601String(),
                        'closed_at'  => $closedAt->toIso8601String(),
                        'days'       => round($createdAt->diffInMinutes($closedAt) / 1440, 1),
                    ];
                }
            }

            $pkey = $a->pheoc_code    ?: 'UNASSIGNED';
            $dkey = $a->district_code ?: 'UNASSIGNED';
            $okey = $a->poe_code      ?: 'UNASSIGNED';
            $byPheoc[$pkey]    ??= ['code' => $pkey, ...$bucketShape];
            $byDistrict[$dkey] ??= ['code' => $dkey, ...$bucketShape];
            $byPoe[$okey]      ??= ['code' => $okey, ...$bucketShape];

            $byPheoc[$pkey]['alerts']++;
            $byDistrict[$dkey]['alerts']++;
            $byPoe[$okey]['alerts']++;
            if ($a->acknowledged_at) {
                $byPheoc[$pkey]['acked']++; $byDistrict[$dkey]['acked']++; $byPoe[$okey]['acked']++;
                if ($createdAt && $ackAt && $createdAt->diffInMinutes($ackAt) > 1440) {
                    $byPheoc[$pkey]['breach_24h']++; $byDistrict[$dkey]['breach_24h']++; $byPoe[$okey]['breach_24h']++;
                }
            }
            if ($countableClose) {
                $byPheoc[$pkey]['closed']++; $byDistrict[$dkey]['closed']++; $byPoe[$okey]['closed']++;
            }
            if ($isConfirmed) {
                $byPheoc[$pkey]['confirmed']++; $byDistrict[$dkey]['confirmed']++; $byPoe[$okey]['confirmed']++;
            }
        }

        // Fold screening counts into the aggregates.
        foreach ($primByProvince as $p => $c) { $byPheoc[$p]    ??= ['code' => $p, ...$bucketShape]; $byPheoc[$p]['screened']    += (int) $c; }
        foreach ($primByDistrict as $d => $c) { $byDistrict[$d] ??= ['code' => $d, ...$bucketShape]; $byDistrict[$d]['screened'] += (int) $c; }
        foreach ($primByPoe      as $o => $c) { $byPoe[$o]      ??= ['code' => $o, ...$bucketShape]; $byPoe[$o]['screened']      += (int) $c; }

        // ── Compute per-bucket rates + composite score ────────────────────
        $finalize = static function (array $bucket): array {
            $out = [];
            foreach ($bucket as $row) {
                $row['ack_rate']         = $row['alerts'] > 0 ? round($row['acked']  / $row['alerts'] * 100, 1) : 0.0;
                $row['closure_rate']     = $row['alerts'] > 0 ? round($row['closed'] / $row['alerts'] * 100, 1) : 0.0;
                $row['compliance_7_1_7'] = $row['acked']  > 0 ? round(($row['acked'] - $row['breach_24h']) / $row['acked'] * 100, 1) : 0.0;
                $out[] = $row;
            }
            usort($out, fn ($a, $b) => $b['screened'] <=> $a['screened']);
            return $out;
        };

        $byPheocList    = $finalize($byPheoc);
        $byDistrictList = $finalize($byDistrict);
        $byPoeList      = $finalize($byPoe);

        // Decorate with display labels (district name, PoE name).
        foreach ($byDistrictList as &$row) {
            $row['name'] = $districtNames[$row['code']] ?? $row['code'];
        }
        unset($row);
        foreach ($byPoeList as &$row) {
            $row['name'] = $poeNames[$row['code']] ?? $row['code'];
        }
        unset($row);

        $compliance7_1_7 = [
            'notify_24h_rate'  => $compliance['notify_24h_total'] > 0 ? round($compliance['notify_24h_met']  / $compliance['notify_24h_total'], 4) : 0.0,
            'respond_7d_rate'  => $compliance['respond_7d_total'] > 0 ? round($compliance['respond_7d_met']  / $compliance['respond_7d_total'], 4) : 0.0,
            'notify_24h_met'   => $compliance['notify_24h_met'],
            'notify_24h_total' => $compliance['notify_24h_total'],
            'respond_7d_met'   => $compliance['respond_7d_met'],
            'respond_7d_total' => $compliance['respond_7d_total'],
            'notify_24h_breaches' => count($breaches['notify_24h']),
            'respond_7d_breaches' => count($breaches['respond_7d']),
        ];

        // ── IHR readiness composite (v1, locked) ──────────────────────────
        $ihrReadiness = [];
        foreach ($byPheocList as $row) {
            $score = 0.4 * ($row['ack_rate']         / 100)
                   + 0.4 * ($row['closure_rate']     / 100)
                   + 0.2 * ($row['compliance_7_1_7'] / 100);
            $ihrReadiness[] = [
                'code'        => $row['code'],
                'score'       => round($score * 100, 1),
                'methodology' => '0.4·ack_rate + 0.4·cycle_closure + 0.2·7-1-7 (v1)',
            ];
        }
        usort($ihrReadiness, fn ($a, $b) => $b['score'] <=> $a['score']);

        // Leader / laggard PHEOC for narrative tile.
        $leaderPheoc  = $ihrReadiness[0]                              ?? null;
        $laggardPheoc = $ihrReadiness ? end($ihrReadiness) : null;
        $reportingPheocs = count(array_filter($byPheocList, fn ($r) => $r['screened'] > 0 || $r['alerts'] > 0));

        // ── Trends — weekly screened + alerts + reach sparkline ────────────
        // Build a normalised week ladder so screened and alerts share keys.
        $weekKeys = [];
        $weekLabels = [];
        $cur = $from->copy()->startOfWeek();
        while ($cur->lte($to)) {
            $weekKeys[]   = $cur->format('o\WW');
            $weekLabels[] = $cur->copy()->endOfWeek()->toDateString();
            $cur->addWeek();
        }
        // Map MySQL YEARWEEK(_, 1) (ISO mode 1) to o\WW so both series align.
        $weeklyScreened = array_fill_keys($weekKeys, 0);
        foreach ($primWeekly as $w) {
            // YEARWEEK(_, 1) returns YYYYWW (mode 1 = ISO week, week 1 contains Jan 4).
            $yw  = (string) $w->yw;
            if (strlen($yw) >= 5) {
                $year = substr($yw, 0, 4);
                $week = substr($yw, -2);
                $key  = $year . 'W' . str_pad($week, 2, '0', STR_PAD_LEFT);
                if (isset($weeklyScreened[$key])) { $weeklyScreened[$key] = (int) $w->c; }
            }
        }
        $weeklyAlerts = array_fill_keys($weekKeys, 0);
        foreach ($alerts as $a) {
            if (! $a->created_at) { continue; }
            $key = Carbon::parse((string) $a->created_at)->format('o\WW');
            if (isset($weeklyAlerts[$key])) { $weeklyAlerts[$key]++; }
        }

        // Reach sparkline: last 8 weeks of total screened.
        $reachSparkline = array_slice(array_values($weeklyScreened), -self::REACH_SPARK_WEEKS);

        // ── Per-PHEOC drilldown ───────────────────────────────────────────
        // For each PHEOC, expose its district + POE rollups (volume + alerts).
        // Built from the alert aggregates + screening rollups, indexed by PHEOC.
        $alertsByPheocDistrict = [];
        $alertsByPheocPoe      = [];
        foreach ($alerts as $a) {
            if (in_array($a->close_category, ['FALSE_ALARM', 'DUPLICATE'], true)) { continue; }
            $pkey = $a->pheoc_code    ?: 'UNASSIGNED';
            $dkey = $a->district_code ?: 'UNASSIGNED';
            $okey = $a->poe_code      ?: 'UNASSIGNED';
            $alertsByPheocDistrict[$pkey][$dkey] = ($alertsByPheocDistrict[$pkey][$dkey] ?? 0) + 1;
            $alertsByPheocPoe[$pkey][$okey]      = ($alertsByPheocPoe[$pkey][$okey]      ?? 0) + 1;
        }
        $screenByPheocDistrict = [];
        foreach ($primByPheocDistrict as $r) {
            $screenByPheocDistrict[$r->pheoc_code ?: 'UNASSIGNED'][$r->district_code ?: 'UNASSIGNED'] = (int) $r->c;
        }
        $screenByPheocPoe = [];
        foreach ($primByPheocPoe as $r) {
            $screenByPheocPoe[$r->pheoc_code ?: 'UNASSIGNED'][$r->poe_code ?: 'UNASSIGNED'] = (int) $r->c;
        }

        $pheocDrill = [];
        foreach ($byPheocList as $row) {
            $pkey = $row['code'];
            $districts = [];
            $dCodes = array_unique(array_merge(
                array_keys($alertsByPheocDistrict[$pkey] ?? []),
                array_keys($screenByPheocDistrict[$pkey] ?? []),
            ));
            foreach ($dCodes as $dc) {
                $districts[] = [
                    'code'     => $dc,
                    'name'     => $districtNames[$dc] ?? $dc,
                    'screened' => (int) ($screenByPheocDistrict[$pkey][$dc] ?? 0),
                    'alerts'   => (int) ($alertsByPheocDistrict[$pkey][$dc] ?? 0),
                ];
            }
            usort($districts, fn ($a, $b) => $b['alerts'] <=> $a['alerts'] ?: $b['screened'] <=> $a['screened']);

            $poes = [];
            $pCodes = array_unique(array_merge(
                array_keys($alertsByPheocPoe[$pkey] ?? []),
                array_keys($screenByPheocPoe[$pkey] ?? []),
            ));
            foreach ($pCodes as $pc) {
                $poes[] = [
                    'code'     => $pc,
                    'name'     => $poeNames[$pc] ?? $pc,
                    'screened' => (int) ($screenByPheocPoe[$pkey][$pc] ?? 0),
                    'alerts'   => (int) ($alertsByPheocPoe[$pkey][$pc] ?? 0),
                ];
            }
            usort($poes, fn ($a, $b) => $b['alerts'] <=> $a['alerts'] ?: $b['screened'] <=> $a['screened']);

            $pheocDrill[$pkey] = [
                'code'      => $pkey,
                'districts' => $districts,
                'poes'      => $poes,
            ];
        }

        // ── Aggregated KPIs ──────────────────────────────────────────────
        $confirmationRate = $suspectedSet > 0
            ? round($confirmedCount / $suspectedSet, 4)
            : 0.0;

        $kpis = [
            // Engine contract — DO NOT REMOVE
            'total_screened'     => $primTotal,
            'total_secondary'    => $secTotal,
            'total_suspected'    => $suspectedSet,
            'total_confirmed'    => $confirmedCount,
            'total_alerts'       => $totalAlerts,
            'excluded_alerts'    => $excluded,
            'ack_rate'           => $totalAlerts > 0 ? round($ackCount / $totalAlerts, 4) : 0.0,
            'cycle_closure_rate' => $totalAlerts > 0 ? round($closedCount / max(1, $totalAlerts - $excluded), 4) : 0.0,
            'compliance_7_1_7'   => $compliance7_1_7['notify_24h_rate'] > 0
                ? round(($compliance7_1_7['notify_24h_rate'] + $compliance7_1_7['respond_7d_rate']) / 2, 4)
                : 0.0,

            // Premium-view extensions
            'confirmation_rate'  => $confirmationRate,
            'reporting_pheocs'   => $reportingPheocs,
            'leader_pheoc'       => $leaderPheoc['code']  ?? null,
            'leader_score'       => $leaderPheoc['score'] ?? 0.0,
            'laggard_pheoc'      => $laggardPheoc && $laggardPheoc['code'] !== ($leaderPheoc['code'] ?? null) ? $laggardPheoc['code'] : null,
            'laggard_score'      => $laggardPheoc && $laggardPheoc['code'] !== ($leaderPheoc['code'] ?? null) ? $laggardPheoc['score'] : null,
        ];

        return [
            'window' => [
                'from'      => $from->toDateString(),
                'to'        => $to->toDateString(),
                'generated' => now()->toIso8601String(),
            ],
            'kpis'             => $kpis,

            // Premium analytics
            'reach_sparkline'  => $reachSparkline,
            'pheoc_drill'      => $pheocDrill,
            'compliance_detail'=> [
                'notify_24h' => [
                    'met'     => $compliance7_1_7['notify_24h_met'],
                    'total'   => $compliance7_1_7['notify_24h_total'],
                    'rate'    => $compliance7_1_7['notify_24h_rate'],
                    'breaches'=> $breaches['notify_24h'],
                ],
                'respond_7d' => [
                    'met'     => $compliance7_1_7['respond_7d_met'],
                    'total'   => $compliance7_1_7['respond_7d_total'],
                    'rate'    => $compliance7_1_7['respond_7d_rate'],
                    'breaches'=> $breaches['respond_7d'],
                ],
            ],

            // Legacy keys retained for backward compatibility
            'by_pheoc'         => $byPheocList,
            'by_district'      => $byDistrictList,
            'by_poe'           => $byPoeList,
            'compliance_7_1_7' => $compliance7_1_7,
            'ihr_readiness'    => $ihrReadiness,
            'trends' => [
                'week_keys'       => $weekKeys,
                'week_labels'     => $weekLabels,
                'weekly_screened' => $weeklyScreened,
                'weekly_alerts'   => $weeklyAlerts,
                'annotations'     => self::ANNOTATIONS,
            ],
            'quality' => [
                'exclusions_applied' => $this->exclusionsDoc(),
                'rows_excluded'      => $excluded,
            ],
        ];
    }

    private function exclusionsDoc(): array
    {
        return [
            'soft_deleted'    => 'Rows with deleted_at NOT NULL are excluded from every base table.',
            'failed_sync'     => 'Rows with sync_status = FAILED are excluded (alerts, secondary_screenings, primary_screenings).',
            'voided_primary'  => 'primary_screenings rows with record_status = VOIDED are excluded from screening totals.',
            'merged_alerts'   => 'Alerts with close_category IN (FALSE_ALARM, DUPLICATE) are excluded from outcome rollups (counted under "excluded_alerts" KPI).',
            'drafts'          => 'This codebase has no separate "draft" state on surveillance rows. case_status = OPEN means in-progress; included in totals as such.',
        ];
    }
}
