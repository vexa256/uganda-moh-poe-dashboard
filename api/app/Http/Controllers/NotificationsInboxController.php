<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * NotificationsInboxController
 * ─────────────────────────────────────────────────────────────────────────
 * Per-user inbox on top of notification_log. Lets an officer or admin see
 * the alerts and digests they were included on, mark read/unread, filter,
 * and pull an unread counter for the PWA bell icon.
 *
 * "User" here is resolved via X-User-Id header (until Sanctum is wired).
 * If no user is provided we return the full company feed filtered by
 * country for demo / ops dashboards.
 *
 * Endpoint map
 *   GET  /inbox                                list inbox items (paginated, filterable)
 *   GET  /inbox/unread-count                   { count } for the bell badge
 *   GET  /inbox/{logId}                        single item (subject + full rendered body)
 *   POST /inbox/mark-read        Body: { ids: [int, ...] }
 *   POST /inbox/mark-unread
 *   POST /inbox/mark-all-read
 *   GET  /inbox/facets                         facet counts (by template / risk / status)
 *
 * Read-state is persisted via a companion table `notification_log_reads`
 * (auto-created on first read-mark if it does not exist).
 */
final class NotificationsInboxController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        try {
            $this->ensureReadsTable();
            $userId = $this->userId($r);
            $q = DB::table('notification_log as l');

            if ($userId && $email = DB::table('users')->where('id', $userId)->value('email')) {
                $q->where('l.to_email', $email);
            } elseif ($cc = $r->query('country_code')) {
                $q->join('poe_notification_contacts as c', 'c.id', '=', 'l.contact_id')
                  ->where('c.country_code', $cc);
            }

            if ($tpl = $r->query('template_code')) $q->where('l.template_code', $tpl);
            if ($status = $r->query('status'))     $q->where('l.status', $status);
            if ($ent = $r->query('entity_type'))   $q->where('l.related_entity_type', $ent);
            if ($eid = $r->query('entity_id'))     $q->where('l.related_entity_id', $eid);
            if ($unreadOnly = $r->query('unread_only')) {
                $q->leftJoin('notification_log_reads as rd', function ($j) use ($userId) {
                    $j->on('rd.notification_log_id', '=', 'l.id')
                      ->where('rd.user_id', '=', $userId ?: 0);
                })->whereNull('rd.id');
            }

            $limit = (int) $r->query('limit', 50);
            $offset = (int) $r->query('offset', 0);
            $rows = $q->select('l.id', 'l.template_code', 'l.subject', 'l.to_email', 'l.status',
                               'l.related_entity_type', 'l.related_entity_id', 'l.triggered_by',
                               'l.created_at')
                ->orderByDesc('l.created_at')->limit($limit)->offset($offset)->get();

            $readIds = $userId
                ? DB::table('notification_log_reads')->where('user_id', $userId)
                    ->whereIn('notification_log_id', $rows->pluck('id'))->pluck('notification_log_id')->all()
                : [];
            $readSet = array_flip($readIds);
            $rows = $rows->map(function ($row) use ($readSet) {
                $row->is_read = isset($readSet[$row->id]);
                return $row;
            });

            return $this->ok([
                'items' => $rows,
                'count' => $rows->count(),
                'limit' => $limit, 'offset' => $offset,
            ]);
        } catch (Throwable $e) { return $this->fail($e, 'index'); }
    }

    public function unreadCount(Request $r): JsonResponse
    {
        try {
            $this->ensureReadsTable();
            $userId = $this->userId($r);
            if (! $userId) return $this->ok(['count' => 0]);
            $email = DB::table('users')->where('id', $userId)->value('email');
            if (! $email) return $this->ok(['count' => 0]);
            $n = DB::table('notification_log as l')
                ->leftJoin('notification_log_reads as rd', function ($j) use ($userId) {
                    $j->on('rd.notification_log_id', '=', 'l.id')->where('rd.user_id', '=', $userId);
                })
                ->where('l.to_email', $email)
                ->whereNull('rd.id')->count();
            return $this->ok(['count' => $n]);
        } catch (Throwable $e) { return $this->fail($e, 'unreadCount'); }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $row = DB::table('notification_log')->where('id', $id)->first();
            if (! $row) return $this->err('Not found', 404);
            return $this->ok(['item' => $row]);
        } catch (Throwable $e) { return $this->fail($e, 'show'); }
    }

    public function markRead(Request $r): JsonResponse
    {
        return $this->bulkMark($r, true);
    }

    public function markUnread(Request $r): JsonResponse
    {
        return $this->bulkMark($r, false);
    }

    public function markAllRead(Request $r): JsonResponse
    {
        try {
            $this->ensureReadsTable();
            $userId = $this->userId($r);
            if (! $userId) return $this->err('user required', 400);
            $email = DB::table('users')->where('id', $userId)->value('email');
            if (! $email) return $this->err('user has no email', 400);
            $ids = DB::table('notification_log')->where('to_email', $email)->pluck('id')->all();
            $existing = DB::table('notification_log_reads')->where('user_id', $userId)
                ->whereIn('notification_log_id', $ids)->pluck('notification_log_id')->all();
            $missing = array_diff($ids, $existing);
            if (! empty($missing)) {
                $now = now();
                DB::table('notification_log_reads')->insert(array_map(fn($i) => [
                    'notification_log_id' => $i, 'user_id' => $userId, 'read_at' => $now,
                ], $missing));
            }
            return $this->ok(['marked_read' => count($missing)]);
        } catch (Throwable $e) { return $this->fail($e, 'markAllRead'); }
    }

    public function facets(Request $r): JsonResponse
    {
        try {
            $userId = $this->userId($r);
            $q = DB::table('notification_log');
            if ($userId) {
                $email = DB::table('users')->where('id', $userId)->value('email');
                if ($email) $q->where('to_email', $email);
            }
            $byTemplate = (clone $q)->selectRaw('template_code, COUNT(*) AS n')
                ->groupBy('template_code')->orderByDesc('n')->get();
            $byStatus = (clone $q)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->get();
            $byEntity = (clone $q)->selectRaw('related_entity_type, COUNT(*) AS n')
                ->groupBy('related_entity_type')->get();
            return $this->ok(['by_template' => $byTemplate, 'by_status' => $byStatus, 'by_entity' => $byEntity]);
        } catch (Throwable $e) { return $this->fail($e, 'facets'); }
    }

    // ── helpers ────────────────────────────────────────────────────────────

    private function bulkMark(Request $r, bool $read): JsonResponse
    {
        try {
            $this->ensureReadsTable();
            $userId = $this->userId($r);
            if (! $userId) return $this->err('user required', 400);
            $data = $r->validate(['ids' => 'required|array', 'ids.*' => 'integer']);
            if ($read) {
                $existing = DB::table('notification_log_reads')->where('user_id', $userId)
                    ->whereIn('notification_log_id', $data['ids'])->pluck('notification_log_id')->all();
                $missing = array_diff($data['ids'], $existing);
                if (! empty($missing)) {
                    $now = now();
                    DB::table('notification_log_reads')->insert(array_map(fn($i) => [
                        'notification_log_id' => $i, 'user_id' => $userId, 'read_at' => $now,
                    ], $missing));
                }
                return $this->ok(['marked_read' => count($missing)]);
            } else {
                $n = DB::table('notification_log_reads')->where('user_id', $userId)
                    ->whereIn('notification_log_id', $data['ids'])->delete();
                return $this->ok(['marked_unread' => $n]);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        } catch (Throwable $e) { return $this->fail($e, 'bulkMark'); }
    }

    private function ensureReadsTable(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        DB::statement("CREATE TABLE IF NOT EXISTS notification_log_reads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            notification_log_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uk_read (notification_log_id, user_id),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Resolve the acting user id across three auth surfaces:
     *   · Sanctum bearer token     ($request->user())
     *   · X-User-Id header         (legacy mobile)
     *   · ?user_id=…               (admin-panel legacy param)
     * Returns null if the caller is anonymous.
     */
    private function userId(Request $r): ?int
    {
        if ($u = $r->user()) return (int) $u->id;
        $h = $r->header('X-User-Id');
        if ($h !== null && ctype_digit((string) $h)) return (int) $h;
        $q = $r->query('user_id');
        if ($q !== null && ctype_digit((string) $q)) return (int) $q;
        return null;
    }

    private function ok(array $d = [], int $c = 200): JsonResponse { return response()->json(['ok' => true, 'data' => $d], $c); }
    private function err(string $m, int $c = 400): JsonResponse { return response()->json(['ok' => false, 'error' => $m], $c); }
    private function fail(Throwable $e, string $ctx): JsonResponse {
        Log::error("[Inbox::{$ctx}] " . $e->getMessage());
        return response()->json(['ok' => false, 'error' => $e->getMessage(), 'ctx' => $ctx], 500);
    }
}
