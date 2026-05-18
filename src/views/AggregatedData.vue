<template>
  <IonPage>
    <!-- ═══ HEADER ═══════════════════════════════════════════════════ -->
    <IonHeader class="ad-hdr" translucent>
      <div class="ad-hdr-bg" :style="{ '--accent': template?.colour || '#1E40AF' }">
        <div class="ad-hdr-top">
          <IonButtons slot="start"><IonBackButton default-href="/aggregated-data" class="ad-back"/></IonButtons>
          <div class="ad-hdr-title">
            <span class="ad-hdr-eye" v-if="template">{{ freqLabel(template.reporting_frequency) }} · v{{ template.version }}</span>
            <span v-else class="ad-hdr-eye">Aggregated</span>
            <span class="ad-hdr-h1">{{ template?.template_name || 'Loading…' }}</span>
          </div>
          <IonButtons slot="end">
            <span :class="['ad-sync', syncPillClass]">
              <span class="ad-sync-dot"/>{{ SYNC.LABELS[syncStatus] || syncStatus }}
            </span>
          </IonButtons>
        </div>
        <!-- Step progress -->
        <div class="ad-steps" v-if="template">
          <div v-for="(st, i) in STEPS" :key="st.k" :class="['ad-step', step === st.k && 'ad-step--on', stepIndex > i && 'ad-step--done']" @click="goStep(st.k)">
            <span class="ad-step-n">{{ i + 1 }}</span>
            <span class="ad-step-l">{{ st.l }}</span>
          </div>
        </div>
      </div>
    </IonHeader>

    <!-- ═══ CONTENT ═══════════════════════════════════════════════════ -->
    <IonContent class="ad-content" :fullscreen="true">
      <!-- Loading -->
      <div v-if="loading" class="ad-loading">
        <div class="ad-spinner"/>
        <span>Loading template…</span>
      </div>

      <!-- Template not found -->
      <div v-else-if="!template" class="ad-err">
        <div class="ad-err-ic">!</div>
        <div class="ad-err-t">Template unavailable</div>
        <div class="ad-err-s">This report template may have been retired or the cache is stale. Pull to refresh from the hub.</div>
        <button class="ad-err-btn" @click="$router.push('/aggregated-data')">Back to reports</button>
      </div>

      <!-- Submitted success -->
      <div v-else-if="submitted" class="ad-done">
        <div class="ad-done-check">
          <svg viewBox="0 0 64 64" width="56" height="56" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round">
            <path class="ad-done-circle" d="M32 4 a 28 28 0 1 1 0 56 a 28 28 0 1 1 0 -56" />
            <polyline class="ad-done-tick" points="20 32 29 41 44 24"/>
          </svg>
        </div>
        <div class="ad-done-t">Report saved</div>
        <div class="ad-done-s">Queued for upload. It will sync automatically when online.</div>
        <div class="ad-done-acts">
          <button class="ad-done-btn ad-done-btn--primary" @click="$router.push('/aggregated-data')">Done</button>
          <button class="ad-done-btn" @click="startNew">Submit another</button>
        </div>
      </div>

      <!-- Dynamic form -->
      <form v-else @submit.prevent="onNext" class="ad-form" novalidate>
        <!-- ── STEP: PERIOD ── -->
        <section v-if="step === 'period'" class="ad-sec">
          <p class="ad-intro">Tell us what period this report covers.</p>
          <div class="ad-period-grid">
            <div class="ad-f">
              <label class="ad-lbl">From *</label>
              <input type="date" v-model="form.period_start" :max="todayStr" class="ad-inp" :class="errors.period_start && 'ad-inp--err'"/>
              <div v-if="errors.period_start" class="ad-err-line">{{ errors.period_start }}</div>
            </div>
            <div class="ad-f">
              <label class="ad-lbl">To *</label>
              <input type="date" v-model="form.period_end" :max="todayStr" class="ad-inp" :class="errors.period_end && 'ad-inp--err'"/>
              <div v-if="errors.period_end" class="ad-err-line">{{ errors.period_end }}</div>
            </div>
          </div>
          <div v-if="periodDays > 0" class="ad-hint">Covers {{ periodDays }} day{{ periodDays === 1 ? '' : 's' }}.</div>
          <div v-if="periodDays > 30" class="ad-warn">⚠ {{ periodDays }} days is longer than usual for a {{ freqLabel(template.reporting_frequency).toLowerCase() }} report. Verify this is intentional.</div>
          <!-- Auto-calculate suggestion (daily/weekly only) -->
          <button v-if="['DAILY','WEEKLY'].includes(template.reporting_frequency)" type="button" class="ad-calc" @click="autoCalculate" :disabled="calculating">
            {{ calculating ? 'Calculating…' : '🧮 Auto-calculate from local screenings' }}
          </button>
          <div v-if="autoMsg" class="ad-hint">{{ autoMsg }}</div>
        </section>

        <!-- ── STEP: FILL ── -->
        <section v-if="step === 'fill'" class="ad-sec">
          <p class="ad-intro">Enter the numbers for each field. Required fields are marked *.</p>

          <div v-for="cat in categorisedColumns" :key="cat.category" class="ad-group">
            <header class="ad-group-h">
              <span :class="['ad-group-pill', `ad-group-pill--${cat.category.toLowerCase()}`]">{{ cat.category }}</span>
              <span class="ad-group-n">{{ cat.cols.length }} field{{ cat.cols.length === 1 ? '' : 's' }}</span>
            </header>
            <div class="ad-fields">
              <div v-for="c in cat.cols" :key="c.column_key" class="ad-f">
                <label class="ad-lbl">
                  {{ c.column_label }}<span v-if="c.is_required == 1" class="ad-req">*</span>
                  <span v-if="c.help_text" class="ad-help">{{ c.help_text }}</span>
                </label>

                <!-- INTEGER -->
                <input v-if="c.data_type === 'INTEGER'" type="number" inputmode="numeric" min="0" step="1"
                  v-model.number="values[c.column_key]" class="ad-inp" :class="errors[c.column_key] && 'ad-inp--err'"
                  :placeholder="c.placeholder || '0'" @input="validateField(c)"/>

                <!-- DECIMAL -->
                <input v-else-if="c.data_type === 'DECIMAL'" type="number" step="0.01"
                  v-model.number="values[c.column_key]" class="ad-inp" :class="errors[c.column_key] && 'ad-inp--err'"
                  :placeholder="c.placeholder || '0.00'" @input="validateField(c)"/>

                <!-- PERCENT -->
                <input v-else-if="c.data_type === 'PERCENT'" type="number" step="0.1" min="0" max="100"
                  v-model.number="values[c.column_key]" class="ad-inp" :class="errors[c.column_key] && 'ad-inp--err'"
                  :placeholder="c.placeholder || '0-100'" @input="validateField(c)"/>

                <!-- DATE -->
                <input v-else-if="c.data_type === 'DATE'" type="date"
                  v-model="values[c.column_key]" class="ad-inp" :class="errors[c.column_key] && 'ad-inp--err'"
                  @change="validateField(c)"/>

                <!-- SELECT -->
                <select v-else-if="c.data_type === 'SELECT'" v-model="values[c.column_key]"
                  class="ad-inp" :class="errors[c.column_key] && 'ad-inp--err'" @change="validateField(c)">
                  <option value="">—</option>
                  <option v-for="opt in (c.select_options || [])" :key="opt" :value="opt">{{ opt }}</option>
                </select>

                <!-- BOOLEAN -->
                <label v-else-if="c.data_type === 'BOOLEAN'" class="ad-bool">
                  <input type="checkbox" v-model="values[c.column_key]" @change="validateField(c)"/>
                  <span>{{ values[c.column_key] ? 'Yes' : 'No' }}</span>
                </label>

                <!-- TEXT -->
                <input v-else type="text" v-model.trim="values[c.column_key]"
                  class="ad-inp" :class="errors[c.column_key] && 'ad-inp--err'"
                  :placeholder="c.placeholder || ''" maxlength="500" @input="validateField(c)"/>

                <div v-if="errors[c.column_key]" class="ad-err-line">{{ errors[c.column_key] }}</div>
              </div>
            </div>
          </div>

          <!-- Sum validation pills -->
          <div class="ad-sums">
            <span v-if="hasGender" :class="['ad-sum', genderSumOk ? 'ad-sum--ok' : 'ad-sum--bad']">
              Gender {{ genderSum }} / {{ screened }} {{ genderSumOk ? '✓' : '≠' }}
            </span>
            <span v-if="hasSymptoms" :class="['ad-sum', sympSumOk ? 'ad-sum--ok' : 'ad-sum--bad']">
              Symptom {{ sympSum }} / {{ screened }} {{ sympSumOk ? '✓' : '≠' }}
            </span>
          </div>
        </section>

        <!-- ── STEP: NOTES ── -->
        <section v-if="step === 'notes'" class="ad-sec">
          <p class="ad-intro">Add any context — exceptional events, retrospective counts, interruptions.</p>
          <textarea v-model="form.notes" rows="4" maxlength="255" class="ad-inp ad-inp--ta"
            placeholder="Optional — up to 255 characters"/>
          <div class="ad-hint">{{ (form.notes || '').length }} / 255</div>
        </section>

        <!-- ── STEP: REVIEW ── -->
        <section v-if="step === 'review'" class="ad-sec">
          <p class="ad-intro">Review before submitting. Data is saved on-device even if the network fails.</p>

          <div class="ad-rev-card">
            <div class="ad-rev-hdr">
              <span class="ad-rev-k">Report</span>
              <span class="ad-rev-v">{{ template.template_name }}</span>
            </div>
            <div class="ad-rev-row"><span class="ad-rev-k">Period</span><span class="ad-rev-v">{{ form.period_start }} → {{ form.period_end }}</span></div>
            <div class="ad-rev-row"><span class="ad-rev-k">POE</span><span class="ad-rev-v">{{ auth.poe_code || '—' }}</span></div>
            <div class="ad-rev-row"><span class="ad-rev-k">Submitted by</span><span class="ad-rev-v">{{ auth.full_name || auth.username || '—' }}</span></div>
          </div>

          <h4 class="ad-rev-sh">Values</h4>
          <div class="ad-rev-vals">
            <div v-for="c in filledColumns" :key="c.column_key" class="ad-rev-val">
              <span class="ad-rev-k">{{ c.column_label }}</span>
              <span class="ad-rev-v">{{ fmtValue(c) }}</span>
            </div>
            <div v-if="filledColumns.length === 0" class="ad-none">No values entered — only zeros would be submitted.</div>
          </div>

          <div v-if="submitError" class="ad-submit-err">{{ submitError }}</div>
        </section>

        <!-- Nav -->
        <div class="ad-nav">
          <button type="button" class="ad-nav-btn ad-nav-btn--ghost" :disabled="stepIndex === 0" @click="goBack">Back</button>
          <button type="submit" class="ad-nav-btn ad-nav-btn--primary" :disabled="submitting">
            <template v-if="step === 'review'">{{ submitting ? 'Saving…' : 'Submit report' }}</template>
            <template v-else>Next</template>
          </button>
        </div>
      </form>

      <div style="height:48px"/>
    </IonContent>

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="3000" position="top" @didDismiss="toast.show=false"/>
  </IonPage>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue'
import {
  IonPage, IonHeader, IonContent, IonButtons, IonBackButton, IonToast,
  onIonViewDidEnter,
} from '@ionic/vue'
import { useRoute, useRouter } from 'vue-router'
import {
  dbPut, dbGet, dbGetByIndex, dbGetAll,
  genUUID, isoNow, createRecordBase,
  STORE, SYNC, APP,
} from '@/services/poeDB'

const route = useRoute()
const router = useRouter()

function getAuth() { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {} }
const auth = ref(getAuth())
const todayStr = new Date().toISOString().slice(0, 10)

// Wizard steps (a submission wizard — not to be confused with the admin
// template wizard, which is a separate view).
const STEPS = [
  { k: 'period', l: 'Period' },
  { k: 'fill',   l: 'Fill'   },
  { k: 'notes',  l: 'Notes'  },
  { k: 'review', l: 'Review' },
]
const step = ref('period')
const stepIndex = computed(() => STEPS.findIndex(s => s.k === step.value))

// ── Template state ─────────────────────────────────────────────────────────
const template = ref(null)
const columns  = ref([])
const loading  = ref(true)
const values   = reactive({})   // column_key → value
const errors   = reactive({})
const form     = reactive({ period_start: todayStr, period_end: todayStr, notes: '' })

const submitting = ref(false)
const submitted  = ref(false)
const submitError = ref('')
const syncStatus = ref(SYNC.UNSYNCED)
const calculating = ref(false)
const autoMsg = ref('')
const toast = reactive({ show: false, msg: '', color: 'success' })

// ── Resolve template from route param, prefer IDB cache (offline-first) ────
async function loadTemplate() {
  loading.value = true
  // Audit AD-001/AD-002: validate the template id explicitly. Number('abc')
  // and Number(undefined) both produce NaN; Number(0) is 0. The previous
  // `Number(... || 0)` swallowed all three into 0 and the wizard rendered
  // blank. Now: if the id is invalid, send the user back to the hub with a
  // toast instead of silently degrading.
  const tid = Number(route.params.templateId)
  if (!Number.isFinite(tid) || tid <= 0) {
    loading.value = false
    toast.msg = 'Template not found.'; toast.color = 'danger'; toast.show = true
    setTimeout(() => router.replace('/aggregated-data'), 800)
    return
  }

  try {
    // 1. Cache first (offline safety)
    let cached = await dbGet(STORE.AGGREGATED_TEMPLATES_CACHE, tid).catch(() => null)
    if (cached) {
      template.value = cached
      columns.value = (cached.columns || []).filter(c => c.is_enabled == 1)
      initValues()
      loading.value = false
    }

    // 2. Server refresh (if online) — overlays newer data
    let serverFound = false
    if (navigator.onLine) {
      try {
        const res = await fetch(
          `${window.SERVER_URL}/aggregated-templates/${tid}?user_id=${auth.value?.id}`,
          { headers: { Accept: 'application/json' } },
        )
        const body = await res.json().catch(() => null)
        if (res.ok && body?.success) {
          serverFound = true
          const t = body.data.template
          template.value = { ...t, columns: body.data.columns }
          columns.value = (body.data.columns || []).filter(c => c.is_enabled == 1)
          // Update cache
          await dbPut(STORE.AGGREGATED_TEMPLATES_CACHE, { ...t, columns: body.data.columns, cached_at: new Date().toISOString() }).catch(() => {})
          initValues()
        } else if (res.status === 404) {
          // Template was published once (we may have cache) but is now gone
          // server-side — surface and bounce. Audit AD-002.
          toast.msg = 'This template was retired by an administrator.'
          toast.color = 'warning'
          toast.show = true
          setTimeout(() => router.replace('/aggregated-data'), 1200)
          return
        }
      } catch (_) { /* offline is fine — we have cache */ }
    }
    // If neither cache nor server gave us a template, bounce.
    if (!template.value && !serverFound) {
      toast.msg = 'Template not available.'
      toast.color = 'danger'
      toast.show = true
      setTimeout(() => router.replace('/aggregated-data'), 800)
    }
  } finally {
    loading.value = false
  }
}

function initValues() {
  for (const c of columns.value) {
    if (values[c.column_key] !== undefined) continue
    if (c.data_type === 'BOOLEAN') values[c.column_key] = false
    else if (['INTEGER', 'DECIMAL', 'PERCENT'].includes(c.data_type)) values[c.column_key] = 0
    else values[c.column_key] = ''
  }
}

// ── Columns grouped by category for rendering ──────────────────────────────
const categorisedColumns = computed(() => {
  const byCat = new Map()
  for (const c of columns.value) {
    const k = c.category || 'CUSTOM'
    if (!byCat.has(k)) byCat.set(k, [])
    byCat.get(k).push(c)
  }
  const order = ['CORE', 'GENDER', 'AGE', 'SYMPTOMS', 'DISEASE', 'OUTCOMES', 'TRAVEL', 'CONVEYANCE', 'ENVIRONMENT', 'VACCINE', 'LAB', 'QUALITY', 'CUSTOM']
  return order
    .filter(c => byCat.has(c))
    .map(c => ({ category: c, cols: byCat.get(c) }))
    .concat(
      Array.from(byCat.keys()).filter(c => !order.includes(c)).map(c => ({ category: c, cols: byCat.get(c) })),
    )
})

const filledColumns = computed(() =>
  columns.value.filter(c => {
    const v = values[c.column_key]
    if (c.data_type === 'BOOLEAN') return !!v
    if (['INTEGER', 'DECIMAL', 'PERCENT'].includes(c.data_type)) return typeof v === 'number' && v > 0
    return typeof v === 'string' && v.length > 0
  }),
)

// Sum validations
const screened = computed(() => Number(values.total_screened || 0))
const hasGender = computed(() => columns.value.some(c => c.column_key === 'total_male' || c.column_key === 'total_female'))
const hasSymptoms = computed(() => columns.value.some(c => c.column_key === 'total_symptomatic' || c.column_key === 'total_asymptomatic'))
// MALE + FEMALE only — OTHER/UNKNOWN gender columns retired 2026-04-21.
const genderSum = computed(() => ['total_male', 'total_female']
  .reduce((s, k) => s + Number(values[k] || 0), 0))
const sympSum = computed(() => Number(values.total_symptomatic || 0) + Number(values.total_asymptomatic || 0))
const genderSumOk = computed(() => genderSum.value === screened.value)
const sympSumOk   = computed(() => sympSum.value === screened.value)

const periodDays = computed(() => {
  if (!form.period_start || !form.period_end) return 0
  return Math.round((new Date(form.period_end) - new Date(form.period_start)) / 86400000) + 1
})
const syncPillClass = computed(() => syncStatus.value === SYNC.SYNCED ? 'ad-sync--ok' : 'ad-sync--pending')

// ── Validation ─────────────────────────────────────────────────────────────
function validateField(c) {
  const v = values[c.column_key]
  let err = ''
  if (c.is_required == 1) {
    // A required field is empty when the user has not entered anything.
    // For numerics, 0 IS a valid entry — except total_screened, where 0
    // means the user ran the report without capturing anything and must
    // re-enter, so we treat that case as empty.
    const isEmptyEntry = v === null || v === undefined || v === ''
    const isTotalScreenedZero =
      c.column_key === 'total_screened' &&
      ['INTEGER', 'DECIMAL', 'PERCENT'].includes(c.data_type) &&
      v === 0
    if (isEmptyEntry || isTotalScreenedZero) err = 'Required'
  }
  if (['INTEGER', 'DECIMAL', 'PERCENT'].includes(c.data_type) && typeof v === 'number') {
    if (c.min_value != null && v < Number(c.min_value)) err = `Minimum ${c.min_value}`
    if (c.max_value != null && v > Number(c.max_value)) err = `Maximum ${c.max_value}`
    if (v < 0) err = 'Cannot be negative'
  }
  if (c.data_type === 'PERCENT' && typeof v === 'number' && (v < 0 || v > 100)) err = 'Must be 0-100'
  if (err) errors[c.column_key] = err
  else delete errors[c.column_key]
  return !err
}

function validatePeriod() {
  let ok = true
  if (!form.period_start) { errors.period_start = 'Required'; ok = false } else delete errors.period_start
  if (!form.period_end)   { errors.period_end   = 'Required'; ok = false } else delete errors.period_end
  if (form.period_start && form.period_end && new Date(form.period_start) > new Date(form.period_end)) {
    errors.period_end = 'Must be on or after the start date'; ok = false
  }
  return ok
}

function validateAllFields() {
  let ok = true
  for (const c of columns.value) { if (!validateField(c)) ok = false }
  return ok
}

// ── Auto-calculate (daily/weekly only) ─────────────────────────────────────
async function autoCalculate() {
  if (!auth.value?.poe_code) return
  calculating.value = true; autoMsg.value = ''
  try {
    const start = form.period_start + ' 00:00:00'
    const end   = form.period_end   + ' 23:59:59'
    const records = await dbGetByIndex(STORE.PRIMARY_SCREENINGS, 'poe_code', auth.value.poe_code)
    const inRange = records.filter(r =>
      r.captured_at >= start && r.captured_at <= end &&
      r.record_status === 'COMPLETED' && !r.deleted_at,
    )
    // Populate any known-key columns we have
    const counts = {
      total_screened:     inRange.length,
      total_male:         inRange.filter(r => r.gender === 'MALE').length,
      total_female:       inRange.filter(r => r.gender === 'FEMALE').length,
      total_symptomatic:  inRange.filter(r => r.symptoms_present === 1).length,
      total_asymptomatic: inRange.filter(r => r.symptoms_present === 0).length,
      fever_above_38:     inRange.filter(r => r.symptoms_present === 1 && Number(r.temperature_value || 0) >= 38).length,
    }
    for (const [k, v] of Object.entries(counts)) {
      if (values[k] !== undefined) values[k] = v
    }
    autoMsg.value = `Pre-filled from ${inRange.length} local screening record${inRange.length === 1 ? '' : 's'}.`
  } catch (e) {
    autoMsg.value = `Couldn't pre-fill: ${e.message}`
  } finally {
    calculating.value = false
  }
}

// ── Navigation ─────────────────────────────────────────────────────────────
function goStep(k) {
  const target = STEPS.findIndex(s => s.k === k)
  if (target < stepIndex.value) step.value = k  // only jump backwards by click
}
function goBack() { if (stepIndex.value > 0) step.value = STEPS[stepIndex.value - 1].k }

function onNext() {
  if (step.value === 'period') {
    if (!validatePeriod()) return
    step.value = 'fill'
  } else if (step.value === 'fill') {
    if (!validateAllFields()) {
      showToast('Fix the highlighted fields', 'danger')
      return
    }
    step.value = 'notes'
  } else if (step.value === 'notes') {
    step.value = 'review'
  } else if (step.value === 'review') {
    submit()
  }
}

// ── Submit — offline-first ─────────────────────────────────────────────────
async function submit() {
  submitError.value = ''
  if (!validatePeriod() || !validateAllFields()) {
    submitError.value = 'Some fields need attention'; return
  }
  const a = getAuth()
  if (!a?.id) { submitError.value = 'Session expired. Log in again.'; return }

  submitting.value = true
  try {
    // Build the fixed-schema portion (for back-compat with dashboards on
    // aggregated_submissions) + template_values array (for everything else)
    const templateValues = []
    for (const c of columns.value) {
      const v = values[c.column_key]
      if (v === null || v === undefined || v === '') continue
      templateValues.push({
        template_id:        template.value.id,
        template_column_id: c.id,
        column_key:         c.column_key,
        data_type:          c.data_type,
        value_numeric:      ['INTEGER', 'DECIMAL', 'PERCENT'].includes(c.data_type) ? Number(v) : null,
        value_text:         ['TEXT', 'DATE', 'SELECT'].includes(c.data_type) ? String(v) : null,
        value_boolean:      c.data_type === 'BOOLEAN' ? !!v : null,
      })
    }

    const record = createRecordBase(a, {
      submitted_by_user_id: a.id,
      period_start:         form.period_start + ' 00:00:00',
      period_end:           form.period_end   + ' 23:59:59',
      // Legacy fixed fields (back-compat with existing aggregated_submissions columns)
      total_screened:     Number(values.total_screened || 0),
      total_male:         Number(values.total_male     || 0),
      total_female:       Number(values.total_female   || 0),
      // total_other + total_unknown_gender retired 2026-04-21 — always 0
      // for backward-compat with the fixed aggregated_submissions columns.
      total_other:          0,
      total_unknown_gender: 0,
      total_symptomatic:  Number(values.total_symptomatic    || 0),
      total_asymptomatic: Number(values.total_asymptomatic   || 0),
      notes:                form.notes?.trim() || null,
      // Template context
      template_id:          template.value.id,
      template_code:        template.value.template_code,
      template_version:     template.value.version,
      template_values:      templateValues,
      // Local flags
      is_local_draft:       false,
    })

    await dbPut(STORE.AGGREGATED_SUBMISSIONS, record)
    syncStatus.value = SYNC.UNSYNCED
    submitted.value = true
  } catch (e) {
    submitError.value = `Couldn't save: ${e.message}`
  } finally {
    submitting.value = false
  }
}

function startNew() {
  submitted.value = false
  step.value = 'period'
  for (const k of Object.keys(values)) delete values[k]
  initValues()
  form.period_start = todayStr
  form.period_end = todayStr
  form.notes = ''
}

// ── Helpers ────────────────────────────────────────────────────────────────
function freqLabel(f) { return { DAILY: 'Daily', WEEKLY: 'Weekly', MONTHLY: 'Monthly', QUARTERLY: 'Quarterly', AD_HOC: 'Ad-hoc', EVENT: 'Event-based' }[f] || 'Report' }
function fmtValue(c) {
  const v = values[c.column_key]
  if (c.data_type === 'BOOLEAN') return v ? 'Yes' : 'No'
  if (['INTEGER', 'DECIMAL', 'PERCENT'].includes(c.data_type)) return Number(v).toLocaleString()
  return v || '—'
}
function showToast(msg, color = 'success') { toast.show = true; toast.msg = msg; toast.color = color }

// ── Lifecycle ──────────────────────────────────────────────────────────────
onMounted(loadTemplate)
onIonViewDidEnter(() => { auth.value = getAuth(); loadTemplate() })

// React to route param changes (if navigating between templates within the SPA)
watch(() => route.params.templateId, (nv, ov) => { if (nv && nv !== ov) loadTemplate() })
</script>

<style scoped>
* { box-sizing: border-box }

/* Header */
.ad-hdr { --background: transparent; border: none }
.ad-hdr-bg { background: linear-gradient(135deg, var(--accent), #001D3D 140%); padding: 0 0 10px }
.ad-hdr-top { display: flex; align-items: center; gap: 4px; padding: 8px 8px 0 }
.ad-back { --color: rgba(255,255,255,.85) }
.ad-hdr-title { flex: 1; display: flex; flex-direction: column; min-width: 0 }
.ad-hdr-eye { font-size: 9px; font-weight: 700; color: rgba(255,255,255,.55); text-transform: uppercase; letter-spacing: 1.4px }
.ad-hdr-h1 { font-size: 17px; font-weight: 800; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
.ad-sync { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 99px; font-size: 10px; font-weight: 700; margin-right: 8px; letter-spacing: .3px }
.ad-sync--ok { background: rgba(16,185,129,.2); color: #6EE7B7 }
.ad-sync--pending { background: rgba(234,88,12,.24); color: #FDBA74 }
.ad-sync-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; box-shadow: 0 0 8px currentColor }

.ad-steps { display: flex; gap: 4px; padding: 10px 10px 0; overflow-x: auto; scrollbar-width: none }
.ad-steps::-webkit-scrollbar { display: none }
.ad-step { flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 99px; background: rgba(255,255,255,.08); color: rgba(255,255,255,.7); cursor: pointer; font-size: 11px; font-weight: 700 }
.ad-step-n { width: 18px; height: 18px; border-radius: 50%; background: rgba(255,255,255,.15); font-size: 10px; font-weight: 900; display: inline-flex; align-items: center; justify-content: center }
.ad-step--on { background: #fff; color: #1A3A5C }
.ad-step--on .ad-step-n { background: var(--accent); color: #fff }
.ad-step--done { color: rgba(255,255,255,.85) }
.ad-step--done .ad-step-n { background: rgba(16,185,129,.4); color: #fff }

/* Content */
.ad-content { --background: #F0F4FA }
.ad-loading { padding: 80px 20px; display: flex; flex-direction: column; align-items: center; gap: 12px; color: #64748B; font-size: 12px }
.ad-spinner { width: 28px; height: 28px; border: 3px solid #E2E8F0; border-top-color: #1E40AF; border-radius: 50%; animation: ad-spin 1s linear infinite }
@keyframes ad-spin { to { transform: rotate(360deg) } }
.ad-err { padding: 60px 24px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 10px }
.ad-err-ic { width: 48px; height: 48px; border-radius: 50%; background: #FEE2E2; color: #991B1B; font-size: 24px; font-weight: 900; display: flex; align-items: center; justify-content: center }
.ad-err-t { font-size: 16px; font-weight: 800; color: #1A3A5C }
.ad-err-s { font-size: 12px; color: #64748B; line-height: 1.5; max-width: 300px }
.ad-err-btn { margin-top: 8px; padding: 10px 18px; border-radius: 10px; border: 1px solid #CBD5E1; background: #fff; color: #475569; font-size: 12px; font-weight: 700; cursor: pointer }

.ad-form { padding: 14px 10px 0 }
.ad-sec { background: #fff; border: 1px solid #E8EDF5; border-radius: 14px; padding: 16px; animation: ad-slide .25s ease-out }
@keyframes ad-slide { from { opacity: 0; transform: translateY(8px) } to { opacity: 1; transform: translateY(0) } }
.ad-intro { font-size: 12.5px; color: #475569; margin: 0 0 14px; line-height: 1.5 }

/* Period */
.ad-period-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px }
.ad-f { display: flex; flex-direction: column; gap: 4px; min-width: 0 }
.ad-lbl { font-size: 11px; font-weight: 800; color: #1A3A5C; letter-spacing: .2px; display: flex; flex-direction: column; gap: 2px }
.ad-req { color: #DC2626 }
.ad-help { font-size: 10px; color: #64748B; font-weight: 500; line-height: 1.35; text-transform: none; letter-spacing: 0 }
.ad-inp {
  padding: 10px 12px; border: 1.5px solid #E8EDF5; border-radius: 8px; font-size: 13px;
  color: #1A3A5C; background: #fff; font-family: inherit; outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.ad-inp:focus { border-color: var(--accent, #1E40AF); box-shadow: 0 0 0 3px rgba(30,64,175,.12) }
.ad-inp--err { border-color: #DC2626; background: #FEF2F2 }
.ad-inp--ta { resize: vertical; min-height: 90px }
.ad-err-line { font-size: 10.5px; color: #DC2626; font-weight: 700 }
.ad-hint { font-size: 11px; color: #64748B; margin-top: 6px; font-weight: 600 }
.ad-warn { font-size: 11px; color: #9A3412; background: #FFEDD5; border: 1px solid #FED7AA; border-radius: 6px; padding: 8px 10px; margin-top: 8px; line-height: 1.4 }
.ad-calc {
  display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; border-radius: 10px;
  background: linear-gradient(135deg, rgba(30,64,175,.08), rgba(30,64,175,.04));
  border: 1px dashed #BFDBFE; color: #1E40AF; font-size: 12px; font-weight: 800; cursor: pointer;
  margin-top: 12px;
}
.ad-calc:disabled { opacity: .5 }

/* Grouped fields */
.ad-group { margin-top: 14px; padding-top: 14px; border-top: 1px dashed #E8EDF5 }
.ad-group:first-of-type { margin-top: 0; padding-top: 0; border-top: none }
.ad-group-h { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px }
.ad-group-pill { font-size: 9.5px; font-weight: 900; padding: 3px 9px; border-radius: 4px; background: #F1F5F9; color: #475569; letter-spacing: .4px }
.ad-group-pill--core { background: #DBEAFE; color: #1E40AF }
.ad-group-pill--gender { background: #FCE7F3; color: #9D174D }
.ad-group-pill--symptoms, .ad-group-pill--disease { background: #FEE2E2; color: #991B1B }
.ad-group-pill--outcomes { background: #D1FAE5; color: #047857 }
.ad-group-pill--age { background: #EDE9FE; color: #6D28D9 }
.ad-group-pill--travel { background: #DCFCE7; color: #166534 }
.ad-group-pill--vaccine { background: #FEF3C7; color: #854D0E }
.ad-group-pill--lab { background: #E0E7FF; color: #3730A3 }
.ad-group-pill--quality { background: #E0F2FE; color: #0369A1 }
.ad-group-n { font-size: 10px; color: #94A3B8; font-weight: 700 }
.ad-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px }
@media (max-width: 380px) { .ad-fields { grid-template-columns: 1fr } }
.ad-bool { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border: 1.5px solid #E8EDF5; border-radius: 8px; cursor: pointer; font-size: 13px; color: #1A3A5C; background: #fff }
.ad-bool input { width: 18px; height: 18px; cursor: pointer; accent-color: var(--accent, #1E40AF) }

.ad-sums { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px }
.ad-sum { font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 6px }
.ad-sum--ok { background: #D1FAE5; color: #047857 }
.ad-sum--bad { background: #FFEDD5; color: #9A3412 }

/* Review */
.ad-rev-card { background: #F8FAFC; border: 1px solid #E8EDF5; border-radius: 10px; padding: 12px; margin-bottom: 14px; display: flex; flex-direction: column; gap: 8px }
.ad-rev-hdr, .ad-rev-row, .ad-rev-val { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start }
.ad-rev-hdr { padding-bottom: 8px; border-bottom: 1px solid #E8EDF5 }
.ad-rev-k { font-size: 10.5px; color: #64748B; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; flex-shrink: 0 }
.ad-rev-v { font-size: 12.5px; color: #1A3A5C; font-weight: 700; text-align: right; word-break: break-word }
.ad-rev-sh { font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: 1px; margin: 14px 0 8px }
.ad-rev-vals { background: #fff; border: 1px solid #E8EDF5; border-radius: 10px; padding: 4px 12px }
.ad-rev-val { padding: 8px 0; border-bottom: 1px solid #F0F4FA }
.ad-rev-val:last-child { border-bottom: none }
.ad-none { padding: 14px 4px; color: #94A3B8; font-size: 11.5px; font-style: italic; text-align: center }
.ad-submit-err { margin-top: 10px; padding: 8px 10px; background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B; border-radius: 6px; font-size: 11.5px; font-weight: 600 }

/* Nav */
.ad-nav { display: flex; gap: 10px; padding: 14px 4px 0; position: sticky; bottom: 0; background: linear-gradient(180deg, transparent, #F0F4FA 30%); padding-bottom: 14px }
.ad-nav-btn { flex: 1; padding: 13px; border-radius: 10px; border: none; font-size: 13.5px; font-weight: 800; cursor: pointer; transition: transform .1s }
.ad-nav-btn:active { transform: scale(.97) }
.ad-nav-btn:disabled { opacity: .45; cursor: not-allowed }
.ad-nav-btn--ghost { background: #fff; color: #475569; border: 1px solid #CBD5E1 }
.ad-nav-btn--primary { background: var(--accent, #1E40AF); color: #fff; box-shadow: 0 4px 14px rgba(30,64,175,.25) }

/* Done */
.ad-done { padding: 60px 20px; display: flex; flex-direction: column; align-items: center; gap: 10px; text-align: center }
.ad-done-check { color: #10B981; margin-bottom: 6px }
.ad-done-circle { stroke-dasharray: 176; stroke-dashoffset: 176; animation: ad-circle 1s ease-out .1s forwards }
.ad-done-tick { stroke-dasharray: 30; stroke-dashoffset: 30; animation: ad-tick .4s ease-out .8s forwards }
@keyframes ad-circle { to { stroke-dashoffset: 0 } }
@keyframes ad-tick   { to { stroke-dashoffset: 0 } }
.ad-done-t { font-size: 20px; font-weight: 800; color: #1A3A5C }
.ad-done-s { font-size: 12.5px; color: #64748B; max-width: 280px; line-height: 1.5 }
.ad-done-acts { display: flex; gap: 10px; margin-top: 18px }
.ad-done-btn { padding: 10px 18px; border-radius: 10px; border: 1px solid #CBD5E1; background: #fff; color: #475569; font-size: 12.5px; font-weight: 800; cursor: pointer }
.ad-done-btn--primary { background: #1E40AF; color: #fff; border-color: #1E40AF }

@media (min-width: 540px) { .ad-form { max-width: 540px; margin: 0 auto } }
</style>
