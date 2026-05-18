<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * alert_wizard_state — per-alert resolution-wizard progress.
 *
 * Stores the current step the wizard is on plus an append-only history of
 * decisions an officer has made (each {step, option, timestamp, reason?,
 * evidence_ref?}). The history feeds the case-room timeline and the
 * closure summary; current_step_code lets the wizard pick up where the
 * officer left off.
 *
 * One row per alert. Soft-deletes so a re-opened alert can keep its
 * earlier wizard trail without colliding on the unique index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alert_wizard_state')) return;

        Schema::create('alert_wizard_state', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('alert_id');
            $t->string('current_step_code', 80)->nullable();
            $t->dateTime('last_step_at')->nullable();
            $t->unsignedBigInteger('last_actor_user_id')->nullable();
            $t->json('decisions');
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['alert_id', 'deleted_at'], 'aws_alert_unique_idx');
            $t->index('current_step_code', 'aws_step_idx');
            $t->index('last_actor_user_id', 'aws_actor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_wizard_state');
    }
};
