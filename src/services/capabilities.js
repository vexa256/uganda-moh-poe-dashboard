/**
 * services/capabilities.js — central toggle registry for every opt-in native capability.
 *
 * Every capability wrapper under services/plugins/* reads its flag through
 * isEnabled(key); Settings + CapabilitiesHelp write flags through setEnabled(key, bool).
 *
 * Design rules:
 *   - All flags live under the "cap.*" localStorage namespace.
 *   - Each flag has a declared DEFAULT here — one source of truth for onboarding.
 *   - Listeners are notified via `subscribe(key, cb)` AND a custom DOM event
 *     "capability-changed" so views don't need to import this file if they just
 *     want to react to any change.
 *   - Every call is try/catch wrapped — a corrupted localStorage cannot crash
 *     any caller; returning the default is always safe.
 *
 * KEYS (keep in sync with docs/CAPABILITIES_PLAN.md §12.2)
 */

export const CAPABILITY_KEYS = Object.freeze({
  NETWORK:        'cap.network_plugin.enabled',
  KEEPAWAKE:      'cap.keepawake.enabled',
  VOICE:          'cap.voice.enabled',
  BARCODE:        'cap.barcode.enabled',
  LOCAL_NOTIFS:   'cap.local_notifications.enabled',
  PDF_SHARE:      'cap.pdf_share.enabled',
  APP_LOCK:       'cap.applock.enabled',
  APP_LOCK_MIN:   'cap.autolock.minutes',
  DIRECTORY:      'cap.directory.enabled',
  TOUR_SEEN:      'cap.tour.seen_version',
  HAPTICS:        'haptics.enabled',

  // Sentinel productivity plan — see docs/sentinel-plan/ARCHITECTURE.md
  SENTINEL_MASTER:    'cap.sentinel.master.enabled',
  SENTINEL_FROZEN:    'cap.sentinel.downloads.frozen',
  MRZ:                'cap.mrz.enabled',
  NFC_PASSPORT:       'cap.nfc_passport.enabled',
  BCBP:               'cap.bcbp.enabled',
  DOC_SCANNER:        'cap.doc_scanner.enabled',
  UNIFIED_SCAN:       'cap.unified_scan.enabled',
  VOICE_WIZARD:       'cap.voice_wizard.enabled',
  BLE_THERMOMETER:    'cap.ble_thermometer.enabled',
  SHORTCUTS:          'cap.shortcuts.enabled',
  FACE_MATCH:         'cap.face_match.enabled',
  TRANSLATE:          'cap.translate.enabled',
  ENTITY_EXTRACTION:  'cap.entity_extraction.enabled',
  SMART_REPLY:        'cap.smart_reply.enabled',
})

const DEFAULTS = {
  [CAPABILITY_KEYS.NETWORK]:        true,
  [CAPABILITY_KEYS.KEEPAWAKE]:      true,
  [CAPABILITY_KEYS.VOICE]:          false,   // 2026-05-07: voice disconnected app-wide
  [CAPABILITY_KEYS.BARCODE]:        true,
  [CAPABILITY_KEYS.LOCAL_NOTIFS]:   true,
  [CAPABILITY_KEYS.PDF_SHARE]:      true,
  [CAPABILITY_KEYS.APP_LOCK]:       false,
  [CAPABILITY_KEYS.APP_LOCK_MIN]:   5,
  [CAPABILITY_KEYS.DIRECTORY]:      true,
  [CAPABILITY_KEYS.TOUR_SEEN]:      '',
  [CAPABILITY_KEYS.HAPTICS]:        true,

  // Sentinel: master is ON so the subsystem responds when individual features
  // are enabled, but every individual feature is OFF until a supervisor
  // calibrates it on-device. See docs/sentinel-plan/PHILOSOPHY.md.
  [CAPABILITY_KEYS.SENTINEL_MASTER]:    true,
  [CAPABILITY_KEYS.SENTINEL_FROZEN]:    false,
  // MRZ and Voice are operational requirements — on by default.
  [CAPABILITY_KEYS.MRZ]:                true,
  [CAPABILITY_KEYS.NFC_PASSPORT]:       false,
  [CAPABILITY_KEYS.BCBP]:               false,
  [CAPABILITY_KEYS.DOC_SCANNER]:        false,
  [CAPABILITY_KEYS.UNIFIED_SCAN]:       false,
  [CAPABILITY_KEYS.VOICE_WIZARD]:       false,  // 2026-05-07: voice disconnected app-wide
  [CAPABILITY_KEYS.BLE_THERMOMETER]:    false,
  [CAPABILITY_KEYS.SHORTCUTS]:          false,
  [CAPABILITY_KEYS.FACE_MATCH]:         false,
  [CAPABILITY_KEYS.TRANSLATE]:          false,
  [CAPABILITY_KEYS.ENTITY_EXTRACTION]:  false,
  [CAPABILITY_KEYS.SMART_REPLY]:        false,
}

/** Keys that belong to the Sentinel productivity subsystem (master-gated). */
export const SENTINEL_KEYS = Object.freeze([
  CAPABILITY_KEYS.MRZ,
  CAPABILITY_KEYS.NFC_PASSPORT,
  CAPABILITY_KEYS.BCBP,
  CAPABILITY_KEYS.DOC_SCANNER,
  CAPABILITY_KEYS.UNIFIED_SCAN,
  CAPABILITY_KEYS.VOICE_WIZARD,
  CAPABILITY_KEYS.BLE_THERMOMETER,
  CAPABILITY_KEYS.SHORTCUTS,
  CAPABILITY_KEYS.FACE_MATCH,
  CAPABILITY_KEYS.TRANSLATE,
  CAPABILITY_KEYS.ENTITY_EXTRACTION,
  CAPABILITY_KEYS.SMART_REPLY,
])

/**
 * True iff a Sentinel feature is currently usable on the device.
 * Honours the master kill switch and the per-feature flag.
 *
 * Note: the "frozen" flag does NOT live here — freezing blocks downloads
 * in the sandbox layer only (see `services/plugins/_base.js` isGatedOff when
 * `sentinel: true`), so pure features like BCBP/Voice that don't depend on
 * downloads keep working even when `SENTINEL_FROZEN` is set. This matches
 * the UI label "Block all model downloads".
 */
export function isSentinelFeatureOn(key) {
  if (!isEnabled(CAPABILITY_KEYS.SENTINEL_MASTER)) return false
  return isEnabled(key)
}

// 2026-05-07 — Voice retirement guard.
// Voice was retired app-wide. Force any persisted flag value off on first
// load so users with the old default "on" don't see ghost voice toggles.
// Runs once per page load; idempotent.
try {
  const VOICE_RETIRED_FLAG = 'cap.voice.retired_v1'
  if (typeof localStorage !== 'undefined' && !localStorage.getItem(VOICE_RETIRED_FLAG)) {
    localStorage.setItem('cap.voice.enabled', '0')
    localStorage.setItem('cap.voice_wizard.enabled', '0')
    localStorage.setItem(VOICE_RETIRED_FLAG, '1')
  }
} catch { /* localStorage may be unavailable in SSR or private mode */ }

const listeners = new Map() // key -> Set<cb>

function isBoolKey(key) {
  return key !== CAPABILITY_KEYS.APP_LOCK_MIN && key !== CAPABILITY_KEYS.TOUR_SEEN
}

export function isEnabled(key) {
  try {
    const v = localStorage.getItem(key)
    if (v === null) return DEFAULTS[key] ?? true
    if (isBoolKey(key)) return v === '1' || v === 'true'
    return v
  } catch {
    return DEFAULTS[key] ?? true
  }
}

export function getValue(key) {
  try {
    const v = localStorage.getItem(key)
    if (v === null) return DEFAULTS[key]
    if (isBoolKey(key)) return v === '1' || v === 'true'
    const n = Number(v)
    return Number.isFinite(n) && String(n) === v ? n : v
  } catch {
    return DEFAULTS[key]
  }
}

export function setEnabled(key, value) {
  try {
    if (isBoolKey(key)) {
      localStorage.setItem(key, value ? '1' : '0')
    } else {
      localStorage.setItem(key, String(value))
    }
  } catch (err) {
    console.debug('[capabilities] setEnabled failed:', err?.message)
  }
  notify(key, value)
}

export function subscribe(key, cb) {
  if (!listeners.has(key)) listeners.set(key, new Set())
  listeners.get(key).add(cb)
  return () => listeners.get(key)?.delete(cb)
}

function notify(key, value) {
  try {
    const set = listeners.get(key)
    if (set) for (const cb of set) { try { cb(value) } catch (e) { console.debug('[capabilities] listener threw:', e?.message) } }
    window.dispatchEvent(new CustomEvent('capability-changed', { detail: { key, value } }))
  } catch (err) {
    console.debug('[capabilities] notify failed:', err?.message)
  }
}

/** Snapshot of every declared capability — used by Help + Settings views. */
export function snapshot() {
  const out = {}
  for (const k of Object.values(CAPABILITY_KEYS)) out[k] = getValue(k)
  return out
}
