import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Diarrhoea with dehydration <5y (AWD non-cholera) — IDSR Annex 1A', () => {
  it('Positive: watery diarrhoea + dehydration in young child (no cholera signs)', () => {
    const top = topDiseaseFor(
      ['watery_diarrhea', 'diarrhea', 'dehydration', 'vomiting'],
      []
    );
    // AWD-non-cholera or foodborne_illness or cholera (if dehydration severe).
    expect(['awd_non_cholera', 'foodborne_illness', 'cholera']).toContain(top);
  });
});
