<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Clinical;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin · Clinical Library · Scoring Rules (clin-boosts) — READ-ONLY.
 */
final class BoostsController extends BaseClinicalController
{
    protected string $sectionKey = 'clin-boosts';

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.clinical.boosts.index', [
            'sectionKey' => $this->sectionKey,
            'pageTitle'  => 'Scoring Rules — Clinical Library',
            'scope'      => $scope,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->ensureAccess($request);
        $rows     = $this->registry->engineConfigRows();
        $diseases = $this->registry->diseases();

        // Build per-disease boost map by inspecting every engine_config value.
        $byDisease   = [];
        $boostList   = [];
        $shape       = [];
        foreach ($rows as $r) {
            $val   = $r->config_value;
            $entry = [
                'id'          => (int) $r->id,
                'config_key'  => $r->config_key,
                'section'     => $r->section,
                'description' => $r->description,
                'version'     => $r->version,
                'is_active'   => (bool) $r->is_active,
                'value'       => $val,
            ];
            $entry['shape'] = $this->detectShape($val);
            $shape[$entry['shape']] = ($shape[$entry['shape']] ?? 0) + 1;

            if ($entry['shape'] === 'disease_boost_map' && is_array($val)) {
                foreach ($val as $diseaseCode => $boost) {
                    if (! is_numeric($boost)) continue;
                    $boostList[] = [
                        'disease_code' => (string) $diseaseCode,
                        'disease_name' => $this->translator->diseaseName((string) $diseaseCode),
                        'boost'        => (float) $boost,
                        'strength'     => $this->translator->weightStrength((float) $boost),
                        'config_key'   => $r->config_key,
                        'section'      => $r->section,
                    ];
                    $byDisease[(string) $diseaseCode][] = [
                        'boost'      => (float) $boost,
                        'config_key' => $r->config_key,
                        'section'    => $r->section,
                    ];
                }
            }
            $entry['plain_summary'] = $this->plainSummaryFor($r->config_key, $val);
            $entry['preview']       = $this->preview($val);
            $entry['rows'][]        = null; // placeholder — real per-row drill rendered client-side
        }

        usort($boostList, fn ($a, $b) => $b['boost'] <=> $a['boost']);

        // Per-disease score caps for the score-cap reference tab.
        $scoreCaps = $diseases->map(function (object $d) {
            $cap = $this->simulator->maxAttainableScore($d);
            return [
                'disease_code' => $d->disease_code,
                'display_name' => $d->display_name,
                'tier'         => $this->translator->tier((int) $d->ihr_tier),
                'cap'          => $cap,
                'action_band'  => $this->simulator->actionBand($cap),
            ];
        })->sortByDesc('cap')->values()->all();

        return $this->ok([
            'rows'        => $rows->map(function (object $r) {
                $val = $r->config_value;
                return [
                    'id'           => (int) $r->id,
                    'config_key'   => $r->config_key,
                    'section'      => $r->section,
                    'description'  => $r->description,
                    'version'      => $r->version,
                    'is_active'    => (bool) $r->is_active,
                    'shape'        => $this->detectShape($val),
                    'preview'      => $this->preview($val),
                    'plain_summary'=> $this->plainSummaryFor($r->config_key, $val),
                ];
            })->all(),
            'top_boosts'  => array_slice($boostList, 0, 20),
            'by_disease'  => $byDisease,
            'shapes'      => $shape,
            'score_caps'  => $scoreCaps,
            'sections_in_use' => $this->registry->engineSectionsInUse(),
            'kpis' => [
                'total'            => $rows->count(),
                'active'           => $rows->where('is_active', true)->count(),
                'distinct_disease_boosts' => count($byDisease),
                'largest_boost'    => empty($boostList) ? 0 : max(array_column($boostList, 'boost')),
            ],
        ], ['section_key' => $this->sectionKey]);
    }

    public function toggle(Request $request, int $id): JsonResponse
    {
        return $this->readOnly($request);
    }

    /* helpers */

    private function detectShape(mixed $val): string
    {
        if ($val === null) return 'null';
        if (is_array($val)) {
            if (empty($val)) return 'empty';
            $allNumericValues = true;
            foreach ($val as $k => $v) {
                if (! is_numeric($v)) { $allNumericValues = false; break; }
            }
            if ($allNumericValues) return 'disease_boost_map';
            return 'object';
        }
        if (is_numeric($val)) return 'scalar';
        return 'string';
    }

    private function preview(mixed $val): string
    {
        if ($val === null) return '(empty)';
        if (is_scalar($val)) return (string) $val;
        if (! is_array($val)) return '(complex)';
        $bits = [];
        $count = 0;
        foreach ($val as $k => $v) {
            if (is_array($v)) {
                $bits[] = "{$k}: …";
            } else {
                $sign = is_numeric($v) && $v > 0 ? '+' : '';
                $bits[] = is_numeric($v) ? "{$k} {$sign}{$v}" : "{$k}: {$v}";
            }
            if (++$count >= 6) break;
        }
        $more = count($val) > 6 ? ' · …' : '';
        return implode(' · ', $bits) . $more;
    }

    private function plainSummaryFor(string $key, mixed $val): string
    {
        $shape = $this->detectShape($val);
        return match ($shape) {
            'disease_boost_map' => 'Adds a baseline boost to every listed disease — high-priority pathogens get a head-start in the scoring formula.',
            'object'            => 'A structured tunable used by the engine — see "Show technical detail" for the shape.',
            'scalar'            => 'A single global tunable that applies across the engine.',
            'string'            => 'A configuration label or selector consumed by the engine.',
            'empty'             => 'No active configuration in this row.',
            default             => 'Configuration not yet classified.',
        };
    }
}
