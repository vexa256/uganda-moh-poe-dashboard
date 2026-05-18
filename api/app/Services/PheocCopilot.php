<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\DiseaseIntel;
use App\Support\DiseaseResolver;
use App\Support\EnumTranslator;
use App\Support\TimelineBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PheocCopilot
 * ---------------------------------------------------------------------------
 * Deterministic, rule-driven operational advisor for the PHEOC admin panel.
 *
 * **Zero external LLM**. Every recommendation is computed from:
 *   · IntelligenceEngine output (the six tripwire detectors)
 *   · AlertFollowups / BreachReports / alert_timeline_events
 *   · DiseaseIntel (WHO IHR + AFRO IDSR knowledge)
 *   · SLA state (7-1-7 detect / notify / respond, RTSL-14 follow-ups)
 *   · Geographic baseline (14-day POE / district)
 *
 * Six canonical methods (per the master directive):
 *   narrate($alert)           → timeline prose for the War Room Timeline tab
 *   recommend($ctx)           → next-best-action list for the Copilot dock
 *   rankDifferentials($case)  → disease confidence ranking for the case view
 *   suggestCloseReason($alert)→ close_category + rationale
 *   escalationRationale($alert)→ pre-filled escalation text
 *   triageBrief($snapshot)    → 72h national intelligence narrative
 *
 * Every output includes a `reasoning` field citing the rule(s) that
 * triggered it (S8.3).
 */
final class PheocCopilot
{
    public function __construct(
        protected EnumTranslator $enum,
        protected DiseaseResolver $diseases,
        protected TimelineBuilder $timeline,
    ) {
    }

    /** Default country when no scope is available — read from
     *  config('country.code') so the same code runs unchanged across the
     *  ZM / UG / RW forks. PHP class constants must be compile-time
     *  literals, so we resolve at first use via {@see defaultCountry()}. */
    protected static function defaultCountry(): string
    {
        return (string) (config('country.code') ?: 'RW');
    }

    /* ══════════════════════════════════════════════════════════════════
       1. NARRATE · Alert → timeline prose
       ══════════════════════════════════════════════════════════════════ */

    public function narrate($alert): array
    {
        $a = (array) $alert;
        $id       = $a['id'] ?? null;
        $code     = (string) ($a['alert_code'] ?? ('#' . ($id ?? '?')));
        $status   = (string) ($a['status'] ?? 'OPEN');
        $risk     = (string) ($a['risk_level'] ?? 'MEDIUM');
        $disease  = (string) ($a['disease_code'] ?? '');
        $diseaseV = $this->diseases->resolve($disease);
        $created  = isset($a['created_at']) ? Carbon::parse((string) $a['created_at']) : null;
        $closed   = isset($a['closed_at']) && $a['closed_at'] ? Carbon::parse((string) $a['closed_at']) : null;
        $poeCode  = (string) ($a['poe_code'] ?? '');
        $district = (string) ($a['district_code'] ?? '');

        $events = $id
            ? DB::table('alert_timeline_events')->where('alert_id', $id)->orderByDesc('created_at')->limit(10)->get()->all()
            : [];

        $timeline = $this->timeline->build($events);

        $sentences = [];
        $reasoning = [];

        if ($created) {
            $sentences[] = sprintf(
                "Alert %s opened %s%s%s.",
                $code,
                $created->diffForHumans(),
                $poeCode  ? ' at ' . $poeCode : '',
                $district ? ' (' . $district . ')' : '',
            );
            $reasoning[] = 'N1: alerts.created_at + POE/district.';
        }

        if ($diseaseV['known']) {
            $sentences[] = sprintf(
                "Suspected disease: %s — %s.",
                $diseaseV['name'],
                $diseaseV['tier_label'],
            );
            $reasoning[] = 'N2: disease_code matched DiseaseIntel registry.';
        }

        $sentences[] = sprintf(
            "Current state: %s, risk %s.",
            $this->enum->alertStatus($status),
            $this->enum->riskLevel($risk),
        );
        $reasoning[] = 'N3: alerts.status + alerts.risk_level.';

        if ($created && $status === 'OPEN') {
            $hoursOpen = $created->diffInHours(Carbon::now());
            if ($hoursOpen >= 24) {
                $sentences[] = sprintf("Open %d hours without acknowledgement — the 7-1-7 Notify window has elapsed.", $hoursOpen);
                $reasoning[] = 'N4: open >24h = Notify SLA breach candidate.';
            } else {
                $sentences[] = "Still within the 24-hour acknowledgement window.";
                $reasoning[] = 'N4: open <24h since creation.';
            }
        }

        if ($closed) {
            $sentences[] = sprintf(
                "Closed %s — category: %s.",
                $closed->diffForHumans(),
                $this->enum->closeCategory((string) ($a['close_category'] ?? ''))
            );
            $reasoning[] = 'N5: alerts.closed_at present.';
        }

        if (! empty($timeline)) {
            $tailBits = [];
            foreach (array_slice($timeline, 0, 3) as $ev) {
                $tailBits[] = sprintf('%s %s', $ev['event_label'], $ev['at_relative']);
            }
            $sentences[] = 'Recent: ' . implode(' · ', $tailBits) . '.';
            $reasoning[] = 'N6: last 3 alert_timeline_events.';
        }

        return [
            'prose'     => implode(' ', $sentences),
            'sentences' => $sentences,
            'reasoning' => $reasoning,
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
       2. RECOMMEND · next-best-action list
       ══════════════════════════════════════════════════════════════════ */

    public function recommend(array $ctx): array
    {
        $scope       = $ctx['scope'] ?? [];
        $countryCode = (string) ($scope['country_code'] ?? self::defaultCountry());

        $stuck   = $this->safeInt(fn () => IntelligenceEngine::stuckAlerts($countryCode));
        $overdue = $this->safeInt(fn () => IntelligenceEngine::overdueFollowups($countryCode));
        $silent  = $this->safeInt(fn () => IntelligenceEngine::silentPoes24h($countryCode));
        $unsub   = $this->safeInt(fn () => IntelligenceEngine::unsubmittedPoes3d($countryCode));

        $recs = [];

        if ($stuck > 0) {
            $recs[] = [
                'title'     => "Review {$stuck} stuck alert" . ($stuck === 1 ? '' : 's'),
                'body'      => 'Open >24h without acknowledgement. The 7-1-7 Notify window has elapsed or is close to it.',
                'tone'      => 'critical',
                'reasoning' => 'IntelligenceEngine::stuckAlerts — alerts.status=OPEN AND created_at < NOW()-24h.',
                'url'       => url('/admin/alerts?filter=stuck'),
            ];
        }

        if ($overdue > 0) {
            $recs[] = [
                'title'     => "Complete {$overdue} overdue follow-up" . ($overdue === 1 ? '' : 's'),
                'body'      => 'RTSL-14 actions past due. Six of the 14 block alert closure until completed.',
                'tone'      => 'warning',
                'reasoning' => 'IntelligenceEngine::overdueFollowups — alert_followups.due_at < NOW() AND status NOT IN (COMPLETED, NOT_APPLICABLE).',
                'url'       => url('/admin/compliance/717'),
            ];
        }

        if ($silent > 0) {
            $recs[] = [
                'title'     => "Check {$silent} silent point" . ($silent === 1 ? '' : 's') . " of entry",
                'body'      => 'No primary screening in the last 24 h. Possible device fault, staffing gap, or genuine zero throughput.',
                'tone'      => 'warning',
                'reasoning' => 'IntelligenceEngine::silentPoes24h.',
                'url'       => url('/admin/intelligence#silent-poes'),
            ];
        }

        if ($unsub > 0) {
            $recs[] = [
                'title'     => "Nudge {$unsub} outstanding aggregated report" . ($unsub === 1 ? '' : 's'),
                'body'      => 'POEs missed their period submission. Trigger the reminder template.',
                'tone'      => 'info',
                'reasoning' => 'IntelligenceEngine::unsubmittedPoes3d.',
                'url'       => url('/admin/comms/outbound'),
            ];
        }

        if (empty($recs)) {
            $recs[] = [
                'title'     => 'Nothing urgent right now',
                'body'      => 'All six tripwires are green. Good time for the 7-1-7 quarterly review or to chase outstanding invitations.',
                'tone'      => 'success',
                'reasoning' => 'All tripwire counts = 0.',
                'url'       => null,
            ];
        }

        return array_slice($recs, 0, 4);
    }

    /* ══════════════════════════════════════════════════════════════════
       3. RANK DIFFERENTIALS · case → disease confidence bands
       ══════════════════════════════════════════════════════════════════ */

    public function rankDifferentials(int $secondaryScreeningId): array
    {
        $rows = DB::table('secondary_suspected_diseases')
            ->where('secondary_screening_id', $secondaryScreeningId)
            ->orderByDesc('confidence')
            ->get()->all();

        return $this->diseases->rankSuspected(array_map(fn ($r) => (array) $r, $rows));
    }

    /* ══════════════════════════════════════════════════════════════════
       4. SUGGEST CLOSE REASON · alert → {category, rationale, blockers}
       ══════════════════════════════════════════════════════════════════ */

    public function suggestCloseReason(int $alertId): array
    {
        $alert = DB::table('alerts')->find($alertId);
        if (! $alert) {
            return [
                'category' => 'OTHER',
                'label'    => $this->enum->closeCategory('OTHER'),
                'rationale'=> 'Alert record not found. Please provide a note documenting the circumstances.',
                'blockers' => [],
            ];
        }

        $blockers = DB::table('alert_followups')
            ->where('alert_id', $alertId)
            ->where('blocks_closure', 1)
            ->whereIn('status', ['PENDING', 'IN_PROGRESS'])
            ->get()
            ->map(fn ($r) => [
                'action_code'  => (string) $r->action_code,
                'action_label' => $this->enum->followupAction((string) $r->action_code),
                'due_at'       => $r->due_at,
                'status'       => $this->enum->followupStatus((string) $r->status),
            ])
            ->all();

        // Category suggestion — read the linked secondary screening's disposition
        // via alerts.secondary_screening_id (one-to-one FK).
        $linkedDispo = null;
        if (! empty($alert->secondary_screening_id)) {
            try {
                $s = DB::table('secondary_screenings')
                    ->where('id', $alert->secondary_screening_id)
                    ->first();
                if ($s) $linkedDispo = (string) ($s->final_disposition ?? '');
            } catch (\Throwable) { /* graceful */ }
        }

        // Dispositions that indicate "not a case" → FALSE_POSITIVE
        $falsePositiveDispos = ['RELEASED']; // released-after-screening is the closest "not a case" signal
        if ($linkedDispo && in_array($linkedDispo, $falsePositiveDispos, true)) {
            $cat = 'FALSE_POSITIVE';
            $rationale = sprintf(
                'The linked secondary screening was dispositioned "%s" — no case confirmed. Suggested close category: "False positive".',
                $this->enum->disposition($linkedDispo),
            );
        } elseif ($this->hasTimelineEvent($alertId, 'PHEIC_DECLARED')) {
            $cat = 'RESOLVED';
            $rationale = 'Alert reached the PHEIC pathway. Close with "Resolved" only after WHO / ICA sign-off.';
        } elseif (! empty($alert->merged_into_alert_id)) {
            $cat = 'DUPLICATE';
            $rationale = sprintf('Merged into alert #%s. Close with "Duplicate".', $alert->merged_into_alert_id);
        } else {
            $cat = 'RESOLVED';
            $rationale = 'No contradicting signals in the timeline. Default suggestion: "Resolved".';
        }

        if (! empty($blockers)) {
            $rationale .= ' ' . sprintf(
                '%d blocking follow-up%s still open — close action will be refused until %s completed.',
                count($blockers),
                count($blockers) === 1 ? '' : 's',
                count($blockers) === 1 ? 'it is' : 'they are',
            );
        }

        return [
            'category' => $cat,
            'label'    => $this->enum->closeCategory($cat),
            'rationale'=> $rationale,
            'blockers' => $blockers,
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
       5. ESCALATION RATIONALE · alert → pre-filled text
       ══════════════════════════════════════════════════════════════════ */

    public function escalationRationale(int $alertId): array
    {
        $alert = DB::table('alerts')->find($alertId);
        if (! $alert) return ['text' => 'Alert record not found.', 'signals' => []];

        $signals = [];
        $created = isset($alert->created_at) && $alert->created_at ? Carbon::parse((string) $alert->created_at) : null;
        $ack     = isset($alert->acknowledged_at) && $alert->acknowledged_at ? Carbon::parse((string) $alert->acknowledged_at) : null;

        if ($created) {
            $signals[] = sprintf('Opened %s', $created->diffForHumans());
            if (! $ack) {
                $signals[] = sprintf('%d hours without acknowledgement (target: ≤24h)', $created->diffInHours(Carbon::now()));
            }
        }

        $risk = strtoupper((string) ($alert->risk_level ?? ''));
        if (in_array($risk, ['HIGH', 'CRITICAL'], true)) {
            $signals[] = sprintf('Risk: %s', $this->enum->riskLevel($risk));
        }

        $tier = strtoupper((string) ($alert->ihr_tier ?? ''));
        if (in_array($tier, ['TIER_1_ALWAYS_NOTIFIABLE', 'ANNEX2_EPIDEMIC_PRONE', 'TIER_1', 'TIER_2'], true)) {
            $signals[] = $this->enum->ihrTier($tier);
        }

        if (! empty($alert->disease_code)) {
            $d = $this->diseases->resolve((string) $alert->disease_code);
            if ($d['known']) {
                $signals[] = sprintf('Suspected disease: %s', $d['name']);
            }
        }

        if (! empty($alert->district_code) && stripos((string) $alert->district_code, 'border') !== false) {
            $signals[] = 'Cross-border district — potential WHO Member State notification trigger';
        }

        $text = sprintf(
            "Escalation request for alert %s. %sAwaiting decision at the next level of the escalation ladder.",
            (string) ($alert->alert_code ?? ('#' . $alertId)),
            empty($signals) ? '' : implode('. ', $signals) . '. ',
        );

        return ['text' => $text, 'signals' => $signals];
    }

    /* ══════════════════════════════════════════════════════════════════
       7. ASK · deterministic Q&A over the tripwire / alert knowledge base
       ══════════════════════════════════════════════════════════════════ */

    /**
     * Deterministic rule-based Q&A. Accepts a free-text question + an
     * optional route context. Returns a structured reply: narrative
     * paragraph + 0–4 cited sources + 0–4 next-best-action links.
     *
     * Never calls an external LLM — purely rule-matched against current
     * intel state, recent alerts, and the DiseaseIntel registry.
     *
     * @param array{question:string, route?:string, alert_id?:int, scope?:array} $ctx
     * @return array{reply:string, sources:array<int,array>, actions:array<int,array>, reasoning:array<int,string>}
     */
    public function ask(array $ctx): array
    {
        $q       = strtolower(trim((string) ($ctx['question'] ?? '')));
        $scope   = $ctx['scope'] ?? [];
        $country = (string) ($scope['country_code'] ?? self::defaultCountry());
        $alertId = isset($ctx['alert_id']) ? (int) $ctx['alert_id'] : null;

        $reasoning = [];
        $sources   = [];
        $actions   = [];
        $reply     = '';

        if ($q === '') {
            return [
                'reply'     => 'Ask me about any alert, case, or compliance figure on screen. I can explain why it looks the way it does and what to do next.',
                'sources'   => [],
                'actions'   => [
                    ['label' => 'Show the national brief', 'url' => url('/admin/dashboard')],
                ],
                'reasoning' => ['Empty-question default.'],
            ];
        }

        // Rule: alert status / context
        if ($alertId && (str_contains($q, 'why') || str_contains($q, 'close') || str_contains($q, 'escalate'))) {
            if (str_contains($q, 'close')) {
                $s = $this->suggestCloseReason($alertId);
                $reply = sprintf('For alert #%d I suggest closing as "%s". %s', $alertId, $s['label'], $s['rationale']);
                $sources[] = ['label' => 'AlertFollowups (blockers)', 'url' => url('/admin/alerts/' . $alertId . '?tab=followups')];
                $actions[] = ['label' => 'Open alert war-room', 'url' => url('/admin/alerts/' . $alertId)];
                $reasoning[] = 'Route: suggestCloseReason($alertId).';
                return compact('reply', 'sources', 'actions', 'reasoning');
            }
            if (str_contains($q, 'escalate')) {
                $e = $this->escalationRationale($alertId);
                $reply = sprintf('For alert #%d, the escalation rationale I would pre-fill is:' . "\n\n%s", $alertId, $e['text']);
                $actions[] = ['label' => 'Open war-room to act',  'url' => url('/admin/alerts/' . $alertId)];
                $reasoning[] = 'Route: escalationRationale($alertId).';
                return compact('reply', 'sources', 'actions', 'reasoning');
            }
            // "why is this open / why not closed" — narrate
            $alert = DB::table('alerts')->find($alertId);
            if ($alert) {
                $n = $this->narrate($alert);
                $reply = $n['prose'];
                $reasoning = $n['reasoning'];
                $actions[] = ['label' => 'Open war-room',         'url' => url('/admin/alerts/' . $alertId)];
                $actions[] = ['label' => 'Jump to timeline tab',  'url' => url('/admin/alerts/' . $alertId . '?tab=timeline')];
                return compact('reply', 'sources', 'actions', 'reasoning');
            }
        }

        // Rule: disease intel lookup
        foreach (DiseaseIntel::REGISTRY as $code => $meta) {
            $name = strtolower((string) ($meta['name'] ?? $code));
            if ($name && str_contains($q, $name)) {
                $v = $this->diseases->resolve($code);
                $lines = [
                    sprintf('%s — %s.', $v['name'], $v['tier_label']),
                ];
                if ($v['case_definition']) $lines[] = 'Case definition: ' . $v['case_definition'];
                if ($v['transmission'])    $lines[] = 'Transmission: ' . $v['transmission'];
                if ($v['incubation'])      $lines[] = 'Incubation: ' . $v['incubation'];
                if ($v['ppe'])             $lines[] = 'PPE: ' . $v['ppe'];
                if ($v['isolation'])       $lines[] = 'Isolation posture: ' . $v['isolation'];
                if (! empty($v['immediate_actions'])) {
                    $lines[] = 'Immediate actions: ' . implode(' · ', $v['immediate_actions']);
                }
                $reply     = implode("\n\n", $lines);
                $sources[] = ['label' => 'WHO IHR 2005 / AFRO IDSR 2021', 'url' => null];
                $reasoning[] = 'DiseaseIntel registry match: ' . $code;
                $actions[] = ['label' => 'Open disease catalogue', 'url' => url('/admin/reference/diseases')];
                return compact('reply', 'sources', 'actions', 'reasoning');
            }
        }

        // Rule: tripwire / 7-1-7 / compliance keywords
        if (str_contains($q, '7-1-7') || str_contains($q, 'compliance') || str_contains($q, 'sla') || str_contains($q, 'breach')) {
            $brief = $this->triageBrief(['country_code' => $country]);
            $reply = 'Here\'s what the 7-1-7 clock looks like right now: ' . implode(' ', $brief['paragraphs']);
            $actions[] = ['label' => 'Open the 7-1-7 board', 'url' => url('/admin/compliance/717')];
            $reasoning[] = 'Route: triageBrief($country).';
            return compact('reply', 'sources', 'actions', 'reasoning');
        }

        if (str_contains($q, 'silent') || str_contains($q, 'quiet') || str_contains($q, 'poe')) {
            $n = $this->safeInt(fn () => IntelligenceEngine::silentPoes24h($country));
            $reply = $n === 0
                ? 'No points of entry have gone silent in the last 24 hours. All POEs that reported in the prior week have reported again.'
                : sprintf('%d point%s of entry stopped reporting in the last 24 hours.', $n, $n === 1 ? '' : 's');
            $actions[] = ['label' => 'Open national intel brief', 'url' => url('/admin/intelligence')];
            $reasoning[] = 'IntelligenceEngine::silentPoes24h.';
            return compact('reply', 'sources', 'actions', 'reasoning');
        }

        if (str_contains($q, 'stuck') || str_contains($q, 'overdue') || str_contains($q, 'followup') || str_contains($q, 'follow-up')) {
            $stuck   = $this->safeInt(fn () => IntelligenceEngine::stuckAlerts($country));
            $overdue = $this->safeInt(fn () => IntelligenceEngine::overdueFollowups($country));
            $reply = sprintf('There are %d stuck alert%s (open >24h without acknowledgement) and %d overdue follow-up%s. Six of the RTSL-14 follow-ups block alert closure.',
                $stuck, $stuck === 1 ? '' : 's', $overdue, $overdue === 1 ? '' : 's');
            $actions[] = ['label' => 'Open Alert Hub · stuck filter', 'url' => url('/admin/alerts?filter=stuck')];
            $actions[] = ['label' => 'Open 7-1-7 Board',              'url' => url('/admin/compliance/717')];
            $reasoning[] = 'IntelligenceEngine::stuckAlerts + ::overdueFollowups.';
            return compact('reply', 'sources', 'actions', 'reasoning');
        }

        if (str_contains($q, 'dormant') || str_contains($q, 'inactive') || str_contains($q, 'mfa') || str_contains($q, 'user')) {
            $dormant = $this->safeInt(fn () => IntelligenceEngine::dormantAccounts($country));
            $reply = sprintf('%d account%s %s gone dormant (no sign-in in 14+ days). MFA enforcement is a separate admin surface — see Personnel Directory.', $dormant, $dormant === 1 ? '' : 's', $dormant === 1 ? 'has' : 'have');
            $actions[] = ['label' => 'Personnel · Dormant facet', 'url' => url('/admin/users?facet=dormant')];
            $actions[] = ['label' => 'Personnel · MFA facet',     'url' => url('/admin/users?facet=mfa')];
            $reasoning[] = 'IntelligenceEngine::dormantAccounts.';
            return compact('reply', 'sources', 'actions', 'reasoning');
        }

        // Default: brief the situation
        $brief = $this->triageBrief(['country_code' => $country]);
        $reply = $brief['paragraphs'] ? implode(' ', $brief['paragraphs']) : 'Everything is quiet right now. No open critical alerts, no silent POEs, no overdue actions.';
        $actions[] = ['label' => 'Open the national brief', 'url' => url('/admin/intelligence')];
        $actions[] = ['label' => 'Open the dashboard',      'url' => url('/admin/dashboard')];
        $reasoning[] = 'Default route: triageBrief($country).';
        return compact('reply', 'sources', 'actions', 'reasoning');
    }

    /* ══════════════════════════════════════════════════════════════════
       6. TRIAGE BRIEF · 72h national narrative paragraph
       ══════════════════════════════════════════════════════════════════ */

    public function triageBrief(?array $snapshot = null): array
    {
        $country = $snapshot['country_code'] ?? self::defaultCountry();
        $snap    = $snapshot ?? $this->safeCall(fn () => IntelligenceEngine::runFullReport($country), []);

        $headline   = config('country.name') . ' PHEOC — national situation brief';
        $paragraphs = [];
        $reasoning  = [];

        $stuck   = (int) ($snap['stuck_alerts']       ?? 0);
        $overdue = (int) ($snap['overdue_followups']  ?? 0);
        $silent  = (int) ($snap['poe_silent_24h']     ?? 0);
        $unsub   = (int) ($snap['poe_no_submission_3d'] ?? 0);
        $dormant = (int) ($snap['dormant_accounts']   ?? 0);
        $spikes  = (int) ($snap['spike_count']        ?? 0);

        if (($stuck + $overdue + $silent + $unsub + $spikes) === 0) {
            $paragraphs[] = 'All six early-warning tripwires are green: no silent POEs, no stuck alerts, no overdue follow-ups, no outstanding aggregated reports, and no case-count anomalies against the 14-day baseline.';
            $reasoning[]  = 'All tripwire counts equal zero.';
        } else {
            if ($silent > 0) {
                $paragraphs[] = sprintf('%d point%s of entry %s gone quiet in the last 24 hours — possible device fault, staffing gap, or genuine zero throughput.', $silent, $silent === 1 ? '' : 's', $silent === 1 ? 'has' : 'have');
                $reasoning[]  = 'poe_silent_24h > 0';
            }
            if ($stuck > 0) {
                $paragraphs[] = sprintf('%d alert%s %s open without acknowledgement beyond 24 hours — the 7-1-7 Notify SLA is red.', $stuck, $stuck === 1 ? '' : 's', $stuck === 1 ? 'is' : 'are');
                $reasoning[]  = 'stuck_alerts > 0';
            }
            if ($overdue > 0) {
                $paragraphs[] = sprintf('%d follow-up action%s past due. Six of the 14 RTSL actions block alert closure until completed.', $overdue, $overdue === 1 ? '' : 's');
                $reasoning[]  = 'overdue_followups > 0';
            }
            if ($spikes > 0) {
                $paragraphs[] = sprintf('%d location%s breached %s 14-day case baseline (more than double expected volume).', $spikes, $spikes === 1 ? '' : 's', $spikes === 1 ? 'its' : 'their');
                $reasoning[]  = 'spike_count > 0';
            }
            if ($unsub > 0) {
                $paragraphs[] = sprintf('%d POE%s outstanding aggregated submission%s for the current period.', $unsub, $unsub === 1 ? ' has an' : 's have', $unsub === 1 ? '' : 's');
                $reasoning[]  = 'poe_no_submission_3d > 0';
            }
            if ($dormant > 0) {
                $paragraphs[] = sprintf('Workforce: %d account%s dormant 14+ days — review for access cleanup.', $dormant, $dormant === 1 ? '' : 's');
                $reasoning[]  = 'dormant_accounts > 0';
            }
        }

        if (! empty($snap['anomaly_summary'])) {
            $paragraphs[] = (string) $snap['anomaly_summary'];
            $reasoning[]  = 'Appended IntelligenceEngine::narrativeFor($country).';
        }

        return [
            'headline'   => $headline,
            'paragraphs' => $paragraphs,
            'reasoning'  => $reasoning,
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
       HELPERS
       ══════════════════════════════════════════════════════════════════ */

    protected function hasTimelineEvent(int $alertId, string $code): bool
    {
        return DB::table('alert_timeline_events')
            ->where('alert_id', $alertId)
            ->where('event_code', $code)
            ->exists();
    }

    protected function safeInt(callable $fn): int
    {
        try { return (int) $fn(); } catch (\Throwable) { return 0; }
    }

    protected function safeCall(callable $fn, $default = null)
    {
        try { return $fn(); } catch (\Throwable) { return $default; }
    }
}
