/**
 * ============================================================
 * WHO POE EXPOSURE RISK CATALOG v2.1.1
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
 * REFACTOR v2.1.1 (2026-05-05):
 *   - Merged SEXUAL_CONTACT into CONTACT_PERSON_INFECTIOUS, reframed in
 *     WHO contact-tracing language (sexual contact is a recognised mode of
 *     direct person-to-person transmission per WHO 2021/2024 guidance).
 *     The 'sexual_contact' engine code is preserved on the merged entry so
 *     mpox/zika/hepatitis_b scoring is unchanged.
 *   - SEXUAL_CONTACT shimmed to CONTACT_PERSON_INFECTIOUS in LEGACY_CODE_SHIMS.
 *
 * REFACTOR v2.1.0 (2026-05-05):
 *   - Merged CONTACT_SICK_PERSON + CONTACT_CONFIRMED_CASE
 *     → CONTACT_PERSON_INFECTIOUS
 *   - Merged TRAVEL_OUTBREAK_AREA + RESIDENCE_OUTBREAK_AREA
 *     → GEOGRAPHIC_OUTBREAK_EXPOSURE
 *   - Merged ANIMAL_EXPOSURE_LIVESTOCK + POULTRY_BIRD_EXPOSURE + CAMEL_EXPOSURE
 *     → ANIMAL_LIVESTOCK_DOMESTIC
 *   - Merged MASS_GATHERING + HAJJ_UMRAH_PILGRIMAGE
 *     → MASS_GATHERING_PILGRIMAGE
 *   - Moved CONTACT_DEAD_BODY + CONTACT_BODY_FLUIDS to person_contact category
 *   - Removed DATE_PALM_SAP, FIELD_FLOOD_AGRICULTURE, SOIL_DUST_AEROSOL
 *   - All removed/merged old codes are shimmed in LEGACY_CODE_SHIMS
 *     so that IDB records written before this refactor continue to resolve.
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
        version: '2.1.1',
        schema_version: '2.1.1',
        last_updated: '2026-05-05',
        context: 'WHO IHR 2005 aligned exposure risk factors for POE secondary screening',
        engine_compatibility: 'Diseases.js v3.0.0+',
        usage: [
            'Call mapToEngineCodes(dbRecords) to translate DB responses to engine codes.',
            'Call getEnhancedScoreResult() in Diseases_intelligence.js passing engine codes.',
            'Never put exposure mapping logic in the Vue — all logic belongs here.',
        ],
    },

    /* ────────────────────────────────────────────────────────────────────
       LEGACY CODE SHIMS
       Maps old DB/IDB exposure codes to their new canonical codes.
       Used by getByCode() and mapToEngineCodes() so that records written
       before the v2.1.0 refactor continue to resolve correctly at read time.
       A value of null means the code has been removed — it is silently
       dropped (no engine codes emitted).
    ─────────────────────────────────────────────────────────────────────── */

    LEGACY_CODE_SHIMS: {
        // Old codes → new canonical codes (for IDB records written before this refactor)
        'TRAVEL_OUTBREAK_AREA':      'GEOGRAPHIC_OUTBREAK_EXPOSURE',
        'RESIDENCE_OUTBREAK_AREA':   'GEOGRAPHIC_OUTBREAK_EXPOSURE',
        'CONTACT_SICK_PERSON':       'CONTACT_PERSON_INFECTIOUS',
        'CONTACT_CONFIRMED_CASE':    'CONTACT_PERSON_INFECTIOUS',
        'ANIMAL_EXPOSURE_LIVESTOCK': 'ANIMAL_LIVESTOCK_DOMESTIC',
        'POULTRY_BIRD_EXPOSURE':     'ANIMAL_LIVESTOCK_DOMESTIC',
        'CAMEL_EXPOSURE':            'ANIMAL_LIVESTOCK_DOMESTIC',
        'MASS_GATHERING':            'MASS_GATHERING_PILGRIMAGE',
        'HAJJ_UMRAH_PILGRIMAGE':     'MASS_GATHERING_PILGRIMAGE',
        'SEXUAL_CONTACT':            'CONTACT_PERSON_INFECTIOUS',
        // Removed codes — map to null (silently drop, do not send to engine)
        'DATE_PALM_SAP':             null,
        'FIELD_FLOOD_AGRICULTURE':   null,
        'SOIL_DUST_AEROSOL':         null,
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
            code: 'GEOGRAPHIC_OUTBREAK_EXPOSURE',
            label: 'Resident of or travel through an area with ongoing outbreak',
            description: 'The traveller currently resides in, or visited, a geographic zone with an ongoing confirmed or suspected outbreak of a notifiable disease within the past 21 days.',
            category: 'travel_geographic',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'travel_from_outbreak_area',
                'residence_in_outbreak_area',
            ],
            priority_diseases: ['cholera', 'ebola_virus_disease', 'yellow_fever', 'mpox', 'meningococcal_meningitis', 'dengue', 'malaria_severe', 'lassa_fever', 'typhoid_fever'],
            who_ihr_ref: 'IHR 2005 Art. 23(1)(a); AFRO IDSR 2021 §4.2',
            requires_details: true,
            screening_questions: [
                'Which country/region specifically?',
                'Was there an active WHO Disease Outbreak News for that area?',
                'Did you have contact with ill people in that area?',
                'Duration of stay or residence?',
            ],
            who_info_modal: {
                definition: 'The traveller currently resides in, or visited, a geographic zone with an ongoing confirmed or suspected outbreak of a notifiable disease within the past 21 days.',
                clinical_signs: 'Risk depends on the specific disease in outbreak. Any febrile illness in a traveller from an active outbreak zone should be treated as a potential case until excluded.',
                differentiation: 'Distinguish between travel through (transit, short stay) and residence (prolonged exposure, higher cumulative risk). Both are significant but residence carries higher risk.',
                who_source: 'IHR 2005 Art. 23(1)(a); WHO Disease Outbreak News; AFRO IDSR 2021 §4.2',
            },
        },

        // ── PERSON-TO-PERSON CONTACT ─────────────────────────────────────────
        {
            code: 'CONTACT_PERSON_INFECTIOUS',
            label: 'Direct or indirect contact with a person with a suspected or confirmed infectious disease — including physical, droplet, body-fluid, or sexual contact',
            description: 'Per WHO contact-tracing guidance, any direct or indirect exposure to a person classified by a health authority as a confirmed, probable, or suspected case of a notifiable infectious disease within the lookback window. This includes face-to-face conversation within 1 metre, shared enclosed environments, droplet or aerosol exposure, contact with body fluids or contaminated materials, AND sexual contact (vaginal, anal, or oral) — recognised by WHO as a mode of direct person-to-person transmission for mpox, zika, hepatitis B, and other pathogens.',
            category: 'person_contact',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'close_contact_case',
                'contact_body_fluids',
                'affected_healthcare_facility_exposure',
                'contact_with_rash_case',
                'sexual_contact',
            ],
            priority_diseases: ['ebola_virus_disease', 'marburg_virus_disease', 'lassa_fever', 'mpox', 'cholera', 'meningococcal_meningitis', 'influenza_new_subtype_zoonotic', 'measles', 'zika', 'hepatitis_b', 'rubella'],
            who_ihr_ref: 'IHR 2005 Art. 23(1)(a); AFRO IDSR 2021 §5.1; WHO Contact Tracing Guidelines 2021; WHO Mpox Guidance 2024; WHO Sexual Health Guidelines 2022',
            requires_details: true,
            screening_questions: [
                'What disease was the person diagnosed with or suspected of having?',
                'What was the nature of contact (physical, respiratory droplet, shared space, blood/body fluids, sexual)?',
                'Were you within 1 metre without protection?',
                'Did you share a room, vehicle, or food with this person?',
                'Did the contact include sexual exposure (vaginal, anal, or oral) — particularly with a new or unknown partner, or in an area with elevated mpox or STI transmission?',
                'Were you wearing PPE during the contact?',
                'Date of last contact?',
            ],
            who_info_modal: {
                definition: 'Per WHO contact-tracing guidance, any direct or indirect exposure to a person classified as a confirmed, probable, or suspected case of a notifiable infectious disease — including face-to-face/droplet contact, shared enclosed environments, body-fluid exposure, and sexual contact (vaginal, anal, or oral). WHO recognises sexual contact as a mode of direct person-to-person transmission for mpox, zika, hepatitis B, and several other pathogens, and treats it as a form of close contact for case-investigation purposes.',
                clinical_signs: 'Variable — dependent on the disease of the source case. Concerns include VHF, respiratory pathogens, enteric pathogens, mpox lesions, and sexually transmissible viral infections (mpox, zika, hepatitis B). Mucous-membrane and broken-skin exposure carry higher risk than intact-skin contact.',
                differentiation: 'Distinguish: (a) confirmed case contact (known diagnosis) vs. symptomatic individual contact (suspected, undiagnosed) — both carry risk, confirmed is higher; (b) physical/droplet contact (general respiratory and contact-route pathogens) vs. body-fluid/sexual contact (mpox, VHF, hepatitis B, zika) — same exposure category, but sexual or mucosal exposure raises priority for mpox and STI-transmissible viruses.',
                who_source: 'WHO Contact Tracing: Key Considerations 2021; WHO Mpox Guidance 2024; WHO Sexual Health Guidelines 2022; IHR 2005 Art. 23(1)(a)',
            },
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
            who_info_modal: {
                definition: 'Direct skin or mucous membrane contact with a person exhibiting unexplained vesicular, pustular, or ulcerative skin lesions (relevant for Mpox, varicella, smallpox differential).',
                clinical_signs: 'Mpox: deep-seated, umbilicated lesions progressing macule→papule→vesicle→pustule→crust over 2-4 weeks. Lesions often on face, hands, genitals. Measles: maculopapular, face first, spreads downward. Varicella: superficial vesicles, varied stages simultaneously.',
                rash_distribution: 'Mpox: centrifugal (face/extremities > trunk). Smallpox: centrifugal, synchronous stage. Measles: centrifugal, maculopapular. Varicella: centripetal (trunk > extremities), varied stages.',
                progression: 'Mpox lesion stages: macule (flat) → papule (raised) → vesicle (fluid-filled) → pustule (pus-filled) → crust → scar. All at same stage at any one time (unlike varicella).',
                differentiation: 'Key differentiator: Mpox lesions are deep-seated and firm; varicella lesions are superficial and fragile. Mpox: multiple lesion stages simultaneously is unusual (unlike varicella where they are mixed). Smallpox: all lesions at same stage, centrifugal.',
                who_source: 'WHO Mpox Guidance 2024; IHR Annex 2',
            },
        },

        // SEXUAL_CONTACT merged into CONTACT_PERSON_INFECTIOUS in v2.1.1 — see
        // LEGACY_CODE_SHIMS. The 'sexual_contact' engine code is preserved on
        // the merged entry so mpox/zika/hepatitis_b/rubella scoring is unchanged.

        // ── HIGH-RISK BODY SUBSTANCE EXPOSURE (now person_contact category) ──
        {
            code: 'CONTACT_BODY_FLUIDS',
            label: 'Exposure to blood or body fluids of a symptomatic or deceased person',
            description: 'Contact with blood, sweat, saliva, vomit, faeces, urine, or other body fluids of a person who was ill or deceased, without adequate personal protective equipment.',
            category: 'person_contact',
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
            who_info_modal: {
                definition: 'Contact with blood, sweat, saliva, vomit, faeces, urine, or other body fluids of a person who was ill or deceased, without adequate personal protective equipment.',
                clinical_signs: 'Source person with VHF signs: fever, haemorrhage, vomiting, diarrhoea. Blood contact is highest risk; sweat and saliva are lower risk for most VHF.',
                differentiation: 'Any mucous membrane (eyes, mouth, nose) or broken skin contact with body fluids is significant. Intact skin is a partial barrier. Healthcare workers, family caregivers, and traditional healers are highest risk groups.',
                who_source: 'IHR 2005 Art. 23; WHO Standard Precautions Guidelines 2007',
            },
        },

        {
            code: 'CONTACT_DEAD_BODY',
            label: 'Contact with a dead body or participation in burial/funeral rites',
            description: 'Direct physical contact with a deceased person, including participation in traditional burial practices that involve touching the body, without adequate protection. Extremely high risk for VHF transmission.',
            category: 'person_contact',
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
            who_info_modal: {
                definition: 'Direct physical contact with a deceased person, including participation in traditional burial practices involving touching the body. An extremely high-risk exposure for VHF transmission.',
                clinical_signs: 'Source person likely had VHF symptoms at time of death: fever, haemorrhage, vomiting, diarrhoea. The body remains infectious after death.',
                differentiation: 'Distinguish traditional burial rites (body washing, communal touching) from hospital-supervised burial with PPE. Traditional rites carry extremely high VHF risk.',
                who_source: 'WHO Safe and Dignified Burial Guidelines 2021; IHR Annex 2',
            },
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
            code: 'ANIMAL_LIVESTOCK_DOMESTIC',
            label: 'Contact with livestock, poultry, or domestic animals',
            description: 'Direct physical contact with live or recently slaughtered livestock, poultry, camels, or other domestic animals within the past 21 days, including handling, slaughtering, or close proximity in a farming, market, or household setting.',
            category: 'animal_zoonotic',
            risk_level: 'HIGH',
            lookback_days: 21,
            engine_codes: [
                'livestock_raw_dairy_abattoir',
                'animal_bite_or_wildlife_contact',
                'flood_livestock_exposure',
                'camel_exposure_or_mideast_healthcare',
                'poultry_or_live_bird_exposure',
            ],
            priority_diseases: ['brucellosis', 'rift_valley_fever', 'cchf', 'anthrax_cutaneous', 'mers', 'leptospirosis', 'influenza_new_subtype_zoonotic'],
            who_ihr_ref: 'WHO One Health Framework; AFRO IDSR §8.3; WHO MERS Interim Guidance 2023; WHO Avian Influenza 2024',
            requires_details: true,
            screening_questions: [
                'What type of animal (camel, cattle, poultry/chicken/duck, goat, pig, other)?',
                'Did you consume raw or undercooked meat, blood, or unpasteurized milk?',
                'Were any animals ill or dying in the area?',
                'Was there an animal die-off (epizootic) in the area?',
                'Did you visit a live bird market or wet market?',
                'Did you handle carcasses or feathers without PPE?',
            ],
            critical_flag: true,
            critical_message: 'Poultry contact + severe respiratory illness = IMMEDIATE IHR notification (H5N1 risk). Camel contact in Arabian Peninsula = MERS risk.',
            who_info_modal: {
                definition: 'Direct physical contact with live or recently slaughtered livestock, poultry, camels, or other domestic animals within the past 21 days, including handling, slaughtering, or close proximity in a farming, market, or household setting.',
                clinical_signs: 'MERS: fever, cough, shortness of breath (camel exposure). Avian influenza: severe respiratory illness (poultry exposure). Brucellosis: fever, sweats, arthralgia (livestock). CCHF: fever, haemorrhage (tick on livestock).',
                differentiation: 'Camel contact in Middle East — high MERS risk. Poultry at live bird markets — Avian influenza. General livestock — Brucellosis, RVF, CCHF. Bushmeat — Ebola, Mpox.',
                who_source: 'WHO One Health Framework; WHO MERS Guidance 2023; WHO Avian Influenza 2024; IHR Annex 2',
            },
        },

        {
            code: 'ANIMAL_EXPOSURE_WILDLIFE',
            label: 'Contact with wildlife, wild animals, bushmeat, or bats',
            description: 'Within the last 21 days: hunting, handling, butchering, or consuming wild animals (bushmeat) including primates, bats, rodents, or other wildlife. Entry into caves, mines, or bat roosting sites.',
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
            who_info_modal: {
                definition: 'Within the last 21 days: hunting, handling, butchering, or consuming wild animals (bushmeat) including primates, bats, rodents, or other wildlife. Entry into caves, mines, or bat roosting sites.',
                clinical_signs: 'Ebola/Marburg: fever, haemorrhage (primate/bat contact). Nipah: fever, encephalitis (bat cave). Hantavirus: fever, respiratory failure (rodent). Rabies: encephalitis (bat bite).',
                differentiation: 'Bat caves — Marburg (especially fruit bats), Nipah. Bushmeat hunting/handling — Ebola, Mpox. Rodent handling — Hantavirus, Lassa. Dead wildlife — higher risk than live (VHF viremic animals die).',
                who_source: 'WHO One Health; PREDICT Programme Guidelines; IHR Annex 2',
            },
        },

        {
            code: 'ANIMAL_BITE_SCRATCH',
            label: 'Bite, scratch, or lick from an animal (within the past 90 days)',
            description: 'Within the last 90 days: any animal bite or scratch that broke the skin, or lick on broken skin or mucous membranes — particularly from dogs, bats, cats, foxes, jackals, or non-human primates. Rabies risk regardless of animal appearance.',
            category: 'animal_zoonotic',
            risk_level: 'HIGH',
            lookback_days: 90,
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
            who_info_modal: {
                definition: 'A bite, scratch, or mucosal/wound lick by a potentially rabid animal — including dogs, cats, bats, foxes, raccoons, or any wild mammal — occurring within the past 90 days. Even minor scratches or licks on broken skin are considered significant exposures.',
                clinical_signs: 'Rabies prodrome: fever, headache, malaise, paraesthesia at bite site. Followed by agitation, hydrophobia, encephalitis. Virtually 100% fatal once symptomatic.',
                differentiation: 'Distinguish hydrophobic (furious) from paralytic rabies. ANY animal bite in a rabies-endemic country without PEP = significant risk. Even a vaccinated dog that has not received boosters can transmit.',
                who_source: 'WHO Rabies PEP Guidelines 2018; IHR Art. 23',
            },
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

        // ── MASS GATHERINGS ───────────────────────────────────────────────────
        {
            code: 'MASS_GATHERING_PILGRIMAGE',
            label: 'Attendance at a mass gathering or religious pilgrimage',
            description: 'Attendance at a mass gathering of 1,000 or more people, including religious pilgrimages, political rallies, sporting events, or festivals, within the past 21 days. Mass gatherings facilitate rapid spread of respiratory, enteric, and vaccine-preventable diseases.',
            category: 'community',
            risk_level: 'MODERATE',
            lookback_days: 21,
            engine_codes: [
                'crowded_closed_setting',
                'mass_gathering_hajj_umrah',
                'close_contact_case',
                'camel_exposure_or_mideast_healthcare',
            ],
            priority_diseases: ['measles', 'meningococcal_meningitis', 'influenza_seasonal', 'influenza_new_subtype_zoonotic', 'cholera', 'mers'],
            who_ihr_ref: 'WHO Mass Gatherings Risk Assessment Guide 2015; WHO Hajj Health Requirements; Saudi MoH Guidelines 2024',
            requires_details: true,
            screening_questions: [
                'What type of event (pilgrimage, sports, concert, political rally, market, festival)?',
                'Approximate number of attendees?',
                'Was this indoors or outdoors?',
                'Were others visibly ill at the event?',
                'Was this event in a country with known outbreak activity?',
                'Did you receive MenACWY vaccination before Hajj or a major pilgrimage?',
                'Did you have contact with camels during the visit?',
            ],
            who_info_modal: {
                definition: 'Attendance at a mass gathering of 1,000 or more people, including religious pilgrimages, political rallies, sporting events, or festivals, within the past 21 days.',
                clinical_signs: 'Fever, rash (measles), fever + stiff neck (meningitis), respiratory illness (influenza, MERS). Crowded settings amplify spread of respiratory and vaccine-preventable pathogens.',
                differentiation: 'Hajj/Umrah — specific MERS and meningitis risk (Saudi Arabia). Any large gathering — measles, influenza, meningitis. Outdoor festivals in endemic areas — dengue, malaria via mosquito exposure.',
                who_source: 'WHO Mass Gatherings Risk Assessment Guide 2015; Saudi MoH Hajj Guidelines 2024',
            },
        },

        // ── VACCINATION STATUS ────────────────────────────────────────────────
        {
            code: 'UNVACCINATED',
            label: 'Vaccination status for vaccine-preventable diseases',
            description: 'Traveller\'s vaccination status for vaccine-preventable diseases — including whether they have valid vaccination records, which vaccines they have received, and when.',
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

        // CONTACT_PARALYSIS_CASE removed per operational request:
        // AFP paralysis requires physical examination to identify — screeners
        // should not be in prolonged close contact to assess for this.

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
     * Resolves legacy shimmed codes transparently.
     */
    getByCode(code) {
        if (!code) return null;
        // Resolve legacy shim first
        if (Object.prototype.hasOwnProperty.call(this.LEGACY_CODE_SHIMS, code)) {
            const shimmed = this.LEGACY_CODE_SHIMS[code];
            if (shimmed === null) return null;
            code = shimmed;
        }
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
     * Legacy shimmed codes (from records written before v2.1.0 refactor)
     * are resolved transparently. Removed codes (null shim) are silently dropped.
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
            let lookupCode = rec.exposure_code;
            // Legacy shim: map old codes to new canonical codes
            if (Object.prototype.hasOwnProperty.call(this.LEGACY_CODE_SHIMS, lookupCode)) {
                lookupCode = this.LEGACY_CODE_SHIMS[lookupCode];
                if (lookupCode === null) continue; // silently drop removed codes
            }
            const entry = this.getByCode(lookupCode);
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
        person_contact: 'Person-to-Person Contact (Including Body Fluid & Burial)',
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
    console.log('%c[WHO-POE Exposure Catalog] Loaded v2.1.1 — ' + window.EXPOSURES.exposures.length + ' exposures with engine code mapping. Call EXPOSURES.mapToEngineCodes(records) before scoreDiseases().', 'color:#00B0FF;font-weight:700;font-size:12px');
} else {
    console.warn('[WHO-POE Exposure Catalog] WARNING: Diseases_intelligence.js not loaded. Load order: Diseases.js → Diseases_intelligence.js → exposures.js');
}
