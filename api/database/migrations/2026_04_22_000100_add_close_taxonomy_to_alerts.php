<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Adds the close-reason taxonomy + reopen tracking to `alerts`, matching the
 * War Room specification in dashboard.txt §M2.
 *
 * New columns:
 *   close_category         — enum of canonical close categories
 *   close_note             — mandatory for OTHER, allowed everywhere
 *   merged_into_alert_id   — when category = DUPLICATE, points to the canonical alert
 *   reopened_at            — most-recent reopen timestamp (null = never reopened)
 *   reopen_count           — how many times this alert has been reopened
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $t) {
            if (! Schema::hasColumn('alerts', 'close_category')) {
                $t->string('close_category', 32)->nullable()->after('closed_at');
            }
            if (! Schema::hasColumn('alerts', 'close_note')) {
                $t->string('close_note', 500)->nullable()->after('close_category');
            }
            if (! Schema::hasColumn('alerts', 'merged_into_alert_id')) {
                $t->unsignedBigInteger('merged_into_alert_id')->nullable()->after('close_note');
            }
            if (! Schema::hasColumn('alerts', 'reopened_at')) {
                $t->dateTime('reopened_at')->nullable()->after('merged_into_alert_id');
            }
            if (! Schema::hasColumn('alerts', 'reopen_count')) {
                $t->unsignedInteger('reopen_count')->default(0)->after('reopened_at');
            }
        });

        // MySQL's `enum` handling differs per version; use a CHECK-style
        // validation column instead so we can add categories without an ALTER.
        // Keep a dedicated index for the dashboard filters.
        try {
            DB::statement('CREATE INDEX idx_alerts_close_category ON alerts (close_category)');
        } catch (\Throwable) { /* idempotent */ }
        try {
            DB::statement('CREATE INDEX idx_alerts_reopened_at ON alerts (reopened_at)');
        } catch (\Throwable) { /* idempotent */ }
        try {
            DB::statement('CREATE INDEX idx_alerts_merged_into ON alerts (merged_into_alert_id)');
        } catch (\Throwable) { /* idempotent */ }
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $t) {
            foreach (['close_category','close_note','merged_into_alert_id','reopened_at','reopen_count'] as $c) {
                if (Schema::hasColumn('alerts', $c)) $t->dropColumn($c);
            }
        });
    }
};
