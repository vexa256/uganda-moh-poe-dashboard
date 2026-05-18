/**
 * httpInterceptor.js — global fetch interceptor + reactive in-flight tracker.
 *
 * Intercepts window.fetch at the module level. Every fetch request increments
 * a counter; every response (ok or error) decrements it. Views subscribe to
 * the counter to show/hide a loading overlay.
 *
 * IMPORTANT — what is excluded from the loader:
 *   • Background sync URLs (/sync, /primary-screenings, alert outbox)
 *   • Reference data fetches (/geo, /reference)
 *   • Auth pings (/up, /heartbeat)
 *   • Pre-flight / OPTIONS
 *
 * Only foreground user-initiated requests trigger the loader (API reads for
 * the alert war room + My Cases page).
 */
import { ref, readonly } from 'vue'

// Global reactive in-flight counter. Views import `inFlight` to subscribe.
const _inFlight = ref(0)
export const inFlight = readonly(_inFlight)

// URL patterns that should NOT trigger the visible loader (background syncs etc.)
const SILENT_PATTERNS = [
  '/sync',
  '/primary-screenings',
  '/secondary-screenings',
  '/outbox',
  '/geo/',
  '/reference/',
  '/heartbeat',
  '/up',
  'notification_id=',    // cross-account primary fetch
  'per_page=1',           // background cross-account lookup
]

function isSilent(url) {
  const u = String(url)
  return SILENT_PATTERNS.some(p => u.includes(p))
}

// Only install once, even if this module is hot-reloaded
if (!window.__poeFetchIntercepted) {
  window.__poeFetchIntercepted = true
  const _origFetch = window.fetch.bind(window)

  window.fetch = async function interceptedFetch(input, init = {}) {
    const method = (init?.method || 'GET').toUpperCase()
    if (method === 'OPTIONS') return _origFetch(input, init)

    const url = typeof input === 'string' ? input
      : input instanceof Request ? input.url
      : String(input)

    const silent = isSilent(url)
    if (!silent) _inFlight.value++

    try {
      return await _origFetch(input, init)
    } finally {
      if (!silent && _inFlight.value > 0) _inFlight.value--
    }
  }
}
