/**
 * services/rbac.js — Single Source of Truth for role-based access control.
 *
 * Every menu-visibility, route-guard, and in-view permission check in the
 * mobile app routes through `can(perm, authData)`. This module is also
 * pure-JS so it's unit-testable without Vue.
 *
 * Design laws:
 *   L1 · NATIONAL_ADMIN has the jailbreak — every check short-circuits to
 *        `true`. No matrix lookup. This role is the super-user; the only
 *        thing that matters for them is the server-side scope, which is
 *        already "everything country-wide".
 *
 *   L2 · Unknown permission keys DENY by default. A typo in a `can()` call
 *        fails closed.
 *
 *   L3 · Unknown roles DENY by default. A stale session or DB migration
 *        that introduces a role before the mobile app is updated fails
 *        closed (user only sees universal items).
 *
 *   L4 · The matrix is the truth. Menu, router guard, per-view checks all
 *        read from the same PERMS map. If you need a new permission, add
 *        it here first.
 *
 *   L5 · "Universal" permissions (Dashboard, Sync, Directory, etc.) use
 *        the `'*'` role marker. Any authenticated user passes.
 *
 * Reference: docs/RBAC_SCOPE_PLAN.md §3 (the authoritative matrix).
 */

export const ROLE = Object.freeze({
  NATIONAL_ADMIN:      'NATIONAL_ADMIN',
  PHEOC_OFFICER:       'PHEOC_OFFICER',
  DISTRICT_SUPERVISOR: 'DISTRICT_SUPERVISOR',
  POE_ADMIN:           'POE_ADMIN',
  POE_PRIMARY:         'POE_PRIMARY',
  POE_SECONDARY:       'POE_SECONDARY',
  POE_DATA_OFFICER:    'POE_DATA_OFFICER',
  SCREENER:            'SCREENER',
})

export const ROLE_TIER = Object.freeze({
  [ROLE.NATIONAL_ADMIN]:      5,
  [ROLE.PHEOC_OFFICER]:       4,
  [ROLE.DISTRICT_SUPERVISOR]: 3,
  [ROLE.POE_ADMIN]:           2,
  [ROLE.POE_PRIMARY]:         1,
  [ROLE.POE_SECONDARY]:       1,
  [ROLE.POE_DATA_OFFICER]:    1,
  [ROLE.SCREENER]:            1,
})

const ALL_POE_STAFF  = [ROLE.POE_ADMIN, ROLE.POE_PRIMARY, ROLE.POE_SECONDARY, ROLE.POE_DATA_OFFICER, ROLE.SCREENER]
const ALL_SCREENERS  = [ROLE.POE_PRIMARY, ROLE.POE_SECONDARY, ROLE.SCREENER, ROLE.POE_ADMIN]   // every screener-class role
const ALL_DATA_ROLES = [ROLE.POE_DATA_OFFICER, ROLE.POE_ADMIN, ROLE.POE_PRIMARY, ROLE.POE_SECONDARY, ROLE.SCREENER]
const SUPERVISORS    = [ROLE.POE_ADMIN, ROLE.DISTRICT_SUPERVISOR, ROLE.PHEOC_OFFICER]
const EVERYONE       = ['*']

/**
 * Permission matrix — role list per permission key.
 * Use "*" to mean every authenticated role.
 * NATIONAL_ADMIN is IMPLICIT — it never needs to appear; L1 grants it everything.
 */
export const PERMS = Object.freeze({
  // ── Universal (primary core views per R4) ──────────────────────────
  'dashboard':              EVERYONE,
  'sync':                   EVERYONE,
  'directory':              EVERYONE,
  'capabilities-help':      EVERYONE,
  'settings':               EVERYONE,
  'profile':                EVERYONE,
  'alerts.matrix':          EVERYONE,  // WHO reference — no PII

  // ── Screening (core operational flows) ─────────────────────────────
  // Per directive: ALL screener-class roles AND all data roles can perform
  // every screening flow without exception. Geographic scope is still
  // enforced at the data layer.
  'screening.capture':      [...ALL_SCREENERS, ROLE.POE_DATA_OFFICER, ...SUPERVISORS],
  'screening.records':      EVERYONE,   // scoped by server; everyone reads within their scope
  // 2026-05-07: dashboards open to EVERY role. Server enforces geographic
  // scope (POE → DISTRICT → PHEOC → NATIONAL) via the user_id query
  // param on every endpoint. The view shows the user a prominent banner
  // labelling exactly which scope they are looking at.
  'screening.intelligence': EVERYONE,
  'secondary.queue':        [...ALL_SCREENERS, ROLE.POE_DATA_OFFICER, ...SUPERVISORS],
  'secondary.records':      EVERYONE,

  // ── Alerts ─────────────────────────────────────────────────────────
  // Active alerts list is read-only for POE staff (action buttons are gated
  // separately below). Supervisors (DISTRICT/PHEOC) and NATIONAL see all the
  // alerts they need; the view itself filters to their geographic scope.
  'alerts.active':          [...ALL_POE_STAFF, ...SUPERVISORS],
  'alerts.intelligence':    SUPERVISORS,   // dashboards/analytics — supervisor tier and above
  'alerts.history':         SUPERVISORS,

  // ── Alerts (full lifecycle / war-room actions) ─────────────────────
  // Action-on-target (level ladder) is layered on top via canForTarget(perm, target).
  'alerts.acknowledge':     [ROLE.DISTRICT_SUPERVISOR, ROLE.PHEOC_OFFICER],
  'alerts.escalate':        [ROLE.DISTRICT_SUPERVISOR, ROLE.PHEOC_OFFICER],
  'alerts.close':           [ROLE.DISTRICT_SUPERVISOR, ROLE.PHEOC_OFFICER],
  'alerts.reopen':          [ROLE.PHEOC_OFFICER],
  'alerts.comment':         [ROLE.POE_SECONDARY, ROLE.POE_DATA_OFFICER, ...SUPERVISORS],
  'alerts.evidence.upload': [ROLE.POE_SECONDARY, ROLE.POE_DATA_OFFICER, ...SUPERVISORS],
  'alerts.followup.complete': [ROLE.POE_SECONDARY, ROLE.POE_DATA_OFFICER, ...SUPERVISORS],

  // Full mobile lifecycle (war room) — defence-in-depth UX gating. Server
  // re-enforces every action with PheocScope + AlertOpsAccess.
  'alerts.warroom':            [ROLE.POE_SECONDARY, ROLE.POE_DATA_OFFICER, ...SUPERVISORS],
  'alerts.mycases':            [ROLE.POE_SECONDARY, ROLE.POE_DATA_OFFICER, ...SUPERVISORS],
  'alerts.reassign':           [ROLE.DISTRICT_SUPERVISOR, ROLE.PHEOC_OFFICER],
  'alerts.outcome.record':     [ROLE.DISTRICT_SUPERVISOR, ROLE.PHEOC_OFFICER],
  'alerts.blocker.resolve':    [ROLE.DISTRICT_SUPERVISOR, ROLE.PHEOC_OFFICER, ROLE.POE_SECONDARY, ROLE.POE_DATA_OFFICER],
  'alerts.breach.log':         [ROLE.DISTRICT_SUPERVISOR, ROLE.PHEOC_OFFICER],
  // NATIONAL_ADMIN-only — empty array means jailbreak only (per the L1 rule).
  'alerts.close.override':     [],
  'alerts.pheic.declare':      [],

  // ── Aggregated / reporting ─────────────────────────────────────────
  'aggregated.reports':     [ROLE.POE_DATA_OFFICER, ...SUPERVISORS],
  'aggregated.history':     [ROLE.POE_DATA_OFFICER, ...SUPERVISORS],

  // ── Core administration (R3 — hidden entirely from non-admins) ─────
  'admin.poes':             [],   // NATIONAL_ADMIN-only — empty array means "nobody but jailbreak"
  'admin.diseases':         [],
  'admin.aggregated.wizard':    [],
  'admin.aggregated.templates': [],
  'admin.system':           [],

  // ── Scoped administration (admins + delegated admins) ──────────────
  'admin.users':            [ROLE.POE_ADMIN, ROLE.DISTRICT_SUPERVISOR, ROLE.PHEOC_OFFICER],
  'admin.poe-contacts':     [ROLE.POE_ADMIN, ROLE.DISTRICT_SUPERVISOR],  // NATIONAL_ADMIN via L1 jailbreak
})

const KNOWN_ROLES = new Set(Object.values(ROLE))

/**
 * Central gate.
 * @param {string} perm      permission key from PERMS
 * @param {object} authData  the session-restored auth payload
 * @returns {boolean}
 */
export function can(perm, authData) {
  const role = authData && authData.role_key
  if (!role) return false

  // L1 · NATIONAL_ADMIN jailbreak — everything passes.
  if (role === ROLE.NATIONAL_ADMIN) return true

  // L3 · Unknown role → deny, including "universal" items.
  //      A role we don't recognise could be a stale session or a DB drift;
  //      fail closed until the client is updated with that role.
  if (!KNOWN_ROLES.has(role)) return false

  // L2 · Unknown perm → deny.
  const allowed = PERMS[perm]
  if (!Array.isArray(allowed)) return false

  // L5 · Universal marker — any KNOWN authenticated role passes.
  if (allowed.includes('*')) return true

  return allowed.includes(role)
}

/**
 * Convenience — does this user have ANY of the listed perms?
 */
export function canAny(perms, authData) {
  if (!Array.isArray(perms)) return false
  return perms.some(p => can(p, authData))
}

/**
 * Is this user a national admin (the jailbreak)?
 */
export function isAdmin(authData) {
  return !!(authData && authData.role_key === ROLE.NATIONAL_ADMIN)
}

/**
 * Is this user at supervisor tier (POE_ADMIN+)?
 */
export function isSupervisor(authData) {
  const t = authData && ROLE_TIER[authData.role_key]
  return typeof t === 'number' && t >= ROLE_TIER[ROLE.POE_ADMIN]
}

/**
 * Is this user a POE-level operator (POE_PRIMARY / POE_SECONDARY / SCREENER / POE_DATA_OFFICER)?
 */
export function isPoeStaff(authData) {
  return !!(authData && ALL_POE_STAFF.includes(authData.role_key))
}

/**
 * Resolve the effective geographic scope of an authData payload.
 * Returns { level, code, label } — useful for UI badges + client-side filtering.
 */
export function scopeOf(authData) {
  if (!authData || !authData.role_key) return { level: 'NONE', code: null, label: 'No scope' }
  switch (authData.role_key) {
    case ROLE.NATIONAL_ADMIN:
      return { level: 'NATIONAL', code: authData.country_code || null, label: 'National' }
    case ROLE.PHEOC_OFFICER:
      return { level: 'PHEOC', code: authData.pheoc_code || authData.province_code || null, label: 'PHEOC / Province' }
    case ROLE.DISTRICT_SUPERVISOR:
      return { level: 'DISTRICT', code: authData.district_code || null, label: 'District' }
    case ROLE.POE_ADMIN:
    case ROLE.POE_PRIMARY:
    case ROLE.POE_SECONDARY:
    case ROLE.POE_DATA_OFFICER:
    case ROLE.SCREENER:
      return { level: 'POE', code: authData.poe_code || null, label: 'POE' }
    default:
      return { level: 'UNKNOWN', code: null, label: authData.role_key }
  }
}

/**
 * Route → permission map. Used by the router guard.
 * Values are either a permission key string, '*' (universal), or null (no guard).
 */
export const ROUTE_PERMS = Object.freeze({
  'Home':                     'dashboard',
  'Welcome':                  'dashboard',
  'PrimaryScreening':         'screening.capture',
  'PrimaryScreeningRecords':  'screening.records',
  'ScreeningDashboard':       'screening.intelligence',
  'NotificationsCenter':      'secondary.queue',
  'SecondaryRecords':         'secondary.records',
  'SecondaryScreening':       'secondary.records',
  'ActiveAlerts':             'alerts.active',
  'AlertIntelligence':        'alerts.intelligence',
  'AlertHistory':             'alerts.history',
  'AlertMatrix':              'alerts.matrix',
  'AlertWarRoom':             'alerts.warroom',
  'MyCases':                  'alerts.mycases',
  'AggregatedHub':            'aggregated.reports',
  'AggregatedHistory':        'aggregated.history',
  'AggregatedDataNew':        'aggregated.reports',
  'AggregatedWizard':         'admin.aggregated.wizard',
  'AggregatedTemplateAdmin':  'admin.aggregated.templates',
  'PoeContactsAdmin':         'admin.poe-contacts',
  'POEs':                     'admin.poes',
  'Users':                    'admin.users',
  'DiseaseInteligence':       'admin.diseases',
  'SyncManagement':           'sync',
  'Directory':                'directory',
  'CapabilitiesHelp':         'capabilities-help',
  'AppSettings':              'settings',
  'SentinelModels':           'settings',
  'PluginDiagnostics':        'settings',
  'MyProfile':                'profile',
})

/**
 * Resolve the permission required for a route name. Returns null if the
 * route has no guard (leave such routes open — e.g. root redirects).
 */
export function permForRoute(routeName) {
  if (!routeName) return null
  return ROUTE_PERMS[routeName] || null
}

/**
 * canForTarget(perm, authData, target) — action-on-target ladder check.
 *
 * Layers a `routed_to_level`-aware ladder on top of `can()`. Used for alert
 * lifecycle actions where DISTRICT users must not act on PHEOC/NATIONAL
 * alerts. NATIONAL_ADMIN still jailbreaks.
 *
 * Ladder (target.routed_to_level → roles allowed):
 *   POE       → POE_ADMIN, DISTRICT_SUPERVISOR, PHEOC_OFFICER, NATIONAL_ADMIN
 *   DISTRICT  → DISTRICT_SUPERVISOR, PHEOC_OFFICER, NATIONAL_ADMIN
 *   PHEOC     → DISTRICT_SUPERVISOR, PHEOC_OFFICER, NATIONAL_ADMIN
 *   NATIONAL  → PHEOC_OFFICER, NATIONAL_ADMIN
 *
 * Note: DISTRICT_SUPERVISOR can close PHEOC-level alerts.
 * PHEOC_OFFICER can close NATIONAL-level alerts.
 * DISTRICT_SUPERVISOR CANNOT close NATIONAL-level alerts (not in NATIONAL array).
 *
 * If target has no routed_to_level, falls back to plain can(). Server still
 * enforces — this is defence-in-depth + UX (hide buttons the user can't use).
 */
const ROUTED_LEVEL_LADDER = Object.freeze({
  POE:      [ROLE.POE_ADMIN, ROLE.DISTRICT_SUPERVISOR, ROLE.PHEOC_OFFICER, ROLE.NATIONAL_ADMIN],
  DISTRICT: [ROLE.DISTRICT_SUPERVISOR, ROLE.PHEOC_OFFICER, ROLE.NATIONAL_ADMIN],
  PHEOC:    [ROLE.DISTRICT_SUPERVISOR, ROLE.PHEOC_OFFICER, ROLE.NATIONAL_ADMIN],
  NATIONAL: [ROLE.PHEOC_OFFICER, ROLE.NATIONAL_ADMIN],
})

export function canForTarget(perm, authData, target) {
  if (!can(perm, authData)) return false
  if (!target || !target.routed_to_level) return true
  if (authData.role_key === ROLE.NATIONAL_ADMIN) return true
  const allowed = ROUTED_LEVEL_LADDER[String(target.routed_to_level).toUpperCase()]
  if (!Array.isArray(allowed)) return true // unknown level → don't over-restrict
  return allowed.includes(authData.role_key)
}
