<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\Insights\ContactTracingInsightEngine;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R4 · rpt-contact-tracing — Contact Tracing Readiness
 */
final class ContactTracingController extends BaseReportController
{
    protected string $reportKey   = 'rpt-contact-tracing';
    protected string $reportTitle = 'Contact Tracing Readiness';

    private const FIELD_WEIGHTS = [
        'phone_number'            => 2.0,
        'alternative_phone'       => 0.5,
        'residence_address_text'  => 1.5,
        'destination_address_text' => 1.0,
        'emergency_contact_name'  => 1.0,
        'emergency_contact_phone' => 1.0,
        'travel_document_number'  => 0.5,
        'journey_start_country_code' => 0.5,
    ];

    public function __construct(
        ReportScope $scope,
        ReportAccess $access,
        ExportWriter $writer,
        protected ContactTracingInsightEngine $engine,
    ) {
        parent::__construct($scope, $access, $writer);
    }

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.reports.rpt-contact-tracing.index', [
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
        $payload = $this->memoise((int) ($scope['user_id'] ?? 0), $filters + ['__r' => 'r4'], fn () => $this->buildPayload($scope, $filters));
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
        if (empty($filters['start_date']) && empty($filters['year'])) { $filters['default_days'] = 30; }
        $payload = $this->buildPayload($scope, $filters);
        $headers = ['Screener', 'Cases', 'Completeness', 'Missing phone', 'Missing address'];
        $rows = [];
        foreach ($payload['screeners'] as $r) {
            $rows[] = [$r['screener'], $r['cases'], number_format($r['completeness'] * 100, 1) . '%', $r['missing_phone'], $r['missing_address']];
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

        $q = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($q, $scope);
        if (! empty($filters['poe'])) {
            $q->whereIn('poe_code', is_array($filters['poe']) ? $filters['poe'] : explode(',', (string) $filters['poe']));
        }

        $rows = (clone $q)->select(array_merge([
            'id', 'opened_by_user_id', 'poe_code', 'opened_at', 'risk_level',
        ], array_keys(self::FIELD_WEIGHTS)))->get();

        $total = $rows->count();
        $missingPhone   = 0;
        $missingAddress = 0;
        $highRisk       = 0;
        $completenessFieldCount = [];
        foreach (self::FIELD_WEIGHTS as $f => $_) { $completenessFieldCount[$f] = 0; }

        $byScreener = [];
        $byPoe      = [];
        $dayBuckets = [];
        $maxWeight  = array_sum(self::FIELD_WEIGHTS);
        $totalScore = 0.0;

        foreach ($rows as $r) {
            $score = 0.0;
            foreach (self::FIELD_WEIGHTS as $field => $weight) {
                if (! empty($r->$field)) {
                    $score += $weight;
                    $completenessFieldCount[$field]++;
                }
            }
            $pct = $score / max(0.0001, $maxWeight);
            $totalScore += $pct;

            if (empty($r->phone_number) && empty($r->alternative_phone)) $missingPhone++;
            if (empty($r->residence_address_text)) $missingAddress++;
            if (in_array($r->risk_level, ['HIGH', 'CRITICAL'], true)) $highRisk++;

            $sk = (int) ($r->opened_by_user_id ?? 0);
            if (! isset($byScreener[$sk])) {
                $byScreener[$sk] = ['user_id' => $sk, 'cases' => 0, 'score_sum' => 0.0, 'missing_phone' => 0, 'missing_address' => 0];
            }
            $byScreener[$sk]['cases']++;
            $byScreener[$sk]['score_sum'] += $pct;
            if (empty($r->phone_number) && empty($r->alternative_phone)) $byScreener[$sk]['missing_phone']++;
            if (empty($r->residence_address_text)) $byScreener[$sk]['missing_address']++;

            $pkey = $r->poe_code ?: 'UNKNOWN';
            if (! isset($byPoe[$pkey])) {
                $byPoe[$pkey] = ['poe' => $pkey, 'cases' => 0, 'score_sum' => 0.0];
            }
            $byPoe[$pkey]['cases']++;
            $byPoe[$pkey]['score_sum'] += $pct;

            if ($r->opened_at) {
                $day = Carbon::parse((string) $r->opened_at)->format('Y-m-d');
                if (! isset($dayBuckets[$day])) { $dayBuckets[$day] = ['total' => 0, 'score' => 0.0]; }
                $dayBuckets[$day]['total']++;
                $dayBuckets[$day]['score'] += $pct;
            }
        }

        // User labels
        $userIds = array_keys($byScreener);
        $userLabels = [];
        if (! empty($userIds)) {
            $userLabels = DB::table('users')->whereIn('id', $userIds)
                ->pluck('full_name', 'id')->all();
        }

        $screeners = [];
        foreach ($byScreener as $uid => $row) {
            $screeners[] = [
                'user_id'       => $uid,
                'screener'      => $userLabels[$uid] ?? ('User #' . $uid),
                'cases'         => $row['cases'],
                'completeness'  => $row['cases'] > 0 ? round($row['score_sum'] / $row['cases'], 3) : 0,
                'missing_phone' => $row['missing_phone'],
                'missing_address' => $row['missing_address'],
            ];
        }
        usort($screeners, fn ($a, $b) => $b['cases'] <=> $a['cases']);

        $poes = [];
        foreach ($byPoe as $row) {
            $poes[] = [
                'poe'          => $row['poe'],
                'cases'        => $row['cases'],
                'completeness' => $row['cases'] > 0 ? round($row['score_sum'] / $row['cases'], 3) : 0,
            ];
        }
        usort($poes, fn ($a, $b) => $b['cases'] <=> $a['cases']);

        ksort($dayBuckets);
        $trend = [];
        foreach ($dayBuckets as $day => $bucket) {
            $trend[] = [
                'day' => $day,
                'completeness' => $bucket['total'] > 0 ? round($bucket['score'] / $bucket['total'], 3) : 0,
                'cases' => $bucket['total'],
            ];
        }

        $fieldsBar = [];
        foreach ($completenessFieldCount as $field => $count) {
            $fieldsBar[] = [
                'field' => $field,
                'present' => $count,
                'missing' => $total - $count,
                'pct' => $total > 0 ? round($count / $total, 3) : 0,
            ];
        }
        usort($fieldsBar, fn ($a, $b) => $b['pct'] <=> $a['pct']);

        $completenessScore = $total > 0 ? round($totalScore / $total, 3) : 0;

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => [
                'total_screenings'   => $total,
                'complete_contact_info_pct' => $total > 0 ? round(($total - $missingPhone - $missingAddress) / max(1, $total), 3) : 0,
                'missing_phone_pct'  => $total > 0 ? round($missingPhone / $total, 3) : 0,
                'completeness_score' => $completenessScore,
                'high_risk_cases'    => $highRisk,
            ],
            'fields'    => $fieldsBar,
            'screeners' => $screeners,
            'poes'      => $poes,
            'trend'     => $trend,
        ];
    }
}
