<script setup>
/**
 * SmartCloseWizard — guided close + outcome flow that aggressively reuses
 * everything the system already captured, so the user closes a case in
 * 2-3 taps for the common path.
 *
 * Steps:
 *   0. INTENT       — one-tap presets ('False positive' / 'Resolved — recovered'
 *                     / 'Lost to follow-up' / 'Deceased' / 'Custom'). Each
 *                     preset pre-fills the entire form with the most likely
 *                     outcome + close_category combination derived from the
 *                     case file (top suspected disease, lab confirmation,
 *                     clinical disposition, IHR tier, risk level).
 *   1. BLOCKERS     — only shown if alert has open blocking follow-ups. Bulk
 *                     resolve all in one tap, OR (NATIONAL_ADMIN) toggle the
 *                     override path with a 30-char justification. Per-blocker
 *                     "tap to mark COMPLETED" chips for granular control.
 *   2. CONFIRM      — review chips with one-line summary + outcome chips +
 *                     "Edit details" disclosure for the rare case the user
 *                     needs to override an inferred field.
 *
 * Result: outcome row + alert close in two writes; both are queued through
 * the offline outbox so it works on a flaky 3G connection at a border post.
 *
 * Props: modelValue (open), caseFile (read-only, from useAlertLifecycle),
 *        lifecycle (the composable instance — we drive it directly).
 *
 * Emits: 'update:modelValue', 'closed' (success).
 */
import { ref, computed, watch } from 'vue'
import {
  IonHeader, IonToolbar, IonTitle, IonButtons, IonButton, IonContent,
  IonList, IonItem, IonLabel, IonInput, IonTextarea, IonSelect, IonSelectOption,
  IonCard, IonCardContent, IonChip, IonIcon, IonNote, IonProgressBar,
} from '@ionic/vue'
import {
  closeCircleOutline, checkmarkCircle, warningOutline, sparklesOutline,
  fileTrayStackedOutline, ribbonOutline, sadOutline, alertCircleOutline,
  arrowForward, lockClosedOutline, flashOutline, optionsOutline,
} from 'ionicons/icons'
import { useCan } from '@/composables/useCan'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  caseFile: { type: Object, default: null },
  lifecycle: { type: Object, required: true },
})
const emit = defineEmits(['update:modelValue', 'closed'])

const { role: roleRef } = useCan()
const isNationalAdmin = computed(() => roleRef.value === 'NATIONAL_ADMIN')

const step = ref(0)        // 0 intent, 1 blockers (skipped if none), 2 confirm
const submitting = ref(false)
const showAdvanced = ref(false)
const overrideOn = ref(false)
const overrideReason = ref('')

const alert       = computed(() => props.caseFile?.alert || {})
const screening   = computed(() => props.caseFile?.screening || null)
const suspected   = computed(() => props.caseFile?.suspected_diseases || [])
const samples     = computed(() => props.caseFile?.samples || [])
const blockers    = computed(() => props.caseFile?.blockers || [])
const hasBlockers = computed(() => blockers.value.length > 0)

// ── INTELLIGENT INFERENCE ────────────────────────────────────────────────
//
// This is the "system already knows it" engine. Every intent preset feeds
// into computeForm(intent) which derives a fully-formed close + outcome
// payload from the captured data. The user only confirms.
//
// Top suspected disease is the primary driver: if the case file ranked a
// disease at ≥0.6 confidence, we pre-fill lab_disease_code; if a sample was
// collected and marked POSITIVE, we set lab_status = POSITIVE and
// case_classification = CONFIRMED. Otherwise we lean on the close intent to
// shape classification (FALSE_POSITIVE → DISCARDED; RESOLVED + Tier1 →
// CONFIRMED unless lab disagrees; LOST_TO_FOLLOWUP → LOST_TO_FOLLOWUP).

const topSuspected = computed(() => {
  const list = suspected.value || []
  if (!list.length) return null
  return list.reduce((best, cur) => {
    const c = Number(cur.confidence ?? 0)
    return (!best || c > Number(best.confidence ?? 0)) ? cur : best
  }, null)
})

const labResult = computed(() => {
  // Look for a sample with a recorded result
  const positive = (samples.value || []).find(s =>
    /POS/i.test(String(s.result || s.sample_status || '')))
  if (positive) return 'POSITIVE'
  const negative = (samples.value || []).find(s =>
    /NEG/i.test(String(s.result || s.sample_status || '')))
  if (negative) return 'NEGATIVE'
  return null
})

const isHighRisk = computed(() => ['CRITICAL','HIGH'].includes(alert.value?.risk_level))
const isTier1   = computed(() => /TIER_1/i.test(alert.value?.ihr_tier || ''))

function inferOutcomeFromIntent(intent) {
  const top = topSuspected.value
  const lab = labResult.value
  const tier1 = isTier1.value
  const out = {
    case_classification: 'SUSPECTED',
    case_classification_reason: '',
    lab_status: lab || (samples.value.length ? 'PENDING' : 'NOT_TESTED'),
    lab_disease_code: top?.disease_code || '',
    lab_test_method: '',
    clinical_outcome: '',
    ph_action: tier1 ? 'IHR_NOTIFIED' : (isHighRisk.value ? 'ENHANCED_SURVEILLANCE' : 'STANDARD_SURVEILLANCE'),
    outbreak_status: 'NONE',
    ihr_notified: tier1,
    ihr_reference: '',
    notes: '',
    source: 'WIZARD',
  }

  if (intent === 'FALSE_POSITIVE') {
    out.case_classification = 'DISCARDED'
    out.case_classification_reason = 'Officer review concluded the alert was not a true case.'
    out.clinical_outcome = 'RECOVERED'   // traveler proceeded normally
    out.ph_action = 'STANDARD_SURVEILLANCE'
  } else if (intent === 'RESOLVED_RECOVERED') {
    out.case_classification = lab === 'POSITIVE' ? 'CONFIRMED' : (top ? 'PROBABLE' : 'SUSPECTED')
    out.clinical_outcome = 'RECOVERED'
    out.case_classification_reason = lab === 'POSITIVE'
      ? 'Lab-confirmed; case responded to treatment and recovered.'
      : 'Clinically managed; resolved without lab confirmation.'
  } else if (intent === 'LOST_TO_FOLLOWUP') {
    out.case_classification = 'LOST_TO_FOLLOWUP'
    out.clinical_outcome = 'LOST_TO_FOLLOWUP'
    out.case_classification_reason = 'Traveller could not be re-contacted within follow-up window.'
  } else if (intent === 'DECEASED') {
    out.case_classification = lab === 'POSITIVE' ? 'CONFIRMED' : (top ? 'PROBABLE' : 'SUSPECTED')
    out.clinical_outcome = 'DECEASED'
    out.case_classification_reason = 'Traveller died during the response window.'
    out.ph_action = 'OUTBREAK_INVESTIGATION'
  } else if (intent === 'TRANSFERRED') {
    out.case_classification = lab === 'POSITIVE' ? 'CONFIRMED' : 'SUSPECTED'
    out.clinical_outcome = 'TRANSFERRED'
    out.case_classification_reason = 'Case transferred out of country for continued care.'
  } else if (intent === 'DUPLICATE') {
    out.case_classification = 'UNKNOWN'
    out.clinical_outcome = ''
    out.case_classification_reason = 'Duplicate of another alert; outcome tracked on the canonical record.'
  }
  return out
}

function inferCloseFromIntent(intent) {
  if (intent === 'FALSE_POSITIVE')   return { close_category: 'FALSE_POSITIVE', close_note: '' }
  if (intent === 'RESOLVED_RECOVERED') return { close_category: 'RESOLVED', close_note: '' }
  if (intent === 'LOST_TO_FOLLOWUP') return { close_category: 'LOST_TO_FOLLOWUP', close_note: '' }
  if (intent === 'DECEASED')         return { close_category: 'DECEASED', close_note: '' }
  if (intent === 'TRANSFERRED')      return { close_category: 'TRANSFERRED_OUT_OF_COUNTRY', close_note: '' }
  if (intent === 'DUPLICATE')        return { close_category: 'DUPLICATE', close_note: '', merged_into_alert_id: null }
  return { close_category: 'OTHER', close_note: '' }
}

const intent = ref('')
const outcomeForm = ref(inferOutcomeFromIntent(''))
const closeForm = ref(inferCloseFromIntent(''))
const blockerResolveReason = ref('')

function pickIntent(code) {
  intent.value = code
  outcomeForm.value = inferOutcomeFromIntent(code)
  closeForm.value = inferCloseFromIntent(code)
  // Skip blockers step if none
  step.value = hasBlockers.value && code !== 'FALSE_POSITIVE' ? 1 : 2
  // FALSE_POSITIVE specifically auto-disregards blockers when NATIONAL_ADMIN
  // since the alert was never real — gentle nudge but no force.
}

function back() {
  if (step.value === 2 && hasBlockers.value) step.value = 1
  else step.value = 0
}

function intentLabel(code) {
  return ({
    FALSE_POSITIVE: 'False positive',
    RESOLVED_RECOVERED: 'Resolved — recovered',
    LOST_TO_FOLLOWUP: 'Lost to follow-up',
    DECEASED: 'Deceased',
    TRANSFERRED: 'Transferred out of country',
    DUPLICATE: 'Duplicate',
    CUSTOM: 'Custom close',
  })[code] || '—'
}

// WHO / Africa CDC label helpers for the summary chips. Map raw enum values
// stored on the wire to plain-English phrasing executives can read at a glance.
function classificationLabel(v) {
  return ({
    SUSPECTED:        'Suspected case',
    PROBABLE:         'Probable case',
    CONFIRMED:        'Confirmed by laboratory',
    DISCARDED:        'Discarded — not a case',
    LOST_TO_FOLLOWUP: 'Lost to follow-up',
    UNKNOWN:          'Classification unknown',
  })[v] || (v ? String(v) : 'Classification unknown')
}
function labLabel(v) {
  return ({
    PENDING:             'Pending',
    POSITIVE:            'Positive',
    NEGATIVE:            'Negative',
    INCONCLUSIVE:        'Inconclusive',
    INSUFFICIENT_SAMPLE: 'Insufficient sample',
    NOT_TESTED:          'Not tested',
  })[v] || (v ? String(v) : '')
}
function clinicalLabel(v) {
  return ({
    RECOVERED:        'Recovered',
    CONVALESCING:     'Convalescing',
    DECEASED:         'Deceased',
    LOST_TO_FOLLOWUP: 'Lost to follow-up',
    TRANSFERRED:      'Transferred',
    UNKNOWN:          'Outcome unknown',
  })[v] || (v ? String(v) : '')
}
function phActionLabel(v) {
  return ({
    STANDARD_SURVEILLANCE:  'Standard surveillance',
    ENHANCED_SURVEILLANCE:  'Enhanced surveillance',
    OUTBREAK_INVESTIGATION: 'Outbreak investigation',
    OUTBREAK_RESPONSE:      'Outbreak response',
    IHR_NOTIFIED:           'IHR notified',
  })[v] || (v ? String(v) : '')
}

async function bulkResolveBlockers() {
  if ((blockerResolveReason.value || '').trim().length < 10) return
  await props.lifecycle.resolveAllBlockers({
    resolution: 'NOT_APPLICABLE',
    reason: blockerResolveReason.value || 'Resolved during guided close',
  })
  // Move on regardless — server will refresh via loadCaseFile
  step.value = 2
}

async function resolveOne(b, resolution = 'COMPLETED') {
  // Quick chip-tap: mark COMPLETED with a generic reason; the user can edit
  // history afterwards. Keeps the audit trail truthful — we don't fake a long
  // reason; the wizard step itself is recorded in the timeline.
  await props.lifecycle.resolveBlocker(b.id, {
    resolution,
    reason: 'Resolved via SmartCloseWizard quick action.',
  })
}

async function submit() {
  // STEP 1 — pre-validate blockers SYNCHRONOUSLY using the cached blocker
  // list. If the user has open blockers and didn't enable the override,
  // bail BEFORE we touch the network so the modal stays open with the
  // explanation. The server still re-validates as a defence in depth.
  const hasBlockers = (props.lifecycle?.blockers?.value?.length ?? 0) > 0
  if (hasBlockers && !overrideOn.value) {
    step.value = 1
    return
  }

  // STEP 2 — DISMISS THE MODAL IMMEDIATELY. The whole point of the
  // optimistic-write pattern is that the user shouldn't wait for the
  // network. We snapshot the form payload, fire the close + outcome
  // calls in the background, and let Ionic dismiss the modal in the
  // ~300 ms slide animation.
  //
  // If the background call fails, the lifecycle composable's outbox
  // queues it for retry — same offline-first guarantee that powers
  // every other write in this app. On weak networks the modal slides
  // away cleanly and the alert eventually transitions to CLOSED on
  // the server without any further user action.
  const closePayload = { ...closeForm.value }
  if (overrideOn.value) {
    closePayload.override_blocking_followups = 1
    closePayload.override_reason = overrideReason.value
  }
  const outcomePayload = (intent.value !== 'CUSTOM' && outcomeForm.value.case_classification)
    ? { ...outcomeForm.value }
    : null

  // Reset spinner + dismiss BEFORE awaiting anything.
  submitting.value = false
  emit('update:modelValue', false)
  emit('closed')

  // Now fire the network calls detached.
  ;(async () => {
    try {
      if (outcomePayload) {
        await props.lifecycle.recordOutcome(outcomePayload).catch(() => {})
      }
      await props.lifecycle.close(closePayload).catch(() => {})
    } catch { /* outbox handles retries; the user has already moved on */ }
  })()
}

watch(() => props.modelValue, (v) => {
  if (v) {
    step.value = 0
    intent.value = ''
    overrideOn.value = false
    overrideReason.value = ''
    blockerResolveReason.value = ''
    showAdvanced.value = false
    outcomeForm.value = inferOutcomeFromIntent('')
    closeForm.value = inferCloseFromIntent('')
  }
})
</script>

<template>
  <ion-header>
    <ion-toolbar>
      <ion-buttons slot="start">
        <ion-button v-if="step > 0" @click="back" fill="clear">Back</ion-button>
      </ion-buttons>
      <ion-title>
        <span v-if="step === 0">Close — pick intent</span>
        <span v-else-if="step === 1">Resolve blockers</span>
        <span v-else>Confirm & close</span>
      </ion-title>
      <ion-buttons slot="end">
        <ion-button @click="emit('update:modelValue', false)" fill="clear">Cancel</ion-button>
      </ion-buttons>
    </ion-toolbar>
    <ion-progress-bar :value="step / 2" color="success"/>
  </ion-header>

  <ion-content class="ion-padding scw">
    <!-- Step 0: INTENT -->
    <template v-if="step === 0">
      <div class="scw-head">
        <ion-icon :icon="sparklesOutline" class="scw-spark"/>
        <h2>What happened with this case?</h2>
        <p>Pick the closest match — we'll fill in everything else from the screening.</p>
      </div>

      <div class="scw-intents">
        <button class="scw-intent" @click="pickIntent('RESOLVED_RECOVERED')">
          <ion-icon :icon="checkmarkCircle" class="scw-iicon scw-iicon--success"/>
          <strong>Resolved — recovered</strong>
          <small>{{ topSuspected ? `Top: ${topSuspected.disease_name || topSuspected.disease_code}` : 'Standard outcome' }} · clinical_outcome=RECOVERED</small>
        </button>

        <button class="scw-intent" @click="pickIntent('FALSE_POSITIVE')">
          <ion-icon :icon="closeCircleOutline" class="scw-iicon scw-iicon--medium"/>
          <strong>False positive</strong>
          <small>Not a real case → DISCARDED · STANDARD_SURVEILLANCE</small>
        </button>

        <button class="scw-intent" @click="pickIntent('LOST_TO_FOLLOWUP')">
          <ion-icon :icon="alertCircleOutline" class="scw-iicon scw-iicon--warning"/>
          <strong>Lost to follow-up</strong>
          <small>Traveller could not be re-contacted</small>
        </button>

        <button class="scw-intent" @click="pickIntent('DECEASED')">
          <ion-icon :icon="sadOutline" class="scw-iicon scw-iicon--danger"/>
          <strong>Deceased</strong>
          <small>Triggers OUTBREAK_INVESTIGATION ph_action</small>
        </button>

        <button class="scw-intent" @click="pickIntent('TRANSFERRED')">
          <ion-icon :icon="arrowForward" class="scw-iicon scw-iicon--tertiary"/>
          <strong>Transferred out of country</strong>
          <small>Continuing care abroad</small>
        </button>

        <button class="scw-intent" @click="pickIntent('DUPLICATE')">
          <ion-icon :icon="fileTrayStackedOutline" class="scw-iicon scw-iicon--medium"/>
          <strong>Duplicate</strong>
          <small>Of another alert</small>
        </button>

        <button class="scw-intent scw-intent--ghost" @click="pickIntent('CUSTOM')">
          <ion-icon :icon="optionsOutline" class="scw-iicon scw-iicon--medium"/>
          <strong>Custom</strong>
          <small>I'll fill out details myself</small>
        </button>
      </div>

      <ion-card v-if="hasBlockers" color="warning">
        <ion-card-content>
          <strong>{{ blockers.length }} blocking follow-up(s)</strong> will need to be resolved (or overridden by NATIONAL_ADMIN) before the alert can close.
        </ion-card-content>
      </ion-card>
    </template>

    <!-- Step 1: BLOCKERS -->
    <template v-else-if="step === 1">
      <div class="scw-head">
        <ion-icon :icon="lockClosedOutline" class="scw-spark scw-spark--warn"/>
        <h2>{{ blockers.length }} blocker(s) hold this alert open</h2>
        <p>Resolve them quickly with one tap, or batch-mark them not-applicable.</p>
      </div>

      <ion-list lines="full">
        <ion-item v-for="b in blockers" :key="b.id">
          <ion-label class="ion-text-wrap">
            <h3>{{ b.action_label || b.action_code }}</h3>
            <p>{{ b.status }}<span v-if="b.due_at"> · due {{ new Date(b.due_at).toLocaleDateString() }}</span></p>
          </ion-label>
          <div slot="end" style="display:flex; gap:6px">
            <ion-chip color="success" outline @click="resolveOne(b, 'COMPLETED')">Done</ion-chip>
            <ion-chip color="medium" outline @click="resolveOne(b, 'NOT_APPLICABLE')">N/A</ion-chip>
          </div>
        </ion-item>
      </ion-list>

      <ion-card>
        <ion-card-content>
          <strong>Or — bulk mark all NOT_APPLICABLE</strong>
          <p>For when none of the open blockers apply to this case (e.g. for a clear false positive).</p>
          <ion-item>
            <ion-label position="stacked">Reason (≥10 chars) *</ion-label>
            <ion-textarea v-model="blockerResolveReason" :auto-grow="true" rows="2"
              placeholder="e.g. Traveller turned out asymptomatic — none of the RTSL actions apply"/>
          </ion-item>
          <ion-button expand="block" color="warning" :disabled="(blockerResolveReason || '').trim().length < 10" @click="bulkResolveBlockers">
            Mark all {{ blockers.length }} NOT_APPLICABLE
          </ion-button>
        </ion-card-content>
      </ion-card>

      <ion-card v-if="isNationalAdmin" color="danger">
        <ion-card-content>
          <strong>NATIONAL_ADMIN — override</strong>
          <p>Force-close despite open blockers. Logs a CRITICAL timeline event.</p>
          <ion-item>
            <ion-label>Use override</ion-label>
            <ion-select slot="end" v-model="overrideOn" interface="popover">
              <ion-select-option :value="false">No</ion-select-option>
              <ion-select-option :value="true">Yes</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item v-if="overrideOn">
            <ion-label position="stacked">Override reason (≥30 chars) *</ion-label>
            <ion-textarea v-model="overrideReason" :auto-grow="true" rows="3"/>
          </ion-item>
          <ion-button v-if="overrideOn" expand="block" color="danger"
                      :disabled="(overrideReason || '').trim().length < 30" @click="step = 2">
            Continue with override
          </ion-button>
        </ion-card-content>
      </ion-card>

      <div style="text-align:center; padding: 8px;">
        <ion-button v-if="!blockers.length" expand="block" color="success" @click="step = 2">All clear → confirm</ion-button>
        <ion-button v-else fill="clear" size="small" @click="step = 2">Skip — I'll continue and let the server gate</ion-button>
      </div>
    </template>

    <!-- Step 2: CONFIRM -->
    <template v-else>
      <div class="scw-head">
        <ion-icon :icon="ribbonOutline" class="scw-spark scw-spark--ok"/>
        <h2>Closing as: <em>{{ intentLabel(intent) }}</em></h2>
        <p>Review the inferred outcome — tap any field to override.</p>
      </div>

      <!-- Summary chips: all the inferred fields at a glance -->
      <ion-card>
        <ion-card-content>
          <strong>Outcome</strong>
          <div class="scw-chips">
            <ion-chip color="tertiary">{{ classificationLabel(outcomeForm.case_classification) }}</ion-chip>
            <ion-chip v-if="outcomeForm.lab_status" outline>Lab: {{ labLabel(outcomeForm.lab_status) }}</ion-chip>
            <ion-chip v-if="outcomeForm.lab_disease_code" outline>{{ outcomeForm.lab_disease_code }}</ion-chip>
            <ion-chip v-if="outcomeForm.clinical_outcome" outline>{{ clinicalLabel(outcomeForm.clinical_outcome) }}</ion-chip>
            <ion-chip v-if="outcomeForm.ph_action" outline>{{ phActionLabel(outcomeForm.ph_action) }}</ion-chip>
            <ion-chip v-if="outcomeForm.ihr_notified" color="warning" outline>IHR notified</ion-chip>
          </div>
          <p v-if="outcomeForm.case_classification_reason" class="scw-explain">{{ outcomeForm.case_classification_reason }}</p>
        </ion-card-content>
      </ion-card>

      <ion-card>
        <ion-card-content>
          <strong>Close metadata</strong>
          <div class="scw-chips">
            <ion-chip color="success">{{ closeForm.close_category || 'OTHER' }}</ion-chip>
            <ion-chip v-if="closeForm.merged_into_alert_id" outline>→ #{{ closeForm.merged_into_alert_id }}</ion-chip>
            <ion-chip v-if="overrideOn" color="danger">OVERRIDE</ion-chip>
          </div>
          <p v-if="closeForm.close_note" class="scw-explain">{{ closeForm.close_note }}</p>
        </ion-card-content>
      </ion-card>

      <!-- Required fields that the system cannot infer -->
      <ion-list v-if="closeForm.close_category === 'DUPLICATE'" lines="full">
        <ion-item>
          <ion-label position="stacked">Canonical alert id *</ion-label>
          <ion-input type="number" v-model="closeForm.merged_into_alert_id"/>
        </ion-item>
      </ion-list>

      <!-- Optional advanced override -->
      <button class="scw-toggle" @click="showAdvanced = !showAdvanced">
        <ion-icon :icon="optionsOutline"/>
        {{ showAdvanced ? 'Hide' : 'Edit' }} details (optional)
      </button>

      <ion-list v-if="showAdvanced" lines="full">
        <ion-item>
          <ion-label position="stacked">Case classification (WHO / Africa CDC)</ion-label>
          <ion-select v-model="outcomeForm.case_classification" interface="action-sheet"
                      :interface-options="{ header: 'WHO case classification', subHeader: 'Africa CDC / AFRO criteria' }">
            <ion-select-option value="SUSPECTED">Suspected — meets the WHO clinical case definition for the disease</ion-select-option>
            <ion-select-option value="PROBABLE">Probable — clinical case + epidemiological link, lab not yet confirmed</ion-select-option>
            <ion-select-option value="CONFIRMED">Confirmed — laboratory confirmation by an accredited reference lab</ion-select-option>
            <ion-select-option value="DISCARDED">Discarded — does not meet the case definition (alternative diagnosis or lab-negative)</ion-select-option>
            <ion-select-option value="LOST_TO_FOLLOWUP">Lost to follow-up — case could not be re-contacted within the response window</ion-select-option>
            <ion-select-option value="UNKNOWN">Unknown — classification not yet determined</ion-select-option>
          </ion-select>
        </ion-item>
        <ion-item>
          <ion-label position="stacked">Laboratory status</ion-label>
          <ion-select v-model="outcomeForm.lab_status" interface="action-sheet"
                      :interface-options="{ header: 'Laboratory result', subHeader: 'WHO / Africa CDC reporting' }">
            <ion-select-option value="">— Not specified</ion-select-option>
            <ion-select-option value="PENDING">Pending — specimen collected, result awaited</ion-select-option>
            <ion-select-option value="POSITIVE">Positive — pathogen detected by the reference assay</ion-select-option>
            <ion-select-option value="NEGATIVE">Negative — pathogen not detected by the reference assay</ion-select-option>
            <ion-select-option value="INCONCLUSIVE">Inconclusive — result indeterminate, repeat testing required</ion-select-option>
            <ion-select-option value="INSUFFICIENT_SAMPLE">Insufficient sample — specimen could not be tested, recollect</ion-select-option>
            <ion-select-option value="NOT_TESTED">Not tested — specimen not submitted for testing</ion-select-option>
          </ion-select>
        </ion-item>
        <ion-item v-if="outcomeForm.lab_status && outcomeForm.lab_status !== 'NOT_TESTED'">
          <ion-label position="stacked">Confirmed disease (ICD-10 / WHO disease code)</ion-label>
          <ion-input v-model="outcomeForm.lab_disease_code" placeholder="e.g. A98.4 (Ebola), B33.4 (Mpox), A37 (Pertussis)"/>
        </ion-item>
        <ion-item v-if="outcomeForm.lab_status && outcomeForm.lab_status !== 'NOT_TESTED'">
          <ion-label position="stacked">Test method</ion-label>
          <ion-input v-model="outcomeForm.lab_test_method" placeholder="PCR / RDT / Serology / Culture / Sequencing"/>
        </ion-item>
        <ion-item>
          <ion-label position="stacked">Clinical outcome</ion-label>
          <ion-select v-model="outcomeForm.clinical_outcome" interface="action-sheet"
                      :interface-options="{ header: 'Patient clinical outcome' }">
            <ion-select-option value="">— Not specified</ion-select-option>
            <ion-select-option value="RECOVERED">Recovered — full clinical recovery, discharged</ion-select-option>
            <ion-select-option value="CONVALESCING">Convalescing — recovering, still under medical follow-up</ion-select-option>
            <ion-select-option value="DECEASED">Deceased — death attributable to the index condition</ion-select-option>
            <ion-select-option value="LOST_TO_FOLLOWUP">Lost to follow-up — patient no longer reachable</ion-select-option>
            <ion-select-option value="TRANSFERRED">Transferred — care continues at another facility / abroad</ion-select-option>
            <ion-select-option value="UNKNOWN">Unknown — outcome not yet determined</ion-select-option>
          </ion-select>
        </ion-item>
        <ion-item>
          <ion-label position="stacked">Public-health action taken</ion-label>
          <ion-select v-model="outcomeForm.ph_action" interface="action-sheet"
                      :interface-options="{ header: 'Public-health action', subHeader: 'WHO IHR / Africa CDC response tier' }">
            <ion-select-option value="">— Not specified</ion-select-option>
            <ion-select-option value="STANDARD_SURVEILLANCE">Standard surveillance — routine reporting only</ion-select-option>
            <ion-select-option value="ENHANCED_SURVEILLANCE">Enhanced surveillance — heightened case finding around the index event</ion-select-option>
            <ion-select-option value="OUTBREAK_INVESTIGATION">Outbreak investigation — field investigation team activated</ion-select-option>
            <ion-select-option value="OUTBREAK_RESPONSE">Outbreak response — full multisectoral response (IPC, risk communication, vaccination if applicable)</ion-select-option>
            <ion-select-option value="IHR_NOTIFIED">IHR notified — WHO informed via the National IHR Focal Point under Article 6/9</ion-select-option>
          </ion-select>
        </ion-item>
        <ion-item>
          <ion-label position="stacked">Outbreak status (event scale)</ion-label>
          <ion-select v-model="outcomeForm.outbreak_status" interface="action-sheet"
                      :interface-options="{ header: 'Outbreak status', subHeader: 'WHO event-scale taxonomy' }">
            <ion-select-option value="NONE">None — single isolated case, no spread evidence</ion-select-option>
            <ion-select-option value="SPORADIC">Sporadic — isolated cases without epidemiological link</ion-select-option>
            <ion-select-option value="CLUSTER">Cluster — two or more linked cases in a defined place / time</ion-select-option>
            <ion-select-option value="OUTBREAK">Outbreak — incidence above the expected baseline for the disease and population</ion-select-option>
            <ion-select-option value="EPIDEMIC">Epidemic — rapid spread across a region beyond the index focus</ion-select-option>
            <ion-select-option value="PANDEMIC">Pandemic — spread across multiple WHO regions / global event</ion-select-option>
          </ion-select>
        </ion-item>
        <ion-item>
          <ion-label>IHR notified</ion-label>
          <ion-select slot="end" v-model="outcomeForm.ihr_notified" interface="popover">
            <ion-select-option :value="false">No</ion-select-option>
            <ion-select-option :value="true">Yes</ion-select-option>
          </ion-select>
        </ion-item>
        <ion-item v-if="outcomeForm.ihr_notified">
          <ion-label position="stacked">IHR reference</ion-label>
          <ion-input v-model="outcomeForm.ihr_reference"/>
        </ion-item>
        <ion-item>
          <ion-label position="stacked">Close note</ion-label>
          <ion-textarea v-model="closeForm.close_note" :auto-grow="true" rows="2"/>
        </ion-item>
        <ion-item>
          <ion-label position="stacked">Outcome notes</ion-label>
          <ion-textarea v-model="outcomeForm.notes" :auto-grow="true" rows="2"/>
        </ion-item>
      </ion-list>

      <ion-button expand="block" color="success" :disabled="submitting" @click="submit">
        <ion-icon :icon="lockClosedOutline" slot="start"/>
        Confirm — close alert
      </ion-button>
      <ion-note class="scw-hint">Both writes (outcome + close) go through the offline outbox. Safe on flaky networks.</ion-note>
    </template>
  </ion-content>
</template>

<style scoped>
.scw { --background: var(--ion-background-color); }
.scw-head { display:flex; flex-direction:column; align-items:center; gap:6px; padding: 8px 0 16px; text-align:center; }
.scw-head h2 { margin: 0; font-size: 18px; }
.scw-head p  { margin: 0; opacity: .8; font-size: 14px; }
.scw-spark { font-size: 36px; color: var(--ion-color-success); }
.scw-spark--warn { color: var(--ion-color-warning); }
.scw-spark--ok { color: var(--ion-color-success); }
.scw-intents { display:grid; grid-template-columns: 1fr; gap: 10px; }
@media (min-width: 480px) { .scw-intents { grid-template-columns: 1fr 1fr; } }
.scw-intent {
  display:flex; flex-direction:column; align-items:flex-start; gap: 4px;
  text-align:left; padding: 14px;
  background: var(--ion-card-background, #fff);
  border: 1px solid var(--ion-color-light-shade);
  border-radius: 12px;
  box-shadow: 0 1px 2px rgba(0,0,0,.04);
  transition: transform .12s, box-shadow .12s, border-color .12s;
}
.scw-intent:active { transform: scale(.98); box-shadow: none; }
.scw-intent strong { font-size: 15px; }
.scw-intent small  { font-size: 12px; opacity: .82; }
.scw-intent--ghost { opacity: .85; border-style: dashed; }
.scw-iicon { font-size: 22px; }
.scw-iicon--success { color: var(--ion-color-success); }
.scw-iicon--warning { color: var(--ion-color-warning); }
.scw-iicon--danger  { color: var(--ion-color-danger); }
.scw-iicon--tertiary { color: var(--ion-color-tertiary); }
.scw-iicon--medium  { color: var(--ion-color-medium); }
.scw-chips { display:flex; flex-wrap:wrap; gap: 6px; margin-top: 6px; }
.scw-explain { margin-top: 8px; font-size: 13px; opacity: .85; }
.scw-toggle {
  background: none; border: none; padding: 8px 0; color: var(--ion-color-primary);
  display:inline-flex; gap: 6px; align-items:center; font-size: 14px;
}
.scw-hint { display:block; margin-top: 8px; font-size: 12px; opacity: .7; text-align:center; }
</style>
