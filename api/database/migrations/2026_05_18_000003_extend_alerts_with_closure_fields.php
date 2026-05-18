<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds closure/merge/reopen tracking to alerts. Each column is guarded
 * with hasColumn() so the migration is idempotent and can be retried.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('alerts')) {
            return;
        }

        Schema::table('alerts', function (Blueprint $t): void {
            if (! Schema::hasColumn('alerts', 'close_category')) {
                $t->string('close_category', 32)->nullable()->after('status');
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
    }

    public function down(): void
    {
        if (! Schema::hasTable('alerts')) {
            return;
        }

        Schema::table('alerts', function (Blueprint $t): void {
            foreach (['reopen_count', 'reopened_at', 'merged_into_alert_id', 'close_note', 'close_category'] as $col) {
                if (Schema::hasColumn('alerts', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
