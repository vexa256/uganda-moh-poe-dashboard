<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * user_training_records — workforce competency ledger.
 *
 * Captures who completed which IHR / IDSR / PoE-relevant training, when it
 * was completed, and when a refresher is due. Powers the Workforce → Training
 * surface and feeds risk dashboards (overdue refreshers).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('user_training_records')) {
            return;
        }
        Schema::create('user_training_records', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id')->index();
            $t->string('training_code', 64)->index();           // e.g. IHR_CORE, IDSR_DETECT, JEE_2024
            $t->string('training_title', 191);
            $t->string('competency_domain', 32)->index();       // IHR | IDSR | RTSL | EBS | PORT_HEALTH | LAB | RCCE | OTHER
            $t->string('provider', 191)->nullable();            // WHO AFRO, ZNPHI, RTSL, etc.
            $t->date('completed_on');
            $t->date('expires_on')->nullable();
            $t->string('certificate_no', 96)->nullable();
            $t->string('evidence_url', 500)->nullable();
            $t->unsignedTinyInteger('score')->nullable();       // 0-100
            $t->string('status', 16)->default('VALID');         // VALID | EXPIRING | EXPIRED | REVOKED
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('recorded_by_user_id')->nullable();
            $t->unsignedBigInteger('updated_by_user_id')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->index(['user_id', 'training_code']);
            $t->index(['competency_domain', 'status']);
            $t->index('expires_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_training_records');
    }
};
