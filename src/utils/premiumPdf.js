/**
 * src/utils/premiumPdf.js — shared premium PDF building blocks.
 *
 * Every report PDF in the app uses this module so the visual language
 * stays consistent: navy hero header, KPI tile grid, color-blocked
 * sections, embedded chart images via canvas, tabular layouts that
 * never jumble, filter-context block, scope indicator (NATIONAL vs POE),
 * paginated footer with page X of Y.
 *
 * Usage
 * -----
 *   const pdf = await createPdf({
 *     title:    'Primary Screening Report',
 *     subtitle: 'Real-time POE surveillance',
 *     scope:    { level: 'POE' | 'NATIONAL', label: 'Entebbe International Airport' },
 *     officer:  { name: 'Jane Doe', role: 'POE_PRIMARY' },
 *     filters:  [['Period', 'Last 30 days'], ['Direction', 'All']],
 *   })
 *   pdf.kpiGrid([{label, value, accent}, ...])
 *   pdf.section('Throughput')
 *   await pdf.embedChart(canvasOrDataUrl, { caption: 'Hourly traveller volume' })
 *   pdf.table([[...row], [...row]], { headers: [...] })
 *   pdf.save('primary-screening-report-...')
 *
 * Layout rules
 *   • A4 portrait, 40 pt margin
 *   • Helvetica fallbacks (jspdf default — embeds clean)
 *   • Section title 12 pt bold #0F172A
 *   • Body 10 pt #334155
 *   • KPI tile 130×60, navy stroke, value 18 pt bold, label 8 pt
 *   • Auto-page-break every 750 pt y position
 */

const PAGE_W = 595
const PAGE_H = 842
const MARGIN = 40

const COLOR = {
  navy:    [11, 37, 69],
  teal:    [0, 180, 166],
  ink:     [15, 23, 42],
  body:    [51, 65, 85],
  muted:   [100, 116, 139],
  rule:    [226, 232, 240],
  accent:  [37, 99, 235],
  warn:    [217, 119, 6],
  bad:     [185, 28, 28],
  good:    [22, 101, 52],
  bgsoft:  [248, 250, 252],
}

// ─────────────────────────────────────────────────────────────────────────
// TEXT SAFETY — jsPDF's default Helvetica font does not render every UTF-8
// character. Symbols like ≥, ≤, em-dash, smart quotes, etc. come out as
// garbled boxes. We normalise to a safe ASCII subset so the printed PDF
// always looks clean. Only applied at draw time — the source data is
// untouched.
// ─────────────────────────────────────────────────────────────────────────
function sanitizeText(input) {
  if (input == null) return ''
  let s = String(input)
  // Common typographic substitutions that look awful in helvetica
  const map = {
    '≥': '>=', '≤': '<=', '≠': '!=', '×': 'x', '÷': '/',
    '–': '-', '—': '-', '−': '-',          // dashes
    '‘': "'", '’': "'",           // single quotes
    '“': '"', '”': '"',           // double quotes
    '…': '...', ' ': ' ',              // ellipsis + NBSP
    '•': '*', '●': '*',           // bullets
    '→': '->', '←': '<-', '⇒': '=>',
    '✓': 'OK', '✔': 'OK',         // checkmarks
    '✘': 'X', '✖': 'X',           // crossmarks
    '°': 'deg ',                       // degree (always safer as 'deg')
  }
  for (const [k, v] of Object.entries(map)) s = s.split(k).join(v)
  // Strip any remaining non-printable / multi-byte char that helvetica refuses
  return s.replace(/[^\x00-\x7E]/g, '')
}
function san(...args) { return args.map(sanitizeText).join('') }

export async function createPdf({ title, subtitle, scope, officer, filters, context }) {
  const { default: jsPDF } = await import('jspdf')
  const doc = new jsPDF({ unit: 'pt', format: 'a4', compress: true })
  let y = 0
  // Wrap doc.text so EVERY text call goes through the sanitizer — defends
  // against any caller that forgets to sanitize a field with a UTF-8 oddity.
  const _origText = doc.text.bind(doc)
  doc.text = (str, x, yPos, opts) => {
    if (Array.isArray(str)) str = str.map(sanitizeText)
    else str = sanitizeText(str)
    return _origText(str, x, yPos, opts)
  }
  const _origSplit = doc.splitTextToSize.bind(doc)
  doc.splitTextToSize = (str, w, opts) => _origSplit(sanitizeText(str), w, opts)

  // ── HERO HEADER (2-tone — navy → ocean gradient simulated by 2 fills) ───
  doc.setFillColor(...COLOR.navy)
  doc.rect(0, 0, PAGE_W, 110, 'F')
  // Subtle ocean wash on the right half so the hero feels less flat
  doc.setFillColor(13, 35, 64)
  doc.rect(PAGE_W * 0.55, 0, PAGE_W * 0.45, 110, 'F')
  // Teal accent strip
  doc.setFillColor(...COLOR.teal)
  doc.rect(0, 110, PAGE_W, 3, 'F')
  // Subtle bottom shadow line for depth
  doc.setFillColor(7, 18, 40)
  doc.rect(0, 113, PAGE_W, 1, 'F')

  doc.setTextColor(255, 255, 255)
  doc.setFont('helvetica', 'bold')
  doc.setFontSize(22)
  doc.text(title || 'Report', MARGIN, 46)

  doc.setFont('helvetica', 'normal')
  doc.setFontSize(10.5)
  doc.setTextColor(186, 230, 253)
  if (subtitle) doc.text(subtitle, MARGIN, 64)

  // Tag line — always shows (provenance reassures auditors)
  doc.setFontSize(8.5)
  doc.setTextColor(148, 197, 224)
  doc.text('Generated offline-first from on-device records', MARGIN, 82)

  // Right-side meta block
  const rightX = PAGE_W - MARGIN
  doc.setFontSize(9.5)
  doc.setTextColor(255, 255, 255)
  const stamp = new Date().toLocaleString('en-GB', {
    weekday: 'short', year: 'numeric', month: 'short', day: '2-digit',
    hour: '2-digit', minute: '2-digit',
  })
  doc.text(stamp, rightX, 46, { align: 'right' })

  // Scope chip + officer
  doc.setFontSize(9)
  doc.setTextColor(186, 230, 253)
  const scopeLabel = scope?.level === 'NATIONAL'
    ? `NATIONAL VIEW - all POEs${scope?.label ? ' - ' + scope.label : ''}`
    : `POE: ${scope?.label || 'N/A'}`
  doc.text(scopeLabel, rightX, 64, { align: 'right' })
  if (officer?.name) {
    doc.text(`${officer.name}${officer.role ? ' - ' + officer.role : ''}`, rightX, 80, { align: 'right' })
  }

  y = 130

  // ── WHO / WHERE / WHAT / WHEN context block ─────────────────────────────
  // Mandate 2026-05-06 — every report opens with a structured 4-quadrant
  // context block so the reader always knows the answers to the journalism
  // 4 W's before they look at any chart.
  const ctxRows = [
    ['WHO',   context?.who   || (officer?.name ? `${officer.name}${officer.role ? ' (' + officer.role + ')' : ''}` : 'POE Screening system')],
    ['WHERE', context?.where || (scope?.label || 'Uganda')],
    ['WHAT',  context?.what  || (subtitle || title || 'Surveillance report')],
    ['WHEN',  context?.when  || stamp],
  ]
  const ctxH = 22 + ctxRows.length * 14
  doc.setFillColor(245, 247, 250)
  doc.setDrawColor(...COLOR.rule)
  doc.setLineWidth(0.5)
  doc.roundedRect(MARGIN, y, PAGE_W - MARGIN * 2, ctxH, 6, 6, 'FD')
  doc.setFontSize(8.5)
  doc.setFont('helvetica', 'bold')
  doc.setTextColor(...COLOR.muted)
  doc.text('CONTEXT', MARGIN + 12, y + 14)
  let cy = y + 28
  for (const [k, v] of ctxRows) {
    doc.setFont('helvetica', 'bold')
    doc.setTextColor(...COLOR.ink)
    doc.setFontSize(8.5)
    doc.text(k, MARGIN + 12, cy)
    doc.setFont('helvetica', 'normal')
    doc.setTextColor(...COLOR.body)
    doc.setFontSize(9)
    doc.text(String(v).slice(0, 120), MARGIN + 60, cy, { maxWidth: PAGE_W - MARGIN * 2 - 70 })
    cy += 14
  }
  y += ctxH + 12

  // ── FILTER CONTEXT BAND ────────────────────────────────────────────────
  if (filters && filters.length) {
    doc.setFillColor(...COLOR.bgsoft)
    doc.roundedRect(MARGIN, y, PAGE_W - MARGIN * 2, 26 + 14 * Math.ceil(filters.length / 3), 6, 6, 'F')
    doc.setFontSize(8.5)
    doc.setTextColor(...COLOR.muted)
    doc.setFont('helvetica', 'bold')
    doc.text('REPORT PARAMETERS', MARGIN + 12, y + 16)
    doc.setFont('helvetica', 'normal')
    doc.setTextColor(...COLOR.body)
    let cx = MARGIN + 12
    let cy = y + 32
    let col = 0
    filters.forEach(([k, v]) => {
      const text = `${k}: ${String(v)}`
      doc.text(text, cx, cy, { maxWidth: (PAGE_W - MARGIN * 2 - 24) / 3 - 6 })
      col++
      if (col % 3 === 0) { cx = MARGIN + 12; cy += 14 }
      else cx += (PAGE_W - MARGIN * 2 - 24) / 3
    })
    y += 26 + 14 * Math.ceil(filters.length / 3) + 14
  }

  // ── HELPER: page break ─────────────────────────────────────────────────
  function ensureSpace(needed) {
    if (y + needed > PAGE_H - 50) {
      doc.addPage()
      y = MARGIN
    }
  }

  // ── HELPER: section header ────────────────────────────────────────────
  function section(label) {
    ensureSpace(40)
    y += 8
    doc.setFillColor(...COLOR.navy)
    doc.rect(MARGIN, y, 3, 14, 'F')
    doc.setFontSize(12)
    doc.setFont('helvetica', 'bold')
    doc.setTextColor(...COLOR.ink)
    doc.text(label, MARGIN + 10, y + 11)
    y += 22
    return { y }
  }

  // ── HELPER: KPI grid ──────────────────────────────────────────────────
  function kpiGrid(tiles) {
    const cols = 3
    const colW = (PAGE_W - MARGIN * 2 - (cols - 1) * 10) / cols
    const tileH = 64
    let i = 0
    for (const t of tiles) {
      const col = i % cols
      const row = Math.floor(i / cols)
      if (col === 0) ensureSpace(tileH + 10)
      const x = MARGIN + col * (colW + 10)
      const ty = y + row * (tileH + 10)
      // Tile background + accent bar
      doc.setFillColor(255, 255, 255)
      doc.setDrawColor(...COLOR.rule)
      doc.setLineWidth(0.6)
      doc.roundedRect(x, ty, colW, tileH, 6, 6, 'FD')
      const accent = t.accent === 'bad' ? COLOR.bad
        : t.accent === 'warn' ? COLOR.warn
        : t.accent === 'good' ? COLOR.good
        : t.accent === 'accent' ? COLOR.accent
        : COLOR.navy
      doc.setFillColor(...accent)
      doc.rect(x, ty, 3, tileH, 'F')
      // Value
      doc.setFont('helvetica', 'bold')
      doc.setFontSize(20)
      doc.setTextColor(...accent)
      doc.text(String(t.value ?? '—'), x + 12, ty + 32)
      // Label
      doc.setFont('helvetica', 'normal')
      doc.setFontSize(8)
      doc.setTextColor(...COLOR.muted)
      doc.text(String(t.label || '').toUpperCase(), x + 12, ty + 50, { maxWidth: colW - 24 })
      // Optional sublabel
      if (t.sublabel) {
        doc.setFontSize(8)
        doc.setTextColor(...COLOR.body)
        doc.text(String(t.sublabel), x + 12, ty + 60, { maxWidth: colW - 24 })
      }
      i++
    }
    const rows = Math.ceil(tiles.length / cols)
    y += rows * (tileH + 10) + 4
  }

  // ── HELPER: horizontal-bar list ───────────────────────────────────────
  function barList(rows, { showPct = true, accent = 'accent' } = {}) {
    const accentColor = accent === 'bad' ? COLOR.bad
      : accent === 'warn' ? COLOR.warn
      : accent === 'good' ? COLOR.good
      : COLOR.accent
    const labelW = 180
    const valW   = 70
    const barX   = MARGIN + labelW + 8
    const barW   = PAGE_W - MARGIN * 2 - labelW - valW - 20
    const max    = Math.max(1, ...rows.map(r => r.value || 0))
    for (const r of rows) {
      ensureSpace(20)
      doc.setFontSize(9.5)
      doc.setTextColor(...COLOR.body)
      doc.setFont('helvetica', 'normal')
      doc.text(String(r.label || '').slice(0, 40), MARGIN, y + 10, { maxWidth: labelW })
      // Bar background
      doc.setFillColor(...COLOR.rule)
      doc.roundedRect(barX, y + 4, barW, 8, 4, 4, 'F')
      // Bar fill
      const fillW = Math.max(2, (r.value / max) * barW)
      doc.setFillColor(...accentColor)
      doc.roundedRect(barX, y + 4, fillW, 8, 4, 4, 'F')
      // Value text
      doc.setFontSize(9.5)
      doc.setFont('helvetica', 'bold')
      doc.setTextColor(...COLOR.ink)
      const txt = showPct && r.pct != null ? `${r.value}  (${r.pct}%)` : String(r.value)
      doc.text(txt, PAGE_W - MARGIN, y + 10, { align: 'right' })
      y += 18
    }
    y += 4
  }

  // ── HELPER: table ─────────────────────────────────────────────────────
  function table(rows, { headers = null, columnWidths = null } = {}) {
    if (!rows || !rows.length) return
    const cols = (headers || rows[0] || []).length
    const widths = columnWidths
      || new Array(cols).fill((PAGE_W - MARGIN * 2) / cols)
    const totalW = widths.reduce((a, b) => a + b, 0)

    if (headers) {
      ensureSpace(28)
      doc.setFillColor(...COLOR.navy)
      doc.rect(MARGIN, y, totalW, 22, 'F')
      doc.setFontSize(8.5)
      doc.setFont('helvetica', 'bold')
      doc.setTextColor(255, 255, 255)
      let cx = MARGIN + 8
      headers.forEach((h, i) => {
        doc.text(String(h).toUpperCase(), cx, y + 14, { maxWidth: widths[i] - 8 })
        cx += widths[i]
      })
      y += 22
    }

    doc.setFontSize(9)
    doc.setFont('helvetica', 'normal')
    rows.forEach((row, idx) => {
      ensureSpace(20)
      // Zebra
      if (idx % 2 === 0) {
        doc.setFillColor(...COLOR.bgsoft)
        doc.rect(MARGIN, y, totalW, 18, 'F')
      }
      let cx = MARGIN + 8
      doc.setTextColor(...COLOR.body)
      row.forEach((cell, i) => {
        const txt = cell == null ? '—' : String(cell)
        doc.text(txt, cx, y + 12, { maxWidth: widths[i] - 8 })
        cx += widths[i]
      })
      y += 18
    })
    y += 6
  }

  // ── HELPER: paragraph / muted note ────────────────────────────────────
  // Uses normal weight (italic helvetica with embedded compression sometimes
  // renders as bold-faux which looks broken). Adds a left-rule for premium
  // newspaper-style "pull-out" feel.
  function note(text) {
    ensureSpace(24)
    const lineW = PAGE_W - MARGIN * 2 - 14
    doc.setFontSize(9)
    doc.setFont('helvetica', 'normal')
    doc.setTextColor(...COLOR.muted)
    const lines = doc.splitTextToSize(text || '', lineW)
    const blockH = lines.length * 12 + 8
    // Left-rule
    doc.setFillColor(...COLOR.rule)
    doc.rect(MARGIN, y + 2, 2, blockH, 'F')
    let ly = y + 12
    lines.forEach(l => {
      ensureSpace(14)
      doc.text(l, MARGIN + 12, ly)
      ly += 12
    })
    y += blockH + 4
  }

  // ── HELPER: embed chart from canvas ───────────────────────────────────
  /**
   * Embed a canvas (or data-url) as a chart image.
   * @param sourceCanvasOrDataUrl  HTMLCanvasElement or data:image/png;base64,...
   * @param opts.caption            Optional caption rendered below
   * @param opts.maxHeight          Cap height in pt (default 200)
   */
  async function embedChart(sourceCanvasOrDataUrl, { caption = '', maxHeight = 200 } = {}) {
    let dataUrl = ''
    let imgW = 0, imgH = 0
    if (typeof sourceCanvasOrDataUrl === 'string') {
      dataUrl = sourceCanvasOrDataUrl
      // Probe dimensions
      const img = await new Promise(resolve => {
        const i = new Image()
        i.onload = () => resolve(i)
        i.onerror = () => resolve({ width: 0, height: 0 })
        i.src = dataUrl
      })
      imgW = img.width || 0
      imgH = img.height || 0
    } else if (sourceCanvasOrDataUrl && typeof sourceCanvasOrDataUrl.toDataURL === 'function') {
      dataUrl = sourceCanvasOrDataUrl.toDataURL('image/png')
      imgW = sourceCanvasOrDataUrl.width
      imgH = sourceCanvasOrDataUrl.height
    } else {
      return
    }
    if (!dataUrl || !imgW || !imgH) return
    const targetW = PAGE_W - MARGIN * 2
    const aspect = imgH / imgW
    const targetH = Math.min(maxHeight, targetW * aspect)
    ensureSpace(targetH + (caption ? 18 : 4) + 4)
    try {
      doc.addImage(dataUrl, 'PNG', MARGIN, y, targetW, targetH, undefined, 'FAST')
    } catch (err) {
      console.debug('[premiumPdf] addImage failed:', err?.message)
    }
    y += targetH + 4
    if (caption) {
      doc.setFontSize(8.5)
      doc.setFont('helvetica', 'italic')
      doc.setTextColor(...COLOR.muted)
      doc.text(String(caption), MARGIN, y + 10)
      y += 16
    }
  }

  // ── HELPER: footer ────────────────────────────────────────────────────
  function paginate(footerText) {
    const total = doc.internal.getNumberOfPages()
    for (let i = 1; i <= total; i++) {
      doc.setPage(i)
      // Top thin teal rule on continuation pages
      if (i > 1) {
        doc.setFillColor(...COLOR.teal)
        doc.rect(0, 0, PAGE_W, 2, 'F')
      }
      doc.setFontSize(8)
      doc.setTextColor(...COLOR.muted)
      doc.setFont('helvetica', 'normal')
      doc.text(String(footerText || 'Uganda POE Screening'), MARGIN, PAGE_H - 24)
      doc.text(`Page ${i} of ${total}`, PAGE_W - MARGIN, PAGE_H - 24, { align: 'right' })
    }
  }

  // ── HELPER: save ──────────────────────────────────────────────────────
  function save(filename) {
    paginate(`${title || 'Report'} · ${scope?.label || ''}`)
    doc.save(filename || `${(title || 'report').toLowerCase().replace(/\s+/g, '-')}-${Date.now()}.pdf`)
  }

  return {
    raw: doc,
    section,
    kpiGrid,
    barList,
    table,
    note,
    embedChart,
    save,
    /** Direct y-cursor access for advanced views. */
    cursor: () => y,
    setCursor: (v) => { y = v },
  }
}
