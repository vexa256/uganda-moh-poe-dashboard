<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * idempotency_keys — replay store for the IdempotencyKey middleware.
 *
 * Mutating admin-alerts endpoints accept an `Idempotency-Key` header. The
 * first call's response is captured and stored here keyed by
 * (user_id, endpoint, key); subsequent calls with the same triple within
 * the TTL replay the original response with `X-Idempotent-Replay: 1`.
 *
 * created_at is indexed for nightly cleanup (older than 24h is stale).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('idempotency_keys')) return;

        Schema::create('idempotency_keys', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->string('endpoint', 120);
            $t->string('key', 120);
            $t->unsignedSmallInteger('response_status');
            $t->char('response_hash', 64);
            $t->mediumText('response_body');
            $t->timestamp('created_at')->useCurrent();

            $t->unique(['user_id', 'endpoint', 'key'], 'idempotency_unique_idx');
            $t->index('created_at', 'idempotency_age_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
