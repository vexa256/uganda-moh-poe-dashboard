/**
 * Sentinel kill-switch integration tests.
 *
 * Proves that the three kill-switch layers (per-feature / master / freeze)
 * each independently shut a Sentinel feature off — regardless of the other
 * two. This is the release-blocking test from docs/sentinel-plan/TEST_PLAN.md.
 *
 * Uses jsdom's localStorage (vitest environment: jsdom, already configured
 * in vite.config.ts).
 */
import { beforeEach, describe, it, expect } from 'vitest'
import {
  CAPABILITY_KEYS, SENTINEL_KEYS,
  isEnabled, setEnabled, isSentinelFeatureOn,
} from '../capabilities.js'
import { makePluginSandbox } from '../plugins/_base.js'

beforeEach(() => {
  // Fresh localStorage before each test so defaults apply.
  localStorage.clear()
})

describe('Sentinel capability keys — defaults', () => {
  it('master switch defaults to ON', () => {
    expect(isEnabled(CAPABILITY_KEYS.SENTINEL_MASTER)).toBe(true)
  })
  it('downloads-frozen defaults to OFF', () => {
    expect(isEnabled(CAPABILITY_KEYS.SENTINEL_FROZEN)).toBe(false)
  })
  it('every Sentinel feature defaults to OFF', () => {
    for (const k of SENTINEL_KEYS) {
      expect(isEnabled(k), `${k} must default OFF`).toBe(false)
    }
  })
})

describe('isSentinelFeatureOn — master + per-feature (freeze affects downloads only)', () => {
  it('returns false when feature flag is off (master on)', () => {
    expect(isSentinelFeatureOn(CAPABILITY_KEYS.MRZ)).toBe(false)
  })

  it('returns true when feature flag is on (master on)', () => {
    setEnabled(CAPABILITY_KEYS.MRZ, true)
    expect(isSentinelFeatureOn(CAPABILITY_KEYS.MRZ)).toBe(true)
  })

  it('master OFF kills every feature regardless of individual toggle', () => {
    for (const k of SENTINEL_KEYS) setEnabled(k, true)
    setEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, false)
    for (const k of SENTINEL_KEYS) {
      expect(isSentinelFeatureOn(k), `${k} must be off when master off`).toBe(false)
    }
  })

  it('freeze does NOT block pure features — only downloads are gated', () => {
    // Freeze is the "block model downloads" kill switch. Features that do not
    // require downloads (e.g. BCBP, Voice Wizard) continue to work. The
    // download gate lives in the sandbox layer and is exercised in the
    // "returns fallback when downloads frozen" test below.
    for (const k of SENTINEL_KEYS) setEnabled(k, true)
    setEnabled(CAPABILITY_KEYS.SENTINEL_FROZEN, true)
    for (const k of SENTINEL_KEYS) {
      expect(isSentinelFeatureOn(k), `${k} must remain on when frozen (UI surface)`).toBe(true)
    }
  })

  it('restoring master brings feature flags back without needing to re-toggle', () => {
    setEnabled(CAPABILITY_KEYS.MRZ, true)
    setEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, false)
    expect(isSentinelFeatureOn(CAPABILITY_KEYS.MRZ)).toBe(false)
    setEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, true)
    expect(isSentinelFeatureOn(CAPABILITY_KEYS.MRZ)).toBe(true)
  })
})

describe('makePluginSandbox — master gate short-circuits sentinel-tagged sandbox', () => {
  function mkSandbox({ sentinel, settingsKey }) {
    return makePluginSandbox({
      name: 'test-sandbox',
      importer: async () => ({ echo: (x) => x }),
      settingsKey,
      requiresNative: false,
      sentinel,
    })
  }

  it('runs the function when all gates are open', async () => {
    setEnabled(CAPABILITY_KEYS.MRZ, true)
    const sb = mkSandbox({ sentinel: true, settingsKey: CAPABILITY_KEYS.MRZ })
    const out = await sb.run(async (mod) => mod.echo('ok'), { fallback: 'fb' })
    expect(out).toBe('ok')
  })

  it('returns fallback when per-feature flag is off (master on)', async () => {
    // MRZ defaults to off
    const sb = mkSandbox({ sentinel: true, settingsKey: CAPABILITY_KEYS.MRZ })
    const out = await sb.run(async () => 'ok', { fallback: 'fb' })
    expect(out).toBe('fb')
  })

  it('returns fallback when master is off even though per-feature is on', async () => {
    setEnabled(CAPABILITY_KEYS.MRZ, true)
    setEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, false)
    const sb = mkSandbox({ sentinel: true, settingsKey: CAPABILITY_KEYS.MRZ })
    const out = await sb.run(async () => 'ok', { fallback: 'fb' })
    expect(out).toBe('fb')
  })

  it('returns fallback when downloads frozen even with everything else on', async () => {
    setEnabled(CAPABILITY_KEYS.MRZ, true)
    setEnabled(CAPABILITY_KEYS.SENTINEL_FROZEN, true)
    const sb = mkSandbox({ sentinel: true, settingsKey: CAPABILITY_KEYS.MRZ })
    const out = await sb.run(async () => 'ok', { fallback: 'fb' })
    expect(out).toBe('fb')
  })

  it('non-sentinel sandbox is unaffected by master off', async () => {
    // master off should NOT affect regular sandboxes (only sentinel-tagged ones)
    setEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, false)
    setEnabled(CAPABILITY_KEYS.BARCODE, true)
    const sb = mkSandbox({ sentinel: false, settingsKey: CAPABILITY_KEYS.BARCODE })
    const out = await sb.run(async () => 'ok', { fallback: 'fb' })
    expect(out).toBe('ok')
  })

  it('non-sentinel sandbox is unaffected by freeze', async () => {
    setEnabled(CAPABILITY_KEYS.SENTINEL_FROZEN, true)
    setEnabled(CAPABILITY_KEYS.BARCODE, true)
    const sb = mkSandbox({ sentinel: false, settingsKey: CAPABILITY_KEYS.BARCODE })
    const out = await sb.run(async () => 'ok', { fallback: 'fb' })
    expect(out).toBe('ok')
  })

  it('sandbox swallows inner exceptions and returns fallback', async () => {
    setEnabled(CAPABILITY_KEYS.MRZ, true)
    const sb = mkSandbox({ sentinel: true, settingsKey: CAPABILITY_KEYS.MRZ })
    const out = await sb.run(async () => { throw new Error('boom') }, { fallback: 'fb' })
    expect(out).toBe('fb')
  })
})

describe('applySentinelFields — form-write guard', () => {
  let applySentinelFields
  beforeEach(async () => {
    const mod = await import('../sentinelFormWrite.js')
    applySentinelFields = mod.applySentinelFields
  })

  it('returns empty array on garbage inputs', () => {
    expect(applySentinelFields(null, {})).toEqual([])
    expect(applySentinelFields({}, null)).toEqual([])
    expect(applySentinelFields({ value: {} }, 'nope')).toEqual([])
  })

  it('writes fields that are empty in the form', () => {
    const form = { value: { name: '', passport_number: null, nationality: '' } }
    const filled = applySentinelFields(form, {
      name: 'JANE SMITH',
      passport_number: 'X12345',
      nationality: 'ZMB',
    })
    expect(filled.sort()).toEqual(['name', 'nationality', 'passport_number'])
    expect(form.value.name).toBe('JANE SMITH')
    expect(form.value.passport_number).toBe('X12345')
  })

  it('NEVER overwrites a field the officer already typed', () => {
    const form = { value: { name: 'OFFICER TYPED', passport_number: '' } }
    const filled = applySentinelFields(form, {
      name: 'SCANNER SAYS',
      passport_number: 'X12345',
    })
    expect(filled).toEqual(['passport_number'])
    expect(form.value.name).toBe('OFFICER TYPED')      // preserved
    expect(form.value.passport_number).toBe('X12345')
  })

  it('NEVER introduces a new key that did not exist in the form', () => {
    const form = { value: { name: '' } }
    const filled = applySentinelFields(form, {
      name: 'OK',
      passport_number: 'should-not-appear',
    })
    expect(filled).toEqual(['name'])
    expect('passport_number' in form.value).toBe(false)
  })

  it('ignores null/undefined/empty-string values in the partial', () => {
    const form = { value: { name: '', nationality: '' } }
    const filled = applySentinelFields(form, { name: null, nationality: undefined })
    expect(filled).toEqual([])
    expect(form.value.name).toBe('')
  })

  it('swallows a throwing setter without crashing', () => {
    const form = { value: {} }
    Object.defineProperty(form.value, 'locked', {
      enumerable: true,
      get: () => '',
      set: () => { throw new Error('read-only') },
    })
    const filled = applySentinelFields(form, { locked: 'X' })
    expect(filled).toEqual([])   // write failed but no crash
  })
})
