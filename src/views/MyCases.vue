<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import {
  IonPage, IonHeader, IonToolbar, IonButtons, IonMenuButton,
  IonContent, IonIcon, IonRefresher, IonRefresherContent, IonModal,
  alertController, toastController,
} from '@ionic/vue'
import {
  flameOutline, lockClosedOutline, refreshOutline,
  checkmarkCircle, cloudOfflineOutline, syncOutline,
  warningOutline, documentTextOutline, arrowForwardOutline,
} from 'ionicons/icons'
import { useAuth } from '@/composables/useAuth'
import { useCan } from '@/composables/useCan'
import { listCachedAlerts } from '@/services/alertCache'
import { useAlertLifecycle } from '@/composables/useAlertLifecycle'
import { useOutbox } from '@/services/alertOutbox'
import { inFlight } from '@/services/httpInterceptor'
import SmartCloseWizard from '@/components/alerts/SmartCloseWizard.vue'
import { useI18n } from '@/i18n'
const { t } = useI18n()

const router  = useRouter()
const auth    = useAuth()
const { can } = useCan()
const { pending: outboxPending } = useOutbox()

// ── Online ────────────────────────────────────────────────────────────────────
const isOnline = ref(typeof navigator === 'undefined' ? true : navigator.onLine !== false)
const onOnline  = () => { isOnline.value = true;  load() }
const onOffline = () => { isOnline.value = false }
if (typeof window !== 'undefined') {
  window.addEventListener('online',  onOnline)
  window.addEventListener('offline', onOffline)
}
onBeforeUnmount(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('online',  onOnline)
    window.removeEventListener('offline', onOffline)
  }
})

// ── State ─────────────────────────────────────────────────────────────────────
const tab     = ref('open')
const loading = ref(false)
const acking  = ref(null)
const alerts  = ref([])
const cached  = ref([])

// ── Close wizard ──────────────────────────────────────────────────────────────
const closeAlertId = ref(null)
const lc = useAlertLifecycle(closeAlertId)
const closeFor  = ref(null)
const closeOpen = computed({
  get: () => !!closeFor.value,
  set: v => { if (!v) { closeFor.value = null; closeAlertId.value = null } },
})

// Fired by IonModal AFTER the slide-down animation completes. Doing the
// list reload here (instead of inline with @closed) means the user sees
// an instant dismiss, and the (expensive) re-fetch happens off-frame.
function onCloseModalDismissed() {
  closeFor.value = null
  closeAlertId.value = null
  // requestAnimationFrame so the next layout pass is the dismissed one;
  // load() then runs in idle time.
  if (typeof requestAnimationFrame === 'function') {
    requestAnimationFrame(() => { load().catch(() => {}) })
  } else {
    setTimeout(() => { load().catch(() => {}) }, 0)
  }
}

// ── API ───────────────────────────────────────────────────────────────────────
function baseUrl() {
  return (typeof window !== 'undefined' && window.SERVER_URL) || import.meta.env.VITE_SERVER_URL || ''
}
async function load() {
  if (!auth.value?.id) return
  cached.value = listCachedAlerts()
  if (!isOnline.value) return
  loading.value = true
  try {
    const url = baseUrl().replace(/\/$/, '') + '/alerts?user_id=' + auth.value.id + '&per_page=100'
    const res  = await fetch(url, { headers: { Accept: 'application/json', 'X-User-Id': String(auth.value.id) } })
    const json = await res.json().catch(() => null)
    if (res.ok && json?.success) {
      const d = json.data
      alerts.value = Array.isArray(d?.items)  ? d.items
                   : Array.isArray(d?.alerts) ? d.alerts
                   : Array.isArray(d)          ? d : []
    }
  } catch { /* cached list still shows */ }
  finally { loading.value = false }
}
onMounted(load)

// ── Derived ───────────────────────────────────────────────────────────────────
const myUid = computed(() => Number(auth.value?.id || 0))
const list  = computed(() => alerts.value.length ? alerts.value : cached.value.map(c => c.alert).filter(Boolean))

const RISK_ORDER = { CRITICAL: 4, HIGH: 3, MEDIUM: 2, LOW: 1 }
const byRisk = arr => [...arr].sort((a, b) => (RISK_ORDER[b.risk_level] || 0) - (RISK_ORDER[a.risk_level] || 0))

const open   = computed(() => byRisk(list.value.filter(a => a?.status === 'OPEN')))
const mine   = computed(() => byRisk(list.value.filter(a =>
  Number(a?.acknowledged_by_user_id) === myUid.value || Number(a?.current_owner_user_id) === myUid.value
)))

function alertHoursOld(a) {
  if (!a?.created_at) return 0
  return (Date.now() - new Date(a.created_at).getTime()) / 3600000
}
function slaRemainingH(a) {
  const hrs = alertHoursOld(a)
  if (!a?.acknowledged_at) return Math.max(0, 24 - hrs)
  const ackHrs = (Date.now() - new Date(a.acknowledged_at).getTime()) / 3600000
  return Math.max(0, 168 - ackHrs)
}
const slaRisky = computed(() => byRisk(list.value.filter(a => {
  if (a?.status === 'CLOSED') return false
  return a?.overdue_24h || alertHoursOld(a) > 18
})))

const visibleSet = computed(() =>
  tab.value === 'open' ? open.value : tab.value === 'mine' ? mine.value : slaRisky.value
)

// ── Formatters ────────────────────────────────────────────────────────────────
function relTime(s) {
  if (!s) return '—'
  const diff = (Date.now() - Date.parse(s)) / 1000
  if (isNaN(diff)) return s
  if (diff < 60)     return 'just now'
  if (diff < 3600)   return Math.floor(diff / 60) + 'm ago'
  if (diff < 86400)  return Math.floor(diff / 3600) + 'h ago'
  if (diff < 604800) return Math.floor(diff / 86400) + 'd ago'
  try { return new Date(s).toLocaleDateString() } catch { return s }
}
function fmtSla(a) {
  const h = slaRemainingH(a)
  if (h <= 0) return 'OVERDUE'
  if (h < 1)  return Math.round(h * 60) + 'm left'
  if (h < 24) return h.toFixed(0) + 'h left'
  return (h / 24).toFixed(1) + 'd left'
}
function traveller(a) {
  if (a?.traveler_full_name) return a.traveler_full_name
  const label = a?.traveler_label ?? ''
  if (label && !/^anon-/i.test(label) && !/^anonymous$/i.test(label)) return label
  return 'Identity not recorded'
}
function travellerMissing(a) { return !a?.traveler_full_name && !a?.traveler_initials }
function tempClass(a) {
  const v = Number(a?.temperature_value)
  if (!v) return ''
  if (v >= 38.5) return 'danger'
  if (v >= 37.5) return 'warn'
  return ''
}
function isOverdue(a) { return !!(a?.overdue_24h || alertHoursOld(a) > 24) && a?.status !== 'CLOSED' }
function riskColor(r) {
  return ({ CRITICAL: '#DC2626', HIGH: '#D97706', MEDIUM: '#6366F1', LOW: '#10B981' })[r] || '#94A3B8'
}
function riskBg(r) {
  return ({ CRITICAL: 'rgba(220,38,38,.08)', HIGH: 'rgba(217,119,6,.07)', MEDIUM: 'rgba(99,102,241,.07)', LOW: 'rgba(16,185,129,.07)' })[r] || 'rgba(15,23,42,.04)'
}

// ── Navigation ────────────────────────────────────────────────────────────────
function gotoWarRoom(a) {
  setTimeout(() => {
    if (document.activeElement instanceof HTMLElement) document.activeElement.blur()
    router.push({ name: 'AlertWarRoom', params: { id: a.id } })
  }, 0)
}
function gotoCaseFile(a) {
  setTimeout(() => {
    if (document.activeElement instanceof HTMLElement) document.activeElement.blur()
    const uuid = a.secondary_case_client_uuid
    if (uuid) {
      router.push({ name: 'SecondaryRecords', query: { open: uuid } })
    } else {
      router.push({ name: 'SecondaryRecords' })
    }
  }, 0)
}

// ── Actions ───────────────────────────────────────────────────────────────────
async function showToast(msg, color = 'medium') {
  const t = await toastController.create({ message: msg, duration: 2400, color, position: 'top' })
  await t.present()
}
async function quickClose(a) {
  closeAlertId.value = a.id
  await lc.loadCaseFile()
  closeFor.value = a
}
async function quickAck(a) {
  if (!isOnline.value) { showToast('You are offline. Connect to acknowledge.', 'warning'); return }
  const dlg = await alertController.create({
    header: 'Acknowledge alert?',
    message: `${a.alert_code || 'This alert'} — records you as the responding officer.`,
    buttons: [
      { text: 'Cancel', role: 'cancel' },
      {
        text: 'Acknowledge', role: 'confirm',
        handler: async () => {
          acking.value = a.id
          try {
            const res = await fetch(
              baseUrl().replace(/\/$/, '') + `/alerts/${a.id}/acknowledge?user_id=${myUid.value}`,
              { method: 'PATCH', headers: { Accept: 'application/json', 'Content-Type': 'application/json' }, body: JSON.stringify({ user_id: myUid.value }) }
            )
            if (res.ok) { showToast('Alert acknowledged.', 'success'); load() }
            else        { showToast('Acknowledge failed (HTTP ' + res.status + ').', 'danger') }
          } catch { showToast('Network error — please try again.', 'danger') }
          finally { acking.value = null }
        },
      },
    ],
  })
  await dlg.present()
}
async function onRefresh(ev) { await load(); ev.target.complete() }
</script>

<template>
  <ion-page>

    <!-- HTTP shimmer -->
    <div v-if="inFlight > 0" class="mc-shimmer-bar" aria-hidden="true">
      <div class="mc-shimmer-progress"/>
    </div>

    <!-- ── HEADER ─────────────────────────────────────────────────────────── -->
    <ion-header class="ion-no-border">
      <ion-toolbar class="mc-toolbar">
        <ion-buttons slot="start">
          <ion-menu-button auto-hide="false" class="mc-menu-btn"/>
        </ion-buttons>

        <div class="mc-hdr-center">
          <span class="mc-hdr-title">{{ t('My Cases') }}</span>
          <span v-if="!isOnline" class="mc-offline-pill">
            <ion-icon :icon="cloudOfflineOutline"/>Offline
          </span>
        </div>

        <ion-buttons slot="end" class="mc-hdr-end">
          <div v-if="outboxPending > 0" class="mc-sync-dot" :aria-label="`${outboxPending} pending sync`">
            <ion-icon :icon="syncOutline" class="mc-sync-ico"/>
            <span class="mc-sync-badge">{{ outboxPending }}</span>
          </div>
          <button class="mc-refresh-btn" :class="loading && 'mc-refresh-btn--spin'" :disabled="loading" @click="load()" aria-label="Refresh">
            <ion-icon :icon="refreshOutline"/>
          </button>
        </ion-buttons>
      </ion-toolbar>

      <!-- Tab strip -->
      <div class="mc-tabs" role="tablist">
        <button role="tab" :aria-selected="tab === 'open'"  :class="['mc-tab', tab === 'open'  && 'mc-tab--on']" @click="tab = 'open'">
          <span class="mc-tab-num" :class="open.length > 0 && 'mc-tab-num--amber'">{{ open.length }}</span>
          Open
        </button>
        <button role="tab" :aria-selected="tab === 'mine'"  :class="['mc-tab', tab === 'mine'  && 'mc-tab--on']" @click="tab = 'mine'">
          <span class="mc-tab-num">{{ mine.length }}</span>
          Mine
        </button>
        <button role="tab" :aria-selected="tab === 'sla'"   :class="['mc-tab', tab === 'sla'   && 'mc-tab--on']" @click="tab = 'sla'">
          <span class="mc-tab-num" :class="slaRisky.length > 0 && 'mc-tab-num--red'">{{ slaRisky.length }}</span>
          SLA risk
        </button>
      </div>
    </ion-header>

    <!-- ── CONTENT ────────────────────────────────────────────────────────── -->
    <ion-content :fullscreen="true" class="mc-content">
      <ion-refresher slot="fixed" @ionRefresh="onRefresh">
        <ion-refresher-content/>
      </ion-refresher>

      <!-- Offline notice -->
      <div v-if="!isOnline" class="mc-offline-notice">
        <ion-icon :icon="cloudOfflineOutline"/>
        Offline — cached data shown. Actions disabled.
      </div>

      <!-- Loading skeletons -->
      <div v-if="loading && !visibleSet.length" class="mc-skel-list">
        <div v-for="i in 4" :key="i" class="mc-skel">
          <div class="mc-skel-left"/>
          <div class="mc-skel-body">
            <div class="mc-skel-line" style="width:65%"/>
            <div class="mc-skel-line" style="width:45%;margin-top:6px"/>
            <div class="mc-skel-line" style="width:80%;margin-top:10px"/>
          </div>
        </div>
      </div>

      <!-- Empty state -->
      <div v-else-if="!visibleSet.length" class="mc-empty">
        <template v-if="tab === 'open'">
          <div class="mc-empty-icon mc-empty-icon--green">
            <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="16" cy="16" r="12"/><polyline points="10 16 14 20 22 12"/></svg>
          </div>
          <p class="mc-empty-h">All clear</p>
          <p class="mc-empty-s">No open alerts in your scope. Pull down to refresh.</p>
        </template>
        <template v-else-if="tab === 'mine'">
          <div class="mc-empty-icon mc-empty-icon--indigo">
            <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="16" cy="12" r="6"/><path d="M4 28c0-6.6 5.4-12 12-12s12 5.4 12 12"/></svg>
          </div>
          <p class="mc-empty-h">Nothing assigned</p>
          <p class="mc-empty-s">Alerts you acknowledge or are assigned to appear here.</p>
        </template>
        <template v-else>
          <div class="mc-empty-icon mc-empty-icon--green">
            <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="16" cy="16" r="12"/><polyline points="10 16 14 20 22 12"/></svg>
          </div>
          <p class="mc-empty-h">Within SLA</p>
          <p class="mc-empty-s">All active alerts are within the 7-1-7 response windows.</p>
        </template>
      </div>

      <!-- ── Case cards ─────────────────────────────────────────────────── -->
      <div v-else class="mc-list">
        <article
          v-for="a in visibleSet"
          :key="a.id"
          :class="['mc-card', isOverdue(a) && 'mc-card--overdue', a.status === 'CLOSED' && 'mc-card--closed']"
          :style="`--risk-color:${riskColor(a.risk_level)};--risk-bg:${riskBg(a.risk_level)}`"
          @click="gotoWarRoom(a)"
          role="button"
          :aria-label="`Alert ${a.alert_code}, ${a.risk_level} risk — tap to open war room`"
        >
          <!-- Left risk bar -->
          <div class="mc-risk-bar"/>

          <!-- Card body -->
          <div class="mc-card-body">

            <!-- Row 1: traveller name + time -->
            <div class="mc-row mc-row--head">
              <span :class="['mc-traveller', travellerMissing(a) && 'mc-traveller--dim']">
                {{ traveller(a) }}
              </span>
              <span class="mc-timeago">{{ relTime(a.created_at) }}</span>
            </div>

            <!-- Row 2: alert code + risk pill + status pill -->
            <div class="mc-row mc-row--pills">
              <code class="mc-code">{{ a.alert_code }}</code>
              <span class="mc-risk-pill">{{ a.risk_level }}</span>
              <span :class="['mc-status-pill', `mc-status-pill--${(a.status || '').toLowerCase()}`]">{{ a.status }}</span>
              <span v-if="isOverdue(a)" class="mc-overdue-tag">OVERDUE</span>
            </div>

            <!-- Row 3: disease + confidence -->
            <div v-if="a.top_disease_name || a.syndrome" class="mc-row mc-row--disease">
              <span class="mc-disease-dot"/>
              <span class="mc-disease-name">{{ a.top_disease_name || a.syndrome }}</span>
              <span v-if="a.top_disease_confidence != null" class="mc-disease-conf">{{ Math.round(Number(a.top_disease_confidence)) }}%</span>
            </div>

            <!-- Row 4: demographics strip -->
            <div class="mc-row mc-row--bio">
              <span v-if="a.traveler_gender" class="mc-chip">{{ a.traveler_gender === 'MALE' ? '♂' : a.traveler_gender === 'FEMALE' ? '♀' : '⚥' }} {{ a.traveler_gender[0] }}</span>
              <span v-if="a.traveler_age_years != null" class="mc-chip">{{ a.traveler_age_years }}y</span>
              <span v-if="a.traveler_nationality" class="mc-chip">{{ a.traveler_nationality }}</span>
              <span v-if="a.temperature_value" :class="['mc-chip', tempClass(a) === 'danger' && 'mc-chip--danger', tempClass(a) === 'warn' && 'mc-chip--warn']">
                {{ Number(a.temperature_value).toFixed(1) }}°C
              </span>
              <span v-if="a.poe_code" class="mc-chip mc-chip--dim">{{ a.poe_code }}</span>
              <span v-if="a.routed_to_level" class="mc-chip mc-chip--dim">→ {{ a.routed_to_level }}</span>
            </div>

            <!-- SLA row (SLA tab or overdue) -->
            <div v-if="tab === 'sla' || isOverdue(a)" :class="['mc-sla-row', isOverdue(a) && slaRemainingH(a) <= 0 && 'mc-sla-row--over']">
              <ion-icon :icon="flameOutline" class="mc-sla-ico"/>
              <span class="mc-sla-txt">{{ fmtSla(a) }}</span>
              <span class="mc-sla-sub">{{ !a.acknowledged_at ? '· awaiting acknowledgement' : '· response window' }}</span>
            </div>

            <!-- Ack row (Mine tab) -->
            <div v-if="tab === 'mine' && a.acknowledged_by_name" class="mc-ack-row">
              <ion-icon :icon="checkmarkCircle" class="mc-ack-ico"/>
              <span>Ack'd by {{ a.acknowledged_by_name }}</span>
            </div>

            <!-- ── Action footer ──────────────────────────────────────── -->
            <div class="mc-footer" @click.stop>
              <!-- Left: primary actions -->
              <div class="mc-footer-left">
                <button
                  v-if="a.status === 'OPEN' && can('alerts.acknowledge', a)"
                  :class="['mc-btn mc-btn--ack', (!isOnline || acking === a.id) && 'mc-btn--disabled']"
                  :disabled="acking === a.id || !isOnline"
                  @click="quickAck(a)"
                >
                  <div v-if="acking === a.id" class="mc-spinner"/>
                  <ion-icon v-else :icon="checkmarkCircle"/>
                  {{ acking === a.id ? 'Acknowledging…' : 'Acknowledge' }}
                </button>

                <button
                  v-if="a.status !== 'CLOSED' && can('alerts.close', a)"
                  :class="['mc-btn mc-btn--close', (!isOnline || acking === a.id) && 'mc-btn--disabled']"
                  :disabled="acking === a.id || !isOnline"
                  @click="quickClose(a)"
                >
                  <ion-icon :icon="lockClosedOutline"/>
                  Close
                </button>
              </div>

              <!-- Right: case file link -->
              <button class="mc-btn mc-btn--file" @click="gotoCaseFile(a)" aria-label="Open case file record">
                <ion-icon :icon="documentTextOutline"/>
                Case File
                <ion-icon :icon="arrowForwardOutline" class="mc-arr"/>
              </button>
            </div>

            <!-- Offline notice -->
            <div v-if="!isOnline && (a.status === 'OPEN' || a.status === 'ACKNOWLEDGED')" class="mc-offline-row">
              <ion-icon :icon="cloudOfflineOutline"/>
              Actions unavailable offline
            </div>
          </div>
        </article>
      </div>

      <div style="height:60px"/>
    </ion-content>

    <!-- Quick close wizard.
         Plain slide-up modal (NOT a sheet with breakpoints) — sheet-style
         IonModal has a known stick-on-dismiss bug on Android WebView when
         the slot v-if drops. Plain modal animates down cleanly every time.
         did-dismiss handles the post-animation reload. -->
    <ion-modal :is-open="closeOpen" @did-dismiss="onCloseModalDismissed">
      <SmartCloseWizard
        v-if="closeFor"
        :model-value="closeOpen"
        :case-file="lc.caseFile.value"
        :lifecycle="lc"
        @update:model-value="closeOpen = $event"
        @closed="closeOpen = false"
      />
    </ion-modal>

  </ion-page>
</template>

<style scoped>
/* ── Reset ───────────────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }

/* ── HTTP shimmer ─────────────────────────────────────────────────────────── */
.mc-shimmer-bar { position: fixed; top: 0; left: 0; right: 0; z-index: 9999; pointer-events: none; height: 3px; background: rgba(99,102,241,.12); }
.mc-shimmer-progress { height: 100%; background: linear-gradient(90deg, #6366F1, #818CF8, #6366F1); background-size: 200% 100%; animation: mc-shimmer 1.2s linear infinite; }
@keyframes mc-shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }

/* ── Toolbar ──────────────────────────────────────────────────────────────── */
.mc-toolbar {
  --background: #0B2545;
  --color: #fff;
  --border-width: 0;
  --padding-start: 4px;
  --padding-end: 4px;
}
.mc-menu-btn { --color: rgba(255,255,255,.75); }
.mc-hdr-center { flex: 1; display: flex; align-items: center; gap: 8px; padding: 0 8px; }
.mc-hdr-title { font-size: 18px; font-weight: 800; letter-spacing: -.01em; color: #fff; }
.mc-offline-pill { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 20px; background: rgba(252,165,165,.2); color: #fca5a5; font-size: 11px; font-weight: 700; }
.mc-hdr-end { gap: 4px; padding-right: 4px; }
.mc-sync-dot { position: relative; display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; }
.mc-sync-ico { font-size: 18px; color: #fbbf24; }
.mc-sync-badge { position: absolute; top: 4px; right: 4px; min-width: 16px; height: 16px; padding: 0 3px; border-radius: 8px; background: #f59e0b; color: #fff; font-size: 9px; font-weight: 800; display: flex; align-items: center; justify-content: center; }
.mc-refresh-btn { display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 8px; border: none; background: transparent; color: rgba(255,255,255,.75); cursor: pointer; }
.mc-refresh-btn--spin { animation: mc-spin 1s linear infinite; }
@keyframes mc-spin { to { transform: rotate(360deg); } }

/* ── Tab strip ────────────────────────────────────────────────────────────── */
.mc-tabs {
  display: flex;
  background: #0B2545;
  padding: 0 12px 8px;
  gap: 4px;
}
.mc-tab {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 7px 8px;
  border-radius: 10px;
  border: none;
  background: rgba(255,255,255,.08);
  color: rgba(255,255,255,.6);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all .15s;
}
.mc-tab--on { background: rgba(255,255,255,.18); color: #fff; }
.mc-tab-num { font-size: 16px; font-weight: 800; line-height: 1; }
.mc-tab-num--amber { color: #fbbf24; }
.mc-tab-num--red   { color: #f87171; }

/* ── Content area ─────────────────────────────────────────────────────────── */
.mc-content { --background: #F1F5F9; }

/* ── Offline notice ───────────────────────────────────────────────────────── */
.mc-offline-notice { display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: rgba(220,38,38,.07); color: #b91c1c; font-size: 12px; font-weight: 600; border-bottom: 1px solid rgba(220,38,38,.1); }

/* ── Skeleton ─────────────────────────────────────────────────────────────── */
.mc-skel-list { padding: 10px 12px; display: flex; flex-direction: column; gap: 10px; }
.mc-skel { display: flex; gap: 12px; background: #fff; border-radius: 16px; padding: 16px; box-shadow: 0 1px 4px rgba(15,23,42,.06); }
.mc-skel-left { width: 4px; border-radius: 2px; background: #E2E8F0; flex-shrink: 0; }
.mc-skel-body { flex: 1; }
.mc-skel-line { height: 13px; border-radius: 7px; background: linear-gradient(90deg, #F1F5F9 25%, #E2E8F0 50%, #F1F5F9 75%); background-size: 200% 100%; animation: mc-shimmer 1.4s infinite; }

/* ── Empty state ──────────────────────────────────────────────────────────── */
.mc-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; min-height: 300px; padding: 32px 24px; text-align: center; }
.mc-empty-icon { width: 56px; height: 56px; border-radius: 18px; display: flex; align-items: center; justify-content: center; }
.mc-empty-icon svg { width: 28px; height: 28px; }
.mc-empty-icon--green  { background: rgba(16,185,129,.12); color: #059669; }
.mc-empty-icon--indigo { background: rgba(99,102,241,.12); color: #4F46E5; }
.mc-empty-h { font-size: 18px; font-weight: 800; color: #0F172A; margin: 0; }
.mc-empty-s { font-size: 13px; color: #64748B; margin: 0; line-height: 1.55; max-width: 240px; }

/* ── Card list ────────────────────────────────────────────────────────────── */
.mc-list { padding: 10px 12px; display: flex; flex-direction: column; gap: 10px; }

/* ── Card ─────────────────────────────────────────────────────────────────── */
.mc-card {
  display: flex;
  background: #fff;
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 1px 4px rgba(15,23,42,.06), 0 1px 1px rgba(15,23,42,.04);
  border: 1px solid rgba(15,23,42,.05);
  cursor: pointer;
  transition: transform .12s, box-shadow .12s;
  -webkit-tap-highlight-color: transparent;
}
.mc-card:active { transform: scale(.99); box-shadow: 0 1px 2px rgba(15,23,42,.05); }
.mc-card--overdue { border-color: rgba(220,38,38,.15); }
.mc-card--closed  { opacity: .6; }

/* Left risk bar */
.mc-risk-bar { width: 4px; flex-shrink: 0; background: var(--risk-color, #94A3B8); }

/* Card body */
.mc-card-body { flex: 1; min-width: 0; padding: 14px 14px 12px; display: flex; flex-direction: column; gap: 8px; }

/* Rows */
.mc-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.mc-row--head { justify-content: space-between; flex-wrap: nowrap; gap: 8px; }
.mc-row--pills { gap: 5px; }
.mc-row--disease { gap: 6px; }
.mc-row--bio { gap: 4px; }

/* Traveller name */
.mc-traveller { font-size: 15px; font-weight: 700; color: #0F172A; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0; }
.mc-traveller--dim { color: #92400E; font-style: italic; }
.mc-timeago { font-size: 11px; color: #94A3B8; flex-shrink: 0; white-space: nowrap; }

/* Code */
.mc-code { font-family: ui-monospace, monospace; font-size: 11px; color: #64748B; background: #F1F5F9; padding: 2px 6px; border-radius: 5px; flex-shrink: 0; }

/* Risk pill */
.mc-risk-pill { font-size: 10px; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; padding: 2px 8px; border-radius: 6px; background: var(--risk-bg); color: var(--risk-color); flex-shrink: 0; }

/* Status pill */
.mc-status-pill { font-size: 10px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; padding: 2px 7px; border-radius: 20px; }
.mc-status-pill--open         { background: rgba(245,158,11,.12); color: #B45309; }
.mc-status-pill--acknowledged { background: rgba(99,102,241,.12); color: #4338CA; }
.mc-status-pill--closed       { background: rgba(16,185,129,.12); color: #047857; }

/* Overdue tag */
.mc-overdue-tag { font-size: 9px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; padding: 2px 6px; border-radius: 4px; background: rgba(220,38,38,.12); color: #DC2626; }

/* Disease row */
.mc-disease-dot { width: 6px; height: 6px; border-radius: 50%; background: #6366F1; flex-shrink: 0; }
.mc-disease-name { font-size: 13px; font-weight: 600; color: #1E293B; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mc-disease-conf { font-size: 11px; font-weight: 600; color: #6366F1; flex-shrink: 0; }

/* Bio chips */
.mc-chip { display: inline-flex; align-items: center; padding: 2px 7px; border-radius: 6px; background: #F1F5F9; font-size: 11px; font-weight: 500; color: #475569; white-space: nowrap; }
.mc-chip--warn   { background: rgba(245,158,11,.14); color: #92400E; }
.mc-chip--danger { background: rgba(220,38,38,.14); color: #991B1B; font-weight: 700; }
.mc-chip--dim    { opacity: .65; }

/* SLA row */
.mc-sla-row { display: flex; align-items: center; gap: 5px; padding: 5px 8px; background: rgba(245,158,11,.08); border-radius: 8px; font-size: 12px; color: #92400E; }
.mc-sla-row--over { background: rgba(220,38,38,.09); color: #DC2626; }
.mc-sla-ico { font-size: 13px; flex-shrink: 0; }
.mc-sla-txt { font-weight: 800; }
.mc-sla-sub { font-size: 11px; opacity: .75; }

/* Ack row */
.mc-ack-row { display: flex; align-items: center; gap: 5px; font-size: 12px; color: #047857; padding: 4px 8px; background: rgba(16,185,129,.08); border-radius: 7px; }
.mc-ack-ico { font-size: 14px; flex-shrink: 0; }

/* ── Action footer ────────────────────────────────────────────────────────── */
.mc-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  padding-top: 10px;
  margin-top: 2px;
  border-top: 1px solid #F1F5F9;
}
.mc-footer-left { display: flex; align-items: center; gap: 6px; }

/* Shared button base */
.mc-btn {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 8px 13px;
  border-radius: 10px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  border: none;
  transition: opacity .12s, transform .1s;
  white-space: nowrap;
  -webkit-tap-highlight-color: transparent;
}
.mc-btn:active { transform: scale(.96); }
.mc-btn--disabled { opacity: .38; cursor: not-allowed; }

/* Acknowledge — filled green */
.mc-btn--ack { background: #10B981; color: #fff; }

/* Close — ghost slate */
.mc-btn--close { background: #F1F5F9; color: #475569; border: 1px solid #E2E8F0; }

/* Case file — ghost indigo, right-aligned */
.mc-btn--file { background: #EEF2FF; color: #4338CA; border: 1px solid #C7D2FE; margin-left: auto; }
.mc-arr { font-size: 12px; margin-left: -2px; }

/* Spinner inside buttons */
.mc-spinner { width: 13px; height: 13px; border: 2px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: mc-spin .7s linear infinite; flex-shrink: 0; }

/* Offline action row */
.mc-offline-row { display: flex; align-items: center; gap: 6px; font-size: 11px; color: #DC2626; padding-top: 4px; }
</style>
