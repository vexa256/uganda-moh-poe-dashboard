import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('SARS — IDSR Annex 1A Tier-1', () => {
  it('Positive: severe pneumonia + healthcare exposure → SARS in differential', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'cough', 'shortness_of_breath', 'difficulty_breathing'],
      ['healthcare_exposure', 'travel_from_outbreak_area'],
      { temperature_c: 39, oxygen_saturation: 91 }
    );
    expect(['sars', 'sari', 'covid_19', 'severe_pneumonia_under5']).toContain(top);
  });
});
