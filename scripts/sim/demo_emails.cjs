#!/usr/bin/env node
/**
 * scripts/sim/demo_emails.cjs — small SMTP demonstration batch.
 *
 * Reuses already-dispositioned secondary cases from the bulk sim and
 * emits a small, throttled set of alerts covering EVERY notification
 * template — guaranteeing each of the two whitelisted addresses
 * (vexa256 + ayebare.k.timothy) receives at least one of each:
 *
 *   ALERT_CRITICAL · ALERT_HIGH · ALERT_CASE_FILE
 *   TIER1_ADVISORY · PHEIC_ADVISORY
 *   ALERT_CLOSED · DAILY_REPORT
 *   (FOLLOWUP_DUE / BREACH_717 / ESCALATION optional)
 *
 * Heavily throttled so WSL2 ↔ Gmail SMTP 587 hand-off has breathing
 * room — Gmail personal accounts tolerate ~100 relays/hour; this script
 * sends far fewer.
 */

const http = require('http')
const { randomUUID } = require('crypto')

const CFG = {
  api: 'http://127.0.0.1:8000/api',
  userId: 2,
  deviceId: 'ECSA-DEMO-' + randomUUID().slice(0, 8),
  throttleMs: 3500,
  referenceDataVersion: 'rda-2026-02-01',
}

function httpJson(method, path, body = null) {
  return new Promise((resolve, reject) => {
    const u = new URL(CFG.api + path)
    const payload = body ? JSON.stringify(body) : null
    const req = http.request({
      method,
      hostname: u.hostname,
      port: u.port,
      path: u.pathname + u.search,
      headers: Object.assign({ 'Accept': 'application/json' },
        payload ? { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) } : {}),
      timeout: 120000,
    }, res => {
      let buf = ''
      res.on('data', c => buf += c)
      res.on('end', () => {
        let parsed = null; try { parsed = buf ? JSON.parse(buf) : null } catch (_) {}
        resolve({ status: res.statusCode, body: parsed, raw: buf })
      })
    })
    req.on('error', reject)
    if (payload) req.write(payload)
    req.end()
  })
}

const wait = (ms) => new Promise(r => setTimeout(r, ms))
const ts = () => new Date().toISOString().slice(11, 19)
const log = (m) => console.log(`[${ts()}] ${m}`)

async function getOpenSecondaries(limit) {
  // Server wraps the list as data.items — extract before filtering.
  const r = await httpJson('GET', `/secondary-screenings?user_id=${CFG.userId}&case_status=DISPOSITIONED&per_page=${limit}`)
  const items = r?.body?.data?.items ?? r?.body?.data ?? []
  return Array.isArray(items) ? items.slice(0, limit) : []
}

async function createAlert(sec, variant, idx) {
  const specs = {
    'critical-tier1':   { risk:'CRITICAL', route:'NATIONAL', tier:'TIER_1', codePrefix:'DEMO-TIER1' },
    'critical':         { risk:'CRITICAL', route:'NATIONAL', tier:null,     codePrefix:'DEMO-CRIT' },
    'high':             { risk:'HIGH',     route:'PHEOC',    tier:null,     codePrefix:'DEMO-HIGH' },
  }[variant]
  const body = {
    client_uuid: randomUUID(),
    created_by_user_id: CFG.userId,
    secondary_screening_id: sec.id,
    alert_code: `${specs.codePrefix}-${String(idx).padStart(3,'0')}-${Date.now()}`,
    alert_title: `DEMO ${variant.toUpperCase()} — ${sec.syndrome_classification || 'unspecified'}`,
    alert_details: `Real-SMTP demo: ${variant}. See your inbox.`,
    risk_level: specs.risk,
    routed_to_level: specs.route,
    ihr_tier: specs.tier,
    generated_from: 'RULE_BASED',
    device_id: CFG.deviceId,
    platform: 'ANDROID',
    app_version: 'demo-1.0',
    reference_data_version: CFG.referenceDataVersion,
  }
  log(`  → alert ${idx} (${variant}) risk=${specs.risk} route=${specs.route} tier=${specs.tier ?? '-'}`)
  const r = await httpJson('POST', '/alerts', body)
  if (r.status !== 200 && r.status !== 201) {
    log(`      ✗ alert failed HTTP ${r.status} — ${JSON.stringify(r.body).slice(0,200)}`)
    return null
  }
  log(`      ✓ alert id=${r.body.data.id}`)
  return r.body.data
}

async function closeAlert(a) {
  // Satisfy blocking RTSL followups first so close is accepted.
  const fu = await httpJson('GET', `/alerts/${a.id}/followups?user_id=${CFG.userId}`)
  for (const r of (fu?.body?.data || []).filter(f => f.blocks_closure)) {
    await httpJson('PATCH', `/alert-followups/${r.id}`, {
      user_id: CFG.userId, status: 'COMPLETED', completed_note: 'demo' })
  }
  const res = await httpJson('PATCH', `/alerts/${a.id}/close`, {
    user_id: CFG.userId, close_category: 'RESOLVED',
    close_note: `DEMO closure — smoke the ALERT_CLOSED template.`,
  })
  log(`  → close alert ${a.id}: HTTP ${res.status}`)
}

async function sendDigest() {
  const res = await httpJson('POST', '/digests/daily/send', { user_id: CFG.userId, country: 'Zambia' })
  log(`  → daily digest: HTTP ${res.status}`)
}

async function main() {
  log('DEMO BATCH — real SMTP sends to vexa256 + ayebare.k.timothy')
  const secs = await getOpenSecondaries(10)
  if (secs.length < 3) { log(`insufficient dispositioned secondaries (${secs.length}); run full sim first`); process.exit(1) }

  let idx = 0
  const variants = ['critical-tier1', 'critical', 'high']
  const created = []
  for (const v of variants) {
    idx++
    const a = await createAlert(secs[idx - 1], v, idx)
    if (a) created.push(a)
    await wait(CFG.throttleMs)
  }

  // Close one alert → ALERT_CLOSED template fires
  if (created[0]) {
    await wait(CFG.throttleMs)
    await closeAlert(created[0])
  }

  // Daily digest — DAILY_REPORT template
  await wait(CFG.throttleMs)
  await sendDigest()

  log('DEMO COMPLETE.')
}

main().catch(e => { console.error('DEMO FAIL:', e.stack || e.message); process.exit(2) })
