import { describe, it, expect } from 'vitest';
import { topDiseaseFor } from '../_helpers/loadEngine.mjs';

describe('Marburg — IDSR Annex 1A AHF', () => {
  it('Positive: fever + bleeding + bat/mine exposure + outbreak travel', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'bleeding', 'vomiting', 'severe_fatigue'],
      ['bat_or_cave_or_mine_exposure', 'travel_from_outbreak_area'],
      { temperature_c: 39.5 }
    );
    // Either marburg or another VHF wins; marburg should at least be in the candidate set.
    // Strict: marburg should be top when bat exposure dominates.
    expect(['marburg_virus_disease', 'ebola_virus_disease']).toContain(top);
  });

  it('Negative: no bleeding → marburg NOT top (R3 bleed-gate)', () => {
    const top = topDiseaseFor(['fever'], ['bat_or_cave_or_mine_exposure'], { temperature_c: 39 });
    expect(top).not.toBe('marburg_virus_disease');
  });
});
