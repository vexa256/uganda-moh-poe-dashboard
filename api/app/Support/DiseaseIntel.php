<?php

declare(strict_types=1);

namespace App\Support;

/**
 * DiseaseIntel
 *
 * Server-side mirror of the clinical/epi intelligence used to populate
 * notification emails with disease-specific detail (CFR, incubation,
 * transmission, IHR tier, immediate response actions, recommended tests,
 * PPE level, key distinguishers, WHO basis).
 *
 * Keyed by the same `disease_code` values stored in
 * secondary_suspected_diseases.disease_code and matched against
 * src/Diseases.js on the frontend.
 *
 * Sources: WHO IHR 2005 Annex 2, WHO AFRO IDSR Technical Guidelines 2021 (3rd
 * Ed.), WHO Disease Fact Sheets 2024, CDC Yellow Book 2024, Mandell 9th Ed.
 */
final class DiseaseIntel
{
    /**
     * Fields per disease:
     *   name              — full clinical name
     *   ihr_tier          — TIER_1_ALWAYS_NOTIFIABLE | ANNEX2_EPIDEMIC_PRONE | WHO_NOTIFIABLE | SYNDROMIC
     *   who_category      — human label for the tier
     *   cfr_pct           — case fatality rate (%) — untreated / natural history
     *   incubation        — "min-max days (typical)"
     *   transmission      — primary routes (compact)
     *   ppe               — PPE required at POE (compact)
     *   isolation         — required isolation posture
     *   ihr_notification  — legal deadline to notify WHO
     *   immediate_actions — top actions for the officer RIGHT NOW
     *   recommended_tests — confirmatory lab workup
     *   specimens         — what to collect
     *   case_definition   — one-paragraph WHO case definition summary
     *   key_distinguishers— bedside red flags
     *   differential      — top competing diagnoses to rule out
     */
    public const REGISTRY = [

        // ══════════════════════════════════════════════════════════════════
        //  TIER 1 — IHR ALWAYS NOTIFIABLE (single case = PHEIC candidate)
        // ══════════════════════════════════════════════════════════════════

        'smallpox' => [
            'name' => 'Smallpox (Variola)',
            'ihr_tier' => 'TIER_1_ALWAYS_NOTIFIABLE',
            'who_category' => 'IHR Tier 1 — Always Notifiable',
            'cfr_pct' => 30.0,
            'incubation' => '7–17 days (typically 10–14)',
            'transmission' => 'Respiratory droplets + direct lesion contact + contaminated fomites',
            'ppe' => 'BSL-4 airborne + contact; N95/PAPR, gown, gloves, eye protection; negative-pressure isolation',
            'isolation' => 'MAXIMUM isolation — single-patient negative-pressure room',
            'ihr_notification' => 'IMMEDIATE — WHO notification within 24h of clinical suspicion',
            'immediate_actions' => [
                'MAXIMUM ISOLATION of case in negative-pressure room',
                'Activate bioterrorism / variola preparedness protocol',
                'IMMEDIATE WHO IHR NPF notification (< 24h)',
                'Identify and ring-vaccinate all contacts since rash onset',
                'Do NOT attempt routine lab handling — BSL-4 only',
            ],
            'recommended_tests' => [
                'Variola PCR via BSL-4 reference laboratory',
                'Electron microscopy on vesicle fluid (brick-shaped virions)',
                'Orthopoxvirus genus PCR as triage',
            ],
            'specimens' => 'Vesicle/pustule fluid + scab + throat swab — double-bagged, BSL-4 courier',
            'case_definition' => 'Acute onset of fever ≥38.3°C followed by firm, deep-seated vesicles/pustules in same stage of development, centrifugal distribution (face/extremities > trunk), palms and soles involved, with no other apparent cause.',
            'key_distinguishers' => [
                'Lesions all in the SAME STAGE (synchronous) — unlike mpox or chickenpox',
                'Centrifugal distribution — dense on face and extremities',
                'Palms and soles involved',
                '3–4 day severe prodrome BEFORE rash',
                'No lymphadenopathy (distinguishes from mpox)',
            ],
            'differential' => 'Mpox, chickenpox, disseminated zoster, drug eruption',
        ],

        'sars' => [
            'name' => 'Severe Acute Respiratory Syndrome (SARS)',
            'ihr_tier' => 'TIER_1_ALWAYS_NOTIFIABLE',
            'who_category' => 'IHR Tier 1 — Always Notifiable',
            'cfr_pct' => 10.0,
            'incubation' => '2–10 days (typically 4–6)',
            'transmission' => 'Respiratory droplets + aerosol in close contact, nosocomial super-spreading documented',
            'ppe' => 'Airborne + contact: N95/FFP3, gown, gloves, eye protection; negative-pressure where possible',
            'isolation' => 'Immediate single-room isolation, negative pressure for aerosol-generating procedures',
            'ihr_notification' => 'IMMEDIATE — WHO notification under IHR 2005',
            'immediate_actions' => [
                'IMMEDIATE ISOLATION of case — stop all onward transport',
                'Full PPE for all healthcare contacts',
                'IMMEDIATE WHO IHR notification',
                'Line-list and quarantine all travel/nosocomial contacts × 10 days',
                'Restrict aerosol-generating procedures to controlled environment',
            ],
            'recommended_tests' => [
                'Coronavirus PCR via national reference lab (SARS-CoV-1 or novel CoV panel)',
                'Chest X-ray + CT (bilateral patchy infiltrates typical)',
                'Paired serology (acute + convalescent)',
            ],
            'specimens' => 'Nasopharyngeal + oropharyngeal swab, lower respiratory if available',
            'case_definition' => 'Person with fever ≥38°C AND one or more respiratory symptoms (cough, SOB, difficulty breathing) AND history of travel or close contact with a probable SARS case within 10 days of onset.',
            'key_distinguishers' => [
                'Rapid progression to pneumonia and ARDS',
                'Healthcare cluster / super-spreader association',
                'No rhinitis / coryza typical — distinguishes from common CoV',
                'Lymphopenia, elevated LDH, elevated LFTs at baseline',
            ],
            'differential' => 'Influenza, novel zoonotic influenza, MERS, atypical pneumonia, COVID-19',
        ],

        'influenza_new_subtype_zoonotic' => [
            'name' => 'Zoonotic / Novel Influenza (H5N1, H7N9, etc.)',
            'ihr_tier' => 'TIER_1_ALWAYS_NOTIFIABLE',
            'who_category' => 'IHR Tier 1 — Always Notifiable',
            'cfr_pct' => 30.0,
            'incubation' => '1–10 days (typically 2–8)',
            'transmission' => 'Primary: direct poultry / wild-bird contact. Secondary: limited human-to-human droplet',
            'ppe' => 'Airborne + contact: N95/FFP3, gown, gloves, eye protection',
            'isolation' => 'Single-room isolation; contact + droplet + airborne precautions',
            'ihr_notification' => 'IMMEDIATE — any novel-subtype human case is notifiable',
            'immediate_actions' => [
                'IMMEDIATE ISOLATION',
                'IMMEDIATE WHO IHR notification of a human case of non-seasonal influenza subtype',
                'Initiate empiric oseltamivir without waiting for confirmation',
                'Contact-trace all poultry, live-bird-market, and human contacts',
                'Veterinary/One-Health alert for source investigation',
            ],
            'recommended_tests' => [
                'Influenza A PCR with H5/H7/H9 subtyping at national reference lab',
                'Chest imaging (progression to ARDS common)',
                'Procalcitonin + blood cultures to rule out bacterial co-infection',
            ],
            'specimens' => 'Nasopharyngeal swab + throat swab + lower-respiratory tract specimen',
            'case_definition' => 'Person with fever ≥38°C AND cough or SOB AND exposure to poultry/wild birds/confirmed human case within 10 days OR laboratory-confirmed novel influenza A subtype.',
            'key_distinguishers' => [
                'Poultry / live-bird-market exposure',
                'Unusually severe course for influenza',
                'Rapid progression to pneumonia and multi-organ failure',
                'Cluster with an index case from a different subtype',
            ],
            'differential' => 'Seasonal influenza, SARS, MERS, COVID-19, bacterial pneumonia',
        ],

        'polio' => [
            'name' => 'Poliomyelitis / Acute Flaccid Paralysis',
            'ihr_tier' => 'TIER_1_ALWAYS_NOTIFIABLE',
            'who_category' => 'IHR Tier 1 — Always Notifiable',
            'cfr_pct' => 5.0,
            'incubation' => '3–35 days (typically 7–14)',
            'transmission' => 'Faecal-oral; less commonly respiratory droplet',
            'ppe' => 'Standard + contact precautions; strict hand hygiene',
            'isolation' => 'Stool isolation — contact precautions; GPEI AFP investigation',
            'ihr_notification' => 'Any case of AFP in < 15 y or compatible in ≥15 y → notify WHO within 24h',
            'immediate_actions' => [
                'IMMEDIATE WHO GPEI AFP notification',
                'Collect TWO stool specimens 24–48h apart',
                '60-day follow-up examination for residual paralysis',
                'Launch case investigation + contact sampling within 48h',
                'Check vaccination status — mobilise Mop-up campaign if needed',
            ],
            'recommended_tests' => [
                'Two stool specimens 24–48h apart → WHO-accredited polio lab',
                'Intratypic differentiation of any isolate (WPV vs cVDPV vs Sabin)',
                'Environmental sampling of source community',
            ],
            'specimens' => 'Two stool specimens, 8g each, 24–48h apart, cold chain to accredited lab',
            'case_definition' => 'Any child < 15 y with acute flaccid paralysis, or any person of any age with paralytic illness where polio is suspected.',
            'key_distinguishers' => [
                'Asymmetric flaccid paralysis',
                'NO sensory loss (distinguishes from Guillain-Barré)',
                'Rapid onset over 1–3 days',
                'Preceding fever / prodromal illness',
                'Unvaccinated or incompletely-vaccinated child',
            ],
            'differential' => 'Guillain-Barré syndrome, transverse myelitis, traumatic neuritis, acute flaccid myelitis',
        ],

        // ══════════════════════════════════════════════════════════════════
        //  TIER 2 — IHR ANNEX 2 EPIDEMIC-PRONE (Annex 2 decision instrument)
        // ══════════════════════════════════════════════════════════════════

        'cholera' => [
            'name' => 'Cholera (Vibrio cholerae O1/O139)',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone',
            'cfr_pct' => 1.0,
            'incubation' => '2 hours–5 days (typically 2–3 days)',
            'transmission' => 'Faecal-oral via contaminated water and food',
            'ppe' => 'Standard + contact precautions; strict hand hygiene; chlorinated footbath',
            'isolation' => 'Cohort in cholera treatment centre / ORP with dedicated latrines',
            'ihr_notification' => 'Report under IHR Annex 2 if unexpected or unusual event',
            'immediate_actions' => [
                'Immediate aggressive rehydration — Ringer\'s lactate / ORS',
                'Isolate in CTC/ORP with chlorinated footbath and latrine',
                'Notify DHO + PHEOC within 24h',
                'Collect rectal swab in Cary-Blair medium',
                'WASH + community hygiene + OCV campaign if cluster',
            ],
            'recommended_tests' => [
                'Rapid dipstick (Crystal VC) at bedside',
                'Stool culture on TCBS agar at reference lab',
                'Antimicrobial susceptibility (tetracycline, azithro, ciprofloxacin)',
            ],
            'specimens' => 'Rectal swab or stool in Cary-Blair transport medium',
            'case_definition' => 'In an area where cholera is NOT known to be present: severe dehydration or death from acute watery diarrhoea in a patient ≥5 y. In an area where there IS an outbreak: any person ≥2 y with acute watery diarrhoea.',
            'key_distinguishers' => [
                '"Rice-water" stool — profuse, pale, no blood',
                'Rapid progression to severe dehydration (hours, not days)',
                'Minimal abdominal pain; minimal / no fever',
                'Massive stool volumes — litres per hour possible',
            ],
            'differential' => 'Viral gastroenteritis, ETEC, shigellosis, food poisoning',
        ],

        'yellow_fever' => [
            'name' => 'Yellow fever',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone',
            'cfr_pct' => 30.0,
            'incubation' => '3–6 days',
            'transmission' => 'Aedes aegypti / Haemagogus mosquito bite (sylvatic + urban cycle)',
            'ppe' => 'Standard precautions; vector control at case residence; no person-to-person spread',
            'isolation' => 'Mosquito-proof room × 5 days from fever onset (viraemic period)',
            'ihr_notification' => 'IHR Annex 2 — any suspected case must be reported',
            'immediate_actions' => [
                'Verify yellow fever vaccination certificate (ICVP)',
                'Mosquito-proof patient room × 5 days (viraemia)',
                'Serology + PCR via national reference lab',
                'Vector control sweep at case residence + POE',
                'Assess need for reactive YF vaccination campaign',
            ],
            'recommended_tests' => [
                'YF IgM ELISA + plaque-reduction neutralisation test',
                'YF-specific PCR (serum, first 5 days of symptoms)',
                'Liver function tests (AST > ALT typical)',
            ],
            'specimens' => 'Serum (acute + convalescent), post-mortem liver histopathology',
            'case_definition' => 'Acute fever with jaundice within 14 days of onset in a patient with plausible exposure in a YF-endemic area, with no documented YF vaccination or with IgM seroconversion.',
            'key_distinguishers' => [
                'Biphasic course — remission day 3–4 then "period of intoxication"',
                'Jaundice + hepatic failure + haemorrhage triad',
                'Paradoxical bradycardia (Faget sign)',
                'AST disproportionately higher than ALT',
                'Unvaccinated traveller from endemic zone',
            ],
            'differential' => 'Viral hepatitis, leptospirosis, malaria, other VHF (Ebola, Lassa)',
        ],

        'ebola_virus_disease' => [
            'name' => 'Ebola Virus Disease (EVD)',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone (VHF)',
            'cfr_pct' => 50.0,
            'incubation' => '2–21 days (typically 4–10)',
            'transmission' => 'Direct contact with body fluids of symptomatic case or contaminated fomites; sexual transmission from survivors; bat reservoir',
            'ppe' => 'VHF full PPE: fluid-resistant coverall, double gloves, PAPR or N95+face shield, hood, boots, apron',
            'isolation' => 'Dedicated VHF isolation ward / treatment centre with buddy-system PPE donning/doffing',
            'ihr_notification' => 'IMMEDIATE — any suspected VHF is a public health event of potential international concern',
            'immediate_actions' => [
                'IMMEDIATE VHF isolation — dedicated ETU or holding isolation',
                'Full VHF PPE for ALL contacts (including ambulance transfer)',
                'IMMEDIATE WHO IHR + AFRO notification within 24h',
                'Ring-vaccinate contacts with rVSV-ZEBOV if EBOV confirmed',
                'Line-list contacts × 21 days; safe dignified burial if deceased',
                'Activate national Ebola response plan',
            ],
            'recommended_tests' => [
                // PRESERVED: regional BSL-4 filovirus reference lab (Category B clinical fact).
                'Filovirus RT-PCR via BSL-4 reference lab (UVRI in Uganda)',
                'Rapid antigen test at case holding unit',
                'Malaria smear to rule in/out co-infection',
                'Liver function + coagulation (DIC picture)',
            ],
            'specimens' => 'EDTA whole blood (5 mL), double-bagged, cold chain, dangerous-goods courier',
            'case_definition' => 'Acute fever ≥38°C and ≥3 of: headache, vomiting, anorexia, diarrhoea, lethargy, abdominal pain, muscle/joint pain, difficulty swallowing, difficulty breathing, hiccup, OR unexplained bleeding, OR contact with suspected/confirmed case within 21 days.',
            'key_distinguishers' => [
                'Travel from EVD-affected area within 21 days',
                'Contact with confirmed case, body fluids, or funeral',
                'Haemorrhagic signs in a febrile traveller',
                'Conjunctival injection + retrosternal pain typical early',
                'Rapid progression to shock / multi-organ failure',
            ],
            'differential' => 'Marburg, Lassa, CCHF, severe malaria, typhoid, meningococcaemia, severe dengue',
        ],

        'marburg_virus_disease' => [
            'name' => 'Marburg Virus Disease (MVD)',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone (VHF)',
            'cfr_pct' => 50.0,
            'incubation' => '2–21 days (typically 5–10)',
            'transmission' => 'Cave/mine exposure to Rousettus fruit bats; person-to-person via body fluids',
            'ppe' => 'VHF full PPE (as Ebola)',
            'isolation' => 'Dedicated VHF isolation unit',
            'ihr_notification' => 'IMMEDIATE — notifiable VHF',
            'immediate_actions' => [
                'IMMEDIATE VHF isolation',
                'Full VHF PPE + buddy system for donning/doffing',
                'WHO IHR notification within 24h',
                'Ask about bat / cave / mine exposure in last 21 days',
                'Line-list contacts × 21 days',
                'Safe + dignified burial SOPs',
            ],
            'recommended_tests' => [
                'Filovirus RT-PCR at BSL-4 reference lab',
                'MVD antigen ELISA',
                'Coagulation + LFT panel',
            ],
            'specimens' => 'EDTA whole blood, cold chain, dangerous-goods courier',
            'case_definition' => 'Clinical illness as with Ebola, AND epidemiological link to Marburg-affected area or confirmed case, within 21 days.',
            'key_distinguishers' => [
                'Cave / bat / mine exposure history',
                'Abrupt high fever + severe headache + malaise',
                'Non-pruritic maculopapular rash day 5–7',
                'Haemorrhagic signs day 5–7',
                'No vaccine approved; no specific antiviral',
            ],
            'differential' => 'Ebola, Lassa, CCHF, severe malaria, typhoid',
        ],

        'lassa_fever' => [
            'name' => 'Lassa fever',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone (VHF)',
            'cfr_pct' => 15.0,
            'incubation' => '2–21 days (typically 6–21)',
            'transmission' => 'Contact with Mastomys rodent excreta; person-to-person via body fluids, esp. nosocomial',
            'ppe' => 'VHF PPE; droplet + contact precautions',
            'isolation' => 'Single-room isolation, VHF precautions',
            'ihr_notification' => 'Notifiable under IHR Annex 2',
            'immediate_actions' => [
                'Isolate case under VHF precautions',
                'Empiric IV ribavirin if suspicion high and within 6 days onset',
                'Notify WHO / national PHEOC within 24h',
                'Identify rodent exposure, West-Africa travel',
                'Trace and monitor contacts × 21 days',
            ],
            'recommended_tests' => [
                'Lassa virus RT-PCR at BSL-4 reference lab',
                'Lassa antigen ELISA',
                'Paired serology (IgM/IgG)',
            ],
            'specimens' => 'EDTA blood, throat swab, urine — cold chain, double-bagged',
            'case_definition' => 'Acute fever + malaise + ≥2 of: sore throat, retrosternal pain, conjunctivitis, facial oedema, proteinuria, bleeding, exposure link to known Lassa area.',
            'key_distinguishers' => [
                'West-Africa travel (Nigeria / Sierra Leone / Liberia / Guinea)',
                'Exudative pharyngitis / retrosternal pain',
                'Sensorineural deafness in 25 % (pathognomonic late sign)',
                'Facial and neck oedema in severe cases',
                'Ribavirin-responsive — early treatment critical',
            ],
            'differential' => 'Malaria, typhoid, Ebola, Marburg, bacterial sepsis',
        ],

        'cchf' => [
            'name' => 'Crimean-Congo Haemorrhagic Fever (CCHF)',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone (VHF)',
            'cfr_pct' => 30.0,
            'incubation' => '1–14 days (typically 3–7)',
            'transmission' => 'Hyalomma tick bite; contact with infected livestock blood/tissue; nosocomial via body fluids',
            'ppe' => 'VHF PPE; contact + droplet precautions',
            'isolation' => 'VHF isolation; particular care for nosocomial transmission',
            'ihr_notification' => 'Notifiable under IHR Annex 2',
            'immediate_actions' => [
                'VHF isolation',
                'Empiric ribavirin IV if clinical suspicion high',
                'Identify tick exposure or livestock slaughter exposure',
                'Contact tracing × 14 days',
                'Strict nosocomial infection control',
            ],
            'recommended_tests' => [
                'CCHF RT-PCR at BSL-4 reference lab',
                'CCHF IgM / IgG ELISA (late)',
                'Coagulation panel — DIC picture',
            ],
            'specimens' => 'EDTA blood, cold chain',
            'case_definition' => 'Acute fever + myalgia + bleeding/bruising, with tick bite or livestock slaughter exposure or contact with confirmed case within 14 days.',
            'key_distinguishers' => [
                'Tick bite OR livestock slaughter exposure (abattoir worker, butcher)',
                'Rapid progression to haemorrhage over 3–6 days',
                'Widespread ecchymosis',
                'Healthcare-worker transmission well-documented',
                'Eastern Europe / Middle East / Central Asia / Africa',
            ],
            'differential' => 'Other VHFs, severe malaria, meningococcaemia, leptospirosis',
        ],

        'rift_valley_fever' => [
            'name' => 'Rift Valley fever (RVF)',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone',
            'cfr_pct' => 1.0,
            'incubation' => '2–6 days',
            'transmission' => 'Mosquito bite; contact with blood/tissues of infected livestock; aerosol in abattoir',
            'ppe' => 'Standard + contact; N95 at abattoir / necropsy',
            'isolation' => 'Standard isolation; vector control at residence',
            'ihr_notification' => 'Notifiable under IHR Annex 2 if unusual pattern',
            'immediate_actions' => [
                'Supportive care; no specific antiviral',
                'Assess livestock contact / abattoir work',
                'One-Health coordination with Veterinary Services',
                'Vector control + personal protection advice',
                'Report to PHEOC within 24h',
            ],
            'recommended_tests' => [
                'RVF RT-PCR (acute phase)',
                'RVF IgM ELISA',
                'LFTs, ophthalmology review (retinitis)',
            ],
            'specimens' => 'Serum, whole blood',
            'case_definition' => 'Acute febrile illness with ≥1 of: haemorrhage, encephalitis, retinitis, jaundice, AND exposure to livestock/mosquitoes in RVF-affected area.',
            'key_distinguishers' => [
                'Livestock contact / abattoir exposure',
                'Retinitis 1–3 weeks post-infection',
                'Self-limited in 98 % — 2 % develop VHF / encephalitis / retinitis',
                'Linked to flooding + mosquito surge in East Africa',
            ],
            'differential' => 'Other VHFs, leptospirosis, malaria, brucellosis',
        ],

        'mpox' => [
            'name' => 'Mpox (Monkeypox)',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone (PHEIC 2024)',
            'cfr_pct' => 1.0,
            'incubation' => '1–21 days (typically 6–16)',
            'transmission' => 'Close physical / sexual contact; respiratory droplets in prolonged face-to-face; vertical',
            'ppe' => 'Contact + droplet; gown, gloves, eye protection, surgical mask',
            'isolation' => 'Single-room isolation until all lesions crusted and re-epithelialised',
            'ihr_notification' => 'Notifiable; linked to 2024 PHEIC for clade Ib',
            'immediate_actions' => [
                'Isolate until lesions crusted and re-epithelialised',
                'Assess clade (Ia / Ib / IIb) — clade Ib more severe',
                'Test partners and household contacts',
                'Offer MVA-BN vaccination post-exposure prophylaxis if < 14 days',
                'Sexual-health + safer-sex counselling',
            ],
            'recommended_tests' => [
                'Orthopoxvirus PCR from vesicle swab',
                'MPXV-specific PCR + clade typing',
                'HIV + STI screen',
            ],
            'specimens' => 'Dry swab from vesicle/pustule (2 lesions), skin scrapings',
            'case_definition' => 'Unexplained acute rash AND ≥1 of: headache, fever ≥38.5°C, lymphadenopathy, myalgia, back pain, severe asthenia — with epidemiological link.',
            'key_distinguishers' => [
                'Lymphadenopathy (distinguishes from smallpox)',
                'Lesions at different stages — unlike smallpox',
                'Centrifugal + genital / perianal involvement in clade Ib',
                'Painful lesions; may be localised in sexual transmission',
            ],
            'differential' => 'Smallpox, chickenpox, disseminated zoster, secondary syphilis, herpes simplex',
        ],

        'meningococcal_meningitis' => [
            'name' => 'Meningococcal meningitis (Neisseria meningitidis)',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone (African meningitis belt)',
            'cfr_pct' => 10.0,
            'incubation' => '2–10 days (typically 4)',
            'transmission' => 'Respiratory droplets; close / household contact',
            'ppe' => 'Droplet precautions for first 24h of effective therapy',
            'isolation' => 'Droplet isolation × 24h post first dose of antibiotics',
            'ihr_notification' => 'Notifiable under IHR Annex 2; MenAfriVac campaign trigger',
            'immediate_actions' => [
                'IMMEDIATE empiric IV ceftriaxone 2g',
                'Droplet precautions × 24h',
                'Chemoprophylaxis to household / close contacts (ciprofloxacin / rifampicin / ceftriaxone)',
                'Notify DHO + PHEOC; alert threshold 10 / 100,000 / week',
                'Vaccine campaign if above epidemic threshold',
            ],
            'recommended_tests' => [
                'LP with CSF Gram stain + culture + latex agglutination + PCR',
                'Blood cultures × 2',
                'Serogroup identification (A / C / W / X / Y)',
            ],
            'specimens' => 'CSF + blood culture',
            'case_definition' => 'Acute onset of fever ≥38.5°C with stiff neck AND ≥1 of: altered consciousness, purpuric/petechial rash, bulging fontanelle (infant).',
            'key_distinguishers' => [
                'Purpuric / petechial rash that does not blanch',
                'Rapid progression — hours, not days',
                'Kernig / Brudzinski / stiff neck',
                'African meningitis belt during dry season',
                'Waterhouse-Friderichsen if fulminant',
            ],
            'differential' => 'Pneumococcal meningitis, Hib meningitis, viral meningitis, cerebral malaria',
        ],

        'measles' => [
            'name' => 'Measles',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone',
            'cfr_pct' => 1.0,
            'incubation' => '7–23 days (typically 10–14)',
            'transmission' => 'Airborne — most infectious human virus known (R0 12–18)',
            'ppe' => 'Airborne precautions — N95, negative-pressure room',
            'isolation' => 'Airborne isolation × 4 days after rash onset',
            'ihr_notification' => 'Notifiable; measles elimination programme',
            'immediate_actions' => [
                'Airborne isolation × 4 days post-rash',
                'Vitamin A × 2 doses',
                'Check vaccination status of all exposed',
                'Post-exposure MMR within 72h OR immunoglobulin within 6 days for unvaccinated contacts',
                'Line-list and trace contacts',
            ],
            'recommended_tests' => [
                'Measles IgM ELISA',
                'Measles RT-PCR (throat swab, urine)',
                'Genotyping at WHO reference lab',
            ],
            'specimens' => 'Blood, throat swab, urine',
            'case_definition' => 'Fever + generalised maculopapular rash AND ≥1 of: cough, coryza, conjunctivitis. Lab-confirmed by IgM or PCR.',
            'key_distinguishers' => [
                '3 Cs prodrome: cough, coryza, conjunctivitis',
                'Koplik spots — pathognomonic, 1–2 days before rash',
                'Rash starts on face → spreads downward',
                'Unvaccinated / sub-optimally-vaccinated population',
            ],
            'differential' => 'Rubella, dengue, scarlet fever, drug eruption, parvovirus B19',
        ],

        'rubella' => [
            'name' => 'Rubella (German measles)',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone',
            'cfr_pct' => 0.1,
            'incubation' => '12–23 days (typically 14)',
            'transmission' => 'Respiratory droplets; vertical (congenital rubella syndrome risk)',
            'ppe' => 'Droplet + standard; avoid pregnant contacts',
            'isolation' => 'Droplet × 7 days post-rash; avoid pregnant contacts',
            'ihr_notification' => 'CRS elimination surveillance',
            'immediate_actions' => [
                'Isolate × 7 days post-rash; screen pregnant contacts',
                'If pregnant contact — rubella IgG/IgM + ID specialist urgently',
                'MMR vaccination gaps audit in area',
            ],
            'recommended_tests' => [
                'Rubella IgM ELISA',
                'Rubella PCR on throat swab',
            ],
            'specimens' => 'Throat swab + serum',
            'case_definition' => 'Acute fever + generalised maculopapular rash + ≥1 of: arthralgia, lymphadenopathy, conjunctivitis.',
            'key_distinguishers' => [
                'Posterior cervical / post-auricular lymphadenopathy',
                'Rash faster-moving than measles, less prodrome',
                'Polyarthralgia in adult women',
                'Pregnancy exposure → congenital rubella syndrome',
            ],
            'differential' => 'Measles, dengue, parvovirus B19, enterovirus',
        ],

        'pneumonic_plague' => [
            'name' => 'Pneumonic plague',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone (CRITICAL)',
            'cfr_pct' => 50.0,
            'incubation' => '1–4 days',
            'transmission' => 'Respiratory droplets; rodent-flea bite for bubonic form',
            'ppe' => 'Droplet precautions minimum; N95 if AGP — full PPE',
            'isolation' => 'Strict droplet isolation until 48h of effective therapy',
            'ihr_notification' => 'IMMEDIATE — any suspected plague case',
            'immediate_actions' => [
                'IMMEDIATE droplet isolation',
                'Empiric streptomycin OR gentamicin OR doxycycline',
                'Chemoprophylaxis for all exposed contacts × 7 days (doxycycline)',
                'Notify PHEOC + WHO within 24h',
                'Rodent + flea control at exposure site',
            ],
            'recommended_tests' => [
                'Y. pestis F1 antigen rapid test',
                'Sputum / blood culture',
                'PCR at reference lab',
            ],
            'specimens' => 'Sputum + blood culture + bubo aspirate (if bubonic)',
            'case_definition' => 'Acute fever + cough + haemoptysis + rapid-progression pneumonia with epidemiological link to plague focus or rodent exposure.',
            'key_distinguishers' => [
                'Haemoptysis in febrile patient',
                'Very rapid progression — death within 24–48h untreated',
                'Rodent / flea exposure OR known plague focus',
                '100 % fatal if untreated — antibiotic window is narrow',
            ],
            'differential' => 'Anthrax pulmonary, pneumococcal sepsis, tularaemia, Legionella',
        ],

        'bubonic_plague' => [
            'name' => 'Bubonic plague',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone',
            'cfr_pct' => 10.0,
            'incubation' => '2–6 days',
            'transmission' => 'Rodent flea bite (Xenopsylla); cat/dog bite',
            'ppe' => 'Standard + contact; droplet if pulmonary involvement develops',
            'isolation' => 'Contact precautions; droplet if secondary pneumonic',
            'ihr_notification' => 'IMMEDIATE',
            'immediate_actions' => [
                'Empiric streptomycin / gentamicin / doxycycline',
                'Aspirate bubo for diagnosis',
                'Rodent + flea control at home',
                'Chemoprophylaxis for exposed contacts',
            ],
            'recommended_tests' => [
                'Bubo aspirate Gram stain + culture',
                'Y. pestis F1 antigen test',
                'Blood culture',
            ],
            'specimens' => 'Bubo aspirate, blood culture',
            'case_definition' => 'Acute fever + painful lymphadenopathy (bubo), usually inguinal, with plague-focus exposure.',
            'key_distinguishers' => [
                'Painful bubo — inguinal > axillary > cervical',
                'Flea bite exposure or plague focus',
                'Can progress to pneumonic or septicaemic plague',
            ],
            'differential' => 'Tularaemia, cat-scratch, TB adenitis, bacterial lymphadenitis',
        ],

        'nipah_virus' => [
            'name' => 'Nipah Virus Disease',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone',
            'cfr_pct' => 70.0,
            'incubation' => '4–14 days',
            'transmission' => 'Fruit bat contact; date palm sap; person-to-person via body fluids; nosocomial',
            'ppe' => 'Contact + droplet; N95 in AGP; full PPE for suspected nosocomial',
            'isolation' => 'Single-room isolation',
            'ihr_notification' => 'IMMEDIATE notifiable',
            'immediate_actions' => [
                'Strict isolation; no specific antiviral',
                'Supportive ICU care',
                'Bat contact / date palm sap history',
                'Nosocomial contact tracing × 21 days',
                'Notify WHO',
            ],
            'recommended_tests' => [
                'Nipah PCR at BSL-4 reference lab',
                'IgM / IgG ELISA',
            ],
            'specimens' => 'Serum, throat swab, CSF',
            'case_definition' => 'Acute fever with encephalitis / respiratory distress AND epidemiological link (bat, pig, case, date palm sap) in South/SE Asia.',
            'key_distinguishers' => [
                'Encephalitis + rapidly declining consciousness',
                'Fruit bat contact / date palm sap (Bangladesh, India)',
                'Nosocomial transmission documented',
                '70 % CFR — no specific treatment',
            ],
            'differential' => 'Japanese encephalitis, HSV encephalitis, cerebral malaria',
        ],

        'hantavirus' => [
            'name' => 'Hantavirus (HPS / HFRS)',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone',
            'cfr_pct' => 20.0,
            'incubation' => 'Typically 9–35 days',
            'transmission' => 'Rodent aerosolised excreta inhalation; no person-to-person (except Andes virus)',
            'ppe' => 'N95 for rodent environment decontamination',
            'isolation' => 'Standard precautions (Andes virus — droplet)',
            'ihr_notification' => 'Notifiable if unusual',
            'immediate_actions' => [
                'Supportive ICU care — mechanical ventilation for HPS',
                'Rodent exposure + geography history',
                'Ribavirin for HFRS early',
            ],
            'recommended_tests' => [
                'Hantavirus IgM / IgG ELISA',
                'RT-PCR',
            ],
            'specimens' => 'Serum',
            'case_definition' => 'Acute fever + cardiopulmonary or renal syndrome with rodent exposure.',
            'key_distinguishers' => [
                'Rodent exposure (Americas — HPS; Asia/Europe — HFRS)',
                'Rapid non-cardiogenic pulmonary oedema',
                'Thrombocytopenia + haemoconcentration',
            ],
            'differential' => 'Leptospirosis, Q fever, influenza, Legionella',
        ],

        'mers' => [
            'name' => 'MERS-CoV (Middle East Respiratory Syndrome)',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone',
            'cfr_pct' => 35.0,
            'incubation' => '2–14 days',
            'transmission' => 'Dromedary camel contact; respiratory droplets (limited human-human); nosocomial super-spreading',
            'ppe' => 'Airborne + contact: N95/FFP3, gown, gloves, eye protection',
            'isolation' => 'Negative-pressure isolation where possible',
            'ihr_notification' => 'Notifiable under IHR',
            'immediate_actions' => [
                'Strict airborne + contact isolation',
                'Camel / Arabian Peninsula travel history',
                'Notify WHO within 24h',
                'Contact tracing × 14 days (including flight)',
                'Infection control audit at referring facility',
            ],
            'recommended_tests' => [
                'MERS-CoV RT-PCR (upstream E + ORF1a) at reference lab',
                'Serology — acute + convalescent',
                'Chest imaging',
            ],
            'specimens' => 'Lower-respiratory tract preferred; NP + OP swab',
            'case_definition' => 'Acute fever + cough + radiographically-proven pneumonia AND travel to / resident in Arabian Peninsula within 14 days OR camel contact OR contact with confirmed case.',
            'key_distinguishers' => [
                'Arabian Peninsula travel OR camel exposure in 14 days',
                'Progression to ARDS + renal failure',
                'Healthcare cluster typical',
                'Diarrhoea in 25 % early in course',
            ],
            'differential' => 'Influenza, SARS, novel CoV, COVID-19, bacterial pneumonia',
        ],

        'dengue' => [
            'name' => 'Dengue',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone',
            'cfr_pct' => 0.5,
            'incubation' => '4–10 days',
            'transmission' => 'Aedes aegypti / albopictus mosquito bite',
            'ppe' => 'Standard precautions; vector control at residence',
            'isolation' => 'Standard (mosquito-proof during viraemia)',
            'ihr_notification' => 'Notifiable if unusual / cluster',
            'immediate_actions' => [
                'Supportive care — crystalloid fluid resuscitation by warning-sign stage',
                'Vector control at residence / POE',
                'Monitor platelets + haematocrit',
                'Identify travel to Aedes-endemic area',
            ],
            'recommended_tests' => [
                'NS1 antigen day 1–7',
                'Dengue IgM day 5+',
                'Dengue PCR day 1–7',
                'FBC + LFT',
            ],
            'specimens' => 'Serum',
            'case_definition' => 'Acute fever + ≥2 of: headache, retro-orbital pain, myalgia, arthralgia, rash, haemorrhagic manifestations, leucopenia, WITH residence in / travel to Aedes area.',
            'key_distinguishers' => [
                'Retro-orbital pain',
                '"Break-bone" myalgia',
                'Biphasic fever, rash on defervescence',
                'Warning signs day 3–6 — herald severe dengue',
            ],
            'differential' => 'Chikungunya, Zika, malaria, rickettsiosis, measles',
        ],

        'dengue_severe' => [
            'name' => 'Severe Dengue (DHF / DSS)',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'IHR Annex 2 — Epidemic-Prone (CRITICAL)',
            'cfr_pct' => 20.0,
            'incubation' => '4–10 days',
            'transmission' => 'As dengue',
            'ppe' => 'Standard',
            'isolation' => 'ICU capacity required',
            'ihr_notification' => 'Notifiable',
            'immediate_actions' => [
                'AGGRESSIVE fluid resuscitation per WHO DSS protocol',
                'ICU admission; monitor haematocrit + platelets q4–6h',
                'AVOID aspirin / NSAIDs',
                'Packed cells / FFP as needed for bleeding',
            ],
            'recommended_tests' => [
                'NS1 + IgM + PCR',
                'Serial HCT + platelets',
                'LFT',
            ],
            'specimens' => 'Serum',
            'case_definition' => 'Dengue with ≥1 of: severe plasma leakage (shock, fluid overload), severe bleeding, severe organ impairment.',
            'key_distinguishers' => [
                'Plasma leakage → narrow pulse pressure, cold extremities',
                'Ascites / pleural effusion on imaging',
                'Severe thrombocytopenia < 20k',
                'Deterioration at defervescence (day 3–6) — critical phase',
            ],
            'differential' => 'Severe malaria, septic shock, VHF',
        ],

        'malaria_uncomplicated' => [
            'name' => 'Malaria (Uncomplicated)',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'Endemic — travel-medicine priority',
            'cfr_pct' => 0.5,
            'incubation' => '7–30 days (falciparum shorter)',
            'transmission' => 'Anopheles mosquito bite',
            'ppe' => 'Standard',
            'isolation' => 'None (mosquito-proof during viraemia)',
            'ihr_notification' => 'Routine national notification',
            'immediate_actions' => [
                'First-line ACT per national guidelines',
                'Rule out severe malaria (any danger sign)',
                'Identify travel to endemic area',
                'Check chemoprophylaxis adherence',
            ],
            'recommended_tests' => [
                'RDT (HRP-2 for P. falciparum)',
                'Thick + thin blood film × 3 (if RDT negative and suspicion high)',
                'FBC + glucose + LFT',
            ],
            'specimens' => 'EDTA blood',
            'case_definition' => 'Acute febrile illness with parasitological confirmation by microscopy or RDT.',
            'key_distinguishers' => [
                'Travel to endemic area (or endemic residence)',
                'Cyclical fever — tertian/quartan pattern if typical',
                'Splenomegaly',
                'Thrombocytopenia + mildly elevated LFTs typical',
            ],
            'differential' => 'Typhoid, dengue, leptospirosis, VHF, influenza',
        ],

        'malaria_severe' => [
            'name' => 'Severe Malaria (P. falciparum)',
            'ihr_tier' => 'ANNEX2_EPIDEMIC_PRONE',
            'who_category' => 'Endemic — CRITICAL',
            'cfr_pct' => 20.0,
            'incubation' => '7–30 days',
            'transmission' => 'Anopheles bite',
            'ppe' => 'Standard',
            'isolation' => 'ICU-level monitoring',
            'ihr_notification' => 'Routine national; any cluster — PHEOC alert',
            'immediate_actions' => [
                'IMMEDIATE IV artesunate 2.4 mg/kg at 0, 12, 24h',
                'ICU admission; strict fluid balance',
                'Treat hypoglycaemia, lactic acidosis, AKI, cerebral oedema',
                'Exchange transfusion for parasitaemia > 10 %',
            ],
            'recommended_tests' => [
                'Thick + thin film with parasite density quantification',
                'RDT',
                'Lactate, glucose, ABG, creatinine, haemoglobin',
                'LP if altered consciousness (exclude meningitis)',
            ],
            'specimens' => 'EDTA blood',
            'case_definition' => 'Parasitologically-confirmed malaria with ≥1 of WHO severe criteria: impaired consciousness, prostration, multiple convulsions, acidosis, hypoglycaemia, severe anaemia, renal impairment, jaundice, pulmonary oedema, bleeding, shock, hyperparasitaemia.',
            'key_distinguishers' => [
                'Coma / GCS reduction (cerebral malaria)',
                'Respiratory distress from acidosis',
                'Hypoglycaemia < 2.2 mmol/L',
                'Haemoglobinuria (blackwater fever)',
                'Parasitaemia > 10 %',
            ],
            'differential' => 'Septic shock, VHF, meningitis, encephalitis',
        ],

        // ══════════════════════════════════════════════════════════════════
        //  TIER 3 — WHO Notifiable (regional public-health priority)
        // ══════════════════════════════════════════════════════════════════

        'typhoid_fever' => [
            'name' => 'Typhoid fever (Enteric fever)',
            'ihr_tier' => 'WHO_NOTIFIABLE',
            'who_category' => 'WHO Priority Pathogen',
            'cfr_pct' => 1.0,
            'incubation' => '6–30 days (typically 8–14)',
            'transmission' => 'Faecal-oral; contaminated food/water',
            'ppe' => 'Standard + contact',
            'isolation' => 'Contact precautions; food-handler exclusion',
            'ihr_notification' => 'Routine national',
            'immediate_actions' => [
                'Empiric ceftriaxone or azithromycin',
                'Stool culture + food-handler screening',
                'WASH intervention; trace common food/water source',
            ],
            'recommended_tests' => [
                'Blood culture (yield 60–80 % week 1)',
                'Stool culture (week 2+)',
                'Widal (limited utility)',
            ],
            'specimens' => 'Blood × 2, stool, urine',
            'case_definition' => 'Progressive fever with ≥1 of: headache, abdominal pain, relative bradycardia, hepatosplenomegaly, rose spots, constipation or diarrhoea.',
            'key_distinguishers' => [
                'Step-ladder fever over 5–7 days',
                'Relative bradycardia (Faget sign)',
                'Rose spots (30 %)',
                'Hepatosplenomegaly + tender RUQ',
            ],
            'differential' => 'Malaria, dengue, brucellosis, VHF, amoebic abscess',
        ],

        'hepatitis_a' => [
            'name' => 'Hepatitis A',
            'ihr_tier' => 'WHO_NOTIFIABLE',
            'who_category' => 'WHO Priority',
            'cfr_pct' => 0.3,
            'incubation' => '15–50 days (typically 28)',
            'transmission' => 'Faecal-oral; contaminated food/water; MSM',
            'ppe' => 'Standard + contact',
            'isolation' => 'Contact precautions × 7 days post-jaundice',
            'ihr_notification' => 'Routine national',
            'immediate_actions' => [
                'Supportive care; no specific antiviral',
                'Household + food-handler screening',
                'Post-exposure vaccination < 14 days',
                'Identify common source',
            ],
            'recommended_tests' => [
                'HAV IgM ELISA',
                'LFTs',
            ],
            'specimens' => 'Serum',
            'case_definition' => 'Acute onset fever + jaundice + elevated ALT/AST with HAV IgM positive.',
            'key_distinguishers' => [
                'Short incubation vs HEV',
                'Common-source outbreak pattern',
                'No chronic carriage',
            ],
            'differential' => 'Hepatitis B/E, yellow fever, leptospirosis, drug-induced hepatitis',
        ],

        'hepatitis_e' => [
            'name' => 'Hepatitis E',
            'ihr_tier' => 'WHO_NOTIFIABLE',
            'who_category' => 'WHO Priority (high CFR in pregnancy)',
            'cfr_pct' => 4.0,
            'incubation' => '15–60 days',
            'transmission' => 'Faecal-oral; contaminated water; zoonotic from swine',
            'ppe' => 'Standard + contact',
            'isolation' => 'Contact precautions',
            'ihr_notification' => 'Notifiable, esp. pregnancy-related',
            'immediate_actions' => [
                'Supportive care; ICU if pregnant (mortality 20 %)',
                'Water-source investigation',
                'WASH response',
            ],
            'recommended_tests' => [
                'HEV IgM ELISA',
                'HEV RNA PCR',
                'LFT',
            ],
            'specimens' => 'Serum, stool',
            'case_definition' => 'Acute fever + jaundice with HEV IgM positive.',
            'key_distinguishers' => [
                'High CFR (20 %) in 3rd-trimester pregnancy',
                'Outbreak-prone in refugee / IDP settings',
                'Water-source outbreak pattern',
            ],
            'differential' => 'Hepatitis A/B/C, yellow fever, leptospirosis',
        ],

        'rabies' => [
            'name' => 'Rabies',
            'ihr_tier' => 'WHO_NOTIFIABLE',
            'who_category' => 'WHO Priority — 100 % fatal once symptomatic',
            'cfr_pct' => 100.0,
            'incubation' => '20 days – 3 months (can be years)',
            'transmission' => 'Bite / scratch from rabid animal (dog 99 %); rarely aerosol / transplant',
            'ppe' => 'Standard + contact; body-fluid precautions',
            'isolation' => 'Standard; supportive palliative care',
            'ihr_notification' => 'Routine; WHO ZERO-BY-30 initiative',
            'immediate_actions' => [
                'PEP (wound care + HRIG + 4-dose vaccine schedule) for any Category II/III exposure',
                'Notify DHO + veterinary services',
                'Trace biting animal — 10-day observation if healthy',
                'Palliative care for symptomatic cases',
            ],
            'recommended_tests' => [
                'Skin biopsy (nuchal) DFA + RT-PCR',
                'Saliva RT-PCR',
                'Serum + CSF serology (post-onset)',
            ],
            'specimens' => 'Nuchal skin, saliva, CSF',
            'case_definition' => 'Acute encephalomyelitis with hydrophobia / aerophobia / progressive ascending paralysis and animal-bite history.',
            'key_distinguishers' => [
                'Hydrophobia — pathognomonic',
                'Aerophobia',
                'Autonomic instability',
                'Animal bite history (often weeks-months prior)',
            ],
            'differential' => 'Tetanus, Guillain-Barré, encephalitis, psychogenic',
        ],

        'anthrax_cutaneous' => [
            'name' => 'Cutaneous anthrax',
            'ihr_tier' => 'WHO_NOTIFIABLE',
            'who_category' => 'Zoonotic bioterrorism-adjacent pathogen',
            'cfr_pct' => 20.0,
            'incubation' => '1–12 days',
            'transmission' => 'Skin contact with infected animal hide, wool, meat; bioterrorism',
            'ppe' => 'Contact; standard',
            'isolation' => 'Contact precautions until crusted',
            'ihr_notification' => 'Notifiable',
            'immediate_actions' => [
                'Ciprofloxacin or doxycycline × 60 days',
                'Do NOT debride lesion',
                'One-Health investigation of animal source',
            ],
            'recommended_tests' => [
                'Gram stain + culture of vesicle fluid / eschar',
                'PCR at reference lab',
            ],
            'specimens' => 'Swab of vesicle + eschar edge',
            'case_definition' => 'Painless ulcer with black eschar + oedema, with occupational or animal exposure.',
            'key_distinguishers' => [
                'Painless black eschar (key!)',
                'Marked surrounding oedema',
                'Farmer / butcher / leather-worker',
                'Potential bioterrorism if unusual cluster',
            ],
            'differential' => 'Spider bite, ecthyma gangrenosum, tularaemia, scrub typhus eschar',
        ],

        'anthrax_pulmonary' => [
            'name' => 'Pulmonary / Inhalation anthrax',
            'ihr_tier' => 'WHO_NOTIFIABLE',
            'who_category' => 'Zoonotic / Bioterrorism — CRITICAL',
            'cfr_pct' => 90.0,
            'incubation' => '1–7 days',
            'transmission' => 'Inhalation of anthrax spores',
            'ppe' => 'Standard + contact (no person-to-person); full PPE if bioterrorism suspected',
            'isolation' => 'Standard',
            'ihr_notification' => 'IMMEDIATE notifiable',
            'immediate_actions' => [
                'IMMEDIATE combination IV ciprofloxacin + meropenem + linezolid',
                'Notify PHEOC + security services (bioterrorism)',
                'Anthrax immune globulin / antitoxin',
                'ICU admission',
            ],
            'recommended_tests' => [
                'Chest CT — widened mediastinum',
                'Blood culture',
                'Pleural fluid culture + PCR',
            ],
            'specimens' => 'Blood culture, pleural fluid',
            'case_definition' => 'Acute fever + non-specific prodrome followed by fulminant respiratory failure with widened mediastinum on imaging + spore exposure.',
            'key_distinguishers' => [
                'Widened mediastinum on CXR (haemorrhagic mediastinitis)',
                'Biphasic course — prodrome → fulminant collapse',
                'Haemorrhagic pleural effusion',
                'Consider bioterrorism if cluster',
            ],
            'differential' => 'Plague, tularaemia, bacterial mediastinitis, aortic dissection',
        ],

        'tularemia' => [
            'name' => 'Tularaemia (Rabbit fever)',
            'ihr_tier' => 'WHO_NOTIFIABLE',
            'who_category' => 'Zoonotic / Bioterrorism',
            'cfr_pct' => 5.0,
            'incubation' => '1–14 days (typically 3–5)',
            'transmission' => 'Tick / deerfly bite, rabbit handling, water contamination',
            'ppe' => 'Standard + BSL-3 in lab',
            'isolation' => 'Standard',
            'ihr_notification' => 'Notifiable',
            'immediate_actions' => [
                'Streptomycin or gentamicin × 10 days',
                'Lab: alert technicians — BSL-3 required',
                'Source investigation',
            ],
            'recommended_tests' => [
                'Francisella tularensis serology',
                'PCR',
                'Culture (BSL-3)',
            ],
            'specimens' => 'Serum, swab of ulcer',
            'case_definition' => 'Ulceroglandular syndrome (ulcer + regional adenopathy) or typhoidal syndrome with tularaemia exposure.',
            'key_distinguishers' => [
                'Ulcer + regional lymphadenopathy (ulceroglandular)',
                'Rabbit / hare / tick exposure',
                'Bioterrorism potential',
            ],
            'differential' => 'Plague, cat-scratch, anthrax cutaneous',
        ],

        'rickettsia_scrub_typhus' => [
            'name' => 'Rickettsiosis / Scrub typhus / Spotted fever',
            'ihr_tier' => 'WHO_NOTIFIABLE',
            'who_category' => 'Vector-borne travel priority',
            'cfr_pct' => 7.0,
            'incubation' => '6–18 days',
            'transmission' => 'Tick, mite, flea, louse bite depending on species',
            'ppe' => 'Standard',
            'isolation' => 'Standard',
            'ihr_notification' => 'Routine',
            'immediate_actions' => [
                'Doxycycline — empirically if suspected',
                'Vector exposure history (rural trekking, safari, forestry)',
            ],
            'recommended_tests' => [
                'Rickettsia IgM / IgG (indirect IFA)',
                'Weil-Felix (limited)',
            ],
            'specimens' => 'Serum; eschar swab if present',
            'case_definition' => 'Acute fever + rash + headache with characteristic eschar or vector exposure.',
            'key_distinguishers' => [
                'Eschar at bite site (pathognomonic)',
                'Maculopapular rash starting wrists/ankles',
                'Rural / trekking exposure',
            ],
            'differential' => 'Dengue, leptospirosis, typhoid, measles',
        ],

        'brucellosis' => [
            'name' => 'Brucellosis',
            'ihr_tier' => 'WHO_NOTIFIABLE',
            'who_category' => 'Zoonotic',
            'cfr_pct' => 2.0,
            'incubation' => '5–60 days',
            'transmission' => 'Unpasteurised dairy; contact with abortion products; aerosol in labs',
            'ppe' => 'Standard; BSL-3 in lab',
            'isolation' => 'Standard',
            'ihr_notification' => 'Routine',
            'immediate_actions' => [
                'Doxycycline + rifampicin × 6 weeks',
                'One-Health investigation',
                'Identify unpasteurised dairy source',
            ],
            'recommended_tests' => [
                'Brucella serology (Rose Bengal, SAT, Coombs)',
                'Blood culture (prolonged; alert lab)',
                'PCR',
            ],
            'specimens' => 'Serum + blood culture',
            'case_definition' => 'Undulant fever + myalgia + sweats with unpasteurised dairy or livestock exposure.',
            'key_distinguishers' => [
                'Undulant fever',
                'Profuse sweats',
                'Unpasteurised dairy / herdsman exposure',
                'Sacroiliitis / orchitis / spondylitis if chronic',
            ],
            'differential' => 'Typhoid, TB, lymphoma, VHF',
        ],

        'leptospirosis' => [
            'name' => 'Leptospirosis',
            'ihr_tier' => 'WHO_NOTIFIABLE',
            'who_category' => 'Zoonotic outbreak-prone',
            'cfr_pct' => 10.0,
            'incubation' => '2–30 days (typically 5–14)',
            'transmission' => 'Water / mud contaminated by rodent urine; occupational exposure',
            'ppe' => 'Standard + contact',
            'isolation' => 'Standard',
            'ihr_notification' => 'Notifiable if cluster',
            'immediate_actions' => [
                'Empiric doxycycline / ceftriaxone',
                'ICU if Weil disease (jaundice + AKI + bleeding)',
                'Flood / rodent exposure + occupation history',
            ],
            'recommended_tests' => [
                'MAT (reference lab)',
                'IgM ELISA',
                'Blood / urine PCR',
            ],
            'specimens' => 'Blood, urine, serum',
            'case_definition' => 'Acute fever + myalgia + conjunctival suffusion with flood water, rodent or sewage exposure.',
            'key_distinguishers' => [
                'Conjunctival suffusion (red eye without discharge)',
                'Severe calf myalgia',
                'Weil disease — jaundice + AKI + bleeding',
                'Flood / rice paddy / sewage exposure',
            ],
            'differential' => 'Dengue, yellow fever, hepatitis, malaria',
        ],

        'japanese_encephalitis' => [
            'name' => 'Japanese Encephalitis (JE)',
            'ihr_tier' => 'WHO_NOTIFIABLE',
            'who_category' => 'Vaccine-preventable encephalitis',
            'cfr_pct' => 30.0,
            'incubation' => '5–15 days',
            'transmission' => 'Culex mosquito bite; pig/bird amplification',
            'ppe' => 'Standard',
            'isolation' => 'Standard',
            'ihr_notification' => 'Notifiable',
            'immediate_actions' => [
                'Supportive ICU care',
                'Vaccine-preventable — audit coverage',
                'Vector control',
            ],
            'recommended_tests' => [
                'JEV IgM in CSF + serum',
                'MRI — thalamus / basal ganglia',
            ],
            'specimens' => 'CSF + serum',
            'case_definition' => 'Acute encephalitic syndrome with CSF / serum JEV IgM in endemic area.',
            'key_distinguishers' => [
                'Encephalitis with parkinsonian features',
                'Rice-paddy / pig-farming exposure',
                'S/SE Asia geography',
                '30–50 % severe neurological sequelae',
            ],
            'differential' => 'HSV encephalitis, Nipah, cerebral malaria, bacterial meningitis',
        ],

        'shigellosis_dysentery' => [
            'name' => 'Shigellosis (Bacillary dysentery)',
            'ihr_tier' => 'WHO_NOTIFIABLE',
            'who_category' => 'WHO Priority',
            'cfr_pct' => 1.0,
            'incubation' => '1–3 days',
            'transmission' => 'Faecal-oral; highly infectious (very low inoculum)',
            'ppe' => 'Contact precautions',
            'isolation' => 'Contact precautions; food-handler exclusion',
            'ihr_notification' => 'Notifiable if cluster',
            'immediate_actions' => [
                'Ciprofloxacin or azithromycin (monitor resistance)',
                'Aggressive rehydration',
                'WASH response',
            ],
            'recommended_tests' => [
                'Stool culture + sensitivity',
                'PCR',
            ],
            'specimens' => 'Stool in Cary-Blair',
            'case_definition' => 'Acute bloody diarrhoea + fever + abdominal cramps, lab-confirmed Shigella.',
            'key_distinguishers' => [
                'Bloody diarrhoea + tenesmus',
                'Highly infectious — small inoculum',
                'HUS in children (especially S. dysenteriae type 1)',
            ],
            'differential' => 'EHEC / O157, amoebiasis, cholera, salmonellosis',
        ],

        // ══════════════════════════════════════════════════════════════════
        //  TIER 4 — SYNDROMIC (differential-grade travel-medicine)
        // ══════════════════════════════════════════════════════════════════

        'awd_non_cholera' => [
            'name' => 'Acute Watery Diarrhoea (Non-Cholera)',
            'ihr_tier' => 'SYNDROMIC',
            'who_category' => 'Syndromic',
            'cfr_pct' => 0.1,
            'incubation' => 'Hours–5 days',
            'transmission' => 'Faecal-oral; multiple pathogens (rota, noro, ETEC, etc.)',
            'ppe' => 'Contact precautions',
            'isolation' => 'Contact precautions',
            'ihr_notification' => 'Routine; cluster = PHEOC alert',
            'immediate_actions' => [
                'ORS + zinc (children)',
                'Rule out cholera if severe / cluster',
                'WASH hygiene counselling',
            ],
            'recommended_tests' => [
                'Stool microscopy + culture if cluster',
                'Rotavirus / norovirus PCR',
            ],
            'specimens' => 'Stool',
            'case_definition' => '≥3 loose stools in 24 h without visible blood.',
            'key_distinguishers' => [
                'No blood — distinguishes from dysentery',
                'Usually self-limited',
                'Watch for cluster = cholera rule-out',
            ],
            'differential' => 'Cholera, viral gastroenteritis, ETEC, parasitic',
        ],

        'influenza_seasonal' => [
            'name' => 'Seasonal influenza',
            'ihr_tier' => 'SYNDROMIC',
            'who_category' => 'Syndromic — annual surveillance',
            'cfr_pct' => 0.1,
            'incubation' => '1–4 days',
            'transmission' => 'Respiratory droplets',
            'ppe' => 'Droplet + standard',
            'isolation' => 'Droplet × 7 days of symptoms',
            'ihr_notification' => 'Not routinely notifiable unless novel subtype',
            'immediate_actions' => [
                'Oseltamivir if at-risk OR severe, within 48h',
                'Droplet precautions',
                'Test for novel subtype if unusual severity',
            ],
            'recommended_tests' => [
                'Rapid influenza diagnostic test',
                'RT-PCR for subtype if severe',
            ],
            'specimens' => 'NP / OP swab',
            'case_definition' => 'Acute onset fever + cough in influenza season.',
            'key_distinguishers' => [
                'Abrupt onset — hours',
                'Systemic features dominate (myalgia, headache)',
                'Seasonal pattern',
            ],
            'differential' => 'COVID-19, RSV, zoonotic influenza, bacterial pneumonia',
        ],

        'chikungunya' => [
            'name' => 'Chikungunya',
            'ihr_tier' => 'SYNDROMIC',
            'who_category' => 'IHR-adjacent outbreak-prone arbovirus',
            'cfr_pct' => 0.1,
            'incubation' => '3–7 days',
            'transmission' => 'Aedes aegypti / albopictus',
            'ppe' => 'Standard',
            'isolation' => 'Mosquito-proof during viraemia',
            'ihr_notification' => 'Notifiable if cluster',
            'immediate_actions' => [
                'Supportive care; NSAID for arthralgia (avoid aspirin until dengue excluded)',
                'Vector control',
            ],
            'recommended_tests' => [
                'CHIKV PCR (day 1–7)',
                'CHIKV IgM (day 5+)',
            ],
            'specimens' => 'Serum',
            'case_definition' => 'Acute fever + severe polyarthralgia + recent Aedes-area exposure.',
            'key_distinguishers' => [
                'Disabling symmetric polyarthralgia',
                'Maculopapular rash day 3–5',
                'May persist months',
            ],
            'differential' => 'Dengue, Zika, rheumatic fever, parvovirus',
        ],

        'zika' => [
            'name' => 'Zika virus disease',
            'ihr_tier' => 'SYNDROMIC',
            'who_category' => 'Outbreak-prone arbovirus (PHEIC 2016)',
            'cfr_pct' => 0.1,
            'incubation' => '3–14 days',
            'transmission' => 'Aedes mosquito; sexual; vertical',
            'ppe' => 'Standard + sexual precautions',
            'isolation' => 'Standard; no sexual contact for 3 months (male) if positive',
            'ihr_notification' => 'Pregnancy-associated: urgent',
            'immediate_actions' => [
                'Supportive',
                'Pregnancy screening + counselling',
                'Rule out dengue before NSAIDs',
            ],
            'recommended_tests' => [
                'ZIKV PCR (serum / urine) day 1–7',
                'IgM day 5+',
            ],
            'specimens' => 'Serum + urine',
            'case_definition' => 'Acute fever or rash + ≥2 of: arthralgia/arthritis, conjunctivitis, myalgia in Aedes area.',
            'key_distinguishers' => [
                'Non-purulent conjunctivitis',
                'Short febrile illness + rash',
                'Congenital microcephaly risk',
                'Guillain-Barré post-infection',
            ],
            'differential' => 'Dengue, chikungunya, parvovirus, measles',
        ],

        'west_nile_fever' => [
            'name' => 'West Nile fever',
            'ihr_tier' => 'SYNDROMIC',
            'who_category' => 'Arbovirus surveillance',
            'cfr_pct' => 5.0,
            'incubation' => '2–14 days',
            'transmission' => 'Culex mosquito; bird reservoir',
            'ppe' => 'Standard',
            'isolation' => 'Standard',
            'ihr_notification' => 'Cluster or neuroinvasive: notifiable',
            'immediate_actions' => [
                'Supportive ICU for neuroinvasive',
                'Vector control',
            ],
            'recommended_tests' => [
                'WNV IgM in CSF / serum',
                'PCR (low yield)',
            ],
            'specimens' => 'Serum + CSF',
            'case_definition' => 'Acute febrile illness ± neurological features in endemic exposure.',
            'key_distinguishers' => [
                'Flaccid paralysis (polio-like) in neuroinvasive form',
                'Bird die-off precedes human cases',
                '< 1 % develop neuroinvasive disease',
            ],
            'differential' => 'Polio, Japanese encephalitis, GBS',
        ],
    ];

    /** Get intel for a disease_code, or a neutral fallback. */
    public static function get(string $code): array
    {
        $k = strtolower(trim($code));
        if (isset(self::REGISTRY[$k])) return self::REGISTRY[$k];

        return [
            'name' => $code ?: 'Unspecified syndromic alert',
            'ihr_tier' => 'SYNDROMIC',
            'who_category' => 'Not in IHR Tier 1/2 registry — syndromic review',
            'cfr_pct' => null,
            'incubation' => 'unknown',
            'transmission' => 'To be determined on clinical review',
            'ppe' => 'Standard precautions until disease identified',
            'isolation' => 'Isolate pending clinical review',
            'ihr_notification' => 'Escalate to national public-health authority for classification',
            'immediate_actions' => [
                'Isolate and stabilise patient pending clinical review',
                'Document full history + clinical examination',
                'Escalate to DHO / PHEOC / National Focal Point',
                'Hold sample aliquots in cold chain pending directive',
            ],
            'recommended_tests' => [
                'Syndromic panel guided by presenting signs',
                'Targeted PCR once candidate disease identified',
            ],
            'specimens' => 'Hold all available specimens in cold chain pending review',
            'case_definition' => 'Case requires clinical review for syndromic classification against WHO IHR Annex 2.',
            'key_distinguishers' => ['Insufficient data for differentiation — escalate'],
            'differential' => 'Broad — dependent on syndromic pattern',
        ];
    }

    /** Quick helper — just the pretty name. */
    public static function nameFor(string $code): string
    {
        return (string) (self::get($code)['name'] ?? $code);
    }
}
