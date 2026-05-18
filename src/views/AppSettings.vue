<template>
  <IonPage>
    <IonHeader class="st-hdr" translucent>
      <div class="st-hdr-bg">
        <div class="st-hdr-top">
          <IonButtons slot="start"><IonMenuButton menu="app-menu" class="st-menu"/></IonButtons>
          <div class="st-hdr-title">
            <span class="st-eye">{{ auth.poe_code||'POE' }} · System</span>
            <span class="st-h1">App Settings</span>
          </div>
        </div>
      </div>
    </IonHeader>

    <IonContent class="st-content" :fullscreen="true">
      <div class="st-body">

        <!-- QUICK LINKS — relocated 2026-05-07 from sidebar -->
        <!-- Reasoning: the sidebar was overcrowded with operational +
             account entries. Moved Sync Centre, My Profile, Staff
             Directory, and the IHR Matrix reference here so the sidebar
             can stay focused on actual workflows. Each card-row is a
             tap-target; bottom-sheet navigation, no extra screens. -->
        <div class="st-card st-card--quicklinks">
          <div class="st-card-h"><span class="st-card-t">Quick links</span></div>

          <button class="st-ql" type="button" @click="goRoute('/profile')">
            <ion-icon class="st-ql-ico" :icon="personCircleOutline" aria-hidden="true" />
            <div class="st-ql-body">
              <span class="st-ql-t">My Profile</span>
              <span class="st-ql-d">View &amp; edit your account</span>
            </div>
            <ion-icon class="st-ql-arr" :icon="chevronForwardOutline" aria-hidden="true" />
          </button>

          <button class="st-ql" type="button" @click="goRoute('/sync')">
            <ion-icon class="st-ql-ico" :icon="syncOutline" aria-hidden="true" />
            <div class="st-ql-body">
              <span class="st-ql-t">Sync Centre</span>
              <span class="st-ql-d">{{ syncSubtitle }}</span>
            </div>
            <span v-if="syncPendingTotal > 0" class="st-ql-badge">{{ syncPendingTotal }}</span>
            <ion-icon class="st-ql-arr" :icon="chevronForwardOutline" aria-hidden="true" />
          </button>

          <button class="st-ql" type="button" @click="goRoute('/directory')">
            <ion-icon class="st-ql-ico" :icon="callOutline" aria-hidden="true" />
            <div class="st-ql-body">
              <span class="st-ql-t">Staff Directory</span>
              <span class="st-ql-d">Tap to dial · district · PHEOC · national</span>
            </div>
            <ion-icon class="st-ql-arr" :icon="chevronForwardOutline" aria-hidden="true" />
          </button>

          <button class="st-ql" type="button" @click="goRoute('/alerts/matrix')">
            <ion-icon class="st-ql-ico" :icon="bookOutline" aria-hidden="true" />
            <div class="st-ql-body">
              <span class="st-ql-t">WHO / IHR Matrix</span>
              <span class="st-ql-d">IHR Tier 1 · Tier 2 · Annex 2 · 7-1-7 reference</span>
            </div>
            <ion-icon class="st-ql-arr" :icon="chevronForwardOutline" aria-hidden="true" />
          </button>
        </div>

        <!-- USER -->
        <div class="st-card">
          <div class="st-card-h"><span class="st-card-t">Account</span></div>
          <div class="st-row"><span class="st-k">Name</span><span class="st-v">{{ auth.full_name||auth.name||'—' }}</span></div>
          <div class="st-row"><span class="st-k">Username</span><span class="st-v">{{ auth.username||'—' }}</span></div>
          <div class="st-row"><span class="st-k">Email</span><span class="st-v">{{ auth.email||'—' }}</span></div>
          <div class="st-row"><span class="st-k">Phone</span><span class="st-v">{{ auth.phone||'—' }}</span></div>
          <div class="st-row"><span class="st-k">Role</span><span class="st-v">{{ auth.role_key||'—' }}</span></div>
        </div>

        <!-- ASSIGNMENT -->
        <div class="st-card">
          <div class="st-card-h"><span class="st-card-t">Assignment</span></div>
          <div class="st-row"><span class="st-k">POE</span><span class="st-v">{{ auth.poe_code||'—' }}</span></div>
          <div class="st-row"><span class="st-k">District</span><span class="st-v">{{ auth.district_code||'—' }}</span></div>
          <div class="st-row"><span class="st-k">PHEOC</span><span class="st-v">{{ auth.pheoc_code||'—' }}</span></div>
          <div class="st-row"><span class="st-k">Province</span><span class="st-v">{{ auth.province_code||'—' }}</span></div>
          <div class="st-row"><span class="st-k">Country</span><span class="st-v">{{ auth.country_code||'—' }}</span></div>
        </div>

        <!-- SYNC -->
        <div class="st-card">
          <div class="st-card-h"><span class="st-card-t">Sync &amp; Storage</span></div>
          <div class="st-row"><span class="st-k">Connection</span><span :class="['st-v', isOnline?'st-v--g':'st-v--r']">{{ isOnline?'Online':'Offline' }}</span></div>
          <div class="st-row"><span class="st-k">App Version</span><span class="st-v">{{ APP.VERSION }}</span></div>
          <div class="st-row"><span class="st-k">Reference Data</span><span class="st-v">{{ APP.REFERENCE_DATA_VER }}</span></div>
          <div class="st-row"><span class="st-k">Device ID</span><span class="st-v st-v--mono">{{ deviceId }}</span></div>
          <div class="st-row"><span class="st-k">Server URL</span><span class="st-v st-v--mono">{{ serverUrl }}</span></div>
          <div class="st-row"><span class="st-k">Local Records</span><span class="st-v">{{ idbCounts.total }} cached</span></div>
        </div>

        <!-- PREFERENCES -->
        <div class="st-card">
          <div class="st-card-h"><span class="st-card-t">Preferences</span></div>
          <button class="st-action-btn" @click="toggleHaptics" type="button" :aria-pressed="haptics">
            <span class="st-action-ico">&#x1F4F3;</span>
            <div class="st-action-body">
              <span class="st-action-t">Haptic Feedback</span>
              <span class="st-action-d">Vibrate on critical alerts and validation errors</span>
            </div>
            <span :class="['st-toggle', haptics && 'st-toggle--on']" aria-hidden="true"><span class="st-toggle-dot"/></span>
          </button>
        </div>

        <!-- CAPABILITIES & HELP -->
        <!-- Per UX request, Capabilities & Help no longer lives as a separate
             top-level menu row. The full feature tour, status detail and
             troubleshooting are reached via the highlighted card below; the
             individual toggles below it are kept as a power-user shortcut. -->
        <div class="st-card">
          <div class="st-card-h">
            <span class="st-card-t">Capabilities &amp; help</span>
            <span class="st-card-sub">{{ capsEnabledCount }} of {{ capsList.length }} on</span>
          </div>

          <!-- Hero CTA — full feature explorer + tour replay -->
          <button class="st-action-btn st-action-btn--hero" type="button" @click="openCapsHelp"
                  aria-label="Open the Capabilities and Help explorer">
            <span class="st-action-ico st-action-ico--hero" aria-hidden="true">&#x1F9ED;</span>
            <div class="st-action-body">
              <span class="st-action-t">Explore capabilities &amp; how-to</span>
              <span class="st-action-d">Feature tour · status · troubleshooting · 30-second replay</span>
            </div>
            <span class="st-action-chev" aria-hidden="true">›</span>
          </button>

          <!-- Power-user shortcut: per-capability toggles inline. Long-form
               descriptions, demos and tours live in the explorer above. -->
          <div class="st-card-subhead">Quick toggles</div>
          <button v-for="c in capsList" :key="c.key" class="st-action-btn" type="button"
                  :aria-pressed="capToggles[c.key]" @click="toggleCap(c.key)">
            <span class="st-action-ico">{{ c.ico }}</span>
            <div class="st-action-body">
              <span class="st-action-t">{{ c.title }}</span>
              <span class="st-action-d">{{ c.desc }}</span>
            </div>
            <span :class="['st-toggle', capToggles[c.key] && 'st-toggle--on']" aria-hidden="true"><span class="st-toggle-dot"/></span>
          </button>

          <!-- App-lock setup shortcut when toggle is ON -->
          <button v-if="capToggles[CAPABILITY_KEYS.APP_LOCK]" class="st-action-btn" type="button" @click="setupPin">
            <span class="st-action-ico">&#x1F511;</span>
            <div class="st-action-body">
              <span class="st-action-t">{{ hasLockPin ? 'Change PIN' : 'Set a PIN' }}</span>
              <span class="st-action-d">{{ hasLockPin ? 'Replace your current 4–10 digit PIN' : 'Required before the lock can activate' }}</span>
            </div>
          </button>
        </div>

        <!-- SENTINEL PRODUCTIVITY — opt-in capture accelerators -->
        <section class="st-card" aria-labelledby="st-sent-h">
          <div class="st-card-h">
            <h2 id="st-sent-h" class="st-card-t">Capture accelerators</h2>
          </div>
          <p class="st-sent-lead">
            Optional helpers that fill the form for you. All off by default —
            enable individually. Turning them off returns the app to standard
            manual entry; nothing else changes.
          </p>

          <!-- Master switch -->
          <button class="st-action-btn" type="button"
                  :aria-pressed="sentinelToggles[CAPABILITY_KEYS.SENTINEL_MASTER]"
                  @click="toggleSentinel(CAPABILITY_KEYS.SENTINEL_MASTER, 'Master switch')">
            <ion-icon class="st-action-ico" :icon="flashOutline" aria-hidden="true" />
            <div class="st-action-body">
              <span class="st-action-t">Master switch</span>
              <span class="st-action-d">Disables every accelerator below in one tap.</span>
            </div>
            <span :class="['st-toggle', sentinelToggles[CAPABILITY_KEYS.SENTINEL_MASTER] && 'st-toggle--on']" aria-hidden="true"><span class="st-toggle-dot"/></span>
          </button>

          <!-- Production surface: only features with end-to-end JS feature
               code are exposed. Planned items are intentionally NOT shown —
               toggles you can't act on are worse than no toggles. -->
          <button v-for="f in sentinelFeatures" :key="f.key" class="st-action-btn"
                  type="button"
                  :aria-pressed="sentinelToggles[f.key]"
                  :disabled="!masterOn"
                  @click="toggleSentinel(f.key, f.title)">
            <ion-icon class="st-action-ico" :icon="f.icon" aria-hidden="true" />
            <div class="st-action-body">
              <span class="st-action-t">{{ f.title }}</span>
              <span class="st-action-d">{{ f.help }}</span>
            </div>
            <span :class="['st-toggle', sentinelToggles[f.key] && 'st-toggle--on']" aria-hidden="true"><span class="st-toggle-dot"/></span>
          </button>

          <!-- Bulk off -->
          <div class="st-sent-actions">
            <IonButton expand="block" color="danger" fill="outline" @click="confirmDisableAllSentinel">
              Disable all accelerators
            </IonButton>
          </div>
        </section>

        <!-- ACTIONS -->
        <!-- ── Alert Notification Channels (Request 4) ──────────────── -->
        <div class="st-card" aria-labelledby="st-notif-h">
          <div class="st-card-h">
            <span id="st-notif-h" class="st-card-t">Alert Notifications</span>
            <span class="st-card-sub">Choose how you receive critical alerts</span>
          </div>

          <!-- In-app — always on -->
          <div class="st-action-btn" style="cursor:default">
            <span class="st-action-ico">📲</span>
            <div class="st-action-body">
              <span class="st-action-t">In-App Notifications</span>
              <span class="st-action-d">Always enabled — alerts appear inside the app</span>
            </div>
            <span class="st-toggle st-toggle--on" style="flex-shrink:0;pointer-events:none" aria-label="Always on"><span class="st-toggle-dot"/></span>
          </div>

          <!-- SMS toggle -->
          <button class="st-action-btn" type="button"
            :aria-pressed="notifPrefs.sms"
            @click="notifPrefs.sms = !notifPrefs.sms">
            <span class="st-action-ico">💬</span>
            <div class="st-action-body">
              <span class="st-action-t">SMS Alerts</span>
              <span class="st-action-d">Receive critical alerts as text messages</span>
            </div>
            <span :class="['st-toggle', notifPrefs.sms && 'st-toggle--on']" aria-hidden="true"><span class="st-toggle-dot"/></span>
          </button>
          <div v-if="notifPrefs.sms" class="st-notif-input-row">
            <label class="st-notif-lbl" for="st-sms-num">Phone number for SMS</label>
            <input id="st-sms-num" class="st-notif-input" type="tel"
              placeholder="+250 700 000 000"
              v-model="notifPrefs.sms_number"
              inputmode="tel"
              autocomplete="tel"
            />
          </div>

          <!-- Email toggle -->
          <button class="st-action-btn" type="button"
            :aria-pressed="notifPrefs.email"
            @click="notifPrefs.email = !notifPrefs.email">
            <span class="st-action-ico">✉️</span>
            <div class="st-action-body">
              <span class="st-action-t">Email Alerts</span>
              <span class="st-action-d">Receive critical alerts via email</span>
            </div>
            <span :class="['st-toggle', notifPrefs.email && 'st-toggle--on']" aria-hidden="true"><span class="st-toggle-dot"/></span>
          </button>
          <div v-if="notifPrefs.email" class="st-notif-input-row">
            <label class="st-notif-lbl" for="st-email-addr">Email address for alerts</label>
            <input id="st-email-addr" class="st-notif-input" type="email"
              placeholder="officer@health.go.ug"
              v-model="notifPrefs.email_address"
              inputmode="email"
              autocomplete="email"
            />
          </div>

          <button class="st-save-notif-btn" type="button" @click="persistNotifPrefs">
            Save notification preferences
          </button>
        </div>

        <div class="st-card">
          <div class="st-card-h"><span class="st-card-t">Actions</span></div>
          <button class="st-action-btn" @click="goSync">
            <span class="st-action-ico">&#x21BB;</span>
            <div class="st-action-body"><span class="st-action-t">Manage Sync Queue</span><span class="st-action-d">View pending uploads and retry failed syncs</span></div>
          </button>
          <!-- Audit A1: ModelManager view was orphaned from the menu/settings.
               Surface here so officers can see/download the Sentinel ML
               models when accelerators are enabled. -->
          <button class="st-action-btn" @click="goModelManager">
            <span class="st-action-ico">&#x1F4E6;</span>
            <div class="st-action-body"><span class="st-action-t">Manage offline models</span><span class="st-action-d">Download or remove on-device ML models for accelerators</span></div>
          </button>
          <!-- In-app plugin diagnostics — runs a verbose self-test of every
               Capacitor plugin and shows pass/warn/fail per probe with the
               error message + remediation hint. Lets a developer (or field
               tech) triage "X feature isn't working" without pulling logs. -->
          <button class="st-action-btn" @click="goDiagnostics">
            <span class="st-action-ico">&#x1F9EA;</span>
            <div class="st-action-body"><span class="st-action-t">Plugin diagnostics</span><span class="st-action-d">Run a verbose self-test of every native plugin · pass / warn / fail per probe</span></div>
          </button>
          <button class="st-action-btn" @click="confirmClearCache" :disabled="clearing">
            <span class="st-action-ico">&#x1F5D1;</span>
            <div class="st-action-body"><span class="st-action-t">{{ clearing?'Clearing...':'Clear Local Cache' }}</span><span class="st-action-d">Removes cached dashboard data. Records are preserved.</span></div>
          </button>
          <button class="st-action-btn st-action-btn--danger" @click="signOut">
            <span class="st-action-ico">&#x21AA;</span>
            <div class="st-action-body"><span class="st-action-t">Sign Out</span><span class="st-action-d">End this session and return to login</span></div>
          </button>
        </div>

        <!-- ABOUT -->
        <div class="st-card">
          <div class="st-card-h"><span class="st-card-t">About</span></div>
          <div class="st-about">
            <p><strong>ECSA-HC POE Screening</strong></p>
            <p>WHO/IHR 2005 compliant Point of Entry surveillance and screening system. Built for offline-first operation with enterprise-grade sync.</p>
            <p class="st-about-meta">Schema v{{ APP.MIN_SCHEMA_VERSION }} · Built {{ buildYear }}</p>
          </div>
        </div>

        <div style="height:48px"/>
      </div>
    </IonContent>

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="2500" position="top" @didDismiss="toast.show=false"/>
  </IonPage>
</template>

<script setup>
import { IonPage, IonHeader, IonButtons, IonMenuButton, IonContent, IonToast, IonButton, IonIcon, alertController } from '@ionic/vue'
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { APP, dbGetCount, STORE } from '@/services/poeDB'
import { hapticsEnabled, setHapticsEnabled, hapticLight } from '@/services/haptics'
import { useI18n } from '@/i18n'
const { t } = useI18n()
import {
  CAPABILITY_KEYS,
  isEnabled as isCapEnabled,
  setEnabled as setCapEnabled,
  subscribe as subscribeCap,
} from '@/services/capabilities'
import { hasPin as hasLockPinFn } from '@/services/plugins/biometric'
import {
  flashOutline, scanOutline,
  // 2026-05-07 — icons for the relocated quick-link cards.
  personCircleOutline, syncOutline, callOutline, bookOutline,
  chevronForwardOutline,
} from 'ionicons/icons'

const router = useRouter()
function getAuth() { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {} }
const auth = ref(getAuth())
const isOnline = ref(navigator.onLine)
const clearing = ref(false)
const toast = reactive({ show:false, msg:'', color:'success' })
const idbCounts = ref({ total: 0 })

// 2026-05-07 — Quick-link plumbing.
// `syncPendingTotal` polls the unified sync engine every 8s while the
// Settings view is open; matches the badge logic in App.vue's sidebar.
// `goRoute` is a tiny helper used by every quick-link button.
const syncPendingTotal = ref(0)
let _syncPollTimer = null
async function _refreshSyncPending() {
  try {
    const mod = await import('@/services/syncEngine')
    if (typeof mod.getPendingCounts === 'function') {
      const counts = await mod.getPendingCounts()
      let p = 0
      for (const c of counts || []) p += (c.pending || 0)
      syncPendingTotal.value = p
    }
  } catch { /* engine unavailable — leave count at 0 */ }
}
const syncSubtitle = computed(() => {
  if (!isOnline.value) return 'Offline · ' + (syncPendingTotal.value > 0 ? syncPendingTotal.value + ' records waiting' : 'all records on device')
  if (syncPendingTotal.value > 0) return syncPendingTotal.value + ' record' + (syncPendingTotal.value === 1 ? '' : 's') + ' pending sync'
  return 'All records synced'
})
function goRoute(path) {
  try { router.push(path) } catch (e) { console.debug('[settings] navTo failed', e?.message) }
}
const buildYear = new Date().getFullYear()

const deviceId = ref(localStorage.getItem('ug_poe_device_id') || localStorage.getItem('poe_device_id') || 'Not assigned')
const serverUrl = ref(window.SERVER_URL || 'Not configured')

// ── Alert Notification Channels (Request 4) ──────────────────────────────
// Persisted to localStorage so the user's preference survives app restarts.
// The server reads these prefs via the user profile API and routes alerts
// to the configured channels. In-app is always on (cannot be disabled).
const NOTIF_PREFS_KEY = 'alert_notif_prefs_v1'
function loadNotifPrefs() {
  try { return JSON.parse(localStorage.getItem(NOTIF_PREFS_KEY) || '{}') } catch { return {} }
}
function saveNotifPrefs(prefs) {
  try { localStorage.setItem(NOTIF_PREFS_KEY, JSON.stringify(prefs)) } catch {}
}
const notifPrefs = reactive({
  in_app:  true,   // always on
  sms:     loadNotifPrefs().sms     ?? false,
  email:   loadNotifPrefs().email   ?? true,
  sms_number:   loadNotifPrefs().sms_number   ?? '',
  email_address: loadNotifPrefs().email_address ?? '',
})
function persistNotifPrefs() {
  const { sms, email, sms_number, email_address } = notifPrefs
  saveNotifPrefs({ sms, email, sms_number, email_address })
  // Fire-and-forget: patch the server profile so the backend also knows.
  const uid = auth?.value?.id
  if (uid && navigator.onLine) {
    fetch(`${window.SERVER_URL}/users/${uid}/notification-prefs`, {
      method: 'PATCH',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ in_app: true, sms, email, sms_number, email_address }),
    }).catch(() => {}) // best-effort
  }
  toast.msg = 'Notification preferences saved'
  toast.color = 'success'
  toast.show = true
}

const haptics = ref(hapticsEnabled())
function toggleHaptics() {
  haptics.value = !haptics.value
  setHapticsEnabled(haptics.value)
  if (haptics.value) hapticLight()
  toast.msg = haptics.value ? 'Haptic feedback enabled' : 'Haptic feedback disabled'
  toast.color = 'success'
  toast.show = true
}

// Capability toggles — mirror of the Capabilities & Help view but inline here
// for power-users who live in Settings.
const capsList = [
  { key: CAPABILITY_KEYS.NETWORK,      ico: '📶', title: 'Accurate connectivity',   desc: 'Native network probe replaces the unreliable browser flag' },
  { key: CAPABILITY_KEYS.KEEPAWAKE,    ico: '☀',        title: 'Keep awake during screening', desc: 'Screen stays on for long secondary interviews' },
  { key: CAPABILITY_KEYS.BARCODE,      ico: '📷', title: 'Barcode / QR scan',       desc: 'On-device scanning — no internet required' },
  { key: CAPABILITY_KEYS.LOCAL_NOTIFS, ico: '🔔', title: 'Follow-up reminders',     desc: 'Schedule on-device reminders for RTSL follow-ups' },
  { key: CAPABILITY_KEYS.PDF_SHARE,    ico: '📤', title: 'PDF share',               desc: 'Share clinical reports via system chooser' },
  { key: CAPABILITY_KEYS.DIRECTORY,    ico: '📞', title: 'Staff directory',         desc: 'Tap-to-dial contacts sidebar entry' },
  { key: CAPABILITY_KEYS.APP_LOCK,     ico: '🔒', title: 'App lock (biometric + PIN)', desc: 'Gate the app on launch and after idle' },
]
const capToggles = reactive(Object.fromEntries(
  capsList.map(c => [c.key, isCapEnabled(c.key)])
))
// Used by the Capabilities & Help card header — gives the user a quick
// "X of Y on" tally without having to scan every toggle row.
const capsEnabledCount = computed(() =>
  capsList.reduce((n, c) => n + (capToggles[c.key] ? 1 : 0), 0)
)
const hasLockPin = ref(hasLockPinFn())
function toggleCap(key) {
  const next = !capToggles[key]
  capToggles[key] = next
  setCapEnabled(key, next)
  hapticLight()
  toast.msg = (next ? 'Enabled: ' : 'Disabled: ') + (capsList.find(c => c.key === key)?.title || key)
  toast.color = 'success'
  toast.show = true
  // If user enables applock but hasn't set a PIN, prompt for setup.
  if (key === CAPABILITY_KEYS.APP_LOCK && next && !hasLockPinFn()) {
    setTimeout(setupPin, 300)
  }
}
function setupPin() {
  try { window.dispatchEvent(new CustomEvent('app-lock-request-setup')) } catch {}
  hasLockPin.value = hasLockPinFn()
}
function openCapsHelp() { router.push('/capabilities-help') }

// ─── Sentinel productivity (production surface) ────────────────────────────
// Only features with end-to-end JS+native wiring appear here. Capability
// keys for planned features (MRZ, NFC, doc-scanner, face-match, BLE,
// translate, entity, smart-reply, shortcuts, unified-scan) remain in
// `capabilities.js` so the underlying gating + Model Manager registry stay
// stable for future feature work, but they are intentionally NOT exposed
// as toggles until they have feature code consumers.
// 2026-05-07 — Voice wizard removed app-wide. Sentinel features list is now
// empty; the master switch + bulk-disable row remain for future feature work.
const sentinelFeatures = []
const SENTINEL_VISIBLE_KEYS = sentinelFeatures.map(f => f.key)

// Reactive mirror — only the visible flags + master.
const sentinelToggles = reactive({
  [CAPABILITY_KEYS.SENTINEL_MASTER]: isCapEnabled(CAPABILITY_KEYS.SENTINEL_MASTER),
  ...Object.fromEntries(SENTINEL_VISIBLE_KEYS.map(k => [k, isCapEnabled(k)])),
})
const masterOn = computed(() => !!sentinelToggles[CAPABILITY_KEYS.SENTINEL_MASTER])

function toggleSentinel(key, title) {
  const next = !sentinelToggles[key]
  sentinelToggles[key] = next
  setCapEnabled(key, next)
  hapticLight()
  toast.msg = `${title} ${next ? 'enabled' : 'disabled'}`
  toast.color = 'success'
  toast.show = true
}

async function confirmDisableAllSentinel() {
  const alert = await alertController.create({
    header: 'Disable all?',
    message: 'Turns off the master switch and every accelerator. Manual entry continues to work exactly as before. Individual toggles are kept so you can re-enable them later.',
    buttons: [
      { text: 'Cancel', role: 'cancel' },
      {
        text: 'Disable',
        role: 'destructive',
        handler: () => {
          // Only flip the visible (wired) keys. Planned-but-hidden keys are
          // already off by default; we don't touch them.
          for (const k of SENTINEL_VISIBLE_KEYS) {
            setCapEnabled(k, false)
            sentinelToggles[k] = false
          }
          setCapEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, false)
          sentinelToggles[CAPABILITY_KEYS.SENTINEL_MASTER] = false
          toast.msg = 'All accelerators disabled.'
          toast.color = 'success'
          toast.show = true
        },
      },
    ],
  })
  await alert.present()
}

// Live reactivity: keep the UI mirror in sync with any setEnabled() from anywhere.
const sentinelUnsubs = []
function bindSentinelSubscriptions() {
  const keys = [CAPABILITY_KEYS.SENTINEL_MASTER, ...SENTINEL_VISIBLE_KEYS]
  for (const k of keys) {
    const off = subscribeCap(k, v => { sentinelToggles[k] = !!v })
    sentinelUnsubs.push(off)
  }
}

function onLockChanged() { hasLockPin.value = hasLockPinFn() }
window.addEventListener('app-lock-changed', onLockChanged)

async function loadCounts() {
  try {
    const totals = await Promise.all([
      dbGetCount(STORE.PRIMARY_SCREENINGS).catch(() => 0),
      dbGetCount(STORE.SECONDARY_SCREENINGS).catch(() => 0),
      dbGetCount(STORE.NOTIFICATIONS).catch(() => 0),
      dbGetCount(STORE.ALERTS).catch(() => 0),
      dbGetCount(STORE.AGGREGATED_SUBMISSIONS).catch(() => 0),
    ])
    idbCounts.value.total = totals.reduce((s, n) => s + (n || 0), 0)
  } catch {}
}

function goSync() { router.push('/sync') }
function goModelManager() { router.push('/settings/sentinel-models') }
function goDiagnostics() { router.push('/settings/diagnostics') }

async function confirmClearCache() {
  if (!confirm('Clear cached dashboard data? Records and pending syncs are preserved.')) return
  clearing.value = true
  try {
    // Clear localStorage cache keys (dashboard snapshots, sync timestamps)
    const keys = ['cmd_summary_v3', 'cmd_trend_v3', 'cmd_summary_v2', 'cmd_trend_v2', 'rw_pr_last_server_sync', 'rw_ssr_last_server_sync', 'rw_nq_last_sync', 'pr_last_server_sync', 'ssr_last_server_sync', 'nq_last_sync']
    for (const k of keys) localStorage.removeItem(k)
    toast.msg = 'Cache cleared'; toast.color = 'success'; toast.show = true
  } finally { clearing.value = false }
}

function signOut() {
  if (!confirm('Sign out of this session?')) return
  sessionStorage.removeItem('AUTH_DATA')
  router.replace('/')
  setTimeout(() => location.reload(), 100)
}

function onOnline() { isOnline.value = true }
function onOffline() { isOnline.value = false }

onMounted(() => {
  auth.value = getAuth()
  isOnline.value = navigator.onLine
  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)
  bindSentinelSubscriptions()
  loadCounts()
  // 2026-05-07: Sync-pending badge for the Quick links card.
  _refreshSyncPending()
  _syncPollTimer = setInterval(_refreshSyncPending, 8000)
})

onUnmounted(() => {
  window.removeEventListener('online', onOnline)
  window.removeEventListener('offline', onOffline)
  window.removeEventListener('app-lock-changed', onLockChanged)
  for (const off of sentinelUnsubs) { try { off && off() } catch {} }
  sentinelUnsubs.length = 0
  if (_syncPollTimer) { clearInterval(_syncPollTimer); _syncPollTimer = null }
})
</script>

<style scoped>
*{box-sizing:border-box}
.st-hdr{--background:transparent;border:none}
.st-hdr-bg{background:linear-gradient(135deg,#001D3D,#003566,#003F88);padding:8px 0}
.st-hdr-top{display:flex;align-items:center;gap:4px;padding:0 8px}
.st-menu{--color:rgba(255,255,255,.7)}
.st-hdr-title{flex:1;display:flex;flex-direction:column;min-width:0}
.st-eye{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:rgba(255,255,255,.4)}
.st-h1{font-size:17px;font-weight:800;color:#fff}

.st-content{--background:#F0F4FA}
.st-body{padding:10px 12px 0;max-width:480px;margin:0 auto}

.st-card{background:#fff;border-radius:10px;border:1px solid #E8EDF5;margin-bottom:10px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.03)}
.st-card-h{padding:12px 14px;border-bottom:1px solid #F0F4FA}
.st-card-t{font-size:13px;font-weight:800;color:#1A3A5C}

/* ── Quick-link rows (2026-05-07) — relocated sidebar items ── */
.st-card--quicklinks { padding-bottom: 4px; }
.st-ql {
  display: flex; align-items: center; gap: 12px;
  width: 100%;
  padding: 12px 14px;
  border: none; background: transparent;
  border-top: 1px solid #F0F4FA;
  text-align: left;
  font: inherit; cursor: pointer;
  -webkit-tap-highlight-color: transparent;
  transition: background 0.15s ease;
}
.st-ql:first-of-type { border-top: none; }
.st-ql:hover  { background: #F8FAFC; }
.st-ql:active { background: #F1F5F9; }
.st-ql-ico {
  flex-shrink: 0;
  width: 36px; height: 36px;
  border-radius: 10px;
  background: linear-gradient(135deg, #EFF6FF, #DBEAFE);
  color: #1E40AF;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 18px;
  border: 1px solid rgba(30,64,175,0.10);
}
.st-ql-body { flex: 1 1 auto; min-width: 0; }
.st-ql-t {
  display: block; font-weight: 800; font-size: 13px; color: #0F172A;
  letter-spacing: -0.1px;
}
.st-ql-d {
  display: block; font-size: 11px; color: #64748B;
  margin-top: 2px;
  line-height: 1.4;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.st-ql-arr {
  flex-shrink: 0;
  font-size: 18px;
  color: #94A3B8;
}
.st-ql-badge {
  flex-shrink: 0;
  background: linear-gradient(135deg, #F59E0B, #D97706);
  color: #fff;
  font-size: 10.5px; font-weight: 800;
  padding: 3px 8px;
  border-radius: 9999px;
  min-width: 22px; text-align: center;
  letter-spacing: 0.2px;
  box-shadow: 0 2px 6px rgba(245,158,11,0.35);
}

.st-row{display:flex;justify-content:space-between;align-items:center;padding:10px 14px;border-top:1px solid #F0F4FA}
.st-row:first-of-type{border-top:none}
.st-k{font-size:12px;color:#64748B;font-weight:600}
.st-v{font-size:13px;font-weight:700;color:#1A3A5C;text-align:right;max-width:65%;word-break:break-word}
.st-v--mono{font-family:monospace;font-size:11px}
.st-v--g{color:#10B981}.st-v--r{color:#DC2626}

.st-action-btn{width:100%;display:flex;align-items:center;gap:12px;padding:14px;border:none;border-top:1px solid #F0F4FA;background:transparent;cursor:pointer;text-align:left}
.st-action-btn:first-of-type{border-top:none}
.st-action-btn:disabled{opacity:.5;cursor:not-allowed}
.st-action-btn--danger .st-action-t{color:#DC2626}
.st-action-ico{font-size:20px;width:32px;height:32px;display:flex;align-items:center;justify-content:center;background:#F1F5F9;border-radius:8px;flex-shrink:0}
.st-action-btn--danger .st-action-ico{background:#FEE2E2}
.st-action-body{flex:1;display:flex;flex-direction:column;gap:2px}
.st-action-t{font-size:13px;font-weight:700;color:#1A3A5C}
.st-action-d{font-size:11px;color:#64748B}

.st-about{padding:14px}
.st-about p{margin:0 0 8px;font-size:12px;color:#475569;line-height:1.5}
.st-about strong{color:#1A3A5C}
.st-about-meta{font-size:10px;color:#94A3B8!important;font-family:monospace}

.st-toggle{flex-shrink:0;width:40px;height:22px;border-radius:12px;background:#CBD5E1;position:relative;transition:background .2s}
.st-toggle--on{background:#10B981}
.st-toggle-dot{position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:transform .2s}
.st-toggle--on .st-toggle-dot{transform:translateX(18px)}
.st-notif-input-row{padding:8px 14px 12px;display:flex;flex-direction:column;gap:4px;border-top:1px solid #F0F4FA;background:#F8FAFC}
.st-notif-lbl{font-size:11px;font-weight:600;color:#64748B}
.st-notif-input{padding:8px 12px;border:1px solid #CBD5E1;border-radius:8px;font-size:13px;background:#fff;color:#1E293B;width:100%;outline:none}
.st-notif-input:focus{border-color:#0891B2;box-shadow:0 0 0 2px rgba(8,145,178,.12)}
.st-save-notif-btn{width:calc(100% - 28px);margin:8px 14px 14px;padding:10px;border:none;border-radius:10px;background:#0F3460;color:#EDF2FA;font-size:13px;font-weight:700;cursor:pointer}
.st-save-notif-btn:active{opacity:.85}

.st-card-h{display:flex;justify-content:space-between;align-items:center}
.st-card-link{background:transparent;border:none;color:#0891B2;font-size:11px;font-weight:700;cursor:pointer;text-transform:uppercase;letter-spacing:.6px}
.st-card-link:hover{text-decoration:underline}
.st-card-sub{font-size:10px;font-weight:700;color:#64748B;letter-spacing:.4px;text-transform:uppercase;font-variant-numeric:tabular-nums}
.st-card-subhead{padding:10px 14px 6px;font-size:10px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.8px;border-top:1px solid #F0F4FA;background:#F8FAFC}

/* Hero variant of an action button — used to surface the
   Capabilities & Help explorer from inside Settings. Same row geometry,
   richer chrome so it reads as the canonical entry-point rather than a
   plain-jane row. */
.st-action-btn--hero{
  background:linear-gradient(135deg,#EBF3FF 0%,#F4F8FF 100%);
  border-bottom:1px solid #E2E8F0;
  border-top:none;
  padding:16px 14px;
}
.st-action-btn--hero .st-action-t{color:#0F3460;font-weight:800}
.st-action-btn--hero .st-action-d{color:#475569}
.st-action-ico--hero{
  background:linear-gradient(135deg,#0F3460 0%,#1B4D8C 100%);
  color:#fff;
  font-size:22px;
  width:40px;
  height:40px;
}
.st-action-chev{flex-shrink:0;font-size:24px;font-weight:300;color:#0F3460;opacity:.55;line-height:1}

.st-sent-lead{padding:10px 14px 12px;font-size:13px;line-height:1.5;color:#475569;border-bottom:1px solid #F0F4FA;margin:0}
.st-sent-sub{padding:10px 14px 6px;font-size:10px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.8px;border-top:1px solid #F0F4FA;background:#F8FAFC;margin:0}
.st-sent-actions{display:flex;flex-direction:column;gap:8px;padding:12px 14px;border-top:1px solid #F0F4FA}
.st-action-badge{display:inline-block;margin-left:8px;padding:1px 6px;font-size:9px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:#6B7280;background:#F1F5F9;border:1px solid #E2E8F0;border-radius:999px;vertical-align:middle}
</style>
