/**
 * services/plainLabels.js — single source of truth for plain-language
 * labels used across every dashboard, modal, and badge in the app.
 *
 * Why this exists:
 *   The codebase persists short uppercase enum keys (`SYNCED`, `OPEN`,
 *   `CRITICAL`, `QUARANTINED`, …) directly in IDB and the API. Showing
 *   those raw keys to a non-technical user is the audit's #1 confusion
 *   finding. Every view that surfaces an enum value should pipe it
 *   through one of the maps below so a screener with basic English
 *   reads "Waiting" instead of "OPEN" and "Held alone for medical
 *   reasons" instead of "ISOLATED".
 *
 *   The maps are intentionally NOT identity maps — they intentionally
 *   *replace* the developer-facing enum with a sentence-case word a
 *   non-clinician understands. Where the original term is medically
 *   important (Quarantined, Isolated) we keep the clinical word but
 *   add an in-place explainer so the badge body stays short and the
 *   tooltip / sub-line carries the meaning.
 *
 *   To avoid double labelling, ALWAYS pass the raw enum through the
 *   `*_LABEL` map BEFORE rendering. Don't string-format in templates.
 *
 * Conventions:
 *   - Returns the original key when the lookup misses (defensive — a
 *     stale or new server enum still renders, instead of `undefined`).
 *   - Lower-case keys are tolerated; the maps key on UPPER.
 *   - No HTML, just strings — callers decide weight/colour/icon.
 */

// ─── Sync state ─────────────────────────────────────────────────────────────
// Used in: AggregatedHistory, SyncManagement, NotificationsCenter,
// PrimaryScreeningRecords, SecondaryRecords, every record-row badge.
const SYNC_LABEL_MAP = {
  SYNCED:      'Uploaded',
  UNSYNCED:    'Waiting to upload',
  PENDING:     'Waiting to upload',
  FAILED:      'Upload failed',
  QUARANTINED: 'Stuck — contact support',
  UNKNOWN:     'Status unknown',
}

// ─── Operational / lifecycle status ─────────────────────────────────────────
// Used in: notifications.status, secondary_screenings.case_status, alerts.status.
const STATUS_LABEL_MAP = {
  OPEN:           'Waiting',
  IN_PROGRESS:    'Being worked on',
  CLOSED:         'Done',
  ACKNOWLEDGED:   'Seen by supervisor',
  DISPOSITIONED:  'Decision made',
  // Disposition-specific — pulled from secondary case `disposition` columns
  RELEASED:       'Sent on their way',
  QUARANTINED:    'Held for observation',
  ISOLATED:       'Held alone (medical)',
  REFERRED:       'Sent to a clinic',
}

// ─── Priority (referrals) ───────────────────────────────────────────────────
// "Normal" reads as "ignore me" to a plain user, so we relabel.
const PRIORITY_LABEL_MAP = {
  NORMAL:   'Routine',
  HIGH:     'Urgent',
  CRITICAL: 'Emergency',
}

// ─── Risk level (alerts + secondary cases) ──────────────────────────────────
const RISK_LABEL_MAP = {
  LOW:      'Low',
  MEDIUM:   'Medium',
  HIGH:     'High',
  CRITICAL: 'Critical',
}

// ─── Operational dashboard status (HomePage status strip) ───────────────────
// Maps the API's terse enum to a sentence-case state word the user sees.
const OP_STATUS_LABEL_MAP = {
  NORMAL:      'Operating normally',
  OPERATIONAL: 'Operating normally',
  STANDBY:     'On standby',
  WARNING:     'Needs attention',
  CRITICAL:    'Needs urgent attention',
  OFFLINE:     'Offline',
  UNKNOWN:     'Status unknown',
}

function lookup(map, key) {
  if (key == null) return ''
  const k = String(key).toUpperCase()
  return map[k] ?? String(key)
}

export const syncLabel     = (k) => lookup(SYNC_LABEL_MAP, k)
export const statusLabel   = (k) => lookup(STATUS_LABEL_MAP, k)
export const priorityLabel = (k) => lookup(PRIORITY_LABEL_MAP, k)
export const riskLabel     = (k) => lookup(RISK_LABEL_MAP, k)
export const opStatusLabel = (k) => lookup(OP_STATUS_LABEL_MAP, k)

// ─── Threshold legends ──────────────────────────────────────────────────────
// Reused as the inline caption under colour-coded panels (Hero ring, KPI
// strips, weekly grid). Single source so a future tuning of the threshold
// updates every place that explains it.
export const THRESHOLDS = Object.freeze({
  symptomaticRate: {
    green:  10,   // % — under this is normal
    amber:  20,   // % — warning band
    // anything ≥ amber is red
    legend: 'Green: under 10% · Amber: 10–20% · Red: above 20%',
    explain: (pct) => {
      const n = Number(pct) || 0
      if (n < 10) return `Of every 100 travellers, about ${Math.round(n)} had symptoms — within normal range.`
      if (n < 20) return `Of every 100 travellers, about ${Math.round(n)} had symptoms — slightly above normal, watch the day's case mix.`
      return `Of every 100 travellers, about ${Math.round(n)} had symptoms — investigate today's case mix.`
    },
  },
  feverC: {
    threshold: 37.5,            // °C — IHR Annex 2 fever threshold
    high:      38.5,            // °C — high fever
    legend:    'Fever ≥ 37.5°C · High fever ≥ 38.5°C (WHO IHR 2005)',
  },
  notify247Pct: {
    target: 80,
    legend: 'Amber: less than 8 in 10 alerts hit the deadline · Red: a top-priority alert is open OR a deadline was missed.',
  },
})

// ─── Plain-language interpretation helpers ──────────────────────────────────
// One-liners the UI can put under a headline number so the user sees not
// just "21%" but "21 in every 100 travellers had symptoms — investigate".
export function interpretSymptomaticRate(pct) {
  return THRESHOLDS.symptomaticRate.explain(pct)
}
