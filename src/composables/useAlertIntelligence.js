/**
 * useAlertIntelligence.js — WHO / IHR 2005 / 7-1-7 hardcoded rules engine
 *
 * Operational logic for the Alert Intelligence Hub. Every rule in this file
 * traces to an authoritative source; change the rule ONLY if the underlying
 * framework changes.
 *
 * SOURCES:
 *   - WHO IHR (2005) Third Edition, Annex 2 decision instrument
 *   - Uganda IDSR Technical Guidelines (WHO AFRO-aligned)
 *   - Resolve to Save Lives / WHO 7-1-7 performance target
 *     (Frieden et al., Lancet 2021; 398:638-640)
 *   - WHO IHR Article 6 (24-hour notification) and Article 12 (PHEIC)
 *
 * This module is pure: no DOM, no Vue refs, no network. Import functions,
 * call them with alert/user data, get deterministic output. The view layer
 * is responsible for rendering.
 */

// ─────────────────────────────────────────────────────────────────────────
//  IHR TIER 1 — Single case = mandatory WHO notification (Annex 2, always)
// ─────────────────────────────────────────────────────────────────────────
export const IHR_TIER1 = Object.freeze({
  codes: ['smallpox', 'polio', 'wild_polio', 'novel_flu_subtype', 'sars'],
  names: ['Smallpox', 'Poliomyelitis (wild)', 'Novel influenza A subtype', 'SARS'],
  // Token matchers applied over alert_code + alert_title + syndrome + alert_details
  matchers: [
    /smallpox|variola/i,
    /wild[\s_-]?polio|wpv|poliomyel/i,
    /novel[\s_-]?influenza|new[\s_-]?flu[\s_-]?subtype|pandemic[\s_-]?flu|H[57]N\d/i,
    /\bSARS\b|severe[\s_-]?acute[\s_-]?respiratory[\s_-]?syndrome/i,
  ],
  reason: 'IHR 2005 Annex 2 — always notifiable on a single confirmed or probable case.',
})

// ─────────────────────────────────────────────────────────────────────────
//  IHR TIER 2 — Always require Annex 2 assessment (2-of-4 rule)
// ─────────────────────────────────────────────────────────────────────────
export const IHR_TIER2 = Object.freeze({
  names: [
    'Cholera', 'Pneumonic plague', 'Yellow fever',
    'Viral haemorrhagic fever (Ebola / Marburg / Lassa / CCHF)',
    'West Nile fever', 'Meningococcal disease',
    'MERS-CoV', 'SARS-CoV-2 / COVID-19', 'Mpox (clade I)', 'Rift Valley fever',
    'Dengue (severe/outbreak cluster)',
  ],
  matchers: [
    /cholera/i,
    /pneumonic[\s_-]?plague|yersinia.*plague/i,
    /yellow[\s_-]?fever|\bYF\b/i,
    /\bVHF\b|haemorrhagic|hemorrhagic|ebola|marburg|lassa|crimean|CCHF/i,
    /west[\s_-]?nile/i,
    /meningococc|meningitis/i,
    /MERS[\s_-]?CoV|mers/i,
    /COVID|SARS[\s_-]?CoV[\s_-]?2|novel[\s_-]?coronavirus/i,
    /mpox|monkeypox/i,
    /rift[\s_-]?valley/i,
    /\bdengue\b/i,
  ],
  reason: 'IHR 2005 Annex 2 — run the 4-criteria decision instrument. Notify WHO within 24h if ANY 2 of 4 are YES.',
})

// ─────────────────────────────────────────────────────────────────────────
//  7-1-7 — Pandemic preparedness performance targets
// ─────────────────────────────────────────────────────────────────────────
export const SLA_717 = Object.freeze({
  detect_hrs:  7 * 24,   // 168h — emergence → detection
  notify_hrs:  24,       // 1d   — detection → notification / first action
  respond_hrs: 7 * 24,   // 168h — detection → early response (14 RTSL actions)
  source: 'Resolve to Save Lives / WHO — Frieden et al., Lancet 2021; 398:638-640.',
})

// ─────────────────────────────────────────────────────────────────────────
//  IDSR / IHR escalation timing targets (hours)
// ─────────────────────────────────────────────────────────────────────────
export const ESCALATION = Object.freeze({
  poe_to_district_hrs:     2,   // Immediately notifiable (phone) — IDSR 3rd Ed. §2
  district_to_national_hrs: 24, // National PHEOC
  national_to_who_hrs:      24, // IHR Article 6
  acknowledge_critical_hrs: 4,  // operational: CRITICAL = ack within 4h
  acknowledge_high_hrs:     24,
  acknowledge_medium_hrs:   48,
})

// ─────────────────────────────────────────────────────────────────────────
//  Role-based geographic scope matrix
//  MUST stay consistent with server-side checkScope() in AlertsController.
// ─────────────────────────────────────────────────────────────────────────
export const ROLE_SCOPE = Object.freeze({
  NATIONAL_ADMIN:       { kind: 'COUNTRY',  label: 'National',  scopeKey: 'country_code' },
  PHEOC_OFFICER:        { kind: 'PHEOC',    label: 'PHEOC',     scopeKey: 'pheoc_code' },
  DISTRICT_SUPERVISOR:  { kind: 'DISTRICT', label: 'District',  scopeKey: 'district_code' },
  POE_PRIMARY:          { kind: 'POE',      label: 'POE',       scopeKey: 'poe_code' },
  POE_SECONDARY:        { kind: 'POE',      label: 'POE',       scopeKey: 'poe_code' },
  POE_DATA_OFFICER:     { kind: 'POE',      label: 'POE',       scopeKey: 'poe_code' },
  POE_ADMIN:            { kind: 'POE',      label: 'POE',       scopeKey: 'poe_code' },
  SCREENER:             { kind: 'POE',      label: 'POE',       scopeKey: 'poe_code' },
})

// Roles allowed to acknowledge/close at each routing level (must match
// AlertsController::ACKNOWLEDGE_ROLES exactly)
export const ACK_ROLES = Object.freeze({
  DISTRICT: ['DISTRICT_SUPERVISOR', 'PHEOC_OFFICER', 'NATIONAL_ADMIN'],
  PHEOC:    ['PHEOC_OFFICER', 'NATIONAL_ADMIN'],
  NATIONAL: ['NATIONAL_ADMIN'],
})

// ─────────────────────────────────────────────────────────────────────────
//  PHEIC — Article 12 criteria (for advisory guidance only; actual declaration
//  is by the WHO Director-General).
// ─────────────────────────────────────────────────────────────────────────
export const PHEIC_CRITERIA = Object.freeze([
  { key: 'extraordinary',          label: 'Extraordinary event' },
  { key: 'international_risk',     label: 'Risk to other States through international spread' },
  { key: 'coordinated_response',   label: 'Potentially requires coordinated international response' },
])

// ─────────────────────────────────────────────────────────────────────────
//  ENGINE FUNCTIONS
// ─────────────────────────────────────────────────────────────────────────

function joinAlertTokens(a) {
  return [
    a?.alert_code, a?.alert_title, a?.alert_details,
    a?.syndrome, a?.top_suspected_disease?.disease_code,
    a?.top_suspected_disease?.disease_name, a?.ihr_tier,
  ].filter(Boolean).join(' ')
}

/**
 * Classify an alert against IHR Tier 1 / 2.
 * @returns {{ tier: 1|2|null, name: string|null, reason: string|null }}
 */
export function classifyIHRTier(alert) {
  if (!alert) return { tier: null, name: null, reason: null }
  const blob = joinAlertTokens(alert)
  // The server already sets ihr_tier for NATIONAL routing — trust it first
  if (typeof alert.ihr_tier === 'string') {
    if (alert.ihr_tier.includes('TIER_1')) {
      return { tier: 1, name: IHR_TIER1.names[0] || 'Tier 1', reason: IHR_TIER1.reason }
    }
    if (alert.ihr_tier.includes('TIER_2')) {
      const m = IHR_TIER2.matchers.findIndex(r => r.test(blob))
      return { tier: 2, name: m >= 0 ? IHR_TIER2.names[m] : 'Tier 2 event', reason: IHR_TIER2.reason }
    }
  }
  for (let i = 0; i < IHR_TIER1.matchers.length; i++) {
    if (IHR_TIER1.matchers[i].test(blob)) {
      return { tier: 1, name: IHR_TIER1.names[i], reason: IHR_TIER1.reason }
    }
  }
  for (let i = 0; i < IHR_TIER2.matchers.length; i++) {
    if (IHR_TIER2.matchers[i].test(blob)) {
      return { tier: 2, name: IHR_TIER2.names[i], reason: IHR_TIER2.reason }
    }
  }
  return { tier: null, name: null, reason: null }
}

/**
 * Heuristic Annex 2 assessment — marks a criterion YES where the alert
 * signal is strong enough. Never replaces expert judgment; it is a prompt
 * for the user to confirm.
 *
 * @returns {{ yes: number, total: 4, details: Array, meetsThreshold: boolean, summary: string }}
 */
export function assessAnnex2(alert) {
  const details = []
  if (!alert) return { yes: 0, total: 4, details, meetsThreshold: false, summary: 'No alert supplied.' }

  const tier = classifyIHRTier(alert).tier
  const crit = alert.risk_level === 'CRITICAL'
  const high = alert.risk_level === 'HIGH'
  const syn  = String(alert.syndrome || '').toUpperCase()

  // 1. Serious public health impact
  const seriousYes =
    tier === 1 ||
    crit ||
    syn.includes('VHF') || syn.includes('CHOLERA') || syn.includes('MENINGIT')
  details.push({
    key: 'serious',
    label: 'Is the public health impact serious?',
    yes: !!seriousYes,
    basis: tier === 1 ? 'IHR Tier 1 always-notifiable disease.'
      : crit ? 'Alert risk level classified CRITICAL.'
      : syn ? `Syndrome classification "${syn.replace(/_/g, ' ')}" is severity-indicative.`
      : 'No automatic basis — require officer judgment.',
  })

  // 2. Unusual or unexpected
  const unusualYes =
    alert.generated_from === 'OFFICER' ||
    tier === 1 ||
    /outbreak|cluster|unusual|novel/i.test(alert.alert_details || '')
  details.push({
    key: 'unusual',
    label: 'Is the event unusual or unexpected?',
    yes: !!unusualYes,
    basis: alert.generated_from === 'OFFICER' ? 'Raised by an officer (non-rule event).'
      : tier === 1 ? 'Tier 1 events are always deemed unusual.'
      : 'Keyword match in alert details (cluster/outbreak/novel).',
  })

  // 3. Risk of international spread — always YES for POE alerts (by definition the traveler crossed a border)
  details.push({
    key: 'international_spread',
    label: 'Is there significant risk of international spread?',
    yes: true,
    basis: 'Alert raised at a Point of Entry — traveler by definition represents cross-border transmission risk.',
  })

  // 4. Risk of international travel/trade restrictions
  const tradeYes =
    tier === 1 ||
    (tier === 2 && (crit || high)) ||
    alert.routed_to_level === 'NATIONAL'
  details.push({
    key: 'trade_risk',
    label: 'Is there significant risk of international travel or trade restrictions?',
    yes: !!tradeYes,
    basis: tier === 1 ? 'Tier 1 events systematically trigger travel/trade advisories.'
      : (tier === 2 && (crit || high)) ? 'Tier 2 event at HIGH/CRITICAL severity.'
      : alert.routed_to_level === 'NATIONAL' ? 'National-level routing indicates potential trade impact.'
      : 'No strong automatic basis.',
  })

  const yes = details.filter(d => d.yes).length
  const meetsThreshold = yes >= 2
  const summary = meetsThreshold
    ? `${yes} of 4 criteria YES — WHO notification required within 24 hours via the National IHR Focal Point (IHR Art. 6).`
    : `${yes} of 4 criteria YES — below notification threshold. Monitor and re-assess as new information arrives.`

  return { yes, total: 4, details, meetsThreshold, summary }
}

/**
 * 7-1-7 scorecard for an alert.
 *
 * Framework (Resolve to Save Lives / WHO):
 *   7d detect  —  emergence to detection
 *   1d notify  —  detection to notification
 *   7d respond —  notification to early response actions
 *
 * Fields used:
 *   created_at       → proxy for "detection" (when alert was raised)
 *   acknowledged_at  → proxy for "notification / investigation start"
 *   closed_at        → proxy for "early response actions completed"
 *
 * @returns {{
 *   detect:  { hrs: number|null, target: number, on_target: boolean, label: string },
 *   notify:  { hrs: number|null, target: number, on_target: boolean, label: string },
 *   respond: { hrs: number|null, target: number, on_target: boolean, label: string },
 *   bottleneck: 'DETECT' | 'NOTIFY' | 'RESPOND' | null,
 *   overall: 'ON_TARGET' | 'AT_RISK' | 'BREACH'
 * }}
 */
export function evaluate717(alert) {
  const now = Date.now()
  const ts = v => v ? new Date(String(v).replace(' ', 'T')).getTime() : null
  const hrs = (from, to) => (from && to) ? Math.max(0, (to - from) / 3.6e6) : null

  const created = ts(alert?.created_at)
  const acked   = ts(alert?.acknowledged_at)
  const closed  = ts(alert?.closed_at)

  // Detect: best proxy = hours_since_creation (the server derives this)
  const detect_hrs = typeof alert?.hours_since_creation === 'number'
    ? alert.hours_since_creation
    : (created ? hrs(created, now) : null)

  // Notify: created → acknowledged  (or open time if not acked yet)
  const notify_hrs = acked
    ? hrs(created, acked)
    : (created ? hrs(created, now) : null)

  // Respond: created → closed  (only meaningful for closed alerts)
  const respond_hrs = closed
    ? hrs(created, closed)
    : (created ? hrs(created, now) : null)

  const detect  = { hrs: detect_hrs,  target: SLA_717.detect_hrs,  on_target: detect_hrs  != null && detect_hrs  <= SLA_717.detect_hrs,  label: '7 days · detect' }
  const notify  = { hrs: notify_hrs,  target: SLA_717.notify_hrs,  on_target: notify_hrs  != null && notify_hrs  <= SLA_717.notify_hrs,  label: '1 day · notify' }
  const respond = { hrs: respond_hrs, target: SLA_717.respond_hrs, on_target: closed ? (respond_hrs <= SLA_717.respond_hrs) : null,      label: '7 days · respond' }

  // Bottleneck = first failed stage
  let bottleneck = null
  if (!detect.on_target)  bottleneck = 'DETECT'
  else if (!notify.on_target) bottleneck = 'NOTIFY'
  else if (respond.on_target === false) bottleneck = 'RESPOND'

  let overall = 'ON_TARGET'
  if (bottleneck) overall = 'BREACH'
  else if (alert?.status === 'OPEN' && notify.hrs != null && notify.hrs > SLA_717.notify_hrs * 0.75) overall = 'AT_RISK'

  return { detect, notify, respond, bottleneck, overall }
}

/**
 * Next recommended escalation hop, based on routing level and risk.
 */
export function nextEscalation(alert) {
  if (!alert || alert.status === 'CLOSED') {
    return { target: null, within_hrs: null, note: 'No escalation — alert is closed.' }
  }
  const r = alert.routed_to_level
  const risk = alert.risk_level
  const ack = alert.status === 'ACKNOWLEDGED'

  if (r === 'DISTRICT') {
    if (!ack || risk === 'CRITICAL') {
      return { target: 'PHEOC', within_hrs: risk === 'CRITICAL' ? 2 : ESCALATION.acknowledge_critical_hrs,
        note: `Escalate to PHEOC within ${risk === 'CRITICAL' ? 2 : 4}h if not acknowledged.` }
    }
    return { target: 'CLOSE', within_hrs: 24, note: 'Complete response actions and close.' }
  }
  if (r === 'PHEOC') {
    if (risk === 'CRITICAL' && !ack) {
      return { target: 'NATIONAL', within_hrs: 2, note: 'CRITICAL unacknowledged at PHEOC — escalate to National PHEOC immediately.' }
    }
    return { target: 'CLOSE', within_hrs: 24, note: 'Coordinate response; complete and close within 24h.' }
  }
  if (r === 'NATIONAL') {
    return { target: 'WHO', within_hrs: ESCALATION.national_to_who_hrs,
      note: 'If Tier 1 or Annex 2 2-of-4 met, notify WHO via IHR Focal Point within 24h.' }
  }
  return { target: null, within_hrs: null, note: '—' }
}

/**
 * Determine whether the current user may act on the alert (acknowledge/close)
 * using the ACK_ROLES matrix. Mirrors server-side enforcement exactly.
 */
export function canActOnAlert(user, alert) {
  if (!user || !alert) return false
  const role = user.role_key || ''
  return (ACK_ROLES[alert.routed_to_level] || []).includes(role)
}

/**
 * User's geographic scope descriptor (for showing the scope badge at the top
 * of the hub). Does NOT filter server-returned alerts — the server already
 * enforces scope. This is a display-only summary.
 */
export function userScope(user) {
  const role = user?.role_key || ''
  const s = ROLE_SCOPE[role]
  if (!s) return { kind: 'UNKNOWN', label: 'Limited scope', code: null, role }
  const code = (
    s.kind === 'COUNTRY'  ? user.country_code :
    s.kind === 'PHEOC'    ? user.pheoc_code   :
    s.kind === 'DISTRICT' ? user.district_code :
                            user.poe_code
  ) || null
  return { kind: s.kind, label: s.label, code, role }
}

// ─────────────────────────────────────────────────────────────────────────
//  AGGREGATE ANALYTICS — operate over an alert array
// ─────────────────────────────────────────────────────────────────────────

/**
 * Risk-level distribution.
 */
export function riskDistribution(alerts) {
  const bucket = { CRITICAL: 0, HIGH: 0, MEDIUM: 0, LOW: 0 }
  for (const a of (alerts || [])) {
    const r = a.risk_level || 'LOW'
    if (bucket[r] != null) bucket[r]++
  }
  return bucket
}

/**
 * Syndrome cloud — counts by syndrome classification.
 */
export function syndromeCloud(alerts) {
  const m = new Map()
  for (const a of (alerts || [])) {
    const s = (a.syndrome || 'UNCLASSIFIED').replace(/_/g, ' ')
    m.set(s, (m.get(s) || 0) + 1)
  }
  return Array.from(m.entries())
    .map(([k, v]) => ({ label: k, count: v }))
    .sort((a, b) => b.count - a.count)
}

/**
 * POE / district concentration — where are alerts concentrating?
 */
export function concentrationByGeo(alerts, key = 'poe_code') {
  const m = new Map()
  for (const a of (alerts || [])) {
    const k = a[key] || '—'
    m.set(k, (m.get(k) || 0) + 1)
  }
  return Array.from(m.entries())
    .map(([k, v]) => ({ label: k, count: v }))
    .sort((a, b) => b.count - a.count)
    .slice(0, 10)
}

/**
 * Bucketed response-time histogram.
 * @returns [{ label, count }]
 */
export function responseTimeHistogram(alerts) {
  const buckets = [
    { label: '≤ 1h',   cap: 1,    count: 0 },
    { label: '≤ 4h',   cap: 4,    count: 0 },
    { label: '≤ 24h',  cap: 24,   count: 0 },
    { label: '≤ 72h',  cap: 72,   count: 0 },
    { label: '> 72h',  cap: Infinity, count: 0 },
  ]
  for (const a of (alerts || [])) {
    if (!a.acknowledged_at || !a.created_at) continue
    const hrs = (new Date(String(a.acknowledged_at).replace(' ', 'T')).getTime()
                - new Date(String(a.created_at).replace(' ', 'T')).getTime()) / 3.6e6
    for (const b of buckets) { if (hrs <= b.cap) { b.count++; break } }
  }
  return buckets
}

/**
 * Escalation funnel — how many alerts at each routing level, and the
 * conversion (open → acknowledged → closed).
 */
export function escalationFunnel(alerts) {
  const by = { DISTRICT: {}, PHEOC: {}, NATIONAL: {} }
  for (const r of Object.keys(by)) by[r] = { open: 0, acked: 0, closed: 0 }
  for (const a of (alerts || [])) {
    const r = a.routed_to_level
    if (!by[r]) continue
    if (a.status === 'OPEN')          by[r].open++
    else if (a.status === 'ACKNOWLEDGED') by[r].acked++
    else if (a.status === 'CLOSED')       by[r].closed++
  }
  return by
}

/**
 * Generate the aggressive, WHO/IHR-anchored AI insights feed.
 * @param {Array} alerts
 * @param {Object} user
 * @returns {Array<{ id:string, level:'critical'|'high'|'medium'|'info', title:string, body:string, actions?:string[], cite?:string }>}
 */
export function generateIntelligenceInsights(alerts = [], user = {}) {
  const out = []
  const open       = alerts.filter(a => a.status === 'OPEN')
  const ackd       = alerts.filter(a => a.status === 'ACKNOWLEDGED')
  const crit       = open.filter(a => a.risk_level === 'CRITICAL')
  const highOpen   = open.filter(a => a.risk_level === 'HIGH')
  const overdue    = open.filter(a => a.overdue_24h)
  const national   = open.filter(a => a.routed_to_level === 'NATIONAL')
  const tier1      = alerts.filter(a => classifyIHRTier(a).tier === 1 && a.status !== 'CLOSED')
  const tier2      = alerts.filter(a => classifyIHRTier(a).tier === 2 && a.status !== 'CLOSED')

  // 1) Tier 1 surge — highest priority insight
  if (tier1.length > 0) {
    out.push({
      id: 'tier1',
      level: 'critical',
      title: `${tier1.length} IHR Tier 1 event${tier1.length > 1 ? 's' : ''} active`,
      body: 'Tier 1 diseases (smallpox, wild polio, novel flu subtype, SARS) require immediate WHO notification on a single case. Verify the chain of notification to the National IHR Focal Point and WHO within 24 hours.',
      actions: [
        'Acknowledge and document first-response actions now',
        'Notify National IHR Focal Point if not already done',
        'Confirm WHO has been notified within the 24h window (IHR Art. 6)',
      ],
      cite: 'IHR 2005 Annex 2 — Tier 1 always-notifiable diseases',
    })
  }

  // 2) Tier 2 — Annex 2 assessment required
  if (tier2.length > 0) {
    out.push({
      id: 'tier2',
      level: 'high',
      title: `${tier2.length} Tier 2 event${tier2.length > 1 ? 's' : ''} need Annex 2 assessment`,
      body: 'Run the 4-criteria decision instrument on each event: (1) serious, (2) unusual, (3) international spread risk, (4) trade/travel restriction risk. If any 2 are YES, notify WHO within 24 hours.',
      actions: [
        'Open each Tier 2 alert and review the Annex 2 scorecard',
        'Document the officer-confirmed YES/NO decisions in the case file',
      ],
      cite: 'IHR 2005 Annex 2 decision instrument',
    })
  }

  // 3) 7-1-7 bottleneck analysis
  const breaches = alerts.filter(a => evaluate717(a).overall === 'BREACH' && a.status !== 'CLOSED')
  if (breaches.length > 0) {
    const byPhase = { DETECT: 0, NOTIFY: 0, RESPOND: 0 }
    for (const a of breaches) {
      const b = evaluate717(a).bottleneck
      if (b) byPhase[b]++
    }
    const phase = Object.entries(byPhase).sort((a, b) => b[1] - a[1])[0]
    out.push({
      id: '717',
      level: 'high',
      title: `7-1-7 target breached on ${breaches.length} alert${breaches.length > 1 ? 's' : ''}`,
      body: `The ${phase[0].toLowerCase()} stage is the dominant bottleneck (${phase[1]} alert${phase[1] > 1 ? 's' : ''}). Conduct root-cause analysis per the RTSL / WHO 7-1-7 framework.`,
      actions: [
        'Escalate breaching alerts immediately',
        'Document the bottleneck cause (capacity, training, comms, lab, leadership)',
        'Add the corrective action to the next POE/District review meeting',
      ],
      cite: 'Resolve to Save Lives / WHO — Frieden et al., Lancet 2021',
    })
  }

  // 4) Critical surge
  if (crit.length >= 3) {
    out.push({
      id: 'critsurge',
      level: 'critical',
      title: `${crit.length} CRITICAL alerts open simultaneously`,
      body: 'Multiple CRITICAL-level alerts at one time is a surge signal. Consider activating POE emergency coordination and notifying district health office immediately.',
      actions: ['Activate POE emergency response protocol', 'Coordinate with district health office', 'Stand up daily situation reports'],
      cite: 'IDSR 3rd Ed. §2 — Outbreak response',
    })
  } else if (crit.length > 0) {
    out.push({
      id: 'crit',
      level: 'high',
      title: `${crit.length} CRITICAL alert${crit.length > 1 ? 's' : ''} require immediate acknowledgement`,
      body: `IHR Annex 2 response-time standards apply. CRITICAL-level alerts should be acknowledged within ${ESCALATION.acknowledge_critical_hrs}h.`,
      cite: 'Operational target',
    })
  }

  // 5) Overdue
  if (overdue.length >= 2) {
    out.push({
      id: 'overdue',
      level: 'high',
      title: `${overdue.length} alerts exceeded 24h without acknowledgement`,
      body: 'Alerts open for more than 24h without an acknowledgement signal a delay in the notification chain. Escalate to the next routing level.',
      actions: ['Escalate district → PHEOC; PHEOC → national', 'Document the delay cause in the audit log'],
      cite: 'IDSR notification SLA',
    })
  } else if (overdue.length === 1) {
    out.push({
      id: 'overdue1',
      level: 'medium',
      title: '1 alert overdue (>24h)',
      body: 'Review and either acknowledge or escalate.',
    })
  }

  // 6) High-open but no crit
  if (highOpen.length >= 3 && crit.length === 0) {
    out.push({
      id: 'highcluster',
      level: 'medium',
      title: `${highOpen.length} HIGH alerts concentrated`,
      body: 'Cluster of HIGH-severity alerts without a CRITICAL — verify whether a common exposure, vehicle or origin connects the cases.',
      actions: ['Cross-reference traveler origins', 'Review recent travel from known outbreak zones'],
    })
  }

  // 7) National cluster
  if (national.length >= 2) {
    out.push({
      id: 'natcluster',
      level: 'high',
      title: `${national.length} alerts routed to NATIONAL`,
      body: 'Two or more alerts simultaneously at national level typically indicates multi-POE coordination is needed. Verify IHR Focal Point workflow is active.',
      cite: 'IHR Art. 6 — national coordination',
    })
  }

  // 8) Concentration
  const poeHot = concentrationByGeo(alerts.filter(a => a.status !== 'CLOSED'), 'poe_code')
  if (poeHot.length > 0 && poeHot[0].count >= 3) {
    out.push({
      id: 'poehot',
      level: 'medium',
      title: `${poeHot[0].label} is the alert hotspot`,
      body: `${poeHot[0].count} active alerts concentrated at a single POE. Review surveillance capacity, lab referral chain, and staffing.`,
      actions: ['Check POE laboratory sample turnaround', 'Verify screening-to-case conversion rate at this POE'],
    })
  }

  // 9) All clear
  if (open.length === 0 && alerts.length > 0) {
    out.push({
      id: 'clear',
      level: 'info',
      title: 'All alerts resolved',
      body: 'No open alerts in your scope. Continue routine IDSR surveillance and weekly aggregated reporting.',
      cite: 'IDSR 3rd Ed. routine reporting',
    })
  }

  // 10) No data at all
  if (alerts.length === 0) {
    out.push({
      id: 'nodata',
      level: 'info',
      title: 'No alerts in your scope',
      body: 'Check connectivity and pull to refresh. Verify your role and geographic assignment in the user profile.',
    })
  }

  // 11) Pending acknowledgement by me
  const role = user?.role_key
  if (role) {
    const mine = open.filter(a => (ACK_ROLES[a.routed_to_level] || []).includes(role))
    if (mine.length > 0 && role !== 'SCREENER') {
      out.push({
        id: 'myqueue',
        level: 'medium',
        title: `${mine.length} alert${mine.length > 1 ? 's' : ''} awaiting YOUR action`,
        body: `As ${role.replace(/_/g, ' ').toLowerCase()}, these alerts are in your authorised acknowledgement queue.`,
      })
    }
  }

  return out
}

/**
 * 14 RTSL early response actions for 7-1-7 follow-up workflow.
 * Each action has a `code`, human `label`, and `blocks_closure` flag (true =
 * alert cannot close until this action is completed or marked NOT_APPLICABLE).
 */
export const RTSL_14_ACTIONS = Object.freeze([
  { code: 'CASE_INVESTIGATION',       label: 'Case investigation started',                            blocks_closure: true  },
  { code: 'ISOLATION',                label: 'Index case isolated / treatment initiated',             blocks_closure: true  },
  { code: 'CONTACT_LISTING',          label: 'Close contacts identified and listed',                  blocks_closure: true  },
  { code: 'CONTACT_TRACING',          label: 'Contact tracing and follow-up operational',             blocks_closure: true  },
  { code: 'LAB_SPECIMENS',            label: 'Laboratory specimens collected and transported',        blocks_closure: false },
  { code: 'LAB_CONFIRMATION',         label: 'Laboratory confirmation obtained',                      blocks_closure: false },
  { code: 'LINE_LIST',                label: 'Epidemiological line list maintained',                  blocks_closure: false },
  { code: 'RISK_COMMS',               label: 'Risk communication to the public initiated',            blocks_closure: false },
  { code: 'IPC',                      label: 'Infection prevention & control (IPC) in facilities',    blocks_closure: false },
  { code: 'VECTOR_CONTROL',           label: 'Vector control measures (if applicable)',               blocks_closure: false },
  { code: 'POE_SURVEILLANCE',         label: 'Cross-border / POE surveillance strengthened',          blocks_closure: false },
  { code: 'EOC_ACTIVATION',           label: 'Coordination structure activated (EOC / PHEOC)',        blocks_closure: true  },
  { code: 'RESOURCE_MOBILISATION',    label: 'Response resources mobilised',                          blocks_closure: false },
  { code: 'WHO_NOTIFICATION',         label: 'WHO and partners notified per IHR Article 6',           blocks_closure: true  },
])

/**
 * For a Tier-1/Tier-2 alert, which RTSL actions should be pre-seeded as PENDING?
 * Returns an array of { action_code, action_label, blocks_closure, due_at_offset_hrs }
 *
 * Hours offset relative to alert.created_at — the due_at is computed by the
 * caller (so we can keep this function pure).
 */
export function recommendedFollowups(alert) {
  const tier = classifyIHRTier(alert).tier
  const risk = alert?.risk_level
  const out = []
  for (const a of RTSL_14_ACTIONS) {
    let due = null
    if (['CASE_INVESTIGATION', 'ISOLATION'].includes(a.code)) due = 4           // 4h
    else if (['CONTACT_LISTING', 'CONTACT_TRACING'].includes(a.code)) due = 24
    else if (['LAB_SPECIMENS', 'LAB_CONFIRMATION'].includes(a.code)) due = 48
    else if (['RISK_COMMS', 'IPC', 'VECTOR_CONTROL'].includes(a.code)) due = 72
    else if (a.code === 'EOC_ACTIVATION') due = risk === 'CRITICAL' ? 4 : 24
    else if (a.code === 'WHO_NOTIFICATION') due = tier === 1 ? 24 : 24  // 24h for Tier 1 or Annex 2 2-of-4
    else due = 168  // default: within 7-day response window
    out.push({ ...a, due_at_offset_hrs: due })
  }
  return out
}

/**
 * Compliance rollup for a list of alerts.
 *
 * IMPORTANT: this function enforces "compute only what the data supports".
 * Each metric returns { computable, value | reason }. This is critical for
 * IHR-compliant reporting — we never fabricate timings.
 */
export function compliance717Summary(alerts = []) {
  const now = Date.now()
  const ts  = v => v ? new Date(String(v).replace(' ', 'T')).getTime() : null

  // Counts
  const total    = alerts.length
  const open     = alerts.filter(a => a.status === 'OPEN').length
  const acked    = alerts.filter(a => a.status === 'ACKNOWLEDGED').length
  const closed   = alerts.filter(a => a.status === 'CLOSED').length

  // Notify: 24h SLA — measure over alerts that have been acknowledged
  const ackedAlerts = alerts.filter(a => a.acknowledged_at && a.created_at)
  const notifyOn    = ackedAlerts.filter(a => ((ts(a.acknowledged_at) - ts(a.created_at)) / 3.6e6) <= SLA_717.notify_hrs).length
  const notifyBrk   = ackedAlerts.length - notifyOn
  const notifyPend  = alerts.filter(a => a.status === 'OPEN' && a.created_at).length

  // Respond: 168h SLA — measure over closed alerts
  const closedAlerts = alerts.filter(a => a.closed_at && a.created_at)
  const respondOn    = closedAlerts.filter(a => ((ts(a.closed_at) - ts(a.created_at)) / 3.6e6) <= SLA_717.respond_hrs).length
  const respondBrk   = closedAlerts.length - respondOn
  const respondOpen  = alerts.filter(a => a.status !== 'CLOSED' && a.created_at && ((now - ts(a.created_at)) / 3.6e6) > SLA_717.respond_hrs).length

  // IHR tier tally
  const tier1 = alerts.filter(a => classifyIHRTier(a).tier === 1).length
  const tier2 = alerts.filter(a => classifyIHRTier(a).tier === 2).length
  const annex2Hits = alerts.filter(a => classifyIHRTier(a).tier === 2 && assessAnnex2(a).meetsThreshold).length

  // Breach ledger
  const breaches = alerts.filter(a => evaluate717(a).overall === 'BREACH')
  const breachByPhase = { DETECT: 0, NOTIFY: 0, RESPOND: 0 }
  for (const a of breaches) {
    const p = evaluate717(a).bottleneck
    if (p && breachByPhase[p] != null) breachByPhase[p]++
  }

  // On-target rates (percentages)
  const pct = (num, den) => den > 0 ? Math.round(num / den * 100) : null
  const notifyRate  = pct(notifyOn, ackedAlerts.length)
  const respondRate = pct(respondOn, closedAlerts.length)

  return {
    counts: { total, open, acked, closed },
    detect: {
      computable: false,
      reason: 'Detection hours (emergence → detection) require a symptom-onset or emergence timestamp that is not captured for every alert source. Compliance for Detect is governed by surveillance system design (IDSR) rather than alert metadata.',
      hint:   'To enable, capture `symptom_onset_at` or `exposure_start_date` on the linked screening and expose on the alert.',
    },
    notify: {
      computable: true,
      target_hours: SLA_717.notify_hrs,
      on_target: notifyOn,
      breach:    notifyBrk,
      pending:   notifyPend,
      rate_pct:  notifyRate,  // of acknowledged alerts, what % within 24h
    },
    respond: {
      computable: true,
      target_hours: SLA_717.respond_hrs,
      on_target: respondOn,
      breach:    respondBrk,
      open_overdue: respondOpen,
      rate_pct:  respondRate, // of closed alerts, what % within 168h
    },
    ihr: { tier1, tier2, annex2_threshold_hits: annex2Hits },
    breaches: { total: breaches.length, by_phase: breachByPhase },
  }
}

/**
 * Aggregate follow-up statistics across a list of followups.
 */
export function followupSummary(followups = []) {
  const now = Date.now()
  const ts = v => v ? new Date(String(v).replace(' ', 'T')).getTime() : null

  let pending = 0, inProgress = 0, completed = 0, blocked = 0, na = 0, overdue = 0
  for (const f of followups) {
    switch (f.status) {
      case 'PENDING':     pending++; break
      case 'IN_PROGRESS': inProgress++; break
      case 'COMPLETED':   completed++; break
      case 'BLOCKED':     blocked++; break
      case 'NOT_APPLICABLE': na++; break
    }
    if (f.status !== 'COMPLETED' && f.status !== 'NOT_APPLICABLE' && f.due_at) {
      const due = ts(f.due_at)
      if (due != null && now > due) overdue++
    }
  }
  const actionable = pending + inProgress + blocked
  const total = followups.length
  const completion_pct = total > 0 ? Math.round((completed + na) / total * 100) : 0
  return { total, pending, inProgress, completed, blocked, na, overdue, actionable, completion_pct }
}

export default {
  IHR_TIER1, IHR_TIER2, SLA_717, ESCALATION, ROLE_SCOPE, ACK_ROLES, PHEIC_CRITERIA,
  RTSL_14_ACTIONS,
  classifyIHRTier, assessAnnex2, evaluate717, nextEscalation, canActOnAlert, userScope,
  recommendedFollowups, compliance717Summary, followupSummary,
  riskDistribution, syndromeCloud, concentrationByGeo,
  responseTimeHistogram, escalationFunnel, generateIntelligenceInsights,
}
