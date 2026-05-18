import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Rubella — IDSR Annex 1A eradication/elimination', () => {
  it('Positive: fever + maculopapular rash → rubella top', () => {
    const top = topDiseaseFor(['fever', 'rash_maculopapular'], [], { temperature_c: 38 });
    expect(top).toBe('rubella');
  });

  it('Positive: rash + retroauricular lymph nodes (pathognomonic)', () => {
    const top = topDiseaseFor(['rash_maculopapular', 'retroauricular_lymph_nodes', 'low_grade_fever']);
    expect(top).toBe('rubella');
  });
});
