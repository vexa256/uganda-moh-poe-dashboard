<?php

declare(strict_types=1);

namespace Database\Seeders\Country;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * phase B — geography seeder (provinces + districts + ref_countries row).
 * Idempotent. Resolves district FK via ref_provinces.id.
 */
class GeographySeeder extends AbstractCountrySeeder
{
    public function run(): void
    {
        $profile = $this->profile();
        $countryName = $profile['name']; // canonical SSoT form, e.g. 'Uganda'

        // ref_countries — 1 row per deployment
        DB::table('ref_countries')->updateOrInsert(
            ['country_code' => $countryName],
            [
                'iso_alpha2'    => $this->iso2,
                'iso_alpha3'    => $profile['iso3'],
                'name'          => $countryName,
                'is_active'     => 1,
                'display_order' => 1,
                'updated_at'    => now(),
                'created_at'    => now(),
            ]
        );

        $rows = $this->loadJson('geography.json');
        $provDisplay = 0; $distDisplay = 0;
        /** @var array<string,bool> by lowercased district name */
        $distSeen = [];

        foreach ($rows as $province) {
            $pCode = (string) $province['code'];
            $pName = (string) $province['name'];

            DB::table('ref_provinces')->updateOrInsert(
                ['country_code' => $countryName, 'code' => $pCode],
                [
                    'name'              => $pName,
                    'admin_level_1_type'=> 'PROVINCE',
                    'is_active'         => 1,
                    'display_order'     => ++$provDisplay,
                    'updated_at'        => now(),
                    'created_at'        => now(),
                ]
            );

            $provinceId = (int) DB::table('ref_provinces')
                ->where('country_code', $countryName)
                ->where('code', $pCode)
                ->value('id');

            foreach (($province['districts'] ?? []) as $dist) {
                $rawCode = (string) $dist['code'];
                $dName   = (string) $dist['name'];

                // Globally-unique district code is "<province-code>-<district-code>".
                $dCode = "{$pCode}-{$rawCode}";

                // dedupe by global code AND by name within country (e.g. ZM Chama listed twice).
                $key = strtolower($dName);
                if (isset($distSeen[$key])) continue;
                $distSeen[$key] = true;

                DB::table('ref_districts')->updateOrInsert(
                    ['country_code' => $countryName, 'code' => $dCode],
                    [
                        'province_id'    => $provinceId,
                        'name'           => $dName,
                        'name_raw'       => $dName,
                        'is_active'      => 1,
                        'display_order'  => ++$distDisplay,
                        'updated_at'     => now(),
                        'created_at'     => now(),
                    ]
                );
            }
        }

        $this->info("geography: {$provDisplay} provinces / {$distDisplay} districts");
    }
}
