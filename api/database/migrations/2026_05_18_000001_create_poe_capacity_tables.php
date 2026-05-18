<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotent — re-runnable on any host that may already have these tables
 * partially created out-of-band. We guard with hasTable() / hasColumn() so
 * production deploys never abort on "table already exists" / "duplicate
 * column" errors.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('poe_capacity_assessments')) {
            Schema::create('poe_capacity_assessments', function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->string('country_code', 10)->default('Uganda')->index();
                $t->string('poe_code', 200)->index();
                $t->date('assessment_date');
                $t->enum('status', ['DRAFT', 'SUBMITTED', 'REVIEWED', 'ARCHIVED'])
                    ->default('DRAFT')
                    ->index();
                $t->unsignedTinyInteger('overall_score')->nullable();
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
            Schema::create('poe_capacity_scores', function (Blueprint $t): void {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('assessment_id')->index();
                $t->string('capacity_code', 60)->index();
                $t->string('capacity_label', 200);
                $t->unsignedTinyInteger('score');
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
    }
};
