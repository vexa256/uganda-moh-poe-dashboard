<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PheocCopilot;
use App\Services\PheocScope;
use App\Support\DiseaseResolver;
use App\Support\EnumTranslator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin · Case Files Register (sidebar #5 · Phase B).
 *
 *   GET /admin/cases                  — paginated list with filters
 *   GET /admin/cases/{id}             — single case detail
 *
 * Read-only over `secondary_screenings` + its child tables. The mobile
 * app owns CREATE/UPDATE via /api/screenings/*; this admin controller
 * never writes.
 */
final class CaseFilesController extends Controller
{
    public const PAGE_SIZE = 25;

    public function __construct(
        protected PheocScope $scope,
        protected PheocCopilot $copilot,
        protected EnumTranslator $enum,
        protected DiseaseResolver $diseaseResolver,
    ) {
    }

    public function index(Request $request): View
    {
        $scope = $request->user()
            ? $this->scope->forUser($request->user())
            : ['country_code' => config('country.code'), 'is_super' => true, 'label' => config('country.legacy_code') . ' · National (preview)'];
        $country = (string) ($scope['country_code'] ?? config('country.code'));

        $filters = [
            'status'       => (string) $request->query('status', ''),
            'disposition'  => (string) $request->query('disposition', ''),
            'poe'          => (string) $request->query('poe', ''),
            'district'     => (string) $request->query('district', ''),
            'q'            => trim((string) $request->query('q', '')),
        ];

        $paginator = $this->fetchCases($country, $filters);
        $stats = $this->stats($country);
        $poeOptions = $this->poeOptions($country);
        $brief = $this->copilot->triageBrief(['country_code' => $country]);

        return view('admin.cases.index', [
            'scope'      => $scope,
            'filters'    => $filters,
            'rows'       => $paginator->items(),
            'paginator'  => $paginator,
            'stats'      => $stats,
            'poeOptions' => $poeOptions,
            'brief'      => $brief,
        ]);
    }

    public function show(Request $request, int $id): View
    {
        $scope = $request->user()
            ? $this->scope->forUser($request->user())
            : ['country_code' => config('country.code'), 'is_super' => true, 'label' => config('country.legacy_code') . ' · National (preview)'];
        $country = (string) ($scope['country_code'] ?? config('country.code'));

        $screening = DB::table('secondary_screenings')
            ->where('id', $id)
            ->where('country_code', $country)
            ->whereNull('deleted_at')
            ->first();

        $caseSheet = $screening
            ? $this->buildCaseSheet($id)
            : ['screening' => null];

        // The linked alert, if one exists, is shown as a breadcrumb/quick link.
        $alert = null;
        if ($screening) {
            $alert = DB::table('alerts')
                ->where('secondary_screening_id', $id)
                ->where('country_code', $country)
                ->whereNull('deleted_at')
                ->first();
        }

        return view('admin.cases.show', [
            'scope'     => $scope,
            'caseId'    => $id,
            'caseSheet' => $caseSheet,
            'alert'     => $alert,
            'enum'      => $this->enum,
        ]);
    }

    /* ─── Composition helpers ───────────────────────────────────────── */

    protected function fetchCases(string $country, array $filters)
    {
        $query = DB::table('secondary_screenings')
            ->where('country_code', $country)
            ->whereNull('deleted_at');

        if ($filters['status'] !== '') {
            $query->where('case_status', $filters['status']);
        }
        if ($filters['disposition'] !== '') {
            $query->where('final_disposition', $filters['disposition']);
        }
        if ($filters['poe'] !== '') {
            $query->where('poe_code', $filters['poe']);
        }
        if ($filters['district'] !== '') {
            $query->where('district_code', $filters['district']);
        }
        if ($filters['q'] !== '') {
            $needle = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $query->where(function ($w) use ($needle) {
                $w->where('case_code', 'like', $needle)
                  ->orWhere('traveller_full_name', 'like', $needle)
                  ->orWhere('traveller_passport', 'like', $needle)
                  ->orWhere('traveller_phone', 'like', $needle);
            });
        }

        $paginator = $query->orderByDesc('opened_at')->paginate(self::PAGE_SIZE)->withQueryString();

        $paginator->getCollection()->transform(function ($r) {
            // Top-confidence suspected disease
            $top = DB::table('secondary_suspected_diseases')
                ->where('secondary_screening_id', $r->id)
                ->orderByDesc('confidence')
                ->orderBy('rank_order')
                ->first();
            $diseaseCode = $top ? (string) $top->disease_code : '';
            $diseaseName = $diseaseCode ? \App\Support\DiseaseIntel::nameFor($diseaseCode) : '';

            return [
                'id'                => (int) $r->id,
                'case_code'         => (string) ($r->case_code ?? ('#' . $r->id)),
                'traveller_name'    => (string) ($r->traveller_full_name ?? '—'),
                'traveller_sex'     => (string) ($r->traveller_sex ?? ''),
                'traveller_age'     => (int)    ($r->traveller_age_years ?? 0),
                'status'            => (string) ($r->case_status ?? ''),
                'status_label'      => $this->enum->caseStatus((string) ($r->case_status ?? '')),
                'disposition'       => (string) ($r->final_disposition ?? ''),
                'disposition_label' => $this->enum->disposition((string) ($r->final_disposition ?? '')),
                'poe_code'          => (string) ($r->poe_code ?? ''),
                'district_code'     => (string) ($r->district_code ?? ''),
                'opened_at'         => $r->opened_at,
                'opened_rel'        => $r->opened_at ? Carbon::parse((string) $r->opened_at)->diffForHumans() : '—',
                'disease_code'      => $diseaseCode,
                'disease_name'      => $diseaseName,
                'url'               => url('/admin/cases/' . (int) $r->id),
            ];
        });

        return $paginator;
    }

    protected function buildCaseSheet(int $secondaryId): array
    {
        try {
            $screening = DB::table('secondary_screenings')->where('id', $secondaryId)->whereNull('deleted_at')->first();
            if (! $screening) return ['screening' => null];

            $symptoms  = DB::table('secondary_symptoms')->where('secondary_screening_id', $secondaryId)->get()->map(fn ($r) => (array) $r)->all();
            $exposures = DB::table('secondary_exposures')->where('secondary_screening_id', $secondaryId)->get()->map(fn ($r) => (array) $r)->all();
            $travel    = DB::table('secondary_travel_countries')->where('secondary_screening_id', $secondaryId)->get()->map(fn ($r) => (array) $r)->all();
            $actions   = DB::table('secondary_actions')->where('secondary_screening_id', $secondaryId)->get()->map(fn ($r) => (array) $r)->all();
            $samples   = DB::table('secondary_samples')->where('secondary_screening_id', $secondaryId)->get()->map(fn ($r) => (array) $r)->all();
            $suspected = DB::table('secondary_suspected_diseases')
                ->where('secondary_screening_id', $secondaryId)
                ->orderByDesc('confidence')
                ->orderBy('rank_order')
                ->get()->map(fn ($r) => (array) $r)->all();

            $diseases = $this->diseaseResolver->rankSuspected($suspected);

            return [
                'screening' => (array) $screening,
                'symptoms'  => $symptoms,
                'exposures' => $exposures,
                'travel'    => $travel,
                'actions'   => $actions,
                'samples'   => $samples,
                'diseases'  => $diseases,
                'counts'    => [
                    'symptoms'  => count($symptoms),
                    'exposures' => count($exposures),
                    'travel'    => count($travel),
                    'actions'   => count($actions),
                    'samples'   => count($samples),
                    'diseases'  => count($diseases),
                ],
            ];
        } catch (\Throwable) {
            return ['screening' => null];
        }
    }

    protected function stats(string $country): array
    {
        try {
            $base = DB::table('secondary_screenings')->where('country_code', $country)->whereNull('deleted_at');
            $total = (clone $base)->count();
            $open  = (clone $base)->where('case_status', 'OPEN')->count();
            $last24= (clone $base)->where('opened_at', '>=', now()->subDay())->count();
            $last7 = (clone $base)->where('opened_at', '>=', now()->subDays(7))->count();

            return [
                'total'  => $total,
                'open'   => $open,
                'last24' => $last24,
                'last7'  => $last7,
            ];
        } catch (\Throwable) {
            return ['total' => 0, 'open' => 0, 'last24' => 0, 'last7' => 0];
        }
    }

    protected function poeOptions(string $country): array
    {
        try {
            return DB::table('secondary_screenings')
                ->selectRaw('poe_code, COUNT(*) as n')
                ->where('country_code', $country)
                ->whereNull('deleted_at')
                ->where('opened_at', '>=', now()->subDays(90))
                ->groupBy('poe_code')
                ->orderByDesc('n')
                ->limit(40)
                ->get()
                ->map(fn ($r) => ['code' => (string) $r->poe_code, 'count' => (int) $r->n])
                ->all();
        } catch (\Throwable) { return []; }
    }
}
