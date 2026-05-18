<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\System;

use App\Services\AuthEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin · System Health · WHO Connector (sys-who)
 * ---------------------------------------------------------------------------
 * v4 — honest placeholder for the future IHR Event Information Site (EIS)
 * gateway. The connector is not yet built; this surface tracks readiness
 * towards the live cut-over and lets an operator register interest in
 * being notified when it is enabled.
 *
 * Discipline (per the System Health brief):
 *   · NEVER invent connector state. The status is hardcoded
 *     'NOT_CONNECTED' and the screen is explicit about it.
 *   · No fake green ticks on the readiness checklist — readiness items
 *     are marked done only when they have been completed for real.
 *   · The "Notify me when ready" affordance writes ONE row to the
 *     existing auth_events table with event_type=WHO_EIS_NOTIFY_ME_REQUESTED.
 *     This is consistent with the connector's documented audit prefix
 *     (WHO_EIS_*) and requires no schema change.
 *   · AUDIT — every read records an audit row.
 *
 * Mobile-API impact: NONE. Routes live under /admin/system/who/*.
 */
final class WhoConnectorController extends BaseSystemController
{
    protected function viewKey(): string
    {
        return 'who';
    }

    public function index(Request $request)
    {
        return view('admin.system.who.index', [
            'page_title'    => 'WHO connector',
            'page_eyebrow'  => 'System Health',
            'page_subtitle' => 'Planned outbound link to WHO. Currently not connected — IHR notifications continue through existing manual channels.',
            'coach'         => $this->coach(),
        ]);
    }

    /**
     * The connector contract — what it WILL look like when built.
     * Hardcoded because this is a forward-looking commitment, not a
     * runtime read. The readiness checklist lives here so it can be
     * updated by code review as preparation work completes.
     */
    public function contract(Request $request): JsonResponse
    {
        $this->auditView($request, ['view' => 'who.contract'], ['row_count' => 0]);

        // Count of operators who have asked to be notified when the
        // connector is ready. Computed from auth_events; absent table
        // gracefully falls back to zero.
        $interestCount = 0;
        try {
            if (Schema::hasTable('auth_events')) {
                $interestCount = (int) DB::table('auth_events')
                    ->where('event_type', 'WHO_EIS_NOTIFY_ME_REQUESTED')
                    ->distinct()->count(DB::raw('user_id'));
            }
        } catch (Throwable) {
            $interestCount = 0;
        }

        $youAlreadyAsked = false;
        try {
            $u = $request->user();
            if ($u && Schema::hasTable('auth_events')) {
                $youAlreadyAsked = (bool) DB::table('auth_events')
                    ->where('user_id', (int) $u->getAuthIdentifier())
                    ->where('event_type', 'WHO_EIS_NOTIFY_ME_REQUESTED')
                    ->exists();
            }
        } catch (Throwable) {
            $youAlreadyAsked = false;
        }

        $readiness = [
            ['item' => 'National IHR Focal Point contact seeded in users table',         'done' => true],
            ['item' => 'Alert → Annex 2 decision instrument produces 4-YES/NO scores',   'done' => true],
            ['item' => 'OAuth2 client credentials issued by WHO Lyon',                   'done' => false],
            ['item' => 'Signing key rotation configured in app secrets',                 'done' => false],
            ['item' => 'Staging gateway URL + sandbox event identifiers provisioned',    'done' => false],
            ['item' => 'Retry policy + idempotency key negotiated with EIS',             'done' => false],
            ['item' => 'Operations runbook + incident severity matrix',                  'done' => false],
        ];
        $doneCount = count(array_filter($readiness, fn ($r) => $r['done'] === true));

        return $this->ok([
            'status'      => 'NOT_CONNECTED',
            'server_time' => now()->toIso8601String(),
            'plain_status'=> 'This connector is not yet active. When it is enabled, this view will show whether IHR notifications are being delivered to WHO and what their status is.',
            'manual_fallback' => 'Until the connector is live, IHR notifications are sent by the National IHR Focal Point through the existing manual channels.',
            'legal' => [
                'basis'            => 'IHR (2005), Articles 6–11 · Annex 2 decision instrument',
                'custodian'        => 'WHO Lyon Office · EIS / PEIN gateway',
                'focal_point_role' => 'National IHR Focal Point (NATIONAL_ADMIN)',
            ],
            'interfaces' => [
                [
                    'direction'   => 'OUTBOUND',
                    'name'        => 'IHR Article 6 notification',
                    'plain_name'  => 'Sending a formal IHR alert to WHO',
                    'trigger'     => 'An alert reaches the highest tier or scores ≥ 2 of 4 on the Annex 2 decision instrument.',
                    'sla_phrase'  => 'Within 24 hours of detection.',
                ],
                [
                    'direction'   => 'OUTBOUND',
                    'name'        => 'IHR Article 11 · response measure update',
                    'plain_name'  => 'Telling WHO what we are doing about it',
                    'trigger'     => 'When an alert closes or a follow-up milestone is reached.',
                    'sla_phrase'  => 'On the next event change.',
                ],
                [
                    'direction'   => 'INBOUND',
                    'name'        => 'EIOS signal injection',
                    'plain_name'  => 'Pulling in WHO-verified signals',
                    'trigger'     => 'WHO publishes a signal we should be aware of.',
                    'sla_phrase'  => 'Continuous.',
                ],
                [
                    'direction'   => 'INBOUND',
                    'name'        => 'EIS gazette pull',
                    'plain_name'  => 'Daily summary of cross-border outbreaks',
                    'trigger'     => 'Once per day, to keep the endemic overlay current.',
                    'sla_phrase'  => 'Daily.',
                ],
            ],
            'readiness'      => $readiness,
            'readiness_done' => $doneCount,
            'readiness_total'=> count($readiness),
            'interest' => [
                'count'             => $interestCount,
                'you_already_asked' => $youAlreadyAsked,
            ],
            'next_actions' => [
                'Request WHO Lyon onboarding via the National IHR Focal Point.',
                'Provision sandbox credentials in the application secrets store.',
                'Implement the outbound client behind the WHO_EIS_ENABLED feature flag.',
                'Dry-run with a synthetic Tier-1 alert in staging before cutover.',
            ],
            'preview' => [
                'health_pill'          => 'When live, the pill above will say "All notifications delivered" or name the failures.',
                'recent_notifications' => 'When live, this tab will list the IHR notifications the platform has sent and their delivery status.',
                'incoming_signals'     => 'When live, this tab will list the EIOS signals the platform has received and where they were ingested into the Signal Inbox.',
            ],
        ]);
    }

    /**
     * Record an operator's interest in being notified when the connector
     * is enabled. Writes one row to auth_events. Idempotent — a second
     * click from the same user does not create a duplicate row.
     */
    public function notifyMe(Request $request): JsonResponse
    {
        try {
            $u = $request->user();
            if (! $u) {
                return $this->err(401, 'Sign in to register interest.');
            }
            $userId = (int) $u->getAuthIdentifier();

            $alreadyAsked = false;
            if (Schema::hasTable('auth_events')) {
                $alreadyAsked = (bool) DB::table('auth_events')
                    ->where('user_id', $userId)
                    ->where('event_type', 'WHO_EIS_NOTIFY_ME_REQUESTED')
                    ->exists();
            }

            if (! $alreadyAsked) {
                AuthEventLogger::log(
                    'WHO_EIS_NOTIFY_ME_REQUESTED',
                    $userId,
                    null,
                    'INFO',
                    [
                        'idempotency_key' => $this->idempotencyKey($request),
                        'connector'       => 'WHO_EIS',
                    ],
                    0,
                    $request,
                );
            }

            $this->auditView($request, [
                'action'         => 'notify_me',
                'idempotency_key'=> $this->idempotencyKey($request),
                'already_asked'  => $alreadyAsked,
            ], ['row_count' => 1]);

            return $this->ok([
                'recorded'        => true,
                'already_asked'   => $alreadyAsked,
                'plain_summary'   => $alreadyAsked
                    ? 'Your interest was already recorded. We will notify you when the connector is enabled.'
                    : 'Your interest is recorded. We will notify you when the connector is enabled.',
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'notifyMe');
        }
    }
}
