#!/usr/bin/env node
/**
 * scripts/sim/run_simulation.cjs — end-to-end simulation driver
 * ─────────────────────────────────────────────────────────────────────
 * Creates 100 primary screenings + 100 secondary screenings + a varied
 * batch of alerts that exercises every notification template path.
 *
 * Uses the REAL API — nothing is inserted directly into the DB.  Every
 * step goes through the controller, so validation, FK scoping, idempotency
 * and the dispatcher all fire exactly as they would in production.
 *
 * Safety:
 *   · NOTIFICATIONS_TEST_MODE=1 restricts all outbound email to the
 *     NOTIFICATIONS_TEST_WHITELIST set in api/.env — the only addresses
 *     that receive anything are vexa256@gmail.com + ayebare.k.timothy.
 *   · All writes carry a unique client_uuid and are idempotent on
 *     resubmit, so this script is safe to re-run.
 *   · SMTP calls are synchronous server-side and the script throttles
 *     alert creation to stay well below Gmail's rate limits.
 *
 * Usage:
 *   node scripts/sim/run_simulation.cjs              # full run
 *   node scripts/sim/run_simulation.cjs --no-alerts  # primaries + secondaries only
 *   node scripts/sim/run_simulation.cjs --primaries 20 --secondaries 20
 */

const http = require('http')
const { randomUUID } = require('crypto')

// ── Config ───────────────────────────────────────────────────────────
const CONFIG = {
  api: 'http://127.0.0.1:8000/api',
  userId: 2,                                  // zambia1 → NATIONAL_ADMIN assigned to Chirundu
  deviceId: 'ECSA-' + randomUUID() + '-SIM',
  platform: 'ANDROID',
  appVersion: 'sim-1.0.0',
  referenceDataVersion: 'rda-2026-02-01',
  timezone: 'Africa/Lusaka',
  primaries: 100,
  secondaries: 100,
  alertFraction: 0.2,                         // 20% of secondaries produce alerts
  alertThrottleMs: 900,                       // between alert creations (SMTP breathing room)
  secondaryThrottleMs: 80,                    // between secondary closes
  primaryThrottleMs: 60,                      // between primary creates
}
const argv = process.argv.slice(2)
if (argv.includes('--no-alerts')) CONFIG.alertFraction = 0
for (let i = 0; i < argv.length; i++) {
  if (argv[i] === '--primaries')    CONFIG.primaries   = Number(argv[++i])
  if (argv[i] === '--secondaries')  CONFIG.secondaries = Number(argv[++i])
  if (argv[i] === '--throttle')     CONFIG.alertThrottleMs = Number(argv[++i])
}

// ── HTTP helper ──────────────────────────────────────────────────────
function httpJson(method, path, body = null) {
  return new Promise((resolve, reject) => {
    const u = new URL(CONFIG.api + path)
    const payload = body ? JSON.stringify(body) : null
    const req = http.request({
      method,
      hostname: u.hostname,
      port: u.port,
      path: u.pathname + u.search,
      headers: Object.assign(
        { 'Accept': 'application/json' },
        payload ? { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(payload) } : {},
      ),
    }, res => {
      let buf = ''
      res.on('data', c => buf += c)
      res.on('end', () => {
        let parsed = null
        try { parsed = buf ? JSON.parse(buf) : null } catch (_) { parsed = null }
        resolve({ status: res.statusCode, body: parsed, raw: buf })
      })
    })
    req.on('error', reject)
    if (payload) req.write(payload)
    req.end()
  })
}

// ── Helpers ──────────────────────────────────────────────────────────
const rand   = (min, max) => Math.random() * (max - min) + min
const randi  = (min, max) => Math.floor(rand(min, max + 1))
const pick   = (arr) => arr[randi(0, arr.length - 1)]
const wait   = (ms) => new Promise(r => setTimeout(r, ms))
function nowSql(offsetMinutes = 0) {
  const d = new Date(Date.now() + offsetMinutes * 60000)
  const p = n => String(n).padStart(2, '0')
  return `${d.getUTCFullYear()}-${p(d.getUTCMonth()+1)}-${p(d.getUTCDate())} ${p(d.getUTCHours())}:${p(d.getUTCMinutes())}:${p(d.getUTCSeconds())}`
}

const GENDERS      = ['MALE', 'FEMALE']
const DISEASE_HINTS = ['Suspected viral syndrome','Fever of unknown origin','Gastrointestinal presentation','Respiratory presentation','Rash + fever','Neurological presentation']
const SYNDROMES    = ['ILI','SARI','AWD','VHF','RASH_FEVER','JAUNDICE','NEUROLOGICAL']
// final_disposition enum (from server schema): RELEASED, DELAYED, QUARANTINED,
// ISOLATED, REFERRED, TRANSFERRED, DENIED_BOARDING, OTHER.
// Note: REFERRED_HOSPITAL is an ACTION CODE, not a disposition — don't mix them.
const DISPOSITIONS = {
  LOW:      ['RELEASED'],
  MEDIUM:   ['RELEASED','DELAYED'],
  HIGH:     ['ISOLATED','REFERRED'],
  CRITICAL: ['ISOLATED','REFERRED'],
}
const OUTCOMES     = { LOW:'NON_CASE', MEDIUM:'PERSON_UNDER_SURVEILLANCE', HIGH:'SUSPECTED_CASE', CRITICAL:'SUSPECTED_CASE' }

// ── Logger ───────────────────────────────────────────────────────────
const metrics = {
  primariesOK: 0, primariesFail: 0,
  secondariesOK: 0, secondariesFail: 0,
  dispositionsOK: 0, dispositionsFail: 0,
  alertsOK: 0, alertsFail: 0,
  alertsClosed: 0, alertsEscalated: 0,
  digestsSent: 0,
  failures: [],
}

function log(msg, extra) {
  const ts = new Date().toISOString().slice(11, 19)
  if (extra !== undefined) console.log(`[${ts}] ${msg}`, extra)
  else console.log(`[${ts}] ${msg}`)
}

// ── Primary screening ────────────────────────────────────────────────
async function createPrimary(i) {
  // priority distribution: 25% CRITICAL (≥38.5°C+sym), 30% HIGH (≥37.5+sym),
  // 45% NORMAL (sym but no fever).  All symptomatic so every primary produces
  // a notification + secondary case.
  const r = Math.random()
  let temp = null
  if (r < 0.25)      temp = +(rand(38.5, 40.2)).toFixed(1)        // CRITICAL
  else if (r < 0.55) temp = +(rand(37.5, 38.4)).toFixed(1)        // HIGH
  // else NORMAL — no temp

  const body = {
    client_uuid:            randomUUID(),
    reference_data_version: CONFIG.referenceDataVersion,
    gender:                 pick(GENDERS),
    symptoms_present:       1,
    captured_at:            nowSql(-randi(1, 240)),
    device_id:              CONFIG.deviceId,
    platform:               CONFIG.platform,
    app_version:            CONFIG.appVersion,
    captured_timezone:      CONFIG.timezone,
    traveler_full_name:     `SimTraveler-${String(i).padStart(3, '0')}`,
    captured_by_user_id:    CONFIG.userId,
  }
  if (temp != null) {
    body.temperature_value = temp
    body.temperature_unit  = 'C'
  }

  const res = await httpJson('POST', '/primary-screenings', body)
  if (res.status === 200 || res.status === 201) {
    metrics.primariesOK++
    return {
      ok: true,
      primary_id: res.body?.data?.screening?.id ?? res.body?.data?.id,
      notification: res.body?.data?.notification,
      priority: res.body?.data?.notification?.priority ?? 'NORMAL',
    }
  }
  metrics.primariesFail++
  metrics.failures.push({ step: 'primary', i, status: res.status, body: res.body })
  return { ok: false }
}

// ── Secondary screening ──────────────────────────────────────────────
async function createSecondary(primaryResult, i) {
  const notif = primaryResult.notification
  if (!notif || !notif.id) return { ok: false, reason: 'no notification' }

  const body = {
    client_uuid:             randomUUID(),
    reference_data_version:  CONFIG.referenceDataVersion,
    notification_id:         notif.id,
    primary_screening_id:    primaryResult.primary_id,
    device_id:               CONFIG.deviceId,
    platform:                CONFIG.platform,
    app_version:             CONFIG.appVersion,
    opened_at:               nowSql(0),
    opened_timezone:         CONFIG.timezone,
    traveler_gender:         pick(GENDERS),
    opened_by_user_id:       CONFIG.userId,
  }
  const res = await httpJson('POST', '/secondary-screenings', body)
  if (res.status !== 200 && res.status !== 201) {
    metrics.secondariesFail++
    metrics.failures.push({ step: 'secondary', i, status: res.status, body: res.body })
    return { ok: false }
  }
  const secId = res.body?.data?.id
  metrics.secondariesOK++
  return { ok: true, secondary_id: secId, priority: primaryResult.priority }
}

// ── Secondary disposition via fullSync (drives risk_level + ack email path) ──
async function disposeSecondary(sec, i, forceRisk = null) {
  const risk = forceRisk || (sec.priority === 'CRITICAL' ? 'CRITICAL'
                        : sec.priority === 'HIGH'     ? (Math.random() < 0.6 ? 'HIGH' : 'MEDIUM')
                        : pick(['LOW','MEDIUM']))
  const disposition = pick(DISPOSITIONS[risk])
  const outcome     = OUTCOMES[risk]
  const syndrome    = pick(SYNDROMES)

  // State machine requires OPEN → IN_PROGRESS → DISPOSITIONED.  We can't
  // skip IN_PROGRESS, so the sim does a two-step fullSync: first move to
  // IN_PROGRESS (populating clinical fields), then to DISPOSITIONED.
  // Action payload shape (matches secondary_actions schema):
  //   { action_code, is_done (tinyint), details }
  // For HIGH/CRITICAL risk the server requires ISOLATED or REFERRED_HOSPITAL
  // action with is_done=1; for LOW/MEDIUM any completed action satisfies
  // the pre-dispositioned gate.  Pick the action that mirrors the chosen
  // final_disposition ("REFERRED" final_disposition → "REFERRED_HOSPITAL"
  // action code).
  const requiredAction = (risk === 'HIGH' || risk === 'CRITICAL')
    ? (disposition === 'REFERRED' ? 'REFERRED_HOSPITAL' : 'ISOLATED')
    : 'DOCUMENTATION'

  const base = {
    user_id:                   CONFIG.userId,
    opened_by_user_id:         CONFIG.userId,
    reference_data_version:    CONFIG.referenceDataVersion,
    syndrome_classification:   syndrome,
    symptoms:                  [{ symptom_code: 'FEVER', present: 1, severity: 'MODERATE' },
                                 { symptom_code: 'COUGH', present: 1, severity: 'MILD' }],
    exposures:                 [],
    actions:                   [{ action_code: requiredAction, is_done: 1, details: `Auto-sim: ${risk} → ${disposition}` }],
    samples:                   [],
    diseases:                  risk === 'CRITICAL' || risk === 'HIGH'
                                 ? [{ disease_code: 'ebola', confidence: 82, rank_order: 1, reason: 'simulated top signal' }]
                                 : [{ disease_code: 'influenza', confidence: 45, rank_order: 1, reason: 'simulated ILI' }],
  }

  // Step 1: OPEN → IN_PROGRESS
  const step1 = await httpJson('POST', `/secondary-screenings/${sec.secondary_id}/sync`, {
    ...base,
    case_status:      'IN_PROGRESS',
    officer_notes:    `Simulated case ${i} — opened for workup.`,
  })
  if (step1.status !== 200 && step1.status !== 201) {
    metrics.dispositionsFail++
    metrics.failures.push({ step: 'dispose-step1', i, status: step1.status, body: step1.body })
    return { ok: false }
  }

  // Step 2: IN_PROGRESS → DISPOSITIONED
  const step2 = await httpJson('POST', `/secondary-screenings/${sec.secondary_id}/sync`, {
    ...base,
    case_status:             'DISPOSITIONED',
    risk_level:              risk,
    final_disposition:       disposition,
    screening_outcome:       outcome,
    officer_notes:           `Simulated disposition ${i}: ${disposition} · ${risk} · ${outcome}`,
    disposition_details:     `OUTCOME:${outcome}|DISP:${disposition}|RISK:${risk}`,
  })
  if (step2.status !== 200 && step2.status !== 201) {
    metrics.dispositionsFail++
    metrics.failures.push({ step: 'dispose-step2', i, status: step2.status, body: step2.body })
    return { ok: false }
  }

  metrics.dispositionsOK++
  return { ok: true, risk, disposition, syndrome }
}

// ── Alert creation ───────────────────────────────────────────────────
async function createAlert(sec, dispo, idx, variant) {
  // Variants drive template coverage:
  //   'critical-tier1' → ALERT_CRITICAL + TIER1_ADVISORY + PHEIC_ADVISORY
  //   'critical'       → ALERT_CRITICAL
  //   'high'           → ALERT_HIGH
  //   'tier2-pheoc'    → routed_to_level=PHEOC, ihr_tier=TIER_2
  //   'medium-district'→ routed_to_level=DISTRICT
  //   'low'            → ALERT_LOW (falls through generic)
  const variantSpec = {
    'critical-tier1':    { risk:'CRITICAL', route:'NATIONAL', tier:'TIER_1', codePrefix:'TIER1' },
    'critical':          { risk:'CRITICAL', route:'NATIONAL', tier:null,     codePrefix:'ALT-CRIT' },
    'high':              { risk:'HIGH',     route:'PHEOC',    tier:null,     codePrefix:'ALT-HIGH' },
    'tier2-pheoc':       { risk:'HIGH',     route:'PHEOC',    tier:'TIER_2', codePrefix:'TIER2' },
    'medium-district':   { risk:'MEDIUM',   route:'DISTRICT', tier:null,     codePrefix:'ALT-MED' },
    'low':               { risk:'LOW',      route:'DISTRICT', tier:null,     codePrefix:'ALT-LOW' },
  }[variant]

  const body = {
    client_uuid:             randomUUID(),
    created_by_user_id:      CONFIG.userId,
    secondary_screening_id:  sec.secondary_id,
    alert_code:              `${variantSpec.codePrefix}-${String(idx).padStart(3,'0')}`,
    alert_title:             `Simulated ${variant.toUpperCase()} — case ${idx}`,
    alert_details:           `${dispo.syndrome} · ${dispo.risk} · disp=${dispo.disposition}`,
    risk_level:              variantSpec.risk,
    routed_to_level:         variantSpec.route,
    ihr_tier:                variantSpec.tier,
    generated_from:          'RULE_BASED',
    device_id:               CONFIG.deviceId,
    platform:                CONFIG.platform,
    app_version:             CONFIG.appVersion,
    reference_data_version:  CONFIG.referenceDataVersion,
  }
  const res = await httpJson('POST', '/alerts', body)
  if (res.status !== 200 && res.status !== 201) {
    metrics.alertsFail++
    metrics.failures.push({ step: 'alert', idx, variant, status: res.status, body: res.body })
    return { ok: false }
  }
  metrics.alertsOK++
  return { ok: true, alert_id: res.body?.data?.id, variant, ...variantSpec }
}

// ── Alert close / escalate ───────────────────────────────────────────
async function closeAlert(alert) {
  // Fresh alerts have 14 RTSL follow-ups auto-seeded; the ones with
  // blocks_closure=1 (CASE_INVESTIGATION, ISOLATION, CONTACT_LISTING,
  // CONTACT_TRACING, EOC_ACTIVATION, WHO_NOTIFICATION) must be in a
  // terminal state before close is accepted.  The sim marks them all
  // COMPLETED via the mutation endpoint so the closure path (and its
  // ALERT_CLOSED email) fires.
  try {
    const fuRes = await httpJson('GET', `/alerts/${alert.alert_id}/followups?user_id=${CONFIG.userId}`)
    const rows = fuRes?.body?.data || []
    for (const r of rows.filter(f => f.blocks_closure)) {
      await httpJson('PATCH', `/alert-followups/${r.id}`, {
        user_id:       CONFIG.userId,
        status:        'COMPLETED',
        completed_note:'auto-sim: blocker satisfied',
      })
    }
  } catch (e) {
    metrics.failures.push({ step: 'close-prep', alert_id: alert.alert_id, err: e.message })
    return false
  }

  const body = {
    user_id:        CONFIG.userId,
    close_category: 'RESOLVED',
    close_note:     `Simulated closure — ${alert.variant} cycle completed by auto-sim harness.`,
  }
  const res = await httpJson('PATCH', `/alerts/${alert.alert_id}/close`, body)
  if (res.status === 200) { metrics.alertsClosed++; return true }
  metrics.failures.push({ step: 'close', alert_id: alert.alert_id, status: res.status, body: res.body })
  return false
}

async function escalateAlert(alert, to) {
  // AlertCollaborationController.escalate expects `to_level` + `reason`.
  const body = {
    user_id:  CONFIG.userId,
    to_level: to,
    reason:   `Simulated escalation: ${alert.variant} → ${to} (auto-sim coverage)`,
  }
  const res = await httpJson('POST', `/alerts/${alert.alert_id}/escalate`, body)
  if (res.status === 200) { metrics.alertsEscalated++; return true }
  metrics.failures.push({ step: 'escalate', alert_id: alert.alert_id, status: res.status, body: res.body })
  return false
}

async function sendDailyDigest() {
  const res = await httpJson('POST', '/digests/daily/send', { user_id: CONFIG.userId, country: 'Zambia' })
  if (res.status === 200) { metrics.digestsSent++; return true }
  metrics.failures.push({ step: 'digest', status: res.status, body: res.body })
  return false
}

// ── Main ─────────────────────────────────────────────────────────────
async function main() {
  log(`Starting simulation → ${CONFIG.primaries} primaries · ${CONFIG.secondaries} secondaries · alert_fraction=${CONFIG.alertFraction}`)
  log(`Recipients (via NOTIFICATIONS_TEST_WHITELIST): vexa256@gmail.com, ayebare.k.timothy@gmail.com`)

  const primaries = []
  // ── 1. Primaries ────────────────────────────────────────────────
  for (let i = 1; i <= CONFIG.primaries; i++) {
    const p = await createPrimary(i)
    if (p.ok) primaries.push(p)
    if (i % 10 === 0) log(`  primary ${i}/${CONFIG.primaries} · ok=${metrics.primariesOK} fail=${metrics.primariesFail}`)
    await wait(CONFIG.primaryThrottleMs)
  }
  log(`Primaries: ok=${metrics.primariesOK} fail=${metrics.primariesFail}`)

  // ── 2. Secondaries ───────────────────────────────────────────────
  const secondaries = []
  const limit = Math.min(CONFIG.secondaries, primaries.length)
  for (let i = 0; i < limit; i++) {
    const s = await createSecondary(primaries[i], i + 1)
    if (s.ok) secondaries.push(s)
    if ((i + 1) % 10 === 0) log(`  secondary ${i+1}/${limit} · ok=${metrics.secondariesOK} fail=${metrics.secondariesFail}`)
    await wait(CONFIG.secondaryThrottleMs)
  }
  log(`Secondaries opened: ok=${metrics.secondariesOK} fail=${metrics.secondariesFail}`)

  // ── 3. Dispose secondaries ───────────────────────────────────────
  const disposed = []
  for (let i = 0; i < secondaries.length; i++) {
    const d = await disposeSecondary(secondaries[i], i + 1)
    if (d.ok) disposed.push({ ...secondaries[i], dispo: d })
    if ((i + 1) % 10 === 0) log(`  dispose ${i+1}/${secondaries.length} · ok=${metrics.dispositionsOK}`)
    await wait(CONFIG.secondaryThrottleMs)
  }
  log(`Dispositions: ok=${metrics.dispositionsOK} fail=${metrics.dispositionsFail}`)

  // ── 4. Alerts — variant coverage ─────────────────────────────────
  if (CONFIG.alertFraction > 0 && disposed.length) {
    const variants = [
      'critical-tier1', 'critical-tier1',
      'critical',       'critical',
      'high',           'high',
      'tier2-pheoc',    'tier2-pheoc',
      'medium-district','medium-district',
      'low',            'low',
    ]
    const highRiskSecs = disposed.filter(s => s.dispo.risk === 'CRITICAL' || s.dispo.risk === 'HIGH')
    const otherSecs    = disposed.filter(s => s.dispo.risk === 'MEDIUM' || s.dispo.risk === 'LOW')
    const pool = (highRiskSecs.length ? highRiskSecs : disposed)
    const totalAlerts = Math.min(variants.length, Math.ceil(disposed.length * CONFIG.alertFraction), pool.length)
    log(`Creating ${totalAlerts} alerts (variant coverage: ${variants.slice(0, totalAlerts).join(', ')})`)
    const created = []
    for (let i = 0; i < totalAlerts; i++) {
      const pickPool = (variants[i] === 'medium-district' || variants[i] === 'low')
        ? (otherSecs.length ? otherSecs : pool)
        : pool
      const s = pickPool[i % pickPool.length]
      const a = await createAlert(s, s.dispo, i + 1, variants[i])
      if (a.ok) created.push(a)
      log(`  alert ${i+1}/${totalAlerts} · variant=${variants[i]} · ok=${metrics.alertsOK} fail=${metrics.alertsFail}`)
      await wait(CONFIG.alertThrottleMs)
    }

    // ── 5. Close ~25% of alerts → ALERT_CLOSED emails ────────────
    const toClose = created.filter((_, i) => i % 4 === 1)
    for (const a of toClose) {
      await closeAlert(a)
      await wait(CONFIG.alertThrottleMs)
    }
    log(`Closed: ${metrics.alertsClosed}`)

    // ── 6. Escalate one open alert → ESCALATION email ────────────
    const openAlert = created.find(a => !toClose.includes(a))
    if (openAlert) {
      await escalateAlert(openAlert, 'NATIONAL')
      await wait(CONFIG.alertThrottleMs)
    }
    log(`Escalated: ${metrics.alertsEscalated}`)

    // ── 7. Daily digest fire → DAILY_REPORT email ────────────────
    await sendDailyDigest()
    log(`Daily digest sent: ${metrics.digestsSent}`)
  }

  // ── Summary ───────────────────────────────────────────────────────
  console.log('\n──────── SIMULATION SUMMARY ────────')
  console.log(JSON.stringify({
    primaries:     { ok: metrics.primariesOK,     fail: metrics.primariesFail     },
    secondaries:   { ok: metrics.secondariesOK,   fail: metrics.secondariesFail   },
    dispositions:  { ok: metrics.dispositionsOK,  fail: metrics.dispositionsFail  },
    alerts:        { ok: metrics.alertsOK,        fail: metrics.alertsFail        },
    closed:        metrics.alertsClosed,
    escalated:     metrics.alertsEscalated,
    digests:       metrics.digestsSent,
    failures_sample: metrics.failures.slice(0, 5),
  }, null, 2))
  if (metrics.failures.length) {
    console.log(`\n⚠ total failures: ${metrics.failures.length}`)
    process.exitCode = 1
  } else {
    console.log('\n✓ zero failures')
  }
}

main().catch(e => { console.error('HARNESS FAIL:', e.stack || e.message); process.exit(2) })
