<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports\V2;

use App\Http\Controllers\Admin\Reports\BaseReportController;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * R6 · rpt-case-files — Case File Registry.
 *
 * Centred on secondary_screenings (the master case record). The drill modal
 * surfaces 100% of the case data: identity · travel · clinical · symptoms ·
 * exposures · suspected diseases · actions · samples · disposition · linked
 * alert + outcome. Audience split:
 *   Executive (main view) — case volume by disposition, top suspected diseases.
 *   Technical (drill modal) — every column the schema supports.
 */
final class CaseFileRegistryController extends BaseReportController
{
    protected string $reportKey   = 'rpt-case-files';
    protected string $reportTitle = 'Case File Registry';

    public function index(Request $request): View
    {
        $this->ensureAccess($request);
        return view('admin.reports.v2.rpt-case-files', [
            'reportKey'   => $this->reportKey,
            'reportTitle' => $this->reportTitle,
        ]);
    }

    public function meta(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        return $this->ok([
            'poes'  => $this->scope->allowedPoes($scope),
            'scope' => ['label' => $scope['label'] ?? '—', 'level' => $scope['scope_level'] ?? 'SELF'],
        ]);
    }

    public function kpis(Request $request): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $q = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($q, $scope);
        if (! empty($f['poe'])) $q->where('poe_code', $f['poe']);

        $agg = (clone $q)->selectRaw("
            COUNT(*) AS total,
            SUM(final_disposition='RELEASED')                            AS released,
            SUM(final_disposition IN ('REFERRED','TRANSFERRED'))         AS referred,
            SUM(final_disposition IN ('ISOLATED','QUARANTINED'))         AS isolated,
            SUM(risk_level IN ('HIGH','CRITICAL'))                       AS high_risk
        ")->first();

        // With active alert.
        $secIds = (clone $q)->pluck('id')->all();
        $withAlert = empty($secIds) ? 0 : (int) DB::table('alerts')->whereNull('deleted_at')
            ->whereIn('secondary_screening_id', $secIds)->distinct()->count('secondary_screening_id');

        return $this->ok([
            'window' => [
                'from'  => $from->toDateString(),
                'to'    => $to->toDateString(),
                'label' => $from->format('d M Y') . ' – ' . $to->format('d M Y'),
            ],
            'kpis' => [
                ['key' => 'total',     'label' => 'Total Cases',    'value' => number_format((int) ($agg->total ?? 0)),    'tone' => 'brand',   'hint' => 'Secondary screenings in window.'],
                ['key' => 'released',  'label' => 'Released',       'value' => number_format((int) ($agg->released ?? 0)), 'tone' => 'success', 'hint' => 'Sent on their way after review.'],
                ['key' => 'referred',  'label' => 'Referred',       'value' => number_format((int) ($agg->referred ?? 0)), 'tone' => 'info',    'hint' => 'Sent to facility / transferred.'],
                ['key' => 'isolated',  'label' => 'Isolated',       'value' => number_format((int) ($agg->isolated ?? 0)), 'tone' => 'warning', 'hint' => 'Quarantined or isolated at POE.'],
                ['key' => 'high_risk', 'label' => 'High / Critical','value' => number_format((int) ($agg->high_risk ?? 0)),'tone' => 'critical','hint' => 'Clinician marked HIGH or CRITICAL risk.'],
            ],
            'extra' => ['with_alert' => $withAlert],
        ]);
    }

    public function chart(Request $request, string $chart): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        return match ($chart) {
            'disposition_mix'         => $this->ok($this->chartDispositionMix($scope, $f, $from, $to)),
            'top_suspected_diseases'  => $this->ok($this->chartTopDiseases($scope, $f, $from, $to)),
            default                   => $this->fail(404, 'Unknown chart key.'),
        };
    }

    public function chartCsv(Request $request, string $chart): StreamedResponse
    {
        $scope = $this->ensureAccess($request);
        $f     = $this->readFilters($request);
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $payload = match ($chart) {
            'disposition_mix'        => $this->chartDispositionMix($scope, $f, $from, $to),
            'top_suspected_diseases' => $this->chartTopDiseases($scope, $f, $from, $to),
            default                  => abort(404),
        };
        return $this->streamCsv("rpt-case-files__{$chart}", $payload['csv_headers'], $payload['csv_rows']);
    }

    public function records(Request $request): JsonResponse
    {
        $scope    = $this->ensureAccess($request);
        $f        = $this->readFilters($request);
        $page     = max(1, (int) $request->input('page', 1));
        $perPage  = 10;
        $q        = trim((string) $request->input('q', ''));
        $sort     = (string) $request->input('sort', 'opened_at');
        $dir      = strtolower((string) $request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $cat      = (string) $request->input('cat', 'all');
        [$from, $to] = $this->scope->resolveDateWindow($f);

        $base = function () use ($scope, $f, $from, $to) {
            $q = DB::table('secondary_screenings AS s')->whereNull('s.deleted_at')
                ->whereBetween('s.opened_at', [$from, $to]);
            $this->scope->apply($q, $scope, 's');
            if (! empty($f['poe']))    $q->where('s.poe_code', $f['poe']);
            if (! empty($f['gender'])) $q->where('s.traveler_gender', $f['gender']);
            return $q;
        };

        $qb = $base();
        if ($cat === 'released') $qb->where('s.final_disposition', 'RELEASED');
        elseif ($cat === 'referred') $qb->whereIn('s.final_disposition', ['REFERRED', 'TRANSFERRED']);
        elseif ($cat === 'isolated') $qb->whereIn('s.final_disposition', ['ISOLATED', 'QUARANTINED']);
        elseif ($cat === 'delayed') $qb->where('s.final_disposition', 'DELAYED');
        elseif ($cat === 'high_risk') $qb->whereIn('s.risk_level', ['HIGH', 'CRITICAL']);

        if ($q !== '') {
            $qb->where(function ($w) use ($q) {
                $w->where('s.traveler_full_name', 'like', '%' . $q . '%')
                  ->orWhere('s.traveler_nationality_country_code', 'like', '%' . $q . '%')
                  ->orWhere('s.poe_code', 'like', '%' . $q . '%')
                  ->orWhere('s.travel_document_number', 'like', '%' . $q . '%');
            });
        }

        $sortMap = [
            'opened_at'   => 's.opened_at',
            'name'        => 's.traveler_full_name',
            'poe_code'    => 's.poe_code',
            'risk_level'  => 's.risk_level',
            'disposition' => 's.final_disposition',
            'age'         => 's.traveler_age_years',
        ];
        $sortCol = $sortMap[$sort] ?? 's.opened_at';
        $qb->orderBy($sortCol, $dir);

        // Total count.
        $totalQb = $base();
        if ($cat === 'released') $totalQb->where('s.final_disposition', 'RELEASED');
        elseif ($cat === 'referred') $totalQb->whereIn('s.final_disposition', ['REFERRED', 'TRANSFERRED']);
        elseif ($cat === 'isolated') $totalQb->whereIn('s.final_disposition', ['ISOLATED', 'QUARANTINED']);
        elseif ($cat === 'delayed') $totalQb->where('s.final_disposition', 'DELAYED');
        elseif ($cat === 'high_risk') $totalQb->whereIn('s.risk_level', ['HIGH', 'CRITICAL']);
        if ($q !== '') {
            $totalQb->where(function ($w) use ($q) {
                $w->where('s.traveler_full_name', 'like', '%' . $q . '%')
                  ->orWhere('s.traveler_nationality_country_code', 'like', '%' . $q . '%')
                  ->orWhere('s.poe_code', 'like', '%' . $q . '%')
                  ->orWhere('s.travel_document_number', 'like', '%' . $q . '%');
            });
        }
        $total      = (int) $totalQb->count('s.id');
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page       = min($page, $totalPages);

        $rows = $qb->forPage($page, $perPage)->get([
            's.id', 's.traveler_full_name', 's.traveler_initials', 's.traveler_anonymous_code',
            's.traveler_gender', 's.traveler_age_years', 's.traveler_nationality_country_code',
            's.poe_code', 's.opened_at', 's.final_disposition', 's.risk_level', 's.triage_category',
        ])->map(fn ($r) => $this->access->maskPii([
            'id'          => (int) $r->id,
            'name'        => $r->traveler_full_name ?: ($r->traveler_initials ?: $r->traveler_anonymous_code ?: 'Anonymous'),
            'gender'      => $r->traveler_gender,
            'age'         => $r->traveler_age_years,
            'nationality' => $r->traveler_nationality_country_code,
            'poe_code'    => $r->poe_code,
            'opened_at'   => $r->opened_at,
            'disposition' => $r->final_disposition,
            'risk_level'  => $r->risk_level,
            'triage'      => $r->triage_category,
        ], $scope));

        // Category counts.
        $catRow = (clone $base())->selectRaw("
            COUNT(*) AS all_,
            SUM(s.final_disposition='RELEASED') AS released,
            SUM(s.final_disposition IN ('REFERRED','TRANSFERRED')) AS referred,
            SUM(s.final_disposition IN ('ISOLATED','QUARANTINED')) AS isolated,
            SUM(s.final_disposition='DELAYED') AS delayed_,
            SUM(s.risk_level IN ('HIGH','CRITICAL')) AS high_risk
        ")->first();

        return $this->ok([
            'rows' => $rows,
            'pagination' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $totalPages,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to'   => min($page * $perPage, $total),
            ],
            'controls' => ['sort' => $sort, 'dir' => $dir, 'q' => $q, 'cat' => $cat],
            'category_counts' => [
                'all'       => (int) ($catRow->all_ ?? 0),
                'released'  => (int) ($catRow->released ?? 0),
                'referred'  => (int) ($catRow->referred ?? 0),
                'isolated'  => (int) ($catRow->isolated ?? 0),
                'delayed'   => (int) ($catRow->delayed_ ?? 0),
                'high_risk' => (int) ($catRow->high_risk ?? 0),
            ],
        ]);
    }

    public function recordDetail(Request $request, int $id): JsonResponse
    {
        $scope = $this->ensureAccess($request);
        return $this->ok($this->buildRecordDetail($scope, $id));
    }

    /**
     * Full-page rendering of the same case-file payload that recordDetail()
     * returns as JSON. Routed at GET /admin/reports/rpt-case-files/{id} so
     * deep links from Alert Intel / Resolution DB / etc. can land directly
     * on the full case file instead of the cramped 460px drill side-sheet.
     */
    public function show(Request $request, int $id): \Illuminate\Contracts\View\View
    {
        $scope = $this->ensureAccess($request);
        $data  = $this->buildRecordDetail($scope, $id);

        return view('admin.reports.v2.rpt-case-files-show', [
            'data'        => $data,
            'caseId'      => $id,
            'reportKey'   => $this->reportKey,
            'reportTitle' => 'Case File · #' . $id,
            'scope'       => $scope,
        ]);
    }

    /**
     * Pulls the full case-file payload (identity, travel, clinical, symptoms,
     * exposures, suspected diseases, actions, samples, linked alert + outcome)
     * for the given secondary_screenings row. Honors PII masking per scope.
     */
    private function buildRecordDetail(array $scope, int $id): array
    {
        $s = DB::table('secondary_screenings')->where('id', $id)->whereNull('deleted_at')->first();
        abort_if(! $s, 404, 'Case not found.');

        // Symptoms (with name lookup, collation-safe).
        $sx = DB::table('secondary_symptoms')->where('secondary_screening_id', $id)->get(['symptom_code', 'is_present', 'explicit_absent', 'onset_date', 'details']);
        $sxCodes = $sx->pluck('symptom_code')->all();
        $sxNames = empty($sxCodes) ? [] : DB::table('ref_symptoms')->whereIn('symptom_code', $sxCodes)
            ->pluck('display_name', 'symptom_code')->all();
        $sxRedFlags = empty($sxCodes) ? [] : DB::table('ref_symptoms')->whereIn('symptom_code', $sxCodes)
            ->pluck('is_red_flag', 'symptom_code')->all();
        $symptoms = $sx->map(fn ($r) => [
            'code'         => $r->symptom_code,
            'name'         => $sxNames[$r->symptom_code] ?? $r->symptom_code,
            'is_present'   => (bool) $r->is_present,
            'explicit_absent' => (bool) $r->explicit_absent,
            'onset_date'   => $r->onset_date,
            'details'      => $r->details,
            'is_red_flag'  => (bool) ($sxRedFlags[$r->symptom_code] ?? false),
        ]);

        // Exposures.
        $ex = DB::table('secondary_exposures')->where('secondary_screening_id', $id)->get(['exposure_code', 'response', 'details']);
        $exCodes = $ex->pluck('exposure_code')->all();
        $exNames = empty($exCodes) ? [] : DB::table('ref_exposures')->whereIn('exposure_code', $exCodes)
            ->pluck('display_name', 'exposure_code')->all();
        $exRisks = empty($exCodes) ? [] : DB::table('ref_exposures')->whereIn('exposure_code', $exCodes)
            ->pluck('is_high_risk', 'exposure_code')->all();
        $exposures = $ex->map(fn ($r) => [
            'code'         => $r->exposure_code,
            'name'         => $exNames[$r->exposure_code] ?? $r->exposure_code,
            'response'     => $r->response,
            'details'      => $r->details,
            'is_high_risk' => (bool) ($exRisks[$r->exposure_code] ?? false),
        ]);

        // Suspected diseases (collation-safe).
        $sd = DB::table('secondary_suspected_diseases')->where('secondary_screening_id', $id)
            ->orderBy('rank_order')->get(['disease_code', 'rank_order', 'confidence', 'reasoning']);
        $disCodes = $sd->pluck('disease_code')->all();
        $disNames = empty($disCodes) ? [] : DB::table('ref_diseases')->whereIn('disease_code', $disCodes)
            ->pluck('display_name', 'disease_code')->all();
        $diseases = $sd->map(fn ($r) => [
            'code'        => $r->disease_code,
            'name'        => $disNames[$r->disease_code] ?? $r->disease_code,
            'rank'        => $r->rank_order,
            'confidence'  => $r->confidence,
            'reasoning'   => $r->reasoning,
        ]);

        $actions      = DB::table('secondary_actions')->where('secondary_screening_id', $id)->get(['action_code', 'is_done', 'details']);
        $samples      = DB::table('secondary_samples')->where('secondary_screening_id', $id)->get(['sample_collected', 'sample_type', 'sample_identifier', 'lab_destination', 'collected_at']);
        $travelCountries = DB::table('secondary_travel_countries')->where('secondary_screening_id', $id)
            ->orderBy('arrival_date')->get(['country_code', 'travel_role', 'arrival_date', 'departure_date']);

        // Linked alert + outcome.
        $alert = DB::table('alerts')->where('secondary_screening_id', $id)->whereNull('deleted_at')->first();
        $outcome = $alert ? DB::table('alert_case_outcomes')->where('alert_id', $alert->id)->whereNull('deleted_at')->first() : null;

        // Apply PII masking on the secondary record itself.
        $cardRaw = (array) $s;
        $card    = $this->access->maskPii($cardRaw, $scope);

        return [
            'case' => [
                'id'                  => $card['id'],
                'opened_at'           => $card['opened_at'],
                'closed_at'           => $card['closed_at'],
                'dispositioned_at'    => $card['dispositioned_at'],
                'case_status'         => $card['case_status'],
                'final_disposition'   => $card['final_disposition'],
                'disposition_details' => $card['disposition_details'],
                'screening_outcome'   => $card['screening_outcome'],
                'followup_required'   => (bool) ($card['followup_required'] ?? false),
                'followup_assigned_level' => $card['followup_assigned_level'],
                'poe_code'            => $card['poe_code'],
            ],
            'identity' => [
                'name'             => $card['traveler_full_name'],
                'initials'         => $card['traveler_initials'],
                'anonymous_code'   => $card['traveler_anonymous_code'],
                'document_type'    => $card['travel_document_type'],
                'document_number'  => $card['travel_document_number'],
                'gender'           => $card['traveler_gender'],
                'age'              => $card['traveler_age_years'],
                'dob'              => $card['traveler_dob'],
                'nationality'      => $card['traveler_nationality_country_code'],
                'occupation'       => $card['traveler_occupation'],
                'residence_country'=> $card['residence_country_code'],
                'residence_address'=> $card['residence_address_text'],
                'phone'            => $card['phone_number'] ?? null,
                'phone_alt'        => $card['alternative_phone'] ?? null,
                'email'            => $card['email'] ?? null,
                'destination_address' => $card['destination_address_text'],
                'destination_district'=> $card['destination_district_code'],
                'emergency_contact_name'  => $card['emergency_contact_name'],
                'emergency_contact_phone' => $card['emergency_contact_phone'] ?? null,
            ],
            'travel' => [
                'origin_country'         => $card['journey_start_country_code'],
                'embarkation_port_city'  => $card['embarkation_port_city'],
                'conveyance_type'        => $card['conveyance_type'],
                'conveyance_identifier'  => $card['conveyance_identifier'],
                'seat_number'            => $card['seat_number'],
                'arrival_datetime'       => $card['arrival_datetime'],
                'departure_datetime'     => $card['departure_datetime'],
                'purpose_of_travel'      => $card['purpose_of_travel'],
                'planned_length_of_stay' => $card['planned_length_of_stay_days'],
                'countries'              => $travelCountries,
            ],
            'clinical' => [
                'general_appearance'      => $card['general_appearance'],
                'temperature_value'       => $card['temperature_value'],
                'temperature_unit'        => $card['temperature_unit'],
                'pulse_rate'              => $card['pulse_rate'],
                'respiratory_rate'        => $card['respiratory_rate'],
                'bp_systolic'             => $card['bp_systolic'],
                'bp_diastolic'            => $card['bp_diastolic'],
                'oxygen_saturation'       => $card['oxygen_saturation'],
                'syndrome_classification' => $card['syndrome_classification'],
                'risk_level'              => $card['risk_level'],
                'triage_category'         => $card['triage_category'],
                'emergency_signs_present' => (bool) ($card['emergency_signs_present'] ?? false),
                'officer_notes'           => $card['officer_notes'],
            ],
            'symptoms'  => $symptoms,
            'exposures' => $exposures,
            'diseases'  => $diseases,
            'actions'   => $actions,
            'samples'   => $samples,
            'alert'     => $alert ? [
                'id'              => $alert->id,
                'code'            => $alert->alert_code,
                'title'           => $alert->alert_title,
                'risk_level'      => $alert->risk_level,
                'status'          => $alert->status,
                'created_at'      => $alert->created_at,
                'closed_at'       => $alert->closed_at,
                'close_category'  => $alert->close_category,
                'reopen_count'    => $alert->reopen_count,
            ] : null,
            'outcome' => $outcome ? [
                'classification'   => $outcome->case_classification,
                'reason'           => $outcome->case_classification_reason,
                'lab_status'       => $outcome->lab_status,
                'lab_disease_code' => $outcome->lab_disease_code,
                'lab_test_method'  => $outcome->lab_test_method,
                'clinical_outcome' => $outcome->clinical_outcome,
                'ph_action'        => $outcome->ph_action,
                'ihr_notified'     => (bool) $outcome->ihr_notified,
                'ihr_reference'    => $outcome->ihr_reference,
            ] : null,
        ];
    }

    /* ───── chart builders ───── */

    private function chartDispositionMix(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        $q = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to])
            ->selectRaw("COALESCE(final_disposition, 'NOT_SET') AS disposition, COUNT(*) AS c")
            ->groupBy('disposition')
            ->orderByDesc('c');
        $this->scope->apply($q, $scope);
        if (! empty($f['poe']))    $q->where('poe_code', $f['poe']);
        if (! empty($f['gender'])) $q->where('traveler_gender', $f['gender']);
        $rows = $q->get();

        $labels = $data = $csv = [];
        foreach ($rows as $r) {
            $labels[] = $r->disposition;
            $data[]   = (int) $r->c;
            $csv[]    = [$r->disposition, (int) $r->c];
        }

        return [
            'labels'   => $labels,
            'datasets' => [['label' => 'Cases', 'data' => $data]],
            'csv_headers' => ['Disposition', 'Cases'],
            'csv_rows'    => $csv,
        ];
    }

    private function chartTopDiseases(array $scope, array $f, Carbon $from, Carbon $to): array
    {
        // Bound by scope: get the secondary screening IDs first.
        $secQ = DB::table('secondary_screenings')->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($secQ, $scope);
        if (! empty($f['poe']))    $secQ->where('poe_code', $f['poe']);
        if (! empty($f['gender'])) $secQ->where('traveler_gender', $f['gender']);
        $secIds = $secQ->pluck('id')->all();

        if (empty($secIds)) {
            return ['labels' => [], 'datasets' => [['label' => 'Suspicions', 'data' => []]],
                    'csv_headers' => ['Disease', 'Suspicions'], 'csv_rows' => []];
        }

        $rows = DB::table('secondary_suspected_diseases')
            ->whereIn('secondary_screening_id', $secIds)
            ->selectRaw('disease_code, COUNT(*) AS c')
            ->groupBy('disease_code')
            ->orderByDesc('c')
            ->limit(10)->get();

        $codes = $rows->pluck('disease_code')->all();
        $names = empty($codes) ? [] : DB::table('ref_diseases')->whereIn('disease_code', $codes)
            ->pluck('display_name', 'disease_code')->all();

        $labels = $data = $csv = [];
        foreach ($rows as $r) {
            $name = $names[$r->disease_code] ?? $r->disease_code;
            $labels[] = $name;
            $data[]   = (int) $r->c;
            $csv[]    = [$name, (int) $r->c];
        }

        return [
            'labels'   => $labels,
            'datasets' => [['label' => 'Suspicions', 'data' => $data]],
            'csv_headers' => ['Disease', 'Suspicions'],
            'csv_rows'    => $csv,
        ];
    }

    private function streamCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $r) fputcsv($out, $r);
            fclose($out);
        }, $filename . '__' . now()->format('Ymd-Hi') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
