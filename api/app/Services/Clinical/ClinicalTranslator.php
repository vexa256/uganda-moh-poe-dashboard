<?php

declare(strict_types=1);

namespace App\Services\Clinical;

use Illuminate\Support\Facades\DB;

/**
 * ClinicalTranslator — deterministic, hardcoded code-to-plain-language layer.
 *
 * Per the Paranoid v2 Clinical Library brief §3 and §15.15: every translation
 * is hardcoded, deterministic, versioned (`// v1 — domain sign-off pending`).
 * When a translation is missing, the translator returns the raw code paired
 * with a clear "we haven't translated this yet" caption — never invents an
 * interpretation.
 *
 * The translator handles:
 *   - IHR tier (1, 2, 3)               → label + downstream consequence
 *   - Endemicity level (5 enum vals)    → label + scoring consequence
 *   - Likelihood weight (numeric)       → strength label (Strong / Moderate / Weak / Negligible / Negative)
 *   - Symptom sensitivity (0..1)        → label (High / Moderate / Low / Unknown)
 *   - Exposure response_type            → label
 *   - WHO syndrome string               → label
 *   - Symptom code → display_name        (DB-backed cache)
 *   - Exposure code → display_name       (DB-backed cache)
 *   - Disease code → display_name        (DB-backed cache)
 *   - Country code → country_name        (DB-backed cache, falls back to ref_endemic_countries)
 *   - Engine config key → human label    (best-effort heuristic + hardcoded common keys)
 *
 * Every public method returns either a string or an array shaped
 * `{label: string, plain: string, technical: string|null, fallback?: bool}`
 * so the view layer can render with consistent two-tier disclosure (plain
 * label + a "Show technical detail" disclosure that exposes the raw code).
 */
final class ClinicalTranslator
{
    public const VERSION = 'v1';

    /* ============================================================
     * IHR tier
     * ============================================================ */

    /** v1 — domain sign-off pending */
    private const TIER_LABELS = [
        1 => [
            'label'       => 'Tier 1 · always notifiable',
            'short'       => 'Tier 1',
            'consequence' => 'Always reportable to WHO under the International Health Regulations. A confirmed case triggers immediate national notification and the highest-priority response.',
        ],
        2 => [
            'label'       => 'Tier 2 · WHO Annex 2 conditional',
            'short'       => 'Tier 2',
            'consequence' => 'Reportable when the case meets the WHO Annex 2 algorithm thresholds. Higher-than-baseline scores trigger national notification and a clinical review.',
        ],
        3 => [
            'label'       => 'Tier 3 · national surveillance',
            'short'       => 'Tier 3',
            'consequence' => 'Tracked nationally for surveillance. Cases are recorded and feed the situational picture but do not by themselves trigger WHO notification.',
        ],
    ];

    public function tier(?int $tier): array
    {
        if ($tier === null) {
            return $this->fallback('tier', null, 'No tier on file', 'This disease has not been classified into a notification tier.');
        }
        $entry = self::TIER_LABELS[$tier] ?? null;
        if ($entry === null) {
            return $this->fallback('tier', (string) $tier, "Tier {$tier}", 'This tier value is recorded but has not yet been translated.');
        }
        return [
            'code'        => $tier,
            'label'       => $entry['label'],
            'short'       => $entry['short'],
            'plain'       => $entry['consequence'],
            'consequence' => $entry['consequence'],
            'technical'   => "ihr_tier={$tier}",
            'fallback'    => false,
        ];
    }

    public function tierBadgeClass(?int $tier): string
    {
        return match ($tier) {
            1       => 'badge-critical',
            2       => 'badge-warning',
            3       => 'badge-muted',
            default => 'badge-soft',
        };
    }

    /* ============================================================
     * Endemicity level
     * ============================================================ */

    /** v1 — domain sign-off pending */
    private const ENDEMICITY_LABELS = [
        'OUTBREAK_ACTIVE' => [
            'label'       => 'Active outbreak',
            'consequence' => 'A traveller arriving from this country gets the largest endemic boost added to their score for this disease. Use when an outbreak is currently confirmed and being responded to.',
            'badge'       => 'badge-critical',
        ],
        'OUTBREAK_RECENT' => [
            'label'       => 'Recent outbreak',
            'consequence' => 'A traveller from this country still gets a meaningful endemic boost; the outbreak is no longer active but the country remains a higher-risk origin for the immediate post-outbreak period.',
            'badge'       => 'badge-warning',
        ],
        'ENDEMIC' => [
            'label'       => 'Endemic',
            'consequence' => 'A traveller from this country gets the standard endemic boost. The disease is regularly present in the country.',
            'badge'       => 'badge-info',
        ],
        'SPORADIC' => [
            'label'       => 'Sporadic cases',
            'consequence' => 'A traveller from this country gets a small endemic boost. Cases are uncommon but documented.',
            'badge'       => 'badge-soft',
        ],
        'IMPORTED_ONLY' => [
            'label'       => 'Imported cases only',
            'consequence' => 'No additional boost: the country itself is not a source — cases there have been imported from elsewhere.',
            'badge'       => 'badge-muted',
        ],
    ];

    public function endemicity(?string $level): array
    {
        if (! $level) {
            return $this->fallback('endemicity', null, 'No endemicity on file', 'This country-disease pair has no endemicity classification.');
        }
        $up = strtoupper($level);
        $entry = self::ENDEMICITY_LABELS[$up] ?? null;
        if ($entry === null) {
            return $this->fallback('endemicity', $up, str_replace('_', ' ', strtolower($up)),
                'This endemicity level exists in the data but has not yet been translated.');
        }
        return [
            'code'        => $up,
            'label'       => $entry['label'],
            'plain'       => $entry['consequence'],
            'consequence' => $entry['consequence'],
            'badge'       => $entry['badge'],
            'technical'   => "endemicity_level={$up}",
            'fallback'    => false,
        ];
    }

    /* ============================================================
     * Numeric weight → plain-language strength label
     *
     * v1 deterministic bands. Source: empirical inspection of
     * ref_diseases.symptom_weights / exposure_weights values which range
     * in the dataset roughly from -24 (negative; rules out) to +24
     * (very strong indicator). We choose symmetric bands so the same
     * label semantics apply to symptoms, exposures, and boosts.
     * ============================================================ */

    public function weightStrength(float|int|null $w): array
    {
        if ($w === null) {
            return ['label' => 'No weight on file', 'sign' => 0, 'fallback' => true];
        }
        $w = (float) $w;
        $abs = abs($w);
        if ($w < 0) {
            $label = match (true) {
                $abs >= 12.0 => 'Strongly rules this disease out',
                $abs >= 6.0  => 'Reduces likelihood of this disease',
                default       => 'Slightly lowers likelihood',
            };
            return ['label' => $label, 'sign' => -1, 'fallback' => false];
        }
        $label = match (true) {
            $w >= 18.0 => 'Very strong indicator',
            $w >= 12.0 => 'Strong indicator',
            $w >= 6.0  => 'Moderate indicator',
            $w >  0.0  => 'Weak indicator',
            default     => 'No effect',
        };
        return ['label' => $label, 'sign' => $w > 0 ? 1 : 0, 'fallback' => false];
    }

    /** Symptom sensitivity (0..1) — labelled bands. */
    public function sensitivity(?float $s): array
    {
        if ($s === null) {
            return ['label' => 'Unknown sensitivity', 'fraction' => null, 'fallback' => true];
        }
        $label = match (true) {
            $s >= 0.80 => 'High clinical sensitivity (≥80%)',
            $s >= 0.50 => 'Moderate clinical sensitivity (50–79%)',
            $s >= 0.20 => 'Low clinical sensitivity (20–49%)',
            default     => 'Very low clinical sensitivity (<20%)',
        };
        return ['label' => $label, 'fraction' => $s, 'fallback' => false];
    }

    /* ============================================================
     * Exposure response_type → plain label
     * ============================================================ */
    private const RESPONSE_TYPE_LABELS = [
        'YES_NO'         => 'Yes / No question',
        'YES_NO_UNKNOWN' => 'Yes / No / Unknown question',
        'MULTI_SELECT'   => 'Multi-select question',
        'TEXT'           => 'Free-text answer',
        'NUMERIC'        => 'Numeric answer',
    ];

    public function responseType(?string $code): string
    {
        if (! $code) return 'Unknown response type';
        return self::RESPONSE_TYPE_LABELS[strtoupper($code)] ?? $code;
    }

    /* ============================================================
     * WHO syndrome — best-effort plain label
     * Source: distinct values inspected in ref_diseases.who_syndrome.
     * ============================================================ */
    private const SYNDROME_LABELS = [
        'vesiculopustular_rash'   => 'Vesicular / pustular rash',
        'high_consequence_rash'   => 'High-consequence rash illness',
        'haemorrhagic_fever'      => 'Haemorrhagic fever',
        'acute_respiratory'       => 'Acute respiratory illness',
        'severe_acute_respiratory'=> 'Severe acute respiratory illness',
        'acute_diarrhoea'         => 'Acute diarrhoeal illness',
        'acute_neurological'      => 'Acute neurological illness',
        'acute_jaundice'          => 'Acute jaundice syndrome',
        'meningitis'              => 'Meningitis syndrome',
        'sepsis'                  => 'Sepsis syndrome',
        'undifferentiated_fever'  => 'Undifferentiated fever',
        'paralytic_illness'       => 'Acute paralytic illness',
        'pneumonia'               => 'Pneumonia',
        'gastroenteritis'         => 'Gastroenteritis',
    ];

    public function syndrome(?string $raw): array
    {
        if (! $raw) {
            return $this->fallback('syndrome', null, 'No syndrome on file', 'This disease is not associated with a WHO syndromic group.');
        }
        $key = strtolower(trim($raw));
        $label = self::SYNDROME_LABELS[$key] ?? null;
        if ($label === null) {
            return $this->fallback('syndrome', $raw, ucfirst(str_replace('_', ' ', $key)), 'This syndromic grouping has not yet been translated.');
        }
        return ['code' => $raw, 'label' => $label, 'plain' => $label, 'technical' => $raw, 'fallback' => false];
    }

    /* ============================================================
     * DB-backed name lookups (cached per-request).
     * ============================================================ */

    /** @var array<string,string>|null */
    private ?array $diseaseNameCache = null;
    /** @var array<string,string>|null */
    private ?array $symptomNameCache = null;
    /** @var array<string,string>|null */
    private ?array $exposureNameCache = null;
    /** @var array<string,string>|null */
    private ?array $countryNameCache = null;

    public function diseaseName(?string $code): string
    {
        if (! $code) return 'Unknown disease';
        if ($this->diseaseNameCache === null) {
            $this->diseaseNameCache = DB::table('ref_diseases')->pluck('display_name', 'disease_code')->all();
        }
        return $this->diseaseNameCache[$code] ?? $code;
    }

    public function symptomName(?string $code): string
    {
        if (! $code) return 'Unknown symptom';
        if ($this->symptomNameCache === null) {
            $this->symptomNameCache = DB::table('ref_symptoms')->pluck('display_name', 'symptom_code')->all();
        }
        return $this->symptomNameCache[$code] ?? $code;
    }

    public function exposureName(?string $code): string
    {
        if (! $code) return 'Unknown exposure';
        if ($this->exposureNameCache === null) {
            $this->exposureNameCache = DB::table('ref_exposures')->pluck('display_name', 'exposure_code')->all();
        }
        return $this->exposureNameCache[$code] ?? $code;
    }

    public function countryName(?string $code): string
    {
        if (! $code) return 'Unknown country';
        if ($this->countryNameCache === null) {
            $this->countryNameCache = [];
            // Prefer the structured ref_countries table when present; the
            // canonical column is `name` (not `country_name`); fall back to
            // ref_endemic_countries.country_name for any code missing here.
            if (\Illuminate\Support\Facades\Schema::hasTable('ref_countries')) {
                foreach (DB::table('ref_countries')->select('country_code', 'name')->get() as $r) {
                    $this->countryNameCache[(string) $r->country_code] = (string) ($r->name ?? $r->country_code);
                }
            }
            // Fill gaps from endemic table.
            foreach (DB::table('ref_endemic_countries')->select('country_code', 'country_name')->whereNotNull('country_name')->distinct()->get() as $r) {
                $this->countryNameCache[(string) $r->country_code] ??= (string) $r->country_name;
            }
        }
        return $this->countryNameCache[$code] ?? $code;
    }

    /* ============================================================
     * Helpers
     * ============================================================ */

    private function fallback(string $kind, ?string $raw, string $label, string $caption): array
    {
        return [
            'code'      => $raw,
            'label'     => $label,
            'plain'     => $caption,
            'technical' => $raw,
            'fallback'  => true,
            'kind'      => $kind,
        ];
    }
}
