<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * alert_case_outcomes — WHO-aligned outcome record per alert.
 *
 * Captured automatically every time an alert closes (normal closure,
 * false-alarm sweep, master-close, lab-confirmation step on the wizard).
 * Backs future analytics: "lab-confirmed VHF cases by month",
 * "case-fatality rate by disease", "discarded vs confirmed cholera by
 * district", etc.
 *
 * Vocabulary follows WHO/IHR-2005 + AFRO IDSR 3rd edition:
 *
 *   case_classification  — SUSPECTED | PROBABLE | CONFIRMED | DISCARDED |
 *                          LOST_TO_FOLLOWUP | UNKNOWN
 *   lab_status           — PENDING | POSITIVE | NEGATIVE | INCONCLUSIVE |
 *                          INSUFFICIENT_SAMPLE | NOT_TESTED
 *   clinical_outcome     — RECOVERED | CONVALESCING | DECEASED |
 *                          LOST_TO_FOLLOWUP | TRANSFERRED | UNKNOWN
 *   ph_action            — STANDARD_SURVEILLANCE | ENHANCED_SURVEILLANCE |
 *                          OUTBREAK_INVESTIGATION | OUTBREAK_RESPONSE |
 *                          IHR_NOTIFIED
 *   outbreak_status      — SPORADIC | CLUSTER | OUTBREAK | EPIDEMIC |
 *                          PANDEMIC | NONE
 *
 * One row per alert (UNIQUE alert_id + deleted_at). Re-opens get a fresh
 * row by soft-deleting the previous one — preserves audit history.
 *
 * Mobile app does not read this table — pure analytics surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alert_case_outcomes')) return;

        Schema::create('alert_case_outcomes', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('alert_id');

            $t->string('case_classification', 40);                     // SUSPECTED|PROBABLE|CONFIRMED|DISCARDED|LOST_TO_FOLLOWUP|UNKNOWN
            $t->text('case_classification_reason')->nullable();

            $t->string('lab_status', 40)->nullable();                  // PENDING|POSITIVE|NEGATIVE|INCONCLUSIVE|INSUFFICIENT_SAMPLE|NOT_TESTED
            $t->string('lab_disease_code', 80)->nullable();            // confirmed disease (FK ref_diseases.disease_code)
            $t->string('lab_test_method', 120)->nullable();            // PCR | RDT | Serology | Culture | …
            $t->dateTime('lab_confirmed_at')->nullable();

            $t->string('clinical_outcome', 40)->nullable();            // RECOVERED|CONVALESCING|DECEASED|LOST_TO_FOLLOWUP|TRANSFERRED|UNKNOWN
            $t->dateTime('clinical_outcome_at')->nullable();

            $t->string('ph_action', 40)->nullable();                   // public-health action taken
            $t->string('outbreak_status', 40)->default('NONE');        // outbreak classification

            $t->boolean('ihr_notified')->default(false);
            $t->dateTime('ihr_notified_at')->nullable();
            $t->string('ihr_reference', 120)->nullable();

            $t->unsignedBigInteger('recorded_by_user_id')->nullable();
            $t->dateTime('recorded_at');
            $t->string('source', 40)->default('WIZARD');               // WIZARD | MASTER_CLOSE | FALSE_ALARM | LAB_RESULT | MANUAL

            $t->text('notes')->nullable();
            $t->json('payload')->nullable();                            // extensible — extra structured detail

            $t->timestamps();
            $t->softDeletes();

            $t->unique(['alert_id', 'deleted_at'], 'aco_alert_unique_idx');
            $t->index('case_classification', 'aco_class_idx');
            $t->index(['lab_disease_code', 'case_classification'], 'aco_disease_class_idx');
            $t->index('clinical_outcome', 'aco_clinical_idx');
            $t->index('recorded_at', 'aco_recorded_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_case_outcomes');
    }
};
