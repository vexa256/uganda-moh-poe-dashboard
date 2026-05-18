/**
 * services/passportOcr.js — robust MRZ extractor for OCR output.
 *
 * Hardening contract (2026-04-26)
 * --------------------------------------------------------------------------
 * Every export in this file is non-throwing. Even with hostile/malformed
 * input (null, empty arrays, gigantic strings, unicode garbage, lines that
 * only LOOK like MRZ) the function returns a structured result and never
 * propagates an exception. The Vue layer relies on this — it passes raw
 * OCR output straight in.
 *
 * Coverage matrix
 *   - TD3 (passport, 2 × 44)              — every modern passport
 *   - TD2 (ID-2, 2 × 36)                  — older travel documents
 *   - TD1 (ID-1, 3 × 30)                  — modern card-style IDs
 *   - MRV-A (long visa, 2 × 44, V<…)      — visa stickers (long form)
 *   - MRV-B (short visa, 2 × 36, V<…)     — visa stickers (short form)
 *
 * Multi-strategy extraction
 *   1. Per-line scoring with OCR-confusion repair, then take top-N lines
 *      and reorder them by document position for the parser.
 *   2. Concatenated-MRZ fallback: when OCR returns one long line because
 *      it didn't separate the rows, slice into canonical lengths
 *      (44/44, 36/36, 30/30/30) at every offset and pick the slice that
 *      scores highest as a complete MRZ.
 *   3. Sliding window: search within long lines for an embedded MRZ run
 *      (handles OCR concatenating stamps + MRZ on one line).
 *   4. Fuzzy line lengths: accept ±2 chars on each canonical length to
 *      tolerate OCR truncation on the trailing chevron run.
 *   5. Tail/head padding: short MRZ lines (truncated trailing chevrons)
 *      get padded with '<' to canonical length so the parser sees the
 *      correct positions.
 *
 * The final result is fed into services/passport.js — the same code path
 * the manual paste flow uses. Even if our extraction is imperfect, the
 * parser's name/sex/dob fields will surface whatever is recognisable.
 */

import { parseTravelerDoc } from './passport'

// Canonical MRZ line lengths and their fuzzy tolerance.
const CANONICAL_LENGTHS = [44, 36, 30]
const LENGTH_TOLERANCE = 2  // accept 42-46, 34-38, 28-32

// Total MRZ lengths (concat) per format. Used for the sliding-window pass.
const FORMAT_TOTAL = Object.freeze({
  TD3:    88,   // 2 × 44 — passport
  TD2:    72,   // 2 × 36
  TD1:    90,   // 3 × 30
  MRV_A:  88,   // visa long (same as TD3)
  MRV_B:  72,   // visa short (same as TD2)
})

// MRZ alphabet pattern.
const MRZ_RE = /^[A-Z0-9<]+$/

// OCR-confusion repair: characters that an OCR engine sometimes returns
// where the MRZ alphabet expects something else. We never strip an
// unknown character — we map only the unambiguous look-alikes.
const OCR_CONFUSIONS = Object.freeze({
  // lowercase → uppercase (Latin OCR sometimes drops case on faint print)
  'a': 'A', 'b': 'B', 'c': 'C', 'd': 'D', 'e': 'E', 'f': 'F', 'g': 'G',
  'h': 'H', 'i': 'I', 'j': 'J', 'k': 'K', 'l': 'L', 'm': 'M', 'n': 'N',
  'o': 'O', 'p': 'P', 'q': 'Q', 'r': 'R', 's': 'S', 't': 'T', 'u': 'U',
  'v': 'V', 'w': 'W', 'x': 'X', 'y': 'Y', 'z': 'Z',
  // chevron lookalikes
  '«': '<', '〈': '<', '＜': '<', '‹': '<', '〈': '<', '<': '<',
  // dashes / hyphens often misrecognised over a chevron run
  '–': '<', '—': '<', '‒': '<', '−': '<', '‐': '<',
  // quotes / apostrophes mid-MRZ
  '"': '<', '‘': '<', '’': '<', '`': '<',
  // accented Latin → bare letter (ICAO 9303 encodes accents as bare; OCR
  // sometimes preserves them when the model is multilingual)
  'À': 'A', 'Á': 'A', 'Â': 'A', 'Ã': 'A', 'Ä': 'A', 'Å': 'A', 'Æ': 'A',
  'Ç': 'C', 'È': 'E', 'É': 'E', 'Ê': 'E', 'Ë': 'E', 'Ì': 'I', 'Í': 'I',
  'Î': 'I', 'Ï': 'I', 'Ñ': 'N', 'Ò': 'O', 'Ó': 'O', 'Ô': 'O', 'Õ': 'O',
  'Ö': 'O', 'Ø': 'O', 'Ù': 'U', 'Ú': 'U', 'Û': 'U', 'Ü': 'U', 'Ý': 'Y',
  'Þ': 'T', 'ß': 'S',
})

// Defensive cap so a runaway input never produces a giant intermediate.
const MAX_INPUT_LENGTH = 50_000
const MAX_LINE_LENGTH  = 500

/**
 * Tighten a single OCR line into MRZ form: strip whitespace, apply
 * confusion table. Never throws. Always returns a string (possibly empty).
 */
export function cleanMrzLine(raw) {
  try {
    if (typeof raw !== 'string') return ''
    let s = raw.length > MAX_LINE_LENGTH ? raw.slice(0, MAX_LINE_LENGTH) : raw
    s = s.replace(/\s+/g, '')
    if (!s) return ''
    let out = ''
    for (const ch of s) {
      out += OCR_CONFUSIONS[ch] ?? ch
    }
    return out
  } catch { return '' }
}

/**
 * Score how MRZ-shaped a cleaned line looks.
 * 0 means "not MRZ"; positive scores let multiple candidates be ranked.
 */
export function mrzShapeScore(line) {
  try {
    if (!line || typeof line !== 'string') return 0
    if (!MRZ_RE.test(line)) return 0
    let score = 0
    const chevrons = (line.match(/</g) || []).length
    if (chevrons >= 2) score += chevrons
    // Canonical length is a strong signal.
    for (const L of CANONICAL_LENGTHS) {
      if (line.length === L) { score += 8; break }
      if (Math.abs(line.length - L) <= LENGTH_TOLERANCE) { score += 4; break }
    }
    // Type designators.
    if (/^P</.test(line))               score += 6   // passport (TD3)
    else if (/^V</.test(line))          score += 4   // visa (MRV-A/B)
    else if (/^[ICA]</.test(line))      score += 4   // TD1 ID card / crew
    return score
  } catch { return 0 }
}

/**
 * Pad / truncate a candidate to the canonical length closest to it,
 * but only when the candidate is within tolerance — otherwise leave it
 * alone so the parser can still try.
 */
function snapToCanonical(line) {
  if (!line) return line
  for (const L of CANONICAL_LENGTHS) {
    if (line.length === L) return line
    if (Math.abs(line.length - L) <= LENGTH_TOLERANCE) {
      if (line.length < L) return line + '<'.repeat(L - line.length)
      return line.slice(0, L)
    }
  }
  return line
}

/**
 * Extract MRZ from OCR `lines` (or single full `text`), returning a
 * concatenated MRZ string ready for parseTravelerDoc plus diagnostics.
 *
 * Strategies, in order:
 *   1. line-based pickN
 *   2. concatenated slicing (when OCR fused rows)
 *   3. sliding-window scan within long lines
 */
export function extractMrz({ lines, text } = {}) {
  try {
    const rawLines = collectLines(lines, text)
    if (!rawLines.length) return { mrz: null, candidates: [], strategy: 'no-input' }

    // Strategy 1 — per-line scoring.
    const scored = rawLines
      .map(l => cleanMrzLine(l))
      .filter(Boolean)
      .map(line => ({ line, score: mrzShapeScore(line) }))
      .filter(x => x.score > 0)

    if (scored.length) {
      // Take top 3 by score, re-order by their original position so the
      // parser sees the canonical line order.
      const top = new Set(scored.slice().sort((a, b) => b.score - a.score).slice(0, 3).map(x => x.line))
      const ordered = []
      for (const raw of rawLines) {
        const cleaned = cleanMrzLine(raw)
        if (top.has(cleaned)) ordered.push(snapToCanonical(cleaned))
      }
      if (ordered.length) {
        return {
          mrz: ordered.join('\n'),
          candidates: scored.map(x => x.line),
          strategy: 'line-pickN',
        }
      }
    }

    // Strategy 2 — concatenated slicing. OCR sometimes fuses both MRZ
    // rows into one long line (no newline between them).
    for (const raw of rawLines) {
      const cleaned = cleanMrzLine(raw)
      if (!cleaned || !MRZ_RE.test(cleaned)) continue
      const sliced = sliceIntoMrz(cleaned)
      if (sliced) return { mrz: sliced, candidates: [cleaned], strategy: 'concat-slice' }
    }

    // Strategy 3 — sliding window over the *concatenated* OCR text. OCR
    // sometimes splits a single MRZ line across multiple text-blocks,
    // glueing chunks of stamps in between.
    const flat = rawLines.map(cleanMrzLine).join('').replace(/[^A-Z0-9<]/g, '')
    if (flat.length >= 60) {
      const sliced = sliceIntoMrz(flat)
      if (sliced) return { mrz: sliced, candidates: [flat], strategy: 'window-flat' }
    }

    return { mrz: null, candidates: [], strategy: 'no-mrz' }
  } catch (err) {
    return { mrz: null, candidates: [], strategy: 'error', error: err?.message || 'unknown' }
  }
}

/** Collect candidate lines from {lines, text}. Always returns an array. */
function collectLines(lines, text) {
  const out = []
  try {
    if (Array.isArray(lines)) {
      for (const l of lines) {
        if (typeof l === 'string' && l.length <= MAX_LINE_LENGTH) out.push(l)
      }
    }
    if (typeof text === 'string' && text.length <= MAX_INPUT_LENGTH) {
      for (const l of text.split(/\r?\n/)) {
        if (l.length <= MAX_LINE_LENGTH) out.push(l)
      }
    }
  } catch { /* defensive */ }
  return out
}

/**
 * Try to slice a flat string into a valid MRZ (TD3, TD2, or TD1) by
 * scanning every offset and scoring the best candidate. Returns the
 * MRZ-shaped string (with newlines) or null.
 */
function sliceIntoMrz(flat) {
  if (!flat || typeof flat !== 'string') return null
  let bestScore = 0
  let best = null

  // Try TD3 / MRV-A: 2 × 44, look for a 'P<' or 'V<' anchor first.
  for (const anchor of ['P<', 'V<']) {
    let idx = 0
    while ((idx = flat.indexOf(anchor, idx)) !== -1) {
      if (idx + 88 <= flat.length) {
        const l1 = flat.slice(idx,      idx + 44)
        const l2 = flat.slice(idx + 44, idx + 88)
        const s = mrzShapeScore(l1) + mrzShapeScore(l2)
        if (s > bestScore) { bestScore = s; best = l1 + '\n' + l2 }
      }
      idx++
    }
  }

  // TD2 / MRV-B: 2 × 36, anchor on 'P<' or 'V<' or 'I<'.
  for (const anchor of ['P<', 'V<', 'I<']) {
    let idx = 0
    while ((idx = flat.indexOf(anchor, idx)) !== -1) {
      if (idx + 72 <= flat.length) {
        const l1 = flat.slice(idx,      idx + 36)
        const l2 = flat.slice(idx + 36, idx + 72)
        const s = mrzShapeScore(l1) + mrzShapeScore(l2)
        if (s > bestScore) { bestScore = s; best = l1 + '\n' + l2 }
      }
      idx++
    }
  }

  // TD1: 3 × 30, anchor on 'I<', 'A<', 'C<'.
  for (const anchor of ['I<', 'A<', 'C<']) {
    let idx = 0
    while ((idx = flat.indexOf(anchor, idx)) !== -1) {
      if (idx + 90 <= flat.length) {
        const l1 = flat.slice(idx,      idx + 30)
        const l2 = flat.slice(idx + 30, idx + 60)
        const l3 = flat.slice(idx + 60, idx + 90)
        const s = mrzShapeScore(l1) + mrzShapeScore(l2) + mrzShapeScore(l3)
        if (s > bestScore) { bestScore = s; best = l1 + '\n' + l2 + '\n' + l3 }
      }
      idx++
    }
  }

  // Threshold: require both halves of the MRZ to be plausible.
  return bestScore >= 12 ? best : null
}

/**
 * One-call interface: take the OCR result from textRecognition, return a
 * parsed traveller. Caller is expected to have already verified
 * ocrResult.ok === true. Always returns a structured result; never throws.
 */
export function parsePassportFromOcr(ocrResult) {
  try {
    if (!ocrResult || ocrResult.ok !== true) {
      return { ok: false, reason: 'no-ocr-result' }
    }
    const { mrz, candidates, strategy } = extractMrz({
      lines: ocrResult.lines,
      text:  ocrResult.text,
    })
    if (!mrz) {
      return {
        ok: false,
        reason: 'no-mrz-found',
        candidates: candidates || [],
        strategy: strategy || 'unknown',
      }
    }
    let doc = null
    try {
      doc = parseTravelerDoc(mrz)
    } catch (err) {
      return {
        ok: false,
        reason: 'parser-throw:' + (err?.message || 'unknown'),
        mrz,
        candidates: candidates || [],
        strategy,
      }
    }
    if (!doc || !doc.name) {
      return {
        ok: false,
        reason: 'parse-failed',
        mrz,
        candidates: candidates || [],
        strategy,
      }
    }
    return { ok: true, mrz, doc, candidates: candidates || [], strategy }
  } catch (err) {
    // Last-resort guard so a defect in our own code never crashes the UI.
    return { ok: false, reason: 'extract-throw:' + (err?.message || 'unknown') }
  }
}
