<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reports_access_audit — append-only audit trail for the My Reports module.
 *
 * Mandated by the Paranoid v2 brief §4.4: every analytical query is logged
 * with the user, role, scope, and SQL filter applied; every PII reveal is
 * logged separately. National admins read; nobody edits or deletes.
 *
 * Web-only table. Mobile API never reads or writes here.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('reports_access_audit')) {
            return;
        }

        Schema::create('reports_access_audit', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->string('role_key', 40);
            $t->string('account_type', 40)->nullable();
            $t->string('scope_level', 20);
            $t->boolean('is_super')->default(false);
            $t->json('scope_json')->nullable();
            $t->string('report_key', 40);
            $t->enum('action', ['VIEW', 'PII_REVEAL', 'DRILL', 'EXPORT_PREVIEW', 'DENIED']);
            $t->json('filters_json')->nullable();
            $t->unsignedInteger('row_count')->default(0);
            $t->unsignedInteger('suppressed_count')->default(0);
            $t->json('pii_columns_revealed')->nullable();
            $t->unsignedSmallInteger('http_status')->default(200);
            $t->char('request_id', 36)->nullable();
            $t->dateTime('created_at')->useCurrent();

            $t->index(['user_id', 'created_at'], 'idx_audit_user_time');
            $t->index(['report_key', 'created_at'], 'idx_audit_report_time');
            $t->index(['scope_level', 'created_at'], 'idx_audit_scope_time');
            $t->index(['action', 'created_at'], 'idx_audit_action_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports_access_audit');
    }
};
