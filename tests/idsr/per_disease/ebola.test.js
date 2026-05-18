import { describe, it, expect } from 'vitest';
import { topDiseaseFor, fullResult } from '../_helpers/loadEngine.mjs';

describe('Ebola — IDSR Annex 1A Acute Haemorrhagic Fever', () => {
  it('Positive: fever + bleeding + body-fluid contact + outbreak travel → ebola top', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'bleeding', 'vomiting', 'severe_fatigue'],
      ['contact_body_fluids', 'travel_from_outbreak_area'],
      { temperature_c: 39.5 }
    );
    expect(top).toBe('ebola_virus_disease');
  });

  it('Negative: fever + body-fluid contact WITHOUT bleeding → ebola NOT top (R3 bleed-gate)', () => {
    const top = topDiseaseFor(
      ['fever', 'high_fever', 'fatigue'],
      ['contact_body_fluids', 'travel_from_outbreak_area'],
      { temperature_c: 39 }
    );
    expect(top).not.toBe('ebola_virus_disease');
  });

  it('Negative: fever alone (no bleeding, no exposure) → ebola NOT top', () => {
    const top = topDiseaseFor(['fever'], [], { temperature_c: 38.5 });
    expect(top).not.toBe('ebola_virus_disease');
  });

  it('CRITICAL escalation when ebola pattern complete', () => {
    const r = fullResult(
      ['fever', 'bleeding', 'vomiting', 'severe_fatigue'],
      ['contact_body_fluids', 'travel_from_outbreak_area'],
      { temperature_c: 39.5 }
    );
    expect(['HIGH', 'CRITICAL']).toContain(r.ihr_risk.risk_level);
  });
});
