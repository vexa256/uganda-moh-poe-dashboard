<?php

declare(strict_types=1);

namespace App\Services\Clinical;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ClinicalRegistry — dynamic discovery for the Clinical Library section.
 *
 * Per the Paranoid v2 Clinical Library brief §3 and §13, every count, every
 * weight, every tier, every endemic mapping, every vaccine rule must be
 * discovered at request time from the live reference tables. The view never
 * hard-codes "42 diseases" or "3 tiers" or any other reference value.
 *
 * This class is the ONLY path the Clinical Library controllers take to read
 * the reference tables. It is read-only by construction: there are no
 * write methods. The reference tables themselves are owned by the scoring
 * engine team and are out of bounds for this section per brief §2.
 *
 * All methods return either the raw rows the caller needs or already-shaped
 * payloads for the controllers to forward to JSON. JSON columns
 * (symptom_weights, gates, etc.) are decoded once here and re-emitted as
 * arrays so controllers never re-decode.
 */
final class ClinicalRegistry
{
    /* ============================================================
     * Diseases
     * ============================================================ */

    /**
     * @return Collection<int,object> Hydrated disease rows with JSON columns
     *                                already decoded into arrays.
     */
    public function diseases(): Collection
    {
        return DB::table('ref_diseases')
            ->orderBy('display_name')
            ->get()
            ->map(fn ($r) => $this->hydrateDisease($r));
    }

    public function disease(int $id): ?object
    {
        $row = DB::table('ref_diseases')->where('id', $id)->first();
        return $row ? $this->hydrateDisease($row) : null;
    }

    public function diseaseByCode(string $code): ?object
    {
        $row = DB::table('ref_diseases')->where('disease_code', $code)->first();
        return $row ? $this->hydrateDisease($row) : null;
    }

    /** Distinct tier integers actually present in the data. */
    public function tiersInUse(): array
    {
        return DB::table('ref_diseases')
            ->whereNotNull('ihr_tier')
            ->distinct()->orderBy('ihr_tier')
            ->pluck('ihr_tier')->map(fn ($v) => (int) $v)->all();
    }

    /** Distinct WHO syndrome strings actually present (raw). */
    public function whoSyndromesInUse(): array
    {
        return DB::table('ref_diseases')
            ->whereNotNull('who_syndrome')->where('who_syndrome', '!=', '')
            ->distinct()->orderBy('who_syndrome')
            ->pluck('who_syndrome')->all();
    }

    /* ============================================================
     * Symptoms
     * ============================================================ */

    public function symptoms(): Collection
    {
        return DB::table('ref_symptoms')
            ->orderByRaw('CASE WHEN category IS NULL THEN 1 ELSE 0 END, category')
            ->orderBy('display_name')
            ->get()
            ->map(fn ($r) => $this->hydrateSymptom($r));
    }

    public function symptom(int $id): ?object
    {
        $row = DB::table('ref_symptoms')->where('id', $id)->first();
        return $row ? $this->hydrateSymptom($row) : null;
    }

    public function symptomByCode(string $code): ?object
    {
        $row = DB::table('ref_symptoms')->where('symptom_code', $code)->first();
        return $row ? $this->hydrateSymptom($row) : null;
    }

    /** Distinct symptom categories actually in the data. */
    public function symptomCategoriesInUse(): array
    {
        return DB::table('ref_symptoms')
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')
            ->pluck('category')->all();
    }

    /** Distinct syndrome tag strings actually in the data (flattened). */
    public function syndromeTagsInUse(): array
    {
        $tags = [];
        foreach (DB::table('ref_symptoms')->whereNotNull('syndrome_tags')->pluck('syndrome_tags') as $json) {
            $arr = json_decode((string) $json, true);
            if (! is_array($arr)) continue;
            foreach ($arr as $entry) {
                $tag = is_array($entry) ? ($entry['tag'] ?? null) : (string) $entry;
                if ($tag) $tags[$tag] = true;
            }
        }
        ksort($tags);
        return array_keys($tags);
    }

    /* ============================================================
     * Exposures + engine-code mappings
     * ============================================================ */

    public function exposures(): Collection
    {
        return DB::table('ref_exposures')
            ->orderByRaw('CASE WHEN category IS NULL THEN 1 ELSE 0 END, category')
            ->orderBy('display_name')
            ->get()
            ->map(fn ($r) => $this->hydrateExposure($r));
    }

    public function exposure(int $id): ?object
    {
        $row = DB::table('ref_exposures')->where('id', $id)->first();
        return $row ? $this->hydrateExposure($row) : null;
    }

    public function exposureByCode(string $code): ?object
    {
        $row = DB::table('ref_exposures')->where('exposure_code', $code)->first();
        return $row ? $this->hydrateExposure($row) : null;
    }

    /** All exposure→engine-code mappings (one row per pair). */
    public function exposureMappings(): Collection
    {
        return DB::table('ref_exposure_mappings')
            ->where('is_active', 1)
            ->orderBy('exposure_code')->orderBy('priority')
            ->get();
    }

    public function exposureCategoriesInUse(): array
    {
        return DB::table('ref_exposures')
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')
            ->pluck('category')->all();
    }

    /* ============================================================
     * Engine config (boosts and tunables)
     * ============================================================ */

    public function engineConfigRows(): Collection
    {
        return DB::table('ref_engine_config')
            ->orderBy('section')->orderBy('config_key')
            ->get()
            ->map(fn ($r) => $this->hydrateEngineConfig($r));
    }

    public function engineConfig(int $id): ?object
    {
        $row = DB::table('ref_engine_config')->where('id', $id)->first();
        return $row ? $this->hydrateEngineConfig($row) : null;
    }

    public function engineConfigByKey(string $key): ?object
    {
        $row = DB::table('ref_engine_config')->where('config_key', $key)->first();
        return $row ? $this->hydrateEngineConfig($row) : null;
    }

    public function engineSectionsInUse(): array
    {
        return DB::table('ref_engine_config')
            ->whereNotNull('section')->where('section', '!=', '')
            ->distinct()->orderBy('section')
            ->pluck('section')->all();
    }

    /* ============================================================
     * Endemic countries
     * ============================================================ */

    public function endemicMappings(): Collection
    {
        return DB::table('ref_endemic_countries AS e')
            ->leftJoin('ref_diseases AS d', 'd.disease_code', '=', 'e.disease_code')
            ->select([
                'e.id',
                'e.disease_code',
                'e.country_code',
                'e.country_name',
                'e.endemicity_level',
                'e.since_year',
                'e.source',
                'e.last_verified_at',
                'e.is_active',
                'e.updated_at',
                'd.display_name AS disease_display_name',
                'd.ihr_tier AS disease_ihr_tier',
            ])
            ->orderByRaw("FIELD(e.endemicity_level,'OUTBREAK_ACTIVE','OUTBREAK_RECENT','ENDEMIC','SPORADIC','IMPORTED_ONLY')")
            ->orderBy('e.disease_code')
            ->get();
    }

    /** Endemic mappings for one disease. */
    public function endemicMappingsForDisease(string $diseaseCode): Collection
    {
        return DB::table('ref_endemic_countries')
            ->where('disease_code', $diseaseCode)
            ->where('is_active', 1)
            ->orderByRaw("FIELD(endemicity_level,'OUTBREAK_ACTIVE','OUTBREAK_RECENT','ENDEMIC','SPORADIC','IMPORTED_ONLY')")
            ->orderBy('country_name')
            ->get();
    }

    /** Endemic mappings for one country. */
    public function endemicMappingsForCountry(string $countryCode): Collection
    {
        return DB::table('ref_endemic_countries AS e')
            ->leftJoin('ref_diseases AS d', 'd.disease_code', '=', 'e.disease_code')
            ->where('e.country_code', $countryCode)
            ->where('e.is_active', 1)
            ->select([
                'e.disease_code',
                'e.endemicity_level',
                'e.since_year',
                'e.source',
                'd.display_name AS disease_display_name',
                'd.ihr_tier AS disease_ihr_tier',
            ])
            ->orderByRaw("FIELD(e.endemicity_level,'OUTBREAK_ACTIVE','OUTBREAK_RECENT','ENDEMIC','SPORADIC','IMPORTED_ONLY')")
            ->orderBy('d.display_name')
            ->get();
    }

    public function endemicLevelsInUse(): array
    {
        return DB::table('ref_endemic_countries')
            ->distinct()->pluck('endemicity_level')->all();
    }

    /* ============================================================
     * Vaccines — derived surface (no first-class vaccine table)
     *
     * Reality: there is no ref_vaccines table. Vaccine rules live as
     * (a) ref_engine_config rows tagged with vaccine-related keys, and
     * (b) aggregated_template_columns whose column_key encodes a vaccine
     *     stance like `yellow_fever_vacc_valid` /
     *     `yellow_fever_cert_invalid_refused`.
     *
     * The brief mandates a clin-vaccines view; the reconciliation log
     * notes this divergence and the view is built from these two
     * surrogate sources rather than fabricating a vaccine table.
     * ============================================================ */

    /** Engine-config rows whose key looks vaccine-related. */
    public function vaccineEngineRows(): Collection
    {
        return DB::table('ref_engine_config')
            ->where(function ($q) {
                foreach (['vaccin', 'immuni', 'yellow_fever', 'polio', 'cholera', 'meningitis'] as $needle) {
                    $q->orWhere('config_key', 'like', "%{$needle}%");
                }
            })
            ->orderBy('config_key')
            ->get()
            ->map(fn ($r) => $this->hydrateEngineConfig($r));
    }

    /**
     * Aggregated-template column keys that encode a per-vaccine stance.
     * Returned grouped by inferred vaccine name (yellow_fever / polio / …).
     *
     * @return array<string,array<int,object>>
     */
    public function vaccineSubmissionColumns(): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('aggregated_template_columns')) {
            return [];
        }
        $rows = DB::table('aggregated_template_columns')
            ->where(function ($q) {
                foreach (['vacc', 'cert', 'yellow_fever', 'polio', 'cholera', 'meningitis', 'immuni'] as $needle) {
                    $q->orWhere('column_key', 'like', "%{$needle}%");
                }
            })
            ->orderBy('column_key')
            ->get();
        $grouped = [];
        foreach ($rows as $r) {
            $vaccine = $this->inferVaccineFromColumnKey((string) $r->column_key);
            $grouped[$vaccine] ??= [];
            $grouped[$vaccine][] = $r;
        }
        ksort($grouped);
        return $grouped;
    }

    /* ============================================================
     * Hydration helpers — JSON columns decoded once.
     * ============================================================ */

    private function hydrateDisease(object $r): object
    {
        $r->case_definition  = $this->decodeJson($r->case_definition  ?? null);
        $r->gates            = $this->decodeJson($r->gates            ?? null);
        $r->symptom_weights  = $this->decodeJson($r->symptom_weights  ?? null);
        $r->exposure_weights = $this->decodeJson($r->exposure_weights ?? null);
        $r->triage_overrides = $this->decodeJson($r->triage_overrides ?? null);
        $r->absent_penalties = $this->decodeJson($r->absent_penalties ?? null);
        $r->sources          = $this->decodeJson($r->sources          ?? null);
        $r->payload          = $this->decodeJson($r->payload          ?? null);
        return $r;
    }

    private function hydrateSymptom(object $r): object
    {
        $r->syndrome_tags = $this->decodeJson($r->syndrome_tags ?? null);
        $r->payload       = $this->decodeJson($r->payload       ?? null);
        return $r;
    }

    private function hydrateExposure(object $r): object
    {
        $r->triggers_diseases = $this->decodeJson($r->triggers_diseases ?? null);
        $r->payload           = $this->decodeJson($r->payload           ?? null);
        return $r;
    }

    private function hydrateEngineConfig(object $r): object
    {
        $r->config_value = $this->decodeJson($r->config_value ?? null);
        return $r;
    }

    private function decodeJson(mixed $raw): mixed
    {
        if ($raw === null || $raw === '') return null;
        if (is_array($raw)) return $raw;
        $decoded = json_decode((string) $raw, true);
        return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? null : $decoded;
    }

    private function inferVaccineFromColumnKey(string $columnKey): string
    {
        foreach (['yellow_fever' => 'yellow_fever',
                  'polio'        => 'polio',
                  'cholera'      => 'cholera',
                  'meningitis'   => 'meningitis',
                  'immuni'       => 'general_immunisation'] as $needle => $label) {
            if (str_contains($columnKey, $needle)) return $label;
        }
        return 'other';
    }
}
