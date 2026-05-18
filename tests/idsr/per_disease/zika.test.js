import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Zika — IDSR Annex 1A PHEIC', () => {
  it('Positive: rash + conjunctivitis + mild fever + mosquito exposure', () => {
    const top = topDiseaseFor(
      ['rash_maculopapular', 'conjunctivitis', 'low_grade_fever', 'joint_pain'],
      ['mosquito_exposure', 'travel_from_outbreak_area']
    );
    expect(top).toBe('zika');
  });
});
