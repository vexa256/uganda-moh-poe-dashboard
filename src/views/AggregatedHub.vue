<template>
  <IonPage>
    <!-- ═══ HEADER ═════════════════════════════════════════════════════ -->
    <IonHeader class="ah-hdr" translucent>
      <div class="ah-hdr-bg">
        <div class="ah-hdr-top">
          <IonButtons slot="start"><IonMenuButton menu="app-menu" class="ah-menu"/></IonButtons>
          <div class="ah-hdr-title">
            <span class="ah-hdr-eye">{{ auth.poe_code || 'POE' }} · {{ auth.country_code || TENANT_CC }}</span>
            <span class="ah-hdr-h1">Reports</span>
          </div>
          <IonButtons slot="end">
            <button v-if="isAdmin" class="ah-admin-btn" @click="gotoWizard" aria-label="Create report template">
              <IonIcon :icon="addCircleOutline" slot="icon-only"/>
            </button>
            <button class="ah-ref" :class="syncing && 'ah-spin'" :disabled="syncing" @click="refresh" aria-label="Refresh">
              <IonIcon :icon="refreshOutline" slot="icon-only"/>
            </button>
          </IonButtons>
        </div>

        <!-- Sync / offline status pill -->
        <div class="ah-meta">
          <span :class="['ah-pill', isOnline ? 'ah-pill--on' : 'ah-pill--off']">
            <span class="ah-dot"/>
            {{ isOnline ? 'Live' : 'Offline — using cached templates' }}
          </span>
          <span v-if="lastSyncAt" class="ah-sync-txt">Synced {{ fmtRelative(lastSyncAt) }}</span>
        </div>
      </div>
    </IonHeader>

    <!-- ═══ CONTENT ═══════════════════════════════════════════════════ -->
    <IonContent class="ah-content" :fullscreen="true">
      <IonRefresher slot="fixed" @ionRefresh="pull($event)"><IonRefresherContent/></IonRefresher>

      <!-- Loading skeleton -->
      <div v-if="loading && templates.length === 0" class="ah-skel-wrap">
        <div v-for="i in 3" :key="i" class="ah-skel">
          <div class="ah-skel-icon"/>
          <div class="ah-skel-body">
            <div class="ah-skel-line ah-skel-line--t"/>
            <div class="ah-skel-line ah-skel-line--s"/>
          </div>
        </div>
      </div>

      <!-- Empty state -->
      <div v-else-if="templates.length === 0" class="ah-empty">
        <div class="ah-empty-ic">
          <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="2" width="64" height="64"><rect x="12" y="8" width="40" height="48" rx="3"/><line x1="20" y1="20" x2="44" y2="20"/><line x1="20" y1="28" x2="44" y2="28"/><line x1="20" y1="36" x2="36" y2="36"/></svg>
        </div>
        <div class="ah-empty-t">No reports yet</div>
        <div class="ah-empty-s">
          <template v-if="isAdmin">Tap the + button to create your first report template. Published templates appear instantly at every POE in your country.</template>
          <template v-else>Your national administrator hasn't published any report templates yet.</template>
        </div>
        <button v-if="isAdmin" class="ah-empty-cta" @click="gotoWizard">
          <IonIcon :icon="addCircleOutline"/> Create report template
        </button>
      </div>

      <!-- ═══ REPORT CARDS ═════════════════════════════════════════════ -->
      <section v-else>
        <div class="ah-summary">
          <div class="ah-summary-kv"><span class="ah-summary-n">{{ templates.length }}</span><span class="ah-summary-l">Published</span></div>
          <div class="ah-summary-kv"><span class="ah-summary-n">{{ totalDraftsSubmissions }}</span><span class="ah-summary-l">Drafts saved locally</span></div>
          <div class="ah-summary-kv"><span class="ah-summary-n">{{ totalPendingSync }}</span><span class="ah-summary-l">Awaiting sync</span></div>
        </div>

        <h3 class="ah-sh">Available reports</h3>
        <div class="ah-cards">
          <article v-for="(t, i) in templates" :key="t.id"
            :class="['ah-card', `ah-card--${(t.reporting_frequency || 'WEEKLY').toLowerCase()}`]"
            :style="{ '--card-accent': t.colour || accentFor(t.reporting_frequency), 'animation-delay': (i * 40) + 'ms' }"
            @click="openSubmission(t)">
            <div class="ah-card-stripe" :style="{ background: t.colour || accentFor(t.reporting_frequency) }"/>
            <div class="ah-card-body">
              <div class="ah-card-row1">
                <span class="ah-card-badge">{{ freqLabel(t.reporting_frequency) }}</span>
                <span v-if="t.is_default" class="ah-card-badge ah-card-badge--who">WHO Baseline</span>
                <span v-if="hasLocalDraft(t.id)" class="ah-card-badge ah-card-badge--draft">Draft saved</span>
                <span v-if="pendingSyncCount(t.id) > 0" class="ah-card-badge ah-card-badge--pending">{{ pendingSyncCount(t.id) }} pending</span>
              </div>
              <h2 class="ah-card-title">{{ t.template_name }}</h2>
              <p v-if="t.description" class="ah-card-desc">{{ truncate(t.description, 120) }}</p>
              <div class="ah-card-meta">
                <span class="ah-card-chip"><IonIcon :icon="listOutline"/> {{ t.columns_enabled || t.columns?.length || 0 }} fields</span>
                <span class="ah-card-chip"><IonIcon :icon="checkmarkCircleOutline"/> v{{ t.version }}</span>
                <span v-if="lastSubmissionFor(t.id)" class="ah-card-chip"><IonIcon :icon="timeOutline"/> Last: {{ fmtRelative(lastSubmissionFor(t.id)) }}</span>
              </div>
            </div>
            <div class="ah-card-action">
              <IonIcon :icon="chevronForwardOutline"/>
            </div>
          </article>
        </div>

        <!-- Past submissions strip -->
        <h3 class="ah-sh">Recent submissions</h3>
        <div v-if="recentSubmissions.length === 0" class="ah-none">No submissions yet on this device.</div>
        <div v-else class="ah-hist">
          <article v-for="s in recentSubmissions" :key="s.client_uuid" class="ah-hist-row" @click="viewSubmission(s)">
            <span :class="['ah-hist-dot', `ah-hist-dot--${s.sync_status.toLowerCase()}`]"/>
            <div class="ah-hist-body">
              <div class="ah-hist-title">{{ templateNameFor(s.template_id) || 'Aggregated report' }}</div>
              <div class="ah-hist-meta">
                {{ fmtDate(s.period_start) }} → {{ fmtDate(s.period_end) }}
                · <span class="ah-hist-n">{{ s.total_screened || 0 }}</span> screened
              </div>
            </div>
            <span :class="['ah-hist-status', `ah-hist-status--${s.sync_status.toLowerCase()}`]">{{ SYNC.LABELS[s.sync_status] || s.sync_status }}</span>
          </article>
        </div>

        <!-- Admin quick actions -->
        <div v-if="isAdmin" class="ah-admin-bar">
          <button class="ah-admin-card" @click="gotoWizard">
            <IonIcon :icon="addCircleOutline" class="ah-admin-ic"/>
            <div class="ah-admin-t">Create new report</div>
            <div class="ah-admin-s">5-step wizard · publish to every POE</div>
          </button>
          <button class="ah-admin-card" @click="gotoTemplates">
            <IonIcon :icon="layersOutline" class="ah-admin-ic"/>
            <div class="ah-admin-t">Manage templates</div>
            <div class="ah-admin-s">Edit columns · retire · version</div>
          </button>
        </div>
      </section>

      <div style="height:64px"/>
    </IonContent>

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="2800" position="top" @didDismiss="toast.show=false"/>
  </IonPage>
</template>

<script setup>
import {
  IonPage, IonHeader, IonContent, IonButtons, IonMenuButton,
  IonIcon, IonRefresher, IonRefresherContent, IonToast,
  onIonViewDidEnter, onIonViewWillEnter,
} from '@ionic/vue'
import {
  refreshOutline, addCircleOutline, checkmarkCircleOutline,
  listOutline, timeOutline, chevronForwardOutline, layersOutline,
} from 'ionicons/icons'
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  dbGetAll, dbGetByIndex, dbPut, dbPutBatch, dbDeleteByIndex,
  STORE, SYNC, APP,
} from '@/services/poeDB'

const router = useRouter()

function getAuth() { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {} }
const auth = ref(getAuth())
// Tenant country — sourced from main.ts (window.COUNTRY_CODE driven by
// VITE_COUNTRY_CODE). Replaces fork-residue 'UG' fallbacks throughout.
const TENANT_CC = (typeof window !== 'undefined' && window.COUNTRY_CODE) || 'UG'
const isAdmin = computed(() => (auth.value?.role_key || '') === 'NATIONAL_ADMIN')
const countryCode = computed(() => auth.value?.country_code || TENANT_CC)

// ── State ───────────────────────────────────────────────────────────────────
const templates = ref([])            // published templates (from IDB cache, overlaid by server)
const submissions = ref([])           // local submissions for past-submissions strip
const loading = ref(true)
const syncing = ref(false)
const isOnline = ref(navigator.onLine)
const lastSyncAt = ref(localStorage.getItem('agg_hub_last_sync') || null)
const toast = reactive({ show: false, msg: '', color: 'success' })
let pollTimer = null

// ── Computeds ───────────────────────────────────────────────────────────────
const totalDraftsSubmissions = computed(() =>
  submissions.value.filter(s => s.is_local_draft && !s.deleted_at).length,
)
const totalPendingSync = computed(() =>
  submissions.value.filter(s => s.sync_status !== SYNC.SYNCED && !s.deleted_at).length,
)
const recentSubmissions = computed(() =>
  [...submissions.value]
    .filter(s => !s.deleted_at)
    .sort((a, b) => (b.created_at || '').localeCompare(a.created_at || ''))
    .slice(0, 5),
)

// ── Cache-first load ────────────────────────────────────────────────────────
async function loadFromCache() {
  try {
    const cached = await dbGetByIndex(STORE.AGGREGATED_TEMPLATES_CACHE, 'country_code', countryCode.value).catch(() => [])
    templates.value = cached.filter(t => t.status === 'PUBLISHED').sort(sortByFreq)
  } catch { templates.value = [] }
  try {
    const subs = await dbGetByIndex(STORE.AGGREGATED_SUBMISSIONS, 'poe_code', auth.value?.poe_code || '').catch(() => [])
    submissions.value = subs
  } catch { submissions.value = [] }
}

async function syncTemplatesFromServer(silent = false) {
  if (!isOnline.value) return
  if (!silent) syncing.value = true
  try {
    const url = `${window.SERVER_URL}/aggregated-templates/published?user_id=${auth.value?.id}&country_code=${encodeURIComponent(countryCode.value)}`
    const ctrl = new AbortController()
    const tid  = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS)
    const res  = await fetch(url, { headers: { Accept: 'application/json' }, signal: ctrl.signal })
    clearTimeout(tid)
    if (!res.ok) return
    const body = await res.json().catch(() => null)
    if (!body?.success) return

    const remote = body.data || []
    // Fresh, full overwrite of published cache for this country — admin
    // publish/retire propagates instantly because cache reflects exactly
    // what is PUBLISHED server-side.
    await dbDeleteByIndex(STORE.AGGREGATED_TEMPLATES_CACHE, 'country_code', countryCode.value).catch(() => {})
    const rows = remote.map(t => ({
      id: t.id,
      country_code: t.country_code,
      template_code: t.template_code,
      template_name: t.template_name,
      description: t.description,
      version: t.version,
      status: t.status,
      is_default: t.is_default ? 1 : 0,
      is_active: t.is_active ? 1 : 0,
      reporting_frequency: t.reporting_frequency,
      icon: t.icon,
      colour: t.colour,
      columns_total: t.columns_total,
      columns_enabled: t.columns_enabled,
      columns: t.columns || [],
      cached_at: new Date().toISOString(),
    }))
    if (rows.length) await dbPutBatch(STORE.AGGREGATED_TEMPLATES_CACHE, rows)

    templates.value = rows.filter(t => t.status === 'PUBLISHED').sort(sortByFreq)
    const now = new Date().toISOString()
    localStorage.setItem('agg_hub_last_sync', now)
    lastSyncAt.value = now
    if (!silent) showToast(`Synced ${rows.length} report${rows.length === 1 ? '' : 's'}`, 'success')
  } catch (e) {
    if (!silent) showToast('Offline or server unavailable — using cached templates', 'warning')
  } finally {
    if (!silent) syncing.value = false
  }
}

async function refresh() {
  await syncTemplatesFromServer(false)
  await loadFromCache()
}

async function pull(ev) {
  await refresh()
  ev.target.complete()
}

// ── Navigation ──────────────────────────────────────────────────────────────
// Before every router.push we blur the active element. Without this, Ionic
// applies aria-hidden="true" to the outgoing <ion-page> while a button inside
// it still holds focus — which browsers + Ionic now flag as an accessibility
// violation in the console. Blurring first breaks the chain cleanly.
function blurActive() {
  try { if (document.activeElement instanceof HTMLElement) document.activeElement.blur() } catch (_) {}
}
function openSubmission(t) {
  blurActive()
  // Audit AH-001: validate the template id before pushing — bad ids used to
  // route to a blank wizard. Templates always carry a server-assigned
  // positive integer id; anything else (null, 0, NaN, string) is data
  // corruption upstream and we surface it instead of silently navigating.
  const id = Number(t?.id)
  if (!Number.isFinite(id) || id <= 0) {
    console.warn('[AggregatedHub] openSubmission called with invalid template id', t)
    return
  }
  router.push(`/aggregated-data/new/${id}`)
}
function viewSubmission(s) {
  blurActive()
  router.push('/aggregated-data/history')
}
function gotoWizard()    { blurActive(); router.push('/admin/aggregated-wizard') }
function gotoTemplates() { blurActive(); router.push('/admin/aggregated-templates') }

// ── Helpers ─────────────────────────────────────────────────────────────────
const FREQ_ORDER = { DAILY: 1, WEEKLY: 2, MONTHLY: 3, QUARTERLY: 4, AD_HOC: 5, EVENT: 6 }
function sortByFreq(a, b) {
  const da = FREQ_ORDER[a.reporting_frequency] ?? 99
  const db = FREQ_ORDER[b.reporting_frequency] ?? 99
  if (da !== db) return da - db
  return (a.template_name || '').localeCompare(b.template_name || '')
}
function freqLabel(f) {
  return { DAILY: 'Daily', WEEKLY: 'Weekly', MONTHLY: 'Monthly', QUARTERLY: 'Quarterly', AD_HOC: 'Ad-hoc', EVENT: 'Event-based' }[f] || 'Report'
}
function accentFor(f) {
  return { DAILY: '#059669', WEEKLY: '#1E40AF', MONTHLY: '#9333EA', QUARTERLY: '#CA8A04', AD_HOC: '#64748B', EVENT: '#DC2626' }[f] || '#1E40AF'
}
function templateNameFor(tid) {
  const t = templates.value.find(x => x.id === tid)
  return t?.template_name
}
function hasLocalDraft(tid) {
  return submissions.value.some(s => s.template_id === tid && s.is_local_draft && !s.deleted_at)
}
function pendingSyncCount(tid) {
  return submissions.value.filter(s => s.template_id === tid && s.sync_status !== SYNC.SYNCED && !s.deleted_at && !s.is_local_draft).length
}
function lastSubmissionFor(tid) {
  const last = submissions.value.filter(s => s.template_id === tid && !s.deleted_at).sort((a, b) => (b.created_at || '').localeCompare(a.created_at || ''))[0]
  return last?.created_at
}
function truncate(s, n) { return s && s.length > n ? s.slice(0, n) + '…' : s }
function fmtDate(dt) { if (!dt) return ''; try { return new Date(String(dt).replace(' ', 'T')).toLocaleDateString([], { day: '2-digit', month: 'short' }) } catch { return dt } }
function fmtRelative(dt) {
  if (!dt) return ''
  const then = new Date(String(dt).replace(' ', 'T')).getTime()
  const secs = Math.round((Date.now() - then) / 1000)
  if (secs < 60) return 'just now'
  if (secs < 3600) return Math.round(secs / 60) + 'm ago'
  if (secs < 86400) return Math.round(secs / 3600) + 'h ago'
  return Math.round(secs / 86400) + 'd ago'
}
function showToast(msg, color = 'success') { toast.show = true; toast.msg = msg; toast.color = color }

// ── Lifecycle ───────────────────────────────────────────────────────────────
function onOnline()  { isOnline.value = true;  syncTemplatesFromServer(true) }
function onOffline() { isOnline.value = false }

onMounted(async () => {
  auth.value = getAuth()
  window.addEventListener('online',  onOnline)
  window.addEventListener('offline', onOffline)
  await loadFromCache()
  loading.value = false
  if (isOnline.value) syncTemplatesFromServer(true).then(loadFromCache)
  // Poll for template changes every 30s so admin publish/retire propagates.
  pollTimer = setInterval(() => {
    if (isOnline.value && !syncing.value) syncTemplatesFromServer(true).then(loadFromCache)
  }, 30_000)
})
onIonViewWillEnter(() => { auth.value = getAuth() })
onIonViewDidEnter(async () => {
  await loadFromCache()
  if (isOnline.value) syncTemplatesFromServer(true).then(loadFromCache)
})
onUnmounted(() => {
  window.removeEventListener('online',  onOnline)
  window.removeEventListener('offline', onOffline)
  clearInterval(pollTimer)
})
</script>

<style scoped>
* { box-sizing: border-box }

/* Header */
.ah-hdr { --background: transparent; border: none }
.ah-hdr-bg { background: linear-gradient(135deg, #001D3D 0%, #003566 50%, #003F88 100%); padding: 0 0 10px }
.ah-hdr-top { display: flex; align-items: center; gap: 4px; padding: 8px 8px 0 }
.ah-menu { --color: rgba(255,255,255,.75) }
.ah-hdr-title { flex: 1; display: flex; flex-direction: column; min-width: 0 }
.ah-hdr-eye { font-size: 9px; font-weight: 700; color: rgba(255,255,255,.5); text-transform: uppercase; letter-spacing: 1.4px }
.ah-hdr-h1 { font-size: 18px; font-weight: 800; color: #fff; letter-spacing: -.3px }
.ah-admin-btn, .ah-ref {
  width: 32px; height: 32px; border-radius: 50%; border: 1px solid rgba(255,255,255,.1);
  background: rgba(255,255,255,.06); color: rgba(255,255,255,.78);
  display: flex; align-items: center; justify-content: center; margin-right: 4px;
  cursor: pointer;
}
.ah-admin-btn { background: rgba(96,165,250,.25) }
.ah-admin-btn:active { transform: scale(.94) }
.ah-ref:disabled { opacity: .45 }
.ah-spin { animation: ah-rotate 1s linear infinite }
@keyframes ah-rotate { to { transform: rotate(360deg) } }

.ah-meta { display: flex; gap: 10px; align-items: center; padding: 6px 12px 0 }
.ah-pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 9px; border-radius: 99px; font-size: 10.5px; font-weight: 700; letter-spacing: .3px }
.ah-pill--on { background: rgba(16,185,129,.18); color: #6EE7B7 }
.ah-pill--off { background: rgba(220,38,38,.18); color: #FCA5A5 }
.ah-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; box-shadow: 0 0 8px currentColor }
.ah-sync-txt { font-size: 10px; color: rgba(255,255,255,.55); font-weight: 600 }

/* Content */
.ah-content { --background: #F0F4FA }
.ah-sh { font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 1px; margin: 16px 16px 8px }

/* Summary strip */
.ah-summary { display: flex; gap: 8px; padding: 12px 10px 0 }
.ah-summary-kv {
  flex: 1; padding: 10px 12px; background: #fff; border: 1px solid #E8EDF5; border-radius: 10px;
  display: flex; flex-direction: column; gap: 2px; box-shadow: 0 1px 2px rgba(0,0,0,.03);
}
.ah-summary-n { font-size: 18px; font-weight: 900; color: #1A3A5C; line-height: 1; font-variant-numeric: tabular-nums }
.ah-summary-l { font-size: 9.5px; font-weight: 700; color: #64748B; text-transform: uppercase; letter-spacing: .4px }

/* Cards */
.ah-cards { padding: 0 10px; display: flex; flex-direction: column; gap: 10px }
.ah-card {
  position: relative; background: #fff; border: 1px solid #E8EDF5; border-radius: 14px;
  padding: 0; overflow: hidden; cursor: pointer;
  box-shadow: 0 2px 6px rgba(26,58,92,.05), 0 0 0 0 var(--card-accent);
  transition: transform .15s, box-shadow .15s;
  display: flex; align-items: stretch;
  opacity: 0; animation: ah-card-in .35s ease-out forwards;
}
@keyframes ah-card-in { from { opacity: 0; transform: translateY(6px) } to { opacity: 1; transform: translateY(0) } }
.ah-card:active { transform: scale(.992); box-shadow: 0 2px 10px rgba(26,58,92,.1), 0 0 0 2px var(--card-accent) }
.ah-card-stripe { width: 4px; flex-shrink: 0 }
.ah-card-body { flex: 1; min-width: 0; padding: 14px 14px }
.ah-card-action {
  display: flex; align-items: center; padding: 0 12px; color: #94A3B8; flex-shrink: 0;
  font-size: 22px; transition: color .2s, transform .2s;
}
.ah-card:hover .ah-card-action, .ah-card:active .ah-card-action { color: var(--card-accent); transform: translateX(3px) }

.ah-card-row1 { display: flex; gap: 5px; flex-wrap: wrap; margin-bottom: 8px }
.ah-card-badge {
  font-size: 9px; font-weight: 800; padding: 2px 8px; border-radius: 4px;
  background: #F1F5F9; color: #475569; letter-spacing: .3px; text-transform: uppercase;
}
.ah-card-badge--who { background: #EDE9FE; color: #6D28D9 }
.ah-card-badge--draft { background: #FEF3C7; color: #854D0E }
.ah-card-badge--pending { background: #FFEDD5; color: #9A3412 }
.ah-card-title { font-size: 15px; font-weight: 800; color: #1A3A5C; line-height: 1.3; margin: 0 0 4px }
.ah-card-desc { font-size: 12px; color: #64748B; line-height: 1.45; margin: 0 0 8px }
.ah-card-meta { display: flex; flex-wrap: wrap; gap: 8px }
.ah-card-chip { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; color: #475569; font-weight: 600 }
.ah-card-chip ion-icon { font-size: 12px; color: var(--card-accent) }

/* Skeleton */
.ah-skel-wrap { padding: 12px 10px; display: flex; flex-direction: column; gap: 10px }
.ah-skel { background: #fff; border: 1px solid #E8EDF5; border-radius: 14px; padding: 14px; display: flex; gap: 12px; align-items: center }
.ah-skel-icon { width: 36px; height: 36px; border-radius: 8px; background: #E2E8F0; flex-shrink: 0 }
.ah-skel-body { flex: 1; display: flex; flex-direction: column; gap: 6px }
.ah-skel-line { height: 10px; border-radius: 3px; background: linear-gradient(90deg, #E2E8F0 25%, #F1F5F9 50%, #E2E8F0 75%); background-size: 200% 100%; animation: ah-sh 1.4s linear infinite }
.ah-skel-line--t { width: 70% }
.ah-skel-line--s { width: 40% }
@keyframes ah-sh { 0% { background-position: 200% 0 } 100% { background-position: -200% 0 } }

/* Empty */
.ah-empty { display: flex; flex-direction: column; align-items: center; padding: 60px 24px; gap: 12px; text-align: center }
.ah-empty-ic { color: #CBD5E1 }
.ah-empty-t { font-size: 17px; font-weight: 800; color: #1A3A5C }
.ah-empty-s { font-size: 12px; color: #64748B; line-height: 1.5; max-width: 300px }
.ah-empty-cta {
  margin-top: 6px; padding: 10px 18px; border-radius: 10px; background: #1E40AF; color: #fff;
  border: none; font-size: 12.5px; font-weight: 800; cursor: pointer; display: inline-flex; align-items: center; gap: 8px;
}
.ah-empty-cta ion-icon { font-size: 16px }

/* History strip */
.ah-none { padding: 10px 20px; font-size: 12px; color: #94A3B8 }
.ah-hist { padding: 0 10px; display: flex; flex-direction: column; gap: 6px }
.ah-hist-row { display: flex; align-items: center; gap: 10px; padding: 10px 12px; background: #fff; border: 1px solid #E8EDF5; border-radius: 10px; cursor: pointer }
.ah-hist-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0 }
.ah-hist-dot--synced { background: #10B981 }
.ah-hist-dot--unsynced { background: #EA580C }
.ah-hist-dot--failed { background: #DC2626 }
.ah-hist-body { flex: 1; min-width: 0 }
.ah-hist-title { font-size: 12.5px; font-weight: 700; color: #1A3A5C; white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
.ah-hist-meta { font-size: 10.5px; color: #64748B; font-weight: 600; margin-top: 2px }
.ah-hist-n { font-weight: 800; color: #1A3A5C; font-variant-numeric: tabular-nums }
.ah-hist-status { font-size: 9.5px; font-weight: 800; padding: 2px 7px; border-radius: 4px; letter-spacing: .3px }
.ah-hist-status--synced { background: #D1FAE5; color: #047857 }
.ah-hist-status--unsynced { background: #FFEDD5; color: #9A3412 }
.ah-hist-status--failed { background: #FEE2E2; color: #991B1B }

/* Admin bar */
.ah-admin-bar { padding: 16px 10px 0; display: grid; grid-template-columns: 1fr 1fr; gap: 10px }
.ah-admin-card {
  padding: 14px; background: #fff; border: 1px solid #E8EDF5; border-radius: 12px;
  display: flex; flex-direction: column; gap: 3px; align-items: flex-start; cursor: pointer;
  text-align: left; transition: border-color .2s, box-shadow .2s;
}
.ah-admin-card:active { border-color: #1E40AF; box-shadow: 0 0 0 3px rgba(30,64,175,.12) }
.ah-admin-ic { font-size: 22px; color: #1E40AF; margin-bottom: 4px }
.ah-admin-t { font-size: 12.5px; font-weight: 800; color: #1A3A5C }
.ah-admin-s { font-size: 10px; color: #64748B; font-weight: 600 }

@media (min-width: 540px) {
  .ah-cards, .ah-summary, .ah-hist, .ah-admin-bar { max-width: 540px; margin-left: auto; margin-right: auto }
}
</style>
