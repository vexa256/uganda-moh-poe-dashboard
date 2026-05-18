// Deterministic 200-vignette generator for the IDSR refactor baseline.
// Run with:  node tests/idsr/_baseline/generate-vignettes.mjs
// Output:    tests/idsr/_baseline/vignettes.json
//
// Vignettes cover every IDSR Annex 1A syndrome plus edge cases. Each vignette is
// a deterministic synthetic clinical input — no PII, no real patient data.
// The set is FROZEN once generated (committed) so Phase 6 can diff scoring deltas.

import fs from 'node:fs';

const v = [];
const add = (label, present_symptoms, exposure_engine_codes, opts = {}) => {
  v.push({
    id: 'v' + String(v.length + 1).padStart(3, '0'),
    label,
    present_symptoms,
    absent_symptoms: opts.absent_symptoms || [],
    exposure_engine_codes,
    visited_countries: opts.visited_countries || [],
    vitals: opts.vitals || { temperature_c: null, oxygen_saturation: null }
  });
};

// Helper: build a country-visit
const visited = (cc, role = 'VISITED') => [{ country_code: cc, travel_role: role }];

// =============================================================================
// VHF / acute haemorrhagic fever (30)
// =============================================================================
const vhfBase = ['fever', 'high_fever', 'bleeding', 'severe_fatigue'];
const vhfExposures = ['travel_from_outbreak_area', 'contact_body_fluids', 'close_contact_case'];
for (let i = 0; i < 5; i++) {
  add('VHF: classic Ebola travel', vhfBase, ['travel_from_outbreak_area', 'contact_body_fluids'],
    { visited_countries: visited('CD'), vitals: { temperature_c: 39.2 + i * 0.1, oxygen_saturation: 96 } });
}
for (let i = 0; i < 5; i++) {
  add('VHF: Marburg cave / bat exposure', ['fever', 'severe_fatigue', 'bleeding_gums_or_nose', 'muscle_pain'],
    ['bat_cave_mine_exposure', 'travel_from_outbreak_area'],
    { visited_countries: visited('UG'), vitals: { temperature_c: 39.5, oxygen_saturation: 95 } });
}
for (let i = 0; i < 5; i++) {
  add('VHF: Lassa rodent exposure', ['fever', 'sore_throat', 'bleeding', 'vomiting'],
    ['rodent_exposure', 'travel_from_outbreak_area'],
    { visited_countries: visited('NG'), vitals: { temperature_c: 38.9, oxygen_saturation: 97 } });
}
for (let i = 0; i < 5; i++) {
  add('VHF: CCHF tick + livestock', ['fever', 'bleeding', 'muscle_pain', 'headache'],
    ['tick_bite_endemic', 'livestock_raw_dairy_abattoir'],
    { vitals: { temperature_c: 39.0, oxygen_saturation: 96 } });
}
for (let i = 0; i < 5; i++) {
  add('VHF: funeral exposure no travel', ['fever', 'bleeding', 'malaise'],
    ['funeral_or_burial_exposure', 'contact_dead_body'],
    { vitals: { temperature_c: 38.7, oxygen_saturation: 97 } });
}
for (let i = 0; i < 5; i++) {
  add('VHF: severely ill HCW exposure', ['fever', 'bleeding', 'severe_fatigue'],
    ['healthcare_exposure', 'affected_healthcare_facility_exposure'],
    { vitals: { temperature_c: 39.3, oxygen_saturation: 94 } });
}

// =============================================================================
// AWD / cholera / dehydration <5 (30)
// =============================================================================
for (let i = 0; i < 8; i++) {
  add('AWD: rice-water diarrhoea outbreak', ['watery_diarrhea', 'rice_water_diarrhea', 'severe_dehydration', 'vomiting'],
    ['unsafe_water', 'contaminated_food_or_water'],
    { vitals: { temperature_c: 37.2, oxygen_saturation: 98 } });
}
for (let i = 0; i < 7; i++) {
  add('AWD: cholera-like w/ travel', ['watery_diarrhea', 'severe_dehydration'],
    ['unsafe_water', 'travel_from_outbreak_area'],
    { visited_countries: visited('YE'), vitals: { temperature_c: 37.0, oxygen_saturation: 98 } });
}
for (let i = 0; i < 7; i++) {
  add('Shigellosis: bloody diarrhoea', ['diarrhea', 'bleeding', 'abdominal_pain'],
    ['contaminated_food_or_water'],
    { vitals: { temperature_c: 38.4, oxygen_saturation: 98 } });
}
for (let i = 0; i < 8; i++) {
  add('Dehydration <5: 3+ loose stools', ['watery_diarrhea', 'severe_dehydration'],
    [],
    { vitals: { temperature_c: 37.6, oxygen_saturation: 98 } });
}

// =============================================================================
// RASH + FEVER (30)
// =============================================================================
for (let i = 0; i < 6; i++) {
  add('Measles classic', ['fever', 'rash_maculopapular', 'cough', 'sore_throat'],
    ['close_contact_case'],
    { vitals: { temperature_c: 38.6, oxygen_saturation: 98 } });
}
for (let i = 0; i < 6; i++) {
  add('Rubella', ['fever', 'rash_maculopapular'],
    [],
    { vitals: { temperature_c: 38.0, oxygen_saturation: 98 } });
}
for (let i = 0; i < 6; i++) {
  add('Smallpox: vesicular pustular same-stage', ['fever', 'high_fever', 'rash_vesicular_pustular', 'rash_face_first', 'rash_palms_soles'],
    ['close_contact_case'],
    { vitals: { temperature_c: 39.0, oxygen_saturation: 97 } });
}
for (let i = 0; i < 6; i++) {
  add('Mpox suspect (legacy ID kept readable)', ['fever', 'rash_vesicular_pustular', 'mucosal_lesions'],
    ['sexual_contact', 'close_contact_case'],
    { vitals: { temperature_c: 38.5, oxygen_saturation: 98 } });
}
for (let i = 0; i < 6; i++) {
  add('Yellow fever: fever + jaundice', ['fever', 'jaundice', 'dark_urine', 'vomiting'],
    ['mosquito_exposure'],
    { visited_countries: visited('CD'), vitals: { temperature_c: 38.8, oxygen_saturation: 97 } });
}

// =============================================================================
// MENINGITIS (25)
// =============================================================================
for (let i = 0; i < 8; i++) {
  add('Meningitis: fever + neck stiffness', ['fever', 'high_fever', 'stiff_neck', 'headache'],
    ['crowded_closed_setting'],
    { vitals: { temperature_c: 39.0, oxygen_saturation: 97 } });
}
for (let i = 0; i < 8; i++) {
  add('Meningitis: belt season + mass-gathering', ['fever', 'stiff_neck', 'headache', 'malaise'],
    ['mass_gathering_hajj_umrah'],
    { vitals: { temperature_c: 38.7, oxygen_saturation: 98 } });
}
for (let i = 0; i < 9; i++) {
  add('Meningitis: AFP rule-out', ['fever', 'stiff_neck', 'paralysis_acute_flaccid'],
    [],
    { vitals: { temperature_c: 38.5, oxygen_saturation: 97 } });
}

// =============================================================================
// ILI / SARI / SARS / influenza-new-subtype (30)
// =============================================================================
for (let i = 0; i < 8; i++) {
  add('ILI: fever + cough', ['fever', 'cough', 'sore_throat'],
    [],
    { vitals: { temperature_c: 38.2, oxygen_saturation: 97 } });
}
for (let i = 0; i < 8; i++) {
  add('SARI: severe respiratory + hospitalisation', ['fever', 'cough', 'shortness_of_breath', 'difficulty_breathing'],
    [],
    { vitals: { temperature_c: 38.5, oxygen_saturation: 90 } });
}
for (let i = 0; i < 7; i++) {
  add('Avian flu exposure', ['fever', 'cough', 'shortness_of_breath'],
    ['poultry_or_live_bird_exposure'],
    { vitals: { temperature_c: 38.6, oxygen_saturation: 92 } });
}
for (let i = 0; i < 7; i++) {
  add('SARS-like + camel exposure', ['fever', 'cough', 'shortness_of_breath'],
    ['camel_exposure_or_mideast_healthcare', 'travel_from_outbreak_area'],
    { visited_countries: visited('SA'), vitals: { temperature_c: 38.8, oxygen_saturation: 91 } });
}

// =============================================================================
// MALARIA / TYPHOID / BRUCELLOSIS / OTHER FEBRILE (25)
// =============================================================================
for (let i = 0; i < 8; i++) {
  add('Malaria uncomplicated', ['fever', 'chills', 'headache'],
    ['mosquito_exposure'],
    { visited_countries: visited('TZ'), vitals: { temperature_c: 38.4, oxygen_saturation: 98 } });
}
for (let i = 0; i < 6; i++) {
  add('Severe malaria + danger signs', ['fever', 'high_fever', 'jaundice', 'dark_urine'],
    ['mosquito_exposure'],
    { visited_countries: visited('TZ'), vitals: { temperature_c: 39.1, oxygen_saturation: 95 } });
}
for (let i = 0; i < 6; i++) {
  add('Typhoid: prolonged fever', ['fever', 'abdominal_pain', 'headache', 'malaise'],
    ['contaminated_food_or_water'],
    { vitals: { temperature_c: 38.5, oxygen_saturation: 98 } });
}
for (let i = 0; i < 5; i++) {
  add('Brucellosis: livestock + arthralgia', ['fever', 'muscle_pain'],
    ['livestock_raw_dairy_abattoir'],
    { vitals: { temperature_c: 38.0, oxygen_saturation: 98 } });
}

// =============================================================================
// ZOONOTIC / ARBOVIRAL / RABIES (15)
// =============================================================================
for (let i = 0; i < 4; i++) {
  add('RVF: livestock + flood', ['fever', 'jaundice', 'bleeding', 'muscle_pain'],
    ['flood_livestock_exposure', 'livestock_raw_dairy_abattoir'],
    { vitals: { temperature_c: 38.9, oxygen_saturation: 96 } });
}
for (let i = 0; i < 4; i++) {
  add('Dengue suspect', ['fever', 'headache', 'muscle_pain', 'rash_maculopapular'],
    ['mosquito_exposure'],
    { visited_countries: visited('LK'), vitals: { temperature_c: 38.6, oxygen_saturation: 97 } });
}
for (let i = 0; i < 4; i++) {
  add('Chikungunya', ['fever', 'muscle_pain'],
    ['mosquito_exposure'],
    { vitals: { temperature_c: 38.8, oxygen_saturation: 98 } });
}
for (let i = 0; i < 3; i++) {
  add('Rabies: hydrophobia + animal bite', ['fever', 'headache'],
    ['dog_bat_animal_bite', 'animal_bite_or_wildlife_contact'],
    { vitals: { temperature_c: 38.0, oxygen_saturation: 98 } });
}

// =============================================================================
// PLAGUE / ANTHRAX (10)
// =============================================================================
for (let i = 0; i < 3; i++) {
  add('Bubonic plague: bubo + flea', ['fever', 'limb_pain'],
    ['flea_or_rodent_exposure'],
    { vitals: { temperature_c: 39.0, oxygen_saturation: 97 } });
}
for (let i = 0; i < 3; i++) {
  add('Pneumonic plague: cough + pleuritic', ['fever', 'cough', 'shortness_of_breath'],
    ['flea_or_rodent_exposure', 'close_contact_case'],
    { vitals: { temperature_c: 39.2, oxygen_saturation: 92 } });
}
for (let i = 0; i < 4; i++) {
  add('Anthrax cutaneous: black eschar', ['fever'],
    ['livestock_raw_dairy_abattoir', 'animal_bite_or_wildlife_contact'],
    { vitals: { temperature_c: 38.0, oxygen_saturation: 98 } });
}

// =============================================================================
// EDGE CASES (the rest, to hit 200)
// =============================================================================
add('Edge: no symptoms, no exposures', [], []);
add('Edge: fever alone', ['fever'], []);
add('Edge: bleeding alone, afebrile', ['bleeding'], []);
add('Edge: cough alone', ['cough'], []);
add('Edge: rash alone', ['rash_maculopapular'], []);
add('Edge: stiff neck alone', ['stiff_neck'], []);
add('Edge: severe dehydration alone', ['severe_dehydration'], []);
add('Edge: paralysis alone', ['paralysis_acute_flaccid'], []);
add('Edge: jaundice alone', ['jaundice'], []);
add('Edge: only exposure (close contact case)', [], ['close_contact_case']);
add('Edge: only exposure (animal bite)', [], ['dog_bat_animal_bite']);
add('Edge: visited DRC, asymptomatic', [], [], { visited_countries: visited('CD') });
add('Edge: vitals only (high temp)', [], [], { vitals: { temperature_c: 40.0, oxygen_saturation: 95 } });
add('Edge: vitals only (low SpO2)', [], [], { vitals: { temperature_c: 36.8, oxygen_saturation: 88 } });
add('Edge: temp + SpO2 critical', ['fever'], [], { vitals: { temperature_c: 39.5, oxygen_saturation: 87 } });

while (v.length < 200) {
  const i = v.length;
  add(`Filler ${i}: fever + headache combo`, ['fever', 'headache'], [],
    { vitals: { temperature_c: 38.0, oxygen_saturation: 97 } });
}

// Trim if we overshot
v.length = 200;

const outPath = new URL('./vignettes.json', import.meta.url);
fs.writeFileSync(outPath, JSON.stringify(v, null, 2));
console.log('Generated', v.length, 'vignettes →', outPath.pathname);
