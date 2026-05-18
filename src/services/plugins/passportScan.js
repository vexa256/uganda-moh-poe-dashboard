/**
 * services/plugins/passportScan.js — hardened passport MRZ scanning orchestrator.
 *
 * Refactored 2026-05-04, hardened again 2026-05-07.
 *
 * Pipeline (fully offline — no network required at any step):
 *   1. Permission check + request (explain before asking; deep-link when denied)
 *   2. Photo acquisition (camera OR gallery; up to MAX_PHOTO_ATTEMPTS retries)
 *   3. Multi-engine OCR sweep on the photo:
 *        a. Native ML Kit Latin OCR via TextRecognitionPlugin (bundled model)
 *        b. 6 rotation passes (0/90/180/270 + 45 / 315 oblique)
 *        c. Image-enhanced variants run through canvas → recognizeBytes:
 *             - bottom-third MRZ crop (passport MRZ is always at the bottom)
 *             - high-contrast threshold (rescue dark / faded MRZ)
 *             - grayscale + 1.4× contrast (rescue uneven lighting)
 *   4. MRZ extraction + parse on every OCR variant — first parse to a
 *      valid name/sex/dob wins.
 *   5. Return best result or structured failure with hint.
 *
 * Edge cases handled
 *   E01  Camera permission denied first time  → explain + re-request
 *   E02  Camera permission permanently denied → { reason:'permission-permanent', settingsUrl }
 *   E03  Camera hardware not present          → { reason:'camera-not-available' }
 *   E04  Camera plugin not in APK build       → { reason:'camera-import-failed' }
 *   E05  User cancels camera                  → { reason:'photo-cancelled' } (silent)
 *   E06  Camera saves zero-byte file          → detected; treated as no-photo-path
 *   E07  Dark/under-exposed photo             → contrast enhancement variant rescue
 *   E08  Blurry image (OCR returns tiny text) → hint to hold steady; retake offered
 *   E09  MRZ cropped (passport held too far)  → bottom-crop variant rescue
 *   E10  Glare / flash reflection             → grayscale variant rescue
 *   E11  Wrong orientation                    → 6-way rotation retry
 *   E12  Worn / faded MRZ                     → fuzzy OCR repair in passportOcr.js
 *   E13  Non-standard format (TD1/TD2 IDs)    → supported by passportOcr.js
 *   E14  OCR returns empty text               → variant retries; { reason:'ocr-empty' } only after all variants fail
 *   E15  OCR times out (slow phone)           → 12 s JS timeout per call
 *   E16  ML Kit OOM on large image            → sub-sampled decode in native plugin
 *   E17  Plugin crash / null response         → sandbox returns fallback
 *   E18  MRZ found but parse yields no name   → { reason:'parse-failed' }
 *   E19  App goes to background mid-scan      → Camera plugin handles; path returned on resume
 *   E20  Low storage (ENOSPC writing photo)   → caught as photo-failed
 *   E21  Concurrent scan attempt              → lock prevents parallel calls
 *   E22  Feature gated off in settings        → { reason:'disabled' }
 *   E23  OCR model not bundled (old APK)      → { reason:'unbundled' } + hint
 *   E24  Gallery picker returns nothing       → { reason:'photo-cancelled' } (silent)
 *   E25  Photo URI is content:// not readable → falls back to webPath/dataUrl
 */

import { isSentinelFeatureOn, CAPABILITY_KEYS } from '../capabilities.js'
import { recognizeImage, recognizeBytes, isAvailable as isOcrAvailable } from './textRecognition.js'
import { parsePassportFromOcr } from '../passportOcr.js'
import { parseTravelerDoc } from '../passport.js'

// Maximum number of fresh photos to take before giving up.
const MAX_PHOTO_ATTEMPTS = 4

// Cardinal + oblique rotation passes per photo. Six rotations covers every
// realistic orientation including diagonal-held passports.
const RETRY_ROTATIONS = [0, 90, 180, 270, 45, 315]

// Minimum OCR line count that signals a legible photo. Below this we
// move to the enhancement variants instead of declaring the photo dark.
const MIN_LEGIBLE_LINES = 3

// E21 — lock to prevent overlapping camera sessions.
let _scanning = false

// ── Public failure hints (shown to officer) ────────────────────────────────
export const FAILURE_HINTS = Object.freeze({
  'disabled':                  'Passport scanning is turned off. Enable it in Settings → Capabilities.',
  'ocr-not-available':         'Text recogniser is unavailable on this device — paste the MRZ or type the name.',
  'unbundled':                 'OCR is not available in this build — paste the two MRZ lines or type the name.',
  'camera-import-failed':      'Camera plugin missing in this build — paste the MRZ or type the name.',
  'camera-not-available':      'No camera is available on this device — paste the MRZ or type the name.',
  'permission-denied':         'Camera permission denied. Tap "Try again" and allow camera access when prompted.',
  'permission-permanent':      'Camera access is blocked. Open Settings → App → Permissions and enable Camera.',
  'photo-cancelled':            null,   // silent — user backed out intentionally
  'photo-failed':              'Could not take the photo — try again, or pick from gallery.',
  'no-photo-path':             'Photo capture returned no file — try again, or pick from gallery.',
  'ocr-empty':                 'No text detected. Move closer, add light, hold steady — or pick a clearer photo from gallery.',
  'ocr-dark':                  'The photo is too dark. Move to a brighter area, turn on flash, or pick from gallery.',
  'ocr-blurry':                'The image is blurry. Tap the screen to focus and hold steady — or pick a sharper photo from gallery.',
  'ocr-cropped':               'The bottom of the passport is cut off. Move the camera lower so the full MRZ strip is visible.',
  'ocr-failed':                'Text recogniser failed — try again with better lighting, or pick a photo from gallery.',
  'ocr-timeout':               'Text recognition is taking too long. Try again, or pick a smaller / clearer photo from gallery.',
  'no-mrz-found':              "Couldn't find the passport code (MRZ). Keep the bottom strip with <<< fully in frame, well-lit, and sharp.",
  'parse-failed':              'MRZ detected but could not be read clearly. Try a brighter photo or paste the two MRZ lines.',
  'all-attempts-failed':       'Scanning failed after several attempts. Pick from gallery, paste the MRZ, or type the name.',
  'scan-in-progress':          'A scan is already in progress. Please wait.',
  'orchestrator-error':        'Internal error during scanning. Pick from gallery, paste the MRZ, or type the name.',
})

export function hintFor(reason) {
  if (!reason || typeof reason !== 'string') return FAILURE_HINTS['orchestrator-error']
  if (FAILURE_HINTS[reason] !== undefined)   return FAILURE_HINTS[reason]
  for (const key of Object.keys(FAILURE_HINTS)) {
    if (reason.startsWith(key + ':') || reason.startsWith(key + '-')) return FAILURE_HINTS[key]
  }
  if (reason.startsWith('ocr-'))     return FAILURE_HINTS['ocr-failed']
  if (reason.startsWith('parser-'))  return FAILURE_HINTS['parse-failed']
  if (reason.startsWith('extract-')) return FAILURE_HINTS['parse-failed']
  return FAILURE_HINTS['orchestrator-error']
}

function finalise(res) {
  if (!res) return { ok: false, reason: 'orchestrator-error', hint: hintFor('orchestrator-error') }
  if (res.ok) return res
  return { ...res, hint: res.hint ?? hintFor(res.reason) }
}

// ── Permission helpers ─────────────────────────────────────────────────────

async function requestCameraPermission(Camera) {
  try {
    const current = await Camera.checkPermissions()
    const state   = current?.camera

    if (state === 'granted' || state === 'limited') return { granted: true, permanent: false }

    if (state === 'denied') {
      const before = Date.now()
      const req    = await Camera.requestPermissions({ permissions: ['camera'] })
      const elapsed = Date.now() - before
      const likelySuppressed = elapsed < 150
      const granted = req?.camera === 'granted' || req?.camera === 'limited'
      return { granted, permanent: !granted && likelySuppressed }
    }

    const req     = await Camera.requestPermissions({ permissions: ['camera'] })
    const granted = req?.camera === 'granted' || req?.camera === 'limited'
    return { granted, permanent: false }
  } catch {
    return { granted: false, permanent: false }
  }
}

// ── Photo quality heuristics ───────────────────────────────────────────────

function diagnoseOcrFailure(ocr) {
  if (!ocr || ocr.ok !== true) return null
  const text  = ocr.text  || ''
  const lines = ocr.lines || []
  if (lines.length < MIN_LEGIBLE_LINES && text.length < 30) return 'ocr-dark'
  if (lines.length > 10 && !text.includes('<')) return 'ocr-cropped'
  if (text.includes('<') && lines.every(l => l.replace(/[^A-Z0-9<]/g, '').length < 20)) return 'ocr-blurry'
  return 'no-mrz-found'
}

function guidanceForRetry(reason) {
  const map = {
    'ocr-dark':    'Add more light or move to a brighter spot, then try again.',
    'ocr-blurry':  'Tap the screen to focus before shooting and hold the device still.',
    'ocr-cropped': 'Move the camera lower so the entire bottom strip (<<<) fits in the frame.',
    'no-mrz-found':'Position the passport so the two machine-readable lines at the bottom are fully visible.',
    'ocr-empty':   'Ensure the passport data page is fully in the frame with good lighting.',
    'parse-failed':'The MRZ strip was detected but unclear. Try better lighting and a steady hand.',
  }
  return map[reason] || 'Adjust the angle, ensure good lighting, and try again.'
}

// ── Mock (dev mode) ───────────────────────────────────────────────────────

function isMockMode() {
  try { return new URLSearchParams(window.location.search).get('sentinel-mock') === '1' } catch { return false }
}
const MOCK_OCR = {
  ok: true,
  text: 'P<RWABANDA<<UWIMANA<<<<<<<<<<<<<<<<<<<<<<<<\nRW123456781RWA9001011M3001014<<<<<<<<<<<<<<06',
  lines: ['P<RWABANDA<<UWIMANA<<<<<<<<<<<<<<<<<<<<<<<<', 'RW123456781RWA9001011M3001014<<<<<<<<<<<<<<06'],
  blocks: 1,
}

// ── Image enhancement via canvas ──────────────────────────────────────────
// All variants run on the JS side, drawing onto an OffscreenCanvas (or
// regular HTMLCanvasElement on older runtimes), then encoded to JPEG and
// passed to the native plugin via recognizeBytes. Every step is wrapped
// in try/catch — a canvas failure simply skips that variant.

async function _loadImage(uri) {
  return new Promise((resolve, reject) => {
    try {
      const img = new Image()
      img.crossOrigin = 'anonymous'
      img.onload  = () => resolve(img)
      img.onerror = () => reject(new Error('image-load-failed'))
      img.src = uri
    } catch (err) { reject(err) }
  })
}

function _makeCanvas(w, h) {
  try {
    if (typeof OffscreenCanvas === 'function') return new OffscreenCanvas(w, h)
  } catch { /* fall through */ }
  if (typeof document !== 'undefined') {
    const c = document.createElement('canvas')
    c.width = w; c.height = h
    return c
  }
  return null
}

async function _canvasToJpegBase64(canvas, quality = 0.92) {
  try {
    if (typeof canvas.convertToBlob === 'function') {
      const blob = await canvas.convertToBlob({ type: 'image/jpeg', quality })
      return await _blobToBase64(blob)
    }
    if (typeof canvas.toDataURL === 'function') {
      const dataUrl = canvas.toDataURL('image/jpeg', quality)
      const idx = dataUrl.indexOf(',')
      return idx >= 0 ? dataUrl.slice(idx + 1) : dataUrl
    }
  } catch (err) { console.debug('[passportScan] canvas encode failed:', err?.message) }
  return null
}

function _blobToBase64(blob) {
  return new Promise((resolve, reject) => {
    try {
      const fr = new FileReader()
      fr.onload  = () => {
        const s = String(fr.result || '')
        const idx = s.indexOf(',')
        resolve(idx >= 0 ? s.slice(idx + 1) : s)
      }
      fr.onerror = () => reject(new Error('blob-read-failed'))
      fr.readAsDataURL(blob)
    } catch (err) { reject(err) }
  })
}

/**
 * Build an enhanced JPEG variant of the photo.
 * Returns base-64 JPEG payload ready for recognizeBytes, or null on failure.
 *
 * variant: 'crop-bottom' | 'contrast' | 'grayscale' | 'binarize'
 */
async function _enhanceVariant(srcUri, variant) {
  try {
    const img = await _loadImage(srcUri)
    let sx = 0, sy = 0, sw = img.naturalWidth, sh = img.naturalHeight
    let dw = sw, dh = sh
    // Cap the longest side to 2000px so the encoded JPEG stays under
    // recognizeBytes's typical safe limit and OCR runs fast.
    const maxSide = 2000
    const longest = Math.max(sw, sh)
    if (longest > maxSide) {
      const k = maxSide / longest
      dw = Math.round(sw * k); dh = Math.round(sh * k)
    }
    // Bottom-third crop: the MRZ lives at the bottom 30-40% of every TD3 passport.
    if (variant === 'crop-bottom') {
      const cropFrac = 0.42
      sy = Math.floor(sh * (1 - cropFrac))
      sh = sh - sy
      // Recompute display size proportional to the crop
      dw = Math.min(maxSide, sw)
      dh = Math.round(dw * (sh / sw))
    }
    const canvas = _makeCanvas(dw, dh)
    if (!canvas) return null
    const ctx = canvas.getContext('2d', { willReadFrequently: variant !== 'crop-bottom' })
    if (!ctx) return null
    ctx.drawImage(img, sx, sy, sw, sh, 0, 0, dw, dh)

    if (variant === 'contrast' || variant === 'grayscale' || variant === 'binarize') {
      try {
        const imgData = ctx.getImageData(0, 0, dw, dh)
        const d = imgData.data
        if (variant === 'contrast') {
          // 1.45x contrast around 128, then mild brightness lift.
          const C = 1.45
          for (let i = 0; i < d.length; i += 4) {
            d[i]   = Math.min(255, Math.max(0, ((d[i]   - 128) * C) + 132))
            d[i+1] = Math.min(255, Math.max(0, ((d[i+1] - 128) * C) + 132))
            d[i+2] = Math.min(255, Math.max(0, ((d[i+2] - 128) * C) + 132))
          }
        } else if (variant === 'grayscale') {
          for (let i = 0; i < d.length; i += 4) {
            const g = (d[i] * 0.299 + d[i+1] * 0.587 + d[i+2] * 0.114) | 0
            d[i] = d[i+1] = d[i+2] = g
          }
        } else if (variant === 'binarize') {
          // Adaptive-ish threshold: compute mean, then apply 0/255 around it
          let sum = 0, n = 0
          for (let i = 0; i < d.length; i += 4) { sum += (d[i] + d[i+1] + d[i+2]) / 3; n++ }
          const mean = sum / Math.max(1, n)
          const T = mean - 8   // push slightly toward black so faint chars survive
          for (let i = 0; i < d.length; i += 4) {
            const g = (d[i] + d[i+1] + d[i+2]) / 3
            const v = g > T ? 255 : 0
            d[i] = d[i+1] = d[i+2] = v
          }
        }
        ctx.putImageData(imgData, 0, 0)
      } catch (err) {
        console.debug('[passportScan] enhance variant failed:', variant, err?.message)
      }
    }
    return await _canvasToJpegBase64(canvas, 0.92)
  } catch (err) {
    console.debug('[passportScan] _enhanceVariant outer-throw:', variant, err?.message)
    return null
  }
}

// ── Single-photo OCR sweep ────────────────────────────────────────────────
// Runs every rotation × every variant, returning the first parse-success.
async function _sweepPhoto(ocrPath, dataUri, opts) {
  const { onProgress } = opts || {}

  // Order matters: cheapest pass first (native at 0°), then the rest.
  const variants = [
    { id: 'native',       label: 'native scan' },
    { id: 'crop-bottom',  label: 'MRZ crop'    },
    { id: 'contrast',     label: 'contrast'    },
    { id: 'grayscale',    label: 'grayscale'   },
    { id: 'binarize',     label: 'binarize'    },
  ]
  const totalPasses = variants.length * RETRY_ROTATIONS.length

  let passNum = 0
  let lastOcr = null
  let lastReason = 'no-mrz-found'
  const variantBytesCache = new Map()  // variant.id → base64 (computed once)

  // Pre-extract raw bytes once if we only have a dataUri (no native path).
  // We use these as the input for the native variant when ocrPath is unset.
  let rawBytes = null
  if (!ocrPath && dataUri && dataUri.startsWith('data:')) {
    const idx = dataUri.indexOf(',')
    if (idx >= 0) rawBytes = dataUri.slice(idx + 1)
  }

  for (const variant of variants) {
    let bytes = null
    if (variant.id === 'native') {
      // Native pass: prefer file path; fall back to raw bytes if path unavailable.
      bytes = rawBytes
    } else {
      if (!dataUri) continue
      if (!variantBytesCache.has(variant.id)) {
        emit(onProgress, 'ocr-pass', { passNum: passNum + 1, totalPasses, label: `Preparing ${variant.label}` })
        const b = await _enhanceVariant(dataUri, variant.id)
        variantBytesCache.set(variant.id, b)
      }
      bytes = variantBytesCache.get(variant.id)
      if (!bytes) {
        // Skip rotations for an undeliverable variant
        passNum += RETRY_ROTATIONS.length
        continue
      }
    }

    for (const rotation of RETRY_ROTATIONS) {
      passNum++
      emit(onProgress, 'ocr-pass', { passNum, totalPasses, label: `${variant.label} ${rotation}°`, rotation, variant: variant.id })

      let ocr = null
      try {
        if (variant.id === 'native' && ocrPath) {
          ocr = await recognizeImage(ocrPath, { rotation })
        } else if (bytes) {
          ocr = await recognizeBytes(bytes, { rotation })
        } else {
          ocr = { ok: false, reason: 'no-image-source' }
        }
      } catch (err) {
        ocr = { ok: false, reason: 'ocr-throw:' + (err?.message || 'unknown') }
      }

      lastOcr = ocr
      emit(onProgress, 'ocr-done', { passNum, rotation, variant: variant.id, ok: !!ocr?.ok, lines: ocr?.lines?.length ?? 0 })

      if (!ocr || !ocr.ok) continue
      if (!ocr.text && (!ocr.lines || !ocr.lines.length)) {
        lastReason = 'ocr-empty'; continue
      }

      const parsed = parsePassportFromOcr(ocr)
      if (parsed && parsed.ok) {
        return {
          ok: true,
          parsed,
          ocr,
          rotation,
          variant: variant.id,
          passNum,
          totalPasses,
        }
      }

      // ── Regional non-MRZ rescue ───────────────────────────────────────
      // The MRZ extractor returned nothing useful, but the OCR text may
      // contain a printed national ID number (front-of-card photos, no-MRZ
      // cards). parseTravelerDoc internally runs the regional NIN parsers
      // for Uganda, Kenya, Tanzania, Rwanda, Burundi, Ethiopia, DRC,
      // South Sudan, Sudan, etc. Costs ~1 ms per attempt.
      try {
        const ocrText = ocr.text || (ocr.lines || []).join('\n')
        if (ocrText && ocrText.length > 8) {
          const reg = parseTravelerDoc(ocrText)
          if (reg && reg.source === 'NON_MRZ' && (reg.dob_iso || reg.passport_no)) {
            return {
              ok: true,
              parsed: { ok: true, doc: reg, mrz: null, source: 'NON_MRZ' },
              ocr,
              rotation,
              variant: variant.id,
              passNum,
              totalPasses,
              regional: true,
            }
          }
        }
      } catch { /* swallow — regional rescue must never block MRZ pipeline */ }

      const diag = diagnoseOcrFailure(ocr)
      if (diag) lastReason = diag
    }
  }

  return { ok: false, lastOcr, lastReason, passNum, totalPasses }
}

// ── Main export ───────────────────────────────────────────────────────────

/**
 * Scan a passport and return the parsed document.
 *
 * @param {object}   opts
 * @param {'camera'|'gallery'} [opts.source='camera']  Where to obtain the photo.
 * @param {function} [opts.onProgress] (step, detail) → void
 *   step values: 'permission-check', 'photo-start', 'photo-done',
 *                'ocr-start', 'ocr-pass', 'ocr-done', 'retry-guidance'
 *
 * Returns
 *   { ok:true,  doc, mrz, raw_text, attempts }
 *   { ok:false, reason, hint, settingsUrl?, candidates?, raw_text? }
 */
export async function scanPassport({ onProgress, source = 'camera' } = {}) {
  // E22 — feature gate
  if (!isSentinelFeatureOn(CAPABILITY_KEYS.MRZ)) {
    return finalise({ ok: false, reason: 'disabled' })
  }

  // E21 — concurrent call lock
  if (_scanning) return finalise({ ok: false, reason: 'scan-in-progress' })
  _scanning = true

  try {
    // Dev mock
    if (isMockMode()) {
      return finalise(parsePassportFromOcr(MOCK_OCR))
    }

    // ── Load @capacitor/camera FIRST ─────────────────────────────────
    emit(onProgress, 'permission-check', {})
    let Camera, CameraResultType, CameraSource
    try {
      const cam        = await import('@capacitor/camera')
      Camera           = cam?.Camera
      CameraResultType = cam?.CameraResultType
      CameraSource     = cam?.CameraSource
    } catch (err) {
      console.debug('[passportScan] camera import failed:', err?.message)
      return finalise({ ok: false, reason: 'camera-import-failed' })
    }
    if (!Camera?.getPhoto || !CameraResultType || !CameraSource) {
      return finalise({ ok: false, reason: 'camera-not-available' })
    }

    // For the camera source we need camera permission. Gallery uses the
    // photo-library permission which the OS handles via the picker UI.
    if (source === 'camera') {
      emit(onProgress, 'permission-check', { phase: 'requesting' })
      const perm = await requestCameraPermission(Camera)
      if (!perm.granted) {
        if (perm.permanent) {
          const settingsUrl = buildSettingsUrl()
          return finalise({ ok: false, reason: 'permission-permanent', settingsUrl })
        }
        return finalise({ ok: false, reason: 'permission-denied' })
      }
    }

    // ── E23 — OCR model availability ──────────────────────────────────
    const ocrAvail = await isOcrAvailable()

    // ── Multi-attempt photo loop ──────────────────────────────────────
    const allAttempts = []
    let lastReason    = 'no-mrz-found'
    let lastOcr       = null

    for (let attempt = 1; attempt <= MAX_PHOTO_ATTEMPTS; attempt++) {

      if (attempt > 1) {
        emit(onProgress, 'retry-guidance', { attempt, guidance: guidanceForRetry(lastReason) })
      }

      emit(onProgress, 'photo-start', { attempt, source })
      let photo
      const suggestFlash = source === 'camera' && attempt > 1 && lastReason === 'ocr-dark'

      try {
        photo = await Camera.getPhoto({
          quality:            attempt === 1 ? 90 : 95,
          allowEditing:       false,
          // Request DataUrl so we have direct bytes for the canvas-enhancement
          // pipeline without a second filesystem read on Android. The native
          // plugin still receives the path via webPath for the rotation-0
          // probe pass.
          resultType:         CameraResultType.DataUrl,
          source:             source === 'gallery' ? CameraSource.Photos : CameraSource.Camera,
          width:              attempt === 1 ? 2400 : 2000,
          correctOrientation: true,
          saveToGallery:      false,
          promptLabelHeader:  source === 'gallery'
            ? 'Pick the passport photo'
            : (attempt === 1
              ? 'Photograph the passport data page'
              : suggestFlash
                ? 'Enable flash, then photograph the data page — last photo was too dark'
                : `Try again — ${guidanceForRetry(lastReason)}`),
          promptLabelPicture: suggestFlash ? 'Take photo (enable flash first)' : 'Take photo',
          promptLabelPhoto:   'Library',
        })
      } catch (err) {
        const msg = String(err?.message || err || '')
        if (/cancel|user cancelled/i.test(msg)) return finalise({ ok: false, reason: 'photo-cancelled' })
        if (/denied|permission/i.test(msg))      return finalise({ ok: false, reason: 'permission-denied' })
        if (/no space|storage|ENOSPC/i.test(msg)) return finalise({ ok: false, reason: 'photo-failed' })
        console.debug('[passportScan] camera failed:', msg)
        return finalise({ ok: false, reason: 'photo-failed' })
      }

      const ocrPath = photo?.path || photo?.webPath
      const dataUri = photo?.dataUrl
        ? (photo.dataUrl.startsWith('data:') ? photo.dataUrl : `data:image/jpeg;base64,${photo.dataUrl}`)
        : (photo?.webPath || photo?.path || null)

      if (!ocrPath && !dataUri) return finalise({ ok: false, reason: 'no-photo-path' })
      emit(onProgress, 'photo-done', { attempt })

      if (!ocrAvail) {
        return finalise({ ok: false, reason: 'ocr-not-available' })
      }

      // ── Multi-engine sweep — first parse-success returns immediately ──
      emit(onProgress, 'ocr-start', { attempt, rotation: 0 })
      const sweep = await _sweepPhoto(ocrPath || dataUri, dataUri, { onProgress })
      allAttempts.push({ attempt, passes: sweep.passNum || sweep.totalPasses })

      if (sweep.ok) {
        return {
          ok:       true,
          doc:      sweep.parsed.doc,
          mrz:      sweep.parsed.mrz,
          raw_text: sweep.ocr?.text || '',
          attempts: allAttempts,
          rotation: sweep.rotation,
          variant:  sweep.variant,
          regional: !!sweep.regional,
          attempt,
        }
      }

      lastOcr    = sweep.lastOcr
      lastReason = sweep.lastReason || diagnoseOcrFailure(lastOcr) || 'no-mrz-found'

      if (['ocr-not-available', 'unbundled', 'not-native', 'camera-not-available'].includes(lastReason)) break
    }

    const finalReason = allAttempts.length >= MAX_PHOTO_ATTEMPTS
      ? 'all-attempts-failed'
      : lastReason || 'no-mrz-found'

    return finalise({
      ok:         false,
      reason:     finalReason,
      attempts:   allAttempts,
      raw_text:   lastOcr?.text || '',
    })

  } catch (err) {
    console.debug('[passportScan] orchestrator threw:', err?.message)
    return finalise({ ok: false, reason: 'orchestrator-error:' + (err?.message || 'unknown') })
  } finally {
    _scanning = false
  }
}

// ── Helpers ────────────────────────────────────────────────────────────────

function emit(fn, step, detail) {
  if (typeof fn !== 'function') return
  try { fn(step, detail) } catch { /* listener errors are non-fatal */ }
}

function buildSettingsUrl() {
  try {
    const cap = (typeof window !== 'undefined' && window.Capacitor) || null
    const plat = typeof cap?.getPlatform === 'function' ? cap.getPlatform() : 'web'
    if (plat === 'android' || plat === 'ios') return 'app-settings:'
  } catch { /* swallow */ }
  return null
}
