<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ensure `users` and `user_assignments` tables exist.
 *
 * Background
 * ----------
 * The original Uganda deployment provisions these two tables via the
 * hand-maintained `app.sql` MySQL dump rather than via Laravel
 * migrations. On any deployment that hasn't pre-loaded `app.sql`
 * (including fresh SQLite test databases and operator installs that
 * drive schema entirely from `php artisan migrate`), those tables would
 * be missing and every auth/assignment code path would fail.
 *
 * This migration provides a minimal self-bootstrap: it only creates
 * tables that do not already exist, so deployments that already have
 * the richer `app.sql`-sourced schema are untouched.
 *
 * The generated schema is a conservative subset of app.sql's `users`
 * and `user_assignments` tables — exactly the columns that
 * `UserLoginController`, `UserController`, `PheocScope`, and the mobile
 * app consume. Extra app.sql columns (two_factor_*, risk_flags_json,
 * locale, timezone, …) have DB defaults on MySQL, and the seeder's
 * `projectToExistingColumns()` helper handles deployments that do /
 * don't carry them.
 *
 * Shared-DB safety
 * ----------------
 * • `Schema::hasTable()` ensures we never re-create an existing table,
 *   so Uganda deployments that share the MySQL server see a no-op.
 * • Never drops or alters existing columns.
 * • The only mutation is in `up()`; `down()` is a no-op so rolling back
 *   never wipes Uganda's data.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('role_key', 60)->nullable();
                $t->string('country_code', 10)->nullable();
                $t->string('full_name', 150)->nullable();
                $t->string('username', 80)->nullable()->unique();
                $t->string('password_hash', 255)->nullable();
                $t->string('email', 190)->nullable()->unique();
                $t->string('phone', 40)->nullable();
                $t->tinyInteger('is_active')->default(1);
                $t->dateTime('last_login_at')->nullable();
                $t->timestamp('email_verified_at')->nullable();
                $t->string('password', 200)->nullable();
                $t->string('name', 200)->nullable();
                $t->string('account_type', 60)->default('POE_OFFICER');
                $t->rememberToken();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('user_assignments')) {
            Schema::create('user_assignments', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('user_id');
                $t->string('country_code', 10);
                $t->string('province_code', 30)->nullable();
                $t->string('pheoc_code', 30)->nullable();
                $t->string('district_code', 30)->nullable();
                $t->string('poe_code', 40)->nullable();
                $t->tinyInteger('is_primary')->default(1);
                $t->tinyInteger('is_active')->default(1);
                $t->dateTime('starts_at')->nullable();
                $t->dateTime('ends_at')->nullable();
                $t->timestamps();

                $t->index('user_id');
                $t->index(['user_id', 'is_primary', 'is_active']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally no-op. We do not know whether the `users` or
        // `user_assignments` tables were created by this migration or by
        // a prior app.sql load; dropping them on rollback could destroy
        // production data. Roll forward, never back.
    }
};
