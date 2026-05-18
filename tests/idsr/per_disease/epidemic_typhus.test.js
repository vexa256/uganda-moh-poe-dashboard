import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Epidemic typhus — IDSR Annex 1A epidemic-prone', () => {
  it('Positive: fever + severe headache + severe fatigue + crowded setting (refugee camp)', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'severe_headache', 'severe_fatigue', 'chills'],
      ['crowded_closed_setting']
    );
    expect(top).toBe('epidemic_typhus');
  });
});
