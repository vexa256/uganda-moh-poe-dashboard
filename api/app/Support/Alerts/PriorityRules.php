<?php

declare(strict_types=1);

namespace App\Support\Alerts;

use App\Support\DiseaseIntel;

/**
 * Hard-coded clinical priority rules for the resolution wizard.
 *
 * No LLM. No remote calls. The wizard's reasoning lives entirely in this
 * class plus the live alert/followups state. Every change here is an
 * editable constant — clinicians can be walked through one screen.
 *
 * v1 — clinician sign-off pending. Update when the canonical disease-to-
 * action priority table is approved. The shape is intentionally stable so
 * future edits are pure data.
 */
final class PriorityRules
{
    // Disease groups — buckets shared with HumanLabels::DISEASE_GROUP for
    // both labelling and routing.
    public const GROUP_VHF                = 'vhf';
    public const GROUP_CHOLERA_DIARRHOEAL = 'cholera_diarrhoeal';
    public const GROUP_NOVEL_RESPIRATORY  = 'novel_respiratory';
    public const GROUP_MEASLES_FAMILY     = 'measles_family';
    public const GROUP_VECTOR_BORNE       = 'vector_borne';
    public const GROUP_MENINGITIS         = 'meningitis';
    public const GROUP_PLAGUE             = 'plague';
    public const GROUP_ZOONOTIC           = 'zoonotic';
    public const GROUP_FOODBORNE          = 'foodborne';
    public const GROUP_SEASONAL_FLU       = 'seasonal_flu';
    public const GROUP_SYNDROMIC_UNKNOWN  = 'syndromic_unknown';

    /**
     * Disease code → group bucket.
     */
    public const DISEASE_GROUP = [
        'ebola_virus_disease'              => self::GROUP_VHF,
        'marburg_virus_disease'            => self::GROUP_VHF,
        'lassa_fever'                      => self::GROUP_VHF,
        'cchf'                             => self::GROUP_VHF,
        'rift_valley_fever'                => self::GROUP_VHF,
        'hantavirus'                       => self::GROUP_VHF,
        'nipah_virus'                      => self::GROUP_VHF,

        'cholera'                          => self::GROUP_CHOLERA_DIARRHOEAL,
        'awd_non_cholera'                  => self::GROUP_CHOLERA_DIARRHOEAL,
        'shigellosis_dysentery'            => self::GROUP_CHOLERA_DIARRHOEAL,

        'sars'                             => self::GROUP_NOVEL_RESPIRATORY,
        'mers'                             => self::GROUP_NOVEL_RESPIRATORY,
        'influenza_new_subtype_zoonotic'   => self::GROUP_NOVEL_RESPIRATORY,
        'smallpox'                         => self::GROUP_NOVEL_RESPIRATORY,

        'measles'                          => self::GROUP_MEASLES_FAMILY,
        'rubella'                          => self::GROUP_MEASLES_FAMILY,
        'polio'                            => self::GROUP_MEASLES_FAMILY,
        'mpox'                             => self::GROUP_MEASLES_FAMILY,

        'yellow_fever'                     => self::GROUP_VECTOR_BORNE,
        'dengue'                           => self::GROUP_VECTOR_BORNE,
        'dengue_severe'                    => self::GROUP_VECTOR_BORNE,
        'malaria_uncomplicated'            => self::GROUP_VECTOR_BORNE,
        'malaria_severe'                   => self::GROUP_VECTOR_BORNE,
        'japanese_encephalitis'            => self::GROUP_VECTOR_BORNE,
        'west_nile_fever'                  => self::GROUP_VECTOR_BORNE,
        'chikungunya'                      => self::GROUP_VECTOR_BORNE,
        'zika'                             => self::GROUP_VECTOR_BORNE,
        'rickettsia_scrub_typhus'          => self::GROUP_VECTOR_BORNE,

        'meningococcal_meningitis'         => self::GROUP_MENINGITIS,

        'bubonic_plague'                   => self::GROUP_PLAGUE,
        'pneumonic_plague'                 => self::GROUP_PLAGUE,

        'rabies'                           => self::GROUP_ZOONOTIC,
        'anthrax_cutaneous'                => self::GROUP_ZOONOTIC,
        'anthrax_pulmonary'                => self::GROUP_ZOONOTIC,
        'brucellosis'                      => self::GROUP_ZOONOTIC,
        'tularemia'                        => self::GROUP_ZOONOTIC,
        'leptospirosis'                    => self::GROUP_ZOONOTIC,

        'typhoid_fever'                    => self::GROUP_FOODBORNE,
        'hepatitis_a'                      => self::GROUP_FOODBORNE,
        'hepatitis_e'                      => self::GROUP_FOODBORNE,

        'influenza_seasonal'               => self::GROUP_SEASONAL_FLU,
    ];

    /**
     * Per-group action codes that JUMP to the front of the queue, in order.
     * Anything not in this list falls back to the default RTSL-14 sequence
     * (see DEFAULT_ORDER).
     */
    public const JUMP_BY_GROUP = [
        self::GROUP_VHF => [
            'ISOLATION', 'WHO_NOTIFICATION', 'CONTACT_LISTING', 'IPC', 'LAB_SPECIMENS',
        ],
        self::GROUP_CHOLERA_DIARRHOEAL => [
            'LAB_SPECIMENS', 'RISK_COMMS', 'CONTACT_LISTING', 'IPC',
        ],
        self::GROUP_NOVEL_RESPIRATORY => [
            'ISOLATION', 'WHO_NOTIFICATION', 'IPC', 'CONTACT_TRACING',
        ],
        self::GROUP_MEASLES_FAMILY => [
            'CASE_INVESTIGATION', 'CONTACT_LISTING', 'LAB_SPECIMENS',
        ],
        self::GROUP_VECTOR_BORNE => [
            'VECTOR_CONTROL', 'CASE_INVESTIGATION', 'LAB_SPECIMENS', 'RISK_COMMS',
        ],
        self::GROUP_MENINGITIS => [
            'ISOLATION', 'CONTACT_LISTING', 'LAB_SPECIMENS', 'RISK_COMMS',
        ],
        self::GROUP_PLAGUE => [
            // Pneumonic version gets ISOLATION jumped via overrideForDisease().
            'LAB_SPECIMENS', 'CONTACT_LISTING', 'VECTOR_CONTROL', 'RISK_COMMS',
        ],
        self::GROUP_ZOONOTIC => [
            'CASE_INVESTIGATION', 'LAB_SPECIMENS', 'RISK_COMMS',
        ],
        self::GROUP_FOODBORNE => [
            'LAB_SPECIMENS', 'CASE_INVESTIGATION', 'RISK_COMMS',
        ],
        self::GROUP_SEASONAL_FLU => [
            'LAB_SPECIMENS', 'CASE_INVESTIGATION',
        ],
        self::GROUP_SYNDROMIC_UNKNOWN => [
            'CASE_INVESTIGATION', 'ISOLATION', 'LAB_SPECIMENS', 'WHO_NOTIFICATION',
        ],
    ];

    /**
     * Per-group action codes the wizard PRE-MARKS as not-applicable (these
     * become a polite chip the user can confirm rather than a step).
     */
    public const NOT_APPLICABLE_BY_GROUP = [
        self::GROUP_VHF                => ['VECTOR_CONTROL', 'POE_SURVEILLANCE'],
        self::GROUP_CHOLERA_DIARRHOEAL => [],
        self::GROUP_NOVEL_RESPIRATORY  => ['VECTOR_CONTROL'],
        self::GROUP_MEASLES_FAMILY     => ['VECTOR_CONTROL'],
        self::GROUP_VECTOR_BORNE       => ['CONTACT_TRACING'],
        self::GROUP_MENINGITIS         => ['VECTOR_CONTROL'],
        self::GROUP_PLAGUE             => [],
        self::GROUP_ZOONOTIC           => ['CONTACT_TRACING'],
        self::GROUP_FOODBORNE          => ['VECTOR_CONTROL'],
        self::GROUP_SEASONAL_FLU       => ['VECTOR_CONTROL', 'EOC_ACTIVATION', 'WHO_NOTIFICATION'],
        self::GROUP_SYNDROMIC_UNKNOWN  => [],
    ];

    /**
     * Default RTSL-14 order, applied when a step is not jumped or not-applicable.
     */
    public const DEFAULT_ORDER = [
        'CASE_INVESTIGATION',
        'ISOLATION',
        'LAB_SPECIMENS',
        'LAB_CONFIRMATION',
        'CONTACT_LISTING',
        'CONTACT_TRACING',
        'LINE_LIST',
        'EOC_ACTIVATION',
        'WHO_NOTIFICATION',
        'IPC',
        'RISK_COMMS',
        'VECTOR_CONTROL',
        'POE_SURVEILLANCE',
        'RESOURCE_MOBILISATION',
    ];

    /**
     * Canonical disease group for the supplied disease code. Falls back to
     * GROUP_SYNDROMIC_UNKNOWN when the code is not in the registry.
     */
    public static function groupFor(string $diseaseCode): string
    {
        return self::DISEASE_GROUP[$diseaseCode] ?? self::GROUP_SYNDROMIC_UNKNOWN;
    }

    /**
     * Returns whether the disease's notification posture is mandatory at the
     * top of the queue (Tier 1 always-notifiable). Used by the wizard to
     * promote WHO_NOTIFICATION above lower-priority steps.
     */
    public static function isTopTier(string $diseaseCode): bool
    {
        $intel = DiseaseIntel::get($diseaseCode);
        $tier  = (string) ($intel['ihr_tier'] ?? '');
        return str_contains($tier, 'TIER_1');
    }

    /**
     * Annex-2-style "assess first" disease — the wizard prompts for the four
     * gating questions before promoting WHO_NOTIFICATION.
     */
    public static function isAnnex2(string $diseaseCode): bool
    {
        $intel = DiseaseIntel::get($diseaseCode);
        $tier  = (string) ($intel['ihr_tier'] ?? '');
        return str_contains($tier, 'ANNEX2');
    }

    /**
     * Returns the queue order for the given disease. Stabilisation overrides
     * (CASE_INVESTIGATION + ISOLATION first) win when $criticalVitals is true.
     *
     * The result is a list of action codes ranked from "ask first" to "ask
     * last". Codes the wizard auto-marks NOT_APPLICABLE are excluded.
     *
     * @return string[]
     */
    public static function orderFor(?string $diseaseCode, bool $criticalVitals = false): array
    {
        $code  = (string) ($diseaseCode ?? '');
        $group = $code === '' ? self::GROUP_SYNDROMIC_UNKNOWN : self::groupFor($code);

        $jump = self::JUMP_BY_GROUP[$group] ?? [];

        if ($code !== '') {
            $jump = self::overrideForDisease($code, $jump);
        }

        if ($criticalVitals) {
            $jump = array_values(array_unique(array_merge(['CASE_INVESTIGATION', 'ISOLATION'], $jump)));
        }

        if ($code !== '' && self::isTopTier($code)) {
            $jump = self::promoteWhoNotification($jump);
        }

        $tail = array_values(array_diff(self::DEFAULT_ORDER, $jump));

        $combined = array_values(array_merge($jump, $tail));

        $na = self::NOT_APPLICABLE_BY_GROUP[$group] ?? [];

        return array_values(array_filter($combined, fn ($c) => !in_array($c, $na, true)));
    }

    /**
     * Returns the action codes that the wizard auto-marks NOT_APPLICABLE
     * for the disease. The user still sees a confirm chip for each so they
     * can object if local context demands the action.
     *
     * @return string[]
     */
    public static function notApplicableFor(?string $diseaseCode): array
    {
        $code  = (string) ($diseaseCode ?? '');
        $group = $code === '' ? self::GROUP_SYNDROMIC_UNKNOWN : self::groupFor($code);

        $base  = self::NOT_APPLICABLE_BY_GROUP[$group] ?? [];

        // Disease-level overrides — RVF must keep VECTOR_CONTROL applicable.
        if ($code === 'rift_valley_fever') {
            $base = array_values(array_diff($base, ['VECTOR_CONTROL']));
        }

        return $base;
    }

    // ------------------------------------------------------------------
    //  Internals
    // ------------------------------------------------------------------

    /**
     * Disease-level tweaks on top of group defaults.
     *
     * @param string[] $jump
     * @return string[]
     */
    private static function overrideForDisease(string $code, array $jump): array
    {
        if ($code === 'pneumonic_plague') {
            // Pneumonic plague needs isolation up front.
            $jump = array_values(array_unique(array_merge(['ISOLATION'], $jump)));
        }

        if ($code === 'rift_valley_fever') {
            // RVF — vector control is the lever; promote it.
            $jump = array_values(array_unique(array_merge($jump, ['VECTOR_CONTROL'])));
        }

        if ($code === 'anthrax_pulmonary') {
            // Pulmonary anthrax has a biothreat dimension — ISOLATION + WHO_NOTIFICATION first.
            $jump = array_values(array_unique(array_merge(['ISOLATION', 'WHO_NOTIFICATION'], $jump)));
        }

        return $jump;
    }

    /**
     * Promotes WHO_NOTIFICATION to position 2 (after isolation/stabilisation).
     *
     * @param string[] $jump
     * @return string[]
     */
    private static function promoteWhoNotification(array $jump): array
    {
        $jump = array_values(array_diff($jump, ['WHO_NOTIFICATION']));
        $head = array_slice($jump, 0, 1);
        $rest = array_slice($jump, 1);

        return array_values(array_merge($head, ['WHO_NOTIFICATION'], $rest));
    }
}
