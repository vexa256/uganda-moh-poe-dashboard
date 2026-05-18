<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Clinical;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin · Clinical Library · Exposures (clin-exposures) — READ-ONLY.
 */
final class ExposuresController extends BaseClinicalController
{
    protected string $sectionKey = 'clin-exposures';

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.clinical.exposures.index', [
            'sectionKey' => $this->sectionKey,
            'pageTitle'  => 'Exposures — Clinical Library',
            'scope'      => $scope,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->ensureAccess($request);
        $exposures = $this->registry->exposures();
        $diseases  = $this->registry->diseases();
        $mappings  = $this->registry->exposureMappings();

        // exposure_code → engine_codes[]
        $engineByExposure = [];
        foreach ($mappings as $m) {
            $engineByExposure[(string) $m->exposure_code][] = [
                'engine_code' => $m->engine_code,
                'priority'    => (int) $m->priority,
            ];
        }

        // exposure_code → disease links (from disease.exposure_weights)
        $linkIndex = [];
        foreach ($diseases as $d) {
            if (! is_array($d->exposure_weights ?? null)) continue;
            foreach ($d->exposure_weights as $code => $w) {
                if (! is_numeric($w)) continue;
                $linkIndex[(string) $code][] = [
                    'disease_code' => $d->disease_code,
                    'display_name' => $d->display_name,
                    'weight'       => (float) $w,
                ];
            }
        }

        $rows = $exposures->map(function (object $e) use ($engineByExposure, $linkIndex) {
            $links = $linkIndex[(string) $e->exposure_code] ?? [];
            usort($links, fn ($a, $b) => abs($b['weight']) <=> abs($a['weight']));
            $strongest = $links[0] ?? null;
            return [
                'id'              => (int) $e->id,
                'exposure_code'   => $e->exposure_code,
                'display_name'    => $e->display_name,
                'category'        => $e->category,
                'prompt_text'     => $e->prompt_text,
                'response_type'   => $e->response_type,
                'response_label'  => $this->translator->responseType($e->response_type),
                'is_high_risk'    => (bool) $e->is_high_risk,
                'is_active'       => (bool) $e->is_active,
                'engine_codes'    => $engineByExposure[(string) $e->exposure_code] ?? [],
                'engine_code_count' => count($engineByExposure[(string) $e->exposure_code] ?? []),
                'disease_count'   => count($links),
                'strongest_link'  => $strongest,
                'updated_at'      => optional($e->updated_at)->__toString() ?? $e->updated_at,
            ];
        })->values();

        $kpis = [
            'total'           => $rows->count(),
            'active'          => $rows->where('is_active', true)->count(),
            'high_risk'       => $rows->where('is_high_risk', true)->count(),
            'distinct_engine_codes' => $mappings->pluck('engine_code')->unique()->count(),
            'response_types'  => $this->groupByCount($rows, fn ($r) => $r['response_label']),
            'categories'      => $this->groupByCount($rows, fn ($r) => $r['category'] ?? '— uncategorised'),
        ];

        // Engine-code hit parade — how many exposures map to each engine code.
        $engineHits = [];
        foreach ($mappings as $m) {
            $engineHits[(string) $m->engine_code] = ($engineHits[(string) $m->engine_code] ?? 0) + 1;
        }
        arsort($engineHits);

        return $this->ok([
            'rows'        => $rows->all(),
            'kpis'        => $kpis,
            'engine_hits' => $engineHits,
            'mappings'    => $mappings->map(fn ($m) => [
                'exposure_code' => $m->exposure_code,
                'engine_code'   => $m->engine_code,
                'priority'      => (int) $m->priority,
            ])->all(),
            'categories_in_use' => $this->registry->exposureCategoriesInUse(),
        ], ['section_key' => $this->sectionKey]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $this->ensureAccess($request);
        $e = $this->registry->exposure($id);
        if (! $e) return response()->json(['success' => false, 'error' => 'Exposure not found'], 404);

        $links = [];
        foreach ($this->registry->diseases() as $d) {
            $w = $d->exposure_weights[(string) $e->exposure_code] ?? null;
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

        $engine = $this->registry->exposureMappings()
            ->where('exposure_code', $e->exposure_code)
            ->map(fn ($m) => ['engine_code' => $m->engine_code, 'priority' => (int) $m->priority])
            ->values()->all();

        return $this->ok([
            'id'              => (int) $e->id,
            'exposure_code'   => $e->exposure_code,
            'display_name'    => $e->display_name,
            'category'        => $e->category,
            'prompt_text'     => $e->prompt_text,
            'response_type'   => $e->response_type,
            'response_label'  => $this->translator->responseType($e->response_type),
            'is_high_risk'    => (bool) $e->is_high_risk,
            'is_active'       => (bool) $e->is_active,
            'engine_codes'    => $engine,
            'linked_diseases' => $links,
        ]);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        return $this->readOnly($request);
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
