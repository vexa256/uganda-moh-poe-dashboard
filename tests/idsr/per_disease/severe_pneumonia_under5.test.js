import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Severe pneumonia (<5y) — IDSR Annex 1A other major', () => {
  it('Positive: child with fever + cough + rapid breathing + chest indrawing', () => {
    const top = topDiseaseFor(
      ['fever', 'cough', 'rapid_breathing', 'chest_indrawing'],
      [],
      { temperature_c: 38.5, oxygen_saturation: 91 }
    );
    expect(['severe_pneumonia_under5', 'sari']).toContain(top);
  });
});
