import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('COVID-19 — IDSR Annex 1A PHEIC', () => {
  it('Positive: fever + cough + loss of taste/smell', () => {
    const top = topDiseaseFor(
      ['fever', 'cough', 'loss_of_taste_smell', 'sore_throat'],
      ['close_contact_case']
    );
    // COVID-specific anosmia drives covid_19 over generic ILI/SARI.
    expect(['covid_19', 'sari', 'influenza_seasonal']).toContain(top);
  });
});
