import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Yellow fever — IDSR Annex 1A PHEIC', () => {
  it('Positive: fever + jaundice + dark urine + mosquito exposure', () => {
    const top = topDiseaseFor(
      ['fever', 'jaundice', 'dark_urine', 'vomiting'],
      ['mosquito_exposure'],
      { temperature_c: 38.8 }
    );
    expect(top).toBe('yellow_fever');
  });

  it('Negative: respiratory pattern (no fever, no jaundice) → NOT yellow_fever', () => {
    const top = topDiseaseFor(['cough', 'sore_throat'], [], {});
    expect(top).not.toBe('yellow_fever');
  });
});
