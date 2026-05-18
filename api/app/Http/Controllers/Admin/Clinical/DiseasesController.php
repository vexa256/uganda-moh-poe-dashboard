<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Clinical;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin · Clinical Library · Diseases (clin-diseases)
 *
 * READ-ONLY reference console — Paranoid v2 brief §2 and §9.1.
 *
 * Replaces the previous edit-affordance browser. Nothing in this rebuild
 * mutates ref_diseases; the legacy toggle endpoint is preserved at the
 * route level but returns 403 with a clear "this section is read-only"
 * message, so the URL surface is honest about its semantics.
 *
 * Mobile contract: untouched. The mobile API does not consume any of these
 * admin endpoints — the mobile downloads ref_* tables via the version-
 * snapshot mechanism owned by the scoring engine team.
 */
final class DiseasesController extends BaseClinicalController
{
    protected string $sectionKey = 'clin-diseases';

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.clinical.diseases.index', [
            'sectionKey' => $this->sectionKey,
            'pageTitle'  => 'Diseases — Clinical Library',
            'scope'      => $scope,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $scope    = $this->ensureAccess($request);
        $diseases = $this->registry->diseases();

        $rows = $diseases->map(function (object $d) {
            return [
                'id'                  => (int) $d->id,
                'disease_code'        => $d->disease_code,
                'display_name'        => $d->display_name,
                'tier'                => $this->translator->tier((int) $d->ihr_tier),
                'tier_int'            => (int) $d->ihr_tier,
                'tier_badge_class'    => $this->translator->tierBadgeClass((int) $d->ihr_tier),
                'syndrome'            => $this->translator->syndrome($d->who_syndrome),
                'incubation_min'      => $d->incubation_days_min,
                'incubation_max'      => $d->incubation_days_max,
                'is_active'           => (bool) $d->is_active,
                'hallmark_required'   => $this->hallmarkSummary($d),
                'top_symptoms'        => $this->topWeights($d->symptom_weights ?? [], fn (string $c) => $this->translator->symptomName($c), 5),
                'top_exposures'       => $this->topWeights($d->exposure_weights ?? [], fn (string $c) => $this->translator->exposureName($c), 5),
                'endemic_country_count' => $this->registry->endemicMappingsForDisease((string) $d->disease_code)->count(),
                'score_cap_estimated' => $this->simulator->maxAttainableScore($d),
                'updated_at'          => optional($d->updated_at)->__toString() ?? $d->updated_at,
            ];
        })->values();

        // KPIs — discovered live, never hard-coded.
        $kpis = [
            'total'             => $rows->count(),
            'active'            => $rows->where('is_active', true)->count(),
            'tiers'             => $this->groupByCount($rows, fn ($r) => 'Tier ' . $r['tier_int']),
            'syndromes'         => $this->groupByCount($rows, fn ($r) => $r['syndrome']['label']),
            'with_hallmark'     => $rows->filter(fn ($r) => ! empty($r['hallmark_required']['required']))->count(),
            'max_score_cap'     => (int) ($rows->max('score_cap_estimated') ?? 0),
            'min_score_cap'     => (int) ($rows->min('score_cap_estimated') ?? 0),
        ];

        // Action-band distribution from estimated cap — useful for the
        // "by action band" tab.
        $byActionBand = ['HIGH' => 0, 'MEDIUM' => 0, 'LOW' => 0, 'NONE' => 0];
        foreach ($rows as $r) {
            $band = $this->simulator->actionBand((int) $r['score_cap_estimated'])['key'];
            $byActionBand[$band]++;
        }

        // Recently updated — diseases sorted by updated_at desc, top 10.
        $recent = $rows
            ->filter(fn ($r) => ! empty($r['updated_at']))
            ->sortByDesc('updated_at')
            ->take(10)
            ->values();

        return $this->ok([
            'rows'           => $rows->all(),
            'kpis'           => $kpis,
            'by_action_band' => $byActionBand,
            'recent'         => $recent->all(),
            'tiers_in_use'   => $this->registry->tiersInUse(),
            'syndromes_in_use' => $this->registry->whoSyndromesInUse(),
        ], [
            'section_key' => $this->sectionKey,
            'simulator_version' => \App\Services\Clinical\ClinicalScoringSimulator::VERSION,
            'translator_version' => \App\Services\Clinical\ClinicalTranslator::VERSION,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->ensureAccess($request);
        $disease = $this->registry->disease($id);
        if (! $disease) return response()->json(['success' => false, 'error' => 'Disease not found'], 404);

        $endemic = $this->registry->endemicMappingsForDisease((string) $disease->disease_code)
            ->map(fn (object $e) => [
                'country_code' => $e->country_code,
                'country_name' => $e->country_name ?: $this->translator->countryName($e->country_code),
                'level'        => $this->translator->endemicity($e->endemicity_level),
                'since_year'   => $e->since_year,
                'source'       => $e->source,
            ])->values()->all();

        $payload = [
            'id'                => (int) $disease->id,
            'disease_code'      => $disease->disease_code,
            'display_name'      => $disease->display_name,
            'tier'              => $this->translator->tier((int) $disease->ihr_tier),
            'syndrome'          => $this->translator->syndrome($disease->who_syndrome),
            'incubation_min'    => $disease->incubation_days_min,
            'incubation_max'    => $disease->incubation_days_max,
            'is_active'         => (bool) $disease->is_active,
            'hallmark'          => $this->hallmarkSummary($disease),
            'symptom_weights'   => $this->translatedWeights($disease->symptom_weights ?? [], fn (string $c) => $this->translator->symptomName($c)),
            'exposure_weights'  => $this->translatedWeights($disease->exposure_weights ?? [], fn (string $c) => $this->translator->exposureName($c)),
            'absent_penalties'  => $this->translatedWeights($disease->absent_penalties ?? [], fn (string $c) => $this->translator->symptomName($c)),
            'gates'             => $disease->gates,
            'case_definition'   => $disease->case_definition,
            'sources'           => $disease->sources,
            'endemic_countries' => $endemic,
            'score_cap_estimated' => $this->simulator->maxAttainableScore($disease),
        ];

        return $this->ok($payload);
    }

    /**
     * Worked-example endpoint — runs the simulator against the disease and
     * returns a deterministic dry-run payload. Honest framing: the result
     * carries `is_simulation: true, engine_called: false` per simulator.
     */
    public function workedExample(Request $request, int $id): JsonResponse
    {
        $this->ensureAccess($request);
        $disease = $this->registry->disease($id);
        if (! $disease) return response()->json(['success' => false, 'error' => 'Disease not found'], 404);

        $present = (array) $request->query('present', []);
        $absent  = (array) $request->query('absent',  []);
        $exp     = (array) $request->query('exposures', []);
        $country = $request->query('arrival_country');

        $result = $this->simulator->simulate(
            (string) $disease->disease_code,
            array_filter(array_map('strval', $present)),
            array_filter(array_map('strval', $absent)),
            array_filter(array_map('strval', $exp)),
            $country ? (string) $country : null,
        );
        return $this->ok($result);
    }

    /** Legacy mutation endpoint preserved at route level — returns 403. */
    public function toggle(Request $request, int $id): JsonResponse
    {
        return $this->readOnly($request);
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * @param  array<string,float|int>  $weights
     * @param  callable(string):string  $nameFor
     * @return array<int,array{code:string,display_name:string,weight:float,strength:array}>
     */
    private function topWeights(array $weights, callable $nameFor, int $limit): array
    {
        $arr = [];
        foreach ($weights as $code => $w) {
            if (! is_numeric($w)) continue;
            $arr[] = [
                'code'         => (string) $code,
                'display_name' => $nameFor((string) $code),
                'weight'       => (float) $w,
                'strength'     => $this->translator->weightStrength((float) $w),
            ];
        }
        usort($arr, fn ($a, $b) => $b['weight'] <=> $a['weight']);
        return array_slice($arr, 0, $limit);
    }

    private function translatedWeights(array $weights, callable $nameFor): array
    {
        $arr = [];
        foreach ($weights as $code => $w) {
            if (! is_numeric($w)) continue;
            $arr[] = [
                'code'         => (string) $code,
                'display_name' => $nameFor((string) $code),
                'weight'       => (float) $w,
                'strength'     => $this->translator->weightStrength((float) $w),
            ];
        }
        usort($arr, fn ($a, $b) => abs($b['weight']) <=> abs($a['weight']));
        return $arr;
    }

    private function hallmarkSummary(object $disease): array
    {
        $gates = is_array($disease->gates) ? $disease->gates : [];
        $required = array_values(array_unique(array_merge(
            (array) ($gates['required_all'] ?? []),
            (array) ($gates['required_any'] ?? []),
        )));
        $hardFail = (array) ($gates['hard_fail_if_absent'] ?? []);
        return [
            'required'         => array_map(fn (string $c) => [
                'code' => $c, 'display_name' => $this->translator->symptomName($c),
            ], $required),
            'hard_fail_if_absent' => array_map(fn (string $c) => [
                'code' => $c, 'display_name' => $this->translator->symptomName($c),
            ], array_values($hardFail)),
            'plain' => empty($required)
                ? 'No hallmark requirement — scoring is purely additive.'
                : 'A hallmark signal is required for this disease to be flagged.',
        ];
    }

    private function groupByCount(\Illuminate\Support\Collection $rows, callable $keyFor): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = $keyFor($r);
            $out[$k] = ($out[$k] ?? 0) + 1;
        }
        ksort($out);
        return $out;
    }
}
