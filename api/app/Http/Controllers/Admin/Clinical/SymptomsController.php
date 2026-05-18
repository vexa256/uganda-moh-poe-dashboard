<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Clinical;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin · Clinical Library · Symptoms (clin-symptoms) — READ-ONLY.
 */
final class SymptomsController extends BaseClinicalController
{
    protected string $sectionKey = 'clin-symptoms';

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.clinical.symptoms.index', [
            'sectionKey' => $this->sectionKey,
            'pageTitle'  => 'Symptoms — Clinical Library',
            'scope'      => $scope,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->ensureAccess($request);
        $symptoms = $this->registry->symptoms();
        $diseases = $this->registry->diseases();

        $diseaseLinkIndex = $this->buildSymptomToDiseaseIndex($diseases);

        $rows = $symptoms->map(function (object $s) use ($diseaseLinkIndex) {
            $links     = $diseaseLinkIndex[(string) $s->symptom_code] ?? [];
            $strongest = empty($links) ? null : collect($links)->sortByDesc('weight')->first();
            return [
                'id'             => (int) $s->id,
                'symptom_code'   => $s->symptom_code,
                'display_name'   => $s->display_name,
                'category'       => $s->category,
                'syndrome_tags'  => $this->normalizeSyndromeTags($s->syndrome_tags ?? []),
                'sensitivity'    => $this->translator->sensitivity($s->sensitivity !== null ? (float) $s->sensitivity : null),
                'is_red_flag'    => (bool) $s->is_red_flag,
                'is_hallmark'    => (bool) $s->is_hallmark,
                'is_active'      => (bool) $s->is_active,
                'disease_count'  => count($links),
                'strongest_link' => $strongest,
                'updated_at'     => optional($s->updated_at)->__toString() ?? $s->updated_at,
            ];
        })->values();

        $kpis = [
            'total'      => $rows->count(),
            'active'     => $rows->where('is_active', true)->count(),
            'red_flag'   => $rows->where('is_red_flag', true)->count(),
            'hallmark'   => $rows->where('is_hallmark', true)->count(),
            'categories' => $this->groupByCount($rows, fn ($r) => $r['category'] ?? '— uncategorised'),
        ];

        // Syndrome-tag distribution.
        $syndromeAcc = [];
        foreach ($rows as $r) {
            foreach ($r['syndrome_tags'] as $t) {
                $syndromeAcc[$t['label']] = ($syndromeAcc[$t['label']] ?? 0) + 1;
            }
        }
        ksort($syndromeAcc);
        $kpis['syndrome_tags'] = $syndromeAcc;

        return $this->ok([
            'rows'                 => $rows->all(),
            'kpis'                 => $kpis,
            'categories_in_use'    => $this->registry->symptomCategoriesInUse(),
            'syndrome_tags_in_use' => $this->registry->syndromeTagsInUse(),
        ], ['section_key' => $this->sectionKey]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->ensureAccess($request);
        $sym = $this->registry->symptom($id);
        if (! $sym) return response()->json(['success' => false, 'error' => 'Symptom not found'], 404);

        $links = [];
        foreach ($this->registry->diseases() as $d) {
            $w = ($d->symptom_weights[(string) $sym->symptom_code] ?? null);
            if ($w === null || ! is_numeric($w)) continue;
            $links[] = [
                'disease_code' => $d->disease_code,
                'display_name' => $d->display_name,
                'tier'         => $this->translator->tier((int) $d->ihr_tier),
                'weight'       => (float) $w,
                'strength'     => $this->translator->weightStrength((float) $w),
            ];
        }
        usort($links, fn ($a, $b) => abs($b['weight']) <=> abs($a['weight']));

        return $this->ok([
            'id'              => (int) $sym->id,
            'symptom_code'    => $sym->symptom_code,
            'display_name'    => $sym->display_name,
            'category'        => $sym->category,
            'syndrome_tags'   => $this->normalizeSyndromeTags($sym->syndrome_tags ?? []),
            'sensitivity'     => $this->translator->sensitivity($sym->sensitivity !== null ? (float) $sym->sensitivity : null),
            'is_red_flag'     => (bool) $sym->is_red_flag,
            'is_hallmark'     => (bool) $sym->is_hallmark,
            'is_active'       => (bool) $sym->is_active,
            'linked_diseases' => $links,
        ]);
    }

    /** Symptom combinations — pick 2-3 codes, return combined disease list. */
    public function combinations(Request $request): JsonResponse
    {
        $this->ensureAccess($request);
        $codes = array_filter(array_map('strval', (array) $request->query('codes', [])));
        if (empty($codes)) {
            return $this->ok(['codes' => [], 'matches' => []]);
        }
        $matches = [];
        foreach ($this->registry->diseases() as $d) {
            $sw = is_array($d->symptom_weights) ? $d->symptom_weights : [];
            $hits = [];
            $total = 0;
            foreach ($codes as $code) {
                if (! isset($sw[$code])) continue;
                $hits[] = ['code' => $code, 'weight' => (float) $sw[$code]];
                $total += (float) $sw[$code];
            }
            if (count($hits) === count($codes) && $total > 0) {
                $matches[] = [
                    'disease_code' => $d->disease_code,
                    'display_name' => $d->display_name,
                    'tier'         => $this->translator->tier((int) $d->ihr_tier),
                    'combined_weight' => $total,
                    'strength'     => $this->translator->weightStrength($total),
                ];
            }
        }
        usort($matches, fn ($a, $b) => $b['combined_weight'] <=> $a['combined_weight']);
        return $this->ok(['codes' => $codes, 'matches' => $matches]);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        return $this->readOnly($request);
    }

    /* helpers */

    private function buildSymptomToDiseaseIndex(\Illuminate\Support\Collection $diseases): array
    {
        $idx = [];
        foreach ($diseases as $d) {
            if (! is_array($d->symptom_weights ?? null)) continue;
            foreach ($d->symptom_weights as $code => $w) {
                if (! is_numeric($w)) continue;
                $idx[(string) $code][] = [
                    'disease_code' => $d->disease_code,
                    'display_name' => $d->display_name,
                    'weight'       => (float) $w,
                ];
            }
        }
        return $idx;
    }

    private function normalizeSyndromeTags(mixed $raw): array
    {
        if (! is_array($raw)) return [];
        $out = [];
        foreach ($raw as $entry) {
            $tag = is_array($entry) ? ($entry['tag'] ?? null) : (string) $entry;
            if (! $tag) continue;
            $out[] = [
                'code'  => $tag,
                'label' => $this->translator->syndrome($tag)['label'],
            ];
        }
        return $out;
    }

    private function groupByCount(\Illuminate\Support\Collection $rows, callable $keyFor): array
    {
        $out = [];
        foreach ($rows as $r) {
            $k = $keyFor($r) ?? '—';
            $out[$k] = ($out[$k] ?? 0) + 1;
        }
        ksort($out);
        return $out;
    }
}
