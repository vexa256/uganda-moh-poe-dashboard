/**
 * useIntelligenceData.js — ECSA-HC POE Sentinel
 * ══════════════════════════════════════════════════════════════════════
 * Offline-first data layer for the Screening Intelligence Dashboard.
 *
 * ARCHITECTURE:
 *   1. On mount: read cached dashboard snapshot from IDB (instant, offline-safe)
 *   2. If online: fetch fresh data from server, write to IDB, update reactive state
 *   3. Every 15s: background incremental check
 *   4. Filters: day/week/month/year/custom range — sent as date_from/date_to to server,
 *      applied client-side to cached data when offline
 *
 * All state is reactive. All chart helpers are pure functions.
 * ══════════════════════════════════════════════════════════════════════
 */

import { ref, computed, reactive, onMounted, onUnmounted } from 'vue'
import { onIonViewWillEnter } from '@ionic/vue'
import { APP, dbGet, dbPut, STORE } from '@/services/poeDB'

function getAuth() {
  return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {}
}

// ─── CONSTANTS ───────────────────────────────────────────────────────────────
export const QUICK_PERIODS = [
  { v: 'today', l: 'Today' },
  { v: '7d', l: '7 Days' },
  { v: '14d', l: '14 Days' },
  { v: '30d', l: '30 Days' },
  { v: '90d', l: '90 Days' },
  { v: 'all', l: 'All Time' },
  { v: 'custom', l: 'Custom' },
]
export const MONTHS = [
  { v: 0, l: 'Jan' }, { v: 1, l: 'Feb' }, { v: 2, l: 'Mar' }, { v: 3, l: 'Apr' },
  { v: 4, l: 'May' }, { v: 5, l: 'Jun' }, { v: 6, l: 'Jul' }, { v: 7, l: 'Aug' },
  { v: 8, l: 'Sep' }, { v: 9, l: 'Oct' }, { v: 10, l: 'Nov' }, { v: 11, l: 'Dec' },
]
export const THIS_YEAR = new Date().getFullYear()
export const YEARS = [THIS_YEAR, THIS_YEAR - 1, THIS_YEAR - 2]
// Legacy values retained as fallback for existing records, but UI only shows MALE/FEMALE
export const GS = { MALE: 'M', FEMALE: 'F', OTHER: '—', UNKNOWN: '—' }
export const GENDER_FULL = { MALE: 'Male', FEMALE: 'Female', OTHER: '—', UNKNOWN: '—' }
export const TW = 300

const POLL_MS = 15_000
const IDB_CACHE_KEY = 'dashboard_cache'

// ─── COMPOSABLE ──────────────────────────────────────────────────────────────
export function useIntelligenceData() {
  const auth = ref(getAuth())
  const isOnline = ref(navigator.onLine)

  // ── Filter state ──────────────────────────────────────────────────────
  const activeFilter  = ref('30d')
  const showAdvFilter = ref(false)
  const filterDate    = ref('')
  const filterMonth   = ref(null)
  const filterYear    = ref(null)
  const filterFrom    = ref('')
  const filterTo      = ref('')

  const advFilterCount = computed(() =>
    [filterDate.value, filterMonth.value !== null ? 1 : null,
     filterYear.value, filterFrom.value, filterTo.value].filter(Boolean).length
  )

  const filterLabel = computed(() => {
    const f = activeFilter.value
    if (f === 'custom') {
      if (filterDate.value) return filterDate.value
      if (filterMonth.value !== null) {
        const ml = MONTHS.find(m => m.v === filterMonth.value)?.l || ''
        return filterYear.value ? `${ml} ${filterYear.value}` : ml
      }
      if (filterYear.value) return String(filterYear.value)
      if (filterFrom.value || filterTo.value) return `${filterFrom.value || '...'} - ${filterTo.value || '...'}`
      return 'Custom'
    }
    return QUICK_PERIODS.find(p => p.v === f)?.l || '30 Days'
  })

  function setFilter(v) {
    activeFilter.value = v
    filterDate.value = ''; filterMonth.value = null; filterYear.value = null
    filterFrom.value = ''; filterTo.value = ''
    loadAll()
  }
  function clearFilters() {
    setFilter('30d')
    showAdvFilter.value = false
  }

  function computeDateParams() {
    const now = new Date()
    let from = null, to = null
    const f = activeFilter.value
    if (f === 'today')  { from = iso(now); to = from }
    else if (f === '7d')  from = iso(daysAgo(now, 7))
    else if (f === '14d') from = iso(daysAgo(now, 14))
    else if (f === '30d') from = iso(daysAgo(now, 30))
    else if (f === '90d') from = iso(daysAgo(now, 90))
    else if (f === 'all') { /* no params */ }
    else if (f === 'custom') {
      if (filterDate.value) { from = filterDate.value; to = filterDate.value }
      else if (filterMonth.value !== null || filterYear.value) {
        const y = filterYear.value || THIS_YEAR
        const ms = filterMonth.value !== null ? filterMonth.value : 0
        const me = filterMonth.value !== null ? filterMonth.value : 11
        from = iso(new Date(y, ms, 1))
        to   = iso(new Date(y, me + 1, 0))
      } else { from = filterFrom.value || null; to = filterTo.value || null }
    }
    let p = ''
    if (from) p += `date_from=${from}`
    if (to)   p += `${p ? '&' : ''}date_to=${to}`
    return p
  }
  function trendDays() {
    const m = { today: 1, '7d': 7, '14d': 14, '30d': 30, '90d': 90, all: 365 }
    if (m[activeFilter.value]) return m[activeFilter.value]
    if (filterDate.value) return 1
    if (filterFrom.value && filterTo.value) return Math.max(1, Math.min(Math.ceil((new Date(filterTo.value) - new Date(filterFrom.value)) / 864e5), 365))
    if (filterMonth.value !== null) return 31
    if (filterYear.value) return 365
    return 30
  }
  function iso(d) { return d.toISOString().slice(0, 10) }
  function daysAgo(d, n) { const r = new Date(d); r.setDate(r.getDate() - n); return r }

  // ── Data state ────────────────────────────────────────────────────────
  const loading    = ref(false)
  const sum        = ref(null)
  const periodSum  = ref(null)   // period-filtered totals (tied to active filter)
  const trend      = ref(null)
  const hmap       = ref(null)
  const fun        = ref(null)
  const epi        = ref(null)
  const devices    = ref(null)
  const officers   = ref(null)
  const weekly     = ref(null)
  const alertsData = ref(null)
  const liveData   = ref(null)
  const lastSyncAt = ref(null)
  let pollTimer = null
  let liveTimer = null

  // ── Computed ──────────────────────────────────────────────────────────
  // For 'today' filter: use today's rate from summary.
  // For all other periods: use the period-filtered rate from periodSum.
  const sr = computed(() => {
    if (activeFilter.value === 'today') {
      const t = sum.value?.today?.total || 0
      const s = sum.value?.today?.symptomatic || 0
      return t ? Math.round(s / t * 100) : 0
    }
    // period rate from date-filtered endpoint
    const t = periodSum.value?.total || 0
    const s = periodSum.value?.symptomatic || 0
    return t ? Math.round(s / t * 100) : 0
  })
  const srColor = computed(() => sr.value >= 30 ? '#E53935' : sr.value >= 15 ? '#FF9800' : '#43A047')
  const srDash = computed(() => { const c = 2 * Math.PI * 42; const f = sr.value / 100 * c; return `${f} ${c - f}` })
  const delta = computed(() => sum.value?.today?.vs_yesterday ?? 0)
  const peakH = computed(() => (hmap.value?.buckets || []).reduce((m, x) => x.total > (m?.total || 0) ? x : m, { bucket: -1 }).bucket)
  const tBands = computed(() => {
    const b = epi.value?.temperature?.bands; if (!b) return []
    const tot = (b.hypothermia || 0) + (b.normal || 0) + (b.low_grade_fever || 0) + (b.high_fever || 0)
    if (!tot) return []
    const p = v => Math.round((v || 0) / tot * 100)
    return [
      { k: 'hf', l: 'High Fever', n: b.high_fever || 0, p: p(b.high_fever), c: '#E53935' },
      { k: 'lg', l: 'Low-Grade', n: b.low_grade_fever || 0, p: p(b.low_grade_fever), c: '#FF7043' },
      { k: 'nm', l: 'Normal', n: b.normal || 0, p: p(b.normal), c: '#26A69A' },
      { k: 'hy', l: 'Hypo', n: b.hypothermia || 0, p: p(b.hypothermia), c: '#1E88E5' },
    ]
  })
  const trendLabels = computed(() => {
    const s = trend.value?.series || []
    if (s.length <= 7) return s.map(d => d.date.slice(5))
    const step = Math.ceil(s.length / 6)
    return s.map((d, i) => (i % step === 0 || i === s.length - 1) ? d.date.slice(5) : '')
  })

  // ── Chart helpers ─────────────────────────────────────────────────────
  function trendLine(field) {
    const s = trend.value?.series || []; if (!s.length) return ''
    const v = s.map(d => d[field] || 0); const max = Math.max(...v, 1)
    const H = 70, P = 5
    return s.map((d, i) => `${P + i / Math.max(s.length - 1, 1) * (TW - P * 2)},${H - P - (d[field] || 0) / max * (H - P * 2)}`).join(' ')
  }
  function trendAreaPath(field) {
    const s = trend.value?.series || []; if (!s.length) return ''
    const v = s.map(d => d[field] || 0); const max = Math.max(...v, 1)
    const H = 70, P = 5
    const pts = s.map((d, i) => `${P + i / Math.max(s.length - 1, 1) * (TW - P * 2)},${H - P - (d[field] || 0) / max * (H - P * 2)}`)
    return `M${pts[0]} L${pts.join(' L')} L${TW - P},${H} L${P},${H} Z`
  }
  function genderPct(g) { const a = epi.value?.by_gender || []; const t = a.reduce((s, x) => s + (x.total || 0), 0); return t ? Math.round(g.total / t * 100) : 0 }
  function synPct(s) { return Math.round(s.count / Math.max(...(epi.value?.syndromes || []).map(x => x.count), 1) * 100) }
  function hourPct(b) { return Math.round(b.total / Math.max(...(hmap.value?.buckets || []).map(x => x.total), 1) * 100) }
  function officerPct(o) { return Math.round(o.total / Math.max(...(officers.value?.screeners || []).map(x => x.total), 1) * 100) }
  function funnelPct(f) { return Math.max(f.rate, 3) }

  // ── IDB CACHE — offline dashboard ─────────────────────────────────────
  async function loadFromCache() {
    try {
      const cached = await dbGet('users_local', IDB_CACHE_KEY).catch(() => null)
      if (!cached?.data) return false
      const d = cached.data
      sum.value = d.sum; periodSum.value = d.periodSum || null
      trend.value = d.trend; hmap.value = d.hmap
      fun.value = d.fun; epi.value = d.epi; devices.value = d.devices
      officers.value = d.officers; weekly.value = d.weekly
      alertsData.value = d.alertsData; liveData.value = d.liveData
      lastSyncAt.value = cached.synced_at || null
      return true
    } catch { return false }
  }
  async function saveToCache() {
    try {
      const now = new Date().toISOString()
      await dbPut('users_local', {
        client_uuid: IDB_CACHE_KEY,
        synced_at: now,
        data: {
          sum: sum.value, periodSum: periodSum.value,
          trend: trend.value, hmap: hmap.value,
          fun: fun.value, epi: epi.value, devices: devices.value,
          officers: officers.value, weekly: weekly.value,
          alertsData: alertsData.value, liveData: liveData.value,
        },
      })
      lastSyncAt.value = now
    } catch (e) { console.warn('[SD] saveToCache', e?.message) }
  }

  // ── API ───────────────────────────────────────────────────────────────
  async function api(path) {
    const uid = auth.value?.id; if (!uid) return null
    const sep = path.includes('?') ? '&' : '?'
    const ctrl = new AbortController()
    const tid = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS)
    try {
      const res = await fetch(`${window.SERVER_URL}${path}${sep}user_id=${uid}`, {
        headers: { Accept: 'application/json' }, signal: ctrl.signal,
      })
      clearTimeout(tid)
      if (!res.ok) return null
      const j = await res.json()
      return j.success ? j.data : null
    } catch { clearTimeout(tid); return null }
  }

  // ── Load all ──────────────────────────────────────────────────────────
  async function loadAll() {
    loading.value = true
    auth.value = getAuth()
    isOnline.value = navigator.onLine

    // Phase 1: load from IDB cache (instant, offline-safe)
    const hadCache = await loadFromCache()
    if (hadCache) loading.value = false

    // Phase 2: fetch from server if online
    if (isOnline.value) {
      const dp = computeDateParams()
      const days = trendDays()
      try {
        const dpQ = dp ? `?${dp}` : ''
        const dpA = dp ? `&${dp}` : ''

        // Batch 1: summary (always all-time/today) + period-filtered counts + trend
        const [s, ps, t] = await Promise.all([
          api('/dashboard/summary'),
          api(`/dashboard/period-summary${dpQ}`),
          api(`/dashboard/trend?days=${days}${dpA}`),
        ])
        if (s) sum.value = s
        if (ps) periodSum.value = ps
        if (t) trend.value = t

        // Batch 2: epi, heatmap, funnel, alerts — all date-filtered
        const [e, h, f, al] = await Promise.all([
          api(`/dashboard/epi${dpQ}`), api(`/dashboard/heatmap?group_by=hour${dpA}`),
          api(`/dashboard/funnel${dpQ}`), api(`/dashboard/alerts-summary${dpQ}`),
        ])
        if (e) epi.value = e; if (h) hmap.value = h
        if (f) fun.value = f; if (al) alertsData.value = al

        // Batch 3: weekly (always current week), devices + officers — date-filtered
        const [w, d, o] = await Promise.all([
          api('/dashboard/weekly-report'), api(`/dashboard/device-health${dpQ}`), api(`/dashboard/screener-report${dpQ}`),
        ])
        if (w) weekly.value = w; if (d) devices.value = d; if (o) officers.value = o

        await saveToCache()
      } catch (e) { console.warn('[SD] loadAll', e?.message) }
    }
    loading.value = false
  }

  async function loadLive() {
    if (!isOnline.value) return
    const d = await api('/dashboard/live')
    if (d) { liveData.value = d; saveToCache().catch(() => {}) }
  }

  async function pullRefresh(ev) { await loadAll(); ev.target.complete() }

  function onOnline() { isOnline.value = true; loadAll() }
  function onOffline() { isOnline.value = false }

  // ── Lifecycle ─────────────────────────────────────────────────────────
  onMounted(() => {
    auth.value = getAuth()
    window.addEventListener('online', onOnline)
    window.addEventListener('offline', onOffline)
    loadAll()
    loadLive()
    liveTimer = setInterval(loadLive, 30_000)
    pollTimer = setInterval(() => { if (isOnline.value && !loading.value) loadAll() }, POLL_MS)
  })
  onIonViewWillEnter(() => { auth.value = getAuth(); loadAll(); loadLive() })
  onUnmounted(() => {
    window.removeEventListener('online', onOnline)
    window.removeEventListener('offline', onOffline)
    clearInterval(liveTimer); clearInterval(pollTimer)
  })

  return {
    auth, isOnline,
    activeFilter, showAdvFilter, filterDate, filterMonth, filterYear,
    filterFrom, filterTo, advFilterCount, filterLabel,
    setFilter, clearFilters,
    loading, sum, periodSum, trend, hmap, fun, epi, devices, officers, weekly, alertsData, liveData, lastSyncAt,
    sr, srColor, srDash, delta, peakH, tBands, trendLabels,
    trendLine, trendAreaPath, genderPct, synPct, hourPct, officerPct, funnelPct,
    loadAll, pullRefresh,
  }
}
