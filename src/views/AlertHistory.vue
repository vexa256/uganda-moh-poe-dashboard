<template>
  <IonPage>
    <IonHeader class="ah-hdr" translucent>
      <div class="ah-hdr-bg">
        <div class="ah-hdr-top">
          <IonButtons slot="start"><IonMenuButton menu="app-menu" class="ah-menu"/></IonButtons>
          <div class="ah-hdr-title">
            <span class="ah-eye">{{ scope.label }} · {{ scope.code || '—' }} · IHR Audit</span>
            <span class="ah-h1">Alert History</span>
          </div>
          <IonButtons slot="end">
            <button class="ah-nav" @click="gotoMatrix" aria-label="WHO matrix"><IonIcon :icon="bookOutline" slot="icon-only"/></button>
            <button class="ah-ref" @click="loadAlerts(true)" :disabled="loading"><IonIcon :icon="refreshOutline" slot="icon-only"/></button>
          </IonButtons>
        </div>

        <!-- Stats strip -->
        <div class="ah-stats">
          <div class="ah-st"><span class="ah-st-n">{{ stats.total }}</span><span class="ah-st-l">Total</span></div>
          <div class="ah-st ah-st--c"><span class="ah-st-n">{{ stats.critical }}</span><span class="ah-st-l">Critical</span></div>
          <div class="ah-st ah-st--h"><span class="ah-st-n">{{ stats.high }}</span><span class="ah-st-l">High</span></div>
          <div class="ah-st ah-st--g"><span class="ah-st-n">{{ stats.closed }}</span><span class="ah-st-l">Closed</span></div>
          <div class="ah-st ah-st--t"><span class="ah-st-n">{{ rollup.ihr.tier1 + rollup.ihr.tier2 }}</span><span class="ah-st-l">IHR Tier</span></div>
          <div class="ah-st ah-st--b" :class="rollup.breaches.total > 0 && 'ah-st--bad'"><span class="ah-st-n">{{ rollup.breaches.total }}</span><span class="ah-st-l">Breach</span></div>
        </div>

        <!-- Filter pills -->
        <div class="ah-fr">
          <button v-for="f in PERIODS" :key="f.v" :class="['ah-fp',period===f.v&&'ah-fp--on']" @click="period=f.v;loadAlerts(true)">{{ f.l }}</button>
          <button :class="['ah-fp',advOpen&&'ah-fp--on']" @click="advOpen=!advOpen">More</button>
        </div>

        <transition name="ah-sl"><div v-if="advOpen" class="ah-adv">
          <div class="ah-ar"><span class="ah-al">Risk</span><div class="ah-ap">
            <button v-for="r in ['CRITICAL','HIGH','MEDIUM','LOW']" :key="r" :class="['ah-pill',riskFilter===r&&'ah-pill--on']" @click="riskFilter=riskFilter===r?null:r;loadAlerts(true)">{{ r }}</button>
          </div></div>
          <div class="ah-ar"><span class="ah-al">Level</span><div class="ah-ap">
            <button v-for="r in ['DISTRICT','PHEOC','NATIONAL']" :key="r" :class="['ah-pill',routeFilter===r&&'ah-pill--on']" @click="routeFilter=routeFilter===r?null:r;loadAlerts(true)">{{ r }}</button>
          </div></div>
          <div class="ah-ar"><span class="ah-al">From</span><input type="date" v-model="dateFrom" class="ah-ai" @change="loadAlerts(true)"/></div>
          <div class="ah-ar"><span class="ah-al">To</span><input type="date" v-model="dateTo" class="ah-ai" @change="loadAlerts(true)"/></div>
          <div class="ah-ar" v-if="hasFilters">
            <button class="ah-clear" @click="clearFilters">Clear all</button>
          </div>
        </div></transition>
      </div>
    </IonHeader>

    <IonContent class="ah-content" :fullscreen="true">
      <IonRefresher slot="fixed" @ionRefresh="pullRefresh($event)"><IonRefresherContent pulling-text="Pull to refresh" refreshing-spinner="crescent"/></IonRefresher>

      <div v-if="!isOnline" class="ah-offline">Offline — showing cached history</div>

      <!-- Compliance summary (historical 7-1-7 performance) -->
      <div v-if="alerts.length > 0" class="ah-compl">
        <div class="ah-compl-hdr">
          <span class="ah-compl-t">7-1-7 compliance (historical)</span>
        </div>
        <div class="ah-compl-rows">
          <div class="ah-compl-row">
            <span class="ah-compl-l">Notify 24h</span>
            <div class="ah-compl-bar"><div class="ah-compl-f ah-compl-f--ok" :style="{ width: (rollup.notify.rate_pct ?? 0) + '%' }"/></div>
            <span :class="['ah-compl-n', (rollup.notify.rate_pct ?? 0) < 80 && 'ah-compl-n--bad']">{{ rollup.notify.rate_pct == null ? '—' : rollup.notify.rate_pct + '%' }}</span>
          </div>
          <div class="ah-compl-row">
            <span class="ah-compl-l">Respond 7d</span>
            <div class="ah-compl-bar"><div class="ah-compl-f ah-compl-f--ok" :style="{ width: (rollup.respond.rate_pct ?? 0) + '%' }"/></div>
            <span :class="['ah-compl-n', (rollup.respond.rate_pct ?? 0) < 80 && 'ah-compl-n--bad']">{{ rollup.respond.rate_pct == null ? '—' : rollup.respond.rate_pct + '%' }}</span>
          </div>
        </div>
      </div>

      <!-- Search -->
      <div class="ah-search">
        <input v-model="searchQuery" type="search" class="ah-search-input"
          placeholder="Search by code, title, POE..."
          @input="onSearchInput"/>
      </div>

      <!-- Loading -->
      <div v-if="loading && alerts.length === 0" class="ah-skels">
        <div v-for="i in 3" :key="i" class="ah-skel"><div class="ah-sk ah-sk--1"/><div class="ah-sk ah-sk--2"/></div>
      </div>

      <!-- Empty -->
      <div v-else-if="!loading && filteredAlerts.length === 0" class="ah-empty">
        <div class="ah-empty-ico">
          <svg viewBox="0 0 56 56" fill="none" stroke="currentColor" stroke-width="2" width="48" height="48"><rect x="6" y="10" width="44" height="40" rx="3"/><line x1="6" y1="20" x2="50" y2="20"/></svg>
        </div>
        <div class="ah-empty-title">No alert history</div>
        <div class="ah-empty-sub">{{ hasFilters || searchQuery ? 'No alerts match the current filters.' : 'No alerts have been recorded for this POE.' }}</div>
      </div>

      <!-- Timeline list -->
      <div v-else class="ah-list">
        <div v-for="g in groupedAlerts" :key="g.date" class="ah-group">
          <div class="ah-group-hdr">
            <span class="ah-group-date">{{ g.label }}</span>
            <span class="ah-group-count">{{ g.items.length }} alert{{ g.items.length!==1?'s':'' }}</span>
          </div>
          <article v-for="a in g.items" :key="a.id"
            :class="['ah-card','ah-card--'+a.risk_level.toLowerCase(), a.status==='CLOSED'&&'ah-card--closed']"
            @click="openDetail(a)">
            <div class="ah-stripe" :class="'ah-stripe--'+a.risk_level.toLowerCase()"/>
            <div class="ah-body">
              <div class="ah-row1">
                <span :class="['ah-risk','ah-risk--'+a.risk_level.toLowerCase()]">{{ a.risk_level }}</span>
                <span :class="['ah-route','ah-route--'+a.routed_to_level.toLowerCase()]">{{ a.routed_to_level }}</span>
                <span :class="['ah-status','ah-status--'+a.status.toLowerCase()]">{{ a.status }}</span>
                <span v-if="tierOf(a).tier === 1" class="ah-tier ah-tier--1">TIER 1</span>
                <span v-else-if="tierOf(a).tier === 2" class="ah-tier ah-tier--2">TIER 2</span>
                <span v-if="scorecardOf(a).overall === 'BREACH'" class="ah-717">7-1-7</span>
                <span class="ah-time">{{ fmtTime(a.created_at) }}</span>
              </div>
              <div class="ah-title">{{ a.alert_title || a.alert_code }}</div>
              <div class="ah-code">{{ a.alert_code }}</div>
              <div class="ah-meta">
                <span v-if="a.syndrome" class="ah-chip">{{ a.syndrome.replace(/_/g,' ') }}</span>
                <span class="ah-chip ah-chip--poe">{{ a.poe_code }}</span>
                <span v-if="a.acknowledged_by_name" class="ah-chip ah-chip--ack">Ack: {{ a.acknowledged_by_name }}</span>
              </div>
              <div v-if="a.closed_at" class="ah-close-info">
                Closed {{ fmtDateTime(a.closed_at) }} · resolved in {{ resolutionTime(a) }}
              </div>
            </div>
          </article>
        </div>

        <div v-if="hasMore" class="ah-loadmore">
          <button class="ah-lm-btn" :disabled="loadingMore" @click="loadMore">
            {{ loadingMore ? 'Loading...' : 'Load older alerts' }}
          </button>
        </div>
      </div>

      <div style="height:48px"/>
    </IonContent>

    <!-- Detail modal -->
    <IonModal :is-open="!!detail" @didDismiss="detail=null" :initial-breakpoint="1" :breakpoints="[0,1]" class="ah-modal">
      <IonHeader><IonToolbar class="ah-st"><div slot="start" class="ah-handle"/><IonButtons slot="end"><IonButton fill="clear" @click="detail=null">Close</IonButton></IonButtons></IonToolbar></IonHeader>
      <IonContent class="ah-sc" v-if="detail">
        <div class="ah-sp">
          <div :class="['ah-mhdr','ah-mhdr--'+detail.risk_level.toLowerCase()]">
            <span class="ah-mhdr-cat">{{ detail.routed_to_level }} · {{ detail.risk_level }} · {{ detail.status }}</span>
            <span class="ah-mhdr-title">{{ detail.alert_title || detail.alert_code }}</span>
            <span class="ah-mhdr-code">{{ detail.alert_code }}</span>
          </div>

          <h3 class="ah-msh">Lifecycle</h3>
          <div class="ah-tl">
            <div class="ah-tl-row">
              <div class="ah-tl-dot ah-tl-dot--c"/>
              <div><span class="ah-tl-label">Created</span><span class="ah-tl-time">{{ fmtDateTime(detail.created_at) }}</span></div>
            </div>
            <div v-if="detail.acknowledged_at" class="ah-tl-row">
              <div class="ah-tl-dot ah-tl-dot--a"/>
              <div><span class="ah-tl-label">Acknowledged by {{ detail.acknowledged_by_name||'—' }}</span><span class="ah-tl-time">{{ fmtDateTime(detail.acknowledged_at) }}</span></div>
            </div>
            <div v-if="detail.closed_at" class="ah-tl-row">
              <div class="ah-tl-dot ah-tl-dot--g"/>
              <div><span class="ah-tl-label">Closed</span><span class="ah-tl-time">{{ fmtDateTime(detail.closed_at) }}</span></div>
            </div>
          </div>

          <h3 class="ah-msh">Resolution Metrics</h3>
          <div class="ah-dg">
            <div class="ah-dr"><span class="ah-dk">Time to Acknowledge</span><span class="ah-dv">{{ ackTime(detail) }}</span></div>
            <div class="ah-dr"><span class="ah-dk">Time to Close</span><span class="ah-dv">{{ resolutionTime(detail) }}</span></div>
            <div class="ah-dr"><span class="ah-dk">Total Lifecycle</span><span class="ah-dv">{{ totalLifecycle(detail) }}</span></div>
            <div class="ah-dr"><span class="ah-dk">SLA Status</span><span :class="['ah-dv',slaStatus(detail).cls]">{{ slaStatus(detail).label }}</span></div>
          </div>

          <h3 class="ah-msh">Alert Details</h3>
          <div class="ah-details">
            <p v-if="cleanDetails(detail.alert_details)">{{ cleanDetails(detail.alert_details) }}</p>
            <p v-else class="ah-no-d">No details recorded</p>
          </div>

          <h3 class="ah-msh" v-if="closeReason(detail.alert_details)">Close Reason</h3>
          <div v-if="closeReason(detail.alert_details)" class="ah-details ah-details--close">{{ closeReason(detail.alert_details) }}</div>

          <h3 class="ah-msh">Geographic Scope</h3>
          <div class="ah-dg">
            <div class="ah-dr"><span class="ah-dk">POE</span><span class="ah-dv">{{ detail.poe_code }}</span></div>
            <div class="ah-dr"><span class="ah-dk">District</span><span class="ah-dv">{{ detail.district_code }}</span></div>
            <div class="ah-dr"><span class="ah-dk">Country</span><span class="ah-dv">{{ detail.country_code }}</span></div>
          </div>
        </div>
        <div style="height:48px"/>
      </IonContent>
    </IonModal>

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="3000" position="top" @didDismiss="toast.show=false"/>
  </IonPage>
</template>

<script setup>
import { IonPage, IonHeader, IonToolbar, IonButtons, IonMenuButton, IonButton, IonContent, IonIcon, IonModal, IonToast, IonRefresher, IonRefresherContent, onIonViewWillEnter, onIonViewDidEnter } from '@ionic/vue'
import { refreshOutline, pulseOutline, bookOutline } from 'ionicons/icons'
import { ref, computed, reactive, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { APP } from '@/services/poeDB'
import {
  userScope, classifyIHRTier, evaluate717, compliance717Summary,
} from '@/composables/useAlertIntelligence'

const router = useRouter()

function getAuth() { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {} }
const auth = ref(getAuth())
const scope = computed(() => userScope(auth.value))

// Intelligence-engine memoisation
const _tierCache = new WeakMap()
function tierOf(a) {
  if (!a) return { tier: null }
  if (_tierCache.has(a)) return _tierCache.get(a)
  const r = classifyIHRTier(a); _tierCache.set(a, r); return r
}
const _scCache = new WeakMap()
function scorecardOf(a) {
  if (!a) return { overall: 'ON_TARGET', bottleneck: null }
  if (_scCache.has(a)) return _scCache.get(a)
  const r = evaluate717(a); _scCache.set(a, r); return r
}

function gotoMatrix() { router.push('/alerts/matrix') }

const PERIODS = [
  { v:'7d', l:'7 Days' },{ v:'30d', l:'30 Days' },{ v:'90d', l:'90 Days' },{ v:'all', l:'All Time' },
]

const alerts = ref([])
const loading = ref(false)
const loadingMore = ref(false)
const page = ref(1)
const hasMore = ref(false)
const period = ref('30d')
const advOpen = ref(false)
const riskFilter = ref(null)
const routeFilter = ref(null)
const dateFrom = ref('')
const dateTo = ref('')
const searchQuery = ref('')
const detail = ref(null)
const isOnline = ref(navigator.onLine)
const toast = reactive({ show:false, msg:'', color:'success' })
let pollTimer = null
let searchTimer = null

const stats = computed(() => ({
  total: alerts.value.length,
  critical: alerts.value.filter(a => a.risk_level === 'CRITICAL').length,
  high: alerts.value.filter(a => a.risk_level === 'HIGH').length,
  closed: alerts.value.filter(a => a.status === 'CLOSED').length,
}))
const rollup = computed(() => compliance717Summary(alerts.value))

const hasFilters = computed(() => riskFilter.value || routeFilter.value || dateFrom.value || dateTo.value || period.value !== '30d')

const filteredAlerts = computed(() => {
  if (!searchQuery.value.trim()) return alerts.value
  const q = searchQuery.value.trim().toLowerCase()
  return alerts.value.filter(a =>
    (a.alert_code || '').toLowerCase().includes(q) ||
    (a.alert_title || '').toLowerCase().includes(q) ||
    (a.poe_code || '').toLowerCase().includes(q) ||
    (a.syndrome || '').toLowerCase().includes(q)
  )
})

const groupedAlerts = computed(() => {
  const groups = new Map()
  for (const a of filteredAlerts.value) {
    const d = (a.created_at || '').slice(0, 10)
    if (!d) continue
    if (!groups.has(d)) groups.set(d, [])
    groups.get(d).push(a)
  }
  return Array.from(groups.entries())
    .sort((a, b) => b[0].localeCompare(a[0]))
    .map(([date, items]) => ({ date, items, label: fmtGroupDate(date) }))
})

function fmtGroupDate(d) {
  if (!d) return ''
  try {
    const today = new Date().toISOString().slice(0, 10)
    const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10)
    if (d === today) return 'Today'
    if (d === yesterday) return 'Yesterday'
    return new Date(d + 'T00:00:00').toLocaleDateString([], { weekday: 'long', day: '2-digit', month: 'short' })
  } catch { return d }
}
function fmtTime(dt) { if(!dt)return''; try{return new Date(dt.replace(' ','T')).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}catch{return''} }
function fmtDateTime(dt) { if(!dt)return'—'; try{return new Date(dt.replace(' ','T')).toLocaleString([],{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})}catch{return dt} }
function cleanDetails(d) { return (d||'').replace(/\[CLOSED:[^\]]+\]/g,'').trim() }
function closeReason(d) { const m = (d||'').match(/\[CLOSED:\s*([^\]]+)\]/); return m ? m[1].trim() : null }

function diffMin(start, end) {
  if (!start || !end) return null
  try { return Math.round((new Date(end.replace(' ','T')) - new Date(start.replace(' ','T'))) / 60000) }
  catch { return null }
}
function fmtMins(m) {
  if (m == null) return '—'
  if (m < 60) return m + ' min'
  const h = m / 60
  if (h < 24) return h.toFixed(1) + ' h'
  return (h / 24).toFixed(1) + ' d'
}
function ackTime(a) { return fmtMins(diffMin(a.created_at, a.acknowledged_at)) }
function resolutionTime(a) { return fmtMins(diffMin(a.acknowledged_at || a.created_at, a.closed_at)) }
function totalLifecycle(a) { return fmtMins(diffMin(a.created_at, a.closed_at)) }
function slaStatus(a) {
  const ackMin = diffMin(a.created_at, a.acknowledged_at)
  if (a.risk_level === 'CRITICAL' && ackMin != null && ackMin > 60) return { label: 'CRITICAL SLA breached (>60min)', cls: 'ah-dv--r' }
  if (a.risk_level === 'HIGH' && ackMin != null && ackMin > 240) return { label: 'HIGH SLA breached (>4h)', cls: 'ah-dv--r' }
  if (ackMin != null && ackMin > 1440) return { label: 'Acknowledged late (>24h)', cls: 'ah-dv--a' }
  if (a.acknowledged_at) return { label: 'Met SLA', cls: 'ah-dv--g' }
  return { label: 'Not yet acknowledged', cls: 'ah-dv--a' }
}

function clearFilters() { period.value='30d'; riskFilter.value=null; routeFilter.value=null; dateFrom.value=''; dateTo.value=''; loadAlerts(true) }
function onSearchInput() { /* client-side filter via computed */ }

async function api(path, opts = {}) {
  const uid = auth.value?.id
  if (!uid) return null
  const sep = path.includes('?') ? '&' : '?'
  const ctrl = new AbortController()
  const tid = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS)
  try {
    const res = await fetch(`${window.SERVER_URL}${path}${sep}user_id=${uid}`, {
      headers: { Accept: 'application/json' }, signal: ctrl.signal, ...opts,
    })
    clearTimeout(tid)
    if (!res.ok) return null
    const j = await res.json()
    return j.success ? j.data : null
  } catch { clearTimeout(tid); return null }
}

function periodDates() {
  const now = new Date()
  if (period.value === 'all') return { from: null, to: null }
  const days = period.value === '7d' ? 7 : period.value === '90d' ? 90 : 30
  const from = new Date(now); from.setDate(from.getDate() - days)
  return { from: from.toISOString().slice(0,10), to: now.toISOString().slice(0,10) }
}

async function loadAlerts(reset = false) {
  if (loading.value) return
  loading.value = true
  if (reset) { page.value = 1; alerts.value = [] }
  try {
    let path = `/alerts?per_page=50&page=${page.value}`
    const { from, to } = periodDates()
    if (from) path += `&date_from=${from}`
    if (to)   path += `&date_to=${to}`
    if (riskFilter.value)  path += `&risk_level=${riskFilter.value}`
    if (routeFilter.value) path += `&routed_to_level=${routeFilter.value}`
    if (dateFrom.value)    path += `&date_from=${dateFrom.value}`
    if (dateTo.value)      path += `&date_to=${dateTo.value}`

    const d = await api(path)
    if (!d) { isOnline.value = false; return }
    isOnline.value = true
    if (reset) alerts.value = d.items || []
    else alerts.value = [...alerts.value, ...(d.items || [])]
    hasMore.value = (d.page ?? 1) < (d.pages ?? 1)
  } finally { loading.value = false }
}

async function loadMore() {
  if (loadingMore.value || !hasMore.value) return
  loadingMore.value = true
  page.value++
  try { await loadAlerts(false) }
  finally { loadingMore.value = false }
}

async function pullRefresh(ev) { await loadAlerts(true); ev.target.complete() }
function openDetail(a) {
  // Tap any historical alert → War Room (full lifecycle hub: reopen,
  // outcome wizard, breach RCA, timeline, comments). Legacy detail modal
  // stays in the template but no longer triggers (detail null).
  if (a?.id) {
    router.push({ name: 'AlertWarRoom', params: { id: a.id } })
    return
  }
  detail.value = { ...a }
}

function onOnline() { isOnline.value = true; loadAlerts(true) }
function onOffline() { isOnline.value = false }

onMounted(() => {
  auth.value = getAuth()
  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)
  loadAlerts(true)
  pollTimer = setInterval(() => { if (isOnline.value && !loading.value) loadAlerts(true) }, 60_000)
})
onIonViewWillEnter(() => { auth.value = getAuth(); loadAlerts(true) })
onIonViewDidEnter(() => { if (isOnline.value && !loading.value) loadAlerts(true) })
onUnmounted(() => {
  window.removeEventListener('online', onOnline)
  window.removeEventListener('offline', onOffline)
  clearInterval(pollTimer)
  clearTimeout(searchTimer)
})
</script>

<style scoped>
*{box-sizing:border-box}
.ah-hdr{--background:transparent;border:none}
.ah-hdr-bg{background:linear-gradient(135deg,#001D3D,#003566,#003F88);padding:0 0 4px}
.ah-hdr-top{display:flex;align-items:center;gap:4px;padding:6px 8px 0}
.ah-menu{--color:rgba(255,255,255,.7)}
.ah-hdr-title{flex:1;display:flex;flex-direction:column;min-width:0}
.ah-eye{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:rgba(255,255,255,.4)}
.ah-h1{font-size:17px;font-weight:800;color:#fff}
.ah-ref{width:32px;height:32px;border-radius:50%;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.05);color:rgba(255,255,255,.7);cursor:pointer;display:flex;align-items:center;justify-content:center}
.ah-ref:disabled{opacity:.4}

.ah-stats{display:flex;gap:4px;padding:8px}
.ah-st{flex:1;padding:8px 4px;border-radius:8px;background:rgba(255,255,255,.06);text-align:center}
.ah-st-n{display:block;font-size:18px;font-weight:900;color:#fff;line-height:1}
.ah-st-l{display:block;font-size:9px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.5);margin-top:2px}
.ah-st--c .ah-st-n{color:#FF8A80}
.ah-st--h .ah-st-n{color:#FFB74D}
.ah-st--g .ah-st-n{color:#81C784}

.ah-fr{display:flex;gap:4px;padding:0 8px 4px;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch}.ah-fr::-webkit-scrollbar{display:none}
.ah-fp{padding:5px 12px;border-radius:99px;border:1px solid rgba(255,255,255,.12);background:transparent;color:rgba(255,255,255,.55);font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0}
.ah-fp--on{background:rgba(255,255,255,.2);color:#fff;border-color:rgba(255,255,255,.3)}

.ah-adv{padding:6px 8px;background:#001D3D}
.ah-ar{display:flex;align-items:center;gap:4px;margin-bottom:6px;flex-wrap:wrap}
.ah-al{font-size:10px;font-weight:700;color:rgba(255,255,255,.4);min-width:42px;text-transform:uppercase;letter-spacing:.4px}
.ah-ap{display:flex;gap:3px;flex:1;flex-wrap:wrap}
.ah-pill{padding:3px 8px;border-radius:99px;border:1px solid rgba(255,255,255,.1);background:transparent;color:rgba(255,255,255,.5);font-size:10px;font-weight:700;cursor:pointer}
.ah-pill--on{background:rgba(255,255,255,.18);color:#fff}
.ah-ai{flex:1;padding:5px 8px;border-radius:5px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.05);color:#fff;font-size:11px;outline:none}
.ah-clear{padding:6px 12px;border-radius:5px;border:1px solid rgba(255,107,107,.4);background:transparent;color:#FF8A80;font-size:10px;font-weight:700;cursor:pointer}
.ah-sl-enter-active,.ah-sl-leave-active{transition:max-height .2s,opacity .2s;overflow:hidden}
.ah-sl-enter-from,.ah-sl-leave-to{max-height:0;opacity:0}
.ah-sl-enter-to,.ah-sl-leave-from{max-height:300px;opacity:1}

.ah-content{--background:#F0F4FA}
.ah-offline{padding:8px 12px;background:#FFF3E0;border-bottom:1px solid #FFB74D;font-size:11px;color:#BF360C;text-align:center;font-weight:600}

.ah-search{padding:8px 10px}
.ah-search-input{width:100%;padding:10px 14px;border-radius:8px;border:1px solid #E2E8F0;background:#fff;font-size:13px;color:#1A3A5C;outline:none}
.ah-search-input:focus{border-color:#1565C0}

.ah-skels{padding:8px 10px}
.ah-skel{background:#fff;border-radius:8px;padding:12px;margin-bottom:6px;border:1px solid #E8EDF5}
.ah-sk{height:10px;background:linear-gradient(90deg,#E2E8F0 25%,#F1F5F9 50%,#E2E8F0 75%);background-size:200% 100%;animation:ah-sh 1.4s linear infinite;border-radius:4px;margin-bottom:6px}
.ah-sk--1{width:70%}.ah-sk--2{width:40%}
@keyframes ah-sh{0%{background-position:200% 0}100%{background-position:-200% 0}}

.ah-empty{display:flex;flex-direction:column;align-items:center;padding:60px 20px;gap:10px}
.ah-empty-ico{color:#B0BEC5}
.ah-empty-title{font-size:17px;font-weight:800;color:#1A3A5C}
.ah-empty-sub{font-size:12px;color:#64748B;text-align:center;max-width:280px}

.ah-list{padding:0 10px}
.ah-group{margin-bottom:14px}
.ah-group-hdr{display:flex;align-items:center;justify-content:space-between;padding:6px 4px}
.ah-group-date{font-size:11px;font-weight:800;color:#1A3A5C;text-transform:uppercase;letter-spacing:.5px}
.ah-group-count{font-size:10px;color:#94A3B8;font-weight:600}

.ah-card{display:flex;background:#fff;border-radius:8px;border:1px solid #E8EDF5;overflow:hidden;margin-bottom:6px;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.ah-card--closed{opacity:.7}
.ah-stripe{width:3px;flex-shrink:0}
.ah-stripe--critical{background:#DC2626}.ah-stripe--high{background:#EA580C}.ah-stripe--medium{background:#CA8A04}.ah-stripe--low{background:#10B981}
.ah-body{flex:1;padding:9px 11px;min-width:0}
.ah-row1{display:flex;align-items:center;gap:4px;flex-wrap:wrap;margin-bottom:4px}
.ah-risk{font-size:9px;font-weight:800;padding:2px 5px;border-radius:3px;letter-spacing:.3px}
.ah-risk--critical{background:#FEE2E2;color:#991B1B}
.ah-risk--high{background:#FFEDD5;color:#9A3412}
.ah-risk--medium{background:#FEF3C7;color:#854D0E}
.ah-risk--low{background:#D1FAE5;color:#047857}
.ah-route{font-size:9px;font-weight:700;padding:2px 5px;border-radius:3px;background:#F1F5F9;color:#475569}
.ah-route--national{background:#E0E7FF;color:#3730A3}
.ah-route--pheoc{background:#FCE7F3;color:#9D174D}
.ah-route--district{background:#DCFCE7;color:#166534}
.ah-status{font-size:9px;font-weight:700;padding:2px 5px;border-radius:3px}
.ah-status--open{background:#FEE2E2;color:#991B1B}
.ah-status--acknowledged{background:#DBEAFE;color:#1E40AF}
.ah-status--closed{background:#F1F5F9;color:#64748B}
.ah-time{margin-left:auto;font-size:10px;color:#94A3B8;font-weight:600}
.ah-title{font-size:13px;font-weight:800;color:#1A3A5C;line-height:1.3;margin-bottom:1px}
.ah-code{font-size:10px;color:#94A3B8;font-family:monospace;margin-bottom:5px}
.ah-meta{display:flex;gap:4px;flex-wrap:wrap}
.ah-chip{font-size:10px;font-weight:700;padding:2px 6px;border-radius:4px;background:#F1F5F9;color:#475569}
.ah-chip--poe{background:#DBEAFE;color:#1E40AF}
.ah-chip--ack{background:#D1FAE5;color:#047857}
.ah-close-info{font-size:10px;color:#64748B;margin-top:4px;font-style:italic}

.ah-loadmore{display:flex;justify-content:center;padding:10px 0}
.ah-lm-btn{padding:8px 22px;border-radius:99px;border:1px solid #CBD5E1;background:#fff;color:#475569;font-size:12px;font-weight:700;cursor:pointer}
.ah-lm-btn:disabled{opacity:.4}

.ah-modal::part(content){border-radius:14px 14px 0 0}
.ah-st{--background:#fff;--border-width:0 0 1px 0;--border-color:#E8EDF5;--min-height:36px}
.ah-handle{width:32px;height:3px;border-radius:2px;background:#DDE3EA;margin:0 10px}
.ah-sc{--background:#fff}
.ah-sp{padding:14px 16px 0}
.ah-mhdr{padding:14px;border-radius:10px;margin-bottom:14px}
.ah-mhdr--critical{background:#FEF2F2;border:1px solid #FECACA}
.ah-mhdr--high{background:#FFF7ED;border:1px solid #FED7AA}
.ah-mhdr--medium{background:#FEFCE8;border:1px solid #FDE68A}
.ah-mhdr--low{background:#F0FDF4;border:1px solid #BBF7D0}
.ah-mhdr-cat{display:block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#94A3B8;margin-bottom:4px}
.ah-mhdr-title{display:block;font-size:16px;font-weight:800;color:#1A3A5C;line-height:1.3}
.ah-mhdr-code{display:block;font-size:10px;color:#94A3B8;margin-top:4px;font-family:monospace}
.ah-msh{font-size:13px;font-weight:800;color:#1A3A5C;margin:14px 0 8px}
.ah-tl{background:#F8FAFC;border-radius:8px;padding:10px;border:1px solid #E8EDF5}
.ah-tl-row{display:flex;align-items:flex-start;gap:10px;padding:6px 0}
.ah-tl-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;margin-top:3px}
.ah-tl-dot--c{background:#0066CC}
.ah-tl-dot--a{background:#1E40AF}
.ah-tl-dot--g{background:#10B981}
.ah-tl-label{display:block;font-size:11px;font-weight:700;color:#1A3A5C}
.ah-tl-time{display:block;font-size:10px;color:#64748B;margin-top:1px}
.ah-dg{background:#F8FAFC;border-radius:8px;border:1px solid #E8EDF5;overflow:hidden}
.ah-dr{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;border-top:1px solid #E8EDF5}
.ah-dr:first-child{border-top:none}
.ah-dk{font-size:11px;color:#64748B;font-weight:600}
.ah-dv{font-size:12px;font-weight:700;color:#1A3A5C}
.ah-dv--r{color:#DC2626!important}
.ah-dv--a{color:#EA580C!important}
.ah-dv--g{color:#10B981!important}
.ah-details{background:#F8FAFC;border-radius:8px;padding:12px;border:1px solid #E8EDF5;font-size:12px;color:#475569;line-height:1.5}
.ah-details--close{background:#FEF2F2;border-color:#FECACA;color:#991B1B}
.ah-details p{margin:0}
.ah-no-d{color:#94A3B8;font-style:italic}

@media(min-width:500px){.ah-list,.ah-search,.ah-compl{max-width:480px;margin:0 auto}}

/* Upgraded header nav + tier/breach badges + compliance strip */
.ah-nav{width:28px;height:28px;border-radius:50%;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.05);color:rgba(255,255,255,.75);cursor:pointer;display:flex;align-items:center;justify-content:center;margin-right:4px;font-size:14px}
.ah-nav:active{background:rgba(255,255,255,.15)}
.ah-st--t .ah-st-n{color:#CE93D8}
.ah-st--b .ah-st-n{color:#90CAF9}
.ah-st--bad .ah-st-n{color:#FF8A80}
.ah-tier{font-size:9px;font-weight:800;padding:2px 5px;border-radius:4px;letter-spacing:.3px}
.ah-tier--1{background:#F3E8FF;color:#6B21A8;border:1px solid #D8B4FE}
.ah-tier--2{background:#FEF2F2;color:#DC2626;border:1px solid #FECACA}
.ah-717{font-size:9px;font-weight:800;padding:2px 5px;border-radius:4px;background:#7F1D1D;color:#FEE2E2;letter-spacing:.3px}
.ah-compl{margin:8px 10px 0;padding:10px 12px;background:#fff;border:1px solid #E8EDF5;border-radius:10px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
.ah-compl-hdr{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.ah-compl-t{font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.5px}
.ah-compl-link{background:none;border:none;color:#1E40AF;font-size:11px;font-weight:800;cursor:pointer;padding:0}
.ah-compl-rows{display:flex;flex-direction:column;gap:6px}
.ah-compl-row{display:flex;align-items:center;gap:8px;font-size:11px}
.ah-compl-l{width:78px;font-weight:700;color:#1A3A5C;flex-shrink:0}
.ah-compl-bar{flex:1;height:6px;background:#F1F5F9;border-radius:3px;overflow:hidden}
.ah-compl-f{height:100%;transition:width .3s}
.ah-compl-f--ok{background:linear-gradient(90deg,#10B981,#047857)}
.ah-compl-n{width:40px;text-align:right;font-weight:800;color:#047857;font-variant-numeric:tabular-nums}
.ah-compl-n--bad{color:#DC2626}
</style>
