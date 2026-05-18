import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Bacterial meningitis — IDSR Annex 1A epidemic-prone (renamed from meningococcal_meningitis)', () => {
  it('Positive: fever + stiff neck + altered consciousness + petechial rash', () => {
    const top = topDiseaseFor(
      ['fever', 'stiff_neck', 'altered_consciousness', 'photophobia'],
      ['close_contact_case', 'crowded_closed_setting']
    );
    expect(top).toBe('meningococcal_meningitis');
  });
});
