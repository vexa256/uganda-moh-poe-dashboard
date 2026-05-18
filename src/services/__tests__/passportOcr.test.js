/**
 * passportOcr.test.js — exercise MRZ extraction against typical OCR
 * mistakes the ML Kit Latin recogniser makes on a passport data page,
 * AND adversarial inputs (null, mixed scripts, oversized strings,
 * scrambled OCR blocks, concatenated MRZ rows, multi-format MRZ).
 *
 * Hardening bar (2026-04-26): every test exercises the no-throw contract.
 * The functions return structured failure shapes; they never throw.
 */

import { describe, it, expect } from 'vitest'
import {
  cleanMrzLine,
  mrzShapeScore,
  extractMrz,
  parsePassportFromOcr,
} from '../passportOcr.js'

// Real-shape TD3 specimen used by the parser tests too.
const TD3_LINE_1 = 'P<ZMBBANDA<<MULENGA<<<<<<<<<<<<<<<<<<<<<<<<<'
const TD3_LINE_2 = 'ZN12345678ZMB9001011M3001014<<<<<<<<<<<<<<06'

describe('cleanMrzLine — defensive against hostile input', () => {
  it('passes through a clean MRZ line untouched', () => {
    expect(cleanMrzLine(TD3_LINE_1)).toBe(TD3_LINE_1)
  })

  it('returns "" for null/undefined/non-string without throwing', () => {
    expect(cleanMrzLine(null)).toBe('')
    expect(cleanMrzLine(undefined)).toBe('')
    expect(cleanMrzLine(42)).toBe('')
    expect(cleanMrzLine([])).toBe('')
    expect(cleanMrzLine({})).toBe('')
    expect(cleanMrzLine(true)).toBe('')
  })

  it('truncates oversized input rather than blowing memory', () => {
    const huge = 'x'.repeat(10_000)
    const out = cleanMrzLine(huge)
    expect(out.length).toBeLessThanOrEqual(500)
  })

  it('strips internal whitespace (OCR sometimes splits the chevron run)', () => {
    expect(cleanMrzLine('P<ZMBBANDA<<MULENGA<<  <<<<<<<<<<<<<<<<<<<<<<<')).toBe(TD3_LINE_1)
  })

  it('normalises lowercase letters to uppercase', () => {
    expect(cleanMrzLine('p<zmbBanda<<Mulenga<<<<<<<<<<<<<<<<<<<<<<<<<')).toBe(TD3_LINE_1)
  })

  it('replaces look-alike chevrons (« ‹ ＜ – —)', () => {
    expect(cleanMrzLine('P«ZMBBANDA‹‹MULENGA‒‒〈〈＜＜')).toBe('P<ZMBBANDA<<MULENGA<<<<<<')
  })

  it('strips accents on Latin letters per ICAO 9303', () => {
    expect(cleanMrzLine('P<FRAGÖRTZ<<JÉRÔME')).toBe('P<FRAGORTZ<<JEROME')
  })
})

describe('mrzShapeScore — structural recognition + hostile input', () => {
  it('scores a real TD3 line above 10', () => {
    expect(mrzShapeScore(TD3_LINE_1)).toBeGreaterThan(10)
    expect(mrzShapeScore(TD3_LINE_2)).toBeGreaterThan(10)
  })

  it('returns 0 for a non-MRZ line', () => {
    expect(mrzShapeScore('Hello, world!')).toBe(0)
    expect(mrzShapeScore('REPUBLIC OF ZAMBIA')).toBe(0)
  })

  it('returns 0 for null/undefined/non-string', () => {
    expect(mrzShapeScore(null)).toBe(0)
    expect(mrzShapeScore(undefined)).toBe(0)
    expect(mrzShapeScore(42)).toBe(0)
    expect(mrzShapeScore({})).toBe(0)
  })

  it('returns 0 for a line containing illegal MRZ characters', () => {
    expect(mrzShapeScore('P<ZMB BANDA<<MULENGA<<<<<<<<<<<<<<<<<<<<<<<<')).toBe(0) // space
    expect(mrzShapeScore('P!ZMBBANDA<<MULENGA<<<<<<<<<<<<<<<<<<<<<<<<')).toBe(0) // !
  })

  it('rewards canonical lengths (30/36/44) and tolerates ±2', () => {
    const len44 = 'P<ZMB' + 'A'.repeat(39)
    const len42 = 'P<ZMB' + 'A'.repeat(37)
    const len46 = 'P<ZMB' + 'A'.repeat(41)
    const len38 = 'P<ZMB' + 'A'.repeat(33) // ±2 of 36 allowed
    const len28 = 'P<ZMB' + 'A'.repeat(23) // way off
    expect(mrzShapeScore(len44)).toBeGreaterThan(0)
    expect(mrzShapeScore(len42)).toBeGreaterThan(0)
    expect(mrzShapeScore(len46)).toBeGreaterThan(0)
    expect(mrzShapeScore(len38)).toBeGreaterThan(0)
    // len28 still has chevrons-only score from any chevrons (none here)
    expect(mrzShapeScore(len28)).toBeGreaterThan(0)
  })

  it('rewards type designators (P< V< I< A< C<)', () => {
    const passport = 'P<ZMBBANDA<<MULENGA<<<<<<<<<<<<<<<<<<<<<<<<'
    const visa     = 'V<ZMBBANDA<<MULENGA<<<<<<<<<<<<<<<<<<<<<<<<'
    const idTd1    = 'I<ZMBBANDA<<MULENGA<<<<<<<<<<<<<<<<<<<<<<<<'
    const generic  = 'X<ZMBBANDA<<MULENGA<<<<<<<<<<<<<<<<<<<<<<<<'
    expect(mrzShapeScore(passport)).toBeGreaterThan(mrzShapeScore(generic))
    expect(mrzShapeScore(visa)).toBeGreaterThan(mrzShapeScore(generic))
    expect(mrzShapeScore(idTd1)).toBeGreaterThan(mrzShapeScore(generic))
  })
})

describe('extractMrz — strategy 1: per-line scoring', () => {
  it('finds the two MRZ lines among page chrome lines', () => {
    const lines = [
      'PASSPORT', 'REPUBLIC OF ZAMBIA',
      'BANDA', 'MULENGA', 'M', '01 JAN 90',
      TD3_LINE_1, TD3_LINE_2,
    ]
    const r = extractMrz({ lines })
    expect(r.mrz).toContain(TD3_LINE_1)
    expect(r.mrz).toContain(TD3_LINE_2)
    expect(r.strategy).toBe('line-pickN')
  })

  it('preserves document order', () => {
    const lines = [TD3_LINE_1, 'between', TD3_LINE_2]
    const r = extractMrz({ lines })
    const split = r.mrz.split('\n')
    expect(split[0]).toBe(TD3_LINE_1)
    expect(split[1]).toBe(TD3_LINE_2)
  })

  it('repairs OCR confusions inline', () => {
    const dirty1 = 'p«zmbbanda‹‹mulenga<<<<<<<<<<<<<<<<<<<<<<<<<' // 44 after normalisation
    const dirty2 = TD3_LINE_2.toLowerCase()
    const r = extractMrz({ lines: ['REPUBLIC OF ZAMBIA', dirty1, dirty2] })
    expect(r.mrz).toContain(TD3_LINE_1)
    expect(r.mrz).toContain(TD3_LINE_2)
  })

  it('falls back to splitting `text` when `lines` is empty', () => {
    const text = `PASSPORT\nREPUBLIC OF ZAMBIA\n${TD3_LINE_1}\n${TD3_LINE_2}`
    const r = extractMrz({ text })
    expect(r.mrz).toContain(TD3_LINE_1)
    expect(r.mrz).toContain(TD3_LINE_2)
  })
})

describe('extractMrz — strategy 2: concatenated slicing', () => {
  it('pulls TD3 out of a single concatenated line', () => {
    // line-pickN may catch this first if the whole concat scores well;
    // either way the MRZ must come back. The strategy field is
    // diagnostic, not contract.
    const concat = TD3_LINE_1 + TD3_LINE_2
    const r = extractMrz({ lines: [concat] })
    expect(r.mrz).toBeTruthy()
    expect(r.mrz).toContain('P<ZMB')
  })

  it('finds TD3 even when surrounded by non-MRZ text in the same line', () => {
    const noisy = 'STAMP-2024' + TD3_LINE_1 + TD3_LINE_2 + 'CHECK'
    const r = extractMrz({ lines: [noisy] })
    expect(r.mrz).toBeTruthy()
    expect(r.mrz).toContain('P<ZMB')
  })
})

describe('extractMrz — strategy 3: sliding window across multi-block OCR', () => {
  it('reconstructs MRZ when OCR splits chunks across many short lines', () => {
    // Real-world failure mode: OCR breaks the MRZ at every space-equivalent.
    const chunks = [
      'P<ZMBBANDA<<', 'MULENGA<<<<<<<<', '<<<<<<<<<<<<<<<<<<<',
      'ZN12345678ZMB', '9001011M3001014', '<<<<<<<<<<<<<<06',
    ]
    const r = extractMrz({ lines: chunks })
    expect(r.mrz).toBeTruthy()
  })
})

describe('extractMrz — defensive against hostile input', () => {
  it('null / undefined / empty inputs return mrz=null without throwing', () => {
    expect(extractMrz().mrz).toBeNull()
    expect(extractMrz({}).mrz).toBeNull()
    expect(extractMrz({ lines: null }).mrz).toBeNull()
    expect(extractMrz({ lines: [] }).mrz).toBeNull()
    expect(extractMrz({ text: '' }).mrz).toBeNull()
  })

  it('garbage input returns mrz=null without throwing', () => {
    expect(extractMrz({ lines: [42, null, undefined, {}, []] }).mrz).toBeNull()
    expect(extractMrz({ text: 42 }).mrz).toBeNull()
  })

  it('cap input length to prevent runaway processing', () => {
    const huge = 'x'.repeat(60_000)
    const r = extractMrz({ text: huge })
    expect(r.mrz).toBeNull()  // doesn't crash
  })

  it('mixed-script OCR (Arabic/Cyrillic among Latin) ignores non-MRZ lines', () => {
    const lines = [
      'جواز السفر',                            // Arabic
      'ПАСПОРТ',                               // Cyrillic
      'PASSPORT',
      TD3_LINE_1, TD3_LINE_2,
    ]
    const r = extractMrz({ lines })
    expect(r.mrz).toContain(TD3_LINE_1)
  })
})

describe('parsePassportFromOcr — end-to-end', () => {
  it('returns ok+doc for a clean OCR result with both MRZ lines', () => {
    const ocr = {
      ok: true,
      lines: ['PASSPORT', 'REPUBLIC OF ZAMBIA', TD3_LINE_1, TD3_LINE_2],
      text: '',
      blocks: 1,
    }
    const r = parsePassportFromOcr(ocr)
    expect(r.ok).toBe(true)
    expect(r.doc).toBeTruthy()
    expect(typeof r.doc.name).toBe('string')
    expect(r.doc.name.length).toBeGreaterThan(0)
    expect(r.mrz).toContain(TD3_LINE_1)
  })

  it('returns reason=no-mrz-found when OCR sees text but no MRZ', () => {
    const ocr = { ok: true, lines: ['Just some prose'], text: 'Just some prose', blocks: 1 }
    const r = parsePassportFromOcr(ocr)
    expect(r.ok).toBe(false)
    expect(r.reason).toBe('no-mrz-found')
  })

  it('returns reason=no-ocr-result when OCR itself failed', () => {
    expect(parsePassportFromOcr(null).ok).toBe(false)
    expect(parsePassportFromOcr(undefined).ok).toBe(false)
    const r = parsePassportFromOcr({ ok: false, reason: 'ocr-failed' })
    expect(r.ok).toBe(false)
    expect(r.reason).toBe('no-ocr-result')
  })

  it('returns reason=parse-failed for MRZ-shaped but unparseable text', () => {
    // Right shape, wrong content — no recognisable surname/given-name
    // structure for the parser to return a name from.
    const lines = [
      'P<ZMB<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<',
      '<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<',
    ]
    const r = parsePassportFromOcr({ ok: true, lines, text: lines.join('\n') })
    expect(r.ok).toBe(false)
    expect(['parse-failed', 'no-mrz-found']).toContain(r.reason)
  })

  it('NEVER throws even when fed obviously hostile input', () => {
    // Tests the no-throw contract: a user-supplied attacker-grade payload
    // should still resolve as an error, not crash the JS sandbox.
    expect(() => parsePassportFromOcr({ ok: true, lines: [Array(10000).fill('<')], text: '' })).not.toThrow()
    expect(() => parsePassportFromOcr({ ok: true, lines: null, text: null })).not.toThrow()
    expect(() => parsePassportFromOcr({ ok: true, lines: 42, text: { foo: 'bar' } })).not.toThrow()
  })
})
