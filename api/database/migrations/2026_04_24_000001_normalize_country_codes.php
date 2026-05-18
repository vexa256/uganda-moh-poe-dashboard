<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalise country_code across the aggregated + operational tables so the
 * value stored everywhere matches the SSoT form in `ref_countries`.
 *
 * Why
 * ----
 * Different writers historically picked different aliases for the same
 * country — the mobile app defaulted to ISO alpha-2 ("ZM") while the
 * admin geo bundle writes the full name ("Zambia"). A `where('country_code',
 * ...)` scope filter then silently matches zero rows even though the data
 * exists, because the filter picks one alias and the row carries another.
 *
 * What
 * ----
 * For every row in the tenant-scoped tables below, this migration looks up
 * the matching ref_countries row by any of its aliases (country_code /
 * iso_alpha2 / iso_alpha3 / name) and rewrites country_code to the SSoT's
 * `country_code` value. Rows carrying an alias that doesn't match any
 * ref_countries row are left untouched (they are either legacy cross-
 * tenant test data or a country we haven't seeded yet — either way, safer
 * to leave in place than to silently drop).
 *
 * What is deliberately NOT touched
 * --------------------------------
 * - `ref_countries`               — the SSoT itself. Already canonical.
 * - `ref_endemic_countries`       — each row is ISO-2 code of a DIFFERENT
 *                                   country (a per-disease endemic list),
 *                                   not a reference to the tenant's country.
 *                                   Normalising these would destroy the
 *                                   endemic map.
 * - `ref_geo_version`             — carries one row per data bundle
 *                                   (including legacy 'TestLand' used in
 *                                   unit tests); normalising would merge
 *                                   versions unsafely.
 *
 * Up is idempotent: running it twice on already-normalised data is a no-op.
 * Down is a no-op: we can't tell after the fact which rows were "ZM" vs
 * "Zambia" because the mapping is lossy. The migration is considered a
 * forward-only data hygiene step.
 */
return new class extends Migration {

    /**
     * Tables whose country_code column references the tenant's country.
     * Every row in these tables should agree with ref_countries.country_code.
     */
    private const TENANT_SCOPED_TABLES = [
        'users',
        'user_assignments',
        'aggregated_templates',
        'aggregated_submissions',
        'notification_log',
        'notifications',
        'poe_notification_contacts',
        'primary_screenings',
        'secondary_screenings',
        'external_responders',
        'alerts',
        'alert_followups',
        'poe_capacity_assessments',
        'poe_status_events',
        'ref_districts',
        'ref_provinces',
        'ref_poes',
        'ref_hospitals',
        'ref_geo_metadata',
        'ref_traveler_notes',
        'secondary_travel_countries',
    ];

    public function up(): void
    {
        // Build alias → canonical map from the SSoT.
        $canonicalByAlias = [];
        foreach (DB::table('ref_countries')->get() as $row) {
            $canonical = (string) ($row->country_code ?? '');
            if ($canonical === '') continue;
            foreach (['country_code', 'iso_alpha2', 'iso_alpha3', 'name'] as $f) {
                $v = (string) ($row->{$f} ?? '');
                if ($v !== '') $canonicalByAlias[strtoupper($v)] = $canonical;
            }
        }
        if (empty($canonicalByAlias)) return; // no countries seeded yet; nothing to do

        foreach (self::TENANT_SCOPED_TABLES as $table) {
            if (! Schema::hasTable($table))                      continue;
            if (! Schema::hasColumn($table, 'country_code'))     continue;

            // Pull distinct current values once per table, build the
            // update batches in PHP, and apply them in one UPDATE per
            // source value. Cheaper than per-row updates for tables with
            // thousands of rows.
            $distinct = DB::table($table)->distinct()->pluck('country_code')->filter()->all();
            foreach ($distinct as $raw) {
                $upper = strtoupper((string) $raw);
                $canonical = $canonicalByAlias[$upper] ?? null;
                if ($canonical === null || $canonical === $raw) {
                    continue; // unknown alias (leave alone) or already canonical
                }
                DB::table($table)
                    ->where('country_code', $raw)
                    ->update(['country_code' => $canonical]);
            }
        }
    }

    public function down(): void
    {
        // Forward-only data hygiene. We cannot reliably reverse the
        // normalisation because the historical split between 'ZM' and
        // 'Zambia' was arbitrary per-writer, with no audit trail.
    }
};
