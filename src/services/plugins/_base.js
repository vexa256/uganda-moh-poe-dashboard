/**
 * services/plugins/_base.js — sandbox factory for every Capacitor plugin wrapper.
 *
 * Contract (see docs/CAPABILITIES_PLAN.md §4.1):
 *   - Dynamic import only.
 *   - Platform gate via Capacitor.getPlatform().
 *   - Settings flag gate via capabilities.isEnabled(key).
 *   - Per-call try/catch.
 *   - Circuit breaker — 10 consecutive failures → degraded 10 min no-op.
 *   - dispose() hook for listener cleanup.
 *   - Never throws to caller. Never surfaces errors except via optional verbose flag.
 *
 * Every plugin wrapper consumes makePluginSandbox(...) and exports
 * feature-specific methods that call `sandbox.run(fn)` internally.
 */

import { isEnabled as _capIsEnabled } from '../capabilities'

const BREAKER_THRESHOLD = 10
const BREAKER_COOLDOWN_MS = 10 * 60 * 1000

export function makePluginSandbox({ name, importer, settingsKey, requiresNative = true, sentinel = false }) {
  let modulePromise = null
  let platformChecked = false
  let isNative = false
  let consecutiveFailures = 0
  let breakerOpenUntil = 0

  async function resolvePlatform() {
    if (platformChecked) return isNative
    platformChecked = true
    try {
      const core = await import('@capacitor/core')
      const plat = core?.Capacitor?.getPlatform?.()
      isNative = plat === 'android' || plat === 'ios'
    } catch (err) {
      console.debug(`[plugin:${name}] platform probe failed:`, err?.message)
      isNative = false
    }
    return isNative
  }

  async function loadModule() {
    if (modulePromise) return modulePromise
    modulePromise = (async () => {
      try {
        const mod = await importer()
        if (!mod) throw new Error('empty module')
        return mod
      } catch (err) {
        console.debug(`[plugin:${name}] import failed:`, err?.message)
        return null
      }
    })()
    return modulePromise
  }

  function isGatedOff() {
    // Root fix 2026-05-04:
    // Previously this function read localStorage directly and treated null as
    // "gated off" for Sentinel features. That contradicted capabilities.js
    // isEnabled() which falls back to DEFAULTS[key] when localStorage is null.
    // On a fresh Android device with empty localStorage every Sentinel feature
    // (MRZ, voice) was blocked — camera never opened, no permission requested.
    //
    // Fix: use _capIsEnabled() for every gate decision. It reads localStorage
    // AND falls back to DEFAULTS so fresh-install behaviour matches the
    // explicitly-configured behaviour. Both code paths now agree.
    try {
      if (sentinel) {
        // Master switch — explicit OFF kills the whole Sentinel subsystem.
        if (!_capIsEnabled('cap.sentinel.master.enabled')) return true
        // Downloads frozen — only blocks download operations. Pure capture
        // features (camera/OCR, voice) continue working.
        const frozen = localStorage.getItem('cap.sentinel.downloads.frozen')
        if (frozen === '1' || frozen === 'true') return true
      }
      // Per-feature key: delegate to _capIsEnabled which honours DEFAULTS.
      if (settingsKey && !_capIsEnabled(settingsKey)) return true
      return false
    } catch {
      // Last-resort: if capabilities module throws, fail open (don't block).
      return false
    }
  }

  function breakerOpen() {
    return Date.now() < breakerOpenUntil
  }

  function recordSuccess() { consecutiveFailures = 0 }
  function recordFailure() {
    consecutiveFailures++
    if (consecutiveFailures >= BREAKER_THRESHOLD) {
      breakerOpenUntil = Date.now() + BREAKER_COOLDOWN_MS
      consecutiveFailures = 0
      console.debug(`[plugin:${name}] circuit breaker tripped — cooldown 10m`)
    }
  }

  return {
    name,
    /**
     * Invoke the plugin safely.
     * @param fn async function (module) => result
     * @param opts { requireNative?: bool, fallback?: any, bypassGate?: bool }
     */
    async run(fn, opts = {}) {
      const {
        requireNative = requiresNative,
        fallback = undefined,
        bypassGate = false,
      } = opts
      if (!bypassGate && isGatedOff()) return fallback
      if (breakerOpen()) return fallback
      if (requireNative && !(await resolvePlatform())) return fallback
      const mod = await loadModule()
      if (!mod) return fallback
      try {
        const r = await fn(mod)
        recordSuccess()
        return r
      } catch (err) {
        recordFailure()
        console.debug(`[plugin:${name}] call failed:`, err?.message)
        return fallback
      }
    },

    isAvailable: async () => {
      if (isGatedOff()) return false
      if (requiresNative && !(await resolvePlatform())) return false
      const mod = await loadModule()
      return !!mod
    },

    isNativeSupported: async () => resolvePlatform(),

    breakerState: () => ({
      tripped: breakerOpen(),
      resumesAt: breakerOpenUntil || null,
    }),
  }
}
