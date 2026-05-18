/**
 * useIntelligenceAI.js — ECSA-HC POE Screening
 * ══════════════════════════════════════════════════════════════════════
 * WHO/IHR-grade AI surveillance signals (hardcoded clinical logic, no
 * external APIs) and PDF report generation using jsPDF.
 *
 * PDF generation uses jsPDF directly (no html2canvas) so it works in
 * Capacitor Android/iOS, PWA, and web. Uses Blob + object URL for
 * download — compatible with WebView file handling.
 * ══════════════════════════════════════════════════════════════════════
 */

import { computed } from 'vue'
import { GENDER_FULL } from './useIntelligenceData'

// ─── WHO/IHR CLINICAL THRESHOLDS ─────────────────────────────────────────────
const IHR_SYMP_RATE_CRITICAL = 30  // %  — IHR Annex 2 trigger
const IHR_SYMP_RATE_HIGH     = 20  // %  — enhanced surveillance
const IHR_SYMP_RATE_ELEVATED = 10  // %  — monitoring threshold
const FEVER_CLUSTER_PCT      = 15  // %  — febrile illness cluster signal
const REFERRAL_BACKLOG_WARN  = 3   // count
const REFERRAL_BACKLOG_HIGH  = 5   // count
const IHR_PICKUP_SLA_MIN     = 30  // minutes — Art.23 response time
const SURGE_PCT_THRESHOLD    = 80  // % increase vs yesterday
const DROP_PCT_THRESHOLD     = 50  // % decrease vs yesterday

// ─── AI COMPOSABLE ───────────────────────────────────────────────────────────
export function useIntelligenceAI({ sum, sr, delta, fun, weekly, epi, alertsData, devices, officers }) {

  const signals = computed(() => {
    const s = sum.value
    if (!s) return []
    const out = []
    const rate = sr.value
    const t = s.today?.total || 0
    const feverToday = s.today?.fever_count || 0
    const highFever = s.today?.high_fever_count || 0

    // ── 1. SYMPTOMATIC RATE ANALYSIS (WHO IHR Annex 2) ──────────────
    if (rate >= IHR_SYMP_RATE_CRITICAL) {
      out.push({
        level: 'critical', ico: '!!', category: 'IHR ANNEX 2',
        title: 'Critical Symptomatic Rate',
        desc: `${rate}% of ${t} travelers screened today are symptomatic — exceeds the ${IHR_SYMP_RATE_CRITICAL}% IHR Annex 2 threshold. ` +
          `This triggers the IHR 2005 decision instrument assessment. The national IHR focal point should be notified. ` +
          `Consider enhanced screening measures, increased PPE levels, and activation of the POE public health emergency contingency plan.`,
        action: 'Notify IHR Focal Point. Activate POE contingency plan. Increase PPE.',
      })
    } else if (rate >= IHR_SYMP_RATE_HIGH) {
      out.push({
        level: 'high', ico: '!', category: 'SURVEILLANCE',
        title: 'High Symptomatic Rate',
        desc: `${rate}% symptomatic rate (${s.today?.symptomatic || 0} of ${t}) — significantly above the 10% baseline. ` +
          `Review syndrome classification patterns from secondary screening and verify the referral pipeline has capacity. ` +
          `Cross-reference with syndromic surveillance data from neighboring POEs and district health facilities.`,
        action: 'Review syndrome patterns. Verify referral capacity. Cross-reference with district data.',
      })
    } else if (rate >= IHR_SYMP_RATE_ELEVATED) {
      out.push({
        level: 'medium', ico: 'i', category: 'MONITORING',
        title: 'Elevated Symptomatic Rate',
        desc: `${rate}% symptomatic rate — at upper baseline threshold. Monitor trend across next 48 hours for sustained increase. ` +
          `If rate persists above 10% for 3+ consecutive days, escalate to enhanced surveillance protocol.`,
        action: 'Monitor 48-hour trend. Document in shift log.',
      })
    }

    // ── 2. FEVER CLUSTER DETECTION ──────────────────────────────────
    if (t > 0 && feverToday > 0) {
      const fPct = Math.round(feverToday / t * 100)
      if (highFever > 0) {
        out.push({
          level: 'critical', ico: '!!', category: 'CLINICAL',
          title: `${highFever} High Fever Case${highFever > 1 ? 's' : ''} (≥38.5°C)`,
          desc: `${highFever} traveler${highFever > 1 ? 's' : ''} with temperature ≥38.5°C detected today. ` +
            `Total febrile: ${feverToday} (${fPct}% of screened). Average temperature: ${s.today?.avg_temp_c?.toFixed(1) || '—'}°C. ` +
            `High fever is a cardinal sign for VHF, SARI, meningitis, and malaria. ` +
            `Correlate with travel history from secondary screening and endemic disease patterns at this POE.`,
          action: 'Correlate with travel history. Check VHF/SARI/Meningitis differential. Ensure isolation protocols.',
        })
      } else if (fPct >= FEVER_CLUSTER_PCT) {
        out.push({
          level: 'high', ico: '!', category: 'EPI',
          title: 'Febrile Illness Cluster',
          desc: `${feverToday} fever cases (${fPct}% of ${t} screened) — exceeds ${FEVER_CLUSTER_PCT}% threshold. ` +
            `Check for ILI/SARI cluster signal. Verify screening area ventilation and decontamination protocols. ` +
            `Consider temporal and geographic clustering of febrile cases.`,
          action: 'Verify ventilation. Check for ILI/SARI clustering. Decontaminate screening area.',
        })
      }
    }

    // ── 3. REFERRAL QUEUE ANALYSIS ──────────────────────────────────
    const cRef = s.referral_queue?.critical_open || 0
    const hRef = s.referral_queue?.high_open || 0
    const oRef = s.referral_queue?.open || 0
    const oldestMin = s.referral_queue?.oldest_open_minutes || 0

    if (cRef > 0) {
      out.push({
        level: 'critical', ico: '!!', category: 'OPERATIONS',
        title: `${cRef} Critical Referral${cRef > 1 ? 's' : ''} Pending`,
        desc: `CRITICAL priority referral${cRef > 1 ? 's' : ''} awaiting secondary screening. ` +
          `IHR Art.23 maximum response time: ${IHR_PICKUP_SLA_MIN} minutes. ` +
          (oldestMin > IHR_PICKUP_SLA_MIN
            ? `OVERDUE: oldest referral waiting ${oldestMin} minutes (${Math.round(oldestMin / 60 * 10) / 10} hours). Immediate escalation required.`
            : `Oldest referral: ${oldestMin} minutes. Within SLA but requires immediate attention.`),
        action: 'Assign secondary officer immediately. Escalate to POE supervisor if no officer available.',
      })
    } else if (oRef >= REFERRAL_BACKLOG_HIGH) {
      out.push({
        level: 'high', ico: '!', category: 'OPERATIONS',
        title: 'Referral Queue Backlog',
        desc: `${oRef} open referrals pending secondary screening. Oldest: ${oldestMin} minutes. ` +
          `${hRef > 0 ? `${hRef} HIGH priority. ` : ''}` +
          `Consider deploying additional secondary screening officers or escalating to POE supervisor. ` +
          `Prolonged queue times increase cross-contamination risk in waiting areas.`,
        action: 'Deploy additional secondary officers. Reorganize waiting area.',
      })
    } else if (oRef >= REFERRAL_BACKLOG_WARN) {
      out.push({
        level: 'medium', ico: 'i', category: 'CAPACITY',
        title: 'Growing Referral Queue',
        desc: `${oRef} referrals pending. Monitor queue clearance rate — backlog above ${REFERRAL_BACKLOG_HIGH} triggers escalation.`,
        action: 'Monitor queue. Prepare standby secondary officer.',
      })
    }

    // ── 4. IHR ALERT INTELLIGENCE ───────────────────────────────────
    const critAl = s.alerts?.critical_open || 0
    const highAl = s.alerts?.high_open || 0
    const openAl = s.alerts?.open || 0

    if (critAl > 0) {
      out.push({
        level: 'critical', ico: '!!', category: 'IHR',
        title: `${critAl} Critical IHR Alert${critAl > 1 ? 's' : ''}`,
        desc: `Active CRITICAL-level IHR alert${critAl > 1 ? 's' : ''}. Per IHR 2005 Art. 6, events that may constitute a PHEIC ` +
          `must be notified to WHO within 24 hours of assessment. Verify that district DHIS2 reporting and PHEOC notification ` +
          `channels are active. Confirm alert acknowledgment within 60 minutes per national protocol.`,
        action: 'Verify DHIS2 notification. Confirm PHEOC channel. Document WHO notification timeline.',
      })
    } else if (openAl > 0) {
      out.push({
        level: 'high', ico: '!', category: 'IHR',
        title: `${openAl} Active IHR Alert${openAl > 1 ? 's' : ''}`,
        desc: `IHR alert${openAl > 1 ? 's' : ''} requiring attention. ${highAl > 0 ? `${highAl} at HIGH priority. ` : ''}` +
          `Review syndrome classification and confirm appropriate routing to district/PHEOC.`,
        action: 'Review alert details. Confirm routing. Ensure acknowledgment.',
      })
    }

    // ── 5. SECONDARY CASE RISK INTELLIGENCE ─────────────────────────
    const fc = fun.value
    if (fc?.secondary_cases?.risk_critical > 0) {
      const cr = fc.secondary_cases.risk_critical
      out.push({
        level: 'critical', ico: '!!', category: 'CASE MANAGEMENT',
        title: `${cr} Critical-Risk Secondary Case${cr > 1 ? 's' : ''}`,
        desc: `Active secondary screening case${cr > 1 ? 's' : ''} at CRITICAL risk level. ` +
          `Verify isolation/quarantine measures are in place per IHR Annex 1 core capacity requirements. ` +
          `Check if IHR Annex 2 decision instrument criteria are met for national focal point notification. ` +
          (fc.secondary_cases.quarantined > 0 ? `${fc.secondary_cases.quarantined} currently quarantined. ` : '') +
          (fc.secondary_cases.isolated > 0 ? `${fc.secondary_cases.isolated} currently isolated.` : ''),
        action: 'Verify isolation. Run Annex 2 decision instrument. Notify IHR focal point if criteria met.',
      })
    }

    // ── 6. VOLUME ANOMALY DETECTION ─────────────────────────────────
    const d = delta.value
    if (t > 5 && d > 0 && d >= t * (SURGE_PCT_THRESHOLD / 100)) {
      const pctInc = t - d > 0 ? Math.round(d / (t - d) * 100) : 100
      out.push({
        level: 'high', ico: '!', category: 'OPERATIONS',
        title: 'Screening Volume Surge',
        desc: `Today's volume (${t}) is ${pctInc}% higher than yesterday (${t - d}). ` +
          `This may indicate increased border traffic, a public event, or seasonal migration. ` +
          `Verify screening station capacity, consumable stock levels (PPE, thermometers), and officer availability.`,
        action: 'Verify consumable stock. Prepare additional screening lanes. Check officer schedules.',
      })
    } else if (t > 0 && d < 0 && Math.abs(d) >= t * (DROP_PCT_THRESHOLD / 100)) {
      out.push({
        level: 'medium', ico: 'i', category: 'OPERATIONS',
        title: 'Volume Drop Detected',
        desc: `Today's count (${t}) is significantly below yesterday (${t + Math.abs(d)}). ` +
          `Verify screening operations are running normally — possible equipment failure, staffing gap, or connectivity issue preventing records from syncing.`,
        action: 'Check equipment. Verify staffing. Check device sync status.',
      })
    }

    // ── 7. SYNC + DATA INTEGRITY ────────────────────────────────────
    const unsynced = s.all_time?.unsynced || 0
    const failed = s.all_time?.sync_failed || 0

    if (failed > 0) {
      out.push({
        level: 'high', ico: '!', category: 'DATA INTEGRITY',
        title: `${failed} Sync Failure${failed > 1 ? 's' : ''}`,
        desc: `${failed} screening record${failed > 1 ? 's' : ''} failed to upload to the central server. ` +
          `Data integrity at risk — these records exist only on the device. Loss or damage to the device would result in permanent data loss. ` +
          `Check network connectivity, server availability, and retry sync from the device.`,
        action: 'Check network. Retry sync. Document failure in data quality log.',
      })
    } else if (unsynced > 10) {
      out.push({
        level: 'medium', ico: 'i', category: 'SYNC',
        title: `${unsynced} Records Pending Sync`,
        desc: `${unsynced} records awaiting server upload. Normal during offline operation — will resolve on connectivity restoration.`,
        action: 'Monitor. Will auto-resolve on connectivity.',
      })
    }

    // ── 8. WEEK-OVER-WEEK TREND ─────────────────────────────────────
    const wk = weekly.value?.report
    if (wk?.vs_previous_week != null) {
      if (wk.vs_previous_week < -20) {
        out.push({
          level: 'medium', ico: 'i', category: 'TREND',
          title: 'Week-over-Week Decline',
          desc: `This week's screening volume is ${Math.abs(wk.vs_previous_week)} fewer than last week ` +
            `(${wk.total_screened || 0} vs ${wk.previous_week_total || 0}). ` +
            `Verify no operational disruptions at POE entry points.`,
          action: 'Verify screening operations. Check for route/traffic changes.',
        })
      } else if (wk.vs_previous_week > 50) {
        out.push({
          level: 'medium', ico: 'i', category: 'TREND',
          title: 'Week-over-Week Surge',
          desc: `This week's volume (${wk.total_screened || 0}) is +${wk.vs_previous_week} vs last week. ` +
            `May indicate seasonal migration, event-driven traffic, or improved screening compliance.`,
          action: 'Assess cause. Ensure capacity matches demand.',
        })
      }
    }

    // ── 9. EPIDEMIOLOGICAL PATTERN ANALYSIS ─────────────────────────
    const syndromes = epi.value?.syndromes || []
    const vhf = syndromes.find(s => s.syndrome === 'VHF')
    if (vhf && vhf.count > 0) {
      out.push({
        level: 'critical', ico: '!!', category: 'DISEASE SURVEILLANCE',
        title: `VHF Syndrome Detected (${vhf.count} case${vhf.count > 1 ? 's' : ''})`,
        desc: `Viral Hemorrhagic Fever syndrome classification detected in ${vhf.count} secondary screening case${vhf.count > 1 ? 's' : ''}. ` +
          `VHF is an IHR Annex 2 Tier 1 "always notifiable" event. Immediate notification to WHO through the national IHR focal point is mandatory. ` +
          `Activate full VHF response protocol: enhanced PPE (Tyvek suit, N95, goggles, double gloves), dedicated isolation area, contact tracing initiation.`,
        action: 'IMMEDIATE: Activate VHF protocol. Notify IHR focal point. Isolate patient. Begin contact tracing.',
      })
    }

    const meningitis = syndromes.find(s => s.syndrome === 'MENINGITIS')
    if (meningitis && meningitis.count >= 2) {
      out.push({
        level: 'high', ico: '!', category: 'DISEASE SURVEILLANCE',
        title: `Meningitis Cluster (${meningitis.count} cases)`,
        desc: `${meningitis.count} meningitis cases detected. In meningitis belt countries, ≥2 cases at a single POE within a week ` +
          `meets the WHO epidemic threshold for investigation. Verify vaccination status of cases and consider prophylaxis for contacts.`,
        action: 'Investigate cluster. Check vaccination status. Consider prophylaxis.',
      })
    }

    // ── 10. GENDER DISPARITY ────────────────────────────────────────
    const genders = epi.value?.by_gender || []
    const totalG = genders.reduce((a, g) => a + (g.total || 0), 0)
    if (totalG > 20) {
      const female = genders.find(g => g.gender === 'FEMALE')
      const femalePct = female ? Math.round((female.total / totalG) * 100) : 0
      if (femalePct < 20) {
        out.push({
          level: 'medium', ico: 'i', category: 'EQUITY',
          title: 'Gender Screening Gap',
          desc: `Only ${femalePct}% of screenings are female travelers. This may indicate gender-biased screening practices, ` +
            `separate entry lanes not being monitored, or cultural barriers to screening. ` +
            `WHO recommends equitable screening regardless of gender, age, or nationality.`,
          action: 'Review screening lane coverage. Ensure female screeners are available.',
        })
      }
    }

    return out
  })

  // ─── PDF INTELLIGENCE REPORT ─────────────────────────────────────────────────
  // National-level POE intelligence report using jsPDF.
  // No DOM/canvas — works in Capacitor WebView, PWA, desktop.
  // Dynamically tied to current filter state via the data refs.
  async function generatePDFReport() {
    const { jsPDF } = await import('jspdf')
    const doc = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' })
    const W = 210, M = 14, CW = W - M * 2, R = W - M
    let y = M
    const s = sum.value || {}
    const sc = s.scope || {}
    const td = s.today || {}
    const at = s.all_time || {}
    const rq = s.referral_queue || {}
    const al = s.alerts || {}
    const tw = s.this_week || {}
    const tm = s.this_month || {}
    const ep = epi.value || {}
    const fn = fun.value || {}
    const wk = weekly.value || {}
    const dv = devices.value || {}
    const of_ = officers.value || {}
    const ald = alertsData.value || {}
    const now = new Date()
    const dateStr = now.toLocaleDateString([], { day: '2-digit', month: 'long', year: 'numeric' })
    const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    const poeLabel = sc.poe_code || 'POE'
    const distLabel = sc.district_code || ''
    const countryLabel = sc.country_code || ''

    // ── Text sanitizer — jsPDF default Helvetica has no Unicode glyphs ──
    // Replace ALL non-ASCII characters with safe ASCII equivalents.
    // Without this, characters like >= degrees etc render as spaced-out garbage.
    function clean(text) {
      return String(text ?? '')
        .replace(/\u2265/g, '>=')       // ≥
        .replace(/\u2264/g, '<=')       // ≤
        .replace(/\u00B0/g, ' deg ')    // °
        .replace(/\u2014/g, ' - ')      // —
        .replace(/\u2013/g, '-')        // –
        .replace(/\u00B7/g, '-')        // ·
        .replace(/\u2018/g, "'")        // '
        .replace(/\u2019/g, "'")        // '
        .replace(/\u201C/g, '"')        // "
        .replace(/\u201D/g, '"')        // "
        .replace(/\u2026/g, '...')      // …
        .replace(/\u2192/g, '->')       // →
        .replace(/\u2190/g, '<-')       // ←
        .replace(/[^\x00-\x7F]/g, '')   // strip any remaining non-ASCII
    }

    // Wrap doc.text so ALL text is sanitized automatically
    const _origText = doc.text.bind(doc)
    doc.text = (text, x, _y, opts) => {
      if (Array.isArray(text)) _origText(text.map(clean), x, _y, opts)
      else _origText(clean(text), x, _y, opts)
      return doc
    }
    // Also wrap splitTextToSize
    const _origSplit = doc.splitTextToSize.bind(doc)
    doc.splitTextToSize = (text, maxW) => _origSplit(clean(text), maxW)

    // ── Helpers ──────────────────────────────────────────────────────
    const pg = () => { if (y > 268) { doc.addPage(); y = M } }
    const H = (text, sz = 12) => { pg(); doc.setFontSize(sz).setFont('helvetica', 'bold').setTextColor(0, 31, 61); doc.text(text, M, y); y += sz * 0.45 + 3 }
    const SH = (text) => { pg(); doc.setFontSize(8).setFont('helvetica', 'bold').setTextColor(100, 120, 140); doc.text(text.toUpperCase(), M, y); y += 4.5 }
    const R2 = (label, val, highlight) => {
      pg()
      doc.setFontSize(8.5).setFont('helvetica', 'normal').setTextColor(80, 100, 120)
      doc.text(String(label), M, y)
      if (highlight === 'r') doc.setTextColor(198, 40, 40)
      else if (highlight === 'a') doc.setTextColor(230, 81, 0)
      else if (highlight === 'g') doc.setTextColor(46, 125, 50)
      else doc.setTextColor(26, 58, 92)
      doc.setFont('helvetica', 'bold')
      doc.text(String(val ?? '—'), M + 68, y)
      y += 4.5
    }
    const LN = () => { doc.setDrawColor(210, 218, 228).setLineWidth(0.2); doc.line(M, y, R, y); y += 3 }
    const P = (text, maxW) => {
      pg()
      doc.setFontSize(8).setFont('helvetica', 'normal').setTextColor(70, 90, 110)
      const lines = doc.splitTextToSize(String(text), maxW || CW)
      doc.text(lines, M, y); y += lines.length * 3.2 + 1
    }
    const BOLD_P = (text, maxW) => {
      pg()
      doc.setFontSize(8).setFont('helvetica', 'bold').setTextColor(0, 80, 180)
      const lines = doc.splitTextToSize(String(text), maxW || CW)
      doc.text(lines, M, y); y += lines.length * 3.2 + 2
    }

    // ═══════════════════════════════════════════════════════════════
    // PAGE 1 — COVER + EXECUTIVE SUMMARY
    // ═══════════════════════════════════════════════════════════════
    doc.setFillColor(0, 31, 61); doc.rect(0, 0, W, 44, 'F')
    doc.setFontSize(8).setFont('helvetica', 'normal').setTextColor(150, 180, 220)
    doc.text('ECSA-HC POE SENTINEL · WHO IHR 2005 · CONFIDENTIAL', M, 10)
    doc.setFontSize(17).setFont('helvetica', 'bold').setTextColor(255, 255, 255)
    doc.text('POE Screening Intelligence Report', M, 22)
    doc.setFontSize(11).setFont('helvetica', 'normal').setTextColor(180, 210, 240)
    doc.text(`${poeLabel}  ·  ${distLabel}  ·  ${countryLabel}`, M, 30)
    doc.setFontSize(9).setTextColor(140, 170, 210)
    doc.text(`Report Date: ${dateStr} ${timeStr}   |   Classification: OFFICIAL`, M, 38)
    y = 52

    H('1. Executive Summary', 13)
    P(`This report summarizes screening operations at ${poeLabel} Point of Entry. ` +
      `A total of ${at.total ?? 0} travelers have been screened (all time), of which ${at.symptomatic ?? 0} (${at.symptomatic_rate ?? 0}%) were assessed as symptomatic. ` +
      `${at.referrals ?? 0} referrals were issued for secondary screening. Today, ${td.total ?? 0} travelers were screened with a ${td.symptomatic_rate ?? 0}% symptomatic rate.`)
    y += 2

    SH('All-Time Totals')
    R2('Total Travelers Screened', at.total ?? 0)
    R2('Completed Screenings', at.completed ?? 0)
    R2('Voided Records', at.voided ?? 0)
    R2('Symptomatic (All Time)', at.symptomatic ?? 0, (at.symptomatic ?? 0) > 0 ? 'r' : null)
    R2('Asymptomatic (All Time)', at.asymptomatic ?? 0, 'g')
    R2('All-Time Symptomatic Rate', `${at.symptomatic_rate ?? 0}%`, (at.symptomatic_rate ?? 0) >= 20 ? 'r' : null)
    R2('Total Referrals Issued', at.referrals ?? 0)
    R2('Male Travelers', at.male ?? 0)
    R2('Female Travelers', at.female ?? 0)
    R2('Last Screening', at.last_capture_at || '—')
    LN()

    SH('Today\'s Situation')
    R2('Screened Today', td.total ?? 0)
    R2('Symptomatic Today', td.symptomatic ?? 0, (td.symptomatic ?? 0) > 0 ? 'r' : null)
    R2('Symptomatic Rate Today', `${td.symptomatic_rate ?? 0}%`, (td.symptomatic_rate ?? 0) >= 20 ? 'r' : null)
    R2('Referrals Today', td.referrals ?? 0)
    R2('Fever Cases (≥37.5°C)', td.fever_count ?? 0, (td.fever_count ?? 0) > 0 ? 'a' : null)
    R2('High Fever (≥38.5°C)', td.high_fever_count ?? 0, (td.high_fever_count ?? 0) > 0 ? 'r' : null)
    R2('Avg Temperature Today', td.avg_temp_c ? `${Number(td.avg_temp_c).toFixed(1)}°C` : '—')
    R2('Change vs Yesterday', `${(td.vs_yesterday ?? 0) >= 0 ? '+' : ''}${td.vs_yesterday ?? 0}`, (td.vs_yesterday ?? 0) >= 0 ? 'g' : 'r')
    R2('First Screening Today', td.first_capture_at || '—')
    R2('Last Screening Today', td.last_capture_at || '—')
    LN()

    SH('This Week / This Month')
    R2('This Week Total', tw.total ?? 0)
    R2('This Week Symptomatic', tw.symptomatic ?? 0)
    R2('This Week Referrals', tw.referrals ?? 0)
    R2('This Month Total', tm.total ?? 0)
    R2('This Month Symptomatic', tm.symptomatic ?? 0)

    // ═══════════════════════════════════════════════════════════════
    // PAGE 2 — SURVEILLANCE SIGNALS + RISK ASSESSMENT
    // ═══════════════════════════════════════════════════════════════
    doc.addPage(); y = M
    H('2. AI Surveillance Signals & Risk Assessment', 13)
    P(`Automated analysis of ${poeLabel} screening data. Signals are generated from WHO IHR 2005 clinical thresholds, ` +
      `epidemiological patterns, and operational capacity metrics. ${signals.value.length} signal${signals.value.length !== 1 ? 's' : ''} detected.`)
    y += 2

    if (signals.value.length === 0) {
      doc.setFontSize(9).setFont('helvetica', 'normal').setTextColor(46, 125, 50)
      doc.text('No surveillance signals at this time. All indicators within normal parameters.', M, y); y += 6
    } else {
      for (const sig of signals.value) {
        pg()
        const clr = sig.level === 'critical' ? [198, 40, 40] : sig.level === 'high' ? [230, 81, 0] : [120, 120, 120]
        doc.setFillColor(...clr); doc.circle(M + 2, y - 1, 1.8, 'F')
        doc.setFontSize(7).setFont('helvetica', 'bold').setTextColor(...clr)
        doc.text(sig.category || '', M + 7, y - 0.5)
        doc.setFontSize(9).setFont('helvetica', 'bold').setTextColor(26, 58, 92)
        doc.text(sig.title, M + 7, y + 3.5); y += 7
        doc.setFontSize(7.5).setFont('helvetica', 'normal').setTextColor(70, 90, 110)
        const descLines = doc.splitTextToSize(sig.desc, CW - 7)
        doc.text(descLines, M + 7, y); y += descLines.length * 3 + 1
        if (sig.action) {
          doc.setFontSize(7.5).setFont('helvetica', 'bold').setTextColor(0, 80, 180)
          const actLines = doc.splitTextToSize('RECOMMENDED ACTION: ' + sig.action, CW - 7)
          doc.text(actLines, M + 7, y); y += actLines.length * 3 + 1
        }
        y += 3
      }
    }

    // ═══════════════════════════════════════════════════════════════
    // PAGE 3 — REFERRAL PIPELINE + CASE MANAGEMENT
    // ═══════════════════════════════════════════════════════════════
    doc.addPage(); y = M
    H('3. Referral Pipeline & Secondary Case Management', 13)

    SH('Referral Queue Status')
    R2('Open Referrals', rq.open ?? 0, (rq.open ?? 0) > 0 ? 'a' : 'g')
    R2('In Progress', rq.in_progress ?? 0)
    R2('Closed (Total)', rq.closed_total ?? 0, 'g')
    R2('Critical Priority Open', rq.critical_open ?? 0, (rq.critical_open ?? 0) > 0 ? 'r' : null)
    R2('High Priority Open', rq.high_open ?? 0, (rq.high_open ?? 0) > 0 ? 'a' : null)
    R2('Oldest Open Referral', rq.oldest_open_minutes ? `${rq.oldest_open_minutes} minutes` : '—', (rq.oldest_open_minutes ?? 0) > 30 ? 'r' : null)
    R2('Queue Critical?', rq.queue_critical ? 'YES — EXCEEDS SLA' : 'No', rq.queue_critical ? 'r' : 'g')
    LN()

    if (fn.funnel?.length) {
      SH('Referral Funnel (Primary → Secondary Conversion)')
      for (const step of fn.funnel) {
        R2(step.description || step.stage, `${step.count}  (${step.rate}%)`)
      }
      LN()
    }

    if (fn.notifications) {
      SH('Notification Performance')
      R2('Average Pickup Time', fn.notifications.avg_pickup_minutes != null ? `${fn.notifications.avg_pickup_minutes} minutes` : '—',
        (fn.notifications.avg_pickup_minutes ?? 0) > 30 ? 'r' : null)
      R2('Priority: Critical', fn.notifications.priority_critical ?? 0, (fn.notifications.priority_critical ?? 0) > 0 ? 'r' : null)
      R2('Priority: High', fn.notifications.priority_high ?? 0)
      R2('Priority: Normal', fn.notifications.priority_normal ?? 0)
      LN()
    }

    if (fn.secondary_cases) {
      SH('Secondary Screening Case Outcomes')
      R2('Total Secondary Cases', fn.secondary_cases.total ?? 0)
      R2('Open Cases', fn.secondary_cases.open ?? 0, (fn.secondary_cases.open ?? 0) > 0 ? 'a' : null)
      R2('In Progress', fn.secondary_cases.in_progress ?? 0)
      R2('Dispositioned', fn.secondary_cases.dispositioned ?? 0)
      R2('Closed', fn.secondary_cases.closed ?? 0, 'g')
      R2('CRITICAL Risk', fn.secondary_cases.risk_critical ?? 0, (fn.secondary_cases.risk_critical ?? 0) > 0 ? 'r' : null)
      R2('HIGH Risk', fn.secondary_cases.risk_high ?? 0, (fn.secondary_cases.risk_high ?? 0) > 0 ? 'a' : null)
      R2('Avg Case Duration', fn.secondary_cases.avg_case_duration_minutes != null ? `${fn.secondary_cases.avg_case_duration_minutes} minutes` : '—')
      LN()
      SH('Disposition Breakdown')
      R2('Released', fn.secondary_cases.released ?? 0)
      R2('Quarantined', fn.secondary_cases.quarantined ?? 0, (fn.secondary_cases.quarantined ?? 0) > 0 ? 'a' : null)
      R2('Isolated', fn.secondary_cases.isolated ?? 0, (fn.secondary_cases.isolated ?? 0) > 0 ? 'r' : null)
      R2('Referred (to facility)', fn.secondary_cases.referred ?? 0)
    }

    // ═══════════════════════════════════════════════════════════════
    // PAGE 4 — EPIDEMIOLOGICAL PROFILE
    // ═══════════════════════════════════════════════════════════════
    doc.addPage(); y = M
    H('4. Epidemiological Profile', 13)

    SH('Gender Distribution')
    const genders = ep.by_gender || []
    const gTotal = genders.reduce((a, g) => a + (g.total || 0), 0)
    for (const g of genders) {
      const pct = gTotal ? Math.round((g.total / gTotal) * 100) : 0
      R2(`${GENDER_FULL[g.gender] || g.gender}`, `${g.total}  (${pct}%)  —  ${g.symp_rate ?? 0}% symptomatic`)
    }
    LN()

    SH('Temperature Analysis')
    const temp = ep.temperature
    if (temp) {
      R2('Travelers with Temp Recorded', temp.count_with_temp ?? 0)
      R2('Average Temperature', temp.avg_c != null ? `${Number(temp.avg_c).toFixed(1)}°C` : '—')
      R2('Temperature Range', `${temp.min_c ?? '—'}°C — ${temp.max_c ?? '—'}°C`)
      if (temp.bands) {
        R2('High Fever (≥38.5°C)', temp.bands.high_fever ?? 0, (temp.bands.high_fever ?? 0) > 0 ? 'r' : null)
        R2('Low-Grade Fever (37.5–38.5°C)', temp.bands.low_grade_fever ?? 0, (temp.bands.low_grade_fever ?? 0) > 0 ? 'a' : null)
        R2('Normal (36.0–37.5°C)', temp.bands.normal ?? 0, 'g')
        R2('Hypothermia (<36.0°C)', temp.bands.hypothermia ?? 0)
      }
    } else { P('No temperature data available for this period.') }
    LN()

    const syns = ep.syndromes || []
    if (syns.length) {
      SH('Syndrome Classification (from Secondary Screening)')
      P('Syndromes are classified during secondary screening per WHO IHR case definitions.')
      for (const sn of syns) {
        const hrNote = sn.high_risk ? `  (${sn.high_risk} high/critical risk)` : ''
        R2(sn.syndrome.replace(/_/g, ' '), `${sn.count} case${sn.count !== 1 ? 's' : ''}${hrNote}`,
          ['VHF', 'MENINGITIS'].includes(sn.syndrome) ? 'r' : null)
      }
      LN()
    }

    const wdays = ep.symp_by_weekday || []
    const activeDays = wdays.filter(w => w.total > 0)
    if (activeDays.length) {
      SH('Symptomatic Rate by Day of Week')
      for (const wd of activeDays) {
        R2(wd.label, `${wd.total} screened  ·  ${wd.symptomatic} symptomatic  ·  ${wd.symp_rate}% rate`,
          wd.symp_rate >= 20 ? 'r' : null)
      }
    }

    // ═══════════════════════════════════════════════════════════════
    // PAGE 5 — IHR ALERTS
    // ═══════════════════════════════════════════════════════════════
    doc.addPage(); y = M
    H('5. IHR Alert Intelligence', 13)
    const aTotals = ald.totals || {}
    R2('Total Alerts', aTotals.total ?? 0)
    R2('Open', aTotals.open ?? 0, (aTotals.open ?? 0) > 0 ? 'r' : 'g')
    R2('Acknowledged', aTotals.acknowledged ?? 0)
    R2('Closed', aTotals.closed ?? 0, 'g')
    R2('CRITICAL Level', aTotals.critical ?? 0, (aTotals.critical ?? 0) > 0 ? 'r' : null)
    R2('HIGH Level', aTotals.high ?? 0, (aTotals.high ?? 0) > 0 ? 'a' : null)
    R2('Rule-Based', aTotals.rule_based ?? 0)
    R2('Officer-Raised', aTotals.officer_raised ?? 0)
    R2('Avg Acknowledgment Time', aTotals.avg_ack_minutes != null ? `${aTotals.avg_ack_minutes} minutes` : '—')
    LN()

    const recentAlerts = ald.recent_open || []
    if (recentAlerts.length) {
      SH('Active Open Alerts')
      for (const ra of recentAlerts.slice(0, 8)) {
        R2(`[${ra.risk_level}] ${ra.alert_title || ra.alert_code}`,
          `${ra.syndrome_classification || '—'}  ·  ${ra.poe_code || ''}`,
          ra.risk_level === 'CRITICAL' ? 'r' : ra.risk_level === 'HIGH' ? 'a' : null)
      }
    }

    // ═══════════════════════════════════════════════════════════════
    // PAGE 6 — WEEKLY REPORT + OPERATIONS
    // ═══════════════════════════════════════════════════════════════
    doc.addPage(); y = M
    const wkr = wk.report || {}
    H(`6. Weekly Operational Report — ${wk.week_label || 'Current Week'}`, 13)
    R2('Total Screened This Week', wkr.total_screened ?? 0)
    R2('Symptomatic This Week', wkr.total_symptomatic ?? 0, (wkr.total_symptomatic ?? 0) > 0 ? 'r' : null)
    R2('Symptomatic Rate', `${wkr.symptomatic_rate ?? 0}%`, (wkr.symptomatic_rate ?? 0) >= 20 ? 'r' : null)
    R2('Referrals This Week', wkr.total_referrals ?? 0)
    R2('Fever Cases', wkr.fever_count ?? 0, (wkr.fever_count ?? 0) > 0 ? 'a' : null)
    R2('High Fever (≥38.5°C)', wkr.high_fever_count ?? 0, (wkr.high_fever_count ?? 0) > 0 ? 'r' : null)
    R2('Daily Average', wkr.avg_daily ?? 0)
    if (wkr.peak_day) R2('Peak Day', `${wkr.peak_day.date} (${wkr.peak_day.count} screenings)`)
    R2('Change vs Previous Week', `${(wkr.vs_previous_week ?? 0) >= 0 ? '+' : ''}${wkr.vs_previous_week ?? 0}`, (wkr.vs_previous_week ?? 0) >= 0 ? 'g' : 'r')
    R2('Previous Week Total', wkr.previous_week_total ?? 0)
    LN()
    SH('Weekly Gender Breakdown')
    R2('Male', wkr.male ?? 0); R2('Female', wkr.female ?? 0)
    R2('Other/Unknown', (wkr.other ?? 0) + (wkr.unknown ?? 0))
    LN()
    SH('Weekly Secondary Outcomes')
    R2('Secondary Cases Opened', wkr.secondary_cases ?? 0)
    R2('High Risk Cases', wkr.cases_high_risk ?? 0, (wkr.cases_high_risk ?? 0) > 0 ? 'r' : null)
    R2('Cases Released', wkr.cases_released ?? 0)
    R2('Cases Quarantined', wkr.cases_quarantined ?? 0, (wkr.cases_quarantined ?? 0) > 0 ? 'a' : null)
    R2('Cases Isolated', wkr.cases_isolated ?? 0, (wkr.cases_isolated ?? 0) > 0 ? 'r' : null)
    R2('IHR Alerts Raised', wkr.alerts_raised ?? 0, (wkr.alerts_raised ?? 0) > 0 ? 'a' : null)
    R2('Screening Officers Active', wkr.screener_count ?? 0)
    R2('Devices Used', wkr.device_count ?? 0)

    // ═══════════════════════════════════════════════════════════════
    // PAGE 7 — DEVICE + OFFICER ACCOUNTABILITY
    // ═══════════════════════════════════════════════════════════════
    doc.addPage(); y = M
    H('7. Device & Officer Accountability', 13)

    const devList = dv.devices || []
    if (devList.length) {
      SH(`Device Health — ${dv.device_count ?? 0} devices`)
      R2('Devices at Risk', dv.devices_at_risk ?? 0, (dv.devices_at_risk ?? 0) > 0 ? 'r' : 'g')
      R2('Total Unsynced Records', dv.total_unsynced ?? 0, (dv.total_unsynced ?? 0) > 0 ? 'a' : null)
      R2('Total Failed Syncs', dv.total_failed ?? 0, (dv.total_failed ?? 0) > 0 ? 'r' : null)
      y += 2
      for (const d of devList.slice(0, 8)) {
        R2(d.device_id, `${d.status} · ${d.platform} · ${d.total_records} rec · ${d.unsynced || 0} unsynced`,
          d.status === 'CRITICAL' ? 'r' : d.status === 'WARNING' ? 'a' : null)
      }
      LN()
    }

    const offList = of_.screeners || []
    if (offList.length) {
      SH(`Officer Activity — ${of_.screener_count ?? 0} officers`)
      for (const o of offList.slice(0, 10)) {
        R2(o.full_name || o.username || '—',
          `${o.total} screenings · ${o.symptomatic || 0} symp · ${o.referrals || 0} ref · ${o.active_days || 0} active days`)
      }
    }

    // ═══════════════════════════════════════════════════════════════
    // PAGE 8 — RECOMMENDATIONS + SIGN-OFF
    // ═══════════════════════════════════════════════════════════════
    doc.addPage(); y = M
    H('8. Recommendations & Action Items', 13)
    y += 2
    const sigs = signals.value.filter(s => s.action)
    if (sigs.length) {
      P('Based on automated surveillance analysis, the following actions are recommended:')
      y += 2
      let idx = 1
      for (const sig of sigs) {
        pg()
        doc.setFontSize(8.5).setFont('helvetica', 'bold').setTextColor(26, 58, 92)
        doc.text(`${idx}. [${sig.category}] ${sig.title}`, M, y); y += 4
        BOLD_P(sig.action, CW - 5)
        idx++
      }
    } else {
      P('No action items at this time. All surveillance indicators are within normal operational parameters.')
    }

    y += 10; LN(); y += 5
    SH('Report Authorization')
    y += 3
    doc.setFontSize(8).setFont('helvetica', 'normal').setTextColor(100, 100, 100)
    doc.text('Prepared by: ___________________________     Signature: ___________________________', M, y); y += 8
    doc.text('Reviewed by: ___________________________     Signature: ___________________________', M, y); y += 8
    doc.text(`Date: ${dateStr}                                           POE: ${poeLabel}`, M, y); y += 12
    doc.setFontSize(7).setTextColor(140, 140, 140)
    doc.text('This report is auto-generated by the ECSA-HC POE Screening system. Data accuracy depends on device sync status.', M, y); y += 3.5
    doc.text('Report classification: OFFICIAL. Distribution: POE Health Officer, District Surveillance Officer, PHEOC, National IHR Focal Point.', M, y)

    // ── Footer on every page ────────────────────────────────────────
    const total = doc.internal.getNumberOfPages()
    for (let i = 1; i <= total; i++) {
      doc.setPage(i)
      doc.setFontSize(6.5).setFont('helvetica', 'normal').setTextColor(160, 160, 160)
      doc.text(`ECSA-HC POE Screening · IHR Art.23 · Page ${i}/${total} · ${poeLabel} · ${dateStr} · OFFICIAL`, M, 290)
    }

    // ── Output ──────────────────────────────────────────────────────
    const blob = doc.output('blob')
    const safePoe = poeLabel.replace(/[^a-zA-Z0-9-_]/g, '_')
    const filename = `POE_Intelligence_${safePoe}_${now.toISOString().slice(0, 10)}.pdf`

    // Capacitor native: write to cache dir then open system share sheet.
    // <a download> is silently ignored inside Android WebView.
    let isNative = false
    try {
      const { Capacitor } = await import('@capacitor/core')
      isNative = Capacitor.isNativePlatform()
    } catch { /* not in Capacitor build */ }

    if (isNative) {
      const { Filesystem, Directory } = await import('@capacitor/filesystem')
      const { Share } = await import('@capacitor/share')

      // blob → base64 in safe chunks (avoids stack overflow on large PDFs)
      const arrayBuffer = await blob.arrayBuffer()
      const bytes = new Uint8Array(arrayBuffer)
      const CHUNK = 8192
      let binary = ''
      for (let i = 0; i < bytes.length; i += CHUNK) {
        binary += String.fromCharCode(...bytes.subarray(i, Math.min(i + CHUNK, bytes.length)))
      }
      const base64 = btoa(binary)

      const { uri } = await Filesystem.writeFile({
        path: filename, data: base64, directory: Directory.Cache,
      })

      await Share.share({ title: filename, files: [uri], dialogTitle: 'Save or Share Report' })
    } else {
      // Browser / PWA fallback
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url; link.download = filename; link.style.display = 'none'
      document.body.appendChild(link); link.click()
      setTimeout(() => { document.body.removeChild(link); URL.revokeObjectURL(url) }, 5000)
    }

    return filename
  }

  return {
    signals,
    generatePDFReport,
  }
}
