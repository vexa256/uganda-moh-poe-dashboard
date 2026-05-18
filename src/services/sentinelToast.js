/**
 * services/sentinelToast.js — the single error/success surface for every
 * Sentinel productivity feature. See docs/sentinel-plan/ARCHITECTURE.md §6.
 *
 * Usage:
 *   import { sentinelToast } from '@/services/sentinelToast.js'
 *   await sentinelToast('Passport model downloading', 'primary')
 *
 * Contract:
 *   - Never throws. A failing toast is a no-op.
 *   - User-kind messages only. Never expose error codes or stack traces.
 *   - Position top, duration 2.5 s, so it never blocks the capture form.
 */

const DEFAULT_DURATION = 2500

export async function sentinelToast(message, color = 'warning', opts = {}) {
  if (!message) return
  try {
    const { toastController } = await import('@ionic/vue')
    const t = await toastController.create({
      message:   String(message).slice(0, 240),
      color,
      position:  'top',
      duration:  Number.isFinite(opts.duration) ? opts.duration : DEFAULT_DURATION,
      cssClass:  'sentinel-toast',
      ...(opts.buttons ? { buttons: opts.buttons } : {}),
    })
    await t.present()
  } catch (err) {
    // Toast failure must never reach the caller. The feature already decided
    // what to do on failure; the toast is just a courtesy.
    console.debug('[sentinel] toast failed:', err?.message)
  }
}

/** Convenience variants — exported so feature code reads at a glance. */
export const sentinelError   = (msg) => sentinelToast(msg, 'danger')
export const sentinelSuccess = (msg) => sentinelToast(msg, 'success')
export const sentinelInfo    = (msg) => sentinelToast(msg, 'primary')
