import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('SARI (Severe Acute Respiratory Infection) — IDSR Annex 1A', () => {
  it('Positive: fever + cough + shortness of breath (no specific exposure)', () => {
    const top = topDiseaseFor(
      ['fever', 'cough', 'shortness_of_breath', 'difficulty_breathing'],
      [],
      { temperature_c: 38.5, oxygen_saturation: 90 }
    );
    expect(top).toBe('sari');
  });

  it('SARI loses to influenza_new_subtype_zoonotic when poultry exposure is present', () => {
    const top = topDiseaseFor(
      ['fever', 'cough', 'shortness_of_breath'],
      ['poultry_or_live_bird_exposure'],
      { temperature_c: 38.6, oxygen_saturation: 92 }
    );
    expect(top).toBe('influenza_new_subtype_zoonotic');
  });
});
