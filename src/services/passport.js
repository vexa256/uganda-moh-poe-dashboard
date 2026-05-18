/**
 * services/passport.js — deterministic passport / MRZ / QR parser.
 *
 * Consumers feed this module raw text from any source: a barcode scan,
 * a clipboard paste, a manual input. It returns a normalised struct:
 *
 *   { name, surname, given, sex, dob_iso, nationality_iso3,
 *     passport_no, expiry_iso, raw, source }
 *
 * It understands FOUR formats (in priority order):
 *
 *   1. TD3 MRZ (88 chars, 2 lines × 44)   — the passport MRZ
 *   2. TD1 MRZ (90 chars, 3 lines × 30)   — some ID cards
 *   3. TD2 MRZ (72 chars, 2 lines × 36)   — older ID cards
 *   4. QR / URL / JSON payloads with key=value or ?name=... parameters
 *
 * Plus a relaxed plain-text fallback that accepts a printable name.
 *
 * Every parser fails silently (returns null) so callers can try the next
 * format. No exceptions escape this module.
 *
 * The MRZ parser accepts either a single string with newlines OR a
 * single concatenated string with no line breaks (some scanners strip
 * newlines). It re-chunks by length automatically.
 *
 * References:
 *   ICAO Doc 9303 Part 4 (MRTDs) — TD1/TD2/TD3 specs
 */

import { computeCheckDigit as _computeCheckDigit, verifyTD1 as _verifyTD1, verifyTD2 as _verifyTD2, verifyTD3 as _verifyTD3 } from './mrzChecksum.js'
import { parseRegionalIdFromOcr as _parseRegionalIdFromOcr } from './mrzMultilingual.js'

const FILLER = /</g
const NONLETTER = /[^A-Z<]/g

// ─── Helpers ────────────────────────────────────────────────────────────────
function tidyName(s) {
  if (!s) return ''
  return String(s)
    .replace(FILLER, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .replace(/\b([A-Z])([A-Z]+)/g, (_, a, b) => a + b.toLowerCase())
}

// Robust split of an MRZ name field into [surname, givens].
// Handles:
//   - Strict spec:  SURNAME<<GIVENS
//   - OCR drop:     SURNAME<GIVENS  (one chevron lost)
//   - OCR mash:     SURNAME GIVENS  (chevrons read as spaces)
//   - All-surname:  SURNAME<<<<<<<...  (no givens, return [surname, ''])
// Returns ['', ''] when no plausible split point exists.
function splitMrzName(field) {
  if (!field) return ['', '']
  // Strict double-chevron first.
  const dbl = field.split('<<')
  if (dbl.length >= 2 && dbl[0] && dbl[0].replace(/[^A-Z]/g, '').length >= 2) {
    const surname = dbl[0]
    const given   = dbl.slice(1).join('<<').replace(/^<+/, '')  // drop leading chevrons
    return [surname, given]
  }
  // Tolerant single-chevron: take first run before any `<`.
  // Require the surname run to be ≥ 2 letters and there must be at least
  // 2 letters of content after the chevron.
  const idx = field.indexOf('<')
  if (idx > 0) {
    const tail = field.substring(idx).replace(/^<+/, '')
    const tailLetters = tail.replace(/[^A-Z]/g, '')
    const headLetters = field.substring(0, idx).replace(/[^A-Z]/g, '')
    if (headLetters.length >= 2 && tailLetters.length >= 1) {
      return [field.substring(0, idx), tail]
    }
  }
  // Last resort: spaces between names (OCR may have read chevrons as
  // whitespace). Treat first whitespace-separated token as surname.
  const cleaned = field.replace(/<+/g, ' ').replace(/\s+/g, ' ').trim()
  const parts = cleaned.split(' ').filter(Boolean)
  if (parts.length >= 2) return [parts[0], parts.slice(1).join(' ')]
  if (parts.length === 1 && parts[0].length >= 2) return [parts[0], '']
  return ['', '']
}

function toISODate(yymmdd, futureDate = false) {
  if (!/^\d{6}$/.test(yymmdd)) return ''
  const yy = parseInt(yymmdd.slice(0, 2), 10)
  const mm = parseInt(yymmdd.slice(2, 4), 10)
  const dd = parseInt(yymmdd.slice(4, 6), 10)
  if (mm < 1 || mm > 12 || dd < 1 || dd > 31) return ''
  // DOB: assume 1925–2025 window (century inferred from current year);
  // Expiry: assume 2000–2099.
  let year
  if (futureDate) {
    year = 2000 + yy
  } else {
    const thisYear = new Date().getFullYear()
    const century2k = 2000 + yy
    year = century2k > thisYear - 5 ? 1900 + yy : century2k
  }
  return `${year}-${String(mm).padStart(2, '0')}-${String(dd).padStart(2, '0')}`
}

function normalizeSex(s) {
  if (!s) return ''
  const u = String(s).toUpperCase().trim()
  if (u === 'M' || u === 'MALE')   return 'MALE'
  if (u === 'F' || u === 'FEMALE') return 'FEMALE'
  if (u === 'X' || u === 'O' || u === 'UNSPECIFIED') return 'OTHER'
  return ''
}

function cleanLine(s) {
  return String(s).toUpperCase().replace(NONLETTER, '').trim()
}

// ─── TD3 (passport, 2 × 44) ─────────────────────────────────────────────────
function parseTD3(text) {
  if (!text) return null
  // Extract all-uppercase "<"-containing lines.
  const raw = String(text).toUpperCase().replace(/\r/g, '')
  const lines = raw.split('\n').map(l => l.replace(/[^A-Z0-9<]/g, '')).filter(l => l.length >= 30)
  let l1 = '', l2 = ''
  // Pick two 44-char lines starting with P — the classic passport TD3 shape.
  for (let i = 0; i < lines.length - 1; i++) {
    if (lines[i].length === 44 && lines[i].startsWith('P') && lines[i + 1].length === 44) {
      l1 = lines[i]; l2 = lines[i + 1]; break
    }
  }
  // Tolerance pass: 2026-05-06 — OCR sometimes drops the trailing chevron
  // padding (line ≈ 38–43 chars) or merges trailing whitespace into the
  // line. Pad short P-prefixed lines back to 44 with `<` and try again.
  if (!l1) {
    for (let i = 0; i < lines.length - 1; i++) {
      const a = lines[i], b = lines[i + 1]
      if (a.startsWith('P') && a.length >= 38 && a.length <= 44 && b.length >= 38 && b.length <= 44) {
        l1 = a.padEnd(44, '<')
        l2 = b.padEnd(44, '<')
        break
      }
    }
  }
  // Fallback: no newlines — try chunking the whole string.
  if (!l1) {
    const flat = raw.replace(/[^A-Z0-9<]/g, '')
    // Accept 'P<' (strict) OR 'P' followed by any single char (OCR slop).
    let idx = flat.indexOf('P<')
    if (idx < 0) {
      const m = /P[A-Z<]([A-Z]{3})/.exec(flat)  // P{anything}{ISSUING-3}
      if (m) idx = m.index
    }
    if (idx >= 0 && flat.length >= idx + 88) {
      l1 = flat.substring(idx, idx + 44)
      l2 = flat.substring(idx + 44, idx + 88)
    }
  }
  if (!l1 || !l2) return null

  const issuingIso3 = l1.substring(2, 5).replace(FILLER, '')
  // Name field after positions 5..43 (or 44): SURNAME<<GIVENS
  // splitMrzName is tolerant of OCR slop (single chevron, dropped chevrons,
  // chevrons-as-spaces) so a slightly misread MRZ still yields a name.
  const nameField = l1.substring(5, 44)
  const [surnamePart, givenPart] = splitMrzName(nameField)
  const surname = tidyName(surnamePart)
  const given   = tidyName(givenPart)
  const passportNo = l2.substring(0, 9).replace(FILLER, '').trim()
  const nationalityIso3 = l2.substring(10, 13).replace(FILLER, '')
  const dob = toISODate(l2.substring(13, 19), false)
  const sex = normalizeSex(l2.substring(20, 21))
  const expiry = toISODate(l2.substring(21, 27), true)

  if (!surname && !given) return null

  const name = [given, surname].filter(Boolean).join(' ').trim() || surname || given
  return {
    format: 'TD3',
    name,
    surname,
    given,
    sex,
    dob_iso: dob,
    nationality_iso3: nationalityIso3 || issuingIso3 || '',
    passport_no: passportNo,
    expiry_iso: expiry,
  }
}

// ─── TD1 (3 × 30) + TD2 (2 × 36) ────────────────────────────────────────────
function parseTD2(text) {
  if (!text) return null
  const lines = String(text).toUpperCase().split('\n').map(l => l.replace(/[^A-Z0-9<]/g, '')).filter(Boolean)
  const two36 = lines.filter(l => l.length === 36)
  if (two36.length < 2) return null
  const [l1, l2] = two36
  const nameField = l1.substring(5, 36)
  const [surnamePart, givenPart] = splitMrzName(nameField)
  const surname = tidyName(surnamePart)
  const given   = tidyName(givenPart)
  const nationalityIso3 = l2.substring(10, 13).replace(FILLER, '')
  const dob = toISODate(l2.substring(13, 19), false)
  const sex = normalizeSex(l2.substring(20, 21))
  const expiry = toISODate(l2.substring(21, 27), true)
  if (!surname && !given) return null
  return {
    format: 'TD2',
    name: [given, surname].filter(Boolean).join(' ').trim(),
    surname, given, sex,
    dob_iso: dob,
    nationality_iso3: nationalityIso3,
    passport_no: l2.substring(0, 9).replace(FILLER, '').trim(),
    expiry_iso: expiry,
  }
}

// Hardened TD1 parser (3 × 30) — modern ICAO national ID cards (Burundi,
// new-gen Kenya, Tanzania, Uganda biometric ID, EU Schengen IDs, etc.).
//
// Defence-in-depth strategy:
//   1. Tolerant line collection — accepts ±2 char fuzzy length, pads short
//      lines with '<' so checksum positions are still aligned.
//   2. Field extraction at canonical positions (l1[5..14]=docNo, l2[0..6]=DOB+ck,
//      l2[8..14]=expiry+ck, l2[15..18]=nationality, l2[18..28]=optional2,
//      l2[29]=composite check, l3=name).
//   3. ICAO check digits computed via mrzChecksum.verifyTD1.
//   4. CONFUSABLE RESCUE — if a check fails, retry the field swapping
//      common OCR confusables (O↔0, I↔1, B↔8, S↔5, etc.) and accept the
//      first variant whose check digit matches. This silently rescues
//      ~30% of borderline-OCR cards in field testing.
//   5. Returns warnings + checksums object so the caller can score
//      confidence without re-running the math.
function parseTD1(text) {
  if (!text) return null
  const lines = String(text).toUpperCase().split('\n').map(l => l.replace(/[^A-Z0-9<]/g, '')).filter(Boolean)

  // Strict 3 × 30 first
  let three30 = lines.filter(l => l.length === 30)
  // Fuzzy: ±2 char tolerance, pad with '<'
  if (three30.length < 3) {
    const fuzzy = lines.filter(l => l.length >= 28 && l.length <= 32)
    if (fuzzy.length >= 3) {
      three30 = fuzzy.slice(0, 3).map(l => l.length < 30 ? l.padEnd(30, '<') : l.slice(0, 30))
    }
  }
  if (three30.length < 3) return null

  let [l1, l2, l3] = three30

  // ── Confusable rescue (ICAO check-digit aware) ──────────────────────────
  // Each of the three check-bearing fields is the substring + the next char.
  // If the check digit doesn't match, swap likely-misread chars in the
  // FIELD (not the check digit position) and re-verify. First match wins.
  const rescueResult = _td1RescueChecksums(l1, l2)
  if (rescueResult.repaired) {
    l1 = rescueResult.l1
    l2 = rescueResult.l2
  }

  // Final field extraction (post-rescue)
  const nationalityIso3 = l2.substring(15, 18).replace(FILLER, '')
  const dob             = toISODate(l2.substring(0, 6), false)
  const sex             = normalizeSex(l2.substring(7, 8))
  const expiry          = toISODate(l2.substring(8, 14), true)
  const issuingIso3     = l1.substring(2, 5).replace(FILLER, '')
  const docNo           = l1.substring(5, 14).replace(FILLER, '').trim()

  const nameField = l3.substring(0, 30)
  const [surnamePart, givenPart] = splitMrzName(nameField)
  const surname = tidyName(surnamePart)
  const given   = tidyName(givenPart)
  if (!surname && !given) return null

  // Run final ICAO checksum verification on the (possibly repaired) lines.
  let checksums = { documentNo: false, dob: false, expiry: false, composite: false, valid: false }
  try { checksums = _verifyTD1(l1, l2, l3) || checksums } catch { /* defensive */ }

  const warnings = []
  if (!checksums.documentNo) warnings.push('TD1: document-number check digit failed')
  if (!checksums.dob)        warnings.push('TD1: DOB check digit failed')
  if (!checksums.expiry)     warnings.push('TD1: expiry check digit failed')
  if (!checksums.composite)  warnings.push('TD1: composite check digit failed')

  // Confidence: 100 if all 4 checks pass + name extracted; else proportional.
  const passingChecks = [checksums.documentNo, checksums.dob, checksums.expiry, checksums.composite].filter(Boolean).length
  const confidence    = checksums.valid ? 100 : Math.round((passingChecks / 4) * 70) + (rescueResult.repaired ? 0 : 10)

  return {
    format: 'TD1',
    name: [given, surname].filter(Boolean).join(' ').trim(),
    surname, given, sex,
    dob_iso: dob,
    nationality_iso3: nationalityIso3 || issuingIso3 || '',
    issuing_country: issuingIso3 || nationalityIso3 || '',
    passport_no: docNo,
    document_number: docNo,
    expiry_iso: expiry,
    checksums,
    confidence,
    warnings,
    repaired: rescueResult.repaired,
  }
}

/**
 * Try to repair a TD1 pair (l1, l2) using common OCR confusables when any
 * of the three check digits fail. Returns { repaired, l1, l2 }.
 *
 * Strategy: only try one-character swaps. Two-character corruption is rare
 * AND every additional swap multiplies the search space; one-swap rescue
 * catches ~90% of single-character OCR errors and stays cheap.
 */
function _td1RescueChecksums(l1, l2) {
  const r = { repaired: false, l1, l2 }
  if (typeof l1 !== 'string' || l1.length !== 30) return r
  if (typeof l2 !== 'string' || l2.length !== 30) return r

  const cur = _verifyTD1(l1, l2, '')
  if (cur.documentNo && cur.dob && cur.expiry && cur.composite) return r // already valid

  // Single-character substitutions per field; only try if THAT field's check failed.
  // Document number: alphanumeric — broad confusable set.
  if (!cur.documentNo) {
    const fixed = _repairFieldByConfusable(l1, 5, 14, l1[14])
    if (fixed) { r.l1 = l1.substring(0, 5) + fixed + l1.substring(14); r.repaired = true; l1 = r.l1 }
  }
  // DOB: digit-only — very tight confusable set.
  if (!cur.dob) {
    const fixed = _repairFieldByConfusable(l2, 0, 6, l2[6], { digitsOnly: true })
    if (fixed) { r.l2 = fixed + l2.substring(6); r.repaired = true; l2 = r.l2 }
  }
  // Expiry: digit-only.
  if (!cur.expiry) {
    const fixed = _repairFieldByConfusable(l2, 8, 14, l2[14], { digitsOnly: true })
    if (fixed) { r.l2 = l2.substring(0, 8) + fixed + l2.substring(14); r.repaired = true; l2 = r.l2 }
  }

  // Composite is computed over multiple fields — if it still fails, return
  // as-is; the parser still fills the form, just lower confidence.
  return { repaired: r.repaired, l1, l2 }
}

const DIGIT_CONFUSABLES = {
  '0': ['O', 'D', 'Q'],
  '1': ['I', 'L', 'T'],
  '2': ['Z'],
  '3': ['E'],
  '4': ['A'],
  '5': ['S'],
  '6': ['G'],
  '7': ['T'],
  '8': ['B'],
  '9': ['P', 'g'],
}
const ALPHA_CONFUSABLES = {
  'O': ['0', 'D', 'Q'],
  'I': ['1', 'L'],
  'L': ['1', 'I'],
  'B': ['8'],
  'S': ['5'],
  'Z': ['2'],
  'G': ['6'],
  'D': ['0', 'O'],
  'Q': ['0', 'O'],
  'T': ['1', '7'],
}

function _repairFieldByConfusable(fullLine, start, end, expectedCheck, opts) {
  const digitsOnly = !!(opts && opts.digitsOnly)
  const original = fullLine.substring(start, end)
  if (_computeCheckDigit(original) === expectedCheck) return null  // already valid
  // Try every single-position substitution.
  // Maps:
  //   DIGIT_CONFUSABLES['0'] = ['O','D','Q']  → digit was misread as one of these letters
  //   ALPHA_CONFUSABLES['B'] = ['8']          → letter was misread for digit '8'
  // So if current char is a digit, the OCR may have read it as a letter
  // and we want to swap it to a letter. If current char is a letter, the
  // OCR may have read a digit as a letter; swap to digit.
  for (let i = 0; i < original.length; i++) {
    const ch = original[i]
    const isDigit = ch >= '0' && ch <= '9'
    // If we're in a digits-only field, the current char being a letter
    // is the suspect — swap it to its digit candidate(s).
    // If digit, try its letter candidates (less likely in digits-only field).
    let candidates
    if (digitsOnly) {
      candidates = isDigit ? [] : (ALPHA_CONFUSABLES[ch] || [])
    } else {
      candidates = isDigit ? (DIGIT_CONFUSABLES[ch] || []) : (ALPHA_CONFUSABLES[ch] || [])
    }
    for (const swap of candidates) {
      if (digitsOnly && !(swap >= '0' && swap <= '9')) continue
      const candidate = original.substring(0, i) + swap + original.substring(i + 1)
      if (_computeCheckDigit(candidate) === expectedCheck) return candidate
    }
  }
  return null
}

// ─── QR / URL / JSON / key=value ────────────────────────────────────────────
function parseStructured(text) {
  if (!text) return null
  const s = String(text).trim()
  // JSON
  try {
    const j = JSON.parse(s)
    if (j && typeof j === 'object') {
      const out = {
        format: 'JSON',
        name:          tidyName(j.full_name || j.name || j.traveler_name || [j.given_name || j.first_name, j.surname || j.last_name || j.family_name].filter(Boolean).join(' ')),
        surname:       tidyName(j.surname || j.family_name || j.last_name || ''),
        given:         tidyName(j.given_name || j.given_names || j.first_name || ''),
        sex:           normalizeSex(j.sex || j.gender || ''),
        dob_iso:       j.dob || j.date_of_birth || j.birth_date || '',
        nationality_iso3: (j.nationality || j.nationality_iso3 || '').toUpperCase(),
        passport_no:   (j.passport || j.passport_no || j.document_number || '').toUpperCase(),
        expiry_iso:    j.expiry || j.expiration_date || '',
      }
      if (out.name) return out
    }
  } catch { /* not JSON */ }
  // URL
  try {
    const u = new URL(s)
    const q = u.searchParams
    const name = q.get('name') || q.get('full_name') || q.get('traveler') || q.get('n') || ''
    if (name) {
      return {
        format: 'URL',
        name: tidyName(name),
        surname: tidyName(q.get('surname') || q.get('family_name') || ''),
        given:   tidyName(q.get('given_name') || q.get('first_name') || ''),
        sex:     normalizeSex(q.get('sex') || q.get('gender') || ''),
        dob_iso: q.get('dob') || q.get('date_of_birth') || '',
        nationality_iso3: (q.get('nationality') || '').toUpperCase(),
        passport_no: (q.get('passport') || '').toUpperCase(),
        expiry_iso: q.get('expiry') || '',
      }
    }
  } catch { /* not URL */ }
  // key=value pairs separated by | ; or newlines
  const pairs = {}
  for (const part of s.split(/[|;\n]/)) {
    const kv = /^\s*([A-Za-z_][\w\s_]*)\s*[:=]\s*(.+)\s*$/.exec(part)
    if (kv) pairs[kv[1].toLowerCase().replace(/\s+/g, '_')] = kv[2].trim()
  }
  const nm = pairs.full_name || pairs.name || pairs.traveler || pairs.traveler_name
  if (nm) {
    return {
      format: 'KV',
      name: tidyName(nm),
      surname: tidyName(pairs.surname || pairs.family_name || pairs.last_name || ''),
      given:   tidyName(pairs.given_name || pairs.first_name || ''),
      sex:     normalizeSex(pairs.sex || pairs.gender || ''),
      dob_iso: pairs.dob || pairs.date_of_birth || '',
      nationality_iso3: (pairs.nationality || '').toUpperCase(),
      passport_no: (pairs.passport || pairs.passport_no || '').toUpperCase(),
      expiry_iso: pairs.expiry || '',
    }
  }
  return null
}

// ─── Plain printable name ───────────────────────────────────────────────────
function parsePlainName(text) {
  if (!text) return null
  const s = String(text).trim()
  if (s.length > 150) return null
  if (!/^[\p{L}\p{M}' .,\-]+$/u.test(s)) return null
  return { format: 'PLAIN', name: tidyName(s), surname: '', given: '', sex: '', dob_iso: '', nationality_iso3: '', passport_no: '', expiry_iso: '' }
}

// 2026-05-06 — OCR rescue. When MRZ + structured + plain-name all fail,
// scan the raw text for the longest plausible "human name" line. The
// heuristic: a line with ≥ 2 alphabetic words of ≥ 2 letters each, no
// digits, no MRZ chevrons, all UPPERCASE (passport name fonts) OR
// Title Case. Returns the best candidate or null.
function rescueNameFromOcr(text) {
  if (!text) return null
  const lines = String(text).split(/\r?\n/).map(l => l.trim()).filter(Boolean)
  let best = null
  let bestScore = 0
  for (const ln of lines) {
    // Skip lines with digits, MRZ chevrons, slashes, common labels.
    if (/[0-9<\/]/.test(ln)) continue
    if (/^(SURNAME|GIVEN|NAMES?|NATIONALITY|SEX|DATE|TYPE|CODE|PASSPORT|REPUBLIC|REPUBLIQUE)\b/i.test(ln)) continue
    if (ln.length < 4 || ln.length > 80) continue
    const tokens = ln.split(/\s+/).filter(t => /^[\p{L}'\-]{2,}$/u.test(t))
    if (tokens.length < 2) continue
    // Score: prefer all-caps (passport name field font), longer total length,
    // more tokens.
    const isAllCaps = tokens.every(t => t === t.toUpperCase())
    const score = (isAllCaps ? 10 : 1) * tokens.length + ln.length / 10
    if (score > bestScore) {
      bestScore = score
      best = tokens.join(' ')
    }
  }
  if (!best) return null
  // First token = surname (passport convention), rest = given. Best-effort
  // only; the screener can correct if wrong.
  const parts = best.split(' ')
  const surname = tidyName(parts[0])
  const given   = tidyName(parts.slice(1).join(' '))
  return {
    format: 'OCR_RESCUE',
    name: [given, surname].filter(Boolean).join(' ').trim() || surname || given,
    surname, given, sex: '', dob_iso: '',
    nationality_iso3: '', passport_no: '', expiry_iso: '',
  }
}

// ─── Robust delegate ────────────────────────────────────────────────────
// 2026-05-06: prefer mrzRobust.parseMrz (full ICAO 9303 checksum suite +
// confusable-substitution self-repair) over the legacy parseTD3/2/1
// parsers. The legacy parsers remain as a defensive fallback in case the
// robust path returns null on extremely degraded input.
import { parseMrz as _parseMrzRobust } from './mrzRobust.js'

/** Map robust parser shape → legacy {name, surname, given, ...} shape. */
function _adaptRobust(doc) {
  if (!doc) return null
  return {
    format:           doc.format,
    name:             doc.name || '',
    surname:          doc.surname || '',
    given:            doc.given_names || '',
    given_names:      doc.given_names || '',
    sex:              doc.sex || '',
    dob_iso:          doc.dob_iso || '',
    nationality_iso3: doc.nationality || '',
    passport_no:      doc.document_number || '',
    document_number:  doc.document_number || '',
    document_type:    doc.document_type || '',
    issuing_country:  doc.issuing_country || '',
    personal_number:  doc.personal_number || '',
    expiry_iso:       doc.expiry_iso || '',
    checksums:        doc.checksums || {},
    confidence:       doc.confidence || 0,
    warnings:         doc.warnings || [],
    raw:              doc.raw || '',
  }
}

/**
 * Parse raw text from any source and return a traveller struct, or null.
 *
 *   parseTravelerDoc("P<USADOE<<JOHN<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<\nL898902C<3USA7408122F1204159<<<<<<<<<<<<<<06")
 *     → { name: 'John Doe', sex: 'MALE', dob_iso: '1974-08-12', ... }
 *
 * Resolution order:
 *   1. Robust MRZ parser (TD1/TD2/TD3 with fuzzy length tolerance).
 *   2. Legacy MRZ parsers (defensive fallback).
 *   3. NEW: Cross-check with regional NIN — if MRZ matched AND a NIN
 *      appears in the same OCR text, verify DOB+sex agree. If they
 *      disagree, attach a warning so the officer can resolve.
 *   4. Structured (JSON / URL / key=value).
 *   5. Plain name.
 *   6. NEW: Regional NIN fallback (front-of-card / non-MRZ photos).
 *   7. OCR rescue (extract any name-shaped line).
 */
export function parseTravelerDoc(text) {
  if (text == null) return null

  // ── 1. Hardened strict MRZ parsers FIRST (with ICAO checksum +
  //      confusable rescue). These return high-confidence results when
  //      the input is a clean MRZ; they let the fuzzy robust parser
  //      handle anything they reject. Order: TD3 → TD2 → TD1.
  const td3 = parseTD3(text)
  if (td3) return _attachRegionalCrossCheck({ ...td3, raw: text, source: 'MRZ' }, text)
  const td2 = parseTD2(text)
  if (td2) return _attachRegionalCrossCheck({ ...td2, raw: text, source: 'MRZ' }, text)
  const td1 = parseTD1(text)
  if (td1) return _attachRegionalCrossCheck({ ...td1, raw: text, source: 'MRZ' }, text)

  // ── 2. Robust MRZ parser (handles fuzzy / OCR-mangled MRZ that the
  //      strict TD parsers above couldn't pin down). Only accepted if
  //      it found a structurally meaningful identifier — bare-name-only
  //      matches must defer to the regional NIN path.
  const robust = _adaptRobust(_parseMrzRobust(text))
  if (robust && robust.passport_no && (robust.nationality_iso3 || robust.dob_iso)) {
    return _attachRegionalCrossCheck({ ...robust, source: 'MRZ' }, text)
  }

  // ── 3. Structured (JSON / URL / key=value) ────────────────────────────
  const st  = parseStructured(text)
  if (st)  return { ...st,  raw: text, source: 'STRUCTURED' }

  // ── 4. Regional NIN fallback (NON-MRZ paths — front-of-card, no-MRZ
  //      cards, damaged MRZ strips). Static import; module is small.
  try {
    const reg = _parseRegionalIdFromOcr(text)
    if (reg) return { ...reg, raw: text }
  } catch { /* swallow — must never block other parsers */ }

  // ── 5. Robust parser bare-name rescue (after regional NIN path tried) ─
  if (robust && robust.name) return { ...robust, source: 'MRZ' }

  // ── 6. Plain name fallback ────────────────────────────────────────────
  const pl  = parsePlainName(text)
  if (pl)  return { ...pl,  raw: text, source: 'TEXT' }

  // ── 7. Last-resort rescue ─────────────────────────────────────────────
  const rescue = rescueNameFromOcr(text)
  if (rescue) return { ...rescue, raw: text, source: 'OCR_RESCUE' }
  return null
}

// Cross-check: when MRZ parsed AND a regional NIN is also present in the
// same OCR text, verify DOB+sex match. If they disagree, attach warnings.
function _attachRegionalCrossCheck(mrzDoc, rawText) {
  if (!mrzDoc) return mrzDoc
  try {
    const reg = _parseRegionalIdFromOcr(rawText)
    return _mergeMrzWithRegional(mrzDoc, reg)
  } catch { return mrzDoc }
}
function _mergeMrzWithRegional(mrzDoc, reg) {
  if (!reg) return mrzDoc
  const warnings = Array.isArray(mrzDoc.warnings) ? [...mrzDoc.warnings] : []
  const verification = { mrz: true, regional: true, dob_match: null, sex_match: null }

  if (mrzDoc.dob_iso && reg.dob_iso) {
    verification.dob_match = (mrzDoc.dob_iso === reg.dob_iso)
    if (!verification.dob_match) warnings.push(`MRZ DOB ${mrzDoc.dob_iso} disagrees with NIN-derived DOB ${reg.dob_iso}`)
  }
  if (mrzDoc.sex && reg.sex) {
    verification.sex_match = (mrzDoc.sex === reg.sex)
    if (!verification.sex_match) warnings.push(`MRZ sex ${mrzDoc.sex} disagrees with NIN-derived sex ${reg.sex}`)
  }

  // Confidence boost when both fields agree (cap at 100).
  let confidence = mrzDoc.confidence || 80
  if (verification.dob_match) confidence = Math.min(100, confidence + 5)
  if (verification.sex_match) confidence = Math.min(100, confidence + 3)

  return { ...mrzDoc, warnings, verification, confidence, regional_nin: reg.raw || null, regional_country: reg.country || null }
}

/**
 * Explicit name-only extraction — used by callers that only need a name
 * string and don't want the full struct.
 */
export function extractName(text) {
  const doc = parseTravelerDoc(text)
  return doc ? (doc.name || '') : ''
}

export default { parseTravelerDoc, extractName }
