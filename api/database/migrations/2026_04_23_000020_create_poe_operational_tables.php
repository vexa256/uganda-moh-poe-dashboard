<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operational PoE tables — temporal status log + Annex-1A capacity
 * assessment (header + per-capacity scores).
 *
 * - poe_status_events       : time-series of OPEN / CLOSED / REDUCED_HOURS
 *                             / EMERGENCY_CLOSED / MAINTENANCE entries.
 *                             The "current" status of a PoE is the latest
 *                             event with no ended_at.
 * - poe_capacity_assessments: WHO IHR-2005 Annex-1A self-assessment header.
 * - poe_capacity_scores     : 1-5 score per capacity dimension (8 dimensions
 *                             per Annex-1A.1 + extras).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('poe_status_events')) {
            Schema::create('poe_status_events', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10)->default('Zambia')->index();
                $t->string('poe_code', 200)->index();
                $t->enum('status', [
                    'OPEN',
                    'CLOSED',
                    'REDUCED_HOURS',
                    'EMERGENCY_CLOSED',
                    'MAINTENANCE',
                ])->default('OPEN')->index();
                $t->text('reason')->nullable();
                $t->dateTime('started_at')->index();
                $t->dateTime('ended_at')->nullable()->index();
                $t->json('hours_json')->nullable();   // for REDUCED_HOURS
                $t->unsignedBigInteger('created_by_user_id')->nullable();
                $t->timestamps();
                $t->index(['poe_code', 'started_at'], 'ix_status_poe_started');
                $t->index(['poe_code', 'ended_at'],   'ix_status_poe_ended');
            });
        }

        if (! Schema::hasTable('poe_capacity_assessments')) {
            Schema::create('poe_capacity_assessments', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('country_code', 10)->default('Zambia')->index();
                $t->string('poe_code', 200)->index();
                $t->date('assessment_date');
                $t->enum('status', ['DRAFT', 'SUBMITTED', 'REVIEWED', 'ARCHIVED'])
                  ->default('DRAFT')->index();
                $t->unsignedTinyInteger('overall_score')->nullable();   // 0-100
                $t->text('summary')->nullable();
                $t->text('gaps_identified')->nullable();
                $t->text('action_plan')->nullable();
                $t->unsignedBigInteger('assessor_user_id')->nullable();
                $t->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $t->dateTime('reviewed_at')->nullable();
                $t->dateTime('submitted_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['poe_code', 'assessment_date'], 'ix_cap_poe_date');
            });
        }

        if (! Schema::hasTable('poe_capacity_scores')) {
            Schema::create('poe_capacity_scores', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('assessment_id')->index();
                $t->string('capacity_code', 60)->index();
                $t->string('capacity_label', 200);
                $t->unsignedTinyInteger('score');     // 1-5 per Annex-1A scoring
                $t->text('evidence')->nullable();
                $t->text('gap_notes')->nullable();
                $t->timestamps();
                $t->unique(['assessment_id', 'capacity_code'], 'uq_cap_score_pair');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('poe_capacity_scores');
        Schema::dropIfExists('poe_capacity_assessments');
        Schema::dropIfExists('poe_status_events');
    }
};
