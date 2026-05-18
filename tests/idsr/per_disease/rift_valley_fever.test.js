import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Rift Valley fever — IDSR Annex 1A', () => {
  it('Positive: fever + bleeding + livestock exposure → RVF candidate', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'bleeding', 'jaundice', 'muscle_pain'],
      ['raw_meat_or_unpasteurised_dairy', 'mosquito_exposure'],
      { temperature_c: 39 }
    );
    // RVF or another zoonotic febrile-haemorrhagic candidate may win.
    expect(['rift_valley_fever', 'yellow_fever', 'cchf']).toContain(top);
  });
});
