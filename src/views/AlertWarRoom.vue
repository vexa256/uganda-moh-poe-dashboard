<script setup>
/**
 * AlertWarRoom.vue — single-screen mobile investigation hub.
 *
 * Drives the FULL alert lifecycle from open → closed within the app:
 *   - Overview (status, owner, SLA, IHR tier)
 *   - Case File (demographics / vitals / symptoms / exposures / travel /
 *     samples / suspected diseases) — read-only mirror of secondary screening
 *   - Advisor (PPE / IHR / referral / samples / traveler script + rule cites)
 *   - Followups (RTSL 14 actions + create + status transitions + blocker badge)
 *   - Timeline (append-only event log)
 *   - Evidence (camera + file uploads, gallery)
 *   - Comments (thread, append-only)
 *   - Notifications (this alert's email log)
 *   - Outcome (case classification + lab + clinical + IHR notification)
 *   - Breach reports (root-cause taxonomy)
 *
 * Action bar surfaces: Acknowledge, Reassign, Escalate, Close, Reopen,
 * Record Outcome, Log Breach, Declare PHEIC. Every button is gated by
 * useCan(perm, alert) AND by server-returned permissions[]. Server is
 * source of truth — these flags are paranoid UX hints, not auth.
 *
 * Closure flow:
 *   tap [Close] → CloseAlertSheet shows blockers if any
 *     → tap a blocker → BlockerResolveSheet (mark COMPLETED|NOT_APPLICABLE
 *       + reason ≥10 chars) — closes back into the close sheet
 *     → all clear → category picker (RESOLVED/FALSE_POSITIVE/DUPLICATE/…)
 *       → optional outcome wizard → confirm
 *   NATIONAL_ADMIN sees an "Override blockers" toggle (≥30-char reason).
 */
import { ref, computed, onMounted, watch, h } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from '@/i18n'
const { t } = useI18n()
import {
  IonPage, IonHeader, IonToolbar, IonTitle, IonButtons, IonBackButton,
  IonContent, IonSegment, IonSegmentButton, IonLabel, IonIcon,
  IonCard, IonCardContent, IonChip, IonBadge,
  IonList, IonItem, IonInput, IonTextarea, IonSelect, IonSelectOption,
  IonButton, IonSpinner, IonModal, IonNote, IonRefresher, IonRefresherContent,
  IonFooter, IonAvatar, IonText, IonGrid, IonRow, IonCol, IonRippleEffect,
  alertController,
} from '@ionic/vue'
import {
  alertCircleOutline, checkmarkCircle, closeCircle, swapHorizontal,
  arrowUpCircleOutline, lockClosedOutline, refreshOutline, attachOutline,
  chatbubblesOutline, documentTextOutline, medkitOutline, megaphoneOutline,
  shieldCheckmarkOutline, timeOutline, flashOutline, warningOutline,
  cameraOutline, sendOutline, personAddOutline, ribbonOutline,
  thermometerOutline, airplaneOutline, personCircleOutline,
  flaskOutline, clipboardOutline, pulseOutline,
  chevronBackOutline, ellipsisHorizontalOutline, addOutline,
  arrowForwardOutline, returnUpBackOutline,
} from 'ionicons/icons'
import { useAlertLifecycle } from '@/composables/useAlertLifecycle'
import { useCan } from '@/composables/useCan'
import { sentinelToast } from '@/services/sentinelToast'
import { inFlight } from '@/services/httpInterceptor'
import SmartCloseWizard from '@/components/alerts/SmartCloseWizard.vue'
import { cloudOfflineOutline, syncOutline } from 'ionicons/icons'

const route = useRoute()
const router = useRouter()
const { can, role: roleRef, auth } = useCan()
const isNationalAdmin = computed(() => roleRef.value === 'NATIONAL_ADMIN')

const alertId = computed(() => Number(route.params.id || 0))
const lc = useAlertLifecycle(alertId)

const tab = ref('overview')
const refreshing = ref(false)
// Forensic FIX F-1: single in-flight guard. Every async action handler wraps
// its body in `runAction()` so double-taps cannot fire two requests.
const actionBusy = ref(false)
async function runAction(fn) {
  if (actionBusy.value) return
  actionBusy.value = true
  try { return await fn() } finally { actionBusy.value = false }
}

// ── modal flags
const closeOpen     = ref(false)
const blockerOpen   = ref(false)
const blockerCtx    = ref(null)        // followup row currently being resolved
const reassignOpen  = ref(false)
const escalateOpen  = ref(false)
const reopenOpen    = ref(false)
const outcomeOpen   = ref(false)
const breachOpen    = ref(false)
const followupOpen  = ref(false)
const evidenceOpen  = ref(false)
const moreOpen      = ref(false)        // overflow action sheet

// ── derived
const alert = computed(() => lc.caseFile.value?.alert || null)
const screening = computed(() => lc.caseFile.value?.screening || null)
const symptoms = computed(() => lc.caseFile.value?.symptoms || [])
const exposures = computed(() => lc.caseFile.value?.exposures || [])
const travel = computed(() => lc.caseFile.value?.travel || [])
const samples = computed(() => lc.caseFile.value?.samples || [])
const actions = computed(() => lc.caseFile.value?.actions || [])
const suspected = computed(() => lc.caseFile.value?.suspected_diseases || [])
const diseaseMeta = (code) => window.DISEASES?.getDiseaseById?.(code, { includeLegacy: true }) || null
const status = computed(() => alert.value?.status || '—')
const riskTone = computed(() => ({
  CRITICAL: 'danger', HIGH: 'warning', MEDIUM: 'tertiary', LOW: 'success',
}[alert.value?.risk_level] || 'medium'))
const statusTone = computed(() => ({ OPEN: 'warning', ACKNOWLEDGED: 'tertiary', CLOSED: 'success' }[status.value] || 'medium'))

const perms = computed(() => lc.permissions.value || {})
const canSee = (p, fallback = false) => (perms.value && p in perms.value) ? !!perms.value[p] : fallback

// ── load
async function load() {
  // 1-H FIX: alertId.value is 0 when onMounted fires before Ionic commits
  // route params. Guard here — the watch(alertId) below fires the real load
  // once the route param is populated with a valid non-zero id.
  if (!alertId.value) return
  await lc.loadCaseFile()
  // Fire advisor + comms-inbox in parallel; best-effort, must not block render.
  Promise.allSettled([lc.loadAdvisor(), lc.loadCommsInbox()])
}
onMounted(load)
watch(alertId, (id) => { if (id) load() })

async function onRefresh(ev) {
  refreshing.value = true
  try { await load() } finally { refreshing.value = false; ev.target.complete() }
}

// ── case-file deep link → SecondaryRecords with the exact record auto-opened
// SecondaryRecords.vue's tryDeepLinkOpen() reads ?open=<client_uuid> on every
// onIonViewWillEnter and immediately opens the detail modal for that record.
// router.push (not replace) so the Ionic back button returns here.
function openCaseFile() {
  const caseUuid = screening.value?.client_uuid
  if (caseUuid) {
    router.push({ name: 'SecondaryRecords', query: { open: caseUuid } })
  } else {
    router.push({ name: 'SecondaryRecords' })
  }
}

// ── action handlers
// FIX: window.confirm() is blocked in Capacitor/Ionic webviews and silently
// returns false, cancelling the acknowledge. Use Ionic alertController instead.
async function doAcknowledge() {
  if (actionBusy.value) return // F-1: drop double-tap that fires before dialog mounts
  const a = await alertController.create({
    header: 'Acknowledge alert?',
    message: `${alert.value?.alert_code || 'This alert'}\nThis records you as the responding officer.`,
    buttons: [
      { text: 'Cancel', role: 'cancel' },
      {
        text: 'Acknowledge', role: 'confirm',
        handler: async () => runAction(async () => {
          try { await lc.acknowledge() } catch (e) { sentinelToast(e?.message || 'Acknowledge failed', 'danger') }
        }),
      },
    ],
  })
  await a.present()
}

// (Close flow now handled entirely by SmartCloseWizard — see modal below.)
const blockerForm = ref({ resolution: 'COMPLETED', reason: '', evidence_ref: '' })
function openBlocker(f) {
  blockerCtx.value = f
  blockerForm.value = { resolution: 'COMPLETED', reason: '', evidence_ref: '' }
  blockerOpen.value = true
}
async function resolveBlockerSubmit() {
  if ((blockerForm.value.reason || '').trim().length < 10) return sentinelToast('Reason must be ≥10 chars.', 'warning')
  await runAction(async () => {
    const data = await lc.resolveBlocker(blockerCtx.value.id, {
      resolution: blockerForm.value.resolution,
      reason: blockerForm.value.reason,
      evidence_ref: blockerForm.value.evidence_ref || null,
    })
    if (data) { blockerOpen.value = false; blockerCtx.value = null }
  })
}

// ── reassign
const reassignForm = ref({ owner_user_id: '', level: '', reason: '' })
async function submitReassign() {
  if (!Number(reassignForm.value.owner_user_id)) return sentinelToast('owner_user_id required.', 'warning')
  await runAction(async () => {
    const ok = await lc.reassign({
      owner_user_id: Number(reassignForm.value.owner_user_id),
      level: reassignForm.value.level || null,
      reason: reassignForm.value.reason || null,
    })
    if (ok) { reassignOpen.value = false; reassignForm.value = { owner_user_id: '', level: '', reason: '' } }
  })
}

// ── escalate
const escalateForm = ref({ to_level: 'PHEOC', reason: '', notify: true })
async function submitEscalate() {
  if ((escalateForm.value.reason || '').trim().length < 10) return sentinelToast('Reason must be ≥10 chars.', 'warning')
  await runAction(async () => {
    const ok = await lc.escalate(escalateForm.value)
    if (ok) { escalateOpen.value = false; escalateForm.value = { to_level: 'PHEOC', reason: '', notify: true } }
  })
}

// ── reopen
const reopenReason = ref('')
async function submitReopen() {
  if ((reopenReason.value || '').trim().length < 10) return sentinelToast('Reason must be ≥10 chars.', 'warning')
  await runAction(async () => {
    const ok = await lc.reopen(reopenReason.value)
    if (ok) { reopenOpen.value = false; reopenReason.value = '' }
  })
}

// ── outcome
const outcomeForm = ref({
  case_classification: 'SUSPECTED',
  lab_status: '', lab_disease_code: '', lab_test_method: '',
  clinical_outcome: '', ph_action: '', outbreak_status: 'NONE',
  ihr_notified: false, ihr_reference: '',
  case_classification_reason: '', notes: '',
})
async function submitOutcome() {
  await runAction(async () => {
    await lc.recordOutcome(outcomeForm.value)
    outcomeOpen.value = false
  })
}

// ── breach
const breachForm = ref({
  phase: 'NOTIFY', target_hours: 24, elapsed_hours: 0,
  root_cause_category: 'COORDINATION', root_cause_text: '', mitigation_plan: '',
  owner_level: 'DISTRICT',
})
async function submitBreach() {
  if ((breachForm.value.root_cause_text || '').trim().length < 10) return sentinelToast('Root-cause text ≥10 chars.', 'warning')
  if ((breachForm.value.mitigation_plan || '').trim().length < 10) return sentinelToast('Mitigation plan ≥10 chars.', 'warning')
  await runAction(async () => {
    const ok = await lc.logBreach(breachForm.value)
    if (ok) breachOpen.value = false
  })
}

// ── add followup
const newFollowup = ref({ action_code: 'CUSTOM', action_label: '', due_at: '', blocks_closure: false, notes: '' })
async function submitFollowup() {
  if (!(newFollowup.value.action_label || '').trim()) return sentinelToast('Action label required.', 'warning')
  await runAction(async () => {
    await lc.addFollowup(newFollowup.value)
    followupOpen.value = false
    newFollowup.value = { action_code: 'CUSTOM', action_label: '', due_at: '', blocks_closure: false, notes: '' }
  })
}

// ── update followup status
async function updateFollowupStatus(f, newStatus, reason) {
  await runAction(async () => { await lc.updateFollowup(f.id, { status: newStatus, reason }) })
}

// ── comment
const newComment = ref('')
async function submitComment() {
  const t = (newComment.value || '').trim()
  if (!t) return
  await runAction(async () => {
    await lc.postComment(t)
    newComment.value = ''
  })
}

// ── evidence (mobile camera)
const evidenceForm = ref({ category: 'PHOTO', title: '', external_url: '', file_mime: 'image/jpeg', notes: '' })
async function captureAndAttachPhoto() {
  await runAction(async () => {
    try {
      const { Camera, CameraResultType, CameraSource } = await import('@capacitor/camera')
      const photo = await Camera.getPhoto({
        quality: 70, allowEditing: false, resultType: CameraResultType.Uri,
        source: CameraSource.Prompt,
      })
      // file_ref points to local URI; server stores metadata only (per addEvidence
      // contract) and the URI works as a deep-link from the inbox web view.
      await lc.addEvidence({
        category: 'PHOTO',
        title: evidenceForm.value.title || ('Photo ' + new Date().toISOString()),
        file_ref: photo.webPath || photo.path || '',
        file_mime: 'image/jpeg',
        file_size_bytes: 0,
        external_url: null,
        visibility: 'INTERNAL',
      })
      evidenceOpen.value = false
    } catch (e) {
      sentinelToast('Camera unavailable: ' + (e?.message || e), 'danger')
    }
  })
}
async function submitEvidenceUrl() {
  if (!(evidenceForm.value.external_url || '').match(/^https?:\/\//i)) return sentinelToast('Provide a https:// URL.', 'warning')
  await runAction(async () => {
    await lc.addEvidence({
      category: evidenceForm.value.category || 'OTHER',
      title: evidenceForm.value.title || 'External evidence',
      external_url: evidenceForm.value.external_url,
      file_ref: null,
      file_mime: 'text/url',
      file_size_bytes: 0,
      visibility: 'INTERNAL',
      description: evidenceForm.value.notes || null,
    })
    evidenceOpen.value = false
    evidenceForm.value = { category: 'PHOTO', title: '', external_url: '', file_mime: 'image/jpeg', notes: '' }
  })
}

// ── helpers
function fmtDate(s) { if (!s) return '—'; try { return new Date(s).toLocaleString() } catch { return s } }
function fmtDateShort(s) { if (!s) return '—'; try { return new Date(s).toLocaleDateString() } catch { return s } }
function severityTone(sev) {
  return ({ INFO: 'medium', WARN: 'warning', ERROR: 'danger', CRITICAL: 'danger' })[sev] || 'medium'
}

// Case-file vital sign helpers — hint colour-coded ranges for fast triage.
const hasAnyVital = computed(() => {
  const s = screening.value || {}
  return s.temperature_value != null || s.pulse_rate != null || s.respiratory_rate != null
      || s.oxygen_saturation != null || s.bp_systolic != null || s.general_appearance
})
function vitalTone(kind, raw) {
  const v = Number(raw)
  if (!isFinite(v)) return ''
  if (kind === 'temp') {
    if (v >= 38.5) return 'cf-v-n--danger'
    if (v >= 37.5 || v < 36) return 'cf-v-n--warn'
    return 'cf-v-n--ok'
  }
  if (kind === 'pulse') {
    if (v >= 120 || v < 50) return 'cf-v-n--danger'
    if (v >= 100 || v < 60) return 'cf-v-n--warn'
    return 'cf-v-n--ok'
  }
  if (kind === 'rr') {
    if (v >= 30 || v < 8) return 'cf-v-n--danger'
    if (v >= 22 || v < 12) return 'cf-v-n--warn'
    return 'cf-v-n--ok'
  }
  if (kind === 'spo2') {
    if (v < 90) return 'cf-v-n--danger'
    if (v < 94) return 'cf-v-n--warn'
    return 'cf-v-n--ok'
  }
  return ''
}
// ── New helpers for the rebuilt UI ────────────────────────────────────
function relTime(s) {
  if (!s) return ''
  const t = Date.parse(s); if (isNaN(t)) return ''
  const diff = (Date.now() - t) / 1000
  if (diff < 60) return 'just now'
  if (diff < 3600) return Math.floor(diff / 60) + 'm'
  if (diff < 86400) return Math.floor(diff / 3600) + 'h'
  if (diff < 604800) return Math.floor(diff / 86400) + 'd'
  try { return new Date(s).toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) } catch { return '' }
}

const travellerLabel = computed(() => {
  const a = alert.value || {}
  if (a.traveler_anonymous_code) return a.traveler_anonymous_code
  if (a.traveler_label) return a.traveler_label
  const bits = [
    a.traveler_gender ? a.traveler_gender[0].toUpperCase() : null,
    a.traveler_age_years ? a.traveler_age_years + 'y' : null,
    a.traveler_nationality,
  ].filter(Boolean)
  return bits.length ? bits.join(' · ') : 'Anonymous'
})

const headlineCondition = computed(() => {
  const a = alert.value || {}
  return a.top_disease_name || a.top_disease_code || a.syndrome || a.alert_title || 'Investigation'
})

// Single primary CTA — Apple/Tesla single-action philosophy.
const primary = computed(() => {
  const a = alert.value
  if (!a) return null
  if (a.status === 'OPEN' && (canSee('can_acknowledge') || can('alerts.acknowledge', a))) {
    return { label: 'Acknowledge', tone: 'amber', icon: checkmarkCircle, fn: doAcknowledge }
  }
  if (a.status === 'ACKNOWLEDGED' && (canSee('can_close') || can('alerts.close', a))) {
    return { label: 'Close case', tone: 'success', icon: lockClosedOutline, fn: () => (closeOpen.value = true) }
  }
  if (a.status === 'CLOSED' && (canSee('can_reopen') || can('alerts.reopen', a))) {
    return { label: 'Reopen', tone: 'info', icon: returnUpBackOutline, fn: () => (reopenOpen.value = true) }
  }
  if (a.status === 'OPEN' && (canSee('can_close') || can('alerts.close', a))) {
    return { label: 'Close', tone: 'success', icon: lockClosedOutline, fn: () => (closeOpen.value = true) }
  }
  return { label: 'Add comment', tone: 'neutral', icon: chatbubblesOutline, fn: () => (tab.value = 'comments') }
})

const overflowActions = computed(() => {
  const a = alert.value
  if (!a) return []
  const out = []
  if (a.status !== 'CLOSED' && (canSee('can_escalate') || can('alerts.escalate', a))) {
    out.push({ id: 'escalate', label: 'Escalate', sub: 'Route up the IHR ladder', icon: arrowUpCircleOutline, fn: () => (escalateOpen.value = true) })
  }
  if (canSee('can_reassign') || can('alerts.reassign', a)) {
    out.push({ id: 'reassign', label: 'Reassign owner', sub: 'Hand to another officer', icon: swapHorizontal, fn: () => (reassignOpen.value = true) })
  }
  if (canSee('can_record_outcome') || can('alerts.outcome.record', a)) {
    out.push({ id: 'outcome', label: 'Record outcome', sub: 'Classification · lab · clinical', icon: ribbonOutline, fn: () => (outcomeOpen.value = true) })
  }
  if (canSee('can_log_breach') || can('alerts.breach.log', a)) {
    out.push({ id: 'breach', label: 'Log SLA breach', sub: '7-1-7 root-cause RCA', icon: warningOutline, fn: () => (breachOpen.value = true) })
  }
  if (isNationalAdmin.value && a.status !== 'CLOSED') {
    out.push({ id: 'pheic', label: 'Declare PHEIC', sub: 'WHO coordination pathway', icon: megaphoneOutline, fn: () => lc.declarePheic('PHEIC pathway entered.') })
  }
  return out
})

// SLA hours since open (Syne numeric in stats ribbon).
const slaHours = computed(() => {
  if (!alert.value?.created_at) return '0'
  const h = (Date.now() - Date.parse(alert.value.created_at)) / 36e5
  if (!isFinite(h)) return '0'
  return h < 1 ? '<1' : Math.floor(h).toString()
})

// Risk → POE-style colour key (red/amber/purple/green).
const riskKey = computed(() => ({
  CRITICAL: 'red', HIGH: 'amber', MEDIUM: 'purple', LOW: 'green',
}[alert.value?.risk_level] || 'blue'))

// Short labels for the stats ribbon — long enums like DISTRICT_SUPERVISOR
// or ACKNOWLEDGED would overflow the narrow per-stat column on small phones.
const shortStatus = computed(() => ({
  OPEN: 'Open', ACKNOWLEDGED: 'Acked', CLOSED: 'Closed',
}[alert.value?.status] || (alert.value?.status?.slice(0, 5) || '—')))
const shortRisk = computed(() => ({
  CRITICAL: 'Crit', HIGH: 'High', MEDIUM: 'Med', LOW: 'Low',
}[alert.value?.risk_level] || '—'))
const shortRoute = computed(() => ({
  DISTRICT: 'Distr', PHEOC: 'PHEOC', NATIONAL: 'Natl',
}[alert.value?.routed_to_level] || (alert.value?.routed_to_level?.slice(0, 5) || '—')))

const tabs = computed(() => [
  { id: 'overview',  label: 'Overview' },
  { id: 'case',      label: 'Case file' },
  { id: 'advisor',   label: 'Advisor' },
  { id: 'followups', label: 'Tasks',         badge: lc.blockers.value.length || null },
  { id: 'timeline',  label: 'Timeline',      badge: lc.timeline.value.length || null },
  { id: 'evidence',  label: 'Evidence',      badge: lc.evidence.value.length || null },
  { id: 'comments',  label: 'Comments',      badge: lc.comments.value.length || null },
  { id: 'comms',     label: 'Notifications' },
  { id: 'breach',    label: 'Breaches',      badge: lc.breachReports.value.length || null },
])

// Symptom helpers — picks an emoji glyph + flag-card tone class. Severity
// SEVERE / is_critical → red flag; MODERATE → amber; mild / unknown → blue.
function symptomFlagClass(s) {
  const sev = String(s?.severity || '').toUpperCase()
  if (sev === 'SEVERE' || s?.is_critical) return 'pdr-flag--danger'
  if (sev === 'MODERATE') return 'pdr-flag--warn'
  return 'pdr-flag--info'
}
function symptomGlyph(s) {
  const code = String(s?.symptom_code || s?.symptom_name || '').toUpperCase()
  if (code.includes('FEVER') || code.includes('TEMP')) return '🌡'
  if (code.includes('COUGH')) return '🫁'
  if (code.includes('DIARRH')) return '💧'
  if (code.includes('VOMIT')) return '🤢'
  if (code.includes('RASH')) return '✦'
  if (code.includes('BLEED') || code.includes('HEMOR')) return '◉'
  if (code.includes('HEAD') || code.includes('PAIN')) return '◐'
  if (code.includes('FATIG') || code.includes('WEAK')) return '◔'
  if (code.includes('JAUND')) return '◑'
  return '•'
}

function confidencePct(raw) {
  const n = Number(raw)
  if (!isFinite(n)) return 0
  // Backend stores 0–1 OR 0–100 depending on engine version; normalise.
  return n <= 1 ? Math.round(n * 100) : Math.round(n)
}

// Tiny inline section wrapper — keeps the Case File markup readable. Not
// extracted to a separate file because it's used only here and lives in the
// premium look that should ship as a single SFC.
const CfSection = {
  props: { title: String, icon: { type: [String, Object], default: null }, tone: { type: String, default: 'default' } },
  setup(props, { slots }) {
    return () => h('section', { class: ['cf-section', `cf-section--${props.tone}`] }, [
      h('header', { class: 'cf-section-h' }, [
        props.icon ? h(IonIcon, { icon: props.icon, class: 'cf-section-i' }) : null,
        h('h3', { class: 'cf-section-t' }, props.title),
      ]),
      h('div', { class: 'cf-section-b' }, slots.default ? slots.default() : []),
    ])
  },
}
</script>

<template>
  <ion-page>
    <!-- Global HTTP loading bar — top-of-screen progress for every API call in this view -->
    <div v-if="inFlight > 0" class="awr-http-overlay" aria-live="polite" aria-label="Loading">
      <div class="awr-http-bar"><div class="awr-http-progress"/></div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         DARK ZONE — Header · Stats ribbon · Tab chips
    ══════════════════════════════════════════════════════════════════ -->
    <ion-header :translucent="false" class="aw-header">
      <ion-toolbar class="aw-toolbar">
        <ion-buttons slot="start">
          <ion-back-button default-href="/my-cases" text="" style="--color:#00B4FF;" aria-label="Back"/>
        </ion-buttons>
        <ion-title>
          <div class="aw-title-block">
            <span class="aw-eyebrow">ECSA-HC · IHR 2005 · CASE LIFECYCLE</span>
            <span class="aw-title">{{ alert?.alert_code || 'War Room' }}</span>
          </div>
        </ion-title>
        <button v-if="alert && overflowActions.length" slot="end" class="aw-more-badge" @click="moreOpen = true" aria-label="More actions">
          <span class="aw-more-dots">⋯</span>
        </button>
      </ion-toolbar>

      <!-- Stats ribbon — current SLA / risk / routing snapshot. Labels are
           truncated short forms so long enums (ACKNOWLEDGED, DISTRICT_SUPERVISOR,
           NATIONAL_ADMIN) never wrap or overflow the thin stat columns. -->
      <div v-if="alert" class="aw-stats-ribbon">
        <div class="aw-stats-texture"/>
        <div class="aw-stat">
          <span class="aw-stat-n" :class="`aw-stat-n--${statusTone}`">{{ status[0] }}</span>
          <span class="aw-stat-l">{{ shortStatus }}</span>
        </div>
        <div class="aw-stat-sep"/>
        <div class="aw-stat">
          <span class="aw-stat-n" :class="`aw-stat-n--${riskKey}`">{{ alert.risk_level?.[0] || '·' }}</span>
          <span class="aw-stat-l">{{ shortRisk }}</span>
        </div>
        <div class="aw-stat-sep"/>
        <div class="aw-stat">
          <span class="aw-stat-n aw-stat-n--blue">{{ alert.routed_to_level?.[0] || '·' }}</span>
          <span class="aw-stat-l">{{ shortRoute }}</span>
        </div>
        <div class="aw-stat-sep"/>
        <div class="aw-stat">
          <span class="aw-stat-n aw-stat-n--purple">{{ slaHours }}<small>h</small></span>
          <span class="aw-stat-l">Open</span>
        </div>
        <div class="aw-stat-sep"/>
        <div class="aw-stat">
          <span class="aw-stat-n aw-stat-n--teal">{{ lc.followups.value.length || 0 }}</span>
          <span class="aw-stat-l">Tasks</span>
        </div>
        <div class="aw-stat-sep"/>
        <div class="aw-stat">
          <span class="aw-stat-n aw-stat-n--amber">{{ lc.blockers.value.length || 0 }}</span>
          <span class="aw-stat-l">Blocks</span>
        </div>
      </div>

      <!-- Tab chip row -->
      <div v-if="alert" class="aw-chip-row">
        <button v-for="t in tabs" :key="t.id"
                class="aw-chip" :class="tab === t.id && 'aw-chip--active'"
                @click="tab = t.id" :aria-pressed="tab === t.id">
          {{ t.label }}
          <em v-if="t.badge">{{ t.badge }}</em>
        </button>
      </div>
    </ion-header>

    <!-- ══════════════════════════════════════════════════════════════
         LIGHT ZONE — Content
    ══════════════════════════════════════════════════════════════════ -->
    <ion-content
      :fullscreen="true" :scroll-y="true"
      style="--background:linear-gradient(180deg,#EAF0FA 0%,#F2F5FB 40%,#E4EBF7 100%);--color:#0B1A30;"
      class="aw-scroll">
      <ion-refresher slot="fixed" @ionRefresh="onRefresh">
        <ion-refresher-content refreshing-spinner="crescent"/>
      </ion-refresher>

      <!-- the rest of the markup (loading, hero, CTA, vitals cluster, panes)
           continues below using the same .war-* classes which are now
           re-skinned by the new stylesheet. -->
      <div class="aw-spacer-top"/>
      <ion-refresher slot="fixed" @ionRefresh="onRefresh"><ion-refresher-content/></ion-refresher>

      <!-- Cross-device hint: this alert was captured on a different phone.
           Lifecycle ops (acknowledge / close / escalate / resolve) ARE
           permitted from this device — server enforces scope. Only the
           full screening sync is gated to prevent canonical-record
           corruption (handled in SecondaryScreening's syncCaseToServer). -->
      <div v-if="lc.isReadOnlyByDevice && lc.isReadOnlyByDevice.value" class="war-strip war-strip--stale"
           role="status" aria-live="polite">
        <ion-icon :icon="lockClosedOutline"/>
        <span>Cross-device session — lifecycle actions allowed. Screening edits stay on the originating phone.</span>
      </div>

      <!-- Connectivity strip — one row, slate-quiet, only when needed. -->
      <div v-if="!lc.isOnline.value || lc.queuedForThisAlert.value > 0 || (lc.isStale.value && !lc.loadingFresh.value)"
           class="war-strip" :class="!lc.isOnline.value ? 'war-strip--off' : (lc.queuedForThisAlert.value ? 'war-strip--sync' : 'war-strip--stale')">
        <ion-icon :icon="!lc.isOnline.value ? cloudOfflineOutline : (lc.queuedForThisAlert.value ? syncOutline : timeOutline)"/>
        <span v-if="!lc.isOnline.value && lc.queuedForThisAlert.value">{{ lc.queuedForThisAlert.value }} queued · offline</span>
        <span v-else-if="!lc.isOnline.value">Offline · cached</span>
        <span v-else-if="lc.queuedForThisAlert.value">Syncing {{ lc.queuedForThisAlert.value }}…</span>
        <span v-else>Refreshing</span>
      </div>

      <!-- Loading skeleton — Apple-spec breathing pulse, no spinner overlay. -->
      <div v-if="lc.loading.value && !alert" class="war-skel">
        <div class="war-skel-hero"/>
        <div class="war-skel-row"/>
        <div class="war-skel-grid">
          <div class="war-skel-tile"/><div class="war-skel-tile"/>
          <div class="war-skel-tile"/><div class="war-skel-tile"/>
        </div>
      </div>

      <div v-else-if="!alert" class="war-empty">
        <div class="war-empty-glyph"><ion-icon :icon="alertCircleOutline"/></div>
        <h3>Not found</h3>
        <p>{{ lc.lastError.value?.message || 'This case is no longer accessible.' }}</p>
        <button class="war-btn-ghost" @click="load">Retry</button>
      </div>

      <template v-else>
        <!-- HERO — full-bleed instrument cluster.
             Tone is driven by risk; the radial glow is the single visual cue.
             Apple Large Title ↔ Tesla minimal numerics co-existence. -->
        <section class="war-hero" :class="`war-hero--${riskTone}`">
          <div class="war-hero-glow"/>
          <div class="war-hero-meta">
            <span class="war-status-line">
              <span class="war-status-dot" :class="`war-status-dot--${statusTone}`"/>
              <span>{{ status }}</span>
            </span>
            <span class="war-meta-sep"/>
            <span>{{ relTime(alert.created_at) }} ago</span>
            <span class="war-meta-sep"/>
            <span class="war-meta-route">{{ alert.routed_to_level }}</span>
          </div>

          <h1 class="war-hero-name">{{ travellerLabel }}</h1>
          <p class="war-hero-cond">{{ headlineCondition }}</p>

          <div class="war-hero-pills" v-if="alert.ihr_tier || alert.poe_code || alert.top_disease_confidence != null">
            <span v-if="alert.ihr_tier" class="war-pill war-pill--solid">{{ String(alert.ihr_tier).replace('_ALWAYS_NOTIFIABLE','').replace('_ANNEX2','') }}</span>
            <span v-if="alert.poe_code" class="war-pill">{{ alert.poe_code }}</span>
            <span v-if="alert.top_disease_confidence != null" class="war-pill">{{ confidencePct(alert.top_disease_confidence) }}% match</span>
          </div>

          <div class="war-hero-risk">
            <span class="war-risk-glyph">{{ (alert.risk_level || '·')[0] }}</span>
            <span class="war-risk-label">{{ alert.risk_level }}</span>
          </div>
        </section>

        <!-- Single decisive primary action — Tesla single-CTA philosophy. -->
        <div v-if="primary" class="war-cta-wrap">
          <button class="war-cta" :class="`war-cta--${primary.tone}`" :disabled="actionBusy" @click="primary.fn">
            <ion-icon :icon="primary.icon" class="war-cta-icon"/>
            <span class="war-cta-label">{{ primary.label }}</span>
            <ion-icon :icon="arrowForwardOutline" class="war-cta-arrow"/>
          </button>
        </div>

        <!-- Vital instrument cluster — Tesla dashboard. Abnormal = colour glow. -->
        <section v-if="hasAnyVital && screening" class="war-cluster">
          <div v-if="screening.temperature_value != null" class="war-vital" :class="vitalTone('temp', screening.temperature_value).replace('cf-v-n--', 'war-vital--')">
            <span class="war-vital-l">Temperature</span>
            <span class="war-vital-v"><b>{{ screening.temperature_value }}</b><small>°{{ (screening.temperature_unit || 'C')[0] }}</small></span>
          </div>
          <div v-if="screening.pulse_rate != null" class="war-vital" :class="vitalTone('pulse', screening.pulse_rate).replace('cf-v-n--', 'war-vital--')">
            <span class="war-vital-l">Pulse</span>
            <span class="war-vital-v"><b>{{ screening.pulse_rate }}</b><small>bpm</small></span>
          </div>
          <div v-if="screening.respiratory_rate != null" class="war-vital" :class="vitalTone('rr', screening.respiratory_rate).replace('cf-v-n--', 'war-vital--')">
            <span class="war-vital-l">Respiration</span>
            <span class="war-vital-v"><b>{{ screening.respiratory_rate }}</b><small>/min</small></span>
          </div>
          <div v-if="screening.oxygen_saturation != null" class="war-vital" :class="vitalTone('spo2', screening.oxygen_saturation).replace('cf-v-n--', 'war-vital--')">
            <span class="war-vital-l">SpO₂</span>
            <span class="war-vital-v"><b>{{ screening.oxygen_saturation }}</b><small>%</small></span>
          </div>
          <div v-if="screening.bp_systolic != null || screening.blood_pressure_systolic != null" class="war-vital"
               :class="vitalTone('bp', screening.bp_systolic ?? screening.blood_pressure_systolic).replace('cf-v-n--', 'war-vital--')">
            <span class="war-vital-l">BP</span>
            <span class="war-vital-v">
              <b>{{ screening.bp_systolic ?? screening.blood_pressure_systolic }}</b>
              <small v-if="screening.bp_diastolic ?? screening.blood_pressure_diastolic">/{{ screening.bp_diastolic ?? screening.blood_pressure_diastolic }}</small>
              <small v-else>mmHg</small>
            </span>
          </div>
        </section>

        <!-- Tab chip row was moved into the IonHeader (Sentinel dark zone)
             so the canvas below stays content-only. -->


        <!-- Overview -->
        <section v-if="tab === 'overview'" class="awr-section">
          <!-- ── Case at a glance — every key clinical signal in one card ── -->
          <ion-card v-if="screening">
            <ion-card-content>
              <h4>Clinical at a glance</h4>
              <div class="awr-glance-grid">
                <div class="awr-glance">
                  <span class="awr-glance-k">Risk</span>
                  <ion-chip :color="riskTone" outline>{{ alert.risk_level }}</ion-chip>
                </div>
                <div class="awr-glance">
                  <span class="awr-glance-k">Triage</span>
                  <ion-chip :color="screening.triage_category === 'EMERGENCY' ? 'danger' : screening.triage_category === 'URGENT' ? 'warning' : 'medium'" outline>
                    {{ screening.triage_category || '—' }}
                  </ion-chip>
                </div>
                <div class="awr-glance">
                  <span class="awr-glance-k">Syndrome</span>
                  <ion-chip color="tertiary" outline>{{ screening.syndrome_classification || '—' }}</ion-chip>
                </div>
                <div class="awr-glance">
                  <span class="awr-glance-k">Disposition</span>
                  <ion-chip color="medium" outline>{{ screening.final_disposition || screening.case_status || '—' }}</ion-chip>
                </div>
              </div>
              <div v-if="suspected.length" class="awr-glance-sus">
                <span class="awr-glance-k">Top suspect</span>
                <span class="awr-glance-v">
                  <strong>{{ suspected[0].disease_name || suspected[0].disease_code }}</strong>
                  <small v-if="suspected[0].confidence != null"> · {{ confidencePct(suspected[0].confidence) }}%</small>
                  <small v-if="suspected.length > 1"> + {{ suspected.length - 1 }} other{{ suspected.length > 2 ? 's' : '' }}</small>
                </span>
              </div>
              <div class="awr-glance-counts">
                <span class="awr-count"><b>{{ symptoms.length }}</b> symptoms</span>
                <span class="awr-count" v-if="exposures.filter(e => e.response === 'YES').length">
                  <b>{{ exposures.filter(e => e.response === 'YES').length }}</b> exposures
                </span>
                <span class="awr-count" v-if="travel.length"><b>{{ travel.length }}</b> countries</span>
                <span class="awr-count" v-if="actions.length"><b>{{ actions.length }}</b> actions</span>
                <span class="awr-count" v-if="samples.length"><b>{{ samples.length }}</b> samples</span>
              </div>
              <div v-if="screening.officer_notes" class="awr-glance-notes">
                <span class="awr-glance-k">Officer notes</span>
                <p>{{ screening.officer_notes }}</p>
              </div>
            </ion-card-content>
          </ion-card>

          <ion-card>
            <ion-card-content>
              <h4>Geography & routing</h4>
              <ion-list lines="none" class="awr-kv">
                <ion-item><ion-label>Country</ion-label><ion-note slot="end">{{ alert.country_code }}</ion-note></ion-item>
                <ion-item><ion-label>PHEOC</ion-label><ion-note slot="end">{{ alert.pheoc_code || '—' }}</ion-note></ion-item>
                <ion-item><ion-label>District</ion-label><ion-note slot="end">{{ alert.district_code || '—' }}</ion-note></ion-item>
                <ion-item><ion-label>POE</ion-label><ion-note slot="end">{{ alert.poe_code || '—' }}</ion-note></ion-item>
                <ion-item><ion-label>Routed to</ion-label><ion-note slot="end">{{ alert.routed_to_level }}</ion-note></ion-item>
                <ion-item><ion-label>Generated from</ion-label><ion-note slot="end">{{ alert.generated_from }}</ion-note></ion-item>
                <ion-item v-if="alert.ihr_tier"><ion-label>IHR tier</ion-label><ion-note slot="end">{{ alert.ihr_tier }}</ion-note></ion-item>
                <ion-item v-if="alert.acknowledged_at"><ion-label>Acknowledged</ion-label><ion-note slot="end">{{ fmtDate(alert.acknowledged_at) }}</ion-note></ion-item>
                <ion-item v-if="alert.closed_at"><ion-label>Closed</ion-label><ion-note slot="end">{{ fmtDate(alert.closed_at) }}</ion-note></ion-item>
              </ion-list>
            </ion-card-content>
          </ion-card>

          <ion-card v-if="lc.outcome.value">
            <ion-card-content>
              <h4>Recorded outcome</h4>
              <p>
                <ion-chip color="tertiary" outline>{{ lc.outcome.value.case_classification }}</ion-chip>
                <ion-chip v-if="lc.outcome.value.lab_status" color="medium" outline>Lab: {{ lc.outcome.value.lab_status }}</ion-chip>
                <ion-chip v-if="lc.outcome.value.clinical_outcome" color="medium" outline>{{ lc.outcome.value.clinical_outcome }}</ion-chip>
                <ion-chip v-if="lc.outcome.value.ihr_notified" color="warning" outline>IHR notified</ion-chip>
              </p>
              <small v-if="lc.outcome.value.notes">{{ lc.outcome.value.notes }}</small>
            </ion-card-content>
          </ion-card>

          <ion-card v-if="lc.followups.value.length">
            <ion-card-content>
              <h4>Open follow-ups · {{ lc.followups.value.filter(f => !['COMPLETED','NOT_APPLICABLE'].includes(f.status)).length }}/{{ lc.followups.value.length }}</h4>
              <p class="awr-glance-fu">
                <ion-chip v-for="f in lc.followups.value.slice(0, 5)" :key="f.id"
                          :color="f.status === 'COMPLETED' ? 'success' : Number(f.blocks_closure) ? 'warning' : 'medium'"
                          outline>
                  {{ f.action_label || f.action_code }}
                </ion-chip>
                <small v-if="lc.followups.value.length > 5">+{{ lc.followups.value.length - 5 }} more — see Tasks tab</small>
              </p>
            </ion-card-content>
          </ion-card>
        </section>

        <!-- Case File — Sentinel detail-modal pattern (mirrors POEs.vue
             pd-* class system). The traveller hero + vital cluster already
             render at the top of the war-room, so this pane focuses on the
             rich screening data: clinical, exposures, journey, identity,
             samples, assessment, metadata. -->
        <section v-if="tab === 'case'" class="cf-pane">
          <div v-if="!screening" class="cf-empty">
            <ion-icon :icon="documentTextOutline"/>
            <p>No secondary screening attached to this alert.</p>
          </div>

          <template v-else>
            <!-- ── OPEN FULL CASE FILE — primary CTA at top of the case tab ── -->
            <ion-card class="cf-open-cta">
              <ion-card-content>
                <div class="cf-open-flex">
                  <div>
                    <strong>Open Case File</strong>
                    <p>Opens the secondary screening records and expands this exact case — all clinical data, symptoms, exposures, disposition. Tap Back to return here.</p>
                  </div>
                  <ion-button color="primary" @click="openCaseFile">
                    <ion-icon :icon="arrowForwardOutline" slot="end"/>
                    Open
                  </ion-button>
                </div>
              </ion-card-content>
            </ion-card>

            <!-- ── SUSPECTED DISEASES — premium ranked tree ─────────────── -->
            <div v-if="suspected.length" class="pdr-section">
              <div class="pdr-label">
                <span class="pdr-glyph pdr-glyph--violet">⊕</span>
                <span>Suspected Diseases · {{ suspected.length }}</span>
              </div>
              <div class="pdr-rank-list">
                <article v-for="(d, i) in suspected" :key="d.id || d.disease_code || i"
                         class="pdr-rank-card" :class="i === 0 && 'pdr-rank-card--top'">
                  <div class="pdr-rank-rail"/>
                  <div class="pdr-rank-rank">
                    <span class="pdr-rank-num">{{ i + 1 }}</span>
                    <span class="pdr-rank-of">/{{ suspected.length }}</span>
                  </div>
                  <div class="pdr-rank-body">
                    <div class="pdr-rank-name">{{ diseaseMeta(d.disease_code)?.name || d.disease_name || d.disease_code }}</div>
                    <div v-if="d.case_definition" class="pdr-rank-def">{{ d.case_definition }}</div>
                    <div class="pdr-rank-meta">
                      <span v-if="d.confidence != null" class="pdr-conf">
                        <span class="pdr-conf-bar"><span class="pdr-conf-fill" :style="{ width: confidencePct(d.confidence) + '%' }"/></span>
                        <span class="pdr-conf-pct">{{ confidencePct(d.confidence) }}%</span>
                      </span>
                      <span v-if="d.incubation_days_min != null" class="pdr-tag">
                        Incub. {{ d.incubation_days_min }}–{{ d.incubation_days_max ?? '?' }}d
                      </span>
                      <span v-if="d.notifiable_who" class="pdr-tag pdr-tag--ihr">IHR-notifiable</span>
                    </div>
                    <div v-if="diseaseMeta(d.disease_code)?.alert_threshold || diseaseMeta(d.disease_code)?.epidemic_threshold"
                         class="pdr-thresh">
                      <div v-if="diseaseMeta(d.disease_code)?.alert_threshold" class="pdr-thresh-row">
                        <span class="pdr-thresh-tag pdr-thresh-tag--alert">ALERT</span>
                        <span class="pdr-thresh-text">{{ diseaseMeta(d.disease_code).alert_threshold }}</span>
                      </div>
                      <div v-if="diseaseMeta(d.disease_code)?.epidemic_threshold" class="pdr-thresh-row">
                        <span class="pdr-thresh-tag pdr-thresh-tag--epi">EPIDEMIC</span>
                        <span class="pdr-thresh-text">{{ diseaseMeta(d.disease_code).epidemic_threshold }}</span>
                      </div>
                    </div>
                  </div>
                </article>
              </div>
            </div>

            <!-- ── SYMPTOMS — flag-card grid coloured by severity ───────── -->
            <div v-if="symptoms.length" class="pdr-section">
              <div class="pdr-label">
                <span class="pdr-glyph pdr-glyph--amber">🌡</span>
                <span>Symptoms · {{ symptoms.length }}</span>
              </div>
              <div class="pdr-flag-grid">
                <article v-for="s in symptoms" :key="s.id || s.symptom_code"
                         class="pdr-flag" :class="symptomFlagClass(s)">
                  <div class="pdr-flag-shine"/>
                  <div class="pdr-flag-icon">{{ symptomGlyph(s) }}</div>
                  <div class="pdr-flag-name">{{ s.symptom_name || String(s.symptom_code || '').replace(/_/g,' ') }}</div>
                  <div class="pdr-flag-meta">
                    <span v-if="s.severity" class="pdr-flag-sev">{{ s.severity }}</span>
                    <span v-if="s.duration_hours">{{ s.duration_hours }}h</span>
                  </div>
                </article>
              </div>
            </div>

            <!-- ── EXPOSURES — tree-node list ───────────────────────────── -->
            <div v-if="exposures.length" class="pdr-section">
              <div class="pdr-label">
                <span class="pdr-glyph pdr-glyph--rose">⚠</span>
                <span>Exposures · {{ exposures.length }}</span>
              </div>
              <div class="pdr-nodes">
                <article v-for="e in exposures" :key="e.id || e.exposure_code" class="pdr-node pdr-node--rose">
                  <div class="pdr-node-shine"/>
                  <div class="pdr-node-icon pdr-node-icon--rose">⚠</div>
                  <div class="pdr-node-body">
                    <div class="pdr-node-key">
                      {{ e.exposure_label || String(e.exposure_code || '').replace(/_/g,' ') }}
                    </div>
                    <div class="pdr-node-val" :style="e.response === 'YES' ? 'color: var(--ion-color-danger)' : e.response === 'NO' ? 'color: var(--ion-color-success)' : ''">
                      {{ e.response || 'UNKNOWN' }}
                    </div>
                    <div v-if="e.details || e.notes" class="pdr-node-sub">{{ e.details || e.notes }}</div>
                  </div>
                  <div v-if="e.exposure_date" class="pdr-node-tail">{{ fmtDateShort(e.exposure_date) }}</div>
                </article>
              </div>
            </div>

            <!-- ── TRAVEL HISTORY ───────────────────────────────────────── -->
            <div v-if="travel.length" class="pdr-section">
              <div class="pdr-label">
                <span class="pdr-glyph pdr-glyph--blue">✈</span>
                <span>Travel · last 14 days · {{ travel.length }}</span>
              </div>
              <div class="pdr-country-strip">
                <div v-for="t in travel" :key="t.id || t.country_code" class="pdr-country">
                  <span class="pdr-country-code">{{ t.country_code }}</span>
                  <span v-if="t.travel_role === 'TRANSIT'" class="pdr-country-role">TRANSIT</span>
                  <span v-if="t.days_in_country" class="pdr-country-days">{{ t.days_in_country }}<small>d</small></span>
                  <span v-else-if="t.arrival_date || t.departure_date" class="pdr-country-days" style="font-size:9px;opacity:.7">
                    {{ t.arrival_date ? fmtDateShort(t.arrival_date) : '?' }} – {{ t.departure_date ? fmtDateShort(t.departure_date) : '?' }}
                  </span>
                </div>
              </div>
            </div>

            <!-- ── JOURNEY / ITINERARY — ops grid ───────────────────────── -->
            <div v-if="screening.embarkation_port_city || screening.conveyance_type || screening.arrival_datetime || screening.purpose_of_travel" class="pdr-section">
              <div class="pdr-label">
                <span class="pdr-glyph pdr-glyph--blue">⌖</span>
                <span>Journey & Itinerary</span>
              </div>
              <div class="pdr-ops-grid">
                <div v-if="screening.journey_start_country_code" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Origin</div>
                  <div class="pdr-ops-val">{{ screening.journey_start_country_code }}</div>
                </div>
                <div v-if="screening.embarkation_port_city" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Embarkation</div>
                  <div class="pdr-ops-val">{{ screening.embarkation_port_city }}</div>
                </div>
                <div v-if="screening.conveyance_type" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Conveyance</div>
                  <div class="pdr-ops-val">{{ screening.conveyance_type }}</div>
                  <div v-if="screening.conveyance_identifier" class="pdr-ops-sub">{{ screening.conveyance_identifier }}</div>
                </div>
                <div v-if="screening.seat_number" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Seat</div>
                  <div class="pdr-ops-val pdr-mono">{{ screening.seat_number }}</div>
                </div>
                <div v-if="screening.arrival_datetime" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Arrival</div>
                  <div class="pdr-ops-val">{{ fmtDate(screening.arrival_datetime) }}</div>
                </div>
                <div v-if="screening.departure_datetime" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Departure</div>
                  <div class="pdr-ops-val">{{ fmtDate(screening.departure_datetime) }}</div>
                </div>
                <div v-if="screening.purpose_of_travel" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Purpose</div>
                  <div class="pdr-ops-val">{{ String(screening.purpose_of_travel).replace(/_/g,' ') }}</div>
                </div>
                <div v-if="screening.planned_length_of_stay_days" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Stay</div>
                  <div class="pdr-ops-val">{{ screening.planned_length_of_stay_days }} days</div>
                </div>
              </div>
            </div>

            <!-- ── TRAVELER DEMOGRAPHICS — full identity from secondary screening ── -->
            <div v-if="screening.traveler_full_name || screening.traveler_dob || screening.traveler_occupation || screening.traveler_nationality_country_code || screening.traveler_gender" class="pdr-section">
              <div class="pdr-label">
                <span class="pdr-glyph pdr-glyph--slate">👤</span>
                <span>Traveler Demographics</span>
              </div>
              <div class="pdr-ref">
                <div v-if="screening.traveler_full_name" class="pdr-ref-row">
                  <span class="pdr-ref-key">Full name</span>
                  <span class="pdr-ref-val">{{ screening.traveler_full_name }}</span>
                </div>
                <div v-if="screening.traveler_initials" class="pdr-ref-row">
                  <span class="pdr-ref-key">Initials</span>
                  <span class="pdr-ref-val pdr-ref-val--code">{{ screening.traveler_initials }}</span>
                </div>
                <div v-if="screening.traveler_dob" class="pdr-ref-row">
                  <span class="pdr-ref-key">Date of birth</span>
                  <span class="pdr-ref-val">{{ fmtDateShort(screening.traveler_dob) }}</span>
                </div>
                <div v-if="screening.traveler_age_years != null" class="pdr-ref-row">
                  <span class="pdr-ref-key">Age</span>
                  <span class="pdr-ref-val">{{ screening.traveler_age_years }} years</span>
                </div>
                <div v-if="screening.traveler_gender" class="pdr-ref-row">
                  <span class="pdr-ref-key">Gender</span>
                  <span class="pdr-ref-val">{{ screening.traveler_gender }}</span>
                </div>
                <div v-if="screening.traveler_nationality_country_code || screening.traveler_nationality" class="pdr-ref-row">
                  <span class="pdr-ref-key">Nationality</span>
                  <span class="pdr-ref-val">{{ screening.traveler_nationality_country_code || screening.traveler_nationality }}</span>
                </div>
                <div v-if="screening.traveler_occupation" class="pdr-ref-row">
                  <span class="pdr-ref-key">Occupation</span>
                  <span class="pdr-ref-val">{{ screening.traveler_occupation }}</span>
                </div>
              </div>
            </div>

            <!-- ── IDENTITY & CONTACT — tree-node list ──────────────────── -->
            <div v-if="screening.travel_document_number || screening.phone_number || screening.email || screening.residence_address_text" class="pdr-section">
              <div class="pdr-label">
                <span class="pdr-glyph pdr-glyph--blue">⎔</span>
                <span>Identity & Contact</span>
              </div>
              <div class="pdr-nodes">
                <article v-if="screening.travel_document_number" class="pdr-node pdr-node--blue">
                  <div class="pdr-node-shine"/>
                  <div class="pdr-node-icon pdr-node-icon--blue">▤</div>
                  <div class="pdr-node-body">
                    <div class="pdr-node-key">Travel Document</div>
                    <div class="pdr-node-val pdr-mono">{{ screening.travel_document_number }}</div>
                    <div v-if="screening.travel_document_type" class="pdr-node-sub">{{ screening.travel_document_type }}</div>
                  </div>
                </article>
                <article v-if="screening.phone_number" class="pdr-node pdr-node--green">
                  <div class="pdr-node-shine"/>
                  <div class="pdr-node-icon pdr-node-icon--green">☎</div>
                  <div class="pdr-node-body">
                    <div class="pdr-node-key">Phone</div>
                    <div class="pdr-node-val pdr-mono">{{ screening.phone_number }}</div>
                    <div v-if="screening.alternative_phone" class="pdr-node-sub">Alt · {{ screening.alternative_phone }}</div>
                  </div>
                </article>
                <article v-if="screening.email" class="pdr-node pdr-node--violet">
                  <div class="pdr-node-shine"/>
                  <div class="pdr-node-icon pdr-node-icon--violet">✉</div>
                  <div class="pdr-node-body">
                    <div class="pdr-node-key">Email</div>
                    <div class="pdr-node-val pdr-mono">{{ screening.email }}</div>
                  </div>
                </article>
                <article v-if="screening.residence_address_text || screening.residence_country_code" class="pdr-node pdr-node--blue">
                  <div class="pdr-node-shine"/>
                  <div class="pdr-node-icon pdr-node-icon--blue">⌂</div>
                  <div class="pdr-node-body">
                    <div class="pdr-node-key">Residence</div>
                    <div class="pdr-node-val">{{ screening.residence_address_text || screening.residence_country_code }}</div>
                    <div v-if="screening.residence_country_code && screening.residence_address_text" class="pdr-node-sub">{{ screening.residence_country_code }}</div>
                  </div>
                </article>
                <article v-if="screening.destination_address_text" class="pdr-node pdr-node--amber">
                  <div class="pdr-node-shine"/>
                  <div class="pdr-node-icon pdr-node-icon--amber">⤳</div>
                  <div class="pdr-node-body">
                    <div class="pdr-node-key">Destination</div>
                    <div class="pdr-node-val">{{ screening.destination_address_text }}</div>
                    <div v-if="screening.destination_district_code" class="pdr-node-sub">{{ screening.destination_district_code }}</div>
                  </div>
                </article>
                <article v-if="screening.emergency_contact_name" class="pdr-node pdr-node--rose">
                  <div class="pdr-node-shine"/>
                  <div class="pdr-node-icon pdr-node-icon--rose">♥</div>
                  <div class="pdr-node-body">
                    <div class="pdr-node-key">Emergency Contact</div>
                    <div class="pdr-node-val">{{ screening.emergency_contact_name }}</div>
                    <div v-if="screening.emergency_contact_phone" class="pdr-node-sub pdr-mono">{{ screening.emergency_contact_phone }}</div>
                  </div>
                </article>
              </div>
            </div>

            <!-- ── LAB SAMPLES — tree-node list ─────────────────────────── -->
            <div v-if="samples.length" class="pdr-section">
              <div class="pdr-label">
                <span class="pdr-glyph pdr-glyph--violet">⚗</span>
                <span>Lab Samples · {{ samples.length }}</span>
              </div>
              <div class="pdr-nodes">
                <article v-for="s in samples" :key="s.id" class="pdr-node pdr-node--violet">
                  <div class="pdr-node-shine"/>
                  <div class="pdr-node-icon pdr-node-icon--violet">⚗</div>
                  <div class="pdr-node-body">
                    <div class="pdr-node-key">{{ s.sample_status || 'Pending' }}</div>
                    <div class="pdr-node-val">{{ s.sample_type }}</div>
                    <div v-if="s.lab_destination" class="pdr-node-sub">→ {{ s.lab_destination }}</div>
                    <div v-if="s.notes" class="pdr-node-sub">{{ s.notes }}</div>
                  </div>
                  <div v-if="s.collected_at" class="pdr-node-tail">{{ fmtDateShort(s.collected_at) }}</div>
                </article>
              </div>
            </div>

            <!-- ── CLINICAL ACTIONS TAKEN — secondary_actions table ───────── -->
            <div v-if="actions.length" class="pdr-section">
              <div class="pdr-label">
                <span class="pdr-glyph pdr-glyph--green">✓</span>
                <span>Clinical Actions · {{ actions.length }}</span>
              </div>
              <div class="pdr-nodes">
                <article v-for="ac in actions" :key="ac.id || ac.action_code"
                         class="pdr-node" :class="ac.is_done ? 'pdr-node--green' : 'pdr-node--amber'">
                  <div class="pdr-node-shine"/>
                  <div class="pdr-node-icon" :class="ac.is_done ? 'pdr-node-icon--green' : 'pdr-node-icon--amber'">
                    {{ ac.is_done ? '✓' : '○' }}
                  </div>
                  <div class="pdr-node-body">
                    <div class="pdr-node-key">{{ ac.is_done ? 'Done' : 'Pending' }}</div>
                    <div class="pdr-node-val">{{ ac.action_label || String(ac.action_code || '').replace(/_/g,' ') }}</div>
                    <div v-if="ac.details" class="pdr-node-sub">{{ ac.details }}</div>
                  </div>
                </article>
              </div>
            </div>

            <!-- ── OFFICER ASSESSMENT — gradient cards ──────────────────── -->
            <div v-if="screening.triage_category || screening.officer_notes || screening.final_disposition || screening.case_status" class="pdr-section">
              <div class="pdr-label">
                <span class="pdr-glyph pdr-glyph--blue">⊟</span>
                <span>Officer Assessment</span>
              </div>

              <div class="pdr-ops-grid">
                <div v-if="screening.case_status" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Case status</div>
                  <div class="pdr-ops-val">{{ screening.case_status }}</div>
                </div>
                <div v-if="screening.triage_category" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Triage</div>
                  <div class="pdr-ops-val">{{ screening.triage_category }}</div>
                </div>
                <div v-if="screening.emergency_signs_present !== null && screening.emergency_signs_present !== undefined"
                     class="pdr-ops" :class="screening.emergency_signs_present ? 'pdr-ops--alert' : ''">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Emergency signs</div>
                  <div class="pdr-ops-val">{{ screening.emergency_signs_present ? 'PRESENT' : 'None' }}</div>
                </div>
                <div v-if="screening.final_disposition" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Disposition</div>
                  <div class="pdr-ops-val">{{ screening.final_disposition }}</div>
                </div>
                <div v-if="screening.screening_outcome" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Outcome</div>
                  <div class="pdr-ops-val">{{ screening.screening_outcome }}</div>
                </div>
                <div v-if="screening.followup_required" class="pdr-ops pdr-ops--info">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Follow-up</div>
                  <div class="pdr-ops-val">Required</div>
                  <div v-if="screening.followup_assigned_level" class="pdr-ops-sub">{{ screening.followup_assigned_level }}</div>
                </div>
                <div v-if="screening.disposition_details" class="pdr-ops pdr-ops--wide">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Details</div>
                  <div class="pdr-ops-val pdr-ops-val--small">{{ screening.disposition_details }}</div>
                </div>
              </div>

              <article v-if="screening.officer_notes" class="pdr-tip pdr-tip--green">
                <div class="pdr-tip-shine"/>
                <div class="pdr-tip-hdr">
                  <span class="pdr-tip-icon">✎</span>
                  <span class="pdr-tip-title">Officer Notes</span>
                </div>
                <div class="pdr-tip-text">{{ screening.officer_notes }}</div>
              </article>
            </div>

            <!-- ── RISK SCORING (engine override / concordance) ─────────── -->
            <div v-if="screening.concordance_score != null || screening.scoring_engine_version || screening.override_applied" class="pdr-section">
              <div class="pdr-label">
                <span class="pdr-glyph pdr-glyph--amber">⊜</span>
                <span>Risk Scoring</span>
              </div>
              <div class="pdr-ops-grid">
                <div v-if="screening.concordance_score != null" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Concordance</div>
                  <div class="pdr-ops-val pdr-mono">{{ Math.round(Number(screening.concordance_score) * 100) }}%</div>
                </div>
                <div v-if="screening.scoring_engine_version" class="pdr-ops">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Engine</div>
                  <div class="pdr-ops-val pdr-mono">{{ screening.scoring_engine_version }}</div>
                </div>
                <div v-if="screening.override_applied" class="pdr-ops pdr-ops--alert pdr-ops--wide">
                  <div class="pdr-ops-shine"/>
                  <div class="pdr-ops-key">Override Applied</div>
                  <div class="pdr-ops-val">YES</div>
                  <div v-if="screening.override_reason" class="pdr-ops-sub">{{ screening.override_reason }}</div>
                </div>
              </div>
            </div>

            <!-- ── ENCOUNTER METADATA — compact ref-table ───────────────── -->
            <div v-if="screening.opened_at || screening.dispositioned_at || screening.closed_at || screening.device_id" class="pdr-section">
              <div class="pdr-label">
                <span class="pdr-glyph pdr-glyph--slate">⏱</span>
                <span>Encounter Metadata</span>
              </div>
              <div class="pdr-ref">
                <div v-if="screening.opened_at" class="pdr-ref-row">
                  <span class="pdr-ref-key">Opened</span>
                  <span class="pdr-ref-val">{{ fmtDate(screening.opened_at) }}</span>
                </div>
                <div v-if="screening.dispositioned_at" class="pdr-ref-row">
                  <span class="pdr-ref-key">Dispositioned</span>
                  <span class="pdr-ref-val">{{ fmtDate(screening.dispositioned_at) }}</span>
                </div>
                <div v-if="screening.closed_at" class="pdr-ref-row">
                  <span class="pdr-ref-key">Closed</span>
                  <span class="pdr-ref-val">{{ fmtDate(screening.closed_at) }}</span>
                </div>
                <div v-if="screening.opened_timezone" class="pdr-ref-row">
                  <span class="pdr-ref-key">Timezone</span>
                  <span class="pdr-ref-val pdr-ref-val--code">{{ screening.opened_timezone }}</span>
                </div>
                <div v-if="screening.device_id" class="pdr-ref-row">
                  <span class="pdr-ref-key">Device</span>
                  <span class="pdr-ref-val pdr-ref-val--id">{{ screening.device_id }}</span>
                </div>
                <div v-if="screening.app_version" class="pdr-ref-row">
                  <span class="pdr-ref-key">App</span>
                  <span class="pdr-ref-val pdr-ref-val--code">{{ screening.app_version }}<span v-if="screening.platform"> · {{ screening.platform }}</span></span>
                </div>
              </div>
            </div>
          </template>
        </section>

        <!-- Advisor -->
        <section v-if="tab === 'advisor'" class="awr-section">
          <ion-card v-if="!lc.advisor.value">
            <ion-card-content><em>No advisor recommendation available.</em></ion-card-content>
          </ion-card>
          <ion-card v-else>
            <ion-card-content>
              <h4><ion-icon :icon="shieldCheckmarkOutline"/> Recommendation</h4>
              <div v-if="lc.advisor.value.insufficient" class="awr-warn">
                <strong>Insufficient input:</strong>
                <ul><li v-for="m in (lc.advisor.value.missing_inputs || [])" :key="m.field">{{ m.why }}</li></ul>
              </div>
              <ion-list v-else lines="full" class="awr-kv">
                <ion-item><ion-label>PPE</ion-label><ion-note slot="end">{{ lc.advisor.value.recommendation?.ppe_level || lc.advisor.value.ppe_level || '—' }}</ion-note></ion-item>
                <ion-item><ion-label>IHR status</ion-label><ion-note slot="end">{{ lc.advisor.value.recommendation?.ihr_status || lc.advisor.value.ihr_status || '—' }}</ion-note></ion-item>
                <ion-item><ion-label>Referral</ion-label><ion-note slot="end">{{ lc.advisor.value.recommendation?.referral_level || lc.advisor.value.referral_level || '—' }}</ion-note></ion-item>
                <ion-item><ion-label>Next action</ion-label><ion-note slot="end">{{ lc.advisor.value.recommendation?.next_action || lc.advisor.value.next_action || '—' }}</ion-note></ion-item>
              </ion-list>
              <div v-if="lc.advisor.value.recommendation?.samples?.length" class="awr-block">
                <strong>Samples:</strong>
                <ion-chip v-for="(s, i) in lc.advisor.value.recommendation.samples" :key="i" outline>
                  {{ s.sample_type || s }}
                </ion-chip>
              </div>
              <div v-if="lc.advisor.value.recommendation?.traveler_script || lc.advisor.value.traveler_script" class="awr-script">
                <strong>Traveler script</strong>
                <p>{{ lc.advisor.value.recommendation?.traveler_script || lc.advisor.value.traveler_script }}</p>
              </div>
              <details v-if="(lc.advisor.value.rules_fired || []).length">
                <summary>Rules fired ({{ lc.advisor.value.rules_fired.length }})</summary>
                <ul>
                  <li v-for="(r, i) in lc.advisor.value.rules_fired" :key="i">
                    <code>{{ r.rule }}</code> — {{ r.because }}
                  </li>
                </ul>
              </details>
            </ion-card-content>
          </ion-card>
        </section>

        <!-- Follow-ups -->
        <section v-if="tab === 'followups'" class="awr-section">
          <div class="awr-section-head">
            <strong>Follow-ups · {{ lc.followups.value.length }}</strong>
            <ion-button size="small" fill="outline" @click="followupOpen = true">
              <ion-icon :icon="personAddOutline" slot="start"/> Add
            </ion-button>
          </div>

          <ion-card v-if="lc.blockers.value.length" color="warning">
            <ion-card-content>
              <strong>{{ lc.blockers.value.length }} blocking follow-up(s) — closure is locked.</strong>
              <p>Resolve each (or NATIONAL_ADMIN may override during close).</p>
            </ion-card-content>
          </ion-card>

          <ion-card v-for="f in lc.followups.value" :key="f.id">
            <ion-card-content>
              <div class="awr-fu-row">
                <div>
                  <strong>{{ f.action_label || f.action_code }}</strong>
                  <ion-chip :color="f.status === 'COMPLETED' ? 'success' : f.status === 'BLOCKED' ? 'danger' : 'medium'" outline>{{ f.status }}</ion-chip>
                  <ion-chip v-if="Number(f.blocks_closure)" color="warning" outline>Blocks closure</ion-chip>
                </div>
                <small v-if="f.due_at">Due {{ fmtDate(f.due_at) }}</small>
              </div>
              <p v-if="f.notes" class="awr-fu-notes">{{ f.notes }}</p>
              <div class="awr-fu-actions">
                <ion-button v-if="f.status === 'PENDING'" size="small" fill="outline" @click="updateFollowupStatus(f, 'IN_PROGRESS')">Start</ion-button>
                <ion-button v-if="f.status !== 'COMPLETED'" size="small" fill="outline" color="success" @click="updateFollowupStatus(f, 'COMPLETED')">Complete</ion-button>
                <ion-button v-if="Number(f.blocks_closure) && !['COMPLETED','NOT_APPLICABLE'].includes(f.status)" size="small" fill="solid" color="warning" @click="openBlocker(f)">
                  Resolve blocker
                </ion-button>
              </div>
            </ion-card-content>
          </ion-card>
        </section>

        <!-- Timeline -->
        <section v-if="tab === 'timeline'" class="awr-section">
          <ion-card v-if="!lc.timeline.value.length"><ion-card-content><em>No events yet.</em></ion-card-content></ion-card>
          <ion-card v-for="e in lc.timeline.value" :key="e.id" class="awr-tl">
            <ion-card-content>
              <div class="awr-tl-row">
                <ion-chip :color="severityTone(e.severity)" outline>{{ e.event_category }}</ion-chip>
                <ion-chip outline>{{ e.event_code }}</ion-chip>
                <small>{{ fmtDate(e.created_at) }}</small>
              </div>
              <p>{{ e.summary }}</p>
              <small v-if="e.actor_name">— {{ e.actor_name }} ({{ e.actor_role }})</small>
            </ion-card-content>
          </ion-card>
        </section>

        <!-- Evidence -->
        <section v-if="tab === 'evidence'" class="awr-section">
          <div class="awr-section-head">
            <strong>Evidence · {{ lc.evidence.value.length }}</strong>
            <ion-button size="small" fill="outline" @click="evidenceOpen = true">
              <ion-icon :icon="attachOutline" slot="start"/> Attach
            </ion-button>
          </div>
          <ion-card v-for="ev in lc.evidence.value" :key="ev.id">
            <ion-card-content>
              <strong>{{ ev.title || ev.category }}</strong>
              <p>{{ ev.description || ev.file_mime || '—' }}</p>
              <small>By {{ ev.uploader_name || '—' }} · {{ fmtDate(ev.created_at) }}</small>
              <p v-if="ev.external_url"><a :href="ev.external_url" target="_blank">Open</a></p>
            </ion-card-content>
          </ion-card>
        </section>

        <!-- Comments -->
        <section v-if="tab === 'comments'" class="awr-section">
          <ion-card v-for="c in lc.comments.value" :key="c.id">
            <ion-card-content>
              <strong>{{ c.author_name || ('User #' + c.author_user_id) }}</strong>
              <small> · {{ fmtDate(c.created_at) }}</small>
              <p>{{ c.body_text }}</p>
            </ion-card-content>
          </ion-card>
          <ion-card v-if="!lc.comments.value.length"><ion-card-content><em>No comments yet.</em></ion-card-content></ion-card>

          <ion-item v-if="can('alerts.comment')">
            <ion-textarea v-model="newComment" :auto-grow="true" placeholder="Add a comment…" rows="2"/>
          </ion-item>
          <ion-button v-if="can('alerts.comment')" expand="block" :disabled="!newComment.trim() || actionBusy" @click="submitComment">
            <ion-icon :icon="sendOutline" slot="start"/> Post
          </ion-button>
        </section>

        <!-- Comms / notifications -->
        <section v-if="tab === 'comms'" class="awr-section">
          <ion-button size="small" fill="outline" @click="lc.loadCommsInbox()">
            <ion-icon :icon="refreshOutline" slot="start"/> Refresh
          </ion-button>
          <ion-card v-for="n in lc.commsInbox.value" :key="n.id">
            <ion-card-content>
              <strong>{{ n.template_code }}</strong> →
              <em>{{ n.recipient_email }}</em>
              <p>Status: {{ n.status }}<span v-if="n.sent_at"> · sent {{ fmtDate(n.sent_at) }}</span></p>
              <small v-if="n.error_detail">{{ n.error_detail }}</small>
            </ion-card-content>
          </ion-card>
          <ion-card v-if="!lc.commsInbox.value.length"><ion-card-content><em>Tap Refresh to load notifications.</em></ion-card-content></ion-card>
        </section>

        <!-- Breaches -->
        <section v-if="tab === 'breach'" class="awr-section">
          <ion-card v-for="b in lc.breachReports.value" :key="b.id">
            <ion-card-content>
              <strong>{{ b.phase }} · {{ b.root_cause_category }}</strong>
              <ion-chip :color="b.status === 'RESOLVED' ? 'success' : 'warning'" outline>{{ b.status }}</ion-chip>
              <p>{{ b.root_cause_text }}</p>
              <small>Mitigation: {{ b.mitigation_plan }}</small>
              <small><br/>Logged {{ fmtDate(b.created_at) }}</small>
            </ion-card-content>
          </ion-card>
          <ion-card v-if="!lc.breachReports.value.length"><ion-card-content><em>No breach reports.</em></ion-card-content></ion-card>
        </section>
      </template>
    </ion-content>

    <!-- ───────────────── Modals ───────────────── -->

    <!-- SmartCloseWizard: pre-filled, intent-led, blocker-aware close flow.
         Replaces the older multi-modal close + outcome dance with a single
         3-step path that finishes in 2-3 taps for the common cases. -->
    <!-- Overflow action sheet — secondary actions hidden until requested -->
    <ion-modal v-if="moreOpen" :is-open="moreOpen" @did-dismiss="moreOpen = false" :breakpoints="[0, 0.7, 1]" :initial-breakpoint="0.7">
      <ion-header><ion-toolbar><ion-title>More actions</ion-title>
        <ion-buttons slot="end"><ion-button @click="moreOpen = false">Done</ion-button></ion-buttons>
      </ion-toolbar></ion-header>
      <ion-content class="war-actions-sheet">
        <button v-for="a in overflowActions" :key="a.id" class="war-action-row"
                :class="a.id === 'breach' ? 'is-warn' : a.id === 'pheic' ? 'is-danger' : ''"
                @click="moreOpen = false; a.fn()">
          <ion-icon :icon="a.icon"/>
          <span class="war-action-text">
            <strong>{{ a.label }}</strong>
            <small>{{ a.sub }}</small>
          </span>
          <ion-icon :icon="arrowForwardOutline"/>
        </button>
        <p v-if="!overflowActions.length" style="text-align:center; padding: 24px; color: var(--war-text-3);">
          No additional actions available for your role.
        </p>
      </ion-content>
    </ion-modal>

    <ion-modal v-if="closeOpen" :is-open="closeOpen" @did-dismiss="closeOpen = false" :breakpoints="[0, 1]" :initial-breakpoint="1">
      <SmartCloseWizard
        :model-value="closeOpen"
        :case-file="lc.caseFile.value"
        :lifecycle="lc"
        @update:model-value="closeOpen = $event"
        @closed="closeOpen = false"/>
    </ion-modal>

    <!-- Blocker resolve -->
    <ion-modal v-if="blockerOpen" :is-open="blockerOpen" @did-dismiss="blockerOpen = false; blockerCtx = null" :breakpoints="[0, 1]" :initial-breakpoint="1">
      <ion-header><ion-toolbar><ion-title>Resolve blocker</ion-title>
        <ion-buttons slot="end"><ion-button @click="blockerOpen = false">Cancel</ion-button></ion-buttons>
      </ion-toolbar></ion-header>
      <ion-content class="ion-padding" v-if="blockerCtx">
        <h4>{{ blockerCtx.action_label || blockerCtx.action_code }}</h4>
        <p v-if="blockerCtx.due_at"><small>Due {{ fmtDate(blockerCtx.due_at) }}</small></p>
        <ion-list lines="full">
          <ion-item>
            <ion-label position="stacked">Resolution *</ion-label>
            <ion-select v-model="blockerForm.resolution" interface="popover">
              <ion-select-option value="COMPLETED">COMPLETED — work was done</ion-select-option>
              <ion-select-option value="NOT_APPLICABLE">NOT_APPLICABLE — not relevant</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item>
            <ion-label position="stacked">Reason (≥10 chars) *</ion-label>
            <ion-textarea v-model="blockerForm.reason" :auto-grow="true" rows="3"/>
          </ion-item>
          <ion-item>
            <ion-label position="stacked">Evidence ref (optional)</ion-label>
            <ion-input v-model="blockerForm.evidence_ref" placeholder="lab report id, file uri, …"/>
          </ion-item>
        </ion-list>
        <ion-button expand="block" color="warning" :disabled="actionBusy" @click="resolveBlockerSubmit">Resolve blocker</ion-button>
      </ion-content>
    </ion-modal>

    <!-- Reassign -->
    <ion-modal v-if="reassignOpen" :is-open="reassignOpen" @did-dismiss="reassignOpen = false" :breakpoints="[0, 1]" :initial-breakpoint="1">
      <ion-header><ion-toolbar><ion-title>Reassign owner</ion-title>
        <ion-buttons slot="end"><ion-button @click="reassignOpen = false">Cancel</ion-button></ion-buttons>
      </ion-toolbar></ion-header>
      <ion-content class="ion-padding">
        <ion-list lines="full">
          <ion-item><ion-label position="stacked">New owner user_id *</ion-label><ion-input type="number" v-model="reassignForm.owner_user_id"/></ion-item>
          <ion-item><ion-label position="stacked">Level</ion-label>
            <ion-select v-model="reassignForm.level" interface="popover">
              <ion-select-option value="DISTRICT">DISTRICT</ion-select-option>
              <ion-select-option value="PHEOC">PHEOC</ion-select-option>
              <ion-select-option value="NATIONAL">NATIONAL</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item><ion-label position="stacked">Reason</ion-label><ion-textarea v-model="reassignForm.reason" :auto-grow="true" rows="2"/></ion-item>
        </ion-list>
        <ion-button expand="block" :disabled="actionBusy" @click="submitReassign">Reassign</ion-button>
      </ion-content>
    </ion-modal>

    <!-- Escalate -->
    <ion-modal v-if="escalateOpen" :is-open="escalateOpen" @did-dismiss="escalateOpen = false" :breakpoints="[0, 1]" :initial-breakpoint="1">
      <ion-header><ion-toolbar><ion-title>Escalate</ion-title>
        <ion-buttons slot="end"><ion-button @click="escalateOpen = false">Cancel</ion-button></ion-buttons>
      </ion-toolbar></ion-header>
      <ion-content class="ion-padding">
        <ion-list lines="full">
          <ion-item><ion-label position="stacked">To level *</ion-label>
            <ion-select v-model="escalateForm.to_level" interface="popover">
              <ion-select-option value="DISTRICT">DISTRICT</ion-select-option>
              <ion-select-option value="PHEOC">PHEOC</ion-select-option>
              <ion-select-option value="NATIONAL">NATIONAL</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item><ion-label position="stacked">Reason (≥10 chars) *</ion-label><ion-textarea v-model="escalateForm.reason" :auto-grow="true" rows="3"/></ion-item>
          <ion-item><ion-label>Notify recipients</ion-label>
            <ion-select v-model="escalateForm.notify" slot="end" interface="popover">
              <ion-select-option :value="true">Yes</ion-select-option>
              <ion-select-option :value="false">No</ion-select-option>
            </ion-select>
          </ion-item>
        </ion-list>
        <ion-button expand="block" :disabled="actionBusy" @click="submitEscalate">Escalate</ion-button>
      </ion-content>
    </ion-modal>

    <!-- Reopen -->
    <ion-modal v-if="reopenOpen" :is-open="reopenOpen" @did-dismiss="reopenOpen = false; reopenReason = ''" :breakpoints="[0, 1]" :initial-breakpoint="1">
      <ion-header><ion-toolbar><ion-title>Reopen alert</ion-title>
        <ion-buttons slot="end"><ion-button @click="reopenOpen = false">Cancel</ion-button></ion-buttons>
      </ion-toolbar></ion-header>
      <ion-content class="ion-padding">
        <p>Reopening returns this alert to <strong>ACKNOWLEDGED</strong> and increments reopen_count.</p>
        <ion-item><ion-label position="stacked">Reason (≥10 chars) *</ion-label><ion-textarea v-model="reopenReason" :auto-grow="true" rows="3"/></ion-item>
        <ion-button expand="block" color="tertiary" :disabled="actionBusy" @click="submitReopen">Reopen</ion-button>
      </ion-content>
    </ion-modal>

    <!-- Outcome wizard -->
    <ion-modal v-if="outcomeOpen" :is-open="outcomeOpen" @did-dismiss="outcomeOpen = false" :breakpoints="[0, 1]" :initial-breakpoint="1">
      <ion-header><ion-toolbar><ion-title>Record outcome</ion-title>
        <ion-buttons slot="end"><ion-button @click="outcomeOpen = false">Cancel</ion-button></ion-buttons>
      </ion-toolbar></ion-header>
      <ion-content class="ion-padding">
        <ion-list lines="full">
          <ion-item><ion-label position="stacked">Case classification *</ion-label>
            <ion-select v-model="outcomeForm.case_classification" interface="action-sheet">
              <ion-select-option value="SUSPECTED">SUSPECTED</ion-select-option>
              <ion-select-option value="PROBABLE">PROBABLE</ion-select-option>
              <ion-select-option value="CONFIRMED">CONFIRMED</ion-select-option>
              <ion-select-option value="DISCARDED">DISCARDED</ion-select-option>
              <ion-select-option value="LOST_TO_FOLLOWUP">LOST_TO_FOLLOWUP</ion-select-option>
              <ion-select-option value="UNKNOWN">UNKNOWN</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item><ion-label position="stacked">Lab status</ion-label>
            <ion-select v-model="outcomeForm.lab_status" interface="popover">
              <ion-select-option value="">—</ion-select-option>
              <ion-select-option value="PENDING">PENDING</ion-select-option>
              <ion-select-option value="POSITIVE">POSITIVE</ion-select-option>
              <ion-select-option value="NEGATIVE">NEGATIVE</ion-select-option>
              <ion-select-option value="INCONCLUSIVE">INCONCLUSIVE</ion-select-option>
              <ion-select-option value="INSUFFICIENT_SAMPLE">INSUFFICIENT_SAMPLE</ion-select-option>
              <ion-select-option value="NOT_TESTED">NOT_TESTED</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item v-if="outcomeForm.lab_status && outcomeForm.lab_status !== 'NOT_TESTED'">
            <ion-label position="stacked">Confirmed disease code</ion-label>
            <ion-input v-model="outcomeForm.lab_disease_code" placeholder="e.g. EBV"/>
          </ion-item>
          <ion-item v-if="outcomeForm.lab_status && outcomeForm.lab_status !== 'NOT_TESTED'">
            <ion-label position="stacked">Lab method</ion-label>
            <ion-input v-model="outcomeForm.lab_test_method" placeholder="PCR / RDT / Serology / Culture"/>
          </ion-item>
          <ion-item><ion-label position="stacked">Clinical outcome</ion-label>
            <ion-select v-model="outcomeForm.clinical_outcome" interface="popover">
              <ion-select-option value="">—</ion-select-option>
              <ion-select-option value="RECOVERED">RECOVERED</ion-select-option>
              <ion-select-option value="CONVALESCING">CONVALESCING</ion-select-option>
              <ion-select-option value="DECEASED">DECEASED</ion-select-option>
              <ion-select-option value="LOST_TO_FOLLOWUP">LOST_TO_FOLLOWUP</ion-select-option>
              <ion-select-option value="TRANSFERRED">TRANSFERRED</ion-select-option>
              <ion-select-option value="UNKNOWN">UNKNOWN</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item><ion-label position="stacked">PH action</ion-label>
            <ion-select v-model="outcomeForm.ph_action" interface="popover">
              <ion-select-option value="">—</ion-select-option>
              <ion-select-option value="STANDARD_SURVEILLANCE">Standard surveillance</ion-select-option>
              <ion-select-option value="ENHANCED_SURVEILLANCE">Enhanced surveillance</ion-select-option>
              <ion-select-option value="OUTBREAK_INVESTIGATION">Outbreak investigation</ion-select-option>
              <ion-select-option value="OUTBREAK_RESPONSE">Outbreak response</ion-select-option>
              <ion-select-option value="IHR_NOTIFIED">IHR notified</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item><ion-label position="stacked">Outbreak status</ion-label>
            <ion-select v-model="outcomeForm.outbreak_status" interface="popover">
              <ion-select-option value="NONE">None</ion-select-option>
              <ion-select-option value="SPORADIC">Sporadic</ion-select-option>
              <ion-select-option value="CLUSTER">Cluster</ion-select-option>
              <ion-select-option value="OUTBREAK">Outbreak</ion-select-option>
              <ion-select-option value="EPIDEMIC">Epidemic</ion-select-option>
              <ion-select-option value="PANDEMIC">Pandemic</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item><ion-label>IHR notified</ion-label>
            <ion-select v-model="outcomeForm.ihr_notified" slot="end" interface="popover">
              <ion-select-option :value="false">No</ion-select-option>
              <ion-select-option :value="true">Yes</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item v-if="outcomeForm.ihr_notified"><ion-label position="stacked">IHR reference</ion-label><ion-input v-model="outcomeForm.ihr_reference"/></ion-item>
          <ion-item><ion-label position="stacked">Notes</ion-label><ion-textarea v-model="outcomeForm.notes" :auto-grow="true" rows="3"/></ion-item>
        </ion-list>
        <ion-button expand="block" color="tertiary" :disabled="actionBusy" @click="submitOutcome">Save outcome</ion-button>
      </ion-content>
    </ion-modal>

    <!-- Breach -->
    <ion-modal v-if="breachOpen" :is-open="breachOpen" @did-dismiss="breachOpen = false" :breakpoints="[0, 1]" :initial-breakpoint="1">
      <ion-header><ion-toolbar><ion-title>Log SLA breach</ion-title>
        <ion-buttons slot="end"><ion-button @click="breachOpen = false">Cancel</ion-button></ion-buttons>
      </ion-toolbar></ion-header>
      <ion-content class="ion-padding">
        <ion-list lines="full">
          <ion-item><ion-label position="stacked">Phase</ion-label>
            <ion-select v-model="breachForm.phase" interface="popover">
              <ion-select-option value="DETECT">DETECT</ion-select-option>
              <ion-select-option value="NOTIFY">NOTIFY</ion-select-option>
              <ion-select-option value="RESPOND">RESPOND</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item><ion-label position="stacked">Target hours</ion-label><ion-input type="number" v-model="breachForm.target_hours"/></ion-item>
          <ion-item><ion-label position="stacked">Elapsed hours</ion-label><ion-input type="number" v-model="breachForm.elapsed_hours"/></ion-item>
          <ion-item><ion-label position="stacked">Root cause</ion-label>
            <ion-select v-model="breachForm.root_cause_category" interface="action-sheet">
              <ion-select-option value="CAPACITY">CAPACITY</ion-select-option>
              <ion-select-option value="TRAINING">TRAINING</ion-select-option>
              <ion-select-option value="COMMS">COMMS</ion-select-option>
              <ion-select-option value="LAB">LAB</ion-select-option>
              <ion-select-option value="LEADERSHIP">LEADERSHIP</ion-select-option>
              <ion-select-option value="COORDINATION">COORDINATION</ion-select-option>
              <ion-select-option value="LEGAL">LEGAL</ion-select-option>
              <ion-select-option value="SUPPLIES">SUPPLIES</ion-select-option>
              <ion-select-option value="OTHER">OTHER</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item><ion-label position="stacked">Root cause text (≥10 chars)</ion-label><ion-textarea v-model="breachForm.root_cause_text" :auto-grow="true" rows="3"/></ion-item>
          <ion-item><ion-label position="stacked">Mitigation plan (≥10 chars)</ion-label><ion-textarea v-model="breachForm.mitigation_plan" :auto-grow="true" rows="3"/></ion-item>
        </ion-list>
        <ion-button expand="block" color="warning" :disabled="actionBusy" @click="submitBreach">Log breach</ion-button>
      </ion-content>
    </ion-modal>

    <!-- Add followup -->
    <ion-modal v-if="followupOpen" :is-open="followupOpen" @did-dismiss="followupOpen = false" :breakpoints="[0, 1]" :initial-breakpoint="1">
      <ion-header><ion-toolbar><ion-title>Add follow-up</ion-title>
        <ion-buttons slot="end"><ion-button @click="followupOpen = false">Cancel</ion-button></ion-buttons>
      </ion-toolbar></ion-header>
      <ion-content class="ion-padding">
        <ion-list lines="full">
          <ion-item><ion-label position="stacked">Action label *</ion-label><ion-input v-model="newFollowup.action_label"/></ion-item>
          <ion-item><ion-label position="stacked">Action code</ion-label><ion-input v-model="newFollowup.action_code"/></ion-item>
          <ion-item><ion-label position="stacked">Due (ISO datetime)</ion-label><ion-input v-model="newFollowup.due_at" placeholder="2026-05-01 12:00:00"/></ion-item>
          <ion-item><ion-label>Blocks closure?</ion-label>
            <ion-select v-model="newFollowup.blocks_closure" slot="end" interface="popover">
              <ion-select-option :value="false">No</ion-select-option>
              <ion-select-option :value="true">Yes</ion-select-option>
            </ion-select>
          </ion-item>
          <ion-item><ion-label position="stacked">Notes</ion-label><ion-textarea v-model="newFollowup.notes" :auto-grow="true" rows="2"/></ion-item>
        </ion-list>
        <ion-button expand="block" :disabled="actionBusy" @click="submitFollowup">Create</ion-button>
      </ion-content>
    </ion-modal>

    <!-- Evidence attach -->
    <ion-modal v-if="evidenceOpen" :is-open="evidenceOpen" @did-dismiss="evidenceOpen = false" :breakpoints="[0, 1]" :initial-breakpoint="1">
      <ion-header><ion-toolbar><ion-title>Attach evidence</ion-title>
        <ion-buttons slot="end"><ion-button @click="evidenceOpen = false">Cancel</ion-button></ion-buttons>
      </ion-toolbar></ion-header>
      <ion-content class="ion-padding">
        <ion-button expand="block" color="primary" :disabled="actionBusy" @click="captureAndAttachPhoto">
          <ion-icon :icon="cameraOutline" slot="start"/> Capture photo
        </ion-button>
        <p style="text-align:center; margin: 12px 0; opacity:.7">— or —</p>
        <ion-list lines="full">
          <ion-item><ion-label position="stacked">Title</ion-label><ion-input v-model="evidenceForm.title"/></ion-item>
          <ion-item><ion-label position="stacked">External URL (https)</ion-label><ion-input v-model="evidenceForm.external_url"/></ion-item>
          <ion-item><ion-label position="stacked">Notes</ion-label><ion-textarea v-model="evidenceForm.notes" :auto-grow="true" rows="2"/></ion-item>
        </ion-list>
        <ion-button expand="block" fill="outline" :disabled="actionBusy" @click="submitEvidenceUrl">Attach URL</ion-button>
      </ion-content>
    </ion-modal>
  </ion-page>
</template>

<style scoped>
/* ═══════════════════════════════════════════════════════════════════════
   ALERT WAR ROOM — SENTINEL DUAL-TONE
   Conforms to /views/POEs.vue design system.
   DARK ZONE  : header · stats · tabs (navy gradients · neon accents)
   LIGHT ZONE : content · cards · panes (luminous gradients · navy ink)
═══════════════════════════════════════════════════════════════════════ */
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Syne:wght@600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap');

@keyframes awSlideUp     { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
@keyframes awScan        { 0% { left: -60%; } 100% { left: 150%; } }
@keyframes awPulseGreen  { 0%,100% { box-shadow: 0 0 6px rgba(0,168,107,.4); } 50% { box-shadow: 0 0 16px rgba(0,168,107,.8); } }
@keyframes awPulseBlue   { 0%,100% { box-shadow: 0 0 6px rgba(0,112,224,.4); } 50% { box-shadow: 0 0 16px rgba(0,112,224,.8); } }
@keyframes awPulseAmber  { 0%,100% { box-shadow: 0 0 6px rgba(255,179,0,.4); }  50% { box-shadow: 0 0 16px rgba(255,179,0,.8); } }
@keyframes awPulseRed    { 0%,100% { box-shadow: 0 0 6px rgba(224,32,80,.4); }  50% { box-shadow: 0 0 16px rgba(224,32,80,.8); } }
@keyframes awShimmer     { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; } }

/* ═══ DARK ZONE — Header ═══ */
.aw-header  { --background: #070E1B; }
.aw-toolbar { --background: linear-gradient(180deg, #070E1B 0%, #0E1A2E 100%); --color: #EDF2FA; --border-width: 0; }
.aw-title-block { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.aw-eyebrow {
  font-size: 7px; font-weight: 700; color: #7E92AB;
  letter-spacing: 1.2px; text-transform: uppercase;
  font-family: 'DM Sans', sans-serif;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.aw-title {
  font-size: 18px; font-weight: 800; color: #EDF2FA;
  font-family: 'Syne', sans-serif; line-height: 1.1;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.aw-more-badge {
  display: flex; align-items: center; justify-content: center;
  width: 36px; height: 36px; padding: 0; margin-right: 4px;
  background: rgba(0, 180, 255, .08); color: #00B4FF;
  border: 1px solid rgba(0, 180, 255, .15); border-radius: 10px;
  cursor: pointer; -webkit-tap-highlight-color: transparent;
}
.aw-more-dots { font-size: 22px; line-height: 1; font-weight: 700; }

.aw-stats-ribbon {
  display: flex; align-items: center;
  background: linear-gradient(180deg, #0E1A2E 0%, #142640 100%);
  padding: 9px 12px; border-bottom: 1px solid rgba(255, 255, 255, .05);
  position: relative; overflow: hidden;
}
.aw-stats-texture {
  position: absolute; inset: 0; pointer-events: none;
  background-image: linear-gradient(rgba(0, 180, 255, .03) 1px, transparent 1px),
                    linear-gradient(90deg, rgba(0, 180, 255, .03) 1px, transparent 1px);
  background-size: 28px 28px;
  mask-image: linear-gradient(180deg, black 50%, transparent 100%);
}
.aw-stat {
  display: flex; flex-direction: column; align-items: center;
  flex: 1; min-width: 0;          /* allow ellipsis to actually clip */
  position: relative; z-index: 1;
}
.aw-stat-n {
  font-size: 17px; font-weight: 900; color: #EDF2FA;
  font-family: 'Syne', sans-serif; line-height: 1;
  display: inline-flex; align-items: baseline; gap: 1px;
}
.aw-stat-n small { font-size: 9px; font-weight: 700; opacity: .7; }
.aw-stat-l {
  font-size: 8px; font-weight: 700; color: #7E92AB;
  letter-spacing: .6px; text-transform: uppercase; margin-top: 1px;
  /* Hard-clip — long enums must never wrap or push siblings */
  max-width: 100%;
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.aw-stat-n--green   { color: #00E676; text-shadow: 0 0 12px rgba(0, 230, 118, .3); }
.aw-stat-n--blue    { color: #00B4FF; text-shadow: 0 0 12px rgba(0, 180, 255, .3); }
.aw-stat-n--teal    { color: #00E5FF; text-shadow: 0 0 12px rgba(0, 229, 255, .3); }
.aw-stat-n--amber   { color: #FFB300; text-shadow: 0 0 12px rgba(255, 179, 0, .3); }
.aw-stat-n--purple  { color: #B388FF; text-shadow: 0 0 12px rgba(179, 136, 255, .3); }
.aw-stat-n--red     { color: #FF6B8A; text-shadow: 0 0 12px rgba(224, 32, 80, .35); }
.aw-stat-n--warning { color: #FFB300; text-shadow: 0 0 12px rgba(255, 179, 0, .3); }
.aw-stat-n--tertiary{ color: #00B4FF; text-shadow: 0 0 12px rgba(0, 180, 255, .3); }
.aw-stat-n--success { color: #00E676; text-shadow: 0 0 12px rgba(0, 230, 118, .3); }
.aw-stat-n--medium  { color: #7E92AB; }
.aw-stat-sep { width: 1px; height: 28px; background: rgba(255, 255, 255, .06); margin: 0 2px; }

.aw-chip-row {
  display: flex; gap: 6px; overflow-x: auto;
  padding: 8px 12px 10px;
  background: linear-gradient(180deg, #0E1A2E, #0A1628);
  border-bottom: 1px solid rgba(255, 255, 255, .06);
  scrollbar-width: none; -ms-overflow-style: none;
}
.aw-chip-row::-webkit-scrollbar { display: none; }
.aw-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 7px 12px; border-radius: 20px;
  font-size: 10px; font-weight: 700;
  cursor: pointer; border: 1px solid transparent;
  white-space: nowrap; flex-shrink: 0;   /* never compress — let row scroll */
  background: rgba(255, 255, 255, .07); color: #7E92AB;
  transition: all .18s; min-height: 36px;
  font-family: 'DM Sans', sans-serif;
  -webkit-tap-highlight-color: transparent;
}
.aw-chip em { font-style: normal; background: rgba(255, 255, 255, .08); border-radius: 8px; padding: 0 5px; font-size: 8px; font-weight: 900; font-family: 'JetBrains Mono', monospace; }
.aw-chip--active { color: #EDF2FA; border-color: rgba(0, 180, 255, .3); background: rgba(0, 180, 255, .12); }
.aw-chip--active em { background: rgba(0, 180, 255, .25); color: #EDF2FA; }

/* ═══ LIGHT ZONE ═══ */
.aw-scroll { font-family: 'DM Sans', sans-serif; }
.aw-spacer-top { height: 6px; }

.war-strip {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; margin: 8px 12px 0;
  font-size: 11px; font-weight: 600;
  border-radius: 10px;
  background: linear-gradient(135deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0, 0, 0, .06);
  color: #475569;
}
.war-strip ion-icon { font-size: 16px; }
.war-strip--off    { background: linear-gradient(135deg, #FEF2F2, #FECACA); border-color: rgba(224, 32, 80, .2); color: #E02050; }
.war-strip--sync   { background: linear-gradient(135deg, #E0ECFF, #CCE0FF); border-color: rgba(0, 112, 224, .2); color: #0070E0; }
.war-strip--stale  { background: linear-gradient(135deg, #FFFBEB, #FEF3C7); border-color: rgba(204, 136, 0, .2); color: #CC8800; }

.war-skel { padding: 14px 12px; display: flex; flex-direction: column; gap: 12px; }
.war-skel > * { background: linear-gradient(90deg, #E5EBF5 0%, #F4F7FC 50%, #E5EBF5 100%); background-size: 200% 100%; border-radius: 14px; animation: awShimmer 1.4s ease-in-out infinite; }
.war-skel-hero { height: 220px; }
.war-skel-row  { height: 64px; }
.war-skel-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; background: none; animation: none; padding: 0; border-radius: 0; }
.war-skel-tile { height: 100px; }

.war-empty { display: flex; flex-direction: column; align-items: center; padding: 80px 24px; gap: 12px; min-height: 50vh; }
.war-empty-glyph { width: 64px; height: 64px; border-radius: 14px; background: linear-gradient(135deg, #E0ECFF, #CCE0FF); border: 1.5px solid rgba(0, 112, 224, .15); display: flex; align-items: center; justify-content: center; color: #0070E0; }
.war-empty-glyph ion-icon { font-size: 30px; }
.war-empty h3 { margin: 0; font-size: 17px; font-weight: 700; color: #475569; font-family: 'DM Sans', sans-serif; }
.war-empty p  { margin: 0; font-size: 12px; color: #94A3B8; text-align: center; line-height: 1.5; }
.war-btn-ghost { margin-top: 8px; padding: 10px 22px; border-radius: 10px; background: linear-gradient(135deg, #0055CC, #0070E0); color: #fff; border: 0; cursor: pointer; font-size: 12px; font-weight: 700; font-family: 'DM Sans', sans-serif; box-shadow: 0 4px 14px rgba(0, 112, 224, .3); min-height: 44px; }

/* ═══ HERO ═══ */
.war-hero { position: relative; overflow: hidden; margin: 12px 12px 10px; padding: 22px 18px 18px; border-radius: 18px; animation: awSlideUp .4s cubic-bezier(.16, 1, .3, 1) both; box-shadow: 0 4px 22px rgba(0, 30, 80, .14); }
.war-hero--danger   { background: linear-gradient(160deg, #1B0011 0%, #2E0420 50%, #3D0726 100%); }
.war-hero--warning  { background: linear-gradient(160deg, #1B1101 0%, #2E1F03 50%, #3D2F06 100%); }
.war-hero--tertiary { background: linear-gradient(160deg, #110011 0%, #1F0A2E 50%, #2A0F3D 100%); }
.war-hero--success  { background: linear-gradient(160deg, #011B0A 0%, #042E14 50%, #073D1A 100%); }
.war-hero--medium   { background: linear-gradient(160deg, #010D1E 0%, #031526 50%, #061E38 100%); }
.war-hero::before { content: ''; position: absolute; inset: 0; pointer-events: none; background-image: linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px); background-size: 24px 24px; mask-image: linear-gradient(180deg, black 40%, transparent 100%); }
.war-hero-glow { position: absolute; top: -80px; right: -80px; z-index: 0; width: 260px; height: 260px; border-radius: 50%; filter: blur(80px); opacity: .35; pointer-events: none; }
.war-hero--danger   .war-hero-glow { background: #FF6B8A; }
.war-hero--warning  .war-hero-glow { background: #FFB300; }
.war-hero--tertiary .war-hero-glow { background: #B388FF; }
.war-hero--success  .war-hero-glow { background: #00E676; }
.war-hero--medium   .war-hero-glow { background: #00B4FF; }

.war-hero-meta { position: relative; z-index: 2; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; font-size: 9px; font-weight: 700; letter-spacing: .8px; color: #7E92AB; text-transform: uppercase; font-family: 'DM Sans', sans-serif; }
.war-status-line { display: inline-flex; align-items: center; gap: 5px; color: #EDF2FA; }
.war-status-dot { width: 7px; height: 7px; border-radius: 50%; background: #7E92AB; }
.war-status-dot--warning  { background: #FFB300; animation: awPulseAmber 2s infinite; }
.war-status-dot--tertiary { background: #00B4FF; animation: awPulseBlue 2s infinite; }
.war-status-dot--success  { background: #00E676; animation: awPulseGreen 2s infinite; }
.war-meta-sep { width: 3px; height: 3px; border-radius: 50%; background: #7E92AB; opacity: .5; }
.war-meta-route { font-family: 'JetBrains Mono', monospace; letter-spacing: .8px; }

.war-hero-name { position: relative; z-index: 2; margin: 12px 0 4px; padding: 0; font-size: 28px; font-weight: 900; line-height: 1.05; color: #EDF2FA; letter-spacing: -.5px; font-family: 'Syne', sans-serif; }
.war-hero-cond { position: relative; z-index: 2; margin: 0 0 14px; font-size: 13px; font-weight: 600; line-height: 1.4; color: #B7C5DA; letter-spacing: .2px; font-family: 'DM Sans', sans-serif; }
.war-hero-pills { position: relative; z-index: 2; display: flex; flex-wrap: wrap; gap: 6px; }
.war-pill { display: inline-flex; align-items: center; font-size: 9px; font-weight: 700; padding: 4px 9px; border-radius: 6px; letter-spacing: .4px; background: rgba(255,255,255,.07); color: #B7C5DA; border: 1px solid rgba(255,255,255,.1); font-family: 'JetBrains Mono', monospace; }
.war-pill--solid { background: rgba(0, 180, 255, .12); color: #00B4FF; border-color: rgba(0, 180, 255, .25); }

.war-hero-risk { position: absolute; top: 16px; right: 14px; z-index: 3; display: flex; flex-direction: column; align-items: center; gap: 2px; padding: 6px 10px; border-radius: 10px; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.1); -webkit-backdrop-filter: blur(8px); backdrop-filter: blur(8px); }
.war-risk-glyph { font-size: 18px; font-weight: 900; line-height: 1; color: #EDF2FA; font-family: 'Syne', sans-serif; letter-spacing: -.5px; }
.war-risk-label { font-size: 7px; font-weight: 700; color: #7E92AB; letter-spacing: 1px; text-transform: uppercase; }
.war-hero--danger   .war-risk-glyph { color: #FF6B8A; }
.war-hero--warning  .war-risk-glyph { color: #FFB300; }
.war-hero--tertiary .war-risk-glyph { color: #B388FF; }
.war-hero--success  .war-risk-glyph { color: #00E676; }

/* ═══ PRIMARY CTA ═══ */
.war-cta-wrap { padding: 4px 12px 10px; }
.war-cta { position: relative; overflow: hidden; display: flex; align-items: center; gap: 12px; width: 100%; padding: 14px 18px; border: 0; border-radius: 14px; font-size: 14px; font-weight: 800; letter-spacing: -.2px; font-family: 'DM Sans', sans-serif; cursor: pointer; -webkit-tap-highlight-color: transparent; transition: transform .15s cubic-bezier(.16, 1, .3, 1), box-shadow .15s, filter .15s; color: #fff; }
.war-cta::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 15%, rgba(255,255,255,.55) 50%, transparent 85%); }
.war-cta:active { transform: scale(.985); filter: brightness(.94); }
.war-cta-icon { font-size: 20px; flex-shrink: 0; }
.war-cta-label { flex: 1; text-align: left; }
.war-cta-arrow { font-size: 16px; opacity: .9; transition: transform .2s; }
.war-cta:hover .war-cta-arrow { transform: translateX(2px); }
.war-cta--amber   { background: linear-gradient(135deg, #FFB300, #FF8800); box-shadow: 0 4px 16px rgba(255,179,0,.35); color: #1B1101; }
.war-cta--success { background: linear-gradient(135deg, #00C865, #00A86B); box-shadow: 0 4px 16px rgba(0,168,107,.32); }
.war-cta--info    { background: linear-gradient(135deg, #0070E0, #0055CC); box-shadow: 0 4px 16px rgba(0,112,224,.3); }
.war-cta--neutral { background: linear-gradient(145deg, #FFFFFF, #F4F7FC); color: #0B1A30; border: 1.5px solid rgba(0,0,0,.06); box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 14px rgba(0,30,80,.06); }

/* ═══ VITAL CLUSTER ═══ */
.war-cluster { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; padding: 4px 12px 10px; }
@media (min-width: 720px) { .war-cluster { grid-template-columns: repeat(4, 1fr); } }
.war-vital { position: relative; padding: 12px 14px; background: linear-gradient(145deg, #FFFFFF, #F4F7FC); border: 1.5px solid rgba(0,0,0,.06); border-radius: 14px; box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 18px rgba(0,30,80,.06); display: flex; flex-direction: column; gap: 4px; overflow: hidden; animation: awSlideUp .35s cubic-bezier(.16, 1, .3, 1) both; }
.war-vital::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 15%, rgba(255,255,255,.9) 50%, transparent 85%); }
.war-vital-l { font-size: 8px; font-weight: 800; letter-spacing: .8px; text-transform: uppercase; color: #94A3B8; font-family: 'DM Sans', sans-serif; }
.war-vital-v { display: flex; align-items: baseline; gap: 3px; color: #0B1A30; font-family: 'Syne', sans-serif; }
.war-vital-v b { font-size: 26px; font-weight: 900; line-height: 1; letter-spacing: -.6px; font-family: 'Syne', sans-serif; }
.war-vital-v small { font-size: 11px; font-weight: 700; color: #94A3B8; font-family: 'JetBrains Mono', monospace; }
.war-vital--ok      { border-color: rgba(0,168,107,.25); }
.war-vital--ok .war-vital-v b { color: #00A86B; text-shadow: 0 0 8px rgba(0,168,107,.15); }
.war-vital--warn    { border-color: rgba(204,136,0,.3); background: linear-gradient(145deg, #FFFBEB, #FEF3C7); }
.war-vital--warn .war-vital-v b { color: #CC8800; text-shadow: 0 0 10px rgba(255,179,0,.25); }
.war-vital--danger  { border-color: rgba(224,32,80,.35); background: linear-gradient(145deg, #FEF2F2, #FECACA); }
.war-vital--danger .war-vital-v b { color: #E02050; text-shadow: 0 0 10px rgba(224,32,80,.25); }

/* ═══ PANES — legacy class names mapped to Sentinel tokens ═══ */
.awr-section { padding: 6px 12px 10px; }
.awr-section ion-card,
.cf-section,
.awr-tl,
.cf-v {
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0,0,0,.06);
  border-radius: 14px;
  box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  margin: 0 0 10px; position: relative; overflow: hidden;
  animation: awSlideUp .4s cubic-bezier(.16, 1, .3, 1) both;
}
.awr-section ion-card::before,
.cf-section::before,
.cf-v::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 15%, rgba(255,255,255,.9) 50%, transparent 85%); z-index: 2; }
.awr-section ion-card { contain: layout; --background: transparent; --color: #0B1A30; }
ion-card.awr-banner { display: none !important; }

.awr-section-head { display: flex; justify-content: space-between; align-items: center; padding: 14px 4px 6px; }
.awr-section-head strong { font-size: 9px; font-weight: 800; letter-spacing: 1.1px; text-transform: uppercase; color: #0070E0; font-family: 'DM Sans', sans-serif; }

.awr-section ion-card-content { --color: #0B1A30; padding: 14px 16px; font-size: 13px; line-height: 1.55; font-family: 'DM Sans', sans-serif; }
.awr-section ion-card-content h4 { margin: 0 0 12px; padding: 0 0 8px; font-size: 9px; font-weight: 800; letter-spacing: 1.1px; text-transform: uppercase; color: #0070E0; border-bottom: 1px solid rgba(0,112,224,.12); font-family: 'DM Sans', sans-serif; }
.awr-section ion-card-content p { color: #475569; }
.awr-section ion-card-content small { color: #94A3B8; }

/* Overview "Clinical at a glance" — high-density at-a-glance card */
.awr-glance-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px 14px; margin-bottom: 10px; }
.awr-glance { display: flex; flex-direction: column; gap: 3px; }
.awr-glance-k { font-size: 9px; font-weight: 800; letter-spacing: 0.9px; text-transform: uppercase; color: #94A3B8; }
.awr-glance-v { font-size: 13px; color: #0B1A30; }
.awr-glance-sus { display: flex; flex-direction: column; gap: 3px; padding: 8px 0; border-top: 1px dashed #E2E8F0; }
.awr-glance-counts { display: flex; flex-wrap: wrap; gap: 10px; padding-top: 8px; border-top: 1px dashed #E2E8F0; }
.awr-glance-counts .awr-count { font-size: 11px; color: #475569; }
.awr-glance-counts .awr-count b { color: #0B1A30; font-weight: 700; font-size: 13px; }
.awr-glance-notes { padding-top: 10px; margin-top: 8px; border-top: 1px dashed #E2E8F0; }
.awr-glance-notes p { margin: 4px 0 0; font-size: 12px; color: #334155; white-space: pre-wrap; }
.awr-glance-fu { display: flex; flex-wrap: wrap; gap: 6px; }
.awr-glance-fu small { color: #94A3B8; font-size: 10px; }
.awr-vital { } /* tone classes already styled elsewhere */

/* "Open full case file" CTA — primary entry to /secondary-screening viewer */
.cf-open-cta { margin-bottom: 12px; --background: linear-gradient(135deg, rgba(0,112,224,.06), rgba(0,112,224,.02)); border: 1px solid rgba(0,112,224,.20); }
.cf-open-flex { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.cf-open-flex strong { display: block; font-size: 13px; color: #0B1A30; font-weight: 700; margin-bottom: 2px; }
.cf-open-flex p { margin: 0; font-size: 11px; color: #475569; }

.awr-fu-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap; }
.awr-fu-row strong { color: #0B1A30; font-size: 13px; font-weight: 700; }
.awr-fu-notes { margin: 8px 0; font-size: 12px; color: #475569; padding: 9px 12px; background: linear-gradient(135deg, #E0ECFF, #CCE0FF); border-radius: 8px; border: 1px solid rgba(0,112,224,.12); }
.awr-fu-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
.awr-warn { color: #CC8800; font-weight: 700; }
.awr-block { margin: 8px 0; display: flex; flex-wrap: wrap; gap: 6px; }
.awr-script { margin-top: 12px; padding: 12px 14px; background: linear-gradient(135deg, #ECFDF5, #D1FAE5); border-radius: 10px; border: 1.5px solid rgba(0,168,107,.2); font-size: 12px; line-height: 1.55; color: #007A50; font-weight: 600; font-family: 'DM Sans', sans-serif; }
.awr-tl { padding: 12px 14px; }
.awr-tl-row { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 6px; }
.awr-tl-row small { color: #94A3B8; font-size: 10px; margin-left: auto; font-family: 'JetBrains Mono', monospace; }
.awr-hint { font-size: 11px; color: #94A3B8; padding: 0 4px; margin-top: 12px; }

ion-chip { --background: linear-gradient(135deg, #E0ECFF, #CCE0FF); --color: #0070E0; font-size: 9px; font-weight: 800; height: 22px; border-radius: 6px; font-family: 'JetBrains Mono', monospace; letter-spacing: .3px; }
ion-chip[outline="true"] { border: 1px solid rgba(0,0,0,.08); }
ion-chip[color="warning"]  { --background: linear-gradient(135deg, #FFFBEB, #FEF3C7); --color: #CC8800; }
ion-chip[color="success"]  { --background: linear-gradient(135deg, #ECFDF5, #D1FAE5); --color: #00A86B; }
ion-chip[color="danger"]   { --background: linear-gradient(135deg, #FEF2F2, #FECACA); --color: #E02050; }
ion-chip[color="tertiary"] { --background: linear-gradient(135deg, #F5F3FF, #EDE9FE); --color: #7B40D8; }
ion-chip[color="medium"]   { --background: rgba(0,0,0,.04); --color: #475569; }
ion-chip[color="dark"]     { --background: linear-gradient(135deg, #1F2937, #0B1A30); --color: #EDF2FA; }

.awr-section ion-button { --border-radius: 10px; --padding-start: 14px; --padding-end: 14px; font-family: 'DM Sans', sans-serif; font-weight: 700; letter-spacing: -.1px; font-size: 12px; text-transform: none; }

/* ═══ CASE FILE ═══ */
.cf { padding: 6px 12px 24px; }
.cf-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 200px; gap: 8px; color: #94A3B8; }
.cf-empty ion-icon { font-size: 36px; opacity: .35; }

.cf-hero { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding: 18px; background: linear-gradient(160deg, #010D1E 0%, #031526 50%, #061E38 100%); border-radius: 16px; margin: 6px 0 10px; position: relative; overflow: hidden; }
.cf-hero::before { content: ''; position: absolute; inset: 0; pointer-events: none; background-image: linear-gradient(rgba(255,255,255,.025) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.025) 1px, transparent 1px); background-size: 24px 24px; mask-image: linear-gradient(180deg, black 40%, transparent 100%); }
.cf-hero > * { position: relative; z-index: 1; }
.cf-hero-eyebrow { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #7E92AB; font-weight: 700; font-family: 'DM Sans', sans-serif; }
.cf-hero-name { margin: 6px 0 8px; font-size: 22px; font-weight: 900; letter-spacing: -.5px; color: #EDF2FA; line-height: 1.05; font-family: 'Syne', sans-serif; }
.cf-hero-demo { display: flex; flex-wrap: wrap; gap: 10px; font-size: 11px; color: #B7C5DA; font-weight: 600; font-family: 'DM Sans', sans-serif; }
.cf-hero-r { display: flex; flex-direction: column; gap: 5px; align-items: flex-end; flex-shrink: 0; }
.cf-pill { font-size: 9px; font-weight: 800; padding: 4px 9px; border-radius: 6px; letter-spacing: .4px; text-transform: uppercase; background: rgba(255,255,255,.07); color: #B7C5DA; border: 1px solid rgba(255,255,255,.1); font-family: 'JetBrains Mono', monospace; }
.cf-pill--critical { background: rgba(224,32,80,.15); color: #FF6B8A; border-color: rgba(224,32,80,.3); }
.cf-pill--high     { background: rgba(255,179,0,.15); color: #FFB300; border-color: rgba(255,179,0,.3); }
.cf-pill--medium   { background: rgba(179,136,255,.15); color: #B388FF; border-color: rgba(179,136,255,.3); }
.cf-pill--low      { background: rgba(0,230,118,.15); color: #00E676; border-color: rgba(0,230,118,.3); }
.cf-pill--quiet    { background: rgba(255,255,255,.05); color: #B7C5DA; }

.cf-vitals { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 10px; }
@media (max-width: 480px) { .cf-vitals { grid-template-columns: repeat(2, 1fr); } }
.cf-v { padding: 12px; display: flex; flex-direction: column; gap: 4px; }
.cf-v--wide { grid-column: span 2; }
.cf-v-l { font-size: 8px; text-transform: uppercase; letter-spacing: .8px; color: #94A3B8; font-weight: 800; font-family: 'DM Sans', sans-serif; }
.cf-v-n { font-size: 22px; font-weight: 900; letter-spacing: -.5px; display: flex; align-items: baseline; gap: 3px; color: #0B1A30; font-family: 'Syne', sans-serif; }
.cf-v-n small { font-size: 10px; font-weight: 700; color: #94A3B8; font-family: 'JetBrains Mono', monospace; }
.cf-v-n--ok      { color: #00A86B; }
.cf-v-n--warn    { color: #CC8800; }
.cf-v-n--danger  { color: #E02050; }
.cf-v-n--text    { font-size: 13px; font-weight: 700; line-height: 1.3; }

.cf-section { padding: 0; }
.cf-section-h { display: flex; align-items: center; gap: 8px; padding: 12px 14px 6px; }
.cf-section-i { font-size: 14px; opacity: .9; }
.cf-section-t { margin: 0; font-size: 9px; text-transform: uppercase; letter-spacing: 1.1px; font-weight: 800; color: #0070E0; border-bottom: 1px solid rgba(0,112,224,.12); padding-bottom: 6px; flex: 1; font-family: 'DM Sans', sans-serif; }
.cf-section-b { padding: 8px 14px 14px; }
.cf-section--tertiary .cf-section-i { color: #7B40D8; }
.cf-section--tertiary .cf-section-t { color: #7B40D8; border-bottom-color: rgba(123,64,216,.15); }
.cf-section--warning  .cf-section-i { color: #CC8800; }
.cf-section--warning  .cf-section-t { color: #CC8800; border-bottom-color: rgba(204,136,0,.15); }
.cf-section--danger   .cf-section-i { color: #E02050; }
.cf-section--danger   .cf-section-t { color: #E02050; border-bottom-color: rgba(224,32,80,.15); }
.cf-section--primary  .cf-section-i { color: #0070E0; }

.cf-rank { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; }
.cf-rank > li { display: flex; gap: 10px; align-items: flex-start; }
.cf-rank-n { flex-shrink: 0; width: 26px; height: 26px; border-radius: 999px; background: linear-gradient(135deg, #F5F3FF, #EDE9FE); color: #7B40D8; font-size: 10px; font-weight: 900; display: inline-flex; align-items: center; justify-content: center; font-family: 'JetBrains Mono', monospace; border: 1px solid rgba(123,64,216,.15); }
.cf-rank > li:first-child .cf-rank-n { background: linear-gradient(135deg, #7B40D8, #5B2EAB); color: #fff; box-shadow: 0 0 12px rgba(123,64,216,.35); }
.cf-rank-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 3px; }
.cf-rank-body strong { font-size: 13px; font-weight: 800; color: #0B1A30; font-family: 'DM Sans', sans-serif; }
.cf-rank-body small  { font-size: 11px; color: #475569; line-height: 1.45; }
.cf-rank-meta { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 4px; }
.cf-conf-bar { position: relative; flex: 1; min-width: 100px; max-width: 200px; height: 4px; background: rgba(0,0,0,.06); border-radius: 999px; }
.cf-conf-fill { position: absolute; left: 0; top: 0; bottom: 0; background: linear-gradient(90deg, #7B40D8, #B388FF); border-radius: 999px; }
.cf-conf-pct { position: absolute; right: -38px; top: 50%; transform: translateY(-50%); font-size: 10px; font-weight: 800; color: #7B40D8; font-family: 'JetBrains Mono', monospace; }

.cf-chips { display: flex; flex-wrap: wrap; gap: 5px; }
.cf-chip { display: inline-flex; align-items: center; gap: 4px; padding: 5px 10px; border-radius: 6px; background: linear-gradient(135deg, #E0ECFF, #CCE0FF); font-size: 10px; font-weight: 700; color: #0070E0; font-family: 'DM Sans', sans-serif; border: 1px solid rgba(0,112,224,.15); }
.cf-chip small { color: #94A3B8; font-size: 9px; font-family: 'JetBrains Mono', monospace; }
.cf-chip--alert { background: linear-gradient(135deg, #FEF2F2, #FECACA); color: #E02050; border-color: rgba(224,32,80,.2); }

.cf-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 7px; }
.cf-list > li { padding: 10px 12px; background: linear-gradient(145deg, #FFFFFF, #F4F7FC); border-radius: 10px; border: 1px solid rgba(0,0,0,.05); border-left: 3px solid rgba(0,112,224,.25); }
.cf-list > li strong { font-size: 12px; color: #0B1A30; font-weight: 700; }
.cf-list > li p { margin: 3px 0 0; font-size: 11px; color: #475569; }
.cf-list > li small { font-size: 10px; color: #94A3B8; font-family: 'JetBrains Mono', monospace; }

.cf-kv { display: grid; grid-template-columns: 1fr 1fr; gap: 9px 16px; margin: 0; }
@media (max-width: 480px) { .cf-kv { grid-template-columns: 1fr; } }
.cf-kv > div { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.cf-kv-wide { grid-column: 1 / -1; }
.cf-kv dt { font-size: 8px; text-transform: uppercase; letter-spacing: .8px; color: #94A3B8; font-weight: 800; font-family: 'DM Sans', sans-serif; }
.cf-kv dd { margin: 0; font-size: 13px; font-weight: 700; color: #0B1A30; word-wrap: break-word; font-family: 'DM Sans', sans-serif; }
.cf-mono { font-family: 'JetBrains Mono', monospace; font-size: 11px; color: #0070E0; background: linear-gradient(135deg, #E0ECFF, #CCE0FF); padding: 2px 6px; border-radius: 4px; align-self: flex-start; }
.cf-tiny { font-size: 10px; color: #94A3B8; font-family: 'JetBrains Mono', monospace; }
.cf-quote { padding: 10px 12px; background: linear-gradient(135deg, #FFFBEB, #FEF3C7); border-radius: 10px; border: 1px solid rgba(204,136,0,.18); border-left: 3px solid #CC8800; font-style: normal; font-weight: 600; font-size: 12px; line-height: 1.5; color: #7A4F00; }

/* Modals */
ion-modal { --background: #EAF0FA; --backdrop-opacity: .42; }
ion-modal ion-toolbar { --background: linear-gradient(180deg, #070E1B 0%, #0E1A2E 100%); --color: #EDF2FA; --border-color: rgba(255,255,255,.06); }
ion-modal ion-content { --background: linear-gradient(180deg, #EAF0FA 0%, #F2F5FB 40%, #E4EBF7 100%); --color: #0B1A30; }
ion-modal h4 { color: #0B1A30; font-weight: 800; font-family: 'Syne', sans-serif; letter-spacing: -.3px; }

/* Overflow action sheet */
.war-actions-sheet { padding: 8px 14px 24px; background: linear-gradient(180deg, #EAF0FA 0%, #F2F5FB 100%); min-height: 100%; }
.war-action-row { display: flex; gap: 12px; align-items: center; width: 100%; padding: 12px 14px; margin-bottom: 8px; background: linear-gradient(145deg, #FFFFFF, #F4F7FC); border: 1.5px solid rgba(0,0,0,.06); border-radius: 12px; font-size: 13px; font-weight: 700; color: #0B1A30; text-align: left; cursor: pointer; font-family: 'DM Sans', sans-serif; -webkit-tap-highlight-color: transparent; transition: transform .12s, box-shadow .15s; position: relative; overflow: hidden; }
.war-action-row::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 15%, rgba(255,255,255,.85) 50%, transparent 85%); }
.war-action-row:active { transform: scale(.99); box-shadow: 0 2px 10px rgba(0,30,80,.08); }
.war-action-row ion-icon { font-size: 18px; color: #0070E0; flex-shrink: 0; width: 36px; height: 36px; background: linear-gradient(135deg, #E0ECFF, #CCE0FF); border-radius: 9px; padding: 9px; border: 1px solid rgba(0,112,224,.15); }
.war-action-text { display: flex; flex-direction: column; gap: 2px; flex: 1; min-width: 0; }
.war-action-row strong { font-size: 13px; font-weight: 800; color: #0B1A30; }
.war-action-row small  { font-size: 10px; font-weight: 600; color: #94A3B8; font-family: 'DM Sans', sans-serif; }
.war-action-row.is-warn ion-icon  { background: linear-gradient(135deg, #FFFBEB, #FEF3C7); color: #CC8800; border-color: rgba(204,136,0,.2); }
.war-action-row.is-danger ion-icon { background: linear-gradient(135deg, #FEF2F2, #FECACA); color: #E02050; border-color: rgba(224,32,80,.2); }

/* ═══════════════════════════════════════════════════════════════════════
   CASE FILE PANE — Sentinel detail-modal pattern
   Replaces the bland `.cf-*` cards with POE.vue's `.pd-*` design language
   (section labels, tree-nodes, flag cards, ops grids, ref tables, tips).
   ═══════════════════════════════════════════════════════════════════════ */
.cf-pane { padding: 4px 12px 24px; }

/* Section label — matches .pd-section-label */
.pdr-section { margin-bottom: 22px; animation: awSlideUp .35s cubic-bezier(.16, 1, .3, 1) both; }
.pdr-label {
  display: flex; align-items: center; gap: 8px;
  font-size: 9px; font-weight: 800; color: #0070E0;
  letter-spacing: 1.1px; text-transform: uppercase;
  margin-bottom: 12px; padding-bottom: 8px;
  border-bottom: 1px solid rgba(0, 112, 224, .12);
  font-family: 'DM Sans', sans-serif;
}
.pdr-glyph {
  width: 24px; height: 24px; border-radius: 6px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; line-height: 1;
  background: linear-gradient(135deg, #E0ECFF, #CCE0FF);
  border: 1px solid rgba(0, 112, 224, .15); color: #0070E0;
}
.pdr-glyph--violet { background: linear-gradient(135deg, #F5F3FF, #EDE9FE); border-color: rgba(123, 64, 216, .18); color: #7B40D8; }
.pdr-glyph--amber  { background: linear-gradient(135deg, #FFFBEB, #FEF3C7); border-color: rgba(204, 136, 0, .18); color: #CC8800; }
.pdr-glyph--rose   { background: linear-gradient(135deg, #FEF2F2, #FECACA); border-color: rgba(224, 32, 80, .18); color: #E02050; }
.pdr-glyph--blue   { background: linear-gradient(135deg, #E0ECFF, #CCE0FF); border-color: rgba(0, 112, 224, .18); color: #0070E0; }
.pdr-glyph--green  { background: linear-gradient(135deg, #ECFDF5, #D1FAE5); border-color: rgba(0, 168, 107, .18); color: #00A86B; }
.pdr-glyph--slate  { background: linear-gradient(135deg, #F1F5F9, #E2E8F0); border-color: rgba(71, 85, 105, .18); color: #475569; }

/* Suspected-disease ranked cards — top one highlighted */
.pdr-rank-list { display: flex; flex-direction: column; gap: 8px; }
.pdr-rank-card {
  position: relative; overflow: hidden;
  display: flex; align-items: stretch; gap: 0;
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0, 0, 0, .06);
  border-radius: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, .04), 0 4px 20px rgba(0, 30, 80, .06);
}
.pdr-rank-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent 15%, rgba(255, 255, 255, .9) 50%, transparent 85%);
  z-index: 2;
}
.pdr-rank-card--top {
  background: linear-gradient(135deg, #F5F3FF, #EDE9FE);
  border-color: rgba(123, 64, 216, .25);
}
.pdr-rank-rail { width: 4px; flex-shrink: 0; background: rgba(123, 64, 216, .35); }
.pdr-rank-card--top .pdr-rank-rail { background: linear-gradient(180deg, #7B40D8, #B388FF); box-shadow: 0 0 12px rgba(123, 64, 216, .35); }
.pdr-rank-rank {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  min-width: 56px; padding: 12px 8px;
  border-right: 1px solid rgba(0, 0, 0, .04);
}
.pdr-rank-num { font-size: 22px; font-weight: 900; color: #7B40D8; line-height: 1; font-family: 'Syne', sans-serif; letter-spacing: -.04em; }
.pdr-rank-card--top .pdr-rank-num { color: #5B2EAB; }
.pdr-rank-of  { font-size: 9px; font-weight: 700; color: #94A3B8; margin-top: 2px; font-family: 'JetBrains Mono', monospace; }

.pdr-rank-body { flex: 1; padding: 12px 14px; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.pdr-rank-name { font-size: 14px; font-weight: 800; color: #0B1A30; font-family: 'DM Sans', sans-serif; line-height: 1.25; }
.pdr-rank-card--top .pdr-rank-name { font-size: 15px; }
.pdr-rank-def  { font-size: 11px; color: #475569; line-height: 1.5; font-family: 'DM Sans', sans-serif; }
.pdr-rank-meta { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-top: 6px; }
.pdr-conf { display: flex; align-items: center; gap: 8px; flex: 1; min-width: 100px; }
.pdr-conf-bar { position: relative; flex: 1; height: 5px; background: rgba(0, 0, 0, .06); border-radius: 999px; overflow: hidden; }
.pdr-conf-fill { position: absolute; left: 0; top: 0; bottom: 0; background: linear-gradient(90deg, #7B40D8, #B388FF); border-radius: 999px; }
.pdr-conf-pct { font-size: 11px; font-weight: 800; color: #7B40D8; font-family: 'JetBrains Mono', monospace; min-width: 36px; text-align: right; }
.pdr-tag {
  display: inline-flex; align-items: center;
  font-size: 8px; font-weight: 800; padding: 3px 7px;
  border-radius: 4px;
  background: rgba(0, 112, 224, .08); color: #0070E0;
  border: 1px solid rgba(0, 112, 224, .15);
  letter-spacing: .3px; font-family: 'JetBrains Mono', monospace;
}
.pdr-tag--ihr {
  background: linear-gradient(135deg, #F5F3FF, #EDE9FE);
  color: #7B40D8; border-color: rgba(123, 64, 216, .2);
}
.pdr-thresh { display: flex; flex-direction: column; gap: 4px; margin-top: 8px; padding-top: 8px; border-top: 1px dashed rgba(0, 0, 0, .08); }
.pdr-thresh-row { display: flex; align-items: flex-start; gap: 8px; font-size: 11px; line-height: 1.45; color: #475569; font-family: 'DM Sans', sans-serif; }
.pdr-thresh-tag {
  flex-shrink: 0; font-size: 8px; font-weight: 900; letter-spacing: .4px;
  padding: 2px 6px; border-radius: 3px; font-family: 'JetBrains Mono', monospace;
  margin-top: 1px;
}
.pdr-thresh-tag--alert { background: rgba(202, 138, 4, .12); color: #B45309; border: 1px solid rgba(202, 138, 4, .2); }
.pdr-thresh-tag--epi   { background: rgba(220, 38, 38, .1);  color: #B91C1C; border: 1px solid rgba(220, 38, 38, .18); }
.pdr-thresh-text { flex: 1; min-width: 0; }

/* Symptom flag-card grid */
.pdr-flag-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
@media (min-width: 480px) { .pdr-flag-grid { grid-template-columns: repeat(3, 1fr); } }
.pdr-flag {
  position: relative; overflow: hidden;
  border-radius: 14px; padding: 12px 12px 10px;
  border: 1.5px solid;
  display: flex; flex-direction: column; gap: 4px;
  animation: awSlideUp .35s cubic-bezier(.16, 1, .3, 1) both;
}
.pdr-flag-shine { position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 20%, rgba(255, 255, 255, .8) 50%, transparent 80%); }
.pdr-flag--info   { background: linear-gradient(145deg, #FFFFFF, #F4F7FC); border-color: rgba(0, 0, 0, .06); color: #0B1A30; }
.pdr-flag--warn   { background: linear-gradient(135deg, #FFFBEB, #FEF3C7); border-color: rgba(204, 136, 0, .22); color: #7A4F00; }
.pdr-flag--danger { background: linear-gradient(135deg, #FEF2F2, #FECACA); border-color: rgba(224, 32, 80, .22); color: #951536; }
.pdr-flag-icon { font-size: 18px; line-height: 1; opacity: .9; margin-bottom: 4px; }
.pdr-flag-name {
  font-size: 12px; font-weight: 700; line-height: 1.3;
  font-family: 'DM Sans', sans-serif;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;
  overflow: hidden;
}
.pdr-flag-meta {
  display: flex; gap: 6px; flex-wrap: wrap;
  font-size: 9px; font-weight: 700; opacity: .75;
  font-family: 'JetBrains Mono', monospace;
}
.pdr-flag-sev { letter-spacing: .5px; }

/* Tree-node list (exposures, identity, samples) */
.pdr-nodes { display: flex; flex-direction: column; gap: 8px; }
.pdr-node {
  position: relative; overflow: hidden;
  display: flex; align-items: flex-start; gap: 12px;
  padding: 12px 14px;
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0, 0, 0, .06);
  border-radius: 12px;
  animation: awSlideUp .35s cubic-bezier(.16, 1, .3, 1) both;
}
.pdr-node-shine { position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 20%, rgba(255, 255, 255, .85) 50%, transparent 80%); }
.pdr-node--blue   { border-color: rgba(0, 112, 224, .18); }
.pdr-node--violet { border-color: rgba(123, 64, 216, .18); }
.pdr-node--green  { border-color: rgba(0, 168, 107, .2); }
.pdr-node--amber  { border-color: rgba(204, 136, 0, .2); }
.pdr-node--rose   { border-color: rgba(224, 32, 80, .2); }
.pdr-node-icon {
  width: 38px; height: 38px; flex-shrink: 0;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 16px; font-weight: 700;
  border: 1px solid;
}
.pdr-node-icon--blue   { background: linear-gradient(135deg, #E0ECFF, #CCE0FF); color: #0070E0; border-color: rgba(0, 112, 224, .15); }
.pdr-node-icon--violet { background: linear-gradient(135deg, #F5F3FF, #EDE9FE); color: #7B40D8; border-color: rgba(123, 64, 216, .15); }
.pdr-node-icon--green  { background: linear-gradient(135deg, #ECFDF5, #D1FAE5); color: #00A86B; border-color: rgba(0, 168, 107, .18); }
.pdr-node-icon--amber  { background: linear-gradient(135deg, #FFFBEB, #FEF3C7); color: #CC8800; border-color: rgba(204, 136, 0, .18); }
.pdr-node-icon--rose   { background: linear-gradient(135deg, #FEF2F2, #FECACA); color: #E02050; border-color: rgba(224, 32, 80, .18); }
.pdr-node-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.pdr-node-key { font-size: 8px; font-weight: 800; color: #94A3B8; letter-spacing: .8px; text-transform: uppercase; font-family: 'DM Sans', sans-serif; }
.pdr-node-val { font-size: 14px; font-weight: 700; color: #0B1A30; font-family: 'DM Sans', sans-serif; line-height: 1.3; word-break: break-word; }
.pdr-node-sub { font-size: 11px; color: #475569; margin-top: 2px; line-height: 1.4; word-break: break-word; }
.pdr-node-tail {
  flex-shrink: 0; align-self: center;
  font-size: 10px; font-weight: 700; color: #94A3B8;
  font-family: 'JetBrains Mono', monospace;
  padding: 4px 8px; border-radius: 6px;
  background: rgba(0, 0, 0, .04);
}

/* Country travel strip */
.pdr-country-strip { display: grid; grid-template-columns: repeat(auto-fill, minmax(80px, 1fr)); gap: 8px; }
.pdr-country {
  position: relative;
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 12px 8px;
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0, 112, 224, .12);
  border-radius: 12px; gap: 4px;
  animation: awSlideUp .35s cubic-bezier(.16, 1, .3, 1) both;
}
.pdr-country::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 20%, rgba(255, 255, 255, .9) 50%, transparent 80%); }
.pdr-country-code { font-size: 16px; font-weight: 900; color: #0B1A30; font-family: 'Syne', sans-serif; letter-spacing: -.02em; }
.pdr-country-days { font-size: 11px; font-weight: 700; color: #0070E0; font-family: 'JetBrains Mono', monospace; }
.pdr-country-days small { font-size: 9px; opacity: .7; margin-left: 1px; }
.pdr-country-role { font-size: 8px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; background: rgba(112, 68, 255, .12); color: #5b21b6; border-radius: 4px; padding: 1px 4px; }

/* Operations grid (Journey, Officer Assessment, Risk Scoring) */
.pdr-ops-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
@media (min-width: 480px) { .pdr-ops-grid { grid-template-columns: repeat(3, 1fr); } }
.pdr-ops {
  position: relative; overflow: hidden;
  padding: 12px 14px; border-radius: 12px;
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0, 0, 0, .06);
  animation: awSlideUp .35s cubic-bezier(.16, 1, .3, 1) both;
  display: flex; flex-direction: column; gap: 3px;
}
.pdr-ops-shine { position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 20%, rgba(255, 255, 255, .85) 50%, transparent 80%); }
.pdr-ops--wide  { grid-column: 1 / -1; }
.pdr-ops--alert { background: linear-gradient(135deg, #FEF2F2, #FECACA); border-color: rgba(224, 32, 80, .22); }
.pdr-ops--info  { background: linear-gradient(135deg, #E0ECFF, #CCE0FF); border-color: rgba(0, 112, 224, .2); }
.pdr-ops-key { font-size: 8px; font-weight: 800; color: #94A3B8; letter-spacing: .7px; text-transform: uppercase; font-family: 'DM Sans', sans-serif; }
.pdr-ops--alert .pdr-ops-key { color: #951536; }
.pdr-ops--info  .pdr-ops-key { color: #0070E0; }
.pdr-ops-val { font-size: 13px; font-weight: 800; color: #0B1A30; font-family: 'DM Sans', sans-serif; line-height: 1.3; }
.pdr-ops-val--small { font-size: 11px; font-weight: 600; }
.pdr-ops--alert .pdr-ops-val { color: #951536; }
.pdr-ops-sub { font-size: 10px; color: #94A3B8; line-height: 1.4; }
.pdr-mono { font-family: 'JetBrains Mono', monospace; }

/* Officer notes tip card */
.pdr-tip {
  position: relative; overflow: hidden;
  margin-top: 10px; padding: 14px 16px;
  background: linear-gradient(135deg, #ECFDF5, #D1FAE5);
  border: 1.5px solid rgba(0, 168, 107, .2);
  border-radius: 14px;
  animation: awSlideUp .35s cubic-bezier(.16, 1, .3, 1) both;
}
.pdr-tip-shine { position: absolute; top: 0; left: 0; right: 0; height: 1px; background: linear-gradient(90deg, transparent 20%, rgba(255, 255, 255, .8) 50%, transparent 80%); }
.pdr-tip--green .pdr-tip-icon { color: #00A86B; }
.pdr-tip-hdr {
  display: flex; align-items: center; gap: 8px; margin-bottom: 8px;
  font-size: 9px; font-weight: 800; color: #00A86B;
  letter-spacing: .8px; text-transform: uppercase;
  font-family: 'DM Sans', sans-serif;
}
.pdr-tip-icon { font-size: 16px; line-height: 1; }
.pdr-tip-text { font-size: 13px; font-weight: 600; color: #007A50; line-height: 1.55; font-family: 'DM Sans', sans-serif; }

/* Reference table (encounter metadata) */
.pdr-ref {
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0, 0, 0, .06);
  border-radius: 14px; overflow: hidden;
  position: relative;
  animation: awSlideUp .4s cubic-bezier(.16, 1, .3, 1) both;
}
.pdr-ref::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent 20%, rgba(255, 255, 255, .9) 50%, transparent 80%);
}
.pdr-ref-row {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; padding: 11px 14px;
  border-bottom: 1px solid rgba(0, 0, 0, .05);
}
.pdr-ref-row:last-child { border-bottom: none; }
.pdr-ref-key {
  font-size: 9px; font-weight: 700; color: #94A3B8;
  letter-spacing: .8px; text-transform: uppercase;
  font-family: 'DM Sans', sans-serif; flex-shrink: 0;
}
.pdr-ref-val {
  font-size: 12px; font-weight: 600; color: #475569;
  text-align: right; font-family: 'DM Sans', sans-serif;
  word-break: break-word;
}
.pdr-ref-val--code {
  font-family: 'JetBrains Mono', monospace; font-size: 11px;
  color: #0070E0;
  background: linear-gradient(135deg, #E0ECFF, #CCE0FF);
  padding: 2px 7px; border-radius: 4px;
}
.pdr-ref-val--id {
  font-family: 'JetBrains Mono', monospace; font-size: 10px;
  color: #94A3B8;
}

/* ── Global HTTP loading overlay ── */
.awr-http-overlay { position: fixed; top: 0; left: 0; right: 0; z-index: 9999; pointer-events: none; }
.awr-http-bar { height: 3px; background: rgba(0,180,255,.15); overflow: hidden; }
.awr-http-progress {
  height: 100%;
  background: linear-gradient(90deg, #00B4FF 0%, #0070E0 50%, #00B4FF 100%);
  background-size: 200% 100%;
  animation: awr-slide 1.2s linear infinite;
}
@keyframes awr-slide {
  0%   { background-position: 200% 0; }
  100% { background-position: -200% 0; }
}

</style>
