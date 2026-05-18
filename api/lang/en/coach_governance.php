<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Coach Manifest — Governance module
|--------------------------------------------------------------------------
|
| Per-view, deterministic guidance for the National Governance Console.
| Voice: calm, precise, slightly formal, never cheerful — written for a
| registrar, compliance officer, or data-protection officer. Operators
| reading these surfaces are reading legal-defensibility data; the screen
| must leave them with no unanswered questions.
|
| Every action answers the ten questions from the brief:
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
| Concept names that appear in the data (login event, MFA, lockout,
| suppression window, idempotency hit, retention clock, void, etc.)
| each have a glossary entry written for someone who knows what email,
| privacy, and audit mean — and nothing else.
|
*/

return [

    /*--------------------------------------------------------------------
     | Governance-wide glossary (merged into every view's glossary)
     *--------------------------------------------------------------------*/
    'glossary_common' => [
        ['term' => 'Audit log',           'plain_english' => 'A permanent, append-only record of who looked at sensitive data and what they did with it. Lawyers and auditors read this. You cannot edit or delete an entry; you can only add to it.'],
        ['term' => 'Append-only',         'plain_english' => 'A record that can only be added to, never changed or removed. Designed so a future reader can prove what was true at a given moment.'],
        ['term' => 'Scope',               'plain_english' => 'The slice of the system you are allowed to see. National sees everything; province sees their PHEOC area; district and PoE see less. Governance is national-only.'],
        ['term' => 'PII',                 'plain_english' => 'Personally identifiable information — anything that names or can be used to find a real person. Names, phone numbers, emails, passport numbers, dates of birth.'],
        ['term' => 'PII reveal',          'plain_english' => 'A moment when a screen shows a personal detail that is normally hidden. Every reveal is logged with your name, the rows you saw, and the columns that were unmasked.'],
        ['term' => 'PoE',                 'plain_english' => 'Point of Entry — an airport, border post, or seaport where travellers are screened.'],
        ['term' => 'PHEOC',               'plain_english' => 'Public Health Emergency Operations Centre — the province-level command point.'],
        ['term' => 'Idempotency key',     'plain_english' => 'A short code the phone sends with a save so the system can recognise the same save coming in twice and return the original answer instead of writing a duplicate.'],
        ['term' => 'Soft delete',         'plain_english' => 'A row marked as gone but kept in the table for audit. The screens treat soft-deleted rows as voided; the database still holds them.'],
        ['term' => 'Deterministic rule',  'plain_english' => 'A rule that always produces the same answer for the same input. There is no machine-learning, no randomness, no AI rewriting. The rules are written down and listed in this guide.'],
    ],

    /*====================================================================
     | VIEW · gov-auth — Auth Events
     *===================================================================*/
    'auth' => [
        'view' => [
            'id'            => 'governance.auth',
            'title'         => 'Auth events',
            'purpose'       => 'Every sign-in attempt, every multi-factor challenge, every account lockout, every flagged anomaly is recorded here. This is the screen that answers: who has been signing in, who got locked out, who is suspended, where unusual activity is showing up.',
            'audience'      => 'National administrators reviewing platform security and account governance.',
            'prerequisites' => [
                'You know which user, period, or event type you are investigating before you begin.',
                'You have a way to verify the identity of anyone you are about to take action on (a callback line, in-person verification).',
            ],
            'header_intro'  => 'Sign-ins, lockouts, multi-factor events, and anomaly flags. Read-only forensic record. Your view is logged.',
        ],

        'actions' => [
            'view_event' => [
                'id'              => 'view_event',
                'label'           => 'Open event detail',
                'verb'            => 'open',
                'icon'            => 'eye',
                'one_liner'       => 'See full identity, IP, device, and outcome of one sign-in event.',
                'when_to_use'     => 'When you need to verify a single record — investigating a complaint, preparing a brief, supporting a legal request.',
                'when_not_to_use' => 'For trend analysis, use the heatmap and ranking tabs instead.',
                'prerequisites'   => [],
                'consequences'    => ['Read-only. The act of viewing is logged with your name and the time.'],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => null],
                'visibility'      => ['You.', 'National-admin reviewers in the access-audit log.'],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => 'If the row is unclear, do not act on it — return to the list and corroborate with the user’s assignment record before taking any next step.',
            ],
            'clear_anomaly' => [
                'id'              => 'clear_anomaly',
                'label'           => 'Clear anomaly flag',
                'verb'            => 'clear',
                'icon'            => 'check',
                'one_liner'       => 'Mark a flagged anomaly as reviewed and resolved.',
                'when_to_use'     => 'When you have investigated the flag and concluded that the activity is legitimate, or that the underlying issue has been addressed (the user reset their password, the device is now trusted, the unusual sign-in was explained).',
                'when_not_to_use' => 'Do not clear flags you have not investigated. Do not clear a flag because the volume is inconvenient. The flag exists to draw attention; clearing one without review will be visible in the audit trail.',
                'prerequisites'   => ['A note in your records explaining what you investigated and concluded.'],
                'consequences'    => [
                    'The flag row is marked cleared with your name and the time.',
                    'The flag will not appear in the active anomalies list again.',
                    'The original anomaly remains in the historical record; it is not deleted.',
                    'The clearing event is recorded in the access-audit log.',
                ],
                'reversibility'   => ['reversible' => false, 'window_minutes' => null, 'how_to_undo' => 'A cleared flag cannot be re-raised from this surface. If new evidence emerges, open a fresh investigation note in your records and document the change of assessment.'],
                'visibility'      => ['You.', 'National-admin reviewers in the access-audit log.'],
                'estimated_time'  => 'Under a minute.',
                'fallback_action' => 'If you are uncertain whether the activity is legitimate, leave the flag in place and escalate to the security lead.',
            ],
            'export_csv' => [
                'id'              => 'export_csv',
                'label'           => 'Export the visible rows',
                'verb'            => 'export',
                'icon'            => 'download',
                'one_liner'       => 'Download the filtered rows as a CSV with the exact filter and timestamp footer.',
                'when_to_use'     => 'When you are preparing a brief, responding to a request from a regulator, or producing evidence for an investigation.',
                'when_not_to_use' => 'For routine review, the on-screen view is preferred — every export is logged as a separate access event.',
                'prerequisites'   => ['You know what the file will be used for. Once it leaves the system, you are responsible for what happens to it.'],
                'consequences'    => [
                    'A CSV file is generated containing exactly the rows currently shown.',
                    'The file footer carries the scope, the filter set, and the timestamp.',
                    'The export is recorded in the access-audit log as a PII reveal with the columns the file contained.',
                ],
                'reversibility'   => ['reversible' => false, 'window_minutes' => null, 'how_to_undo' => 'A downloaded file cannot be recalled. If you exported the wrong filter, log a note in your records explaining the mistake and dispose of the file.'],
                'visibility'      => ['You.', 'National-admin reviewers in the access-audit log.'],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => 'If you are not sure whether you are authorised to take this data out of the system, do not export. Ask first.',
            ],
        ],

        'modals' => [
            'clear_anomaly' => [
                'id'      => 'clear_anomaly_confirm',
                'title'   => 'Clearing an anomaly flag',
                'purpose' => 'A short note for the audit, then a final confirm.',
                'fields'  => [
                    ['id' => 'note', 'label' => 'What you concluded', 'hint' => 'One or two sentences. Plain words. Recorded permanently.', 'example' => 'User confirmed the sign-in was theirs (call from a known number). Device is being added to trusted list.', 'why_required' => 'Required.', 'validation_human' => 'Give a brief reason of at least 30 characters.'],
                ],
            ],
        ],

        'glossary' => [
            ['term' => 'Sign-in event',       'plain_english' => 'A row written every time someone tries to sign in — successful or not — and every related action like sign-out, password change, multi-factor enrolment.'],
            ['term' => 'Multi-factor (MFA)',  'plain_english' => 'A second proof of identity beyond the password — usually a code from a phone authenticator app. The system records the moment it is offered and whether it was answered correctly.'],
            ['term' => 'Lockout',             'plain_english' => 'The system blocked the account because of too many wrong-password attempts in a short time. The lock lifts automatically after a cool-off; an administrator can lift it sooner.'],
            ['term' => 'Suspended account',   'plain_english' => 'An administrator manually pulled the account from circulation. The person cannot sign in until the suspension is lifted. Different from a lockout.'],
            ['term' => 'Anomaly flag',        'plain_english' => 'A flag raised by a small set of plain-English rules — too many failed sign-ins, sign-ins from unusual countries, role-changes the user did not request. The current rule set is version 1 and pending domain sign-off; the rules themselves are listed in this guide so you can see exactly what is checked.'],
            ['term' => 'Severity',             'plain_english' => 'A rough indication of how seriously to treat the row — INFO is everyday, WARN is worth a glance, ERROR is worth investigating, CRITICAL is worth investigating now.'],
            ['term' => 'IP address',           'plain_english' => 'The numerical address of the network the sign-in came from. Useful for grouping unusual activity, not for identifying a person on its own.'],
            ['term' => 'User agent',           'plain_english' => 'A short text string the browser or app sends that names what kind of device and software was used. Useful for spotting unusual device patterns.'],
        ],

        'comparison_columns' => ['When this fits', 'Heads-up', 'Reversible', 'Time'],
        'pre_confirm'        => ['header' => 'Last look — what is about to happen', 'note' => 'The audit will record exactly what you see below.'],
        'post_action'        => ['header_success' => 'Recorded', 'next_step_hint' => 'What is next: continue your review, or close this and look at the next flagged event.'],
    ],

    /*====================================================================
     | VIEW · gov-notif-log — Delivery Audit
     *===================================================================*/
    'notif-log' => [
        'view' => [
            'id'            => 'governance.notif-log',
            'title'         => 'Delivery audit',
            'purpose'       => 'For every message the system tried to send — alert notification, follow-up reminder, sign-in code, password reset — this surface records who it went to, what the system tried, and what came back. The past-tense record. If a recipient claims they did not receive a message, this is where you confirm the system’s side of the story.',
            'audience'      => 'National administrators investigating delivery, message governance, or recipient PII.',
            'prerequisites' => [
                'You know the time period, recipient, or template you are investigating.',
                'You understand that the recipient details on this surface (email, phone) are PII and your view is logged.',
            ],
            'header_intro'  => 'Per-recipient delivery proof. Did the message leave the building? What did the provider say? Read-only. Your view is logged.',
        ],

        'actions' => [
            'view_delivery' => [
                'id'              => 'view_delivery',
                'label'           => 'Open delivery detail',
                'verb'            => 'open',
                'icon'            => 'eye',
                'one_liner'       => 'See recipient, channel, status, and the provider’s last response.',
                'when_to_use'     => 'When a recipient queries whether a message arrived, or you need to verify a single delivery for a brief.',
                'when_not_to_use' => 'For trend analysis, use the channel split and template ranking tabs.',
                'prerequisites'   => [],
                'consequences'    => ['Read-only. Opening the detail logs a PII reveal because recipient details are unmasked here.'],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => null],
                'visibility'      => ['You.', 'National-admin reviewers in the access-audit log.'],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => 'If the row’s status is unclear, do not act — read it back to the recipient verbatim and let them tell you whether it matches their experience.',
            ],
            'export_csv' => [
                'id'              => 'export_csv',
                'label'           => 'Export the visible rows',
                'verb'            => 'export',
                'icon'            => 'download',
                'one_liner'       => 'Download the filtered delivery rows as a CSV with scope and timestamp footer.',
                'when_to_use'     => 'When preparing a delivery report for the regulator, the WHO contact, or an internal incident review.',
                'when_not_to_use' => 'Routine inspection — the on-screen view is preferred and produces less audit volume.',
                'prerequisites'   => ['You know what the file will be used for and have authority to take it out of the system.'],
                'consequences'    => [
                    'A CSV file is generated containing the visible rows including recipient PII.',
                    'The footer carries scope, filters, and timestamp.',
                    'The export is logged in the access-audit log as a PII reveal listing the recipient columns disclosed.',
                ],
                'reversibility'   => ['reversible' => false, 'window_minutes' => null, 'how_to_undo' => 'A downloaded file cannot be recalled.'],
                'visibility'      => ['You.', 'National-admin reviewers in the access-audit log.'],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => 'If you are not sure whether you are authorised to take this data out of the system, do not export. Ask first.',
            ],
        ],

        'modals' => [],

        'glossary' => [
            ['term' => 'Delivery row',     'plain_english' => 'One attempt to send one message to one recipient. Even an alert that goes to ten people produces ten delivery rows.'],
            ['term' => 'Queued',           'plain_english' => 'The system has accepted the message for sending and put it in line. The provider has not yet been called.'],
            ['term' => 'Sent',             'plain_english' => 'The provider accepted the message from us. This proves the system did its part. It does not, on its own, prove the recipient saw it.'],
            ['term' => 'Failed',           'plain_english' => 'The provider rejected the message at the moment we tried to send it — wrong address, rate-limited, network error. The retry queue may pick this up automatically.'],
            ['term' => 'Bounced',          'plain_english' => 'The provider initially accepted the message but the recipient’s mail server later rejected it. Mailbox full, address unknown, blocked by a filter.'],
            ['term' => 'Skipped',          'plain_english' => 'The system declined to send the message because a suppression rule was active — usually to prevent the same person from receiving the same alert twice in a short window.'],
            ['term' => 'Last error',       'plain_english' => 'The provider’s most recent reason for refusal. Translated to plain English on the screen; the original technical text is available under the Technical Details disclosure.'],
            ['term' => 'Channel',          'plain_english' => 'The pipe the message went through — Email, SMS, or Push notification. Each channel has its own provider and its own kinds of failure.'],
            ['term' => 'Suppression window','plain_english' => 'A time window during which a duplicate of the same message will be silently skipped instead of resent. Each template type has its own window — see the Templates view for the current dial.'],
        ],

        'comparison_columns' => ['When this fits', 'Heads-up', 'Reversible', 'Time'],
        'pre_confirm'        => ['header' => 'Last look — what is about to happen', 'note' => 'Exporting recipient PII is a notable action. The export will be logged with your name and the columns it contained.'],
        'post_action'        => ['header_success' => 'Recorded', 'next_step_hint' => 'What is next: review the next batch, or hand the file to the requester through your secure channel.'],
    ],

    /*====================================================================
     | VIEW · gov-reminders — Reminders & Retry
     *===================================================================*/
    'reminders' => [
        'view' => [
            'id'            => 'governance.reminders',
            'title'         => 'Reminders & retry',
            'purpose'       => 'The future-tense counterpart to the delivery audit. What is scheduled to fire, what is currently suppressed and why, what will retry, what is overdue. The retry cron runs every fifteen minutes; this surface observes its state — it does not trigger the cron.',
            'audience'      => 'National administrators monitoring scheduled communications and the retry pipeline.',
            'prerequisites' => [
                'You understand that this view is read-only — the cron is the only path that actually sends.',
                'You know the alert or template you are investigating.',
            ],
            'header_intro'  => 'Scheduled follow-ups, active suppression windows, the retry queue. Read-only. The retry cron runs every fifteen minutes and is the only path that sends.',
        ],

        'actions' => [
            'view_followup' => [
                'id'              => 'view_followup',
                'label'           => 'Open follow-up detail',
                'verb'            => 'open',
                'icon'            => 'eye',
                'one_liner'       => 'See the alert, the recipient, the due date, and the suppression context.',
                'when_to_use'     => 'When a follow-up appears overdue and you need to understand why it has not gone yet, or whether a suppression window is holding it back.',
                'when_not_to_use' => 'For broad trend review, the per-alert and per-recipient tabs are faster.',
                'prerequisites'   => [],
                'consequences'    => ['Read-only. Opening the detail logs an access event because the recipient line is PII.'],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => null],
                'visibility'      => ['You.', 'National-admin reviewers in the access-audit log.'],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => 'If you believe a follow-up is overdue but the system disagrees, do not improvise — confirm the cron’s last run on the Cron Status surface, then re-evaluate.',
            ],
        ],

        'modals' => [],

        'glossary' => [
            ['term' => 'Follow-up',         'plain_english' => 'A scheduled reminder linked to an alert — a check-in expected at a future date so a case is not forgotten. Each follow-up has a due date and a recipient.'],
            ['term' => 'Due',               'plain_english' => 'The follow-up has reached its scheduled date. The retry cron will pick it up at the next fifteen-minute tick.'],
            ['term' => 'Overdue',           'plain_english' => 'The follow-up was due and has not yet been sent or actioned. Worth investigating; the most common reason is an active suppression window.'],
            ['term' => 'Suppression window','plain_english' => 'The system declines to send the same message to the same recipient inside a configured time window — to prevent a person from receiving the same alert twice within a short period. The dial is set per template (see the Templates view); these are version-1 deterministic rules pending domain sign-off.'],
            ['term' => 'Retry queue',       'plain_english' => 'Delivery rows that failed and are waiting for the cron to attempt them again. The cron runs every fifteen minutes and stops trying after four attempts.'],
            ['term' => 'Last notified',     'plain_english' => 'The most recent time the system actually sent any message to this recipient. Used by the suppression rule to decide whether to send another one.'],
        ],

        'comparison_columns' => ['When this fits', 'Heads-up', 'Reversible', 'Time'],
        'pre_confirm'        => null,
        'post_action'        => null,
    ],

    /*====================================================================
     | VIEW · gov-templates — Notification Templates
     *===================================================================*/
    'templates' => [
        'view' => [
            'id'            => 'governance.templates',
            'title'         => 'Notification templates',
            'purpose'       => 'The catalogue of message templates the system uses. Each template has a fixed body, a fixed subject, a list of variables the dispatcher fills in, and a suppression window. Templates are part of a calibrated WHO-aligned response set; the body and subject are not editable from this surface — only the active flag can be toggled. A preview renders a sample message.',
            'audience'      => 'National administrators auditing message governance and verifying template wording.',
            'prerequisites' => [
                'You understand that template body and subject are deliberately not editable here — drift in calibrated responses is a regulatory risk.',
                'If the underlying wording must change, that is a separate change-control process outside this surface.',
            ],
            'header_intro'  => 'Catalogue of message templates. Read-mostly. You can toggle a template active or inactive; you cannot edit body or subject from here.',
        ],

        'actions' => [
            'view_template' => [
                'id'              => 'view_template',
                'label'           => 'Open template detail',
                'verb'            => 'open',
                'icon'            => 'eye',
                'one_liner'       => 'See the body, subject, variables, and current suppression window.',
                'when_to_use'     => 'When verifying that a template’s wording matches the calibrated reference, or preparing a brief on which templates fire under what conditions.',
                'when_not_to_use' => 'To change wording — wording is not editable here. That is a separate change-control process.',
                'prerequisites'   => [],
                'consequences'    => ['Read-only. The view is logged.'],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => null],
                'visibility'      => ['You.', 'National-admin reviewers in the access-audit log.'],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => 'If a template does not match the calibrated reference, do not silently accept it — escalate through change control.',
            ],
            'preview_template' => [
                'id'              => 'preview_template',
                'label'           => 'Render a preview',
                'verb'            => 'preview',
                'icon'            => 'eye',
                'one_liner'       => 'Render the template against sample variables to see what a recipient would actually receive.',
                'when_to_use'     => 'When verifying that the variables produce a correctly-worded message, or showing a stakeholder what an alert email looks like.',
                'when_not_to_use' => 'No real message is sent during preview; it is a rendering exercise only. Do not use the preview to validate that delivery works — that is what the test-send command is for.',
                'prerequisites'   => [],
                'consequences'    => [
                    'A rendered version of the template is shown on screen with the sample variables filled in.',
                    'No message is sent. No row is written to the delivery audit.',
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => null],
                'visibility'      => ['You.'],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => null,
            ],
            'toggle_active' => [
                'id'              => 'toggle_active',
                'label'           => 'Activate or deactivate',
                'verb'            => 'toggle',
                'icon'            => 'switch',
                'one_liner'       => 'Decide whether the dispatcher will use this template at all.',
                'when_to_use'     => 'When retiring a template that is no longer accurate, or bringing one back into circulation after a refreshed wording has been signed off.',
                'when_not_to_use' => 'To prevent a single recipient from receiving messages — that is a suppression matter, not a template matter.',
                'prerequisites'   => ['A note in your records explaining the change of state.'],
                'consequences'    => [
                    'An inactive template is skipped by the dispatcher; the corresponding alerts will not produce messages of this type.',
                    'Existing queued messages of this template continue to send unless cancelled.',
                    'The toggle is recorded in the access-audit log.',
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => 'Toggle it back. The body and subject are unchanged.'],
                'visibility'      => ['You.', 'National-admin reviewers in the access-audit log.'],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => 'If you are uncertain whether deactivation is the right call, leave it active and raise the question with change control.',
            ],
        ],

        'modals' => [],

        'glossary' => [
            ['term' => 'Template',          'plain_english' => 'A reusable message with placeholders for the parts that change per recipient. The dispatcher fills the placeholders at send time.'],
            ['term' => 'Variable',          'plain_english' => 'A placeholder in the template that gets replaced at send time — for example, the recipient’s name, the alert reference, or the deadline. Written as a curly-brace token in the body.'],
            ['term' => 'Subject',           'plain_english' => 'The single line that appears as the email subject. Templates have one subject; SMS messages do not use a subject.'],
            ['term' => 'Body',              'plain_english' => 'The text of the message itself. Plain text or simple HTML. Not editable from this surface — change control owns the wording.'],
            ['term' => 'AI enhancement',    'plain_english' => 'A flag on each template that will, in a future version, opt the template into automatic body enrichment. It is currently inert: messages render exactly from the template, no AI rewriting is applied.'],
            ['term' => 'Suppression window','plain_english' => 'The minimum time the dispatcher will wait before sending the same template to the same recipient again. Set per template; deterministic; version 1 pending domain sign-off.'],
        ],

        'comparison_columns' => ['When this fits', 'Heads-up', 'Reversible', 'Time'],
        'pre_confirm'        => ['header' => 'Last look — what is about to happen', 'note' => 'A toggle change records who decided and when. Existing queued messages of this template continue to send unless cancelled separately.'],
        'post_action'        => ['header_success' => 'Recorded', 'next_step_hint' => 'What is next: confirm the new state by previewing the template, or close this and review the next entry.'],
    ],

    /*====================================================================
     | VIEW · gov-dq — Data Quality
     *===================================================================*/
    'dq' => [
        'view' => [
            'id'            => 'governance.dq',
            'title'         => 'Data quality',
            'purpose'       => 'The operational health of the data itself, across every table the mobile app writes. Voids (rows the field deleted), duplicates (the same record submitted twice), late syncs (records that took more than an hour to reach the server), idempotency hits (retries the system caught and answered correctly), and the sync-failure backlog. A high number is a signal, not a verdict — every count carries a caveat in this guide.',
            'audience'      => 'National administrators monitoring the platform’s data hygiene.',
            'prerequisites' => [
                'You know which table or POE you are investigating, or you are reading the system-wide rollup.',
                'You read the caveat panels on each tab — the numbers are honest only if the caveats are read alongside them.',
            ],
            'header_intro'  => 'Operational health of the data itself. Counts come with caveats; read them.',
        ],

        'actions' => [
            'view_table' => [
                'id'              => 'view_table',
                'label'           => 'Open per-table breakdown',
                'verb'            => 'open',
                'icon'            => 'eye',
                'one_liner'       => 'See the void rate, late-sync rate, and duplicate count for one table.',
                'when_to_use'     => 'When investigating a specific data domain — primary screenings, secondary screenings, alerts, follow-ups.',
                'when_not_to_use' => 'For a high-level read, the system rollup tab is faster and avoids small-number distractions.',
                'prerequisites'   => [],
                'consequences'    => ['Read-only. The view is logged.'],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => null],
                'visibility'      => ['You.', 'National-admin reviewers in the access-audit log.'],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => 'If a table looks unusually quiet, check the Mobile Health surface — devices may not be syncing, in which case nothing is wrong with the data; the data simply is not arriving.',
            ],
        ],

        'modals' => [],

        'glossary' => [
            ['term' => 'Void',              'plain_english' => 'A row marked as deleted by the field operator — usually after they noticed they entered the wrong record. The row stays in the database for audit; it is hidden from operational lists. The void rate is voided rows divided by total rows for that table.'],
            ['term' => 'Duplicate',         'plain_english' => 'Two or more rows in the same table that share the same client identifier. The system enforces this identifier as unique on insert; a duplicate appearing here means an unusual condition occurred and is worth investigating. A count of zero is the expected, healthy state.'],
            ['term' => 'Late sync',         'plain_english' => 'A row that was created more than one hour before it reached the server. Common during fieldwork in low-connectivity areas; not necessarily a problem. The threshold is set at one hour and is hardcoded.'],
            ['term' => 'Sync-failure backlog','plain_english' => 'Rows whose sync status is FAILED — the phone tried to send them and the server rejected them. These need investigation; they are not lost data, but they are stuck.'],
            ['term' => 'Idempotency hit',   'plain_english' => 'The framework caught a save coming in twice with the same idempotency key and returned the original answer. This is good news: the system caught a retry it was supposed to catch.'],
            ['term' => 'Mobile retry count','plain_english' => 'A counter on each row showing how many times the phone tried to send it before the server confirmed acceptance. A high number means the connection was flaky; the row arrived correctly anyway.'],
            ['term' => 'Small-number suppression','plain_english' => 'When a count is below five, we display "fewer than 5" instead of the exact number. Tiny counts are statistically unstable and easy to misread.'],
        ],

        'comparison_columns' => ['When this fits', 'Heads-up', 'Reversible', 'Time'],
        'pre_confirm'        => null,
        'post_action'        => null,
    ],

    /*====================================================================
     | VIEW · gov-retention — Retention & PII
     *===================================================================*/
    'retention' => [
        'view' => [
            'id'            => 'governance.retention',
            'title'         => 'Retention & PII',
            'purpose'       => 'Where personally identifiable information lives, how long it can stay, what is approaching the retention limit, and a complete record of every export of personal data. The system holds named-traveller PII in one place — the secondary-screening records — and the retention default is seven years, in line with the WHO case-data guidance. Subject access requests and legal-hold extensions are not yet supported from this surface; the guide explains the operational fallback.',
            'audience'      => 'National administrators and the data-protection officer.',
            'prerequisites' => [
                'You understand that this surface shows real personal information and that every reveal is logged.',
                'You have an authoritative reason for any export — a regulator request, a subject access request, an internal investigation.',
                'You know what you will do with a downloaded file before you click the export button.',
            ],
            'header_intro'  => 'Where personal information lives, how long it stays, what is due for removal. Every reveal and every export is logged with your name.',
        ],

        'actions' => [
            'view_record' => [
                'id'              => 'view_record',
                'label'           => 'Open a record',
                'verb'            => 'open',
                'icon'            => 'eye',
                'one_liner'       => 'See one secondary screening including named-traveller fields.',
                'when_to_use'     => 'When you have a verified reason to look at a single individual’s record — a request from the named person, a regulator query, an investigation.',
                'when_not_to_use' => 'Browsing. The retention-clock and coverage tabs answer most operational questions without revealing names.',
                'prerequisites'   => ['A note in your records explaining the reason you opened the record.'],
                'consequences'    => ['The view is recorded in the access-audit log as a PII reveal listing the columns you saw.'],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => 'A view cannot be unseen. The audit row stays.'],
                'visibility'      => ['You.', 'National-admin reviewers in the access-audit log.'],
                'estimated_time'  => 'A minute.',
                'fallback_action' => 'If you are not sure you should be looking at this person’s record, do not open it — confirm authorisation first.',
            ],
            'export_pii' => [
                'id'              => 'export_pii',
                'label'           => 'Export records',
                'verb'            => 'export',
                'icon'            => 'download',
                'one_liner'       => 'Download a file containing personal information of one or more travellers.',
                'when_to_use'     => 'When fulfilling a subject access request, supporting a regulator, or producing evidence for an investigation under written authority.',
                'when_not_to_use' => 'Routine review. Anything routine should be done on screen.',
                'prerequisites'   => [
                    'A written authorisation or a verified subject access request.',
                    'A justification of at least thirty characters that you are willing to have read back to you in court.',
                    'Typed confirmation of the action — the operator types the specific phrase the screen presents.',
                    'A secure place to put the file once it leaves the system.',
                ],
                'consequences'    => [
                    'The export is recorded in the access-audit log before the file is generated, so no successful download is unaudited.',
                    'The file footer carries the scope, the filter set, the timestamp, and your justification.',
                    'The file leaves the system and you are responsible for what happens to it next.',
                ],
                'reversibility'   => ['reversible' => false, 'window_minutes' => null, 'how_to_undo' => 'A downloaded file cannot be recalled.'],
                'visibility'      => ['You.', 'The data-protection officer, on review of the export log.', 'National-admin reviewers in the access-audit log.'],
                'estimated_time'  => 'Two to three minutes including the justification.',
                'fallback_action' => 'If you are unsure whether you should be exporting at all, stop. Ask the data-protection officer in writing first.',
            ],
        ],

        'modals' => [
            'export' => [
                'id'      => 'export_pii_confirm',
                'title'   => 'Exporting personal information',
                'purpose' => 'A justification you are willing to have read back to you, and a typed confirmation that you understand what you are doing.',
                'fields'  => [
                    ['id' => 'justification', 'label' => 'Why this export, in your own words', 'hint' => 'Plain language. At least thirty characters. Recorded in the audit log next to your name.', 'example' => 'Subject access request from R. Banda, received 2026-04-25, reference SAR-019. Records to be sent to her solicitor at her instruction.', 'why_required' => 'Required.', 'validation_human' => 'Give a justification of at least thirty characters.'],
                    ['id' => 'typed_confirm',  'label' => 'Type EXPORT to confirm',           'hint' => 'Typed confirmation slows the action down and creates an unambiguous record of intent.', 'example' => 'EXPORT', 'why_required' => 'Required.', 'validation_human' => 'Type the word EXPORT exactly as shown.'],
                ],
            ],
        ],

        'glossary' => [
            ['term' => 'PII home',          'plain_english' => 'In this system, secondary screenings are the only table that holds named-traveller information — full name, passport, date of birth, contact details. Other tables refer to people by anonymous codes.'],
            ['term' => 'Retention clock',   'plain_english' => 'A countdown that begins when a record is created and runs for the retention period. When it expires, the record is due for removal. The default period here is seven years, in line with WHO case-data guidance.'],
            ['term' => 'Due for removal',   'plain_english' => 'Records whose retention clock has expired and which should be reviewed for deletion or anonymisation. Removal is not automatic from this surface; it is a deliberate operational step.'],
            ['term' => 'Export log',        'plain_english' => 'An append-only record of every download of personal information from any surface in this system. Visible to national administrators and the data-protection officer.'],
            ['term' => 'Subject access request', 'plain_english' => 'A formal request from a named person to receive a copy of the personal information the system holds about them. The platform does not yet have a workflow for these from this surface; the guide describes the operational route.'],
            ['term' => 'Legal hold',        'plain_english' => 'A formal instruction — usually from a court or a regulator — that records relating to a matter must not be deleted until the hold is lifted, even if their retention clock expires. The platform does not yet enforce legal holds from this surface.'],
            ['term' => 'Justification',     'plain_english' => 'A short note from you explaining why you are taking the action. Recorded permanently next to your name. Should be plain enough that a future reader who knows nothing about today understands what you were doing and why.'],
        ],

        'comparison_columns' => ['When this fits', 'Heads-up', 'Reversible', 'Time'],
        'pre_confirm' => [
            'header' => 'About to export personal information',
            'note'   => 'The export is logged before the file is generated, so no successful download is unaudited. Once the file leaves the system, you are responsible for what happens to it.',
        ],
        'post_action' => [
            'header_success' => 'Export recorded',
            'next_step_hint' => 'What is next: deliver the file through your secure channel, or save it to the controlled location named in the authorising instruction. Do not relay it through email or open chat.',
        ],
    ],

];
