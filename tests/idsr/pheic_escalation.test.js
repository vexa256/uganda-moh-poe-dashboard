import { describe, it, expect, beforeAll } from 'vitest';
import { getEngine, fullResult } from './_helpers/loadEngine.mjs';

let D;
beforeAll(() => { D = getEngine(); });

describe('PHEIC escalation — IDSR PHEIC IDs surface in IHR rules', () => {
  it('PHEIC list is derived from idsr_category=pheic among non-event diseases', () => {
    const pheicDiseases = (D.diseases || [])
      .filter(d => d.idsr_category === 'pheic' && d.category !== 'event')
      .map(d => d.id);
    const exported = D.IDSR_PHEIC_DISEASES || [];
    for (const id of pheicDiseases) {
      expect(exported.includes(id), `${id} (idsr_category=pheic) should be in IDSR_PHEIC_DISEASES`).toBe(true);
    }
    expect(exported.length).toBe(pheicDiseases.length);
  });

  it('AHF list contains exactly the 4 IDSR Annex-1A AHF members', () => {
    expect(new Set(D.IDSR_AHF_DISEASES)).toEqual(new Set([
      'ebola_virus_disease', 'marburg_virus_disease', 'lassa_fever', 'cchf',
    ]));
  });

  it('ihr_escalation_rules is an array with at least 5 rules', () => {
    expect(Array.isArray(D.ihr_escalation_rules)).toBe(true);
    expect(D.ihr_escalation_rules.length).toBeGreaterThanOrEqual(5);
  });

  it('Ebola pattern with bleeding + body-fluid exposure raises CRITICAL or HIGH', () => {
    const r = fullResult(['fever', 'bleeding', 'vomiting'], ['contact_body_fluids', 'travel_from_outbreak_area'], { temperature_c: 39 });
    expect(['HIGH', 'CRITICAL']).toContain(r.ihr_risk.risk_level);
  });

  it('Common ILI without exposure does NOT escalate to CRITICAL', () => {
    // Regression check: tightened IDSR_PHEIC_TOP3 rule from Phase 3 must hold.
    const r = fullResult(['fever', 'cough', 'sore_throat'], [], { temperature_c: 38 });
    expect(r.ihr_risk.risk_level).not.toBe('CRITICAL');
  });
});
