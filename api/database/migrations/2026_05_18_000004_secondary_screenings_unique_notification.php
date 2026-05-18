<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforce: ONE live secondary screening per notification.
 *
 * Background: the mobile sync engine could generate a fresh client_uuid on retry
 * paths (e.g. IDB wipe + re-fetch race), and the server controller's
 * idempotency-by-notification_id check is not transactionally safe under
 * concurrent inserts. Result: two secondary_screenings rows for the same
 * notification, both with deleted_at IS NULL. Reporting de-duped on display,
 * but the upstream data is still wrong.
 *
 * Fix: a virtual generated column that mirrors notification_id ONLY while the
 * row is live (deleted_at IS NULL), plus a UNIQUE INDEX on it. Soft-deleted
 * rows fall out of the index so the column-as-NULL allows multiple historical
 * deleted rows without collision. The controller catches the resulting
 * QueryException and returns the existing row idempotently (no API change).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pre-clean known live duplicates so the unique index can be created.
        // For each notification with >1 live secondary, keep the one with the
        // highest record_version (the mobile's actively-updated row) and
        // soft-delete the abandoned ones. Children (symptoms/exposures/etc.)
        // are not touched — they only hang off the kept row anyway.
        $now = now()->format('Y-m-d H:i:s');

        $dupGroups = DB::table('secondary_screenings')
            ->select('notification_id', DB::raw('COUNT(*) AS cnt'))
            ->whereNull('deleted_at')
            ->groupBy('notification_id')
            ->having('cnt', '>', 1)
            ->pluck('notification_id');

        foreach ($dupGroups as $notifId) {
            $rows = DB::table('secondary_screenings')
                ->where('notification_id', $notifId)
                ->whereNull('deleted_at')
                ->orderByDesc('record_version')
                ->orderByDesc('id')
                ->get(['id', 'record_version']);

            // Keep the head (highest record_version, then highest id); soft-delete the rest.
            foreach ($rows->slice(1) as $orphan) {
                DB::table('secondary_screenings')
                    ->where('id', $orphan->id)
                    ->update([
                        'deleted_at'      => $now,
                        'updated_at'      => $now,
                        'last_sync_error' => 'soft-deleted by migration 2026_05_18_000004 (notification-level dup; abandoned by mobile)',
                    ]);
            }
        }

        // Add the partial-uniqueness scaffolding. MySQL 8 has no partial unique
        // indexes; the standard pattern is a virtual column that is NULL when
        // the row is soft-deleted, paired with a UNIQUE index that ignores
        // multiple NULLs.
        DB::statement('
            ALTER TABLE secondary_screenings
                ADD COLUMN notification_id_active BIGINT UNSIGNED
                    GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN notification_id END) VIRTUAL
                    AFTER notification_id
        ');

        DB::statement('
            ALTER TABLE secondary_screenings
                ADD UNIQUE INDEX uq_secondary_notif_active (notification_id_active)
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE secondary_screenings DROP INDEX uq_secondary_notif_active');
        DB::statement('ALTER TABLE secondary_screenings DROP COLUMN notification_id_active');
        // No reversal of the soft-deletes — they were data-quality cleanup, not schema.
    }
};
