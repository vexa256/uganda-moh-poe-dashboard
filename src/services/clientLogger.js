/**
 * services/clientLogger.js — universal client-side error capture + ship.
 *
 * Goal: capture EVERY JS error the app ever sees (and a configurable amount
 * of console noise as breadcrumbs), durably queue them in IndexedDB, and
 * flush them to the Laravel `/client-logs` endpoint. Online or offline,
 * the queue survives reload, network loss, and process kill — exactly the
 * same model as the secondary-screening outbox.
 *
 * Sources captured
 *   1. window.onerror                    — synchronous JS exceptions
 *   2. window.onunhandledrejection       — unhandled Promise rejections
 *   3. Vue app.config.errorHandler       — render / lifecycle exceptions
 *   4. Vue app.config.warnHandler        — Vue compile / runtime warnings (DEV)
 *   5. console.error patch               — explicit error logging
 *   6. console.warn  patch               — explicit warning logging
 *   7. fetch interceptor errors          — network failures, 5xx responses
 *   8. Capacitor plugin sandbox failures — already routed to console.debug,
 *                                          captured via console.warn patch
 *   9. Manual API:  logger.error(...)    — for explicit business errors
 *  10. Manual API:  logger.fatal(...)    — for unrecoverable states
 *
 * Output
 *   - POST /client-logs  with a single record body
 *
 * Storage
 *   - localStorage queue (small JSON array, capped at 1000 entries) so the
 *     ship loop survives navigation. IndexedDB would be overkill for the
 *     volume; localStorage is synchronous and zero-dep.
 *
 * Privacy
 *   - PII redaction: traveler names, document numbers, and phone numbers
 *     are scrubbed from message + stack BEFORE write. Adjust REDACT_PATTERNS
 *     below if your domain adds new PII.
 */

// ── Configuration ────────────────────────────────────────────────────────
const ENDPOINT_PATH      = '/client-logs'
const FLUSH_INTERVAL_MS  = 5000          // periodic flush
const FLUSH_BATCH_SIZE   = 25            // max records per POST
const QUEUE_CAP          = 1000          // localStorage entries (oldest dropped)
const STORAGE_KEY        = 'rw_poe_client_log_queue'
const SESSION_KEY        = 'rw_poe_client_log_session'
const HEARTBEAT_MS       = 60_000        // periodic INFO ping (proves the app is alive)
const MAX_FIELD_LEN      = 8000          // truncate fields longer than this
const MAX_BREADCRUMBS    = 30            // ring buffer

const LEVELS = Object.freeze({
  TRACE: 'TRACE',
  DEBUG: 'DEBUG',
  INFO:  'INFO',
  WARN:  'WARN',
  ERROR: 'ERROR',
  FATAL: 'FATAL',
})

// ── Module state ─────────────────────────────────────────────────────────
let _installed = false
let _flushTimer = null
let _sessionId  = null
const _breadcrumbs = []   // ring buffer

// ── Helpers ──────────────────────────────────────────────────────────────
function isoNow() { return new Date().toISOString() }

function getOrCreateSession() {
  if (_sessionId) return _sessionId
  try {
    const cached = sessionStorage.getItem(SESSION_KEY)
    if (cached) { _sessionId = cached; return cached }
  } catch {}
  const id = (typeof crypto !== 'undefined' && crypto.randomUUID)
    ? crypto.randomUUID()
    : 'sess-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10)
  try { sessionStorage.setItem(SESSION_KEY, id) } catch {}
  _sessionId = id
  return id
}

function safeAuth() {
  try {
    const a = JSON.parse(sessionStorage.getItem('AUTH_DATA') || 'null')
    return a && typeof a === 'object' ? a : {}
  } catch { return {} }
}

function safeDeviceId() {
  try { return localStorage.getItem('rw_poe_device_id') || null } catch { return null }
}

function safeAppVersion() {
  try { return (window.APP_VERSION || import.meta?.env?.VITE_APP_VERSION || null) } catch { return null }
}

function truncate(v, n = MAX_FIELD_LEN) {
  if (v == null) return null
  const s = typeof v === 'string' ? v : String(v)
  return s.length > n ? s.slice(0, n) + '…[truncated]' : s
}

const REDACT_PATTERNS = [
  // Document numbers (passports, NIDs) — long alphanumeric runs
  { re: /\b[A-Z]{1,3}\d{6,12}\b/g, sub: '[DOC]' },
  // Phone numbers — Uganda style (+256 …) and generic E.164
  { re: /\+\d{6,15}\b/g, sub: '[PHONE]' },
  // Email
  { re: /[\w.\-]+@[\w.\-]+\.[a-z]{2,}/gi, sub: '[EMAIL]' },
  // Bearer tokens
  { re: /Bearer\s+[A-Za-z0-9._-]+/gi, sub: 'Bearer [REDACTED]' },
  // JSON full_name fields with quoted values
  { re: /("(?:full_name|traveler_full_name|name|phone_number)"\s*:\s*")([^"]*)(")/gi,
    sub: (_, a, _b, c) => a + '[REDACTED]' + c },
]

function redact(s) {
  if (!s || typeof s !== 'string') return s
  let out = s
  for (const { re, sub } of REDACT_PATTERNS) {
    out = out.replace(re, sub)
  }
  return out
}

// ── Breadcrumbs (mini-trail attached to every event) ─────────────────────
export function breadcrumb(category, message, data = null) {
  const crumb = {
    t: isoNow(),
    category: String(category || 'misc').slice(0, 32),
    message: redact(truncate(message, 400)),
    data: data ? truncate(JSON.stringify(safe(data)), 1000) : null,
  }
  _breadcrumbs.push(crumb)
  if (_breadcrumbs.length > MAX_BREADCRUMBS) _breadcrumbs.shift()
}

function safe(o) {
  // JSON-clone with circular-ref guard
  const seen = new WeakSet()
  return JSON.parse(JSON.stringify(o, (_k, v) => {
    if (typeof v === 'object' && v !== null) {
      if (seen.has(v)) return '[Circular]'
      seen.add(v)
    }
    if (typeof v === 'function') return '[Function]'
    if (typeof v === 'bigint')   return v.toString() + 'n'
    return v
  }))
}

// ── Storage queue ────────────────────────────────────────────────────────
function readQueue() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return []
    const arr = JSON.parse(raw)
    return Array.isArray(arr) ? arr : []
  } catch { return [] }
}

function writeQueue(arr) {
  try {
    if (arr.length > QUEUE_CAP) arr = arr.slice(-QUEUE_CAP)   // drop oldest
    localStorage.setItem(STORAGE_KEY, JSON.stringify(arr))
  } catch {
    // Quota exceeded — keep only the most recent half so we don't lose
    // brand-new errors while waiting for the queue to drain.
    try {
      const half = arr.slice(-Math.floor(QUEUE_CAP / 2))
      localStorage.setItem(STORAGE_KEY, JSON.stringify(half))
    } catch {}
  }
}

function pushQueue(record) {
  const q = readQueue()
  q.push(record)
  writeQueue(q)
}

// ── Build a record ───────────────────────────────────────────────────────
function buildRecord(level, message, { stack, source, lineno, colno, error, extra } = {}) {
  const auth = safeAuth()
  return {
    client_uuid: (typeof crypto !== 'undefined' && crypto.randomUUID)
      ? crypto.randomUUID() : 'log-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10),
    level,
    message:    redact(truncate(message)),
    source:     truncate(source, 500),
    lineno:     lineno != null ? Number(lineno) : null,
    colno:      colno  != null ? Number(colno)  : null,
    stack:      redact(truncate(stack || (error?.stack ?? null))),
    error_name: truncate(error?.name || null, 120),
    error_message: redact(truncate(error?.message || null, 1000)),
    user_id:    auth?.id != null ? Number(auth.id) : null,
    role_key:   auth?.role_key || null,
    poe_code:   auth?.poe_code || null,
    session_id: getOrCreateSession(),
    device_id:  safeDeviceId(),
    app_version: safeAppVersion(),
    platform:   detectPlatform(),
    user_agent: typeof navigator !== 'undefined' ? truncate(navigator.userAgent, 500) : null,
    url:        typeof location !== 'undefined' ? truncate(location.href, 1000) : null,
    route:      typeof location !== 'undefined' ? (location.hash || location.pathname) : null,
    online:     typeof navigator !== 'undefined' ? !!navigator.onLine : true,
    breadcrumbs: _breadcrumbs.slice(),
    extra:      extra ? truncate(JSON.stringify(safe(extra)), 4000) : null,
    occurred_at: isoNow(),
  }
}

function detectPlatform() {
  try {
    const cap = (typeof window !== 'undefined' && window.Capacitor) || null
    if (cap?.getPlatform) {
      const p = cap.getPlatform()
      return p === 'web' ? 'WEB' : (p === 'android' ? 'ANDROID' : (p === 'ios' ? 'IOS' : 'WEB'))
    }
    return 'WEB'
  } catch { return 'WEB' }
}

// ── Public API ───────────────────────────────────────────────────────────
function _log(level, message, opts) {
  try {
    const r = buildRecord(level, message, opts)
    pushQueue(r)
    // Best-effort instant flush on ERROR/FATAL — don't wait for the timer.
    if (level === LEVELS.ERROR || level === LEVELS.FATAL) {
      flush().catch(() => {})
    }
  } catch (e) {
    // The logger itself must NEVER throw. Last-resort echo to console.
    if (typeof console !== 'undefined' && console.warn) {
      console.warn('[clientLogger] internal error while logging:', e?.message)
    }
  }
}

export const logger = Object.freeze({
  trace: (m, o) => _log(LEVELS.TRACE, m, o),
  debug: (m, o) => _log(LEVELS.DEBUG, m, o),
  info:  (m, o) => _log(LEVELS.INFO,  m, o),
  warn:  (m, o) => _log(LEVELS.WARN,  m, o),
  error: (m, o) => _log(LEVELS.ERROR, m, o),
  fatal: (m, o) => _log(LEVELS.FATAL, m, o),
  breadcrumb,
  flush: () => flush(),
  pendingCount: () => readQueue().length,
})

// ── Flusher ──────────────────────────────────────────────────────────────
// Endpoint discovery: POST to /client-logs once. If the server returns
// 404 we mark the endpoint as missing and DROP the queue forever in this
// session — no more 404 spam in the console while production catches up
// to the new route. Cleared on next page load.
let _flushing = false
let _endpointMissing = false
async function flush() {
  if (_flushing) return
  if (_endpointMissing) {
    // Endpoint isn't deployed — drop everything we've accumulated so we
    // don't keep growing localStorage, and DON'T retry.
    try { writeQueue([]) } catch {}
    return
  }
  if (typeof navigator !== 'undefined' && navigator.onLine === false) return
  const baseUrl = (typeof window !== 'undefined' && window.SERVER_URL) || ''
  if (!baseUrl) return
  const queue = readQueue()
  if (!queue.length) return
  _flushing = true
  try {
    const batch = queue.slice(0, FLUSH_BATCH_SIZE)
    const ctrl = new AbortController()
    const tid = setTimeout(() => ctrl.abort(), 8000)
    let ok = false
    let endpointMissing = false
    try {
      const res = await fetch(`${baseUrl}${ENDPOINT_PATH}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ records: batch }),
        signal: ctrl.signal,
        keepalive: true,
      })
      ok = res.ok
      // 404 / 405 / 410 = endpoint not deployed on this server. Stop forever.
      if (res.status === 404 || res.status === 405 || res.status === 410) {
        endpointMissing = true
      }
    } catch { /* network error — keep queue, retry next tick */ }
    finally { clearTimeout(tid) }
    if (endpointMissing) {
      _endpointMissing = true
      writeQueue([])
      // Stop the periodic timer so we don't keep waking up to do nothing.
      if (_flushTimer) { clearInterval(_flushTimer); _flushTimer = null }
      return
    }
    if (ok) {
      const remaining = queue.slice(batch.length)
      writeQueue(remaining)
    }
  } finally {
    _flushing = false
  }
}

// ── Console patches ──────────────────────────────────────────────────────
// We KEEP the original console.* behaviour so devs still see it in DevTools,
// but mirror error/warn into the queue. The patches are guarded so a recursive
// log from inside the logger never re-enters.
let _patching = false
function patchConsole() {
  const origError = console.error
  const origWarn  = console.warn
  console.error = function (...args) {
    if (!_patching) {
      _patching = true
      try {
        const msg = args.map(a => formatArg(a)).join(' ')
        const errArg = args.find(a => a instanceof Error)
        _log(LEVELS.ERROR, msg, { error: errArg, source: 'console.error' })
      } finally { _patching = false }
    }
    try { origError.apply(console, args) } catch {}
  }
  console.warn = function (...args) {
    if (!_patching) {
      _patching = true
      try {
        const msg = args.map(a => formatArg(a)).join(' ')
        const errArg = args.find(a => a instanceof Error)
        _log(LEVELS.WARN, msg, { error: errArg, source: 'console.warn' })
      } finally { _patching = false }
    }
    try { origWarn.apply(console, args) } catch {}
  }
}

function formatArg(a) {
  if (a instanceof Error) return `${a.name}: ${a.message}`
  if (typeof a === 'string') return a
  if (a == null) return String(a)
  try { return JSON.stringify(a) } catch { return String(a) }
}

// ── Install ──────────────────────────────────────────────────────────────
/**
 * Wire every error source. Idempotent. Call once at app boot.
 *
 * @param {object} [opts]
 * @param {import('vue').App} [opts.app] Vue 3 app — wires errorHandler / warnHandler
 */
export function install({ app } = {}) {
  if (_installed) return
  _installed = true

  // 1. window.onerror
  window.addEventListener('error', (ev) => {
    if (ev?.target && ev.target !== window && (ev.target.tagName === 'IMG' || ev.target.tagName === 'SCRIPT' || ev.target.tagName === 'LINK')) {
      // Resource load failure (404 image/script/css). Capture too — these
      // are often signs of a broken deployment.
      _log(LEVELS.WARN, `Resource load failed: ${ev.target.src || ev.target.href || ev.target.tagName}`, {
        source: 'resource',
      })
      return
    }
    const e = ev?.error
    _log(LEVELS.ERROR, ev?.message || 'window.onerror', {
      stack:  e?.stack || null,
      source: ev?.filename || 'window',
      lineno: ev?.lineno,
      colno:  ev?.colno,
      error:  e || null,
    })
  }, true)   // capture phase so resource errors bubble up

  // 2. unhandled promise rejection
  window.addEventListener('unhandledrejection', (ev) => {
    const r = ev?.reason
    const msg = r?.message || (typeof r === 'string' ? r : 'Unhandled rejection')
    _log(LEVELS.ERROR, msg, {
      stack:  r?.stack || null,
      source: 'unhandledrejection',
      error:  r instanceof Error ? r : null,
      extra:  r && typeof r === 'object' && !(r instanceof Error) ? r : null,
    })
  })

  // 3 + 4. Vue handlers — only if an app instance is supplied
  if (app && app.config) {
    app.config.errorHandler = (err, instance, info) => {
      _log(LEVELS.ERROR, err?.message || String(err) || 'Vue errorHandler', {
        stack:  err?.stack,
        source: 'vue.errorHandler:' + (info || ''),
        error:  err instanceof Error ? err : null,
        extra:  { component_name: instance?.$options?.name || instance?.type?.name || null },
      })
    }
    // warnHandler is dev-only; no-op in production builds, but harmless to set.
    app.config.warnHandler = (msg, _instance, trace) => {
      _log(LEVELS.WARN, msg, { source: 'vue.warnHandler', stack: trace })
    }
  }

  // 5 + 6. Patch console.error / console.warn
  patchConsole()

  // 7. Fetch wrapper — capture network errors and non-2xx 5xx responses.
  // We DON'T replace fetch; we wrap it. If httpInterceptor.js already wraps
  // fetch, we simply layer on top — the wrapping is associative.
  if (typeof window.fetch === 'function') {
    const origFetch = window.fetch.bind(window)
    window.fetch = async function (input, init) {
      const startedAt = Date.now()
      const url = typeof input === 'string' ? input : (input?.url || '')
      // NEVER instrument the logger's own POST or we'll loop forever.
      const isOurOwnPost = url.includes(ENDPOINT_PATH)
      try {
        const res = await origFetch(input, init)
        if (!isOurOwnPost && res && res.status >= 500) {
          _log(LEVELS.ERROR, `HTTP ${res.status} ${url}`, {
            source: 'fetch',
            extra: { status: res.status, ms: Date.now() - startedAt, method: (init?.method || 'GET').toUpperCase() },
          })
        }
        return res
      } catch (err) {
        if (!isOurOwnPost) {
          _log(LEVELS.ERROR, `Network error: ${err?.message || err} (${url})`, {
            source: 'fetch',
            error: err,
            extra: { ms: Date.now() - startedAt, method: (init?.method || 'GET').toUpperCase() },
          })
        }
        throw err
      }
    }
  }

  // 8. Periodic flush + boot ping
  if (_flushTimer) clearInterval(_flushTimer)
  _flushTimer = setInterval(() => { flush().catch(() => {}) }, FLUSH_INTERVAL_MS)
  setInterval(() => { _log(LEVELS.INFO, 'heartbeat', { source: 'logger.heartbeat' }) }, HEARTBEAT_MS)
  window.addEventListener('online', () => { flush().catch(() => {}) })
  window.addEventListener('beforeunload', () => { flush().catch(() => {}) })
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') flush().catch(() => {})
  })

  // Boot record so admins see the app launching
  _log(LEVELS.INFO, 'client logger installed', { source: 'logger.install' })
}

// Auto-install removed 2026-05-05 — this module is now opt-in. Callers
// must explicitly invoke install({ app }) for the global handlers, fetch
// wrapper, and periodic flush to start. Importing this file alone is a
// no-op so dead-code paths cannot trigger the 404-spamming flush loop.

export default { install, logger, breadcrumb }
