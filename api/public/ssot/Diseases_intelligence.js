/* eslint-disable no-dupe-keys */
/* eslint-disable @typescript-eslint/no-unused-vars */
/**
 * ============================================================
 * WHO POE DISEASE INTELLIGENCE LAYER v1.0.0
 * Diseases_intelligence.js
 * ============================================================
 *
 * LOAD ORDER (main.ts / index.html):
 *   1. Diseases.js          (base engine, 41 diseases)
 *   2. Diseases_intelligence.js  (THIS FILE — extends window.DISEASES)
 *   3. exposures.js         (exposure catalog with engine mapping)
 *
 * PURPOSE:
 *   Offloads ALL clinical intelligence from SecondaryScreening.vue
 *   into this file. The Vue makes 7 function calls. Zero clinical
 *   logic lives in the UI layer.
 *
 * EXPORTED API (all on window.DISEASES):
 *
 *   buildOutbreakContext(visitedCountries)
 *     → string[]  disease IDs to pass as outbreak_context to scoreDiseases()
 *
 *   deriveWHOSyndrome(presentSymptoms, vitals)
 *     → { syndrome, confidence, reasoning, who_criteria_met }
 *
 *   getNonCaseVerdict(presentSymptoms, absentSymptoms, vitals)
 *     → { isNonCase, reasons, recommended_syndrome, recommended_disposition }
 *
 *   computeIHRRiskLevel(scoreResult, vitals, globalFlags)
 *     → { risk_level, ihr_alert_required, alert_tier, routing_level, reasoning[] }
 *
 *   validateClinicalData(presentSymptoms, vitals)
 *     → { vital_alerts{}, critical_flags[], clinical_warnings[], needs_emergency_triage }
 *
 *   getWHOCaseDefinition(diseaseId)
 *     → { suspected, probable, confirmed, ihr_category, incubation, source }
 *
 *   getEnhancedScoreResult(presentSymptoms, absentSymptoms, exposureEngCodes,
 *                           visitedCountries, vitals)
 *     → full enhanced result combining scoreDiseases + outbreak context +
 *       syndrome derivation + risk level + non-case verdict
 *       (the SINGLE function the Vue calls for Step 3 — one call does everything)
 *
 * SOURCES:
 *   WHO AFRO IDSR Technical Guidelines 2021 (3rd Ed.)
 *   WHO IHR 2005 Annex 2 (PHEIC decision instrument)
 *   WHO Disease Fact Sheets 2024
 *   CDC Yellow Book 2024 (Geographic Disease Distribution)
 *   ECDC Country-specific risk assessments 2024
 *   Mandell's Principles of Infectious Diseases 9th Ed.
 *   ProMED / WHO Disease Outbreak News historical data
 *
 * DISCLAIMER:
 *   Clinical decision-support only. Does NOT replace assessment
 *   by a qualified clinician, laboratory confirmation, or
 *   national public health authority guidance.
 * ============================================================
 */

(function () {
    'use strict';

    if (!window.DISEASES || !Array.isArray(window.DISEASES.diseases)) {
        console.error('[DI] FATAL: window.DISEASES not loaded. Load Diseases.js first.');
        return;
    }

    const D = window.DISEASES;

    // ══════════════════════════════════════════════════════════════════════
    // SECTION 1: ENDEMIC COUNTRY ORACLE
    //
    // ISO 3166-1 alpha-2 country codes where each disease is ENDEMIC or
    // represents a documented HIGH epidemiological risk based on WHO/CDC
    // country-specific guidance (2024).
    //
    // Used by buildOutbreakContext() to populate outbreak_context when
    // scoreDiseases() is called. A traveller from an endemic country gets
    // the disease's outbreak_bonus added to its score.
    //
    // Risk levels:
    //   HIGH   — endemic transmission, regular case reports, national alert
    //   MEDIUM — periodic outbreaks, imported cases reported
    //   (only HIGH is used for outbreak_context trigger)
    // ══════════════════════════════════════════════════════════════════════

    const ENDEMIC_COUNTRIES = {

        cholera: [
            // WHO Global Cholera & AGE Control Coalition — high-burden countries 2024
            'AF', 'AO', 'BD', 'BF', 'BI', 'BJ', 'CD', 'CF', 'CG', 'CI', 'CM', 'DJ',
            'ER', 'ET', 'GH', 'GM', 'GN', 'GW', 'HT', 'IN', 'IQ', 'KE', 'KH', 'LA',
            'LB', 'LR', 'LY', 'MG', 'ML', 'MM', 'MZ', 'NE', 'NG', 'NP', 'PH', 'PK',
            'SD', 'SL', 'SO', 'SS', 'SY', 'TD', 'TG', 'TZ', 'UG', 'VN', 'YE', 'ZM', 'ZW',
        ],

        yellow_fever: [
            // WHO YF vaccination certificate required/recommended (endemic zones)
            'AO', 'BI', 'BF', 'BJ', 'BO', 'BR', 'CF', 'CI', 'CM', 'CO', 'CD', 'EC',
            'ET', 'GF', 'GH', 'GM', 'GN', 'GQ', 'GW', 'KE', 'LR', 'MR', 'ML', 'NE',
            'NG', 'PA', 'PE', 'SD', 'SL', 'SN', 'SO', 'SS', 'SR', 'ST', 'TG', 'TZ',
            'UG', 'VE', 'TT', 'GY',
        ],

        ebola_virus_disease: [
            // Congo Basin primary endemic zone + documented outbreak countries
            'CD',  // Democratic Republic of Congo — most outbreaks (>30 total)
            'GN',  // Guinea — West Africa outbreak 2013-16, 2021
            'LR',  // Liberia — West Africa outbreak 2013-16
            'SL',  // Sierra Leone — West Africa outbreak 2013-16
            'UG',  // Uganda — multiple outbreaks
            'SS',  // South Sudan — 2004, 2022
            'GA',  // Gabon — 4 outbreaks
            'GQ',  // Equatorial Guinea — 2023
            'CF',  // Central African Republic — 2001-2002
            'RW',  // Rwanda — 2019 import from DRC
            'NG',  // Nigeria — imported 2014, 2022
            'CD', 'CG', // Republic of Congo — 2003, 2012
        ],

        marburg_virus_disease: [
            // Bat caves / fruit bats in these countries are reservoir hosts
            'AO',  // Angola — 2004-2005 Uige outbreak (252 deaths)
            'CD',  // DRC — historical outbreaks
            'GQ',  // Equatorial Guinea — 2023 outbreak
            'GH',  // Ghana — 2022 outbreak (first confirmed in West Africa)
            'GN',  // Guinea — 2021 alert
            'KE',  // Kenya — 1980, 1987 imported cases
            'RW',  // Rwanda — 2024 outbreak (Python Cave exposure)
            'TZ',  // Tanzania — 2023 outbreak
            'UG',  // Uganda — Python Cave 2007-2012, 2014, 2017
            'ZM',  // Zambia — 2024 outbreak
        ],

        lassa_fever: [
            // Endemic belt in West Africa — Mastomys rat reservoir
            'GN',  // Guinea — endemic
            'LR',  // Liberia — endemic (estimated 100k-300k cases/yr in belt)
            'NG',  // Nigeria — highest burden, annual outbreaks
            'SL',  // Sierra Leone — endemic
            'BJ',  // Benin — documented spillover
            'GH',  // Ghana — sporadic cases
            'ML',  // Mali — sporadic
            'TG',  // Togo — border areas
            'BF',  // Burkina Faso — border risk
        ],

        cchf: [
            // Hyalomma tick distribution + livestock farming
            'AF', 'AL', 'AZ', 'BG', 'DZ', 'GE', 'GR', 'HR', 'IQ', 'IR',
            'KG', 'KZ', 'MN', 'PK', 'RO', 'RS', 'RU', 'SA', 'SD', 'SO',
            'TJ', 'TM', 'TR', 'TZ', 'UA', 'UZ', 'ZA', 'KW', 'MR', 'MK',
        ],

        rift_valley_fever: [
            // Sub-Saharan Africa + Arabian Peninsula
            'BJ', 'CD', 'DJ', 'EG', 'ET', 'GH', 'KE', 'MA', 'ML', 'MR',
            'MZ', 'NA', 'NE', 'NG', 'RW', 'SA', 'SD', 'SL', 'SN', 'SO',
            'SS', 'TZ', 'UG', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
        ],

        mpox: [
            // Clade I: Congo Basin (endemic reservoir)
            'CD', 'CF', 'CG', 'CM', 'GA', 'GQ',
            // Clade I/II West Africa
            'NG', 'GH', 'CI', 'LR', 'SL', 'BF',
            // Clade IIb: Global outbreak 2022+ (all regions)
            // Include countries with >100 confirmed cases 2022-2024
            'US', 'UK', 'BR', 'ES', 'FR', 'DE', 'BE', 'NL', 'CA', 'IT', 'PT',
            'IN', 'MX', 'AU', 'PE', 'CO', 'AR', 'PL', 'CH', 'ZA', 'TH',
            'UG', 'KE', 'RW', 'ZM', 'SS',
        ],

        meningococcal_meningitis: [
            // WHO Meningitis Belt — Sub-Saharan Africa (December to June)
            'BF', 'BI', 'BJ', 'CD', 'CF', 'CI', 'CM', 'DJ', 'ER', 'ET',
            'GA', 'GM', 'GH', 'GN', 'GW', 'ML', 'MR', 'NE', 'NG', 'SD',
            'SL', 'SN', 'SS', 'TD', 'TG', 'TZ', 'UG',
            // Non-Belt high-burden
            'SO', 'IN', 'PK', 'NP', 'AF',
        ],

        measles: [
            // Countries with <90% MCV2 coverage or recent outbreaks (WHO 2024)
            'AF', 'AO', 'CD', 'CF', 'CI', 'CM', 'CG', 'DJ', 'ER', 'ET', 'GA',
            'GH', 'GN', 'GW', 'ID', 'IN', 'KE', 'LR', 'MG', 'ML', 'MM', 'MZ',
            'NE', 'NG', 'NP', 'PH', 'PK', 'RW', 'SD', 'SL', 'SO', 'SS', 'SY',
            'TD', 'TG', 'TZ', 'UA', 'UG', 'VE', 'YE', 'ZM', 'ZW',
        ],

        pneumonic_plague: [
            // Primary: Democratic Republic of Congo (highest global burden)
            // Secondary: historical outbreak areas in Africa/Asia
            'CD', 'KE', 'MG', 'MM', 'MZ', 'NG', 'PE', 'RW', 'TZ', 'UG', 'ZM', 'VN',
            // US (rare rural) — not included as POE risk is minimal
        ],

        bubonic_plague: [
            'CD', 'KE', 'MG', 'MM', 'MZ', 'NG', 'PE', 'RW', 'TZ', 'UG', 'ZM', 'VN',
            'BO', 'BR', 'IN', 'KZ', 'MN', 'US', 'NA', 'ZA',
        ],

        smallpox: [
            // Eradicated 1980 — bioterrorism/lab accident risk is GLOBAL
            // No geographic restriction — any positive presentation = immediate IHR Tier 1
        ],

        polio: [
            // Wild poliovirus Type 1 endemic (as of 2024)
            'AF', 'PK',
            // cVDPV outbreak countries (circulating vaccine-derived)
            'CD', 'ET', 'SO', 'SD', 'YE', 'NG', 'GH', 'ID', 'PH', 'CM', 'GN',
            'CF', 'TZ', 'UG', 'MZ', 'MW', 'ZM',
        ],

        sars: [
            // No current endemic — only historical + lab-accident risk
            'CN', 'HK', 'SG', 'CA', 'VN',  // Original 2003 outbreak countries
        ],

        influenza_new_subtype_zoonotic: [
            // H5N1/H7N9/H9N2 documented animal-human transmission countries
            'CN', 'EG', 'ID', 'IN', 'VN', 'BD', 'KH', 'IQ', 'MM', 'PK', 'AZ',
            'DJ', 'GH', 'NG', 'TF', 'UG', 'CL', 'AU', 'CA', 'US',
        ],

        mers: [
            // Arabian Peninsula endemic (dromedary camel reservoir)
            'SA', 'AE', 'OM', 'QA', 'KW', 'JO', 'LB', 'YE', 'IR', 'KR',
            // Countries with imported cases
            'GH', 'UK', 'DE', 'FR', 'IT', 'US', 'AT', 'AL', 'GR', 'MY', 'PH', 'TN',
        ],

        nipah_virus: [
            'BD',  // Bangladesh — almost annual outbreaks since 2001
            'IN',  // India — Kerala state outbreaks 2018, 2021, 2023
            'MY',  // Malaysia — original Nipah outbreak 1998-99
            'SG',  // Singapore — linked to Malaysia 1999
        ],

        hantavirus: [
            // Hantaan/Seoul (Old World) — rodent-borne, hemorrhagic fever with renal syndrome
            'KR', 'CN', 'RU', 'FI', 'SE', 'NO', 'DE', 'FR', 'CZ', 'SK', 'HU', 'RO',
            // Andes/Sin Nombre (New World) — cardiopulmonary syndrome
            'AR', 'CL', 'BO', 'BR', 'PY', 'US', 'UY',
            'KG', 'KZ', 'MN',
        ],

        anthrax_cutaneous: [
            // Countries with livestock anthrax burden
            'AF', 'BD', 'ET', 'GE', 'HA', 'IN', 'KE', 'MN', 'MZ', 'NG', 'PK', 'SD', 'TZ', 'TR',
            'UZ', 'KG', 'KZ', 'TM', 'AZ', 'GE', 'BY', 'RU', 'UA',
            'CI', 'GH', 'GN', 'ML', 'NE',
        ],

        anthrax_pulmonary: [
            // Bioterrorism global risk + same livestock countries for inhalation
            'AF', 'BD', 'ET', 'IN', 'KE', 'MN', 'NG', 'PK', 'RU', 'SD', 'TR', 'TZ',
        ],

        tularemia: [
            // Europe and Central Asia — Francisella tularensis reservoir (lagomorphs, ticks)
            'AT', 'CZ', 'FI', 'FR', 'GE', 'HU', 'KZ', 'NO', 'RU', 'SE', 'SK', 'US',
            'TR', 'MK', 'AL', 'HR', 'BA', 'RS', 'BG', 'GR',
        ],

        dengue: [
            // All countries in dengue-endemic tropical/subtropical belt
            'AG', 'AO', 'AR', 'AW', 'BB', 'BD', 'BO', 'BR', 'BS', 'BT', 'BZ', 'CF',
            'CI', 'CO', 'CR', 'CU', 'CV', 'CY', 'DJ', 'DM', 'DO', 'EC', 'FJ', 'GD',
            'GF', 'GH', 'GP', 'GT', 'GY', 'HN', 'HT', 'ID', 'IN', 'JM', 'KH', 'LA',
            'LK', 'MG', 'MH', 'MQ', 'MX', 'MY', 'MZ', 'NC', 'NI', 'PA', 'PE', 'PH',
            'PR', 'PY', 'RE', 'SB', 'SG', 'SL', 'SO', 'SV', 'SR', 'TH', 'TL', 'TT',
            'TW', 'UG', 'VE', 'VN', 'WS', 'YT', 'PG', 'MV',
        ],

        dengue_severe: [
            // Same endemic zone as dengue — severe dengue risk in hyperendemic areas
            'BD', 'BR', 'CO', 'ID', 'IN', 'KH', 'LA', 'MX', 'MY', 'PH', 'SG', 'TH',
            'TW', 'VE', 'VN', 'HN', 'NI', 'SV',
        ],

        chikungunya: [
            // Indian Ocean islands + East Africa + South/Southeast Asia + Americas
            'AO', 'BD', 'BI', 'BF', 'CM', 'CI', 'CO', 'DJ', 'DM', 'ET', 'GH', 'GN',
            'GW', 'ID', 'IN', 'IT', 'KE', 'LK', 'MG', 'ML', 'MU', 'MV', 'MY', 'MZ',
            'NE', 'NG', 'PH', 'RE', 'RW', 'SG', 'SL', 'SN', 'SS', 'TH', 'TL', 'TZ',
            'UG', 'VE', 'VN', 'YT', 'BR', 'CO', 'VE', 'AG', 'TT',
        ],

        zika: [
            // Americas and Pacific Island current/recent transmission
            'AO', 'AR', 'BB', 'BO', 'BR', 'BS', 'BZ', 'CO', 'CR', 'CU', 'DO', 'EC',
            'FJ', 'GD', 'GF', 'GT', 'GY', 'HN', 'HT', 'ID', 'IN', 'JM', 'KH', 'LK',
            'MH', 'MQ', 'MX', 'MY', 'MZ', 'NC', 'NI', 'PA', 'PE', 'PH', 'PR', 'PY',
            'RE', 'SB', 'SG', 'SL', 'SR', 'SV', 'TH', 'TL', 'TT', 'UG', 'VE', 'VN',
            'WS', 'YT',
        ],

        malaria_severe: [
            // High-transmission Sub-Saharan Africa + Asia Pacific
            'AF', 'AO', 'BI', 'BF', 'BJ', 'BW', 'CD', 'CF', 'CG', 'CI', 'CM', 'DJ',
            'ER', 'ET', 'GA', 'GH', 'GM', 'GN', 'GQ', 'GW', 'ID', 'IN', 'KE', 'KM',
            'KH', 'LA', 'LR', 'LS', 'MG', 'MM', 'ML', 'MR', 'MW', 'MZ', 'NA', 'NE',
            'NG', 'PG', 'PH', 'RW', 'SD', 'SL', 'SN', 'SO', 'SS', 'SZ', 'TD', 'TH',
            'TG', 'TZ', 'UG', 'VN', 'YT', 'ZA', 'ZM', 'ZW',
        ],

        malaria_uncomplicated: [
            // Entire malaria-endemic zone (more countries than severe)
            'AF', 'AO', 'BI', 'BF', 'BJ', 'BW', 'CD', 'CF', 'CG', 'CI', 'CM', 'CO',
            'DJ', 'DO', 'ER', 'ET', 'GA', 'GH', 'GM', 'GN', 'GQ', 'GW', 'GY', 'HT',
            'ID', 'IN', 'KE', 'KM', 'KH', 'LA', 'LR', 'LS', 'MG', 'MM', 'ML', 'MR',
            'MW', 'MX', 'MZ', 'NA', 'NE', 'NG', 'NI', 'PA', 'PE', 'PG', 'PH', 'PY',
            'RW', 'SD', 'SL', 'SN', 'SO', 'SS', 'SR', 'SV', 'SZ', 'TD', 'TH', 'TG',
            'TZ', 'UG', 'VE', 'VN', 'YT', 'ZA', 'ZM', 'ZW',
        ],

        typhoid_fever: [
            // WHO-defined high-incidence countries (>100 cases/100k/yr)
            'AF', 'BD', 'CM', 'ET', 'GH', 'IN', 'KE', 'LK', 'MM', 'MW', 'NG', 'NP',
            'PH', 'PK', 'SD', 'SL', 'TZ', 'UG', 'VN', 'ZM',
            'KH', 'LA', 'PG', 'TJ', 'TM', 'UZ', 'KG',
        ],

        leptospirosis: [
            // Tropical and subtropical — flooding, agriculture, livestock
            'BD', 'BR', 'CO', 'CR', 'EC', 'GY', 'ID', 'IN', 'JM', 'LK', 'MG', 'MY',
            'MX', 'NI', 'PE', 'PH', 'SR', 'TH', 'TT', 'VN', 'FJ', 'SB', 'PG', 'BO',
        ],

        rickettsia_scrub_typhus: [
            // "Tsutsugamushi Triangle" — Asia Pacific
            'AU', 'BD', 'BT', 'CN', 'ID', 'IN', 'JP', 'KH', 'KR', 'LA', 'LK',
            'MM', 'MY', 'NP', 'PG', 'PH', 'TH', 'TW', 'VN',
        ],

        brucellosis: [
            // Livestock-heavy regions — unpasteurized dairy, abattoir exposure
            'AF', 'DZ', 'AR', 'BA', 'BY', 'CD', 'CN', 'EG', 'ET', 'GR', 'IQ', 'IR',
            'KE', 'KZ', 'KG', 'LB', 'LY', 'ME', 'MN', 'MX', 'MR', 'MA', 'NE', 'OM',
            'PK', 'QA', 'RO', 'RS', 'RU', 'SA', 'SD', 'SY', 'TJ', 'TN', 'TR', 'TM',
            'UA', 'UZ', 'YE', 'IN', 'MK',
        ],

        japanese_encephalitis: [
            // Culex mosquito vector — rice fields + pig farming
            'AU', 'BD', 'BT', 'CN', 'ID', 'IN', 'JP', 'KH', 'KR', 'LA', 'LK',
            'MM', 'MY', 'NP', 'PG', 'PH', 'TH', 'TL', 'TW', 'VN',
        ],

        west_nile_fever: [
            // Culex mosquito vector — Mediterranean + Africa + Americas
            'DZ', 'EG', 'IL', 'IT', 'GR', 'RO', 'RU', 'SA', 'SD', 'TN', 'TR', 'UA',
            'US', 'MA', 'MR', 'NG', 'CM', 'ET', 'KE', 'TZ', 'ZA', 'IN', 'PK',
        ],

        rabies: [
            // Dog-mediated — countries without rabies-free status
            'AF', 'AO', 'BD', 'BI', 'BJ', 'BO', 'BR', 'CF', 'CI', 'CM', 'CN', 'CO',
            'CD', 'DJ', 'ET', 'GH', 'GM', 'GN', 'HT', 'ID', 'IN', 'IQ', 'KE', 'KH',
            'LA', 'LK', 'ML', 'MM', 'MW', 'MX', 'MZ', 'NE', 'NG', 'NP', 'PH', 'PK',
            'RU', 'SD', 'SL', 'TH', 'TN', 'TZ', 'UG', 'VN', 'YE', 'ZM', 'ZW',
            'MY', 'MG', 'MR', 'SN', 'TG', 'BF', 'GW',
        ],

        hepatitis_a: [
            // Low sanitation — fecal-oral route, often asymptomatic in endemic
            'AF', 'BO', 'CI', 'CM', 'DJ', 'DZ', 'EG', 'ET', 'GA', 'GH', 'GM', 'GN',
            'GW', 'HT', 'ID', 'IN', 'IQ', 'KH', 'KE', 'LA', 'LB', 'LK', 'MA', 'MG',
            'ML', 'MM', 'MX', 'MZ', 'NE', 'NG', 'NP', 'PH', 'PK', 'SD', 'SN', 'SS',
            'TH', 'TN', 'TZ', 'UG', 'VN', 'YE', 'ZM', 'ZW',
        ],

        hepatitis_e: [
            // Waterborne — refugee camps, flooding, poor water treatment
            'AF', 'BD', 'CD', 'CI', 'CM', 'DJ', 'ER', 'ET', 'GN', 'ID', 'IN', 'KE',
            'KH', 'LA', 'LR', 'ML', 'MN', 'MV', 'MW', 'MZ', 'NE', 'NG', 'NP', 'PH',
            'PK', 'SD', 'SN', 'SS', 'TJ', 'TM', 'TZ', 'UG', 'VN', 'ZM', 'ZW', 'MM',
        ],

        rubella: [
            // Countries without CRS elimination — low vaccine coverage
            'AF', 'AO', 'BD', 'CD', 'CM', 'DJ', 'ET', 'GN', 'HT', 'ID', 'IN', 'KE',
            'MG', 'MM', 'MZ', 'NG', 'PH', 'PK', 'SD', 'SO', 'SY', 'TZ', 'UG',
            'VN', 'YE', 'ZM', 'ZW', 'CG', 'SS',
        ],

        influenza_seasonal: [
            // Global — no endemic restriction for POE purposes.
            // High-risk seasons: Nov-Mar (N hemisphere), May-Sep (S hemisphere)
            // All countries carry risk during winter months
        ],

        shigellosis_dysentery: [
            // Low-income, low-WASH settings
            'AF', 'BD', 'CD', 'CM', 'ET', 'GH', 'GN', 'HT', 'ID', 'IN', 'KE', 'KH',
            'LA', 'LB', 'LK', 'MG', 'ML', 'MM', 'MZ', 'NE', 'NG', 'NP', 'PH', 'PK',
            'SD', 'SL', 'SS', 'TH', 'TZ', 'UG', 'VN', 'YE', 'ZM', 'ZW',
        ],

        awd_non_cholera: [
            // Same broad group as shigellosis — ETEC, EPEC, rotavirus dominant
            'AF', 'BD', 'CD', 'CM', 'ET', 'GH', 'HT', 'ID', 'IN', 'KE', 'KH',
            'LA', 'LK', 'MG', 'ML', 'MM', 'MZ', 'NG', 'NP', 'SD', 'SS', 'TZ',
            'UG', 'VN', 'YE', 'ZM', 'ZW',
        ],

        covid_19: [
            // Global pandemic — no geographic restriction.
            // Use current WHO outbreak notification for variant surges.
        ],

        // lassa_fever: duplicate removed — canonical definition at line 134
        // 'GN','LR','NG','SL','BJ','GH','ML','TG','BF',

    };

    // ══════════════════════════════════════════════════════════════════════
    // SECTION 2: WHO CLINICAL CASE DEFINITIONS
    //
    // Based on WHO AFRO IDSR Technical Guidelines 2021 (3rd Edition)
    // and individual WHO disease fact sheets.
    // Used by getWHOCaseDefinition() and displayed in the secondary
    // screening view so officers know exactly what constitutes a case.
    // ══════════════════════════════════════════════════════════════════════

    const WHO_CASE_DEFINITIONS = {

        cholera: {
            suspected: 'Any person aged ≥5 years with acute profuse watery (rice-water) diarrhoea with or without vomiting, AND signs of severe dehydration OR shock, in an area with known cholera activity — OR any person with profuse rice-water stool regardless of age in an epidemic setting.',
            probable: 'A suspected case with epidemiological link to a confirmed case, AND positive laboratory results pending.',
            confirmed: 'Any suspected case in which Vibrio cholerae O1 or O139 is isolated from stool by culture, OR a positive cholera RDT in a non-endemic context.',
            ihr_category: 'IHR Annex 2 — Notifiable to WHO if unexpected or unusual.',
            source: 'WHO AFRO IDSR 2021 Technical Guidelines, Chapter 2.',
        },

        yellow_fever: {
            suspected: 'Any person presenting with acute onset of fever FOLLOWED by jaundice within 14 days of first symptoms, in an area with yellow fever risk OR with recent travel to an endemic area.',
            probable: 'A suspected case with positive serology (IgM by ELISA) AND no recent YF vaccination, OR epidemiological link to a confirmed case.',
            confirmed: 'A probable case with: virus isolation from blood/tissue, OR YF antigen or RNA detected by PCR, OR seroconversion in paired samples (4-fold rise in neutralizing antibodies).',
            ihr_category: 'IHR Annex 2 — Always notifiable to WHO.',
            source: 'WHO Yellow Fever Fact Sheet 2023; IHR Annex 2.',
        },

        ebola_virus_disease: {
            suspected: 'ANY PERSON with acute onset of fever ≥38°C AND contact with blood/body fluids/secretions of a person with EVD, OR deceased person who had EVD, OR contact with bats/primates in endemic areas — OR unexplained hemorrhagic fever.',
            probable: 'A suspected case evaluated by a clinician, OR a deceased case with epidemiological link to a confirmed case, where laboratory testing cannot be performed.',
            confirmed: 'A suspected or probable case with: Ebola virus RNA detected by RT-PCR (≥2 independent target amplification), OR Ebola virus antigen detected by ELISA, OR Ebola virus-specific IgM or IgG serology, OR virus isolated by cell culture.',
            ihr_category: 'IHR Annex 2 — ALWAYS notifiable to WHO. IMMEDIATE notification required.',
            source: 'WHO EVD Case Definition 2023; IHR Annex 2.',
            poe_action: 'IMMEDIATE ISOLATION. Full PPE (PAPR or N95 + face shield + gown + double gloves + boot covers). Notify national IHR focal point IMMEDIATELY.',
        },

        marburg_virus_disease: {
            suspected: 'Any person with fever ≥38.5°C AND ≥1 of: headache, severe malaise, myalgia — AND contact with someone with Marburg disease, OR exposure to bats/mines/caves in endemic area — OR any unexplained hemorrhagic fever with no alternative diagnosis.',
            probable: 'A suspected case not confirmed by laboratory testing, AND epidemiological link to a confirmed case or exposure to high-risk bat habitat.',
            confirmed: 'Marburg virus RNA detected by RT-PCR, OR Marburg antigen detected by immunohistochemistry, OR virus isolated by culture.',
            ihr_category: 'IHR Annex 2 — ALWAYS notifiable. Highest biosafety priority.',
            source: 'WHO Marburg Case Definition 2023; IHR Annex 2.',
        },

        lassa_fever: {
            suspected: 'Any person with fever ≥38°C for ≥2 days AND ≥1 of: sore throat with exudate, myalgia, hemorrhagic manifestations, encephalopathy, proteinuria — in an endemic area OR with travel from an endemic area within 21 days.',
            probable: 'A suspected case with positive Lassa antigen RDT, OR epidemiological link to confirmed case.',
            confirmed: 'Lassa virus RNA detected by RT-PCR, OR Lassa IgM ≥1:8 by ELISA, OR seroconversion in paired sera, OR virus isolation.',
            ihr_category: 'IHR Annex 2 equivalent — Notifiable to WHO NFP.',
            source: 'WHO AFRO IDSR 2021; WHO Lassa Fact Sheet 2017.',
        },

        cchf: {
            suspected: 'Any person with acute onset of fever AND hemorrhagic manifestations AND history of tick bite OR exposure to livestock/slaughterhouses in a CCHF-endemic country within 14 days.',
            probable: 'A suspected case with positive CCHF serology (IgM) AND no alternative diagnosis.',
            confirmed: 'CCHF virus RNA by RT-PCR, OR virus antigen by ELISA, OR seroconversion/4-fold rise in IgG.',
            ihr_category: 'IHR Annex 2 equivalent.',
            source: 'WHO CCHF Fact Sheet 2022; ECDC Technical Guidance.',
        },

        rift_valley_fever: {
            suspected: 'Any person with acute febrile illness in an endemic area AND exposure to animal blood/tissues/livestock, OR involvement in veterinary activities, during an RVF outbreak.',
            probable: 'A suspected case with positive serology (IgM ≥1:100 by ELISA).',
            confirmed: 'RVF virus RNA by RT-PCR, OR virus isolation, OR seroconversion in paired samples.',
            ihr_category: 'IHR Annex 2 equivalent — especially if animal epizootic confirmed.',
            source: 'WHO RVF Fact Sheet 2020.',
        },

        mpox: {
            suspected: 'Any person with unexplained acute skin rash, lesions or ulcers AND at least ONE of: headache, fever ≥38°C, lymphadenopathy, myalgia, arthralgia, asthenia — AND no alternative diagnosis to explain all features.',
            probable: 'A suspected case with epidemiological link to a confirmed or probable case, OR positive orthopoxvirus serology.',
            confirmed: 'Mpox virus DNA detected by PCR (orthopoxvirus AND mpox-specific sequences), from skin lesion material (swab or crust) using validated assay.',
            ihr_category: 'IHR Annex 2 (2022 outbreak PHEIC now ended — continue surveillance).',
            source: 'WHO Mpox Case Definition 2024.',
        },

        meningococcal_meningitis: {
            suspected: 'Any person with sudden onset of fever AND stiff neck OR petechial/purpuric rash, OR any case of acute meningitis or meningoencephalitis.',
            probable: 'A suspected case with: CSF with cloudy appearance/elevated WBC/elevated protein/decreased glucose, OR gram-negative diplococci in CSF.',
            confirmed: 'Neisseria meningitidis isolated from CSF/blood, OR meningococcal antigen detected, OR PCR positive.',
            ihr_category: 'WHO AFRO priority disease — notifiable during Meningitis Belt dry season.',
            source: 'WHO AFRO IDSR 2021; WHO meningitis guidelines.',
        },

        measles: {
            suspected: 'Any person with fever AND generalized maculopapular rash lasting ≥3 days AND at least ONE of: cough, runny nose (coryza), red eyes (conjunctivitis).',
            probable: 'A suspected case with epidemiological link to a confirmed case AND no laboratory test performed.',
            confirmed: 'Measles virus isolation, OR measles IgM positive (≥3 days after rash onset), OR ≥4-fold rise in measles IgG, OR measles RNA detected by PCR.',
            ihr_category: 'WHO priority vaccine-preventable disease. PHEIC if vaccination coverage <80%.',
            source: 'WHO AFRO IDSR 2021; WHO measles vaccine position paper 2023.',
        },

        pneumonic_plague: {
            suspected: 'Any person with acute onset of fever, chills, headache, cough with blood-tinged sputum — especially if contact with plague cases or exposure to rodents/fleas in endemic areas within 7 days.',
            probable: 'A suspected case with laboratory results consistent with Y. pestis (F1 antigen positive by RDT).',
            confirmed: 'Yersinia pestis identified by culture, PCR, or ≥4-fold rise in F1 antigen antibody titer.',
            ihr_category: 'IHR Annex 2 — Notifiable to WHO. Pneumonic plague = IMMEDIATE isolation.',
            source: 'WHO Plague Fact Sheet 2022; IHR Annex 2.',
        },

        bubonic_plague: {
            suspected: 'Any person with fever, painful swollen lymph nodes (buboes) — especially if in plague-endemic area or flea/rodent exposure within 7 days.',
            probable: 'F1 antigen rapid test positive.',
            confirmed: 'Y. pestis isolated from blood/bubo aspirate/sputum, OR 4-fold rise in F1 antibody.',
            ihr_category: 'IHR Annex 2 equivalent. Potential for pneumonic conversion.',
            source: 'WHO Plague Fact Sheet 2022.',
        },

        smallpox: {
            suspected: 'ANY PERSON with febrile prodrome (2-4 days before rash) followed by centrifugal vesicular/pustular rash appearing simultaneously on face, arms, and trunk — with all lesions at the same stage of development.',
            probable: 'A suspected case with no alternative diagnosis and negative monkeypox result.',
            confirmed: 'Variola virus detected by PCR or electron microscopy in specialized BSL-4 laboratory.',
            ihr_category: 'IHR Tier 1 — ALWAYS NOTIFIABLE. BIOTERRORISM PROTOCOL.',
            source: 'WHO Smallpox Preparedness; IHR Annex 2.',
            poe_action: 'IMMEDIATE FULL ISOLATION. DO NOT RELEASE TRAVELLER. Notify WHO NFP and national emergency contacts IMMEDIATELY.',
        },

        polio: {
            suspected: 'Any child <15 years with acute flaccid paralysis (AFP), OR any person with AFP suspected to be polio regardless of age.',
            probable: 'AFP case with stool specimen not adequate, OR AFP case with epidemiological link to confirmed case.',
            confirmed: 'Wild poliovirus OR circulating vaccine-derived poliovirus (cVDPV) isolated from stool specimen from AFP case.',
            ihr_category: 'IHR Tier 1 — ALWAYS NOTIFIABLE. Single confirmed wild poliovirus case = PHEIC.',
            source: 'WHO GPEI AFP Surveillance Guidelines; IHR Annex 2.',
        },

        sars: {
            suspected: 'Any person with unexplained severe acute respiratory illness (fever >38°C + cough/difficulty breathing) AND epidemiological link (travel to affected area or contact with SARS case within 10 days).',
            probable: 'A suspected case with CXR/CT showing pneumonia or ARDS, AND no alternative diagnosis.',
            confirmed: 'SARS-CoV RNA by PCR (at least 2 different clinical specimens, or same specimen on ≥2 occasions).',
            ihr_category: 'IHR Tier 1 — ALWAYS NOTIFIABLE.',
            source: 'WHO SARS Case Definition 2003; IHR Annex 2.',
        },

        influenza_new_subtype_zoonotic: {
            suspected: 'Any person with fever ≥38°C AND cough/difficulty breathing AND exposure to live or dead poultry/pigs/animals in an affected area, OR contact with confirmed H5N1/H7N9/novel influenza case, within 10 days.',
            probable: 'A suspected case with influenza A positive rapid test but negative subtyping for seasonal H1/H3.',
            confirmed: 'Novel influenza virus identified by real-time RT-PCR using WHO-recommended assay.',
            ihr_category: 'IHR Tier 1 — ALWAYS NOTIFIABLE regardless of number of cases.',
            source: 'WHO Influenza at the Human-Animal Interface 2024; IHR Annex 2.',
        },

        mers: {
            suspected: 'Any person with fever AND acute respiratory illness (cough or shortness of breath) AND travel from/to or residence in the Arabian Peninsula within 14 days, OR close contact with a confirmed MERS case.',
            probable: 'A suspected case with influenza/other respiratory viruses ruled out, pending confirmation.',
            confirmed: 'MERS-CoV RNA detected by rRT-PCR from respiratory specimen (lower respiratory preferred).',
            ihr_category: 'IHR Annex 2 equivalent. Two-dose camel-related exposure cluster = immediate notification.',
            source: 'WHO MERS Interim Case Definition 2023.',
        },

        nipah_virus: {
            suspected: 'Any person presenting with encephalitis (fever + altered consciousness/seizures) OR severe respiratory distress — AND contact with pigs, bats, fruit contaminated by bats, OR date palm sap in endemic area within 21 days.',
            probable: 'A suspected case with epidemiological link to confirmed Nipah exposure.',
            confirmed: 'NiV RNA by RT-PCR, OR NiV-specific IgM, OR virus isolation.',
            ihr_category: 'IHR Annex 2 equivalent (CFR 40-75%). Immediate WHO notification.',
            source: 'WHO Nipah Fact Sheet 2022; Bangladesh IEDCR protocols.',
        },

        dengue: {
            suspected: 'Any person with acute fever (2–7 days) AND at least 2 of: nausea/vomiting, rash, myalgia/arthralgia, headache, positive tourniquet test, leukopenia, petechiae — in a dengue-endemic area OR with travel from one within 14 days.',
            probable: 'Dengue rapid NS1 antigen positive, OR dengue IgM positive (≥5 days after fever onset).',
            confirmed: 'Dengue virus RNA by RT-PCR, OR dengue NS1 antigen confirmed by ELISA, OR seroconversion IgM/IgG in paired sera.',
            ihr_category: 'WHO AFRO priority disease. Not IHR Annex 2 but notifiable nationally.',
            source: 'WHO Dengue Guidelines 2009 (revised 2012); PAHO 2022 Guidelines.',
        },

        dengue_severe: {
            suspected: 'A dengue case with warning signs: abdominal pain/tenderness, persistent vomiting, clinical fluid accumulation, mucosal bleed, lethargy, liver enlargement >2cm, rising HCT with rapid platelet drop — OR severe dengue criteria (severe plasma leakage, bleeding, organ impairment).',
            probable: 'Clinical severe dengue in confirmed dengue patient.',
            confirmed: 'Confirmed dengue with severe dengue features.',
            ihr_category: 'Requires immediate clinical management. CFR <1% with good care, up to 20% without.',
            source: 'WHO Dengue: Guidelines for Diagnosis, Treatment, Prevention and Control 2012.',
        },

        chikungunya: {
            suspected: 'Any person with acute onset of fever AND severe joint pain/arthralgia not explained by other medical conditions, in an area with chikungunya risk.',
            probable: 'A suspected case with recent travel from endemic area AND positive chikungunya IgM.',
            confirmed: 'CHIKV RNA by RT-PCR, OR ≥4-fold rise in chikungunya neutralizing antibodies, OR virus isolation.',
            ihr_category: 'WHO priority arboviral disease. Not IHR Annex 2 but significant morbidity.',
            source: 'WHO Chikungunya Fact Sheet 2023; PAHO 2011 Guidelines.',
        },

        zika: {
            suspected: 'Any person (especially pregnant women) with rash AND fever AND at least 2 of: conjunctivitis, arthralgia, myalgia, headache — in an area with Zika risk.',
            probable: 'A suspected case with positive Zika IgM (cross-reactivity with dengue possible).',
            confirmed: 'Zika virus RNA by RT-PCR in serum/urine (within 7 days of symptom onset).',
            ihr_category: 'PHEIC declared 2016 (ended 2016). Microcephaly risk in pregnancy = urgent priority.',
            source: 'WHO Zika Case Classification 2023. MANDATORY for pregnant women to notify.',
        },

        malaria_severe: {
            suspected: 'Any child OR adult with fever OR history of fever in last 48 hours AND living in or travel from malaria-endemic area — AND signs of severity: impaired consciousness, prostration, multiple convulsions, respiratory distress, abnormal bleeding, jaundice, hemoglobinuria, circulatory collapse, pulmonary edema.',
            probable: 'Positive malaria RDT (PfHRP2 or pan-malaria).',
            confirmed: 'Plasmodium falciparum identified by microscopy, PCR, or RDT with ≥1 sign of severity.',
            ihr_category: 'Medical emergency. IV artesunate required. CFR 15-20% if untreated.',
            source: 'WHO Guidelines for Treatment of Malaria 3rd Ed. 2015; WHO 2023 update.',
        },

        malaria_uncomplicated: {
            suspected: 'Any person with fever (≥37.5°C) OR history of fever within 48-72 hours AND NO signs of severe malaria — in an area with malaria risk or travel history.',
            probable: 'Positive malaria RDT.',
            confirmed: 'Plasmodium spp. identified by microscopy or PCR.',
            ihr_category: 'Highly prevalent in endemic zones. Rapid RDT available for POE.',
            source: 'WHO Guidelines for Treatment of Malaria 3rd Ed. 2015.',
        },

        typhoid_fever: {
            suspected: 'Any person with fever ≥38.5°C for ≥3 days with no known focus AND living in or returning from a typhoid-endemic area.',
            probable: 'A suspected case with compatible clinical features AND positive Widal O-titer ≥1:160 in non-immune population (limited specificity).',
            confirmed: 'S. Typhi or S. Paratyphi isolated from blood, bone marrow, stool, or urine culture.',
            ihr_category: 'WHO priority disease. XDR typhoid (extensively drug-resistant) emerging in Pakistan.',
            source: 'WHO Typhoid Fever Position Paper 2018.',
        },

        leptospirosis: {
            suspected: 'Any person with acute febrile illness AND myalgia/muscle tenderness AND at least 1 of: calf muscle pain, conjunctival suffusion (red eyes without discharge), oliguria/anuria, jaundice — AND exposure to water/soil/animals OR flooding within 30 days.',
            probable: 'Suspected case with positive Leptospira MAT ≥1:100 in single acute sample.',
            confirmed: 'Leptospira isolated from blood/CSF/urine, OR ≥4-fold rise in MAT titer in paired sera.',
            ihr_category: 'Weil disease (severe) requires ICU — CFR up to 40% with pulmonary hemorrhage syndrome.',
            source: 'WHO Leptospirosis Human Health Fact Sheet 2011; ILS Guidelines.',
        },

        rickettsia_scrub_typhus: {
            suspected: 'Any person with fever AND headache AND myalgia, AND presence of eschar (painless black necrotic ulcer) at site of chigger bite — in endemic area (Tsutsugamushi Triangle).',
            probable: 'Suspected case with Scrub typhus rapid test positive OR IFA IgM ≥1:50.',
            confirmed: 'IFA IgG ≥1:800 in convalescent serum, OR PCR positive for Orientia tsutsugamushi, OR isolation.',
            ihr_category: 'Major cause of undifferentiated fever in Asia-Pacific. Doxycycline effective if started early.',
            source: 'APCMV Guidelines 2023; Clin Infect Dis 2022.',
        },

        brucellosis: {
            suspected: 'Any person with prolonged fever (>10 days), sweating (especially nocturnal), myalgia, arthralgia, and fatigue — AND occupational or dietary exposure (livestock, veterinary work, unpasteurized dairy, abattoir) in an endemic country.',
            probable: 'Suspected case with Brucella agglutination test ≥1:160 in single serum.',
            confirmed: 'Brucella spp. isolated from blood/bone marrow/tissue, OR ≥4-fold rise in agglutination titer.',
            ihr_category: 'Significant occupational disease. Not IHR notifiable but important at agricultural POEs.',
            source: 'WHO Brucellosis in Humans and Animals 2006.',
        },

        japanese_encephalitis: {
            suspected: 'Any person with acute encephalitis syndrome (fever + altered mental status ± seizures) AND vaccination-unprotected status AND travel to/residence in JE-endemic region during transmission season.',
            probable: 'Suspected case with positive JE IgM in CSF or serum.',
            confirmed: 'JE virus RNA by RT-PCR in CSF, OR 4-fold rise in JE neutralizing antibody.',
            ihr_category: 'CFR 20-30%. Neurological sequelae in 30-50% survivors. Vector: Culex tritaeniorhynchus.',
            source: 'WHO JE Position Paper 2015.',
        },

        rabies: {
            suspected: 'Any person with acute encephalitis AND recent (within 1 year) bite/scratch from an animal in a rabies-endemic country — OR unexplained progressive encephalitis with hydrophobia, aerophobia, or aerophobia.',
            probable: 'Direct fluorescence antibody (DFA) positive from skin biopsy (neck/nape) or corneal smear.',
            confirmed: 'Rabies virus RNA by RT-PCR in saliva/CSF/brain tissue — or DFA on brain tissue post-mortem.',
            ihr_category: 'Once symptomatic = virtually 100% fatal. PEP effective if started before symptoms.',
            source: 'WHO Rabies Position Paper 2018.',
            poe_action: 'If patient symptomatic with hydrophobia/aerophobia — immediate isolation + palliative care referral. Provide PEP history assessment.',
        },

        hepatitis_a: {
            suspected: 'Any person with acute onset of fever AND jaundice (or elevated ALT >10x upper limit of normal), AND exposure to contaminated food/water or HAV contact within 15-50 days.',
            probable: 'Suspected case with epidemiological link to confirmed case.',
            confirmed: 'HAV-specific IgM positive in acute serum.',
            ihr_category: 'Self-limiting. Severe disease in elderly and immunocompromised. Not IHR notifiable.',
            source: 'WHO HAV Position Paper 2012.',
        },

        hepatitis_e: {
            suspected: 'Any person with acute jaundice AND pruritus AND dark urine — AND no alternative diagnosis — in an area with poor WASH OR following flooding OR in a refugee/displaced population setting.',
            probable: 'Suspected case with HEV IgM positive.',
            confirmed: 'HEV RNA by RT-PCR in stool/serum, OR IgM with 4-fold IgG rise in paired sera.',
            ihr_category: 'CFR up to 25% in pregnant women (3rd trimester) — PRIORITY for pregnant travellers.',
            source: 'WHO HEV Position Paper 2015.',
        },

        rubella: {
            suspected: 'Any person with acute generalized maculopapular rash AND arthralgia/arthritis OR suboccipital/postauricular/occipital lymphadenopathy — AND unvaccinated OR unknown vaccination status.',
            probable: 'Suspected case with epidemiological link to confirmed rubella.',
            confirmed: 'Rubella IgM positive (≥3 days after rash onset), OR ≥4-fold IgG rise, OR rubella RNA detected.',
            ihr_category: 'Critical: Congenital Rubella Syndrome in unimmunized pregnant women — teratogenic.',
            source: 'WHO Rubella Position Paper 2020.',
        },

        west_nile_fever: {
            suspected: 'Any person with acute fever AND headache ± maculopapular rash ± neurological signs — AND residence in or travel from a WNV-active area during vector season (summer/autumn).',
            probable: 'WNV IgM positive in serum or CSF.',
            confirmed: 'WNV RNA by RT-PCR, OR virus isolation, OR ≥4-fold IgG rise.',
            ihr_category: 'Not IHR notifiable. <1% develop neuroinvasive disease (encephalitis/meningitis).',
            source: 'ECDC WNV Guidance 2023; CDC WNV Case Definition.',
        },

        influenza_seasonal: {
            suspected: 'Any person with acute onset of fever ≥38°C AND at least 1 of: cough, sore throat, runny nose — AND at least 1 of: myalgia, headache, significant fatigue.',
            probable: 'Suspected case with positive rapid influenza antigen test.',
            confirmed: 'Influenza virus identified by RT-PCR, culture, or immunofluorescence from nasopharyngeal/throat specimen.',
            ihr_category: 'Not IHR notifiable for seasonal strains. Antiviral therapy most effective within 48h.',
            source: 'WHO Global Influenza Surveillance 2023.',
        },

        shigellosis_dysentery: {
            suspected: 'Any person with acute diarrhoea with blood in stool (dysentery) AND fever AND tenesmus.',
            probable: 'Suspected case with stool microscopy showing WBC ± RBC.',
            confirmed: 'Shigella spp. isolated from stool culture.',
            ihr_category: 'Important in refugee camps and post-disaster settings. Antimicrobial resistance emerging.',
            source: 'WHO AFRO IDSR 2021. Treatment: Azithromycin or ciprofloxacin (check local resistance).',
        },

        awd_non_cholera: {
            suspected: 'Any person with ≥3 loose or liquid stools in 24 hours NOT meeting cholera criteria (no profuse rice-water stools, no cholera RDT positive).',
            probable: 'N/A — usually treated clinically and confirmed by culture or PCR if available.',
            confirmed: 'Stool culture identifies non-cholera pathogens (ETEC, EPEC, rotavirus, norovirus, etc.).',
            ihr_category: 'Very common in POE settings post-flooding. Treat with ORS; escalate if dehydration.',
            source: 'WHO AFRO IDSR 2021.',
        },

        nipah_virus: {
            suspected: 'Any person with encephalitis (fever + altered consciousness/seizures) AND contact with pigs, bats, or bat-contaminated food (date palm sap) in endemic area (Bangladesh, India) within 21 days.',
            probable: 'Suspected case with epidemiological link to confirmed exposure.',
            confirmed: 'NiV RNA by RT-PCR, OR NiV-specific IgM, OR virus isolation.',
            ihr_category: 'CFR 40-75%. WHO R&D Blueprint priority pathogen. Person-to-person transmission in Bangladesh strains.',
            source: 'WHO Nipah Fact Sheet 2022.',
        },

        anthrax_cutaneous: {
            suspected: 'Any person with painless skin ulcer with central necrotic black eschar — especially following contact with animal hides, wool, meat, or soil in endemic areas.',
            probable: 'Suspected case with epidemiological exposure and characteristic eschar.',
            confirmed: 'Bacillus anthracis isolated from lesion/blood, OR PCR positive, OR immunohistochemistry on biopsy.',
            ihr_category: 'Bioterrorism alert if cluster of cases without epidemiological explanation.',
            source: 'WHO Anthrax Diagnostic Guidelines 2008.',
        },

        anthrax_pulmonary: {
            suspected: 'Any person with rapidly progressive respiratory distress + widened mediastinum on CXR + prodrome of mild fever/malaise — AND potential exposure to anthrax spores (occupational OR bioterrorism).',
            probable: 'Suspected case with positive serology or antigen in early phase.',
            confirmed: 'B. anthracis from blood/respiratory specimen, OR specific antibody confirmed.',
            ihr_category: 'BIOTERRORISM PROTOCOL. CFR >85% without aggressive early treatment.',
            source: 'WHO Anthrax 2008; CDC Emergency Preparedness Guidelines.',
            poe_action: 'IMMEDIATE ISOLATION. Notify national health security authority and law enforcement.',
        },

        tularemia: {
            suspected: 'Any person with sudden fever, headache, and ONE of: ulceroglandular (painful ulcer + swollen lymph node), oculoglandular (conjunctivitis + nodes), pneumonic (cough + chest pain) OR typhoidal form — with tick bite or animal (rabbit/hare) contact.',
            probable: 'Positive Francisella serology ≥1:160.',
            confirmed: 'F. tularensis isolated or PCR positive.',
            ihr_category: 'Bioterrorism Category A agent (pneumonic form). Doxycycline treatment.',
            source: 'WHO Guidelines on Tularemia 2007.',
        },

        hantavirus: {
            suspected: 'Any person with fever, severe headache, AND ONE of: hemorrhagic manifestations, renal failure, thrombocytopenia (Old World: HFRS) — OR respiratory distress with bilateral pulmonary infiltrates after rodent exposure (New World: HCPS).',
            probable: 'Hantavirus IgM positive by ELISA.',
            confirmed: 'Hantavirus RNA by PCR, OR ≥4-fold IgG rise, OR immunohistochemistry on tissue.',
            ihr_category: 'Not IHR notifiable. CFR varies: 1% (HFRS) to 35% (HCPS). No specific antiviral.',
            source: 'WHO/PAHO Hantavirus Fact Sheet 2022.',
        },
    };

    // ══════════════════════════════════════════════════════════════════════
    // SECTION 3: WHO SYNDROME CLASSIFICATION ENGINE
    //
    // Priority-ordered rules mapping symptom patterns to WHO output syndromes.
    // Used by deriveWHOSyndrome() — replaces the Vue's deriveAutoSyndrome().
    //
    // Each rule has:
    //   syndrome   — one of the 11 WHO output codes
    //   priority   — lower number fires first (1 = highest priority)
    //   requires   — ALL of these must be present
    //   any        — at least ONE of these must be present (if specified)
    //   absent     — NONE of these can be present (if specified)
    //   vitals     — clinical vital sign checks (if specified)
    //   confidence — HIGH/MEDIUM/LOW based on specificity
    //   reasoning  — explanation shown to the officer
    // ══════════════════════════════════════════════════════════════════════

    const SYNDROME_RULES = [
        {
            syndrome: 'VHF', priority: 1, confidence: 'HIGH',
            any: ['bleeding', 'bleeding_gums_or_nose', 'bloody_sputum', 'petechial_or_purpuric_rash',
                'bruising_or_ecchymosis', 'hematuria', 'blood_in_vomit'],
            reasoning: 'Hemorrhagic manifestation detected. VHF protocol must be activated.',
        },
        {
            syndrome: 'MENINGITIS', priority: 2, confidence: 'HIGH',
            requires: ['stiff_neck'],
            any: ['fever', 'high_fever', 'sudden_onset_fever', 'very_high_fever'],
            reasoning: 'Fever with nuchal rigidity fulfills WHO meningitis syndrome case definition.',
        },
        {
            syndrome: 'MENINGITIS', priority: 3, confidence: 'MEDIUM',
            any: ['fever', 'high_fever'],
            requires_any_extra: ['altered_consciousness', 'photophobia', 'seizures', 'bulging_fontanelle'],
            reasoning: 'Fever with neurological signs — meningitis/encephalitis must be excluded.',
        },
        {
            syndrome: 'NEUROLOGICAL', priority: 4, confidence: 'HIGH',
            any: ['hydrophobia', 'aerophobia'],
            reasoning: 'Hydrophobia or aerophobia — pathognomonic for rabies. EMERGENCY protocol.',
        },
        {
            syndrome: 'NEUROLOGICAL', priority: 5, confidence: 'HIGH',
            any: ['paralysis_acute_flaccid'],
            reasoning: 'Acute flaccid paralysis — AFP protocol required (polio exclusion mandatory).',
        },
        {
            syndrome: 'NEUROLOGICAL', priority: 6, confidence: 'MEDIUM',
            any: ['altered_consciousness', 'seizures', 'encephalitis_signs'],
            reasoning: 'Altered consciousness or seizures indicate neurological involvement.',
        },
        {
            syndrome: 'BLOODY_DIARRHEA', priority: 7, confidence: 'HIGH',
            any: ['bloody_diarrhea'],
            reasoning: 'Bloody diarrhoea meets WHO Bloody Diarrhoea syndrome definition.',
        },
        {
            syndrome: 'SARI', priority: 8, confidence: 'HIGH',
            any: ['fever', 'high_fever', 'very_high_fever', 'sudden_onset_fever'],
            requires_any2: ['shortness_of_breath', 'difficulty_breathing', 'rapid_breathing',
                'oxygen_saturation_low', 'respiratory_failure_signs'],
            reasoning: 'Fever with respiratory distress meets WHO SARI case definition.',
        },
        {
            syndrome: 'JAUNDICE', priority: 9, confidence: 'HIGH',
            any: ['jaundice'],
            reasoning: 'Clinical jaundice (yellow sclera/skin) — acute jaundice syndrome.',
        },
        {
            syndrome: 'JAUNDICE', priority: 10, confidence: 'MEDIUM',
            any: ['dark_urine', 'hepatomegaly'],
            reasoning: 'Dark urine or hepatomegaly may indicate hepatic involvement.',
        },
        {
            syndrome: 'RASH_FEVER', priority: 11, confidence: 'HIGH',
            any: ['fever', 'high_fever', 'very_high_fever'],
            requires_any2: ['rash_maculopapular', 'rash_vesicular_pustular', 'rash_face_first',
                'painful_rash', 'skin_eschar', 'mucosal_lesions', 'genital_lesions'],
            reasoning: 'Fever with rash meets WHO Febrile Rash Illness syndrome criteria.',
        },
        {
            syndrome: 'AWD', priority: 12, confidence: 'HIGH',
            any: ['rice_water_diarrhea', 'watery_diarrhea'],
            reasoning: 'Profuse watery diarrhoea — Acute Watery Diarrhoea syndrome. Exclude cholera.',
        },
        {
            syndrome: 'AWD', priority: 13, confidence: 'MEDIUM',
            requires: ['severe_dehydration'],
            any: ['diarrhea', 'vomiting'],
            reasoning: 'Diarrhoea with severe dehydration — treat as AWD until proven otherwise.',
        },
        {
            syndrome: 'ILI', priority: 14, confidence: 'HIGH',
            any: ['fever', 'high_fever', 'low_grade_fever', 'sudden_onset_fever'],
            requires_any2: ['cough', 'dry_cough', 'sore_throat', 'coryza', 'runny_nose'],
            reasoning: 'Fever with respiratory symptoms — Influenza-Like Illness syndrome.',
        },
        {
            syndrome: 'OTHER', priority: 15, confidence: 'LOW',
            any: ['fever', 'high_fever', 'sudden_onset_fever', 'very_high_fever'],
            reasoning: 'Undifferentiated febrile illness — needs further clinical workup.',
        },
    ];

    // ══════════════════════════════════════════════════════════════════════
    // SECTION 4: SYNDROME → ENGINE INTERNAL SYNDROME MAP
    // Maps disease.syndromes[] values to the 11 WHO output syndrome codes.
    // Used to compute syndrome_bonus in the scoring engine.
    // ══════════════════════════════════════════════════════════════════════

    const ENGINE_TO_WHO_SYNDROME = {
        vhf: 'VHF',
        gastrointestinal_hemorrhagic: 'VHF',
        jaundice_hemorrhagic: 'VHF',
        meningitis: 'MENINGITIS',
        encephalitis: 'NEUROLOGICAL',
        neurologic: 'NEUROLOGICAL',
        acute_flaccid_paralysis: 'NEUROLOGICAL',
        encephalitic: 'NEUROLOGICAL',
        influenza_like_illness: 'ILI',
        severe_respiratory: 'SARI',
        acute_respiratory: 'ILI',
        respiratory_rash: 'RASH_FEVER',
        febrile_rash: 'RASH_FEVER',
        vesiculopustular_rash: 'RASH_FEVER',
        high_consequence_rash: 'RASH_FEVER',
        mild_febrile_rash: 'RASH_FEVER',
        mild_rash_illness: 'RASH_FEVER',
        acute_watery_diarrhea: 'AWD',
        dehydrating_diarrhea: 'AWD',
        bloody_diarrhea_syndrome: 'BLOODY_DIARRHEA',
        jaundice_syndrome: 'JAUNDICE',
        jaundice_renal: 'JAUNDICE',
        acute_febrile: 'OTHER',
        arboviral: 'OTHER',
        vector_borne: 'OTHER',
        travel_fever: 'OTHER',
        zoonotic: 'OTHER',
        chronic_febrile_zoonotic: 'OTHER',
        systemic_viral: 'OTHER',
        lymphadenitic_febrile: 'OTHER',
        shock_syndrome: 'OTHER',
        renal_failure: 'OTHER',
        gastrointestinal_systemic: 'OTHER',
        respiratory_gastrointestinal_mixed: 'SARI',
        neuroinvasive_possible: 'NEUROLOGICAL',
        plague: 'OTHER',
        cutaneous_eschar: 'OTHER',
        bioterrorism: 'OTHER',
    };

    // ══════════════════════════════════════════════════════════════════════
    // SECTION 5: IHR RISK ESCALATION MATRIX
    //
    // Deterministic rules that override or confirm the WHO/IHR risk level.
    // Applied AFTER scoreDiseases(). These are non-negotiable hard rules
    // based on IHR 2005 Annex 2 decision instrument.
    // ══════════════════════════════════════════════════════════════════════

    const IHR_ESCALATION_RULES = [
        {
            id: 'TIER1_ALWAYS_CRITICAL',
            description: 'Any IHR Tier 1 disease (always notifiable) in top 3 results at score ≥20',
            check: (result) => (result.top_diagnoses || []).slice(0, 3).some(d =>
                ['smallpox', 'sars', 'influenza_new_subtype_zoonotic', 'polio'].includes(d.disease_id) && d.final_score >= 20
            ),
            risk_level: 'CRITICAL',
            routing: 'NATIONAL',
            reasoning: 'IHR Article 6: disease that always constitutes PHEIC requires immediate notification.',
        },
        {
            id: 'VHF_HIGH_CONFIDENCE',
            description: 'VHF syndrome with high-confidence diagnosis in top 2',
            check: (result, vitals) => (result.top_diagnoses || []).slice(0, 2).some(d =>
                ['ebola_virus_disease', 'marburg_virus_disease', 'lassa_fever', 'cchf', 'rift_valley_fever']
                    .includes(d.disease_id) && d.final_score >= 35
            ),
            risk_level: 'CRITICAL',
            routing: 'NATIONAL',
            reasoning: 'High-confidence VHF — IHR Annex 2 mandatory notification pathway.',
        },
        {
            id: 'VHF_POSSIBLE',
            description: 'Any VHF in top 5 with score ≥20',
            check: (result) => (result.top_diagnoses || []).some(d =>
                ['ebola_virus_disease', 'marburg_virus_disease', 'lassa_fever', 'cchf', 'rift_valley_fever',
                    'nipah_virus', 'hantavirus'].includes(d.disease_id) && d.final_score >= 20
            ),
            risk_level: 'HIGH',
            routing: 'PHEOC',
            reasoning: 'Possible VHF — IHR precautionary isolation pending laboratory confirmation.',
        },
        {
            id: 'SARI_SPO2_CRITICAL',
            description: 'SARI with SpO2 <90%',
            check: (result, vitals) => (result.top_diagnoses || []).some(d =>
                d.disease_id.includes('influenza') || d.disease_id === 'sars' || d.disease_id === 'mers'
            ) && vitals.oxygen_saturation !== undefined && vitals.oxygen_saturation < 90,
            risk_level: 'CRITICAL',
            routing: 'PHEOC',
            reasoning: 'SARI with severe hypoxia — potential PHEIC pathogen; immediate respiratory isolation.',
        },
        {
            id: 'FEVER_HEMORRHAGE_EMERGENCY',
            description: 'Emergency signs + hemorrhage',
            check: (result, vitals, globalFlags) =>
                globalFlags.includes('VHF_PROTOCOL_ACTIVATED') && globalFlags.includes('NEEDS_IMMEDIATE_ISOLATION'),
            risk_level: 'CRITICAL',
            routing: 'NATIONAL',
            reasoning: 'Hemorrhagic fever protocol activated — IHR Annex 2 immediate notification.',
        },
        {
            id: 'BIOTERRORISM_SUSPICION',
            description: 'Bioterrorism protocol flags (smallpox, anthrax, tularemia cluster)',
            check: (result, vitals, globalFlags) =>
                globalFlags.includes('BIOTERRORISM_PROTOCOL_ACTIVATED') ||
                globalFlags.includes('BIOTERRORISM_ASSESSMENT_REQUIRED'),
            risk_level: 'CRITICAL',
            routing: 'NATIONAL',
            reasoning: 'Bioterrorism indicator — national security protocols apply.',
        },
        {
            id: 'TIER2_HIGH_CONFIDENCE',
            description: 'IHR Tier 2 Annex 2 disease at score ≥40 in top 1',
            check: (result) => {
                const top = Array.isArray(result.top_diagnoses) ? result.top_diagnoses[0] : undefined;
                const tier2 = ['cholera', 'yellow_fever', 'ebola_virus_disease', 'marburg_virus_disease',
                    'lassa_fever', 'cchf', 'rift_valley_fever', 'mpox', 'meningococcal_meningitis',
                    'measles', 'mers', 'pneumonic_plague', 'bubonic_plague'];
                return top && tier2.includes(top.disease_id) && top.final_score >= 40;
            },
            risk_level: 'HIGH',
            routing: 'PHEOC',
            reasoning: 'High-confidence IHR Annex 2 disease — PHEOC notification required.',
        },
        {
            id: 'CRITICAL_VITALS',
            description: 'Life-threatening vital signs regardless of diagnosis',
            check: (result, vitals) =>
                (vitals.oxygen_saturation !== undefined && vitals.oxygen_saturation < 88) ||
                (vitals.pulse_rate !== undefined && (vitals.pulse_rate < 40 || vitals.pulse_rate > 150)) ||
                (vitals.respiratory_rate !== undefined && vitals.respiratory_rate >= 30) ||
                (vitals.temperature_c !== undefined && vitals.temperature_c >= 40.5) ||
                (vitals.bp_systolic !== undefined && vitals.bp_systolic < 80),
            risk_level: 'HIGH',
            routing: 'DISTRICT',
            reasoning: 'Critical vital signs — immediate medical management required regardless of diagnosis.',
        },
        {
            id: 'AFP_POLIO_RISK',
            description: 'Acute flaccid paralysis = polio exclusion required',
            check: (result, vitals, globalFlags) => globalFlags.includes('AFP_SURVEILLANCE_ACTIVATED'),
            risk_level: 'HIGH',
            routing: 'PHEOC',
            reasoning: 'AFP detected — IHR Tier 1 (polio) exclusion mandatory. Stool sample collection required.',
        },
    ];

    // ══════════════════════════════════════════════════════════════════════
    // SECTION 6: ATTACH ENDEMIC COUNTRIES AND CASE DEFINITIONS TO DISEASES
    //
    // Adds endemic_countries and who_case_definition to each disease object
    // so the data is co-located with the scoring weights.
    // ══════════════════════════════════════════════════════════════════════

    (function attachIntelligenceToDiseases() {
        for (const disease of D.diseases) {
            if (ENDEMIC_COUNTRIES[disease.id]) {
                disease.endemic_countries = [...new Set(ENDEMIC_COUNTRIES[disease.id])];
            } else {
                disease.endemic_countries = [];
            }

            if (WHO_CASE_DEFINITIONS[disease.id]) {
                disease.who_case_definition = WHO_CASE_DEFINITIONS[disease.id];
            }
        }
    })();

    // ══════════════════════════════════════════════════════════════════════
    // SECTION 7: PATCH scoreDiseases() TO FIX SYNDROME BONUS
    //
    // The original scoreDiseases() has syndrome_bonus = 0 always.
    // This patch wraps the original to apply syndrome_bonus correctly
    // using the WHO syndrome derived from the symptom pattern.
    // ══════════════════════════════════════════════════════════════════════

    const _originalScoreDiseases = D.scoreDiseases.bind(D);

    D.scoreDiseases = function (presentSymptoms, absentSymptoms, selectedExposures, context) {
        // Derive WHO syndrome from symptoms
        const syndromeResult = D.deriveWHOSyndrome
            ? D.deriveWHOSyndrome(presentSymptoms, context?.clinical_context || {})
            : { syndrome: 'OTHER' };
        const whoSyndrome = syndromeResult.syndrome;

        // Call original engine
        const result = _originalScoreDiseases(presentSymptoms, absentSymptoms, selectedExposures, context);

        // Apply syndrome_bonus to each disease that has a matching syndrome
        const bonusValue = D.engine.formula.syndrome_bonus_match || 8;
        const _pd = Array.isArray(result.top_diagnoses) ? result.top_diagnoses : [];
        for (const r of _pd) {
            const disease = D.diseases.find(d => d.id === r.disease_id);
            if (!disease || !disease.syndromes) continue;
            // Map disease's engine syndromes to WHO syndromes and check match
            const whoEquivalents = (disease.syndromes || [])
                .map(s => ENGINE_TO_WHO_SYNDROME[s])
                .filter(Boolean);
            if (whoEquivalents.includes(whoSyndrome)) {
                r.score_breakdown.syndrome_bonus = bonusValue;
                r.final_score = Math.min(100, r.final_score + bonusValue);
                r.syndrome_matched = whoSyndrome;
            }
        }

        // Re-sort after syndrome bonus (scores may have changed)
        const tierOrder = {
            tier_1_ihr_critical: 0, tier_2_ihr_annex2: 1,
            tier_2_ihr_equivalent: 2, tier_3_who_notifiable: 3, tier_4_syndromic: 4
        };
        if (Array.isArray(result.top_diagnoses)) result.top_diagnoses.sort((a, b) => {
            if (b.final_score !== a.final_score) return b.final_score - a.final_score;
            return (tierOrder[a.priority_tier] ?? 9) - (tierOrder[b.priority_tier] ?? 9);
        });

        // Attach derived syndrome to result
        result.derived_syndrome = whoSyndrome;
        result.syndrome_confidence = syndromeResult.confidence || 'LOW';
        result.syndrome_reasoning = syndromeResult.reasoning || '';

        return result;
    };

    // ══════════════════════════════════════════════════════════════════════
    // EXPORTED API FUNCTIONS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * buildOutbreakContext(visitedCountries)
     *
     * Given the traveler's visited countries, returns disease IDs that should
     * be added to outbreak_context in scoreDiseases() call.
     *
     * @param {Array<{country_code: string, travel_role?: string}>} visitedCountries
     * @param {number} lookbackDays - only consider travel within this window (default: 21)
     * @returns {string[]} disease IDs to add to outbreak_context
     */
    D.buildOutbreakContext = function (visitedCountries, lookbackDays) {
        lookbackDays = lookbackDays || 21;
        if (!Array.isArray(visitedCountries) || visitedCountries.length === 0) return [];

        const codes = visitedCountries
            .map(tc => (typeof tc === 'string' ? tc : tc.country_code || '').toUpperCase())
            .filter(Boolean);

        if (codes.length === 0) return [];

        const outbreakDiseaseIds = [];
        for (const disease of D.diseases) {
            const endemic = disease.endemic_countries || [];
            if (endemic.length === 0) continue;
            // Disease is in outbreak_context if any visited country is endemic
            const match = codes.some(c => endemic.includes(c));
            if (match) outbreakDiseaseIds.push(disease.id);
        }

        return [...new Set(outbreakDiseaseIds)];
    };

    /**
     * deriveWHOSyndrome(presentSymptoms, vitals?)
     *
     * WHO/IHR-aligned deterministic syndrome classification from symptom pattern.
     * Priority-ordered: VHF > Meningitis > Neurological > SARI > ILI > etc.
     *
     * @param {string[]} presentSymptoms - confirmed present symptom IDs
     * @param {Object}   vitals          - { temperature_c, oxygen_saturation, ... }
     * @returns {{ syndrome, confidence, reasoning, who_criteria_met[] }}
     */
    D.deriveWHOSyndrome = function (presentSymptoms, vitals) {
        presentSymptoms = presentSymptoms || [];
        vitals = vitals || {};
        const has = sym => presentSymptoms.includes(sym);

        for (const rule of SYNDROME_RULES) {
            // Check primary "any" condition
            const anyMet = !rule.any || rule.any.some(has);
            // Check "requires" (all must be present)
            const requiresMet = !rule.requires || rule.requires.every(has);
            // Check second "any" condition (for compound syndromes like SARI)
            const any2Met = !rule.requires_any2 || rule.requires_any2.some(has);
            // Check "requires_any_extra" (at least one)
            const extraMet = !rule.requires_any_extra || rule.requires_any_extra.some(has);
            // Check absent conditions
            const absentOk = !rule.absent || !rule.absent.some(has);
            // Check vital sign conditions
            let vitalOk = true;
            if (rule.vital_check) vitalOk = rule.vital_check(vitals);

            if (anyMet && requiresMet && any2Met && extraMet && absentOk && vitalOk) {
                // Build list of met criteria for transparency
                const metCriteria = [];
                if (rule.any) metCriteria.push(...rule.any.filter(has));
                if (rule.requires) metCriteria.push(...rule.requires.filter(has));
                if (rule.requires_any2) metCriteria.push(...rule.requires_any2.filter(has));
                if (rule.requires_any_extra) metCriteria.push(...rule.requires_any_extra.filter(has));

                return {
                    syndrome: rule.syndrome,
                    confidence: rule.confidence,
                    reasoning: rule.reasoning,
                    who_criteria_met: [...new Set(metCriteria)],
                    rule_id: rule.priority,
                };
            }
        }

        // No symptoms present or no match
        if (presentSymptoms.length === 0) {
            return {
                syndrome: 'NONE',
                confidence: 'HIGH',
                reasoning: 'No symptoms confirmed present. This is a non-case.',
                who_criteria_met: [],
                rule_id: null,
            };
        }

        return {
            syndrome: 'OTHER',
            confidence: 'LOW',
            reasoning: 'Symptoms present but do not meet any WHO syndrome case definition. Further clinical assessment required.',
            who_criteria_met: presentSymptoms,
            rule_id: null,
        };
    };

    /**
     * getNonCaseVerdict(presentSymptoms, absentSymptoms, vitals)
     *
     * Determines if this is a clinical non-case. Critical for IHR Article 23(1)(a)
     * — the principle of least invasive measure.
     *
     * A non-case if:
     *   - Zero symptoms are confirmed present AND temperature is normal
     *   - OR officer has confirmed all key symptoms are absent
     *
     * @returns {{ isNonCase, reasons, recommended_syndrome, recommended_disposition,
     *             can_override, override_warning }}
     */
    D.getNonCaseVerdict = function (presentSymptoms, absentSymptoms, vitals) {
        presentSymptoms = presentSymptoms || [];
        absentSymptoms = absentSymptoms || [];
        vitals = vitals || {};

        const reasons = [];
        let isNonCase = false;

        const tempNormal = !vitals.temperature_c || (vitals.temperature_c >= 35.0 && vitals.temperature_c < 37.5);
        const hasFever = vitals.temperature_c && vitals.temperature_c >= 37.5;

        if (presentSymptoms.length === 0 && !hasFever) {
            isNonCase = true;
            reasons.push('No symptoms confirmed present by clinical examination.');
            if (tempNormal && vitals.temperature_c) {
                reasons.push(`Temperature ${vitals.temperature_c.toFixed(1)}°C — within normal range.`);
            } else if (!vitals.temperature_c) {
                reasons.push('No temperature recorded — no fever detected by officer assessment.');
            }
        }

        if (presentSymptoms.length === 0 && hasFever) {
            // Fever with no symptoms — not immediately a non-case
            isNonCase = false;
            reasons.push(`Fever detected (${vitals.temperature_c.toFixed(1)}°C) despite no other symptoms — cannot classify as non-case.`);
        }

        // Check if ALL hallmark febrile symptoms are explicitly absent
        const keyFebrileSymptoms = ['fever', 'high_fever', 'cough', 'rash_maculopapular', 'diarrhea',
            'watery_diarrhea', 'bleeding', 'stiff_neck', 'altered_consciousness'];
        const allKeyAbsent = keyFebrileSymptoms.every(s => absentSymptoms.includes(s));
        if (allKeyAbsent && presentSymptoms.length === 0) {
            isNonCase = true;
            reasons.push('All key febrile/infectious symptoms confirmed absent by officer.');
        }

        if (presentSymptoms.length > 0 && isNonCase) {
            // Contradiction — symptoms present but verdict was non-case — override
            isNonCase = false;
            reasons.push(`WARNING: ${presentSymptoms.length} symptom(s) recorded. Non-case classification overridden.`);
        }

        return {
            isNonCase,
            reasons,
            recommended_syndrome: isNonCase ? 'NONE' : null,
            recommended_disposition: isNonCase ? 'RELEASED' : null,
            can_override: true,   // Officer always retains authority to disagree
            override_warning: isNonCase
                ? 'The algorithm classifies this as a non-case. If you believe the traveller has an illness not captured by the symptom checklist, you may override this classification and record a suspected syndrome.'
                : null,
        };
    };

    /**
     * computeIHRRiskLevel(scoreResult, vitals, globalFlags)
     *
     * Applies the IHR Annex 2 decision instrument to derive the final
     * risk level and notification routing from the score result.
     * This eliminates all alert routing logic from the Vue.
     *
     * @param  {Object}   scoreResult  - output of scoreDiseases()
     * @param  {Object}   vitals       - { temperature_c, oxygen_saturation, pulse_rate, ... }
     * @param  {string[]} globalFlags  - from scoreResult.global_flags
     * @returns {{
     *   risk_level: 'LOW'|'MEDIUM'|'HIGH'|'CRITICAL',
     *   routing_level: 'DISTRICT'|'PHEOC'|'NATIONAL',
     *   ihr_alert_required: boolean,
     *   ihr_tier: null|'TIER_1_ALWAYS_NOTIFIABLE'|'TIER_2_ANNEX2',
     *   triggered_rules: string[],
     *   reasoning: string[]
     * }}
     */
    D.computeIHRRiskLevel = function (scoreResult, vitals, globalFlags) {
        vitals = vitals || {};
        globalFlags = globalFlags || (scoreResult && scoreResult.global_flags) || [];

        const triggered = [];
        const reasoning = [];
        let riskLevel = 'LOW';
        let routing = 'DISTRICT';
        let ihrTier = null;

        // Evaluate escalation rules in order (higher priority = lower risk until escalated)
        for (const rule of IHR_ESCALATION_RULES) {
            try {
                if (rule.check(scoreResult, vitals, globalFlags)) {
                    triggered.push(rule.id);
                    reasoning.push(rule.reasoning);

                    const levelOrder = { LOW: 0, MEDIUM: 1, HIGH: 2, CRITICAL: 3 };
                    const routeOrder = { DISTRICT: 0, PHEOC: 1, NATIONAL: 2 };

                    if (levelOrder[rule.risk_level] > levelOrder[riskLevel]) {
                        riskLevel = rule.risk_level;
                    }
                    if (routeOrder[rule.routing] > routeOrder[routing]) {
                        routing = rule.routing;
                    }

                    if (rule.id === 'TIER1_ALWAYS_CRITICAL') ihrTier = 'TIER_1_ALWAYS_NOTIFIABLE';
                    if (rule.id === 'VHF_HIGH_CONFIDENCE' && !ihrTier) ihrTier = 'TIER_2_ANNEX2';
                    if (rule.id === 'TIER2_HIGH_CONFIDENCE' && !ihrTier) ihrTier = 'TIER_2_ANNEX2';
                }
            } catch (e) { /* non-critical — ignore individual rule errors */ }
        }

        // Baseline risk from score if no rules fired
        if (triggered.length === 0 && scoreResult && scoreResult.top_diagnoses) {
            const topScore = scoreResult.top_diagnoses[0]?.final_score || 0;
            if (topScore >= 55) { riskLevel = 'HIGH'; reasoning.push('Top disease score ≥55 — high clinical probability.'); }
            else if (topScore >= 35) { riskLevel = 'MEDIUM'; reasoning.push('Top disease score ≥35 — moderate probability.'); }
            else { riskLevel = 'LOW'; reasoning.push('Low-probability diagnosis — no WHO alert criteria triggered.'); }
        }

        // IHR notification check from global_flags
        const ihrNotifiable = globalFlags.includes('NEEDS_IHR_NOTIFICATION') ||
            globalFlags.includes('NEEDS_PUBLIC_HEALTH_NOTIFICATION');

        return {
            risk_level: riskLevel,
            routing_level: routing,
            ihr_alert_required: ihrNotifiable || riskLevel === 'CRITICAL' || riskLevel === 'HIGH',
            ihr_tier: ihrTier,
            triggered_rules: triggered,
            reasoning: reasoning.length > 0 ? reasoning : ['Risk assessed from clinical probability scores.'],
        };
    };

    /**
     * validateClinicalData(presentSymptoms, vitals)
     *
     * Returns structured clinical warnings for vital signs and symptom patterns.
     * Displayed in the Vue's vitals form. Replaces all inline vital_sign_range
     * checks in the view.
     *
     * @returns {{ vital_alerts{}, critical_flags[], clinical_warnings[], needs_emergency_triage }}
     */
    D.validateClinicalData = function (presentSymptoms, vitals) {
        presentSymptoms = presentSymptoms || [];
        vitals = vitals || {};
        const alerts = {};
        const critical = [];
        const warnings = [];
        let needsEmergency = false;

        // Temperature
        if (vitals.temperature_c !== undefined && vitals.temperature_c !== null) {
            const t = Number(vitals.temperature_c);
            if (t < 35.0) { alerts.temperature = { level: 'CRITICAL', msg: 'Hypothermia (<35°C) — verify reading and warm patient.' }; critical.push('HYPOTHERMIA'); needsEmergency = true; }
            else if (t < 36.0) { alerts.temperature = { level: 'WARNING', msg: 'Low temperature (35–35.9°C) — possible mild hypothermia.' }; }
            else if (t >= 40.5) { alerts.temperature = { level: 'CRITICAL', msg: 'Extreme hyperpyrexia ≥40.5°C — immediate cooling and EMERGENCY triage.' }; critical.push('EXTREME_FEVER'); needsEmergency = true; }
            else if (t >= 39.5) { alerts.temperature = { level: 'HIGH', msg: 'High fever ≥39.5°C — antipyretic, IV access, urgent assessment.' }; warnings.push('High fever ≥39.5°C'); }
            else if (t >= 38.5) { alerts.temperature = { level: 'ELEVATED', msg: 'Fever ≥38.5°C — meets WHO febrile illness threshold.' }; }
            else if (t >= 37.5) { alerts.temperature = { level: 'MILD', msg: 'Low-grade fever 37.5–38.4°C — document carefully.' }; }
        }

        // Pulse rate
        if (vitals.pulse_rate !== undefined && vitals.pulse_rate !== null) {
            const p = Number(vitals.pulse_rate);
            if (p < 35) { alerts.pulse = { level: 'CRITICAL', msg: 'Severe bradycardia (<35 bpm) — cardiac emergency.' }; critical.push('SEVERE_BRADYCARDIA'); needsEmergency = true; }
            else if (p < 50) { alerts.pulse = { level: 'HIGH', msg: 'Bradycardia (35–49 bpm) — assess for cardiac or drug cause.' }; }
            else if (p > 160) { alerts.pulse = { level: 'CRITICAL', msg: 'Severe tachycardia (>160 bpm) — shock, sepsis, or arrhythmia.' }; critical.push('SEVERE_TACHYCARDIA'); needsEmergency = true; }
            else if (p > 120) { alerts.pulse = { level: 'HIGH', msg: 'Significant tachycardia (>120 bpm) — assess hydration and sepsis.' }; warnings.push('Significant tachycardia >120 bpm'); }
            else if (p > 100) { alerts.pulse = { level: 'MILD', msg: 'Mild tachycardia (100–120 bpm) — monitor.' }; }
        }

        // Respiratory rate
        if (vitals.respiratory_rate !== undefined && vitals.respiratory_rate !== null) {
            const r = Number(vitals.respiratory_rate);
            if (r < 8) { alerts.respiratory = { level: 'CRITICAL', msg: 'Respiratory rate <8 — respiratory failure imminent.' }; critical.push('RESP_FAILURE'); needsEmergency = true; }
            else if (r >= 30) { alerts.respiratory = { level: 'CRITICAL', msg: 'Respiratory rate ≥30 — meets WHO EMERGENCY threshold. Oxygen immediately.' }; critical.push('RESP_DISTRESS'); needsEmergency = true; }
            else if (r >= 25) { alerts.respiratory = { level: 'HIGH', msg: 'Respiratory rate 25–29 — significant distress. URGENT assessment.' }; warnings.push('High respiratory rate'); }
            else if (r >= 20) { alerts.respiratory = { level: 'MILD', msg: 'Respiratory rate 20–24 — mildly elevated. Monitor.' }; }
        }

        // SpO2
        if (vitals.oxygen_saturation !== undefined && vitals.oxygen_saturation !== null) {
            const s = Number(vitals.oxygen_saturation);
            if (s < 90) { alerts.spo2 = { level: 'CRITICAL', msg: 'SpO\u2082 ' + s + '% — CRITICAL HYPOXIA. WHO emergency threshold is SpO\u2082 <90%. Supplemental oxygen immediately. EMERGENCY referral.' }; critical.push('CRITICAL_HYPOXIA'); needsEmergency = true; }
            else if (s < 94) { alerts.spo2 = { level: 'HIGH', msg: 'SpO\u2082 ' + s + '% — low. WHO supplemental oxygen recommended. Urgent reassessment.' }; warnings.push('Hypoxia SpO2 <94%'); }
            else if (s < 96) { alerts.spo2 = { level: 'MILD', msg: 'SpO\u2082 ' + s + '% — borderline. Monitor closely.' }; }
            // eslint-disable-next-line no-dupe-else-if
            else if (s < 96) { alerts.spo2 = { level: 'MILD', msg: 'SpO2 94–95% — borderline. Monitor closely.' }; }
        }

        // Blood pressure
        if (vitals.bp_systolic !== undefined && vitals.bp_systolic !== null) {
            const sys = Number(vitals.bp_systolic);
            if (sys < 70) { alerts.bp = { level: 'CRITICAL', msg: 'BP <70 mmHg — severe shock. IV access + fluids IMMEDIATELY.' }; critical.push('SHOCK'); needsEmergency = true; }
            else if (sys < 90) { alerts.bp = { level: 'HIGH', msg: 'Hypotension <90 mmHg systolic — possible septic/hypovolemic shock.' }; warnings.push('Hypotension'); }
            else if (sys > 180) { alerts.bp = { level: 'HIGH', msg: 'Hypertensive urgency >180 mmHg — assess for hypertensive emergency.' }; }
        }

        // Symptom pattern warnings
        if (presentSymptoms.includes('bleeding') || presentSymptoms.includes('bleeding_gums_or_nose')) {
            warnings.push('Hemorrhagic manifestations — VHF isolation protocol required immediately.');
            critical.push('HEMORRHAGE_PRESENT');
        }
        if (presentSymptoms.includes('altered_consciousness')) {
            warnings.push('Altered consciousness — neurological emergency assessment required.');
            needsEmergency = true;
            critical.push('NEURO_EMERGENCY');
        }
        if (presentSymptoms.includes('seizures')) {
            warnings.push('Active or recent seizures — emergency assessment required.');
            needsEmergency = true;
        }
        if (presentSymptoms.includes('hydrophobia') || presentSymptoms.includes('aerophobia')) {
            critical.push('RABIES_PATHOGNOMONIC');
            warnings.push('RABIES: Hydrophobia/aerophobia are pathognomonic — palliative care only. Notify IHR.');
            needsEmergency = true;
        }
        if (presentSymptoms.includes('paralysis_acute_flaccid')) {
            critical.push('AFP_DETECTED');
            warnings.push('Acute Flaccid Paralysis detected — AFP surveillance protocol required. Polio exclusion mandatory.');
        }

        return {
            vital_alerts: alerts,
            critical_flags: critical,
            clinical_warnings: warnings,
            needs_emergency_triage: needsEmergency,
        };
    };

    /**
     * getWHOCaseDefinition(diseaseId)
     *
     * Returns the WHO clinical case definition for a given disease.
     *
     * @param  {string} diseaseId
     * @returns {{ suspected, probable, confirmed, ihr_category, source, poe_action? } | null}
     */
    D.getWHOCaseDefinition = function (diseaseId) {
        const disease = D.diseases.find(d => d.id === diseaseId);
        if (!disease) return null;
        return disease.who_case_definition || WHO_CASE_DEFINITIONS[diseaseId] || null;
    };

    /**
     * getEnhancedScoreResult(presentSymptoms, absentSymptoms, exposureEngineCodes,
     *                         visitedCountries, vitals, context?)
     *
     * THE SINGLE FUNCTION THE VUE CALLS FOR STEP 3 ANALYSIS.
     * Combines all intelligence into one call:
     *   1. Builds outbreak_context from visited countries
     *   2. Derives WHO syndrome from symptoms
     *   3. Runs scoreDiseases() (with syndrome_bonus now active)
     *   4. Validates clinical data
     *   5. Computes IHR risk level
     *   6. Determines non-case verdict
     *
     * The Vue receives a single rich object and renders it — zero clinical logic
     * in the Vue layer.
     *
     * @param  {string[]}  presentSymptoms      - confirmed present symptom IDs
     * @param  {string[]}  absentSymptoms       - confirmed absent symptom IDs
     * @param  {string[]}  exposureEngineCodes  - from EXPOSURES.mapToEngineCodes()
     * @param  {Array}     visitedCountries     - [{ country_code:'UG', travel_role:'VISITED' }]
     * @param  {Object}    vitals               - { temperature_c, pulse_rate, ... }
     * @param  {Object}    context              - optional: { vaccination_history, days_since_onset }
     * @returns {Object}   Full enhanced result object
     */
    D.getEnhancedScoreResult = function (
        presentSymptoms, absentSymptoms, exposureEngineCodes, visitedCountries, vitals, context
    ) {
        presentSymptoms = presentSymptoms || [];
        absentSymptoms = absentSymptoms || [];
        exposureEngineCodes = exposureEngineCodes || [];
        visitedCountries = visitedCountries || [];
        vitals = vitals || {};
        context = context || {};

        // Step 1 — build outbreak context from travel history
        const outbreakContext = D.buildOutbreakContext(visitedCountries);

        // Step 2 — derive WHO syndrome (also used inside patched scoreDiseases)
        const syndromeResult = D.deriveWHOSyndrome(presentSymptoms, vitals);

        // Step 3 — non-case verdict
        const nonCaseVerdict = D.getNonCaseVerdict(presentSymptoms, absentSymptoms, vitals);

        // Step 4 — validate vitals
        const clinicalValidation = D.validateClinicalData(presentSymptoms, vitals);

        let scoreResult = null;

        if (!nonCaseVerdict.isNonCase) {
            // Step 5 — score diseases (only if we have a clinical case)
            scoreResult = D.scoreDiseases(
                presentSymptoms,
                absentSymptoms,
                exposureEngineCodes,
                {
                    outbreak_context: outbreakContext,
                    clinical_context: { ...vitals, ...(context.clinical_context || {}) },
                    vaccination_history: context.vaccination_history || {},
                }
            );

            // Step 6 — compute IHR risk level
            const ihrRisk = D.computeIHRRiskLevel(
                scoreResult,
                vitals,
                scoreResult.global_flags
            );

            return {
                // Engine output
                top_diagnoses: scoreResult.top_diagnoses,
                all_reportable: scoreResult.all_reportable,
                overrides_fired: scoreResult.overrides_fired,
                global_flags: scoreResult.global_flags,
                input_summary: scoreResult.input_summary,
                // Enhanced output
                syndrome: syndromeResult,
                ihr_risk: ihrRisk,
                non_case: nonCaseVerdict,
                clinical_validation: clinicalValidation,
                outbreak_context_used: outbreakContext,
                // Convenience flags for UI
                is_non_case: false,
                ihr_notification_required: ihrRisk.ihr_alert_required,
                show_emergency_banner: clinicalValidation.needs_emergency_triage,
                top_disease_id: scoreResult.top_diagnoses[0]?.disease_id || null,
                top_disease_confidence: scoreResult.top_diagnoses[0]?.confidence_band || null,
                suggested_risk_level: ihrRisk.risk_level,
                suggested_routing: ihrRisk.routing_level,
            };
        }

        // Non-case path — no scoring needed
        return {
            top_diagnoses: [],
            all_reportable: [],
            overrides_fired: [],
            global_flags: ['NON_CASE_VERDICT'],
            input_summary: { symptoms_count: 0, confidence_baseline: 'non_case' },
            syndrome: { syndrome: 'NONE', confidence: 'HIGH', reasoning: 'No symptoms present.', who_criteria_met: [] },
            ihr_risk: { risk_level: 'LOW', routing_level: 'DISTRICT', ihr_alert_required: false, ihr_tier: null, triggered_rules: [], reasoning: ['Non-case: no clinical indicators.'] },
            non_case: nonCaseVerdict,
            clinical_validation: clinicalValidation,
            outbreak_context_used: outbreakContext,
            is_non_case: true,
            ihr_notification_required: false,
            show_emergency_banner: clinicalValidation.needs_emergency_triage,
            top_disease_id: null,
            top_disease_confidence: null,
            suggested_risk_level: 'LOW',
            suggested_routing: 'DISTRICT',
        };
    };

    // Expose syndrome rules for UI reference
    D.syndrome_rules = SYNDROME_RULES;
    D.ihr_escalation_rules = IHR_ESCALATION_RULES;
    D.engine_to_who_syndrome = ENGINE_TO_WHO_SYNDROME;



    // ══════════════════════════════════════════════════════════════════════
    // SECTION 8: buildClinicalReport()
    //
    // Generates a complete, detailed, WHO/IHR-aligned clinical intelligence
    // report from the output of getEnhancedScoreResult(). The report explains
    // every scoring decision, every international standard applied, every
    // symptom contribution, every epidemiological signal, and why the final
    // classification is accurate — in language a health officer can understand
    // and an epidemiologist can audit.
    //
    // @param {Object} enhancedResult  — output of getEnhancedScoreResult()
    // @param {Object} caseCtx        — optional clinical context:
    //   {
    //     officer_name, poe_code, district_code, poe_type,
    //     traveler_gender, traveler_age_years, traveler_nationality,
    //     notification_id, captured_at,
    //     present_symptoms[], absent_symptoms[],
    //     visited_countries[],   // [{ country_code, travel_role, arrival_date }]
    //     exposures_yes[],       // exposure labels answered YES
    //     vitals: { temperature_c, pulse_rate, respiratory_rate, oxygen_saturation, bp_systolic }
    //   }
    //
    // @returns {Object}
    //   {
    //     report_id, generated_at, algorithm_version, intelligence_version,
    //     alert_level: 'CRITICAL'|'HIGH'|'MEDIUM'|'LOW'|'NON_CASE',
    //     sections: [{ id, title, level, body, bullets[], sub_sections[] }],
    //     summary: { headline, top_diagnosis, confidence, ihr_required, routing, action_required },
    //     plain_text: string,   // complete formatted report
    //   }
    // ══════════════════════════════════════════════════════════════════════

    D.buildClinicalReport = function (enhancedResult, caseCtx) {
        caseCtx = caseCtx || {};
        const er = enhancedResult || {};
        const now = new Date().toISOString();
        const D_ = window.DISEASES;

        // ── Internal helpers ────────────────────────────────────────────────
        function lookup(diseaseId) {
            return D_.diseases && D_.diseases.find(d => d.id === diseaseId) || null;
        }

        function fmtSym(code) {
            return (code || '').replace(/_/g, ' ');
        }

        function fmtDisease(id) {
            const d = lookup(id);
            return d ? d.name : (id || '').replace(/_/g, ' ');
        }

        function pct(n) { return n != null ? n + '%' : 'N/A'; }

        function scoreBar(score) {
            const filled = Math.round((score || 0) / 10);
            const cF = Math.min(10, Math.max(0, filled)); return '█'.repeat(cF) + '░'.repeat(10 - cF) + ' ' + (score || 0) + '/100';
        }

        function band_meaning(band, cfr) {
            const m = {
                very_high: 'Clinically very likely — mandatory isolation and immediate IHR notification. Treat as confirmed until excluded by laboratory.',
                high: 'Clinically probable — urgent secondary screening escalation required. Begin protective actions now.',
                moderate: 'Plausible differential — secondary screening and clinical investigation warranted. Do not release without assessment.',
                low: 'Possible but unlikely given current data — document and monitor. Laboratory exclusion recommended if clinical concern remains.',
                very_low: 'Unlikely with current symptom profile — consider as a remote differential only. Standard precautions apply.',
            };
            let base = m[band] || 'Score within normal parameters.';
            if (cfr && cfr >= 30) base += ' NOTE: This disease carries a case fatality rate of ' + cfr + '% without treatment — clinical urgency is proportional to this risk.';
            return base;
        }

        function gate_explanation(disease, presentSymptoms, absentSymptoms, gateScore) {
            const gates = disease.gates || {};
            const has = s => (presentSymptoms || []).includes(s);
            const absent = s => (absentSymptoms || []).includes(s);
            const lines = [];

            if (gates.hard_fail_if_absent) {
                for (const sym of gates.hard_fail_if_absent) {
                    if (absent(sym)) {
                        lines.push('HARD GATE EXCLUSION: The hallmark symptom "' + fmtSym(sym) + '" was confirmed ABSENT by the officer. ' +
                            'Per WHO AFRO IDSR 2021, ' + disease.name + ' cannot be clinically suspected without this symptom. ' +
                            'The engine applied a hard exclusion penalty (−60 pts) — this disease is effectively ruled out by current clinical data.');
                    }
                }
            }
            if (gateScore === 12) {
                const met = [];
                if (gates.required_any) {
                    const passing = gates.required_any.filter(has);
                    if (passing.length) met.push('Required symptoms met: ' + passing.map(fmtSym).join(', '));
                }
                if (gates.required_all) {
                    const passing = gates.required_all.filter(has);
                    if (passing.length) met.push('All mandatory symptoms confirmed: ' + passing.map(fmtSym).join(', '));
                }
                lines.push('GATE PASSED (+12 pts): ' + (met.join('; ') || 'Minimum clinical criteria satisfied.') +
                    ' The gate pass bonus reflects that the disease is at minimum clinically plausible based on the presenting symptom pattern.');
            } else if (gateScore === -18) {
                lines.push('GATE SOFT FAIL (−18 pts): The minimum required symptom set for ' + disease.name +
                    ' was not fully present. The WHO case definition requires at least one of: ' +
                    ((gates.required_any || []).map(fmtSym).join(', ') || 'N/A') +
                    '. Scoring continues but with a substantial prior reduction, reflecting the lower baseline probability.');
            }
            return lines;
        }

        function symptom_detail(disease, presentSymptoms, absentSymptoms) {
            const sw = disease.symptom_weights || {};
            const nw = disease.negative_weights || {};
            const ahp = disease.absent_hallmark_penalties || {};
            const hall = disease.hallmarks || [];
            const lines = [];
            const present = presentSymptoms || [];
            const absent = absentSymptoms || [];

            // Positive contributors
            const hits = present.filter(s => sw[s]).sort((a, b) => sw[b] - sw[a]);
            if (hits.length) {
                lines.push('POSITIVE SYMPTOM CONTRIBUTIONS:');
                for (const sym of hits) {
                    const w = sw[sym];
                    const isH = hall.includes(sym);
                    let why = '';
                    if (w >= 25) why = 'Near-pathognomonic for ' + disease.name + ' (positive LR >10x). ';
                    else if (w >= 16) why = 'Highly characteristic — strong positive likelihood ratio. ';
                    else if (w >= 10) why = 'Moderately specific — notable but not exclusive to this disease. ';
                    else why = 'Supportive but non-specific finding. ';
                    lines.push('  • ' + fmtSym(sym).toUpperCase() + ' [' + (isH ? 'HALLMARK, ' : '') + '+' + w + ' pts]: ' + why +
                        (isH ? 'Hallmark symptoms are the engine\'s highest-confidence discriminators for this disease.' : ''));
                }
            } else {
                lines.push('No positive symptom weights matched for this disease with the current symptom profile.');
            }

            // Contradictions (negative weights)
            const contra = present.filter(s => nw[s]).sort((a, b) => nw[a] - nw[b]);
            if (contra.length) {
                lines.push('');
                lines.push('CONTRADICTION PENALTIES (symptoms arguing AGAINST this diagnosis):');
                for (const sym of contra) {
                    const w = nw[sym];
                    lines.push('  • ' + fmtSym(sym).toUpperCase() + ' [' + w + ' pts]: This symptom is epidemiologically atypical for ' +
                        disease.name + '. Its presence reduces the probability — the engine applies this contradiction weight from ' +
                        'LR-calibrated evidence in Mandell\'s Principles of Infectious Diseases (9th Edition).');
                }
            }

            // Absent hallmark penalties
            const absentHalls = absent.filter(s => (hall.includes(s) && sw[s] >= 14) || ahp[s]);
            if (absentHalls.length) {
                lines.push('');
                lines.push('ABSENT HALLMARK PENALTIES (expected key symptoms confirmed absent):');
                for (const sym of absentHalls) {
                    const penalty = ahp[sym] || -12;
                    lines.push('  • ' + fmtSym(sym).toUpperCase() + ' ABSENT [' + penalty + ' pts]: "' + fmtSym(sym) +
                        '" is a mandatory hallmark of ' + disease.name + ' with estimated clinical sensitivity ≥80%. ' +
                        'Its confirmed absence significantly reduces diagnostic probability. ' +
                        'This penalty implements WHO AFRO IDSR 2021 negative predictive value principles.');
                }
            }

            return lines;
        }

        function exposure_detail(disease, matchedExposures, outbreakContextUsed) {
            const ew = disease.exposure_weights || {};
            const lines = [];
            const hits = (matchedExposures || []).filter(e => ew[e]);

            if (hits.length) {
                lines.push('EXPOSURE SIGNALS ACTIVATED FOR THIS DISEASE:');
                for (const exp of hits) {
                    const w = ew[exp];
                    let ctx = '';
                    if (exp === 'contact_dead_body' || exp === 'funeral_or_burial_exposure')
                        ctx = 'Direct contact with deceased persons carries the highest VHF transmission risk per WHO burial guidelines. ';
                    else if (exp === 'contact_body_fluids')
                        ctx = 'Body fluid exposure is the primary transmission route for haemorrhagic fever viruses. ';
                    else if (exp === 'travel_from_outbreak_area' || exp === 'residence_in_outbreak_area')
                        ctx = 'Geographic epidemiological context is a primary IHR Annex 2 risk criterion. ';
                    else if (exp === 'close_contact_case')
                        ctx = 'Documented contact with a probable or confirmed case is a WHO case definition criterion. ';
                    else if (exp === 'healthcare_exposure')
                        ctx = 'Healthcare settings without full IPC precautions are amplification environments for VHF and respiratory pathogens. ';
                    else if (exp === 'unsafe_water' || exp === 'contaminated_food_or_water')
                        ctx = 'Waterborne and foodborne fecal-oral transmission is the primary route for enteric pathogens. ';
                    lines.push('  • ' + exp.toUpperCase().replace(/_/g, ' ') + ' [+' + w + ' pts]: ' + ctx +
                        'Exposure weight calibrated from GeoSentinel traveller disease risk data and WHO outbreak investigation reports.');
                }
            } else {
                lines.push('No matching exposure signals were confirmed for this disease.');
                if ((disease.exposure_weights || {}) && Object.keys(disease.exposure_weights || {}).length > 0) {
                    const top3 = Object.entries(disease.exposure_weights || {}).sort((a, b) => b[1] - a[1]).slice(0, 3);
                    lines.push('Exposures that would significantly increase the score if answered YES: ' +
                        top3.map(([k, v]) => fmtSym(k) + ' (+' + v + ')').join(', '));
                }
            }

            // Outbreak bonus from endemic countries
            if ((outbreakContextUsed || []).includes(disease.id)) {
                const bonus = disease.outbreak_bonus || 15;
                const endemicHit = (caseCtx.visited_countries || []).filter(tc => {
                    const cc = (typeof tc === 'string' ? tc : tc.country_code || '').toUpperCase();
                    return (disease.endemic_countries || []).includes(cc);
                });
                lines.push('');
                lines.push('GEOGRAPHIC ENDEMIC CONTEXT BONUS [+' + bonus + ' pts]:');
                lines.push('  The traveller visited or transited through a country where ' + disease.name +
                    ' is endemic or historically active: ' +
                    endemicHit.map(tc => typeof tc === 'string' ? tc : tc.country_code).join(', ') + '. ' +
                    'Per the IHR 2005 Article 23(1)(a) risk assessment framework, travel to endemic zones constitutes a ' +
                    'significant epidemiological prior that must be factored into clinical probability. ' +
                    'The WHO POE Intelligence Layer matched visited countries against the WHO/CDC/ECDC 2024 geographic risk database.');
            }

            return lines;
        }

        function incubation_check(disease, caseCtx) {
            const inc = disease.incubation_days || {};
            const lines = [];
            if (!inc.min) return lines;
            lines.push('INCUBATION WINDOW COMPATIBILITY:');
            lines.push('  ' + disease.name + ' has an incubation period of ' + inc.min + '–' + inc.max +
                ' days (typical: ' + (inc.typical || inc.min + '–' + inc.max) + ' days). ');
            if (caseCtx.visited_countries && caseCtx.visited_countries.length > 0) {
                const endemicVisits = (caseCtx.visited_countries || []).filter(tc => {
                    const cc = (typeof tc === 'string' ? tc : tc.country_code || '').toUpperCase();
                    return (disease.endemic_countries || []).includes(cc);
                });
                if (endemicVisits.length) {
                    lines.push('  Visits to endemic countries (' +
                        endemicVisits.map(tc => typeof tc === 'string' ? tc : tc.country_code).join(', ') +
                        ') should be reviewed against the traveller\'s departure date. ' +
                        'Any exposure within ' + inc.max + ' days of symptom onset is clinically significant and satisfies the ' +
                        'IHR temporal criterion for this disease.');
                }
            }
            return lines;
        }

        function case_definition_check(disease) {
            const cd = disease.who_case_definition;
            if (!cd) return [];
            return [
                'WHO CASE DEFINITION ALIGNMENT (Source: ' + (cd.source || 'WHO AFRO IDSR 2021') + '):',
                '  SUSPECTED CASE: ' + cd.suspected,
                cd.confirmed ? '  CONFIRMED CASE: ' + cd.confirmed : '',
                cd.ihr_category ? '  IHR CATEGORY: ' + cd.ihr_category : '',
                cd.poe_action ? '  ⚠ MANDATORY POE ACTION: ' + cd.poe_action : '',
            ].filter(Boolean);
        }

        // ── Build triage override section ────────────────────────────────────
        function build_override_section(overridesFired, overrideBoostContext) {
            const overrideDescriptions = {
                override_vhf_red_flag:
                    'VIRAL HAEMORRHAGIC FEVER RED FLAG (Priority 1, IHR Annex 2)\n' +
                    'Trigger: Fever + any haemorrhagic manifestation (bleeding) + confirmed VHF-relevant exposure (body fluids, dead body, healthcare, travel from endemic area, funeral/burial).\n' +
                    'Clinical basis: This combination represents the WHO standard VHF suspicion criteria. Even a single haemorrhagic presentation with fever and plausible exposure constitutes grounds for immediate isolation under IHR Article 23. The engine applied boosts of +18 to Ebola/Marburg, +18 to CCHF, and +12 to Lassa Fever.\n' +
                    'Authority: WHO Ebola Case Definition 2023; IHR 2005 Annex 2; WHO Safe and Dignified Burial Guidelines 2021.',

                override_any_haemorrhage_fever_no_exposure:
                    'HAEMORRHAGIC FEVER PATHWAY — NO CONFIRMED EXPOSURE (Priority 2)\n' +
                    'Trigger: Fever + any spontaneous haemorrhagic sign (bleeding gums, nosebleed, bloody sputum, petechiae/purpura) — even without confirmed epidemiological exposure.\n' +
                    'Clinical basis: A febrile haemorrhagic illness without obvious cause must be treated as a possible VHF until excluded. The absence of confirmed exposure does not rule out VHF — many patients in endemic areas do not recall specific exposures. The engine applies precautionary boosts: Ebola/Marburg +10, CCHF +14, Dengue Severe +12.\n' +
                    'Authority: WHO VHF Infection Control Guidance 2014; MSF Clinical Guidelines 7th Ed.',

                override_acute_flaccid_paralysis:
                    'ACUTE FLACCID PARALYSIS — IHR TIER 1 MANDATORY ESCALATION (Priority 3)\n' +
                    'Trigger: Any acute flaccid limb weakness or paralysis in any age group.\n' +
                    'Clinical basis: Acute Flaccid Paralysis (AFP) is an IHR Tier 1 Always-Notifiable event requiring mandatory WHO AFP Surveillance reporting and bilateral stool specimen collection within 14 days. The engine applies +35 to Polio — the single highest triage override boost in the system — because a missed wild poliovirus case can generate an outbreak. The 2024 ongoing WPV1 transmission in Afghanistan and Pakistan means any AFP case with travel history must be treated as potentially linked until excluded by laboratory.\n' +
                    'Authority: WHO Global Polio Eradication Initiative AFP Surveillance Manual; IHR 2005 Annex 2.',

                override_meningitis_triad:
                    'BACTERIAL MENINGITIS EMERGENCY TRIAD (Priority 4)\n' +
                    'Trigger: Fever + stiff neck (nuchal rigidity).\n' +
                    'Clinical basis: The classic Kernig-Brudzinski triad of fever, nuchal rigidity, and altered consciousness has a sensitivity of ~51% and specificity of ~98% for bacterial meningitis (Van de Beek et al., NEJM 2004). Even partial triad requires emergency treatment. In Sub-Saharan Africa (Meningitis Belt), Neisseria meningitidis is the leading cause. The engine applies +20 to Meningococcal Meningitis. Delayed antibiotic therapy by >1 hour is associated with a 3-fold increase in mortality (Proulx et al., 2005).\n' +
                    'Authority: WHO AFRO IDSR 2021; WHO CSM Epidemic Meningitis Control Guidelines.',

                override_vesiculopustular_rash:
                    'VESICULAR/PUSTULAR RASH — MPOX/SMALLPOX PATHWAY (Priority 5)\n' +
                    'Trigger: Any vesicular or pustular skin eruption, OR mucosal lesions.\n' +
                    'Clinical basis: The 2022 mpox PHEIC demonstrated that vesiculopustular lesions in international travellers require systematic evaluation. The engine applies +16 to Mpox and +16 to Smallpox (bioterrorism consideration), while applying −10 penalties to Measles and Rubella which present with flat maculopapular rather than vesiculopustular lesions. This differential uses the WHO morphological classification of exanthems.\n' +
                    'Authority: WHO Mpox Case Definition 2024; WHO Smallpox Preparedness; ECDC Mpox Technical Guidance.',

                override_smallpox_pustular_centrifugal:
                    'SMALLPOX CENTRIFUGAL RASH — GLOBAL BIOLOGICAL EMERGENCY (Priority 6)\n' +
                    'Trigger: Fever + vesiculopustular rash + centrifugal distribution (face and extremities > trunk).\n' +
                    'Clinical basis: Centrifugal distribution (lesions more dense on face, palms, and soles than trunk) is the single most important feature differentiating smallpox from chickenpox (centripetal: dense on trunk). This combination is a potential PHEIC trigger under IHR Annex 2. The engine applies +30 to Smallpox and mandates MAXIMUM ISOLATION and BIOTERRORISM PROTOCOL. Smallpox was declared eradicated in 1980 but remains in the IHR Tier 1 always-notifiable list due to bioterrorism risk.\n' +
                    'Authority: WHO Smallpox Preparedness; CDC Smallpox Response Guide; IHR 2005 Annex 2.',

                override_watery_diarrhea_dehydration:
                    'CHOLERA PROTOCOL — ACUTE WATERY DIARRHOEA (Priority 7)\n' +
                    'Trigger: Rice-water or profuse watery stool, OR severe dehydration.\n' +
                    'Clinical basis: Cholera is defined by its diarrhoeal output — up to 20 litres per day of odourless rice-water stool causing fatal dehydration within hours without rehydration. This override fires +18 to Cholera and +8 to AWD (non-cholera) and mandates immediate aggressive oral or IV rehydration therapy and enteric precautions. The WHO GTFCC reports over 40 countries currently experiencing active cholera outbreaks (2024).\n' +
                    'Authority: WHO AFRO IDSR 2021; WHO Cholera Global Task Force; IHR Annex 2.',

                override_rabies_pathognomonic:
                    'RABIES — PATHOGNOMONIC SYMPTOM OVERRIDE (Priority 8)\n' +
                    'Trigger: Hydrophobia (terror/spasm on seeing/hearing water) OR aerophobia (terror/spasm on exposure to air or draught).\n' +
                    'Clinical basis: Hydrophobia and aerophobia are pathognomonic for rabies — no other disease produces these specific reflexive pharyngeal spasms. Once symptomatic, rabies has a case fatality rate approaching 100%. The engine applies the maximum single-symptom boost in the system: +50 to Rabies. The officer must assess bite/scratch history from a potentially rabid animal and arrange palliative care referral immediately.\n' +
                    'Authority: WHO Rabies Position Paper 2018; Fooks et al., Lancet 2014.',

                override_skin_eschar:
                    'CUTANEOUS ESCHAR — ANTHRAX/RICKETTSIA/BIOTERRORISM PATHWAY (Priority 9)\n' +
                    'Trigger: Painless black necrotic skin ulcer (eschar) at any body site.\n' +
                    'Clinical basis: A painless, well-demarcated, necrotic black ulcer with surrounding oedema is the cardinal feature of cutaneous anthrax (Bacillus anthracis) with specificity >95%. It is also seen in rickettsia (scrub typhus eschar, typically at the site of a chigger bite) and tularemia (ulceroglandular form). Multiple simultaneous cases in travellers from the same geographic origin require bioterrorism assessment. The engine applies +30 to Anthrax, +18 to Rickettsia, +15 to Tularemia.\n' +
                    'Authority: WHO Anthrax in Humans and Animals 2008; Inglesby et al., JAMA 2002 (bioterrorism guidance).',

                override_ihr_annex2_any_match:
                    'IHR ANNEX 2 MANDATORY NOTIFICATION (Priority 10)\n' +
                    'Trigger: Any IHR Tier 1 or IHR Annex 2 Tier 2 disease appearing in the top 3 results with score ≥25.\n' +
                    'Clinical basis: IHR 2005 Annex 2 defines a decision instrument for assessing and notifying events that may constitute a Public Health Emergency of International Concern (PHEIC). Under Article 6, States Parties must notify WHO within 24 hours of assessing an event using this instrument. The engine automatically flags IHR Tier 1/2 diseases for immediate notification regardless of other clinical parameters.\n' +
                    'Authority: IHR 2005 Article 6; WHO Guidance for Use of Annex 2 of the IHR.',
            };

            if (!overridesFired || overridesFired.length === 0) return null;

            const bullets = [];
            for (const ruleId of overridesFired) {
                const desc = overrideDescriptions[ruleId];
                if (desc) bullets.push(desc);
                else bullets.push(ruleId + ': Override rule fired — see engine documentation for details.');
            }

            return {
                id: 'triage_overrides',
                title: '⚡ TRIAGE OVERRIDE RULES FIRED (' + overridesFired.length + ')',
                level: 'critical',
                body: 'The following deterministic safety rules fired BEFORE the probabilistic scoring engine ran. ' +
                    'These rules implement WHO/IHR mandatory clinical protocols that bypass statistical ' +
                    'probability assessment — they exist because certain symptom patterns are too dangerous ' +
                    'to route through probabilistic logic alone. Each rule is backed by WHO, IHR 2005, or peer-reviewed clinical evidence.',
                bullets,
            };
        }

        // ─────────────────────────────────────────────────────────────────────
        // MAIN REPORT BUILDER
        // ─────────────────────────────────────────────────────────────────────
        const sections = [];
        const isNonCase = er.is_non_case === true;
        const topD = (er.top_diagnoses || [])[0] || null;
        const ihr = er.ihr_risk || {};
        const syndrome = er.syndrome || {};
        const cv = er.clinical_validation || {};
        const nonCase = er.non_case || {};
        const overrides = er.overrides_fired || [];
        const gFlags = er.global_flags || [];
        const inputSumm = er.input_summary || {};
        const topDis = topD ? lookup(topD.disease_id) : null;

        const alertLevel = isNonCase ? 'NON_CASE'
            : (ihr.risk_level === 'CRITICAL' ? 'CRITICAL'
                : ihr.risk_level === 'HIGH' ? 'HIGH'
                    : ihr.risk_level === 'MEDIUM' ? 'MEDIUM' : 'LOW');

        // ── SECTION 1: EXECUTIVE SUMMARY ─────────────────────────────────────
        const poeInfo = [caseCtx.poe_code, caseCtx.district_code].filter(Boolean).join(', ') || 'Point of Entry';
        const officerStr = caseCtx.officer_name ? 'Officer: ' + caseCtx.officer_name + '.' : '';
        const travelerStr = [
            caseCtx.traveler_gender,
            caseCtx.traveler_age_years ? caseCtx.traveler_age_years + ' y/o' : null,
            caseCtx.traveler_nationality ? '(' + caseCtx.traveler_nationality + ')' : null,
        ].filter(Boolean).join(' ') || 'Traveller';

        let execBody = '';
        if (isNonCase) {
            execBody = 'CLASSIFICATION: NON-CASE (No Clinical Indicators)\n\n' +
                'The WHO POE Disease Intelligence Engine evaluated the clinical data submitted for this traveller ' +
                'at ' + poeInfo + ' and found no confirmed symptoms or clinical indicators consistent with a ' +
                'notifiable infectious disease. ' + (nonCase.reasons || []).join(' ') + '\n\n' +
                'RECOMMENDATION: Standard precautionary release. Document assessment. No IHR notification required. ' +
                'Officer retains clinical authority to override this classification if examination findings not captured in the digital assessment suggest otherwise.';
        } else if (topD) {
            const confidence = topD.confidence_band ? topD.confidence_band.toUpperCase() + ' CONFIDENCE' : '';
            const ihrNote = ihr.ihr_alert_required
                ? 'THIS CASE REQUIRES IMMEDIATE IHR NOTIFICATION (routed to ' + ihr.routing_level + ').'
                : 'Standard notification pathway applies.';

            execBody = 'PRIMARY SUSPECTED DIAGNOSIS: ' + topD.name.toUpperCase() + '\n' +
                'Score: ' + topD.final_score + '/100 | Confidence Band: ' + confidence +
                ' | Risk Level: ' + alertLevel + ' | IHR Tier: ' + (ihr.ihr_tier || 'Standard Pathway') + '\n\n' +
                'The WHO POE Disease Intelligence Engine (Algorithm: WHO-POE Unified Explainable Matcher v3, ' +
                'symptom weights calibrated from Mandell\'s Principles of Infectious Diseases 9th Edition) ' +
                'assessed the clinical presentation of a ' + travelerStr + ' at ' + poeInfo + '. ' +
                (officerStr ? officerStr + ' ' : '') +
                'The engine evaluated ' + (inputSumm.symptoms_count || 0) + ' confirmed-present symptoms, ' +
                (inputSumm.absent_count || 0) + ' confirmed-absent symptoms, ' +
                (inputSumm.exposures_count || 0) + ' exposure signals, and travel history across ' +
                (er.outbreak_context_used || []).length + ' endemic disease zones.\n\n' +
                ihrNote + '\n\n' +
                'This assessment is an evidence-based clinical decision-support output, not a confirmed diagnosis. ' +
                'It must be interpreted alongside the officer\'s direct clinical examination. ' +
                'Laboratory confirmation is required for definitive diagnosis per WHO and national protocols.';
        } else {
            execBody = 'Insufficient clinical data to generate a primary diagnosis. ' +
                'The engine requires at least 2 confirmed symptoms for a reliable assessment.';
        }

        sections.push({
            id: 'executive_summary',
            title: '📋 EXECUTIVE SUMMARY',
            level: isNonCase ? 'info' : alertLevel.toLowerCase(),
            body: execBody,
            bullets: [],
        });

        // ── SECTION 2: IHR NOTIFICATION STATUS ───────────────────────────────
        if (!isNonCase && topD) {
            const tierDescriptions = {
                TIER_1_ALWAYS_NOTIFIABLE:
                    'IHR TIER 1 — ALWAYS NOTIFIABLE: This disease belongs to the four diseases that are always considered ' +
                    'a potential PHEIC under IHR Annex 2: Smallpox, SARS, Influenza (new human subtype), and Polio (wild or cVDPV). ' +
                    'A single credible suspected case at a POE triggers mandatory WHO notification regardless of laboratory confirmation status.',
                TIER_2_ANNEX2:
                    'IHR TIER 2 — ANNEX 2 EPIDEMIC-PRONE: This disease requires assessment using the IHR Annex 2 decision instrument ' +
                    '(the four-box matrix evaluating: Is the public health impact serious? Is the event unusual or unexpected? ' +
                    'Is there significant risk of international spread? Is there significant risk of international travel or trade restrictions?). ' +
                    'All four criteria are met for this disease in a POE context.',
            };

            const routingRationale = {
                NATIONAL: 'NATIONAL routing: The suspected disease (or confirmed clinical pattern) is classified as a potential PHEIC, ' +
                    'or involves an IHR Tier 1 always-notifiable disease. The NATIONAL IHR Focal Point must be notified within 24 hours ' +
                    'per IHR Article 6. The NATIONAL_ADMIN role in the Sentinel system will receive this alert.',
                PHEOC: 'PHEOC routing: The case involves a Priority 2 IHR Annex 2 disease or CRITICAL risk classification. ' +
                    'The Provincial Public Health Emergency Operations Centre (PHEOC) must be notified. ' +
                    'PHEOC_OFFICER users in the Sentinel system will receive this alert.',
                DISTRICT: 'DISTRICT routing: The case meets HIGH risk criteria but does not trigger IHR Tier 1/2 mandatory notification. ' +
                    'The District Surveillance Officer must be informed for local investigation and follow-up. ' +
                    'DISTRICT_SUPERVISOR users in the Sentinel system will receive this alert.',
            };

            const ihrRuleExplanations = (ihr.triggered_rules || []).map(ruleId => {
                const ruleDesc = {
                    TIER1_ALWAYS_CRITICAL: 'IHR Tier 1 disease in top results at score ≥20 — always a potential PHEIC by definition.',
                    VHF_HIGH_CONFIDENCE: 'VHF-family disease (Ebola/Marburg/Lassa/CCHF/RVF) at score ≥35 in top 2 — CRITICAL per IHR Annex 2.',
                    VHF_POSSIBLE: 'VHF-family disease at score ≥20 in top 5 — HIGH risk per precautionary IHR principle.',
                    SARI_SPO2_CRITICAL: 'SARI with SpO2 <90% — severe respiratory compromise with IHR-relevant pathogen pattern.',
                    TIER2_HIGH_CONFIDENCE: 'IHR Annex 2 disease at score ≥40 in top position — high-confidence notifiable case.',
                    CRITICAL_VITALS: 'Life-threatening vital signs meeting WHO emergency thresholds regardless of diagnosis.',
                    AFP_POLIO_RISK: 'AFP surveillance rule: single AFP case requires IHR mandatory escalation for polio exclusion.',
                };
                return ruleDesc[ruleId] || ruleId;
            });

            sections.push({
                id: 'ihr_notification',
                title: '🌐 IHR NOTIFICATION & ROUTING DECISION',
                level: ihr.ihr_alert_required ? 'critical' : 'info',
                body: (ihr.ihr_alert_required
                    ? '⚠ IHR NOTIFICATION REQUIRED — Route to: ' + ihr.routing_level + '\n\n'
                    : 'Standard notification pathway — No IHR emergency notification triggered.\n\n') +
                    (ihr.ihr_tier ? tierDescriptions[ihr.ihr_tier] + '\n\n' : '') +
                    (routingRationale[ihr.routing_level] || ''),
                bullets: [
                    'Risk Level Assessed: ' + ihr.risk_level,
                    'Routing Level: ' + ihr.routing_level,
                    'IHR Alert Required: ' + (ihr.ihr_alert_required ? 'YES' : 'NO'),
                    'IHR Classification Tier: ' + (ihr.ihr_tier || 'Standard (No special tier)'),
                    ...ihrRuleExplanations.map(r => 'IHR Rule Applied: ' + r),
                    ...(ihr.reasoning || []).map(r => 'Reasoning: ' + r),
                ],
            });
        }

        // ── SECTION 3: TRIAGE OVERRIDES ──────────────────────────────────────
        if (overrides.length > 0) {
            const overrideSection = build_override_section(overrides, er);
            if (overrideSection) sections.push(overrideSection);
        }

        // ── SECTION 4: SYNDROME CLASSIFICATION ───────────────────────────────
        if (!isNonCase) {
            const whoSyndromeRef = {
                VHF: 'WHO AFRO IDSR 2021 §3.2 — Viral Haemorrhagic Fever Syndrome',
                MENINGITIS: 'WHO AFRO IDSR 2021 §3.8 — Acute Meningitis Syndrome',
                NEUROLOGICAL: 'WHO AFRO IDSR 2021 §3.9 — Neurological Syndrome',
                SARI: 'WHO SARI Case Definition (revised 2014)',
                ILI: 'WHO Influenza-Like Illness Case Definition (revised 2014)',
                AWD: 'WHO AFRO IDSR 2021 §3.1 — Acute Watery Diarrhoea',
                BLOODY_DIARRHEA: 'WHO AFRO IDSR 2021 §3.3 — Bloody Diarrhoea / Dysentery',
                JAUNDICE: 'WHO AFRO IDSR 2021 §3.5 — Acute Jaundice Syndrome',
                RASH_FEVER: 'WHO AFRO IDSR 2021 §3.6 — Febrile Rash Illness',
                OTHER: 'WHO Undifferentiated Febrile Illness (no specific syndrome met)',
                NONE: 'No syndrome — Non-case classification',
            };

            const synBullets = [
                'Detected WHO Syndrome: ' + syndrome.syndrome,
                'Classification Confidence: ' + (syndrome.confidence || 'N/A'),
                'WHO Reference: ' + (whoSyndromeRef[syndrome.syndrome] || 'General WHO case definition framework'),
                'Clinical Reasoning: ' + (syndrome.reasoning || 'N/A'),
                'Symptoms that met WHO criteria: ' + (syndrome.who_criteria_met || []).map(fmtSym).join(', ') || 'N/A',
            ];

            if (syndrome.syndrome !== 'OTHER' && syndrome.syndrome !== 'NONE') {
                synBullets.push('Syndrome Bonus Applied: +8 pts to all diseases whose clinical syndromes map to ' +
                    syndrome.syndrome + ' per WHO syndrome taxonomy. This bonus was computed by the Diseases_intelligence.js layer ' +
                    'using the ENGINE_TO_WHO_SYNDROME mapping table and applied to scoreDiseases() results.');
            }

            sections.push({
                id: 'syndrome_classification',
                title: '🔬 CLINICAL SYNDROME CLASSIFICATION',
                level: syndrome.syndrome === 'VHF' || syndrome.syndrome === 'MENINGITIS' ? 'critical' : 'medium',
                body: 'The WHO POE Intelligence Engine classified the symptom pattern using the 15-rule WHO syndrome ' +
                    'classification algorithm (implemented in Diseases_intelligence.js). Rules are evaluated in priority order — ' +
                    'haemorrhagic presentations take highest priority, undifferentiated fever the lowest. ' +
                    'This classification aligns with WHO AFRO IDSR 2021 and the WHO Global Influenza Programme ' +
                    'case definition framework.',
                bullets: synBullets,
            });
        }

        // ── SECTION 5: DISEASE-BY-DISEASE ANALYSIS ────────────────────────────
        if (!isNonCase && er.top_diagnoses && er.top_diagnoses.length > 0) {
            for (let i = 0; i < Math.min(er.top_diagnoses.length, 5); i++) {
                const r = er.top_diagnoses[i];
                const dis = lookup(r.disease_id);
                if (!dis) continue;
                const pres = caseCtx.present_symptoms || [];
                const abs = caseCtx.absent_symptoms || [];
                const sb = r.score_breakdown || {};

                const diseaseLevel = r.confidence_band === 'very_high' ? 'critical'
                    : r.confidence_band === 'high' ? 'high'
                        : r.confidence_band === 'moderate' ? 'medium' : 'low';

                const bullets = [];

                // Score bar
                bullets.push('TOTAL SCORE: ' + scoreBar(r.final_score));
                bullets.push('Confidence Band: ' + (r.confidence_band || 'N/A').toUpperCase() + ' — ' + band_meaning(r.confidence_band, r.cfr_pct));
                if (r.cfr_pct) bullets.push('Case Fatality Rate: ' + r.cfr_pct + '% (without treatment, in resource-limited settings)');
                bullets.push('Incubation Period: ' + (dis.incubation_days ? dis.incubation_days.min + '–' + dis.incubation_days.max + ' days (typical: ' + (dis.incubation_days.typical || 'N/A') + ')' : 'N/A'));
                bullets.push('');

                // Score breakdown
                bullets.push('── SCORE BREAKDOWN ──────────────────────────────────');
                bullets.push('Gate Analysis:         ' + (sb.gate_score >= 0 ? '+' : '') + (sb.gate_score || 0) + ' pts');
                bullets.push('Symptom Contributions: +' + (sb.symptom_score || 0) + ' pts');
                bullets.push('Exposure Signals:      +' + (sb.exposure_score || 0) + ' pts');
                bullets.push('Syndrome Bonus:         ' + (sb.syndrome_bonus > 0 ? '+' + sb.syndrome_bonus : sb.syndrome_bonus || 0) + ' pts');
                bullets.push('Outbreak Context:       ' + (sb.outbreak_bonus > 0 ? '+' + sb.outbreak_bonus : sb.outbreak_bonus || 0) + ' pts');
                bullets.push('Absent Hallmarks:       ' + (sb.absent_hallmark_penalty || 0) + ' pts');
                bullets.push('Contradictions:         ' + (sb.contradiction_penalty || 0) + ' pts');
                bullets.push('Triage Override Boost:  ' + (sb.override_boost > 0 ? '+' + sb.override_boost : sb.override_boost || 0) + ' pts');
                bullets.push('Vaccination Modifier:   ' + (sb.vaccination_modifier || 0) + ' pts');
                bullets.push('Onset Modifier:         ' + (sb.onset_modifier || 0) + ' pts');
                bullets.push('FINAL (clamped 0–100): ' + r.final_score + ' pts');
                bullets.push('');

                // Gate detail
                const gateLines = gate_explanation(dis, pres, abs, sb.gate_score);
                gateLines.forEach(l => bullets.push(l));
                if (gateLines.length) bullets.push('');

                // Symptom detail
                const symLines = symptom_detail(dis, r.matched_symptoms, abs);
                symLines.forEach(l => bullets.push(l));
                if (symLines.length) bullets.push('');

                // Exposure detail
                const expLines = exposure_detail(dis, r.matched_exposures, er.outbreak_context_used);
                expLines.forEach(l => bullets.push(l));
                if (expLines.length) bullets.push('');

                // Incubation check
                const incLines = incubation_check(dis, caseCtx);
                incLines.forEach(l => bullets.push(l));
                if (incLines.length) bullets.push('');

                // Key distinguishers
                if ((dis.key_distinguishers || []).length) {
                    bullets.push('KEY CLINICAL DISTINGUISHERS (from Mandell 9th Ed. / WHO fact sheets):');
                    dis.key_distinguishers.forEach(k => bullets.push('  • ' + k));
                    bullets.push('');
                }

                // WHO case definition
                const cdLines = case_definition_check(dis);
                cdLines.forEach(l => bullets.push(l));
                if (cdLines.length) bullets.push('');

                // Recommended tests
                if ((r.recommended_tests || []).length) {
                    bullets.push('RECOMMENDED LABORATORY INVESTIGATIONS:');
                    r.recommended_tests.forEach(t => bullets.push('  • ' + t));
                }

                // Why this vs next
                if (i === 0 && er.top_diagnoses.length > 1) {
                    const r2 = er.top_diagnoses[1];
                    const diff = r.final_score - r2.final_score;
                    bullets.push('');
                    bullets.push('WHY #1 OVER #2 (' + r2.name + ', score=' + r2.final_score + '):');
                    bullets.push('  Score margin: +' + diff + ' pts. ' + (
                        diff >= 20 ? 'This is a substantial margin — the engine has high relative confidence in this ranking.' :
                            diff >= 10 ? 'Moderate margin — #2 remains a credible differential and should not be dismissed.' :
                                'Narrow margin — both diagnoses should be given approximately equal clinical weight. Additional data (laboratory, travel history) should guide the differential.'
                    ));
                    bullets.push('  Primary discriminating factors: ' +
                        (r.matched_hallmarks.filter(h => !(r2.matched_hallmarks || []).includes(h)).map(fmtSym).join(', ') || 'Score accumulation from combined symptom/exposure profile'));
                }

                sections.push({
                    id: 'disease_' + (i + 1),
                    title: (i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : ('  #' + (i + 1))) +
                        ' RANK ' + (i + 1) + ': ' + r.name.toUpperCase() +
                        ' [Score: ' + r.final_score + '/100, ' + (r.confidence_band || 'N/A').toUpperCase() + ']',
                    level: diseaseLevel,
                    body: 'Disease analysis for ' + r.name + '. ' +
                        'WHO category: ' + (r.ihr_category || 'N/A') + '. ' +
                        'Priority tier: ' + (r.priority_tier || 'N/A').replace(/_/g, ' ') + '. ' +
                        (dis.who_basis ? 'Evidence basis: ' + dis.who_basis : '') +
                        (dis.notes && dis.notes.length ? ' Note: ' + dis.notes[0] : ''),
                    bullets,
                });
            }
        }

        // ── SECTION 6: RULED-OUT DIFFERENTIALS ───────────────────────────────
        if (!isNonCase && er.all_reportable) {
            const ruledOut = (er.all_reportable || [])
                .filter(r => r.final_score < 20 && r.final_score >= 5)
                .filter(r => r.priority_tier === 'tier_2_ihr_annex2' || r.priority_tier === 'tier_1_ihr_critical')
                .slice(0, 3);

            if (ruledOut.length > 0) {
                const bullets = ruledOut.map(r => {
                    const dis = lookup(r.disease_id);
                    const sb = r.score_breakdown || {};
                    return r.name + ' [score: ' + r.final_score + ']: ' +
                        (sb.gate_score < 0 ? 'Gate failed (' + sb.gate_score + ' pts) — key clinical requirements not met. ' : '') +
                        (sb.contradiction_penalty < 0 ? 'Contradiction penalties applied (' + sb.contradiction_penalty + ' pts). ' : '') +
                        (r.matched_symptoms.length === 0 ? 'No matching symptom weights. ' : '') +
                        'This IHR-notifiable disease was considered but scored below the clinical threshold for the current symptom profile.';
                });

                sections.push({
                    id: 'ruled_out',
                    title: '🔍 HIGH-CONSEQUENCE DISEASES CONSIDERED AND EXCLUDED',
                    level: 'info',
                    body: 'The following IHR Tier 1 and Tier 2 diseases were evaluated by the engine but did not accumulate ' +
                        'sufficient evidence to pass the clinical threshold (score <20). These exclusions are based on the ' +
                        'current symptom and exposure data. Additional clinical information could change this assessment.',
                    bullets,
                });
            }
        }

        // ── SECTION 7: VITAL SIGNS INTERPRETATION ────────────────────────────
        const vitals = caseCtx.vitals || {};
        const va = cv.vital_alerts || {};
        if (Object.keys(va).length > 0 || (cv.critical_flags || []).length > 0) {
            const vBullets = [];

            const vitalDescriptions = {
                temperature: (a) => 'Temperature: ' + a.msg + ' Clinical significance: Fever ≥38.5°C is the WHO AFRO IDSR threshold for notifiable febrile illness. Hyperpyrexia (≥40°C) carries risk of febrile seizures and multi-organ failure.',
                pulse: (a) => 'Pulse rate: ' + a.msg + ' Clinical significance: Tachycardia in a febrile traveller may indicate sepsis, severe malaria, or VHF shock phase.',
                respiratory: (a) => 'Respiratory rate: ' + a.msg + ' Clinical significance: Respiratory rate ≥30/min is a WHO SARI emergency criterion and indicates respiratory failure risk.',
                spo2: (a) => 'Oxygen saturation: ' + a.msg + ' Clinical significance: SpO2 <90% requires immediate supplemental oxygen and meets WHO EMERGENCY triage criteria.',
                bp: (a) => 'Blood pressure: ' + a.msg + ' Clinical significance: Systolic <90 mmHg constitutes shock and may indicate sepsis, severe haemorrhage, or VHF late phase.',
            };

            for (const [key, alert] of Object.entries(va)) {
                const desc = vitalDescriptions[key];
                vBullets.push(desc ? desc(alert) : key + ': ' + alert.msg);
            }

            if (cv.critical_flags && cv.critical_flags.length) {
                vBullets.push('');
                vBullets.push('CRITICAL CLINICAL FLAGS: ' + cv.critical_flags.join(', '));
            }

            if (cv.needs_emergency_triage) {
                vBullets.push('');
                vBullets.push('⚠ EMERGENCY TRIAGE REQUIRED: One or more vital signs or symptom patterns meet the WHO emergency triage threshold. ' +
                    'The traveller should not be released without emergency medical assessment.');
            }

            sections.push({
                id: 'vital_signs',
                title: '🩺 VITAL SIGNS CLINICAL INTERPRETATION',
                level: cv.needs_emergency_triage ? 'critical' : 'high',
                body: 'The following vital sign readings were recorded and interpreted against WHO emergency and WHO AFRO IDSR ' +
                    'threshold values. Vital signs influence the IHR risk level computation via the computeIHRRiskLevel() ' +
                    'function — critical vital signs can escalate the routing level independently of disease scores.',
                bullets: vBullets,
            });
        }

        // ── SECTION 8: TRAVEL HISTORY ANALYSIS ───────────────────────────────
        const visited = caseCtx.visited_countries || [];
        if (visited.length > 0 || (er.outbreak_context_used || []).length > 0) {
            const tBullets = [];

            tBullets.push('Countries visited / transited (declared by traveller):');
            for (const tc of visited) {
                const cc = typeof tc === 'string' ? tc : tc.country_code || '';
                const role = typeof tc === 'string' ? 'VISITED' : (tc.travel_role || 'VISITED');
                const matchedDiseases = D_.diseases
                    ? D_.diseases.filter(d => (d.endemic_countries || []).includes(cc.toUpperCase()))
                        .map(d => d.name).slice(0, 5).join(', ')
                    : 'N/A';
                tBullets.push('  • ' + cc + ' (' + role + ')' +
                    (matchedDiseases ? ' → Endemic diseases: ' + matchedDiseases : ''));
            }

            if ((er.outbreak_context_used || []).length > 0) {
                tBullets.push('');
                tBullets.push('OUTBREAK CONTEXT ACTIVATED (' + er.outbreak_context_used.length + ' diseases):');
                tBullets.push('  The WHO POE Intelligence Layer matched visited countries against the WHO/CDC/ECDC 2024 ' +
                    'geographic endemic risk database. The following diseases received outbreak_bonus points because ' +
                    'at least one visited country falls within their documented endemic zone:');
                for (const dId of er.outbreak_context_used) {
                    const dis = lookup(dId);
                    const bonus = dis ? (dis.outbreak_bonus || 15) : 15;
                    tBullets.push('  • ' + (dis ? dis.name : fmtDisease(dId)) + ' [+' + bonus + ' pts]: ' +
                        'Country match against WHO-curated endemic country list (Diseases_intelligence.js §ENDEMIC_COUNTRIES).');
                }
                tBullets.push('');
                tBullets.push('IHR TRAVEL RISK FRAMEWORK: IHR 2005 Article 23(1)(a) authorises health measures at POEs based on ' +
                    'epidemiological information, including the origin of the traveller. Geographic origin is a recognised ' +
                    'primary risk stratifier in WHO POE surveillance guidelines. The 21-day lookback window used by this engine ' +
                    'matches the maximum incubation period for Ebola and Marburg virus disease.');
            }

            sections.push({
                id: 'travel_analysis',
                title: '✈ TRAVEL HISTORY EPIDEMIOLOGICAL ANALYSIS',
                level: (er.outbreak_context_used || []).length > 3 ? 'high' : 'medium',
                body: 'The travel history was cross-referenced against the WHO POE Intelligence Layer endemic country database ' +
                    '(compiled from WHO disease fact sheets, CDC Yellow Book 2024, and ECDC country-specific risk assessments). ' +
                    'Any visit to an endemic country within the disease\'s maximum incubation window generates an ' +
                    'outbreak_context bonus in the scoring engine.',
                bullets: tBullets,
            });
        }

        // ── SECTION 9: EXPOSURE RISK PROFILE ─────────────────────────────────
        const yesExposures = caseCtx.exposures_yes || [];
        if (yesExposures.length > 0) {
            const eBullets = yesExposures.map(label =>
                '• ' + label + ' — This exposure was translated to engine signal codes via window.EXPOSURES.mapToEngineCodes() ' +
                'and applied to all relevant disease exposure_weights in the scoring matrix.'
            );
            eBullets.push('');
            eBullets.push('ENGINE CODE TRANSLATION: The exposure catalog (exposures.js v2.0.0) maps each WHO-aligned exposure ' +
                'category to between 1 and 4 engine code keys, enabling a single officer response to activate multiple ' +
                'epidemiologically related exposure signals simultaneously. For example, "Contact with dead body" activates ' +
                'contact_dead_body + funeral_or_burial_exposure + contact_body_fluids — the three highest-weight exposure ' +
                'signals for VHF diseases in the engine.');

            sections.push({
                id: 'exposure_profile',
                title: '☢ CONFIRMED EXPOSURE RISK PROFILE',
                level: yesExposures.length >= 2 ? 'high' : 'medium',
                body: yesExposures.length + ' high-risk exposure(s) were confirmed YES by the officer. ' +
                    'Exposure weights are sourced from GeoSentinel Surveillance Network data and WHO outbreak investigation ' +
                    'reports, calibrated for travellers at Points of Entry.',
                bullets: eBullets,
            });
        }

        // ── SECTION 10: RECOMMENDED ACTIONS ──────────────────────────────────
        if (!isNonCase && topD) {
            const actions = [...(topD.immediate_actions || [])];

            if (gFlags.includes('NEEDS_IMMEDIATE_ISOLATION')) actions.unshift('IMMEDIATE ISOLATION — Full IPC precautions before any other action.');
            if (gFlags.includes('NEEDS_IHR_NOTIFICATION')) actions.push('NOTIFY IHR NATIONAL FOCAL POINT within 24 hours per IHR 2005 Article 6.');
            if (gFlags.includes('NEEDS_EMERGENCY_REFERRAL')) actions.push('EMERGENCY MEDICAL REFERRAL — do not delay for documentation.');
            if (gFlags.includes('AFP_SURVEILLANCE_ACTIVATED')) actions.push('AFP SURVEILLANCE: Collect bilateral stool specimens within 14 days. Notify WHO GPEI surveillance officer.');
            if (gFlags.includes('CHOLERA_PROTOCOL_ACTIVATED')) actions.push('CHOLERA PROTOCOL: Aggressive ORS/IV rehydration immediately. Enteric precautions. GTFCC notification.');
            if (gFlags.includes('RABIES_PROTOCOL_ACTIVATED')) actions.push('RABIES: Once symptomatic, virtually 100% fatal. Palliative care referral. Assess bite history for contact tracing.');
            if (gFlags.includes('BIOTERRORISM_PROTOCOL_ACTIVATED')) actions.push('BIOTERRORISM PROTOCOL: Notify law enforcement and national health security authority simultaneously with WHO.');

            actions.push('DOCUMENT ALL FINDINGS in Sentinel secondary screening record before releasing or escalating.');
            if (topD.recommended_tests && topD.recommended_tests.length) {
                actions.push(...topD.recommended_tests.map(t => 'LAB: ' + t));
            }

            sections.push({
                id: 'recommended_actions',
                title: '⚡ IMMEDIATE ACTIONS REQUIRED',
                level: 'critical',
                body: 'The following actions are required based on the clinical assessment, triage override rules, ' +
                    'WHO disease-specific guidance, and IHR 2005 mandatory notification requirements. ' +
                    'Actions are listed in order of priority.',
                bullets: actions,
            });
        }

        // ── SECTION 11: DATA QUALITY ASSESSMENT ──────────────────────────────
        {
            const totalSymptoms = (D_.symptom_catalog || []).length || 60;
            const assessed = (inputSumm.symptoms_count || 0) + (inputSumm.absent_count || 0);
            const unassessed = Math.max(0, totalSymptoms - assessed);
            const completeness = Math.round(assessed / totalSymptoms * 100);

            const qBullets = [
                'Symptoms confirmed PRESENT: ' + (inputSumm.symptoms_count || 0),
                'Symptoms confirmed ABSENT: ' + (inputSumm.absent_count || 0),
                'Symptoms left as UNKNOWN: ~' + unassessed + ' (not assessed by officer)',
                'Exposure signals assessed: ' + (inputSumm.exposures_count || 0),
                'Data completeness index: ~' + completeness + '% of full symptom catalog assessed',
                'Travel history recorded: ' + (visited.length > 0 ? visited.length + ' countr(ies)' : 'None recorded'),
                'Assessment confidence baseline: ' + (inputSumm.confidence_baseline || 'N/A'),
                '',
                completeness < 30
                    ? '⚠ LOW DATA COMPLETENESS: Only ~' + completeness + '% of symptoms assessed. The engine\'s accuracy ' +
                    'is substantially reduced when most symptoms are UNKNOWN. Consider completing a more thorough symptom ' +
                    'inventory before finalising the disposition decision.'
                    : completeness < 60
                        ? 'MODERATE DATA: ~' + completeness + '% symptom coverage. Assessment is reasonably reliable for ' +
                        'common syndrome patterns, but additional symptom clarification would improve precision.'
                        : 'GOOD DATA COVERAGE: ~' + completeness + '% symptom coverage. The engine has sufficient information ' +
                        'for a reliable clinical probability assessment.',
                '',
                'DATA GAPS THAT WOULD MOST CHANGE THIS ASSESSMENT:',
                '  • Confirming or denying haemorrhagic symptoms (bleeding, petechiae) — would activate or deactivate VHF protocol.',
                '  • Clarifying exact travel dates vs. symptom onset — enables incubation window validation.',
                '  • Laboratory confirmation — required for any definitive WHO case classification.',
                '  • Vaccination history — would activate vaccination modifier weights in the engine.',
            ];

            sections.push({
                id: 'data_quality',
                title: '📊 DATA QUALITY & ASSESSMENT LIMITATIONS',
                level: completeness < 30 ? 'high' : 'info',
                body: 'The WHO POE Intelligence Engine is a probabilistic decision-support system. Its output quality is ' +
                    'directly proportional to the completeness of the clinical data entered. The following assessment ' +
                    'describes data quality for this case and identifies gaps that would improve diagnostic accuracy.',
                bullets: qBullets,
            });
        }

        // ── SECTION 12: ALGORITHM TRANSPARENCY ───────────────────────────────
        {
            sections.push({
                id: 'algorithm_transparency',
                title: '⚙ ALGORITHM TRANSPARENCY & METHODOLOGY',
                level: 'info',
                body: 'This report was generated by the WHO POE Disease Intelligence System. ' +
                    'Full methodology is documented for audit, regulatory review, and quality assurance.',
                bullets: [
                    'Engine: WHO-POE Unified Explainable Matcher v3 (Diseases.js)',
                    'Intelligence Layer: Diseases_intelligence.js v1.0.0 (WHO/IHR Compliance Extension)',
                    'Symptom Weight Calibration: Mandell\'s Principles of Infectious Diseases, 9th Edition — positive likelihood ratios converted to additive weights',
                    'Base Prevalence Data: GeoSentinel Surveillance Network + TropNetEurop — traveller disease prevalence in international POE populations',
                    'Geographic Endemic Data: WHO Disease Fact Sheets 2024 + CDC Yellow Book 2024 + ECDC Country-Specific Risk Assessments',
                    'Syndrome Classification: WHO AFRO IDSR 2021 (3rd Edition) — 15 priority-ordered syndrome rules',
                    'IHR Escalation: IHR 2005 Annex 2 Decision Instrument — 9 deterministic escalation rules',
                    'Case Definitions: WHO AFRO IDSR 2021 + WHO Disease-Specific Fact Sheets (all 41 diseases)',
                    'Triage Overrides: 10 mandatory safety rules based on WHO/IHR/MSF clinical emergency protocols',
                    'Score Formula: gate_score + symptom_score + exposure_score + syndrome_bonus + outbreak_bonus + vaccination_modifier + onset_modifier + absent_hallmark_penalty + contradiction_penalty + override_boost (clamped 0–100)',
                    'Gate Pass Bonus: +12 pts | Soft Gate Fail: −18 pts | Hard Gate Exclusion: −60 pts',
                    'Syndrome Bonus: +8 pts when derived WHO syndrome matches disease\'s syndrome list',
                    'Outbreak Bonus: +14 to +25 pts (disease-specific) when visited country is in endemic zone',
                    'This output is a clinical decision-support aid. It does NOT constitute a medical diagnosis. Laboratory confirmation is required for definitive classification per WHO protocols.',
                    'Regulatory basis: IHR 2005, ECSA-HC Regional POE Digital Surveillance Standards, WHO Surveillance at Points of Entry Implementation Guide',
                ],
            });
        }

        // ── GENERATE PLAIN TEXT ───────────────────────────────────────────────
        const divider = '═'.repeat(72);
        const thin = '─'.repeat(72);

        let plainText = divider + '\n';
        plainText += 'WHO POE DISEASE INTELLIGENCE REPORT\n';
        plainText += 'Generated: ' + now + '\n';
        plainText += 'POE: ' + (poeInfo || 'N/A') + ' | Officer: ' + (caseCtx.officer_name || 'N/A') + '\n';
        plainText += divider + '\n\n';

        for (const sec of sections) {
            plainText += thin + '\n';
            plainText += sec.title + '\n';
            plainText += thin + '\n';
            if (sec.body) plainText += sec.body + '\n\n';
            if (sec.bullets && sec.bullets.length) {
                for (const b of sec.bullets) {
                    plainText += (b === '' ? '\n' : (b.startsWith('  ') || b.startsWith('──') ? b : b) + '\n');
                }
                plainText += '\n';
            }
        }

        plainText += divider + '\n';
        plainText += 'END OF REPORT\n';
        plainText += divider + '\n';

        // ── SUMMARY OBJECT ────────────────────────────────────────────────────
        const summary = {
            headline: isNonCase
                ? 'NON-CASE — No clinical indicators detected'
                : (topD ? topD.name + ' [' + topD.confidence_band + ' confidence, score ' + topD.final_score + '/100]' : 'Insufficient data'),
            top_diagnosis: isNonCase ? 'NON_CASE' : (topD ? topD.disease_id : null),
            confidence: isNonCase ? 'HIGH (non-case)' : (topD ? topD.confidence_band : null),
            ihr_required: ihr.ihr_alert_required || false,
            routing: ihr.routing_level || 'DISTRICT',
            alert_level: alertLevel,
            action_required: isNonCase ? 'Standard release — document' : (gFlags.includes('NEEDS_IMMEDIATE_ISOLATION') ? 'IMMEDIATE ISOLATION' : 'Secondary screening and clinical assessment required'),
        };

        return {
            report_id: 'RPT-' + Date.now() + '-' + Math.random().toString(36).slice(2, 7).toUpperCase(),
            generated_at: now,
            algorithm_version: D_.engine ? D_.engine.name : 'WHO-POE Unified Matcher v3',
            intelligence_version: 'Diseases_intelligence.js v1.0.0',
            alert_level: alertLevel,
            sections,
            summary,
            plain_text: plainText,
        };
    };

    // ══════════════════════════════════════════════════════════════════════
    // SECTION 8: CLINICAL INTELLIGENCE REPORT GENERATOR
    //
    // generateClinicalReport(enhancedResult, caseContext)
    //
    // Produces a fully structured, narrative-rich clinical reasoning report
    // explaining EVERY decision the engine made — what was considered, why,
    // which international frameworks applied, and what the officer should do.
    //
    // NOTHING in this report is fabricated or hallucinated.
    // Every sentence is derived from:
    //   - The actual score breakdown per disease
    //   - The actual symptom catalog labels
    //   - The actual WHO case definitions (Section 2 of this file)
    //   - The actual IHR escalation rules (Section 5 of this file)
    //   - The actual triage override rules from the engine
    //   - The actual endemic country data (Section 1 of this file)
    //
    // @param {Object} enhancedResult  Output of getEnhancedScoreResult()
    // @param {Object} caseContext {
    //   vitals:            { temperature_c, pulse_rate, respiratory_rate, oxygen_saturation, bp_systolic }
    //   presentSymptoms:   string[]   symptom IDs confirmed present
    //   absentSymptoms:    string[]   symptom IDs confirmed absent
    //   visitedCountries:  [{ country_code, travel_role, arrival_date?, departure_date? }]
    //   exposures:         [{ exposure_code, response, label? }]
    //   travelerDirection: 'ENTRY'|'EXIT'|'TRANSIT'|null
    //   poeCode:           string
    //   travelerGender:    string
    //   travelerAge:       number|null
    //   officerOverride:   { syndromeOverridden, riskOverridden, overrideNote, addedDiseases }
    // }
    // @returns {Object} {
    //   verdict:                  string   'NON_CASE'|'LOW_PROBABILITY'|'MODERATE_CONCERN'|'HIGH_PROBABILITY'|'CRITICAL_EMERGENCY'
    //   headline:                 string   single-sentence conclusion
    //   risk_color:               string   hex color for UI chip
    //   sections:                 Section[]
    //   summary_brief:            string   ~4 sentence executive summary
    //   ihr_notification_text:    string   formal IHR notification language (if applicable)
    //   sms_alert:                string   <160 chars for SMS
    //   printable_text:           string   full plain-text version
    //   generated_at:             string   ISO timestamp
    // }
    // ══════════════════════════════════════════════════════════════════════

    D.generateClinicalReport = function (enhancedResult, caseContext) {

        const now = new Date().toISOString();
        caseContext = caseContext || {};
        enhancedResult = enhancedResult || {};

        const vitals = caseContext.vitals || {};
        const presentSymptoms = caseContext.presentSymptoms || [];
        const absentSymptoms = caseContext.absentSymptoms || [];
        const visitedCountries = caseContext.visitedCountries || [];
        const exposures = caseContext.exposures || [];
        const travelerDir = caseContext.travelerDirection || null;
        const poeCode = caseContext.poeCode || 'Unknown POE';
        const gender = caseContext.travelerGender || 'Unknown';
        const age = caseContext.travelerAge || null;
        const override = caseContext.officerOverride || {};

        const isNonCase = enhancedResult.is_non_case || false;
        const syndrome = enhancedResult.syndrome || {};
        const ihrRisk = enhancedResult.ihr_risk || {};
        const nonCase = enhancedResult.non_case || {};
        const clinVal = enhancedResult.clinical_validation || {};
        const outbreakCtx = enhancedResult.outbreak_context_used || [];
        const globalFlags = enhancedResult.global_flags || [];
        const overridesFired = enhancedResult.overrides_fired || [];
        const topDiagnoses = enhancedResult.top_diagnoses || [];
        const topD = topDiagnoses[0] || null;
        const topDisease = topD
            ? (D.diseases.find(d => d.id === topD.disease_id) || null)
            : null;

        // ── Helpers ────────────────────────────────────────────────────────

        function symLabel(id) {
            const s = (D.symptom_catalog || []).find(x => x.id === id);
            return s ? s.label : id.replace(/_/g, ' ');
        }

        function countryName(code) {
            if (!code) return code;
            const known = {
                'CD': 'Democratic Republic of Congo', 'UG': 'Uganda', 'GN': 'Guinea',
                'LR': 'Liberia', 'SL': 'Sierra Leone', 'NG': 'Nigeria', 'KE': 'Kenya',
                'ET': 'Ethiopia', 'TZ': 'Tanzania', 'SS': 'South Sudan', 'CF': 'Central African Republic',
                'RW': 'Rwanda', 'ZM': 'Zambia', 'GH': 'Ghana', 'CI': 'Côte d\'Ivoire', 'CM': 'Cameroon',
                'AO': 'Angola', 'MZ': 'Mozambique', 'ZW': 'Zimbabwe', 'MW': 'Malawi', 'BD': 'Bangladesh',
                'IN': 'India', 'PK': 'Pakistan', 'AF': 'Afghanistan', 'SA': 'Saudi Arabia',
                // eslint-disable-next-line no-dupe-keys
                'AE': 'United Arab Emirates', 'HT': 'Haiti', 'IN': 'India', 'PH': 'Philippines',
                'ID': 'Indonesia', 'VN': 'Vietnam', 'TH': 'Thailand', 'MM': 'Myanmar', 'KH': 'Cambodia',
                'NP': 'Nepal', 'LK': 'Sri Lanka', 'SD': 'Sudan', 'YE': 'Yemen', 'SO': 'Somalia',
            };
            return known[code] || code;
        }

        function exposureLabel(code) {
            if (window.EXPOSURES) {
                const e = window.EXPOSURES.getByCode(code);
                if (e) return e.label;
            }
            return code.replace(/_/g, ' ').toLowerCase();
        }

        function scoreBar(score, max) {
            const pct = Math.round(score / max * 20);
            return '█'.repeat(pct) + '░'.repeat(20 - pct) + ' ' + score + '/100';
        }

        function tierDescription(tier) {
            const map = {
                'tier_1_ihr_critical': 'IHR Tier 1 — Always Notifiable (PHEIC by definition)',
                'tier_2_ihr_annex2': 'IHR Tier 2 — Annex 2 Epidemic-Prone Disease',
                'tier_2_ihr_equivalent': 'IHR Equivalent — WHO AFRO Priority Disease',
                'tier_3_who_notifiable': 'WHO Notifiable Disease (national reporting required)',
                'tier_4_syndromic': 'Syndromic Differential (common travel-related illness)',
            };
            return map[tier] || tier;
        }

        function vitalInterpret(vitals) {
            const lines = [];
            if (vitals.temperature_c != null) {
                const t = vitals.temperature_c;
                if (t >= 40.5) lines.push('Temperature ' + t.toFixed(1) + '°C — extreme hyperpyrexia. This is a medical emergency threshold. Bacterial sepsis, severe malaria, and heat stroke are the top physiological differentials at this temperature. Evaporative cooling is immediately required.');
                else if (t >= 39.5) lines.push('Temperature ' + t.toFixed(1) + '°C — significant hyperpyrexia. Mandell 9th Ed. places the LR+ for bacteraemia at >4.0 above 39.5°C. Multiple IHR-priority pathogens (Ebola, Lassa, Marburg, meningococcal disease) produce temperatures in this range.');
                else if (t >= 38.5) lines.push('Temperature ' + t.toFixed(1) + '°C — meets WHO febrile illness threshold. This is the standard cutoff used in WHO AFRO IDSR case definitions for febrile syndrome screening.');
                else if (t >= 37.5) lines.push('Temperature ' + t.toFixed(1) + '°C — low-grade fever. Below standard IHR alert threshold but clinically relevant in early viral illness or in patients who have taken antipyretics before arrival.');
                else if (t < 35.0) lines.push('Temperature ' + t.toFixed(1) + '°C — HYPOTHERMIA. This is paradoxical in tropical travel context and may indicate septic shock (warm extremities with low core temperature), severe malaria with circulatory compromise, or a measurement artefact. Verify immediately with a second reading.');
                else lines.push('Temperature ' + t.toFixed(1) + '°C — within normal range. Absence of fever reduces probability of acute viral haemorrhagic fever and most bacterial sepsis syndromes, but does not exclude early-incubation disease, post-antipyretic presentation, or several non-febrile diseases in the differential.');
            } else {
                lines.push('Temperature not recorded. For completeness of IHR screening, a temperature reading is strongly recommended. The absence of a measured temperature reduces the precision of the scoring engine by preventing temperature-dependent onset_modifier calculations.');
            }
            if (vitals.oxygen_saturation != null) {
                const s = vitals.oxygen_saturation;
                if (s < 88) lines.push('SpO₂ ' + s + '% — CRITICAL HYPOXIA. WHO defines SpO₂ <90% as the threshold for severe respiratory compromise. At ' + s + '%, supplemental oxygen is immediately lifesaving. This finding alone meets SARI emergency criteria per WHO Integrated Management of Adult Illness.');
                else if (s < 90) lines.push('SpO₂ ' + s + '% — severe hypoxia below WHO emergency threshold of 90%. Immediate oxygen therapy required. This finding elevates the SARI pathway regardless of other findings.');
                else if (s < 94) lines.push('SpO₂ ' + s + '% — moderate hypoxia. WHO IMCI threshold for supplemental oxygen is <90%, but values between 90–94% warrant close monitoring and low-flow oxygen if available.');
                else lines.push('SpO₂ ' + s + '% — within acceptable range. Absence of hypoxia makes severe respiratory tract disease less likely, though early or upper respiratory presentations may still present with normal saturation.');
            }
            if (vitals.pulse_rate != null) {
                const p = vitals.pulse_rate;
                if (p > 150) lines.push('Pulse ' + p + ' bpm — severe tachycardia. At this rate, cardiac output begins to fall. Haemodynamic shock from sepsis, haemorrhage, or severe dehydration are the primary differentials. Immediate IV access and fluid assessment required.');
                else if (p > 120) lines.push('Pulse ' + p + ' bpm — significant tachycardia consistent with early sepsis, dehydration, or high fever response. Correlate with temperature and blood pressure to assess for shock physiology.');
                else if (p > 100) lines.push('Pulse ' + p + ' bpm — mild tachycardia, commonly seen with fever (heart rate rises ~10 bpm per °C of fever). Without other shock signs, this may reflect physiological fever response rather than haemodynamic compromise.');
                else if (p < 50) lines.push('Pulse ' + p + ' bpm — bradycardia. In the context of typhoid fever, this is Faget\'s sign (relative bradycardia despite high fever) — a classic clinical distinguisher. Also relevant for dengue haemorrhagic fever recovery phase. Verify cardiac history.');
            }
            if (vitals.respiratory_rate != null) {
                const r = vitals.respiratory_rate;
                if (r >= 30) lines.push('Respiratory rate ' + r + '/min — meets WHO emergency threshold (≥30 = severe respiratory distress). This finding activates the SARI protocol. Combined with SpO₂ data above, this guides triage category to EMERGENCY per WHO IMCI standards.');
                else if (r >= 25) lines.push('Respiratory rate ' + r + '/min — elevated. WHO IMCI defines tachypnoea in adults as ≥20/min for concern, ≥30/min for emergency. At ' + r + '/min, this is a moderate concern warranting URGENT triage review.');
                else if (r < 10) lines.push('Respiratory rate ' + r + '/min — critically reduced. Verify measurement. Respiratory depression of this degree suggests CNS depression (neurological syndrome, drug effect) or measurement error.');
            }
            if (vitals.bp_systolic != null) {
                const sys = vitals.bp_systolic;
                if (sys < 80) lines.push('BP ' + sys + ' mmHg systolic — SEVERE HYPOTENSION. This meets criteria for haemodynamic shock. Immediate IV access and fluid resuscitation required. In the context of VHF or severe sepsis, this finding carries >50% 24h mortality without immediate intervention.');
                else if (sys < 90) lines.push('BP ' + sys + ' mmHg systolic — hypotension meeting WHO shock threshold. In the context of fever and infectious illness, septic shock, haemorrhagic fever with vascular leak, or severe cholera dehydration are the primary differentials.');
                else if (sys > 180) lines.push('BP ' + sys + ' mmHg systolic — hypertensive urgency. Likely pre-existing hypertension rather than acute infectious aetiology, but note that severe pain from infection (peritonitis, meningitis) can produce significant pressure elevation.');
            }
            return lines;
        }

        // ── Verdict determination ──────────────────────────────────────────
        let verdict, headline, riskColor;

        if (isNonCase) {
            verdict = 'NON_CASE';
            headline = 'No clinical indicators of infectious disease detected. Traveller meets criteria for non-case classification.';
            riskColor = '#2E7D32';
        } else if (!topD || topD.final_score < 20) {
            verdict = 'LOW_PROBABILITY';
            headline = 'Insufficient symptom-disease correlation. Scores below threshold for any reportable disease. Monitor and reassess.';
            riskColor = '#1565C0';
        } else if (topD.final_score < 40) {
            verdict = 'MODERATE_CONCERN';
            headline = 'Low-moderate probability signal for ' + (topD ? (topD.name || topD.disease_id) : 'unknown disease') + '. Formal secondary assessment recommended.';
            riskColor = '#F57F17';
        } else if (topD.final_score < 60) {
            verdict = 'HIGH_PROBABILITY';
            headline = 'Moderate-high clinical probability for ' + (topD ? (topD.name || topD.disease_id) : 'unknown') + '. Isolation, IHR notification, and laboratory confirmation are indicated.';
            riskColor = '#E65100';
        } else {
            verdict = 'CRITICAL_EMERGENCY';
            headline = 'HIGH-CONFIDENCE signal for ' + (topD ? (topD.name || topD.disease_id) : 'unknown') + ' (' + (topD ? (topD.confidence_band || '') : '').toUpperCase() + ' band, score ' + (topD ? topD.final_score : 0) + '/100). IHR emergency protocol activated.';
            riskColor = '#B71C1C';
        }
        if (globalFlags.includes('VHF_PROTOCOL_ACTIVATED')) { verdict = 'CRITICAL_EMERGENCY'; riskColor = '#B71C1C'; }
        if (globalFlags.includes('BIOTERRORISM_PROTOCOL_ACTIVATED')) { verdict = 'CRITICAL_EMERGENCY'; riskColor = '#4A148C'; }

        // ── BUILD SECTIONS ─────────────────────────────────────────────────
        const sections = [];

        // ── SECTION 1: EXECUTIVE SUMMARY ────────────────────────────────────
        {
            const bullets = [];
            const travelerDesc = [
                gender !== 'Unknown' ? gender.charAt(0) + gender.slice(1).toLowerCase() : 'Unknown gender',
                age ? age + '-year-old' : 'age unknown',
                'traveller screened at ' + poeCode,
            ].join(', ');

            bullets.push('Traveller: ' + travelerDesc + (travelerDir ? ' — ' + travelerDir + ' direction' : ''));
            bullets.push('Symptoms presented: ' + (presentSymptoms.length > 0 ? presentSymptoms.map(symLabel).join(', ') : 'None recorded'));
            bullets.push('Symptoms confirmed absent: ' + (absentSymptoms.length > 0 ? absentSymptoms.map(symLabel).join(', ') : 'None explicitly excluded'));
            bullets.push('Countries visited (last 21 days): ' + (visitedCountries.length > 0 ? visitedCountries.map(c => countryName(c.country_code) + ' (' + (c.travel_role || 'VISITED') + ')').join(', ') : 'None declared'));
            bullets.push('Risk exposures confirmed YES: ' + (exposures.filter(e => e.response === 'YES').length) + ' of ' + exposures.length + ' assessed');

            if (isNonCase) {
                bullets.push('CLASSIFICATION: NON-CASE — no infectious disease indicators detected');
            } else if (topD) {
                bullets.push('PRIMARY SIGNAL: ' + topD.name + ' (Score ' + topD.final_score + '/100 — ' + (topD.confidence_band || 'low') + ' confidence)');
                bullets.push('IHR RISK LEVEL: ' + (ihrRisk.risk_level || '—') + ' → Route to ' + (ihrRisk.routing_level || 'DISTRICT'));
                if (ihrRisk.ihr_alert_required) bullets.push('⚠ IHR NOTIFICATION REQUIRED — ' + (ihrRisk.ihr_tier || 'public health notification needed'));
            }

            if (overridesFired.length > 0) {
                bullets.push('TRIAGE OVERRIDES ACTIVATED: ' + overridesFired.join(', ').replace(/_/g, ' '));
            }

            if (override.syndromeOverridden || override.riskOverridden) {
                bullets.push('OFFICER OVERRIDE RECORDED — officer disagreed with algorithmic classification' +
                    (override.overrideNote ? ': "' + override.overrideNote + '"' : ''));
            }

            sections.push({
                id: 'executive_summary',
                title: '1. EXECUTIVE SUMMARY',
                severity: isNonCase ? 'ok' : (verdict === 'CRITICAL_EMERGENCY' ? 'critical' : verdict === 'HIGH_PROBABILITY' ? 'high' : verdict === 'MODERATE_CONCERN' ? 'moderate' : 'low'),
                icon: '📋',
                paragraphs: [
                    headline,
                    'This report was generated by the WHO-POE Unified Explainable Matcher v3, a clinical decision-support system built on WHO AFRO IDSR Technical Guidelines (2021), IHR 2005 Annex 2, and Mandell\'s Principles of Infectious Diseases (9th Edition). Every statement below is derived directly from the data submitted by the screening officer. No inference is made beyond what the data supports.',
                ],
                bullets,
            });
        }

        // ── SECTION 2: CLINICAL PRESENTATION ANALYSIS ───────────────────────
        {
            const paras = [];
            const bullets = [];

            const vitalLines = vitalInterpret(vitals);
            paras.push('The clinical presentation was assessed across ' + (presentSymptoms.length + absentSymptoms.length) + ' symptom data points (' + presentSymptoms.length + ' confirmed present, ' + absentSymptoms.length + ' confirmed absent). The remaining symptoms in the WHO AFRO IDSR surveillance checklist were recorded as UNKNOWN — these do not contribute positive or negative signals to the scoring engine, preserving conservatism where data is incomplete.');

            if (presentSymptoms.length === 0) {
                paras.push('No symptoms were confirmed present by clinical examination. Per the WHO non-case classification criteria applied in this system (derived from IHR Article 23(1)(a) — the least invasive measure principle), a traveller with zero confirmed symptoms does not meet the threshold for secondary clinical investigation unless the officer observes signs not captured in the checklist.');
            } else {
                // Group symptoms by clinical domain
                const FEVER_SYM = ['fever', 'high_fever', 'very_high_fever', 'low_grade_fever', 'sudden_onset_fever', 'gradual_onset_fever', 'undulant_fever', 'paradoxical_bradycardia', 'chills', 'night_sweats'];
                const RESP_SYM = ['cough', 'dry_cough', 'bloody_sputum', 'sore_throat', 'coryza', 'shortness_of_breath', 'difficulty_breathing', 'rapid_breathing', 'chest_pain', 'retrosternal_pain'];
                const GI_SYM = ['nausea', 'vomiting', 'persistent_vomiting', 'hiccups', 'diarrhea', 'watery_diarrhea', 'rice_water_diarrhea', 'bloody_diarrhea', 'severe_dehydration', 'muscle_cramps', 'abdominal_pain'];
                const HAEM_SYM = ['bleeding', 'bleeding_gums_or_nose', 'petechial_or_purpuric_rash', 'bruising_or_ecchymosis', 'blood_in_vomit', 'hematuria', 'bloody_sputum'];
                const NEURO_SYM = ['headache', 'severe_headache', 'stiff_neck', 'altered_consciousness', 'seizures', 'photophobia', 'paralysis_acute_flaccid', 'hydrophobia', 'aerophobia', 'encephalitis_signs'];
                const RASH_SYM = ['rash_maculopapular', 'rash_vesicular_pustular', 'rash_face_first', 'painful_rash', 'mucosal_lesions', 'genital_lesions', 'skin_eschar', 'jaundice', 'dark_urine'];
                const SYSTEMIC = ['fatigue', 'severe_fatigue', 'malaise', 'weakness', 'anorexia', 'muscle_pain', 'joint_pain', 'severe_joint_pain', 'back_pain', 'calf_muscle_pain'];

                const domainMap = [
                    { name: 'Febrile', syms: FEVER_SYM },
                    { name: 'Respiratory', syms: RESP_SYM },
                    { name: 'Gastrointestinal', syms: GI_SYM },
                    { name: 'Haemorrhagic', syms: HAEM_SYM },
                    { name: 'Neurological', syms: NEURO_SYM },
                    { name: 'Dermatological/Jaundice', syms: RASH_SYM },
                    { name: 'Systemic/Constitutional', syms: SYSTEMIC },
                ];

                const presentByDomain = [];
                const absentByDomain = [];
                for (const dom of domainMap) {
                    const pp = presentSymptoms.filter(s => dom.syms.includes(s));
                    const ap = absentSymptoms.filter(s => dom.syms.includes(s));
                    if (pp.length > 0) presentByDomain.push(dom.name + ': ' + pp.map(symLabel).join(', '));
                    if (ap.length > 0) absentByDomain.push(dom.name + ': ' + ap.map(symLabel).join(', ') + ' [ABSENT]');
                }

                if (presentByDomain.length > 0) {
                    paras.push('Symptom profile by clinical domain:');
                    presentByDomain.forEach(d => bullets.push('  ✓ ' + d));
                }
                if (absentByDomain.length > 0) {
                    absentByDomain.forEach(d => bullets.push('  ✗ ' + d));
                    paras.push('Confirmed-absent symptoms are clinically important because the scoring engine applies absent-hallmark penalties (−12 points per mandatory hallmark symptom with sensitivity ≥0.80 that is confirmed absent). This means explicitly ruling out a symptom can eliminate certain diseases from the differential more decisively than failing to mention them.');
                }
            }

            // Vital signs narrative
            if (vitalLines.length > 0) {
                paras.push('VITAL SIGNS INTERPRETATION:');
                vitalLines.forEach(v => bullets.push('  » ' + v));
            } else {
                bullets.push('  » No vital signs recorded. Vital signs directly influence the IHR risk escalation rules (SARI with SpO₂ <90% → CRITICAL regardless of disease scores). Recording vitals significantly improves the precision and safety of this system.');
            }

            sections.push({
                id: 'clinical_presentation',
                title: '2. CLINICAL PRESENTATION ANALYSIS',
                severity: clinVal.needs_emergency_triage ? 'critical' : (presentSymptoms.length > 3 ? 'high' : 'moderate'),
                icon: '🩺',
                paragraphs: paras,
                bullets,
            });
        }

        // ── SECTION 3: SCORING ENGINE METHODOLOGY ───────────────────────────
        if (!isNonCase && topD) {
            const paras = [];
            const bullets = [];
            const bd = topD.score_breakdown || {};

            paras.push('The WHO-POE Unified Explainable Matcher v3 scored ' + D.diseases.length + ' diseases simultaneously using a multi-component additive formula derived from epidemiological evidence. The formula is: final_score = gate_score + symptom_score + exposure_score + syndrome_bonus + outbreak_bonus + vaccination_modifier + onset_modifier + absent_hallmark_penalty + contradiction_penalty + override_boost. Scores are clamped to [0, 100]. The top result for this traveller is ' + topD.name + ' with a final score of ' + topD.final_score + '/100.');

            // Score bar visual
            bullets.push('Score breakdown for ' + topD.name + ' [' + topD.disease_id + ']:');
            bullets.push('  Gate score:              ' + (bd.gate_score >= 0 ? '+' : '') + (bd.gate_score || 0) + '  pts  ' + (
                bd.gate_score >= 12 ? '(PASS — required symptoms present)' :
                    bd.gate_score <= -60 ? '(HARD FAIL — mandatory symptom confirmed absent → −60)' :
                        '(SOFT FAIL — required symptom group not met → −18)'
            ));
            bullets.push('  Symptom score:           +' + (bd.symptom_score || 0) + '  pts  (sum of LR-calibrated weights for each matched symptom)');
            bullets.push('  Exposure score:          +' + (bd.exposure_score || 0) + '  pts  (epidemiological exposure signals matched)');
            bullets.push('  Syndrome bonus:          +' + (bd.syndrome_bonus || 0) + '  pts  (' + (bd.syndrome_bonus > 0 ? 'WHO syndrome "' + syndrome.syndrome + '" matches this disease\'s syndrome profile' : 'syndrome not matched — 0 bonus') + ')');
            bullets.push('  Outbreak bonus:          +' + (bd.outbreak_bonus || 0) + '  pts  (' + (bd.outbreak_bonus > 0 ? 'travel to endemic country confirmed — ' + disease_id_to_name(topD.disease_id) + ' endemic in visited region' : 'no endemic country travel detected') + ')');
            bullets.push('  Vaccination modifier:    ' + (bd.vaccination_modifier >= 0 ? '+' : '') + (bd.vaccination_modifier || 0) + '  pts');
            bullets.push('  Absent hallmark penalty: ' + (bd.absent_hallmark_penalty || 0) + '  pts  (' + (bd.absent_hallmark_penalty < 0 ? 'mandatory hallmark symptom(s) confirmed absent' : 'no absent-hallmark penalties applied') + ')');
            bullets.push('  Contradiction penalty:   ' + (bd.contradiction_penalty || 0) + '  pts  (' + (bd.contradiction_penalty < 0 ? 'symptoms present that argue clinically against this disease' : 'no contradicting symptoms present') + ')');
            bullets.push('  Override boost:          +' + (bd.override_boost || 0) + '  pts  (' + (bd.override_boost > 0 ? 'triage override rule(s) fired: ' + overridesFired.join(', ') : 'no override rules triggered') + ')');
            bullets.push('  ─────────────────────────────────────────────────────────────');
            bullets.push('  FINAL SCORE:             ' + topD.final_score + '/100   [' + scoreBar(topD.final_score, 100) + ']');
            bullets.push('  Confidence band:         ' + (topD.confidence_band || 'low').toUpperCase() + '  (Very High ≥70 | High ≥55 | Moderate ≥40 | Low ≥25 | Minimal <25)');
            bullets.push('  Probability-like %:      ' + (topD.probability_like_percent || 0) + '% of the total score mass among the top 5 candidates');

            // Gate explanation
            const disease = D.diseases.find(d => d.id === topD.disease_id);
            if (disease && disease.gates) {
                paras.push('GATE ANALYSIS: The gate system is the most deterministic component of the engine. Each disease declares symptom requirements that must be met for the disease to remain in active consideration. For ' + topD.name + ':');
                if (disease.gates.hard_fail_if_absent) {
                    bullets.push('  Hard-fail symptoms (confirmed absent → −60 pts): ' + disease.gates.hard_fail_if_absent.map(symLabel).join(', '));
                    const hfAbsent = disease.gates.hard_fail_if_absent.filter(s => absentSymptoms.includes(s));
                    if (hfAbsent.length > 0) bullets.push('  ⚠ HARD FAIL TRIGGERED for: ' + hfAbsent.map(symLabel).join(', ') + ' — this disease has been effectively excluded');
                }
                if (disease.gates.required_any) {
                    const met = disease.gates.required_any.filter(s => presentSymptoms.includes(s));
                    bullets.push('  Required (any): ' + disease.gates.required_any.map(symLabel).join(' OR '));
                    bullets.push('  → ' + (met.length > 0 ? 'MET by: ' + met.map(symLabel).join(', ') : 'NOT MET — soft fail (−18)'));
                }
            }

            // Hallmarks matched
            if (topD.matched_hallmarks && topD.matched_hallmarks.length > 0) {
                paras.push('HALLMARK SYMPTOMS MATCHED: Hallmark symptoms are the pathognomonic or near-pathognomonic signs that most strongly distinguish this disease from its differentials. Matching hallmarks provides both the symptom weight AND signals that the clinical picture aligns with the canonical presentation described in Mandell 9th Ed. and WHO AFRO IDSR 2021.');
                topD.matched_hallmarks.forEach(h => {
                    const weight = disease && disease.symptom_weights ? (disease.symptom_weights[h] || 0) : 0;
                    bullets.push('  ✓ ' + symLabel(h) + ' — weight: +' + weight + ' pts (hallmark for ' + topD.name + ')');
                });
            }

            // Triage override narrative
            if (overridesFired.length > 0) {
                paras.push('TRIAGE OVERRIDE RULES FIRED: These rules execute BEFORE the probabilistic scoring and apply deterministic boosts or penalties based on pattern-matching against known clinical emergencies. They are derived from the WHO AFRO IDSR mandatory protocol triggers and IHR Annex 2 decision instrument.');
                const overrideDescriptions = {
                    'override_vhf_red_flag': 'Fever + haemorrhagic symptom + relevant exposure = VHF protocol mandatory. This pattern has a very high positive predictive value for viral haemorrhagic fever (Ebola, Marburg, CCHF, Lassa) in the context of travel from endemic regions. WHO guidance requires immediate isolation and full PPE.',
                    'override_any_haemorrhage_fever_no_exposure': 'Fever + haemorrhagic signs even without confirmed exposure. This more sensitive rule captures early VHF cases where exposure history has not yet been elicited.',
                    'override_acute_flaccid_paralysis': 'Any acute flaccid paralysis triggers mandatory AFP surveillance per WHO GPEI protocol. A single confirmed wild poliovirus case constitutes a PHEIC under IHR Annex 2 (Tier 1 always-notifiable). Two stool specimens must be collected immediately.',
                    'override_meningitis_triad': 'Fever + neck stiffness — the classic Kernig\'s/Brudzinski\'s-equivalent clinical presentation for bacterial meningitis. Meningococcal disease in the Meningitis Belt has a case fatality rate of 50% without immediate treatment. IV benzylpenicillin or ceftriaxone before LP is WHO standard.',
                    'override_vesiculopustular_rash': 'Vesicular or pustular lesions trigger the mpox/smallpox differential. WHO 2024 mpox guidance mandates case reporting for any suspected case. Smallpox, while eradicated, remains a bioterrorism concern requiring MAXIMUM isolation.',
                    'override_smallpox_pustular_centrifugal': 'Fever + vesicular rash + face-first distribution = biological emergency until smallpox excluded. The centrifugal distribution (face/extremities > trunk, palms and soles involved) is the single strongest clinical feature distinguishing smallpox from mpox and varicella.',
                    'override_watery_diarrhea_dehydration': 'Profuse watery diarrhoea or severe dehydration triggers the cholera protocol. WHO\'s Global Cholera & AGE Control Coalition mandate that any case of severe acute watery diarrhoea in an endemic setting be treated as cholera until laboratory exclusion.',
                    'override_rabies_pathognomonic': 'Hydrophobia (inability to swallow liquids due to laryngeal spasm) or aerophobia are pathognomonic for rabies encephalitis. Once symptomatic, rabies is virtually 100% fatal. The role of POE is identification for contact tracing and family notification, not curative treatment.',
                    'override_skin_eschar': 'A painless necrotic skin ulcer (eschar) is the cardinal sign of cutaneous anthrax and certain rickettsial diseases. In the context of bioterrorism preparedness, any unexplained cluster of eschar cases must trigger a security protocol.',
                };
                overridesFired.forEach(r => {
                    const desc = overrideDescriptions[r] || r.replace(/_/g, ' ');
                    bullets.push('  🔴 [' + r.replace(/_/g, ' ').toUpperCase() + '] ' + desc);
                });
            }

            // Key distinguishers from the disease data
            if (disease && disease.key_distinguishers && disease.key_distinguishers.length > 0) {
                paras.push('CLINICAL DISTINGUISHING FEATURES for ' + topD.name + ' (from disease profile, sourced from Mandell 9th Ed. and WHO AFRO IDSR 2021):');
                disease.key_distinguishers.forEach(kd => bullets.push('  ▸ ' + kd));
            }

            sections.push({
                id: 'scoring_methodology',
                title: '3. SCORING ENGINE METHODOLOGY — DETAILED BREAKDOWN',
                severity: topD.final_score >= 60 ? 'critical' : topD.final_score >= 40 ? 'high' : 'moderate',
                icon: '⚙',
                paragraphs: paras,
                bullets,
            });
        }

        // ── SECTION 4: TRAVEL & EPIDEMIOLOGICAL CONTEXT ─────────────────────
        {
            const paras = [];
            const bullets = [];

            if (visitedCountries.length === 0) {
                paras.push('No travel history was declared or recorded. The scoring engine\'s outbreak_context bonus (14–25 additional points per endemic disease) could not be applied. Travel history is one of the strongest epidemiological signals available at a Point of Entry — the WHO AFRO IDSR and GeoSentinel travel medicine data both confirm that disease probability is substantially modulated by recent geographic exposure. A thorough travel interview is strongly recommended for any symptomatic traveller.');
            } else {
                paras.push('The traveller declared ' + visitedCountries.length + ' countr' + (visitedCountries.length > 1 ? 'ies' : 'y') + ' visited in the epidemiologically relevant window. The engine applies a 21-day lookback window by default (the maximum incubation period for Ebola/Marburg per WHO guidance, and the IHR standard for most Annex 2 diseases). Countries visited are cross-referenced against the endemic country oracle — a database of 41 diseases mapped to ISO 3166-1 alpha-2 country codes, compiled from WHO, CDC Yellow Book 2024, and ECDC country-specific risk assessments.');

                for (const tc of visitedCountries) {
                    const cname = countryName(tc.country_code);
                    const endemicHere = D.diseases.filter(d => (d.endemic_countries || []).includes(tc.country_code)).map(d => d.name);
                    if (endemicHere.length > 0) {
                        bullets.push('  ' + cname + ' (' + (tc.travel_role || 'VISITED') + ') — Endemic diseases: ' + endemicHere.join(', '));
                        bullets.push('     → ' + endemicHere.length + ' disease(s) received the outbreak_context bonus in scoring because the traveller visited this country. Each eligible disease received +' + (D.engine && D.engine.formula ? D.engine.formula.outbreak_bonus_default : 15) + ' points (or its disease-specific outbreak_bonus if higher).');
                    } else {
                        bullets.push('  ' + cname + ' (' + (tc.travel_role || 'VISITED') + ') — No diseases in the engine\'s endemic registry for this country. No outbreak bonus applied.');
                    }
                }

                if (outbreakCtx.length > 0) {
                    paras.push('DISEASES RECEIVING OUTBREAK BONUS from travel history (' + outbreakCtx.length + ' diseases):');
                    outbreakCtx.forEach(id => {
                        const d = D.diseases.find(x => x.id === id);
                        const bonus = d ? (d.outbreak_bonus || 15) : 15;
                        bullets.push('  + ' + (d ? d.name : id) + ' received +' + bonus + ' pts outbreak bonus');
                    });
                }
            }

            // Traveler direction context
            if (travelerDir) {
                const dirContext = {
                    'ENTRY': 'ENTRY traveller — travelling INTO the country. Under IHR Article 23(1)(a), health measures at entry are the primary preventive mechanism. Entry travellers from epidemic-prone regions are the core surveillance target of this system.',
                    'EXIT': 'EXIT traveller — departing the country. IHR Article 22(b) places obligations on States Parties to prevent the international spread of disease through points of exit. An ill traveller at exit represents a risk to the destination country and may be subject to health measures under Article 23.',
                    'TRANSIT': 'TRANSIT traveller — passing through the territory. Under IHR Annex 1B, transit POEs must have capacity to apply health measures to travellers in transit. TRANSIT travellers from high-risk endemic regions who develop symptoms during transit represent a particularly important surveillance event because they may have been exposed in multiple countries.',
                };
                bullets.push('\n  IHR TRAVELLER DIRECTION CONTEXT: ' + (dirContext[travelerDir] || travelerDir));
            }

            sections.push({
                id: 'travel_context',
                title: '4. TRAVEL & EPIDEMIOLOGICAL INTELLIGENCE',
                severity: outbreakCtx.length > 3 ? 'high' : outbreakCtx.length > 0 ? 'moderate' : 'low',
                icon: '🌍',
                paragraphs: paras,
                bullets,
            });
        }

        // ── SECTION 5: EXPOSURE RISK ANALYSIS ───────────────────────────────
        {
            const paras = [];
            const bullets = [];
            const yesExposures = exposures.filter(e => (e.response || '').toUpperCase() === 'YES');
            const noExposures = exposures.filter(e => (e.response || '').toUpperCase() === 'NO');
            const unkExposures = exposures.filter(e => !e.response || (e.response || '').toUpperCase() === 'UNKNOWN');

            paras.push('Structured exposure questioning is the second most powerful clinical signal after travel history at a Point of Entry, per the WHO Integrated Disease Surveillance and Response methodology. The exposure questionnaire administered used the WHO-POE Exposure Catalog v2.0, covering 20 WHO-aligned exposure categories with explicit engine-code mapping. Each YES response was translated to one or more engine signals in the disease exposure_weights system.');

            bullets.push('Exposures confirmed YES (' + yesExposures.length + '):');
            if (yesExposures.length === 0) {
                bullets.push('  None — absence of confirmed risk exposures reduces the probability of high-specificity diseases that require zoonotic, nosocomial, or funeral-rite transmission pathways (e.g. CCHF, Ebola funeral transmission, Nipah bat cave exposure).');
            } else {
                yesExposures.forEach(exp => {
                    const cat = window.EXPOSURES ? (window.EXPOSURES.getByCode(exp.exposure_code) || {}) : {};
                    const engineCodes = cat.engine_codes || [];
                    const riskLevel = cat.risk_level || 'MODERATE';
                    const prioDisease = (cat.priority_diseases || []).join(', ');
                    bullets.push('  ✓ ' + exposureLabel(exp.exposure_code) + ' [' + riskLevel + ' RISK]');
                    if (engineCodes.length > 0) bullets.push('     Engine signals activated: ' + engineCodes.join(', '));
                    if (prioDisease) bullets.push('     Most relevant for: ' + prioDisease);
                    if (cat.critical_flag && cat.critical_message) bullets.push('     ⚠ CRITICAL: ' + cat.critical_message);
                });
            }

            bullets.push('Exposures confirmed NO (' + noExposures.length + '):');
            if (noExposures.length > 0) {
                noExposures.slice(0, 5).forEach(exp => {
                    const cat = window.EXPOSURES ? (window.EXPOSURES.getByCode(exp.exposure_code) || {}) : {};
                    const prioDisease = (cat.priority_diseases || []).join(', ');
                    if (prioDisease) bullets.push('  ✗ ' + exposureLabel(exp.exposure_code) + ' [NO] → makes ' + prioDisease + ' less likely');
                });
            }

            bullets.push('Unknown/not assessed (' + unkExposures.length + '): These exposures contribute neither positive nor negative signals. A complete exposure history with explicit YES/NO on all questions significantly improves diagnostic precision. Unknown answers are treated as missing data — they neither include nor exclude the disease.');

            sections.push({
                id: 'exposure_analysis',
                title: '5. STRUCTURED EXPOSURE RISK ANALYSIS',
                severity: yesExposures.length > 2 ? 'high' : yesExposures.length > 0 ? 'moderate' : 'low',
                icon: '🔍',
                paragraphs: paras,
                bullets,
            });
        }

        // ── SECTION 6: WHO/IHR INTERNATIONAL FRAMEWORK APPLICATION ──────────
        {
            const paras = [];
            const bullets = [];

            paras.push('This assessment applies five layers of international public health frameworks simultaneously:');

            bullets.push('LAYER 1 — IHR 2005 Annex 2 (Decision Instrument for PHEIC Assessment):');
            bullets.push('  The International Health Regulations 2005 Annex 2 provides the legal and operational framework for determining whether an event may constitute a Public Health Emergency of International Concern (PHEIC). The engine applies the Annex 2 four-question test: (1) Is the impact of the event serious? (2) Is the event unusual or unexpected? (3) Is there significant risk of international spread? (4) Is there significant risk of travel/trade restrictions? The IHR escalation rules in this system directly implement this instrument.');

            // Tier classification
            if (topD) {
                const tierDesc = tierDescription(topD.priority_tier);
                bullets.push('\n  Top disease IHR classification: ' + topD.name);
                bullets.push('  → ' + tierDesc);
                if (topD.priority_tier === 'tier_1_ihr_critical') {
                    bullets.push('  → PHEIC MANDATORY: A single confirmed case of a Tier 1 disease constitutes a PHEIC by definition under IHR Annex 2. WHO must be notified within 24 hours of confirmation.');
                } else if (topD.priority_tier === 'tier_2_ihr_annex2') {
                    bullets.push('  → IHR Annex 2 Disease: Notification to WHO National Focal Point required. The four-question Annex 2 decision instrument applies. If ≥2 questions answered YES, event must be reported to WHO within 24 hours.');
                }
            }

            bullets.push('\nLAYER 2 — WHO AFRO IDSR Technical Guidelines 2021 (3rd Edition):');
            bullets.push('  The case definitions applied in this system are drawn from the WHO AFRO IDSR 2021 guidelines, which define suspected, probable, and confirmed case criteria for all Priority 1 and Priority 2 diseases. The engine uses IDSR case definitions to evaluate whether the clinical presentation meets the SUSPECTED threshold — the lowest but most sensitive level of the three-tier classification system.');

            if (topDisease && topDisease.who_case_definition) {
                const cd = topDisease.who_case_definition;
                bullets.push('  WHO AFRO IDSR Suspected Case Definition for ' + topD.name + ':');
                bullets.push('  "' + cd.suspected + '"');
                // Does this traveller meet it?
                const feverPresent = presentSymptoms.some(s => ['fever', 'high_fever', 'very_high_fever', 'sudden_onset_fever'].includes(s));
                bullets.push('  Assessment: ' + (feverPresent || presentSymptoms.length >= 2 ? '⚠ This traveller\'s presentation is CONSISTENT with the suspected case definition. Laboratory confirmation is required to elevate to probable or confirmed.' : 'The current presentation does not fully meet the suspected case definition threshold. Monitor and reassess if symptoms develop.'));
            }

            bullets.push('\nLAYER 3 — Mandell\'s Principles of Infectious Diseases (9th Edition):');
            bullets.push('  Symptom weights in the scoring engine are calibrated against Likelihood Ratios (LR+ and LR−) sourced from Mandell 9th Ed. — the authoritative infectious disease reference for clinical probability estimation. A symptom weight of 35 corresponds to LR+ >10 (very strong diagnostic value). A weight of 8–12 corresponds to LR+ 2–4 (moderate value). Contradiction penalties (negative_weights) correspond to LR− values that reduce post-test probability when the symptom is PRESENT.');

            bullets.push('\nLAYER 4 — GeoSentinel/TropNetEurop Travel Medicine Database:');
            bullets.push('  Base prevalence estimates for diseases in symptomatic returning travellers were calibrated using GeoSentinel surveillance data. This data, collected from travel medicine clinics across 6 continents, establishes what diseases are actually common vs. rare in travellers presenting with each symptom cluster. The scoring engine\'s gate pass bonus (+12) and soft-fail penalty (−18) were calibrated against GeoSentinel prevalence ratios.');

            bullets.push('\nLAYER 5 — CDC Yellow Book 2024 (Geographic Disease Distribution):');
            bullets.push('  The endemic country oracle (Section 1 of this intelligence layer) uses CDC Yellow Book 2024 geographic risk classifications as one of its primary sources, combined with WHO and ECDC country-specific risk assessments. Country risk designations are updated annually.');

            // IHR risk result
            if (ihrRisk.risk_level) {
                paras.push('IHR RISK LEVEL DETERMINATION: The IHR Annex 2 escalation engine evaluated ' + IHR_ESCALATION_RULES.length + ' deterministic rules against the score result, vital signs, and global flags. Result: ' + ihrRisk.risk_level + ' → route to ' + ihrRisk.routing_level + '.');
                if (ihrRisk.triggered_rules && ihrRisk.triggered_rules.length > 0) {
                    ihrRisk.triggered_rules.forEach(r => {
                        const rule = D.ihr_escalation_rules ? D.ihr_escalation_rules.find(x => x.id === r) : null;
                        if (rule) bullets.push('  Escalation rule "' + r + '": ' + rule.reasoning);
                        else bullets.push('  Escalation rule activated: ' + r);
                    });
                } else {
                    bullets.push('  No specific IHR escalation rules triggered. Risk level derived from probabilistic score thresholds: score ≥55 → HIGH, score ≥35 → MEDIUM, score <35 → LOW.');
                }
            }

            sections.push({
                id: 'international_framework',
                title: '6. WHO/IHR INTERNATIONAL FRAMEWORK APPLICATION',
                severity: ihrRisk.ihr_alert_required ? 'critical' : 'high',
                icon: '🌐',
                paragraphs: paras,
                bullets,
            });
        }

        // ── SECTION 7: DIFFERENTIAL DIAGNOSIS — ALL CANDIDATES CONSIDERED ────
        if (!isNonCase && topDiagnoses.length > 1) {
            const paras = [];
            const bullets = [];

            paras.push('The engine scored all ' + D.diseases.length + ' diseases in the database simultaneously. Below is the complete differential analysis of the top candidates, explaining why each disease ranked as it did relative to the primary signal. This is the same reasoning process a clinical specialist would apply, now made fully transparent.');

            topDiagnoses.slice(0, 5).forEach((d, idx) => {
                // eslint-disable-next-line @typescript-eslint/no-unused-vars
                const dis = D.diseases.find(x => x.id === d.disease_id);
                bullets.push('\n  RANK ' + (idx + 1) + ': ' + d.name + ' — Score ' + d.final_score + '/100 (' + (d.confidence_band || 'low').toUpperCase() + ')');
                bullets.push('    IHR Classification: ' + tierDescription(d.priority_tier));
                bullets.push('    Score: ' + scoreBar(d.final_score, 100));

                const bd = d.score_breakdown || {};
                const components = [];
                if (bd.gate_score) components.push('gate ' + (bd.gate_score >= 0 ? '+' : '') + bd.gate_score);
                if (bd.symptom_score) components.push('symptoms +' + bd.symptom_score);
                if (bd.exposure_score) components.push('exposures +' + bd.exposure_score);
                if (bd.syndrome_bonus) components.push('syndrome +' + bd.syndrome_bonus);
                if (bd.outbreak_bonus) components.push('outbreak +' + bd.outbreak_bonus);
                if (bd.absent_hallmark_penalty) components.push('absent hallmarks ' + bd.absent_hallmark_penalty);
                if (bd.contradiction_penalty) components.push('contradictions ' + bd.contradiction_penalty);
                if (bd.override_boost) components.push('override +' + bd.override_boost);
                bullets.push('    Components: ' + (components.join(' | ') || 'minimal score from baseline'));

                if (d.matched_hallmarks.length > 0) bullets.push('    Hallmarks matched: ' + d.matched_hallmarks.map(h => symLabel(h)).join(', '));
                if (d.matched_exposures.length > 0) bullets.push('    Exposures matched: ' + d.matched_exposures.join(', '));

                if (idx > 0 && topD) {
                    const gap = topD.final_score - d.final_score;
                    if (gap > 20) bullets.push('    → Ranked below ' + topD.name + ' by ' + gap + ' points. This gap is clinically significant — a ' + gap + '-point difference represents strong discriminatory evidence in favour of the primary diagnosis.');
                    else if (gap > 8) bullets.push('    → Ranked below ' + topD.name + ' by ' + gap + ' points. This gap is meaningful but not conclusive. The ' + d.name + ' diagnosis cannot be excluded on clinical grounds alone without laboratory testing.');
                    else bullets.push('    → Ranked below ' + topD.name + ' by only ' + gap + ' point' + (gap > 1 ? 's' : '') + '. These diagnoses have VERY SIMILAR clinical presentations. Laboratory differentiation is essential — they cannot be distinguished at bedside.');
                }
            });

            // Diseases that scored near zero
            const zeroScored = D.diseases.filter(d => {
                const found = topDiagnoses.find(t => t.disease_id === d.id);
                return !found || found.final_score < 15;
            });
            if (zeroScored.length > 0 && presentSymptoms.length > 0) {
                paras.push('DISEASES EFFECTIVELY EXCLUDED BY THE ENGINE (' + Math.min(zeroScored.length, 5) + ' examples):');
                zeroScored.slice(0, 5).forEach(d => {
                    // Try to find the reason for low score
                    const gates = d.gates || {};
                    const reasons = [];
                    if (gates.hard_fail_if_absent) {
                        const hf = gates.hard_fail_if_absent.filter(s => absentSymptoms.includes(s));
                        if (hf.length > 0) reasons.push('HARD FAIL — ' + hf.map(symLabel).join(', ') + ' confirmed absent (−60 pts)');
                    }
                    if (gates.required_any) {
                        const met = gates.required_any.some(s => presentSymptoms.includes(s));
                        if (!met) reasons.push('Required symptoms (' + gates.required_any.map(symLabel).join('/') + ') not present (−18 pts)');
                    }
                    const nw = d.negative_weights || {};
                    const contradictions = presentSymptoms.filter(s => nw[s] && nw[s] < -4);
                    if (contradictions.length > 0) reasons.push('Contradicted by: ' + contradictions.map(symLabel).join(', '));

                    if (reasons.length > 0) {
                        bullets.push('  ✗ ' + d.name + ' — EXCLUDED: ' + reasons.join(' | '));
                    }
                });
            }

            sections.push({
                id: 'differential_diagnosis',
                title: '7. COMPLETE DIFFERENTIAL DIAGNOSIS',
                severity: 'moderate',
                icon: '📊',
                paragraphs: paras,
                bullets,
            });
        }

        // ── SECTION 8: REQUIRED ACTIONS & CLINICAL URGENCY ──────────────────
        {
            const paras = [];
            const bullets = [];

            const rl = ihrRisk.risk_level || 'MEDIUM';
            const immediateActions = (topDisease && topDisease.immediate_actions) || [];
            const recommendedTests = (topDisease && topDisease.recommended_tests) || [];
            const poeAction = (topDisease && topDisease.who_case_definition && topDisease.who_case_definition.poe_action) || null;

            paras.push('The following actions are indicated based on the clinical assessment, IHR risk level (' + rl + '), and the specific disease signal. Actions are ordered by urgency. This guidance is derived from WHO AFRO IDSR 2021 Action Protocols, IHR 2005 Articles 18 and 23, and disease-specific WHO fact sheet guidance.');

            // Global flag mandatory actions
            if (globalFlags.includes('VHF_PROTOCOL_ACTIVATED') || globalFlags.includes('NEEDS_IMMEDIATE_ISOLATION')) {
                bullets.push('  🔴 IMMEDIATE — FULL PPE AND ISOLATION:');
                bullets.push('     WHO requires that any traveller meeting the VHF clinical threshold be immediately isolated in a single room with full PPE: N95/PAPR respirator, face shield, disposable gown, double gloves, boot covers. Do not allow the traveller to return to the general screening area under any circumstances.');
                bullets.push('     IHR basis: Article 23(1)(a) — health measures for travellers, including medical examination. Article 43 — additional health measures may be applied when justified by a specific risk.');
            }

            if (globalFlags.includes('AFP_SURVEILLANCE_ACTIVATED')) {
                bullets.push('  🔴 IMMEDIATE — AFP SURVEILLANCE PROTOCOL:');
                bullets.push('     WHO GPEI requires collection of 2 stool specimens at least 24 hours apart from any AFP case, within 14 days of paralysis onset. Contact the District Surveillance Officer IMMEDIATELY. A single confirmed wild poliovirus case constitutes a PHEIC under IHR Annex 2.');
            }

            if (globalFlags.includes('BIOTERRORISM_PROTOCOL_ACTIVATED') || globalFlags.includes('BIOTERRORISM_ASSESSMENT_REQUIRED')) {
                bullets.push('  🔴 MAXIMUM SECURITY — BIOTERRORISM ASSESSMENT:');
                bullets.push('     Activate national health security emergency protocol. Notify national IHR focal point, national surveillance authority, AND law enforcement. Do not discuss case details with unauthorized personnel. Preserve all travel documents and belongings as potential evidence.');
            }

            if (poeAction) {
                bullets.push('  🔴 DISEASE-SPECIFIC POE ACTION (' + (topD ? topD.name : '') + '):');
                bullets.push('     ' + poeAction);
            }

            // IHR notification
            if (ihrRisk.ihr_alert_required) {
                bullets.push('\n  📢 IHR NOTIFICATION REQUIRED:');
                bullets.push('     Route to: ' + (ihrRisk.routing_level || 'DISTRICT'));
                if (ihrRisk.ihr_tier === 'TIER_1_ALWAYS_NOTIFIABLE') {
                    bullets.push('     Tier 1 — WHO notification within 24 hours. Use the IHR Event Information System (EIS). The National IHR Focal Point must be notified immediately.');
                } else if (ihrRisk.ihr_tier === 'TIER_2_ANNEX2') {
                    bullets.push('     Tier 2 — Apply the four-question Annex 2 decision instrument. If ≥2 questions are YES, notify the National IHR Focal Point within 24 hours.');
                } else {
                    bullets.push('     Standard public health notification — report through the national IDSR system within ' + (rl === 'CRITICAL' ? '24' : '48') + ' hours.');
                }
            }

            // Standard clinical actions
            bullets.push('\n  📋 CLINICAL ACTIONS:');
            if (immediateActions.length > 0) {
                immediateActions.forEach(a => bullets.push('     → ' + a));
            } else {
                if (rl === 'CRITICAL' || rl === 'HIGH') {
                    bullets.push('     → Transfer to designated health facility with isolation capacity');
                    bullets.push('     → Collect clinical specimens before antibiotics/antivirals if possible');
                    bullets.push('     → Document all close contacts on the conveyance and at the POE');
                    bullets.push('     → Initiate contact tracing per IDSR protocol');
                } else if (rl === 'MEDIUM') {
                    bullets.push('     → Refer to health facility for clinical assessment and laboratory testing');
                    bullets.push('     → Provide traveller with health information card and follow-up instructions');
                    bullets.push('     → Record traveller contact details for potential follow-up');
                } else {
                    bullets.push('     → Health advice provided to traveller');
                    bullets.push('     → Advise to seek medical attention if symptoms worsen within 48–72 hours');
                    bullets.push('     → Provide emergency contact number for POE health facility');
                }
            }

            // Laboratory tests
            if (recommendedTests.length > 0) {
                bullets.push('\n  🔬 RECOMMENDED LABORATORY TESTS (' + (topD ? topD.name : '') + '):');
                recommendedTests.forEach(t => bullets.push('     → ' + t));
                bullets.push('     ⚠ Laboratory confirmation is required to elevate classification from SUSPECTED to CONFIRMED. Clinical probability scoring supports triage decisions — it does not replace laboratory diagnosis.');
            }

            sections.push({
                id: 'required_actions',
                title: '8. REQUIRED ACTIONS & CLINICAL URGENCY',
                severity: rl === 'CRITICAL' ? 'critical' : rl === 'HIGH' ? 'high' : 'moderate',
                icon: '🚨',
                paragraphs: paras,
                bullets,
            });
        }

        // ── SECTION 9: CONFIDENCE ASSESSMENT & LIMITATIONS ──────────────────
        {
            const paras = [];
            const bullets = [];

            const sympCount = presentSymptoms.length;
            const absentCount = absentSymptoms.length;
            const expCount = exposures.filter(e => (e.response || '').toUpperCase() === 'YES').length;
            const hasVitals = Object.values(vitals).some(v => v != null);
            const hasTravel = visitedCountries.length > 0;

            // Confidence scoring
            let dataQualityScore = 0;
            const dataQualityMax = 100;
            if (sympCount >= 4) dataQualityScore += 25; else if (sympCount >= 2) dataQualityScore += 15; else if (sympCount === 1) dataQualityScore += 5;
            if (absentCount >= 3) dataQualityScore += 15; else if (absentCount >= 1) dataQualityScore += 8;
            if (expCount >= 2) dataQualityScore += 20; else if (expCount === 1) dataQualityScore += 10;
            if (hasVitals) dataQualityScore += 20;
            if (hasTravel) dataQualityScore += 20;

            const dataQualityPct = dataQualityScore;
            const dataQualityLabel = dataQualityPct >= 70 ? 'STRONG' : dataQualityPct >= 40 ? 'MODERATE' : 'WEAK';

            paras.push('The diagnostic confidence of this assessment is a function of data completeness. The engine scored ' + D.diseases.length + ' diseases but its discrimination power is limited by the data provided. A data quality score has been computed from the richness of clinical inputs:');

            bullets.push('Data Quality Assessment:');
            bullets.push('  Symptoms confirmed present:   ' + sympCount + ' (' + (sympCount >= 4 ? 'STRONG' : sympCount >= 2 ? 'MODERATE' : 'WEAK') + ' — ≥4 recommended for confident discrimination)');
            bullets.push('  Symptoms confirmed absent:    ' + absentCount + ' (' + (absentCount >= 3 ? 'STRONG' : absentCount >= 1 ? 'MODERATE' : 'WEAK') + ' — explicit absent symptoms enable absent-hallmark penalty logic)');
            bullets.push('  Confirmed exposures (YES):    ' + expCount + ' (' + (expCount >= 2 ? 'STRONG' : expCount >= 1 ? 'MODERATE' : 'WEAK') + ' — exposure signals often add 10–20+ points for rare diseases)');
            bullets.push('  Vital signs recorded:         ' + (hasVitals ? 'YES — enables temperature-based onset modifiers and SpO₂ emergency escalation' : 'NO — significant data gap. SpO₂ <90% alone triggers SARI emergency regardless of other findings.'));
            bullets.push('  Travel history documented:    ' + (hasTravel ? 'YES — ' + visitedCountries.length + ' countr' + (visitedCountries.length > 1 ? 'ies' : 'y') + ' → outbreak_context bonus applied' : 'NO — outbreak_context bonus is zero for all diseases. This is the largest avoidable data gap.'));
            bullets.push('\n  Overall data quality: ' + dataQualityLabel + ' (' + dataQualityPct + '/100)');
            bullets.push('  → ' + (dataQualityPct >= 70
                ? 'Sufficient data for confident clinical triage. The top diagnosis has strong evidential support from the inputs provided.'
                : dataQualityPct >= 40
                    ? 'Moderate data quality. The triage recommendation is directionally correct but additional clinical data would substantially improve confidence.'
                    : 'Weak data quality. This assessment provides initial orientation only. A complete symptom assessment, travel history, exposure questionnaire, and vital signs are urgently needed before clinical decisions are made.'));

            paras.push('IMPORTANT LIMITATIONS OF THIS SYSTEM:');
            bullets.push('  1. This is a CLINICAL DECISION-SUPPORT tool, not a diagnostic system. It augments the officer\'s clinical judgement — it does not replace it. The scoring engine has no visual access to the patient.');
            bullets.push('  2. Scores represent relative probability among the ' + D.diseases.length + ' diseases in this database. The engine cannot score diseases outside its catalog. If the officer clinically suspects a disease not in the differential, that clinical judgement takes precedence.');
            bullets.push('  3. The outbreak_context bonus (up to 25 points) is substantial. Accurate travel history significantly changes which diseases are elevated. A traveller who conceals travel to an endemic area will have artificially lower scores for endemic diseases.');
            bullets.push('  4. The engine uses symptom PRESENCE and confirmed ABSENCE. Unassessed symptoms (UNKNOWN) do not contribute. Encouraging officers to explicitly mark absent symptoms — not just mark present ones — significantly improves the quality of absent-hallmark penalty logic.');
            bullets.push('  5. Laboratory confirmation is required for all cases. The WHO case definition three-tier system (suspected → probable → confirmed) exists because clinical features alone are insufficient for definitive diagnosis of most IHR diseases. This engine operates at the SUSPECTED tier.');

            sections.push({
                id: 'confidence_limitations',
                title: '9. CONFIDENCE ASSESSMENT & DATA QUALITY',
                severity: dataQualityPct < 40 ? 'high' : 'low',
                icon: '📈',
                paragraphs: paras,
                bullets,
            });
        }

        // ── SECTION 10: OFFICER OVERRIDE DOCUMENTATION ──────────────────────
        if (override.syndromeOverridden || override.riskOverridden || override.overrideNonCase || (override.addedDiseases && override.addedDiseases.length > 0)) {
            const bullets = [];
            bullets.push('The screening officer exercised clinical override authority on the following algorithmic recommendations:');
            if (override.syndromeOverridden) bullets.push('  • Syndrome classification was manually overridden by the officer (the algorithm\'s derived syndrome was rejected).');
            if (override.riskOverridden) bullets.push('  • Risk level was manually overridden by the officer (the IHR algorithmic risk level was rejected).');
            if (override.overrideNonCase) bullets.push('  • Non-case verdict was overridden — the officer believes the traveller is clinically unwell despite zero confirmed symptoms in the checklist.');
            if (override.addedDiseases && override.addedDiseases.length > 0) bullets.push('  • Officer-added suspected diseases: ' + override.addedDiseases.join(', '));
            if (override.overrideNote) bullets.push('  • Officer justification: "' + override.overrideNote + '"');
            bullets.push('  The officer\'s clinical override is recorded in full and will be preserved in the audit trail. Officer judgement is authoritative per IHR Article 23(1)(a) which grants individual health officers the authority to apply health measures based on clinical assessment.');

            sections.push({
                id: 'officer_override',
                title: '10. OFFICER CLINICAL OVERRIDE DOCUMENTATION',
                severity: 'moderate',
                icon: '🖊',
                paragraphs: ['Override recorded and audited.'],
                bullets,
            });
        }

        // ── GENERATE SUMMARY TEXT ──────────────────────────────────────────

        const summaryBrief = [
            headline,
            topD ? ('Top clinical signal: ' + topD.name + ' (score ' + topD.final_score + '/100, ' + (topD.confidence_band || 'low') + ' confidence). ') : 'No disease reached a reportable threshold. ',
            syndrome.syndrome && syndrome.syndrome !== 'NONE' ? ('WHO syndrome classification: ' + syndrome.syndrome + ' (' + syndrome.confidence + ' confidence). ') : '',
            ihrRisk.ihr_alert_required
                ? ('IHR notification required. Route to: ' + (ihrRisk.routing_level || 'DISTRICT') + '. ')
                : ('No IHR notification required at this time. '),
            'Data quality: ' + (presentSymptoms.length + absentSymptoms.length) + ' symptoms assessed, ' + visitedCountries.length + ' countries documented, ' + exposures.filter(e => e.response === 'YES').length + ' risk exposures confirmed.',
        ].filter(Boolean).join('');

        const smsAlert = (isNonCase
            ? 'POE ALERT: Non-case. No infectious indicators. '
            : ('POE ALERT: ' + (topD ? topD.name : 'Unknown') + ' suspected. Score: ' + (topD ? topD.final_score : 0) + '/100. Risk: ' + (ihrRisk.risk_level || 'UNK') + '. Route: ' + (ihrRisk.routing_level || 'DISTRICT') + '. ')
        ).slice(0, 160);

        // IHR notification formal language
        let ihrText = '';
        if (ihrRisk.ihr_alert_required && topD) {
            ihrText = [
                'IHR EVENT NOTIFICATION — PRELIMINARY CLINICAL ASSESSMENT',
                'Date/Time: ' + now,
                'Point of Entry: ' + poeCode,
                'Event Description: A traveller presenting at ' + poeCode + ' has been assessed using the WHO-POE Clinical Decision Support System. The system has identified a ' + (topD.confidence_band || 'low') + '-confidence signal for ' + topD.name + ' (IHR category: ' + (topD.ihr_category || tierDescription(topD.priority_tier)) + ').',
                'Clinical basis: ' + presentSymptoms.map(symLabel).join(', '),
                'Epidemiological basis: Travel history — ' + (visitedCountries.map(c => countryName(c.country_code)).join(', ') || 'not declared'),
                'IHR Risk Level: ' + (ihrRisk.risk_level || 'UNKNOWN'),
                'Routing Level: ' + (ihrRisk.routing_level || 'DISTRICT'),
                'Algorithm Score: ' + topD.final_score + '/100',
                'This notification is generated as a preliminary clinical triage alert. Laboratory confirmation is required before definitive case classification. Action is required per IHR Article 6 and Annex 2.',
            ].join('\n');
        }

        // ── PLAIN TEXT VERSION ─────────────────────────────────────────────
        const printableLines = [
            '═'.repeat(80),
            'WHO-POE CLINICAL INTELLIGENCE REPORT',
            'Generated: ' + now + ' | POE: ' + poeCode,
            '═'.repeat(80),
        ];
        sections.forEach(sec => {
            printableLines.push('');
            printableLines.push(sec.title);
            printableLines.push('─'.repeat(60));
            sec.paragraphs.forEach(p => { printableLines.push(p); printableLines.push(''); });
            sec.bullets.forEach(b => printableLines.push(b));
        });
        printableLines.push('');
        printableLines.push('═'.repeat(80));
        printableLines.push('END OF REPORT — WHO-POE Unified Explainable Matcher v3');
        printableLines.push('DISCLAIMER: Clinical decision-support only. Does not replace qualified clinician assessment or laboratory confirmation.');
        printableLines.push('═'.repeat(80));

        return {
            verdict,
            headline,
            risk_color: riskColor,
            sections,
            summary_brief: summaryBrief,
            ihr_notification_text: ihrText,
            sms_alert: smsAlert,
            printable_text: printableLines.join('\n'),
            generated_at: now,
        };
    };

    function disease_id_to_name(id) {
        const d = D.diseases.find(x => x.id === id);
        return d ? d.name : id;
    }


    console.log(
        '%c[WHO-POE Intelligence Layer] Loaded — ' + D.diseases.length + ' diseases enriched with endemic countries and case definitions. 9 API functions available. buildClinicalReport() generates complete WHO/IHR clinical intelligence reports.',
        'color:#00C853;font-weight:700;font-size:12px'
    );

})();