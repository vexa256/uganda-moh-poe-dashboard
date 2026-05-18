import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Foodborne illness — IDSR Annex 1A other major', () => {
  it('Positive: vomiting + watery diarrhoea + contaminated food source', () => {
    const top = topDiseaseFor(
      ['vomiting', 'persistent_vomiting', 'diarrhea', 'watery_diarrhea', 'nausea'],
      ['contaminated_food_or_water']
    );
    expect(['foodborne_illness', 'cholera', 'awd_non_cholera']).toContain(top);
  });

  it('Foodborne loses to shigellosis when blood is present', () => {
    const top = topDiseaseFor(
      ['diarrhea', 'bloody_diarrhea', 'abdominal_pain'],
      ['contaminated_food_or_water']
    );
    expect(top).toBe('shigellosis_dysentery');
  });
});
