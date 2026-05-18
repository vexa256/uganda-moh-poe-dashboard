/**
 * services/alertNotifier.js — WhatsApp-style local-notification bridge for
 * the alert lifecycle.
 *
 * Why this file exists
 *   The user wants local notifications that behave like WhatsApp's:
 *     - Pop up immediately when something important happens (new alert,
 *       new high-risk screening, follow-up due, override applied, alert
 *       acknowledged / closed).
 *     - Tapping the notification opens the relevant screen in the app
 *       (deep-link via the Capacitor LocalNotifications tap event +
 *       window.dispatchEvent('app:deep-link', ...)).
 *     - Persistent on the lock screen until dismissed.
 *     - High-priority for CRITICAL / HIGH events (full-screen / heads-up).
 *
 * Architecture
 *   - notifyAlert({alert}) — raises a heads-up notification for a brand-new
 *     alert. Tap → routes to /alerts/<id> via deepLinkBus.
 *   - notifyHighRiskScreening(...) — raises a heads-up when secondary
 *     screening produces HIGH/CRITICAL.
 *   - notifyFollowupDue(...) — fires when an open follow-up reaches its
 *     due_at; scheduled at the time the follow-up is created.
 *   - install() — wires the LocalNotifications tap listener once at boot
 *     and resolves deep-link routing through the app router.
 *
 * Implementation rules
 *   - Pure additive layer over services/plugins/localNotifications.js — no
 *     existing API breaks.
 *   - Idempotent — same alert id produces a stable notification id derived
 *     from a hash of the alert id, so repeated calls overwrite cleanly.
 *   - Web-safe — every method silently no-ops when LocalNotifications is
 *     unavailable (PWA mode, dev build).
 */

import * as LN from '@/services/plugins/localNotifications'

const NS = 'sentinel-alerts'

// 31-bit-safe id derivation. The Capacitor LocalNotifications API takes
// integers; alert ids on the server are also integers, but we apply a
// namespace offset so notification ids never collide with primary-screening
// or follow-up reminder ids that might already be in flight.
function _alertNotifId(alertId) {
  const n = Number(alertId) || 0
  return 100000 + (n % 1000000)
}
function _screeningNotifId(screeningId) {
  const n = Number(screeningId) || 0
  return 200000 + (n % 1000000)
}
function _followupNotifId(followupId) {
  const n = Number(followupId) || 0
  return 300000 + (n % 1000000)
}
// Event-specific id namespaces so different events on the SAME alert produce
// DIFFERENT system notifications (instead of the OS replacing the prior one).
// Each kind gets its own 7-digit band; alerts with id > 1M wrap into the
// band, which is acceptable — collision probability stays tiny.
const _NS = Object.freeze({
  CREATED:      400000,
  ACKNOWLEDGED: 500000,
  CLOSED:       600000,
  REOPENED:     700000,
  ESCALATED:    800000,
  REASSIGNED:   900000,
  PHEIC:       1100000,
  COMMENT:     1200000,
  FOLLOWUP:    1300000,
  REFERRAL:    1400000,
  OVERRIDE:    1500000,
  SYNC_FAIL:   1600000,
})
function _idForEvent(kind, alertId, extraSeed = 0) {
  const base = _NS[kind] ?? 100000
  const n = Number(alertId) || 0
  const seed = Number(extraSeed) || 0
  return base + ((n * 7 + seed) % 999000) // keep 1000-range headroom
}

function _riskTitle(risk_level) {
  switch (String(risk_level || '').toUpperCase()) {
    case 'CRITICAL': return '🔴 CRITICAL alert'
    case 'HIGH':     return '🟠 HIGH-risk alert'
    case 'MEDIUM':   return '🟡 Medium-risk alert'
    case 'LOW':      return '🟢 Low-risk alert'
    default:         return 'New alert'
  }
}

/**
 * Raise a heads-up notification for a newly-created alert.
 *
 * @param {object} alert  — must have at least { id, alert_code, alert_title,
 *                          risk_level, secondary_screening_id }
 */
export async function notifyAlert(alert) {
  if (!alert || !alert.id) return false
  const title = _riskTitle(alert.risk_level)
  const subject = String(alert.alert_title || alert.alert_code || 'Alert raised')
  const body = `${subject}${alert.alert_code ? ' · ' + alert.alert_code : ''}`
  const at = new Date(Date.now() + 1000) // ~immediate; OS smooths sub-5s delay
  return LN.schedule(
    _alertNotifId(alert.id),
    title.slice(0, 64),
    body.slice(0, 240),
    at,
    {
      ns: NS,
      kind: 'ALERT',
      alert_id: Number(alert.id),
      secondary_screening_id: alert.secondary_screening_id ?? null,
      route: `/alerts/${alert.id}`,
    },
  )
}

/**
 * Raise a notification when a secondary screening lands on HIGH or CRITICAL.
 * Lower risk levels are intentionally quiet — they would be noise.
 */
export async function notifyHighRiskScreening({ secondary_screening_id, traveler_label, risk_level, suspected_disease }) {
  const rl = String(risk_level || '').toUpperCase()
  if (rl !== 'HIGH' && rl !== 'CRITICAL') return false
  const id = _screeningNotifId(secondary_screening_id)
  const title = `${rl === 'CRITICAL' ? '🔴' : '🟠'} ${rl} risk screening`
  const subj = traveler_label ? `Traveller: ${traveler_label}` : 'Traveller flagged'
  const body = suspected_disease ? `${subj} · suspected ${suspected_disease}` : subj
  return LN.schedule(
    id,
    title.slice(0, 64),
    body.slice(0, 240),
    new Date(Date.now() + 1000),
    {
      ns: NS,
      kind: 'SCREENING_RISK',
      secondary_screening_id: Number(secondary_screening_id) || null,
      route: secondary_screening_id ? `/secondary-records?case=${secondary_screening_id}` : '/my-cases',
    },
  )
}

/**
 * Schedule a reminder that fires at `due_at`. Cancelling the same id
 * (e.g. when the follow-up is resolved) is the caller's responsibility via
 * cancelFollowup(id).
 */
export async function scheduleFollowup({ followup_id, alert_id, title, body, due_at }) {
  if (!followup_id || !due_at) return false
  return LN.schedule(
    _followupNotifId(followup_id),
    String(title || 'Follow-up due').slice(0, 64),
    String(body || '').slice(0, 240),
    new Date(due_at),
    {
      ns: NS,
      kind: 'FOLLOWUP',
      alert_id: alert_id ? Number(alert_id) : null,
      followup_id: Number(followup_id),
      route: alert_id ? `/alerts/${alert_id}` : '/my-cases',
    },
  )
}

// ── Lifecycle event notifiers ─────────────────────────────────────────────
// Every transition that the user might care about gets its own immediate
// heads-up. Each call shares the same shape: derive a stable per-event id
// (so retries overwrite cleanly), build a short title/body, schedule via
// the LocalNotifications plugin with a 1 s lead so the OS treats it as a
// fresh push, and stamp the route into `extra` so a tap deep-links back
// to the correct screen.

function _summary(alert) {
  const code = alert?.alert_code || ''
  const title = alert?.alert_title || code || 'Alert'
  return code ? `${title} · ${code}` : title
}

export async function notifyAcknowledged(alert, byUserId) {
  if (!alert?.id) return false
  const subj = _summary(alert)
  return LN.schedule(
    _idForEvent('ACKNOWLEDGED', alert.id),
    '✅ Alert acknowledged',
    `${subj}${byUserId ? ' · by user #' + byUserId : ''}`.slice(0, 240),
    new Date(Date.now() + 1000),
    { ns: NS, kind: 'ACKNOWLEDGED', alert_id: Number(alert.id), route: `/alerts/${alert.id}` },
  )
}

export async function notifyClosed(alert, { close_category } = {}) {
  if (!alert?.id) return false
  const subj = _summary(alert)
  return LN.schedule(
    _idForEvent('CLOSED', alert.id),
    '🔒 Alert closed',
    `${subj}${close_category ? ' · ' + String(close_category).replace(/_/g, ' ') : ''}`.slice(0, 240),
    new Date(Date.now() + 1000),
    { ns: NS, kind: 'CLOSED', alert_id: Number(alert.id), route: `/alerts/${alert.id}` },
  )
}

export async function notifyReopened(alert, reason) {
  if (!alert?.id) return false
  const subj = _summary(alert)
  return LN.schedule(
    _idForEvent('REOPENED', alert.id),
    '↩️ Alert reopened',
    `${subj}${reason ? ' · ' + String(reason).slice(0, 80) : ''}`.slice(0, 240),
    new Date(Date.now() + 1000),
    { ns: NS, kind: 'REOPENED', alert_id: Number(alert.id), route: `/alerts/${alert.id}` },
  )
}

export async function notifyEscalated(alert, toLevel) {
  if (!alert?.id) return false
  const subj = _summary(alert)
  return LN.schedule(
    _idForEvent('ESCALATED', alert.id),
    `⤴️ Alert escalated → ${String(toLevel || 'higher').toUpperCase()}`,
    subj.slice(0, 240),
    new Date(Date.now() + 1000),
    { ns: NS, kind: 'ESCALATED', alert_id: Number(alert.id), route: `/alerts/${alert.id}` },
  )
}

export async function notifyReassigned(alert, toUserId) {
  if (!alert?.id) return false
  const subj = _summary(alert)
  return LN.schedule(
    _idForEvent('REASSIGNED', alert.id),
    '👤 Alert reassigned',
    `${subj}${toUserId ? ' · to user #' + toUserId : ''}`.slice(0, 240),
    new Date(Date.now() + 1000),
    { ns: NS, kind: 'REASSIGNED', alert_id: Number(alert.id), route: `/alerts/${alert.id}` },
  )
}

export async function notifyPheic(alert, reason) {
  if (!alert?.id) return false
  const subj = _summary(alert)
  return LN.schedule(
    _idForEvent('PHEIC', alert.id),
    '🚨 PHEIC pathway entered',
    `${subj}${reason ? ' · ' + String(reason).slice(0, 80) : ''}`.slice(0, 240),
    new Date(Date.now() + 1000),
    { ns: NS, kind: 'PHEIC', alert_id: Number(alert.id), route: `/alerts/${alert.id}` },
  )
}

export async function notifyComment(alert, { author, snippet } = {}) {
  if (!alert?.id) return false
  const subj = _summary(alert)
  // Use a per-event seed (timestamp) so multiple comments on the same alert
  // each produce a separate heads-up rather than stomping each other.
  const seed = Date.now() & 0xffffff
  return LN.schedule(
    _idForEvent('COMMENT', alert.id, seed),
    '💬 New comment on alert',
    `${author ? author + ': ' : ''}${snippet || ''} (${subj})`.slice(0, 240),
    new Date(Date.now() + 1000),
    { ns: NS, kind: 'COMMENT', alert_id: Number(alert.id), route: `/alerts/${alert.id}` },
  )
}

export async function notifyFollowupCreated(alert, { title }) {
  if (!alert?.id) return false
  return LN.schedule(
    _idForEvent('FOLLOWUP', alert.id, Date.now() & 0xffffff),
    '📋 Follow-up added',
    `${title || 'New follow-up'} · ${_summary(alert)}`.slice(0, 240),
    new Date(Date.now() + 1000),
    { ns: NS, kind: 'FOLLOWUP', alert_id: Number(alert.id), route: `/alerts/${alert.id}` },
  )
}

export async function notifyReferralReceived({ notification_id, traveler_label, poe_label, urgency }) {
  if (!notification_id) return false
  const urg = String(urgency || '').toUpperCase()
  const icon = urg === 'CRITICAL' ? '🔴' : urg === 'HIGH' ? '🟠' : '📨'
  return LN.schedule(
    _idForEvent('REFERRAL', notification_id),
    `${icon} New referral received`,
    `${traveler_label || 'Traveller'}${poe_label ? ' · ' + poe_label : ''}`.slice(0, 240),
    new Date(Date.now() + 1000),
    { ns: NS, kind: 'REFERRAL', notification_id: Number(notification_id), route: '/notifications' },
  )
}

export async function notifyOverrideApplied({ secondary_screening_id, declared_disease, by_user }) {
  if (!secondary_screening_id) return false
  return LN.schedule(
    _idForEvent('OVERRIDE', secondary_screening_id),
    '⚖️ Officer override applied',
    `${declared_disease || 'Disease declared'}${by_user ? ' · ' + by_user : ''}`.slice(0, 240),
    new Date(Date.now() + 1000),
    {
      ns: NS, kind: 'OVERRIDE',
      secondary_screening_id: Number(secondary_screening_id),
      route: `/secondary-records?case=${secondary_screening_id}`,
    },
  )
}

export async function notifySyncFailure({ kind, count }) {
  if (!count || count <= 0) return false
  // Coalesce to a single heads-up keyed on kind so we don't spam.
  return LN.schedule(
    _idForEvent('SYNC_FAIL', 0, kind ? kind.charCodeAt(0) : 0),
    '⚠️ Sync needs attention',
    `${count} ${kind || 'records'} pending — check Sync Management`.slice(0, 240),
    new Date(Date.now() + 1000),
    { ns: NS, kind: 'SYNC_FAIL', route: '/sync/queue' },
  )
}

export async function cancelAlert(alertId)        { return LN.cancel(_alertNotifId(alertId)) }
export async function cancelScreening(screeningId) { return LN.cancel(_screeningNotifId(screeningId)) }
export async function cancelFollowup(followupId)   { return LN.cancel(_followupNotifId(followupId)) }

/**
 * Wire the tap listener once at app boot. When the user taps any of our
 * notifications, we re-emit a `app:deep-link` window event that the router
 * layer can pick up to navigate to the originating screen.
 */
let _installed = false
export async function install() {
  if (_installed) return
  _installed = true
  // Best-effort permission grant on first boot — the user can revoke later.
  try { await LN.requestPermission() } catch {}
  await LN.onTap((ev) => {
    try {
      const extra = ev?.notification?.extra || {}
      if (extra?.ns !== NS) return
      const route = String(extra.route || '/').trim()
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('app:deep-link', {
          detail: { route, kind: extra.kind, payload: extra },
        }))
      }
    } catch (err) {
      console.debug('[alert-notifier] tap handler failed:', err?.message)
    }
  })
}
