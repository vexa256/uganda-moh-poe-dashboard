<template>
  <Teleport to="body">
    <transition name="al-fade">
      <div v-if="open" class="al-root" role="dialog" aria-modal="true" aria-label="App lock">
        <div class="al-bg" aria-hidden="true"/>
        <div class="al-card">
          <div class="al-brand">
            <div class="al-shield" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                <path d="M12 2l8 3v7c0 5-3.5 9.7-8 10-4.5-.3-8-5-8-10V5l8-3zm0 6a2 2 0 0 0-1 3.7V14a1 1 0 0 0 2 0v-2.3A2 2 0 0 0 12 8z"/>
              </svg>
            </div>
            <div class="al-brand-text">
              <span class="al-eyebrow">POE SENTINEL</span>
              <span class="al-title">{{ mode === 'setup' ? 'Set your PIN' : 'Unlock to continue' }}</span>
              <span class="al-sub">{{ subtitle }}</span>
            </div>
          </div>

          <div class="al-dots" aria-hidden="true">
            <span v-for="i in pinLength" :key="i" :class="['al-dot', pin.length >= i && 'al-dot--on']"/>
          </div>

          <div v-if="error" class="al-err" role="alert">{{ error }}</div>

          <div class="al-pad">
            <button v-for="k in keys" :key="k" type="button" class="al-key"
              :class="{ 'al-key--bio': k === 'bio', 'al-key--back': k === 'back' }"
              :disabled="k === 'bio' && !bioAvailable"
              :aria-label="keyLabel(k)"
              @click="onKey(k)">
              <template v-if="k === 'bio'">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 2a7 7 0 0 0-7 7v1a1 1 0 1 0 2 0V9a5 5 0 0 1 10 0v1a1 1 0 1 0 2 0V9a7 7 0 0 0-7-7zM6 13a1 1 0 0 0-1 1c0 3.3 1.8 6.3 4.7 7.9a1 1 0 0 0 1-1.7A8 8 0 0 1 7 14a1 1 0 0 0-1-1zm12 0a1 1 0 0 0-1 1 6 6 0 0 1-3.3 5.3 1 1 0 1 0 1 1.7C17.2 20.4 19 17.4 19 14a1 1 0 0 0-1-1zm-6-3a2 2 0 0 0-2 2v3a1 1 0 1 0 2 0v-3h2v4a1 1 0 1 0 2 0v-4a2 2 0 0 0-2-2h-2z"/></svg>
              </template>
              <template v-else-if="k === 'back'">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M22 6H9.4a2 2 0 0 0-1.5.7L2 12l5.9 5.3c.4.4.9.7 1.5.7H22V6zm-7.3 10.3l-1.4 1.4L11 15.4l-2.3 2.3-1.4-1.4L9.6 14l-2.3-2.3 1.4-1.4L11 12.6l2.3-2.3 1.4 1.4L12.4 14l2.3 2.3z"/></svg>
              </template>
              <template v-else-if="k === ''">&nbsp;</template>
              <template v-else>{{ k }}</template>
            </button>
          </div>

          <div class="al-foot">
            <button v-if="mode === 'verify'" class="al-link" type="button" @click="onForgot">Forgot PIN?</button>
            <button v-else class="al-link" type="button" @click="onCancelSetup">Cancel</button>
          </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup>
/**
 * AppLockScreen — premium numeric lock UI with optional biometric quick-unlock.
 *
 * Consumed by App.vue via a `v-model:open` style pattern using `open` prop
 * + emit('unlocked'), emit('forgot'), emit('cancel-setup').
 *
 * Modes:
 *   - 'verify' : user has a PIN; on correct PIN we emit 'unlocked'.
 *   - 'setup'  : first-run — user enters a PIN twice (confirm step) and emits 'unlocked'.
 */
import { ref, computed, onMounted, watch } from 'vue'
import {
  hasPin, enrollPin, verifyPin,
  biometricAvailable, isBiometricEnabled, verifyBiometric,
} from '@/services/plugins/biometric'
import { hapticError, hapticSuccess, hapticLight } from '@/services/haptics'

const props = defineProps({
  open: { type: Boolean, default: false },
  forceSetup: { type: Boolean, default: false },
})
const emit = defineEmits(['unlocked', 'forgot', 'cancel-setup'])

const mode = ref('verify')        // 'verify' | 'setup'
const subtitle = ref('Enter your PIN')
const pin = ref('')
const pinLength = 6
const error = ref('')
const setupFirst = ref('')
const bioAvailable = ref(false)

const keys = ['1','2','3','4','5','6','7','8','9','bio','0','back']

function keyLabel(k) {
  if (k === 'bio') return 'Use biometric'
  if (k === 'back') return 'Delete last digit'
  return `Digit ${k}`
}

function reset() {
  pin.value = ''
  error.value = ''
  setupFirst.value = ''
}

async function initialiseMode() {
  reset()
  const needSetup = props.forceSetup || !hasPin()
  mode.value = needSetup ? 'setup' : 'verify'
  subtitle.value = needSetup
    ? 'Choose a 4 to 10-digit PIN. You\'ll use it to unlock the app.'
    : 'Use your PIN or biometric to unlock.'
  const b = await biometricAvailable()
  bioAvailable.value = !!b?.available && isBiometricEnabled()
  // If biometric is already enabled and we're in verify mode, auto-prompt.
  if (mode.value === 'verify' && bioAvailable.value) {
    setTimeout(tryBiometric, 350)
  }
}

async function tryBiometric() {
  const ok = await verifyBiometric({ reason: 'Unlock POE Screening', title: 'Unlock' })
  if (ok) {
    try { hapticSuccess() } catch {}
    emit('unlocked', { via: 'biometric' })
  }
}

function onKey(k) {
  if (!props.open) return
  error.value = ''
  try { hapticLight() } catch {}
  if (k === 'back') { pin.value = pin.value.slice(0, -1); return }
  if (k === 'bio')  { tryBiometric(); return }
  if (!/^[0-9]$/.test(k)) return
  if (pin.value.length >= pinLength) return
  pin.value += k
  // Auto-submit at min length 4; user can keep typing up to 6 for extra entropy.
  if (mode.value === 'verify' && pin.value.length >= 4) {
    setTimeout(trySubmit, 130)
  }
  if (mode.value === 'setup' && pin.value.length >= 4 && pin.value.length === pinLength) {
    setTimeout(trySubmit, 130)
  }
}

async function trySubmit() {
  if (mode.value === 'verify') {
    const ok = await verifyPin(pin.value)
    if (ok) {
      try { hapticSuccess() } catch {}
      emit('unlocked', { via: 'pin' })
      reset()
    } else {
      try { hapticError() } catch {}
      error.value = 'Incorrect PIN — try again.'
      pin.value = ''
    }
  } else {
    if (!setupFirst.value) {
      if (pin.value.length < 4) { error.value = 'PIN must be at least 4 digits.'; return }
      setupFirst.value = pin.value
      subtitle.value = 'Re-enter to confirm'
      pin.value = ''
      return
    }
    if (pin.value !== setupFirst.value) {
      try { hapticError() } catch {}
      error.value = 'PINs didn\'t match — start again.'
      setupFirst.value = ''
      pin.value = ''
      subtitle.value = 'Choose a 4 to 10-digit PIN.'
      return
    }
    const saved = await enrollPin(pin.value)
    if (!saved) { error.value = 'Could not save PIN. Try a different one.'; pin.value = ''; return }
    try { hapticSuccess() } catch {}
    emit('unlocked', { via: 'pin-setup' })
    reset()
  }
}

function onForgot() { emit('forgot') }
function onCancelSetup() { reset(); emit('cancel-setup') }

watch(() => props.open, (v) => { if (v) initialiseMode() })
onMounted(() => { if (props.open) initialiseMode() })
</script>

<style scoped>
.al-root {
  position: fixed; inset: 0; z-index: 10040;
  display: flex; align-items: center; justify-content: center;
  padding: calc(24px + env(safe-area-inset-top, 0)) 16px calc(24px + env(safe-area-inset-bottom, 0));
}
.al-bg {
  position: absolute; inset: 0;
  background:
    radial-gradient(120% 80% at 20% 0%, rgba(0,180,166,.22) 0%, rgba(0,180,166,0) 60%),
    linear-gradient(180deg, #0B2545 0%, #040A18 100%);
}
.al-card {
  position: relative;
  width: 100%; max-width: 360px;
  background: rgba(255,255,255,.06);
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  border: 1px solid rgba(255,255,255,.08);
  border-radius: 20px;
  padding: 22px 18px 18px;
  color: #EDF2FA;
  box-shadow: 0 24px 60px rgba(0,0,0,.45);
}
.al-brand { display: flex; gap: 12px; align-items: center; margin-bottom: 18px; }
.al-shield {
  width: 48px; height: 48px; border-radius: 14px; flex-shrink: 0;
  background: linear-gradient(135deg, #00B4A6 0%, #0B2545 100%);
  color: #fff; display: flex; align-items: center; justify-content: center;
  box-shadow: 0 8px 18px rgba(0,180,166,.35);
}
.al-brand-text { display: flex; flex-direction: column; min-width: 0; }
.al-eyebrow { font-size: 10px; font-weight: 800; letter-spacing: 1.4px; color: rgba(0,230,214,.7); }
.al-title   { font-size: 17px; font-weight: 800; color: #fff; margin-top: 2px; }
.al-sub     { font-size: 12px; color: rgba(255,255,255,.6); margin-top: 2px; line-height: 1.4; }

.al-dots { display: flex; gap: 10px; justify-content: center; padding: 12px 0 6px; }
.al-dot { width: 12px; height: 12px; border-radius: 50%; background: rgba(255,255,255,.18); transition: background .15s, transform .15s; }
.al-dot--on { background: #00E6D6; transform: scale(1.15); box-shadow: 0 0 10px rgba(0,230,214,.5); }

.al-err { text-align: center; color: #FFB4B4; font-size: 12px; margin: 8px 0 0; min-height: 16px; }

.al-pad {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px;
  margin-top: 14px;
}
.al-key {
  background: rgba(255,255,255,.07);
  color: #fff; border: 1px solid rgba(255,255,255,.06);
  border-radius: 14px;
  height: 58px; font-size: 22px; font-weight: 700;
  cursor: pointer; transition: transform .08s, background .15s;
  display: flex; align-items: center; justify-content: center;
}
.al-key:hover:not(:disabled) { background: rgba(255,255,255,.12); }
.al-key:active:not(:disabled) { transform: scale(0.97); background: rgba(0,230,214,.18); }
.al-key:disabled { opacity: .3; cursor: not-allowed; }
.al-key--bio { color: #00E6D6; }
.al-key--back { color: #FCA5A5; }

.al-foot { display: flex; justify-content: center; padding-top: 14px; }
.al-link {
  background: transparent; border: none; color: rgba(255,255,255,.7);
  font-size: 12px; font-weight: 600; cursor: pointer; text-decoration: underline;
}
.al-link:hover { color: #fff; }

.al-fade-enter-active, .al-fade-leave-active { transition: opacity .25s ease; }
.al-fade-enter-from, .al-fade-leave-to { opacity: 0; }
</style>
