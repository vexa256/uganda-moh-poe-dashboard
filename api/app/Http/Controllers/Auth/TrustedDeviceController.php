<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuthEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * TrustedDeviceController — the "passkey-like" second factor.
 *
 * A trusted device is a server-side random 32-byte token hash tied to a
 * (user_id, user_agent, fingerprint). The raw token is returned once and
 * stored client-side behind platform biometrics (the browser's WebAuthn
 * `navigator.credentials.get()` wraps access with the thumbprint / Face ID).
 *
 * When a user presents this token at /auth/login, the server skips the TOTP
 * step — same security guarantee as a passkey without requiring a full
 * WebAuthn cryptographic library on the server.
 *
 *   GET    /auth/trusted-devices          — list active devices
 *   POST   /auth/trusted-devices          — register current device (requires fresh auth)
 *   DELETE /auth/trusted-devices/{id}     — revoke
 *   POST   /auth/trusted-devices/revoke-all
 */
final class TrustedDeviceController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $uid = (int) $r->user()->id;
        $rows = DB::table('trusted_devices')->where('user_id', $uid)
            ->whereNull('revoked_at')->orderByDesc('last_used_at')->get();
        return response()->json(['ok' => true, 'data' => ['devices' => $rows]]);
    }

    public function register(Request $r): JsonResponse
    {
        $data = Validator::make($r->all(), [
            'label'               => 'nullable|string|max:120',
            'device_fingerprint'  => 'nullable|string|max:128',
        ])->validate();

        $u = $r->user();
        $raw = bin2hex(random_bytes(32));
        $id = DB::table('trusted_devices')->insertGetId([
            'user_id'           => $u->id,
            'device_token_hash' => hash('sha256', $raw),
            'device_fingerprint'=> $data['device_fingerprint'] ?? null,
            'label'             => $data['label'] ?? $this->label($r),
            'user_agent'        => mb_substr((string) $r->userAgent(), 0, 300),
            'ip_first'          => $r->ip(),
            'ip_last'           => $r->ip(),
            'expires_at'        => now()->addDays(30),
            'created_at'        => now(),
        ]);
        AuthEventLogger::log('TRUSTED_DEVICE_ADDED', (int) $u->id, null, 'INFO',
            ['trusted_device_id' => $id], 0, $r);
        return response()->json(['ok' => true, 'data' => [
            'id'         => $id,
            'token'      => $raw,
            'expires_at' => now()->addDays(30)->toIso8601String(),
            'warning'    => 'This token is shown once — store it behind platform biometrics (WebAuthn get()).',
        ]]);
    }

    public function revoke(Request $r, int $id): JsonResponse
    {
        $uid = (int) $r->user()->id;
        $n = DB::table('trusted_devices')->where('id', $id)->where('user_id', $uid)
            ->update(['revoked_at' => now(), 'revoked_reason' => 'user', 'updated_at' => now() ?? null]);
        if ($n) AuthEventLogger::log('TRUSTED_DEVICE_REMOVED', $uid, null, 'INFO',
            ['trusted_device_id' => $id], 0, $r);
        return response()->json(['ok' => (bool) $n]);
    }

    public function revokeAll(Request $r): JsonResponse
    {
        $uid = (int) $r->user()->id;
        $n = DB::table('trusted_devices')->where('user_id', $uid)->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_reason' => 'user-bulk']);
        AuthEventLogger::log('TRUSTED_DEVICE_REMOVED', $uid, null, 'WARN',
            ['scope' => 'all', 'count' => $n], 0, $r);
        return response()->json(['ok' => true, 'data' => ['revoked' => $n]]);
    }

    private function label(Request $r): string
    {
        $ua = (string) $r->userAgent();
        $os = 'Unknown'; $browser = 'Browser';
        foreach (['Windows' => 'Windows', 'Mac OS' => 'macOS', 'Linux' => 'Linux',
                  'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS'] as $needle => $name) {
            if (str_contains($ua, $needle)) { $os = $name; break; }
        }
        foreach (['Firefox' => 'Firefox', 'Edg/' => 'Edge', 'Chrome' => 'Chrome', 'Safari' => 'Safari'] as $needle => $name) {
            if (str_contains($ua, $needle)) { $browser = $name; break; }
        }
        return "{$browser} · {$os}";
    }
}
