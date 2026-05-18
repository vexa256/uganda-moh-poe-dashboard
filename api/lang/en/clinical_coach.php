<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Coach Manifest — Clinical Library section
|--------------------------------------------------------------------------
|
| Per-view, deterministic plain-language guidance for the read-only
| Clinical Library reference console. Voice: calm, precise, slightly
| formal — written for an operator who is NOT a clinician, has not read a
| WHO case-definition document, and does not know what likelihood ratios
| are. Every concept that has a name in the data is explained in lay
| terms here.
|
| Per Paranoid v2 brief §7, every entry answers the ten questions:
|
|   q1_where_am_i      — Where am I?
|   q2_what_can_i_do   — What can I do here? (read-only — explore, audit, export)
|   q3_tabs            — What does each tab mean?
|   q4_charts          — What does each chart show?
|   q5_eye_lands_first — Where does the eye land first?
|   q6_filters         — What does each filter do to the picture?
|   q7_numbers         — What does each number mean?
|   q8_concerning      — What's the "concerning" pattern that warrants raising
|                        with the clinical team (since edits aren't possible
|                        from this view)?
|   q9_data_quality    — What's the data quality on this view?
|   q10_next_view      — What's the next view I should look at?
|
| Plus a per-view glossary keyed by named concepts.
|
| Strings live here. Views never hard-code coach text. The
| ClinicalCoachManifestTest fails if any of the ten slots is missing or
| empty for any of the six section keys.
|
| Last revised: 2026-04-26.
*/

return [

    /*--------------------------------------------------------------------
     | Cross-view scaffolding shown on every coach drawer.
     *--------------------------------------------------------------------*/
    'common' => [
        'invocation_label'    => 'What would you like to know?',
        'drawer_heading'      => 'Reading the Clinical Library',
        'drawer_subheading'   => 'A short brief on what is on screen, what each clinical concept means, and what the platform would do with this information at the border.',
        'read_only_notice'    => 'This section is a reference browser. You can read, audit, and export — you cannot change clinical rules from here. Changes to clinical reference data go through a separate, governed workflow because they affect what every screening team sees on every mobile device.',
        'simulation_notice'   => 'Worked examples are deterministic simulations that mirror the mobile case-scoring algorithm. The live engine that runs at the points of entry is on the mobile device itself; this page does not call it.',
        'fallback_notice'     => 'Where a code in the data has no plain-language translation yet, the raw code is shown with a small caption — the platform never invents an interpretation.',
        'close_label'         => 'Close coach',
    ],

    /*--------------------------------------------------------------------
     | Cross-view glossary — concepts that recur across every clin-* view.
     *--------------------------------------------------------------------*/
    'glossary_common' => [
        'scoring' => [
            'term'  => 'Scoring',
            'plain' => 'When an officer at the border records a traveller’s symptoms, exposures, and arrival country, the platform compares those facts against the reference data shown in this section and produces a score per possible disease. The score determines what action is taken — release, watchful, secondary screening, or immediate referral.',
        ],
        'tier' => [
            'term'  => 'Tier',
            'plain' => 'The category that determines what happens when a case scores high enough. Tier 1 is always reportable to WHO. Tier 2 is reportable when WHO’s Annex 2 algorithm thresholds are met. Tier 3 is tracked nationally for surveillance.',
        ],
        'weight' => [
            'term'  => 'Weight',
            'plain' => 'How strongly a single signal — a symptom or an exposure — points toward a particular disease. Big positive numbers are strong indicators; big negative numbers rule the disease out. Weights are added together to make a score.',
        ],
        'hallmark' => [
            'term'  => 'Hallmark',
            'plain' => 'A required signal. Without it, the disease is not flagged regardless of other findings. Hallmarks are how the platform distinguishes diseases that share many other symptoms.',
        ],
        'score_cap' => [
            'term'  => 'Score cap',
            'plain' => 'The highest possible score for a disease. Scores never go above 100; many diseases have practical caps below that because not every signal in the world contributes to them.',
        ],
        'endemic' => [
            'term'  => 'Endemic country',
            'plain' => 'A country where the disease is regularly present. A traveller arriving from one of these countries gets a higher score for that disease — how much higher depends on whether the country is currently in active outbreak or simply endemic.',
        ],
        'action_band' => [
            'term'  => 'Action band',
            'plain' => 'The category the score falls into: no action, watchful, secondary screening, or immediate referral. The action determines what happens next at the point of entry.',
        ],
        'simulation' => [
            'term'  => 'Simulation',
            'plain' => 'A worked example computed deterministically from the same reference data the mobile uses. It is not a real case and creates no record. The live mobile engine may produce slightly different numbers because it factors in things that cannot be supplied to a simulation (vaccination certificates, symptom-onset dates, full clinical context).',
        ],
    ],

    /*====================================================================
     | clin-diseases — Diseases
     *====================================================================*/
    'clin-diseases' => [
        'title'    => 'Diseases — what the platform watches for',
        'audience' => 'Clinical reviewers, PHEOC officers, national admins.',
        'q1_where_am_i' => 'You are looking at every disease the platform currently scores travellers against — what each one is, how the platform recognises it, what it would do at the border if a traveller matched the profile, and which countries put a traveller at higher risk for it.',
        'q2_what_can_i_do' => [
            'Browse every disease the platform tracks.',
            'See the plain-language description, the tier, the score cap, and the hallmark requirement (if any) for each disease.',
            'See which symptoms and which exposures point most strongly to each disease.',
            'Run a worked example: pick a country and a set of symptoms, and see what the platform would score for that traveller.',
            'Export the disease catalogue as CSV for record-keeping.',
        ],
        'q3_tabs' => [
            'All Diseases'      => 'Every disease the platform tracks. The default view.',
            'By Tier'           => 'Diseases grouped by what happens when one of them is detected: Tier 1 always notifies WHO; Tier 2 conditionally notifies; Tier 3 is national surveillance only.',
            'By Action Band'    => 'Diseases grouped by the highest action they can drive. Some diseases will only ever produce a watchful flag; others can drive an immediate referral.',
            'Worked Examples'   => 'Pick a disease, a country, a symptom set, and an exposure set, and see the platform’s score for that traveller. The example is a simulation — no real case is created.',
            'Recently Updated'  => 'Diseases whose reference rows have changed in the period — useful for clinical review.',
            'Methodology'       => 'Plain-language explanation of how the scoring formula works, with a "Show technical detail" disclosure for the actual mathematics.',
        ],
        'q4_charts' => [
            'Tier distribution donut — how many diseases fall in each tier. Helps you see the shape of the platform’s clinical scope at a glance.',
            'Score-cap horizontal bars per disease — how high the worst-case score for each disease can climb. A disease whose cap stays below 35 will never trigger secondary screening from score alone.',
            'Symptom-contribution bars per disease (in the dossier) — the symptoms that contribute the most to that disease’s score, sorted strongest first.',
            'Endemic-country count per disease — how many countries are currently flagged as endemic, recent outbreak, or active outbreak.',
        ],
        'q5_eye_lands_first' => 'The header KPI strip — total diseases tracked, distribution across tiers, count of diseases with a hallmark requirement. Then scan the disease cards for any unfamiliar entries; clicking a card opens its full dossier.',
        'q6_filters' => 'The Tier filter narrows to diseases of one notification tier. The Hallmark filter narrows to diseases that require a specific signal to be flagged. The Active filter hides retired entries. Search matches both the disease name and its codes.',
        'q7_numbers' => [
            'Total — the count of diseases on file. Discovered live from the reference table.',
            'Hallmark count — diseases with at least one hallmark requirement in their gate definition.',
            'Score cap (per disease) — the maximum score this disease can deterministically reach, computed by summing every positive symptom and exposure weight, plus the maximum endemic boost, plus any engine-config boost.',
            'Endemic countries (per disease) — count of country rows on file for that disease.',
        ],
        'q8_concerning' => 'A disease whose tier seems wrong for its modern threat profile, a disease with no hallmark whose cap can still drive secondary screening on weak signals, a disease that should be endemic somewhere it is not flagged. None of these are fixable from this view — raise them with the clinical team through the change-request channel.',
        'q9_data_quality' => 'If a disease is missing its display name or has no symptom weights at all, the dossier will show a clear placeholder rather than blanks. If the count on this view ever drifts from the count Reports shows, the cross-section coherence test will fail loudly.',
        'q10_next_view' => 'To dig into a single signal, open Symptoms or Exposures from the dossier. To see where a disease is currently flagged geographically, open Endemic. To audit how scoring boosts are configured, open Scoring Rules.',
    ],

    /*====================================================================
     | clin-symptoms — Symptoms
     *====================================================================*/
    'clin-symptoms' => [
        'title'    => 'Symptoms — what each one tells the platform',
        'audience' => 'Clinical reviewers, surveillance officers.',
        'q1_where_am_i' => 'You are looking at every symptom the platform knows how to score — what each one is, which clinical category it belongs to, and which diseases it points to (and how strongly).',
        'q2_what_can_i_do' => [
            'Browse every symptom on file.',
            'See which diseases each symptom is a meaningful signal for, with plain-language strength labels (strong / moderate / weak).',
            'See which symptoms are flagged as red-flag (urgent on their own) and which are flagged as hallmark (required for a particular disease).',
            'Pick two or three symptoms and see which diseases they collectively point to.',
            'Export the symptom catalogue as CSV.',
        ],
        'q3_tabs' => [
            'All Symptoms'             => 'Every symptom on file.',
            'By Syndromic Group'       => 'Symptoms grouped by the WHO syndromic category they belong to (acute respiratory, haemorrhagic fever, acute neurological, etc.).',
            'By Strongest Disease Link'=> 'Symptoms ranked by how strongly they point to their top-linked disease — useful for clinical review of the rule set.',
            'Symptom Combinations'     => 'Pick two or three symptoms and see the combined disease list — useful for "what would the platform do if a traveller had all of these?"',
            'Methodology'              => 'How symptoms are coded, where syndromic groupings come from, what "weight" means for each symptom-disease pair.',
        ],
        'q4_charts' => [
            'Category bars — how many symptoms in each clinical category. Click a bar to filter the table.',
            'Syndromic-group bars — how many symptoms carry each WHO syndromic tag.',
            'Per-symptom disease-strength bars (in the dossier) — every disease this symptom contributes to, with a strength label.',
        ],
        'q5_eye_lands_first' => 'The KPI strip — total symptoms, count of red-flag symptoms, count of hallmark symptoms. Then the category chart for shape; click any bar to drill in.',
        'q6_filters' => 'Category narrows by clinical category. Syndromic group narrows by WHO grouping. Red-flag narrows to urgent-on-their-own symptoms. Hallmark narrows to symptoms that are required for at least one disease.',
        'q7_numbers' => [
            'Total — count of symptoms on file. Discovered live.',
            'Red-flag count — symptoms marked is_red_flag = 1.',
            'Hallmark count — symptoms marked is_hallmark = 1.',
            'Per-symptom strength label — derived from the disease’s symptom_weights JSON: ≥18 very strong, ≥12 strong, ≥6 moderate, > 0 weak; negative weights mean the symptom rules out the disease.',
        ],
        'q8_concerning' => 'A symptom labelled hallmark for a disease the clinical team no longer considers it diagnostic for; a red-flag symptom whose links no longer match the current threat picture. Raise with clinical team — not editable here.',
        'q9_data_quality' => 'Sensitivity values that are missing show as "Unknown sensitivity"; the symptom is still surfaced. If a symptom has no disease links at all, the dossier will say so plainly.',
        'q10_next_view' => 'To see how a symptom drives a particular disease’s score in context, open the disease dossier from the linked-diseases list. To explore symptom-by-exposure interaction, open Exposures.',
    ],

    /*====================================================================
     | clin-exposures — Exposures
     *====================================================================*/
    'clin-exposures' => [
        'title'    => 'Exposures — what raises a traveller’s risk',
        'audience' => 'Clinical reviewers, surveillance officers.',
        'q1_where_am_i' => 'You are looking at every exposure the platform asks about — situations a traveller has been in (sick contacts, animal contact, food handling, lab work, etc.) — and how each one connects to the engine’s scoring logic.',
        'q2_what_can_i_do' => [
            'Browse every exposure on file with its plain-language prompt.',
            'See the mapping between operator-facing exposure names and engine-internal codes.',
            'See which diseases each exposure points to (and how strongly).',
            'Export the exposure catalogue as CSV.',
        ],
        'q3_tabs' => [
            'All Exposures'             => 'Every exposure on file.',
            'Engine-Code Mappings'      => 'The translation between the operator-facing exposure name and the engine-internal code. Operators see plain language at the border; the engine sees a precise code; this view shows how they stay in sync.',
            'By Strongest Disease Link' => 'Exposures ranked by which disease they most strongly indicate.',
            'Methodology'               => 'How exposures are recorded, why they are collected, and how they change a traveller’s score.',
        ],
        'q4_charts' => [
            'Engine-code frequency bars — which engine codes are mapped from the most operator-facing exposures.',
            'Response-type bars — how many exposures use each question shape (Yes/No, multi-select, free text, numeric).',
            'Per-exposure disease-strength list (in the dossier) — every disease this exposure contributes to.',
        ],
        'q5_eye_lands_first' => 'The KPI strip — total exposures, count of high-risk exposures. Then scan the engine-code mappings tab to confirm each operator-facing prompt maps to a sensible engine code.',
        'q6_filters' => 'High-risk narrows to exposures the platform considers high-risk on their own. Active hides retired entries.',
        'q7_numbers' => [
            'Total — count of exposures on file.',
            'High-risk count — exposures marked is_high_risk = 1.',
            'Engine codes — distinct count of engine codes referenced by the mappings table.',
        ],
        'q8_concerning' => 'An exposure that maps to two or more engine codes with the same priority (ambiguous wins); an exposure that no longer links to any active disease; a high-risk exposure with no engine code mapping at all (its signal would never reach the engine). Raise with clinical team.',
        'q9_data_quality' => 'Operator-facing prompts that are missing are surfaced as plain text "Prompt not yet recorded" rather than blanks. Mappings with priority = 0 are surfaced explicitly so reviewers can spot ambiguous wins.',
        'q10_next_view' => 'To see what a particular exposure does to a specific disease, open the disease dossier. To explore which symptoms commonly co-occur with which exposures, open the Symptom Combinations view.',
    ],

    /*====================================================================
     | clin-boosts — Scoring Rules
     *====================================================================*/
    'clin-boosts' => [
        'title'    => 'Scoring Rules — what gets boosted',
        'audience' => 'Clinical reviewers, platform engineers, national admins.',
        'q1_where_am_i' => 'You are looking at the special boost rules layered on top of the standard symptom-and-exposure scoring. Some diseases are serious enough that even modest signals should raise the score; this view shows you exactly which diseases get boosted, by how much, and why.',
        'q2_what_can_i_do' => [
            'Browse every boost rule the engine applies on top of the standard scoring.',
            'Group boosts by disease — see, per disease, every boost that affects it.',
            'See the score cap for every disease in one place.',
            'Run a worked example to see how a boost changes a traveller’s score.',
        ],
        'q3_tabs' => [
            'All Boosts'           => 'Every boost rule on file. Sorted by section.',
            'By Disease'           => 'Boosts grouped under the disease they affect, so you can see — per disease — every special rule that applies.',
            'Score-Cap Reference'  => 'Per-disease score cap, with a plain-language explainer of what each cap means.',
            'Worked Examples'      => 'Pick a disease, a context, and see how the boost changes the score.',
            'Methodology'          => 'Why boosts exist (some diseases are rare enough that standard scoring would miss them) with the technical formula behind a disclosure.',
        ],
        'q4_charts' => [
            'Top boosts by magnitude — horizontal bars ranked by boost value, with disease names in plain language.',
            'Boost-section bars — how many boost rules belong to each engine section.',
            'Score-cap distribution — histogram of per-disease score caps.',
        ],
        'q5_eye_lands_first' => 'The largest boost on the page. If it lands somewhere unexpected (a disease the team no longer considers high-priority), that is the first thing to raise.',
        'q6_filters' => 'Section narrows by which engine section a boost belongs to. Search matches both config keys and disease names.',
        'q7_numbers' => [
            'Total boosts — count of active engine-config rows.',
            'Largest boost — the highest single boost value present in the data.',
            'Per-disease cap — computed by summing the maximum positive symptom and exposure weights plus endemic and engine boosts, clamped to 100.',
        ],
        'q8_concerning' => 'A boost so large it overrides the rest of the formula on weak evidence; a boost that targets a disease no longer in scope; two boosts that conflict on the same disease. Raise with clinical team.',
        'q9_data_quality' => 'Boost values are shown as raw numbers and as plain-language strength labels side-by-side, so the magnitude is unambiguous. If a boost row has no description, the row is still surfaced with a clear "no description" caption.',
        'q10_next_view' => 'To see what the boost does in context, open the Disease dossier. To audit which countries trigger an endemic boost, open Endemic Map.',
    ],

    /*====================================================================
     | clin-endemic — Endemic Map
     *====================================================================*/
    'clin-endemic' => [
        'title'    => 'Endemic Map — where each disease is regularly present',
        'audience' => 'Clinical reviewers, IHR Focal Points, surveillance officers.',
        'q1_where_am_i' => 'You are looking at the country-level endemic map. For every disease, this view shows which countries are flagged for it — and a traveller arriving from one of those countries gets a higher score for that disease at the border.',
        'q2_what_can_i_do' => [
            'Browse the full set of endemic mappings.',
            'See, per disease, which countries are currently flagged and at what level.',
            'See, per country, which diseases are flagged for a traveller arriving from there.',
            'Identify recently changed mappings — important when outbreaks shift geographically.',
            'Run a worked example: pick a country and see how arriving from there changes a traveller’s score for the endemic diseases.',
        ],
        'q3_tabs' => [
            'By Disease'         => 'Per disease, the countries currently flagged at each endemicity level.',
            'By Country'         => 'Per country, the diseases flagged for a traveller arriving from there.',
            'Recently Changed'   => 'Mappings whose endemicity level was updated in the period — useful when outbreaks shift.',
            'Worked Examples'    => 'Pick a country and a traveller profile; see how the endemic flags change the score.',
            'Methodology'        => 'How endemic mappings are decided, how often they should be reviewed, and what the consequences are at the border.',
        ],
        'q4_charts' => [
            'Endemicity level distribution donut — how mappings split across active outbreak / recent outbreak / endemic / sporadic / imported-only.',
            'Top diseases by outbreak pressure — diseases with the most countries currently in active or recent outbreak.',
            'Per-country flag list (in the dossier) — diseases flagged for a single country, with the endemicity-level chip and "since" year.',
        ],
        'q5_eye_lands_first' => 'The active-outbreaks badge cluster at the top — these are the country-disease pairs currently driving the largest endemic boost. If anything looks unfamiliar, that is the first thing to verify with the clinical team.',
        'q6_filters' => 'Endemicity level narrows to a single severity tier. Disease and country narrow as expected. Search matches both disease and country names.',
        'q7_numbers' => [
            'Total mappings — count of country-disease pairs on file.',
            'Active outbreaks — distinct mappings with endemicity_level = OUTBREAK_ACTIVE.',
            'Per-disease endemic-country count — derived live from the mappings table.',
            'Endemic boost values (per level) — the score points added when a traveller arrives from a flagged country: active outbreak +15, recent outbreak +10, endemic +7, sporadic +3, imported-only +0.',
        ],
        'q8_concerning' => 'A country flagged endemic for a disease the country has eliminated; an active outbreak with no recent verification timestamp; a mapping that suddenly appears in Recently Changed without a documented source. Raise with clinical team.',
        'q9_data_quality' => 'Country names that are missing fall back to the country code; the mapping is still surfaced. If "since year" is empty, the row is still rendered with a clear placeholder. The total count is computed live and never hardcoded.',
        'q10_next_view' => 'To see how a country’s mappings change a specific traveller’s score, run the Worked Example on the Disease dossier. To audit whether scoring boosts compound endemic boosts, open Scoring Rules.',
    ],

    /*====================================================================
     | clin-vaccines — Vaccines
     *====================================================================*/
    'clin-vaccines' => [
        'title'    => 'Vaccines — what counts as valid documentation',
        'audience' => 'Clinical reviewers, IHR Focal Points, port-health officers.',
        'q1_where_am_i' => 'You are looking at the vaccination documentation rules the platform recognises — what counts as a valid record, what counts as expired, and what difference any of it makes to a traveller’s risk score at the border.',
        'q2_what_can_i_do' => [
            'Browse every vaccine the platform recognises.',
            'See the validity rules for each vaccine: what document types are accepted, what time windows count as still-valid, what counts as expired.',
            'See which diseases each vaccine reduces the score for.',
            'See how often each rule has been triggered in the recent reporting window.',
        ],
        'q3_tabs' => [
            'All Vaccines'         => 'Every vaccine the platform recognises.',
            'Validity Rules'       => 'Per vaccine, a compact decision tree showing what makes a record valid, partially valid, or invalid.',
            'By Disease Link'      => 'Per disease, which vaccines (if any) reduce its score for a traveller who can prove vaccination.',
            'Worked Examples'      => 'Pick a vaccine and a traveller profile; see how the vaccination changes the score.',
            'Methodology'          => 'Why vaccination changes scoring; why some records are accepted and others are not.',
        ],
        'q4_charts' => [
            'Per-vaccine submission rollups — how many travellers in the period had a valid, invalid, or in-process certificate for each vaccine.',
            'Engine-config hooks table — the engine-config rows that mention vaccines, with their config keys, descriptions, and current values.',
        ],
        'q5_eye_lands_first' => 'The vaccines list. If a vaccine the team expected to see is missing, the most likely reason is that no engine-config row tags it; raise with the clinical team.',
        'q6_filters' => 'Window selector (30d / 90d / 1y) narrows the submission rollups to a recent reporting period. Vaccine search matches the vaccine name.',
        'q7_numbers' => [
            'Vaccine count — discovered live from the engine-config rows tagged for vaccines, plus the aggregated-template columns whose key encodes a vaccine stance.',
            'Validity counts — proportion of submissions with a valid / invalid / in-process record per vaccine in the window.',
            'Engine-config hooks — count of engine-config rows whose key mentions a vaccine name.',
        ],
        'q8_concerning' => 'A vaccine the team considers important that has no engine-config hook (the platform does not yet score it); a vaccine whose validity rule no longer matches WHO guidance; a vaccine showing a sudden spike in invalid submissions. Raise with clinical team.',
        'q9_data_quality' => 'There is no first-class vaccine reference table in the schema; this view derives its content from engine-config rows tagged for vaccines plus aggregated-template columns whose keys encode a vaccine stance. When neither source has rows, the view says so plainly rather than fabricating a vaccine list.',
        'q10_next_view' => 'To see how a vaccination affects a specific disease score, open the Disease dossier and run a Worked Example with the vaccine context. To audit how endemic flags compound or offset vaccination effects, open Endemic Map.',
    ],

];
