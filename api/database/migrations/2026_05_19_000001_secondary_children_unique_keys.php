<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Belt-and-suspenders integrity for the six secondary-screening child tables.
 *
 * Why now (2026-05-19):
 *   • Mobile sync was missing a Phase-2 trigger for IN_PROGRESS cases —
 *     children entered before disposition never reached the server.
 *     Patched in src/services/syncEngine.js (paranoid needsPhase2 + chain
 *     after Phase 1). With that fix, the server's "replace-all per child
 *     table" pattern will be exercised much more often than before.
 *   • If a network/Octane glitch interrupts the controller mid-loop between
 *     the DELETE and the INSERTs, the table can briefly hold a partial set.
 *     A UNIQUE composite on (secondary_screening_id, code) makes the
 *     race-window safe: a re-sync that lands a previously-inserted row
 *     gets a clean DB error the controller can recover from instead of
 *     silently producing a duplicate.
 *
 * Idempotency:
 *   Every ALTER is guarded by a SHOW INDEXES check — running this migration
 *   on a database that already has the indexes is a no-op. Conversely, the
 *   `down()` only drops what `up()` created, so reverting is symmetric.
 *
 * Charset / collation:
 *   No charset writes here. Operational tables stay utf8mb4_0900_ai_ci.
 *   This migration only adds composite indexes on existing columns.
 */
return new class extends Migration {

    /**
     * (table, indexName, columns[])
     *
     * Composite UNIQUE on (secondary_screening_id, *_code [, travel_role])
     * mirrors the server's replace-all semantics in
     * SecondaryScreeningController::fullSync().
     */
    private const TARGETS = [
        ['secondary_suspected_diseases', 'uq_ssd_screening_disease',  ['secondary_screening_id', 'disease_code']],
        ['secondary_symptoms',           'uq_ss_screening_symptom',    ['secondary_screening_id', 'symptom_code']],
        ['secondary_exposures',          'uq_se_screening_exposure',   ['secondary_screening_id', 'exposure_code']],
        ['secondary_actions',            'uq_sa_screening_action',     ['secondary_screening_id', 'action_code']],
        ['secondary_travel_countries',   'uq_stc_screening_country',   ['secondary_screening_id', 'country_code', 'travel_role']],
        // NB: secondary_samples is intentionally NOT in this list — a single
        // screening can collect multiple samples of the same `sample_type`
        // (e.g. two blood draws an hour apart), so a UNIQUE constraint
        // there would be wrong epidemiologically.
    ];

    public function up(): void
    {
        foreach (self::TARGETS as [$table, $indexName, $columns]) {
            if (! Schema::hasTable($table)) {
                continue; // tolerate: in case a partial deploy hasn't created the table yet
            }
            if ($this->indexExists($table, $indexName)) {
                continue; // idempotent no-op — re-running this migration is safe
            }

            // Defensive de-dup BEFORE adding the unique key. If duplicate
            // rows exist they would otherwise abort the ALTER. We keep the
            // lowest id per composite key and delete the rest. This mirrors
            // the same pattern the secondary_screenings dup-cleanup migration
            // used on 2026-05-18.
            $this->dedupePriorRows($table, $columns);

            $colList = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));
            DB::statement("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$indexName}` ({$colList})");
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as [$table, $indexName, $_columns]) {
            if (! Schema::hasTable($table))           { continue; }
            if (! $this->indexExists($table, $indexName)) { continue; }
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = DB::select(
            'SELECT COUNT(*) AS c FROM information_schema.statistics
              WHERE table_schema = DATABASE()
                AND table_name   = ?
                AND index_name   = ?',
            [$table, $indexName],
        );
        return ! empty($rows) && (int) $rows[0]->c > 0;
    }

    /**
     * Remove pre-existing duplicate rows that would otherwise prevent
     * ALTER ADD UNIQUE. Keeps the lowest id per composite key. Soft-delete
     * is not available on these child tables, so it's a hard delete — safe
     * because the surviving row carries the same payload (we keep the oldest,
     * which is the canonical first write).
     *
     * @param  array<int,string>  $columns
     */
    private function dedupePriorRows(string $table, array $columns): void
    {
        $partition = implode(', ', array_map(fn ($c) => "`{$c}`", $columns));

        // Find IDs to delete (everything except the lowest id per group).
        $sql = "
            DELETE FROM `{$table}` WHERE id IN (
                SELECT id FROM (
                    SELECT id,
                           ROW_NUMBER() OVER (PARTITION BY {$partition} ORDER BY id) AS rn
                      FROM `{$table}`
                ) ranked
                WHERE rn > 1
            )
        ";

        try {
            DB::statement($sql);
        } catch (\Throwable $e) {
            // ROW_NUMBER requires MySQL 8.0+. Skip silently — production
            // already runs MySQL 8.0+ (verified on con-dev2 2026-05-19),
            // and any environment that doesn't is welcome to add the unique
            // by hand. The migration's idempotency guard above means a
            // future re-run will succeed once the dedupe completes.
        }
    }
};
