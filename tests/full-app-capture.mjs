/**
 * tests/full-app-capture.mjs
 *
 * Full-app visual audit. Walks every meaningful screen, every wizard step,
 * every modal, every distinct scroll zone. Output is meant to support a
 * National TOT manual and a non-technical reader who has never opened the app.
 *
 * Output:
 *   _audit/PRESENTATION/full-app/screenshots/<NN>_<slug>.png
 *   _audit/PRESENTATION/full-app/INDEX.md       — prose user-manual gallery
 *   _audit/PRESENTATION/full-app/MANIFEST.json  — machine-readable manifest
 *
 * Hard rules honoured:
 *   - Output dir is wiped before the run.
 *   - Every coachmark / tour / overlay flag suppressed before first navigation.
 *   - Suppression flags removed inside the headless profile before close.
 *   - Wait times respected (1300ms after goto, 8000ms before /settings/diagnostics).
 *   - Multiple scroll positions per long screen.
 *   - md5 of adjacent screenshots compared; identical pairs retry with extra
 *     wait, then log a warning in MANIFEST if still identical.
 *   - Filled, working states for Primary + Secondary (real data seeded into IDB).
 *
 * Web-only constraints:
 *   - Sentinel feature flags OFF for the bulk of the run so plugin buttons
 *     don't render. A single visual-only pass at the end flips the master flag,
 *     captures the rendered Sentinel surface, and reverts. No plugin is invoked.
 */

import puppeteer from 'puppeteer'
import { mkdir, writeFile, rm, stat } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'
import { createHash } from 'node:crypto'

const __dirname = dirname(fileURLToPath(import.meta.url))
const REPO = resolve(__dirname, '..')
const BASE = process.env.BASE_URL || 'http://127.0.0.1:5173'
const ROOT = resolve(REPO, '_audit/PRESENTATION/full-app')
const SHOTS = resolve(ROOT, 'screenshots')
const INDEX_MD = resolve(ROOT, 'INDEX.md')
const MANIFEST = resolve(ROOT, 'MANIFEST.json')

// VIEWPORT — tall on purpose. Ionic styles `body { position: fixed; height: 100% }`
// and the entire ion-app stack uses absolute positioning, so the natural
// scrollable container is `<ion-content>`'s shadow `.inner-scroll`. In headless
// Chrome that shadow scroller reports zero dimensions for reasons we can't
// influence — slotted content is laid out in light DOM but the shadow's
// scrollHeight stays at 0. To work around it we make the viewport itself
// tall enough to render the longest page in full, then take 915-tall clips
// at different Y offsets to mimic what a user would see at each scroll
// position. SHOT_HEIGHT is the Pixel-6 viewport height we report in the manifest.
const SHOT_WIDTH = 412
const SHOT_HEIGHT = 915
const RENDER_HEIGHT = 4000
const VIEWPORT = { width: SHOT_WIDTH, height: RENDER_HEIGHT, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
const REPORTED_VIEWPORT = { width: SHOT_WIDTH, height: SHOT_HEIGHT, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 6) AppleWebKit/537.36 ' +
           '(KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36'

// Deterministic UUIDs so we can navigate to /secondary-screening/<known>
const NOTIF_UUID  = 'demo01-notif-4444-aaaa-555566667777'
const PRIMARY_SYMP_UUID = 'demo01-prim2-4444-bbbb-555566667777'
const PRIMARY_OK_UUID   = 'demo01-prim1-4444-cccc-555566667777'
const SECONDARY_UUID    = 'demo01-secondary4444-dddd-666677778888'
const ALERT_UUID        = 'demo01-alert-4444-eeee-777788889999'

// ── SUPPRESSION FLAGS ───────────────────────────────────────────────────────
// Single source of truth. Used by AUTH_SEED to set, by RESTORE_SCRIPT to clear.
// Tour / coachmark flags get cleared at the end (so the test profile mirrors
// a fresh first-run device for the next session). Sentinel feature flags are
// LEFT OFF — that is the production default; clearing them would be wrong.
const COACHMARK_FLAGS = [
  { key: 'cap.tour.seen_version',                value: '2026.04.24-wave1' },
  { key: 'tour.seen.primary.scan-intro',         value: '1' },
  { key: 'tour.seen.directory.first-open',       value: '1' },
  { key: 'tour.seen.caps-help.first-open',       value: '1' },
  { key: 'tour.seen.sentinel.first-toggle',      value: '1' },
  { key: 'tour.seen.secondary.voice-mic-intro',  value: '1' }, // not in §6 but found in source
  { key: 'tour.seen.welcome.replay',             value: '1' },
]
const SENTINEL_FLAGS = [
  { key: 'cap.sentinel.master.enabled', value: '0' },
  { key: 'cap.bcbp.enabled',            value: '0' },
  { key: 'cap.voice_wizard.enabled',    value: '0' },
  { key: 'cap.barcode.enabled',         value: '0' },
]

// ── Auth seed (NATIONAL_ADMIN) + suppression (runs on every fresh page) ─────
const AUTH_SEED = `(() => {
  try {
    const now = new Date().toISOString()
    sessionStorage.setItem('AUTH_DATA', JSON.stringify({
      id: 1, role_key: 'NATIONAL_ADMIN', country_code: 'ZM',
      poe_code: 'LUSKIA', district_code: 'LUSAKA', province_code: 'LSK',
      pheoc_code: 'LSK', full_name: 'Demo Walkthrough Admin',
      username: 'demo.admin', email: 'demo@example.org', phone: '',
      is_active: true, last_login_at: now, created_at: now, updated_at: now,
      email_verified_at: now, name: 'Demo Walkthrough Admin',
      _logged_in_at: now, _offline_login: false,
      _permissions: {
        view_dashboard: true, view_alerts: true, manage_alerts: true,
        view_primary_screening: true, capture_primary_screening: true,
        view_secondary_screening: true, capture_secondary_screening: true,
        view_users: true, manage_users: true, view_poes: true, manage_poes: true,
        view_settings: true, manage_settings: true, view_aggregated: true,
        manage_aggregated: true, view_sync: true, view_notifications: true,
        view_intelligence: true, view_matrix: true, view_history: true,
        view_profile: true, view_disease_intelligence: true,
        can_do_primary_screening: true,
      },
    }))
    ${[...COACHMARK_FLAGS, ...SENTINEL_FLAGS]
        .map(f => `try { localStorage.setItem('${f.key}', '${f.value}') } catch {}`).join('\n    ')}
  } catch {}
})()`

// ── IDB seed — every store, every field, exact names ─────────────────────────
const IDB_SEED = `(async () => {
  try {
    const NOTIF_UUID = '${NOTIF_UUID}'
    const PRIMARY_SYMP_UUID = '${PRIMARY_SYMP_UUID}'
    const PRIMARY_OK_UUID = '${PRIMARY_OK_UUID}'
    const SECONDARY_UUID = '${SECONDARY_UUID}'
    const ALERT_UUID = '${ALERT_UUID}'
    const now = new Date().toISOString()
    const yesterday = new Date(Date.now() - 86400000).toISOString()
    const weekAgo  = new Date(Date.now() - 7*86400000).toISOString()

    function open() {
      return new Promise((res, rej) => {
        const r = indexedDB.open('poe_offline_db')
        r.onsuccess = () => res(r.result)
        r.onerror = () => rej(r.error)
      })
    }
    function put(db, store, value) {
      return new Promise((res) => {
        try {
          const tx = db.transaction(store, 'readwrite')
          tx.objectStore(store).put(value)
          tx.oncomplete = () => res(true)
          tx.onerror = () => res(false)
        } catch { res(false) }
      })
    }
    let db
    try { db = await open() } catch { return }

    const primaryAsymp = {
      client_uuid: PRIMARY_OK_UUID, id: 101, server_id: 101, reference_data_version: 1,
      country_code: 'ZM', province_code: 'LSK', pheoc_code: 'LSK',
      district_code: 'LUSAKA', poe_code: 'LUSKIA', created_by_user_id: 1,
      traveler_direction: 'ENTRY', gender: 'FEMALE',
      traveler_full_name: 'Mary Banda', temperature_value: 36.7, temperature_unit: 'C',
      symptoms_present: 0, captured_at: yesterday,
      captured_timezone: 'Africa/Lusaka', referral_created: 0,
      record_status: 'COMPLETED', void_reason: null,
      device_id: 'demo-device', app_version: '1.1.1', platform: 'WEB',
      record_version: 1, sync_status: 'SYNCED', synced_at: yesterday,
      sync_attempt_count: 0, last_sync_error: null, sync_note: null,
      created_at: yesterday, updated_at: yesterday,
    }
    const primarySymp = {
      ...primaryAsymp, client_uuid: PRIMARY_SYMP_UUID, id: 102, server_id: 102,
      gender: 'MALE', traveler_full_name: 'Joseph Phiri',
      temperature_value: 38.6, symptoms_present: 1, referral_created: 1,
      captured_at: now, created_at: now, updated_at: now,
      sync_status: 'UNSYNCED', synced_at: null,
    }

    const notification = {
      client_uuid: NOTIF_UUID, id: 201, server_id: 201, reference_data_version: 1,
      country_code: 'ZM', province_code: 'LSK', pheoc_code: 'LSK',
      district_code: 'LUSAKA', poe_code: 'LUSKIA',
      primary_screening_id: PRIMARY_SYMP_UUID, primary_uuid: PRIMARY_SYMP_UUID,
      created_by_user_id: 1, notification_type: 'SECONDARY_REFERRAL',
      status: 'OPEN', priority: 'HIGH',
      reason_code: 'PRIMARY_SYMPTOMS_DETECTED',
      reason_text: 'Symptoms present. Direction: ENTRY. Sex: MALE. Temp: 38.6 deg C. Priority: HIGH.',
      assigned_role_key: 'SCREENER', assigned_user_id: null,
      opened_at: null, closed_at: null,
      device_id: 'demo-device', app_version: '1.1.1', platform: 'WEB',
      record_version: 1,
      gender: 'MALE', traveler_full_name: 'Joseph Phiri',
      traveler_direction: 'ENTRY', temperature_value: 38.6, temperature_unit: 'C',
      captured_at: now, screener_name: 'Demo Walkthrough Admin',
      sync_status: 'UNSYNCED', synced_at: null,
      sync_attempt_count: 0, last_sync_error: null,
      created_at: now, updated_at: now,
    }

    // Secondary case seeded as DISPOSITIONED so Step 4 (Analysis) is reachable
    // from the step pills. Field names match SecondaryScreening.vue lookups:
    //   - notification_id (the index field on the store), NOT notification_uuid
    //   - traveler_full_name / traveler_gender / traveler_age_years
    //   - is_present (1/0/null), NOT response, on the symptom rows
    //   - final_disposition, NOT disposition, on the case
    const secondary = {
      client_uuid: SECONDARY_UUID,
      notification_id: NOTIF_UUID,
      reference_data_version: 1,
      country_code: 'ZM', province_code: 'LSK', pheoc_code: 'LSK',
      district_code: 'LUSAKA', poe_code: 'LUSKIA',
      primary_screening_id: PRIMARY_SYMP_UUID,
      created_by_user_id: 1, opened_by_user_id: 1,
      traveler_full_name: 'Joseph Phiri',
      traveler_gender: 'MALE',
      traveler_age_years: 34,
      travel_document_type: 'Passport',
      travel_document_number: 'ZN1234567',
      traveler_nationality_country_code: 'ZM',
      residence_country_code: 'ZM',
      phone_number: '+260977000222',
      journey_start_country_code: 'CD',
      conveyance_type: 'ROAD',
      conveyance_identifier: 'BUS-LUS-44',
      arrival_datetime: yesterday,
      purpose_of_travel: 'BUSINESS',
      destination_district_code: 'LUSAKA',
      temperature_value: 38.6, temperature_unit: 'C',
      pulse_rate: 92, respiratory_rate: 22,
      bp_systolic: 128, bp_diastolic: 82,
      oxygen_saturation: 96, triage_category: 'YELLOW',
      emergency_signs_present: 0, general_appearance: 'Mildly unwell, alert, oriented.',
      symptoms_summary: 'Cough, fever for 3 days. No haemorrhagic signs.',
      onset_date: yesterday.slice(0, 10),
      exposure_summary: 'Visited DRC border market within past 14 days.',
      syndrome_classification: 'RESPIRATORY',
      risk_level: 'HIGH', routing_level: 'PHEOC',
      ihr_alert_required: 1,
      case_status: 'DISPOSITIONED',
      final_disposition: 'REFERRED',
      officer_notes: 'Referred to district hospital for further evaluation. Suspected influenza-like illness with travel-related exposure.',
      followup_required: 1, followup_assigned_level: 'DISTRICT',
      dispositioned_at: now, closed_at: null,
      device_id: 'demo-device', app_version: '1.1.1', platform: 'WEB',
      record_version: 3, sync_status: 'UNSYNCED', synced_at: null,
      sync_attempt_count: 0, last_sync_error: null,
      created_at: yesterday, updated_at: now,
    }

    const sym1 = {
      client_uuid: 'demo01-sym1-4444-aaaa-1', secondary_screening_id: SECONDARY_UUID,
      symptom_code: 'COUGH', is_present: 1,
      onset_date: yesterday.slice(0, 10), details: 'Dry cough, no sputum.',
      sync_status: 'UNSYNCED', created_at: yesterday, updated_at: now,
    }
    const sym2 = {
      client_uuid: 'demo01-sym2-4444-aaaa-2', secondary_screening_id: SECONDARY_UUID,
      symptom_code: 'FEVER', is_present: 1,
      onset_date: yesterday.slice(0, 10), details: 'Subjective fever for 3 days.',
      sync_status: 'UNSYNCED', created_at: yesterday, updated_at: now,
    }
    const exp1 = {
      client_uuid: 'demo01-exp1-4444-aaaa-1', secondary_screening_id: SECONDARY_UUID,
      exposure_code: 'CONTACT_MARKET_LIVESTOCK', response: 'YES',
      details: 'Visited DRC border livestock market 5 days ago.',
      sync_status: 'UNSYNCED', created_at: yesterday, updated_at: now,
    }
    const country1 = {
      client_uuid: 'demo01-tcountry-4444-aaaa-1', secondary_screening_id: SECONDARY_UUID,
      country_code: 'CD', visited_from: weekAgo.slice(0, 10),
      visited_to: yesterday.slice(0, 10),
      sync_status: 'UNSYNCED', created_at: yesterday, updated_at: now,
    }
    const susp1 = {
      client_uuid: 'demo01-disease-4444-aaaa-1', secondary_screening_id: SECONDARY_UUID,
      disease_code: 'INFLUENZA', rank_order: 1, confidence: 0.42,
      reasoning: 'ALGORITHM_TOP', sync_status: 'UNSYNCED',
      created_at: now, updated_at: now,
    }
    const susp2 = {
      client_uuid: 'demo01-disease-4444-aaaa-2', secondary_screening_id: SECONDARY_UUID,
      disease_code: 'COVID19', rank_order: 2, confidence: 0.27,
      reasoning: 'ALGORITHM_NEXT', sync_status: 'UNSYNCED',
      created_at: now, updated_at: now,
    }

    const alert = {
      client_uuid: ALERT_UUID, id: 301, server_id: 301, reference_data_version: 1,
      country_code: 'ZM', province_code: 'LSK', pheoc_code: 'LSK',
      district_code: 'LUSAKA', poe_code: 'LUSKIA',
      alert_code: 'IHR-1', alert_title: 'Suspected respiratory cluster',
      risk_level: 'HIGH', status: 'OPEN', routed_to_level: 'PHEOC',
      raised_by: 'rule', raised_at: now, ack_at: null, closed_at: null,
      ihr_alert_required: 1,
      reason_text: 'Three symptomatic cases at LUSKIA in past 24h.',
      device_id: 'demo-device', app_version: '1.1.1', platform: 'WEB',
      record_version: 1, sync_status: 'UNSYNCED',
      created_at: now, updated_at: now,
    }

    const aggTemplate = {
      id: 1, template_name: 'Daily POE Arrivals',
      template_code: 'DAILY_POE_V1', reporting_frequency: 'DAILY',
      is_published: 1, is_enabled: 1, version: 3,
      columns: [
        { column_key: 'total_screened', label: 'Total Screened', data_type: 'INTEGER', is_enabled: 1, category: 'COUNTS', display_order: 1 },
        { column_key: 'total_symptomatic', label: 'Symptomatic', data_type: 'INTEGER', is_enabled: 1, category: 'COUNTS', display_order: 2 },
        { column_key: 'total_asymptomatic', label: 'Asymptomatic', data_type: 'INTEGER', is_enabled: 1, category: 'COUNTS', display_order: 3 },
        { column_key: 'total_male', label: 'Male', data_type: 'INTEGER', is_enabled: 1, category: 'GENDER', display_order: 4 },
        { column_key: 'total_female', label: 'Female', data_type: 'INTEGER', is_enabled: 1, category: 'GENDER', display_order: 5 },
      ],
      cached_at: now, created_at: yesterday, updated_at: now,
    }
    const aggSubmission = {
      client_uuid: 'demo01-sub-4444-aaaa-1', id: 5001, template_id: 1,
      country_code: 'ZM', province_code: 'LSK', pheoc_code: 'LSK',
      district_code: 'LUSAKA', poe_code: 'LUSKIA',
      created_by_user_id: 1, user_full_name: 'Demo Walkthrough Admin',
      period_start: weekAgo.slice(0,10), period_end: now.slice(0,10),
      total_screened: 142, total_symptomatic: 18, total_asymptomatic: 124,
      total_male: 71, total_female: 71,
      notes: 'Routine weekly aggregated submission for LUSKIA POE.',
      device_id: 'demo-device', app_version: '1.1.1', platform: 'WEB',
      record_version: 2, sync_status: 'SYNCED', synced_at: now,
      sync_attempt_count: 0, last_sync_error: null, deleted_at: null,
      is_local_draft: false, created_at: yesterday, updated_at: now,
    }

    const poeContact = {
      client_uuid: 'demo01-cont-4444-aaaa-1', id: 401,
      poe_code: 'LUSKIA', country_code: 'ZM',
      contact_name: 'Esther Mwale', role_title: 'POE Health Officer',
      phone: '+260977000111', email: 'esther.mwale@example.org',
      escalation_order: 1, is_active: 1,
      created_at: yesterday, updated_at: now, sync_status: 'SYNCED',
    }

    await put(db, 'primary_screenings', primaryAsymp)
    await put(db, 'primary_screenings', primarySymp)
    await put(db, 'notifications', notification)
    await put(db, 'secondary_screenings', secondary)
    await put(db, 'secondary_symptoms', sym1)
    await put(db, 'secondary_symptoms', sym2)
    await put(db, 'secondary_exposures', exp1)
    await put(db, 'secondary_travel_countries', country1)
    await put(db, 'secondary_suspected_diseases', susp1)
    await put(db, 'secondary_suspected_diseases', susp2)
    await put(db, 'alerts', alert)
    await put(db, 'aggregated_templates_cache', aggTemplate)
    await put(db, 'aggregated_submissions', aggSubmission)
    await put(db, 'poe_notification_contacts', poeContact)
  } catch (e) {
    console.warn('IDB seed failed', e?.message)
  }
})()`

// ── Restore — wipe coachmark flags so the next session is fresh ─────────────
// Sentinel flags are LEFT OFF (production default).
const RESTORE_SCRIPT = `(() => {
  try {
    ${COACHMARK_FLAGS.map(f => `try { localStorage.removeItem('${f.key}') } catch {}`).join('\n    ')}
  } catch {}
})()`

// ── helpers ────────────────────────────────────────────────────────────────

function slug(s) {
  return String(s).replace(/[^a-zA-Z0-9._-]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60) || 'shot'
}
const sleep = (ms) => new Promise(r => setTimeout(r, ms))

async function ensureDirs() {
  if (!existsSync(ROOT)) await mkdir(ROOT, { recursive: true })
  try { await rm(SHOTS, { recursive: true, force: true }) } catch {}
  await mkdir(SHOTS, { recursive: true })
}

async function clickByText(page, text, opts = {}) {
  const tag = opts.tag || 'button'
  return await page.evaluate(({ text, tag }) => {
    const list = [...document.querySelectorAll(tag)]
    const t = text.toLowerCase()
    const m = list.find(el => (el.textContent || '').trim().toLowerCase() === t)
              || list.find(el => (el.textContent || '').trim().toLowerCase().includes(t))
    if (m) { m.scrollIntoView({ block: 'center' }); m.click(); return true }
    return false
  }, { text, tag })
}

// scrollTo — records the desired clip-Y for the next screenshot. With a
// 4000-tall viewport every page lays out in full, so we don't need to
// actually scroll anything; the screenshot's clip rect determines what slice
// of the rendered page becomes the shot. We also trigger any IntersectionObserver
// based lazy renders by briefly scrolling the inner-scroll if it exists.
async function scrollTo(page, y) {
  page._clipY = Math.max(0, Math.floor(y))
  await page.evaluate(async (y) => {
    // Best-effort: nudge any intersection observers / virtual lists watching
    // the ion-content scroller. We don't depend on this for the shot itself.
    try {
      const c = document.querySelector('ion-content')
      if (c?.scrollToPoint) { try { await c.scrollToPoint(0, y, 0) } catch {} }
      const inner = c?.shadowRoot?.querySelector?.('.inner-scroll')
      if (inner) inner.scrollTop = y
    } catch {}
  }, y)
  await new Promise(r => setTimeout(r, 450))
}

// Defensive overlay sweep — Escape, click any "Close/Got it/Skip/Dismiss"
// button, hide anything class-named "tour" / "coach". Runs before every shot.
async function dismissOverlays(page) {
  try { await page.keyboard.press('Escape') } catch {}
  await page.evaluate(() => {
    const close = [...document.querySelectorAll('button')]
      .find(b => /^(got it|close|skip|dismiss|continue|next)$/i.test((b.textContent || '').trim()))
    close?.click()
    document.querySelectorAll('[class*="tour"], [class*="coach"], .lm-overlay--tour')
      .forEach(el => { try { el.style.display = 'none' } catch {} })
  })
}

// md5 of buffer — used to detect adjacent-shot identity (failed prep).
function md5(buf) {
  return createHash('md5').update(buf).digest('hex')
}

// ── shot plan ──────────────────────────────────────────────────────────────
// stage:
//   'login'  — visit without auth seeded
//   'route'  — fresh page, seeded auth, goto, settle, screenshot
//   'menu'   — same as 'route' then open the side menu drawer
//   'action' — re-use the previous page (no goto), run prep, screenshot
//
// Each entry's `prose` is a self-contained paragraph for INDEX.md. It
// answers in order: (1) what is on the screen, (2) what each control does,
// (3) when the user sees it, (4) why this exists. No labels, no captions.

const PLAN = [
  // ── Login ────────────────────────────────────────────────────────────────
  { stage: 'login', route: '/home',
    prose: 'The first thing every user sees on a cold-start of the app: a sign-in overlay laid on top of an atmospheric airport-terminal photograph. The username field accepts the operator id issued by the national administrator, and the password field accepts the password they were given during onboarding. Below the password field is a Sign in button that validates the credentials, dismissing the overlay and revealing the home dashboard. If the device has no network the app falls back to validating against the locally cached password hash from the most recent successful sign-in, which is what makes the rest of the app work fully offline. This is the reason the screen exists at all: every record the app stores carries the signed-in user as its author, so the system cannot let any work begin until it knows who is using it.' },
  { stage: 'login', route: '/home',
    prep: async (page) => {
      await page.evaluate(() => {
        const u = document.querySelector('input[autocomplete="username"], input[name="username"], input[type="text"]')
        const p = document.querySelector('input[type="password"]')
        if (u) { u.focus(); u.value = 'demo.admin'; u.dispatchEvent(new Event('input', { bubbles: true })) }
        if (p) { p.value = 'demo-pass-1234'; p.dispatchEvent(new Event('input', { bubbles: true })) }
      })
    },
    prose: 'The same sign-in overlay after a user has typed their credentials. The username field shows the operator id and the password field shows the masked password (the eye-icon, when present, toggles between masked and plain text so the user can confirm what they typed). The Sign in button is now active because both fields are populated. When the user taps Sign in, the app first tries the live server; if the network is down it tries the cached password hash from a previous successful login. Either way, on success the overlay dismisses, the app remounts with the user identity loaded into session storage, and every record from this point forward is stamped with that user.' },

  // ── Welcome briefing ────────────────────────────────────────────────────
  { stage: 'route', route: '/welcome',
    prose: 'A role-aware first-run guide that opens the moment a user signs in. The header reads the operator\'s name and role and the lead sentence underneath changes to match: a national administrator sees "National oversight is active", a screener sees "Screening station ready", a data officer sees "Data officer — aggregated reporting", and so on. The screen exists to give people a one-paragraph orientation appropriate to what they will actually be doing today, instead of dropping them straight onto the dashboard. The user can come back here at any time from the side menu under Welcome.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 600) },
    prose: 'The middle of the welcome briefing, showing a row of primary tiles that each lead to a feature the signed-in role is allowed to use. A national administrator sees every tile; a screener sees only screening-related tiles; a data officer sees aggregated reporting and history; and so on. Each tile is a single tap that takes the user straight to that feature. The page exists so a brand-new user can find their first task without having to memorise the side-menu layout.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1400) },
    prose: 'The bottom of the welcome briefing, showing the How-to guides — short labelled cards that link to the most common workflows. "Run your first primary screen" jumps to the capture form, "Pick up a secondary referral" jumps to the notifications inbox, and "Push your offline queue" jumps to sync. The cards are the answer to the question "I have signed in, now what do I do first." Tapping any card lands the user on the right page with the right tab pre-selected.' },

  // ── Home dashboard ──────────────────────────────────────────────────────
  { stage: 'route', route: '/home',
    prose: 'The home dashboard, top of page. A status strip across the top reads in plain language ("Operating normally", or "Working offline" if the device has lost network) so a non-technical user can tell at a glance whether the app is healthy. Underneath the strip is a hero capture button reserved for screener-class roles — national administrators do not see it because they don\'t do screening themselves. Below that is a triptych of three counters: Open Referrals, Open investigations, and Health alerts. The whole page is the user\'s personal launchpad for the day; the figures refresh on a thirty-second timer.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 800) },
    prose: 'Further down the home dashboard, where a recent-activity feed lists the last screenings and notifications captured at this device. Each row is a one-line summary: traveller name, time, what was captured, sync state. A small "Updated Xm ago" freshness label sits at the top of the feed alongside a manual refresh button so a user who has been offline can pull a fresh read without reloading the page. When the feed is empty or errored it says so plainly, rather than leaving the user staring at an empty container. The feed is here so an operator can confirm that work they thought they captured actually landed in the app.' },
  { stage: 'menu', route: '/home',
    prose: 'The side-menu drawer slid open over the home dashboard. The top of the drawer is an identity panel — avatar, full name, role chip, and the geographic scope the user is allowed to see (country, province, district, point of entry). Below that the navigation is grouped into Welcome, Dashboard, POE Management, Disease Management, User Management, Screening, Alerts, Aggregated Data, Sync, Coordination, and Account & Settings, with Sign Out at the bottom. The drawer is the primary way users navigate around the app once they\'re past the welcome screen, and it respects the role: items the user has no permission to use are not rendered.' },

  // ── Primary Screening ───────────────────────────────────────────────────
  { stage: 'route', route: '/PrimaryScreening',
    prose: 'The primary screening surface, opened on the Capture tab. Three tabs run along the top — Capture, Records, Referral Queue — and the user starts on Capture. The form leads with a row of Direction pills (Entry, Exit, Transit), which is the screener\'s very first decision because it determines how the rest of the form is interpreted. The whole page is built to be operated one-tap-at-a-time so a screener at a busy port can clear travellers without dragging out the on-screen keyboard. This screen is the highest-volume screen in the app — most of the country\'s daily data passes through it.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 350) },
    prose: 'The middle of the capture form. After Direction the user picks Sex with another pair of pills. Optional fields follow: a temperature input (defaulting to Celsius), an optional traveller-name + passport tile (for cases where the screener wants to record more detail), and a Symptoms YES/NO toggle that drives the rest of the form. The whole pattern is "tap, tap, tap, done" — keyboards only appear when free-text genuinely matters. The form is ordered this way because Direction, Sex, Temperature, and Symptoms are the four facts every WHO/IHR primary screen has to capture; everything else is optional.' },
  { stage: 'action',
    prep: async (page) => {
      await clickByText(page, '→ Entry')
      await sleep(150)
      await clickByText(page, '♂ Male')
      await sleep(150)
      await page.evaluate(() => {
        const t = document.querySelector('input[type="number"]') || document.querySelector('input[inputmode="decimal"]')
        if (t) { t.focus(); t.value = '38.6'; t.dispatchEvent(new Event('input', { bubbles: true })) }
      })
      await sleep(200)
      await clickByText(page, 'Yes')
      await sleep(250)
      await scrollTo(page, 600)
    },
    prose: 'The capture form in its symptomatic state — Direction set to Entry, Sex set to Male, a temperature of 38.6°C entered (above the 38.0 fever threshold), and Symptoms set to Yes. The form has reacted: the Capture button at the bottom has shifted to a red "create referral" style with a warning sentence underneath ("Will file a SECONDARY_REFERRAL on capture."). When the screener taps it the app commits the screening record and the referral notification together in a single atomic IndexedDB transaction, so the secondary officer sees the case immediately and the data can never get half-saved. This is the most consequential interaction in the app — it is the moment a traveller is flagged for further investigation.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 0); await clickByText(page, 'Records', { tag: 'button' }); await sleep(900) },
    prose: 'The Records tab inside primary screening. This is the screener\'s personal case register — every primary record they have ever captured at this device, in reverse-chronological order. A row of filter chips pins to the top (All, Today, This week, Symptomatic, Sync state) and a search box sits above the list for traveller name. Each card shows the traveller, captured time, temperature, a symptoms badge, and a sync-state badge so the user can see at a glance whether the row has been uploaded. The tab exists so a screener can find a record they captured an hour ago without leaving the screening surface.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 600) },
    prose: 'The Records tab scrolled down to older rows. The list paginates as the user scrolls, loading in batches so the browser doesn\'t have to render thousands of cards at once. A tap on a card opens a read-only detail modal; a long-press surfaces a void affordance. The view exists for a screener who needs to look back over the last few days of work — for example, when reconciling counts at end-of-shift or correcting a record that was captured against the wrong direction.' },
  { stage: 'action',
    prep: async (page) => {
      await page.evaluate(() => {
        const card = document.querySelector('.ps-rec-card')
        card?.click()
      })
      await sleep(900)
    },
    prose: 'The detail modal for a single primary screening record, slid up from the bottom of the screen. It shows every field captured: traveller name, captured time, direction, sex, temperature, symptoms state, whether a referral was raised, sync state, the user who captured it, and the device id. Most of the modal is read-only — by design, because a primary record is supposed to be a faithful record of what was observed at the moment of capture, not an editable draft. The Void Record button at the bottom is the one mutation possible from here, and it opens a separate void-with-reason flow that requires the user to pick a reason before the void commits.' },
  { stage: 'action',
    prep: async (page) => {
      await dismissOverlays(page)
      await page.evaluate(() => document.querySelector('ion-modal')?.dismiss?.())
      await sleep(500)
      await clickByText(page, 'Referral Queue', { tag: 'button' })
      await sleep(900)
    },
    prose: 'The Referral Queue tab inside primary screening. Where Records is the screener\'s own case register, this tab shows the same data the secondary officer sees — but scoped to referrals that originated at this device. It exists so a screener can confirm that every symptomatic case they raised has been picked up downstream, and to give them a quick way to verify the queue isn\'t backing up. Each row is a referral with a priority pill and the time it was raised; tapping a row would open the case in read-only mode.' },

  // ── Primary Screening Records (standalone view) ─────────────────────────
  { stage: 'route', route: '/primary-screening/records',
    prose: 'The standalone primary-screening records view, reachable from the side menu. Where the Records tab inside the capture screen shows a screener\'s own work, this view is the broader register: every primary record visible to the user\'s scope. The same filter chips appear at the top (All, Today, This week, Symptomatic, sync state), and the same record cards render below. The view exists because supervisors and national administrators need to look at primary records without going through the capture form first — it is the read-mostly equivalent of the capture view\'s Records tab.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 600) },
    prose: 'The standalone primary-screening records view, scrolled down. The paginated list continues below the fold. A tap on any card opens the same detail modal seen elsewhere; the modal is shared across every place primary records are listed, so the layout is identical wherever the user lands.' },

  // ── Screening Intelligence Dashboard ────────────────────────────────────
  { stage: 'route', route: '/screening-dashboard',
    prose: 'The screening intelligence dashboard, top of page. A row of filter chips (Today, This week, This month, This year, Custom) selects the window the rest of the page summarises. The hero element below is a ring chart that shows the symptomatic rate as a percentage with a plain-language interpretation sentence below it ("Of every 100 travellers screened, X had symptoms.") so a reader does not have to think about percentages. A small colour legend explains the green/amber/red bands. The dashboard exists for supervisors and national administrators who want to read the day at a glance without opening individual records.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 700) },
    prose: 'The middle of the screening intelligence dashboard, where an eight-cell quick-stats grid sits below the hero ring. Each cell carries a whole-word label — Completed, With symptoms, Sent for review, Fever today, Open referrals, Open alerts, Not uploaded, Cancelled — with a count and a small comparison against yesterday. If the engine has detected any signals (a high-fever cluster, a queue overflow), they appear as cards immediately below this grid. The grid exists because a single ring chart is not enough to tell the whole shift story; the eight cells together give a reader the headline figures in under five seconds.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1500) },
    prose: 'Further down the dashboard, where a weekly grid + trend chart sits. The grid breaks the chosen period into days, with a row each for Screened, Symptoms %, Sent for review, Fever, Daily average, and a comparison to last week. The chart underneath uses two lines — solid for total screened, dashed for symptomatic — so a reader can see at a glance whether the symptomatic rate is climbing relative to volume. The view is here so a supervisor can spot a developing pattern (a slow climb in fevers, for example) before it becomes an alert.' },
  { stage: 'action',
    prep: async (page) => {
      await page.evaluate(() => document.querySelector('.sd-ft, button.sd-ft')?.click())
      await sleep(700)
    },
    prose: 'The dashboard with the advanced filter expanded. The user can pick a date, a month chip, a year chip, or a from-to range. Apply runs the dashboard against the chosen window without leaving the page; Download report (top-right of the page) exports the same window as a multi-page PDF formatted for routine WHO reporting. The filter exists for the moments where "this week" is not the right window — month-end reports, ad-hoc audits, and back-fill checks.' },

  // ── Notifications + Secondary Screening (the full 4-step wizard) ────────
  { stage: 'route', route: '/NotificationsCenter',
    prose: 'The secondary-referral inbox, called the Notifications Centre throughout the app. It lists every open referral routed to the signed-in user\'s scope, with critical priorities pinned to the top. Each card carries the traveller, the originating point of entry, captured time, and a priority pill (Routine, Urgent, Emergency). Open takes the secondary officer into the case wizard; Cancel closes the referral when no further action is needed. This is the secondary officer\'s equivalent of the screener\'s capture form — it is where their work day starts.' },
  { stage: 'route', route: '/secondary-screening/' + NOTIF_UUID,
    prose: 'Step 1 of the four-step secondary investigation wizard, opened by tapping a referral. Across the top sits a four-step progress bar (Profile, Symptoms, Exposures, Analysis); the user can tap any step to jump there once they have advanced past it at least once. Step 1 captures the traveller\'s profile: full name, sex, age and unit (years/months), nationality, country of residence, passport details, occupation, language preferences. The wizard is the implementation of the WHO/IHR secondary investigation pattern — the four steps map exactly to the four parts of an IHR-aligned case investigation.' },
  { stage: 'action',
    prep: async (page) => {
      await page.evaluate(() => {
        const pills = [...document.querySelectorAll('.sc-step, button.sc-step')]
        pills[1]?.click()
      })
      await sleep(1000)
    },
    prose: 'Step 2 of the wizard, Symptoms. A multi-select symptom checklist organised by category — general, respiratory, gastrointestinal, neurological, dermatological, and haemorrhagic — so the officer reads down a familiar grouping rather than scanning a flat list. Each row captures three things: the response (Yes, No, Unknown), an onset date, and severity. The engine that runs in Step 4 reads these rows directly, so the quality of analysis depends on the care taken here. The step exists because primary screening only asks "any symptoms yes/no"; secondary needs to know which specific symptoms, when they started, and how bad they are.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 800) },
    prose: 'Step 2 scrolled down, where the lower symptom categories continue. Save & Next at the bottom commits the symptoms to the case record and advances the wizard. Back returns to Step 1 without saving. Forward jumps (using the step pills at the top to skip ahead) are allowed once the user has advanced past a step at least once, because the wizard tracks the highest step ever reached for the case. The pattern keeps a junior officer from accidentally skipping the symptom inventory the first time they open a case.' },
  { stage: 'action',
    prep: async (page) => {
      await scrollTo(page, 0)
      await page.evaluate(() => {
        const pills = [...document.querySelectorAll('.sc-step, button.sc-step')]
        pills[2]?.click()
      })
      await sleep(1000)
    },
    prose: 'Step 3 of the wizard, Exposures. The page captures travel history (countries the traveller has been in over the past 14 days) and exposure events (sick contact, livestock contact, healthcare exposure, mass gathering, and so on). The country list is searchable and cross-references the disease engine\'s endemic-zone map, so a country selection here can change the engine\'s ranking on the next step. The page exists because a respiratory case from a country with active Marburg surveillance is a very different signal from a respiratory case from a country with no haemorrhagic-fever activity, and the engine needs the country list to make that distinction.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 600) },
    prose: 'Step 3 scrolled down, where the exposure-code list continues with response and free-text details for each row. Save & Next commits the exposures, runs the diagnostic engine, and advances to Step 4. The engine pass at this point produces the syndrome classification, the IHR risk level, the routing destination, and the ranked suspected-disease list — so the user feels the wizard "thinking" between Step 3 and Step 4 even though the data has already been gathered.' },
  { stage: 'action',
    prep: async (page) => {
      await scrollTo(page, 0)
      await page.evaluate(() => {
        const pills = [...document.querySelectorAll('.sc-step, button.sc-step')]
        pills[3]?.click()
      })
      await sleep(1500)
    },
    prose: 'Step 4 of the wizard, Analysis. This is the engine output: the IHR risk level (Low, Medium, High), the routing destination (Point of Entry, District, PHEOC, National), the suspected-disease list ranked by probability with confidence scores, and a syndrome classification (respiratory, gastrointestinal, haemorrhagic, neurological, dermatological, and so on). Below the engine output is a short panel of recommended actions matched to the syndrome and risk level. The officer can override the engine\'s risk level and routing destination — the engine is a recommendation, not a decision — and their override is recorded as part of the case audit trail.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 800) },
    prose: 'Step 4 scrolled down to the disposition controls at the bottom of the page. A radio group offers four options — Released, Quarantined, Isolated, Referred — and a free-text field captures the officer\'s notes for the case. Disposition case at the very bottom commits the case to the DISPOSITIONED state, writes a sync row, and locks the wizard against further edits (a re-open from the records view is required to change anything afterwards). The disposition is the moment the case officially closes from the secondary officer\'s perspective and is handed over to the routing destination chosen on this step.' },

  // ── Secondary records ───────────────────────────────────────────────────
  { stage: 'route', route: '/secondary-screening/records',
    prose: 'The secondary-screening case register. Every full investigation that has ever been opened or completed in the user\'s scope appears here, regardless of who opened it. Filter chips along the top sort by status (Waiting, Being worked on, Done, Decision made), risk level, and syndrome. Each row carries the traveller, the case status, the risk level, and a one-line outcome summary. Tapping a row opens a detail modal showing the full case timeline — every step the case passed through, every officer who touched it, the engine output, and the final disposition. The view is the answer to "what investigations have we done at this site over the last week."' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 500) },
    prose: 'The secondary case register scrolled down to older entries. The list paginates as the user scrolls. The detail modal opened from any row is read-only by default, with a Re-open affordance at the bottom for a supervisor who needs to amend a finding — re-opening writes a new audit row rather than overwriting the original disposition.' },

  // ── Alerts ──────────────────────────────────────────────────────────────
  { stage: 'route', route: '/alerts',
    prose: 'The active-alerts page. An alert is a system-generated signal that something needs attention, raised either automatically (by a rule that watched a count cross a threshold) or manually (by an officer escalating a case). Each row carries a title, a risk pill, an age (how long the alert has been open), the originating point of entry, and the level it has been routed to. Tap to acknowledge or escalate. The page exists for supervisors and PHEOC officers who are watching for events the front-line screeners are too busy to flag themselves.' },
  { stage: 'route', route: '/alerts/intelligence',
    prose: 'The alert intelligence page, top of view. The page is titled "Outbreak response timeliness" — the plain-language framing of what the page actually measures. A row of KPIs sits at the top: Within 24h notice (target 8 in 10), Within 7d response, Top-priority count, Targets missed. Each KPI carries a small sub-line explaining what the number means. A colour legend pinned beneath the KPIs explains the green/amber/red bands. The page exists for the PHEOC and national levels, who care less about individual alerts and more about whether the response pipeline as a whole is keeping up.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 700) },
    prose: 'Further down the alert intelligence page, where the rolling rates for the three response-timeliness stages are rendered as three stage cards: Detect, Notify, Respond. Each card shows whether the stage is currently computable (i.e., whether enough data exists for a meaningful rate), the current rolling rate, and a small footnote citing the WHO/IHR article the metric derives from. The cards are how a non-technical reader can understand why this number matters: each one ties to a treaty obligation rather than a vendor metric.' },
  { stage: 'route', route: '/alerts/history',
    prose: 'The alerts history page. Acknowledged and closed alerts with a full audit trail — who acknowledged the alert, when, what action they took, and what comment they left. Closed alerts cannot be re-opened from this view; the page is read-only. It exists for after-action review and for the audit-trail evidence required by the IHR notification process.' },
  { stage: 'route', route: '/alerts/matrix',
    prose: 'The WHO IHR Annex 2 reference matrix. A read-only catalogue showing which diseases trigger Tier 1 (always notify WHO immediately) versus Tier 2 (notify if certain criteria are met). The matrix is PII-free and works fully offline. The page is here as a reference users can consult mid-case — it does not affect any record or trigger any action; it is purely a quick-lookup of treaty obligations.' },

  // ── Aggregated Hub + Wizard + History ───────────────────────────────────
  { stage: 'route', route: '/aggregated-data',
    prose: 'The aggregated-data hub. The hub lists every aggregated-reporting template the user is allowed to submit against, grouped by frequency (Daily, Weekly, Monthly, Quarterly, Ad-hoc, Event-based). Each card shows the template name, a frequency badge, the period to cover next, and the status of the most recent submission. Submit opens the dynamic submission wizard for that template. The hub exists because a single point of entry can have several reporting obligations on different cadences, and the user needs one place where every "you owe a report" item lives.' },
  { stage: 'route', route: '/aggregated-data/new/1',
    prose: 'Step 1 of the aggregated submission wizard, opened by tapping Submit on a template. The step is titled "Tell us what period this report covers" and shows two date pickers (Start and End). The wizard validates that End is on or after Start and that neither date is in the future; Next is disabled until both dates pass validation. The step exists separately from the data step so the user has to commit to a period before the dynamic form is rendered — which lets the wizard render only the columns relevant to that period (some templates change columns over time).' },
  { stage: 'action',
    prep: async (page) => {
      await page.evaluate(() => {
        const inputs = document.querySelectorAll('input[type="date"]')
        if (inputs[0]) { inputs[0].value = '2026-04-19'; inputs[0].dispatchEvent(new Event('input', { bubbles: true })) }
        if (inputs[1]) { inputs[1].value = '2026-04-25'; inputs[1].dispatchEvent(new Event('input', { bubbles: true })) }
      })
      await sleep(250)
      await clickByText(page, 'Next')
      await sleep(1000)
    },
    prose: 'Step 2 of the aggregated submission wizard. After picking a period the wizard renders the template\'s columns grouped by category — here, Counts and Gender. Each field has a label, an integer input, and an inline auto-calculate hint where the relationship between fields is known (for example, Total = Symptomatic + Asymptomatic). The Auto-calculate button at the top tries to fill the counts from the user\'s own primary screenings over the chosen period, which is the path most users take. The step is the heart of the wizard — everything before it is set-up; everything after it is review.' },
  { stage: 'route', route: '/aggregated-data/history',
    prose: 'The aggregated-submission history page. Filter chips at the top (30 days, 90 days, This year, All) narrow the list. Each card shows the period range of the submission, the headline counts, the symptomatic rate, the gender breakdown, and a plain "Uploaded" or "Waiting to upload" badge. The page exists so a data officer can confirm a submission landed correctly and audit the counts they reported in past periods — both of which are routine end-of-month tasks.' },
  { stage: 'action',
    prep: async (page) => {
      await page.evaluate(() => {
        const cards = [...document.querySelectorAll('.ag-card, [class*="ag-card"]')]
        cards[0]?.click()
      })
      await sleep(1000)
    },
    prose: 'The detail page for a single aggregated submission. Period and headline counts at the top, gender breakdown below, then a green-tick checklist of quality checks ("Male + Female adds up to total screened ✓", "With-symptoms + No-symptoms adds up to total screened ✓", "Reporting period covers 7 days ✓"). The schema metadata (server id, record version, app version, last edit) is hidden behind a Technical details disclosure so a non-technical reader sees the meaningful figures first. The page exists to let a data officer answer "did the report I sent reconcile" without reaching for a calculator.' },
  { stage: 'action',
    prep: async (page) => {
      await page.evaluate(() => {
        const det = [...document.querySelectorAll('details.ag-tech, details')].find(d => /technical/i.test(d.querySelector('summary')?.textContent || ''))
        if (det) det.open = true
      })
      await sleep(300)
      await scrollTo(page, 1400)
    },
    prose: 'The same submission detail page with the Technical details disclosure expanded. The disclosure reveals the schema-bleed fields a developer or support engineer might need: server id, device id, app version, record version, last-edit timestamp. By default the disclosure is closed so non-technical users are not confronted with phrases like "record_version v2" — the disclosure exists for the moments when something has gone wrong and the support channel needs the technical details to diagnose it.' },

  // ── Aggregated template wizard (admin) ──────────────────────────────────
  { stage: 'route', route: '/admin/aggregated-templates',
    prose: 'The aggregated-template administration page, visible only to national administrators. The page lists every template that has ever been published or retired, with controls per row to edit the template\'s columns, toggle published state, or retire the template. Retiring a template warns the administrator that pending submissions filed against the template stay valid but new submissions become impossible — which is the correct behaviour for a controlled reporting cadence. The page exists because reporting needs change over time and the administration of templates has to happen somewhere.' },
  { stage: 'route', route: '/admin/aggregated-wizard',
    prose: 'Step 1 of the new-template wizard, used by national administrators to design a brand-new aggregated report. Step 1 captures the template\'s identity: name, code, frequency (Daily, Weekly, Monthly, Quarterly, Ad-hoc, Event-based), reporting unit (POE, district, national), and the role keys allowed to submit against it. The wizard exists because templates are a moving target — new diseases, new programmes, and new donor reporting requirements all eventually become a new template, and the administrator needs a guided way to define one without hand-editing the database.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 700) },
    prose: 'The template wizard scrolled down. Subsequent steps appear inline as the administrator fills the form, with the wizard guiding through identity, then the column list, then validation rules, then audience, and finally publish. Each section is collapsible. The pattern keeps the administrator oriented in a single long page rather than a sequence of separate pages they could lose their place in.' },
  { stage: 'route', route: '/admin/poe-contacts',
    prose: 'The point-of-entry notification-contacts roster. This is the escalation list — who gets called or emailed when something fires at this point of entry. Each contact has a name, role title, phone, email, escalation order (1 means called first), and an active flag. New, edit, and delete are reachable via the floating action button in the corner. The page exists because the system\'s ability to alert a real human depends on whether the right phone number is in the right slot, and there has to be a place to keep the list accurate.' },

  // ── Disease intelligence ────────────────────────────────────────────────
  { stage: 'route', route: '/DiseaseInteligence',
    prose: 'The tracked-diseases catalogue. Every disease the syndromic engine knows about appears here with a search box at the top and filter chips for IHR Tier and syndrome. The page is read-only — it is a reference, not a configuration surface. It exists so a clinical officer can look up the case definition, the endemic-country list, the IHR tier, and the recommended actions for a disease the engine has flagged in a Step 4 ranking.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 700) },
    prose: 'The tracked-diseases catalogue scrolled down. The card list paginates as the user scrolls. Tapping any disease opens a detail page with the full case definition, endemic-country map, IHR tier classification, and the recommended actions panel — the same panel that drives the recommendations on Step 4 of the secondary wizard.' },

  // ── Sync ────────────────────────────────────────────────────────────────
  { stage: 'route', route: '/sync',
    prose: 'The sync centre. A KPI strip across the top shows the totals for Synced, Pending, Failed, and Quarantined records across every store. Sync Now (single-flight, re-entrance guarded) runs the upload pass. Below the strip the page breaks down the totals by store — Primary screenings, Notifications, Secondary screenings, Alerts, Aggregated submissions — each with its own per-store sync button so a user can push one store at a time when they suspect a problem in only one. The page exists as the operator\'s answer to "is everything I captured today actually on the server."' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 700) },
    prose: 'The sync centre scrolled down to the per-store breakdown. Each store row shows synced, pending, and failed counts as numbers and as a horizontal bar so the user can see at a glance which stores are healthy and which are backed up. The Sync button on each row pushes only that store. The view exists because a user reporting "sync is broken" usually means one specific store is failing while every other store is fine, and the sync engine has to give them a way to see that.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1200) },
    prose: 'Further down the sync centre, where Failed Records are surfaced if any exist. A header row shows the count and a Retry All button. Filter chips below let the user narrow the list to a specific store or rejection reason. Each failed row carries the store, the record id, the error the server returned, and a one-tap retry. The whole section only renders when there is at least one failed record — by default the page is quiet, which is the desired behaviour because a healthy device should not have a "failed records" panel taking up space.' },

  // ── POE + Users admin ───────────────────────────────────────────────────
  { stage: 'route', route: '/POEs',
    prose: 'The point-of-entry registry, visible to national administrators. Every gazetted point of entry in the country appears here as a row, with filter chips for province, district, and active/inactive status. The detail/edit modal opens on row tap; the floating action button at the bottom-right opens the create modal. The page exists because the list of points of entry is not static — new sites get gazetted, existing sites get retired, and the administrator needs a single surface to keep the list correct. The list flows downstream into every drop-down in every other view.' },
  { stage: 'action',
    prep: async (page) => {
      await page.evaluate(() => {
        const fab = document.querySelector('ion-fab-button, [class*="fab"], [class*="add-btn"]')
        fab?.click()
      })
      await sleep(900)
    },
    prose: 'The create-POE wizard, opened from the floating action button. The wizard walks through identity (name, code, type), location (province, district, coordinates), assignment (PHEOC, on-call hours), and status. Fields validate live (a duplicate code is rejected immediately, for example). Save commits to the local store and queues the new POE for sync to the central registry, where it eventually flows back to every other device on the next sync.' },
  { stage: 'route', route: '/Users',
    prose: 'The user-management page. Every user in the administrator\'s scope appears as a row with a role badge and an active/inactive badge. Filter by role or status; search by name or username. The page is the administration surface for the operator roster — onboarding new staff, deactivating staff who have moved on, and reassigning staff between sites all happen here.' },
  { stage: 'action',
    prep: async (page) => {
      await clickByText(page, '+ New')
      await sleep(900)
    },
    prose: 'The create-user form. The administrator fills in full name, username, email, phone, password, role, and geographic assignment. The form auto-shows or hides fields based on the role chosen — a screener needs province, district, and POE; a national administrator needs none of those because their scope is the whole country. A strength indicator next to the password field shows whether the chosen password meets the minimum, and autocomplete=new-password keeps browser password managers from auto-filling the field with the administrator\'s own credentials.' },

  // ── Profile + Directory ─────────────────────────────────────────────────
  { stage: 'route', route: '/profile',
    prose: 'The user\'s own profile page. Read-mostly view of name, username, email, phone, role, geographic assignment, and last-login timestamp. The user can edit phone and email (those are user-controlled fields); role and scope are read-only here because only an administrator can change them. The page exists as a self-service surface for the everyday "my phone number changed" task that does not need administrator involvement.' },
  { stage: 'route', route: '/directory',
    prose: 'The staff directory. A tap-to-dial roster of every staff member in scope, filterable by role and geographic scope. Each contact card carries call and SMS buttons that fire the device\'s tel: and sms: handlers — both of which work offline because they are handled by the OS dialler and messaging app, not by the network. The page is the answer to "who do I call right now" for a screener or secondary officer who needs to escalate a case in real time.' },

  // ── Settings ────────────────────────────────────────────────────────────
  { stage: 'route', route: '/settings',
    prose: 'The app-settings page, top of view. The first card is the Account & Assignment summary: name, username, email, role on the top half, and the geographic assignment (country, province, district, point of entry, PHEOC) on the bottom half. Both halves are read-only here — by design, because changing them is an administrator action that lives in the user-management page. The card exists so the user can confirm the system has them in the right slot before doing anything that depends on scope.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 350) },
    prose: 'Further down settings, the Sync & Storage card. It surfaces connection state (online or offline), the running app version, the reference-data version (the country/disease/POE catalogue), the device id, the server URL, and the count of locally cached records. The card is most useful for support: when a user reports something is broken, the first thing the support channel asks for is what they see here.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 700) },
    prose: 'Further down still, the Preferences card. A single toggle for Haptic Feedback (vibrate on critical alerts and validation errors). On by default; off here saves battery on older devices and lets a user run silent in environments where vibration is unwelcome.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1000) },
    prose: 'The Capabilities & Help card on the settings page. The card replaces what used to be a standalone Capabilities & Help menu row — folding the surface into settings keeps it discoverable without putting it at top level. The header tally reads "0 of 8 on" because every capability is off in the demo seed. The hero CTA "Explore capabilities & how-to" opens the full explorer page, which is reached on its own route at /capabilities-help.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1400) },
    prose: 'The Capabilities Quick toggles, sitting under the hero CTA. Each row shows an icon, a plain-language name and sub-line, and an on/off toggle. Toggling app-lock on reveals a "Set a PIN" follow-up that has to be completed before the toggle stays on. The quick toggles are here for users who already know what each capability does and do not need to see the explorer first.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1900) },
    prose: 'The bottom of the settings page, showing the Capture accelerators (Sentinel) card alongside the Actions card and the About card below it. Sentinel\'s master kill switch sits with per-feature toggles for Boarding-pass barcode and Voice fill — all off by default; turning them off returns the app to standard manual entry. The Actions card below it links to Sync Queue, the offline-model manager, plugin diagnostics, Clear Local Cache (behind a confirm), and Sign Out in red. The About card at the very bottom carries the product blurb, the schema version of the local database, and the build year. This zone of the page is a catch-all for the operations and reference data that don\'t fit cleanly elsewhere on the settings surface.' },

  // ── Settings sub-pages ──────────────────────────────────────────────────
  { stage: 'route', route: '/settings/sentinel-models',
    prose: 'The Sentinel offline-model manager. Each row is one of the on-device machine-learning models the app can use to accelerate capture (an ML Kit barcode model, for example). Per row: status (Idle, Downloading, Available, Failed, Quarantined), download progress as a percentage, last-used timestamp, and file size on disk. Per-row buttons allow Download, Retry, and Remove. The page is reached from Settings → Manage offline models. It exists because the models are large and occasionally fail to install, and the user needs an obvious place to see what state each model is in.' },
  { stage: 'route', route: '/settings/diagnostics',
    prep: async (page) => { await sleep(8000) }, // wait for the runner to finish
    prose: 'The plugin diagnostics page. The runner auto-starts on mount and probes each Capacitor plugin for module load, gate state, permission state, and platform support. By the time the screen has settled the summary strip across the top shows pass / warn / fail / skip totals plus the duration of the run and the platform stamp. Filter chips below the strip narrow the suite list. Each suite is rendered as a colour-coded card; failing suites auto-expand to show per-test detail, the error stack, and a green "Fix:" remediation hint. Copy report dumps a JSON snapshot for support channels. The page exists because Capacitor plugins fail in many subtle ways (a missing native dependency, a missing OS permission, a version mismatch), and the support channel needs a fast way to see them all at once.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1400) },
    prose: 'The diagnostics page scrolled down to the per-plugin suites. Each suite header carries a pass/warn/fail pill and the suite\'s one-line summary; per-test rows show the probe name, a plain-English description of what the probe checked, and a green "Fix:" hint when relevant. The page is what turns a Capacitor problem from "it doesn\'t work" into "this specific permission is denied — go grant it in your phone\'s app settings."' },

  // ── Capabilities & Help ─────────────────────────────────────────────────
  { stage: 'route', route: '/capabilities-help',
    prose: 'The capabilities and help explorer. A banner at the top offers to replay the welcome briefing. Below that, capabilities are grouped into Security, Capture assists, Communication, and Connectivity; each group is a stack of cards, one card per capability. Each card carries an icon, a plain-language description, the current state, an on/off toggle, a "Try it now" demo (a non-destructive probe of the underlying feature), and a "Show me in the app" spotlight tour that takes the user to the place in the app where the feature shows up. The page is the long-form complement to the Quick toggles in settings — meant for users who want to understand a feature before turning it on.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 800) },
    prose: 'The capabilities explorer scrolled down to the Capture assists group: voice dictation, barcode scan, and keep-awake. Each "Try it now" invokes the underlying plugin in a non-destructive way — a probe rather than a real recording or scan — so the user can confirm a capability works without committing to it.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1600) },
    prose: 'The capabilities explorer scrolled to the Communication and Connectivity groups: PDF share, staff directory, local notifications, network probe. The settings page hero CTA "Explore capabilities & how-to" lands the user here, which is the canonical entry point for first-time users learning the breadth of the app.' },
]

// ── runner ─────────────────────────────────────────────────────────────────

async function captureShot(page, file, opts = {}) {
  const requestedClipY = opts.clipY ?? page._clipY ?? 0
  // Find the document's actual rendered content height by walking the DOM
  // and reading bounding rects. Ionic's wrappers are absolutely positioned
  // so document.body.scrollHeight reports 0 — we have to measure children.
  const contentHeight = await page.evaluate(() => {
    // Ionic wrappers (ion-app, ion-router-outlet, ion-page, ion-content)
    // expand to fill the viewport, so they always report bottom=viewportHeight
    // regardless of actual content. Skip them and HTML/BODY too — measure
    // only true content elements.
    // Ionic wrappers expand to fill the viewport so they always bottom at
    // viewportHeight regardless of actual content. They show up both as
    // custom elements (<ion-app>) AND as plain DIVs with class names
    // ("ion-page" — IonPage renders as a div). Skip both forms.
    const SKIP_TAGS = new Set(['HTML', 'BODY', 'ION-APP', 'ION-ROUTER-OUTLET', 'ION-PAGE', 'ION-CONTENT', 'ION-NAV', 'ION-MENU'])
    const SKIP_CLASS_RE = /\b(?:ion-page|ion-app|ion-router-outlet|ion-content|ion-nav|ion-menu)\b/
    let max = 0
    document.querySelectorAll('*').forEach(el => {
      if (SKIP_TAGS.has(el.tagName)) return
      const cls = (el.className || '').toString()
      if (SKIP_CLASS_RE.test(cls)) return
      const r = el.getBoundingClientRect()
      if (r.height > 50 && r.bottom > max) max = r.bottom
    })
    return Math.ceil(max)
  })
  const maxClipY = Math.max(0, contentHeight - SHOT_HEIGHT)
  const clipY = Math.min(requestedClipY, maxClipY)
  // Pixel-6 reported viewport is 412×915; we render in a 412×4000 viewport
  // and clip 915 high at the requested Y offset (capped at content bottom
  // so we never clip into whitespace). DPR=2 means the file ends up 824×1830,
  // exactly what a real Pixel-6 device produces.
  await page.screenshot({
    path: file,
    clip: { x: 0, y: clipY, width: SHOT_WIDTH, height: SHOT_HEIGHT },
  })
  const buf = await (await import('node:fs/promises')).readFile(file)
  return { buf, hash: md5(buf), bytes: buf.length, clipY, requestedClipY, contentHeight }
}

async function runPlanEntry(page, plan, opts = {}) {
  // Every fresh entry starts at clip-Y=0 (top of page) unless the prep moves it.
  page._clipY = 0
  if (opts.goto && plan.route) {
    const url = `${BASE}/#${plan.route.startsWith('/') ? plan.route : '/' + plan.route}`
    await page.goto(url, { waitUntil: 'networkidle0', timeout: 30000 })
    await sleep(1300) // §8: never go below 1300ms after goto
    await dismissOverlays(page)
  }
  if (plan.stage === 'menu') {
    try { await page.evaluate(() => document.querySelector('ion-menu-button')?.click()) } catch {}
    await sleep(750)
  }
  if (plan.prep) {
    try { await plan.prep(page) } catch {}
    await sleep(500)
  }
  await dismissOverlays(page)
}

async function main() {
  console.log(`POE Sentinel — full-app capture rig`)
  console.log(`Base: ${BASE}`)
  console.log(`Output: ${ROOT}`)
  await ensureDirs()

  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
  })

  // Pre-seed IDB once on a throwaway page (auth + suppression flags + sample data)
  {
    const page = await browser.newPage()
    await page.setViewport(VIEWPORT)
    await page.setUserAgent(UA)
    await page.evaluateOnNewDocument(AUTH_SEED)
    await page.goto(`${BASE}/#/home`, { waitUntil: 'networkidle0', timeout: 30000 })
    await sleep(1500) // §8: 1500ms before first IDB seed read after mount
    await page.evaluate(IDB_SEED)
    await sleep(1500)
    await page.close()
  }

  const results = []
  let lastPage = null

  for (let i = 0; i < PLAN.length; i++) {
    const plan = PLAN[i]
    const num = String(i + 1).padStart(3, '0')
    const routeForName = plan.route || results[results.length - 1]?.route || 'state'
    const filename = `${num}_${plan.stage}_${slug(routeForName)}.png`
    const file = resolve(SHOTS, filename)

    let page = lastPage
    let goto = false
    if (plan.stage !== 'action' || !page) {
      if (page) try { await page.close() } catch {}
      page = await browser.newPage()
      await page.setViewport(VIEWPORT)
      await page.setUserAgent(UA)
      if (plan.stage !== 'login') await page.evaluateOnNewDocument(AUTH_SEED)
      goto = true
    }

    const consoleErrors = []
    const pageErrors = []
    const errListeners = {
      console: m => { if (m.type() === 'error') consoleErrors.push(m.text()) },
      pageerror: e => pageErrors.push(String(e?.message || e)),
    }
    page.on('console', errListeners.console)
    page.on('pageerror', errListeners.pageerror)

    process.stdout.write(`  [${num}/${String(PLAN.length).padStart(3, '0')}] ${plan.stage.padEnd(7)} ${routeForName.padEnd(40)} `)
    const t0 = Date.now()
    let ok = true
    let url = goto && plan.route
      ? `${BASE}/#${plan.route.startsWith('/') ? plan.route : '/' + plan.route}`
      : null
    let hash = null
    let bytes = null
    let dims = null
    let dupRetry = 0
    let dupNote = null

    try {
      await runPlanEntry(page, plan, { goto })
      let { hash: h, bytes: b, clipY: capClipY } = await captureShot(page, file)
      hash = h; bytes = b

      // Adjacent duplicate check — if md5 matches the previous shot AND the
      // entry has a prep step, the prep silently failed. Re-run with extra
      // wait, then re-screenshot. One retry only — log a warning if still
      // identical.
      const prev = results[results.length - 1]
      if (prev && prev.ok && prev.hash === hash && plan.prep && plan.stage === 'action') {
        dupRetry = 1
        await sleep(800)
        try { await plan.prep(page) } catch {}
        await sleep(600)
        await dismissOverlays(page)
        const re = await captureShot(page, file)
        hash = re.hash; bytes = re.bytes
        if (re.hash === prev.hash) {
          dupNote = 'adjacent-duplicate-after-retry'
        }
      }

      // Read dimensions from the saved file via a quick parse of PNG header
      dims = await pngDims(file).catch(() => null)
    } catch (err) {
      ok = false
      pageErrors.push(`error: ${err.message}`)
    }

    page.off('console', errListeners.console)
    page.off('pageerror', errListeners.pageerror)
    const ms = Date.now() - t0
    process.stdout.write(`${ok ? '✓' : '✗'} ${ms}ms${dupRetry ? ' [retry]' : ''}${dupNote ? ' ⚠' : ''}\n`)

    results.push({
      num, stage: plan.stage, route: routeForName, filename,
      file: ok ? `screenshots/${filename}` : null,
      prose: plan.prose,
      ok, ms, url, hash, bytes, dims,
      consoleErrors, pageErrors,
      dupRetry, dupNote,
      suppressionFlags: [...COACHMARK_FLAGS, ...SENTINEL_FLAGS].map(f => f.key),
    })

    if (i + 1 < PLAN.length && PLAN[i + 1].stage === 'action') lastPage = page
    else { try { await page.close() } catch {} ; lastPage = null }
  }
  if (lastPage) try { await lastPage.close() } catch {}

  // ── Sentinel on-state visual-only pass ──────────────────────────────────
  // Flip the master flag on, navigate to /settings, scroll to the Sentinel
  // card, screenshot. Revert immediately. NO clicks into plugin actions —
  // this is a pure render test.
  console.log(`  [sentinel-on] flipping master flag, capturing /settings ...`)
  {
    const page = await browser.newPage()
    await page.setViewport(VIEWPORT)
    await page.setUserAgent(UA)
    await page.evaluateOnNewDocument(`(() => {
      try {
        ${[...COACHMARK_FLAGS]
            .map(f => `try { localStorage.setItem('${f.key}', '${f.value}') } catch {}`).join('\n        ')}
        // Sentinel master ON for this single capture
        try { localStorage.setItem('cap.sentinel.master.enabled', '1') } catch {}
        try { localStorage.setItem('cap.bcbp.enabled', '1') } catch {}
        try { localStorage.setItem('cap.voice_wizard.enabled', '1') } catch {}
        try { localStorage.setItem('cap.barcode.enabled', '1') } catch {}
        // Auth still required — re-seed it inline
        const now = new Date().toISOString()
        sessionStorage.setItem('AUTH_DATA', JSON.stringify({
          id: 1, role_key: 'NATIONAL_ADMIN', country_code: 'ZM',
          poe_code: 'LUSKIA', district_code: 'LUSAKA', province_code: 'LSK',
          pheoc_code: 'LSK', full_name: 'Demo Walkthrough Admin',
          username: 'demo.admin', email: 'demo@example.org', phone: '',
          is_active: true, last_login_at: now, created_at: now, updated_at: now,
          email_verified_at: now, name: 'Demo Walkthrough Admin',
          _logged_in_at: now, _offline_login: false,
          _permissions: { view_settings: true, manage_settings: true, view_dashboard: true },
        }))
      } catch {}
    })()`)

    const consoleErrors = []
    const pageErrors = []
    page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()) })
    page.on('pageerror', e => pageErrors.push(String(e?.message || e)))

    const num = String(PLAN.length + 1).padStart(3, '0')
    const filename = `${num}_action_settings-sentinel-on.png`
    const file = resolve(SHOTS, filename)
    let ok = true, hash = null, bytes = null, dims = null
    try {
      await page.goto(`${BASE}/#/settings`, { waitUntil: 'networkidle0', timeout: 30000 })
      await sleep(1300)
      await dismissOverlays(page)
      await scrollTo(page, 1900) // Sentinel card region
      await sleep(500)
      await dismissOverlays(page)
      const cap = await captureShot(page, file)
      hash = cap.hash; bytes = cap.bytes
      dims = await pngDims(file).catch(() => null)
    } catch (err) {
      ok = false
      pageErrors.push(`error: ${err.message}`)
    }
    results.push({
      num, stage: 'action', route: '/settings (Sentinel master ON)',
      filename, file: ok ? `screenshots/${filename}` : null,
      prose: 'The settings page Sentinel card with the master switch flipped on, captured for documentation. The card now renders the per-feature toggles in their enabled state and the Sentinel-related action rows on the page below it become available. No plugin is invoked — this is a render-only capture; on a real device, tapping any of the Sentinel buttons would prompt the user for the relevant OS permission (camera, microphone, storage). The card is shown both off and on so a reader of the manual can see the difference between the production default (all off) and the in-use state.',
      ok, url: `${BASE}/#/settings`, hash, bytes, dims,
      consoleErrors, pageErrors, dupRetry: 0, dupNote: null,
      suppressionFlags: ['cap.sentinel.master.enabled=1 (temporarily ON for this shot)'],
    })

    // Restore Sentinel flags inside this profile before close
    await page.evaluate(`(() => {
      try {
        try { localStorage.setItem('cap.sentinel.master.enabled', '0') } catch {}
        try { localStorage.setItem('cap.bcbp.enabled', '0') } catch {}
        try { localStorage.setItem('cap.voice_wizard.enabled', '0') } catch {}
        try { localStorage.setItem('cap.barcode.enabled', '0') } catch {}
      } catch {}
    })()`)

    await page.close()
  }

  // ── §7 Restore — wipe coachmark flags inside the headless profile ───────
  // This runs in the test browser only. It documents the principle that the
  // rig does not leak its suppression flags into the production state.
  console.log(`  [restore] removing coachmark flags inside the headless profile ...`)
  {
    const page = await browser.newPage()
    await page.setViewport(VIEWPORT)
    await page.setUserAgent(UA)
    await page.goto(`${BASE}/#/home`, { waitUntil: 'domcontentloaded', timeout: 30000 })
    await sleep(800)
    await page.evaluate(RESTORE_SCRIPT)
    await page.close()
  }

  await browser.close()

  // ── Build prose INDEX.md and MANIFEST.json ──────────────────────────────
  await buildIndex(results)
  await writeFile(MANIFEST, JSON.stringify({
    generatedAt: new Date().toISOString(),
    baseUrl: BASE,
    viewport: REPORTED_VIEWPORT,
    renderViewport: VIEWPORT,
    renderNote: 'Renders into a 412x4000 viewport so Ionic\'s fixed-positioned ion-app stack lays out the whole page; each shot is then a 412x915 clip at the scroll Y the prep requested. The reported "viewport" is the Pixel-6 size a real user sees.',
    suppressionFlagsAtCapture: [...COACHMARK_FLAGS, ...SENTINEL_FLAGS].map(f => ({ key: f.key, value: f.value })),
    restoredAtEnd: COACHMARK_FLAGS.map(f => f.key),
    total: results.length,
    captured: results.filter(r => r.ok).length,
    duplicates: results.filter(r => r.dupNote === 'adjacent-duplicate-after-retry').map(r => r.num),
    items: results,
  }, null, 2))

  console.log('')
  console.log(`Captured:      ${results.filter(r => r.ok).length} / ${results.length}`)
  console.log(`Duplicates:    ${results.filter(r => r.dupNote === 'adjacent-duplicate-after-retry').length}`)
  console.log(`Screenshots:   ${SHOTS}`)
  console.log(`INDEX:         ${INDEX_MD}`)
  console.log(`MANIFEST:      ${MANIFEST}`)
  const fails = results.filter(r => !r.ok)
  if (fails.length) {
    console.log(`Failures (${fails.length}):`)
    for (const f of fails) console.log(`  ✗ ${f.num} ${f.stage} ${f.route} — ${f.pageErrors[0] || 'unknown'}`)
  }
}

// PNG header parsing — read width/height without a dep
async function pngDims(file) {
  const fs = await import('node:fs/promises')
  const buf = await fs.readFile(file)
  // PNG: 8-byte signature + IHDR chunk: 4 length + "IHDR" + 4 width + 4 height
  if (buf[0] !== 0x89 || buf[1] !== 0x50) return null
  const w = buf.readUInt32BE(16)
  const h = buf.readUInt32BE(20)
  return { w, h }
}

async function buildIndex(results) {
  const lines = []
  lines.push('# POE Sentinel — Full-App Visual Walkthrough')
  lines.push('')
  lines.push(`Generated ${new Date().toISOString().slice(0, 10)} from a headless Pixel-6 viewport (412 × 915, 2× DPR) running Android Chrome user-agent. Authentication is pre-seeded as a national administrator so every gated view is reachable. IndexedDB is pre-seeded with deterministic sample data so wizards, modals, and detail views render with realistic content. Tours and coachmarks are suppressed before any shot is taken and restored at the end of the run inside the headless profile.`)
  lines.push('')
  lines.push(`This walkthrough captures ${results.filter(r => r.ok).length} screens covering the entire mobile application: authentication, the home dashboard, primary screening (capture form, records, referral queue, detail modal, symptomatic state), secondary screening (the four-step Profile/Symptoms/Exposures/Analysis wizard with engine output), the notifications inbox, secondary case records, the alerts surface and its intelligence and history pages, the WHO/IHR Annex 2 reference matrix, the aggregated-data hub and submission wizard with submission history, the aggregated-template administration wizard, the point-of-entry contacts roster, disease intelligence, the sync centre, point-of-entry administration, user administration, the user profile, the staff directory, the settings page in its successive scroll positions, the Sentinel offline-model manager, plugin diagnostics, and the capabilities-and-help explorer. A single render-only Sentinel-on capture sits at the end so a reader can see the difference between the production default (every accelerator off) and the in-use state.`)
  lines.push('')
  lines.push(`Each entry below is a single self-contained paragraph describing what is on the screen, what each control does, when a user encounters the screen, and why the screen exists. Plain language is used everywhere except in the explicit administrator-tier surfaces, where treaty-aligned vocabulary (IHR, PHEOC, Annex 2) is appropriate.`)
  lines.push('')
  lines.push('---')

  let lastRoute = null
  for (const r of results) {
    lines.push('')
    // A short heading, but the body is prose. The brief asks for "no labels"
    // in the body — the section heading is for navigation, the body is prose.
    const headRoute = r.route.replace(/\(Sentinel master ON\)/, '— Sentinel master ON')
    lines.push(`## ${r.num} · ${headRoute}`)
    lines.push('')
    if (r.file) {
      lines.push(`![](${r.file})`)
    } else {
      lines.push(`*(screenshot not captured — ${r.pageErrors?.[0] || 'unknown error'})*`)
    }
    lines.push('')
    lines.push(r.prose)
    lines.push('')
    if (r.dupNote === 'adjacent-duplicate-after-retry') {
      lines.push(`> ⚠ This shot is byte-identical to the previous shot after a retry. The prep step likely did not advance the underlying state; the screenshot may not show what the caption describes.`)
      lines.push('')
    }
    if (r.consoleErrors?.length) {
      lines.push(`<details><summary>Console messages (${r.consoleErrors.length})</summary>`)
      lines.push('')
      for (const e of r.consoleErrors.slice(0, 4)) lines.push(`- \`${e.replace(/`/g, "'").slice(0, 200)}\``)
      lines.push('')
      lines.push('</details>')
      lines.push('')
    }
    lines.push('---')
    lastRoute = r.route
  }
  await writeFile(INDEX_MD, lines.join('\n'))
}

main().catch(e => { console.error(e); process.exit(1) })
