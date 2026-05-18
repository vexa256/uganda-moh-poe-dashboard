<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * client_logs — durable telemetry of every client-side error.
 *
 * Architecture
 * ------------
 *  • The mobile + web app captures EVERY error source via
 *    src/services/clientLogger.js (window.onerror, unhandledrejection,
 *    Vue errorHandler / warnHandler, console.error / warn, fetch failures,
 *    explicit logger.error() calls).
 *  • The app POSTs batches to /client-logs (no auth: telemetry must work
 *    even when the user is signed out / token expired). The endpoint
 *    rate-limits and validates payload shape.
 *  • This table is the canonical store. Indexed for the realtime admin
 *    page that filters by level / time / user / device.
 *
 * Sizing
 * ------
 *  message  — up to 8 KB after redaction
 *  stack    — up to 8 KB
 *  breadcrumbs / extra — JSON, up to ~16 KB each
 *
 * Pruning
 * -------
 *  Keep INFO/DEBUG for 14 days, WARN for 60 days, ERROR/FATAL forever.
 *  Add a scheduled prune command in a follow-up if storage grows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_logs', function (Blueprint $t) {
            $t->bigIncrements('id');

            // Idempotency: client generates a UUID per record so retries from
            // the offline queue are dedup'd at insert time.
            $t->uuid('client_uuid')->nullable()->unique();

            // Severity. Indexed because the admin page filters on it.
            $t->enum('level', ['TRACE', 'DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL'])
              ->default('ERROR')->index();

            $t->string('message', 8192);
            $t->string('source', 500)->nullable();
            $t->unsignedInteger('lineno')->nullable();
            $t->unsignedInteger('colno')->nullable();
            $t->text('stack')->nullable();
            $t->string('error_name', 120)->nullable();
            $t->string('error_message', 1024)->nullable();

            // Originating user/device — every field nullable because the
            // client may not be logged in when the error happens.
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('role_key', 60)->nullable()->index();
            $t->string('poe_code', 80)->nullable()->index();

            $t->string('session_id', 64)->nullable()->index();
            $t->string('device_id', 80)->nullable()->index();
            $t->string('app_version', 40)->nullable();
            $t->enum('platform', ['WEB', 'ANDROID', 'IOS'])->nullable()->index();

            $t->string('user_agent', 500)->nullable();
            $t->text('url')->nullable();
            $t->string('route', 500)->nullable();
            $t->boolean('online')->nullable();

            // Breadcrumb trail leading up to the error (JSON array).
            $t->json('breadcrumbs')->nullable();
            // Caller-supplied extra context.
            $t->json('extra')->nullable();

            // When the error occurred client-side (the client's clock).
            $t->timestamp('occurred_at')->nullable()->index();
            // When the server received it (authoritative for ordering).
            $t->timestamp('received_at')->nullable()->index();

            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_logs');
    }
};
