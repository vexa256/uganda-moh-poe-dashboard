<template>
  <IonPage>
    <IonHeader class="ag-hdr" translucent>
      <div class="ag-hdr-bg">
        <div class="ag-hdr-top">
          <IonButtons slot="start"><IonMenuButton menu="app-menu" class="ag-menu"/></IonButtons>
          <div class="ag-hdr-title">
            <span class="ag-eye">{{ auth.poe_code||'POE' }} · Aggregated</span>
            <span class="ag-h1">Submission History</span>
          </div>
          <IonButtons slot="end">
            <button class="ag-new-btn" @click="goNew" title="New Submission">+ New</button>
            <button class="ag-ref" @click="loadAll(true)" :disabled="loading"><IonIcon :icon="refreshOutline" slot="icon-only"/></button>
          </IonButtons>
        </div>

        <div class="ag-stats">
          <div class="ag-st"><span class="ag-st-n">{{ stats.total }}</span><span class="ag-st-l">Submissions</span></div>
          <div class="ag-st ag-st--g"><span class="ag-st-n">{{ stats.totalScreened }}</span><span class="ag-st-l">Screened</span></div>
          <div class="ag-st ag-st--r"><span class="ag-st-n">{{ stats.totalSymp }}</span><span class="ag-st-l">Symptomatic</span></div>
          <div class="ag-st ag-st--a"><span class="ag-st-n">{{ stats.unsynced }}</span><span class="ag-st-l">Unsynced</span></div>
        </div>

        <div class="ag-fr">
          <button v-for="f in PERIODS" :key="f.v" :class="['ag-fp',period===f.v&&'ag-fp--on']" @click="period=f.v;loadAll(true)">{{ f.l }}</button>
        </div>
      </div>
    </IonHeader>

    <IonContent class="ag-content" :fullscreen="true">
      <IonRefresher slot="fixed" @ionRefresh="pullRefresh($event)"><IonRefresherContent pulling-text="Pull to refresh" refreshing-spinner="crescent"/></IonRefresher>

      <div v-if="!isOnline" class="ag-offline">Offline — showing cached submissions</div>

      <div v-if="loading && !items.length" class="ag-skels">
        <div v-for="i in 3" :key="i" class="ag-skel"><div class="ag-sk ag-sk--1"/><div class="ag-sk ag-sk--2"/><div class="ag-sk ag-sk--3"/></div>
      </div>

      <div v-else-if="!loading && !items.length" class="ag-empty">
        <div class="ag-empty-ico">
          <svg viewBox="0 0 56 56" fill="none" stroke="currentColor" stroke-width="2" width="48" height="48"><rect x="6" y="10" width="44" height="40" rx="3"/><line x1="6" y1="20" x2="50" y2="20"/><line x1="14" y1="30" x2="42" y2="30"/><line x1="14" y1="38" x2="32" y2="38"/></svg>
        </div>
        <div class="ag-empty-title">No submissions yet</div>
        <div class="ag-empty-sub">Open <strong>Reports</strong> to pick a published template and submit your first aggregated report.</div>
        <button class="ag-empty-btn" @click="goNew">Go to Reports</button>
      </div>

      <div v-else class="ag-list">
        <article v-for="item in items" :key="item.client_uuid"
          :class="['ag-card', item.sync_status!=='SYNCED'&&'ag-card--unsynced']"
          @click="openDetail(item)">
          <div class="ag-card-h">
            <div class="ag-card-period">
              <span class="ag-period-from">{{ fmtDate(item.period_start) }}</span>
              <span class="ag-period-arrow">→</span>
              <span class="ag-period-to">{{ fmtDate(item.period_end) }}</span>
            </div>
            <!-- Audit C-E2: friendly upload state instead of raw enum. -->
            <span :class="['ag-sync','ag-sync--'+(item.sync_status||'unknown').toLowerCase()]" :title="'Upload status: ' + syncLabelFor(item.sync_status || 'UNKNOWN')">{{ syncLabelFor(item.sync_status || 'UNKNOWN') }}</span>
          </div>
          <div class="ag-card-stats">
            <div class="ag-cs"><span class="ag-cs-n">{{ item.total_screened }}</span><span class="ag-cs-l">Total</span></div>
            <div class="ag-cs ag-cs--r"><span class="ag-cs-n">{{ item.total_symptomatic }}</span><span class="ag-cs-l">With sympt.</span></div>
            <div class="ag-cs"><span class="ag-cs-n">{{ rate(item) }}%</span><span class="ag-cs-l">Sympt. rate</span></div>
            <div class="ag-cs"><span class="ag-cs-n">{{ totalGenders(item) }}</span><span class="ag-cs-l">Male+Female</span></div>
          </div>
          <div v-if="item.notes" class="ag-card-notes">{{ truncate(item.notes, 120) }}</div>
          <div class="ag-card-foot">
            <span class="ag-card-meta">{{ item.submitted_by_name||item.user_full_name||'Unknown' }}</span>
            <span class="ag-card-meta">{{ fmtDateTime(item.created_at) }}</span>
          </div>
        </article>

        <div v-if="hasMore" class="ag-loadmore">
          <button class="ag-lm-btn" :disabled="loadingMore" @click="loadMore">
            {{ loadingMore ? 'Loading...' : 'Load older submissions' }}
          </button>
        </div>
      </div>

      <div style="height:48px"/>
    </IonContent>

    <!-- Detail modal -->
    <IonModal :is-open="!!detail" @didDismiss="detail=null" :initial-breakpoint="1" :breakpoints="[0,1]" class="ag-modal">
      <IonHeader><IonToolbar class="ag-mt"><div slot="start" class="ag-handle"/><IonButtons slot="end"><IonButton fill="clear" @click="detail=null">Close</IonButton></IonButtons></IonToolbar></IonHeader>
      <IonContent class="ag-mc" v-if="detail">
        <div class="ag-mp">
          <div class="ag-mhdr">
            <span class="ag-mhdr-cat">AGGREGATED SUBMISSION</span>
            <span class="ag-mhdr-title">{{ fmtDate(detail.period_start) }} → {{ fmtDate(detail.period_end) }}</span>
            <span class="ag-mhdr-sub">{{ daysBetween(detail.period_start, detail.period_end) }} day period</span>
          </div>

          <h3 class="ag-msh">Counts</h3>
          <div class="ag-dg">
            <div class="ag-dr"><span class="ag-dk">Total Screened</span><span class="ag-dv ag-dv--g">{{ detail.total_screened }}</span></div>
            <div class="ag-dr"><span class="ag-dk">Symptomatic</span><span class="ag-dv ag-dv--r">{{ detail.total_symptomatic }}</span></div>
            <div class="ag-dr"><span class="ag-dk">Asymptomatic</span><span class="ag-dv">{{ detail.total_asymptomatic }}</span></div>
            <div class="ag-dr"><span class="ag-dk">Symptomatic Rate</span><span :class="['ag-dv',rate(detail)>=20&&'ag-dv--r']">{{ rate(detail) }}%</span></div>
          </div>

          <h3 class="ag-msh">Gender Breakdown</h3>
          <div class="ag-dg">
            <div class="ag-dr"><span class="ag-dk">Male</span><span class="ag-dv">{{ detail.total_male }}</span></div>
            <div class="ag-dr"><span class="ag-dk">Female</span><span class="ag-dv">{{ detail.total_female }}</span></div>
            <div class="ag-dr"><span class="ag-dk">Sum</span><span :class="['ag-dv',genderSum(detail)===detail.total_screened?'ag-dv--g':'ag-dv--r']">{{ genderSum(detail) }} {{ genderSum(detail)===detail.total_screened?'✓':'✗' }}</span></div>
          </div>

          <!-- Audit C-C1, C-C2, C-C3: rename "Validation Checks" → "Quality
               checks" and replace bare PASS/FAIL with green-tick prose so a
               non-technical reader knows whether to act. -->
          <h3 class="ag-msh">Quality checks</h3>
          <div class="ag-checklist">
            <div class="ag-check" :class="genderSum(detail)===detail.total_screened ? 'ag-check--ok' : 'ag-check--bad'">
              <span class="ag-check-mark" aria-hidden="true">{{ genderSum(detail)===detail.total_screened ? '✓' : '✗' }}</span>
              <span class="ag-check-text">
                <template v-if="genderSum(detail)===detail.total_screened">Male + Female adds up to total screened</template>
                <template v-else>Male + Female does NOT add up to total screened — please review and re-submit.</template>
              </span>
            </div>
            <div class="ag-check" :class="(detail.total_symptomatic+detail.total_asymptomatic)===detail.total_screened ? 'ag-check--ok' : 'ag-check--bad'">
              <span class="ag-check-mark" aria-hidden="true">{{ (detail.total_symptomatic+detail.total_asymptomatic)===detail.total_screened ? '✓' : '✗' }}</span>
              <span class="ag-check-text">
                <template v-if="(detail.total_symptomatic+detail.total_asymptomatic)===detail.total_screened">With-symptoms + No-symptoms adds up to total screened</template>
                <template v-else>With-symptoms + No-symptoms does NOT add up to total screened — please review.</template>
              </span>
            </div>
            <div class="ag-check" :class="new Date(detail.period_start) < new Date(detail.period_end) ? 'ag-check--ok' : 'ag-check--bad'">
              <span class="ag-check-mark" aria-hidden="true">{{ new Date(detail.period_start)<new Date(detail.period_end) ? '✓' : '✗' }}</span>
              <span class="ag-check-text">
                <template v-if="new Date(detail.period_start)<new Date(detail.period_end)">Reporting period covers {{ daysBetween(detail.period_start, detail.period_end) }} day{{ daysBetween(detail.period_start, detail.period_end) === 1 ? '' : 's' }} (start before end)</template>
                <template v-else>Reporting period is invalid — start date is on/after end date.</template>
              </span>
            </div>
          </div>

          <h3 class="ag-msh" v-if="detail.notes">Notes</h3>
          <div v-if="detail.notes" class="ag-notes">{{ detail.notes }}</div>

          <!-- Audit C-C4: hide schema metadata behind a disclosure so the
               district supervisor isn't faced with `record_version v2`,
               `Server ID`, `app_version` on first read. -->
          <h3 class="ag-msh">Submission</h3>
          <div class="ag-dg">
            <div class="ag-dr"><span class="ag-dk">Submitted by</span><span class="ag-dv">{{ detail.submitted_by_name||detail.user_full_name||'Unknown' }}</span></div>
            <div class="ag-dr"><span class="ag-dk">Submitted on</span><span class="ag-dv">{{ fmtDateTime(detail.created_at) }}</span></div>
            <!-- Audit C-C5: friendly sync labels instead of the raw enum. -->
            <div class="ag-dr"><span class="ag-dk">Upload status</span><span :class="['ag-dv',detail.sync_status==='SYNCED'?'ag-dv--g':'ag-dv--a']">{{ syncLabelFor(detail.sync_status) }}</span></div>
            <div class="ag-dr" v-if="detail.synced_at"><span class="ag-dk">Uploaded on</span><span class="ag-dv">{{ fmtDateTime(detail.synced_at) }}</span></div>
          </div>
          <details class="ag-tech">
            <summary>Technical details</summary>
            <div class="ag-dg">
              <div class="ag-dr"><span class="ag-dk">Last edit</span><span class="ag-dv">{{ fmtDateTime(detail.updated_at) }}</span></div>
              <div class="ag-dr"><span class="ag-dk">Server ID</span><span class="ag-dv">{{ detail.id || 'Not yet synced' }}</span></div>
              <div class="ag-dr"><span class="ag-dk">Device</span><span class="ag-dv">{{ detail.platform }} · {{ detail.app_version||'—' }}</span></div>
              <div class="ag-dr"><span class="ag-dk">Record version</span><span class="ag-dv">v{{ detail.record_version }}</span></div>
            </div>
          </details>

          <h3 class="ag-msh">Geographic Scope</h3>
          <div class="ag-dg">
            <div class="ag-dr"><span class="ag-dk">POE</span><span class="ag-dv">{{ detail.poe_code }}</span></div>
            <div class="ag-dr"><span class="ag-dk">District</span><span class="ag-dv">{{ detail.district_code }}</span></div>
            <div class="ag-dr"><span class="ag-dk">PHEOC</span><span class="ag-dv">{{ detail.pheoc_code||'—' }}</span></div>
            <div class="ag-dr"><span class="ag-dk">Country</span><span class="ag-dv">{{ detail.country_code }}</span></div>
          </div>
        </div>
        <div style="height:48px"/>
      </IonContent>
    </IonModal>

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="3000" position="top" @didDismiss="toast.show=false"/>
  </IonPage>
</template>

<script setup>
import { IonPage, IonHeader, IonToolbar, IonButtons, IonMenuButton, IonButton, IonContent, IonIcon, IonModal, IonToast, IonRefresher, IonRefresherContent, onIonViewWillEnter } from '@ionic/vue'
import { refreshOutline } from 'ionicons/icons'
import { ref, computed, reactive, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { APP, dbGetByIndex, STORE } from '@/services/poeDB'
import { syncLabel as syncLabelFor } from '@/services/plainLabels'

const router = useRouter()
function getAuth() { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {} }
const auth = ref(getAuth())

const PERIODS = [
  { v:'30d', l:'30 Days' },{ v:'90d', l:'90 Days' },{ v:'this_year', l:'This Year' },{ v:'all', l:'All' },
]

const items = ref([])
const loading = ref(false)
const loadingMore = ref(false)
const page = ref(1)
const hasMore = ref(false)
const period = ref('30d')
const detail = ref(null)
const isOnline = ref(navigator.onLine)
const toast = reactive({ show:false, msg:'', color:'success' })
let pollTimer = null

const stats = computed(() => ({
  total: items.value.length,
  totalScreened: items.value.reduce((s, i) => s + (Number(i.total_screened) || 0), 0),
  totalSymp: items.value.reduce((s, i) => s + (Number(i.total_symptomatic) || 0), 0),
  unsynced: items.value.filter(i => i.sync_status !== 'SYNCED').length,
}))

function rate(i) { const t = Number(i.total_screened)||0; const s = Number(i.total_symptomatic)||0; return t ? Math.round(s/t*100) : 0 }
function genderSum(i) { return (Number(i.total_male)||0) + (Number(i.total_female)||0) }
function totalGenders(i) { return genderSum(i) }
function daysBetween(a, b) { try { return Math.ceil((new Date(b)-new Date(a))/86400000) + 1 } catch { return '—' } }
function truncate(s, n) { return (s && s.length > n) ? s.slice(0,n)+'...' : s }
function fmtDate(d) { if(!d)return'—'; try{return new Date(d.replace(' ','T')).toLocaleDateString([],{day:'2-digit',month:'short',year:'numeric'})}catch{return d} }
function fmtDateTime(d) { if(!d)return'—'; try{return new Date(d.replace(' ','T')).toLocaleString([],{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})}catch{return d} }
function showToast(msg, color='success') { toast.show = true; toast.msg = msg; toast.color = color }

function goNew(ev) {
  try { ev?.currentTarget?.blur?.() } catch (_) {}
  // Hub lists every published report; user picks which one to submit.
  router.push('/aggregated-data')
}

async function api(path) {
  const uid = auth.value?.id; if (!uid) return null
  const sep = path.includes('?') ? '&' : '?'
  const ctrl = new AbortController()
  const tid = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS)
  try {
    const res = await fetch(`${window.SERVER_URL}${path}${sep}user_id=${uid}`, { headers: { Accept: 'application/json' }, signal: ctrl.signal })
    clearTimeout(tid); if (!res.ok) return null
    const j = await res.json(); return j.success ? j.data : null
  } catch { clearTimeout(tid); return null }
}

function periodDates() {
  const now = new Date()
  if (period.value === 'all') return { from: null, to: null }
  if (period.value === 'this_year') return { from: `${now.getFullYear()}-01-01`, to: now.toISOString().slice(0,10) }
  const days = period.value === '90d' ? 90 : 30
  const from = new Date(now); from.setDate(from.getDate() - days)
  return { from: from.toISOString().slice(0,10), to: now.toISOString().slice(0,10) }
}

async function loadAll(reset = false) {
  if (loading.value) return
  loading.value = true
  if (reset) { page.value = 1; items.value = [] }
  try {
    let path = `/aggregated?per_page=50&page=${page.value}`
    const { from, to } = periodDates()
    if (from) path += `&date_from=${from}`
    if (to)   path += `&date_to=${to}`

    const d = await api(path)
    if (!d) {
      isOnline.value = false
      // Fallback: read from IDB
      try {
        const all = await dbGetByIndex(STORE.AGGREGATED_SUBMISSIONS, 'poe_code', auth.value.poe_code).catch(() => [])
        const filtered = all.filter(x => !x.deleted_at).sort((a, b) => new Date(b.period_start || 0) - new Date(a.period_start || 0))
        if (reset) items.value = filtered
        hasMore.value = false
      } catch {}
      return
    }
    isOnline.value = true
    if (reset) items.value = d.items || []
    else items.value = [...items.value, ...(d.items || [])]
    hasMore.value = (d.page ?? 1) < (d.pages ?? 1)
  } finally { loading.value = false }
}

async function loadMore() {
  if (loadingMore.value || !hasMore.value) return
  loadingMore.value = true; page.value++
  try { await loadAll(false) } finally { loadingMore.value = false }
}

async function pullRefresh(ev) { await loadAll(true); ev.target.complete() }
function openDetail(i) { detail.value = { ...i } }
function onOnline() { isOnline.value = true; loadAll(true) }
function onOffline() { isOnline.value = false }

onMounted(() => {
  auth.value = getAuth()
  window.addEventListener('online', onOnline)
  window.addEventListener('offline', onOffline)
  loadAll(true)
  pollTimer = setInterval(() => { if (isOnline.value && !loading.value) loadAll(true) }, 60_000)
})
onIonViewWillEnter(() => { auth.value = getAuth(); loadAll(true) })
onUnmounted(() => {
  window.removeEventListener('online', onOnline)
  window.removeEventListener('offline', onOffline)
  clearInterval(pollTimer)
})
</script>

<style scoped>
*{box-sizing:border-box}
.ag-hdr{--background:transparent;border:none}
.ag-hdr-bg{background:linear-gradient(135deg,#001D3D,#003566,#003F88);padding:0 0 6px}
.ag-hdr-top{display:flex;align-items:center;gap:4px;padding:6px 8px 0}
.ag-menu{--color:rgba(255,255,255,.7)}
.ag-hdr-title{flex:1;display:flex;flex-direction:column;min-width:0}
.ag-eye{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:rgba(255,255,255,.4)}
.ag-h1{font-size:17px;font-weight:800;color:#fff}
.ag-new-btn{padding:5px 10px;border-radius:99px;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.12);color:#fff;font-size:11px;font-weight:800;cursor:pointer}
.ag-ref{width:32px;height:32px;border-radius:50%;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.05);color:rgba(255,255,255,.7);cursor:pointer;display:flex;align-items:center;justify-content:center}
.ag-ref:disabled{opacity:.4}

.ag-stats{display:flex;gap:4px;padding:8px}
.ag-st{flex:1;padding:8px 4px;border-radius:8px;background:rgba(255,255,255,.06);text-align:center;min-width:0}
.ag-st-n{display:block;font-size:18px;font-weight:900;color:#fff;line-height:1}
.ag-st-l{display:block;font-size:9px;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.5);margin-top:2px}
.ag-st--g .ag-st-n{color:#81C784}
.ag-st--r .ag-st-n{color:#FF8A80}
.ag-st--a .ag-st-n{color:#FFB74D}

.ag-fr{display:flex;gap:4px;padding:0 8px;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch}.ag-fr::-webkit-scrollbar{display:none}
.ag-fp{padding:5px 12px;border-radius:99px;border:1px solid rgba(255,255,255,.12);background:transparent;color:rgba(255,255,255,.55);font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;flex-shrink:0}
.ag-fp--on{background:rgba(255,255,255,.2);color:#fff;border-color:rgba(255,255,255,.3)}

.ag-content{--background:#F0F4FA}
.ag-offline{padding:8px 12px;background:#FFF3E0;border-bottom:1px solid #FFB74D;font-size:11px;color:#BF360C;text-align:center;font-weight:600}

.ag-skels{padding:8px 10px}
.ag-skel{background:#fff;border-radius:8px;padding:12px;margin-bottom:6px;border:1px solid #E8EDF5}
.ag-sk{height:10px;background:linear-gradient(90deg,#E2E8F0 25%,#F1F5F9 50%,#E2E8F0 75%);background-size:200% 100%;animation:ag-sh 1.4s linear infinite;border-radius:4px;margin-bottom:6px}
.ag-sk--1{width:60%}.ag-sk--2{width:90%}.ag-sk--3{width:40%}
@keyframes ag-sh{0%{background-position:200% 0}100%{background-position:-200% 0}}

.ag-empty{display:flex;flex-direction:column;align-items:center;padding:60px 20px;gap:12px}
.ag-empty-ico{color:#B0BEC5}
.ag-empty-title{font-size:17px;font-weight:800;color:#1A3A5C}
.ag-empty-sub{font-size:12px;color:#64748B;text-align:center;max-width:280px}
.ag-empty-btn{margin-top:8px;padding:10px 24px;border-radius:99px;border:none;background:#1565C0;color:#fff;font-size:13px;font-weight:700;cursor:pointer}

.ag-list{padding:8px 10px}
.ag-card{background:#fff;border-radius:10px;border:1px solid #E8EDF5;padding:12px;margin-bottom:8px;cursor:pointer;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.ag-card--unsynced{border-left:3px solid #F59E0B}
.ag-card-h{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.ag-card-period{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:800;color:#1A3A5C}
.ag-period-arrow{color:#94A3B8;font-weight:600}
.ag-sync{font-size:9px;font-weight:800;padding:2px 6px;border-radius:4px;text-transform:uppercase}
.ag-sync--synced{background:#D1FAE5;color:#047857}
.ag-sync--unsynced{background:#FEF3C7;color:#92400E}
.ag-sync--failed{background:#FEE2E2;color:#991B1B}
.ag-sync--unknown{background:#F1F5F9;color:#64748B}
.ag-card-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:#F1F5F9;border-radius:6px;overflow:hidden;margin-bottom:8px}
.ag-cs{display:flex;flex-direction:column;align-items:center;padding:8px 4px;background:#fff;gap:2px}
.ag-cs-n{font-size:15px;font-weight:900;color:#1A3A5C;line-height:1}
.ag-cs-l{font-size:9px;font-weight:700;color:#94A3B8;text-transform:uppercase}
.ag-cs--r .ag-cs-n{color:#DC2626}
.ag-card-notes{font-size:11px;color:#64748B;margin-bottom:6px;font-style:italic;line-height:1.4}
.ag-card-foot{display:flex;justify-content:space-between;font-size:10px;color:#94A3B8;font-weight:600;border-top:1px solid #F0F4FA;padding-top:6px}
.ag-card-meta{font-weight:600}

.ag-loadmore{display:flex;justify-content:center;padding:10px 0}
.ag-lm-btn{padding:8px 22px;border-radius:99px;border:1px solid #CBD5E1;background:#fff;color:#475569;font-size:12px;font-weight:700;cursor:pointer}
.ag-lm-btn:disabled{opacity:.4}

.ag-modal::part(content){border-radius:14px 14px 0 0}
.ag-mt{--background:#fff;--border-width:0 0 1px 0;--border-color:#E8EDF5;--min-height:36px}
.ag-handle{width:32px;height:3px;border-radius:2px;background:#DDE3EA;margin:0 10px}
.ag-mc{--background:#fff}
.ag-mp{padding:14px 16px 0}
.ag-mhdr{padding:14px;border-radius:10px;margin-bottom:14px;background:linear-gradient(135deg,#F0F9FF,#E0F2FE);border:1px solid #BAE6FD}
.ag-mhdr-cat{display:block;font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;color:#0369A1;margin-bottom:4px}
.ag-mhdr-title{display:block;font-size:16px;font-weight:800;color:#1A3A5C;line-height:1.3}
.ag-mhdr-sub{display:block;font-size:11px;color:#64748B;margin-top:4px;font-weight:600}
.ag-msh{font-size:13px;font-weight:800;color:#1A3A5C;margin:14px 0 8px}
.ag-dg{background:#F8FAFC;border-radius:8px;border:1px solid #E8EDF5;overflow:hidden}
.ag-dr{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;border-top:1px solid #E8EDF5}
.ag-dr:first-child{border-top:none}
.ag-dk{font-size:11px;color:#64748B;font-weight:600}
.ag-dv{font-size:12px;font-weight:700;color:#1A3A5C}
.ag-dv--r{color:#DC2626!important}
.ag-dv--g{color:#10B981!important}
.ag-dv--a{color:#EA580C!important}
.ag-notes{background:#FEF9C3;border-radius:8px;padding:12px;border:1px solid #FDE68A;font-size:12px;color:#713F12;line-height:1.5}

/* Audit C-C2: green-tick checklist replaces bare PASS/FAIL pills. */
.ag-checklist{display:flex;flex-direction:column;gap:6px}
.ag-check{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-radius:8px;border:1px solid;background:#fff;font-size:12px;line-height:1.5}
.ag-check--ok{border-color:#A7F3D0;background:#ECFDF5;color:#065F46}
.ag-check--bad{border-color:#FECACA;background:#FEF2F2;color:#991B1B}
.ag-check-mark{flex-shrink:0;width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:900;color:#fff}
.ag-check--ok .ag-check-mark{background:#10B981}
.ag-check--bad .ag-check-mark{background:#DC2626}
.ag-check-text{flex:1}

/* Audit C-C4: technical-detail disclosure — closed by default. */
.ag-tech{margin-top:10px}
.ag-tech>summary{cursor:pointer;font-size:11px;font-weight:700;color:#64748B;padding:6px 0;list-style:none}
.ag-tech>summary::-webkit-details-marker{display:none}
.ag-tech>summary::before{content:'▸ ';color:#94A3B8;font-weight:600}
.ag-tech[open]>summary::before{content:'▾ '}
.ag-tech .ag-dg{margin-top:4px}

@media(min-width:500px){.ag-list{max-width:480px;margin:0 auto}}
</style>
