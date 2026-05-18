<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase REF · Unit REF-1 — Reference-Data Tables
 *
 * Creates the seven reference-data tables that move POEs, diseases,
 * exposures, symptoms, engine-config, exposure-mappings, and endemic-
 * country oracle data out of the client JS files (src/Diseases.js,
 * src/exposures.js, src/POEs.js, src/countries.js,
 * src/Diseases_intelligence.js) into the database, served via
 * /v2/reference/* in later REF units (REF-3) and bumped via the
 * REFERENCE_DATA_VER migration in REF-7.
 *
 * Authority:
 *   WHO IHR (2005) Annex 1 / Annex 2
 *   WHO AFRO IDSR Technical Guidelines 2021 (3rd Ed.)
 *   WHO SPH Portal — designated POEs
 *   CDC Yellow Book 2024 — travel-medicine differentials
 *   Mandell 9th Ed — LR-calibrated symptom weights
 *
 * This unit is additive-only — no existing table or column changes.
 * Seeding lands in REF-2; endpoints in REF-3; admin CRUD in REF-4;
 * client bootstrap in REF-5; parity in REF-6; REFERENCE_DATA_VER in REF-7.
 */
return new class extends Migration
{
    public function up(): void
    {
        /* ── ref_poes ──────────────────────────────────────────────────
         * Mirrors window.POE_MAIN administrative_groups[].pois[].
         * One row per gazetted entry point (Uganda baseline = 61 rows).
         */
        if (!Schema::hasTable('ref_poes')) {
            Schema::create('ref_poes', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10)->index();
                $t->string('poe_code', 40);
                $t->string('poe_name', 200);
                $t->string('admin_level_1', 120)->nullable();
                $t->string('admin_level_1_type', 60)->nullable();
                $t->string('district', 120)->nullable();
                $t->enum('poe_type', ['airport', 'airstrip', 'port', 'island_entry', 'land_border', 'rail', 'other'])
                  ->default('land_border')->index();
                $t->enum('transport_mode', ['air', 'water', 'land', 'rail', 'other'])
                  ->default('land')->index();
                $t->string('regional_cluster', 120)->nullable()->index();
                $t->boolean('is_national_level')->default(false);
                $t->boolean('is_major_entry')->default(false);
                $t->boolean('is_recommended_osbp')->default(false);
                $t->string('border_country', 80)->nullable();
                $t->decimal('latitude', 10, 6)->nullable();
                $t->decimal('longitude', 10, 6)->nullable();
                $t->string('gazette_source', 200)->nullable();
                $t->json('payload')->nullable();
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->timestamps();

                $t->unique(['country_code', 'poe_code'], 'ref_poes_country_poe_unique');
            });
        }

        /* ── ref_diseases ───────────────────────────────────────────────
         * Mirrors window.DISEASES.diseases[].
         * 42-disease baseline in REF-2.  IHR Annex-2 tier carried in
         * `ihr_tier` (1 = always-notifiable, 2 = priority, 3 = differential).
         */
        if (!Schema::hasTable('ref_diseases')) {
            Schema::create('ref_diseases', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('disease_code', 80)->unique();
                $t->string('display_name', 200);
                $t->unsignedTinyInteger('ihr_tier')->default(2)->index();
                $t->string('who_syndrome', 80)->nullable()->index();
                $t->unsignedSmallInteger('incubation_days_min')->nullable();
                $t->unsignedSmallInteger('incubation_days_max')->nullable();
                $t->json('case_definition')->nullable();
                $t->json('gates')->nullable();
                $t->json('symptom_weights')->nullable();
                $t->json('exposure_weights')->nullable();
                $t->json('triage_overrides')->nullable();
                $t->json('absent_penalties')->nullable();
                $t->json('sources')->nullable();
                $t->json('payload')->nullable();
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->timestamps();
            });
        }

        /* ── ref_symptoms ──────────────────────────────────────────────
         * Master symptom catalogue.  Each row is a symptom code referenced
         * by ref_diseases.symptom_weights[*] and by the secondary-screening
         * UI.  Sensitivity lets the engine apply absent-symptom penalties
         * only for hallmark symptoms with sensitivity ≥ 0.80 (see
         * Diseases.js engine.ranking_principles).
         */
        if (!Schema::hasTable('ref_symptoms')) {
            Schema::create('ref_symptoms', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('symptom_code', 80)->unique();
                $t->string('display_name', 200);
                $t->string('category', 60)->nullable()->index();
                $t->json('syndrome_tags')->nullable();
                $t->decimal('sensitivity', 4, 3)->nullable();
                $t->boolean('is_red_flag')->default(false)->index();
                $t->boolean('is_hallmark')->default(false)->index();
                $t->string('display_order', 6)->nullable();
                $t->json('payload')->nullable();
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->timestamps();
            });
        }

        /* ── ref_exposures ─────────────────────────────────────────────
         * Mirrors window.EXPOSURES.exposures[].  Each entry maps to one
         * or more engine codes via ref_exposure_mappings (see below).
         */
        if (!Schema::hasTable('ref_exposures')) {
            Schema::create('ref_exposures', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('exposure_code', 80)->unique();
                $t->string('display_name', 200);
                $t->string('category', 60)->nullable()->index();
                $t->string('prompt_text', 500)->nullable();
                $t->enum('response_type', ['YES_NO', 'YES_NO_UNKNOWN', 'MULTI_SELECT', 'TEXT', 'NUMERIC'])
                  ->default('YES_NO');
                $t->boolean('is_high_risk')->default(false)->index();
                $t->json('triggers_diseases')->nullable();
                $t->json('payload')->nullable();
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->timestamps();
            });
        }

        /* ── ref_exposure_mappings ─────────────────────────────────────
         * The DB-code → engine-code translation table.  This is the data
         * that exposures.js mapToEngineCodes() encodes today (DB stores
         * CONTACT_SICK_PERSON, engine expects close_contact_case).
         * Many-to-many: one DB code can fan out to multiple engine codes.
         */
        if (!Schema::hasTable('ref_exposure_mappings')) {
            Schema::create('ref_exposure_mappings', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('exposure_code', 80)->index();
                $t->string('engine_code', 80)->index();
                $t->unsignedSmallInteger('priority')->default(0);
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->timestamps();

                $t->unique(['exposure_code', 'engine_code'], 'ref_exposure_mappings_pair_unique');
            });
        }

        /* ── ref_engine_config ─────────────────────────────────────────
         * Key / value store for engine config (formula, weights,
         * thresholds, gates definitions, syndrome bonuses, outbreak
         * bonuses, copy text).  Mirrors window.DISEASES.engine.* and the
         * tunable thresholds used by Diseases_intelligence.js.
         *
         * Access pattern: read by config_key, payload is JSON.
         * Versioned via `version` so migrations / admin edits don't
         * silently drift from a previous shipped client baseline.
         */
        if (!Schema::hasTable('ref_engine_config')) {
            Schema::create('ref_engine_config', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('config_key', 120)->unique();
                $t->string('description', 300)->nullable();
                $t->json('config_value');
                $t->string('version', 20)->nullable();
                $t->string('section', 60)->nullable()->index();
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->timestamps();
            });
        }

        /* ── ref_endemic_countries ────────────────────────────────────
         * Disease-by-country endemicity oracle from
         * Diseases_intelligence.js.  Drives the outbreak-context bonus
         * and the WHO syndrome inference for travelers from endemic
         * jurisdictions.
         */
        if (!Schema::hasTable('ref_endemic_countries')) {
            Schema::create('ref_endemic_countries', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('disease_code', 80)->index();
                $t->string('country_code', 3)->index();
                $t->string('country_name', 120)->nullable();
                $t->enum('endemicity_level', ['ENDEMIC', 'OUTBREAK_ACTIVE', 'OUTBREAK_RECENT', 'SPORADIC', 'IMPORTED_ONLY'])
                  ->default('ENDEMIC')->index();
                $t->unsignedSmallInteger('since_year')->nullable();
                $t->string('source', 200)->nullable();
                $t->date('last_verified_at')->nullable();
                $t->json('payload')->nullable();
                $t->boolean('is_active')->default(true)->index();
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->unsignedBigInteger('updated_by_user_id')->nullable();
                $t->timestamps();

                $t->unique(['disease_code', 'country_code'], 'ref_endemic_countries_pair_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_endemic_countries');
        Schema::dropIfExists('ref_engine_config');
        Schema::dropIfExists('ref_exposure_mappings');
        Schema::dropIfExists('ref_exposures');
        Schema::dropIfExists('ref_symptoms');
        Schema::dropIfExists('ref_diseases');
        Schema::dropIfExists('ref_poes');
    }
};
