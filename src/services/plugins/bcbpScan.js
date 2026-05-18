/**
 * services/plugins/bcbpScan.js — scan a boarding pass (PDF417 / Aztec) with
 * the existing MLKit barcode plugin, then parse IATA 792 BCBP M1.
 *
 * Composed from:
 *   - services/plugins/barcode.js — sandbox-wrapped camera scan
 *   - services/bcbp.js            — pure parser
 *
 * We deliberately do NOT wrap the MLKit import in a new sandbox. The barcode
 * sandbox already enforces the master + freeze + per-feature gates. We add
 * one extra check here against `CAPABILITY_KEYS.BCBP` so the officer can
 * turn BCBP off independently of the generic barcode scanner.
 *
 * See docs/sentinel-plan/features/03-bcbp-parser.md (spec stub) and
 * docs/sentinel-plan/ARCHITECTURE.md §1.
 */

import { scanOnce, scanFromImage } from './barcode.js'
import { parseBCBP, iataToCountry } from '../bcbp.js'
import { isSentinelFeatureOn, CAPABILITY_KEYS } from '../capabilities.js'

// Dev-only canned specimen so feature wiring can be exercised in a laptop
// browser via `npm run dev` with `?sentinel-mock=1` on the URL. Matches the
// IATA 792 specimen used in the parser tests. Never reachable in a
// production build because the URL-param check is a browser-time check and
// no officer would be handed a dev URL.
function isMockMode() {
  try {
    return new URLSearchParams(window.location.search).get('sentinel-mock') === '1'
  } catch { return false }
}
const MOCK_BCBP = 'M1MOCKER/TEST MS      EABC1234LUNJNB ET00715180Y014C00001000'

// Formats we accept for boarding passes: PDF_417 covers paper passes,
// AZTEC covers most airline-app passes, QR_CODE handles a handful of LCCs.
const BCBP_FORMATS = ['PDF_417', 'AZTEC', 'QR_CODE']

/**
 * Decode a boarding pass from an image already captured offline (typically
 * from @capacitor/camera). Uses the BUNDLED ML Kit barcode-scanning lib —
 * no Play Services Code Scanner module needed, no network ever required.
 * This is the path used on government-issued offline tablets.
 *
 * Hardening: never throws. Returns reason="no-barcode" if the photo
 * contains no decodable code, "scan-failed" for any plugin/runtime
 * issue. Caller may retry with a fresh photo.
 */
export async function scanBoardingPassFromImage(path) {
  try {
    if (!isSentinelFeatureOn(CAPABILITY_KEYS.BCBP)) {
      return { ok: false, reason: 'disabled' }
    }
    if (!path || typeof path !== 'string') return { ok: false, reason: 'missing-path' }
    let scan = null
    try {
      scan = await scanFromImage(path, { formats: BCBP_FORMATS })
    } catch (err) {
      console.debug('[bcbp] scanFromImage threw:', err?.message)
      return { ok: false, reason: 'scan-failed' }
    }
    if (!scan || !scan.value) return { ok: false, reason: 'no-barcode' }
    return finaliseBcbp(scan)
  } catch (err) {
    console.debug('[bcbp] scanBoardingPassFromImage outer-throw:', err?.message)
    return { ok: false, reason: 'scan-failed' }
  }
}

/**
 * Launch a camera scan for a boarding pass barcode and return the parsed
 * BCBP record, plus a convenience `origin_country` field derived from the
 * first leg's IATA origin.
 *
 * Uses Google Code Scanner (on-demand from Play Services). Requires
 * network on first use to download the module. For offline-first
 * deployments, prefer `scanBoardingPassFromImage(path)`.
 *
 * Never throws. Returns one of:
 *   { ok: true, scan: {...}, data, legs, origin_country }
 *   { ok: false, reason: 'disabled' | 'scan-cancelled' | <parser reason> }
 */
export async function scanBoardingPass({ onInstallProgress } = {}) {
  if (!isSentinelFeatureOn(CAPABILITY_KEYS.BCBP)) {
    return { ok: false, reason: 'disabled' }
  }
  // Dev-mode shortcut: no camera, returns a canned pass. See top of file.
  let scan
  if (isMockMode()) {
    scan = { value: MOCK_BCBP, format: 'PDF_417', valueType: 'TEXT' }
  } else {
    scan = await scanOnce({ formats: BCBP_FORMATS, onInstallProgress })
  }
  return finaliseBcbp(scan)
}

function finaliseBcbp(scan) {
  if (!scan || !scan.value) return { ok: false, reason: 'scan-cancelled' }

  const parsed = parseBCBP(scan.value)
  if (!parsed.ok) return { ok: false, reason: parsed.reason, raw: scan.value }

  const firstLeg = parsed.legs?.[0]
  const originCountry = firstLeg ? iataToCountry(firstLeg.from_iata) : null

  return {
    ok:             true,
    scan:           { format: scan.format, valueType: scan.valueType },
    data:           parsed.data,
    legs:           parsed.legs,
    origin_country: originCountry,
  }
}
