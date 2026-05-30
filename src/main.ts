import { createApp } from 'vue'
import App from './App.vue'
import router from './router'
import { IonicVue } from '@ionic/vue'
import { Capacitor } from '@capacitor/core'
import { CapacitorUpdater } from '@capgo/capacitor-updater'
// Import the composable here so its plugin event listeners (download,
// downloadComplete, updateAvailable, updateFailed, appReady) are wired
// at boot — surfaces like UpdateBanner + AppSettings then just read the
// reactive state without re-attaching listeners.
import '@/composables/useAppUpdates'
// Global HTTP interceptor — must be imported before any fetch call is made
import '@/services/httpInterceptor'

/* Core CSS required for Ionic components to work properly */
import '@ionic/vue/css/core.css'

/* Basic CSS for apps built with Ionic */
import '@ionic/vue/css/normalize.css'
import '@ionic/vue/css/structure.css'
import '@ionic/vue/css/typography.css'

/* Optional CSS utils */
import '@ionic/vue/css/padding.css'
import '@ionic/vue/css/float-elements.css'
import '@ionic/vue/css/text-alignment.css'
import '@ionic/vue/css/text-transformation.css'
import '@ionic/vue/css/flex-utils.css'
import '@ionic/vue/css/display.css'

/**
 * DARK MODE — PERMANENTLY DISABLED per project requirements.
 */

/* Reference data — load order is CRITICAL ──────────────────────────────────
 *
 *  1. Diseases.js              Base scoring engine (41 diseases, scoreDiseases)
 *  2. Diseases_intelligence.js Intelligence layer — extends window.DISEASES with:
 *                                 endemic country oracle, WHO case definitions,
 *                                 syndrome classification, IHR escalation rules,
 *                                 generateClinicalReport(), getEnhancedScoreResult()
 *                              MUST load after Diseases.js — reads window.DISEASES
 *  3. exposures.js             Exposure catalog with engine-code mapping
 *                              MUST load after Diseases_intelligence.js (checks for it)
 *  4. POEs.js                  Point of Entry hierarchy reference data
 *  5. countries.js             ISO country code reference data
 *  6. poeDB.js                 IndexedDB layer — Dexie singleton, all DB operations
 *
 *  NEVER import Dexie directly in views — always go through poeDB.js.
 *  NEVER put clinical logic in Vue files — always call window.DISEASES.* functions.
 */
import '/src/Diseases.js'
import '/src/Diseases_intelligence.js'
import '/src/exposures.js'
import '/src/POEs.js'
import '/src/countries.js'
import '/src/services/poeDB.js'

/**
 * Server URL — set via .env:
 *   VITE_SERVER_URL=http://your-server/api
 * Defaults to localhost for development.
 */
window.SERVER_URL = import.meta.env.VITE_SERVER_URL || 'https://ug-poe.ecsahc.com/api'

/**
 * Country tenant — set via .env: VITE_COUNTRY_CODE=UG (or ZM, etc.).
 * Used by views (UsersList, etc.) to populate `country_code` on writes
 * so we never POST a fork-residue value and trip server-side scope rules.
 */
window.COUNTRY_CODE = (import.meta.env.VITE_COUNTRY_CODE || 'UG').toUpperCase()

/* Project theme variables */
import './theme/variables.css'

import VueApexCharts from 'vue3-apexcharts'

const app = createApp(App)
  // Disable all Ionic-driven Web Animations (page transitions, modal slide,
  // menu slide, action-sheet rise, etc.). Combined with the CSS kill-switch
  // in theme/variables.css, this eliminates the sidebar-freeze contention
  // on lower-end Android devices.
  .use(IonicVue, { animated: false })
  .use(router)
  .use(VueApexCharts)

// Client-side error telemetry (clientLogger.js) was previously installed
// here. It has been REMOVED at the user's request — the production server
// does not yet expose POST /api/client-logs, so the periodic flusher was
// generating 404 spam on every page. The service file remains in the
// repo for a future opt-in re-enable; nothing imports it at boot.

router.isReady().then(() => {
  app.mount('#app')
  // Tell the Capgo plugin the current OTA bundle booted cleanly. If this
  // call never fires within appReadyTimeout (10s, see capacitor.config.ts),
  // the plugin rolls back to the previous bundle automatically. Calling it
  // right after mount() means rollback fires for any failure that prevents
  // Vue from ever rendering its first view.
  //
  // Hard-guard with Capacitor.isNativePlatform() — on web preview / PWA the
  // plugin's native bridge throws, and we'd rather not catch a stack trace
  // on every load. The try/catch is a second line of defence for the case
  // where the plugin loaded but its method is unavailable (older versions).
  if (Capacitor?.isNativePlatform?.()) {
    try { CapacitorUpdater.notifyAppReady() } catch (e: any) {
      console.debug('[capgo] notifyAppReady failed:', e?.message)
    }
  }
  // Unified sync engine — single source of truth for outbound sync.
  // Replaces the old per-feature workers. Drains every syncable IDB store
  // (primary screenings → notifications → secondary screenings phase 1 →
  // phase 2 → alerts) on boot, online (3-burst), visibility, focus,
  // pageshow, Capacitor App.resume + Network change, every router
  // navigation, a continuous 5s heartbeat while visible (15s while hidden),
  // and after every IDB write that nudges window.__SYNC_NOW__().
  // No retry caps. Records never quarantine. See services/syncEngine.js.
  import('@/services/syncEngine').then(({ startSyncEngine }) => {
    try { startSyncEngine() } catch (e) { console.debug('[sync-engine] start failed:', (e as any)?.message) }
  }).catch(() => { /* engine import failed — app continues without background flush */ })

  // Router-change kick — every navigation triggers a sync attempt. Cheap
  // because the engine debounces and short-circuits when offline.
  try {
    router.afterEach(() => {
      try {
        const w = window as any
        if (typeof w.__SYNC_NOW__ === 'function') w.__SYNC_NOW__('router-after-each')
      } catch { /* engine not yet started — fine */ }
    })
  } catch { /* router instance unavailable */ }

  // WhatsApp-style alert notifier. Wires the LocalNotifications tap handler
  // and re-emits a `app:deep-link` window event whose detail.route the
  // router should consume. install() is idempotent and silently no-ops on
  // platforms without the plugin.
  import('@/services/alertNotifier').then(({ install }) => {
    install().catch((e: any) => console.debug('[alert-notifier] install failed:', e?.message))
  }).catch(() => { /* notifier import failed — app continues without local notifications */ })

  // Deep-link receiver — when a user taps a notification, alertNotifier
  // dispatches `app:deep-link` and we route to it via the Vue router.
  if (typeof window !== 'undefined') {
    window.addEventListener('app:deep-link', (ev: any) => {
      const route = ev?.detail?.route
      if (typeof route === 'string' && route.startsWith('/')) {
        try { router.push(route) } catch (e: any) { console.debug('[deep-link] push failed:', e?.message) }
      }
    })
  }

  // PWA lifecycle — service-worker registration + install-prompt capture.
  // No-ops inside Capacitor (the WebView already serves bundled assets).
  import('@/services/pwa').then(({ initPwa }) => {
    try { initPwa() } catch (e: any) { console.debug('[pwa] init failed:', e?.message) }
  }).catch(() => {})
})