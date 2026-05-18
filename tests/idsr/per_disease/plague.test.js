import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Plague (pneumonic + bubonic) — IDSR Annex 1A', () => {
  it('Pneumonic plague: bloody sputum + sudden fever + flea/rodent exposure', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'cough', 'bloody_sputum', 'shortness_of_breath', 'chest_pain'],
      ['flea_or_rodent_exposure']
    );
    expect(['pneumonic_plague', 'bubonic_plague', 'sari']).toContain(top);
  });

  it('Bubonic plague: painful swollen lymph nodes (bubo) + flea/rodent exposure', () => {
    // Catalog-correct symptom names: painful_swollen_lymph_nodes (not _bubo);
    // exposure code flea_or_rodent_exposure (not rodent_or_flea_exposure).
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'painful_swollen_lymph_nodes', 'severe_headache'],
      ['flea_or_rodent_exposure']
    );
    expect(['bubonic_plague', 'pneumonic_plague']).toContain(top);
  });
});
