/**
 * services/plugins/share.js — native Share-Sheet + Filesystem for PDF export.
 *
 * References:
 *   Share:      https://capacitorjs.com/docs/apis/share
 *   Filesystem: https://capacitorjs.com/docs/apis/filesystem
 *
 * Strategy:
 *   1. Receive raw bytes (e.g. a jsPDF Blob already produced elsewhere).
 *   2. On native: write to Documents dir, then invoke Share with file://
 *      URL; on cancel or failure, fall through to web share / download.
 *   3. On web: use navigator.share (Web Share API) if available, else
 *      anchor-download.
 *
 * Never throws. Returns { shared, target } on success or { shared:false, reason }.
 */

import { makePluginSandbox } from './_base'
import { CAPABILITY_KEYS } from '../capabilities'

const shareSandbox = makePluginSandbox({
  name: 'share',
  importer: () => import('@capacitor/share'),
  settingsKey: CAPABILITY_KEYS.PDF_SHARE,
  requiresNative: false,
})

const fsSandbox = makePluginSandbox({
  name: 'filesystem',
  importer: () => import('@capacitor/filesystem'),
  settingsKey: CAPABILITY_KEYS.PDF_SHARE,
  requiresNative: false,
})

function sanitizeName(name) {
  return String(name || 'file').replace(/[^a-zA-Z0-9._-]+/g, '_').slice(0, 80)
}

async function blobToBase64(blob) {
  const buf = await blob.arrayBuffer()
  const bytes = new Uint8Array(buf)
  let bin = ''
  const chunk = 0x8000
  for (let i = 0; i < bytes.length; i += chunk) {
    bin += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk))
  }
  return btoa(bin)
}

/**
 * Share a Blob (e.g. PDF) via the best available channel.
 * @param blob    Blob
 * @param opts    { filename, title?, text?, dialogTitle? }
 */
export async function sharePdf(blob, opts = {}) {
  const filename = sanitizeName(opts.filename || 'report.pdf')
  const title = opts.title || 'POE Screening Report'
  const text = opts.text || ''
  const dialogTitle = opts.dialogTitle || 'Share report'

  // --- Native path ---
  const nativeOk = await fsSandbox.isAvailable() && await shareSandbox.isAvailable()
  if (nativeOk) {
    const written = await fsSandbox.run(async ({ Filesystem, Directory, Encoding }) => {
      const b64 = await blobToBase64(blob)
      const res = await Filesystem.writeFile({
        path: filename,
        data: b64,
        directory: Directory.Documents,
      })
      return res?.uri || null
    }, { fallback: null })

    if (written) {
      const shared = await shareSandbox.run(async ({ Share }) => {
        await Share.share({ title, text, files: [written], dialogTitle })
        return true
      }, { fallback: false })
      if (shared) return { shared: true, target: 'native', uri: written }
      // If share failed but file is written, still return success-with-file path.
      return { shared: true, target: 'native-file-only', uri: written }
    }
  }

  // --- Web / fallback path ---
  try {
    if (typeof navigator !== 'undefined' && typeof navigator.share === 'function' && typeof File !== 'undefined') {
      const file = new File([blob], filename, { type: 'application/pdf' })
      if (typeof navigator.canShare !== 'function' || navigator.canShare({ files: [file] })) {
        await navigator.share({ title, text, files: [file] })
        return { shared: true, target: 'web-share' }
      }
    }
  } catch (err) {
    console.debug('[share] web-share failed:', err?.message)
  }

  // Anchor download last-resort.
  try {
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    document.body.appendChild(a)
    a.click()
    setTimeout(() => { try { document.body.removeChild(a); URL.revokeObjectURL(url) } catch {} }, 1000)
    return { shared: true, target: 'download' }
  } catch (err) {
    console.debug('[share] download fallback failed:', err?.message)
    return { shared: false, reason: 'no-channel' }
  }
}

export async function isAvailable() {
  return (await shareSandbox.isAvailable()) || typeof navigator !== 'undefined'
}
