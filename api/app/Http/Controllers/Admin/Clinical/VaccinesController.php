<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Clinical;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin · Clinical Library · Vaccines (clin-vaccines) — READ-ONLY.
 *
 * Reality (see reconciliation log): there is no first-class vaccine table
 * in the schema. The view derives its content from:
 *   (a) ref_engine_config rows whose key mentions a vaccine name;
 *   (b) aggregated_template_columns whose column_key encodes a vaccine
 *       stance (e.g. yellow_fever_vacc_valid).
 * When neither source has rows, the view says so plainly per brief §3
 * fallback rule.
 */
final class VaccinesController extends BaseClinicalController
{
    protected string $sectionKey = 'clin-vaccines';

    public function index(Request $request): View
    {
        $scope = $this->ensureAccess($request);
        return view('admin.clinical.vaccines.index', [
            'sectionKey' => $this->sectionKey,
            'pageTitle'  => 'Vaccines — Clinical Library',
            'scope'      => $scope,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->ensureAccess($request);
        $engineRows  = $this->registry->vaccineEngineRows();
        $columnsByVx = $this->registry->vaccineSubmissionColumns();

        // Vaccine inventory — derived from the union of engine-config keys
        // and column-key prefixes.
        $inventory = [];
        foreach ($columnsByVx as $vaccineKey => $cols) {
            $inventory[$vaccineKey] = [
                'vaccine_key'  => $vaccineKey,
                'display_name' => $this->vaccineDisplayName($vaccineKey),
                'source'       => 'aggregated_template_columns',
                'column_count' => count($cols),
                'columns'      => array_map(fn ($c) => [
                    'column_key'  => $c->column_key ?? '—',
                    'description' => $c->description ?? null,
                ], $cols),
                'engine_rows'  => 0,
            ];
        }
        foreach ($engineRows as $r) {
            $vk = $this->vaccineFromEngineKey((string) $r->config_key);
            $inventory[$vk] ??= [
                'vaccine_key'  => $vk,
                'display_name' => $this->vaccineDisplayName($vk),
                'source'       => 'ref_engine_config',
                'column_count' => 0,
                'columns'      => [],
                'engine_rows'  => 0,
            ];
            $inventory[$vk]['engine_rows']++;
        }
        $inventory = array_values($inventory);
        usort($inventory, fn ($a, $b) => strcmp($a['display_name'], $b['display_name']));

        // Disease links — a vaccine reduces the score for diseases whose
        // case_definition.payload.vaccines (or symptom/exposure key) names it.
        // v1: best-effort match by disease name appearing in the vaccine_key.
        // Documented as a heuristic; not authoritative.
        $diseaseLinks = $this->buildDiseaseLinks($inventory);

        return $this->ok([
            'inventory'        => $inventory,
            'disease_links'    => $diseaseLinks,
            'engine_rows'      => $engineRows->map(fn ($r) => [
                'config_key'  => $r->config_key,
                'description' => $r->description,
                'value'       => $r->config_value,
                'section'     => $r->section,
                'version'     => $r->version,
                'is_active'   => (bool) $r->is_active,
            ])->all(),
            'kpis' => [
                'vaccine_count'     => count($inventory),
                'engine_row_count'  => $engineRows->count(),
                'column_group_count'=> count($columnsByVx),
            ],
            'reality_note' => 'There is no first-class vaccine reference table in this schema. Inventory is the union of engine-config rows whose key mentions a vaccine and aggregated-template columns whose key encodes a vaccine stance. When neither source has rows, the inventory list is empty and the view says so plainly.',
        ], ['section_key' => $this->sectionKey]);
    }

    /* helpers */

    private function vaccineDisplayName(string $key): string
    {
        return match ($key) {
            'yellow_fever'         => 'Yellow Fever Vaccine',
            'polio'                => 'Polio Vaccine',
            'cholera'              => 'Cholera Vaccine',
            'meningitis'           => 'Meningitis Vaccine',
            'general_immunisation' => 'General immunisation',
            'other'                => 'Other (uncategorised)',
            default                => ucfirst(str_replace('_', ' ', $key)),
        };
    }

    private function vaccineFromEngineKey(string $key): string
    {
        $lc = strtolower($key);
        foreach (['yellow_fever' => 'yellow_fever',
                  'polio'        => 'polio',
                  'cholera'      => 'cholera',
                  'meningitis'   => 'meningitis',
                  'immuni'       => 'general_immunisation'] as $needle => $label) {
            if (str_contains($lc, $needle)) return $label;
        }
        return 'other';
    }

    private function buildDiseaseLinks(array $inventory): array
    {
        $out = [];
        foreach ($inventory as $vx) {
            $needle = $vx['vaccine_key'];
            // Heuristic match: disease_code containing the vaccine_key stem.
            foreach ($this->registry->diseases() as $d) {
                if (str_contains(strtolower((string) $d->disease_code), $needle)) {
                    $out[] = [
                        'vaccine_key'  => $needle,
                        'vaccine_name' => $vx['display_name'],
                        'disease_code' => $d->disease_code,
                        'disease_name' => $d->display_name,
                        'tier'         => $this->translator->tier((int) $d->ihr_tier),
                        'note'         => 'Heuristic match by disease name — confirm with clinical team.',
                    ];
                }
            }
        }
        return $out;
    }
}
