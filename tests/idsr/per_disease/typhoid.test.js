import { describe, it, expect } from 'vitest';
import { topDiseaseFor, getEngine } from '../_helpers/loadEngine.mjs';

describe('Typhoid fever — IDSR Annex 1A', () => {
  it('Positive: prolonged fever + abdominal pain + relative bradycardia', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'abdominal_pain', 'paradoxical_bradycardia', 'rose_spots'],
      ['contaminated_food_or_water'],
      { temperature_c: 39 }
    );
    expect(top).toBe('typhoid_fever');
  });

  it('Typhoid epidemic threshold is aggregate per facility', () => {
    const D = getEngine();
    const t = D.evaluateIDSRThresholds('typhoid_fever');
    expect(t.epidemic.kind).toBe('aggregate_per_facility');
  });
});
