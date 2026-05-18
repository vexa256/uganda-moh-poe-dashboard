<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `alert_followups` — tracks RTSL 14 early response actions per alert
 * for the 7-1-7 / IHR follow-up workflow.
 *
 * Additive only. No existing table or column is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alert_followups')) {
            return;
        }

        Schema::create('alert_followups', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('client_uuid', 36)->unique();
            $t->unsignedBigInteger('alert_id')->index();
            $t->char('alert_client_uuid', 36)->nullable();

            $t->string('action_code', 60);
            $t->string('action_label', 200);
            $t->enum('status', ['PENDING', 'IN_PROGRESS', 'COMPLETED', 'BLOCKED', 'NOT_APPLICABLE'])
              ->default('PENDING')->index();

            $t->dateTime('due_at')->nullable()->index();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->unsignedBigInteger('completed_by_user_id')->nullable();
            $t->unsignedBigInteger('assigned_to_user_id')->nullable();
            $t->string('assigned_to_role', 40)->nullable();
            $t->string('notes', 500)->nullable();
            $t->string('evidence_ref', 200)->nullable();
            $t->string('who_notification_reference', 80)->nullable();
            $t->boolean('blocks_closure')->default(false);

            $t->string('country_code', 10);
            $t->string('district_code', 30);
            $t->string('poe_code', 40)->index();

            $t->unsignedBigInteger('created_by_user_id');
            $t->string('device_id', 80);
            $t->string('app_version', 40)->nullable();
            $t->enum('platform', ['ANDROID', 'IOS', 'WEB'])->default('ANDROID');
            $t->unsignedInteger('record_version')->default(1);

            $t->enum('sync_status', ['UNSYNCED', 'SYNCED', 'FAILED'])->default('UNSYNCED')->index();
            $t->dateTime('synced_at')->nullable();
            $t->unsignedInteger('sync_attempt_count')->default(0);
            $t->string('last_sync_error', 500)->nullable();

            $t->dateTime('deleted_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_followups');
    }
};
