/**
 * services/haptics.js — safe native haptic feedback wrapper.
 *
 * Wraps @capacitor/haptics (v8) so the rest of the app can call
 *   hapticCritical()   — CRITICAL alert / IHR notification required
 *   hapticWarning()    — HIGH risk / temp-high warning
 *   hapticError()      — validation failure / submit blocked
 *   hapticLight()      — subtle tap / button confirm (optional)
 *
 * CONTRACT — these functions NEVER throw and NEVER block the caller:
 *   - Dynamic-import the plugin so a missing native dependency cannot
 *     break the Vite/Rollup bundle or the runtime web build.
 *   - Every plugin call is wrapped in try/catch; errors go to console.debug
 *     only (never surface, never reject).
 *   - Respects the "haptics.enabled" localStorage flag (default: ON).
 *   - No-op on web builds (Capacitor platform !== 'android'/'ios').
 *
 * Reference:
 *   https://capacitorjs.com/docs/apis/haptics
 *   ImpactStyle: Heavy | Medium | Light
 *   NotificationType: SUCCESS | WARNING | ERROR
 */

const STORAGE_KEY = 'haptics.enabled'

let _pluginPromise = null
let _platformChecked = false
let _isNative = false

/**
 * Resolve Capacitor platform once. Returns false on web, true on native.
 * Any failure in the platform check counts as "web" — never throws.
 */
async function isNative() {
  if (_platformChecked) return _isNative
  _platformChecked = true
  try {
    const core = await import('@capacitor/core')
    const plat = core?.Capacitor?.getPlatform?.()
    _isNative = plat === 'android' || plat === 'ios'
  } catch (err) {
    console.debug('[haptics] platform probe failed:', err?.message)
    _isNative = false
  }
  return _isNative
}

/**
 * Lazily import the @capacitor/haptics module. Cached across calls.
 * Returns null if the module can't be loaded (missing plugin, build shim).
 */
async function loadPlugin() {
  if (_pluginPromise) return _pluginPromise
  _pluginPromise = (async () => {
    try {
      const mod = await import('@capacitor/haptics')
      if (!mod?.Haptics) throw new Error('Haptics export missing')
      return mod
    } catch (err) {
      console.debug('[haptics] plugin unavailable:', err?.message)
      return null
    }
  })()
  return _pluginPromise
}

/**
 * Read the user preference. Default = enabled.
 */
export function hapticsEnabled() {
  try {
    const v = localStorage.getItem(STORAGE_KEY)
    return v === null ? true : v === '1'
  } catch { return true }
}

/**
 * Persist the user preference. Safe on SSR / locked storage.
 */
export function setHapticsEnabled(on) {
  try { localStorage.setItem(STORAGE_KEY, on ? '1' : '0') } catch {}
}

/**
 * Internal: run a plugin call, swallowing any error.
 */
async function safeCall(fn) {
  if (!hapticsEnabled()) return
  if (!(await isNative())) return
  const mod = await loadPlugin()
  if (!mod) return
  try { await fn(mod) } catch (err) { console.debug('[haptics] call failed:', err?.message) }
}

/**
 * CRITICAL / IHR alert: double-strong impact + ERROR notification pattern.
 * Used when a screening result reaches CRITICAL risk or when IHR alert
 * is required. Distinct "urgent" feel on Android (~2 stronger taps).
 */
export function hapticCritical() {
  safeCall(async ({ Haptics, ImpactStyle, NotificationType }) => {
    await Haptics.impact({ style: ImpactStyle.Heavy })
    await new Promise(r => setTimeout(r, 90))
    await Haptics.notification({ type: NotificationType.ERROR })
  })
}

/**
 * HIGH risk / temp-over-threshold warning.
 */
export function hapticWarning() {
  safeCall(async ({ Haptics, NotificationType }) => {
    await Haptics.notification({ type: NotificationType.WARNING })
  })
}

/**
 * Validation failure / submit blocked.
 * Short sharp buzz, signals "something is wrong, check the form".
 */
export function hapticError() {
  safeCall(async ({ Haptics, ImpactStyle }) => {
    await Haptics.impact({ style: ImpactStyle.Medium })
  })
}

/**
 * Lightweight confirmation tap — optional, used sparingly.
 */
export function hapticLight() {
  safeCall(async ({ Haptics, ImpactStyle }) => {
    await Haptics.impact({ style: ImpactStyle.Light })
  })
}

/**
 * Success confirmation — record captured, alert acknowledged, etc.
 */
export function hapticSuccess() {
  safeCall(async ({ Haptics, NotificationType }) => {
    await Haptics.notification({ type: NotificationType.SUCCESS })
  })
}
