<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\Country\GeographySeeder;
use Database\Seeders\Country\HospitalsSeeder;
use Database\Seeders\Country\PoesSeeder;
use Database\Seeders\Country\UsersSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * phase B — country:wipe-and-seed
 *
 * Wipes the per-country reference + identity tables and reseeds them
 * from api/database/seeders/country/<ISO2>/data/*.json. Idempotent —
 * running twice produces the same state.
 *
 * Refuses without --force when APP_ENV=production.
 */
class CountryWipeAndSeed extends Command
{
    protected $signature = 'country:wipe-and-seed {iso2 : Country ISO-2 (ZM|UG|RW)} {--force : Required in production} {--ref-only : Skip users/user_assignments wipe and re-seed (preserve live identity data)}';

    protected $description = 'Wipe per-country reference + identity tables and reseed from JSON.';

    /** @var array<int,string> */
    private const COUNTRY_TABLES = [
        // identity (FKs in user_assignments → users, so wipe assignments first)
        'user_assignments',
        'users',
        // reference data
        'ref_traveler_notes',
        'ref_geo_metadata',
        'ref_geo_version',
        'ref_hospitals',
        'ref_poes',
        'ref_districts',
        'ref_provinces',
        'ref_countries',
    ];

    public function handle(): int
    {
        $iso2 = strtoupper((string) $this->argument('iso2'));
        if (!in_array($iso2, ['ZM', 'UG', 'RW'], true)) {
            $this->error("Unknown ISO2 '{$iso2}'. Use ZM|UG|RW.");
            return self::FAILURE;
        }

        if (app()->environment('production') && !$this->option('force')) {
            $this->error('Refusing to run in production without --force.');
            return self::FAILURE;
        }

        $dataDir = database_path("seeders/country/{$iso2}/data");
        foreach (['geography.json', 'hospitals.json', 'poes.json', 'users.json'] as $f) {
            if (!is_file("{$dataDir}/{$f}")) {
                $this->error("Missing data file: {$dataDir}/{$f}");
                return self::FAILURE;
            }
        }

        $this->info("Wiping country-scoped tables for {$iso2}…");

        // TRUNCATE auto-commits on MySQL, so we cannot wrap it in a transaction.
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        } elseif ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }
        $tablesToWipe = self::COUNTRY_TABLES;
        if ($this->option('ref-only')) {
            $tablesToWipe = array_values(array_diff($tablesToWipe, ['user_assignments', 'users']));
        }
        try {
            foreach ($tablesToWipe as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }
        } finally {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON');
            }
        }

        $this->info("Seeding {$iso2}…");

        $seederClasses = $this->option('ref-only')
            ? [GeographySeeder::class, HospitalsSeeder::class, PoesSeeder::class]
            : [GeographySeeder::class, HospitalsSeeder::class, PoesSeeder::class, UsersSeeder::class];

        foreach ($seederClasses as $cls) {
            $seeder = new $cls();
            $seeder->setIso2($iso2);
            $seeder->setCommand($this);
            $seeder->run();
        }

        $this->newLine();
        $this->info("✔ country:wipe-and-seed {$iso2} complete.");
        $this->table(['table', 'rows'], [
            ['ref_countries',  DB::table('ref_countries')->count()],
            ['ref_provinces',  DB::table('ref_provinces')->count()],
            ['ref_districts',  DB::table('ref_districts')->count()],
            ['ref_hospitals',  DB::table('ref_hospitals')->count()],
            ['ref_poes',       DB::table('ref_poes')->count()],
            ['users',          DB::table('users')->count()],
            ['user_assignments', DB::table('user_assignments')->count()],
        ]);

        return self::SUCCESS;
    }
}
