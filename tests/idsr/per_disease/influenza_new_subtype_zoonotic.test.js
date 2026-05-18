import { describe, it, expect } from 'vitest';
import { topDiseaseFor, fullResult } from '../_helpers/loadEngine.mjs';

describe('Zoonotic / novel influenza (H5N1, H7N9) — IDSR Annex 1A Tier-1 PHEIC', () => {
  it('Positive: respiratory infection + poultry exposure → INSZ top', () => {
    const top = topDiseaseFor(
      ['fever', 'cough', 'shortness_of_breath'],
      ['poultry_or_live_bird_exposure'],
      { temperature_c: 38.6, oxygen_saturation: 92 }
    );
    expect(top).toBe('influenza_new_subtype_zoonotic');
  });

  it('Negative: respiratory infection WITHOUT poultry exposure → NOT INSZ', () => {
    const top = topDiseaseFor(
      ['fever', 'cough', 'shortness_of_breath'],
      [],
      { temperature_c: 38.6 }
    );
    expect(top).not.toBe('influenza_new_subtype_zoonotic');
  });

  it('Risk level escalates when INSZ tops with poultry exposure', () => {
    const r = fullResult(
      ['fever', 'high_fever', 'cough', 'shortness_of_breath'],
      ['poultry_or_live_bird_exposure'],
      { temperature_c: 39, oxygen_saturation: 91 }
    );
    expect(['HIGH', 'CRITICAL']).toContain(r.ihr_risk.risk_level);
  });
});
