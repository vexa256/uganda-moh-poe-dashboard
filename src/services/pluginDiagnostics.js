/**
 * services/pluginDiagnostics.js — runtime self-test for every Capacitor /
 * native plugin the app uses. Designed to be invoked from the in-app
 * Settings → "Plugin diagnostics" view so a developer (or a field tech
 * trying to triage on a real device) can see exactly:
 *
 *   - which plugin works
 *   - which is gated off (and which localStorage key to flip)
 *   - which is unavailable (no native module / non-android)
 *   - which threw an error and the error message + stack
 *   - which permission is missing
 *
 * Each plugin contributes one diagnostic suite. A suite returns:
 *   {
 *     id:         string,      // 'network', 'biometric', ...
 *     title:      string,      // human label
 *     summary:    string,      // 1-line outcome
 *     overall:    'pass' | 'partial' | 'fail' | 'skip',
 *     durationMs: number,
 *     tests:      Array<TestResult>,
 *   }
 *
 * TestResult:
 *   {
 *     id:       string,
 *     name:     string,
 *     status:   'pass' | 'fail' | 'skip' | 'warn',
 *     detail:   string,        // what happened
 *     hint?:    string,        // remediation hint
 *     error?:   { message, name, stack }, // full error surface
 *     durationMs: number,
 *   }
 *
 * Design rules:
 *   - No probe is destructive. We never schedule a real notification, write
 *     a real PIN, share a real file, or trigger a real biometric prompt.
 *   - Every probe wraps in try/catch and serialises any thrown error.
 *   - All probes have a hard timeout so a hung native call cannot hang the UI.
 *   - Output is JSON-serialisable for "Copy report" in the view.
 */

import { Capacitor } from '@capacitor/core'

// ─── helpers ────────────────────────────────────────────────────────────────

const DEFAULT_TIMEOUT_MS = 6000

function nowMs() { return (typeof performance !== 'undefined' ? performance.now() : Date.now()) }

function serialiseError(err) {
  if (!err) return null
  return {
    name:    err.name    || 'Error',
    message: err.message || String(err),
    stack:   err.stack   ? String(err.stack).split('\n').slice(0, 8).join('\n') : null,
  }
}

async function withTimeout(label, fn, timeoutMs = DEFAULT_TIMEOUT_MS) {
  let timer
  try {
    return await Promise.race([
      Promise.resolve().then(fn),
      new Promise((_, reject) => {
        timer = setTimeout(() => reject(new Error(`${label} timed out after ${timeoutMs}ms`)), timeoutMs)
      }),
    ])
  } finally {
    clearTimeout(timer)
  }
}

function safeLs(key) {
  try { return localStorage.getItem(key) } catch { return null }
}

function makeTest(id, name) {
  const start = nowMs()
  return {
    id, name,
    status: 'skip',
    detail: '',
    hint: undefined,
    error: null,
    durationMs: 0,
    pass(detail) {
      this.status = 'pass'; this.detail = detail || 'OK'
      this.durationMs = Math.round(nowMs() - start)
      return this
    },
    fail(detail, opts = {}) {
      this.status = 'fail'; this.detail = detail
      if (opts.hint) this.hint = opts.hint
      if (opts.error) this.error = serialiseError(opts.error)
      this.durationMs = Math.round(nowMs() - start)
      return this
    },
    warn(detail, opts = {}) {
      this.status = 'warn'; this.detail = detail
      if (opts.hint) this.hint = opts.hint
      if (opts.error) this.error = serialiseError(opts.error)
      this.durationMs = Math.round(nowMs() - start)
      return this
    },
    skip(detail) {
      this.status = 'skip'; this.detail = detail || 'Skipped'
      this.durationMs = Math.round(nowMs() - start)
      return this
    },
  }
}

function rollUp(tests) {
  const has = (s) => tests.some(t => t.status === s)
  if (tests.length === 0) return 'skip'
  if (has('fail')) return tests.every(t => t.status === 'fail' || t.status === 'skip') ? 'fail' : 'partial'
  if (has('warn')) return 'partial'
  if (tests.every(t => t.status === 'skip')) return 'skip'
  return 'pass'
}

function platformInfo() {
  let plat = 'unknown'
  let isNative = false
  try {
    plat = Capacitor?.getPlatform?.() || 'unknown'
    isNative = plat === 'android' || plat === 'ios'
  } catch (_e) { /* swallow */ }
  return { plat, isNative }
}

// Wraps a suite — captures top-level error so a single broken probe doesn't
// kill the entire diagnostic run.
async function runSuite(id, title, suiteFn) {
  const start = nowMs()
  let tests = []
  let summaryOverride = null
  try {
    const result = await suiteFn()
    tests = Array.isArray(result?.tests) ? result.tests : (Array.isArray(result) ? result : [])
    summaryOverride = result?.summary
  } catch (err) {
    tests = [{
      id: 'suite-init', name: 'Suite initialisation',
      status: 'fail',
      detail: 'The diagnostic suite itself crashed before running tests.',
      hint: 'Check the imports / module load for the plugin wrapper.',
      error: serialiseError(err),
      durationMs: Math.round(nowMs() - start),
    }]
  }
  const overall = rollUp(tests)
  const durationMs = Math.round(nowMs() - start)
  const summary = summaryOverride || (
    overall === 'pass'    ? `${tests.length} probe(s) passed.`
    : overall === 'fail'  ? `Plugin is not functional in this environment.`
    : overall === 'skip'  ? `Skipped (not applicable on this device).`
                          : `${tests.filter(t => t.status === 'pass').length}/${tests.length} probes passed; see details.`
  )
  return { id, title, summary, overall, durationMs, tests }
}

// ─── individual suites ─────────────────────────────────────────────────────

async function suiteEnvironment() {
  const tests = []
  // Test 1: Capacitor presence
  {
    const t = makeTest('cap-present', 'Capacitor core present')
    try {
      if (Capacitor && typeof Capacitor.getPlatform === 'function') t.pass(`Capacitor.getPlatform()='${Capacitor.getPlatform()}'`)
      else t.fail('Capacitor object missing or malformed.', { hint: 'Re-run `npx cap sync android` and rebuild.' })
    } catch (err) { t.fail('Threw while reading Capacitor.', { error: err }) }
    tests.push(t)
  }
  // Test 2: Platform probe
  {
    const t = makeTest('platform', 'Platform detection')
    const { plat, isNative } = platformInfo()
    if (plat === 'unknown') t.warn('Platform reported as "unknown".', { hint: 'On the web, this is normal.' })
    else if (isNative) t.pass(`Native platform: ${plat}`)
    else t.warn(`Non-native (${plat}) — native plugins will fall back or no-op.`, { hint: 'Open the app on Android/iOS to test the native paths.' })
    tests.push(t)
  }
  // Test 3: localStorage available
  {
    const t = makeTest('localstorage', 'localStorage write/read')
    try {
      const k = '__diag_ls_probe__', v = String(Date.now())
      localStorage.setItem(k, v)
      const got = localStorage.getItem(k)
      localStorage.removeItem(k)
      if (got === v) t.pass('Read-back matches.')
      else t.fail(`Read-back mismatch: wrote '${v}', got '${got}'.`)
    } catch (err) { t.fail('localStorage unavailable.', { error: err, hint: 'Capability flags + auth session need this.' }) }
    tests.push(t)
  }
  // Test 4: IndexedDB available
  {
    const t = makeTest('indexeddb', 'IndexedDB available')
    try {
      const ok = typeof indexedDB !== 'undefined' && typeof indexedDB.open === 'function'
      if (ok) t.pass('indexedDB.open is callable.')
      else t.fail('indexedDB missing.', { hint: 'Offline-first storage will not work.' })
    } catch (err) { t.fail('Threw while probing indexedDB.', { error: err }) }
    tests.push(t)
  }
  // Test 5: navigator.onLine
  {
    const t = makeTest('navigator-online', 'navigator.onLine value')
    try {
      const v = navigator?.onLine
      if (typeof v === 'boolean') t.pass(`navigator.onLine=${v}`)
      else t.warn('navigator.onLine is not a boolean — wrapper falls back to plugin.', { hint: 'On Android WebView this can lie; the @capacitor/network plugin is authoritative.' })
    } catch (err) { t.fail('Threw reading navigator.onLine.', { error: err }) }
    tests.push(t)
  }
  return { tests }
}

async function suiteSandbox() {
  const tests = []
  let makePluginSandbox
  {
    const t = makeTest('import-base', 'Sandbox factory imports cleanly')
    try {
      const mod = await withTimeout('import _base.js', () => import('./plugins/_base.js'))
      makePluginSandbox = mod.makePluginSandbox
      if (typeof makePluginSandbox === 'function') t.pass('makePluginSandbox is exported.')
      else t.fail('makePluginSandbox missing from _base.js exports.')
    } catch (err) { t.fail('Could not import plugins/_base.js.', { error: err }) }
    tests.push(t)
  }
  if (!makePluginSandbox) return { tests }

  // Construct a throwaway sandbox and drive the breaker briefly.
  {
    const t = makeTest('breaker-shape', 'Sandbox exposes breaker state')
    try {
      const sb = makePluginSandbox({ name: 'diag-probe', importer: async () => ({}), requiresNative: false })
      const s = sb.breakerState()
      if (typeof s?.tripped === 'boolean') t.pass(`breakerState() shape ok (tripped=${s.tripped})`)
      else t.fail('breakerState() returned unexpected shape.')
    } catch (err) { t.fail('Sandbox construction or breakerState failed.', { error: err }) }
    tests.push(t)
  }
  return { tests }
}

async function suiteNetwork() {
  const tests = []
  // Module import probe
  let mod
  {
    const t = makeTest('import-pkg', 'Imports @capacitor/network')
    try {
      mod = await withTimeout('import network', () => import('@capacitor/network'))
      if (mod?.Network) t.pass('Module + Network export present.')
      else t.fail('Network export missing.', { hint: 'Run `npx cap sync android`.' })
    } catch (err) { t.fail('Could not import @capacitor/network.', { error: err, hint: 'Package may not be installed.' }) }
    tests.push(t)
  }
  // Wrapper import + getStatus
  {
    const t = makeTest('wrapper-getstatus', 'Wrapper getStatus() returns a status')
    try {
      const wrap = await withTimeout('import wrapper', () => import('./plugins/network.js'))
      const s = await withTimeout('getStatus', () => wrap.getStatus(), 4000)
      if (s && typeof s.connected === 'boolean') {
        t.pass(`connected=${s.connected}, type=${s.connectionType || 'unknown'}`)
      } else t.fail('Wrapper returned an unexpected shape.', { hint: 'Open plugins/network.js → getStatus().' })
    } catch (err) { t.fail('Wrapper getStatus() threw or timed out.', { error: err }) }
    tests.push(t)
  }
  // Subscribe / lastKnown
  {
    const t = makeTest('subscribe', 'subscribe() fires immediately and returns an unsubscribe fn')
    try {
      const wrap = await import('./plugins/network.js')
      let fired = false
      const off = wrap.subscribe(() => { fired = true })
      if (!fired) { off?.(); t.fail('Listener was not fired immediately on subscribe.', { hint: 'subscribe() should sync-fire with lastStatus.' }) }
      else if (typeof off !== 'function') t.fail('subscribe() did not return an unsubscribe function.')
      else { off(); t.pass('Listener fired and unsubscribe is a function.') }
    } catch (err) { t.fail('subscribe() threw.', { error: err }) }
    tests.push(t)
  }
  return { tests }
}

async function suiteBiometric() {
  const tests = []
  const { isNative } = platformInfo()
  let wrap
  {
    const t = makeTest('wrapper-import', 'Wrapper imports')
    try {
      wrap = await withTimeout('import wrapper', () => import('./plugins/biometric.js'))
      if (typeof wrap?.hasPin === 'function') t.pass('hasPin / enrollPin / verifyPin exported.')
      else t.fail('Expected exports missing.')
    } catch (err) { t.fail('Could not import wrapper.', { error: err }) }
    tests.push(t)
  }
  if (!wrap) return { tests }

  // Enrolment state (read-only)
  {
    const t = makeTest('has-pin', 'Reports current PIN enrolment')
    try {
      const ok = typeof wrap.hasPin() === 'boolean'
      if (ok) t.pass(`hasPin()=${wrap.hasPin()}`)
      else t.fail('hasPin() did not return a boolean.')
    } catch (err) { t.fail('hasPin() threw.', { error: err }) }
    tests.push(t)
  }

  // Crypto round-trip with a sentinel PIN — does NOT touch user-enrolled data.
  {
    const t = makeTest('crypto-roundtrip', 'PIN hash round-trips correctly (sentinel PIN)')
    const PIN_HASH_KEY = 'app.lock.pin_hash'
    const PIN_SALT_KEY = 'app.lock.pin_salt'
    const BIO_OK_KEY   = 'app.lock.bio_ok'
    // Snapshot existing enrolment so we can restore it after the probe.
    const snap = {
      hash: safeLs(PIN_HASH_KEY),
      salt: safeLs(PIN_SALT_KEY),
      bio:  safeLs(BIO_OK_KEY),
    }
    try {
      // Wipe → enroll a probe PIN → verify good + bad → restore snapshot.
      try { localStorage.removeItem(PIN_HASH_KEY); localStorage.removeItem(PIN_SALT_KEY); localStorage.removeItem(BIO_OK_KEY) } catch {}
      const enrolled = await wrap.enrollPin('991122')
      if (!enrolled) throw new Error('enrollPin returned false for valid 6-digit PIN')
      const good = await wrap.verifyPin('991122')
      const bad  = await wrap.verifyPin('123456')
      if (good && !bad) t.pass('verifyPin returned true for the right PIN, false for the wrong one.')
      else t.fail(`verifyPin returned good=${good}, bad=${bad}.`, { hint: 'Check sha256Hex/randomSalt in plugins/biometric.js.' })
    } catch (err) {
      t.fail('Crypto round-trip threw.', { error: err, hint: 'crypto.subtle may be unavailable; the wrapper falls back to a non-cryptographic hash.' })
    } finally {
      // Restore previous enrolment, if any.
      try {
        if (snap.hash) localStorage.setItem(PIN_HASH_KEY, snap.hash); else localStorage.removeItem(PIN_HASH_KEY)
        if (snap.salt) localStorage.setItem(PIN_SALT_KEY, snap.salt); else localStorage.removeItem(PIN_SALT_KEY)
        if (snap.bio)  localStorage.setItem(BIO_OK_KEY,   snap.bio);  else localStorage.removeItem(BIO_OK_KEY)
      } catch {}
    }
    tests.push(t)
  }

  // Hardware probe (NEVER prompts the user)
  {
    const t = makeTest('hw-probe', 'Hardware probe (biometricAvailable)')
    try {
      const r = await withTimeout('biometricAvailable', () => wrap.biometricAvailable(), 4000)
      if (!isNative) t.warn(`Non-native: hardware probe returned ${JSON.stringify(r)}.`)
      else if (r?.available) t.pass(`Hardware reports available: type=${r.type}`)
      else t.warn('Hardware reports unavailable. The PIN fallback still works.', { hint: 'Enrol a fingerprint/face in OS settings to enable.' })
    } catch (err) { t.fail('biometricAvailable() threw.', { error: err }) }
    tests.push(t)
  }
  return { tests }
}

async function suiteKeepAwake() {
  const tests = []
  let wrap
  {
    const t = makeTest('wrapper-import', 'Wrapper imports')
    try {
      wrap = await withTimeout('import', () => import('./plugins/keepAwake.js'))
      if (typeof wrap?.activate === 'function') t.pass('activate / deactivate exported.')
      else t.fail('Wrapper missing exports.')
    } catch (err) { t.fail('Could not import.', { error: err }) }
    tests.push(t)
  }
  if (!wrap) return { tests }
  {
    const t = makeTest('initial-state', 'Reports inactive at start')
    try {
      const a = wrap.isActive()
      if (a === false) t.pass('isActive()=false (expected on a fresh probe).')
      else t.warn(`isActive()=true — another part of the app already activated keep-awake. The probe will not toggle to avoid disturbing it.`)
    } catch (err) { t.fail('isActive() threw.', { error: err }) }
    tests.push(t)
  }
  {
    const t = makeTest('isavailable', 'Wrapper isAvailable() resolves')
    try {
      const av = await withTimeout('isAvailable', () => wrap.isAvailable(), 4000)
      if (typeof av === 'boolean') t.pass(`isAvailable()=${av}`)
      else t.fail('isAvailable() returned non-boolean.')
    } catch (err) { t.fail('isAvailable() threw.', { error: err }) }
    tests.push(t)
  }
  return { tests }
}

async function suiteLocalNotifications() {
  const tests = []
  const { isNative } = platformInfo()
  let wrap
  {
    const t = makeTest('wrapper-import', 'Wrapper imports')
    try {
      wrap = await withTimeout('import', () => import('./plugins/localNotifications.js'))
      if (typeof wrap?.hasPermission === 'function') t.pass('hasPermission / requestPermission / schedule exported.')
      else t.fail('Wrapper missing exports.')
    } catch (err) { t.fail('Could not import.', { error: err }) }
    tests.push(t)
  }
  if (!wrap) return { tests }

  {
    const t = makeTest('has-permission', 'hasPermission() resolves')
    try {
      const v = await withTimeout('hasPermission', () => wrap.hasPermission(), 4000)
      if (typeof v === 'boolean') {
        if (v) t.pass('Permission is granted.')
        else t.warn('Permission not granted.', { hint: isNative ? 'Tap "Capabilities & help" → toggle reminders, then accept the OS prompt.' : 'On web this is expected.' })
      } else t.fail('Returned non-boolean.')
    } catch (err) { t.fail('hasPermission() threw.', { error: err }) }
    tests.push(t)
  }
  {
    const t = makeTest('list-pending', 'listPending() returns an array')
    try {
      const list = await withTimeout('listPending', () => wrap.listPending(), 4000)
      if (Array.isArray(list)) t.pass(`Pending count: ${list.length}`)
      else t.fail('listPending() did not return an array.')
    } catch (err) { t.fail('listPending() threw.', { error: err }) }
    tests.push(t)
  }
  {
    const t = makeTest('refuses-past', 'schedule() refuses a past date')
    try {
      const ok = await wrap.schedule(987654321, 'diag', 'past', new Date(Date.now() - 60_000))
      if (ok === false) t.pass('schedule(past) correctly refused.')
      else t.fail('schedule(past) returned truthy — wrapper guard is broken.', { hint: 'plugins/localNotifications.js should reject any time < now+5s.' })
    } catch (err) { t.fail('schedule(past) threw.', { error: err }) }
    tests.push(t)
  }
  return { tests }
}

async function suiteShare() {
  const tests = []
  let wrap
  {
    const t = makeTest('wrapper-import', 'Wrapper imports')
    try {
      wrap = await withTimeout('import', () => import('./plugins/share.js'))
      if (typeof wrap?.sharePdf === 'function') t.pass('sharePdf / isAvailable exported.')
      else t.fail('Wrapper missing exports.')
    } catch (err) { t.fail('Could not import.', { error: err }) }
    tests.push(t)
  }
  if (!wrap) return { tests }
  {
    const t = makeTest('isavailable', 'isAvailable() resolves')
    try {
      const av = await withTimeout('isAvailable', () => wrap.isAvailable(), 4000)
      if (typeof av === 'boolean') t.pass(`isAvailable()=${av}`)
      else t.fail('isAvailable() returned non-boolean.')
    } catch (err) { t.fail('isAvailable() threw.', { error: err }) }
    tests.push(t)
  }
  {
    const t = makeTest('blob-arraybuffer', 'Blob.arrayBuffer is available (PDF path needs it)')
    try {
      const b = new Blob([new Uint8Array([1, 2, 3])], { type: 'application/pdf' })
      if (typeof b.arrayBuffer === 'function') {
        const buf = await b.arrayBuffer()
        if (buf?.byteLength === 3) t.pass('Blob.arrayBuffer() produced 3 bytes.')
        else t.fail(`Unexpected byteLength=${buf?.byteLength}`)
      } else t.fail('Blob.arrayBuffer is not a function.', { hint: 'WebView is too old; the share path will degrade to download/no-op.' })
    } catch (err) { t.fail('Blob.arrayBuffer probe threw.', { error: err }) }
    tests.push(t)
  }
  {
    const t = makeTest('createobjecturl', 'URL.createObjectURL is available (download fallback)')
    try {
      if (typeof URL?.createObjectURL === 'function') t.pass('URL.createObjectURL is callable.')
      else t.warn('URL.createObjectURL missing — anchor-download fallback would fail.', { hint: 'On Android WebView this is normally present; check WebView version.' })
    } catch (err) { t.fail('Probe threw.', { error: err }) }
    tests.push(t)
  }
  return { tests }
}

async function suiteBarcode() {
  const tests = []
  const { isNative } = platformInfo()
  let wrap
  {
    const t = makeTest('wrapper-import', 'Wrapper imports')
    try {
      wrap = await withTimeout('import', () => import('./plugins/barcode.js'))
      if (typeof wrap?.scanOnce === 'function') t.pass('scanOnce / requestPermissions / isSupported exported.')
      else t.fail('Wrapper missing exports.')
    } catch (err) { t.fail('Could not import.', { error: err }) }
    tests.push(t)
  }
  if (!wrap) return { tests }
  {
    const t = makeTest('isavailable', 'Wrapper isAvailable()')
    try {
      const av = await withTimeout('isAvailable', () => wrap.isAvailable(), 4000)
      if (typeof av === 'boolean') t.pass(`isAvailable()=${av}`)
      else t.fail('Returned non-boolean.')
    } catch (err) { t.fail('isAvailable() threw.', { error: err }) }
    tests.push(t)
  }
  {
    const t = makeTest('issupported', 'isSupported() probes the device')
    try {
      const ok = await withTimeout('isSupported', () => wrap.isSupported(), 4000)
      if (!isNative) t.skip('Non-native — scanner cannot run here.')
      else if (ok) t.pass('Device reports barcode scanning supported.')
      else t.warn('Device reports unsupported.', { hint: 'Camera hardware or Play Services missing.' })
    } catch (err) { t.fail('isSupported() threw.', { error: err }) }
    tests.push(t)
  }
  return { tests }
}

// 2026-05-07: speech-recognition diagnostics removed — voice plugin uninstalled.

async function suiteHaptics() {
  const tests = []
  let mod
  {
    const t = makeTest('wrapper-import', 'Wrapper imports')
    try {
      mod = await withTimeout('import', () => import('./haptics.js'))
      if (typeof mod?.hapticLight === 'function') t.pass('hapticLight / hapticSuccess / hapticError exported.')
      else t.fail('Wrapper missing exports.')
    } catch (err) { t.fail('Could not import.', { error: err }) }
    tests.push(t)
  }
  if (!mod) return { tests }
  {
    const t = makeTest('flag', 'Honours the haptics.enabled flag')
    try {
      const v = mod.hapticsEnabled?.()
      if (typeof v === 'boolean') t.pass(`hapticsEnabled()=${v}`)
      else t.fail('hapticsEnabled() did not return a boolean.')
    } catch (err) { t.fail('Threw reading flag.', { error: err }) }
    tests.push(t)
  }
  {
    const t = makeTest('non-throwing', 'hapticLight() never throws')
    try {
      const r = mod.hapticLight()
      // hapticLight can be sync or async; either way we must not throw.
      if (r && typeof r.then === 'function') await withTimeout('hapticLight', () => r, 2000)
      t.pass('Returned without throwing.')
    } catch (err) { t.fail('hapticLight() threw — should be a no-op when unavailable.', { error: err }) }
    tests.push(t)
  }
  return { tests }
}

async function suiteMlkitInstaller() {
  const tests = []
  let wrap
  {
    const t = makeTest('wrapper-import', 'Wrapper imports')
    try {
      wrap = await withTimeout('import', () => import('./plugins/mlkitInstaller.js'))
      if (typeof wrap?.installModule === 'function') t.pass('installModule / isModuleAvailable exported.')
      else t.fail('Wrapper missing exports.')
    } catch (err) { t.fail('Could not import.', { error: err }) }
    tests.push(t)
  }
  if (!wrap) return { tests }
  {
    const t = makeTest('invalid-id', 'Rejects invalid module id without throwing')
    try {
      const r = await withTimeout('installModule(null)', () => wrap.installModule(null), 4000)
      if (r && r.ok === false && r.reason === 'invalid-module-id') t.pass('Returned the documented invalid-module-id error.')
      else t.fail(`Unexpected result: ${JSON.stringify(r)}`)
    } catch (err) { t.fail('Threw on invalid id — should never throw.', { error: err }) }
    tests.push(t)
  }
  {
    const t = makeTest('local-asset', 'Local-asset downloader resolves ok without native call')
    try {
      const r = await withTimeout('installModule(local-asset)', () => wrap.installModule('face-embedding-facenet', { downloader: 'local-asset' }), 4000)
      if (r?.ok === true) t.pass('Local-asset path returns ok=true.')
      else t.fail(`Unexpected: ${JSON.stringify(r)}`)
    } catch (err) { t.fail('Threw.', { error: err }) }
    tests.push(t)
  }
  {
    const t = makeTest('unknown-downloader', 'Surfaces unknown-downloader:* reason')
    try {
      const r = await withTimeout('installModule(unknown)', () => wrap.installModule('whatever', { downloader: 'bogus-kind' }), 4000)
      if (r?.ok === false && /^unknown-downloader:/.test(r.reason)) t.pass(`Reason='${r.reason}'`)
      else t.fail(`Expected unknown-downloader:* fail, got ${JSON.stringify(r)}`)
    } catch (err) { t.fail('Threw.', { error: err }) }
    tests.push(t)
  }
  return { tests }
}

async function suiteBcbpScan() {
  const tests = []
  const { isNative } = platformInfo()
  let wrap
  {
    const t = makeTest('wrapper-import', 'Wrapper imports')
    try {
      wrap = await withTimeout('import', () => import('./plugins/bcbpScan.js'))
      if (typeof wrap?.scanBoardingPass === 'function') t.pass('scanBoardingPass exported.')
      else t.fail('Wrapper missing exports.')
    } catch (err) { t.fail('Could not import.', { error: err }) }
    tests.push(t)
  }
  if (!wrap) return { tests }
  // We do NOT call scanBoardingPass — it would open the camera UI.
  // Instead, prove the parse pipeline by importing the parser side.
  {
    const t = makeTest('parser-import', 'BCBP parser reachable')
    try {
      const m = await import('./bcbp.js')
      if (typeof m.parseBCBP === 'function') t.pass('parseBCBP exported by services/bcbp.js.')
      else t.fail('parseBCBP missing.')
    } catch (err) { t.fail('Could not import services/bcbp.js.', { error: err }) }
    tests.push(t)
  }
  if (!isNative) tests.push(makeTest('camera', 'Camera surface').skip('Non-native — camera not testable on web.'))
  return { tests }
}

async function suiteCapabilities() {
  const tests = []
  let mod
  {
    const t = makeTest('import', 'capabilities.js imports')
    try {
      mod = await withTimeout('import', () => import('./capabilities.js'))
      if (mod?.CAPABILITY_KEYS && typeof mod.isEnabled === 'function') t.pass('CAPABILITY_KEYS + isEnabled exported.')
      else t.fail('Expected exports missing.')
    } catch (err) { t.fail('Could not import.', { error: err }) }
    tests.push(t)
  }
  if (!mod) return { tests }
  // Sentinel master flag introspection
  {
    const t = makeTest('sentinel-master', 'Sentinel master flag readable')
    try {
      const v = mod.isEnabled(mod.CAPABILITY_KEYS.SENTINEL_MASTER)
      if (typeof v === 'boolean') t.pass(`SENTINEL_MASTER=${v}`)
      else t.fail('Did not return a boolean.')
    } catch (err) { t.fail('Threw.', { error: err }) }
    tests.push(t)
  }
  // Quick scan: which sentinel keys are on
  {
    const t = makeTest('sentinel-keys', 'Per-feature sentinel keys snapshot')
    try {
      const enabled = []
      for (const k of mod.SENTINEL_KEYS || []) if (mod.isEnabled(k)) enabled.push(k)
      t.pass(enabled.length ? `On: ${enabled.join(', ')}` : 'No per-feature sentinel keys are on.')
    } catch (err) { t.fail('Threw enumerating SENTINEL_KEYS.', { error: err }) }
    tests.push(t)
  }
  return { tests }
}

// ─── orchestrator ───────────────────────────────────────────────────────────

const SUITES = [
  { id: 'env',         title: 'Environment',                run: suiteEnvironment },
  { id: 'sandbox',     title: 'Sandbox factory',            run: suiteSandbox },
  { id: 'capabilities',title: 'Capability flags',           run: suiteCapabilities },
  { id: 'haptics',     title: 'Haptics',                    run: suiteHaptics },
  { id: 'network',     title: 'Network plugin',             run: suiteNetwork },
  { id: 'biometric',   title: 'Biometric / PIN',            run: suiteBiometric },
  { id: 'keepawake',   title: 'Keep-awake',                 run: suiteKeepAwake },
  { id: 'localnotifs', title: 'Local notifications',        run: suiteLocalNotifications },
  { id: 'share',       title: 'Share & filesystem',         run: suiteShare },
  { id: 'barcode',     title: 'Barcode scanner',            run: suiteBarcode },
  { id: 'mlkit',       title: 'MLKit module installer',     run: suiteMlkitInstaller },
  { id: 'bcbp',        title: 'BCBP boarding-pass scan',    run: suiteBcbpScan },
]

/**
 * Run every diagnostic suite. The optional onSuite callback is invoked after
 * each suite finishes so the UI can render incrementally instead of waiting
 * for the entire run to complete.
 *
 * @returns {Promise<{ startedAt:string, finishedAt:string, durationMs:number, env:object, suites:Array }>}
 */
export async function runAllDiagnostics({ onSuite } = {}) {
  const startedAt = new Date().toISOString()
  const start = nowMs()
  const suites = []
  for (const meta of SUITES) {
    const r = await runSuite(meta.id, meta.title, meta.run)
    suites.push(r)
    try { onSuite && onSuite(r) } catch { /* never let UI crash the run */ }
  }
  const finishedAt = new Date().toISOString()
  const env = (() => {
    const { plat, isNative } = platformInfo()
    return {
      platform: plat,
      isNative,
      userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : null,
      timezone:  Intl.DateTimeFormat().resolvedOptions().timeZone,
    }
  })()
  return { startedAt, finishedAt, durationMs: Math.round(nowMs() - start), env, suites }
}

/** Stable list of suite ids — useful for the UI to render skeleton rows. */
export const DIAGNOSTIC_SUITES = SUITES.map(s => ({ id: s.id, title: s.title }))
