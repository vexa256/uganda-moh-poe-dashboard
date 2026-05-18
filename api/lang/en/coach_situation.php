<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Coach Manifest — Situation Room (single-screen executive cockpit)
|--------------------------------------------------------------------------
|
| Voice: calm, executive-appropriate, never cheerful, never alarmist.
| Written for Permanent Secretaries, Directors of Public Health,
| IHR Focal Points, PHEOC Officers, and District Supervisors — most of
| whom are NOT technologists. Every acronym is expanded on first use.
|
| Section keys:
|   view         → the screen's identity (greeting copy, scope wording)
|   master_tour  → "Walk me through this room" — the brand-new-user tour
|   charts       → per-chart explainer wizards (seven points each)
|   glossary     → every concept that has a name on the screen
|
| Strings live here. The Blade view never hardcodes coach copy.
|
*/

return [

    /*--------------------------------------------------------------------
     | View identity & greeting copy
     *--------------------------------------------------------------------*/
    'view' => [
        'id'            => 'situation.room',
        'title'         => 'Situation Room',
        'header_intro'  => 'The single screen that tells you whether the country is okay right now, what is most urgent on your desk today, and where to look if you want to go deeper.',
        'three_jobs' => [
            'Tell you whether the country is okay right now, in one glance.',
            'Tell you what is most urgent on your desk today, with the obvious next action.',
            'Let you go deeper on anything you see, through charts that explain themselves.',
        ],
        'audience'      => 'Permanent Secretaries, Directors of Public Health, IHR Focal Points, PHEOC Officers, District Supervisors, donor missions, WHO Country Office staff, and Ministers’ offices.',
        'prerequisites' => [
            'You are signed in. The room you see reflects what you are allowed to see.',
            'You have ten seconds. The headline figures answer the urgent questions first.',
        ],
        // Words that change with scope. The view substitutes these at render time.
        'scope_wording' => [
            'NATIONAL' => 'Uganda',
            'PHEOC'    => 'your PHEOC',
            'DISTRICT' => 'your district',
            'POE'      => 'your point of entry',
            'OBSERVER' => 'the aggregates available to your role',
        ],
        // Greetings keyed by hour-of-day band; the view picks one.
        'greetings' => [
            'morning'   => 'Good morning',
            'afternoon' => 'Good afternoon',
            'evening'   => 'Good evening',
            'night'     => 'Good evening',
        ],
    ],

    /*--------------------------------------------------------------------
     | Master tour — "Walk me through this room"
     |
     | Numbered steps the wizard walks a brand-new executive through.
     | Voice: gentle, generous, never patronising. Each step explains
     | one region in plain language with the brand-new-user assumption.
     *--------------------------------------------------------------------*/
    'master_tour' => [
        'title'        => 'Walk me through this room',
        'intro'        => 'You will not need anyone to explain this screen to you again. Five minutes; ten short stops. At the end you will know what every chart means, what to look for, and where to go next.',
        'closing'      => 'You can re-open this tour any time from the header. The summary you just read can be saved as a single page if you want to share it with a colleague.',
        'steps' => [
            [
                'n'        => 1,
                'title'    => 'Where you are',
                'body'     => 'The header tells you your name, your role, and the part of the country you are looking at. The freshness stamp tells you when the screen last refreshed. If the data is more than a few minutes old, that is the first thing the screen tells you — so you know whether you are looking at a live picture or a paused one.',
                'callout'  => 'If the room name does not match what you expected, ask your administrator about your access. The room reflects exactly what you are allowed to see.',
            ],
            [
                'n'        => 2,
                'title'    => 'Is the country okay right now',
                'body'     => 'The headline strip near the top is the one-glance answer. Each tile carries a number, a small line showing the recent direction, and a short caption naming what the number is and what counts as good. Together, the tiles answer "are we okay?" in three seconds.',
                'callout'  => 'A red tile is not, on its own, a crisis — read the caption beneath it. It tells you whether the number is unusual or expected.',
            ],
            [
                'n'        => 3,
                'title'    => 'Are we meeting our deadlines',
                'body'     => 'The 7-1-7 rings show whether the country is meeting the international standard for outbreak response — find the signal in 7 days, notify the world in 1 day, respond on the ground in 7 days. Three rings, three percentages. Each ring fills toward 100% as the country meets that part of the standard.',
                'callout'  => 'A ring below 80% deserves a conversation with the PHEOC officer responsible for the lagging stage.',
            ],
            [
                'n'        => 4,
                'title'    => 'Where is the trouble',
                'body'     => 'The points-of-entry chart tells you which border crossings, airports, or ports are showing activity, and which are silent. Silence at a busy crossing usually means the screening team has stopped capturing — not that travellers have stopped arriving. Tap any point on the chart to see the full station picture.',
                'callout'  => 'A silent station for more than a day is worth a phone call. The screen flags the station; you ask why.',
            ],
            [
                'n'        => 5,
                'title'    => 'What changed in the last two weeks',
                'body'     => 'The alert pulse is a small line showing how many alerts the country has raised over the last fourteen days. A flat line is the baseline; a spike is unusual; a quiet patch deserves a check that nobody has stopped reporting. Markers on the line note tripwires the system fired automatically.',
                'callout'  => 'Tripwires are simple, deterministic rules — the same situation will trigger the same marker tomorrow. The rules are listed in the wizard for that chart.',
            ],
            [
                'n'        => 6,
                'title'    => 'What is on your desk today',
                'body'     => 'The Copilot brief is a list — usually three to five items — of the most useful actions to take today, computed by deterministic rules from current data. Each item names the case, the action, and why this is on the list. Tap any item to go straight to the right view to act.',
                'callout'  => 'The Copilot does not act on your behalf. It surfaces what is worth doing. You decide.',
            ],
            [
                'n'        => 7,
                'title'    => 'Are we sending too many messages',
                'body'     => 'The platform commits to send at most one reminder per case per recipient per day. The dispatch sanity card shows today’s reminder count against that ceiling. It is here so you can see the rule being kept — and so the people whose phones used to flood with reminders trust that the platform respects them.',
                'callout'  => null,
            ],
            [
                'n'        => 8,
                'title'    => 'How to read a chart you do not understand',
                'body'     => 'Every chart on this screen has a small information button. Tapping it opens a focused walk-through of just that chart — what the numbers are, where the data comes from, what good looks like, what concerning looks like, and where to go if you want to look closer. You will never need to ask anyone what a chart on this screen means.',
                'callout'  => null,
            ],
            [
                'n'        => 9,
                'title'    => 'How the room changes when something new happens',
                'body'     => 'A new alert opening or a tripwire firing while you are looking at the screen does not jump the layout around — the change appears with a small visual cue, the headline figure ticks up, the alert appears at the top of the feed, and the freshness stamp updates. You will always notice; you will never be jarred.',
                'callout'  => 'If your network drops, the freshness stamp ages and the screen tells you so. Nothing on screen pretends to be live when it is not.',
            ],
            [
                'n'        => 10,
                'title'    => 'Where to go next',
                'body'     => 'Anything you see on this screen is a doorway. The points-of-entry chart leads to the full stations register; the alert feed leads to each case’s dossier; the Copilot brief leads to the right form to act. You can always come back to this room — every other view in the platform has a way back to it.',
                'callout'  => 'If you are about to brief a Minister or call WHO, the print-this-room option in the header gives you a clean one-page picture of exactly what is on screen, with timestamp.',
            ],
        ],
    ],

    /*--------------------------------------------------------------------
     | Per-chart explainer wizards
     |
     | Seven points each per brief §6.2:
     |   showing  · how_to_read · good_looks_like · concerning_looks_like
     |   more_charts · what_to_do · what_it_cannot_tell_you · learn_more
     *--------------------------------------------------------------------*/
    'charts' => [

        'rings_717' => [
            'title'    => 'Are we meeting the 7-1-7 standard',
            'one_line' => 'Detect within 7 days; notify within 1; respond within 7. Three rings, three percentages.',
            'showing'  => 'Three rings, one for each part of the international standard. The first ring is the share of recent alerts that were detected within seven days of the underlying signal. The second ring is the share that were notified within one day of detection. The third ring is the share that were responded to within seven days of opening. Each percentage is computed from alerts in the last 30 days inside your scope.',
            'how_to_read' => 'Each ring fills clockwise toward 100%. The number in the centre is the percentage. The colour reflects the urgency: green is on track, amber is worth a conversation, red is worth a phone call. The label under the ring names the deadline target.',
            'good_looks_like'      => 'All three rings sit above 95%. Detect and notify rings tend to lead — they reflect mostly automatic system behaviour. The respond ring is the slower one because it tracks the people on the ground.',
            'concerning_looks_like'=> 'Any single ring sliding below 80% in a fortnight, or two rings amber for three days running. The respond ring sliding while detect and notify are green typically means PHEOC officers are stretched, not that the system is broken.',
            'more_charts' => [
                'A breakdown by tripwire type — which kinds of signal are slipping the deadline most often.',
                'A breakdown by district within your scope — where the slippage is concentrated.',
                'A weekly trend over the last twelve weeks — is this a one-off or a pattern.',
            ],
            'what_to_do' => 'Open the Compliance section and filter by the lagging stage; ask the PHEOC officer assigned to the lagging district to brief you. The deep-link from this wizard takes you there with the right filter applied.',
            'cannot_tell_you' => 'Why a particular alert was late — you will need the case dossier for that. The ring is a population view, not a single-case view.',
            'learn_more' => 'Compliance & Performance section, 7-1-7 board.',
            'deep_link'  => '/admin/compliance/717',
        ],

        'alert_pulse' => [
            'title'    => 'What changed in the last two weeks',
            'one_line' => 'A simple line of alerts opened per day, with markers where the system fired tripwires.',
            'showing'  => 'One dot per day for the last fourteen days, joined by a line. The vertical scale is the number of alerts opened that day. Small triangles on the line mark days when a deterministic tripwire fired — for example, three or more alerts of the same suspected disease in 48 hours.',
            'how_to_read' => 'Read it left to right. A flat line at a low number is normal. A clear bump worth investigating is roughly twice the recent average for two days running. A flat line at zero for several days is also worth checking — it may mean nobody is reporting.',
            'good_looks_like'      => 'A modest, steady level reflecting the country’s baseline screening pressure. Occasional small bumps are normal; spikes have explanations the system can usually point to.',
            'concerning_looks_like'=> 'A sudden cluster of triangles in one week, or a week with no alerts when a busy week is expected, or a steady upward trend that is not seasonal.',
            'more_charts' => [
                'The same fourteen days broken down by suspected condition — is one disease driving the line.',
                'The same fourteen days broken down by district — is one place driving the line.',
                'The hour-of-day distribution — at which hours are alerts being opened.',
            ],
            'what_to_do' => 'Tap a triangle to read the tripwire that fired in plain language. If you see a real cluster, open the Surveillance Intelligence section to investigate; if the line has gone quiet, the System Health section will tell you whether it is a screening problem or a connectivity problem.',
            'cannot_tell_you' => 'Whether an alert turned out to be a real outbreak or a false alarm. That is in the case dossier and in the Reports section, not on this chart.',
            'learn_more' => 'Surveillance Intelligence section.',
            'deep_link'  => '/admin/intelligence/copilot',
        ],

        'poe_map' => [
            'title'    => 'How the points of entry are doing',
            'one_line' => 'A pin per border post, airport, or seaport. Colour reflects activity in the last 24 hours.',
            'showing'  => 'Every official Point of Entry in your scope, with a pin coloured by how busy the screening team has been in the last day. Green is healthy activity, amber is light, red is silent. The numbers next to each pin are travellers screened.',
            'how_to_read' => 'Scan the colours first. Most pins should be green or amber on a normal day. A red pin at a normally-busy crossing is the row that wants attention — it usually means screening has stopped, not that travellers have stopped.',
            'good_looks_like'      => 'A spread of green and amber across the country. Some quiet posts are normal — small crossings have low traffic.',
            'concerning_looks_like'=> 'Any usually-busy crossing showing red. A whole region’s pins going quiet on the same day suggests a connectivity or cron problem, not a screening problem.',
            'more_charts' => [
                'A peer-comparison bar — your post or district against the others around it.',
                'A throughput line for any selected post over fourteen days.',
                'The silent-period detector — which posts have been quiet longer than expected.',
            ],
            'what_to_do' => 'Tap a red or unusual pin to open the post’s page. If you see a national-scale silence, check System Health’s mobile-sync card — phones may not be reaching the server.',
            'cannot_tell_you' => 'Whether a screening team is doing thorough screening or just opening records. The chart counts records, not quality. The Reports section answers quality.',
            'learn_more' => 'Points of Entry section.',
            'deep_link'  => '/admin/geo/poes',
        ],

        'classification_donut' => [
            'title'    => 'How current cases are being classified',
            'one_line' => 'A donut showing open cases by suspected, probable, confirmed, ruled-out, and pending.',
            'showing'  => 'The current open caseload in your scope, broken down by case classification in plain language. Each segment is a count and a percentage. The total in the centre is the number of cases currently open.',
            'how_to_read' => 'A healthy donut leans toward "ruled-out" and "pending" — the system catches and processes a lot of suspected cases that turn out to be nothing. "Confirmed" is the smallest slice you ever want to see.',
            'good_looks_like'      => 'A small "confirmed" slice; a moderate "probable" slice that resolves into "ruled-out" or "confirmed" within a week or two; a healthy "pending" slice reflecting laboratory turnaround.',
            'concerning_looks_like'=> 'A growing "confirmed" slice; a "probable" slice that does not move (cases are stuck mid-pipeline); a "pending" slice ballooning (laboratory capacity strain).',
            'more_charts' => [
                'A breakdown of "probable" cases by suspected disease.',
                'Time-since-classification histogram — how stale is each segment.',
                'Cases moving through classifications over the last fortnight (a Sankey-style flow if the data supports it).',
            ],
            'what_to_do' => 'Tap a segment to open the cases registry filtered to that classification. Stuck "probable" usually means a laboratory result is late; stuck "pending" usually means lab confirmation. Either way, the deep-link takes you to the right view.',
            'cannot_tell_you' => 'Whether the case count reflects all cases or only those reported into the platform. The Reports section can compare against external reference rates.',
            'learn_more' => 'Cases Registry report.',
            'deep_link'  => '/admin/reports/rpt-registry',
        ],

        'ack_compliance' => [
            'title'    => 'Are alerts being acknowledged on time',
            'one_line' => 'A bar showing the share of recent alerts acknowledged within their deadline.',
            'showing'  => 'The percentage of alerts in the last 24 hours that were acknowledged within one hour of opening — or whatever your scope’s deadline is. The bar fills toward 100%. The number alongside is the median minutes from opening to acknowledgement.',
            'how_to_read' => 'A long full bar is good. A bar that is two-thirds full and a high median minutes-to-acknowledge usually means the alerts are arriving outside business hours and the on-call rotation is delayed.',
            'good_looks_like'      => 'Above 95% acknowledged within deadline; median minutes-to-acknowledge under 30. The on-call rotation is responsive.',
            'concerning_looks_like'=> 'Below 80% acknowledged within deadline, especially if the median is climbing — the on-call rotation needs attention.',
            'more_charts' => [
                'Acknowledge time distribution — how many alerts were acknowledged in 0–15 min, 15–60 min, 60+ min.',
                'Acknowledge rate by hour-of-day — when are responses slowest.',
                'Acknowledge rate by responder — who acknowledged how many.',
            ],
            'what_to_do' => 'If acknowledge times are creeping up, open Compliance and check the responder leaderboard. If a single responder is dragging the average, that is a coverage conversation.',
            'cannot_tell_you' => 'Whether the acknowledgement was meaningful — only that someone clicked acknowledge. Quality of follow-through is in the case dossier.',
            'learn_more' => 'Alert Acknowledgement report.',
            'deep_link'  => '/admin/reports/rpt-alert-acknowledgement',
        ],

        'followup_completeness' => [
            'title'    => 'Are follow-ups being completed',
            'one_line' => 'A ring showing the share of follow-up steps completed across active cases.',
            'showing'  => 'For every case currently open, the follow-up checklist (contacts traced, samples collected, lab results received, etc.) is tracked. This ring is the average completeness across all open cases in your scope.',
            'how_to_read' => 'A high ring means the operational discipline of running cases through to completion is good. A low ring means cases are being opened and forgotten — the most dangerous failure mode in surveillance.',
            'good_looks_like'      => 'Above 80% on a scope with a steady stream of cases. Younger cases pull the average down naturally; older cases that are still incomplete pull it down by neglect.',
            'concerning_looks_like'=> 'Below 60%, or a number that is dropping week-on-week. The follow-up workbench will name the cases that have stalled.',
            'more_charts' => [
                'Completeness by case age — are old cases being neglected.',
                'Completeness by district within your scope — where is the gap.',
                'Top stalled cases — which specific cases need a nudge.',
            ],
            'what_to_do' => 'Open the Follow-up Workbench and triage the longest-stalled cases first. The deep-link takes you there.',
            'cannot_tell_you' => 'Whether the recorded follow-ups were of good quality — only whether they were ticked off. Quality is in each case’s dossier.',
            'learn_more' => 'Follow-up Workbench.',
            'deep_link'  => '/admin/alerts/followups',
        ],

        'dispatch_sanity' => [
            'title'    => 'Are reminders flooding inboxes',
            'one_line' => 'Today’s reminder volume against the platform’s "at most once per case per day" ceiling.',
            'showing'  => 'A short bar showing how many recipients have received a reminder today, alongside the ceiling — at most one reminder per case per recipient per day. If the bar is well below the ceiling, the platform is being a good citizen of your colleagues’ inboxes.',
            'how_to_read' => 'The number is the count of distinct recipient × case pairs that received a reminder today. The ceiling, in absolute terms, depends on the open caseload. As long as the bar stays below the ceiling, the cadence rule is being kept.',
            'good_looks_like'      => 'A bar comfortably below the ceiling. Recipients who used to receive several reminders a day for the same case now receive at most one.',
            'concerning_looks_like'=> 'The bar approaching the ceiling, or any single recipient receiving more than one reminder for the same case in a day. If you see this, the dispatch service has a bug worth raising.',
            'more_charts' => [
                'Reminders sent per recipient over the last fourteen days.',
                'Suppression-window hits — when the dispatcher correctly declined to re-send.',
                'Failure pipeline — where reminders fall over (provider rejection, address bounce).',
            ],
            'what_to_do' => 'Open the Delivery Audit in the Governance section to investigate any specific failure. The deep-link takes you there.',
            'cannot_tell_you' => 'Whether the reminders that did go out were appropriate — only that the cadence was honoured. Appropriateness is the wording in the templates.',
            'learn_more' => 'Governance · Delivery Audit, and Reminders & Retry.',
            'deep_link'  => '/admin/governance/notification-log',
        ],

        'copilot_brief' => [
            'title'    => 'What is on your desk today',
            'one_line' => 'A short list of the most useful actions to take today, with the reason each is on the list.',
            'showing'  => 'Three to five items selected by deterministic rules from the current operational state. Each item names the case or condition, the recommended action, the source signal that put the item on the list, and a one-tap link to the right view to act.',
            'how_to_read' => 'Read top to bottom; the most urgent item is first. Each item carries a confidence indicator computed from the deterministic rules — high confidence means several signals concur; lower confidence means one signal triggered the recommendation.',
            'good_looks_like'      => 'A short list (three or four items). A long list usually means the operational pipeline is backed up, not that more is happening — fewer is better here, because a clean room means the team is keeping up.',
            'concerning_looks_like'=> 'A list that grows day on day with the same items not being acted on. That is a workforce or capacity problem, not a platform problem.',
            'more_charts' => [
                'The full deterministic rule set behind the recommendations — exactly which signals trigger which item.',
                'A history of items that were on previous days’ briefs and what happened to them.',
            ],
            'what_to_do' => 'Tap an item to go straight to the right view to act. The Copilot does not act on your behalf — it surfaces, you decide. You can also forward the brief to a PHEOC officer to handle.',
            'cannot_tell_you' => 'Whether you have already actioned an item earlier today — the brief is computed from the current data state at refresh time. If you act and the underlying signal clears, the item disappears on the next refresh.',
            'learn_more' => 'Surveillance Intelligence · Copilot.',
            'deep_link'  => '/admin/intelligence/copilot',
        ],

        'tripwires' => [
            'title'    => 'What automatic checks are flagging',
            'one_line' => 'A row of small flags from deterministic rules — silent posts, stuck alerts, overdue follow-ups, dormant accounts.',
            'showing'  => 'Six small numbers. Each is a deterministic count produced by the surveillance intelligence engine. Silent posts: points of entry with no screening submitted in 24 hours. Stuck alerts: alerts open longer than their deadline. Overdue follow-ups: follow-up steps past their due date. Outstanding reports: aggregated reports a station owes. Case-count anomalies: clusters above the historical baseline. Dormant accounts: users who have not signed in in 30 days.',
            'how_to_read' => 'Each number is a count. The colour reflects the operational tone: green or empty is fine, amber is worth a glance, red is worth attention. None of these are emergencies on their own; they are the inputs that the Copilot uses to compose your brief.',
            'good_looks_like'      => 'Single-digit numbers across the row, most of them green. The country is operating cleanly.',
            'concerning_looks_like'=> 'Any single number in double digits that does not move for several days. That is operational drift — alerts piling up faster than they are being closed, posts going quiet, follow-ups slipping.',
            'more_charts' => [
                'The detail behind each tripwire — which exact alerts are stuck, which posts are silent, which accounts are dormant.',
                'The history of each tripwire over fourteen days.',
            ],
            'what_to_do' => 'Tap a tripwire to see the underlying list. Most of these flow into the Copilot brief automatically; the Copilot prioritises which to tackle first.',
            'cannot_tell_you' => 'Why a tripwire fired — only that it did. The detail view names the underlying records.',
            'learn_more' => 'Surveillance Intelligence section.',
            'deep_link'  => '/admin/intelligence/tripwires',
        ],

        'alerts_feed' => [
            'title'    => 'The live alert feed',
            'one_line' => 'The most recent active alerts in your scope, with the obvious next action on each.',
            'showing'  => 'Up to ten of the most recent alerts that are still open or acknowledged but not yet closed in your scope. Each row names the case, the suspected condition, the current owner, the time elapsed since opening, and the deadline countdown.',
            'how_to_read' => 'Read the top of the list first — it is the most recent. The colour on the left edge of each row reflects the risk level. Tap a row to open the case dossier inside the room without leaving the page.',
            'good_looks_like'      => 'A short list, most rows green or amber, deadlines comfortably ahead.',
            'concerning_looks_like'=> 'Multiple red rows, deadlines counting down, the same owner on several rows. That is a coverage problem.',
            'more_charts' => [
                'The full alert master list, with filters.',
                'Alerts grouped by owner — who is carrying the load.',
                'Alerts grouped by suspected condition — what the country is dealing with.',
            ],
            'what_to_do' => 'Tap a row to open the case dossier. Use the filters in the Alerts section if you need to triage by owner, condition, or scope.',
            'cannot_tell_you' => 'Why an alert was opened — that is in the case dossier behind the row.',
            'learn_more' => 'Alert Operations section.',
            'deep_link'  => '/admin/alerts/master',
        ],
    ],

    /*--------------------------------------------------------------------
     | Glossary — every concept that has a name on the screen
     *--------------------------------------------------------------------*/
    'glossary' => [
        ['term' => 'Situation Room',  'plain_english' => 'The single screen you are looking at — designed so you can answer "is the country okay?" in three seconds without opening anything else.'],
        ['term' => 'PHEOC',           'plain_english' => 'Public Health Emergency Operations Centre — the provincial command centre that runs outbreak response below the national level.'],
        ['term' => 'POE',             'plain_english' => 'Point of Entry — the official border crossings, airports, and ports where travellers are screened on arrival or departure.'],
        ['term' => 'IHR',             'plain_english' => 'International Health Regulations — the WHO-led framework countries follow to detect, notify, and respond to public-health emergencies.'],
        ['term' => '7-1-7',           'plain_english' => 'The international standard for outbreak response: detect the signal in 7 days, notify the world in 1 day, respond on the ground in 7 days.'],
        ['term' => 'Alert',           'plain_english' => 'A record opened when a screener spots a traveller worth investigating. Every alert has an owner, a deadline, and a record of what happened next.'],
        ['term' => 'Tripwire',        'plain_english' => 'A simple, deterministic rule that fires when the system spots an unusual pattern — a silent border post, a cluster of similar suspected cases, an alert that has been open too long.'],
        ['term' => 'Acknowledge',     'plain_english' => 'The first formal action on an alert — the responder confirms they have seen it. The deadline to acknowledge is one hour for high-risk alerts.'],
        ['term' => 'Follow-up',       'plain_english' => 'The checklist of operational steps that closes a case — contacts traced, samples collected, lab results received, contacts cleared.'],
        ['term' => 'Suspected case',  'plain_english' => 'A case that fits the pattern of a notifiable disease but has not yet been confirmed. Most suspected cases turn out to be ruled out.'],
        ['term' => 'Probable case',   'plain_english' => 'A case where multiple signs point to a notifiable disease but laboratory confirmation has not yet arrived.'],
        ['term' => 'Confirmed case',  'plain_english' => 'A case where laboratory confirmation has arrived. The smallest segment you ever want to see on the donut.'],
        ['term' => 'Ruled out',       'plain_english' => 'A suspected case that has been investigated and shown not to be the disease in question. Most cases end here, and that is a healthy outcome.'],
        ['term' => 'Copilot',         'plain_english' => 'A deterministic rule engine that composes a short list of recommended actions each day. It does not use modern AI — it uses written-down rules. Same situation tomorrow → same recommendation tomorrow.'],
        ['term' => 'Reminder',        'plain_english' => 'A message the platform sends to a responder when a deadline is approaching or passed. The platform sends at most one reminder per case per recipient per day.'],
        ['term' => 'Cadence ceiling', 'plain_english' => 'The platform’s upper bound on reminder volume — at most one per case per recipient per day. The dispatch sanity card on the screen shows the ceiling being kept.'],
        ['term' => 'Dossier',         'plain_english' => 'The full, single-page record of a case — who, what, where, when, why, and what was done. Tapping any alert in the feed opens its dossier.'],
        ['term' => 'Scope',           'plain_english' => 'The slice of the country you are allowed to see. The greeting at the top of the screen tells you whether you are looking at the whole country, your PHEOC, your district, or your point of entry.'],
        ['term' => 'Freshness stamp', 'plain_english' => 'The time the screen last refreshed. If the network drops, the stamp ages and the screen tells you so. Nothing on screen pretends to be live when it is not.'],
        ['term' => 'Methodology footer', 'plain_english' => 'The line of context attached to every export from this room — your scope, the filters you had set, and the timestamp. So a printed brief cannot be misread out of context.'],
    ],

    /*--------------------------------------------------------------------
     | Comparison columns — used by chart-explainer comparison sheets if
     | the user wants to see all charts side-by-side.
     *--------------------------------------------------------------------*/
    'comparison_columns' => ['What it answers', 'When to look', 'Where it leads', 'What it cannot tell you'],
];
