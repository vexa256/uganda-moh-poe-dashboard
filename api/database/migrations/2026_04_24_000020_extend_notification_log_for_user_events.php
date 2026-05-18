<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend notification_log into the canonical email_delivery_log surface
 * required by the alerts refactor brief §3.2:
 *   recipient_user_id  → identifies the internal user (assignee/team/originator/management)
 *                         when the recipient is not a poe_notification_contacts row.
 *   action_code        → ALERT_ACKNOWLEDGED / ALERT_REASSIGNED / ALERT_REOPENED / etc.
 *                         distinct from template_code (which is the rendered template key).
 *   cc_addresses       → JSON array of mandatory CC recipients (ops mailbox).
 *   bcc_addresses      → JSON array of optional BCC recipients.
 *   queued_at          → when the queued mail job was pushed onto the queue.
 *
 * Additive only — existing columns and rows untouched. Mobile API never reads
 * these columns; safe to add without ABI risk.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_log')) return;

        Schema::table('notification_log', function (Blueprint $t): void {
            if (! Schema::hasColumn('notification_log', 'recipient_user_id')) {
                $t->unsignedBigInteger('recipient_user_id')->nullable()->after('contact_id');
                $t->index('recipient_user_id', 'nl_recipient_user_idx');
            }
            if (! Schema::hasColumn('notification_log', 'action_code')) {
                $t->string('action_code', 60)->nullable()->after('template_code');
                $t->index('action_code', 'nl_action_idx');
            }
            if (! Schema::hasColumn('notification_log', 'cc_addresses')) {
                $t->text('cc_addresses')->nullable()->after('to_phone');
            }
            if (! Schema::hasColumn('notification_log', 'bcc_addresses')) {
                $t->text('bcc_addresses')->nullable()->after('cc_addresses');
            }
            if (! Schema::hasColumn('notification_log', 'queued_at')) {
                $t->dateTime('queued_at')->nullable()->after('sent_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_log')) return;

        Schema::table('notification_log', function (Blueprint $t): void {
            foreach (['recipient_user_id','action_code','cc_addresses','bcc_addresses','queued_at'] as $c) {
                if (Schema::hasColumn('notification_log', $c)) {
                    if ($c === 'recipient_user_id') { try { $t->dropIndex('nl_recipient_user_idx'); } catch (\Throwable $e) {} }
                    if ($c === 'action_code')       { try { $t->dropIndex('nl_action_idx'); }         catch (\Throwable $e) {} }
                    $t->dropColumn($c);
                }
            }
        });
    }
};
