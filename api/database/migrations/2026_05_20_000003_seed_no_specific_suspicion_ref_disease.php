<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent seed for the `no_specific_suspicion` placeholder disease.
 *
 * Context (2026-05-20 directive): POEs always record exactly three
 * suspected diseases. When the rule engine + officer override produce
 * fewer than three hypotheses, the mobile pads the gap with rows whose
 * disease_code = 'no_specific_suspicion' (see SecondaryScreening.vue
 * dispositionCase). The placeholder rows are excluded from the
 * SuspectedCases chart + "with diagnosis" KPI by code, but every other
 * report path JOINs disease_code → ref_diseases for display. Without
 * this reference row, those paths render the raw code, which looks
 * broken.
 *
 * This migration inserts a single deactivated reference row that resolves
 * the placeholder to a friendly display name. It is fully idempotent:
 * on second run the existence check short-circuits, on a fresh DB the
 * insert lands once. No down() implementation — placeholder rows in
 * secondary_suspected_diseases would dangle if the ref row disappeared.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('ref_diseases')
            ->where('disease_code', 'no_specific_suspicion')
            ->exists();

        if ($exists) {
            return; // idempotent — already seeded
        }

        DB::table('ref_diseases')->insert([
            'disease_code'        => 'no_specific_suspicion',
            'display_name'        => 'No specific suspicion',
            'ihr_tier'            => 2, // schema default; non-tier-1
            'who_syndrome'        => null,
            'incubation_days_min' => null,
            'incubation_days_max' => null,
            'case_definition'     => json_encode([
                'description' => 'Placeholder. Recorded when the rule engine and '
                              .  'officer override together produced fewer than '
                              .  'three differential hypotheses for a POE case. '
                              .  'Not a clinical entity — surveillance flag only.',
                'cfr_pct'     => 0,
                'severity'    => 0,
                'hallmarks'   => [],
            ]),
            'gates'            => json_encode(new \stdClass()),
            'symptom_weights'  => json_encode(new \stdClass()),
            'exposure_weights' => json_encode(new \stdClass()),
            'triage_overrides' => null,
            'absent_penalties' => json_encode(new \stdClass()),
            'sources'          => json_encode([
                ['id' => 'ECSA_POE_DIRECTIVE_2026_05_20', 'url' => null],
            ]),
            'payload'    => json_encode([
                'id'          => 'no_specific_suspicion',
                'name'        => 'No specific suspicion',
                'placeholder' => true,
            ]),
            // Deactivated so it never surfaces in disease pickers, search,
            // or recommendation panels — only retrieved when an existing
            // secondary_suspected_diseases row references it.
            'is_active'  => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // No-op: removing this row would leave existing
        // secondary_suspected_diseases.disease_code references dangling.
        // If a true rollback is ever needed, do it manually after
        // purging the placeholder rows from secondary_suspected_diseases.
    }
};
