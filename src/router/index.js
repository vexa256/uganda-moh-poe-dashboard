import { createRouter, createWebHashHistory } from '@ionic/vue-router'

import HomePage from '../views/HomePage.vue'
import PrimaryScreening from '../views/PrimaryScreening.vue'
import POEs from '../views/POEs.vue'
import DiseaseInteligence from '../views/DiseaseInteligence.vue'
import { can as rbacCan, permForRoute } from '@/services/rbac'

/**
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  POE Sentinel Router — index.js                                      ║
 * ╠══════════════════════════════════════════════════════════════════════╣
 * ║  ORDERING LAW — NEVER VIOLATE:                                       ║
 * ║                                                                      ║
 * ║  SPECIFIC paths must be declared BEFORE wildcard /:param paths in    ║
 * ║  the same segment. Vue Router matches top-to-bottom.                 ║
 * ║                                                                      ║
 * ║  /secondary-screening/records   ← MUST come before                  ║
 * ║  /secondary-screening/:notificationId  ← wildcard catches "records"  ║
 * ║                                                                      ║
 * ║  Same applies to /primary-screening/dashboard and /records.          ║
 * ║                                                                      ║
 * ║  LAW 1: Navigate with server integer id for most routes.             ║
 * ║  EXCEPTION: /secondary-screening/:notificationId uses client_uuid   ║
 * ║  (the IDB primary key) — SecondaryScreening reads IDB by this key.  ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 */

const routes = [

  // ── Root redirects ─────────────────────────────────────────────────────────
  {
    path: '/',
    redirect: '/home',
  },
  {
    path: '/dashboard',
    redirect: '/home',
  },

  // ── Home Dashboard ─────────────────────────────────────────────────────────
  {
    path: '/home',
    name: 'Home',
    component: HomePage,
  },

  // ── Welcome / role-aware AI briefing (first page after login) ─────────────
  // Always reachable from the menu so users can replay it.
  {
    path: '/welcome',
    name: 'Welcome',
    component: () => import('@/views/WelcomeGuide.vue'),
  },
  {
    path: '/DiseaseInteligence',
    name: 'DiseaseInteligence',
    component: DiseaseInteligence,
  },

  // ── Primary Screening (capture screen) ────────────────────────────────────
  {
    path: '/PrimaryScreening',
    name: 'PrimaryScreening',
    component: PrimaryScreening,
  },

  // ── Screening Intelligence Dashboard (primary + secondary analytics) ────────
  // ⚑ Specific paths declared BEFORE any wildcard in the same segment
  {
    path: '/screening-dashboard',
    name: 'ScreeningDashboard',
    component: () => import('@/views/PrimaryScreeningDashboard.vue'),
  },
  // Legacy route redirect — keep old bookmarks working
  {
    path: '/primary-screening/dashboard',
    redirect: '/screening-dashboard',
  },

  // ── Primary Screening Records (officer case register) ──────────────────────
  // 2026-05-06 — components swapped per user mandate. The previous binding
  // rendered the secondary register here. Now `/primary-screening/records`
  // loads SecondaryRecords.vue's content. Route NAME left as
  // 'PrimaryScreeningRecords' so RBAC allow-list (SCREENER_ALLOWED) keeps
  // working unchanged.
  {
    path: '/primary-screening/records',
    name: 'PrimaryScreeningRecords',
    component: () => import('@/views/SecondaryRecords.vue'),
  },

  // ── Primary Screening Report (POE-scoped analytics + filterable PDF) ────
  {
    path: '/primary-screening/report',
    name: 'PrimaryScreeningReport',
    component: () => import('@/views/PrimaryScreeningReport.vue'),
  },

  // ── Secondary Screening Report (POE-scoped analytics + filterable PDF) ──
  {
    path: '/secondary-screening/report',
    name: 'SecondaryScreeningReport',
    component: () => import('@/views/SecondaryScreeningReport.vue'),
  },

  // ── Notifications Centre (referral queue for secondary officers) ───────────
  {
    path: '/NotificationsCenter',
    name: 'NotificationsCenter',
    component: () => import('@/views/NotificationsCenter.vue'),
  },

  // ── Secondary Screening Records ────────────────────────────────────────────
  // ⚑ MUST be declared BEFORE /secondary-screening/:notificationId
  //   Vue Router reads top-to-bottom — the string "records" matches the
  //   :notificationId wildcard if the wildcard comes first → blank page.
  // 2026-05-06 — paired swap with /primary-screening/records above. This
  // route now renders PrimaryScreeningRecords.vue's content. Route NAME
  // 'SecondaryRecords' stays so RBAC allow-list keeps working.
  {
    path: '/secondary-screening/records',
    name: 'SecondaryRecords',
    component: () => import('@/views/PrimaryScreeningRecords.vue'),
  },

  // ── Secondary Screening case view (opened from NotificationsCenter) ─────────
  {
    path: '/secondary-screening/:notificationId',
    name: 'SecondaryScreening',
    component: () => import('@/views/SecondaryScreening.vue'),
  },

  // ── Active Alerts (DISTRICT_SUPERVISOR / PHEOC_OFFICER / NATIONAL_ADMIN) ───
  // ⚑ Specific paths declared BEFORE /alerts/:id wildcards (none exist now,
  //   but keep ordering law for future expansion)
  {
    path: '/alerts/history',
    redirect: '/alerts',
  },
  // /alerts/intelligence — disconnected from the mobile app (2026-05-17).
  // Route + sidebar entry + entry buttons removed. Deep links fall through
  // to the 404 catch-all and redirect to /home. AlertIntelligence.vue stays
  // on disk for now but is unreachable from the router.
  {
    path: '/alerts/matrix',
    name: 'AlertMatrix',
    component: () => import('@/views/AlertMatrix.vue'),
  },
  {
    path: '/alerts',
    name: 'ActiveAlerts',
    component: () => import('@/views/ActiveAlerts.vue'),
  },

  // ── Alert War Room (single-page lifecycle hub: case file, advisor,
  // followups + blocker resolve, timeline, evidence, comments, notifications,
  // outcome, breach RCA, close + reopen + reassign + escalate). RBAC gated
  // by ROUTE_PERMS['AlertWarRoom'] = 'alerts.warroom'; server re-enforces
  // PheocScope + AlertOpsAccess on every read & write.
  {
    path: '/alerts/:id(\\d+)/war-room',
    name: 'AlertWarRoom',
    component: () => import('@/views/AlertWarRoom.vue'),
  },

  // ── My Cases — quick triage queue (one-tap acknowledge / close / open).
  {
    path: '/my-cases',
    name: 'MyCases',
    component: () => import('@/views/MyCases.vue'),
  },

  // ── Admin: aggregated template + POE contacts (NATIONAL_ADMIN / POE_ADMIN) ──
  {
    path: '/admin/aggregated-templates',
    name: 'AggregatedTemplateAdmin',
    component: () => import('@/views/AggregatedTemplateAdmin.vue'),
  },
  {
    path: '/admin/aggregated-wizard',
    name: 'AggregatedWizard',
    component: () => import('@/views/AggregatedWizard.vue'),
  },
  {
    path: '/admin/poe-contacts',
    name: 'PoeContactsAdmin',
    component: () => import('@/views/PoeContactsAdmin.vue'),
  },

  // ── Aggregated Data (POE users + supervisors) ─────────────────────────────
  // Landing hub at /aggregated-data lists every PUBLISHED template available.
  // /aggregated-data/new/:templateId opens the dynamic submission wizard
  // for that specific template. History keeps its own route.
  {
    path: '/aggregated-data',
    name: 'AggregatedHub',
    component: () => import('@/views/AggregatedHub.vue'),
  },
  {
    path: '/aggregated-data/history',
    name: 'AggregatedHistory',
    component: () => import('@/views/AggregatedHistory.vue'),
  },
  {
    path: '/aggregated-data/new/:templateId',
    name: 'AggregatedDataNew',
    component: () => import('@/views/AggregatedData.vue'),
  },
  // Back-compat: /aggregated-data/new (no id) + /aggregated both route to hub.
  { path: '/aggregated-data/new', redirect: '/aggregated-data' },
  { path: '/aggregated', redirect: '/aggregated-data' },

  // ── Sync Management (offline queue status + manual push) ──────────────────
  // SyncManagement handles all sync tabs internally
  {
    path: '/sync/queue',
    redirect: '/sync',
  },
  {
    path: '/sync/history',
    redirect: '/sync',
  },
  {
    path: '/sync/failed',
    redirect: '/sync',
  },
  {
    path: '/sync',
    name: 'SyncManagement',
    component: () => import('@/views/SyncManagement.vue'),
  },

  // ── System admin route — redirects to settings for now ────────────────────
  {
    path: '/admin/system',
    redirect: '/settings',
  },

  // ── POE Management ─────────────────────────────────────────────────────────
  {
    path: '/POEs',
    name: 'POEs',
    component: POEs,
  },

  // ── User Management ────────────────────────────────────────────────────────
  {
    path: '/Users',
    name: 'Users',
    component: () => import('../views/UsersList.vue'),
  },

  // ── My Profile ─────────────────────────────────────────────────────────────
  {
    path: '/profile',
    name: 'MyProfile',
    component: () => import('@/views/MyProfile.vue'),
  },

  // ── App Settings ───────────────────────────────────────────────────────────
  {
    path: '/settings',
    name: 'AppSettings',
    component: () => import('@/views/AppSettings.vue'),
  },

  // ── Sentinel Model Manager (per-model download & status) ──────────────────
  {
    path: '/settings/sentinel-models',
    name: 'SentinelModels',
    component: () => import('@/views/ModelManagerView.vue'),
    // Permission is enforced via ROUTE_PERMS in rbac.js (key: 'settings' —
    // EVERYONE, same as the parent AppSettings view). `meta.permission` is
    // informational only; the guard reads ROUTE_PERMS.
    meta: { requiresAuth: true },
  },

  // ── Plugin diagnostics (Settings → Diagnostics) ───────────────────────────
  // Runtime self-test of every Capacitor plugin wrapper. Surfaces module-load,
  // gate, permission, and platform failures with developer-grade detail.
  {
    path: '/settings/diagnostics',
    name: 'PluginDiagnostics',
    component: () => import('@/views/PluginDiagnostics.vue'),
    meta: { requiresAuth: true },
  },

  // ── Staff Directory (client-only view over GET /users) ────────────────────
  {
    path: '/directory',
    name: 'Directory',
    component: () => import('@/views/Directory.vue'),
  },

  // ── Capabilities & Help (in-app docs + feature demos) ─────────────────────
  {
    path: '/capabilities-help',
    name: 'CapabilitiesHelp',
    component: () => import('@/views/CapabilitiesHelp.vue'),
  },

  // ── 404 fallback ───────────────────────────────────────────────────────────
  {
    path: '/:pathMatch(.*)*',
    redirect: '/home',
  },

]

const router = createRouter({
  // Hash history is the Ionic+Capacitor-safe mode: survives WebView reload
  // and file:// fallbacks without white-screening.
  history: createWebHashHistory(import.meta.env.BASE_URL),
  routes,
})

/**
 * RBAC route guard — redirects silently to /home when a user lacks the
 * permission required for the target route.
 *
 * Design rules (match src/services/rbac.js):
 *   - No auth session → allow through; App.vue's login modal will take over.
 *   - No permission registered for the route → allow (fail-open for unknown
 *     routes is intentional so we don't break future routes by omission).
 *   - NATIONAL_ADMIN jailbreak is handled inside rbacCan() — super-user.
 *   - Redirect, don't show a 403 screen — non-admins shouldn't even know
 *     admin routes exist.
 *
 * Side-effect: fires a window event so App.vue can show an optional toast.
 */
// Routes the SCREENER role may access. Derived from the PERMS matrix:
//   screening.capture   → PrimaryScreening
//   secondary.queue     → NotificationsCenter
//   secondary.records   → SecondaryRecords, SecondaryScreening
//   alerts.active       → ActiveAlerts
//   alerts.matrix       → AlertMatrix
//   Universal perms     → Home, Welcome, SyncManagement, Directory,
//                         CapabilitiesHelp, AppSettings, SentinelModels,
//                         PluginDiagnostics, MyProfile
const SCREENER_ALLOWED = new Set([
  // Core operational — primary and secondary screening flows
  'PrimaryScreening',
  'PrimaryScreeningRecords',
  'NotificationsCenter',
  'SecondaryRecords',
  'SecondaryScreening',
  // Analytics + reports — all roles can view, server scopes the data
  // automatically via user_id (role-based scope: POE → DISTRICT →
  // PHEOC → NATIONAL). Added 2026-05-07 per mandate "all roles should
  // access primary + secondary screening dashboards, scoped to RBAC".
  'ScreeningDashboard',
  'PrimaryScreeningReport',
  'SecondaryScreeningReport',
  // Alerts (read-only; action buttons hidden in the view itself)
  'ActiveAlerts',
  'AlertMatrix',
  // Universal — every authenticated role
  'Home',
  'Welcome',
  'SyncManagement',
  'Directory',
  'CapabilitiesHelp',
  'AppSettings',
  'SentinelModels',
  'PluginDiagnostics',
  'MyProfile',
])

router.beforeEach((to, _from, next) => {
  try {
    const raw = sessionStorage.getItem('AUTH_DATA')
    const authData = raw ? JSON.parse(raw) : null
    // Unauthenticated — App.vue gates the UI; let the router continue.
    if (!authData || !authData.id) return next()

    // SCREENER lock-down: restrict to the curated allow-list above.
    // Admin, aggregated-data, and high-level alert routes stay blocked.
    if (authData.role_key === 'SCREENER' && to.name && !SCREENER_ALLOWED.has(to.name)) {
      return next({ name: 'Home' })
    }

    const perm = permForRoute(to.name)
    if (!perm) return next() // route has no guard registered

    if (rbacCan(perm, authData)) return next()

    // Blocked — silent redirect to home. Fire event for optional toast.
    try { window.dispatchEvent(new CustomEvent('rbac-denied', { detail: { route: to.name, perm } })) } catch {}
    if (to.name === 'Home') return next() // avoid redirect loop
    return next({ name: 'Home' })
  } catch (_err) {
    // Defensive: never break navigation because of an RBAC read failure.
    return next()
  }
})

export default router