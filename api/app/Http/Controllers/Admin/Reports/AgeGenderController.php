<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\Insights\AgeGenderInsightEngine;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R6 · rpt-age-gender — Age & Gender Risk Intelligence
 */
final class AgeGenderController extends BaseReportController
{
    protected string $reportKey   = 'rpt-age-gender';
    protected string $reportTitle = 'Age & Gender Risk Intelligence';

    private const BANDS = [
        ['key' => '0-4',   'label' => '0–4',   'min' => 0,  'max' => 4],
        ['key' => '5-14',  'label' => '5–14',  'min' => 5,  'max' => 14],
        ['key' => '15-24', 'label' => '15–24', 'min' => 15, 'max' => 24],
        ['key' => '25-49', 'label' => '25–49', 'min' => 25, 'max' => 49],
        ['key' => '50-64', 'label' => '50–64', 'min' => 50, 'max' => 64],
        ['key' => '65+',   'label' => '65+',   'min' => 65, 'max' => 150],
    ];

    public function __construct(
        ReportScope $scope,
        ReportAccess $access,
        ExportWriter $writer,
        protected AgeGenderInsightEngine $engine,
    ) {
        parent::__construct($scope, $access, $writer);
    }

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.reports.rpt-age-gender.index', [
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
        $payload = $this->memoise((int) ($scope['user_id'] ?? 0), $filters + ['__r' => 'r6'], fn () => $this->buildPayload($scope, $filters));
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
        $headers = ['Age band', 'Male', 'Female', 'Total', 'Notifiable', 'Notifiable %'];
        $rows = [];
        foreach ($payload['age_bands'] as $r) {
            $pct = $r['total'] >= 5 ? number_format(($r['notifiable'] / max(1, $r['total'])) * 100, 1) . '%' : '— (n<5)';
            $rows[] = [$r['band'], $r['male'], $r['female'], $r['total'], $r['notifiable'], $pct];
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

        $primaryQ = DB::table('primary_screenings')
            ->whereNull('deleted_at')->where('record_status', 'COMPLETED')
            ->whereBetween('captured_at', [$from, $to]);
        $this->scope->apply($primaryQ, $scope);
        if (! empty($filters['poe'])) {
            $primaryQ->whereIn('poe_code', is_array($filters['poe']) ? $filters['poe'] : explode(',', (string) $filters['poe']));
        }

        $primary = (int) (clone $primaryQ)->count();

        $secQ = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($secQ, $scope);
        if (! empty($filters['poe'])) {
            $secQ->whereIn('poe_code', is_array($filters['poe']) ? $filters['poe'] : explode(',', (string) $filters['poe']));
        }
        $secRows = (clone $secQ)->select([
            'id', 'traveler_age_years', 'traveler_gender', 'risk_level', 'final_disposition', 'case_status',
        ])->get();

        $secIds = $secRows->pluck('id')->all();
        $alertNotifiable = $secIds ? DB::table('alerts')
            ->whereIn('secondary_screening_id', $secIds)
            ->whereNotNull('ihr_tier')->pluck('secondary_screening_id')->unique()->values()->all() : [];
        $notifSet = array_flip($alertNotifiable);

        $secondary = $secRows->count();
        $notifiable = $secRows->filter(function ($r) use ($notifSet) {
            return isset($notifSet[$r->id]) || in_array($r->risk_level, ['HIGH', 'CRITICAL'], true);
        })->count();
        $referrals = $secRows->whereIn('final_disposition', ['REFERRED', 'TRANSFERRED'])->count();
        $completion = $secRows->whereNotIn('case_status', ['OPEN', 'IN_PROGRESS'])->count();

        $bands = [];
        $summary = ['U5' => 0, '65+' => 0];
        foreach (self::BANDS as $b) {
            $bands[$b['key']] = [
                'band' => $b['label'],
                'male' => 0, 'female' => 0, 'other' => 0,
                'total' => 0, 'notifiable' => 0, 'referrals' => 0,
            ];
        }
        foreach ($secRows as $r) {
            $age = $r->traveler_age_years;
            if ($age === null) { continue; }
            $age = (int) $age;
            $bandKey = null;
            foreach (self::BANDS as $b) { if ($age >= $b['min'] && $age <= $b['max']) { $bandKey = $b['key']; break; } }
            if ($bandKey === null) { continue; }
            $bands[$bandKey]['total']++;
            $g = strtoupper((string) $r->traveler_gender);
            if ($g === 'MALE')        $bands[$bandKey]['male']++;
            elseif ($g === 'FEMALE')  $bands[$bandKey]['female']++;
            else                      $bands[$bandKey]['other']++;
            $isNotif = isset($notifSet[$r->id]) || in_array($r->risk_level, ['HIGH', 'CRITICAL'], true);
            if ($isNotif) $bands[$bandKey]['notifiable']++;
            if (in_array($r->final_disposition, ['REFERRED', 'TRANSFERRED'], true)) $bands[$bandKey]['referrals']++;
            if ($bandKey === '0-4') $summary['U5']++;
            if ($bandKey === '65+') $summary['65+']++;
        }
        $bandsArr = array_values($bands);

        // Gender totals
        $gender = [];
        foreach (['MALE', 'FEMALE', 'OTHER', 'UNKNOWN'] as $g) {
            $gTotal = $secRows->where('traveler_gender', $g)->count();
            $gNotif = $secRows->where('traveler_gender', $g)->filter(function ($r) use ($notifSet) {
                return isset($notifSet[$r->id]) || in_array($r->risk_level, ['HIGH', 'CRITICAL'], true);
            })->count();
            $gRef = $secRows->where('traveler_gender', $g)->whereIn('final_disposition', ['REFERRED', 'TRANSFERRED'])->count();
            $gender[$g] = ['total' => $gTotal, 'notifiable' => $gNotif, 'referrals' => $gRef];
        }

        // Gender disparity across age bands
        $disparity = [];
        foreach ($bandsArr as $row) {
            $mTotal = $row['male']; $fTotal = $row['female'];
            if ($mTotal + $fTotal === 0) { continue; }
            $disparity[] = [
                'band'       => $row['band'],
                'male'       => $mTotal,
                'female'     => $fTotal,
                'notifiable' => $row['notifiable'],
                'referrals'  => $row['referrals'],
            ];
        }

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => [
                'primary'      => $primary,
                'secondary'    => $secondary,
                'referrals'    => $referrals,
                'notifiable'   => $notifiable,
                'completion_pct' => $secondary > 0 ? round(($completion / $secondary) * 100, 1) : null,
            ],
            'age_bands'         => $bandsArr,
            'age_bands_summary' => $summary,
            'gender'            => $gender,
            'disparity'         => $disparity,
        ];
    }
}
