/**
 * src/i18n/translateService.js — enterprise-grade translation pipeline.
 *
 * Lookup chain for every t(key) call:
 *   1. Static dictionary (fr.js / pt.js) — instant, offline-safe
 *   2. localStorage cache (`ug_poe_t_cache_<lang>`) — instant, offline-safe
 *   3. Online translation provider — Google Cloud Translation v2 if a key is
 *      configured, else LibreTranslate (free, public). Result cached.
 *   4. English fallback + queued retry — if offline AND not in cache.
 *
 * Design rules
 *   • The synchronous `t(key)` function ALWAYS returns immediately. It never
 *     blocks on the network. If a translation isn't yet known we return
 *     English and asynchronously schedule a fetch — when the response lands
 *     we write it into the cache AND re-emit a `i18n:translation-ready`
 *     window event so reactive views can re-render.
 *   • Online detection is `navigator.onLine` + a 5-second probe to the
 *     provider every 60 s while the queue has work. Failures backoff
 *     exponentially capped at 5 minutes.
 *   • Offline notify: when the queue accumulates ≥ 1 untranslated key AND
 *     navigator.onLine === false, we dispatch ONE `i18n:offline-notice`
 *     event so the host UI can show a single toast (not one per key).
 *   • Cache integrity: every entry is `{v: 1, t: <translated>, src: 'cache'}`
 *     so future schema changes can invalidate cleanly.
 *   • Privacy: we send ONLY the source phrase. Never user data.
 *   • Bounded growth: cache capped at 5,000 keys per language; oldest
 *     evicted by LRU.
 */

import { ref } from 'vue'
import FR from './fr.js'
import PT from './pt.js'

// ─── Configuration ────────────────────────────────────────────────────────
const SUPPORTED = ['en', 'fr', 'pt']
const STATIC_DICTS = { en: null, fr: FR || {}, pt: PT || {} }
const CACHE_KEY = lang => `ug_poe_t_cache_${lang}`
const META_KEY  = 'ug_poe_t_meta'                    // global meta (queue, last-online)
const CACHE_CAP = 5000                                // max keys per language
const PROBE_INTERVAL_MS = 60_000
const PROBE_TIMEOUT_MS  = 5_000
const QUEUE_DEBOUNCE_MS = 400                         // batch translate calls
const RETRY_BACKOFF_BASE_MS = 30_000
const RETRY_BACKOFF_CAP_MS  = 5 * 60_000

// Provider config — settable from window before app boot.
//   window.GOOGLE_TRANSLATE_API_KEY = 'AIza...'   (preferred when present)
//   window.LIBRETRANSLATE_URL       = 'https://libretranslate.com'   (default)
function _googleKey()      { try { return (typeof window !== 'undefined' && window.GOOGLE_TRANSLATE_API_KEY) || null } catch { return null } }
function _libreUrl()       { try { return (typeof window !== 'undefined' && window.LIBRETRANSLATE_URL) || 'https://libretranslate.com' } catch { return 'https://libretranslate.com' } }

// ─── Reactive state ───────────────────────────────────────────────────────
export const isOnline       = ref(typeof navigator === 'undefined' ? true : navigator.onLine !== false)
export const lastTranslateError = ref(null)
export const pendingCount   = ref(0)

// In-memory caches mirroring localStorage for sync reads.
const _cache = { en: {}, fr: {}, pt: {} }
function _loadCache(lang) {
  try {
    const raw = localStorage.getItem(CACHE_KEY(lang))
    if (!raw) return
    const parsed = JSON.parse(raw)
    if (parsed && typeof parsed === 'object') _cache[lang] = parsed
  } catch {}
}
function _persistCache(lang) {
  try {
    let entries = Object.entries(_cache[lang] || {})
    if (entries.length > CACHE_CAP) {
      // LRU eviction — keep most recently touched
      entries.sort((a, b) => (b[1]?.ts || 0) - (a[1]?.ts || 0))
      entries = entries.slice(0, CACHE_CAP)
      _cache[lang] = Object.fromEntries(entries)
    }
    localStorage.setItem(CACHE_KEY(lang), JSON.stringify(_cache[lang]))
  } catch {
    // Quota exceeded — keep half
    try {
      const entries = Object.entries(_cache[lang] || {}).sort((a, b) => (b[1]?.ts || 0) - (a[1]?.ts || 0))
      _cache[lang] = Object.fromEntries(entries.slice(0, Math.floor(CACHE_CAP / 2)))
      localStorage.setItem(CACHE_KEY(lang), JSON.stringify(_cache[lang]))
    } catch {}
  }
}
SUPPORTED.forEach(_loadCache)

// ─── Sync lookup (no network) ─────────────────────────────────────────────
/**
 * Synchronous translation lookup. Always returns a string. If translation
 * is unknown, returns the English source and queues an async fetch.
 *
 * @param {string} key   English source label (canonical)
 * @param {string} lang  Target language (en/fr/pt)
 * @returns {string}
 */
export function tSync(key, lang) {
  if (!key && key !== '') return ''
  if (lang === 'en' || !SUPPORTED.includes(lang)) return key

  const dict = STATIC_DICTS[lang]
  if (dict && Object.prototype.hasOwnProperty.call(dict, key)) {
    return dict[key]
  }

  const cached = _cache[lang]?.[key]
  if (cached?.t) {
    // Touch LRU timestamp without rewriting localStorage on every read
    cached.ts = Date.now()
    return cached.t
  }

  // Unknown — schedule async fetch and return English meanwhile
  _queueTranslate(key, lang)
  return key
}

// ─── Async queue (debounced batch, persistent across reloads) ────────────
const _queue = new Map()    // `${lang}::${key}` → { lang, key }
const QUEUE_LS_KEY = 'ug_poe_t_queue'
let   _flushTimer = null
let   _failureStreak = 0
let   _lastFailureAt = 0

// Hydrate any queue that was persisted before the last reload — so a user
// who flipped a phrase to a non-English language and was offline doesn't
// lose pending translations across page refreshes / app restarts.
try {
  const raw = (typeof localStorage !== 'undefined') ? localStorage.getItem(QUEUE_LS_KEY) : null
  if (raw) {
    const arr = JSON.parse(raw)
    if (Array.isArray(arr)) {
      for (const item of arr) {
        if (item?.lang && item?.key && SUPPORTED.includes(item.lang)) {
          _queue.set(`${item.lang}::${item.key}`, item)
        }
      }
    }
  }
} catch {}

function _persistQueue() {
  try {
    if (typeof localStorage === 'undefined') return
    if (_queue.size === 0) localStorage.removeItem(QUEUE_LS_KEY)
    else localStorage.setItem(QUEUE_LS_KEY, JSON.stringify([..._queue.values()]))
  } catch {}
}

function _queueTranslate(key, lang) {
  const id = `${lang}::${key}`
  if (_queue.has(id)) return
  if (_cache[lang]?.[key]?.t) return    // already cached
  _queue.set(id, { key, lang })
  pendingCount.value = _queue.size
  _persistQueue()

  // Schedule a flush. If offline we still queue but will retry on `online`.
  if (_flushTimer) clearTimeout(_flushTimer)
  _flushTimer = setTimeout(() => _flush().catch(() => {}), QUEUE_DEBOUNCE_MS)

  // Surface offline notice exactly once.
  if (!isOnline.value) _emitOfflineNotice()
}

let _offlineNoticeShown = false
function _emitOfflineNotice() {
  if (_offlineNoticeShown) return
  _offlineNoticeShown = true
  try {
    window.dispatchEvent(new CustomEvent('i18n:offline-notice', {
      detail: { pending: _queue.size, message: 'Some translations need internet — showing English until back online.' },
    }))
  } catch {}
}

async function _flush() {
  if (!_queue.size) return
  if (!isOnline.value) return
  // Backoff if recent failure
  const since = Date.now() - _lastFailureAt
  const backoff = Math.min(RETRY_BACKOFF_BASE_MS * Math.pow(2, Math.min(_failureStreak, 5)), RETRY_BACKOFF_CAP_MS)
  if (_failureStreak > 0 && since < backoff) {
    setTimeout(() => _flush().catch(() => {}), backoff - since)
    return
  }

  // Group by language for batch endpoints
  const byLang = {}
  for (const item of _queue.values()) {
    (byLang[item.lang] ||= []).push(item.key)
  }

  for (const [lang, keys] of Object.entries(byLang)) {
    try {
      const translated = await _translateBatch(keys, lang)
      for (let i = 0; i < keys.length; i++) {
        const k = keys[i]
        const v = translated[i]
        if (v && typeof v === 'string') {
          _cache[lang][k] = { v: 1, t: v, ts: Date.now(), src: 'online' }
          _queue.delete(`${lang}::${k}`)
        }
      }
      _persistCache(lang)
      _failureStreak = 0
      lastTranslateError.value = null
      // Tell reactive views to re-evaluate t().
      try {
        window.dispatchEvent(new CustomEvent('i18n:translation-ready', {
          detail: { lang, count: keys.length },
        }))
      } catch {}
    } catch (err) {
      _failureStreak++
      _lastFailureAt = Date.now()
      lastTranslateError.value = err?.message || 'translate failed'
      // Don't drain; keys remain in _queue for retry
      break
    }
  }
  pendingCount.value = _queue.size
  _persistQueue()
  if (_queue.size === 0) _offlineNoticeShown = false
}

async function _translateBatch(keys, lang) {
  // 1. Google if API key configured
  const gKey = _googleKey()
  if (gKey) return _translateGoogle(keys, lang, gKey)
  // 2. LibreTranslate fallback (free)
  return _translateLibre(keys, lang)
}

async function _translateGoogle(keys, lang, apiKey) {
  const url = `https://translation.googleapis.com/language/translate/v2?key=${encodeURIComponent(apiKey)}`
  const ctrl = new AbortController()
  const tid = setTimeout(() => ctrl.abort(), 12_000)
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ q: keys, source: 'en', target: lang, format: 'text' }),
      signal: ctrl.signal,
    })
    if (!res.ok) throw new Error(`google ${res.status}`)
    const body = await res.json()
    const translations = body?.data?.translations || []
    return keys.map((_, i) => translations[i]?.translatedText || null)
  } finally {
    clearTimeout(tid)
  }
}

async function _translateLibre(keys, lang) {
  const url = _libreUrl().replace(/\/+$/, '') + '/translate'
  const ctrl = new AbortController()
  const tid = setTimeout(() => ctrl.abort(), 12_000)
  try {
    // Libre's batch endpoint accepts `q: string[]`.
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ q: keys, source: 'en', target: lang, format: 'text' }),
      signal: ctrl.signal,
    })
    if (!res.ok) throw new Error(`libre ${res.status}`)
    const body = await res.json()
    if (Array.isArray(body)) return body.map(b => b?.translatedText || null)
    if (body?.translatedText) {
      // Single response — should not happen with array input but be defensive
      return keys.map((_, i) => i === 0 ? body.translatedText : null)
    }
    return keys.map(() => null)
  } finally {
    clearTimeout(tid)
  }
}

// ─── Connectivity tracking ────────────────────────────────────────────────
function _setOnline(v) {
  if (isOnline.value === v) return
  isOnline.value = v
  if (v) {
    _offlineNoticeShown = false
    // Drain anything that queued while offline
    setTimeout(() => _flush().catch(() => {}), 250)
  }
}
if (typeof window !== 'undefined') {
  window.addEventListener('online',  () => _setOnline(true))
  window.addEventListener('offline', () => _setOnline(false))
  // Periodic probe — even without `online` event firing on flaky networks
  setInterval(async () => {
    if (!_queue.size) return
    try {
      const ctrl = new AbortController()
      const tid = setTimeout(() => ctrl.abort(), PROBE_TIMEOUT_MS)
      const url = _googleKey()
        ? 'https://translation.googleapis.com/'
        : _libreUrl().replace(/\/+$/, '') + '/'
      const res = await fetch(url, { method: 'HEAD', mode: 'no-cors', signal: ctrl.signal })
      clearTimeout(tid)
      _setOnline(true)
      _flush().catch(() => {})
    } catch {
      _setOnline(navigator.onLine !== false)
    }
  }, PROBE_INTERVAL_MS)
}

// ─── Public maintenance API ───────────────────────────────────────────────
export function clearLanguageCache(lang) {
  if (!SUPPORTED.includes(lang)) return
  _cache[lang] = {}
  try { localStorage.removeItem(CACHE_KEY(lang)) } catch {}
}
export function cacheSize(lang) { return Object.keys(_cache[lang] || {}).length }

export default { tSync, isOnline, lastTranslateError, pendingCount, clearLanguageCache, cacheSize }
