/**
 * alertOutbox.js — offline-first write queue for alert lifecycle actions.
 *
 * Every write the war-room initiates (acknowledge / close / reassign /
 * escalate / reopen / followup-update / blocker-resolve / outcome / breach /
 * comment / evidence) routes through here. Online: posts immediately. Offline
 * (or transient 5xx): persists to localStorage and the operation surfaces in
 * `useOutbox()` so the UI can show a "X queued" indicator. When the
 * network comes back, `flushOutbox()` drains the queue oldest-first.
 *
 * Why localStorage over IndexedDB: the queue is tiny (≤200 ops × ≤1KB each),
 * adding a new IDB store requires a schema migration in poeDB.js, and we want
 * synchronous reads for the queue badge. localStorage hits ~5MB cap.
 *
 * Schema (one entry per queued op):
 *   { id, queued_at, attempts, last_error,
 *     method, path, body,                  // raw HTTP shape
 *     kind,                                // semantic key for UI: 'close', 'ack', …
 *     alert_id, optimistic                 // mirror payload UI applied locally
 *   }
 */

import { ref, computed } from 'vue'

const KEY = 'ALERT_OUTBOX_V1'
// Mandate 2026-05-06: NO retry cap. Operations stay in the queue until they
// succeed or the user explicitly drops them. The previous MAX_ATTEMPTS=6
// silently dropped war-room actions on weak networks.
const MAX_ATTEMPTS = Number.POSITIVE_INFINITY
const BASE_BACKOFF_MS = 2000
const MAX_BACKOFF_MS  = 60_000

const _queue = ref(load())

function load() {
  try {
    const raw = localStorage.getItem(KEY)
    if (!raw) return []
    const parsed = JSON.parse(raw)
    return Array.isArray(parsed) ? parsed : []
  } catch { return [] }
}

function persist() {
  try { localStorage.setItem(KEY, JSON.stringify(_queue.value)) } catch {}
}

if (typeof window !== 'undefined') {
  window.addEventListener('storage', (e) => { if (e.key === KEY) _queue.value = load() })
}

export function useOutbox() {
  return {
    queue: _queue,
    pending: computed(() => _queue.value.length),
    pendingFor: (alertId) => computed(() => _queue.value.filter(o => o.alert_id === Number(alertId)).length),
    hasPending: computed(() => _queue.value.length > 0),
  }
}

export function enqueue(op) {
  const entry = {
    id: (typeof crypto !== 'undefined' && crypto.randomUUID) ? crypto.randomUUID() : ('op-' + Date.now() + '-' + Math.random().toString(36).slice(2)),
    queued_at: new Date().toISOString(),
    attempts: 0,
    last_error: null,
    ...op,
  }
  _queue.value = [..._queue.value, entry]
  persist()
  return entry
}

export function dropOp(id) {
  _queue.value = _queue.value.filter(o => o.id !== id)
  persist()
}

/**
 * Best-effort flush. `httpSend(op)` must return { ok, status, body }.
 * Returns { sent, failed, queued }.
 *
 * - Success → drop the entry, advance.
 * - 4xx (validation / auth) → drop the entry but record the failure on the
 *   item so the UI can surface "this op was rejected" — retrying it will
 *   not change the outcome.
 * - 5xx / 0 (network) → keep the entry, increment attempts. Stop on first
 *   network error to avoid hammering. Schedule a retry.
 */
export async function flushOutbox(httpSend) {
  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    return { sent: 0, failed: 0, queued: _queue.value.length, skipped: 'offline' }
  }
  let sent = 0
  let failed = 0
  // Snapshot — mutate via persisted helpers below.
  const snapshot = [..._queue.value]
  for (const op of snapshot) {
    // Mandate 2026-05-06: never drop. 4xx may resolve once the dependency it
    // was waiting on (e.g. parent secondary case) lands on the server, so we
    // keep retrying every op indefinitely. Backoff in setupAutoFlush() prevents
    // hammering. The `dead` flag is preserved on existing queue entries for
    // backward compatibility with the UI but we no longer set it here.
    if ((op?.attempts || 0) >= MAX_ATTEMPTS) continue
    try {
      op.attempts = (op.attempts || 0) + 1
      const res = await httpSend(op)
      if (res && res.ok) {
        dropOp(op.id)
        sent++
        continue
      }
      const status = res?.status || 0
      // Any non-success keeps the op in the queue. Surface the latest error so
      // UI can show "still trying — last error: ...". Keep going through the
      // snapshot so a single failing op cannot stall everything else.
      op.last_error = (res?.body?.message || `HTTP ${status || 'network'}`).slice(0, 240)
      // Clear the legacy dead flag if it was set by a previous build — without
      // this, ops queued under the old logic stay dead forever.
      if (op.dead) op.dead = false
      replaceOp(op)
      failed++
    } catch (e) {
      op.last_error = String(e?.message || e).slice(0, 240)
      if (op.dead) op.dead = false
      replaceOp(op)
      failed++
    }
  }
  return { sent, failed, queued: _queue.value.length }
}

function replaceOp(op) {
  const idx = _queue.value.findIndex(o => o.id === op.id)
  if (idx >= 0) {
    const next = [..._queue.value]
    next[idx] = { ...op }
    _queue.value = next
    persist()
  }
}

/** Drop dead ops after the UI has shown them. */
export function clearDead() {
  const next = _queue.value.filter(o => !o.dead)
  if (next.length !== _queue.value.length) {
    _queue.value = next
    persist()
  }
}

/** Drop everything for a specific alert (e.g. when it is closed and we want
 *  the user to start fresh on a re-opened action). */
export function clearForAlert(alertId) {
  const aid = Number(alertId)
  _queue.value = _queue.value.filter(o => Number(o.alert_id) !== aid)
  persist()
}

let _flushTimer = null
let _flushFn = null
export function setupAutoFlush(httpSend) {
  _flushFn = httpSend
  if (typeof window === 'undefined') return
  const tick = async () => {
    if (!_flushFn || !navigator.onLine || _queue.value.length === 0) return
    // Pick the op with the fewest attempts as the scheduling anchor. With
    // MAX_ATTEMPTS=Infinity nothing is "dead", so the queue is always live.
    // Old `dead` entries are unstuck by flushOutbox on the next pass.
    const live = _queue.value.reduce(
      (acc, o) => (acc == null || (o?.attempts || 0) < (acc?.attempts || 0) ? o : acc),
      null,
    )
    if (!live) return
    const wait = Math.min(BASE_BACKOFF_MS * Math.pow(2, Math.min(live.attempts || 0, 5)), MAX_BACKOFF_MS)
    if (_flushTimer) return
    _flushTimer = setTimeout(async () => {
      _flushTimer = null
      try { await flushOutbox(_flushFn) } catch {}
      tick()
    }, wait)
  }
  window.addEventListener('online', () => { setTimeout(() => flushOutbox(_flushFn).then(tick), 250) })
  // Also retry on visibility change (returning to app from background).
  document?.addEventListener?.('visibilitychange', () => {
    if (document.visibilityState === 'visible') flushOutbox(_flushFn).then(tick).catch(()=>{})
  })
  tick()
}
