<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AlertGuestTokens — mints + consumes single-use signed tokens for non-account
 * recipients of alerts emails (alerts refactor §3.3).
 *
 * Storage shape: alert_guest_tokens.token_hash carries sha256 of the plaintext.
 * The plaintext is returned ONLY by issue() and never persisted, so a leaked
 * DB cannot reveal usable tokens.
 *
 * Public surface:
 *   issue($alertId, $email, $scope, $ttlMinutes)  → ['plaintext','url']
 *   resolve($plaintext)                           → ?object  (the row, or null)
 *   consume($plaintext, $ip, $ua)                 → ?object  (consumed row)
 *   isExpired(object $row)                        → bool
 */
final class AlertGuestTokens
{
    /** Default TTL minutes when no override is given. 48 hours. */
    public const DEFAULT_TTL_MINUTES = 60 * 48;

    /** Allowed scopes — match the controller's branch table. */
    public const SCOPES = ['view', 'ack'];

    /**
     * Mint a new token for the given (alert, email, scope) triple. Returns
     * ['plaintext' => '...', 'url' => '/g/alert/...', 'expires_at' => '...'].
     * Returns ['error' => '...'] on failure (no exception thrown).
     */
    public static function issue(
        int $alertId,
        string $email,
        string $scope = 'view',
        ?int $ttlMinutes = null,
        ?int $issuedByUserId = null,
        ?string $note = null,
    ): array {
        try {
            if ($alertId <= 0)                            return ['error' => 'alert_id required'];
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) return ['error' => 'invalid email'];
            if (! in_array($scope, self::SCOPES, true))   return ['error' => 'invalid scope'];

            $ttl       = max(15, min(60 * 24 * 14, $ttlMinutes ?? self::DEFAULT_TTL_MINUTES));
            $plaintext = bin2hex(random_bytes(24)); // 48 chars
            $hash      = hash('sha256', $plaintext);
            $expiresAt = Carbon::now()->addMinutes($ttl);

            DB::table('alert_guest_tokens')->insert([
                'token_hash'        => $hash,
                'alert_id'          => $alertId,
                'recipient_email'   => mb_strtolower(trim($email)),
                'scope'             => $scope,
                'issued_by_user_id' => $issuedByUserId,
                'expires_at'        => $expiresAt,
                'status'            => 'ISSUED',
                'note'              => $note ? mb_substr($note, 0, 240) : null,
                'created_at'        => Carbon::now(),
                'updated_at'        => Carbon::now(),
            ]);

            $url = AdminLinks::base() . '/g/alert/' . $plaintext . ($scope === 'ack' ? '/ack' : '/view');
            return [
                'plaintext'  => $plaintext,
                'url'        => $url,
                'expires_at' => $expiresAt->toIso8601String(),
                'scope'      => $scope,
            ];
        } catch (Throwable $e) {
            Log::error('[AlertGuestTokens::issue] ' . $e->getMessage());
            return ['error' => 'issue_failed'];
        }
    }

    /** Resolve a plaintext token into the alert_guest_tokens row, or null. */
    public static function resolve(string $plaintext): ?object
    {
        if ($plaintext === '') return null;
        $hash = hash('sha256', $plaintext);
        return DB::table('alert_guest_tokens')->where('token_hash', $hash)->first() ?: null;
    }

    public static function isExpired(object $row): bool
    {
        try {
            return Carbon::parse($row->expires_at)->isPast();
        } catch (Throwable $e) { return true; }
    }

    /**
     * Consume a token (one-shot). Returns the freshly-updated row or null if
     * the token is missing, already consumed, expired, or revoked.
     *
     * Race-safe: uses a transactional lockForUpdate row so two concurrent
     * requests cannot both observe ISSUED and both succeed. The first thread
     * flips it to CONSUMED; the second sees CONSUMED and returns null.
     *
     * Strict single-use: there is NO idempotency window. A re-clicked link
     * returns null and the controller renders a "link consumed" page. This
     * matches the brief: "token expires on use".
     */
    public static function consume(string $plaintext, ?string $ip = null, ?string $ua = null): ?object
    {
        if ($plaintext === '') return null;
        $hash = hash('sha256', $plaintext);
        try {
            return DB::transaction(function () use ($hash, $ip, $ua): ?object {
                $row = DB::table('alert_guest_tokens')
                    ->where('token_hash', $hash)
                    ->lockForUpdate()
                    ->first();
                if (! $row) return null;
                if ($row->status !== 'ISSUED') return null;
                if (Carbon::parse($row->expires_at)->isPast()) {
                    DB::table('alert_guest_tokens')->where('id', $row->id)
                        ->update(['status' => 'EXPIRED', 'updated_at' => Carbon::now()]);
                    return null;
                }
                DB::table('alert_guest_tokens')->where('id', $row->id)->update([
                    'status'      => 'CONSUMED',
                    'consumed_at' => Carbon::now(),
                    'consumed_ip' => $ip ? mb_substr($ip, 0, 45) : null,
                    'consumed_ua' => $ua ? mb_substr($ua, 0, 255) : null,
                    'updated_at'  => Carbon::now(),
                ]);
                return DB::table('alert_guest_tokens')->where('id', $row->id)->first();
            });
        } catch (Throwable $e) {
            Log::error('[AlertGuestTokens::consume] ' . $e->getMessage());
            return null;
        }
    }
}
