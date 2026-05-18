import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Measles — IDSR Annex 1A eradication/elimination', () => {
  it('Positive: fever + maculopapular rash + cough + coryza + conjunctivitis', () => {
    const top = topDiseaseFor(
      ['fever', 'rash_maculopapular', 'cough', 'coryza', 'conjunctivitis'],
      ['unvaccinated_or_unknown_vaccination'],
      { temperature_c: 38.5 }
    );
    expect(top).toBe('measles');
  });

  it('Negative: no rash → NOT measles', () => {
    const top = topDiseaseFor(['fever', 'cough'], []);
    expect(top).not.toBe('measles');
  });
});
