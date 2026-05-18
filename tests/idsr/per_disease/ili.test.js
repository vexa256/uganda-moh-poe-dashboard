import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Influenza-like illness (ILI) — IDSR Annex 1A', () => {
  it('Positive: sudden fever + cough + sore throat (no severe respiratory signs)', () => {
    const top = topDiseaseFor(
      ['sudden_onset_fever', 'fever', 'cough', 'sore_throat', 'muscle_pain'],
      []
    );
    expect(['influenza_seasonal', 'covid_19']).toContain(top);
  });
});
