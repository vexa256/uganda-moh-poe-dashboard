<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geo Hierarchy — normalized reference tables for countries, provinces
 * (PHEOCs), districts, POEs and hospitals.  Strictly additive: existing
 * `ref_poes` is extended with nullable province_id/district_id FKs but
 * its legacy columns and JSON `payload` are untouched so prior writers
 * keep working.
 *
 * The mobile app consumes this as `window.POE_MAIN` via
 * /api/reference/poe-bundle — the bundle is assembled from these tables
 * and is byte-equivalent to the legacy hardcoded POEs.js blob.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ref_countries')) {
            Schema::create('ref_countries', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10)->unique();   // e.g. "Zambia" (matches legacy ref_poes.country_code)
                $t->string('iso_alpha2', 2)->nullable()->index();
                $t->string('iso_alpha3', 3)->nullable()->index();
                $t->string('name', 120);
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedInteger('display_order')->default(0);
                $t->json('metadata_json')->nullable();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (!Schema::hasTable('ref_provinces')) {
            Schema::create('ref_provinces', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10)->index();
                $t->string('code', 40);                     // normalized slug e.g. "lusaka-province-pheoc"
                $t->string('name', 160);                    // exact display name e.g. "Lusaka Province PHEOC"
                $t->string('admin_level_1_type', 60)->default('PHEOC'); // PHEOC | PROVINCE | REGION | …
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

        if (!Schema::hasTable('ref_districts')) {
            Schema::create('ref_districts', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10)->index();
                $t->unsignedBigInteger('province_id')->index();
                $t->string('code', 60);                     // normalized slug
                $t->string('name', 160);                    // full display name e.g. "Chililabombwe District"
                $t->string('name_raw', 160)->nullable();    // stripped form e.g. "Chililabombwe"
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedInteger('display_order')->default(0);
                $t->json('metadata_json')->nullable();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['country_code', 'code'], 'uq_ref_districts_country_code');
                $t->unique(['country_code', 'name'], 'uq_ref_districts_country_name');
            });
        }

        if (!Schema::hasTable('ref_hospitals')) {
            Schema::create('ref_hospitals', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10)->index();
                $t->unsignedBigInteger('province_id')->index();
                $t->unsignedBigInteger('district_id')->nullable()->index();
                $t->string('code', 60);
                $t->string('name', 200);
                $t->string('hospital_type', 60)->default('GENERAL'); // TEACHING | GENERAL | DISTRICT | RURAL | CLINIC | PRIVATE | MILITARY | OTHER
                $t->boolean('is_national_level')->default(false)->index();
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedInteger('display_order')->default(0);
                $t->decimal('latitude', 10, 6)->nullable();
                $t->decimal('longitude', 10, 6)->nullable();
                $t->string('phone', 40)->nullable();
                $t->string('address', 255)->nullable();
                $t->json('metadata_json')->nullable();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['country_code', 'code'], 'uq_ref_hospitals_country_code');
            });
        }

        if (!Schema::hasTable('ref_geo_metadata')) {
            Schema::create('ref_geo_metadata', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10);
                $t->string('meta_key', 80);                 // dataset_name | schema_version | created_from_user_supplied_text_on | countries | country_entry_counts | primary_filter_fields | cross_country_mapping_note | data_quality_notes
                $t->json('meta_value');
                $t->unsignedInteger('display_order')->default(0);
                $t->timestamps();
                $t->unique(['country_code', 'meta_key'], 'uq_ref_geo_metadata_key');
            });
        }

        if (!Schema::hasTable('ref_traveler_notes')) {
            Schema::create('ref_traveler_notes', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10)->index();
                $t->string('note_key', 80);                 // zambia_osbp_tip | cargo_route_tip | ...
                $t->string('note_type', 20)->default('MULTI'); // MULTI = recommended_poes[]; SINGLE = recommended_poe
                $t->json('recommended_poes_json');          // always json-array of poe_name strings (single → 1-element array)
                $t->text('note_text');
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedInteger('display_order')->default(0);
                $t->timestamps();
                $t->unique(['country_code', 'note_key'], 'uq_ref_traveler_notes_key');
            });
        }

        if (!Schema::hasTable('ref_geo_version')) {
            Schema::create('ref_geo_version', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10)->unique();
                $t->unsignedBigInteger('version')->default(1);   // bumped on every geo mutation
                $t->string('etag', 64)->nullable();              // sha1 of rendered bundle
                $t->timestamp('last_built_at')->nullable();
                $t->timestamps();
            });
        }

        // Extend ref_poes with nullable FKs — additive only; legacy writers unaffected.
        if (Schema::hasTable('ref_poes')) {
            Schema::table('ref_poes', function (Blueprint $t) {
                if (!Schema::hasColumn('ref_poes', 'province_id')) {
                    $t->unsignedBigInteger('province_id')->nullable()->after('admin_level_1_type')->index();
                }
                if (!Schema::hasColumn('ref_poes', 'district_id')) {
                    $t->unsignedBigInteger('district_id')->nullable()->after('district')->index();
                }
                if (!Schema::hasColumn('ref_poes', 'display_order')) {
                    $t->unsignedInteger('display_order')->default(0)->after('is_active');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ref_poes')) {
            Schema::table('ref_poes', function (Blueprint $t) {
                foreach (['province_id', 'district_id', 'display_order'] as $col) {
                    if (Schema::hasColumn('ref_poes', $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
        Schema::dropIfExists('ref_geo_version');
        Schema::dropIfExists('ref_traveler_notes');
        Schema::dropIfExists('ref_geo_metadata');
        Schema::dropIfExists('ref_hospitals');
        Schema::dropIfExists('ref_districts');
        Schema::dropIfExists('ref_provinces');
        Schema::dropIfExists('ref_countries');
    }
};
