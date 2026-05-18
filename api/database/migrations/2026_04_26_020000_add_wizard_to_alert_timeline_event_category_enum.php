<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add 'WIZARD' to alert_timeline_events.event_category ENUM.
 *
 * TimelineRecorder::recordWizardDecision and ::recordStakeholderContact
 * insert with event_category='WIZARD'; the original ENUM did not include
 * it, so MySQL was silently truncating the value to '' (or default
 * SYSTEM under strict mode warnings). See storage/logs/laravel.log
 * entries on 2026-04-26 16:48–16:49 for the truncation incidents.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('alert_timeline_events')) return;
        if (! Schema::hasColumn('alert_timeline_events', 'event_category')) return;

        DB::statement(
            "ALTER TABLE `alert_timeline_events` ".
            "MODIFY `event_category` ".
            "ENUM('SYSTEM','HUMAN','EMAIL','WORKFLOW','BREACH','CLINICAL','WIZARD') ".
            "NOT NULL DEFAULT 'SYSTEM'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('alert_timeline_events')) return;
        if (! Schema::hasColumn('alert_timeline_events', 'event_category')) return;

        $hasWizardRows = (int) DB::table('alert_timeline_events')
            ->where('event_category', 'WIZARD')
            ->limit(1)
            ->count();

        if ($hasWizardRows > 0) return;

        DB::statement(
            "ALTER TABLE `alert_timeline_events` ".
            "MODIFY `event_category` ".
            "ENUM('SYSTEM','HUMAN','EMAIL','WORKFLOW','BREACH','CLINICAL') ".
            "NOT NULL DEFAULT 'SYSTEM'"
        );
    }
};
