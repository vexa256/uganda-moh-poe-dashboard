import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Rabies — IDSR Annex 1A always-fatal', () => {
  it('Positive: hydrophobia + agitation + animal bite', () => {
    const top = topDiseaseFor(
      ['hydrophobia', 'agitation', 'fever', 'altered_consciousness'],
      ['animal_bite_or_wildlife_contact']
    );
    expect(top).toBe('rabies');
  });

  it('No bite + no neurological signs → rabies NOT top', () => {
    // Without animal_bite exposure AND without hydrophobia/agitation,
    // rabies should not surface above generic febrile candidates.
    const top = topDiseaseFor(['fever', 'cough'], []);
    expect(top).not.toBe('rabies');
  });
});
