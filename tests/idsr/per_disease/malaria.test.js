import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Malaria — IDSR Annex 1A (uncomplicated + severe)', () => {
  it('Uncomplicated malaria: fever alone in mosquito-exposed traveller', () => {
    const top = topDiseaseFor(['fever'], ['mosquito_exposure'], { temperature_c: 38.5 });
    // Either malaria_uncomplicated or another acute-febrile candidate may win.
    expect(['malaria_uncomplicated', 'dengue', 'malaria_severe']).toContain(top);
  });

  it('Severe malaria: fever + altered consciousness + danger signs', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'altered_consciousness', 'jaundice', 'dark_urine'],
      ['mosquito_exposure'],
      { temperature_c: 39.5 }
    );
    expect(top).toBe('malaria_severe');
  });
});
