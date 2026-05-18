<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen all long-text "summary" columns from VARCHAR(…) to TEXT.
 *
 * Root cause: Uganda's original schema sized these narrow
 * (notifications.reason_text = VARCHAR(255)). Zambia's longer POE /
 * district / province names + officer full_names push the generated
 * reason_text over 255 chars, and MySQL rejects the insert with
 * SQLSTATE[22001] "Data too long for column 'reason_text'". The whole
 * primary_screenings + notifications transaction rolls back and the
 * symptomatic traveller record never reaches the server — users see it
 * as a sync failure that mentions the officer name (because the officer
 * name appears in the offending row).
 *
 * Columns covered — every VARCHAR that stores free-form user-generated
 * summary text (where the payload length depends on geography / user
 * input rather than a fixed code). They all become TEXT so no realistic
 * user input can ever overflow them again.
 *
 * Idempotent: checks information_schema; alters only if the column is
 * still a narrow VARCHAR. Safe to re-run.
 */
return new class extends Migration
{
    /** @var array<string, array<int, string>> */
    private const TARGETS = [
        'notifications'      => ['reason_text'],
        'alerts'             => ['close_note'],
        'primary_screenings' => ['void_reason'],
        'users'              => ['suspension_reason'],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                if ($this->isText($table, $column)) {
                    continue; // already widened
                }
                // Portable DDL: MODIFY … TEXT NULL. No indexes reference these.
                DB::statement("ALTER TABLE `{$table}` MODIFY `{$column}` TEXT NULL");
            }
        }
    }

    public function down(): void
    {
        // Intentionally a no-op. Narrowing back to VARCHAR(255) would truncate
        // data that legitimately grew past the original limit, which is exactly
        // the bug this migration fixes. If you really need to narrow, write a
        // new migration with an explicit data audit.
    }

    private function isText(string $table, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT data_type FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            [$table, $column]
        );
        $type = (string) ($row?->data_type ?? $row?->DATA_TYPE ?? '');
        return strtolower($type) === 'text';
    }
};
