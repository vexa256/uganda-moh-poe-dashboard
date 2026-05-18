import { describe, it, expect, beforeAll } from 'vitest';
import { getEngine } from './_helpers/loadEngine.mjs';

let D;
beforeAll(() => { D = getEngine(); });

describe('evaluateIDSRThresholds — IDSR Annex 1A threshold parsing', () => {
  it('cholera alert is single-case', () => {
    const t = D.evaluateIDSRThresholds('cholera');
    expect(t).toBeTruthy();
    expect(t.alert?.kind).toBe('single_case');
  });

  it('cholera epidemic is single confirmed case', () => {
    const t = D.evaluateIDSRThresholds('cholera');
    expect(t.epidemic?.kind).toBe('single_case');
  });

  it('typhoid epidemic is aggregate per facility', () => {
    const t = D.evaluateIDSRThresholds('typhoid_fever');
    expect(t.epidemic?.kind).toBe('aggregate_per_facility');
  });

  it('legacy IDs return unknown/empty kinds', () => {
    const t = D.evaluateIDSRThresholds('mpox');
    expect(t).toBeTruthy();
    expect(['unknown', undefined, null]).toContain(t.alert?.kind);
    expect(['unknown', undefined, null]).toContain(t.epidemic?.kind);
  });
});

describe('Active diseases — every active disease has threshold strings', () => {
  it('every active disease has alert_threshold and epidemic_threshold', () => {
    const missing = [];
    for (const d of D.diseases) {
      if (d.category === 'event') continue; // events bypass threshold semantics
      if (!d.alert_threshold) missing.push({ id: d.id, missing: 'alert_threshold' });
      if (!d.epidemic_threshold) missing.push({ id: d.id, missing: 'epidemic_threshold' });
    }
    expect(missing).toEqual([]);
  });
});
