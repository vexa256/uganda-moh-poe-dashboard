<template>
  <IonApp>
    <!-- 2026-05-06 v5: Google Translate is hardcoded in index.html OUTSIDE
         the Vue mount root. Vue's reactivity therefore cannot re-render
         the widget's <font>-wrapped text nodes (which is what kept
         destroying the v4 attempt). See index.html for the full
         button + panel + script. -->

    <!-- iOS Add-to-Home-Screen hint — Safari can't fire beforeinstallprompt
         so we surface a manual instruction. Auto-shown on iOS Safari when
         the app is opened in a tab and not already installed. Dismissable
         with a 14-day cooldown. -->
    <div v-if="iosInstallHintVisible" class="poe-ios-install" role="status">
      <div class="poe-ios-install-card">
        <div class="poe-ios-install-icon">
          <svg viewBox="0 0 28 28" width="28" height="28" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M14 19V5"/><polyline points="9 9 14 4 19 9"/><rect x="5" y="19" width="18" height="5" rx="2"/>
          </svg>
        </div>
        <div class="poe-ios-install-body">
          <div class="poe-ios-install-title">Install POE Screening on iPhone</div>
          <div class="poe-ios-install-desc">
            Tap <strong>Share</strong>
            <svg viewBox="0 0 14 14" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" style="vertical-align: middle"><path d="M7 9V2"/><polyline points="4 5 7 2 10 5"/><path d="M3 9v3h8V9"/></svg>
            then <strong>Add to Home Screen</strong>. Works offline, full-screen, with your icon.
          </div>
        </div>
        <button class="poe-ios-install-x" type="button" aria-label="Dismiss install hint"
                @click="dismissIosHint">×</button>
      </div>
    </div>

    <!-- Non-iOS browser hint (Android, desktop Chrome, dev-tools responsive
         mode). Replaces the old PWA install button: PWA install is iOS-only;
         everyone else is directed to the signed APK. Hidden inside Capacitor
         (already running native) and on iOS (which gets the install hint
         above). Dismissable with a 14-day cooldown. -->
    <div v-if="nonIosDownloadHintVisible" class="poe-ios-install" role="status">
      <div class="poe-ios-install-card">
        <div class="poe-ios-install-icon">
          <svg viewBox="0 0 28 28" width="28" height="28" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M14 5v14"/><polyline points="9 14 14 19 19 14"/><rect x="5" y="22" width="18" height="3" rx="1.5"/>
          </svg>
        </div>
        <div class="poe-ios-install-body">
          <div class="poe-ios-install-title">Download the Android app</div>
          <div class="poe-ios-install-desc">
            The browser version is preview-only. Install the signed POE Screening APK for full offline + sync support.
            <a href="/apks/latest.apk" download style="display:inline-block;margin-top:6px;font-weight:700;text-decoration:underline;">Download APK →</a>
          </div>
        </div>
        <button class="poe-ios-install-x" type="button" aria-label="Dismiss download hint"
                @click="dismissNonIosHint">×</button>
      </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════
         LOGIN GUARD — plain fixed <div>, NOT IonModal.
         IonModal is a Web Component. Its shadow DOM ignores CSS variable
         overrides for width/height, always rendering a small floating card.
         A plain div with position:fixed + inset:0 + z-index:99999 is the
         ONLY reliable full-screen overlay in Ionic Vue on web + Capacitor.
         v-if removes it from DOM on login success (isAuthenticated=true).
         pointer-events:all blocks all interaction with content behind it.
    ════════════════════════════════════════════════════════════════ -->
    <!-- ═══ PREMIUM LOGIN — full-screen, native-grade light theme ═══════════
         No IonModal, no card artifacting. Pure fixed div, inset:0, z:99999.
         Design: Material You health app, WHO authority, offline-always.
    ══════════════════════════════════════════════════════════════════════ -->
    <div
      v-if="!isAuthenticated"
      class="lm-overlay"
      role="dialog"
      aria-modal="true"
      aria-label="Sign in to continue"
    >
      <!-- ── Decorative top band ── -->
      <div class="lm-top-band" aria-hidden="true">
        <div class="lm-band-wave"></div>
      </div>

      <!-- ── Scroll container ── -->
      <div class="lm-scroll">

        <!-- Safe-area top spacer -->
        <div class="lm-st" aria-hidden="true"></div>

        <!-- ── HERO ── -->
        <div class="lm-hero" aria-label="Uganda POE Screening">

          <!-- Uganda flag logo -->
          <div class="lm-logo-wrap" aria-hidden="true">
            <div class="lm-logo-ring lm-logo-ring--1"></div>
            <div class="lm-logo-ring lm-logo-ring--2"></div>
            <div class="lm-logo-icon">
              <img src="/uganda-flag.svg" alt="" width="80" height="80" />
            </div>
          </div>

          <h1 class="lm-appname">Uganda POE <span class="lm-accent">Screening</span></h1>

          <div class="lm-chips" aria-label="System status">
            <span class="lm-chip lm-chip--green">
              <span class="lm-chip-dot"></span>Offline First
            </span>
            <span class="lm-chip lm-chip--slate">v{{ APP_VERSION }}</span>
          </div>
        </div>

        <!-- ── OFFLINE BANNER — returning user, no network ── -->
        <div v-if="isOffline" class="lm-offline" role="status" aria-live="polite">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"
            stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M1 6s4-4 11-4 11 4 11 4"/><path d="M5 10s3-3 7-3 7 3 7 3"/>
            <line x1="1" y1="1" x2="23" y2="23"/><circle cx="12" cy="20" r="2"/>
          </svg>
          <span>Offline — using saved credentials</span>
        </div>

        <!-- ── FORM SURFACE ── -->
        <section class="lm-surface" role="main">

          <div class="lm-surface-hdr">
            <h2 class="lm-form-title">Sign in</h2>
          </div>

          <!-- Error -->
          <div v-if="loginError" class="lm-error" role="alert" aria-live="assertive">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
              <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
              <circle cx="12" cy="16.5" r=".8" fill="currentColor"/>
            </svg>
            <div>
              <div class="lm-error-msg">{{ loginError }}</div>
              <div v-if="loginErrorDetail" class="lm-error-detail">{{ loginErrorDetail }}</div>
            </div>
          </div>

          <form autocomplete="on" @submit.prevent="submitLogin" novalidate>

            <!-- Username field -->
            <div class="lm-field-wrap">
              <label class="lm-label" :class="{ active: focusLogin || loginForm.login }" for="f-login">
                Username or email
              </label>
              <div class="lm-input-row" :class="{ focus: focusLogin, filled: !!loginForm.login, err: !!loginError }">
                <svg class="lm-input-ic" width="18" height="18" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                  <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
                  <circle cx="12" cy="7" r="4"/>
                </svg>
                <IonInput
                  id="f-login"
                  v-model="loginForm.login"
                  type="text"
                  name="username"
                  autocomplete="username"
                  placeholder="Enter username or email"
                  :disabled="loginLoading"
                  class="lm-ion"
                  aria-required="true"
                  @ionFocus="focusLogin = true"
                  @ionBlur="focusLogin = false"
                />
              </div>
            </div>

            <!-- Password field -->
            <div class="lm-field-wrap" style="margin-top:16px">
              <label class="lm-label" :class="{ active: focusPw || loginForm.password }" for="f-pw">
                Password
              </label>
              <div class="lm-input-row" :class="{ focus: focusPw, filled: !!loginForm.password, err: !!loginError }">
                <svg class="lm-input-ic" width="18" height="18" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                  <rect x="3" y="11" width="18" height="11" rx="2"/>
                  <path d="M7 11V7a5 5 0 0110 0v4"/>
                </svg>
                <IonInput
                  id="f-pw"
                  v-model="loginForm.password"
                  :type="showPw ? 'text' : 'password'"
                  name="password"
                  autocomplete="current-password"
                  placeholder="Enter password"
                  :disabled="loginLoading"
                  class="lm-ion"
                  aria-required="true"
                  @ionFocus="focusPw = true"
                  @ionBlur="focusPw = false"
                />
                <button type="button" class="lm-eye-btn"
                  :aria-label="showPw ? 'Hide password' : 'Show password'"
                  @click="showPw = !showPw">
                  <svg v-if="!showPw" width="18" height="18" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                    <circle cx="12" cy="12" r="3"/>
                  </svg>
                  <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none"
                    stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                    <path d="M17.94 17.94A10.07 10.07 0 0112 20c-7 0-11-8-11-8a18.45 18.45 0 015.06-5.94M9.9 4.24A9.12 9.12 0 0112 4c7 0 11 8 11 8a18.5 18.5 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24"/>
                    <line x1="1" y1="1" x2="23" y2="23"/>
                  </svg>
                </button>
              </div>
            </div>

            <!-- Sign-in button -->
            <button
              type="submit"
              class="lm-btn"
              :class="{ 'lm-btn--loading': loginLoading }"
              :disabled="loginLoading || !loginForm.login.trim() || !loginForm.password"
              aria-label="Sign in"
            >
              <span v-if="!loginLoading" class="lm-btn-inner">
                Sign in
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                  stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                  <line x1="5" y1="12" x2="19" y2="12"/>
                  <polyline points="12 5 19 12 12 19"/>
                </svg>
              </span>
              <span v-else class="lm-btn-dots" role="status" aria-label="Signing in…">
                <span></span><span></span><span></span>
              </span>
            </button>

          </form>

        </section>

        <footer class="lm-footer">Uganda POE Screening · v{{ APP_VERSION }}</footer>

        <!-- Safe-area bottom spacer -->
        <div class="lm-sb" aria-hidden="true"></div>

      </div><!-- /lm-scroll -->
    </div><!-- /lm-overlay -->

    <!-- ═══════════════════════════════════════════════════════════════════
         ORIGINAL MENU — untouched
    ═══════════════════════════════════════════════════════════════════ -->
    <IonMenu menu-id="app-menu" content-id="main-content" type="overlay" side="start" :swipeGesture="true" :animated="false">
      <IonContent class="menu-content" :scrollY="true">

        <!-- IDENTITY PANEL -->
        <div class="ip">
          <div class="ip__bar" aria-hidden="true" />
          <div class="ip__body">
            <div class="ip__av-wrap">
              <div class="ip__av" role="img" :aria-label="`Avatar for ${displayName}`">
                <span class="ip__initials" aria-hidden="true">{{ userInitials }}</span>
              </div>
              <div class="ip__dot" :class="`ip__dot--${syncState}`" :aria-label="syncLabel" />
            </div>
            <div class="ip__info">
              <p class="ip__name">{{ displayName }}</p>
              <span class="ip__role" :class="`ip__role--${roleCss}`">{{ ROLE_LABELS[authData?.role_key as RoleKey] ?? authData?.role_key ?? '' }}</span>
              <p class="ip__scope">{{ scopeLabel }}</p>
            </div>
          </div>
          <button class="ip__sync" :class="`ip__sync--${syncState}`" type="button"
            :aria-label="`Sync: ${syncLabel}`" @click="navTo('/sync/queue')">
            <IonIcon :icon="syncIcon" class="ip__sync-icon" aria-hidden="true" />
            <span class="ip__sync-label">{{ syncLabel }}</span>
            <span v-if="syncPendingTotal > 0" class="ip__sync-ct">{{ syncPendingTotal }} pending</span>
          </button>
          <!-- App-wide language switcher. Reactive via the shared useI18n()
               composable; flipping here updates every screen instantly. -->
          <div class="ip__lang">
            <LangSwitcher />
          </div>
        </div>

        <!-- GENERATED NAVIGATION — single loop over menuGroups computed -->
        <nav class="mn" aria-label="Application navigation">
          <template v-for="group in menuGroups" :key="group.id">
            <div class="mn__group">
              <p v-if="group.title" class="mn__gt" aria-hidden="true">{{ group.title }}</p>

              <template v-for="item in group.items" :key="item.id">
                <button
                  v-if="item.show !== false"
                  class="mn__item"
                  :class="{
                    'mn__item--active':  item.route && currentPath === item.route,
                    'mn__item--danger':  item.danger,
                    [`mn__item--${item.accentColor}`]: !!item.accentColor,
                  }"
                  type="button"
                  :id="item.anchorId || undefined"
                  :aria-label="item.ariaLabel || item.label"
                  @click="item.action ? item.action() : navTo(item.route!)"
                >
                  <IonIcon :icon="item.icon" class="mn__icon" :class="item.iconClass" aria-hidden="true" />
                  <div class="mn__text">
                    <span class="mn__label">{{ item.label }}</span>
                    <span class="mn__sub">{{ item.sub }}</span>
                  </div>
                  <!-- live badge count -->
                  <span v-if="item.badge && item.badge() > 0"
                    class="mn__badge" :class="`mn__badge--${item.badgeVariant || 'primary'}`"
                    aria-hidden="true">
                    {{ item.badge() }}
                  </span>
                  <!-- static tag (e.g. 10s, NATL) -->
                  <span v-if="item.tag" class="mn__tag" :class="`mn__tag--${item.tagVariant || 'default'}`"
                    aria-hidden="true">{{ item.tag }}</span>
                </button>
              </template>
            </div>
          </template>
        </nav>

        <!-- FOOTER -->
        <footer class="mf" aria-label="Build info">
          <div class="mf__div" aria-hidden="true" />
          <div v-for="row in footerRows" :key="row.key" class="mf__row">
            <span class="mf__k">{{ row.key }}</span>
            <span class="mf__v" :class="{ 'mf__mono': row.mono }">{{ row.val }}</span>
          </div>
        </footer>

      </IonContent>
    </IonMenu>

    <IonRouterOutlet id="main-content" :animated="true" />

    <!-- Global overlays — outside IonRouterOutlet so they persist across routes -->
    <FeatureSpotlight />
    <AppLockScreen
      :open="appLockOpen"
      :force-setup="appLockForceSetup"
      @unlocked="onAppUnlocked"
      @forgot="onAppLockForgot"
      @cancel-setup="onAppLockCancelSetup"
    />
  </IonApp>
</template>

<script setup lang="ts">
/**
 * App.vue — ECSA POE Offline-First Screening System
 *
 * APPROACH: Data-driven menu. `menuGroups` is a computed array of
 * group/item config objects. The template is a single v-for loop —
 * zero duplicated markup. Adding a new route = one object in the array.
 *
 * AUTH: Login guard modal sits above everything. isAuthenticated drives
 * its visibility. AUTH_DATA in sessionStorage is the single source of truth.
 * On success, body.data (flat users row) is stored + _permissions derived
 * from role_key so RBAC still works without a separate permissions endpoint.
 *
 * LIGHT THEME ONLY — dark.system.css permanently removed from main.ts
 */

import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import {
  IonApp, IonMenu, IonContent, IonRouterOutlet, IonIcon,
  IonPage, IonInput,
  menuController, alertController, toastController,
} from '@ionic/vue'
import {
  gridOutline,
  addCircleOutline,
  listOutline,
  gitMergeOutline,
  archiveOutline,
  medkitOutline,
  documentTextOutline,
  clipboardOutline,
  warningOutline,
  shieldCheckmarkOutline,
  cloudUploadOutline,
  barChartOutline,
  syncOutline,
  cloudDoneOutline,
  refreshOutline,
  peopleOutline,
  personAddOutline,
  mapOutline,
  analyticsOutline,
  statsChartOutline,
  pulseOutline,
  layersOutline,
  personCircleOutline,
  cogOutline,
  libraryOutline,
  settingsOutline,
  logOutOutline,
  bookOutline,
  callOutline,
  languageOutline,
  helpCircleOutline,
  sparklesOutline,
  // ── login modal icons ──
  alertCircleOutline,
  lockClosedOutline,
  eyeOutline,
  eyeOffOutline,
  personOutline,
  flashOutline,
} from 'ionicons/icons'

// ─── Capability wiring (Wave 1 features) ──────────────────────────────────────
import { CAPABILITY_KEYS, isEnabled as isCapEnabled } from '@/services/capabilities'
import * as netPlugin from '@/services/plugins/network'
import { maybeRunWelcomeTour } from '@/services/tour'
import FeatureSpotlight from '@/components/FeatureSpotlight.vue'
import AppLockScreen from '@/components/AppLockScreen.vue'
import LangSwitcher from '@/components/LangSwitcher.vue'
import { hasPin, clearPin } from '@/services/plugins/biometric'

// Bridge non-reactive localStorage reads in isCapEnabled() into Vue reactivity.
// Every capability-changed event bumps this; computeds that ALSO read capBump.value
// will re-evaluate when toggles flip — so the sidebar redraws live.
const capBump = ref(0)
try { window.addEventListener('capability-changed', () => { capBump.value++ }) } catch {}
function capEnabled(key: string): boolean { void capBump.value; return isCapEnabled(key) }

// ─── RBAC (single source of truth — src/services/rbac.js) ─────────────────
// `canPerm` is a reactive wrapper: whenever authData changes (login/logout/
// session-restore), every computed that reads canPerm() re-evaluates —
// menu groups rebuild live, guards re-run.
import { can as rbacCan, isAdmin as rbacIsAdmin } from '@/services/rbac'
function canPerm(perm: string): boolean {
  // authData is a ref; reading authData.value makes this Vue-reactive.
  return rbacCan(perm, authData.value)
}

// ─── Live IDB-backed counters wired to menu badges ─────────────────────────
// Mandate 2026-05-06: every menu item that points at "things needing
// attention" gets a live count badge. Reads IDB indices (cheap), scoped
// to the user's role tier, refreshed every 12 s and on focus + on every
// sync engine event so badges update immediately after a successful sync.
import { dbCountIndex, dbGetByIndex, STORE } from '@/services/poeDB'
const liveOpenAlerts       = ref(0)   // OPEN alerts in scope (My Cases / Active Alerts)
const liveOpenReferrals    = ref(0)   // OPEN+IN_PROGRESS notifications (Notifications Center)
const liveActiveCases      = ref(0)   // OPEN+IN_PROGRESS secondary cases (Secondary Records)
const liveDamagedRecords   = ref(0)   // FAILED records that need user review

function authScope() {
  const a = JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {}
  return { a, role: a?.role_key || '' }
}
function inUserScope(x, a, role) {
  if (!x || x.deleted_at) return false
  if (['POE_PRIMARY','POE_SECONDARY','POE_DATA_OFFICER','POE_ADMIN','SCREENER'].includes(role)) {
    return !a.poe_code || x.poe_code === a.poe_code
  }
  if (role === 'DISTRICT_SUPERVISOR') return !a.district_code || x.district_code === a.district_code
  if (role === 'PHEOC_OFFICER')       return !a.pheoc_code   || x.pheoc_code === a.pheoc_code
  return !a.country_code || x.country_code === a.country_code
}

async function refreshLiveAlertCount() {
  try {
    const all = await dbGetByIndex(STORE.ALERTS, 'status', 'OPEN').catch(() => [])
    const { a, role } = authScope()
    liveOpenAlerts.value = all.filter(x => inUserScope(x, a, role)).length
  } catch { liveOpenAlerts.value = 0 }
}

async function refreshLiveReferralCount() {
  try {
    const open       = await dbGetByIndex(STORE.NOTIFICATIONS, 'status', 'OPEN').catch(() => [])
    const inProgress = await dbGetByIndex(STORE.NOTIFICATIONS, 'status', 'IN_PROGRESS').catch(() => [])
    const { a, role } = authScope()
    let n = 0
    for (const r of [...open, ...inProgress]) {
      if (r.notification_type === 'SECONDARY_REFERRAL' && inUserScope(r, a, role)) n++
    }
    liveOpenReferrals.value = n
  } catch { liveOpenReferrals.value = 0 }
}

async function refreshLiveCaseCount() {
  try {
    const open       = await dbGetByIndex(STORE.SECONDARY_SCREENINGS, 'case_status', 'OPEN').catch(() => [])
    const inProgress = await dbGetByIndex(STORE.SECONDARY_SCREENINGS, 'case_status', 'IN_PROGRESS').catch(() => [])
    const { a, role } = authScope()
    liveActiveCases.value = [...open, ...inProgress].filter(x => inUserScope(x, a, role)).length
  } catch { liveActiveCases.value = 0 }
}

async function refreshLiveDamagedCount() {
  try {
    const stores = [STORE.PRIMARY_SCREENINGS, STORE.SECONDARY_SCREENINGS, STORE.NOTIFICATIONS, STORE.ALERTS]
    let total = 0
    for (const s of stores) {
      try { total += await dbCountIndex(s, 'sync_status', 'FAILED') } catch {}
    }
    liveDamagedRecords.value = total
  } catch { liveDamagedRecords.value = 0 }
}

async function refreshAllLiveBadges() {
  await Promise.all([
    refreshLiveAlertCount(),
    refreshLiveReferralCount(),
    refreshLiveCaseCount(),
    refreshLiveDamagedCount(),
  ])
}

// Refresh every 12 s + on window focus + on every successful engine sync.
setInterval(refreshAllLiveBadges, 12_000)
window.addEventListener('focus', refreshAllLiveBadges)
window.addEventListener('online', refreshAllLiveBadges)
window.addEventListener('sync-engine:flush-end',     refreshAllLiveBadges)
window.addEventListener('sync-engine:primary-synced',   refreshAllLiveBadges)
window.addEventListener('sync-engine:secondary-phase2', refreshAllLiveBadges)
window.addEventListener('sync-engine:alert-synced',     refreshAllLiveBadges)
refreshAllLiveBadges()

// ─── App constants ────────────────────────────────────────────────────────────
const APP_VERSION  = '0.0.1'
const REF_DATA_VER = 'rda-2026-02-01'

// ─── AUTH — sessionStorage key ────────────────────────────────────────────────
// AUTH_DATA shape (written on login success):
//   body.data from UserLoginController = flat users row:
//     id, role_key, country_code, full_name, username, email, phone,
//     is_active, last_login_at, created_at, updated_at, email_verified_at, name
//   plus client-added:
//     _permissions  — derived from role_key (see derivePermissions)
//     _logged_in_at — ISO timestamp of this login
const AUTH_KEY = 'AUTH_DATA'
declare global { interface Window { SERVER_URL: string; COUNTRY_CODE: string } }

const authData        = ref<Record<string, any> | null>(null)
const isAuthenticated = computed((): boolean => !!authData.value)

// ─── Login form state ─────────────────────────────────────────────────────────
const loginForm        = ref({ login: '', password: '' })
const loginLoading     = ref(false)
const loginError       = ref('')
const loginErrorDetail = ref('')
const showPw           = ref(false)
const focusLogin       = ref(false)
const focusPw          = ref(false)

// ─── Offline detection (reactive — updates on network events) ─────────────────
// Uses @capacitor/network when available (accurate on Android + captive portals)
// and falls back to navigator.onLine on web. The wrapper guarantees no-throw.
const isOffline = ref(!navigator.onLine)
window.addEventListener('online',  () => { isOffline.value = false })
window.addEventListener('offline', () => { isOffline.value = true  })

// i18n offline notice — fires ONCE when an untranslated key is requested
// while the device is offline. Surface as a quiet bottom toast.
window.addEventListener('i18n:offline-notice', async (ev: any) => {
  try {
    const t = await toastController.create({
      message: ev?.detail?.message || 'Some translations need internet — showing English until back online.',
      duration: 3500,
      position: 'bottom',
      color: 'warning',
    })
    void t.present()
  } catch {}
})
// The network plugin drives isOffline once it's up (started from onMounted).
const onNetChange = (st: any) => { try { isOffline.value = !(st && st.connected) } catch {} }
const unsubNet = netPlugin.subscribe(onNetChange)
netPlugin.start().catch(() => {})

// ─── App-lock state machine ───────────────────────────────────────────────────
// The lock screen is a premium overlay that renders over everything else.
// Shown when (a) user has enabled cap.applock.enabled AND set a PIN, and
//  - app is cold-starting, or
//  - app has been in background longer than cap.autolock.minutes
// Failure modes: if the biometric plugin is missing, PIN fallback still works.
const appLockOpen       = ref(false)
const appLockForceSetup = ref(false)
const APP_LOCK_LAST_ACTIVE_KEY = 'app.lock.last_active_at'
function recordActive() { try { localStorage.setItem(APP_LOCK_LAST_ACTIVE_KEY, String(Date.now())) } catch {} }
function idleMinutes(): number {
  try {
    const v = Number(localStorage.getItem(APP_LOCK_LAST_ACTIVE_KEY) || '0')
    if (!v) return Infinity
    return (Date.now() - v) / 60_000
  } catch { return Infinity }
}
function lockThresholdMinutes(): number {
  try {
    const v = Number(localStorage.getItem(CAPABILITY_KEYS.APP_LOCK_MIN) || '5')
    return Number.isFinite(v) && v > 0 ? v : 5
  } catch { return 5 }
}
function maybeShowLock(reason: 'cold' | 'resume') {
  if (!isCapEnabled(CAPABILITY_KEYS.APP_LOCK)) return
  // No point showing the lock while the user isn't authenticated — the login
  // modal owns the screen and the PIN would be redundant.
  if (!isAuthenticated.value) return
  if (!hasPin()) {
    // Capability is on but the user never set a PIN — prompt enrolment once
    // so the lock isn't a silent no-op. Only fire on cold-start or first
    // post-idle resume; re-prompts are debounced by the Settings view.
    appLockForceSetup.value = true
    appLockOpen.value = true
    return
  }
  if (reason === 'resume' && idleMinutes() < lockThresholdMinutes()) return
  appLockForceSetup.value = false
  appLockOpen.value = true
}
function onAppUnlocked() { appLockOpen.value = false; recordActive(); try { window.dispatchEvent(new CustomEvent('app-lock-changed')) } catch {} }
// Settings view requests an enrolment flow.
// RBAC route-denial listener — fires when the router guard sends a user
// back to /home because they lacked permission. We surface a subtle banner
// so the user understands what just happened, without exposing the name of
// the admin route they tried to reach.
window.addEventListener('rbac-denied', () => {
  try {
    // Lightweight signal — App.vue doesn't have its own toast host, so we
    // route through the existing offline-banner-style slot once at most.
    console.debug('[rbac] navigation denied → redirected to /home')
  } catch {}
})

window.addEventListener('app-lock-request-setup', () => {
  appLockForceSetup.value = true
  appLockOpen.value = true
})
function onAppLockForgot() {
  clearPin()
  try { sessionStorage.removeItem('AUTH_DATA') } catch {}
  appLockOpen.value = false
  recordActive()
  try { location.reload() } catch {}
}
function onAppLockCancelSetup() { appLockOpen.value = false }

function onVisibility() {
  if (document.visibilityState === 'visible') {
    maybeShowLock('resume')
  } else {
    recordActive()
  }
}
document.addEventListener('visibilitychange', onVisibility)

// ─── Offline credential cache ─────────────────────────────────────────────────
// localStorage key that stores a bcrypt-free credential fingerprint for offline login.
// We store a SHA-256 hash of (username:password) so we NEVER persist the plaintext
// password. On offline login attempt we re-hash the input and compare.
// This is intentionally lightweight — the server hash is authoritative;
// the offline hash only unblocks the device when the server is unreachable.
const OFFLINE_CREDS_KEY = 'POE_OFFLINE_CREDS'

/** Store a credential fingerprint after a successful server login. */
/** Canonical form of window.SERVER_URL used to scope the offline cache
 *  to a specific backend — guarantees a production-cached payload can't
 *  be replayed against a dev Laravel (or vice versa) and leak a user
 *  that doesn't exist on the current server. */
function currentServerKey(): string {
  const url = String((window as any).SERVER_URL || '').replace(/\/+$/, '').toLowerCase()
  return url || 'unknown'
}

async function cacheOfflineCredentials(login: string, password: string, authPayload: Record<string, any>): Promise<void> {
  try {
    const raw     = login.trim().toLowerCase() + ':' + password
    const encoded = new TextEncoder().encode(raw)
    const hashBuf = await crypto.subtle.digest('SHA-256', encoded)
    const hash    = Array.from(new Uint8Array(hashBuf)).map(b => b.toString(16).padStart(2, '0')).join('')
    const cacheEntry = {
      hash,
      // Bind the cache to the server the payload came from.  tryOfflineLogin
      // refuses to match if the active server URL differs.
      serverKey: currentServerKey(),
      authPayload,
      cachedAt: new Date().toISOString(),
    }
    localStorage.setItem(OFFLINE_CREDS_KEY, JSON.stringify(cacheEntry))
    console.log('%c[POE AUTH] Offline credential cache updated', 'color:#10B981;font-weight:600', 'for', cacheEntry.serverKey)
  } catch (e) {
    // Non-fatal — crypto.subtle unavailable in very old WebViews (should not happen on Android 8+)
    console.warn('[POE AUTH] Failed to cache offline credentials:', e)
  }
}

/** Attempt login using cached credentials. Returns authPayload or null.
 *  Only succeeds if the cache was created against the CURRENT server —
 *  prevents a payload cached on production from being replayed on dev. */
async function tryOfflineLogin(login: string, password: string): Promise<Record<string, any> | null> {
  try {
    const raw = localStorage.getItem(OFFLINE_CREDS_KEY)
    if (!raw) return null
    const cached = JSON.parse(raw) as {
      hash: string
      serverKey?: string
      authPayload: Record<string, any>
      cachedAt: string
    }
    if (!cached?.hash || !cached?.authPayload) return null

    // Scope guard: refuse to match a cache created for a different server.
    // Legacy entries (pre-scope) have no serverKey — treat them as stale
    // and clear them so the user re-authenticates against the live backend.
    const expected = currentServerKey()
    if (!cached.serverKey || cached.serverKey !== expected) {
      console.warn(
        '%c[POE AUTH] Offline cache discarded — server-scope mismatch',
        'color:#F59E0B;font-weight:700',
        { cached: cached.serverKey ?? '(pre-scope)', current: expected }
      )
      try { localStorage.removeItem(OFFLINE_CREDS_KEY) } catch { /* ignore */ }
      return null
    }

    const input   = login.trim().toLowerCase() + ':' + password
    const encoded = new TextEncoder().encode(input)
    const hashBuf = await crypto.subtle.digest('SHA-256', encoded)
    const inputHash = Array.from(new Uint8Array(hashBuf)).map(b => b.toString(16).padStart(2, '0')).join('')

    if (inputHash === cached.hash) {
      console.log('%c[POE AUTH] Offline login matched cached credentials', 'color:#10B981;font-weight:700')
      console.log('%c[POE AUTH] Cached at:', 'color:#10B981', cached.cachedAt, 'for', cached.serverKey)
      return cached.authPayload
    }
    return null
  } catch (e) {
    console.warn('[POE AUTH] Offline login check failed:', e)
    return null
  }
}

// ─── SSOT role keys — users.role_key VARCHAR(60) ─────────────────────────────
type RoleKey =
  | 'POE_PRIMARY' | 'POE_SECONDARY' | 'POE_DATA_OFFICER'
  | 'POE_ADMIN' | 'DISTRICT_SUPERVISOR' | 'PHEOC_OFFICER' | 'NATIONAL_ADMIN'
  | 'SCREENER'

const ROLE_LABELS: Record<RoleKey, string> = {
  POE_PRIMARY:         'Primary Officer',
  POE_SECONDARY:       'Secondary Officer',
  POE_DATA_OFFICER:    'Data Officer',
  POE_ADMIN:           'POE Admin',
  DISTRICT_SUPERVISOR: 'District Supervisor',
  PHEOC_OFFICER:       'PHEOC Officer',
  NATIONAL_ADMIN:      'National Admin',
  SCREENER:            'Screener',
}

// ─── Client-side permission derivation ───────────────────────────────────────
// The controller returns only the users row — no permissions object.
// Permissions are computed from role_key here so RBAC still works
// for menu visibility and route gating throughout the app.
function derivePermissions(roleKey: string | null): Record<string, boolean> {
  const base: Record<string, boolean> = {
    can_do_primary_screening:   false,
    can_do_secondary_screening: false,
    can_submit_aggregated:      false,
    can_manage_users:           false,
    can_view_all_poe_data:      false,
    can_view_district_data:     false,
    can_view_province_data:     false,
    can_view_national_data:     false,
    can_manage_poes:            false,
    can_acknowledge_alerts:     false,
    can_close_notifications:    false,
  }
  const m: Record<string, Partial<Record<string, boolean>>> = {
    POE_PRIMARY:         { can_do_primary_screening: true,  can_view_all_poe_data: true,  can_close_notifications: true },
    POE_SECONDARY:       { can_do_secondary_screening: true, can_view_all_poe_data: true, can_close_notifications: true, can_acknowledge_alerts: true },
    POE_DATA_OFFICER:    { can_do_primary_screening: true,  can_do_secondary_screening: true, can_submit_aggregated: true, can_view_all_poe_data: true, can_acknowledge_alerts: true, can_close_notifications: true },
    POE_ADMIN:           { can_do_primary_screening: true,  can_do_secondary_screening: true, can_submit_aggregated: true, can_manage_users: true, can_view_all_poe_data: true, can_manage_poes: true, can_acknowledge_alerts: true, can_close_notifications: true },
    // DISTRICT_SUPERVISOR: full operational capability within their district.
    // Any POE in their district is fair game — screening, secondary cases,
    // aggregated data, manage users + POEs, acknowledge/close.
    DISTRICT_SUPERVISOR: { can_do_primary_screening: true, can_do_secondary_screening: true, can_submit_aggregated: true, can_view_all_poe_data: true, can_view_district_data: true, can_manage_users: true, can_manage_poes: true, can_acknowledge_alerts: true, can_close_notifications: true },
    // PHEOC_OFFICER: full operational capability within their PHEOC / province.
    PHEOC_OFFICER:       { can_do_primary_screening: true, can_do_secondary_screening: true, can_submit_aggregated: true, can_view_all_poe_data: true, can_view_district_data: true, can_view_province_data: true, can_manage_users: true, can_manage_poes: true, can_acknowledge_alerts: true, can_close_notifications: true },
    NATIONAL_ADMIN:      { can_do_primary_screening: true,  can_do_secondary_screening: true, can_submit_aggregated: true, can_manage_users: true, can_view_all_poe_data: true, can_view_district_data: true, can_view_province_data: true, can_view_national_data: true, can_manage_poes: true, can_acknowledge_alerts: true, can_close_notifications: true },
    SCREENER:            { can_do_primary_screening: true,  can_view_all_poe_data: true,  can_close_notifications: true },
  }
  return { ...base, ...(m[roleKey ?? ''] ?? {}) } as Record<string, boolean>
}

// ─── Router ───────────────────────────────────────────────────────────────────
const router      = useRouter()
const route       = useRoute()
const currentPath = computed(() => route.path)

// 2026-05-06 v5: Google Translate is hardcoded in index.html. Vue does
// not mount, render, or own any part of that widget — keeping it outside
// the reactive graph is the only way to prevent Vue from eating the
// <font>-wrapped translated text on the next re-render.
//
// If a Vue component ever wants to programmatically open the Translate
// panel, call window.__POE_TOGGLE_TRANSLATE__() — exposed by index.html.

// ─── PWA install hints — iOS-only (2026-05-08) ─────────────────────────────
// iOS Safari: surface "Add to Home Screen" instructions (manual flow — iOS
// does not fire beforeinstallprompt).
// Non-iOS browsers (Android Chrome, desktop Chrome, dev-tools responsive
// mode): the PWA install button has been removed per executive directive.
// Those users see a "Download Android APK" hint instead — the signed APK is
// the only sanctioned non-iOS install path.
// Capacitor (native shell): both hints suppressed.
import { isIos, isStandalone, shouldShowIosInstallHint, dismissIosInstallHint } from '@/services/pwa'

const NON_IOS_HINT_KEY = 'poe.nonios_hint_dismissed_at'
const NON_IOS_COOLDOWN_MS = 14 * 24 * 60 * 60 * 1000  // 14 days

const iosInstallHintVisible    = ref(false)
const nonIosDownloadHintVisible = ref(false)

function dismissIosHint() {
  iosInstallHintVisible.value = false
  dismissIosInstallHint()
}
function dismissNonIosHint() {
  nonIosDownloadHintVisible.value = false
  try { localStorage.setItem(NON_IOS_HINT_KEY, String(Date.now())) } catch {}
}
function _shouldShowNonIosHint(): boolean {
  try {
    const last = Number(localStorage.getItem(NON_IOS_HINT_KEY) || '0')
    if (!Number.isFinite(last) || last <= 0) return true
    return (Date.now() - last) > NON_IOS_COOLDOWN_MS
  } catch {
    return true
  }
}
function _isCapacitor(): boolean {
  const w: any = window as any
  return !!(w.Capacitor?.isNativePlatform?.() || w.Capacitor)
}

onMounted(() => {
  setTimeout(() => {
    try {
      const inCapacitor = _isCapacitor()
      const standalone  = isStandalone()
      const onIos       = isIos()
      iosInstallHintVisible.value =
        !inCapacitor && !standalone && onIos && shouldShowIosInstallHint()
      nonIosDownloadHintVisible.value =
        !inCapacitor && !standalone && !onIos && _shouldShowNonIosHint()
    } catch {}
  }, 800)
})

// ─── Identity display — reads from real authData when logged in ───────────────
const displayName = computed(() =>
  authData.value?.full_name ?? authData.value?.name ?? authData.value?.username ?? 'User'
)

const userInitials = computed(() =>
  displayName.value.split(' ').filter(Boolean).slice(0, 2)
    .map((w: string) => w[0]?.toUpperCase() ?? '').join('')
)

const scopeLabel = computed(() => {
  const d = authData.value
  if (!d) return ''
  // Root-level shortcuts come from primary assignment (set by controller)
  if (d.poe_code)      return `POE · ${d.poe_code}`
  if (d.district_code) return `District · ${d.district_code}`
  if (d.pheoc_code)    return `PHEOC · ${d.pheoc_code}`
  if (d.province_code) return `Province · ${d.province_code}`
  // Fallback to users.country_code
  if (d.country_code)  return `Country · ${d.country_code}`
  return ''
})

const roleCss = computed(() =>
  (authData.value?.role_key ?? '').toLowerCase().replace(/_/g, '-')
)

// ─── Mock counts (replace with IndexedDB store reads in later sprint) ─────────
const mockCounts = {
  unsynced:      7,
  openReferrals: 3,
  activeCases:   5,
  openAlerts:    1,
}
const mockDeviceId = 'ECSA-7K2M'

// ─── Sync display — wired to the unified syncEngine ─────────────────────────
// Mandate 2026-05-06: every page must show a live sync indicator. Reads
// engine state every 2 s + on every engine event so the header badge
// reflects what the engine is actually doing right now.
type SyncState = 'synced' | 'unsynced' | 'syncing' | 'failed' | 'offline'
const syncState = ref<SyncState>('synced')
const syncPendingTotal = ref(0)
const syncStuckTotal   = ref(0)

const syncLabel = computed(() => {
  if (syncState.value === 'offline') return 'Offline — auto-sync paused'
  if (syncState.value === 'syncing') return `Syncing… ${syncPendingTotal.value} pending`
  if (syncState.value === 'unsynced') {
    if (syncStuckTotal.value > 0) return `${syncPendingTotal.value} pending (${syncStuckTotal.value} stuck)`
    return `${syncPendingTotal.value} pending`
  }
  return 'All synced'
})
const syncIcon = computed(() => ({
  synced:   cloudDoneOutline,
  unsynced: cloudUploadOutline,
  syncing:  syncOutline,
  failed:   warningOutline,
  offline:  cloudUploadOutline,
}[syncState.value]))

let _appSyncUnsub: (() => void) | null = null
let _appSyncTimer: any = null
async function _refreshAppSync() {
  try {
    const { syncEngineState, getPendingCounts } = await import('@/services/syncEngine')
    const s = syncEngineState()
    if (!s.online) { syncState.value = 'offline'; return }
    const counts = await getPendingCounts()
    let pending = 0, stuck = 0
    for (const c of counts) { pending += (c.pending || 0); stuck += (c.stuck4xx || 0) }
    syncPendingTotal.value = pending
    syncStuckTotal.value   = stuck
    if (s.flushing) syncState.value = 'syncing'
    else if (pending > 0) syncState.value = 'unsynced'
    else syncState.value = 'synced'
  } catch { /* engine optional */ }
}
onMounted(async () => {
  _refreshAppSync()
  _appSyncTimer = setInterval(_refreshAppSync, 2000)
  try {
    const { onSyncEvent } = await import('@/services/syncEngine')
    _appSyncUnsub = onSyncEvent(() => { _refreshAppSync() })
  } catch {}
  if (typeof window !== 'undefined') {
    window.addEventListener('online',  _refreshAppSync)
    window.addEventListener('offline', _refreshAppSync)
  }
})
onUnmounted(() => {
  if (_appSyncTimer) { clearInterval(_appSyncTimer); _appSyncTimer = null }
  if (_appSyncUnsub) { try { _appSyncUnsub() } catch {} _appSyncUnsub = null }
  if (typeof window !== 'undefined') {
    window.removeEventListener('online',  _refreshAppSync)
    window.removeEventListener('offline', _refreshAppSync)
  }
})

// ─── RBAC — derived from real role_key after login ────────────────────────────
const r = computed(() => authData.value?.role_key ?? '')
const inRoles = (...roles: RoleKey[]) => roles.includes(r.value as RoleKey)
// Supervisor roles (NATIONAL_ADMIN, PHEOC_OFFICER, DISTRICT_SUPERVISOR) are
// implicitly added to every POE-level capability — within their own scope.
// Geographic enforcement happens at the controller / data layer; the menu
// simply exposes the view.
const SUPERVISOR_ROLES = ['DISTRICT_SUPERVISOR', 'PHEOC_OFFICER', 'NATIONAL_ADMIN'] as const
const can = computed(() => ({
  primary:      inRoles('POE_PRIMARY', 'POE_SECONDARY', 'POE_ADMIN', 'SCREENER', ...SUPERVISOR_ROLES),
  queue:        inRoles('POE_PRIMARY', 'SCREENER', 'POE_SECONDARY', 'POE_ADMIN', ...SUPERVISOR_ROLES),
  secondary:    inRoles('POE_PRIMARY', 'SCREENER', 'POE_SECONDARY', 'POE_ADMIN', ...SUPERVISOR_ROLES),
  alerts:       inRoles('POE_SECONDARY', 'POE_ADMIN', ...SUPERVISOR_ROLES),
  aggregated:   inRoles('POE_DATA_OFFICER', 'POE_ADMIN', ...SUPERVISOR_ROLES),
  surveillance: inRoles(...SUPERVISOR_ROLES),
  admin:        inRoles('POE_ADMIN', ...SUPERVISOR_ROLES),
  system:       r.value === 'NATIONAL_ADMIN',
}))

// ─── Navigation helper ────────────────────────────────────────────────────────
async function navTo(path: string) {
  await menuController.close('app-menu')
  await router.push(path)
}

// ─── Sign out — replaces mock handleSignOut, uses real auth teardown ──────────
async function handleSignOut() {
  await menuController.close('app-menu')
  const alert = await alertController.create({
    header:  'Sign Out',
    message: syncPendingTotal.value > 0
      ? `${syncPendingTotal.value} unsynced record(s) will remain safely on this device. Sign out anyway?`
      : 'Are you sure you want to sign out?',
    buttons: [
      { text: 'Cancel', role: 'cancel' },
      {
        text: 'Sign Out', role: 'destructive',
        handler: async () => {
          console.log('%c[POE AUTH] User signed out — AUTH_DATA cleared', 'color:#DC3545;font-weight:700')
          sessionStorage.removeItem(AUTH_KEY)
          // Clear the app-lock PIN + biometric enrolment so the next user
          // on this device is not gated by the prior user's credentials.
          try { clearPin() } catch {}
          appLockOpen.value = false
          appLockForceSetup.value = false
          authData.value         = null
          loginError.value       = ''
          loginErrorDetail.value = ''
          void router.replace('/')
          const t = await toastController.create({
            message:  'You have been signed out.',
            duration: 2000,
            position: 'bottom',
            color:    'medium',
          })
          void t.present()
        },
      },
    ],
  })
  await alert.present()
}

// ─────────────────────────────────────────────────────────────────────────────
//  MENU CONFIG — original, untouched
// ─────────────────────────────────────────────────────────────────────────────

interface MenuItem {
  id:           string
  label:        string
  sub:          string
  route?:       string
  icon:         string
  iconClass?:   string
  badge?:       () => number
  badgeVariant?:string
  tag?:         string
  tagVariant?:  string
  ariaLabel?:   string
  danger?:      boolean
  accentColor?: string
  action?:      () => void
  anchorId?:    string  // tour-spotlight anchor id (optional)
  show?:        boolean // per-item RBAC gate — undefined = visible
}
interface MenuGroup {
  id:    string
  title?:string
  show:  boolean
  items: MenuItem[]
}

const menuGroups = computed((): MenuGroup[] => [

 // ── 0. WELCOME / AI BRIEFING ────────────────────────────────────────────────
// Role-aware first-run briefing. Always reachable so users can replay it.
{
  id: 'welcome', show: true,
  items: [{
    id: 'welcome', label: 'Welcome Briefing', icon: sparklesOutline,
    sub: 'Role-aware guide · what you can do',
    route: '/welcome',
    accentColor: 'teal',
  }],
},

 // ── 1. DASHBOARD ────────────────────────────────────────────────────────────
// Read-only summary: total screenings, open referrals, active cases, alerts,
// unsynced count. Entry point after login. Visible all roles.
{
  id: 'dashboard', show: true,
  items: [{
    id: 'dashboard', label: 'Dashboard', icon: gridOutline,
    sub: 'Operational summary · sync status',
    route: '/home',
  }],
},

// ── POE MANAGEMENT ───────────────────────────────────────────────────────────
{
  id: 'poe-management', title: 'POE MANAGEMENT', show: canPerm('admin.poes'),
  items: [
    {
      id: 'poe-management-create-poe', label: 'Manage POEs', icon: mapOutline,
      sub: 'Add · edit · assign district & PHEOC · set status',
      route: '/POEs',
      tag: 'core', tagVariant: 'primary',
      ariaLabel: 'Manage Points of Entry — register, configure and assign POE hierarchy',
    },
  ],
},
// ── DISEASE MANAGEMENT ───────────────────────────────────────────────────────
{
  id: 'disease-management', title: 'DISEASE MANAGEMENT', show: canPerm('admin.diseases'),
  items: [
    {
      id: 'disease-management', label: 'Tracked Diseases', icon: mapOutline,
      sub: 'Manage and view tracked diseases in the system',
      route: '/DiseaseInteligence',
      tag: 'core', tagVariant: 'primary',
      ariaLabel: 'Manage and view tracked diseases in the system',
    },
  ],
},

// ── USER MANAGEMENT ──────────────────────────────────────────────────────────
{
  id: 'user-management', title: 'USER MANAGEMENT', show: canPerm('admin.users'),
  items: [
    {
      id: 'user-management-create-user', label: 'Manage Users', icon: peopleOutline,
      sub: 'Create · assign role · activate / deactivate',
      route: '/Users',
      ariaLabel: 'Manage user accounts — create users, assign roles and geographic scope',
    },
  ],
},

// ── 2. SCREENING WORKFLOWS ───────────────────────────────────────────────────
// DFD: Traveler arrives → primary officer captures gender / temp / symptoms
//   symptoms=NO → record saved offline, traveler exits
//   symptoms=YES → notification auto-created → secondary queue → case opened
// DB: primary_screenings + notifications + secondary_screenings (all offline-first)
{
  id: 'screening', title: 'SCREENING', show: true, /* all operational roles including SCREENER */
  items: [
    {
      // Ultra-fast capture: gender + optional temp + symptoms YES/NO.
      // symptoms=YES auto-creates SECONDARY_REFERRAL notification atomically.
      id: 'primary-new', label: ' Primary Screening', icon: medkitOutline,
      sub: 'Capture traveler · temperature · symptoms',
      iconClass: 'mn__icon--create',
      route: '/PrimaryScreening',
      tag: '10s', tagVariant: 'speed',
      ariaLabel: 'Start new primary screening — ultra-fast 10-second traveler capture',
      show: canPerm('screening.capture'),
    },
    {
      // Referral queue: lists all OPEN SECONDARY_REFERRAL notifications at this POE.
      // Secondary officer picks up a referral here to open a secondary case.
      id: 'secondary-queue', label: 'Secondary Screening', icon: gitMergeOutline,
      sub: 'Open referrals · pick up case · action required',
      iconClass: 'mn__icon--secondary',
      route: '/NotificationsCenter',
      badge: () => liveOpenReferrals.value, badgeVariant: 'danger',
      ariaLabel: 'Secondary referral queue — open notifications awaiting secondary officer',
      show: canPerm('secondary.queue'),
    },
    {
      // Full WHO/IHR secondary case investigation records.
      id: 'secondary-records', label: 'Secondary Case Records', icon: documentTextOutline,
      sub: 'All cases · clinical findings · disposition',
      route: '/secondary-screening/records',
      badge: () => liveActiveCases.value, badgeVariant: 'warning',
      ariaLabel: 'Secondary screening case records — view, search and filter all cases',
      show: canPerm('secondary.records'),
    },
    {
      // Paginated list of all primary screenings at this POE with sync status.
      id: 'primary-records', label: 'Primary Screening Records', icon: listOutline,
      sub: 'View · search · filter · sync status',
      route: '/primary-screening/records',
      ariaLabel: 'Primary screening records — full list with search, filters and sync state',
      show: canPerm('screening.records'),
    },
    {
      // POE-scoped primary screening analytics + filter-aware PDF report.
      id: 'primary-report', label: 'Primary Screening Report', icon: documentTextOutline,
      sub: 'Stats · trends · symptom mix · PDF export',
      route: '/primary-screening/report',
      ariaLabel: 'Primary screening report — analytics and filter-aware PDF for this POE',
      show: canPerm('screening.records'),
    },
    {
      // POE-scoped secondary screening analytics + filter-aware PDF report.
      id: 'secondary-report', label: 'Secondary Screening Report', icon: documentTextOutline,
      sub: 'Risk distribution · disposition · syndromes · PDF',
      route: '/secondary-screening/report',
      ariaLabel: 'Secondary screening report — analytics and filter-aware PDF for this POE',
      show: canPerm('secondary.records'),
    },
    {
      // Intelligence dashboard: primary + secondary screening analytics, surveillance signals, AI analysis.
      id: 'screening-dashboard', label: 'Screening Intelligence', icon: barChartOutline,
      sub: 'Surveillance · trends · referral funnel · AI signals',
      route: '/screening-dashboard',
      ariaLabel: 'Screening intelligence dashboard — primary and secondary surveillance analytics',
      show: canPerm('screening.intelligence'),
    },
  ],
},

  // ── 3. REFERRAL / NOTIFICATION WORKFLOW ────────────────────────────────────
  // DFD step: symptoms=YES → notification auto-created (OPEN) →
  //   Secondary officer picks up → moves to IN_PROGRESS → closes after case
  // DB: notifications  status: OPEN → IN_PROGRESS → CLOSED
  // {
  //   id: 'referrals', title: 'REFERRAL WORKFLOW', show: true  /* DEV: auth guard disabled */,
  //   items: [
  //     {
  //       id: 'notif-queue', label: 'Referral Queue', icon: gitMergeOutline,
  //       sub: 'Open · in-progress · action required',
  //       route: '/notifications',
  //       badge: () => mockCounts.openReferrals, badgeVariant: 'danger',
  //       ariaLabel: `Referral queue — ${mockCounts.openReferrals} open`,
  //     },
  //     {
  //       id: 'notif-history', label: 'Referral History', icon: archiveOutline,
  //       sub: 'Closed referrals · audit trail',
  //       route: '/notifications/history',
  //     },
  //   ],
  // },

  // ── 4. SECONDARY SCREENING ─────────────────────────────────────────────────
  // DFD step: Secondary officer opens referral → full WHO/IHR investigation:
  //   Tab 1 Triage & safety  Tab 2 Identity/demographics
  //   Tab 3 Travel history   Tab 4 Exposure & risk
  //   Tab 5 Symptoms         Tab 6 Vitals & clinical
  //   Tab 7 Measures taken   Tab 8 Assessment & disposition
  // DB: secondary_screenings (main) + child tables:
  //   secondary_travel_countries, secondary_symptoms, secondary_exposures,
  //   secondary_actions, secondary_samples, secondary_suspected_diseases
  // case_status: OPEN → IN_PROGRESS → DISPOSITIONED → CLOSED
  // {
  //   id: 'secondary', title: 'SECONDARY SCREENING', show: true  /* DEV: auth guard disabled */,
  //   items: [
  //     {
  //       id: 'secondary-active', label: 'Active Investigations', icon: medkitOutline,
  //       sub: 'WHO/IHR case investigation · 8 tabs',
  //       iconClass: 'mn__icon--secondary',
  //       route: '/secondary-screening',
  //       badge: () => mockCounts.activeCases, badgeVariant: 'warning',
  //       ariaLabel: `Active secondary investigations — ${mockCounts.activeCases} open`,
  //     },
  //     {
  //       id: 'secondary-records', label: 'Case Records', icon: documentTextOutline,
  //       sub: 'All cases · linked primary & referral',
  //       route: '/secondary-screening/records',
  //     },
  //     {
  //       id: 'secondary-samples', label: 'Samples & Testing', icon: clipboardOutline,
  //       sub: 'Sample IDs · lab destination · results',
  //       route: '/secondary-screening/samples',
  //     },
  //   ],
  // },

  // ── 5. ALERTS ───────────────────────────────────────────────────────────────
  // DFD step: Secondary officer assesses HIGH/CRITICAL risk →
  //   Alert auto-generated (rule-based) or officer-created →
  //   Routed to correct tier: DISTRICT | PHEOC | NATIONAL
  //   Supervisor acknowledges → escalates or closes
  // DB: alerts  status: OPEN → ACKNOWLEDGED → CLOSED
  //            risk_level: LOW | MEDIUM | HIGH | CRITICAL
  //            routed_to_level: DISTRICT | PHEOC | NATIONAL
  {
    id: 'alerts', title: 'ALERTS', show: canPerm('alerts.active') || canPerm('alerts.mycases') || canPerm('alerts.intelligence') || canPerm('alerts.matrix'),
    items: [
      {
        // "My Cases" — fastest path to action. Officer's personal triage queue
        // surfacing OPEN-in-scope, MINE (acked/owned), and SLA-risky alerts
        // with one-tap Acknowledge / SmartCloseWizard inline. War Room is one
        // tap deeper for the full lifecycle hub.
        id: 'alerts-mycases', label: 'My Cases', icon: flashOutline,
        sub: 'Quick triage · ack · close · resolve blockers',
        iconClass: 'mn__icon--alert',
        route: '/my-cases',
        badge: () => liveOpenAlerts.value, badgeVariant: 'danger',
        ariaLabel: 'My cases — quick triage',
        show: canPerm('alerts.mycases'),
      },
      {
        id: 'alerts-active', label: 'Active Alerts', icon: warningOutline,
        sub: 'Open · acknowledge · escalate · route',
        iconClass: 'mn__icon--alert',
        route: '/alerts',
        badge: () => liveOpenAlerts.value, badgeVariant: 'danger',
        ariaLabel: 'Active alerts',
        show: canPerm('alerts.active'),
      },
      // 2026-05-17: Alert Intelligence disconnected from the mobile app.
      // Sidebar entry removed; /alerts/intelligence route removed in router.
      // 2026-05-06: WHO Matrix sidebar entry removed by mandate. The
      // /alerts/matrix route is preserved in router/index.js so any deep
      // link / external bookmark still resolves — only the sidebar tile
      // is gone. Re-add the {} block above ↑ to restore.
    ],
  },

  // ── 6. AGGREGATED DATA SUBMISSION ──────────────────────────────────────────
  // DFD step: Independent parallel workflow — Data Officer aggregates counts
  //   (total_screened, by gender, symptomatic/asymptomatic) for reporting period
  //   → saves offline → queues for sync
  // DB: aggregated_submissions
  {
    id: 'aggregated', title: 'AGGREGATED DATA', show: canPerm('aggregated.reports') || canPerm('admin.aggregated.wizard') || canPerm('admin.poe-contacts'),
    items: [
      {
        id: 'agg-hub', label: 'Reports', icon: cloudUploadOutline,
        sub: 'Browse & submit · daily · weekly · ad-hoc',
        iconClass: 'mn__icon--create',
        route: '/aggregated-data',
        show: canPerm('aggregated.reports'),
      },
      {
        id: 'agg-history', label: 'Submission History', icon: barChartOutline,
        sub: 'Past reports · details · sync state',
        route: '/aggregated-data/history',
        show: canPerm('aggregated.history'),
      },
      {
        id: 'agg-wizard', label: 'Create Report Template', icon: addCircleOutline,
        sub: '5-step wizard · publish to all POEs',
        route: '/admin/aggregated-wizard',
        show: canPerm('admin.aggregated.wizard'),
      },
      {
        id: 'agg-template', label: 'Template Settings', icon: cogOutline,
        sub: 'Edit columns · retire · version',
        route: '/admin/aggregated-templates',
        show: canPerm('admin.aggregated.templates'),
      },
      {
        id: 'agg-contacts', label: 'Notification Contacts', icon: peopleOutline,
        sub: 'POE escalation · email recipients',
        route: '/admin/poe-contacts',
        show: canPerm('admin.poe-contacts'),
      },
    ],
  },

  // ── 7. SURVEILLANCE (hierarchy-scoped read views) ───────────────────────────
  // DFD step: Supervisors review cross-POE data within their geographic scope
  //   District: sees own district_code data
  //   PHEOC:    sees province_code / pheoc_code data
  //   National: sees all data
  // Sources: primary_screenings + secondary_screenings + alerts +
  //          aggregated_submissions + secondary_suspected_diseases
  // {
  //   id: 'surveillance', title: 'SURVEILLANCE', show: true  /* DEV: auth guard disabled */,
  //   items: [
  //     {
  //       id: 'surv-overview', label: 'Overview', icon: analyticsOutline,
  //       sub: 'Cross-POE summary · jurisdiction scope',
  //       route: '/surveillance/overview',
  //     },
  //     {
  //       id: 'surv-trends', label: 'Screening Trends', icon: statsChartOutline,
  //       sub: 'Primary + secondary volumes · time series',
  //       route: '/surveillance/screening-trends',
  //     },
  //     {
  //       id: 'surv-signals', label: 'Disease Signals', icon: pulseOutline,
  //       sub: 'Syndromes · suspected diseases · risk levels',
  //       route: '/surveillance/disease-signals',
  //     },
  //     {
  //       id: 'surv-alerts', label: 'Alert Summary', icon: layersOutline,
  //       sub: 'Alerts by POE · level · risk · status',
  //       route: '/surveillance/alert-summary',
  //     },
  //     {
  //       id: 'surv-agg', label: 'Aggregated Reports', icon: barChartOutline,
  //       sub: 'Submitted counts from all jurisdiction POEs',
  //       route: '/surveillance/aggregated-reports',
  //     },
  //   ],
  // },

  // ── 8. SYNC MANAGEMENT ──────────────────────────────────────────────────────
  // DFD step: All offline records across 5 stores queue as UNSYNCED →
  //   Officer initiates manual sync → sync_batch created (client_batch_uuid) →
  //   Server validates per entity → returns server IDs →
  //   sync_batch_items record ACCEPTED|REJECTED per entity →
  //   Local records updated to SYNCED | FAILED
  // DB: sync_batches + sync_batch_items
  //     entity_type: PRIMARY|NOTIFICATION|SECONDARY|ALERT|AGGREGATED
  {
    // 2026-05-07: Sync Centre sidebar entry removed by mandate.
    // Reachable via Settings → Sync Centre card. The /sync route is
    // preserved in the router so deep links still work, and the Settings
    // page surfaces a prominent card with the pending-record badge.
    id: 'sync-removed', title: '', show: false, items: [],
  },

  // ── 9. ADMINISTRATION ───────────────────────────────────────────────────────
  // DFD step: Admin manages user accounts and geographic scope assignments
  // DB: users (CRUD) + user_assignments (CRUD)
  //     user_assignments scope: country → province → pheoc → district → poe
  // {
  //   id: 'admin', title: 'ADMINISTRATION', show: true  /* DEV: auth guard disabled */,
  //   items: [
  //     {
  //       id: 'admin-users', label: 'User Management', icon: peopleOutline,
  //       sub: 'Create · edit · roles · activate/deactivate',
  //       route: '/PrimaryScreening',
  //     },
  //     {
  //       id: 'admin-users-new', label: 'Add User', icon: personAddOutline,
  //       sub: 'New user account · assign role',
  //       iconClass: 'mn__icon--create',
  //       route: '/admin/users/new',
  //     },
  //     {
  //       id: 'admin-assignments', label: 'User Assignments', icon: mapOutline,
  //       sub: 'Country · district · PHEOC · POE scope',
  //       route: '/admin/assignments',
  //     },
  //   ],
  // },

  // ── 10. ACCOUNT & DEVICE ───────────────────────────────────────────────────
  // Profile: own users row (restricted fields only)
  // App Settings: device_id, platform, offline behavior config
  // Reference Data: READ-ONLY viewer of hardcoded JSON
  //   (countries, admin codes, symptoms, diseases, action codes, exposure codes)
  // {
  //   id: 'account', title: 'ACCOUNT & DEVICE', show: true,
  //   items: [
  //     {
  //       id: 'profile', label: 'My Profile', icon: personCircleOutline,
  //       sub: 'View · update own details',
  //       route: '/profile',
  //     },
  //     {
  //       id: 'settings', label: 'App Settings', icon: cogOutline,
  //       sub: 'Device info · offline behavior · display',
  //       route: '/settings',
  //     },
  //     {
  //       id: 'ref-data', label: 'Reference Data', icon: libraryOutline,
  //       sub: 'Countries · symptoms · diseases · codes',
  //       route: '/admin/reference-data',
  //     },
  //   ],
  // },

  // ── 11. SYSTEM SETTINGS (REMOVED — duplicate screen) ─────────────────────
  // The "System Settings" row used to point to /admin/system, which is just a
  // redirect to /settings. Two menu rows landing on the same screen is a
  // duplicate. NATIONAL_ADMIN-only knobs (if/when added) belong as cards
  // inside AppSettings, gated by `canPerm('admin.system')`. Kept the redirect
  // /admin/system → /settings in the router for back-compat with deep links.
  // (audit F-001, decision D-001)

  // 2026-05-07: Staff Directory sidebar entry removed by mandate.
  // Reachable via Settings → Staff Directory card. Route /directory
  // preserved in the router for deep links.

  // 2026-05-06 v5: Translate sidebar entry removed. The trigger button
  // is hardcoded in index.html as a fixed-position floating control
  // (top-right of the viewport) that Vue does not own. This survives
  // Vue re-renders, route changes, and HMR — same reason the v1 build
  // worked: the widget lives outside the SPA mount.

  // ── ACCOUNT, HELP & SETTINGS — always visible ─────────────────────────────
  {
    id: 'capabilities', title: 'SETTINGS', show: true,
    items: [
      // 2026-05-07: My Profile entry removed from sidebar. Now appears
      // as a card inside App Settings (along with Sync Centre, Staff
      // Directory, Translate, etc.). Route /profile preserved in router.
      {
        id: 'app-settings', label: 'App Settings', icon: settingsOutline,
        sub: 'Profile · sync · staff directory · preferences · help',
        route: '/settings',
        anchorId: 'tour-anchor-settings',
        ariaLabel: 'App settings — profile, sync, staff directory, preferences, help',
      },
    ],
  },

  // ── 12. SIGN OUT ────────────────────────────────────────────────────────────
  // Clears localStorage auth tokens ONLY.
  // IndexedDB is NEVER wiped — unsynced records must survive session end.
  {
    id: 'signout', show: true,
    items: [{
      id: 'sign-out', label: 'Sign Out', icon: logOutOutline,
      sub: mockCounts.unsynced > 0 ? `${mockCounts.unsynced} offline records stay on device` : 'Session will end',
      danger: true,
      action: handleSignOut,
      ariaLabel: 'Sign out — unsynced records remain on device',
    }],
  },

].filter(g => g.show))

// ─── Footer rows ──────────────────────────────────────────────────────────────
const footerRows = [
  { key: 'ECSA · POE SYSTEM', val: `v${APP_VERSION}`,  mono: false },
  { key: 'REF DATA',          val: REF_DATA_VER,        mono: true  },
  { key: 'DEVICE',            val: mockDeviceId,        mono: true  },
]

// ─── Session restore on mount ─────────────────────────────────────────────────
// Validates id (user exists) and is_active.
// The controller returns the flat users row — no nested user object.
onMounted((): void => {
  try {
    const raw = sessionStorage.getItem(AUTH_KEY)
    if (!raw) return
    const parsed = JSON.parse(raw) as Record<string, any>
    // Validate minimum fields:
    //   id          — user exists on server
    //   is_active   — account still enabled
    //   _logged_in_at — client-side timestamp; undefined = stale session from
    //                   a previous code version, must force re-login
    const isValid =
      parsed?.id &&
      parsed?.is_active === true &&
      typeof parsed._logged_in_at === 'string'

    // Server-scope guard: if the session was captured against a DIFFERENT
    // backend, treat it as stale.  Prevents a production AUTH_DATA from
    // leaking into a dev environment where the user id doesn't exist.
    //
    // Legacy sessions (pre-scope) have no _server_key.  We accept them
    // and stamp the current server key in place, so an already-logged-in
    // user is NOT forced to re-authenticate just because the code was
    // upgraded — the id either exists on this server (safe) or the very
    // next API call will 401/404 and prompt re-login naturally.
    const sessionServerKey = typeof parsed?._server_key === 'string' ? parsed._server_key : null
    const currentKey = currentServerKey()
    let scopeOk: boolean
    if (!sessionServerKey) {
      // Legacy session — adopt the current server key and persist.
      parsed._server_key = currentKey
      try { sessionStorage.setItem(AUTH_KEY, JSON.stringify(parsed)) } catch { /* quota */ }
      scopeOk = true
      console.log('%c[POE AUTH] Legacy session adopted current server key', 'color:#17A2B8;font-weight:600', currentKey)
    } else {
      scopeOk = sessionServerKey === currentKey
    }

    if (isValid && scopeOk) {
      authData.value = parsed
      // DEV: dump exactly what was restored so the developer can see every field
      console.group('%c[POE AUTH] Session restored from sessionStorage', 'color:#0066CC;font-weight:700;font-size:13px')
      console.log('%c── users table fields ──', 'color:#0066CC;font-weight:600')
      console.table({
        id:                parsed.id,
        role_key:          parsed.role_key,
        full_name:         parsed.full_name,
        name:              parsed.name,
        username:          parsed.username,
        email:             parsed.email,
        phone:             parsed.phone,
        country_code:      parsed.country_code,
        is_active:         parsed.is_active,
        last_login_at:     parsed.last_login_at,
        created_at:        parsed.created_at,
        updated_at:        parsed.updated_at,
        email_verified_at: parsed.email_verified_at,
      })
      console.log('%c── assignment (primary) ──', 'color:#E83E8C;font-weight:600', parsed.assignment ?? 'none')
      console.log('%c── all_assignments ──', 'color:#FD7E14;font-weight:600', parsed.all_assignments ?? [])
      console.log('%c── geographic shortcuts ──', 'color:#28A745;font-weight:600', {
        poe_code:      parsed.poe_code,
        district_code: parsed.district_code,
        province_code: parsed.province_code,
        pheoc_code:    parsed.pheoc_code,
      })
      console.log('%c── _permissions ──', 'color:#6F42C1;font-weight:600', parsed._permissions)
      console.log('%c── _logged_in_at ──', 'color:#17A2B8;font-weight:600', parsed._logged_in_at)
      console.groupEnd()
    } else {
      // Stale or invalid session — clear and force re-login
      if (parsed) {
        console.warn('[POE AUTH] Stale/invalid session cleared', {
          had_id:            !!parsed.id,
          had_is_active:     parsed.is_active,
          had_logged_in_at:  parsed._logged_in_at,
          reason: !parsed._logged_in_at
            ? '_logged_in_at missing — session predates current code version'
            : !parsed.is_active
            ? 'is_active is false'
            : 'id missing',
        })
      }
      sessionStorage.removeItem(AUTH_KEY)
    }
  } catch {
    sessionStorage.removeItem(AUTH_KEY)
  }

  // ─── Capability post-mount wiring ──────────────────────────────────────────
  // Show the app-lock on cold-start IF (a) capability ON, (b) PIN enrolled,
  // AND (c) the user has a valid authenticated session (no point gating an
  // empty app over the login screen). Otherwise record active for the idle
  // timer so the resume-guard doesn't fire prematurely.
  try {
    if (isAuthenticated.value && isCapEnabled(CAPABILITY_KEYS.APP_LOCK)) {
      if (hasPin()) {
        appLockOpen.value = true
      } else {
        // Toggle is ON but no PIN — force the enrolment flow.
        appLockForceSetup.value = true
        appLockOpen.value = true
      }
    } else {
      recordActive()
    }
  } catch (e) { console.debug('[applock] cold-start check failed:', (e as any)?.message) }

  // Welcome tour runs once per tour version, and ONLY after the user is
  // authenticated — otherwise anchors (sidebar entries) aren't in the DOM.
  // Re-check after login succeeds via the watcher below.
  if (isAuthenticated.value) {
    try { maybeRunWelcomeTour() } catch {}
  }
})

// Fire the welcome tour the FIRST time isAuthenticated flips true after
// login. Gated by maybeRunWelcomeTour's own seen-version persistence, so
// it won't re-trigger on subsequent logins once shown.
watch(isAuthenticated, (on, was) => {
  if (on && !was) {
    setTimeout(() => { try { maybeRunWelcomeTour() } catch {} }, 600)
  }
})

// Passive activity tracker — any touch/scroll/keystroke counts as "active"
// so the idle-timer resets. Using passive listeners prevents jank.
const activityEvents = ['pointerdown', 'keydown', 'touchstart', 'scroll']
for (const ev of activityEvents) {
  try { window.addEventListener(ev, recordActive, { passive: true }) } catch {}
}

// ─── Login ────────────────────────────────────────────────────────────────────
async function submitLogin(): Promise<void> {
  loginError.value       = ''
  loginErrorDetail.value = ''

  const loginVal    = loginForm.value.login.trim()
  const passwordVal = loginForm.value.password

  if (!loginVal || !passwordVal) {
    loginError.value = 'Please enter your username and password.'
    return
  }

  loginLoading.value = true

  // try/finally guarantees loginLoading returns to false on EVERY exit path
  // (server-rejected credentials, network fail, offline-cache miss). Without
  // this the button stayed stuck spinning on HTTP 401, hiding the retry
  // affordance — exactly the "frozen in loading state" symptom reported.
  try {
    // ── PATH A: Online — try server first ──────────────────────────────────────
    if (navigator.onLine) {
      try {
        const ctrl = new AbortController()
        const tid  = window.setTimeout(() => ctrl.abort(), 10_000) // 10s hard timeout

        const res = await fetch(`${window.SERVER_URL}/auth/login`, {
          method:  'POST',
          headers: {
            'Content-Type':     'application/json',
            'Accept':           'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body:   JSON.stringify({ login: loginVal, password: passwordVal }),
          signal: ctrl.signal,
        })
        window.clearTimeout(tid)

        const body = await res.json() as {
          success: boolean
          message: string
          data?:   Record<string, any>
          error?:  Record<string, any>
        }

        // DEV: log the raw server response BEFORE any processing
        console.group(`%c[POE AUTH] Raw API response  HTTP ${res.status}`, `color:${res.ok ? '#0066CC' : '#DC3545'};font-weight:700;font-size:13px`)
        console.log('success:', body.success, '| message:', body.message)
        if (body.data)  console.table(body.data)
        if (body.error) console.error('error detail:', body.error)
        console.groupEnd()

        if (!body.success) {
          loginError.value = body.message ?? 'Login failed.'
          if (body.error) {
            const e = body.error
            const detail = e.reason
                        ?? e.hint
                        ?? (e.validation_errors ? Object.values(e.validation_errors).flat().join(' ') : null)
                        ?? e.message
                        ?? null
            loginErrorDetail.value = detail ? String(detail) : ''
          }
          // Clear the password so the user can type a fresh one without
          // selecting stale characters; username stays for convenience.
          loginForm.value.password = ''
          return
        }

        // ── ONLINE SUCCESS ──────────────────────────────────────────────────────
        const userData = body.data!
        const payload: Record<string, any> = {
          ...userData,
          _permissions:  derivePermissions(userData.role_key ?? null),
          _logged_in_at: new Date().toISOString(),
          _server_key:   currentServerKey(),   // scope this session to the live backend
        }

        // Persist AUTH_DATA — single source of truth for auth state in this session
        sessionStorage.setItem(AUTH_KEY, JSON.stringify(payload))

        // Cache credentials for future offline logins (SHA-256 hash — no plaintext)
        await cacheOfflineCredentials(loginVal, passwordVal, payload)

        // DEV: full dump so developers can see every field
        console.group('%c[POE AUTH] LOGIN OK (online) — AUTH_DATA written', 'color:#28A745;font-weight:700;font-size:13px')
        console.table({ id: userData.id, role_key: userData.role_key, full_name: userData.full_name, username: userData.username, poe_code: userData.poe_code ?? null, district_code: userData.district_code ?? null })
        if (!userData.assignment) {
          console.warn('%c⚠ ASSIGNMENT MISSING — user id=' + userData.id, 'color:#DC3545;font-weight:700', '\nRun: INSERT INTO user_assignments (user_id,country_code,...) VALUES (' + userData.id + ",...);" )
        } else {
          console.log('%c── assignment ──', 'color:#E83E8C;font-weight:600', userData.assignment)
        }
        console.log('%c── _permissions ──', 'color:#6F42C1;font-weight:600', payload._permissions)
        console.groupEnd()

        finishLogin(payload)
        return

      } catch (err: unknown) {
        // Network error or AbortError — fall through to offline path
        const isTimeout = err instanceof Error && err.name === 'AbortError'
        console.warn('%c[POE AUTH] Online login failed, trying offline cache', 'color:#F59E0B;font-weight:600', isTimeout ? '(timed out)' : err)
      }
    }

    // ── PATH B: Offline (or online-but-server-unreachable fallback) ─────────────
    // Only attempt if the device is actually offline or the server timed out.
    // This allows officers to keep working through temporary connectivity gaps.
    const offlinePayload = await tryOfflineLogin(loginVal, passwordVal)

    if (offlinePayload) {
      // Refresh the timestamp so session validation passes
      const payload = {
        ...offlinePayload,
        _logged_in_at:      new Date().toISOString(),
        _server_key:        currentServerKey(),   // scope the restored session to the live backend
        _offline_login:     true,   // flag — views can detect and show an offline banner
        // Re-derive permissions in case the role_key was updated since last cache
        _permissions:       derivePermissions(offlinePayload.role_key ?? null),
      }
      // Write to sessionStorage — same shape as online login, fully compatible with
      // every view that reads AUTH_DATA. No view changes needed.
      sessionStorage.setItem(AUTH_KEY, JSON.stringify(payload))

      console.group('%c[POE AUTH] LOGIN OK (offline cache)', 'color:#10B981;font-weight:700;font-size:13px')
      console.log('%c── user ──', 'color:#10B981;font-weight:600', { id: payload.id, role_key: payload.role_key, full_name: payload.full_name })
      console.log('%c── offline_login flag ──', 'color:#F59E0B;font-weight:600', true)
      console.groupEnd()

      finishLogin(payload)
      return
    }

    // ── PATH C: Nothing worked ──────────────────────────────────────────────────
    if (!navigator.onLine) {
      loginError.value       = 'You are offline and no cached credentials were found.'
      loginErrorDetail.value = 'Connect to the internet and sign in once to enable offline login on this device.'
    } else {
      loginError.value       = 'Unable to reach the server.'
      loginErrorDetail.value = 'Check your network connection and try again.'
    }
  } finally {
    loginLoading.value = false
  }
}

/** Apply a successful auth payload — same steps whether online or offline. */
function finishLogin(payload: Record<string, any>): void {
  authData.value = payload

  loginForm.value        = { login: '', password: '' }
  loginError.value       = ''
  loginErrorDetail.value = ''
  showPw.value           = false
  loginLoading.value     = false

  // Post-login routing: send the user to the premium AI Welcome briefing
  // first, unless they ticked "Don't show again" on a previous session
  // (keyed per user id on this device). Menu still exposes /welcome.
  let skipWelcome = false
  try {
    const uid = payload?.id ?? 'anon'
    skipWelcome = localStorage.getItem(`welcome.skip_for_user_${uid}`) === '1'
  } catch { /* localStorage unavailable — fall through to welcome */ }
  void router.replace(skipWelcome ? '/home' : '/welcome')
}
</script>

<!-- ══════════════════════════════════════════════════════════════════════════
  GLOBAL — SSOT design tokens + light-mode enforcement
  color-scheme: light forced on all Ionic elements.
  No dark backgrounds defined anywhere in this file.
══════════════════════════════════════════════════════════════════════════ -->
<style>
/* ═══════════════════════════════════════════════════════════════════════
   DESIGN SYSTEM — SSOT TOKENS
   Light theme enforced globally. Color scheme preserved exactly.
   No dark mode anywhere.
═══════════════════════════════════════════════════════════════════════ */
ion-app, ion-menu, ion-page, ion-content,
ion-header, ion-toolbar, ion-footer,
ion-item, ion-list, ion-card { color-scheme: light !important; }

/* ═══════════════════════════════════════════════════════════════════════
   GLOBAL TOP-BAR SAFE-AREA ENFORCEMENT (audit F-007/F-011)
   Defensive: every IonHeader's first IonToolbar must reserve the
   status-bar / notch / cutout inset, even if a per-view stylesheet
   overrides Ionic's defaults. Padding-top compounds the inset on the
   --padding-top set inside the toolbar body, so we use min-height to
   guarantee the chrome itself is never less than (default toolbar
   height + inset). max(...) keeps the visual height stable on devices
   without any inset.
═══════════════════════════════════════════════════════════════════════ */
ion-header ion-toolbar:first-of-type {
  padding-top: var(--ion-safe-area-top, env(safe-area-inset-top, 0px)) !important;
}
ion-header.header-translucent ion-toolbar:first-of-type,
ion-header[translucent] ion-toolbar:first-of-type {
  /* When the header is translucent, content can scroll underneath it.
     The padding above keeps the bar's title/buttons clear of the clock. */
  padding-top: var(--ion-safe-area-top, env(safe-area-inset-top, 0px)) !important;
}
/* .lm-safe-top / .lm-safe-bottom utility shims for the side-nav header are
   defined further down (see search-for class) and already account for the
   inset; we don't redeclare them here. */

:root {
  /* ── Ionic overrides — always light ── */
  --ion-background-color:     #FFFFFF;
  --ion-background-color-rgb: 255,255,255;
  --ion-text-color:           #1A1A1A;
  --ion-text-color-rgb:       26,26,26;
  --ion-border-color:         #E0E0E0;
  --ion-item-background:      #FFFFFF;
  --ion-toolbar-background:   #FFFFFF;
  --ion-tab-bar-background:   #FFFFFF;
  --ion-card-background:      #FFFFFF;

  /* ── Brand primary ── */
  --ion-color-primary:          #0066CC;
  --ion-color-primary-rgb:      0,102,204;
  --ion-color-primary-contrast: #FFFFFF;
  --ion-color-primary-shade:    #005AB3;
  --ion-color-primary-tint:     #EBF3FF;

  /* ── Status palette ── */
  --ion-color-success:       #28A745;
  --ion-color-success-tint:  #E8F5E9;
  --ion-color-warning:       #FFC107;
  --ion-color-warning-tint:  #FFF8E1;
  --ion-color-warning-shade: #E6AD06;
  --ion-color-danger:        #DC3545;
  --ion-color-danger-tint:   #FFEBEE;
  --ion-color-danger-shade:  #C82333;
  --ion-color-info:          #17A2B8;

  /* ── Sync state colours ── */
  --clr-synced:  #28A745;
  --clr-queued:  #E6AD06;
  --clr-syncing: #2196F3;
  --clr-failed:  #DC3545;
  --clr-offline: #9E9E9E;

  /* ── Spacing scale ── */
  --sp-xs: 4px;
  --sp-sm: 8px;
  --sp-md: 16px;
  --sp-lg: 24px;
  --sp-xl: 32px;

  /* ── Radius ── */
  --r-sm:   4px;
  --r-md:   8px;
  --r-lg:   12px;
  --r-xl:   16px;
  --r-full: 9999px;

  /* ── Menu surface tokens — POE SENTINEL DARK (2026-05-06 v3) ──
     Re-anchored to the EXACT home-page banner gradient stops:
       #06111E (deep abyss) → #0D253F (mid-navy) → #0F3460 (sea-navy)
     Same gradient as HomePage.vue's `.hp-hdr-bg` so the sidebar visually
     belongs to the same surface family. Teal #00B4A6 + amber #FBBF24
     stay as accents (matching the brand spinner + warmth pop). */
  --mn-bg:          #06111E;            /* exact: home banner stop 0 */
  --mn-bg2:         #0F3460;            /* exact: home banner stop 100 */
  --mn-border:      rgba(255,255,255,0.07);
  --mn-accent:      #00B4A6;            /* teal — splash spinner / brand */
  --mn-accent-2:    #FBBF24;            /* warm amber for warnings only */
  --mn-accent-bg:   rgba(0,180,166,0.14);
  --mn-text:        #F5F7FA;            /* near-white, matches app bg */
  --mn-sub:         rgba(245,247,250,0.74);
  --mn-micro:       rgba(245,247,250,0.50);
  --mn-item-active: rgba(0,180,166,0.18);
  --mn-item-hover:  rgba(255,255,255,0.06);
  --mn-gt-color:    rgba(245,247,250,0.50);
  --mn-width:       min(86vw, 304px);
  --mn-shadow:      0 24px 48px rgba(11,37,69,0.55), 0 4px 12px rgba(0,180,166,0.20);

  /* ── Identity panel (dark, on-brand) ── */
  --ip-bg:        rgba(0,180,166,0.08);
  --ip-bg2:       rgba(0,180,166,0.13);
  --ip-border:    rgba(0,180,166,0.26);
  --ip-av-ring:   linear-gradient(135deg, #00B4A6 0%, #17A2B8 50%, #FBBF24 100%);
}

*, *::before, *::after { box-sizing: border-box; }

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration:  .01ms !important;
    transition-duration: .01ms !important;
  }
}

/* ═══════════════════════════════════════════════════════════════════
   LOGIN OVERLAY — Premium light theme, native Android grade
   Class namespace: lm-*
   Design: Material You, WHO/IHR authority, offline-first
═══════════════════════════════════════════════════════════════════ */

.lm-overlay {
  position: fixed !important;
  inset: 0 !important;
  z-index: 99999 !important;
  width: 100vw !important;
  height: 100dvh !important;
  background: #F0F4FF;
  overflow: hidden;
  pointer-events: all;
  display: flex;
  flex-direction: column;
  padding-top: env(safe-area-inset-top, 0px);
  padding-bottom: env(safe-area-inset-bottom, 0px);
}

/* ── Decorative top band with Uganda-blue gradient ── */
.lm-top-band {
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 52%;
  background: linear-gradient(170deg, #1E3A8A 0%, #1D4ED8 45%, #0EA5E9 100%);
  overflow: hidden;
  z-index: 0;
}
.lm-band-wave {
  position: absolute;
  bottom: -2px; left: -5%; right: -5%;
  height: 80px;
  background: #F0F4FF;
  border-radius: 100% 100% 0 0;
}

/* ── Scroll container ── */
.lm-scroll {
  position: relative;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
  height: 100%;
  overflow-y: auto;
  -webkit-overflow-scrolling: touch;
  padding: 0 20px;
  box-sizing: border-box;
}

/* Safe-area spacers */
.lm-st  { flex-shrink: 0; height: max(env(safe-area-inset-top, 0px), 12px); width: 100%; }
.lm-sb  { flex-shrink: 0; height: max(env(safe-area-inset-bottom, 0px), 8px); width: 100%; }

/* ── Hero ── */
.lm-hero {
  width: 100%;
  max-width: 400px;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding-top: clamp(16px, 3vh, 32px);
  padding-bottom: clamp(20px, 3.5vh, 36px);
  animation: lm-rise .5s cubic-bezier(.16,1,.3,1) both;
}
@keyframes lm-rise {
  from { opacity:0; transform:translateY(16px); }
  to   { opacity:1; transform:translateY(0); }
}

/* Logo */
.lm-logo-wrap {
  position: relative;
  width: 80px; height: 80px;
  margin: 0 auto clamp(14px, 2.2vh, 22px);
  display: flex; align-items: center; justify-content: center;
}
.lm-logo-ring {
  position: absolute;
  border-radius: 50%;
  border: 1.5px solid rgba(255,255,255,.45);
  animation: lm-ring 2.8s cubic-bezier(.215,.61,.355,1) infinite;
}
.lm-logo-ring--1 { inset: -8px; }
.lm-logo-ring--2 { inset: -16px; border-color: rgba(255,255,255,.2); animation-delay: .7s; }
@keyframes lm-ring {
  0%   { transform: scale(1);   opacity: .7; }
  100% { transform: scale(1.4); opacity: 0;  }
}
.lm-logo-icon {
  width: 80px; height: 80px;
  border-radius: 50%;
  background: #fff;
  border: 2px solid rgba(255,255,255,.9);
  box-shadow:
    0 8px 32px rgba(30,58,138,.25),
    0 2px 8px rgba(30,58,138,.15),
    inset 0 1px 0 rgba(255,255,255,.8);
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
}
.lm-logo-icon img {
  width: 110%; height: 110%; object-fit: cover; display: block;
  border-radius: 50%;
}

/* Text */
.lm-appname {
  font-size: clamp(24px, 5.6vw, 30px);
  font-weight: 900; letter-spacing: -.6px; line-height: 1.1;
  color: #FFFFFF; margin: 0 0 14px;
  text-shadow: 0 2px 8px rgba(0,0,0,.15);
}
.lm-accent { color: #BAE6FD; }

/* Compliance chips */
.lm-chips {
  display: flex; align-items: center;
  gap: 6px; flex-wrap: wrap; justify-content: center;
}
.lm-chip {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 10px; font-weight: 700; letter-spacing: .8px;
  text-transform: uppercase;
  padding: 4px 10px; border-radius: 20px;
  background: rgba(255,255,255,.18);
  border: 1px solid rgba(255,255,255,.28);
  color: rgba(255,255,255,.9);
  backdrop-filter: blur(4px);
}
.lm-chip-dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: #34D399; flex-shrink: 0;
}
.lm-chip-dot--blue { background: #7DD3FC; }
.lm-chip--slate { background: rgba(255,255,255,.12); border-color: rgba(255,255,255,.18); }

/* ── Offline banner ── */
.lm-offline {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; border-radius: 12px;
  background: rgba(16,185,129,.12);
  border: 1px solid rgba(16,185,129,.3);
  color: #065F46;
  font-size: 12px; font-weight: 600;
  width: 100%; max-width: 400px;
  margin-bottom: 10px;
  animation: lm-rise .4s both;
}

/* ── Form surface — white panel on the light base ── */
.lm-surface {
  width: 100%;
  max-width: 400px;
  background: #FFFFFF;
  border-radius: 24px;
  padding: clamp(20px, 3.5vh, 28px) clamp(18px, 5vw, 24px);
  box-shadow:
    0 1px 3px rgba(30,58,138,.06),
    0 8px 24px rgba(30,58,138,.1),
    0 20px 48px rgba(30,58,138,.07);
  animation: lm-rise .5s .08s cubic-bezier(.16,1,.3,1) both;
  margin-bottom: 12px;
}

.lm-surface-hdr { margin-bottom: 18px; }
.lm-form-title {
  font-size: 20px; font-weight: 800; color: #0F172A;
  letter-spacing: -.4px; margin: 0;
}

/* ── Error ── */
.lm-error {
  display: flex; align-items: flex-start; gap: 9px;
  padding: 10px 13px; border-radius: 12px;
  background: #FEF2F2; border: 1px solid #FECACA;
  color: #B91C1C;
  margin-bottom: 14px;
  animation: lm-shake .35s cubic-bezier(.36,.07,.19,.97);
}
@keyframes lm-shake {
  0%,100%{ transform:translateX(0); }
  25%    { transform:translateX(-5px); }
  75%    { transform:translateX(5px); }
}
.lm-error-msg    { font-size: 12.5px; font-weight: 700; line-height: 1.3; }
.lm-error-detail { font-size: 11px; margin-top: 2px; color: #7F1D1D; line-height: 1.4; }

/* ── Input field ── */
.lm-field-wrap { position: relative; }

/* Floating label */
.lm-label {
  display: block;
  font-size: 12px; font-weight: 600;
  letter-spacing: .5px; text-transform: uppercase;
  color: #94A3B8;
  margin-bottom: 6px;
  transition: color .18s;
}
.lm-label.active { color: #1D4ED8; }

.lm-input-row {
  display: flex; align-items: center;
  background: #F8FAFC;
  border: 1.5px solid #E2E8F0;
  border-radius: 14px;
  padding: 0 4px 0 14px;
  min-height: 54px;
  transition: border-color .18s, box-shadow .18s, background .18s;
}
.lm-input-row.focus {
  border-color: #2563EB;
  background: #FFFFFF;
  box-shadow: 0 0 0 3px rgba(37,99,235,.12);
}
.lm-input-row.filled { border-color: #94A3B8; background: #FFFFFF; }
.lm-input-row.err    { border-color: #EF4444 !important; box-shadow: 0 0 0 3px rgba(239,68,68,.1) !important; }

.lm-input-ic {
  flex-shrink: 0; margin-right: 10px;
  color: #94A3B8;
  transition: color .18s;
}
.lm-input-row.focus  .lm-input-ic,
.lm-input-row.filled .lm-input-ic { color: #2563EB; }

/* IonInput override — make it look like a native input */
.lm-ion {
  flex: 1;
  --background: transparent;
  --border-width: 0;
  --padding-start: 0; --padding-end: 0;
  --padding-top: 14px; --padding-bottom: 14px;
  --placeholder-color: #CBD5E1;
  --color: #0F172A;
  color: #0F172A;
  font-size: 15px; font-weight: 500;
  caret-color: #2563EB;
}

/* Eye toggle */
.lm-eye-btn {
  width: 44px; height: 44px; border-radius: 10px;
  border: none; background: transparent; color: #94A3B8;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; flex-shrink: 0;
  transition: color .15s, background .15s;
  -webkit-tap-highlight-color: transparent;
}
.lm-eye-btn:active { background: rgba(15,23,42,.06); color: #475569; }

/* ── Sign-in button ── */
.lm-btn {
  display: flex; align-items: center; justify-content: center;
  width: 100%;
  margin-top: 20px;
  padding: 0;
  height: 54px;
  border-radius: 14px;
  border: none;
  background: linear-gradient(135deg, #1D4ED8 0%, #2563EB 50%, #0EA5E9 100%);
  color: #FFFFFF;
  font-size: 15px; font-weight: 700; letter-spacing: .3px;
  cursor: pointer;
  box-shadow: 0 4px 16px rgba(37,99,235,.35), 0 1px 4px rgba(37,99,235,.2);
  transition: transform .1s, box-shadow .15s, opacity .15s;
  -webkit-tap-highlight-color: transparent;
  position: relative; overflow: hidden;
}
.lm-btn::after {
  content: '';
  position: absolute; inset: 0;
  background: linear-gradient(to bottom, rgba(255,255,255,.12), transparent);
  pointer-events: none;
}
.lm-btn:not(:disabled):active {
  transform: scale(.98);
  box-shadow: 0 2px 8px rgba(37,99,235,.3);
}
.lm-btn:disabled { opacity: .5; cursor: not-allowed; }
.lm-btn--loading { opacity: .85; }

.lm-btn-inner {
  display: flex; align-items: center; gap: 8px;
}
.lm-btn-dots {
  display: flex; align-items: center; gap: 5px;
}
.lm-btn-dots span {
  width: 7px; height: 7px; border-radius: 50%;
  background: rgba(255,255,255,.9);
  animation: lm-dot-pulse 1.2s ease-in-out infinite;
}
.lm-btn-dots span:nth-child(2) { animation-delay: .2s; }
.lm-btn-dots span:nth-child(3) { animation-delay: .4s; }
@keyframes lm-dot-pulse {
  0%,80%,100% { transform:scale(.6); opacity:.5; }
  40%          { transform:scale(1);  opacity:1; }
}

/* Footer */
.lm-footer {
  font-size: 10px; font-weight: 600; letter-spacing: .8px;
  text-transform: uppercase;
  color: #94A3B8; text-align: center;
  padding: 4px 0 8px;
}

@media (min-height: 700px) {
  .lm-hero { padding-top: clamp(20px, 4vh, 40px); }
}

/* ═══════════════════════════════════════════════════════════════════════
   ✦ GLOBAL ANDROID SAFE-AREA OVERFLOW FIX — 2026-05-06 ✦
   Belt-and-braces: even with edge-to-edge opt-out + StatusBar.overlaysWebView
   = false, some views render fixed-position content (toolbars, FABs, nav
   buttons) at top:0 / bottom:0 and end up under the system clock or the
   3-button nav. This block injects safe-area padding for every page so
   nothing reaches the bars unless the view explicitly opts out via
   `.no-safe-area`. Keep this rule UNSCOPED so it applies to all views.
═══════════════════════════════════════════════════════════════════════ */
:root {
  --safe-top:    env(safe-area-inset-top,    0px);
  --safe-bottom: env(safe-area-inset-bottom, 0px);
  --safe-left:   env(safe-area-inset-left,   0px);
  --safe-right:  env(safe-area-inset-right,  0px);
}

/* Ion-content already handles padding via --padding-top/--padding-bottom.
   Double-apply via safe-area is the global fallback for ANY content
   placed outside an ion-content (popovers, modals, custom layers). */
ion-content {
  --padding-top:    max(var(--safe-top, 0px), 0px);
  --padding-bottom: max(var(--safe-bottom, 0px), 0px);
}

/* Custom fixed bars that sit OUTSIDE ion-header / ion-footer must respect
   the inset themselves. Add `.has-safe-bottom` or `.has-safe-top` on any
   fixed/absolute element in a view to opt in. */
.has-safe-top    { padding-top:    max(var(--safe-top,    0px), 0px) !important; }
.has-safe-bottom { padding-bottom: max(var(--safe-bottom, 0px), 0px) !important; }
.has-safe-left   { padding-left:   max(var(--safe-left,   0px), 0px) !important; }
.has-safe-right  { padding-right:  max(var(--safe-right,  0px), 0px) !important; }

/* Catch-all for views that paint a custom toolbar/header div instead of
   using ion-toolbar — auto-pad the first child of ion-page so the system
   clock never overlaps. Explicit opt-out via class `no-auto-safe`. */
.ion-page:not(.no-auto-safe) > :not(ion-header):not(ion-footer):not(.no-safe-area):first-child {
  padding-top: max(var(--safe-top, 0px), 0px);
}

/* Bottom-fixed FABs / sticky CTAs that anchor to bottom: 0 must lift
   above the system nav. Apply globally to common patterns; views can
   override per-element. */
[data-sticky-bottom],
.sticky-cta,
.sticky-action-bar,
.fab-bottom {
  padding-bottom: max(var(--safe-bottom, 0px), 0px);
  padding-bottom: calc(var(--existing-padding, 0px) + max(var(--safe-bottom, 0px), 0px));
}

/* Modal / sheet overlays — bottom margin so the close handle isn't
   under the system 3-button nav. */
ion-modal::part(content),
ion-action-sheet::part(content) {
  padding-bottom: max(var(--safe-bottom, 0px), 0px);
}

/* 2026-05-06 v5: Google Translate bar CSS lives in index.html (outside
   Vue's scoped style boundary so it can target the float button +
   panel that Vue does not own). */

/* ═══════════════════════════════════════════════════════════════════════
   ✦ PWA INSTALL BANNERS — iOS + Android — 2026-05-06 ✦
═══════════════════════════════════════════════════════════════════════ */
.poe-ios-install {
  position: fixed; left: 12px; right: 12px;
  bottom: calc(max(var(--safe-bottom, 0px), 0px) + 12px);
  z-index: 99997;
  pointer-events: none;
  display: flex; justify-content: center;
  animation: poe-ios-rise 0.45s cubic-bezier(0.22, 1, 0.36, 1) both;
}
.poe-ios-install-card {
  pointer-events: auto;
  display: flex; align-items: center; gap: 12px;
  width: 100%; max-width: 480px;
  padding: 12px 14px;
  border-radius: 16px;
  background: linear-gradient(135deg, #0F172A 0%, #1E40AF 100%);
  color: #F8FAFC;
  box-shadow: 0 18px 36px rgba(0,0,0,0.32), 0 2px 8px rgba(30,64,175,0.45);
  border: 1px solid rgba(255,255,255,0.10);
  backdrop-filter: blur(12px);
  -webkit-backdrop-filter: blur(12px);
}
.poe-ios-install-icon {
  flex-shrink: 0;
  width: 42px; height: 42px;
  border-radius: 12px;
  background: linear-gradient(135deg, rgba(77,168,255,0.35), rgba(179,146,240,0.35));
  border: 1px solid rgba(255,255,255,0.18);
  display: inline-flex; align-items: center; justify-content: center;
}
.poe-ios-install-body { flex: 1 1 auto; min-width: 0; }
.poe-ios-install-title { font-size: 13px; font-weight: 800; letter-spacing: -0.1px; }
.poe-ios-install-desc {
  font-size: 11.5px; margin-top: 2px; line-height: 1.45;
  color: rgba(248,250,252,0.86);
}
.poe-ios-install-x {
  flex-shrink: 0;
  width: 30px; height: 30px;
  border-radius: 50%;
  background: rgba(255,255,255,0.10);
  border: 1px solid rgba(255,255,255,0.16);
  color: #F8FAFC; font-size: 18px; line-height: 1;
  display: inline-flex; align-items: center; justify-content: center;
  cursor: pointer;
}
.poe-ios-install-x:active { background: rgba(255,255,255,0.20); }
@keyframes poe-ios-rise {
  from { opacity: 0; transform: translateY(28px) scale(0.96); }
  to   { opacity: 1; transform: translateY(0) scale(1); }
}

.poe-android-install {
  position: fixed;
  bottom: calc(max(var(--safe-bottom, 0px), 0px) + 12px);
  right: 12px;
  z-index: 99997;
  display: inline-flex; align-items: center; gap: 6px;
  padding: 10px 14px;
  border-radius: 999px;
  background: linear-gradient(135deg, #4DA8FF 0%, #1E40AF 100%);
  color: #fff;
  font-size: 13px; font-weight: 700; letter-spacing: 0.2px;
  border: 1px solid rgba(255,255,255,0.18);
  box-shadow: 0 12px 28px rgba(30,64,175,0.42);
  cursor: pointer;
  animation: poe-android-bounce 0.4s cubic-bezier(0.22, 1, 0.36, 1) both;
}
.poe-android-install:active { transform: scale(0.97); }
@keyframes poe-android-bounce {
  from { opacity: 0; transform: translateY(20px); }
  to   { opacity: 1; transform: translateY(0); }
}
</style>

<style scoped>

/* ═══════════════════════════════════════════════════════════════════════
   SIDE MENU — PREMIUM LIGHT DESIGN
   All class names unchanged. Pure CSS upgrade.
═══════════════════════════════════════════════════════════════════════ */

ion-menu { --width: var(--mn-width); }

.menu-content {
  --background:    var(--mn-bg);
  --padding-start: 0;
  --padding-end:   0;
  /* Reserve the status-bar inset at the menu surface itself.
     Background: targetSdkVersion=36 (Android 15+) enables edge-to-edge by
     default — the WebView extends behind the system bars regardless of the
     Capacitor StatusBar `overlaysWebView:false` setting. Without this
     padding the IonMenu drawer would slide UNDER the clock/battery icons
     and the brand block would be partially obscured. env(safe-area-inset-top)
     is 0 on devices/modes where the system reserves the inset for us, so
     this is also safe on older Android, iOS, and the web. */
  --padding-top:   max(env(safe-area-inset-top, 0px), 0px);
  --padding-bottom: max(env(safe-area-inset-bottom, 0px), 0px);
}

/* ═══════════════════════════════════════════════════════════
   IDENTITY PANEL — premium frosted header
═══════════════════════════════════════════════════════════ */
.ip {
  position:         relative;
  background:       var(--ip-bg);
  /* Status-bar inset is reserved one level up on .menu-content's
     --padding-top (see comment there). Don't double-pad here. */
  overflow:         hidden;
}

/* Gradient accent bar — thicker, more vivid */
.ip__bar {
  height:     4px;
  background: linear-gradient(90deg, #0066CC 0%, #1A75D1 40%, #17A2B8 100%);
  box-shadow: 0 1px 8px rgba(0,102,204,.35);
}

/* Subtle diagonal shine overlay */
.ip::after {
  content:    '';
  position:   absolute;
  top:        0; left:0; right:0;
  height:     100%;
  background: linear-gradient(135deg, rgba(255,255,255,.55) 0%, transparent 60%);
  pointer-events: none;
}

.ip__body {
  display:     flex;
  align-items: center;
  gap:         12px;
  padding:     16px 16px 12px;
  position:    relative;
  z-index:     1;
}

/* ── Avatar ── */
.ip__av-wrap {
  position:    relative;
  width:       52px;
  height:      52px;
  flex-shrink: 0;
}

/* Gradient ring around avatar */
.ip__av-wrap::before {
  content:       '';
  position:      absolute;
  inset:         -3px;
  border-radius: 50%;
  background:    var(--ip-av-ring);
  padding:       2px;
  mask:          linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
  mask-composite:exclude;
  -webkit-mask:  linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
  -webkit-mask-composite: xor;
}

.ip__av {
  width:           52px;
  height:          52px;
  border-radius:   50%;
  background:      linear-gradient(135deg, #0066CC, #1A75D1);
  display:         flex;
  align-items:     center;
  justify-content: center;
  box-shadow:      0 4px 12px rgba(0,102,204,.3), 0 0 0 3px #fff;
}

.ip__initials {
  font-size:      17px;
  font-weight:    800;
  color:          #fff;
  letter-spacing: .5px;
  user-select:    none;
  line-height:    1;
}

/* Status dot — sits outside the ring */
.ip__dot {
  position:      absolute;
  bottom:        2px;
  right:         2px;
  width:         13px;
  height:        13px;
  border-radius: 50%;
  border:        2.5px solid #fff;
  box-shadow:    0 1px 4px rgba(0,0,0,.2);
  z-index:       2;
}
.ip__dot--synced   { background: var(--clr-synced);  }
.ip__dot--unsynced { background: var(--clr-queued);  }
.ip__dot--syncing  { background: var(--clr-syncing); animation: pulse-dot 1.4s ease-in-out infinite; }
.ip__dot--failed   { background: var(--clr-failed);  }
.ip__dot--offline  { background: var(--clr-offline); }
@keyframes pulse-dot {
  0%,100% { box-shadow: 0 1px 4px rgba(0,0,0,.2), 0 0 0 0   rgba(33,150,243,.5); }
  50%     { box-shadow: 0 1px 4px rgba(0,0,0,.2), 0 0 0 5px rgba(33,150,243,0);  }
}

/* ── User info ── */
.ip__info { flex:1; min-width:0; position:relative; z-index:1; }

.ip__name {
  margin:         0 0 4px;
  font-size:      14px;
  font-weight:    700;
  color:          var(--mn-text);
  white-space:    nowrap;
  overflow:       hidden;
  text-overflow:  ellipsis;
  letter-spacing: -.2px;
}

.ip__role {
  display:        inline-flex;
  align-items:    center;
  margin-bottom:  4px;
  padding:        3px 9px;
  border-radius:  var(--r-full);
  font-size:      9px;
  font-weight:    800;
  letter-spacing: .8px;
  text-transform: uppercase;
  color:          #fff;
  box-shadow:     0 2px 6px rgba(0,0,0,.2);
}
.ip__role--poe-primary         { background: linear-gradient(135deg,#0066CC,#1A75D1); }
.ip__role--poe-secondary       { background: linear-gradient(135deg,#17A2B8,#0d8fa4); }
.ip__role--poe-data-officer    { background: linear-gradient(135deg,#6F42C1,#5a32a3); }
.ip__role--poe-admin           { background: linear-gradient(135deg,#E83E8C,#d4286e); }
.ip__role--district-supervisor { background: linear-gradient(135deg,#28A745,#1e8435); }
.ip__role--pheoc-officer       { background: linear-gradient(135deg,#FD7E14,#e56d05); }
.ip__role--national-admin      { background: linear-gradient(135deg,#DC3545,#c82333); }
.ip__role--screener            { background: linear-gradient(135deg,#0066CC,#1A75D1); }

.ip__scope {
  margin:         0;
  font-size:      9px;
  font-weight:    600;
  color:          var(--mn-sub);
  text-transform: uppercase;
  letter-spacing: .6px;
  font-family:    'Courier New', monospace;
}

/* ── Language switcher slot — placed below the sync strip in the
   identity panel. Inherits the same border-top and padding rhythm. */
.ip__lang {
  padding: 8px 16px 12px;
  border-top: 1px solid var(--ip-border);
  display: flex; justify-content: flex-start;
}

/* ── Sync strip ── */
.ip__sync {
  display:           flex;
  align-items:       center;
  gap:               8px;
  width:             100%;
  padding:           9px 16px;
  border:            none;
  border-top:        1px solid var(--ip-border);
  background:        rgba(235,243,255,.5);
  cursor:            pointer;
  font-family:       inherit;
  position:          relative;
  z-index:           1;
  -webkit-tap-highlight-color: transparent;
  transition:        background .15s;
}
.ip__sync:hover  { background: rgba(0,102,204,.05); }
.ip__sync:active { background: rgba(0,102,204,.1); }

.ip__sync-icon  { width:14px; height:14px; flex-shrink:0; }
.ip__sync-label { font-size:10px; font-weight:800; letter-spacing:.6px; text-transform:uppercase; flex:1; }

.ip__sync-ct {
  font-size:     9px;
  font-weight:   700;
  padding:       2px 8px;
  border-radius: var(--r-full);
  background:    linear-gradient(135deg,#FFF8E1,#FFF3CD);
  color:         #7A5000;
  border:        1px solid rgba(230,173,6,.4);
  box-shadow:    0 1px 3px rgba(230,173,6,.2);
}
.ip__sync--synced   .ip__sync-icon, .ip__sync--synced   .ip__sync-label { color: var(--clr-synced);  }
.ip__sync--unsynced .ip__sync-icon, .ip__sync--unsynced .ip__sync-label { color: #9A6A00; }
.ip__sync--syncing  .ip__sync-icon, .ip__sync--syncing  .ip__sync-label { color: var(--clr-syncing); }
.ip__sync--failed   .ip__sync-icon, .ip__sync--failed   .ip__sync-label { color: var(--clr-failed);  }
.ip__sync--offline  .ip__sync-icon, .ip__sync--offline  .ip__sync-label { color: var(--clr-offline); }

/* ═══════════════════════════════════════════════════════════
   NAVIGATION — premium items
═══════════════════════════════════════════════════════════ */
.mn {
  padding:    6px 0 32px;
  background: var(--mn-bg);
}

.mn__group { padding: 2px 0; }

/* Hairline separator between groups — refined */
.mn__group + .mn__group {
  border-top:  1px solid var(--mn-border);
  margin-top:  2px;
  padding-top: 4px;
}

/* Group section title */
.mn__gt {
  margin:         0;
  padding:        14px 16px 3px;
  font-size:      8.5px;
  font-weight:    800;
  letter-spacing: 1.8px;
  text-transform: uppercase;
  color:          var(--mn-gt-color);
  font-family:    'Courier New', monospace;
  user-select:    none;
  display:        flex;
  align-items:    center;
  gap:            6px;
}
/* Decorative line after section title */
.mn__gt::after {
  content:    '';
  flex:       1;
  height:     1px;
  background: linear-gradient(90deg, var(--mn-border), transparent);
  margin-left:4px;
}

/* ── Navigation item ── */
.mn__item {
  display:         flex;
  align-items:     center;
  gap:             11px;
  width:           100%;
  padding:         0 12px 0 14px;
  min-height:      50px;
  border:          none;
  border-left:     3px solid transparent;
  background:      transparent;
  cursor:          pointer;
  text-align:      left;
  position:        relative;
  -webkit-tap-highlight-color: transparent;
  transition:      background .14s ease, border-color .14s ease, transform .1s ease;
}

.mn__item:hover {
  background:    var(--mn-item-hover);
  border-left-color: rgba(0,102,204,.2);
}

.mn__item:active { transform: scaleX(.99); }

/* Active state — premium gradient treatment */
.mn__item--active {
  background:        linear-gradient(90deg, rgba(0,102,204,.10) 0%, rgba(0,102,204,.04) 100%);
  border-left-color: var(--mn-accent);
}
.mn__item--active::after {
  content:    '';
  position:   absolute;
  right:      0;
  top:        20%;
  bottom:     20%;
  width:      3px;
  background: linear-gradient(180deg, rgba(0,102,204,.15), rgba(0,102,204,.05));
  border-radius: 2px 0 0 2px;
}
.mn__item--active .mn__icon  { color: var(--mn-accent); }
.mn__item--active .mn__label { color: var(--mn-accent); font-weight: 700; }
.mn__item--active .mn__sub   { color: rgba(0,102,204,.6); }

/* Danger item */
.mn__item--danger .mn__icon,
.mn__item--danger .mn__label { color: var(--ion-color-danger); }
.mn__item--danger .mn__sub   { color: rgba(220,53,69,.6); }
.mn__item--danger:hover      { background: var(--ion-color-danger-tint); border-left-color: var(--ion-color-danger); }

/* ── Icon ── */
.mn__icon {
  width:      20px;
  height:     20px;
  flex-shrink:0;
  color:      var(--mn-sub);
  transition: color .14s, transform .2s;
}
.mn__item:hover .mn__icon    { transform: scale(1.06); }
.mn__item--active .mn__icon  { transform: scale(1.08); }

/* Icon accent colours per section */
.mn__icon--create    { color: #0066CC; }
.mn__icon--secondary { color: #17A2B8; }
.mn__icon--alert     { color: #DC3545; }
.mn__icon--system    { color: #6F42C1; }

/* ── Text block ── */
.mn__text  { flex:1; min-width:0; display:flex; flex-direction:column; gap:1px; }
.mn__label {
  font-size:      13.5px;
  font-weight:    500;
  color:          var(--mn-text);
  line-height:    1.25;
  transition:     color .14s;
  letter-spacing: -.1px;
}
.mn__sub {
  font-size:      10px;
  font-weight:    400;
  color:          var(--mn-sub);
  letter-spacing: .05px;
  white-space:    nowrap;
  overflow:       hidden;
  text-overflow:  ellipsis;
}

/* ── Badges ── */
.mn__badge {
  flex-shrink:     0;
  min-width:       22px;
  height:          20px;
  padding:         0 6px;
  border-radius:   var(--r-full);
  font-size:       10px;
  font-weight:     800;
  display:         inline-flex;
  align-items:     center;
  justify-content: center;
  letter-spacing:  .2px;
  box-shadow:      0 2px 6px rgba(0,0,0,.12);
}
.mn__badge--danger  { background: var(--ion-color-danger);  color: #fff; }
.mn__badge--warning { background: var(--ion-color-warning-tint); color: #7A5000; border:1px solid var(--ion-color-warning-shade); }
.mn__badge--primary { background: var(--ion-color-primary); color: #fff; }
.mn__badge--sync    {
  background:   linear-gradient(135deg, #EBF3FF, #DCE9FF);
  color:        var(--mn-accent);
  border:       1px solid rgba(0,102,204,.25);
  box-shadow:   0 1px 4px rgba(0,102,204,.1);
}

/* ── Tags ── */
.mn__tag {
  flex-shrink:    0;
  font-size:      8px;
  font-weight:    800;
  letter-spacing: .5px;
  text-transform: uppercase;
  padding:        3px 7px;
  border-radius:  var(--r-full);
  font-family:    'Courier New', monospace;
}
.mn__tag--speed {
  background:  linear-gradient(135deg, #EBF3FF, #DCE9FF);
  color:       var(--mn-accent);
  border:      1px solid rgba(0,102,204,.2);
  box-shadow:  0 1px 3px rgba(0,102,204,.1);
}
.mn__tag--restricted {
  background: rgba(111,66,193,.08);
  color:      #6F42C1;
  border:     1px solid rgba(111,66,193,.2);
}
.mn__tag--primary {
  background: rgba(0,102,204,.08);
  color:      #0066CC;
  border:     1px solid rgba(0,102,204,.2);
}
.mn__tag--default {
  background: var(--mn-item-hover);
  color:      var(--mn-sub);
  border:     1px solid var(--mn-border);
}

/* ═══════════════════════════════════════════════════════════
   MENU FOOTER — build info strip
═══════════════════════════════════════════════════════════ */
.mf {
  padding:    6px 16px calc(12px + env(safe-area-inset-bottom, 0px));
  background: var(--mn-bg2);
  border-top: 1px solid var(--mn-border);
}
.mf__div    { display:none; }   /* replaced by border-top above */
.mf__row    { display:flex; justify-content:space-between; align-items:center; margin-bottom:3px; }
.mf__k      { font-size:7px; font-weight:800; letter-spacing:1.4px; text-transform:uppercase; color:var(--mn-micro); font-family:'Courier New',monospace; }
.mf__v      { font-size:9px; color:var(--mn-micro); }
.mf__mono   { font-family:'Courier New',monospace; letter-spacing:.6px; }

/* ═══════════════════════════════════════════════════════════
   KEYFRAMES
═══════════════════════════════════════════════════════════ */
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.15} }

/* ═══════════════════════════════════════════════════════════
   RESPONSIVE
═══════════════════════════════════════════════════════════ */
@media (min-width: 600px) { ion-menu { --width: 316px; } }

/* ═══════════════════════════════════════════════════════════════════════
   ✧ PREMIUM SIDEBAR UPGRADE — 2026-04-21 v3 ✧
   World-class visual pass. Non-breaking override layer that elevates the
   existing .ip, .mn, .mf class structure without touching the template.
═══════════════════════════════════════════════════════════════════════ */

/* Deep aurora background with animated mesh */
ion-menu { --width: 316px; --background: linear-gradient(180deg, #F7FAFF 0%, #EEF2FF 100%); }
.menu-content {
  --background: transparent;
  background:
    radial-gradient(1200px 500px at -10% -10%, rgba(59,130,246,.08), transparent 60%),
    radial-gradient(800px 500px at 110% 110%, rgba(147,51,234,.06), transparent 60%),
    linear-gradient(180deg, #F8FAFC 0%, #F1F5F9 100%);
}

/* Identity panel — frosted glass with subtle gradient accent */
.ip {
  background: linear-gradient(135deg, rgba(255,255,255,.9) 0%, rgba(240,244,250,.8) 100%) !important;
  backdrop-filter: blur(24px);
  -webkit-backdrop-filter: blur(24px);
  border-bottom: 1px solid rgba(148,163,184,.12);
  box-shadow: 0 1px 0 rgba(255,255,255,.8) inset, 0 10px 30px -10px rgba(15,23,42,.08);
}
.ip__bar {
  height: 3px !important;
  background: linear-gradient(90deg, #1E40AF 0%, #3B82F6 35%, #8B5CF6 65%, #EC4899 100%) !important;
  background-size: 200% 100%;
  animation: ip-shimmer 7s linear infinite;
  box-shadow: 0 2px 14px rgba(30,64,175,.45) !important;
}
@keyframes ip-shimmer {
  0%   { background-position: 0% 50% }
  100% { background-position: 200% 50% }
}

/* Avatar — premium frame with animated ring */
.ip__av-wrap {
  position: relative;
  padding: 3px;
  background: linear-gradient(135deg, #3B82F6, #8B5CF6 50%, #EC4899);
  border-radius: 50%;
  box-shadow: 0 4px 18px rgba(59,130,246,.35), 0 0 0 1px rgba(255,255,255,.4);
}
.ip__av-wrap::before {
  content: ''; position: absolute; inset: -2px;
  border-radius: 50%;
  background: conic-gradient(from 0deg, #3B82F6, #8B5CF6, #EC4899, #F59E0B, #3B82F6);
  filter: blur(8px); opacity: .55; z-index: -1;
  animation: ip-ring 6s linear infinite;
}
@keyframes ip-ring { to { transform: rotate(360deg) } }
.ip__av {
  background: linear-gradient(135deg, #0F172A, #1E293B) !important;
  color: #fff !important;
  box-shadow: inset 0 0 0 1px rgba(255,255,255,.1);
}
.ip__initials { font-weight: 800 !important; letter-spacing: .5px }

/* Name + role pill */
.ip__name { letter-spacing: -.3px; font-weight: 800 !important; color: #0F172A !important }
.ip__role {
  letter-spacing: .6px !important;
  box-shadow: 0 2px 8px rgba(0,0,0,.12), inset 0 1px 0 rgba(255,255,255,.2) !important;
  padding: 2px 8px !important;
}
.ip__scope { color: #64748B !important; font-weight: 600 }

/* Sync chip — elevate */
.ip__sync {
  background: linear-gradient(135deg, rgba(255,255,255,.9), rgba(241,245,249,.85)) !important;
  border: 1px solid rgba(148,163,184,.2) !important;
  box-shadow: 0 2px 8px rgba(15,23,42,.06), 0 0 0 1px rgba(255,255,255,.4) inset;
  border-radius: 10px !important;
  transition: all .15s ease !important;
}
.ip__sync:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(15,23,42,.1) !important; background: rgba(255,255,255,.98) !important }

/* Group titles — elegant section headers with gradient bar */
.mn__gt {
  position: relative;
  font-size: 10px !important;
  letter-spacing: 1.4px !important;
  font-weight: 900 !important;
  color: #475569 !important;
  text-transform: uppercase;
  padding: 14px 18px 8px !important;
  margin: 0;
}
.mn__gt::after {
  content: '';
  display: block;
  height: 1px;
  margin-top: 8px;
  background: linear-gradient(90deg, rgba(148,163,184,.45) 0%, rgba(148,163,184,.05) 100%);
}

/* Menu items — premium surface with smooth hover + active gradient */
.mn__item {
  position: relative;
  border-radius: 10px !important;
  margin: 2px 10px !important;
  padding: 11px 12px !important;
  gap: 12px !important;
  background: transparent !important;
  transition: background .15s ease, transform .1s ease, box-shadow .15s ease !important;
  overflow: hidden;
}
.mn__item::before {
  content: '';
  position: absolute; left: 0; top: 50%; transform: translateY(-50%);
  width: 3px; height: 0;
  background: linear-gradient(180deg, #3B82F6, #8B5CF6);
  border-radius: 0 3px 3px 0;
  transition: height .2s ease;
}
.mn__item:hover {
  background: linear-gradient(90deg, rgba(59,130,246,.06), rgba(59,130,246,.02)) !important;
  transform: translateX(2px);
}
.mn__item:hover::before { height: 70% }
.mn__item:active { transform: scale(.99) }

/* Active state — prominent with gradient + glow */
.mn__item--active {
  background: linear-gradient(135deg, rgba(59,130,246,.16), rgba(139,92,246,.08)) !important;
  box-shadow:
    0 0 0 1px rgba(59,130,246,.18),
    0 6px 18px -8px rgba(59,130,246,.35),
    inset 0 1px 0 rgba(255,255,255,.5) !important;
  transform: translateX(2px);
}
.mn__item--active::before { height: 85% }

/* Icon bubble — premium elevated surface */
.mn__icon {
  width: 34px !important;
  height: 34px !important;
  border-radius: 10px !important;
  background: linear-gradient(135deg, #fff 0%, #F1F5F9 100%) !important;
  border: 1px solid rgba(148,163,184,.18) !important;
  box-shadow: 0 2px 6px rgba(15,23,42,.06) !important;
  color: #1E40AF !important;
  font-size: 17px !important;
  transition: all .2s ease !important;
  flex-shrink: 0;
}
.mn__item:hover .mn__icon {
  transform: scale(1.04) rotate(-3deg);
  box-shadow: 0 4px 12px rgba(30,64,175,.2) !important;
  background: linear-gradient(135deg, #EFF6FF, #DBEAFE) !important;
}
.mn__item--active .mn__icon {
  background: linear-gradient(135deg, #3B82F6, #1E40AF) !important;
  color: #fff !important;
  border-color: transparent !important;
  box-shadow: 0 4px 14px rgba(30,64,175,.4) !important;
  transform: scale(1.03);
}

/* Icon accent variants */
.mn__icon--create  { background: linear-gradient(135deg, #ECFDF5, #D1FAE5) !important; color: #047857 !important }
.mn__icon--alert   { background: linear-gradient(135deg, #FEF2F2, #FEE2E2) !important; color: #991B1B !important }
.mn__icon--screen  { background: linear-gradient(135deg, #EFF6FF, #DBEAFE) !important; color: #1E40AF !important }
.mn__icon--queue   { background: linear-gradient(135deg, #FEF3C7, #FDE68A) !important; color: #854D0E !important }
.mn__icon--data    { background: linear-gradient(135deg, #FAF5FF, #EDE9FE) !important; color: #6B21A8 !important }

/* Label typography */
.mn__label {
  font-size: 13px !important;
  font-weight: 700 !important;
  color: #0F172A !important;
  letter-spacing: -.1px;
}
.mn__sub {
  font-size: 10.5px !important;
  color: #64748B !important;
  font-weight: 500 !important;
  margin-top: 2px !important;
}
.mn__item--active .mn__label { color: #1E3A8A !important; font-weight: 800 !important }
.mn__item--active .mn__sub   { color: #3B82F6 !important }

/* Live badges — premium pulse */
.mn__badge {
  font-size: 10px !important;
  font-weight: 900 !important;
  padding: 2px 8px !important;
  border-radius: 99px !important;
  min-width: 22px;
  text-align: center;
  box-shadow: 0 2px 8px rgba(0,0,0,.15), inset 0 1px 0 rgba(255,255,255,.3);
  letter-spacing: .3px;
}
.mn__badge--primary  { background: linear-gradient(135deg, #3B82F6, #1E40AF) !important; color: #fff !important }
.mn__badge--warning  { background: linear-gradient(135deg, #F59E0B, #D97706) !important; color: #fff !important }
.mn__badge--danger   {
  background: linear-gradient(135deg, #EF4444, #DC2626) !important;
  color: #fff !important;
  animation: mn-badge-pulse 2s ease-in-out infinite;
}
@keyframes mn-badge-pulse {
  0%, 100% { box-shadow: 0 2px 8px rgba(220,38,38,.4), 0 0 0 0 rgba(220,38,38,.5), inset 0 1px 0 rgba(255,255,255,.3) }
  50%      { box-shadow: 0 2px 8px rgba(220,38,38,.4), 0 0 0 8px rgba(220,38,38,0),   inset 0 1px 0 rgba(255,255,255,.3) }
}

/* Tag chip */
.mn__tag {
  font-size: 9px !important;
  font-weight: 800 !important;
  padding: 2px 7px !important;
  border-radius: 4px !important;
  letter-spacing: .4px !important;
}

/* Danger item (logout) */
.mn__item--danger .mn__icon {
  background: linear-gradient(135deg, #FEF2F2, #FEE2E2) !important;
  color: #DC2626 !important;
}
.mn__item--danger:hover {
  background: linear-gradient(90deg, rgba(220,38,38,.08), rgba(220,38,38,.02)) !important;
}
.mn__item--danger:hover::before { background: linear-gradient(180deg, #EF4444, #DC2626) }

/* Footer — subtle glass strip */
.mf {
  margin-top: auto;
  padding: 14px 18px 16px !important;
  background: linear-gradient(180deg, rgba(241,245,249,0) 0%, rgba(241,245,249,.8) 50%) !important;
  border-top: 1px solid rgba(148,163,184,.15);
}
.mf__div { background: linear-gradient(90deg, transparent, rgba(148,163,184,.3), transparent) !important; height: 1px !important }
.mf__k { font-size: 9.5px !important; color: #64748B !important; font-weight: 700 !important; letter-spacing: .5px !important }
.mf__v { font-size: 10.5px !important; color: #1E293B !important; font-weight: 700 !important }
.mf__mono { font-family: ui-monospace, Menlo, monospace !important }

/* Whole-sidebar entrance */
.ip, .mn__group, .mf { animation: mn-slide-in .4s ease-out both }
.mn__group:nth-child(1) { animation-delay: .05s }
.mn__group:nth-child(2) { animation-delay: .1s }
.mn__group:nth-child(3) { animation-delay: .15s }
.mn__group:nth-child(4) { animation-delay: .2s }
.mn__group:nth-child(5) { animation-delay: .25s }
.mn__group:nth-child(6) { animation-delay: .3s }
@keyframes mn-slide-in {
  from { opacity: 0; transform: translateX(-6px) }
  to   { opacity: 1; transform: translateX(0) }
}

/* Scrollbar */
.menu-content::part(scroll)::-webkit-scrollbar { width: 6px }
.menu-content::part(scroll)::-webkit-scrollbar-thumb {
  background: linear-gradient(180deg, rgba(59,130,246,.35), rgba(139,92,246,.35));
  border-radius: 3px;
}

/* ═══════════════════════════════════════════════════════════════════════
   ✦ POE SENTINEL DARK SIDEBAR — 2026-05-06 v2 ✦
   On-brand: deep navy (#0B2545 — same as toolbar, splash, brand) + teal
   accent (#00B4A6) + warm amber for warnings. NO foreign colours
   (no purple/pink/blue that don't belong to the app's palette).
   High-contrast text (cream #F5F7FA on navy = WCAG AAA).
═══════════════════════════════════════════════════════════════════════ */

/* Sidebar surface = SAME gradient as the home page hero banner.
   Linear angle 145° matches HomePage.vue:.hp-hdr-bg verbatim. Two
   radial accent glows (teal top-left, amber bottom-right) sit ABOVE
   the gradient and are blended at low opacity so the navy reads
   exactly as the home banner does. */
ion-menu { --background: #06111E !important; }

.menu-content {
  --background: transparent !important;
  background:
    radial-gradient(900px 600px at -8% -10%, rgba(0,180,166,0.18), transparent 62%),
    radial-gradient(750px 520px at 112% 110%, rgba(251,191,36,0.10), transparent 65%),
    linear-gradient(145deg, #06111E 0%, #0D253F 55%, #0F3460 100%) !important;
}

/* ═══════════════════════════════════════════════════════════════════
   ✦ IDENTITY PANEL — EXTREME GLASS, DEEP NAVY, COLOURED TEXT (v3) ✦
   The previous version inherited a 55%-white shimmer overlay from
   .ip::after (legacy light theme), giving the top a milky-grey wash
   on the dark sidebar — that's the "ugly" the user flagged. This
   override:
     • Kills the inherited white wash.
     • Layers 3 radial glows + backdrop-filter blur(36px) for extreme
       frosted-glass depth.
     • Gives the user name a teal→amber gradient text fill (visual
       interest, not flat white).
     • Restores the white avatar ring as a teal-glow ring.
     • Coloured scope + role text.
═══════════════════════════════════════════════════════════════════ */
/* ─── Identity panel — AGGRESSIVE PREMIUM GLASS ───
   • Background = same 3-stop navy gradient as the home banner, rendered
     at 88-94% opacity so the underlying menu-content glow shows through
     for the "frosted-on-glass" depth effect.
   • Two radial coloured glows (teal top-left, amber bottom-right).
   • Backdrop-filter: blur(40px) + saturate(180%) + brightness(1.05) =
     true premium frosted-glass look (Apple Big-Sur grade).
   • Layered shadow stack: top hairline highlight + teal under-glow +
     deep ambient drop = the "glass card" depth signature. */
.ip {
  position: relative !important;
  background:
    radial-gradient(280px 140px at 12% 18%, rgba(0,180,166,0.26), transparent 70%),
    radial-gradient(320px 180px at 92% 82%, rgba(251,191,36,0.16), transparent 72%),
    linear-gradient(145deg, rgba(6,17,30,0.94) 0%, rgba(13,37,63,0.88) 55%, rgba(15,52,96,0.86) 100%) !important;
  backdrop-filter: blur(40px) saturate(180%) brightness(1.05);
  -webkit-backdrop-filter: blur(40px) saturate(180%) brightness(1.05);
  border-bottom: 1px solid rgba(0,180,166,0.24) !important;
  box-shadow:
    0 1px 0 rgba(255,255,255,0.08) inset,
    0 -1px 0 rgba(0,180,166,0.28) inset,
    0 14px 40px -10px rgba(0,180,166,0.22),
    0 24px 70px -16px rgba(0,0,0,0.75) !important;
  overflow: hidden;
}
/* Top sheen — single bright diagonal sweep that sells the "glass" feel.
   Sits ABOVE the radial glows but BELOW the content. */
.ip::before {
  content: '';
  position: absolute;
  top: 0; left: -20%;
  width: 140%; height: 35%;
  background: linear-gradient(120deg, transparent 0%, rgba(255,255,255,0.10) 35%, rgba(255,255,255,0.0) 60%);
  pointer-events: none;
  filter: blur(2px);
  z-index: 0;
}
/* KILL the inherited white-wash overlay (.ip::after at line ~2661 of the
   base styles). On a dark sidebar that 55%-white gradient is exactly
   what makes the top look "milky" / ugly. We replace it with a vivid
   diagonal teal→amber glass shimmer that reads as "frosted dark".  */
.ip::after {
  background:
    linear-gradient(135deg,
      rgba(0,180,166,0.10) 0%,
      rgba(255,255,255,0.04) 30%,
      transparent 55%,
      rgba(251,191,36,0.06) 100%) !important;
  mix-blend-mode: screen;
}

.ip__bar {
  background: linear-gradient(90deg, #00B4A6 0%, #17A2B8 30%, #FBBF24 55%, #FCA5A5 80%, #00B4A6 100%) !important;
  background-size: 200% 100% !important;
  box-shadow: 0 2px 16px rgba(0,180,166,0.65), 0 0 30px rgba(0,180,166,0.35) !important;
}

.ip__av-wrap {
  background: linear-gradient(135deg, #00B4A6, #17A2B8 50%, #FBBF24) !important;
  box-shadow:
    0 4px 18px rgba(0,180,166,0.55),
    0 0 0 1px rgba(255,255,255,0.10),
    0 0 24px rgba(0,180,166,0.35) !important;
}
.ip__av-wrap::before {
  background: conic-gradient(from 0deg, #00B4A6, #17A2B8, #FBBF24, #FCA5A5, #00B4A6) !important;
  filter: blur(10px) !important;
  opacity: 0.85 !important;
}
.ip__av {
  background:
    radial-gradient(circle at 30% 25%, rgba(0,180,166,0.30), transparent 60%),
    linear-gradient(135deg, #1A2F4F 0%, #0B2545 100%) !important;
  color: #5EEAD4 !important;                /* teal initials — pop on navy */
  /* IMPORTANT: kill the legacy 3px white outer ring (line ~2709). On a
     dark navy header that ring screams "foreign" — replace with a teal
     halo. */
  box-shadow:
    inset 0 0 0 1px rgba(94,234,212,0.22),
    0 4px 14px rgba(0,180,166,0.40),
    0 0 0 3px rgba(0,180,166,0.18) !important;
}
.ip__initials {
  color: #FFFFFF !important;
  text-shadow: 0 0 12px rgba(0,180,166,0.65), 0 1px 2px rgba(0,0,0,0.35);
}

/* Name — gradient text fill for colour + premium feel */
.ip__name {
  background: linear-gradient(135deg, #5EEAD4 0%, #99F6E4 40%, #FBBF24 100%) !important;
  -webkit-background-clip: text !important;
  background-clip: text !important;
  -webkit-text-fill-color: transparent !important;
  color: transparent !important;            /* fallback for non-WebKit */
  font-weight: 800 !important;
  letter-spacing: -0.2px !important;
  text-shadow: 0 1px 8px rgba(0,180,166,0.18);  /* glow halo */
}

.ip__role {
  background: linear-gradient(135deg, #00B4A6, #17A2B8) !important;
  color: #FFFFFF !important;
  box-shadow:
    0 2px 10px rgba(0,180,166,0.50),
    inset 0 1px 0 rgba(255,255,255,0.30),
    inset 0 -1px 0 rgba(0,0,0,0.20) !important;
}

.ip__scope {
  color: #FBBF24 !important;                /* warm amber — clear scope cue */
  font-weight: 700 !important;
  text-shadow: 0 1px 4px rgba(251,191,36,0.20);
}

.ip__sync {
  background: linear-gradient(135deg, rgba(255,255,255,0.08), rgba(0,180,166,0.05)) !important;
  border: 1px solid rgba(0,180,166,0.20) !important;
  box-shadow:
    0 2px 10px rgba(11,37,69,0.45),
    0 0 0 1px rgba(255,255,255,0.04) inset !important;
  color: rgba(245,247,250,0.94) !important;
}
.ip__sync:hover {
  background: linear-gradient(135deg, rgba(0,180,166,0.18), rgba(0,180,166,0.08)) !important;
  box-shadow: 0 6px 18px rgba(0,180,166,0.30) !important;
}
.ip__sync-label { color: #99F6E4 !important; font-weight: 700; }
.ip__sync-icon  { color: #5EEAD4 !important; }

/* ─── Language switcher inside the dark identity panel ───
   The LangSwitcher component is also used on the (light) Secondary
   Screening view, so we MUST NOT edit its scoped styles. Instead we
   pierce the scope with :deep() and override colours for the sidebar
   surface only. Outcome: legible amber/teal pills on navy. */
.ip__lang {
  background: rgba(0,180,166,0.04);
  border-top: 1px solid rgba(0,180,166,0.18) !important;
}
.ip__lang :deep(.lang-switcher) {
  background: rgba(255,255,255,0.06) !important;
  border: 1px solid rgba(0,180,166,0.22);
  box-shadow: inset 0 1px 0 rgba(255,255,255,0.04);
}
.ip__lang :deep(.lang-switcher__btn) {
  color: #99F6E4 !important;            /* teal-200, AAA on navy */
  background: transparent !important;
  font-weight: 800 !important;
  letter-spacing: 0.5px !important;
}
.ip__lang :deep(.lang-switcher__btn:hover) {
  color: #FFFFFF !important;
  background: rgba(0,180,166,0.18) !important;
}
.ip__lang :deep(.lang-switcher__btn:focus-visible) {
  outline: 2px solid #FBBF24 !important;
  outline-offset: 2px !important;
}
.ip__lang :deep(.lang-switcher__btn--active) {
  background: linear-gradient(135deg, #00B4A6, #17A2B8) !important;
  color: #FFFFFF !important;
  box-shadow:
    0 2px 10px rgba(0,180,166,0.55),
    inset 0 1px 0 rgba(255,255,255,0.30) !important;
}

/* Group titles — readable, AAA contrast */
.mn__gt { color: rgba(245,247,250,0.58) !important; }
.mn__gt::after {
  background: linear-gradient(90deg, rgba(255,255,255,0.12) 0%, rgba(255,255,255,0.0) 100%) !important;
}

/* Menu items */
.mn__item:hover {
  background: linear-gradient(90deg, rgba(0,180,166,0.12), rgba(0,180,166,0.02)) !important;
}
.mn__item::before {
  background: linear-gradient(180deg, #00B4A6, #FBBF24) !important;
}
.mn__item--active {
  background: linear-gradient(135deg, rgba(0,180,166,0.20), rgba(23,162,184,0.10)) !important;
  box-shadow:
    0 0 0 1px rgba(0,180,166,0.30),
    0 6px 24px -6px rgba(0,180,166,0.40),
    inset 0 1px 0 rgba(255,255,255,0.06) !important;
}

/* Icon tiles — navy elevated, teal-glow on active */
.mn__icon {
  background: linear-gradient(135deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02)) !important;
  border: 1px solid rgba(255,255,255,0.08) !important;
  box-shadow: 0 2px 6px rgba(11,37,69,0.45), inset 0 1px 0 rgba(255,255,255,0.04) !important;
  color: #5EEAD4 !important;            /* teal-300 — readable on navy */
}
.mn__item:hover .mn__icon {
  background: linear-gradient(135deg, rgba(0,180,166,0.20), rgba(0,180,166,0.08)) !important;
  box-shadow: 0 4px 14px rgba(0,180,166,0.30) !important;
  color: #99F6E4 !important;            /* teal-200 */
}
.mn__item--active .mn__icon {
  background: linear-gradient(135deg, #00B4A6, #0E7C7B) !important;
  color: #FFFFFF !important;
  border-color: transparent !important;
  box-shadow: 0 6px 18px rgba(0,180,166,0.50), inset 0 1px 0 rgba(255,255,255,0.20) !important;
}

/* Icon accent variants — readable on navy, palette-locked */
.mn__icon--create { background: linear-gradient(135deg, rgba(0,180,166,0.20), rgba(0,180,166,0.10)) !important; color: #99F6E4 !important; }
.mn__icon--alert  { background: linear-gradient(135deg, rgba(239,68,68,0.22), rgba(239,68,68,0.10)) !important; color: #FCA5A5 !important; }
.mn__icon--screen { background: linear-gradient(135deg, rgba(23,162,184,0.22), rgba(23,162,184,0.10)) !important; color: #67E8F9 !important; }
.mn__icon--queue  { background: linear-gradient(135deg, rgba(251,191,36,0.22), rgba(251,191,36,0.10)) !important; color: #FCD34D !important; }
.mn__icon--data   { background: linear-gradient(135deg, rgba(0,180,166,0.18), rgba(23,162,184,0.10)) !important; color: #5EEAD4 !important; }
.mn__icon--secondary { background: linear-gradient(135deg, rgba(23,162,184,0.22), rgba(23,162,184,0.10)) !important; color: #67E8F9 !important; }

/* Labels — high-contrast cream on navy */
.mn__label { color: #FFFFFF !important; }
.mn__sub   { color: rgba(245,247,250,0.72) !important; }
.mn__item--active .mn__label { color: #99F6E4 !important; }
.mn__item--active .mn__sub   { color: rgba(153,246,228,0.88) !important; }

/* Tag chip */
.mn__tag {
  background: rgba(0,180,166,0.18) !important;
  color: rgba(245,247,250,0.92) !important;
  border: 1px solid rgba(0,180,166,0.30) !important;
}

/* Danger (logout) */
.mn__item--danger .mn__icon {
  background: linear-gradient(135deg, rgba(239,68,68,0.22), rgba(239,68,68,0.10)) !important;
  color: #FCA5A5 !important;
}
.mn__item--danger .mn__label { color: #FCA5A5 !important; }
.mn__item--danger .mn__sub   { color: rgba(252,165,165,0.70) !important; }
.mn__item--danger:hover {
  background: linear-gradient(90deg, rgba(239,68,68,0.14), rgba(239,68,68,0.04)) !important;
}

/* Footer */
.mf {
  background: linear-gradient(180deg, rgba(11,37,69,0) 0%, rgba(255,255,255,0.04) 100%) !important;
  border-top: 1px solid rgba(255,255,255,0.07) !important;
}
.mf__div { background: linear-gradient(90deg, transparent, rgba(255,255,255,0.12), transparent) !important; }
.mf__k { color: rgba(245,247,250,0.55) !important; }
.mf__v { color: rgba(245,247,250,0.92) !important; }

/* Scrollbar — teal accent */
.menu-content::part(scroll)::-webkit-scrollbar-thumb {
  background: linear-gradient(180deg, rgba(0,180,166,0.55), rgba(251,191,36,0.45)) !important;
}
</style>