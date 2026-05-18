<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('poe_status_events')) {
            return;
        }

        Schema::create('poe_status_events', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('country_code', 10)->default('Uganda')->index();
            $t->string('poe_code', 200)->index();
            $t->enum('status', ['OPEN', 'CLOSED', 'REDUCED_HOURS', 'EMERGENCY_CLOSED', 'MAINTENANCE'])
                ->default('OPEN')
                ->index();
            $t->text('reason')->nullable();
            $t->dateTime('started_at')->index();
            $t->dateTime('ended_at')->nullable()->index();
            $t->json('hours_json')->nullable();
            $t->unsignedBigInteger('created_by_user_id')->nullable();
            $t->timestamps();

            $t->index(['poe_code', 'started_at'], 'ix_status_poe_started');
            $t->index(['poe_code', 'ended_at'],   'ix_status_poe_ended');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('poe_status_events');
    }
};
