<?php

declare(strict_types=1);

namespace Database\Seeders\Country;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * phase B — POEs seeder. Maps research-JSON 'type' to ref_poes.poe_type+transport_mode enums.
 */
class PoesSeeder extends AbstractCountrySeeder
{
    /** @var array<string,int> */
    private array $districtLookup = [];

    public function run(): void
    {
        $countryName = $this->profile()['name'];
        $rows = $this->loadJson('poes.json');

        $provLookup = DB::table('ref_provinces')
            ->where('country_code', $countryName)
            ->pluck('id', 'code')->all();

        $this->districtLookup = [];
        foreach (DB::table('ref_districts')->where('country_code', $countryName)->get(['id','code','name']) as $d) {
            $this->districtLookup[$d->code] = $d->id;
            $raw = (string) Str::afterLast($d->code, '-');
            $this->districtLookup[$raw] = $d->id;
            $this->districtLookup[strtoupper($raw)] = $d->id;
            $this->districtLookup[strtoupper($d->name)] = $d->id;
        }

        $countByDistrict = [];
        $inserted = 0;

        foreach ($rows as $idx => $p) {
            $type = strtoupper((string) ($p['type'] ?? 'LAND'));
            [$poeType, $transport] = match ($type) {
                'AIR'   => ['airport', 'air'],
                'LAND'  => ['land_border', 'land'],
                'SEA'   => ['port', 'water'],
                'LAKE'  => ['port', 'water'],
                'RAIL'  => ['rail', 'rail'],
                default => ['other', 'other'],
            };

            $pCode = (string) ($p['province'] ?? '');
            $dCode = strtoupper((string) ($p['district'] ?? ''));
            $provinceId = $provLookup[$pCode] ?? null;
            $districtId = $this->districtLookup[$dCode] ?? $this->districtLookup[strtoupper($dCode)] ?? null;

            $countByDistrict[$dCode] = ($countByDistrict[$dCode] ?? 0) + 1;
            $code = sprintf('%s-%s-%03d',
                $this->iso2,
                substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($dCode ?: 'XX')), 0, 8) ?: 'XX',
                $countByDistrict[$dCode]
            );

            // Resolve human-readable province + district names so the
            // bundle payload (read by GeoHierarchyController::assembleBundle)
            // carries the strings the mobile app expects.
            $provinceName = (string) DB::table('ref_provinces')->where('id', $provinceId)->value('name');
            $districtName = (string) DB::table('ref_districts')->where('id', $districtId)->value('name');
            $sourceOrigin = (string) config('app.country_source_origin', $countryName);

            // Honor authoritative JSON fields; fall back to AIR-defaults for legacy seeds.
            $isMajor    = isset($p['is_major_entry'])   ? (bool) $p['is_major_entry']   : ($type === 'AIR');
            $isNational = isset($p['is_national_level']) ? (bool) $p['is_national_level'] : ($type === 'AIR');
            $isOsbp     = isset($p['is_osbp'])           ? (bool) $p['is_osbp']           : false;
            $border     = $p['border_country'] ?? null;

            // Legacy POEs.js byte-preserved payload shape — keys must
            // match GeoHierarchyController::POE_PAYLOAD_KEY_ORDER exactly.
            $bundlePayload = [
                'id'                         => $code,
                'country'                    => $countryName,
                'province'                   => $provinceName,
                'admin_level_1'              => $provinceName,
                'admin_level_1_type'         => 'PROVINCE',
                'district'                   => $districtName,
                'district_raw'               => $districtName,
                'poe_name'                   => (string) $p['name'],
                'poe_code'                   => $code,
                'poe_type'                   => $poeType,
                'transport_mode'             => $transport,
                'border_country'             => $border,
                'is_major_entry'             => $isMajor,
                'is_recommended_osbp'        => $isOsbp,
                'is_national_level'          => $isNational,
                'regional_cluster_or_rpheoc' => $provinceName,
                'critical_details'           => '',
                'source_province_group'      => $provinceName,
                'source_url'                 => '',
                'source_origin'              => $sourceOrigin,
                // Operational fields appended after the SSoT keys
                'opening_hours'              => $p['opening_hours'] ?? null,
                'iata'                       => $p['iata'] ?? null,
                'icao'                       => $p['icao'] ?? null,
                'status'                     => $p['status'] ?? 'OPEN',
                'latitude'                   => $p['lat'] ?? null,
                'longitude'                  => $p['lon'] ?? null,
            ];

            DB::table('ref_poes')->updateOrInsert(
                ['country_code' => $countryName, 'poe_code' => $code],
                [
                    'poe_name'            => (string) $p['name'],
                    'admin_level_1'       => $provinceName,
                    'admin_level_1_type'  => 'PROVINCE',
                    'province_id'         => $provinceId,
                    'district'            => $districtName ?: null,
                    'district_id'         => $districtId,
                    'poe_type'            => $poeType,
                    'transport_mode'      => $transport,
                    'is_national_level'   => $isNational ? 1 : 0,
                    'is_major_entry'      => $isMajor    ? 1 : 0,
                    'is_recommended_osbp' => $isOsbp     ? 1 : 0,
                    'border_country'      => $border,
                    'is_active'           => ($p['status'] ?? 'OPEN') === 'OPEN' ? 1 : 0,
                    'latitude'            => $p['lat'] ?? null,
                    'longitude'           => $p['lon'] ?? null,
                    'display_order'       => $idx + 1,
                    'payload'             => json_encode($bundlePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at'          => now(),
                    'created_at'          => now(),
                ]
            );
            $inserted++;
        }

        $this->info("poes: {$inserted} seeded");
    }
}
