import { describe, it, expect } from 'vitest';
import { topDiseaseFor, getEngine } from '../_helpers/loadEngine.mjs';

describe('Cholera — IDSR Annex 1A epidemic-prone', () => {
  it('Positive: profuse watery diarrhoea + dehydration + unsafe water', () => {
    const top = topDiseaseFor(
      ['watery_diarrhea', 'rice_water_stool', 'severe_dehydration', 'vomiting'],
      ['unsafe_water', 'contaminated_food_or_water'],
      { temperature_c: 36.8 }
    );
    expect(top).toBe('cholera');
  });

  it('Cholera threshold is single-case (alert + epidemic)', () => {
    const D = getEngine();
    const t = D.evaluateIDSRThresholds('cholera');
    expect(t.alert.kind).toBe('single_case');
    expect(t.epidemic.kind).toBe('single_case');
  });

  it('Negative: bloody stool (dysentery) → NOT cholera', () => {
    const top = topDiseaseFor(['diarrhea', 'bloody_diarrhea'], ['contaminated_food_or_water']);
    expect(top).not.toBe('cholera');
  });
});
