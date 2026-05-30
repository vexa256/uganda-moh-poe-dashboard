<template>
  <!--
    Passive OTA banner. Three states:
      1. Update downloaded     → action: "Apply now" (reload)
      2. Update available      → action: "Download" (or auto)
      3. Update failed         → action: "Retry"
    Hides itself otherwise. Tap-target full width, dismissable.
    Sits at the very top, above everything else, fixed position.
  -->
  <transition name="ub-fade">
    <div
      v-if="visible"
      class="ub-banner"
      :class="['ub-banner--' + variant]"
      role="status"
      aria-live="polite"
    >
      <div class="ub-icon" aria-hidden="true">
        <span v-if="variant === 'ready'">⬆️</span>
        <span v-else-if="variant === 'available'">✨</span>
        <span v-else-if="variant === 'failed'">⚠️</span>
        <span v-else>🔄</span>
      </div>
      <div class="ub-body">
        <div class="ub-title">{{ title }}</div>
        <div class="ub-msg">{{ message }}</div>
      </div>
      <button
        v-if="action"
        type="button"
        class="ub-btn"
        :disabled="busy"
        @click="onAction"
      >{{ busy ? 'Working…' : action }}</button>
      <button
        type="button"
        class="ub-x"
        aria-label="Dismiss"
        @click="dismiss"
      >×</button>
    </div>
  </transition>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useAppUpdates } from '@/composables/useAppUpdates'

const upd = useAppUpdates()
const busy = ref(false)
const dismissed = ref(false)
// Re-show after 24h of dismissal so a long-running install gets nudged again
const DISMISS_TTL_MS = 24 * 60 * 60 * 1000
const DISMISS_KEY = 'ug_poe_ota_banner_dismissed_at'

;(function hydrateDismiss() {
  try {
    const ts = Number(localStorage.getItem(DISMISS_KEY) || 0)
    if (ts && (Date.now() - ts) < DISMISS_TTL_MS) dismissed.value = true
  } catch { /* fine */ }
})()

// Re-show automatically when state transitions (new download, new failure)
watch([() => upd.lastResult.value, () => upd.lastError.value], () => {
  dismissed.value = false
})

const variant = computed(() => {
  if (upd.lastResult.value === 'failed')    return 'failed'
  if (upd.downloaded.value)                  return 'ready'
  if (upd.updateAvailable.value)             return 'available'
  if (upd.lastResult.value === 'breaking')   return 'available'
  return 'idle'
})

const visible = computed(() => {
  if (dismissed.value) return false
  if (!upd.isNative.value) return false
  return variant.value === 'ready' || variant.value === 'failed' || variant.value === 'available'
})

const title = computed(() => {
  switch (variant.value) {
    case 'ready':     return 'Update ready'
    case 'available': return upd.lastResult.value === 'breaking' ? 'New version requires app store update' : 'Update available'
    case 'failed':    return 'Update failed'
    default:          return ''
  }
})
const message = computed(() => {
  const v = upd.latestKnown.value?.version
  switch (variant.value) {
    case 'ready':     return v ? `Version ${v} downloaded. Tap to apply.` : 'A new version is downloaded.'
    case 'available':
      if (upd.lastResult.value === 'breaking') {
        return 'Please install the latest APK from the store.'
      }
      return upd.isDownloading.value
        ? `Downloading${upd.downloadPct.value ? ' ' + upd.downloadPct.value + '%' : '…'}`
        : (v ? `Version ${v} is ready to download.` : 'A new version is available.')
    case 'failed':    return upd.lastError.value || 'The last update attempt did not complete.'
    default:          return ''
  }
})
const action = computed(() => {
  switch (variant.value) {
    case 'ready':     return 'Apply now'
    case 'available': return upd.lastResult.value === 'breaking' ? null : (upd.isDownloading.value ? null : 'Check again')
    case 'failed':    return 'Retry'
    default:          return null
  }
})

async function onAction() {
  if (busy.value) return
  busy.value = true
  try {
    if (variant.value === 'ready') {
      await upd.applyDownloadedNow()
    } else {
      await upd.checkNow()
    }
  } finally { busy.value = false }
}

function dismiss() {
  dismissed.value = true
  try { localStorage.setItem(DISMISS_KEY, String(Date.now())) } catch { /* ignore */ }
}
</script>

<style scoped>
/*
 * Sits BELOW the IonHeader, full-width, slides down. Visual language
 * borrows from the rest of the app's amber/blue/red palette already
 * used for warnings + actions in views/PrimaryScreening.
 */
.ub-banner {
  position: fixed;
  top: env(safe-area-inset-top, 0);
  left: 0; right: 0;
  z-index: 9999;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  font-size: 13px;
  line-height: 1.3;
  box-shadow: 0 2px 8px rgba(0,0,0,0.12);
  color: #0B2545;
  background: #E3F2FD;
  border-bottom: 1px solid #90CAF9;
}
.ub-banner--ready {
  background: #E8F5E9;
  border-bottom-color: #81C784;
}
.ub-banner--available {
  background: #E3F2FD;
  border-bottom-color: #64B5F6;
}
.ub-banner--failed {
  background: #FFF3E0;
  border-bottom-color: #FFB74D;
  color: #5D2A00;
}
.ub-icon { font-size: 20px; flex: 0 0 auto; }
.ub-body { flex: 1 1 auto; min-width: 0; }
.ub-title { font-weight: 700; font-size: 13px; }
.ub-msg { font-size: 12px; opacity: .85; margin-top: 1px; }
.ub-btn {
  flex: 0 0 auto;
  height: 32px; padding: 0 12px;
  border-radius: 999px;
  border: none;
  background: #1565C0; color: #fff;
  font-size: 12px; font-weight: 600;
  cursor: pointer;
}
.ub-btn:disabled { opacity: .5; cursor: not-allowed; }
.ub-banner--ready  .ub-btn { background: #2E7D32; }
.ub-banner--failed .ub-btn { background: #E65100; }
.ub-x {
  flex: 0 0 auto;
  width: 28px; height: 28px;
  display: grid; place-items: center;
  border: none; background: transparent;
  font-size: 20px; color: inherit; opacity: .55;
  cursor: pointer;
}
.ub-x:hover { opacity: 1; }

.ub-fade-enter-active, .ub-fade-leave-active { transition: transform .2s ease, opacity .2s ease; }
.ub-fade-enter-from, .ub-fade-leave-to { transform: translateY(-20px); opacity: 0; }
</style>
