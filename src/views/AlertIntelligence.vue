<template>
  <IonPage>
    <!-- ═══ HEADER ═══════════════════════════════════════════════════ -->
    <IonHeader class="ai-hdr" translucent>
      <div class="ai-hdr-bg">
        <div class="ai-hdr-top">
          <IonButtons slot="start"><IonBackButton default-href="/alerts" class="ai-back"/></IonButtons>
          <div class="ai-hdr-title">
            <!-- Audit C-D1: replace acronym-stuffed eyebrow with plain
                 language; small WHO/IHR badges go in the chip strip below. -->
            <span class="ai-hdr-eye">Outbreak response timeliness</span>
            <span class="ai-hdr-h1">Alert Intelligence</span>
          </div>
          <IonButtons slot="end">
            <button class="ai-ref" :class="loading && 'ai-spin'" :disabled="loading" @click="refresh"><IonIcon :icon="refreshOutline" slot="icon-only"/></button>
          </IonButtons>
        </div>

        <!-- Scope badge -->
        <div class="ai-scope">
          <span class="ai-scope-k">{{ scope.label }}</span>
          <span class="ai-scope-v">{{ scope.code || 'unscoped' }}</span>
          <span class="ai-scope-r">{{ scope.role || '—' }}</span>
        </div>

        <!-- Headline KPI strip — Audit C-D2: every KPI now ships a short
             plain-language sub-line beneath the label so touch users (who
             can't trigger tooltips) understand what each number means. -->
        <div class="ai-kpis">
          <div class="ai-kpi" title="Total alerts in your area">
            <span class="ai-kpi-n">{{ rollup.counts.total }}</span>
            <span class="ai-kpi-l">Alerts</span>
            <span class="ai-kpi-sub">in your area</span>
          </div>
          <div class="ai-kpi"
               :class="rollup.notify.rate_pct != null && rollup.notify.rate_pct < 80 && 'ai-kpi--warn'"
               title="Of every 10 alerts, how many were notified within 24 hours? Amber = fewer than 8 in 10 (under 80%).">
            <span class="ai-kpi-n">{{ rollup.notify.rate_pct == null ? '—' : rollup.notify.rate_pct + '%' }}</span>
            <span class="ai-kpi-l">Within 24h notice</span>
            <span class="ai-kpi-sub">target: 8 in 10</span>
          </div>
          <div class="ai-kpi"
               :class="rollup.respond.rate_pct != null && rollup.respond.rate_pct < 80 && 'ai-kpi--warn'"
               title="Of every 10 alerts, how many had a response inside 7 days? Amber = fewer than 8 in 10.">
            <span class="ai-kpi-n">{{ rollup.respond.rate_pct == null ? '—' : rollup.respond.rate_pct + '%' }}</span>
            <span class="ai-kpi-l">Within 7d response</span>
            <span class="ai-kpi-sub">target: 8 in 10</span>
          </div>
          <div class="ai-kpi"
               :class="rollup.ihr.tier1 > 0 && 'ai-kpi--crit'"
               title="Top-priority diseases that must be reported automatically to WHO. Red = at least one is open.">
            <span class="ai-kpi-n">{{ rollup.ihr.tier1 }}</span>
            <span class="ai-kpi-l">Top-priority (Tier 1)</span>
            <span class="ai-kpi-sub">auto-notified to WHO</span>
          </div>
          <div class="ai-kpi"
               :class="rollup.breaches.total > 0 && 'ai-kpi--crit'"
               title="Alerts where a deadline (24h notice or 7d response) was missed.">
            <span class="ai-kpi-n">{{ rollup.breaches.total }}</span>
            <span class="ai-kpi-l">Targets missed</span>
            <span class="ai-kpi-sub">deadlines breached</span>
          </div>
        </div>
        <!-- Audit C-D5: rewrite legend in plain English (no "Tier-1" jargon). -->
        <div class="ai-legend" aria-label="KPI colour key">
          <span class="ai-legend-i"><span class="ai-legend-sw ai-legend-sw--warn"/>Amber: less than 8 in 10 alerts hit the deadline</span>
          <span class="ai-legend-i"><span class="ai-legend-sw ai-legend-sw--crit"/>Red: a top-priority alert is open OR a deadline was missed</span>
        </div>

        <!-- Tabs -->
        <div class="ai-tabs">
          <button v-for="t in TABS" :key="t.v" :class="['ai-tab', tab === t.v && 'ai-tab--on']" @click="tab = t.v">
            {{ t.l }}
            <span v-if="t.v === 'insights' && insights.length > 0" class="ai-tab-b">{{ insights.length }}</span>
            <span v-else-if="t.v === 'followups' && fuSummary.overdue > 0" class="ai-tab-b ai-tab-b--warn">{{ fuSummary.overdue }}</span>
          </button>
        </div>
      </div>
    </IonHeader>

    <IonContent class="ai-content" :fullscreen="true">
      <IonRefresher slot="fixed" @ionRefresh="pull($event)"><IonRefresherContent/></IonRefresher>

      <div v-if="!isOnline" class="ai-offline">Offline — showing cached data</div>

      <!-- ═══ COMPLIANCE TAB ════════════════════════════════════════════ -->
      <section v-show="tab === 'compliance'" class="ai-sec">
        <!-- Stage 1: Detect -->
        <article class="ai-stage ai-stage--detect">
          <header class="ai-stage-h">
            <span class="ai-stage-n">7</span>
            <div class="ai-stage-meta">
              <span class="ai-stage-l">Detect</span>
              <span class="ai-stage-s">Emergence → detection · 168h target</span>
            </div>
            <span class="ai-stage-badge ai-stage-badge--na">NOT COMPUTABLE</span>
          </header>
          <p class="ai-stage-body">{{ rollup.detect.reason }}</p>
          <p class="ai-stage-hint"><strong>Enable:</strong> {{ rollup.detect.hint }}</p>
        </article>

        <!-- Stage 2: Notify -->
        <article :class="['ai-stage', 'ai-stage--notify', rollup.notify.rate_pct != null && rollup.notify.rate_pct < 80 && 'ai-stage--breach']">
          <header class="ai-stage-h">
            <span class="ai-stage-n">1</span>
            <div class="ai-stage-meta">
              <span class="ai-stage-l">Notify</span>
              <span class="ai-stage-s">Detection → notification · 24h target · IHR Art. 6</span>
            </div>
            <span :class="['ai-stage-badge', rollup.notify.rate_pct == null ? 'ai-stage-badge--na' : rollup.notify.rate_pct >= 80 ? 'ai-stage-badge--ok' : 'ai-stage-badge--bad']">
              {{ rollup.notify.rate_pct == null ? 'NO DATA' : rollup.notify.rate_pct + '% ON TARGET' }}
            </span>
          </header>
          <div class="ai-stats">
            <div class="ai-stat"><span class="ai-stat-n">{{ rollup.notify.on_target }}</span><span class="ai-stat-l">Within 24h</span></div>
            <div class="ai-stat ai-stat--bad"><span class="ai-stat-n">{{ rollup.notify.breach }}</span><span class="ai-stat-l">Over 24h</span></div>
            <div class="ai-stat ai-stat--pend"><span class="ai-stat-n">{{ rollup.notify.pending }}</span><span class="ai-stat-l">Open (not yet ack'd)</span></div>
          </div>
          <div class="ai-bar"><div class="ai-bar-f ai-bar-f--ok" :style="{ width: pct(rollup.notify.on_target, rollup.counts.acked + rollup.counts.closed) + '%' }"/><div class="ai-bar-f ai-bar-f--bad" :style="{ width: pct(rollup.notify.breach, rollup.counts.acked + rollup.counts.closed) + '%' }"/></div>
        </article>

        <!-- Stage 3: Respond -->
        <article :class="['ai-stage', 'ai-stage--respond', rollup.respond.rate_pct != null && rollup.respond.rate_pct < 80 && 'ai-stage--breach']">
          <header class="ai-stage-h">
            <span class="ai-stage-n">7</span>
            <div class="ai-stage-meta">
              <span class="ai-stage-l">Respond</span>
              <span class="ai-stage-s">Detection → early response · 168h target · RTSL 14 actions</span>
            </div>
            <span :class="['ai-stage-badge', rollup.respond.rate_pct == null ? 'ai-stage-badge--na' : rollup.respond.rate_pct >= 80 ? 'ai-stage-badge--ok' : 'ai-stage-badge--bad']">
              {{ rollup.respond.rate_pct == null ? 'NO DATA' : rollup.respond.rate_pct + '% ON TARGET' }}
            </span>
          </header>
          <div class="ai-stats">
            <div class="ai-stat"><span class="ai-stat-n">{{ rollup.respond.on_target }}</span><span class="ai-stat-l">Closed ≤168h</span></div>
            <div class="ai-stat ai-stat--bad"><span class="ai-stat-n">{{ rollup.respond.breach }}</span><span class="ai-stat-l">Closed &gt;168h</span></div>
            <div class="ai-stat ai-stat--pend"><span class="ai-stat-n">{{ rollup.respond.open_overdue }}</span><span class="ai-stat-l">Open over 7d</span></div>
          </div>
        </article>

        <!-- Breach breakdown -->
        <h3 class="ai-sh">Breach breakdown</h3>
        <div class="ai-breach">
          <div class="ai-breach-row"><span class="ai-br-l">Detect bottleneck</span><span class="ai-br-n">{{ rollup.breaches.by_phase.DETECT }}</span></div>
          <div class="ai-breach-row"><span class="ai-br-l">Notify bottleneck</span><span class="ai-br-n">{{ rollup.breaches.by_phase.NOTIFY }}</span></div>
          <div class="ai-breach-row"><span class="ai-br-l">Respond bottleneck</span><span class="ai-br-n">{{ rollup.breaches.by_phase.RESPOND }}</span></div>
        </div>

        <!-- IHR rollup -->
        <h3 class="ai-sh">IHR classification</h3>
        <div class="ai-ihr">
          <div class="ai-ihr-row ai-ihr-row--t1">
            <div><span class="ai-ihr-tag">TIER 1</span><span class="ai-ihr-l">Always notifiable</span></div>
            <span class="ai-ihr-n">{{ rollup.ihr.tier1 }}</span>
          </div>
          <div class="ai-ihr-row ai-ihr-row--t2">
            <div><span class="ai-ihr-tag">TIER 2</span><span class="ai-ihr-l">Annex 2 assessment</span></div>
            <span class="ai-ihr-n">{{ rollup.ihr.tier2 }}</span>
          </div>
          <div class="ai-ihr-row ai-ihr-row--hit">
            <div><span class="ai-ihr-tag">2/4 MET</span><span class="ai-ihr-l">Notify WHO within 24h</span></div>
            <span class="ai-ihr-n">{{ rollup.ihr.annex2_threshold_hits }}</span>
          </div>
        </div>

        <!-- Enforcement notice -->
        <div class="ai-enforce">
          <span class="ai-enforce-tag">ENFORCEMENT</span>
          <div>
            <strong>This view shows only what data supports.</strong>
            Detect times are not computed because the system does not capture emergence timestamps per alert.
            Notify and Respond metrics are derived from <code>created_at</code> / <code>acknowledged_at</code> / <code>closed_at</code>.
            This conforms to IHR reporting 
          </div>
        </div>
      </section>

      <!-- ═══ INSIGHTS TAB ═════════════════════════════════════════════ -->
      <section v-show="tab === 'insights'" class="ai-sec">
        <div v-if="insights.length === 0" class="ai-empty">
          <span class="ai-empty-t">No insights</span>
          <span class="ai-empty-s">Insights generate from your live alert data. Refresh to rescan.</span>
        </div>
        <article v-for="ins in insights" :key="ins.id" :class="['ai-ins', 'ai-ins--'+ins.level]">
          <div class="ai-ins-hdr">
            <span class="ai-ins-ic">{{ levelIcon(ins.level) }}</span>
            <span class="ai-ins-t">{{ ins.title }}</span>
          </div>
          <p class="ai-ins-body">{{ ins.body }}</p>
          <ul v-if="ins.actions && ins.actions.length" class="ai-ins-acts">
            <li v-for="(a, i) in ins.actions" :key="i">{{ a }}</li>
          </ul>
          <div v-if="ins.cite" class="ai-ins-cite">{{ ins.cite }}</div>
        </article>

        <h3 class="ai-sh">7-1-7 breach ledger</h3>
        <div v-if="breaches.length === 0" class="ai-empty-s ai-pad">No alerts currently breaching 7-1-7.</div>
        <article v-for="a in breaches" :key="a.id" class="ai-breach-card" @click="openAlert(a)">
          <div class="ai-breach-top">
            <span class="ai-breach-code">{{ a.alert_code }}</span>
            <span :class="['ai-breach-phase', 'ai-breach-phase--'+(scorecard(a).bottleneck||'').toLowerCase()]">{{ scorecard(a).bottleneck || '—' }}</span>
          </div>
          <div class="ai-breach-title">{{ a.alert_title || a.alert_code }}</div>
          <div class="ai-breach-meta">
            <span>{{ a.routed_to_level }}</span>
            <span>{{ a.risk_level }}</span>
            <span v-if="a.poe_code">POE: {{ a.poe_code }}</span>
          </div>
        </article>
      </section>

      <!-- ═══ FOLLOW-UPS TAB ═══════════════════════════════════════════ -->
      <section v-show="tab === 'followups'" class="ai-sec">
        <div class="ai-fu-summary">
          <div class="ai-fu-kv"><span class="ai-fu-k">Total</span><span class="ai-fu-v">{{ fuSummary.total }}</span></div>
          <div class="ai-fu-kv"><span class="ai-fu-k">Completed</span><span class="ai-fu-v ai-fu-v--ok">{{ fuSummary.completed }}</span></div>
          <div class="ai-fu-kv"><span class="ai-fu-k">In progress</span><span class="ai-fu-v">{{ fuSummary.inProgress }}</span></div>
          <div class="ai-fu-kv"><span class="ai-fu-k">Overdue</span><span class="ai-fu-v ai-fu-v--bad">{{ fuSummary.overdue }}</span></div>
          <div class="ai-fu-kv"><span class="ai-fu-k">% done</span><span class="ai-fu-v">{{ fuSummary.completion_pct }}%</span></div>
        </div>

        <div v-if="eligibleAlerts.length === 0" class="ai-empty-s ai-pad">No alerts in scope eligible for follow-up tracking.</div>
        <div v-else>
          <div class="ai-fu-filter">
            <select v-model="fuAlertFilter" class="ai-fu-sel">
              <option value="">All alerts ({{ eligibleAlerts.length }})</option>
              <option v-for="a in eligibleAlerts" :key="a.id" :value="a.id">
                {{ (a.alert_title || a.alert_code).slice(0, 40) }} · {{ a.risk_level }}
              </option>
            </select>
            <button class="ai-fu-seed" :disabled="!fuAlertFilter" @click="seedFollowups">Seed RTSL 14</button>
          </div>

          <div v-for="bucket in fuByAlert" :key="bucket.alertId" class="ai-fu-group">
            <header class="ai-fu-gh">
              <span class="ai-fu-gh-t">{{ bucket.alertTitle }}</span>
              <span class="ai-fu-gh-n">{{ bucket.items.length }} action{{ bucket.items.length === 1 ? '' : 's' }}</span>
            </header>
            <article v-for="f in bucket.items" :key="f.client_uuid" :class="['ai-fu-row', 'ai-fu-row--'+f.status.toLowerCase(), isOverdue(f) && 'ai-fu-row--overdue']">
              <div class="ai-fu-main">
                <span class="ai-fu-lbl">{{ f.action_label }}</span>
                <div class="ai-fu-meta">
                  <span class="ai-fu-code">{{ f.action_code }}</span>
                  <span v-if="f.blocks_closure" class="ai-fu-blk">Blocks closure</span>
                  <span v-if="f.due_at" :class="['ai-fu-due', isOverdue(f) && 'ai-fu-due--bad']">Due {{ fmtDate(f.due_at) }}</span>
                </div>
              </div>
              <select :value="f.status" class="ai-fu-stat" @change="setStatus(f, $event.target.value)">
                <option value="PENDING">Pending</option>
                <option value="IN_PROGRESS">In progress</option>
                <option value="COMPLETED">Completed</option>
                <option value="BLOCKED">Blocked</option>
                <option value="NOT_APPLICABLE">N/A</option>
              </select>
            </article>
          </div>
        </div>
      </section>

      <!-- ═══ ANALYTICS TAB ════════════════════════════════════════════ -->
      <section v-show="tab === 'analytics'" class="ai-sec">
        <h3 class="ai-sh">Risk distribution</h3>
        <div class="ai-risk">
          <div v-for="(n, k) in riskDist" :key="k" :class="['ai-risk-row', 'ai-risk-row--'+k.toLowerCase()]">
            <span class="ai-risk-l">{{ k }}</span>
            <div class="ai-risk-t"><div class="ai-risk-f" :style="{ width: pctOf(n, riskDistMax) + '%' }"/></div>
            <span class="ai-risk-n">{{ n }}</span>
          </div>
        </div>

        <h3 class="ai-sh">Top syndromes</h3>
        <div v-if="syndromes.length === 0" class="ai-empty-s ai-pad">No syndrome data.</div>
        <div v-else class="ai-syn">
          <div v-for="s in syndromes.slice(0, 10)" :key="s.label" class="ai-syn-row">
            <span class="ai-syn-l">{{ s.label }}</span>
            <div class="ai-syn-t"><div class="ai-syn-f" :style="{ width: pctOf(s.count, syndromes[0].count) + '%' }"/></div>
            <span class="ai-syn-n">{{ s.count }}</span>
          </div>
        </div>

        <h3 class="ai-sh">Geographic concentration</h3>
        <div v-if="conc.length === 0" class="ai-empty-s ai-pad">No geographic data.</div>
        <div v-else class="ai-conc">
          <div v-for="(c, i) in conc" :key="c.label" class="ai-conc-row">
            <span class="ai-conc-r">{{ i + 1 }}</span>
            <span class="ai-conc-l">{{ c.label }}</span>
            <span class="ai-conc-n">{{ c.count }}</span>
          </div>
        </div>

        <h3 class="ai-sh">Acknowledgement time histogram</h3>
        <div v-if="totalAcked === 0" class="ai-empty-s ai-pad">No acknowledged alerts yet.</div>
        <div v-else class="ai-hist">
          <div v-for="b in histogram" :key="b.label" class="ai-hist-col">
            <div class="ai-hist-bar"><div class="ai-hist-f" :style="{ height: pctOf(b.count, histMax) + '%' }"/></div>
            <span class="ai-hist-n">{{ b.count }}</span>
            <span class="ai-hist-l">{{ b.label }}</span>
          </div>
        </div>

        <h3 class="ai-sh">Escalation funnel</h3>
        <div class="ai-funnel">
          <div v-for="(r, level) in funnel" :key="level" class="ai-funnel-row">
            <span class="ai-funnel-l">{{ level }}</span>
            <div class="ai-funnel-bars">
              <div class="ai-fb ai-fb--open" :style="{ width: pctOf(r.open, funnelMax) + '%' }"><span v-if="r.open">{{ r.open }}</span></div>
              <div class="ai-fb ai-fb--ack" :style="{ width: pctOf(r.acked, funnelMax) + '%' }"><span v-if="r.acked">{{ r.acked }}</span></div>
              <div class="ai-fb ai-fb--closed" :style="{ width: pctOf(r.closed, funnelMax) + '%' }"><span v-if="r.closed">{{ r.closed }}</span></div>
            </div>
          </div>
        </div>
        <div class="ai-legend">
          <span><span class="ai-sw ai-fb--open"/>Open</span>
          <span><span class="ai-sw ai-fb--ack"/>Acknowledged</span>
          <span><span class="ai-sw ai-fb--closed"/>Closed</span>
        </div>
      </section>

      <div style="height:64px"/>
    </IonContent>

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="3000" position="top" @didDismiss="toast.show = false"/>
  </IonPage>
</template>

<script setup>
import {
  IonPage, IonHeader, IonContent, IonButtons, IonBackButton,
  IonIcon, IonToast, IonRefresher, IonRefresherContent,
  onIonViewDidEnter,
} from '@ionic/vue'
import { refreshOutline } from 'ionicons/icons'
import { ref, computed, reactive, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  APP, STORE, SYNC, dbGet, dbGetAll, dbPut, dbGetByIndex, safeDbPut,
  genUUID, isoNow, getPlatform, getDeviceId,
} from '@/services/poeDB'
import engine, {
  userScope, classifyIHRTier, evaluate717, assessAnnex2,
  generateIntelligenceInsights, compliance717Summary, followupSummary,
  recommendedFollowups, RTSL_14_ACTIONS,
  riskDistribution, syndromeCloud, concentrationByGeo,
  responseTimeHistogram, escalationFunnel,
} from '@/composables/useAlertIntelligence'

const router = useRouter()

function getAuth() { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {} }
const auth  = ref(getAuth())
const scope = computed(() => userScope(auth.value))

const TABS = [
  { v: 'compliance', l: 'Compliance' },
  { v: 'insights',   l: 'Insights'   },
  { v: 'followups',  l: 'Follow-ups' },
  { v: 'analytics',  l: 'Analytics'  },
]
const tab = ref('compliance')

// State
const alerts    = ref([])
const followups = ref([])
const loading   = ref(false)
const isOnline  = ref(navigator.onLine)
const toast     = reactive({ show: false, msg: '', color: 'success' })
const fuAlertFilter = ref('')
let pollTimer = null

// ── API ─────────────────────────────────────────────────────────────────────
async function api(path, opts = {}) {
  const uid = auth.value?.id
  if (!uid) return null
  const sep = path.includes('?') ? '&' : '?'
  const url = `${window.SERVER_URL}${path}${sep}user_id=${uid}`
  const ctrl = new AbortController()
  const tid = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS)
  try {
    const res = await fetch(url, {
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      signal: ctrl.signal, ...opts,
    })
    clearTimeout(tid)
    const j = await res.json().catch(() => null)
    return { ok: res.ok, status: res.status, body: j }
  } catch (e) { clearTimeout(tid); return { ok: false, status: 0, body: null, error: e?.message } }
}

async function loadAlerts() {
  const res = await api('/alerts?per_page=200&page=1')
  if (!res?.ok || !res.body?.success) {
    isOnline.value = false
    // Fallback to local IDB
    alerts.value = await dbGetAll(STORE.ALERTS).catch(() => [])
    return
  }
  isOnline.value = true
  alerts.value = res.body.data?.items || []
}

async function loadFollowups() {
  // Prefer local IDB (includes unsynced); enrich from server per alert
  try {
    const local = await dbGetAll(STORE.ALERT_FOLLOWUPS).catch(() => [])
    const a = auth.value
    const scopeMatch = f => {
      if (!f) return false
      if (f.deleted_at) return false
      const s = scope.value
      if (s.kind === 'COUNTRY')  return !s.code || f.country_code === s.code
      if (s.kind === 'PHEOC')    return !s.code || f.pheoc_code === s.code
      if (s.kind === 'DISTRICT') return !s.code || f.district_code === s.code
      if (s.kind === 'POE')      return !s.code || f.poe_code === s.code
      return true
    }
    followups.value = local.filter(scopeMatch)
  } catch (e) {
    followups.value = []
  }
}

async function refresh() {
  if (loading.value) return
  loading.value = true
  try {
    await Promise.all([loadAlerts(), loadFollowups()])
  } finally { loading.value = false }
}
async function pull(ev) { await refresh(); ev.target.complete() }

// ── Computeds ───────────────────────────────────────────────────────────────
const rollup   = computed(() => compliance717Summary(alerts.value))
const insights = computed(() => generateIntelligenceInsights(alerts.value, auth.value))
const breaches = computed(() => alerts.value.filter(a => evaluate717(a).overall === 'BREACH').slice(0, 50))

const riskDist = computed(() => riskDistribution(alerts.value))
const riskDistMax = computed(() => Math.max(1, ...Object.values(riskDist.value)))
const syndromes = computed(() => syndromeCloud(alerts.value))
const conc = computed(() => {
  const key = scope.value.kind === 'POE' ? 'district_code'
           : scope.value.kind === 'DISTRICT' ? 'poe_code'
           : scope.value.kind === 'PHEOC' ? 'district_code'
           : 'poe_code'
  return concentrationByGeo(alerts.value, key)
})
const histogram = computed(() => responseTimeHistogram(alerts.value))
const histMax   = computed(() => Math.max(1, ...histogram.value.map(b => b.count)))
const totalAcked = computed(() => alerts.value.filter(a => a.acknowledged_at).length)
const funnel = computed(() => escalationFunnel(alerts.value))
const funnelMax = computed(() => {
  let m = 1
  for (const r of Object.values(funnel.value)) m = Math.max(m, r.open + r.acked + r.closed)
  return m
})

// Follow-up summaries — filtered
const filteredFollowups = computed(() => {
  if (!fuAlertFilter.value) return followups.value
  return followups.value.filter(f => Number(f.alert_id) === Number(fuAlertFilter.value))
})
const fuSummary = computed(() => followupSummary(filteredFollowups.value))

// Eligible alerts for follow-up tracking (Tier 1 / Tier 2 / CRITICAL)
const eligibleAlerts = computed(() =>
  alerts.value.filter(a => {
    const tier = classifyIHRTier(a).tier
    return tier === 1 || tier === 2 || a.risk_level === 'CRITICAL' || a.risk_level === 'HIGH'
  })
)

// Follow-ups grouped by alert (for display)
const fuByAlert = computed(() => {
  const groups = new Map()
  for (const f of filteredFollowups.value) {
    const key = f.alert_id || f.alert_client_uuid
    if (!groups.has(key)) {
      const related = alerts.value.find(a => Number(a.id) === Number(key) || a.client_uuid === key)
      groups.set(key, {
        alertId: key,
        alertTitle: related ? (related.alert_title || related.alert_code) : `Alert #${key}`,
        items: [],
      })
    }
    groups.get(key).items.push(f)
  }
  // Sort items by due_at (nulls last), then by label
  for (const g of groups.values()) {
    g.items.sort((a, b) => {
      const da = a.due_at ? new Date(a.due_at).getTime() : Infinity
      const db = b.due_at ? new Date(b.due_at).getTime() : Infinity
      return da - db
    })
  }
  return Array.from(groups.values())
})

// ── Helpers ─────────────────────────────────────────────────────────────────
function scorecard(a) { return evaluate717(a) }
function pct(n, d) { return d > 0 ? Math.round((n / d) * 100) : 0 }
function pctOf(n, d) { if (!d || d <= 0) return 0; return Math.min(100, Math.round((n / d) * 100)) }
function levelIcon(lv) { return lv === 'critical' ? '!!' : lv === 'high' ? '!' : lv === 'medium' ? 'i' : '•' }
function fmtDate(dt) { if (!dt) return ''; try { return new Date(String(dt).replace(' ', 'T')).toLocaleString([], { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit' }) } catch { return dt } }
function isOverdue(f) {
  if (!f?.due_at) return false
  if (f.status === 'COMPLETED' || f.status === 'NOT_APPLICABLE') return false
  try { return new Date(String(f.due_at).replace(' ', 'T')).getTime() < Date.now() } catch { return false }
}
function showToast(msg, color = 'success') { toast.show = true; toast.msg = msg; toast.color = color }

function openAlert(a) {
  router.push({ path: '/alerts', query: { focus: a.id } })
}

// ── Seed RTSL 14 follow-ups for a selected alert ────────────────────────────
async function seedFollowups() {
  const alertId = Number(fuAlertFilter.value)
  if (!alertId) return
  const alert = alerts.value.find(a => Number(a.id) === alertId)
  if (!alert) return
  const a = auth.value
  if (!a?.id) { showToast('Session expired.', 'danger'); return }

  // Check which actions already exist
  const existing = followups.value.filter(f => Number(f.alert_id) === alertId)
  const haveCodes = new Set(existing.map(f => f.action_code))

  const recs = recommendedFollowups(alert)
  const createdAt = alert.created_at ? new Date(String(alert.created_at).replace(' ', 'T')).getTime() : Date.now()

  let created = 0
  for (const r of recs) {
    if (haveCodes.has(r.code)) continue
    const due = new Date(createdAt + r.due_at_offset_hrs * 3.6e6)
    const due_at = due.toISOString().replace('T', ' ').slice(0, 19)
    const record = {
      client_uuid: genUUID(),
      alert_id: alert.id,
      alert_client_uuid: alert.client_uuid,
      action_code: r.code,
      action_label: r.label,
      status: 'PENDING',
      due_at,
      started_at: null,
      completed_at: null,
      completed_by_user_id: null,
      assigned_to_user_id: null,
      assigned_to_role: null,
      notes: null,
      evidence_ref: null,
      who_notification_reference: null,
      blocks_closure: r.blocks_closure ? 1 : 0,
      country_code: alert.country_code,
      district_code: alert.district_code,
      poe_code: alert.poe_code,
      created_by_user_id: a.id,
      device_id: getDeviceId(),
      app_version: APP.VERSION,
      platform: getPlatform(),
      record_version: 1,
      sync_status: SYNC.UNSYNCED,
      synced_at: null,
      sync_attempt_count: 0,
      last_sync_error: null,
      deleted_at: null,
      created_at: isoNow(),
      updated_at: isoNow(),
    }
    // safeDbPut, not dbPut — `record` may carry a Vue Proxy reference (e.g.
    // alert.client_uuid copied from a reactive ref). Raw dbPut hits the
    // structured-clone barrier and throws DataCloneError.
    await safeDbPut(STORE.ALERT_FOLLOWUPS, record)
    // Best-effort server sync
    if (isOnline.value) {
      try {
        const res = await fetch(`${window.SERVER_URL}/alerts/${alert.id}/followups`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ ...record, created_by_user_id: a.id, blocks_closure: !!r.blocks_closure }),
        })
        const body = await res.json().catch(() => ({}))
        if (res.ok && body?.success) {
          await safeDbPut(STORE.ALERT_FOLLOWUPS, {
            ...record,
            id: body.data?.id ?? null,
            sync_status: SYNC.SYNCED,
            synced_at: isoNow(),
            record_version: 2,
            updated_at: isoNow(),
          })
        }
      } catch (_) { /* keep local */ }
    }
    created++
  }
  await loadFollowups()
  showToast(created > 0 ? `Seeded ${created} follow-up action${created === 1 ? '' : 's'}.` : 'Already seeded.', 'success')
}

async function setStatus(f, status) {
  // AlertFollowupsController::update requires a reason ≥10 chars when status
  // is NOT_APPLICABLE or BLOCKED. Without it the PATCH 422s, was caught
  // silently, and the record was stuck UNSYNCED forever.
  let reason = null
  if (status === 'NOT_APPLICABLE' || status === 'BLOCKED') {
    const promptMsg = status === 'BLOCKED'
      ? 'What is blocking this follow-up? (min 10 characters)'
      : 'Why is this follow-up not applicable? (min 10 characters)'
    const entered = (typeof window !== 'undefined' && window.prompt)
      ? window.prompt(promptMsg, '')
      : ''
    if (!entered || entered.trim().length < 10) {
      showToast('A reason of at least 10 characters is required.', 'warning')
      return
    }
    reason = entered.trim()
  }

  const updated = {
    ...f,
    status,
    notes: reason ?? f.notes,
    started_at: status === 'IN_PROGRESS' && !f.started_at ? isoNow() : f.started_at,
    completed_at: status === 'COMPLETED' ? isoNow() : (status === 'PENDING' || status === 'IN_PROGRESS' ? null : f.completed_at),
    completed_by_user_id: status === 'COMPLETED' ? (auth.value?.id || null) : f.completed_by_user_id,
    record_version: (f.record_version || 1) + 1,
    sync_status: SYNC.UNSYNCED,
    updated_at: isoNow(),
  }
  await safeDbPut(STORE.ALERT_FOLLOWUPS, updated)
  // Update in memory
  const idx = followups.value.findIndex(x => x.client_uuid === f.client_uuid)
  if (idx !== -1) { followups.value[idx] = updated; followups.value = [...followups.value] }
  // Best-effort server sync
  if (isOnline.value && f.id) {
    try {
      const body = { user_id: auth.value?.id, status }
      if (reason) body.reason = reason
      const res = await fetch(`${window.SERVER_URL}/alert-followups/${f.id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      })
      const respBody = await res.json().catch(() => ({}))
      if (res.ok && respBody?.success) {
        await safeDbPut(STORE.ALERT_FOLLOWUPS, {
          ...updated,
          sync_status: SYNC.SYNCED,
          synced_at: isoNow(),
          record_version: (updated.record_version || 1) + 1,
          updated_at: isoNow(),
        })
      } else {
        // Surface server validation errors instead of silent stuck-pending.
        const msg = respBody?.error?.validation_errors
          ? Object.values(respBody.error.validation_errors).flat().join(' ')
          : (respBody?.message || `Status update failed (HTTP ${res.status}).`)
        showToast(msg, 'danger')
      }
    } catch (_) {}
  }
}

// ── Lifecycle ───────────────────────────────────────────────────────────────
function onOnline()  { isOnline.value = true; refresh() }
function onOffline() { isOnline.value = false }

onMounted(() => {
  auth.value = getAuth()
  window.addEventListener('online',  onOnline)
  window.addEventListener('offline', onOffline)
  refresh()
  pollTimer = setInterval(() => { if (isOnline.value && !loading.value) refresh() }, 30_000)
})
onIonViewDidEnter(() => { auth.value = getAuth(); refresh() })
onUnmounted(() => {
  window.removeEventListener('online',  onOnline)
  window.removeEventListener('offline', onOffline)
  clearInterval(pollTimer)
})
</script>

<style scoped>
*{box-sizing:border-box}

/* Header */
.ai-hdr{--background:transparent;border:none}
.ai-hdr-bg{background:linear-gradient(135deg,#001D3D,#003566,#003F88);padding:0 0 0}
.ai-hdr-top{display:flex;align-items:center;gap:4px;padding:8px 8px 0}
.ai-back{--color:rgba(255,255,255,.85)}
.ai-hdr-title{flex:1;display:flex;flex-direction:column;min-width:0}
.ai-hdr-eye{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1.4px;color:rgba(255,255,255,.5)}
.ai-hdr-h1{font-size:17px;font-weight:800;color:#fff;letter-spacing:-.2px}
.ai-ref{width:32px;height:32px;border-radius:50%;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.06);color:rgba(255,255,255,.78);display:flex;align-items:center;justify-content:center}
.ai-ref:disabled{opacity:.4}
.ai-spin{animation:ai-rotate 1s linear infinite}
@keyframes ai-rotate{to{transform:rotate(360deg)}}

.ai-scope{display:flex;align-items:center;gap:8px;padding:6px 10px 0;font-size:10px}
.ai-scope-k{font-weight:800;color:#fff;padding:2px 8px;border-radius:4px;background:rgba(255,255,255,.14);letter-spacing:.4px}
.ai-scope-v{color:rgba(255,255,255,.85);font-weight:700;font-family:ui-monospace,Menlo,monospace}
.ai-scope-r{color:rgba(255,255,255,.5);font-weight:700;margin-left:auto;text-transform:uppercase;letter-spacing:.4px}

.ai-kpis{display:flex;gap:4px;padding:8px 8px 6px}
.ai-kpi{flex:1;padding:8px 4px;border-radius:8px;background:rgba(255,255,255,.06);display:flex;flex-direction:column;align-items:center;gap:1px;min-width:0}
.ai-kpi-n{font-size:17px;font-weight:900;color:#fff;line-height:1;font-variant-numeric:tabular-nums}
.ai-kpi-l{font-size:9px;font-weight:700;color:rgba(255,255,255,.7);text-transform:uppercase;letter-spacing:.3px;text-align:center}
/* Audit C-D2: plain-English sub-line under each KPI label so touch users
   (who can't trigger title= tooltips) still know what each number means. */
.ai-kpi-sub{font-size:8.5px;font-weight:600;color:rgba(255,255,255,.55);text-align:center;margin-top:2px;line-height:1.2}
.ai-kpi--warn{background:rgba(234,88,12,.28)}
.ai-kpi--warn .ai-kpi-n{color:#FFB74D}
.ai-kpi--crit{background:rgba(220,38,38,.32)}
.ai-kpi--crit .ai-kpi-n{color:#FF8A80}
/* Audit RV-3: KPI colour key — keeps amber/red meaningful at first glance. */
.ai-legend{display:flex;flex-wrap:wrap;gap:10px 14px;padding:0 10px 6px;font-size:9.5px;color:rgba(255,255,255,.78);letter-spacing:.2px}
.ai-legend-i{display:inline-flex;align-items:center;gap:5px}
.ai-legend-sw{display:inline-block;width:9px;height:9px;border-radius:2.5px}
.ai-legend-sw--warn{background:#FFB74D}
.ai-legend-sw--crit{background:#FF8A80}

.ai-tabs{display:flex;gap:0;padding:2px 4px 0;overflow-x:auto;scrollbar-width:none}
.ai-tabs::-webkit-scrollbar{display:none}
.ai-tab{flex:1;min-width:88px;padding:10px 6px 12px;background:transparent;border:none;border-bottom:2px solid transparent;color:rgba(255,255,255,.55);font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap;display:flex;align-items:center;justify-content:center;gap:4px}
.ai-tab--on{color:#fff;border-bottom-color:#60A5FA}
.ai-tab-b{background:#DC2626;color:#fff;font-size:9px;font-weight:900;border-radius:10px;padding:1px 6px}
.ai-tab-b--warn{background:#EA580C}

/* Content */
.ai-content{--background:#F0F4FA}
.ai-offline{padding:8px 12px;background:#FFF3E0;border-bottom:1px solid #FFB74D;font-size:11px;color:#BF360C;text-align:center;font-weight:600}
.ai-sec{padding:10px 10px 0}
.ai-sh{font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:1px;margin:16px 4px 8px}
.ai-pad{padding:0 4px}

/* Stage cards */
.ai-stage{margin:0 0 10px;background:#fff;border:1px solid #E8EDF5;border-radius:12px;padding:14px;box-shadow:0 1px 3px rgba(0,0,0,.04);border-left:4px solid #94A3B8}
.ai-stage--detect{border-left-color:#9333EA}
.ai-stage--notify{border-left-color:#1E40AF}
.ai-stage--respond{border-left-color:#059669}
.ai-stage--breach{border-left-color:#DC2626;background:#FEF2F2}
.ai-stage-h{display:flex;align-items:center;gap:12px;margin-bottom:10px}
.ai-stage-n{font-size:26px;font-weight:900;line-height:1;color:#1A3A5C;width:40px;flex-shrink:0;text-align:center}
.ai-stage--detect .ai-stage-n{color:#9333EA}
.ai-stage--notify .ai-stage-n{color:#1E40AF}
.ai-stage--respond .ai-stage-n{color:#059669}
.ai-stage--breach .ai-stage-n{color:#DC2626}
.ai-stage-meta{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.ai-stage-l{font-size:14px;font-weight:800;color:#1A3A5C}
.ai-stage-s{font-size:10.5px;color:#64748B;font-weight:600}
.ai-stage-badge{font-size:9.5px;font-weight:900;padding:3px 8px;border-radius:4px;letter-spacing:.3px;flex-shrink:0}
.ai-stage-badge--ok{background:#D1FAE5;color:#047857}
.ai-stage-badge--bad{background:#FEE2E2;color:#991B1B}
.ai-stage-badge--na{background:#F1F5F9;color:#64748B}
.ai-stage-body{font-size:11.5px;color:#475569;line-height:1.5;margin:6px 0 4px}
.ai-stage-hint{font-size:10.5px;color:#6366F1;background:#EEF2FF;padding:6px 8px;border-radius:6px;margin:0;line-height:1.4}
.ai-stage-hint strong{font-weight:800}

.ai-stats{display:flex;gap:8px;margin:8px 0 6px}
.ai-stat{flex:1;padding:8px 6px;background:#F0FDF4;border:1px solid #BBF7D0;border-radius:6px;display:flex;flex-direction:column;align-items:center;gap:2px}
.ai-stat--bad{background:#FEF2F2;border-color:#FECACA}
.ai-stat--pend{background:#FFF7ED;border-color:#FED7AA}
.ai-stat-n{font-size:18px;font-weight:900;color:#047857;font-variant-numeric:tabular-nums;line-height:1}
.ai-stat--bad .ai-stat-n{color:#991B1B}
.ai-stat--pend .ai-stat-n{color:#9A3412}
.ai-stat-l{font-size:9px;font-weight:700;color:#475569;text-align:center;line-height:1.2}

.ai-bar{height:6px;background:#F1F5F9;border-radius:3px;overflow:hidden;display:flex;margin-top:6px}
.ai-bar-f{height:100%;transition:width .3s}
.ai-bar-f--ok{background:#10B981}
.ai-bar-f--bad{background:#DC2626}

/* Breach rows */
.ai-breach{background:#fff;border:1px solid #E8EDF5;border-radius:10px;padding:4px 12px;display:flex;flex-direction:column}
.ai-breach-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #F0F4FA}
.ai-breach-row:last-child{border-bottom:none}
.ai-br-l{font-size:12px;color:#475569;font-weight:600}
.ai-br-n{font-size:14px;font-weight:800;color:#DC2626;font-variant-numeric:tabular-nums}

/* IHR rollup */
.ai-ihr{display:flex;flex-direction:column;gap:6px}
.ai-ihr-row{display:flex;justify-content:space-between;align-items:center;padding:10px 12px;border-radius:10px;border:1px solid}
.ai-ihr-row--t1{background:#F3E8FF;border-color:#D8B4FE}
.ai-ihr-row--t2{background:#FEF2F2;border-color:#FECACA}
.ai-ihr-row--hit{background:#FFEDD5;border-color:#FED7AA}
.ai-ihr-tag{font-size:9.5px;font-weight:900;padding:2px 7px;border-radius:4px;margin-right:8px;background:#fff;letter-spacing:.4px}
.ai-ihr-row--t1 .ai-ihr-tag{color:#6B21A8}
.ai-ihr-row--t2 .ai-ihr-tag{color:#991B1B}
.ai-ihr-row--hit .ai-ihr-tag{color:#9A3412}
.ai-ihr-l{font-size:12px;font-weight:700;color:#1A3A5C}
.ai-ihr-n{font-size:20px;font-weight:900;color:#1A3A5C;font-variant-numeric:tabular-nums}
.ai-ihr-row--t1 .ai-ihr-n{color:#6B21A8}
.ai-ihr-row--t2 .ai-ihr-n{color:#991B1B}
.ai-ihr-row--hit .ai-ihr-n{color:#9A3412}

/* Enforcement notice */
.ai-enforce{margin:14px 0 0;display:flex;gap:10px;align-items:flex-start;padding:12px;background:#EEF2FF;border:1px solid #C7D2FE;border-radius:10px;font-size:11.5px;color:#3730A3;line-height:1.5}
.ai-enforce-tag{font-size:9.5px;font-weight:900;padding:3px 8px;border-radius:4px;background:#4F46E5;color:#fff;flex-shrink:0;letter-spacing:.4px}
.ai-enforce strong{font-weight:800}
.ai-enforce code{background:#fff;padding:1px 5px;border-radius:3px;font-size:10.5px;font-family:ui-monospace,Menlo,monospace;color:#1E1B4B}

/* Empty states */
.ai-empty{padding:30px 20px;text-align:center;display:flex;flex-direction:column;gap:6px;align-items:center}
.ai-empty-t{font-size:15px;font-weight:800;color:#1A3A5C}
.ai-empty-s{font-size:11.5px;color:#64748B;line-height:1.4;max-width:280px}

/* Insights cards */
.ai-ins{margin:0 0 10px;background:#fff;border:1px solid #E8EDF5;border-radius:10px;padding:12px 14px;position:relative;overflow:hidden}
.ai-ins::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px}
.ai-ins--critical::before{background:#DC2626}
.ai-ins--high::before{background:#EA580C}
.ai-ins--medium::before{background:#CA8A04}
.ai-ins--info::before{background:#16A34A}
.ai-ins-hdr{display:flex;align-items:center;gap:8px;margin-bottom:6px}
.ai-ins-ic{width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:900;color:#fff;flex-shrink:0}
.ai-ins--critical .ai-ins-ic{background:#DC2626}
.ai-ins--high .ai-ins-ic{background:#EA580C}
.ai-ins--medium .ai-ins-ic{background:#CA8A04}
.ai-ins--info .ai-ins-ic{background:#16A34A}
.ai-ins-t{font-size:13px;font-weight:800;color:#1A3A5C;flex:1;line-height:1.3}
.ai-ins-body{font-size:12px;color:#475569;line-height:1.5;margin:4px 0 6px}
.ai-ins-acts{font-size:11.5px;color:#1A3A5C;padding-left:18px;margin:6px 0;line-height:1.5}
.ai-ins-cite{font-size:10px;color:#94A3B8;font-style:italic;margin-top:6px}

.ai-breach-card{margin:0 0 8px;background:#fff;border:1px solid #FECACA;border-radius:10px;padding:10px 12px;cursor:pointer}
.ai-breach-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:3px}
.ai-breach-code{font-size:10px;font-family:ui-monospace,Menlo,monospace;color:#94A3B8}
.ai-breach-phase{font-size:9.5px;font-weight:900;padding:2px 7px;border-radius:4px;background:#FEE2E2;color:#991B1B;letter-spacing:.3px}
.ai-breach-phase--detect{background:#F3E8FF;color:#6B21A8}
.ai-breach-phase--respond{background:#FFEDD5;color:#9A3412}
.ai-breach-title{font-size:13px;font-weight:800;color:#1A3A5C;margin-bottom:4px}
.ai-breach-meta{display:flex;gap:8px;font-size:10.5px;color:#64748B;font-weight:600}

/* Follow-ups */
.ai-fu-summary{display:flex;gap:6px;overflow-x:auto;padding:0 0 10px;scrollbar-width:none}
.ai-fu-summary::-webkit-scrollbar{display:none}
.ai-fu-kv{flex-shrink:0;padding:8px 12px;background:#fff;border:1px solid #E8EDF5;border-radius:8px;display:flex;flex-direction:column;gap:2px;min-width:78px}
.ai-fu-k{font-size:9.5px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.3px}
.ai-fu-v{font-size:17px;font-weight:900;color:#1A3A5C;font-variant-numeric:tabular-nums;line-height:1}
.ai-fu-v--ok{color:#047857}
.ai-fu-v--bad{color:#DC2626}

.ai-fu-filter{display:flex;gap:8px;margin:0 0 8px}
.ai-fu-sel{flex:1;padding:9px 10px;border:1px solid #E8EDF5;border-radius:8px;background:#fff;font-size:12px;color:#1A3A5C;font-family:inherit;min-width:0}
.ai-fu-seed{padding:0 14px;border-radius:8px;border:none;background:#1E40AF;color:#fff;font-size:11.5px;font-weight:800;cursor:pointer;white-space:nowrap}
.ai-fu-seed:disabled{opacity:.45;cursor:not-allowed}

.ai-fu-group{margin:0 0 12px;background:#fff;border:1px solid #E8EDF5;border-radius:10px;overflow:hidden}
.ai-fu-gh{display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:#F8FAFC;border-bottom:1px solid #E8EDF5}
.ai-fu-gh-t{font-size:12.5px;font-weight:800;color:#1A3A5C;flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding-right:10px}
.ai-fu-gh-n{font-size:10px;color:#94A3B8;font-weight:700;flex-shrink:0}
.ai-fu-row{display:flex;gap:10px;align-items:center;padding:10px 12px;border-top:1px solid #F0F4FA}
.ai-fu-row:first-of-type{border-top:none}
.ai-fu-row--completed{background:#F0FDF4}
.ai-fu-row--in_progress{background:#EFF6FF}
.ai-fu-row--overdue{background:#FEF2F2}
.ai-fu-row--not_applicable{opacity:.55}
.ai-fu-main{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px}
.ai-fu-lbl{font-size:12px;font-weight:700;color:#1A3A5C;line-height:1.35}
.ai-fu-meta{display:flex;gap:6px;flex-wrap:wrap;font-size:10px;color:#64748B}
.ai-fu-code{font-family:ui-monospace,Menlo,monospace;color:#94A3B8}
.ai-fu-blk{color:#DC2626;font-weight:700}
.ai-fu-due{color:#64748B;font-weight:600}
.ai-fu-due--bad{color:#DC2626;font-weight:800}
.ai-fu-stat{padding:5px 8px;border:1px solid #E8EDF5;border-radius:6px;background:#fff;font-size:11px;font-weight:700;color:#1A3A5C;flex-shrink:0;cursor:pointer}

/* Analytics */
.ai-risk{display:flex;flex-direction:column;gap:6px}
.ai-risk-row{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#fff;border:1px solid #E8EDF5;border-radius:8px}
.ai-risk-l{width:68px;font-size:11px;font-weight:800;color:#1A3A5C;flex-shrink:0}
.ai-risk-t{flex:1;height:8px;background:#F1F5F9;border-radius:4px;overflow:hidden}
.ai-risk-f{height:100%;transition:width .3s}
.ai-risk-row--critical .ai-risk-f{background:linear-gradient(90deg,#DC2626,#991B1B)}
.ai-risk-row--high .ai-risk-f{background:linear-gradient(90deg,#EA580C,#C2410C)}
.ai-risk-row--medium .ai-risk-f{background:linear-gradient(90deg,#CA8A04,#A16207)}
.ai-risk-row--low .ai-risk-f{background:#10B981}
.ai-risk-n{width:34px;text-align:right;font-size:12px;font-weight:800;color:#1A3A5C;font-variant-numeric:tabular-nums;flex-shrink:0}

.ai-syn{display:flex;flex-direction:column;gap:6px}
.ai-syn-row{display:flex;align-items:center;gap:10px;padding:8px 12px;background:#fff;border:1px solid #E8EDF5;border-radius:8px}
.ai-syn-l{flex:1;min-width:0;font-size:11.5px;font-weight:700;color:#1A3A5C;text-transform:capitalize;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ai-syn-t{width:80px;height:6px;background:#F1F5F9;border-radius:3px;overflow:hidden;flex-shrink:0}
.ai-syn-f{height:100%;background:linear-gradient(90deg,#1E40AF,#3B82F6);transition:width .3s}
.ai-syn-n{width:28px;text-align:right;font-size:11px;font-weight:800;color:#1A3A5C;font-variant-numeric:tabular-nums}

.ai-conc{display:flex;flex-direction:column;gap:4px}
.ai-conc-row{display:flex;align-items:center;gap:10px;padding:9px 12px;background:#fff;border:1px solid #E8EDF5;border-radius:8px}
.ai-conc-r{width:20px;height:20px;border-radius:50%;background:#DBEAFE;color:#1E40AF;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ai-conc-l{flex:1;font-size:12px;font-weight:700;color:#1A3A5C;font-family:ui-monospace,Menlo,monospace}
.ai-conc-n{font-size:13px;font-weight:800;color:#1E40AF;font-variant-numeric:tabular-nums}

.ai-hist{display:flex;align-items:flex-end;gap:6px;padding:14px;background:#fff;border:1px solid #E8EDF5;border-radius:10px;height:160px}
.ai-hist-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;height:100%;min-width:0}
.ai-hist-bar{width:100%;flex:1;display:flex;align-items:flex-end;background:#F1F5F9;border-radius:3px;overflow:hidden}
.ai-hist-f{width:100%;background:linear-gradient(180deg,#60A5FA,#1E40AF);transition:height .3s}
.ai-hist-n{font-size:11px;font-weight:800;color:#1A3A5C;font-variant-numeric:tabular-nums}
.ai-hist-l{font-size:9px;font-weight:700;color:#64748B;text-align:center}

.ai-funnel{display:flex;flex-direction:column;gap:8px}
.ai-funnel-row{display:flex;align-items:center;gap:10px;padding:10px 12px;background:#fff;border:1px solid #E8EDF5;border-radius:10px}
.ai-funnel-l{width:72px;font-size:11px;font-weight:800;color:#1A3A5C;flex-shrink:0}
.ai-funnel-bars{flex:1;display:flex;gap:2px;height:20px}
.ai-fb{background:#E2E8F0;color:#fff;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;min-width:0;border-radius:3px;font-variant-numeric:tabular-nums;transition:width .3s}
.ai-fb--open{background:#DC2626}
.ai-fb--ack{background:#3B82F6}
.ai-fb--closed{background:#64748B}
.ai-legend{display:flex;gap:14px;justify-content:center;padding:10px;font-size:10px;color:#475569;font-weight:600}
.ai-legend span{display:flex;align-items:center;gap:4px}
.ai-sw{width:10px;height:10px;border-radius:2px;display:inline-block}

@media(min-width:500px){.ai-sec{max-width:540px;margin-left:auto;margin-right:auto}}
</style>
