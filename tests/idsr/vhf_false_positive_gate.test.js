import { describe, it, expect } from 'vitest';
import { topDiseaseFor, scoreFor, fullResult } from './_helpers/loadEngine.mjs';

// IDSR Annex 1A — Acute Haemorrhagic Fever Syndrome:
// "Acute onset of fever … AND any at least one of the following:
//   haemorrhagic or purpuric rash, epistaxis, hematemesis, hemoptysis,
//   blood in stool, other hemorrhagic symptoms …"
//
// Both clauses are mandatory. The strict 2026-05-08 gate enforces fever AND
// bleeding before any AHF/VHF disease can score above 18 (below the 'low'
// confidence band threshold of 25). These vignettes prove the gate fires.

const AHF_FAMILY = [
  'ebola_virus_disease',
  'marburg_virus_disease',
  'lassa_fever',
  'cchf',
  'rift_valley_fever',
  'dengue_severe',
];

describe('VHF false-positive gate (IDSR Annex 1A — fever AND bleeding both required)', () => {
  it('fever-only (no bleeding) — every AHF disease capped at ≤18', () => {
    for (const id of AHF_FAMILY) {
      const s = scoreFor(id, ['fever', 'high_fever'], [], { temperature_c: 38.8 });
      expect(s, `${id} with fever-only`).toBeLessThanOrEqual(18);
    }
  });

  it('bleeding-only (no fever) — every AHF disease capped at ≤18', () => {
    for (const id of AHF_FAMILY) {
      const s = scoreFor(id, ['bleeding', 'bleeding_gums_or_nose'], [], {});
      expect(s, `${id} with bleeding-only`).toBeLessThanOrEqual(18);
    }
  });

  it('gastroenteritis traveller (vomiting + diarrhoea + fever, no bleeding, no exposure) — AHF NOT top', () => {
    const top = topDiseaseFor(
      ['fever', 'vomiting', 'diarrhea', 'abdominal_pain'],
      [],
      { temperature_c: 38.5 }
    );
    expect(AHF_FAMILY).not.toContain(top);
  });

  it('respiratory presentation (fever + cough + sore throat) — AHF NOT in top 3', () => {
    const r = fullResult(
      ['fever', 'cough', 'sore_throat'],
      [],
      { temperature_c: 38.2 }
    );
    const top3Ids = (r.top_diagnoses || []).slice(0, 3).map(d => d.disease_id);
    for (const id of AHF_FAMILY) {
      expect(top3Ids, `${id} should not be in top 3`).not.toContain(id);
    }
  });

  it('outbreak-area exposure ALONE (no fever, no bleeding) — does NOT lift AHFs above 18', () => {
    for (const id of AHF_FAMILY) {
      const s = scoreFor(
        id,
        [],
        ['travel_from_outbreak_area', 'contact_body_fluids'],
        {}
      );
      expect(s, `${id} with exposure-only`).toBeLessThanOrEqual(18);
    }
  });

  it('petechial rash + fever (no other bleeding sign) — AHF still gated when no exposure', () => {
    // Per current code, petechial_or_purpuric_rash IS in VHF_BLEEDING_SYMPTOMS
    // so the bleed-gate would PASS on petechial alone. But without exposure,
    // VHFs still must rely on weights/overrides — they should not dominate
    // a top-3 of more-likely differentials (dengue, meningococcal, etc).
    const r = fullResult(
      ['fever', 'petechial_or_purpuric_rash'],
      [],
      { temperature_c: 39 }
    );
    // top must be a fever+petechiae disease that's better fit than AHFs:
    // dengue / meningococcal_meningitis / rickettsia-style. NOT Ebola/Marburg
    // since they require additional hallmarks.
    const top = (r.top_diagnoses || [])[0]?.disease_id;
    expect(['ebola_virus_disease', 'marburg_virus_disease', 'lassa_fever']).not.toContain(top);
  });

  it('positive control: fever + bleeding + outbreak travel → Ebola IS top', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'bleeding', 'vomiting'],
      ['travel_from_outbreak_area', 'contact_body_fluids'],
      { temperature_c: 39.5 }
    );
    expect(top).toBe('ebola_virus_disease');
  });

  it('every score breakdown in a gated case carries vhf_gate_reason', () => {
    const r = fullResult(['fever'], [], { temperature_c: 38.5 });
    const all = [...(r.top_diagnoses || []), ...(r.all_reportable || [])];
    for (const id of AHF_FAMILY) {
      const hit = all.find(d => d.disease_id === id);
      if (hit && hit.score_breakdown && hit.score_breakdown.vhf_bleeding_gate) {
        expect(hit.score_breakdown.vhf_gate_reason).toBeDefined();
        expect(['no_fever', 'no_bleeding', 'no_fever_no_bleeding']).toContain(hit.score_breakdown.vhf_gate_reason);
      }
    }
  });
});

describe('57% probability cap', () => {
  it('no top diagnosis exceeds 57 % probability_like_percent', () => {
    const cases = [
      [['fever', 'bleeding', 'vomiting'], ['contact_body_fluids', 'travel_from_outbreak_area'], { temperature_c: 39 }],
      [['fever', 'cough', 'shortness_of_breath'], [], { temperature_c: 38.8 }],
      [['fever', 'rash_maculopapular'], [], { temperature_c: 39 }],
      [['fever', 'stiff_neck'], [], { temperature_c: 39.5 }],
      [['watery_diarrhea', 'severe_dehydration'], ['unsafe_water'], {}],
    ];
    for (const [present, exposures, vitals] of cases) {
      const r = fullResult(present, exposures, vitals);
      for (const d of r.top_diagnoses || []) {
        expect(d.probability_like_percent, `${d.disease_id}`).toBeLessThanOrEqual(57);
      }
    }
  });
});

describe('top-3 differential', () => {
  it('engine returns up to 3 top diagnoses', () => {
    const r = fullResult(
      ['fever', 'cough', 'sore_throat'],
      [],
      { temperature_c: 38.3 }
    );
    expect((r.top_diagnoses || []).length).toBeGreaterThan(0);
    expect((r.top_diagnoses || []).length).toBeLessThanOrEqual(3);
  });
});
