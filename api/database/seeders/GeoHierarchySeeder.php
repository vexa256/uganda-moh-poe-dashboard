<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * GeoHierarchySeeder — hydrates ref_countries / ref_provinces /
 * ref_districts / ref_hospitals (empty by default) / ref_geo_metadata /
 * ref_traveler_notes / ref_geo_version from the canonical window.POE_MAIN
 * blob in src/POEs.js.
 *
 * Also back-fills the newly added province_id / district_id FK columns
 * on the pre-existing ref_poes rows and normalises display_order to
 * preserve the exact ordering of POEs.js.
 *
 * Idempotent — every insert is an upsert keyed on business identity.
 */
class GeoHierarchySeeder extends Seeder
{
    /** Absolute path to src/POEs.js (two dirs up from base_path()/database/seeders). */
    private const SOURCE_JS = __DIR__ . '/../../../src/POEs.js';

    public function run(): void
    {
        $bundle = $this->loadPoeMainBundle();
        $now    = Carbon::now();

        // ─── ref_countries ──────────────────────────────────────────
        // Source: window.POE_MAIN.metadata.countries[]
        $countries = $bundle['metadata']['countries'] ?? [];
        foreach ($countries as $idx => $countryName) {
            DB::table('ref_countries')->updateOrInsert(
                ['country_code' => $countryName],
                [
                    'name'          => $countryName,
                    'iso_alpha2'    => match ($countryName) {
                        'Uganda' => 'UG', 'Zambia' => 'ZM', 'Rwanda' => 'RW',
                        'Kenya' => 'KE', 'Tanzania' => 'TZ', 'South Sudan' => 'SS', 'DRC' => 'CD',
                        default => null,
                    },
                    'iso_alpha3'    => match ($countryName) {
                        'Uganda' => 'UGA', 'Zambia' => 'ZMB', 'Rwanda' => 'RWA',
                        'Kenya' => 'KEN', 'Tanzania' => 'TZA', 'South Sudan' => 'SSD', 'DRC' => 'COD',
                        default => null,
                    },
                    'is_active'     => 1,
                    'display_order' => $idx + 1,
                    'updated_at'    => $now,
                    'created_at'    => $now,
                ]
            );
        }

        // ─── ref_provinces ──────────────────────────────────────────
        // Source: window.POE_MAIN.administrative_groups[] — each entry is
        // (country, admin_level_1 "name", admin_level_1_type, districts[]).
        $provinceOrder = 0;
        foreach ($bundle['administrative_groups'] ?? [] as $group) {
            $provinceOrder++;
            $countryCode = $group['country'] ?? '';
            $provinceName = $group['admin_level_1'] ?? '';
            $typeName = $group['admin_level_1_type'] ?? 'PHEOC';
            if ($countryCode === '' || $provinceName === '') {
                continue;
            }
            $provinceCode = $this->slug($provinceName);
            DB::table('ref_provinces')->updateOrInsert(
                ['country_code' => $countryCode, 'code' => $provinceCode],
                [
                    'name'               => $provinceName,
                    'admin_level_1_type' => $typeName,
                    'is_active'          => 1,
                    'display_order'      => $provinceOrder,
                    'updated_at'         => $now,
                    'created_at'         => $now,
                ]
            );
        }

        // Cache province_id map
        $provinceIds = DB::table('ref_provinces')
            ->select('id', 'country_code', 'name')
            ->get()
            ->groupBy('country_code')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->name => (int) $r->id])->all())
            ->all();

        // ─── ref_districts ──────────────────────────────────────────
        // Source: admin_group.districts[]
        $districtOrder = 0;
        foreach ($bundle['administrative_groups'] ?? [] as $group) {
            $countryCode = $group['country'] ?? '';
            $provinceName = $group['admin_level_1'] ?? '';
            $pid = $provinceIds[$countryCode][$provinceName] ?? null;
            if (!$pid) {
                continue;
            }
            foreach ($group['districts'] ?? [] as $districtName) {
                $districtOrder++;
                $nameRaw = preg_replace('/\s+District\s*$/u', '', $districtName);
                $districtCode = $this->slug($districtName);
                DB::table('ref_districts')->updateOrInsert(
                    ['country_code' => $countryCode, 'code' => $districtCode],
                    [
                        'province_id'   => $pid,
                        'name'          => $districtName,
                        'name_raw'      => $nameRaw,
                        'is_active'     => 1,
                        'display_order' => $districtOrder,
                        'updated_at'    => $now,
                        'created_at'    => $now,
                    ]
                );
            }
        }

        // Cache district_id map
        $districtIds = DB::table('ref_districts')
            ->select('id', 'country_code', 'name')
            ->get()
            ->groupBy('country_code')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [$r->name => (int) $r->id])->all())
            ->all();

        // ─── ref_poes — upsert from bundle + back-fill FKs ──────────
        // Source: window.POE_MAIN.poes[]
        $poeOrder = 0;
        foreach ($bundle['poes'] ?? [] as $poe) {
            $poeOrder++;
            $country = $poe['country'] ?? '';
            if ($country === '') {
                continue;
            }
            $provinceName = $poe['admin_level_1'] ?? ($poe['province'] ?? '');
            $districtName = $poe['district'] ?? '';
            $pid = $provinceIds[$country][$provinceName] ?? null;
            $did = $districtIds[$country][$districtName] ?? null;

            $externalId = $poe['id'] ?? null;
            $poeType = $poe['poe_type'] ?? 'land_border';
            $mode = $poe['transport_mode'] ?? 'land';

            // Build payload byte-for-byte in the key order POEs.js uses.
            $payload = [
                'id'                         => $poe['id'] ?? null,
                'country'                    => $poe['country'] ?? null,
                'province'                   => $poe['province'] ?? null,
                'admin_level_1'              => $poe['admin_level_1'] ?? null,
                'admin_level_1_type'         => $poe['admin_level_1_type'] ?? null,
                'district'                   => $poe['district'] ?? null,
                'district_raw'               => $poe['district_raw'] ?? null,
                'poe_name'                   => $poe['poe_name'] ?? null,
                'poe_code'                   => $poe['poe_code'] ?? null,
                'poe_type'                   => $poe['poe_type'] ?? null,
                'transport_mode'             => $poe['transport_mode'] ?? null,
                'border_country'             => array_key_exists('border_country', $poe) ? $poe['border_country'] : null,
                'is_major_entry'             => (bool) ($poe['is_major_entry'] ?? false),
                'is_recommended_osbp'        => (bool) ($poe['is_recommended_osbp'] ?? false),
                'is_national_level'          => (bool) ($poe['is_national_level'] ?? false),
                'regional_cluster_or_rpheoc' => $poe['regional_cluster_or_rpheoc'] ?? null,
                'critical_details'           => $poe['critical_details'] ?? null,
                'source_province_group'      => $poe['source_province_group'] ?? null,
                'source_url'                 => $poe['source_url'] ?? null,
                'source_origin'              => $poe['source_origin'] ?? null,
            ];

            $row = [
                'country_code'       => $country,
                'poe_code'           => $poe['poe_code'] ?? $externalId,
                'poe_name'           => $poe['poe_name'] ?? '',
                'admin_level_1'      => $provinceName ?: null,
                'admin_level_1_type' => $poe['admin_level_1_type'] ?? null,
                'province_id'        => $pid,
                'district'           => $districtName ?: null,
                'district_id'        => $did,
                'poe_type'           => $poeType,
                'transport_mode'     => $mode,
                'regional_cluster'   => $poe['regional_cluster_or_rpheoc'] ?? null,
                'is_national_level'  => (int) (bool) ($poe['is_national_level'] ?? false),
                'is_major_entry'     => (int) (bool) ($poe['is_major_entry'] ?? false),
                'is_recommended_osbp'=> (int) (bool) ($poe['is_recommended_osbp'] ?? false),
                'border_country'     => array_key_exists('border_country', $poe) ? $poe['border_country'] : null,
                'gazette_source'     => $poe['source_url'] ?? null,
                'payload'            => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'is_active'          => 1,
                'display_order'      => $poeOrder,
                'updated_at'         => $now,
            ];

            if ($externalId) {
                $existing = DB::table('ref_poes')->where('external_id', $externalId)->first();
                if ($existing) {
                    DB::table('ref_poes')->where('id', $existing->id)->update($row);
                } else {
                    DB::table('ref_poes')->insert(array_merge($row, [
                        'external_id' => $externalId,
                        'created_at'  => $now,
                    ]));
                }
            } else {
                // Fallback: key on country_code + poe_code when external_id absent
                $existing = DB::table('ref_poes')
                    ->where('country_code', $country)
                    ->where('poe_code', $row['poe_code'])
                    ->first();
                if ($existing) {
                    DB::table('ref_poes')->where('id', $existing->id)->update($row);
                } else {
                    DB::table('ref_poes')->insert(array_merge($row, ['created_at' => $now]));
                }
            }
        }

        // ─── ref_geo_metadata ───────────────────────────────────────
        $this->upsertMetadata($bundle['metadata'] ?? [], $now);

        // ─── ref_traveler_notes ─────────────────────────────────────
        $this->upsertTravelerNotes($bundle['metadata']['countries'][0] ?? 'Uganda', $bundle['traveler_notes'] ?? [], $now);

        // ─── ref_geo_version ────────────────────────────────────────
        foreach ($countries as $countryName) {
            DB::table('ref_geo_version')->updateOrInsert(
                ['country_code' => $countryName],
                [
                    'version'       => DB::raw('COALESCE(version, 0) + 1'),
                    'etag'          => null,
                    'last_built_at' => $now,
                    'updated_at'    => $now,
                    'created_at'    => $now,
                ]
            );
        }
    }

    private function upsertMetadata(array $metadata, Carbon $now): void
    {
        $countryName = ($metadata['countries'][0] ?? 'Uganda');
        $order = 0;
        foreach ($metadata as $key => $value) {
            $order++;
            DB::table('ref_geo_metadata')->updateOrInsert(
                ['country_code' => $countryName, 'meta_key' => $key],
                [
                    'meta_value'    => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'display_order' => $order,
                    'updated_at'    => $now,
                    'created_at'    => $now,
                ]
            );
        }
    }

    private function upsertTravelerNotes(string $countryName, array $notes, Carbon $now): void
    {
        $order = 0;
        foreach ($notes as $key => $note) {
            $order++;
            $type = isset($note['recommended_poes']) ? 'MULTI' : 'SINGLE';
            $recommended = $note['recommended_poes'] ?? ($note['recommended_poe'] ? [$note['recommended_poe']] : []);
            DB::table('ref_traveler_notes')->updateOrInsert(
                ['country_code' => $countryName, 'note_key' => $key],
                [
                    'note_type'              => $type,
                    'recommended_poes_json'  => json_encode($recommended, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'note_text'              => $note['note'] ?? '',
                    'is_active'              => 1,
                    'display_order'          => $order,
                    'updated_at'             => $now,
                    'created_at'             => $now,
                ]
            );
        }
    }

    /**
     * Read src/POEs.js (a `window.POE_MAIN = { … };` assignment) and
     * return the decoded bundle as an associative array.
     */
    private function loadPoeMainBundle(): array
    {
        if (!is_file(self::SOURCE_JS)) {
            throw new RuntimeException('POEs.js not found at ' . self::SOURCE_JS);
        }
        $js = file_get_contents(self::SOURCE_JS);
        if ($js === false || $js === '') {
            throw new RuntimeException('POEs.js is empty');
        }
        // Strip `window.POE_MAIN = ` assignment and any trailing ;
        $json = preg_replace('/^\s*window\.POE_MAIN\s*=\s*/s', '', $js, 1);
        $json = rtrim(trim($json), ';');
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Failed to parse POEs.js as JSON: ' . json_last_error_msg());
        }
        return $decoded;
    }

    private function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s);
        return trim($s, '-');
    }
}
