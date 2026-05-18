<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single writer for alert_timeline_events.
 *
 * Existing controllers (Admin\Alerts\*) inline DB::insert into this table
 * with slight variations. They keep working — this service exists so new
 * code (the wizard, refactored mutation paths) records timeline events
 * through one place. Inserts are append-only; correction is a fresh event.
 *
 * Failures are logged, never thrown, so timeline outage does not break
 * the user-facing action.
 */
final class TimelineRecorder
{
    public const SEVERITY_INFO     = 'INFO';
    public const SEVERITY_WARN     = 'WARN';
    public const SEVERITY_ERROR    = 'ERROR';
    public const SEVERITY_CRITICAL = 'CRITICAL';

    /**
     * Records a timeline event for the given alert.
     *
     * @param array<string,mixed> $payload  free-form structured detail (json-encoded)
     */
    public function record(
        int     $alertId,
        string  $eventCode,
        string  $eventCategory,
        string  $summary,
        array   $payload         = [],
        string  $severity        = self::SEVERITY_INFO,
        ?int    $actorUserId     = null,
        ?string $relatedEntityType = null,
        ?int    $relatedEntityId = null
    ): ?int {
        try {
            $actorUserId ??= (int) (Auth::id() ?? 0) ?: null;

            $actor = $actorUserId
                ? DB::table('users')->where('id', $actorUserId)->first(['full_name', 'role_key'])
                : null;

            $id = DB::table('alert_timeline_events')->insertGetId([
                'alert_id'            => $alertId,
                'event_code'          => $eventCode,
                'event_category'      => $eventCategory,
                'severity'            => $severity,
                'actor_user_id'       => $actorUserId,
                'actor_name'          => $actor->full_name ?? null,
                'actor_role'          => $actor->role_key  ?? null,
                'summary'             => mb_substr($summary, 0, 500),
                'payload_json'        => $payload === [] ? null : json_encode($payload, JSON_UNESCAPED_UNICODE),
                'related_entity_type' => $relatedEntityType,
                'related_entity_id'   => $relatedEntityId,
                'created_at'          => Carbon::now(),
            ]);

            return is_int($id) ? $id : null;
        } catch (Throwable $e) {
            Log::warning('[TimelineRecorder][record] ' . $e->getMessage(), [
                'alert_id'   => $alertId,
                'event_code' => $eventCode,
            ]);
            return null;
        }
    }

    /**
     * Records a wizard step decision. Adds the wizard category and a
     * standardised payload shape so the case-room view can group these
     * together.
     *
     * @param array<string,mixed> $extra
     */
    public function recordWizardDecision(
        int     $alertId,
        string  $stepCode,
        string  $optionCode,
        string  $summary,
        array   $extra        = [],
        ?int    $actorUserId  = null
    ): ?int {
        return $this->record(
            $alertId,
            'WIZARD_DECISION',
            'WIZARD',
            $summary,
            array_merge(['step' => $stepCode, 'option' => $optionCode], $extra),
            self::SEVERITY_INFO,
            $actorUserId
        );
    }

    /**
     * Records a stakeholder contact action (resend, ask new, mark called).
     *
     * @param array<string,mixed> $extra
     */
    public function recordStakeholderContact(
        int     $alertId,
        string  $action,
        string  $summary,
        array   $extra        = [],
        ?int    $actorUserId  = null
    ): ?int {
        return $this->record(
            $alertId,
            'STAKEHOLDER_CONTACT',
            'WIZARD',
            $summary,
            array_merge(['action' => $action], $extra),
            self::SEVERITY_INFO,
            $actorUserId
        );
    }
}
