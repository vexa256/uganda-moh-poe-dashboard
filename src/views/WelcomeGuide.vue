<template>
  <IonPage>
    <!-- ══════════════════════════════════════════════════════════════
         WELCOME / ROLE BRIEFING — the first thing a user sees after
         login. A deterministic "AI"-style page that reads role, scope,
         time-of-day and offline/sync state to produce a curated set
         of icon tiles linking to exactly what *this* user can do, with
         short guides and deep links into the app.

         There is no model and no network call — the "intelligence" is
         a rules engine driven by the RBAC matrix + a few world-state
         signals (online, pending-sync, time-of-day, incoming referrals
         count cached in localStorage). This makes it fast, predictable
         and offline-safe — the exact opposite of a hallucination.
    ══════════════════════════════════════════════════════════════ -->
    <IonHeader class="wg-hdr" translucent>
      <div class="wg-hdr-bg">
        <div class="wg-hdr-top">
          <IonMenuButton menu="app-menu" class="wg-menu"/>
          <div class="wg-hdr-id">
            <span class="wg-eyebrow">AI BRIEFING · {{ todayLabel }}</span>
            <span class="wg-h1">{{ greeting }}, {{ firstName }}</span>
          </div>
          <button class="wg-skip" type="button" @click="skipToHome">
            Skip
            <svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="6 3 11 8 6 13"/></svg>
          </button>
        </div>

        <div class="wg-hero">
          <div class="wg-hero-l">
            <div class="wg-chip-row">
              <span class="wg-chip wg-chip--role">{{ roleLabel }}</span>
              <span class="wg-chip wg-chip--scope" v-if="scopeCode">{{ scopeLabel }} · {{ scopeCode }}</span>
              <span :class="['wg-chip', isOnline ? 'wg-chip--on' : 'wg-chip--off']">
                <span class="wg-chip-dot"/>{{ isOnline ? 'Online' : 'Offline' }}
              </span>
            </div>
            <h2 class="wg-lead">{{ lead }}</h2>
            <p class="wg-sub">{{ subLead }}</p>
          </div>
        </div>

        <!-- narrative insights — up to 3, generated deterministically -->
        <div v-if="insights.length" class="wg-insights">
          <div v-for="(ins, i) in insights" :key="i" :class="['wg-ins', 'wg-ins--'+ins.tone]">
            <span class="wg-ins-dot"/>
            <span class="wg-ins-t">{{ ins.text }}</span>
          </div>
        </div>
      </div>
    </IonHeader>

    <IonContent class="wg-content" :fullscreen="true">
      <div class="wg-body">
        <!-- PRIMARY SECTION — "What you can do right now" -->
        <section class="wg-sec">
          <header class="wg-sec-h">
            <span class="wg-sec-t">What you can do right now</span>
            <span class="wg-sec-n">{{ primaryTiles.length }}</span>
          </header>
          <div class="wg-grid">
            <button
              v-for="tile in primaryTiles"
              :key="tile.id"
              class="wg-tile"
              :class="['wg-tile--'+tile.accent, tile.badge && 'wg-tile--hasbadge']"
              @click="openTile(tile)"
              type="button">
              <span class="wg-tile-ico" :style="{background: tile.iconBg}" aria-hidden="true">
                <component :is="tile.iconSvg"/>
              </span>
              <span class="wg-tile-body">
                <span class="wg-tile-t">{{ tile.title }}</span>
                <span class="wg-tile-d">{{ tile.description }}</span>
              </span>
              <span v-if="tile.badge" class="wg-tile-badge" :class="'wg-tile-badge--'+tile.badgeTone">{{ tile.badge }}</span>
              <svg class="wg-tile-arrow" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><polyline points="6 3 11 8 6 13"/></svg>
            </button>
          </div>
        </section>

        <!-- SECONDARY SECTION — "Also available" / admin + reports -->
        <section class="wg-sec" v-if="secondaryTiles.length">
          <header class="wg-sec-h">
            <span class="wg-sec-t">Also available</span>
            <span class="wg-sec-n">{{ secondaryTiles.length }}</span>
          </header>
          <div class="wg-grid wg-grid--compact">
            <button
              v-for="tile in secondaryTiles"
              :key="tile.id"
              class="wg-tile wg-tile--compact"
              @click="openTile(tile)"
              type="button">
              <span class="wg-tile-ico wg-tile-ico--sm" :style="{background: tile.iconBg}" aria-hidden="true">
                <component :is="tile.iconSvg"/>
              </span>
              <span class="wg-tile-body">
                <span class="wg-tile-t">{{ tile.title }}</span>
                <span class="wg-tile-d">{{ tile.description }}</span>
              </span>
              <svg class="wg-tile-arrow" viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><polyline points="6 3 11 8 6 13"/></svg>
            </button>
          </div>
        </section>

        <!-- LEARN SECTION — guides and deep links to help -->
        <section class="wg-sec wg-sec--learn">
          <header class="wg-sec-h">
            <span class="wg-sec-t">Guides &amp; orientation</span>
          </header>
          <div class="wg-learn">
            <button
              v-for="g in guides"
              :key="g.id"
              class="wg-guide"
              @click="openGuide(g)"
              type="button">
              <span class="wg-guide-ico" aria-hidden="true">{{ g.emoji }}</span>
              <span class="wg-guide-body">
                <span class="wg-guide-t">{{ g.title }}</span>
                <span class="wg-guide-d">{{ g.description }}</span>
              </span>
              <svg viewBox="0 0 16 16" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="6 3 11 8 6 13"/></svg>
            </button>
          </div>
        </section>

        <!-- CTA -->
        <div class="wg-cta-row">
          <label class="wg-remember">
            <input type="checkbox" v-model="dontShowAgain"/>
            <span class="wg-remember-box"><svg v-if="dontShowAgain" viewBox="0 0 16 16" width="10" height="10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="3 8 6 11 13 4"/></svg></span>
            <span class="wg-remember-t">Don't show this on next login</span>
          </label>
          <button class="wg-cta" type="button" @click="continueToDashboard">
            Continue to dashboard
            <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="6 3 11 8 6 13"/></svg>
          </button>
        </div>

        <div style="height:48px"/>
      </div>
    </IonContent>
  </IonPage>
</template>

<script setup>
/**
 * WelcomeGuide.vue
 * ------------------------------------------------------------------
 * A premium, role-aware, deterministic-AI briefing page shown as the
 * first view after login.
 *
 * Inputs (all read-only):
 *   - AUTH_DATA from sessionStorage
 *   - navigator.onLine  (+ live online/offline events)
 *   - localStorage: cmd_summary_v3 (home summary cache — for badge counts)
 *   - time of day, day of week
 *
 * Output:
 *   - Greeting tailored to time-of-day and first name
 *   - Narrative lead + sublead chosen deterministically by role+scope
 *   - 0–3 "insights" highlighting pending work, offline state, or
 *     critical items waiting
 *   - A curated set of ICON TILES (primary + secondary) — every tile
 *     routes to exactly one view. Tiles are filtered through
 *     rbac.can() so users only see what they can access.
 *   - A "Guides & orientation" row with deep-links to CapabilitiesHelp
 *     and the feature tours.
 *
 * No network calls. Fully offline-safe.
 *
 * Persistence:
 *   localStorage key  "welcome.skip_for_user_<id>" = '1' means the user
 *   has ticked "Don't show again" — App.vue's post-login router
 *   redirect will bypass /welcome for that user on this device. The
 *   menu still exposes "Welcome" so the user can replay it.
 */
import { computed, h, markRaw, onMounted, onBeforeUnmount, ref } from 'vue'
import { useRouter } from 'vue-router'
import { IonPage, IonHeader, IonContent, IonMenuButton } from '@ionic/vue'
import { can, scopeOf, ROLE } from '@/services/rbac'

const router = useRouter()

// ─── Auth snapshot ─────────────────────────────────────────────────────────
function readAuth() { try { return JSON.parse(sessionStorage.getItem('AUTH_DATA') || 'null') || {} } catch { return {} } }
const auth = ref(readAuth())

const fullName = computed(() => String(auth.value?.full_name || auth.value?.name || auth.value?.username || 'Officer').trim())
const firstName = computed(() => fullName.value.split(/\s+/)[0] || 'Officer')

const roleLabel = computed(() => {
  const r = String(auth.value?.role_key || '').toUpperCase()
  switch (r) {
    case 'NATIONAL_ADMIN':      return 'National Admin'
    case 'PHEOC_OFFICER':       return 'PHEOC Officer'
    case 'DISTRICT_SUPERVISOR': return 'District Supervisor'
    case 'POE_ADMIN':           return 'POE Administrator'
    case 'POE_PRIMARY':         return 'Primary Screener'
    case 'POE_SECONDARY':       return 'Secondary Officer'
    case 'POE_DATA_OFFICER':    return 'Data Officer'
    case 'SCREENER':            return 'Screener'
    default: return r ? r.replace(/_/g,' ').toLowerCase().replace(/\b\w/g,c=>c.toUpperCase()) : 'Officer'
  }
})

const scope = computed(() => scopeOf(auth.value))
const scopeLabel = computed(() => scope.value.label)
const scopeCode  = computed(() => scope.value.code)

// ─── World state ───────────────────────────────────────────────────────────
const isOnline = ref(navigator.onLine)
function onOnline()  { isOnline.value = true }
function onOffline() { isOnline.value = false }

const summary = ref(null)
function readSummary() {
  try {
    const raw = localStorage.getItem('cmd_summary_v3')
    if (!raw) return null
    const p = JSON.parse(raw)
    return p?.d || p || null
  } catch { return null }
}

// ─── Greeting / date labels ────────────────────────────────────────────────
const nowHour = new Date().getHours()
const greeting = computed(() => {
  if (nowHour < 5)  return 'Good night'
  if (nowHour < 12) return 'Good morning'
  if (nowHour < 17) return 'Good afternoon'
  if (nowHour < 21) return 'Good evening'
  return 'Good night'
})
const todayLabel = computed(() => {
  try { return new Date().toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'short' }) } catch { return '' }
})

// ─── Deterministic narrative (lead / sub) ──────────────────────────────────
// Audit WG-004: the previous fall-through "Welcome back." silently hid the
// fact that the briefing has nothing to say to a user without a role_key.
// Roles are assigned at user creation; a missing role_key means the auth
// payload is incomplete (server hot-fix in flight, sessionStorage tampered,
// or stale offline cache from before a role schema change). Surface it
// in the lead text below instead of greeting the user as if everything
// were fine.
const lead = computed(() => {
  const r = String(auth.value?.role_key || '')
  const poe = auth.value?.poe_code ? ` · ${auth.value.poe_code}` : ''
  if (r === 'NATIONAL_ADMIN')      return `National oversight is active${poe ? '' : ''}.`
  if (r === 'PHEOC_OFFICER')       return `PHEOC command centre — ${scopeCode.value || 'your province'} at a glance.`
  if (r === 'DISTRICT_SUPERVISOR') return `District supervision — ${scopeCode.value || 'your district'}.`
  if (r === 'POE_ADMIN')           return `POE administration${poe}.`
  if (r === 'POE_PRIMARY')         return `Primary screening station ready${poe}.`
  if (r === 'POE_SECONDARY')       return `Secondary screening desk${poe}.`
  if (r === 'POE_DATA_OFFICER')    return `Data officer — aggregated reporting${poe}.`
  if (r === 'SCREENER')            return `Screening station ready${poe}.`
  return `Profile incomplete — your account has no role assigned.`
})
const subLead = computed(() => {
  const r = String(auth.value?.role_key || '')
  if (r === 'POE_PRIMARY' || r === 'SCREENER') return 'Capture every arriving, departing and transiting traveller. Fever + any symptom → refer.'
  if (r === 'POE_SECONDARY')                   return 'Work the referral queue. Elevated referrals first. Every case creates an IHR-ready record.'
  if (r === 'POE_DATA_OFFICER')                return 'Submit aggregated reports on schedule. Overdue templates block compliance.'
  if (r === 'POE_ADMIN')                       return 'You manage this POE — users, contacts, and the operational picture.'
  if (r === 'DISTRICT_SUPERVISOR')             return 'Watch alerts across your district. Intelligence and history are one tap away.'
  if (r === 'PHEOC_OFFICER')                   return 'Your province is visible end-to-end. Escalate to national when thresholds trigger.'
  if (r === 'NATIONAL_ADMIN')                  return 'You see everything country-wide. Use the admin panel for configuration.'
  return 'Sign out and back in to refresh your session, or contact your administrator if this persists.'
})

// ─── Insights (up to 3) — deterministic derivation ─────────────────────────
const insights = computed(() => {
  const out = []
  const s = summary.value
  const pending = Number(s?.sync_health?.grand_total_unsynced ?? 0)
  const openRef = Number(s?.referral_queue?.open ?? 0)
  const critRef = Number(s?.referral_queue?.open_critical ?? 0)
  const critAlerts = Number(s?.alerts?.open_critical ?? 0)
  const offlineLogin = !!auth.value?._offline_login
  const r = String(auth.value?.role_key || '')

  if (!isOnline.value) {
    out.push({ tone: 'warn', text: `You are offline — capture still works; records will sync when you're back online.` })
  } else if (offlineLogin) {
    out.push({ tone: 'info', text: 'Signed in from the offline cache — session will refresh against the server shortly.' })
  }

  if (pending > 0 && out.length < 3) {
    out.push({ tone: pending > 20 ? 'warn' : 'info', text: `${pending} record${pending === 1 ? '' : 's'} pending sync — push from the Sync hub when ready.` })
  }

  if (critAlerts > 0 && out.length < 3) {
    out.push({ tone: 'danger', text: `${critAlerts} critical alert${critAlerts === 1 ? '' : 's'} open in your scope — open Active Alerts first.` })
  } else if (critRef > 0 && (r === 'POE_SECONDARY' || /ADMIN|SUPERVISOR|PHEOC/.test(r)) && out.length < 3) {
    out.push({ tone: 'danger', text: `${critRef} critical referral${critRef === 1 ? '' : 's'} awaiting secondary screening.` })
  } else if (openRef > 0 && (r === 'POE_SECONDARY' || /ADMIN|SUPERVISOR|PHEOC/.test(r)) && out.length < 3) {
    out.push({ tone: 'info', text: `${openRef} open referral${openRef === 1 ? '' : 's'} in your queue.` })
  }

  if (out.length === 0) {
    // Neutral confidence message
    if (r === 'POE_PRIMARY' || r === 'SCREENER') out.push({ tone: 'ok', text: 'All quiet. Start capturing travellers when they arrive.' })
    else if (r === 'POE_SECONDARY')              out.push({ tone: 'ok', text: 'Referral queue is clear. You can review case records or alerts.' })
    else                                          out.push({ tone: 'ok', text: 'System nominal — everything within your scope is in order.' })
  }
  return out.slice(0, 3)
})

// ─── Icon SVG factories (tiny inline components — zero dependencies) ───────
// markRaw() so Vue doesn't deep-reactify the component object when these
// land inside tilesRef.value (a ref()) — fixes the "Component made reactive"
// warning from `<component :is="tile.iconSvg"/>` rendering.
const svg = (path) => markRaw({ render: () => h('svg', { viewBox: '0 0 24 24', width: 22, height: 22, fill: 'none', stroke: 'currentColor', 'stroke-width': 1.8, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }, [h('g', { innerHTML: path })]) })

const ICONS = {
  capture:  svg('<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M9 12h6M12 9v6"/>'),
  queue:    svg('<rect x="4" y="5" width="16" height="14" rx="2"/><path d="M8 10h8M8 13h5"/>'),
  records:  svg('<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>'),
  bolt:     svg('<path d="M13 2L4 14h6l-1 8 9-12h-6l1-8z"/>'),
  bell:     svg('<path d="M6 8a6 6 0 1 1 12 0v5l2 3H4l2-3z"/><path d="M10 19a2 2 0 0 0 4 0"/>'),
  radar:    svg('<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.5"/>'),
  sync:     svg('<path d="M4 12a8 8 0 0 1 14-5"/><polyline points="18 3 18 7 14 7"/><path d="M20 12a8 8 0 0 1-14 5"/><polyline points="6 21 6 17 10 17"/>'),
  pie:      svg('<path d="M12 3v9l8 4A9 9 0 1 1 12 3z"/>'),
  users:    svg('<circle cx="9" cy="8" r="3.5"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><circle cx="17" cy="7" r="2.5"/><path d="M14 19c0-2.8 2.2-5 5-5"/>'),
  settings: svg('<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5h0a1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8v0a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'),
  book:     svg('<path d="M4 4h12a3 3 0 0 1 3 3v14H8a4 4 0 0 1-4-4z"/><path d="M8 4v14"/>'),
  phone:    svg('<path d="M5 4h3l2 5-2 1a12 12 0 0 0 6 6l1-2 5 2v3a2 2 0 0 1-2 2A18 18 0 0 1 3 6a2 2 0 0 1 2-2z"/>'),
  shield:   svg('<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/><polyline points="9 12 11 14 15 10"/>'),
  building: svg('<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 7h2M8 11h2M8 15h2M14 7h2M14 11h2M14 15h2"/>'),
  profile:  svg('<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>'),
  compass:  svg('<circle cx="12" cy="12" r="9"/><path d="M15 9l-2 6-4 2 2-6z"/>'),
  chart:    svg('<rect x="3" y="13" width="4" height="8"/><rect x="10" y="8" width="4" height="13"/><rect x="17" y="3" width="4" height="18"/>'),
  clipboard:svg('<rect x="6" y="5" width="12" height="16" rx="2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 11h6M9 15h4"/>'),
}

// ─── Tile catalog ──────────────────────────────────────────────────────────
// Each tile declares the rbac perm it gates on; can(perm) determines visibility.
// role_pin — optional: list of roles the tile is *primary* for; promotes it into
// the primary grid for those roles. Others drop into secondary.

function makeTiles() {
  const s = summary.value
  const badges = {
    openReferrals: Number(s?.referral_queue?.open ?? 0),
    critReferrals: Number(s?.referral_queue?.open_critical ?? 0),
    activeCases:   Number(s?.secondary_cases?.active ?? 0),
    openAlerts:    Number(s?.alerts?.open ?? 0),
    critAlerts:    Number(s?.alerts?.open_critical ?? 0),
    pending:       Number(s?.sync_health?.grand_total_unsynced ?? 0),
  }

  const tiles = [
    {
      id: 'primary-capture',
      perm: 'screening.capture',
      route: '/PrimaryScreening',
      title: 'Primary Screening',
      description: 'Capture travellers — direction, temperature, symptoms, referrals.',
      iconSvg: ICONS.capture,
      iconBg: 'linear-gradient(135deg,#0F3460,#1A4E86)',
      accent: 'navy',
      role_pin: ['POE_PRIMARY', 'SCREENER', 'POE_ADMIN'],
    },
    {
      id: 'primary-records',
      perm: 'screening.records',
      route: '/primary-screening/records',
      title: 'Primary Records',
      description: 'Your station register, filtered by day / symptom / sync status.',
      iconSvg: ICONS.records,
      iconBg: 'linear-gradient(135deg,#334155,#475569)',
      accent: 'slate',
      role_pin: ['POE_PRIMARY', 'SCREENER', 'POE_ADMIN'],
    },
    {
      id: 'secondary-queue',
      perm: 'secondary.queue',
      route: '/NotificationsCenter',
      title: 'Secondary Queue',
      description: 'Referral queue from primary stations. Work criticals first.',
      iconSvg: ICONS.queue,
      iconBg: 'linear-gradient(135deg,#B45309,#D97706)',
      accent: 'amber',
      role_pin: ['POE_SECONDARY'],
      badge: badges.critReferrals ? `${badges.critReferrals} CRIT` : (badges.openReferrals ? `${badges.openReferrals}` : ''),
      badgeTone: badges.critReferrals ? 'red' : 'warn',
    },
    {
      id: 'secondary-records',
      perm: 'secondary.records',
      route: '/secondary-screening/records',
      title: 'Case Records',
      description: 'Every full secondary case — disease screens, dispositions.',
      iconSvg: ICONS.clipboard,
      iconBg: 'linear-gradient(135deg,#7C3AED,#8B5CF6)',
      accent: 'purple',
      role_pin: ['POE_SECONDARY'],
      badge: badges.activeCases ? `${badges.activeCases} active` : '',
      badgeTone: 'warn',
    },
    {
      id: 'alerts-active',
      perm: 'alerts.active',
      route: '/alerts',
      title: 'Active Alerts',
      description: 'Current IHR-grade alerts visible in your scope.',
      iconSvg: ICONS.bell,
      iconBg: 'linear-gradient(135deg,#DC2626,#EF4444)',
      accent: 'red',
      role_pin: ['DISTRICT_SUPERVISOR', 'PHEOC_OFFICER', 'POE_ADMIN'],
      badge: badges.critAlerts ? `${badges.critAlerts} CRIT` : (badges.openAlerts ? `${badges.openAlerts}` : ''),
      badgeTone: badges.critAlerts ? 'red' : 'warn',
    },
    // alerts-intel tile removed (2026-05-17) — Alert Intelligence is
    // disconnected from the mobile app per executive directive.
    {
      id: 'alerts-mycases',
      perm: 'alerts.mycases',
      route: '/my-cases',
      title: 'My Cases',
      description: 'Your personal triage queue — ack, close, and escalate in one tap.',
      iconSvg: ICONS.bolt,
      iconBg: 'linear-gradient(135deg,#6D28D9,#7C3AED)',
      accent: 'purple',
      role_pin: ['POE_SECONDARY', 'POE_DATA_OFFICER', 'POE_ADMIN', 'DISTRICT_SUPERVISOR', 'PHEOC_OFFICER', 'NATIONAL_ADMIN'],
      badge: badges.openAlerts ? `${badges.openAlerts}` : '',
      badgeTone: 'warn',
    },
    {
      id: 'alerts-matrix',
      perm: 'alerts.matrix',
      route: '/alerts/matrix',
      title: 'IHR Matrix',
      description: 'WHO IHR Annex-2 reference — no PII, always available.',
      iconSvg: ICONS.shield,
      iconBg: 'linear-gradient(135deg,#0D9488,#14B8A6)',
      accent: 'teal',
    },
    {
      id: 'intel-dashboard',
      perm: 'screening.intelligence',
      route: '/screening-dashboard',
      title: 'Screening Intelligence',
      description: 'Analytics across primary + secondary in your scope.',
      iconSvg: ICONS.chart,
      iconBg: 'linear-gradient(135deg,#0369A1,#0284C7)',
      accent: 'sky',
      role_pin: ['POE_DATA_OFFICER', 'POE_ADMIN', 'DISTRICT_SUPERVISOR', 'PHEOC_OFFICER'],
    },
    {
      id: 'aggregated-hub',
      perm: 'aggregated.reports',
      route: '/aggregated-data',
      title: 'Aggregated Reports',
      description: 'Submit published aggregate templates (daily, weekly, event).',
      iconSvg: ICONS.pie,
      iconBg: 'linear-gradient(135deg,#7C2D12,#B45309)',
      accent: 'amber',
      role_pin: ['POE_DATA_OFFICER'],
    },
    {
      id: 'aggregated-history',
      perm: 'aggregated.history',
      route: '/aggregated-data/history',
      title: 'Report History',
      description: 'Every aggregated submission with status + revisions.',
      iconSvg: ICONS.book,
      iconBg: 'linear-gradient(135deg,#334155,#475569)',
      accent: 'slate',
    },
    {
      id: 'admin-users',
      perm: 'admin.users',
      route: '/Users',
      title: 'User Management',
      description: 'Provision, deactivate, and scope users in your area.',
      iconSvg: ICONS.users,
      iconBg: 'linear-gradient(135deg,#1E40AF,#2563EB)',
      accent: 'indigo',
      role_pin: ['POE_ADMIN', 'DISTRICT_SUPERVISOR', 'PHEOC_OFFICER'],
    },
    {
      id: 'admin-poe-contacts',
      perm: 'admin.poe-contacts',
      route: '/admin/poe-contacts',
      title: 'POE Contacts',
      description: 'Notification roster for your POE.',
      iconSvg: ICONS.phone,
      iconBg: 'linear-gradient(135deg,#0E7490,#0891B2)',
      accent: 'cyan',
      role_pin: ['POE_ADMIN'],
    },
    {
      id: 'admin-poes',
      perm: 'admin.poes',
      route: '/POEs',
      title: 'POE Registry',
      description: 'Manage points of entry across Uganda.',
      iconSvg: ICONS.building,
      iconBg: 'linear-gradient(135deg,#4C1D95,#6D28D9)',
      accent: 'purple',
      role_pin: ['NATIONAL_ADMIN'],
    },
    {
      id: 'admin-agg-templates',
      perm: 'admin.aggregated.templates',
      route: '/admin/aggregated-templates',
      title: 'Aggregated Templates',
      description: 'Build and publish the aggregate forms field teams fill in.',
      iconSvg: ICONS.clipboard,
      iconBg: 'linear-gradient(135deg,#1E3A8A,#1E40AF)',
      accent: 'indigo',
      role_pin: ['NATIONAL_ADMIN'],
    },
    {
      id: 'admin-agg-wizard',
      perm: 'admin.aggregated.wizard',
      route: '/admin/aggregated-wizard',
      title: 'Template Wizard',
      description: 'Design a new aggregated template step-by-step.',
      iconSvg: ICONS.bolt,
      iconBg: 'linear-gradient(135deg,#B91C1C,#DC2626)',
      accent: 'red',
    },
    {
      id: 'admin-diseases',
      perm: 'admin.diseases',
      route: '/DiseaseInteligence',
      title: 'Disease Intelligence',
      description: 'Browse the full WHO/IHR disease catalogue powering triage.',
      iconSvg: ICONS.shield,
      iconBg: 'linear-gradient(135deg,#065F46,#059669)',
      accent: 'emerald',
    },
    {
      id: 'directory',
      perm: 'directory',
      route: '/directory',
      title: 'Staff Directory',
      description: 'Reach anyone in your scope — one tap to call.',
      iconSvg: ICONS.phone,
      iconBg: 'linear-gradient(135deg,#166534,#16A34A)',
      accent: 'emerald',
    },
    {
      // Side-menu and home-screen call this "Sync Centre" — keep that
      // naming consistent here so users see one label everywhere.
      id: 'sync',
      perm: 'sync',
      route: '/sync',
      title: 'Sync Centre',
      description: 'Queue · history · failed retries — push on tap.',
      iconSvg: ICONS.sync,
      iconBg: 'linear-gradient(135deg,#0F172A,#1E293B)',
      accent: 'navy',
      badge: badges.pending ? `${badges.pending}` : '',
      badgeTone: 'warn',
    },
    {
      id: 'profile',
      perm: 'profile',
      route: '/profile',
      title: 'My Profile',
      description: 'Your account, POE assignment and permissions.',
      iconSvg: ICONS.profile,
      iconBg: 'linear-gradient(135deg,#0F3460,#13315C)',
      accent: 'navy',
    },
    {
      id: 'settings',
      perm: 'settings',
      route: '/settings',
      title: 'Settings',
      description: 'Capabilities, lock, haptics, and sync preferences.',
      iconSvg: ICONS.settings,
      iconBg: 'linear-gradient(135deg,#111827,#374151)',
      accent: 'slate',
    },
    {
      id: 'capabilities',
      perm: 'capabilities-help',
      route: '/capabilities-help',
      title: 'Capabilities &amp; Help',
      description: 'Everything this device can do, with live probes + tours.',
      iconSvg: ICONS.compass,
      iconBg: 'linear-gradient(135deg,#065F46,#0D9488)',
      accent: 'teal',
    },
  ]

  const role = String(auth.value?.role_key || '')

  // SCREENER lock-down: they may only reach /alerts and /alerts/matrix.
  // All other tiles are hidden to match the sidebar and router restrictions.
  const SCREENER_TILE_PERMS = new Set(['alerts.active', 'alerts.matrix'])
  const isScreener = role === 'SCREENER'

  // filter by permission, then split primary/secondary by role-pin.
  const visible = tiles.filter(t => can(t.perm, auth.value) && (!isScreener || SCREENER_TILE_PERMS.has(t.perm)))
  const primary   = visible.filter(t => Array.isArray(t.role_pin) && t.role_pin.includes(role))
  const secondary = visible.filter(t => !primary.includes(t))
  // NATIONAL_ADMIN sees everything — put the big-ticket tiles first so the grid is useful.
  if (role === 'NATIONAL_ADMIN' && primary.length === 0) {
    const pinForNat = ['admin-poes', 'admin-agg-templates', 'admin-users', 'alerts-active', 'alerts-mycases', 'intel-dashboard', 'sync']
    for (const id of pinForNat) {
      const t = visible.find(x => x.id === id)
      if (t) { primary.push(t); secondary.splice(secondary.indexOf(t), 1) }
    }
  }
  return { primary, secondary }
}

const tilesRef = ref({ primary: [], secondary: [] })
const primaryTiles   = computed(() => tilesRef.value.primary)
const secondaryTiles = computed(() => tilesRef.value.secondary)

function refreshTiles() { tilesRef.value = makeTiles() }

// ─── Guides ────────────────────────────────────────────────────────────────
// Each guide tile lands on the ACTUAL feature page (not a documentation
// anchor) so the user can immediately try the thing. Earlier versions
// pointed at /capabilities-help#tour-anchor-primary etc., but those
// anchors only exist for `tour-anchor-applock` in CapabilitiesHelp.vue —
// every other anchor was a silent dead link that scrolled to the top of
// /capabilities-help and showed unrelated content. The "Capabilities &
// Help" tile remains the route into the explorer (which itself lives
// inside Settings now, but /capabilities-help is the canonical URL).
const guides = computed(() => {
  const role = String(auth.value?.role_key || '')
  const isScreener = role === 'SCREENER'
  const out = []

  // Screeners only get the alerts guide
  if (isScreener) {
    out.push({
      id: 'g-alerts', emoji: '🚨',
      title: 'Monitor active alerts',
      description: 'View IHR-grade alerts in your scope — see status, who is responding, and the full timeline.',
      route: '/alerts',
    })
    return out
  }

  out.push({
    id: 'capabilities', emoji: '🧭',
    title: 'What this device can do',
    description: 'Open the Capabilities & Help explorer — probe every plugin, replay tours, see status.',
    route: '/capabilities-help',
  })
  if (can('screening.capture', auth.value)) out.push({
    id: 'g-primary', emoji: '🩺',
    title: 'Run your first primary screen',
    description: 'Direction → temp → symptoms → refer. The capture loop is under 20 seconds per traveller.',
    route: '/PrimaryScreening',
  })
  if (can('secondary.queue', auth.value)) out.push({
    id: 'g-secondary', emoji: '📋',
    title: 'Pick up a secondary referral',
    description: 'Open the queue, work criticals first, complete the WHO/IHR investigation.',
    route: '/NotificationsCenter',
  })
  if (can('aggregated.reports', auth.value)) out.push({
    id: 'g-agg', emoji: '📑',
    title: 'File an aggregated report',
    description: 'Browse published templates, fill the fields, submit. Offline-safe.',
    route: '/aggregated-data',
  })
  if (can('alerts.active', auth.value)) out.push({
    id: 'g-alerts', emoji: '🚨',
    title: 'Read the active alerts',
    description: 'Open IHR-grade alerts in your scope — see why each fired and the disposition timeline.',
    route: '/alerts',
  })
  if (can('sync', auth.value)) out.push({
    id: 'g-sync', emoji: '🔄',
    title: 'Push your offline queue',
    description: 'Capture never blocks. When you reconnect, push the queue from the Sync Centre.',
    route: '/sync',
  })
  return out.slice(0, 6)
})

// ─── Navigation ────────────────────────────────────────────────────────────
// Both tiles and guides now navigate to a real route (no more anchor-only
// destinations). Hash-fragment scrolling support is kept for forward
// compatibility — if a future guide adds `#anchor`, this still does the
// right thing instead of crashing.
function openTile(tile) { if (tile?.route) router.push(tile.route) }
function openGuide(g) {
  if (!g?.route) return
  const [path, hash] = String(g.route).split('#')
  if (hash) {
    router.push(path).then(() => {
      setTimeout(() => {
        try { document.getElementById(hash)?.scrollIntoView({ behavior: 'smooth', block: 'start' }) } catch {}
      }, 350)
    })
  } else {
    router.push(path)
  }
}
function skipToHome() { continueToDashboard() }

// ─── "Don't show again" persistence ────────────────────────────────────────
const SKIP_KEY = () => `welcome.skip_for_user_${auth.value?.id || 'anon'}`
const dontShowAgain = ref(false)
try { dontShowAgain.value = localStorage.getItem(SKIP_KEY()) === '1' } catch {}
function continueToDashboard() {
  try { localStorage.setItem(SKIP_KEY(), dontShowAgain.value ? '1' : '0') } catch {}
  router.replace('/home')
}

// ─── Lifecycle ─────────────────────────────────────────────────────────────
onMounted(() => {
  auth.value = readAuth()
  summary.value = readSummary()
  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)
  refreshTiles()
})
onBeforeUnmount(() => {
  window.removeEventListener('online', onOnline)
  window.removeEventListener('offline', onOffline)
})
</script>

<style scoped>
*{box-sizing:border-box}

/* ═══ HEADER — dark command zone ═══════════════════════════════════ */
.wg-hdr{--background:transparent;border:none}
.wg-hdr-bg{
  background:
    radial-gradient(1000px 300px at 100% -20%, rgba(20,184,166,.18), transparent 60%),
    radial-gradient(800px 280px at -10% 0%,  rgba(99,102,241,.22), transparent 60%),
    linear-gradient(160deg,#06111E 0%, #0D253F 55%, #0F3460 100%);
  padding:0 0 16px;
  padding-top: env(safe-area-inset-top,0);
}
.wg-hdr-top{display:flex;align-items:center;gap:6px;padding:10px 12px 0}
.wg-menu{--color:rgba(255,255,255,.75);flex-shrink:0}
.wg-hdr-id{flex:1;min-width:0}
.wg-eyebrow{display:block;font-size:9px;font-weight:800;color:rgba(255,255,255,.5);letter-spacing:1.2px;text-transform:uppercase}
.wg-h1{display:block;font-size:22px;font-weight:900;color:#EDF2FA;line-height:1.1;margin-top:2px;letter-spacing:-.3px}
.wg-skip{display:inline-flex;align-items:center;gap:4px;padding:6px 10px;border-radius:99px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.85);font-size:10px;font-weight:700;cursor:pointer;flex-shrink:0}
.wg-skip:active{transform:scale(.96)}

.wg-hero{padding:14px 14px 4px}
.wg-chip-row{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:10px}
.wg-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:99px;font-size:9px;font-weight:800;letter-spacing:.3px;text-transform:uppercase}
.wg-chip--role{background:rgba(20,184,166,.18);color:#5EEAD4;border:1px solid rgba(20,184,166,.35)}
.wg-chip--scope{background:rgba(255,255,255,.06);color:rgba(255,255,255,.7);border:1px solid rgba(255,255,255,.12)}
.wg-chip--on{background:rgba(16,185,129,.15);color:#34D399;border:1px solid rgba(16,185,129,.3)}
.wg-chip--off{background:rgba(239,68,68,.15);color:#FCA5A5;border:1px solid rgba(239,68,68,.35)}
.wg-chip-dot{width:5px;height:5px;border-radius:50%;background:currentColor}
.wg-lead{font-size:16px;font-weight:800;color:#EDF2FA;line-height:1.25;margin:0 0 4px}
.wg-sub{font-size:12px;color:rgba(255,255,255,.65);line-height:1.4;margin:0}

.wg-insights{display:flex;flex-direction:column;gap:4px;padding:2px 14px 0}
.wg-ins{display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:8px;font-size:11px;font-weight:600}
.wg-ins-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.wg-ins--ok{background:rgba(16,185,129,.1);color:#6EE7B7}
.wg-ins--ok .wg-ins-dot{background:#34D399}
.wg-ins--info{background:rgba(96,165,250,.12);color:#BFDBFE}
.wg-ins--info .wg-ins-dot{background:#60A5FA}
.wg-ins--warn{background:rgba(245,158,11,.14);color:#FDE68A}
.wg-ins--warn .wg-ins-dot{background:#F59E0B}
.wg-ins--danger{background:rgba(239,68,68,.16);color:#FCA5A5}
.wg-ins--danger .wg-ins-dot{background:#EF4444;animation:wg-pulse 1.4s ease infinite}
@keyframes wg-pulse{0%,100%{opacity:1}50%{opacity:.4}}
.wg-ins-t{flex:1;line-height:1.35}

/* ═══ CONTENT ═══════════════════════════════════════════════════════ */
.wg-content{--background:#F1F5F9}
.wg-body{padding:12px 10px 0;max-width:520px;margin:0 auto}

.wg-sec{margin-bottom:12px}
.wg-sec-h{display:flex;align-items:baseline;justify-content:space-between;padding:0 4px 8px}
.wg-sec-t{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#334155}
.wg-sec-n{font-size:10px;font-weight:700;color:#94A3B8}

.wg-grid{display:grid;grid-template-columns:1fr;gap:8px}
.wg-grid--compact{grid-template-columns:1fr 1fr;gap:6px}

.wg-tile{display:flex;align-items:center;gap:10px;padding:12px;background:#fff;border-radius:14px;border:1px solid #E2E8F0;cursor:pointer;text-align:left;box-shadow:0 1px 2px rgba(15,52,96,.04);transition:transform .12s ease,box-shadow .12s ease;position:relative;width:100%}
.wg-tile:active{transform:scale(.98)}
.wg-tile:hover{box-shadow:0 4px 14px rgba(15,52,96,.08);border-color:#CBD5E1}
.wg-tile--navy{border-color:#DBE3EF}
.wg-tile--red{border-color:#FEE2E2}
.wg-tile--amber{border-color:#FEF3C7}
.wg-tile--purple{border-color:#EDE9FE}
.wg-tile--cyan{border-color:#CFFAFE}
.wg-tile--teal{border-color:#CCFBF1}
.wg-tile--sky{border-color:#E0F2FE}
.wg-tile--slate{border-color:#E2E8F0}
.wg-tile--indigo{border-color:#E0E7FF}
.wg-tile--emerald{border-color:#D1FAE5}
.wg-tile--compact{padding:10px;border-radius:12px}

.wg-tile-ico{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;color:#fff;flex-shrink:0}
.wg-tile-ico--sm{width:32px;height:32px;border-radius:9px}
.wg-tile-ico svg{width:22px;height:22px}
.wg-tile-ico--sm svg{width:18px;height:18px}

.wg-tile-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.wg-tile-t{font-size:13px;font-weight:800;color:#0F172A;letter-spacing:-.1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wg-tile-d{font-size:10.5px;color:#64748B;line-height:1.3;font-weight:500;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.wg-tile--compact .wg-tile-t{font-size:12px}
.wg-tile--compact .wg-tile-d{font-size:10px;-webkit-line-clamp:2}

.wg-tile-badge{position:absolute;top:8px;right:28px;padding:2px 7px;border-radius:99px;font-size:9px;font-weight:900;letter-spacing:.2px}
.wg-tile-badge--warn{background:#FEF3C7;color:#92400E}
.wg-tile-badge--red{background:#FEE2E2;color:#991B1B;animation:wg-pulse 1.8s ease infinite}

.wg-tile-arrow{color:#94A3B8;flex-shrink:0}

/* GUIDES */
.wg-sec--learn .wg-sec-t{color:#0F3460}
.wg-learn{display:flex;flex-direction:column;gap:4px}
.wg-guide{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#fff;border:1px solid #E2E8F0;border-radius:10px;cursor:pointer;text-align:left;width:100%;transition:background .12s ease}
.wg-guide:hover{background:#F8FAFC}
.wg-guide-ico{font-size:18px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:linear-gradient(135deg,#F1F5F9,#E2E8F0);flex-shrink:0}
.wg-guide-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:1px}
.wg-guide-t{font-size:12px;font-weight:700;color:#0F172A}
.wg-guide-d{font-size:10px;color:#64748B;font-weight:500;line-height:1.3;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wg-guide svg{color:#94A3B8;flex-shrink:0}

/* CTA */
.wg-cta-row{margin-top:10px;padding:14px 4px 0;display:flex;flex-direction:column;align-items:stretch;gap:10px;border-top:1px dashed #CBD5E1}
.wg-remember{display:flex;align-items:center;gap:8px;font-size:11px;color:#475569;cursor:pointer;user-select:none}
.wg-remember input{display:none}
.wg-remember-box{width:16px;height:16px;border-radius:4px;border:1.5px solid #94A3B8;display:flex;align-items:center;justify-content:center;color:#fff;background:#fff;transition:all .15s}
.wg-remember input:checked + .wg-remember-box{background:#0F3460;border-color:#0F3460}
.wg-cta{display:flex;align-items:center;justify-content:center;gap:8px;padding:14px;border:none;border-radius:12px;background:linear-gradient(135deg,#0F3460,#13315C);color:#fff;font-size:14px;font-weight:800;cursor:pointer;box-shadow:0 4px 18px rgba(15,52,96,.28);letter-spacing:.2px}
.wg-cta:active{transform:scale(.98)}

@media (min-width: 600px){
  .wg-grid{grid-template-columns:1fr 1fr}
  .wg-grid--compact{grid-template-columns:1fr 1fr 1fr}
}
</style>
