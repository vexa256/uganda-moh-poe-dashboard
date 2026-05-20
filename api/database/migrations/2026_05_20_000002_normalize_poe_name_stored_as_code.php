<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Data-cleanup migration · normalize POE name stored as code.
 *
 * BACKGROUND
 *   For some operational rows the mobile app wrote the human-readable POE
 *   name (e.g. "Entebbe International Airport") into the `poe_code` column
 *   where the canonical code (e.g. "UG-WAKISO-001") was expected.
 *
 *   Investigation traced this to a stale `EMBEDDED_FALLBACK` literal in
 *   `src/POEs.js` that ships with the mobile app — each entry there has
 *   `poe_code` populated with the POE name, so any row captured before
 *   the live API replaces that fallback gets the wrong code value.
 *
 *   The mobile-side fix is to regenerate the fallback via
 *   `scripts/rebuild-poes-fallback.cjs --url <api-host>/api/poes/bundle`.
 *   That ships in a separate change.
 *
 * WHAT THIS MIGRATION DOES
 *   For every operational table that carries a `poe_code` column, scan
 *   rows whose `poe_code` matches a known `ref_poes.poe_name` and rewrite
 *   the value to the canonical `poe_code`.
 *
 * SAFETY
 *   - Idempotent: re-running after a clean DB is a no-op (UPDATE matches 0
 *     rows because the names are already normalized away).
 *   - Soft-deleted rows are left alone.
 *   - No schema changes; no FK changes; no data dropped.
 *   - No mobile-API contract impact — the mobile app still writes whatever
 *     `auth.poe_code` is on the device. This only fixes historical writes.
 *
 * INSTRUMENTATION
 *   Counts written rows per table to laravel.log so the gov admin can
 *   confirm what got rewritten on their database.
 */
return new class extends Migration
{
    /** Tables that hold a `poe_code` we may need to rewrite. */
    private const TABLES = [
        'primary_screenings',
        'secondary_screenings',
        'alerts',
    ];

    public function up(): void
    {
        // ref_poes is collation-different from operational tables; pull the
        // mapping into PHP and write back in a transaction. No SQL JOIN.
        $nameToCode = DB::table('ref_poes')
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->pluck('poe_code', 'poe_name')
            ->all();

        if (! $nameToCode) {
            Log::info('[normalize_poe_name_stored_as_code] ref_poes empty — nothing to do.');
            return;
        }

        $names = array_keys($nameToCode);
        $summary = [];

        DB::transaction(function () use ($names, $nameToCode, &$summary) {
            foreach (self::TABLES as $table) {
                if (! \Schema::hasTable($table) || ! \Schema::hasColumn($table, 'poe_code')) {
                    $summary[$table] = 'skipped (table or column missing)';
                    continue;
                }

                $touched = 0;
                // Per-name UPDATE keeps the SQL trivial and lets us bound the
                // scope tightly — only rows whose poe_code exactly matches a
                // known name get rewritten. Rows that happen to share a name
                // with the canonical code (no overlap exists today) wouldn't
                // be touched because the lookup map keys are names only.
                foreach ($names as $name) {
                    $canonical = $nameToCode[$name] ?? null;
                    if (! $canonical || $canonical === $name) { continue; }

                    $q = DB::table($table)
                        ->where('poe_code', $name);
                    if (\Schema::hasColumn($table, 'deleted_at')) {
                        $q->whereNull('deleted_at');
                    }
                    $touched += $q->update(['poe_code' => $canonical]);
                }
                $summary[$table] = $touched;
            }
        });

        Log::info('[normalize_poe_name_stored_as_code] rewrites: ' . json_encode($summary));
    }

    /**
     * Non-reversible by design. Restoring the bad data would require knowing
     * which UG-WAKISO-001 rows were originally "Entebbe International Airport"
     * vs. legitimately the code, and that information isn't recoverable.
     */
    public function down(): void
    {
        throw new \RuntimeException(
            'normalize_poe_name_stored_as_code is intentionally non-reversible. ' .
            'Use a DB snapshot if you need to roll back.'
        );
    }
};
