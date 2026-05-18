import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Smallpox — IDSR Annex 1A Tier-1 (eradicated)', () => {
  it('Positive: vesicular pustular rash same-stage + face/palms-soles + close contact', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'rash_vesicular_pustular', 'rash_face_first', 'rash_palms_soles'],
      ['close_contact_case'],
      { temperature_c: 39 }
    );
    expect(top).toBe('smallpox');
  });

  it('Negative: no rash → smallpox NOT top', () => {
    const top = topDiseaseFor(['fever'], ['close_contact_case'], { temperature_c: 39 });
    expect(top).not.toBe('smallpox');
  });
});
