<?php

declare(strict_types=1);

namespace Database\Seeders\Country;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * phase B — users + user_assignments seeder.
 * Password placeholder "ChangeMe!2026" hashed via Laravel Hash facade.
 */
class UsersSeeder extends AbstractCountrySeeder
{
    public function run(): void
    {
        $countryName = $this->profile()['name'];
        $rows = $this->loadJson('users.json');

        $hash = Hash::make('ChangeMe!2026');
        $inserted = 0;

        foreach ($rows as $u) {
            $email = (string) $u['email'];
            $username = Str::before($email, '@');
            $role = (string) ($u['role'] ?? 'SCREENER');
            $accountType = match ($role) {
                'NATIONAL_ADMIN' => 'NATIONAL_ADMIN',
                'PHEOC'          => 'PHEOC_ADMIN',
                'DISTRICT'       => 'DISTRICT_ADMIN',
                'SCREENER'       => 'POE_OFFICER',
                default          => 'POE_OFFICER',
            };
            $name = (string) $u['name'];

            $userInsert = [
                'role_key'      => $role,
                'country_code'  => $this->iso2, // ZM/UG/RW (matches scope-default form)
                'full_name'     => $name,
                'name'          => $name,
                'username'      => $username,
                'email'         => $email,
                'phone'         => null,
                'is_active'     => 1,
                'password'      => $hash,
                'password_hash' => $hash,
                'account_type'  => $accountType,
                'updated_at'    => now(),
                'created_at'    => now(),
            ];
            // tolerate columns that may not exist on minimal schema
            $userInsert = $this->projectToColumns('users', $userInsert);

            $existing = DB::table('users')->where('email', $email)->first();
            if ($existing) {
                DB::table('users')->where('id', $existing->id)->update(array_diff_key($userInsert, ['created_at' => 1]));
                $userId = (int) $existing->id;
            } else {
                $userId = (int) DB::table('users')->insertGetId($userInsert);
            }

            $assignment = [
                'user_id'       => $userId,
                'country_code'  => $this->iso2,
                'province_code' => $u['province_code'] ?? null,
                'district_code' => $u['district'] ?? null,
                'is_primary'    => 1,
                'is_active'     => 1,
                'updated_at'    => now(),
                'created_at'    => now(),
            ];
            $assignment = $this->projectToColumns('user_assignments', $assignment);

            DB::table('user_assignments')
                ->updateOrInsert(
                    ['user_id' => $userId, 'is_primary' => 1],
                    $assignment
                );
            $inserted++;
        }

        $this->info("users: {$inserted} seeded with primary assignments");
    }

    private function projectToColumns(string $table, array $row): array
    {
        $cols = Schema::getColumnListing($table);
        return array_intersect_key($row, array_flip($cols));
    }
}
