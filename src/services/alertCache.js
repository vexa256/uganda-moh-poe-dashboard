/**
 * alertCache.js — local cache for war-room reads, LRU-bounded.
 *
 * Caches the GET /alerts/{id}/case-file payload (and a list of recent alerts)
 * so the war room opens instantly even when the user is offline. Each entry
 * carries a `cached_at` timestamp; consumers decide whether to use it as
 * stale-while-revalidate or render-only-when-fresh.
 *
 * Storage: localStorage, capped to 25 alert payloads (LRU). Roughly
 * 1.5 MB worst case — comfortable inside the 5 MB localStorage budget.
 */

const KEY_PREFIX = 'AWR_CACHE_'
const INDEX_KEY  = 'AWR_CACHE_INDEX_V1'
const MAX_ENTRIES = 25

function readIndex() {
  try {
    const raw = localStorage.getItem(INDEX_KEY)
    if (!raw) return []
    const parsed = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed : []
  } catch { return [] }
}

function writeIndex(ids) {
  try { localStorage.setItem(INDEX_KEY, JSON.stringify(ids)) } catch {}
}

/** Returns { data, cached_at } or null. */
export function readCaseFile(alertId) {
  const id = Number(alertId)
  if (!id) return null
  try {
    const raw = localStorage.getItem(KEY_PREFIX + id)
    if (!raw) return null
    return JSON.parse(raw)
  } catch { return null }
}

export function writeCaseFile(alertId, data) {
  const id = Number(alertId)
  if (!id || !data) return
  const entry = { data, cached_at: new Date().toISOString() }
  try {
    localStorage.setItem(KEY_PREFIX + id, JSON.stringify(entry))
    let idx = readIndex().filter(x => x !== id)
    idx.unshift(id)
    if (idx.length > MAX_ENTRIES) {
      const drop = idx.slice(MAX_ENTRIES)
      drop.forEach(d => { try { localStorage.removeItem(KEY_PREFIX + d) } catch {} })
      idx = idx.slice(0, MAX_ENTRIES)
    }
    writeIndex(idx)
  } catch (e) {
    // Quota — best-effort eviction
    try {
      const idx = readIndex()
      idx.slice(-5).forEach(d => { try { localStorage.removeItem(KEY_PREFIX + d) } catch {} })
      writeIndex(idx.slice(0, -5))
      localStorage.setItem(KEY_PREFIX + id, JSON.stringify(entry))
    } catch {}
  }
}

export function dropCaseFile(alertId) {
  const id = Number(alertId)
  if (!id) return
  try { localStorage.removeItem(KEY_PREFIX + id) } catch {}
  writeIndex(readIndex().filter(x => x !== id))
}

export function listCachedAlerts() {
  return readIndex().map(id => {
    const e = readCaseFile(id)
    if (!e) return null
    return { id, cached_at: e.cached_at, alert: e.data?.alert || null }
  }).filter(Boolean)
}

/** Apply an optimistic patch to the cached alert head so the war-room
 *  reflects the user's action even before the server round-trip lands. */
export function patchAlertHead(alertId, patch) {
  const e = readCaseFile(alertId)
  if (!e?.data?.alert) return
  e.data.alert = { ...e.data.alert, ...patch }
  writeCaseFile(alertId, e.data)
}

/** Append a synthetic timeline event optimistically (so user sees their
 *  action immediately even when offline). Marked `pending: true` so the
 *  UI can render it muted until the real event arrives. */
export function appendOptimisticTimeline(alertId, event) {
  const e = readCaseFile(alertId)
  if (!e?.data) return
  e.data.timeline = [{
    id: 'pending-' + Date.now(),
    pending: true,
    created_at: new Date().toISOString(),
    severity: 'INFO',
    event_category: 'WORKFLOW',
    ...event,
  }, ...(e.data.timeline || [])]
  writeCaseFile(alertId, e.data)
}
