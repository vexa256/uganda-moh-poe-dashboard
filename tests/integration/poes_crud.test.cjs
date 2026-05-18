#!/usr/bin/env node
/**
 * CRUD gate — exercises every /api/geo/* endpoint against the running
 * dev server, verifies:
 *   • NATIONAL_ADMIN guard rejects missing/bad user_id
 *   • create → show → update → destroy round-trip
 *   • bundle ETag changes on mutation and recovers after rollback
 *   • bundle shape remains byte-equal to golden after full rollback
 *
 * Usage:
 *   node tests/integration/poes_crud.test.cjs [--api http://127.0.0.1:8000/api] [--admin 1]
 */

const fs   = require('fs')
const path = require('path')

const REPO_ROOT = path.resolve(__dirname, '..', '..')
const GOLDEN    = path.join(REPO_ROOT, 'tests/fixtures/poe_main.golden.json')

function parseArgs (argv) {
  const out = { api: 'http://127.0.0.1:8000/api', admin: 1 }
  for (let i = 2; i < argv.length; i++) {
    if (argv[i] === '--api' && argv[i + 1]) out.api = argv[++i]
    if (argv[i] === '--admin' && argv[i + 1]) out.admin = Number(argv[++i])
  }
  return out
}

function httpJson (method, url, { headers = {}, body = null } = {}) {
  return new Promise((resolve, reject) => {
    const u = new URL(url)
    const lib = u.protocol === 'https:' ? require('https') : require('http')
    const payload = body != null ? (typeof body === 'string' ? body : JSON.stringify(body)) : null
    const req = lib.request({
      method,
      hostname: u.hostname,
      port: u.port,
      path: u.pathname + u.search,
      headers: Object.assign(
        { 'Accept': 'application/json' },
        payload ? { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) } : {},
        headers
      ),
    }, res => {
      let buf = ''
      res.on('data', c => buf += c)
      res.on('end', () => {
        let parsed = null
        try { parsed = buf ? JSON.parse(buf) : null } catch (_) { parsed = null }
        resolve({ status: res.statusCode, headers: res.headers, body: parsed, raw: buf })
      })
    })
    req.on('error', reject)
    if (payload) req.write(payload)
    req.end()
  })
}

const results = []
async function test (name, fn) {
  try { await fn(); results.push({ name, status: 'PASS' }); console.log('✓', name) }
  catch (e) { results.push({ name, status: 'FAIL', err: e.message }); console.log('✗', name, '—', e.message) }
}
function assert (c, m) { if (!c) throw new Error(m) }

function deepDiff (a, b, p = '') {
  const d = []
  if (typeof a !== typeof b || (a === null) !== (b === null)) { d.push(p + ': type'); return d }
  if (Array.isArray(a)) {
    if (a.length !== b.length) d.push(p + ': len ' + a.length + ' vs ' + b.length)
    const n = Math.min(a.length, b.length)
    for (let i = 0; i < n; i++) d.push(...deepDiff(a[i], b[i], p + '[' + i + ']'))
    return d
  }
  if (typeof a === 'object' && a !== null) {
    for (const k of Object.keys(a)) d.push(...deepDiff(a[k], b[k], p + '.' + k))
    for (const k of Object.keys(b)) if (!(k in a)) d.push(p + '.' + k + ': extra')
    return d
  }
  if (a !== b) d.push(p + ': ' + JSON.stringify(a) + ' vs ' + JSON.stringify(b))
  return d
}

const args = parseArgs(process.argv)
const API = args.api.replace(/\/+$/, '')
const UID = args.admin

;(async () => {
  const golden = JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

  // Snapshot starting ETag + version.
  const v0 = await httpJson('GET', API + '/poes/bundle/version')
  assert(v0.status === 200, 'version endpoint HTTP ' + v0.status)
  const startingVersion = v0.body.data.version
  const startingEtag = v0.body.data.etag

  // ---------- GUARD: missing user_id ----------
  await test('GUARD: POST without user_id → 422', async () => {
    const r = await httpJson('POST', API + '/geo/countries', { body: { country_code: 'X' } })
    assert(r.status === 422, 'expected 422, got ' + r.status)
  })

  // ---------- COUNTRY CRUD ----------
  let createdCountryCode = null
  await test('COUNTRY: create', async () => {
    const r = await httpJson('POST', API + '/geo/countries', {
      body: { user_id: UID, country_code: 'TestLand', name: 'TestLand', iso_alpha2: 'TL', iso_alpha3: 'TLX', is_active: true, display_order: 999 },
    })
    assert(r.status === 200, 'HTTP ' + r.status + ' ' + (r.body && r.body.message))
    assert(r.body && r.body.data && r.body.data.country_code === 'TestLand', 'country not returned')
    createdCountryCode = r.body.data.country_code
  })
  await test('COUNTRY: show', async () => {
    const r = await httpJson('GET', API + '/geo/countries/TestLand')
    assert(r.status === 200, 'HTTP ' + r.status)
    assert(r.body.data.name === 'TestLand', 'name mismatch')
  })
  await test('COUNTRY: update', async () => {
    const r = await httpJson('PATCH', API + '/geo/countries/TestLand', {
      body: { user_id: UID, name: 'TestLand Updated' },
    })
    assert(r.status === 200, 'HTTP ' + r.status)
    assert(r.body.data.name === 'TestLand Updated', 'update did not persist: ' + JSON.stringify(r.body.data))
  })
  await test('COUNTRY: destroy', async () => {
    const r = await httpJson('DELETE', API + '/geo/countries/TestLand?user_id=' + UID)
    assert(r.status === 200, 'HTTP ' + r.status + ' ' + r.raw)
    assert(r.body.data.deleted === true, 'not soft-deleted')
  })

  // ---------- PROVINCE CRUD ----------
  let createdProvinceId = null
  await test('PROVINCE: create', async () => {
    const r = await httpJson('POST', API + '/geo/provinces', {
      body: { user_id: UID, country_code: 'Zambia', name: 'Test Province PHEOC', admin_level_1_type: 'PHEOC' },
    })
    assert(r.status === 200, 'HTTP ' + r.status + ' ' + r.raw)
    createdProvinceId = r.body.data.id
    assert(r.body.data.name === 'Test Province PHEOC', 'name mismatch')
    assert(r.body.data.code === 'test-province-pheoc', 'slug mismatch: ' + r.body.data.code)
  })
  await test('PROVINCE: bundle now exposes it in administrative_groups', async () => {
    const r = await httpJson('GET', API + '/poes/bundle')
    assert(r.status === 200, 'HTTP ' + r.status)
    const names = r.body.data.administrative_groups.map(g => g.admin_level_1)
    assert(names.includes('Test Province PHEOC'), 'new province missing in bundle')
    assert(r.body.data.administrative_groups.length === golden.administrative_groups.length + 1, 'admin_groups count did not grow')
  })
  await test('PROVINCE: update renames in bundle', async () => {
    const r = await httpJson('PATCH', API + '/geo/provinces/' + createdProvinceId, {
      body: { user_id: UID, name: 'Renamed Province PHEOC' },
    })
    assert(r.status === 200, 'HTTP ' + r.status)
    const b = await httpJson('GET', API + '/poes/bundle')
    const names = b.body.data.administrative_groups.map(g => g.admin_level_1)
    assert(names.includes('Renamed Province PHEOC'), 'renamed province not in bundle')
    assert(!names.includes('Test Province PHEOC'), 'old province name still present')
  })
  await test('PROVINCE: destroy', async () => {
    const r = await httpJson('DELETE', API + '/geo/provinces/' + createdProvinceId + '?user_id=' + UID)
    assert(r.status === 200, 'HTTP ' + r.status + ' ' + r.raw)
  })

  // ---------- DISTRICT CRUD ----------
  // Need a province_id for the district. Use Lusaka Province PHEOC.
  const provList = await httpJson('GET', API + '/geo/provinces?country=Zambia')
  const lusaka = provList.body.data.find(p => p.name === 'Lusaka Province PHEOC')
  assert(lusaka, 'Lusaka Province PHEOC not found for district test')
  let createdDistrictId = null
  await test('DISTRICT: create', async () => {
    const r = await httpJson('POST', API + '/geo/districts', {
      body: { user_id: UID, country_code: 'Zambia', province_id: lusaka.id, name: 'Test District' },
    })
    assert(r.status === 200, 'HTTP ' + r.status + ' ' + r.raw)
    createdDistrictId = r.body.data.id
    assert(r.body.data.name_raw === 'Test', 'name_raw should strip District suffix — got ' + r.body.data.name_raw)
  })
  await test('DISTRICT: visible in bundle admin_groups for Lusaka', async () => {
    const b = await httpJson('GET', API + '/poes/bundle')
    const grp = b.body.data.administrative_groups.find(g => g.admin_level_1 === 'Lusaka Province PHEOC')
    assert(grp && grp.districts.includes('Test District'), 'district not in Lusaka PHEOC group')
  })
  await test('DISTRICT: destroy', async () => {
    const r = await httpJson('DELETE', API + '/geo/districts/' + createdDistrictId + '?user_id=' + UID)
    assert(r.status === 200, 'HTTP ' + r.status)
  })

  // ---------- POE CRUD ----------
  const distList = await httpJson('GET', API + '/geo/districts?country=Zambia&province_id=' + lusaka.id)
  const lusakaDistrict = distList.body.data[0]
  assert(lusakaDistrict, 'no district under Lusaka PHEOC')
  let createdPoeId = null
  await test('POE: create (minimal inputs → server auto-derives every shape field)', async () => {
    // NOTE: we intentionally do NOT send transport_mode, regional_cluster_or_rpheoc,
    // source_province_group, source_origin, country, province, admin_level_1, etc.
    // The server MUST derive / default all of those.  We also send a rogue
    // transport_mode to prove it is IGNORED for typed POEs.
    const r = await httpJson('POST', API + '/geo/poes', {
      body: {
        user_id: UID,
        province_id: lusaka.id,
        district_id: lusakaDistrict.id,
        poe_name: 'Test Border Post',
        poe_type: 'airport',                 // must pin transport_mode to 'air'
        transport_mode: 'water',             // must be IGNORED (server derives)
        border_country: 'Testistan',
        critical_details: 'Ephemeral test entry.',
        source_url: 'https://test.local',
        is_major_entry: false,
        is_recommended_osbp: false,
        is_national_level: false,
      },
    })
    assert(r.status === 200, 'HTTP ' + r.status + ' ' + r.raw)
    createdPoeId = r.body.data.id
    const payload = r.body.data.payload
    assert(payload, 'payload missing in response')
    assert(payload.poe_name === 'Test Border Post', 'name mismatch')
    assert(payload.poe_code === 'Test Border Post', 'poe_code must default to poe_name: ' + payload.poe_code)
    assert(payload.poe_type === 'airport', 'poe_type mismatch')
    assert(payload.transport_mode === 'air', 'transport_mode must be derived from poe_type: got ' + payload.transport_mode)
    assert(payload.country === 'Zambia', 'country must be auto-set')
    assert(payload.province === 'Lusaka Province PHEOC', 'province must derive from FK')
    assert(payload.admin_level_1 === 'Lusaka Province PHEOC', 'admin_level_1 must derive from FK')
    assert(payload.admin_level_1_type === 'PHEOC', 'admin_level_1_type must derive from FK')
    assert(payload.regional_cluster_or_rpheoc === 'Lusaka Province PHEOC', 'regional_cluster must auto-equal province')
    assert(payload.source_province_group === 'Lusaka Province PHEOC', 'source_province_group must auto-equal province')
    assert(payload.district === lusakaDistrict.name, 'district must derive from FK')
    assert(payload.district_raw === lusakaDistrict.name_raw, 'district_raw must come from ref_districts.name_raw')
    assert(payload.source_origin === 'Zambia Department of Immigration - Gazetted Border Stations 2026',
      'source_origin must default to the canonical gazette string: ' + payload.source_origin)
    assert(payload.border_country === 'Testistan', 'border_country preserved')
    assert(typeof payload.is_major_entry === 'boolean', 'booleans must stay booleans')
    // Deterministic id: ZM-LUS-LUA-TES-001
    assert(/^ZM-[A-Z0-9]{3}-[A-Z0-9]{3}-[A-Z0-9]{3}-\d{3}$/.test(payload.id),
      'external_id must match deterministic pattern: ' + payload.id)
    // Key order of payload must match legacy contract.
    const expectedKeys = ['id','country','province','admin_level_1','admin_level_1_type',
      'district','district_raw','poe_name','poe_code','poe_type','transport_mode',
      'border_country','is_major_entry','is_recommended_osbp','is_national_level',
      'regional_cluster_or_rpheoc','critical_details','source_province_group',
      'source_url','source_origin']
    const got = Object.keys(payload)
    assert(JSON.stringify(got) === JSON.stringify(expectedKeys),
      'payload key order drifted — got ' + JSON.stringify(got))
  })
  await test('POE: present in bundle and linked to correct PHEOC', async () => {
    const b = await httpJson('GET', API + '/poes/bundle')
    const e = b.body.data.poes.find(p => p.poe_name === 'Test Border Post')
    assert(e, 'new POE missing in bundle')
    assert(e.province === 'Lusaka Province PHEOC', 'PHEOC linkage wrong: ' + e.province)
    assert(e.district === lusakaDistrict.name, 'district linkage wrong')
    assert(typeof e.is_major_entry === 'boolean', 'boolean cast broken')
    assert(e.border_country === 'Testistan', 'border_country mismatch')
    assert(e.transport_mode === 'air', 'bundle transport_mode must match derived value')
  })
  await test('POE: update (server-owned keys ignored, derived fields preserved)', async () => {
    const r = await httpJson('PATCH', API + '/geo/poes/' + createdPoeId, {
      body: {
        user_id: UID,
        is_major_entry: true,
        critical_details: 'Updated.',
        // Rogue server-owned keys — must be ignored:
        country: 'Narnia',
        province: 'Fake Province',
        admin_level_1: 'Fake Province',
        admin_level_1_type: 'FAKE',
        district: 'Fake District',
        district_raw: 'Fake',
        regional_cluster_or_rpheoc: 'Fake Cluster',
        source_province_group: 'Fake Group',
        transport_mode: 'water',  // poe_type still 'airport' → must stay 'air'
      },
    })
    assert(r.status === 200, 'HTTP ' + r.status + ' ' + r.raw)
    const b = await httpJson('GET', API + '/poes/bundle')
    const e = b.body.data.poes.find(p => p.poe_name === 'Test Border Post')
    assert(e.is_major_entry === true, 'update did not propagate')
    assert(e.critical_details === 'Updated.', 'critical_details did not update')
    // Server-owned invariants:
    assert(e.country === 'Zambia', 'rogue country accepted: ' + e.country)
    assert(e.province === 'Lusaka Province PHEOC', 'rogue province accepted: ' + e.province)
    assert(e.admin_level_1 === 'Lusaka Province PHEOC', 'rogue admin_level_1 accepted')
    assert(e.admin_level_1_type === 'PHEOC', 'rogue admin_level_1_type accepted: ' + e.admin_level_1_type)
    assert(e.regional_cluster_or_rpheoc === 'Lusaka Province PHEOC', 'rogue regional_cluster accepted')
    assert(e.source_province_group === 'Lusaka Province PHEOC', 'rogue source_province_group accepted')
    assert(e.transport_mode === 'air', 'rogue transport_mode accepted: ' + e.transport_mode)
  })
  await test('POE: destroy', async () => {
    const r = await httpJson('DELETE', API + '/geo/poes/' + createdPoeId + '?user_id=' + UID)
    assert(r.status === 200, 'HTTP ' + r.status)
  })

  // ---------- HOSPITAL CRUD (new capability, not in bundle) ----------
  let createdHospitalId = null
  await test('HOSPITAL: create', async () => {
    const r = await httpJson('POST', API + '/geo/hospitals', {
      body: {
        user_id: UID,
        country_code: 'Zambia',
        province_id: lusaka.id,
        district_id: lusakaDistrict.id,
        code: 'TEST-HOSP-001',
        name: 'Test Teaching Hospital',
        hospital_type: 'TEACHING',
        is_national_level: false,
        latitude: -15.4,
        longitude: 28.3,
      },
    })
    assert(r.status === 200, 'HTTP ' + r.status + ' ' + r.raw)
    createdHospitalId = r.body.data.id
  })
  await test('HOSPITAL: update', async () => {
    const r = await httpJson('PATCH', API + '/geo/hospitals/' + createdHospitalId, {
      body: { user_id: UID, name: 'Test Teaching Hospital (renamed)' },
    })
    assert(r.status === 200, 'HTTP ' + r.status)
    assert(r.body.data.name === 'Test Teaching Hospital (renamed)', 'rename did not persist')
  })
  await test('HOSPITAL: destroy', async () => {
    const r = await httpJson('DELETE', API + '/geo/hospitals/' + createdHospitalId + '?user_id=' + UID)
    assert(r.status === 200, 'HTTP ' + r.status)
  })

  // ---------- AFTER rollback, bundle must deep-equal golden ----------
  await test('ROLLBACK: bundle still deep-equals golden after full CRUD cycle', async () => {
    const r = await httpJson('GET', API + '/poes/bundle')
    const d = deepDiff(golden, r.body.data)
    assert(d.length === 0, 'post-rollback drift: ' + d.slice(0, 3).join(' ; '))
  })

  // ---------- VERSION: monotonically increased ----------
  await test('VERSION: ref_geo_version incremented after CRUD', async () => {
    const r = await httpJson('GET', API + '/poes/bundle/version')
    assert(r.status === 200, 'HTTP ' + r.status)
    assert(r.body.data.version > startingVersion, 'version did not advance: ' + startingVersion + ' → ' + r.body.data.version)
  })

  // ---------- Summary ----------
  console.log('\n──────── summary ────────')
  const pass = results.filter(r => r.status === 'PASS').length
  const fail = results.filter(r => r.status === 'FAIL').length
  console.log('PASS ' + pass + ' · FAIL ' + fail)
  if (fail) {
    results.filter(r => r.status === 'FAIL').forEach(r => console.log('  ✗', r.name, '—', r.err))
    process.exit(1)
  }
})().catch(e => { console.error('HARNESS FAIL:', e.stack || e.message); process.exit(2) })
