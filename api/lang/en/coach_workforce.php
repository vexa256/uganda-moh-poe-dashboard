<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Coach Manifest — Workforce module
|--------------------------------------------------------------------------
|
| Per-view, deterministic guidance for the surveillance workforce admin.
| Voice: brisk, calm, operational, second-person — written for UNIPH /
| PHEOC officers and national admins running the roster, not for end
| users. Never cheerful, never bureaucratic.
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
| Surface headings render in domain-native voice, not slot names.
| Strings live here. Views never hardcode coach text.
|
*/

return [

    /*--------------------------------------------------------------------
     | Workforce-wide glossary (loaded into every view)
     *--------------------------------------------------------------------*/
    'glossary_common' => [
        ['term' => 'Role',                'plain_english' => 'What kind of work the person does in the system. Each role hands out a fixed set of permissions; you cannot change those permissions per person.'],
        ['term' => 'Account type',        'plain_english' => 'Where the account sits in the org chart — National, Province / PHEOC, District, Point of Entry, Observer, or Service.'],
        ['term' => 'Scope',               'plain_english' => 'How far this person can see and act. National sees everything; PHEOC sees their province and below; District sees their district and below; PoE sees only their port.'],
        ['term' => 'Primary assignment',  'plain_english' => "The jurisdiction the system uses by default when this person signs in. They can have more, but only one is primary."],
        ['term' => 'PoE',                 'plain_english' => "Point of Entry — an airport, border post, or seaport where travellers are screened."],
        ['term' => 'PHEOC',               'plain_english' => "Public Health Emergency Operations Centre — the province-level command point."],
        ['term' => 'MFA',                 'plain_english' => "Multi-factor authentication. The person enters a second code from their phone after their password."],
        ['term' => 'Locked',              'plain_english' => "The system locked the account because of too many failed sign-in attempts. They cannot sign in until you unlock or the timer runs out."],
        ['term' => 'Suspended',           'plain_english' => "You manually pulled this person's access. They cannot sign in until you reactivate them. Their record stays."],
        ['term' => 'Pending invite',      'plain_english' => "An invitation link is out but the person has not accepted yet. The account cannot sign in until they do."],
        ['term' => 'Dormant',             'plain_english' => "The person has not signed in for 30 days or more. Often means they have moved on; worth checking."],
        ['term' => 'Audit log',           'plain_english' => "An append-only record of every change made to a person's account. You cannot edit it; it is what auditors will read."],
        ['term' => 'Capability',          'plain_english' => "One specific thing a role can do — read national data, close alerts, submit screenings, and so on."],
    ],

    /*====================================================================
     | VIEW · Users
     *===================================================================*/
    'users' => [
        'view' => [
            'id'            => 'workforce.users',
            'title'         => 'Workforce roster',
            'purpose'       => 'This is the full list of people with sign-in access to the National Public Health Emergency Operations Centre — national, province, district, and point-of-entry. Add or invite people here, hand out roles, assign jurisdictions, and pull access when you have to.',
            'audience'      => 'National admins and PHEOC officers running the surveillance roster.',
            'prerequisites' => [
                "The person's full name and a working email or phone number.",
                "The role you intend to give them — pick one that matches what they will actually do, not their job title.",
                "A way to reach them out-of-band (a call, secure message, or in person) if you are issuing a temporary password.",
            ],
            'header_intro'  => "Roster — everyone who can sign in. Tap a person to see their full profile, audit trail, and active jurisdictions.",
        ],

        'actions' => [

            /*------------------------------ ADD USER ------------------------------*/
            'add_user' => [
                'id'              => 'add_user',
                'label'           => 'Add user',
                'verb'            => 'add',
                'icon'            => 'plus',
                'one_liner'       => 'Bring a new person onto the roster.',
                'when_to_use'     => "When somebody new joins the surveillance workforce and you need to give them a sign-in.",
                'when_not_to_use' => "If they already have an account here — search for their email first; reactivate or change their role instead of creating a duplicate.",
                'prerequisites'   => [
                    "Full name and a working email.",
                    "The role they will hold — see Roles for what each one allows.",
                    "Whether you can reach them in person or by secure channel today (decides which onboarding path you pick).",
                ],
                'steps' => [
                    [
                        'n'         => 1,
                        'ask'       => "Who is this person?",
                        'explainer' => "Name them as their colleagues will see them — full name, the username they will type at sign-in, and a working email. The username cannot be changed later.",
                        'example'   => "Jane Nakato · jnakato · jane.nakato@health.go.ug",
                        'pitfall'   => "Avoid generic usernames like 'admin1' or 'screener' — every action gets logged against the username, so it must point to a single person.",
                    ],
                    [
                        'n'         => 2,
                        'ask'       => "What kind of work will they do?",
                        'explainer' => "Pick the role that matches what they actually do. The role decides what they can read, what they can write, and at which level. Account type is set automatically from the role and rarely needs changing.",
                        'example'   => "PoE Officer for someone running primary screening at Entebbe International Airport.",
                        'pitfall'   => "Do not promote someone to a higher role just to give them one extra capability — that is what assignments and admin overrides are for.",
                    ],
                    [
                        'n'         => 3,
                        'ask'       => "How will they sign in for the first time?",
                        'explainer' => "Two paths. Pick the one that fits how you can reach them today.",
                        'example'   => "If they are sitting next to you, hand them a temporary password. If they are remote, send them an invitation link.",
                        'pitfall'   => "Never type a temporary password into chat or email — share it on a phone call, in person, or through your secure channel.",
                    ],
                    [
                        'n'         => 4,
                        'ask'       => "Last look before you save.",
                        'explainer' => "Read it back. Once you save, the username is fixed and the audit log records who created the account.",
                        'example'   => null,
                        'pitfall'   => "If anything looks wrong, go back. There is no penalty for fixing it now; correcting a username after the fact is painful.",
                    ],
                ],
                'sub_paths' => [
                    'credential' => [
                        'label'         => 'Add now · temporary password',
                        'one_liner'     => 'Account live in seconds; share the password yourself.',
                        'when_to_use'   => "When you can hand the credential to the person on a phone call, in person, or on a secure channel today.",
                        'consequences'  => [
                            "The account is created and active immediately.",
                            "The system generates a 12-character temporary password and shows it to you once.",
                            "On their first sign-in they are forced to change it.",
                        ],
                        'estimated_time' => 'Under a minute.',
                    ],
                    'email' => [
                        'label'         => 'Send invitation link',
                        'one_liner'     => 'Account inactive until they open the link and set a password.',
                        'when_to_use'   => "When the person is remote and you would rather not relay a password through anyone else.",
                        'consequences'  => [
                            "The account is created in pending state and cannot sign in.",
                            "You get a one-time URL to share. The link expires in seven days.",
                            "When they open it they pick their own password and the account becomes active.",
                        ],
                        'estimated_time' => 'A minute to send; the person finishes when they accept.',
                    ],
                ],
                'consequences' => [
                    "A new row appears in the roster with the role and account type you set.",
                    "Either a temporary password (shown once) or an invitation link (shown once) is generated.",
                    "The audit log records that you created the account.",
                ],
                'reversibility' => [
                    'reversible'      => true,
                    'window_minutes'  => null,
                    'how_to_undo'     => "Suspend the account immediately, or deactivate it. The row stays for the audit trail; access is gone.",
                ],
                'visibility'      => ["You.", "The new user once they sign in.", "Anyone with national-admin access reviewing the audit log."],
                'estimated_time'  => 'A minute or two end-to-end.',
                'fallback_action' => "If you are not sure they actually need an account, do not create one — ask their supervisor first. Duplicates are harder to clean up than to prevent.",
            ],

            /*------------------------------ EDIT ------------------------------*/
            'edit_user' => [
                'id'              => 'edit_user',
                'label'           => 'Edit details',
                'verb'            => 'update',
                'icon'            => 'pencil',
                'one_liner'       => 'Change name, contact, role, or account type.',
                'when_to_use'     => "When the person's role changes, their phone or email changes, or their name was entered wrong.",
                'when_not_to_use' => "Do not edit the username — it is the audit anchor and cannot be changed. To change someone's jurisdiction, use Assignments instead.",
                'prerequisites'   => ["The new value, and a quick check that they really should hold the new role."],
                'consequences'    => [
                    "The roster row updates immediately.",
                    "The audit log records what you changed and from what.",
                    "If you change the role, what they can see and do may change at their next page load.",
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => "Edit again with the previous values."],
                'visibility'      => ["You.", "The user, on their next sign-in.", "National-admin reviewers in the audit log."],
                'estimated_time'  => 'Thirty seconds.',
                'fallback_action' => "If the role change feels promotional, raise it with the person's supervisor first.",
            ],

            /*------------------------------ VIEW PROFILE ------------------------------*/
            'view_profile' => [
                'id'              => 'view_profile',
                'label'           => 'View profile',
                'verb'            => 'open',
                'icon'            => 'eye',
                'one_liner'       => 'See identity, status, assignments, and full history.',
                'when_to_use'     => "When you want context before acting — recent sign-ins, recent admin actions, who they cover.",
                'when_not_to_use' => "If you already know what you need to do, skip the profile and pick the action directly.",
                'prerequisites'   => [],
                'consequences'    => ["Read-only. Nothing changes."],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => null],
                'visibility'      => ["Only you, in this session."],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => null,
            ],

            /*------------------------------ RESET PASSWORD ------------------------------*/
            'reset_password' => [
                'id'              => 'reset_password',
                'label'           => 'Reset password',
                'verb'            => 'reset',
                'icon'            => 'key',
                'one_liner'       => 'Issue a fresh temporary password and force a change on next sign-in.',
                'when_to_use'     => "When the person has lost their password, when you suspect their password has leaked, or after a personal-device loss.",
                'when_not_to_use' => "If their account is locked because of failed attempts, unlock it first — they may already remember the password.",
                'prerequisites'   => ["A way to share the new password with them out-of-band today."],
                'consequences'    => [
                    "The old password stops working immediately.",
                    "A new 12-character password is generated and shown to you once.",
                    "On their next sign-in the system forces them to change it.",
                    "Failed-attempt counter and lock are cleared.",
                ],
                'reversibility'   => ['reversible' => false, 'window_minutes' => null, 'how_to_undo' => "You cannot recover the old password. Reset again if needed."],
                'visibility'      => ["You see the new password once.", "The user, when they sign in.", "National-admin reviewers in the audit log."],
                'estimated_time'  => 'Under a minute.',
                'fallback_action' => "If you are not sure who is asking for the reset, hang up and call the person back on a number you trust.",
            ],

            /*------------------------------ INVITE LINK ------------------------------*/
            'send_invite_link' => [
                'id'              => 'send_invite_link',
                'label'           => 'Send / resend invitation link',
                'verb'            => 'invite',
                'icon'            => 'envelope',
                'one_liner'       => 'Issue a one-time link the person can use to set their own password.',
                'when_to_use'     => "When the person is remote, when their original invitation expired, or when you want to avoid handling a password yourself.",
                'when_not_to_use' => "If you can hand them a temporary password in person right now — that path is faster and equally safe.",
                'prerequisites'   => ["A way to send them the link — email, secure chat, or message."],
                'consequences'    => [
                    "Any earlier invitation link for this person stops working.",
                    "A fresh one-time URL is generated and shown to you once.",
                    "The link expires in seven days.",
                    "The account stays inactive until they open it and set a password.",
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => "Use Revoke invitation to kill the link before it is used."],
                'visibility'      => ["You see the URL once.", "The person you send it to."],
                'estimated_time'  => 'Under a minute to issue; the person finishes when they accept.',
                'fallback_action' => "If you sent it to the wrong address, revoke it and issue a new one — do not chase the message.",
            ],

            /*------------------------------ REVOKE INVITE ------------------------------*/
            'revoke_invite' => [
                'id'              => 'revoke_invite',
                'label'           => 'Revoke invitation',
                'verb'            => 'revoke',
                'icon'            => 'x',
                'one_liner'       => 'Cancel an outstanding invitation link before it is used.',
                'when_to_use'     => "When the link went to the wrong person, when the role decision changed, or when the recipient is no longer joining.",
                'when_not_to_use' => "If the user has already accepted the invitation — this does nothing for them; suspend the account instead.",
                'prerequisites'   => [],
                'consequences'    => [
                    "The outstanding link stops working immediately.",
                    "The account remains in pending state until you issue a new link or delete it.",
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => "Issue a new invitation link."],
                'visibility'      => ["You.", "Anyone trying to use the old link will see an expired-link page.", "National-admin reviewers in the audit log."],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => null,
            ],

            /*------------------------------ RESET MFA ------------------------------*/
            'reset_mfa' => [
                'id'              => 'reset_mfa',
                'label'           => 'Reset multi-factor',
                'verb'            => 'reset',
                'icon'            => 'shield',
                'one_liner'       => 'Clear the second-factor secret so the person can re-enrol.',
                'when_to_use'     => "When they lost the phone they used for codes, switched phones, or their authenticator app stopped working.",
                'when_not_to_use' => "If you suspect the account is compromised — suspend it first, then investigate.",
                'prerequisites'   => ["Confirmed identity of the person asking — call them back on a known number."],
                'consequences'    => [
                    "The current second-factor secret and recovery codes are wiped.",
                    "On the person's next sign-in they will be asked to enrol a new second factor.",
                ],
                'reversibility'   => ['reversible' => false, 'window_minutes' => null, 'how_to_undo' => "Once enrolled fresh, the previous codes cannot come back."],
                'visibility'      => ["You.", "The user, on their next sign-in.", "National-admin reviewers in the audit log."],
                'estimated_time'  => 'Under a minute.',
                'fallback_action' => "If you cannot positively identify the person on the line, do not reset — escalate.",
            ],

            /*------------------------------ UNLOCK ------------------------------*/
            'unlock' => [
                'id'              => 'unlock',
                'label'           => 'Unlock account',
                'verb'            => 'unlock',
                'icon'            => 'unlock',
                'one_liner'       => 'Clear the failed-attempt counter so the person can sign in again.',
                'when_to_use'     => "When the person is locked out after too many wrong-password attempts and you have confirmed it is them.",
                'when_not_to_use' => "If the lock is from a series of attempts you cannot account for — investigate first; it may be an attack.",
                'prerequisites'   => ["Confirmed identity of the person asking."],
                'consequences'    => [
                    "Failed-attempt counter is set to zero.",
                    "Any active lock is cleared.",
                    "The password itself is unchanged — they sign in with whatever they had.",
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => "Suspend the account if you change your mind."],
                'visibility'      => ["You.", "National-admin reviewers in the audit log."],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => "If they still cannot sign in after unlock, reset the password instead.",
            ],

            /*------------------------------ SUSPEND ------------------------------*/
            'suspend' => [
                'id'              => 'suspend',
                'label'           => 'Suspend access',
                'verb'            => 'suspend',
                'icon'            => 'pause',
                'one_liner'       => 'Cut access immediately, keep the record.',
                'when_to_use'     => "When you cannot leave them in until you have spoken to them — for misuse, suspected compromise, sudden role change, or extended leave.",
                'when_not_to_use' => "If they have permanently left the organisation, deactivate instead. If they only need MFA reset, do that instead.",
                'prerequisites'   => ["A reason — at least 30 characters, plain English, recorded in the audit log."],
                'consequences'    => [
                    "The person is signed out everywhere they are signed in.",
                    "They cannot sign in again until you reactivate.",
                    "Their assignments, history, and audit trail stay in place.",
                    "The reason you give is permanently in the audit log.",
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => "Reactivate the account. Their previous assignments come back."],
                'visibility'      => ["You.", "The user, the next time they try to sign in.", "Their supervisor, on the roster.", "National-admin reviewers in the audit log."],
                'estimated_time'  => 'Under a minute, including the reason.',
                'fallback_action' => "If you are not sure suspension is warranted, talk to their supervisor first — suspension stings if it turns out to be a misunderstanding.",
            ],

            /*------------------------------ REACTIVATE ------------------------------*/
            'reactivate' => [
                'id'              => 'reactivate',
                'label'           => 'Reactivate',
                'verb'            => 'reactivate',
                'icon'            => 'check',
                'one_liner'       => 'Restore sign-in access for a suspended or deactivated person.',
                'when_to_use'     => "When the reason you suspended them is resolved — they have returned from leave, the investigation cleared them, or the role change took effect.",
                'when_not_to_use' => "If they have changed roles, edit the role first, then reactivate.",
                'prerequisites'   => [],
                'consequences'    => [
                    "The account becomes active again.",
                    "The previous suspension reason stays in the audit log.",
                    "Their old assignments come back unless you have ended them separately.",
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => "Suspend again if needed."],
                'visibility'      => ["You.", "The user, on their next sign-in.", "Their supervisor.", "National-admin reviewers in the audit log."],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => null,
            ],

            /*------------------------------ ASSIGN JURISDICTION ------------------------------*/
            'assign_jurisdiction' => [
                'id'              => 'assign_jurisdiction',
                'label'           => 'Assign jurisdiction',
                'verb'            => 'assign',
                'icon'            => 'pin',
                'one_liner'       => 'Decide which province, district, or PoE they cover.',
                'when_to_use'     => "Right after adding a new person, or when their posting changes.",
                'when_not_to_use' => "Do not stack jurisdictions on someone who only ever needs one — pick a primary.",
                'prerequisites'   => ["The exact province, district, or PoE."],
                'consequences'    => [
                    "What they see in the system narrows or widens to the new jurisdiction.",
                    "Their primary assignment becomes their default scope on sign-in.",
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => "End the assignment in Assignments."],
                'visibility'      => ["You.", "The user, on their next sign-in.", "National-admin reviewers in the audit log."],
                'estimated_time'  => 'A minute or two.',
                'fallback_action' => null,
            ],

        ],

        'modals' => [
            'wizard' => [
                'id'      => 'add_user_wizard',
                'title'   => 'Adding a new person to the roster',
                'purpose' => 'Walks you through identity, role, onboarding path, and a final read-back before you save.',
                'fields'  => [
                    ['id' => 'full_name',    'label' => 'Full name',    'hint' => "Their full name as colleagues will see it.",                                       'example' => "Jane Banda",                       'why_required' => 'Required.', 'validation_human' => "Tell us their full name."],
                    ['id' => 'username',     'label' => 'Username',     'hint' => "What they will type at sign-in. Letters, numbers, dot, underscore. Not changeable later.", 'example' => "jbanda",                          'why_required' => 'Required.', 'validation_human' => "Pick 3–32 characters: letters, numbers, dots, underscores."],
                    ['id' => 'email',        'label' => 'Email',        'hint' => "A working address — invitations and resets go here.",                              'example' => "jane.nakato@health.go.ug",          'why_required' => 'Required.', 'validation_human' => "Enter a working email address."],
                    ['id' => 'phone',        'label' => 'Phone',        'hint' => "Optional — used for contact, not sign-in.",                                        'example' => "+260 …",                          'why_required' => 'Optional.', 'validation_human' => null],
                    ['id' => 'role_key',     'label' => 'Role',         'hint' => "Pick what they actually do, not what their job title says.",                       'example' => "PoE Officer for primary screening.", 'why_required' => 'Required.', 'validation_human' => "Choose a role from the list."],
                    ['id' => 'account_type', 'label' => 'Account type', 'hint' => "Set automatically from the role. Change only if you know why.",                    'example' => "POE_OFFICER",                     'why_required' => 'Required.', 'validation_human' => "Pick an account type."],
                    ['id' => 'invite_mode',  'label' => 'Onboarding',   'hint' => "Hand them a password now, or send a link they open themselves.",                  'example' => "Add now · temporary password",    'why_required' => 'Required.', 'validation_human' => "Pick how the person will sign in for the first time."],
                ],
            ],
            'suspend' => [
                'id'      => 'suspend_confirm',
                'title'   => 'Suspending access',
                'purpose' => 'A short typed reason that lands in the audit log, plus a final confirm.',
                'fields'  => [
                    ['id' => 'reason', 'label' => 'Reason', 'hint' => "One or two sentences. Plain words. Recorded permanently.", 'example' => "Compliance investigation — sign-ins from an unfamiliar IP last weekend.", 'why_required' => 'Required.', 'validation_human' => "Give a reason of at least 30 characters."],
                ],
            ],
        ],

        'glossary' => [
            ['term' => 'Username',          'plain_english' => "What the person types at sign-in. Cannot be changed after creation."],
            ['term' => 'Temporary password','plain_english' => "A 12-character password the system generates once. The person must change it on first sign-in."],
            ['term' => 'Invitation link',   'plain_english' => "A one-time URL the person opens to set their own password. Expires in seven days."],
            ['term' => 'PW reset',          'plain_english' => "Short for: this person must change their password on their next sign-in."],
            ['term' => 'Risk score',        'plain_english' => "An internal score from 0 to 100 that reflects unusual sign-in patterns. Higher means more attention warranted."],
        ],

        'comparison_columns' => ['When this fits', 'Heads-up', 'Reversible', 'Time'],

        'pre_confirm' => [
            'header' => 'Last look — what is about to happen',
            'note'   => "Read this back. Once you confirm, the audit log records what changed and the person sees the result on their next page load.",
        ],
        'post_action' => [
            'header_success' => 'Done',
            'next_step_hint' => "What is next: assign their jurisdiction in Assignments, or close this and pick the next person.",
        ],
    ],

    /*====================================================================
     | VIEW · Roles
     *===================================================================*/
    'roles' => [
        'view' => [
            'id'            => 'workforce.roles',
            'title'         => 'Roles & capabilities',
            'purpose'       => 'A read-only catalogue of the seven role keys the system supports, the permissions each one grants, and how many people hold each role today. Roles themselves are foundational — you cannot create or delete them — but you can switch a role on or off so that new users cannot be given a role you no longer use.',
            'audience'      => 'National admins reviewing the access matrix.',
            'prerequisites' => [],
            'header_intro'  => "Read what each role can do, see who holds it, and turn off any role you no longer want to hand out.",
        ],

        'actions' => [
            'view_role' => [
                'id'              => 'view_role',
                'label'           => 'Open role',
                'verb'            => 'open',
                'icon'            => 'book',
                'one_liner'       => 'See full description, capability list, and the people who hold this role.',
                'when_to_use'     => "When you are deciding which role to give a new person, or when you want to know who has elevated access.",
                'when_not_to_use' => null,
                'prerequisites'   => [],
                'consequences'    => ["Read-only. Nothing changes."],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => null],
                'visibility'      => ["Only you."],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => null,
            ],
            'toggle_role_active' => [
                'id'              => 'toggle_role_active',
                'label'           => 'Activate / deactivate role',
                'verb'            => 'toggle',
                'icon'            => 'switch',
                'one_liner'       => 'Decide whether this role is offered when adding new people.',
                'when_to_use'     => "When you want to retire a role for new sign-ups but keep existing holders working.",
                'when_not_to_use' => "Do not deactivate a role to revoke an individual's access — suspend that user instead.",
                'prerequisites'   => [],
                'consequences'    => [
                    "An inactive role disappears from the role picker on the Add User wizard.",
                    "People who already hold the role keep it; nothing changes for them.",
                    "You can switch it back on at any time.",
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => "Toggle it back on."],
                'visibility'      => ["You.", "Anyone running the Add User wizard from now on.", "National-admin reviewers in the audit log."],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => null,
            ],
        ],

        'glossary' => [
            ['term' => 'Capability matrix', 'plain_english' => "A grid showing every role on one axis and every permission on the other. A green dot means the role has that permission; grey means it does not."],
            ['term' => 'Capability density','plain_english' => "How many of the tracked permissions this role grants, out of the total. Higher density means more privilege."],
        ],
        'pre_confirm'  => null,
        'post_action'  => null,
        'comparison_columns' => null,
    ],

    /*====================================================================
     | VIEW · Assignments
     *===================================================================*/
    'assignments' => [
        'view' => [
            'id'            => 'workforce.assignments',
            'title'         => 'Jurisdiction assignments',
            'purpose'       => "An assignment is a specific person's coverage of a specific place — a country, a province, a district, or a Point of Entry — for a specific period. People can have several assignments; one is marked primary and that is the place the system shows them by default when they sign in. Assignments decide what each person can read and act on.",
            'audience'      => 'National admins and PHEOC officers placing people in the field.',
            'prerequisites' => [
                "The person is already on the roster.",
                "You know the exact province, district, or PoE they should cover.",
                "The start date — and the end date if you already know it.",
            ],
            'header_intro'  => "Decide who covers where. Tap a row to edit, end, or reopen; the smart-action sheet will route you to the right form.",
        ],

        'actions' => [
            'create_assignment' => [
                'id'              => 'create_assignment',
                'label'           => 'New assignment',
                'verb'            => 'create',
                'icon'            => 'plus',
                'one_liner'       => 'Place a person in a province, district, or PoE.',
                'when_to_use'     => "Right after adding a new user, when somebody is reposted, or when one person needs to cover a second area temporarily.",
                'when_not_to_use' => "Do not create overlapping assignments unless you mean to — the primary one wins for default scope, the others widen visibility.",
                'prerequisites'   => ["The user, the place, and the start date."],
                'steps' => [
                    [
                        'n'         => 1,
                        'ask'       => "Who is this assignment for?",
                        'explainer' => "Pick from the roster. If you cannot see them, they may be inactive — check Users first.",
                        'example'   => "Jane Banda · PoE Officer.",
                        'pitfall'   => "If the person should be the default for this jurisdiction, tick Primary.",
                    ],
                    [
                        'n'         => 2,
                        'ask'       => "Where will they cover?",
                        'explainer' => "Pick the most specific level you have. PoE narrows to one port; District narrows to one district; Province widens to a whole PHEOC area.",
                        'example'   => "Entebbe International — PoE level.",
                        'pitfall'   => "Selecting a PoE auto-fills the district and province. Do not edit them back unless you know why.",
                    ],
                    [
                        'n'         => 3,
                        'ask'       => "From when, to when, and read it back.",
                        'explainer' => "Open-ended end date is fine — you can end the assignment at any time. Confirm the line you see; that is exactly what the audit log will record.",
                        'example'   => "Starts today, ends — open.",
                        'pitfall'   => "Backdating starts is allowed, but everything before today is purely for the record; visibility only changes from now.",
                    ],
                ],
                'consequences' => [
                    "The person can immediately read and act inside the chosen jurisdiction.",
                    "If you ticked Primary, that jurisdiction becomes their default scope on sign-in.",
                    "The audit log records who created the assignment and when.",
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => "End the assignment. The row stays for history."],
                'visibility'      => ["You.", "The user, on their next sign-in.", "Anyone running scope-aware reports for that jurisdiction.", "National-admin reviewers in the audit log."],
                'estimated_time'  => 'Under two minutes.',
                'fallback_action' => "If you are not sure the posting is final, set a near end date and revisit.",
            ],
            'edit_assignment' => [
                'id'              => 'edit_assignment',
                'label'           => 'Edit assignment',
                'verb'            => 'update',
                'icon'            => 'pencil',
                'one_liner'       => 'Change place, primary status, or start / end date.',
                'when_to_use'     => "When the posting paperwork was wrong, or when somebody is repositioned within the same person's record.",
                'when_not_to_use' => "Do not edit to switch the user — end this assignment and create a new one for the other person.",
                'prerequisites'   => [],
                'consequences'    => [
                    "Visibility shifts to the new place from now.",
                    "Audit log records the before and after.",
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => "Edit again with the previous values."],
                'visibility'      => ["You.", "The user.", "National-admin reviewers in the audit log."],
                'estimated_time'  => 'Under a minute.',
                'fallback_action' => null,
            ],
            'end_assignment' => [
                'id'              => 'end_assignment',
                'label'           => 'End assignment',
                'verb'            => 'end',
                'icon'            => 'stop',
                'one_liner'       => 'Close this coverage so the person no longer sees that place.',
                'when_to_use'     => "When somebody is reposted, leaves the area, or when an assignment was created in error.",
                'when_not_to_use' => "If you want to keep them in the place but reduce activity, suspend the user instead.",
                'prerequisites'   => [],
                'consequences'    => [
                    "Ends_at is set to now and the row goes inactive.",
                    "The person stops seeing data from that jurisdiction immediately.",
                    "If this was their primary, the system falls back to another active assignment, or none.",
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => "Reopen the assignment from the row actions."],
                'visibility'      => ["You.", "The user.", "National-admin reviewers in the audit log."],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => null,
            ],
            'reopen_assignment' => [
                'id'              => 'reopen_assignment',
                'label'           => 'Reopen assignment',
                'verb'            => 'reopen',
                'icon'            => 'rewind',
                'one_liner'       => 'Bring an ended assignment back into force.',
                'when_to_use'     => "When somebody returns to a place they covered before — keeps the original history continuous.",
                'when_not_to_use' => "If the person is in a fresh role for that place, create a new assignment instead so the timeline is honest.",
                'prerequisites'   => [],
                'consequences'    => [
                    "Ends_at is cleared and the row goes active.",
                    "The person can read the jurisdiction again from now.",
                ],
                'reversibility'   => ['reversible' => true, 'window_minutes' => null, 'how_to_undo' => "End the assignment again."],
                'visibility'      => ["You.", "The user.", "National-admin reviewers in the audit log."],
                'estimated_time'  => 'A few seconds.',
                'fallback_action' => null,
            ],
        ],

        'modals' => [
            'wizard' => [
                'id'      => 'assignment_wizard',
                'title'   => 'Placing a person',
                'purpose' => 'Walks you through user, place, and period before you save.',
                'fields'  => [
                    ['id' => 'user_id',       'label' => 'User',     'hint' => "Pick the person from the roster.",                                              'example' => "Jane Banda · PoE Officer",          'why_required' => 'Required.', 'validation_human' => "Choose a user."],
                    ['id' => 'is_primary',    'label' => 'Primary',  'hint' => "Tick if this jurisdiction is their default on sign-in.",                       'example' => null,                                'why_required' => 'Optional.', 'validation_human' => null],
                    ['id' => 'province_code', 'label' => 'Province', 'hint' => "PHEOC level. Auto-filled if you pick a PoE.",                                   'example' => "Central",                            'why_required' => 'At least one of province / district / PoE is required.', 'validation_human' => "Pick the province."],
                    ['id' => 'district_code', 'label' => 'District', 'hint' => "District level. Auto-filled if you pick a PoE.",                                'example' => "Central",                            'why_required' => 'At least one of province / district / PoE is required.', 'validation_human' => "Pick the district."],
                    ['id' => 'poe_code',      'label' => 'PoE',      'hint' => "Specific port. Leave blank for province- or district-only coverage.",          'example' => "Entebbe Intl.",              'why_required' => 'Optional.', 'validation_human' => null],
                    ['id' => 'starts_at',     'label' => 'Starts',   'hint' => "Effective date. Today by default.",                                            'example' => "Today",                             'why_required' => 'Required.', 'validation_human' => "Pick a start date."],
                    ['id' => 'ends_at',       'label' => 'Ends',     'hint' => "Leave blank for an open-ended assignment.",                                    'example' => "Leave blank.",                      'why_required' => 'Optional.', 'validation_human' => null],
                ],
            ],
            'end' => [
                'id'      => 'end_assignment_confirm',
                'title'   => 'Ending coverage',
                'purpose' => 'Confirms the person will stop seeing that place from now.',
                'fields'  => [],
            ],
        ],

        'glossary' => [
            ['term' => 'Primary',     'plain_english' => "The default jurisdiction the system shows when this person signs in. They can have only one primary."],
            ['term' => 'Open ended',  'plain_english' => "An assignment with no end date. Stays in force until you end it."],
            ['term' => 'Coverage',    'plain_english' => "The place this person can read and act on — country, province, district, or PoE."],
        ],

        'comparison_columns' => ['When this fits', 'Heads-up', 'Reversible', 'Time'],

        'pre_confirm' => [
            'header' => 'Last look — what is about to happen',
            'note'   => "Once you confirm, the person's visibility and the audit log update on their next page load.",
        ],
        'post_action' => [
            'header_success' => 'Saved',
            'next_step_hint' => "What is next: open the user profile to verify their primary, or place the next person.",
        ],
    ],

];
