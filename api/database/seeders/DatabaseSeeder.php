<?php

namespace Database\Seeders;

use Database\Seeders\Country\GeographySeeder;
use Database\Seeders\Country\HospitalsSeeder;
use Database\Seeders\Country\PoesSeeder;
use Database\Seeders\Country\UsersSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database — Uganda POE Sentinel deployment.
     *
     * Drives off database/seeders/country/UG/data/*.json.
     */
    public function run(): void
    {
        // Generic reference data (diseases / symptoms / exposures / engine
        // config / exposure mappings / endemic countries). Idempotent.
        $this->call(ReferenceDataSeeder::class);

        $iso2 = 'UG';

        foreach ([GeographySeeder::class, PoesSeeder::class, HospitalsSeeder::class, UsersSeeder::class] as $cls) {
            /** @var \Database\Seeders\Country\AbstractCountrySeeder $seeder */
            $seeder = new $cls();
            $seeder->setIso2($iso2);
            $seeder->setCommand($this->command);
            $seeder->run();
        }

        // (Suite test fixture removed — the production users table on this
        // tenant lacks the `remember_token` column the auth scaffold uses.)
    }
}
