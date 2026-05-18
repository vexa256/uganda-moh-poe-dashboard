/**
 * useAuth.js — read-only access to the current AUTH_DATA payload.
 *
 * Source of truth: sessionStorage['AUTH_DATA'] (set by App.vue login flow).
 * Returns a reactive ref<Record<string,any>|null> that updates on storage events.
 *
 * NOTE: `sessionStorage` (NOT localStorage) — this matches every other view in
 * the app (HomePage, AlertHistory, ActiveAlerts, App.vue login flow, router
 * guard). Reading from the wrong one yields null and breaks downstream views.
 *
 * Why a composable: any view that needs role_key / scope can `const auth = useAuth()`
 * and read `auth.value.role_key` without re-implementing sessionStorage parsing.
 *
 * Login/logout writes happen in App.vue. This composable is read-only by design.
 */
import { ref, onMounted, onUnmounted } from 'vue'

const AUTH_KEY = 'AUTH_DATA'

function readAuth() {
  try {
    const raw = sessionStorage.getItem(AUTH_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw)
    return (parsed && typeof parsed === 'object') ? parsed : null
  } catch { return null }
}

// Module-level singleton — every component sees the same ref.
const _authRef = ref(readAuth())

function _refresh() {
  _authRef.value = readAuth()
}

if (typeof window !== 'undefined') {
  window.addEventListener('storage', (e) => {
    if (e.key === AUTH_KEY) _refresh()
  })
}

export function useAuth() {
  // Re-read on mount in case App.vue updated AUTH_DATA after this module loaded.
  onMounted(_refresh)
  // Optional: poll once per minute to catch logout from another tab without storage event.
  let interval = null
  onMounted(() => { interval = setInterval(_refresh, 60_000) })
  onUnmounted(() => { if (interval) clearInterval(interval) })

  return _authRef
}

/** Imperative refresh (after login/logout from App.vue). */
export function refreshAuth() { _refresh() }

/** Direct snapshot (non-reactive) for one-shot reads. */
export function getAuthSnapshot() { return readAuth() }
