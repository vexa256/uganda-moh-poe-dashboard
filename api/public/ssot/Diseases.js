/* eslint-disable no-dupe-keys */
/* eslint-disable no-unused-vars */
/**
 * ============================================================
 * WHO-POE UNIFIED EXPLAINABLE MATCHER v3 — Diseases.js
 * BASE SCORING ENGINE
 * ============================================================
 *
 * LOAD ORDER (main.ts):
 *   1. Diseases.js               ← THIS FILE (base engine, 42 diseases)
 *   2. Diseases_intelligence.js  (extends window.DISEASES)
 *   3. exposures.js              (exposure catalog)
 *
 * WHAT THIS FILE PROVIDES:
 *   window.DISEASES              ← the disease database + engine config
 *   window.DISEASES.scoreDiseases() ← the scoring function
 *
 * WHAT THIS FILE DOES NOT PROVIDE (provided by Diseases_intelligence.js):
 *   getEnhancedScoreResult()
 *   generateClinicalReport()
 *   buildOutbreakContext()
 *   deriveWHOSyndrome()
 *   computeIHRRiskLevel()
 *   getNonCaseVerdict()
 *   validateClinicalData()
 *   getWHOCaseDefinition()
 *
 * DISEASES: 42 (matches ENDEMIC_COUNTRIES oracle in Diseases_intelligence.js)
 *   IHR Tier 1 (Always Notifiable): smallpox, sars, influenza_new_subtype_zoonotic, polio
 *   IHR Tier 2 / WHO Priority: 26 diseases
 *   Extended Differential: 12 diseases
 *
 * SOURCES:
 *   WHO AFRO IDSR Technical Guidelines 2021 (3rd Ed.)
 *   WHO IHR 2005 Annex 2
 *   WHO Disease Fact Sheets 2024
 *   CDC Yellow Book 2024
 *   ECDC Country Risk Assessments 2024
 *   Mandell's Principles of Infectious Diseases, 9th Ed.
 *   GeoSentinel / TropNetEurop Travel Medicine Database
 *
 * DO NOT MODIFY THIS FILE.
 * All clinical intelligence logic lives in Diseases_intelligence.js.
 * ============================================================
 */

window.DISEASES = {

  metadata: {
    dataset_name: 'who_poe_unified_matcher_v3',
    version: '3.0.0',
    schema_version: '3.0.0',
    created_utc: '2026-04-17T00:00:00Z',
    disease_count: 42,
    purpose: 'Weighted, audit-friendly, WHO/IHR-aligned disease scoring engine for POE syndromic triage.',
    design_note: 'Explainable rules engine, not a statistically calibrated diagnostic model. All weights are LR-calibrated from Mandell 9th Ed. and WHO AFRO IDSR 2021. Syndrome bonuses and outbreak bonuses applied by Diseases_intelligence.js patch.',
    scope_note: 'Covers all IHR Annex 2 always-notifiable diseases, all WHO AFRO IDSR Priority 1 diseases, and the most clinically relevant travel-medicine differentials for East/Central Africa POE screening.',
  },

  sources: [
    { id: 'WHO_IHR_2005_ANNEX2', title: 'International Health Regulations (2005) — Annex 2', url: 'https://apps.who.int/gb/bd/pdf_files/IHR_2014-2022-2024-en.pdf' },
    { id: 'WHO_AFRO_IDSR_2021', title: 'WHO AFRO IDSR Technical Guidelines 2021 (3rd Ed.)', url: 'https://www.afro.who.int/sites/default/files/2021-09/Final_IDSR%20Technical%20Guidelines_Final%20Version%20signed%20by%20%20Hon.%20DG%20and%20Hon.%20Minister.pdf' },
    { id: 'WHO_DISEASE_FACT_SHEETS', title: 'WHO Disease Fact Sheets 2024', url: 'https://www.who.int/news-room/fact-sheets' },
    { id: 'CDC_YELLOW_BOOK_2024', title: 'CDC Yellow Book 2024 — Travelers\' Health', url: 'https://wwwnc.cdc.gov/travel/yellowbook/2024' },
    { id: 'MANDELL_9TH_ED', title: 'Mandell, Douglas, and Bennett\'s Principles of Infectious Diseases, 9th Edition', notes: 'LR-calibrated symptom weights' },
    { id: 'GEOSENTINEL_TROPNET', title: 'GeoSentinel / TropNetEurop Travel Medicine Database', notes: 'Prevalence calibration in symptomatic returning travellers' },
  ],

  engine: {
    name: 'WHO-POE Unified Explainable Matcher v3',
    ranking_principles: [
      'Gate diseases by their strongest hallmark or syndrome requirement — no gate pass = hard exclusion.',
      'Reward signature symptoms more than generic symptoms using LR-calibrated weights.',
      'Boost scores using exposure and outbreak epidemiological context.',
      'Penalise contradictions when clearly conflicting symptoms are confirmed absent.',
      'Promote high-consequence IHR diseases when red-flag patterns exist, even when symptom overlap is high.',
      'Fire triage overrides as hard rules independent of and before scoring — safety first.',
      'Apply absent-symptom penalties only for mandatory hallmark symptoms with sensitivity >= 0.80.',
    ],
    formula: {
      description: 'final_score = gate_score + symptom_score + exposure_score + syndrome_bonus + outbreak_bonus + vaccination_modifier + onset_modifier + absent_hallmark_penalty + contradiction_penalty + override_boost',
      gate_pass_bonus: 12,
      gate_soft_fail_penalty: -18,
      gate_hard_fail_penalty: -60,
      syndrome_bonus_match: 8,
      outbreak_bonus_default: 15,
      documented_valid_vaccination_reduction_default: -8,
      strong_documented_vaccination_reduction: -14,
      contradiction_penalty_default: -6,
      absent_mandatory_hallmark_penalty: -12,
      max_score_cap: 100,
      min_score_floor: 0,
      absent_penalise_only_if_sensitivity_gte: 0.8,
    },
    normalization: {
      rank_to_probability_like_percent: 'prob_like = round(100 * disease_score / sum(top_5_scores)) — displayed over top candidates only when total > 0',
      confidence_bands: [
        { band: 'very_high', min_score: 70, color: '#DC3545', poe_action: 'MANDATORY_ISOLATION_AND_IHR_NOTIFICATION' },
        { band: 'high', min_score: 55, color: '#E85D04', poe_action: 'SECONDARY_REFERRAL_URGENT' },
        { band: 'moderate', min_score: 40, color: '#FFC107', poe_action: 'SECONDARY_REFERRAL' },
        { band: 'low', min_score: 25, color: '#17A2B8', poe_action: 'DOCUMENT_AND_MONITOR' },
        { band: 'minimal', min_score: 0, color: '#6C757D', poe_action: 'STANDARD_PRECAUTIONS' },
      ],
      minimum_data_rule: 'If fewer than 2 symptoms and no exposure selected, confidence = minimal regardless of raw score.',
    },
    triage_overrides: [
      {
        rule_id: 'override_vhf_red_flag',
        priority: 1,
        description: 'Fever + any haemorrhage + any relevant exposure = VHF protocol — highest POE emergency',
        when_all: ['fever', 'bleeding'],
        and_any: ['contact_body_fluids', 'contact_dead_body', 'healthcare_exposure', 'rodent_exposure', 'travel_from_outbreak_area', 'funeral_or_burial_exposure'],
        effect: {
          boost_diseases: { ebola_virus_disease: 18, marburg_virus_disease: 18, lassa_fever: 12, cchf: 18, yellow_fever: 8, rift_valley_fever: 8, hantavirus: 10 },
          force_alert_level: 'critical',
          mandatory_actions: ['FULL_PPE', 'IMMEDIATE_ISOLATION', 'NOTIFY_IHR_FOCAL_POINT'],
        },
      },
      {
        rule_id: 'override_any_haemorrhage_fever_no_exposure',
        priority: 2,
        description: 'Fever + any spontaneous haemorrhage even without confirmed exposure = VHF screening pathway',
        when_all: ['fever'],
        and_any: ['bleeding', 'bleeding_gums_or_nose', 'bloody_sputum', 'petechial_or_purpuric_rash'],
        effect: {
          boost_diseases: { ebola_virus_disease: 10, marburg_virus_disease: 10, cchf: 14, dengue_severe: 12, yellow_fever: 8 },
          force_alert_level: 'critical',
        },
      },
      {
        rule_id: 'override_acute_flaccid_paralysis',
        priority: 3,
        description: 'ANY acute flaccid paralysis = mandatory AFP/polio escalation regardless of all other findings',
        when_any: ['paralysis_acute_flaccid'],
        effect: {
          boost_diseases: { polio: 35, west_nile_fever: 8, japanese_encephalitis: 8 },
          force_alert_level: 'critical',
          mandatory_actions: ['NOTIFY_WHO_AFP_SURVEILLANCE', 'STOOL_SPECIMENS_X2'],
        },
      },
      {
        rule_id: 'override_meningitis_triad',
        priority: 4,
        description: 'Fever + stiff neck = bacterial meningitis/septicaemia — minutes matter',
        when_all: ['fever', 'stiff_neck'],
        effect: {
          boost_diseases: { meningococcal_meningitis: 20 },
          force_alert_level: 'critical',
          mandatory_actions: ['EMERGENCY_MEDICAL_REFERRAL', 'IV_ANTIBIOTICS_WITHOUT_DELAY'],
        },
      },
      {
        rule_id: 'override_vesiculopustular_rash',
        priority: 5,
        description: 'Vesicular/pustular rash = mpox/smallpox pathway',
        when_any: ['rash_vesicular_pustular', 'mucosal_lesions'],
        effect: {
          boost_diseases: { mpox: 16, smallpox: 16 },
          penalize_diseases: { measles: 10, rubella: 10 },
          force_alert_level: 'high',
        },
      },
      {
        rule_id: 'override_smallpox_pustular_centrifugal',
        priority: 6,
        description: 'Pustular centrifugal rash (face/palms/soles > trunk) + fever = GLOBAL BIOLOGICAL EMERGENCY until excluded',
        when_all: ['fever', 'rash_vesicular_pustular', 'rash_face_first'],
        effect: {
          boost_diseases: { smallpox: 30 },
          force_alert_level: 'critical',
          mandatory_actions: ['MAXIMUM_ISOLATION', 'IMMEDIATE_IHR_WHO_NOTIFICATION', 'BIOTERRORISM_PROTOCOL'],
        },
      },
      {
        rule_id: 'override_watery_diarrhea_dehydration',
        priority: 7,
        description: 'Profuse watery diarrhoea OR severe dehydration = cholera protocol immediately',
        when_any: ['watery_diarrhea', 'rice_water_diarrhea', 'severe_dehydration'],
        effect: {
          boost_diseases: { cholera: 18, awd_non_cholera: 8 },
          force_alert_level: 'high',
          mandatory_actions: ['AGGRESSIVE_REHYDRATION', 'ISOLATE_ENTERIC_PRECAUTIONS'],
        },
      },
      {
        rule_id: 'override_rabies_pathognomonic',
        priority: 8,
        description: 'Hydrophobia OR aerophobia = rabies until excluded — virtually 100% fatal once symptomatic',
        when_any: ['hydrophobia', 'aerophobia'],
        effect: {
          boost_diseases: { rabies: 50 },
          force_alert_level: 'critical',
          mandatory_actions: ['EMERGENCY_REFERRAL', 'ASSESS_BITE_HISTORY', 'PEP_EVALUATION'],
        },
      },
      {
        rule_id: 'override_skin_eschar',
        priority: 9,
        description: 'Painless black necrotic skin ulcer = anthrax or rickettsia — bioterrorism consideration if cluster',
        when_any: ['skin_eschar'],
        effect: {
          boost_diseases: { anthrax_cutaneous: 30, rickettsia_scrub_typhus: 18, tularemia: 15 },
          force_alert_level: 'high',
          mandatory_actions: ['SECONDARY_REFERRAL', 'BIOTERRORISM_ASSESSMENT_IF_CLUSTER'],
        },
      },
      {
        rule_id: 'override_ihr_annex2_any_match',
        priority: 10,
        description: 'Any IHR Tier 1 disease in top 3 results = mandatory public health notification',
        applies_to_tiers: ['tier_1_ihr_critical', 'tier_2_ihr_annex2_epidemic_prone'],
        effect: {
          force_alert_level: 'critical',
          mandatory_actions: ['NOTIFY_IHR_NATIONAL_FOCAL_POINT', 'DOCUMENT_IN_SURVEILLANCE_SYSTEM'],
        },
      },
    ],
    output_sorting: [
      'Sort descending by final_score',
      'Break ties by higher priority tier (tier_1 > tier_2 > tier_3)',
      'Then by greater number of matched hallmark symptoms',
      'Then by greater number of matched exposure signals',
      'Always pin tier_1 and tier_2_ihr_annex2 above lower tiers when scores within 8 points',
    ],
  },


  input_schema: {
    selected_symptoms: 'string[] — symptom ids from symptom_catalog',
    explicit_absent_symptoms: 'optional string[] — symptom ids CONFIRMED absent (triggers absent-hallmark penalties)',
    selected_exposures: 'optional string[] — exposure ids from exposure_catalog',
    outbreak_context: 'optional string[] — disease ids currently active in source, destination, or transit corridor',
    vaccination_history: {
      yellow_fever: 'unknown|documented_valid|documented_invalid_or_expired|not_vaccinated',
      measles: 'unknown|documented_valid|not_vaccinated',
      rubella: 'unknown|documented_valid|not_vaccinated',
      polio: 'unknown|documented_valid|not_vaccinated',
      covid_19: 'unknown|documented_valid|not_vaccinated',
      hepatitis_a: 'unknown|documented_valid|not_vaccinated',
      japanese_encephalitis: 'unknown|documented_valid|not_vaccinated',
      rabies: 'unknown|documented_valid|not_vaccinated',
    },
    clinical_context: {
      days_since_onset: 'optional integer',
      temperature_c: 'optional number',
      age_group: 'optional child|adolescent|adult|older_adult',
      pregnant: 'optional boolean',
    },
  },

  symptom_catalog: [
    // ── FEVER ─────────────────────────────────────────────────
    { id: 'fever', label: 'Fever (≥38.0°C)', who_priority: true },
    { id: 'high_fever', label: 'High fever (≥39.0°C)', who_priority: true },
    { id: 'very_high_fever', label: 'Very high fever (≥40.0°C)', who_priority: true },
    { id: 'low_grade_fever', label: 'Low-grade fever (37.5–38.0°C)', who_priority: false },
    { id: 'sudden_onset_fever', label: 'Sudden-onset fever — patient recalls exact hour', who_priority: false },
    { id: 'gradual_onset_fever', label: 'Gradual-onset fever — worsening over days', who_priority: false },
    { id: 'undulant_fever', label: 'Undulant fever — rises and falls cyclically', who_priority: false },
    { id: 'paradoxical_bradycardia', label: 'Paradoxical bradycardia (slow pulse despite fever)', who_priority: false },
    { id: 'chills', label: 'Chills / rigors', who_priority: false },
    { id: 'night_sweats', label: 'Night sweats', who_priority: false },
    // ── RESPIRATORY ───────────────────────────────────────────
    { id: 'cough', label: 'Cough', who_priority: true },
    { id: 'dry_cough', label: 'Dry cough', who_priority: false },
    { id: 'sore_throat', label: 'Sore throat', who_priority: false },
    { id: 'coryza', label: 'Runny / blocked nose', who_priority: false },
    { id: 'shortness_of_breath', label: 'Shortness of breath', who_priority: true },
    { id: 'difficulty_breathing', label: 'Difficulty breathing / respiratory distress', who_priority: true },
    { id: 'rapid_breathing', label: 'Rapid breathing / tachypnoea', who_priority: true },
    { id: 'chest_pain', label: 'Chest pain', who_priority: false },
    { id: 'retrosternal_pain', label: 'Retrosternal / behind-sternum pain', who_priority: false },
    { id: 'bloody_sputum', label: 'Blood-stained sputum / haemoptysis', who_priority: true },
    // ── GASTROINTESTINAL ─────────────────────────────────────
    { id: 'nausea', label: 'Nausea', who_priority: false },
    { id: 'vomiting', label: 'Vomiting', who_priority: false },
    { id: 'persistent_vomiting', label: 'Persistent or severe vomiting', who_priority: true },
    { id: 'hiccups', label: 'Intractable hiccups', who_priority: false },
    { id: 'diarrhea', label: 'Diarrhoea (general)', who_priority: false },
    { id: 'watery_diarrhea', label: 'Acute watery diarrhoea', who_priority: true },
    { id: 'rice_water_diarrhea', label: 'Rice-water / profuse watery stool', who_priority: true },
    { id: 'bloody_diarrhea', label: 'Bloody diarrhoea / dysentery', who_priority: true },
    { id: 'severe_dehydration', label: 'Severe dehydration', who_priority: true },
    { id: 'abdominal_pain', label: 'Abdominal pain', who_priority: false },
    { id: 'abdominal_tenderness', label: 'Abdominal tenderness / guarding', who_priority: false },
    // ── HEPATIC / JAUNDICE ───────────────────────────────────
    { id: 'jaundice', label: 'Jaundice / yellow eyes or skin', who_priority: true },
    { id: 'dark_urine', label: 'Dark urine / cola-coloured urine', who_priority: false },
    { id: 'pale_stools', label: 'Pale / clay-coloured stools', who_priority: false },
    { id: 'right_upper_quadrant_pain', label: 'Right upper quadrant pain / liver tenderness', who_priority: false },
    // ── HAEMORRHAGIC ─────────────────────────────────────────
    { id: 'bleeding', label: 'Bleeding from any site', who_priority: true },
    { id: 'bleeding_gums_or_nose', label: 'Bleeding gums or nose', who_priority: true },
    { id: 'petechial_or_purpuric_rash', label: 'Petechial or purpuric rash', who_priority: true },
    { id: 'bruising_or_ecchymosis', label: 'Easy bruising / ecchymosis', who_priority: false },
    // ── DERMATOLOGICAL / RASH ────────────────────────────────
    { id: 'rash_maculopapular', label: 'Maculopapular generalised rash', who_priority: true },
    { id: 'rash_vesicular_pustular', label: 'Vesicular or pustular rash', who_priority: true },
    { id: 'painful_rash', label: 'Painful rash / lesions', who_priority: false },
    { id: 'rash_face_first', label: 'Rash starting on face / centrifugal distribution', who_priority: true },
    { id: 'rash_palms_soles', label: 'Rash on palms and/or soles', who_priority: false },
    { id: 'mucosal_lesions', label: 'Mucosal lesions (mouth, genital)', who_priority: true },
    { id: 'genital_lesions', label: 'Genital lesions / ulcers', who_priority: false },
    { id: 'skin_eschar', label: 'Painless black necrotic skin ulcer (eschar)', who_priority: true },
    { id: 'rose_spots', label: 'Rose-coloured spots (rose spots) on trunk', who_priority: false },
    // ── LYMPH NODES ──────────────────────────────────────────
    { id: 'swollen_lymph_nodes', label: 'Swollen lymph nodes', who_priority: false },
    { id: 'painful_swollen_lymph_nodes', label: 'Painful swollen lymph nodes / bubo', who_priority: true },
    { id: 'retroauricular_lymph_nodes', label: 'Swollen lymph nodes behind ears / neck', who_priority: false },
    // ── NEUROLOGICAL ─────────────────────────────────────────
    { id: 'headache', label: 'Headache', who_priority: false },
    { id: 'severe_headache', label: 'Severe headache', who_priority: true },
    { id: 'stiff_neck', label: 'Stiff neck / neck rigidity', who_priority: true },
    { id: 'photophobia', label: 'Sensitivity to light / photophobia', who_priority: false },
    { id: 'altered_consciousness', label: 'Altered consciousness / confusion', who_priority: true },
    { id: 'seizures', label: 'Seizures / convulsions', who_priority: true },
    { id: 'paralysis_acute_flaccid', label: 'Acute flaccid paralysis', who_priority: true },
    { id: 'hydrophobia', label: 'Hydrophobia (fear of water / unable to swallow)', who_priority: true },
    { id: 'aerophobia', label: 'Aerophobia (fear of air currents)', who_priority: true },
    { id: 'encephalitis_signs', label: 'Encephalitis / brain inflammation signs', who_priority: true },
    // ── MUSCULOSKELETAL / SYSTEMIC ────────────────────────────
    { id: 'fatigue', label: 'Fatigue', who_priority: false },
    { id: 'severe_fatigue', label: 'Severe fatigue / prostration', who_priority: true },
    { id: 'malaise', label: 'Malaise / generally unwell', who_priority: false },
    { id: 'weakness', label: 'Weakness', who_priority: false },
    { id: 'anorexia', label: 'Loss of appetite / anorexia', who_priority: false },
    { id: 'muscle_pain', label: 'Muscle pain / myalgia', who_priority: false },
    { id: 'calf_muscle_pain', label: 'Severe calf muscle pain / tenderness', who_priority: false },
    { id: 'joint_pain', label: 'Joint pain / arthralgia', who_priority: false },
    { id: 'severe_joint_pain', label: 'Severe / debilitating joint pain', who_priority: false },
    { id: 'back_pain', label: 'Back pain', who_priority: false },
    { id: 'limb_pain', label: 'Pain in limbs', who_priority: false },
    { id: 'chest_tightness', label: 'Chest tightness', who_priority: false },
    // ── EYE / EAR / FACE ─────────────────────────────────────
    { id: 'conjunctivitis', label: 'Conjunctivitis / red eyes', who_priority: false },
    { id: 'pain_behind_eyes', label: 'Pain behind the eyes', who_priority: false },
    { id: 'facial_swelling', label: 'Facial swelling', who_priority: false },
    { id: 'hearing_loss', label: 'Hearing loss', who_priority: false },
    // ── RESPIRATORY / SMELL / TASTE ─────────────────────────
    { id: 'loss_of_taste_smell', label: 'Loss or change of taste/smell', who_priority: false },
    // ── SPECIAL ──────────────────────────────────────────────
    { id: 'splenomegaly', label: 'Enlarged spleen / splenomegaly', who_priority: false },
    { id: 'hepatomegaly', label: 'Enlarged liver / hepatomegaly', who_priority: false },
    { id: 'cold_pale_skin', label: 'Pale or cold skin / shock signs', who_priority: false },
    { id: 'dizziness', label: 'Dizziness / vertigo', who_priority: false },
    { id: 'low_energy', label: 'Low energy', who_priority: false },
    { id: 'weight_loss', label: 'Unexplained weight loss', who_priority: false },
  ],

  exposure_catalog: [
    { id: 'close_contact_case', label: 'Close contact with symptomatic case' },
    { id: 'contact_body_fluids', label: 'Direct contact with body fluids' },
    { id: 'contact_dead_body', label: 'Contact with dead body / funeral exposure' },
    { id: 'funeral_or_burial_exposure', label: 'Funeral or burial participation' },
    { id: 'healthcare_exposure', label: 'Healthcare or caregiving exposure' },
    { id: 'travel_from_outbreak_area', label: 'Travel from outbreak/affected area' },
    { id: 'residence_in_outbreak_area', label: 'Residence in outbreak/affected area' },
    { id: 'unsafe_water', label: 'Unsafe water / poor sanitation' },
    { id: 'contaminated_food_or_water', label: 'Likely contaminated food or water' },
    { id: 'poultry_or_live_bird_exposure', label: 'Poultry / live bird / bird market exposure' },
    { id: 'camel_exposure_or_mideast_healthcare', label: 'Camel exposure or recent healthcare in Middle East' },
    { id: 'rodent_exposure', label: 'Rodent exposure / rodent-contaminated surfaces' },
    { id: 'mosquito_exposure', label: 'Mosquito exposure in endemic area' },
    { id: 'flood_livestock_exposure', label: 'Livestock, raw animal tissues, or flood/agricultural outbreak' },
    { id: 'contact_with_rash_case', label: 'Contact with person with rash illness' },
    { id: 'unvaccinated_or_unknown_vaccination', label: 'Unvaccinated or vaccination status unknown' },
    { id: 'contact_with_paralysis_case', label: 'Contact with acute flaccid paralysis case' },
    { id: 'flea_or_rodent_exposure', label: 'Flea bite / rodent exposure' },
    { id: 'animal_bite_or_wildlife_contact', label: 'Animal bite, scratch, or wildlife carcass contact' },
    { id: 'sexual_contact', label: 'Recent sexual contact with symptomatic person' },
    { id: 'laboratory_exposure', label: 'Laboratory exposure (biosafety incident)' },
    { id: 'affected_healthcare_facility_exposure', label: 'Exposure to affected healthcare facility or cluster' },
    { id: 'crowded_closed_setting', label: 'Crowded enclosed setting / respiratory spread risk' },
    { id: 'tick_bite', label: 'Tick bite or exposure in tick-endemic area' },
    { id: 'raw_meat_or_unpasteurised_dairy', label: 'Raw meat, blood, or unpasteurised dairy consumption' },
    { id: 'fresh_water_contact', label: 'Fresh water contact (rivers, flooding, wading)' },
  ],

  priority_tiers: {
    tier_1_ihr_critical: 'IHR Tier 1 — Always Notifiable (PHEIC by definition regardless of score)',
    tier_2_ihr_annex2: 'IHR Tier 2 — Annex 2 epidemic-prone disease (four-question decision instrument)',
    tier_2_ihr_equivalent: 'IHR-equivalent WHO AFRO Priority 1 disease',
    tier_3_who_notifiable: 'WHO notifiable / national reporting required',
    tier_4_syndromic: 'Important travel-medicine and syndromic differential',
  },

  diseases: [

    // ════════════════════════════════════════════════════════════════════
    // TIER 1 — IHR ALWAYS NOTIFIABLE (4 diseases)
    // A single confirmed case = PHEIC by definition. Report to WHO.
    // ════════════════════════════════════════════════════════════════════

    {
      id: 'smallpox', name: 'Smallpox', priority_tier: 'tier_1_ihr_critical',
      who_category: 'IHR_TIER1_ALWAYS_NOTIFIABLE', severity: 5, case_fatality_rate_pct: 30,
      syndromes: ['vesiculopustular_rash', 'high_consequence_rash'],
      incubation_days: { min: 7, max: 17, typical: '10-14' },
      gates: {
        required_all: ['fever'],
        required_any: ['rash_vesicular_pustular'],
        soft_require_any: ['rash_face_first', 'rash_palms_soles', 'back_pain'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'rash_vesicular_pustular', 'rash_face_first'],
      key_distinguishers: [
        'Centrifugal distribution (face/extremities > trunk)', 'Palms and soles involved',
        'All lesions at same stage of development (synchronous)', 'Deep, rounded, firm lesions',
        'Severe prodrome (3-4 days) before rash', 'Absence of lymphadenopathy (vs mpox)',
      ],
      symptom_weights: {
        fever: 12, high_fever: 14, very_high_fever: 16, severe_fatigue: 8, back_pain: 12,
        vomiting: 4, abdominal_pain: 4, rash_vesicular_pustular: 24, rash_face_first: 12,
        rash_palms_soles: 12, mucosal_lesions: 8,
      },
      absent_hallmark_penalties: { rash_vesicular_pustular: -24 },
      exposure_weights: { close_contact_case: 14, laboratory_exposure: 14, travel_from_outbreak_area: 8 },
      negative_weights: { swollen_lymph_nodes: -10, conjunctivitis: -6, coryza: -6, loss_of_taste_smell: -8, watery_diarrhea: -8 },
      outbreak_bonus: 20, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['Immediate WHO/national public health emergency pathway — BSL-4 only', 'DO NOT attempt routine lab — activate bioterrorism protocol'],
      immediate_actions: ['MAXIMUM ISOLATION', 'IMMEDIATE IHR WHO NOTIFICATION', 'BIOTERRORISM PROTOCOL', 'Contact tracing of all contacts since rash onset'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_7_to_17: 3 },
      who_basis: 'IHR 2005 — always notifiable. WHO smallpox eradication surveillance guidance.',
    },

    {
      id: 'sars', name: 'Severe Acute Respiratory Syndrome (SARS)', priority_tier: 'tier_1_ihr_critical',
      who_category: 'IHR_TIER1_ALWAYS_NOTIFIABLE', severity: 5, case_fatality_rate_pct: 10,
      syndromes: ['severe_respiratory'],
      incubation_days: { min: 2, max: 10, typical: '4-6' },
      gates: {
        required_all: ['fever'],
        required_any: ['cough', 'shortness_of_breath', 'difficulty_breathing'],
        soft_require_any: ['close_contact_case', 'affected_healthcare_facility_exposure'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'cough', 'shortness_of_breath'],
      key_distinguishers: ['Rapid progression to pneumonia', 'Healthcare cluster association', 'No rhinitis (coryza) typical'],
      symptom_weights: {
        fever: 12, high_fever: 14, cough: 12, shortness_of_breath: 16, difficulty_breathing: 16,
        rapid_breathing: 12, headache: 6, malaise: 8, muscle_pain: 6, diarrhea: 4, chills: 6,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { close_contact_case: 16, affected_healthcare_facility_exposure: 12, travel_from_outbreak_area: 12, healthcare_exposure: 10 },
      negative_weights: { rash_vesicular_pustular: -12, loss_of_taste_smell: -8, watery_diarrhea: -8, jaundice: -10, coryza: -4 },
      outbreak_bonus: 18, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['Coronavirus PCR via public health reference laboratory', 'Chest X-ray / CT'],
      immediate_actions: ['IMMEDIATE ISOLATION', 'FULL PPE + N95', 'IMMEDIATE IHR NOTIFICATION'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_2_to_10: 4 },
      who_basis: 'IHR 2005 — always notifiable. Retained in scoring engine per IHR legal obligation.',
    },

    {
      id: 'influenza_new_subtype_zoonotic', name: 'Zoonotic / Novel Influenza (H5N1, H7N9 etc.)',
      priority_tier: 'tier_1_ihr_critical',
      who_category: 'IHR_TIER1_ALWAYS_NOTIFIABLE', severity: 5, case_fatality_rate_pct: 30,
      syndromes: ['acute_respiratory', 'influenza_like_illness', 'severe_respiratory'],
      incubation_days: { min: 1, max: 10, typical: '2-8' },
      gates: {
        required_any: ['fever', 'high_fever', 'sudden_onset_fever'],
        soft_require_any: ['cough', 'shortness_of_breath', 'poultry_or_live_bird_exposure'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'cough'],
      key_distinguishers: ['Poultry/live bird exposure', 'Rapid progression to severe pneumonia', 'Unusually severe for influenza'],
      symptom_weights: {
        sudden_onset_fever: 12, fever: 10, high_fever: 12, cough: 14, sore_throat: 6,
        muscle_pain: 8, fatigue: 8, shortness_of_breath: 10, difficulty_breathing: 10, rapid_breathing: 8,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { poultry_or_live_bird_exposure: 20, travel_from_outbreak_area: 8, close_contact_case: 8, affected_healthcare_facility_exposure: 6 },
      negative_weights: { loss_of_taste_smell: -8, rash_vesicular_pustular: -10, jaundice: -10, stiff_neck: -10 },
      outbreak_bonus: 18, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['Influenza PCR + subtyping via national reference lab', 'Chest imaging'],
      immediate_actions: ['IMMEDIATE ISOLATION', 'N95 + full PPE', 'IMMEDIATE IHR NOTIFICATION', 'Contact tracing of all poultry/bird exposures'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_1_to_10: 4 },
      who_basis: 'IHR 2005 — always notifiable. WHO zoonotic influenza risk assessment guidance.',
    },

    {
      id: 'polio', name: 'Poliomyelitis / Acute Flaccid Paralysis', priority_tier: 'tier_1_ihr_critical',
      who_category: 'IHR_TIER1_ALWAYS_NOTIFIABLE', severity: 5, case_fatality_rate_pct: 5,
      syndromes: ['neurologic', 'acute_flaccid_paralysis'],
      incubation_days: { min: 3, max: 35, typical: '7-14' },
      gates: {
        required_any: ['paralysis_acute_flaccid'],
        hard_fail_if_absent: ['paralysis_acute_flaccid'],
        soft_require_any: ['fever', 'weakness'],
      },
      hallmarks: ['paralysis_acute_flaccid'],
      key_distinguishers: ['Asymmetric flaccid paralysis', 'No sensory loss', 'Rapid onset (1-3 days)', 'Preceding fever/prodrome'],
      symptom_weights: {
        paralysis_acute_flaccid: 34, fever: 8, fatigue: 6, headache: 6, vomiting: 6,
        stiff_neck: 8, limb_pain: 8, weakness: 10, muscle_pain: 6,
      },
      absent_hallmark_penalties: { paralysis_acute_flaccid: -60 },
      exposure_weights: { contact_with_paralysis_case: 14, travel_from_outbreak_area: 10, unvaccinated_or_unknown_vaccination: 12 },
      negative_weights: { rash_vesicular_pustular: -12, cough: -8, watery_diarrhea: -6, jaundice: -10 },
      outbreak_bonus: 18, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['Two stool specimens 24-48h apart for AFP investigation', 'Submit to WHO-accredited poliovirus laboratory'],
      immediate_actions: ['IMMEDIATE WHO AFP SURVEILLANCE NOTIFICATION', 'COLLECT STOOL SPECIMENS × 2', 'Case investigation within 48h'],
      vaccination_modifiers: { polio: { documented_valid: -10 } },
      onset_modifiers: { days_since_onset_0_to_10: 4 },
      who_basis: 'IHR 2005 — always notifiable. WHO GPEI AFP surveillance case definition.',
    },

    // ════════════════════════════════════════════════════════════════════
    // TIER 2 — IHR ANNEX 2 / WHO AFRO PRIORITY 1 (26 diseases)
    // ════════════════════════════════════════════════════════════════════

    {
      id: 'cholera', name: 'Cholera', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 4, case_fatality_rate_pct: 1,
      syndromes: ['acute_watery_diarrhea', 'dehydrating_diarrhea'],
      incubation_days: { min: 0.2, max: 5, typical: '2-3' },
      gates: {
        required_any: ['watery_diarrhea', 'rice_water_diarrhea', 'severe_dehydration'],
        hard_fail_if_absent: ['watery_diarrhea'],
        soft_require_any: [],
      },
      hallmarks: ['watery_diarrhea', 'rice_water_diarrhea', 'severe_dehydration'],
      key_distinguishers: ['Profuse painless watery diarrhoea', 'Rice-water stools', 'Rapid severe dehydration', 'No/low fever', 'Muscle cramps from electrolyte loss'],
      symptom_weights: {
        watery_diarrhea: 28, rice_water_diarrhea: 35, severe_dehydration: 24,
        vomiting: 10, diarrhea: 8, abdominal_pain: 4, weakness: 4, muscle_pain: 6, calf_muscle_pain: 8,
      },
      absent_hallmark_penalties: { watery_diarrhea: -20 },
      exposure_weights: { unsafe_water: 18, contaminated_food_or_water: 14, travel_from_outbreak_area: 10, residence_in_outbreak_area: 10 },
      negative_weights: { bloody_diarrhea: -16, fever: -10, high_fever: -14, rash_maculopapular: -8, stiff_neck: -10, jaundice: -8 },
      outbreak_bonus: 18, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Stool culture (Vibrio cholerae O1/O139)', 'Validated cholera RDT', 'Clinical dehydration assessment'],
      immediate_actions: ['AGGRESSIVE IV/ORS REHYDRATION', 'Enteric precautions isolation', 'Notify surveillance team', 'Contact trace source of water/food'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_0_to_5: 6 },
      who_basis: 'WHO Global Cholera and AGE Control Coalition. IHR Annex 2 epidemic-prone disease.',
    },

    {
      id: 'yellow_fever', name: 'Yellow fever', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 5, case_fatality_rate_pct: 30,
      syndromes: ['acute_febrile', 'jaundice_hemorrhagic'],
      incubation_days: { min: 3, max: 6, typical: '3-6' },
      gates: {
        required_any: ['fever', 'high_fever', 'jaundice'],
        soft_require_any: ['jaundice', 'dark_urine', 'bleeding'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'jaundice'],
      key_distinguishers: ['Biphasic illness (remission then toxic phase)', 'Jaundice + bleeding + renewed fever = toxic phase', 'Dark urine / haemorrhage', 'Relative bradycardia (Faget\'s sign)'],
      symptom_weights: {
        fever: 12, high_fever: 14, headache: 8, muscle_pain: 8, back_pain: 8, nausea: 6,
        vomiting: 8, weakness: 8, jaundice: 26, dark_urine: 14, abdominal_pain: 10, bleeding: 12,
        paradoxical_bradycardia: 10, right_upper_quadrant_pain: 8,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { mosquito_exposure: 10, travel_from_outbreak_area: 12, residence_in_outbreak_area: 12, unvaccinated_or_unknown_vaccination: 8 },
      negative_weights: { coryza: -8, conjunctivitis: -6, paralysis_acute_flaccid: -12, watery_diarrhea: -6, loss_of_taste_smell: -8 },
      outbreak_bonus: 16, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['Yellow fever PCR (early) / IgM serology (after day 5)', 'Liver function tests', 'Renal function'],
      immediate_actions: ['Mosquito bite prevention around case', 'Urgent isolation/referral', 'Immediate public health notification', 'Vaccination status verification'],
      vaccination_modifiers: { yellow_fever: { documented_valid: -14, documented_invalid_or_expired: -4 } },
      onset_modifiers: { days_since_onset_3_to_7: 4 },
      who_basis: 'WHO Yellow Fever fact sheet. IHR Annex 2 epidemic-prone disease. Required vaccination at POE.',
    },

    {
      id: 'ebola_virus_disease', name: 'Ebola Virus Disease (EVD)', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 5, case_fatality_rate_pct: 50,
      syndromes: ['acute_febrile', 'vhf', 'gastrointestinal_hemorrhagic'],
      incubation_days: { min: 2, max: 21, typical: '4-10' },
      gates: {
        required_any: ['fever', 'high_fever'],
        soft_require_any: ['vomiting', 'diarrhea', 'bleeding', 'severe_fatigue', 'contact_body_fluids', 'travel_from_outbreak_area'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'severe_fatigue', 'vomiting', 'diarrhea', 'bleeding'],
      key_distinguishers: ['Intense fatigue + GI symptoms early', 'Haemorrhage in late phase', 'Hiccups (late sign)', 'Funeral/contact exposure critical', 'Smell and taste preserved'],
      symptom_weights: {
        fever: 14, high_fever: 16, very_high_fever: 18, fatigue: 10, severe_fatigue: 14,
        malaise: 10, muscle_pain: 8, headache: 8, sore_throat: 6, vomiting: 10, diarrhea: 10,
        watery_diarrhea: 8, abdominal_pain: 10, rash_maculopapular: 6, bleeding: 16,
        bleeding_gums_or_nose: 12, weakness: 8, hiccups: 10, anorexia: 6,
      },
      absent_hallmark_penalties: {},
      exposure_weights: {
        contact_body_fluids: 20, contact_dead_body: 22, funeral_or_burial_exposure: 18,
        healthcare_exposure: 14, travel_from_outbreak_area: 14, residence_in_outbreak_area: 12,
        close_contact_case: 16, laboratory_exposure: 18,
      },
      negative_weights: { coryza: -4, loss_of_taste_smell: -6, pain_behind_eyes: -4, paralysis_acute_flaccid: -10 },
      outbreak_bonus: 18, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['Ebola PCR per national/WHO algorithm (whole blood in outbreak context)', 'DO NOT obtain blood without full PPE'],
      immediate_actions: ['IMMEDIATE ISOLATION', 'FULL PPE (coverall + N95 + face shield + double gloves)', 'IMMEDIATE IHR/PUBLIC HEALTH ESCALATION', 'Contact trace all contacts within 21 days'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_2_to_21: 5 },
      who_basis: 'WHO Ebola disease fact sheet. VHF red-flag logic per IHR Annex 2.',
    },

    {
      id: 'marburg_virus_disease', name: 'Marburg Virus Disease (MVD)', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 5, case_fatality_rate_pct: 50,
      syndromes: ['acute_febrile', 'vhf', 'gastrointestinal_hemorrhagic'],
      incubation_days: { min: 2, max: 21, typical: '5-10' },
      gates: {
        required_any: ['fever', 'high_fever'],
        soft_require_any: ['severe_headache', 'severe_fatigue', 'diarrhea', 'vomiting', 'bleeding', 'travel_from_outbreak_area'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['high_fever', 'severe_headache', 'severe_fatigue'],
      key_distinguishers: ['Abrupt onset with intense headache', 'Maculopapular rash (day 5)', 'Haemorrhage (day 5-7)', 'Cave/mine bat exposure (fruit bats)', 'Clinically indistinguishable from Ebola'],
      symptom_weights: {
        fever: 12, high_fever: 18, severe_headache: 14, headache: 8, severe_fatigue: 16,
        malaise: 12, muscle_pain: 8, diarrhea: 12, watery_diarrhea: 10, abdominal_pain: 10,
        vomiting: 10, rash_maculopapular: 6, bleeding: 14, hiccups: 8,
      },
      absent_hallmark_penalties: {},
      exposure_weights: {
        contact_body_fluids: 20, contact_dead_body: 22, healthcare_exposure: 14,
        travel_from_outbreak_area: 16, residence_in_outbreak_area: 12, close_contact_case: 16, laboratory_exposure: 18,
      },
      negative_weights: { coryza: -4, loss_of_taste_smell: -6, pain_behind_eyes: -4, paralysis_acute_flaccid: -10 },
      outbreak_bonus: 18, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['Marburg PCR per national/WHO algorithm'],
      immediate_actions: ['IMMEDIATE ISOLATION', 'FULL PPE', 'IMMEDIATE IHR NOTIFICATION', 'Contact trace all contacts'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_2_to_21: 5 },
      who_basis: 'WHO Marburg virus disease fact sheet.',
    },

    {
      id: 'lassa_fever', name: 'Lassa fever', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 4, case_fatality_rate_pct: 15,
      syndromes: ['acute_febrile', 'vhf', 'respiratory_gastrointestinal_mixed'],
      incubation_days: { min: 2, max: 21, typical: '6-21' },
      gates: {
        required_any: ['fever', 'high_fever', 'gradual_onset_fever'],
        soft_require_any: ['sore_throat', 'cough', 'chest_pain', 'hearing_loss', 'rodent_exposure', 'bleeding'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['gradual_onset_fever', 'malaise', 'headache'],
      key_distinguishers: ['Gradual insidious onset', 'Sore throat + chest pain', 'Facial oedema (late)', 'Hearing loss (sequela)', 'Mastomys rat exposure — West Africa endemic'],
      symptom_weights: {
        fever: 10, high_fever: 10, gradual_onset_fever: 14, weakness: 10, malaise: 10,
        headache: 8, sore_throat: 10, muscle_pain: 6, chest_pain: 10, nausea: 6, vomiting: 8,
        diarrhea: 8, cough: 8, abdominal_pain: 8, facial_swelling: 14, bleeding: 12, hearing_loss: 12, anorexia: 6,
      },
      absent_hallmark_penalties: {},
      exposure_weights: {
        rodent_exposure: 18, travel_from_outbreak_area: 10, residence_in_outbreak_area: 10, close_contact_case: 12, healthcare_exposure: 10,
      },
      negative_weights: { loss_of_taste_smell: -6, rash_vesicular_pustular: -10, pain_behind_eyes: -4, paralysis_acute_flaccid: -10 },
      outbreak_bonus: 14, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['Lassa PCR / serology per national algorithm', 'Full blood count — thrombocytopenia common'],
      immediate_actions: ['IMMEDIATE ISOLATION', 'IPC precautions', 'Urgent ribavirin referral if high clinical suspicion'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_2_to_21: 4 },
      who_basis: 'WHO Lassa fever fact sheet. Endemic in West Africa Mastomys rat belt.',
    },

    {
      id: 'cchf', name: 'Crimean-Congo Haemorrhagic Fever (CCHF)', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 5, case_fatality_rate_pct: 30,
      syndromes: ['vhf', 'acute_febrile', 'tick_borne_hemorrhagic'],
      incubation_days: { min: 1, max: 14, typical: '3-7' },
      gates: {
        required_any: ['fever', 'high_fever'],
        soft_require_any: ['bleeding', 'tick_bite', 'livestock_exposure', 'healthcare_exposure'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'bleeding'],
      key_distinguishers: ['Tick bite or livestock slaughter exposure', 'Rapid progression to haemorrhage', 'Healthcare worker transmission', 'Widespread ecchymosis', 'Eastern Europe/Middle East/Central Asia/Africa'],
      symptom_weights: {
        fever: 14, high_fever: 16, very_high_fever: 16, headache: 10, severe_headache: 12,
        muscle_pain: 10, malaise: 10, nausea: 8, vomiting: 10, diarrhea: 6, abdominal_pain: 10,
        bleeding: 22, bleeding_gums_or_nose: 16, petechial_or_purpuric_rash: 14, bruising_or_ecchymosis: 16,
        weakness: 8, dizziness: 6,
      },
      absent_hallmark_penalties: {},
      exposure_weights: {
        tick_bite: 20, flood_livestock_exposure: 16, raw_meat_or_unpasteurised_dairy: 10,
        healthcare_exposure: 14, travel_from_outbreak_area: 12, contact_body_fluids: 14,
      },
      negative_weights: { coryza: -6, loss_of_taste_smell: -8, rash_vesicular_pustular: -10, watery_diarrhea: -6 },
      outbreak_bonus: 18, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['CCHF PCR or serology per BSL-3/national reference lab', 'Full blood count — severe thrombocytopenia'],
      immediate_actions: ['FULL PPE + contact/blood precautions', 'Urgent isolation', 'Notify public health authority immediately'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_1_to_14: 5 },
      who_basis: 'WHO CCHF fact sheet 2024. IHR Annex 2 — requires special attention.',
    },

    {
      id: 'rift_valley_fever', name: 'Rift Valley fever (RVF)', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 4, case_fatality_rate_pct: 1,
      syndromes: ['arboviral', 'vhf_possible', 'zoonotic'],
      incubation_days: { min: 2, max: 6, typical: '2-6' },
      gates: {
        required_any: ['fever', 'high_fever'],
        soft_require_any: ['back_pain', 'dizziness', 'flood_livestock_exposure', 'bleeding'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'back_pain', 'dizziness'],
      key_distinguishers: ['Flood/livestock exposure', 'Mosquito-borne + direct animal contact', 'Most cases mild — minority develop haemorrhage or encephalitis', 'East/Central Africa, Arabian Peninsula'],
      symptom_weights: {
        fever: 12, high_fever: 12, weakness: 10, back_pain: 12, dizziness: 10, muscle_pain: 6,
        bleeding: 10, headache: 6, nausea: 6, vomiting: 6, altered_consciousness: 8, jaundice: 8,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { flood_livestock_exposure: 20, mosquito_exposure: 10, travel_from_outbreak_area: 10, residence_in_outbreak_area: 10, animal_bite_or_wildlife_contact: 6 },
      negative_weights: { coryza: -8, loss_of_taste_smell: -8, rash_vesicular_pustular: -10, stiff_neck: -10 },
      outbreak_bonus: 14, alert_level_if_top_ranked: 'high',
      recommended_tests: ['RVF PCR / serology per protocol'],
      immediate_actions: ['Urgent referral if haemorrhagic or neurological signs present'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_2_to_6: 4 },
      who_basis: 'WHO Rift Valley fever fact sheet. IHR Annex 2 epidemic-prone.',
    },

    {
      id: 'mpox', name: 'Mpox (Monkeypox)', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 3, case_fatality_rate_pct: 1,
      syndromes: ['vesiculopustular_rash', 'febrile_rash'],
      incubation_days: { min: 1, max: 21, typical: '6-16' },
      gates: {
        required_any: ['rash_vesicular_pustular', 'painful_rash', 'mucosal_lesions', 'genital_lesions'],
        soft_require_any: ['swollen_lymph_nodes', 'fever', 'rash_palms_soles'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['rash_vesicular_pustular', 'swollen_lymph_nodes'],
      key_distinguishers: ['LYMPHADENOPATHY — the major differentiator from smallpox', 'Lesions in same stage simultaneously', 'Palm/sole lesions', 'Genital/perianal lesions (clade Ib)', 'Painful lesions'],
      symptom_weights: {
        rash_vesicular_pustular: 24, painful_rash: 12, mucosal_lesions: 14, genital_lesions: 14,
        rash_face_first: 8, rash_palms_soles: 12, fever: 10, headache: 6, muscle_pain: 6,
        back_pain: 8, low_energy: 6, swollen_lymph_nodes: 18, sore_throat: 6, fatigue: 8,
      },
      absent_hallmark_penalties: {},
      exposure_weights: {
        close_contact_case: 14, contact_with_rash_case: 16, sexual_contact: 14,
        travel_from_outbreak_area: 10, residence_in_outbreak_area: 10, animal_bite_or_wildlife_contact: 8, healthcare_exposure: 8,
      },
      negative_weights: { coryza: -6, dry_cough: -4, loss_of_taste_smell: -6, rash_maculopapular: -4 },
      outbreak_bonus: 16, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Mpox PCR from lesion swab (ideally roof of lesion or lesion fluid)', 'Submit to national reference laboratory'],
      immediate_actions: ['Contact/lesion precautions', 'Isolate — airborne precautions for clade Ib', 'Notify surveillance team', 'Sexual history and contact tracing'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_1_to_21: 4 },
      who_basis: 'WHO Mpox fact sheet 2024. WHO PHEIC declared 2022 (lifted 2023). New PHEIC August 2024 (clade Ib).',
    },

    {
      id: 'meningococcal_meningitis', name: 'Meningococcal meningitis', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 5, case_fatality_rate_pct: 10,
      syndromes: ['meningitis', 'neurologic'],
      incubation_days: { min: 2, max: 10, typical: '4' },
      gates: {
        required_all: ['fever'],
        required_any: ['stiff_neck', 'altered_consciousness', 'photophobia', 'severe_headache'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'stiff_neck'],
      key_distinguishers: ['Meningismus triad (fever + stiff neck + photophobia)', 'Petechial/purpuric rash = meningococcaemia', 'Rapid deterioration (hours)', 'Meningitis Belt (sub-Saharan Africa) + Hajj clusters'],
      symptom_weights: {
        fever: 12, high_fever: 14, stiff_neck: 26, headache: 12, severe_headache: 14,
        photophobia: 12, vomiting: 8, altered_consciousness: 16, seizures: 10, petechial_or_purpuric_rash: 12,
      },
      absent_hallmark_penalties: { stiff_neck: -12 },
      exposure_weights: { close_contact_case: 10, travel_from_outbreak_area: 10, residence_in_outbreak_area: 10, crowded_closed_setting: 8 },
      negative_weights: { watery_diarrhea: -12, loss_of_taste_smell: -8, jaundice: -8, paralysis_acute_flaccid: -10 },
      outbreak_bonus: 16, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['LP/CSF analysis per protocol', 'Blood cultures (before antibiotics if safe to delay briefly)', 'Meningococcal PCR'],
      immediate_actions: ['EMERGENCY ANTIBIOTICS WITHOUT DELAY (ceftriaxone)', 'Droplet precautions', 'Immediate public health notification if cluster/outbreak'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_0_to_10: 4 },
      who_basis: 'WHO AFRO IDSR meningitis case definition. WHO Meningitis Belt surveillance.',
    },

    {
      id: 'measles', name: 'Measles', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 3, case_fatality_rate_pct: 1,
      syndromes: ['febrile_rash', 'respiratory_rash'],
      incubation_days: { min: 7, max: 23, typical: '10-14' },
      gates: {
        required_all: ['fever', 'rash_maculopapular'],
        required_any: ['cough', 'coryza', 'conjunctivitis'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'rash_maculopapular', 'cough', 'coryza', 'conjunctivitis'],
      key_distinguishers: ['3 Cs: Cough + Coryza + Conjunctivitis', 'Koplik spots (early, pathognomonic)', 'Cephalocaudal rash spread (face first)', 'Non-vesicular confluent rash', 'High transmissibility (R0=15)'],
      symptom_weights: {
        fever: 12, high_fever: 14, rash_maculopapular: 18, cough: 10, coryza: 10, conjunctivitis: 10, malaise: 6, anorexia: 4,
      },
      absent_hallmark_penalties: { rash_maculopapular: -12 },
      exposure_weights: {
        contact_with_rash_case: 14, close_contact_case: 10, travel_from_outbreak_area: 12,
        residence_in_outbreak_area: 12, unvaccinated_or_unknown_vaccination: 12, crowded_closed_setting: 6,
      },
      negative_weights: { rash_vesicular_pustular: -16, swollen_lymph_nodes: -4, retroauricular_lymph_nodes: -6, severe_joint_pain: -8, watery_diarrhea: -8 },
      outbreak_bonus: 16, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Measles IgM / PCR per national algorithm', 'Urine PCR if IgM negative in early rash'],
      immediate_actions: ['AIRBORNE PRECAUTIONS', 'Public health notification', 'Contact vaccination status check', 'Post-exposure prophylaxis within 72h'],
      vaccination_modifiers: { measles: { documented_valid: -14 } },
      onset_modifiers: { days_since_onset_7_to_23: 3 },
      who_basis: 'WHO AFRO IDSR measles case definition. WHO surveillance standard.',
    },

    {
      id: 'rubella', name: 'Rubella', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 2, case_fatality_rate_pct: 0.1,
      syndromes: ['febrile_rash', 'mild_rash_illness'],
      incubation_days: { min: 12, max: 23, typical: '14' },
      gates: {
        required_all: ['rash_maculopapular'],
        required_any: ['low_grade_fever', 'retroauricular_lymph_nodes', 'conjunctivitis'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['rash_maculopapular', 'retroauricular_lymph_nodes'],
      key_distinguishers: ['Posterior auricular/suboccipital lymphadenopathy (distinguishes from measles)', 'Mild low-grade fever', 'Rash fades within 3 days', 'Joint pain (women)', 'Congenital rubella syndrome risk in pregnancy'],
      symptom_weights: {
        rash_maculopapular: 16, low_grade_fever: 12, fever: 6, retroauricular_lymph_nodes: 18,
        swollen_lymph_nodes: 8, conjunctivitis: 6, joint_pain: 8, severe_joint_pain: 10, nausea: 4,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { contact_with_rash_case: 10, close_contact_case: 8, travel_from_outbreak_area: 10, residence_in_outbreak_area: 10, unvaccinated_or_unknown_vaccination: 10 },
      negative_weights: { high_fever: -8, cough: -6, coryza: -6, rash_vesicular_pustular: -16, painful_rash: -10 },
      outbreak_bonus: 12, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Rubella IgM / PCR per national algorithm (especially in pregnancy)'],
      immediate_actions: ['Public health notification', 'PREGNANCY RISK ASSESSMENT — congenital rubella syndrome', 'Contact tracing'],
      vaccination_modifiers: { rubella: { documented_valid: -12 } },
      onset_modifiers: { days_since_onset_12_to_23: 3 },
      who_basis: 'WHO rubella surveillance. Critical for CRS prevention.',
    },

    {
      id: 'pneumonic_plague', name: 'Pneumonic plague', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 5, case_fatality_rate_pct: 50,
      syndromes: ['severe_respiratory', 'plague'],
      incubation_days: { min: 1, max: 4, typical: '1-3' },
      gates: {
        required_all: ['fever'],
        required_any: ['cough', 'bloody_sputum', 'difficulty_breathing', 'shortness_of_breath'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'cough', 'bloody_sputum'],
      key_distinguishers: ['Haemoptysis = pneumonic plague until excluded in endemic area', 'Rapid fatal course without antibiotics (24-72h)', 'Highly contagious person-to-person', 'Flea/rodent exposure OR secondary from bubonic'],
      symptom_weights: {
        fever: 12, high_fever: 14, cough: 12, bloody_sputum: 22, chest_pain: 10,
        shortness_of_breath: 14, difficulty_breathing: 14, weakness: 6, chills: 8, severe_headache: 8,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { close_contact_case: 14, travel_from_outbreak_area: 10, flea_or_rodent_exposure: 10 },
      negative_weights: { loss_of_taste_smell: -8, rash_vesicular_pustular: -10, watery_diarrhea: -8, jaundice: -10 },
      outbreak_bonus: 16, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['Plague testing via national/WHO reference laboratory (BSL-2+)', 'Chest X-ray'],
      immediate_actions: ['IMMEDIATE ISOLATION (DROPLET + CONTACT)', 'URGENT ANTIBIOTICS (doxycycline/gentamicin)', 'PUBLIC HEALTH NOTIFICATION'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_1_to_4: 4 },
      who_basis: 'WHO Plague fact sheet. IHR Annex 2 epidemic-prone disease.',
    },

    {
      id: 'bubonic_plague', name: 'Bubonic plague', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 4, case_fatality_rate_pct: 10,
      syndromes: ['lymphadenitic_febrile', 'plague'],
      incubation_days: { min: 2, max: 6, typical: '2-6' },
      gates: {
        required_all: ['fever'],
        required_any: ['painful_swollen_lymph_nodes'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'painful_swollen_lymph_nodes'],
      key_distinguishers: ['Inguinal/axillary/cervical bubo — exquisitely painful', 'Rapid onset (2-6 days)', 'Flea/rodent exposure in plague-endemic area', 'Can progress to septicaemic or pneumonic plague'],
      symptom_weights: {
        fever: 12, high_fever: 14, painful_swollen_lymph_nodes: 26, swollen_lymph_nodes: 10,
        headache: 6, weakness: 6, vomiting: 4, nausea: 4, chills: 8, muscle_pain: 6,
      },
      absent_hallmark_penalties: { painful_swollen_lymph_nodes: -18 },
      exposure_weights: { flea_or_rodent_exposure: 18, travel_from_outbreak_area: 8, animal_bite_or_wildlife_contact: 8 },
      negative_weights: { cough: -8, loss_of_taste_smell: -8, watery_diarrhea: -8, jaundice: -10, rash_vesicular_pustular: -10 },
      outbreak_bonus: 14, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['Plague rapid diagnostic test', 'Culture/PCR from bubo aspirate'],
      immediate_actions: ['URGENT ANTIBIOTICS', 'Contact precautions', 'Notify surveillance team', 'Isolate to prevent pneumonic progression'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_2_to_6: 4 },
      who_basis: 'WHO Plague fact sheet. Madagascar, DRC, Tanzania are endemic.',
    },

    {
      id: 'nipah_virus', name: 'Nipah Virus Disease', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 5, case_fatality_rate_pct: 70,
      syndromes: ['neurologic', 'severe_respiratory', 'encephalitic'],
      incubation_days: { min: 4, max: 14, typical: '4-14' },
      gates: {
        required_any: ['fever', 'altered_consciousness', 'encephalitis_signs'],
        soft_require_any: ['headache', 'vomiting', 'difficulty_breathing'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'encephalitis_signs', 'altered_consciousness'],
      key_distinguishers: ['Encephalitis with rapidly declining consciousness', 'Fruit bat contact / date palm sap in South/SE Asia', 'Nosocomial transmission documented', 'No specific treatment — 70% CFR'],
      symptom_weights: {
        fever: 12, high_fever: 14, headache: 10, severe_headache: 14, vomiting: 8,
        altered_consciousness: 22, seizures: 14, encephalitis_signs: 24, weakness: 10,
        difficulty_breathing: 8, shortness_of_breath: 6,
      },
      absent_hallmark_penalties: {},
      exposure_weights: {
        animal_bite_or_wildlife_contact: 16, close_contact_case: 14, healthcare_exposure: 12,
        travel_from_outbreak_area: 12, contaminated_food_or_water: 10,
      },
      negative_weights: { watery_diarrhea: -6, coryza: -6, rash_vesicular_pustular: -10 },
      outbreak_bonus: 20, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['Nipah PCR (BSL-4 reference laboratory)', 'Serology per WHO protocol'],
      immediate_actions: ['FULL PPE ISOLATION', 'IMMEDIATE WHO NOTIFICATION', 'Contact and bat exposure investigation'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_4_to_14: 5 },
      who_basis: 'WHO Nipah virus Blueprint pathogen. Highest pandemic potential per WHO R&D Blueprint.',
    },

    {
      id: 'hantavirus', name: 'Hantavirus (HPS / HFRS)', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 4, case_fatality_rate_pct: 20,
      syndromes: ['vhf_possible', 'severe_respiratory', 'hemorrhagic_fever_renal'],
      incubation_days: { min: 5, max: 42, typical: '14-21' },
      gates: {
        required_any: ['fever', 'high_fever'],
        soft_require_any: ['rodent_exposure', 'cough', 'shortness_of_breath', 'back_pain', 'abdominal_pain'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'back_pain'],
      key_distinguishers: ['Rodent exposure (Hantaan, Seoul, Sin Nombre virus)', 'HPS: acute respiratory failure', 'HFRS: renal failure + haemorrhage', 'No person-to-person transmission (most strains)', 'Sub-Saharan Africa: HFRS strains'],
      symptom_weights: {
        fever: 12, high_fever: 12, fatigue: 10, headache: 8, muscle_pain: 10, back_pain: 12,
        abdominal_pain: 10, vomiting: 8, cough: 8, shortness_of_breath: 10, difficulty_breathing: 10,
        bleeding: 8, dizziness: 6, weakness: 8,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { rodent_exposure: 22, travel_from_outbreak_area: 8, fresh_water_contact: 4 },
      negative_weights: { rash_vesicular_pustular: -10, stiff_neck: -8, coryza: -6, loss_of_taste_smell: -8 },
      outbreak_bonus: 14, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Hantavirus serology / PCR per reference laboratory', 'Renal function (HFRS) / Chest X-ray (HPS)'],
      immediate_actions: ['Supportive care + ICU referral if respiratory failure', 'Rodent exposure history', 'No person-to-person isolation needed for most strains'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_5_to_42: 4 },
      who_basis: 'WHO Hantavirus fact sheet. Emerging zoonotic — global distribution.',
    },

    {
      id: 'mers', name: 'MERS-CoV (Middle East Respiratory Syndrome)', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 5, case_fatality_rate_pct: 35,
      syndromes: ['severe_respiratory'],
      incubation_days: { min: 2, max: 14, typical: '5-6' },
      gates: {
        required_any: ['fever', 'cough', 'shortness_of_breath'],
        soft_require_any: ['camel_exposure_or_mideast_healthcare', 'diarrhea'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'cough', 'shortness_of_breath'],
      key_distinguishers: ['Camel or Arabian Peninsula healthcare exposure', 'Diarrhoea distinguishes from SARS', 'Rapid progression to ARDS', 'No sustained community transmission'],
      symptom_weights: {
        fever: 10, cough: 12, shortness_of_breath: 14, difficulty_breathing: 14, rapid_breathing: 10,
        diarrhea: 6, fatigue: 6, muscle_pain: 6, nausea: 4, vomiting: 4,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { camel_exposure_or_mideast_healthcare: 22, travel_from_outbreak_area: 10, healthcare_exposure: 10, close_contact_case: 8 },
      negative_weights: { loss_of_taste_smell: -8, rash_vesicular_pustular: -10, jaundice: -10, stiff_neck: -10 },
      outbreak_bonus: 14, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['MERS-CoV PCR per national/WHO protocol (lower + upper respiratory)', 'Chest imaging'],
      immediate_actions: ['IMMEDIATE ISOLATION (AIRBORNE)', 'URGENT PUBLIC HEALTH NOTIFICATION', 'Travel and camel exposure history'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_2_to_14: 4 },
      who_basis: 'WHO MERS-CoV fact sheet.',
    },

    {
      id: 'dengue', name: 'Dengue', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 3, case_fatality_rate_pct: 0.5,
      syndromes: ['acute_febrile', 'arboviral'],
      incubation_days: { min: 4, max: 10, typical: '4-7' },
      gates: {
        required_all: ['fever'],
        required_any: ['severe_headache', 'pain_behind_eyes', 'muscle_pain', 'joint_pain', 'rash_maculopapular'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'severe_headache', 'pain_behind_eyes'],
      key_distinguishers: ['Retro-orbital pain (pathognomonic signal)', 'Breakbone fever — severe myalgia', 'Dengue rash (3-5 days — islands of white in sea of red)', 'Warning signs for severe dengue: abdominal pain, vomiting, mucosal bleeding'],
      symptom_weights: {
        fever: 12, high_fever: 14, headache: 8, severe_headache: 12, pain_behind_eyes: 14,
        nausea: 6, vomiting: 6, persistent_vomiting: 10, muscle_pain: 10, joint_pain: 10,
        swollen_lymph_nodes: 4, rash_maculopapular: 8, abdominal_pain: 10, bleeding_gums_or_nose: 10,
        bleeding: 8, cold_pale_skin: 10, fatigue: 6,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { mosquito_exposure: 16, travel_from_outbreak_area: 10, residence_in_outbreak_area: 10 },
      negative_weights: { cough: -8, coryza: -8, loss_of_taste_smell: -8, stiff_neck: -10, paralysis_acute_flaccid: -12, watery_diarrhea: -6 },
      outbreak_bonus: 14, alert_level_if_top_ranked: 'high',
      recommended_tests: ['NS1 antigen (days 1-5)', 'Dengue IgM/IgG', 'FBC: thrombocytopenia + haematocrit rise'],
      immediate_actions: ['Monitor for warning signs (abdominal pain, persistent vomiting, mucosal bleeding, plasma leakage)', 'Urgent referral if dengue shock suspected'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_4_to_10: 4 },
      who_basis: 'WHO Dengue fact sheet 2024. WHO dengue clinical guidelines — severe dengue warning signs.',
    },

    {
      id: 'dengue_severe', name: 'Severe Dengue (Dengue Haemorrhagic Fever / Shock Syndrome)', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 5, case_fatality_rate_pct: 5,
      syndromes: ['hemorrhagic_fever', 'shock_syndrome'],
      incubation_days: { min: 4, max: 10, typical: '4-7' },
      gates: {
        required_all: ['fever'],
        required_any: ['bleeding', 'cold_pale_skin', 'persistent_vomiting', 'abdominal_pain'],
        soft_require_any: ['petechial_or_purpuric_rash', 'bleeding_gums_or_nose'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'bleeding', 'cold_pale_skin'],
      key_distinguishers: ['Plasma leakage signs: haemoconcentration, pleural effusion', 'Rapid deterioration after fever defervescence', 'Shock (cold clammy skin, narrowing pulse pressure)', 'Thrombocytopenia < 100,000/mm³'],
      symptom_weights: {
        fever: 14, bleeding: 20, petechial_or_purpuric_rash: 16, bleeding_gums_or_nose: 14,
        persistent_vomiting: 14, abdominal_pain: 16, abdominal_tenderness: 14,
        cold_pale_skin: 18, weakness: 12, dizziness: 10,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { mosquito_exposure: 16, travel_from_outbreak_area: 10, residence_in_outbreak_area: 10 },
      negative_weights: { coryza: -6, stiff_neck: -10, watery_diarrhea: -6, rash_vesicular_pustular: -8 },
      outbreak_bonus: 16, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['FBC hourly if deteriorating', 'Dengue NS1/serology', 'Chest X-ray for pleural effusion'],
      immediate_actions: ['EMERGENCY IV FLUID RESUSCITATION', 'ICU LEVEL CARE', 'Platelet monitoring'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_4_to_10: 4 },
      who_basis: 'WHO Dengue clinical management guidelines. Severe dengue warning signs criteria.',
    },

    {
      id: 'malaria_uncomplicated', name: 'Malaria (Uncomplicated)', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 3, case_fatality_rate_pct: 0.5,
      syndromes: ['acute_febrile', 'travel_fever'],
      incubation_days: { min: 7, max: 30, typical: '7-14' },
      gates: {
        required_any: ['fever', 'high_fever', 'chills'],
        soft_require_any: ['headache', 'vomiting', 'mosquito_exposure'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'chills', 'headache'],
      key_distinguishers: ['Cyclical fever (48h P. vivax/ovale, 72h P. malariae)', 'Chills + rigors + sweating cycle', 'Travel to sub-Saharan Africa within 30 days', 'Most common cause of fever in returning travellers from Africa'],
      symptom_weights: {
        fever: 12, high_fever: 12, chills: 14, headache: 10, vomiting: 6, weakness: 8,
        fatigue: 6, muscle_pain: 4, abdominal_pain: 4, nausea: 6, diarrhea: 4, anorexia: 6,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { mosquito_exposure: 16, travel_from_outbreak_area: 8, residence_in_outbreak_area: 8 },
      negative_weights: { rash_maculopapular: -8, rash_vesicular_pustular: -12, coryza: -6, swollen_lymph_nodes: -6, stiff_neck: -8 },
      outbreak_bonus: 10, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Malaria RDT (urgent)', 'Thick and thin blood smear', 'Species identification critical for treatment'],
      immediate_actions: ['URGENT MALARIA TEST for any febrile traveller from endemic area', 'ACT (artemisinin-based combination therapy) per national protocol'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_7_or_more: 5 },
      who_basis: 'WHO Malaria fact sheet 2024. Most common cause of fever in returning Africa travellers.',
    },

    {
      id: 'malaria_severe', name: 'Severe Malaria (P. falciparum)', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 5, case_fatality_rate_pct: 15,
      syndromes: ['acute_febrile', 'neurologic', 'hemorrhagic_possible'],
      incubation_days: { min: 7, max: 30, typical: '7-14' },
      gates: {
        required_any: ['fever', 'high_fever', 'altered_consciousness', 'seizures'],
        soft_require_any: ['mosquito_exposure', 'travel_from_outbreak_area'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'altered_consciousness'],
      key_distinguishers: ['Cerebral malaria: impaired consciousness + fever', 'Severe anaemia, respiratory distress, jaundice', 'Haemoglobinuria (blackwater fever)', 'EMERGENCY — IV artesunate required', 'P. falciparum only (predominantly)'],
      symptom_weights: {
        fever: 14, high_fever: 16, very_high_fever: 18, altered_consciousness: 22, seizures: 16,
        headache: 10, vomiting: 8, weakness: 12, jaundice: 10, dark_urine: 10, difficulty_breathing: 10, rapid_breathing: 10,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { mosquito_exposure: 16, travel_from_outbreak_area: 10, residence_in_outbreak_area: 10 },
      negative_weights: { coryza: -6, rash_vesicular_pustular: -12, swollen_lymph_nodes: -6 },
      outbreak_bonus: 12, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['Urgent malaria RDT + blood smear', 'Blood glucose (hypoglycaemia common)', 'Haematocrit, renal function'],
      immediate_actions: ['EMERGENCY IV ARTESUNATE', 'ICU LEVEL CARE', 'Treat hypoglycaemia', 'Anticonvulsants for seizures'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_7_or_more: 5 },
      who_basis: 'WHO Severe Malaria clinical management guidelines 2023.',
    },

    {
      id: 'covid_19', name: 'COVID-19', priority_tier: 'tier_2_ihr_annex2',
      who_category: 'IHR_ANNEX2_EPIDEMIC_PRONE', severity: 3, case_fatality_rate_pct: 1,
      syndromes: ['acute_respiratory', 'systemic_viral'],
      incubation_days: { min: 1, max: 14, typical: '3-5' },
      gates: {
        required_any: ['fever', 'cough', 'loss_of_taste_smell', 'shortness_of_breath', 'sore_throat'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['cough', 'loss_of_taste_smell'],
      key_distinguishers: ['Loss of taste/smell (anosmia) — strongest single differentiator', 'Wide clinical spectrum (asymptomatic to critical)', 'Respiratory predominant', 'Fatigue persisting post-illness'],
      symptom_weights: {
        fever: 8, cough: 14, dry_cough: 12, fatigue: 10, loss_of_taste_smell: 22,
        sore_throat: 8, headache: 6, muscle_pain: 6, shortness_of_breath: 12, difficulty_breathing: 12, diarrhea: 4, conjunctivitis: 2,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { close_contact_case: 14, travel_from_outbreak_area: 8, crowded_closed_setting: 8, healthcare_exposure: 8 },
      negative_weights: { rash_vesicular_pustular: -6, jaundice: -10, stiff_neck: -10, watery_diarrhea: -8, painful_swollen_lymph_nodes: -8 },
      outbreak_bonus: 14, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Rapid antigen test', 'PCR if negative RAT with high clinical suspicion'],
      immediate_actions: ['Respiratory hygiene isolation', 'Assess SpO2 — supplemental O2 if < 94%', 'Antivirals per national protocol if high risk'],
      vaccination_modifiers: { covid_19: { documented_valid: -6 } },
      onset_modifiers: { days_since_onset_1_to_14: 4 },
      who_basis: 'WHO COVID-19 illness surveillance. IHR notification obligations for unusual clusters.',
    },

    // ════════════════════════════════════════════════════════════════════
    // EXTENDED DIFFERENTIAL — TIER 3/4 (important travel medicine differentials)
    // ════════════════════════════════════════════════════════════════════

    {
      id: 'typhoid_fever', name: 'Typhoid fever (Enteric fever)', priority_tier: 'tier_3_who_notifiable',
      who_category: 'WHO_AFRO_IDSR_PRIORITY', severity: 3, case_fatality_rate_pct: 1,
      syndromes: ['acute_febrile', 'enteric'],
      incubation_days: { min: 7, max: 21, typical: '10-14' },
      gates: {
        required_any: ['fever', 'gradual_onset_fever'],
        soft_require_any: ['headache', 'constipation', 'abdominal_pain', 'contaminated_food_or_water'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['gradual_onset_fever', 'headache', 'rose_spots'],
      key_distinguishers: ['Step-ladder fever pattern — rises over 4-5 days', 'Relative bradycardia (Faget\'s sign)', 'Rose spots on trunk (rare, specific)', 'Constipation more common than diarrhoea (early)', 'Splenomegaly'],
      symptom_weights: {
        gradual_onset_fever: 14, fever: 10, high_fever: 10, headache: 10, malaise: 8, anorexia: 8,
        abdominal_pain: 10, abdominal_tenderness: 8, nausea: 6, vomiting: 4, constipation: 8,
        rose_spots: 16, splenomegaly: 12, paradoxical_bradycardia: 12, weakness: 8,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { contaminated_food_or_water: 16, unsafe_water: 14, travel_from_outbreak_area: 8, residence_in_outbreak_area: 8 },
      negative_weights: { watery_diarrhea: -6, bleeding: -6, coryza: -8, rash_vesicular_pustular: -10 },
      outbreak_bonus: 10, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Blood culture (gold standard, first week)', 'Widal test (limited specificity)', 'Typhoid RDT where available'],
      immediate_actions: ['Enteric precautions', 'Antibiotics per local resistance pattern', 'Hydration'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_7_to_21: 4 },
      who_basis: 'WHO AFRO IDSR typoid fever case definition. WHO typhoid fact sheet.',
    },

    {
      id: 'hepatitis_a', name: 'Hepatitis A', priority_tier: 'tier_3_who_notifiable',
      who_category: 'WHO_AFRO_IDSR_PRIORITY', severity: 2, case_fatality_rate_pct: 0.3,
      syndromes: ['acute_hepatitis', 'jaundice'],
      incubation_days: { min: 15, max: 50, typical: '28' },
      gates: {
        required_any: ['jaundice', 'dark_urine'],
        soft_require_any: ['fever', 'nausea', 'abdominal_pain', 'unsafe_water'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['jaundice', 'dark_urine'],
      key_distinguishers: ['Acute onset jaundice after faecal-oral exposure', 'Pre-icteric prodrome (fever, nausea, anorexia) 1-2 weeks before jaundice', 'Self-limiting in most adults', 'Severe in elderly/chronic liver disease'],
      symptom_weights: {
        jaundice: 24, dark_urine: 16, pale_stools: 12, nausea: 12, vomiting: 8, fever: 8,
        low_grade_fever: 8, fatigue: 10, abdominal_pain: 10, right_upper_quadrant_pain: 14,
        anorexia: 12, malaise: 8,
      },
      absent_hallmark_penalties: { jaundice: -12 },
      exposure_weights: { unsafe_water: 16, contaminated_food_or_water: 16, travel_from_outbreak_area: 8, residence_in_outbreak_area: 8 },
      negative_weights: { cough: -8, rash_vesicular_pustular: -10, stiff_neck: -10, bleeding: -6, coryza: -8 },
      outbreak_bonus: 10, alert_level_if_top_ranked: 'medium',
      recommended_tests: ['Hepatitis A IgM (anti-HAV IgM)', 'Liver function tests (ALT/AST elevation)'],
      immediate_actions: ['Enteric precautions', 'Supportive care', 'Post-exposure vaccination within 2 weeks', 'Outbreak source investigation'],
      vaccination_modifiers: { hepatitis_a: { documented_valid: -12 } },
      onset_modifiers: { days_since_onset_15_to_50: 4 },
      who_basis: 'WHO Hepatitis A fact sheet 2024.',
    },

    {
      id: 'hepatitis_e', name: 'Hepatitis E', priority_tier: 'tier_3_who_notifiable',
      who_category: 'WHO_AFRO_IDSR_PRIORITY', severity: 3, case_fatality_rate_pct: 1,
      syndromes: ['acute_hepatitis', 'jaundice'],
      incubation_days: { min: 15, max: 64, typical: '40' },
      gates: {
        required_any: ['jaundice', 'dark_urine'],
        soft_require_any: ['fever', 'nausea', 'abdominal_pain'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['jaundice', 'dark_urine'],
      key_distinguishers: ['Epidemic hepatitis in refugee/displacement settings', 'CFR 20-25% in PREGNANT WOMEN (critical POE concern)', 'Faecal-oral, flood-associated', 'East Africa: major waterborne outbreaks'],
      symptom_weights: {
        jaundice: 24, dark_urine: 16, pale_stools: 12, nausea: 12, vomiting: 8, fever: 8,
        low_grade_fever: 8, fatigue: 10, abdominal_pain: 10, right_upper_quadrant_pain: 14,
        anorexia: 12, malaise: 8,
      },
      absent_hallmark_penalties: { jaundice: -12 },
      exposure_weights: { unsafe_water: 18, contaminated_food_or_water: 14, flood_livestock_exposure: 10, travel_from_outbreak_area: 10, residence_in_outbreak_area: 10 },
      negative_weights: { cough: -8, rash_vesicular_pustular: -10, stiff_neck: -10, bleeding: -6 },
      outbreak_bonus: 12, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Hepatitis E IgM (anti-HEV IgM)', 'Liver function tests', 'PREGNANCY TEST — if positive, classify as HIGH RISK IMMEDIATELY'],
      immediate_actions: ['ASSESS PREGNANCY STATUS', 'Supportive care', 'Water source investigation', 'Enteric precautions'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_15_to_64: 4 },
      who_basis: 'WHO Hepatitis E fact sheet 2024. WHO EMRO hepatitis E in humanitarian settings.',
    },

    {
      id: 'rabies', name: 'Rabies', priority_tier: 'tier_3_who_notifiable',
      who_category: 'WHO_AFRO_PRIORITY_ZOONOTIC', severity: 5, case_fatality_rate_pct: 100,
      syndromes: ['neurologic', 'encephalitic'],
      incubation_days: { min: 4, max: 90, typical: '20-90' },
      gates: {
        required_any: ['hydrophobia', 'aerophobia', 'encephalitis_signs', 'altered_consciousness'],
        soft_require_any: ['animal_bite_or_wildlife_contact', 'fever'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['hydrophobia', 'aerophobia'],
      key_distinguishers: ['HYDROPHOBIA + AEROPHOBIA = PATHOGNOMONIC for rabies (virtually 100% specific)', 'Animal bite history (dog/bat/other mammal)', 'Once symptomatic: virtually 100% fatal', 'POE role: identify, document, contact trace — not treatment', 'Long incubation makes this possible at any POE'],
      symptom_weights: {
        hydrophobia: 45, aerophobia: 40, encephalitis_signs: 22, altered_consciousness: 18,
        fever: 10, headache: 8, malaise: 8, weakness: 10, muscle_pain: 6, paralysis_acute_flaccid: 8,
        seizures: 10, fatigue: 6,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { animal_bite_or_wildlife_contact: 20, travel_from_outbreak_area: 8 },
      negative_weights: { cough: -6, coryza: -6, watery_diarrhea: -8, rash_vesicular_pustular: -10, jaundice: -10 },
      outbreak_bonus: 10, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['DFA/PCR from brain tissue post-mortem (clinical diagnosis pre-mortem)', 'Saliva/CSF/skin biopsy PCR during life — reference lab only'],
      immediate_actions: ['EMERGENCY REFERRAL — palliative approach once symptomatic', 'ASSESS BITE HISTORY and PEP eligibility', 'Contact trace — identify animal and human contacts exposed', 'Notify public health for PEP distribution'],
      vaccination_modifiers: { rabies: { documented_valid: -6 } },
      onset_modifiers: {},
      who_basis: 'WHO Rabies fact sheet 2024. Zero by 30 campaign.',
    },

    {
      id: 'anthrax_cutaneous', name: 'Cutaneous anthrax', priority_tier: 'tier_3_who_notifiable',
      who_category: 'WHO_AFRO_PRIORITY_ZOONOTIC', severity: 3, case_fatality_rate_pct: 5,
      syndromes: ['cutaneous_ulcer', 'zoonotic'],
      incubation_days: { min: 1, max: 12, typical: '3-5' },
      gates: {
        required_any: ['skin_eschar'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['skin_eschar'],
      key_distinguishers: ['Painless — the hallmark (differentiates from all other ulcers)', 'Black necrotic centre with oedema ring', 'Animal product exposure (hide, wool, bone meal)', 'Bioterrorism sentinel: multiple simultaneous cases = escalate immediately'],
      symptom_weights: {
        skin_eschar: 40, fever: 8, headache: 6, malaise: 8, swollen_lymph_nodes: 8,
        facial_swelling: 10,
      },
      absent_hallmark_penalties: { skin_eschar: -30 },
      exposure_weights: { flood_livestock_exposure: 18, raw_meat_or_unpasteurised_dairy: 14, animal_bite_or_wildlife_contact: 10, travel_from_outbreak_area: 8, laboratory_exposure: 12 },
      negative_weights: { watery_diarrhea: -10, cough: -6, rash_vesicular_pustular: -8 },
      outbreak_bonus: 14, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Swab from lesion for culture/PCR (Bacillus anthracis)', 'Blood culture if systemic involvement', 'DO NOT INCISE — risk of systemic spread'],
      immediate_actions: ['Antibiotics (ciprofloxacin/doxycycline)', 'BIOTERRORISM ASSESSMENT if multiple simultaneous cases', 'Occupational/animal exposure investigation'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_1_to_12: 5 },
      who_basis: 'WHO Anthrax fact sheet. Bioterrorism sentinel event.',
    },

    {
      id: 'anthrax_pulmonary', name: 'Pulmonary / Inhalation anthrax', priority_tier: 'tier_3_who_notifiable',
      who_category: 'WHO_AFRO_PRIORITY_ZOONOTIC_BIOTERRORISM', severity: 5, case_fatality_rate_pct: 80,
      syndromes: ['severe_respiratory', 'bioterrorism_sentinel'],
      incubation_days: { min: 1, max: 60, typical: '2-5' },
      gates: {
        required_any: ['fever', 'shortness_of_breath', 'chest_pain'],
        soft_require_any: ['laboratory_exposure', 'unusual_cluster'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'chest_pain', 'shortness_of_breath'],
      key_distinguishers: ['Widened mediastinum on chest X-ray (pathognomonic)', 'Biphasic illness — brief improvement then rapid deterioration', 'Bioterrorism scenario: multiple simultaneous respiratory cases', 'Spore inhalation (no person-to-person)'],
      symptom_weights: {
        fever: 12, chest_pain: 14, shortness_of_breath: 16, difficulty_breathing: 16, rapid_breathing: 12,
        severe_fatigue: 10, headache: 8, malaise: 8, cough: 6, nausea: 6, vomiting: 6,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { laboratory_exposure: 20, travel_from_outbreak_area: 10 },
      negative_weights: { watery_diarrhea: -8, rash_vesicular_pustular: -10, stiff_neck: -8, loss_of_taste_smell: -8 },
      outbreak_bonus: 20, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['Blood culture', 'Chest X-ray (mediastinal widening)', 'PCR via public health laboratory', 'ACTIVATE BIOTERRORISM PROTOCOL'],
      immediate_actions: ['IMMEDIATE ISOLATION', 'BIOTERRORISM NOTIFICATION', 'HIGH-DOSE IV ANTIBIOTICS', 'Mass prophylaxis assessment if cluster'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_1_to_5: 5 },
      who_basis: 'WHO Anthrax fact sheet. Bioterrorism preparedness.',
    },

    {
      id: 'tularemia', name: 'Tularaemia (Rabbit fever)', priority_tier: 'tier_3_who_notifiable',
      who_category: 'WHO_BIOTERRORISM_TIER_A', severity: 4, case_fatality_rate_pct: 5,
      syndromes: ['cutaneous_ulcer', 'lymphadenitic_febrile', 'zoonotic'],
      incubation_days: { min: 1, max: 14, typical: '3-5' },
      gates: {
        required_any: ['fever', 'skin_eschar', 'painful_swollen_lymph_nodes'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'skin_eschar', 'painful_swollen_lymph_nodes'],
      key_distinguishers: ['Ulceroglandular: skin ulcer + regional lymphadenopathy', 'Tick bite or wild animal contact', 'Francisella tularensis — Biosafety concern', 'Pneumonic form if inhaled: severe respiratory'],
      symptom_weights: {
        fever: 12, high_fever: 12, skin_eschar: 20, painful_swollen_lymph_nodes: 18, swollen_lymph_nodes: 8,
        headache: 8, muscle_pain: 8, fatigue: 8, chest_pain: 6, cough: 6, shortness_of_breath: 6,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { tick_bite: 16, animal_bite_or_wildlife_contact: 16, flood_livestock_exposure: 10, travel_from_outbreak_area: 8, laboratory_exposure: 12 },
      negative_weights: { watery_diarrhea: -8, coryza: -6, rash_vesicular_pustular: -10, jaundice: -8 },
      outbreak_bonus: 12, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Serology (agglutination)', 'Culture at BSL-3', 'PCR from skin lesion or lymph node'],
      immediate_actions: ['Antibiotics (gentamicin/doxycycline)', 'Bioterrorism assessment if cluster', 'Animal/tick exposure investigation'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_1_to_14: 4 },
      who_basis: 'WHO Tularaemia fact sheet. CDC Tier A bioterrorism agent.',
    },

    {
      id: 'rickettsia_scrub_typhus', name: 'Rickettsiosis / Scrub typhus / Spotted fever', priority_tier: 'tier_3_who_notifiable',
      who_category: 'WHO_AFRO_IDSR_PRIORITY', severity: 3, case_fatality_rate_pct: 5,
      syndromes: ['acute_febrile', 'cutaneous_ulcer', 'tick_borne'],
      incubation_days: { min: 5, max: 21, typical: '7-14' },
      gates: {
        required_any: ['fever', 'skin_eschar', 'rash_maculopapular'],
        soft_require_any: ['headache', 'tick_bite', 'animal_bite_or_wildlife_contact'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'skin_eschar', 'rash_maculopapular'],
      key_distinguishers: ['Eschar at tick bite site (spotted fevers + scrub typhus)', 'Maculopapular rash (may involve palms/soles)', 'Relative bradycardia', 'Tick or mite exposure', 'Rapid response to doxycycline = diagnostic + therapeutic'],
      symptom_weights: {
        fever: 12, high_fever: 12, skin_eschar: 22, rash_maculopapular: 14, headache: 10, severe_headache: 12,
        muscle_pain: 10, malaise: 8, fatigue: 8, swollen_lymph_nodes: 8, vomiting: 4,
        photophobia: 6, altered_consciousness: 8,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { tick_bite: 18, animal_bite_or_wildlife_contact: 10, travel_from_outbreak_area: 8, residence_in_outbreak_area: 8 },
      negative_weights: { watery_diarrhea: -8, coryza: -6, rash_vesicular_pustular: -8, jaundice: -6 },
      outbreak_bonus: 10, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Serology (Weil-Felix, IFA)', 'PCR from eschar swab or blood (early)'],
      immediate_actions: ['DOXYCYCLINE WITHOUT DELAY — do not wait for lab confirmation', 'Tick removal if found', 'Do not give sulphonamides (worsen outcome)'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_5_to_21: 4 },
      who_basis: 'WHO AFRO IDSR case definitions. CDC Rickettsia guidance.',
    },

    {
      id: 'brucellosis', name: 'Brucellosis', priority_tier: 'tier_3_who_notifiable',
      who_category: 'WHO_AFRO_IDSR_PRIORITY', severity: 3, case_fatality_rate_pct: 1,
      syndromes: ['acute_febrile', 'undulant_fever', 'zoonotic'],
      incubation_days: { min: 5, max: 60, typical: '10-30' },
      gates: {
        required_any: ['undulant_fever', 'fever'],
        soft_require_any: ['raw_meat_or_unpasteurised_dairy', 'joint_pain', 'night_sweats', 'fatigue'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['undulant_fever', 'night_sweats'],
      key_distinguishers: ['Undulant (waves of) fever', 'Profuse night sweats', 'Raw milk/cheese or livestock exposure', 'Insidious prolonged illness', 'Musculoskeletal complications (sacroiliitis)'],
      symptom_weights: {
        undulant_fever: 16, fever: 10, night_sweats: 14, fatigue: 10, malaise: 10, joint_pain: 12,
        back_pain: 10, muscle_pain: 8, headache: 8, weakness: 8, anorexia: 6, splenomegaly: 10,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { raw_meat_or_unpasteurised_dairy: 18, flood_livestock_exposure: 14, travel_from_outbreak_area: 8, animal_bite_or_wildlife_contact: 8 },
      negative_weights: { coryza: -8, rash_vesicular_pustular: -10, watery_diarrhea: -8, cough: -8 },
      outbreak_bonus: 10, alert_level_if_top_ranked: 'medium',
      recommended_tests: ['Blood culture (gold standard)', 'Serology (SAT, RBPT)', 'Rose Bengal agglutination test'],
      immediate_actions: ['Combination antibiotics (doxycycline + rifampicin × 6 weeks)', 'Occupational/food source investigation'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_5_to_60: 4 },
      who_basis: 'WHO AFRO IDSR brucellosis case definition. Neglected zoonosis.',
    },

    {
      id: 'leptospirosis', name: 'Leptospirosis', priority_tier: 'tier_3_who_notifiable',
      who_category: 'WHO_AFRO_IDSR_PRIORITY', severity: 4, case_fatality_rate_pct: 10,
      syndromes: ['acute_febrile', 'zoonotic', 'hemorrhagic_possible'],
      incubation_days: { min: 2, max: 30, typical: '5-14' },
      gates: {
        required_any: ['fever', 'high_fever'],
        soft_require_any: ['calf_muscle_pain', 'jaundice', 'fresh_water_contact', 'conjunctivitis'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'calf_muscle_pain'],
      key_distinguishers: ['Severe calf muscle tenderness (pathognomonic + highly specific)', 'Conjunctival suffusion (red eyes without discharge)', 'Fresh water/flood contact or rodent exposure', 'Weil\'s disease: jaundice + renal failure + bleeding', 'East Africa: floods + agricultural workers high risk'],
      symptom_weights: {
        fever: 12, high_fever: 12, calf_muscle_pain: 22, muscle_pain: 8, headache: 10, severe_headache: 10,
        conjunctivitis: 12, jaundice: 14, dark_urine: 10, malaise: 8, nausea: 6, vomiting: 6,
        abdominal_pain: 8, chills: 6, bleeding: 10, weakness: 8,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { fresh_water_contact: 18, flood_livestock_exposure: 14, rodent_exposure: 12, animal_bite_or_wildlife_contact: 8, travel_from_outbreak_area: 8 },
      negative_weights: { coryza: -8, rash_vesicular_pustular: -10, stiff_neck: -6, loss_of_taste_smell: -8 },
      outbreak_bonus: 12, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Leptospira MAT (gold standard, paired sera)', 'PCR (early acute phase)', 'Renal/liver function — critical for Weil\'s'],
      immediate_actions: ['Doxycycline (mild-moderate) or penicillin/ceftriaxone (severe)', 'Renal function monitoring', 'Dialysis if renal failure (Weil\'s)'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_2_to_14: 5 },
      who_basis: 'WHO Leptospirosis burden epidemiology reference group.',
    },

    {
      id: 'japanese_encephalitis', name: 'Japanese Encephalitis (JE)', priority_tier: 'tier_3_who_notifiable',
      who_category: 'WHO_AFRO_PRIORITY', severity: 4, case_fatality_rate_pct: 20,
      syndromes: ['neurologic', 'encephalitic', 'arboviral'],
      incubation_days: { min: 5, max: 15, typical: '5-15' },
      gates: {
        required_any: ['altered_consciousness', 'encephalitis_signs', 'seizures', 'fever'],
        soft_require_any: ['mosquito_exposure', 'headache'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'encephalitis_signs', 'altered_consciousness'],
      key_distinguishers: ['Epidemic encephalitis in Asia + parts of Africa', 'Mosquito-borne (Culex, rice paddy areas)', '< 1% of infections symptomatic — but severe in those affected', 'Parkinsonism features (extrapyramidal signs)', 'Acute flaccid paralysis variant'],
      symptom_weights: {
        fever: 12, high_fever: 12, altered_consciousness: 22, seizures: 16, encephalitis_signs: 22,
        headache: 10, severe_headache: 12, vomiting: 8, stiff_neck: 10, weakness: 10, fatigue: 6,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { mosquito_exposure: 14, travel_from_outbreak_area: 12, residence_in_outbreak_area: 12 },
      negative_weights: { watery_diarrhea: -6, coryza: -6, rash_vesicular_pustular: -10, jaundice: -6 },
      outbreak_bonus: 12, alert_level_if_top_ranked: 'critical',
      recommended_tests: ['JE IgM in CSF and serum', 'CT/MRI — thalamic lesions', 'PCR (low sensitivity in clinical samples)'],
      immediate_actions: ['ICU supportive care', 'Anticonvulsants', 'Public health notification for cluster investigation'],
      vaccination_modifiers: { japanese_encephalitis: { documented_valid: -12 } },
      onset_modifiers: { days_since_onset_5_to_15: 5 },
      who_basis: 'WHO Japanese Encephalitis fact sheet 2024.',
    },

    {
      id: 'shigellosis_dysentery', name: 'Shigellosis (Bacillary dysentery)', priority_tier: 'tier_3_who_notifiable',
      who_category: 'WHO_AFRO_IDSR_PRIORITY', severity: 3, case_fatality_rate_pct: 1,
      syndromes: ['acute_bloody_diarrhea', 'enteric'],
      incubation_days: { min: 1, max: 4, typical: '1-3' },
      gates: {
        required_any: ['bloody_diarrhea', 'diarrhea'],
        soft_require_any: ['fever', 'abdominal_pain'],
        hard_fail_if_absent: ['bloody_diarrhea'],
      },
      hallmarks: ['bloody_diarrhea', 'abdominal_pain'],
      key_distinguishers: ['Bloody mucoid stools', 'Tenesmus (painful incomplete defecation)', 'Low infectious dose — highly contagious', 'Severe in malnourished children/immunocompromised', 'Increasing antibiotic resistance (ESBL-Shigella)'],
      symptom_weights: {
        bloody_diarrhea: 28, diarrhea: 8, abdominal_pain: 16, fever: 10, high_fever: 10,
        nausea: 6, vomiting: 4, weakness: 6, fatigue: 6,
      },
      absent_hallmark_penalties: { bloody_diarrhea: -20 },
      exposure_weights: { contaminated_food_or_water: 14, unsafe_water: 12, travel_from_outbreak_area: 8, close_contact_case: 8 },
      negative_weights: { watery_diarrhea: -10, cough: -6, rash_vesicular_pustular: -10, jaundice: -8, stiff_neck: -8 },
      outbreak_bonus: 10, alert_level_if_top_ranked: 'high',
      recommended_tests: ['Stool culture (Shigella)', 'Antibiotic sensitivity — critical due to resistance'],
      immediate_actions: ['Antibiotics per local sensitivity (azithromycin)', 'Oral rehydration', 'Enteric precautions', 'Outbreak source investigation'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_1_to_4: 5 },
      who_basis: 'WHO AFRO IDSR dysentery case definition.',
    },

    {
      id: 'awd_non_cholera', name: 'Acute Watery Diarrhoea (Non-Cholera)', priority_tier: 'tier_4_syndromic',
      who_category: 'WHO_AFRO_IDSR_SYNDROMIC', severity: 2, case_fatality_rate_pct: 0.5,
      syndromes: ['acute_watery_diarrhea'],
      incubation_days: { min: 0.5, max: 5, typical: '1-3' },
      gates: {
        required_any: ['watery_diarrhea', 'diarrhea'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['watery_diarrhea', 'severe_dehydration'],
      key_distinguishers: ['Acute onset watery diarrhoea without blood', 'No rice-water stools (distinguishes from cholera)', 'Common in displacement/emergency settings', 'Enteric viruses (rotavirus, norovirus), ETEC', 'Lower severity than cholera but high burden'],
      symptom_weights: {
        watery_diarrhea: 20, diarrhea: 10, severe_dehydration: 18, vomiting: 8, nausea: 6,
        abdominal_pain: 6, weakness: 6, fever: 4, low_grade_fever: 4,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { unsafe_water: 14, contaminated_food_or_water: 12, travel_from_outbreak_area: 6, crowded_closed_setting: 6 },
      negative_weights: { bloody_diarrhea: -10, rice_water_diarrhea: -8, stiff_neck: -10, cough: -4 },
      outbreak_bonus: 8, alert_level_if_top_ranked: 'medium',
      recommended_tests: ['Stool R+E if available', 'Cholera exclusion first in outbreak context'],
      immediate_actions: ['Oral/IV rehydration', 'Enteric precautions', 'Hygiene promotion in cluster setting'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_0_to_5: 4 },
      who_basis: 'WHO AFRO IDSR acute diarrhoea case definition.',
    },

    {
      id: 'influenza_seasonal', name: 'Seasonal influenza', priority_tier: 'tier_4_syndromic',
      who_category: 'WHO_GLOBAL_INFLUENZA_PROGRAMME', severity: 2, case_fatality_rate_pct: 0.1,
      syndromes: ['acute_respiratory', 'influenza_like_illness'],
      incubation_days: { min: 1, max: 4, typical: '2' },
      gates: {
        required_any: ['fever', 'high_fever', 'sudden_onset_fever'],
        soft_require_any: ['cough', 'sore_throat', 'muscle_pain'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['sudden_onset_fever', 'cough', 'muscle_pain'],
      key_distinguishers: ['SUDDEN onset — patient remembers exact hour', 'Myalgia disproportionate to fever', 'Rapid defervescence within 3-5 days', 'Cough persists longest', 'Major differential for COVID-19 and zoonotic influenza'],
      symptom_weights: {
        sudden_onset_fever: 14, fever: 10, high_fever: 12, cough: 14, dry_cough: 10,
        sore_throat: 8, headache: 8, muscle_pain: 10, joint_pain: 6, fatigue: 10, coryza: 6,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { close_contact_case: 10, crowded_closed_setting: 8, travel_from_outbreak_area: 4 },
      negative_weights: { loss_of_taste_smell: -10, rash_vesicular_pustular: -10, jaundice: -10, stiff_neck: -10, watery_diarrhea: -6 },
      outbreak_bonus: 10, alert_level_if_top_ranked: 'medium',
      recommended_tests: ['Influenza rapid test or PCR if indicated', 'Subtype to rule out novel strain if severe'],
      immediate_actions: ['Respiratory hygiene', 'Antivirals (oseltamivir) if high-risk or severe', 'Distinguish from zoonotic influenza if poultry exposure'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_1_to_4: 4 },
      who_basis: 'WHO Global Influenza Programme. ILI definition.',
    },

    {
      id: 'chikungunya', name: 'Chikungunya', priority_tier: 'tier_4_syndromic',
      who_category: 'WHO_AFRO_IDSR_PRIORITY', severity: 2, case_fatality_rate_pct: 0.1,
      syndromes: ['arboviral', 'febrile_arthralgia'],
      incubation_days: { min: 2, max: 12, typical: '4-8' },
      gates: {
        required_all: ['fever'],
        required_any: ['severe_joint_pain', 'joint_pain'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'severe_joint_pain'],
      key_distinguishers: ['Severe symmetrical polyarthralgia — more debilitating than dengue joint pain', 'Rapid defervescence but arthralgia persists weeks-months', 'Rash in 50% (maculopapular, pruritic)', 'Both Aedes mosquitoes — often co-circulates with dengue'],
      symptom_weights: {
        fever: 12, high_fever: 12, severe_joint_pain: 20, joint_pain: 14, muscle_pain: 8,
        headache: 6, rash_maculopapular: 6, fatigue: 6, nausea: 4,
      },
      absent_hallmark_penalties: { severe_joint_pain: -10 },
      exposure_weights: { mosquito_exposure: 16, travel_from_outbreak_area: 8, residence_in_outbreak_area: 8 },
      negative_weights: { cough: -8, coryza: -8, loss_of_taste_smell: -8, watery_diarrhea: -8, stiff_neck: -10 },
      outbreak_bonus: 10, alert_level_if_top_ranked: 'medium',
      recommended_tests: ['Chikungunya PCR (days 1-5)', 'IgM after day 5'],
      immediate_actions: ['Symptomatic — NSAIDs for arthralgia', 'Aspirin CONTRAINDICATED until dengue excluded'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_2_to_12: 4 },
      who_basis: 'WHO Chikungunya fact sheet.',
    },

    {
      id: 'zika', name: 'Zika virus disease', priority_tier: 'tier_4_syndromic',
      who_category: 'WHO_AFRO_IDSR_PRIORITY', severity: 2, case_fatality_rate_pct: 0.01,
      syndromes: ['arboviral', 'mild_febrile_rash'],
      incubation_days: { min: 3, max: 14, typical: '3-7' },
      gates: {
        required_any: ['rash_maculopapular', 'conjunctivitis', 'joint_pain'],
        soft_require_any: ['fever', 'mosquito_exposure'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['rash_maculopapular', 'conjunctivitis', 'joint_pain'],
      key_distinguishers: ['PREGNANCY RISK — microcephaly + congenital Zika syndrome', 'Guillain-Barré syndrome association', 'Generally mild illness in adults', 'Conjunctivitis distinguishes from chikungunya/dengue', 'Sexual transmission possible'],
      symptom_weights: {
        rash_maculopapular: 14, fever: 8, low_grade_fever: 8, conjunctivitis: 12,
        joint_pain: 10, muscle_pain: 6, headache: 6, malaise: 6,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { mosquito_exposure: 14, travel_from_outbreak_area: 8, residence_in_outbreak_area: 8, sexual_contact: 8 },
      negative_weights: { high_fever: -8, severe_joint_pain: -6, cough: -8, watery_diarrhea: -8, stiff_neck: -10 },
      outbreak_bonus: 10, alert_level_if_top_ranked: 'medium',
      recommended_tests: ['Zika PCR (urine preferred, blood early)', 'IgM serology', 'PREGNANCY TEST — if positive, urgent obstetric referral'],
      immediate_actions: ['ASSESS PREGNANCY STATUS — congenital Zika syndrome risk', 'Pregnancy counselling', 'Sexual transmission counselling'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_3_to_14: 3 },
      who_basis: 'WHO Zika virus fact sheet.',
    },

    {
      id: 'west_nile_fever', name: 'West Nile fever', priority_tier: 'tier_4_syndromic',
      who_category: 'WHO_AFRO_IDSR_DIFFERENTIAL', severity: 3, case_fatality_rate_pct: 5,
      syndromes: ['arboviral', 'febrile', 'neuroinvasive_possible'],
      incubation_days: { min: 3, max: 14, typical: '3-14' },
      gates: {
        required_any: ['fever', 'headache'],
        soft_require_any: ['mosquito_exposure', 'swollen_lymph_nodes', 'rash_maculopapular'],
        hard_fail_if_absent: [],
      },
      hallmarks: ['fever', 'headache'],
      key_distinguishers: ['< 1% develop neuroinvasive disease', 'Meningitis/encephalitis/AFP in severe cases', 'Migratory bird exposure (dead birds) — sentinel', 'Maculopapular rash 50%', 'Africa, Middle East, Europe, Americas'],
      symptom_weights: {
        fever: 10, headache: 10, fatigue: 8, muscle_pain: 6, joint_pain: 4, nausea: 4,
        vomiting: 4, rash_maculopapular: 6, swollen_lymph_nodes: 6, weakness: 6,
        altered_consciousness: 8, paralysis_acute_flaccid: 8, stiff_neck: 8,
      },
      absent_hallmark_penalties: {},
      exposure_weights: { mosquito_exposure: 14, travel_from_outbreak_area: 8, residence_in_outbreak_area: 8 },
      negative_weights: { coryza: -6, loss_of_taste_smell: -8, rash_vesicular_pustular: -10, watery_diarrhea: -8 },
      outbreak_bonus: 10, alert_level_if_top_ranked: 'medium',
      recommended_tests: ['WNV IgM in CSF and serum', 'PCR (low yield after viraemia phase)'],
      immediate_actions: ['ICU care if neuroinvasive', 'Supportive management', 'Mosquito control investigation'],
      vaccination_modifiers: {},
      onset_modifiers: { days_since_onset_3_to_14: 3 },
      who_basis: 'WHO West Nile virus fact sheet.',
    },

  ], // end diseases


}; // end window.DISEASES

// ============================================================
// scoreDiseases() — THE CORE SCORING ENGINE
// ============================================================
//
// @param {string[]}  presentSymptoms   Symptom IDs confirmed PRESENT
// @param {string[]}  absentSymptoms    Symptom IDs confirmed ABSENT
// @param {string[]}  selectedExposures Exposure IDs (from exposure_catalog)
// @param {object}    context           { outbreak_context[], vaccination_history{},
//                                        clinical_context{ days_since_onset, temperature_c,
//                                          age_group, pregnant } }
//
// @returns {object}  {
//   top_diagnoses:   Top 5 scored disease result objects
//   all_reportable:  All diseases with score >= 25
//   overrides_fired: string[] rule IDs that fired
//   global_flags:    string[] emergency protocol flags
//   input_summary:   { symptoms_count, absent_count, exposures_count, outbreak_context, confidence_baseline }
// }
//
// Each disease result:
//   { disease_id, name, final_score, confidence_band, priority_tier, ihr_category,
//     cfr_pct, matched_hallmarks, matched_symptoms, matched_exposures,
//     score_breakdown: { gate_score, symptom_score, exposure_score, syndrome_bonus,
//                        outbreak_bonus, vaccination_modifier, onset_modifier,
//                        absent_hallmark_penalty, contradiction_penalty, override_boost },
//     key_distinguishers, recommended_tests, immediate_actions, differential_diseases,
//     probability_like_percent }
//
// NOTE: syndrome_bonus and outbreak_bonus are always 0 from this function.
// Diseases_intelligence.js patches this function to activate both bonuses
// using the ENDEMIC_COUNTRIES oracle and WHO syndrome classification rules.
// ============================================================

window.DISEASES.scoreDiseases = function (presentSymptoms, absentSymptoms, selectedExposures, context) {
  const engine = window.DISEASES;
  const params = engine.engine.formula;
  const bands = engine.engine.normalization.confidence_bands;

  context = context || {};
  absentSymptoms = absentSymptoms || [];
  selectedExposures = selectedExposures || [];

  const outbreakContext = context.outbreak_context || [];
  const vaccinationHistory = context.vaccination_history || {};
  const clinicalContext = context.clinical_context || {};

  // ── STEP 1: Evaluate triage overrides (fire BEFORE scoring) ─────────
  const overridesFired = [];
  const overrideBoosts = {}; // disease_id → additional score
  let forcedAlertLevel = null;

  for (const rule of engine.engine.triage_overrides) {
    let triggered = false;

    // Handle applies_to_tiers (rule 10 — post-scoring, handled in global_flags)
    if (rule.applies_to_tiers) continue;

    if (rule.when_all && rule.and_any) {
      const allMet = rule.when_all.every(s => presentSymptoms.includes(s));
      const anyMet = rule.and_any.some(s =>
        presentSymptoms.includes(s) || selectedExposures.includes(s)
      );
      triggered = allMet && anyMet;
    } else if (rule.when_all) {
      triggered = rule.when_all.every(s => presentSymptoms.includes(s));
    } else if (rule.when_any) {
      triggered = rule.when_any.some(s => presentSymptoms.includes(s));
    }

    if (triggered) {
      overridesFired.push(rule.rule_id);

      if (rule.effect.boost_diseases) {
        for (const [id, boost] of Object.entries(rule.effect.boost_diseases)) {
          overrideBoosts[id] = (overrideBoosts[id] || 0) + boost;
        }
      }
      if (rule.effect.penalize_diseases) {
        for (const [id, pen] of Object.entries(rule.effect.penalize_diseases)) {
          overrideBoosts[id] = (overrideBoosts[id] || 0) - pen;
        }
      }
      if (rule.effect.force_alert_level) {
        const levels = ['medium', 'high', 'critical'];
        const cur = levels.indexOf(forcedAlertLevel || 'medium');
        const nxt = levels.indexOf(rule.effect.force_alert_level);
        if (nxt > cur) forcedAlertLevel = rule.effect.force_alert_level;
      }
    }
  }

  // ── STEP 2–11: Score each disease ───────────────────────────────────
  const results = [];

  for (const disease of engine.diseases) {
    let score = 0;
    const breakdown = {
      gate_score: 0, symptom_score: 0, exposure_score: 0,
      syndrome_bonus: 0,    // activated by Diseases_intelligence.js
      outbreak_bonus: 0,    // activated by Diseases_intelligence.js
      vaccination_modifier: 0, onset_modifier: 0,
      absent_hallmark_penalty: 0, contradiction_penalty: 0,
      override_boost: 0,
    };
    const matchedHallmarks = [];
    const matchedSymptoms = [];
    const matchedExposures = [];

    // ── Gate evaluation ────────────────────────────────────────────────
    const gates = disease.gates || {};
    let gatePass = true;

    // Hard fail — mandatory symptom confirmed absent → −60
    if (gates.hard_fail_if_absent && gates.hard_fail_if_absent.length > 0) {
      const hardFail = gates.hard_fail_if_absent.some(s => absentSymptoms.includes(s));
      if (hardFail) {
        breakdown.gate_score = params.gate_hard_fail_penalty; // -60
        score += params.gate_hard_fail_penalty;
        gatePass = false;
      }
    }

    if (gatePass) {
      // Required all — every symptom must be present
      if (gates.required_all && gates.required_all.length > 0) {
        const allMet = gates.required_all.every(s => presentSymptoms.includes(s));
        if (!allMet) {
          breakdown.gate_score = params.gate_soft_fail_penalty; // -18
          score += params.gate_soft_fail_penalty;
          gatePass = false;
        }
      }

      // Required any — at least one must be present
      if (gatePass && gates.required_any && gates.required_any.length > 0) {
        const anyMet = gates.required_any.some(s => presentSymptoms.includes(s));
        if (!anyMet && !gates.soft_require_any) {
          breakdown.gate_score = params.gate_soft_fail_penalty; // -18
          score += params.gate_soft_fail_penalty;
          gatePass = false;
        }
      }

      if (gatePass) {
        breakdown.gate_score = params.gate_pass_bonus; // +12
        score += params.gate_pass_bonus;
      }
    }

    // ── Symptom weights (positive contributions) ───────────────────────
    const sw = disease.symptom_weights || {};
    for (const sym of presentSymptoms) {
      if (sw[sym]) {
        breakdown.symptom_score += sw[sym];
        matchedSymptoms.push(sym);
        if ((disease.hallmarks || []).includes(sym)) {
          matchedHallmarks.push(sym);
        }
      }
    }
    score += breakdown.symptom_score;

    // ── Absent hallmark penalties ─────────────────────────────────────
    // Penalise only if disease has explicit absent_hallmark_penalties
    const ahp = disease.absent_hallmark_penalties || {};
    for (const sym of absentSymptoms) {
      if (ahp[sym]) {
        breakdown.absent_hallmark_penalty += ahp[sym];
      }
    }
    // Additional: penalise mandatory hallmarks confirmed absent if weight >= 14
    // (weight >= 14 proxies sensitivity >= 0.80 — Mandell calibration)
    for (const sym of absentSymptoms) {
      if ((disease.hallmarks || []).includes(sym) && sw[sym] >= 14) {
        breakdown.absent_hallmark_penalty += params.absent_mandatory_hallmark_penalty; // -12
      }
    }
    score += breakdown.absent_hallmark_penalty;

    // ── Contradiction penalties (negative weights) ────────────────────
    const nw = disease.negative_weights || {};
    for (const sym of presentSymptoms) {
      if (nw[sym]) {
        breakdown.contradiction_penalty += nw[sym];
        score += nw[sym];
      }
    }
    // Half-credit for contradicting symptoms confirmed absent (negative evidence)
    for (const sym of absentSymptoms) {
      if (nw[sym] && nw[sym] < -4) {
        const halfPenalty = Math.floor(nw[sym] / 2);
        breakdown.contradiction_penalty += halfPenalty;
        score += halfPenalty;
      }
    }

    // ── Exposure weights ──────────────────────────────────────────────
    const ew = disease.exposure_weights || {};
    for (const exp of selectedExposures) {
      if (ew[exp]) {
        breakdown.exposure_score += ew[exp];
        matchedExposures.push(exp);
      }
    }
    score += breakdown.exposure_score;

    // ── Syndrome bonus ────────────────────────────────────────────────
    // Always 0 here. Diseases_intelligence.js patches this after loading,
    // computing the syndrome_bonus via ENGINE_TO_WHO_SYNDROME mapping
    // and the WHO syndrome classification rules.
    breakdown.syndrome_bonus = 0; // patched by Diseases_intelligence.js

    // ── Outbreak bonus ────────────────────────────────────────────────
    // Always 0 here. Diseases_intelligence.js patches via buildOutbreakContext()
    // which uses ENDEMIC_COUNTRIES oracle to populate outbreakContext[].
    if (outbreakContext.includes(disease.id)) {
      breakdown.outbreak_bonus = disease.outbreak_bonus || params.outbreak_bonus_default;
      score += breakdown.outbreak_bonus;
    }

    // ── Vaccination modifiers ─────────────────────────────────────────
    const vm = disease.vaccination_modifiers || {};
    for (const [vaccine, states] of Object.entries(vm)) {
      const status = vaccinationHistory[vaccine];
      if (status && states[status] !== undefined) {
        breakdown.vaccination_modifier += states[status];
        score += states[status];
      }
    }

    // ── Onset modifiers ───────────────────────────────────────────────
    if (clinicalContext.days_since_onset !== undefined) {
      const days = Number(clinicalContext.days_since_onset);
      for (const [key, val] of Object.entries(disease.onset_modifiers || {})) {
        // Parse range patterns: "days_since_onset_5_to_21" → [5,21]
        const rangeMatch = key.match(/(\d+)_to_(\d+)/);
        const orMoreMatch = key.match(/(\d+)_or_more/);
        if (rangeMatch) {
          const lo = parseInt(rangeMatch[1]);
          const hi = parseInt(rangeMatch[2]);
          if (days >= lo && days <= hi) {
            breakdown.onset_modifier += val;
            score += val;
          }
        } else if (orMoreMatch) {
          const lo = parseInt(orMoreMatch[1]);
          if (days >= lo) {
            breakdown.onset_modifier += val;
            score += val;
          }
        }
      }
    }

    // ── Override boosts ────────────────────────────────────────────────
    if (overrideBoosts[disease.id]) {
      breakdown.override_boost = overrideBoosts[disease.id];
      score += breakdown.override_boost;
    }

    // ── Clamp score to [0, 100] ────────────────────────────────────────
    score = Math.max(params.min_score_floor, Math.min(params.max_score_cap, Math.round(score)));

    // ── Confidence band ────────────────────────────────────────────────
    let confidenceBand = bands[bands.length - 1].band;
    for (const b of bands) {
      if (score >= b.min_score) { confidenceBand = b.band; break; }
    }

    results.push({
      disease_id: disease.id,
      name: disease.name,
      final_score: score,
      raw_score: score,
      confidence_band: confidenceBand,
      priority_tier: disease.priority_tier,
      ihr_category: disease.who_category,
      severity: disease.severity,
      cfr_pct: disease.case_fatality_rate_pct,
      alert_level: forcedAlertLevel || disease.alert_level_if_top_ranked || 'medium',
      matched_hallmarks: matchedHallmarks,
      matched_symptoms: matchedSymptoms,
      matched_exposures: matchedExposures,
      score_breakdown: breakdown,
      key_distinguishers: disease.key_distinguishers || [],
      recommended_tests: disease.recommended_tests || [],
      immediate_actions: disease.immediate_actions || [],
      differential_diseases: disease.differential_diseases || [],
      probability_like_percent: null, // computed below for top 5
    });
  }

  // ── Sort: score desc → priority tier → hallmarks matched ─────────────
  const tierOrder = {
    'tier_1_ihr_critical': 0,
    'tier_2_ihr_annex2': 1,
    'tier_2_ihr_equivalent': 2,
    'tier_3_who_notifiable': 3,
    'tier_4_syndromic': 4,
  };

  results.sort((a, b) => {
    if (b.final_score !== a.final_score) return b.final_score - a.final_score;
    const ta = tierOrder[a.priority_tier] ?? 9;
    const tb = tierOrder[b.priority_tier] ?? 9;
    if (ta !== tb) return ta - tb;
    return b.matched_hallmarks.length - a.matched_hallmarks.length;
  });

  // ── Probability-like normalisation over top 5 ─────────────────────────
  const top5 = results.slice(0, 5);
  const top5Total = top5.reduce((s, r) => s + r.final_score, 0);
  top5.forEach(r => {
    r.probability_like_percent = top5Total > 0
      ? Math.round(100 * r.final_score / top5Total * 10) / 10
      : 0;
  });

  // ── Global flags ───────────────────────────────────────────────────────
  const globalFlags = [];

  if (overridesFired.includes('override_vhf_red_flag') ||
    overridesFired.includes('override_any_haemorrhage_fever_no_exposure')) {
    globalFlags.push('VHF_PROTOCOL_ACTIVATED');
    globalFlags.push('NEEDS_IMMEDIATE_ISOLATION');
    globalFlags.push('NEEDS_IHR_NOTIFICATION');
  }
  if (overridesFired.includes('override_acute_flaccid_paralysis')) {
    globalFlags.push('AFP_SURVEILLANCE_ACTIVATED');
    globalFlags.push('NEEDS_IHR_NOTIFICATION');
  }
  if (overridesFired.includes('override_watery_diarrhea_dehydration')) {
    globalFlags.push('CHOLERA_PROTOCOL_ACTIVATED');
  }
  if (overridesFired.includes('override_rabies_pathognomonic')) {
    globalFlags.push('RABIES_PROTOCOL_ACTIVATED');
    globalFlags.push('NEEDS_EMERGENCY_REFERRAL');
  }
  if (overridesFired.includes('override_smallpox_pustular_centrifugal')) {
    globalFlags.push('BIOTERRORISM_PROTOCOL_ACTIVATED');
    globalFlags.push('NEEDS_IMMEDIATE_ISOLATION');
    globalFlags.push('NEEDS_IHR_NOTIFICATION');
  }
  if (overridesFired.includes('override_skin_eschar')) {
    globalFlags.push('BIOTERRORISM_ASSESSMENT_REQUIRED');
  }
  if (overridesFired.includes('override_meningitis_triad')) {
    globalFlags.push('MENINGITIS_PROTOCOL_ACTIVATED');
    globalFlags.push('NEEDS_EMERGENCY_REFERRAL');
  }

  // Tier 1 / Tier 2 IHR disease in top 3 with score >= 25
  if (top5.slice(0, 3).some(r =>
    ['tier_1_ihr_critical', 'tier_2_ihr_annex2'].includes(r.priority_tier) &&
    r.final_score >= 25
  )) {
    if (!globalFlags.includes('NEEDS_IHR_NOTIFICATION')) {
      globalFlags.push('NEEDS_IHR_NOTIFICATION');
    }
    globalFlags.push('NEEDS_PUBLIC_HEALTH_NOTIFICATION');
  }

  // Pregnancy risk diseases
  if (clinicalContext.pregnant) {
    const pregnancyRisk = ['zika', 'hepatitis_e', 'rubella', 'malaria_severe', 'malaria_uncomplicated'];
    if (top5.some(r => pregnancyRisk.includes(r.disease_id) && r.final_score >= 25)) {
      globalFlags.push('PREGNANCY_RISK_FLAG');
    }
  }

  // Insufficient data
  if (presentSymptoms.length < 2 && selectedExposures.length === 0) {
    globalFlags.push('INSUFFICIENT_DATA');
  }

  // All reportable = score >= 25
  const reportable = results.filter(r => r.final_score >= 25);

  return {
    top_diagnoses: top5,
    all_reportable: reportable,
    overrides_fired: overridesFired,
    global_flags: [...new Set(globalFlags)],
    input_summary: {
      symptoms_count: presentSymptoms.length,
      absent_count: absentSymptoms.length,
      exposures_count: selectedExposures.length,
      outbreak_context: outbreakContext,
      confidence_baseline: presentSymptoms.length >= 2 || selectedExposures.length > 0
        ? 'sufficient' : 'very_low',
      total_scored: results.length,
    },
  };
};

console.log(
  '%c[Diseases.js] Loaded — ' + window.DISEASES.diseases.length +
  ' diseases, scoreDiseases() ready. Load Diseases_intelligence.js next.',
  'color:#00B4FF;font-weight:700;font-size:12px'
);