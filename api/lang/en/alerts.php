<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Plain-English copy for the National Admin Alerts panel.
|--------------------------------------------------------------------------
|
| Every string a user might read in the admin panel lives here so the views
| never see raw codes (PENDING, TIER_1, LINE_LIST, IHR…). Keys are by intent,
| never by enum value, so future Bemba/Nyanja translations slot straight in.
|
| Reading level: literate but assume English is a second language for many.
| No clinical jargon. No abbreviations. No code-shaped tokens.
|
*/

return [

    'app' => [
        'name'        => 'Public Health Alerts',
        'short'       => 'Alerts',
        'description' => 'Track and close alerts from start to finish.',
    ],

    /*
    | Action codes — the 14 follow-up steps. Each gets a clear title (the
    | question we ask the officer), a short answer prompt, and a one-line
    | "why this matters" the wizard surfaces inline.
    */
    'action' => [

        'CASE_INVESTIGATION' => [
            'title' => 'Has the case been properly looked into?',
            'short' => 'Investigate the case and confirm the basics.',
            'why'   => 'Without a quick look at the patient and how they got here, no one can decide what to do next.',
            'icon'  => 'clipboard-check',
        ],

        'ISOLATION' => [
            'title' => 'Has the patient been kept apart from other people?',
            'short' => 'Move the patient to a single room with the right protective equipment.',
            'why'   => 'If the illness can spread by touch or air, every minute the patient is mixed with others, more people are exposed.',
            'icon'  => 'shield-alert',
        ],

        'CONTACT_LISTING' => [
            'title' => 'Have the people in close contact with the patient been written down?',
            'short' => 'List names, phone numbers, and where to find each contact.',
            'why'   => 'If we do not know who they touched, we cannot warn the people who may already be sick.',
            'icon'  => 'users',
        ],

        'CONTACT_TRACING' => [
            'title' => 'Are the close contacts being checked every day?',
            'short' => 'A team is calling or visiting each contact daily.',
            'why'   => 'Early checks catch the next case before it spreads further.',
            'icon'  => 'user-check',
        ],

        'LAB_SPECIMENS' => [
            'title' => 'Have samples been taken and sent to the laboratory?',
            'short' => 'The right samples are on their way to the lab.',
            'why'   => 'Without a sample, the lab cannot say what the illness is.',
            'icon'  => 'flask-conical',
        ],

        'LAB_CONFIRMATION' => [
            'title' => 'Has the laboratory given an answer?',
            'short' => 'The lab has reported a result we can act on.',
            'why'   => 'A lab answer tells us if our response should grow, change, or stop.',
            'icon'  => 'microscope',
        ],

        'LINE_LIST' => [
            'title' => 'Is there one shared list of every known case so far?',
            'short' => 'A single, up-to-date list everyone uses.',
            'why'   => 'Without one shared list, teams duplicate work and lose track of cases.',
            'icon'  => 'list-checks',
        ],

        'RISK_COMMS' => [
            'title' => 'Have we told the public what to do?',
            'short' => 'A clear public message is going out (radio, posters, community meetings).',
            'why'   => 'When the public knows the signs and what to do, they come for help sooner and protect their families.',
            'icon'  => 'megaphone',
        ],

        'IPC' => [
            'title' => 'Is the health facility working safely?',
            'short' => 'Staff have soap, gloves, masks, and a separate area for sick people.',
            'why'   => 'Hospitals are where outbreaks often grow. If the facility is not safe, it makes everything worse.',
            'icon'  => 'spray-can',
        ],

        'VECTOR_CONTROL' => [
            'title' => 'Are we acting on the mosquitoes, ticks, or other insects involved?',
            'short' => 'Spraying, nets, or another method is in motion.',
            'why'   => 'For some illnesses, the only way to stop new cases is to deal with what carries them.',
            'icon'  => 'bug',
        ],

        'POE_SURVEILLANCE' => [
            'title' => 'Are the borders watching for more cases?',
            'short' => 'Border points are checking travellers for signs of the same illness.',
            'why'   => 'If the case came through a border, more may follow. We need eyes on it.',
            'icon'  => 'shield',
        ],

        'EOC_ACTIVATION' => [
            'title' => 'Has the response team been formally activated?',
            'short' => 'The province or national operations centre is running this case.',
            'why'   => 'Without one team in charge, decisions stall and partners do not know who to call.',
            'icon'  => 'siren',
        ],

        'WHO_NOTIFICATION' => [
            'title' => 'Have international health partners been told?',
            'short' => 'The right people outside the country know about this case.',
            'why'   => 'For some illnesses we are required to tell partners within 24 hours so they can help and prepare.',
            'icon'  => 'globe',
        ],

        'RESOURCE_MOBILISATION' => [
            'title' => 'Have we asked for the people, money, and supplies we need?',
            'short' => 'Equipment, transport, and staff are on their way.',
            'why'   => 'A team without supplies cannot do the job, no matter how willing they are.',
            'icon'  => 'package',
        ],

    ],

    /*
    | Internal status codes mapped to plain-English labels and a tone hint
    | the views use to colour the chip. Tones:
    |   urgent  — must do now (red)
    |   watch   — in flight (amber)
    |   done    — completed (green)
    |   skipped — not applicable here (grey)
    */
    'status' => [
        'PENDING'        => ['label' => 'Not started yet',     'tone' => 'urgent'],
        'IN_PROGRESS'    => ['label' => 'In progress',         'tone' => 'watch'],
        'BLOCKED'        => ['label' => 'Stuck — needs help',  'tone' => 'urgent'],
        'COMPLETED'      => ['label' => 'Done',                'tone' => 'done'],
        'NOT_APPLICABLE' => ['label' => 'Does not apply here', 'tone' => 'skipped'],
    ],

    /*
    | Alert lifecycle states.
    */
    'alert_status' => [
        'OPEN'         => ['label' => 'New — needs attention', 'tone' => 'urgent'],
        'ACKNOWLEDGED' => ['label' => 'Being worked on',       'tone' => 'watch'],
        'CLOSED'       => ['label' => 'Closed',                'tone' => 'done'],
        'REOPENED'     => ['label' => 'Reopened',              'tone' => 'watch'],
    ],

    /*
    | Risk levels — never shown as the raw word.
    */
    'risk_level' => [
        'CRITICAL' => ['label' => 'Top priority',          'tone' => 'urgent', 'short' => 'Top priority'],
        'HIGH'     => ['label' => 'Serious',               'tone' => 'urgent', 'short' => 'Serious'],
        'MEDIUM'   => ['label' => 'Worth watching',        'tone' => 'watch',  'short' => 'Watch'],
        'LOW'      => ['label' => 'Low concern for now',   'tone' => 'info',   'short' => 'Low'],
    ],

    /*
    | International notification tier — translated to plain priority words.
    | Sources:
    |   ref_diseases.ihr_tier  (1 / 2 / 3 numeric)
    |   DiseaseIntel ihr_tier  (TIER_1_ALWAYS_NOTIFIABLE / ANNEX2_EPIDEMIC_PRONE / WHO_NOTIFIABLE / SYNDROMIC)
    */
    'tier' => [
        'top'    => ['label' => 'Top priority — must report internationally', 'dot' => 'red',   'short' => 'Top priority'],
        'high'   => ['label' => 'Should be reported internationally',         'dot' => 'amber', 'short' => 'High priority'],
        'normal' => ['label' => 'Routine reporting',                          'dot' => 'grey',  'short' => 'Routine'],
    ],

    /*
    | Routing levels — who currently owns the alert.
    */
    'routed_to' => [
        'POE'      => 'Border point team',
        'DISTRICT' => 'District team',
        'PHEOC'    => 'Province response centre',
        'NATIONAL' => 'National response centre',
    ],

    /*
    | Close categories — verbatim user-facing labels for the closure form.
    | Maps to api/app/Http/Controllers/AlertsController::CLOSE_CATEGORIES.
    */
    'close_category' => [
        'RESOLVED'                   => ['label' => 'Resolved through response',           'help' => 'We dealt with the case and the situation is under control.'],
        'FALSE_POSITIVE'             => ['label' => 'It was not a real case (false alarm)', 'help' => 'After looking into it, we found this was raised in error.'],
        'DUPLICATE'                  => ['label' => 'Same case as another alert',          'help' => 'This alert is the same case as another one already being handled.'],
        'LOST_TO_FOLLOWUP'           => ['label' => 'We could not reach the person again', 'help' => 'The person stopped responding and we cannot find them.'],
        'TRANSFERRED_OUT_OF_COUNTRY' => ['label' => 'The person left the country',         'help' => 'Care has been handed over to another country.'],
        'DECEASED'                   => ['label' => 'The person passed away',              'help' => 'The patient died — close with respect and complete the record.'],
        'OTHER'                      => ['label' => 'Other reason',                        'help' => 'Tell us briefly in the note below.'],
    ],

    /*
    | Disease group headlines for the master alerts list.
    | Used by HumanLabels::diseaseHeadline() which falls back gracefully.
    */
    'disease_group' => [
        'vhf'                  => 'Suspected viral haemorrhagic fever',
        'cholera_diarrhoeal'   => 'Suspected cholera or severe diarrhoea',
        'novel_respiratory'    => 'Suspected new respiratory illness',
        'measles_family'       => 'Suspected vaccine-preventable illness',
        'vector_borne'         => 'Suspected mosquito or insect-borne illness',
        'meningitis'           => 'Suspected meningitis',
        'plague'               => 'Suspected plague',
        'zoonotic'             => 'Suspected illness from animals',
        'foodborne'            => 'Suspected food or water-borne illness',
        'seasonal_flu'         => 'Suspected seasonal flu',
        'syndromic_unknown'    => 'Unidentified illness — under review',
    ],

    /*
    | Confidence words — translates 0–100 percent into plain ranking words
    | for the multi-disease ranked list on each alert row.
    */
    'confidence' => [
        'most_likely' => 'Most likely',
        'possible'    => 'Possible',
        'less_likely' => 'Less likely',
    ],

    /*
    | Wizard surface copy — the shell, prompts, and decision chips.
    */
    'wizard' => [

        'header' => [
            'time_since' => ':amount since this alert came in',
            'top_action' => 'Next step',
        ],

        'gateway' => [
            'title'        => 'What do you want to do with this case?',
            'subtitle'     => 'Pick the option that fits where you are.',
            'options'      => [
                'walk_through'   => [
                    'label' => 'Walk me through closing this case',
                    'help'  => 'I will ask you one clear question at a time, in the right order.',
                    'icon'  => 'route',
                ],
                'see_file'       => [
                    'label' => 'Just show me the case details',
                    'help'  => 'Open the full file. I will not ask you to make any decisions.',
                    'icon'  => 'file-search',
                ],
                'reassign'       => [
                    'label' => 'Hand this case to someone else',
                    'help'  => 'Pass it to another officer or team.',
                    'icon'  => 'user-plus',
                ],
                'escalate'       => [
                    'label' => 'Send this case higher up',
                    'help'  => 'Move it to the province or national centre for help.',
                    'icon'  => 'trending-up',
                ],
                'master_close'   => [
                    'label' => 'Close this case on behalf of the field team',
                    'help'  => 'For national admins only. You will record what was done by phone or on the ground.',
                    'icon'  => 'shield-check',
                ],
                'false_alarm'    => [
                    'label' => 'After investigation, this turned out to be a false alarm',
                    'help'  => 'Mark all the open steps as not applicable in one go and close the case.',
                    'icon'  => 'circle-x',
                ],
            ],
        ],

        'step' => [
            'why_label'     => 'Why this matters',
            'evidence_label'=> 'If you can, attach proof',
            'help_label'    => 'I need help with this — contact someone',
            'option_yes'    => 'Yes, this is done',
            'option_doing'  => 'We are working on it now',
            'option_na'     => 'This does not apply here',
            'option_help'   => 'I need help — contact someone',
            'na_reason'     => 'Why does this not apply? (a short reason)',
            'next'          => 'Next step',
        ],

        'sweep' => [
            'title'      => 'Mark all open steps as not applicable',
            'subtitle'   => 'You are about to confirm there is no real case here. Tell us why so the record is clear.',
            'reason'     => 'Why is this a false alarm? (at least :min characters)',
            'confirm'    => 'Yes, mark them all and close the case',
            'cancel'     => 'No, take me back',
            'note_template' => 'Marked not applicable as part of a false-alarm closure: :reason',
        ],

        'closure' => [
            'title'              => 'Ready to close this case',
            'subtitle'           => 'Check the summary below, choose a reason, and close.',
            'category_label'     => 'Why are you closing it?',
            'note_label'         => 'Anything we should record? (required if you picked "Other reason")',
            'duplicate_label'    => 'Which alert is this a duplicate of?',
            'override_label'     => 'You are closing this on behalf of the team. Tell us briefly why.',
            'override_min'       => 'Please explain in at least :min characters.',
            'submit'             => 'Close the case',
            'submit_master'      => 'Close on behalf of the team',
        ],

        'stakeholders' => [
            'title'      => 'Who is involved',
            'notified'   => 'We told them',
            'responded'  => 'They responded',
            'silent'     => 'No reply yet',
            'resend'     => 'Send the message again',
            'call'       => 'Mark that you spoke by phone',
            'ask_new'    => 'Ask someone new',
            'silent_help' => ':count contact(s) have not replied. You can resend or speak by phone.',
        ],

        'progress' => [
            'title'         => 'How this case is progressing',
            'next_marker'   => 'Next',
            'done_marker'   => 'Done',
            'na_marker'     => 'Skipped',
            'pending_marker'=> 'Waiting',
        ],

    ],

    /*
    | Time relative phrases. Used by HumanLabels::dueHuman().
    */
    'due' => [
        'overdue_short'   => 'overdue',
        'overdue_amount'  => 'overdue by :amount',
        'in_amount'       => 'in :amount',
        'no_due'          => 'no deadline set',
        'just_now'        => 'just now',
    ],

    /*
    | Generic error envelope copy. Codes map to AlertEnvelope::err().
    */
    'error' => [
        'generic'             => ['code' => 'GENERIC_ERROR',         'human' => 'Something went wrong on our side. Try again, or ask for help.'],
        'validation'          => ['code' => 'INPUT_NEEDS_FIXING',    'human' => 'Some of the information needs fixing. Please look at the highlighted fields.'],
        'forbidden'           => ['code' => 'NOT_ALLOWED_HERE',      'human' => 'You do not have permission to do this for this alert.'],
        'not_found'           => ['code' => 'NOT_FOUND',             'human' => 'We could not find what you were looking for.'],
        'idempotent_replay'   => ['code' => 'ALREADY_DONE',          'human' => 'You already did this once. Showing the same result.'],
        'closure_blocked'     => ['code' => 'STILL_HAS_OPEN_STEPS',  'human' => 'There are still open steps that must be done or marked not applicable before this case can close.'],
        'override_too_short'  => ['code' => 'NEED_MORE_DETAIL',      'human' => 'Please give us a longer explanation (at least :min characters).'],
        'duplicate_target'    => ['code' => 'DUPLICATE_NEEDS_TARGET','human' => 'Tell us which alert this one is a duplicate of.'],
        'note_required'       => ['code' => 'NOTE_REQUIRED',         'human' => 'Please add a short note explaining the reason.'],
        'invalid_state'       => ['code' => 'WRONG_TIME_FOR_THIS',   'human' => 'This action is not available right now for this case.'],
        'concurrent'          => ['code' => 'SOMEONE_ELSE_CHANGED_IT','human' => 'Someone else updated this case at the same time. Please refresh and try again.'],
    ],

];
