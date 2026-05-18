<?php

declare(strict_types=1);

namespace App\Support;

/**
 * EnumTranslator
 * ---------------------------------------------------------------------------
 * Single public surface for translating every database enum / internal code
 * into plain public-health English. Views must NEVER hard-code a translation
 * map; they call this helper (often via the `x-ui.badge` component).
 *
 * Rule S5 (UI_STANDARDS): raw enum codes (tier1_*, CLOSED, RTSL_ACTION_*,
 * etc.) must never reach the user. Every admin view resolves here.
 *
 * The maps below are the frozen seed from DB_MAP.md §Z. Do NOT modify
 * without first updating DB_MAP.md.
 *
 * Usage:
 *   $translator = app(\App\Support\EnumTranslator::class);
 *   $translator->riskLevel('CRITICAL');            // "Critical"
 *   $translator->alertStatus('ACKNOWLEDGED');      // "Acknowledged"
 *   $translator->closeCategory('LOST_TO_FOLLOWUP');// "Lost to follow-up"
 *   $translator->ihrTier('TIER_1_ALWAYS_NOTIFIABLE'); // "WHO Tier 1 — always notifiable"
 *   $translator->tone('risk', 'HIGH');             // "high"  (for badge / ring)
 */
final class EnumTranslator
{
    /* ────────────────────────────────────────────────────────────────
     *  RISK / SEVERITY
     * ──────────────────────────────────────────────────────────────── */

    public function riskLevel(?string $code): string
    {
        if ($code === null || $code === '') return 'Unknown';
        return match (strtoupper($code)) {
            'LOW'      => 'Low',
            'MEDIUM'   => 'Medium',
            'HIGH'     => 'High',
            'CRITICAL' => 'Critical',
            default    => ucwords(strtolower(str_replace('_', ' ', $code))),
        };
    }

    /** Tone name for risk level — maps to badge / chip classes. */
    public function riskTone(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'LOW'      => 'low',
            'MEDIUM'   => 'medium',
            'HIGH'     => 'high',
            'CRITICAL' => 'critical',
            default    => 'default',
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  IHR TIER
     * ──────────────────────────────────────────────────────────────── */

    public function ihrTier(?string $code): string
    {
        if ($code === null || $code === '') return 'Not assessed';
        return match (strtoupper($code)) {
            'TIER_1_ALWAYS_NOTIFIABLE', 'TIER_1'  => 'WHO Tier 1 — always notifiable',
            'ANNEX2_EPIDEMIC_PRONE', 'TIER_2'     => 'WHO Annex 2 — epidemic-prone',
            'WHO_NOTIFIABLE', 'TIER_3'            => 'WHO notifiable',
            'SYNDROMIC', 'TIER_4'                 => 'Syndromic surveillance',
            default                                => ucwords(strtolower(str_replace('_', ' ', $code))),
        };
    }

    /** Tone name for IHR tier — maps to badge classes. */
    public function ihrTone(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'TIER_1_ALWAYS_NOTIFIABLE', 'TIER_1'  => 'critical',
            'ANNEX2_EPIDEMIC_PRONE', 'TIER_2'     => 'high',
            'WHO_NOTIFIABLE', 'TIER_3'            => 'warning',
            'SYNDROMIC', 'TIER_4'                 => 'info',
            default                                => 'default',
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  ALERT · status, close category, routing
     * ──────────────────────────────────────────────────────────────── */

    public function alertStatus(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'OPEN'         => 'Open',
            'ACKNOWLEDGED' => 'Acknowledged',
            'CLOSED'       => 'Closed',
            default        => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    public function alertStatusTone(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'OPEN'         => 'critical',
            'ACKNOWLEDGED' => 'warning',
            'CLOSED'       => 'success',
            default        => 'default',
        };
    }

    public function closeCategory(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'RESOLVED'                   => 'Resolved',
            'FALSE_POSITIVE'             => 'False positive',
            'DUPLICATE'                  => 'Duplicate (merged into another case)',
            'LOST_TO_FOLLOWUP'           => 'Lost to follow-up',
            'TRANSFERRED_OUT_OF_COUNTRY' => 'Transferred out of country',
            'DECEASED'                   => 'Deceased',
            'OTHER'                      => 'Other (see note)',
            default                      => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    public function routedToLevel(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'POE'      => 'Point of entry',
            'DISTRICT' => 'District',
            'PHEOC'    => 'PHEOC regional centre',
            'NATIONAL' => 'National',
            'WHO'      => 'WHO (international)',
            default    => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  ALERT COLLABORATOR ROLE
     * ──────────────────────────────────────────────────────────────── */

    public function collaboratorRole(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'INCIDENT_COMMANDER' => 'Incident commander',
            'CASE_OWNER'         => 'Case owner',
            'CLINICAL_LEAD'      => 'Clinical lead',
            'LAB_LIAISON'        => 'Lab liaison',
            'DISTRICT_LIAISON'   => 'District liaison',
            'PHEOC_LIAISON'      => 'PHEOC liaison',
            'NATIONAL_LIAISON'   => 'National liaison',
            'WHO_LIAISON'        => 'WHO liaison',
            'CONTACT_TRACER'     => 'Contact tracer',
            'RISK_COMMS'         => 'Risk communications',
            'LOGISTICS'          => 'Logistics',
            'OBSERVER'           => 'Observer',
            default              => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  HANDOFF STATUS
     * ──────────────────────────────────────────────────────────────── */

    public function handoffStatus(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'SENT'         => 'Handoff sent',
            'ACKNOWLEDGED' => 'Acknowledged',
            'ACCEPTED'     => 'Accepted',
            'REJECTED'     => 'Rejected',
            'RECALLED'     => 'Recalled',
            default        => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  FOLLOWUP · status, action code (RTSL-14)
     * ──────────────────────────────────────────────────────────────── */

    public function followupStatus(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'PENDING'       => 'Pending',
            'IN_PROGRESS'   => 'In progress',
            'COMPLETED'     => 'Completed',
            'BLOCKED'       => 'Blocked',
            'NOT_APPLICABLE'=> 'Not applicable',
            default         => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    public function followupStatusTone(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'PENDING'       => 'warning',
            'IN_PROGRESS'   => 'info',
            'COMPLETED'     => 'success',
            'BLOCKED'       => 'critical',
            'NOT_APPLICABLE'=> 'default',
            default         => 'default',
        };
    }

    /** RTSL-14 action code translations. */
    public function followupAction(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'CASE_INVESTIGATION'   => 'Case investigation',
            'ISOLATION'            => 'Patient isolation',
            'CONTACT_LISTING'      => 'Contact listing',
            'CONTACT_TRACING'      => 'Contact tracing',
            'EOC_ACTIVATION'       => 'EOC activation',
            'WHO_NOTIFICATION'     => 'WHO notification',
            'COORDINATION_MEETING' => 'Coordination meeting',
            'SAMPLE_COLLECTION'    => 'Sample collection',
            'LAB_TESTING'          => 'Laboratory testing',
            'RISK_COMMS'           => 'Risk communications',
            'COMMUNITY_ENGAGEMENT' => 'Community engagement',
            'IPC'                  => 'Infection prevention & control',
            'SAFE_BURIAL'          => 'Safe & dignified burial',
            'VACCINATION'          => 'Vaccination',
            default                => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    /** The 6 RTSL-14 actions that block alert closure server-side. */
    public function blocksClosure(?string $code): bool
    {
        return in_array(strtoupper((string) $code), [
            'CASE_INVESTIGATION',
            'ISOLATION',
            'CONTACT_LISTING',
            'CONTACT_TRACING',
            'EOC_ACTIVATION',
            'WHO_NOTIFICATION',
        ], true);
    }

    /* ────────────────────────────────────────────────────────────────
     *  EVIDENCE CATEGORY
     * ──────────────────────────────────────────────────────────────── */

    public function evidenceCategory(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'DOCUMENT'       => 'Document',
            'PHOTO'          => 'Photograph',
            'LAB_RESULT'     => 'Lab result',
            'CONSENT'        => 'Consent form',
            'WHO_FORM'       => 'WHO form',
            'CONTACT_LIST'   => 'Contact list',
            'SOP_SIGN_OFF'   => 'SOP sign-off',
            'PPE_CHECKLIST'  => 'PPE checklist',
            'OTHER'          => 'Other',
            default          => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  BREACH REPORT · phase, status
     * ──────────────────────────────────────────────────────────────── */

    public function breachPhase(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'DETECT'  => 'Detect (target ≤ 7 days)',
            'NOTIFY'  => 'Notify (target ≤ 1 day)',
            'RESPOND' => 'Respond (target ≤ 7 days)',
            default   => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    public function breachStatus(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'LOGGED'      => 'Logged',
            'IN_PROGRESS' => 'Under investigation',
            'RESOLVED'    => 'Resolved',
            default       => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  EXTERNAL RESPONDER TYPE
     * ──────────────────────────────────────────────────────────────── */

    public function responderType(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'HOSPITAL'         => 'Hospital',
            'LAB'              => 'Laboratory',
            'EMS'              => 'Emergency medical services',
            'LAW_ENFORCEMENT'  => 'Law enforcement',
            'PARTNER_AGENCY'   => 'Partner agency',
            'OTHER'            => 'Other',
            default            => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  NOTIFICATION · template, channel, status
     * ──────────────────────────────────────────────────────────────── */

    public function notificationTemplate(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'TIER1_ADVISORY'         => 'Tier 1 advisory',
            'ALERT_CRITICAL'         => 'Critical alert',
            'ALERT_HIGH'             => 'High-risk alert',
            'ALERT_CASE_FILE'        => 'Case-file alert',
            'ALERT_CLOSED'           => 'Alert closed',
            'PHEIC_ADVISORY'         => 'PHEIC advisory',
            'ESCALATION'             => 'Escalation',
            'FOLLOWUP_DUE'           => 'Follow-up due',
            'FOLLOWUP_OVERDUE'       => 'Follow-up overdue',
            'BREACH_717'             => '7-1-7 breach',
            'DAILY_REPORT'           => 'Daily digest',
            'WEEKLY_REPORT'          => 'Weekly scorecard',
            'NATIONAL_INTELLIGENCE'  => 'National intelligence brief',
            'RESPONDER_INFO_REQUEST' => 'Responder information request',
            'AUTH_INVITATION'        => 'Invitation',
            'AUTH_WELCOME'           => 'Welcome',
            'AUTH_VERIFY_EMAIL'      => 'Email verification',
            'AUTH_PASSWORD_RESET'    => 'Password reset',
            'AUTH_PASSWORD_CHANGED'  => 'Password changed',
            'AUTH_TWOFA_ENABLED'     => 'Two-factor enabled',
            'AUTH_TWOFA_DISABLED'    => 'Two-factor disabled',
            'AUTH_NEW_LOGIN_DEVICE'  => 'New device sign-in',
            'AUTH_ACCOUNT_LOCKED'    => 'Account locked',
            'AUTH_SUSPENDED'         => 'Account suspended',
            default                  => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    public function notificationChannel(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'EMAIL' => 'Email',
            'SMS'   => 'SMS',
            'PUSH'  => 'Push notification',
            default => ucwords(strtolower((string) $code)),
        };
    }

    public function notificationStatus(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'QUEUED'    => 'Queued',
            'SENT'      => 'Sent',
            'DELIVERED' => 'Delivered',
            'FAILED'    => 'Failed',
            'BOUNCED'   => 'Bounced',
            'SKIPPED'   => 'Suppressed (dedup)',
            default     => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    public function notificationStatusTone(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'QUEUED'    => 'info',
            'SENT'      => 'success',
            'DELIVERED' => 'success',
            'FAILED'    => 'critical',
            'BOUNCED'   => 'critical',
            'SKIPPED'   => 'default',
            default     => 'default',
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  USER · role, account type
     * ──────────────────────────────────────────────────────────────── */

    public function roleKey(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'NATIONAL_ADMIN'      => 'National administrator',
            'PHEOC_OFFICER'       => 'PHEOC officer',
            'DISTRICT_SUPERVISOR' => 'District supervisor',
            'POE_ADMIN'           => 'Port-of-entry administrator',
            'POE_OFFICER'         => 'Port-of-entry officer',
            'POE_DATA_OFFICER'    => 'POE data officer',
            'POE_SECONDARY'       => 'POE secondary screener',
            'POE_PRIMARY'         => 'POE primary screener',
            'SCREENER'            => 'Screener',
            'OBSERVER'            => 'Observer',
            'SERVICE'             => 'Service account',
            'SUPER_ADMIN'         => 'Super administrator',
            default               => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    public function scopeLevel(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'NATIONAL' => 'National',
            'PHEOC'    => 'PHEOC region',
            'DISTRICT' => 'District',
            'POE'      => 'Point of entry',
            'SELF'     => 'Self only',
            default    => ucwords(strtolower((string) $code)),
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  AGGREGATED TEMPLATE · status, column data type, aggregation fn
     * ──────────────────────────────────────────────────────────────── */

    public function templateStatus(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'DRAFT'     => 'Draft',
            'PUBLISHED' => 'Published',
            'RETIRED'   => 'Retired',
            'LOCKED'    => 'Locked',
            default     => ucwords(strtolower((string) $code)),
        };
    }

    public function columnDataType(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'INTEGER' => 'Whole number',
            'DECIMAL' => 'Decimal',
            'TEXT'    => 'Text',
            'BOOLEAN' => 'Yes / no',
            'DATE'    => 'Date',
            'PERCENT' => 'Percentage',
            'SELECT'  => 'Pick list',
            default   => ucwords(strtolower((string) $code)),
        };
    }

    public function aggregationFn(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'SUM'    => 'Sum',
            'AVG'    => 'Average',
            'MIN'    => 'Minimum',
            'MAX'    => 'Maximum',
            'COUNT'  => 'Count',
            'LATEST' => 'Latest submission',
            'NONE'   => 'No aggregation',
            default  => ucwords(strtolower((string) $code)),
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  AUTH EVENT · event type, severity
     * ──────────────────────────────────────────────────────────────── */

    public function authEventType(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'LOGIN'           => 'Sign-in',
            'LOGOUT'          => 'Sign-out',
            'MFA_SETUP'       => 'Two-factor enabled',
            'MFA_DISABLE'     => 'Two-factor disabled',
            '2FA_VERIFY'      => 'Two-factor verification',
            'PASSWORD_RESET'  => 'Password reset',
            'PASSWORD_CHANGE' => 'Password change',
            'LOCKOUT'         => 'Lockout triggered',
            'ACCOUNT_LOCKED'  => 'Account locked',
            'FORBIDDEN'       => 'Access denied',
            'DEVICE_TRUSTED'  => 'Trusted device added',
            'DEVICE_REVOKED'  => 'Trusted device revoked',
            'SESSION_REVOKED' => 'Session revoked',
            default           => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    public function severity(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'INFO'     => 'Info',
            'WARN'     => 'Warning',
            'ERROR'    => 'Error',
            'CRITICAL' => 'Critical',
            default    => ucwords(strtolower((string) $code)),
        };
    }

    public function severityTone(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'INFO'     => 'info',
            'WARN'     => 'warning',
            'ERROR'    => 'critical',
            'CRITICAL' => 'critical',
            default    => 'default',
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  USER ANOMALY FLAGS
     * ──────────────────────────────────────────────────────────────── */

    public function anomalyFlag(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'DORMANT'                 => 'Dormant (no sign-in 14 d+)',
            'PASSWORD_STALE'          => 'Password not rotated',
            'FREQUENT_FAILED_LOGINS'  => 'Many failed sign-ins',
            'MULTIPLE_IPS_24H'        => 'Many IPs in 24 h',
            'MULTIPLE_DEVICES_24H'    => 'Many devices in 24 h',
            'NO_MFA_FOR_ADMIN'        => 'Admin without two-factor',
            'WEAK_PASSWORD_AGE'       => 'Password change overdue',
            'INVITATION_OLD'          => 'Invitation never accepted',
            'ACCOUNT_NEVER_USED'      => 'Account never used',
            'ROLE_ACTIVITY_MISMATCH'  => 'Role / activity mismatch',
            'UNUSUAL_HOURS'           => 'Sign-in at unusual hours',
            'IMPOSSIBLE_TRAVEL'       => 'Impossible travel',
            'EMAIL_UNVERIFIED_ADMIN'  => 'Admin with unverified email',
            'LOCKED_OUT'              => 'Locked out',
            default                   => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  ALERT TIMELINE EVENT CODE (subset — TimelineBuilder is the full deal)
     * ──────────────────────────────────────────────────────────────── */

    public function timelineEvent(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'ALERT_CREATED'        => 'Alert created',
            'ACKNOWLEDGED'         => 'Alert acknowledged',
            'ESCALATED'            => 'Alert escalated',
            'REASSIGNED'           => 'Alert reassigned',
            'REOPENED'             => 'Alert reopened',
            'ALERT_CLOSED'         => 'Alert closed',
            'COLLABORATOR_ADDED'   => 'Collaborator added',
            'COLLABORATOR_REMOVED' => 'Collaborator removed',
            'COMMENT_POSTED'       => 'Comment posted',
            'COMMENT_PINNED'       => 'Comment pinned',
            'EVIDENCE_UPLOADED'    => 'Evidence uploaded',
            'HANDOFF_SENT'         => 'Handoff sent',
            'HANDOFF_ACCEPTED'     => 'Handoff accepted',
            'HANDOFF_REJECTED'     => 'Handoff rejected',
            'FOLLOWUP_CREATED'     => 'Follow-up created',
            'FOLLOWUP_COMPLETED'   => 'Follow-up completed',
            'FOLLOWUP_OVERDUE'     => 'Follow-up overdue',
            'BREACH_717_DETECTED'  => '7-1-7 breach detected',
            'PHEIC_DECLARED'       => 'PHEIC pathway declared',
            'EXTERNAL_INFO_REQUESTED' => 'External info requested',
            'EXTERNAL_INFO_RECEIVED'  => 'External info received',
            'NOTIFICATION_SENT'    => 'Notification sent',
            default                => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  SECONDARY SCREENING · disposition, symptom / exposure presence
     * ──────────────────────────────────────────────────────────────── */

    /**
     * Secondary screening final_disposition enum values (matches DB):
     * RELEASED, DELAYED, QUARANTINED, ISOLATED, REFERRED, TRANSFERRED,
     * DENIED_BOARDING, OTHER.
     */
    public function disposition(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'RELEASED'        => 'Released (cleared)',
            'DELAYED'         => 'Delayed for further assessment',
            'QUARANTINED'     => 'Quarantined',
            'ISOLATED'        => 'Isolated on-site',
            'REFERRED'        => 'Referred (hospital / clinic / lab)',
            'TRANSFERRED'     => 'Transferred',
            'DENIED_BOARDING' => 'Denied boarding',
            'OTHER'           => 'Other (see note)',
            default           => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    /** Secondary screening case_status enum: OPEN, IN_PROGRESS, DISPOSITIONED, CLOSED. */
    public function caseStatus(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'OPEN'          => 'Open',
            'IN_PROGRESS'   => 'In progress',
            'DISPOSITIONED' => 'Dispositioned',
            'CLOSED'        => 'Closed',
            default         => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    /** Generated-from enum for alerts: RULE_BASED or OFFICER. */
    public function generatedFrom(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'RULE_BASED' => 'Rule-based (auto)',
            'OFFICER'    => 'Officer-initiated',
            default      => ucwords(strtolower(str_replace('_', ' ', (string) $code))),
        };
    }

    public function presence(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'YES'     => 'Yes',
            'NO'      => 'No',
            'UNKNOWN' => 'Unknown',
            default   => ucwords(strtolower((string) $code)),
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  SYNC STATUS (mobile IDB)
     * ──────────────────────────────────────────────────────────────── */

    public function syncStatus(?string $code): string
    {
        return match (strtoupper((string) $code)) {
            'UNSYNCED'    => 'Pending sync',
            'SYNCING'     => 'Syncing',
            'SYNCED'      => 'Synced',
            'FAILED'      => 'Sync failed',
            'QUARANTINED' => 'Quarantined',
            default       => ucwords(strtolower((string) $code)),
        };
    }

    /* ────────────────────────────────────────────────────────────────
     *  GENERIC FALLBACK · lowercase_with_underscores → Title Case
     * ──────────────────────────────────────────────────────────────── */

    public function humanize(?string $code): string
    {
        if ($code === null || $code === '') return '—';
        return ucwords(strtolower(str_replace('_', ' ', $code)));
    }

    /* ────────────────────────────────────────────────────────────────
     *  GENERIC TONE DISPATCHER · used by components that accept a
     *  {kind, code} pair (e.g. <x-ui.badge :code="..." kind="risk"/>).
     * ──────────────────────────────────────────────────────────────── */

    public function tone(string $kind, ?string $code): string
    {
        return match ($kind) {
            'risk'                  => $this->riskTone($code),
            'ihr'                   => $this->ihrTone($code),
            'alert_status'          => $this->alertStatusTone($code),
            'followup_status'       => $this->followupStatusTone($code),
            'notification_status'   => $this->notificationStatusTone($code),
            'severity'              => $this->severityTone($code),
            default                 => 'default',
        };
    }

    public function label(string $kind, ?string $code): string
    {
        return match ($kind) {
            'risk'                => $this->riskLevel($code),
            'ihr'                 => $this->ihrTier($code),
            'alert_status'        => $this->alertStatus($code),
            'close_category'      => $this->closeCategory($code),
            'routed_to'           => $this->routedToLevel($code),
            'collaborator_role'   => $this->collaboratorRole($code),
            'handoff_status'      => $this->handoffStatus($code),
            'followup_status'     => $this->followupStatus($code),
            'followup_action'     => $this->followupAction($code),
            'evidence_category'   => $this->evidenceCategory($code),
            'breach_phase'        => $this->breachPhase($code),
            'breach_status'       => $this->breachStatus($code),
            'responder_type'      => $this->responderType($code),
            'notification_template'=> $this->notificationTemplate($code),
            'notification_channel'=> $this->notificationChannel($code),
            'notification_status' => $this->notificationStatus($code),
            'role'                => $this->roleKey($code),
            'scope_level'         => $this->scopeLevel($code),
            'template_status'     => $this->templateStatus($code),
            'column_data_type'    => $this->columnDataType($code),
            'aggregation_fn'      => $this->aggregationFn($code),
            'auth_event'          => $this->authEventType($code),
            'severity'            => $this->severity($code),
            'anomaly_flag'        => $this->anomalyFlag($code),
            'timeline_event'      => $this->timelineEvent($code),
            'disposition'         => $this->disposition($code),
            'presence'            => $this->presence($code),
            'sync_status'         => $this->syncStatus($code),
            default               => $this->humanize($code),
        };
    }
}
