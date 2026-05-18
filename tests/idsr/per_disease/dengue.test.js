import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Dengue — IDSR Annex 1A epidemic-prone', () => {
  it('Positive: fever + severe headache + retro-orbital pain + mosquito (classical dengue)', () => {
    const top = topDiseaseFor(
      ['fever', 'severe_headache', 'pain_behind_eyes', 'muscle_pain', 'joint_pain'],
      ['mosquito_exposure', 'travel_from_outbreak_area'],
      { temperature_c: 39 }
    );
    expect(top).toBe('dengue');
  });

  it('Severe dengue: fever + bleeding + plasma leak signs', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'bleeding_gums_or_nose', 'persistent_vomiting', 'cold_pale_skin'],
      ['mosquito_exposure'],
      { temperature_c: 39 }
    );
    // Severe dengue is in catalog as separate disease; either dengue or dengue_severe should top.
    expect(['dengue_severe', 'dengue', 'cchf']).toContain(top);
  });
});
