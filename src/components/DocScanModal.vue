<template>
  <!-- Live streaming scanner — opened from runPassport / runNationalId.
       Self-contained full-screen modal with its own camera lifecycle. -->
  <LiveDocScanModal
    :is-open="liveOpen"
    :doc-type="liveDocType"
    @result="_onLiveResult"
    @cancel="_onLiveCancel"
    @manual="_onLiveManual"
  />

  <ion-modal :is-open="open" @did-dismiss="emitClose" class="ds-modal">
    <ion-header>
      <ion-toolbar>
        <ion-title>{{ titleText }}</ion-title>
        <ion-buttons slot="end">
          <ion-button @click="emitClose">Close</ion-button>
        </ion-buttons>
      </ion-toolbar>
    </ion-header>
    <ion-content :scroll-y="true" style="--background:#F8FAFC;--color:#0B1A30;">
      <div class="ds-body">
        <!-- ── MENU / CHOOSER ────────────────────────────────────────── -->
        <div v-if="mode === 'menu'">
          <div class="ds-lead">
            <div class="ds-lead-t">What are you scanning?</div>
            <div class="ds-lead-d">Take a photo and we'll fill the form. Works without network.</div>
          </div>

          <button class="ds-opt ds-opt--primary" type="button" :disabled="busy" @click="runPassport">
            <span class="ds-opt-ico" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M7 17h10M7 19.5h6"/>
              </svg>
            </span>
            <span class="ds-opt-body">
              <span class="ds-opt-t">{{ busy ? 'Opening camera…' : 'Passport' }}</span>
              <span class="ds-opt-d">Photograph the data page — we read the MRZ at the bottom</span>
            </span>
          </button>

          <!-- National ID — Request 13: support TD1/TD2 MRZ format -->
          <button class="ds-opt ds-opt--primary" type="button" :disabled="busy" @click="runNationalId">
            <span class="ds-opt-ico" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="5" width="20" height="14" rx="2"/>
                <circle cx="8" cy="12" r="2.5"/>
                <path d="M13 9h5M13 12h5M13 15h3"/>
              </svg>
            </span>
            <span class="ds-opt-body">
              <span class="ds-opt-t">{{ busy ? 'Opening camera…' : 'National ID' }}</span>
              <span class="ds-opt-d">Photograph the front of the ID card — reads the TD1 MRZ (3 lines × 30 chars) or barcode</span>
            </span>
          </button>

          <button class="ds-opt" type="button" @click="mode = 'paste'; error = ''">
            <span class="ds-opt-ico" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <rect x="6" y="4" width="12" height="16" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 12h6M9 16h4"/>
              </svg>
            </span>
            <span class="ds-opt-body">
              <span class="ds-opt-t">Paste MRZ text or QR payload</span>
              <span class="ds-opt-d">Use this when you copy the MRZ from another app</span>
            </span>
          </button>

          <button class="ds-opt" type="button" @click="emitClose">
            <span class="ds-opt-ico" aria-hidden="true">
              <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>
              </svg>
            </span>
            <span class="ds-opt-body">
              <span class="ds-opt-t">Type the name manually</span>
              <span class="ds-opt-d">Close this panel and type directly into the form</span>
            </span>
          </button>

          <div v-if="hint" class="ds-hint">{{ hint }}</div>
          <div v-if="error" class="ds-err">{{ error }}</div>
          <!-- Open Settings button — only shown when permission permanently denied -->
          <a
            v-if="settingsUrl"
            :href="settingsUrl"
            class="ds-settings-btn"
            target="_system"
            aria-label="Open device Settings to grant camera permission"
          >Open Settings →</a>
        </div>

        <!-- ── PASTE ──────────────────────────────────────────────────── -->
        <div v-else-if="mode === 'paste'">
          <div class="ds-lead">
            <div class="ds-lead-t">Paste MRZ, QR payload, or full name</div>
            <div class="ds-lead-d">Examples accepted: the two 44-character passport MRZ lines, a QR with <code>name=...</code>, JSON with <code>"name"</code>, or a plain printable name.</div>
          </div>
          <textarea
            v-model="paste"
            rows="6"
            class="ds-paste"
            placeholder="P<ZMBBANDA<<MULENGA<<<<<<<<<<<<<<<<<<<<<<<<&#10;ZN12345678ZMB9001011M3001014<<<<<<<<<<<<<<06"
            autocapitalize="characters"
            spellcheck="false"
          />
          <div v-if="error" class="ds-err">{{ error }}</div>
          <div class="ds-row">
            <button class="ds-btn ds-btn--ghost" type="button" @click="mode = 'menu'; error = ''">← Back</button>
            <button class="ds-btn ds-btn--primary" type="button" @click="parsePasted">Parse</button>
          </div>
        </div>

        <!-- ── RESULT PREVIEW ─────────────────────────────────────────── -->
        <div v-else-if="mode === 'result'">
          <div class="ds-lead">
            <div class="ds-lead-t">Scan complete — review and apply</div>
            <div class="ds-lead-d">Check the values below; tap <strong>Apply</strong> to fill the form, or scan again.</div>
          </div>
          <div class="ds-result-card">
            <div v-if="result?.name" class="ds-rrow"><span class="ds-rk">Name</span><span class="ds-rv">{{ result.name }}</span></div>
            <div v-if="result?.sex" class="ds-rrow"><span class="ds-rk">Gender</span><span class="ds-rv">{{ result.sex }}</span></div>
            <div v-if="result?.nationality_iso3" class="ds-rrow"><span class="ds-rk">Nationality</span><span class="ds-rv">{{ result.nationality_iso3 }}</span></div>
            <div v-if="result?.passport_no" class="ds-rrow"><span class="ds-rk">Document</span><span class="ds-rv">{{ result.passport_no }}</span></div>
            <div v-if="result?._bcbp && result?._firstLeg" class="ds-rrow"><span class="ds-rk">From</span><span class="ds-rv">{{ result._firstLeg.from_iata }}{{ result._origin ? ' · ' + result._origin : '' }}</span></div>
            <div v-if="result?._bcbp && result?._firstLeg" class="ds-rrow"><span class="ds-rk">Flight</span><span class="ds-rv">{{ result._firstLeg.carrier }}{{ result._firstLeg.flight_number }}</span></div>
          </div>
          <div class="ds-row">
            <button class="ds-btn ds-btn--ghost" type="button" @click="mode = 'menu'; error = ''">← Scan again</button>
            <button class="ds-btn ds-btn--primary" type="button" @click="applyAndClose">Apply</button>
          </div>
        </div>
      </div>
    </ion-content>
  </ion-modal>
</template>

<script setup>
/**
 * DocScanModal — reusable Passport / Boarding-Pass scan modal.
 *
 * Emits
 *   - update:open(boolean) — for v-model:open
 *   - result(payload)      — fired ONLY when the user taps Apply.
 *                            payload shape:
 *                              { kind: 'passport', doc: { name, sex, nationality_iso3, passport_no, dob_iso, ... } }
 *                              { kind: 'bcbp',     data, legs, origin_country }
 *
 * Hardening contract: every async path is wrapped, never throws to the
 * parent. Internal failures resolve to friendly hints in `error`.
 */
import { ref, watch, computed } from 'vue'
import { IonModal, IonHeader, IonToolbar, IonTitle, IonButtons, IonButton, IonContent } from '@ionic/vue'
import { parseTravelerDoc } from '@/services/passport'
import { isRealPluginFailure, pluginAlert } from '@/services/pluginAlert'
import LiveDocScanModal from '@/components/LiveDocScanModal.vue'

// Live-scanner modal control + selected document type. The legacy
// "take a photo, then OCR" flow remains available as a fallback in case
// live scanning ever fails on a particular device.
const liveOpen     = ref(false)
const liveDocType  = ref('passport')

const props = defineProps({
  open:  { type: Boolean, default: false },
  title: { type: String, default: 'Scan document' },
})
const emit = defineEmits(['update:open', 'result'])

const mode    = ref('menu')          // 'menu' | 'paste' | 'result'
const busy    = ref(false)
const hint    = ref('')
const error   = ref('')
const paste   = ref('')
const result  = ref(null)

const titleText = computed(() => props.title || 'Scan document')

// Reset modal state every time it (re)opens so a previous failure doesn't
// linger when the operator opens it again.
watch(() => props.open, (v) => {
  if (v) {
    mode.value = 'menu'
    busy.value = false
    hint.value = ''
    error.value = ''
    paste.value = ''
    result.value = null
  }
})

function emitClose() {
  emit('update:open', false)
}

// ── PASSPORT FLOW ──────────────────────────────────────────────────────
// settingsUrl is populated when permission is permanently denied so the
// template can show an "Open Settings" button instead of just an error.
const settingsUrl = ref(null)

// Open the live streaming scanner. On result, populate `result` and
// switch to the review screen. On 'manual', drop into paste mode.
function runPassport() {
  if (busy.value) return
  error.value = ''
  hint.value  = ''
  settingsUrl.value = null
  liveDocType.value = 'passport'
  liveOpen.value    = true
}
function runNationalIdLive() {
  if (busy.value) return
  error.value = ''
  hint.value  = ''
  settingsUrl.value = null
  liveDocType.value = 'national-id'
  liveOpen.value    = true
}

function _onLiveResult(doc) {
  liveOpen.value = false
  if (!doc) return
  // Adapt mrzRobust shape → existing result schema (legacy field names kept
  // for back-compat with whatever the parent expected from parseTravelerDoc).
  result.value = {
    format:           doc.format,
    name:             doc.name || '',
    surname:          doc.surname || '',
    given:            doc.given_names || '',
    given_names:      doc.given_names || '',
    sex:              doc.sex || '',
    dob_iso:          doc.dob_iso || '',
    nationality_iso3: doc.nationality || '',
    passport_no:      doc.document_number || '',
    document_number:  doc.document_number || '',
    document_type:    doc.document_type || '',
    issuing_country:  doc.issuing_country || '',
    personal_number:  doc.personal_number || '',
    expiry_iso:       doc.expiry_iso || '',
    confidence:       doc.confidence || 0,
    warnings:         doc.warnings || [],
  }
  mode.value = 'result'
}
function _onLiveCancel() { liveOpen.value = false }
function _onLiveManual() { liveOpen.value = false; mode.value = 'paste'; error.value = '' }

// Legacy photo-based flow — retained as a fallback if the user taps
// "Use legacy photo" from the menu (not currently exposed in the UI but
// preserved in case live scanning needs to be disabled on a problematic
// device).
async function runPassportLegacy() {
  if (busy.value) return
  busy.value    = true
  error.value   = ''
  hint.value    = 'Opening camera…'
  settingsUrl.value = null

  try {
    const { scanPassport } = await import('@/services/plugins/passportScan')

    const scanRes = await scanPassport({
      // onProgress: update hint text live during the scan so the officer
      // always knows what the app is doing (essential for slow phones).
      onProgress(step, detail) {
        if (step === 'permission-check') {
          hint.value = 'Checking camera permission…'
        } else if (step === 'photo-start') {
          hint.value = detail.attempt > 1
            ? `Attempt ${detail.attempt} of 3 — position the passport data page`
            : 'Photograph the passport data page — keep the MRZ at the bottom in the frame.'
        } else if (step === 'ocr-start') {
          hint.value = detail.rotation === 0
            ? 'Reading the passport…'
            : `Retrying at ${detail.rotation}° rotation…`
        } else if (step === 'retry-guidance') {
          hint.value = detail.guidance || 'Adjust and try again.'
        }
      },
    })

    hint.value = ''

    if (!scanRes || scanRes.ok !== true) {
      if (scanRes?.reason === 'photo-cancelled') return   // silent — user chose to cancel

      // E02 — permanent permission denial: offer deep-link to settings
      if (scanRes?.reason === 'permission-permanent' && scanRes?.settingsUrl) {
        settingsUrl.value = scanRes.settingsUrl
        error.value = 'Camera access is blocked. Open Settings and enable Camera permission for this app, then try again.'
        return
      }

      // Real plugin failures (not user-driven) → show native alert with details
      if (isRealPluginFailure(scanRes?.reason)) {
        pluginAlert('Passport Scanner', scanRes.reason, scanRes?.hint)
      }

      error.value = scanRes?.hint || 'Scan failed. Paste the MRZ lines or type the name manually.'
      return
    }

    result.value = scanRes.doc
    mode.value   = 'result'
  } catch (err) {
    console.debug('[DocScanModal] passport outer-throw:', err?.message)
    pluginAlert('Passport Scanner', 'orchestrator-error:' + (err?.message || 'unknown'), null)
    error.value = 'Passport scanner failed. Paste the MRZ or type the name manually.'
  } finally {
    busy.value = false
    if (mode.value !== 'result') hint.value = ''
  }
}

// ── NATIONAL ID FLOW ──────────────────────────────────────────────────
// New entry point opens the live streaming scanner with TD1-aware parsing.
// The legacy photo-based version is preserved below as runNationalIdLegacy
// in case live scanning ever needs to be disabled.
function runNationalId() {
  // Default to live scanning. Falls through to legacy if live fails.
  return runNationalIdLive()
}

async function runNationalIdLegacy() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  hint.value = 'Photograph the front of the National ID — keep the text zone / barcode in frame.'
  try {
    let scanRes
    try {
      const { scanPassport } = await import('@/services/plugins/passportScan')
      // TD1 MRZ (3-line National IDs) is auto-detected by the same parser
      scanRes = await scanPassport({ docTypeHint: 'TD1' })
    } catch (importErr) {
      console.debug('[DocScanModal] national-id import failed:', importErr?.message)
      // Fallback: try barcode scan (some national IDs carry a barcode)
      try {
        const { runCameraScan } = await import('@/services/plugins/barcode')
        scanRes = await runCameraScan()
      } catch (barcodeErr) {
        error.value = 'National ID scanner not available in this build. Paste the MRZ or type manually.'
        return
      }
    }
    hint.value = ''
    if (!scanRes || scanRes.ok !== true) {
      if (scanRes && scanRes.reason === 'photo-cancelled') return
      error.value = (scanRes && scanRes.hint) || 'Scan failed. Try the barcode, paste the MRZ, or type manually.'
      return
    }
    result.value = { ...scanRes.doc, doc_type: 'NATIONAL_ID' }
    mode.value = 'result'
  } catch (err) {
    console.debug('[DocScanModal] national-id outer-throw:', err?.message)
    error.value = 'National ID scan failed. Paste the MRZ or type the name manually.'
  } finally {
    busy.value = false
    if (mode.value !== 'result') hint.value = ''
  }
}

// Boarding-pass flow removed (2026-05-05). Replaced by National ID + Passport
// scan via runPassport / runNationalId. Stub kept for any cached code path.
async function runBoardingPass() { return runNationalId() }
async function _legacyBoardingPassNoop() {
  /* preserved as inert no-op so any cached chunk reference resolves cleanly */
}

// ── PASTE FLOW ─────────────────────────────────────────────────────────
function parsePasted() {
  try {
    error.value = ''
    const txt = (paste.value || '').trim()
    if (!txt) { error.value = 'Paste an MRZ line, QR payload, or a full name first.'; return }
    const doc = parseTravelerDoc(txt)
    if (!doc || !doc.name) {
      error.value = 'Could not find a name in that text. Paste either the two MRZ lines, a QR payload, or just the traveller name.'
      return
    }
    result.value = doc
    mode.value = 'result'
  } catch (err) {
    console.debug('[DocScanModal] paste throw:', err?.message)
    error.value = 'Could not read that text. Try pasting just the name.'
  }
}

// ── APPLY ──────────────────────────────────────────────────────────────
function applyAndClose() {
  if (!result.value) {
    emitClose()
    return
  }
  // Discriminate on _bcbp marker so the consumer knows how to map fields.
  if (result.value._bcbp) {
    emit('result', {
      kind:            'bcbp',
      name:            result.value.name || '',
      origin_country:  result.value._origin || null,
      data:            result.value._data,
      legs:            result.value._legs || [],
      first_leg:       result.value._firstLeg || null,
    })
  } else {
    emit('result', {
      kind:             'passport',
      doc:              result.value,
    })
  }
  emitClose()
}
</script>

<style scoped>
.ds-body{padding:14px}
.ds-lead{margin-bottom:14px}
.ds-lead-t{font-size:14px;font-weight:700;color:#0B1A30;line-height:1.3}
.ds-lead-d{font-size:11px;color:#475569;line-height:1.4;margin-top:4px}
.ds-opt{display:flex;align-items:center;gap:12px;width:100%;padding:14px;border:1px solid #E2E8F0;background:#FFFFFF;border-radius:10px;margin-bottom:10px;text-align:left;cursor:pointer;color:#0B1A30}
.ds-opt:disabled{opacity:.55;cursor:wait}
.ds-opt--primary{border-color:#1565C0;background:#EFF6FF}
.ds-opt-ico{flex:0 0 auto;width:36px;height:36px;display:flex;align-items:center;justify-content:center;color:#1565C0}
.ds-opt-body{display:flex;flex-direction:column;flex:1 1 auto;min-width:0}
.ds-opt-t{font-size:13px;font-weight:700;color:inherit}
.ds-opt-d{font-size:11px;color:#475569;margin-top:2px;line-height:1.35}
.ds-row{display:flex;gap:8px;margin-top:10px}
.ds-btn{flex:1 1 0;padding:10px;border-radius:8px;border:1px solid #E2E8F0;background:#FFFFFF;color:#0B1A30;font-size:13px;font-weight:700;cursor:pointer}
.ds-btn--primary{background:#1565C0;color:#FFFFFF;border-color:#1565C0}
.ds-btn--ghost{background:transparent}
.ds-paste{width:100%;padding:10px;border:1px solid #CBD5E1;border-radius:8px;background:#FFFFFF;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;color:#0B1A30;resize:vertical}
.ds-err{padding:8px 10px;background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;border-radius:8px;font-size:11px;font-weight:600;line-height:1.4;margin-top:8px}
.ds-hint{padding:8px 10px;background:#EFF6FF;border:1px solid #BFDBFE;color:#1E40AF;border-radius:8px;font-size:11px;font-weight:600;line-height:1.4;margin-top:8px}
.ds-result-card{padding:12px;background:#FFFFFF;border:1px solid #E2E8F0;border-radius:10px;margin-bottom:8px}
.ds-rrow{display:flex;justify-content:space-between;gap:12px;padding:6px 0;border-bottom:1px solid #F1F5F9}
.ds-rrow:last-child{border-bottom:0}
.ds-rk{font-size:11px;color:#64748B;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.ds-rv{font-size:13px;color:#0B1A30;font-weight:700;text-align:right;word-break:break-word}
.ds-settings-btn{display:block;margin-top:10px;padding:10px 14px;background:#1E40AF;color:#fff;border-radius:9px;text-align:center;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer}
.ds-settings-btn:active{opacity:.85}
</style>
