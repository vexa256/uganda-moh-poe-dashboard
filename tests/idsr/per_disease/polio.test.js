import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Polio / AFP — IDSR Annex 1A Tier-1', () => {
  it('Positive: acute flaccid paralysis in child', () => {
    const top = topDiseaseFor(
      ['paralysis_acute_flaccid', 'fever', 'weakness'],
      ['unvaccinated_or_unknown_vaccination']
    );
    expect(top).toBe('polio');
  });

  it('Negative: no paralysis → polio gate fails (hard_fail)', () => {
    const top = topDiseaseFor(['fever'], []);
    expect(top).not.toBe('polio');
  });
});
