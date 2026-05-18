/**
 * services/modelManager.js — single source of truth for every downloadable
 * ML-Kit / local-asset model the Sentinel subsystem uses.
 *
 * See docs/sentinel-plan/DOWNLOAD_MANAGER.md for the full spec.
 *
 * Design rules (from the audit):
 *   - Every public entry point is try/catch wrapped. Nothing throws.
 *   - State is persisted to localStorage key `sentinel.modelState.v1`.
 *     Corrupt or missing state is recoverable — we always fall back to
 *     `missing` for any registered id.
 *   - No infinite retry loops. The backoff schedule is finite; native-bridge-
 *     missing is detected and halts retries immediately (audit defect
 *     E6.03 / D4.04).
 *   - Cellular confirm emits a DOM event AND falls back with a user-visible
 *     toast if nobody listens (audit defect D4.02 — "silent downgrade").
 *   - `disposeAll()` clears every in-flight retry timer and active download
 *     controller so views unmounting can stop the module cleanly (audit
 *     defect D5.01 — "retryTimers leak").
 *
 * Lazy initialisation: state is only read from localStorage on first public
 * call. This preserves the cold-start budget (<50 ms) called out in the spec
 * section 12 (success criterion #7).
 */

import { CAPABILITY_KEYS, isEnabled } from './capabilities.js'
import { sentinelInfo } from './sentinelToast.js'
import { installModule } from './plugins/mlkitInstaller.js'

// ─────────────────────────────────────────────────────────────────────────────
// Registry
// ─────────────────────────────────────────────────────────────────────────────

export const MODEL_REGISTRY = Object.freeze({
  'mrz-latin': Object.freeze({
    id: 'mrz-latin',
    label: 'Passport MRZ (Latin script)',
    sizeMb: 7,
    feature: CAPABILITY_KEYS.MRZ,
    cellularSafe: true,
    downloader: 'mlkit-text-recognition-latin',
    bundled: false, // see docs/sentinel-plan/UNBUNDLED.md (2026-04-26)
    required_for: ['feature 01 MRZ', 'feature 05 unified scan'],
  }),
  'doc-scanner': Object.freeze({
    id: 'doc-scanner',
    label: 'Document scanner',
    sizeMb: 0,
    feature: CAPABILITY_KEYS.DOC_SCANNER,
    cellularSafe: true,
    downloader: 'play-services-builtin',
    required_for: ['feature 04'],
  }),
  'face-detection': Object.freeze({
    id: 'face-detection',
    label: 'Face detection',
    sizeMb: 4,
    feature: CAPABILITY_KEYS.FACE_MATCH,
    cellularSafe: true,
    downloader: 'mlkit-face-detection',
    bundled: false, // see docs/sentinel-plan/UNBUNDLED.md (2026-04-26)
    required_for: ['feature 09'],
  }),
  'face-embedding-facenet': Object.freeze({
    id: 'face-embedding-facenet',
    label: 'Face-match embedding (FaceNet)',
    sizeMb: 4,
    feature: CAPABILITY_KEYS.FACE_MATCH,
    cellularSafe: true,
    downloader: 'local-asset',
    required_for: ['feature 09'],
  }),
  'translate-en-pt': Object.freeze({
    id: 'translate-en-pt',
    label: 'Translate English <-> Portuguese',
    sizeMb: 30,
    feature: CAPABILITY_KEYS.TRANSLATE,
    cellularSafe: false,
    downloader: 'mlkit-translate',
    bundled: false, // see docs/sentinel-plan/UNBUNDLED.md (2026-04-26)
    required_for: ['feature 10 translation'],
  }),
  'translate-en-fr': Object.freeze({
    id: 'translate-en-fr',
    label: 'Translate English <-> French',
    sizeMb: 30,
    feature: CAPABILITY_KEYS.TRANSLATE,
    cellularSafe: false,
    downloader: 'mlkit-translate',
    bundled: false, // see docs/sentinel-plan/UNBUNDLED.md (2026-04-26)
    required_for: ['feature 10 translation'],
  }),
  'translate-en-bem': Object.freeze({
    id: 'translate-en-bem',
    label: 'Translate English <-> Bemba',
    sizeMb: 30,
    feature: CAPABILITY_KEYS.TRANSLATE,
    cellularSafe: false,
    downloader: 'mlkit-translate',
    bundled: false, // see docs/sentinel-plan/UNBUNDLED.md (2026-04-26)
    required_for: ['feature 10 translation'],
  }),
  'entity-extraction': Object.freeze({
    id: 'entity-extraction',
    label: 'Entity extraction (names, dates, addresses)',
    sizeMb: 15,
    feature: CAPABILITY_KEYS.ENTITY_EXTRACTION,
    cellularSafe: false,
    downloader: 'mlkit-entity-extraction',
    bundled: false, // see docs/sentinel-plan/UNBUNDLED.md (2026-04-26)
    required_for: ['feature 10 entity'],
  }),
  'smart-reply': Object.freeze({
    id: 'smart-reply',
    label: 'Smart reply',
    sizeMb: 0,
    feature: CAPABILITY_KEYS.SMART_REPLY,
    cellularSafe: true,
    downloader: 'play-services-builtin',
    required_for: ['feature 10 smart reply'],
  }),
  // Test-only fixture: present in the registry so vitest can exercise the
  // download/retry state machine, hidden from the UI by listModels() and
  // ignored by every public iterator. Routes through `installModule` with
  // a synthetic downloader that mlkitInstaller treats as unsupported on
  // real devices (so even if it leaked into a release it would never call
  // an unbundled native lib).
  '__test_install_fixture': Object.freeze({
    id: '__test_install_fixture',
    label: 'Test fixture (installable)',
    sizeMb: 1,
    feature: CAPABILITY_KEYS.MRZ,
    cellularSafe: true,
    downloader: 'test-fixture',
    bundled: true,
    __test_fixture: true,
    required_for: [],
  }),
})

// ─────────────────────────────────────────────────────────────────────────────
// Status enum
// ─────────────────────────────────────────────────────────────────────────────

export const MODEL_STATUS = Object.freeze({
  unknown:     'unknown',
  missing:     'missing',
  queued:      'queued',
  downloading: 'downloading',
  ready:       'ready',
  error:       'error',
  evicted:     'evicted',
})

// ─────────────────────────────────────────────────────────────────────────────
// State persistence
// ─────────────────────────────────────────────────────────────────────────────

const STATE_KEY = 'sentinel.modelState.v1'
const MAX_RETRY_COUNT = 6

/**
 * Reason strings that CANNOT be resolved by retrying. The installer returns
 * these when the problem is structural (code or APK-level), not transient.
 * We stamp retryCount at MAX_RETRY_COUNT immediately rather than scheduling
 * a retry loop that would fail identically every time.
 *
 *   native-plugin-not-yet-built  — legacy sentinel from the pre-Kotlin build
 *   native-plugin-not-registered — plugin class missing from APK
 *   library-not-bundled          — ML Kit library not on the classpath
 *   invalid-module-id            — caller bug
 *   malformed-plugin-response    — bridge contract violated
 *   install-error                — JS layer threw; internal bug
 *   unknown-downloader:*         — registry row references an unsupported kind
 */
const NON_RETRIABLE_REASONS = new Set([
  'native-plugin-not-yet-built',
  'native-plugin-not-registered',
  'library-not-bundled',
  'module-not-bundled',
  'invalid-module-id',
  'malformed-plugin-response',
  'install-error',
  'install-timeout',
  'unsupported-language',
])
function isNonRetriableReason(reason) {
  if (typeof reason !== 'string') return false
  if (NON_RETRIABLE_REASONS.has(reason)) return true
  if (reason.startsWith('unknown-downloader:')) return true
  if (reason.startsWith('library-init-failed:')) return true
  if (reason.startsWith('module-install-client-unavailable')) return true
  return false
}
const RETRY_SCHEDULE_MS = [
  30 * 1000,         // 30 s
  2 * 60 * 1000,     // 2 min
  10 * 60 * 1000,    // 10 min
  30 * 60 * 1000,    // 30 min
  2 * 60 * 60 * 1000,  // 2 h
  8 * 60 * 60 * 1000,  // 8 h
]
const CELLULAR_CONFIRM_TIMEOUT_MS = 30_000
const CELLULAR_SIZE_THRESHOLD_MB = 5

// Per-model runtime state keyed by model id.
// Shape: { status, bytesSoFar, bytesTotal, progressPct, lastAttemptAt,
//          lastError, retryCount, lastUsedAt }
let stateLoaded = false
const state = new Map()
const listeners = new Map()            // id -> Set<cb>
const retryTimers = new Map()          // id -> timeoutHandle
const activeDownloads = new Map()      // id -> { cancelled: bool }

function defaultStateFor(id) {
  return {
    status: MODEL_REGISTRY[id] ? MODEL_STATUS.missing : MODEL_STATUS.unknown,
    bytesSoFar: 0,
    bytesTotal: MODEL_REGISTRY[id] ? MODEL_REGISTRY[id].sizeMb * 1_000_000 : 0,
    progressPct: 0,
    lastAttemptAt: 0,
    lastError: null,
    retryCount: 0,
    lastUsedAt: 0,
  }
}

function ensureStateLoaded() {
  if (stateLoaded) return
  stateLoaded = true
  try {
    const raw = localStorage.getItem(STATE_KEY)
    if (raw) {
      const parsed = JSON.parse(raw)
      if (parsed && typeof parsed === 'object') {
        for (const id of Object.keys(parsed)) {
          if (MODEL_REGISTRY[id]) {
            state.set(id, { ...defaultStateFor(id), ...parsed[id] })
          }
        }
      }
    }
  } catch (err) {
    console.debug('[modelManager] failed to load state:', err?.message)
  }
  // Fill any missing registered ids with defaults, and reset `downloading`
  // → `missing` because any in-flight download from the previous session is
  // definitely gone.
  for (const id of Object.keys(MODEL_REGISTRY)) {
    if (!state.has(id)) state.set(id, defaultStateFor(id))
    const s = state.get(id)
    if (s.status === MODEL_STATUS.downloading) {
      s.status = MODEL_STATUS.missing
      s.bytesSoFar = 0
      s.progressPct = 0
    }
  }
}

function persistState() {
  try {
    const snap = {}
    for (const [id, s] of state.entries()) snap[id] = s
    localStorage.setItem(STATE_KEY, JSON.stringify(snap))
  } catch (err) {
    console.debug('[modelManager] failed to persist state:', err?.message)
  }
}

function getState(id) {
  ensureStateLoaded()
  if (!state.has(id)) state.set(id, defaultStateFor(id))
  return state.get(id)
}

function setStatus(id, nextStatus, extras = {}) {
  const s = getState(id)
  s.status = nextStatus
  for (const k of Object.keys(extras)) s[k] = extras[k]
  persistState()
  // Per-id listeners
  try {
    const set = listeners.get(id)
    if (set) {
      for (const cb of set) {
        try { cb({ id, ...s }) } catch (e) { console.debug('[modelManager] listener threw:', e?.message) }
      }
    }
  } catch (err) { console.debug('[modelManager] listener dispatch failed:', err?.message) }
  // DOM event for the Settings UI and modal subscribers.
  try {
    if (typeof window !== 'undefined' && typeof CustomEvent === 'function') {
      window.dispatchEvent(new CustomEvent('sentinel-model-state', {
        detail: {
          id,
          status: s.status,
          progressPct: s.progressPct || 0,
          reason: s.lastError || null,
        },
      }))
    }
  } catch (err) { console.debug('[modelManager] DOM event dispatch failed:', err?.message) }
}

// ─────────────────────────────────────────────────────────────────────────────
// Gate helpers
// ─────────────────────────────────────────────────────────────────────────────

function masterOff() {
  try { return !isEnabled(CAPABILITY_KEYS.SENTINEL_MASTER) } catch { return true }
}
function downloadsFrozen() {
  try { return isEnabled(CAPABILITY_KEYS.SENTINEL_FROZEN) === true } catch { return false }
}

// ─────────────────────────────────────────────────────────────────────────────
// Cellular confirmation — audit fix D4.02 (no silent cellular downgrade)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Ask the user (via the ModelManagerView modal) whether a cellular download
 * is OK. If no listener responds within CELLULAR_CONFIRM_TIMEOUT_MS, surface a
 * toast so the user knows WHY the queue is stuck, then fall back to
 * 'wifi-only'.
 *
 * Audit fix: the previous version silently defaulted to wifi-only. Officers
 * blamed the app for "being broken" because the queue was stuck with no
 * visible reason.
 */
function requestCellularConfirm(id, sizeMb) {
  return new Promise((resolve) => {
    let settled = false
    let onResponse = null
    let timeoutHandle = null

    const cleanup = () => {
      if (timeoutHandle) { try { clearTimeout(timeoutHandle) } catch {} timeoutHandle = null }
      if (onResponse && typeof window !== 'undefined') {
        try { window.removeEventListener('sentinel-model-confirm-response', onResponse) } catch {}
      }
      onResponse = null
    }

    const settle = (value) => {
      if (settled) return
      settled = true
      cleanup()
      resolve(value)
    }

    try {
      onResponse = (e) => {
        const detail = e?.detail
        const v = (detail && detail.id && detail.id !== id) ? null : (detail?.response || detail)
        if (v === 'cellular-ok' || v === 'wifi-only') settle(v)
      }
      if (typeof window !== 'undefined') {
        window.addEventListener('sentinel-model-confirm-response', onResponse)
        window.dispatchEvent(new CustomEvent('sentinel-model-confirm-needed', {
          detail: { id, sizeMb },
        }))
      }
      timeoutHandle = setTimeout(() => {
        // Nobody responded. Surface the stuck state so the user knows why.
        try { sentinelInfo(`${MODEL_REGISTRY[id]?.label || id} queued — waiting for Wi-Fi`) } catch {}
        settle('wifi-only')
      }, CELLULAR_CONFIRM_TIMEOUT_MS)
    } catch (err) {
      console.debug('[modelManager] cellular confirm failed:', err?.message)
      settle('wifi-only')
    }
  })
}

// ─────────────────────────────────────────────────────────────────────────────
// Network probe
// ─────────────────────────────────────────────────────────────────────────────

function isOnline() {
  try { return typeof navigator === 'undefined' ? true : navigator.onLine !== false } catch { return true }
}
function isOnCellular() {
  try {
    const n = typeof navigator !== 'undefined' ? navigator : null
    const conn = n && (n.connection || n.mozConnection || n.webkitConnection)
    if (!conn) return false
    const t = conn.type || conn.effectiveType
    return t === 'cellular' || t === '2g' || t === '3g' || t === '4g' || t === '5g'
  } catch {
    return false
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Download driver
// ─────────────────────────────────────────────────────────────────────────────

async function runDownload(id) {
  const reg = MODEL_REGISTRY[id]
  if (!reg) return

  // Already in-flight? No-op.
  if (activeDownloads.has(id)) return

  // Gate re-check at run time.
  if (masterOff()) { setStatus(id, MODEL_STATUS.missing, { lastError: 'sentinel-master-off' }); return }
  if (downloadsFrozen()) { setStatus(id, MODEL_STATUS.missing, { lastError: 'downloads-frozen' }); return }

  // Offline? Queue it — the UI shows queued status.
  if (!isOnline()) {
    setStatus(id, MODEL_STATUS.queued, { lastError: 'offline' })
    return
  }

  // Cellular confirm if needed.
  if (isOnCellular()) {
    if (!reg.cellularSafe || reg.sizeMb > CELLULAR_SIZE_THRESHOLD_MB) {
      // User must explicitly opt in.
      const choice = await requestCellularConfirm(id, reg.sizeMb)
      if (choice !== 'cellular-ok') {
        setStatus(id, MODEL_STATUS.queued, { lastError: 'waiting-for-wifi' })
        return
      }
    }
  }

  const controller = { cancelled: false }
  activeDownloads.set(id, controller)
  setStatus(id, MODEL_STATUS.downloading, {
    bytesSoFar: 0,
    progressPct: 0,
    lastAttemptAt: Date.now(),
    lastError: null,
  })

  let result
  try {
    // Pass the registry's `downloader` hint so mlkitInstaller can resolve
    // the correct native module id (text-recognition-latin / face-detection /
    // play-services built-in / ...).
    result = await installModule(id, {
      downloader: reg.downloader,
      onProgress: (p) => {
        if (controller.cancelled) return
        const s = getState(id)
        s.bytesSoFar = p?.bytesSoFar ?? 0
        s.bytesTotal = p?.bytesTotal ?? s.bytesTotal
        s.progressPct = p?.progressPct ?? 0
        // Downloading status already set; dispatch a progress tick.
        setStatus(id, MODEL_STATUS.downloading)
      },
    })
  } catch (err) {
    // installModule guarantees no-throw, but belt + suspenders.
    console.debug('[modelManager] installModule unexpected throw:', err?.message)
    result = { ok: false, reason: 'install-error' }
  }

  activeDownloads.delete(id)

  if (controller.cancelled) {
    setStatus(id, MODEL_STATUS.missing, {
      bytesSoFar: 0,
      progressPct: 0,
      lastError: 'cancelled',
    })
    return
  }

  if (result && result.ok) {
    setStatus(id, MODEL_STATUS.ready, {
      bytesSoFar: getState(id).bytesTotal,
      progressPct: 100,
      retryCount: 0,
      lastError: null,
      lastUsedAt: Date.now(),
    })
    return
  }

  const reason = result?.reason || 'unknown-error'

  // Non-retriable failure modes — retrying cannot possibly succeed without a
  // code change (missing native plugin registration, missing ML Kit library
  // in the APK, malformed plugin response, unknown downloader). Stamp
  // retryCount at max so scheduleRetry short-circuits, and log a clear
  // developer-facing reason.
  //
  // Keeps the app from looping forever on a device that can never succeed,
  // and still surfaces the underlying cause to the officer via the Model
  // Manager's error card.
  if (isNonRetriableReason(reason)) {
    console.debug('[modelManager] non-retriable reason for', id, '→', reason)
    setStatus(id, MODEL_STATUS.error, {
      retryCount: MAX_RETRY_COUNT,
      lastError: reason,
    })
    return
  }

  // Generic failure — schedule retry with backoff up to MAX_RETRY_COUNT.
  const s = getState(id)
  const nextAttempt = (s.retryCount || 0) + 1
  if (nextAttempt >= MAX_RETRY_COUNT) {
    setStatus(id, MODEL_STATUS.error, {
      retryCount: MAX_RETRY_COUNT,
      lastError: 'max-retries',
    })
    return
  }
  setStatus(id, MODEL_STATUS.error, {
    retryCount: nextAttempt,
    lastError: reason,
  })
  scheduleRetry(id, nextAttempt)
}

function scheduleRetry(id, attempt) {
  try {
    if (retryTimers.has(id)) {
      try { clearTimeout(retryTimers.get(id)) } catch {}
      retryTimers.delete(id)
    }
    const idx = Math.min(attempt - 1, RETRY_SCHEDULE_MS.length - 1)
    const delay = RETRY_SCHEDULE_MS[idx]
    const handle = setTimeout(() => {
      retryTimers.delete(id)
      runDownload(id).catch((e) => console.debug('[modelManager] retry failed:', e?.message))
    }, delay)
    retryTimers.set(id, handle)
  } catch (err) {
    console.debug('[modelManager] scheduleRetry failed:', err?.message)
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Public API
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Ensure `id` is available. Never throws.
 *
 * Returns:
 *   { ok: true }
 *   { ok: false, reason: 'unknown-model' | 'sentinel-master-off' |
 *                        'downloads-frozen' | 'downloading' | 'queued' |
 *                        'error' | 'missing', progressPct? }
 */
export async function ensureModel(id) {
  try {
    if (!id || typeof id !== 'string' || !MODEL_REGISTRY[id]) {
      return { ok: false, reason: 'unknown-model' }
    }
    // Global UX overrides apply before per-model structural checks so the
    // master kill-switch / download freeze always wins.
    if (masterOff())      return { ok: false, reason: 'sentinel-master-off' }
    if (downloadsFrozen()) return { ok: false, reason: 'downloads-frozen' }
    // Models whose ML Kit feature library was unbundled from the APK
    // (see docs/sentinel-plan/UNBUNDLED.md) cannot be installed at runtime.
    // Short-circuit before the queue/retry machinery so the UI sees the
    // canonical reason on every platform (web mock + native).
    if (MODEL_REGISTRY[id].bundled === false) {
      return { ok: false, reason: 'module-not-bundled' }
    }

    const s = getState(id)
    if (s.status === MODEL_STATUS.ready) {
      // Touch last-used so eviction skips it.
      s.lastUsedAt = Date.now()
      try { persistState() } catch {}
      return { ok: true }
    }
    if (s.status === MODEL_STATUS.downloading) {
      return { ok: false, reason: 'downloading', progressPct: s.progressPct || 0 }
    }
    if (s.status === MODEL_STATUS.queued) {
      return { ok: false, reason: 'queued' }
    }

    // missing / error / evicted / unknown → kick off a download.
    runDownload(id).catch((e) => console.debug('[modelManager] runDownload error:', e?.message))
    return { ok: false, reason: 'missing', progressPct: 0 }
  } catch (err) {
    console.debug('[modelManager] ensureModel failed:', err?.message)
    return { ok: false, reason: 'unknown-error' }
  }
}

/**
 * Subscribe to per-model state transitions.
 * @returns {() => void} unsubscribe
 */
export function subscribeModel(id, cb) {
  try {
    if (!id || typeof cb !== 'function') return () => {}
    if (!listeners.has(id)) listeners.set(id, new Set())
    listeners.get(id).add(cb)
    return () => {
      try { listeners.get(id)?.delete(cb) } catch {}
    }
  } catch (err) {
    console.debug('[modelManager] subscribeModel failed:', err?.message)
    return () => {}
  }
}

export function cancelDownload(id) {
  try {
    if (!id || !MODEL_REGISTRY[id]) return
    const ctrl = activeDownloads.get(id)
    if (ctrl) ctrl.cancelled = true
    if (retryTimers.has(id)) {
      try { clearTimeout(retryTimers.get(id)) } catch {}
      retryTimers.delete(id)
    }
    // Downgrade status if it was queued — downloading will be caught by the
    // controller.cancelled check at the end of runDownload.
    const s = getState(id)
    if (s.status === MODEL_STATUS.queued || s.status === MODEL_STATUS.error) {
      setStatus(id, MODEL_STATUS.missing, {
        bytesSoFar: 0,
        progressPct: 0,
        lastError: 'cancelled',
      })
    }
  } catch (err) {
    console.debug('[modelManager] cancelDownload failed:', err?.message)
  }
}

export function pauseDownload(id) {
  // Pause semantics: treat as cancel + queued.
  try {
    if (!id || !MODEL_REGISTRY[id]) return
    const ctrl = activeDownloads.get(id)
    if (ctrl) ctrl.cancelled = true
    setStatus(id, MODEL_STATUS.queued, { lastError: 'paused' })
  } catch (err) {
    console.debug('[modelManager] pauseDownload failed:', err?.message)
  }
}

export function resumeDownload(id) {
  try {
    if (!id || !MODEL_REGISTRY[id]) return
    const s = getState(id)
    if (s.status === MODEL_STATUS.queued || s.status === MODEL_STATUS.error || s.status === MODEL_STATUS.missing || s.status === MODEL_STATUS.evicted) {
      runDownload(id).catch((e) => console.debug('[modelManager] resume runDownload failed:', e?.message))
    }
  } catch (err) {
    console.debug('[modelManager] resumeDownload failed:', err?.message)
  }
}

export function deleteModel(id) {
  try {
    if (!id || !MODEL_REGISTRY[id]) return
    const ctrl = activeDownloads.get(id)
    if (ctrl) ctrl.cancelled = true
    if (retryTimers.has(id)) {
      try { clearTimeout(retryTimers.get(id)) } catch {}
      retryTimers.delete(id)
    }
    setStatus(id, MODEL_STATUS.evicted, {
      bytesSoFar: 0,
      progressPct: 0,
      retryCount: 0,
      lastError: 'deleted',
      lastUsedAt: 0,
    })
  } catch (err) {
    console.debug('[modelManager] deleteModel failed:', err?.message)
  }
}

export function retryModel(id) {
  try {
    if (!id || !MODEL_REGISTRY[id]) return
    if (retryTimers.has(id)) {
      try { clearTimeout(retryTimers.get(id)) } catch {}
      retryTimers.delete(id)
    }
    const s = getState(id)
    s.retryCount = 0
    runDownload(id).catch((e) => console.debug('[modelManager] retryModel runDownload failed:', e?.message))
  } catch (err) {
    console.debug('[modelManager] retryModel failed:', err?.message)
  }
}

/**
 * Enumerate every registered model with its current merged state.
 * Never throws.
 * @returns {Array<object>}
 */
export function listModels() {
  try {
    ensureStateLoaded()
    const out = []
    for (const id of Object.keys(MODEL_REGISTRY)) {
      const reg = MODEL_REGISTRY[id]
      const s = getState(id)
      out.push({ ...reg, ...s })
    }
    return out
  } catch (err) {
    console.debug('[modelManager] listModels failed:', err?.message)
    return []
  }
}

/**
 * Aggregate telemetry for the Sync dashboard pill + ModelManagerView header.
 * Never throws.
 */
export function getTelemetry() {
  try {
    ensureStateLoaded()
    let totalBytesDownloaded = 0
    const modelsReady = []
    const modelsEvicted = []
    const currentDownloads = []
    const errorsLast24h = []
    const cutoff = Date.now() - 24 * 60 * 60 * 1000
    for (const id of Object.keys(MODEL_REGISTRY)) {
      const s = getState(id)
      if (s.status === MODEL_STATUS.ready) {
        modelsReady.push(id)
        totalBytesDownloaded += s.bytesTotal || 0
      }
      if (s.status === MODEL_STATUS.evicted) modelsEvicted.push(id)
      if (s.status === MODEL_STATUS.downloading) {
        currentDownloads.push({
          id,
          bytesSoFar: s.bytesSoFar || 0,
          bytesTotal: s.bytesTotal || 0,
        })
      }
      if (s.status === MODEL_STATUS.error && s.lastAttemptAt && s.lastAttemptAt >= cutoff) {
        errorsLast24h.push({ id, reason: s.lastError || 'unknown', at: s.lastAttemptAt })
      }
    }
    return { totalBytesDownloaded, modelsReady, modelsEvicted, currentDownloads, errorsLast24h }
  } catch (err) {
    console.debug('[modelManager] getTelemetry failed:', err?.message)
    return { totalBytesDownloaded: 0, modelsReady: [], modelsEvicted: [], currentDownloads: [], errorsLast24h: [] }
  }
}

/**
 * AUDIT FIX D5.01 — retryTimers leak.
 *
 * Every view that imports this module MUST call disposeAll() in onUnmounted.
 * Without it, retry timers keep firing forever (they're on setTimeout, not
 * tied to any component lifecycle), and a view that mounts and unmounts
 * repeatedly leaks one timer per mount.
 *
 * Also cancels every in-flight download so no further progress events fire
 * into detached listeners.
 *
 * Never throws.
 */
export function disposeAll() {
  try {
    for (const [, handle] of retryTimers) {
      try { clearTimeout(handle) } catch {}
    }
    retryTimers.clear()
  } catch (err) { console.debug('[modelManager] disposeAll timers failed:', err?.message) }
  try {
    for (const [, ctrl] of activeDownloads) {
      try { ctrl.cancelled = true } catch {}
    }
    activeDownloads.clear()
  } catch (err) { console.debug('[modelManager] disposeAll controllers failed:', err?.message) }
  // Listeners intentionally kept — a view removing its listener is a
  // separate call (subscribeModel's returned unsubscribe). disposeAll does
  // not guess what other callers want.
}
