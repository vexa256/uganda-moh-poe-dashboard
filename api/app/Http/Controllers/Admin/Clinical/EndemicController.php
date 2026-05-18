<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Clinical;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin · Clinical Library · Endemic Map (clin-endemic) — READ-ONLY.
 */
final class EndemicController extends BaseClinicalController
{
    protected string $sectionKey = 'clin-endemic';

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.clinical.endemic.index', [
            'sectionKey' => $this->sectionKey,
            'pageTitle'  => 'Endemic Map — Clinical Library',
            'scope'      => $scope,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->ensureAccess($request);
        $rows = $this->registry->endemicMappings();

        $formatted = $rows->map(function (object $m) {
            $level = $this->translator->endemicity($m->endemicity_level);
            return [
                'id'            => (int) $m->id,
                'disease_code'  => $m->disease_code,
                'disease_name'  => $m->disease_display_name ?: $this->translator->diseaseName($m->disease_code),
                'tier'          => $this->translator->tier($m->disease_ihr_tier !== null ? (int) $m->disease_ihr_tier : null),
                'country_code'  => $m->country_code,
                'country_name'  => $m->country_name ?: $this->translator->countryName($m->country_code),
                'level'         => $level,
                'level_code'    => $m->endemicity_level,
                'since_year'    => $m->since_year,
                'source'        => $m->source,
                'last_verified' => $m->last_verified_at,
                'is_active'     => (bool) $m->is_active,
                'updated_at'    => optional($m->updated_at)->__toString() ?? $m->updated_at,
            ];
        })->values();

        $byLevel = [];
        foreach ($formatted as $r) {
            $byLevel[$r['level_code']] = ($byLevel[$r['level_code']] ?? 0) + 1;
        }

        // Per-disease counts for "outbreak pressure" chart.
        $byDisease = [];
        foreach ($formatted as $r) {
            $key = $r['disease_code'];
            $byDisease[$key] ??= [
                'disease_code' => $key,
                'disease_name' => $r['disease_name'],
                'tier'         => $r['tier'],
                'active'       => 0, 'recent' => 0, 'endemic' => 0, 'sporadic' => 0, 'imported' => 0,
            ];
            switch ($r['level_code']) {
                case 'OUTBREAK_ACTIVE': $byDisease[$key]['active']++; break;
                case 'OUTBREAK_RECENT': $byDisease[$key]['recent']++; break;
                case 'ENDEMIC':         $byDisease[$key]['endemic']++; break;
                case 'SPORADIC':        $byDisease[$key]['sporadic']++; break;
                case 'IMPORTED_ONLY':   $byDisease[$key]['imported']++; break;
            }
        }
        $byDisease = array_values($byDisease);
        usort($byDisease, fn ($a, $b) => ($b['active'] + $b['recent']) <=> ($a['active'] + $a['recent']));

        // Per-country counts.
        $byCountry = [];
        foreach ($formatted as $r) {
            $key = $r['country_code'];
            $byCountry[$key] ??= [
                'country_code' => $key,
                'country_name' => $r['country_name'],
                'count'        => 0,
                'active'       => 0,
            ];
            $byCountry[$key]['count']++;
            if ($r['level_code'] === 'OUTBREAK_ACTIVE') $byCountry[$key]['active']++;
        }
        $byCountry = array_values($byCountry);
        usort($byCountry, fn ($a, $b) => $b['count'] <=> $a['count']);
        $byCountry = array_slice($byCountry, 0, 50);

        $activeOutbreaks = $formatted->where('level_code', 'OUTBREAK_ACTIVE')->values()->all();

        // Recently updated mappings.
        $recent = $formatted
            ->filter(fn ($r) => ! empty($r['updated_at']))
            ->sortByDesc('updated_at')
            ->take(20)
            ->values()->all();

        return $this->ok([
            'rows'              => $formatted->all(),
            'kpis' => [
                'total'           => $formatted->count(),
                'active_outbreaks'=> count($activeOutbreaks),
                'distinct_diseases'  => $formatted->pluck('disease_code')->unique()->count(),
                'distinct_countries' => $formatted->pluck('country_code')->unique()->count(),
                'levels'             => $byLevel,
            ],
            'by_disease'        => $byDisease,
            'by_country'        => $byCountry,
            'active_outbreaks'  => $activeOutbreaks,
            'recent'            => $recent,
            'levels_in_use'     => $this->registry->endemicLevelsInUse(),
        ], ['section_key' => $this->sectionKey]);
    }

    public function updateLevel(Request $request, int $id): JsonResponse
    {
        return $this->readOnly($request);
    }
}
