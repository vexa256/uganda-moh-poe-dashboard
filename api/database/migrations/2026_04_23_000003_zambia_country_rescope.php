<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * ZAMBIA COUNTRY RESCOPE — forward-only, additive, no-op migration.
 *
 * Context
 * -------
 * The POE Sentinel platform was forked from its original Uganda deployment
 * to a Zambia-scoped deployment on 2026-04-23. `src/POEs.js` (and its SSOT
 * mirror) have been replaced with the Zambia gazetted POE dataset (35
 * entries, 10 Provincial PHEOCs). The regenerated `poes.json` snapshot is
 * upserted into `ref_poes` by `ReferenceDataSeeder` — which is the correct
 * mechanism for this rescope.
 *
 * Why this migration is a NO-OP
 * -----------------------------
 * Earlier drafts of this file DELETEd Uganda-scope rows from `ref_poes`
 * and flagged Uganda-anchored `user_assignments`. Both operations were
 * dropped because:
 *
 *   • The Zambia and Uganda deployments may SHARE the same physical
 *     MySQL/MariaDB server (different app hosts, same DB). Destructive
 *     country-scoped deletes would corrupt the Uganda app's runtime data.
 *   • Row-level country scoping is already enforced at the application
 *     layer via `PheocScope`, the SsotRegistry country filters, and the
 *     UserController geographic whitelists. Uganda and Zambia rows can
 *     coexist in `ref_poes` without leaking into each other's UI.
 *   • `ReferenceDataSeeder` is idempotent: running it against the
 *     upgraded `poes.json` will UPSERT the 35 Zambia rows (keyed on
 *     external_id) alongside any Uganda rows already present.
 *
 * What the operator must run
 * --------------------------
 *   php artisan migrate
 *   php artisan db:seed --class=Database\\Seeders\\ReferenceDataSeeder
 *
 * The migration exists purely to anchor this commit in the migration
 * timeline so `migrate:status` shows the rescope was applied and to give
 * future deploys a hook if a *non-destructive* schema adjustment becomes
 * necessary.
 */
return new class extends Migration {
    public function up(): void
    {
        // Intentionally no-op. See class docblock above.
        //
        // Rescope is performed by the `ReferenceDataSeeder`, which reads
        // the updated `api/database/seeders/data/poes.json` and UPSERTs
        // Zambia rows into `ref_poes` without touching pre-existing rows
        // owned by any other deployment.
    }

    public function down(): void
    {
        // Intentionally no-op: this migration made no schema or data
        // changes, so there is nothing to roll back.
    }
};
