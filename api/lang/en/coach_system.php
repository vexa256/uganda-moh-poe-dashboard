<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Coach Manifest — System Health module
|--------------------------------------------------------------------------
|
| Per-view, deterministic guidance for the National System Health Console.
| Voice: calm, plain, second-person, never cheerful — written for an
| operator who is not a developer, who has never opened a server log,
| and who needs to know whether the platform's machinery is healthy and
| what to do if it is not.
|
| Every concept that has a name in the data has a glossary entry. Every
| chart has interpretation copy. Every wizard option ends with a
| summary statement. Every action answers the ten governance questions:
|
|   1. Where am I?              → view.purpose
|   2. What can I do here?      → actions[].label + one_liner
|   3. What does this mean?     → one_liner
|   4. When should I pick this? → when_to_use / when_not_to_use
|   5. What do I need ready?    → prerequisites
|   6. How long will it take?   → estimated_time
|   7. What happens on confirm? → consequences + steps
|   8. Can I undo it?           → reversibility
|   9. Who else will see it?    → visibility
|  10. What if I'm not sure?    → fallback_action
|
| Boundary discipline:
|   - "cron" appears in the user-facing copy ONLY in the methodology
|     glossary. Body copy says "scheduled job" or "the timekeeper".
|   - "SMTP", "queue worker", "kernel", "TLS", "OAuth2", and the like
|     appear ONLY behind "Show technical detail" disclosures.
|   - "sync queue depth" → "messages waiting to upload from operators'
|     phones".
|   - "Bounce register" → "mail that came back undeliverable".
|
| Versioned: v1 — domain sign-off pending.
*/

return [

    /*--------------------------------------------------------------------
     | System-Health-wide glossary (merged into every view's glossary)
     *--------------------------------------------------------------------*/
    'glossary_common' => [
        ['term' => 'System Health',     'plain_english' => 'The set of background machinery the platform relies on — the timekeeper that runs scheduled jobs, the mail delivery, the mobile-app uploads, the planned link to WHO. This section tells you whether that machinery is healthy.'],
        ['term' => 'Healthy',            'plain_english' => 'The machinery is doing what it is supposed to do, on time. There is nothing for you to act on right now.'],
        ['term' => 'Unhealthy',          'plain_english' => 'Something is not running, not delivering, or not arriving when it should. The screen will tell you who is affected and what the platform is doing about it.'],
        ['term' => 'Read-only',          'plain_english' => 'You can see what is happening, but you cannot change settings from this section. Settings are owned by the engineering team.'],
        ['term' => 'Audit log',          'plain_english' => 'A permanent, append-only record of who looked at sensitive data and what they did with it. Your view of these screens is logged.'],
        ['term' => 'Idempotency key',    'plain_english' => 'A short code attached to an action so the system can recognise the same action coming in twice and ignore the duplicate. Used here on the manual-trigger and the notify-me actions.'],
        ['term' => 'Retry',              'plain_english' => 'When the system tries the same job or message again after a failure. Most retries are automatic; a few are manual and visible on this surface.'],
        ['term' => 'PII',                'plain_english' => 'Personally identifiable information — anything that names or can be used to find a real person. Names, phone numbers, emails. Some screens show PII; your view of it is logged.'],
    ],

    /*====================================================================
     | VIEW · sys-cron — Scheduled Jobs
     *===================================================================*/
    'cron' => [
        'view' => [
            'id'            => 'system.cron',
            'title'         => 'Scheduled jobs',
            'purpose'       => 'The platform runs jobs in the background — sending the morning digest, dispatching follow-up reminders, retrying messages that did not deliver, scanning for missed deadlines. This screen tells you whether those jobs are running on time.',
            'audience'      => 'National administrators monitoring the platform’s background machinery.',
            'prerequisites' => [
                'You know which job (if any) you are investigating before you begin.',
                'You understand that re-running a job is safe — every job here is idempotent — but is recorded with your name in the audit log.',
            ],
            'header_intro'  => 'The platform’s background timekeeper. Read-only — you can see when each job runs, when it last ran, and re-run it if needed. Your view is logged.',
        ],

        'actions' => [
            'open_schedule' => [
                'id'              => 'open_schedule',
                'label'           => 'Open a schedule’s detail',
                'verb'            => 'open',
                'icon'            => 'eye',
                'one_liner'       => 'See the full history, the cadence in plain language, who is affected, and what the platform does when this schedule fails.',
                'when_to_use'     => 'When you want to verify a single schedule is running on time, or when a colleague asks why a particular message has not arrived.',
                'when_not_to_use' => 'For a quick health overview, the Overview tab is enough.',
                'prerequisites'   => [],
                'consequences'    => ['Read-only. Opening a schedule’s detail is logged with your name and the time.'],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => null],
                'visibility'      => ['You.', 'National administrators reviewing the access-audit log.'],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => 'If you are not sure what a schedule does, open the Methodology tab — every schedule is described there in plain language.',
            ],
            'manual_trigger' => [
                'id'              => 'manual_trigger',
                'label'           => 'Run a schedule now',
                'verb'            => 'trigger',
                'icon'            => 'play',
                'one_liner'       => 'Run a schedule on demand instead of waiting for its next slot. Safe to repeat — duplicates are blocked at the message level.',
                'when_to_use'     => 'When a job has missed its window and you want to catch up; when you have just made a configuration change and want to verify it works.',
                'when_not_to_use' => 'Do not use this to spam recipients. The 24-hour suppression rule on alerts and reminders means a same-day repeat will not produce a duplicate message — but a manual trigger still consumes a real run, and it is logged.',
                'prerequisites'   => ['You know which schedule you are running and why.'],
                'consequences'    => [
                    'The platform runs the chosen schedule as if it had been called by the timekeeper.',
                    'Any messages produced by the run go through the same suppression and idempotency checks as the automatic run.',
                    'The trigger is recorded in the audit log with your name, the schedule, and the time.',
                ],
                'reversibility'   => ['reversible' => false, 'window_minutes' => null, 'how_to_undo' => 'A run cannot be un-run. Messages already sent cannot be recalled. Subsequent runs continue on the normal schedule.'],
                'visibility'      => ['You.', 'National-admin reviewers in the access-audit log.', 'Anyone the schedule was supposed to message — they will see the message arrive.'],
                'estimated_time'  => 'Under a minute for most schedules; the breach scanner can take a few minutes.',
                'fallback_action' => 'If you are not sure a manual trigger is appropriate, leave it alone. The job will run again on its normal schedule.',
            ],
        ],

        'modals' => [
            'manual_trigger_confirm' => [
                'id'      => 'manual_trigger_confirm',
                'title'   => 'About to run a schedule on demand',
                'purpose' => 'A short summary of what is about to happen, then a final confirm.',
                'fields'  => [],
            ],
        ],

        'glossary' => [
            ['term' => 'Schedule',         'plain_english' => 'A job the platform runs in the background on a fixed cadence — every minute, every hour, every day, every three days. Each schedule has a clear purpose; the most common ones are listed in the Each Schedule tab.'],
            ['term' => 'Run',              'plain_english' => 'One execution of a schedule. The Recent Runs tab lists every run across all schedules with its outcome.'],
            ['term' => 'Last run',          'plain_english' => 'The most recent time the schedule actually executed and produced evidence (a sent message, a filed report). Not necessarily the same as "last attempted".'],
            ['term' => 'Next due',          'plain_english' => 'The next time the timekeeper will start this schedule, calculated from the cadence.'],
            ['term' => 'Overdue',           'plain_english' => 'The schedule has not produced fresh evidence within twice its expected interval. Usually means the job ran but did nothing, or the timekeeper itself is paused.'],
            ['term' => 'cron',              'plain_english' => 'A standard way of saying "run this on this cadence". The platform uses cron expressions internally; this screen translates them into plain language.'],
            ['term' => 'Idempotent',        'plain_english' => 'A job is idempotent when running it twice has the same effect as running it once. Every schedule on this platform is idempotent — manual re-runs are safe.'],
            ['term' => 'Suppression window','plain_english' => 'A period during which the same kind of message will not be sent twice to the same recipient. Used to prevent floods of identical alerts. Manual re-runs respect the suppression window.'],
            ['term' => 'Scanner',           'plain_english' => 'A read-only schedule that looks for a condition and files a record when it finds one. The breach scanner is an example — it looks for missed 7-1-7 deadlines and writes a breach report.'],
            ['term' => 'Manual trigger',    'plain_english' => 'You asking the platform to run a schedule now instead of waiting for the timekeeper. Recorded in the audit log; the run goes through the same path as the automatic run.'],
        ],

        'wizard' => [
            'launcher_label' => 'What do you want to do?',
            'options' => [
                ['id' => 'health_check',      'label' => 'Check whether everything is running on schedule', 'goto_tab' => 'overview',  'summary' => 'The Overview tab shows a single health pill and the last 24 hours of runs for every schedule. If the pill is green, you are done — there is nothing else to look at.'],
                ['id' => 'see_failures',      'label' => 'See what failed recently and why',                'goto_tab' => 'failures',  'summary' => 'The Failures tab lists every failed run in the period, grouped by schedule, with the failure reason translated into plain language. Click a row for the technical detail.'],
                ['id' => 'find_message',      'label' => 'Find out if a colleague’s message was sent',      'goto_tab' => 'recent',    'summary' => 'Recent Runs lists every run with the count of messages produced. For a per-recipient view, switch to the Mail Status screen — it has the same information one row per message.'],
                ['id' => 'rerun_now',         'label' => 'Re-run a job that should have run by now',       'goto_tab' => 'manual',    'summary' => 'Manual Triggers lets you run a schedule on demand. Every trigger is recorded with your name. The job is idempotent — you cannot accidentally produce duplicates.'],
                ['id' => 'walk_through',      'label' => 'Walk me through this view from the start',       'goto_tab' => 'overview',  'summary' => 'You will be guided through Overview, then the Each Schedule tab, then the Methodology page. Three minutes.'],
            ],
        ],

        'charts' => [
            'heartbeat_strip' => [
                'title'        => 'Heartbeat strip',
                'shows'        => 'For one schedule, every run in the last 7 or 30 days as a coloured tick — green for success, red for failed, grey for skipped, blue for currently running.',
                'how_to_read'  => 'Read left to right; the rightmost tick is the most recent run. Hover any tick for the run time and outcome. The strip stops at "now" — the gap on the right is the time until the next run.',
                'healthy'      => 'A regular pattern of green ticks at the cadence the schedule expects.',
                'concerning'   => 'A long gap with no ticks at all (the schedule is not running), a stretch of red (the schedule is running but failing), or a "running" tick that does not resolve (the run is stuck).',
                'what_to_do'   => 'A long gap or a stuck "running" tick is worth a call to the engineering team. A stretch of red — open the Failures tab to read the reason in plain language.',
                'cannot_tell'  => 'It cannot tell you what each run produced. For per-recipient detail, the Mail Status screen has one row per message.',
                'data_source'  => 'Drawn from the platform’s notification log and the breach-report log, filtered to the rows the schedule produced.',
            ],
            'overview_pill' => [
                'title'        => 'Health pill',
                'shows'        => 'A single pill summarising whether all schedules are running on time.',
                'how_to_read'  => 'Green is "everything on time"; amber is "one schedule needs attention"; red is "two or more schedules need attention".',
                'healthy'      => 'A green pill.',
                'concerning'   => 'Anything other than green.',
                'what_to_do'   => 'If amber or red, scroll to the Each Schedule tab to find the unhappy schedule, then read its detail.',
                'cannot_tell'  => 'It cannot tell you whether a healthy schedule is producing the right output — only that it is running on its cadence.',
                'data_source'  => 'Computed from each schedule’s last-run timestamp against its expected interval.',
            ],
        ],

        'comparison_columns' => ['When this fits', 'Heads-up', 'Reversible', 'Time'],
        'pre_confirm'        => ['header' => 'About to run on demand', 'note' => 'The audit will record exactly the schedule below, your name, and the time.'],
        'post_action'        => ['header_success' => 'The schedule has been triggered', 'next_step_hint' => 'You can refresh the heartbeat strip in a moment — the run should appear as a fresh tick.'],
    ],

    /*====================================================================
     | VIEW · sys-mail — Mail Delivery
     *===================================================================*/
    'mail' => [
        'view' => [
            'id'            => 'system.mail',
            'title'         => 'Mail delivery',
            'purpose'       => 'For every email the platform sent, this screen tells you whether it actually reached its recipient. The deliveries are summarised by send type (digest, reminder, sign-in code) and by recipient. When a delivery comes back undeliverable, the reason is shown in plain language.',
            'audience'      => 'National administrators investigating mail delivery, message governance, and bounces.',
            'prerequisites' => [
                'You know the time period or the recipient you are investigating.',
                'You understand that recipient details on this screen are PII and your view is logged.',
            ],
            'header_intro'  => 'Per-recipient delivery proof. Did the message leave the building? What did the recipient’s mail server say? Read-only. Your view is logged.',
        ],

        'actions' => [
            'open_send' => [
                'id'              => 'open_send',
                'label'           => 'Open a delivery’s detail',
                'verb'            => 'open',
                'icon'            => 'eye',
                'one_liner'       => 'See the full record of one outgoing mail — recipient, send type, delivery status, and the recipient mail server’s last response in plain language.',
                'when_to_use'     => 'When a recipient queries whether a message arrived, or when you need to verify a single delivery for a brief.',
                'when_not_to_use' => 'For trend analysis, the Overview tab has the right shape.',
                'prerequisites'   => [],
                'consequences'    => ['Read-only. Opening the detail is recorded as a PII reveal because the recipient address is unmasked here.'],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => null],
                'visibility'      => ['You.', 'National-admin reviewers in the access-audit log.'],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => 'If the row is unclear, do not act on it — read it back to the recipient verbatim and let them tell you whether it matches their experience.',
            ],
        ],

        'modals' => [],

        'glossary' => [
            ['term' => 'Send',              'plain_english' => 'One attempt by the platform to deliver one mail to one recipient. A single broadcast — say, the morning digest — produces many sends, one per recipient.'],
            ['term' => 'Delivery',           'plain_english' => 'A send that the recipient mail server accepted. Acceptance is not the same as the recipient reading the message — that is outside what the platform can know.'],
            ['term' => 'Bounce',             'plain_english' => 'A send that came back as undeliverable. The recipient mail server told us why; we translate that reason into plain language.'],
            ['term' => 'Failure',            'plain_english' => 'A send that did not complete because of a problem at our end or in transit. Different from a bounce — failures are usually retried automatically.'],
            ['term' => 'Suppression',        'plain_english' => 'A rule that holds back further mail to a recipient who has bounced repeatedly. Protects our sender reputation and the recipient’s inbox.'],
            ['term' => 'Mail delivery',      'plain_english' => 'The path mail takes from the platform to the recipient — how it leaves us, how it travels, how the recipient mail server replies. Behind the scenes, this uses a protocol called SMTP; you do not need to know that to read this screen.'],
            ['term' => 'Send type',          'plain_english' => 'What the mail was for — a daily digest, a follow-up reminder, a sign-in code, a notification. The Overview tab groups deliveries by send type.'],
            ['term' => 'Recipient domain',   'plain_english' => 'The part of an email address after the @ — usually the recipient’s organisation. Useful for spotting whole-organisation problems.'],
            ['term' => 'Delivery rate',      'plain_english' => 'Out of every 100 attempts, how many were accepted by the recipient mail server. A healthy figure is 95% or above. Below 90% means something is wrong.'],
        ],

        'wizard' => [
            'launcher_label' => 'What do you want to do?',
            'options' => [
                ['id' => 'check_specific',  'label' => 'See if a specific message reached its recipient',     'goto_tab' => 'recent',    'summary' => 'Recent Sends lists every outgoing mail. Search by recipient or send type. Each row tells you whether it was delivered, bounced, or failed.'],
                ['id' => 'check_bouncing',  'label' => 'See what is bouncing',                                'goto_tab' => 'bounces',   'summary' => 'The Bounces tab lists every undeliverable mail with the reason in plain language. Repeated bounces to the same recipient indicate the address may be wrong.'],
                ['id' => 'walk_send',       'label' => 'Walk me through a recent send',                       'goto_tab' => 'recent',    'summary' => 'You will be guided through one recent send: who it went to, what was sent, what came back. Two minutes.'],
                ['id' => 'walk_through',    'label' => 'Walk me through this view from the start',           'goto_tab' => 'overview',  'summary' => 'You will be guided through Overview, then By Recipient, then the Methodology page. Three minutes.'],
            ],
        ],

        'charts' => [
            'sparkline' => [
                'title'        => 'Send / delivered / failed sparkline',
                'shows'        => 'The number of sends, deliveries, and failures over the chosen period.',
                'how_to_read'  => 'Three lines: blue for sends, green for delivered, red for failed. The vertical scale is sends per hour. Hover for the exact numbers.',
                'healthy'      => 'A green line that closely tracks the blue line. A flat or near-zero red line.',
                'concerning'   => 'A red line that grows towards the blue line — failures climbing. A green line that drops away from the blue — deliveries failing silently.',
                'what_to_do'   => 'A growing red line is worth investigating now. The Bounces tab will tell you why. If many recipients on the same domain are failing, that domain’s mail server is most likely the cause.',
                'cannot_tell'  => 'It cannot tell you whether the recipient read the mail — only that the recipient’s mail server accepted it.',
                'data_source'  => 'Drawn from every notification-log row marked EMAIL in the chosen period.',
            ],
            'domain_league' => [
                'title'        => 'Recipient-domain league',
                'shows'        => 'For the busiest recipient domains, how many sends went there and how many succeeded.',
                'how_to_read'  => 'One row per domain. The bar shows successful versus failed sends. Sorted by total volume, highest first.',
                'healthy'      => 'Each row’s bar is mostly green.',
                'concerning'   => 'A row with a mostly red bar — that one domain’s mail server is rejecting our messages.',
                'what_to_do'   => 'Talk to the engineering team. They can check the rejection messages and, if appropriate, contact the receiving organisation.',
                'cannot_tell'  => 'It cannot identify individual recipients — only the domain (the part after the @).',
                'data_source'  => 'Aggregated from the same notification-log rows as the sparkline.',
            ],
        ],

        'comparison_columns' => ['When this fits', 'Heads-up', 'Reversible', 'Time'],
        'pre_confirm'        => null,
        'post_action'        => null,
    ],

    /*====================================================================
     | VIEW · sys-mobile — Mobile App Health
     *===================================================================*/
    'mobile' => [
        'view' => [
            'id'            => 'system.mobile',
            'title'         => 'Mobile app health',
            'purpose'       => 'Operators use a mobile app at the points of entry. This screen tells you how the app is performing — what version operators have, what platforms they are on, and how much data is waiting to upload from their phones.',
            'audience'      => 'National administrators monitoring the field-app fleet.',
            'prerequisites' => [
                'You understand that operator-device identifiers are PII and your view is logged.',
                'You know that "waiting to upload" usually clears itself; long delays are the exception, not the rule.',
            ],
            'header_intro'  => 'Field-app fleet health. Pending uploads, app versions, platforms, and devices that have gone quiet. Read-only. Your view is logged.',
        ],

        'actions' => [
            'open_device' => [
                'id'              => 'open_device',
                'label'           => 'Open a device’s detail',
                'verb'            => 'open',
                'icon'            => 'eye',
                'one_liner'       => 'See the device’s identifier, platform, app version, and time since its last successful upload.',
                'when_to_use'     => 'When you are tracing why a particular operator’s data is missing from the dashboard.',
                'when_not_to_use' => 'For overall fleet health, the Overview tab is enough.',
                'prerequisites'   => [],
                'consequences'    => ['Read-only. Opening the detail is recorded with the device identifier in the access-audit log.'],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => null],
                'visibility'      => ['You.', 'National-admin reviewers in the access-audit log.'],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => 'If you are unsure which operator owns a device, do not act on the detail — confirm with the field supervisor first.',
            ],
        ],

        'modals' => [],

        'glossary' => [
            ['term' => 'Pending upload',     'plain_english' => 'A row the operator captured on the phone but has not yet sent to the platform. Usually the phone is offline; sometimes the upload was paused. The phone will retry on its own.'],
            ['term' => 'Sync',               'plain_english' => 'The phone uploading what it captured to the platform. Sync is automatic when the phone is online.'],
            ['term' => 'Quiet device',       'plain_english' => 'A device the platform has not heard from in longer than its expected sync window. Usually means the phone is off, has no signal, or is unattended.'],
            ['term' => 'App version',        'plain_english' => 'Which build of the field app is on the phone. Older versions sometimes lack newer fields; the latest version is the one we want every device on.'],
            ['term' => 'Device platform',    'plain_english' => 'The kind of device — Android phone, iPhone, or web browser. Used to spot platform-specific issues.'],
            ['term' => 'Last seen',          'plain_english' => 'The time of the device’s most recent action that reached the platform. A long time ago means the device has not synced; not necessarily that anything is wrong.'],
        ],

        'wizard' => [
            'launcher_label' => 'What do you want to do?',
            'options' => [
                ['id' => 'check_stuck',     'label' => 'See if any device is stuck',                          'goto_tab' => 'pending',   'summary' => 'Pending Uploads lists devices with data waiting to upload. A small number is normal; a single device with hundreds of pending rows for many hours is unusual.'],
                ['id' => 'old_versions',    'label' => 'See who is running an older app version',            'goto_tab' => 'versions',  'summary' => 'App Versions shows the spread of versions in the field. Devices on older versions are flagged.'],
                ['id' => 'walk_through',    'label' => 'Walk me through this view from the start',           'goto_tab' => 'overview',  'summary' => 'You will be guided through Overview, then Pending Uploads, then App Versions. Three minutes.'],
            ],
        ],

        'charts' => [
            'pending_sparkline' => [
                'title'        => 'Pending-uploads sparkline',
                'shows'        => 'The total count of pending uploads across all devices over the chosen window.',
                'how_to_read'  => 'A single line; vertical scale is pending count; hover for exact numbers.',
                'healthy'      => 'A small, fluctuating count that drops to near zero each day.',
                'concerning'   => 'A line that climbs without coming down — uploads are being created faster than they are being delivered.',
                'what_to_do'   => 'A persistent climb is worth a call to the engineering team. A short climb followed by a drop is usually the field-network catching up after a quiet stretch.',
                'cannot_tell'  => 'It cannot tell you which device is responsible. The Pending Uploads tab has that breakdown.',
                'data_source'  => 'Aggregated from the sync_status column on each mobile-write table.',
            ],
            'platform_mix' => [
                'title'        => 'Device-platform mix',
                'shows'        => 'How many distinct devices are using each platform — Android, iPhone, web.',
                'how_to_read'  => 'One bar per platform; the height is the number of devices.',
                'healthy'      => 'A mix that matches the field deployment plan.',
                'concerning'   => 'A platform that suddenly disappears — most likely a deployment problem on that platform.',
                'what_to_do'   => 'Match the bar against the deployment list. If the bar is lower than expected, ask the field supervisor whether anything has changed.',
                'cannot_tell'  => 'It cannot tell you which operator owns which device.',
                'data_source'  => 'Distinct device identifiers grouped by platform across all mobile-write tables.',
            ],
        ],

        'comparison_columns' => ['When this fits', 'Heads-up', 'Reversible', 'Time'],
        'pre_confirm'        => null,
        'post_action'        => null,
    ],

    /*====================================================================
     | VIEW · sys-who — WHO Connector
     *===================================================================*/
    'who' => [
        'view' => [
            'id'            => 'system.who',
            'title'         => 'WHO connector',
            'purpose'       => 'In future, the platform will send IHR notifications directly to WHO. This screen will show whether those notifications are being delivered. Until that connector is live, this screen is honest about it: there is nothing to monitor yet, and IHR notifications continue to flow through the existing manual channels.',
            'audience'      => 'National administrators preparing for IHR-EIS onboarding and tracking readiness.',
            'prerequisites' => [
                'You understand that this connector is not yet active.',
                'Until the connector is live, IHR notifications go via the existing manual channels — this screen does not change that.',
            ],
            'header_intro'  => 'Planned outbound link to WHO. Currently not connected. Read-only — this screen tracks readiness towards the live cut-over.',
        ],

        'actions' => [
            'notify_me' => [
                'id'              => 'notify_me',
                'label'           => 'Tell me when this is ready',
                'verb'            => 'register',
                'icon'            => 'bell',
                'one_liner'       => 'Record your interest. The engineering team sees the count and will notify you when the connector is enabled.',
                'when_to_use'     => 'When you want to be alerted the moment the connector is live and you can start using it from this screen.',
                'when_not_to_use' => 'Do not use this when you need to send an IHR notification right now — that goes via the existing manual channels.',
                'prerequisites'   => [],
                'consequences'    => [
                    'Your interest is recorded with your name and the time.',
                    'When the connector is enabled, you will receive a notification through the platform’s normal channels.',
                    'The action is recorded in the audit log.',
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => 'Submit again to refresh; the engineering team only counts unique interests.'],
                'visibility'      => ['You.', 'The engineering team (count and timestamp).', 'Audit-log reviewers.'],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => 'If you need an IHR notification dispatched right now, contact the National IHR Focal Point through the existing manual channel.',
            ],
        ],

        'modals' => [],

        'glossary' => [
            ['term' => 'IHR',                'plain_english' => 'International Health Regulations — the WHO framework that obliges member states to report certain public-health events. The platform’s alert-tier rules are written so that Tier-1 alerts meet the IHR Article 6 reporting threshold.'],
            ['term' => 'IHR Focal Point',    'plain_english' => 'The named officer (a National Admin) responsible for sending IHR notifications to WHO. Until the connector is live, the focal point sends notifications through existing manual channels.'],
            ['term' => 'EIS / EIOS',         'plain_english' => 'WHO’s digital systems for receiving notifications and sharing signals with member states. The connector’s job is to talk to these systems automatically.'],
            ['term' => 'Connector',          'plain_english' => 'A software bridge that lets the platform send messages to WHO’s digital systems and receive verified signals back. Not yet built; this screen tracks the steps to building it.'],
            ['term' => 'Readiness',          'plain_english' => 'The list of things that must be true before the connector can be turned on — the focal point seeded, the credentials issued, the keys in place, the runbook written.'],
            ['term' => 'Annex 2',            'plain_english' => 'The decision instrument in the IHR (2005) that helps a country decide whether an event must be reported to WHO. The platform’s alert engine produces the Annex 2 yes/no scores automatically.'],
        ],

        'wizard' => [
            'launcher_label' => 'What do you want to do?',
            'options' => [
                ['id' => 'see_status',      'label' => 'See whether the connector is ready yet',              'goto_tab' => 'status',    'summary' => 'The Status tab shows the readiness checklist. The current state is "not yet active"; the checklist explains exactly what is left to do.'],
                ['id' => 'preview',          'label' => 'See what this screen will look like once it is live','goto_tab' => 'preview',   'summary' => 'The Preview tab shows the chart shapes that will populate. Everything there is clearly marked as a preview.'],
                ['id' => 'register',         'label' => 'Register your interest in being notified',          'goto_tab' => 'status',    'summary' => 'The Register button on the Status tab records your interest with your name. The engineering team sees the count.'],
                ['id' => 'walk_through',    'label' => 'Walk me through this view from the start',           'goto_tab' => 'status',    'summary' => 'You will be guided through Status, then Preview, then Methodology. Two minutes.'],
            ],
        ],

        'charts' => [
            'readiness_ring' => [
                'title'        => 'Readiness ring',
                'shows'        => 'How many readiness checklist items have been ticked.',
                'how_to_read'  => 'The filled portion is the proportion of items completed.',
                'healthy'      => 'A fully-filled ring means every readiness item is ticked and the connector can be enabled.',
                'concerning'   => 'No state of this ring is concerning by itself — until the connector is enabled, the ring simply tracks progress.',
                'what_to_do'   => 'Items are ticked by the engineering team as preparation work completes. Read the checklist for the current state of each item.',
                'cannot_tell'  => 'It cannot tell you when the connector will go live; that is a project-management question, not a screen one.',
                'data_source'  => 'The hardcoded readiness checklist in the contract payload, updated as items complete.',
            ],
        ],

        'comparison_columns' => ['When this fits', 'Heads-up', 'Reversible', 'Time'],
        'pre_confirm'        => null,
        'post_action'        => ['header_success' => 'Your interest is recorded', 'next_step_hint' => 'You will receive a notification when the connector is enabled.'],
    ],
];
