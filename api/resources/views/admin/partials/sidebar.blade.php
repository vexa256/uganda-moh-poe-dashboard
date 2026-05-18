{{-- ============================================================================
  SIDEBAR · PHEOC Command Centre · Uganda National POE Surveillance
  Rebuild 2026-04-23 · WHO-aligned IA · short labels (≤20 chars, no truncation)
  ----------------------------------------------------------------------------
  Uses ONLY primitives defined in admin.partials.theme.

  Section 06 · Aggregated Reports — full mirror of every aggregated/template
  operation the mobile app exposes today (templates CRUD + lifecycle, column
  editor, submissions, rollups, sync queue, late-reporters, versioning,
  exports). API surface:

    GET    /aggregated-templates              · /published · /active · /{id}
    POST   /aggregated-templates              + /columns
    PATCH  /aggregated-templates/{id}         + /columns
    POST   /aggregated-templates/{id}/publish · /retire · /activate · /lock
    DELETE /aggregated-templates/{id}?cascade
    PATCH  /aggregated-template-columns/{id}
    DELETE /aggregated-template-columns/{id}
    GET    /aggregated  POST /aggregated  GET /aggregated/{id}

  Section 03 · Alert Lifecycle answers 5W1H per alert from create → close.

  Routing contract:
    /admin/dashboard is the ONLY real href. Every other item resolves to "#"
    and fires shell.navGuard($event, label). Flip the href + live=true as
    each surface is rebuilt.
============================================================================ --}}

@php
    $nav = function (string $id, string $label, string $hint, string $iconPath, string $href = '#', bool $live = false) {
        return compact('id','label','hint','iconPath','href','live');
    };

    // My Reports module (2026-04-24): role-conditional sidebar entries.
    // ReportAccess::canSee() decides per (scope, report-key); items the
    // current user cannot query are silently omitted from the menu so
    // server-side gates and the visible UI remain consistent.
    $reportsScope  = request()->attributes->get('scope') ?? null;
    $reportsAccess = app(\App\Services\Reports\ReportAccess::class);
    $reportsAll    = [
        // Wave 3 — Executive Reporting Module (rebuild 2026-04-27, ported to
        // UG 2026-05-18). Each entry renders data immediately on landing
        // instead of forcing the user through a filter wizard. Legacy V1
        // rpt-* keys (rpt-suspected, rpt-geo, rpt-contact-tracing,
        // rpt-registry, rpt-age-gender, rpt-symptom-exposure,
        // rpt-screening-outcomes, rpt-suspected-disease-analytics,
        // rpt-case-confirmation, rpt-alert-acknowledgement, rpt-country-
        // analytics, rpt-poe-operations) remain routable for deep-links and
        // archived saved-views, but are no longer surfaced in the sidebar.
        'rpt-national-dashboard' => $nav('rpt-national-dashboard', 'National Dashboard',
            'Cross-cutting executive overview · KPIs from R1–R10 in one page · drill into any report',
            'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
            url('/admin/reports/rpt-national-dashboard'), true),
        'rpt-screening-overview' => $nav('rpt-screening-overview', 'Screening Overview',
            'R1 · Total screened · primary vs secondary · escalation rate · top POEs by volume · trend over time',
            'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
            url('/admin/reports/rpt-screening-overview'), true),
        'rpt-ops-risk' => $nav('rpt-ops-risk', 'Operational Risk',
            'R11 · POEs trending up · dark POEs · inactive officers · open alerts > 24h · endemic-country travellers',
            'M13 10V3L4 14h7v7l9-11h-7z',
            url('/admin/reports/rpt-ops-risk'), true),
        'rpt-gender' => $nav('rpt-gender', 'Gender Analytics',
            'R2 · Male vs female mix · gender by POE · gender mix over time · female share %',
            'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
            url('/admin/reports/rpt-gender'), true),
        'rpt-symptom-distribution' => $nav('rpt-symptom-distribution', 'Symptom Distribution',
            'R9 · Top symptoms (secondary tier) · symptom × gender heatmap · red-flag symptoms',
            'M3 3v18h18M9 17V9m4 8V5m4 12v-7',
            url('/admin/reports/rpt-symptom-distribution'), true),
        'rpt-alert-intel' => $nav('rpt-alert-intel', 'Alert Intelligence',
            'R3 · Total alerts · status mix · risk level trend · classification donut · false-positive rate',
            'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
            url('/admin/reports/rpt-alert-intel'), true),
        'rpt-response-time' => $nav('rpt-response-time', 'Response Timeliness',
            'R4 · Acknowledgement time · resolution time · % within SLA · median time by POE · open-alert aging',
            'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
            url('/admin/reports/rpt-response-time'), true),
        'rpt-resolution-db' => $nav('rpt-resolution-db', 'Resolution Database',
            'R5 · Every handled alert · closure reason · responsible officer · follow-ups · full lifecycle modal',
            'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
            url('/admin/reports/rpt-resolution-db'), true),
        'rpt-case-files' => $nav('rpt-case-files', 'Case File Registry',
            'R6 · Traveller name · demographics · travel · symptoms · exposures · disposition · 100% case file modal',
            'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8',
            url('/admin/reports/rpt-case-files'), true),
        'rpt-poe-performance' => $nav('rpt-poe-performance', 'POE Performance',
            'R7 · Volume vs alert rate by POE · top 10 vs dark POEs · active officers · trends over time',
            'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            url('/admin/reports/rpt-poe-performance'), true),
        'rpt-user-activity' => $nav('rpt-user-activity', 'User Activity',
            'R8 · Active vs inactive officers · screenings per officer · alerts handled · last activity',
            'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z',
            url('/admin/reports/rpt-user-activity'), true),
        'rpt-country-travel' => $nav('rpt-country-travel', 'Country & Travel',
            'R10 · Top origin countries · top alert-generating countries · transit routes · endemic-country flow',
            'M12 21v-1m0 0a8 8 0 100-16 8 8 0 000 16zM8 12h8M12 8v8',
            url('/admin/reports/rpt-country-travel'), true),
        // rpt-volume kept (V1) — operational counter; no V2 equivalent yet.
        'rpt-volume' => $nav('rpt-volume', 'Screening Volume',
            'Travellers screened · share sent for full check · risk found · sent on for care · holding queue · monthly / quarterly / yearly trend',
            'M9 12l2 2 4-4M9 12V8h6v4m-3 4v3m-6 0h12a2 2 0 002-2V8a2 2 0 00-2-2H6a2 2 0 00-2 2v9a2 2 0 002 2z',
            url('/admin/reports/rpt-volume'), true),
    ];
    $reportsItems = [];
    if ($reportsScope) {
        foreach ($reportsAll as $key => $item) {
            if ($reportsAccess->canSee($reportsScope, $key)) {
                $reportsItems[] = $item;
            }
        }
    }

    $menuSections = [

        // ── 00 · SITUATION ROOM ───────────────────────────────────────────
        [
            'section' => 'Situation Room',
            'caption' => 'The single-screen PHEOC cockpit.',
            'icon'    => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
            'items'   => [
                $nav('ops-dashboard', 'Situation Room',
                    'Live KPIs · alert feed · 7-1-7 rings · POE map · Copilot brief',
                    'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
                    url('/admin/dashboard'), true),
            ],
        ],




        // ══════════════════════════════════════════════════════════════════
        // 03 · ALERT LIFECYCLE · FORENSIC 5W1H
        // ══════════════════════════════════════════════════════════════════
        //
        // Compressed 14 → 7 items (2026-04-23). Zero functionality lost —
        // every operation, FSM transition, and event_code in dashboard.txt
        // is still exposed via either a merged surface (tabs / filter chips)
        // or a relocation to the section where it ontologically belongs.
        //
        // ── Merges (within Section 03) ─────────────────────────────────────
        //
        //   "Active Alerts" + "Closure & Reopen"
        //       → "Alerts" — status tabs: Open · Acknowledged · Closed · Reopened.
        //         Closed tab surfaces close_category + merged_into_alert_id
        //         (§B.9). Reopened tab surfaces reopen_count + reason from
        //         alert_timeline_events.payload_json (§B.6).
        //
        //   "Ownership" + "Escalations" + "Reassignments" + "Handoffs"
        //       → "Ownership Trail" — unified ledger of every event that
        //         changes routed_to_level OR current_owner_user_id, with
        //         filter chips per event_code (ESCALATED · REASSIGNED ·
        //         HANDOFF_SENT / ACCEPTED / REJECTED) and a "now" matrix
        //         tab (current_owner_user_id × routed_to_level).
        //
        //   "Collaborators" + "Evidence"
        //       → "Case Room" — COLLABORATOR_ADDED / UPDATED / REMOVED ·
        //         COMMENT_POSTED · EVIDENCE_UPLOADED / DELETED.
        //         Per-alert workspace + cross-alert audit lens.
        //
        // ── Relocations (out of Section 03) ────────────────────────────────
        //
        //   "PHEIC Declarations" → Section 04 · PHEIC Advisories.
        //         The IHR NFP workspace already hosts Article 12 (§B.5).
        //         PHEIC_DECLARED events + TIER1_ADVISORY auto-fanout audit
        //         (60-min suppression) are NFP responsibility, not
        //         per-alert state.
        //
        //   "Reminders" → Section 12 · Reminders & Retry.
        //         Suppression windows (§A.2), last_notified_at, scheduled
        //         FOLLOWUP_DUE / FOLLOWUP_OVERDUE (§C.2), and the
        //         retry-failed cron (every 15 min) are notification-domain
        //         governance, not per-alert state.
        //
        // ── Preserved 1:1 ──────────────────────────────────────────────────
        //   Followups · External Requests · SLA & Breaches · Timeline
        //
        // ── dashboard.txt event-code coverage map ──────────────────────────
        //   Alerts          — OPENED · ACKNOWLEDGED · CLOSED · ALERT_REOPENED
        //   Followups       — FOLLOWUP_COMPLETED · FOLLOWUP_BLOCKED ·
        //                     FOLLOWUP_IN_PROGRESS
        //   Ownership Trail — ESCALATED · REASSIGNED · HANDOFF_SENT /
        //                     ACCEPTED / REJECTED
        //   Case Room       — COLLABORATOR_ADDED / UPDATED / REMOVED ·
        //                     COMMENT_POSTED · EVIDENCE_UPLOADED / DELETED
        //   External Reqs   — EXTERNAL_INFO_REQUESTED
        //   SLA & Breaches  — BREACH_ROOT_CAUSE_LOGGED · BREACH_UPDATED
        //   Timeline        — all 18 event codes (the forensic backbone,
        //                     append-only · payload_json viewer)
        //
        // ── UI primitive directive (memorised) ─────────────────────────────
        //   NEVER kanban. Every status surface — alerts, followups, ownership
        //   — is a slim premium table with tabs / filter chips, or a card
        //   grid. Theme primitives: .table-wrap / .table / .table-row (SSoT
        //   in admin.partials.theme).
        // ══════════════════════════════════════════════════════════════════
        [
            'section' => 'Alert Lifecycle',
            'caption' => 'OPEN → ACKNOWLEDGED → CLOSED. Every alert, every owner, every breach.',
            'icon'    => 'M13 10V3L4 14h7v7l9-11h-7z',
            'items'   => [
                // "Alerts" absorbs "Active Alerts" (kanban → tabbed table)
                // + "Closure & Reopen" (status tabs Closed / Reopened).
                $nav('alert-hub',       'Alerts',            'Tabbed table · Open · Acknowledged · Closed · Reopened · severity × routed_to_level · close_category · merged_into_alert_id · reopen_count · per-alert dossier deep-link', 'M4 6h16M4 10h16M4 14h10M4 18h10', url('/admin/alerts'), true),

                // 14 RTSL auto-seeded followups (§B.7). blocks_closure is the
                // gate to CLOSED. Cron hourlyAt(:15) fires DUE / OVERDUE.
                $nav('alert-followups', 'Followups',         '14 RTSL items · PENDING · IN_PROGRESS · COMPLETED · BLOCKED · NOT_APPLICABLE · blocks_closure tracker · FOLLOWUP_DUE / FOLLOWUP_OVERDUE schedule',                         'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4', url('/admin/alerts/followups'), true),

                // "Ownership Trail" absorbs "Ownership" (now-matrix tab) +
                // "Escalations" + "Reassignments" + "Handoffs". Every event
                // that changes ownership or routing lives here.
                $nav('alert-ownership', 'Ownership Trail',   'Unified · OPENED · ACKNOWLEDGED · ESCALATED · REASSIGNED · HANDOFF_SENT / ACCEPTED / REJECTED · "now" matrix tab (current_owner_user_id × routed_to_level)',               'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6', url('/admin/alerts/ownership'), true),

                // "Case Room" absorbs "Collaborators" + "Evidence". Internal
                // authenticated collaboration — kept separate from External
                // Requests (anonymous tokens) because of the auth boundary.
                $nav('alert-caseroom',  'Case Room',         'COLLABORATOR_ADDED / UPDATED / REMOVED · COMMENT_POSTED · EVIDENCE_UPLOADED / DELETED · per-alert + cross-alert investigation audit',                                     'M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2v16z', url('/admin/alerts/case-room'), true),

                // Tokenised one-time-URL portal for external responders
                // (labs · hospitals · airlines · port ops). Distinct from
                // Case Room because these callers never log in.
                $nav('alert-external',  'External Requests', 'responder_info_requests · tokenised one-time-URL portal · mint / revoke / audit · EXTERNAL_INFO_REQUESTED events',                                                        'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z', url('/admin/alerts/external'), true),

                // SLA thresholds per §B.6: risk_level → {4h, 24h, 48h}.
                // Per §B.8 breach only re-notifies; it does NOT change state.
                // Root-cause capture via BREACH_ROOT_CAUSE_LOGGED / UPDATED.
                $nav('alert-sla',       'SLA & Breaches',    '4h / 24h / 48h per-alert breach register · BREACH_ROOT_CAUSE_LOGGED · BREACH_UPDATED · per-alert forensic + aggregate trend',                                             'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', url('/admin/alerts/sla'), true),

                // The forensic backbone. Every other item is a filtered lens
                // over this raw feed. Append-only (§D.4 immutable evidence).
                $nav('alert-timeline',  'Case history',      'Plain-language record of every event on every case · By case · Recent · By event type · Search. Backed by alert_timeline_events; the dossier is the per-case anchor.', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', url('/admin/alerts/timeline'), true),
            ],
        ],



        // ══════════════════════════════════════════════════════════════════
        // 06 · AGGREGATED REPORTS · IDSR — compressed 10 → 3 (2026-04-24)
        // ══════════════════════════════════════════════════════════════════
        //
        // Zero functionality lost. Every legacy surface (templates list,
        // builder wizard, columns editor, versions, lifecycle, submissions
        // browser, rollups, late-reporter detector, sync queue, exports)
        // still exists — they have been collapsed into three wizard-driven
        // premium surfaces that mirror the mobile IDSR capture contract.
        //
        //   Studio      → legacy Templates + New Template wizard + Columns
        //                 editor + Versions + Lifecycle controls. A single
        //                 tabbed console; each template opens a 5-step
        //                 wizard sheet (Meta → Start-From → Columns →
        //                 Review → Publish). Column editor is a sub-tab of
        //                 the per-template sheet.
        //
        //   Intelligence→ legacy Submissions browser + Rollups (monthly,
        //                 POE, district, province, national) + Late
        //                 Reporters gap detector + CSV Exports. Tabbed with
        //                 progressive-reveal filters; inline period picker.
        //
        //   Sync Queue  → legacy Sync Queue + Diagnostics. UNSYNCED /
        //                 FAILED filter tabs · sync_attempt_count ·
        //                 last_sync_error · force-resync confirm token.
        //
        // Mobile contract preserved: all /aggregated and /aggregated-templates
        // endpoints untouched. Admin views talk to dedicated /admin/aggregated
        // JSON endpoints so mobile stays green regardless of admin churn.
        // ══════════════════════════════════════════════════════════════════
        [
            'section' => 'Aggregated Reports',
            'caption' => 'IDSR · template studio · submissions intelligence · sync queue. Full mobile parity.',
            'icon'    => 'M9 17v-2a4 4 0 00-4-4H3m14 0h-2a4 4 0 00-4 4v2M17 20h4M19 18v4',
            'items'   => [
                $nav('agg-studio',       'Studio',        'Template library · 5-step builder wizard · columns editor · versions · publish / retire / lock — one console for every IDSR template operation', 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253', url('/admin/aggregated/studio'),      true),
                $nav('agg-intel',        'Intelligence',  'Submissions browser · per-period / per-POE / per-district / national rollups · late-reporter gap detector · CSV export — role-scoped read hub',   'M3 12h3l3-9 6 18 3-9h3',                                                                                                                                                                                                                                                                                                    url('/admin/aggregated/submissions'), true),
                $nav('agg-reports',      'Reports',       'Dynamic per-template analytics · type-aware charts · time series · per-POE / per-district / per-province · outlier z-scores · trend slope · coverage · anomalies — every template column visualised',                                       'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',     url('/admin/aggregated/reports'),     true),
                $nav('agg-sync',         'Sync Queue',    'UNSYNCED / FAILED submissions · sync_attempt_count · last_sync_error · diagnostics · force-resync with confirm',                                   'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',                                                                                                                                                                                                             url('/admin/aggregated/sync'),        true),
            ],
        ],



        // ── 08 · INTELLIGENCE ─────────────────────────────────────────────
        [
            'section' => 'Intelligence',
            'caption' => 'Tripwires · ranking · heatmaps · automated digests · Copilot.',
            'icon'    => 'M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z',
            'items'   => [
                $nav('intel-rank',    'Disease Ranking', 'Rolling 7 / 14 / 30d by suspected disease · confidence bands',                  'M3 3v18h18M7 14l3-3 4 4 5-5',
                       url('/admin/intelligence/rank'), true),
                $nav('intel-geo',     'Heatmap & POEs',  'Case density · screening throughput · POE-to-POE benchmarking',                 'M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7',
                       url('/admin/intelligence/geo'), true),
                $nav('intel-trip',    'Tripwires',       'Stuck Alerts · Silent POEs · Dormant Officers · Case Spikes · Unsubmitted',     'M13 10V3L4 14h7v7l9-11h-7z',
                       url('/admin/intelligence/tripwires'), true),
                $nav('intel-digests', 'Digest Builder',  'Daily 07:00 + 3-day national 08:00 · preview · manual trigger · cron history',  'M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z',
                       url('/admin/intelligence/digests'), true),
                $nav('intel-copilot', 'Copilot',         'Deterministic next-best-action recommendations · alert narrations',             'M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z',
                       url('/admin/intelligence/copilot'), true),
            ],
        ],

        // ── 09 · CLINICAL LIBRARY ─────────────────────────────────────────
        [
            'section' => 'Clinical Library',
            'caption' => 'The admin home of the scoring engine. Epidemiologists update the brain of the mobile.',
            'icon'    => 'M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253',
            'items'   => [
                $nav('clin-diseases', 'Diseases',      'ref_diseases · 42 pathogens · LR weights · hallmark gate · tier (IHR-1 / WHO-26 / travel)', 'M4.5 12.75l6 6 9-13.5',
                       url('/admin/clinical/diseases'), true),
                $nav('clin-symptoms', 'Symptoms',      'ref_symptoms · WHO syndromic groupings · deriveWHOSyndrome() inputs',                     'M19 14l-7 7m0 0l-7-7m7 7V3',
                       url('/admin/clinical/symptoms'), true),
                $nav('clin-exposures','Exposures',     'ref_exposures + engine-code mappings (CONTACT_SICK_PERSON → close_contact)',              'M17.657 18.657A8 8 0 016.343 7.343S7 9 9 10c0-2 .5-5 2.986-7C14 5 16.09 5.24 17 7.28 18 9 19 11 19 13c0 2-.5 3.657-1.343 5.657z',
                       url('/admin/clinical/exposures'), true),
                $nav('clin-boosts',   'Scoring Rules', 'ref_engine_config · anthrax +30 · polio +35 · rabies +50 · score cap 100',                'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6',
                       url('/admin/clinical/boosts'), true),
                $nav('clin-endemic',  'Endemic Map',   'ref_endemic_countries · flip when outbreaks move · per-disease overlays',                 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
                       url('/admin/clinical/endemic'), true),
                $nav('clin-vaccines', 'Vaccines',      'Valid / invalid documentation rules · yellow fever · polio · influenza',                  'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
                       url('/admin/clinical/vaccines'), true),
            ],
        ],

        // ── 10 · POEs · ANNEX-1A ──────────────────────────────────────────
        [
            'section' => 'PoEs · Annex-1A',
            'caption' => 'Borders as IHR health objects · capacity · roster · escalation ladder.',
            'icon'    => 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'items'   => [
                // Live since 2026-04-23 — first geo CRUD slice. Wired to
                // Admin\Geo\PoesController; byte-equivalent to mobile bundle.
                $nav('poe-registry', 'PoE Registry',     'CRUD ref_poes · 35 rows · 24-field bundle contract · auto-derived external_id (ZM-PROV-DIST-NAME-NNN) · NATIONAL_ADMIN write · soft-delete + restore · auto bundle version bump',           'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4', url('/admin/geo/poes'), true),
                $nav('poe-capacity', 'PoE Capacity',     'Annex-1A core capacities · 8 dimensions · DRAFT → SUBMITTED → REVIEWED workflow',                'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z', url('/admin/poe/capacity'), true),
                $nav('poe-status',   'PoE Status',       'Open / Closed / Reduced / Emergency / Maintenance · temporal log · scope-filtered',             'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',                                                                                                                                                                                                                url('/admin/poe/status'),   true),
                $nav('poe-contacts', 'Roster & Ladder',  'poe_notification_contacts · 10 receives_* flags · 5-level escalation ladder',                  'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', url('/admin/poe/contacts'), true),
            ],
        ],

        // ── 11 · GEOGRAPHY · reference hierarchy CRUD ─────────────────────
        //
        // Companion to Section 10 (PoEs · Annex-1A). Section 10 holds the
        // operational PoE surfaces (registry, capacity, status, contacts);
        // this section holds the reference-data hierarchy underneath PoEs:
        // countries → provinces (PHEOCs) → districts → hospitals.
        //
        // All four landed live on 2026-04-23 with byte-equivalent writes
        // to /api/poes/bundle (same ref_geo_version bump path as mobile).
        //
        [
            'section' => 'Geography',
            'caption' => 'Reference hierarchy · countries · provinces (PHEOCs) · districts · hospitals.',
            'icon'    => 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
            'items'   => [
                $nav('geo-provinces', 'Provinces',   '10 PHEOCs · ref_provinces · cascades to PoEs on rename',                                                'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z', url('/admin/geo/provinces'), true),
                $nav('geo-districts', 'Districts',   '30 districts · ref_districts · auto-strip " District" suffix to district_raw',                          'M9 17v-2a4 4 0 00-4-4H3m14 0h-2a4 4 0 00-4 4v2',                                                                                                                                                                                                            url('/admin/geo/districts'), true),
                $nav('geo-hospitals', 'Hospitals',   'ref_hospitals · greenfield (0 seeded) · TEACHING / GENERAL / DISTRICT / RURAL / CLINIC / PRIVATE / MILITARY / OTHER', 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z', url('/admin/geo/hospitals'), true),
                $nav('geo-countries', 'Countries',   'ref_countries · single-tenant (Uganda) · ISO codes + dataset metadata',                                'M12 21v-1m0 0a8 8 0 100-16 8 8 0 000 16zM8 12h8M12 8v8',                                                                                                                                                                                                    url('/admin/geo/countries'), true),
            ],
        ],

        // ── 12 · WORKFORCE ────────────────────────────────────────────────
        [
            'section' => 'Workforce',
            'caption' => 'Surveillance workforce, roles, jurisdiction assignments, training.',
            'icon'    => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z',
            'items'   => [
                $nav('wf-users',    'Users',       'Surveillance workforce · invite · suspend / reactivate · MFA · risk · dormancy', 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',                                                                                                                                                                                                       url('/admin/workforce/users'),       true),
                $nav('wf-roles',    'Roles',       '7 role keys × write scopes · authoritative capability grid',                     'M9 4.804A7.968 7.968 0 005.5 4c-1.255 0-2.443.29-3.5.804v10A7.969 7.969 0 015.5 14c1.669 0 3.218.51 4.5 1.385A7.962 7.962 0 0114.5 14c1.255 0 2.443.29 3.5.804v-10A7.968 7.968 0 0014.5 4c-1.255 0-2.443.29-3.5.804V12',                                                                              url('/admin/workforce/roles'),       true),
                $nav('wf-assigns',  'Assignments', 'user_assignments · country / province / PHEOC / district / POE scope',           'M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z M15 11a3 3 0 11-6 0 3 3 0 016 0z',                                                                                                                                                                  url('/admin/workforce/assignments'), true),
            ],
        ],

        // ── 12 · GOVERNANCE ───────────────────────────────────────────────
        //
        // "Reminders & Retry" relocated here from Section 03 (2026-04-23).
        // Suppression windows, last_notified_at, scheduled FOLLOWUP_DUE /
        // OVERDUE, and the retry-failed cron are notification-domain
        // governance — they do not belong to any single alert's FSM.
        // Pairs with Delivery Audit: past sends vs scheduled / suppressed.
        //
        [
            'section' => 'Governance',
            'caption' => 'Auth events · delivery audit · reminders & retry · templates · data quality · retention.',
            'icon'    => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z',
            'items'   => [
                $nav('gov-auth',      'Auth Events',       'Login · MFA · lockouts · suspended users · auth_events feed',                                                                                       'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
                       url('/admin/governance/auth-events'), true),

                // Past-looking — did this send leave the building?
                $nav('gov-notif-log', 'Delivery Audit',    'notification_log · SENT / SKIPPED / FAILED · per-recipient delivery proof · last_error capture',                                                     'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
                       url('/admin/governance/notification-log'), true),

                // Future-looking — what will fire / is suppressed / will retry?
                // Was "Reminders" in Section 03 before the 2026-04-23 compression.
                $nav('gov-reminders', 'Reminders & Retry', 'Scheduled per-alert reminders · 15 suppression windows · last_notified_at · retry-failed cron (every 15 min) · FOLLOWUP_DUE / FOLLOWUP_OVERDUE',     'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9',
                       url('/admin/governance/reminders'), true),

                $nav('gov-templates', 'Notif Templates',   '15 templates · Mustache vars · preview · suppression windows · is_ai_enhanced (currently inert · see §B.5)',                                          'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
                       url('/admin/governance/templates'), true),
                $nav('gov-dq',        'Data Quality',      'Void rates · duplicate client_uuid · late syncs · idempotency hits',                                                                                 'M4 6h16M4 10h16M4 14h16M4 18h16',
                       url('/admin/governance/data-quality'), true),
                $nav('gov-retention', 'Retention & PII',   'secondary_screenings (the only PII home) · retention clock · export log',                                                                            'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z',
                       url('/admin/governance/retention'), true),
            ],
        ],

        // ── 13 · SYSTEM HEALTH ────────────────────────────────────────────
        [
            'section' => 'System Health',
            'caption' => 'Background jobs · mail delivery · mobile app · WHO connector. Read-only.',
            'icon'    => 'M5 12h14M12 5l7 7-7 7',
            'items'   => [
                $nav('sys-cron',   'Scheduled jobs',   'Background timekeeper · runs on time, last-run evidence, manual triggers',    'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                       url('/admin/system/cron'), true),
                $nav('sys-mail',   'Mail delivery',    'Did the mail leave the building, did it arrive, what came back undeliverable','M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
                       url('/admin/system/mail'), true),
                $nav('sys-mobile', 'Mobile app health','Field-app fleet · pending uploads, app versions, platforms, quiet devices', 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 002-2H8a2 2 0 00-2 2v14a2 2 0 002 2z',
                       url('/admin/system/mobile'), true),
                $nav('sys-who',    'WHO connector',    'Planned outbound link to WHO. Currently not connected — readiness tracker', 'M13 10V3L4 14h7v7l9-11h-7z',
                       url('/admin/system/who'), true),
            ],
        ],
    ];

    if (! empty($reportsItems)) {
        $menuSections[] = [
            'section' => 'My Reports',
            'caption' => 'Operational, surveillance, and epidemiological reports scoped to your access level.',
            'icon'    => 'M9 17v-2a4 4 0 00-4-4H3m14 0h-2a4 4 0 00-4 4v2M17 20h4M19 18v4',
            'items'   => $reportsItems,
        ];
    }

    $currentPath = trim(request()->path(), '/');
@endphp

<nav class="sidebar-rail" aria-label="Primary navigation">

    {{-- Brand · premium gradient · live-dot · subtle accent backdrop --}}
    <div class="sidebar-brand">
        <div class="relative grid place-items-center h-9 w-9 rounded-lg bg-gradient-to-br from-brand to-brand-ink text-white shadow-[0_4px_12px_-2px_hsl(var(--brand)/.35)]">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 2l3 6 6 .9-4.5 4.4 1 6.2L12 16.8 6.5 19.5l1-6.2L3 8.9 9 8z"/>
            </svg>
            <span class="absolute -top-0.5 -right-0.5 inline-flex h-2 w-2 rounded-full bg-success ring-2 ring-background" aria-hidden="true"></span>
        </div>
        <div class="min-w-0 leading-tight">
            <p class="text-[13px] font-bold tracking-tight truncate text-foreground">PHEOC Command</p>
            <p class="text-[10px] font-semibold tracking-[.14em] uppercase text-brand/85 truncate">Uganda · National</p>
        </div>
        <button type="button" class="ml-auto inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-brand-soft hover:text-brand-ink lg:hidden" @click="sidebarOpen = false" aria-label="Close navigation">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
    </div>

    {{-- Scrollable menu · premium quiet · no badge clutter --}}
    <div class="sidebar-scroll">
        @foreach ($menuSections as $sIdx => $section)
            <div class="sidebar-section" title="{{ $section['caption'] ?? '' }}">
                <span class="truncate">{{ $section['section'] }}</span>
            </div>

            <ul class="mt-0.5 mb-1 space-y-px" role="list">
                @foreach ($section['items'] as $item)
                    @php
                        $href     = $item['href'];
                        $isLive   = (bool) ($item['live'] ?? false);
                        $isSoon   = ! $isLive;
                        $itemPath = $isLive ? trim((string) parse_url($href, PHP_URL_PATH), '/') : null;
                        $isActive = $itemPath !== null && $itemPath === $currentPath;
                    @endphp

                    <li>
                        <a href="{{ $href }}"
                           @if ($isSoon) @click="navGuard($event, @js($item['label']))" @endif
                           class="group nav-item {{ $isActive ? 'nav-item-active' : '' }} {{ $isSoon ? 'nav-item-soon' : '' }}"
                           aria-current="{{ $isActive ? 'page' : 'false' }}"
                           data-item-id="{{ $item['id'] }}"
                           title="{{ $item['hint'] ?? $item['label'] }}">
                            <svg class="nav-item-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="{{ $item['iconPath'] }}"/>
                            </svg>
                            <span class="nav-item-label">{{ $item['label'] }}</span>
                            {{-- State indicators · subtle but legible:
                                 - active: handled by ::before accent bar + bg fill (CSS)
                                 - live:   small filled brand dot
                                 - soon:   small dashed circle outline --}}
                            @if (! $isActive)
                                @if ($isLive)
                                    <span class="nav-item-live-dot" aria-label="live"></span>
                                @else
                                    <span class="nav-item-soon-dot" aria-label="soon"></span>
                                @endif
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
        @endforeach
    </div>

    {{-- User chip · sticky bottom · branded avatar · scope label · logout --}}
    @php
        $authUser = auth()->user();
        $authScope = request()->attributes->get('scope') ?? null;
        $initials = $authUser
            ? strtoupper(substr((string) ($authUser->full_name ?? $authUser->name ?? $authUser->email ?? 'U'), 0, 2))
            : 'PR';
        $roleLabel = $authUser ? str_replace('_', ' ', strtolower((string) ($authUser->role_key ?? $authUser->account_type ?? 'Observer'))) : 'Awaiting auth';
        $scopeLabel = $authScope['label'] ?? null;
    @endphp
    <div class="sidebar-user" x-data="{ menu: false }">
        <button type="button" @click="menu = !menu" @click.away="menu = false" class="w-full flex items-center gap-2.5 px-1.5 py-1.5 rounded-md hover:bg-background transition-colors group">
            <span class="relative grid place-items-center h-8 w-8 rounded-md bg-gradient-to-br from-brand to-brand-ink text-white font-bold text-[11px] shadow-[0_2px_8px_-2px_hsl(var(--brand)/.40)]">
                {{ $initials }}
                <span class="absolute -bottom-0.5 -right-0.5 inline-flex h-2 w-2 rounded-full bg-success ring-2 ring-background"></span>
            </span>
            <span class="min-w-0 flex-1 text-left leading-tight">
                <span class="block text-[11.5px] font-semibold text-foreground truncate">{{ $authUser?->full_name ?? $authUser?->name ?? 'Preview' }}</span>
                <span class="block text-[9.5px] uppercase tracking-[.12em] font-medium text-muted-foreground/70 truncate">{{ $roleLabel }}</span>
            </span>
            <svg class="h-3.5 w-3.5 text-muted-foreground/60 group-hover:text-brand transition-colors shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 9l7-7 7 7M5 15l7 7 7-7"/></svg>
        </button>

        {{-- Pop-up menu --}}
        <div x-show="menu" x-cloak x-transition.opacity class="mt-1 rounded-md border bg-card shadow-elevation-3 p-1 text-[12px]">
            @if ($scopeLabel)
                <div class="px-2 py-1.5">
                    <p class="text-[9.5px] font-semibold uppercase tracking-[.14em] text-muted-foreground/65">Scope</p>
                    <p class="text-[11.5px] text-foreground/90 mt-0.5 leading-snug">{{ $scopeLabel }}</p>
                </div>
                <div class="separator separator-h my-1"></div>
            @endif
            @auth
                <form method="POST" action="{{ url('/logout') }}" class="m-0">
                    @csrf
                    <button type="submit" class="w-full flex items-center gap-2 px-2 py-1.5 rounded text-foreground/80 hover:bg-critical-soft hover:text-critical">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        Sign out
                    </button>
                </form>
            @endauth
            @guest
                <a href="{{ url('/login') }}" class="flex items-center gap-2 px-2 py-1.5 rounded text-brand hover:bg-brand-soft/50">
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 003 3h4a3 3 0 003-3V7a3 3 0 00-3-3h-4a3 3 0 00-3 3v1"/></svg>
                    Sign in
                </a>
            @endguest
        </div>
    </div>
</nav>
