<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthEventLogger;
use App\Services\AuthMailer;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * TwoFactorController — TOTP (RFC 6238) + recovery codes.
 *
 *   POST /auth/2fa/setup     — issue a fresh secret + otpauth:// URI + QR-safe payload
 *   POST /auth/2fa/confirm   — prove the Authenticator is linked; activates 2FA
 *   POST /auth/2fa/disable   — requires current password; wipes secret + recovery
 *   POST /auth/2fa/recovery-codes — regenerate codes (returns 10, unhashed, once)
 *
 * Setup flow:
 *   1. /setup → server stores secret in a PENDING slot (cache), not users.
 *   2. /confirm with a 6-digit code → if valid, secret moves to users.two_factor_secret,
 *      two_factor_confirmed_at = now(), 10 recovery codes are generated and hashed.
 */
final class TwoFactorController extends Controller
{
    public function setup(Request $r): JsonResponse
    {
        $u = $r->user();
        $secret = Totp::generateSecret(160);
        $issuer = 'POE Sentinel';
        $account = $u->email ?: $u->username ?: ('user-' . $u->id);
        $uri = Totp::provisioningUri($secret, $account, $issuer);

        cache()->put('auth.2fa.pending.' . $u->id, $secret, now()->addMinutes(10));
        AuthEventLogger::log('TWOFA_SETUP_STARTED', (int) $u->id, null, 'INFO', null, 0, $r);

        return response()->json(['ok' => true, 'data' => [
            'secret'             => $secret,
            'provisioning_uri'   => $uri,
            'issuer'             => $issuer,
            'account'            => $account,
            'expires_in_minutes' => 10,
        ]]);
    }

    public function confirm(Request $r): JsonResponse
    {
        $data = Validator::make($r->all(), ['code' => 'required|string|max:10'])->validate();
        $u = $r->user();
        $secret = cache()->get('auth.2fa.pending.' . $u->id);
        if (! $secret) return response()->json(['ok' => false, 'error' => 'Setup expired — restart'], 410);
        if (! Totp::verify($secret, $data['code'], drift: 1)) {
            AuthEventLogger::log('TWOFA_FAIL', (int) $u->id, null, 'WARN', ['stage' => 'confirm'], 2, $r);
            return response()->json(['ok' => false, 'error' => 'Invalid code'], 401);
        }
        // Generate recovery codes — plaintext returned ONCE; only hashes stored.
        $recovery = Totp::generateRecoveryCodes(10);
        $hashes = array_map(fn($c) => hash('sha256', strtoupper($c)), $recovery);

        DB::table('users')->where('id', $u->id)->update([
            'two_factor_secret'              => $secret,
            'two_factor_recovery_codes_hash' => json_encode($hashes),
            'two_factor_confirmed_at'        => now(),
            'updated_at'                     => now(),
        ]);
        cache()->forget('auth.2fa.pending.' . $u->id);

        AuthEventLogger::log('TWOFA_ENABLED', (int) $u->id, null, 'INFO', null, -5, $r);
        AuthMailer::send('AUTH_TWOFA_ENABLED', (string) $u->email, [
            'user_id' => (int) $u->id, 'email' => $u->email,
            'full_name' => $u->full_name ?? '',
            'app_name' => 'POE Sentinel',
            'now' => now()->format('Y-m-d H:i'),
            'ip' => $r->ip(),
        ]);

        return response()->json(['ok' => true, 'data' => [
            'confirmed'      => true,
            'recovery_codes' => $recovery,
            'warning'        => 'Store these recovery codes in your password manager — they will not be shown again.',
        ]]);
    }

    public function disable(Request $r): JsonResponse
    {
        $data = Validator::make($r->all(), [
            'current_password' => 'required|string',
        ])->validate();

        $u = $r->user();
        $fresh = DB::table('users')->where('id', $u->id)->first();
        $hash = (string) ($fresh->password ?? $fresh->password_hash ?? '');
        if (! Hash::check($data['current_password'], $hash)) {
            AuthEventLogger::log('TWOFA_DISABLE_FAIL', (int) $u->id, null, 'WARN', null, 3, $r);
            return response()->json(['ok' => false, 'error' => 'Password is incorrect'], 400);
        }
        DB::table('users')->where('id', $u->id)->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes_hash' => null,
            'two_factor_confirmed_at' => null,
            'updated_at' => now(),
        ]);
        AuthEventLogger::log('TWOFA_DISABLED', (int) $u->id, null, 'WARN', null, 10, $r);
        AuthMailer::send('AUTH_TWOFA_DISABLED', (string) $u->email, [
            'user_id' => (int) $u->id, 'email' => $u->email,
            'full_name' => $u->full_name ?? '', 'app_name' => 'POE Sentinel',
            'now' => now()->format('Y-m-d H:i'), 'ip' => $r->ip(),
        ]);
        return response()->json(['ok' => true]);
    }

    public function regenerateRecoveryCodes(Request $r): JsonResponse
    {
        $data = Validator::make($r->all(), [
            'current_password' => 'required|string',
        ])->validate();

        $u = $r->user();
        $fresh = DB::table('users')->where('id', $u->id)->first();
        $hash = (string) ($fresh->password ?? $fresh->password_hash ?? '');
        if (! Hash::check($data['current_password'], $hash)) {
            return response()->json(['ok' => false, 'error' => 'Password is incorrect'], 400);
        }
        if (! $fresh->two_factor_confirmed_at) {
            return response()->json(['ok' => false, 'error' => '2FA not enabled'], 409);
        }
        $recovery = Totp::generateRecoveryCodes(10);
        $hashes = array_map(fn($c) => hash('sha256', strtoupper($c)), $recovery);
        DB::table('users')->where('id', $u->id)->update([
            'two_factor_recovery_codes_hash' => json_encode($hashes),
            'updated_at' => now(),
        ]);
        AuthEventLogger::log('TWOFA_RECOVERY_REGENERATED', (int) $u->id, null, 'INFO', null, 0, $r);
        return response()->json(['ok' => true, 'data' => ['recovery_codes' => $recovery]]);
    }

    public function status(Request $r): JsonResponse
    {
        $u = DB::table('users')->where('id', $r->user()->id)->first();
        $remaining = 0;
        if ($u->two_factor_recovery_codes_hash) {
            $remaining = count(array_filter((array) json_decode((string) $u->two_factor_recovery_codes_hash, true)));
        }
        return response()->json(['ok' => true, 'data' => [
            'enabled'                 => (bool) $u->two_factor_confirmed_at,
            'confirmed_at'            => $u->two_factor_confirmed_at,
            'recovery_codes_remaining'=> $remaining,
        ]]);
    }
}
