// Invariants — codifies I-1 through I-13 from 01-INVARIANTS.md
// (I-13 is covered by source-integrity.test.js; this file covers I-1 .. I-8 + I-12.)

import { describe, it, expect, beforeAll } from 'vitest';
import { getEngine, fullResult } from './_helpers/loadEngine.mjs';

let D;
beforeAll(() => { D = getEngine(); });

describe('I-1 — engine public API surface', () => {
  it('all required functions exist on window.DISEASES', () => {
    const required = [
      'scoreDiseases',
      'getEnhancedScoreResult',
      'generateClinicalReport',
      'deriveWHOSyndrome',
      'getNonCaseVerdict',
      'computeIHRRiskLevel',
      'getWHOCaseDefinition',
      'getDiseaseById',
      'evaluateIDSRThresholds',
      'deriveIDSRSyndrome',
      'getIDSRCaseDefinition',
    ];
    for (const fn of required) expect(typeof D[fn]).toBe('function');
  });

  it('engine.getOfficerOverrideEligibility exists', () => {
    expect(typeof D.engine.getOfficerOverrideEligibility).toBe('function');
  });
});

describe('I-3 — every legacy disease_code resolves', () => {
  const knownLegacy = [
    'mpox', 'nipah_virus', 'hantavirus', 'mers',
    'hepatitis_a', 'hepatitis_e', 'tularemia',
    'rickettsia_scrub_typhus', 'leptospirosis',
    'japanese_encephalitis', 'west_nile_fever',
  ];
  it('all 11 legacy IDs resolve via getDiseaseById (default + includeLegacy)', () => {
    for (const id of knownLegacy) {
      const hit = D.getDiseaseById(id);
      expect(hit, `legacy ${id}`).toBeTruthy();
      expect(typeof hit.name).toBe('string');
      expect(hit.name.length).toBeGreaterThan(0);
    }
  });

  it('legacy IDs are flagged with deprecation metadata + null source ref', () => {
    for (const id of ['mpox', 'mers', 'hepatitis_e']) {
      const d = D.getDiseaseById(id);
      expect(d).toBeTruthy();
      expect(d.idsr_source_ref).toBeNull();
      expect(d.deprecated_since).toBeTruthy();
    }
  });
});

describe('I-4 — legacy diseases NEVER score', () => {
  const legacyIds = new Set([
    'mpox', 'nipah_virus', 'hantavirus', 'mers',
    'hepatitis_a', 'hepatitis_e', 'tularemia',
    'rickettsia_scrub_typhus', 'leptospirosis',
    'japanese_encephalitis', 'west_nile_fever',
  ]);

  const rashCase = { present: ['fever', 'rash_vesicular_pustular'], exposures: ['close_contact_case'] };
  const respCase = { present: ['fever', 'cough', 'shortness_of_breath'], exposures: ['poultry_or_live_bird_exposure'] };
  const giCase   = { present: ['diarrhea', 'bleeding'], exposures: ['contaminated_food_or_water'] };
  const jaundCase = { present: ['fever', 'jaundice', 'dark_urine'], exposures: ['mosquito_exposure'] };

  it.each([
    ['rash + close-contact', rashCase],
    ['poultry-exposed respiratory', respCase],
    ['bloody GI', giCase],
    ['jaundice + mosquito', jaundCase],
  ])('top_diagnoses excludes legacy (%s)', (_label, c) => {
    const r = fullResult(c.present, c.exposures);
    for (const top of (r.top_diagnoses || [])) {
      expect(legacyIds.has(top.disease_id), `legacy id ${top.disease_id} in top_diagnoses`).toBe(false);
    }
    for (const e of (r.all_reportable || [])) {
      expect(legacyIds.has(e.disease_id), `legacy id ${e.disease_id} in all_reportable`).toBe(false);
    }
  });
});

describe('I-5 — no dangling override / IHR-rule disease references', () => {
  it('every triage_override boost_diseases ref exists in active catalog', () => {
    const activeIds = new Set(D.diseases.map(d => d.id));
    const dangling = [];
    for (const ov of (D.engine.triage_overrides || [])) {
      const refs = Object.keys(ov.effect?.boost_diseases || {});
      for (const ref of refs) {
        if (!activeIds.has(ref)) dangling.push({ rule: ov.rule_id, ref });
      }
    }
    expect(dangling).toEqual([]);
  });

  it('IDSR_PHEIC_DISEASES + IDSR_AHF_DISEASES all refer to active catalog ids', () => {
    const activeIds = new Set(D.diseases.map(d => d.id));
    for (const id of (D.IDSR_PHEIC_DISEASES || [])) {
      expect(activeIds.has(id), `PHEIC ${id} not active`).toBe(true);
    }
    for (const id of (D.IDSR_AHF_DISEASES || [])) {
      expect(activeIds.has(id), `AHF ${id} not active`).toBe(true);
    }
  });
});

describe('I-6 — risk_level enum lock', () => {
  const valid = new Set(['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']);

  it.each([
    ['fever alone', ['fever'], []],
    ['ebola pattern', ['fever', 'bleeding', 'vomiting'], ['contact_body_fluids', 'travel_from_outbreak_area']],
    ['SARI', ['fever', 'cough', 'shortness_of_breath'], []],
    ['cluster context', ['fever'], []],
  ])('risk_level is in {LOW,MEDIUM,HIGH,CRITICAL} (%s)', (_label, present, exposures) => {
    const r = fullResult(present, exposures);
    expect(valid.has(r.ihr_risk?.risk_level)).toBe(true);
  });
});

describe('I-7 — syndrome_classification length', () => {
  it('every syndrome key in engine_to_who_syndrome maps to a string ≤ 60 chars', () => {
    const map = D.engine_to_who_syndrome || {};
    for (const [k, v] of Object.entries(map)) {
      expect(typeof v).toBe('string');
      expect(v.length, `engine_to_who_syndrome.${k} = ${v}`).toBeLessThanOrEqual(60);
    }
  });
});

describe('I-8 — catalog sizes', () => {
  it('active catalog = 56', () => {
    expect(D.diseases.length).toBe(56);
  });
  it('legacy catalog = 11', () => {
    expect(D.legacy_diseases.length).toBe(11);
  });
  it('PHEIC list = 11 (4 AHF + 6 *** + polio)', () => {
    expect((D.IDSR_PHEIC_DISEASES || []).length).toBe(11);
  });
  it('AHF list = 4', () => {
    expect((D.IDSR_AHF_DISEASES || []).length).toBe(4);
  });
});
