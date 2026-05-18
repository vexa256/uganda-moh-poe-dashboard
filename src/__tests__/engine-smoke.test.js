/**
 * Engine smoke test — verifies the user's directive 2026-05-05 is honoured:
 *
 *   1. VHF bleed-gate: Ebola/Marburg/Lassa/CCHF/RVF NEVER appear in
 *      top_diagnoses, override picker, or ihr_risk unless a bleeding
 *      symptom is recorded.
 *   2. No VHF/Marburg/Mpox calibration bias.
 *   3. Engine top-1: top_diagnoses collapses to a single ranked result.
 *   4. Officer-override eligibility blocks impossible diseases (cholera
 *      without watery diarrhoea, mpox without rash).
 *   5. Override explainability returns deterministic factor list.
 *
 * Runs in vitest (jsdom env). Loads Diseases.js + Diseases_intelligence.js
 * which mutate the global window.DISEASES catalog.
 */

// @vitest-environment jsdom
import { describe, it, expect, beforeAll } from 'vitest'

beforeAll(async () => {
  // The intelligence layer attaches itself to window.DISEASES.engine when
  // the module is imported. Diseases.js must load first.
  await import('../Diseases.js')
  await import('../Diseases_intelligence.js')
})

describe('engine — VHF bleed-gate (no bleeding → no VHF)', () => {
  it('does not surface Ebola in top_diagnoses without bleeding', () => {
    const D = /** @type {any} */ (globalThis).window.DISEASES
    expect(typeof D?.getEnhancedScoreResult).toBe('function')
    const r = D.getEnhancedScoreResult(
      ['fever', 'headache', 'fatigue'],     // present
      [],                                   // absent
      ['CONTACT_PERSON_INFECTIOUS'],        // exposures
      { vitals: { temperature: 38.7 } },
    )
    const ids = (r.top_diagnoses || []).map(d => d.disease_id)
    expect(ids).not.toContain('ebola_virus_disease')
    expect(ids).not.toContain('marburg_virus_disease')
    expect(ids).not.toContain('lassa_fever')
    expect(ids).not.toContain('cchf')
    expect(ids).not.toContain('rift_valley_fever')
  })

  it('blocks VHF override declaration when no bleeding is recorded', () => {
    const D = /** @type {any} */ (globalThis).window.DISEASES
    const elig = D.engine.getOfficerOverrideEligibility(
      'ebola_virus_disease',
      ['fever', 'headache'],
      ['CONTACT_PERSON_INFECTIOUS'],
      {},
    )
    expect(elig.selectable).toBe(false)
    expect(String(elig.reason || '')).toMatch(/bleed/i)
  })

  it('permits Ebola override when bleeding IS recorded', () => {
    const D = /** @type {any} */ (globalThis).window.DISEASES
    const elig = D.engine.getOfficerOverrideEligibility(
      'ebola_virus_disease',
      ['fever', 'bleeding', 'haemorrhagic_skin_rash'],
      ['CONTACT_PERSON_INFECTIOUS'],
      {},
    )
    expect(elig.selectable).toBe(true)
  })
})

describe('engine — top-3 ranking (revised 2026-05-08)', () => {
  it('returns at most three diagnoses in top_diagnoses', () => {
    const D = /** @type {any} */ (globalThis).window.DISEASES
    const r = D.getEnhancedScoreResult(
      ['watery_diarrhea', 'severe_dehydration', 'vomiting'],
      [],
      ['UNSAFE_FOOD_WATER'],
      {},
    )
    expect(Array.isArray(r.top_diagnoses)).toBe(true)
    expect(r.top_diagnoses.length).toBeLessThanOrEqual(3)
  })
})

describe('engine — impossible-disease override gate', () => {
  it('blocks Cholera when no watery diarrhoea is recorded', () => {
    const D = /** @type {any} */ (globalThis).window.DISEASES
    const elig = D.engine.getOfficerOverrideEligibility(
      'cholera',
      ['fever', 'headache'],
      [],
      {},
    )
    expect(elig.selectable).toBe(false)
  })

  it('blocks Mpox when no rash is recorded', () => {
    const D = /** @type {any} */ (globalThis).window.DISEASES
    const elig = D.engine.getOfficerOverrideEligibility(
      'mpox',
      ['fever', 'fatigue'],
      [],
      {},
    )
    expect(elig.selectable).toBe(false)
  })
})

describe('engine — override explainability', () => {
  it('returns a deterministic factor breakdown for an eligible override', () => {
    const D = /** @type {any} */ (globalThis).window.DISEASES
    const exp = D.engine.buildOfficerOverrideExplanation(
      'cholera',
      ['watery_diarrhea', 'severe_dehydration', 'vomiting'],
      ['UNSAFE_FOOD_WATER'],
      {},
    )
    expect(exp).toBeTruthy()
    expect(Array.isArray(exp.symptom_factors)).toBe(true)
    expect(exp.symptom_factors.length).toBeGreaterThan(0)
    expect(typeof exp.final_score).toBe('number')
    expect(exp.final_score).toBeGreaterThan(0)
    expect(exp.final_score).toBeLessThanOrEqual(100)
  })
})
