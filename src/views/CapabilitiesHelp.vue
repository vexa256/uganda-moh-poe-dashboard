<template>
  <IonPage>
    <IonHeader class="ch-hdr" translucent>
      <div class="ch-hdr-bg">
        <div class="ch-hdr-top">
          <IonButtons slot="start"><IonMenuButton menu="app-menu" class="ch-menu"/></IonButtons>
          <div class="ch-hdr-title">
            <span class="ch-eye">SYSTEM</span>
            <span class="ch-h1">Capabilities &amp; Help</span>
          </div>
          <IonButtons slot="end"><IonButton fill="clear" @click="playWelcome" aria-label="Replay welcome tour"><IonIcon :icon="sparklesOutline"/></IonButton></IonButtons>
        </div>
        <p class="ch-sub">Everything new on this device. Toggle any feature, see its status, and replay its in-context tour.</p>
      </div>
    </IonHeader>

    <IonContent class="ch-content" :fullscreen="true">
      <div class="ch-body">
        <div class="ch-banner" @click="playWelcome">
          <div class="ch-banner-ico" aria-hidden="true">
            <IonIcon :icon="sparklesOutline"/>
          </div>
          <div class="ch-banner-text">
            <div class="ch-banner-t">Replay the welcome tour</div>
            <div class="ch-banner-d">5 quick cards — takes under 30 seconds.</div>
          </div>
          <IonIcon :icon="chevronForwardOutline" class="ch-banner-arrow"/>
        </div>

        <div v-for="group in groups" :key="group.id" class="ch-group">
          <div class="ch-group-h">{{ group.title }}</div>
          <div v-for="f in group.features" :key="f.id" :id="f.anchorId || undefined" class="ch-card">
            <div class="ch-card-row">
              <div class="ch-card-ico" :style="{ background: f.iconBg }" aria-hidden="true">
                <IonIcon :icon="f.icon"/>
              </div>
              <div class="ch-card-main">
                <div class="ch-card-t">{{ f.title }}</div>
                <div class="ch-card-d">{{ f.description }}</div>
                <div class="ch-card-status">
                  <span :class="['ch-dot', statusClass(statuses[f.id])]" aria-hidden="true"/>
                  <span class="ch-status-t">{{ statusLabel(statuses[f.id]) }}</span>
                </div>
              </div>
              <div v-if="f.toggleKey" class="ch-card-toggle" @click.stop="toggle(f.toggleKey)" role="switch" :aria-checked="toggles[f.toggleKey]">
                <span :class="['ch-tg', toggles[f.toggleKey] && 'ch-tg--on']"><span class="ch-tg-dot"/></span>
              </div>
            </div>
            <div class="ch-card-body">
              <div class="ch-card-how">
                <div class="ch-card-how-h">How to use</div>
                <ol class="ch-card-how-l">
                  <li v-for="s in f.howTo" :key="s">{{ s }}</li>
                </ol>
              </div>
              <div v-if="f.troubleshoot && f.troubleshoot.length" class="ch-card-how">
                <div class="ch-card-how-h">Troubleshooting</div>
                <ul class="ch-card-how-l">
                  <li v-for="t in f.troubleshoot" :key="t.q"><strong>{{ t.q }}</strong> — {{ t.a }}</li>
                </ul>
              </div>
              <div class="ch-actions">
                <button v-if="f.demo" class="ch-btn ch-btn--primary" type="button" @click="f.demo()">{{ f.demoLabel || 'Try it now' }}</button>
                <button v-if="f.tourSteps" class="ch-btn ch-btn--ghost" type="button" @click="runFeatureTour(f)">Show me in the app</button>
              </div>
              <div v-if="demoOut[f.id]" class="ch-demo-out">{{ demoOut[f.id] }}</div>
            </div>
          </div>
        </div>
        <div style="height:48px"/>
      </div>
    </IonContent>

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="1800" position="top" @didDismiss="toast.show=false"/>
  </IonPage>
</template>

<script setup>
/**
 * CapabilitiesHelp.vue — dedicated in-app docs + live probe view.
 *
 * For each feature:
 *   - Shows human-readable status (Ready / Off / Unsupported / Permission needed).
 *   - Lets the user toggle the capability (writes through capabilities.js).
 *   - Offers a "Try it now" demo that exercises the wrapper's happy path
 *     WITHOUT requiring a real screening flow — every demo is a short probe.
 *   - Offers "Show me in the app" which plays a spotlight on the relevant
 *     sidebar entry or on-screen anchor via the tour system.
 */
import { ref, reactive, onMounted, onUnmounted } from 'vue'
import {
  IonPage, IonHeader, IonContent, IonButtons, IonMenuButton, IonButton, IonIcon, IonToast,
} from '@ionic/vue'
import {
  sparklesOutline, chevronForwardOutline,
  wifiOutline, flashlightOutline, qrCodeOutline, notificationsOutline, shareOutline,
  lockClosedOutline, callOutline, phonePortraitOutline,
} from 'ionicons/icons'

import { CAPABILITY_KEYS, isEnabled, setEnabled, snapshot, subscribe } from '@/services/capabilities'
import { maybeRunWelcomeTour, replayWelcomeTour, runSteps } from '@/services/tour'
import { hapticCritical, hapticLight, hapticSuccess, hapticWarning, hapticError } from '@/services/haptics'
import * as net from '@/services/plugins/network'
import * as keepAwake from '@/services/plugins/keepAwake'
// 2026-05-07: speech recognition import removed; voice feature retired app-wide.
import * as barcode from '@/services/plugins/barcode'
import * as localNotifs from '@/services/plugins/localNotifications'
import { sharePdf, isAvailable as shareAvail } from '@/services/plugins/share'
import * as bio from '@/services/plugins/biometric'

const toast = reactive({ show: false, msg: '', color: 'success' })
function showToast(msg, color = 'success') { toast.msg = msg; toast.color = color; toast.show = true }

const toggles = reactive({
  [CAPABILITY_KEYS.HAPTICS]:       isEnabled(CAPABILITY_KEYS.HAPTICS),
  [CAPABILITY_KEYS.NETWORK]:       isEnabled(CAPABILITY_KEYS.NETWORK),
  [CAPABILITY_KEYS.KEEPAWAKE]:     isEnabled(CAPABILITY_KEYS.KEEPAWAKE),
  [CAPABILITY_KEYS.BARCODE]:       isEnabled(CAPABILITY_KEYS.BARCODE),
  [CAPABILITY_KEYS.LOCAL_NOTIFS]:  isEnabled(CAPABILITY_KEYS.LOCAL_NOTIFS),
  [CAPABILITY_KEYS.PDF_SHARE]:     isEnabled(CAPABILITY_KEYS.PDF_SHARE),
  [CAPABILITY_KEYS.APP_LOCK]:      isEnabled(CAPABILITY_KEYS.APP_LOCK),
  [CAPABILITY_KEYS.DIRECTORY]:     isEnabled(CAPABILITY_KEYS.DIRECTORY),
})

const statuses = reactive({})
const demoOut = reactive({})

function toggle(key) {
  toggles[key] = !toggles[key]
  setEnabled(key, toggles[key])
  try { hapticLight() } catch {}
  showToast((toggles[key] ? 'Enabled: ' : 'Disabled: ') + labelForKey(key))
  refreshStatuses()
}

function labelForKey(k) {
  return ({
    [CAPABILITY_KEYS.HAPTICS]: 'Haptics',
    [CAPABILITY_KEYS.NETWORK]: 'Network monitor',
    [CAPABILITY_KEYS.KEEPAWAKE]: 'Keep awake',
    [CAPABILITY_KEYS.BARCODE]: 'Barcode/QR scan',
    [CAPABILITY_KEYS.LOCAL_NOTIFS]: 'Local reminders',
    [CAPABILITY_KEYS.PDF_SHARE]: 'PDF share',
    [CAPABILITY_KEYS.APP_LOCK]: 'App lock',
    [CAPABILITY_KEYS.DIRECTORY]: 'Directory',
  })[k] || k
}

const features = [
  {
    id: 'haptics', title: 'Haptic feedback', icon: phonePortraitOutline, iconBg: 'linear-gradient(135deg,#0B2545,#13315C)',
    description: 'Subtle vibration on critical alerts and validation errors so you know something needs attention without looking.',
    toggleKey: CAPABILITY_KEYS.HAPTICS,
    howTo: [
      'Fires when a secondary screening classifies as CRITICAL or HIGH risk.',
      'Fires when a primary screening form fails validation.',
      'Turn it off here if you find it distracting.',
    ],
    troubleshoot: [{ q: 'Nothing vibrates', a: 'Some devices disable vibration in silent / DND mode.' }],
    demoLabel: 'Buzz critical', demo: async () => { hapticCritical(); demoOut.haptics = 'Fired CRITICAL pattern.' },
  },
  {
    id: 'network', title: 'Accurate connectivity', icon: wifiOutline, iconBg: 'linear-gradient(135deg,#2563EB,#0891B2)',
    description: 'Uses a native connectivity probe (instead of the unreliable browser flag) so the app knows when to queue vs sync.',
    toggleKey: CAPABILITY_KEYS.NETWORK,
    howTo: [
      'Runs in the background — nothing to configure.',
      'The header pill updates automatically.',
      'Sync Queue resumes when real connectivity returns.',
    ],
    troubleshoot: [{ q: 'Pill says offline on wifi', a: 'You may be on a captive-portal network — open the browser and accept the T&Cs.' }],
    demoLabel: 'Check now', demo: async () => {
      const s = await net.getStatus(); demoOut.network = `Connected: ${s.connected} · Type: ${s.connectionType || 'n/a'}`
    },
  },
  {
    id: 'keepawake', title: 'Keep awake during screening', icon: flashlightOutline, iconBg: 'linear-gradient(135deg,#D97706,#F59E0B)',
    description: 'Prevents the screen from locking while you\'re in a long secondary-screening interview.',
    toggleKey: CAPABILITY_KEYS.KEEPAWAKE,
    howTo: [
      'Activates automatically when you open a Secondary Screening case.',
      'Releases when you leave the view, or after 30 minutes max.',
    ],
    troubleshoot: [{ q: 'Screen still locks', a: 'Your device may force-sleep when battery is very low — charge above 20%.' }],
    demoLabel: 'Activate 5 s', demo: async () => {
      const ok = await keepAwake.activate(5_000); demoOut.keepawake = ok ? 'Active for 5 s (watch the screen).' : 'Plugin not available on this device.'
      setTimeout(() => keepAwake.deactivate(), 5_000)
    },
  },
  {
    id: 'barcode', title: 'Barcode / QR scan', icon: qrCodeOutline, iconBg: 'linear-gradient(135deg,#059669,#10B981)',
    description: 'Scan a health-declaration QR, a yellow-fever card code, or a passport barcode to auto-fill identity fields.',
    toggleKey: CAPABILITY_KEYS.BARCODE,
    howTo: [
      'Tap the scan icon in a Primary or Secondary Screening form.',
      'Hold the code within the frame.',
      'The value fills in automatically; verify before submitting.',
    ],
    troubleshoot: [{ q: 'Scanner stays blank', a: 'Grant camera permission; the first scan downloads Google\'s on-device model (~20 MB).' }],
    demoLabel: 'Scan a code', demo: async () => {
      const r = await barcode.scanOnce()
      demoOut.barcode = r ? `Scanned (${r.format}): ${r.value.slice(0, 60)}` : 'Nothing scanned (cancelled or unsupported).'
    },
  },
  {
    id: 'localnotifs', title: 'Follow-up reminders', icon: notificationsOutline, iconBg: 'linear-gradient(135deg,#DB2777,#E11D48)',
    description: 'Reminds you about due follow-ups on this device — no server required.',
    toggleKey: CAPABILITY_KEYS.LOCAL_NOTIFS,
    howTo: [
      'Grant notification permission once.',
      'When a follow-up is scheduled, a reminder fires at the due time.',
      'Tap the notification to jump to the case.',
    ],
    troubleshoot: [{ q: 'No notification fired', a: 'Enable notifications for this app in Android Settings → Apps → POE Screening.' }],
    demoLabel: 'Fire in 10 s', demo: async () => {
      const ok = await localNotifs.schedule(99901, 'POE Screening', 'Demo reminder — everything is wired.', new Date(Date.now() + 10_500))
      demoOut.localnotifs = ok ? 'Scheduled — stays in background.' : 'Not scheduled (permission denied or not supported).'
    },
  },
  {
    id: 'pdfshare', title: 'PDF share', icon: shareOutline, iconBg: 'linear-gradient(135deg,#0EA5E9,#0284C7)',
    description: 'Export a clinical report PDF and share via WhatsApp, email, or any compatible app.',
    toggleKey: CAPABILITY_KEYS.PDF_SHARE,
    howTo: [
      'Open an Alert or Clinical Report.',
      'Tap the share icon — the OS chooser lets you send to any app.',
    ],
    troubleshoot: [{ q: 'Nothing happens', a: 'On web, this falls back to a file download; on device, ensure at least one receiving app is installed.' }],
    demoLabel: 'Share a test PDF', demo: async () => {
      const data = new Blob([`POE Screening test PDF — ${new Date().toISOString()}`], { type: 'application/pdf' })
      const r = await sharePdf(data, { filename: 'poe-test.pdf', title: 'POE Screening Test', dialogTitle: 'Share test' })
      demoOut.pdfshare = r?.shared ? `Shared via ${r.target}` : `Failed: ${r?.reason || 'unknown'}`
    },
  },
  {
    id: 'applock', title: 'App lock + PIN / biometric', icon: lockClosedOutline, iconBg: 'linear-gradient(135deg,#DC2626,#B91C1C)', anchorId: 'tour-anchor-applock',
    description: 'Protect PII on a shared device. Unlock by fingerprint/face, fall back to a 6-digit PIN.',
    toggleKey: CAPABILITY_KEYS.APP_LOCK,
    howTo: [
      'Toggle the switch to enable.',
      'Set a 4–10 digit PIN. Enrol biometrics if prompted.',
      'The lock appears at launch and after 5 minutes idle in background.',
    ],
    troubleshoot: [{ q: 'Forgot PIN', a: 'Tap "Forgot PIN" on the lock screen — you\'ll be signed out and can re-enrol after re-login.' }, { q: 'No biometric option', a: 'Your device has no fingerprint / face unlock — PIN still works.' }],
    demoLabel: 'Check status', demo: async () => {
      const b = await bio.biometricAvailable()
      const p = bio.hasPin() ? 'PIN set' : 'no PIN'
      demoOut.applock = `Biometric ${b.available ? 'available' : 'unavailable'} · ${p}`
    },
  },
  {
    id: 'directory', title: 'Staff directory', icon: callOutline, iconBg: 'linear-gradient(135deg,#0891B2,#06B6D4)',
    description: 'Tap to dial or email any district, PHEOC or POE officer — no copy-pasting from email.',
    toggleKey: CAPABILITY_KEYS.DIRECTORY,
    howTo: [
      'Open Directory from the side menu.',
      'Filter by role or search.',
      'Tap the phone icon — your dialler opens pre-filled; confirm before calling.',
    ],
    troubleshoot: [{ q: 'Tablet — tap opens empty dialler', a: 'Tablets without a SIM fall back to copy-number; paste into an app that can dial.' }],
    tourSteps: [
      { elementId: 'tour-anchor-directory-menu', title: 'Open Directory', body: 'From here you can reach anyone in your scope.', icon: 'call', ctaLabel: 'Got it' },
    ],
  },
]

const groups = [
  { id: 'security', title: 'Security', features: features.filter(f => ['applock','haptics'].includes(f.id)) },
  { id: 'capture',  title: 'Capture assists', features: features.filter(f => ['barcode','keepawake'].includes(f.id)) },
  { id: 'comms',    title: 'Communication', features: features.filter(f => ['directory','pdfshare','localnotifs'].includes(f.id)) },
  { id: 'connect',  title: 'Connectivity', features: features.filter(f => ['network'].includes(f.id)) },
]

function statusClass(s) {
  if (s === 'ready') return 'ch-dot--g'
  if (s === 'off')   return 'ch-dot--a'
  if (s === 'perm')  return 'ch-dot--a'
  return 'ch-dot--r'
}
function statusLabel(s) {
  if (s === 'ready') return 'Ready'
  if (s === 'off')   return 'Disabled'
  if (s === 'perm')  return 'Permission needed'
  return 'Unsupported on this device'
}

async function refreshStatuses() {
  const check = async (key, probe) => {
    if (!isEnabled(key)) return 'off'
    const v = await probe(); return v ? 'ready' : 'na'
  }
  statuses.haptics      = isEnabled(CAPABILITY_KEYS.HAPTICS) ? 'ready' : 'off'
  statuses.network      = await check(CAPABILITY_KEYS.NETWORK,      async () => true) // plugin has web fallback
  statuses.keepawake    = await check(CAPABILITY_KEYS.KEEPAWAKE,    async () => keepAwake.isAvailable())
  statuses.barcode      = await check(CAPABILITY_KEYS.BARCODE,      async () => barcode.isAvailable())
  statuses.localnotifs  = await check(CAPABILITY_KEYS.LOCAL_NOTIFS, async () => localNotifs.isAvailable())
  statuses.pdfshare     = await check(CAPABILITY_KEYS.PDF_SHARE,    async () => shareAvail())
  statuses.applock      = await check(CAPABILITY_KEYS.APP_LOCK,     async () => bio.isAvailable())
  statuses.directory    = isEnabled(CAPABILITY_KEYS.DIRECTORY) ? 'ready' : 'off'
}

function playWelcome() { replayWelcomeTour() }

function runFeatureTour(f) {
  if (f.tourSteps && f.tourSteps.length) { runSteps(f.tourSteps); return }
  if (f.anchorId) {
    runSteps([{ elementId: f.anchorId, title: f.title, body: f.description, icon: 'sparkles', ctaLabel: 'Got it' }])
  }
}

let unsub = null
onMounted(async () => {
  await refreshStatuses()
  unsub = subscribe(CAPABILITY_KEYS.HAPTICS, () => refreshStatuses())
  maybeRunWelcomeTour()
})
onUnmounted(() => { try { unsub && unsub() } catch {} })
</script>

<style scoped>
*{box-sizing:border-box}
.ch-hdr{--background:transparent;border:none}
.ch-hdr-bg{background:linear-gradient(135deg,#0B2545,#13315C,#003F88);padding:8px 0 16px}
.ch-hdr-top{display:flex;align-items:center;gap:4px;padding:0 8px}
.ch-menu{--color:rgba(255,255,255,.7)}
.ch-hdr-title{flex:1;display:flex;flex-direction:column;min-width:0}
.ch-eye{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:rgba(255,255,255,.45)}
.ch-h1{font-size:17px;font-weight:800;color:#fff}
.ch-sub{font-size:12px;color:rgba(255,255,255,.65);padding:4px 14px 0;margin:0;line-height:1.45;max-width:560px}

.ch-content{--background:#F0F4FA}
.ch-body{padding:10px 12px 0;max-width:620px;margin:0 auto}

.ch-banner{
  display:flex;gap:12px;align-items:center;background:linear-gradient(135deg,#003566,#0B2545);
  color:#fff;border-radius:14px;padding:14px;margin-bottom:14px;cursor:pointer;
  box-shadow:0 6px 16px rgba(11,37,69,.25)
}
.ch-banner-ico{width:40px;height:40px;border-radius:10px;background:rgba(0,230,214,.2);color:#00E6D6;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
.ch-banner-text{flex:1}
.ch-banner-t{font-size:14px;font-weight:800}
.ch-banner-d{font-size:11px;color:rgba(255,255,255,.7);margin-top:2px}
.ch-banner-arrow{color:rgba(255,255,255,.6);font-size:20px}

.ch-group{margin-bottom:14px}
.ch-group-h{font-size:10px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:1.2px;padding:4px 4px 8px}

.ch-card{background:#fff;border-radius:12px;border:1px solid #E6ECF5;margin-bottom:10px;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.03)}
.ch-card-row{display:flex;gap:12px;padding:14px;align-items:flex-start}
.ch-card-ico{width:40px;height:40px;border-radius:10px;color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.ch-card-main{flex:1;min-width:0}
.ch-card-t{font-size:14px;font-weight:800;color:#0B2545}
.ch-card-d{font-size:12px;color:#475569;margin-top:3px;line-height:1.5}
.ch-card-status{display:flex;align-items:center;gap:6px;margin-top:6px}
.ch-dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.ch-dot--g{background:#10B981;box-shadow:0 0 6px rgba(16,185,129,.4)}
.ch-dot--a{background:#F59E0B}
.ch-dot--r{background:#CBD5E1}
.ch-status-t{font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.6px}

.ch-card-toggle{flex-shrink:0;cursor:pointer;padding:2px;align-self:flex-start}
.ch-tg{width:40px;height:22px;border-radius:12px;background:#CBD5E1;position:relative;display:block;transition:background .2s}
.ch-tg--on{background:#10B981}
.ch-tg-dot{position:absolute;top:2px;left:2px;width:18px;height:18px;border-radius:50%;background:#fff;box-shadow:0 1px 2px rgba(0,0,0,.2);transition:transform .2s}
.ch-tg--on .ch-tg-dot{transform:translateX(18px)}

.ch-card-body{padding:0 14px 14px;display:flex;flex-direction:column;gap:10px}
.ch-card-how-h{font-size:10px;font-weight:800;color:#0B2545;text-transform:uppercase;letter-spacing:.8px;margin-bottom:4px}
.ch-card-how-l{margin:0;padding-left:18px;font-size:12px;color:#334155;line-height:1.55}
.ch-card-how-l li{margin:2px 0}

.ch-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:4px}
.ch-btn{font-size:12px;font-weight:700;padding:8px 14px;border-radius:10px;border:none;cursor:pointer;transition:transform .08s,box-shadow .15s}
.ch-btn:active{transform:translateY(1px)}
.ch-btn--primary{background:linear-gradient(135deg,#0B2545,#13315C);color:#fff;box-shadow:0 4px 10px rgba(11,37,69,.25)}
.ch-btn--ghost{background:#F1F5F9;color:#0B2545}
.ch-btn--ghost:hover{background:#E2E8F0}

.ch-demo-out{font-family:monospace;font-size:11px;color:#059669;background:#ECFDF5;padding:8px 10px;border-radius:8px;border:1px solid #D1FAE5}
</style>
