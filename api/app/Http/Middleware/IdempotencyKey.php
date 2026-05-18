<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency-Key support for mutating admin-alerts endpoints.
 *
 *   POST/PATCH/PUT/DELETE   with header  Idempotency-Key: <uuid>
 *     1st call → response is captured + stored
 *     2nd call (same user + same path + same key) → original response replayed
 *
 * The middleware is safe to wire in advance of the table existing — if
 * `idempotency_keys` is missing it passes through transparently. Stage 2
 * adds the migration; from that commit forward replays are honoured.
 */
final class IdempotencyKey
{
    private const KEY_TTL_HOURS = 24;
    private const MAX_BODY_BYTES = 2_500_000; // ~2.5 MB — covers JSON + small attachments

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->isMutating($request)) {
            return $next($request);
        }

        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '') {
            return $next($request);
        }

        if (!$this->tableReady()) {
            // Pre-migration — pass through. Stage 2 migration enables full behaviour.
            return $next($request);
        }

        $userId   = (int) ($request->user()?->id ?? 0);
        $endpoint = strtolower($request->method()) . ':' . $request->path();

        if ($cached = $this->fetchCached($userId, $endpoint, $key)) {
            return response($cached['body'], (int) $cached['status'])
                ->header('Content-Type', 'application/json')
                ->header('X-Idempotent-Replay', '1');
        }

        $response = $next($request);

        $this->storeIfReplayable($userId, $endpoint, $key, $response);

        return $response;
    }

    private function isMutating(Request $request): bool
    {
        return in_array(strtoupper($request->method()), ['POST', 'PATCH', 'PUT', 'DELETE'], true);
    }

    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('idempotency_keys');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{status:int,body:string}|null
     */
    private function fetchCached(int $userId, string $endpoint, string $key): ?array
    {
        try {
            $row = DB::table('idempotency_keys')
                ->where('user_id', $userId)
                ->where('endpoint', $endpoint)
                ->where('key', $key)
                ->first();

            if (!$row) return null;

            return [
                'status' => (int) $row->response_status,
                'body'   => (string) $row->response_body,
            ];
        } catch (\Throwable $e) {
            Log::warning('[IdempotencyKey][fetch] ' . $e->getMessage());
            return null;
        }
    }

    private function storeIfReplayable(int $userId, string $endpoint, string $key, Response $response): void
    {
        $status = $response->getStatusCode();

        // Only persist 2xx and select 4xx that are deterministic. 5xx are skipped so
        // a transient server error can be retried without replaying the failure.
        if ($status >= 500) return;

        $body = (string) $response->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) return;

        try {
            DB::table('idempotency_keys')->insertOrIgnore([
                'user_id'         => $userId,
                'endpoint'        => $endpoint,
                'key'             => $key,
                'response_status' => $status,
                'response_hash'   => hash('sha256', $body),
                'response_body'   => $body,
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[IdempotencyKey][store] ' . $e->getMessage());
        }
    }
}
