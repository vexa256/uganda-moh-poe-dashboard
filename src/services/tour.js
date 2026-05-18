/**
 * services/tour.js — premium feature-spotlight orchestration.
 *
 * Coordinates:
 *   - First-time feature-spotlight tour on a version bump.
 *   - On-demand replays from Capabilities Help view ("Show me").
 *   - One-time coachmarks (first time a user encounters a new UI affordance).
 *
 * Design:
 *   - Uses DOM events + a bus of queued steps.
 *   - The actual visual renderer is <FeatureSpotlight/> mounted in App.vue;
 *     this service just emits events the component subscribes to.
 *   - Steps identify their anchor by `selector` (CSS) or by `elementId`.
 *   - Persistence: "tour.seen.<key>" flags in localStorage; "cap.tour.seen_version"
 *     tracks which app-capabilities version the user has completed.
 *
 * Public API:
 *   runSteps(steps)           – play an array of {selector,title,body,icon,ctaLabel}
 *   coachmark(key, step)      – play step ONCE per device (persists seen flag)
 *   maybeRunWelcomeTour()     – plays the big welcome tour if version changed
 *   dismiss()                 – cancels any active playback
 *   onStep(cb)/onDone(cb)     – subscribe to renderer events
 *
 * The current "capabilities tour version" is bumped whenever we add a new batch
 * of features — each bump surfaces the delta to every user.
 */

import { CAPABILITY_KEYS, getValue, setEnabled } from './capabilities'

export const TOUR_VERSION = '2026.04.24-wave1'

const SEEN_PREFIX = 'tour.seen.'
const TOUR_EVENT = 'tour-event'

function emit(type, detail = {}) {
  try { window.dispatchEvent(new CustomEvent(TOUR_EVENT, { detail: { type, ...detail } })) } catch {}
}

function seen(key) {
  try { return localStorage.getItem(SEEN_PREFIX + key) === '1' } catch { return true }
}
function markSeen(key) {
  try { localStorage.setItem(SEEN_PREFIX + key, '1') } catch {}
}

export function runSteps(steps) {
  if (!Array.isArray(steps) || steps.length === 0) return
  emit('run', { steps: steps.map((s, i) => ({
    selector: s.selector || null,
    elementId: s.elementId || null,
    title: s.title || '',
    body: s.body || '',
    icon: s.icon || null,
    ctaLabel: s.ctaLabel || (i === steps.length - 1 ? 'Got it' : 'Next'),
    placement: s.placement || 'auto',
  })) })
}

export function coachmark(key, step) {
  if (!key || seen(key)) return false
  runSteps([step])
  // Mark seen regardless of whether the anchor was found — a missed coachmark
  // should not re-play ad infinitum.
  markSeen(key)
  return true
}

export function dismiss() { emit('dismiss') }

export function onEvent(cb) {
  const handler = (e) => { try { cb(e.detail) } catch {} }
  try { window.addEventListener(TOUR_EVENT, handler) } catch {}
  return () => { try { window.removeEventListener(TOUR_EVENT, handler) } catch {} }
}

/**
 * Welcome-tour catalog. Safe-by-default: each step includes a fallback selector
 * (the Capabilities Help view nav link) so the tour always has *something* to
 * anchor to even if a particular feature is disabled.
 */
export const WELCOME_TOUR = [
  {
    selector: '.mn__item[aria-label*="Capabilities"]',
    elementId: 'tour-anchor-caps-help',
    title: 'New features unlocked',
    body: 'This device now supports haptics, offline-tolerant network detection, keep-awake, voice dictation, QR scanning, PDF share, reminders, app-lock and a staff directory. Let us walk you through each one.',
    icon: 'sparkles',
    ctaLabel: 'Show me',
  },
  {
    elementId: 'tour-anchor-settings',
    title: 'Toggle any feature',
    body: 'Every new capability can be switched on or off in App Settings → Capabilities. Turn off anything you don\'t need — the rest of the app keeps working.',
    icon: 'settings',
    ctaLabel: 'Next',
  },
  {
    elementId: 'tour-anchor-directory',
    title: 'Staff Directory',
    body: 'Tap any phone number to dial a district or PHEOC officer directly — no more copy-pasting from email.',
    icon: 'call',
    ctaLabel: 'Next',
  },
  {
    elementId: 'tour-anchor-applock',
    title: 'Protect this device',
    body: 'Enable the app-lock to gate sensitive PII behind a biometric or 6-digit PIN — recommended for shared devices.',
    icon: 'lock',
    ctaLabel: 'Next',
  },
  {
    elementId: 'tour-anchor-caps-help',
    title: 'Dedicated Help view',
    body: 'Anything you forget — how to use voice input, how PDF sharing works, how to reset your PIN — lives in Capabilities & Help. You can replay this tour anytime from there.',
    icon: 'information-circle',
    ctaLabel: 'Got it',
  },
]

export function maybeRunWelcomeTour() {
  try {
    const seenVer = getValue(CAPABILITY_KEYS.TOUR_SEEN)
    if (seenVer === TOUR_VERSION) return false
    // Run on next tick so the app has time to render anchors.
    setTimeout(() => {
      runSteps(WELCOME_TOUR)
      setEnabled(CAPABILITY_KEYS.TOUR_SEEN, TOUR_VERSION)
    }, 1200)
    return true
  } catch (err) {
    console.debug('[tour] welcome check failed:', err?.message)
    return false
  }
}

export function replayWelcomeTour() {
  try { setEnabled(CAPABILITY_KEYS.TOUR_SEEN, '') } catch {}
  runSteps(WELCOME_TOUR)
}
