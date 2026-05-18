import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Brucellosis — IDSR Annex 1A', () => {
  it('Positive: undulant fever + livestock/dairy exposure', () => {
    const top = topDiseaseFor(
      ['fever', 'undulant_fever', 'night_sweats', 'joint_pain'],
      ['raw_meat_or_unpasteurised_dairy']
    );
    expect(top).toBe('brucellosis');
  });

  it('Vignette-generator alias: livestock_raw_dairy_abattoir is recognised', () => {
    const top = topDiseaseFor(
      ['fever', 'muscle_pain'],
      ['livestock_raw_dairy_abattoir']
    );
    expect(top).toBe('brucellosis');
  });
});
