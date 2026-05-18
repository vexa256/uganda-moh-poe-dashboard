/**
 * Hostile test suite for the WHO-POE secondary-screening calibration layer (v3.1.0).
 *
 * Loads Diseases.js → Diseases_intelligence.js → exposures.js in jsdom (the
 * same order the Ionic app uses) and exercises the production entry point
 * window.DISEASES.getEnhancedScoreResult(). These tests try to break the
 * scorer — specifically to prove that Marburg / Ebola / Mpox are no longer
 * top-ranked on weak or absent evidence while a true positive still alerts.
 *
 * If a test fails, fix the calibration in Diseases_intelligence.js — not the
 * test.
 */
// @ts-nocheck
import { describe, test, beforeAll, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)
const SRC = resolve(__dirname, '../../src')

function loadScript(path: string) {
  const code = readFileSync(path, 'utf8')
  // eslint-disable-next-line no-new-func
  new Function(code).call(globalThis)
}

beforeAll(() => {
  // jsdom provides window; the engine assigns to window.DISEASES etc.
  loadScript(resolve(SRC, 'Diseases.js'))
  loadScript(resolve(SRC, 'Diseases_intelligence.js'))
  loadScript(resolve(SRC, 'exposures.js'))
})

const VHF_CORE = [
  'ebola_virus_disease',
  'marburg_virus_disease',
  'lassa_fever',
  'cchf',
  'rift_valley_fever',
  'nipah_virus',
  'hantavirus',
]

const TIER1_CONSEQUENCE = [
  ...VHF_CORE,
  'smallpox',
  'sars',
  'influenza_new_subtype_zoonotic',
  'polio',
  'mpox',
  'mers',
]

function getEnhanced(
  present: string[],
  absent: string[] = [],
  exposures: string[] = [],
  visited: Array<{ country_code: string }> = [],
  vitals: Record<string, any> = {},
  context: Record<string, any> = {}
) {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const w = globalThis as any
  return w.DISEASES.getEnhancedScoreResult(present, absent, exposures, visited, vitals, context)
}

function topId(r: any) {
  return r?.top_diagnoses?.[0]?.disease_id ?? null
}

function topScore(r: any) {
  return r?.top_diagnoses?.[0]?.final_score ?? 0
}

describe('engine is loaded and at v3.1.0', () => {
  test('version advertises calibration v3.1', () => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const w = globalThis as any
    expect(w.DISEASES).toBeDefined()
    // Two-layer versioning by design:
    //   metadata.version              — base disease-catalog (Diseases.js)
    //   engine.formula.calibration    — patch by Diseases_intelligence.js
    // Catalog is at 3.1.0; the intelligence calibration patch is at 3.2.0.
    // Tests pin the contract — bump deliberately if either layer rolls.
    expect(w.DISEASES.metadata.version).toBe('3.1.0')
    expect(w.DISEASES.engine.formula.calibration.version).toBe('3.2.0')
  })
})

describe('A. empty symptom set', () => {
  test('no symptoms, no exposures, no travel → non-case, no Tier-1 alert', () => {
    const r = getEnhanced([], [], [], [], {})
    expect(r.is_non_case).toBe(true)
    expect(r.ihr_risk.risk_level).toBe('LOW')
    expect(r.ihr_notification_required).toBe(false)
    expect(r.top_disease_id).toBeNull()
    // No VHF or Tier-1 should be top
    expect(TIER1_CONSEQUENCE).not.toContain(topId(r))
  })

  test('no symptoms + visited Marburg-endemic country → still non-case', () => {
    // Zambia (ZM) is endemic for Marburg in the oracle — this is the known
    // false-positive vector. Non-case must hold.
    const r = getEnhanced([], [], [], [{ country_code: 'ZM' }], {})
    expect(r.is_non_case).toBe(true)
    expect(TIER1_CONSEQUENCE).not.toContain(topId(r))
    expect(r.ihr_risk.risk_level).toBe('LOW')
  })
})

describe('B. one mild common symptom', () => {
  test('headache alone → no Tier-1 VHF top-ranked', () => {
    const r = getEnhanced(['headache'], [], [], [], {})
    expect(VHF_CORE).not.toContain(topId(r))
    // Either non-case or the top candidate should NOT be in the very_high band.
    if (!r.is_non_case) {
      expect(topScore(r)).toBeLessThan(55)
    }
  })

  test('low-grade fever alone from Zambia → no VHF top, no critical alert', () => {
    const r = getEnhanced(['fever'], [], [], [{ country_code: 'ZM' }],
      { temperature_c: 37.8 })
    expect(VHF_CORE).not.toContain(topId(r))
    expect(r.ihr_risk.risk_level).not.toBe('CRITICAL')
    // A febrile Zambian traveller is never emitted as high-confidence VHF.
    expect(topScore(r)).toBeLessThan(55)
  })
})

describe('C. hallmark ABSENT (no bleeding, no rash)', () => {
  test('Marburg-shaped input with bleeding explicitly ABSENT falls below high band', () => {
    // This is the calibration target: even with a VHF-specific exposure and
    // endemic travel, explicit absence of bleeding must pull the VHF below
    // the high-confidence band because supports are insufficient.
    const r = getEnhanced(
      ['fever', 'severe_headache'],
      ['bleeding', 'rash_maculopapular'],
      ['contact_body_fluids'],
      [{ country_code: 'UG' }],
      { temperature_c: 38.5 }
    )
    const marburg = r.top_diagnoses.find((d: any) => d.disease_id === 'marburg_virus_disease')
    const ebola = r.top_diagnoses.find((d: any) => d.disease_id === 'ebola_virus_disease')
    if (marburg) expect(marburg.final_score).toBeLessThan(55)
    if (ebola) expect(ebola.final_score).toBeLessThan(55)
  })

  test('no hemorrhage + no rash + no VHF exposure → no VHF in top 1', () => {
    const r = getEnhanced(
      ['fever', 'cough'],
      ['bleeding', 'bleeding_gums_or_nose', 'rash_maculopapular'],
      [],
      [],
      { temperature_c: 38.0 }
    )
    expect(VHF_CORE).not.toContain(topId(r))
  })
})

describe('D. UNKNOWN treated as neutral, never positive', () => {
  test('empty present + empty absent → not a positive, non-case', () => {
    const r = getEnhanced([], [], [], [])
    expect(r.is_non_case).toBe(true)
  })

  test('symptom recorded as both present AND absent → treated as unknown (dropped)', () => {
    // Defensive hygiene: the engine must not let a conflicting entry count
    // as positive for anyone.
    const r = getEnhanced(['fever'], ['fever'], [], [])
    expect(r.calibration_version).toBeDefined()
    // Non-case (no symptoms remain after hygiene)
    expect(r.is_non_case).toBe(true)
    expect(topScore(r)).toBe(0)
  })
})

describe('E. exposure / incubation window violated', () => {
  test('days_since_onset 35 (outside Ebola 21 max) → Ebola not top-ranked', () => {
    const r = getEnhanced(
      ['fever', 'bleeding', 'severe_fatigue'],
      [],
      ['contact_body_fluids'],
      [{ country_code: 'UG' }],
      { temperature_c: 38.8 },
      { clinical_context: { days_since_onset: 35 } }
    )
    const ebola = r.top_diagnoses.find((d: any) => d.disease_id === 'ebola_virus_disease')
    // Either demoted out of top, or capped at minimal band.
    if (ebola) {
      expect(ebola.final_score).toBeLessThanOrEqual(
        (globalThis as any).DISEASES.engine.formula.calibration.incubation_violation_cap
      )
    }
  })
})

describe('F. common syndrome should outrank a VHF when no VHF signals', () => {
  test('fever + cough + sore throat (no travel, no exposure) → common ILI, not VHF', () => {
    const r = getEnhanced(
      ['fever', 'cough', 'sore_throat'],
      [],
      [],
      [],
      { temperature_c: 38.0 }
    )
    expect(VHF_CORE).not.toContain(topId(r))
    // Acceptable top candidates for this picture are common respiratory IDs.
    expect(r.ihr_risk.risk_level).not.toBe('CRITICAL')
  })
})

describe('G. true positive — sensitivity must NOT regress', () => {
  test('hemorrhage + endemic travel + fever + exposure + incubation OK → VHF top, CRITICAL', () => {
    const r = getEnhanced(
      ['fever', 'high_fever', 'severe_headache', 'severe_fatigue', 'bleeding', 'vomiting'],
      [],
      ['contact_body_fluids', 'contact_dead_body', 'travel_from_outbreak_area'],
      [{ country_code: 'UG' }],
      { temperature_c: 39.2, pulse_rate: 110 },
      { clinical_context: { days_since_onset: 7 } }
    )
    expect(VHF_CORE).toContain(topId(r))
    expect(topScore(r)).toBeGreaterThanOrEqual(70)
    expect(r.ihr_risk.risk_level).toBe('CRITICAL')
    expect(r.ihr_notification_required).toBe(true)
    expect(r.is_non_case).toBe(false)
  })

  test('suspected Marburg-type picture → critical alert survives calibration', () => {
    // Marburg and Ebola are clinically indistinguishable on this picture;
    // either VHF at the top is acceptable — what matters is the CRITICAL
    // escalation firing.
    const r = getEnhanced(
      ['high_fever', 'severe_headache', 'severe_fatigue', 'diarrhea', 'bleeding', 'bleeding_gums_or_nose'],
      [],
      ['contact_body_fluids', 'travel_from_outbreak_area'],
      [{ country_code: 'UG' }],
      { temperature_c: 39.6 },
      { clinical_context: { days_since_onset: 5 } }
    )
    expect(['marburg_virus_disease', 'ebola_virus_disease']).toContain(topId(r))
    expect(r.ihr_risk.risk_level).toBe('CRITICAL')
    expect(topScore(r)).toBeGreaterThanOrEqual(70)
  })
})

describe('H. officer override', () => {
  test('officer_declared_suspicion always raises alert, even when score is low', () => {
    const r = getEnhanced(
      ['fever'],
      [],
      [],
      [{ country_code: 'ZM' }],
      { temperature_c: 37.8 },
      {
        officer_declared_suspicion: {
          disease_id: 'marburg_virus_disease',
          min_risk_level: 'CRITICAL',
        },
      }
    )
    expect(r.ihr_risk.risk_level).toBe('CRITICAL')
    expect(r.ihr_notification_required).toBe(true)
    expect(r.global_flags).toContain('OFFICER_DECLARED_SUSPICION')
    expect(r.is_non_case).toBe(false)
  })

  test('officer override without a min_risk_level defaults to HIGH', () => {
    const r = getEnhanced(
      ['headache'],
      [],
      [],
      [],
      {},
      { officer_declared_suspicion: { disease_id: 'meningococcal_meningitis' } }
    )
    expect(r.ihr_risk.risk_level).toBe('HIGH')
    expect(r.ihr_notification_required).toBe(true)
  })
})

describe('I. nothing selected → no Tier-1 alert', () => {
  test('zero symptoms + zero exposures + zero travel + no vitals → no Tier-1 emission', () => {
    const r = getEnhanced([], [], [], [])
    expect(r.is_non_case).toBe(true)
    expect(r.ihr_risk.risk_level).toBe('LOW')
    expect(r.global_flags || []).not.toContain('NEEDS_IHR_NOTIFICATION')
    expect(r.global_flags || []).not.toContain('NEEDS_PUBLIC_HEALTH_NOTIFICATION')
  })
})

describe('J. non-specific hemorrhage (epistaxis alone) with no exposure', () => {
  test('fever + bleeding_gums_or_nose only, no exposure → VHF not top, not CRITICAL', () => {
    const r = getEnhanced(
      ['fever', 'bleeding_gums_or_nose'],
      [],
      [],
      [],
      { temperature_c: 38.0 }
    )
    expect(VHF_CORE).not.toContain(topId(r))
    expect(r.ihr_risk.risk_level).not.toBe('CRITICAL')
  })
})

describe('K. insufficient data guard', () => {
  test('1 symptom (fever) + 0 exposures + 0 travel → no VHF top, soft verdict', () => {
    const r = getEnhanced(['fever'], [], [], [], { temperature_c: 38.2 })
    expect(VHF_CORE).not.toContain(topId(r))
    // Insufficient-data flag should surface
    expect(r.global_flags || []).toEqual(expect.arrayContaining(['INSUFFICIENT_DATA']))
  })
})

describe('L. calibration adjustments attached to results', () => {
  test('each scored disease has calibration_adjustments breakdown', () => {
    const r = getEnhanced(['fever', 'cough'], [], [], [], { temperature_c: 38.0 })
    if (!r.is_non_case && r.top_diagnoses.length > 0) {
      const d = r.top_diagnoses[0]
      expect(d.calibration_adjustments).toBeDefined()
      expect(d.calibration_adjustments.supports).toBeDefined()
      expect(typeof d.calibration_adjustments.supports.total).toBe('number')
    }
  })
})
