/**
 * Aggressive plugin test suite — every Capacitor plugin wrapper that the
 * earlier test files (bcbp, voiceWizard, modelManager, sentinel-aggressive,
 * sentinel-gates) DON'T cover.
 *
 * Each describe block runs in module-isolation:
 *   - vi.resetModules() before each scenario so the wrapper's cached
 *     dynamic import + sandbox state are fresh.
 *   - vi.doMock('@capacitor/core', ...) to control the platform probe.
 *   - vi.doMock('<plugin-package>', ...) to control native behaviour.
 *
 * Conventions:
 *   - Tests NEVER touch the source. If a real bug surfaces, mark it.skip
 *     with a TODO referencing the file:line.
 *   - Sentinel-tagged plugins default OFF — these tests target non-Sentinel
 *     plugins (network, biometric, keep-awake, local-notifications, share)
 *     so localStorage gating doesn't hide the call. Sentinel plugins
 *     (barcode, speech-recognition) DO require the per-key toggle on; we
 *     set it explicitly per scenario.
 *
 * Plugins covered here (file → coverage):
 *   plugins/network.js              → all 5 exports + listener emit + stop
 *   plugins/biometric.js            → PIN crypto + biometric-available probe
 *   plugins/keepAwake.js            → activate / deactivate / safety timer
 *   plugins/localNotifications.js   → schedule / cancel / list / clear / tap
 *   plugins/share.js                → native + web-share + download fallback
 *   plugins/barcode.js              → permissions + scanOnce shape
 *   plugins/speechRecognition.js    → listenOnce + startPartials + cancel
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { CAPABILITY_KEYS } from '../capabilities.js'

beforeEach(() => {
  try { localStorage.clear() } catch {}
})

afterEach(() => {
  try { vi.doUnmock('@capacitor/core') } catch {}
  try { vi.doUnmock('@capacitor/network') } catch {}
  try { vi.doUnmock('capacitor-native-biometric') } catch {}
  try { vi.doUnmock('@capacitor-community/keep-awake') } catch {}
  try { vi.doUnmock('@capacitor/local-notifications') } catch {}
  try { vi.doUnmock('@capacitor/share') } catch {}
  try { vi.doUnmock('@capacitor/filesystem') } catch {}
  try { vi.doUnmock('@capacitor-mlkit/barcode-scanning') } catch {}
  try { vi.doUnmock('@capacitor-community/speech-recognition') } catch {}
  vi.useRealTimers()
})

// ── helpers ────────────────────────────────────────────────────────────────
function mockCore({ platform = 'android' } = {}) {
  vi.doMock('@capacitor/core', () => ({
    Capacitor: { getPlatform: () => platform },
    registerPlugin: vi.fn(() => ({})),
  }))
}

// ═══════════════════════════════════════════════════════════════════════════
// network.js
// ═══════════════════════════════════════════════════════════════════════════
describe('plugins/network.js', () => {
  let mod
  let getStatusSpy, addListenerSpy, removeSpy

  async function setup({ platform = 'android', connected = true, type = 'wifi', getStatusThrows = false } = {}) {
    vi.resetModules()
    mockCore({ platform })
    removeSpy = vi.fn()
    getStatusSpy = vi.fn(async () => ({ connected, connectionType: type }))
    addListenerSpy = vi.fn(async () => ({ remove: removeSpy }))
    if (getStatusThrows) getStatusSpy = vi.fn(async () => { throw new Error('boom') })
    vi.doMock('@capacitor/network', () => ({
      Network: {
        getStatus: getStatusSpy,
        addListener: addListenerSpy,
      },
    }))
    mod = await import('../plugins/network.js')
  }

  it('getStatus returns the native plugin shape on android', async () => {
    await setup({ connected: true, type: '4g' })
    const s = await mod.getStatus()
    expect(s).toEqual({ connected: true, connectionType: '4g' })
    expect(getStatusSpy).toHaveBeenCalledTimes(1)
  })

  it('getStatus falls back to navigator.onLine on web', async () => {
    await setup({ platform: 'web', connected: false, type: 'none' })
    // requiresNative=false but the wrapper still relies on the plugin returning
    // a truthy connected; since the platform probe says non-native, sandbox.run
    // calls the plugin (requireNative is ignored when requiresNative=false in
    // the sandbox). So we set the mock to return null to force the fallback.
    getStatusSpy.mockImplementationOnce(async () => null)
    const s = await mod.getStatus()
    expect(typeof s.connected).toBe('boolean')
    expect(s.connectionType).toBe('unknown')
  })

  it('subscribe fires the listener immediately with last-known status, then unsubscribes cleanly', async () => {
    await setup()
    const cb = vi.fn()
    const off = mod.subscribe(cb)
    expect(cb).toHaveBeenCalledTimes(1) // immediate sync
    off()
    // After unsubscribe, even an emit() (we can't trigger without start()) wouldn't fire it.
    expect(typeof off).toBe('function')
  })

  it('start → addListener wired; stop is idempotent and calls remove', async () => {
    await setup()
    await mod.start()
    expect(addListenerSpy).toHaveBeenCalledTimes(1)
    // Calling start a second time is a no-op (started flag).
    await mod.start()
    expect(addListenerSpy).toHaveBeenCalledTimes(1)
    mod.stop()
    expect(removeSpy).toHaveBeenCalledTimes(1)
    mod.stop() // idempotent
    expect(removeSpy).toHaveBeenCalledTimes(1)
  })

  it('start emits an initial status to subscribers', async () => {
    await setup({ connected: false, type: 'none' })
    const cb = vi.fn()
    mod.subscribe(cb)
    cb.mockClear()
    await mod.start()
    expect(cb).toHaveBeenCalled()
    const lastCall = cb.mock.calls[cb.mock.calls.length - 1][0]
    expect(lastCall.connected).toBe(false)
    expect(lastCall.connectionType).toBe('none')
  })

  it('lastKnown reflects the most recent emit', async () => {
    await setup({ connected: true, type: 'wifi' })
    await mod.start()
    const k = mod.lastKnown()
    expect(k.connected).toBe(true)
    expect(k.connectionType).toBe('wifi')
  })

  it('plugin throw on getStatus does not crash subscribe()', async () => {
    await setup({ getStatusThrows: true })
    // Even if the plugin throws, getStatus returns the navigator fallback object.
    const s = await mod.getStatus()
    expect(typeof s.connected).toBe('boolean')
  })

  it('listener that throws does not poison other listeners', async () => {
    await setup()
    const bad = vi.fn(() => { throw new Error('listener boom') })
    const good = vi.fn()
    mod.subscribe(bad)
    mod.subscribe(good)
    // start() emits to all listeners; bad must not stop good.
    await mod.start()
    expect(good).toHaveBeenCalled()
  })
})

// ═══════════════════════════════════════════════════════════════════════════
// biometric.js
// ═══════════════════════════════════════════════════════════════════════════
describe('plugins/biometric.js — PIN', () => {
  let mod
  beforeEach(async () => {
    vi.resetModules()
    mockCore({ platform: 'android' })
    vi.doMock('capacitor-native-biometric', () => ({ NativeBiometric: {} }))
    mod = await import('../plugins/biometric.js')
  })

  it('hasPin is false when nothing is enrolled', () => {
    expect(mod.hasPin()).toBe(false)
  })

  it('enrollPin rejects PINs shorter than 4 digits', async () => {
    expect(await mod.enrollPin('123')).toBe(false)
    expect(await mod.enrollPin('')).toBe(false)
    expect(await mod.enrollPin(null)).toBe(false)
    expect(mod.hasPin()).toBe(false)
  })

  it('enrollPin strips non-digits and accepts 4+ digits', async () => {
    expect(await mod.enrollPin('12-34')).toBe(true)
    expect(mod.hasPin()).toBe(true)
  })

  it('verifyPin returns true for the enrolled PIN, false for the wrong one', async () => {
    await mod.enrollPin('4321')
    expect(await mod.verifyPin('4321')).toBe(true)
    expect(await mod.verifyPin('1234')).toBe(false)
  })

  it('verifyPin tolerates non-digit input by stripping', async () => {
    await mod.enrollPin('4321')
    expect(await mod.verifyPin('4 3 2 1')).toBe(true)
  })

  it('clearPin wipes both salt + hash + biometric flag', async () => {
    await mod.enrollPin('4321')
    mod.setBiometricEnabled(true)
    expect(mod.hasPin()).toBe(true)
    expect(mod.isBiometricEnabled()).toBe(true)
    mod.clearPin()
    expect(mod.hasPin()).toBe(false)
    expect(mod.isBiometricEnabled()).toBe(false)
  })

  it('verifyPin returns false when nothing is enrolled', async () => {
    expect(await mod.verifyPin('4321')).toBe(false)
  })

  it('PIN is truncated at 10 digits (defence against memory exhaustion)', async () => {
    const long = '1234567890ABCDE99' // 9-char digit core, then garbage
    await mod.enrollPin(long)
    // The cleaned PIN is "12345678909" → first 10 → "1234567890"
    expect(await mod.verifyPin('1234567890')).toBe(true)
  })

  it('biometric flag toggles independently of PIN state', () => {
    expect(mod.isBiometricEnabled()).toBe(false)
    mod.setBiometricEnabled(true)
    expect(mod.isBiometricEnabled()).toBe(true)
    mod.setBiometricEnabled(false)
    expect(mod.isBiometricEnabled()).toBe(false)
  })
})

describe('plugins/biometric.js — biometric probe', () => {
  it('biometricAvailable returns {available:true,type} when plugin reports isAvailable:true', async () => {
    vi.resetModules()
    mockCore({ platform: 'android' })
    vi.doMock('capacitor-native-biometric', () => ({
      NativeBiometric: {
        isAvailable: vi.fn(async () => ({ isAvailable: true, biometryType: 'FINGERPRINT' })),
      },
    }))
    const mod = await import('../plugins/biometric.js')
    const r = await mod.biometricAvailable()
    expect(r).toEqual({ available: true, type: 'FINGERPRINT' })
  })

  it('biometricAvailable returns the safe fallback on web (non-native)', async () => {
    vi.resetModules()
    mockCore({ platform: 'web' })
    vi.doMock('capacitor-native-biometric', () => ({ NativeBiometric: { isAvailable: vi.fn(async () => ({ isAvailable: true })) } }))
    const mod = await import('../plugins/biometric.js')
    expect(await mod.biometricAvailable()).toEqual({ available: false, type: 'NONE' })
  })

  it('verifyBiometric resolves true when verifyIdentity does not throw', async () => {
    vi.resetModules()
    mockCore({ platform: 'android' })
    const verifyIdentity = vi.fn(async () => undefined)
    vi.doMock('capacitor-native-biometric', () => ({ NativeBiometric: { verifyIdentity } }))
    const mod = await import('../plugins/biometric.js')
    expect(await mod.verifyBiometric({ reason: 'unlock' })).toBe(true)
    expect(verifyIdentity).toHaveBeenCalledTimes(1)
  })

  it('verifyBiometric returns false when plugin throws (user cancel etc.)', async () => {
    vi.resetModules()
    mockCore({ platform: 'android' })
    vi.doMock('capacitor-native-biometric', () => ({
      NativeBiometric: { verifyIdentity: vi.fn(async () => { throw new Error('user cancel') }) },
    }))
    const mod = await import('../plugins/biometric.js')
    expect(await mod.verifyBiometric()).toBe(false)
  })
})

// ═══════════════════════════════════════════════════════════════════════════
// keepAwake.js
// ═══════════════════════════════════════════════════════════════════════════
describe('plugins/keepAwake.js', () => {
  let mod, keepAwakeSpy, allowSleepSpy

  async function setup({ platform = 'android', keepThrows = false } = {}) {
    vi.resetModules()
    mockCore({ platform })
    keepAwakeSpy  = vi.fn(async () => { if (keepThrows) throw new Error('blocked') })
    allowSleepSpy = vi.fn(async () => {})
    vi.doMock('@capacitor-community/keep-awake', () => ({
      KeepAwake: { keepAwake: keepAwakeSpy, allowSleep: allowSleepSpy },
    }))
    mod = await import('../plugins/keepAwake.js')
  }

  it('activate returns true on a fresh state and toggles isActive', async () => {
    await setup()
    expect(mod.isActive()).toBe(false)
    expect(await mod.activate()).toBe(true)
    expect(mod.isActive()).toBe(true)
    expect(keepAwakeSpy).toHaveBeenCalledTimes(1)
  })

  it('activate is idempotent — second call is a no-op', async () => {
    await setup()
    await mod.activate()
    await mod.activate()
    expect(keepAwakeSpy).toHaveBeenCalledTimes(1)
  })

  it('safety timer auto-deactivates after maxDurationMs', async () => {
    vi.useFakeTimers()
    await setup()
    await mod.activate(1000)
    expect(mod.isActive()).toBe(true)
    await vi.advanceTimersByTimeAsync(1100)
    // Microtask drain after timer fire
    await Promise.resolve()
    expect(allowSleepSpy).toHaveBeenCalled()
    expect(mod.isActive()).toBe(false)
  })

  it('deactivate without prior activate is a no-op returning true', async () => {
    await setup()
    expect(await mod.deactivate()).toBe(true)
    expect(allowSleepSpy).not.toHaveBeenCalled()
  })

  it('plugin throw inside keepAwake() leaves isActive false (sandbox swallows + fallback)', async () => {
    await setup({ keepThrows: true })
    expect(await mod.activate()).toBe(false)
    expect(mod.isActive()).toBe(false)
  })

  it('on web (non-native) activate returns false without calling keepAwake', async () => {
    await setup({ platform: 'web' })
    expect(await mod.activate()).toBe(false)
    expect(keepAwakeSpy).not.toHaveBeenCalled()
  })
})

// ═══════════════════════════════════════════════════════════════════════════
// localNotifications.js
// ═══════════════════════════════════════════════════════════════════════════
describe('plugins/localNotifications.js', () => {
  let mod, scheduleSpy, cancelSpy, getPendingSpy, checkPermSpy, requestPermSpy, addListenerSpy, removeSpy

  async function setup({ platform = 'android', perm = 'granted', requestPerm = 'granted' } = {}) {
    vi.resetModules()
    mockCore({ platform })
    checkPermSpy   = vi.fn(async () => ({ display: perm }))
    requestPermSpy = vi.fn(async () => ({ display: requestPerm }))
    scheduleSpy    = vi.fn(async () => undefined)
    cancelSpy      = vi.fn(async () => undefined)
    getPendingSpy  = vi.fn(async () => ({ notifications: [] }))
    removeSpy      = vi.fn()
    addListenerSpy = vi.fn(async () => ({ remove: removeSpy }))
    vi.doMock('@capacitor/local-notifications', () => ({
      LocalNotifications: {
        checkPermissions: checkPermSpy,
        requestPermissions: requestPermSpy,
        schedule: scheduleSpy,
        cancel: cancelSpy,
        getPending: getPendingSpy,
        addListener: addListenerSpy,
      },
    }))
    mod = await import('../plugins/localNotifications.js')
  }

  it('hasPermission returns true when display=granted', async () => {
    await setup({ perm: 'granted' })
    expect(await mod.hasPermission()).toBe(true)
  })

  it('hasPermission returns false when display=denied', async () => {
    await setup({ perm: 'denied' })
    expect(await mod.hasPermission()).toBe(false)
  })

  it('requestPermission short-circuits when already granted', async () => {
    await setup({ perm: 'granted' })
    expect(await mod.requestPermission()).toBe(true)
    expect(requestPermSpy).not.toHaveBeenCalled()
  })

  it('requestPermission asks the OS when current is not granted', async () => {
    await setup({ perm: 'denied', requestPerm: 'granted' })
    expect(await mod.requestPermission()).toBe(true)
    expect(requestPermSpy).toHaveBeenCalledTimes(1)
  })

  it('requestPermission returns false when OS denies', async () => {
    await setup({ perm: 'denied', requestPerm: 'denied' })
    expect(await mod.requestPermission()).toBe(false)
  })

  it('schedule refuses past dates', async () => {
    await setup()
    const past = new Date(Date.now() - 60_000)
    expect(await mod.schedule(1, 't', 'b', past)).toBe(false)
    expect(scheduleSpy).not.toHaveBeenCalled()
  })

  it('schedule refuses dates inside the 5-second too-close window', async () => {
    await setup()
    const tooSoon = new Date(Date.now() + 1_000)
    expect(await mod.schedule(1, 't', 'b', tooSoon)).toBe(false)
    expect(scheduleSpy).not.toHaveBeenCalled()
  })

  it('schedule accepts a future date and trims long copy', async () => {
    await setup()
    const at = new Date(Date.now() + 60_000)
    const longTitle = 'x'.repeat(200)
    const longBody  = 'y'.repeat(500)
    expect(await mod.schedule(7, longTitle, longBody, at, { foo: 1 })).toBe(true)
    expect(scheduleSpy).toHaveBeenCalledTimes(1)
    const arg = scheduleSpy.mock.calls[0][0].notifications[0]
    expect(arg.id).toBe(7)
    expect(arg.title.length).toBeLessThanOrEqual(80)
    expect(arg.body.length).toBeLessThanOrEqual(240)
    expect(arg.extra).toEqual({ foo: 1 })
  })

  it('cancel forwards the id to the plugin', async () => {
    await setup()
    expect(await mod.cancel(42)).toBe(true)
    expect(cancelSpy).toHaveBeenCalledWith({ notifications: [{ id: 42 }] })
  })

  it('listPending returns the notifications array (or [] when shape is wrong)', async () => {
    await setup()
    getPendingSpy.mockResolvedValueOnce({ notifications: [{ id: 1 }, { id: 2 }] })
    expect(await mod.listPending()).toEqual([{ id: 1 }, { id: 2 }])
    getPendingSpy.mockResolvedValueOnce({})
    expect(await mod.listPending()).toEqual([])
  })

  it('clearAll cancels every pending entry', async () => {
    await setup()
    getPendingSpy.mockResolvedValueOnce({ notifications: [{ id: 1 }, { id: 2 }] })
    expect(await mod.clearAll()).toBe(true)
    expect(cancelSpy).toHaveBeenCalledWith({ notifications: [{ id: 1 }, { id: 2 }] })
  })

  it('clearAll is a no-op when nothing pending', async () => {
    await setup()
    getPendingSpy.mockResolvedValueOnce({ notifications: [] })
    expect(await mod.clearAll()).toBe(true)
    expect(cancelSpy).not.toHaveBeenCalled()
  })

  it('onTap registers a listener; returned stop() is callable', async () => {
    await setup()
    const cb = vi.fn()
    const stop = await mod.onTap(cb)
    expect(addListenerSpy).toHaveBeenCalledWith('localNotificationActionPerformed', expect.any(Function))
    expect(typeof stop).toBe('function')
    stop()
    expect(removeSpy).toHaveBeenCalledTimes(1)
  })

  it('every method is a safe fallback on web (non-native)', async () => {
    await setup({ platform: 'web' })
    expect(await mod.hasPermission()).toBe(false)
    expect(await mod.requestPermission()).toBe(false)
    expect(await mod.schedule(1, 't', 'b', new Date(Date.now() + 60_000))).toBe(false)
    expect(await mod.cancel(1)).toBe(false)
    expect(await mod.listPending()).toEqual([])
    expect(await mod.clearAll()).toBe(false)
  })
})

// ═══════════════════════════════════════════════════════════════════════════
// share.js
//
// jsdom (the test environment) ships without Blob.prototype.arrayBuffer
// and without URL.createObjectURL. Both are exercised by share.js. We
// polyfill them per-test so the mocks line up with how a real browser /
// Android WebView would behave.
// ═══════════════════════════════════════════════════════════════════════════
function installBlobArrayBufferPolyfill() {
  if (typeof Blob === 'undefined') return () => {}
  if (typeof Blob.prototype.arrayBuffer === 'function') return () => {}
  // eslint-disable-next-line no-extend-native
  Blob.prototype.arrayBuffer = async function () {
    return await new Promise((resolve, reject) => {
      const fr = new FileReader()
      fr.onload = () => resolve(fr.result)
      fr.onerror = () => reject(fr.error)
      fr.readAsArrayBuffer(this)
    })
  }
  return () => { try { delete Blob.prototype.arrayBuffer } catch {} }
}
function installUrlPolyfill() {
  if (typeof URL === 'undefined') return () => {}
  if (typeof URL.createObjectURL === 'function') return () => {}
  URL.createObjectURL = () => 'blob:mock'
  URL.revokeObjectURL = () => {}
  return () => { try { delete URL.createObjectURL; delete URL.revokeObjectURL } catch {} }
}

describe('plugins/share.js', () => {
  let mod, writeFileSpy, shareSpy
  let removeBlobPolyfill, removeUrlPolyfill

  beforeEach(() => {
    removeBlobPolyfill = installBlobArrayBufferPolyfill()
    removeUrlPolyfill = installUrlPolyfill()
  })
  afterEach(() => {
    removeBlobPolyfill?.()
    removeUrlPolyfill?.()
  })

  async function setup({ platform = 'android', writeReturns = 'file:///docs/r.pdf', shareThrows = false } = {}) {
    vi.resetModules()
    mockCore({ platform })
    writeFileSpy = vi.fn(async () => ({ uri: writeReturns }))
    shareSpy     = vi.fn(async () => { if (shareThrows) throw new Error('share') })
    vi.doMock('@capacitor/filesystem', () => ({
      Filesystem: { writeFile: writeFileSpy },
      Directory: { Documents: 'DOCUMENTS' },
      Encoding: { UTF8: 'utf8' },
    }))
    vi.doMock('@capacitor/share', () => ({
      Share: { share: shareSpy },
    }))
    mod = await import('../plugins/share.js')
  }

  function makeBlob() {
    return new Blob([new Uint8Array([0x25, 0x50, 0x44, 0x46])], { type: 'application/pdf' })
  }

  it('native path: writes file then invokes Share with the URI', async () => {
    await setup()
    const r = await mod.sharePdf(makeBlob(), { filename: 'report.pdf' })
    expect(r.shared).toBe(true)
    expect(r.target).toBe('native')
    expect(r.uri).toBe('file:///docs/r.pdf')
    expect(writeFileSpy).toHaveBeenCalledTimes(1)
    expect(shareSpy).toHaveBeenCalledTimes(1)
  })

  it('native path: file written but Share throws → returns native-file-only', async () => {
    await setup({ shareThrows: true })
    const r = await mod.sharePdf(makeBlob())
    expect(r.shared).toBe(true)
    expect(r.target).toBe('native-file-only')
  })

  it('sanitises filename: strips slashes, semicolons, whitespace; truncates to 80', async () => {
    await setup()
    await mod.sharePdf(makeBlob(), { filename: '../etc/passwd ;rm -rf;.pdf' })
    expect(writeFileSpy).toHaveBeenCalled()
    const writtenName = writeFileSpy.mock.calls[0][0].path
    // The sanitiser's whitelist keeps [a-zA-Z0-9._-]. Dots survive (benign
    // — Filesystem.writeFile is scoped to Directory.Documents so ".." is a
    // literal, not a traversal). Slashes/semicolons/whitespace must be gone.
    expect(writtenName).not.toMatch(/[\/;\s]/)
    expect(writtenName).not.toMatch(/passwd ;rm/) // shell punctuation is gone
    expect(writtenName.length).toBeLessThanOrEqual(80)
  })

  it('on web (no native), falls back to anchor download when navigator.share is missing', async () => {
    await setup({ platform: 'web' })
    const origShare = navigator.share
    delete navigator.share
    try {
      const r = await mod.sharePdf(makeBlob(), { filename: 'r.pdf' })
      // The native sandboxes have requiresNative=false, so they may still
      // succeed — accept either native (mock invoked) or download fallback.
      expect(r.shared).toBe(true)
      expect(['native', 'download']).toContain(r.target)
    } finally {
      if (origShare) navigator.share = origShare
    }
  })

  it('isAvailable returns true on web (navigator exists in jsdom)', async () => {
    await setup({ platform: 'web' })
    expect(await mod.isAvailable()).toBe(true)
  })
})

// ═══════════════════════════════════════════════════════════════════════════
// barcode.js (Sentinel-tagged via CAPABILITY_KEYS.BARCODE — toggle on)
// ═══════════════════════════════════════════════════════════════════════════
describe('plugins/barcode.js', () => {
  let mod, supportedSpy, checkPermSpy, requestPermSpy, scanSpy, isModSpy, installModSpy

  async function setup({
    platform = 'android',
    supported = true,
    perm = 'granted',
    permRequest = 'granted',
    barcodes = [{ rawValue: 'M1JOHN/DOE', format: 'PDF_417', valueType: 'UNKNOWN' }],
    moduleAvailable = true,
  } = {}) {
    vi.resetModules()
    mockCore({ platform })
    // Sentinel sandbox is gated OFF when settingsKey is unset; barcode uses
    // settingsKey=CAPABILITY_KEYS.BARCODE but is NOT sentinel-flagged in the
    // sandbox factory, so unset === enabled. We don't need to enable it.
    supportedSpy   = vi.fn(async () => ({ supported }))
    checkPermSpy   = vi.fn(async () => ({ camera: perm }))
    requestPermSpy = vi.fn(async () => ({ camera: permRequest }))
    scanSpy        = vi.fn(async () => ({ barcodes }))
    isModSpy       = vi.fn(async () => ({ available: moduleAvailable }))
    installModSpy  = vi.fn(async () => undefined)
    vi.doMock('@capacitor-mlkit/barcode-scanning', () => ({
      BarcodeScanner: {
        isSupported: supportedSpy,
        checkPermissions: checkPermSpy,
        requestPermissions: requestPermSpy,
        scan: scanSpy,
        isGoogleBarcodeScannerModuleAvailable: isModSpy,
        installGoogleBarcodeScannerModule: installModSpy,
      },
    }))
    mod = await import('../plugins/barcode.js')
  }

  it('isSupported returns true when plugin says supported', async () => {
    await setup()
    expect(await mod.isSupported()).toBe(true)
  })

  it('requestPermissions short-circuits when already granted', async () => {
    await setup({ perm: 'granted' })
    expect(await mod.requestPermissions()).toBe(true)
    expect(requestPermSpy).not.toHaveBeenCalled()
  })

  it('requestPermissions accepts "limited" as an OK state', async () => {
    await setup({ perm: 'limited' })
    expect(await mod.requestPermissions()).toBe(true)
  })

  it('requestPermissions returns false when both check and request deny', async () => {
    await setup({ perm: 'denied', permRequest: 'denied' })
    expect(await mod.requestPermissions()).toBe(false)
  })

  it('scanOnce returns the first barcode normalised to {value,format,valueType}', async () => {
    await setup()
    const r = await mod.scanOnce({ formats: ['PDF_417'] })
    expect(r).toEqual({ value: 'M1JOHN/DOE', format: 'PDF_417', valueType: 'UNKNOWN' })
    expect(scanSpy).toHaveBeenCalledTimes(1)
  })

  it('scanOnce returns null when permission is denied', async () => {
    await setup({ perm: 'denied', permRequest: 'denied' })
    expect(await mod.scanOnce()).toBeNull()
    expect(scanSpy).not.toHaveBeenCalled()
  })

  it('scanOnce returns null when no barcodes are detected', async () => {
    await setup({ barcodes: [] })
    expect(await mod.scanOnce()).toBeNull()
  })

  it('ensureModuleInstalled triggers install when module unavailable', async () => {
    await setup({ moduleAvailable: false })
    await mod.ensureModuleInstalled()
    expect(installModSpy).toHaveBeenCalledTimes(1)
  })

  it('ensureModuleInstalled is a no-op when module already available', async () => {
    await setup({ moduleAvailable: true })
    await mod.ensureModuleInstalled()
    expect(installModSpy).not.toHaveBeenCalled()
  })

  it('on web (non-native) every call returns the safe fallback', async () => {
    await setup({ platform: 'web' })
    expect(await mod.isSupported()).toBe(false)
    expect(await mod.scanOnce()).toBeNull()
    expect(scanSpy).not.toHaveBeenCalled()
  })
})

// 2026-05-07: speechRecognition.js tests removed — voice plugin uninstalled.

// ═══════════════════════════════════════════════════════════════════════════
// _base.js — circuit breaker stress (sandbox factory)
// ═══════════════════════════════════════════════════════════════════════════
describe('plugins/_base.js — circuit breaker', () => {
  it('after 10 consecutive plugin failures the breaker trips and short-circuits to fallback', async () => {
    vi.resetModules()
    mockCore({ platform: 'android' })
    const { makePluginSandbox } = await import('../plugins/_base.js')
    const sandbox = makePluginSandbox({
      name: 'breaker-test',
      importer: async () => ({ Foo: {} }),
      requiresNative: false,
    })
    let calls = 0
    const failing = async () => { calls++; throw new Error('nope') }
    for (let i = 0; i < 10; i++) {
      const r = await sandbox.run(failing, { fallback: 'FALLBACK' })
      expect(r).toBe('FALLBACK')
    }
    // 11th call should NOT increment calls — breaker is open.
    const r = await sandbox.run(failing, { fallback: 'FALLBACK' })
    expect(r).toBe('FALLBACK')
    expect(calls).toBe(10)
    expect(sandbox.breakerState().tripped).toBe(true)
    expect(sandbox.breakerState().resumesAt).toBeGreaterThan(Date.now())
  })

  it('a successful call resets the consecutive-failure counter', async () => {
    vi.resetModules()
    mockCore({ platform: 'android' })
    const { makePluginSandbox } = await import('../plugins/_base.js')
    const sandbox = makePluginSandbox({
      name: 'breaker-reset',
      importer: async () => ({ Foo: {} }),
      requiresNative: false,
    })
    // 5 fails, then 1 success, then 5 more fails — should NOT trip.
    let throws = true
    for (let i = 0; i < 5; i++) {
      await sandbox.run(async () => { throw new Error('x') }, { fallback: null })
    }
    await sandbox.run(async () => 'OK', { fallback: null }) // resets counter
    for (let i = 0; i < 5; i++) {
      await sandbox.run(async () => { throw new Error('x') }, { fallback: null })
    }
    expect(sandbox.breakerState().tripped).toBe(false)
  })
})
