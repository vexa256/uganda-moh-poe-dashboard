/**
 * useAlertLifecycle.js — single composable for the full mobile alert war-room.
 *
 * Offline-first contract:
 *   ─ Reads (case-file, advisor, comms-inbox) hit the network when online and
 *     cache the response in localStorage; when offline, they hydrate from the
 *     cache so the war-room opens instantly even with no signal.
 *   ─ Writes (acknowledge / close / reopen / reassign / escalate / followup
 *     update / blocker resolve / outcome / breach / comment / evidence) post
 *     immediately when online; if the network is down or the request fails
 *     transiently, they are queued in `alertOutbox` and an optimistic patch
 *     is applied to the cached case-file so the user sees their action.
 *     The outbox auto-flushes when connectivity returns.
 *
 * Paranoid RBAC:
 *   ─ Server is source of truth. Every write call carries `user_id` (legacy
 *     contract) AND header `X-User-Id`; the controller re-resolves the actor
 *     against `user_assignments` and checks scope + role on every call.
 *   ─ The composable mirrors `permissions{}` returned by /case-file as
 *     `lc.permissions.value` so the UI can fail-closed on hide.
 */

import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useAuth } from '@/composables/useAuth'
import { sentinelToast } from '@/services/sentinelToast'
import { readCaseFile, writeCaseFile, patchAlertHead, appendOptimisticTimeline } from '@/services/alertCache'
import { enqueue, useOutbox, setupAutoFlush } from '@/services/alertOutbox'
import { getDeviceId } from '@/services/poeDB'

const TIMEOUT_MS = 20000

function baseUrl() {
  return (typeof window !== 'undefined' && window.SERVER_URL)
    || import.meta.env.VITE_SERVER_URL
    || 'https://ug-poe.ecsahc.com/api'
}

function withUid(url, uid) {
  if (!uid) return url
  return url + (url.includes('?') ? '&' : '?') + 'user_id=' + uid
}

async function rawFetch(method, path, { uid, body, query } = {}) {
  const headers = {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  }
  if (uid) headers['X-User-Id'] = String(uid)
  let url = baseUrl().replace(/\/$/, '') + path
  if (query) {
    const qs = new URLSearchParams(query).toString()
    if (qs) url += (url.includes('?') ? '&' : '?') + qs
  }
  url = withUid(url, uid)

  const ctl = new AbortController()
  const t = setTimeout(() => ctl.abort(), TIMEOUT_MS)
  try {
    const init = { method, headers, signal: ctl.signal }
    if (body !== undefined && method !== 'GET') {
      init.body = JSON.stringify({ user_id: uid, actor_user_id: uid, ...body })
    }
    const res = await fetch(url, init)
    let json = null
    try { json = await res.json() } catch (_) { /* non-JSON */ }
    return { ok: res.ok, status: res.status, body: json }
  } catch (e) {
    return { ok: false, status: 0, body: { success: false, message: e?.message || 'Network error', error: { code: 'NETWORK' } } }
  } finally {
    clearTimeout(t)
  }
}

// Used by the global outbox auto-flusher. Replays a queued op exactly as it
// was first attempted (path + body + uid).
async function replayOp(op) {
  return rawFetch(op.method, op.path, { uid: op.uid, body: op.body, query: op.query })
}
// Wire up auto-flush once at module load. The auto-flusher is a no-op when
// the queue is empty / the device is offline.
if (typeof window !== 'undefined') {
  setupAutoFlush(replayOp)
}

export function useAlertLifecycle(alertIdRef) {
  const auth = useAuth()
  const uid = computed(() => Number(auth.value?.id || 0))
  const { queue: outboxQueue, pending: outboxPending } = useOutbox()

  const alertId = computed(() => Number(typeof alertIdRef === 'function'
    ? alertIdRef()
    : (alertIdRef?.value ?? alertIdRef)))

  const loading       = ref(false)
  const loadingFresh  = ref(false)         // true only on initial cold-load
  const lastError     = ref(null)
  const isStale       = ref(false)         // shown to the user when data came from cache
  const cachedAt      = ref(null)
  const isOnline      = ref(typeof navigator === 'undefined' ? true : navigator.onLine !== false)

  const caseFile      = ref(null)
  const advisor       = ref(null)
  const followups     = ref([])
  const blockers      = ref([])
  const timeline      = ref([])
  const comments      = ref([])
  const evidence      = ref([])
  const outcome       = ref(null)
  const breachReports = ref([])
  const commsInbox    = ref([])
  const permissions   = ref({})

  // Originating-device lock (executive directive 2026-05-05).
  // The phone that captured the screening owns the alert. When the war-room
  // is opened on a DIFFERENT phone, all writes are refused locally and the
  // UI shows a read-only banner. Server-side sync used to merge stale local
  // state from secondary devices and corrupt the canonical record. Now the
  // outbox is gated by isWritableHere — non-origin devices never enqueue.
  // National admins may bypass this lock (cross-device by role design).
  const isWritableHere = computed(() => {
    const a = caseFile.value?.alert
    if (!a) return true   // pre-load: don't pre-emptively lock
    const role = String(auth.value?.role_key || '').toUpperCase()
    if (role === 'NATIONAL_ADMIN') return true
    const originDevice = String(a.device_id ?? a.origin_device_id ?? '').trim()
    if (!originDevice) return true   // legacy alerts without a device_id stay writable
    let here = ''
    try { here = String(getDeviceId() || '').trim() } catch {}
    return originDevice === here
  })
  const isReadOnlyByDevice = computed(() => !isWritableHere.value)

  const queuedForThisAlert = computed(() =>
    outboxQueue.value.filter(o => Number(o.alert_id) === Number(alertId.value)).length
  )

  // ── connectivity tracking
  function onOnline()  { isOnline.value = true }
  function onOffline() { isOnline.value = false }
  if (typeof window !== 'undefined') {
    window.addEventListener('online',  onOnline)
    window.addEventListener('offline', onOffline)
  }
  onBeforeUnmount(() => {
    if (typeof window !== 'undefined') {
      window.removeEventListener('online',  onOnline)
      window.removeEventListener('offline', onOffline)
    }
  })

  function setError(res, fallback = 'Something went wrong.') {
    const msg = res?.body?.message || fallback
    lastError.value = { message: msg, status: res?.status || 0, detail: res?.body?.error || null }
    return lastError.value
  }

  function hydrateFromCaseFile(d) {
    caseFile.value      = d
    advisor.value       = d?.advisor || null
    followups.value     = d?.followups || []
    blockers.value      = d?.blockers || []
    timeline.value      = d?.timeline || []
    comments.value      = d?.comments || []
    evidence.value      = d?.evidence || []
    outcome.value       = d?.case_outcome || null
    breachReports.value = d?.breach_reports || []
    permissions.value   = d?.permissions || {}
  }

  async function loadCaseFile({ silent = false } = {}) {
    if (!alertId.value || !uid.value) return null
    if (!silent) loading.value = true
    lastError.value = null

    // 1. Hydrate from cache instantly so the page paints in <16ms.
    const cached = readCaseFile(alertId.value)
    if (cached?.data) {
      hydrateFromCaseFile(cached.data)
      cachedAt.value = cached.cached_at
      isStale.value = true
      loadingFresh.value = false
    } else {
      loadingFresh.value = true
    }

    // 2. Fetch fresh in the background (always, when online).
    if (isOnline.value) {
      const res = await rawFetch('GET', `/alerts/${alertId.value}/case-file`, { uid: uid.value })
      if (res.ok) {
        const d = res.body?.data || {}
        hydrateFromCaseFile(d)
        writeCaseFile(alertId.value, d)
        isStale.value = false
        cachedAt.value = new Date().toISOString()
      } else if (!cached) {
        // No cache + network failed → real error.
        setError(res, 'Could not load case file.')
      }
    }
    loading.value = false
    loadingFresh.value = false
    return caseFile.value
  }

  async function loadAdvisor() {
    // 1-H guard: don't fire with alertId=0 (route not yet committed).
    if (!alertId.value) return null
    if (!isOnline.value) {
      sentinelToast('Advisor needs network — showing cached recommendation.', 'warning')
      return advisor.value
    }
    const res = await rawFetch('GET', `/alerts/${alertId.value}/advisor`, { uid: uid.value })
    if (res.ok) {
      const payload = res.body?.data || null
      // Graceful degradation: a well-formed insufficient payload is still valid.
      // Show the "missing inputs" message in the advisor tab instead of an error.
      advisor.value = payload
      return advisor.value
    }
    // 1-H: 503/500 from server → show graceful empty state, NOT a crash toast.
    // RD-001 FIX: template checks .insufficient (truthy) — must include that key
    advisor.value = {
      insufficient: true,        // ← what the war-room template checks (v-if="lc.advisor.value.insufficient")
      sufficient: false,
      missing_inputs: [{ field: 'service', why: `Advisor unavailable (HTTP ${res.status}) — clinical algorithm could not run for this alert.` }],
      rules_fired: [],
      recommendation: null,
    }
    return advisor.value
  }

  async function loadCommsInbox() {
    // 1-H guard: don't fire with alertId=0.
    if (!alertId.value) return null
    if (!isOnline.value) { sentinelToast('Notifications need network.', 'warning'); return null }
    const res = await rawFetch('GET', `/alerts/${alertId.value}/comms-inbox`, { uid: uid.value })
    if (res.ok) { commsInbox.value = res.body?.data?.notifications || []; return commsInbox.value }
    // 1-H: 500 from server → show graceful empty state in comms tab.
    commsInbox.value = []
    return null
  }

  // ──────────────────────────────────────────────────────────────────────────
  //  Write helper: tries online-first, falls back to outbox + optimistic patch.
  //  optimistic({alertPatch?, timelineEvent?}) shapes the cache so the UI
  //  updates immediately. Returns { ok, queued?, blocked?, blockers?, body }.
  // ──────────────────────────────────────────────────────────────────────────
  async function write(method, path, body, { kind, alertId: overrideAlert, optimistic, successMessage, failMessage } = {}) {
    const aid = Number(overrideAlert ?? alertId.value)

    // Originating-device note (executive directive 2026-05-05, refined 2026-05-05):
    // The original lock blocked ALL writes from non-origin devices to prevent
    // corruption. The corruption risk is specifically the full-screening
    // payload sync (SecondaryScreening.vue's syncCaseToServer) overwriting
    // the canonical record with a stale local copy. Lifecycle operations
    // (acknowledge / close / escalate / reassign / reopen / pheic-declare /
    // followup add or update / blocker-resolve / breach reports / comments)
    // are server-authoritative SINGLE-COMMAND state transitions that cannot
    // overwrite the local screening payload. They are safe from any device.
    // The server enforces scope authorisation via checkScope/role ladders.
    // → no client-side device gate here.

    const op = { method, path, body, uid: uid.value, kind, alert_id: aid }

    // Apply optimistic patch BEFORE network so the UI updates instantly.
    let patchedHead = false
    if (optimistic) {
      if (optimistic.alertPatch) { patchAlertHead(aid, optimistic.alertPatch); patchedHead = true }
      if (optimistic.timelineEvent) appendOptimisticTimeline(aid, { ...optimistic.timelineEvent, kind })
      // Re-hydrate from cache so the screen reflects the patch.
      const c = readCaseFile(aid); if (c?.data) hydrateFromCaseFile(c.data)
    }

    if (!isOnline.value) {
      enqueue(op)
      sentinelToast('Offline — queued. Will sync when network returns.', 'warning')
      return { ok: true, queued: true }
    }

    const res = await rawFetch(method, path, { uid: uid.value, body })
    if (res.ok) {
      if (successMessage) sentinelToast(successMessage, 'success')
      // Re-fetch authoritative state — but DETACH it so the caller (e.g.
      // SmartCloseWizard) returns immediately. Awaiting here was making
      // the close flow appear stuck on weak networks: the modal couldn't
      // dismiss until the GET /alerts/{id}/case-file responded, which on
      // slow links could be 5–10 s. The optimistic patch already updated
      // the head; the silent refresh just reconciles minor fields and
      // need not block the UI.
      loadCaseFile({ silent: true }).catch(() => { /* tolerated */ })
      return { ok: true, body: res.body }
    }

    // Specific surfaceable errors
    if (res.body?.error?.code === 'BLOCKS_CLOSURE') {
      // Roll back optimistic close so the UI doesn't lie.
      if (patchedHead && optimistic?.alertPatch) {
        patchAlertHead(aid, { status: optimistic.fromStatus || 'OPEN', closed_at: null })
        const c = readCaseFile(aid); if (c?.data) hydrateFromCaseFile(c.data)
      }
      setError(res, failMessage || 'Cannot close — blockers open.')
      return { ok: false, blocked: true, blockers: res.body.error.blockers || [], body: res.body }
    }

    // 409 Conflict — could be "already closed" (idempotent), state machine violation,
    // or concurrent modification. Check message and reconcile gracefully.
    if (res.status === 409) {
      const msg = (res.body?.message || '').toLowerCase()
      const alreadyClosed = msg.includes('closed') || msg.includes('terminal')
      if (alreadyClosed) {
        // Server already closed — reconcile local state and return ok
        if (patchedHead) await loadCaseFile({ silent: true })
        sentinelToast('Alert was already closed — your device is now in sync.', 'success')
        return { ok: true, body: res.body }
      }
      // Other conflict (e.g. concurrent modification): roll back + notify
      if (patchedHead) await loadCaseFile({ silent: true })
      const conflictMsg = res.body?.message || 'Conflict — another officer may have modified this alert. Please refresh.'
      sentinelToast(conflictMsg, 'warning')
      return { ok: false, conflict: true, body: res.body }
    }

    // Transient (5xx / network) — queue and surface optimistic for now.
    if (res.status >= 500 || res.status === 0) {
      enqueue(op)
      sentinelToast('Server unavailable — queued. Retrying in background.', 'warning')
      return { ok: true, queued: true }
    }

    // 4xx — roll back optimistic and surface error
    if (patchedHead) await loadCaseFile({ silent: true })
    setError(res, failMessage || 'Action failed.')
    sentinelToast(lastError.value.message, 'danger')
    return { ok: false, body: res.body }
  }

  // ── Local-notification fan-out helper ─────────────────────────────────
  // Fires a per-event local notification via the alertNotifier service.
  // Failures are silent — the lifecycle write itself has already succeeded
  // and the notification surface is best-effort. Never await: lifecycle
  // ops must return to the caller without waiting on the OS notification
  // pipeline (Capacitor LocalNotifications.schedule resolves quickly but
  // we don't want to introduce a new failure mode for the write path).
  function _fireNotif(method, ...args) {
    import('@/services/alertNotifier').then(mod => {
      try { mod[method]?.(...args) } catch (e) { console.debug('[notifier]', method, 'threw:', e?.message) }
    }).catch(e => console.debug('[notifier] import failed:', e?.message))
  }

  // ── Lifecycle writes (every one optimistic + outbox-aware) ────────────────
  async function acknowledge() {
    const r = await write('PATCH', `/alerts/${alertId.value}/acknowledge`, {}, {
      kind: 'acknowledge',
      successMessage: 'Alert acknowledged.',
      optimistic: {
        alertPatch: { status: 'ACKNOWLEDGED', acknowledged_at: new Date().toISOString(), acknowledged_by_user_id: uid.value },
        timelineEvent: { event_code: 'ACKNOWLEDGED', summary: 'Alert acknowledged' },
        fromStatus: caseFile.value?.alert?.status,
      },
    })
    if (r.ok) _fireNotif('notifyAcknowledged', caseFile.value?.alert, uid.value)
    return r
  }

  async function close({ close_category, close_note, merged_into_alert_id, override_blocking_followups, override_reason }) {
    const body = {
      close_category: close_category || null,
      close_note: close_note || null,
      merged_into_alert_id: merged_into_alert_id || null,
    }
    if (override_blocking_followups) {
      body.override_blocking_followups = 1
      body.override_reason = override_reason
    }
    const r = await write('PATCH', `/alerts/${alertId.value}/close`, body, {
      kind: 'close',
      successMessage: 'Alert closed.',
      optimistic: {
        alertPatch: { status: 'CLOSED', close_category: close_category || null, close_note: close_note || null, closed_at: new Date().toISOString() },
        timelineEvent: { event_code: 'CLOSED', summary: 'Alert closed (' + (close_category || 'note') + ')' },
        fromStatus: caseFile.value?.alert?.status,
      },
      failMessage: 'Close failed.',
    })
    if (r.ok) _fireNotif('notifyClosed', caseFile.value?.alert, { close_category })
    return r
  }

  async function reopen(reason) {
    const r = await write('POST', `/alerts/${alertId.value}/reopen`, { reason }, {
      kind: 'reopen',
      successMessage: 'Alert reopened.',
      optimistic: {
        alertPatch: { status: 'ACKNOWLEDGED', closed_at: null },
        timelineEvent: { event_code: 'ALERT_REOPENED', summary: 'Alert reopened — ' + reason, severity: 'WARN' },
      },
    })
    if (r.ok) _fireNotif('notifyReopened', caseFile.value?.alert, reason)
    return r
  }

  async function reassign({ owner_user_id, level, reason }) {
    const r = await write('POST', `/alerts/${alertId.value}/reassign`, { owner_user_id, level, reason }, {
      kind: 'reassign',
      successMessage: 'Owner reassigned.',
      optimistic: {
        alertPatch: { current_owner_user_id: owner_user_id, current_owner_level: level || null },
        timelineEvent: { event_code: 'REASSIGNED', summary: 'Reassigned to user #' + owner_user_id },
      },
    })
    if (r.ok) _fireNotif('notifyReassigned', caseFile.value?.alert, owner_user_id)
    return r
  }

  async function escalate({ to_level, reason, notify }) {
    const r = await write('POST', `/alerts/${alertId.value}/escalate`, { to_level, reason, notify: !!notify }, {
      kind: 'escalate',
      successMessage: `Escalated to ${to_level}.`,
      optimistic: {
        alertPatch: { routed_to_level: to_level },
        timelineEvent: { event_code: 'ESCALATED', summary: 'Escalated → ' + to_level },
      },
    })
    if (r.ok) _fireNotif('notifyEscalated', caseFile.value?.alert, to_level)
    return r
  }

  async function declarePheic(reason) {
    const r = await write('POST', `/alerts/${alertId.value}/pheic-declare`, { reason, notify: true }, {
      kind: 'pheic',
      successMessage: 'PHEIC pathway entered.',
      optimistic: {
        alertPatch: { routed_to_level: 'NATIONAL', pheic_declared_at: new Date().toISOString() },
        timelineEvent: { event_code: 'PHEIC_DECLARED', summary: 'PHEIC declared', severity: 'CRITICAL' },
      },
    })
    if (r.ok) _fireNotif('notifyPheic', caseFile.value?.alert, reason)
    return r
  }

  // ── Followups + blockers
  async function addFollowup(payload) {
    const cuuid = payload.client_uuid || (typeof crypto !== 'undefined' && crypto.randomUUID ? crypto.randomUUID() : ('fu-' + Date.now()))
    const body = { created_by_user_id: uid.value, client_uuid: cuuid, ...payload }
    const r = await write('POST', `/alerts/${alertId.value}/followups`, body, { kind: 'followup.add', successMessage: 'Follow-up added.' })
    if (r.ok) {
      _fireNotif('notifyFollowupCreated', caseFile.value?.alert, { title: payload.title || payload.action_text || 'New follow-up' })
      // Schedule a future reminder if the follow-up has a due date
      if (payload.due_at) {
        _fireNotif('scheduleFollowup', {
          followup_id: r.body?.data?.id || cuuid,
          alert_id:    alertId.value,
          title:       payload.title || 'Follow-up due',
          body:        payload.action_text || '',
          due_at:      payload.due_at,
        })
      }
    }
    return r
  }

  async function updateFollowup(id, payload) {
    return write('PATCH', `/alert-followups/${id}`, payload, { kind: 'followup.update' })
  }

  async function resolveBlocker(id, { resolution, reason, evidence_ref }) {
    return write('POST', `/alert-followups/${id}/resolve-blocker`, { resolution, reason, evidence_ref }, {
      kind: 'blocker.resolve',
      successMessage: 'Blocker resolved.',
    })
  }

  /**
   * Bulk-resolve every open blocker in one tap. Each blocker becomes a
   * separate queued op (so the audit trail records each transition).
   */
  async function resolveAllBlockers({ resolution, reason, evidence_ref }) {
    const ids = (blockers.value || []).map(b => b.id)
    if (!ids.length) return { ok: true, resolved: 0 }
    let resolved = 0
    for (const id of ids) {
      const r = await resolveBlocker(id, { resolution, reason, evidence_ref })
      if (r.ok) resolved++
    }
    sentinelToast(`Resolved ${resolved}/${ids.length} blockers.`, resolved === ids.length ? 'success' : 'warning')
    return { ok: resolved > 0, resolved }
  }

  async function recordOutcome(payload) {
    return write('POST', `/alerts/${alertId.value}/case-outcome`, payload, {
      kind: 'outcome.record',
      successMessage: 'Outcome recorded.',
      optimistic: {
        timelineEvent: { event_code: 'CASE_OUTCOME_RECORDED', event_category: 'CLINICAL',
          summary: 'Case classified as ' + (payload.case_classification || 'UNKNOWN') },
      },
    })
  }

  // Forensic FIX F-3: stamp every additive write with a stable client_uuid so
  // (a) the outbox replaying the same op after a network blip cannot create
  //     duplicate comments/evidence/breach rows on the server, and
  // (b) the server can dedup on the client_uuid column where present.
  function _newUuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID()
    return 'cu-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10)
  }

  async function postComment(text, { parent_id = null, visibility = 'INTERNAL' } = {}) {
    const r = await write('POST', `/alerts/${alertId.value}/comments`, {
      // AlertCollaborationController::postComment validates `body` (not body_text).
      body: text,
      parent_id,
      visibility,
      client_uuid: _newUuid(),
    }, { kind: 'comment.post' })
    if (r.ok) {
      _fireNotif('notifyComment', caseFile.value?.alert, {
        author: auth.value?.full_name || auth.value?.username || null,
        snippet: String(text || '').slice(0, 80),
      })
    }
    return r
  }

  async function addEvidence(payload) {
    const body = {
      visibility: 'INTERNAL',
      client_uuid: _newUuid(),
      ...payload,
      uploader_name: auth.value?.full_name || auth.value?.username || null,
    }
    return write('POST', `/alerts/${alertId.value}/evidence`, body, { kind: 'evidence.add', successMessage: 'Evidence attached.' })
  }

  async function logBreach(payload) {
    return write('POST', `/alerts/${alertId.value}/breach-report`, {
      client_uuid: _newUuid(),
      ...payload,
    }, { kind: 'breach.log', successMessage: 'Breach root cause logged.' })
  }

  return {
    // state
    loading, loadingFresh, lastError, isStale, cachedAt, isOnline,
    caseFile, advisor, followups, blockers, timeline, comments, evidence,
    outcome, breachReports, commsInbox, permissions,
    outboxPending, queuedForThisAlert,
    // device-lock state (Step 10 — cross-device corruption fix)
    isWritableHere, isReadOnlyByDevice,
    // reads
    loadCaseFile, loadAdvisor, loadCommsInbox,
    // writes
    acknowledge, close, reopen, reassign, escalate, declarePheic,
    addFollowup, updateFollowup, resolveBlocker, resolveAllBlockers,
    recordOutcome, postComment, addEvidence, logBreach,
  }
}
