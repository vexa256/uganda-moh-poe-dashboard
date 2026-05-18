<template>
  <IonPage>
    <IonHeader class="dg-hdr" translucent>
      <IonToolbar>
        <IonButtons slot="start">
          <IonBackButton default-href="/settings" />
        </IonButtons>
        <IonTitle>
          <span class="dg-eye">SETTINGS · DEV TOOLS</span>
          <span class="dg-h1">Plugin diagnostics</span>
        </IonTitle>
        <IonButtons slot="end">
          <IonButton @click="copyReport" :disabled="!finishedAt" aria-label="Copy report">
            <IonIcon :icon="copyOutline" slot="icon-only" />
          </IonButton>
        </IonButtons>
      </IonToolbar>
    </IonHeader>

    <IonContent class="dg-content" :fullscreen="true">
      <div class="dg-body">

        <!-- Summary strip -->
        <section class="dg-summary" :class="'dg-summary--' + summaryTone">
          <div class="dg-summary-row">
            <div class="dg-summary-stat"><span class="dg-summary-n">{{ counts.pass }}</span><span class="dg-summary-l">Pass</span></div>
            <div class="dg-summary-stat"><span class="dg-summary-n">{{ counts.warn }}</span><span class="dg-summary-l">Warn</span></div>
            <div class="dg-summary-stat"><span class="dg-summary-n">{{ counts.fail }}</span><span class="dg-summary-l">Fail</span></div>
            <div class="dg-summary-stat"><span class="dg-summary-n">{{ counts.skip }}</span><span class="dg-summary-l">Skip</span></div>
          </div>
          <div class="dg-summary-meta">
            <span v-if="running"><IonSpinner name="crescent" class="dg-spin"/> Running… {{ progressLabel }}</span>
            <span v-else-if="finishedAt">Finished in {{ totalDurationMs }} ms · {{ envLabel }}</span>
            <span v-else>Tap "Run diagnostics" to start.</span>
          </div>
        </section>

        <!-- Filters + actions -->
        <section class="dg-controls">
          <div class="dg-filters" role="group" aria-label="Filter results">
            <button v-for="f in FILTERS" :key="f.v"
                    type="button"
                    :class="['dg-chip', filter === f.v && 'dg-chip--on']"
                    @click="filter = f.v">
              {{ f.l }}
              <span v-if="counts[f.v] !== undefined" class="dg-chip-n">{{ counts[f.v] }}</span>
            </button>
          </div>
          <div class="dg-actions">
            <IonButton expand="block" :disabled="running" @click="runOnce" color="primary">
              <IonIcon :icon="playOutline" slot="start" />
              {{ running ? 'Running…' : (suites.length ? 'Re-run diagnostics' : 'Run diagnostics') }}
            </IonButton>
          </div>
        </section>

        <!-- Empty state -->
        <div v-if="!suites.length && !running" class="dg-empty">
          <p>No results yet.</p>
          <p class="dg-empty-d">The diagnostic runner probes every plugin's runtime — module load, platform, gates, permissions, and a non-destructive call. Each probe reports pass / warn / fail with a remediation hint.</p>
        </div>

        <!-- Results -->
        <section v-for="suite in filteredSuites" :key="suite.id" class="dg-suite" :class="'dg-suite--' + suite.overall">
          <header class="dg-suite-h" @click="toggleOpen(suite.id)">
            <span class="dg-suite-status" :class="'dg-status-pill--' + suite.overall">{{ statusLabel(suite.overall) }}</span>
            <div class="dg-suite-meta">
              <span class="dg-suite-t">{{ suite.title }}</span>
              <span class="dg-suite-s">{{ suite.summary }}</span>
            </div>
            <span class="dg-suite-time">{{ suite.durationMs }} ms</span>
            <IonIcon :icon="open[suite.id] ? chevronDownOutline : chevronForwardOutline" class="dg-suite-chev" />
          </header>
          <div v-if="open[suite.id] !== false" class="dg-tests">
            <article v-for="t in suite.tests" :key="t.id" class="dg-test" :class="'dg-test--' + t.status">
              <div class="dg-test-row">
                <span class="dg-test-status" :class="'dg-status-dot--' + t.status" :title="statusLabel(t.status)"/>
                <span class="dg-test-name">{{ t.name }}</span>
                <span class="dg-test-time">{{ t.durationMs }} ms</span>
              </div>
              <p class="dg-test-detail">{{ t.detail }}</p>
              <p v-if="t.hint" class="dg-test-hint"><strong>Fix:</strong> {{ t.hint }}</p>
              <details v-if="t.error" class="dg-test-err">
                <summary>{{ t.error.name }}: {{ t.error.message }}</summary>
                <pre v-if="t.error.stack" class="dg-test-stack">{{ t.error.stack }}</pre>
              </details>
            </article>
          </div>
        </section>

        <div style="height:48px"/>
      </div>
    </IonContent>

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="2000" position="top" @didDismiss="toast.show = false" />
  </IonPage>
</template>

<script setup>
import {
  IonPage, IonHeader, IonToolbar, IonTitle, IonContent, IonButtons,
  IonBackButton, IonButton, IonIcon, IonSpinner, IonToast,
} from '@ionic/vue'
import { reactive, ref, computed, onMounted } from 'vue'
import {
  copyOutline, playOutline, chevronDownOutline, chevronForwardOutline,
} from 'ionicons/icons'
import { runAllDiagnostics, DIAGNOSTIC_SUITES } from '@/services/pluginDiagnostics.js'

const FILTERS = [
  { v: 'all',  l: 'All' },
  { v: 'fail', l: 'Failures' },
  { v: 'warn', l: 'Warnings' },
  { v: 'pass', l: 'Passing' },
  { v: 'skip', l: 'Skipped' },
]

const suites      = ref([])
const running     = ref(false)
const startedAt   = ref(null)
const finishedAt  = ref(null)
const totalDurationMs = ref(0)
const env         = ref(null)
const filter      = ref('all')
const open        = reactive({})
const progressIdx = ref(0)
const toast       = reactive({ show: false, msg: '', color: 'success' })

// All suites collapsed by default after first run; re-opens automatically
// for any suite with at least one fail.
function toggleOpen(id) { open[id] = open[id] === false ? true : false }

function statusLabel(s) {
  return ({ pass: 'PASS', fail: 'FAIL', warn: 'WARN', skip: 'SKIP', partial: 'PARTIAL' })[s] || s
}

const counts = computed(() => {
  const c = { pass: 0, warn: 0, fail: 0, skip: 0 }
  for (const s of suites.value) for (const t of s.tests) c[t.status] = (c[t.status] || 0) + 1
  return c
})

const summaryTone = computed(() => {
  if (running.value) return 'running'
  if (!suites.value.length) return 'idle'
  if (counts.value.fail > 0) return 'fail'
  if (counts.value.warn > 0) return 'warn'
  return 'pass'
})

const envLabel = computed(() => {
  if (!env.value) return ''
  return `${env.value.platform}${env.value.isNative ? ' · native' : ' · web'}`
})

const filteredSuites = computed(() => {
  if (filter.value === 'all') return suites.value
  return suites.value
    .map(s => ({ ...s, tests: s.tests.filter(t => t.status === filter.value) }))
    .filter(s => s.tests.length > 0)
})

const progressLabel = computed(() => {
  const total = DIAGNOSTIC_SUITES.length
  return `${progressIdx.value} / ${total}`
})

async function runOnce() {
  if (running.value) return
  running.value = true
  suites.value = []
  startedAt.value = new Date().toISOString()
  finishedAt.value = null
  totalDurationMs.value = 0
  progressIdx.value = 0
  try {
    const result = await runAllDiagnostics({
      onSuite: (s) => {
        progressIdx.value++
        suites.value = [...suites.value, s]
        // Auto-open any suite that has a failure, leave fully-passing collapsed.
        open[s.id] = s.overall === 'fail' || s.overall === 'partial'
      },
    })
    env.value          = result.env
    finishedAt.value   = result.finishedAt
    totalDurationMs.value = result.durationMs
  } catch (err) {
    toast.msg = `Diagnostics crashed: ${err?.message || err}`
    toast.color = 'danger'
    toast.show = true
  } finally {
    running.value = false
  }
}

async function copyReport() {
  if (!suites.value.length) return
  const report = {
    generatedAt: finishedAt.value || startedAt.value,
    durationMs: totalDurationMs.value,
    env: env.value,
    counts: counts.value,
    suites: suites.value,
  }
  const text = JSON.stringify(report, null, 2)
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text)
      toast.msg = 'Report copied to clipboard.'
    } else {
      // Fallback — pop a textarea for manual copy
      const ta = document.createElement('textarea')
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.left = '-1000px'
      document.body.appendChild(ta); ta.select()
      document.execCommand('copy')
      document.body.removeChild(ta)
      toast.msg = 'Report copied (legacy fallback).'
    }
    toast.color = 'success'; toast.show = true
  } catch (err) {
    toast.msg = `Copy failed: ${err?.message || err}`; toast.color = 'danger'; toast.show = true
  }
}

// Run automatically on first mount so the user lands on results, not an empty
// state. They can re-run from the button.
onMounted(() => { runOnce() })
</script>

<style scoped>
.dg-hdr ion-toolbar {
  --background: linear-gradient(135deg, #0F3460 0%, #1B4D8C 100%);
  --color: #fff;
  --border-color: transparent;
  --min-height: 64px;
}
.dg-eye { display: block; font-size: 9px; font-weight: 800; letter-spacing: 1.2px; opacity: .7; margin-bottom: 1px; }
.dg-h1  { display: block; font-size: 17px; font-weight: 800; line-height: 1.1; }
.dg-content { --background: #F4F6FB; }
.dg-body { padding: 12px; max-width: 720px; margin: 0 auto; }

/* Summary */
.dg-summary { background: #fff; border-radius: 12px; padding: 14px; box-shadow: 0 1px 3px rgba(15,52,96,.06); border-left: 4px solid #94A3B8; margin-bottom: 12px; }
.dg-summary--running { border-left-color: #0EA5E9 }
.dg-summary--pass    { border-left-color: #10B981 }
.dg-summary--warn    { border-left-color: #F59E0B }
.dg-summary--fail    { border-left-color: #DC2626 }
.dg-summary-row { display: flex; gap: 10px; margin-bottom: 8px; }
.dg-summary-stat { flex: 1; background: #F8FAFC; border-radius: 8px; padding: 10px; display: flex; flex-direction: column; align-items: center; gap: 2px; }
.dg-summary-n { font-size: 22px; font-weight: 900; color: #0F3460; line-height: 1; font-variant-numeric: tabular-nums; }
.dg-summary-l { font-size: 9px; font-weight: 800; color: #64748B; text-transform: uppercase; letter-spacing: .5px; }
.dg-summary-meta { font-size: 11px; color: #475569; display: flex; align-items: center; gap: 6px; }
.dg-spin { width: 14px; height: 14px; }

/* Controls */
.dg-controls { display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; }
.dg-filters { display: flex; flex-wrap: wrap; gap: 6px; }
.dg-chip { padding: 6px 12px; border-radius: 999px; border: 1px solid #E2E8F0; background: #fff; font-size: 11px; font-weight: 700; color: #475569; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
.dg-chip--on { background: #0F3460; color: #fff; border-color: #0F3460; }
.dg-chip-n { font-size: 10px; font-weight: 800; opacity: .8; padding: 0 4px; border-radius: 6px; background: rgba(0,0,0,.08); }
.dg-chip--on .dg-chip-n { background: rgba(255,255,255,.2); }
.dg-actions { width: 100%; }

/* Empty */
.dg-empty { background: #fff; border-radius: 12px; padding: 20px; text-align: center; color: #475569; }
.dg-empty p { margin: 0 0 8px; }
.dg-empty-d { font-size: 12px; color: #64748B; line-height: 1.5; }

/* Suite */
.dg-suite { background: #fff; border-radius: 12px; margin-bottom: 8px; box-shadow: 0 1px 2px rgba(0,0,0,.04); border-left: 3px solid #94A3B8; overflow: hidden; }
.dg-suite--pass    { border-left-color: #10B981 }
.dg-suite--partial { border-left-color: #F59E0B }
.dg-suite--warn    { border-left-color: #F59E0B }
.dg-suite--fail    { border-left-color: #DC2626 }
.dg-suite--skip    { border-left-color: #94A3B8 }
.dg-suite-h { display: flex; align-items: center; gap: 10px; padding: 12px 14px; cursor: pointer; user-select: none; }
.dg-suite-meta { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.dg-suite-t { font-size: 13px; font-weight: 800; color: #0F3460; }
.dg-suite-s { font-size: 11px; color: #64748B; line-height: 1.3; }
.dg-suite-time { font-size: 10px; color: #94A3B8; font-variant-numeric: tabular-nums; }
.dg-suite-chev { font-size: 16px; color: #94A3B8; }

/* Status pills + dots */
.dg-suite-status { font-size: 9px; font-weight: 900; letter-spacing: .6px; padding: 3px 8px; border-radius: 999px; }
.dg-status-pill--pass    { background: #DCFCE7; color: #166534 }
.dg-status-pill--partial { background: #FEF3C7; color: #92400E }
.dg-status-pill--warn    { background: #FEF3C7; color: #92400E }
.dg-status-pill--fail    { background: #FEE2E2; color: #991B1B }
.dg-status-pill--skip    { background: #F1F5F9; color: #475569 }
.dg-status-dot--pass { background: #10B981 }
.dg-status-dot--warn { background: #F59E0B }
.dg-status-dot--fail { background: #DC2626 }
.dg-status-dot--skip { background: #94A3B8 }

/* Tests */
.dg-tests { padding: 0 14px 12px; border-top: 1px solid #F0F4FA; }
.dg-test { padding: 10px 0; border-top: 1px dashed #E2E8F0; }
.dg-test:first-of-type { border-top: none; }
.dg-test-row { display: flex; align-items: center; gap: 8px; margin-bottom: 4px; }
.dg-test-status { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.dg-test-name { flex: 1; font-size: 12px; font-weight: 700; color: #0F3460; }
.dg-test-time { font-size: 10px; color: #94A3B8; font-variant-numeric: tabular-nums; }
.dg-test-detail { margin: 0 0 4px 18px; font-size: 11px; color: #475569; line-height: 1.5; }
.dg-test-hint { margin: 0 0 4px 18px; font-size: 11px; color: #166534; background: #ECFDF5; border-radius: 6px; padding: 6px 8px; }
.dg-test-err { margin-left: 18px; }
.dg-test-err summary { font-size: 11px; color: #991B1B; cursor: pointer; padding: 4px 0; }
.dg-test-stack { background: #1F2937; color: #F1F5F9; padding: 8px; border-radius: 6px; font-size: 10px; line-height: 1.45; overflow-x: auto; max-height: 180px; }
</style>
