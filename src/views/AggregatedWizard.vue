<template>
  <IonPage>
    <!-- ═══ HEADER ═══════════════════════════════════════════════════ -->
    <IonHeader class="aw-hdr" translucent>
      <div class="aw-hdr-bg">
        <div class="aw-hdr-top">
          <IonButtons slot="start"><IonBackButton default-href="/aggregated-data" class="aw-back"/></IonButtons>
          <div class="aw-hdr-title">
            <span class="aw-hdr-eye">Admin · {{ auth.country_code || TENANT_CC }}</span>
            <span class="aw-hdr-h1">{{ form.id ? 'Edit report template' : 'New report template' }}</span>
          </div>
          <IonButtons slot="end">
            <button class="aw-close-btn" @click="confirmDiscard">Exit</button>
          </IonButtons>
        </div>

        <!-- Step progress -->
        <div class="aw-steps">
          <div v-for="(st, i) in STEPS" :key="st.k"
            :class="['aw-step', step === st.k && 'aw-step--on', stepIndex > i && 'aw-step--done']"
            @click="goStep(st.k)">
            <span class="aw-step-n">{{ i + 1 }}</span>
            <span class="aw-step-l">{{ st.l }}</span>
          </div>
        </div>
      </div>
    </IonHeader>

    <IonContent class="aw-content" :fullscreen="true">
      <!-- Guard: only NATIONAL_ADMIN -->
      <div v-if="!isAdmin" class="aw-guard">
        <div class="aw-guard-ic">🔒</div>
        <div class="aw-guard-t">NATIONAL_ADMIN only</div>
        <div class="aw-guard-s">Templates shape the country-wide dashboards. Only national administrators may create or edit them.</div>
      </div>

      <template v-else>
        <!-- ══ AI ASSISTANT PANEL ══════════════════════════════════════════
             Hardcoded, offline, deterministic hints sourced from WHO AFRO
             IDSR best practice + 7-1-7 framework. Updates as the form
             changes. Collapsible so it doesn't crowd small screens. -->
        <aside v-if="aiHints.length > 0" :class="['aw-ai', aiCollapsed && 'aw-ai--closed']">
          <button type="button" class="aw-ai-hdr" @click="aiCollapsed = !aiCollapsed" :aria-expanded="!aiCollapsed">
            <span class="aw-ai-badge">AI</span>
            <span class="aw-ai-t">{{ aiHints.length }} suggestion{{ aiHints.length === 1 ? '' : 's' }} for this step</span>
            <span class="aw-ai-chev" :class="!aiCollapsed && 'aw-ai-chev--open'">▾</span>
          </button>
          <div v-if="!aiCollapsed" class="aw-ai-body">
            <div v-for="(h, i) in aiHints" :key="i" :class="['aw-ai-row', 'aw-ai-row--'+h.level]">
              <span class="aw-ai-ic">{{ h.ic }}</span>
              <div class="aw-ai-content">
                <span class="aw-ai-head">{{ h.t }}</span>
                <span class="aw-ai-msg">{{ h.body }}</span>
              </div>
            </div>
          </div>
        </aside>

        <!-- ── STEP 1: PURPOSE ── -->
        <section v-if="step === 'purpose'" class="aw-sec">
          <h3 class="aw-sh">What is this report for?</h3>
          <p class="aw-p">Every POE user will see this name. Keep it short and specific.</p>

          <div class="aw-f">
            <label class="aw-lbl">Report name *</label>
            <input v-model.trim="form.template_name" class="aw-inp" placeholder="e.g. Weekly VHF Surveillance" maxlength="120" @input="autoCode"/>
            <div v-if="errors.template_name" class="aw-err">{{ errors.template_name }}</div>
          </div>

          <div class="aw-f">
            <label class="aw-lbl">Code * <span class="aw-help">UPPERCASE_WITH_UNDERSCORES — auto-generated from the name, edit if needed.</span></label>
            <input v-model.trim="form.template_code" class="aw-inp aw-mono" placeholder="WEEKLY_VHF_V1" maxlength="60" @input="codeEdited = true"/>
            <div v-if="errors.template_code" class="aw-err">{{ errors.template_code }}</div>
          </div>

          <div class="aw-f">
            <label class="aw-lbl">Description</label>
            <textarea v-model.trim="form.description" class="aw-inp aw-inp--ta" rows="3" maxlength="500" placeholder="What does this report capture? Who submits it?"/>
          </div>

          <div class="aw-f">
            <label class="aw-lbl">Reporting frequency *</label>
            <div class="aw-freq-grid">
              <button v-for="f in FREQS" :key="f.v" type="button"
                :class="['aw-freq', form.reporting_frequency === f.v && 'aw-freq--on']"
                @click="form.reporting_frequency = f.v">
                <span class="aw-freq-ic">{{ f.ic }}</span>
                <span class="aw-freq-t">{{ f.l }}</span>
                <span class="aw-freq-s">{{ f.s }}</span>
              </button>
            </div>
          </div>

          <div class="aw-f">
            <label class="aw-lbl">Accent colour</label>
            <div class="aw-colours">
              <button v-for="c in COLOURS" :key="c" type="button"
                :class="['aw-colour', form.colour === c && 'aw-colour--on']"
                :style="{ background: c }"
                @click="form.colour = c" aria-label="Select colour"/>
            </div>
          </div>
        </section>

        <!-- ── STEP 2: START FROM ── -->
        <section v-if="step === 'start'" class="aw-sec">
          <h3 class="aw-sh">Start with</h3>
          <p class="aw-p">Pick a starting point. You can add, remove or toggle columns in the next step.</p>

          <div class="aw-preset-grid">
            <button v-for="p in PRESETS" :key="p.k" type="button"
              :class="['aw-preset', form.preset === p.k && 'aw-preset--on']"
              @click="pickPreset(p.k)">
              <span class="aw-preset-ic">{{ p.ic }}</span>
              <div class="aw-preset-body">
                <span class="aw-preset-t">{{ p.t }}</span>
                <span class="aw-preset-s">{{ p.s }}</span>
                <span v-if="p.cols" class="aw-preset-n">{{ p.cols }} columns</span>
              </div>
              <span v-if="form.preset === p.k" class="aw-preset-check">✓</span>
            </button>
          </div>

          <div v-if="loadingPreset" class="aw-hint">Preparing columns…</div>
        </section>

        <!-- ── STEP 3: COLUMNS ── -->
        <section v-if="step === 'columns'" class="aw-sec">
          <h3 class="aw-sh">Columns</h3>
          <p class="aw-p">Toggle columns on/off. Core columns are required — they cannot be removed. <strong>{{ enabledCount }}</strong> of {{ workingCols.length }} enabled.</p>

          <div class="aw-col-tools">
            <input v-model="colSearch" class="aw-inp aw-inp--sm" placeholder="🔍 Search columns…"/>
            <button type="button" class="aw-btn aw-btn--ghost" @click="openAddCol">+ Add custom</button>
          </div>

          <div class="aw-col-groups">
            <div v-for="g in groupedCols" :key="g.category" class="aw-col-group">
              <header class="aw-col-gh">
                <span :class="['aw-col-gp', `aw-col-gp--${g.category.toLowerCase()}`]">{{ g.category }}</span>
                <span class="aw-col-gn">{{ g.cols.filter(c => c.is_enabled).length }} / {{ g.cols.length }}</span>
              </header>
              <article v-for="c in g.cols" :key="c.column_key"
                :class="['aw-col', c.is_enabled && 'aw-col--on', c.is_core && 'aw-col--core']">
                <label class="aw-col-toggle">
                  <input v-if="!c.is_core" type="checkbox" v-model="c.is_enabled"/>
                  <span v-if="c.is_core" class="aw-col-core-badge">CORE</span>
                  <span v-else class="aw-col-slider"/>
                </label>
                <div class="aw-col-body">
                  <div class="aw-col-name">{{ c.column_label }}</div>
                  <div class="aw-col-meta">
                    <span class="aw-col-k">{{ c.column_key }}</span>
                    <span class="aw-col-t">{{ c.data_type }}</span>
                    <span v-if="c.is_required" class="aw-col-req">Required</span>
                  </div>
                </div>
                <button v-if="c._local_new" type="button" class="aw-col-del" @click="removeLocalCol(c)">×</button>
              </article>
            </div>
          </div>
        </section>

        <!-- ── STEP 4: VALIDATION ── -->
        <section v-if="step === 'validation'" class="aw-sec">
          <h3 class="aw-sh">Validation & rules</h3>
          <p class="aw-p">Which enabled numeric fields need limits? Leave min/max blank for no limit.</p>

          <div class="aw-val-grid">
            <article v-for="c in numericEnabled" :key="c.column_key" class="aw-val-card">
              <div class="aw-val-hdr">
                <span class="aw-val-name">{{ c.column_label }}</span>
                <span class="aw-val-type">{{ c.data_type }}</span>
              </div>
              <div class="aw-val-row">
                <label class="aw-val-f">
                  <span>Min</span>
                  <input type="number" v-model.number="c.min_value" class="aw-inp aw-inp--sm" placeholder="—"/>
                </label>
                <label class="aw-val-f">
                  <span>Max</span>
                  <input type="number" v-model.number="c.max_value" class="aw-inp aw-inp--sm" placeholder="—"/>
                </label>
                <label class="aw-val-f aw-val-req">
                  <input type="checkbox" v-model="c.is_required" :disabled="c.is_core"/>
                  <span>Required</span>
                </label>
              </div>
            </article>
          </div>
          <div v-if="numericEnabled.length === 0" class="aw-hint">No numeric fields enabled.</div>
        </section>

        <!-- ── STEP 5: PUBLISH ── -->
        <section v-if="step === 'publish'" class="aw-sec">
          <h3 class="aw-sh">Preview & publish</h3>
          <p class="aw-p">This is exactly what POE users will see. Publishing makes the template visible instantly at every POE in {{ auth.country_code }}.</p>

          <!-- Preview card -->
          <div class="aw-prev">
            <div class="aw-prev-stripe" :style="{ background: form.colour }"/>
            <div class="aw-prev-body">
              <div class="aw-prev-badges">
                <span class="aw-prev-badge">{{ freqLabel(form.reporting_frequency) }}</span>
                <span class="aw-prev-badge aw-prev-badge--code">{{ form.template_code || 'CODE' }}</span>
              </div>
              <h4 class="aw-prev-title">{{ form.template_name || 'Untitled report' }}</h4>
              <p v-if="form.description" class="aw-prev-desc">{{ form.description }}</p>
              <div class="aw-prev-meta">
                <span>📋 {{ enabledCount }} fields</span>
                <span>📐 v1</span>
              </div>
            </div>
          </div>

          <!-- Field rundown -->
          <h4 class="aw-sub">Fields ({{ enabledCount }})</h4>
          <div class="aw-field-list">
            <div v-for="c in enabledWorking" :key="c.column_key" class="aw-field-pill">
              <span class="aw-field-pill-l">{{ c.column_label }}</span>
              <span class="aw-field-pill-t">{{ c.data_type }}</span>
              <span v-if="c.is_required" class="aw-field-pill-r">*</span>
            </div>
          </div>

          <div v-if="submitError" class="aw-submit-err">{{ submitError }}</div>

          <div class="aw-publish-opts">
            <button type="button" class="aw-big-btn aw-big-btn--draft" :disabled="saving" @click="save(false)">
              Save as draft
            </button>
            <button type="button" class="aw-big-btn aw-big-btn--publish" :disabled="saving" @click="save(true)">
              {{ saving ? 'Publishing…' : '🚀 Publish to all POEs' }}
            </button>
          </div>

          <p class="aw-legal">Publishing is non-destructive — you can retire the template later and re-publish. Historical submissions are never altered.</p>
        </section>

        <!-- ── NAVIGATION ── -->
        <div class="aw-nav">
          <button type="button" class="aw-nav-btn aw-nav-btn--ghost" :disabled="stepIndex === 0 || saving" @click="goBack">Back</button>
          <button v-if="step !== 'publish'" type="button" class="aw-nav-btn aw-nav-btn--primary" @click="onNext">Next</button>
        </div>
      </template>

      <div style="height:48px"/>
    </IonContent>

    <!-- Add column modal -->
    <IonModal :is-open="showAddCol" @didDismiss="showAddCol = false" :initial-breakpoint="0.85" :breakpoints="[0, 0.85]">
      <IonContent class="aw-modal">
        <div class="aw-mp">
          <h3 class="aw-mp-t">Add custom column</h3>
          <p class="aw-mp-s">This column is added to this template only. Once submissions exist, the key can't change.</p>
          <label class="aw-lbl">Key</label>
          <input v-model.trim="newCol.column_key" class="aw-inp aw-mono" placeholder="e.g. suspected_anthrax"/>
          <label class="aw-lbl">Label</label>
          <input v-model.trim="newCol.column_label" class="aw-inp" placeholder="Suspected anthrax"/>
          <div class="aw-modal-row">
            <div class="aw-modal-half">
              <label class="aw-lbl">Category</label>
              <select v-model="newCol.category" class="aw-inp">
                <option v-for="c in CATEGORIES" :key="c">{{ c }}</option>
              </select>
            </div>
            <div class="aw-modal-half">
              <label class="aw-lbl">Type</label>
              <select v-model="newCol.data_type" class="aw-inp">
                <option v-for="d in DATA_TYPES" :key="d">{{ d }}</option>
              </select>
            </div>
          </div>
          <label class="aw-lbl">Help text (optional)</label>
          <input v-model.trim="newCol.help_text" class="aw-inp" placeholder="Shown under the field"/>
          <div v-if="newColErr" class="aw-err">{{ newColErr }}</div>
          <div class="aw-modal-acts">
            <button class="aw-nav-btn aw-nav-btn--ghost" @click="showAddCol = false">Cancel</button>
            <button class="aw-nav-btn aw-nav-btn--primary" @click="confirmAddCol">Add column</button>
          </div>
        </div>
      </IonContent>
    </IonModal>

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="3000" position="top" @didDismiss="toast.show=false"/>
  </IonPage>
</template>

<script setup>
import {
  IonPage, IonHeader, IonContent, IonButtons, IonBackButton,
  IonModal, IonToast, onIonViewDidEnter,
} from '@ionic/vue'
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()

function getAuth() { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {} }
const auth = ref(getAuth())
// Tenant country — single source of truth from main.ts (window.COUNTRY_CODE
// driven by VITE_COUNTRY_CODE). Replaces every hardcoded fork-residue 'UG' fallback.
const TENANT_CC = (typeof window !== 'undefined' && window.COUNTRY_CODE) || 'UG'
const isAdmin = computed(() => (auth.value?.role_key || '') === 'NATIONAL_ADMIN')

// Steps
const STEPS = [
  { k: 'purpose',    l: 'Purpose' },
  { k: 'start',      l: 'Start from' },
  { k: 'columns',    l: 'Columns' },
  { k: 'validation', l: 'Rules' },
  { k: 'publish',    l: 'Publish' },
]
const step = ref('purpose')
const stepIndex = computed(() => STEPS.findIndex(s => s.k === step.value))
const aiCollapsed = ref(false)  // expanded by default on first step; closes when user dismisses

// ── AI guidance (offline, deterministic) ─────────────────────────────────
// Every hint is a pure function of the current form state — no LLM call, no
// network. The goal is a smart-looking assistant that actually encodes WHO
// AFRO IDSR / 7-1-7 best practice and catches common admin mistakes.
const aiHints = computed(() => {
  const hints = []
  const f = form
  const name = (f.template_name || '').toLowerCase()
  const desc = (f.description || '').toLowerCase()

  // ── Step: Purpose ──
  if (step.value === 'purpose') {
    if (!f.template_name) {
      hints.push({ level: 'tip', ic: '✨', t: 'Name your report',
        body: 'Start with what the report measures (e.g. "Weekly VHF surveillance"). POE users will see this exact name on their card — keep it short and specific.' })
    } else if (f.template_name.length < 6) {
      hints.push({ level: 'warn', ic: '!', t: 'Name looks short',
        body: 'Aim for 3-6 words so POE users know exactly what they\'re submitting.' })
    }
    // Suggest frequency based on keywords
    if (/outbreak|sitrep|event|cluster/.test(name + ' ' + desc)) {
      if (f.reporting_frequency !== 'EVENT' && f.reporting_frequency !== 'AD_HOC') {
        hints.push({ level: 'insight', ic: '💡', t: 'Consider EVENT frequency',
          body: 'The name sounds like an outbreak/sitrep report — EVENT or AD_HOC usually fits better than a fixed cadence.' })
      }
    }
    if (/tally|count|daily|check/.test(name)) {
      if (f.reporting_frequency === 'WEEKLY' || f.reporting_frequency === 'MONTHLY') {
        hints.push({ level: 'insight', ic: '💡', t: 'Consider DAILY frequency',
          body: 'Short-cycle tally reports are usually submitted daily. Weekly cadence risks stale operational data.' })
      }
    }
    if (/vhf|ebola|marburg|cholera|polio|meningit|mpox|yellow.?fever/.test(name + ' ' + desc)) {
      hints.push({ level: 'info', ic: 'ℹ️', t: 'IHR Tier 1/2 disease detected',
        body: 'This looks disease-specific. Consider enabling IHR-aligned columns (lab samples, isolation, WHO notification flag) in the Columns step.' })
    }
    if (!f.description || f.description.length < 20) {
      hints.push({ level: 'tip', ic: '📝', t: 'Add a description',
        body: 'A one-sentence description tells POE users what context to capture. It also surfaces in dashboards.' })
    }
  }

  // ── Step: Start from ──
  else if (step.value === 'start') {
    if (f.reporting_frequency === 'DAILY') {
      hints.push({ level: 'insight', ic: '💡', t: 'Start with Daily Tally',
        body: 'DAILY reports work best with a minimal column set (5-9 fields). The Daily Tally preset is purpose-built for this cadence.' })
    } else if (f.reporting_frequency === 'WEEKLY' && !f.preset) {
      hints.push({ level: 'insight', ic: '💡', t: 'Start with WHO AFRO baseline',
        body: 'Weekly IDSR-aligned reports match the full WHO AFRO 57-column baseline out of the box. Toggle off what you don\'t need in the next step.' })
    } else if (f.reporting_frequency === 'EVENT') {
      hints.push({ level: 'insight', ic: '💡', t: 'Clone an existing sitrep template',
        body: 'Event/outbreak reports reuse most columns between templates. Cloning is faster than building from scratch.' })
    }
  }

  // ── Step: Columns ──
  else if (step.value === 'columns') {
    const enabled = workingCols.value.filter(c => c.is_enabled)
    if (enabled.length < 3) {
      hints.push({ level: 'warn', ic: '!', t: 'Too few enabled columns',
        body: `Only ${enabled.length} enabled — WHO baseline for any aggregated report is ≥ 5 (total screened, gender, symptomatic).` })
    }
    if (enabled.length > 40) {
      hints.push({ level: 'warn', ic: '!', t: 'Lots of columns enabled',
        body: `${enabled.length} enabled — long forms discourage submission. If this is meant for a busy POE user, prune to essentials.` })
    }
    // Cadence-specific recommendations
    if (f.reporting_frequency === 'DAILY' && enabled.length > 15) {
      hints.push({ level: 'insight', ic: '💡', t: 'Daily forms should be short',
        body: 'Aim for ≤ 15 columns for daily cadence so POE users can complete in under 2 minutes.' })
    }
    // IHR recommendations
    const hasFever = enabled.some(c => c.column_key === 'fever_above_38')
    const hasIll   = enabled.some(c => c.column_key === 'ill_travellers_detected')
    if (!hasFever && (f.reporting_frequency === 'DAILY' || f.reporting_frequency === 'WEEKLY')) {
      hints.push({ level: 'tip', ic: '🌡️', t: 'Consider enabling "Fever ≥ 38 °C"',
        body: 'A near-universal IDSR indicator — your dashboards will expect it.' })
    }
    if (!hasIll && f.reporting_frequency !== 'EVENT') {
      hints.push({ level: 'tip', ic: '🚑', t: 'Enable "Ill travellers detected"',
        body: 'The denominator for all outcomes (isolation, referral, contact tracing) — enable it unless this report is syndrome-only.' })
    }
    // Sum validators
    const hasMale   = enabled.some(c => c.column_key === 'total_male')
    const hasFemale = enabled.some(c => c.column_key === 'total_female')
    if ((hasMale && !hasFemale) || (!hasMale && hasFemale)) {
      hints.push({ level: 'warn', ic: '!', t: 'Gender breakdown is incomplete',
        body: 'Enable BOTH total_male and total_female so the submission wizard can validate the gender sum.' })
    }
  }

  // ── Step: Validation ──
  else if (step.value === 'validation') {
    const hasAnyMinMax = numericEnabled.value.some(c => c.min_value != null || c.max_value != null)
    if (!hasAnyMinMax && numericEnabled.value.length > 0) {
      hints.push({ level: 'tip', ic: '🔒', t: 'Add at least one max limit',
        body: 'Max limits catch data entry typos (e.g. "1000000" for 1,000). Set max_value on the top 3 highest-signal fields.' })
    }
    const requiredCnt = numericEnabled.value.filter(c => c.is_required).length
    if (requiredCnt < 2) {
      hints.push({ level: 'tip', ic: '✨', t: 'Mark at least 2 fields required',
        body: 'Required fields force completeness. Core columns are implicitly required — but consider marking domain-specific ones too.' })
    }
  }

  // ── Step: Publish ──
  else if (step.value === 'publish') {
    if (enabledCount.value < 3) {
      hints.push({ level: 'warn', ic: '!', t: 'Not enough columns to publish',
        body: `Server requires at least 1 enabled column — 3+ is the sensible minimum for a useful report.` })
    }
    hints.push({ level: 'info', ic: '📡', t: 'Publishing propagates instantly',
      body: 'Every POE in your country will see this template within 30 seconds. You can always retire it later — historical submissions are preserved.' })
    if (f.preset === 'CLONE') {
      hints.push({ level: 'info', ic: 'ℹ️', t: 'This is a clone',
        body: 'You started from an existing template — the original is untouched. Any changes you made here only apply to this new template.' })
    }
  }

  return hints
})

const FREQS = [
  { v: 'DAILY',     l: 'Daily',     ic: '📅', s: 'End-of-day' },
  { v: 'WEEKLY',    l: 'Weekly',    ic: '📆', s: 'By Monday' },
  { v: 'MONTHLY',   l: 'Monthly',   ic: '🗓️', s: 'Month close' },
  { v: 'QUARTERLY', l: 'Quarterly', ic: '📊', s: 'Every quarter' },
  { v: 'AD_HOC',    l: 'Ad-hoc',    ic: '⚡', s: 'On demand' },
  { v: 'EVENT',     l: 'Event',     ic: '🚨', s: 'Outbreak/sitrep' },
]

const COLOURS = ['#1E40AF', '#059669', '#DC2626', '#9333EA', '#CA8A04', '#0F172A', '#EA580C', '#0284C7']

const PRESETS = [
  { k: 'BLANK',   t: 'Blank',                       s: 'Core columns only — 7 fixed fields', ic: '📄' },
  { k: 'WHO',     t: 'WHO AFRO IDSR baseline',      s: 'Full 57-column WHO AFRO + IHR + RTSL baseline',   ic: '🌍', cols: 57 },
  { k: 'DAILY',   t: 'Daily POE tally',              s: 'Lightweight daily screening count',   ic: '📊', cols: 9 },
  { k: 'CLONE',   t: 'Clone existing template',     s: 'Start from one of your published templates',  ic: '📋' },
]

const DATA_TYPES = ['INTEGER', 'DECIMAL', 'TEXT', 'BOOLEAN', 'DATE', 'PERCENT', 'SELECT']
const CATEGORIES = ['CORE', 'GENDER', 'AGE', 'SYMPTOMS', 'DISEASE', 'OUTCOMES', 'TRAVEL', 'CONVEYANCE', 'ENVIRONMENT', 'VACCINE', 'LAB', 'QUALITY', 'CUSTOM']

// ── State ────────────────────────────────────────────────────────────────
const form = reactive({
  id: null,
  country_code: '',
  template_name: '',
  template_code: '',
  description: '',
  reporting_frequency: 'WEEKLY',
  icon: null,
  colour: '#1E40AF',
  preset: null,  // BLANK | WHO | DAILY | CLONE
  clone_source_id: null,
})
const errors = reactive({})
const codeEdited = ref(false)
const workingCols = ref([])       // the set of columns the user is shaping
const loadingPreset = ref(false)
const colSearch = ref('')
const showAddCol = ref(false)
const newCol = reactive({ column_key: '', column_label: '', category: 'CUSTOM', data_type: 'INTEGER', help_text: '' })
const newColErr = ref('')
const saving = ref(false)
const submitError = ref('')
const toast = reactive({ show: false, msg: '', color: 'success' })

// ── Computeds ────────────────────────────────────────────────────────────
const enabledCount = computed(() => workingCols.value.filter(c => c.is_enabled).length)
const enabledWorking = computed(() => workingCols.value.filter(c => c.is_enabled))
const numericEnabled = computed(() => enabledWorking.value.filter(c => ['INTEGER', 'DECIMAL', 'PERCENT'].includes(c.data_type)))

const groupedCols = computed(() => {
  const byCat = new Map()
  const q = colSearch.value.trim().toLowerCase()
  for (const c of workingCols.value) {
    if (q && !(c.column_label.toLowerCase().includes(q) || c.column_key.toLowerCase().includes(q))) continue
    const k = c.category || 'CUSTOM'
    if (!byCat.has(k)) byCat.set(k, [])
    byCat.get(k).push(c)
  }
  const order = ['CORE', 'GENDER', 'AGE', 'SYMPTOMS', 'DISEASE', 'OUTCOMES', 'TRAVEL', 'CONVEYANCE', 'ENVIRONMENT', 'VACCINE', 'LAB', 'QUALITY', 'CUSTOM']
  return order
    .filter(c => byCat.has(c))
    .map(c => ({ category: c, cols: byCat.get(c) }))
    .concat(Array.from(byCat.keys()).filter(c => !order.includes(c)).map(c => ({ category: c, cols: byCat.get(c) })))
})

// ── Auto-code from name ──────────────────────────────────────────────────
function autoCode() {
  if (codeEdited.value) return
  const n = form.template_name || ''
  form.template_code = n.toUpperCase()
    .replace(/[^A-Z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 54) + (n ? '_V1' : '')
}

// ── API ──────────────────────────────────────────────────────────────────
async function api(path, opts = {}) {
  const uid = auth.value?.id
  if (!uid) return null
  const sep = path.includes('?') ? '&' : '?'
  const url = `${window.SERVER_URL}${path}${sep}user_id=${uid}`
  try {
    const res = await fetch(url, {
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      ...opts,
    })
    const body = await res.json().catch(() => null)
    return { ok: res.ok, status: res.status, body }
  } catch (e) { return { ok: false, status: 0, error: e?.message } }
}

// ── Presets ──────────────────────────────────────────────────────────────
async function pickPreset(k) {
  form.preset = k
  loadingPreset.value = true
  try {
    if (k === 'BLANK') {
      workingCols.value = coreBlankColumns()
    } else if (k === 'WHO') {
      // Fetch the default WHO template
      const def = await api('/aggregated-templates?country_code=' + encodeURIComponent(auth.value?.country_code || TENANT_CC) + '&status=PUBLISHED&include_columns=true')
      if (def?.ok && def.body?.success) {
        const whoTpl = (def.body.data || []).find(t => t.template_code === 'WHO_BASELINE_POE_V1')
                    || (def.body.data || []).find(t => t.is_default)
        if (whoTpl?.columns) workingCols.value = whoTpl.columns.map(cloneColForNew)
      }
    } else if (k === 'DAILY') {
      const res = await api('/aggregated-templates?country_code=' + encodeURIComponent(auth.value?.country_code || TENANT_CC) + '&status=PUBLISHED&include_columns=true')
      if (res?.ok && res.body?.success) {
        const daily = (res.body.data || []).find(t => t.template_code === 'DAILY_POE_TALLY_V1')
                    || (res.body.data || [])[0]
        if (daily?.columns) workingCols.value = daily.columns.map(cloneColForNew)
      }
    } else if (k === 'CLONE') {
      // Offer a simple picker — first try; for now clone the active default
      const res = await api('/aggregated-templates?country_code=' + encodeURIComponent(auth.value?.country_code || TENANT_CC) + '&status=PUBLISHED&include_columns=true')
      if (res?.ok && res.body?.success) {
        const picks = res.body.data || []
        if (picks.length > 0) {
          workingCols.value = (picks[0].columns || []).map(cloneColForNew)
          form.clone_source_id = picks[0].id
        }
      }
    }
  } finally {
    loadingPreset.value = false
  }
}

function cloneColForNew(c) {
  return {
    column_key: c.column_key,
    column_label: c.column_label,
    category: c.category || 'CUSTOM',
    data_type: c.data_type,
    is_required: c.is_required == 1,
    is_enabled: c.is_enabled == 1,
    is_core: c.is_core == 1,
    min_value: c.min_value,
    max_value: c.max_value,
    select_options: c.select_options || null,
    help_text: c.help_text || null,
    placeholder: c.placeholder || null,
    dashboard_visible: c.dashboard_visible ?? 1,
    report_visible: c.report_visible ?? 1,
    aggregation_fn: c.aggregation_fn || 'SUM',
    _local_new: false,
  }
}

function coreBlankColumns() {
  // 5 baked-in core columns every template needs. OTHER/UNKNOWN gender
  // retired 2026-04-21 — only MALE and FEMALE are captured at any POE.
  return [
    ['total_screened', 'Total screened', 'CORE', 'INTEGER', true, true],
    ['total_male', 'Total male', 'GENDER', 'INTEGER', true, true],
    ['total_female', 'Total female', 'GENDER', 'INTEGER', true, true],
    ['total_symptomatic', 'Total symptomatic', 'SYMPTOMS', 'INTEGER', true, true],
    ['total_asymptomatic', 'Total asymptomatic', 'SYMPTOMS', 'INTEGER', true, true],
  ].map(([k, l, cat, t, req, core]) => ({
    column_key: k, column_label: l, category: cat, data_type: t,
    is_required: req, is_enabled: true, is_core: core,
    aggregation_fn: 'SUM', dashboard_visible: 1, report_visible: 1,
    _local_new: false,
  }))
}

// ── Add custom col ────────────────────────────────────────────────────────
function openAddCol() {
  Object.assign(newCol, { column_key: '', column_label: '', category: 'CUSTOM', data_type: 'INTEGER', help_text: '' })
  newColErr.value = ''
  showAddCol.value = true
}
function confirmAddCol() {
  newColErr.value = ''
  const k = newCol.column_key
  if (!/^[a-z][a-z0-9_]{1,58}$/.test(k)) {
    newColErr.value = 'Key must be lowercase a-z/0-9/_ starting with a letter (2-60 chars).'; return
  }
  if (workingCols.value.some(c => c.column_key === k)) {
    newColErr.value = 'A column with this key already exists.'; return
  }
  if (!newCol.column_label) { newColErr.value = 'Label is required.'; return }
  workingCols.value.push({
    ...newCol, is_required: false, is_enabled: true, is_core: false,
    aggregation_fn: newCol.data_type === 'PERCENT' ? 'AVG' : newCol.data_type === 'BOOLEAN' ? 'COUNT' : 'SUM',
    dashboard_visible: 1, report_visible: 1, _local_new: true,
  })
  showAddCol.value = false
}
function removeLocalCol(c) {
  workingCols.value = workingCols.value.filter(x => x !== c)
}

// ── Step navigation + validation ─────────────────────────────────────────
function goStep(k) {
  const t = STEPS.findIndex(s => s.k === k)
  if (t < stepIndex.value) step.value = k  // only jump backwards
}
function goBack() { if (stepIndex.value > 0) step.value = STEPS[stepIndex.value - 1].k }

function validatePurpose() {
  Object.keys(errors).forEach(k => delete errors[k])
  let ok = true
  if (!form.template_name || form.template_name.length < 3) { errors.template_name = 'At least 3 characters.'; ok = false }
  if (!/^[A-Z][A-Z0-9_]{1,58}$/.test(form.template_code || '')) { errors.template_code = 'UPPERCASE letters, digits and _ only; must start with a letter.'; ok = false }
  return ok
}

function onNext() {
  if (step.value === 'purpose') {
    if (!validatePurpose()) return
    step.value = 'start'
  } else if (step.value === 'start') {
    if (!form.preset) { showToast('Pick a starting point', 'warning'); return }
    if (workingCols.value.length === 0) workingCols.value = coreBlankColumns()
    step.value = 'columns'
  } else if (step.value === 'columns') {
    if (enabledCount.value === 0) { showToast('Enable at least one column', 'warning'); return }
    step.value = 'validation'
  } else if (step.value === 'validation') {
    step.value = 'publish'
  }
}

// ── Save + publish ──────────────────────────────────────────────────────
async function save(publishNow) {
  if (!validatePurpose()) { step.value = 'purpose'; return }
  if (enabledCount.value === 0) { step.value = 'columns'; showToast('Enable at least one column', 'warning'); return }

  saving.value = true
  submitError.value = ''
  try {
    // 1. Create template
    const createRes = await api('/aggregated-templates', {
      method: 'POST',
      body: JSON.stringify({
        user_id: auth.value?.id,
        country_code: auth.value?.country_code || TENANT_CC,
        template_name: form.template_name,
        template_code: form.template_code,
        description: form.description,
        reporting_frequency: form.reporting_frequency,
        icon: form.icon,
        colour: form.colour,
        clone_default_columns: false,
      }),
    })
    if (!createRes?.ok || !createRes.body?.success) {
      submitError.value = createRes?.body?.message || 'Could not create template'; return
    }
    const newTpl = createRes.body.data
    form.id = newTpl.id

    // 2. Add columns (the cloned preset + locally-new)
    for (let i = 0; i < workingCols.value.length; i++) {
      const c = workingCols.value[i]
      if (!c.is_enabled && !c.is_core) continue  // skip disabled non-core
      const body = {
        user_id: auth.value?.id,
        column_key: c.column_key,
        column_label: c.column_label,
        category: c.category,
        data_type: c.data_type,
        is_required: c.is_required,
        is_enabled: c.is_enabled,
        min_value: c.min_value ?? null,
        max_value: c.max_value ?? null,
        help_text: c.help_text,
        placeholder: c.placeholder,
        dashboard_visible: c.dashboard_visible ?? 1,
        report_visible: c.report_visible ?? 1,
        aggregation_fn: c.aggregation_fn || 'SUM',
      }
      const addRes = await api(`/aggregated-templates/${newTpl.id}/columns`, {
        method: 'POST', body: JSON.stringify(body),
      })
      if (!addRes?.ok || !addRes.body?.success) {
        // 409 conflict when key already exists on template — tolerate (e.g. core columns auto-seeded server-side if that is done)
        if (addRes?.status !== 409) {
          console.warn('[Wizard] add column failed', c.column_key, addRes?.body)
        }
      }
    }

    // 3. Publish if requested
    if (publishNow) {
      const pubRes = await api(`/aggregated-templates/${newTpl.id}/publish`, {
        method: 'POST', body: JSON.stringify({ user_id: auth.value?.id }),
      })
      if (!pubRes?.ok || !pubRes.body?.success) {
        showToast(`Saved as draft (publish failed: ${pubRes?.body?.message || 'server'})`, 'warning')
        router.replace('/admin/aggregated-templates')
        return
      }
    }

    showToast(publishNow ? 'Published to all POEs' : 'Saved as draft', 'success')
    router.replace('/aggregated-data')
  } catch (e) {
    submitError.value = e.message
  } finally {
    saving.value = false
  }
}

// ── Misc ────────────────────────────────────────────────────────────────
function confirmDiscard() {
  if (workingCols.value.length > 0 || form.template_name) {
    if (!confirm('Discard this template? Your progress will be lost.')) return
  }
  router.back()
}
function freqLabel(f) { return (FREQS.find(x => x.v === f) || {}).l || f }
function showToast(msg, color = 'success') { toast.show = true; toast.msg = msg; toast.color = color }

onMounted(() => {
  form.country_code = auth.value?.country_code || TENANT_CC
})
onIonViewDidEnter(() => { auth.value = getAuth() })
</script>

<style scoped>
* { box-sizing: border-box }

.aw-hdr { --background: transparent; border: none }
.aw-hdr-bg { background: linear-gradient(135deg, #001D3D, #003566, #003F88); padding: 0 0 8px }
.aw-hdr-top { display: flex; align-items: center; gap: 4px; padding: 8px 8px 0 }
.aw-back { --color: rgba(255,255,255,.85) }
.aw-hdr-title { flex: 1; display: flex; flex-direction: column; min-width: 0 }
.aw-hdr-eye { font-size: 9px; font-weight: 700; color: rgba(255,255,255,.5); text-transform: uppercase; letter-spacing: 1.4px }
.aw-hdr-h1 { font-size: 17px; font-weight: 800; color: #fff }
.aw-close-btn { margin-right: 8px; padding: 5px 10px; border-radius: 6px; background: rgba(255,255,255,.1); color: #fff; border: none; font-size: 11px; font-weight: 700; cursor: pointer }

.aw-steps { display: flex; gap: 4px; padding: 10px 10px 0; overflow-x: auto; scrollbar-width: none }
.aw-steps::-webkit-scrollbar { display: none }
.aw-step { flex-shrink: 0; display: inline-flex; align-items: center; gap: 6px; padding: 5px 11px; border-radius: 99px; background: rgba(255,255,255,.08); color: rgba(255,255,255,.7); cursor: pointer; font-size: 11px; font-weight: 700 }
.aw-step-n { width: 18px; height: 18px; border-radius: 50%; background: rgba(255,255,255,.15); font-size: 10px; font-weight: 900; display: inline-flex; align-items: center; justify-content: center }
.aw-step--on { background: #fff; color: #1A3A5C }
.aw-step--on .aw-step-n { background: #1E40AF; color: #fff }
.aw-step--done .aw-step-n { background: rgba(16,185,129,.4); color: #fff }

.aw-content { --background: #F0F4FA }
.aw-guard { padding: 80px 24px; text-align: center; display: flex; flex-direction: column; align-items: center; gap: 10px }
.aw-guard-ic { font-size: 36px }
.aw-guard-t { font-size: 17px; font-weight: 800; color: #1A3A5C }
.aw-guard-s { font-size: 12.5px; color: #64748B; line-height: 1.5; max-width: 300px }

.aw-sec { margin: 14px 10px 0; background: #fff; border: 1px solid #E8EDF5; border-radius: 14px; padding: 18px; animation: aw-slide .25s ease-out }
@keyframes aw-slide { from { opacity: 0; transform: translateY(6px) } to { opacity: 1; transform: translateY(0) } }
.aw-sh { font-size: 17px; font-weight: 800; color: #1A3A5C; margin: 0 0 6px }
.aw-p { font-size: 12.5px; color: #64748B; line-height: 1.5; margin: 0 0 16px }
.aw-sub { font-size: 13px; font-weight: 800; color: #1A3A5C; margin: 16px 0 8px }

.aw-f { display: flex; flex-direction: column; gap: 4px; margin-bottom: 14px }
.aw-lbl { font-size: 11px; font-weight: 800; color: #1A3A5C; letter-spacing: .2px }
.aw-help { font-size: 10px; color: #64748B; font-weight: 500; line-height: 1.35; display: block; margin-top: 2px }
.aw-inp { padding: 10px 12px; border: 1.5px solid #E8EDF5; border-radius: 8px; font-size: 13px; color: #1A3A5C; background: #fff; font-family: inherit; outline: none; transition: border-color .15s, box-shadow .15s }
.aw-inp:focus { border-color: #1E40AF; box-shadow: 0 0 0 3px rgba(30,64,175,.12) }
.aw-inp--ta { resize: vertical; min-height: 80px }
.aw-inp--sm { padding: 7px 10px; font-size: 12px }
.aw-mono { font-family: ui-monospace, Menlo, monospace; letter-spacing: .3px }
.aw-err { font-size: 11px; color: #DC2626; font-weight: 700 }
.aw-hint { font-size: 11px; color: #64748B; font-weight: 600; margin-top: 4px }

/* Frequency grid */
.aw-freq-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px }
@media (max-width: 420px) { .aw-freq-grid { grid-template-columns: repeat(2, 1fr) } }
.aw-freq { padding: 12px 8px; border: 1.5px solid #E8EDF5; border-radius: 10px; background: #fff; cursor: pointer; display: flex; flex-direction: column; align-items: center; gap: 2px; transition: all .15s }
.aw-freq:active { transform: scale(.97) }
.aw-freq--on { border-color: #1E40AF; background: #EFF6FF; box-shadow: 0 0 0 2px rgba(30,64,175,.1) }
.aw-freq-ic { font-size: 18px; line-height: 1 }
.aw-freq-t { font-size: 12px; font-weight: 800; color: #1A3A5C }
.aw-freq-s { font-size: 9.5px; color: #64748B; font-weight: 600 }

/* Colour swatches */
.aw-colours { display: flex; gap: 8px; flex-wrap: wrap }
.aw-colour { width: 34px; height: 34px; border-radius: 8px; border: 2px solid transparent; cursor: pointer; transition: transform .1s, border-color .15s }
.aw-colour:active { transform: scale(.9) }
.aw-colour--on { border-color: #1A3A5C; box-shadow: 0 0 0 3px rgba(26,58,92,.15) }

/* Preset grid */
.aw-preset-grid { display: flex; flex-direction: column; gap: 8px }
.aw-preset { display: flex; align-items: center; gap: 12px; padding: 14px; border: 1.5px solid #E8EDF5; border-radius: 10px; background: #fff; cursor: pointer; text-align: left; transition: all .15s; position: relative }
.aw-preset:active { transform: scale(.995) }
.aw-preset--on { border-color: #1E40AF; background: #EFF6FF }
.aw-preset-ic { font-size: 22px; flex-shrink: 0; width: 40px; height: 40px; border-radius: 8px; background: #F1F5F9; display: flex; align-items: center; justify-content: center }
.aw-preset--on .aw-preset-ic { background: #DBEAFE }
.aw-preset-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px }
.aw-preset-t { font-size: 13px; font-weight: 800; color: #1A3A5C }
.aw-preset-s { font-size: 11px; color: #64748B; font-weight: 600 }
.aw-preset-n { font-size: 10px; color: #1E40AF; font-weight: 700; margin-top: 2px }
.aw-preset-check { position: absolute; top: 12px; right: 12px; width: 22px; height: 22px; border-radius: 50%; background: #1E40AF; color: #fff; font-size: 13px; font-weight: 900; display: flex; align-items: center; justify-content: center }

/* Columns step */
.aw-col-tools { display: flex; gap: 8px; margin-bottom: 12px }
.aw-col-tools .aw-inp { flex: 1 }
.aw-btn { padding: 7px 12px; border-radius: 7px; border: none; font-size: 11.5px; font-weight: 800; cursor: pointer }
.aw-btn--ghost { background: #fff; color: #1E40AF; border: 1px solid #BFDBFE }
.aw-col-groups { display: flex; flex-direction: column; gap: 12px }
.aw-col-group { background: #F8FAFC; border: 1px solid #E8EDF5; border-radius: 10px; padding: 10px 8px }
.aw-col-gh { display: flex; justify-content: space-between; align-items: center; padding: 0 4px 8px }
.aw-col-gp { font-size: 9.5px; font-weight: 900; padding: 3px 9px; border-radius: 4px; background: #F1F5F9; color: #475569; letter-spacing: .4px }
.aw-col-gp--core { background: #DBEAFE; color: #1E40AF }
.aw-col-gp--gender { background: #FCE7F3; color: #9D174D }
.aw-col-gp--symptoms, .aw-col-gp--disease { background: #FEE2E2; color: #991B1B }
.aw-col-gp--outcomes { background: #D1FAE5; color: #047857 }
.aw-col-gp--age { background: #EDE9FE; color: #6D28D9 }
.aw-col-gp--travel { background: #DCFCE7; color: #166534 }
.aw-col-gp--vaccine { background: #FEF3C7; color: #854D0E }
.aw-col-gp--lab { background: #E0E7FF; color: #3730A3 }
.aw-col-gp--quality { background: #E0F2FE; color: #0369A1 }
.aw-col-gn { font-size: 10px; color: #64748B; font-weight: 700 }
.aw-col { display: flex; align-items: center; gap: 10px; padding: 8px 4px; border-top: 1px solid #E8EDF5; transition: opacity .2s }
.aw-col:first-of-type { border-top: none }
.aw-col:not(.aw-col--on):not(.aw-col--core) { opacity: .55 }

/* Switch toggle */
.aw-col-toggle { position: relative; width: 36px; height: 20px; flex-shrink: 0; cursor: pointer }
.aw-col-toggle input { opacity: 0; width: 0; height: 0 }
.aw-col-slider { position: absolute; inset: 0; background: #CBD5E1; border-radius: 99px; transition: background .2s }
.aw-col-slider::before { content: ''; position: absolute; left: 2px; top: 2px; width: 16px; height: 16px; background: #fff; border-radius: 50%; transition: transform .2s }
.aw-col-toggle input:checked + .aw-col-slider { background: #059669 }
.aw-col-toggle input:checked + .aw-col-slider::before { transform: translateX(16px) }
.aw-col-core-badge { font-size: 8.5px; font-weight: 900; padding: 3px 6px; border-radius: 4px; background: #DBEAFE; color: #1E40AF; letter-spacing: .3px }
.aw-col-body { flex: 1; min-width: 0 }
.aw-col-name { font-size: 12.5px; font-weight: 700; color: #1A3A5C }
.aw-col-meta { display: flex; gap: 6px; font-size: 9.5px; color: #64748B; font-weight: 600; margin-top: 2px; flex-wrap: wrap }
.aw-col-k { font-family: ui-monospace, Menlo, monospace }
.aw-col-t { color: #1E40AF; font-weight: 800 }
.aw-col-req { color: #DC2626 }
.aw-col-del { width: 24px; height: 24px; border-radius: 50%; border: 1px solid #FECACA; background: #fff; color: #DC2626; font-size: 16px; font-weight: 700; cursor: pointer; flex-shrink: 0 }

/* Validation step */
.aw-val-grid { display: flex; flex-direction: column; gap: 10px }
.aw-val-card { border: 1px solid #E8EDF5; border-radius: 10px; padding: 12px; background: #F8FAFC }
.aw-val-hdr { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px }
.aw-val-name { font-size: 12.5px; font-weight: 800; color: #1A3A5C }
.aw-val-type { font-size: 9.5px; font-weight: 800; padding: 2px 7px; border-radius: 4px; background: #DBEAFE; color: #1E40AF; letter-spacing: .3px }
.aw-val-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: end }
.aw-val-f { display: flex; flex-direction: column; gap: 3px }
.aw-val-f > span:first-of-type { font-size: 10px; color: #64748B; font-weight: 700; text-transform: uppercase; letter-spacing: .3px }
.aw-val-req { flex-direction: row; align-items: center; gap: 6px; padding-bottom: 8px; font-size: 11.5px; color: #1A3A5C; cursor: pointer }
.aw-val-req input { width: 16px; height: 16px; cursor: pointer }

/* Preview step */
.aw-prev { display: flex; background: #fff; border: 1px solid #E8EDF5; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 6px rgba(26,58,92,.05) }
.aw-prev-stripe { width: 4px; flex-shrink: 0 }
.aw-prev-body { flex: 1; padding: 16px }
.aw-prev-badges { display: flex; gap: 6px; margin-bottom: 8px }
.aw-prev-badge { font-size: 9.5px; font-weight: 900; padding: 3px 8px; border-radius: 4px; background: #F1F5F9; color: #475569; letter-spacing: .3px; text-transform: uppercase }
.aw-prev-badge--code { font-family: ui-monospace, Menlo, monospace; background: #E0E7FF; color: #3730A3 }
.aw-prev-title { font-size: 16px; font-weight: 800; color: #1A3A5C; margin: 0 0 6px; line-height: 1.3 }
.aw-prev-desc { font-size: 12px; color: #64748B; margin: 0 0 10px; line-height: 1.45 }
.aw-prev-meta { display: flex; gap: 12px; font-size: 11px; color: #475569; font-weight: 600 }

.aw-field-list { display: flex; flex-wrap: wrap; gap: 6px }
.aw-field-pill { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; background: #fff; border: 1px solid #E8EDF5; border-radius: 99px; font-size: 11px; color: #1A3A5C; font-weight: 600 }
.aw-field-pill-t { font-size: 9px; color: #1E40AF; font-weight: 800; padding: 1px 5px; background: #DBEAFE; border-radius: 3px }
.aw-field-pill-r { color: #DC2626; font-weight: 900 }

.aw-submit-err { margin-top: 12px; padding: 10px; background: #FEF2F2; border: 1px solid #FECACA; color: #991B1B; border-radius: 6px; font-size: 11.5px; font-weight: 600 }

.aw-publish-opts { display: flex; flex-direction: column; gap: 8px; margin-top: 16px }
.aw-big-btn { padding: 14px; border-radius: 10px; border: none; font-size: 14px; font-weight: 800; cursor: pointer; transition: transform .1s }
.aw-big-btn:active { transform: scale(.98) }
.aw-big-btn:disabled { opacity: .45; cursor: not-allowed }
.aw-big-btn--draft { background: #fff; color: #475569; border: 1.5px solid #CBD5E1 }
.aw-big-btn--publish { background: linear-gradient(135deg, #1E40AF, #3B82F6); color: #fff; box-shadow: 0 4px 14px rgba(30,64,175,.3) }
.aw-legal { font-size: 10.5px; color: #94A3B8; font-style: italic; line-height: 1.5; margin: 14px 0 0 }

/* Nav */
.aw-nav { display: flex; gap: 10px; padding: 14px 10px 20px; position: sticky; bottom: 0; background: linear-gradient(180deg, transparent, #F0F4FA 35%) }
.aw-nav-btn { flex: 1; padding: 13px; border-radius: 10px; border: none; font-size: 13.5px; font-weight: 800; cursor: pointer; transition: transform .1s }
.aw-nav-btn:active { transform: scale(.97) }
.aw-nav-btn:disabled { opacity: .45 }
.aw-nav-btn--ghost { background: #fff; color: #475569; border: 1px solid #CBD5E1 }
.aw-nav-btn--primary { background: #1E40AF; color: #fff; box-shadow: 0 4px 14px rgba(30,64,175,.25) }

/* Modal */
.aw-modal { --background: #fff }
.aw-mp { padding: 20px 16px }
.aw-mp-t { font-size: 17px; font-weight: 800; color: #1A3A5C; margin: 0 0 6px }
.aw-mp-s { font-size: 12px; color: #64748B; margin: 0 0 14px; line-height: 1.4 }
.aw-modal-row { display: flex; gap: 10px; margin-bottom: 14px }
.aw-modal-half { flex: 1; min-width: 0 }
.aw-modal-acts { display: flex; gap: 8px; margin-top: 14px }

/* AI assistant panel */
.aw-ai { margin: 14px 10px 0; background: linear-gradient(135deg, #F3E8FF, #EDE9FE); border: 1px solid #D8B4FE; border-radius: 12px; overflow: hidden; animation: aw-slide .25s ease-out }
.aw-ai--closed .aw-ai-hdr { border-bottom: none }
.aw-ai-hdr { width: 100%; display: flex; align-items: center; gap: 8px; padding: 10px 12px; background: transparent; border: none; border-bottom: 1px solid rgba(109,40,217,.15); cursor: pointer; text-align: left }
.aw-ai-badge { font-size: 9px; font-weight: 900; padding: 2px 7px; border-radius: 4px; background: #6D28D9; color: #fff; letter-spacing: .5px }
.aw-ai-t { flex: 1; font-size: 11.5px; font-weight: 800; color: #4C1D95 }
.aw-ai-chev { font-size: 14px; color: #6D28D9; transition: transform .2s }
.aw-ai-chev--open { transform: rotate(180deg) }
.aw-ai-body { padding: 4px 0 }
.aw-ai-row { display: flex; gap: 10px; padding: 9px 12px; border-top: 1px solid rgba(109,40,217,.08) }
.aw-ai-row:first-child { border-top: none }
.aw-ai-row--warn { background: rgba(254,243,199,.4) }
.aw-ai-row--tip, .aw-ai-row--insight { background: transparent }
.aw-ai-row--info { background: rgba(224,231,255,.3) }
.aw-ai-ic { font-size: 15px; flex-shrink: 0; margin-top: 1px }
.aw-ai-content { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px }
.aw-ai-head { font-size: 11.5px; font-weight: 800; color: #4C1D95; line-height: 1.3 }
.aw-ai-row--warn .aw-ai-head { color: #854D0E }
.aw-ai-msg { font-size: 11px; color: #6D28D9; line-height: 1.5 }
.aw-ai-row--warn .aw-ai-msg { color: #854D0E }

@media (min-width: 540px) { .aw-sec, .aw-nav, .aw-ai { max-width: 540px; margin-left: auto; margin-right: auto } }
</style>
