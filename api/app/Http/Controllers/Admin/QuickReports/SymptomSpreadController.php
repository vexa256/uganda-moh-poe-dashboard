<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\QuickReports;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quick Report · Symptom Spread.
 *
 * URL:    /admin/quick-reports/symptom-spread
 *
 * Question: "Which symptoms are screeners actually flagging, and how many
 * cases carry a red-flag symptom that deserves immediate attention?"
 *
 * Cohort: every YES symptom (`secondary_symptoms.is_present = 1`) linked
 * to a secondary screening that the user is allowed to see.
 *
 * Red-flag detection draws from `ref_symptoms.is_red_flag = 1` — the
 * clinical-engine catalogue is authoritative.
 *
 * Adaptive chart cascade:
 *   A. Top reported symptoms (red-flag bars in red, others in Material cycle)
 *   B. Cases by red-flag presence (with red-flag vs no red-flag)
 *   C. Symptoms per POE
 *   D. Empty
 */
final class SymptomSpreadController extends BaseQuickReportController
{
    protected string $reportKey   = 'qr-symptoms';
    protected string $reportTitle = 'Symptom Spread';

    private const TABLE_LIMIT = 20;
    private const CHART_TOP_N = 12;

    private const MATERIAL_PALETTE = [
        '#E53935','#1E88E5','#43A047','#FB8C00','#8E24AA','#00ACC1',
        '#F4511E','#3949AB','#7CB342','#D81B60','#FFB300','#00897B',
    ];

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.quick.symptoms.index', [
            'scope' => $scope, 'reportKey' => $this->reportKey, 'reportTitle' => $this->reportTitle,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));
        $payload = $this->memoise(
            (int) ($scope['user_id'] ?? 0), $filters,
            fn () => $this->buildPayload($scope, $filters),
        );
        $payload['filters'] = $filters;
        $payload['scope']   = $this->scopeBlock($scope);
        return $this->ok($payload);
    }

    public function export(Request $request): Response
    {
        $scope   = $this->ensureAccess($request);
        $filters = $this->applyDefaultWindow($this->readFilters($request));
        $payload = $this->buildPayload($scope, $filters);

        $headers = ['Opened (Africa/Kampala)','Traveller','Age','Sex','Nationality','Symptoms (YES)','Red-flag symptoms','Red-flag count','Point of entry','Case file URL'];
        $rows = [];
        foreach ($payload['table_full'] as $r) {
            $rows[] = [
                $r['opened_at_label'], $r['traveller_name'],
                $r['age'] ?? '', $r['sex'] ?? '', $r['nationality'] ?? '',
                implode(' · ', $r['symptoms'] ?? []) ?: '—',
                implode(' · ', $r['red_flags'] ?? []) ?: '—',
                (int) ($r['red_flag_count'] ?? 0),
                $r['poe_name'] ?? '—', $r['case_file_url'] ?? '',
            ];
        }
        return $this->writer->send($this->reportKey, (string) $request->input('format', 'CSV'),
            $headers, $rows, $filters, (int) ($scope['user_id'] ?? 0), $this->reportTitle);
    }

    public function buildPayload(array $scope, array $filters): array
    {
        [$from, $to] = $this->scope->resolveDateWindow($filters);
        $windowLabel = $this->windowLabel($from, $to);

        // ── 1. Cohort: in-scope secondary screenings in window ─────────────
        $secQ = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->whereBetween('opened_at', [$from, $to]);
        $this->scope->apply($secQ, $scope);

        if (! empty($filters['poe'])) { $secQ->where('poe_code', (string) $filters['poe']); }

        $sec = $secQ->select([
            'id','client_uuid','opened_at','traveler_full_name','traveler_initials','traveler_anonymous_code',
            'traveler_age_years','traveler_gender','traveler_nationality_country_code','poe_code',
        ])->orderBy('opened_at', 'desc')->orderBy('id', 'desc')->get();

        // De-dup by client_uuid
        $byUuid = []; $dedup = [];
        foreach ($sec as $r) {
            if (! $r->client_uuid) { $dedup[] = $r; continue; }
            if (! isset($byUuid[$r->client_uuid]) || (int) $r->id > (int) $byUuid[$r->client_uuid]->id) {
                $byUuid[$r->client_uuid] = $r;
            }
        }
        foreach ($byUuid as $r) { $dedup[] = $r; }
        $sec = collect($dedup);
        $secIds = $sec->pluck('id')->map(fn ($v) => (int) $v)->all();

        // ── 2. Symptom rows (YES only) — replace-all child table ───────────
        $symRows = $secIds ? DB::table('secondary_symptoms')
            ->whereIn('secondary_screening_id', $secIds)
            ->where('is_present', 1)
            ->get(['secondary_screening_id','symptom_code']) : collect();

        // ── 3. ref_symptoms lookup (display name + is_red_flag) ────────────
        $codes = $symRows->pluck('symptom_code')->unique()->values()->all();
        $refSym = $codes ? DB::table('ref_symptoms')->whereIn('symptom_code', $codes)
            ->get(['symptom_code','display_name','is_red_flag']) : collect();
        $symMeta = [];
        foreach ($refSym as $r) {
            $symMeta[(string) $r->symptom_code] = [
                'name'     => $r->display_name ?: $r->symptom_code,
                'red_flag' => (int) $r->is_red_flag === 1,
            ];
        }

        // ── 4. POE name lookup ─────────────────────────────────────────────
        $poeCodes = $sec->pluck('poe_code')->filter()->unique()->values()->all();
        $poeNames = $poeCodes ? DB::table('ref_poes')->whereIn('poe_code', $poeCodes)
            ->pluck('poe_name', 'poe_code')->all() : [];

        // ── 4b. Alert id lookup per secondary (for canonical case-file URL) ─
        //     Per project memory: /admin/alerts/{id}/case-file is the canonical
        //     case-file deep-link. When no alert exists for a secondary, fall
        //     back to the rpt-case-files surface keyed by secondary id.
        $alertIdBySid = $secIds
            ? DB::table('alerts')->whereNull('deleted_at')
                ->whereIn('secondary_screening_id', $secIds)
                ->orderBy('id') // smallest id wins → stable when one secondary → many alerts
                ->pluck('id', 'secondary_screening_id')->all()
            : [];

        // "breached" filter is reused as red-flag-only (alias preserved for URL back-compat).
        $redOnly = ! empty($filters['breached']);

        // ── 5. Per-case aggregation + chart facets ─────────────────────────
        $symptomsByCase  = [];   // sid => [display names]
        $redFlagsByCase  = [];   // sid => [display names]
        $codeFreq        = [];   // code => count
        $redFlagCases    = 0;
        $perPoe          = [];   // poe_code => total YES symptoms
        $now24h          = Carbon::now()->subDay();

        foreach ($symRows as $r) {
            $sid  = (int) $r->secondary_screening_id;
            $code = (string) $r->symptom_code;
            $meta = $symMeta[$code] ?? ['name' => $code, 'red_flag' => false];

            $symptomsByCase[$sid][] = $meta['name'];
            if ($meta['red_flag']) { $redFlagsByCase[$sid][] = $meta['name']; }

            $codeFreq[$code] = ($codeFreq[$code] ?? 0) + 1;
        }

        // Count distinct cases with ≥1 red-flag
        $redFlagCases = count($redFlagsByCase);

        // ── 6. Build table rows (one per case with at least one symptom) ───
        $rows = [];
        $kpi24h = 0;
        foreach ($sec as $s) {
            $sid = (int) $s->id;
            $symptoms = $symptomsByCase[$sid] ?? [];
            if (! $symptoms) { continue; }
            $redFlags = $redFlagsByCase[$sid] ?? [];
            if ($redOnly && ! $redFlags) { continue; }

            try {
                if ($s->opened_at && Carbon::parse((string) $s->opened_at)->greaterThanOrEqualTo($now24h)) { $kpi24h++; }
            } catch (\Throwable $e) { /* skip */ }

            $poeCode = (string) ($s->poe_code ?? '');
            if ($poeCode !== '') { $perPoe[$poeCode] = ($perPoe[$poeCode] ?? 0) + count($symptoms); }

            $row = [
                'id'              => $sid,
                'opened_at_iso'   => (string) $s->opened_at,
                'opened_at_label' => $this->humanDate((string) $s->opened_at),
                'traveller_name'  => $this->displayName($s),
                'age'             => $s->traveler_age_years !== null ? (int) $s->traveler_age_years : null,
                'sex'             => $s->traveler_gender,
                'nationality'     => $s->traveler_nationality_country_code,
                'symptoms'        => $symptoms,
                'red_flags'       => $redFlags,
                'red_flag_count'  => count($redFlags),
                'symptom_count'   => count($symptoms),
                'poe_name'        => $poeNames[$poeCode] ?? $poeCode,
                'case_file_url'   => isset($alertIdBySid[$sid])
                    ? url("/admin/alerts/{$alertIdBySid[$sid]}/case-file")
                    : url("/admin/reports/rpt-case-files/{$sid}"),
                'alert_id'        => $alertIdBySid[$sid] ?? null,
            ];

            // Free-text search
            if (! empty($filters['q'])) {
                $needle = strtolower((string) $filters['q']);
                $hay    = strtolower(implode(' ', array_filter([
                    $row['traveller_name'], $row['nationality'], $row['poe_name'],
                    ...$row['symptoms'], ...$row['red_flags'],
                ])));
                if (strpos($hay, $needle) === false) { continue; }
            }
            $rows[] = $row;
        }

        // Sort red-flag count desc, then by recency
        usort($rows, function ($a, $b) {
            $r = $b['red_flag_count'] <=> $a['red_flag_count'];
            if ($r !== 0) { return $r; }
            return strcmp((string) $b['opened_at_iso'], (string) $a['opened_at_iso']);
        });

        $tableVisible = array_slice($rows, 0, self::TABLE_LIMIT);

        // Median symptoms per case
        $countsArr = array_map(fn ($r) => (int) $r['symptom_count'], $rows);
        sort($countsArr);
        $median = $countsArr ? (count($countsArr) % 2
            ? $countsArr[(int) floor(count($countsArr) / 2)]
            : ($countsArr[(int) (count($countsArr) / 2) - 1] + $countsArr[(int) (count($countsArr) / 2)]) / 2) : 0;

        // Most common symptom display name
        $topCode = $codeFreq ? array_key_first(array_slice(
            (function () use ($codeFreq) { arsort($codeFreq); return $codeFreq; })(), 0, 1)
        ) : null;
        $topSymptom = $topCode ? ($symMeta[$topCode]['name'] ?? $topCode) : null;

        $kpis = [
            'cases_with_symptoms' => count($rows),
            'total_yes_symptoms'  => array_sum($codeFreq),
            'red_flag_cases'      => $redFlagCases,
            'top_symptom'         => $topSymptom,
            'median_symptoms'     => $median,
            'last_24h'            => $kpi24h,
        ];

        $chart = $this->pickChart($codeFreq, $symMeta, $redFlagCases, count($rows), $perPoe, $poeNames, $windowLabel);

        return [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
                         'days' => (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400) + 1,
                         'label' => $windowLabel],
            'kpis'       => $kpis,
            'chart'      => $chart,
            'table'      => $tableVisible,
            'table_full' => $rows,
            'total_rows' => count($rows),
            'shown_rows' => count($tableVisible),
            'meta' => [
                'poes' => $this->scope->allowedPoes($scope),
            ],
        ];
    }

    /**
     * Adaptive chart picker. Top reported symptoms first (most actionable);
     * red-flag bars get red so the eye snaps to them.
     */
    private function pickChart(array $codeFreq, array $symMeta, int $redFlagCases, int $caseCount, array $perPoe, array $poeNames, string $windowLabel): array
    {
        if ($codeFreq) {
            arsort($codeFreq);
            $labels = []; $values = []; $colors = []; $i = 0;
            foreach ($codeFreq as $code => $count) {
                if ($i >= self::CHART_TOP_N) { break; }
                $meta = $symMeta[$code] ?? ['name' => $code, 'red_flag' => false];
                $labels[] = $meta['name'];
                $values[] = (int) $count;
                $colors[] = $meta['red_flag'] ? '#C62828' : self::MATERIAL_PALETTE[$i % count(self::MATERIAL_PALETTE)];
                $i++;
            }
            return [
                'kind'     => 'symptoms',
                'title'    => sprintf('Top reported symptoms · %d %s', $caseCount, $caseCount === 1 ? 'case' : 'cases'),
                'subtitle' => 'How often each symptom was marked YES. Red bars are red-flag symptoms (immediate clinical action warranted).',
                'labels'   => $labels, 'values' => $values, 'colors' => $colors, 'unit' => 'reports',
            ];
        }

        if ($perPoe) {
            arsort($perPoe);
            $labels = []; $values = []; $i = 0;
            foreach ($perPoe as $code => $count) {
                if ($i >= self::CHART_TOP_N) { break; }
                $labels[] = $poeNames[$code] ?? $code;
                $values[] = (int) $count;
                $i++;
            }
            return [
                'kind'     => 'poe',
                'title'    => 'YES symptoms per point of entry',
                'subtitle' => 'Where the symptom reports came from.',
                'labels'   => $labels, 'values' => $values,
                'colors'   => array_map(fn ($_, $idx) => self::MATERIAL_PALETTE[$idx % count(self::MATERIAL_PALETTE)], $labels, array_keys($labels)),
                'unit'     => 'reports',
            ];
        }

        return [
            'kind' => 'empty',
            'title' => 'No symptoms recorded',
            'subtitle' => 'No YES symptoms in this window. Widen the date range or clear a filter.',
            'labels' => [], 'values' => [], 'colors' => [], 'unit' => 'reports',
        ];
    }

    private function displayName(?object $s): string
    {
        if (! $s) { return 'Unknown traveller'; }
        $full = trim((string) ($s->traveler_full_name ?? ''));
        if ($full !== '') { return $full; }
        $init = trim((string) ($s->traveler_initials ?? ''));
        if ($init !== '') { return $init; }
        $anon = trim((string) ($s->traveler_anonymous_code ?? ''));
        if ($anon !== '') { return $anon; }
        return 'Unknown traveller';
    }

    private function humanDate(string $iso): string
    {
        if ($iso === '') { return '—'; }
        try { return Carbon::parse($iso)->setTimezone(config('app.timezone','Africa/Kampala'))->format('M j, H:i'); }
        catch (\Throwable $e) { return $iso; }
    }
}
