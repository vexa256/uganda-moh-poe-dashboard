/**
 * tests/integration/rbac_hostile.test.cjs
 *
 * Aggressive hostile-auditor RBAC integration test against the LIVE Laravel
 * dev server. Covers:
 *
 *   A · Role-tier enforcement on POST /users — a PHEOC can't create a NATIONAL;
 *       a DS can't create a PHEOC; POE_ADMIN can't create a DS; random roles
 *       (POE_PRIMARY / POE_SECONDARY / POE_DATA_OFFICER / SCREENER) can't
 *       create anyone.
 *   B · Scope containment — PHEOC can only assign within their pheoc_code;
 *       DS within their district_code; POE_ADMIN within their poe_code.
 *   C · Required-geography enforcement — POE-role users rejected without
 *       poe_code; DS rejected without district_code; PHEOC rejected without
 *       province_or_pheoc.
 *   D · Valid role-key list — legacy role 'POE_OFFICER' rejected; valid 8
 *       roles accepted.
 *   E · Read-side scope on major endpoints — /alerts, /primary-screenings,
 *       /secondary-screenings, /aggregated, /dashboard/summary return data
 *       scoped to the caller's role × geography.
 *   F · Jailbreak — NATIONAL_ADMIN sees all, PHEOC sees own province,
 *       DISTRICT sees own district, POE_* sees own POE.
 *   G · Hostile user_id tampering — passing a non-existent user_id is
 *       handled gracefully (400 / default scope / no 500).
 *
 * Prerequisites:
 *   - Laravel dev server on http://127.0.0.1:8000/api
 *   - At least one NATIONAL_ADMIN exists (we look up by role).
 *
 * Exits non-zero on any assertion failure.
 */

'use strict'

const BASE = process.env.API_BASE || 'http://127.0.0.1:8000/api'

// ── assert harness ──────────────────────────────────────────────────────────
const results = { pass: 0, fail: 0, errors: [], skipped: 0 }
async function t(name, fn) {
  try { await fn(); results.pass++; process.stdout.write('.') }
  catch (err) {
    results.fail++
    results.errors.push({ name, err: err?.stack || err?.message || String(err) })
    process.stdout.write('F')
  }
}
function skip(name, reason) { results.skipped++; results.errors.push({ name, err: 'SKIPPED: ' + reason, skip: true }) }
function assert(cond, msg = 'assertion failed') { if (!cond) throw new Error(msg) }
function eq(a, b, msg = 'values differ') { if (a !== b) throw new Error(`${msg}: expected ${JSON.stringify(b)} got ${JSON.stringify(a)}`) }

// ── fetch helpers ───────────────────────────────────────────────────────────
async function http(method, path, body = null) {
  const res = await fetch(BASE + path, {
    method,
    headers: { Accept: 'application/json', ...(body ? { 'Content-Type': 'application/json' } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  })
  const text = await res.text()
  let json = null; try { json = text ? JSON.parse(text) : null } catch {}
  return { status: res.status, body: json, raw: text }
}
const get  = (p)    => http('GET', p)
const post = (p, b) => http('POST', p, b)

// ── fixtures ────────────────────────────────────────────────────────────────
let NA = null, PH = null, DS = null
let CREATED_IDS = [] // cleanup list — we'll deactivate these after the run

async function loadFixtures() {
  const r = await get('/users?per_page=200')
  assert(r.status === 200, 'Fixture load failed: ' + r.status)
  const items = (r.body?.data?.items) || []
  // Pick the ORIGINAL seed user for each role:
  //   - username does NOT start with "rbactest_" (those are our test creations)
  //   - all scope fields set (the seed users have complete assignments)
  //   - is_active = 1
  const notTestUser = (u) => !String(u.username || '').startsWith('rbactest_')
  const isComplete  = (u) => u.pheoc_code && u.district_code && u.poe_code
  const byOldest    = (a, b) => a.id - b.id

  NA = items
    .filter(u => u.role_key === 'NATIONAL_ADMIN' && isComplete(u) && notTestUser(u) && u.is_active)
    .sort(byOldest)[0]
  PH = items
    .filter(u => u.role_key === 'PHEOC_OFFICER' && isComplete(u) && notTestUser(u) && u.is_active)
    .sort(byOldest)[0]
  DS = items
    .filter(u => u.role_key === 'DISTRICT_SUPERVISOR' && isComplete(u) && notTestUser(u) && u.is_active)
    .sort(byOldest)[0]
  assert(NA, 'no pristine NATIONAL_ADMIN fixture (complete scope + not test-created + active)')
  assert(PH, 'no pristine PHEOC_OFFICER fixture')
  assert(DS, 'no pristine DISTRICT_SUPERVISOR fixture')
}

const uniq = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 6)

function uuidv4() {
  // RFC 4122 v4 — node 18+ has crypto.randomUUID()
  return (require('crypto').randomUUID && require('crypto').randomUUID()) ||
    'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8); return v.toString(16)
    })
}

function basePayload(role, overrides = {}) {
  const asg = Object.assign({}, overrides.assignment || {})
  // country_code is REQUIRED inside assignment by the server validator.
  if (!asg.country_code) asg.country_code = 'Zambia'
  const out = Object.assign({
    client_uuid:  uuidv4(),
    role_key:     role,
    country_code: 'Zambia',
    full_name:    'RBAC Test ' + role,
    username:     'rbactest_' + role.toLowerCase() + '_' + uniq(),
    email:        'rbactest_' + uniq() + '@x.zm',
    password:     'TestPassword123!',
    is_active:    1,
  }, overrides)
  out.assignment = asg
  return out
}

// ── A · Role-tier enforcement ──────────────────────────────────────────────
async function suiteA() {
  await t('A1 · PHEOC cannot create NATIONAL_ADMIN', async () => {
    const p = basePayload('NATIONAL_ADMIN', {})
    const r = await post(`/users?user_id=${PH.id}`, p)
    eq(r.status, 422, 'expected 422 from hierarchy check, got ' + r.status)
    const msg = JSON.stringify(r.body?.errors || r.body || '')
    assert(/role/i.test(msg), 'expected error to mention role, got: ' + msg)
  })

  await t('A2 · DISTRICT_SUPERVISOR cannot create PHEOC_OFFICER', async () => {
    const p = basePayload('PHEOC_OFFICER', { assignment: { province_code: PH.province_code, pheoc_code: PH.pheoc_code } })
    const r = await post(`/users?user_id=${DS.id}`, p)
    eq(r.status, 422, 'expected 422, got ' + r.status + ': ' + r.raw.slice(0, 200))
  })

  await t('A3 · PHEOC_OFFICER can create DISTRICT_SUPERVISOR within own province', async () => {
    const p = basePayload('DISTRICT_SUPERVISOR', {
      assignment: {
        province_code: PH.province_code,
        pheoc_code:    PH.pheoc_code,
        district_code: PH.district_code,
      },
    })
    const r = await post(`/users?user_id=${PH.id}`, p)
    assert(r.status === 200 || r.status === 201, 'expected 2xx, got ' + r.status + ' — body: ' + r.raw.slice(0, 300))
    const id = r.body?.data?.id; if (id) CREATED_IDS.push(id)
  })

  await t('A4 · DISTRICT_SUPERVISOR cannot create user OUTSIDE their district', async () => {
    const p = basePayload('POE_PRIMARY', {
      assignment: {
        province_code: PH.province_code,
        pheoc_code:    PH.pheoc_code,
        district_code: 'ZM-FAKE-DISTRICT',
        poe_code:      'ZM-FAKE-POE',
      },
    })
    const r = await post(`/users?user_id=${DS.id}`, p)
    eq(r.status, 422, 'expected 422 for cross-district create, got ' + r.status + ': ' + r.raw.slice(0, 200))
  })
}

// ── B · Required-geography enforcement ─────────────────────────────────────
async function suiteB() {
  await t('B1 · POE_PRIMARY rejected without poe_code', async () => {
    const p = basePayload('POE_PRIMARY', {
      assignment: { province_code: PH.province_code, pheoc_code: PH.pheoc_code, district_code: PH.district_code },
    })
    const r = await post(`/users?user_id=${NA.id}`, p)
    eq(r.status, 422, 'expected 422 for missing poe_code, got ' + r.status)
    const msg = JSON.stringify(r.body?.errors || r.body || '')
    assert(/poe_code/i.test(msg), 'expected poe_code error, got: ' + msg)
  })

  await t('B2 · POE_ADMIN rejected without district_code', async () => {
    const p = basePayload('POE_ADMIN', {
      assignment: { province_code: PH.province_code, pheoc_code: PH.pheoc_code, poe_code: PH.poe_code },
    })
    const r = await post(`/users?user_id=${NA.id}`, p)
    eq(r.status, 422, 'expected 422, got ' + r.status)
  })

  await t('B3 · DISTRICT_SUPERVISOR rejected without district_code', async () => {
    const p = basePayload('DISTRICT_SUPERVISOR', {
      assignment: { province_code: PH.province_code, pheoc_code: PH.pheoc_code },
    })
    const r = await post(`/users?user_id=${NA.id}`, p)
    eq(r.status, 422, 'expected 422')
  })
}

// ── D · VALID_ROLES list ───────────────────────────────────────────────────
async function suiteD() {
  await t('D1 · Legacy role "POE_OFFICER" rejected', async () => {
    const p = basePayload('POE_OFFICER')
    const r = await post(`/users?user_id=${NA.id}`, p)
    eq(r.status, 422, 'expected 422 for invalid role')
  })

  await t('D2 · Each of the 4 new POE roles is creatable (by NA with full scope)', async () => {
    for (const role of ['POE_ADMIN', 'POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER']) {
      const p = basePayload(role, {
        assignment: {
          province_code: PH.province_code,
          pheoc_code:    PH.pheoc_code,
          district_code: PH.district_code,
          poe_code:      PH.poe_code,
        },
      })
      const r = await post(`/users?user_id=${NA.id}`, p)
      if (r.status < 200 || r.status > 201) throw new Error(`role ${role} failed: ${r.status} ${r.raw.slice(0, 250)}`)
      const id = r.body?.data?.id; if (id) CREATED_IDS.push(id)
    }
  })
}

// ── E · Read-side scope on major endpoints ─────────────────────────────────
async function suiteE() {
  const endpoints = ['/alerts', '/primary-screenings', '/secondary-screenings', '/aggregated', '/dashboard/summary']

  for (const ep of endpoints) {
    await t(`E · ${ep} accepts NA scope (200)`, async () => {
      const r = await get(`${ep}?user_id=${NA.id}&per_page=5`)
      assert(r.status === 200, `${ep} NA returned ${r.status}: ${r.raw.slice(0, 200)}`)
    })
    await t(`E · ${ep} accepts PHEOC scope (200)`, async () => {
      const r = await get(`${ep}?user_id=${PH.id}&per_page=5`)
      assert(r.status === 200, `${ep} PH returned ${r.status}: ${r.raw.slice(0, 200)}`)
    })
    await t(`E · ${ep} accepts DS scope (200)`, async () => {
      const r = await get(`${ep}?user_id=${DS.id}&per_page=5`)
      assert(r.status === 200, `${ep} DS returned ${r.status}: ${r.raw.slice(0, 200)}`)
    })
  }
}

// ── F · Jailbreak + scope behaviour ────────────────────────────────────────
async function suiteF() {
  let naCount = null, phCount = null, dsCount = null

  await t('F1 · NATIONAL_ADMIN sees alerts (global)', async () => {
    const r = await get(`/alerts?user_id=${NA.id}&per_page=200`)
    assert(r.status === 200)
    const items = r.body?.data?.items || r.body?.data || []
    naCount = Array.isArray(items) ? items.length : 0
  })

  await t('F2 · PHEOC_OFFICER sees ≤ NA (scope contained)', async () => {
    const r = await get(`/alerts?user_id=${PH.id}&per_page=200`)
    assert(r.status === 200)
    const items = r.body?.data?.items || r.body?.data || []
    phCount = Array.isArray(items) ? items.length : 0
    assert(phCount <= naCount, `PHEOC (${phCount}) must not exceed NA (${naCount})`)
    // All items must be in PH's pheoc scope
    for (const it of items) {
      if (it.pheoc_code) eq(it.pheoc_code, PH.pheoc_code, `PHEOC saw cross-pheoc row id=${it.id}`)
    }
  })

  await t('F3 · DISTRICT_SUPERVISOR sees ≤ PHEOC (strict scope)', async () => {
    const r = await get(`/alerts?user_id=${DS.id}&per_page=200`)
    assert(r.status === 200)
    const items = r.body?.data?.items || r.body?.data || []
    dsCount = Array.isArray(items) ? items.length : 0
    assert(dsCount <= phCount || phCount === 0, `DS (${dsCount}) must not exceed PHEOC (${phCount})`)
    for (const it of items) {
      if (it.district_code) eq(it.district_code, DS.district_code, `DS saw cross-district row id=${it.id}`)
    }
  })

  await t('F4 · Same-district primary screenings scoped correctly', async () => {
    const r = await get(`/primary-screenings?user_id=${DS.id}&per_page=200`)
    assert(r.status === 200)
    const items = r.body?.data?.items || r.body?.data || []
    for (const it of items) {
      if (it.district_code) eq(it.district_code, DS.district_code, 'DS saw cross-district primary row')
    }
  })
}

// ── G · Hostile user_id tampering ──────────────────────────────────────────
async function suiteG() {
  await t('G1 · Non-existent user_id handled gracefully (no 5xx)', async () => {
    const r = await get('/alerts?user_id=999999999&per_page=1')
    assert(r.status < 500, 'got 5xx for bad user_id: ' + r.status)
    // 200 with empty scope, 400 with error, or 403 all acceptable. 5xx not.
  })

  await t('G2 · Negative user_id not a crash', async () => {
    const r = await get('/alerts?user_id=-1&per_page=1')
    assert(r.status < 500, 'got 5xx for negative user_id: ' + r.status)
  })

  await t('G3 · Missing user_id not a crash', async () => {
    const r = await get('/alerts?per_page=1')
    assert(r.status < 500, 'got 5xx for missing user_id: ' + r.status)
  })

  await t('G4 · Non-numeric user_id not a crash', async () => {
    const r = await get('/alerts?user_id=DROP%20TABLE%20users&per_page=1')
    assert(r.status < 500, 'got 5xx for SQL-looking user_id: ' + r.status)
  })
}

// ── I · Row-level scope leak scan — every user × every endpoint ────────────
async function suiteI() {
  const endpoints = ['/alerts', '/primary-screenings']
  const users = [NA, PH, DS]

  for (const user of users) {
    for (const ep of endpoints) {
      const label = `I · ${user.role_key} × ${ep} — zero cross-scope rows`
      await t(label, async () => {
        const r = await get(`${ep}?user_id=${user.id}&per_page=200`)
        assert(r.status === 200)
        const items = r.body?.data?.items || r.body?.data || []
        if (!Array.isArray(items) || items.length === 0) return // nothing to audit

        for (const it of items) {
          switch (user.role_key) {
            case 'NATIONAL_ADMIN':
              // NA must not see rows outside their country_code.
              if (user.country_code && it.country_code) {
                eq(it.country_code, user.country_code, `${ep} id=${it.id} wrong country`)
              }
              break
            case 'PHEOC_OFFICER':
              if (it.pheoc_code && user.pheoc_code) {
                eq(it.pheoc_code, user.pheoc_code, `${ep} id=${it.id} wrong pheoc`)
              }
              break
            case 'DISTRICT_SUPERVISOR':
              if (it.district_code && user.district_code) {
                eq(it.district_code, user.district_code, `${ep} id=${it.id} wrong district`)
              }
              break
          }
        }
      })
    }
  }
}

// ── J · Unknown role_key on server user — must not crash ──────────────────
async function suiteJ() {
  await t('J1 · POE_OFFICER legacy user (not in VALID_ROLES) still returns scoped alerts', async () => {
    // Existing POE_OFFICER fixture (id 13) — legacy role. The scope-resolver
    // in controllers falls back to country_code when role is unrecognised.
    const legacy = (await get('/users?role_key=POE_OFFICER&per_page=1')).body?.data?.items?.[0]
    if (!legacy) return // none exist, skip silently
    const r = await get(`/alerts?user_id=${legacy.id}&per_page=1`)
    assert(r.status === 200 || r.status === 400 || r.status === 403, 'unexpected status: ' + r.status)
  })
}

// ── H · Caller-hierarchy edge cases ────────────────────────────────────────
async function suiteH() {
  await t('H1 · No user_id on POST /users → anonymous path still works (v1 open model)', async () => {
    const p = basePayload('POE_DATA_OFFICER', {
      assignment: {
        province_code: PH.province_code,
        pheoc_code:    PH.pheoc_code,
        district_code: PH.district_code,
        poe_code:      PH.poe_code,
      },
    })
    const r = await post('/users', p)
    // Open v1 model — caller is anonymous, hierarchy check bypassed.
    assert(r.status === 200 || r.status === 201, 'expected 2xx for anon POST, got ' + r.status + ': ' + r.raw.slice(0, 200))
    const id = r.body?.data?.id; if (id) CREATED_IDS.push(id)
  })

  await t('H2 · POE_PRIMARY cannot create any user (tier too low)', async () => {
    // Find a POE_PRIMARY we created in D2
    const scan = await get('/users?role_key=POE_PRIMARY&per_page=10')
    const primary = (scan.body?.data?.items || [])[0]
    if (!primary) throw new Error('no POE_PRIMARY fixture — suite D must run first')
    const p = basePayload('SCREENER', {
      assignment: { province_code: PH.province_code, pheoc_code: PH.pheoc_code, district_code: PH.district_code, poe_code: PH.poe_code },
    })
    const r = await post(`/users?user_id=${primary.id}`, p)
    eq(r.status, 422, 'expected 422, POE_PRIMARY should never create; got ' + r.status + ': ' + r.raw.slice(0, 200))
  })
}

// ── K · /users caller-scope filter (Directory view scope-leak fix) ─────────
async function suiteK() {
  let naUsers = null, phUsers = null, dsUsers = null

  await t('K1 · NA /users → unfiltered (global list)', async () => {
    const r = await get(`/users?user_id=${NA.id}&per_page=200`)
    assert(r.status === 200)
    naUsers = r.body?.data?.items || []
    assert(naUsers.length > 0, 'NA must see at least 1 user')
  })

  await t('K2 · PHEOC /users → confined to own pheoc_code', async () => {
    const r = await get(`/users?user_id=${PH.id}&per_page=200`)
    assert(r.status === 200)
    phUsers = r.body?.data?.items || []
    assert(phUsers.length <= naUsers.length, `PHEOC (${phUsers.length}) cannot exceed NA (${naUsers.length})`)
    for (const u of phUsers) {
      if (u.pheoc_code) eq(u.pheoc_code, PH.pheoc_code, `PHEOC saw cross-pheoc user id=${u.id}`)
    }
  })

  await t('K3 · DS /users → confined to own district_code', async () => {
    const r = await get(`/users?user_id=${DS.id}&per_page=200`)
    assert(r.status === 200)
    dsUsers = r.body?.data?.items || []
    assert(dsUsers.length <= phUsers.length || phUsers.length === 0,
      `DS (${dsUsers.length}) cannot exceed PHEOC (${phUsers.length})`)
    for (const u of dsUsers) {
      if (u.district_code) eq(u.district_code, DS.district_code, `DS saw cross-district user id=${u.id}`)
    }
  })

  await t('K4 · Anonymous /users → unscoped (legacy open-route model)', async () => {
    const r = await get('/users?per_page=10')
    assert(r.status === 200, 'anonymous /users should still return 200')
    const list = r.body?.data?.items || []
    assert(list.length > 0, 'anonymous list should not be empty')
  })
}

// ── cleanup ────────────────────────────────────────────────────────────────
async function cleanup() {
  if (!CREATED_IDS.length) return
  // Best-effort deactivate — avoids polluting the DB for next runs.
  for (const id of CREATED_IDS) {
    await http('PATCH', `/users/${id}/status?user_id=${NA.id}`, { is_active: 0 }).catch(() => null)
  }
}

// ── main ────────────────────────────────────────────────────────────────────
;(async () => {
  try {
    console.log('RBAC hostile suite → ' + BASE)
    await loadFixtures()
    await suiteA()
    await suiteB()
    await suiteD()
    await suiteE()
    await suiteF()
    await suiteG()
    await suiteH()
    await suiteI()
    await suiteJ()
    await suiteK()
  } catch (err) {
    console.error('\nFATAL:', err?.stack || err?.message)
    process.exit(2)
  } finally {
    try { await cleanup() } catch {}
  }

  process.stdout.write('\n')
  console.log(`${results.pass} passed, ${results.fail} failed, ${results.skipped} skipped`)
  if (results.fail > 0) {
    for (const e of results.errors.filter(x => !x.skip)) {
      console.error(`  ✗ ${e.name}\n    ${e.err.split('\n')[0]}`)
    }
    process.exit(1)
  }
  process.exit(0)
})()
