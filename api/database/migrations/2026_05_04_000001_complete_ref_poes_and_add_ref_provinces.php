<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Uganda fix: ref_poes was created from an older minimal schema that predates
 * the full historical column set.  This migration adds every missing column
 * using hasColumn() guards (idempotent) and creates ref_provinces if absent.
 *
 * All additions are nullable with safe defaults — mobile writers are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── ref_provinces (needed by PoesController meta + PoesSeeder) ──
        if (!Schema::hasTable('ref_provinces')) {
            Schema::create('ref_provinces', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10)->index();
                $t->string('code', 40);
                $t->string('name', 160);
                $t->string('admin_level_1_type', 60)->default('PROVINCE');
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedInteger('display_order')->default(0);
                $t->json('metadata_json')->nullable();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['country_code', 'code'], 'uq_ref_provinces_country_code');
                $t->unique(['country_code', 'name'], 'uq_ref_provinces_country_name');
            });
        }

        // ── ref_geo_version (needed by PoesController::bumpVersion) ──────
        if (!Schema::hasTable('ref_geo_version')) {
            Schema::create('ref_geo_version', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10)->unique();
                $t->unsignedBigInteger('version')->default(1);
                $t->string('etag', 64)->nullable();
                $t->timestamp('last_built_at')->nullable();
                $t->timestamps();
            });
        }

        // ── ref_poes: add every missing column (all nullable / default-safe) ─
        if (Schema::hasTable('ref_poes')) {
            Schema::table('ref_poes', function (Blueprint $t) {
                if (!Schema::hasColumn('ref_poes', 'external_id')) {
                    $t->string('external_id', 60)->nullable()->after('id');
                }
                if (!Schema::hasColumn('ref_poes', 'admin_level_1')) {
                    $t->string('admin_level_1', 160)->nullable()->after('district');
                }
                if (!Schema::hasColumn('ref_poes', 'admin_level_1_type')) {
                    $t->string('admin_level_1_type', 60)->nullable()->after('admin_level_1');
                }
                if (!Schema::hasColumn('ref_poes', 'province_id')) {
                    $t->unsignedBigInteger('province_id')->nullable()->after('admin_level_1_type');
                }
                if (!Schema::hasColumn('ref_poes', 'poe_type')) {
                    $t->string('poe_type', 30)->nullable()->default('land_border')->after('poe_name');
                }
                if (!Schema::hasColumn('ref_poes', 'transport_mode')) {
                    $t->string('transport_mode', 30)->nullable()->default('land')->after('poe_type');
                }
                if (!Schema::hasColumn('ref_poes', 'regional_cluster')) {
                    $t->string('regional_cluster', 160)->nullable()->after('transport_mode');
                }
                if (!Schema::hasColumn('ref_poes', 'is_national_level')) {
                    $t->boolean('is_national_level')->default(false)->after('regional_cluster');
                }
                if (!Schema::hasColumn('ref_poes', 'is_major_entry')) {
                    $t->boolean('is_major_entry')->default(false)->after('is_national_level');
                }
                if (!Schema::hasColumn('ref_poes', 'is_recommended_osbp')) {
                    $t->boolean('is_recommended_osbp')->default(false)->after('is_major_entry');
                }
                if (!Schema::hasColumn('ref_poes', 'border_country')) {
                    $t->string('border_country', 80)->nullable()->after('is_recommended_osbp');
                }
                if (!Schema::hasColumn('ref_poes', 'latitude')) {
                    $t->decimal('latitude', 10, 6)->nullable()->after('border_country');
                }
                if (!Schema::hasColumn('ref_poes', 'longitude')) {
                    $t->decimal('longitude', 10, 6)->nullable()->after('latitude');
                }
                if (!Schema::hasColumn('ref_poes', 'gazette_source')) {
                    $t->text('gazette_source')->nullable()->after('longitude');
                }
                if (!Schema::hasColumn('ref_poes', 'payload')) {
                    $t->json('payload')->nullable()->after('gazette_source');
                }
                if (!Schema::hasColumn('ref_poes', 'display_order')) {
                    $t->unsignedInteger('display_order')->default(0)->after('is_active');
                }
                if (!Schema::hasColumn('ref_poes', 'created_by_user_id')) {
                    $t->unsignedBigInteger('created_by_user_id')->nullable();
                }
                if (!Schema::hasColumn('ref_poes', 'updated_by_user_id')) {
                    $t->unsignedBigInteger('updated_by_user_id')->nullable();
                }
            });
        }

        // ── ref_districts: add missing columns if needed ─────────────────
        if (Schema::hasTable('ref_districts')) {
            Schema::table('ref_districts', function (Blueprint $t) {
                if (!Schema::hasColumn('ref_districts', 'province_id')) {
                    $t->unsignedBigInteger('province_id')->nullable()->after('country_code');
                }
                if (!Schema::hasColumn('ref_districts', 'code')) {
                    $t->string('code', 60)->nullable()->after('province_id');
                }
                if (!Schema::hasColumn('ref_districts', 'name_raw')) {
                    $t->string('name_raw', 160)->nullable();
                }
                if (!Schema::hasColumn('ref_districts', 'display_order')) {
                    $t->unsignedInteger('display_order')->default(0);
                }
                if (!Schema::hasColumn('ref_districts', 'created_by_user_id')) {
                    $t->unsignedBigInteger('created_by_user_id')->nullable();
                }
                if (!Schema::hasColumn('ref_districts', 'updated_by_user_id')) {
                    $t->unsignedBigInteger('updated_by_user_id')->nullable();
                }
            });
        }

        // ── ref_countries: add missing columns if needed ──────────────────
        if (Schema::hasTable('ref_countries')) {
            Schema::table('ref_countries', function (Blueprint $t) {
                if (!Schema::hasColumn('ref_countries', 'is_active')) {
                    $t->boolean('is_active')->default(true);
                }
                if (!Schema::hasColumn('ref_countries', 'display_order')) {
                    $t->unsignedInteger('display_order')->default(0);
                }
                if (!Schema::hasColumn('ref_countries', 'metadata_json')) {
                    $t->json('metadata_json')->nullable();
                }
                if (!Schema::hasColumn('ref_countries', 'created_by_user_id')) {
                    $t->unsignedBigInteger('created_by_user_id')->nullable();
                }
                if (!Schema::hasColumn('ref_countries', 'updated_by_user_id')) {
                    $t->unsignedBigInteger('updated_by_user_id')->nullable();
                }
                if (!Schema::hasColumn('ref_countries', 'deleted_at')) {
                    $t->softDeletes();
                }
                if (!Schema::hasColumn('ref_countries', 'name')) {
                    $t->string('name', 120)->nullable();
                }
                if (!Schema::hasColumn('ref_countries', 'created_at')) {
                    $t->timestamps();
                }
            });
        }
    }

    public function down(): void
    {
        // Non-destructive rollback — only drop tables this migration created.
        Schema::dropIfExists('ref_geo_version');
        Schema::dropIfExists('ref_provinces');
    }
};
