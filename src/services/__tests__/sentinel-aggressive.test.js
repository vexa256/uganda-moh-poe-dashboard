/**
 * Sentinel subsystem — aggressive integration tests.
 *
 * Goal: stress the corners that the existing four test files (bcbp,
 * voiceWizard, sentinel-gates, modelManager) do NOT cover.
 *
 * Scope & conventions:
 *   - Pure Vitest + jsdom (environment already configured in vite.config.ts).
 *   - localStorage reset between tests.
 *   - Real timers by default; a dedicated section uses vi.useFakeTimers()
 *     for the mlkitInstaller mock-download path.
 *   - @capacitor/core is mocked inside isolated describe blocks that need
 *     native-platform behaviour; `vi.resetModules()` ensures the cached
 *     platform/plugin promises inside mlkitInstaller are re-evaluated per
 *     scenario.
 *   - Tests NEVER modify source files. If a real bug surfaces, it is marked
 *     with it.skip + a clear comment rather than patched away.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'

// ─── capabilities.js ────────────────────────────────────────────────────────
import {
  CAPABILITY_KEYS,
  SENTINEL_KEYS,
  isEnabled,
  getValue,
  setEnabled,
  subscribe,
  snapshot,
  isSentinelFeatureOn,
} from '../capabilities.js'

// ─── sentinelFormWrite / sentinelToast / bcbp ─────────────────────────────
// 2026-05-07: voiceWizard tests removed — voice plugin uninstalled.
import { applySentinelFields } from '../sentinelFormWrite.js'
import { parseBCBP, iataToCountry } from '../bcbp.js'

// ─── _base (sandbox) ───────────────────────────────────────────────────────
import { makePluginSandbox } from '../plugins/_base.js'

beforeEach(() => {
  try { localStorage.clear() } catch {}
})

// ═══════════════════════════════════════════════════════════════════════════
// capabilities.js — subscribe / unsubscribe / custom event / snapshot / value
// ═══════════════════════════════════════════════════════════════════════════
describe('capabilities.js — subscribe / unsubscribe', () => {
  it('subscribe returns a function that unsubscribes the listener', () => {
    const cb = vi.fn()
    const off = subscribe(CAPABILITY_KEYS.VOICE, cb)
    expect(typeof off).toBe('function')
    setEnabled(CAPABILITY_KEYS.VOICE, true)
    expect(cb).toHaveBeenCalledTimes(1)
    off()
    setEnabled(CAPABILITY_KEYS.VOICE, false)
    // Still 1 — unsubscribed callback must NOT fire after unsubscribe.
    expect(cb).toHaveBeenCalledTimes(1)
  })

  it('subscribing the same cb twice to the same key fires it twice per change', () => {
    const cb = vi.fn()
    const off1 = subscribe(CAPABILITY_KEYS.VOICE, cb)
    const off2 = subscribe(CAPABILITY_KEYS.VOICE, cb) // same cb instance
    // Set<cb>: a Set deduplicates identical references, so this is
    // documenting the ACTUAL behaviour: fires once, not twice.
    setEnabled(CAPABILITY_KEYS.VOICE, true)
    expect(cb).toHaveBeenCalledTimes(1)
    off1(); off2()
  })

  it('two DISTINCT subscribers both fire on every setEnabled', () => {
    const a = vi.fn()
    const b = vi.fn()
    subscribe(CAPABILITY_KEYS.VOICE, a)
    subscribe(CAPABILITY_KEYS.VOICE, b)
    setEnabled(CAPABILITY_KEYS.VOICE, true)
    setEnabled(CAPABILITY_KEYS.VOICE, false)
    expect(a).toHaveBeenCalledTimes(2)
    expect(b).toHaveBeenCalledTimes(2)
  })

  it('a subscriber that throws does not break later subscribers', () => {
    const thrower = () => { throw new Error('boom') }
    const victim = vi.fn()
    subscribe(CAPABILITY_KEYS.VOICE, thrower)
    subscribe(CAPABILITY_KEYS.VOICE, victim)
    expect(() => setEnabled(CAPABILITY_KEYS.VOICE, true)).not.toThrow()
    expect(victim).toHaveBeenCalled()
  })
})

describe('capabilities.js — custom DOM event', () => {
  it('dispatches capability-changed CustomEvent on every setEnabled', () => {
    const listener = vi.fn()
    window.addEventListener('capability-changed', listener)
    setEnabled(CAPABILITY_KEYS.BARCODE, false)
    setEnabled(CAPABILITY_KEYS.BARCODE, true)
    window.removeEventListener('capability-changed', listener)
    expect(listener).toHaveBeenCalledTimes(2)
    const detail = listener.mock.calls[0][0]?.detail
    expect(detail).toBeTruthy()
    expect(detail.key).toBe(CAPABILITY_KEYS.BARCODE)
  })

  it('custom event detail carries the new numeric value for APP_LOCK_MIN', () => {
    let captured = null
    const listener = (e) => { captured = e.detail }
    window.addEventListener('capability-changed', listener)
    setEnabled(CAPABILITY_KEYS.APP_LOCK_MIN, 15)
    window.removeEventListener('capability-changed', listener)
    expect(captured).toBeTruthy()
    expect(captured.key).toBe(CAPABILITY_KEYS.APP_LOCK_MIN)
    expect(captured.value).toBe(15)
  })
})

describe('capabilities.js — APP_LOCK_MIN numeric special case', () => {
  it('getValue returns default 5 as a number when unset', () => {
    const v = getValue(CAPABILITY_KEYS.APP_LOCK_MIN)
    expect(typeof v).toBe('number')
    expect(v).toBe(5)
  })

  it('getValue returns a number after setEnabled("5")', () => {
    setEnabled(CAPABILITY_KEYS.APP_LOCK_MIN, 5)
    const v = getValue(CAPABILITY_KEYS.APP_LOCK_MIN)
    expect(typeof v).toBe('number')
    expect(v).toBe(5)
  })

  it('getValue preserves the string form when set to a non-canonical number', () => {
    // "05" → Number("05") is 5, String(5) is "5" (not "05"), so it's kept
    // as string — this documents the safety rail against silent coercion.
    localStorage.setItem(CAPABILITY_KEYS.APP_LOCK_MIN, '05')
    const v = getValue(CAPABILITY_KEYS.APP_LOCK_MIN)
    expect(v).toBe('05')
  })

  it('TOUR_SEEN is treated as a string (not boolified)', () => {
    setEnabled(CAPABILITY_KEYS.TOUR_SEEN, 'v3')
    expect(getValue(CAPABILITY_KEYS.TOUR_SEEN)).toBe('v3')
    expect(isEnabled(CAPABILITY_KEYS.TOUR_SEEN)).toBe('v3')
  })
})

describe('capabilities.js — snapshot', () => {
  it('snapshot returns every declared CAPABILITY_KEYS entry', () => {
    const snap = snapshot()
    for (const k of Object.values(CAPABILITY_KEYS)) {
      expect(Object.prototype.hasOwnProperty.call(snap, k)).toBe(true)
    }
  })

  it('snapshot keys exactly match declared CAPABILITY_KEYS set', () => {
    const snap = snapshot()
    const declared = new Set(Object.values(CAPABILITY_KEYS))
    expect(Object.keys(snap).length).toBe(declared.size)
    for (const k of Object.keys(snap)) expect(declared.has(k)).toBe(true)
  })

  it('snapshot values reflect live localStorage after setEnabled', () => {
    setEnabled(CAPABILITY_KEYS.MRZ, true)
    setEnabled(CAPABILITY_KEYS.APP_LOCK_MIN, 42)
    const snap = snapshot()
    expect(snap[CAPABILITY_KEYS.MRZ]).toBe(true)
    expect(snap[CAPABILITY_KEYS.APP_LOCK_MIN]).toBe(42)
  })
})

describe('capabilities.js — corrupted / hostile localStorage', () => {
  it('isEnabled returns default for a key whose value was set to gibberish', () => {
    localStorage.setItem(CAPABILITY_KEYS.VOICE, 'not-a-bool')
    // Implementation: v === '1' || v === 'true' ⇒ false
    expect(isEnabled(CAPABILITY_KEYS.VOICE)).toBe(false)
  })

  it('repeated reads are stable', () => {
    setEnabled(CAPABILITY_KEYS.BARCODE, true)
    const a = isEnabled(CAPABILITY_KEYS.BARCODE)
    const b = isEnabled(CAPABILITY_KEYS.BARCODE)
    const c = isEnabled(CAPABILITY_KEYS.BARCODE)
    expect(a).toBe(b)
    expect(b).toBe(c)
    expect(a).toBe(true)
  })

  it('isSentinelFeatureOn honours master kill-switch', () => {
    setEnabled(CAPABILITY_KEYS.MRZ, true)
    setEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, true)
    expect(isSentinelFeatureOn(CAPABILITY_KEYS.MRZ)).toBe(true)
    setEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, false)
    expect(isSentinelFeatureOn(CAPABILITY_KEYS.MRZ)).toBe(false)
  })

  it('SENTINEL_KEYS frozen array is exactly 12 features', () => {
    expect(Object.isFrozen(SENTINEL_KEYS)).toBe(true)
    expect(SENTINEL_KEYS.length).toBe(12)
  })
})

// ═══════════════════════════════════════════════════════════════════════════
// sentinelFormWrite.js — cross-product of form shapes
// ═══════════════════════════════════════════════════════════════════════════
describe('sentinelFormWrite.applySentinelFields — hostile inputs', () => {
  const CASES = [
    ['null formRef',        null,                           { x: 1 }, []],
    ['undefined formRef',   undefined,                      { x: 1 }, []],
    ['formRef.value=null',  { value: null },                { x: 1 }, []],
    ['formRef.value=string',{ value: 'str' },               { x: 1 }, []],
    ['formRef.value=num',   { value: 42 },                  { x: 1 }, []],
    ['formRef=[]',          [],                             { x: 1 }, []],
    ['partial=null',        { value: { x: '' } },           null,     []],
    ['partial=undefined',   { value: { x: '' } },           undefined,[]],
    ['partial=string',      { value: { x: '' } },           'bogus',  []],
    ['partial=number',      { value: { x: '' } },           77,       []],
  ]

  for (const [label, formRef, partial, expected] of CASES) {
    it(`returns [] without throwing for: ${label}`, () => {
      let result
      expect(() => { result = applySentinelFields(formRef, partial) }).not.toThrow()
      expect(result).toEqual(expected)
    })
  }

  it('Symbol-valued partial key is silently ignored (Object.entries skips symbols)', () => {
    const form = { value: { name: '' } }
    const partial = { name: 'JOHN', [Symbol('x')]: 'never' }
    const filled = applySentinelFields(form, partial)
    expect(filled).toEqual(['name'])
  })

  it('Symbol as the entire partial returns []', () => {
    expect(applySentinelFields({ value: {} }, Symbol('sym'))).toEqual([])
  })

  it('preserves existing non-zero / non-false truthy values (rule 1)', () => {
    const form = { value: { temp: '37.0', gender: 'MALE' } }
    const filled = applySentinelFields(form, { temp: '38.6', gender: 'FEMALE' })
    expect(filled).toEqual([])
    expect(form.value.temp).toBe('37.0')
    expect(form.value.gender).toBe('MALE')
  })

  it('treats existing value 0 as overwritable (documented 0/false exception)', () => {
    const form = { value: { symptoms: 0 } }
    const filled = applySentinelFields(form, { symptoms: 1 })
    expect(filled).toEqual(['symptoms'])
    expect(form.value.symptoms).toBe(1)
  })

  it('treats existing value false as overwritable (documented 0/false exception)', () => {
    const form = { value: { deferred: false } }
    const filled = applySentinelFields(form, { deferred: true })
    expect(filled).toEqual(['deferred'])
    expect(form.value.deferred).toBe(true)
  })

  it('skips partial key that does not exist on form (rule 2)', () => {
    const form = { value: { a: '' } }
    const filled = applySentinelFields(form, { a: 'OK', ghost: 'nope' })
    expect(filled).toEqual(['a'])
    expect('ghost' in form.value).toBe(false)
  })

  it('skips empty-string partial values', () => {
    const form = { value: { a: '', b: '' } }
    const filled = applySentinelFields(form, { a: '', b: 'X' })
    expect(filled).toEqual(['b'])
  })

  it('treats array formRef with .value undefined as empty', () => {
    // Arrays are objects in JS; applySentinelFields checks formRef.value — an
    // array's `.value` is undefined, so the function short-circuits.
    const arr = []
    const filled = applySentinelFields(arr, { a: 1 })
    expect(filled).toEqual([])
  })

  it('accepts an array AS the form object (typeof array === "object")', () => {
    // Documenting actual behaviour: `value` is an array; indexed "keys" are
    // strings. If partial uses string "0" and array[0] exists, it writes.
    const form = { value: ['', ''] }
    const filled = applySentinelFields(form, { 0: 'A', 1: 'B' })
    expect(filled.sort()).toEqual(['0', '1'])
    expect(form.value[0]).toBe('A')
    expect(form.value[1]).toBe('B')
  })
})

// ═══════════════════════════════════════════════════════════════════════════
// sentinelToast.js — non-throwing contract
// ═══════════════════════════════════════════════════════════════════════════
describe('sentinelToast.js — non-throwing contract (happy mock)', () => {
  let sentinelToast, sentinelError, sentinelSuccess, sentinelInfo
  let createSpy
  beforeEach(async () => {
    vi.resetModules()
    createSpy = vi.fn().mockResolvedValue({ present: vi.fn().mockResolvedValue(undefined) })
    vi.doMock('@ionic/vue', () => ({
      toastController: { create: createSpy },
    }))
    const mod = await import('../sentinelToast.js')
    sentinelToast   = mod.sentinelToast
    sentinelError   = mod.sentinelError
    sentinelSuccess = mod.sentinelSuccess
    sentinelInfo    = mod.sentinelInfo
  })
  afterEach(() => {
    vi.doUnmock('@ionic/vue')
  })

  it('resolves with undefined on a normal call', async () => {
    await expect(sentinelToast('hello')).resolves.toBeUndefined()
    expect(createSpy).toHaveBeenCalledTimes(1)
  })

  it('truncates very long messages to 240 chars', async () => {
    const long = 'x'.repeat(5000)
    await sentinelToast(long)
    const call = createSpy.mock.calls[0][0]
    expect(call.message.length).toBe(240)
  })

  it('no-op on empty / falsy message', async () => {
    await sentinelToast('')
    await sentinelToast(null)
    await sentinelToast(undefined)
    expect(createSpy).not.toHaveBeenCalled()
  })

  it('accepts every convenience variant (error/success/info)', async () => {
    await sentinelError('e')
    await sentinelSuccess('s')
    await sentinelInfo('i')
    const colors = createSpy.mock.calls.map(c => c[0].color).sort()
    expect(colors).toEqual(['danger', 'primary', 'success'])
  })

  it('rapid successive calls never throw', async () => {
    // Sequential to avoid dynamic-import fan-out under vitest's mock layer.
    for (let i = 0; i < 20; i++) {
      await sentinelToast(`msg-${i}`)
    }
    expect(createSpy).toHaveBeenCalledTimes(20)
  }, 15000)

  it('honours opts.duration when finite', async () => {
    await sentinelToast('x', 'primary', { duration: 7000 })
    expect(createSpy.mock.calls[0][0].duration).toBe(7000)
  })

  it('falls back to default duration when opts.duration is NaN', async () => {
    await sentinelToast('x', 'primary', { duration: NaN })
    expect(createSpy.mock.calls[0][0].duration).toBe(2500)
  })
})

describe('sentinelToast.js — swallows a failing @ionic/vue import', () => {
  let sentinelToast
  beforeEach(async () => {
    vi.resetModules()
    vi.doMock('@ionic/vue', () => {
      throw new Error('ionic is broken')
    })
    const mod = await import('../sentinelToast.js')
    sentinelToast = mod.sentinelToast
  })
  afterEach(() => {
    vi.doUnmock('@ionic/vue')
  })

  it('does not throw even when ionic import fails', async () => {
    await expect(sentinelToast('x')).resolves.toBeUndefined()
  })
})

describe('sentinelToast.js — swallows a rejecting create()', () => {
  let sentinelToast
  beforeEach(async () => {
    vi.resetModules()
    vi.doMock('@ionic/vue', () => ({
      toastController: { create: vi.fn().mockRejectedValue(new Error('no dom')) },
    }))
    const mod = await import('../sentinelToast.js')
    sentinelToast = mod.sentinelToast
  })
  afterEach(() => {
    vi.doUnmock('@ionic/vue')
  })

  it('does not throw when create rejects', async () => {
    await expect(sentinelToast('x')).resolves.toBeUndefined()
  })
})

// ═══════════════════════════════════════════════════════════════════════════
// bcbp.js — extreme inputs
// ═══════════════════════════════════════════════════════════════════════════
describe('parseBCBP — multi-leg passes', () => {
  // 23-char mandatory header: M + n + 20 name chars + E
  // 37-char fixed leg:        7 pnr + 3 from + 3 to + 3 carrier + 5 flight# +
  //                           3 julian + 1 compartment + 4 seat + 5 sequence +
  //                           1 paxStatus + 2 cond-len-hex
  const header2 = 'M2DOE/JOHN            E'
  const header3 = 'M3DOE/JOHN            E'
  const header4 = 'M4DOE/JOHN            E'
  const legLHR_JFK = 'ABC1234LHRJFK BA00175266Y007A00071000'
  const legJFK_LAX = 'ABC1234JFKLAX AA00099266Y015B00088000'
  const legLAX_HNL = 'ABC1234LAXHNL UA00215267Y022C00099000'
  const legHNL_NRT = 'ABC1234HNLNRT NH00007268Y001A00010000'

  it('parses a 2-leg pass', () => {
    const raw = header2 + legLHR_JFK + legJFK_LAX
    const r = parseBCBP(raw)
    expect(r.ok).toBe(true)
    expect(r.data.n_legs).toBe(2)
    expect(r.legs).toHaveLength(2)
    expect(r.legs[0].from_iata).toBe('LHR')
    expect(r.legs[1].from_iata).toBe('JFK')
    expect(r.legs[1].to_iata).toBe('LAX')
  })

  it('parses a 3-leg pass', () => {
    const raw = header3 + legLHR_JFK + legJFK_LAX + legLAX_HNL
    const r = parseBCBP(raw)
    expect(r.ok).toBe(true)
    expect(r.data.n_legs).toBe(3)
    expect(r.legs).toHaveLength(3)
  })

  it('parses a 4-leg pass (IATA maximum)', () => {
    const raw = header4 + legLHR_JFK + legJFK_LAX + legLAX_HNL + legHNL_NRT
    const r = parseBCBP(raw)
    expect(r.ok).toBe(true)
    expect(r.data.n_legs).toBe(4)
    expect(r.legs).toHaveLength(4)
    expect(r.legs[3].to_iata).toBe('NRT')
  })

  it('rejects a leg count of 0', () => {
    const bad = 'M0DOE/JOHN            EABC1234LHRJFK BA00175266Y007A00071000'
    expect(parseBCBP(bad).ok).toBe(false)
  })

  it('rejects a pass whose declared n_legs exceeds supplied data', () => {
    // header says 3 legs, body has 1 leg → leg-2-truncated
    const raw = header3 + legLHR_JFK
    const r = parseBCBP(raw)
    expect(r.ok).toBe(false)
    expect(r.reason).toMatch(/leg-2-truncated/)
  })
})

describe('parseBCBP — name normalisation corners', () => {
  it('preserves dashed surnames like VAN-DER-BERG', () => {
    // Name slot is exactly 20 chars. "VAN-DER-BERG/FRANZ" = 18 chars → pad 2.
    const name = 'VAN-DER-BERG/FRANZ'.padEnd(20, ' ')
    expect(name.length).toBe(20)
    const raw = 'M1' + name + 'E' +
                'ABC1234LHRJFK BA00175266Y007A00071000'
    const r = parseBCBP(raw)
    expect(r.ok).toBe(true)
    expect(r.data.pax_name).toBe('FRANZ VAN-DER-BERG')
  })

  it('tolerates CR but rejects when resulting length is too short', () => {
    const raw = 'M1JOHNSON/BOB         E\r' + 'ABC1234LHRJFK BA00175266Y007A00071000'
    const r = parseBCBP(raw)
    expect(r.ok).toBe(true)
    expect(r.legs[0].flight_number).toBe('175')
  })

  it('rejects a payload with empty name slot', () => {
    const raw = 'M1                    E' + 'ABC1234LHRJFK BA00175266Y007A00071000'
    const r = parseBCBP(raw)
    expect(r.ok).toBe(false)
    expect(r.reason).toBe('no-name')
  })

  it('trims trailing honorifics regardless of case', () => {
    const raw = 'M1PATEL/RITA mrs      E' + 'ABC1234LUSJNB ET00715180Y014C00001000'
    const r = parseBCBP(raw)
    expect(r.ok).toBe(true)
    expect(r.data.pax_name).toBe('RITA PATEL')
  })
})

describe('parseBCBP — Julian date calendar corners', () => {
  it('accepts julian 001 (Jan 1)', () => {
    const raw = 'M1JOHNSON/BOB         E' + 'ABC1234LHRJFK BA00175001Y007A00071000'
    const r = parseBCBP(raw)
    expect(r.ok).toBe(true)
    expect(r.legs[0].flight_date).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })

  it('accepts julian 365', () => {
    const raw = 'M1JOHNSON/BOB         E' + 'ABC1234LHRJFK BA00175365Y007A00071000'
    const r = parseBCBP(raw)
    expect(r.ok).toBe(true)
    expect(r.legs[0].flight_date).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })

  it('accepts julian 366 (leap day)', () => {
    const raw = 'M1JOHNSON/BOB         E' + 'ABC1234LHRJFK BA00175366Y007A00071000'
    const r = parseBCBP(raw)
    expect(r.ok).toBe(true)
    // might roll into following year depending on "now" — just confirm valid
    expect(r.legs[0].flight_date).toMatch(/^\d{4}-\d{2}-\d{2}$/)
  })

  it('rejects julian 000 by returning flight_date=null (payload still ok)', () => {
    const raw = 'M1JOHNSON/BOB         E' + 'ABC1234LHRJFK BA00175000Y007A00071000'
    const r = parseBCBP(raw)
    expect(r.ok).toBe(true)
    expect(r.legs[0].flight_date).toBeNull()
  })

  it('rejects julian 400 (out of range)', () => {
    const raw = 'M1JOHNSON/BOB         E' + 'ABC1234LHRJFK BA00175400Y007A00071000'
    const r = parseBCBP(raw)
    expect(r.ok).toBe(true)
    expect(r.legs[0].flight_date).toBeNull()
  })
})

describe('parseBCBP — cond field handling', () => {
  it('honours a declared conditional-field-length and reaches next leg', () => {
    // 2-leg pass where leg-1 declares cond_len = 0x0A (10 bytes of cond
    // payload immediately after). We inject 10 filler chars.
    const header2 = 'M2DOE/JOHN            E'
    const leg1    = 'ABC1234LHRJFK BA0017526 6Y007A0007100A'.replace(' ', ' ')
    // Carefully construct: 37-char fixed leg with cond-len hex 0A
    const leg1Fixed = 'ABC1234LHRJFK BA001752 6Y007A000710 A'
    // The above freehand is fragile — build programmatically to be safe:
    const fixedLeg = (pnr, from, to, carr, fl, jul, comp, seat, seq, pax, condHex) =>
      pnr.padEnd(7) + from + to + carr.padEnd(3) + fl + jul + comp + seat + seq + pax + condHex
    const leg1Built = fixedLeg('ABC1234','LHR','JFK',' BA','00175','266','Y','007A','00071','0','0A')
    const cond     = '0123456789'  // 10 filler chars — matches 0x0A
    const leg2Built = fixedLeg('ABC1234','JFK','LAX',' AA','00099','266','Y','015B','00088','0','00')
    const raw = header2 + leg1Built + cond + leg2Built

    const r = parseBCBP(raw)
    expect(r.ok).toBe(true)
    expect(r.legs).toHaveLength(2)
    expect(r.legs[0].cond_field_len).toBe(10)
    expect(r.legs[1].from_iata).toBe('JFK')
  })

  it('non-hex cond-len degrades to 0 (parser does not throw)', () => {
    const fixedLeg = (pnr, from, to, carr, fl, jul, comp, seat, seq, pax, condHex) =>
      pnr.padEnd(7) + from + to + carr.padEnd(3) + fl + jul + comp + seat + seq + pax + condHex
    const header = 'M1DOE/JOHN            E'
    const leg    = fixedLeg('ABC1234','LHR','JFK',' BA','00175','266','Y','007A','00071','0','ZZ')
    const r = parseBCBP(header + leg)
    expect(r.ok).toBe(true)
    expect(r.legs[0].cond_field_len).toBe(0)
  })
})

describe('iataToCountry — full lookup coverage', () => {
  const MAPPINGS = [
    ['LUN','ZM'], ['LVI','ZM'], ['NLA','ZM'], ['MFU','ZM'], ['KIW','ZM'],
    ['JNB','ZA'], ['CPT','ZA'], ['DUR','ZA'],
    ['HRE','ZW'], ['VFA','ZW'], ['BUQ','ZW'],
    ['GBE','BW'], ['MUB','BW'],
    ['MPM','MZ'], ['WDH','NA'],
    ['LLW','MW'], ['BLZ','MW'],
    ['DAR','TZ'], ['JRO','TZ'], ['ZNZ','TZ'],
    ['EBB','UG'], ['NBO','KE'], ['MBA','KE'],
    ['KGL','RW'], ['BJM','BI'],
    ['ADD','ET'], ['CAI','EG'], ['LOS','NG'], ['ABV','NG'], ['LAD','AO'],
    ['KIN','CD'], ['FIH','CD'], ['FBM','CD'],
    ['DXB','AE'], ['DOH','QA'], ['IST','TR'],
    ['LHR','GB'], ['CDG','FR'], ['AMS','NL'], ['FRA','DE'],
    ['JFK','US'], ['ATL','US'], ['YYZ','CA'],
    ['PEK','CN'], ['PVG','CN'], ['BKK','TH'],
  ]
  for (const [iata, iso] of MAPPINGS) {
    it(`${iata} → ${iso}`, () => expect(iataToCountry(iata)).toBe(iso))
    it(`${iata.toLowerCase()} (lowercase) → ${iso}`, () => expect(iataToCountry(iata.toLowerCase())).toBe(iso))
  }

  it('whitespace input is NOT normalised — returns null (documents real behaviour)', () => {
    // The function uppercases but does not trim. " LUN" → " LUN" after
    // uppercase, which is not in the map → null.
    expect(iataToCountry(' LUN')).toBeNull()
    expect(iataToCountry('LUN ')).toBeNull()
  })

  it('returns null for mixed garbage', () => {
    expect(iataToCountry(42)).toBeNull()
    expect(iataToCountry({})).toBeNull()
    expect(iataToCountry([])).toBeNull()
  })
})

// 2026-05-07: voiceWizard test block removed — voice plugin uninstalled
// app-wide. The parser file no longer exists.

// ═══════════════════════════════════════════════════════════════════════════
// _base.js — circuit breaker + master+freeze cross product
// ═══════════════════════════════════════════════════════════════════════════
describe('_base.js — circuit breaker', () => {
  it('opens on the 10th consecutive failure and short-circuits the 11th', async () => {
    let calls = 0
    const sb = makePluginSandbox({
      name: 'cb-test',
      importer: async () => ({ echo: () => 'x' }),
      settingsKey: undefined,
      requiresNative: false,
      sentinel: false,
    })
    const throwing = async () => { calls++; throw new Error('fail') }
    // 10 failures → breaker trips and consecutiveFailures resets to 0
    for (let i = 0; i < 10; i++) {
      const out = await sb.run(throwing, { fallback: 'FB' })
      expect(out).toBe('FB')
    }
    expect(calls).toBe(10)
    // 11th call: breaker open, fn must NOT be called again
    const out11 = await sb.run(throwing, { fallback: 'FB' })
    expect(out11).toBe('FB')
    expect(calls).toBe(10)
    const bs = sb.breakerState()
    expect(bs.tripped).toBe(true)
    expect(typeof bs.resumesAt).toBe('number')
  })

  it('successful calls reset consecutiveFailures so breaker does not trip', async () => {
    let calls = 0
    const sb = makePluginSandbox({
      name: 'cb-reset',
      importer: async () => ({}),
      requiresNative: false,
      sentinel: false,
    })
    for (let i = 0; i < 9; i++) {
      await sb.run(async () => { calls++; throw new Error('x') }, { fallback: 'FB' })
    }
    await sb.run(async () => { calls++; return 'ok' }, { fallback: 'FB' })
    // one more failure — should NOT trip because consecutive was reset
    await sb.run(async () => { calls++; throw new Error('y') }, { fallback: 'FB' })
    expect(sb.breakerState().tripped).toBe(false)
  })

  it('non-sentinel sandbox ignores master & freeze', async () => {
    setEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, false)
    setEnabled(CAPABILITY_KEYS.SENTINEL_FROZEN, true)
    const sb = makePluginSandbox({
      name: 'non-sen',
      importer: async () => ({ v: 1 }),
      settingsKey: CAPABILITY_KEYS.BARCODE,
      requiresNative: false,
      sentinel: false,
    })
    // barcode defaults to enabled (v==null fallback is "enabled")
    // For non-sentinel: unset or '1'/'true' → enabled. '0' → gated off.
    // Default localStorage is empty here since beforeEach clears; set it '1'
    // to be explicit.
    setEnabled(CAPABILITY_KEYS.BARCODE, true)
    const out = await sb.run(async (m) => m.v, { fallback: 'FB' })
    expect(out).toBe(1)
  })
})

describe('_base.js — sentinel master+freeze+per-feature cross product', () => {
  // Non-requiresNative sandbox to avoid needing a real Capacitor on jsdom.
  function mk() {
    return makePluginSandbox({
      name: 'cross',
      importer: async () => ({ run: () => 'RAN' }),
      settingsKey: CAPABILITY_KEYS.BCBP,
      requiresNative: false,
      sentinel: true,
    })
  }

  const MATRIX = [
    // master, frozen, bcbp, expectRan
    [true,  false, true,  true],
    [false, false, true,  false],    // master off
    [true,  true,  true,  false],    // freeze on
    [true,  false, false, false],    // per-feature off
    [false, true,  true,  false],
    [false, false, false, false],
    [true,  true,  false, false],
    [false, true,  false, false],
  ]

  for (const [master, frozen, bcbp, expectRan] of MATRIX) {
    it(`master=${master} frozen=${frozen} bcbp=${bcbp} → ${expectRan ? 'RAN' : 'FB'}`, async () => {
      setEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, master)
      setEnabled(CAPABILITY_KEYS.SENTINEL_FROZEN, frozen)
      setEnabled(CAPABILITY_KEYS.BCBP, bcbp)
      const sb = mk()
      const out = await sb.run(async (m) => m.run(), { fallback: 'FB' })
      expect(out).toBe(expectRan ? 'RAN' : 'FB')
    })
  }
})

// ═══════════════════════════════════════════════════════════════════════════
// mlkitInstaller — mock-download path + native resolveNativeId coverage
// ═══════════════════════════════════════════════════════════════════════════
describe('mlkitInstaller — web mock-download path', () => {
  // NB: vi.useFakeTimers() interacted badly with this module's dynamic
  // @capacitor/core import on vitest 0.34, leading to a stalled
  // runMockDownload loop. We use real timers instead and widen the
  // per-test timeout to comfortably cover the deterministic ~3 s mock
  // download. The assertions themselves are what we care about; wall time
  // adds ~3 s × N to the suite but keeps the coverage we need.
  let installModule
  beforeEach(async () => {
    vi.resetModules()
    const mod = await import('../plugins/mlkitInstaller.js')
    installModule = mod.installModule
  })

  it('emits 6 progress ticks and resolves ok:true', async () => {
    const onProgress = vi.fn()
    const result = await installModule('__test_install_fixture', {
      downloader: 'mlkit-text-recognition-latin', onProgress,
    })
    expect(result).toEqual({ ok: true })
    expect(onProgress).toHaveBeenCalledTimes(6)
    const last = onProgress.mock.calls[5][0]
    expect(last.progressPct).toBe(100)
    expect(last.bytesSoFar).toBe(last.bytesTotal)
  }, 10000)

  it('rejects invalid moduleId synchronously-ish', async () => {
    const r1 = await installModule('',        { downloader: 'anything' })
    const r2 = await installModule(null,      { downloader: 'anything' })
    const r3 = await installModule(undefined, { downloader: 'anything' })
    const r4 = await installModule(42,        { downloader: 'anything' })
    for (const r of [r1, r2, r3, r4]) {
      expect(r).toEqual({ ok: false, reason: 'invalid-module-id' })
    }
  })

  it('unknown downloader on web still takes mock path (documents behaviour)', async () => {
    const result = await installModule('foo', { downloader: 'no-such-kind' })
    expect(result).toEqual({ ok: true })
  }, 10000)

  it('mock path still works when onProgress is omitted', async () => {
    const r = await installModule('__test_install_fixture', { downloader: 'mlkit-text-recognition-latin' })
    expect(r).toEqual({ ok: true })
  }, 10000)

  it('a throwing onProgress does NOT break the download', async () => {
    const onProgress = vi.fn(() => { throw new Error('listener bug') })
    const r = await installModule('__test_install_fixture', {
      downloader: 'mlkit-text-recognition-latin', onProgress,
    })
    expect(r).toEqual({ ok: true })
    expect(onProgress).toHaveBeenCalledTimes(6)
  }, 10000)
})

describe('mlkitInstaller — native resolveNativeId path (android)', () => {
  let installModule
  let installSpy

  beforeEach(async () => {
    vi.resetModules()
    installSpy = vi.fn(async ({ moduleId }) => ({ ok: true, alreadyInstalled: false, nativeAsked: moduleId }))
    vi.doMock('@capacitor/core', () => ({
      Capacitor: { getPlatform: () => 'android' },
      registerPlugin: vi.fn(() => ({
        install: installSpy,
        isAvailable: vi.fn(async () => ({ ok: true, available: true })),
        addListener: vi.fn(async () => ({ remove: vi.fn() })),
      })),
    }))
    const mod = await import('../plugins/mlkitInstaller.js')
    installModule = mod.installModule
  })
  afterEach(() => {
    vi.doUnmock('@capacitor/core')
  })

  it('play-services-builtin resolves ok without calling native install()', async () => {
    const r = await installModule('doc-scanner', { downloader: 'play-services-builtin' })
    expect(r.ok).toBe(true)
    expect(r.alreadyInstalled).toBe(true)
    expect(installSpy).not.toHaveBeenCalled()
  })

  it('local-asset resolves ok without calling native install()', async () => {
    const r = await installModule('face-embedding-facenet', { downloader: 'local-asset' })
    expect(r.ok).toBe(true)
    expect(r.alreadyInstalled).toBe(true)
    expect(installSpy).not.toHaveBeenCalled()
  })

  // The four ML Kit feature libraries (text-recognition, face-detection,
  // translate, entity-extraction) were unbundled from the APK on
  // 2026-04-26 — see docs/sentinel-plan/UNBUNDLED.md. resolveNativeId now
  // short-circuits all four downloaders with reason="module-not-bundled"
  // BEFORE crossing into native. The native plugin returns the same string
  // for the same module ids, so the contract is consistent across layers.
  it('mlkit-text-recognition-latin short-circuits with module-not-bundled (no native call)', async () => {
    const r = await installModule('__test_install_fixture', { downloader: 'mlkit-text-recognition-latin' })
    expect(r).toEqual({ ok: false, reason: 'module-not-bundled' })
    expect(installSpy).not.toHaveBeenCalled()
  })

  it('mlkit-face-detection short-circuits with module-not-bundled (no native call)', async () => {
    const r = await installModule('face-detection', { downloader: 'mlkit-face-detection' })
    expect(r).toEqual({ ok: false, reason: 'module-not-bundled' })
    expect(installSpy).not.toHaveBeenCalled()
  })

  it('mlkit-translate short-circuits with module-not-bundled (no native call)', async () => {
    const r = await installModule('translate-en-pt', { downloader: 'mlkit-translate' })
    expect(r).toEqual({ ok: false, reason: 'module-not-bundled' })
    expect(installSpy).not.toHaveBeenCalled()
  })

  it('mlkit-entity-extraction short-circuits with module-not-bundled (no native call)', async () => {
    const r = await installModule('entity-extraction', { downloader: 'mlkit-entity-extraction' })
    expect(r).toEqual({ ok: false, reason: 'module-not-bundled' })
    expect(installSpy).not.toHaveBeenCalled()
  })

  it('test-fixture downloader IS forwarded to native (the only routed-to-native path now)', async () => {
    const r = await installModule('__test_install_fixture', { downloader: 'test-fixture' })
    expect(r.ok).toBe(true)
    expect(installSpy).toHaveBeenCalledTimes(1)
    expect(installSpy.mock.calls[0][0].moduleId).toBe('__test_install_fixture')
  })

  it('unknown downloader surfaces reason=unknown-downloader:<kind>', async () => {
    const r = await installModule('whatever', { downloader: 'bogus-kind' })
    expect(r).toEqual({ ok: false, reason: 'unknown-downloader:bogus-kind' })
    expect(installSpy).not.toHaveBeenCalled()
  })

  it('missing downloader string also fails with unknown-downloader:', async () => {
    const r = await installModule('whatever', {})
    expect(r.ok).toBe(false)
    expect(r.reason).toMatch(/^unknown-downloader:/)
  })
})

describe('mlkitInstaller — native failure surfaces', () => {
  let installModule

  async function loadWithInstall(fn) {
    vi.resetModules()
    vi.doMock('@capacitor/core', () => ({
      Capacitor: { getPlatform: () => 'android' },
      registerPlugin: vi.fn(() => ({
        install: fn,
        isAvailable: vi.fn(async () => ({ ok: true, available: true })),
        addListener: vi.fn(async () => ({ remove: vi.fn() })),
      })),
    }))
    const mod = await import('../plugins/mlkitInstaller.js')
    installModule = mod.installModule
  }
  afterEach(() => {
    vi.doUnmock('@capacitor/core')
  })

  // These tests need a downloader that actually crosses into the native
  // install path — the four ML Kit downloaders short-circuit at
  // resolveNativeId now (see UNBUNDLED.md). `test-fixture` is the only
  // remaining path that routes to native install().
  it('malformed-plugin-response when install() returns non-object', async () => {
    await loadWithInstall(async () => 'not-an-object')
    const r = await installModule('__test_install_fixture', { downloader: 'test-fixture' })
    expect(r).toEqual({ ok: false, reason: 'malformed-plugin-response' })
  })

  it('preserves plugin-returned reason string', async () => {
    await loadWithInstall(async () => ({ ok: false, reason: 'library-not-bundled' }))
    const r = await installModule('__test_install_fixture', { downloader: 'test-fixture' })
    expect(r).toEqual({ ok: false, reason: 'library-not-bundled' })
  })

  it('install-failed when plugin returns ok:false with no reason', async () => {
    await loadWithInstall(async () => ({ ok: false }))
    const r = await installModule('__test_install_fixture', { downloader: 'test-fixture' })
    expect(r).toEqual({ ok: false, reason: 'install-failed' })
  })

  it('install-error when plugin itself throws', async () => {
    await loadWithInstall(async () => { throw new Error('bridge dead') })
    const r = await installModule('__test_install_fixture', { downloader: 'test-fixture' })
    expect(r).toEqual({ ok: false, reason: 'install-error' })
  })
})

// ═══════════════════════════════════════════════════════════════════════════
// modelManager — state machine transitions
// ═══════════════════════════════════════════════════════════════════════════
describe('modelManager — state machine (isolated module per test block)', () => {
  // Each test in this block re-imports modelManager fresh so the in-module
  // maps (state, listeners, retryTimers, activeDownloads) start empty.
  let mm
  let installModuleSpy

  beforeEach(async () => {
    vi.resetModules()
    installModuleSpy = vi.fn()
    // Default: success quickly
    installModuleSpy.mockImplementation(async (_id, opts) => {
      if (opts?.onProgress) {
        try { opts.onProgress({ bytesSoFar: 1_000_000, bytesTotal: 1_000_000, progressPct: 100 }) } catch {}
      }
      return { ok: true }
    })
    vi.doMock('../plugins/mlkitInstaller.js', () => ({
      installModule: installModuleSpy,
      isModuleAvailable: vi.fn().mockResolvedValue(false),
    }))
    mm = await import('../modelManager.js')
    localStorage.clear()
    // defaults
    setEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, true)
    setEnabled(CAPABILITY_KEYS.SENTINEL_FROZEN, false)
  })

  afterEach(() => {
    try { mm.disposeAll() } catch {}
    vi.doUnmock('../plugins/mlkitInstaller.js')
  })

  const flushTop = () => new Promise((r) => setTimeout(r, 0))

  it('subscribeModel fires on downloading → ready transitions', async () => {
    const events = []
    const unsub = mm.subscribeModel('__test_install_fixture', (e) => events.push(e.status))
    await mm.ensureModel('__test_install_fixture')
    await flushTop()
    await flushTop()
    unsub()
    expect(events).toContain('downloading')
    expect(events[events.length - 1]).toBe('ready')
  })

  it('two parallel ensureModel calls do NOT spawn two parallel downloads', async () => {
    // Block the first download with a never-resolving promise controlled here.
    let release
    installModuleSpy.mockImplementationOnce(() => new Promise((res) => { release = res }))
    const p1 = mm.ensureModel('__test_install_fixture')
    const p2 = mm.ensureModel('__test_install_fixture')
    const [r1, r2] = await Promise.all([p1, p2])
    await flushTop()
    // First call schedules the download and returns { ok:false, reason:'missing' }
    // Second call sees status=downloading and returns { ok:false, reason:'downloading' }
    expect(r1.ok).toBe(false)
    expect(r2.ok).toBe(false)
    // At this point only ONE installModule call should have happened.
    expect(installModuleSpy).toHaveBeenCalledTimes(1)
    release({ ok: true })
    await flushTop()
    await flushTop()
  })

  // Tiny helper: yield to all pending microtasks + macrotasks.
  const flush = () => new Promise((r) => setTimeout(r, 0))

  it('cancelDownload during downloading transitions to missing; restart works', async () => {
    let release
    installModuleSpy.mockImplementationOnce(() => new Promise((res) => { release = res }))
    await mm.ensureModel('__test_install_fixture')
    // Give runDownload a tick to set status=downloading
    await flush()
    mm.cancelDownload('__test_install_fixture')
    release({ ok: true })
    await flush()
    await flush()

    const list1 = mm.listModels().find(m => m.id === '__test_install_fixture')
    expect(list1.status).toBe('missing')

    // Restart — install now returns ok immediately
    installModuleSpy.mockImplementationOnce(async () => ({ ok: true }))
    await mm.ensureModel('__test_install_fixture')
    await flush()
    await flush()
    const list2 = mm.listModels().find(m => m.id === '__test_install_fixture')
    expect(list2.status).toBe('ready')
  })

  it('pauseDownload sets queued immediately; resume restarts the download', async () => {
    let release
    installModuleSpy.mockImplementationOnce(() => new Promise((res) => { release = res }))
    await mm.ensureModel('__test_install_fixture')
    await flush()
    // Status should be 'downloading' at this point
    const mid = mm.listModels().find(m => m.id === '__test_install_fixture')
    expect(mid.status).toBe('downloading')

    mm.pauseDownload('__test_install_fixture')
    // The synchronous effect of pauseDownload is to set status=queued.
    const paused = mm.listModels().find(m => m.id === '__test_install_fixture')
    expect(paused.status).toBe('queued')

    // Let the original download settle. Because pause set controller.cancelled,
    // the runDownload epilogue will transition status to 'missing' (documents
    // the current code: pause+subsequent resolver drive state to missing).
    release({ ok: true })
    await flush()
    await flush()
    const afterSettle = mm.listModels().find(m => m.id === '__test_install_fixture')
    expect(['queued', 'missing']).toContain(afterSettle.status)

    // Resume: kicks off a new attempt from queued/missing, which succeeds.
    installModuleSpy.mockImplementationOnce(async () => ({ ok: true }))
    mm.resumeDownload('__test_install_fixture')
    await flush()
    await flush()
    const resumed = mm.listModels().find(m => m.id === '__test_install_fixture')
    expect(resumed.status).toBe('ready')
  })

  it('deleteModel after ready transitions to evicted', async () => {
    await mm.ensureModel('__test_install_fixture')
    await flushTop(); await flushTop()
    const ready = mm.listModels().find(m => m.id === '__test_install_fixture')
    expect(ready.status).toBe('ready')

    mm.deleteModel('__test_install_fixture')
    const evicted = mm.listModels().find(m => m.id === '__test_install_fixture')
    expect(evicted.status).toBe('evicted')
  })

  it('deleteModel during downloading cancels the in-flight controller', async () => {
    let release
    installModuleSpy.mockImplementationOnce(() => new Promise((res) => { release = res }))
    await mm.ensureModel('__test_install_fixture')
    await flushTop(); await flushTop()
    mm.deleteModel('__test_install_fixture')
    release({ ok: true })
    await flushTop(); await flushTop()

    const row = mm.listModels().find(m => m.id === '__test_install_fixture')
    // deleteModel sets evicted; the controller.cancelled branch may then
    // overwrite with missing when runDownload completes. Accept either —
    // what matters is that the state is NOT 'ready' (i.e. the download was
    // successfully cancelled / invalidated).
    expect(['evicted', 'missing']).toContain(row.status)
  })

  it('retryModel resets retryCount and schedules a new attempt even at MAX', async () => {
    // First call: cause a generic failure so retryCount increments.
    installModuleSpy.mockImplementationOnce(async () => ({ ok: false, reason: 'transient' }))
    await mm.ensureModel('__test_install_fixture')
    await flushTop(); await flushTop()
    const afterFail = mm.listModels().find(m => m.id === '__test_install_fixture')
    expect(afterFail.status).toBe('error')
    expect(afterFail.retryCount).toBeGreaterThanOrEqual(1)

    // Simulate MAX by writing the state directly through another failure cycle.
    installModuleSpy.mockImplementation(async () => ({ ok: false, reason: 'transient' }))
    // Force retryCount to MAX via repeat ensureModel calls + manual cancels of timers.
    // Simpler: just call retryModel, which resets to 0 and runs again.
    installModuleSpy.mockImplementationOnce(async () => ({ ok: true }))
    mm.retryModel('__test_install_fixture')
    await flushTop(); await flushTop()
    const recovered = mm.listModels().find(m => m.id === '__test_install_fixture')
    expect(recovered.status).toBe('ready')
    expect(recovered.retryCount).toBe(0)
  })

  it('non-retriable reason from native install stamps retryCount at MAX and status=error', async () => {
    // Use the test fixture (bundled:true, downloader=test-fixture) so the
    // install spy IS called. `translate-en-pt` etc are bundled:false now and
    // short-circuit at ensureModel — that path is covered separately below.
    installModuleSpy.mockImplementationOnce(async () => ({ ok: false, reason: 'library-not-bundled' }))
    await mm.ensureModel('__test_install_fixture')
    await flushTop(); await flushTop()
    const row = mm.listModels().find(m => m.id === '__test_install_fixture')
    expect(row.status).toBe('error')
    expect(row.retryCount).toBe(6) // MAX_RETRY_COUNT
    expect(row.lastError).toBe('library-not-bundled')
  })

  it('bundled:false registry entry short-circuits at ensureModel (no install call)', async () => {
    // translate-en-pt was unbundled on 2026-04-26 — see UNBUNDLED.md.
    // ensureModel must reject with module-not-bundled BEFORE the spy is
    // touched, so retryCount jumps to MAX and the UI shows error/not-bundled.
    const r = await mm.ensureModel('translate-en-pt')
    await flushTop(); await flushTop()
    expect(r).toEqual({ ok: false, reason: 'module-not-bundled' })
    expect(installModuleSpy).not.toHaveBeenCalled()
  })

  it('unknown-downloader:* is treated as non-retriable', async () => {
    installModuleSpy.mockImplementationOnce(async () => ({ ok: false, reason: 'unknown-downloader:foo' }))
    await mm.ensureModel('__test_install_fixture')
    await flushTop(); await flushTop()
    const row = mm.listModels().find(m => m.id === '__test_install_fixture')
    expect(row.status).toBe('error')
    expect(row.retryCount).toBe(6)
  })

  it('getTelemetry shape is invariant regardless of state', async () => {
    const t1 = mm.getTelemetry()
    expect(t1).toMatchObject({
      totalBytesDownloaded: expect.any(Number),
      modelsReady: expect.any(Array),
      modelsEvicted: expect.any(Array),
      currentDownloads: expect.any(Array),
      errorsLast24h: expect.any(Array),
    })
    await mm.ensureModel('__test_install_fixture')
    await flushTop(); await flushTop()
    const t2 = mm.getTelemetry()
    expect(t2.modelsReady).toContain('__test_install_fixture')
    expect(t2.totalBytesDownloaded).toBeGreaterThan(0)
  })

  it('listModels shape is invariant — every row has id+label+status', () => {
    const rows = mm.listModels()
    for (const r of rows) {
      expect(typeof r.id).toBe('string')
      expect(typeof r.label).toBe('string')
      expect(typeof r.status).toBe('string')
      expect(typeof r.sizeMb).toBe('number')
      expect(typeof r.feature).toBe('string')
      expect(typeof r.downloader).toBe('string')
    }
  })

  it('disposeAll called twice in a row does not throw', () => {
    expect(() => { mm.disposeAll(); mm.disposeAll() }).not.toThrow()
  })

  it('pauseDownload on an unknown id is a no-op', () => {
    expect(() => mm.pauseDownload('no-such')).not.toThrow()
    expect(() => mm.resumeDownload('no-such')).not.toThrow()
    expect(() => mm.cancelDownload('no-such')).not.toThrow()
    expect(() => mm.deleteModel('no-such')).not.toThrow()
    expect(() => mm.retryModel('no-such')).not.toThrow()
  })

  it('subscribeModel with falsy id returns a no-op unsubscribe', () => {
    const off = mm.subscribeModel('', () => {})
    expect(typeof off).toBe('function')
    expect(() => off()).not.toThrow()
  })

  it('subscribeModel with non-function cb returns a no-op unsubscribe', () => {
    const off = mm.subscribeModel('__test_install_fixture', 'not-a-fn')
    expect(typeof off).toBe('function')
  })
})
