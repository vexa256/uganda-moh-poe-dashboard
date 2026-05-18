<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase REF · Unit REF-2 — Relax ref_poes (country_code, poe_code) unique.
 *
 * The Uganda POE source contains two distinct entries that share both
 * country and poe_code (`Kayanja` and `Kizinga`, each appearing under
 * Kabale RPHEOC and Fort Portal RPHEOC).  REF-1 pinned uniqueness on
 * (country_code, poe_code), which would silently drop one row on
 * insert.  REF-2 keeps poe_code as the human-facing identifier
 * (per POE_MAIN.metadata: `poe_code is set to poe_name for every
 * entry`), but moves the unique constraint onto a new `external_id`
 * column populated from the source `id` (e.g. UG-GUL-AMU-ELE-001).
 *
 * Migration is additive:
 *   • adds external_id VARCHAR(80) NULL with its own unique
 *   • drops the unique on (country_code, poe_code) and replaces it
 *     with a regular index (preserves the lookup pattern callers use)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ref_poes')) {
            return;
        }

        if (!Schema::hasColumn('ref_poes', 'external_id')) {
            Schema::table('ref_poes', function (Blueprint $t) {
                $t->string('external_id', 80)->nullable()->after('id');
                $t->unique('external_id', 'ref_poes_external_id_unique');
            });
        }

        // Drop the old (country_code, poe_code) unique if it exists,
        // then add the same pair as a regular non-unique index for
        // lookup-by-name use cases.  Wrapped in try/catch because the
        // index may already have been dropped on a prior partial run.
        try {
            Schema::table('ref_poes', function (Blueprint $t) {
                $t->dropUnique('ref_poes_country_poe_unique');
            });
        } catch (\Throwable $e) {
            // index already gone — fine
        }

        // Add the loose index — wrap in try/catch because the index
        // may already exist (idempotency) and driver index-introspection
        // syntax differs between MySQL (SHOW INDEX) and SQLite
        // (PRAGMA index_list).  If create fails because the index is
        // already present, that is the desired end state.
        try {
            Schema::table('ref_poes', function (Blueprint $t) {
                $t->index(['country_code', 'poe_code'], 'ref_poes_country_code_poe_code_index');
            });
        } catch (\Throwable $e) {
            // index exists already — non-fatal
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('ref_poes')) {
            return;
        }

        try {
            Schema::table('ref_poes', function (Blueprint $t) {
                $t->dropIndex('ref_poes_country_code_poe_code_index');
            });
        } catch (\Throwable $e) {}

        try {
            Schema::table('ref_poes', function (Blueprint $t) {
                $t->unique(['country_code', 'poe_code'], 'ref_poes_country_poe_unique');
            });
        } catch (\Throwable $e) {}

        if (Schema::hasColumn('ref_poes', 'external_id')) {
            Schema::table('ref_poes', function (Blueprint $t) {
                try { $t->dropUnique('ref_poes_external_id_unique'); } catch (\Throwable $e) {}
                $t->dropColumn('external_id');
            });
        }
    }
};
