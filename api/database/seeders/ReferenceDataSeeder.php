<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase REF · Unit REF-2 — Reference-data seeder.
 *
 * Hydrates the seven REF-1 tables from JSON snapshots written by
 * scripts/extract-reference-data.cjs.  The JSON files live alongside
 * this seeder under api/database/seeders/data/ and are the canonical
 * intermediate format between the JS source modules and the database.
 *
 * Idempotency
 * -----------
 * Every insert is performed via upsert() keyed on the unique columns
 * created in REF-1, so re-running this seeder is safe — it overwrites
 * payload columns with the latest snapshot but preserves auto-id and
 * created_at on existing rows.
 *
 * Sources extracted (see scripts/extract-reference-data.cjs):
 *   • src/POEs.js                 → ref_poes              (61)
 *   • src/Diseases.js             → ref_diseases          (42)
 *   •                             → ref_symptoms          (~89, derived)
 *   •                             → ref_engine_config     (12)
 *   • src/exposures.js            → ref_exposures         (29)
 *   •                             → ref_exposure_mappings (80)
 *   • src/Diseases_intelligence.js→ ref_endemic_countries (1154)
 */
class ReferenceDataSeeder extends Seeder
{
    private const DATA_DIR = __DIR__ . '/data';

    public function run(): void
    {
        // The legacy data/poes.json snapshot is a stale Zambia-scoped fixture
        // from before the multi-country split. Per-country POE data now lives
        // under seeders/country/<ISO2>/data/poes.json and is loaded by
        // Country\PoesSeeder. The legacy import only runs if explicitly opted
        // in via COUNTRY_TENANT_ISO2=ZM — never for the Uganda tenant.
        $iso2 = strtoupper((string) env('COUNTRY_TENANT_ISO2', config('country.iso2', 'UG')));
        if ($iso2 === 'ZM') {
            $this->seedPoes();
        }

        $this->seedDiseases();
        $this->seedSymptoms();
        $this->seedExposures();
        $this->seedExposureMappings();
        $this->seedEngineConfig();
        $this->seedEndemicCountries();
    }

    /* ── Helpers ───────────────────────────────────────────────────── */

    private function loadJson(string $name): array
    {
        $path = self::DATA_DIR . '/' . $name;
        if (!is_file($path)) {
            throw new \RuntimeException(
                "Reference-data snapshot {$name} not found at {$path}. "
                . "Run `node scripts/extract-reference-data.cjs` to regenerate."
            );
        }
        $raw = file_get_contents($path);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException("Reference-data snapshot {$name} did not decode to an array.");
        }
        return $data;
    }

    /** Encode any associative or list value to JSON, or null. */
    private function j($value): ?string
    {
        if ($value === null) return null;
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function now(): string
    {
        return Carbon::now()->toDateTimeString();
    }

    /* ── ref_poes ──────────────────────────────────────────────────── */

    private function seedPoes(): void
    {
        $rows = [];
        $now = $this->now();
        foreach ($this->loadJson('poes.json') as $r) {
            $payload = $r['payload'] ?? [];
            $externalId = is_array($payload) ? ($payload['id'] ?? null) : null;
            $rows[] = [
                'external_id'         => $externalId !== null ? substr((string) $externalId, 0, 80) : null,
                'country_code'        => substr((string) ($r['country_code'] ?? 'Unknown'), 0, 10),
                'poe_code'            => substr((string) $r['poe_code'], 0, 40),
                'poe_name'            => substr((string) $r['poe_name'], 0, 200),
                'admin_level_1'       => $r['admin_level_1']      ?? null,
                'admin_level_1_type'  => $r['admin_level_1_type'] ?? null,
                'district'            => $r['district']           ?? null,
                'poe_type'            => $this->normalisePoeType($r['poe_type'] ?? 'land_border'),
                'transport_mode'      => $this->normaliseTransport($r['transport_mode'] ?? 'land'),
                'regional_cluster'    => $r['regional_cluster']   ?? null,
                'is_national_level'   => (bool) ($r['is_national_level']   ?? false),
                'is_major_entry'      => (bool) ($r['is_major_entry']      ?? false),
                'is_recommended_osbp' => (bool) ($r['is_recommended_osbp'] ?? false),
                'border_country'      => $r['border_country'] ?? null,
                'latitude'            => $r['latitude']  ?? null,
                'longitude'           => $r['longitude'] ?? null,
                'gazette_source'      => $r['gazette_source'] ?? null,
                'payload'             => $this->j($payload ?: null),
                'is_active'           => true,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }
        // Upsert keyed on external_id so the two same-name POEs (e.g.
        // "Kayanja" in Ntungamo vs Kasese district) stay distinct.
        // Rows without external_id fall back to the legacy
        // (country_code, poe_code) key — used only if a future seeder
        // ships rows that lack source IDs.
        $rowsWithExternal = array_values(array_filter($rows, fn ($r) => $r['external_id'] !== null));
        $rowsWithoutExternal = array_values(array_filter($rows, fn ($r) => $r['external_id'] === null));

        if ($rowsWithExternal) {
            $this->upsertChunked('ref_poes', $rowsWithExternal, ['external_id'], [
                'country_code', 'poe_code', 'poe_name', 'admin_level_1', 'admin_level_1_type',
                'district', 'poe_type', 'transport_mode', 'regional_cluster',
                'is_national_level', 'is_major_entry', 'is_recommended_osbp',
                'border_country', 'latitude', 'longitude', 'gazette_source',
                'payload', 'is_active', 'updated_at',
            ]);
        }
        if ($rowsWithoutExternal) {
            // Inserts without conflict resolution — caller is responsible
            // for guaranteeing no collisions when external_id is absent.
            foreach (array_chunk($rowsWithoutExternal, 200) as $chunk) {
                DB::table('ref_poes')->insertOrIgnore($chunk);
            }
        }
    }

    private function normalisePoeType(string $v): string
    {
        $v = strtolower(trim($v));
        $allowed = ['airport', 'airstrip', 'port', 'island_entry', 'land_border', 'rail', 'other'];
        return in_array($v, $allowed, true) ? $v : 'other';
    }

    private function normaliseTransport(string $v): string
    {
        $v = strtolower(trim($v));
        $allowed = ['air', 'water', 'land', 'rail', 'other'];
        return in_array($v, $allowed, true) ? $v : 'other';
    }

    /* ── ref_diseases ──────────────────────────────────────────────── */

    private function seedDiseases(): void
    {
        $rows = [];
        $now = $this->now();
        foreach ($this->loadJson('diseases.json') as $r) {
            $rows[] = [
                'disease_code'        => substr((string) $r['disease_code'], 0, 80),
                'display_name'        => substr((string) $r['display_name'], 0, 200),
                'ihr_tier'            => (int) ($r['ihr_tier'] ?? 2),
                'who_syndrome'        => $r['who_syndrome'] ?? null,
                'incubation_days_min' => $r['incubation_days_min'] ?? null,
                'incubation_days_max' => $r['incubation_days_max'] ?? null,
                'case_definition'     => $this->j($r['case_definition'] ?? null),
                'gates'               => $this->j($r['gates'] ?? null),
                'symptom_weights'     => $this->j($r['symptom_weights'] ?? null),
                'exposure_weights'    => $this->j($r['exposure_weights'] ?? null),
                'triage_overrides'    => $this->j($r['triage_overrides'] ?? null),
                'absent_penalties'    => $this->j($r['absent_penalties'] ?? null),
                'sources'             => $this->j($r['sources'] ?? null),
                'payload'             => $this->j($r['payload'] ?? null),
                'is_active'           => true,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];
        }
        $this->upsertChunked('ref_diseases', $rows, ['disease_code'], [
            'display_name', 'ihr_tier', 'who_syndrome', 'incubation_days_min',
            'incubation_days_max', 'case_definition', 'gates', 'symptom_weights',
            'exposure_weights', 'triage_overrides', 'absent_penalties', 'sources',
            'payload', 'is_active', 'updated_at',
        ]);
    }

    /* ── ref_symptoms ──────────────────────────────────────────────── */

    private function seedSymptoms(): void
    {
        $rows = [];
        $now = $this->now();
        foreach ($this->loadJson('symptoms.json') as $r) {
            $rows[] = [
                'symptom_code'   => substr((string) $r['symptom_code'], 0, 80),
                'display_name'   => substr((string) $r['display_name'], 0, 200),
                'category'       => $r['category'] ?? null,
                'syndrome_tags'  => $this->j($r['syndrome_tags'] ?? null),
                'sensitivity'    => $r['sensitivity'] ?? null,
                'is_red_flag'    => (bool) ($r['is_red_flag'] ?? false),
                'is_hallmark'    => (bool) ($r['is_hallmark'] ?? false),
                'payload'        => $this->j($r['payload'] ?? null),
                'is_active'      => true,
                'created_at'     => $now,
                'updated_at'     => $now,
            ];
        }
        $this->upsertChunked('ref_symptoms', $rows, ['symptom_code'], [
            'display_name', 'category', 'syndrome_tags', 'sensitivity',
            'is_red_flag', 'is_hallmark', 'payload', 'is_active', 'updated_at',
        ]);
    }

    /* ── ref_exposures ─────────────────────────────────────────────── */

    private function seedExposures(): void
    {
        $rows = [];
        $now = $this->now();
        foreach ($this->loadJson('exposures.json') as $r) {
            $rows[] = [
                'exposure_code'     => substr((string) $r['exposure_code'], 0, 80),
                'display_name'      => substr((string) $r['display_name'], 0, 200),
                'category'          => $r['category'] ?? null,
                'prompt_text'       => $r['prompt_text'] ?? null,
                'response_type'     => $this->normaliseResponseType($r['response_type'] ?? 'YES_NO'),
                'is_high_risk'      => (bool) ($r['is_high_risk'] ?? false),
                'triggers_diseases' => $this->j($r['triggers_diseases'] ?? null),
                'payload'           => $this->j($r['payload'] ?? null),
                'is_active'         => true,
                'created_at'        => $now,
                'updated_at'        => $now,
            ];
        }
        $this->upsertChunked('ref_exposures', $rows, ['exposure_code'], [
            'display_name', 'category', 'prompt_text', 'response_type',
            'is_high_risk', 'triggers_diseases', 'payload', 'is_active', 'updated_at',
        ]);
    }

    private function normaliseResponseType(string $v): string
    {
        $v = strtoupper(trim($v));
        $allowed = ['YES_NO', 'YES_NO_UNKNOWN', 'MULTI_SELECT', 'TEXT', 'NUMERIC'];
        return in_array($v, $allowed, true) ? $v : 'YES_NO';
    }

    /* ── ref_exposure_mappings ─────────────────────────────────────── */

    private function seedExposureMappings(): void
    {
        $rows = [];
        $now = $this->now();
        foreach ($this->loadJson('exposure_mappings.json') as $r) {
            $rows[] = [
                'exposure_code' => substr((string) $r['exposure_code'], 0, 80),
                'engine_code'   => substr((string) $r['engine_code'],   0, 80),
                'priority'      => (int) ($r['priority'] ?? 0),
                'is_active'     => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }
        $this->upsertChunked('ref_exposure_mappings', $rows, ['exposure_code', 'engine_code'], [
            'priority', 'is_active', 'updated_at',
        ]);
    }

    /* ── ref_engine_config ─────────────────────────────────────────── */

    private function seedEngineConfig(): void
    {
        $rows = [];
        $now = $this->now();
        foreach ($this->loadJson('engine_config.json') as $r) {
            $rows[] = [
                'config_key'   => substr((string) $r['config_key'], 0, 120),
                'description'  => $r['description'] ?? null,
                'config_value' => $this->j($r['config_value'] ?? null),
                'version'      => $r['version'] ?? null,
                'section'      => $r['section'] ?? null,
                'is_active'    => true,
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }
        $this->upsertChunked('ref_engine_config', $rows, ['config_key'], [
            'description', 'config_value', 'version', 'section', 'is_active', 'updated_at',
        ]);
    }

    /* ── ref_endemic_countries ─────────────────────────────────────── */

    private function seedEndemicCountries(): void
    {
        $rows = [];
        $now = $this->now();
        foreach ($this->loadJson('endemic_countries.json') as $r) {
            $rows[] = [
                'disease_code'     => substr((string) $r['disease_code'], 0, 80),
                'country_code'     => substr((string) $r['country_code'], 0, 3),
                'country_name'     => $r['country_name'] ?? null,
                'endemicity_level' => $this->normaliseEndemicity($r['endemicity_level'] ?? 'ENDEMIC'),
                'since_year'       => $r['since_year'] ?? null,
                'source'           => $r['source'] ?? null,
                'last_verified_at' => $r['last_verified_at'] ?? null,
                'payload'          => $this->j($r['payload'] ?? null),
                'is_active'        => true,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }
        $this->upsertChunked('ref_endemic_countries', $rows, ['disease_code', 'country_code'], [
            'country_name', 'endemicity_level', 'since_year', 'source',
            'last_verified_at', 'payload', 'is_active', 'updated_at',
        ]);
    }

    private function normaliseEndemicity(string $v): string
    {
        $v = strtoupper(trim($v));
        $allowed = ['ENDEMIC', 'OUTBREAK_ACTIVE', 'OUTBREAK_RECENT', 'SPORADIC', 'IMPORTED_ONLY'];
        return in_array($v, $allowed, true) ? $v : 'ENDEMIC';
    }

    /* ── chunked upsert ────────────────────────────────────────────── */

    /**
     * Chunked upsert.  MySQL has a 65 535-placeholder cap; chunking at
     * 200 rows keeps us comfortably under the limit even for the widest
     * row in this seeder (ref_diseases ≈ 17 columns ⇒ 3400 placeholders).
     */
    private function upsertChunked(string $table, array $rows, array $uniqueBy, array $update): void
    {
        if (empty($rows)) return;
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy, $update);
        }
    }
}
