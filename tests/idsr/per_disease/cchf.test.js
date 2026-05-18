import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Crimean-Congo HF — IDSR Annex 1A AHF', () => {
  it('Positive: fever + bleeding + tick exposure → CCHF or another VHF', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'bleeding', 'muscle_pain', 'severe_fatigue'],
      ['tick_bite_or_livestock_blood', 'travel_from_outbreak_area'],
      { temperature_c: 39 }
    );
    expect(['cchf', 'ebola_virus_disease', 'marburg_virus_disease', 'lassa_fever']).toContain(top);
  });

  it('Negative: tick exposure without bleeding → CCHF NOT top', () => {
    const top = topDiseaseFor(['fever'], ['tick_bite_or_livestock_blood'], { temperature_c: 38.5 });
    expect(top).not.toBe('cchf');
  });
});
