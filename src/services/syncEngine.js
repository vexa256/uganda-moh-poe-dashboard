/**
 * services/syncEngine.js — UNIFIED, IDEMPOTENT, REDUNDANT SYNC ENGINE
 *
 *  Mandate (2026-05-06):
 *    1. Primary screenings, secondary screenings, notifications, alerts and
 *       follow-ups MUST reach the server without any user intervention.
 *    2. Records MUST NEVER be quarantined, dropped, or marked permanently
 *       FAILED. Every transient failure is retried with bounded backoff.
 *    3. Every sync operation MUST be idempotent. The server keys all writes
 *       on `client_uuid`, so a re-POST of a record that already exists
 *       is a safe no-op. The engine relies on this guarantee throughout.
 *    4. Sync runs on every meaningful trigger: app boot, online, visibility,
 *       focus, pageshow, Capacitor App.resume, post-write kick, periodic
 *       interval (10s). Sync Center is a redundant observability layer —
 *       NOT the primary sync mechanism.
 *
 *  Topological order (parents before children — server FK resolves cleanly):
 *
 *     primary_screenings        (server auto-creates linked notification)
 *       └─ notifications        (id propagated from primary response)
 *           └─ secondary_screenings  Phase 1 (skeleton create)
 *                └─ secondary_screenings Phase 2 (disposition + child tables)
 *                     └─ alerts
 *                          └─ alert_followups
 *
 *  IDEMPOTENCY MODEL
 *
 *    Each store uses two version fields:
 *      • record_version              — bumped on every local edit
 *      • last_synced_record_version  — engine sets after a successful push
 *    A record needs sync iff record_version > (last_synced_record_version || 0).
 *    Re-pushing an already-up-to-date record is short-circuited locally; even
 *    if it somehow runs, the server's client_uuid idempotency makes it a
 *    safe no-op.
 *
 *    Phase-2 detection for secondary cases:
 *      • Phase 1 success     → server_id stamped, sync_status stays UNSYNCED.
 *      • Phase 2 success     → sync_status = SYNCED + last_synced_record_version
 *                              catches up to record_version.
 *      • Local edits         → safeDbPut bumps record_version, the engine sees
 *                              it on the next pass and re-runs Phase 2.
 *
 *  LEGACY DATA RE-ARM
 *
 *    On boot the engine sweeps every store and:
 *      • Flips any FAILED record back to UNSYNCED (no terminal failure state).
 *      • Re-arms any SYNCED record whose last_synced_record_version is missing
 *        or behind record_version, so half-synced cases from the old worker
 *        get a Phase-2 retry the next pass.
 *      • Clears `sync_retryable: false` flags from older builds.
 */

import {
  dbGetAll, dbGetByIndex, dbGet, safeDbPut, getPoeDB, STORE_KEY,
  STORE, SYNC, APP,
} from '@/services/poeDB'
// 2026-05-06: All IDB metadata stamps (synced_at / updated_at /
// last_sync_attempt_at) use server-corrected time so devices with a
// wrong clock still produce coherent timestamps in the /sync diagnostics
// view and in any view that compares local timestamps to server ones.
import { serverIsoNow } from '@/services/serverTime'

// ────────────────────────────────────────────────────────────────────────────
// Tunables
// ────────────────────────────────────────────────────────────────────────────

// Mandate 2026-05-06: while the app is open, sync is continuous — the user
// should never have to wait for a long poll. The heartbeat fires every 5s
// when the tab is visible, and a longer interval (15s) when hidden so
// background syncing still happens for backgrounded tabs without burning
// battery. Every kick is debounced so a flurry of triggers coalesces into
// one flush.
const POLL_MS_VISIBLE      = 5_000    // periodic flush when tab visible
const POLL_MS_HIDDEN       = 15_000   // periodic flush when tab hidden
const KICK_DEBOUNCE_MS     = 350      // post-write coalescing
// Network-error / soft backoff: short, exponential, capped at 60 s.
const PER_RECORD_BACKOFF   = [1000, 2000, 4000, 8000, 16_000, 32_000, 60_000]
// 4xx persistence backoff: when the same record returns 4xx repeatedly the
// server is rejecting it for a stable reason (stale FK, invalid state, etc.).
// Retrying every 60 s spams the console and the server. We cap at 10 minutes
// instead so the record stays in the queue (per "no records lost" mandate)
// but doesn't burn cycles. Reset on first success or when the user clicks
// "Reset stale records" in Sync Center.
const HARD_4XX_BACKOFF_MS  = 180_000  // 3 minutes — aggressive but not silent
// 4xx is a STABLE rejection — the server says no for a reason that won't
// change in the next minute (FK missing, version stale, validation, scope).
// Aggressive: a SINGLE 4xx triggers the 3-min backoff. Records auto-recover
// when the underlying dependency lands (e.g. parent primary syncs). Reset on
// first success or via Sync Center's "Reset Stale" button.
const HARD_4XX_TRIGGER     = 1
const HTTP_TIMEOUT_MS      = APP.SYNC_TIMEOUT_MS || 12_000
const TAG                  = '[SYNC-ENGINE]'
// On `online` we fire a burst — same kick repeated three times — so a slow
// Capacitor handshake or DNS warm-up doesn't leave the first attempt wasted.
const ONLINE_BURST_MS      = [250, 2_500, 8_000]

// ────────────────────────────────────────────────────────────────────────────
// State
// ────────────────────────────────────────────────────────────────────────────

let _started      = false
let _flushing     = false
let _kickTimer    = null
const _inFlight   = new Set()           // "<uuid>:<phase>" keys currently in-flight
const _recentEvents = []                // ring buffer for SyncCenter live view
const RECENT_MAX  = 200

const _listeners  = new Set()

function pushEvent(event, payload) {
  const e = { event, payload, at: serverIsoNow() }
  _recentEvents.push(e)
  if (_recentEvents.length > RECENT_MAX) _recentEvents.shift()
  for (const fn of _listeners) {
    try { fn(event, payload) } catch { /* listener must not break engine */ }
  }
  if (typeof window !== 'undefined') {
    try { window.dispatchEvent(new CustomEvent('sync-engine:' + event, { detail: payload })) } catch {}
  }
}

export function onSyncEvent(fn) { _listeners.add(fn); return () => _listeners.delete(fn) }
export function recentSyncEvents() { return _recentEvents.slice() }

function log(level, msg, data) {
  const fn = level === 'warn' ? console.warn : level === 'error' ? console.error : console.log
  if (data !== undefined) fn(TAG, msg, data)
  else fn(TAG, msg)
}

function backoffMs(attempts) {
  const i = Math.max(0, Math.min(attempts, PER_RECORD_BACKOFF.length - 1))
  return PER_RECORD_BACKOFF[i]
}

function shouldDeferByBackoff(record) {
  const last = record.last_sync_attempt_at ? new Date(record.last_sync_attempt_at).getTime() : 0
  if (!last) return false
  // If the record has been 4xx-failing repeatedly, use the long cap so the
  // engine doesn't spam the server every minute. The streak is reset to 0
  // on any successful push (markSynced) or when the user clicks the
  // "Reset stale" action in Sync Center.
  const streak = record._4xx_streak || 0
  const wait = streak >= HARD_4XX_TRIGGER
    ? HARD_4XX_BACKOFF_MS
    : backoffMs(record.sync_attempt_count || 0)
  return (Date.now() - last) < wait
}

function isUpToDate(record) {
  // A record is considered fully synced iff sync_status === SYNCED AND
  // last_synced_record_version has caught up to record_version. This is the
  // single source of truth for "no work to do" across all stores.
  if (record.sync_status !== SYNC.SYNCED) return false
  const v = record.record_version || 0
  const sv = record.last_synced_record_version || 0
  return sv >= v
}

// ────────────────────────────────────────────────────────────────────────────
// TENANT SCOPE GUARD — refuse to push cross-tenant records
// ────────────────────────────────────────────────────────────────────────────
//
// IndexedDB persists across deployments — a device that previously ran a
// different country build can carry foreign rows into this Uganda (UG) install.
// Those foreign rows have a non-UG country_code and reference user/POE/case
// ids that do not exist on the Uganda server. Pushing them produces
// 403/404/422 noise and pollutes the Sync Center.
//
// The engine reads the current AUTH_DATA from sessionStorage and refuses to
// push any record whose country_code is set AND differs from the user's
// country. Records with no country_code at all (very old, pre-tagging) are
// still attempted — they may be legitimately Ugandan with missing metadata.

function getCurrentAuth() {
  try {
    if (typeof sessionStorage === 'undefined') return null
    const raw = sessionStorage.getItem('AUTH_DATA')
    if (!raw) return null
    return JSON.parse(raw)
  } catch { return null }
}

function inScope(record) {
  const auth = getCurrentAuth()
  // No active session → defer all pushes; we have no way to scope-check.
  if (!auth || !auth.id) return false
  // Country mismatch is the only reliable cross-tenant signal we have.
  // Records with no country_code (legacy) are passed through.
  const myCountry = (auth.country_code || '').toUpperCase()
  const recCountry = (record.country_code || '').toString().toUpperCase()
  if (myCountry && recCountry && recCountry !== myCountry &&
      // Long-form country name was used as a legacy value before code migration.
      !(myCountry === 'UG' && recCountry === 'UGANDA')) {
    return false
  }
  return true
}

// ────────────────────────────────────────────────────────────────────────────
// HTTP primitives
// ────────────────────────────────────────────────────────────────────────────

function serverUrl() {
  return (typeof window !== 'undefined' && window.SERVER_URL) || ''
}

async function postJSON(url, payload, timeoutMs = HTTP_TIMEOUT_MS) {
  const ctrl = new AbortController()
  const tid  = setTimeout(() => ctrl.abort(), timeoutMs)
  try {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(payload),
      signal: ctrl.signal,
    })
    const body = await res.json().catch(() => ({}))
    if (!res.ok) {
      // Quiet mode: events still flow to Sync Center's live stream + the
      // optional verbose console output is gated behind window.__SYNC_VERBOSE__.
      // Set `window.__SYNC_VERBOSE__ = true` in console to re-enable detailed
      // logging when you want to diagnose a specific failure.
      const summary = {
        url, status: res.status,
        message: body?.message || 'no message',
        validation: body?.error?.validation_errors || body?.error || null,
        client_uuid: payload?.client_uuid,
      }
      // Verbose mode is ON by default. Disable with `window.__SYNC_VERBOSE__ = false`
      // when iterating on the UI and the noise gets in the way.
      const verbose = typeof window === 'undefined' || window.__SYNC_VERBOSE__ !== false
      if (verbose) {
        log('warn', `HTTP ${res.status} from ${url}`, { summary, response_body: body, sent_payload: payload })
      }
      pushEvent('http-error', summary)
    }
    return { ok: res.ok, status: res.status, body }
  } catch (e) {
    const msg = e?.name === 'AbortError' ? 'timeout' : (e?.message || 'network')
    const verbose = typeof window === 'undefined' || window.__SYNC_VERBOSE__ !== false
    if (verbose) {
      log('warn', `network error to ${url}`, { msg, sent_payload: payload })
    }
    pushEvent('http-network-error', { url, msg, client_uuid: payload?.client_uuid })
    return { ok: false, status: 0, body: { message: msg } }
  } finally {
    clearTimeout(tid)
  }
}

// ────────────────────────────────────────────────────────────────────────────
// TRANSACTIONAL COMPARE-AND-SWAP — closes the read-then-write race window
// ────────────────────────────────────────────────────────────────────────────
//
// IDB readwrite transactions are atomic and isolated: a read inside the tx
// followed by a put inside the same tx cannot interleave with any other
// transaction touching the same store. We use this to read the current
// record, run a patcher function, and conditionally write — all atomically.
//
// `casUpdate` returns `{ ok }` on success, `{ aborted: 'no-record' }` if the
// row was deleted, or `{ aborted: 'concurrent-edit', current }` if a local
// edit landed since the engine read the record (so the patch must not be
// applied — the engine will re-arm and retry).

async function casUpdate(store, key, expectedVersionAtRead, patcher) {
  const db = await getPoeDB()
  const keyField = STORE_KEY[store] ?? 'client_uuid'
  return new Promise((resolve, reject) => {
    let outcome = null
    const tx = db.transaction(store, 'readwrite')
    const obj = tx.objectStore(store)
    const getReq = obj.get(key)
    getReq.onsuccess = () => {
      const cur = getReq.result
      if (!cur) { outcome = { aborted: 'no-record' }; return }
      // If we have an expectedVersionAtRead, abort on concurrent edit.
      if (expectedVersionAtRead != null &&
          (cur.record_version || 0) > expectedVersionAtRead) {
        outcome = { aborted: 'concurrent-edit', current: cur }
        return
      }
      let patched
      try { patched = patcher(cur) } catch (e) { outcome = { aborted: 'patcher-throw', err: e?.message }; return }
      if (!patched || patched[keyField] !== cur[keyField]) {
        outcome = { aborted: 'patcher-key-changed' }
        return
      }
      // Strip Vue proxies / non-cloneable values via JSON roundtrip
      try { obj.put(JSON.parse(JSON.stringify(patched))) }
      catch (e) { outcome = { aborted: 'put-throw', err: e?.message } }
    }
    getReq.onerror = () => { outcome = { aborted: 'get-error', err: getReq.error?.message } }
    tx.oncomplete = () => resolve(outcome || { ok: true })
    tx.onerror    = () => reject(tx.error)
    tx.onabort    = () => reject(tx.error || new Error(`tx aborted on ${store}`))
  })
}

// ────────────────────────────────────────────────────────────────────────────
// IDB write helpers — all sync-state mutations go through CAS
// ────────────────────────────────────────────────────────────────────────────

async function markAttempting(store, record) {
  // CAS-protected: bumps record_version inside an atomic IDB tx so a
  // concurrent local edit cannot be silently overwritten. Returns the
  // post-write record (with the new record_version) so the push handler
  // sends that exact version to the server.
  const expected = record.record_version || 1
  let working = null
  const result = await casUpdate(store, record.client_uuid, expected, (cur) => {
    const newVersion = (cur.record_version || 1) + 1
    working = {
      ...cur,
      sync_attempt_count: (cur.sync_attempt_count || 0) + 1,
      last_sync_attempt_at: serverIsoNow(),
      last_sync_error: null,
      record_version: newVersion,
      updated_at: serverIsoNow(),
    }
    return working
  })
  if (result.ok && working) return working
  // Concurrent edit landed → bail, engine will re-pick the record on next pass.
  pushEvent('mark-attempting-aborted', { store, client_uuid: record.client_uuid, reason: result.aborted })
  return null
}

async function markSynced(store, working, extra = {}) {
  // CAS-protected. The version we sent to the server is `working.record_version`.
  // If a local edit landed during the POST, cur.record_version is now greater
  // and we must NOT claim SYNCED — last_synced_record_version stamps the
  // version that DID reach the server, so the next flush sees a gap and
  // re-pushes idempotently. record_version is NOT bumped here: bumping would
  // permanently leave it one ahead of last_synced and force the engine to
  // re-push every successful sync forever. The CAS transaction itself
  // guarantees atomicity, so no version bump is needed for the write to
  // succeed.
  const sentVersion = working.record_version || 1
  const result = await casUpdate(store, working.client_uuid, /*expected*/ null, (cur) => {
    const curVersion = cur.record_version || 1
    const concurrentEdit = curVersion > sentVersion
    return {
      ...cur,
      ...extra,
      sync_status: concurrentEdit ? SYNC.UNSYNCED : SYNC.SYNCED,
      synced_at: serverIsoNow(),
      last_sync_error: null,
      sync_retryable: concurrentEdit ? true : undefined,
      // Reset the 4xx streak — a successful push proves the previous
      // rejection has been resolved (e.g., parent FK landed).
      _4xx_streak: 0,
      // Stamp the highest version we have proof reached the server.
      last_synced_record_version: sentVersion,
      // Preserve cur.record_version — concurrent edits' versions stay intact;
      // no-edit case keeps the version we just sent.
      record_version: curVersion,
      updated_at: serverIsoNow(),
    }
  })
  if (!result.ok) {
    pushEvent('mark-synced-aborted', { store, client_uuid: working.client_uuid, reason: result.aborted })
  }
}

async function markSoftFail(store, record, errMsg, opts = {}) {
  // CAS-protected. Never overwrites a concurrent local edit. The record is
  // left UNSYNCED with the latest error message attached. The 4xx streak
  // is incremented when opts.is4xx is true so persistent rejections back off
  // to the long cap (10 min) instead of hammering at 60 s intervals.
  await casUpdate(store, record.client_uuid, /*expected*/ null, (cur) => ({
    ...cur,
    sync_status: SYNC.UNSYNCED,
    last_sync_error: String(errMsg || 'unknown').slice(0, 500),
    sync_retryable: true,
    _4xx_streak: opts.is4xx ? (cur._4xx_streak || 0) + 1 : (cur._4xx_streak || 0),
    record_version: (cur.record_version || 1) + 1,
    updated_at: serverIsoNow(),
  }))
}

// ────────────────────────────────────────────────────────────────────────────
// PER-STORE PUSH HANDLERS  (each one is idempotent)
// ────────────────────────────────────────────────────────────────────────────

// 1) PRIMARY SCREENING ──────────────────────────────────────────────────────

function buildPrimaryPayload(r) {
  return {
    client_uuid:            r.client_uuid,
    reference_data_version: r.reference_data_version || APP.REFERENCE_DATA_VER,
    captured_by_user_id:    r.created_by_user_id || r.captured_by_user_id,
    traveler_direction:     r.traveler_direction || null,
    gender:                 r.gender,
    traveler_full_name:     r.traveler_full_name || null,
    temperature_value:      r.temperature_value ?? null,
    temperature_unit:       r.temperature_unit ?? null,
    symptoms_present:       r.symptoms_present ?? 0,
    captured_at:            r.captured_at,
    captured_timezone:      r.captured_timezone || null,
    device_id:              r.device_id || 'unknown',
    app_version:            r.app_version || APP.VERSION,
    platform:               r.platform || 'ANDROID',
    record_version:         r.record_version || 1,
    country_code:           r.country_code,
    province_code:          r.province_code,
    pheoc_code:             r.pheoc_code,
    district_code:          r.district_code,
    poe_code:               r.poe_code,
  }
}

async function pushPrimary(record) {
  if (isUpToDate(record) && record.id) return { skipped: 'up-to-date' }
  if (!inScope(record)) return { skipped: 'out-of-scope (cross-tenant)' }
  if (!record.client_uuid || !record.captured_at || !record.poe_code) {
    await markSoftFail(STORE.PRIMARY_SCREENINGS, record, 'pre-flight: client_uuid/captured_at/poe_code missing')
    return { ok: false, status: 0 }
  }
  const working = await markAttempting(STORE.PRIMARY_SCREENINGS, record)
  if (!working) return { skipped: 'concurrent-edit-during-attempt' }
  const { ok, status, body } = await postJSON(`${serverUrl()}/primary-screenings`, buildPrimaryPayload(working))
  if (ok && body?.success) {
    const serverId = body.data?.id ?? null
    await markSynced(STORE.PRIMARY_SCREENINGS, working, { id: serverId, server_id: serverId })
    // Propagate server notification id into the local notification record —
    // CAS-protected so a concurrent edit on the notification (rare but
    // possible in multi-tab scenarios) is not silently overwritten.
    const notifInfo = body.data?.notification
    if (notifInfo?.id && working.referral_created === 1) {
      try {
        const linked = await dbGetByIndex(STORE.NOTIFICATIONS, 'primary_screening_id', working.client_uuid).catch(() => [])
        for (const n of linked) {
          if (isUpToDate(n) && n.id) continue
          await casUpdate(STORE.NOTIFICATIONS, n.client_uuid, /*expected*/ null, (cur) => ({
            ...cur,
            id: notifInfo.id,
            server_id: notifInfo.id,
            sync_status: SYNC.SYNCED,
            synced_at: serverIsoNow(),
            last_sync_error: null,
            sync_retryable: undefined,
            last_synced_record_version: cur.record_version || 1,
            record_version: (cur.record_version || 1) + 1,
            updated_at: serverIsoNow(),
          }))
        }
      } catch (e) {
        log('warn', 'primary→notification id propagation failed', e?.message)
      }
    }
    pushEvent('primary-synced', { client_uuid: working.client_uuid, server_id: serverId })
    return { ok: true }
  }
  await markSoftFail(STORE.PRIMARY_SCREENINGS, working, body?.message || `HTTP ${status}`,
    { is4xx: status >= 400 && status < 500 })
  return { ok: false, status, body }
}

// 2) NOTIFICATIONS — server creates these as a side effect of primary sync.
//    We do not POST them directly. We only nudge the parent primary if a
//    notification is stranded without a server id, so the next primary flush
//    can re-resolve via server idempotency on client_uuid.

async function pushStrandedNotification(record) {
  if (isUpToDate(record) && record.id) return { skipped: 'up-to-date' }
  if (!inScope(record)) return { skipped: 'out-of-scope (cross-tenant)' }
  const psUuid = record.primary_screening_id
  if (!psUuid) {
    await markSoftFail(STORE.NOTIFICATIONS, record, 'no primary_screening_id to reconcile against')
    return { ok: false }
  }
  const ps = await dbGet(STORE.PRIMARY_SCREENINGS, psUuid).catch(() => null)
  if (ps && (!ps.id || ps.id === ps.client_uuid)) {
    if (ps.sync_status !== SYNC.UNSYNCED) {
      await safeDbPut(STORE.PRIMARY_SCREENINGS, {
        ...ps,
        sync_status: SYNC.UNSYNCED,
        last_sync_error: null,
        sync_retryable: true,
        record_version: (ps.record_version || 1) + 1,
        updated_at: serverIsoNow(),
      })
    }
    return { ok: false, deferred: 'primary' }
  }
  await markSoftFail(STORE.NOTIFICATIONS, record, 'awaiting server reconciliation by client_uuid')
  return { ok: false, deferred: 'server-reconcile' }
}

// 3) SECONDARY SCREENING — Phase 1 (skeleton create) ─────────────────────────

async function pushSecondaryPhase1(record) {
  // Phase 1 is needed iff there's no server id yet.
  if (record.id && record.id !== record.client_uuid) return { skipped: 'has-server-id' }
  if (!inScope(record)) return { skipped: 'out-of-scope (cross-tenant)' }
  const required = ['client_uuid', 'notification_id', 'primary_screening_id']
  for (const f of required) {
    if (!record[f]) {
      await markSoftFail(STORE.SECONDARY_SCREENINGS, record, `phase1 missing ${f}`)
      return { ok: false }
    }
  }
  const ownerUid = Number(record.opened_by_user_id || record.created_by_user_id || 0)
  if (!ownerUid) {
    await markSoftFail(STORE.SECONDARY_SCREENINGS, record, 'phase1 missing opened_by_user_id')
    return { ok: false }
  }
  const working = await markAttempting(STORE.SECONDARY_SCREENINGS, record)
  if (!working) return { skipped: 'concurrent-edit-during-attempt' }
  const payload = {
    client_uuid:            working.client_uuid,
    reference_data_version: working.reference_data_version || APP.REFERENCE_DATA_VER,
    notification_id:        working.notification_id,
    primary_screening_id:   working.primary_screening_id,
    opened_by_user_id:      ownerUid,
    opened_at:              working.opened_at || serverIsoNow(),
    opened_timezone:        working.opened_timezone || null,
    device_id:              working.device_id || 'unknown',
    app_version:            working.app_version || APP.VERSION,
    platform:               working.platform || 'ANDROID',
    traveler_gender:        working.traveler_gender || 'UNKNOWN',
    record_version:         working.record_version || 1,
  }
  const { ok, status, body } = await postJSON(`${serverUrl()}/secondary-screenings`, payload)
  if (ok && body?.success && body.data?.id) {
    const serverId = body.data.id
    // Phase 1 success — server has the case shell. CAS-stamp server_id and
    // reset attempt counters so Phase 2 can fire on the same flush. We keep
    // sync_status=UNSYNCED until Phase 2 catches up record_version.
    await casUpdate(STORE.SECONDARY_SCREENINGS, working.client_uuid, /*expected*/ null, (cur) => ({
      ...cur,
      id: serverId,
      server_id: serverId,
      sync_status: SYNC.UNSYNCED,
      sync_attempt_count: 0,
      last_sync_attempt_at: null,
      last_sync_error: null,
      sync_retryable: true,
      record_version: (cur.record_version || 1) + 1,
      updated_at: serverIsoNow(),
    }))
    pushEvent('secondary-phase1', { client_uuid: working.client_uuid, server_id: serverId })

    // ── DEFENSE IN DEPTH ──────────────────────────────────────────────
    // Immediately chain Phase 2 if there are any local children waiting,
    // so child data entered on a still-IN_PROGRESS case lands on the
    // same engine pass that Phase 1 succeeds. Failure here is non-fatal
    // (the engine's flush loop will retry); Phase 1 success is what we
    // return either way.
    try {
      const fresh = await dbGet(STORE.SECONDARY_SCREENINGS, working.client_uuid).catch(() => null)
      if (fresh && await hasLocalChildren(fresh.client_uuid)) {
        await pushSecondaryPhase2(fresh)
      }
    } catch (_) { /* swallow — Phase 1 already succeeded */ }

    return { ok: true, serverId }
  }
  // Idempotent retry: 422 (parent missing) → defer; engine retries after parents land.
  await markSoftFail(STORE.SECONDARY_SCREENINGS, working, body?.message || `phase1 HTTP ${status}`,
    { is4xx: status >= 400 && status < 500 })
  return { ok: false, status, body }
}

// 4) SECONDARY SCREENING — Phase 2 (disposition + 5 child tables) ────────────

// PARANOID phase-2 gate.
//
// Historically this only fired on terminal / decision events, which meant
// that diseases / symptoms / exposures / actions / samples / travel
// countries entered while the case was still IN_PROGRESS sat on the device
// forever (no push). Confirmed in prod 2026-05-19 — screening #34 (AYENAFD
// TIMOTHY) was SYNCED at the parent row but 0 child rows ever reached the
// server, despite three suspected diseases having been entered locally.
//
// New rule: Phase 2 fires when ANY of the following is true.
//   • the case is terminal (DISPOSITIONED / CLOSED), or
//   • a decision value has been set, or
//   • ANY child row exists in IDB for this case.
//
// `hasLocalChildren` is async (it inspects 6 child stores via the existing
// `secondary_screening_id` index) so `needsPhase2` is now async. The three
// call sites have been updated to await it.
async function hasLocalChildren(clientUuid) {
  if (!clientUuid) return false
  const stores = [
    STORE.SECONDARY_SUSPECTED_DISEASES,
    STORE.SECONDARY_SYMPTOMS,
    STORE.SECONDARY_EXPOSURES,
    STORE.SECONDARY_ACTIONS,
    STORE.SECONDARY_SAMPLES,
    STORE.SECONDARY_TRAVEL_COUNTRIES,
  ]
  for (const s of stores) {
    const rows = await dbGetByIndex(s, 'secondary_screening_id', clientUuid).catch(() => [])
    if ((rows || []).length > 0) return true
  }
  return false
}

async function needsPhase2(record) {
  // No server id yet → Phase 1 first, not Phase 2.
  if (!record.id || record.id === record.client_uuid) return false
  // Already synced at this version AND no local children waiting → no work.
  const upToDate = isUpToDate(record)
  // Decision / terminal data on the parent itself.
  const terminal    = record.case_status === 'DISPOSITIONED' || record.case_status === 'CLOSED'
  const hasDecision = !!(record.syndrome_classification || record.risk_level || record.final_disposition)
  if (terminal || hasDecision) return true
  // The paranoid leg: if any child row exists locally, force a Phase 2.
  // The server's `/sync` endpoint is replace-all per child table, so this
  // is idempotent — re-pushing the same children just replays the same set.
  if (await hasLocalChildren(record.client_uuid)) return true
  return !upToDate ? false : false
}

// ────────────────────────────────────────────────────────────────────────────
// ENUM MIGRATION SANITIZERS — wire-format, NOT data mutation
// ────────────────────────────────────────────────────────────────────────────
//
// The server's clinical enums have evolved between deployments. Records
// captured under an older schema may still carry retired values like
// `DIARRHOEAL_DISEASE` (now split into `AWD` and `BLOODY_DIARRHEA` on the
// server). Pushing them as-is yields 422s forever.
//
// We sanitize at SEND time only — IDB rows are never mutated, so every re-send
// produces the same valid wire payload (idempotent). The user's clinical
// intent is preserved as best we can; unknown values fall back to `OTHER`.

const SYNDROME_VALID = new Set([
  'ILI', 'SARI', 'AWD', 'BLOODY_DIARRHEA', 'VHF', 'RASH_FEVER',
  'JAUNDICE', 'NEUROLOGICAL', 'MENINGITIS', 'OTHER', 'NONE',
])
const SYNDROME_ALIASES = {
  // 2026-04 migration: old generic value → AWD (Acute Watery Diarrhoea is
  // the more common case; bloody-stool cases would have been flagged
  // separately on disposition).
  DIARRHOEAL_DISEASE: 'AWD',
  DIARRHEAL_DISEASE:  'AWD',
  DIARRHEA:           'AWD',
  DIARRHOEA:          'AWD',
  // Common alternate spellings / older labels
  INFLUENZA_LIKE_ILLNESS: 'ILI',
  SEVERE_ACUTE_RESPIRATORY_INFECTION: 'SARI',
  VIRAL_HAEMORRHAGIC_FEVER: 'VHF',
  HAEMORRHAGIC_FEVER:       'VHF',
  RASH_AND_FEVER:           'RASH_FEVER',
  ACUTE_JAUNDICE_SYNDROME:  'JAUNDICE',
  NEUROLOGICAL_SYNDROME:    'NEUROLOGICAL',
}
function sanitizeSyndrome(v) {
  if (!v) return null
  const s = String(v).toUpperCase().replace(/[\s-]/g, '_')
  if (SYNDROME_VALID.has(s)) return s
  if (SYNDROME_ALIASES[s])    return SYNDROME_ALIASES[s]
  return 'OTHER'
}

const FINAL_DISP_VALID = new Set([
  // Current canonical set (server-supported). Includes RETURN_TO_ORIGIN
  // as of 2026-05-06 for travellers being repatriated to their country.
  'RELEASED_NO_CONDITION', 'RELEASED_UNDER_FOLLOWUP', 'REFERRED_HEALTH_FACILITY',
  'ISOLATED_ADMITTED', 'RETURN_TO_ORIGIN', 'DECEASED_AT_POE',
  // Legacy values still accepted by the server's enum
  'CLEARED', 'ISOLATED', 'REFERRED_HOSPITAL', 'QUARANTINED', 'OBSERVATION', 'OTHER',
])
const FINAL_DISP_ALIASES = {
  RELEASED:        'RELEASED_NO_CONDITION',
  DELAYED:         'RELEASED_UNDER_FOLLOWUP',
  REFERRED:        'REFERRED_HEALTH_FACILITY',
  TRANSFERRED:     'REFERRED_HEALTH_FACILITY',
  DENIED_BOARDING: 'RETURN_TO_ORIGIN',
  REPATRIATED:     'RETURN_TO_ORIGIN',
  REFUSED_ENTRY:   'RETURN_TO_ORIGIN',
}
function sanitizeFinalDisposition(v) {
  if (!v) return null
  const s = String(v).toUpperCase().replace(/[\s-]/g, '_')
  if (FINAL_DISP_VALID.has(s)) return s
  if (FINAL_DISP_ALIASES[s])    return FINAL_DISP_ALIASES[s]
  return 'OTHER'
}

const RISK_VALID = new Set(['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])
function sanitizeRiskLevel(v) {
  if (!v) return null
  const s = String(v).toUpperCase()
  return RISK_VALID.has(s) ? s : 'MEDIUM'
}

const TRIAGE_VALID = new Set(['ROUTINE', 'URGENT', 'EMERGENCY'])
function sanitizeTriage(v) {
  if (!v) return null
  const s = String(v).toUpperCase()
  return TRIAGE_VALID.has(s) ? s : null
}

const CASE_STATUS_VALID = new Set(['OPEN', 'IN_PROGRESS', 'DISPOSITIONED', 'CLOSED'])
function sanitizeCaseStatus(v) {
  if (!v) return 'IN_PROGRESS'
  const s = String(v).toUpperCase()
  return CASE_STATUS_VALID.has(s) ? s : 'IN_PROGRESS'
}

async function buildPhase2Payload(caseRec) {
  const sid = caseRec.client_uuid
  const [symptoms, exposures, actions, travelCountries, suspectedDiseases] = await Promise.all([
    dbGetByIndex(STORE.SECONDARY_SYMPTOMS,           'secondary_screening_id', sid).catch(() => []),
    dbGetByIndex(STORE.SECONDARY_EXPOSURES,          'secondary_screening_id', sid).catch(() => []),
    dbGetByIndex(STORE.SECONDARY_ACTIONS,            'secondary_screening_id', sid).catch(() => []),
    dbGetByIndex(STORE.SECONDARY_TRAVEL_COUNTRIES,   'secondary_screening_id', sid).catch(() => []),
    dbGetByIndex(STORE.SECONDARY_SUSPECTED_DISEASES, 'secondary_screening_id', sid).catch(() => []),
  ])
  return {
    user_id:                           Number(caseRec.opened_by_user_id || caseRec.created_by_user_id || 0),
    record_version:                    caseRec.record_version ?? 1,
    // All enum fields go through sanitizers — old enum values from previous
    // schema versions are mapped to currently-valid ones at wire time. Local
    // IDB rows are never mutated by the engine.
    case_status:                       sanitizeCaseStatus(caseRec.case_status),
    traveler_full_name:                caseRec.traveler_full_name ?? null,
    traveler_gender:                   caseRec.traveler_gender ?? 'UNKNOWN',
    traveler_age_years:                caseRec.traveler_age_years ?? null,
    travel_document_type:              caseRec.travel_document_type ?? null,
    travel_document_number:            caseRec.travel_document_number ?? null,
    traveler_nationality_country_code: caseRec.traveler_nationality_country_code ?? null,
    residence_country_code:            caseRec.residence_country_code ?? null,
    phone_number:                      caseRec.phone_number ?? null,
    journey_start_country_code:        caseRec.journey_start_country_code ?? null,
    conveyance_type:                   caseRec.conveyance_type ?? null,
    conveyance_identifier:             caseRec.conveyance_identifier ?? null,
    arrival_datetime:                  caseRec.arrival_datetime ?? null,
    purpose_of_travel:                 caseRec.purpose_of_travel ?? null,
    destination_district_code:         caseRec.destination_district_code ?? null,
    temperature_value:                 caseRec.temperature_value ?? null,
    temperature_unit:                  caseRec.temperature_unit ?? null,
    pulse_rate:                        caseRec.pulse_rate ?? null,
    respiratory_rate:                  caseRec.respiratory_rate ?? null,
    bp_systolic:                       caseRec.bp_systolic ?? null,
    bp_diastolic:                      caseRec.bp_diastolic ?? null,
    oxygen_saturation:                 caseRec.oxygen_saturation ?? null,
    triage_category:                   sanitizeTriage(caseRec.triage_category),
    emergency_signs_present:           caseRec.emergency_signs_present ?? 0,
    general_appearance:                caseRec.general_appearance ?? null,
    syndrome_classification:           sanitizeSyndrome(caseRec.syndrome_classification),
    risk_level:                        sanitizeRiskLevel(caseRec.risk_level),
    officer_notes:                     caseRec.officer_notes ?? null,
    final_disposition:                 sanitizeFinalDisposition(caseRec.final_disposition),
    followup_required:                 caseRec.followup_required ?? 0,
    followup_assigned_level:           caseRec.followup_assigned_level ?? null,
    dispositioned_at:                  caseRec.dispositioned_at ?? null,
    closed_at:                         caseRec.closed_at ?? null,
    symptoms:           symptoms.map(s => ({
      symptom_code: s.symptom_code, is_present: s.is_present,
      onset_date: s.onset_date ?? null, details: s.details ?? null,
    })),
    exposures:          exposures.map(e => ({
      exposure_code: e.exposure_code, response: e.response, details: e.details ?? null,
    })),
    actions:            actions.map(a => ({
      action_code: a.action_code, is_done: a.is_done, details: a.details ?? null,
    })),
    travel_countries:   travelCountries.map(t => ({
      country_code: t.country_code, travel_role: t.travel_role,
      arrival_date: t.arrival_date ?? null, departure_date: t.departure_date ?? null,
    })),
    suspected_diseases: suspectedDiseases.map(d => ({
      disease_code: d.disease_code, rank_order: d.rank_order,
      confidence: d.confidence ?? null, reasoning: d.reasoning ?? null,
    })),
  }
}

async function pushSecondaryPhase2(record) {
  if (!(await needsPhase2(record))) return { skipped: 'phase2-not-required' }
  if (!inScope(record)) return { skipped: 'out-of-scope (cross-tenant)' }
  const serverId = record.id || record.server_id
  if (!serverId || serverId === record.client_uuid) return { skipped: 'awaiting-phase1' }
  if (!record.opened_by_user_id && !record.created_by_user_id) {
    await markSoftFail(STORE.SECONDARY_SCREENINGS, record, 'phase2 missing opened_by_user_id')
    return { ok: false }
  }
  const working = await markAttempting(STORE.SECONDARY_SCREENINGS, record)
  if (!working) return { skipped: 'concurrent-edit-during-attempt' }

  // Phase 1.5 — bridge OPEN→IN_PROGRESS if local is terminal.  Idempotent
  // server-side; if already past IN_PROGRESS the server returns 200 or 409,
  // both of which we treat as success for the bridge.
  const localStatus = working.case_status
  if (localStatus === 'DISPOSITIONED' || localStatus === 'CLOSED') {
    await postJSON(`${serverUrl()}/secondary-screenings/${serverId}/sync`, {
      user_id: Number(working.opened_by_user_id || working.created_by_user_id),
      case_status: 'IN_PROGRESS',
      record_version: 0,
    })
  }

  const payload = await buildPhase2Payload(working)
  const { ok, status, body } = await postJSON(
    `${serverUrl()}/secondary-screenings/${serverId}/sync`,
    payload,
    HTTP_TIMEOUT_MS + 4000,
  )
  if (ok && body?.success) {
    await markSynced(STORE.SECONDARY_SCREENINGS, working, {
      id: serverId,
      server_id: serverId,
    })
    pushEvent('secondary-phase2', { client_uuid: working.client_uuid, server_id: serverId })
    return { ok: true }
  }
  // 404 — this server_id doesn't exist on this tenant's server (typically
  // cross-tenant residue from a previous deployment, e.g. Zambia or Rwanda ids leaked
  // into a Uganda install). The case can never be reconciled with that id.
  // Mark it locally SYNCED so the engine stops hammering and clear the
  // stale id so any future Phase 1 attempt re-creates the case if needed.
  if (status === 404) {
    await markSynced(STORE.SECONDARY_SCREENINGS, working, {
      id: null, server_id: null,
    })
    pushEvent('phase2-stale-id-reaped', { client_uuid: working.client_uuid, dead_server_id: serverId })
    return { ok: true, idempotent: true, reason: 'stale-server-id' }
  }
  // ANY 409 — server is in a state that won't accept this transition. The
  // server is the authoritative source on the case state, so we treat
  // "server is ahead" as a terminal idempotent success. (The previous code
  // only matched messages containing 'closed', missing every other 409.)
  if (status === 409) {
    await markSynced(STORE.SECONDARY_SCREENINGS, working, {
      id: serverId,
      server_id: serverId,
    })
    pushEvent('phase2-409-server-ahead', { client_uuid: working.client_uuid, server_id: serverId, msg: body?.message })
    return { ok: true, idempotent: true, reason: 'server-ahead' }
  }
  // 500 = real server error. Treat as 4xx for backoff (long retry) so we
  // don't hammer a broken endpoint at 5s intervals.
  await markSoftFail(STORE.SECONDARY_SCREENINGS, working, body?.message || `phase2 HTTP ${status}`,
    { is4xx: (status >= 400 && status < 500) || status >= 500 })
  return { ok: false, status, body }
}

// 5) ALERTS  ─────────────────────────────────────────────────────────────────

async function pushAlert(record) {
  if (isUpToDate(record) && record.id) return { skipped: 'up-to-date' }
  if (!inScope(record)) return { skipped: 'out-of-scope (cross-tenant)' }
  if (!record.client_uuid || !record.alert_code) {
    await markSoftFail(STORE.ALERTS, record, 'alert missing client_uuid/alert_code')
    return { ok: false }
  }
  // Resolve linked secondary case server id. Local alerts reference the
  // secondary case by its client_uuid; engine waits for Phase 1 completion.
  const sec = record.secondary_screening_id
    ? await dbGet(STORE.SECONDARY_SCREENINGS, record.secondary_screening_id).catch(() => null)
    : null
  const secondaryServerId = sec && sec.id && sec.id !== sec.client_uuid ? sec.id : null
  if (!secondaryServerId) {
    await markSoftFail(STORE.ALERTS, record, 'alert deferred — secondary phase 1 not done')
    return { ok: false, deferred: 'secondary' }
  }
  const ownerUid = Number(record.created_by_user_id || 0)
  if (!ownerUid) {
    await markSoftFail(STORE.ALERTS, record, 'alert missing created_by_user_id')
    return { ok: false }
  }
  const working = await markAttempting(STORE.ALERTS, record)
  if (!working) return { skipped: 'concurrent-edit-during-attempt' }
  const payload = {
    client_uuid:            working.client_uuid,
    reference_data_version: working.reference_data_version || APP.REFERENCE_DATA_VER,
    created_by_user_id:     ownerUid,
    secondary_screening_id: secondaryServerId,
    generated_from:         working.generated_from || 'RULE_BASED',
    risk_level:             working.risk_level || 'HIGH',
    alert_code:             working.alert_code,
    alert_title:            working.alert_title || working.alert_code,
    alert_details:          working.alert_details || null,
    routed_to_level:        working.routed_to_level || 'DISTRICT',
    ihr_tier:               working.ihr_tier || null,
    device_id:              working.device_id || 'unknown',
    app_version:            working.app_version || APP.VERSION,
    platform:               working.platform || 'ANDROID',
    record_version:         working.record_version || 1,
  }
  const { ok, status, body } = await postJSON(`${serverUrl()}/alerts`, payload)
  if (ok && body?.success) {
    const serverId = body.data?.id ?? null
    await markSynced(STORE.ALERTS, working, { id: serverId, server_id: serverId })
    pushEvent('alert-synced', { client_uuid: working.client_uuid, server_id: serverId })
    return { ok: true }
  }
  await markSoftFail(STORE.ALERTS, working, body?.message || `alert HTTP ${status}`,
    { is4xx: status >= 400 && status < 500 })
  return { ok: false, status, body }
}

// ────────────────────────────────────────────────────────────────────────────
// LEGACY RE-ARM — runs once per app boot, then opportunistically each flush
// ────────────────────────────────────────────────────────────────────────────

let _bootRearmDone = false

async function rearmStore(store) {
  // 1) Flip every FAILED record back to UNSYNCED. The engine has no terminal
  //    failure state — failures are just "not yet succeeded".
  try {
    const failed = await dbGetByIndex(store, 'sync_status', SYNC.FAILED).catch(() => [])
    for (const rec of (failed || [])) {
      await safeDbPut(store, {
        ...rec,
        sync_status: SYNC.UNSYNCED,
        sync_retryable: true,
        sync_attempt_count: 0,
        last_sync_attempt_at: null,
        last_sync_error: null,
        record_version: (rec.record_version || 1) + 1,
        updated_at: serverIsoNow(),
      })
    }
  } catch (e) {
    log('warn', `rearm failed→unsynced for ${store}`, e?.message)
  }
}

async function rearmSecondaryHalfSynced() {
  // The OLD secondarySyncWorker marked secondaries SYNCED after Phase 1 even
  // though Phase 2 had not run. These records now have sync_status=SYNCED but
  // case_status terminal AND last_synced_record_version missing or behind.
  // Flip them back so the new engine retries Phase 2 idempotently.
  try {
    const all = await dbGetAll(STORE.SECONDARY_SCREENINGS).catch(() => [])
    for (const rec of (all || [])) {
      if (rec.sync_status !== SYNC.SYNCED) continue
      const v = rec.record_version || 0
      const sv = rec.last_synced_record_version || 0
      const terminal = rec.case_status === 'DISPOSITIONED' || rec.case_status === 'CLOSED'
      if (terminal && sv < v) {
        await safeDbPut(STORE.SECONDARY_SCREENINGS, {
          ...rec,
          sync_status: SYNC.UNSYNCED,
          sync_retryable: true,
          sync_attempt_count: 0,
          last_sync_attempt_at: null,
          last_sync_error: null,
          record_version: (rec.record_version || 1) + 1,
          updated_at: serverIsoNow(),
        })
      }
    }
  } catch (e) {
    log('warn', 'rearmSecondaryHalfSynced', e?.message)
  }
}

async function rearmAll() {
  await rearmStore(STORE.PRIMARY_SCREENINGS)
  await rearmStore(STORE.NOTIFICATIONS)
  await rearmStore(STORE.SECONDARY_SCREENINGS)
  await rearmStore(STORE.ALERTS)
  await rearmStore(STORE.ALERT_FOLLOWUPS)
  await rearmSecondaryHalfSynced()
}

// ────────────────────────────────────────────────────────────────────────────
// FLUSH PASS — top-down through all stores
// ────────────────────────────────────────────────────────────────────────────

async function flushStore(store, pushFn, opts = {}) {
  const { phase2 = false } = opts
  let pending
  if (phase2) {
    pending = await dbGetAll(store).catch(() => [])
    // needsPhase2 is async (paranoid leg inspects 6 child IDB stores).
    // Pre-resolve in parallel and filter on the boolean results so the
    // downstream loop preserves its original synchronous shape.
    const flags = await Promise.all((pending || []).map(r => needsPhase2(r).catch(() => false)))
    pending = (pending || []).filter((_, i) => flags[i])
  } else {
    pending = await dbGetByIndex(store, 'sync_status', SYNC.UNSYNCED).catch(() => [])
  }
  let pushed = 0, deferred = 0, errored = 0
  for (const rec of (pending || [])) {
    if (!rec || !rec.client_uuid) continue
    const lockKey = rec.client_uuid + ':' + (phase2 ? 'p2' : 'p1')
    if (_inFlight.has(lockKey)) continue
    if (shouldDeferByBackoff(rec)) { deferred++; continue }
    _inFlight.add(lockKey)
    try {
      const r = await pushFn(rec)
      if (r?.ok) pushed++
      else if (r?.skipped) deferred++
      else errored++
    } catch (e) {
      log('error', `${store} push exception`, e?.message)
      await markSoftFail(store, rec, e?.message || 'push exception')
      errored++
    } finally {
      _inFlight.delete(lockKey)
    }
  }
  return { pushed, deferred, errored }
}

export async function flushAll(reason = 'manual') {
  if (_flushing) return { skipped: 'in-progress' }
  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    pushEvent('flush-skipped', { reason, why: 'offline' })
    return { skipped: 'offline' }
  }
  _flushing = true
  pushEvent('flush-start', { reason })
  const t0 = Date.now()
  const stats = {}
  try {
    if (!_bootRearmDone) {
      await rearmAll()
      _bootRearmDone = true
    }
    stats.primary       = await flushStore(STORE.PRIMARY_SCREENINGS, pushPrimary)
    stats.notifications = await flushStore(STORE.NOTIFICATIONS,      pushStrandedNotification)
    stats.secondaryP1   = await flushStore(STORE.SECONDARY_SCREENINGS, pushSecondaryPhase1)
    stats.secondaryP2   = await flushStore(STORE.SECONDARY_SCREENINGS, pushSecondaryPhase2, { phase2: true })
    stats.alerts        = await flushStore(STORE.ALERTS,             pushAlert)
  } finally {
    _flushing = false
    const dur = Date.now() - t0
    pushEvent('flush-end', { reason, dur, stats })
    log('log', `flush(${reason}) done in ${dur}ms`, stats)
  }
  return stats
}

// ────────────────────────────────────────────────────────────────────────────
// PUBLIC API — kick + start + Capacitor lifecycle
// ────────────────────────────────────────────────────────────────────────────

export function kick(reason = 'kick') {
  if (typeof navigator !== 'undefined' && navigator.onLine === false) return
  if (_kickTimer) return
  _kickTimer = setTimeout(() => {
    _kickTimer = null
    flushAll(reason).catch(e => log('error', 'flush threw', e?.message))
  }, KICK_DEBOUNCE_MS)
}

function bindCapacitorLifecycle() {
  // Capacitor App.resume fires when the user returns to the foreground.
  // The DOM 'visibilitychange' / 'focus' events sometimes don't fire on
  // Android WebView for cold-resume from a long backgrounded state.
  try {
    import('@capacitor/app').then(({ App }) => {
      try {
        App.addListener('appStateChange', s => {
          if (s?.isActive) kick('cap-app-active')
        })
        App.addListener('resume', () => kick('cap-resume'))
      } catch { /* plugin not registered — fine */ }
    }).catch(() => { /* not installed — fine */ })
  } catch { /* dynamic import unsupported — fine */ }

  // Capacitor Network plugin gives explicit network change events that are
  // more reliable than the DOM 'online' event on Android WebView.
  try {
    import('@capacitor/network').then(({ Network }) => {
      try {
        Network.addListener('networkStatusChange', s => {
          if (s?.connected) {
            for (const d of ONLINE_BURST_MS) setTimeout(() => kick('cap-net'), d)
          }
        })
      } catch {}
    }).catch(() => {})
  } catch {}
}

export function startSyncEngine() {
  if (_started) return
  _started = true
  if (typeof window === 'undefined') return

  // Boot pass once the WebView settles
  setTimeout(() => kick('boot'), 1500)

  // Continuous heartbeat — kicks the engine on a tight cadence while the tab
  // is visible (5s) and a slower cadence while hidden (15s). The engine runs
  // for the lifetime of the app, so no handle is retained for cancellation.
  // kick() is internally debounced + offline-aware, so this is cheap.
  let _hbTimer = null
  const armHeartbeat = () => {
    if (_hbTimer) { clearInterval(_hbTimer); _hbTimer = null }
    const visible = typeof document === 'undefined' || document.visibilityState !== 'hidden'
    const ms = visible ? POLL_MS_VISIBLE : POLL_MS_HIDDEN
    _hbTimer = setInterval(() => {
      if (typeof navigator === 'undefined' || navigator.onLine !== false) {
        kick(visible ? 'heartbeat-visible' : 'heartbeat-hidden')
      }
    }, ms)
  }
  armHeartbeat()
  // Re-arm whenever visibility flips so the cadence matches the new state.
  if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', armHeartbeat)
  }

  // Online event burst
  window.addEventListener('online', () => {
    for (const d of ONLINE_BURST_MS) setTimeout(() => kick('online'), d)
  })

  // Visibility / focus / pageshow
  if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') kick('visibility')
    })
  }
  window.addEventListener('focus',    () => kick('focus'))
  window.addEventListener('pageshow', () => kick('pageshow'))

  // Capacitor mobile lifecycle
  bindCapacitorLifecycle()

  // Globals — any view, including the Sync Center, can fire these.
  window.__SYNC_NOW__   = (reason = 'global-kick')   => kick(reason)
  window.__SYNC_FLUSH__ = (reason = 'global-flush')  => flushAll(reason)

  log('log', 'started', { POLL_MS_VISIBLE, POLL_MS_HIDDEN, HTTP_TIMEOUT_MS })
}

// Lightweight read-only snapshot for UI redundancy (Sync Center)
export function syncEngineState() {
  return {
    started: _started,
    flushing: _flushing,
    inFlight: _inFlight.size,
    pollMsVisible: POLL_MS_VISIBLE,
    pollMsHidden: POLL_MS_HIDDEN,
    bootRearmDone: _bootRearmDone,
    online: typeof navigator === 'undefined' ? true : navigator.onLine !== false,
  }
}

// Reset the 4xx streak counter on every record across every syncable store.
// Called from Sync Center's "Reset stale records" button when the user
// wants the engine to retry stuck records aggressively (e.g., after fixing
// data, restoring connectivity to the right server, or clearing residue).
// NEVER deletes data — only flips state flags. Safe to run any time.
export async function resetStaleRecords() {
  const stores = [
    STORE.PRIMARY_SCREENINGS, STORE.NOTIFICATIONS,
    STORE.SECONDARY_SCREENINGS, STORE.ALERTS, STORE.ALERT_FOLLOWUPS,
  ]
  let resetCount = 0
  for (const s of stores) {
    try {
      const all = await dbGetAll(s).catch(() => [])
      for (const rec of (all || [])) {
        if (!rec || (rec._4xx_streak || 0) === 0) continue
        await casUpdate(s, rec.client_uuid, /*expected*/ null, (cur) => ({
          ...cur,
          _4xx_streak: 0,
          sync_attempt_count: 0,
          last_sync_attempt_at: null,
          last_sync_error: null,
          sync_retryable: true,
          record_version: (cur.record_version || 1) + 1,
          updated_at: serverIsoNow(),
        }))
        resetCount++
      }
    } catch (e) {
      log('warn', `resetStaleRecords ${s}`, e?.message)
    }
  }
  pushEvent('reset-stale-done', { resetCount })
  return resetCount
}

// Per-store pending / synced / errored counts — used by Sync Center to
// render live counters. Queries IDB on every call so the result is the
// freshest possible snapshot. Cheap because it uses indexed counts only.
export async function getPendingCounts() {
  const stores = [
    { key: STORE.PRIMARY_SCREENINGS,   label: 'Primary screenings' },
    { key: STORE.NOTIFICATIONS,        label: 'Notifications' },
    { key: STORE.SECONDARY_SCREENINGS, label: 'Secondary screenings' },
    { key: STORE.ALERTS,               label: 'Alerts' },
    { key: STORE.ALERT_FOLLOWUPS,      label: 'Alert follow-ups' },
  ]
  const out = []
  for (const { key, label } of stores) {
    let pending = 0, synced = 0, withError = 0, phase2Pending = 0, stuck4xx = 0
    try {
      const all = await dbGetAll(key).catch(() => [])
      for (const r of (all || [])) {
        if (!r) continue
        if (r.sync_status === SYNC.SYNCED) {
          if ((r.last_synced_record_version || 0) >= (r.record_version || 0)) synced++
          else pending++
        } else {
          pending++
        }
        if (r.last_sync_error) withError++
        if ((r._4xx_streak || 0) >= HARD_4XX_TRIGGER) stuck4xx++
        // needsPhase2 is async; resolve sequentially so the loop's
        // existing for…of semantics aren't broken. This path is the
        // sync-center counter, not the hot push path, so the small
        // per-record awaits are fine.
        if (key === STORE.SECONDARY_SCREENINGS && (await needsPhase2(r))) phase2Pending++
      }
    } catch (e) {
      log('warn', `getPendingCounts ${key}`, e?.message)
    }
    out.push({ key, label, pending, synced, withError, phase2Pending, stuck4xx })
  }
  return out
}
