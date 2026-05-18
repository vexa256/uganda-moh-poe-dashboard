<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Alerts;

use App\Http\Controllers\AlertCollaborationController;
use App\Http\Controllers\Controller;
use App\Services\NotificationDispatcher;
use App\Support\Alerts\HumanLabels;
use App\Support\Scope\ScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin · Section 03 · External Requests (responder_info_requests).
 *
 * Owns:  list / show / cancel / resend / external-responder roster CRUD.
 * Delegates the initial mint to the canonical AlertCollaborationController::
 * requestExternalInfo so the existing dispatcher / template / suppression
 * pipeline runs untouched.
 *
 * Scope: requests are scoped via their parent alert. Cross-scope poking is
 * rejected at every endpoint (incl. cancel/resend/show) — hostile auditor's
 * favourite vector.
 */
final class ExternalRequestsController extends Controller
{
    private const STATUSES        = ['SENT', 'RECEIVED', 'EXPIRED', 'CANCELLED'];
    private const RESPONDER_TYPES = ['HOSPITAL', 'LAB', 'EMS', 'LAW_ENFORCEMENT', 'PARTNER_AGENCY', 'OTHER'];
    private const TOKEN_TTL_DAYS  = 7;

    /* ═════════════════════════ views ═════════════════════════ */

    public function index(Request $r)
    {
        return view('admin.alertops.external.index');
    }

    /* ═════════════════════════ READS ═════════════════════════ */

    /**
     * GET /admin/alerts/external/data
     * Tabs by status (sent/received/expired/cancelled/all). Filters: responder_id,
     * alert_id, q (subject/body). Cursor pagination on requests.id desc.
     */
    public function data(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $tab   = strtolower((string) $r->query('status', 'sent'));
            if ($tab !== 'all' && ! in_array(strtoupper($tab), self::STATUSES, true)) $tab = 'sent';

            $base = function () use ($scope) {
                // Reach through alert_id → alerts for scope.
                $q = DB::table('responder_info_requests')
                    ->whereExists(function ($w) use ($scope): void {
                        $w->select(DB::raw(1))->from('alerts as a_eir')
                          ->whereColumn('a_eir.id', 'responder_info_requests.alert_id')
                          ->whereNull('a_eir.deleted_at');
                        if (! ScopeFilter::isSuper($scope)) {
                            $level = ScopeFilter::level($scope);
                            $col   = match ($level) {
                                'PHEOC'    => 'province_code',
                                'DISTRICT' => 'district_code',
                                'POE'      => 'poe_code',
                                default    => null,
                            };
                            if ($col === null) { $w->whereRaw('1=0'); return; }
                            $vals = match ($level) {
                                'PHEOC'    => $scope['provinces'] ?? [],
                                'DISTRICT' => $scope['districts'] ?? [],
                                'POE'      => $scope['poes']      ?? [],
                            };
                            $w->whereIn('a_eir.' . $col, $vals ?: ['__none__']);
                        }
                    });
                return $q;
            };

            $rowsQ = $base();
            if ($tab !== 'all') $rowsQ->where('responder_info_requests.status', strtoupper($tab));
            if (($v = (int) $r->query('responder_id', 0)) > 0) $rowsQ->where('responder_info_requests.responder_id', $v);
            if (($v = (int) $r->query('alert_id',     0)) > 0) $rowsQ->where('responder_info_requests.alert_id', $v);
            $qstr = trim((string) $r->query('q', ''));
            if ($qstr !== '') {
                $like = '%' . $qstr . '%';
                $rowsQ->where(function ($w) use ($like) {
                    $w->where('responder_info_requests.request_subject', 'like', $like)
                      ->orWhere('responder_info_requests.request_body',  'like', $like);
                });
            }

            $rows = $rowsQ
                ->leftJoin('alerts             as a', 'a.id', '=', 'responder_info_requests.alert_id')
                ->leftJoin('external_responders as e','e.id', '=', 'responder_info_requests.responder_id')
                ->leftJoin('users as u', 'u.id', '=', 'responder_info_requests.requested_by_user_id')
                ->select([
                    'responder_info_requests.*',
                    'a.alert_code', 'a.alert_title', 'a.risk_level', 'a.status as alert_status',
                    'a.district_code', 'a.poe_code',
                    'e.name as responder_name', 'e.organisation as responder_org',
                    'e.responder_type', 'e.email as responder_email',
                    'u.full_name as requester_name', 'u.role_key as requester_role',
                ])
                ->orderByDesc('responder_info_requests.id')
                ->limit(500)->get();

            $tabs = [
                'sent'      => (clone $base())->where('status', 'SENT')->count(),
                'received'  => (clone $base())->where('status', 'RECEIVED')->count(),
                'expired'   => (clone $base())->where('status', 'EXPIRED')->count(),
                'cancelled' => (clone $base())->where('status', 'CANCELLED')->count(),
                'all'       => (clone $base())->count(),
            ];

            // Mark stale-but-not-yet-EXPIRED rows so the UI shows them red.
            $now = Carbon::now();

            return $this->ok([
                'rows'  => $rows->map(fn ($r) => $this->castRow($r, $now))->all(),
                'count' => $rows->count(),
            ], 'External requests.', [
                'tabs' => $tabs, 'scope_label' => $scope['label'] ?? null,
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    /** GET /admin/alerts/external/{id} */
    public function show(Request $r, int $id): JsonResponse
    {
        try {
            $row = DB::table('responder_info_requests as rir')
                ->leftJoin('alerts as a', 'a.id', '=', 'rir.alert_id')
                ->leftJoin('external_responders as e', 'e.id', '=', 'rir.responder_id')
                ->leftJoin('users as u', 'u.id', '=', 'rir.requested_by_user_id')
                ->where('rir.id', $id)
                ->select([
                    'rir.*',
                    'a.alert_code', 'a.alert_title', 'a.risk_level', 'a.status as alert_status',
                    'a.district_code', 'a.poe_code', 'a.country_code', 'a.province_code',
                    'e.name as responder_name', 'e.organisation as responder_org',
                    'e.responder_type', 'e.email as responder_email', 'e.phone as responder_phone',
                    'u.full_name as requester_name', 'u.role_key as requester_role',
                ])->first();
            if (! $row) return $this->err(404, 'Request not found.');

            // Scope guard.
            $alert = DB::table('alerts')->where('id', $row->alert_id)->first();
            if (! $alert || ! ScopeFilter::canSeeAlert(ScopeFilter::fromRequest($r), $alert)) {
                return $this->err(403, 'Request is outside your scope.');
            }

            // Linked evidence (responses attached as alert_evidence with responder_request_id).
            $evidence = DB::table('alert_evidence')
                ->where('responder_request_id', $id)
                ->whereNull('deleted_at')
                ->orderByDesc('id')->get();

            return $this->ok([
                'request'  => $this->castRow($row, Carbon::now(), /*include_body=*/true),
                'evidence' => $evidence->map(fn ($e) => (array) $e)->all(),
                'public_url' => url('/respond/' . $row->request_token),
            ], 'Request detail.');
        } catch (Throwable $e) { return $this->serverError($e, 'show'); }
    }

    /** GET /admin/alerts/external/meta */
    public function meta(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $resp = DB::table('external_responders')->whereNull('deleted_at')->where('is_active', 1);
            // External responders don't carry province_code; scope by district when DISTRICT/POE.
            if (! ScopeFilter::isSuper($scope)) {
                $level = ScopeFilter::level($scope);
                if ($level === 'DISTRICT') {
                    $resp->whereIn('district_code', $scope['districts'] ?? ['__none__']);
                } elseif ($level === 'POE') {
                    // PoE-scope sees responders in the same district as their PoE.
                    $districts = DB::table('ref_poes')
                        ->whereIn('poe_code', $scope['poes'] ?? [])
                        ->whereNull('deleted_at')
                        ->pluck('district')->unique()->all();
                    $resp->whereIn('district_code', $districts ?: ['__none__']);
                } elseif ($level === 'PHEOC') {
                    $districts = DB::table('ref_districts')
                        ->whereIn('province_id', DB::table('ref_provinces')->whereIn('name', $scope['provinces'] ?? [])->pluck('id'))
                        ->whereNull('deleted_at')->pluck('name')->all();
                    $resp->whereIn('district_code', $districts ?: ['__none__']);
                }
            }
            $responders = $resp->orderBy('name')->get([
                'id','name','organisation','responder_type','email','phone','district_code','is_active',
            ]);

            return $this->ok([
                'statuses'        => self::STATUSES,
                'responder_types' => self::RESPONDER_TYPES,
                'responders'      => $responders,
                'token_ttl_days'  => self::TOKEN_TTL_DAYS,
            ], 'Meta.');
        } catch (Throwable $e) { return $this->serverError($e, 'meta'); }
    }

    /* ═════════════════════════ WRITES ═════════════════════════ */

    /**
     * POST /admin/alerts/{alert_id}/external-requests
     * Body: { responder_id, message, subject? }
     * Delegates to canonical requestExternalInfo (mints token, sends email,
     * emits EXTERNAL_INFO_REQUESTED event).
     */
    public function create(Request $r, int $alertId): JsonResponse
    {
        try {
            $alert = DB::table('alerts')->where('id', $alertId)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Alert not found.');
            if (! ScopeFilter::canSeeAlert(ScopeFilter::fromRequest($r), $alert)) {
                return $this->err(403, 'Alert is outside your scope.');
            }

            // Pre-flight: validate responder + message BEFORE forwarding. The
            // canonical dispatcher returns `['error' => ...]` embedded in a
            // successful body when the responder does not exist, which the
            // wrapper happily passes through as HTTP 200 — surface that as a
            // proper 422 instead.
            $rid = (int) $r->input('responder_id', 0);
            $msg = trim((string) $r->input('message', ''));
            if ($rid <= 0)         return $this->err(422, 'responder_id is required.');
            if ($msg === '')       return $this->err(422, 'message is required.');
            if (mb_strlen($msg) > 4000) {
                return $this->err(422, 'message must be 4000 chars or fewer.', ['len' => mb_strlen($msg)]);
            }
            $responder = DB::table('external_responders')->where('id', $rid)->whereNull('deleted_at')->first(['id','is_active']);
            if (! $responder) return $this->err(422, 'External responder not found.', ['responder_id' => $rid]);
            if (! $responder->is_active) {
                return $this->err(422, 'External responder is inactive.', ['responder_id' => $rid]);
            }

            $actorId = (int) ($r->user()->id ?? 0);
            if ($actorId > 0) {
                $r->headers->set('X-User-Id', (string) $actorId);
                $r->merge(['actor_user_id' => $actorId, 'user_id' => $actorId]);
            }
            return app(AlertCollaborationController::class)->requestExternalInfo($r, $alertId);
        } catch (Throwable $e) { return $this->serverError($e, 'create'); }
    }

    /**
     * POST /admin/alerts/external/{id}/cancel
     * Hard cancellation — token can no longer be used. Audited.
     */
    public function cancel(Request $r, int $id): JsonResponse
    {
        try {
            $row = DB::table('responder_info_requests')->where('id', $id)->first();
            if (! $row) return $this->err(404, 'Request not found.');

            $alert = DB::table('alerts')->where('id', $row->alert_id)->first();
            if (! $alert || ! ScopeFilter::canSeeAlert(ScopeFilter::fromRequest($r), $alert)) {
                return $this->err(403, 'Request is outside your scope.');
            }
            if (in_array($row->status, ['CANCELLED', 'RECEIVED', 'EXPIRED'], true)) {
                return $this->err(409, 'Request is already in a terminal state.', ['status' => $row->status]);
            }

            $actorId = (int) ($r->user()->id ?? 0);
            $now = Carbon::now();

            DB::transaction(function () use ($id, $actorId, $now): void {
                DB::table('responder_info_requests')->where('id', $id)->update([
                    'status'         => 'CANCELLED',
                    'cancelled_at'   => $now,
                    'cancelled_by'   => $actorId ?: null,
                    'updated_at'     => $now,
                ]);
            });
            $this->emitTimeline((int) $row->alert_id, 'EXTERNAL_INFO_REQUESTED', 'EMAIL', $actorId,
                'External request #' . $id . ' cancelled by admin.',
                ['request_id' => $id, 'cancelled_by' => $actorId, 'previous_status' => $row->status],
                'RESPONDER_REQUEST', $id, 'WARN'
            );
            return $this->ok(['id' => $id, 'status' => 'CANCELLED'], 'Request cancelled. Token invalidated.');
        } catch (Throwable $e) { return $this->serverError($e, 'cancel'); }
    }

    /**
     * POST /admin/alerts/external/{id}/resend
     * Rotates the token (mints a new one), invalidates the previous link,
     * fans out a new email via the canonical dispatcher, audited.
     *
     * Body: { message?, subject? } — if absent, reuses the prior values.
     */
    public function resend(Request $r, int $id): JsonResponse
    {
        try {
            $row = DB::table('responder_info_requests')->where('id', $id)->first();
            if (! $row) return $this->err(404, 'Request not found.');

            $alert = DB::table('alerts')->where('id', $row->alert_id)->first();
            if (! $alert || ! ScopeFilter::canSeeAlert(ScopeFilter::fromRequest($r), $alert)) {
                return $this->err(403, 'Request is outside your scope.');
            }
            if (in_array($row->status, ['RECEIVED', 'CANCELLED'], true)) {
                return $this->err(409, 'Cannot resend a ' . $row->status . ' request — create a new one instead.');
            }

            $actorId = (int) ($r->user()->id ?? 0);
            $message = trim((string) $r->input('message',  $row->request_body));
            $subject = trim((string) $r->input('subject',  $row->request_subject));
            if ($message === '') return $this->err(422, 'message is required.');

            // Use the dispatcher to mint a fresh token + send the email.
            $res = NotificationDispatcher::requestExternalResponderInfo(
                (int) $row->responder_id, (int) $row->alert_id, $actorId, $message, $subject !== '' ? $subject : null
            );
            if (! empty($res['error'])) return $this->err(422, $res['error']);

            // Mark the prior token as superseded — flip OLD row to CANCELLED
            // (its token can no longer be used) and bump audit counters.
            $now = Carbon::now();
            DB::table('responder_info_requests')->where('id', $id)->update([
                'status'         => 'CANCELLED',
                'cancelled_at'   => $now,
                'cancelled_by'   => $actorId ?: null,
                'last_resent_at' => $now,
                'resend_count'   => (int) $row->resend_count + 1,
                'updated_at'     => $now,
            ]);

            $this->emitTimeline((int) $row->alert_id, 'EXTERNAL_INFO_REQUESTED', 'EMAIL', $actorId,
                'External request resent. Old token invalidated. New token issued.',
                ['old_request_id' => $id, 'new_token' => $res['token'] ?? null,
                 'resend_count'   => ((int) $row->resend_count) + 1],
                'RESPONDER_REQUEST', $id, 'WARN'
            );

            return $this->ok([
                'old_request_id' => $id,
                'new_token'      => $res['token'] ?? null,
                'public_url'     => isset($res['token']) ? url('/respond/' . $res['token']) : null,
                'send'           => $res,
            ], 'Resent — old token invalidated.');
        } catch (Throwable $e) { return $this->serverError($e, 'resend'); }
    }

    /* ═════════════════════════ External responder roster (light CRUD) ═════════════════════════ */

    public function respondersIndex(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $q = DB::table('external_responders')->whereNull('deleted_at');
            if (! ScopeFilter::isSuper($scope)) {
                $level = ScopeFilter::level($scope);
                if ($level === 'DISTRICT') $q->whereIn('district_code', $scope['districts'] ?? ['__none__']);
                elseif ($level === 'POE') {
                    $districts = DB::table('ref_poes')->whereIn('poe_code', $scope['poes'] ?? [])->pluck('district')->unique()->all();
                    $q->whereIn('district_code', $districts ?: ['__none__']);
                } elseif ($level === 'PHEOC') {
                    $districts = DB::table('ref_districts')
                        ->whereIn('province_id', DB::table('ref_provinces')->whereIn('name', $scope['provinces'] ?? [])->pluck('id'))
                        ->whereNull('deleted_at')->pluck('name')->all();
                    $q->whereIn('district_code', $districts ?: ['__none__']);
                }
            }
            $rows = $q->orderBy('name')->get();
            return $this->ok(['rows' => $rows->map(fn ($r) => (array) $r)->all(), 'count' => $rows->count()], 'External responders.');
        } catch (Throwable $e) { return $this->serverError($e, 'respondersIndex'); }
    }

    public function respondersStore(Request $r): JsonResponse
    {
        try {
            $actorId = (int) ($r->user()->id ?? 0);
            $data = $r->validate([
                'name'           => 'required|string|max:160',
                'email'          => 'required|email|max:160',
                'organisation'   => 'nullable|string|max:160',
                'position'       => 'nullable|string|max:120',
                'phone'          => 'nullable|string|max:40',
                'responder_type' => 'required|in:HOSPITAL,LAB,EMS,LAW_ENFORCEMENT,PARTNER_AGENCY,OTHER',
                'country_code'   => 'nullable|string|max:10',
                'district_code'  => 'nullable|string|max:30',
                'notes'          => 'nullable|string|max:500',
                'is_active'      => 'nullable|boolean',
            ]);
            $data['country_code'] = $data['country_code'] ?? config('country.legacy_code');
            $data['is_active']    = (int) ($data['is_active'] ?? 1);
            $data['created_by_user_id'] = $actorId;
            $data['created_at']   = Carbon::now();
            $data['updated_at']   = Carbon::now();
            $id = DB::table('external_responders')->insertGetId($data);
            $row = DB::table('external_responders')->where('id', $id)->first();
            return $this->ok(['responder' => (array) $row], 'Responder added.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->err(422, 'Validation failed.', $e->errors());
        } catch (Throwable $e) { return $this->serverError($e, 'respondersStore'); }
    }

    /* ═════════════════════════ helpers ═════════════════════════ */

    private function castRow(object $r, Carbon $now, bool $includeBody = false): array
    {
        $expires = $r->expires_at ? Carbon::parse($r->expires_at) : null;
        $stale   = $expires && $r->status === 'SENT' && $expires->isPast();
        $minutesToExpiry = $expires ? $now->diffInMinutes($expires, false) : null;

        $base = [
            'id'                => (int) $r->id,
            'alert_id'          => (int) $r->alert_id,
            'alert_code'        => $r->alert_code  ?? null,
            'alert_title'       => $r->alert_title ?? null,
            'risk_level'        => $r->risk_level  ?? null,
            'alert_status'      => $r->alert_status ?? null,
            'district_code'     => $r->district_code ?? null,
            'poe_code'          => $r->poe_code ?? null,
            'responder_id'      => (int) $r->responder_id,
            'responder_name'    => $r->responder_name ?? null,
            'responder_org'     => $r->responder_org ?? null,
            'responder_type'    => $r->responder_type ?? null,
            'responder_email'   => $r->responder_email ?? null,
            'request_subject'   => (string) $r->request_subject,
            'status'            => (string) $r->status,
            'is_stale'          => $stale,
            'expires_at'        => $r->expires_at,
            'minutes_to_expiry' => $minutesToExpiry,
            'responded_at'      => $r->responded_at,
            'cancelled_at'      => $r->cancelled_at  ?? null,
            'last_resent_at'    => $r->last_resent_at ?? null,
            'resend_count'      => isset($r->resend_count) ? (int) $r->resend_count : 0,
            'view_count'        => isset($r->view_count) ? (int) $r->view_count : 0,
            'last_viewed_at'    => $r->last_viewed_at ?? null,
            'requester_name'    => $r->requester_name ?? null,
            'requester_role'    => $r->requester_role ?? null,
            'created_at'        => $r->created_at,
        ];
        if ($includeBody) {
            $base['request_body']     = $r->request_body;
            $base['response_payload'] = $r->response_payload ? json_decode($r->response_payload, true) : null;
            $base['responder_ip']     = $r->responder_ip ?? null;
            $base['responder_ua']     = $r->responder_ua ?? null;
        }

        // Plain-English overlay.
        $statusLabel = match ((string) $r->status) {
            'SENT'      => $stale ? 'Expired' : 'Sent — waiting for reply',
            'RECEIVED'  => 'They replied',
            'EXPIRED'   => 'Expired before reply',
            'CANCELLED' => 'Cancelled',
            default     => HumanLabels::prettify((string) $r->status),
        };
        $statusTone = match ((string) $r->status) {
            'SENT'      => $stale ? 'skipped' : 'watch',
            'RECEIVED'  => 'done',
            'EXPIRED'   => 'skipped',
            'CANCELLED' => 'skipped',
            default     => 'info',
        };

        $base['human'] = [
            'title'            => (string) $r->request_subject,
            'who_to'           => (string) ($r->responder_name ?? '—'),
            'organisation'     => (string) ($r->responder_org ?? ''),
            'status_label'     => $statusLabel,
            'status_tone'      => $statusTone,
            'sent_human'       => HumanLabels::dueHuman((string) $r->created_at, $now),
            'expires_human'    => $r->expires_at ? HumanLabels::dueHuman((string) $r->expires_at, $now) : 'no expiry',
            'replied_human'    => $r->responded_at ? HumanLabels::dueHuman((string) $r->responded_at, $now) : null,
            'risk_label'       => !empty($r->risk_level) ? HumanLabels::risk((string) $r->risk_level)['short'] : null,
        ];

        return $base;
    }

    private function emitTimeline(int $alertId, string $code, string $category, ?int $actorId,
        string $summary, array $payload = [], ?string $relType = null, ?int $relId = null, string $severity = 'INFO'): void
    {
        try {
            $actor = $actorId ? DB::table('users')->where('id', $actorId)->first(['full_name', 'role_key']) : null;
            DB::table('alert_timeline_events')->insert([
                'alert_id'            => $alertId,
                'event_code'          => $code,
                'event_category'      => $category,
                'actor_user_id'       => $actorId,
                'actor_name'          => $actor->full_name ?? null,
                'actor_role'          => $actor->role_key  ?? null,
                'payload_json'        => json_encode($payload),
                'summary'             => mb_substr($summary, 0, 500),
                'severity'            => $severity,
                'related_entity_type' => $relType,
                'related_entity_id'   => $relId,
                'created_at'          => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('[Admin\\Alerts\\External][emitTimeline] ' . $e->getMessage());
        }
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if(!empty($meta)){$b['meta']=$meta;} return response()->json($b); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Alerts\\External][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
