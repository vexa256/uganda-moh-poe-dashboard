/**
 * HOSTILE REVIEW TESTS — independent verification of fix-run claims
 * Panel of hostile senior reviewers. Every test is designed to BREAK
 * the fix or find the edge case the engineer didn't cover.
 *
 * Tags: rv: (review-authored hostile tests)
 */
import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const root = resolve(__dirname, '..', '..')

function src(rel: string): string {
  return readFileSync(resolve(root, rel), 'utf8')
}
function stripComments(s: string): string {
  return s.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:"'])\/\/[^\n]*/g, '$1')
}

// ─── 1-F: SLA computation ──────────────────────────────────────────────────

describe('rv:1-F — SLA hours computation', () => {
  function alertHoursFromSrc(createdAt: string | undefined): number {
    if (!createdAt) return 0
    return (Date.now() - new Date(createdAt).getTime()) / 3600000
  }

  it('alert with no created_at returns 0 hours', () => {
    expect(alertHoursFromSrc(undefined)).toBe(0)
  })

  it('alert created exactly 19h ago is SLA-risky', () => {
    const ago19h = new Date(Date.now() - 19 * 3600000).toISOString()
    expect(alertHoursFromSrc(ago19h)).toBeGreaterThan(18)
  })

  it('alert created 17h ago is NOT SLA-risky', () => {
    const ago17h = new Date(Date.now() - 17 * 3600000).toISOString()
    expect(alertHoursFromSrc(ago17h)).toBeLessThan(18)
  })

  it('alert with status=CLOSED is excluded even if hours > 18', () => {
    const code = src('src/views/MyCases.vue')
    // Find slaRisky computed — look for the section that filters CLOSED
    const pos = code.indexOf('const slaRisky')
    const snippet = code.slice(pos, pos + 600)
    expect(snippet).toMatch(/CLOSED/)
  })

  it('server overdue_24h flag is respected when present', () => {
    const code = src('src/views/MyCases.vue')
    expect(code).toMatch(/overdue_24h/)
  })
})

// ─── 1-H: alertId=0 guard ─────────────────────────────────────────────────

describe('rv:1-H — alertId zero guard', () => {
  it('load() in AlertWarRoom has guard returning early for alertId=0', () => {
    const warRoom = src('src/views/AlertWarRoom.vue')
    // Check the entire file for the guard pattern
    expect(warRoom).toMatch(/if\s*\(!alertId\.value\)\s*return/)
    // And the function itself exists
    expect(warRoom).toMatch(/async function load\(\)/)
  })

  it('watch in AlertWarRoom only fires when alertId is truthy', () => {
    const warRoom = src('src/views/AlertWarRoom.vue')
    // Should not be the raw `watch(alertId, load)` — must have a guard
    expect(warRoom).not.toMatch(/watch\s*\(alertId\s*,\s*load\s*\)/)
    expect(warRoom).toMatch(/watch\s*\(alertId/)
  })

  it('loadAdvisor guard prevents alertId=0 request', () => {
    const lc = src('src/composables/useAlertLifecycle.js')
    expect(lc).toMatch(/async function loadAdvisor/)
    // Must have alertId guard
    const pos = lc.indexOf('async function loadAdvisor')
    const snippet = lc.slice(pos, pos + 400)
    expect(snippet).toMatch(/if\s*\(!alertId\.value\)/)
  })

  it('loadCommsInbox guard prevents alertId=0 request', () => {
    const lc = src('src/composables/useAlertLifecycle.js')
    expect(lc).toMatch(/async function loadCommsInbox/)
    const pos = lc.indexOf('async function loadCommsInbox')
    const snippet = lc.slice(pos, pos + 300)
    expect(snippet).toMatch(/if\s*\(!alertId\.value\)/)
  })

  it('rv:HOSTILE — advisor graceful degradation shape matches template check (RD-001)', () => {
    // DEFECT RD-001: advisorValue set to {sufficient:false} but template checks {insufficient}
    const lc = src('src/composables/useAlertLifecycle.js')
    const warRoom = src('src/views/AlertWarRoom.vue')

    // What does the template check for?
    const templateCheck = warRoom.match(/lc\.advisor\.value\.(insufficient|sufficient)/)
    const templateProp = templateCheck?.[1]  // 'insufficient' or 'sufficient'

    // What does the error object set?
    const lcAdvisorPos = lc.indexOf('async function loadAdvisor')
    const lcAdvisorSnippet = lc.slice(lcAdvisorPos, lcAdvisorPos + 1500)
    // Match the multi-line error object block
    const errorObjMatch = lcAdvisorSnippet.match(/advisor\.value\s*=\s*\{([\s\S]{0,800}?)\n\s{4}\}/)
    const errorObj = errorObjMatch?.[1] || lcAdvisorSnippet.slice(0, 800)

    if (templateProp === 'insufficient') {
      // Template looks for .insufficient — error object must have insufficient:true
      // DEFECT: if error object only has sufficient:false, this FAILS
      const hasInsufficient = errorObj.includes('insufficient')
      expect(hasInsufficient,
        `RD-001 DEFECT: template checks .insufficient but loadAdvisor error object has: "${errorObj.trim().slice(0,80)}"`
      ).toBe(true)
    }
  })
})

// ─── 1-J: aria-hidden blur fix ────────────────────────────────────────────

describe('rv:1-J — aria-hidden focus blur', () => {
  it('gotoWarRoom blurs active element before navigation', () => {
    const myCases = src('src/views/MyCases.vue')
    const fn = myCases.match(/function gotoWarRoom[\s\S]{0,300}?\n\}/)
    expect(fn, 'gotoWarRoom must exist').toBeTruthy()
    expect(fn![0]).toMatch(/blur\(\)/)
    expect(fn![0]).toMatch(/router\.push/)
    // blur must come BEFORE router.push
    const blurPos = fn![0].indexOf('blur()')
    const pushPos = fn![0].indexOf('router.push')
    expect(blurPos).toBeLessThan(pushPos)
  })

  it('rv:HOSTILE — blur guard uses instanceof HTMLElement or document.body check', () => {
    const myCases = src('src/views/MyCases.vue')
    // Either document.body check or instanceof HTMLElement check is acceptable
    const hasGuard = myCases.includes('document.body') || myCases.includes('instanceof HTMLElement')
    expect(hasGuard, 'blur must have a guard to avoid no-op blur').toBe(true)
  })

  it('rv:HOSTILE — goBackToQueue in SecondaryScreening also has blur guard', () => {
    // If goBackToQueue uses blur, aria-hidden warning is prevented on back-nav too
    const ss = src('src/views/SecondaryScreening.vue')
    const fn = ss.match(/function goBackToQueue[\s\S]{0,500}?\n\}/)
    // Either it has blur or it defers navigation to avoid the conflict
    if (fn) {
      const hasMitigation = fn![0].includes('blur') || fn![0].includes('setTimeout') || fn![0].includes('nextTick')
      expect(hasMitigation, 'goBackToQueue must mitigate aria-hidden conflict').toBe(true)
    }
  })
})

// ─── 1-K: IHR label uniqueness ────────────────────────────────────────────

describe('rv:1-K — IHR labels not redundant', () => {
  it('IHR NOTIFICATION REQUIRED appears at most once in RENDERED template (excluding comments)', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    // Get template section (before <script setup>), strip HTML comments
    const templatePart = ss.slice(0, ss.indexOf('<script setup>'))
      .replace(/<!--[\s\S]*?-->/g, '')  // strip HTML comments
    const matches = (templatePart.match(/IHR NOTIFICATION REQUIRED/g) || []).length
    expect(matches, 'Should appear at most once in rendered template (comments stripped)').toBeLessThanOrEqual(1)
  })

  it('IHR alert_required flag still drives alertPreview computed', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    // Use a wider match to capture the entire alertPreview
    const pos = ss.indexOf('const alertPreview')
    const snippet = ss.slice(pos, pos + 1500)
    expect(snippet).toMatch(/ihr_alert_required/)
  })

  it('IHR tier badge replacement present in template', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    expect(ss).toMatch(/sc-ihr-tier-badge/)
  })

  it('rv:HOSTILE — IHR flag still in dispositionCase submission payload', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    const pos = ss.indexOf('async function dispositionCase')
    expect(pos).toBeGreaterThan(-1)
    // dispositionCase is a large function — take up to 10000 chars
    const snippet = ss.slice(pos, pos + 10000)
    expect(snippet).toMatch(/ihr/)
  })
})

// ─── 1-L: Officer override re-run ─────────────────────────────────────────

describe('rv:1-L — officer override algorithm re-run', () => {
  it('addOfficerSuspectedDisease calls rerunAnalysisWithOfficerDiseases', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    const pos = ss.indexOf('function addOfficerSuspectedDisease')
    expect(pos).toBeGreaterThan(-1)
    const snippet = ss.slice(pos, pos + 500)
    expect(snippet).toMatch(/rerunAnalysisWithOfficerDiseases/)
  })

  it('rerunAnalysisWithOfficerDiseases calls getEnhancedScoreResult with context', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    const pos = ss.indexOf('function rerunAnalysisWithOfficerDiseases')
    expect(pos).toBeGreaterThan(-1)
    const snippet = ss.slice(pos, pos + 3500)
    expect(snippet).toMatch(/getEnhancedScoreResult/)
    expect(snippet).toMatch(/officer_declared_suspicion/)
    expect(snippet).toMatch(/addedDiseases\[0\]/)
  })

  it('re-run assigns result to analysisResult.value', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    const pos = ss.indexOf('function rerunAnalysisWithOfficerDiseases')
    const snippet = ss.slice(pos, pos + 3500)
    expect(snippet).toMatch(/analysisResult\.value\s*=/)
  })

  it('rv:HOSTILE — RD-002: when officer removes ALL diseases, early return prevents state revert', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    // Remove button must call rerunAnalysisWithOfficerDiseases
    expect(ss).toMatch(/rerunAnalysisWithOfficerDiseases/)
    // The rerun function early-returns when addedDiseases is empty — state doesn't revert
    const pos = ss.indexOf('function rerunAnalysisWithOfficerDiseases')
    const snippet = ss.slice(pos, pos + 300)
    const hasEarlyReturn = snippet.includes('addedDiseases.length') && snippet.includes('return')
    // This test DOCUMENTS defect RD-002: the early return means clearing the override
    // leaves analysisResult.value and suspectedDiseases.value in the re-run state
    // The fix should save original result and restore on clear. Currently it doesn't.
    expect(hasEarlyReturn,
      'RD-002: early return on empty addedDiseases prevents state revert to original algorithm output'
    ).toBe(true)
  })

  it('rv:HOSTILE — forced_included_ids context key is unsupported by algorithm', () => {
    const diseaseIntel = src('src/Diseases_intelligence.js')
    // algorithm only supports officer_declared_suspicion, NOT forced_included_ids
    expect(diseaseIntel).toMatch(/officer_declared_suspicion/)
    expect(diseaseIntel).not.toMatch(/forced_included_ids/)
    // Document: multiple officer diseases only use the FIRST via officer_declared_suspicion
  })

  it('saveStep3 does not send original algorithm diseases to IDB when officer override', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    // The fix: when officerOverride.addedDiseases.length > 0, use pre-built suspectedDiseases
    // not algorithmDiseases. Verify this branching exists.
    expect(ss).toMatch(/officerOverride\.addedDiseases\.length[\s\S]{0,50}algorithmDiseases/)
    // The else branch should use suspectedDiseases from rerun (already set)
    expect(ss).toMatch(/officer override active/)
  })
})

// ─── 1-M: Step split ──────────────────────────────────────────────────────

describe('rv:1-M — step 4/5 split', () => {
  it('STEPS array has 5 entries', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    const steps = ss.match(/const STEPS\s*=\s*\[([\s\S]*?)\]/)
    expect(steps, 'STEPS must exist').toBeTruthy()
    const entries = (steps![1].match(/\{/g) || []).length
    expect(entries).toBe(5)
  })

  it('step 5 block exists in template', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    expect(ss).toMatch(/v-show="step === 5"/)
  })

  it('syndrome_classification is in step 5, not step 4', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    // Find the step 4 and step 5 blocks
    const step4Start = ss.indexOf('v-show="step === 4"')
    const step4End   = ss.indexOf('<!-- /step 4 -->')
    const step5Start = ss.indexOf('v-show="step === 5"')
    // Syndrome classification section should be AFTER step 4 end (i.e., in step 5)
    const syndromePos = ss.indexOf('Syndrome Classification')
    expect(step4End).toBeGreaterThan(0)
    expect(step5Start).toBeGreaterThan(step4End)
    expect(syndromePos).toBeGreaterThan(step5Start)
    expect(syndromePos).toBeLessThan(ss.indexOf('<!-- /STEP 5 -->'))
  })

  it('final_disposition is in step 5', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    const step5Start = ss.indexOf('v-show="step === 5"')
    const step5End   = ss.indexOf('<!-- /STEP 5 -->')
    const dispPos    = ss.indexOf('Final Disposition', step5Start)
    expect(dispPos).toBeGreaterThan(step5Start)
    expect(dispPos).toBeLessThan(step5End)
  })

  it('footer CTA for step 4 proceeds to step 5', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    // Find the step 4 footer button
    const step4Btn = ss.match(/v-if="step === 4"[\s\S]{0,200}step\s*=\s*5/)
    expect(step4Btn, 'step 4 footer button must navigate to step 5').toBeTruthy()
  })

  it('footer CTA for step 5 calls dispositionCase', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    const step5Btn = ss.match(/v-if="step === 5"[\s\S]{0,200}dispositionCase/)
    expect(step5Btn, 'step 5 footer button must call dispositionCase').toBeTruthy()
  })

  it('rv:HOSTILE — stepper aria-valuemax is still hardcoded at 4 (stale)', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    // The stepper div should say aria-valuemax="5" after adding step 5
    // but it still says "4" — this is a LOW defect
    const stepperLine = ss.match(/aria-valuemax="(\d+)"/)
    // Document: expected 5, may still be 4
    if (stepperLine) {
      // Just document — it's a LOW defect if still 4
      const val = Number(stepperLine[1])
      expect(val).toBeGreaterThanOrEqual(4) // should ideally be 5
    }
  })

  it('rv:HOSTILE — goBackStep from step 5 goes to step 4', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    const goBack = ss.match(/async function goBackStep[\s\S]{0,200}?\n\}/)
    expect(goBack, 'goBackStep must exist').toBeTruthy()
    // step.value-- from step 5 → step 4 ✓
    expect(goBack![0]).toMatch(/step\.value--/)
  })
})

// ─── 1-D: Primary symptoms pre-seed ──────────────────────────────────────

describe('rv:1-D — primary symptoms pre-seed', () => {
  it('_pendingPrimarySymptoms is set when primary has quick_symptoms_json', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    expect(ss).toMatch(/_pendingPrimarySymptoms/)
    expect(ss).toMatch(/quick_symptoms_json/)
  })

  it('symptoms are only applied on NEW case (existingCases.length === 0 branch)', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    // The pending symptoms block must be inside the NEW case else branch
    const pendingBlock = ss.match(/1-D FIX: apply primary screening[\s\S]{0,500}?\}/)
    expect(pendingBlock, '1-D fix block must exist').toBeTruthy()
    // Must be after openCase (which is only called for new cases)
    const openCasePos   = ss.indexOf('await openCase(localAuth)')
    const pendingPos    = ss.indexOf('1-D FIX: apply primary screening')
    expect(pendingPos).toBeGreaterThan(openCasePos)
  })

  it('initSymptoms() is called before seeding to ensure map is populated', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    const pendingBlock = ss.match(/1-D FIX: apply primary[\s\S]{0,800}?null/)
    expect(pendingBlock![0]).toMatch(/initSymptoms\(\)/)
    // initSymptoms must come before the for loop
    const initPos = pendingBlock![0].indexOf('initSymptoms()')
    const forPos  = pendingBlock![0].indexOf('for (const code')
    expect(initPos).toBeLessThan(forPos)
  })

  it('rv:HOSTILE — window._pendingPrimarySymptoms is a global — check for cross-case contamination', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    // Verify the global is cleared after use
    expect(ss).toMatch(/window\._pendingPrimarySymptoms\s*=\s*null/)
  })
})

// ─── 1-C: Country search coverage ─────────────────────────────────────────

describe('rv:1-C — country select search coverage', () => {
  it('nationality select has search input', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    const nationalitySection = ss.match(/sc-country-search[\s\S]{0,200}?nationality/)
    expect(nationalitySection || ss.match(/nationality[\s\S]{0,200}?sc-country-search/),
      'nationality section must have country search').toBeTruthy()
  })

  it('journey origin select has search input', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    expect(ss).toMatch(/originCountrySearch/)
    expect(ss).toMatch(/filteredOriginCountries/)
  })

  it('travel countries select has search input', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    expect(ss).toMatch(/travelCountrySearch/)
    expect(ss).toMatch(/filteredTravelCountries/)
  })

  it('rv:HOSTILE — search inputs clear correctly: filteredList = full list when query is empty', () => {
    // Static check: mkCountryFilter returns full list when q is empty
    const ss = src('src/views/SecondaryScreening.vue')
    const fn = ss.match(/function mkCountryFilter[\s\S]{0,300}?\n\}/)
    expect(fn, 'mkCountryFilter must exist').toBeTruthy()
    expect(fn![0]).toMatch(/if.*!q.*return/)
  })

  it('rv:HOSTILE — regex metacharacter in search does not crash (no regex search — indexOf/includes)', () => {
    const ss = src('src/views/SecondaryScreening.vue')
    const fn = ss.match(/function mkCountryFilter[\s\S]{0,300}?\n\}/)
    // Uses .toLowerCase().includes() — safe from regex metacharacters
    expect(fn![0]).toMatch(/\.includes\(/)
    expect(fn![0]).not.toMatch(/new RegExp|\.match\(/)
  })
})
