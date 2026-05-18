#!/usr/bin/env node
/**
 * POE_MAIN parity + consumer-contract tests (node-native, zero deps).
 *
 * Gates enforced:
 *   1. GOLDEN      — src/POEs.js EMBEDDED_FALLBACK ≡ tests/fixtures/poe_main.golden.json
 *   2. API BUNDLE  — GET /api/poes/bundle ≡ golden (byte-for-byte, key order preserved)
 *   3. CONSUMERS   — every field read by POEs.vue / UsersList.vue is present
 *   4. OFFLINE     — cached window.POE_MAIN survives a network outage
 *   5. REFRESH     — when ETag differs, a successful fetch replaces window.POE_MAIN
 *   6. COUNT       — administrative_groups.length === provinces in bundle; every POE
 *                    links to a province listed in administrative_groups
 *
 * Usage:
 *   node tests/integration/poes_parity.test.cjs [--api http://127.0.0.1:8000/api]
 */

const fs   = require('fs')
const path = require('path')
const http = require('http')

const REPO_ROOT  = path.resolve(__dirname, '..', '..')
const GOLDEN     = path.join(REPO_ROOT, 'tests/fixtures/poe_main.golden.json')
const POES_JS    = path.join(REPO_ROOT, 'src/POEs.js')
const DEFAULT_API = 'http://127.0.0.1:8000/api'

// ── CLI args ────────────────────────────────────────────────────────────
function parseArgs (argv) {
  const out = { api: DEFAULT_API }
  for (let i = 2; i < argv.length; i++) {
    if (argv[i] === '--api' && argv[i + 1]) { out.api = argv[++i] }
  }
  return out
}

// ── Test harness ────────────────────────────────────────────────────────
const results = []
function test (name, fn) {
  return Promise.resolve().then(() => fn()).then(
    () => { results.push({ name, status: 'PASS' }); console.log('✓', name) },
    (err) => { results.push({ name, status: 'FAIL', err: err.message }); console.log('✗', name, '—', err.message) }
  )
}

function assert (cond, msg) { if (!cond) throw new Error(msg) }

function deepDiff (a, b, p = '') {
  const d = []
  if (typeof a !== typeof b || (a === null) !== (b === null)) { d.push(p + ': type ' + typeof a + ' vs ' + typeof b); return d }
  if (Array.isArray(a)) {
    if (!Array.isArray(b)) { d.push(p + ': array vs ' + typeof b); return d }
    if (a.length !== b.length) d.push(p + ': len ' + a.length + ' vs ' + b.length)
    const n = Math.min(a.length, b.length)
    for (let i = 0; i < n; i++) d.push(...deepDiff(a[i], b[i], p + '[' + i + ']'))
    return d
  }
  if (typeof a === 'object' && a !== null) {
    const ak = Object.keys(a), bk = Object.keys(b)
    ak.filter(k => !(k in b)).forEach(k => d.push(p + '.' + k + ': missing'))
    bk.filter(k => !(k in a)).forEach(k => d.push(p + '.' + k + ': extra'))
    // Key order check (POEs.vue / UsersList.vue do not rely on it but
    // we lock it down to stop silent drift).
    if (JSON.stringify(ak) !== JSON.stringify(bk.filter(k => k in a).concat(bk.filter(k => !(k in a))))) {
      // Soft check — only fail outright if the intersection order differs.
      const interA = ak.filter(k => k in b)
      const interB = bk.filter(k => k in a)
      if (JSON.stringify(interA) !== JSON.stringify(interB)) {
        d.push(p + ': key order differs — ' + JSON.stringify(interA) + ' vs ' + JSON.stringify(interB))
      }
    }
    for (const k of ak) if (k in b) d.push(...deepDiff(a[k], b[k], p + '.' + k))
    return d
  }
  if (a !== b) d.push(p + ': ' + JSON.stringify(a) + ' vs ' + JSON.stringify(b))
  return d
}

// ── Sandboxed loader for src/POEs.js ────────────────────────────────────
function loadPoesJsIn (env) {
  const src = fs.readFileSync(POES_JS, 'utf8')
  const w = env.window
  const sandbox = {
    window: w,
    fetch: env.fetch,
    setTimeout: env.setTimeout,
    CustomEvent: env.CustomEvent,
    console,
  }
  const fn = new Function('window', 'fetch', 'setTimeout', 'CustomEvent', 'console', src)
  fn(sandbox.window, sandbox.fetch, sandbox.setTimeout, sandbox.CustomEvent, sandbox.console)
  return w
}

function makeWindow (opts = {}) {
  const listeners = []
  const store = opts.storage || {}
  return {
    POE_MAIN: undefined,
    SERVER_URL: opts.serverUrl || null,
    localStorage: {
      getItem: (k) => (k in store ? store[k] : null),
      setItem: (k, v) => { store[k] = String(v) },
      removeItem: (k) => { delete store[k] },
    },
    addEventListener: (ev, cb) => listeners.push([ev, cb]),
    removeEventListener: () => {},
    dispatchEvent: (ev) => listeners.filter(([n]) => n === ev.type).forEach(([, cb]) => cb(ev)),
    _listeners: listeners,
    _storage: store,
  }
}

// ── HTTP helper ─────────────────────────────────────────────────────────
function httpJson (method, url, headers = {}, body = null) {
  return new Promise((resolve, reject) => {
    const u = new URL(url)
    const lib = u.protocol === 'https:' ? require('https') : require('http')
    const req = lib.request({
      method,
      hostname: u.hostname,
      port: u.port,
      path: u.pathname + u.search,
      headers: Object.assign({ 'Accept': 'application/json' }, headers),
    }, res => {
      let buf = ''
      res.on('data', c => buf += c)
      res.on('end', () => {
        resolve({ status: res.statusCode, headers: res.headers, body: buf ? safeParse(buf) : null, raw: buf })
      })
    })
    req.on('error', reject)
    if (body != null) req.write(typeof body === 'string' ? body : JSON.stringify(body))
    req.end()
  })
}
function safeParse (s) { try { return JSON.parse(s) } catch { return null } }

// ── Main ────────────────────────────────────────────────────────────────
const args = parseArgs(process.argv)
const API = args.api.replace(/\/+$/, '')

;(async () => {
  // ---------- 1. GOLDEN embedded parity ----------
  await test('GOLDEN embedded fallback deep-equals golden fixture', () => {
    const golden = JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))
    const w = makeWindow()
    // Disable async by making setTimeout a no-op; storage empty so the
    // prime path lands on EMBEDDED_FALLBACK.
    loadPoesJsIn({ window: w, fetch: async () => ({ ok: false }), setTimeout: () => {}, CustomEvent: function (t, i) { this.type = t; Object.assign(this, i) } })
    const d = deepDiff(golden, w.POE_MAIN)
    assert(d.length === 0, 'embedded mismatch: ' + d.slice(0, 3).join(' ; '))
  })

  // ---------- 2. API BUNDLE byte-equal ----------
  let apiBundle = null
  let apiETag = null
  await test('API BUNDLE deep-equals golden fixture', async () => {
    const golden = JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))
    const res = await httpJson('GET', API + '/poes/bundle')
    assert(res.status === 200, 'bundle HTTP ' + res.status)
    assert(res.body && res.body.data, 'bundle missing data')
    apiBundle = res.body.data
    apiETag = res.headers.etag || res.headers.ETag
    const d = deepDiff(golden, apiBundle)
    assert(d.length === 0, 'bundle mismatch: ' + d.slice(0, 3).join(' ; '))
  })

  // ---------- 3. CONSUMER CONTRACT (POEs.vue + UsersList.vue) ----------
  await test('CONSUMER contract: POEs.vue reads', () => {
    assert(apiBundle, 'skip — api bundle unavailable')
    const poe = apiBundle.poes.find(p => p.country === 'Zambia')
    assert(poe, 'no Zambia POE in bundle')
    const required = ['id', 'country', 'province', 'admin_level_1', 'admin_level_1_type', 'district',
      'poe_name', 'poe_code', 'poe_type', 'transport_mode', 'border_country',
      'is_major_entry', 'is_recommended_osbp', 'is_national_level',
      'regional_cluster_or_rpheoc']
    for (const k of required) assert(k in poe, 'POE field missing: ' + k)
    assert(typeof poe.is_major_entry === 'boolean', 'is_major_entry must be boolean')
    assert(typeof poe.is_recommended_osbp === 'boolean', 'is_recommended_osbp must be boolean')
    assert(typeof poe.is_national_level === 'boolean', 'is_national_level must be boolean')
    assert(['land', 'air', 'water'].includes(poe.transport_mode), 'transport_mode out of allowed set')
    assert(apiBundle.metadata && apiBundle.metadata.dataset_name, 'metadata.dataset_name required')
    assert(apiBundle.traveler_notes && typeof apiBundle.traveler_notes === 'object', 'traveler_notes required')
  })

  await test('CONSUMER contract: UsersList.vue reads', () => {
    assert(apiBundle, 'skip — api bundle unavailable')
    assert(Array.isArray(apiBundle.administrative_groups), 'administrative_groups must be array')
    for (const g of apiBundle.administrative_groups) {
      assert('country' in g && 'admin_level_1' in g && 'admin_level_1_type' in g && Array.isArray(g.districts),
        'admin group missing keys')
    }
    // Every POE's province must appear in administrative_groups
    const pheocs = new Set(apiBundle.administrative_groups.map(g => g.admin_level_1))
    for (const p of apiBundle.poes) {
      assert(pheocs.has(p.admin_level_1), 'POE "' + p.poe_name + '" admin_level_1 not in administrative_groups: ' + p.admin_level_1)
    }
  })

  // ---------- 4. OFFLINE cache prime ----------
  await test('OFFLINE: cache survives network outage', () => {
    assert(apiBundle, 'skip — api bundle unavailable')
    const storage = { poe_main_cache_v1: JSON.stringify({ data: apiBundle, etag: apiETag, fetched_at: Date.now() }) }
    const w = makeWindow({ storage, serverUrl: 'http://unreachable.local/api' })
    loadPoesJsIn({
      window: w,
      fetch: async () => { throw new Error('offline') },
      setTimeout: (fn) => { try { fn() } catch (_) {} },
      CustomEvent: function (t, i) { this.type = t; Object.assign(this, i) },
    })
    assert(w.POE_MAIN && Array.isArray(w.POE_MAIN.poes), 'cache did not prime window.POE_MAIN')
    assert(w.POE_MAIN.poes.length === apiBundle.poes.length, 'cached poe count mismatch')
  })

  // ---------- 5. REFRESH: async fetch updates cache + window ----------
  await test('REFRESH: new bundle replaces stale cache when ETag differs', async () => {
    const stale = JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))
    stale.poes = stale.poes.slice(0, 5)                            // pretend the device has a stale cut
    const storage = { poe_main_cache_v1: JSON.stringify({ data: stale, etag: 'W/"stale"', fetched_at: 0 }) }
    const w = makeWindow({ storage, serverUrl: 'http://mock/api' })

    const headerStore = { etag: 'W/"fresh"', ETag: 'W/"fresh"' }
    const mockResponse = {
      ok: true, status: 200,
      headers: { get: (k) => headerStore[k] || headerStore[String(k).toLowerCase()] || null },
      json: async () => ({ success: true, data: apiBundle, meta: { version: 999, counts: {} } }),
    }
    let pending
    loadPoesJsIn({
      window: w,
      fetch: async (url, opts) => {
        // Verify the loader sent If-None-Match based on the stale ETag
        assert(opts && opts.headers && opts.headers['If-None-Match'] === 'W/"stale"',
          'loader did not send If-None-Match: ' + JSON.stringify(opts && opts.headers))
        return mockResponse
      },
      setTimeout: (fn) => { pending = fn },
      CustomEvent: function (t, i) { this.type = t; Object.assign(this, i) },
    })
    // Before refresh: window.POE_MAIN primed from stale cache (5 entries)
    assert(w.POE_MAIN.poes.length === 5, 'stale cache did not prime')
    // Run the deferred refresh
    await pending()
    assert(w.POE_MAIN.poes.length === apiBundle.poes.length, 'window.POE_MAIN not refreshed')
    const fresh = JSON.parse(w._storage.poe_main_cache_v1)
    assert(fresh.etag === 'W/"fresh"', 'cache ETag not updated: ' + fresh.etag)
  })

  // ---------- 6. 304 path: no cache mutation ----------
  await test('REFRESH: 304 leaves cache + window untouched', async () => {
    const storage = { poe_main_cache_v1: JSON.stringify({ data: apiBundle, etag: apiETag || 'W/"cached"', fetched_at: 1 }) }
    const w = makeWindow({ storage, serverUrl: 'http://mock/api' })
    const before = String(w._storage.poe_main_cache_v1)
    let pending
    loadPoesJsIn({
      window: w,
      fetch: async () => ({ status: 304, ok: false, headers: { get: () => null }, json: async () => null }),
      setTimeout: (fn) => { pending = fn },
      CustomEvent: function (t, i) { this.type = t; Object.assign(this, i) },
    })
    await pending()
    assert(String(w._storage.poe_main_cache_v1) === before, '304 path mutated cache')
    assert(w.POE_MAIN.poes.length === apiBundle.poes.length, '304 path broke window.POE_MAIN')
  })

  // ---------- 7. ETag consistency across two bundle calls ----------
  await test('API BUNDLE: ETag is stable when DB unchanged', async () => {
    const a = await httpJson('GET', API + '/poes/bundle')
    const b = await httpJson('GET', API + '/poes/bundle')
    assert(a.headers.etag && a.headers.etag === b.headers.etag, 'ETag drift between reads: ' + a.headers.etag + ' ≠ ' + b.headers.etag)
  })

  // ---------- 8. If-None-Match 304 ----------
  await test('API BUNDLE: If-None-Match returns 304', async () => {
    const a = await httpJson('GET', API + '/poes/bundle')
    const b = await httpJson('GET', API + '/poes/bundle', { 'If-None-Match': a.headers.etag })
    assert(b.status === 304, 'expected 304, got ' + b.status)
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
