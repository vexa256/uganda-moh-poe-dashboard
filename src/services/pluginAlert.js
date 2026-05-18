/**
 * services/pluginAlert.js — native Android alert for real plugin failures.
 *
 * Shows an Ionic alertController dialog with the actual failure reason and
 * technical detail so officers (or support staff) know exactly why a plugin
 * stopped working. Only used for UNEXPECTED plugin failures — NOT for normal
 * user-driven outcomes like "user cancelled" or "no MRZ found".
 *
 * Design:
 *   - The alert is informational only (single OK button).
 *   - Normal app functionality is never blocked by this alert.
 *   - The fallback (manual entry / typed input) always remains available
 *     whether or not the user taps OK.
 *   - The alert is fire-and-forget: pluginAlert() does not await user input.
 *
 * Usage:
 *   import { pluginAlert, isRealPluginFailure } from '@/services/pluginAlert'
 *   if (isRealPluginFailure(reason)) pluginAlert('Camera', reason, hint)
 */

// Reasons that represent real plugin / infrastructure failures.
// Everything else (user-cancel, no-match, quality issues) is a normal outcome.
const PLUGIN_FAILURE_REASONS = new Set([
  'camera-import-failed',
  'camera-not-available',
  'ocr-not-available',
  'unbundled',
  'recognizer-init-failed',
  'input-image-failed',
  'process-error',
  'handler-error',
  'orchestrator-error',
  'content-uri-unsupported',
  'not-native',           // plugin not available in this build
])

/**
 * Returns true when a reason string signals a real plugin failure that the
 * officer couldn't cause by their actions (not quality, not cancel).
 */
export function isRealPluginFailure(reason) {
  if (!reason || typeof reason !== 'string') return false
  if (PLUGIN_FAILURE_REASONS.has(reason)) return true
  // Catch colon-separated reason:detail forms (e.g. 'process-error:message')
  const base = reason.split(':')[0]
  return PLUGIN_FAILURE_REASONS.has(base)
}

/**
 * Show a native Ionic alert dialog with the plugin failure reason.
 *
 * @param {string} feature  Short name of the feature ('Camera', 'OCR', 'Voice')
 * @param {string} reason   Machine reason code from the plugin
 * @param {string} [hint]   Optional user-facing hint already formatted
 */
export async function pluginAlert(feature, reason, hint) {
  try {
    const { alertController } = await import('@ionic/vue')
    const detail = _describe(reason)
    const message = [
      hint ? `<strong>${hint}</strong>` : '',
      `<br><br><small style="color:#64748B">`,
      `Technical detail: ${detail}`,
      `<br>Reason code: <code>${reason || 'unknown'}</code>`,
      `<br>Feature: ${feature}`,
      `</small>`,
    ].filter(Boolean).join('')

    const alert = await alertController.create({
      header:   `${feature} plugin error`,
      message,
      buttons:  [{ text: 'OK', role: 'cancel' }],
      cssClass: 'plugin-failure-alert',
    })
    await alert.present()
    // Do NOT await dismiss — let the officer continue using the form
    // without waiting for them to tap OK.
  } catch {
    // alertController itself failed (e.g. component not mounted) — ignore.
    // The caller already displays the error in the form UI.
  }
}

/** Map machine reason codes to plain-English description. */
function _describe(reason) {
  if (!reason) return 'Unknown error'
  const base = reason.split(':')[0]
  const detail = reason.includes(':') ? reason.slice(reason.indexOf(':') + 1) : ''
  const map = {
    'camera-import-failed':   'Camera plugin is not installed in this APK build. Contact your system administrator to reinstall the app.',
    'camera-not-available':   'No camera hardware was found. This device may not have a back-facing camera.',
    'ocr-not-available':      'The text-recognition plugin is unavailable. The ML Kit OCR model may not be installed.',
    'unbundled':              'The OCR model was removed from this build to reduce APK size. Contact support to get an OCR-enabled build.',
    'recognizer-init-failed': 'ML Kit text recognizer failed to initialise. Google Play Services may be out of date or unavailable.',
    'input-image-failed':     'The photo could not be passed to the OCR engine. The image file may be corrupt.',
    'process-error':          `ML Kit OCR threw an exception during processing${detail ? ': ' + detail : ''}.`,
    'handler-error':          `OCR success handler threw${detail ? ': ' + detail : ''}. This is a bug — please report it.`,
    'orchestrator-error':     `The scanning orchestrator hit an unexpected error${detail ? ': ' + detail : ''}. Please report this.`,
    'content-uri-unsupported':'Content URIs are not supported for OCR — only file:// paths are accepted.',
    'not-native':             'This feature requires a native Android build. It does not work in web / browser mode.',
  }
  return map[base] || (detail ? `${base} — ${detail}` : base)
}
