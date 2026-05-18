/**
 * tests/presentation-screenshots.mjs
 *
 * Maximum-depth presentation walkthrough.
 *
 * Captures every meaningful screen + interaction state. Walks the full
 * Primary Screening flow (capture → result → records → referral queue),
 * the full Secondary Screening 4-step wizard (Profile → Symptoms →
 * Exposures → Analysis), every wizard step the user touches, and varies
 * scroll positions in long views (Settings, Welcome, Capabilities & Help).
 *
 * Web-only constraints honoured:
 *   - NEVER click camera / barcode / voice plugin buttons (they open
 *     OS-level prompts that block the headless browser).
 *   - Sentinel master + per-feature flags are turned OFF in the seed so
 *     those buttons don't even render.
 *   - Tours / coachmarks are marked "seen" before render so no overlay
 *     covers the screenshot.
 *
 * Output:
 *   _audit/PRESENTATION/screenshots/<NN>_<slug>.png
 *   _audit/PRESENTATION/INDEX.md       — usage-style gallery (per-shot caption)
 *   _audit/PRESENTATION/MANIFEST.json  — machine-readable manifest
 *
 * Pre-seeds IndexedDB (poe_offline_db) with deterministic UUIDs so every
 * detail modal + the Secondary wizard loads with real-looking data.
 */

import puppeteer from 'puppeteer'
import { mkdir, writeFile, rm } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const REPO = resolve(__dirname, '..')
const BASE = process.env.BASE_URL || 'http://127.0.0.1:4173'
const ROOT = resolve(REPO, '_audit/PRESENTATION')
const SHOTS = resolve(ROOT, 'screenshots')
const INDEX_MD = resolve(ROOT, 'INDEX.md')
const MANIFEST = resolve(ROOT, 'MANIFEST.json')

const VIEWPORT = { width: 412, height: 915, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 6) AppleWebKit/537.36 ' +
           '(KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36'

// Deterministic UUIDs so we can navigate to /secondary-screening/<known>
const NOTIF_UUID  = 'demo01-notif-4444-aaaa-555566667777'
const PRIMARY_SYMP_UUID = 'demo01-prim2-4444-bbbb-555566667777'
const PRIMARY_OK_UUID   = 'demo01-prim1-4444-cccc-555566667777'
const SECONDARY_UUID    = 'demo01-secondary4444-dddd-666677778888'
const ALERT_UUID        = 'demo01-alert-4444-eeee-777788889999'

// ── Auth seed (NATIONAL_ADMIN) ─────────────────────────────────────────────
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
    // ── Suppress every coachmark / tour the app might show ──
    // tour.js stores "seen" flags as 'tour.seen.<key>' = '1'. Pre-mark every
    // known coachmark so nothing pops up in the screenshots.
    for (const k of [
      'primary.scan-intro', 'directory.first-open', 'welcome.replay',
      'caps-help.first-open', 'sentinel.first-toggle',
    ]) try { localStorage.setItem('tour.seen.' + k, '1') } catch {}
    // Mark welcome briefing tour as seen for this user-id so it doesn't auto-replay.
    try { localStorage.setItem('cap.tour.seen_version', 'demo-1') } catch {}
    // ── Sentinel features OFF — keeps scan / voice buttons OUT of the
    // headless screenshots since web can't safely trigger their OS prompts.
    for (const k of [
      'cap.sentinel.master.enabled',
      'cap.bcbp.enabled', 'cap.voice_wizard.enabled',
      'cap.barcode.enabled',
    ]) try { localStorage.setItem(k, '0') } catch {}
  } catch {}
})()`

// ── IDB seed (deterministic UUIDs so wizards open by URL) ───────────────────
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

    // Seed the secondary screening case as DISPOSITIONED so maxStepReached=4
    // and the screenshot script can click each of the 4 step pills.
    //
    // CRITICAL FIELD-NAME NOTES (from SecondaryScreening.vue source):
    //   - The case lookup uses dbGetByIndex(SECONDARY_SCREENINGS, "notification_id", uuid).
    //     The schema index is named notification_id and pulls from the same-named
    //     field on the record. Writing notification_uuid makes the lookup miss
    //     entirely, the case stays unloaded, maxStepReached=1, and clicking the
    //     "Symptoms / Exposures / Analysis" step pills silently fails.
    //   - Profile data is read by the resume block via existing.traveler_full_name,
    //     existing.traveler_gender, existing.traveler_age_years etc. — every
    //     profile field is prefixed traveler_.
    //   - The disposition field on the case is final_disposition, not disposition.
    const secondary = {
      client_uuid: SECONDARY_UUID,
      // ↓↓ THIS is the key field for the index lookup. Don't rename. ↓↓
      notification_id: NOTIF_UUID,
      reference_data_version: 1,
      country_code: 'ZM', province_code: 'LSK', pheoc_code: 'LSK',
      district_code: 'LUSAKA', poe_code: 'LUSKIA',
      primary_screening_id: PRIMARY_SYMP_UUID,
      created_by_user_id: 1, opened_by_user_id: 1,
      // Profile fields (step 1) — names mirror the resume Object.assign in
      // SecondaryScreening.vue around line 3178.
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
      // Vitals (Step 2 reveals these via showVitals when temperature_value is non-null)
      temperature_value: 38.6, temperature_unit: 'C',
      pulse_rate: 92, respiratory_rate: 22,
      bp_systolic: 128, bp_diastolic: 82,
      oxygen_saturation: 96, triage_category: 'YELLOW',
      emergency_signs_present: 0, general_appearance: 'Mildly unwell, alert, oriented.',
      // Symptoms summary (free text — separate from the per-symptom rows)
      symptoms_summary: 'Cough, fever for 3 days. No haemorrhagic signs.',
      onset_date: yesterday.slice(0, 10),
      exposure_summary: 'Visited DRC border market within past 14 days.',
      // Analysis (step 4)
      syndrome_classification: 'RESPIRATORY',
      risk_level: 'HIGH', routing_level: 'PHEOC',
      ihr_alert_required: 1,
      // case_status DISPOSITIONED makes the resume block set
      // step.value = 4 AND maxStepReached.value = 4 (Round-2 SS-001 fix).
      case_status: 'DISPOSITIONED',
      // The view reads final_disposition (not "disposition").
      final_disposition: 'REFERRED',
      officer_notes: 'Referred to district hospital for further evaluation. Suspected influenza-like illness with travel-related exposure.',
      followup_required: 1, followup_assigned_level: 'DISTRICT',
      dispositioned_at: now, closed_at: null,
      device_id: 'demo-device', app_version: '1.1.1', platform: 'WEB',
      record_version: 3, sync_status: 'UNSYNCED', synced_at: null,
      sync_attempt_count: 0, last_sync_error: null,
      created_at: yesterday, updated_at: now,
    }

    // Seed a couple of symptoms + an exposure so the wizard isn't empty.
    // The view reads s.is_present (1/0/null), NOT response. (See line 3228
    // of SecondaryScreening.vue.)
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
    await put(db, 'alerts', alert)
    await put(db, 'aggregated_templates_cache', aggTemplate)
    await put(db, 'aggregated_submissions', aggSubmission)
    await put(db, 'poe_notification_contacts', poeContact)
  } catch (e) {
    console.warn('IDB seed failed', e?.message)
  }
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

async function clickSelector(page, sel) {
  return await page.evaluate((s) => {
    const el = document.querySelector(s)
    if (el) { el.scrollIntoView({ block: 'center' }); el.click(); return true }
    return false
  }, sel)
}

async function scrollTo(page, y) {
  await page.evaluate((y) => {
    window.scrollTo({ top: y, behavior: 'instant' })
    document.querySelector('ion-content')?.scrollToPoint?.(0, y, 0)
  }, y)
}

// Dismiss any tour overlay / coachmark by pressing Escape and clicking a
// generic Close / Got it / Skip button if present.
async function dismissOverlays(page) {
  try { await page.keyboard.press('Escape') } catch {}
  await page.evaluate(() => {
    const close = [...document.querySelectorAll('button')].find(b => /^(got it|close|skip|dismiss|continue)$/i.test((b.textContent || '').trim()))
    close?.click()
    // Anything with class containing "tour" / "coachmark"
    document.querySelectorAll('[class*="tour"], [class*="coach"], .lm-overlay--tour').forEach(el => {
      try { el.style.display = 'none' } catch {}
    })
  })
}

// ── shot plan ──────────────────────────────────────────────────────────────
// stage:
//   'login'  — visit without auth seeded
//   'route'  — fresh page, seeded auth, goto, settle, screenshot
//   'menu'   — same as 'route' then open the side menu drawer
//   'action' — re-use the previous page (no goto), run prep, screenshot

const PLAN = [
  // ── 1. Login ────────────────────────────────────────────────────────────
  { stage: 'login', route: '/home',
    caption: '**Login — default state.** What every user sees on cold-start. Username + password fields, the locked atmospheric background. Type credentials → tap *Sign in*. The overlay dismisses on successful auth and remounts the app.' },
  { stage: 'login', route: '/home',
    prep: async (page) => {
      await page.evaluate(() => {
        const u = document.querySelector('input[autocomplete="username"], input[name="username"], input[type="text"]')
        const p = document.querySelector('input[type="password"]')
        if (u) { u.focus(); u.value = 'demo.admin'; u.dispatchEvent(new Event('input', { bubbles: true })) }
        if (p) { p.value = 'demo-pass-1234'; p.dispatchEvent(new Event('input', { bubbles: true })) }
      })
    },
    caption: '**Login — credentials entered.** Same overlay with username + password filled in. The eye-icon (if shown) toggles password visibility. *Sign in* validates against the cached server hash if offline; otherwise hits the server.' },

  // ── 2. Welcome briefing ─────────────────────────────────────────────────
  { stage: 'route', route: '/welcome',
    caption: '**Welcome briefing — top.** Role-aware first-run guide. Header reads the user\'s name + role. The lead sentence ("National oversight is active.") changes per role — a screener sees "Screening station ready", a data officer sees "Data officer — aggregated reporting", etc.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 600) },
    caption: '**Welcome briefing — primary tiles row.** Each tile is a feature appropriate to the user\'s role. NATIONAL_ADMIN sees all; lower-tier roles see a smaller subset. Tap any tile to jump to that feature.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1400) },
    caption: '**Welcome briefing — How-to guides.** The fix in Round 5 re-pointed every guide to a real feature page (the previous anchors didn\'t exist). Tap *Run your first primary screen* → /PrimaryScreening, *Pick up a secondary referral* → /NotificationsCenter, *Push your offline queue* → /sync.' },

  // ── 3. Home dashboard ──────────────────────────────────────────────────
  { stage: 'route', route: '/home',
    caption: '**Home dashboard — top.** Status strip uses plain language (*Operating normally*). Hero capture button visible only for screener-class roles (it\'s hidden for NATIONAL_ADMIN). Triptych: Open Referrals / Open investigations / Health alerts (note Round-5 plain labels — no "IHR" jargon visible to the user).' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 800) },
    caption: '**Home dashboard — recent activity feed.** Activity rows refresh every 30 s. Header now carries an "Updated Xm ago" freshness label + a manual ⟳ refresh button (Round-3 fix). Empty / error states explicitly say what\'s wrong.' },
  { stage: 'menu', route: '/home',
    caption: '**Side menu drawer open.** Identity panel (avatar, full name, role chip, geographic scope). Grouped nav: Welcome, Dashboard, POE Management, Disease Management, User Management, Screening, Alerts, Aggregated Data, Sync, Coordination, Account & Settings, Sign Out. Status-bar inset reserved (Round-1 fix).' },

  // ── 4. PRIMARY SCREENING — the full capture loop ────────────────────────
  { stage: 'route', route: '/PrimaryScreening',
    caption: '**Primary Screening — Capture tab (top).** Three tabs along the header: Capture / Records / Referral Queue. Capture form starts with Direction pills (Entry / Exit / Transit) — the screener\'s first decision.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 350) },
    caption: '**Primary Screening — Capture tab (middle).** Sex pills, optional temperature input, optional traveller name + passport tile, Symptoms YES / NO toggle. Each input is a single tap — no keyboards needed for the happy path.' },
  { stage: 'action',
    prep: async (page) => {
      await clickByText(page, '→ Entry')
      await sleep(120)
      await clickByText(page, '♂ Male')
      await sleep(120)
      await page.evaluate(() => {
        const t = document.querySelector('input[type="number"]') || document.querySelector('input[inputmode="decimal"]')
        if (t) { t.focus(); t.value = '38.6'; t.dispatchEvent(new Event('input', { bubbles: true })) }
      })
      await sleep(120)
      await clickByText(page, 'Yes')
      await sleep(150)
      await scrollTo(page, 600)
    },
    caption: '**Primary Screening — symptomatic state.** Direction = Entry, Sex = Male, Temp = 38.6°C (above the 38.0 fever threshold), Symptoms = YES. The Capture button shifts to a red "create referral" style and warns "Will file a SECONDARY_REFERRAL on capture." Tapping commits both the screening AND the notification atomically.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 0); await clickByText(page, 'Records', { tag: 'button' }); await sleep(900) },
    caption: '**Primary Screening — Records tab (top).** The officer\'s personal case register. Filter chips pin to the top: All / Today / This week / Symptomatic / Sync state. Search box for traveller name. Each card shows traveller name + sex, captured time, temperature, symptoms badge, sync state.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 600) },
    caption: '**Primary Screening — Records tab (scrolled).** Older records below the fold, paginated. Tap a card for the read-only detail modal; long-press surfaces the void affordance.' },
  { stage: 'action',
    prep: async (page) => {
      // Records cards are rendered with class `ps-rec-card` (template line 460).
      // Earlier substring selector `[class*="ps-rec-"]` matched the wrong
      // child element so the modal never opened. Use the exact class.
      await page.evaluate(() => {
        const card = document.querySelector('.ps-rec-card')
        card?.click()
      })
      await sleep(900)
    },
    caption: '**Primary Screening — Record detail modal.** Read-only view of one screening: traveller, time, direction, sex, temp, symptoms, referral state, sync status, captured-by, device id. The "Void Record" button at the bottom opens the void-with-reason flow.' },
  { stage: 'action',
    prep: async (page) => {
      await dismissOverlays(page)
      await page.evaluate(() => document.querySelector('ion-modal')?.dismiss?.())
      await sleep(500)
      await clickByText(page, 'Referral Queue', { tag: 'button' })
      await sleep(900)
    },
    caption: '**Primary Screening — Referral Queue tab.** Local view of OPEN secondary referrals raised at this device, before they sync to the central queue. Same data the secondary officer sees in /NotificationsCenter, but scoped to the screener\'s own captures.' },

  // ── 5. SCREENING INTELLIGENCE DASHBOARD ────────────────────────────────
  { stage: 'route', route: '/screening-dashboard',
    caption: '**Screening Intelligence — top.** Filter chips (Today / Week / Month / Year / Custom). Hero ring shows symptomatic-rate as a percent, with the Round-7 plain-language interpretation sentence below ("Of every 100 travellers…") and a colour legend (Green: under 10% · Amber: 10–20% · Red: above 20%).' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 700) },
    caption: '**Screening Intelligence — quick stats + signals.** Eight-cell quick-stats grid: Completed / With symptoms / Sent for review / Fever today / Open referrals / Open alerts / Not uploaded / Cancelled (all whole-word labels per Round 7). If the engine detects any signals (high fever cluster, queue overflow), they appear as cards below.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1500) },
    caption: '**Screening Intelligence — weekly grid + trend.** Weekly grid: Screened / Symptoms % / Sent for review / Fever / Daily average / vs last week (Round-7 plain labels). The chart uses two lines: solid for total screened, dashed for symptomatic.' },
  { stage: 'action',
    prep: async (page) => {
      await page.evaluate(() => document.querySelector('.sd-ft, button.sd-ft')?.click())
      await sleep(700)
    },
    caption: '**Screening Intelligence — advanced filter expanded.** Date / month chip / year chip / from-to range. Apply runs the dashboard against the chosen window; the *Download report* button (top-right of the page) exports the 8-page WHO-format PDF for the same filter.' },

  // ── 6. SECONDARY screening (the full 4-step wizard) ─────────────────────
  { stage: 'route', route: '/NotificationsCenter',
    caption: '**Secondary Referral queue.** Open referrals from primary screening, criticals first. Each card shows the traveller, originating POE, captured time, priority (Routine / Urgent / Emergency — Round-7 plain labels). *Open* takes the secondary officer into the case; *Cancel* closes the referral when no further action is needed.' },
  { stage: 'route', route: '/secondary-screening/' + NOTIF_UUID,
    caption: '**Secondary Screening — Step 1 / Profile.** WHO/IHR-aligned investigation wizard. Top: 4-step progress bar (Profile · Symptoms · Exposures · Analysis). Tap a numbered step to jump there (only steps you have already saved). Step 1 captures full name, sex, age + unit, nationality, residence, passport number, occupation, language preferences.' },
  { stage: 'action',
    prep: async (page) => {
      // Click the step pill labelled "Symptoms" (step 2)
      await page.evaluate(() => {
        const pills = [...document.querySelectorAll('.sc-step, button.sc-step')]
        pills[1]?.click()
      })
      await sleep(900)
    },
    caption: '**Secondary Screening — Step 2 / Symptoms.** Multi-select symptom checklist organised by category (general, respiratory, gastrointestinal, neurological, dermatological, haemorrhagic). Each symptom captures: response (YES/NO/UNKNOWN), onset date, severity. The engine uses these for the Step-4 syndrome classification + suspected disease ranking.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 800) },
    caption: '**Secondary Screening — Step 2 (scrolled).** Symptom categories continue down the page. The bottom of the step has *Save & Next* + *Back*. Forward jump (Round-5 SS-001) is allowed once you\'ve advanced past a step at least once — `maxStepReached` tracks that.' },
  { stage: 'action',
    prep: async (page) => {
      await scrollTo(page, 0)
      await page.evaluate(() => {
        const pills = [...document.querySelectorAll('.sc-step, button.sc-step')]
        pills[2]?.click()
      })
      await sleep(900)
    },
    caption: '**Secondary Screening — Step 3 / Exposures.** Captures travel history (countries visited in the last 14 days) and exposure events (sick contact, livestock contact, healthcare exposure, mass gathering, etc.). Travel countries cross-reference the disease engine\'s endemic-zone map to drive the Step-4 risk assessment.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 600) },
    caption: '**Secondary Screening — Step 3 (scrolled).** Exposure code list with response + free-text "details". Save & Next runs the diagnostic engine and advances to Step 4.' },
  { stage: 'action',
    prep: async (page) => {
      await scrollTo(page, 0)
      await page.evaluate(() => {
        const pills = [...document.querySelectorAll('.sc-step, button.sc-step')]
        pills[3]?.click()
      })
      await sleep(1500)
    },
    caption: '**Secondary Screening — Step 4 / Analysis.** Engine output. IHR risk level, routing destination (POE → DISTRICT → PHEOC → NATIONAL), suspected diseases ranked by probability, syndrome classification, recommended actions. The officer can override risk + routing before disposition.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 800) },
    caption: '**Secondary Screening — Step 4 (scrolled to disposition).** Disposition radio: Released / Quarantined / Isolated / Referred. *Disposition case* commits the case_status to DISPOSITIONED + writes a sync row. The case stays editable from /secondary-screening/records via re-open.' },

  // ── 7. SECONDARY case records ───────────────────────────────────────────
  { stage: 'route', route: '/secondary-screening/records',
    caption: '**Secondary Case Records.** Every full investigation that has been opened or completed. Filter by status (Waiting / Being worked on / Done / Decision made — Round-7 labels), risk level, syndrome. Tap a row for the detail modal showing the full case timeline + disposition.' },

  // ── 8. ALERTS ───────────────────────────────────────────────────────────
  { stage: 'route', route: '/alerts',
    caption: '**Active Alerts.** Open IHR-grade alerts visible in scope. Each row: title, risk pill, age, originating POE, routed-to level. Tap to acknowledge or escalate.' },
  { stage: 'route', route: '/alerts/intelligence',
    caption: '**Alert Intelligence — top.** Title now reads *Outbreak response timeliness* (Round-7 plain language). KPIs each carry plain-English sub-lines: Within 24h notice / target 8 in 10 / Within 7d response / Top-priority (Tier 1) / Targets missed. Colour legend pinned beneath.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 700) },
    caption: '**Alert Intelligence — Detect / Notify / Respond stages.** The 7-1-7 framework rendered as three stage cards. Each card shows whether the stage is computable, the rolling rate, and the WHO/IHR article it derives from.' },
  { stage: 'route', route: '/alerts/history',
    caption: '**Alert History.** Acknowledged + closed alerts with full audit trail (who acknowledged, when, what action). Read-only — closed alerts cannot be re-opened from this view.' },
  { stage: 'route', route: '/alerts/matrix',
    caption: '**WHO IHR 2005 Annex 2 matrix.** Reference catalogue showing which diseases trigger Tier 1 (always notify WHO) vs Tier 2 (notify if certain criteria are met). PII-free, available offline. No interaction beyond browsing.' },

  // ── 9. AGGREGATED HUB + WIZARD + HISTORY ────────────────────────────────
  { stage: 'route', route: '/aggregated-data',
    caption: '**Aggregated Hub.** Lists every PUBLISHED template the user can submit, grouped by frequency. Each card shows template name, frequency badge, period to cover, last-submission status. *Submit* opens the dynamic submission wizard for that template.' },
  { stage: 'route', route: '/aggregated-data/new/1',
    caption: '**Aggregated submission wizard — Step 1 / Period.** *Tell us what period this report covers.* Two date pickers (Start / End). Validation: end must be on/after start, neither in the future. *Next* is disabled until both dates are valid.' },
  { stage: 'action',
    prep: async (page) => {
      await page.evaluate(() => {
        const inputs = document.querySelectorAll('input[type="date"]')
        if (inputs[0]) { inputs[0].value = '2026-04-19'; inputs[0].dispatchEvent(new Event('input', { bubbles: true })) }
        if (inputs[1]) { inputs[1].value = '2026-04-25'; inputs[1].dispatchEvent(new Event('input', { bubbles: true })) }
      })
      await sleep(200)
      await clickByText(page, 'Next')
      await sleep(900)
    },
    caption: '**Aggregated wizard — Step 2 / Counts.** After picking a period, the wizard renders the template\'s columns grouped by category (COUNTS, GENDER). Each field has a label, integer input, and an inline auto-calculate hint (e.g. "Total = Symptomatic + Asymptomatic"). The *Auto-calculate* button (top) tries to fill counts from the user\'s own primary screenings over the chosen period.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 600) },
    caption: '**Aggregated wizard — Step 2 (scrolled to Gender).** Category split below the COUNTS group. Each category is its own visual section so the user knows where the form transitions. *Save draft* lets the data officer leave and return; *Submit* finalises and queues for sync.' },
  { stage: 'route', route: '/aggregated-data/history',
    caption: '**Aggregated submission history — list.** Filter chips: 30d / 90d / This year / All. Each card: period range, traveller counts, symptomatic rate, gender sum, and a plain *Uploaded / Waiting to upload* badge (Round-7).' },
  { stage: 'action',
    prep: async (page) => {
      await page.evaluate(() => {
        const cards = [...document.querySelectorAll('.ag-card, [class*="ag-card"]')]
        cards[0]?.click()
      })
      await sleep(900)
    },
    caption: '**Aggregated submission detail — Quality checks.** The Round-7 redesign. Period header, headline counts, gender breakdown, then a green-tick checklist: "Male + Female adds up to total screened ✓", "With-symptoms + No-symptoms adds up to total screened ✓", "Reporting period covers 7 days ✓". Schema metadata (Server ID, Record version, app_version) is hidden behind a *Technical details* disclosure.' },
  { stage: 'action',
    prep: async (page) => {
      // Open the technical-details disclosure
      await page.evaluate(() => {
        const det = [...document.querySelectorAll('details.ag-tech, details')].find(d => /technical/i.test(d.querySelector('summary')?.textContent || ''))
        if (det) det.open = true
      })
      await sleep(300)
      await scrollTo(page, 1400)
    },
    caption: '**Aggregated submission detail — Technical details expanded.** When the user opens the disclosure they see the schema-bleed fields (Last edit / Server ID / Device & app version / Record version). Closed by default so non-technical readers aren\'t confronted with `record_version v2`.' },

  // ── 10. AGGREGATED template wizard (admin) ──────────────────────────────
  { stage: 'route', route: '/admin/aggregated-templates',
    caption: '**Aggregated template settings (admin).** NATIONAL_ADMIN-only. Lists every template (published + retired). Per row: edit columns, toggle published, retire. The cascade-delete confirm warns that pending submissions filed against the template stay valid but new submissions become impossible.' },
  { stage: 'route', route: '/admin/aggregated-wizard',
    caption: '**Aggregated template wizard — Step 1 / Identity.** Build a brand-new template. Step 1 captures name, code, frequency (Daily / Weekly / Monthly / Quarterly / Ad-hoc / Event-based), reporting unit, target audience role keys.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 700) },
    caption: '**Aggregated template wizard (scrolled).** Subsequent steps appear inline as the user fills the form. The wizard guides through identity → columns → validation rules → audience → publish.' },
  { stage: 'route', route: '/admin/poe-contacts',
    caption: '**POE notification contacts (admin).** Escalation roster — who gets called/emailed when something fires at this POE. Each contact: name, role title, phone, email, escalation order (1 = first to call), active flag. New / edit / delete via the floating action button.' },

  // ── 11. Disease intelligence ───────────────────────────────────────────
  { stage: 'route', route: '/DiseaseInteligence',
    caption: '**Tracked Diseases (Disease Intelligence).** WHO/IHR catalogue of every disease the syndromic engine knows. Search by name, filter by Tier or syndrome.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 700) },
    caption: '**Tracked Diseases — scrolled.** Disease cards continue. Tap any disease for the detail page with case definition, endemic countries, IHR tier, recommended actions.' },

  // ── 12. Sync ───────────────────────────────────────────────────────────
  { stage: 'route', route: '/sync',
    caption: '**Sync Centre — Queue tab.** Default tab. Stats strip at top (Synced / Pending / Failed / Quarantined per store). *Sync Now* runs the upload single-flight (re-entrance guarded — Round-2 fix). Tabs: Queue / History / Failed retries.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 700) },
    caption: '**Sync Centre — store stats scrolled.** Per-store breakdown (Primary screenings, Notifications, Secondary screenings, Alerts, Aggregated submissions). Each store shows synced / pending / failed counts and a per-store *Sync* button.' },

  // ── 13. POE + Users admin ──────────────────────────────────────────────
  { stage: 'route', route: '/POEs',
    caption: '**POE Registry.** NATIONAL_ADMIN view of every Point of Entry. Filter by province / district / status. Tap a row for the detail/edit modal; the FAB opens the create modal.' },
  { stage: 'action',
    prep: async (page) => {
      await page.evaluate(() => {
        const fab = document.querySelector('ion-fab-button, [class*="fab"], [class*="add-btn"]')
        fab?.click()
      })
      await sleep(800)
    },
    caption: '**POE Registry — Create POE wizard.** Step-wizard form: identity (name, code, type), location (province → district → coordinates), assignment (PHEOC, on-call hours), status. Fields validate live; *Save* commits to IDB + queues for sync.' },
  { stage: 'route', route: '/Users',
    caption: '**User Management — list.** All users in scope, with role + active state badges. Filter by role or status; search by name/username.' },
  { stage: 'action',
    prep: async (page) => {
      await clickByText(page, '+ New')
      await sleep(800)
    },
    caption: '**Users — Create-user form.** Full name, username, email, phone, password, role select, geographic assignment that auto-shows/hides based on role (a screener needs province + district + POE; a national admin needs none). Strong-password indicator + autocomplete=new-password.' },

  // ── 14. Profile + Directory ────────────────────────────────────────────
  { stage: 'route', route: '/profile',
    caption: '**My Profile.** User\'s own account: name, username, email, phone, role, geographic assignment, last-login timestamp. The user can edit phone / email; role + scope are read-only here (only an admin can change them in /Users).' },
  { stage: 'route', route: '/directory',
    caption: '**Staff Directory.** Tap-to-dial across the org. Filter by role / scope. Each contact card has call/SMS buttons that fire `tel:` / `sms:` so they work offline.' },

  // ── 15. SETTINGS — every distinct scroll position ──────────────────────
  { stage: 'route', route: '/settings',
    caption: '**App Settings — Account & Assignment.** Account card (read-only — name, username, email, role). Assignment card below (POE, district, PHEOC, country).' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 350) },
    caption: '**App Settings — Sync & Storage.** Connection state (online/offline), App Version, Reference Data version, Device ID, Server URL, Local Records cached count. Useful for support when the user reports "X isn\'t working".' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 700) },
    caption: '**App Settings — Preferences card.** Single toggle for Haptic Feedback (vibrate on critical alerts and validation errors). On by default; off here saves battery on older devices.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1000) },
    caption: '**App Settings — Capabilities & help (top).** Round-5 placement: this card replaced the standalone "Capabilities & Help" menu row. Header tally "0 of 8 on" because every capability is OFF in the demo seed. Hero CTA *Explore capabilities & how-to* opens the full explorer (/capabilities-help).' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1400) },
    caption: '**App Settings — Capabilities Quick toggles.** Per-capability switches under the hero CTA (Round-7 sub-head separates them). Each row: icon, plain-language name + sub-line, on/off toggle. App-lock row reveals a "Set a PIN" follow-up when toggled on.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1900) },
    caption: '**App Settings — Capture accelerators (Sentinel).** Master kill switch + per-feature toggles (Boarding-pass barcode, Voice fill — Round-7 wording). Lead text: "All off by default — enable individually. Turning them off returns the app to standard manual entry; nothing else changes." A "Disable all accelerators" panic button at the bottom.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 2400) },
    caption: '**App Settings — Actions card.** Manage Sync Queue (link to /sync), Manage offline models (Sentinel ML), Plugin diagnostics (Round-6 self-test runner), Clear Local Cache, Sign Out (red).' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 2900) },
    caption: '**App Settings — About card.** Product blurb, schema version, build year. Bottom of the page.' },

  // ── 16. Settings sub-pages ──────────────────────────────────────────────
  { stage: 'route', route: '/settings/sentinel-models',
    caption: '**Sentinel offline-model manager.** Per-model status (Idle / Downloading / Available / Failed / Quarantined), download progress %, last-used timestamp, file size on disk. *Download* / *Retry* / *Remove* per row. Reachable from Settings → Manage offline models.' },
  { stage: 'route', route: '/settings/diagnostics',
    prep: async (page) => { await sleep(8000) }, // wait for the runner to finish
    caption: '**Plugin diagnostics — results view (Round-6).** Auto-runs on mount. Summary strip: Pass / Warn / Fail / Skip totals + duration + platform stamp. Filter chips. Each suite is a colour-coded card; failing suites auto-expand with per-test detail, error stack, and a green "Fix:" remediation hint. *Copy report* copies a JSON dump for support.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1400) },
    caption: '**Plugin diagnostics — scrolled to per-plugin suites.** Each suite header shows a PASS/WARN/FAIL pill and the suite\'s one-line summary; per-test rows show the probe name + plain-English detail + the green "Fix:" hint when relevant.' },

  // ── 17. Capabilities & Help (the explorer reachable from Settings) ──────
  { stage: 'route', route: '/capabilities-help',
    caption: '**Capabilities & Help — top.** Replay-the-welcome-tour banner. Cards grouped by category (Security / Capture assists / Communication / Connectivity). Each card carries icon, plain-language description, current status indicator, on/off toggle, *Try it now* demo, *Show me in the app* spotlight tour.' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 800) },
    caption: '**Capabilities & Help — Capture assists group.** Voice dictation, Barcode scan, Keep awake. Each *Try it now* invokes the underlying plugin in a non-destructive way (no real recording, no real scan — just a probe).' },
  { stage: 'action',
    prep: async (page) => { await scrollTo(page, 1600) },
    caption: '**Capabilities & Help — Communication + Connectivity groups.** PDF share, Staff Directory, Local notifications, Network probe. Settings → "Explore capabilities & how-to" hero CTA lands here.' },
]

// ── runner ─────────────────────────────────────────────────────────────────

async function main() {
  await ensureDirs()
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
  })

  // Pre-seed IDB once (auth + tour-flags + sentinel-off + sample data).
  {
    const page = await browser.newPage()
    await page.setViewport(VIEWPORT)
    await page.setUserAgent(UA)
    await page.evaluateOnNewDocument(AUTH_SEED)
    await page.goto(`${BASE}/#/home`, { waitUntil: 'networkidle0', timeout: 30000 })
    await sleep(1500)
    await page.evaluate(IDB_SEED)
    await sleep(1500)
    await page.close()
  }

  const results = []
  let lastPage = null

  for (let i = 0; i < PLAN.length; i++) {
    const plan = PLAN[i]
    const num = String(i + 1).padStart(2, '0')
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
    page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()) })
    page.on('pageerror', e => pageErrors.push(String(e?.message || e)))

    process.stdout.write(`  [${num}/${PLAN.length}] ${plan.stage.padEnd(7)} ${routeForName.padEnd(38)} `)
    const t0 = Date.now()
    let ok = true
    let loadMs = null
    let url = null
    try {
      if (goto && plan.route) {
        url = `${BASE}/#${plan.route.startsWith('/') ? plan.route : '/' + plan.route}`
        await page.goto(url, { waitUntil: 'networkidle0', timeout: 30000 })
        loadMs = Date.now() - t0
        await sleep(1300)
        // Defensive: dismiss any overlay that snuck in.
        await dismissOverlays(page)
      }
      if (plan.stage === 'menu') {
        try { await page.evaluate(() => document.querySelector('ion-menu-button')?.click()) } catch {}
        await sleep(750)
      }
      if (plan.prep) {
        try { await plan.prep(page) } catch (err) { /* prep best-effort */ }
        await sleep(450)
      }
      // Final defensive overlay sweep just before snapping
      await dismissOverlays(page)
      const fullPage = plan.stage !== 'menu' && plan.stage !== 'login'
      await page.screenshot({ path: file, fullPage })
    } catch (err) {
      ok = false
      pageErrors.push(`error: ${err.message}`)
    }

    process.stdout.write(`${ok ? '✓' : '✗'} ${loadMs ?? '—'}ms\n`)
    results.push({
      num, stage: plan.stage, route: routeForName, filename,
      file: ok ? `screenshots/${filename}` : null,
      caption: plan.caption,
      ok, loadMs, url,
      consoleErrors, pageErrors,
    })

    if (i + 1 < PLAN.length && PLAN[i + 1].stage === 'action') lastPage = page
    else { try { await page.close() } catch {} ; lastPage = null }
  }
  if (lastPage) try { await lastPage.close() } catch {}

  await browser.close()
  await buildIndex(results)
  await writeFile(MANIFEST, JSON.stringify({
    generatedAt: new Date().toISOString(),
    baseUrl: BASE, viewport: VIEWPORT,
    total: results.length,
    captured: results.filter(r => r.ok).length,
    items: results,
  }, null, 2))

  console.log('')
  console.log(`Captured:      ${results.filter(r => r.ok).length} / ${results.length}`)
  console.log(`Screenshots:   _audit/PRESENTATION/screenshots/`)
  console.log(`Gallery:       _audit/PRESENTATION/INDEX.md`)
  console.log(`Manifest:      _audit/PRESENTATION/MANIFEST.json`)
  const fails = results.filter(r => !r.ok)
  if (fails.length) {
    console.log(`Failures (${fails.length}):`)
    for (const f of fails) console.log(`  ✗ ${f.num} ${f.stage} ${f.route} — ${f.pageErrors[0] || 'unknown'}`)
  }
}

async function buildIndex(results) {
  const lines = []
  lines.push('# POE Sentinel — Full UI Walkthrough')
  lines.push('')
  lines.push(`Generated: ${new Date().toISOString()}`)
  lines.push(`Base URL: ${BASE}`)
  lines.push(`Total screenshots: ${results.filter(r => r.ok).length} / ${results.length}`)
  lines.push(`Viewport: Pixel 6 (412 × 915, 2× DPR), Android Chrome UA`)
  lines.push('')
  lines.push('Walks the **full Primary capture loop** (capture form → symptomatic state → records list/detail → referral queue), the **full Secondary investigation wizard** (Profile → Symptoms → Exposures → Analysis), the **Aggregated submission wizard**, the **Aggregated template wizard**, and varies scroll positions in long views (Settings shows 8 distinct scroll points; Capabilities & Help shows 3 groups).')
  lines.push('')
  lines.push('Web-only constraints respected:')
  lines.push('- **Sentinel features are OFF in the seed** so the camera/voice plugin buttons don\'t render. Web cannot safely trigger their OS-level prompts.')
  lines.push('- **Tours and coachmarks are pre-marked "seen"** so no overlay covers a screenshot.')
  lines.push('- **Modal dismiss + Escape** are sent before every shot as a defensive sweep.')
  lines.push('')
  lines.push('Stages:')
  lines.push('- **login** — un-authenticated overlay (no auth seeded)')
  lines.push('- **route** — initial render of a route with auth seeded as NATIONAL_ADMIN')
  lines.push('- **menu** — side-menu drawer opened on top of a route')
  lines.push('- **action** — same page as the previous shot, after triggering an interaction (filling a form, opening a modal, clicking a tab, scrolling)')
  lines.push('')
  lines.push('IndexedDB is pre-seeded with deterministic UUIDs so every detail modal + the Secondary wizard loads with real-looking data. No real backend is contacted.')
  lines.push('')
  lines.push('---')
  for (const r of results) {
    lines.push('')
    lines.push(`## ${r.num}. ${r.route} &nbsp;·&nbsp; *${r.stage}*`)
    lines.push('')
    if (r.file) {
      lines.push(`![${r.route}](${r.file})`)
    } else {
      lines.push(`*Screenshot not captured — ${r.pageErrors?.[0] || 'unknown error'}*`)
    }
    lines.push('')
    lines.push(r.caption)
    lines.push('')
    if (r.consoleErrors?.length) {
      lines.push(`<details><summary>Console messages (${r.consoleErrors.length})</summary>`)
      lines.push('')
      for (const e of r.consoleErrors.slice(0, 4)) lines.push(`- \`${e.replace(/`/g, "'").slice(0, 200)}\``)
      lines.push('')
      lines.push('</details>')
      lines.push('')
    }
    lines.push('---')
  }
  await writeFile(INDEX_MD, lines.join('\n'))
}

main().catch(e => { console.error('fatal', e); process.exit(2) })
