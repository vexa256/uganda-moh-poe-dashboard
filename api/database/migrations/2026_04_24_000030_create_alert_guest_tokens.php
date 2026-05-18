<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * alert_guest_tokens — single-use signed-URL ledger for non-account recipients.
 *
 * Backs the §3.3 guest-landing flow: when a poe_notification_contacts row's
 * email does not match an active users.email, the dispatcher mints a token
 * here (storing only sha256(plaintext)) and embeds the plaintext into the CTA
 * URL via Public/AlertGuestController. The landing page renders a read-only
 * case summary or fires a one-shot acknowledge, then marks the row USED so
 * the link cannot be replayed.
 *
 * Token lifecycle:
 *   ISSUED → CONSUMED  (single use)
 *   ISSUED → EXPIRED   (TTL elapsed without use)
 *
 * Hash storage means a leaked DB never reveals usable tokens.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('alert_guest_tokens')) return;

        Schema::create('alert_guest_tokens', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->char('token_hash', 64)->unique();        // sha256 of plaintext
            $t->unsignedBigInteger('alert_id');
            $t->string('recipient_email', 190);
            $t->string('scope', 24);                     // 'view' | 'ack'
            $t->unsignedBigInteger('issued_by_user_id')->nullable();
            $t->dateTime('expires_at');
            $t->dateTime('consumed_at')->nullable();
            $t->string('consumed_ip', 45)->nullable();
            $t->string('consumed_ua', 255)->nullable();
            $t->string('status', 16)->default('ISSUED'); // ISSUED|CONSUMED|EXPIRED|REVOKED
            $t->text('note')->nullable();                // optional context for audit
            $t->timestamps();

            $t->index('alert_id', 'agt_alert_idx');
            $t->index(['recipient_email', 'alert_id', 'scope'], 'agt_dedup_idx');
            $t->index('expires_at', 'agt_exp_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_guest_tokens');
    }
};
