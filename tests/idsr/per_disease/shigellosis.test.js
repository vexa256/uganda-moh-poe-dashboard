import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Shigellosis (bacillary dysentery) — IDSR Annex 1A', () => {
  it('Positive: bloody diarrhoea + abdominal pain', () => {
    const top = topDiseaseFor(
      ['bloody_diarrhea', 'diarrhea', 'abdominal_pain', 'fever'],
      ['contaminated_food_or_water']
    );
    expect(top).toBe('shigellosis_dysentery');
  });

  it('Vignette-generator alias: bleeding (rectal) + diarrhoea is recognised as dysentery', () => {
    const top = topDiseaseFor(
      ['diarrhea', 'bleeding', 'abdominal_pain'],
      ['contaminated_food_or_water']
    );
    expect(top).toBe('shigellosis_dysentery');
  });
});
