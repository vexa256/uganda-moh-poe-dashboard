/**
 * ============================================================
 * WHO POE EXPOSURE RISK CATALOG v2.0.0
 * exposures.js
 * ============================================================
 *
 * LOAD ORDER (after Diseases.js and Diseases_intelligence.js):
 *   1. Diseases.js
 *   2. Diseases_intelligence.js
 *   3. exposures.js  ← THIS FILE
 *
 * PURPOSE:
 *   Complete WHO/IHR-aligned exposure risk catalog for POE secondary
 *   screening. Each exposure entry maps directly to the disease engine's
 *   exposure_weights keys so scoreDiseases() receives correct signals.
 *
 * CRITICAL DESIGN PRINCIPLE:
 *   The DB stores exposure codes (e.g., CONTACT_SICK_PERSON).
 *   The engine uses different internal codes (e.g., close_contact_case).
 *   This file owns the canonical mapping between them via engine_codes[].
 *   The Vue calls ONLY window.EXPOSURES.mapToEngineCodes(dbRecords) —
 *   zero mapping logic lives in the UI layer.
 *
 * EXPORTED API:
 *   window.EXPOSURES.exposures          — full catalog array
 *   window.EXPOSURES.getAll()           — returns catalog
 *   window.EXPOSURES.getByCode(code)    — single exposure by DB code
 *   window.EXPOSURES.mapToEngineCodes(dbRecords)
 *     → string[]  engine codes for ALL YES-response exposures
 *   window.EXPOSURES.getHighRiskSignals(dbRecords)
 *     → Array of high-risk exposure objects with YES responses
 *   window.EXPOSURES.buildExposureSummary(dbRecords)
 *     → Structured clinical summary for display and sync
 *
 * SOURCES:
 *   WHO IHR 2005 Article 23 — Health measures at points of entry
 *   WHO AFRO IDSR 2021 Technical Guidelines — Exposure history
 *   CDC Yellow Book 2024 — Travel medicine exposure categories
 *   ECDC Rapid Risk Assessment Templates 2023
 * ============================================================
 */

window.EXPOSURES = {

    metadata: {
        version: '2.0.0',
        schema_version: '2.0.0',
        last_updated: '2026-04-01',
        context: 'WHO IHR 2005 aligned exposure risk factors for POE secondary screening',
        engine_compatibility: 'Diseases.js v3.0.0+',
        usage: [
            'Call mapToEngineCodes(dbRecords) to translate DB responses to engine codes.',
            'Call getEnhancedScoreResult() in Diseases_intelligence.js passing engine codes.',
            'Never put exposure mapping logic in the Vue — all logic belongs here.',
        ],
    },

    /* ────────────────────────────────────────────────────────────────────
       EXPOSURE CATALOG
       Each entry has:
         code          — DB-stored code (PK in secondary_exposures.exposure_code)
         label         — UI display text
         description   — Clinical context for the officer
         category      — Grouping for UI rendering
         risk_level    — LOW / MODERATE / HIGH / VERY_HIGH
         lookback_days — IHR window for this exposure type
         engine_codes  — Array of exact keys used in disease.exposure_weights
                         (one UI exposure can trigger multiple engine signals)
         priority_diseases — Top diseases this exposure most strongly suggests
         who_ihr_ref   — WHO IHR / AFRO IDSR reference
         requires_details — Whether officer must enter free-text details
         screening_questions — Structured follow-up questions for officer
    ─────────────────────────────────────────────────────────────────────── */

    exposures: [

        // ── TRAVEL & GEOGRAPHIC ──────────────────────────────────────────────
        {
            code: 'TRAVEL_OUTBREAK_AREA',
            label: 'Travel to area with active outbreak or known public health event',
            description: 'Visited a country, city, or specific geographic zone with an active WHO-notified outbreak, disease event, or elevated public health risk within the past 21 days.',
            category: 'travel_geographic',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'travel_from_outbreak_area',
                'residence_in_outbreak_area',
            ],
            priority_diseases: ['cholera', 'ebola_virus_disease', 'yellow_fever', 'mpox', 'meningococcal_meningitis', 'dengue', 'malaria_severe'],
            who_ihr_ref: 'IHR 2005 Art. 23(1)(a); AFRO IDSR 2021 §4.2',
            requires_details: true,
            screening_questions: [
                'Which country/region specifically?',
                'Was there an active WHO Disease Outbreak News for that area?',
                'Did you have contact with ill people in that area?',
            ],
        },

        {
            code: 'RESIDENCE_OUTBREAK_AREA',
            label: 'Resident of area with ongoing outbreak',
            description: 'The traveller currently resides or spent >14 days in a geographic zone with documented endemic transmission of a notifiable disease.',
            category: 'travel_geographic',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'residence_in_outbreak_area',
                'travel_from_outbreak_area',
            ],
            priority_diseases: ['cholera', 'malaria_uncomplicated', 'typhoid_fever', 'dengue', 'lassa_fever'],
            who_ihr_ref: 'IHR 2005 Art. 23(1)(a)',
            requires_details: true,
            screening_questions: ['Exact location of residence?', 'Duration of stay?'],
        },

        // ── PERSON-TO-PERSON CONTACT ─────────────────────────────────────────
        {
            code: 'CONTACT_SICK_PERSON',
            label: 'Close contact with a symptomatic individual',
            description: 'Face-to-face contact within 1 metre, or direct physical contact, with a person who was ill with symptoms compatible with infectious disease within the past 21 days.',
            category: 'person_contact',
            risk_level: 'MODERATE',
            lookback_days: 21,
            engine_codes: [
                'close_contact_case',
                'contact_body_fluids',
            ],
            priority_diseases: ['influenza_new_subtype_zoonotic', 'measles', 'meningococcal_meningitis', 'mers', 'sars'],
            who_ihr_ref: 'IHR 2005 Art. 23(1)(a); AFRO IDSR 2021 §5.1',
            requires_details: true,
            screening_questions: [
                'What symptoms did the sick person have?',
                'Were you within 1 metre without protection?',
                'Did you share a room, vehicle, or food?',
            ],
        },

        {
            code: 'CONTACT_CONFIRMED_CASE',
            label: 'Direct contact with a confirmed or suspected disease case',
            description: 'Known or suspected contact with a person officially classified as a confirmed or probable case of a notifiable infectious disease by a health authority.',
            category: 'person_contact',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'close_contact_case',
                'contact_body_fluids',
                'affected_healthcare_facility_exposure',
            ],
            priority_diseases: ['ebola_virus_disease', 'marburg_virus_disease', 'lassa_fever', 'mpox', 'cholera', 'meningococcal_meningitis'],
            who_ihr_ref: 'IHR 2005 Art. 23(1)(a); WHO Contact Tracing Guidelines 2021',
            requires_details: true,
            screening_questions: [
                'What disease was the confirmed case diagnosed with?',
                'What was the nature of contact (physical, respiratory, shared space)?',
                'Were you wearing PPE during the contact?',
                'Date of last contact?',
            ],
        },

        {
            code: 'CONTACT_RASH_CASE',
            label: 'Contact with person having unexplained rash or skin lesions',
            description: 'Direct skin or mucous membrane contact with a person exhibiting unexplained vesicular, pustular, or ulcerative skin lesions (relevant for mpox, varicella, smallpox differential).',
            category: 'person_contact',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'contact_with_rash_case',
                'close_contact_case',
                'sexual_contact',
            ],
            priority_diseases: ['mpox', 'smallpox', 'measles', 'rubella', 'chickenpox'],
            who_ihr_ref: 'WHO Mpox Guidance 2024; IHR Annex 2',
            requires_details: true,
            screening_questions: [
                'Where were the lesions located (face, hands, genitals, trunk)?',
                'Did you have sexual contact with this person?',
                'Was the person diagnosed with anything?',
            ],
        },

        {
            code: 'SEXUAL_CONTACT',
            label: 'Sexual contact with a new or unknown partner (particularly in endemic area)',
            description: 'Recent sexual contact with one or more new or unknown partners, particularly if the contact occurred in a country with elevated STI or mpox transmission.',
            category: 'person_contact',
            risk_level: 'MODERATE',
            lookback_days: 21,
            engine_codes: [
                'sexual_contact',
                'close_contact_case',
                'contact_with_rash_case',
            ],
            priority_diseases: ['mpox', 'zika', 'rubella', 'hepatitis_b'],
            who_ihr_ref: 'WHO Sexual Health Guidelines 2022; Mpox Guidance 2024',
            requires_details: false,
            screening_questions: [],
        },

        // ── HIGH-RISK BODY SUBSTANCE EXPOSURE ────────────────────────────────
        {
            code: 'CONTACT_BODY_FLUIDS',
            label: 'Exposure to blood or body fluids of a symptomatic or deceased person',
            description: 'Contact with blood, sweat, saliva, vomit, faeces, urine, or other body fluids of a person who was ill or deceased, without adequate personal protective equipment.',
            category: 'body_fluid_exposure',
            risk_level: 'VERY_HIGH',
            lookback_days: 21,
            engine_codes: [
                'contact_body_fluids',
                'close_contact_case',
                'funeral_or_burial_exposure',
                'healthcare_exposure',
            ],
            priority_diseases: ['ebola_virus_disease', 'marburg_virus_disease', 'lassa_fever', 'cchf', 'hepatitis_b', 'hiv'],
            who_ihr_ref: 'IHR 2005 Art. 23; WHO Standard Precautions Guidelines 2007',
            requires_details: true,
            screening_questions: [
                'Type of fluid (blood, vomit, faeces, urine, other)?',
                'Was the source person ill or deceased?',
                'Did the fluid contact your eyes, mouth, nose, or broken skin?',
                'Were you wearing gloves and gown?',
            ],
        },

        {
            code: 'CONTACT_DEAD_BODY',
            label: 'Contact with a dead body or participation in burial/funeral rites',
            description: 'Direct physical contact with a deceased person, including participation in traditional burial practices that involve touching the body, without adequate protection. Extremely high risk for VHF transmission.',
            category: 'body_fluid_exposure',
            risk_level: 'VERY_HIGH',
            lookback_days: 21,
            engine_codes: [
                'contact_dead_body',
                'funeral_or_burial_exposure',
                'contact_body_fluids',
            ],
            priority_diseases: ['ebola_virus_disease', 'marburg_virus_disease', 'lassa_fever', 'cchf'],
            who_ihr_ref: 'WHO Safe and Dignified Burial Guidelines 2021; IHR Annex 2',
            requires_details: true,
            screening_questions: [
                'Did the deceased person have symptoms of infectious illness?',
                'Did you touch the body directly (skin, fluids)?',
                'Was this a traditional burial with communal washing of the body?',
                'Did other family members become ill after the burial?',
            ],
        },

        // ── HEALTHCARE SETTING ───────────────────────────────────────────────
        {
            code: 'HEALTHCARE_EXPOSURE',
            label: 'Presence in or exposure within a healthcare facility',
            description: 'Admitted as a patient, visited a patient, or worked as a healthcare worker in a clinic or hospital within the past 21 days, particularly in an endemic or outbreak area.',
            category: 'healthcare',
            risk_level: 'MODERATE',
            lookback_days: 21,
            engine_codes: [
                'healthcare_exposure',
                'affected_healthcare_facility_exposure',
                'close_contact_case',
            ],
            priority_diseases: ['mers', 'sars', 'influenza_new_subtype_zoonotic', 'ebola_virus_disease', 'cholera', 'tuberculosis'],
            who_ihr_ref: 'IHR 2005 Art. 23; WHO Healthcare Infection Prevention 2019',
            requires_details: true,
            screening_questions: [
                'Were you a patient, visitor, or healthcare worker?',
                'Were there patients with unexplained respiratory illness in the facility?',
                'Was PPE used if working clinically?',
                'Was this facility in a country with an active outbreak?',
            ],
        },

        {
            code: 'LABORATORY_EXPOSURE',
            label: 'Work in a laboratory handling potentially infectious biological material',
            description: 'Occupational exposure through laboratory work with clinical specimens, cultures, or research materials that may contain pathogens, including during specimen collection.',
            category: 'healthcare',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'laboratory_exposure',
                'contact_body_fluids',
                'healthcare_exposure',
            ],
            priority_diseases: ['sars', 'anthrax_pulmonary', 'smallpox', 'tularemia', 'brucellosis'],
            who_ihr_ref: 'WHO Laboratory Biosafety Guidelines 2020',
            requires_details: true,
            screening_questions: [
                'What type of specimens or agents were handled?',
                'Was there a needle-stick, spill, or aerosol incident?',
                'What BSL level was the facility?',
            ],
        },

        // ── ANIMAL AND WILDLIFE ──────────────────────────────────────────────
        {
            code: 'ANIMAL_EXPOSURE_LIVESTOCK',
            label: 'Contact with livestock, farm animals, or their products',
            description: 'Direct contact with cattle, sheep, goats, camels, pigs, horses, or other farm animals, or handling of raw meat, blood, organs, hides, wool, or unpasteurized dairy from livestock.',
            category: 'animal_zoonotic',
            risk_level: 'MODERATE',
            lookback_days: 21,
            engine_codes: [
                'livestock_raw_dairy_abattoir',
                'animal_bite_or_wildlife_contact',
                'flood_livestock_exposure',
                'camel_exposure_or_mideast_healthcare',
            ],
            priority_diseases: ['brucellosis', 'rift_valley_fever', 'cchf', 'anthrax_cutaneous', 'mers', 'leptospirosis'],
            who_ihr_ref: 'WHO One Health Framework; AFRO IDSR §8.3',
            requires_details: true,
            screening_questions: [
                'What type of animal (camel, cattle, goat, pig, other)?',
                'Did you consume raw or undercooked meat, blood, or unpasteurized milk?',
                'Were any animals ill or dying in the area?',
                'Was there an animal die-off (epizootic) in the area?',
            ],
        },

        {
            code: 'ANIMAL_EXPOSURE_WILDLIFE',
            label: 'Contact with wildlife, wild animals, bushmeat, or bats',
            description: 'Hunting, handling, butchering, or consuming wild animals (bushmeat) including primates, bats, rodents, or other wildlife. Entry into caves, mines, or bat roosting sites.',
            category: 'animal_zoonotic',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'animal_bite_or_wildlife_contact',
                'bat_cave_mine_exposure',
                'rodent_exposure',
                'flea_or_rodent_exposure',
            ],
            priority_diseases: ['ebola_virus_disease', 'marburg_virus_disease', 'nipah_virus', 'hantavirus', 'rabies', 'mpox'],
            who_ihr_ref: 'WHO One Health; PREDICT Programme Guidelines',
            requires_details: true,
            screening_questions: [
                'What type of wildlife (bat, primate, rodent, other)?',
                'Were any animals dead or ill when found?',
                'Did you handle, skin, or eat the animal?',
                'Did you enter caves, mines, or abandoned buildings where bats roost?',
            ],
        },

        {
            code: 'ANIMAL_BITE_SCRATCH',
            label: 'Bite, scratch, or lick from an animal (especially dog, bat, or primate)',
            description: 'Any animal bite or scratch that broke the skin, or lick on broken skin or mucous membranes — particularly from dogs, bats, cats, foxes, jackals, or non-human primates. Rabies risk regardless of animal appearance.',
            category: 'animal_zoonotic',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'dog_bat_animal_bite',
                'animal_bite_or_wildlife_contact',
                'bat_cave_mine_exposure',
            ],
            priority_diseases: ['rabies', 'herpes_b_virus', 'mpox'],
            who_ihr_ref: 'WHO Rabies PEP Guidelines 2018; IHR Art. 23',
            requires_details: true,
            screening_questions: [
                'What animal bit/scratched you?',
                'Did the wound break the skin or contact a mucous membrane?',
                'Did you receive PEP (post-exposure prophylaxis) after the injury?',
                'Was the animal behaving abnormally (aggression, disorientation)?',
                'Was the animal vaccinated against rabies?',
            ],
            critical_flag: true,
            critical_message: 'ANY animal bite in a rabies-endemic country requires PEP assessment — even if vaccinated. Ask for vaccination card.',
        },

        {
            code: 'POULTRY_BIRD_EXPOSURE',
            label: 'Contact with live poultry, wild birds, or live bird markets',
            description: 'Direct contact with live chickens, ducks, geese, or other poultry, including visiting live bird markets (wet markets), poultry farms, or handling sick/dead birds — particularly in Asia or Africa.',
            category: 'animal_zoonotic',
            risk_level: 'HIGH',
            lookback_days: 10,
            engine_codes: [
                'poultry_or_live_bird_exposure',
                'animal_bite_or_wildlife_contact',
            ],
            priority_diseases: ['influenza_new_subtype_zoonotic'],
            who_ihr_ref: 'WHO Avian Influenza Human-Animal Interface 2024; IHR Annex 2 (influenza)',
            requires_details: true,
            screening_questions: [
                'What type of birds (chickens, ducks, geese, wild birds)?',
                'Were any birds sick or dying?',
                'Did you visit a live bird market or wet market?',
                'Did you handle carcasses or feathers without PPE?',
            ],
            critical_flag: true,
            critical_message: 'H5N1 has >60% CFR. Any poultry exposure + severe respiratory illness = IMMEDIATE IHR notification.',
        },

        {
            code: 'CAMEL_EXPOSURE',
            label: 'Close contact with dromedary camels or camel products (Middle East)',
            description: 'Direct contact with dromedary camels (including camel racing, herding, or veterinary work) or consumption of raw camel milk or products — particularly in the Arabian Peninsula.',
            category: 'animal_zoonotic',
            risk_level: 'HIGH',
            lookback_days: 14,
            engine_codes: [
                'camel_exposure_or_mideast_healthcare',
                'livestock_raw_dairy_abattoir',
                'animal_bite_or_wildlife_contact',
            ],
            priority_diseases: ['mers'],
            who_ihr_ref: 'WHO MERS Interim Guidance 2023; IHR Annex 2',
            requires_details: true,
            screening_questions: [
                'Did you have direct nose/mouth contact with camels?',
                'Did you consume raw camel milk or urine?',
                'Which country were the camels in?',
            ],
        },

        {
            code: 'RODENT_EXPOSURE',
            label: 'Contact with rodents, rodent droppings, or contaminated surfaces',
            description: 'Exposure to rats, mice, or other rodents (including dead animals), or surfaces/food contaminated with rodent urine or droppings — especially in agricultural settings, storage areas, or field work.',
            category: 'animal_zoonotic',
            risk_level: 'MODERATE',
            lookback_days: 14,
            engine_codes: [
                'rodent_exposure',
                'flea_or_rodent_exposure',
                'flood_livestock_exposure',
            ],
            priority_diseases: ['hantavirus', 'leptospirosis', 'lassa_fever', 'plague', 'rickettsia_scrub_typhus'],
            who_ihr_ref: 'WHO Hantavirus Fact Sheet 2022; AFRO IDSR §8.4',
            requires_details: true,
            screening_questions: [
                'Did you handle live or dead rodents?',
                'Did you clean or enter a building with visible rodent droppings?',
                'Did this occur indoors in an agricultural or rural setting?',
                'Were you in a flood-affected area (leptospirosis risk)?',
            ],
        },

        // ── FOOD AND WATER ────────────────────────────────────────────────────
        {
            code: 'UNSAFE_FOOD_WATER',
            label: 'Consumption of potentially contaminated food or water',
            description: 'Drinking untreated tap water, surface water, or well water; consuming raw/undercooked food, street food, or food of unknown origin — particularly in low-WASH settings.',
            category: 'food_water',
            risk_level: 'MODERATE',
            lookback_days: 14,
            engine_codes: [
                'unsafe_water',
                'contaminated_food_or_water',
            ],
            priority_diseases: ['cholera', 'typhoid_fever', 'hepatitis_a', 'hepatitis_e', 'shigellosis_dysentery', 'awd_non_cholera'],
            who_ihr_ref: 'WHO WASH Guidelines 2017; AFRO IDSR §6.1',
            requires_details: false,
            screening_questions: [
                'Did you drink tap water, well water, or river water directly?',
                'Did you eat from street vendors or at communal meals?',
                'Were others in your group also ill after eating the same food?',
            ],
        },

        {
            code: 'RAW_MEAT_BLOOD',
            label: 'Consumption of raw or undercooked meat, blood, or organ meat',
            description: 'Eating raw, undercooked, or inadequately smoked meat — especially bushmeat, pork, goat blood, or organ meat — in endemic regions.',
            category: 'food_water',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'livestock_raw_dairy_abattoir',
                'contaminated_food_or_water',
                'animal_bite_or_wildlife_contact',
            ],
            priority_diseases: ['brucellosis', 'anthrax_cutaneous', 'rift_valley_fever', 'cchf', 'hepatitis_e'],
            who_ihr_ref: 'WHO Food Safety Guidelines; AFRO IDSR §8.3',
            requires_details: true,
            screening_questions: [
                'What type of meat (bushmeat, pork, beef, goat, other)?',
                'Was it raw or minimally cooked?',
                'Did you slaughter or butcher the animal yourself?',
            ],
        },

        {
            code: 'DATE_PALM_SAP',
            label: 'Consumption of raw date palm sap or toddy',
            description: 'Drinking raw or unboiled date palm sap (Nipah risk) or fermented palm wine — particularly in Bangladesh, India, or other South/Southeast Asian countries where fruit bats contaminate sap collection.',
            category: 'food_water',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'pig_farm_date_palm_sap',
                'bat_cave_mine_exposure',
                'contaminated_food_or_water',
            ],
            priority_diseases: ['nipah_virus'],
            who_ihr_ref: 'WHO Nipah Fact Sheet 2022; Bangladesh IEDCR Guidelines',
            requires_details: true,
            screening_questions: [
                'In which country did you drink date palm sap?',
                'Was the sap freshly collected or fermented?',
                'Were bats seen near the sap collection pots at night?',
            ],
        },

        // ── VECTOR EXPOSURE ───────────────────────────────────────────────────
        {
            code: 'VECTOR_MOSQUITO',
            label: 'Exposure to mosquito bites in tropical/endemic area (no adequate prevention)',
            description: 'Unprotected exposure to mosquito bites in a region with known mosquito-borne disease transmission — no DEET/permethrin use, no bed nets, sleeping outdoors or in unscreened accommodation.',
            category: 'vector',
            risk_level: 'MODERATE',
            lookback_days: 21,
            engine_codes: [
                'mosquito_exposure',
            ],
            priority_diseases: ['malaria_uncomplicated', 'malaria_severe', 'dengue', 'chikungunya', 'zika', 'yellow_fever', 'west_nile_fever', 'japanese_encephalitis'],
            who_ihr_ref: 'IHR 2005 Art. 22 (vector control); WHO AFRO IDSR §7',
            requires_details: false,
            screening_questions: [
                'Were you sleeping outdoors or under an unprotected bed net?',
                'Did you use DEET or other insect repellent consistently?',
                'Were you in a malaria-endemic area without prophylaxis?',
            ],
        },

        {
            code: 'VECTOR_TICK',
            label: 'Tick bite or presence in tick-endemic habitat (bush, forest, grassland)',
            description: 'Known tick attachment or removal from skin, or time spent in vegetation/bush/grassland in areas where ticks transmit disease — particularly in sub-Saharan Africa, Central Asia, Eastern Europe, or Balkans.',
            category: 'vector',
            risk_level: 'MODERATE',
            lookback_days: 14,
            engine_codes: [
                'tick_bite_endemic',
                'flea_or_rodent_exposure',
            ],
            priority_diseases: ['cchf', 'rickettsia_scrub_typhus', 'tularemia', 'lyme_disease'],
            who_ihr_ref: 'ECDC Tick-borne Diseases Guidance 2023; AFRO IDSR §7.4',
            requires_details: true,
            screening_questions: [
                'Did you find an attached tick on your body?',
                'In which country/region did this occur?',
                'How long was the tick attached (hours, days)?',
                'Was the tick removed properly or crushed?',
            ],
        },

        {
            code: 'VECTOR_FLEA',
            label: 'Flea bites or contact with flea-infested animals or environments',
            description: 'Bites from fleas, particularly from rodents — in endemic plague regions (Sub-Saharan Africa, Asia) or other flea-borne disease areas.',
            category: 'vector',
            risk_level: 'MODERATE',
            lookback_days: 7,
            engine_codes: [
                'flea_or_rodent_exposure',
                'rodent_exposure',
            ],
            priority_diseases: ['pneumonic_plague', 'bubonic_plague', 'rickettsia_scrub_typhus'],
            who_ihr_ref: 'WHO Plague Fact Sheet 2022; AFRO IDSR §7.5',
            requires_details: true,
            screening_questions: [
                'Did you see unusual numbers of dead rodents near your accommodation?',
                'Did you notice flea bites on your body?',
                'Were you in a known plague-endemic district?',
            ],
        },

        // ── OCCUPATIONAL ──────────────────────────────────────────────────────
        {
            code: 'ABATTOIR_FARM_WORK',
            label: 'Work in abattoir, slaughterhouse, tannery, or processing of animal products',
            description: 'Occupational exposure during slaughter, skinning, handling of hides/wool/bones from livestock — high risk for anthrax, brucellosis, Q fever, and CCHF.',
            category: 'occupational',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'livestock_raw_dairy_abattoir',
                'contact_body_fluids',
                'tick_bite_endemic',
            ],
            priority_diseases: ['anthrax_cutaneous', 'brucellosis', 'cchf', 'rift_valley_fever', 'leptospirosis'],
            who_ihr_ref: 'WHO Anthrax in Humans and Animals 2008; AFRO IDSR §8.3',
            requires_details: true,
            screening_questions: [
                'What type of animal products were handled?',
                'Was PPE used (gloves, mask, eye protection)?',
                'Did you notice skin lesions on animals during slaughter?',
            ],
        },

        {
            code: 'FIELD_FLOOD_AGRICULTURE',
            label: 'Occupational or recreational exposure to flood water, mud, or soil',
            description: 'Wading through flood water, working in rice paddies, agricultural fields, or mining — risk for leptospirosis, schistosomiasis, typhoid through skin or mucous membrane contact with contaminated water.',
            category: 'occupational',
            risk_level: 'MODERATE',
            lookback_days: 14,
            engine_codes: [
                'flood_livestock_exposure',
                'unsafe_water',
                'contaminated_food_or_water',
            ],
            priority_diseases: ['leptospirosis', 'typhoid_fever', 'hepatitis_e', 'hantavirus'],
            who_ihr_ref: 'WHO Leptospirosis Fact Sheet 2011; AFRO IDSR §6.3',
            requires_details: false,
            screening_questions: [
                'Were there dead animals in the flood water?',
                'Did you have open skin wounds while wading?',
                'Was this in a post-flood or agricultural setting?',
            ],
        },

        // ── MASS GATHERINGS ───────────────────────────────────────────────────
        {
            code: 'MASS_GATHERING',
            label: 'Attendance at large crowd event (concert, stadium, rally, market)',
            description: 'Participation in an event gathering >1,000 people in enclosed or semi-enclosed space where prolonged close contact and respiratory transmission is possible.',
            category: 'community',
            risk_level: 'MODERATE',
            lookback_days: 14,
            engine_codes: [
                'crowded_closed_setting',
                'mass_gathering_hajj_umrah',
                'close_contact_case',
            ],
            priority_diseases: ['measles', 'meningococcal_meningitis', 'influenza_seasonal', 'influenza_new_subtype_zoonotic', 'covid_19'],
            who_ihr_ref: 'WHO Mass Gatherings Risk Assessment Guide 2015',
            requires_details: true,
            screening_questions: [
                'How many people attended (approximate)?',
                'Was this indoors or outdoors?',
                'Were others visibly ill at the event?',
                'Was this event in a country with known outbreak activity?',
            ],
        },

        {
            code: 'HAJJ_UMRAH_PILGRIMAGE',
            label: 'Participation in Hajj, Umrah, or other major religious pilgrimage',
            description: 'Attendance at Hajj (Mecca, Saudi Arabia) or Umrah, Arba\'een (Iraq), Kumbh Mela (India), or other large-scale religious pilgrimage events with millions of attendees from multiple countries.',
            category: 'community',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'mass_gathering_hajj_umrah',
                'crowded_closed_setting',
                'camel_exposure_or_mideast_healthcare',
                'close_contact_case',
            ],
            priority_diseases: ['mers', 'meningococcal_meningitis', 'influenza_seasonal', 'influenza_new_subtype_zoonotic'],
            who_ihr_ref: 'WHO Hajj Health Requirements; Saudi MoH Guidelines 2024',
            requires_details: true,
            screening_questions: [
                'Which pilgrimage site and year?',
                'Did you receive MenACWY vaccination before Hajj?',
                'Did you have contact with camels during the visit?',
                'Are you presenting within 14 days of returning?',
            ],
        },

        // ── ENVIRONMENTAL ────────────────────────────────────────────────────
        {
            code: 'SOIL_DUST_AEROSOL',
            label: 'Exposure to contaminated dust, soil aerosols, or airborne particles',
            description: 'Inhalation of dust or aerosols in areas with anthrax contamination (tanneries, bone meal factories), or inhalation of rodent-contaminated dust during cleaning/construction.',
            category: 'environmental',
            risk_level: 'MODERATE',
            lookback_days: 14,
            engine_codes: [
                'livestock_raw_dairy_abattoir',
                'rodent_exposure',
                'flea_or_rodent_exposure',
            ],
            priority_diseases: ['anthrax_pulmonary', 'hantavirus', 'tularemia'],
            who_ihr_ref: 'WHO Anthrax Guidelines 2008',
            requires_details: true,
            screening_questions: [
                'What was the location and nature of the dust exposure?',
                'Were you cleaning or disturbing old rodent nests?',
                'Were you in a tannery, wool factory, or bone processing facility?',
            ],
        },

        // ── VACCINATION STATUS ────────────────────────────────────────────────
        {
            code: 'UNVACCINATED',
            label: 'No or unknown vaccination status for vaccine-preventable diseases',
            description: 'Traveller cannot produce valid vaccination records, or confirms NOT being vaccinated against one or more relevant vaccine-preventable diseases.',
            category: 'vaccination_status',
            risk_level: 'MODERATE',
            lookback_days: 365,
            engine_codes: [
                'unvaccinated_or_unknown_vaccination',
            ],
            priority_diseases: ['measles', 'yellow_fever', 'meningococcal_meningitis', 'polio', 'cholera', 'typhoid_fever'],
            who_ihr_ref: 'IHR 2005 Annex 7 (vaccination certificates)',
            requires_details: true,
            screening_questions: [
                'Do you have a valid yellow fever vaccination certificate?',
                'When did you last receive measles, meningococcal, or typhoid vaccine?',
                'Are you travelling from a yellow fever endemic zone without a valid certificate?',
            ],
        },

        // ── PARALYSIS-SPECIFIC ────────────────────────────────────────────────
        {
            code: 'CONTACT_PARALYSIS_CASE',
            label: 'Contact with a person with acute unexplained paralysis (AFP)',
            description: 'Living in the same household or having close contact with a child or adult with acute onset flaccid limb weakness — AFP surveillance requirement for polio exclusion.',
            category: 'neurological',
            risk_level: 'HIGH',
            lookback_days: 30,
            engine_codes: [
                'contact_with_paralysis_case',
                'close_contact_case',
                'unvaccinated_or_unknown_vaccination',
            ],
            priority_diseases: ['polio'],
            who_ihr_ref: 'WHO GPEI AFP Surveillance Manual; IHR Annex 2 (polio)',
            requires_details: true,
            screening_questions: [
                'Is the person with paralysis a child under 15 years?',
                'Did the paralysis start suddenly (within 1-4 days)?',
                'Are stool samples being collected from the paralysed person?',
                'In which country did this contact occur?',
            ],
        },

    ],

    // ── API FUNCTIONS ─────────────────────────────────────────────────────

    /**
     * getAll()
     * Returns the full exposure catalog for UI rendering.
     */
    getAll() {
        return this.exposures;
    },

    /**
     * getByCode(code)
     * Returns a single exposure entry by its DB code.
     */
    getByCode(code) {
        return this.exposures.find(e => e.code === code) || null;
    },

    /**
     * mapToEngineCodes(dbRecords)
     *
     * THE CRITICAL TRANSLATION FUNCTION.
     * Takes the DB exposure records (from IDB or server) and returns
     * the array of engine codes to pass to scoreDiseases().
     *
     * Only YES-response exposures contribute engine codes.
     * Deduplicates the result.
     *
     * @param  {Array<{ exposure_code: string, response: 'YES'|'NO'|'UNKNOWN' }>} dbRecords
     * @returns {string[]}  engine codes for scoreDiseases() selectedExposures param
     *
     * @example
     *   const engineCodes = window.EXPOSURES.mapToEngineCodes(exposures)
     *   // → ['travel_from_outbreak_area', 'close_contact_case', 'mosquito_exposure']
     *   const result = window.DISEASES.scoreDiseases(present, absent, engineCodes, ctx)
     */
    mapToEngineCodes(dbRecords) {
        if (!Array.isArray(dbRecords) || dbRecords.length === 0) return [];
        const codes = [];
        for (const rec of dbRecords) {
            if ((rec.response || '').toUpperCase() !== 'YES') continue;
            const entry = this.getByCode(rec.exposure_code);
            if (!entry || !Array.isArray(entry.engine_codes)) continue;
            codes.push(...entry.engine_codes);
        }
        return [...new Set(codes)];
    },

    /**
     * getHighRiskSignals(dbRecords)
     *
     * Returns exposures answered YES with risk_level HIGH or VERY_HIGH.
     * Used by the Vue to display red-flag exposure warnings in the UI.
     *
     * @param  {Array} dbRecords
     * @returns {Array<{ code, label, risk_level, priority_diseases, critical_flag, critical_message }>}
     */
    getHighRiskSignals(dbRecords) {
        if (!Array.isArray(dbRecords) || dbRecords.length === 0) return [];
        return dbRecords
            .filter(r => (r.response || '').toUpperCase() === 'YES')
            .map(r => this.getByCode(r.exposure_code))
            .filter(e => e && (e.risk_level === 'HIGH' || e.risk_level === 'VERY_HIGH'))
            .sort((a, b) => {
                const order = { VERY_HIGH: 0, HIGH: 1 };
                return (order[a.risk_level] ?? 9) - (order[b.risk_level] ?? 9);
            });
    },

    /**
     * buildExposureSummary(dbRecords)
     *
     * Builds a structured clinical summary of all YES-response exposures
     * for display in the analysis step and for clinical documentation.
     *
     * @param  {Array} dbRecords
     * @returns {{
     *   yes_count: number,
     *   no_count: number,
     *   unknown_count: number,
     *   high_risk_exposures: string[],
     *   engine_codes_activated: string[],
     *   critical_flags: string[],
     *   clinical_narrative: string
     * }}
     */
    buildExposureSummary(dbRecords) {
        if (!Array.isArray(dbRecords)) return { yes_count: 0, no_count: 0, unknown_count: 0, high_risk_exposures: [], engine_codes_activated: [], critical_flags: [], clinical_narrative: 'No exposures recorded.' };

        const yes = dbRecords.filter(r => (r.response || '').toUpperCase() === 'YES');
        const no = dbRecords.filter(r => (r.response || '').toUpperCase() === 'NO');
        const unk = dbRecords.filter(r => (r.response || '').toUpperCase() === 'UNKNOWN');

        const highRisk = this.getHighRiskSignals(dbRecords);
        const engineCodes = this.mapToEngineCodes(dbRecords);
        const critFlags = yes
            .map(r => this.getByCode(r.exposure_code))
            .filter(e => e && e.critical_flag)
            .map(e => e.critical_message || e.code);

        let narrative = `${yes.length} exposure(s) confirmed (YES). `;
        if (highRisk.length > 0) {
            narrative += `HIGH RISK: ${highRisk.map(e => e.label).join('; ')}. `;
        }
        if (critFlags.length > 0) {
            narrative += `CRITICAL FLAGS: ${critFlags.join(' | ')}`;
        }
        if (yes.length === 0) narrative = 'No exposures confirmed by officer assessment.';

        return {
            yes_count: yes.length,
            no_count: no.length,
            unknown_count: unk.length,
            high_risk_exposures: highRisk.map(e => e.label),
            engine_codes_activated: engineCodes,
            critical_flags: critFlags,
            clinical_narrative: narrative.trim(),
        };
    },

    /**
     * getCategoryGroups()
     *
     * Returns exposures grouped by category for UI section rendering.
     * @returns {Object} { category_key: [exposure, ...] }
     */
    getCategoryGroups() {
        const groups = {};
        for (const exp of this.exposures) {
            if (!groups[exp.category]) groups[exp.category] = [];
            groups[exp.category].push(exp);
        }
        return groups;
    },

    CATEGORY_LABELS: {
        travel_geographic: 'Travel & Geographic Risk',
        person_contact: 'Person-to-Person Contact',
        body_fluid_exposure: 'Body Fluid Exposure',
        healthcare: 'Healthcare Setting',
        animal_zoonotic: 'Animal & Wildlife Contact',
        food_water: 'Food & Water',
        vector: 'Vector (Mosquito, Tick, Flea)',
        occupational: 'Occupational Exposure',
        community: 'Mass Gatherings & Community',
        environmental: 'Environmental & Aerosol',
        vaccination_status: 'Vaccination Status',
        neurological: 'Neurological Contact',
    },

};

if (window.DISEASES && typeof window.DISEASES.getEnhancedScoreResult === 'function') {
    console.log('%c[WHO-POE Exposure Catalog] Loaded — ' + window.EXPOSURES.exposures.length + ' exposures with engine code mapping. Call EXPOSURES.mapToEngineCodes(records) before scoreDiseases().', 'color:#00B0FF;font-weight:700;font-size:12px');
} else {
    console.warn('[WHO-POE Exposure Catalog] WARNING: Diseases_intelligence.js not loaded. Load order: Diseases.js → Diseases_intelligence.js → exposures.js');
}