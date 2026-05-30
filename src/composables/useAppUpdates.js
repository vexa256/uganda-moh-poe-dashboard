/*
 * useAppUpdates — single source of truth for the Capgo OTA state.
 *
 * Wraps @capgo/capacitor-updater behind reactive refs so every surface
 * (banner, settings, diagnostics) reads from the same store. All plugin
 * calls are guarded by Capacitor.isNativePlatform() so the same code
 * runs harmlessly in the web preview, on PWA, and in headless E2E
 * environments.
 *
 * Singleton — the listeners attach once on first import and persist for
 * the app's lifetime. Multiple consumers share the same refs.
 */
import { ref, computed, readonly } from 'vue'
import { Capacitor } from '@capacitor/core'

const STORAGE_KEY = 'ug_poe_ota_v1'

const safeStorage = {
  read() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY)
      return raw ? JSON.parse(raw) : {}
    } catch { return {} }
  },
  write(obj) {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(obj)) } catch { /* full / quota */ }
  },
}

// Reactive state — shared singleton
const persisted = safeStorage.read()

const isNative      = ref(false)
const pluginReady   = ref(false)
const currentBundle = ref(persisted.currentBundle || null)   // { version, status, downloaded } | null
const latestKnown   = ref(persisted.latestKnown   || null)   // { version, url, checksum } | null
const downloadPct   = ref(0)                                  // 0..100
const isChecking    = ref(false)
const isDownloading = ref(false)
const isApplying    = ref(false)
const lastCheckAt   = ref(persisted.lastCheckAt   || null)   // ISO timestamp
const lastResult    = ref(persisted.lastResult    || null)   // 'no_new' | 'available' | 'downloaded' | 'failed' | 'applied'
const lastError     = ref(persisted.lastError     || null)   // string | null
const pluginVersion = ref(persisted.pluginVersion || null)
const channel       = ref(persisted.channel || null)
const deviceId      = ref(persisted.deviceId || null)

function persist() {
  safeStorage.write({
    currentBundle:  currentBundle.value,
    latestKnown:    latestKnown.value,
    lastCheckAt:    lastCheckAt.value,
    lastResult:     lastResult.value,
    lastError:      lastError.value,
    pluginVersion:  pluginVersion.value,
    channel:        channel.value,
    deviceId:       deviceId.value,
  })
}

// Lazy plugin handle so we don't crash in non-native environments
let pluginModule = null
async function loadPlugin() {
  if (pluginModule || !isNative.value) return pluginModule
  try {
    pluginModule = await import('@capgo/capacitor-updater')
    return pluginModule
  } catch (e) {
    console.warn('[capgo] plugin import failed (probably web/PWA):', e?.message)
    return null
  }
}

let initPromise = null
async function init() {
  if (initPromise) return initPromise
  initPromise = (async () => {
    try {
      isNative.value = !!(Capacitor && Capacitor.isNativePlatform && Capacitor.isNativePlatform())
    } catch { isNative.value = false }

    if (!isNative.value) {
      // Web preview / PWA: nothing to wire, state stays in `not native` mode.
      return
    }

    const mod = await loadPlugin()
    if (!mod || !mod.CapacitorUpdater) return
    const cu = mod.CapacitorUpdater

    // Hydrate current state
    try {
      const info = await cu.current()
      if (info && info.bundle) {
        currentBundle.value = {
          version:    info.bundle.version || 'builtin',
          status:     info.bundle.status  || 'success',
          downloaded: info.bundle.downloaded || null,
        }
        persist()
      }
    } catch (e) {
      console.debug('[capgo] current() failed:', e?.message)
    }

    try {
      const dev = await cu.getDeviceId()
      deviceId.value = dev?.deviceId || null
    } catch { /* not critical */ }

    try {
      const pv = await cu.getPluginVersion()
      pluginVersion.value = pv?.version || null
    } catch { /* not critical */ }

    try {
      const c = await cu.getChannel?.()
      channel.value = c?.channel || null
    } catch { /* getChannel may not exist in some versions */ }

    // Event wiring — every listener catches its own errors so one broken
    // event handler can never crash the app.
    safeListen(cu, 'updateAvailable',   (e) => {
      latestKnown.value = { version: e?.bundle?.version, url: e?.bundle?.url, checksum: e?.bundle?.checksum }
      lastResult.value  = 'available'
      lastError.value   = null
      persist()
    })
    safeListen(cu, 'noNeedUpdate', () => {
      lastResult.value = 'no_new'
      lastError.value  = null
      lastCheckAt.value = new Date().toISOString()
      persist()
    })
    safeListen(cu, 'download', (e) => {
      isDownloading.value = true
      downloadPct.value   = Math.max(0, Math.min(100, Number(e?.percent) || 0))
    })
    safeListen(cu, 'downloadComplete', (e) => {
      isDownloading.value = false
      downloadPct.value   = 100
      lastResult.value    = 'downloaded'
      latestKnown.value   = { version: e?.bundle?.version, ...(latestKnown.value || {}) }
      persist()
    })
    safeListen(cu, 'downloadFailed', (e) => {
      isDownloading.value = false
      downloadPct.value   = 0
      lastResult.value    = 'failed'
      lastError.value     = `Download failed for v${e?.version || '?'}`
      persist()
    })
    safeListen(cu, 'updateFailed', (e) => {
      isApplying.value = false
      lastResult.value = 'failed'
      lastError.value  = `Apply failed for v${e?.bundle?.version || '?'}`
      persist()
    })
    safeListen(cu, 'appReady', (e) => {
      // The plugin confirms the active bundle booted cleanly.
      if (e?.bundle?.version) {
        currentBundle.value = {
          version:    e.bundle.version,
          status:     'success',
          downloaded: e.bundle.downloaded || null,
        }
        lastResult.value = 'applied'
        lastError.value  = null
        persist()
      }
    })
    safeListen(cu, 'majorAvailable', (e) => {
      latestKnown.value = { version: e?.version, breaking: true }
      lastResult.value  = 'breaking'
      lastError.value   = 'A new version requires updating the native app from the store'
      persist()
    })

    pluginReady.value = true
  })()
  return initPromise
}

function safeListen(cu, name, fn) {
  try {
    cu.addListener(name, (...args) => {
      try { fn(...args) } catch (e) { console.warn(`[capgo:${name}] handler failed:`, e?.message) }
    })
  } catch (e) {
    console.debug(`[capgo:${name}] addListener failed:`, e?.message)
  }
}

async function checkNow() {
  if (!isNative.value) {
    lastResult.value = 'unsupported'
    lastError.value  = 'OTA updates require the native app (not available in web preview)'
    return { ok: false, reason: 'unsupported' }
  }
  isChecking.value  = true
  lastCheckAt.value = new Date().toISOString()
  lastError.value   = null
  try {
    const mod = await loadPlugin()
    if (!mod?.CapacitorUpdater) throw new Error('plugin_unavailable')
    const latest = await mod.CapacitorUpdater.getLatest()
    if (latest?.url) {
      latestKnown.value = { version: latest.version, url: latest.url, checksum: latest.checksum }
      lastResult.value  = 'available'
    } else if (latest?.error === 'no_new_version_available' || latest?.kind === 'up_to_date') {
      lastResult.value = 'no_new'
    } else if (latest?.kind === 'blocked') {
      lastResult.value = 'blocked'
      lastError.value  = latest.error || latest.message || 'Update blocked by server'
    } else {
      lastResult.value = latest?.error ? 'failed' : 'no_new'
      lastError.value  = latest?.message || null
    }
    persist()
    return { ok: true, latest }
  } catch (e) {
    lastResult.value = 'failed'
    lastError.value  = e?.message || 'Update check failed'
    persist()
    return { ok: false, reason: 'transport', error: e?.message }
  } finally {
    isChecking.value = false
  }
}

async function applyDownloadedNow() {
  if (!isNative.value || !latestKnown.value?.version) {
    return { ok: false, reason: 'nothing_to_apply' }
  }
  isApplying.value = true
  try {
    const mod = await loadPlugin()
    if (!mod?.CapacitorUpdater) throw new Error('plugin_unavailable')
    // Reload — plugin will swap to the downloaded bundle on next boot.
    await mod.CapacitorUpdater.reload()
    return { ok: true }
  } catch (e) {
    lastError.value = e?.message || 'Reload failed'
    return { ok: false, error: e?.message }
  } finally {
    isApplying.value = false
  }
}

const updateAvailable = computed(() =>
  !!(latestKnown.value && currentBundle.value && latestKnown.value.version && latestKnown.value.version !== currentBundle.value.version)
)
const downloaded = computed(() => lastResult.value === 'downloaded')

export function useAppUpdates() {
  return {
    // state (read-only outside the composable)
    isNative:        readonly(isNative),
    pluginReady:     readonly(pluginReady),
    currentBundle:   readonly(currentBundle),
    latestKnown:     readonly(latestKnown),
    downloadPct:     readonly(downloadPct),
    isChecking:      readonly(isChecking),
    isDownloading:   readonly(isDownloading),
    isApplying:      readonly(isApplying),
    lastCheckAt:     readonly(lastCheckAt),
    lastResult:      readonly(lastResult),
    lastError:       readonly(lastError),
    pluginVersion:   readonly(pluginVersion),
    channel:         readonly(channel),
    deviceId:        readonly(deviceId),
    updateAvailable, downloaded,
    // actions
    init, checkNow, applyDownloadedNow,
  }
}

// Auto-init on first import (idempotent — guarded by initPromise)
init().catch(e => console.debug('[capgo] init swallowed error:', e?.message))
