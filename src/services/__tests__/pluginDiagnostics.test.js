/**
 * Smoke test for the in-app pluginDiagnostics runner.
 *
 * The runner orchestrates ~30 individual probes across every plugin wrapper
 * and is rendered by /settings/diagnostics. We don't reassert each probe
 * here (those have their own unit tests) — we just prove the orchestrator:
 *
 *   - never throws, even when a plugin import fails
 *   - returns a stable shape: { startedAt, finishedAt, durationMs, env, suites }
 *   - reports per-suite { id, title, summary, overall, durationMs, tests }
 *   - reports per-test { id, name, status, detail, durationMs, ... }
 *   - status enum is one of pass/warn/fail/skip/partial
 *   - the optional onSuite callback fires once per suite
 */

import { describe, it, expect, vi } from 'vitest'

// Mock @capacitor/core so the runner thinks we're on a non-native platform —
// this makes most native probes resolve fast and exercises the warn/skip paths.
vi.mock('@capacitor/core', () => ({
  Capacitor: { getPlatform: () => 'web' },
  registerPlugin: vi.fn(() => ({})),
}))

const VALID_OVERALL = new Set(['pass', 'warn', 'fail', 'skip', 'partial'])
const VALID_TEST    = new Set(['pass', 'warn', 'fail', 'skip'])

// The runner gives every native-call probe up to ~6s before timing it out
// in-band, then continues the run. With ~13 suites the worst case adds up,
// so we hand each test a generous timeout via the third arg to it().
// (Real device runs land in the 200-1500 ms range because the native
// modules either resolve or short-circuit via the platform/gate check.)
describe('pluginDiagnostics — orchestrator', () => {
  it('runs end-to-end and returns a well-formed report', async () => {
    const { runAllDiagnostics, DIAGNOSTIC_SUITES } = await import('../pluginDiagnostics.js')

    const seen = []
    const report = await runAllDiagnostics({ onSuite: (s) => seen.push(s.id) })

    // Top-level shape
    expect(report).toBeTypeOf('object')
    expect(typeof report.startedAt).toBe('string')
    expect(typeof report.finishedAt).toBe('string')
    expect(report.durationMs).toBeGreaterThanOrEqual(0)
    expect(Array.isArray(report.suites)).toBe(true)
    expect(report.suites.length).toBe(DIAGNOSTIC_SUITES.length)

    // env stamp
    expect(report.env).toBeTypeOf('object')
    expect(typeof report.env.platform).toBe('string')
    expect(typeof report.env.isNative).toBe('boolean')

    // onSuite fired once per suite, in order
    expect(seen).toEqual(DIAGNOSTIC_SUITES.map(s => s.id))

    // Every suite has the documented shape
    for (const s of report.suites) {
      expect(typeof s.id).toBe('string')
      expect(typeof s.title).toBe('string')
      expect(typeof s.summary).toBe('string')
      expect(VALID_OVERALL.has(s.overall)).toBe(true)
      expect(s.durationMs).toBeGreaterThanOrEqual(0)
      expect(Array.isArray(s.tests)).toBe(true)
      // every test in the suite
      for (const t of s.tests) {
        expect(typeof t.id).toBe('string')
        expect(typeof t.name).toBe('string')
        expect(VALID_TEST.has(t.status)).toBe(true)
        expect(typeof t.detail).toBe('string')
        expect(t.durationMs).toBeGreaterThanOrEqual(0)
        // error is either null/undefined or a serialised shape
        if (t.error) {
          expect(typeof t.error.message).toBe('string')
          expect(typeof t.error.name).toBe('string')
        }
      }
    }
  }, 60_000)

  it('survives a callback that throws — the run completes anyway', async () => {
    const { runAllDiagnostics } = await import('../pluginDiagnostics.js')
    const report = await runAllDiagnostics({ onSuite: () => { throw new Error('cb boom') } })
    expect(report.suites.length).toBeGreaterThan(0)
  }, 60_000)

  it('exports a stable suite list for the UI to render skeletons', async () => {
    const { DIAGNOSTIC_SUITES } = await import('../pluginDiagnostics.js')
    expect(Array.isArray(DIAGNOSTIC_SUITES)).toBe(true)
    expect(DIAGNOSTIC_SUITES.length).toBeGreaterThan(8) // covers env + sandbox + ~10 plugins
    for (const s of DIAGNOSTIC_SUITES) {
      expect(typeof s.id).toBe('string')
      expect(typeof s.title).toBe('string')
    }
  })
})
