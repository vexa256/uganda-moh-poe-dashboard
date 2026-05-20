<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the secondary_screenings.final_disposition ENUM to include the
 * WHO/IDSR canonical disposition codes that the application's PHP whitelist
 * (VALID_DISPOSITIONS in SecondaryScreeningController) has been accepting but
 * the database enum has been silently rejecting.
 *
 * Root cause it fixes: a dispositioning case sending final_disposition =
 * 'RELEASED_NO_CONDITION' (or any of the other canonical codes) caused MySQL
 * to throw "SQLSTATE[01000]: Warning: 1265 Data truncated for column
 * 'final_disposition'" inside the fullSync DB::transaction(). Because the
 * UPDATE was inside the transaction, every other field update (syndrome,
 * risk_level, officer_notes, case_status, dispositioned_at, closed_at) AND
 * every child write (actions, suspected_diseases) was rolled back. Visible
 * symptom: the case stays IN_PROGRESS server-side and shows "No diagnosis
 * recorded" on the case register even though the mobile app's IDB has the
 * case closed.
 *
 * 35 occurrences of the truncation error were observed in production
 * laravel.log before this migration was written.
 *
 * Additive, non-breaking: existing rows already in the enum are unaffected.
 */
return new class extends Migration {
    public function up(): void
    {
        // ALTER TABLE statements that change ENUM types are not portable
        // through Laravel's Schema builder; use a raw DDL statement against
        // the mysql connection.
        DB::statement("ALTER TABLE `secondary_screenings`
            MODIFY COLUMN `final_disposition` ENUM(
                'RELEASED',
                'DELAYED',
                'QUARANTINED',
                'ISOLATED',
                'REFERRED',
                'TRANSFERRED',
                'DENIED_BOARDING',
                'OTHER',
                'RELEASED_NO_CONDITION',
                'RELEASED_UNDER_FOLLOWUP',
                'REFERRED_HEALTH_FACILITY',
                'ISOLATED_ADMITTED',
                'DECEASED_AT_POE',
                'RETURN_TO_ORIGIN'
            ) NULL DEFAULT NULL");
    }

    public function down(): void
    {
        // Reverting would require deleting / re-mapping any rows that landed
        // on one of the new values. Safe-by-default: leave the schema as-is
        // on rollback. If a true revert is needed it must be done by hand
        // after migrating the affected rows to a legacy code.
    }
};
