<template>
  <ion-page>
    <ion-header :translucent="false">
      <ion-toolbar color="primary">
        <ion-buttons slot="start"><ion-menu-button /></ion-buttons>
        <ion-title>{{ t('Primary Screening Report') }}</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="refresh" :disabled="loading">
            <ion-icon :icon="refreshOutline" slot="icon-only"/>
          </ion-button>
          <ion-button @click="exportPdf" :disabled="loading || !filtered.length">
            <ion-icon :icon="documentTextOutline" slot="start"/>PDF
          </ion-button>
        </ion-buttons>
      </ion-toolbar>
      <!-- Sticky filter bar with active-filter pill -->
      <ion-toolbar class="psr-filterbar">
        <div class="psr-filter-scroll">
          <span v-if="activeFilters > 0" class="psr-active-chip" :title="`${activeFilters} active filter${activeFilters === 1 ? '' : 's'}`">
            <ion-icon :icon="funnelOutline"/>{{ activeFilters }}
          </span>
          <select v-model="filter.period" class="psr-pill" :class="{ 'psr-pill--on': filter.period !== FILTER_DEFAULTS.period }">
            <option value="today">{{ t('Today') }}</option>
            <option value="7d">{{ t('Last 7 days') }}</option>
            <option value="30d">{{ t('Last 30 days') }}</option>
            <option value="90d">{{ t('Last 90 days') }}</option>
            <option value="all">{{ t('All time') }}</option>
          </select>
          <select v-if="isNational" v-model="filter.poe" class="psr-pill" :class="{ 'psr-pill--on': filter.poe }">
            <option value="">All POEs</option>
            <option v-for="p in poeList" :key="p" :value="p">{{ p }}</option>
          </select>
          <select v-model="filter.direction" class="psr-pill" :class="{ 'psr-pill--on': filter.direction }">
            <option value="">{{ t('Direction') }}: {{ t('All') }}</option>
            <option value="ENTRY">{{ t('Arrival') }}</option>
            <option value="EXIT">{{ t('Departure') }}</option>
            <option value="TRANSIT">{{ t('Transit') }}</option>
          </select>
          <select v-model="filter.symptoms" class="psr-pill" :class="{ 'psr-pill--on': filter.symptoms !== '' }">
            <option value="">{{ t('Symptoms') }}: {{ t('All') }}</option>
            <option value="1">{{ t('Symptomatic') }}</option>
            <option value="0">{{ t('Asymptomatic') }}</option>
          </select>
          <select v-model="filter.gender" class="psr-pill" :class="{ 'psr-pill--on': filter.gender }">
            <option value="">{{ t('Gender') }}: {{ t('All') }}</option>
            <option value="MALE">{{ t('Male') }}</option>
            <option value="FEMALE">{{ t('Female') }}</option>
          </select>
          <button v-if="activeFilters > 0" class="psr-pill psr-pill--reset" @click="resetFilters" type="button">{{ t('Reset') }}</button>
        </div>
      </ion-toolbar>
    </ion-header>

    <ion-content class="psr-content">
      <ion-refresher slot="fixed" @ionRefresh="onPullRefresh"><ion-refresher-content/></ion-refresher>

      <!-- Auto-refresh ribbon -->
      <div v-if="lastSyncedLabel" class="psr-sync-strip">
        <ion-icon :icon="syncOutline" :class="{ 'psr-spin': loading }"/>
        <span>{{ t('Updated') }} {{ lastSyncedLabel }}</span>
        <span v-if="isNational" class="psr-scope-tag">NATIONAL · {{ filter.poe || 'All POEs' }}</span>
        <span v-else class="psr-scope-tag">POE · {{ scope.label }}</span>
      </div>

      <!-- Skeleton (first load only — zero layout shift to real content) -->
      <template v-if="!firstLoadDone">
        <div class="psr-kpis">
          <div v-for="i in 6" :key="i" class="psr-kpi psr-skel"><div class="psr-skel-bar psr-skel-bar--big"/><div class="psr-skel-bar"/></div>
        </div>
        <div class="psr-card psr-skel"><div class="psr-skel-bar psr-skel-bar--title"/><div class="psr-skel-block"/></div>
        <div class="psr-card psr-skel"><div class="psr-skel-bar psr-skel-bar--title"/><div class="psr-skel-block psr-skel-block--tall"/></div>
      </template>

      <div v-else-if="!filtered.length" class="psr-empty">
        <ion-icon :icon="archiveOutline"/>
        <span>No screenings match the current filters.</span>
      </div>

      <template v-else>
        <!-- KPI hero grid — count-up animated, staggered reveal -->
        <div class="psr-kpis">
          <div class="psr-kpi psr-rise" style="--accent: #0B2545; --i: 0">
            <div class="psr-kpi-num">{{ fmtN(kpiTotal.value) }}</div>
            <div class="psr-kpi-lbl">{{ t('Travellers screened') }}</div>
          </div>
          <div class="psr-kpi psr-rise" style="--accent: #B45309; --i: 1">
            <div class="psr-kpi-num">{{ fmtN(kpiSymptomatic.value) }}</div>
            <div class="psr-kpi-lbl">{{ t('Symptomatic') }} <span class="psr-kpi-aux">{{ kpis.sympRate }}%</span></div>
          </div>
          <div class="psr-kpi psr-rise" style="--accent: #166534; --i: 2">
            <div class="psr-kpi-num">{{ fmtN(kpiAsymptomatic.value) }}</div>
            <div class="psr-kpi-lbl">{{ t('Asymptomatic') }}</div>
          </div>
          <div class="psr-kpi psr-rise" style="--accent: #B91C1C; --i: 3">
            <div class="psr-kpi-num">{{ fmtN(kpiFever.value) }}</div>
            <div class="psr-kpi-lbl">{{ t('Fever') }} ≥ 38.5 °C</div>
          </div>
          <div class="psr-kpi psr-rise" style="--accent: #1D4ED8; --i: 4">
            <div class="psr-kpi-num">{{ fmtN(kpiReferred.value) }}</div>
            <div class="psr-kpi-lbl">Auto-referred</div>
          </div>
          <div class="psr-kpi psr-rise" style="--accent: #7C3AED; --i: 5">
            <div class="psr-kpi-num">{{ kpis.peakHour }}</div>
            <div class="psr-kpi-lbl">Peak hour <span class="psr-kpi-aux">{{ kpis.peakHourCount }} screenings</span></div>
          </div>
        </div>

        <!-- Conversion funnel — public health context -->
        <div class="psr-card psr-rise" style="--i: 6">
          <div class="psr-card-titlebar">
            <div>
              <h3 class="psr-card-title">Surveillance funnel</h3>
              <p class="psr-card-sub">From screening at the gate → secondary referral pipeline.</p>
            </div>
            <ExplainModal
              title="Surveillance funnel"
              what="Each row shows how many travellers reached that stage of the screening pipeline. The width of the bar is its share of the total screened."
              how="Read top→bottom. A wide bar at 'Total screened' that narrows sharply at 'Symptomatic' is normal. A narrow 'Referred' bar versus a wide 'Symptomatic' bar means referrals are not being filed."
              action="If 'Referred to secondary' is much smaller than 'Symptomatic', audit the auto-referral rule on the symptomatic capture path."
              :columns="['Stage', 'Count', '% of total']"
              :rows="funnel.map(s => [s.label, s.count, s.pct + '%'])"
              source="Filtered primary_screenings (captured_at within selected period)."
            />
          </div>
          <div class="psr-funnel">
            <div v-for="(s, i) in funnel" :key="s.label" class="psr-funnel-row">
              <div class="psr-funnel-bar"
                   :style="{ width: (s.pct || 1) + '%', background: s.color }">
                <span class="psr-funnel-num">{{ s.count }}</span>
              </div>
              <div class="psr-funnel-meta">
                <span class="psr-funnel-lbl">{{ s.label }}</span>
                <span class="psr-funnel-pct">{{ s.pct }}% · drop-off {{ i > 0 ? funnel[i-1].count - s.count : 0 }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Throughput by hour-of-day (when are screeners busy?) -->
        <div class="psr-card psr-rise" style="--i: 6">
          <div class="psr-card-titlebar">
            <div>
              <h3 class="psr-card-title">Throughput by hour of day</h3>
              <p class="psr-card-sub">Identifies operational pressure windows.</p>
            </div>
            <ExplainModal
              title="Throughput by hour of day"
              what="Bars: total screenings per hour. Red overlay: symptomatic share within that hour."
              how="Find the hours with the tallest total bars — those are your busiest. Hours where the red overlay is a large fraction of the bar are clinically high-risk windows."
              action="Schedule extra screeners for the busiest peak hours; brief the secondary lane in advance for hours showing high symptomatic share."
              :columns="['Hour', 'Total screenings', 'Symptomatic']"
              :rows="hourlyData"
              source="Filtered primary_screenings, hour from captured_at (device-local time)."
            />
          </div>
          <canvas ref="hourCanvas" class="psr-canvas"/>
        </div>

        <!-- Daily trend (14d) — fever overlay -->
        <div class="psr-card psr-rise" style="--i: 6">
          <div class="psr-card-titlebar">
            <div>
              <h3 class="psr-card-title">Daily volume — last 14 days</h3>
              <p class="psr-card-sub">Bars: total screenings. Red overlay: febrile travellers.</p>
            </div>
            <ExplainModal
              title="Daily volume — last 14 days"
              what="Per-day count of screenings. Red sub-bar shows how many of that day's travellers were febrile (≥ 38.5 °C)."
              how="A rising total trend means traffic is up. A rising febrile trend on roughly steady total traffic is an outbreak signal — investigate."
              action="If febrile cases double for two consecutive days, escalate to district / PHEOC even before formal alerts cross the threshold."
              :columns="['Date', 'Total', 'Febrile (≥ 38.5°C)']"
              :rows="dailyData"
              source="Filtered primary_screenings; date from captured_at."
            />
          </div>
          <canvas ref="dailyCanvas" class="psr-canvas"/>
        </div>

        <!-- Symptom prevalence (only field actually captured at primary screening) -->
        <div class="psr-card psr-rise" style="--i: 6">
          <div class="psr-card-titlebar">
            <div>
              <h3 class="psr-card-title">Symptom prevalence</h3>
              <p class="psr-card-sub">Across symptomatic travellers only.</p>
            </div>
            <ExplainModal
              title="Symptom prevalence"
              what="Each row is one of the broad symptom categories captured at primary screening, ranked by how many symptomatic travellers reported it."
              how="The top 1-3 rows are the dominant clinical pattern at this POE for the period. A surge in 'Respiratory' usually precedes ILI/SARI clusters; a surge in 'Gastrointestinal' precedes cholera/AWD outbreaks."
              action="If a single category jumps to >40 % of symptomatic travellers, alert the secondary lane to expect that cluster."
              :columns="['Category', 'Count', '% of symptomatic']"
              :rows="topSymptoms.map(r => [r.label, r.count, r.pct + '%'])"
              source="Filtered primary_screenings.quick_symptoms_json — only symptomatic captures contribute."
            />
          </div>
          <div v-if="!topSymptoms.length" class="psr-mini-empty">No symptoms recorded.</div>
          <div v-for="r in topSymptoms" :key="r.code" class="psr-row">
            <span class="psr-row-lbl">{{ r.label }}</span>
            <div class="psr-row-bar"><div class="psr-row-bar-fill" :style="{ width: r.pct + '%', background: '#B45309' }"/></div>
            <span class="psr-row-val">{{ r.count }}</span>
          </div>
        </div>

        <!-- Gender split (binary, captured at primary) -->
        <div class="psr-card psr-rise" style="--i: 6">
          <div class="psr-card-titlebar">
            <div>
              <h3 class="psr-card-title">Gender distribution</h3>
              <p class="psr-card-sub">Travellers screened by reported gender.</p>
            </div>
            <ExplainModal
              title="Gender distribution"
              what="Count of male vs female travellers screened in the filtered period."
              how="A balanced split is expected at most border crossings. Pronounced skew may reflect specific traveller cohorts (e.g. labour migrants)."
              action="No clinical action by itself — use this for resource planning (private screening rooms, female screener availability)."
              :columns="['Gender', 'Count']"
              :rows="[['Male', kpis.maleCount], ['Female', kpis.femaleCount]]"
              source="Filtered primary_screenings.gender."
            /></div>
          <div class="psr-row">
            <span class="psr-row-lbl">Male</span>
            <div class="psr-row-bar">
              <div class="psr-row-bar-fill"
                :style="{ width: ((kpis.maleCount / Math.max(1, kpis.maleCount + kpis.femaleCount)) * 100) + '%', background: '#1D4ED8' }"/>
            </div>
            <span class="psr-row-val">{{ kpis.maleCount }}</span>
          </div>
          <div class="psr-row">
            <span class="psr-row-lbl">Female</span>
            <div class="psr-row-bar">
              <div class="psr-row-bar-fill"
                :style="{ width: ((kpis.femaleCount / Math.max(1, kpis.maleCount + kpis.femaleCount)) * 100) + '%', background: '#DB2777' }"/>
            </div>
            <span class="psr-row-val">{{ kpis.femaleCount }}</span>
          </div>
        </div>

        <!-- POE breakdown (NATIONAL only) -->
        <div v-if="isNational && poeBreakdown.length > 1" class="psr-card">
          <h3 class="psr-card-title">Per-POE volume</h3>
          <p class="psr-card-sub">National-scope breakdown across {{ poeBreakdown.length }} POEs.</p>
          <div v-for="r in poeBreakdown" :key="r.poe" class="psr-row">
            <span class="psr-row-lbl">{{ r.poe }}</span>
            <div class="psr-row-bar"><div class="psr-row-bar-fill" :style="{ width: r.pct + '%', background: '#0EA5E9' }"/></div>
            <span class="psr-row-val">{{ r.count }} · {{ r.symp }} sym</span>
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
const FILTER_DEFAULTS = { period: '30d', poe: '', direction: '', symptoms: '', gender: '' }

const records = ref([])
const loading  = ref(true)
const firstLoadDone = ref(false)
const lastSyncedAt = ref(null)
const hourCanvas = ref(null)
const dailyCanvas = ref(null)
const filter = ref({ ...FILTER_DEFAULTS })

// Smooth-counting KPI values — animate from prior value on filter change.
const kpiTotal       = smoothNumber(0)
const kpiSymptomatic = smoothNumber(0)
const kpiAsymptomatic = smoothNumber(0)
const kpiFever       = smoothNumber(0)
const kpiReferred    = smoothNumber(0)

function getAuth() { try { return JSON.parse(sessionStorage.getItem('AUTH_DATA') || 'null') || {} } catch { return {} } }
const isNational = computed(() => {
  const r = String(getAuth()?.role_key || '').toUpperCase()
  return r === 'NATIONAL_ADMIN'
})
const scope = computed(() => {
  const auth = getAuth()
  if (isNational.value) {
    return { level: 'NATIONAL', label: filter.value.poe || 'All POEs' }
  }
  return { level: 'POE', label: auth?.poe_code || 'N/A' }
})

async function load() {
  loading.value = true
  // Hard 12-second timeout safety net so the skeleton can NEVER stick
  // forever, regardless of which sub-step hangs. After 12s we render
  // whatever IDB had (which is fast-loaded first), even if the server
  // pull is still in flight in the background.
  const escape = setTimeout(() => {
    if (!firstLoadDone.value) {
      loading.value = false
      firstLoadDone.value = true
      try { drawCharts() } catch {}
      console.warn('[PSR] load timed out at 12s — rendering IDB data only')
    }
  }, 12_000)
  try {
    const auth = getAuth()
    const isNat = String(auth?.role_key || '').toUpperCase() === 'NATIONAL_ADMIN'

    // 1. IDB-first read (instant; offline-safe)
    let all = []
    if (isNat) {
      all = await dbGetAll(STORE.PRIMARY_SCREENINGS).catch(() => []) || []
    } else if (auth.poe_code) {
      try { all = await dbGetByIndex(STORE.PRIMARY_SCREENINGS, 'poe_code', auth.poe_code) || [] }
      catch { all = await dbGetAll(STORE.PRIMARY_SCREENINGS).catch(() => []) || [] }
    } else {
      all = await dbGetAll(STORE.PRIMARY_SCREENINGS).catch(() => []) || []
    }
    // Render IDB result IMMEDIATELY so the user sees data within ~50ms,
    // even if the server fetch below takes a while. The post-server merge
    // updates `records.value` again with the fuller set.
    all.sort((a, b) => new Date(b.captured_at || 0) - new Date(a.captured_at || 0))
    records.value = all
    if (all.length > 0) {
      firstLoadDone.value = true
      try { await nextTick(); drawCharts() } catch {}
    }

    // 2. Server fetch — runs in parallel to keep IDB warm. On a fresh
    //    install this is the only source of records; on a returning
    //    device it back-fills anything we don't have yet.
    if (typeof navigator !== 'undefined' && navigator.onLine && window.SERVER_URL && auth?.id) {
      try {
        const merged = await fetchPrimaryFromServer(auth, isNat, all)
        if (merged && merged.length > all.length) {
          merged.sort((a, b) => new Date(b.captured_at || 0) - new Date(a.captured_at || 0))
          all = merged
          records.value = all
        }
      } catch (e) {
        console.debug('[PSR] server fetch skipped:', e?.message)
      }
    }

    lastSyncedAt.value = new Date()
  } catch (e) {
    console.warn('[PSR] load error', e?.message)
  } finally {
    clearTimeout(escape)
    loading.value = false
    firstLoadDone.value = true
    try { await nextTick(); drawCharts() } catch {}
  }
}

async function fetchPrimaryFromServer(auth, isNat, idbList) {
  const allFromServer = []
  const cutoff = periodCutoff(filter.value.period) || (Date.now() - 90 * 86400_000)
  const since  = new Date(cutoff).toISOString().slice(0, 10)
  for (let pg = 1; pg <= 6; pg++) {
    const p = new URLSearchParams({
      user_id: auth.id, page: pg, per_page: 200,
      sort_by: 'captured_at', sort_dir: 'desc', date_from: since,
    })
    const ctrl = new AbortController()
    const tid = setTimeout(() => ctrl.abort(), 8000)
    let body = null
    try {
      const res = await fetch(`${window.SERVER_URL}/primary-records?${p}`, {
        headers: { Accept: 'application/json' }, signal: ctrl.signal,
      })
      if (!res.ok) break
      body = await res.json()
    } catch { break } finally { clearTimeout(tid) }
    const items = body?.data?.items || body?.data || []
    if (!items.length) break
    for (const s of items) {
      if (!s.client_uuid) continue
      // Normalise to IDB shape — captured_at, symptoms_present (0/1), etc.
      allFromServer.push({
        ...s,
        symptoms_present: s.symptoms_present ? 1 : 0,
        referral_created: s.referral_created ? 1 : 0,
      })
      // Write-through cache (best-effort; never block the report on this)
      try {
        const exists = idbList.find(r => r.client_uuid === s.client_uuid)
        if (!exists) {
          await dbPut(STORE.PRIMARY_SCREENINGS, {
            ...s,
            symptoms_present: s.symptoms_present ? 1 : 0,
            referral_created: s.referral_created ? 1 : 0,
            sync_status: 'SYNCED',
          })
        }
      } catch {}
    }
    if (items.length < 200) break
  }
  if (!allFromServer.length) return idbList
  // Merge server + IDB by client_uuid; server wins on conflicts.
  const map = new Map()
  for (const r of idbList) if (r?.client_uuid) map.set(r.client_uuid, r)
  for (const r of allFromServer) if (r?.client_uuid) map.set(r.client_uuid, r)
  return Array.from(map.values())
}

function refresh() { load() }
async function onPullRefresh(ev) { await load(); ev.target.complete() }
const activeFilters = computed(() => activeFilterCount(filter.value, FILTER_DEFAULTS))

const lastSyncedLabel = computed(() => {
  if (!lastSyncedAt.value) return null
  const ago = Math.round((Date.now() - lastSyncedAt.value.getTime()) / 1000)
  if (ago < 60) return `${ago}s ago`
  if (ago < 3600) return `${Math.round(ago / 60)}m ago`
  return lastSyncedAt.value.toLocaleTimeString('en-GB')
})

const poeList = computed(() => {
  const set = new Set()
  for (const r of records.value) if (r.poe_code) set.add(r.poe_code)
  return Array.from(set).sort()
})

// Server returns booleans for symptoms_present / referral_created.
// IDB and local-capture flow store integers (0/1). Coerce both robustly so
// every kpi/filter sees the same shape regardless of source.
const symPresent = (r) => {
  const v = r?.symptoms_present
  if (v === true || v === 1 || v === '1') return 1
  if (v === false || v === 0 || v === '0') return 0
  return Number(v ?? -1)
}

const filtered = computed(() => {
  const cutoff = periodCutoff(filter.value.period)
  const f = filter.value
  return records.value.filter(r => {
    if (cutoff && r.captured_at && new Date(r.captured_at).getTime() < cutoff) return false
    if (f.poe && (r.poe_code || '') !== f.poe) return false
    if (f.direction && (r.traveler_direction || '') !== f.direction) return false
    if (f.symptoms !== '' && symPresent(r) !== Number(f.symptoms)) return false
    if (f.gender && (r.gender || '') !== f.gender) return false
    return true
  })
})

const kpis = computed(() => {
  const list = filtered.value
  const total = list.length
  const symp  = list.filter(r => symPresent(r) === 1).length
  const asymp = list.filter(r => symPresent(r) === 0).length
  const fever = list.filter(r => Number(r.temperature_value || 0) >= 38.5).length
  const referred = symp
  const male   = list.filter(r => (r.gender || '').toUpperCase() === 'MALE').length
  const female = list.filter(r => (r.gender || '').toUpperCase() === 'FEMALE').length
  // Peak hour
  const hours = new Array(24).fill(0)
  for (const r of list) {
    const h = r.captured_at ? new Date(r.captured_at).getHours() : null
    if (h != null) hours[h]++
  }
  const peakHourCount = Math.max(...hours, 0)
  const peakHourIdx = hours.indexOf(peakHourCount)
  const peakHour = peakHourCount > 0 ? `${String(peakHourIdx).padStart(2, '0')}:00` : '—'
  return {
    total, symptomatic: symp, asymptomatic: asymp, fever, referred,
    sympRate: total ? Math.round(symp / total * 100) : 0,
    maleCount: male, femaleCount: female, peakHour, peakHourCount,
  }
})

const funnel = computed(() => {
  const total = filtered.value.length
  const symp  = filtered.value.filter(r => symPresent(r) === 1).length
  const fever = filtered.value.filter(r => Number(r.temperature_value || 0) >= 38.5).length
  const referred = symp // Every symptomatic screening creates a referral notification
  const max = total || 1
  return [
    { label: 'Total screened',         count: total,    color: '#0B2545' },
    { label: 'Symptomatic',            count: symp,     color: '#1D4ED8' },
    { label: 'Febrile (≥ 38.5°C)',     count: fever,    color: '#B45309' },
    { label: 'Referred to secondary',  count: referred, color: '#B91C1C' },
  ].map(s => ({ ...s, pct: Math.round(s.count / max * 100) }))
})

const nationalityTop = computed(() => {
  const counts = {}
  for (const r of filtered.value) {
    const c = (r.traveler_nationality_country_code || '').toUpperCase().slice(0, 2)
    if (!c || c === '') continue
    counts[c] = (counts[c] || 0) + 1
  }
  const total = filtered.value.length || 1
  return Object.entries(counts)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 8)
    .map(([code, count]) => ({ code, label: code, count, pct: Math.round(count / total * 100) }))
})

const topSymptoms = computed(() => {
  const counts = {}
  for (const r of filtered.value) {
    if (r.symptoms !== 1) continue
    let chips = []
    try {
      const raw = r.quick_symptoms_json
      if (raw) chips = typeof raw === 'string' ? JSON.parse(raw) : raw
    } catch {}
    for (const s of (chips || [])) counts[s] = (counts[s] || 0) + 1
  }
  const total = filtered.value.filter(r => symPresent(r) === 1).length || 1
  return Object.entries(counts)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 8)
    .map(([code, count]) => ({ code, label: code.replace(/_/g, ' '), count, pct: Math.round(count / total * 100) }))
})

const agePyramid = computed(() => {
  const buckets = [
    { label: '< 5',   min: 0,  max: 5 },
    { label: '5-17',  min: 5,  max: 18 },
    { label: '18-29', min: 18, max: 30 },
    { label: '30-44', min: 30, max: 45 },
    { label: '45-59', min: 45, max: 60 },
    { label: '60-74', min: 60, max: 75 },
    { label: '75+',   min: 75, max: 999 },
  ]
  const out = buckets.map(b => ({ label: b.label, male: 0, female: 0 }))
  for (const r of filtered.value) {
    const age = Number(r.traveler_age_years || 0)
    if (!age) continue
    const idx = buckets.findIndex(b => age >= b.min && age < b.max)
    if (idx === -1) continue
    if ((r.gender || '').toUpperCase() === 'MALE') out[idx].male++
    else if ((r.gender || '').toUpperCase() === 'FEMALE') out[idx].female++
  }
  const maxRow = Math.max(1, ...out.map(b => Math.max(b.male, b.female)))
  return out.map(b => ({
    ...b,
    malePct:   Math.round(b.male   / maxRow * 100),
    femalePct: Math.round(b.female / maxRow * 100),
  }))
})

const poeBreakdown = computed(() => {
  if (!isNational.value) return []
  const counts = {}
  for (const r of filtered.value) {
    const p = r.poe_code || 'Unknown'
    if (!counts[p]) counts[p] = { count: 0, symp: 0 }
    counts[p].count++
    if (symPresent(r) === 1) counts[p].symp++
  }
  const total = filtered.value.length || 1
  return Object.entries(counts)
    .sort((a, b) => b[1].count - a[1].count)
    .map(([poe, v]) => ({ poe, count: v.count, symp: v.symp, pct: Math.round(v.count / total * 100) }))
})

// Explain-table feeders — same logic as the canvas charts so the modal
// shows exactly the data the chart was drawn from.
const hourlyData = computed(() => {
  const total = new Array(24).fill(0)
  const symp  = new Array(24).fill(0)
  for (const r of filtered.value) {
    if (!r.captured_at) continue
    const h = new Date(r.captured_at).getHours()
    total[h]++
    if (symPresent(r) === 1) symp[h]++
  }
  return total
    .map((t, h) => [String(h).padStart(2, '0') + ':00', t, symp[h]])
    .filter(([, t]) => t > 0)
})

const dailyData = computed(() => {
  const days = {}
  const now  = Date.now()
  for (let i = 13; i >= 0; i--) {
    const d = new Date(now - i * 86400_000).toISOString().slice(0, 10)
    days[d] = { total: 0, fever: 0 }
  }
  for (const r of filtered.value) {
    if (!r.captured_at) continue
    const d = String(r.captured_at).slice(0, 10)
    if (!days[d]) continue
    days[d].total++
    if (Number(r.temperature_value || 0) >= 38.5) days[d].fever++
  }
  return Object.entries(days).map(([d, v]) => [d, v.total, v.fever])
})

function resetFilters() { filter.value = { period: '30d', poe: '', direction: '', symptoms: '', gender: '' } }

// ── Canvas chart drawing — lightweight, no external dep ──
function drawCharts() {
  drawHourly()
  drawDaily()
}

function drawHourly() {
  const canvas = hourCanvas.value
  if (!canvas) return
  const W = canvas.parentElement ? canvas.parentElement.clientWidth - 24 : 720
  const H = 180
  const high = highDpiCanvas(canvas, W, H)
  if (!high) return
  const ctx = high.ctx
  ctx.clearRect(0, 0, W, H)
  const hours = new Array(24).fill(0)
  const sympH = new Array(24).fill(0)
  for (const r of filtered.value) {
    if (!r.captured_at) continue
    const h = new Date(r.captured_at).getHours()
    hours[h]++
    if (symPresent(r) === 1) sympH[h]++
  }
  const max = Math.max(1, ...hours)
  const padL = 30, padR = 14, padT = 10, padB = 28
  const innerW = W - padL - padR
  const innerH = H - padT - padB
  const barW = innerW / 24
  // Axis
  ctx.strokeStyle = '#E2E8F0'; ctx.lineWidth = 1
  ctx.beginPath(); ctx.moveTo(padL, padT); ctx.lineTo(padL, padT + innerH); ctx.lineTo(padL + innerW, padT + innerH); ctx.stroke()
  ctx.fillStyle = '#94A3B8'; ctx.font = '10px Inter, sans-serif'
  ctx.fillText(String(max), 4, padT + 8)
  ctx.fillText('0', 18, padT + innerH - 2)
  for (let h = 0; h < 24; h++) {
    const x = padL + h * barW
    const total = hours[h]
    const symp  = sympH[h]
    const totalH = (total / max) * innerH
    const sympBarH = (symp / max) * innerH
    // Background bar
    ctx.fillStyle = '#DBEAFE'
    ctx.fillRect(x + 1, padT + innerH - totalH, Math.max(2, barW - 2), totalH)
    // Symptomatic overlay
    ctx.fillStyle = '#B45309'
    if (symp > 0) ctx.fillRect(x + 1, padT + innerH - sympBarH, Math.max(2, barW - 2), sympBarH)
    // Hour label every 3 hours
    if (h % 3 === 0) {
      ctx.fillStyle = '#94A3B8'
      ctx.fillText(String(h).padStart(2, '0'), x + 2, padT + innerH + 14)
    }
  }
  // Legend
  ctx.fillStyle = '#1D4ED8'; ctx.fillRect(padL + innerW - 130, 6, 8, 8)
  ctx.fillStyle = '#475569'; ctx.fillText('All', padL + innerW - 118, 13)
  ctx.fillStyle = '#B45309'; ctx.fillRect(padL + innerW - 80, 6, 8, 8)
  ctx.fillStyle = '#475569'; ctx.fillText('Symptomatic', padL + innerW - 68, 13)
}

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
    days.push({ key: d.toISOString().slice(0, 10), short: d.getDate(), count: 0, fever: 0 })
  }
  for (const r of filtered.value) {
    const k = String(r.captured_at || '').slice(0, 10)
    const slot = days.find(d => d.key === k); if (!slot) continue
    slot.count++
    if (Number(r.temperature_value || 0) >= 38.5) slot.fever++
  }
  const max = Math.max(1, ...days.map(d => d.count))
  const padL = 30, padR = 14, padT = 10, padB = 28
  const innerW = W - padL - padR
  const innerH = H - padT - padB
  const colW = innerW / days.length
  ctx.strokeStyle = '#E2E8F0'; ctx.lineWidth = 1
  ctx.beginPath(); ctx.moveTo(padL, padT); ctx.lineTo(padL, padT + innerH); ctx.lineTo(padL + innerW, padT + innerH); ctx.stroke()
  ctx.fillStyle = '#94A3B8'; ctx.font = '10px Inter, sans-serif'
  ctx.fillText(String(max), 4, padT + 8)
  for (let i = 0; i < days.length; i++) {
    const x = padL + i * colW
    const d = days[i]
    const h = (d.count / max) * innerH
    const fh = (d.fever / max) * innerH
    // Gradient bar
    const grad = ctx.createLinearGradient(0, padT + innerH - h, 0, padT + innerH)
    grad.addColorStop(0, '#3B82F6'); grad.addColorStop(1, '#1D4ED8')
    ctx.fillStyle = grad
    ctx.fillRect(x + 2, padT + innerH - h, Math.max(3, colW - 4), h)
    if (fh > 0) {
      ctx.fillStyle = '#B91C1C'
      ctx.fillRect(x + 2, padT + innerH - fh, Math.max(3, colW - 4), fh)
    }
    // Date
    ctx.fillStyle = '#94A3B8'
    if (i % 2 === 0) ctx.fillText(String(d.short), x + 4, padT + innerH + 14)
  }
  ctx.fillStyle = '#1D4ED8'; ctx.fillRect(padL + innerW - 110, 6, 8, 8)
  ctx.fillStyle = '#475569'; ctx.fillText('Total', padL + innerW - 98, 13)
  ctx.fillStyle = '#B91C1C'; ctx.fillRect(padL + innerW - 60, 6, 8, 8)
  ctx.fillStyle = '#475569'; ctx.fillText('Febrile', padL + innerW - 48, 13)
}

// ── Auto-refresh (visibility-gated, leak-safe) ──
useAutoRefresh(() => load().catch(() => {}), 30_000)

// Drive count-up KPIs whenever the filtered set changes
watch(() => kpis.value, (k) => {
  kpiTotal.set(k.total)
  kpiSymptomatic.set(k.symptomatic)
  kpiAsymptomatic.set(k.asymptomatic)
  kpiFever.set(k.fever)
  kpiReferred.set(k.referred)
}, { immediate: true })

watch(filtered, () => { nextTick(drawCharts) })

// ── PDF export — premium template ──
async function exportPdf() {
  const auth = getAuth()
  const periodLabel = ({ '24h':'Last 24 hours','7d':'Last 7 days','30d':'Last 30 days','90d':'Last 90 days','all':'All time' })[filter.value.period] || filter.value.period
  const pdf = await createPdf({
    title: 'Primary Screening Report',
    subtitle: 'Real-time POE health surveillance - WHO IHR 2005 Article 23',
    scope: scope.value,
    officer: { name: auth.full_name || auth.username || 'N/A', role: auth.role_key || 'N/A' },
    context: {
      who:   `${auth.full_name || auth.username || 'POE Officer'} (${auth.role_key || 'role'}) at ${scope.value.label || 'POE'}`,
      where: scope.value.level === 'NATIONAL' ? 'National view - all Uganda POEs' : `POE: ${scope.value.label}`,
      what:  `Primary screening surveillance summary - ${filtered.value.length} of ${records.value.length} records`,
      when:  `${periodLabel} - generated ${new Date().toLocaleString('en-GB', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' })}`,
    },
    filters: [
      ['Period',     periodLabel],
      ['POE filter', isNational.value ? (filter.value.poe || 'All POEs') : scope.value.label],
      ['Direction',  filter.value.direction || 'All'],
      ['Symptoms',   filter.value.symptoms === '' ? 'All' : (filter.value.symptoms === '1' ? 'Symptomatic' : 'Asymptomatic')],
      ['Gender',     filter.value.gender || 'All'],
      ['Records',    `${filtered.value.length} of ${records.value.length}`],
    ],
  })
  const k = kpis.value
  pdf.section('Key indicators')
  pdf.kpiGrid([
    { label: 'Travellers screened', value: k.total },
    { label: 'Symptomatic',         value: k.symptomatic, accent: 'warn',   sublabel: `${k.sympRate}% of total` },
    { label: 'Asymptomatic',        value: k.asymptomatic, accent: 'good' },
    { label: 'Fever ≥ 38.5°C',      value: k.fever,        accent: 'bad' },
    { label: 'Auto-referred',       value: k.referred,     accent: 'accent' },
    { label: 'Peak hour',           value: k.peakHour,     sublabel: `${k.peakHourCount} screenings` },
  ])

  pdf.section('Surveillance funnel')
  pdf.barList(funnel.value.map(s => ({ label: s.label, value: s.count, pct: s.pct })), { accent: 'accent' })

  pdf.section('Hourly throughput')
  if (hourCanvas.value) await pdf.embedChart(hourCanvas.value, { caption: 'Screenings per hour of day. Brown overlay = symptomatic.' })

  pdf.section('Daily volume — last 14 days')
  if (dailyCanvas.value) await pdf.embedChart(dailyCanvas.value, { caption: 'Daily total. Red overlay = febrile (≥ 38.5°C).' })

  if (topSymptoms.value.length) {
    pdf.section('Symptom prevalence')
    pdf.barList(topSymptoms.value.map(r => ({ label: r.label, value: r.count, pct: r.pct })), { accent: 'warn' })
  }
  if (isNational.value && poeBreakdown.value.length > 1) {
    pdf.section('Per-POE breakdown')
    pdf.table(
      poeBreakdown.value.map(r => [r.poe, r.count, r.symp, r.pct + '%']),
      { headers: ['POE', 'Total', 'Symptomatic', 'Share'], columnWidths: [240, 80, 100, 80] }
    )
  }

  pdf.section('Methodology note')
  pdf.note('All data is captured at point-of-entry primary screening per WHO IHR 2005 Article 23. Filters above scope this report. Auto-referrals follow Uganda national protocol — every symptomatic traveller produces a notification routed to a secondary screening officer for clinical assessment. Times are local to the device. This report is generated offline-first from on-device records and reflects the data as of the timestamp in the header.')

  const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')
  pdf.save(`primary-screening-report-${(scope.value.label || 'POE').replace(/\W+/g, '_')}-${stamp}.pdf`)
}
</script>

<style scoped>
.psr-content { --background: #F5F7FA; }

.psr-filterbar { --background: #FFFFFF; border-bottom: 1px solid #E2E8F0; }
.psr-filter-scroll {
  display: flex; gap: 8px; padding: 10px 14px;
  overflow-x: auto; -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}
.psr-filter-scroll::-webkit-scrollbar { display: none; }
.psr-pill {
  flex-shrink: 0; padding: 7px 14px; border-radius: 999px;
  border: 1px solid #CBD5E1; background: #FFFFFF;
  font: inherit; font-size: 12.5px; font-weight: 600; color: #475569;
  -webkit-appearance: none; cursor: pointer;
}
.psr-pill--reset {
  background: #0B2545; color: #FFFFFF; border-color: #0B2545;
}
.psr-pill--on {
  background: #DBEAFE; color: #1D4ED8; border-color: #93C5FD;
}
.psr-active-chip {
  flex-shrink: 0; display: inline-flex; align-items: center; gap: 4px;
  padding: 6px 12px; border-radius: 999px;
  background: #0B2545; color: #FFFFFF;
  font-size: 12px; font-weight: 800; letter-spacing: .3px;
}
.psr-active-chip ion-icon { width: 12px; height: 12px; }

.psr-sync-strip {
  display: flex; align-items: center; gap: 8px;
  padding: 8px 16px; font-size: 11px; color: #64748B;
  background: #FFFFFF; border-bottom: 1px solid #E2E8F0;
}
.psr-sync-strip ion-icon { width: 14px; height: 14px; }
.psr-spin { animation: psr-spin 1s linear infinite; }
@keyframes psr-spin { from { transform: rotate(0); } to { transform: rotate(360deg); } }
.psr-scope-tag {
  margin-left: auto;
  padding: 3px 8px; border-radius: 999px;
  background: #DBEAFE; color: #1D4ED8;
  font-weight: 700; font-size: 9.5px; letter-spacing: .4px;
}

.psr-empty {
  padding: 60px 16px; text-align: center; color: #64748B;
  display: flex; flex-direction: column; align-items: center; gap: 12px;
}
.psr-empty ion-icon { width: 36px; height: 36px; color: #CBD5E1; }

.psr-kpis {
  display: grid; gap: 10px; padding: 14px 14px;
  grid-template-columns: repeat(2, 1fr);
}
@media (min-width: 600px) { .psr-kpis { grid-template-columns: repeat(3, 1fr); } }
@media (min-width: 900px) { .psr-kpis { grid-template-columns: repeat(6, 1fr); } }
.psr-kpi {
  --accent: #0B2545;
  position: relative; background: #FFFFFF;
  border: 1px solid #E2E8F0; border-radius: 14px;
  padding: 14px 14px 14px 18px; min-height: 88px;
  box-shadow: 0 1px 2px rgba(15,23,42,.04);
  overflow: hidden;
}
.psr-kpi::before {
  content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
  background: var(--accent);
}
.psr-kpi-num {
  font-size: 26px; font-weight: 800; color: var(--accent);
  line-height: 1.1; margin-bottom: 4px;
  font-feature-settings: 'tnum';
}
.psr-kpi-lbl {
  font-size: 11.5px; font-weight: 600; color: #64748B;
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
}
.psr-kpi-aux {
  font-size: 10px; font-weight: 700; color: var(--accent);
  background: rgba(15,23,42,.05); padding: 1px 6px; border-radius: 999px;
}

.psr-card {
  background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 14px;
  padding: 16px; margin: 10px 14px;
  box-shadow: 0 1px 2px rgba(15,23,42,.03);
}
.psr-card-titlebar { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:6px; }
.psr-card-titlebar > div:first-child { flex:1; min-width:0; }
.psr-card-title {
  margin: 0 0 4px; font-size: 14px; font-weight: 700; color: #0F172A;
  letter-spacing: -.1px;
}
.psr-card-sub { margin: 0 0 14px; font-size: 11.5px; color: #64748B; line-height: 1.4; }
.psr-grid-2 { display: grid; grid-template-columns: 1fr; gap: 0; }
@media (min-width: 720px) { .psr-grid-2 { grid-template-columns: 1fr 1fr; } }

.psr-canvas {
  width: 100%; height: auto; max-height: 200px; display: block;
  border-radius: 6px;
}

/* Funnel */
.psr-funnel { display: flex; flex-direction: column; gap: 8px; }
.psr-funnel-row {
  display: grid; grid-template-columns: 1fr; gap: 4px;
}
.psr-funnel-bar {
  height: 32px; border-radius: 6px;
  display: flex; align-items: center; justify-content: flex-end;
  padding: 0 12px; color: #FFFFFF; font-weight: 700;
  min-width: 30%;
  transition: width .4s ease;
}
.psr-funnel-num { font-size: 14px; }
.psr-funnel-meta {
  display: flex; justify-content: space-between; font-size: 11.5px;
  padding: 0 4px;
}
.psr-funnel-lbl { color: #0F172A; font-weight: 600; }
.psr-funnel-pct { color: #64748B; }

.psr-row {
  display: grid; grid-template-columns: 110px 1fr 100px;
  gap: 10px; align-items: center; padding: 6px 0;
}
.psr-row-lbl { font-size: 12px; color: #334155; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.psr-row-bar { background: #F1F5F9; border-radius: 4px; height: 8px; overflow: hidden; }
.psr-row-bar-fill { background: #1D4ED8; height: 100%; transition: width .4s ease; }
.psr-row-val { text-align: right; font-size: 12px; color: #475569; font-weight: 700; font-feature-settings: 'tnum'; }
.psr-row-val em { color: #94A3B8; font-style: normal; font-weight: 500; }
.psr-mini-empty { color: #94A3B8; font-size: 12px; padding: 12px 0; text-align: center; }

/* Pyramid */
.psr-pyramid { display: flex; flex-direction: column; gap: 4px; padding: 4px 0; }
.psr-pyramid-row {
  display: grid; grid-template-columns: 1fr 60px 1fr; align-items: center; gap: 6px;
}
.psr-pyramid-half { display: flex; }
.psr-pyramid-half--m { justify-content: flex-end; }
.psr-pyramid-half--f { justify-content: flex-start; }
.psr-pyramid-bar {
  height: 22px; border-radius: 4px;
  display: flex; align-items: center; padding: 0 8px;
  font-size: 11px; font-weight: 700; color: #FFFFFF;
  min-width: 2px; transition: width .3s ease;
}
.psr-pyramid-bar--m { background: #1D4ED8; justify-content: flex-end; }
.psr-pyramid-bar--f { background: #DB2777; }
.psr-pyramid-lbl {
  text-align: center; font-size: 11px; font-weight: 700; color: #475569;
  font-feature-settings: 'tnum';
}
.psr-pyramid-legend {
  margin-top: 12px; display: flex; gap: 14px; font-size: 11px; color: #64748B; align-items: center;
}
.psr-leg { width: 12px; height: 12px; border-radius: 3px; display: inline-block; margin-right: 4px; }
.psr-leg--m { background: #1D4ED8; }
.psr-leg--f { background: #DB2777; }

/* ── Staggered card entrance (subtle, premium) ─────────────────────────── */
.psr-rise {
  animation: psr-rise 480ms cubic-bezier(.16, 1, .3, 1) backwards;
  animation-delay: calc(var(--i, 0) * 35ms);
}
@keyframes psr-rise {
  from { opacity: 0; transform: translate3d(0, 8px, 0); }
  to   { opacity: 1; transform: translate3d(0, 0, 0); }
}
@media (prefers-reduced-motion: reduce) { .psr-rise { animation: none; } }

/* ── Skeleton (matches final card geometry exactly — no layout shift) ─── */
.psr-skel { pointer-events: none; user-select: none; }
.psr-skel .psr-skel-bar,
.psr-skel-block {
  background: linear-gradient(90deg, #EEF2F7 0%, #F8FAFC 50%, #EEF2F7 100%);
  background-size: 200% 100%;
  border-radius: 6px;
  animation: psr-shimmer 1.4s linear infinite;
}
.psr-skel-bar { height: 12px; margin-bottom: 8px; width: 60%; }
.psr-skel-bar--big   { height: 26px; width: 40%; }
.psr-skel-bar--title { height: 16px; width: 50%; margin-bottom: 16px; }
.psr-skel-block { height: 100px; margin-top: 6px; }
.psr-skel-block--tall { height: 160px; }
@keyframes psr-shimmer {
  from { background-position: 200% 0; } to { background-position: -200% 0; }
}
</style>
