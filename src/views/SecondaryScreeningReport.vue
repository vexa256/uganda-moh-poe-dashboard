<template>
  <ion-page>
    <ion-header :translucent="false">
      <ion-toolbar color="primary">
        <ion-buttons slot="start"><ion-menu-button/></ion-buttons>
        <ion-title>{{ t('Secondary Screening Report') }}</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="refresh" :disabled="loading"><ion-icon :icon="refreshOutline" slot="icon-only"/></ion-button>
          <ion-button @click="exportPdf" :disabled="loading || !filtered.length">
            <ion-icon :icon="documentTextOutline" slot="start"/>PDF
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
      <ion-toolbar class="ssr-filterbar">
        <div class="ssr-filter-scroll">
          <span v-if="activeFilters > 0" class="ssr-active-chip" :title="`${activeFilters} active filter${activeFilters === 1 ? '' : 's'}`">
            <ion-icon :icon="funnelOutline"/>{{ activeFilters }}
          </span>
          <select v-model="filter.period" class="ssr-pill" :class="{ 'ssr-pill--on': filter.period !== FILTER_DEFAULTS.period }">
            <option value="today">{{ t('Today') }}</option>
            <option value="7d">{{ t('Last 7 days') }}</option>
            <option value="30d">{{ t('Last 30 days') }}</option>
            <option value="90d">{{ t('Last 90 days') }}</option>
            <option value="all">{{ t('All time') }}</option>
          </select>
          <select v-if="isNational" v-model="filter.poe" class="ssr-pill" :class="{ 'ssr-pill--on': filter.poe }">
            <option value="">All POEs</option>
            <option v-for="p in poeList" :key="p" :value="p">{{ p }}</option>
          </select>
          <select v-model="filter.case_status" class="ssr-pill" :class="{ 'ssr-pill--on': filter.case_status }">
            <option value="">{{ t('Case Status') }}: {{ t('All') }}</option>
            <option value="OPEN">{{ t('Open') }}</option>
            <option value="IN_PROGRESS">{{ t('In progress') }}</option>
            <option value="DISPOSITIONED">{{ t('Dispositioned') }}</option>
            <option value="CLOSED">{{ t('Closed') }}</option>
          </select>
          <select v-model="filter.risk_level" class="ssr-pill" :class="{ 'ssr-pill--on': filter.risk_level }">
            <option value="">{{ t('Risk Level') }}: {{ t('All') }}</option>
            <option value="LOW">{{ t('Low') }}</option>
            <option value="MEDIUM">{{ t('Medium') }}</option>
            <option value="HIGH">{{ t('High') }}</option>
            <option value="CRITICAL">{{ t('Critical') }}</option>
          </select>
          <button v-if="activeFilters > 0" class="ssr-pill ssr-pill--reset" @click="resetFilters" type="button">{{ t('Reset') }}</button>
        </div>
      </ion-toolbar>
    </ion-header>

    <ion-content class="ssr-content">
      <ion-refresher slot="fixed" @ionRefresh="onPullRefresh"><ion-refresher-content/></ion-refresher>

      <div v-if="lastSyncedLabel" class="ssr-sync-strip">
        <ion-icon :icon="syncOutline" :class="{ 'ssr-spin': loading }"/>
        <span>{{ t('Updated') }} {{ lastSyncedLabel }}</span>
        <span v-if="isNational" class="ssr-scope-tag">NATIONAL · {{ filter.poe || 'All POEs' }}</span>
        <span v-else class="ssr-scope-tag">POE · {{ scope.label }}</span>
      </div>

      <template v-if="!firstLoadDone">
        <div class="ssr-kpis">
          <div v-for="i in 6" :key="i" class="ssr-kpi ssr-skel"><div class="ssr-skel-bar ssr-skel-bar--big"/><div class="ssr-skel-bar"/></div>
        </div>
        <div class="ssr-card ssr-skel"><div class="ssr-skel-bar ssr-skel-bar--title"/><div class="ssr-skel-block"/></div>
        <div class="ssr-card ssr-skel"><div class="ssr-skel-bar ssr-skel-bar--title"/><div class="ssr-skel-block ssr-skel-block--tall"/></div>
      </template>
      <div v-else-if="!filtered.length" class="ssr-empty">
        <ion-icon :icon="archiveOutline"/><span>No secondary cases match the current filters.</span>
      </div>

      <template v-else>
        <!-- KPI hero — count-up animated, staggered -->
        <div class="ssr-kpis">
          <div class="ssr-kpi ssr-rise" style="--accent: #0B2545; --i: 0">
            <div class="ssr-kpi-num">{{ fmtN(kpiTotal.value) }}</div>
            <div class="ssr-kpi-lbl">{{ t('Cases opened') }}</div>
          </div>
          <div class="ssr-kpi ssr-rise" style="--accent: #1D4ED8; --i: 1">
            <div class="ssr-kpi-num">{{ fmtN(kpiDispositioned.value) }}</div>
            <div class="ssr-kpi-lbl">{{ t('Dispositioned') }} <span class="ssr-kpi-aux">{{ kpis.dispRate }}%</span></div>
          </div>
          <div class="ssr-kpi ssr-rise" style="--accent: #166534; --i: 2">
            <div class="ssr-kpi-num">{{ fmtN(kpiClosed.value) }}</div>
            <div class="ssr-kpi-lbl">{{ t('Closed') }}</div>
          </div>
          <div class="ssr-kpi ssr-rise" style="--accent: #B45309; --i: 3">
            <div class="ssr-kpi-num">{{ fmtN(kpiHigh.value) }}</div>
            <div class="ssr-kpi-lbl">{{ t('High') }} {{ t('Risk Level') }}</div>
          </div>
          <div class="ssr-kpi ssr-rise" style="--accent: #B91C1C; --i: 4">
            <div class="ssr-kpi-num">{{ fmtN(kpiCritical.value) }}</div>
            <div class="ssr-kpi-lbl">{{ t('Critical') }}</div>
          </div>
          <div class="ssr-kpi ssr-rise" style="--accent: #7C3AED; --i: 5">
            <div class="ssr-kpi-num">{{ kpis.medianTtd }}</div>
            <div class="ssr-kpi-lbl">Median time-to-disposition</div>
          </div>
        </div>

        <!-- Case-state funnel -->
        <div class="ssr-card ssr-rise" style="--i: 6">
          <div class="ssr-card-titlebar">
            <div>
              <h3 class="ssr-card-title">Case lifecycle funnel</h3>
              <p class="ssr-card-sub">Cohort flow from referral arrival to terminal closure.</p>
            </div>
            <ExplainModal
              title="Case lifecycle funnel"
              what="Each stage shows how many cases reached it. Drop-off is the number of cases that did not advance to the next stage."
              how="A wide top with a narrow bottom is normal at the start of a period. Persistent narrowing between Opened → In progress means cases are stalling at intake."
              action="If 'Closed' is much smaller than 'Dispositioned', supervisors should follow up on cases stuck in DISPOSITIONED state."
              :columns="['Stage', 'Count', '% of opened']"
              :rows="stateFunnel.map(s => [s.label, s.count, s.pct + '%'])"
              source="Filtered secondary_screenings.case_status."
            />
          </div>
          <div class="ssr-funnel">
            <div v-for="(s, i) in stateFunnel" :key="s.label" class="ssr-funnel-row">
              <div class="ssr-funnel-bar" :style="{ width: (s.pct || 1) + '%', background: s.color }">
                <span class="ssr-funnel-num">{{ s.count }}</span>
              </div>
              <div class="ssr-funnel-meta">
                <span class="ssr-funnel-lbl">{{ s.label }}</span>
                <span class="ssr-funnel-pct">{{ s.pct }}%<span v-if="i > 0"> · drop-off {{ stateFunnel[i-1].count - s.count }}</span></span>
              </div>
            </div>
          </div>
        </div>

        <!-- Daily risk-over-time -->
        <div class="ssr-card ssr-rise" style="--i: 6">
          <div class="ssr-card-titlebar">
            <div>
              <h3 class="ssr-card-title">Daily caseload — last 14 days</h3>
              <p class="ssr-card-sub">Stacked by risk level. Spike detection highlights days with ≥ 3 high-risk cases.</p>
            </div>
            <ExplainModal
              title="Daily caseload — last 14 days"
              what="Per-day count of secondary cases opened. Coloured by risk level when a disposition has been recorded."
              how="A surge of HIGH/CRITICAL on consecutive days = early outbreak signal. Sustained low/medium with steady total = endemic baseline."
              action="≥ 3 HIGH/CRITICAL on the same day → escalate to district + PHEOC + national IHR NFP within 24 hours."
              :columns="['Date', 'Total cases', 'High/Critical']"
              :rows="dailyCaseRows"
              source="Filtered secondary_screenings; date from opened_at."
            />
          </div>
          <canvas ref="dailyCanvas" class="ssr-canvas"/>
        </div>

        <!-- Time-to-disposition distribution -->
        <div class="ssr-card ssr-rise" style="--i: 6">
          <div class="ssr-card-titlebar">
            <div>
              <h3 class="ssr-card-title">Time-to-disposition distribution</h3>
              <p class="ssr-card-sub">How fast cases are resolved from open → final disposition.</p>
            </div>
            <ExplainModal
              title="Time-to-disposition distribution"
              what="Histogram of how long cases stay in the lane before being dispositioned (or closed)."
              how="A long right-tail (cases > 24h) means clinical decisions are getting delayed. Tight clustering under 4h means the lane is performing well."
              action="If median TTD exceeds 4 hours, audit your secondary lane staffing and IHR Annex 2 SOP adherence."
              :columns="['Bucket', 'Cases']"
              :rows="ttdRows"
              source="opened_at → dispositioned_at (or closed_at fallback) on filtered secondary cases."
            />
          </div>
          <canvas ref="ttdCanvas" class="ssr-canvas"/>
        </div>

        <div class="ssr-grid-2">
          <!-- Risk distribution -->
          <div class="ssr-card ssr-rise" style="--i: 6">
            <div class="ssr-card-titlebar">
              <h3 class="ssr-card-title">Risk distribution</h3>
              <ExplainModal
                title="Risk distribution"
                what="Cases grouped by the risk level recorded on disposition (LOW / MEDIUM / HIGH / CRITICAL)."
                how="A healthy POE under endemic conditions sits mostly in LOW/MEDIUM. A growing HIGH/CRITICAL share is your earliest outbreak signal."
                action="If HIGH+CRITICAL exceeds 20 % over a sustained week, brief the supervisor and pre-position isolation capacity."
                :columns="['Risk level', 'Cases', '% of cases']"
                :rows="riskBreakdown.map(r => [r.label, r.count, r.pct + '%'])"
                source="Filtered secondary_screenings.risk_level."
              />
            </div>
            <div v-for="r in riskBreakdown" :key="r.code" class="ssr-row">
              <span class="ssr-row-lbl">{{ r.label }}</span>
              <div class="ssr-row-bar"><div class="ssr-row-bar-fill" :style="{ width: r.pct + '%', background: r.color }"/></div>
              <span class="ssr-row-val">{{ r.count }} <em>({{ r.pct }}%)</em></span>
            </div>
          </div>
          <!-- Final disposition -->
          <div class="ssr-card ssr-rise" style="--i: 6">
            <div class="ssr-card-titlebar">
              <h3 class="ssr-card-title">Final disposition</h3>
              <ExplainModal
                title="Final disposition"
                what="What ultimately happened to each case after secondary assessment."
                how="High 'Released — no concern' = primary screening filtering well. High 'Isolated/admitted' = real public-health pressure on the POE."
                action="A spike in 'Referred to health facility' for a single syndrome should trigger a cluster review."
                :columns="['Disposition', 'Cases', '% of cases']"
                :rows="dispBreakdown.map(r => [r.label, r.count, r.pct + '%'])"
                source="Filtered secondary_screenings.final_disposition."
              />
            </div>
            <div v-if="!dispBreakdown.length" class="ssr-mini-empty">No dispositions recorded yet.</div>
            <div v-for="r in dispBreakdown" :key="r.code" class="ssr-row">
              <span class="ssr-row-lbl">{{ r.label }}</span>
              <div class="ssr-row-bar"><div class="ssr-row-bar-fill" :style="{ width: r.pct + '%', background: '#1D4ED8' }"/></div>
              <span class="ssr-row-val">{{ r.count }} <em>({{ r.pct }}%)</em></span>
            </div>
          </div>
        </div>

        <!-- Syndromes -->
        <div class="ssr-card ssr-rise" style="--i: 6">
          <div class="ssr-card-titlebar">
            <div>
              <h3 class="ssr-card-title">Syndromic classification</h3>
              <p class="ssr-card-sub">WHO/IDSR syndromic surveillance categories.</p>
            </div>
            <ExplainModal
              title="Syndromic classification"
              what="Cases grouped by the WHO/IDSR syndrome category recorded by the secondary officer."
              how="The dominant syndrome is your main public-health pressure for this period. AWD/Bloody-diarrhea = food/water safety; ILI/SARI = respiratory clusters; VHF = maximum-priority outbreak response."
              action="Any single VHF case requires immediate notification to WHO via the IHR National Focal Point within 24 hours."
              :columns="['Syndrome', 'Cases', '% of cases']"
              :rows="syndromeBreakdown.map(r => [r.label, r.count, r.pct + '%'])"
              source="Filtered secondary_screenings.syndrome_classification."
            />
          </div>
          <div v-for="r in syndromeBreakdown" :key="r.code" class="ssr-row">
            <span class="ssr-row-lbl">{{ r.label }}</span>
            <div class="ssr-row-bar"><div class="ssr-row-bar-fill" :style="{ width: r.pct + '%', background: '#0EA5E9' }"/></div>
            <span class="ssr-row-val">{{ r.count }}</span>
          </div>
        </div>

        <!-- Top suspected diseases -->
        <div class="ssr-card ssr-rise" style="--i: 6">
          <div class="ssr-card-titlebar">
            <div>
              <h3 class="ssr-card-title">Top suspected diseases (engine top-1)</h3>
              <p class="ssr-card-sub">Highest-confidence diagnostic from the analysis engine.</p>
            </div>
            <ExplainModal
              title="Top suspected diseases (engine top-1)"
              what="Each case stamps a top-ranked disease via the intelligence engine. This card shows how often each disease was the #1 candidate."
              how="Repetition of the same #1 disease across many cases indicates an active cluster. New diseases emerging mid-period should trigger surveillance review."
              action="Cluster of any IHR Annex 2 always-notifiable disease (Ebola, Marburg, plague, smallpox) → immediate national + WHO notification."
              :columns="['Disease', 'Cases', '% of cases']"
              :rows="topDiseases.map(r => [r.label, r.count, r.pct + '%'])"
              source="secondary_suspected_diseases joined to filtered cases (rank_order = 1)."
            />
          </div>
          <div v-if="!topDiseases.length" class="ssr-mini-empty">No suspected diseases recorded.</div>
          <div v-for="r in topDiseases" :key="r.code" class="ssr-row">
            <span class="ssr-row-lbl">{{ r.label }}</span>
            <div class="ssr-row-bar"><div class="ssr-row-bar-fill" :style="{ width: r.pct + '%', background: '#B91C1C' }"/></div>
            <span class="ssr-row-val">{{ r.count }}</span>
          </div>
        </div>

        <!-- Country origins -->
        <div class="ssr-card ssr-rise" style="--i: 6">
          <div class="ssr-card-titlebar">
            <div>
              <h3 class="ssr-card-title">Travel-origin countries (cases)</h3>
              <p class="ssr-card-sub">Geographic context for outbreak signal correlation.</p>
            </div>
            <ExplainModal
              title="Travel-origin countries (cases)"
              what="Country cases say they are travelling from. Drives endemic-zone correlation in the engine."
              how="A spike from one country combined with a specific syndrome (e.g. Uganda + RASH_FEVER) should be cross-checked against active outbreak alerts in that country."
              action="Cross-reference with WHO Disease Outbreak News for the originating country."
              :columns="['Country (ISO-2)', 'Cases', '% of cases']"
              :rows="countryOrigins.map(r => [r.label, r.count, r.pct + '%'])"
              source="secondary_screenings.journey_start_country_code or traveler_nationality_country_code."
            />
          </div>
          <div v-if="!countryOrigins.length" class="ssr-mini-empty">No origin data recorded.</div>
          <div v-for="r in countryOrigins" :key="r.code" class="ssr-row">
            <span class="ssr-row-lbl">{{ r.label }}</span>
            <div class="ssr-row-bar"><div class="ssr-row-bar-fill" :style="{ width: r.pct + '%', background: '#7C3AED' }"/></div>
            <span class="ssr-row-val">{{ r.count }}</span>
          </div>
        </div>

        <!-- POE breakdown (NATIONAL only) -->
        <div v-if="isNational && poeBreakdown.length > 1" class="ssr-card">
          <div class="ssr-card-titlebar">
            <div>
              <h3 class="ssr-card-title">Per-POE caseload</h3>
              <p class="ssr-card-sub">National-scope breakdown across {{ poeBreakdown.length }} POEs.</p>
            </div>
            <ExplainModal
              title="Per-POE caseload"
              what="Caseload contributed by each POE in the country. 'HC' = HIGH+CRITICAL count for that POE."
              how="A small POE with a disproportionately high HC count should be investigated — it may be the entry corridor for an outbreak."
              action="If one POE shows >5x the HC rate of similar-volume POEs, send a deployable surveillance team within 24 hours."
              :columns="['POE', 'Cases', 'High/Critical', '% of national']"
              :rows="poeBreakdown.map(r => [r.poe, r.count, r.high, r.pct + '%'])"
              source="Filtered secondary_screenings.poe_code."
            />
          </div>
          <div v-for="r in poeBreakdown" :key="r.poe" class="ssr-row">
            <span class="ssr-row-lbl">{{ r.poe }}</span>
            <div class="ssr-row-bar"><div class="ssr-row-bar-fill" :style="{ width: r.pct + '%', background: '#0B2545' }"/></div>
            <span class="ssr-row-val">{{ r.count }} · {{ r.high }} HC</span>
          </div>
        </div>
      </template>
    </ion-content>
  </ion-page>
</template>

<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import {
  IonPage, IonHeader, IonToolbar, IonTitle, IonContent, IonButton, IonButtons,
  IonMenuButton, IonIcon, IonRefresher, IonRefresherContent,
} from '@ionic/vue'
import {
  documentTextOutline, archiveOutline, refreshOutline, syncOutline, funnelOutline,
} from 'ionicons/icons'
import { dbGetByIndex, dbGetAll, dbPut, STORE } from '@/services/poeDB'
import { useI18n } from '@/i18n'
import { createPdf } from '@/utils/premiumPdf'
import ExplainModal from '@/components/ExplainModal.vue'
import {
  fmtN, periodCutoff, smoothNumber, highDpiCanvas,
  activeFilterCount, useAutoRefresh,
} from '@/composables/useReportEngine'

const { t } = useI18n()
const FILTER_DEFAULTS = { period: '30d', poe: '', case_status: '', risk_level: '' }

const allCases = ref([])
const allDiseases = ref([])
const allAlerts = ref([])
const loading = ref(true)
const firstLoadDone = ref(false)
const lastSyncedAt = ref(null)
const dailyCanvas = ref(null)
const ttdCanvas = ref(null)

const filter = ref({ ...FILTER_DEFAULTS })

const kpiTotal         = smoothNumber(0)
const kpiDispositioned = smoothNumber(0)
const kpiClosed        = smoothNumber(0)
const kpiHigh          = smoothNumber(0)
const kpiCritical      = smoothNumber(0)
const TIER1 = ['smallpox', 'sars', 'influenza_new_subtype_zoonotic', 'polio']
const TIER2 = [
  'cholera', 'yellow_fever', 'ebola_virus_disease', 'marburg_virus_disease',
  'lassa_fever', 'cchf', 'rift_valley_fever', 'mpox', 'meningococcal_meningitis',
  'measles', 'mers', 'pneumonic_plague', 'bubonic_plague',
]

function getAuth() { try { return JSON.parse(sessionStorage.getItem('AUTH_DATA') || 'null') || {} } catch { return {} } }
const isNational = computed(() => String(getAuth()?.role_key || '').toUpperCase() === 'NATIONAL_ADMIN')
const scope = computed(() => {
  const a = getAuth()
  if (isNational.value) return { level: 'NATIONAL', label: filter.value.poe || 'All POEs' }
  return { level: 'POE', label: a?.poe_code || 'N/A' }
})

async function load() {
  loading.value = true
  // 12-second escape hatch — skeleton can NEVER stick forever.
  const escape = setTimeout(() => {
    if (!firstLoadDone.value) {
      loading.value = false
      firstLoadDone.value = true
      try { drawCharts() } catch {}
      console.warn('[SSR] load timed out at 12s — rendering IDB only')
    }
  }, 12_000)
  try {
    const auth = getAuth()
    const isNat = String(auth?.role_key || '').toUpperCase() === 'NATIONAL_ADMIN'

    // 1. IDB-first read — render immediately
    let all = []
    if (isNat) {
      all = await dbGetAll(STORE.SECONDARY_SCREENINGS).catch(() => []) || []
    } else if (auth.poe_code) {
      try { all = await dbGetByIndex(STORE.SECONDARY_SCREENINGS, 'poe_code', auth.poe_code) || [] }
      catch { all = await dbGetAll(STORE.SECONDARY_SCREENINGS).catch(() => []) || [] }
    } else {
      all = await dbGetAll(STORE.SECONDARY_SCREENINGS).catch(() => []) || []
    }
    all.sort((a, b) => new Date(b.opened_at || b.created_at || 0) - new Date(a.opened_at || a.created_at || 0))
    allCases.value = all
    allDiseases.value = await dbGetAll(STORE.SECONDARY_SUSPECTED_DISEASES).catch(() => []) || []
    allAlerts.value   = await dbGetAll(STORE.ALERTS).catch(() => []) || []
    if (all.length > 0) {
      firstLoadDone.value = true
      try { await nextTick(); drawCharts() } catch {}
    }

    // 2. Server back-fill so a fresh device / new login doesn't show empty.
    if (typeof navigator !== 'undefined' && navigator.onLine && window.SERVER_URL && auth?.id) {
      try {
        const merged = await fetchSecondaryFromServer(auth, all)
        if (merged && merged.length > all.length) {
          merged.sort((a, b) => new Date(b.opened_at || b.created_at || 0) - new Date(a.opened_at || a.created_at || 0))
          all = merged
          allCases.value = all
        }
      } catch (e) { console.debug('[SSR] server fetch skipped:', e?.message) }
    }

    lastSyncedAt.value = new Date()
  } catch (e) {
    console.warn('[SSR] load error', e?.message)
  } finally {
    clearTimeout(escape)
    loading.value = false
    firstLoadDone.value = true
    try { await nextTick(); drawCharts() } catch {}
  }
}

function refresh() { load() }
async function onPullRefresh(ev) { await load(); ev.target.complete() }
const activeFilters = computed(() => activeFilterCount(filter.value, FILTER_DEFAULTS))

async function fetchSecondaryFromServer(auth, idbList) {
  const out = []
  const cutoff = periodCutoff(filter.value.period) || (Date.now() - 90 * 86400_000)
  const dateFrom = new Date(cutoff).toISOString().slice(0, 10)
  for (let pg = 1; pg <= 6; pg++) {
    const p = new URLSearchParams({ user_id: auth.id, page: pg, per_page: 200, date_from: dateFrom })
    const ctrl = new AbortController()
    const tid = setTimeout(() => ctrl.abort(), 8000)
    let body = null
    try {
      const res = await fetch(`${window.SERVER_URL}/screening-records?${p}`, {
        headers: { Accept: 'application/json' }, signal: ctrl.signal,
      })
      if (!res.ok) break
      body = await res.json()
    } catch { break } finally { clearTimeout(tid) }
    const items = body?.data?.items || body?.data || []
    if (!items.length) break
    for (const c of items) {
      if (!c.client_uuid) continue
      out.push(c)
      try {
        const exists = idbList.find(r => r.client_uuid === c.client_uuid)
        if (!exists) await dbPut(STORE.SECONDARY_SCREENINGS, { ...c, sync_status: 'SYNCED' })
      } catch {}
    }
    if (items.length < 200) break
  }
  if (!out.length) return idbList
  const map = new Map()
  for (const r of idbList) if (r?.client_uuid) map.set(r.client_uuid, r)
  for (const r of out) if (r?.client_uuid) map.set(r.client_uuid, r)
  return Array.from(map.values())
}

const lastSyncedLabel = computed(() => {
  if (!lastSyncedAt.value) return null
  const ago = Math.round((Date.now() - lastSyncedAt.value.getTime()) / 1000)
  if (ago < 60) return `${ago}s ago`
  if (ago < 3600) return `${Math.round(ago / 60)}m ago`
  return lastSyncedAt.value.toLocaleTimeString('en-GB')
})

const poeList = computed(() => Array.from(new Set(allCases.value.map(c => c.poe_code).filter(Boolean))).sort())

const cases = computed(() => allCases.value)

const filtered = computed(() => {
  const cutoff = periodCutoff(filter.value.period)
  const f = filter.value
  return cases.value.filter(c => {
    const t = new Date(c.opened_at || c.created_at || 0).getTime()
    if (cutoff && t < cutoff) return false
    if (f.poe && (c.poe_code || '') !== f.poe) return false
    if (f.case_status && c.case_status !== f.case_status) return false
    if (f.risk_level && c.risk_level !== f.risk_level) return false
    return true
  })
})

const caseUuids = computed(() => new Set(filtered.value.map(c => c.client_uuid)))
const caseIds   = computed(() => new Set(filtered.value.map(c => c.id || c.server_id).filter(Boolean)))

const kpis = computed(() => {
  const list = filtered.value
  const total = list.length
  const dispositioned = list.filter(c => c.case_status === 'DISPOSITIONED').length
  const closed = list.filter(c => c.case_status === 'CLOSED').length
  const high = list.filter(c => c.risk_level === 'HIGH').length
  const critical = list.filter(c => c.risk_level === 'CRITICAL').length
  // Median time-to-disposition (hours)
  const ttdMs = list
    .map(c => {
      const o = new Date(c.opened_at || c.created_at || 0).getTime()
      const d = new Date(c.dispositioned_at || c.closed_at || 0).getTime()
      return d > 0 && o > 0 ? d - o : null
    })
    .filter(v => v != null && v > 0)
    .sort((a, b) => a - b)
  const median = ttdMs.length ? ttdMs[Math.floor(ttdMs.length / 2)] : null
  let medianTtd = '—'
  if (median != null) {
    const min = Math.round(median / 60000)
    if (min < 60) medianTtd = `${min}m`
    else if (min < 1440) medianTtd = `${Math.round(min / 60)}h`
    else medianTtd = `${Math.round(min / 1440)}d`
  }
  return { total, dispositioned, closed, high, critical, medianTtd, dispRate: total ? Math.round(dispositioned / total * 100) : 0 }
})

// Explain-table feeders for the canvas charts
const dailyCaseRows = computed(() => {
  const days = {}
  const now = Date.now()
  for (let i = 13; i >= 0; i--) {
    const d = new Date(now - i * 86400_000).toISOString().slice(0, 10)
    days[d] = { total: 0, hc: 0 }
  }
  for (const c of filtered.value) {
    const d = String(c.opened_at || c.created_at || '').slice(0, 10)
    if (!days[d]) continue
    days[d].total++
    if (c.risk_level === 'HIGH' || c.risk_level === 'CRITICAL') days[d].hc++
  }
  return Object.entries(days).map(([d, v]) => [d, v.total, v.hc])
})

const ttdRows = computed(() => {
  const buckets = [
    { label: '<30m',   max: 30 * 60_000, count: 0 },
    { label: '30-60m', max: 60 * 60_000, count: 0 },
    { label: '1-4h',   max: 4 * 3600_000, count: 0 },
    { label: '4-12h',  max: 12 * 3600_000, count: 0 },
    { label: '12-24h', max: 24 * 3600_000, count: 0 },
    { label: '>24h',   max: Infinity, count: 0 },
  ]
  for (const c of filtered.value) {
    const o = new Date(c.opened_at || c.created_at || 0).getTime()
    const d = new Date(c.dispositioned_at || c.closed_at || 0).getTime()
    if (!o || !d || d < o) continue
    const ms = d - o
    for (const b of buckets) { if (ms <= b.max) { b.count++; break } }
  }
  return buckets.map(b => [b.label, b.count])
})

const stateFunnel = computed(() => {
  const total  = filtered.value.length
  const opened = total
  const inProg = filtered.value.filter(c => ['IN_PROGRESS', 'DISPOSITIONED', 'CLOSED'].includes(c.case_status)).length
  const disp   = filtered.value.filter(c => ['DISPOSITIONED', 'CLOSED'].includes(c.case_status)).length
  const closed = filtered.value.filter(c => c.case_status === 'CLOSED').length
  const max = opened || 1
  return [
    { label: 'Opened',         count: opened, color: '#0B2545' },
    { label: 'In progress',    count: inProg, color: '#1D4ED8' },
    { label: 'Dispositioned',  count: disp,   color: '#7C3AED' },
    { label: 'Closed',         count: closed, color: '#166534' },
  ].map(s => ({ ...s, pct: Math.round(s.count / max * 100) }))
})

const riskBreakdown = computed(() => {
  const total = filtered.value.length || 1
  const colors = { LOW: '#86EFAC', MEDIUM: '#FCD34D', HIGH: '#FB923C', CRITICAL: '#EF4444' }
  return ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'].map(code => {
    const count = filtered.value.filter(c => c.risk_level === code).length
    return { code, label: code, count, pct: Math.round(count / total * 100), color: colors[code] }
  })
})

const dispBreakdown = computed(() => {
  const total = filtered.value.length || 1
  const labels = {
    RELEASED_NO_CONDITION: 'Released — no condition',
    RELEASED_UNDER_FOLLOWUP: 'Released — follow-up',
    REFERRED_HEALTH_FACILITY: 'Referred',
    ISOLATED_ADMITTED: 'Isolated',
    DECEASED_AT_POE: 'Deceased at POE',
  }
  return Object.entries(labels)
    .map(([code, label]) => {
      const count = filtered.value.filter(c => c.final_disposition === code).length
      return { code, label, count, pct: Math.round(count / total * 100) }
    })
    .filter(r => r.count > 0)
})

const syndromeBreakdown = computed(() => {
  const total = filtered.value.length || 1
  const counts = {}
  for (const c of filtered.value) {
    const s = c.syndrome_classification || 'NO_SYNDROME'
    counts[s] = (counts[s] || 0) + 1
  }
  return Object.entries(counts)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 8)
    .map(([code, count]) => ({ code, label: code.replace(/_/g, ' '), count, pct: Math.round(count / total * 100) }))
})

function resolveDiseaseName(code) {
  if (!code) return ''
  const D = window.DISEASES
  const hit = D?.getDiseaseById?.(code, { includeLegacy: true })
  return hit?.name || code.replace(/_/g, ' ')
}

const topDiseases = computed(() => {
  const counts = {}
  for (const d of allDiseases.value) {
    if (!caseUuids.value.has(d.secondary_screening_id)) continue
    if (d.rank_order && d.rank_order > 1) continue
    if (!d.disease_code) continue
    counts[d.disease_code] = (counts[d.disease_code] || 0) + 1
  }
  const total = Math.max(1, Object.values(counts).reduce((a, b) => a + b, 0))
  return Object.entries(counts)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 8)
    .map(([code, count]) => ({ code, label: resolveDiseaseName(code), count, pct: Math.round(count / total * 100) }))
})

const countryOrigins = computed(() => {
  const counts = {}
  for (const c of filtered.value) {
    const code = (c.journey_start_country_code || c.traveler_nationality_country_code || '').toUpperCase().slice(0, 2)
    if (!code) continue
    counts[code] = (counts[code] || 0) + 1
  }
  const total = Math.max(1, Object.values(counts).reduce((a, b) => a + b, 0))
  return Object.entries(counts)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 8)
    .map(([code, count]) => ({ code, label: code, count, pct: Math.round(count / total * 100) }))
})

const ihrStats = computed(() => {
  const alerts = allAlerts.value.filter(a =>
    caseUuids.value.has(a.secondary_screening_id) ||
    caseIds.value.has(a.secondary_screening_id)
  )
  let tier1 = 0, tier2 = 0
  for (const d of allDiseases.value) {
    if (!caseUuids.value.has(d.secondary_screening_id)) continue
    if (d.rank_order && d.rank_order > 1) continue
    if (TIER1.includes(d.disease_code)) tier1++
    else if (TIER2.includes(d.disease_code)) tier2++
  }
  const total = filtered.value.length || 1
  return {
    alerts: alerts.length,
    tier1, tier2,
    alertRate: Math.round(alerts.length / total * 100),
  }
})

const poeBreakdown = computed(() => {
  if (!isNational.value) return []
  const counts = {}
  for (const c of filtered.value) {
    const p = c.poe_code || 'Unknown'
    if (!counts[p]) counts[p] = { count: 0, high: 0 }
    counts[p].count++
    if (['HIGH', 'CRITICAL'].includes(c.risk_level)) counts[p].high++
  }
  const total = filtered.value.length || 1
  return Object.entries(counts)
    .sort((a, b) => b[1].count - a[1].count)
    .map(([poe, v]) => ({ poe, count: v.count, high: v.high, pct: Math.round(v.count / total * 100) }))
})

function resetFilters() { filter.value = { period: '30d', poe: '', case_status: '', risk_level: '' } }

// ── Charts ──
function drawCharts() { drawDaily(); drawTtd() }

function drawDaily() {
  const canvas = dailyCanvas.value
  if (!canvas) return
  const W = canvas.parentElement ? canvas.parentElement.clientWidth - 24 : 720
  const H = 200
  const high = highDpiCanvas(canvas, W, H)
  if (!high) return
  const ctx = high.ctx
  ctx.clearRect(0, 0, W, H)
  const days = []
  const today = new Date(); today.setHours(0, 0, 0, 0)
  for (let i = 13; i >= 0; i--) {
    const d = new Date(today.getTime() - i * 86400_000)
    days.push({ key: d.toISOString().slice(0, 10), short: d.getDate(), low: 0, med: 0, high: 0, crit: 0 })
  }
  for (const c of filtered.value) {
    const k = String(c.opened_at || c.created_at || '').slice(0, 10)
    const slot = days.find(d => d.key === k); if (!slot) continue
    const r = c.risk_level
    if (r === 'CRITICAL') slot.crit++
    else if (r === 'HIGH') slot.high++
    else if (r === 'MEDIUM') slot.med++
    else slot.low++
  }
  const max = Math.max(1, ...days.map(d => d.low + d.med + d.high + d.crit))
  const padL = 30, padR = 14, padT = 10, padB = 28
  const innerW = W - padL - padR
  const innerH = H - padT - padB
  const colW = innerW / days.length
  ctx.strokeStyle = '#E2E8F0'; ctx.lineWidth = 1
  ctx.beginPath(); ctx.moveTo(padL, padT); ctx.lineTo(padL, padT + innerH); ctx.lineTo(padL + innerW, padT + innerH); ctx.stroke()
  ctx.fillStyle = '#94A3B8'; ctx.font = '10px Inter, sans-serif'; ctx.fillText(String(max), 4, padT + 8)
  for (let i = 0; i < days.length; i++) {
    const x = padL + i * colW
    const d = days[i]
    let stack = 0
    const segments = [
      { v: d.low,  c: '#86EFAC' },
      { v: d.med,  c: '#FCD34D' },
      { v: d.high, c: '#FB923C' },
      { v: d.crit, c: '#EF4444' },
    ]
    for (const seg of segments) {
      const h = (seg.v / max) * innerH
      if (h <= 0) continue
      ctx.fillStyle = seg.c
      ctx.fillRect(x + 2, padT + innerH - stack - h, Math.max(3, colW - 4), h)
      stack += h
    }
    if (i % 2 === 0) {
      ctx.fillStyle = '#94A3B8'
      ctx.fillText(String(d.short), x + 4, padT + innerH + 14)
    }
    // Spike marker
    if (d.high + d.crit >= 3) {
      ctx.fillStyle = '#B91C1C'
      ctx.beginPath(); ctx.arc(x + colW / 2, padT + innerH - stack - 6, 3, 0, Math.PI * 2); ctx.fill()
    }
  }
  // Legend
  const legend = [
    { c: '#86EFAC', l: 'Low' }, { c: '#FCD34D', l: 'Med' },
    { c: '#FB923C', l: 'High' }, { c: '#EF4444', l: 'Crit' },
  ]
  let lx = padL + innerW - 200
  for (const e of legend) {
    ctx.fillStyle = e.c; ctx.fillRect(lx, 6, 8, 8)
    ctx.fillStyle = '#475569'; ctx.fillText(e.l, lx + 12, 13)
    lx += 50
  }
}

function drawTtd() {
  const canvas = ttdCanvas.value
  if (!canvas) return
  const W = canvas.parentElement ? canvas.parentElement.clientWidth - 24 : 720
  const H = 160
  const high = highDpiCanvas(canvas, W, H)
  if (!high) return
  const ctx = high.ctx
  ctx.clearRect(0, 0, W, H)
  // Buckets: <30m, 30-60m, 1-4h, 4-12h, 12-24h, >24h
  const buckets = [
    { label: '<30m',   max: 30 * 60_000, count: 0 },
    { label: '30-60m', max: 60 * 60_000, count: 0 },
    { label: '1-4h',   max: 4 * 3600_000, count: 0 },
    { label: '4-12h',  max: 12 * 3600_000, count: 0 },
    { label: '12-24h', max: 24 * 3600_000, count: 0 },
    { label: '>24h',   max: Infinity, count: 0 },
  ]
  for (const c of filtered.value) {
    const o = new Date(c.opened_at || c.created_at || 0).getTime()
    const d = new Date(c.dispositioned_at || c.closed_at || 0).getTime()
    if (!o || !d || d < o) continue
    const ms = d - o
    for (const b of buckets) { if (ms <= b.max) { b.count++; break } }
  }
  const max = Math.max(1, ...buckets.map(b => b.count))
  const padL = 30, padR = 14, padT = 14, padB = 30
  const innerW = W - padL - padR
  const innerH = H - padT - padB
  const colW = innerW / buckets.length
  ctx.strokeStyle = '#E2E8F0'; ctx.lineWidth = 1
  ctx.beginPath(); ctx.moveTo(padL, padT); ctx.lineTo(padL, padT + innerH); ctx.lineTo(padL + innerW, padT + innerH); ctx.stroke()
  ctx.fillStyle = '#94A3B8'; ctx.font = '10px Inter, sans-serif'
  for (let i = 0; i < buckets.length; i++) {
    const x = padL + i * colW
    const b = buckets[i]
    const h = (b.count / max) * innerH
    const grad = ctx.createLinearGradient(0, padT + innerH - h, 0, padT + innerH)
    grad.addColorStop(0, '#7C3AED'); grad.addColorStop(1, '#5B21B6')
    ctx.fillStyle = grad
    ctx.fillRect(x + 6, padT + innerH - h, colW - 12, h)
    if (b.count > 0) {
      ctx.fillStyle = '#0F172A'; ctx.font = 'bold 11px Inter, sans-serif'
      ctx.fillText(String(b.count), x + colW / 2 - 4, padT + innerH - h - 4)
    }
    ctx.fillStyle = '#94A3B8'; ctx.font = '10px Inter, sans-serif'
    ctx.fillText(b.label, x + 4, padT + innerH + 16)
  }
}

useAutoRefresh(() => load().catch(() => {}), 30_000)

watch(() => kpis.value, (k) => {
  kpiTotal.set(k.total)
  kpiDispositioned.set(k.dispositioned)
  kpiClosed.set(k.closed)
  kpiHigh.set(k.high)
  kpiCritical.set(k.critical)
}, { immediate: true })

watch(filtered, () => { nextTick(drawCharts) })

async function exportPdf() {
  const auth = getAuth()
  const periodLabel = ({ '24h':'Last 24 hours','7d':'Last 7 days','30d':'Last 30 days','90d':'Last 90 days','all':'All time' })[filter.value.period] || filter.value.period
  const pdf = await createPdf({
    title: 'Secondary Screening Report',
    subtitle: 'Clinical case investigation analytics - WHO IHR Annex 2',
    scope: scope.value,
    officer: { name: auth.full_name || auth.username || 'N/A', role: auth.role_key || 'N/A' },
    context: {
      who:   `${auth.full_name || auth.username || 'POE Officer'} (${auth.role_key || 'role'}) at ${scope.value.label || 'POE'}`,
      where: scope.value.level === 'NATIONAL' ? 'National view - all Uganda POEs' : `POE: ${scope.value.label}`,
      what:  `Secondary case investigation summary - ${filtered.value.length} of ${(cases?.value || allCases?.value || []).length} cases`,
      when:  `${periodLabel} - generated ${new Date().toLocaleString('en-GB', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' })}`,
    },
    filters: [
      ['Period',     periodLabel],
      ['POE filter', isNational.value ? (filter.value.poe || 'All POEs') : scope.value.label],
      ['Case status', filter.value.case_status || 'All'],
      ['Risk level', filter.value.risk_level || 'All'],
      ['Cases',      `${filtered.value.length} of ${(cases?.value || allCases?.value || []).length}`],
    ],
  })
  const k = kpis.value
  pdf.section('Key indicators')
  pdf.kpiGrid([
    { label: 'Cases opened',  value: k.total },
    { label: 'Dispositioned', value: k.dispositioned, accent: 'accent', sublabel: `${k.dispRate}% of total` },
    { label: 'Closed',        value: k.closed,        accent: 'good' },
    { label: 'High risk',     value: k.high,          accent: 'warn' },
    { label: 'Critical',      value: k.critical,      accent: 'bad' },
    { label: 'Median TTD',    value: k.medianTtd,     sublabel: 'time to disposition' },
  ])

  pdf.section('Case lifecycle funnel')
  pdf.barList(stateFunnel.value.map(s => ({ label: s.label, value: s.count, pct: s.pct })), { accent: 'accent' })

  pdf.section('Daily caseload — last 14 days')
  if (dailyCanvas.value) await pdf.embedChart(dailyCanvas.value, { caption: 'Stacked by risk level. Red dot marks days with ≥3 high-risk cases.' })

  pdf.section('Time-to-disposition distribution')
  if (ttdCanvas.value) await pdf.embedChart(ttdCanvas.value, { caption: 'Distribution of resolution latency from open to disposition.' })

  pdf.section('Risk distribution')
  pdf.barList(riskBreakdown.value.map(r => ({ label: r.label, value: r.count, pct: r.pct })))

  if (dispBreakdown.value.length) {
    pdf.section('Final disposition')
    pdf.barList(dispBreakdown.value.map(r => ({ label: r.label, value: r.count, pct: r.pct })), { accent: 'accent' })
  }
  if (syndromeBreakdown.value.length) {
    pdf.section('Syndromic classification')
    pdf.barList(syndromeBreakdown.value.map(r => ({ label: r.label, value: r.count, pct: r.pct })))
  }
  if (topDiseases.value.length) {
    pdf.section('Top suspected diseases')
    pdf.barList(topDiseases.value.map(r => ({ label: r.label, value: r.count, pct: r.pct })), { accent: 'bad' })
  }
  if (countryOrigins.value.length) {
    pdf.section('Travel-origin countries')
    pdf.barList(countryOrigins.value.map(r => ({ label: r.label, value: r.count, pct: r.pct })))
  }

  if (isNational.value && poeBreakdown.value.length > 1) {
    pdf.section('Per-POE caseload')
    pdf.table(
      poeBreakdown.value.map(r => [r.poe, r.count, r.high, r.pct + '%']),
      { headers: ['POE', 'Cases', 'High/Critical', 'Share'], columnWidths: [240, 80, 100, 80] }
    )
  }

  pdf.section('Methodology note')
  pdf.note('Cases are captured via the secondary screening case-investigation form per WHO IHR 2005 Annex 2 / IDSR Technical Guidelines. Time-to-disposition is computed from `opened_at` to `dispositioned_at` (or `closed_at` if disposition not separately recorded). Tier-1 / Tier-2 classification reflects the IHR 2005 always-notifiable list and Annex 2 conditional list. All counts are filter-scoped per the parameters block above. Alerts raised are linked to the cases visible in this report.')

  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')
  pdf.save(`secondary-screening-report-${(scope.value.label || 'POE').replace(/\W+/g, '_')}-${stamp}.pdf`)
}
</script>

<style scoped>
.ssr-content { --background: #F5F7FA; }

.ssr-filterbar { --background: #FFFFFF; border-bottom: 1px solid #E2E8F0; }
.ssr-filter-scroll {
  display: flex; gap: 8px; padding: 10px 14px;
  overflow-x: auto; -webkit-overflow-scrolling: touch; scrollbar-width: none;
}
.ssr-filter-scroll::-webkit-scrollbar { display: none; }
.ssr-pill {
  flex-shrink: 0; padding: 7px 14px; border-radius: 999px;
  border: 1px solid #CBD5E1; background: #FFFFFF;
  font: inherit; font-size: 12.5px; font-weight: 600; color: #475569;
  -webkit-appearance: none; cursor: pointer;
}
.ssr-pill--reset { background: #0B2545; color: #FFFFFF; border-color: #0B2545; }
.ssr-pill--on { background: #DBEAFE; color: #1D4ED8; border-color: #93C5FD; }
.ssr-active-chip {
  flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px;
  padding: 6px 12px; border-radius: 999px;
  background: #0B2545; color: #FFFFFF;
  font-size: 12px; font-weight: 800; letter-spacing: .3px;
}
.ssr-active-chip ion-icon { width: 12px; height: 12px; }

.ssr-sync-strip {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 16px; font-size: 11px; color: #64748B;
  background: #FFFFFF; border-bottom: 1px solid #E2E8F0;
}
.ssr-sync-strip ion-icon { width: 14px; height: 14px; }
.ssr-spin { animation: ssr-spin 1s linear infinite; }
@keyframes ssr-spin { from { transform: rotate(0); } to { transform: rotate(360deg); } }
.ssr-scope-tag {
  margin-left: auto; padding: 3px 8px; border-radius: 999px;
  background: #DBEAFE; color: #1D4ED8;
  font-weight: 700; font-size: 9.5px; letter-spacing: .4px;
}

.ssr-empty {
  padding: 60px 16px; text-align: center; color: #64748B;
  display: flex; flex-direction: column; align-items: center; gap: 12px;
}
.ssr-empty ion-icon { width: 36px; height: 36px; color: #CBD5E1; }

.ssr-kpis {
  display: grid; gap: 10px; padding: 14px 14px;
  grid-template-columns: repeat(2, 1fr);
}
@media (min-width: 600px) { .ssr-kpis { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 900px) { .ssr-kpis { grid-template-columns: repeat(6, 1fr); } }
.ssr-kpi {
  --accent: #0B2545;
  position: relative; background: #FFFFFF;
  border: 1px solid #E2E8F0; border-radius: 14px;
  padding: 14px 14px 14px 18px; min-height: 88px;
  box-shadow: 0 1px 2px rgba(15,23,42,.04);
  overflow: hidden;
}
.ssr-kpi::before {
  content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
  background: var(--accent);
}
.ssr-kpi-num { font-size: 26px; font-weight: 800; color: var(--accent); line-height: 1.1; margin-bottom: 4px; font-feature-settings: 'tnum'; }
.ssr-kpi-lbl { font-size: 11.5px; font-weight: 600; color: #64748B; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.ssr-kpi-aux { font-size: 10px; font-weight: 700; color: var(--accent); background: rgba(15,23,42,.05); padding: 1px 6px; border-radius: 999px; }

.ssr-card { background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 14px; padding: 16px; margin: 10px 14px; box-shadow: 0 1px 2px rgba(15,23,42,.03); }
.ssr-card-titlebar { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:6px; }
.ssr-card-titlebar > div:first-child { flex:1; min-width:0; }
.ssr-card-title { margin: 0 0 4px; font-size: 14px; font-weight: 700; color: #0F172A; letter-spacing: -.1px; }
.ssr-card-sub { margin: 0 0 14px; font-size: 11.5px; color: #64748B; line-height: 1.4; }
.ssr-grid-2 { display: grid; grid-template-columns: 1fr; gap: 0; }
@media (min-width: 720px) { .ssr-grid-2 { grid-template-columns: 1fr 1fr; } }

.ssr-canvas { width: 100%; height: auto; max-height: 220px; display: block; border-radius: 6px; }

.ssr-funnel { display: flex; flex-direction: column; gap: 8px; }
.ssr-funnel-row { display: grid; grid-template-columns: 1fr; gap: 4px; }
.ssr-funnel-bar {
  height: 32px; border-radius: 6px; min-width: 30%;
  display: flex; align-items: center; justify-content: flex-end;
  padding: 0 12px; color: #FFFFFF; font-weight: 700; transition: width .4s ease;
}
.ssr-funnel-num { font-size: 14px; }
.ssr-funnel-meta { display: flex; justify-content: space-between; font-size: 11.5px; padding: 0 4px; }
.ssr-funnel-lbl { color: #0F172A; font-weight: 600; }
.ssr-funnel-pct { color: #64748B; }

.ssr-row { display: grid; grid-template-columns: 130px 1fr 100px; gap: 10px; align-items: center; padding: 6px 0; }
.ssr-row-lbl { font-size: 12px; color: #334155; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ssr-row-bar { background: #F1F5F9; border-radius: 4px; height: 8px; overflow: hidden; }
.ssr-row-bar-fill { background: #1D4ED8; height: 100%; transition: width .4s ease; }
.ssr-row-val { text-align: right; font-size: 12px; color: #475569; font-weight: 700; font-feature-settings: 'tnum'; }
.ssr-row-val em { color: #94A3B8; font-style: normal; font-weight: 500; }
.ssr-mini-empty { color: #94A3B8; font-size: 12px; padding: 12px 0; text-align: center; }

.ssr-ihr-row { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
@media (min-width: 600px) { .ssr-ihr-row { grid-template-columns: repeat(4, 1fr); } }
.ssr-ihr-tile {
  background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px;
  padding: 14px; text-align: center;
}
.ssr-ihr-num { font-size: 22px; font-weight: 800; color: #0B2545; font-feature-settings: 'tnum'; }
.ssr-ihr-lbl { font-size: 10.5px; font-weight: 600; color: #64748B; margin-top: 4px; }

/* Staggered card entrance */
.ssr-rise {
  animation: ssr-rise 480ms cubic-bezier(.16, 1, .3, 1) backwards;
  animation-delay: calc(var(--i, 0) * 35ms);
}
@keyframes ssr-rise {
  from { opacity: 0; transform: translate3d(0, 8px, 0); }
  to   { opacity: 1; transform: translate3d(0, 0, 0); }
}
@media (prefers-reduced-motion: reduce) { .ssr-rise { animation: none; } }

/* Skeleton matched to final layout — zero CLS */
.ssr-skel { pointer-events: none; user-select: none; }
.ssr-skel .ssr-skel-bar,
.ssr-skel-block {
  background: linear-gradient(90deg, #EEF2F7 0%, #F8FAFC 50%, #EEF2F7 100%);
  background-size: 200% 100%;
  border-radius: 6px;
  animation: ssr-shimmer 1.4s linear infinite;
}
.ssr-skel-bar { height: 12px; margin-bottom: 8px; width: 60%; }
.ssr-skel-bar--big   { height: 26px; width: 40%; }
.ssr-skel-bar--title { height: 16px; width: 50%; margin-bottom: 16px; }
.ssr-skel-block { height: 100px; margin-top: 6px; }
.ssr-skel-block--tall { height: 160px; }
@keyframes ssr-shimmer {
  from { background-position: 200% 0; } to { background-position: -200% 0; }
}
</style>
