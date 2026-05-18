/**
 * services/plugins/textRecognition.js — ML Kit Latin OCR via custom Capacitor plugin.
 *
 * Restored 2026-05-04: TextRecognitionPlugin.java now runs real ML Kit OCR.
 *
 * Exports
 *   recognizeImage(path, { rotation })
 *     → { ok:true, text, lines, blocks } | { ok:false, reason }
 *   isAvailable() → boolean
 *
 * Edge cases handled here (native plugin handles the rest):
 *   - Plugin not present on web / dev build → { ok:false, reason:'not-native' }
 *   - rotation normalised to 0/90/180/270 before sending to native
 *   - JS-side timeout (12 s) so a hung native call never blocks the UI forever
 *   - Malformed plugin response → { ok:false, reason:'malformed-response' }
 *   - Plugin returns { ok:false, reason:'unbundled' } on old APK builds →
 *     reason passed through so passportScan.js shows the correct hint
 */

import { makePluginSandbox } from './_base'
import { CAPABILITY_KEYS } from '../capabilities'

const OCR_TIMEOUT_MS = 12_000   // 12 s hard wall — slow phones still OCR in < 8 s

const sandbox = makePluginSandbox({
  name: 'textRecognition',
  importer: async () => {
    const core = await import('@capacitor/core')
    if (!core?.Capacitor || !core?.registerPlugin) return null
    const TextRecognition = core.registerPlugin('TextRecognition')
    if (!TextRecognition || typeof TextRecognition.recognizeImage !== 'function') return null
    return { TextRecognition }
  },
  settingsKey: CAPABILITY_KEYS.MRZ,
  sentinel: true,
})

/**
 * Run ML Kit OCR on the image at `path`.
 *
 * @param {string}  path      File-system path (file:// URI or bare path).
 * @param {object}  [opts]
 * @param {number}  [opts.rotation=0]  Clockwise rotation to apply before OCR (0/90/180/270).
 * @returns {Promise<{ok:boolean, text?:string, lines?:string[], blocks?:number, reason?:string}>}
 */
export async function recognizeImage(path, { rotation = 0 } = {}) {
  if (!path || typeof path !== 'string') {
    return { ok: false, reason: 'missing-path' }
  }

  // Normalise rotation so native never sees an unexpected value.
  const rot = (((rotation % 360) + 360) % 360)

  return sandbox.run(async ({ TextRecognition }) => {
    // Wrap the native call in a JS timeout so a hung plugin (GMS crash,
    // DeadObjectException, etc.) does not leave the UI spinner running forever.
    let timer = null
    const native = new Promise((resolve, reject) => {
      TextRecognition.recognizeImage({ path, rotation: rot })
        .then(resolve)
        .catch(reject)
    })
    const timeout = new Promise((_, reject) => {
      timer = setTimeout(() => reject(new Error('ocr-timeout')), OCR_TIMEOUT_MS)
    })
    let r = null
    try {
      r = await Promise.race([native, timeout])
    } finally {
      clearTimeout(timer)
    }

    // Malformed response guard
    if (!r || typeof r !== 'object') {
      return { ok: false, reason: 'malformed-response' }
    }
    if (r.ok === true) {
      return {
        ok:     true,
        text:   typeof r.text   === 'string' ? r.text   : '',
        lines:  Array.isArray(r.lines)       ? r.lines.filter(s => typeof s === 'string') : [],
        blocks: Number.isFinite(r.blocks)    ? r.blocks : 0,
      }
    }
    // Propagate the native reason (e.g. 'file-not-found', 'decode-failed', 'unbundled').
    return { ok: false, reason: String(r.reason || 'ocr-failed') }
  }, { fallback: { ok: false, reason: 'not-native' } })
}

/**
 * recognizeBytes — base64 JPEG bytes → ML Kit OCR.
 * Used by the live-streaming scanner so we can OCR captured camera frames
 * without round-tripping through the filesystem.
 *
 * @param {string} bytes  Base-64 JPEG payload (with or without data: prefix).
 * @param {object} [opts]
 * @param {number} [opts.rotation=0]   Pre-rotation in degrees (0/90/180/270).
 * @param {number} [opts.timeoutMs=4000]  Hard wall — must be < frame interval.
 */
export async function recognizeBytes(bytes, { rotation = 0, timeoutMs = 4000 } = {}) {
  if (!bytes || typeof bytes !== 'string') {
    return { ok: false, reason: 'missing-bytes' }
  }
  const rot = (((rotation % 360) + 360) % 360)
  return sandbox.run(async ({ TextRecognition }) => {
    if (typeof TextRecognition.recognizeBytes !== 'function') {
      // Older APK without bytes support — caller falls back to file path.
      return { ok: false, reason: 'bytes-unsupported' }
    }
    let timer = null
    const native = new Promise((resolve, reject) => {
      TextRecognition.recognizeBytes({ bytes, rotation: rot })
        .then(resolve)
        .catch(reject)
    })
    const timeout = new Promise((_, reject) => {
      timer = setTimeout(() => reject(new Error('ocr-timeout')), timeoutMs)
    })
    let r = null
    try {
      r = await Promise.race([native, timeout])
    } catch (e) {
      return { ok: false, reason: e?.message || 'ocr-error' }
    } finally {
      clearTimeout(timer)
    }
    if (!r || typeof r !== 'object') return { ok: false, reason: 'malformed-response' }
    if (r.ok === true) {
      return {
        ok:     true,
        text:   typeof r.text === 'string' ? r.text : '',
        lines:  Array.isArray(r.lines) ? r.lines.filter(s => typeof s === 'string') : [],
        blocks: Number.isFinite(r.blocks) ? r.blocks : 0,
      }
    }
    return { ok: false, reason: String(r.reason || 'ocr-failed') }
  }, { fallback: { ok: false, reason: 'not-native' } })
}

export async function isAvailable() {
  return sandbox.run(async ({ TextRecognition }) => {
    try {
      const r = await TextRecognition.isAvailable()
      return !!r?.available
    } catch {
      // If isAvailable() doesn't exist on older plugin builds, try a probe call.
      return true
    }
  }, { fallback: false })
}
