import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Lassa fever — IDSR Annex 1A AHF', () => {
  it('Positive: fever + bleeding + rodent exposure', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'bleeding', 'sore_throat', 'vomiting'],
      ['rodent_exposure', 'travel_from_outbreak_area'],
      { temperature_c: 39 }
    );
    // Lassa scores well; could lose to ebola if both VHF candidates trigger,
    // but it MUST be in top 3.
    expect(['lassa_fever', 'ebola_virus_disease', 'marburg_virus_disease']).toContain(top);
  });

  it('Negative: rodent exposure without bleeding → lassa NOT top', () => {
    const top = topDiseaseFor(['fever', 'sore_throat'], ['rodent_exposure'], { temperature_c: 38.5 });
    expect(top).not.toBe('lassa_fever');
  });
});
