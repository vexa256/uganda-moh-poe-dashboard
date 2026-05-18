<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\Insights\GeoIntelligenceInsightEngine;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R3 · rpt-geo — Geographic Risk Intelligence Dashboard
 */
final class GeoIntelligenceController extends BaseReportController
{
    protected string $reportKey   = 'rpt-geo';
    protected string $reportTitle = 'Geographic Risk Intelligence';

    /**
     * Countries on the deterministic endemic list used for the Endemic Zones tab.
     * Source: WHO disease outbreak advisories + ref_endemic_countries (where present).
     */
    private const ENDEMIC_COUNTRIES = [
        'CD', 'UG', 'TZ', 'KE', 'RW', 'BI', 'SS', 'SD', 'ET', 'NG', 'SL', 'LR', 'GN', 'CI', 'MZ', 'SO',
    ];

    public function __construct(
        ReportScope $scope,
        ReportAccess $access,
        ExportWriter $writer,
        protected GeoIntelligenceInsightEngine $engine,
    ) {
        parent::__construct($scope, $access, $writer);
    }

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.reports.rpt-geo.index', [
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
        $payload = $this->memoise((int) ($scope['user_id'] ?? 0), $filters + ['__r' => 'r3'], fn () => $this->buildPayload($scope, $filters));
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

        $headers = ['Origin country', 'Symptomatic arrivals', 'Total arrivals', 'Endemic zone'];
        $rows = [];
        foreach ($payload['origins'] as $r) {
            $rows[] = [$r['country'], $r['symptomatic'], $r['total'], $r['endemic'] ? 'YES' : 'no'];
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

        $secQ = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($secQ, $scope);
        if (! empty($filters['poe'])) {
            $secQ->whereIn('poe_code', is_array($filters['poe']) ? $filters['poe'] : explode(',', (string) $filters['poe']));
        }
        $gender = $filters['sex'] ?? $filters['gender'] ?? null;
        if ($gender) { $secQ->where('traveler_gender', $gender); }

        $sec = (clone $secQ)->select([
            'id', 'poe_code', 'journey_start_country_code', 'risk_level', 'traveler_gender',
            'traveler_age_years', 'general_appearance', 'conveyance_type', 'conveyance_identifier',
        ])->get();

        $secIds = $sec->pluck('id')->all();
        $symptoms = $secIds ? DB::table('secondary_symptoms')
            ->whereIn('secondary_screening_id', $secIds)
            ->where('is_present', 1)
            ->selectRaw('secondary_screening_id, COUNT(*) as cnt')
            ->groupBy('secondary_screening_id')
            ->pluck('cnt', 'secondary_screening_id') : collect();
        $travelRows = $secIds ? DB::table('secondary_travel_countries')
            ->whereIn('secondary_screening_id', $secIds)
            ->get() : collect();

        // High-risk: risk_level HIGH/CRITICAL OR has ≥1 present symptom
        $highRisk = $sec->filter(function ($s) use ($symptoms) {
            if (in_array($s->risk_level, ['HIGH', 'CRITICAL'], true)) return true;
            return ($symptoms[$s->id] ?? 0) > 0;
        });

        // Origin analysis
        $origins = [];
        foreach ($sec as $s) {
            $origin = $s->journey_start_country_code ?: 'UNKNOWN';
            if (! isset($origins[$origin])) {
                $origins[$origin] = ['country' => $origin, 'total' => 0, 'symptomatic' => 0, 'endemic' => in_array($origin, self::ENDEMIC_COUNTRIES, true)];
            }
            $origins[$origin]['total']++;
            if (($symptoms[$s->id] ?? 0) > 0 || in_array($s->risk_level, ['HIGH', 'CRITICAL'], true)) {
                $origins[$origin]['symptomatic']++;
            }
        }
        $origins = array_values($origins);
        usort($origins, fn ($a, $b) => $b['symptomatic'] <=> $a['symptomatic']);

        // Transit routes (origin → POE)
        $routes = [];
        foreach ($sec as $s) {
            $o = $s->journey_start_country_code ?: 'UNKNOWN';
            $p = $s->poe_code ?: 'UNKNOWN';
            $key = $o . '→' . $p;
            if (! isset($routes[$key])) {
                $routes[$key] = ['origin' => $o, 'poe' => $p, 'total' => 0, 'symptomatic' => 0];
            }
            $routes[$key]['total']++;
            if (($symptoms[$s->id] ?? 0) > 0) $routes[$key]['symptomatic']++;
        }
        $routes = array_values($routes);
        usort($routes, fn ($a, $b) => $b['symptomatic'] <=> $a['symptomatic']);

        // Endemic zone arrivals
        $endemic = array_values(array_filter($origins, fn ($o) => $o['endemic']));

        // Border profiles — per POE
        $borders = [];
        foreach ($sec as $s) {
            $p = $s->poe_code ?: 'UNKNOWN';
            if (! isset($borders[$p])) {
                $borders[$p] = ['poe' => $p, 'primary' => 0, 'secondary' => 0, 'high_risk' => 0, 'symptomatic' => 0];
            }
            $borders[$p]['secondary']++;
            if (in_array($s->risk_level, ['HIGH', 'CRITICAL'], true)) $borders[$p]['high_risk']++;
            if (($symptoms[$s->id] ?? 0) > 0) $borders[$p]['symptomatic']++;
        }

        // Primary counts per POE, same window
        $primaryQ = DB::table('primary_screenings')
            ->whereNull('deleted_at')
            ->where('record_status', 'COMPLETED')
            ->whereBetween('captured_at', [$from, $to]);
        $this->scope->apply($primaryQ, $scope);
        $primaryCounts = $primaryQ->selectRaw('poe_code, COUNT(*) as cnt')->groupBy('poe_code')->pluck('cnt', 'poe_code');
        foreach ($borders as $code => &$row) {
            $row['primary'] = (int) ($primaryCounts[$code] ?? 0);
        }
        unset($row);
        $borders = array_values($borders);
        usort($borders, fn ($a, $b) => $b['secondary'] <=> $a['secondary']);

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => [
                'high_risk_arrivals' => $highRisk->count(),
                'distinct_origins'   => count($origins),
                'endemic_origins'    => count($endemic),
                'poes_touched'       => count($borders),
            ],
            'origins'  => $origins,
            'routes'   => array_slice($routes, 0, 25),
            'endemic'  => $endemic,
            'borders'  => $borders,
        ];
    }
}
