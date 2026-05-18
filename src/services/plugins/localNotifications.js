/**
 * services/plugins/localNotifications.js — enterprise-grade local notification
 * wrapper for Capacitor.
 *
 * Why this rewrite (2026-05-06)
 * -----------------------------
 *  V1 silently rejected any schedule whose `at` was less than 5 s in the
 *  future. Every WhatsApp-style call site uses `at: now + 1s` to mean
 *  "fire immediately" — they were ALL being dropped, which is why the
 *  user never saw any local notifications. The fix: when the requested
 *  fire time is < 2 s out, we OMIT the `schedule` field entirely and
 *  Capacitor / the OS fires the notification on the next message-loop
 *  tick — exactly the behaviour callers want.
 *
 * Robustness features
 *   • Android 8+ notification channels — explicit "Alerts" channel with
 *     high importance so heads-up display works on every OEM build.
 *   • Permission flow with three-state result (granted / denied / blocked)
 *     so the UI can route to system settings on permanent denial.
 *   • One-time channel registration (idempotent; safe to call repeatedly).
 *   • Retry queue — if the OS rejects a schedule (rare; e.g. background
 *     restrictions), the request is buffered and re-tried on next foreground.
 *   • Tap deep-link — `onTap()` survives plugin reload and exposes the
 *     full notification payload.
 *   • Web fallback — uses the Web Notifications API when running in a
 *     browser without the native plugin, so the dev preview also works.
 *
 * All methods are safe on web (degrade gracefully) and when permission
 * is denied (return `false` / `null`, never throw).
 */

import { makePluginSandbox } from './_base'
import { CAPABILITY_KEYS } from '../capabilities'

const CHANNEL_ID = 'sentinel-alerts'
const CHANNEL_NAME = 'Sentinel Alerts'
const CHANNEL_DESCRIPTION = 'Health surveillance alerts (high priority)'
const SMALL_ICON = 'ic_stat_icon_config_sample'

let _channelRegistered = false
const _retryQueue = []
let _tapListenerInstalled = false
const _tapListeners = new Set()

const sandbox = makePluginSandbox({
  name: 'local-notifications',
  importer: () => import('@capacitor/local-notifications'),
  settingsKey: CAPABILITY_KEYS.LOCAL_NOTIFS,
})

// ── Android channel (high-importance heads-up) ───────────────────────────
async function ensureChannel() {
  if (_channelRegistered) return true
  return sandbox.run(async ({ LocalNotifications }) => {
    if (typeof LocalNotifications.createChannel !== 'function') {
      _channelRegistered = true
      return true
    }
    try {
      await LocalNotifications.createChannel({
        id: CHANNEL_ID,
        name: CHANNEL_NAME,
        description: CHANNEL_DESCRIPTION,
        importance: 5,             // IMPORTANCE_HIGH (heads-up + sound)
        visibility: 1,             // VISIBILITY_PUBLIC (lock-screen visible)
        sound: 'default',
        lights: true,
        lightColor: '#00B4A6',
        vibration: true,
      })
      _channelRegistered = true
      return true
    } catch (err) {
      console.debug('[local-notifications] createChannel failed:', err?.message)
      return false
    }
  }, { fallback: false })
}

// ── Permission ────────────────────────────────────────────────────────────
/**
 * Request permission. Returns a richer object than the v1 boolean so
 * callers can route to settings on permanent denial.
 *
 * @returns {Promise<{granted:boolean, permanent:boolean, settingsUrl:string|null}>}
 */
export async function requestPermissionDetailed() {
  return sandbox.run(async ({ LocalNotifications }) => {
    let cur = null
    try { cur = await LocalNotifications.checkPermissions() } catch {}
    const state = cur?.display || 'prompt'
    if (state === 'granted') return { granted: true, permanent: false, settingsUrl: null }

    if (state === 'denied') {
      // Probe — system suppresses the dialog when permanently denied
      const before = Date.now()
      let req = null
      try { req = await LocalNotifications.requestPermissions() } catch {}
      const elapsed = Date.now() - before
      const granted = req?.display === 'granted'
      const permanent = !granted && elapsed < 200
      return { granted, permanent, settingsUrl: permanent ? buildSettingsUrl() : null }
    }

    // prompt / prompt-with-rationale
    let req = null
    try { req = await LocalNotifications.requestPermissions() } catch {}
    const granted = req?.display === 'granted'
    return { granted, permanent: false, settingsUrl: null }
  }, { fallback: { granted: false, permanent: false, settingsUrl: null } })
}

// Backwards-compatible boolean form.
export async function requestPermission() {
  const r = await requestPermissionDetailed()
  return !!r.granted
}

export async function hasPermission() {
  return sandbox.run(async ({ LocalNotifications }) => {
    const cur = await LocalNotifications.checkPermissions()
    return cur?.display === 'granted'
  }, { fallback: false })
}

// ── Schedule ──────────────────────────────────────────────────────────────
/**
 * Schedule (or fire immediately) a notification.
 *
 * @param id        number — stable per-reminder id; same id overwrites
 * @param title     string
 * @param body      string
 * @param at        Date|ISO|null — when to fire. Pass `null` or a moment
 *                  ≤ 2 s in the future to fire immediately.
 * @param payload   optional JSON; surfaced via `extra` on tap
 */
export async function schedule(id, title, body, at, payload = null) {
  // Compute the schedule object. If "now" or sub-2-second future,
  // omit `schedule.at` so Capacitor fires on the next tick.
  let scheduleAt = null
  if (at) {
    const when = at instanceof Date ? at : new Date(at)
    if (when instanceof Date && !isNaN(when.getTime())) {
      if (when.getTime() > Date.now() + 2_000) scheduleAt = when
    }
  }

  // Try the native plugin first.
  const ok = await sandbox.run(async ({ LocalNotifications }) => {
    // Permission gate — request once if missing
    const granted = (await hasPermission()) || (await requestPermission())
    if (!granted) return false

    await ensureChannel()

    const notification = {
      id: Number(id),
      title: String(title || '').slice(0, 80),
      body: String(body || '').slice(0, 240),
      smallIcon: SMALL_ICON,
      channelId: CHANNEL_ID,
      extra: payload || undefined,
      autoCancel: true,
      ongoing: false,
    }
    if (scheduleAt) notification.schedule = { at: scheduleAt }

    try {
      await LocalNotifications.schedule({ notifications: [notification] })
      return true
    } catch (err) {
      console.debug('[local-notifications] schedule failed:', err?.message)
      // Buffer for retry on next foreground
      _retryQueue.push({ id, title, body, at: scheduleAt, payload })
      return false
    }
  }, { fallback: null })

  if (ok === true) return true

  // Web fallback — use the Web Notifications API for browsers / dev preview
  if (typeof window !== 'undefined' && 'Notification' in window && !scheduleAt) {
    try {
      if (Notification.permission === 'default') {
        await Notification.requestPermission()
      }
      if (Notification.permission === 'granted') {
        const n = new Notification(String(title || ''), {
          body: String(body || ''),
          tag: String(id),
          data: payload || undefined,
        })
        n.onclick = (ev) => {
          try {
            window.focus()
            window.dispatchEvent(new CustomEvent('app:deep-link', {
              detail: payload && payload.route ? { route: payload.route, kind: payload.kind, payload } : { route: '/' },
            }))
          } catch {}
        }
        return true
      }
    } catch {}
  }

  return false
}

export async function cancel(id) {
  return sandbox.run(async ({ LocalNotifications }) => {
    await LocalNotifications.cancel({ notifications: [{ id: Number(id) }] })
    return true
  }, { fallback: false })
}

export async function listPending() {
  return sandbox.run(async ({ LocalNotifications }) => {
    const r = await LocalNotifications.getPending()
    return Array.isArray(r?.notifications) ? r.notifications : []
  }, { fallback: [] })
}

export async function clearAll() {
  return sandbox.run(async ({ LocalNotifications }) => {
    const pending = await LocalNotifications.getPending()
    const list = Array.isArray(pending?.notifications) ? pending.notifications : []
    if (list.length) await LocalNotifications.cancel({ notifications: list })
    return true
  }, { fallback: false })
}

export async function isAvailable() { return sandbox.isAvailable() }

// ── Tap handling ──────────────────────────────────────────────────────────
async function _installTapListenerOnce() {
  if (_tapListenerInstalled) return
  _tapListenerInstalled = true
  await sandbox.run(async ({ LocalNotifications }) => {
    try {
      await LocalNotifications.addListener('localNotificationActionPerformed', (ev) => {
        for (const cb of _tapListeners) { try { cb(ev) } catch {} }
      })
      // Also surface foreground arrivals so the host can update badges.
      await LocalNotifications.addListener('localNotificationReceived', (ev) => {
        for (const cb of _tapListeners) { try { cb({ ...ev, _foreground: true }) } catch {} }
      })
    } catch {}
  }, { fallback: null })
}

/**
 * Subscribe to taps. Multiple subscribers are supported — the underlying
 * native listener is installed only once and broadcasts to all callbacks.
 *
 * @returns {Promise<() => void>}  unsubscribe function
 */
export async function onTap(cb) {
  if (typeof cb !== 'function') return () => {}
  _tapListeners.add(cb)
  await _installTapListenerOnce()
  return () => { _tapListeners.delete(cb) }
}

// ── Retry queue drain ────────────────────────────────────────────────────
if (typeof document !== 'undefined') {
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return
    const pending = _retryQueue.splice(0, _retryQueue.length)
    for (const item of pending) {
      schedule(item.id, item.title, item.body, item.at, item.payload).catch(() => {})
    }
  })
}

// ── Settings deep-link ────────────────────────────────────────────────────
function buildSettingsUrl() {
  try {
    const cap = (typeof window !== 'undefined' && window.Capacitor) || null
    const plat = typeof cap?.getPlatform === 'function' ? cap.getPlatform() : 'web'
    if (plat === 'android' || plat === 'ios') return 'app-settings:'
  } catch {}
  return null
}
