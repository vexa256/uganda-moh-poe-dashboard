<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Totp — RFC 6238 Time-based One-Time Password (Google Authenticator compatible).
 *
 * Pure-PHP, no composer dep. 30-second period, 6-digit code, SHA-1 HMAC
 * (Google Authenticator + 1Password + Authy + Microsoft Authenticator all
 * use those defaults — widest compatibility).
 *
 *   $secret = Totp::generateSecret();
 *   $uri    = Totp::provisioningUri($secret, 'alice@poe', 'POE Sentinel');
 *   $ok     = Totp::verify($secret, $userSubmittedCode, drift: 1);
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; // RFC 4648 base32

    /** Generates a cryptographically-random 160-bit base32 secret. */
    public static function generateSecret(int $bits = 160): string
    {
        $bytes = random_bytes((int) ceil($bits / 8));
        return self::base32Encode($bytes);
    }

    /** otpauth:// URI the Authenticator apps can scan as a QR code. */
    public static function provisioningUri(string $secret, string $accountName, string $issuer): string
    {
        $label = rawurlencode("{$issuer}:{$accountName}");
        $params = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * Verify a user-supplied TOTP code against the secret.
     * $drift allows ±N steps (30s each) of clock skew. 1 is standard.
     * Returns true on first match and runs constant-time comparison.
     */
    public static function verify(string $secret, string $code, int $drift = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! ctype_digit($code) || strlen($code) !== self::DIGITS) return false;
        $now = (int) floor(time() / self::PERIOD);
        for ($i = -$drift; $i <= $drift; $i++) {
            if (hash_equals(self::generate($secret, $now + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    /** Generate the code for a specific time-step (mostly useful for tests). */
    public static function generate(string $secret, ?int $timestep = null): string
    {
        $timestep ??= (int) floor(time() / self::PERIOD);
        $key = self::base32Decode($secret);
        $bin = pack('N*', 0) . pack('N*', $timestep);    // 8-byte big-endian
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $truncated = (ord($hash[$offset]) & 0x7f) << 24
                   | (ord($hash[$offset + 1]) & 0xff) << 16
                   | (ord($hash[$offset + 2]) & 0xff) << 8
                   | (ord($hash[$offset + 3]) & 0xff);
        $code = $truncated % (10 ** self::DIGITS);
        return str_pad((string) $code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    // ── base32 (RFC 4648) ────────────────────────────────────────────────
    public static function base32Encode(string $bin): string
    {
        if ($bin === '') return '';
        $out = '';
        $buf = 0;
        $bits = 0;
        $len = strlen($bin);
        for ($i = 0; $i < $len; $i++) {
            $buf = ($buf << 8) | ord($bin[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($buf >> $bits) & 0x1f];
            }
        }
        if ($bits > 0) $out .= self::ALPHABET[($buf << (5 - $bits)) & 0x1f];
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32) ?? '');
        if ($b32 === '') return '';
        $out = '';
        $buf = 0;
        $bits = 0;
        $alphabet = self::ALPHABET;
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos($alphabet, $b32[$i]);
            if ($pos === false) continue;
            $buf = ($buf << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buf >> $bits) & 0xff);
            }
        }
        return $out;
    }

    /** Generate a pack of N cryptographically-random recovery codes (8 x 5-char). */
    public static function generateRecoveryCodes(int $n = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $n; $i++) {
            $bytes = random_bytes(5);
            $codes[] = strtoupper(substr(self::base32Encode($bytes), 0, 10));
        }
        return $codes;
    }
}
