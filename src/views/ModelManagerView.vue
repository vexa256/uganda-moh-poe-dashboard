<template>
  <IonPage>
    <IonHeader>
      <IonToolbar>
        <IonButtons slot="start">
          <IonBackButton default-href="/settings" text="Back" />
        </IonButtons>
        <IonTitle>Model Manager</IonTitle>
      </IonToolbar>
    </IonHeader>

    <IonContent :fullscreen="true" class="mm-content">
      <div class="mm-body">

        <!-- Summary strip -->
        <section class="mm-summary" aria-label="Model storage summary">
          <div class="mm-sum-cell">
            <span class="mm-sum-k">Storage</span>
            <span class="mm-sum-v">{{ summary.storageMb }} MB</span>
          </div>
          <div class="mm-sum-cell">
            <span class="mm-sum-k">Queued</span>
            <span class="mm-sum-v">{{ summary.queued }}</span>
          </div>
          <div class="mm-sum-cell">
            <span class="mm-sum-k">Ready</span>
            <span class="mm-sum-v mm-sum-v--ok">{{ summary.ready }}</span>
          </div>
          <div class="mm-sum-cell">
            <span class="mm-sum-k">Error</span>
            <span class="mm-sum-v mm-sum-v--err">{{ summary.error }}</span>
          </div>
          <div class="mm-sum-cell">
            <span class="mm-sum-k">Missing</span>
            <span class="mm-sum-v">{{ summary.missing }}</span>
          </div>
        </section>

        <!-- Master + freeze toggles -->
        <section class="mm-card mm-gates" aria-label="Download gates">
          <div class="mm-gate-row">
            <div class="mm-gate-label">
              <h3 class="mm-gate-t">Sentinel master switch</h3>
              <p class="mm-gate-d">When off, every Sentinel feature and every model download is disabled.</p>
            </div>
            <IonToggle :checked="masterOn" aria-label="Sentinel master switch"
                       @ion-change="onMasterToggle($event)" />
          </div>
          <div class="mm-gate-row">
            <div class="mm-gate-label">
              <h3 class="mm-gate-t">Block all model downloads</h3>
              <p class="mm-gate-d">Freezes every download without turning features off. Useful on metered networks.</p>
            </div>
            <IonToggle :checked="frozen" aria-label="Block all model downloads"
                       @ion-change="onFrozenToggle($event)" />
          </div>
        </section>

        <!-- One card per model -->
        <section v-for="m in models" :key="m.id" class="mm-card mm-model" :aria-labelledby="`mm-model-${m.id}`">
          <header class="mm-model-h">
            <div class="mm-model-meta">
              <h3 :id="`mm-model-${m.id}`" class="mm-model-t">{{ m.label }}</h3>
              <span class="mm-model-size">{{ m.sizeMb ? m.sizeMb + ' MB' : 'built-in' }}</span>
            </div>
            <IonChip :class="['mm-chip', chipClass(m.status)]" outline>
              <IonIcon :icon="chipIcon(m.status)" aria-hidden="true" />
              <span class="mm-chip-txt">{{ chipLabel(m.status) }}</span>
            </IonChip>
          </header>

          <div v-if="m.status === 'downloading'" class="mm-progress">
            <IonProgressBar :value="(m.progressPct || 0) / 100" aria-label="Download progress" />
            <p class="mm-progress-t">
              {{ formatMb(m.bytesSoFar) }} MB of {{ formatMb(m.bytesTotal) }} MB
              <span v-if="m.progressPct != null"> · {{ m.progressPct }}%</span>
            </p>
          </div>

          <p v-if="m.status === 'error'" class="mm-err">
            {{ friendlyError(m.lastError) }}
          </p>

          <p v-if="m.lastUsedAt" class="mm-model-used">
            Last used: {{ formatDate(m.lastUsedAt) }}
          </p>

          <div class="mm-actions">
            <IonButton v-if="canDownload(m)" class="mm-btn" fill="solid" size="small"
                       :aria-label="`Download ${m.label}`"
                       @click="onDownload(m.id)">
              Download
            </IonButton>

            <IonButton v-if="m.status === 'downloading'" class="mm-btn" fill="outline" size="small"
                       :aria-label="`Pause ${m.label}`"
                       @click="onPause(m.id)">
              Pause
            </IonButton>

            <IonButton v-if="m.status === 'downloading'" class="mm-btn" fill="outline" color="danger" size="small"
                       :aria-label="`Cancel download of ${m.label}`"
                       @click="confirmCancel(m)">
              Cancel
            </IonButton>

            <IonButton v-if="m.status === 'queued'" class="mm-btn" fill="outline" size="small"
                       :aria-label="`Resume ${m.label}`"
                       @click="onResume(m.id)">
              Resume
            </IonButton>

            <IonButton v-if="m.status === 'queued'" class="mm-btn" fill="outline" color="medium" size="small"
                       :aria-label="`Cancel queued ${m.label}`"
                       @click="onCancel(m.id)">
              Cancel
            </IonButton>

            <IonButton v-if="m.status === 'error'" class="mm-btn" fill="solid" color="warning" size="small"
                       :aria-label="`Retry ${m.label}`"
                       @click="onRetry(m.id)">
              Retry
            </IonButton>

            <IonButton v-if="m.status === 'ready' || m.status === 'error'" class="mm-btn" fill="outline" color="danger" size="small"
                       :aria-label="`Remove ${m.label}`"
                       @click="confirmDelete(m)">
              Remove
            </IonButton>
          </div>
        </section>

        <div class="mm-spacer" />
      </div>
    </IonContent>
  </IonPage>
</template>

<script setup>
import {
  IonPage, IonHeader, IonToolbar, IonTitle, IonButtons, IonBackButton,
  IonContent, IonIcon, IonButton, IonChip, IonProgressBar, IonToggle,
  alertController,
} from '@ionic/vue'
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import {
  checkmarkCircleOutline, cloudDownloadOutline, warningOutline,
  ellipseOutline, timeOutline, trashOutline, downloadOutline,
} from 'ionicons/icons'

import {
  listModels, subscribeModel, ensureModel,
  cancelDownload, pauseDownload, resumeDownload,
  deleteModel, retryModel, getTelemetry, disposeAll,
  MODEL_STATUS,
} from '@/services/modelManager.js'
import {
  CAPABILITY_KEYS,
  isEnabled as isCapEnabled,
  setEnabled as setCapEnabled,
} from '@/services/capabilities.js'

// ─── State ───────────────────────────────────────────────────────────────
// Filter out internal test fixtures (id-prefixed `__`) — they exist in the
// registry only for vitest to drive the install state machine and must
// never be visible to operators.
const models = ref(listModels().filter(m => !m.__test_fixture))
const masterOn = ref(isCapEnabled(CAPABILITY_KEYS.SENTINEL_MASTER))
const frozen = ref(isCapEnabled(CAPABILITY_KEYS.SENTINEL_FROZEN))

const summary = computed(() => {
  let storageMb = 0, queued = 0, ready = 0, error = 0, missing = 0
  for (const m of models.value) {
    if (m.status === MODEL_STATUS.ready) {
      ready++
      storageMb += m.sizeMb || 0
    } else if (m.status === MODEL_STATUS.queued) queued++
    else if (m.status === MODEL_STATUS.error) error++
    else missing++
  }
  return { storageMb, queued, ready, error, missing }
})

// ─── Refresh ─────────────────────────────────────────────────────────────
function refresh() {
  try { models.value = listModels().filter(m => !m.__test_fixture) } catch { /* no-op */ }
}

function onStateEvent() { refresh() }

// Per-model subscriptions so we update the view on every transition.
const perModelUnsubs = []
function bindPerModel() {
  for (const m of models.value) {
    const off = subscribeModel(m.id, refresh)
    perModelUnsubs.push(off)
  }
}

// ─── Cellular confirm modal (audit fix A1.G19) ──────────────────────────
async function onConfirmNeeded(e) {
  const detail = e?.detail || {}
  const id = detail.id
  const sizeMb = detail.sizeMb
  if (!id) return
  const alert = await alertController.create({
    header: 'Download over cellular?',
    message: `This model is ${sizeMb || '—'} MB. Downloading now will use cellular data.`,
    buttons: [
      {
        text: 'Wait for Wi-Fi',
        role: 'cancel',
        handler: () => {
          try {
            window.dispatchEvent(new CustomEvent('sentinel-model-confirm-response', {
              detail: { id, response: 'wifi-only' },
            }))
          } catch { /* no-op */ }
        },
      },
      {
        text: 'Download now',
        role: 'confirm',
        handler: () => {
          try {
            window.dispatchEvent(new CustomEvent('sentinel-model-confirm-response', {
              detail: { id, response: 'cellular-ok' },
            }))
          } catch { /* no-op */ }
        },
      },
    ],
  })
  await alert.present()
}

// ─── Actions ─────────────────────────────────────────────────────────────
function canDownload(m) {
  return m.status === MODEL_STATUS.missing || m.status === MODEL_STATUS.evicted || m.status === MODEL_STATUS.unknown
}

async function onDownload(id) {
  try { await ensureModel(id) } catch { /* no-op */ }
  refresh()
}
function onPause(id) { pauseDownload(id); refresh() }
function onResume(id) { resumeDownload(id); refresh() }
function onCancel(id) { cancelDownload(id); refresh() }
function onRetry(id) { retryModel(id); refresh() }

// AUDIT FIX B2.05 / Z7.05 / B16.08 — alertController, not window.confirm.
async function confirmDelete(m) {
  const alert = await alertController.create({
    header: 'Remove model?',
    message: `Remove "${m.label}"? You can re-download it later. ${m.sizeMb ? `This frees ${m.sizeMb} MB.` : ''}`,
    buttons: [
      { text: 'Cancel', role: 'cancel' },
      {
        text: 'Remove',
        role: 'destructive',
        handler: () => {
          try { deleteModel(m.id) } catch { /* no-op */ }
          refresh()
        },
      },
    ],
  })
  await alert.present()
}

// AUDIT FIX G3.12 — cancel-while-downloading must confirm.
async function confirmCancel(m) {
  const alert = await alertController.create({
    header: 'Cancel this download?',
    message: 'Progress will be lost. You can restart the download later.',
    buttons: [
      { text: 'Keep downloading', role: 'cancel' },
      {
        text: 'Cancel download',
        role: 'destructive',
        handler: () => {
          try { cancelDownload(m.id) } catch { /* no-op */ }
          refresh()
        },
      },
    ],
  })
  await alert.present()
}

// ─── Gate toggles ────────────────────────────────────────────────────────
function onMasterToggle(e) {
  const v = !!e?.detail?.checked
  masterOn.value = v
  try { setCapEnabled(CAPABILITY_KEYS.SENTINEL_MASTER, v) } catch { /* no-op */ }
  refresh()
}
function onFrozenToggle(e) {
  const v = !!e?.detail?.checked
  frozen.value = v
  try { setCapEnabled(CAPABILITY_KEYS.SENTINEL_FROZEN, v) } catch { /* no-op */ }
  refresh()
}

// ─── Chip presentation ───────────────────────────────────────────────────
function chipClass(status) {
  switch (status) {
    case MODEL_STATUS.ready:       return 'mm-chip--ok'
    case MODEL_STATUS.downloading: return 'mm-chip--warn'
    case MODEL_STATUS.queued:      return 'mm-chip--info'
    case MODEL_STATUS.error:       return 'mm-chip--err'
    case MODEL_STATUS.evicted:     return 'mm-chip--muted'
    default:                       return 'mm-chip--muted'
  }
}
function chipIcon(status) {
  switch (status) {
    case MODEL_STATUS.ready:       return checkmarkCircleOutline
    case MODEL_STATUS.downloading: return cloudDownloadOutline
    case MODEL_STATUS.queued:      return timeOutline
    case MODEL_STATUS.error:       return warningOutline
    case MODEL_STATUS.evicted:     return trashOutline
    default:                       return ellipseOutline
  }
}
function chipLabel(status) {
  switch (status) {
    case MODEL_STATUS.ready:       return 'Ready'
    case MODEL_STATUS.downloading: return 'Downloading'
    case MODEL_STATUS.queued:      return 'Queued'
    case MODEL_STATUS.error:       return 'Error'
    case MODEL_STATUS.evicted:     return 'Removed'
    case MODEL_STATUS.missing:     return 'Not downloaded'
    default:                       return 'Unknown'
  }
}

// ─── Helpers ─────────────────────────────────────────────────────────────
function formatMb(bytes) {
  if (!bytes || !Number.isFinite(bytes)) return '0.0'
  return (bytes / 1_000_000).toFixed(1)
}
function formatDate(ts) {
  if (!ts) return ''
  try { return new Date(ts).toLocaleString() } catch { return '' }
}
function friendlyError(reason) {
  switch (reason) {
    case 'native-plugin-not-yet-built':
      return 'This model needs a newer app build. Please reinstall the app to continue.'
    case 'max-retries':       return 'Download failed after several attempts. Tap Retry to try again.'
    case 'offline':           return 'Offline — queued. The download will resume automatically.'
    case 'waiting-for-wifi':  return 'Waiting for Wi-Fi.'
    case 'cancelled':         return 'Cancelled.'
    case 'paused':            return 'Paused.'
    case 'downloads-frozen':  return 'Downloads are frozen in Settings.'
    case 'sentinel-master-off': return 'Sentinel is off in Settings.'
    default:                  return reason ? `Error: ${reason}` : 'Download failed.'
  }
}

// ─── Lifecycle ───────────────────────────────────────────────────────────
onMounted(() => {
  refresh()
  bindPerModel()
  if (typeof window !== 'undefined') {
    window.addEventListener('sentinel-model-state', onStateEvent)
    window.addEventListener('sentinel-model-confirm-needed', onConfirmNeeded)
    window.addEventListener('capability-changed', refreshGates)
  }
})

function refreshGates() {
  try {
    masterOn.value = isCapEnabled(CAPABILITY_KEYS.SENTINEL_MASTER)
    frozen.value = isCapEnabled(CAPABILITY_KEYS.SENTINEL_FROZEN)
  } catch { /* no-op */ }
}

// CRITICAL AUDIT FIX D5.01 — disposeAll MUST be called or retry timers leak.
onUnmounted(() => {
  try { disposeAll() } catch { /* no-op */ }
  for (const off of perModelUnsubs) { try { off() } catch {} }
  perModelUnsubs.length = 0
  if (typeof window !== 'undefined') {
    try { window.removeEventListener('sentinel-model-state', onStateEvent) } catch {}
    try { window.removeEventListener('sentinel-model-confirm-needed', onConfirmNeeded) } catch {}
    try { window.removeEventListener('capability-changed', refreshGates) } catch {}
  }
})
</script>

<style scoped>
.mm-content {
  --background: var(--ion-background-color, #f5f7fa);
}
.mm-body {
  padding: 16px 12px 48px;
  max-width: 760px;
  margin: 0 auto;
}

/* Summary strip */
.mm-summary {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 6px;
  background: var(--ion-color-light, #fff);
  border: 1px solid var(--ion-color-light-shade, #e5e7eb);
  border-radius: 12px;
  padding: 10px;
  margin-bottom: 16px;
}
.mm-sum-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 6px 2px;
  border-right: 1px solid var(--ion-color-light-shade, #e5e7eb);
}
.mm-sum-cell:last-child { border-right: none; }
.mm-sum-k {
  font-size: 11px;
  color: var(--ion-color-medium, #6b7280);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.mm-sum-v {
  font-size: 18px;
  font-weight: 600;
  color: var(--ion-text-color, #111827);
}
.mm-sum-v--ok  { color: var(--ion-color-success, #16a34a); }
.mm-sum-v--err { color: var(--ion-color-danger, #dc2626); }

/* Card */
.mm-card {
  background: var(--ion-color-light, #fff);
  border: 1px solid var(--ion-color-light-shade, #e5e7eb);
  border-radius: 12px;
  padding: 14px;
  margin-bottom: 12px;
}

/* Gate rows */
.mm-gates .mm-gate-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid var(--ion-color-light-shade, #e5e7eb);
}
.mm-gates .mm-gate-row:last-child { border-bottom: none; }
.mm-gate-label { flex: 1 1 auto; }
.mm-gate-t {
  font-size: 15px;
  font-weight: 600;
  margin: 0 0 2px;
  color: var(--ion-text-color, #111827);
}
.mm-gate-d {
  font-size: 13px;
  color: var(--ion-color-medium, #6b7280);
  margin: 0;
}

/* Model card */
.mm-model-h {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 10px;
  margin-bottom: 10px;
}
.mm-model-meta { flex: 1 1 auto; min-width: 0; }
.mm-model-t {
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 2px;
  color: var(--ion-text-color, #111827);
}
.mm-model-size {
  font-size: 12px;
  color: var(--ion-color-medium, #6b7280);
}
.mm-chip {
  flex-shrink: 0;
  font-size: 12px;
}
.mm-chip-txt { margin-left: 4px; }
.mm-chip--ok    { color: var(--ion-color-success, #16a34a); border-color: var(--ion-color-success, #16a34a); }
.mm-chip--warn  { color: var(--ion-color-warning, #d97706); border-color: var(--ion-color-warning, #d97706); }
.mm-chip--info  { color: var(--ion-color-primary, #2563eb); border-color: var(--ion-color-primary, #2563eb); }
.mm-chip--err   { color: var(--ion-color-danger, #dc2626); border-color: var(--ion-color-danger, #dc2626); }
.mm-chip--muted { color: var(--ion-color-medium, #6b7280); border-color: var(--ion-color-medium, #6b7280); }

.mm-progress { margin: 8px 0; }
.mm-progress-t {
  font-size: 12px;
  color: var(--ion-color-medium, #6b7280);
  margin: 4px 0 0;
}

.mm-err {
  font-size: 13px;
  color: var(--ion-color-danger, #dc2626);
  margin: 4px 0 8px;
}

.mm-model-used {
  font-size: 12px;
  color: var(--ion-color-medium, #6b7280);
  margin: 4px 0 10px;
}

/* Actions — audit fix G15.05: min 44px tap target, visible text. */
.mm-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;
}
.mm-btn {
  min-height: 44px;
  --padding-start: 14px;
  --padding-end: 14px;
}

.mm-spacer { height: 48px; }

@media (max-width: 480px) {
  .mm-summary { grid-template-columns: repeat(5, 1fr); font-size: 11px; }
  .mm-sum-v { font-size: 15px; }
}
</style>
