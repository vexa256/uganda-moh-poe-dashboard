/**
 * services/plugins/barcode.js — MLKit barcode/QR scanning (on-device, offline).
 *
 * Reference: https://github.com/capawesome-team/capacitor-mlkit/tree/main/packages/barcode-scanning
 *
 * Exports:
 *   - scanOnce({ formats, onInstallProgress }) → Promise<{ value, format } | null>
 *   - requestPermissions() → boolean
 *   - ensureModuleInstalled({ onProgress, timeoutMs }) → boolean (waits for completion)
 *
 * Implementation notes
 * --------------------------------------------------------------------------
 * `BarcodeScanner.scan()` (the method we call) uses Google's
 * `GmsBarcodeScanner` — i.e. the **on-demand Code Scanner module from
 * Play Services**, NOT the bundled ML Kit library. On a fresh device the
 * module is not yet downloaded; calling `scan()` while the module is still
 * downloading shows the camera but never renders the scanner UI ("camera
 * opens but nothing happens"). This file's contract is therefore:
 *
 *   1. `scanOnce()` ALWAYS waits for the module to be ready before launching
 *      the scanner. If `isGoogleBarcodeScannerModuleAvailable()` is false,
 *      it kicks off `installGoogleBarcodeScannerModule()` and listens for
 *      the `googleBarcodeScannerModuleInstallProgress` event until the
 *      install reaches state COMPLETED, FAILED, or CANCELED — or a 90 s
 *      hard timeout fires.
 *   2. `scanOnce()` accepts an optional `onInstallProgress` callback so the
 *      caller can render "Downloading scanner — N%" UI during the wait.
 *      The plugin only emits these events on Android.
 *   3. If the install fails or the device lacks Play Services Code Scanner
 *      entirely, `scanOnce` returns `null` and the caller falls back to
 *      manual entry — same contract as before.
 *
 * If the MLKit native module is missing (web build), the wrapper returns
 * null and callers silently fall back to the existing manual-entry path.
 */

import { makePluginSandbox } from './_base'
import { CAPABILITY_KEYS } from '../capabilities'

const sandbox = makePluginSandbox({
  name: 'barcode',
  // 2026-04-28: @capacitor-mlkit/barcode-scanning removed from package.json
  // to slim the APK by ~20 MB (libbarhopper_v3.so). The dynamic-import remains
  // so a future re-bundling only needs to re-add the npm package — no code
  // change here. /* @vite-ignore */ + a string variable prevent Vite from
  // failing the build when the module is absent; at runtime, the import
  // rejects → loadModule() returns null → sandbox.run falls back to
  // { ok: false / null } and views fall back to manual entry.
  importer: async () => {
    try {
      const m = '@capacitor-mlkit/barcode-scanning'
      return await import(/* @vite-ignore */ m)
    } catch { return null }
  },
  settingsKey: CAPABILITY_KEYS.BARCODE,
})

// GoogleBarcodeScannerModuleInstallState (Android only). Numeric form ships
// in @capacitor-mlkit/barcode-scanning ≥5.1; older builds may surface the
// string form via `state` — we check both.
const STATE_COMPLETED = 4
const STATE_FAILED    = 5
const STATE_CANCELED  = 3

// Hard wall-clock so a stuck download never blocks the UI forever.
const INSTALL_TIMEOUT_MS = 90_000

export async function isSupported() {
  return sandbox.run(async ({ BarcodeScanner }) => {
    const r = await BarcodeScanner.isSupported()
    return !!r?.supported
  }, { fallback: false })
}

export async function requestPermissions() {
  return sandbox.run(async ({ BarcodeScanner }) => {
    const cur = await BarcodeScanner.checkPermissions()
    if (cur?.camera === 'granted' || cur?.camera === 'limited') return true
    const req = await BarcodeScanner.requestPermissions()
    return req?.camera === 'granted' || req?.camera === 'limited'
  }, { fallback: false })
}

/**
 * Ensure the Google Barcode Scanner module is installed and ready.
 *
 * Returns true if the module is already installed OR finishes installing
 * during this call. Returns false if install fails, is canceled, or times
 * out — caller should fall back to manual entry.
 *
 * Optional `onProgress({ state, progress })` is invoked for each install
 * progress event so the UI can render a "Downloading — N%" status.
 *
 * Safe to call repeatedly; the first call that finds the module already
 * installed returns immediately without listener setup.
 */
export async function ensureModuleInstalled({ onProgress, timeoutMs } = {}) {
  return sandbox.run(async ({ BarcodeScanner }) => {
    // Plugin too old to expose the install API — assume always ready (the
    // older native code paths bundle the lib unconditionally).
    if (typeof BarcodeScanner.isGoogleBarcodeScannerModuleAvailable !== 'function') {
      return true
    }
    const a = await BarcodeScanner.isGoogleBarcodeScannerModuleAvailable()
    if (a?.available) return true

    // If the plugin version doesn't expose addListener, we cannot wait for
    // the install state — fall back to fire-and-forget install. Caller will
    // see camera-but-no-scan on the very first launch; subsequent launches
    // will succeed once Play Services finishes the background install.
    if (typeof BarcodeScanner.addListener !== 'function') {
      try { await BarcodeScanner.installGoogleBarcodeScannerModule() } catch { /* swallowed; we report false to caller */ }
      return false
    }

    // Attach the progress listener BEFORE kicking off the install so we
    // never miss the first event.
    let listenerHandle = null
    const installCompleted = new Promise((resolve) => {
      let timer = null
      const cleanup = () => {
        if (timer) clearTimeout(timer)
        try { listenerHandle?.remove?.() } catch { /* listener detach is best-effort */ }
      }
      timer = setTimeout(() => { cleanup(); resolve(false) }, timeoutMs ?? INSTALL_TIMEOUT_MS)
      try {
        const p = BarcodeScanner.addListener('googleBarcodeScannerModuleInstallProgress', (evt) => {
          if (typeof onProgress === 'function') {
            try { onProgress({ state: evt?.state, progress: evt?.progress }) } catch { /* listener errors are non-fatal */ }
          }
          const s = evt?.state
          if (s === STATE_COMPLETED || s === 'COMPLETED') { cleanup(); resolve(true);  return }
          if (s === STATE_FAILED    || s === 'FAILED')    { cleanup(); resolve(false); return }
          if (s === STATE_CANCELED  || s === 'CANCELED')  { cleanup(); resolve(false); return }
        })
        if (p && typeof p.then === 'function') {
          p.then((h) => { listenerHandle = h }).catch(() => { /* listener registration failed; the timeout will resolve */ })
        }
      } catch { /* synchronous addListener failure → timeout will resolve */ }

      // Kick off the install. The promise it returns resolves immediately —
      // we wait for the event stream to learn the final state.
      try {
        const ip = BarcodeScanner.installGoogleBarcodeScannerModule()
        if (ip && typeof ip.catch === 'function') {
          ip.catch(() => { cleanup(); resolve(false) })
        }
      } catch { cleanup(); resolve(false) }
    })

    const installed = await installCompleted
    if (!installed) return false

    // Re-check availability — defends against a flaky install reporting
    // COMPLETED but failing to actually register the module.
    const recheck = await BarcodeScanner.isGoogleBarcodeScannerModuleAvailable()
    return !!recheck?.available
  }, { fallback: true })
}

/**
 * Scan one barcode. On Android this drives Google Code Scanner, which
 * requires the on-demand module — `scanOnce` waits for the module install
 * to complete before launching the scanner activity. Without this wait the
 * camera opens but no scanner UI renders ("camera opens, nothing happens").
 *
 * NOTE: Code Scanner needs a network connection on first use to download
 * the module. For offline-first deployments use scanFromImage() instead.
 */
export async function scanOnce({ formats, onInstallProgress } = {}) {
  return sandbox.run(async ({ BarcodeScanner }) => {
    if (!(await requestPermissions())) return null
    const ready = await ensureModuleInstalled({ onProgress: onInstallProgress })
    if (!ready) return null
    const res = await BarcodeScanner.scan({ formats: formats || undefined })
    const list = Array.isArray(res?.barcodes) ? res.barcodes : []
    const first = list[0]
    if (!first) return null
    return {
      value: first.rawValue ?? first.displayValue ?? '',
      format: first.format ?? 'UNKNOWN',
      valueType: first.valueType ?? 'UNKNOWN',
    }
  }, { fallback: null })
}

/**
 * Decode a barcode from an image file (offline). Uses the BUNDLED
 * `com.google.mlkit:barcode-scanning` library (libbarhopper_v3.so) — no
 * Play Services Code Scanner module required. This is the primary path
 * for offline-first deployments: take a photo with @capacitor/camera and
 * pass the path here.
 *
 * Hardening: validates the path string, swallows any plugin throw via
 * the sandbox, and falls back to null on any error. Selects the
 * highest-quality barcode when multiple are detected — boarding passes
 * sometimes have a small QR alongside the primary PDF417, and the
 * reading order from ML Kit isn't guaranteed.
 *
 * Returns { value, format, valueType } or null when no barcode is found.
 */
export async function scanFromImage(path, { formats } = {}) {
  if (!path || typeof path !== 'string') return null
  return sandbox.run(async ({ BarcodeScanner }) => {
    let res = null
    try {
      res = await BarcodeScanner.readBarcodesFromImage({
        path,
        formats: formats || undefined,
      })
    } catch (err) {
      console.debug('[barcode] readBarcodesFromImage threw:', err?.message)
      return null
    }
    const list = Array.isArray(res?.barcodes) ? res.barcodes : []
    if (!list.length) return null
    // Prefer the longest payload — that's almost always the primary
    // boarding-pass code rather than a small URL QR on the same image.
    let best = list[0]
    let bestLen = (best?.rawValue || best?.displayValue || '').length
    for (let i = 1; i < list.length; i++) {
      const v = list[i]?.rawValue || list[i]?.displayValue || ''
      if (v.length > bestLen) { best = list[i]; bestLen = v.length }
    }
    if (!best) return null
    return {
      value: best.rawValue ?? best.displayValue ?? '',
      format: best.format ?? 'UNKNOWN',
      valueType: best.valueType ?? 'UNKNOWN',
    }
  }, { fallback: null })
}

export async function isAvailable() { return sandbox.isAvailable() }
