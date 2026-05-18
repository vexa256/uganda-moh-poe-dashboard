<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend responder_info_requests + alert_evidence for the public token portal.
 *
 *   responder_info_requests
 *     + cancelled_at      DATETIME NULL    — admin revocation timestamp
 *     + cancelled_by      BIGINT UNSIGNED NULL — actor who cancelled
 *     + last_resent_at    DATETIME NULL    — last token rotation
 *     + resend_count      INT UNSIGNED NOT NULL DEFAULT 0
 *     + responder_ip      VARCHAR(45) NULL — recorded on submit (audit)
 *     + responder_ua      VARCHAR(500) NULL — UA string on submit
 *     + last_viewed_at    DATETIME NULL    — last GET on token
 *     + view_count        INT UNSIGNED NOT NULL DEFAULT 0
 *
 *   alert_evidence
 *     + external_responder_id  BIGINT UNSIGNED NULL — FK external_responders.id
 *     + responder_request_id   BIGINT UNSIGNED NULL — FK responder_info_requests.id
 *
 * No row deletes anywhere. uploaded_by_user_id remains NOT NULL — for
 * external uploads we set it to the requested_by_user_id of the request so
 * the existing constraint holds; the *true* origin is the new
 * external_responder_id column, which the UI keys off.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('responder_info_requests')) {
            Schema::table('responder_info_requests', function (Blueprint $t): void {
                if (! Schema::hasColumn('responder_info_requests', 'cancelled_at')) $t->timestamp('cancelled_at')->nullable()->after('responded_at');
                if (! Schema::hasColumn('responder_info_requests', 'cancelled_by')) $t->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
                if (! Schema::hasColumn('responder_info_requests', 'last_resent_at')) $t->timestamp('last_resent_at')->nullable()->after('cancelled_by');
                if (! Schema::hasColumn('responder_info_requests', 'resend_count')) $t->unsignedInteger('resend_count')->default(0)->after('last_resent_at');
                if (! Schema::hasColumn('responder_info_requests', 'responder_ip')) $t->string('responder_ip', 45)->nullable()->after('resend_count');
                if (! Schema::hasColumn('responder_info_requests', 'responder_ua')) $t->string('responder_ua', 500)->nullable()->after('responder_ip');
                if (! Schema::hasColumn('responder_info_requests', 'last_viewed_at')) $t->timestamp('last_viewed_at')->nullable()->after('responder_ua');
                if (! Schema::hasColumn('responder_info_requests', 'view_count')) $t->unsignedInteger('view_count')->default(0)->after('last_viewed_at');
            });
        }
        if (Schema::hasTable('alert_evidence')) {
            Schema::table('alert_evidence', function (Blueprint $t): void {
                if (! Schema::hasColumn('alert_evidence', 'external_responder_id')) {
                    $t->unsignedBigInteger('external_responder_id')->nullable()->after('uploaded_by_user_id')->index();
                }
                if (! Schema::hasColumn('alert_evidence', 'responder_request_id')) {
                    $t->unsignedBigInteger('responder_request_id')->nullable()->after('external_responder_id')->index();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('alert_evidence')) {
            Schema::table('alert_evidence', function (Blueprint $t): void {
                foreach (['responder_request_id', 'external_responder_id'] as $c) {
                    if (Schema::hasColumn('alert_evidence', $c)) $t->dropColumn($c);
                }
            });
        }
        if (Schema::hasTable('responder_info_requests')) {
            Schema::table('responder_info_requests', function (Blueprint $t): void {
                foreach (['view_count','last_viewed_at','responder_ua','responder_ip','resend_count','last_resent_at','cancelled_by','cancelled_at'] as $c) {
                    if (Schema::hasColumn('responder_info_requests', $c)) $t->dropColumn($c);
                }
            });
        }
    }
};
