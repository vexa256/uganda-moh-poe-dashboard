/**
 * tests/primary-secondary-walkthrough.mjs
 *
 * FOCUSED walkthrough: ONLY Primary Screening + Secondary Screening, every
 * stage of the form, every meaningful scroll position. Designed for the
 * presentation deck.
 *
 *   - Welcome tour ("New features unlocked") + per-feature coachmarks are
 *     SUPPRESSED before the run by writing the seen-flags to localStorage.
 *   - After the run, those flags are CLEARED so the next launch behaves
 *     exactly as a real user would experience (defensive — the puppeteer
 *     browser is closed anyway).
 *
 *   - Sentinel features stay OFF so no plugin/scan/voice popup ever fires.
 *
 *   - Output:
 *       _audit/PRESENTATION/screening-flow/screenshots/<NN>_<slug>.png
 *       _audit/PRESENTATION/screening-flow/INDEX.md
 *       _audit/PRESENTATION/screening-flow/MANIFEST.json
 *
 * Prereq: a vite preview running at BASE_URL (default http://127.0.0.1:4173).
 */

import puppeteer from 'puppeteer'
import { mkdir, writeFile, rm } from 'node:fs/promises'
import { existsSync } from 'node:fs'
import { resolve, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const REPO = resolve(__dirname, '..')
const BASE = process.env.BASE_URL || 'http://127.0.0.1:4173'
const ROOT = resolve(REPO, '_audit/PRESENTATION/screening-flow')
const SHOTS = resolve(ROOT, 'screenshots')
const INDEX_MD = resolve(ROOT, 'INDEX.md')
const MANIFEST = resolve(ROOT, 'MANIFEST.json')

const VIEWPORT = { width: 412, height: 915, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
const UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 6) AppleWebKit/537.36 ' +
           '(KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36'

// Match the constant in src/services/tour.js so the welcome tour treats this
// device as already on-version.
const TOUR_VERSION = '2026.04.24-wave1'

const NOTIF_UUID        = 'demo01-notif-4444-aaaa-555566667777'
const PRIMARY_SYMP_UUID = 'demo01-prim2-4444-bbbb-555566667777'
const PRIMARY_OK_UUID   = 'demo01-prim1-4444-cccc-555566667777'
const SECONDARY_UUID    = 'demo01-secondary4444-dddd-666677778888'

// ── Auth + tour-suppress seed ──────────────────────────────────────────────
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
    // ── SUPPRESS the welcome tour ("New features unlocked" 5-card sequence).
    // tour.js checks getValue(CAPABILITY_KEYS.TOUR_SEEN) === TOUR_VERSION; if
    // they match, maybeRunWelcomeTour() returns early. This suppresses the
    // modal that otherwise appears 1.2s after login on every fresh device.
    localStorage.setItem('cap.tour.seen_version', '${TOUR_VERSION}')
    // ── SUPPRESS per-view coachmarks. Each one is gated by a tour.seen.<key>
    // localStorage flag; pre-stamping them prevents the spotlight popup.
    for (const k of [
      'primary.scan-intro',
      'directory.first-open',
      'caps-help.first-open',
      'sentinel.first-toggle',
    ]) try { localStorage.setItem('tour.seen.' + k, '1') } catch {}
    // ── Sentinel features OFF so no scan/voice plugin button can render and
    // accidentally pop an OS-level prompt that blocks the headless browser.
    for (const k of [
      'cap.sentinel.master.enabled',
      'cap.bcbp.enabled', 'cap.voice_wizard.enabled',
      'cap.barcode.enabled',
    ]) try { localStorage.setItem(k, '0') } catch {}
  } catch {}
})()`

// ── Restore (clear suppression flags) ───────────────────────────────────────
const RESTORE_SEED = `(() => {
  try {
    // Re-enable the welcome tour for any future user of this storage profile.
    localStorage.removeItem('cap.tour.seen_version')
    for (const k of [
      'tour.seen.primary.scan-intro',
      'tour.seen.directory.first-open',
      'tour.seen.caps-help.first-open',
      'tour.seen.sentinel.first-toggle',
    ]) try { localStorage.removeItem(k) } catch {}
    // (Sentinel feature flags left OFF — that's the production default.)
  } catch {}
})()`

// ── IDB seed (correct field names — see prior round's findings) ─────────────
const IDB_SEED = `(async () => {
  try {
    const now = new Date().toISOString()
    const yesterday = new Date(Date.now() - 86400000).toISOString()
    const NOTIF_UUID = '${NOTIF_UUID}'
    const PRIMARY_SYMP_UUID = '${PRIMARY_SYMP_UUID}'
    const PRIMARY_OK_UUID = '${PRIMARY_OK_UUID}'
    const SECONDARY_UUID = '${SECONDARY_UUID}'

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

    // ── Primary screenings: 1 asymptomatic + 1 symptomatic so the Records
    // tab has something to render and the Records detail modal can open.
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

    // ── Notification (referral) — links the symptomatic primary to the
    // secondary case below. notification_id (NOT notification_uuid) is the
    // index field SecondaryScreening.vue queries against.
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

    // ── Secondary case — DISPOSITIONED so the resume block sets
    // step.value = 4 AND maxStepReached.value = 4, allowing direct jumps
    // to all 4 step pills (Round-2 SS-001 fix). Field names match the
    // resume Object.assign (traveler_*, final_disposition, is_present, etc.).
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

    // ── Symptom + exposure rows (use is_present, not response — see prior fix).
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
      country_code: 'CD',
      visited_from: new Date(Date.now() - 7*86400000).toISOString().slice(0, 10),
      visited_to: yesterday.slice(0, 10),
      sync_status: 'UNSYNCED', created_at: yesterday, updated_at: now,
    }
    const susp1 = {
      client_uuid: 'demo01-disease-4444-aaaa-1', secondary_screening_id: SECONDARY_UUID,
      disease_code: 'INFLUENZA', rank_order: 1, confidence: 0.42,
      reasoning: 'ALGORITHM_TOP', sync_status: 'UNSYNCED',
      created_at: now, updated_at: now,
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
  } catch (e) {
    console.warn('IDB seed failed', e?.message)
  }
})()`

// ── helpers ─────────────────────────────────────────────────────────────────
function slug(s) {
  return String(s).replace(/[^a-zA-Z0-9._-]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60) || 'shot'
}
const sleep = (ms) => new Promise(r => setTimeout(r, ms))

async function ensureDirs() {
  if (!existsSync(ROOT)) await mkdir(ROOT, { recursive: true })
  try { await rm(SHOTS, { recursive: true, force: true }) } catch {}
  await mkdir(SHOTS, { recursive: true })
}

async function clickByText(page, text) {
  return await page.evaluate((t) => {
    const list = [...document.querySelectorAll('button')]
    const lo = t.toLowerCase()
    const m = list.find(el => (el.textContent || '').trim().toLowerCase() === lo)
              || list.find(el => (el.textContent || '').trim().toLowerCase().includes(lo))
    if (m) { m.scrollIntoView({ block: 'center' }); m.click(); return true }
    return false
  }, text)
}
async function clickSel(page, sel) {
  return await page.evaluate((s) => {
    const el = document.querySelector(s)
    if (el) { el.scrollIntoView({ block: 'center' }); el.click(); return true }
    return false
  }, sel)
}
async function scrollIonContent(page, y) {
  await page.evaluate((y) => {
    window.scrollTo({ top: y, behavior: 'instant' })
    document.querySelector('ion-content')?.scrollToPoint?.(0, y, 0)
  }, y)
}
async function dismissOverlays(page) {
  try { await page.keyboard.press('Escape') } catch {}
  await page.evaluate(() => {
    const close = [...document.querySelectorAll('button')]
      .find(b => /^(got it|close|skip|dismiss|continue|next)$/i.test((b.textContent || '').trim()))
    close?.click()
    document.querySelectorAll('[class*="tour"], [class*="coach"]').forEach(el => {
      try { el.style.display = 'none' } catch {}
    })
  })
}

// ── shot plan ──────────────────────────────────────────────────────────────
// Two routes only — Primary Screening + Secondary Screening — every stage
// of each form, every meaningful scroll. Each entry produces ONE shot.
const SECONDARY_PATH = '/secondary-screening/' + NOTIF_UUID
const PLAN = [
  // ════════════════════════════════════════════════════════════════════════
  // PRIMARY SCREENING — full capture loop
  // ════════════════════════════════════════════════════════════════════════
  { route: '/PrimaryScreening', stage: 'route',
    caption: '**Primary Screening — Capture tab, top.** This is the kiosk capture screen the screener uses for every traveller. Three tabs along the header: Capture / Records / Referral Queue. Capture starts with **Direction pills** — the screener\'s first decision. The numbers strip above the tabs is a live tally for the current shift (Today / Symptomatic / Synced / Pending / Queue).' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 350) },
    caption: '**Primary Screening — middle of capture form (Sex + Temperature).** After Direction, the screener picks **Sex** (Male / Female), then optionally enters **Temperature** in °C or °F. The unit toggle sits next to the field. Temperature ≥ 37.5°C arms the fever indicator; ≥ 38.5°C is High Fever (per WHO IHR Annex 2).' },
  { stage: 'action',
    prep: async (p) => {
      await clickByText(p, '→ Entry')
      await sleep(120)
      await clickByText(p, '♂ Male')
      await sleep(150)
    },
    caption: '**Primary Screening — Direction + Sex selected.** Direction = Entry (highlighted blue), Sex = Male (highlighted). The screener has now committed both cardinal facts. The Capture button at the bottom is still inactive until Symptoms is answered.' },
  { stage: 'action',
    prep: async (p) => {
      await p.evaluate(() => {
        const t = document.querySelector('input[type="number"]') || document.querySelector('input[inputmode="decimal"]')
        if (t) { t.focus(); t.value = '38.6'; t.dispatchEvent(new Event('input', { bubbles: true })) }
      })
      await sleep(200)
    },
    caption: '**Primary Screening — Temperature 38.6°C entered (High Fever).** A red **High fever** chip appears next to the temperature input as soon as the value crosses 38.5°C. This is a visual cue for the screener that this traveller is almost certainly going to be a referral.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 700) },
    caption: '**Primary Screening — Symptoms section visible.** The two big tap targets are *Clear* (No symptoms) and *Symptomatic* (Referral created). Each has its own colour and icon. The tooltip *IHR Required* sits next to the label so the screener knows this is mandatory under WHO IHR 2005.' },
  { stage: 'action',
    prep: async (p) => {
      await clickSel(p, '.ps-sym-yes')
      await sleep(200)
      await scrollIonContent(p, 800)
    },
    caption: '**Primary Screening — Symptomatic selected.** The Symptomatic card is now active (filled red). The IHR Surveillance Symptoms reference panel below offers a one-tap reference of WHO Annex-2 syndromes (cough, fever, jaundice, haemorrhagic signs etc.) — purely informational, doesn\'t affect capture.' },
  { stage: 'action',
    prep: async (p) => {
      await scrollIonContent(p, 1000)
      await p.evaluate(() => {
        const n = document.querySelector('.ps-name-input, input[placeholder*="ame" i]')
        if (n) { n.focus(); n.value = 'Joseph Phiri'; n.dispatchEvent(new Event('input', { bubbles: true })) }
      })
      await sleep(200)
    },
    caption: '**Primary Screening — Name field revealed (only when Symptomatic).** When the screener picks Symptomatic, the Name field reveals progressively. Optional but recommended for referrals so the secondary officer has a name to call. *Scan* icon (Sentinel feature, OFF in this demo) would let them auto-fill from passport / health-declaration QR.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 1300) },
    caption: '**Primary Screening — Capture button armed (red Referral style).** The big bottom button is now enabled and styled red (Sentinel-7 fix C-B1: clearly says "Will create a referral"). Tapping commits BOTH the screening AND a SECONDARY_REFERRAL notification atomically (single dbAtomicWrite — both or neither). On success: critical haptic + a "Referral filed" toast naming the priority.' },
  { stage: 'action',
    prep: async (p) => { await clickByText(p, 'Capture'); await sleep(1500); await scrollIonContent(p, 0) },
    caption: '**Primary Screening — after Capture (Result panel).** The result strip slides in at the top: ✓ Saved / ⚠ Referral Created (if symptomatic) with the priority chip + a today-count. The form auto-clears for the next traveller. The result strip auto-dismisses after 5 s (Round-3 SCR-1 fix) or on tap of *Next traveller →*.' },
  { stage: 'action',
    prep: async (p) => {
      await scrollIonContent(p, 0)
      await clickByText(p, 'Records')
      await sleep(900)
    },
    caption: '**Primary Screening — Records tab, top.** The officer\'s personal case register. Filter chip strip pinned to the top: All / Symptomatic / Clear / Pending / All Directions. Below: a 1-line summary ("1 record"). Each card shows traveller name + sex, captured time, temperature, symptomatic + sync status badges.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 600) },
    caption: '**Primary Screening — Records tab scrolled.** Older records below the fold. With only one record in the demo seed, the list is short — in production the officer paginates through their shift\'s captures. Long-press a card surfaces the void affordance.' },
  { stage: 'action',
    prep: async (p) => {
      await scrollIonContent(p, 0)
      await clickSel(p, '.ps-rec-card')
      await sleep(900)
    },
    caption: '**Primary Screening — Record detail modal.** Slide-up sheet showing one screening read-only: Direction / Sex / Full Name / Temperature / Symptoms / POE / Captured time / Sync state / Server ID. Big red **Void This Record** button at the bottom opens a void-with-reason flow.' },
  { stage: 'action',
    prep: async (p) => {
      await dismissOverlays(p)
      await p.evaluate(() => document.querySelector('ion-modal')?.dismiss?.())
      await sleep(500)
      await clickByText(p, 'Referral Queue')
      await sleep(900)
    },
    caption: '**Primary Screening — Referral Queue tab.** Local view of OPEN secondary referrals raised on this device, before they sync to the central /NotificationsCenter queue. Each row: traveller, time, priority pill. Tap *Open* to hand the referral to the secondary officer.' },

  // ════════════════════════════════════════════════════════════════════════
  // SECONDARY SCREENING — full 4-step wizard, with scrolls per step
  // ════════════════════════════════════════════════════════════════════════
  { route: SECONDARY_PATH, stage: 'route',
    caption: '**Secondary Screening — Step 1 / Profile, top.** The WHO/IHR-aligned investigation wizard. Top bar shows case status pill (Pending) + traveller header (Joseph Phiri, ENTRY, HIGH risk). 4-step progress bar (Profile · Symptoms · Exposures · Analysis) — tap any green step to jump back. Step 1 starts with **Traveller Identity**: Full Name, Gender, Age (years), Nationality.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 600) },
    caption: '**Secondary Screening — Step 1 scrolled to Travel Document.** Document Type pills (Passport / National ID / Laissez-Passer / Other) → Document Number input. The card design groups every Step-1 sub-section into its own labelled card so the officer knows where they are.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 1100) },
    caption: '**Secondary Screening — Step 1 scrolled to Geographic / Conveyance.** Phone, Journey-start country, Conveyance type (Air / Road / Rail / Sea / Foot), Conveyance identifier (e.g. flight or bus number), Arrival date-time, Purpose of travel, Destination district. All used by the engine to enrich the Step-4 risk assessment.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 1700) },
    caption: '**Secondary Screening — Step 1 scrolled to Vitals & Triage.** Temperature, Pulse, Respiratory rate, BP, SpO₂, Triage category (Green / Yellow / Red), Emergency signs flag, General appearance free-text. Vitals reveal automatically when temperature is set (anything else cascades from there).' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 2400) },
    caption: '**Secondary Screening — Step 1 scrolled to bottom (Save & Next).** Bottom of step shows the *Save & Next* CTA. Saving commits the case row (or updates the existing one), advances to Step 2, and bumps `maxStepReached` so the user can later jump back to this step.' },

  { stage: 'action',
    prep: async (p) => {
      await scrollIonContent(p, 0)
      await p.evaluate(() => { const pills = [...document.querySelectorAll('.sc-step')]; pills[1]?.click() })
      await sleep(900)
    },
    caption: '**Secondary Screening — Step 2 / Symptoms (Symptom Checklist top).** Multi-select symptom checklist organised by WHO Annex-2 category: **Fever & Systemic** (Fever / High fever / Sudden-onset fever / Low-grade / Chills-Rigors / Fatigue / Severe Fatigue / Weakness-Malaise). Each cell toggles between Off / Present. The badge top-right counts how many are present.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 700) },
    caption: '**Secondary Screening — Step 2 scrolled (Respiratory + GI).** Continues with **Respiratory** (Cough / Dry cough / Shortness of breath / Difficulty breathing / Sore throat / Runny nose), then **Gastrointestinal** (Nausea / Vomiting / Diarrhoea / Profuse watery diarrhoea). Tapping any cell flips it on; tap again to clear.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 1500) },
    caption: '**Secondary Screening — Step 2 scrolled to Neurological + Dermatological.** Headache, Confusion, Seizures, Stiff neck, Photophobia, then Rash, Jaundice, Conjunctivitis, etc. Each section is colour-coded; the small dot in the section header lights up if any symptom in that group is present.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 2400) },
    caption: '**Secondary Screening — Step 2 scrolled to Haemorrhagic + Notes.** The Haemorrhagic group (Unusual bleeding / Petechiae / Ecchymoses / Haematuria / Bloody stools / Bloody vomit) is the biggest red-flag set — any one of these escalates the engine\'s suspected-disease ranking towards VHF (Ebola / Marburg / Lassa / CCHF). Then a free-text Symptoms summary + onset date.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 3200) },
    caption: '**Secondary Screening — Step 2 scrolled to bottom (Save & Next).** Save & Next persists the symptom rows + the symptoms_summary on the case, advances to Step 3.' },

  { stage: 'action',
    prep: async (p) => {
      await scrollIonContent(p, 0)
      await p.evaluate(() => { const pills = [...document.querySelectorAll('.sc-step')]; pills[2]?.click() })
      await sleep(900)
    },
    caption: '**Secondary Screening — Step 3 / Exposures, top.** *Structured Exposure Questionnaire.* Ask the traveller each question; pick the most accurate response. **Unknown** is the default — change to Yes / No when certain. Top group: **Travel & Geographic risk** (visited a country with known outbreak / resident of an area with ongoing outbreak).' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 700) },
    caption: '**Secondary Screening — Step 3 scrolled to Person-to-person contact.** Close contact with a symptomatic individual / contact with someone subsequently confirmed positive / household member ill. Each question shows a brief *Why we ask* sub-line so the officer can explain it to the traveller.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 1400) },
    caption: '**Secondary Screening — Step 3 scrolled to Healthcare + Animal exposures.** Visit to a healthcare facility in the past 14 days, contact with bats / livestock / sick or dead animals, mosquito-rich environment, food/water from suspect source. The animal cluster drives the zoonotic-disease ranking in Step 4.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 2100) },
    caption: '**Secondary Screening — Step 3 scrolled to Travel Countries list.** Below the questions, the officer logs every country visited in the last 14 days, with from/to dates. Each country is cross-referenced against the engine\'s endemic-zone map to drive the Step-4 syndrome scoring.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 2900) },
    caption: '**Secondary Screening — Step 3 scrolled to bottom (Analyse →).** Bottom CTA reads *Analyse →* (orange). Tapping runs the diagnostic engine across all symptoms + exposures + travel countries, ranks suspected diseases, classifies the syndrome, and computes IHR risk + routing destination.' },

  { stage: 'action',
    prep: async (p) => {
      await scrollIonContent(p, 0)
      await p.evaluate(() => { const pills = [...document.querySelectorAll('.sc-step')]; pills[3]?.click() })
      await sleep(1500)
    },
    caption: '**Secondary Screening — Step 4 / Analysis, top.** Engine output card. The big green / red badge is the headline ("NON-CASE — No Clinical Indicators" if specificity gates fail; "SUSPECT — VHF / RESPIRATORY / etc." if not). Below: rationale + recommended disposition. *Officer Override — I disagree* below lets the clinician override.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 600) },
    caption: '**Secondary Screening — Step 4 scrolled to Suspected Disease.** A "Select disease ▾" dropdown lets the officer add or override the engine\'s top suggestion (the suspected-diseases catalogue is the same one used by /DiseaseInteligence).' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 1100) },
    caption: '**Secondary Screening — Step 4 scrolled to Syndrome Classification.** A required pill list: respiratory / haemorrhagic / GI / neuro / dermatological / unspecified febrile / other. The engine pre-selects its best guess; the officer confirms or changes. This drives the WHO IHR Annex-2 reporting category on the alert.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 1700) },
    caption: '**Secondary Screening — Step 4 scrolled to Risk + Routing override.** Engine-suggested risk (LOW/MEDIUM/HIGH/CRITICAL) and routing level (POE → DISTRICT → PHEOC → NATIONAL). Officer override toggle below — *Apply suggestion* button on the right when the officer\'s pick disagrees.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 2300) },
    caption: '**Secondary Screening — Step 4 scrolled to Disposition.** Final disposition radio: **Released** / **Quarantined** (held for observation) / **Isolated** (held alone for medical reasons) / **Referred** (sent to a clinic). Each option carries a one-line definition so a non-clinician knows what they\'re picking.' },
  { stage: 'action',
    prep: async (p) => { await scrollIonContent(p, 2900) },
    caption: '**Secondary Screening — Step 4 scrolled to Officer Notes + Save & Disposition.** Free-text officer notes + a follow-up checkbox (assigned to DISTRICT / PHEOC / NATIONAL). The bottom *Save & Disposition* button commits the final case_status to DISPOSITIONED, raises the IHR alert if required, and closes the referral notification.' },
]

// ── runner ─────────────────────────────────────────────────────────────────
async function main() {
  await ensureDirs()
  const browser = await puppeteer.launch({
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
  })

  // Pre-seed: auth + tour-suppression + IDB sample data
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
    const filename = `${num}_${slug(routeForName)}.png`
    const file = resolve(SHOTS, filename)

    let page = lastPage
    let goto = false
    if (plan.stage !== 'action' || !page) {
      if (page) try { await page.close() } catch {}
      page = await browser.newPage()
      await page.setViewport(VIEWPORT)
      await page.setUserAgent(UA)
      await page.evaluateOnNewDocument(AUTH_SEED)
      goto = true
    }

    const consoleErrors = []
    const pageErrors = []
    page.on('console', m => { if (m.type() === 'error') consoleErrors.push(m.text()) })
    page.on('pageerror', e => pageErrors.push(String(e?.message || e)))

    process.stdout.write(`  [${num}/${PLAN.length}] ${(plan.route || 'action').padEnd(56)} `)
    let ok = true
    let loadMs = null
    try {
      const t0 = Date.now()
      if (goto && plan.route) {
        await page.goto(`${BASE}/#${plan.route}`, { waitUntil: 'networkidle0', timeout: 30000 })
        loadMs = Date.now() - t0
        await sleep(1300)
        await dismissOverlays(page)
      }
      if (plan.prep) {
        try { await plan.prep(page) } catch {}
        await sleep(450)
      }
      await dismissOverlays(page)
      await page.screenshot({ path: file, fullPage: true })
    } catch (err) {
      ok = false
      pageErrors.push(`error: ${err.message}`)
    }

    process.stdout.write(`${ok ? '✓' : '✗'} ${loadMs ?? '—'}ms\n`)
    results.push({
      num, stage: plan.stage, route: routeForName, filename,
      file: ok ? `screenshots/${filename}` : null,
      caption: plan.caption,
      ok, loadMs, consoleErrors, pageErrors,
    })

    if (i + 1 < PLAN.length && PLAN[i + 1].stage === 'action') lastPage = page
    else { try { await page.close() } catch {} ; lastPage = null }
  }
  if (lastPage) try { await lastPage.close() } catch {}

  // Restore: clear the tour-suppression flags so the next launch behaves
  // exactly as a real user would experience.
  {
    const page = await browser.newPage()
    await page.setViewport(VIEWPORT)
    await page.setUserAgent(UA)
    await page.goto(`${BASE}/#/home`, { waitUntil: 'networkidle0', timeout: 15000 }).catch(() => {})
    await sleep(500)
    await page.evaluate(RESTORE_SEED)
    await sleep(300)
    await page.close()
  }

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
  console.log(`Screenshots:   _audit/PRESENTATION/screening-flow/screenshots/`)
  console.log(`Gallery:       _audit/PRESENTATION/screening-flow/INDEX.md`)
  console.log(`Manifest:      _audit/PRESENTATION/screening-flow/MANIFEST.json`)
  const fails = results.filter(r => !r.ok)
  if (fails.length) {
    console.log(`Failures (${fails.length}):`)
    for (const f of fails) console.log(`  ✗ ${f.num} ${f.route} — ${f.pageErrors[0] || 'unknown'}`)
  }
}

async function buildIndex(results) {
  const lines = []
  lines.push('# Primary + Secondary Screening — Full Walkthrough')
  lines.push('')
  lines.push(`Generated: ${new Date().toISOString()}`)
  lines.push(`Base URL: ${BASE}`)
  lines.push(`Total screenshots: ${results.filter(r => r.ok).length} / ${results.length}`)
  lines.push(`Viewport: Pixel 6 (412 × 915, 2× DPR), Android Chrome UA`)
  lines.push('')
  lines.push('Focused walkthrough of the **two most important flows in the app**: the Primary Screening capture loop (kiosk capture → records → referral queue) and the Secondary Screening 4-step WHO/IHR investigation wizard. Every form stage + every meaningful scroll.')
  lines.push('')
  lines.push('Tour suppression:')
  lines.push('- The **welcome tour** ("New features unlocked" 5-card sequence) is **suppressed** before the run by setting `cap.tour.seen_version = ' + TOUR_VERSION + '` in localStorage.')
  lines.push('- Per-view **coachmarks** (`tour.seen.<key>` flags) are pre-stamped so no spotlight pops over a screenshot.')
  lines.push('- After the run completes, those flags are **cleared** so the next launch behaves exactly as a real user would experience.')
  lines.push('')
  lines.push('Web-only constraints respected: Sentinel features OFF (no scan / voice / camera buttons render), defensive Escape + Close + tour-class hide before every shot.')
  lines.push('')
  lines.push('Sample data: 1 asymptomatic + 1 symptomatic primary screening, 1 referral notification linking the symptomatic primary to a DISPOSITIONED secondary case (Joseph Phiri, MALE, 34, 38.6°C, RESPIRATORY syndrome, HIGH risk, REFERRED disposition).')
  lines.push('')
  lines.push('---')
  for (const r of results) {
    lines.push('')
    lines.push(`## ${r.num}. ${r.route}`)
    lines.push('')
    if (r.file) lines.push(`![${r.route}](${r.file})`)
    else lines.push(`*Screenshot not captured — ${r.pageErrors?.[0] || 'unknown error'}*`)
    lines.push('')
    lines.push(r.caption)
    lines.push('')
    lines.push('---')
  }
  await writeFile(INDEX_MD, lines.join('\n'))
}

main().catch(e => { console.error('fatal', e); process.exit(2) })
