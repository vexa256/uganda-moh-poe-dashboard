import { describe, it, expect } from 'vitest';
import { topDiseaseFor, fullResult } from './_helpers/loadEngine.mjs';

// Aggressive clinical-differential audit (2026-05-08).
// Each vignette below was hand-checked against IDSR Annex 1A. The expected
// disease (or the set of forbidden diseases) is what a public-health officer
// in Rwanda should see at the top of the differential.

describe('Clinical differential audit — false-positive guards', () => {
  it('fever-only mild traveller — no AHF in top 3', () => {
    const r = fullResult(['fever'], [], { temperature_c: 38.2 });
    const top3 = r.top_diagnoses.slice(0, 3).map(d => d.disease_id);
    for (const ahf of ['ebola_virus_disease','marburg_virus_disease','lassa_fever','cchf','rift_valley_fever','dengue_severe']) {
      expect(top3, `${ahf} must not appear`).not.toContain(ahf);
    }
  });

  it('fever + headache (no other signs) — no AHF in top 3', () => {
    const r = fullResult(['fever','headache'], [], { temperature_c: 38.5 });
    const top3 = r.top_diagnoses.slice(0, 3).map(d => d.disease_id);
    for (const ahf of ['ebola_virus_disease','marburg_virus_disease','lassa_fever','cchf']) {
      expect(top3).not.toContain(ahf);
    }
  });

  it('fever + diarrhoea + no unsafe-water exposure — cholera not in top 3', () => {
    const r = fullResult(['fever','diarrhea'], [], { temperature_c: 38.5 });
    const top3 = r.top_diagnoses.slice(0, 3).map(d => d.disease_id);
    expect(top3).not.toContain('cholera');
  });

  it('plain cough + sore throat — no AHF', () => {
    const top = topDiseaseFor(['cough','sore_throat'], [], {});
    expect(['ebola_virus_disease','sars']).not.toContain(top);
  });

  it('jaundice + fever — yellow fever leads, AHF not in top', () => {
    const top = topDiseaseFor(['fever','jaundice'], [], { temperature_c: 39 });
    expect(top).toBe('yellow_fever');
  });

  it('AFP-only (paralysis_acute_flaccid) — polio is top, NOT rabies', () => {
    const top = topDiseaseFor(['paralysis_acute_flaccid'], [], {});
    expect(top).toBe('polio');
  });

  it('meningitis triad — bacterial meningitis is top', () => {
    const top = topDiseaseFor(['fever','stiff_neck','severe_headache'], [], { temperature_c: 39.5 });
    expect(top).toBe('meningococcal_meningitis');
  });

  it('rabies pathognomonic — hydrophobia + animal bite → rabies', () => {
    const top = topDiseaseFor(
      ['hydrophobia','altered_consciousness'],
      ['animal_bite_or_wildlife_contact'],
      {}
    );
    expect(top).toBe('rabies');
  });

  it('aerophobia removed from selectable symptoms — must not score rabies', () => {
    // aerophobia is no longer a recognised symptom code; if anything sends it
    // (legacy IDB record) the engine must not crash AND must not score rabies
    // on aerophobia alone.
    const r = fullResult(['aerophobia'], [], {});
    const rabies = r.top_diagnoses.find(d => d.disease_id === 'rabies');
    if (rabies) expect(rabies.final_score).toBeLessThan(25);
  });

  it('pure exposure with no symptoms — engine returns no positive top', () => {
    const r = fullResult([], ['contact_body_fluids','travel_from_outbreak_area'], {});
    const top = r.top_diagnoses?.[0]?.disease_id;
    expect(['ebola_virus_disease','marburg_virus_disease']).not.toContain(top);
  });
});

describe('Clinical differential audit — true-positive controls', () => {
  it('Ebola pattern (fever + bleeding + outbreak travel + body-fluid contact) → Ebola top', () => {
    const top = topDiseaseFor(
      ['fever','high_fever','bleeding','vomiting'],
      ['contact_body_fluids','travel_from_outbreak_area'],
      { temperature_c: 39.5 }
    );
    expect(top).toBe('ebola_virus_disease');
  });

  it('Cholera pattern (rice-water diarrhoea + dehydration + unsafe water) → Cholera top', () => {
    const top = topDiseaseFor(
      ['watery_diarrhea','severe_dehydration','vomiting'],
      ['unsafe_water'],
      {}
    );
    expect(top).toBe('cholera');
  });

  it('SARI pattern (fever + cough + dyspnoea) → SARI or pneumonia under-5 in top 2', () => {
    const r = fullResult(['fever','cough','difficulty_breathing','rapid_breathing'], [], { temperature_c: 39 });
    const top2 = r.top_diagnoses.slice(0, 2).map(d => d.disease_id);
    const okHits = ['sari','severe_pneumonia_under5','influenza_new_subtype_zoonotic','sars','covid_19'];
    expect(top2.some(id => okHits.includes(id))).toBe(true);
  });

  it('Vector-borne (fever + maculo rash + arthralgia + mosquito exposure) → Zika or Chikungunya top', () => {
    const top = topDiseaseFor(
      ['fever','rash_maculopapular','joint_pain'],
      ['mosquito_exposure'],
      {}
    );
    expect(['zika','chikungunya','dengue']).toContain(top);
  });
});

describe('57% probability cap holds across the audit set', () => {
  it('every top diagnosis ≤ 57% probability', () => {
    const cases = [
      [['fever'], [], { temperature_c: 38.2 }],
      [['fever','bleeding','vomiting'], ['contact_body_fluids','travel_from_outbreak_area'], { temperature_c: 39 }],
      [['fever','stiff_neck','severe_headache'], [], { temperature_c: 39.5 }],
      [['watery_diarrhea','severe_dehydration'], ['unsafe_water'], {}],
      [['hydrophobia'], ['animal_bite_or_wildlife_contact'], {}],
      [['paralysis_acute_flaccid'], [], {}],
    ];
    for (const [p, e, v] of cases) {
      const r = fullResult(p, e, v);
      for (const d of r.top_diagnoses) {
        expect(d.probability_like_percent, `${d.disease_id}`).toBeLessThanOrEqual(57);
      }
    }
  });
});
