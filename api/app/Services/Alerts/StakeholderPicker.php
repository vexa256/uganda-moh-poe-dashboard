<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Support\Alerts\HumanLabels;
use App\Support\Scope\ScopeFilter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resolves "who can help" / "who has been told" for the resolution wizard.
 *
 * Three sources, in this precedence (de-duplicated by lowercase email):
 *   1. external_responders          — named field experts with email/phone
 *   2. poe_notification_contacts    — geography-routed inboxes
 *   3. users + user_assignments     — registered staff at the alert's POE/district
 *
 * forStep()   — suggestions filtered to the current wizard step.
 * forAlert()  — three-bucket panel (notified / responded / silent).
 *
 * Scope is honoured everywhere — a district-level officer never sees
 * contacts in other districts.
 */
final class StakeholderPicker
{
    /**
     * Per-step relevance hints.
     *
     *   responder_types[]  — match against external_responders.responder_type
     *   levels[]           — match against poe_notification_contacts.level
     *   user_role_keys[]   — match against users.role_key
     */
    private const STEP_LEVELS = [
        'CASE_INVESTIGATION'    => ['responder_types' => ['FIELD_TEAM','CLINICAL'],   'levels' => ['POE','DISTRICT'],          'user_role_keys' => ['POE_OFFICER','DISTRICT_SUPERVISOR']],
        'ISOLATION'             => ['responder_types' => ['CLINICAL','HOSPITAL'],     'levels' => ['POE','DISTRICT'],          'user_role_keys' => ['POE_OFFICER','DISTRICT_SUPERVISOR']],
        'CONTACT_LISTING'       => ['responder_types' => ['FIELD_TEAM'],              'levels' => ['DISTRICT'],                'user_role_keys' => ['DISTRICT_SUPERVISOR','POE_OFFICER']],
        'CONTACT_TRACING'       => ['responder_types' => ['FIELD_TEAM'],              'levels' => ['DISTRICT'],                'user_role_keys' => ['DISTRICT_SUPERVISOR']],
        'LAB_SPECIMENS'         => ['responder_types' => ['LAB'],                     'levels' => ['DISTRICT','PHEOC','NATIONAL'], 'user_role_keys' => []],
        'LAB_CONFIRMATION'      => ['responder_types' => ['LAB'],                     'levels' => ['NATIONAL','PHEOC'],        'user_role_keys' => []],
        'LINE_LIST'             => ['responder_types' => ['FIELD_TEAM'],              'levels' => ['DISTRICT','PHEOC'],        'user_role_keys' => ['DISTRICT_SUPERVISOR','PHEOC_OFFICER']],
        'EOC_ACTIVATION'        => ['responder_types' => [],                          'levels' => ['PHEOC','NATIONAL'],        'user_role_keys' => ['PHEOC_OFFICER','NATIONAL_ADMIN']],
        'WHO_NOTIFICATION'      => ['responder_types' => [],                          'levels' => ['NATIONAL','WHO'],          'user_role_keys' => ['NATIONAL_ADMIN']],
        'IPC'                   => ['responder_types' => ['HOSPITAL','CLINICAL'],     'levels' => ['POE','DISTRICT'],          'user_role_keys' => ['POE_OFFICER','DISTRICT_SUPERVISOR']],
        'RISK_COMMS'            => ['responder_types' => ['PRESS','COMMS'],           'levels' => ['PHEOC','NATIONAL'],        'user_role_keys' => ['PHEOC_OFFICER','NATIONAL_ADMIN']],
        'VECTOR_CONTROL'        => ['responder_types' => ['FIELD_TEAM'],              'levels' => ['DISTRICT'],                'user_role_keys' => ['DISTRICT_SUPERVISOR']],
        'POE_SURVEILLANCE'      => ['responder_types' => ['FIELD_TEAM'],              'levels' => ['POE','DISTRICT'],          'user_role_keys' => ['POE_OFFICER','DISTRICT_SUPERVISOR']],
        'RESOURCE_MOBILISATION' => ['responder_types' => ['LOGISTICS'],               'levels' => ['NATIONAL','PHEOC'],        'user_role_keys' => ['NATIONAL_ADMIN','PHEOC_OFFICER']],
    ];

    /**
     * Suggestions for the current wizard step.
     *
     * @param array<string,mixed> $scope
     * @return array{suggestions:array<int,array<string,mixed>>,reason:string}
     */
    public function forStep(int $alertId, string $stepCode, array $scope): array
    {
        $alert = $this->loadAlert($alertId, $scope);
        if (!$alert) return ['suggestions' => [], 'reason' => 'Alert not visible.'];

        $hint = self::STEP_LEVELS[$stepCode] ?? ['responder_types' => [], 'levels' => [], 'user_role_keys' => []];

        $rs = $this->fromExternalResponders($alert, $hint['responder_types']);
        $cs = $this->fromNotificationContacts($alert, $hint['levels']);
        $us = $this->fromAssignedUsers($alert, $hint['user_role_keys']);

        return [
            'suggestions' => $this->dedupe($rs, $cs, $us),
            'reason'      => 'These are the people best placed to help with this step.',
        ];
    }

    /**
     * "Who is involved" panel for the alert as a whole.
     *
     * @return array{notified:array<int,array<string,mixed>>,responded:array<int,array<string,mixed>>,silent:array<int,array<string,mixed>>}
     */
    public function forAlert(int $alertId, array $scope): array
    {
        $alert = $this->loadAlert($alertId, $scope);
        if (!$alert) return ['notified' => [], 'responded' => [], 'silent' => []];

        $logs = DB::table('notification_log')
            ->where('related_entity_type', 'ALERT')
            ->where('related_entity_id', $alertId)
            ->orderBy('created_at', 'desc')
            ->get(['to_email', 'template_code', 'status', 'sent_at', 'created_at']);

        $byEmail = [];
        foreach ($logs as $log) {
            $email = strtolower((string) $log->to_email);
            if ($email === '') continue;
            $byEmail[$email] = $byEmail[$email] ?? [];
            $byEmail[$email][] = (array) $log;
        }

        $responses = DB::table('responder_info_requests')
            ->where('alert_id', $alertId)
            ->whereIn('status', ['SENT', 'RECEIVED'])
            ->get(['responder_id', 'status', 'responded_at', 'created_at']);

        $respondedRespIds = [];
        foreach ($responses as $r) {
            if ($r->status === 'RECEIVED' && !empty($r->responded_at)) {
                $respondedRespIds[(int) $r->responder_id] = true;
            }
        }

        $responderEmails = [];
        if (!empty($respondedRespIds)) {
            $rows = DB::table('external_responders')
                ->whereIn('id', array_keys($respondedRespIds))
                ->get(['id','email']);
            foreach ($rows as $row) {
                $responderEmails[strtolower((string) $row->email)] = true;
            }
        }

        $notified  = [];
        $responded = [];
        $silent    = [];

        foreach ($byEmail as $email => $rows) {
            $latest = $rows[0];
            $name   = $this->lookupNameForEmail($email);
            $entry  = [
                'email'     => $email,
                'name'      => $name ?: $email,
                'last_sent' => HumanLabels::dueHuman((string) ($latest['sent_at'] ?? $latest['created_at'])),
                'channel'   => 'EMAIL',
            ];

            $notified[] = $entry;

            if (isset($responderEmails[$email])) {
                $responded[] = $entry;
            } else {
                $silent[] = $entry;
            }
        }

        return ['notified' => $notified, 'responded' => $responded, 'silent' => $silent];
    }

    // ------------------------------------------------------------------
    //  Internals
    // ------------------------------------------------------------------

    /** @return object|null */
    private function loadAlert(int $alertId, array $scope)
    {
        $q = DB::table('alerts')->where('id', $alertId)->whereNull('deleted_at');
        ScopeFilter::applyToAlerts($q, $scope);
        return $q->first(['id','poe_code','district_code','province_code','country_code','risk_level','ihr_tier']);
    }

    /**
     * @param string[] $responderTypes
     * @return array<int,array<string,mixed>>
     */
    private function fromExternalResponders(object $alert, array $responderTypes): array
    {
        $q = DB::table('external_responders')
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($alert) {
                $q->where('district_code', $alert->district_code)
                  ->orWhereNull('district_code'); // national-scope responders
            });

        if ($alert->country_code) {
            $q->where(function ($q) use ($alert) {
                $q->where('country_code', $alert->country_code)
                  ->orWhereNull('country_code');
            });
        }

        if (!empty($responderTypes)) {
            $q->whereIn('responder_type', $responderTypes);
        }

        return $q->limit(15)->get(['id','name','organisation','position','email','phone','responder_type'])
            ->map(fn ($r) => [
                'id'           => (int) $r->id,
                'kind'         => 'external_responder',
                'kind_label'   => 'Outside expert',
                'name'         => (string) $r->name,
                'role_or_pos'  => trim((string) ($r->position ?? '')) ?: $this->prettifyResponderType((string) $r->responder_type),
                'organisation' => (string) ($r->organisation ?? ''),
                'email'        => (string) ($r->email ?? ''),
                'phone'        => (string) ($r->phone ?? ''),
                'source'       => 'external_responders',
            ])
            ->all();
    }

    /**
     * @param string[] $levels
     * @return array<int,array<string,mixed>>
     */
    private function fromNotificationContacts(object $alert, array $levels): array
    {
        $q = DB::table('poe_notification_contacts')
            ->where('is_active', 1)
            ->whereNull('deleted_at');

        $q->where(function ($q) use ($alert) {
            $q->where('poe_code', $alert->poe_code)
              ->orWhere('district_code', $alert->district_code)
              ->orWhereNull('poe_code'); // national fallback
        });

        if (!empty($levels)) {
            $q->whereIn('level', $levels);
        }

        return $q->orderBy('priority_order')
            ->limit(15)
            ->get(['id','full_name','position','organisation','email','alternate_email','phone','level'])
            ->map(fn ($r) => [
                'id'           => (int) $r->id,
                'kind'         => 'notification_contact',
                'kind_label'   => HumanLabels::routedTo((string) $r->level),
                'name'         => (string) ($r->full_name ?? '—'),
                'role_or_pos'  => (string) ($r->position ?? ''),
                'organisation' => (string) ($r->organisation ?? ''),
                'email'        => (string) ($r->email ?? $r->alternate_email ?? ''),
                'phone'        => (string) ($r->phone ?? ''),
                'source'       => 'poe_notification_contacts',
            ])
            ->all();
    }

    /**
     * @param string[] $roleKeys
     * @return array<int,array<string,mixed>>
     */
    private function fromAssignedUsers(object $alert, array $roleKeys): array
    {
        if (empty($roleKeys)) return [];

        $q = DB::table('user_assignments as ua')
            ->join('users as u', 'u.id', '=', 'ua.user_id')
            ->where('u.is_active', 1)
            ->where('ua.is_active', 1)
            ->whereIn('u.role_key', $roleKeys)
            ->where(function ($q) use ($alert) {
                $q->where('ua.poe_code', $alert->poe_code)
                  ->orWhere('ua.district_code', $alert->district_code)
                  ->orWhere('ua.province_code', $alert->province_code);
            });

        return $q->limit(10)
            ->get(['u.id','u.full_name','u.email','u.role_key','ua.poe_code','ua.district_code'])
            ->map(fn ($r) => [
                'id'           => (int) $r->id,
                'kind'         => 'assigned_user',
                'kind_label'   => HumanLabels::prettify((string) $r->role_key),
                'name'         => (string) ($r->full_name ?? $r->email),
                'role_or_pos'  => HumanLabels::prettify((string) $r->role_key),
                'organisation' => '',
                'email'        => (string) ($r->email ?? ''),
                'phone'        => '',
                'source'       => 'users',
            ])
            ->all();
    }

    /**
     * Dedupes by lowercase email keeping the highest-precedence source first.
     *
     * @return array<int,array<string,mixed>>
     */
    private function dedupe(array ...$lists): array
    {
        $seen = [];
        $out  = [];
        foreach ($lists as $list) {
            foreach ($list as $row) {
                $email = strtolower((string) ($row['email'] ?? ''));
                if ($email === '' || isset($seen[$email])) continue;
                $seen[$email] = true;
                $out[] = $row;
            }
        }
        return $out;
    }

    private function lookupNameForEmail(string $email): ?string
    {
        $email = strtolower($email);
        $row = DB::table('poe_notification_contacts')
            ->whereRaw('LOWER(email)=? OR LOWER(alternate_email)=?', [$email, $email])
            ->first(['full_name']);
        if ($row && $row->full_name) return (string) $row->full_name;

        $row = DB::table('external_responders')
            ->whereRaw('LOWER(email)=?', [$email])
            ->first(['name']);
        if ($row && $row->name) return (string) $row->name;

        $row = DB::table('users')
            ->whereRaw('LOWER(email)=?', [$email])
            ->first(['full_name']);
        return $row?->full_name ? (string) $row->full_name : null;
    }

    private function prettifyResponderType(string $type): string
    {
        return match ($type) {
            'LAB'         => 'Laboratory',
            'FIELD_TEAM'  => 'Field team',
            'CLINICAL'    => 'Clinician',
            'HOSPITAL'    => 'Hospital',
            'PRESS'       => 'Press / communications',
            'COMMS'       => 'Communications',
            'LOGISTICS'   => 'Logistics',
            default       => HumanLabels::prettify($type),
        };
    }
}
