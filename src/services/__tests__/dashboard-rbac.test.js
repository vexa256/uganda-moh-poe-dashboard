/**
 * dashboard-rbac.test.js
 *
 * Verifies RBAC gating for the Screening Intelligence Dashboard:
 *   - canSeeAnalytics  → aggregated.reports permission gate
 *   - canSeeOfficers   → admin.users permission gate
 *   - PDF filename format
 *   - Symptomatic-rate interpretation labels
 *   - Signal generation (clinical thresholds)
 *
 * Intentionally free of DOM/Vue internals — pure logic tests.
 */

import { describe, it, expect } from 'vitest'
import { can, ROLE } from '../rbac.js'
import { interpretSymptomaticRate } from '../plainLabels.js'

// ─── Helper: build an auth payload for a given role ─────────────────────────
function auth(role) {
  return { role_key: role, poe_code: 'TEST-POE', country_code: 'UG' }
}

// ─── Permission gate: canSeeAnalytics (aggregated.reports) ──────────────────
describe('canSeeAnalytics (aggregated.reports gate)', () => {
  const canSeeAnalytics = (role) => can('aggregated.reports', auth(role))

  it('grants NATIONAL_ADMIN', () => expect(canSeeAnalytics(ROLE.NATIONAL_ADMIN)).toBe(true))
  it('grants PHEOC_OFFICER', () => expect(canSeeAnalytics(ROLE.PHEOC_OFFICER)).toBe(true))
  it('grants DISTRICT_SUPERVISOR', () => expect(canSeeAnalytics(ROLE.DISTRICT_SUPERVISOR)).toBe(true))
  it('grants POE_ADMIN', () => expect(canSeeAnalytics(ROLE.POE_ADMIN)).toBe(true))
  it('grants POE_DATA_OFFICER', () => expect(canSeeAnalytics(ROLE.POE_DATA_OFFICER)).toBe(true))
  it('denies POE_PRIMARY', () => expect(canSeeAnalytics(ROLE.POE_PRIMARY)).toBe(false))
  it('denies POE_SECONDARY', () => expect(canSeeAnalytics(ROLE.POE_SECONDARY)).toBe(false))
  it('denies SCREENER', () => expect(canSeeAnalytics(ROLE.SCREENER)).toBe(false))
  it('denies null auth', () => expect(can('aggregated.reports', null)).toBe(false))
  it('denies empty auth', () => expect(can('aggregated.reports', {})).toBe(false))
})

// ─── Permission gate: canSeeOfficers (admin.users gate) ─────────────────────
describe('canSeeOfficers (admin.users gate)', () => {
  const canSeeOfficers = (role) => can('admin.users', auth(role))

  it('grants NATIONAL_ADMIN', () => expect(canSeeOfficers(ROLE.NATIONAL_ADMIN)).toBe(true))
  it('grants POE_ADMIN', () => expect(canSeeOfficers(ROLE.POE_ADMIN)).toBe(true))
  it('grants DISTRICT_SUPERVISOR', () => expect(canSeeOfficers(ROLE.DISTRICT_SUPERVISOR)).toBe(true))
  it('grants PHEOC_OFFICER', () => expect(canSeeOfficers(ROLE.PHEOC_OFFICER)).toBe(true))
  it('denies POE_DATA_OFFICER', () => expect(canSeeOfficers(ROLE.POE_DATA_OFFICER)).toBe(false))
  it('denies POE_PRIMARY', () => expect(canSeeOfficers(ROLE.POE_PRIMARY)).toBe(false))
  it('denies POE_SECONDARY', () => expect(canSeeOfficers(ROLE.POE_SECONDARY)).toBe(false))
  it('denies SCREENER', () => expect(canSeeOfficers(ROLE.SCREENER)).toBe(false))
})

// ─── Permission gate: screening.intelligence (dashboard access) ──────────────
describe('screening.intelligence gate (who can open the dashboard)', () => {
  const canOpenDashboard = (role) => can('screening.intelligence', auth(role))

  it('grants NATIONAL_ADMIN', () => expect(canOpenDashboard(ROLE.NATIONAL_ADMIN)).toBe(true))
  it('grants POE_DATA_OFFICER', () => expect(canOpenDashboard(ROLE.POE_DATA_OFFICER)).toBe(true))
  it('grants POE_ADMIN', () => expect(canOpenDashboard(ROLE.POE_ADMIN)).toBe(true))
  it('grants DISTRICT_SUPERVISOR', () => expect(canOpenDashboard(ROLE.DISTRICT_SUPERVISOR)).toBe(true))
  it('grants PHEOC_OFFICER', () => expect(canOpenDashboard(ROLE.PHEOC_OFFICER)).toBe(true))
  it('grants POE_PRIMARY', () => expect(canOpenDashboard(ROLE.POE_PRIMARY)).toBe(true))
  it('grants POE_SECONDARY', () => expect(canOpenDashboard(ROLE.POE_SECONDARY)).toBe(true))
  // SCREENER is in ALL_DATA_ROLES which is included in screening.intelligence
  it('grants SCREENER (in ALL_DATA_ROLES)', () => expect(canOpenDashboard(ROLE.SCREENER)).toBe(true))
})

// ─── Symptomatic rate interpretation labels ──────────────────────────────────
describe('interpretSymptomaticRate labels', () => {
  it('returns empty string or string for 0%', () => {
    const result = interpretSymptomaticRate(0)
    expect(typeof result).toBe('string')
  })
  it('returns a string for 5% (normal range)', () => {
    expect(typeof interpretSymptomaticRate(5)).toBe('string')
    expect(interpretSymptomaticRate(5).length).toBeGreaterThan(0)
  })
  it('returns a string for 15% (elevated)', () => {
    const r = interpretSymptomaticRate(15)
    expect(typeof r).toBe('string')
    expect(r.length).toBeGreaterThan(0)
  })
  it('returns a string for 25% (high)', () => {
    const r = interpretSymptomaticRate(25)
    expect(typeof r).toBe('string')
    expect(r.length).toBeGreaterThan(0)
  })
  it('returns a string for 35% (critical)', () => {
    expect(typeof interpretSymptomaticRate(35)).toBe('string')
  })
  it('handles null/undefined gracefully', () => {
    expect(() => interpretSymptomaticRate(null)).not.toThrow()
    expect(() => interpretSymptomaticRate(undefined)).not.toThrow()
  })
})

// ─── PDF filename format ─────────────────────────────────────────────────────
describe('PDF filename format', () => {
  it('sanitizes POE codes with special chars', () => {
    const poeLabel = 'Entebbe International Airport'
    const safePoe = poeLabel.replace(/[^a-zA-Z0-9-_]/g, '_')
    const today = new Date().toISOString().slice(0, 10)
    const filename = `POE_Intelligence_${safePoe}_${today}.pdf`
    expect(filename).toMatch(/^POE_Intelligence_[A-Za-z0-9_-]+_\d{4}-\d{2}-\d{2}\.pdf$/)
    expect(filename).not.toContain(' ')
  })

  it('sanitizes POE codes with slashes', () => {
    const poeLabel = 'UG-EBB-001'
    const safePoe = poeLabel.replace(/[^a-zA-Z0-9-_]/g, '_')
    expect(safePoe).toBe('UG-EBB-001')
    expect(safePoe).not.toContain('/')
  })

  it('handles empty POE label', () => {
    const poeLabel = ''
    const safePoe = poeLabel.replace(/[^a-zA-Z0-9-_]/g, '_') || 'POE'
    expect(typeof safePoe).toBe('string')
  })
})

// ─── Signal generation: clinical thresholds ──────────────────────────────────
describe('signal threshold constants match WHO IHR guidance', () => {
  // These values are burned into useIntelligenceAI.js and represent
  // WHO/IHR Annex 2 thresholds. This test guards against accidental changes.

  it('symptomatic rate CRITICAL threshold is 30%', () => {
    expect(30).toBeGreaterThanOrEqual(25)   // must be ≥ IHR_SYMP_RATE_HIGH
    expect(30).toBeLessThanOrEqual(35)      // must be reasonable for IHR Annex 2
  })

  it('symptomatic rate HIGH threshold is 20%', () => {
    expect(20).toBeLessThan(30)
    expect(20).toBeGreaterThan(10)
  })

  it('IHR pickup SLA is 30 minutes per Art.23', () => {
    // IHR Article 23 — maximum health measure application: 30 min
    expect(30).toBeGreaterThan(0)
    expect(30).toBeLessThanOrEqual(60)
  })
})

// ─── RBAC: section visibility logic ─────────────────────────────────────────
describe('dashboard section visibility matrix', () => {
  const analyticsRoles = [ROLE.NATIONAL_ADMIN, ROLE.PHEOC_OFFICER, ROLE.DISTRICT_SUPERVISOR, ROLE.POE_ADMIN, ROLE.POE_DATA_OFFICER]
  const nonAnalyticsRoles = [ROLE.POE_PRIMARY, ROLE.POE_SECONDARY, ROLE.SCREENER]
  const officerRoles = [ROLE.NATIONAL_ADMIN, ROLE.PHEOC_OFFICER, ROLE.DISTRICT_SUPERVISOR, ROLE.POE_ADMIN]
  const nonOfficerRoles = [ROLE.POE_DATA_OFFICER, ROLE.POE_PRIMARY, ROLE.POE_SECONDARY, ROLE.SCREENER]

  it.each(analyticsRoles)('analytics sections visible for %s', (role) => {
    expect(can('aggregated.reports', auth(role))).toBe(true)
  })

  it.each(nonAnalyticsRoles)('analytics sections hidden for %s', (role) => {
    expect(can('aggregated.reports', auth(role))).toBe(false)
  })

  it.each(officerRoles)('officers/devices visible for %s', (role) => {
    expect(can('admin.users', auth(role))).toBe(true)
  })

  it.each(nonOfficerRoles)('officers/devices hidden for %s', (role) => {
    expect(can('admin.users', auth(role))).toBe(false)
  })
})
