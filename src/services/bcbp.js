/**
 * services/bcbp.js — IATA 792 Bar-Coded Boarding Pass (BCBP) M1 parser.
 *
 * Ref: IATA Resolution 792 — Bar Coded Boarding Pass (BCBP) Implementation
 * Guide, version 7. Fixed-width layout starting with "M".
 *
 * This file is the parser ONLY. Camera/scanner integration lives in
 * services/plugins/bcbpScan.js so the parser can be unit-tested without a
 * device. See docs/sentinel-plan/features/10-mlkit-task-apis.md § "why task
 * APIs" for the scope rationale.
 *
 * Contract: pure function, never throws. Returns { ok, data, legs, reason }.
 */

// ─── Layout constants ──────────────────────────────────────────────────────
const MANDATORY_HEADER_LEN = 23     // "M" + "1-4" + 20 name chars  [but spec: 1+1+20 = 22]
// Correction: the mandatory *unique* header is 1(format) + 1(legs) + 20(name)
// + 1(e-ticket indicator) = 23. Keep as 23 matching spec.

const LEG_FIXED_LEN = 37
// Per IATA 792: operating-carrier PNR(7) + from(3) + to(3) + carrier(3)
// + flight#(5) + julian date(3) + compartment(1) + seat(4) + sequence(5)
// + pax status(1) + cond-field-size(2 hex) = 37

// ─── Helpers ───────────────────────────────────────────────────────────────
function slice(s, start, len) { return s.slice(start, start + len) }
function trim(s) { return String(s ?? '').trim() }
function hex2(n) {
  const v = parseInt(n, 16)
  return Number.isFinite(v) ? v : 0
}

/**
 * "SMITH/JOHN MR      " → "John Smith"
 * Conservative: we return "FIRST LAST" upper-cased because the form field
 * advises uppercase entry. Officer can re-case manually if needed.
 */
function normaliseName(raw) {
  const t = trim(raw)
  if (!t) return ''
  const parts = t.split('/')
  if (parts.length === 1) return parts[0]
  const [last, rest] = parts
  // drop common honorifics at end of given-names
  const given = trim(rest).replace(/\b(MR|MRS|MS|MISS|DR|PROF)\.?$/i, '').trim()
  return trim(`${given} ${last}`)
}

/**
 * Julian date "245" → ISO date. BCBP does not carry a year. We infer:
 *   - default to current year
 *   - if the implied date would be > 180 days in the future, assume previous year
 */
function julianToISO(j3, now = new Date()) {
  const j = parseInt(j3, 10)
  if (!Number.isFinite(j) || j < 1 || j > 366) return null
  const year = now.getUTCFullYear()
  const candidate = new Date(Date.UTC(year, 0, 1))
  candidate.setUTCDate(j)
  const diffDays = (candidate.getTime() - now.getTime()) / 86400000
  if (diffDays > 180) {
    candidate.setUTCFullYear(year - 1)
  }
  return candidate.toISOString().slice(0, 10)
}

// ─── Parser ────────────────────────────────────────────────────────────────

/**
 * Parse a BCBP payload. Accepts payloads captured from PDF417 or Aztec
 * barcodes on paper boarding passes and phone-app passes.
 *
 * @param  {string} raw    the full decoded barcode string
 * @returns {{
 *   ok: boolean,
 *   reason?: string,
 *   data?: {
 *     pax_name: string,         // normalised "First Last"
 *     pax_name_raw: string,     // original "LAST/FIRST MR"
 *     n_legs: number,
 *     e_ticket: boolean,
 *   },
 *   legs?: Array<{
 *     pnr: string,
 *     from_iata: string,
 *     to_iata: string,
 *     carrier: string,
 *     flight_number: string,
 *     flight_date: string|null, // ISO yyyy-mm-dd
 *     compartment: string,
 *     seat: string,
 *     sequence: string,
 *     pax_status: string,
 *     cond_field_len: number,
 *   }>,
 * }}
 */
export function parseBCBP(raw) {
  if (typeof raw !== 'string') return { ok: false, reason: 'not-a-string' }
  const s = raw.replace(/\r/g, '') // tolerate CR, never LF inside BCBP
  if (s.length < MANDATORY_HEADER_LEN + LEG_FIXED_LEN) {
    return { ok: false, reason: 'too-short' }
  }
  if (s[0] !== 'M') return { ok: false, reason: 'not-bcbp' }

  const nLegsChar = s[1]
  const nLegs = parseInt(nLegsChar, 10)
  if (!Number.isFinite(nLegs) || nLegs < 1 || nLegs > 4) {
    return { ok: false, reason: 'invalid-leg-count' }
  }

  const paxNameRaw = slice(s, 2, 20)
  const eTicket    = s[22] === 'E'

  const data = {
    pax_name:     normaliseName(paxNameRaw),
    pax_name_raw: trim(paxNameRaw),
    n_legs:       nLegs,
    e_ticket:     eTicket,
  }
  if (!data.pax_name) return { ok: false, reason: 'no-name' }

  // Parse each leg
  const legs = []
  let cursor = MANDATORY_HEADER_LEN
  for (let i = 0; i < nLegs; i++) {
    if (cursor + LEG_FIXED_LEN > s.length) {
      return { ok: false, reason: `leg-${i + 1}-truncated` }
    }
    const pnr          = trim(slice(s, cursor, 7));        cursor += 7
    const fromIata     = trim(slice(s, cursor, 3));        cursor += 3
    const toIata       = trim(slice(s, cursor, 3));        cursor += 3
    const carrier      = trim(slice(s, cursor, 3));        cursor += 3
    const flightNumRaw = trim(slice(s, cursor, 5));        cursor += 5
    const julian       = slice(s, cursor, 3);              cursor += 3
    const compartment  = trim(slice(s, cursor, 1));        cursor += 1
    const seat         = trim(slice(s, cursor, 4));        cursor += 4
    const sequence     = trim(slice(s, cursor, 5));        cursor += 5
    const paxStatus    = trim(slice(s, cursor, 1));        cursor += 1
    const condLenHex   = slice(s, cursor, 2);              cursor += 2
    const condLen      = hex2(condLenHex)

    // Validate IATA codes shape (3 upper-case alpha)
    if (!/^[A-Z]{3}$/.test(fromIata) || !/^[A-Z]{3}$/.test(toIata)) {
      return { ok: false, reason: `leg-${i + 1}-invalid-iata` }
    }

    legs.push({
      pnr,
      from_iata:     fromIata,
      to_iata:       toIata,
      carrier,
      flight_number: flightNumRaw.replace(/^0+/, '') || '0',
      flight_date:   julianToISO(julian),
      compartment,
      seat,
      sequence,
      pax_status:    paxStatus,
      cond_field_len: condLen,
    })

    // Skip conditional fields + airline-use area; we don't parse them in this
    // version. Cursor jumps past the declared conditional-field length.
    cursor += condLen
  }

  return { ok: true, data, legs }
}

// ─── IATA airport → country lookup (seed only) ────────────────────────────
//
// A minimal seed so the disease-engine can receive an origin country for the
// common African/gateway airports a Uganda POE officer will see. Extend on
// demand — the map is a plain object for trivial editing. Unknown IATA → null
// (caller decides whether to surface the traveller's origin in UI).

const IATA_TO_COUNTRY = Object.freeze({
  // Zambia
  LUN: 'ZM', LVI: 'ZM', NLA: 'ZM', MFU: 'ZM', KIW: 'ZM',
  // Regional neighbours
  JNB: 'ZA', CPT: 'ZA', DUR: 'ZA',
  HRE: 'ZW', VFA: 'ZW', BUQ: 'ZW',
  GBE: 'BW', MUB: 'BW',
  MPM: 'MZ',  WDH: 'NA',
  LLW: 'MW', BLZ: 'MW',
  DAR: 'TZ', JRO: 'TZ', ZNZ: 'TZ',
  EBB: 'UG',
  NBO: 'KE', MBA: 'KE',
  KGL: 'RW', BJM: 'BI',
  // Africa gateways
  ADD: 'ET', CAI: 'EG', LOS: 'NG', ABV: 'NG', LAD: 'AO',
  KIN: 'CD', FIH: 'CD', FBM: 'CD',
  // Outside-Africa hubs common on connections
  DXB: 'AE', DOH: 'QA', IST: 'TR',
  LHR: 'GB', CDG: 'FR', AMS: 'NL', FRA: 'DE',
  JFK: 'US', ATL: 'US', YYZ: 'CA',
  PEK: 'CN', PVG: 'CN', BKK: 'TH',
})

export function iataToCountry(iata) {
  if (typeof iata !== 'string') return null
  return IATA_TO_COUNTRY[iata.toUpperCase()] ?? null
}
