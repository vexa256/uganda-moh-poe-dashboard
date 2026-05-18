<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Coach Manifest — Alert Operations (rebuild)
|--------------------------------------------------------------------------
|
| Per-view, deterministic plain-language guidance for the six rebuilt
| Alert Operations rooms. Voice: calm, never cheerful, never alarmist.
| Audience per brief §7: an operator who has never worked in public
| health and has no technical background. Public-health vocabulary
| appears only behind "Show technical detail" disclosures.
|
| Every entry answers ten questions per brief §9:
|   q1_where_am_i      · Where am I?
|   q2_what_can_i_do   · What can I do here?
|   q3_anchor          · What does the anchor element show?
|   q4_eye_lands_first · Where does the eye land first?
|   q5_filters         · What does each filter do?
|   q6_numbers         · What does each number mean?
|   q7_good            · What does "good" look like?
|   q8_concerning      · What does "concerning" look like?
|   q9_status_updates  · What happens when state changes?
|   q10_next_view      · What to look at next.
|
| Plus a per-view "What would you like to do?" wizard inventory of
| three-to-five focused action paths per brief §9.
|
| Last revised: 2026-04-26.
*/

return [

    'common' => [
        'invocation_label'    => 'What would you like to do?',
        'tour_label'          => 'Walk me through this view',
        'drawer_heading'      => 'Reading Alert Operations',
        'pii_notice'          => 'PII follows the existing rules. National and PHEOC see full traveller details; District sees masked phone and email; POE sees only the cases anchored to its own port.',
        'reminder_notice'     => 'Reminders to recipients fire at most once every 24 hours per case. The platform does not chase the same person twice on the same day.',
        'simulation_notice'   => 'State changes show optimistically the moment you click; the server confirms within two seconds and rolls back with a plain-language note if it cannot.',
        'fallback_notice'     => 'Where a code in the data has no plain-language label yet, the raw code is shown with a small caption — the platform never invents one.',
        'close_label'         => 'Close',
    ],

    /*====================================================================
     | alert-followups — case-led units
     *====================================================================*/
    'alert-followups' => [
        'title'    => 'Follow-ups — what is left to do on each case',
        'audience' => 'Owners + collaborators on active cases.',
        'q1_where_am_i' => 'You are looking at every case where there is still work to do — a stack of case cards, ordered by urgency. Each card opens to show the steps left, who is responsible, what is blocking closure, and the next obvious thing to do.',
        'q2_what_can_i_do' => [
            'See, at a glance, every case still open in your scope and what is left to do on each.',
            'Open a card to drill into its steps — grouped by category, with blockers visually distinct.',
            'Mark a step done, mark a step blocked, or mark a step not applicable for this case.',
            'Walk through any case end-to-end via "What would you like to do?" → "Walk me through a case".',
        ],
        'q3_anchor' => 'A vertical stack of case cards. Each card is a self-contained unit with the traveller, the case category, the owner, a progress ring of done-versus-outstanding steps, the blocker count, and the time-to-close. There is no master table — the cards are the canvas.',
        'q4_eye_lands_first' => 'The first card. The cards are sorted by urgency — overdue items at the top, then time-to-close, then risk. Whatever is at the top of the stack is the most pressing thing on your plate today.',
        'q5_filters' => 'The mine-only filter narrows the stack to cases you own; the watching filter shows cases you are added to as a collaborator. The blocker filter shows only cards with at least one step that is blocking closure. Search matches the traveller and the case code.',
        'q6_numbers' => [
            'Cases on your plate — count of unique alerts in your scope with at least one open follow-up step.',
            'Steps remaining (per card) — number of follow-up rows on this alert whose status is PENDING or IN_PROGRESS.',
            'Blockers (per card) — count of those remaining steps whose blocks_closure flag is true.',
            'Time-to-close — minutes until the case\'s next-due step is overdue (negative when already overdue).',
        ],
        'q7_good' => 'A card with zero blockers, a near-complete progress ring, and a positive time-to-close — the case is on track and the next step is obvious.',
        'q8_concerning' => 'A card whose progress ring has not moved in days, a card with multiple blockers, or a card whose time-to-close is deeply negative. These are the cards that need your attention before the deadline window misses.',
        'q9_status_updates' => 'Marking a step done updates the card\'s progress ring immediately. The case-level event chronicle in alert-timeline picks up the change within seconds. If the server rejects the change, the ring rolls back and the card surfaces a plain-language note explaining why.',
        'q10_next_view' => 'A card whose deadline is approaching is best read on alert-sla; a card you want to discuss with the team belongs in alert-caseroom; the full chronicle of any case lives on alert-timeline.',

        'wizard_actions' => [
            ['key' => 'mark-step-done',  'label' => 'Mark a step done on a case I own'],
            ['key' => 'find-blockers',   'label' => 'Find everything blocking a chosen case'],
            ['key' => 'walk-a-case',     'label' => 'Walk me through a case end-to-end'],
            ['key' => 'overdue-only',    'label' => 'Show me only cases with overdue steps'],
        ],
    ],

    /*====================================================================
     | alert-sla — deadline strip + countdown grid
     *====================================================================*/
    'alert-sla' => [
        'title'    => 'Deadlines — what is on the team\'s clock',
        'audience' => 'Owners, supervisors, and PHEOC duty officers.',
        'q1_where_am_i' => 'You are looking at every active case in your scope, plotted against time. The clock is the canvas — every case is a countdown until its next deadline, and the cases that have already missed a deadline sit in a separate region with their reasons.',
        'q2_what_can_i_do' => [
            'See, at a glance, what is coming up on the team\'s clock today, this week.',
            'See, at a glance, every deadline that has already been missed and how long ago.',
            'Record why a deadline was missed using the deterministic taxonomy — required when a case has missed any of its three deadlines.',
            'Walk through the platform\'s reminder cadence so you know exactly what the platform is doing on the team\'s behalf.',
        ],
        'q3_anchor' => 'A horizontal time strip across the top of the canvas. Every case is a small countdown card placed where its next deadline falls — left of "now" if it has missed, right of "now" if it has not. Beneath the strip, two grids: missed deadlines on the left (with reasons), at-risk deadlines on the right (with reminder dispatch times).',
        'q4_eye_lands_first' => 'The countdowns sitting closest to "now" on the right of the strip — those are the deadlines about to land. Then the missed-deadlines grid on the left for cases that are already past due.',
        'q5_filters' => 'The phase filter narrows the strip to one of the three deadline phases (detect, notify, respond). The risk filter narrows by case risk. The district / PoE filters narrow geography.',
        'q6_numbers' => [
            'Cases on the clock — count of unique active alerts (status OPEN or ACKNOWLEDGED) in your scope.',
            'Missed deadlines — count of phase-deadlines that have already lapsed for those cases.',
            'At-risk — count of deadlines that have not yet missed but are within their warning band.',
            'Time to next deadline (per case) — minutes until the case\'s soonest unbreached deadline lands.',
        ],
        'q7_good' => 'A strip with most countdowns sitting comfortably to the right of "now" and a small missed-deadlines grid. The cadence is keeping up with the team.',
        'q8_concerning' => 'A strip clustering close to "now" on the right (a wave of deadlines about to land) or a missed-deadlines grid that has grown without recorded reasons.',
        'q9_status_updates' => 'When you record a missed-deadline reason, the case moves from "missed but unrecorded" to "missed with a reason on file" immediately. Acknowledging or closing a case removes it from the strip live.',
        'q10_next_view' => 'A missed deadline tells you where to look — the case\'s open follow-ups live on alert-followups; the case\'s shared workspace lives on alert-caseroom; the full event chronicle lives on alert-timeline.',

        'wizard_actions' => [
            ['key' => 'whats-coming',     'label' => 'Show me what is coming up'],
            ['key' => 'whats-missed',     'label' => 'Show me what has been missed'],
            ['key' => 'record-reason',    'label' => 'Record why a deadline was missed'],
            ['key' => 'reminder-policy',  'label' => 'Walk me through the reminder cadence'],
        ],
    ],

    /*====================================================================
     | alert-ownership — flow-of-ownership
     *====================================================================*/
    'alert-ownership' => [
        'title'    => 'Ownership — who has had each case, who holds it now',
        'audience' => 'Supervisors, PHEOC duty officers, national admins.',
        'q1_where_am_i' => 'You are looking at how cases have moved between owners and levels over time. The story reads as movement, not as a list of transfers — a flow that shows where ownership originates, where it tends to stall, and where it lands.',
        'q2_what_can_i_do' => [
            'See the flow of ownership in your scope as a single visual.',
            'See, at a glance, who currently holds which cases.',
            'See pending handoffs that have been sent but not yet accepted.',
            'Drill into any thread of the flow to see the underlying cases.',
        ],
        'q3_anchor' => 'A flow diagram — a Sankey-style band of ownership transitions from level to level (POE → district → PHEOC → national, and the reverse). Beside it, the "right now" panel: who currently holds which cases. Beneath: pending handoffs with the recipient and elapsed time.',
        'q4_eye_lands_first' => 'The largest band in the flow — that is where ownership tends to move most. Then the "right now" panel for the live picture.',
        'q5_filters' => 'The risk filter narrows to cases of one risk level. The window filter narrows to handoffs in the past 24 hours, 7 days, or 30 days.',
        'q6_numbers' => [
            'Cases in your scope — count of active and recent alerts visible to you.',
            'Currently held by you — count of cases whose current owner is the user you are signed in as.',
            'Pending handoffs — count of handoff events whose status is SENT and not yet accepted.',
            'Average time-to-accept — median minutes between a handoff being sent and accepted, computed live.',
        ],
        'q7_good' => 'A flow with thin bands moving up to the next level (escalations are the rare events) and a small pending-handoffs panel — accountability is moving.',
        'q8_concerning' => 'A thick stalled band (cases pile up at one level), a long pending-handoffs queue (acceptance is slow), or a recipient with no current capacity holding too many cases.',
        'q9_status_updates' => 'Accepting or rejecting a handoff updates the "right now" panel immediately and the flow diagram on the next refresh. Reassignment is reflected the moment the server confirms.',
        'q10_next_view' => 'A stalled band is best understood case-by-case on alert-followups; the team conversation around a stalled case lives on alert-caseroom.',

        'wizard_actions' => [
            ['key' => 'who-holds-what',  'label' => 'Show me who holds which cases right now'],
            ['key' => 'pending-handoffs','label' => 'Show me pending handoffs'],
            ['key' => 'find-stall',      'label' => 'Find where ownership tends to stall'],
        ],
    ],

    /*====================================================================
     | alert-caseroom — workspace
     *====================================================================*/
    'alert-caseroom' => [
        'title'    => 'Case Room — where the team works the case together',
        'audience' => 'Owners, collaborators, anyone working on a case.',
        'q1_where_am_i' => 'You are looking at the shared workspace for whichever case you have entered. The traveller, the people working it, the conversation, the evidence, and the open items are arranged so you can read the state of the room at a glance.',
        'q2_what_can_i_do' => [
            'Add a comment to the conversation.',
            'Add or remove a collaborator on the case.',
            'Upload evidence — a photo, a document, a lab result.',
            'Hand the case off to a different owner.',
        ],
        'q3_anchor' => 'A three-pane workspace once you have entered a case. Left: case identity and status. Centre: the conversation thread, rendered as plain-language event entries with icons. Right: the people on the case + the evidence library + outstanding items. Outside a specific case, the canvas is a triaged inbox of recent activity across rooms you are part of.',
        'q4_eye_lands_first' => 'The centre conversation pane — it tells you what just happened. Then the right pane for who is involved and what is outstanding.',
        'q5_filters' => 'When inside a case, the conversation can be filtered to comments only, system events only, or evidence-related entries only. Outside a case, the inbox can be filtered to activity in the past 24 hours, 7 days, or 30 days.',
        'q6_numbers' => [
            'Active collaborators (per case) — count of users on the collaborator list whose is_active is true.',
            'Comments (per case) — count of non-deleted comments.',
            'Evidence (per case) — count of non-deleted evidence rows.',
            'Open handoffs (per case) — count of handoffs with status SENT.',
        ],
        'q7_good' => 'An active conversation, a small evidence library that grew naturally, and the outstanding-items panel showing the team is closing things out.',
        'q8_concerning' => 'A silent conversation on a case that is supposed to be active, a single collaborator working alone on a high-risk case, or an evidence library that has grown without resolution.',
        'q9_status_updates' => 'New comments and uploads appear in the centre pane as soon as the server confirms. Collaborator changes update the right pane immediately. If a comment fails to save, it rolls back with a plain-language note.',
        'q10_next_view' => 'The case\'s outstanding work is best read on alert-followups; deadline pressure on alert-sla; the full event chronicle on alert-timeline.',

        'wizard_actions' => [
            ['key' => 'add-comment',     'label' => 'Add a comment to a case'],
            ['key' => 'upload-evidence', 'label' => 'Upload evidence to a case'],
            ['key' => 'add-collaborator','label' => 'Add or remove a collaborator'],
            ['key' => 'walk-new-case',   'label' => 'Walk me through a case I am new to'],
        ],
    ],

    /*====================================================================
     | alert-external — request-cards canvas
     *====================================================================*/
    'alert-external' => [
        'title'    => 'External requests — conversations outside the platform',
        'audience' => 'Owners + supervisors coordinating with labs, hospitals, airlines, and port operators.',
        'q1_where_am_i' => 'You are looking at every information request the platform has sent to a party outside the system — laboratories, hospitals, airlines, port operators, or anyone else. Cards are grouped by the kind of recipient. Replies that have come back but not yet been read sit in their own region.',
        'q2_what_can_i_do' => [
            'See every active external request in your scope, grouped by who it went to.',
            'See replies that came back and have not yet been acknowledged.',
            'Cancel a request that is no longer needed.',
            'Resend a request whose link has expired.',
        ],
        'q3_anchor' => 'Stacked sections — one section per recipient type (Laboratories, Hospitals, Airlines, Port Operators, Other). Inside each section, a grid of request cards. Each card surfaces what was asked, who it went to, when it was sent, when the link expires, and the reply status. A separate region above the sections surfaces unread replies.',
        'q4_eye_lands_first' => 'The unread-replies region at the top — those are the items waiting on you. Then the section for whichever recipient type you most often coordinate with.',
        'q5_filters' => 'The status filter narrows to sent / received / expired / cancelled. The recipient-type filter narrows to one section. Search matches the request subject and the case code.',
        'q6_numbers' => [
            'Active requests (per section) — count of responder_info_requests rows whose status is SENT and whose alert is in your scope.',
            'Unread replies — count of requests whose status is RECEIVED and whose responded_at is more recent than the last time you opened the view.',
            'Expired (per section) — requests whose expires_at is in the past and were not responded to.',
            'Resend count (per card) — number of times this request has been resent.',
        ],
        'q7_good' => 'An unread-replies region that does not grow without being acknowledged, and a small expired-requests count.',
        'q8_concerning' => 'An unread-replies region that grows day over day, a section dominated by expired requests, or a recipient with a high resend count and no reply.',
        'q9_status_updates' => 'Cancelling a request moves the card to the cancelled state immediately. Resending mints a fresh link, cancels the old one, and shows the new expiry on the card. Replies that arrive while the view is open appear in the unread-replies region within seconds.',
        'q10_next_view' => 'A reply that resolves a question is best recorded on alert-caseroom as evidence; a request that has missed its deadline lives on alert-sla; the full chronicle of the conversation lives on alert-timeline.',

        'wizard_actions' => [
            ['key' => 'send-request',    'label' => 'Send a request to a specific recipient type'],
            ['key' => 'cancel-request',  'label' => 'Cancel a request'],
            ['key' => 'review-replies',  'label' => 'Review replies that have not been read'],
        ],
    ],

    /*====================================================================
     | alert-timeline — chronicle anchor
     *====================================================================*/
    'alert-timeline' => [
        'title'    => 'Case History — the complete record, in order',
        'audience' => 'Anyone who needs the story of a case — owners, supervisors, auditors.',
        'q1_where_am_i' => 'You are looking at the full chronicle of any case — every event in order, told as plain-language entries with icons. Outside a specific case, the canvas is a recent-activity feed scoped to your geography, plus a search box for finding specific events.',
        'q2_what_can_i_do' => [
            'See the complete record of any case in order.',
            'See recent activity on any case in your scope.',
            'Search events by case, traveller, event type, or date.',
        ],
        'q3_anchor' => 'A vertical event spine — once you enter a case, every event becomes an entry on the spine, top to bottom in chronological order. Each entry has an icon, a one-line plain-language summary, the actor, and the time. Outside a case, the canvas is a recent-activity feed with the same entry shape.',
        'q4_eye_lands_first' => 'The most recent entry at the top of the spine. Then the icons for repeating event types — they tell you the rhythm of the case.',
        'q5_filters' => 'The category filter narrows to one of the event categories — system, human, email, workflow, breach, clinical. The window filter narrows to events in the past 24 hours, 7 days, 30 days, or all time. The actor filter narrows to events that one user fired.',
        'q6_numbers' => [
            'Events on this case — count of alert_timeline_events rows for the case in scope.',
            'Events today — count of events with created_at in the last 24 hours.',
            'Events this week — count of events with created_at in the last 7 days.',
            'Distinct event categories — distinct count of event_category values present in the chronicle.',
        ],
        'q7_good' => 'A spine where each entry follows the previous in a sensible order — a case that was opened, acknowledged, worked, and closed. A clean rhythm.',
        'q8_concerning' => 'A spine with long silences punctuated by escalations, or a spine with many breach entries clustered together — the case stalled and the system noticed.',
        'q9_status_updates' => 'Every state change on every other view writes to this chronicle within seconds. New entries appear at the top of the spine live.',
        'q10_next_view' => 'A breach entry leads to alert-sla; a comment or evidence entry leads to alert-caseroom; an open follow-up referenced in an entry leads to alert-followups.',

        'wizard_actions' => [
            ['key' => 'open-case-history','label' => 'See everything that has happened on a chosen case'],
            ['key' => 'find-event',       'label' => 'Find a specific event from a recent period'],
            ['key' => 'walk-recent',      'label' => 'Walk me through recent activity on my own cases'],
        ],
    ],

];
