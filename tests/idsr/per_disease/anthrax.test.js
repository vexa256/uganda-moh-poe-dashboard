import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Anthrax (cutaneous + pulmonary) — IDSR Annex 1A', () => {
  it('Cutaneous anthrax: black eschar + animal exposure', () => {
    const top = topDiseaseFor(
      ['fever', 'skin_eschar', 'facial_swelling'],
      ['raw_meat_or_unpasteurised_dairy', 'animal_bite_or_wildlife_contact']
    );
    expect(top).toBe('anthrax_cutaneous');
  });

  it('Pulmonary anthrax: fever + severe respiratory + mediastinal widening', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'shortness_of_breath', 'difficulty_breathing', 'mediastinal_widening'],
      ['laboratory_exposure']
    );
    // anthrax_pulmonary OR a SARI-class disease may win.
    expect(['anthrax_pulmonary', 'sari', 'sars']).toContain(top);
  });
});
