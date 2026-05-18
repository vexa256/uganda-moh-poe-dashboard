/**
 * tests/unit/rbac.test.cjs — exhaustive role-matrix assertions for
 * src/services/rbac.js.
 *
 * Run:   node tests/unit/rbac.test.cjs
 * Exits non-zero on any failure.
 *
 * The file is a pure Node script using CommonJS + a simple assert harness
 * so it has zero runtime dependencies. Mirrors the permission matrix in
 * docs/RBAC_SCOPE_PLAN.md §3.
 */

'use strict'

// ── Load rbac.js via a minimal ESM bridge ───────────────────────────────────
// rbac.js is ESM (uses export). We copy its runtime into a temp CJS module
// so the test can `require` it without transpiling the whole repo.
const fs = require('fs')
const path = require('path')
const vm = require('vm')

function loadRbac() {
  const src = fs.readFileSync(path.join(__dirname, '..', '..', 'src', 'services', 'rbac.js'), 'utf8')
  // Replace ESM syntax with CJS so Node can eval it directly.
  const cjs = src
    .replace(/export const /g, 'const ')
    .replace(/export function /g, 'function ')
  const mod = { exports: {} }
  const ctx = { module: mod, exports: mod.exports, console }
  vm.createContext(ctx)
  vm.runInContext(cjs + '\n;module.exports={ROLE, ROLE_TIER, PERMS, can, canAny, isAdmin, isSupervisor, isPoeStaff, scopeOf, ROUTE_PERMS, permForRoute};', ctx)
  return ctx.module.exports
}
const rbac = loadRbac()

// ── Tiny assert harness ────────────────────────────────────────────────────
const results = { pass: 0, fail: 0, errors: [] }
function t(name, fn) {
  try { fn(); results.pass++; process.stdout.write('.') }
  catch (err) { results.fail++; results.errors.push({ name, err: err.message || String(err) }); process.stdout.write('F') }
}
function assert(cond, msg = 'assertion failed') { if (!cond) throw new Error(msg) }
function eq(a, b, msg = 'values differ') { if (a !== b) throw new Error(`${msg}: expected ${JSON.stringify(b)} got ${JSON.stringify(a)}`) }

// ── Fixtures ────────────────────────────────────────────────────────────────
const NA = { role_key: 'NATIONAL_ADMIN',      country_code: 'ZM' }
const PH = { role_key: 'PHEOC_OFFICER',       pheoc_code: 'ZM-LSK-P' }
const DS = { role_key: 'DISTRICT_SUPERVISOR', district_code: 'ZM-LSK-D' }
const PA = { role_key: 'POE_ADMIN',           poe_code: 'ZM-KK-AIR' }
const PP = { role_key: 'POE_PRIMARY',         poe_code: 'ZM-KK-AIR' }
const PS = { role_key: 'POE_SECONDARY',       poe_code: 'ZM-KK-AIR' }
const PD = { role_key: 'POE_DATA_OFFICER',    poe_code: 'ZM-KK-AIR' }
const SC = { role_key: 'SCREENER',            poe_code: 'ZM-KK-AIR' }
const NONE = null
const UNK  = { role_key: 'GHOST_ROLE' }

// ── L1 · NATIONAL_ADMIN jailbreak ──────────────────────────────────────────
t('NATIONAL_ADMIN can do everything (jailbreak)', () => {
  // Every permission in the matrix + a few unknown keys must return true.
  const keys = Object.keys(rbac.PERMS).concat(['nonexistent-perm', 'ghost.key'])
  for (const k of keys) {
    assert(rbac.can(k, NA), `jailbreak failed for ${k}`)
  }
})

// ── L2 · Unknown perm denies for non-admins ────────────────────────────────
t('Unknown perm denies for every non-admin', () => {
  for (const u of [PH, DS, PA, PP, PS, PD, SC]) {
    eq(rbac.can('totally-fake-perm', u), false, `${u.role_key} got unknown perm`)
  }
})

// ── L3 · No auth denies everything ─────────────────────────────────────────
t('Null authData denies all perms', () => {
  for (const k of Object.keys(rbac.PERMS)) {
    eq(rbac.can(k, NONE), false, `null auth got ${k}`)
  }
})

// ── L3 · Unknown role denies everything ────────────────────────────────────
t('Unknown role denies all perms', () => {
  for (const k of Object.keys(rbac.PERMS)) {
    eq(rbac.can(k, UNK), false, `unknown role got ${k}`)
  }
})

// ── Core admin items hidden from non-admins ────────────────────────────────
t('Core admin items denied to every non-admin', () => {
  const adminOnly = ['admin.poes', 'admin.diseases', 'admin.aggregated.wizard', 'admin.aggregated.templates', 'admin.system']
  for (const p of adminOnly) {
    for (const u of [PH, DS, PA, PP, PS, PD, SC]) {
      eq(rbac.can(p, u), false, `${u.role_key} got admin perm ${p}`)
    }
  }
})

// ── Universal permissions visible to everyone authenticated ────────────────
t('Universal perms granted to every authenticated role', () => {
  const universal = ['dashboard', 'sync', 'directory', 'capabilities-help', 'settings', 'profile', 'alerts.matrix', 'screening.records']
  for (const p of universal) {
    for (const u of [NA, PH, DS, PA, PP, PS, PD, SC]) {
      assert(rbac.can(p, u), `${u.role_key} missing universal perm ${p}`)
    }
  }
})

// ── Screening capture — only capture roles ─────────────────────────────────
t('Primary screening capture restricted to POE_PRIMARY + SCREENER + POE_ADMIN + NA', () => {
  assert(rbac.can('screening.capture', PP), 'POE_PRIMARY should capture')
  assert(rbac.can('screening.capture', SC), 'SCREENER should capture')
  assert(rbac.can('screening.capture', PA), 'POE_ADMIN should capture')
  assert(rbac.can('screening.capture', NA), 'NA should capture')
  eq(rbac.can('screening.capture', PS), false, 'POE_SECONDARY must NOT capture')
  eq(rbac.can('screening.capture', PD), false, 'POE_DATA_OFFICER must NOT capture')
  eq(rbac.can('screening.capture', DS), false, 'DS must NOT capture')
  eq(rbac.can('screening.capture', PH), false, 'PH must NOT capture')
})

// ── Secondary queue — frontline screeners now permitted ────────────────────
// rbac.js:79 widened to include POE_PRIMARY + SCREENER per the comment:
// "Frontline screeners need full visibility into secondary screening to
//  follow up on cases they referred. The API already permits SCREENER on
//  these endpoints — the mobile gate was the only blocker."
// POE_DATA_OFFICER (PD) remains excluded — read-only data role, not clinical.
t('Secondary queue restricted correctly', () => {
  for (const u of [PP, PS, SC, PA, DS, PH, NA]) assert(rbac.can('secondary.queue', u), `${u.role_key} should see secondary queue`)
  eq(rbac.can('secondary.queue', PD), false, `${PD.role_key} must NOT see secondary queue`)
})

// ── Alerts active ─────────────────────────────────────────────────────────
t('Active alerts visible to secondary + supervisors + NA', () => {
  for (const u of [PS, PA, DS, PH, NA]) assert(rbac.can('alerts.active', u), `${u.role_key} should see alerts.active`)
  for (const u of [PP, PD, SC])          eq(rbac.can('alerts.active', u), false, `${u.role_key} must NOT see alerts.active`)
})

// ── Alert intelligence + history (supervisors + NA only) ───────────────────
t('Alert intelligence/history restricted to supervisors + NA', () => {
  for (const p of ['alerts.intelligence', 'alerts.history']) {
    for (const u of [PA, DS, PH, NA]) assert(rbac.can(p, u), `${u.role_key} should see ${p}`)
    for (const u of [PP, PS, PD, SC]) eq(rbac.can(p, u), false, `${u.role_key} must NOT see ${p}`)
  }
})

// ── Aggregated reports — data officer + supervisors + NA ───────────────────
t('Aggregated reports gate correct', () => {
  for (const u of [PD, PA, DS, PH, NA]) assert(rbac.can('aggregated.reports', u), `${u.role_key} should see aggregated.reports`)
  for (const u of [PP, PS, SC])          eq(rbac.can('aggregated.reports', u), false, `${u.role_key} must NOT see aggregated.reports`)
})

// ── Template admin is NA-only (POE_ADMIN doesn't get it) ───────────────────
t('Aggregated template admin is NATIONAL_ADMIN only', () => {
  for (const u of [PH, DS, PA, PP, PS, PD, SC]) {
    eq(rbac.can('admin.aggregated.wizard', u), false, `${u.role_key} must NOT see wizard`)
    eq(rbac.can('admin.aggregated.templates', u), false, `${u.role_key} must NOT see templates`)
  }
  assert(rbac.can('admin.aggregated.wizard', NA), 'NA should see wizard')
})

// ── User management is delegated (POE_ADMIN / DS / PH / NA) ────────────────
t('User management delegated correctly', () => {
  for (const u of [PA, DS, PH, NA]) assert(rbac.can('admin.users', u), `${u.role_key} should manage users`)
  for (const u of [PP, PS, PD, SC]) eq(rbac.can('admin.users', u), false, `${u.role_key} must NOT manage users`)
})

// ── POE contacts — POE_ADMIN + NA ─────────────────────────────────────────
t('POE contacts admin restricted to POE_ADMIN + NA', () => {
  assert(rbac.can('admin.poe-contacts', PA), 'POE_ADMIN should manage contacts')
  assert(rbac.can('admin.poe-contacts', NA), 'NA should manage contacts')
  for (const u of [PP, PS, PD, SC, DS, PH]) {
    eq(rbac.can('admin.poe-contacts', u), false, `${u.role_key} must NOT manage contacts`)
  }
})

// ── isAdmin / isSupervisor / isPoeStaff ────────────────────────────────────
t('isAdmin true only for NATIONAL_ADMIN', () => {
  assert(rbac.isAdmin(NA), 'NA should be admin')
  for (const u of [PH, DS, PA, PP, PS, PD, SC, UNK, NONE]) eq(rbac.isAdmin(u), false, `${u && u.role_key} must NOT be admin`)
})

t('isSupervisor true for PA/DS/PH/NA', () => {
  for (const u of [PA, DS, PH, NA]) assert(rbac.isSupervisor(u), `${u.role_key} should be supervisor`)
  for (const u of [PP, PS, PD, SC, NONE, UNK]) eq(rbac.isSupervisor(u), false, `${u && u.role_key} must NOT be supervisor`)
})

t('isPoeStaff true for POE_ADMIN + PP/PS/PD/SC', () => {
  for (const u of [PA, PP, PS, PD, SC]) assert(rbac.isPoeStaff(u), `${u.role_key} should be POE staff`)
  for (const u of [DS, PH, NA, NONE, UNK]) eq(rbac.isPoeStaff(u), false, `${u && u.role_key} must NOT be POE staff`)
})

// ── scopeOf returns correct geographic envelope ───────────────────────────
t('scopeOf NATIONAL_ADMIN = NATIONAL', () => {
  const s = rbac.scopeOf(NA); eq(s.level, 'NATIONAL'); eq(s.code, 'ZM')
})
t('scopeOf PHEOC_OFFICER = PHEOC', () => {
  const s = rbac.scopeOf(PH); eq(s.level, 'PHEOC'); eq(s.code, 'ZM-LSK-P')
})
t('scopeOf DISTRICT_SUPERVISOR = DISTRICT', () => {
  const s = rbac.scopeOf(DS); eq(s.level, 'DISTRICT'); eq(s.code, 'ZM-LSK-D')
})
t('scopeOf all POE roles = POE', () => {
  for (const u of [PA, PP, PS, PD, SC]) { const s = rbac.scopeOf(u); eq(s.level, 'POE'); eq(s.code, 'ZM-KK-AIR') }
})
t('scopeOf null = NONE', () => {
  const s = rbac.scopeOf(NONE); eq(s.level, 'NONE')
})

// ── permForRoute ───────────────────────────────────────────────────────────
t('permForRoute returns correct keys', () => {
  eq(rbac.permForRoute('POEs'),               'admin.poes')
  eq(rbac.permForRoute('Users'),              'admin.users')
  eq(rbac.permForRoute('AggregatedWizard'),   'admin.aggregated.wizard')
  eq(rbac.permForRoute('PrimaryScreening'),   'screening.capture')
  eq(rbac.permForRoute('AlertMatrix'),        'alerts.matrix')
  eq(rbac.permForRoute('Home'),               'dashboard')
  eq(rbac.permForRoute('Directory'),          'directory')
  eq(rbac.permForRoute('does-not-exist'),     null)
})

// ── canAny semantics ───────────────────────────────────────────────────────
t('canAny returns true if any listed perm granted', () => {
  eq(rbac.canAny(['admin.poes', 'dashboard'], PP), true)  // dashboard grants
  eq(rbac.canAny(['admin.poes', 'admin.system'], PP), false)
  eq(rbac.canAny([], NA), false)
  eq(rbac.canAny(null, NA), false)
  assert(rbac.canAny(['admin.system'], NA), 'NA jailbreaks canAny')
})

// ── No DEV stragglers: matrix has every route declared ─────────────────────
t('Every route in ROUTE_PERMS maps to a known perm or universal', () => {
  const universal = new Set()
  for (const [k, v] of Object.entries(rbac.PERMS)) {
    if (Array.isArray(v) && v.includes('*')) universal.add(k)
  }
  for (const [route, perm] of Object.entries(rbac.ROUTE_PERMS)) {
    assert(perm in rbac.PERMS, `Route ${route} maps to unknown perm ${perm}`)
  }
})

// ── Print results ──────────────────────────────────────────────────────────
process.stdout.write('\n')
console.log(`${results.pass} passed, ${results.fail} failed`)
if (results.fail > 0) {
  for (const e of results.errors) console.error(`  ✗ ${e.name}: ${e.err}`)
  process.exit(1)
}
process.exit(0)
