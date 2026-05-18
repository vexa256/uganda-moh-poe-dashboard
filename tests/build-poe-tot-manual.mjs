/**
 * tests/build-poe-tot-manual.mjs
 *
 * National Training-of-Trainers manual — POE Sentinel.
 *
 * Self-contained .pptx training manual that teaches a non-technical audience
 * the entire app, role by role, from cold. Wins on Microsoft PowerPoint
 * Awards-grade design, instructional craft, and presentation polish.
 *
 * Source of truth (read-only):
 *   _audit/PRESENTATION/full-app/screenshots/    — 70 PNGs from the capture rig
 *   _audit/PRESENTATION/full-app/INDEX.md         — continuous-prose user-manual
 *   _audit/PRESENTATION/full-app/MANIFEST.json    — per-shot metadata
 *
 * Output:
 *   _audit/PRESENTATION/full-app/POE_Sentinel_TOT_Manual.pptx
 *
 * Build:
 *   node tests/build-poe-tot-manual.mjs
 *
 * Pedagogical spine (every module, no exceptions):
 *   Hook → Objectives → Present → Demonstrate → Practice → Recap
 *
 * Visual posture: non-Zambian, premium, restrained, engineered.
 *   Ink:        #0B1220   deep blue-black, never pure black
 *   Graphite:   #3B4453   secondary type
 *   Accent:     #1F4E79   sophisticated tonal navy (hotspots, active states)
 *   Bronze:     #A87837   refined secondary accent
 *   Paper:      #FBFAF7   warm neutral surface
 *   Cool:       #F4F6FA   cool tonal section break
 *   Amber:      #B45309   warning (low saturation)
 *   Rose:       #B91C1C   alert (low saturation)
 *
 * Typography: Calibri Light (display) + Calibri (body) — Office default that
 * renders gracefully on Keynote (Helvetica) and LibreOffice Impress
 * (Liberation Sans) without font embedding.
 */

import path from 'node:path'
import fs from 'node:fs'
import { fileURLToPath } from 'node:url'
import PptxGenJS from '/tmp/tot-tools/node_modules/pptxgenjs/dist/pptxgen.cjs.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const REPO = path.resolve(__dirname, '..')
const ROOT = path.resolve(REPO, '_audit/PRESENTATION/full-app')
const SHOTS = path.resolve(ROOT, 'screenshots')
const MANIFEST_PATH = path.resolve(ROOT, 'MANIFEST.json')
const OUT_PPTX = path.resolve(ROOT, 'POE_Sentinel_TOT_Manual.pptx')

const exists = (p) => { try { fs.statSync(p); return true } catch { return false } }

// ═══════════════════════════════════════════════════════════════════════════
// PRECONDITION GATE — §1 of the master prompt
// ═══════════════════════════════════════════════════════════════════════════
{
  if (!exists(SHOTS)) {
    console.error(`✗ ${SHOTS} not found. Run the screenshot capture rig first.`)
    process.exit(1)
  }
  if (!exists(MANIFEST_PATH)) {
    console.error(`✗ ${MANIFEST_PATH} not found.`)
    process.exit(1)
  }
  const m = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
  if (m.captured !== m.total) {
    console.error(`✗ Manifest reports ${m.captured}/${m.total} captured. Capture is incomplete.`)
    process.exit(1)
  }
  if ((m.duplicates || []).length > 0) {
    console.error(`✗ Manifest reports ${m.duplicates.length} adjacent-duplicate-after-retry shots. Resolve before building deck.`)
    process.exit(1)
  }
  console.log(`✓ Preconditions: ${m.captured} screenshots, 0 duplicates, manifest valid.`)
}

// ═══════════════════════════════════════════════════════════════════════════
// DESIGN SYSTEM
// ═══════════════════════════════════════════════════════════════════════════

const C = {
  ink900:   '0B1220',
  ink800:   '15212E',
  ink700:   '202C3D',
  graphite: '3B4453',
  ink500:   '5A6678',
  ink300:   '94A3B8',
  ink100:   'CBD5E1',
  ink050:   'EAEEF5',
  paper:    'FBFAF7',
  cool:     'F4F6FA',
  white:    'FFFFFF',
  navy:     '1F4E79',
  navyLight:'3D6FA0',
  navyVeil: 'E5EDF5',
  bronze:   'A87837',
  bronzeLight: 'C9A26F',
  bronzeVeil:  'F4ECDD',
  amber:    'B45309',
  amberVeil:'FEF3C7',
  rose:     'B91C1C',
  roseVeil: 'FEE2E2',
  green:    '15803D',
  greenVeil:'DCFCE7',
}

// Six-step modular type scale (Calibri/Calibri Light), 16:9 deck
const T = {
  hero:    { fontFace: 'Calibri Light', fontSize: 60, charSpacing: 0,  color: C.ink900 },
  display: { fontFace: 'Calibri Light', fontSize: 40, charSpacing: 0,  color: C.ink900 },
  h1:      { fontFace: 'Calibri',       fontSize: 28, bold: true,      color: C.ink900 },
  h2:      { fontFace: 'Calibri',       fontSize: 18, bold: true,      color: C.ink900 },
  lede:    { fontFace: 'Calibri Light', fontSize: 16,                  color: C.graphite },
  body:    { fontFace: 'Calibri',       fontSize: 13,                  color: C.ink800 },
  caption: { fontFace: 'Calibri',       fontSize: 10,                  color: C.graphite },
  micro:   { fontFace: 'Calibri',       fontSize: 9,  charSpacing: 4,  color: C.ink500 },
}

// 16:9 widescreen 13.333 × 7.5 inches; 12-column grid, 0.5" outer margins
const G = {
  M: 0.5,            // outer margin
  W: 13.333,         // slide width
  H: 7.5,            // slide height
  topY: 0.55,        // header band starts
  bodyY: 1.25,       // body starts
  footY: 7.05,       // footer starts
  col: (13.333 - 1.0) / 12, // column width
}

// Phone aspect: a screenshot is 412×915 logical (824×1830 PNG). h/w = 2.221.
const PHONE_ASPECT_HW = 1830 / 824 // 2.2208

// Picture sizing helpers — preserve aspect ratio. Pass target HEIGHT in inches.
function shotByH(hIn) { return { w: +(hIn / PHONE_ASPECT_HW).toFixed(3), h: +hIn.toFixed(3) } }

// ═══════════════════════════════════════════════════════════════════════════
// MANIFEST INDEX
// ═══════════════════════════════════════════════════════════════════════════

const MANIFEST = JSON.parse(fs.readFileSync(MANIFEST_PATH, 'utf8'))
const BY_NUM = Object.fromEntries(MANIFEST.items.map(r => [r.num, r]))
function shot(num) {
  const r = BY_NUM[num]
  if (!r || !r.file) throw new Error(`Missing shot ${num}`)
  return path.join(ROOT, r.file)
}

// ═══════════════════════════════════════════════════════════════════════════
// COMPONENTS — reusable native-vector elements
// ═══════════════════════════════════════════════════════════════════════════

// Thin top accent rule — module marker
function topRule(slide, color = C.navy) {
  slide.addShape('rect', { x: 0, y: 0, w: G.W, h: 0.05, fill: { color }, line: { color } })
}

// Page header band — eyebrow + slide title + page locator
function pageHeader(slide, opts) {
  const { eyebrow, title, locator, accent = C.navy } = opts
  topRule(slide, accent)
  slide.addText('POE SENTINEL · TOT MANUAL', {
    x: G.M, y: 0.18, w: 6, h: 0.28,
    fontFace: 'Calibri', fontSize: 9, color: C.ink500, bold: true, charSpacing: 4,
  })
  if (locator) slide.addText(locator, {
    x: G.W - 6 - G.M, y: 0.18, w: 6, h: 0.28,
    fontFace: 'Calibri', fontSize: 9, color: C.ink500, bold: true, charSpacing: 4, align: 'right',
  })
  if (title) slide.addText(title, { x: G.M, y: 0.55, w: G.W - 2 * G.M, h: 0.55, ...T.h1 })
  if (eyebrow) slide.addText(eyebrow, {
    x: G.M, y: 0.4, w: G.W - 2 * G.M, h: 0.22,
    fontFace: 'Calibri', fontSize: 9, color: accent, bold: true, charSpacing: 6,
  })
}

// Page footer — hairline + module ID + page number
function pageFooter(slide, opts = {}) {
  slide.addShape('line', {
    x: G.M, y: G.footY, w: G.W - 2 * G.M, h: 0,
    line: { color: C.ink100, width: 0.5 },
  })
  slide.addText(opts.left || '', {
    x: G.M, y: G.footY + 0.08, w: 6, h: 0.28,
    fontFace: 'Calibri', fontSize: 9, color: C.ink500,
  })
  slide.addText(opts.right || '', {
    x: G.W - 6 - G.M, y: G.footY + 0.08, w: 6, h: 0.28,
    fontFace: 'Calibri', fontSize: 9, color: C.ink500, italic: true, align: 'right',
  })
}

// Pixel-6 device frame — rounded rect bezel + soft contact shadow.
// Returns the inner image rect so the caller can place the screenshot
// snugly inside the frame.
function deviceFrame(slide, opts) {
  const { x, y, h } = opts
  const inner = shotByH(h)
  const frameW = inner.w + 0.18
  const frameH = inner.h + 0.18
  // Soft contact shadow — single shape, low opacity
  slide.addShape('roundRect', {
    x: x - 0.04, y: y + 0.10, w: frameW + 0.08, h: frameH + 0.04,
    rectRadius: 0.18,
    fill: { color: C.ink900, transparency: 88 },
    line: { color: C.ink900, transparency: 88 },
  })
  // Bezel
  slide.addShape('roundRect', {
    x, y, w: frameW, h: frameH,
    rectRadius: 0.16,
    fill: { color: C.ink900 },
    line: { color: C.ink900 },
  })
  return { x: x + 0.09, y: y + 0.09, w: inner.w, h: inner.h }
}

// Place a screenshot inside a device frame.
function framedShot(slide, opts) {
  const { x, y, h, num, alt } = opts
  const inner = deviceFrame(slide, { x, y, h })
  slide.addImage({
    path: shot(num),
    x: inner.x, y: inner.y, w: inner.w, h: inner.h,
    altText: alt || `Screenshot ${num}`,
    sizing: { type: 'contain', w: inner.w, h: inner.h },
  })
  return inner
}

// Annotation hotspot — accent-coloured filled circle with a white numeral.
// Position is given as a fraction (0..1) of the inner image rect.
function hotspot(slide, opts) {
  const { rect, fx, fy, num, accent = C.navy, size = 0.36 } = opts
  const cx = rect.x + rect.w * fx
  const cy = rect.y + rect.h * fy
  // Outer halo
  slide.addShape('ellipse', {
    x: cx - size / 2 - 0.04, y: cy - size / 2 - 0.04, w: size + 0.08, h: size + 0.08,
    fill: { color: C.white, transparency: 35 },
    line: { color: C.white, transparency: 35 },
  })
  // Filled disk
  slide.addShape('ellipse', {
    x: cx - size / 2, y: cy - size / 2, w: size, h: size,
    fill: { color: accent }, line: { color: accent },
  })
  // Numeral
  slide.addText(String(num), {
    x: cx - size / 2, y: cy - size / 2 - 0.02, w: size, h: size + 0.02,
    fontFace: 'Calibri', fontSize: 13, bold: true, color: C.white,
    align: 'center', valign: 'middle',
  })
}

// Numbered explanation list — paired with hotspots.
function annotations(slide, opts) {
  const { x, y, w, items, accent = C.navy, gap = 0.55 } = opts
  let cy = y
  items.forEach((it, i) => {
    // Numeral disk
    slide.addShape('ellipse', {
      x, y: cy, w: 0.32, h: 0.32,
      fill: { color: accent }, line: { color: accent },
    })
    slide.addText(String(i + 1), {
      x, y: cy - 0.02, w: 0.32, h: 0.34,
      fontFace: 'Calibri', fontSize: 11, bold: true, color: C.white,
      align: 'center', valign: 'middle',
    })
    // Lead + body
    slide.addText([
      { text: it.lead + '   ', options: { fontFace: 'Calibri', fontSize: 12, bold: true, color: C.ink900 } },
      { text: it.body || '',   options: { fontFace: 'Calibri', fontSize: 12, color: C.graphite } },
    ], {
      x: x + 0.45, y: cy - 0.04, w: w - 0.45, h: gap + 0.05,
      valign: 'top', paraSpaceAfter: 4,
    })
    cy += gap
  })
}

// Status badge — pill-shaped chip
function statusBadge(slide, opts) {
  const { x, y, label, kind = 'neutral' } = opts
  const palette = {
    neutral:  { fill: C.ink050,    text: C.graphite },
    accent:   { fill: C.navyVeil,  text: C.navy },
    bronze:   { fill: C.bronzeVeil,text: C.bronze },
    success:  { fill: C.greenVeil, text: C.green },
    warn:     { fill: C.amberVeil, text: C.amber },
    danger:   { fill: C.roseVeil,  text: C.rose },
  }[kind] || { fill: C.ink050, text: C.graphite }
  const w = Math.max(0.7, label.length * 0.085 + 0.3)
  slide.addShape('roundRect', {
    x, y, w, h: 0.28, rectRadius: 0.14,
    fill: { color: palette.fill }, line: { color: palette.fill },
  })
  slide.addText(label.toUpperCase(), {
    x, y, w, h: 0.28,
    fontFace: 'Calibri', fontSize: 9, bold: true, color: palette.text,
    align: 'center', valign: 'middle', charSpacing: 4,
  })
}

// ═══════════════════════════════════════════════════════════════════════════
// SPEAKER NOTES — uniform shape across every body slide
// ═══════════════════════════════════════════════════════════════════════════

function notes(o) {
  const lines = []
  lines.push(`TEACHING POINT — ${o.teach}`)
  lines.push('')
  lines.push('SCRIPT')
  lines.push(o.script)
  lines.push('')
  if (o.retrieve) {
    lines.push('RETRIEVAL PROMPT')
    lines.push(o.retrieve)
    lines.push('')
  }
  lines.push(`TIME — ${o.time || 2} min`)
  if (o.confuse && o.confuse.length) {
    lines.push('')
    lines.push('COMMON CONFUSIONS')
    o.confuse.forEach((c, i) => lines.push(`${i + 1}. ${c}`))
  }
  return lines.join('\n')
}

// ═══════════════════════════════════════════════════════════════════════════
// LAYOUT FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════

// LAYOUT 1 — COVER (full bleed, ink background)
function L_cover(pptx, opts) {
  const s = pptx.addSlide()
  s._name = opts.title || 'Cover'
  s.background = { color: C.ink900 }
  // Subtle decorative orbs
  s.addShape('ellipse', {
    x: -2, y: -2, w: 5, h: 5,
    fill: { color: C.navy, transparency: 80 },
    line: { color: C.navy, transparency: 80 },
  })
  s.addShape('ellipse', {
    x: 9, y: 4.5, w: 6, h: 6,
    fill: { color: C.bronze, transparency: 88 },
    line: { color: C.bronze, transparency: 88 },
  })
  // Top accent rule
  s.addShape('rect', { x: 0, y: 0, w: G.W, h: 0.18, fill: { color: C.navy }, line: { color: C.navy } })

  s.addText('NATIONAL TRAINING-OF-TRAINERS MANUAL', {
    x: G.M, y: 0.7, w: G.W - 2 * G.M, h: 0.4,
    fontFace: 'Calibri', fontSize: 10, color: C.white, transparency: 25, bold: true, charSpacing: 6,
  })
  s.addText(opts.title || 'POE Sentinel', {
    x: G.M, y: 1.7, w: G.W - 2 * G.M, h: 1.6,
    fontFace: 'Calibri Light', fontSize: 80, color: C.white, charSpacing: 2,
  })
  s.addText(opts.subtitle || '', {
    x: G.M, y: 3.3, w: G.W - 2 * G.M, h: 0.8,
    fontFace: 'Calibri Light', fontSize: 26, color: C.bronzeLight, italic: true,
  })
  // Hairline + course meta
  s.addShape('line', {
    x: G.M, y: 5.5, w: 4, h: 0,
    line: { color: C.white, transparency: 60, width: 1 },
  })
  s.addText(opts.audience || 'Course audience: every operator on the screening line.', {
    x: G.M, y: 5.6, w: 6, h: 0.4,
    fontFace: 'Calibri', fontSize: 12, color: C.white, transparency: 20,
  })
  s.addText([
    { text: 'COURSE  ', options: { color: C.bronzeLight, charSpacing: 4 } },
    { text: opts.code || 'POE-TOT-2026', options: { color: C.white } },
    { text: '         VERSION  ', options: { color: C.bronzeLight, charSpacing: 4 } },
    { text: opts.version || '1.0', options: { color: C.white } },
    { text: '         BUILD  ', options: { color: C.bronzeLight, charSpacing: 4 } },
    { text: opts.build || new Date().toISOString().slice(0, 10), options: { color: C.white } },
  ], {
    x: G.M, y: G.H - 0.7, w: G.W - 2 * G.M, h: 0.3,
    fontFace: 'Calibri', fontSize: 10, bold: true,
  })
  s.addNotes('Cover slide. Hold for ten seconds at the start of the course. Then skip directly to the course-overview table.')
  return s
}

// LAYOUT 2 — SECTION DIVIDER (full bleed tonal break, module opener)
function L_section(pptx, opts) {
  const s = pptx.addSlide()
  s._name = `Module ${opts.number} — ${opts.title}`
  s.background = { color: opts.dark ? C.ink900 : C.cool }
  const fg = opts.dark ? C.white : C.ink900
  const accentColor = opts.accent || C.navy

  // Top accent rule
  s.addShape('rect', { x: 0, y: 0, w: G.W, h: 0.18, fill: { color: accentColor }, line: { color: accentColor } })

  // Big module number
  s.addText(opts.number || '00', {
    x: G.M, y: 0.9, w: 4, h: 2.2,
    fontFace: 'Calibri Light', fontSize: 180, color: accentColor, transparency: opts.dark ? 40 : 70,
  })
  // Module label
  s.addText('MODULE', {
    x: G.M, y: 0.7, w: 4, h: 0.3,
    fontFace: 'Calibri', fontSize: 10, color: opts.dark ? C.bronzeLight : C.bronze, bold: true, charSpacing: 6,
  })
  // Title
  s.addText(opts.title || '', {
    x: G.M, y: 3.3, w: G.W - 2 * G.M, h: 1.0,
    fontFace: 'Calibri Light', fontSize: 56, color: fg,
  })
  // Audience line
  s.addText(opts.audience || '', {
    x: G.M, y: 4.4, w: G.W - 2 * G.M, h: 0.4,
    fontFace: 'Calibri', fontSize: 13, color: opts.dark ? C.ink100 : C.ink500, charSpacing: 3, bold: true,
  })
  // Hairline
  s.addShape('line', {
    x: G.M, y: 5.0, w: 4, h: 0,
    line: { color: accentColor, width: 1.5 },
  })
  // Promise
  s.addText(opts.promise || '', {
    x: G.M, y: 5.15, w: 9, h: 1.3,
    fontFace: 'Calibri Light', fontSize: 22, color: fg, italic: true,
  })
  s.addNotes(opts.notes || `Module opener. Hold for five seconds. Read the title and the promise out loud, then move on.`)
  return s
}

// LAYOUT 3 — HOOK (single pull-quote question + scene)
function L_hook(pptx, opts) {
  const s = pptx.addSlide()
  s._name = `${opts.module} — Hook`
  s.background = { color: C.paper }
  topRule(s, opts.accent || C.navy)
  pageHeader(s, { eyebrow: 'HOOK', title: '', locator: opts.locator, accent: opts.accent })

  // Big quote-mark accent
  s.addText('"', {
    x: G.M, y: 0.9, w: 1.5, h: 1.5,
    fontFace: 'Calibri Light', fontSize: 200, color: opts.accent || C.navy, transparency: 60,
  })
  // The question
  s.addText(opts.question || '', {
    x: G.M + 0.3, y: 2.0, w: G.W - 2 * G.M - 0.3, h: 3.0,
    fontFace: 'Calibri Light', fontSize: 38, color: C.ink900,
  })
  // Hairline
  s.addShape('line', {
    x: G.M + 0.3, y: 5.4, w: 4, h: 0,
    line: { color: opts.accent || C.navy, width: 1.5 },
  })
  // The scene
  s.addText(opts.scene || '', {
    x: G.M + 0.3, y: 5.55, w: 9, h: 0.6,
    fontFace: 'Calibri', fontSize: 14, color: C.graphite, italic: true,
  })
  pageFooter(s, { left: opts.module, right: 'Hook' })

  s.addNotes(notes({
    teach: 'A real situation lands the trainee in the scene before any UI appears. The room reads it together; you take silence as engagement.',
    script: opts.script || `Read the question on screen. Wait three seconds. Read the scene. Wait three more seconds. Then ask: "When something like this happens at your line, what is your first move?" Take two answers from the room. Don't correct yet — every answer is allowed at this stage. We're going to watch the app handle this exact case in the next ten slides.`,
    retrieve: 'In your own words, when does a primary screener decide to raise a referral?',
    time: 3,
    confuse: ['Trainees may try to skip ahead and "answer" the question with the app — gently remind them the scene is for orientation, not capture.'],
  }))
  return s
}

// LAYOUT 4 — OBJECTIVES (3-5 numbered objectives with Bloom verbs)
function L_objectives(pptx, opts) {
  const s = pptx.addSlide()
  s._name = `${opts.module} — Objectives`
  s.background = { color: C.paper }
  pageHeader(s, { eyebrow: 'OBJECTIVES', title: opts.title || 'By the end of this module, you will be able to', locator: opts.locator, accent: opts.accent })

  const items = opts.items || []
  const startY = 1.7
  const rowH = (G.H - startY - 1.2) / Math.max(items.length, 1)
  items.forEach((text, i) => {
    const y = startY + i * rowH
    // Number
    s.addText(String(i + 1).padStart(2, '0'), {
      x: G.M, y, w: 1.2, h: rowH - 0.1,
      fontFace: 'Calibri Light', fontSize: 56, color: opts.accent || C.navy, transparency: 50, valign: 'middle',
    })
    // Hairline
    s.addShape('line', {
      x: G.M + 1.4, y: y + rowH * 0.5, w: 0.4, h: 0,
      line: { color: C.ink100, width: 1 },
    })
    // Body
    s.addText(text, {
      x: G.M + 2.0, y, w: G.W - 2 * G.M - 2.0, h: rowH - 0.1,
      fontFace: 'Calibri', fontSize: 18, color: C.ink900, valign: 'middle',
    })
  })
  pageFooter(s, { left: opts.module, right: 'Objectives' })

  s.addNotes(notes({
    teach: 'Objectives are observable, measurable, role-specific. Read each aloud. Trainees should be able to demonstrate every objective by the end of the module.',
    script: `Read each objective aloud, slowly. After each one, pause for one second. Don't elaborate yet — the next slides do that. At the end of the list say: "By the time we close this module you should be able to do every one of these. We will come back and check."`,
    time: 2,
    confuse: ['Some trainees treat objectives as optional reading. Tell the room "we will hold a quick recap at the end against this exact list."'],
  }))
  return s
}

// LAYOUT 5 — PRESENT (1 framed shot + numbered hotspots + numbered explanations)
function L_present(pptx, opts) {
  const s = pptx.addSlide()
  s._name = `${opts.module} — ${opts.title}`
  s.background = { color: C.paper }
  pageHeader(s, { eyebrow: 'PRESENT', title: opts.title, locator: opts.locator, accent: opts.accent })

  // Phone on the left, larger — h = 5.0" inches
  const phoneH = 5.4
  const phoneX = G.M + 0.2
  const phoneY = G.bodyY + 0.15
  const inner = framedShot(s, { x: phoneX, y: phoneY, h: phoneH, num: opts.shotNum, alt: opts.alt })

  // Hotspots
  if (opts.hotspots) {
    opts.hotspots.forEach(h => hotspot(s, { rect: inner, fx: h.fx, fy: h.fy, num: h.n, accent: opts.accent }))
  }

  // Numbered annotations on the right
  const annoX = phoneX + inner.w + 0.7
  const annoW = G.W - annoX - G.M
  if (opts.intro) {
    s.addText(opts.intro, {
      x: annoX, y: G.bodyY + 0.1, w: annoW, h: 0.6,
      ...T.lede,
    })
  }
  annotations(s, {
    x: annoX, y: G.bodyY + 0.85, w: annoW,
    items: opts.items || [], accent: opts.accent || C.navy, gap: 0.62,
  })

  pageFooter(s, { left: opts.module, right: opts.locator || '' })
  s.addNotes(opts.notes || notes({
    teach: opts.teach || 'Walk the room around the screen one element at a time. Each numbered hotspot ties to a numbered bullet on the right.',
    script: opts.script || `Point to the screen. Read each numbered annotation in order; pause after each one. Trainees should follow the hotspot order, not the visual order.`,
    retrieve: opts.retrieve,
    time: opts.time || 3,
    confuse: opts.confuse,
  }))
  return s
}

// LAYOUT 6 — DEMONSTRATE / WALKTHROUGH (1 framed shot + a single narrative paragraph)
function L_demo(pptx, opts) {
  const s = pptx.addSlide()
  s._name = `${opts.module} — ${opts.title}`
  s.background = { color: C.paper }
  pageHeader(s, { eyebrow: 'DEMONSTRATE', title: opts.title, locator: opts.locator, accent: opts.accent })

  const phoneH = 5.4
  const phoneX = G.M + 0.2
  const phoneY = G.bodyY + 0.15
  framedShot(s, { x: phoneX, y: phoneY, h: phoneH, num: opts.shotNum, alt: opts.alt })

  const annoX = phoneX + shotByH(phoneH).w + 0.9
  const annoW = G.W - annoX - G.M

  // Beat label
  s.addText(opts.beat || 'BEAT', {
    x: annoX, y: G.bodyY + 0.15, w: annoW, h: 0.3,
    fontFace: 'Calibri', fontSize: 10, color: opts.accent || C.navy, bold: true, charSpacing: 6,
  })
  // What just happened
  s.addText(opts.lead || '', {
    x: annoX, y: G.bodyY + 0.45, w: annoW, h: 1.0,
    fontFace: 'Calibri Light', fontSize: 22, color: C.ink900,
  })
  // Body paragraphs
  if (opts.body) {
    s.addText(opts.body, {
      x: annoX, y: G.bodyY + 1.7, w: annoW, h: 4.5,
      fontFace: 'Calibri', fontSize: 12, color: C.ink800, valign: 'top', paraSpaceAfter: 8,
    })
  }
  // Optional badges row
  if (opts.badges && opts.badges.length) {
    let bx = annoX
    const by = G.bodyY + 5.6
    opts.badges.forEach(b => {
      statusBadge(s, { x: bx, y: by, label: b.label, kind: b.kind })
      bx += Math.max(0.8, b.label.length * 0.085 + 0.4)
    })
  }

  pageFooter(s, { left: opts.module, right: opts.locator || '' })
  s.addNotes(opts.notes || notes({
    teach: opts.teach || 'Demonstrate the case advancing one beat at a time.',
    script: opts.script || `Read the BEAT label and the lead sentence. Then read the body paragraph. Pause; ask the room what they would do next.`,
    retrieve: opts.retrieve,
    time: opts.time || 2,
    confuse: opts.confuse,
  }))
  return s
}

// LAYOUT 7 — STRIP (3-4 framed shots in a row, captions below)
function L_strip(pptx, opts) {
  const s = pptx.addSlide()
  s._name = `${opts.module} — ${opts.title}`
  s.background = { color: C.paper }
  pageHeader(s, { eyebrow: opts.eyebrow || 'COMPARE', title: opts.title, locator: opts.locator, accent: opts.accent })

  const shots = opts.shots || []
  const n = shots.length
  if (opts.intro) {
    s.addText(opts.intro, { x: G.M, y: G.bodyY + 0.05, w: G.W - 2 * G.M, h: 0.6, ...T.lede })
  }
  const stripY = G.bodyY + (opts.intro ? 0.85 : 0.2)
  const stripH = 4.6
  const phoneH = stripH
  const phoneW = shotByH(phoneH).w
  const totalShots = n
  const totalW = totalShots * phoneW + (totalShots - 1) * 0.4
  let x = (G.W - totalW) / 2
  shots.forEach((sh, i) => {
    framedShot(s, { x, y: stripY, h: phoneH, num: sh.num, alt: sh.caption })
    // Numeral above
    s.addShape('ellipse', {
      x: x + phoneW / 2 - 0.18, y: stripY - 0.36, w: 0.36, h: 0.36,
      fill: { color: opts.accent || C.navy }, line: { color: opts.accent || C.navy },
    })
    s.addText(String(i + 1), {
      x: x + phoneW / 2 - 0.18, y: stripY - 0.38, w: 0.36, h: 0.4,
      fontFace: 'Calibri', fontSize: 12, bold: true, color: C.white,
      align: 'center', valign: 'middle',
    })
    // Caption below
    s.addText(sh.caption || '', {
      x: x - 0.2, y: stripY + phoneH + 0.3, w: phoneW + 0.4, h: 0.7,
      fontFace: 'Calibri', fontSize: 11, color: C.ink900, align: 'center', valign: 'top',
    })
    x += phoneW + 0.4
  })
  pageFooter(s, { left: opts.module, right: opts.locator || '' })
  s.addNotes(opts.notes || notes({
    teach: opts.teach || 'A comparative strip shows the same surface in successive states. Read left to right.',
    script: opts.script || `Walk left to right. For each panel say what changed since the previous one. Don't skip any.`,
    retrieve: opts.retrieve,
    time: opts.time || 3,
    confuse: opts.confuse,
  }))
  return s
}

// LAYOUT 8 — PRACTICE (scenario card + trainer prompts)
function L_practice(pptx, opts) {
  const s = pptx.addSlide()
  s._name = `${opts.module} — Practice`
  s.background = { color: C.paper }
  pageHeader(s, { eyebrow: 'PRACTICE', title: opts.title || 'A scenario for the room', locator: opts.locator, accent: opts.accent })

  // Scenario card — wide tonal block
  const cardX = G.M
  const cardY = G.bodyY + 0.2
  const cardW = G.W - 2 * G.M
  const cardH = 2.8
  s.addShape('roundRect', {
    x: cardX, y: cardY, w: cardW, h: cardH, rectRadius: 0.12,
    fill: { color: opts.accent === C.bronze ? C.bronzeVeil : C.navyVeil },
    line: { color: opts.accent === C.bronze ? C.bronzeVeil : C.navyVeil },
  })
  s.addText('SCENARIO', {
    x: cardX + 0.4, y: cardY + 0.3, w: cardW - 0.8, h: 0.3,
    fontFace: 'Calibri', fontSize: 10, color: opts.accent || C.navy, bold: true, charSpacing: 6,
  })
  s.addText(opts.scenario || '', {
    x: cardX + 0.4, y: cardY + 0.6, w: cardW - 0.8, h: cardH - 0.8,
    fontFace: 'Calibri Light', fontSize: 22, color: C.ink900,
  })

  // Prompts list below
  s.addText('TRAINER PROMPTS', {
    x: G.M, y: cardY + cardH + 0.4, w: cardW, h: 0.3,
    fontFace: 'Calibri', fontSize: 10, color: opts.accent || C.navy, bold: true, charSpacing: 6,
  })
  const prompts = opts.prompts || []
  prompts.forEach((p, i) => {
    const py = cardY + cardH + 0.8 + i * 0.45
    s.addText(`${i + 1}.`, {
      x: G.M, y: py, w: 0.5, h: 0.4,
      fontFace: 'Calibri', fontSize: 12, bold: true, color: opts.accent || C.navy,
    })
    s.addText(p, {
      x: G.M + 0.5, y: py, w: cardW - 0.5, h: 0.4,
      fontFace: 'Calibri', fontSize: 12, color: C.ink800,
    })
  })
  pageFooter(s, { left: opts.module, right: 'Practice' })
  s.addNotes(opts.notes || notes({
    teach: opts.teach || 'Practice gives the room time to handle the workflow themselves with the trainer guiding.',
    script: opts.script || `Read the scenario aloud. Then give the room ${opts.minutes || 5} minutes to do the workflow on their own devices, following the trainer prompts. Walk between trainees and observe — don't intervene unless someone is stuck.`,
    retrieve: opts.retrieve,
    time: opts.minutes || 6,
    confuse: opts.confuse,
  }))
  return s
}

// LAYOUT 9 — RECAP (5-8 retrieval-practice questions)
function L_recap(pptx, opts) {
  const s = pptx.addSlide()
  s._name = `${opts.module} — Recap`
  s.background = { color: C.paper }
  pageHeader(s, { eyebrow: 'RECAP', title: opts.title || 'Check the room', locator: opts.locator, accent: opts.accent })

  s.addText('Ask each question. Take one answer from the room. Confirm the right answer before moving to the next.', {
    x: G.M, y: G.bodyY + 0.05, w: G.W - 2 * G.M, h: 0.6, ...T.lede, italic: true,
  })

  const items = opts.items || []
  const cols = items.length > 5 ? 2 : 1
  const rows = Math.ceil(items.length / cols)
  const colW = (G.W - 2 * G.M - (cols - 1) * 0.4) / cols
  const startY = G.bodyY + 0.95
  const rowH = Math.min(0.85, (G.H - startY - 1.2) / Math.max(rows, 1))
  items.forEach((q, i) => {
    const c = i % cols
    const r = Math.floor(i / cols)
    const x = G.M + c * (colW + 0.4)
    const y = startY + r * rowH
    s.addShape('ellipse', {
      x, y: y + 0.05, w: 0.32, h: 0.32,
      fill: { color: opts.accent || C.navy }, line: { color: opts.accent || C.navy },
    })
    s.addText(String(i + 1), {
      x, y: y + 0.03, w: 0.32, h: 0.34,
      fontFace: 'Calibri', fontSize: 11, bold: true, color: C.white,
      align: 'center', valign: 'middle',
    })
    s.addText(q, {
      x: x + 0.45, y, w: colW - 0.45, h: rowH,
      fontFace: 'Calibri', fontSize: 13, color: C.ink900, valign: 'top',
    })
  })
  pageFooter(s, { left: opts.module, right: 'Recap' })
  s.addNotes(opts.notes || notes({
    teach: opts.teach || 'Retrieval practice strengthens learning more than re-reading. Every question can be answered from the slides we just walked through.',
    script: opts.script || `Read each question aloud. Take one answer from the room. If the answer is wrong, do not correct directly — instead ask another trainee to add. Once the right answer is on the table, summarise it in one sentence and move to the next question.`,
    time: opts.time || 5,
    confuse: ['Some trainers ask "any questions?" instead of doing the recap. That tests confidence, not knowledge. Stick to the script.'],
  }))
  return s
}

// LAYOUT 10 — TABLE (course overview, roles matrix)
function L_table(pptx, opts) {
  const s = pptx.addSlide()
  s._name = opts.title || 'Table'
  s.background = { color: C.paper }
  pageHeader(s, { eyebrow: opts.eyebrow || 'OVERVIEW', title: opts.title, locator: opts.locator, accent: opts.accent })
  if (opts.intro) {
    s.addText(opts.intro, { x: G.M, y: G.bodyY + 0.05, w: G.W - 2 * G.M, h: 0.6, ...T.lede })
  }

  const startY = G.bodyY + (opts.intro ? 0.85 : 0.2)
  const headers = opts.headers || []
  const rows = opts.rows || []
  const widths = opts.widths || headers.map(() => 1)
  const totalUnits = widths.reduce((a, b) => a + b, 0)
  const tableW = G.W - 2 * G.M
  const colXs = []
  let cx = G.M
  widths.forEach(w => { colXs.push(cx); cx += (w / totalUnits) * tableW })
  // Header row
  const headerH = 0.4
  s.addShape('rect', {
    x: G.M, y: startY, w: tableW, h: headerH,
    fill: { color: opts.accent || C.navy }, line: { color: opts.accent || C.navy },
  })
  headers.forEach((h, i) => {
    const w = (widths[i] / totalUnits) * tableW
    s.addText(h.toUpperCase(), {
      x: colXs[i] + 0.15, y: startY, w: w - 0.3, h: headerH,
      fontFace: 'Calibri', fontSize: 10, bold: true, color: C.white, charSpacing: 4, valign: 'middle',
    })
  })
  // Body rows
  const bodyY = startY + headerH
  const rowH = Math.min(0.55, (G.H - bodyY - 1.2) / Math.max(rows.length, 1))
  rows.forEach((row, ri) => {
    const y = bodyY + ri * rowH
    if (ri % 2 === 0) {
      s.addShape('rect', { x: G.M, y, w: tableW, h: rowH, fill: { color: C.cool }, line: { color: C.cool } })
    }
    row.forEach((cell, ci) => {
      const w = (widths[ci] / totalUnits) * tableW
      s.addText(cell, {
        x: colXs[ci] + 0.15, y, w: w - 0.3, h: rowH,
        fontFace: 'Calibri', fontSize: 11, color: C.ink900, valign: 'middle',
      })
    })
  })
  pageFooter(s, { left: opts.module || '', right: opts.locator || '' })
  s.addNotes(opts.notes || `Reference table — read the headers, then run a finger down the column the trainee belongs to.`)
  return s
}

// LAYOUT 11 — REFERENCE (status badge card / pocket reference)
function L_reference(pptx, opts) {
  const s = pptx.addSlide()
  s._name = opts.title || 'Reference'
  s.background = { color: C.paper }
  pageHeader(s, { eyebrow: 'POCKET REFERENCE', title: opts.title, locator: opts.locator, accent: opts.accent })
  if (opts.intro) {
    s.addText(opts.intro, { x: G.M, y: G.bodyY + 0.05, w: G.W - 2 * G.M, h: 0.6, ...T.lede })
  }
  const groups = opts.groups || []
  const startY = G.bodyY + (opts.intro ? 0.9 : 0.2)
  const colW = (G.W - 2 * G.M - 0.6) / 2
  groups.forEach((g, gi) => {
    const x = G.M + (gi % 2) * (colW + 0.6)
    const rowOffset = Math.floor(gi / 2)
    const y = startY + rowOffset * 2.6
    s.addText(g.title, {
      x, y, w: colW, h: 0.3,
      fontFace: 'Calibri', fontSize: 10, color: opts.accent || C.navy, bold: true, charSpacing: 6,
    })
    g.items.forEach((it, i) => {
      const iy = y + 0.4 + i * 0.4
      statusBadge(s, { x, y: iy, label: it.label, kind: it.kind })
      s.addText(it.meaning, {
        x: x + 1.6, y: iy - 0.02, w: colW - 1.6, h: 0.4,
        fontFace: 'Calibri', fontSize: 11, color: C.ink800, valign: 'middle',
      })
    })
  })
  pageFooter(s, { left: opts.module || '', right: 'Pocket reference' })
  s.addNotes(opts.notes || `Pocket reference — the trainee can keep this slide on their phone or print it. Every status badge in the app is named here in plain language.`)
  return s
}

// LAYOUT 12 — CLOSING (next steps + resources)
function L_closing(pptx, opts) {
  const s = pptx.addSlide()
  s._name = opts.title || 'Closing'
  s.background = { color: C.paper }
  pageHeader(s, { eyebrow: 'CLOSING', title: opts.title, locator: opts.locator, accent: opts.accent })
  s.addText(opts.lead || '', {
    x: G.M, y: G.bodyY + 0.1, w: G.W - 2 * G.M, h: 0.8,
    fontFace: 'Calibri Light', fontSize: 26, color: C.ink900,
  })
  // Two columns: next steps / where to get help
  const colW = (G.W - 2 * G.M - 0.6) / 2
  const colY = G.bodyY + 1.2
  ;['Next steps', 'Where to get help'].forEach((t, i) => {
    const x = G.M + i * (colW + 0.6)
    s.addText(t, {
      x, y: colY, w: colW, h: 0.3,
      fontFace: 'Calibri', fontSize: 10, color: opts.accent || C.navy, bold: true, charSpacing: 6,
    })
    const items = i === 0 ? opts.next : opts.help
    ;(items || []).forEach((it, j) => {
      const iy = colY + 0.5 + j * 0.55
      s.addShape('ellipse', {
        x, y: iy + 0.05, w: 0.2, h: 0.2,
        fill: { color: opts.accent || C.navy }, line: { color: opts.accent || C.navy },
      })
      s.addText(it, {
        x: x + 0.35, y: iy, w: colW - 0.35, h: 0.5,
        fontFace: 'Calibri', fontSize: 13, color: C.ink900, valign: 'top',
      })
    })
  })
  pageFooter(s, { left: opts.module || '', right: 'Closing' })
  s.addNotes(opts.notes || `Closing slide. Read the lead. Read the next steps. Read where to get help. Then thank the room.`)
  return s
}

// LAYOUT 13 — BACK COVER (version, build, contact)
function L_backCover(pptx, opts) {
  const s = pptx.addSlide()
  s._name = 'Back cover'
  s.background = { color: C.ink900 }
  // Top accent rule
  s.addShape('rect', { x: 0, y: 0, w: G.W, h: 0.18, fill: { color: C.bronze }, line: { color: C.bronze } })
  s.addText('END OF MANUAL', {
    x: G.M, y: 0.7, w: G.W - 2 * G.M, h: 0.4,
    fontFace: 'Calibri', fontSize: 10, color: C.bronzeLight, bold: true, charSpacing: 6,
  })
  s.addText('POE Sentinel', {
    x: G.M, y: 1.6, w: G.W - 2 * G.M, h: 1.2,
    fontFace: 'Calibri Light', fontSize: 56, color: C.white,
  })
  s.addText(opts.tagline || 'Train one. Train ten. Train the country.', {
    x: G.M, y: 3.0, w: G.W - 2 * G.M, h: 0.6,
    fontFace: 'Calibri Light', fontSize: 22, color: C.bronzeLight, italic: true,
  })
  s.addShape('line', {
    x: G.M, y: 5.0, w: 4, h: 0,
    line: { color: C.white, transparency: 60, width: 1 },
  })
  s.addText([
    { text: 'VERSION  ', options: { color: C.bronzeLight, charSpacing: 4 } },
    { text: opts.version || '1.0', options: { color: C.white } },
    { text: '         BUILD  ', options: { color: C.bronzeLight, charSpacing: 4 } },
    { text: opts.build || new Date().toISOString().slice(0, 10), options: { color: C.white } },
  ], {
    x: G.M, y: 5.2, w: G.W - 2 * G.M, h: 0.4,
    fontFace: 'Calibri', fontSize: 11, bold: true,
  })
  s.addText(opts.contact || 'For corrections, additions, and translations: speak to your national programme office.', {
    x: G.M, y: 5.7, w: G.W - 2 * G.M, h: 0.5,
    fontFace: 'Calibri Light', fontSize: 14, color: C.ink100,
  })
  s.addNotes('Back cover. Hold for ten seconds. Thank the room.')
  return s
}

// LAYOUT 14 — TEXT-ONLY ESSAY (used for "How to use this manual" and similar)
function L_essay(pptx, opts) {
  const s = pptx.addSlide()
  s._name = opts.title || 'Essay'
  s.background = { color: C.paper }
  pageHeader(s, { eyebrow: opts.eyebrow || 'GUIDE', title: opts.title, locator: opts.locator, accent: opts.accent })
  if (opts.lead) {
    s.addText(opts.lead, {
      x: G.M, y: G.bodyY + 0.1, w: G.W - 2 * G.M, h: 0.7,
      fontFace: 'Calibri Light', fontSize: 22, color: C.ink900,
    })
  }
  const sections = opts.sections || []
  const startY = G.bodyY + (opts.lead ? 1.0 : 0.2)
  const cols = sections.length <= 2 ? sections.length : 2
  const rows = Math.ceil(sections.length / cols)
  const colW = (G.W - 2 * G.M - 0.6) / cols
  const rowH = (G.H - startY - 1.0) / rows
  sections.forEach((sec, i) => {
    const c = i % cols
    const r = Math.floor(i / cols)
    const x = G.M + c * (colW + 0.6)
    const y = startY + r * rowH
    s.addShape('line', {
      x, y, w: 0.4, h: 0,
      line: { color: opts.accent || C.navy, width: 1.5 },
    })
    s.addText(sec.title, {
      x, y: y + 0.1, w: colW, h: 0.4,
      fontFace: 'Calibri', fontSize: 13, color: opts.accent || C.navy, bold: true, charSpacing: 4,
    })
    s.addText(sec.body, {
      x, y: y + 0.55, w: colW, h: rowH - 0.6,
      fontFace: 'Calibri', fontSize: 12, color: C.ink800, paraSpaceAfter: 6,
    })
  })
  pageFooter(s, { left: opts.module || '', right: opts.locator || '' })
  s.addNotes(opts.notes || `Read the lead aloud, then walk the room across the four sections.`)
  return s
}

// LAYOUT 15 — DATA-FLOW STAGE (one beat in Joseph's case across modules)
function L_flowBeat(pptx, opts) {
  const s = pptx.addSlide()
  s._name = `Data Flow — ${opts.beat}`
  s.background = { color: C.paper }
  pageHeader(s, { eyebrow: 'DATA FLOW', title: opts.title, locator: opts.locator, accent: opts.accent })

  // Stage indicator
  const stages = ['Capture', 'Refer', 'Investigate', 'Analyse', 'Decide', 'Notify', 'Close']
  const sx = G.M, sy = G.bodyY + 0.05
  const stageW = (G.W - 2 * G.M) / stages.length
  stages.forEach((st, i) => {
    const x = sx + i * stageW
    const isCurrent = st.toUpperCase() === (opts.stage || '').toUpperCase()
    s.addShape('rect', {
      x: x + 0.1, y: sy + 0.18, w: stageW - 0.2, h: 0.05,
      fill: { color: isCurrent ? (opts.accent || C.navy) : C.ink100 },
      line: { color: isCurrent ? (opts.accent || C.navy) : C.ink100 },
    })
    s.addText(st, {
      x, y: sy + 0.3, w: stageW, h: 0.3,
      fontFace: 'Calibri', fontSize: 10, bold: isCurrent, color: isCurrent ? (opts.accent || C.navy) : C.ink500,
      align: 'center', charSpacing: 4,
    })
  })

  // Phone left, narrative right
  const phoneH = 4.6
  const phoneX = G.M + 0.1
  const phoneY = G.bodyY + 1.1
  framedShot(s, { x: phoneX, y: phoneY, h: phoneH, num: opts.shotNum, alt: opts.alt })

  const annoX = phoneX + shotByH(phoneH).w + 0.9
  const annoW = G.W - annoX - G.M
  s.addText(opts.beat || 'BEAT', {
    x: annoX, y: phoneY, w: annoW, h: 0.3,
    fontFace: 'Calibri', fontSize: 10, color: opts.accent || C.navy, bold: true, charSpacing: 6,
  })
  s.addText(opts.lead || '', {
    x: annoX, y: phoneY + 0.3, w: annoW, h: 1.0,
    fontFace: 'Calibri Light', fontSize: 22, color: C.ink900,
  })
  s.addText(opts.body || '', {
    x: annoX, y: phoneY + 1.5, w: annoW, h: phoneH - 1.5,
    fontFace: 'Calibri', fontSize: 12, color: C.ink800, paraSpaceAfter: 8,
  })
  // Hand-off line
  if (opts.handoff) {
    s.addText('HAND-OFF', {
      x: annoX, y: phoneY + phoneH - 0.7, w: annoW, h: 0.3,
      fontFace: 'Calibri', fontSize: 9, color: C.bronze, bold: true, charSpacing: 6,
    })
    s.addText(opts.handoff, {
      x: annoX, y: phoneY + phoneH - 0.4, w: annoW, h: 0.4,
      fontFace: 'Calibri', fontSize: 12, color: C.ink800, italic: true,
    })
  }
  pageFooter(s, { left: opts.module || 'Module 10', right: opts.locator || '' })
  s.addNotes(opts.notes || notes({
    teach: opts.teach || `One beat in Joseph Phiri's case as it advances across the system.`,
    script: opts.script || `Point to the stage indicator at the top so the room sees where we are. Then read the BEAT label, the lead, and the body. Read the hand-off out loud — that is the moment the case leaves one role and enters the next.`,
    time: 2,
    confuse: opts.confuse,
  }))
  return s
}

// ═══════════════════════════════════════════════════════════════════════════
// DECK BUILD
// ═══════════════════════════════════════════════════════════════════════════

const pptx = new PptxGenJS()
pptx.layout = 'LAYOUT_WIDE'                          // 13.333 × 7.5 inches (16:9)
pptx.title = 'POE Sentinel — National TOT Manual'
pptx.author = 'POE Sentinel programme'
pptx.subject = 'Training of Trainers — full app'
pptx.company = 'National Public Health Programme'

// ── 1. FRONT MATTER ────────────────────────────────────────────────────────

L_cover(pptx, {
  title: 'POE Sentinel',
  subtitle: 'A point-of-entry screening manual for the screener, the health officer, the supervisor, the PHEOC, the national administrator, and the data officer.',
  audience: 'Audience: every operator on the screening line and every coordinator above it.',
  code: 'POE-TOT-2026',
  version: '1.0',
  build: new Date().toISOString().slice(0, 10),
})

L_table(pptx, {
  module: 'Front matter',
  title: 'Course overview',
  intro: 'The manual is twelve modules long. Read it in order the first time. After that, any module is self-contained — the speaker notes carry the lesson.',
  headers: ['Module', 'Title', 'Audience', 'Duration', 'Prerequisites'],
  widths: [0.7, 2.0, 1.7, 0.8, 1.6],
  rows: [
    ['01', 'Front matter and roles overview', 'Everyone', '15 min', '—'],
    ['02', 'Welcome and the big picture', 'Everyone', '20 min', '01'],
    ['03', 'For the screener', 'Screening officers', '60 min', '02'],
    ['04', 'For the health officer', 'Secondary investigation officers', '90 min', '02'],
    ['05', 'For the district supervisor', 'District supervisors', '40 min', '02'],
    ['06', 'For the PHEOC officer', 'Province-level officers', '40 min', '02'],
    ['07', 'For the national administrator', 'National-level administrators', '50 min', '02'],
    ['08', 'For the data officer', 'Data officers and reporters', '50 min', '02'],
    ['09', 'Cross-cutting features', 'Everyone', '40 min', '02'],
    ['10', 'Complete data flow', 'Everyone', '30 min', '03–08'],
    ['11', 'For developers and system administrators', 'Developers', '60 min', '02, 09'],
    ['12', 'Closing and resources', 'Everyone', '10 min', 'All'],
  ],
})

L_essay(pptx, {
  module: 'Front matter',
  title: 'How to use this manual',
  lead: 'The deck is the trainer. Every body slide carries speaker notes that read aloud cleanly. A junior trainer who has never seen the app can teach a competent class.',
  sections: [
    {
      title: 'Pace yourself',
      body: 'Each body slide is two to three minutes. The recap slide at the end of every module is five minutes. Module openers are silence-with-a-promise — hold them, do not narrate. The course is paced to a half-day (front matter through module four) and a full day (every module).',
    },
    {
      title: 'Trust the rhythm',
      body: 'Every module follows the same order: hook, then objectives, then present, then demonstrate, then practice, then recap. After the second module the room learns the rhythm and starts to anticipate the recap — that is the design.',
    },
    {
      title: 'Read the speaker notes',
      body: 'The notes carry the script, the teaching point, the retrieval prompt, the time estimate, and the common confusions. The script is meant to be read aloud verbatim if the trainer prefers — and improvised if the trainer is comfortable.',
    },
    {
      title: 'Plain language is the rule',
      body: 'The deck never uses "IHR", "Annex 2", "schema", "idempotency", or "WHO" in front-line modules. Those words appear only in module eleven. Trainers should not introduce them earlier — the front-line user does not need them and they break the rhythm.',
    },
  ],
})

L_table(pptx, {
  module: 'Front matter',
  title: 'Roles overview',
  intro: 'Every role does some things and explicitly does not do others. The hand-off between roles is where most field problems begin — it has to be clean.',
  headers: ['Role', 'What they do', 'What they do not', 'Hand-off to', 'Primary surface'],
  widths: [1.2, 2.5, 1.7, 1.4, 1.6],
  rows: [
    ['Screener', 'Capture every traveller. Refer the symptomatic.', 'Diagnose. Decide where to send.', 'Health officer (referral)', 'Primary Screening'],
    ['Health officer', 'Investigate referrals. Disposition cases.', 'Capture primary screenings.', 'District / PHEOC (route)', 'Secondary Screening wizard'],
    ['District supervisor', 'Watch alerts at district level. Acknowledge.', 'Capture or investigate.', 'PHEOC (escalate)', 'Active alerts and history'],
    ['PHEOC officer', 'Province-level alert response. Escalate to national.', 'District triage.', 'National administrator', 'Alert intelligence'],
    ['National administrator', 'Country-wide oversight. Configure templates and contacts.', 'Front-line capture.', 'Programme leadership', 'Screening intelligence + admin'],
    ['Data officer', 'File aggregated reports on schedule.', 'Capture or investigate.', 'National administrator', 'Aggregated hub + wizard'],
  ],
})

// ── 2. WELCOME / THE BIG PICTURE ───────────────────────────────────────────

L_section(pptx, {
  number: '02',
  title: 'Welcome and the big picture',
  audience: 'EVERYONE — SCREENERS, HEALTH OFFICERS, SUPERVISORS, ADMINISTRATORS, DATA OFFICERS',
  promise: 'See the whole app once before zooming into your role.',
  accent: C.navy,
  dark: true,
})
L_hook(pptx, {
  module: 'Module 02',
  accent: C.navy,
  question: 'You sign in for the first time. The screen says "National oversight is active." Who are you, and what should be the first thing you tap?',
  scene: 'Day one of a new device, anywhere in the country.',
  locator: 'Module 02 · Hook',
})
L_objectives(pptx, {
  module: 'Module 02',
  accent: C.navy,
  locator: 'Module 02 · Objectives',
  items: [
    'Recognise the home dashboard and the side menu, and explain what each entry leads to.',
    'Identify which features are visible to your role and which are hidden.',
    'Open the /welcome view and read the role-specific guidance it carries.',
    'Recall the four facts that drive every primary screening.',
    'Describe the five status words the app uses everywhere.',
  ],
})
L_present(pptx, {
  module: 'Module 02',
  accent: C.navy,
  title: 'The home dashboard at a glance',
  locator: 'Module 02 · Present 1 of 2',
  shotNum: '006',
  intro: 'Every user lands here on sign-in. The page is a personal launchpad — figures refresh on a thirty-second timer. The /welcome route in the side menu opens the same dashboard.',
  hotspots: [
    { fx: 0.50, fy: 0.03, n: 1 },
    { fx: 0.50, fy: 0.18, n: 2 },
    { fx: 0.50, fy: 0.40, n: 3 },
    { fx: 0.50, fy: 0.65, n: 4 },
    { fx: 0.50, fy: 0.92, n: 5 },
  ],
  items: [
    { lead: 'Header strip',          body: 'Site code (LUSKIA), role banner (POE ADMIN), and an Online / Working offline indicator on the right.' },
    { lead: 'Screened Today + ring', body: '"Screened Today 0 / +0 vs yesterday" with a hero ring, alongside small counters: Screenings per day, Last 7 Days, Lowest day, Busiest day.' },
    { lead: 'Urgent attention banner', body: 'A red strip pinned above the action row when criticals are open ("2 CRITICAL alerts require immediate attention"). Dismissable but reappears on next refresh.' },
    { lead: 'Action triptych and tiles', body: 'Open Referrals / Open investigations / Health alerts in plain numbers; below them, big tiles for Primary Screening and Secondary Queue, then small tiles for Records, Intelligence, Alerts.' },
    { lead: 'Sync Health and Recent Activity', body: '"All synced" / per-store counters and a Recent Activity feed with an "Updated X minutes ago" freshness stamp and refresh button.' },
  ],
  notes: notes({
    teach: 'The home dashboard is the user\'s personal launchpad. Every figure is plain language, every counter refreshes automatically.',
    script: `Read the five numbered annotations on the right in order. Pause after each one for two seconds — let the trainees match the number on the screen to the bullet. After number five, ask the room: "If the urgent-attention banner is dismissed, when does it come back?" Answer: on the next thirty-second refresh, if criticals are still open.`,
    retrieve: 'Where does the user check whether the device is online?',
    time: 4,
    confuse: ['Trainees may try to dismiss the urgent-attention banner thinking it disappears for good. It does not — it reappears on the next refresh while the criticals remain open.'],
  }),
})
L_present(pptx, {
  module: 'Module 02',
  accent: C.navy,
  title: 'The side menu opens from any page',
  locator: 'Module 02 · Present 2 of 2',
  shotNum: '008',
  intro: 'Tap the hamburger in the top-left to open the side-menu drawer. The screenshot shows the drawer beginning to slide in over a dimmed dashboard — only the leftmost column of menu entries is visible at this moment.',
  hotspots: [
    { fx: 0.18, fy: 0.04, n: 1 },
    { fx: 0.18, fy: 0.10, n: 2 },
    { fx: 0.18, fy: 0.55, n: 3 },
    { fx: 0.18, fy: 0.92, n: 4 },
  ],
  items: [
    { lead: 'Role badge',     body: '"Admin" chip at the top of the drawer; the user can confirm at a glance who the app thinks they are.' },
    { lead: 'Pending counter', body: '"7 pending" counter — sync queue depth visible at a glance from any page.' },
    { lead: 'Section labels', body: 'CORE group label visible; below it the workflow entries (Screening, Records, Intelligence, Alerts).' },
    { lead: 'Dimmed background', body: 'The dashboard behind the drawer is dimmed but still readable. Tap anywhere outside the drawer to close.' },
  ],
  notes: notes({
    teach: 'The side menu is the primary way users navigate. It respects role: items the user is not allowed to use are not rendered.',
    script: `Run the room across the four annotations. Note the drawer is mid-animation in this shot — on a real device the drawer fully covers the left half. Then ask: "If you cannot see Disease Management in your drawer, what does that tell you?" Answer: it means your role is not allowed to see disease intelligence.`,
    retrieve: 'How does the user close the drawer without selecting a menu item?',
    time: 3,
    confuse: ['Trainees expect the drawer to take the full screen width. It does not — half-screen with the dashboard dimmed behind is the design.'],
  }),
})
L_demo(pptx, {
  module: 'Module 02',
  accent: C.navy,
  title: 'A first look at primary screening',
  locator: 'Module 02 · Demonstrate',
  shotNum: '009',
  beat: 'TWO MINUTES OF YOUR DAY',
  lead: 'This is where most of the country\'s daily data is captured.',
  body: `Tap Primary Screening. The capture form appears. Three tabs at the top — Capture, Records, Referral Queue. Capture is the default. You see Direction pills first because that is the screener's first decision: Entry, Exit, or Transit. Below that, Sex pills. Below that, an optional temperature input, an optional name field with a passport tile, and a Symptoms YES / NO toggle. The whole pattern is "tap, tap, tap, done" — the form is built to clear a traveller in under fifteen seconds.\n\nWe will walk this surface in detail in module three. For now, it is enough to know it is here, it is the first place a screener goes, and it is the form the country runs on.`,
})
L_practice(pptx, {
  module: 'Module 02',
  accent: C.navy,
  locator: 'Module 02 · Practice',
  scenario: 'Sign into your tablet. Open the side menu. Find every entry your role can reach. Open the /welcome view and read it aloud to your neighbour.',
  prompts: [
    'Have each trainee read the /welcome view aloud to a neighbour. The neighbour confirms the role-specific lead sentence.',
    'Ask each trainee to count the menu groups visible to them. Compare counts across roles in the room.',
    'Ask "what is missing from your menu that someone else in the room sees?" — collect three answers.',
  ],
  minutes: 6,
})
L_recap(pptx, {
  module: 'Module 02',
  accent: C.navy,
  locator: 'Module 02 · Recap',
  items: [
    'Where on the home dashboard does the user start a primary screening?',
    'Where on the home dashboard does the user see whether the device is online?',
    'How does a screener replay the /welcome view if they signed past it?',
    'Name the four groups of entries in the side menu, in order.',
    'Plain-language status words: name three the app uses everywhere.',
    'What does "Operating normally" actually mean about the device?',
    'Where would a data officer go to file a weekly aggregated report?',
  ],
})

// ── 3. FOR THE SCREENER ─────────────────────────────────────────────────────

L_section(pptx, {
  number: '03',
  title: 'For the screener',
  audience: 'OFFICERS CONDUCTING PRIMARY SCREENING AT THE LINE',
  promise: 'Capture every traveller. Refer the right ones. Move on.',
  accent: C.navy,
  dark: false,
})
L_hook(pptx, {
  module: 'Module 03',
  accent: C.navy,
  question: 'A traveller steps up to your line. Their forehead reads 38.7°C. They are coughing. What do you do?',
  scene: 'Line at LUSKIA, 09:14, third bus from Kasumbalesa.',
  locator: 'Module 03 · Hook',
})
L_objectives(pptx, {
  module: 'Module 03',
  accent: C.navy,
  locator: 'Module 03 · Objectives',
  items: [
    'Identify the four facts that drive every primary screening: direction, sex, temperature, symptoms.',
    'Capture an asymptomatic traveller in under fifteen seconds.',
    'Capture a symptomatic traveller and raise the secondary referral atomically.',
    'Find a record you captured an hour ago and review its detail.',
    'Void a record with a reason when the entry was made in error.',
  ],
})
L_present(pptx, {
  module: 'Module 03',
  accent: C.navy,
  title: 'The capture surface',
  locator: 'Module 03 · Present 1 of 4',
  shotNum: '009',
  intro: 'The first screen the screener sees. Three tabs — Capture, Records, Referral Queue. Capture is the default and the work-day starts here.',
  hotspots: [
    { fx: 0.50, fy: 0.06, n: 1 },
    { fx: 0.50, fy: 0.30, n: 2 },
    { fx: 0.50, fy: 0.52, n: 3 },
    { fx: 0.50, fy: 0.74, n: 4 },
  ],
  items: [
    { lead: 'Tab strip',          body: 'Capture (default), Records (your case register), Referral Queue (referrals from your captures).' },
    { lead: 'Direction pills',    body: 'Entry, Exit, Transit. The screener\'s first decision because it interprets the rest of the form.' },
    { lead: 'Sex pills',          body: 'Male / Female. Single-tap input, no keyboard.' },
    { lead: 'Temperature input',  body: 'Numeric, defaults to Celsius. Optional but recommended for every traveller.' },
  ],
  teach: 'The form is ordered Direction → Sex → Temperature → Symptoms because those are the four facts every WHO/IHR primary screen has to capture. Everything else is optional.',
  retrieve: 'Why is Direction the first field, not Name?',
  time: 4,
})
L_present(pptx, {
  module: 'Module 03',
  accent: C.navy,
  title: 'The lower half of the capture form',
  locator: 'Module 03 · Present 2 of 4',
  shotNum: '010',
  intro: 'Below the Direction and Sex pills, the form continues with Temperature and the symptoms commit cards.',
  hotspots: [
    { fx: 0.50, fy: 0.10, n: 1 },
    { fx: 0.50, fy: 0.42, n: 2 },
    { fx: 0.50, fy: 0.78, n: 3 },
  ],
  items: [
    { lead: 'Temperature input', body: '°C / °F selector with an optional numeric value. Skipped for asymptomatic travellers when the line is moving fast.' },
    { lead: 'IHR Surveillance Symptoms', body: 'A blue accordion linking to the WHO reference inventory, so the screener can check whether something they observed counts as a screening symptom.' },
    { lead: 'Clear vs Symptomatic cards', body: 'Two cards — Clear (no symptoms) and Symptomatic (Referral created). The card the screener taps is the commit. There is no separate Save button.' },
  ],
  teach: 'The card the screener taps is the commit. Clear writes the primary screening only; Symptomatic writes the screening AND raises a referral in the same transaction.',
  retrieve: 'What gets written when the screener taps the Symptomatic card?',
  time: 3,
})
L_strip(pptx, {
  module: 'Module 03',
  accent: C.navy,
  title: 'Three moments in the capture loop',
  locator: 'Module 03 · Present 3 of 4',
  intro: 'A walk-through of the same screener\'s shift across three moments — capture, look back, confirm pickup.',
  shots: [
    { num: '009', caption: 'The capture surface. Direction, Sex, Temperature, the WHO surveillance accordion, and a pair of cards — Clear (no symptoms) and Symptomatic (Referral created). Tapping the Symptomatic card commits both records together.' },
    { num: '014', caption: 'Looking back from the Records tab an hour later. Joseph Phiri\'s row visible — MALE · ENTRY · 38.6°C · Symptomatic · Queued. Tapping the row begins to slide up the Screening Record detail modal.' },
    { num: '015', caption: 'The Referral Queue tab on the same surface. The referral the screener raised is at the top — HIGH priority, OPEN, Joseph Phiri, ENTRY, MALE, 38.6°C, captured at 04:37.' },
  ],
  teach: 'The capture loop is a single rhythm: capture, look back, confirm pickup. The same surface carries all three.',
  script: `Walk left to right. After panel one say: "Tapping the Symptomatic card writes the screening AND raises the referral in one go — the secondary officer sees the case immediately." After panel two: "An hour later the screener can find any record they captured." After panel three: "And confirm the case has gone where it should." Avoid the word "atomic" — that is for the developer module.`,
  retrieve: 'What does tapping the Symptomatic card actually commit?',
})
L_present(pptx, {
  module: 'Module 03',
  accent: C.navy,
  title: 'The Records tab — your case register',
  locator: 'Module 03 · Present 4 of 4',
  shotNum: '012',
  intro: 'Every primary record you have ever captured at this device, in reverse-chronological order.',
  hotspots: [
    { fx: 0.50, fy: 0.05, n: 1 },
    { fx: 0.50, fy: 0.20, n: 2 },
    { fx: 0.50, fy: 0.45, n: 3 },
  ],
  items: [
    { lead: 'Filter chips',  body: 'All, Today, This week, Symptomatic, Sync state. Pin to the top so they are always reachable.' },
    { lead: 'Search box',    body: 'Free-text search by traveller name. Useful when the screener needs to find one record fast.' },
    { lead: 'Record card',   body: 'Each card carries traveller, captured time, temperature, symptoms badge, and a sync state badge.' },
  ],
  teach: 'Records is the screener\'s personal register. A supervisor sees a wider register from the standalone primary records page; the Records tab here is scoped to this device.',
  retrieve: 'What does the badge "Waiting to upload" mean?',
  time: 3,
})
L_demo(pptx, {
  module: 'Module 03',
  accent: C.navy,
  title: 'Demonstrate — a symptomatic capture, end to end',
  locator: 'Module 03 · Demonstrate 1 of 3',
  shotNum: '009',
  beat: 'BEAT 1 · CAPTURE',
  lead: 'A symptomatic traveller. Direction → Entry. Sex → Male. Temperature 38.6°C. Symptomatic.',
  body: `The screener picks Direction (Entry), Sex (Male), and types the temperature (38.6°C). The IHR Surveillance Symptoms accordion reminds the user what to watch for. Below it, a pair of cards offers the commit: Clear (no symptoms) on the left, Symptomatic (Referral created) on the right. The screener taps the Symptomatic card.\n\nIn the same instant, two records are written together: the primary screening and the referral notification. The Symptomatic card on the next capture is the commit; there is no separate Save button to forget.`,
  badges: [
    { label: 'Direction · Entry', kind: 'accent' },
    { label: 'Sex · Male',         kind: 'accent' },
    { label: 'Temp 38.6 °C',       kind: 'warn' },
    { label: 'Symptomatic',        kind: 'danger' },
  ],
})
L_demo(pptx, {
  module: 'Module 03',
  accent: C.navy,
  title: 'Demonstrate — finding the record again',
  locator: 'Module 03 · Demonstrate 2 of 3',
  shotNum: '014',
  beat: 'BEAT 2 · LOOK BACK',
  lead: 'An hour later. The screener wants to confirm the record they captured.',
  body: `The screener taps the Records tab and finds Joseph's row. They tap the card. A detail modal slides up showing every field: traveller, captured time, direction, sex, temperature, symptoms, referral state, sync state, the user who captured, the device id.\n\nThe modal is read-only by design — a primary record is supposed to be a faithful record of what was observed at the moment of capture, not an editable draft. The Void Record button at the bottom is the one mutation possible from here, and it opens a separate void-with-reason flow that requires a reason before the void commits.`,
})
L_demo(pptx, {
  module: 'Module 03',
  accent: C.navy,
  title: 'Demonstrate — the referral queue',
  locator: 'Module 03 · Demonstrate 3 of 3',
  shotNum: '015',
  beat: 'BEAT 3 · CONFIRM PICKUP',
  lead: 'The screener checks that their referrals have been picked up.',
  body: `From the same Primary Screening surface, the screener taps the Referral Queue tab. They see the referrals raised at this device — Joseph's at the top with a HIGH priority pill and the time it was raised.\n\nIf the queue has rows, no further action is needed — the secondary officer will pick them up. If a referral is still showing OPEN twenty minutes after capture in a busy site, the screener asks their supervisor to call the secondary officer directly. The Referral Queue is a confidence check, not a workflow.`,
  handoff: 'After the referral, the case is the secondary officer\'s. The screener sees nothing more about it.',
})
L_practice(pptx, {
  module: 'Module 03',
  accent: C.navy,
  locator: 'Module 03 · Practice',
  scenario: 'Capture three travellers — one entering with no symptoms, one transiting with a temperature of 37.4°C, one entering with a temperature of 38.6°C and a cough. Then look back and find each in the Records tab.',
  prompts: [
    'Hold a stopwatch on the asymptomatic capture. Goal: under fifteen seconds.',
    'After the symptomatic capture, ask the trainee to read out what the Symptomatic card label actually says — "Referral created" — and confirm the queue picked it up.',
    'After all three, have the trainee find each record in the Records tab using a different filter chip for each.',
  ],
  minutes: 12,
})
L_recap(pptx, {
  module: 'Module 03',
  accent: C.navy,
  locator: 'Module 03 · Recap',
  items: [
    'Name the four facts that drive every primary screening, in order.',
    'What does tapping the Symptomatic card commit, and to which two stores?',
    'Why is the primary record read-only after capture?',
    'What does the screener see in the Referral Queue, and what does it confirm?',
    'How does a screener void a record they captured by mistake?',
    'Where does a screener go to find a record they captured an hour ago?',
    'What badge does a record carry when it has not yet uploaded to the server?',
    'Why is the Name field hidden until Symptoms is set to YES?',
  ],
})

// ── 4. FOR THE HEALTH OFFICER ──────────────────────────────────────────────

L_section(pptx, {
  number: '04',
  title: 'For the health officer',
  audience: 'OFFICERS RUNNING SECONDARY INVESTIGATIONS — THE FOUR-STEP WIZARD',
  promise: 'Pick up a referral. Investigate. Disposition. Hand off cleanly.',
  accent: C.bronze,
  dark: false,
})
L_hook(pptx, {
  module: 'Module 04',
  accent: C.bronze,
  question: 'A referral lands in your inbox: 38.6°C, dry cough, recent travel through DRC. Where do you start?',
  scene: 'A health officer\'s desk at the secondary screening room, mid-morning.',
  locator: 'Module 04 · Hook',
})
L_objectives(pptx, {
  module: 'Module 04',
  accent: C.bronze,
  locator: 'Module 04 · Objectives',
  items: [
    'Open a referral from the queue and see the case in the wizard with the engine output already drawn.',
    'Capture a complete profile, symptoms inventory, and exposures list across the four steps.',
    'Read the Disease Intelligence engine output and decide whether to override the syndrome or risk.',
    'Pick the right Risk Level and record the matching Actions Taken from the grid.',
    'Re-open a closed case from the records register without losing audit history.',
  ],
})
L_present(pptx, {
  module: 'Module 04',
  accent: C.bronze,
  title: 'The notifications inbox',
  locator: 'Module 04 · Present 1 of 5',
  shotNum: '022',
  intro: 'Where the health officer\'s day starts. Open referrals routed to scope, criticals first.',
  hotspots: [
    { fx: 0.50, fy: 0.18, n: 1 },
    { fx: 0.50, fy: 0.42, n: 2 },
    { fx: 0.85, fy: 0.42, n: 3 },
  ],
  items: [
    { lead: 'Filter strip',     body: 'Open / Working on / Closed. The default is Open. Critical cases are pinned at the top.' },
    { lead: 'Referral card',    body: 'Traveller, originating point of entry, captured time. The card is the unit of work.' },
    { lead: 'Priority pill',    body: 'Routine / Urgent / Emergency. Plain language; no Tier labels are shown to the front-line user.' },
  ],
  teach: 'The inbox is to the health officer what the capture form is to the screener. Tap a card and you enter the case wizard.',
  retrieve: 'What three priorities does a referral card show?',
  time: 3,
})
L_strip(pptx, {
  module: 'Module 04',
  accent: C.bronze,
  title: 'Profile · Symptoms · Exposures · Actions',
  eyebrow: 'PRESENT 2 OF 5',
  locator: 'Module 04 · Present 2 of 5',
  intro: 'A complete WHO/IHR-aligned investigation. Four steps; the disease intelligence engine runs continuously and updates as each step is completed.',
  shots: [
    { num: '023', caption: 'Mid-investigation, on the Exposures step. Profile and Symptoms are already complete (green ticks on the stepper). The Disease Intelligence Analysis card sits above the form with a green NON-CASE read and a recommended Disposition of Released — both subject to officer override.' },
    { num: '024', caption: 'The Symptom Checklist within step 2 — paired symptom chips by syndrome family (Fever / High Fever, Cough / Dry Cough, Diarrhoea / Profuse Watery, etc.) with quick toggle inputs.' },
    { num: '026', caption: 'Step 3 — the structured Exposure Questionnaire. Yes / No / Unknown for travel-to-outbreak, residence-in-outbreak, close contact with symptomatic, and other markers tagged HIGH RISK where the engine cares about them.' },
    { num: '028', caption: 'Step 4 — Actions. The engine\'s output sits at the top of the page again for reference; below it, the officer records the syndrome, the risk level, and the actions taken.' },
  ],
  teach: 'The engine is always running. Every step the officer completes feeds it; the case classification on step 4 is not a separate computation — it is the running tally.',
})
L_present(pptx, {
  module: 'Module 04',
  accent: C.bronze,
  title: 'Step 2 — the symptoms inventory',
  locator: 'Module 04 · Present 3 of 5',
  shotNum: '024',
  intro: 'A multi-select checklist organised by category — general, respiratory, gastrointestinal, neurological, dermatological, haemorrhagic.',
  hotspots: [
    { fx: 0.50, fy: 0.05, n: 1 },
    { fx: 0.50, fy: 0.30, n: 2 },
    { fx: 0.50, fy: 0.55, n: 3 },
    { fx: 0.50, fy: 0.85, n: 4 },
  ],
  items: [
    { lead: 'Step pills',   body: 'Tap any past step to jump there. Cannot skip ahead until each step has been visited at least once.' },
    { lead: 'Category',     body: 'Symptoms grouped so the officer reads down familiar grouping rather than scanning a flat list.' },
    { lead: 'Per-symptom',  body: 'Each row captures three things: response (Yes/No/Unknown), onset date, severity.' },
    { lead: 'Save & Next',  body: 'Commits the inventory and advances. Back returns without saving. Forward jumps allowed once the user has advanced past a step at least once.' },
  ],
  teach: 'Quality of analysis on step four depends on the care taken on step two. A skipped category = a weakened ranking.',
  retrieve: 'Why does the wizard prevent a forward-jump until each step has been visited?',
  time: 4,
})
L_present(pptx, {
  module: 'Module 04',
  accent: C.bronze,
  title: 'Step 3 — exposures and travel',
  locator: 'Module 04 · Present 4 of 5',
  shotNum: '026',
  intro: 'Where the case starts to differ from another with the same symptoms.',
  hotspots: [
    { fx: 0.50, fy: 0.18, n: 1 },
    { fx: 0.50, fy: 0.42, n: 2 },
    { fx: 0.50, fy: 0.70, n: 3 },
  ],
  items: [
    { lead: 'Travel history', body: 'Countries the traveller has been in over the past 14 days. Searchable list.' },
    { lead: 'Exposure events', body: 'Sick contact, livestock contact, healthcare exposure, mass gathering, and so on. Each row has response and free-text details.' },
    { lead: 'Engine pass on Save', body: 'When Save & Next is tapped, the engine runs over steps 1–3 and produces the ranking we see in step 4.' },
  ],
  teach: 'Exposures change the ranking. A respiratory case from a country with active surveillance is a different signal from one with no activity.',
  retrieve: 'Why is step 3 separated from step 2 instead of one combined inventory?',
  time: 3,
})
L_present(pptx, {
  module: 'Module 04',
  accent: C.bronze,
  title: 'Step 4 — Actions and engine review',
  locator: 'Module 04 · Present 5 of 5',
  shotNum: '028',
  intro: 'The Actions step. The Disease Intelligence Analysis card carries the engine\'s read; a Suspected Disease selector and a Syndrome Classification line let the officer review and override.',
  hotspots: [
    { fx: 0.50, fy: 0.10, n: 1 },
    { fx: 0.50, fy: 0.32, n: 2 },
    { fx: 0.50, fy: 0.58, n: 3 },
    { fx: 0.50, fy: 0.82, n: 4 },
  ],
  items: [
    { lead: 'Case header',         body: 'The case banner with traveller, temperature, point of entry, direction and the high-risk chip stays pinned across every step.' },
    { lead: 'Engine card',         body: 'Disease Intelligence Analysis output: NON-CASE / SUSPECTED / CONFIRMED with the rule that fired and the option to override.' },
    { lead: 'Suspected Disease',   body: 'A dropdown the officer can use to override the engine\'s top guess when the clinical picture says otherwise.' },
    { lead: 'Syndrome Classification', body: 'A required selector — the WHO surveillance syndrome the case maps to.' },
  ],
  teach: 'The engine is a recommendation, not a decision. The override is recorded with the officer\'s identity in the case audit trail.',
  retrieve: 'Where on step 4 does the officer override the engine\'s read?',
  time: 4,
})
L_demo(pptx, {
  module: 'Module 04',
  accent: C.bronze,
  title: 'Demonstrate — opening the case',
  locator: 'Module 04 · Demonstrate 1 of 3',
  shotNum: '023',
  beat: 'BEAT 1 · OPEN',
  lead: 'Joseph Phiri\'s case is open. Profile and Symptoms are complete; Exposures is the active step.',
  body: `The case header is pinned across every step: traveller name, age and sex, temperature, point of entry, the ENTRY direction chip, the HIGH-RISK chip. The stepper underneath shows Profile✓, Symptoms✓, Exposures active, Actions still ahead.\n\nAbove the form, the Disease Intelligence Analysis card already carries a read: a green NON-CASE check because no disease has cleared its WHO specificity gate on the data so far. The recommended disposition reads "Released" — but the officer can override. The "Officer Override — I disagree" link, the Suspected Disease selector, and the Syndrome Classification section all sit ready below.`,
})
L_demo(pptx, {
  module: 'Module 04',
  accent: C.bronze,
  title: 'Demonstrate — symptoms and exposures',
  locator: 'Module 04 · Demonstrate 2 of 3',
  shotNum: '027',
  beat: 'BEAT 2 · INVESTIGATE',
  lead: 'Step 3, exposures recorded. Step 2 already captured cough and fever; step 3 adds the DRC travel and the CONTACT_MARKET_LIVESTOCK exposure event.',
  body: `On Save & Next from step 3 the engine runs. The screen advances to step 4. The officer sees the engine\'s output: syndrome RESPIRATORY, risk HIGH, routing PHEOC, top-ranked disease INFLUENZA at 42% confidence, second-ranked COVID-19 at 27%.\n\nThe officer reads the recommended-actions panel. One of the actions reads "Notify district within 24 hours and PHEOC within 7 days." The officer agrees with the engine\'s read and does not override.`,
  badges: [
    { label: 'Risk · High',       kind: 'danger' },
    { label: 'Routing · PHEOC',   kind: 'accent' },
    { label: 'Syndrome · Resp',   kind: 'bronze' },
  ],
})
L_demo(pptx, {
  module: 'Module 04',
  accent: C.bronze,
  title: 'Demonstrate — risk, syndrome, actions',
  locator: 'Module 04 · Demonstrate 3 of 3',
  shotNum: '029',
  beat: 'BEAT 3 · DECIDE',
  lead: 'On the Actions step the officer fixes the risk and records what was done.',
  body: `The officer picks the syndrome from the chip grid (RESPIRATORY for this case). The Risk Level Assessment offers Low, Medium, High, Critical — the officer confirms HIGH. An "Alert Auto-Triggered" yellow banner appears: HIGH_RISK_RESPIRATORY routed to DISTRICT and above.\n\nThe Actions Taken grid offers Isolated, Mask Given, PPE Used, Separate Room, Referred Clinic, Referred Hospital, Quarantine, Sample, Allowed Through, Contact Tracing, Follow-up Scheduled. The officer selects Referred Hospital + Mask Given + Sample. A red footer reminds the officer that Risk HIGH requires either Isolated or Referred Hospital — both are now satisfied.`,
  handoff: 'The case is now the district supervisor\'s — the auto-triggered alert lands on the active-alerts page within seconds.',
})
L_practice(pptx, {
  module: 'Module 04',
  accent: C.bronze,
  locator: 'Module 04 · Practice',
  scenario: 'Open the demo referral on your tablet. Walk all four steps. Disposition the case as Referred. Then re-open it from the records register and confirm the audit trail has a re-open row.',
  prompts: [
    'Time the wizard end-to-end. Goal: under fifteen minutes for a complete investigation.',
    'On step 4, ask the trainee whether they would override the engine\'s risk level. Take their answer and ask why.',
    'After the disposition, have the trainee find the case in /secondary-screening/records and read the audit trail aloud.',
  ],
  minutes: 18,
})
L_recap(pptx, {
  module: 'Module 04',
  accent: C.bronze,
  locator: 'Module 04 · Recap',
  items: [
    'In what order do the four wizard steps appear?',
    'What is the Disease Intelligence engine actually showing on the green NON-CASE card?',
    'Where on step 4 does the officer override the engine\'s read?',
    'What does the Risk Level Assessment offer, and what does HIGH require the officer to record?',
    'Name three Actions Taken that satisfy a Risk HIGH respiratory case.',
    'How does the officer re-open a closed case if a finding needs amending?',
    'What plain-language priorities appear on a referral card in the queue?',
    'Where does the case go after the Actions step is committed? Who sees it next?',
  ],
})

// ── 5. FOR THE DISTRICT SUPERVISOR ─────────────────────────────────────────

L_section(pptx, {
  number: '05',
  title: 'For the district supervisor',
  audience: 'DISTRICT-LEVEL OFFICERS WATCHING ALERTS AND ACKNOWLEDGING THEM',
  promise: 'Watch the alert. Acknowledge promptly. Escalate when the response stalls.',
  accent: C.navy,
})
L_hook(pptx, {
  module: 'Module 05',
  accent: C.navy,
  question: 'Three high-risk respiratory cases at the same point of entry in 24 hours. The system has just raised an alert. What is your first move?',
  scene: 'A district health office, lunch time.',
  locator: 'Module 05 · Hook',
})
L_objectives(pptx, {
  module: 'Module 05',
  accent: C.navy,
  locator: 'Module 05 · Objectives',
  items: [
    'Read the active-alerts page and recognise the four facts on every row.',
    'Acknowledge an alert and record the action you took.',
    'Escalate an alert from district to province when the response is stalling.',
    'Read the alert-history page to confirm closure timing on past alerts.',
  ],
})
L_present(pptx, {
  module: 'Module 05',
  accent: C.navy,
  title: 'The active-alerts page',
  locator: 'Module 05 · Present 1 of 2',
  shotNum: '032',
  intro: 'Open alerts visible to the supervisor\'s scope. Critical at the top.',
  hotspots: [
    { fx: 0.50, fy: 0.10, n: 1 },
    { fx: 0.50, fy: 0.30, n: 2 },
    { fx: 0.85, fy: 0.30, n: 3 },
    { fx: 0.50, fy: 0.55, n: 4 },
  ],
  items: [
    { lead: 'Filter strip', body: 'Open / Acknowledged / Closed. Default is Open.' },
    { lead: 'Alert title',  body: 'Plain-language summary of the signal — what triggered the alert.' },
    { lead: 'Risk pill',    body: 'High / Critical. Plain language; treaty-level vocabulary stays in the developer module.' },
    { lead: 'Origin and route', body: 'Originating point of entry and the level the alert has been routed to.' },
  ],
  teach: 'The supervisor watches this page in fifteen-minute cycles during a busy day.',
  retrieve: 'What does an Acknowledged badge on an alert mean?',
  time: 3,
})
L_present(pptx, {
  module: 'Module 05',
  accent: C.navy,
  title: 'The alerts history page',
  locator: 'Module 05 · Present 2 of 2',
  shotNum: '035',
  intro: 'Acknowledged and closed alerts with full audit trail. Read-only.',
  hotspots: [
    { fx: 0.50, fy: 0.20, n: 1 },
    { fx: 0.50, fy: 0.50, n: 2 },
  ],
  items: [
    { lead: 'Alert row',     body: 'Same fields as active alerts plus an Acknowledged-by stamp and a Closed-at time.' },
    { lead: 'Audit trail',   body: 'Tap any row to see who acknowledged, when, what action, and what comment was left.' },
  ],
  teach: 'History is the after-action review tool. A closed alert cannot be re-opened from this view.',
  retrieve: 'Can a closed alert be re-opened?',
  time: 2,
})
L_demo(pptx, {
  module: 'Module 05',
  accent: C.navy,
  title: 'Demonstrate — acknowledging an alert',
  locator: 'Module 05 · Demonstrate',
  shotNum: '032',
  beat: 'BEAT · ACKNOWLEDGE',
  lead: 'Joseph Phiri\'s case has just generated an alert at the district level.',
  body: `The supervisor sees the alert at the top of the active-alerts page. The title reads "Suspected respiratory cluster". The risk pill is High. The origin is LUSKIA. The supervisor taps the row, reads the alert detail, and taps Acknowledge.\n\nThe supervisor types a one-line action they took: "Spoke to LUSKIA shift lead. Health officer has dispositioned, case has gone on to the district hospital." The alert moves to Acknowledged on the active-alerts page; a row appears in the alert-history page with the supervisor's name, the timestamp, and the comment.`,
  handoff: 'If the response stalls — the case sits without progress for hours — the supervisor escalates by updating the alert with a new comment and changing its routed-to level to PHEOC.',
})
L_practice(pptx, {
  module: 'Module 05',
  accent: C.navy,
  locator: 'Module 05 · Practice',
  scenario: 'The room watches the active-alerts page for ten minutes. Whenever a new row appears, the trainee whose tablet shows it reads the four facts aloud and decides whether to acknowledge or escalate.',
  prompts: [
    'Have the room agree on a time-to-acknowledge target before starting (e.g. five minutes).',
    'Each acknowledgement comment must be one sentence with a verb. Generic comments ("seen", "ok") are rejected.',
    'After ten minutes, read the alert-history page back to the room and discuss timing.',
  ],
  minutes: 12,
})
L_recap(pptx, {
  module: 'Module 05',
  accent: C.navy,
  locator: 'Module 05 · Recap',
  items: [
    'What four facts does an active-alerts row carry?',
    'What is the difference between Acknowledged and Closed?',
    'When does a supervisor escalate an alert to province?',
    'Where is the audit trail of past alerts kept?',
    'Can a closed alert be re-opened from the history page?',
    'What is the recommended target time for acknowledgement during a busy day?',
  ],
})

// ── 6. FOR THE PHEOC OFFICER ───────────────────────────────────────────────

L_section(pptx, {
  number: '06',
  title: 'For the PHEOC officer',
  audience: 'PROVINCE-LEVEL PUBLIC HEALTH EMERGENCY OPERATIONS CENTRE OFFICERS',
  promise: 'See the response pipeline. Spot timeliness gaps. Escalate to national.',
  accent: C.bronze,
})
L_hook(pptx, {
  module: 'Module 06',
  accent: C.bronze,
  question: 'The dashboard shows your district has acknowledged 6 of the last 8 alerts within 24 hours. The other two slipped past 48 hours. Why?',
  scene: 'A PHEOC duty office, end of week review.',
  locator: 'Module 06 · Hook',
})
L_objectives(pptx, {
  module: 'Module 06',
  accent: C.bronze,
  locator: 'Module 06 · Objectives',
  items: [
    'Read the alert-intelligence dashboard and explain what each KPI measures.',
    'Identify timeliness gaps in the response pipeline.',
    'Escalate to national when the province has done what it can.',
    'Use the WHO Annex 2 reference matrix as a quick lookup mid-case.',
  ],
})
L_present(pptx, {
  module: 'Module 06',
  accent: C.bronze,
  title: 'The alert-intelligence dashboard',
  locator: 'Module 06 · Present 1 of 2',
  shotNum: '033',
  intro: 'The province\'s pipeline view. Less about individual alerts; more about whether the response process is keeping up.',
  hotspots: [
    { fx: 0.50, fy: 0.10, n: 1 },
    { fx: 0.50, fy: 0.32, n: 2 },
    { fx: 0.50, fy: 0.55, n: 3 },
  ],
  items: [
    { lead: 'Page title',     body: 'Outbreak response timeliness — the plain-language framing of what the page measures.' },
    { lead: 'KPI strip',      body: 'Within 24h notice (target 8 in 10), Within 7d response, Top-priority count, Targets missed.' },
    { lead: 'Colour legend',  body: 'Pinned beneath the strip so a reader does not have to memorise the bands.' },
  ],
  teach: 'Each KPI carries a sub-line that explains in plain language what the number actually means.',
  retrieve: 'What is the target rate for "within 24h notice"?',
  time: 3,
})
L_present(pptx, {
  module: 'Module 06',
  accent: C.bronze,
  title: 'The Annex 2 reference matrix',
  locator: 'Module 06 · Present 2 of 2',
  shotNum: '036',
  intro: 'A read-only catalogue showing which diseases trigger Tier 1 (always notify) vs Tier 2 (notify if criteria met).',
  hotspots: [
    { fx: 0.50, fy: 0.20, n: 1 },
    { fx: 0.50, fy: 0.50, n: 2 },
  ],
  items: [
    { lead: 'Search and filter', body: 'By disease name, by tier, by syndrome. PII-free, available offline.' },
    { lead: 'Disease detail',    body: 'Tap a row for the case definition, endemic countries, IHR tier, and recommended actions.' },
  ],
  teach: 'The matrix never affects a record or triggers an action. It is a quick-lookup of treaty obligations.',
  retrieve: 'Does the matrix change the engine\'s ranking?',
  time: 2,
})
L_demo(pptx, {
  module: 'Module 06',
  accent: C.bronze,
  title: 'Demonstrate — reading the timeliness panels',
  locator: 'Module 06 · Demonstrate',
  shotNum: '034',
  beat: 'BEAT · READ',
  lead: 'Joseph\'s case has now flowed up to the province.',
  body: `The PHEOC officer scrolls to the Detect / Notify / Respond stage cards. Each card shows whether the stage is currently computable, the rolling rate, and a short footnote citing the article it derives from. Detect: 86%. Notify: 92%. Respond: 78%. The officer notes Respond is below the target band of 90%.\n\nThey tap the Respond card to drill into the alerts that fell past target. Two alerts from one specific district account for the dip. The officer makes a note to call that district and ask what bottlenecked the response.`,
  handoff: 'If the timeliness gap repeats over multiple weeks, PHEOC escalates the conversation to the national administrator with the data attached.',
})
L_practice(pptx, {
  module: 'Module 06',
  accent: C.bronze,
  locator: 'Module 06 · Practice',
  scenario: 'Read the week\'s alert-intelligence dashboard out loud to the room. Identify any KPI that is off-target. Decide what you would do about it.',
  prompts: [
    'For each off-target KPI, the trainee names the action they would take and the level it sits at.',
    'For each on-target KPI, ask "what would have to happen for this to slip?"',
    'Use the Annex 2 matrix at least once during the discussion.',
  ],
  minutes: 10,
})
L_recap(pptx, {
  module: 'Module 06',
  accent: C.bronze,
  locator: 'Module 06 · Recap',
  items: [
    'What does the PHEOC dashboard call the "Detect, Notify, Respond" stages collectively?',
    'What is the target hit-rate for the Within-24h-notice KPI?',
    'When does PHEOC escalate to national?',
    'What does the Annex 2 matrix do for the officer?',
    'Does the matrix affect the engine\'s ranking?',
    'When is the matrix most useful during a busy day?',
  ],
})

// ── 7. FOR THE NATIONAL ADMINISTRATOR ──────────────────────────────────────

L_section(pptx, {
  number: '07',
  title: 'For the national administrator',
  audience: 'NATIONAL-LEVEL ADMINISTRATORS — OVERSIGHT AND CONFIGURATION',
  promise: 'See every site at once. Configure the templates and contacts the system runs on.',
  accent: C.navy,
})
L_hook(pptx, {
  module: 'Module 07',
  accent: C.navy,
  question: 'A new ministry-led reporting requirement lands. The country must start filing it next month. Where do you go in the app first?',
  scene: 'National programme office, late afternoon.',
  locator: 'Module 07 · Hook',
})
L_objectives(pptx, {
  module: 'Module 07',
  accent: C.navy,
  locator: 'Module 07 · Objectives',
  items: [
    'Read the screening intelligence dashboard at a national scope.',
    'Add a point of entry to the registry and confirm it flows to every device.',
    'Onboard a user with the right role and geographic assignment.',
    'Build a new aggregated template through the wizard from start to publish.',
    'Maintain the point-of-entry contacts roster so the alert chain reaches a real human.',
  ],
})
L_present(pptx, {
  module: 'Module 07',
  accent: C.navy,
  title: 'The screening intelligence dashboard',
  locator: 'Module 07 · Present 1 of 4',
  shotNum: '018',
  intro: 'The administrator\'s read at the top of the day. Plain language; eight cells of headline figures plus a hero ring.',
  hotspots: [
    { fx: 0.50, fy: 0.06, n: 1 },
    { fx: 0.50, fy: 0.30, n: 2 },
    { fx: 0.50, fy: 0.55, n: 3 },
  ],
  items: [
    { lead: 'Filter chips',  body: 'Today / Week / Month / Year / Custom. Selects the window the rest of the page summarises.' },
    { lead: 'Hero ring',     body: 'Symptomatic rate as a percent with a plain-language interpretation underneath.' },
    { lead: 'Quick stats',   body: 'Eight cells with whole-word labels and a comparison to yesterday — the headline figures.' },
  ],
  retrieve: 'Why does the dashboard show a plain-language interpretation underneath the percentage?',
  time: 3,
})
L_present(pptx, {
  module: 'Module 07',
  accent: C.navy,
  title: 'Point-of-entry registry',
  locator: 'Module 07 · Present 2 of 4',
  shotNum: '052',
  intro: 'The country\'s gazetted points of entry. Add, edit, or retire from this page; downstream views update on next sync.',
  hotspots: [
    { fx: 0.50, fy: 0.10, n: 1 },
    { fx: 0.50, fy: 0.32, n: 2 },
    { fx: 0.85, fy: 0.85, n: 3 },
  ],
  items: [
    { lead: 'Filter strip', body: 'Province / district / status. The list is long; filters are essential.' },
    { lead: 'Row',          body: 'Code, name, type, status. Tap for detail and edit.' },
    { lead: 'Action button',body: 'Floating action button at the bottom-right opens the create-POE wizard.' },
  ],
  retrieve: 'When does a new point of entry appear on the screening line\'s drop-downs?',
  time: 3,
})
L_present(pptx, {
  module: 'Module 07',
  accent: C.navy,
  title: 'User management',
  locator: 'Module 07 · Present 3 of 4',
  shotNum: '054',
  intro: 'The operator roster. Onboard, deactivate, reassign — it all happens here.',
  hotspots: [
    { fx: 0.50, fy: 0.10, n: 1 },
    { fx: 0.50, fy: 0.30, n: 2 },
    { fx: 0.50, fy: 0.55, n: 3 },
  ],
  items: [
    { lead: 'Search and filter', body: 'By name, username, role, active status.' },
    { lead: 'Row',               body: 'Name + username + role badge + active badge. Tap for detail.' },
    { lead: 'New user button',   body: 'Top-right opens the create-user form. Geographic assignment auto-shows based on role.' },
  ],
  retrieve: 'Why does the create-user form change which fields appear when you change the role?',
  time: 3,
})
L_present(pptx, {
  module: 'Module 07',
  accent: C.navy,
  title: 'The point-of-entry contacts roster',
  locator: 'Module 07 · Present 4 of 4',
  shotNum: '046',
  intro: 'Who gets called when something fires at this point of entry. Order matters.',
  hotspots: [
    { fx: 0.50, fy: 0.20, n: 1 },
    { fx: 0.50, fy: 0.40, n: 2 },
    { fx: 0.50, fy: 0.60, n: 3 },
  ],
  items: [
    { lead: 'Contact card',    body: 'Name, role title, phone, email, escalation order.' },
    { lead: 'Escalation order',body: 'Number 1 is called first. Update when staff move on or change phones.' },
    { lead: 'Active flag',     body: 'Inactive contacts stay in the registry but do not get called.' },
  ],
  teach: 'A wrong phone number here means the alert chain ends at a dead line. The roster is the thing nobody remembers to update — make a calendar reminder.',
  retrieve: 'What happens to alerts when no active contact exists at a point of entry?',
  time: 3,
})
L_demo(pptx, {
  module: 'Module 07',
  accent: C.navy,
  title: 'Demonstrate — the operator roster after onboarding',
  locator: 'Module 07 · Demonstrate',
  shotNum: '054',
  beat: 'BEAT · ONBOARD',
  lead: 'After a new operator is onboarded, the user-management list is the source of truth.',
  body: `The administrator works through the create flow off-screen — full name, username, email, phone, password, role, and the geographic assignment fields that auto-show based on the role chosen. Save commits to the local store and queues the new user for sync.\n\nOn the next sync, the user lands on the server and on every device that pulls a fresh user list. The new row appears in this register: name, role badge, sync state. The administrator also drops the new user's phone number into the LUSKIA contacts roster (Module 07, Present 4 of 4) so the alert chain reaches them on day one.`,
})
L_practice(pptx, {
  module: 'Module 07',
  accent: C.navy,
  locator: 'Module 07 · Practice',
  scenario: 'Add a new fictional point of entry, onboard a fictional screener at it, and add a contact to the new POE\'s roster. Confirm all three appear on a colleague\'s device after sync.',
  prompts: [
    'Have a colleague pull sync and confirm the new POE shows on their drop-downs.',
    'Have the new screener sign in and confirm their dashboard role banner reads "POE PRIMARY" (or whichever role chip matches their assignment).',
    'Have the colleague confirm the contact appears on the contacts roster page.',
  ],
  minutes: 12,
})
L_recap(pptx, {
  module: 'Module 07',
  accent: C.navy,
  locator: 'Module 07 · Recap',
  items: [
    'What does the screening intelligence ring chart actually count?',
    'When does a newly added point of entry become visible to the line officers?',
    'Why does the create-user form change fields when the role changes?',
    'Why is the escalation order on the contacts roster a number?',
    'What happens if no active contact is set at a point of entry?',
    'Where would the administrator go to retire a point of entry without losing past records?',
    'What is the difference between deactivating a user and deleting them?',
  ],
})

// ── 8. FOR THE DATA OFFICER ────────────────────────────────────────────────

L_section(pptx, {
  number: '08',
  title: 'For the data officer',
  audience: 'OFFICERS RESPONSIBLE FOR FILING AGGREGATED REPORTS ON SCHEDULE',
  promise: 'File the right counts on the right cadence. Confirm the report landed.',
  accent: C.bronze,
})
L_hook(pptx, {
  module: 'Module 08',
  accent: C.bronze,
  question: 'It is Friday afternoon. The weekly POE arrivals report is due before close of business. Three points of entry have not pushed their counts. What do you do?',
  scene: 'A data officer\'s desk, end of week.',
  locator: 'Module 08 · Hook',
})
L_objectives(pptx, {
  module: 'Module 08',
  accent: C.bronze,
  locator: 'Module 08 · Objectives',
  items: [
    'Find a template owed for the current period from the Submission History page.',
    'File a complete aggregated submission across all four wizard steps.',
    'Read past submissions and confirm a previous one landed.',
    'Use the Notes step to record an exceptional event before submitting.',
    'Read the Review step values once and decide whether to Submit or go Back to Fill.',
  ],
})
L_present(pptx, {
  module: 'Module 08',
  accent: C.bronze,
  title: 'The submission home base',
  locator: 'Module 08 · Present 1 of 3',
  shotNum: '040',
  intro: 'The Submission History page is where the data officer\'s day starts and ends. Past submissions are the audit trail; the + New button opens the wizard for the next.',
  hotspots: [
    { fx: 0.50, fy: 0.06, n: 1 },
    { fx: 0.50, fy: 0.20, n: 2 },
    { fx: 0.50, fy: 0.40, n: 3 },
    { fx: 0.50, fy: 0.65, n: 4 },
  ],
  items: [
    { lead: 'KPI strip',     body: 'Submissions filed, total screened, total symptomatic, anything still unsynced — the snapshot.' },
    { lead: 'Period tabs',   body: '30 Days / 90 Days / This Year / All. Filters the list below.' },
    { lead: 'Period rows',   body: 'One row per submission. Period range, totals, symptomatic rate, gender split, sync state.' },
    { lead: '+ New button',  body: 'Opens the submission wizard at Step 1 (Period).' },
  ],
  retrieve: 'How does the data officer find a submission filed two months ago?',
  time: 3,
})
L_present(pptx, {
  module: 'Module 08',
  accent: C.bronze,
  title: 'Inside the wizard — the Notes step',
  locator: 'Module 08 · Present 2 of 3',
  shotNum: '038',
  intro: 'Four steps: Period, Fill, Notes, Review. The Notes step is where exceptional events, retrospective counts, and interruptions get recorded.',
  hotspots: [
    { fx: 0.50, fy: 0.06, n: 1 },
    { fx: 0.50, fy: 0.15, n: 2 },
    { fx: 0.50, fy: 0.40, n: 3 },
    { fx: 0.50, fy: 0.85, n: 4 },
  ],
  items: [
    { lead: 'Stepper',          body: 'Period · Fill · Notes (active) · Review. Tap any past step to jump back; future steps unlock once the current step is valid.' },
    { lead: 'Audit Probe header', body: 'Template name + V1 chip + Pending pill. The wizard remembers which template the user picked on the hub.' },
    { lead: 'Notes textarea',   body: 'Optional, 0/255 characters. Plain language: what was unusual, what to flag for the reviewer.' },
    { lead: 'Back / Next',      body: 'Back returns to Fill without saving the note; Next saves and advances to Review.' },
  ],
  teach: 'The Notes field is optional but valued — it is what the central data team reads when a submission has unusual figures.',
  retrieve: 'When is the Notes field worth filling in?',
  time: 3,
})
L_present(pptx, {
  module: 'Module 08',
  accent: C.bronze,
  title: 'Before you Submit — the Review step',
  locator: 'Module 08 · Present 3 of 3',
  shotNum: '039',
  intro: 'The last step before the submission is queued. Read the values; if anything is wrong, Back to Fill.',
  hotspots: [
    { fx: 0.50, fy: 0.10, n: 1 },
    { fx: 0.50, fy: 0.30, n: 2 },
    { fx: 0.50, fy: 0.55, n: 3 },
    { fx: 0.50, fy: 0.80, n: 4 },
  ],
  items: [
    { lead: 'Review card',    body: 'Report name, period, point of entry, submitted-by — the metadata being committed.' },
    { lead: 'Values block',   body: 'Every column the user filled, in order. If the user entered no values, the wizard warns "only zeros would be submitted" — a deliberate prompt to check.' },
    { lead: 'Back',           body: 'Returns to Fill so the user can correct a bad count without losing the period or the notes.' },
    { lead: 'Submit report',  body: 'Commits the submission to the local store and queues it for sync.' },
  ],
  retrieve: 'What warning does the Review step show when no values were entered?',
  time: 3,
})
L_demo(pptx, {
  module: 'Module 08',
  accent: C.bronze,
  title: 'Demonstrate — a weekly submission, end to end',
  locator: 'Module 08 · Demonstrate',
  shotNum: '039',
  beat: 'BEAT · FILE',
  lead: 'The Review step before the data officer commits a weekly Audit Probe submission.',
  body: `The data officer taps + New on the Submission History page. The wizard opens at Step 1 (Period). They pick Start and End, pass validation, advance. Step 2 (Fill) renders the template's columns; the officer enters counts. Step 3 (Notes) is left blank — nothing exceptional this week. They reach Step 4 (Review).\n\nThe Review card summarises everything that is about to be submitted: report name, period, point of entry, submitted-by. The Values block shows the entered counts. The officer reads them once, finds no errors, taps Submit report. The submission queues for sync; the next sync pass uploads it.`,
  handoff: 'On next sync, the submission lands on the central server. Downstream views — the national administrator\'s screening intelligence dashboard — update on their next refresh.',
})
L_practice(pptx, {
  module: 'Module 08',
  accent: C.bronze,
  locator: 'Module 08 · Practice',
  scenario: 'File a weekly submission for last week from start to finish. On the Review step, deliberately leave a count empty and observe the warning. Go back, correct it, Submit. Then find the new row in Submission History.',
  prompts: [
    'Time the wizard end-to-end. Goal: under three minutes for a routine weekly submission.',
    'On the Review step the wizard warns "only zeros would be submitted" if values are missing — make sure each trainee triggers and reads that warning at least once.',
    'In Submission History, the trainee must find their submission, read its period and totals aloud to a neighbour, and confirm the sync state.',
  ],
  minutes: 12,
})
L_recap(pptx, {
  module: 'Module 08',
  accent: C.bronze,
  locator: 'Module 08 · Recap',
  items: [
    'Name the four steps of the submission wizard, in order.',
    'What warning does the Review step display when no values were entered?',
    'When does a submission move from Waiting to upload to Uploaded?',
    'Where does the data officer find a past submission to confirm it landed?',
    'What does the Notes step accept, and when is it worth filling in?',
    'How does the data officer return to the Fill step from Review without losing work?',
  ],
})

// ── 9. CROSS-CUTTING FEATURES ─────────────────────────────────────────────

L_section(pptx, {
  number: '09',
  title: 'Cross-cutting features',
  audience: 'EVERYONE — SYNC, SETTINGS, CAPABILITIES, STATUS REFERENCE',
  promise: 'The features no role owns alone but every role uses every day.',
  accent: C.navy,
})
L_hook(pptx, {
  module: 'Module 09',
  accent: C.navy,
  question: 'You captured ten records this morning. None of them have uploaded. Should you panic?',
  scene: 'Mid-shift at any site.',
  locator: 'Module 09 · Hook',
})
L_objectives(pptx, {
  module: 'Module 09',
  accent: C.navy,
  locator: 'Module 09 · Objectives',
  items: [
    'Read the sync centre and tell whether the device is healthy or backed up.',
    'Push a single store on demand and confirm the upload landed.',
    'Read the settings page and find the four facts a support channel asks for.',
    'Run plugin diagnostics and read the pass / warn / fail summary.',
    'Recall the five plain-language status words used everywhere in the app.',
  ],
})
L_present(pptx, {
  module: 'Module 09',
  accent: C.navy,
  title: 'The sync centre',
  locator: 'Module 09 · Present 1 of 4',
  shotNum: '049',
  intro: 'The operator\'s answer to "is everything I captured today actually on the server?"',
  hotspots: [
    { fx: 0.50, fy: 0.10, n: 1 },
    { fx: 0.50, fy: 0.30, n: 2 },
    { fx: 0.50, fy: 0.55, n: 3 },
  ],
  items: [
    { lead: 'KPI strip',         body: 'Synced / Pending / Failed / Quarantined totals across every store.' },
    { lead: 'Sync now',          body: 'Single-flight upload. Re-entrance guarded — tapping twice does not double-push.' },
    { lead: 'Per-store breakdown',body: 'Each store has its own sync button so the user can push one store at a time.' },
  ],
  retrieve: 'What does Pending mean in plain language?',
  time: 3,
})
L_present(pptx, {
  module: 'Module 09',
  accent: C.navy,
  title: 'Settings — the four cards a support channel asks for',
  locator: 'Module 09 · Present 2 of 4',
  shotNum: '058',
  intro: 'When a user reports something broken, the support channel asks for what they see on this card first.',
  hotspots: [
    { fx: 0.50, fy: 0.10, n: 1 },
    { fx: 0.50, fy: 0.30, n: 2 },
    { fx: 0.50, fy: 0.55, n: 3 },
    { fx: 0.50, fy: 0.85, n: 4 },
  ],
  items: [
    { lead: 'Connection',         body: 'Online or Working offline. The first thing support asks.' },
    { lead: 'App version',        body: 'The build the user is running. Mismatched builds explain many "X is broken" reports.' },
    { lead: 'Reference data',     body: 'Country / disease / POE catalogue version. If this is stale, the drop-downs will be too.' },
    { lead: 'Device id',          body: 'Stable identifier the support channel uses to find the device in logs.' },
  ],
  retrieve: 'Why does the support channel ask for the device id?',
  time: 3,
})
L_present(pptx, {
  module: 'Module 09',
  accent: C.navy,
  title: 'Capabilities and help',
  locator: 'Module 09 · Present 3 of 4',
  shotNum: '067',
  intro: 'The long-form complement to the Quick toggles in settings — for users who want to understand a feature before turning it on.',
  hotspots: [
    { fx: 0.50, fy: 0.20, n: 1 },
    { fx: 0.50, fy: 0.45, n: 2 },
    { fx: 0.50, fy: 0.70, n: 3 },
  ],
  items: [
    { lead: 'Capability card',  body: 'Plain-language description, current status, on/off toggle, and a "Try it now" demo.' },
    { lead: 'Try it now',       body: 'Probes the underlying plugin in a non-destructive way — no real recording, no real scan.' },
    { lead: 'Show me in the app', body: 'A spotlight tour that takes the user to where the feature actually shows up.' },
  ],
  retrieve: 'What does Try it now do that a real use of the feature would not?',
  time: 3,
})
L_present(pptx, {
  module: 'Module 09',
  accent: C.navy,
  title: 'Plugin diagnostics',
  locator: 'Module 09 · Present 4 of 4',
  shotNum: '065',
  intro: 'A self-test runner that probes every device-side feature for module load, permissions, and platform support.',
  hotspots: [
    { fx: 0.50, fy: 0.10, n: 1 },
    { fx: 0.50, fy: 0.35, n: 2 },
    { fx: 0.50, fy: 0.65, n: 3 },
  ],
  items: [
    { lead: 'Summary strip',     body: 'Pass / Warn / Fail / Skip totals plus the duration and the platform stamp.' },
    { lead: 'Filter chips',      body: 'Narrow the suite list to a specific status or area.' },
    { lead: 'Suite card',        body: 'Colour-coded; failing suites auto-expand to show per-test detail and a "Fix:" hint.' },
  ],
  teach: 'Diagnostics turns "it does not work" into "this specific permission is denied — go grant it in the phone\'s app settings."',
  retrieve: 'When would the user run diagnostics?',
  time: 3,
})
L_reference(pptx, {
  module: 'Module 09',
  accent: C.navy,
  title: 'Status badge reference — pocket card',
  locator: 'Module 09 · Reference',
  intro: 'These are the plain-language status words used everywhere in the app. Memorise them. They never change meaning across surfaces.',
  groups: [
    {
      title: 'Sync state',
      items: [
        { label: 'Uploaded',          kind: 'success', meaning: 'Server has the record. Done.' },
        { label: 'Waiting to upload', kind: 'warn',    meaning: 'Stored locally; will upload on next sync.' },
        { label: 'Queued',            kind: 'neutral', meaning: 'Server rejected; held for retry.' },
      ],
    },
    {
      title: 'Referral priority',
      items: [
        { label: 'Routine',     kind: 'neutral', meaning: 'No urgency; pick up in normal queue.' },
        { label: 'Urgent',      kind: 'warn',    meaning: 'Pick up within the shift.' },
        { label: 'Emergency',   kind: 'danger',  meaning: 'Pick up immediately — phone the officer if needed.' },
      ],
    },
    {
      title: 'Case state',
      items: [
        { label: 'Open',           kind: 'accent',  meaning: 'Referral has been raised; waiting for an officer.' },
        { label: 'Working on',     kind: 'warn',    meaning: 'A health officer is investigating.' },
        { label: 'Decision made',  kind: 'success', meaning: 'Disposition has been recorded.' },
      ],
    },
    {
      title: 'Symptom presence',
      items: [
        { label: 'Asymptomatic', kind: 'success', meaning: 'No symptoms recorded.' },
        { label: 'Symptomatic',  kind: 'danger',  meaning: 'One or more symptoms recorded.' },
      ],
    },
  ],
})
L_demo(pptx, {
  module: 'Module 09',
  accent: C.navy,
  title: 'Demonstrate — pushing a backed-up queue',
  locator: 'Module 09 · Demonstrate',
  shotNum: '049',
  beat: 'BEAT · PUSH',
  lead: 'The device has been offline for three hours. The queue shows 14 pending records.',
  body: `The user opens Sync. The KPI strip reads Synced 86, Pending 14, Failed 0. The user taps Sync now. A spinner appears under the button; the strip updates as records upload — Synced 90, Pending 10; Synced 95, Pending 5; Synced 100, Pending 0.\n\nThe spinner clears. Sync now reads "Everything in sync." The user goes back to their work. The whole operation took twenty seconds; nothing else needed touching.`,
})
L_practice(pptx, {
  module: 'Module 09',
  accent: C.navy,
  locator: 'Module 09 · Practice',
  scenario: 'Take your tablet offline. Capture three records. Read the sync KPI strip aloud. Bring the tablet back online. Push the queue. Read the strip again.',
  prompts: [
    'Note the wording on the badges before, during, and after sync.',
    'Open settings. Read the four cards a support channel would ask for.',
    'Run plugin diagnostics. If anything reads Warn or Fail, read the Fix line aloud.',
  ],
  minutes: 10,
})
L_recap(pptx, {
  module: 'Module 09',
  accent: C.navy,
  locator: 'Module 09 · Recap',
  items: [
    'What plain-language word is used for "stored locally, not yet on the server"?',
    'What does the sync engine guarantee when you tap Sync now twice in a row?',
    'Where on the settings page is the device id?',
    'What does "Try it now" on a capability card actually do?',
    'When the user runs plugin diagnostics, what does the green Fix: hint mean?',
    'Name the three sync-state words and what each means.',
    'What is the difference between Routine, Urgent, and Emergency?',
  ],
})

// ── 10. COMPLETE DATA FLOW ─────────────────────────────────────────────────

L_section(pptx, {
  number: '10',
  title: 'Complete data flow',
  audience: 'EVERYONE — ONE CASE FROM ORIGINATION TO CLOSURE',
  promise: 'Watch Joseph Phiri\'s case advance across every role we have met.',
  accent: C.bronze,
  dark: true,
})
L_essay(pptx, {
  module: 'Module 10',
  accent: C.bronze,
  title: 'How to read this module',
  lead: 'One fictional but realistic case follows the same screenshots we have already studied — but framed by which role is looking at it and what they need to do next.',
  sections: [
    {
      title: 'The protagonist',
      body: 'Joseph Phiri, male, 34, travelling by road from the Democratic Republic of Congo to LUSKIA. Symptomatic on arrival: fever 38.6°C, dry cough, three days of subjective fever.',
    },
    {
      title: 'The seven beats',
      body: 'Capture, Refer, Investigate, Analyse, Decide, Notify, Close. Each beat is one slide; the stage indicator at the top shows where in the sequence we are.',
    },
    {
      title: 'The hand-offs',
      body: 'Every beat ends with a hand-off line — the moment the case leaves one role and lands in the next. Read the hand-off out loud; the rhythm of hand-offs is the rhythm of the system.',
    },
    {
      title: 'The audit trail',
      body: 'Every beat creates a record. By the end of the case, the audit trail tells a complete story: who captured, who investigated, who acknowledged, who closed. The trail is the country\'s evidence base.',
    },
  ],
})
L_flowBeat(pptx, {
  module: 'Module 10',
  accent: C.bronze,
  title: 'Beat 1 — Joseph is captured',
  beat: 'CAPTURE · 09:14',
  stage: 'Capture',
  shotNum: '009',
  lead: 'A screener at LUSKIA captures Joseph in eleven seconds.',
  body: `Direction → Entry. Sex → Male. Temperature 38.6°C. The screener taps the Symptomatic card. The card itself is the commit — tapping it writes the primary screening and raises the referral together, in one transaction.`,
  handoff: 'The case leaves the screener\'s line and arrives in the health officer\'s inbox.',
  locator: 'Module 10 · Beat 1',
})
L_flowBeat(pptx, {
  module: 'Module 10',
  accent: C.bronze,
  title: 'Beat 2 — the referral is picked up',
  beat: 'REFER · 09:18',
  stage: 'Refer',
  shotNum: '022',
  lead: 'Four minutes later, the health officer opens the inbox.',
  body: `Joseph's referral is at the top of the list. The traveller name reads "Joseph Phiri", the originating point of entry reads "LUSKIA", the priority pill reads "Urgent". The officer taps Open. The wizard appears on Step 1.`,
  handoff: 'The case is now the health officer\'s. They will work through the four-step investigation.',
  locator: 'Module 10 · Beat 2',
})
L_flowBeat(pptx, {
  module: 'Module 10',
  accent: C.bronze,
  title: 'Beat 3 — investigation',
  beat: 'INVESTIGATE · 09:25 to 09:38',
  stage: 'Investigate',
  shotNum: '027',
  lead: 'Step 1 to Step 3. Profile, symptoms, exposures.',
  body: `The officer fills in age (34), nationality (ZM), passport details, conveyance type (ROAD), journey-start country (CD). On Step 2 they mark COUGH and FEVER as present, with onset three days ago. On Step 3 they add DRC as a country visited in the last 14 days and CONTACT_MARKET_LIVESTOCK as an exposure event.\n\nThey tap Save & Next from Step 3. The engine runs.`,
  handoff: 'The engine\'s output is what the officer sees on Step 4.',
  locator: 'Module 10 · Beat 3',
})
L_flowBeat(pptx, {
  module: 'Module 10',
  accent: C.bronze,
  title: 'Beat 4 — analysis',
  beat: 'ANALYSE · 09:39',
  stage: 'Analyse',
  shotNum: '028',
  lead: 'The engine output appears on Step 4.',
  body: `Syndrome RESPIRATORY. Risk HIGH. Routing PHEOC. Top-ranked disease INFLUENZA at 42% confidence, COVID-19 second at 27%. The recommended-actions panel reads: notify district within 24 hours, PHEOC within 7 days. The officer agrees with the engine — no override.`,
  handoff: 'The engine has written suspected-disease rows to the case. The officer is now ready to disposition.',
  locator: 'Module 10 · Beat 4',
})
L_flowBeat(pptx, {
  module: 'Module 10',
  accent: C.bronze,
  title: 'Beat 5 — disposition',
  beat: 'DECIDE · 09:42',
  stage: 'Decide',
  shotNum: '029',
  lead: 'The officer disposes the case as Referred.',
  body: `On Step 4 (Actions) the officer fixes the syndrome to RESPIRATORY, sets the Risk Level Assessment to HIGH, and watches the "Alert Auto-Triggered" yellow banner appear (HIGH_RISK_RESPIRATORY routed DISTRICT+). They pick Referred Hospital + Mask Given + Sample on the Actions Taken grid. The red footer reminding them that Risk HIGH requires Isolated or Referred Hospital is now satisfied. The case advances to DISPOSITIONED on commit.`,
  handoff: 'Within seconds, the district supervisor sees an alert appear on their active-alerts page.',
  locator: 'Module 10 · Beat 5',
})
L_flowBeat(pptx, {
  module: 'Module 10',
  accent: C.bronze,
  title: 'Beat 6 — district acknowledgement',
  beat: 'NOTIFY · 09:51',
  stage: 'Notify',
  shotNum: '032',
  lead: 'The district supervisor sees the alert and acknowledges.',
  body: `The alert title reads "Suspected respiratory cluster". The risk pill reads High. The supervisor taps the row, reads the alert detail, and taps Acknowledge. They type a one-line action: "Spoke to LUSKIA shift lead. Health officer has dispositioned, case has gone on to the district hospital."\n\nThe alert moves to Acknowledged. A row appears in alert history with the supervisor's name and the timestamp.`,
  handoff: 'PHEOC sees the same alert in their intelligence dashboard. The case is now visible at three levels at once.',
  locator: 'Module 10 · Beat 6',
})
L_flowBeat(pptx, {
  module: 'Module 10',
  accent: C.bronze,
  title: 'Beat 7 — close and audit',
  beat: 'CLOSE · 17:30',
  stage: 'Close',
  shotNum: '035',
  lead: 'End of day. The case is closed.',
  body: `The district hospital reports back: the patient has been admitted, investigations have begun, the IHR-1 alert has been propagated. The supervisor revisits the alert and closes it. The audit trail now contains: capture row, referral row, four wizard-step saves, disposition row, district acknowledge, district close — with author and timestamp on every entry.\n\nThe national administrator sees the case in the next day's screening intelligence dashboard. By next week, the data officer's aggregated submission for LUSKIA includes Joseph in the symptomatic count.`,
  handoff: 'The case is closed. The system retains the trail forever.',
  locator: 'Module 10 · Beat 7',
})

// ── 11. FOR DEVELOPERS AND SYSTEM ADMINISTRATORS ──────────────────────────

L_section(pptx, {
  number: '11',
  title: 'For developers and system administrators',
  audience: 'TECHNICAL READERS — ARCHITECTURE, SCHEMA, SYNC, PLUGINS',
  promise: 'The technical layer that makes the front-line app behave the way the rest of this manual described.',
  accent: C.graphite,
  dark: true,
})
L_essay(pptx, {
  module: 'Module 11',
  accent: C.graphite,
  title: 'Architecture in one page',
  lead: 'Vue 3 + Ionic 8 + Capacitor 8 on Android, with IndexedDB as the local source of truth and a Laravel backend over HTTP/JSON. Offline-first. Atomic local writes. Single-flight sync. WHO/IHR-aligned data model.',
  sections: [
    {
      title: 'Frontend',
      body: 'Vue 3 single-file components, Ionic 8 components for chrome and gestures, Vite build. Ionic\'s ion-content shadow scroller is the canonical scrollable container — note that headless Chrome reports it as 0×0 (see capture rig docs).',
    },
    {
      title: 'Local store',
      body: 'IndexedDB at version 16+, declared once in src/services/poeDB.js. 17 stores covering users, primary screenings, notifications, secondary screenings + child tables, alerts + followups, aggregated submissions/templates, POE contacts, and sync batches.',
    },
    {
      title: 'Sync engine',
      body: 'Single-flight uploader with re-entrance guard. AbortController-bounded fetches (8s timeout). Per-store sync_status enum: UNSYNCED → SYNCED or FAILED. record_version field guards against stale writes via safeDbPut().',
    },
    {
      title: 'Plugins',
      body: 'Capacitor wrappers for camera (BCBP), barcode scanning (ML Kit), voice (speech-recognition), keep-awake, biometric auth, local notifications, network probe. Master kill-switch + per-feature flags. Diagnostics runner probes module load, gate state, permission state, platform support.',
    },
  ],
})
L_essay(pptx, {
  module: 'Module 11',
  accent: C.graphite,
  title: 'The two atomic writes that hold the system together',
  lead: 'Both happen in single IndexedDB transactions across multiple stores. Both are the system\'s correctness boundary; everything else is glue.',
  sections: [
    {
      title: 'Capture-with-referral',
      body: 'When the screener taps Capture on a symptomatic primary, the app writes the primary_screenings row AND the notifications row in one readwrite transaction over both stores. Either both land or neither does — there is no partial state. Implementation: dbAtomicWrite() in poeDB.js.',
    },
    {
      title: 'Replace-all on secondary children',
      body: 'When the secondary wizard saves a step, the symptoms / exposures / travel-countries arrays are replaced wholesale via dbReplaceAll(). One transaction deletes all existing children for the case via the secondary_screening_id index, then inserts the fresh array. No per-row diffing — the wizard owns the entire child set.',
    },
    {
      title: 'safeDbPut and stale writes',
      body: 'Async writes (sync callbacks, toggles) compare record_version on the incoming record against the stored value. Higher stored version blocks the write. Local edits always win against late-arriving sync data. Uses STORE_KEY to locate the keyPath transparently.',
    },
    {
      title: 'IHR alignment',
      body: 'The case_status enum (OPEN, IN_PROGRESS, DISPOSITIONED, CLOSED), the four wizard steps, and the engine\'s syndrome / risk / routing fields map cleanly to the IHR Annex 2 decision instrument. The engine is rule-based; suspected_diseases rows carry the rule that fired and a confidence score.',
    },
  ],
})
L_essay(pptx, {
  module: 'Module 11',
  accent: C.graphite,
  title: 'The status vocabulary, deconstructed',
  lead: 'Front-line surfaces use plain language; backend stores use canonical SQL ENUMs. The front-line view of any record always derives from the canonical column.',
  sections: [
    {
      title: 'Sync state',
      body: 'UNSYNCED → labelled "Waiting to upload". SYNCED → "Uploaded". FAILED → "Queued" (retried). All three are SQL ENUM values declared in poe_2026.sql; mirror constants live on SYNC.LABELS in poeDB.js.',
    },
    {
      title: 'Case state',
      body: 'case_status SQL ENUM: OPEN, IN_PROGRESS, DISPOSITIONED, CLOSED. UI labels in plain language: Open, Working on, Decision made, Closed. The wizard step lock-out logic reads case_status === DISPOSITIONED.',
    },
    {
      title: 'Priority',
      body: 'Notifications.priority: ROUTINE / URGENT / CRITICAL. UI labels: Routine, Urgent, Emergency. The mapping is intentional — CRITICAL is the WHO term, Emergency is what the front-line user understands.',
    },
    {
      title: 'IHR tier',
      body: 'Diseases catalogue has a tier column: 1 (always notify), 2 (criteria-dependent). Front-line surfaces never show the number; they show the disease in the recommended-actions panel only.',
    },
  ],
})
L_present(pptx, {
  module: 'Module 11',
  accent: C.graphite,
  title: 'Sentinel — capture accelerators in the on-state',
  locator: 'Module 11 · Sentinel',
  shotNum: '070',
  intro: 'Sentinel is the umbrella for the optional capture accelerators (boarding-pass barcode, voice fill, ML Kit barcode). Master kill-switch defaults to OFF.',
  hotspots: [
    { fx: 0.50, fy: 0.20, n: 1 },
    { fx: 0.50, fy: 0.45, n: 2 },
    { fx: 0.50, fy: 0.70, n: 3 },
  ],
  items: [
    { lead: 'Master switch',     body: 'When ON, the per-feature toggles below it become live. Defaults to OFF in production.' },
    { lead: 'Per-feature flags', body: 'Boarding-pass barcode, Voice fill — each requires its own platform permission to function.' },
    { lead: 'Disable all',       body: 'A panic button that flips master + every per-feature flag to OFF in one tap.' },
  ],
  notes: notes({
    teach: 'The Sentinel master flag is the only flag in the app that gates whether the OS-level prompt fires. Off means the buttons do not even render. On means the buttons render and the user can request the OS permission.',
    script: `Walk the room around the three annotations. Stress: this is the only place in the app where toggling something can trigger an OS prompt. The capture rig holds master OFF for every screenshot — the screenshot you are looking at was a one-shot exception in a separate run, with the flag flipped back immediately after.`,
    time: 4,
    confuse: ['Trainees often expect Sentinel to be on by default. It is not — by design, capture is manual until proven otherwise.'],
  }),
})
L_recap(pptx, {
  module: 'Module 11',
  accent: C.graphite,
  locator: 'Module 11 · Recap',
  items: [
    'Which atomic write keeps the primary screening and the referral consistent?',
    'What does dbReplaceAll do, and where is it used?',
    'How does safeDbPut decide whether to apply an incoming record?',
    'What does case_status === DISPOSITIONED do to the wizard?',
    'Why does the front-line UI never show the IHR tier number?',
    'What is the production default of the Sentinel master switch?',
    'What does the diagnostics runner check on each Capacitor plugin?',
    'Where in the code is every IndexedDB store declared exactly once?',
  ],
})

// ── 12. CLOSING ────────────────────────────────────────────────────────────

L_section(pptx, {
  number: '12',
  title: 'Closing and resources',
  audience: 'EVERYONE',
  promise: 'What to do after the course ends.',
  accent: C.bronze,
  dark: false,
})
L_closing(pptx, {
  module: 'Module 12',
  accent: C.bronze,
  title: 'After this course',
  locator: 'Module 12 · Closing',
  lead: 'The deck is yours. Keep the pocket reference. Run the recap once a quarter to refresh.',
  next: [
    'Practise the workflow on your own tablet for one full shift, with a peer observing.',
    'Pin the status-badge reference card to your phone\'s home screen.',
    'Re-read the module that matches your role at one-month, three-month, and twelve-month intervals.',
    'Sit in on a peer\'s training session as an observer; offer one improvement to their delivery.',
  ],
  help: [
    'Your shift lead is the first port of call for any in-the-moment question.',
    'The point-of-entry contacts roster lists the right escalation order for your site.',
    'The plugin diagnostics page is the right starting point for "the app is broken".',
    'The capabilities-and-help page replays the /welcome view and runs feature demos.',
  ],
})
L_backCover(pptx, {
  version: '1.0',
  build: new Date().toISOString().slice(0, 10),
  contact: 'Speak to the national programme office for corrections, additions, and translations.',
})

// ═══════════════════════════════════════════════════════════════════════════
// SAVE
// ═══════════════════════════════════════════════════════════════════════════

console.log('  Building...')
await pptx.writeFile({ fileName: OUT_PPTX, compression: true })

// Post-process: pptxgenjs hard-codes generic "Slide N" entries in
// docProps/app.xml's TitlesOfParts list, regardless of the slide names we
// set on slide._name. Patch it so the outline pane and Slide-Titles document
// property show meaningful titles. Required for §11 accessibility compliance.
{
  const JSZip = (await import('/tmp/tot-tools/node_modules/jszip/dist/jszip.min.js')).default
  const buf = fs.readFileSync(OUT_PPTX)
  const zip = await JSZip.loadAsync(buf)
  const appXml = await zip.file('docProps/app.xml').async('string')
  // Read each slide's actual cSld name (reflects the post-build truth).
  const slideNames = []
  for (let i = 1; ; i++) {
    const f = zip.file(`ppt/slides/slide${i}.xml`)
    if (!f) break
    const s = await f.async('string')
    const m = s.match(/<p:cSld[^>]*name="([^"]*)"/)
    slideNames.push(m ? m[1] : `Slide ${i}`)
  }
  const newEntries = slideNames
    .map(n => `<vt:lpstr>${n.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')}</vt:lpstr>`)
    .join('')
  const patched = appXml.replace(
    /(<vt:lpstr>Office Theme<\/vt:lpstr>\s*)(?:<vt:lpstr>Slide \d+<\/vt:lpstr>\s*)+/,
    `$1${newEntries}`
  )
  if (patched !== appXml) {
    zip.file('docProps/app.xml', patched)
    const out = await zip.generateAsync({ type: 'nodebuffer', compression: 'DEFLATE' })
    fs.writeFileSync(OUT_PPTX, out)
    console.log(`  Patched docProps/app.xml: ${slideNames.length} slide titles`)
  } else {
    console.log('  Skipped app.xml patch (no match)')
  }
}

const stat = fs.statSync(OUT_PPTX)
console.log(`✓ ${path.relative(REPO, OUT_PPTX)} — ${(stat.size / 1024 / 1024).toFixed(1)} MB`)
