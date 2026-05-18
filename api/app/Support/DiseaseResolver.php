<?php

declare(strict_types=1);

namespace App\Support;

/**
 * DiseaseResolver
 * ---------------------------------------------------------------------------
 * View-layer convenience wrapper over DiseaseIntel for Blade/controller use.
 * Where DiseaseIntel exposes raw clinical data, DiseaseResolver shapes it
 * into view-ready bundles (label, tone, definition, hallmarks, immediate
 * actions) without every caller repeating the same field stitching.
 *
 * DiseaseIntel is still the authoritative source; this class adds the
 * presentation layer (tone tokens, plain-English translation, fallback).
 */
final class DiseaseResolver
{
    public function __construct(protected EnumTranslator $enum)
    {
    }

    /**
     * Fully resolved view-model for a disease_code.
     *
     * @return array{
     *     code:string,
     *     name:string,
     *     tier_code:?string,
     *     tier_label:string,
     *     tier_tone:string,
     *     category:?string,
     *     cfr_pct:?float,
     *     incubation:?string,
     *     transmission:?string,
     *     ppe:?string,
     *     isolation:?string,
     *     ihr_notification:?string,
     *     immediate_actions:array<int,string>,
     *     recommended_tests:array<int,string>,
     *     specimens:array<int,string>,
     *     case_definition:?string,
     *     key_distinguishers:array<int,string>,
     *     differential:array<int,string>,
     *     known:bool,
     * }
     */
    public function resolve(?string $code): array
    {
        $code = strtoupper((string) $code);
        $known = $code !== '' && isset(DiseaseIntel::REGISTRY[strtolower($code)]);
        $intel = $code === '' ? null : DiseaseIntel::get($code);

        if (! $intel) {
            return [
                'code'              => $code,
                'name'              => $code ? $this->enum->humanize($code) : 'Unknown',
                'tier_code'         => null,
                'tier_label'        => 'Not assessed',
                'tier_tone'         => 'default',
                'category'          => null,
                'cfr_pct'           => null,
                'incubation'        => null,
                'transmission'      => null,
                'ppe'               => null,
                'isolation'         => null,
                'ihr_notification'  => null,
                'immediate_actions' => [],
                'recommended_tests' => [],
                'specimens'         => [],
                'case_definition'   => null,
                'key_distinguishers'=> [],
                'differential'      => [],
                'known'             => false,
            ];
        }

        $tier = $intel['ihr_tier'] ?? null;

        return [
            'code'              => $code,
            'name'              => $intel['name'] ?? $this->enum->humanize($code),
            'tier_code'         => $tier,
            'tier_label'        => $this->enum->ihrTier($tier),
            'tier_tone'         => $this->enum->ihrTone($tier),
            'category'          => $intel['who_category'] ?? null,
            'cfr_pct'           => isset($intel['cfr_pct']) ? (float) $intel['cfr_pct'] : null,
            'incubation'        => $intel['incubation'] ?? null,
            'transmission'      => $intel['transmission'] ?? null,
            'ppe'               => $intel['ppe'] ?? null,
            'isolation'         => $intel['isolation'] ?? null,
            'ihr_notification'  => $intel['ihr_notification'] ?? null,
            'immediate_actions' => $intel['immediate_actions'] ?? [],
            'recommended_tests' => $intel['recommended_tests'] ?? [],
            'specimens'         => $intel['specimens'] ?? [],
            'case_definition'   => $intel['case_definition'] ?? null,
            'key_distinguishers'=> $intel['key_distinguishers'] ?? [],
            'differential'      => $intel['differential'] ?? [],
            'known'             => $known,
        ];
    }

    /**
     * Rank a list of suspected diseases by confidence score and resolve each
     * to a view-model. Used by the Case File "Differential" tab.
     *
     * Input rows from `secondary_suspected_diseases`:
     *   [ 'disease_code', 'confidence', 'notes', 'officer_overridden' ]
     *
     * @param array<int,array> $rows
     * @return array<int,array>
     */
    public function rankSuspected(array $rows): array
    {
        $items = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $view = $this->resolve($r['disease_code'] ?? null);
            $view['confidence']        = isset($r['confidence']) ? (float) $r['confidence'] : null;
            $view['officer_overridden']= (bool) ($r['officer_overridden'] ?? false);
            $view['notes']             = $r['notes'] ?? null;
            $items[] = $view;
        }
        usort($items, fn ($a, $b) => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));
        return $items;
    }
}
