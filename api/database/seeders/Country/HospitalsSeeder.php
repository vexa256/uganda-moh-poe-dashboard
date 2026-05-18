<?php

declare(strict_types=1);

namespace Database\Seeders\Country;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * phase B — hospitals seeder.
 * Resolves province_id by province code, district_id by district code,
 * generates a stable per-country unique `code` for ref_hospitals.
 */
class HospitalsSeeder extends AbstractCountrySeeder
{
    public function run(): void
    {
        $countryName = $this->profile()['name'];
        $rows = $this->loadJson('hospitals.json');

        $provLookup = DB::table('ref_provinces')
            ->where('country_code', $countryName)
            ->pluck('id', 'code')
            ->all();

        // District lookup keyed by: full code, raw code (after last dash), uppercase name.
        $distLookup = [];
        foreach (DB::table('ref_districts')->where('country_code', $countryName)->get(['id','code','name']) as $d) {
            $distLookup[$d->code] = $d->id;
            $rawCode = (string) Str::afterLast($d->code, '-');
            $distLookup[$rawCode] = $d->id;
            $distLookup[strtoupper($rawCode)] = $d->id;
            $distLookup[strtoupper($d->name)] = $d->id;
        }

        $countByDistrict = [];
        $inserted = 0; $skipped = 0;

        foreach ($rows as $idx => $h) {
            $pCode = (string) ($h['province'] ?? '');
            $dCode = strtoupper((string) ($h['district'] ?? ''));

            $provinceId = $provLookup[$pCode] ?? null;
            $districtId = $distLookup[$dCode] ?? $distLookup[strtoupper($dCode)] ?? null;

            if ($provinceId === null) { $skipped++; continue; }

            $level = (string) ($h['level'] ?? 'GENERAL');
            $isNational = $level === 'NATIONAL_REFERRAL';

            $countByDistrict[$dCode] = ($countByDistrict[$dCode] ?? 0) + 1;
            $code = sprintf('%s-%s-%03d',
                $this->iso2,
                substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($dCode ?: 'XX')), 0, 8) ?: 'XX',
                $countByDistrict[$dCode]
            );

            DB::table('ref_hospitals')->updateOrInsert(
                ['country_code' => $countryName, 'code' => $code],
                [
                    'province_id'       => $provinceId,
                    'district_id'       => $districtId,
                    'name'              => (string) $h['name'],
                    'hospital_type'     => $this->mapType($level),
                    'is_national_level' => $isNational ? 1 : 0,
                    'is_active'         => 1,
                    'display_order'     => $idx + 1,
                    'latitude'          => $h['lat'] ?? null,
                    'longitude'         => $h['lon'] ?? null,
                    'phone'             => $h['phone'] ?? null,
                    'metadata_json'     => json_encode([
                        'ownership'    => $h['ownership'] ?? null,
                        'bed_capacity' => $h['bed_capacity'] ?? null,
                        'confidence'   => $h['confidence'] ?? null,
                    ]),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $inserted++;
        }

        $this->info("hospitals: {$inserted} seeded, {$skipped} skipped (missing province FK)");
    }

    private function mapType(string $level): string
    {
        return match ($level) {
            'NATIONAL_REFERRAL', 'REGIONAL_REFERRAL' => 'TEACHING',
            'GENERAL'   => 'GENERAL',
            'DISTRICT'  => 'DISTRICT',
            'HC_IV', 'HC_III' => 'CLINIC',
            'PRIVATE'   => 'PRIVATE',
            default     => 'OTHER',
        };
    }
}
