<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds one NATIONAL_ADMIN and one sample PHEOC_OFFICER so the admin
 * login page has working credentials out of the box.
 *
 * Idempotent: UPSERTs by email. Safe to re-run.
 *
 *   php artisan db:seed --class=Database\\Seeders\\AdminUserSeeder
 *
 * Credentials (rotate before production):
 *   NATIONAL_ADMIN   admin@pheoc.go.ug   · admin1234
 *   PHEOC_OFFICER    central@pheoc.go.ug · central1234  (scoped to Central Region PHEOC)
 *   DISTRICT_SUPER   kampala@pheoc.go.ug · kampala1234  (scoped to Kampala District)
 */
final class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedUser([
            'email'        => 'admin@pheoc.go.ug',
            'username'     => 'admin',
            'full_name'    => 'National Administrator',
            'name'         => 'National Administrator',
            'role_key'     => 'NATIONAL_ADMIN',
            'account_type' => 'NATIONAL_ADMIN',
            'country_code' => 'Uganda',
            'phone'        => '+256 414 111 111',
            'password'     => 'admin1234',
        ], [
            // primary assignment: NATIONAL_ADMIN — no scope (sees all UG data)
            'country_code'  => 'Uganda',
            'province_code' => null,
            'pheoc_code'    => null,
            'district_code' => null,
            'poe_code'      => null,
        ]);

        $this->seedUser([
            'email'        => 'central@pheoc.go.ug',
            'username'     => 'central',
            'full_name'    => 'Central Region PHEOC Officer',
            'name'         => 'Central Region PHEOC Officer',
            'role_key'     => 'PHEOC_OFFICER',
            // users.account_type is a strict ENUM (PHEOC_ADMIN is the enum
            // member; PHEOC_OFFICER lives only in role_key/role_registry).
            'account_type' => 'PHEOC_ADMIN',
            'country_code' => 'Uganda',
            'phone'        => '+256 414 222 222',
            'password'     => 'central1234',
        ], [
            'country_code'  => 'Uganda',
            'province_code' => 'UG-C',
            'pheoc_code'    => 'UG-C',
            'district_code' => 'KAMPALA',
            'poe_code'      => null,
        ]);

        $this->seedUser([
            'email'        => 'kampala@pheoc.go.ug',
            'username'     => 'kampala',
            'full_name'    => 'Kampala District Supervisor',
            'name'         => 'Kampala District Supervisor',
            'role_key'     => 'DISTRICT_SUPERVISOR',
            // users.account_type is a strict ENUM; DISTRICT_ADMIN is the
            // enum-compatible value while role_key carries the official label.
            'account_type' => 'DISTRICT_ADMIN',
            'country_code' => 'Uganda',
            'phone'        => '+256 414 333 333',
            'password'     => 'kampala1234',
        ], [
            'country_code'  => 'Uganda',
            'province_code' => 'UG-C',
            'pheoc_code'    => 'UG-C',
            'district_code' => 'KAMPALA',
            'poe_code'      => null,
        ]);

        $this->command->info('AdminUserSeeder: 3 users upserted (admin / central / kampala).');
    }

    private function seedUser(array $user, array $assignment): void
    {
        $now  = now();
        $hash = Hash::make($user['password']);

        $existing = DB::table('users')->where('email', $user['email'])->first();

        $payload = [
            'email'         => $user['email'],
            'username'      => $user['username'],
            'full_name'     => $user['full_name'],
            'name'          => $user['name'],
            'role_key'      => $user['role_key'],
            'account_type'  => $user['account_type'],
            'country_code'  => $user['country_code'],
            'phone'         => $user['phone'],
            'is_active'     => 1,
            'password'      => $hash,
            'password_hash' => $hash,
            'updated_at'    => $now,
        ];

        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update($payload);
            $uid = (int) $existing->id;
        } else {
            $payload['created_at'] = $now;
            $uid = (int) DB::table('users')->insertGetId($payload);
        }

        // Upsert the primary assignment (one per user for now).
        DB::table('user_assignments')
            ->updateOrInsert(
                ['user_id' => $uid, 'is_primary' => 1],
                array_merge($assignment, [
                    'user_id'    => $uid,
                    'is_primary' => 1,
                    'is_active'  => 1,
                    'starts_at'  => $now,
                    'ends_at'    => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ])
            );
    }
}
