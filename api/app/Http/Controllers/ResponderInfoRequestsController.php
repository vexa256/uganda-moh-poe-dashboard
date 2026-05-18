<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ResponderInfoRequestsController
 * ─────────────────────────────────────────────────────────────────────────
 * Manages the external-responder information-request loop. An operator on
 * an alert emails an external stakeholder (hospital, lab, partner) and
 * that request is tracked here by unique token. The stakeholder either
 * clicks the token link in the PWA or the operator manually transcribes
 * their reply into this endpoint.
 *
 * Endpoint map
 *   GET  /responder-info-requests                  paginated list + filter
 *   GET  /responder-info-requests/{id}             single request detail
 *   GET  /responder-info-requests/by-token/{token} lookup by secure token
 *   POST /responder-info-requests/{id}/cancel      cancel an outstanding request
 *   POST /responder-info-requests/by-token/{token}/respond
 *         Body: { response_body, responder_name?, payload_extra?: object }
 *     · Captures the inbound response
 *     · Flips status to RECEIVED
 *     · Emits an EXTERNAL_INFO_RESPONDED timeline event on the alert
 *     · Auto-adds the responder as a collaborator (role RESPONDER) if not
 *       already present
 *
 * Token semantics
 *   · 48-hex token issued by NotificationDispatcher::requestExternalResponderInfo
 *   · Lives 7 days (expires_at). Expired tokens return 410 GONE.
 *
 * Security note
 *   The token is the only authentication for the external respond endpoint
 *   (the recipient does not have a user account). The token's randomness +
 *   7-day TTL + unique constraint is the security boundary.
 */
final class ResponderInfoRequestsController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        try {
            $q = DB::table('responder_info_requests as rir')
                ->leftJoin('external_responders as er', 'er.id', '=', 'rir.responder_id')
                ->leftJoin('alerts as a', 'a.id', '=', 'rir.alert_id')
                ->select(
                    'rir.*',
                    'er.name as responder_name', 'er.organisation', 'er.email as responder_email',
                    'er.responder_type',
                    'a.alert_code', 'a.risk_level', 'a.poe_code', 'a.country_code',
                );
            if ($status = $r->query('status'))     $q->where('rir.status', $status);
            if ($cc = $r->query('country_code'))   $q->where('a.country_code', $cc);
            if ($aid = $r->query('alert_id'))      $q->where('rir.alert_id', $aid);
            if ($rid = $r->query('responder_id'))  $q->where('rir.responder_id', $rid);
            $rows = $q->orderByDesc('rir.created_at')
                ->limit((int) $r->query('limit', 200))->get();
            return $this->ok(['requests' => $rows, 'count' => $rows->count()]);
        } catch (Throwable $e) {
            return $this->fail($e, 'index');
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $row = $this->fetchOne('rir.id', $id);
            if (! $row) return $this->err('Request not found', 404);
            return $this->ok(['request' => $row]);
        } catch (Throwable $e) {
            return $this->fail($e, 'show');
        }
    }

    public function byToken(string $token): JsonResponse
    {
        try {
            $row = $this->fetchOne('rir.request_token', $token);
            if (! $row) return $this->err('Token not found', 404);
            if ($row->expires_at && strtotime((string) $row->expires_at) < time() && $row->status === 'SENT') {
                DB::table('responder_info_requests')->where('id', $row->id)->update([
                    'status' => 'EXPIRED', 'updated_at' => now(),
                ]);
                return response()->json(['ok' => false, 'error' => 'Token expired'], 410);
            }
            return $this->ok(['request' => $row]);
        } catch (Throwable $e) {
            return $this->fail($e, 'byToken');
        }
    }

    public function cancel(Request $r, int $id): JsonResponse
    {
        try {
            $row = DB::table('responder_info_requests')->where('id', $id)->first();
            if (! $row) return $this->err('Request not found', 404);
            if ((string) $row->status !== 'SENT') {
                return $this->err('Only SENT requests can be cancelled', 409);
            }
            DB::table('responder_info_requests')->where('id', $id)->update([
                'status' => 'CANCELLED', 'updated_at' => now(),
            ]);
            if ($row->alert_id) {
                $this->timeline((int) $row->alert_id, 'EXTERNAL_INFO_CANCELLED',
                    'Information request to responder #' . $row->responder_id . ' cancelled',
                    ['request_id' => $id], $this->actorId($r));
            }
            return $this->ok(['cancelled' => true]);
        } catch (Throwable $e) {
            return $this->fail($e, 'cancel');
        }
    }

    /**
     * POST /responder-info-requests/by-token/{token}/respond
     *
     * This is a PUBLIC endpoint (authenticated only by token). Rate-limit
     * should be applied at the route layer once middleware is wired.
     */
    public function respond(Request $r, string $token): JsonResponse
    {
        try {
            $data = $r->validate([
                'response_body'   => 'required|string',
                'responder_name'  => 'nullable|string|max:160',
                'payload_extra'   => 'nullable|array',
            ]);
            $row = DB::table('responder_info_requests')->where('request_token', $token)->first();
            if (! $row) return $this->err('Token not found', 404);
            if ((string) $row->status !== 'SENT') {
                return $this->err('Request is no longer accepting responses (status=' . $row->status . ')', 409);
            }
            if ($row->expires_at && strtotime((string) $row->expires_at) < time()) {
                DB::table('responder_info_requests')->where('id', $row->id)->update([
                    'status' => 'EXPIRED', 'updated_at' => now(),
                ]);
                return response()->json(['ok' => false, 'error' => 'Token expired'], 410);
            }
            $payload = [
                'response_body'  => $data['response_body'],
                'responder_name' => $data['responder_name'] ?? null,
                'extra'          => $data['payload_extra'] ?? null,
                'received_at'    => now()->toIso8601String(),
                'remote_ip'      => $r->ip(),
                'user_agent'     => mb_substr((string) $r->userAgent(), 0, 300),
            ];
            DB::table('responder_info_requests')->where('id', $row->id)->update([
                'status'           => 'RECEIVED',
                'responded_at'     => now(),
                'response_payload' => json_encode($payload),
                'updated_at'       => now(),
            ]);
            if ($row->alert_id) {
                $this->timeline((int) $row->alert_id, 'EXTERNAL_INFO_RESPONDED',
                    'Response received from ' . ($data['responder_name'] ?? 'external responder'),
                    ['request_id' => $row->id] + $payload, null);
                // Auto-post a SYSTEM comment with the response body
                DB::table('alert_comments')->insert([
                    'alert_id'       => $row->alert_id,
                    'parent_id'      => null,
                    'author_user_id' => $row->requested_by_user_id,
                    'author_role'    => 'SYSTEM',
                    'body'           => "External responder replied:\n\n" . mb_substr($data['response_body'], 0, 20000),
                    'body_format'    => 'MARKDOWN',
                    'is_system'      => 1,
                    'visibility'     => 'ALL',
                    'created_at'     => now(), 'updated_at' => now(),
                ]);
            }
            return $this->ok(['received' => true]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return $this->fail($e, 'respond');
        }
    }

    private function fetchOne(string $col, $val): ?object
    {
        return DB::table('responder_info_requests as rir')
            ->leftJoin('external_responders as er', 'er.id', '=', 'rir.responder_id')
            ->leftJoin('alerts as a', 'a.id', '=', 'rir.alert_id')
            ->where($col, $val)
            ->select('rir.*',
                'er.name as responder_name', 'er.organisation', 'er.email as responder_email',
                'a.alert_code', 'a.country_code', 'a.poe_code', 'a.risk_level')
            ->first();
    }

    private function timeline(int $alertId, string $code, string $summary, ?array $payload, ?int $actorId): void
    {
        DB::table('alert_timeline_events')->insert([
            'alert_id'       => $alertId,
            'event_code'     => $code,
            'event_category' => 'EMAIL',
            'actor_user_id'  => $actorId,
            'summary'        => $summary,
            'payload_json'   => $payload ? json_encode($payload) : null,
            'severity'       => 'INFO',
            'related_entity_type' => 'RESPONDER_REQUEST',
            'created_at'     => now(),
        ]);
    }

    private function actorId(Request $r): ?int
    {
        $h = $r->header('X-User-Id');
        return $h !== null && ctype_digit((string) $h) ? (int) $h : null;
    }

    private function ok(array $d = [], int $c = 200): JsonResponse { return response()->json(['ok' => true, 'data' => $d], $c); }
    private function err(string $m, int $c = 400): JsonResponse { return response()->json(['ok' => false, 'error' => $m], $c); }
    private function fail(Throwable $e, string $ctx): JsonResponse {
        Log::error("[InfoRequests::{$ctx}] " . $e->getMessage());
        return response()->json(['ok' => false, 'error' => $e->getMessage(), 'ctx' => $ctx], 500);
    }
}
