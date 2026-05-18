<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ClientLogController — receives ALL client-side error telemetry.
 *
 * Endpoints
 * ---------
 *   POST /client-logs              — batch ingest (no auth — telemetry must work
 *                                    when token expires or user is signed out)
 *   GET  /client-logs              — paginated list (admin)
 *   GET  /client-logs/stream       — long-poll cursor for the realtime page
 *   GET  /client-logs/{id}         — full record incl. stack + breadcrumbs
 *   GET  /client-logs/stats        — dashboard counters
 *   POST /client-logs/{id}/dismiss — mark a log as triaged (sets resolved_at)
 *
 * Why no auth on POST
 * -------------------
 *   The whole point of error telemetry is to know when the auth flow breaks.
 *   If POST required a valid token, the most important errors (login,
 *   session expiry, forced re-auth) would never reach us. Instead we
 *   rate-limit per-IP (Laravel `throttle` middleware on the route).
 */
final class ClientLogController extends Controller
{
    /** Max records accepted in a single POST batch. */
    private const MAX_BATCH = 50;

    /** Max raw POST size — 1 MB ought to fit any real batch with headroom. */
    private const MAX_PAYLOAD_BYTES = 1_000_000;

    /** Levels accepted. */
    private const VALID_LEVELS = ['TRACE', 'DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL'];

    /** Platforms accepted. */
    private const VALID_PLATFORMS = ['WEB', 'ANDROID', 'IOS'];

    /**
     * POST /client-logs
     * Batch ingest. Body: { records: [...] } or a single record.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $raw = $request->getContent();
            if (strlen($raw) > self::MAX_PAYLOAD_BYTES) {
                return $this->err(413, 'Payload too large.', [
                    'max_bytes' => self::MAX_PAYLOAD_BYTES,
                ]);
            }

            $body = json_decode($raw, true);
            if (! is_array($body)) {
                return $this->err(400, 'Body must be JSON.');
            }

            // Accept either { records: [...] } or a single record object.
            $records = isset($body['records']) && is_array($body['records'])
                ? $body['records']
                : [$body];

            if (count($records) === 0) {
                return $this->ok(['inserted' => 0]);
            }
            if (count($records) > self::MAX_BATCH) {
                $records = array_slice($records, 0, self::MAX_BATCH);
            }

            $now = now()->format('Y-m-d H:i:s');
            $rows = [];
            foreach ($records as $r) {
                if (! is_array($r) || ! isset($r['message'])) {
                    continue;
                }

                $level = strtoupper((string) ($r['level'] ?? 'ERROR'));
                if (! in_array($level, self::VALID_LEVELS, true)) {
                    $level = 'ERROR';
                }

                $platform = strtoupper((string) ($r['platform'] ?? 'WEB'));
                if (! in_array($platform, self::VALID_PLATFORMS, true)) {
                    $platform = 'WEB';
                }

                $rows[] = [
                    'client_uuid'   => $r['client_uuid'] ?? null,
                    'level'         => $level,
                    'message'       => $this->trunc($r['message'], 8192),
                    'source'        => $this->trunc($r['source'] ?? null, 500),
                    'lineno'        => is_numeric($r['lineno'] ?? null) ? (int) $r['lineno'] : null,
                    'colno'         => is_numeric($r['colno'] ?? null)  ? (int) $r['colno']  : null,
                    'stack'         => $this->trunc($r['stack'] ?? null, 8192),
                    'error_name'    => $this->trunc($r['error_name'] ?? null, 120),
                    'error_message' => $this->trunc($r['error_message'] ?? null, 1024),
                    'user_id'       => isset($r['user_id']) && is_numeric($r['user_id']) ? (int) $r['user_id'] : null,
                    'role_key'      => $this->trunc($r['role_key'] ?? null, 60),
                    'poe_code'      => $this->trunc($r['poe_code'] ?? null, 80),
                    'session_id'    => $this->trunc($r['session_id'] ?? null, 64),
                    'device_id'     => $this->trunc($r['device_id'] ?? null, 80),
                    'app_version'   => $this->trunc($r['app_version'] ?? null, 40),
                    'platform'      => $platform,
                    'user_agent'    => $this->trunc($r['user_agent'] ?? null, 500),
                    'url'           => $this->trunc($r['url'] ?? null, 1000),
                    'route'         => $this->trunc($r['route'] ?? null, 500),
                    'online'        => isset($r['online']) ? (bool) $r['online'] : null,
                    'breadcrumbs'   => isset($r['breadcrumbs']) ? json_encode($r['breadcrumbs']) : null,
                    'extra'         => isset($r['extra']) ? (is_string($r['extra']) ? $r['extra'] : json_encode($r['extra'])) : null,
                    'occurred_at'   => $this->parseIso($r['occurred_at'] ?? null) ?: $now,
                    'received_at'   => $now,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }

            if (empty($rows)) {
                return $this->ok(['inserted' => 0]);
            }

            // Use insertOrIgnore to honour the unique(client_uuid) so a
            // queue replay never produces duplicates.
            DB::table('client_logs')->insertOrIgnore($rows);

            return $this->ok(['inserted' => count($rows)]);
        } catch (Throwable $e) {
            // NEVER loop logging back to ourselves — write to the laravel
            // log channel only.
            Log::error('[client-logs.store] failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->err(500, 'ingest failed');
        }
    }

    /**
     * GET /client-logs
     * Paginated list with filters: level, user_id, device_id, q, since.
     */
    public function index(Request $request): JsonResponse
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = max(1, min(200, (int) $request->query('per_page', 50)));
        $level   = strtoupper((string) $request->query('level', ''));
        $userId  = $request->query('user_id');
        $device  = (string) $request->query('device_id', '');
        $q       = (string) $request->query('q', '');
        $since   = (string) $request->query('since', '');
        $unresolvedOnly = (bool) $request->query('unresolved', false);

        $query = DB::table('client_logs')->orderByDesc('id');

        if (in_array($level, self::VALID_LEVELS, true)) {
            $query->where('level', $level);
        }
        if ($userId !== null && $userId !== '') {
            $query->where('user_id', (int) $userId);
        }
        if ($device !== '') {
            $query->where('device_id', $device);
        }
        if ($q !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $query->where(function ($w) use ($like) {
                $w->where('message', 'like', $like)
                  ->orWhere('error_name', 'like', $like)
                  ->orWhere('error_message', 'like', $like)
                  ->orWhere('source', 'like', $like)
                  ->orWhere('route', 'like', $like);
            });
        }
        if ($since !== '') {
            try { $query->where('received_at', '>=', $since); } catch (Throwable $e) {}
        }

        $total = (clone $query)->count();
        $rows  = $query->skip(($page - 1) * $perPage)
                       ->take($perPage)
                       ->get(['id', 'client_uuid', 'level', 'message', 'source',
                              'error_name', 'error_message', 'user_id', 'role_key',
                              'poe_code', 'device_id', 'session_id', 'app_version',
                              'platform', 'route', 'online', 'occurred_at', 'received_at']);

        return $this->ok([
            'records'  => $rows,
            'page'     => $page,
            'per_page' => $perPage,
            'total'    => $total,
        ]);
    }

    /**
     * GET /client-logs/stream?after=<id>
     * Long-poll: returns any record with id > after, or empty after 25 s.
     * The admin page wires this into a tail loop for true realtime.
     */
    public function stream(Request $request): JsonResponse
    {
        $after = (int) $request->query('after', 0);
        $level = strtoupper((string) $request->query('level', ''));

        $deadline = microtime(true) + 25.0;
        while (microtime(true) < $deadline) {
            $q = DB::table('client_logs')->where('id', '>', $after)->orderBy('id');
            if (in_array($level, self::VALID_LEVELS, true)) {
                $q->where('level', $level);
            }
            $rows = $q->take(100)->get([
                'id', 'level', 'message', 'source', 'error_name', 'user_id',
                'role_key', 'device_id', 'platform', 'route', 'occurred_at', 'received_at',
            ]);
            if ($rows->count() > 0) {
                return $this->ok([
                    'records' => $rows,
                    'cursor'  => (int) $rows->last()->id,
                ]);
            }
            usleep(500_000); // 0.5 s
        }

        return $this->ok(['records' => [], 'cursor' => $after]);
    }

    /** GET /client-logs/{id} — full detail */
    public function show(int $id): JsonResponse
    {
        $row = DB::table('client_logs')->where('id', $id)->first();
        if (! $row) {
            return $this->err(404, 'not found');
        }
        if (! empty($row->breadcrumbs)) {
            $row->breadcrumbs = json_decode($row->breadcrumbs, true);
        }
        if (! empty($row->extra)) {
            $row->extra = json_decode($row->extra, true);
        }
        return $this->ok(['record' => $row]);
    }

    /** GET /client-logs/stats — counters for the admin dashboard tiles. */
    public function stats(): JsonResponse
    {
        $since24h = now()->subDay();
        $since1h  = now()->subHour();

        $byLevel = DB::table('client_logs')
            ->where('received_at', '>=', $since24h)
            ->select('level', DB::raw('count(*) as ct'))
            ->groupBy('level')
            ->pluck('ct', 'level');

        $lastHour = DB::table('client_logs')
            ->where('received_at', '>=', $since1h)
            ->count();

        $latest = DB::table('client_logs')
            ->orderByDesc('id')
            ->take(1)
            ->value('received_at');

        return $this->ok([
            'last_24h_by_level' => $byLevel,
            'last_hour_total'   => $lastHour,
            'latest_received'   => $latest,
            'now'               => now()->toIso8601String(),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────
    private function trunc(?string $s, int $max): ?string
    {
        if ($s === null) return null;
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max) : $s;
    }

    private function parseIso(?string $s): ?string
    {
        if (! $s) return null;
        try {
            return (new \DateTime($s))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    private function ok(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data], $status);
    }

    private function err(int $status, string $message, array $extra = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => $extra,
        ], $status);
    }
}
