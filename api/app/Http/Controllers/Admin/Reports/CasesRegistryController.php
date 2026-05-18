<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Services\Reports\ExportWriter;
use App\Services\Reports\Insights\CasesRegistryInsightEngine;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * R5 · rpt-registry — National Notifiable Cases Registry (PII-bearing)
 */
final class CasesRegistryController extends BaseReportController
{
    protected string $reportKey   = 'rpt-registry';
    protected string $reportTitle = 'National Notifiable Cases Registry';

    public function __construct(
        ReportScope $scope,
        ReportAccess $access,
        ExportWriter $writer,
        protected CasesRegistryInsightEngine $engine,
    ) {
        parent::__construct($scope, $access, $writer);
    }

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.reports.rpt-registry.index', [
            'scope' => $scope, 'reportKey' => $this->reportKey,
            'reportTitle' => $this->reportTitle, 'dataNotes' => $this->dataNotes(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->readFilters($request);

        // Default window: past 7 days (R10 protocol) — only kick in when the
        // user has not asked for a specific year / quarter / month / range.
        if (empty($filters['year']) && empty($filters['start_date']) && empty($filters['quarter']) && empty($filters['month']) && empty($filters['end_date'])) {
            $filters['default_days'] = 7;
        }
        $payload = $this->buildPayload($scope, $filters);
        $payload['insights']   = $this->engine->evaluate($payload);
        $payload['filters']    = $filters;
        $payload['scope']      = ['label' => $scope['label'] ?? '—', 'level' => $scope['scope_level'] ?? 'SELF'];
        $payload['data_notes'] = $this->dataNotes();

        // Audit the PII surface explicitly — separate row from the VIEW
        // event so an auditor can identify *what columns the user actually
        // saw unmasked* on the registry. Brief §4.4.
        $this->auditor()?->recordPiiReveal(
            $request,
            $scope,
            $this->reportKey,
            $filters,
            count($payload['cases'] ?? []),
            $this->piiColumnsRevealed($scope),
        );

        return $this->ok($payload);
    }

    /**
     * The PII columns this registry actually shows unmasked, given a scope.
     * Mirrors ReportAccess::maskPii() — keep the two in lock-step.
     */
    private function piiColumnsRevealed(array $scope): array
    {
        $base = ['traveler_full_name', 'traveler_initials', 'traveler_anonymous_code'];
        $pii  = ['phone_number', 'alternative_phone', 'email', 'emergency_contact_phone', 'travel_document_number'];
        if (($scope['is_super'] ?? false) || strtoupper((string) ($scope['scope_level'] ?? '')) === 'PHEOC') {
            return array_merge($base, $pii);
        }
        return $base;
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->readFilters($request);
        if (empty($filters['year']) && empty($filters['start_date'])) { $filters['year'] = (int) Carbon::now()->year; }
        $payload = $this->buildPayload($scope, $filters + ['per_page' => 5000, 'page' => 1]);
        $headers = ['Case #', 'Opened at', 'Name / anonymous', 'Age', 'Gender', 'Risk', 'Disposition', 'EOC (province)', 'POE', 'Phone', 'Email'];
        $rows = [];
        foreach ($payload['cases'] as $r) {
            $rows[] = [
                $r['id'], $r['opened_at'], $r['traveler'], $r['age'] ?? '',
                $r['gender'], $r['risk_level'] ?? '', $r['final_disposition'] ?? '',
                $r['province_code'] ?? '', $r['poe_code'] ?? '',
                $r['phone_number'] ?? '', $r['email'] ?? '',
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

        $q = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($q, $scope);

        if (! empty($filters['poe'])) {
            $q->whereIn('poe_code', is_array($filters['poe']) ? $filters['poe'] : explode(',', (string) $filters['poe']));
        }
        if (! empty($filters['eoc'])) {
            $q->where('province_code', $filters['eoc']);
        }
        if (! empty($filters['outcome'])) {
            $q->where('final_disposition', strtoupper((string) $filters['outcome']));
        }
        if (! empty($filters['classification'])) {
            $q->where('risk_level', strtoupper((string) $filters['classification']));
        }
        if (! empty($filters['q'])) {
            $search = (string) $filters['q'];
            $q->where(function ($w) use ($search) {
                $w->where('traveler_full_name', 'like', "%{$search}%")
                  ->orWhere('traveler_initials', 'like', "%{$search}%")
                  ->orWhere('traveler_anonymous_code', 'like', "%{$search}%")
                  ->orWhere('travel_document_number', 'like', "%{$search}%");
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 25);
        $perPage = max(5, min(5000, $perPage));
        $page    = max(1, (int) ($filters['page'] ?? 1));

        $allRows = (clone $q)->select([
            'id', 'opened_at', 'poe_code', 'province_code', 'district_code',
            'traveler_full_name', 'traveler_initials', 'traveler_anonymous_code',
            'traveler_age_years', 'traveler_gender', 'risk_level', 'syndrome_classification',
            'final_disposition', 'case_status', 'phone_number', 'alternative_phone', 'email',
            'emergency_contact_phone', 'travel_document_number',
        ])->orderByDesc('opened_at')->get();

        $total = $allRows->count();
        $paged = $allRows->slice(($page - 1) * $perPage, $perPage)->values();

        $ids = $paged->pluck('id')->all();
        $linkedAlerts = $ids ? DB::table('alerts')
            ->whereIn('secondary_screening_id', $ids)
            ->select('secondary_screening_id', 'status', 'ihr_tier')
            ->get()->groupBy('secondary_screening_id') : collect();

        $cases = [];
        foreach ($paged as $r) {
            $alerts = $linkedAlerts->get($r->id, collect());
            $confirmed = $alerts->contains(fn ($a) => $a->status === 'CLOSED' && $a->ihr_tier);
            $row = [
                'id'                     => (int) $r->id,
                'opened_at'              => $r->opened_at,
                'traveler'               => $r->traveler_full_name ?: ($r->traveler_initials ?: $r->traveler_anonymous_code ?: '—'),
                'age'                    => $r->traveler_age_years,
                'gender'                 => $r->traveler_gender,
                'risk_level'             => $r->risk_level,
                'syndrome_classification' => $r->syndrome_classification,
                'final_disposition'      => $r->final_disposition,
                'case_status'            => $r->case_status,
                'poe_code'               => $r->poe_code,
                'province_code'          => $r->province_code,
                'district_code'          => $r->district_code,
                'phone_number'           => $r->phone_number,
                'alternative_phone'      => $r->alternative_phone,
                'email'                  => $r->email,
                'emergency_contact_phone' => $r->emergency_contact_phone,
                'travel_document_number' => $r->travel_document_number,
                'confirmed_notifiable'   => $confirmed,
            ];
            $cases[] = $this->access->maskPii($row, $scope);
        }

        // KPIs (over ALL rows in window, not just page)
        $totalAll = $allRows->count();
        $pendingAll = $allRows->whereIn('case_status', ['OPEN', 'IN_PROGRESS'])->count();
        $withOutcomesAll = $allRows->whereNotNull('final_disposition')->count();
        $highRiskAll = $allRows->whereIn('risk_level', ['HIGH', 'CRITICAL'])->count();
        $referralsAll = $allRows->whereIn('final_disposition', ['REFERRED', 'TRANSFERRED'])->count();

        $allIds = $allRows->pluck('id')->all();
        $confirmedAll = 0;
        if (! empty($allIds)) {
            $confirmedAll = DB::table('alerts')
                ->whereIn('secondary_screening_id', $allIds)
                ->whereNotNull('ihr_tier')
                ->where('status', 'CLOSED')
                ->distinct()->count('secondary_screening_id');
        }

        // Outcome funnel
        $outcomeBuckets = [
            'Pending'   => $pendingAll,
            'Released'  => $allRows->where('final_disposition', 'RELEASED')->count(),
            'Referred'  => $referralsAll,
            'Isolated'  => $allRows->whereIn('final_disposition', ['QUARANTINED', 'ISOLATED', 'DENIED_BOARDING', 'DELAYED'])->count(),
            'Other'     => $allRows->whereNotIn('final_disposition', ['RELEASED', 'REFERRED', 'TRANSFERRED', 'QUARANTINED', 'ISOLATED', 'DENIED_BOARDING', 'DELAYED'])->whereNotNull('final_disposition')->count(),
            'Confirmed' => $confirmedAll,
        ];

        // EOC heatmap: province × outcome bucket
        $eocHeat = [];
        foreach ($allRows as $r) {
            $prov = $r->province_code ?: 'UNASSIGNED';
            if (! isset($eocHeat[$prov])) {
                $eocHeat[$prov] = array_fill_keys(['Pending', 'Released', 'Referred', 'Isolated', 'Other'], 0);
            }
            if (in_array($r->case_status, ['OPEN', 'IN_PROGRESS'], true)) { $eocHeat[$prov]['Pending']++; continue; }
            switch ($r->final_disposition) {
                case 'RELEASED': $eocHeat[$prov]['Released']++; break;
                case 'REFERRED':
                case 'TRANSFERRED': $eocHeat[$prov]['Referred']++; break;
                case 'QUARANTINED':
                case 'ISOLATED':
                case 'DENIED_BOARDING':
                case 'DELAYED': $eocHeat[$prov]['Isolated']++; break;
                default: $eocHeat[$prov]['Other']++;
            }
        }

        // POE outcome comparison
        $poeCompare = [];
        foreach ($allRows as $r) {
            $poe = $r->poe_code ?: 'UNKNOWN';
            if (! isset($poeCompare[$poe])) {
                $poeCompare[$poe] = ['poe' => $poe, 'total' => 0, 'pending' => 0, 'referred' => 0, 'isolated' => 0];
            }
            $poeCompare[$poe]['total']++;
            if (in_array($r->case_status, ['OPEN', 'IN_PROGRESS'], true)) $poeCompare[$poe]['pending']++;
            elseif (in_array($r->final_disposition, ['REFERRED', 'TRANSFERRED'], true)) $poeCompare[$poe]['referred']++;
            elseif (in_array($r->final_disposition, ['QUARANTINED', 'ISOLATED', 'DENIED_BOARDING', 'DELAYED'], true)) $poeCompare[$poe]['isolated']++;
        }
        $poeCompare = array_values($poeCompare);
        usort($poeCompare, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'kpis' => [
                'total'          => $totalAll,
                'suspected'      => $totalAll - $pendingAll,
                'pending'        => $pendingAll,
                'with_outcomes'  => $withOutcomesAll,
                'confirmed'      => $confirmedAll,
                'high_risk'      => $highRiskAll,
                'referrals'      => $referralsAll,
            ],
            'pagination' => [
                'total'     => $totalAll,
                'page'      => $page,
                'per_page'  => $perPage,
                'pages'     => (int) ceil($totalAll / $perPage),
            ],
            'cases'       => $cases,
            'outcomes'    => $outcomeBuckets,
            'eoc_heatmap' => $eocHeat,
            'poes'        => $poeCompare,
        ];
    }
}
