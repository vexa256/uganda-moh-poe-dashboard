<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align country_code to ISO alpha-2 across the Uganda tenant.
 *
 * Why
 * ----
 * The mobile app, the API .env (COUNTRY_CODE / COUNTRY_TENANT_ISO2), and the
 * dataProvider scope filter all use the ISO-2 form ("RW"). The DB, however,
 * was seeded with a mix of forms — `ref_countries.country_code` carried the
 * full English name ("Uganda"), and `user_assignments.country_code` held a
 * stale "Zambia" left over from a previous fork.
 *
 * The earlier migration `2026_04_24_000001_normalize_country_codes` already
 * rewrites tenant tables to match `ref_countries.country_code`. So the fix
 * is two steps:
 *
 *   1. Set `ref_countries.country_code = iso_alpha2` for every row (the
 *      SSoT now agrees with the app's ISO-2 expectation).
 *   2. Sweep every tenant-scoped table that holds country_code and
 *      remap any alias (full name, ISO-3, mismatched ISO-2) to the
 *      canonical ISO-2 value.
 *
 * Down: forward-only.  Reversal would require a per-row audit trail of the
 * original alias, which we never recorded.
 */
return new class extends Migration {

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
        if (! Schema::hasTable('ref_countries')) return;

        // 1. Bring the SSoT to ISO-2.
        DB::table('ref_countries')
            ->whereNotNull('iso_alpha2')
            ->where('iso_alpha2', '!=', '')
            ->whereColumn('country_code', '!=', 'iso_alpha2')
            ->update(['country_code' => DB::raw('iso_alpha2')]);

        // 2. Build alias → canonical (ISO-2) map from the freshly aligned SSoT.
        $canonicalByAlias = [];
        foreach (DB::table('ref_countries')->get() as $row) {
            $canonical = strtoupper((string) ($row->iso_alpha2 ?? ''));
            if ($canonical === '') continue;
            foreach (['country_code', 'iso_alpha2', 'iso_alpha3', 'name'] as $f) {
                $v = (string) ($row->{$f} ?? '');
                if ($v !== '') $canonicalByAlias[strtoupper($v)] = $canonical;
            }
        }
        if (empty($canonicalByAlias)) return;

        // 3. Sweep every tenant-scoped table.
        foreach (self::TENANT_SCOPED_TABLES as $table) {
            if (! Schema::hasTable($table))                  continue;
            if (! Schema::hasColumn($table, 'country_code')) continue;

            $distinct = DB::table($table)->distinct()->pluck('country_code')->filter()->all();
            foreach ($distinct as $raw) {
                $upper     = strtoupper((string) $raw);
                $canonical = $canonicalByAlias[$upper] ?? null;
                if ($canonical === null || $canonical === $raw) continue;
                DB::table($table)->where('country_code', $raw)->update(['country_code' => $canonical]);
            }
        }
    }

    public function down(): void
    {
        // Forward-only — the original aliases are no longer recoverable.
    }
};
