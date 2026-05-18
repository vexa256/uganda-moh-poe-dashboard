import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Chikungunya — IDSR Annex 1A epidemic-prone', () => {
  it('Positive: fever + severe joint pain + mosquito exposure', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'severe_joint_pain', 'joint_pain', 'rash_maculopapular'],
      ['mosquito_exposure', 'travel_from_outbreak_area'],
      { temperature_c: 39 }
    );
    expect(['chikungunya', 'dengue']).toContain(top);
  });
});
