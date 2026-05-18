/**
 * services/plugins/mlkitInstaller.js — JS side of the Sentinel Model Manager's
 * native ML Kit module installer.
 *
 * On Android: delegates to the custom Capacitor plugin `ModuleInstall` defined
 * in android/app/src/main/java/io/ionic/starter/sentinel/ModuleInstallPlugin.java.
 * That plugin wraps Google Play Services' ModuleInstallClient and the MLKit
 * client classes that implement OptionalModuleApi (TextRecognizer, FaceDetector).
 * It streams progress back via the plugin event "progress".
 *
 * On web / dev: simulates a ~3 s download with six progress ticks so the
 * ModelManagerView's state machine can be exercised with `npm run dev`.
 *
 * Recognised `moduleId` values (stable strings shared with modelManager.js
 * MODEL_REGISTRY entries' `downloader` field):
 *
 *   mlkit-text-recognition-latin  → "text-recognition-latin" on the native side
 *   mlkit-face-detection          → "face-detection"
 *   play-services-builtin         → "doc-scanner" | "smart-reply" (no install)
 *   mlkit-translate               → translate-<pair>   (library-not-bundled today)
 *   mlkit-entity-extraction       → entity-extraction  (library-not-bundled today)
 *   local-asset                   → always ok (APK-bundled embedding models)
 *
 * Contract:
 *   - Never throws.
 *   - Resolves with { ok: true } on success, or { ok: false, reason: <string> }
 *     on any failure. The model manager interprets specific reasons
 *     non-retriably (library-not-bundled, invalid-module-id, etc.).
 */

import { makePluginSandbox } from './_base.js'

// Sandbox is still used for gate / circuit-breaker / platform probe, even
// though the real work is dispatched via the registerPlugin bridge below.
const sandbox = makePluginSandbox({
  name: 'mlkit-installer',
  importer: () => import('@capacitor/core'),
  settingsKey: undefined,      // gate is per-model in modelManager, not here
  requiresNative: true,
  sentinel: true,
})

/** Cached handle to the native plugin. Resolved lazily, once. */
let _nativePluginPromise = null
async function getNativePlugin() {
  if (_nativePluginPromise) return _nativePluginPromise
  _nativePluginPromise = (async () => {
    try {
      const { registerPlugin, Capacitor } = await import('@capacitor/core')
      const plat = Capacitor?.getPlatform?.()
      if (plat !== 'android' && plat !== 'ios') return null
      // registerPlugin returns a Proxy that delegates calls to the named
      // native plugin. If no native impl is registered (e.g. plugin class
      // missing from the APK), every method call will throw at use-time —
      // we catch that in installModule below.
      const ModuleInstall = registerPlugin('ModuleInstall', {
        // No web fallback: on web we take the mock path in installModule
        // directly, never reaching this shim.
      })
      return ModuleInstall || null
    } catch (err) {
      console.debug('[mlkit-installer] getNativePlugin failed:', err?.message)
      return null
    }
  })()
  return _nativePluginPromise
}

/** Platform probe kept separate so it's cheap to call repeatedly. */
async function isNativePlatform() {
  try {
    const { Capacitor } = await import('@capacitor/core')
    const plat = Capacitor?.getPlatform?.()
    return plat === 'android' || plat === 'ios'
  } catch {
    return false
  }
}

/**
 * Downloaders whose ML Kit feature library is not bundled in the current
 * APK. See docs/sentinel-plan/UNBUNDLED.md (2026-04-26). When the
 * MODEL_REGISTRY entry lists one of these, we short-circuit at the JS layer
 * with reason="module-not-bundled" — saves a JNI hop and matches the
 * non-retriable reason the native plugin returns when it sees the same id.
 *
 * To rebundle a feature: re-add the implementation line in
 * android/app/build.gradle, restore the imports + resolveApi() branch in
 * ModuleInstallPlugin.java, then remove that downloader from this set.
 */
const UNBUNDLED_DOWNLOADERS = new Set([
  'mlkit-text-recognition-latin',
  'mlkit-face-detection',
  'mlkit-translate',
  'mlkit-entity-extraction',
])

/**
 * Translate a MODEL_REGISTRY entry's `downloader` + `id` into the native
 * moduleId the Kotlin plugin expects. The native plugin's resolveApi() table
 * is authoritative — see ModuleInstallPlugin.java.
 *
 * Returns:
 *   { kind: 'native',   nativeId: string }      — call the native plugin
 *   { kind: 'builtin'                   }       — Play Services built-in; resolve ok
 *   { kind: 'local'                     }       — APK-bundled asset; resolve ok
 *   { kind: 'unbundled', reason: 'module-not-bundled' }  — feature lib stripped from APK
 *   { kind: 'unsupported', reason: string }     — not-yet-wired on JS side
 */
function resolveNativeId(modelId, downloader) {
  if (downloader === 'local-asset')         return { kind: 'local' }
  if (downloader === 'play-services-builtin') return { kind: 'builtin' }
  if (UNBUNDLED_DOWNLOADERS.has(downloader)) {
    return { kind: 'unbundled', reason: 'module-not-bundled' }
  }
  // Test fixture: routed through native install path so vitest can drive the
  // state machine via a mocked `installModule`. On a real device this falls
  // through to the native plugin's resolveApi() default and resolves with
  // reason="library-not-bundled" — non-retriable, harmless if it ever leaks.
  if (downloader === 'test-fixture') {
    return { kind: 'native', nativeId: '__test_install_fixture' }
  }
  return { kind: 'unsupported', reason: `unknown-downloader:${downloader}` }
}

/** Listener plumbing for the native "progress" event. One module per call. */
async function attachProgressListener(plugin, moduleId, onProgress) {
  if (!plugin || typeof plugin.addListener !== 'function' || !onProgress) return null
  try {
    const handle = await plugin.addListener('progress', (evt) => {
      if (!evt || evt.moduleId !== moduleId) return
      try {
        const total = Number(evt.bytesTotal) || 0
        const soFar = Number(evt.bytesSoFar) || 0
        onProgress({
          bytesSoFar:  soFar,
          bytesTotal:  total,
          progressPct: total > 0 ? Math.round((soFar / total) * 100) : 0,
        })
      } catch { /* listener errors are non-fatal */ }
    })
    return handle
  } catch (err) {
    console.debug('[mlkit-installer] progress listener attach failed:', err?.message)
    return null
  }
}

async function detachListener(handle) {
  if (!handle) return
  try {
    if (typeof handle.remove === 'function') await handle.remove()
  } catch { /* detach failures are non-fatal */ }
}

/**
 * Install (download) an ML Kit / Play Services module.
 *
 * @param {string} moduleId              registry id, e.g. "mrz-latin"
 * @param {{
 *   downloader?: string,
 *   onProgress?: (p:{bytesSoFar:number,bytesTotal:number,progressPct:number}) => void,
 * }} opts
 * @returns {Promise<{ok:true, alreadyInstalled?:boolean} | {ok:false, reason:string}>}
 */
export async function installModule(moduleId, opts = {}) {
  const onProgress = typeof opts.onProgress === 'function' ? opts.onProgress : null
  const downloader = typeof opts.downloader === 'string' ? opts.downloader : ''

  if (!moduleId || typeof moduleId !== 'string') {
    return { ok: false, reason: 'invalid-module-id' }
  }

  try {
    const native = await isNativePlatform()

    if (!native) {
      return await runMockDownload(onProgress)
    }

    // Native path — map the registry's downloader+id to the native plugin's
    // moduleId vocabulary.
    const resolved = resolveNativeId(moduleId, downloader)

    if (resolved.kind === 'local' || resolved.kind === 'builtin') {
      return { ok: true, alreadyInstalled: true }
    }
    if (resolved.kind === 'unbundled' || resolved.kind === 'unsupported') {
      // Both are non-retriable in modelManager.js. "module-not-bundled" is
      // the stable reason the native plugin returns for the same modules,
      // so JS callers see the same string on web and on native.
      return { ok: false, reason: resolved.reason }
    }

    const plugin = await getNativePlugin()
    if (!plugin) {
      // Capacitor reported native platform but no plugin registered — this
      // should not happen in a correctly-built APK (ModuleInstallPlugin is
      // registered in MainActivity.onCreate). Treat as non-retriable so the
      // model manager stamps max retries.
      return { ok: false, reason: 'native-plugin-not-registered' }
    }

    const listenerHandle = await attachProgressListener(plugin, resolved.nativeId, onProgress)
    try {
      // Wall-clock guard: if the bridge or the underlying Play Services call
      // hangs (no resolve, no reject, no progress events), fall through to a
      // user-visible error instead of leaving the model card stuck on
      // "downloading 0%" forever. 5 minutes accommodates slow 3G + first-
      // install handshake on a cold device; anything beyond that is a real
      // failure regardless of cause.
      const INSTALL_TIMEOUT_MS = 5 * 60 * 1000
      const installPromise = plugin.install({ moduleId: resolved.nativeId })
      const timeoutPromise = new Promise((resolve) => {
        setTimeout(() => resolve({ ok: false, reason: 'install-timeout' }), INSTALL_TIMEOUT_MS)
      })
      const result = await Promise.race([installPromise, timeoutPromise])
      if (!result || typeof result !== 'object') {
        return { ok: false, reason: 'malformed-plugin-response' }
      }
      if (result.ok === true) {
        return { ok: true, alreadyInstalled: !!result.alreadyInstalled }
      }
      return { ok: false, reason: String(result.reason || 'install-failed') }
    } finally {
      await detachListener(listenerHandle)
    }
  } catch (err) {
    console.debug('[mlkit-installer] installModule failed:', err?.message)
    return { ok: false, reason: 'install-error' }
  }
}

/**
 * Best-effort availability probe — "is this module already installed?"
 *
 * @param {string} moduleId
 * @param {{ downloader?: string }} opts
 * @returns {Promise<boolean>}
 */
export async function isModuleAvailable(moduleId, opts = {}) {
  if (!moduleId || typeof moduleId !== 'string') return false
  const downloader = typeof opts.downloader === 'string' ? opts.downloader : ''
  try {
    const native = await isNativePlatform()
    if (!native) {
      return downloader === 'play-services-builtin' || downloader === 'local-asset'
    }
    const resolved = resolveNativeId(moduleId, downloader)
    if (resolved.kind === 'local' || resolved.kind === 'builtin') return true
    if (resolved.kind === 'unsupported') return false
    const plugin = await getNativePlugin()
    if (!plugin) return false
    const result = await plugin.isAvailable({ moduleId: resolved.nativeId })
    return !!(result && result.ok && result.available)
  } catch {
    return false
  }
}

// ── internals ──────────────────────────────────────────────────────────────

async function runMockDownload(onProgress) {
  // Web / dev: simulate a ~3 s download with six progress ticks. Must never
  // throw, so we wrap the onProgress call in try/catch.
  const totalBytes = 1_000_000
  const steps = 6
  const stepMs = 500
  for (let i = 1; i <= steps; i++) {
    await new Promise((resolve) => setTimeout(resolve, stepMs))
    if (onProgress) {
      try {
        onProgress({
          bytesSoFar:  Math.round((totalBytes * i) / steps),
          bytesTotal:  totalBytes,
          progressPct: Math.round((i / steps) * 100),
        })
      } catch { /* listener errors must never fail the install */ }
    }
  }
  return { ok: true }
}

// Export the sandbox for introspection (breaker state, native-support probe).
// Callers must not call sandbox.run() directly — always go through
// installModule() / isModuleAvailable() above.
export const _sandbox = sandbox
