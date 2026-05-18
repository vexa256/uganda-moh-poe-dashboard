<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * UserAnomalyService — hardcoded AI-free rule engine that scores user risk
 * and raises flags on user_anomaly_flags. Every rule is intentionally
 * deterministic so admins can reproduce + audit the reasoning.
 *
 * Risk score = sum of flag weights, clamped to [0, 100].
 *
 *   Flag code                       · weight · severity  · when
 *   ─────────────────────────────────────────────────────────────────────
 *   DORMANT                         ·   20   · MEDIUM    · last_activity > 30d
 *   PASSWORD_STALE                  ·   15   · MEDIUM    · password_changed > 90d
 *   FREQUENT_FAILED_LOGINS          ·   25   · HIGH      · ≥5 failures in last 24h
 *   MULTIPLE_IPS_24H                ·   20   · HIGH      · ≥4 distinct IPs in last 24h
 *   MULTIPLE_DEVICES_24H            ·   15   · MEDIUM    · ≥3 distinct UAs in last 24h
 *   NO_MFA_FOR_ADMIN                ·   30   · HIGH      · NATIONAL/PHEOC admin w/o 2FA
 *   WEAK_PASSWORD_AGE               ·   10   · LOW       · first-login password never changed
 *   INVITATION_OLD                  ·   15   · MEDIUM    · invitation > 7d unaccepted
 *   ACCOUNT_NEVER_USED              ·   10   · LOW       · created > 30d ago, 0 logins
 *   ROLE_ACTIVITY_MISMATCH          ·   15   · MEDIUM    · NATIONAL_ADMIN active in one POE only
 *   UNUSUAL_HOURS                   ·   10   · LOW       · ≥3 logins 23:00-05:00 UTC in last 7d
 *   IMPOSSIBLE_TRAVEL               ·   40   · CRITICAL  · 2 logins from IPs >2000km apart within 2h
 *   EMAIL_UNVERIFIED_ADMIN          ·   25   · HIGH      · admin role, email_verified_at NULL, >24h old
 *   LOCKED_OUT                      ·   20   · HIGH      · locked_until in the future
 *
 * Usage:
 *   UserAnomalyService::scanUser($userId)   — rescans one user, returns flags
 *   UserAnomalyService::scanAll()           — sweeps every active user
 *   UserAnomalyService::riskScore($userId)  — returns cached score
 */
final class UserAnomalyService
{
    public const FLAG_WEIGHTS = [
        'DORMANT' => 20,
        'PASSWORD_STALE' => 15,
        'FREQUENT_FAILED_LOGINS' => 25,
        'MULTIPLE_IPS_24H' => 20,
        'MULTIPLE_DEVICES_24H' => 15,
        'NO_MFA_FOR_ADMIN' => 30,
        'WEAK_PASSWORD_AGE' => 10,
        'INVITATION_OLD' => 15,
        'ACCOUNT_NEVER_USED' => 10,
        'ROLE_ACTIVITY_MISMATCH' => 15,
        'UNUSUAL_HOURS' => 10,
        'IMPOSSIBLE_TRAVEL' => 40,
        'EMAIL_UNVERIFIED_ADMIN' => 25,
        'LOCKED_OUT' => 20,
    ];

    public const FLAG_SEVERITY = [
        'DORMANT' => 'MEDIUM',
        'PASSWORD_STALE' => 'MEDIUM',
        'FREQUENT_FAILED_LOGINS' => 'HIGH',
        'MULTIPLE_IPS_24H' => 'HIGH',
        'MULTIPLE_DEVICES_24H' => 'MEDIUM',
        'NO_MFA_FOR_ADMIN' => 'HIGH',
        'WEAK_PASSWORD_AGE' => 'LOW',
        'INVITATION_OLD' => 'MEDIUM',
        'ACCOUNT_NEVER_USED' => 'LOW',
        'ROLE_ACTIVITY_MISMATCH' => 'MEDIUM',
        'UNUSUAL_HOURS' => 'LOW',
        'IMPOSSIBLE_TRAVEL' => 'CRITICAL',
        'EMAIL_UNVERIFIED_ADMIN' => 'HIGH',
        'LOCKED_OUT' => 'HIGH',
    ];

    /**
     * Run all rules on one user. Raises/updates rows in user_anomaly_flags
     * and updates users.risk_score + risk_score_updated_at + risk_flags_json.
     * Never throws.
     */
    public static function scanUser(int $userId): array
    {
        try {
            $u = DB::table('users')->where('id', $userId)->first();
            if (! $u) return ['error' => 'user not found'];

            $raised = [];

            // DORMANT
            if ($u->last_activity_at && strtotime((string) $u->last_activity_at) < strtotime('-30 days')) {
                $raised['DORMANT'] = ['days_since' => (int) ((time() - strtotime((string) $u->last_activity_at)) / 86400)];
            }

            // PASSWORD_STALE
            if ($u->password_changed_at && strtotime((string) $u->password_changed_at) < strtotime('-90 days')) {
                $raised['PASSWORD_STALE'] = ['days_since' => (int) ((time() - strtotime((string) $u->password_changed_at)) / 86400)];
            }
            if (! $u->password_changed_at && $u->created_at && strtotime((string) $u->created_at) < strtotime('-14 days')) {
                $raised['WEAK_PASSWORD_AGE'] = ['note' => 'password never rotated since account creation'];
            }

            // FREQUENT_FAILED_LOGINS — 24h window
            $fails = DB::table('auth_events')->where('user_id', $userId)
                ->where('event_type', 'LOGIN_FAIL')
                ->where('created_at', '>=', now()->subDay())->count();
            if ($fails >= 5) $raised['FREQUENT_FAILED_LOGINS'] = ['count_24h' => $fails];

            // MULTIPLE_IPS_24H
            $ips = DB::table('auth_events')->where('user_id', $userId)
                ->where('event_type', 'LOGIN_OK')
                ->where('created_at', '>=', now()->subDay())
                ->distinct()->pluck('ip')->filter()->values()->all();
            if (count($ips) >= 4) $raised['MULTIPLE_IPS_24H'] = ['ips' => $ips];

            // MULTIPLE_DEVICES_24H
            $uas = DB::table('auth_events')->where('user_id', $userId)
                ->where('event_type', 'LOGIN_OK')
                ->where('created_at', '>=', now()->subDay())
                ->distinct()->pluck('user_agent')->filter()->values()->all();
            if (count($uas) >= 3) $raised['MULTIPLE_DEVICES_24H'] = ['ua_count' => count($uas)];

            // NO_MFA_FOR_ADMIN
            $adminRoles = ['NATIONAL_ADMIN', 'PHEOC_OFFICER', 'DISTRICT_SUPERVISOR', 'POE_ADMIN'];
            $isAdmin = in_array((string) ($u->account_type ?? $u->role_key ?? ''), $adminRoles, true)
                     || in_array((string) $u->role_key, $adminRoles, true);
            if ($isAdmin && ! $u->two_factor_confirmed_at) {
                $raised['NO_MFA_FOR_ADMIN'] = ['role' => $u->role_key];
            }

            // EMAIL_UNVERIFIED_ADMIN
            if ($isAdmin && ! $u->email_verified_at && $u->created_at
                && strtotime((string) $u->created_at) < strtotime('-1 day')) {
                $raised['EMAIL_UNVERIFIED_ADMIN'] = ['created_at' => $u->created_at];
            }

            // INVITATION_OLD
            if ($u->invitation_token_hash && ! $u->invitation_accepted_at
                && $u->invitation_expires_at && strtotime((string) $u->invitation_expires_at) < time()) {
                $raised['INVITATION_OLD'] = ['expired_at' => $u->invitation_expires_at];
            }

            // ACCOUNT_NEVER_USED
            if (! $u->last_login_at && $u->created_at
                && strtotime((string) $u->created_at) < strtotime('-30 days')) {
                $raised['ACCOUNT_NEVER_USED'] = ['created_at' => $u->created_at];
            }

            // UNUSUAL_HOURS
            $oddHourLogins = DB::table('auth_events')->where('user_id', $userId)
                ->where('event_type', 'LOGIN_OK')
                ->where('created_at', '>=', now()->subDays(7))
                ->whereRaw('HOUR(created_at) NOT BETWEEN 5 AND 22')
                ->count();
            if ($oddHourLogins >= 3) $raised['UNUSUAL_HOURS'] = ['count_7d' => $oddHourLogins];

            // IMPOSSIBLE_TRAVEL — heuristic on IP changes within 2h
            $recent = DB::table('auth_events')->where('user_id', $userId)
                ->where('event_type', 'LOGIN_OK')
                ->where('created_at', '>=', now()->subHours(2))
                ->whereNotNull('ip')
                ->select('ip', 'created_at')->orderBy('created_at')->get();
            if ($recent->count() >= 2) {
                $ipSet = $recent->pluck('ip')->unique();
                if ($ipSet->count() >= 2 && self::looksLikeDifferentContinents($ipSet->all())) {
                    $raised['IMPOSSIBLE_TRAVEL'] = ['ips' => $ipSet->values()->all()];
                }
            }

            // LOCKED_OUT
            if ($u->locked_until && strtotime((string) $u->locked_until) > time()) {
                $raised['LOCKED_OUT'] = ['until' => $u->locked_until];
            }

            // ROLE_ACTIVITY_MISMATCH — national admin with all activity from one POE
            if (strtoupper((string) $u->role_key) === 'NATIONAL_ADMIN') {
                $distinctPoes = DB::table('primary_screenings')
                    ->where('captured_by_user_id', $userId)
                    ->where('captured_at', '>=', now()->subDays(30))
                    ->distinct()->pluck('poe_code')->count();
                if ($distinctPoes === 1) {
                    $raised['ROLE_ACTIVITY_MISMATCH'] = ['distinct_poes_30d' => 1];
                }
            }

            // ── persist flags (upsert + expire) ───────────────────────────
            $now = now();
            $active = [];
            foreach ($raised as $code => $evidence) {
                $existing = DB::table('user_anomaly_flags')
                    ->where('user_id', $userId)->where('flag_code', $code)->first();
                if ($existing) {
                    DB::table('user_anomaly_flags')->where('id', $existing->id)->update([
                        'severity'      => self::FLAG_SEVERITY[$code] ?? 'LOW',
                        'evidence_json' => json_encode($evidence),
                        'last_seen_at'  => $now,
                        'cleared_at'    => null, 'cleared_by_user_id' => null, 'clearance_note' => null,
                    ]);
                } else {
                    DB::table('user_anomaly_flags')->insert([
                        'user_id' => $userId, 'flag_code' => $code,
                        'severity' => self::FLAG_SEVERITY[$code] ?? 'LOW',
                        'evidence_json' => json_encode($evidence),
                        'first_seen_at' => $now, 'last_seen_at' => $now,
                        'created_at' => $now,
                    ]);
                }
                $active[] = $code;
            }

            // Auto-clear any flag no longer raised (fresh scan = truth).
            DB::table('user_anomaly_flags')->where('user_id', $userId)
                ->whereNull('cleared_at')
                ->when(! empty($active), fn($q) => $q->whereNotIn('flag_code', $active))
                ->update(['cleared_at' => $now, 'clearance_note' => 'Auto-cleared — rule no longer matches']);

            // Compute risk score
            $score = 0;
            foreach ($active as $code) $score += self::FLAG_WEIGHTS[$code] ?? 0;
            $score = min(100, $score);

            DB::table('users')->where('id', $userId)->update([
                'risk_score'            => $score,
                'risk_score_updated_at' => $now,
                'risk_flags_json'       => json_encode($active),
                'updated_at'            => $now,
            ]);

            return ['user_id' => $userId, 'risk_score' => $score, 'flags' => $active];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /** Scan every active user. Returns counts. */
    public static function scanAll(): array
    {
        $users = DB::table('users')->where('is_active', 1)->whereNull('suspended_at')->pluck('id');
        $scanned = 0; $flagged = 0;
        foreach ($users as $uid) {
            $r = self::scanUser((int) $uid);
            $scanned++;
            if (! empty($r['flags'])) $flagged++;
        }
        return ['scanned' => $scanned, 'flagged' => $flagged];
    }

    /** Quick risk-score lookup without re-scanning. */
    public static function riskScore(int $userId): int
    {
        return (int) DB::table('users')->where('id', $userId)->value('risk_score');
    }

    /**
     * Very coarse heuristic: look only at the first octet of IPv4 addresses
     * to decide if two IPs are "probably continents apart". This is a
     * placeholder pending a real GeoIP DB (MaxMind/ipdata) — it will have
     * false negatives but avoids false positives within the same subnet.
     */
    private static function looksLikeDifferentContinents(array $ips): bool
    {
        $octets = [];
        foreach ($ips as $ip) {
            $parts = explode('.', (string) $ip);
            if (count($parts) === 4) $octets[] = (int) $parts[0];
        }
        $distinct = array_unique($octets);
        if (count($distinct) < 2) return false;
        sort($distinct);
        return (max($distinct) - min($distinct)) > 20;   // noisy but conservative
    }
}
