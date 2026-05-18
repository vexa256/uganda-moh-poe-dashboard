<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds soft-delete support to ref_poes so the Geo Hierarchy controller
 * can honour the same deleted_at convention used across the rest of
 * the app (alerts, users, notifications, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ref_poes') && !Schema::hasColumn('ref_poes', 'deleted_at')) {
            Schema::table('ref_poes', function (Blueprint $t) {
                $t->softDeletes()->after('updated_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ref_poes') && Schema::hasColumn('ref_poes', 'deleted_at')) {
            Schema::table('ref_poes', function (Blueprint $t) {
                $t->dropSoftDeletes();
            });
        }
    }
};
