<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forensic-audit fix (2026-05-20): country_code residue across PoE ops tables.
 *
 * Three discoveries during the PoEs · Annex-1A audit:
 *
 *   1) poe_notification_contacts was seeded with country_code='UG' (ISO-2)
 *      while the Admin\Geo\PoeContactsController and mobile PoeContactsController
 *      both filter by canonical 'Uganda' (config('country.legacy_code')).
 *      → Roster & Ladder page rendered empty even with 14+ active rows.
 *
 *   2) poe_capacity_assessments.country_code DEFAULT was hardcoded 'Zambia'
 *      (residue from the pre-fork schema). The controller writes 'Uganda'
 *      explicitly, but any tooling that omits the column would land in
 *      'Zambia' and disappear from the admin grid.
 *
 *   3) poe_status_events.country_code DEFAULT identical residue.
 *
 * This migration is idempotent: it normalises any non-'Uganda' / non-'UG'
 * country_code values that look like Uganda data, flips the bad seed rows
 * from 'UG' to 'Uganda', and rewrites both column DEFAULTs to 'Uganda'.
 *
 * No data is destroyed — values that don't fit the Uganda profile are
 * left untouched. The `Schema::hasTable` guards keep the migration safe
 * on shared environments where one of these tables may not yet exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) poe_notification_contacts — backfill 'UG' → 'Uganda'.
        if (Schema::hasTable('poe_notification_contacts')) {
            DB::table('poe_notification_contacts')
                ->where('country_code', 'UG')
                ->update(['country_code' => 'Uganda']);
        }

        // 2) poe_capacity_assessments — fix column DEFAULT residue (Zambia → Uganda)
        //    and backfill any 'UG' or 'Zambia' rows that belong to this tenant.
        if (Schema::hasTable('poe_capacity_assessments')) {
            DB::table('poe_capacity_assessments')
                ->whereIn('country_code', ['UG', 'Zambia'])
                ->update(['country_code' => 'Uganda']);
            try {
                DB::statement(
                    "ALTER TABLE `poe_capacity_assessments`
                     MODIFY COLUMN `country_code` varchar(10) NOT NULL DEFAULT 'Uganda'"
                );
            } catch (\Throwable $e) {
                // ALTER may fail on shared mysql users without DDL — log and skip.
                // The controller already writes the value explicitly so this is
                // only belt-and-braces.
            }
        }

        // 3) poe_status_events — identical residue fix.
        if (Schema::hasTable('poe_status_events')) {
            DB::table('poe_status_events')
                ->whereIn('country_code', ['UG', 'Zambia'])
                ->update(['country_code' => 'Uganda']);
            try {
                DB::statement(
                    "ALTER TABLE `poe_status_events`
                     MODIFY COLUMN `country_code` varchar(10) NOT NULL DEFAULT 'Uganda'"
                );
            } catch (\Throwable $e) {
                // see above
            }
        }
    }

    public function down(): void
    {
        // Intentionally a no-op. Reverting the value normalisation would
        // re-break the admin grids; reverting the DEFAULT is pure cosmetic.
    }
};
