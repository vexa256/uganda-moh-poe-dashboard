<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * aggregated_audit — append-only trail for every admin-side state change
 * to aggregated_templates / aggregated_template_columns / aggregated_submissions.
 *
 * Mirrors the shape of reports_access_audit (App\Services\Reports\AccessAuditor)
 * so an auditor reviewing IDSR aggregated-reporting governance has the same
 * fields available as for the reports console: who, what, when, scope, before
 * snapshot, after snapshot, ip, ua, and a per-request id for correlating
 * multi-step operations (e.g. lifecycle → publish → cache invalidation).
 *
 * Additive — the table is created with hasTable guard so re-running the
 * migration on a database that already carries it is a no-op. No mobile
 * contract change. No edits to existing tables. The audit writer
 * (App\Services\AggregatedAudit) wraps every insert in try/catch and logs
 * to laravel.log on failure so a degraded audit pipeline never blocks
 * the user-facing admin action.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('aggregated_audit')) return;

        Schema::create('aggregated_audit', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->string('role_key', 40)->nullable();
            $t->string('scope_level', 20)->nullable();
            $t->string('country_code', 30)->nullable();
            // Action vocabulary mirrors the lifecycle verbs the controller uses.
            // VARCHAR not enum so future verbs do not need a migration.
            $t->string('action', 40);
            // Entity targeted by the action: TEMPLATE, COLUMN, SUBMISSION.
            $t->string('entity_type', 20);
            $t->unsignedBigInteger('entity_id');
            // template_id is denormalised on column / submission audits so
            // a single index by template gives full per-template history.
            $t->unsignedBigInteger('template_id')->nullable();
            // Snapshots — null on pure read-side audits (none today, but
            // future SUBMISSION_VIEW etc. would use them sparingly).
            $t->json('before_json')->nullable();
            $t->json('after_json')->nullable();
            // HTTP envelope for forensic. ip is varchar so IPv6 fits.
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->char('request_id', 36)->nullable();
            $t->timestamp('created_at')->useCurrent();

            $t->index('user_id', 'agg_audit_user_idx');
            $t->index(['entity_type', 'entity_id'], 'agg_audit_entity_idx');
            $t->index('template_id', 'agg_audit_template_idx');
            $t->index('created_at', 'agg_audit_created_idx');
        });
    }

    public function down(): void
    {
        // Append-only. Never drop on rollback — audit must outlive the
        // controller that produced it. Roll forward, never back.
    }
};
