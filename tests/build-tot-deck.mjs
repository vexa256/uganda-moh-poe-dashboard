/**
 * tests/build-tot-deck.mjs
 *
 * National Training-of-Trainers manual — POE Sentinel.
 *
 * Premium top-shelf design, instructional-design pattern: each module follows
 *   Hook (why this matters) → Objectives → Present (concept) → Demonstrate
 *   (annotated screenshot) → Practice (hands-on) → Assess (recap quiz)
 *
 * Layout library (12 distinct types) enforces Mayer's multimedia learning
 * principles: signaling, contiguity, segmenting, redundancy avoidance,
 * coherence (no decorative clutter).
 *
 * Output:
 *   _audit/PRESENTATION/POE_Sentinel_TOT_Manual.pptx
 *   _audit/PRESENTATION/TOT_OUTLINE.md
 *
 * Source images: _audit/PRESENTATION/screenshots and screening-flow/screenshots
 */

import path from 'node:path'
import fs from 'node:fs'
import { fileURLToPath } from 'node:url'
import PptxGenJS from '/tmp/tot-tools/node_modules/pptxgenjs/dist/pptxgen.cjs.js'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const REPO = path.resolve(__dirname, '..')
const FULL = path.resolve(REPO, '_audit/PRESENTATION/screenshots')
const FLOW = path.resolve(REPO, '_audit/PRESENTATION/screening-flow/screenshots')
const OUT_PPTX = path.resolve(REPO, '_audit/PRESENTATION/POE_Sentinel_TOT_Manual.pptx')
const OUT_OUTLINE = path.resolve(REPO, '_audit/PRESENTATION/TOT_OUTLINE.md')

const fnFull = (n) => path.join(FULL, n)
const fnFlow = (n) => path.join(FLOW, n)
const exists = (p) => { try { fs.statSync(p); return true } catch { return false } }

// ═══════════════════════════════════════════════════════════════════════════
// DESIGN SYSTEM — Tonal palette, type ramp, spacing tokens
// ═══════════════════════════════════════════════════════════════════════════

// Tonal navy + tonal teal + accent tonal Zambia colors. Each role gets its
// own primary tone so a reader can flip the deck and instantly identify which
// audience they're in.
const C = {
  // INK ramp (text colours, lightest to darkest)
  ink900: '0A1929',  // headings on white
  ink700: '1F2937',  // body text on white
  ink500: '475569',  // secondary text
  ink300: '94A3B8',  // tertiary
  ink100: 'CBD5E1',  // dividers
  ink050: 'F1F5F9',  // chip bg

  // Surfaces
  white:  'FFFFFF',
  paper:  'FBFAF7',  // warm white background (premium feel)
  cream:  'F4EFE6',  // warm tone for separator slides
  navyBg: '0A1F3D',  // dark mode bg

  // Brand navy ramp
  navy900: '0A1929',
  navy700: '0F3460',
  navy500: '1B4D8C',
  navy300: '5A85C2',
  navy100: 'EBF1FB',

  // Brand teal ramp
  teal700: '00827A',
  teal500: '00B4A6',
  teal300: '7AD9CE',
  teal100: 'D6F5EE',

  // Zambia palette
  green:   '198A3D',
  red:     'D63027',
  orange:  'F39200',
  black:   '111111',

  // Status semantic
  success: '15803D',
  warning: 'B45309',
  danger:  'B91C1C',
  successBg: 'DCFCE7',
  warningBg: 'FEF3C7',
  dangerBg:  'FEE2E2',

  // Role accents (each role gets its own tonal duo)
  rEveryone:  ['0F3460', '1B4D8C', 'EBF1FB'],
  rScreener:  ['00827A', '00B4A6', 'D6F5EE'],
  rSecondary: ['0369A1', '0EA5E9', 'E0F2FE'],
  rDistrict:  ['6D28D9', '8B5CF6', 'EDE9FE'],
  rPheoc:     ['BE185D', 'EC4899', 'FCE7F3'],
  rNational:  ['B45309', 'F59E0B', 'FEF3C7'],
  rData:      ['0E7490', '06B6D4', 'CFFAFE'],
  rCross:     ['334155', '64748B', 'F1F5F9'],
  rFlow:      ['15803D', '22C55E', 'DCFCE7'],
  rDev:       ['1E293B', '475569', 'E2E8F0'],
}

// Type ramp — Calibri only (universally available); use weight + size for hierarchy
const T = {
  hero:    { fontFace: 'Calibri', fontSize: 60, bold: true, charSpacing: 2 },
  display: { fontFace: 'Calibri', fontSize: 44, bold: true },
  h1:      { fontFace: 'Calibri', fontSize: 32, bold: true },
  h2:      { fontFace: 'Calibri', fontSize: 22, bold: true },
  h3:      { fontFace: 'Calibri', fontSize: 16, bold: true },
  lede:    { fontFace: 'Calibri', fontSize: 18 },
  body:    { fontFace: 'Calibri', fontSize: 14 },
  small:   { fontFace: 'Calibri', fontSize: 11 },
  caption: { fontFace: 'Calibri', fontSize: 9, charSpacing: 4 },
  mono:    { fontFace: 'Consolas', fontSize: 10 },
}

// Slide grid — 13.333 × 7.5 inches (16:9). Standard 0.6" margin.
const G = {
  M: 0.6,        // outer margin
  W: 13.333,     // slide width
  H: 7.5,        // slide height
  topY: 0.6,     // header band starts
  bodyY: 1.4,    // body starts
  footY: 7.0,    // footer starts
}

// ═══════════════════════════════════════════════════════════════════════════
// LAYOUT LIBRARY
// ═══════════════════════════════════════════════════════════════════════════

// Decorative Zambia stripe
function zambiaStripe(slide, y = 7.38, h = 0.12) {
  const w = G.W / 4
  ;[C.green, C.red, C.black, C.orange].forEach((color, i) => {
    slide.addShape('rect', { x: i*w, y, w, h, fill: { color }, line: { color } })
  })
}

// Subtle paper background with a cross-hatched corner mark — "premium document" feel
function premiumBackground(slide, color = C.paper) {
  slide.background = { color }
}

// Top header bar with role accent + module + slide pagination
function premiumHeader(slide, opts) {
  const accent = opts.accent || C.navy700
  // Thin accent rule along the top
  slide.addShape('rect', { x: 0, y: 0, w: G.W, h: 0.06, fill: { color: accent }, line: { color: accent } })

  // Brand mark — top-left
  slide.addText([
    { text: 'POE SENTINEL', options: { bold: true, color: C.ink900, fontSize: 9, charSpacing: 4 } },
    { text: '   ·   ZNPHI', options: { color: C.ink500, fontSize: 9, charSpacing: 4 } },
  ], { x: G.M, y: 0.18, w: 5, h: 0.3, fontFace: 'Calibri' })

  // Module / page label — top-right
  if (opts.moduleLabel) {
    slide.addText(opts.moduleLabel, {
      x: G.W - 5 - G.M, y: 0.18, w: 5, h: 0.3,
      fontFace: 'Calibri', fontSize: 9, color: C.ink500, charSpacing: 4, bold: true, align: 'right',
    })
  }
}

// Footer line — page number, course version
function premiumFooter(slide, opts) {
  // Hairline divider above footer
  slide.addShape('line', { x: G.M, y: G.footY + 0.02, w: G.W - 2*G.M, h: 0,
    line: { color: C.ink100, width: 0.5 } })
  // Left: module ID
  slide.addText(opts.footerLeft || '', {
    x: G.M, y: G.footY + 0.1, w: 5, h: 0.3,
    fontFace: 'Calibri', fontSize: 9, color: C.ink500,
  })
  // Right: page number
  slide.addText(opts.pageStr || '', {
    x: G.W - 5 - G.M, y: G.footY + 0.1, w: 5, h: 0.3,
    fontFace: 'Calibri', fontSize: 9, color: C.ink500, align: 'right', italic: true,
  })
}

// ── Layout 1: HERO COVER (full bleed) ──────────────────────────────────────
function L_cover(pptx, opts) {
  const s = pptx.addSlide()
  s.background = { color: C.navyBg }
  // Top accent bar with role colour
  s.addShape('rect', { x: 0, y: 0, w: G.W, h: 0.18, fill: { color: opts.accent || C.teal500 }, line: { color: opts.accent || C.teal500 } })
  // Decorative orbs (subtle)
  s.addShape('ellipse', { x: -1.5, y: -1.5, w: 4, h: 4, fill: { color: opts.accent || C.teal500, transparency: 92 }, line: { color: opts.accent || C.teal500, transparency: 92 } })
  s.addShape('ellipse', { x: 10, y: 5, w: 6, h: 6, fill: { color: C.orange, transparency: 94 }, line: { color: C.orange, transparency: 94 } })

  // ZNPHI eyebrow
  s.addText('ZNPHI · ECSA-HC · MINISTRY OF HEALTH · REPUBLIC OF ZAMBIA', {
    x: G.M, y: 0.7, w: G.W - 2*G.M, h: 0.4,
    fontFace: 'Calibri', fontSize: 10, color: C.white, transparency: 25, bold: true, charSpacing: 6,
  })

  s.addText(opts.title || 'POE SENTINEL', {
    x: G.M, y: 1.6, w: G.W - 2*G.M, h: 1.4,
    fontFace: 'Calibri', fontSize: 72, color: C.white, bold: true, charSpacing: 4,
  })
  s.addText(opts.subtitle || '', {
    x: G.M, y: 3.2, w: G.W - 2*G.M, h: 0.8,
    fontFace: 'Calibri', fontSize: 24, color: opts.accent || C.teal500, bold: true,
  })
  if (opts.body) {
    s.addText(opts.body, {
      x: G.M, y: 4.2, w: G.W - 2*G.M, h: 2.0,
      fontFace: 'Calibri', fontSize: 16, color: C.white, transparency: 18,
      paraSpaceAfter: 8,
    })
  }

  // Bottom meta row
  const metaY = 6.45
  s.addShape('line', { x: G.M, y: metaY, w: G.W - 2*G.M, h: 0,
    line: { color: C.white, transparency: 70, width: 0.5 } })
  s.addText('COURSE', {
    x: G.M, y: metaY + 0.15, w: 2, h: 0.3,
    fontFace: 'Calibri', fontSize: 9, color: C.white, transparency: 35, bold: true, charSpacing: 4,
  })
  s.addText(opts.courseName || 'Training of Trainers · v1.0', {
    x: G.M, y: metaY + 0.45, w: 5, h: 0.3,
    fontFace: 'Calibri', fontSize: 12, color: C.white, transparency: 10, bold: true,
  })
  s.addText('AUDIENCE', {
    x: 7, y: metaY + 0.15, w: 2, h: 0.3,
    fontFace: 'Calibri', fontSize: 9, color: C.white, transparency: 35, bold: true, charSpacing: 4,
  })
  s.addText(opts.audience || 'National Master Trainers', {
    x: 7, y: metaY + 0.45, w: 6, h: 0.3,
    fontFace: 'Calibri', fontSize: 12, color: C.white, transparency: 10, bold: true,
  })
  zambiaStripe(s)
  return s
}

// ── Layout 2: MODULE COVER (number + audience + duration) ──────────────────
function L_moduleCover(pptx, opts) {
  const role = opts.role || C.rEveryone
  const dark = role[0]
  const mid  = role[1]
  const tint = role[2]
  const s = pptx.addSlide()
  s.background = { color: dark }

  // Massive translucent module number (right side)
  s.addText(`${opts.number}`, {
    x: 6.5, y: 0.5, w: 6.5, h: 6.5,
    fontFace: 'Calibri', fontSize: 460, color: C.white, transparency: 88, bold: true, align: 'right',
  })

  // Eyebrow
  s.addText('MODULE', {
    x: G.M, y: 1.8, w: 6, h: 0.4,
    fontFace: 'Calibri', fontSize: 14, color: C.white, transparency: 35, bold: true, charSpacing: 8,
  })

  // Title
  s.addText(opts.title, {
    x: G.M, y: 2.3, w: 8, h: 1.6,
    fontFace: 'Calibri', fontSize: 48, color: C.white, bold: true,
  })

  // Subtitle
  if (opts.subtitle) {
    s.addText(opts.subtitle, {
      x: G.M, y: 4.0, w: 9, h: 0.8,
      fontFace: 'Calibri', fontSize: 20, color: mid, bold: true,
    })
  }

  // Meta strip — 3 items
  const my = 5.4
  const items = [
    { eyebrow: 'AUDIENCE', value: opts.audience || '' },
    { eyebrow: 'RUNTIME',  value: opts.duration || '' },
    { eyebrow: 'OUTCOME',  value: opts.outcome  || 'Confidence to teach this module' },
  ]
  items.forEach((it, i) => {
    const x = G.M + i * 4.1
    s.addShape('rect', { x, y: my, w: 0.04, h: 1.0, fill: { color: mid }, line: { color: mid } })
    s.addText(it.eyebrow, {
      x: x + 0.12, y: my, w: 4, h: 0.3,
      fontFace: 'Calibri', fontSize: 9, color: C.white, transparency: 35, bold: true, charSpacing: 4,
    })
    s.addText(it.value, {
      x: x + 0.12, y: my + 0.32, w: 4, h: 0.7,
      fontFace: 'Calibri', fontSize: 13, color: C.white, transparency: 5, bold: true, valign: 'top',
    })
  })
  zambiaStripe(s)
  return s
}

// ── Layout 3: HOOK (big quote / why this matters) ──────────────────────────
function L_hook(pptx, opts) {
  const role = opts.role || C.rEveryone
  const s = pptx.addSlide()
  premiumBackground(s, C.cream)
  premiumHeader(s, { accent: role[0], moduleLabel: opts.moduleLabel })

  // Eyebrow
  s.addText('WHY THIS MATTERS', {
    x: G.M, y: G.bodyY, w: G.W - 2*G.M, h: 0.4,
    fontFace: 'Calibri', fontSize: 11, color: role[0], bold: true, charSpacing: 6,
  })

  // Big quote
  s.addText('"' + opts.quote + '"', {
    x: G.M + 0.3, y: 2.1, w: G.W - 2*G.M - 0.6, h: 3.5,
    fontFace: 'Georgia', fontSize: 36, color: C.ink900, italic: true,
    paraSpaceAfter: 8,
  })

  // Attribution / context
  if (opts.attribution) {
    s.addText('— ' + opts.attribution, {
      x: G.M + 0.3, y: 5.7, w: G.W - 2*G.M - 0.6, h: 0.4,
      fontFace: 'Calibri', fontSize: 13, color: C.ink500, bold: true,
    })
  }
  if (opts.context) {
    s.addText(opts.context, {
      x: G.M + 0.3, y: 6.1, w: G.W - 2*G.M - 0.6, h: 0.7,
      fontFace: 'Calibri', fontSize: 12, color: C.ink700, italic: true,
    })
  }
  premiumFooter(s, { footerLeft: opts.moduleLabel || '', pageStr: opts.pageStr })
  return s
}

// ── Layout 4: OBJECTIVES (numbered list with verbs from Bloom) ─────────────
function L_objectives(pptx, opts) {
  const role = opts.role || C.rEveryone
  const s = pptx.addSlide()
  premiumBackground(s, C.paper)
  premiumHeader(s, { accent: role[0], moduleLabel: opts.moduleLabel })

  s.addText('LEARNING OBJECTIVES', {
    x: G.M, y: G.bodyY, w: G.W - 2*G.M, h: 0.4,
    fontFace: 'Calibri', fontSize: 11, color: role[0], bold: true, charSpacing: 6,
  })
  s.addText(opts.title || 'By the end of this module you can…', {
    x: G.M, y: 1.85, w: G.W - 2*G.M, h: 0.9,
    fontFace: 'Calibri', fontSize: 30, color: C.ink900, bold: true,
  })

  // 2-column grid of objective cards, max 6
  const objectives = (opts.objectives || []).slice(0, 6)
  const colW = (G.W - 2*G.M - 0.4) / 2
  const rowH = 1.05
  objectives.forEach((o, i) => {
    const col = i % 2
    const row = Math.floor(i / 2)
    const x = G.M + col * (colW + 0.4)
    const y = 3.0 + row * (rowH + 0.2)

    // Card
    s.addShape('roundRect', { x, y, w: colW, h: rowH, fill: { color: C.white }, line: { color: C.ink100, width: 0.75 }, rectRadius: 0.06 })
    // Number badge
    s.addShape('rect', { x, y, w: 0.55, h: rowH, fill: { color: role[0] }, line: { color: role[0] } })
    s.addText(`${i + 1}`, {
      x, y, w: 0.55, h: rowH,
      fontFace: 'Calibri', fontSize: 24, color: C.white, bold: true, align: 'center', valign: 'middle',
    })
    // Verb (Bloom)
    s.addText(o.verb || 'Apply', {
      x: x + 0.7, y: y + 0.1, w: colW - 0.85, h: 0.3,
      fontFace: 'Calibri', fontSize: 10, color: role[0], bold: true, charSpacing: 4,
    })
    // Objective text
    s.addText(o.text, {
      x: x + 0.7, y: y + 0.36, w: colW - 0.85, h: rowH - 0.4,
      fontFace: 'Calibri', fontSize: 12, color: C.ink700, valign: 'top',
    })
  })

  premiumFooter(s, { footerLeft: opts.moduleLabel || '', pageStr: opts.pageStr })
  return s
}

// ── Layout 5: ANNOTATED SCREENSHOT (numbered hotspots on the image) ────────
// Phone screenshots are 412×915 → aspect 0.45. With h=6.0 the rendered width
// is about 2.7" — we set w=2.7 to keep the math tight.
function L_annotated(pptx, opts) {
  const role = opts.role || C.rEveryone
  const s = pptx.addSlide()
  premiumBackground(s, C.white)
  premiumHeader(s, { accent: role[0], moduleLabel: opts.moduleLabel })

  // Eyebrow + title (top)
  s.addText(opts.eyebrow || 'DEMONSTRATION', {
    x: G.M, y: G.bodyY - 0.4, w: G.W - 2*G.M, h: 0.3,
    fontFace: 'Calibri', fontSize: 10, color: role[0], bold: true, charSpacing: 5,
  })
  s.addText(opts.title || '', {
    x: G.M, y: G.bodyY - 0.1, w: G.W - 2*G.M, h: 0.6,
    fontFace: 'Calibri', fontSize: 24, color: C.ink900, bold: true,
  })
  if (opts.lede) {
    s.addText(opts.lede, {
      x: G.M, y: G.bodyY + 0.55, w: G.W - 2*G.M, h: 0.7,
      fontFace: 'Calibri', fontSize: 13, color: C.ink500, italic: false,
    })
  }

  // Phone frame on the LEFT (with a soft shadow rectangle behind)
  const phoneX = G.M
  const phoneY = G.bodyY + 1.4
  const phoneW = 2.7
  const phoneH = 6.0
  // shadow card
  s.addShape('roundRect', { x: phoneX - 0.05, y: phoneY + 0.1, w: phoneW + 0.1, h: phoneH,
    fill: { color: C.ink050 }, line: { color: C.ink050 }, rectRadius: 0.18 })
  // phone surround
  s.addShape('roundRect', { x: phoneX - 0.08, y: phoneY - 0.08, w: phoneW + 0.16, h: phoneH + 0.16,
    fill: { color: C.ink900 }, line: { color: C.ink900 }, rectRadius: 0.22 })
  // Image
  if (opts.image && exists(opts.image)) {
    s.addImage({ path: opts.image, x: phoneX, y: phoneY, w: phoneW, h: phoneH,
      sizing: { type: 'contain', w: phoneW, h: phoneH } })
  }

  // Annotations on the screenshot — numbered red circles, each tied to a bullet on the right
  const ann = opts.annotations || []
  ann.forEach((a, i) => {
    const cx = phoneX + (a.x || 0.5) * phoneW
    const cy = phoneY + (a.y || 0.5) * phoneH
    s.addShape('ellipse', { x: cx - 0.16, y: cy - 0.16, w: 0.32, h: 0.32,
      fill: { color: role[1] }, line: { color: C.white, width: 1.5 } })
    s.addText(`${i + 1}`, {
      x: cx - 0.16, y: cy - 0.16, w: 0.32, h: 0.32,
      fontFace: 'Calibri', fontSize: 11, color: C.white, bold: true, align: 'center', valign: 'middle',
    })
  })

  // Right column: numbered bullet list matching annotations + supplementary bullets
  const rx = phoneX + phoneW + 0.55
  const rw = G.W - rx - G.M
  let ry = G.bodyY + 1.4
  if (ann.length) {
    const items = ann.map((a, i) => ({
      text: a.text,
      options: {
        bullet: { type: 'number', startAt: i + 1, color: role[1] },
        fontFace: 'Calibri', fontSize: 12, color: C.ink700, paraSpaceAfter: 6,
      },
    }))
    s.addText(items, { x: rx, y: ry, w: rw, h: 0.4 * ann.length + 1.0, valign: 'top' })
    ry += 0.45 * ann.length + 0.3
  }
  if (opts.notes) {
    s.addShape('rect', { x: rx, y: ry, w: rw, h: 0.04, fill: { color: C.ink100 }, line: { color: C.ink100 } })
    ry += 0.2
    s.addText('REMEMBER', {
      x: rx, y: ry, w: rw, h: 0.3,
      fontFace: 'Calibri', fontSize: 9, color: role[0], bold: true, charSpacing: 4,
    })
    s.addText(opts.notes, {
      x: rx, y: ry + 0.25, w: rw, h: 1.2,
      fontFace: 'Calibri', fontSize: 12, color: C.ink700, italic: true,
    })
  }

  premiumFooter(s, { footerLeft: opts.moduleLabel || '', pageStr: opts.pageStr })
  return s
}

// ── Layout 6: SPLIT 2-UP (concept on left, screenshot on right or vice versa)
function L_split(pptx, opts) {
  const role = opts.role || C.rEveryone
  const s = pptx.addSlide()
  premiumBackground(s, C.paper)
  premiumHeader(s, { accent: role[0], moduleLabel: opts.moduleLabel })

  s.addText(opts.eyebrow || '', {
    x: G.M, y: G.bodyY - 0.4, w: G.W - 2*G.M, h: 0.3,
    fontFace: 'Calibri', fontSize: 10, color: role[0], bold: true, charSpacing: 5,
  })
  s.addText(opts.title || '', {
    x: G.M, y: G.bodyY - 0.1, w: G.W - 2*G.M, h: 0.6,
    fontFace: 'Calibri', fontSize: 26, color: C.ink900, bold: true,
  })

  // Left side: text card
  const lx = G.M
  const ly = G.bodyY + 0.7
  const lw = 7.6
  const lh = 5.5
  s.addShape('roundRect', { x: lx, y: ly, w: lw, h: lh,
    fill: { color: C.white }, line: { color: C.ink100, width: 0.5 }, rectRadius: 0.08 })
  if (opts.lede) {
    s.addText(opts.lede, {
      x: lx + 0.3, y: ly + 0.3, w: lw - 0.6, h: 1.0,
      fontFace: 'Calibri', fontSize: 16, color: C.ink900, bold: true,
    })
  }
  if (opts.bullets) {
    const items = opts.bullets.map((b) => ({
      text: typeof b === 'string' ? b : b.text,
      options: { bullet: { code: '25A0' },
        fontFace: 'Calibri', fontSize: 12, color: C.ink700, paraSpaceAfter: 6 },
    }))
    s.addText(items, { x: lx + 0.3, y: ly + 1.4, w: lw - 0.6, h: lh - 1.6, valign: 'top' })
  }

  // Right side: phone screenshot
  const px = lx + lw + 0.4
  const py = ly
  const pw = G.W - px - G.M
  const ph = lh
  s.addShape('roundRect', { x: px - 0.05, y: py - 0.05, w: pw + 0.1, h: ph + 0.1,
    fill: { color: C.ink900 }, line: { color: C.ink900 }, rectRadius: 0.18 })
  if (opts.image && exists(opts.image)) {
    s.addImage({ path: opts.image, x: px, y: py, w: pw, h: ph,
      sizing: { type: 'contain', w: pw, h: ph } })
  }

  premiumFooter(s, { footerLeft: opts.moduleLabel || '', pageStr: opts.pageStr })
  return s
}

// ── Layout 7: BIG STAT (single number + meaning) ───────────────────────────
function L_bigStat(pptx, opts) {
  const role = opts.role || C.rEveryone
  const s = pptx.addSlide()
  premiumBackground(s, role[2]) // tint background
  premiumHeader(s, { accent: role[0], moduleLabel: opts.moduleLabel })

  s.addText(opts.eyebrow || 'A STATISTIC', {
    x: G.M, y: 1.3, w: G.W - 2*G.M, h: 0.4,
    fontFace: 'Calibri', fontSize: 11, color: role[0], bold: true, charSpacing: 6, align: 'center',
  })

  s.addText(opts.stat || '0', {
    x: G.M, y: 2.0, w: G.W - 2*G.M, h: 2.5,
    fontFace: 'Calibri', fontSize: 200, color: role[0], bold: true, align: 'center',
  })

  s.addText(opts.statSub || '', {
    x: G.M, y: 4.7, w: G.W - 2*G.M, h: 0.5,
    fontFace: 'Calibri', fontSize: 24, color: C.ink900, bold: true, align: 'center',
  })

  if (opts.body) {
    s.addText(opts.body, {
      x: 2.5, y: 5.4, w: G.W - 5, h: 1.4,
      fontFace: 'Calibri', fontSize: 14, color: C.ink700, align: 'center', italic: true,
    })
  }

  premiumFooter(s, { footerLeft: opts.moduleLabel || '', pageStr: opts.pageStr })
  return s
}

// ── Layout 8: 6-STAGE DATA FLOW (visual diagram) ───────────────────────────
function L_flow(pptx, opts) {
  const role = opts.role || C.rEveryone
  const s = pptx.addSlide()
  premiumBackground(s, C.white)
  premiumHeader(s, { accent: role[0], moduleLabel: opts.moduleLabel })

  s.addText(opts.eyebrow || '', {
    x: G.M, y: G.bodyY - 0.4, w: G.W - 2*G.M, h: 0.3,
    fontFace: 'Calibri', fontSize: 10, color: role[0], bold: true, charSpacing: 5,
  })
  s.addText(opts.title || 'The flow', {
    x: G.M, y: G.bodyY - 0.1, w: G.W - 2*G.M, h: 0.6,
    fontFace: 'Calibri', fontSize: 28, color: C.ink900, bold: true,
  })
  if (opts.lede) {
    s.addText(opts.lede, {
      x: G.M, y: G.bodyY + 0.55, w: G.W - 2*G.M, h: 0.5,
      fontFace: 'Calibri', fontSize: 13, color: C.ink500,
    })
  }

  const stages = opts.stages || []
  const startY = 3.2
  const cardH = 2.4
  const totalW = G.W - 2*G.M
  const gap = 0.18
  const cardW = (totalW - gap * (stages.length - 1)) / stages.length

  stages.forEach((st, i) => {
    const x = G.M + i * (cardW + gap)
    // Card
    s.addShape('roundRect', { x, y: startY, w: cardW, h: cardH,
      fill: { color: C.white }, line: { color: role[1], width: 1.25 }, rectRadius: 0.08 })
    // Numbered circle on top (overlap)
    s.addShape('ellipse', { x: x + cardW/2 - 0.3, y: startY - 0.3, w: 0.6, h: 0.6,
      fill: { color: role[0] }, line: { color: C.white, width: 2 } })
    s.addText(`${i + 1}`, {
      x: x + cardW/2 - 0.3, y: startY - 0.3, w: 0.6, h: 0.6,
      fontFace: 'Calibri', fontSize: 18, color: C.white, bold: true, align: 'center', valign: 'middle',
    })
    // Title
    s.addText(st.title || '', {
      x: x + 0.1, y: startY + 0.45, w: cardW - 0.2, h: 0.6,
      fontFace: 'Calibri', fontSize: 13, color: C.ink900, bold: true, align: 'center',
    })
    // Body
    s.addText(st.body || '', {
      x: x + 0.1, y: startY + 1.0, w: cardW - 0.2, h: 1.2,
      fontFace: 'Calibri', fontSize: 10, color: C.ink500, align: 'center', valign: 'top',
    })
    // Owner pill
    if (st.owner) {
      s.addShape('roundRect', { x: x + 0.2, y: startY + cardH - 0.5, w: cardW - 0.4, h: 0.32,
        fill: { color: role[2] }, line: { color: role[2] }, rectRadius: 0.16 })
      s.addText(st.owner, {
        x: x + 0.2, y: startY + cardH - 0.5, w: cardW - 0.4, h: 0.32,
        fontFace: 'Calibri', fontSize: 9, color: role[0], bold: true, align: 'center', valign: 'middle', charSpacing: 2,
      })
    }
    // Connector arrow
    if (i < stages.length - 1) {
      const ax = x + cardW + gap/2
      const ay = startY + cardH/2
      s.addText('▶', {
        x: ax - 0.18, y: ay - 0.15, w: 0.36, h: 0.3,
        fontFace: 'Calibri', fontSize: 14, color: role[0], bold: true, align: 'center',
      })
    }
  })

  premiumFooter(s, { footerLeft: opts.moduleLabel || '', pageStr: opts.pageStr })
  return s
}

// ── Layout 9: COMPARISON TABLE / role matrix ───────────────────────────────
function L_table(pptx, opts) {
  const role = opts.role || C.rEveryone
  const s = pptx.addSlide()
  premiumBackground(s, C.white)
  premiumHeader(s, { accent: role[0], moduleLabel: opts.moduleLabel })

  s.addText(opts.eyebrow || '', {
    x: G.M, y: G.bodyY - 0.4, w: G.W - 2*G.M, h: 0.3,
    fontFace: 'Calibri', fontSize: 10, color: role[0], bold: true, charSpacing: 5,
  })
  s.addText(opts.title || '', {
    x: G.M, y: G.bodyY - 0.1, w: G.W - 2*G.M, h: 0.6,
    fontFace: 'Calibri', fontSize: 26, color: C.ink900, bold: true,
  })
  if (opts.lede) {
    s.addText(opts.lede, {
      x: G.M, y: G.bodyY + 0.55, w: G.W - 2*G.M, h: 0.5,
      fontFace: 'Calibri', fontSize: 13, color: C.ink500,
    })
  }

  // Build the table rows. First row is the header.
  const rows = opts.rows || []
  const headerRow = rows[0].map((cell) => ({
    text: cell,
    options: {
      bold: true, color: C.white, fill: { color: role[0] }, fontSize: 11, fontFace: 'Calibri',
      align: 'left', valign: 'middle', margin: 0.08,
    },
  }))
  const bodyRows = rows.slice(1).map((row, ri) => row.map((cell) => ({
    text: cell,
    options: {
      bold: false, color: C.ink700, fill: { color: ri % 2 === 0 ? C.white : C.ink050 },
      fontSize: 11, fontFace: 'Calibri', align: 'left', valign: 'middle', margin: 0.1,
    },
  })))

  s.addTable([headerRow, ...bodyRows], {
    x: G.M, y: 2.6, w: G.W - 2*G.M,
    border: { type: 'solid', pt: 0.4, color: C.ink100 },
    rowH: opts.rowH || 0.5,
    colW: opts.colW,
    fontFace: 'Calibri',
  })

  premiumFooter(s, { footerLeft: opts.moduleLabel || '', pageStr: opts.pageStr })
  return s
}

// ── Layout 10: CHECKLIST / DO-DON'T ────────────────────────────────────────
function L_doDont(pptx, opts) {
  const role = opts.role || C.rEveryone
  const s = pptx.addSlide()
  premiumBackground(s, C.paper)
  premiumHeader(s, { accent: role[0], moduleLabel: opts.moduleLabel })

  s.addText(opts.eyebrow || 'DO / DO NOT', {
    x: G.M, y: G.bodyY - 0.4, w: G.W - 2*G.M, h: 0.3,
    fontFace: 'Calibri', fontSize: 10, color: role[0], bold: true, charSpacing: 5,
  })
  s.addText(opts.title || 'Habits that distinguish great from average', {
    x: G.M, y: G.bodyY - 0.1, w: G.W - 2*G.M, h: 0.6,
    fontFace: 'Calibri', fontSize: 24, color: C.ink900, bold: true,
  })

  const colW = (G.W - 2*G.M - 0.4) / 2
  const top = G.bodyY + 0.9
  const h = G.footY - top - 0.2

  // DO column
  s.addShape('roundRect', { x: G.M, y: top, w: colW, h,
    fill: { color: C.successBg }, line: { color: C.success, width: 0.5 }, rectRadius: 0.08 })
  s.addText('DO', {
    x: G.M + 0.3, y: top + 0.2, w: colW - 0.6, h: 0.5,
    fontFace: 'Calibri', fontSize: 22, color: C.success, bold: true, charSpacing: 4,
  })
  const doItems = (opts.do || []).map((b) => ({
    text: '✓  ' + b,
    options: { fontFace: 'Calibri', fontSize: 12, color: C.ink900, paraSpaceAfter: 8 },
  }))
  s.addText(doItems, { x: G.M + 0.3, y: top + 0.8, w: colW - 0.6, h: h - 1.0, valign: 'top' })

  // DON'T column
  const x2 = G.M + colW + 0.4
  s.addShape('roundRect', { x: x2, y: top, w: colW, h,
    fill: { color: C.dangerBg }, line: { color: C.danger, width: 0.5 }, rectRadius: 0.08 })
  s.addText("DON'T", {
    x: x2 + 0.3, y: top + 0.2, w: colW - 0.6, h: 0.5,
    fontFace: 'Calibri', fontSize: 22, color: C.danger, bold: true, charSpacing: 4,
  })
  const dontItems = (opts.dont || []).map((b) => ({
    text: '✗  ' + b,
    options: { fontFace: 'Calibri', fontSize: 12, color: C.ink900, paraSpaceAfter: 8 },
  }))
  s.addText(dontItems, { x: x2 + 0.3, y: top + 0.8, w: colW - 0.6, h: h - 1.0, valign: 'top' })

  premiumFooter(s, { footerLeft: opts.moduleLabel || '', pageStr: opts.pageStr })
  return s
}

// ── Layout 11: PRACTICE / HANDS-ON activity ────────────────────────────────
function L_practice(pptx, opts) {
  const role = opts.role || C.rEveryone
  const s = pptx.addSlide()
  s.background = { color: role[0] }

  s.addText('PRACTICE  ·  HANDS-ON ACTIVITY', {
    x: G.M, y: 0.7, w: G.W - 2*G.M, h: 0.4,
    fontFace: 'Calibri', fontSize: 12, color: role[1], bold: true, charSpacing: 6,
  })
  s.addText(opts.title || '', {
    x: G.M, y: 1.2, w: G.W - 2*G.M, h: 1.0,
    fontFace: 'Calibri', fontSize: 32, color: C.white, bold: true,
  })
  if (opts.intro) {
    s.addText(opts.intro, {
      x: G.M, y: 2.3, w: G.W - 2*G.M, h: 0.9,
      fontFace: 'Calibri', fontSize: 16, color: C.white, transparency: 12,
    })
  }
  if (opts.steps && opts.steps.length) {
    const items = opts.steps.map((b, i) => ({
      text: b,
      options: {
        bullet: { type: 'number', startAt: i + 1, color: role[1] },
        fontFace: 'Calibri', fontSize: 14, color: C.white, paraSpaceAfter: 8,
      },
    }))
    s.addText(items, { x: G.M + 0.2, y: 3.4, w: G.W - 2*G.M - 0.4, h: 3.0, valign: 'top' })
  }
  // Time + grouping pills
  const py = 6.6
  s.addShape('roundRect', { x: G.M, y: py, w: 3.0, h: 0.45,
    fill: { color: C.white, transparency: 88 }, line: { color: C.white, transparency: 75 }, rectRadius: 0.08 })
  s.addText('⏱  ' + (opts.duration || '15 min'), {
    x: G.M + 0.15, y: py + 0.05, w: 3.0, h: 0.4,
    fontFace: 'Calibri', fontSize: 12, color: C.white, bold: true, valign: 'middle',
  })
  if (opts.grouping) {
    s.addShape('roundRect', { x: G.M + 3.2, y: py, w: 4.5, h: 0.45,
      fill: { color: C.white, transparency: 88 }, line: { color: C.white, transparency: 75 }, rectRadius: 0.08 })
    s.addText('👥  ' + opts.grouping, {
      x: G.M + 3.35, y: py + 0.05, w: 4.5, h: 0.4,
      fontFace: 'Calibri', fontSize: 12, color: C.white, bold: true, valign: 'middle',
    })
  }
  zambiaStripe(s)
  return s
}

// ── Layout 12: MODULE RECAP / RETRIEVAL PRACTICE ───────────────────────────
function L_recap(pptx, opts) {
  const role = opts.role || C.rEveryone
  const s = pptx.addSlide()
  premiumBackground(s, C.paper)
  premiumHeader(s, { accent: role[0], moduleLabel: opts.moduleLabel })

  s.addText('RETRIEVAL PRACTICE  ·  CHECK WHAT YOU LEARNED', {
    x: G.M, y: G.bodyY - 0.4, w: G.W - 2*G.M, h: 0.3,
    fontFace: 'Calibri', fontSize: 10, color: role[0], bold: true, charSpacing: 6,
  })
  s.addText(opts.title || 'Module recap', {
    x: G.M, y: G.bodyY - 0.1, w: G.W - 2*G.M, h: 0.6,
    fontFace: 'Calibri', fontSize: 26, color: C.ink900, bold: true,
  })
  s.addText('Cover the answers. Ask the room each question in turn. Reveal the cue only after a discussion.', {
    x: G.M, y: G.bodyY + 0.55, w: G.W - 2*G.M, h: 0.4,
    fontFace: 'Calibri', fontSize: 11, color: C.ink500, italic: true,
  })

  const layouts = [
    { x: G.M,        y: 2.5, w: 6.0, h: 2.25 },
    { x: G.M + 6.4,  y: 2.5, w: 6.0, h: 2.25 },
    { x: G.M,        y: 4.85, w: 6.0, h: 2.25 },
    { x: G.M + 6.4,  y: 4.85, w: 6.0, h: 2.25 },
  ]
  const qs = (opts.questions || []).slice(0, 4)
  qs.forEach((q, i) => {
    const l = layouts[i]
    s.addShape('roundRect', { x: l.x, y: l.y, w: l.w, h: l.h,
      fill: { color: C.white }, line: { color: C.ink100, width: 0.75 }, rectRadius: 0.08 })
    s.addShape('rect', { x: l.x, y: l.y, w: 0.08, h: l.h, fill: { color: role[0] }, line: { color: role[0] } })
    // Q badge
    s.addShape('roundRect', { x: l.x + 0.3, y: l.y + 0.25, w: 0.65, h: 0.32,
      fill: { color: role[0] }, line: { color: role[0] }, rectRadius: 0.04 })
    s.addText(`Q${i + 1}`, {
      x: l.x + 0.3, y: l.y + 0.25, w: 0.65, h: 0.32,
      fontFace: 'Calibri', fontSize: 11, color: C.white, bold: true, align: 'center', valign: 'middle', charSpacing: 2,
    })
    // Question text
    s.addText(q.q || '', {
      x: l.x + 0.3, y: l.y + 0.65, w: l.w - 0.5, h: 0.85,
      fontFace: 'Calibri', fontSize: 12, color: C.ink900, bold: true, valign: 'top',
    })
    // Cue
    if (q.cue) {
      s.addShape('rect', { x: l.x + 0.3, y: l.y + 1.55, w: l.w - 0.5, h: 0.02,
        fill: { color: C.ink100 }, line: { color: C.ink100 } })
      s.addText('Look for: ' + q.cue, {
        x: l.x + 0.3, y: l.y + 1.6, w: l.w - 0.5, h: 0.6,
        fontFace: 'Calibri', fontSize: 10.5, color: C.ink500, italic: true, valign: 'top',
      })
    }
  })
  premiumFooter(s, { footerLeft: opts.moduleLabel || '', pageStr: opts.pageStr })
  return s
}

// ── Layout 13: KEY TAKEAWAY (single big idea) ──────────────────────────────
function L_takeaway(pptx, opts) {
  const role = opts.role || C.rEveryone
  const s = pptx.addSlide()
  premiumBackground(s, role[2])
  premiumHeader(s, { accent: role[0], moduleLabel: opts.moduleLabel })
  s.addText('KEY TAKEAWAY', {
    x: G.M, y: 1.6, w: G.W - 2*G.M, h: 0.4,
    fontFace: 'Calibri', fontSize: 12, color: role[0], bold: true, charSpacing: 6, align: 'center',
  })
  s.addText(opts.title || '', {
    x: 1.5, y: 2.4, w: G.W - 3, h: 3.5,
    fontFace: 'Calibri', fontSize: 40, color: role[0], bold: true, align: 'center',
  })
  if (opts.body) {
    s.addText(opts.body, {
      x: 2.0, y: 6.1, w: G.W - 4, h: 0.6,
      fontFace: 'Calibri', fontSize: 14, color: C.ink700, italic: true, align: 'center',
    })
  }
  premiumFooter(s, { footerLeft: opts.moduleLabel || '', pageStr: opts.pageStr })
  return s
}

// ── Layout 14: SECTION BREAK (calm pause slide) ────────────────────────────
function L_break(pptx, opts) {
  const s = pptx.addSlide()
  premiumBackground(s, C.cream)
  s.addText(opts.text || '', {
    x: 1, y: 3, w: G.W - 2, h: 1.5,
    fontFace: 'Georgia', fontSize: 30, color: C.ink900, italic: true, align: 'center',
  })
  if (opts.attribution) {
    s.addText(opts.attribution, {
      x: 1, y: 4.5, w: G.W - 2, h: 0.5,
      fontFace: 'Calibri', fontSize: 13, color: C.ink500, align: 'center',
    })
  }
  zambiaStripe(s)
  return s
}

// ═══════════════════════════════════════════════════════════════════════════
// PAGE TRACKING
// ═══════════════════════════════════════════════════════════════════════════
const pptx = new PptxGenJS()
pptx.layout = 'LAYOUT_WIDE'
pptx.title = 'POE Sentinel — National Training-of-Trainers Manual'
pptx.author = 'ZNPHI · ECSA-HC · Republic of Zambia'
pptx.subject = 'POE Sentinel TOT'
pptx.company = 'Zambia National Public Health Institute'

let _pageCounter = 0
function nextPage() { _pageCounter++; return `Page ${_pageCounter}` }

// ═══════════════════════════════════════════════════════════════════════════
// FRONT MATTER
// ═══════════════════════════════════════════════════════════════════════════
L_cover(pptx, {
  title: 'POE SENTINEL',
  subtitle: 'National Training-of-Trainers Manual',
  body:
    'Point-of-Entry surveillance for the Republic of Zambia.\n' +
    'A complete teaching tool — built so any master trainer can\n' +
    'deliver the system to screeners, district supervisors,\n' +
    'PHEOC officers, data officers, and national administrators.',
  courseName: 'TOT v1.0  ·  App build v1.1.1',
  audience: 'National Master Trainers',
  accent: C.teal500,
})

// Course at a glance
L_table(pptx, {
  moduleLabel: 'COURSE OVERVIEW',
  pageStr: nextPage(),
  eyebrow: 'COURSE AT A GLANCE',
  title: '11 modules · 7 hours of content',
  lede: 'Run as a 2-day workshop or pick role-specific modules for half-day sessions.',
  rowH: 0.42,
  colW: [0.7, 4.2, 4.0, 2.4, 0.8],
  rows: [
    ['#', 'Module', 'Audience', 'Style', 'Min'],
    ['1', 'Welcome & The Big Picture', 'Everyone', 'Concept + flow diagram', '30'],
    ['2', 'For the Screener',           'POE Screening Officers',          'Demo + practice',         '90'],
    ['3', 'For the Health Officer',     'POE Health / Secondary Officers', 'Wizard walk-through',     '120'],
    ['4', 'For the District Supervisor','District health offices',         'Dashboard + decisions',   '60'],
    ['5', 'For the PHEOC Officer',      'Provincial PHEOC',                'KPIs + escalation',       '75'],
    ['6', 'For the National Admin',     'Lusaka HQ',                       'CRUD + governance',       '90'],
    ['7', 'For the Data Officer',       'POE Data Officers',               'Wizard + quality checks', '45'],
    ['8', 'Cross-cutting features',     'Everyone',                        'Sync · settings · help',  '40'],
    ['9', 'Complete Data Flow',         'Everyone',                        'Story walk-through',      '30'],
    ['10','For Developers & IT',        'ZNPHI tech team',                 'Architecture + support',  '90'],
    ['11','Closing & Resources',        'Everyone',                        'Contacts + handover',     '15'],
  ],
})

// Roles overview
L_table(pptx, {
  moduleLabel: 'ROLES IN THE SYSTEM',
  pageStr: nextPage(),
  eyebrow: 'ROLES IN THE SYSTEM',
  title: 'Who does what — at a glance',
  lede: 'Each row is one role. The colour bar on the left of each module cover matches its role colour throughout the deck.',
  rowH: 0.46,
  colW: [2.4, 2.4, 5.7, 1.6],
  rows: [
    ['Role', 'Where they work', 'What they do in the app', 'Module'],
    ['Screener',                'POE — kiosk',            'Capture every traveller; raise referrals when symptomatic',                  '2'],
    ['Health Officer',          'POE — clinic room',      'Pick up referrals, run the 4-step investigation, decide disposition',         '3'],
    ['District Supervisor',     'District health office', 'Watch alerts in their district; acknowledge; ensure follow-up',               '4'],
    ['PHEOC Officer',           'Provincial PHEOC',       'Province-wide oversight; response timeliness; escalate to national',          '5'],
    ['National Administrator',  'ZNPHI HQ Lusaka',        'Manage POEs, users, reporting templates, disease catalogue, system settings', '6'],
    ['Data Officer',            'POE — back office',      'File daily/weekly aggregated reports per published template',                 '7'],
    ['Developer / IT',          'ZNPHI tech team',        'Build, deploy, diagnose, support',                                            '10'],
  ],
})

// How to use this manual
L_split(pptx, {
  moduleLabel: 'HOW TO USE THIS MANUAL',
  pageStr: nextPage(),
  eyebrow: 'HOW TO USE THIS MANUAL',
  title: 'A trainer\'s playbook — read this once, then start',
  image: fnFull('05_route_home.png'),
  lede: 'Each module follows the same six-beat instructional pattern.',
  bullets: [
    { text: 'HOOK — a quote or statistic that makes the audience care.' },
    { text: 'OBJECTIVES — what the learner can do by the end of the module.' },
    { text: 'PRESENT — concept slides explaining each idea.' },
    { text: 'DEMONSTRATE — annotated phone screenshots; trainer walks through.' },
    { text: 'PRACTICE — hands-on activity; learners use the app.' },
    { text: 'ASSESS — a 4-question retrieval-practice recap to close the module.' },
  ],
  role: C.rEveryone,
})

// ═══════════════════════════════════════════════════════════════════════════
// MODULE 1 — Welcome & Big Picture
// ═══════════════════════════════════════════════════════════════════════════
L_moduleCover(pptx, {
  number: '01', role: C.rEveryone,
  title: 'Welcome & The Big Picture',
  subtitle: 'Why we built POE Sentinel and how the pieces fit together',
  audience: 'EVERYONE',
  duration: '30 minutes',
  outcome: 'Understand the 6-stage data flow + name every role',
})

L_hook(pptx, {
  moduleLabel: 'M01 · WELCOME', pageStr: nextPage(), role: C.rEveryone,
  quote: 'Travellers move faster than paper.',
  attribution: 'POE Sentinel design principle, 2026',
  context: 'Zambia\'s ports of entry see thousands of arrivals every day. When one of them carries a fever, the country has a small window to act. Paper forms cannot keep up. POE Sentinel digitises every step from kiosk to national IHR focal point.',
})

L_objectives(pptx, {
  moduleLabel: 'M01 · WELCOME', pageStr: nextPage(), role: C.rEveryone,
  title: 'By the end of Module 1 you can…',
  objectives: [
    { verb: 'NAME',    text: 'The six stages a traveller passes through, from arrival to alert closure.' },
    { verb: 'IDENTIFY',text: 'Each role in the system and the screen they spend most of their day on.' },
    { verb: 'EXPLAIN', text: 'In plain language what an alert is, who acknowledges it, and what closes it.' },
    { verb: 'JUSTIFY', text: 'Why the app must work offline at the kiosk.' },
    { verb: 'TRANSLATE', text: 'Five jargon terms (referral, alert, disposition, sync, PHEOC) into ordinary words.' },
    { verb: 'LOCATE',  text: 'Where in the menu each major workflow lives.' },
  ],
})

L_flow(pptx, {
  moduleLabel: 'M01 · BIG PICTURE', pageStr: nextPage(), role: C.rEveryone,
  eyebrow: 'THE BIG PICTURE',
  title: 'From kiosk to national IHR focal point',
  lede: 'Every traveller follows this six-stage path. Module 9 traces one real traveller across all six stages.',
  stages: [
    { title: 'Arrives',       body: 'Traveller arrives at a POE kiosk',                  owner: 'POE STAFF' },
    { title: 'Primary screen',body: 'Direction · sex · temp · symptoms in 14 seconds',   owner: 'SCREENER' },
    { title: 'Referral',      body: 'Auto-created on YES symptoms',                       owner: 'SYSTEM' },
    { title: 'Investigation', body: 'WHO/IHR 4-step wizard; engine helps with diagnosis', owner: 'HEALTH OFFICER' },
    { title: 'Alert',         body: 'Raised + routed by risk level',                      owner: 'DISTRICT/PHEOC' },
    { title: 'Closed',        body: 'Acknowledged · acted on · audit trail',              owner: 'OWNER OF ALERT' },
  ],
})

L_takeaway(pptx, {
  moduleLabel: 'M01 · WELCOME', pageStr: nextPage(), role: C.rEveryone,
  title: 'Six stages. Six minutes. Six lives saved a year.',
  body: 'The faster the country moves through the six stages, the more lives the system saves. Every minute you cut from any stage compounds nationally.',
})

L_split(pptx, {
  moduleLabel: 'M01 · GLOSSARY', pageStr: nextPage(), role: C.rEveryone,
  eyebrow: 'PLAIN-LANGUAGE GLOSSARY',
  title: 'Five words you will hear every day',
  image: fnFull('05_route_home.png'),
  bullets: [
    { text: 'Referral — a notification automatically created when a primary screening flags symptoms.' },
    { text: 'Alert — a public-health alert raised after a secondary investigation when something needs reporting.' },
    { text: 'Acknowledge — a supervisor confirms they have seen an alert. The clock for response stops here.' },
    { text: 'Disposition — the Health Officer\'s decision: released / held for observation / isolated / sent to a clinic.' },
    { text: 'Sync — uploading what was captured offline once the device has internet again.' },
  ],
})

L_recap(pptx, {
  moduleLabel: 'M01 · RECAP', pageStr: nextPage(), role: C.rEveryone,
  title: 'Module 1 — Welcome & The Big Picture',
  questions: [
    { q: 'Name the six stages of the POE Sentinel flow in order.',                            cue: 'Arrives → Primary screen → Referral → Investigation → Alert → Closed.' },
    { q: 'What does the app do when there is no internet at the kiosk?',                      cue: 'It captures normally. Records upload at the next sync. Nothing is lost.' },
    { q: 'Translate "alert acknowledged" into plain language.',                               cue: '"A supervisor has seen this and is now responsible for what happens next."' },
    { q: 'Which role would you train first if a province had only one day for training?',     cue: 'Screeners — they create the data the rest of the system depends on.' },
  ],
})

// ═══════════════════════════════════════════════════════════════════════════
// MODULE 2 — Screener
// ═══════════════════════════════════════════════════════════════════════════
L_moduleCover(pptx, {
  number: '02', role: C.rScreener,
  title: 'For the Screener',
  subtitle: 'Capture every traveller in under 20 seconds — never lose a referral',
  audience: 'POE SCREENING OFFICERS',
  duration: '90 minutes',
  outcome: 'Capture 10 travellers in 5 minutes with zero errors',
})

L_hook(pptx, {
  moduleLabel: 'M02 · SCREENER', pageStr: nextPage(), role: C.rScreener,
  quote: 'If it takes longer than the queue can wait, the system has failed.',
  context: 'A screener at a busy land border may see 200 travellers in a shift. The capture form must take seconds, not minutes. Every extra tap is a real cost.',
})

L_objectives(pptx, {
  moduleLabel: 'M02 · SCREENER', pageStr: nextPage(), role: C.rScreener,
  title: 'By the end of Module 2 you can…',
  objectives: [
    { verb: 'SIGN IN',  text: 'Open the app, log in with your own credentials, and recognise an offline session.' },
    { verb: 'CAPTURE',  text: 'A clear traveller in under 15 seconds (Direction → Sex → Symptoms → Save).' },
    { verb: 'CAPTURE',  text: 'A symptomatic traveller — including temperature, name, and confirm the referral was filed.' },
    { verb: 'REVIEW',   text: 'Today\'s captured records and reconcile counts at end of shift.' },
    { verb: 'CORRECT',  text: 'A wrongly-captured record by voiding it with a reason.' },
    { verb: 'TEACH',    text: 'A colleague the same flow without a script.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M02 · STEP 1 / SIGN IN', pageStr: nextPage(), role: C.rScreener,
  eyebrow: 'STEP 1  ·  SIGN IN',
  title: 'Your own login — every time',
  image: fnFull('01_login_home.png'),
  lede: 'Open the app. Type your username and password. Tap Sign In.',
  annotations: [
    { x: 0.5, y: 0.35, text: 'Your username is usually firstname.lastname (lowercase, no spaces).' },
    { x: 0.5, y: 0.50, text: 'Type your password. Tap the eye icon to see what you typed.' },
    { x: 0.5, y: 0.65, text: 'Tap Sign In. If you have no signal, the app uses your last cached login.' },
    { x: 0.5, y: 0.84, text: 'Forgot password? Tell your supervisor — never share accounts.' },
  ],
  notes: 'The app records WHO captured every traveller. Sharing accounts breaks the audit trail and is a disciplinary matter.',
})

L_annotated(pptx, {
  moduleLabel: 'M02 · STEP 2 / HOME', pageStr: nextPage(), role: C.rScreener,
  eyebrow: 'STEP 2  ·  HOME',
  title: 'Your home dashboard',
  image: fnFull('06_route_home.png'),
  lede: 'After signing in you land here. Everything you need today is on this one screen.',
  annotations: [
    { x: 0.5, y: 0.10, text: 'Status strip — should read "Operating normally". Anything else, call supervisor.' },
    { x: 0.5, y: 0.25, text: '"Start screening" — your one-tap shortcut to capture the next traveller.' },
    { x: 0.5, y: 0.42, text: 'Triptych — open referrals, open investigations, health alerts in your scope.' },
    { x: 0.5, y: 0.55, text: 'Mini stats — quick count of today\'s symptomatic, fever, sent for review, this week.' },
    { x: 0.5, y: 0.72, text: '7-day chart — how busy the kiosk has been across the last week.' },
  ],
  notes: 'You only need ONE button to remember as a screener: "Start screening". Everything else is summary information.',
})

L_split(pptx, {
  moduleLabel: 'M02 · STEP 3 / SIDE MENU', pageStr: nextPage(), role: C.rScreener,
  eyebrow: 'STEP 3  ·  THE SIDE MENU',
  title: 'How to reach every screen in the app',
  image: fnFull('08_menu_home.png'),
  lede: 'Tap the three lines top-left to open the side menu.',
  bullets: [
    { text: 'Top of menu shows your name, role, POE, district and PHEOC.' },
    { text: 'Pick "Primary Screening" to capture a traveller — your default view.' },
    { text: 'Pick "Sync Centre" at end of shift to push records to the server.' },
    { text: 'Pick "Sign Out" only at the end of your shift. Captured records stay on the device until they sync.' },
    { text: 'Most screeners spend 95% of their time on Primary Screening — discourage menu over-exploration.' },
  ],
})

// — Capture loop — six annotated screens —
L_annotated(pptx, {
  moduleLabel: 'M02 · CAPTURE 1 / DIRECTION', pageStr: nextPage(), role: C.rScreener,
  eyebrow: 'CAPTURE STEP 1  ·  DIRECTION',
  title: 'Which way is the traveller going?',
  image: fnFlow('01_PrimaryScreening.png'),
  lede: 'Three pills at the top. Pick one.',
  annotations: [
    { x: 0.30, y: 0.27, text: 'Entry — the traveller is coming INTO Zambia.' },
    { x: 0.50, y: 0.27, text: 'Exit — the traveller is leaving Zambia.' },
    { x: 0.70, y: 0.27, text: 'Transit — passing through (e.g. changing buses).' },
    { x: 0.50, y: 0.85, text: 'Capture button stays inactive until every required field is filled.' },
  ],
  notes: 'If you are unsure of the direction, ask the traveller. Every record needs a direction — there is no "unknown" option.',
})

L_annotated(pptx, {
  moduleLabel: 'M02 · CAPTURE 2 / SEX', pageStr: nextPage(), role: C.rScreener,
  eyebrow: 'CAPTURE STEP 2  ·  SEX',
  title: 'From the travel document',
  image: fnFlow('03_PrimaryScreening.png'),
  lede: 'Tap Male or Female based on what is on the document.',
  annotations: [
    { x: 0.30, y: 0.36, text: 'Male — pick if the document says male.' },
    { x: 0.70, y: 0.36, text: 'Female — pick if the document says female.' },
    { x: 0.50, y: 0.55, text: 'No "other" option — field maps to the WHO IHR submission schema.' },
  ],
  notes: 'You do not need to ask sensitive questions. The travel document is the source of truth.',
})

L_annotated(pptx, {
  moduleLabel: 'M02 · CAPTURE 3 / TEMP', pageStr: nextPage(), role: C.rScreener,
  eyebrow: 'CAPTURE STEP 3  ·  TEMPERATURE (OPTIONAL)',
  title: 'Type the number from your thermometer',
  image: fnFlow('04_PrimaryScreening.png'),
  lede: 'If you measured the temperature, type the number. If you did not, leave blank.',
  annotations: [
    { x: 0.20, y: 0.42, text: 'Tap the field, type the value (no units).' },
    { x: 0.55, y: 0.42, text: 'Toggle °C / °F to match your thermometer.' },
    { x: 0.85, y: 0.42, text: '≥ 38.5°C → red "high fever" badge appears.' },
    { x: 0.50, y: 0.84, text: 'Capture button still requires Symptoms = Yes/No.' },
  ],
  notes: 'A high temperature alone is NOT a referral. The Symptoms answer is what creates the referral.',
})

L_annotated(pptx, {
  moduleLabel: 'M02 · CAPTURE 4 / SYMPTOMS', pageStr: nextPage(), role: C.rScreener,
  eyebrow: 'CAPTURE STEP 4  ·  SYMPTOMS',
  title: 'The most important question on the form',
  image: fnFlow('05_PrimaryScreening.png'),
  lede: 'Two big buttons: Clear (no symptoms) or Symptomatic (creates a referral). Tap one.',
  annotations: [
    { x: 0.27, y: 0.50, text: '"Clear" — no symptoms. Most travellers go here.' },
    { x: 0.72, y: 0.50, text: '"Symptomatic" — RED for a reason. This triggers the next step in the system.' },
    { x: 0.50, y: 0.30, text: '"IHR Required" badge — every record must answer this question.' },
    { x: 0.50, y: 0.70, text: 'Tap the IHR Surveillance Symptoms link to expand a reference list.' },
  ],
  notes: 'Look AND ask. Cough, runny nose, sweating, weakness, rash, jaundice. Any one = Symptomatic.',
})

L_annotated(pptx, {
  moduleLabel: 'M02 · CAPTURE 5 / NAME', pageStr: nextPage(), role: C.rScreener,
  eyebrow: 'CAPTURE STEP 5  ·  NAME (SYMPTOMATIC ONLY)',
  title: 'Capture the name so the secondary officer can call them back',
  image: fnFlow('07_PrimaryScreening.png'),
  lede: 'A Name field reveals only when you tap Symptomatic. Type the full name from the document.',
  annotations: [
    { x: 0.50, y: 0.55, text: 'Type full name as written on the travel document.' },
    { x: 0.85, y: 0.55, text: 'Scan icon (optional) reads passport / health-declaration QR codes.' },
    { x: 0.50, y: 0.75, text: 'Critical priority chip appears for high fever + symptomatic combinations.' },
  ],
  notes: 'Why ask the name only on symptomatic cases? Because the secondary officer needs to call them back. Clear travellers stay anonymous — privacy by default.',
})

L_annotated(pptx, {
  moduleLabel: 'M02 · CAPTURE 6 / SAVE', pageStr: nextPage(), role: C.rScreener,
  eyebrow: 'CAPTURE STEP 6  ·  CAPTURE & REFER',
  title: 'One tap commits everything',
  image: fnFlow('09_PrimaryScreening.png'),
  lede: 'When the form is complete, the bottom button turns red and reads "Capture & Refer →" — tap it once.',
  annotations: [
    { x: 0.50, y: 0.92, text: 'Tap once. Phone vibrates. "Referral filed" toast appears.' },
    { x: 0.50, y: 0.05, text: 'Live counter at top — Today / Symp / Synced / Pending / Queue.' },
  ],
  notes: 'Two-second rule: from one tap on Capture, the next traveller can start. Drill this rhythm with trainees.',
})

L_doDont(pptx, {
  moduleLabel: 'M02 · HABITS', pageStr: nextPage(), role: C.rScreener,
  title: 'Habits of a great screener',
  do: [
    'Use your own login at the start of every shift',
    'Hold the phone landscape if your kiosk has a stand',
    'Keep the form open — do not close the app between travellers',
    'Run "Sync Now" at every break, not just at end-of-shift',
    'Reconcile your record count with the queue tally before you log out',
    'Void wrong entries with a clear reason — never delete or "leave it"',
  ],
  dont: [
    'Share an account with another officer',
    'Type a fake name into the Name field — leave Symptomatic+Name accurate',
    'Skip the temperature when you measured one',
    'Tap Capture twice "to be sure" — the button is single-flight protected but the habit is wrong',
    'Sign out before Sync Centre shows zero pending',
    'Argue with the traveller — capture what you observe and let secondary investigate',
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M02 · RECORDS', pageStr: nextPage(), role: C.rScreener,
  eyebrow: 'AFTER CAPTURE  ·  RECORDS TAB',
  title: 'See what you captured today',
  image: fnFlow('10_PrimaryScreening.png'),
  lede: 'Tap the "Records" tab at the top. Each row is one traveller you captured.',
  annotations: [
    { x: 0.13, y: 0.18, text: 'Counter strip — confirms what you have done in this shift.' },
    { x: 0.50, y: 0.27, text: 'Switch tabs: Capture / Records / Referral Queue.' },
    { x: 0.50, y: 0.48, text: 'Filter chips: All / Symptomatic / Clear / Pending / All Directions.' },
    { x: 0.50, y: 0.62, text: 'Each card: name, sex, captured time, temperature, badges.' },
    { x: 0.50, y: 0.62, text: 'Tap a card to open the read-only detail modal.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M02 · RECORD DETAIL', pageStr: nextPage(), role: C.rScreener,
  eyebrow: 'AFTER CAPTURE  ·  RECORD DETAIL',
  title: 'Looking at one record — and how to void it',
  image: fnFlow('12_PrimaryScreening.png'),
  lede: 'Tap any card. The full record slides up.',
  annotations: [
    { x: 0.50, y: 0.50, text: 'All captured fields shown read-only.' },
    { x: 0.50, y: 0.85, text: 'Red "Void This Record" — opens void-with-reason flow. The record stays in the audit trail.' },
  ],
  notes: 'Voiding does NOT delete. Supervisors still see what was captured and why it was voided. Always give a reason.',
})

L_practice(pptx, {
  moduleLabel: 'M02 · PRACTICE', pageStr: nextPage(), role: C.rScreener,
  title: '10 captures in 5 minutes',
  intro: 'Each trainee picks up their phone. The trainer reads out a script. Trainees capture and hold the screen up.',
  steps: [
    'Trainer reads: "Mary Banda, Female, entering, no fever, no symptoms." — 8 trainees capture; trainer walks the room.',
    'Mix in 2 symptomatic travellers (high fever + cough). Trainees must spot the red Capture button shifting from green.',
    'Trainer times the room. Aim for under 15 seconds per traveller by capture #10.',
    '"How many should now be in your Records tab?" — answer 10.',
    '"How many should be in the Referral Queue tab?" — answer 2.',
    '"Now void one wrong entry and re-capture it." — every trainee practises the void-with-reason flow.',
  ],
  duration: '15 min', grouping: 'Individual at own device',
})

L_recap(pptx, {
  moduleLabel: 'M02 · RECAP', pageStr: nextPage(), role: C.rScreener,
  title: 'Module 2 — For the Screener',
  questions: [
    { q: 'In what order do you fill the form?',                                            cue: 'Direction → Sex → (Temperature) → Symptoms → (Name if symptomatic) → Capture.' },
    { q: 'When does the Name field appear?',                                               cue: 'Only when Symptomatic is selected.' },
    { q: 'What happens automatically when you capture a symptomatic traveller?',           cue: 'A referral notification is created at the same time and saved together.' },
    { q: 'What is the rule about login accounts?',                                         cue: 'One officer, one login. Sharing accounts breaks the audit trail.' },
  ],
})

L_takeaway(pptx, {
  moduleLabel: 'M02 · TAKEAWAY', pageStr: nextPage(), role: C.rScreener,
  title: 'Direction → Sex → Symptoms → Capture. Everything else is optional.',
  body: 'A great screener could capture in their sleep. Repeat the four-tap rhythm until trainees do it without looking.',
})

// ═══════════════════════════════════════════════════════════════════════════
// MODULE 3 — Health Officer (Secondary)
// ═══════════════════════════════════════════════════════════════════════════
L_moduleCover(pptx, {
  number: '03', role: C.rSecondary,
  title: 'For the Health Officer',
  subtitle: 'Pick up referrals · 4-step investigation · disposition',
  audience: 'POE HEALTH / SECONDARY OFFICERS',
  duration: '120 minutes',
  outcome: 'Complete one full case in pairs, including engine override',
})

L_hook(pptx, {
  moduleLabel: 'M03 · HEALTH OFFICER', pageStr: nextPage(), role: C.rSecondary,
  quote: 'The engine suggests. The clinician decides.',
  context: 'The 4-step wizard is your assistant, not your boss. Every recommendation can be overridden. Train officers to use their judgement first.',
})

L_objectives(pptx, {
  moduleLabel: 'M03 · OBJECTIVES', pageStr: nextPage(), role: C.rSecondary,
  title: 'By the end of Module 3 you can…',
  objectives: [
    { verb: 'PICK UP',  text: 'A referral from the Notifications Centre and explain priority pills.' },
    { verb: 'COMPLETE', text: 'Step 1 / Profile — full identity, document, conveyance, vitals.' },
    { verb: 'COMPLETE', text: 'Step 2 / Symptoms — the 6-category checklist; recognise haemorrhagic red flags.' },
    { verb: 'COMPLETE', text: 'Step 3 / Exposures — structured questionnaire + travel countries.' },
    { verb: 'INTERPRET', text: 'Step 4 / Analysis — read the engine result; override risk + routing if needed.' },
    { verb: 'COMMIT',   text: 'A disposition, write defensible officer notes, save & close.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M03 · QUEUE', pageStr: nextPage(), role: C.rSecondary,
  eyebrow: 'STEP 1  ·  PICK UP A REFERRAL',
  title: 'The Notifications Centre',
  image: fnFull('20_route_NotificationsCenter.png'),
  lede: 'Side menu → Secondary Screening (Notifications Centre). Open referrals appear here, criticals first.',
  annotations: [
    { x: 0.5, y: 0.15, text: 'Counts strip — open / critical / today.' },
    { x: 0.5, y: 0.32, text: 'Each card: traveller, originating POE, capture time, priority pill.' },
    { x: 0.5, y: 0.55, text: 'Open — take ownership and start the wizard.' },
    { x: 0.5, y: 0.65, text: 'Cancel — for when no further action is needed (rare). Always with a reason.' },
  ],
  notes: 'Discourage cancelling without reason. Closed referrals leave an audit trail; cancelling without explanation reads as careless.',
})

L_split(pptx, {
  moduleLabel: 'M03 · WIZARD INTRO', pageStr: nextPage(), role: C.rSecondary,
  eyebrow: 'THE 4-STEP WIZARD',
  title: 'Profile → Symptoms → Exposures → Analysis',
  image: fnFlow('14_secondary-screening_demo01-notif-4444-aaaa-555566667777.png'),
  lede: 'Every investigation has the same four steps in this order. The progress bar shows where you are.',
  bullets: [
    { text: 'Step 1 — Profile: who is the traveller, where from, where going.' },
    { text: 'Step 2 — Symptoms: what they feel, when it started.' },
    { text: 'Step 3 — Exposures: where they have been, who they have been near, what risks they carry.' },
    { text: 'Step 4 — Analysis: the engine\'s suggestion + your decision.' },
    { text: 'Tap a green completed step to jump back. You cannot jump forward to an unsaved step.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M03 · STEP 1 / IDENTITY', pageStr: nextPage(), role: C.rSecondary,
  eyebrow: 'STEP 1 / PROFILE  ·  IDENTITY',
  title: 'Confirm the basics',
  image: fnFlow('14_secondary-screening_demo01-notif-4444-aaaa-555566667777.png'),
  lede: 'Top of Step 1 is identity. Many fields pre-fill from what the screener captured.',
  annotations: [
    { x: 0.5, y: 0.10, text: 'Header pill shows case status (Pending) + originating POE.' },
    { x: 0.5, y: 0.20, text: 'Traveller header: name, sex, temperature from primary, risk level pill.' },
    { x: 0.5, y: 0.30, text: 'Progress bar: Profile is "active" (blue), the rest are grey.' },
    { x: 0.5, y: 0.50, text: 'Confirm full name + sex match the document. Type age in years.' },
    { x: 0.5, y: 0.65, text: 'Pick nationality from the dropdown.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M03 · STEP 1 / DOCUMENT', pageStr: nextPage(), role: C.rSecondary,
  eyebrow: 'STEP 1 / PROFILE  ·  TRAVEL DOCUMENT + GEOGRAPHY',
  title: 'What did they show you, and how did they get here?',
  image: fnFlow('15_secondary-screening_demo01-notif-4444-aaaa-555566667777.png'),
  lede: 'Scroll. Pick document type, type the number, capture how the traveller arrived.',
  annotations: [
    { x: 0.5, y: 0.30, text: 'Document type pills — Passport / National ID / Laissez-Passer / Other.' },
    { x: 0.5, y: 0.55, text: 'Document number — this is what supervisors and queries use to track.' },
    { x: 0.5, y: 0.70, text: 'Below: phone for follow-up, journey-start country, conveyance type.' },
    { x: 0.5, y: 0.85, text: 'Conveyance identifier — flight number, bus number, vehicle registration.' },
  ],
  notes: 'A wrong conveyance number in a real outbreak makes contact-tracing impossible. Stress accuracy.',
})

L_annotated(pptx, {
  moduleLabel: 'M03 · STEP 1 / VITALS', pageStr: nextPage(), role: C.rSecondary,
  eyebrow: 'STEP 1 / PROFILE  ·  VITALS & TRIAGE',
  title: 'Capture what you measure',
  image: fnFlow('17_secondary-screening_demo01-notif-4444-aaaa-555566667777.png'),
  lede: 'Vitals reveal once temperature is set. Capture what you have equipment for.',
  annotations: [
    { x: 0.5, y: 0.35, text: 'Temperature, Pulse, Respiratory rate, BP, SpO2.' },
    { x: 0.5, y: 0.55, text: 'Triage category — Green / Yellow / Red.' },
    { x: 0.5, y: 0.70, text: 'Emergency signs flag — tick if severe distress, altered consciousness, uncontrolled bleeding.' },
    { x: 0.5, y: 0.82, text: 'General appearance — free text. "Alert and oriented" / "Looks unwell".' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M03 · STEP 2 / CHECKLIST', pageStr: nextPage(), role: C.rSecondary,
  eyebrow: 'STEP 2 / SYMPTOMS  ·  CHECKLIST',
  title: 'Six categories of symptoms',
  image: fnFlow('19_secondary-screening_demo01-notif-4444-aaaa-555566667777.png'),
  lede: 'Tap each symptom the traveller has. Tap again to clear.',
  annotations: [
    { x: 0.5, y: 0.18, text: 'Categories: Fever & Systemic, Respiratory, GI, Neurological, Dermatological, Haemorrhagic.' },
    { x: 0.5, y: 0.30, text: 'Each cell is a tap-target — Off / Present.' },
    { x: 0.85, y: 0.18, text: 'Counter at top right — how many are currently selected.' },
    { x: 0.5, y: 0.92, text: 'Save & Next button at the bottom — persists, advances to Step 3.' },
  ],
  notes: 'Ask AND look AND listen. Cough is obvious; fatigue must be asked about. Resist the urge to assume.',
})

L_split(pptx, {
  moduleLabel: 'M03 · STEP 2 / RED FLAGS', pageStr: nextPage(), role: C.rSecondary,
  eyebrow: 'STEP 2 / SYMPTOMS  ·  HAEMORRHAGIC RED FLAGS',
  title: 'Any single symptom in this group changes everything',
  image: fnFlow('22_secondary-screening_demo01-notif-4444-aaaa-555566667777.png'),
  lede: 'The Haemorrhagic group is the most serious symptom set the engine knows.',
  bullets: [
    { text: 'Unusual bleeding — gums, nose, injection sites, vomit, stool.' },
    { text: 'Petechiae — small red or purple spots under the skin.' },
    { text: 'Ecchymoses — larger purple bruises that did not come from injury.' },
    { text: 'Haematuria — blood in urine.' },
    { text: 'Any ONE of these → engine ranks viral haemorrhagic fevers (Ebola, Marburg, Lassa, CCHF) at the top.' },
    { text: 'If you see any of these — alert the supervisor BEFORE finishing the wizard.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M03 · STEP 3 / EXPOSURES', pageStr: nextPage(), role: C.rSecondary,
  eyebrow: 'STEP 3 / EXPOSURES  ·  STRUCTURED QUESTIONNAIRE',
  title: 'Ask each question. Pick the answer that fits.',
  image: fnFlow('24_secondary-screening_demo01-notif-4444-aaaa-555566667777.png'),
  lede: 'Yes / No / Unknown for each exposure question. Unknown is the safe default.',
  annotations: [
    { x: 0.5, y: 0.20, text: 'Top group: Travel & Geographic risk. "Visited an outbreak area?"' },
    { x: 0.5, y: 0.45, text: 'Each question carries a "Why we ask" sub-line — read it to the traveller.' },
    { x: 0.5, y: 0.60, text: 'Three response pills per question: Yes / No / Unknown.' },
    { x: 0.85, y: 0.45, text: 'Yellow "HIGH RISK" badge — this question matters most.' },
    { x: 0.5, y: 0.92, text: 'Analyse → orange button at the bottom advances to Step 4.' },
  ],
  notes: 'Resist the urge to answer for the traveller. Ask, then click. Misclassified exposures break the engine\'s ranking.',
})

L_annotated(pptx, {
  moduleLabel: 'M03 · STEP 3 / TRAVEL', pageStr: nextPage(), role: C.rSecondary,
  eyebrow: 'STEP 3 / EXPOSURES  ·  TRAVEL COUNTRIES',
  title: 'Every country in the last 14 days',
  image: fnFlow('27_secondary-screening_demo01-notif-4444-aaaa-555566667777.png'),
  lede: 'Below the questions: log every country the traveller visited in the last 14 days, with dates.',
  annotations: [
    { x: 0.5, y: 0.40, text: '"+ Add country" → pick from dropdown → type from-to dates.' },
    { x: 0.5, y: 0.55, text: 'Each country is cross-referenced against the engine\'s endemic-zone database.' },
    { x: 0.5, y: 0.70, text: 'For a transit-only stop (under 24h), enter same date for from and to.' },
  ],
  notes: 'Travellers often forget transit stops. Prompt: "Did you change planes anywhere?" "Any layovers?" "Any other border crossings?"',
})

L_annotated(pptx, {
  moduleLabel: 'M03 · STEP 4 / ENGINE', pageStr: nextPage(), role: C.rSecondary,
  eyebrow: 'STEP 4 / ANALYSIS  ·  ENGINE OUTPUT',
  title: 'The engine\'s suggestion — read it carefully',
  image: fnFlow('29_secondary-screening_demo01-notif-4444-aaaa-555566667777.png'),
  lede: 'A big green or red badge tells you the engine\'s headline answer.',
  annotations: [
    { x: 0.5, y: 0.25, text: 'NON-CASE — the engine recommends releasing the traveller.' },
    { x: 0.5, y: 0.40, text: 'Or yellow / red — engine suspects something specific. Read the rationale.' },
    { x: 0.5, y: 0.60, text: '"Officer Override — I disagree" — your judgement always wins.' },
    { x: 0.5, y: 0.78, text: 'Suspected Disease + Syndrome Classification dropdowns below.' },
  ],
  notes: 'The engine is a guide, not a doctor. The clinician\'s judgement always wins. Override is a feature, not a failure.',
})

L_annotated(pptx, {
  moduleLabel: 'M03 · STEP 4 / DISPOSITION', pageStr: nextPage(), role: C.rSecondary,
  eyebrow: 'STEP 4 / ANALYSIS  ·  DISPOSITION',
  title: 'What happens to the traveller',
  image: fnFlow('33_secondary-screening_demo01-notif-4444-aaaa-555566667777.png'),
  lede: 'Pick one disposition. Each carries a one-line definition so non-clinicians know what they\'re picking.',
  annotations: [
    { x: 0.5, y: 0.20, text: 'Released — clear, send them on their way.' },
    { x: 0.5, y: 0.32, text: 'Held for observation (Quarantined) — keep at the POE for monitoring.' },
    { x: 0.5, y: 0.45, text: 'Held alone for medical reasons (Isolated) — separate from others.' },
    { x: 0.5, y: 0.55, text: 'Sent to a clinic (Referred) — to a hospital or treatment centre.' },
    { x: 0.5, y: 0.70, text: 'Officer notes — explain the decision. Read by supervisors and auditors.' },
    { x: 0.5, y: 0.92, text: 'Save & Disposition → closes case, raises alert if HIGH/CRITICAL, closes referral.' },
  ],
  notes: 'Officer notes are read by supervisors and auditors. Write in plain professional English. Specifics matter — name the receiving facility.',
})

L_takeaway(pptx, {
  moduleLabel: 'M03 · TAKEAWAY', pageStr: nextPage(), role: C.rSecondary,
  title: 'When in doubt, escalate one level higher than the engine suggests.',
  body: 'Better to over-route than miss an outbreak. The engine is conservative; you have local context the engine does not.',
})

L_practice(pptx, {
  moduleLabel: 'M03 · PRACTICE', pageStr: nextPage(), role: C.rSecondary,
  title: 'Run a complete secondary investigation in pairs',
  intro: 'Trainees pair up. One plays the traveller, one plays the Health Officer. The trainer reads a scripted scenario.',
  steps: [
    'Trainer reads: "Joseph Phiri, 34, male, arrived from DRC by bus, fever 38.6°C three days, dry cough, visited a livestock market 5 days ago, no bleeding."',
    'Health Officer trainee opens the referral, completes Step 1 Profile.',
    'Completes Step 2 Symptoms — should tick Cough + Fever.',
    'Completes Step 3 Exposures — Travel from DRC YES + Animal contact YES; Haemorrhagic NO.',
    'Reads engine\'s top suggestion at Step 4. Decides on disposition. Picks risk + routing.',
    'Saves with notes. Trainer asks the room: "What level was this routed to and why?"',
    'Swap roles. Trainer reads a different scenario.',
  ],
  duration: '25 min', grouping: 'Pairs · 1 traveller + 1 officer',
})

L_recap(pptx, {
  moduleLabel: 'M03 · RECAP', pageStr: nextPage(), role: C.rSecondary,
  title: 'Module 3 — For the Health Officer',
  questions: [
    { q: 'Name the four steps of the wizard in order.',                            cue: 'Profile → Symptoms → Exposures → Analysis.' },
    { q: 'Which symptom group is the most urgent red flag?',                       cue: 'Haemorrhagic — any one symptom escalates to suspect viral haemorrhagic fever.' },
    { q: 'When should you pick "Unknown" on an exposure question?',                cue: 'Whenever the traveller is not sure. Never guess.' },
    { q: 'What three things happen when you tap "Save & Disposition"?',            cue: '1. Case becomes "Decision made". 2. Alert raised + routed. 3. Referral closes.' },
  ],
})

// ═══════════════════════════════════════════════════════════════════════════
// MODULE 4 — DISTRICT
// ═══════════════════════════════════════════════════════════════════════════
L_moduleCover(pptx, {
  number: '04', role: C.rDistrict,
  title: 'For the District Supervisor',
  subtitle: 'Watch alerts in your district · acknowledge · ensure follow-up',
  audience: 'DISTRICT HEALTH / SURVEILLANCE OFFICERS',
  duration: '60 minutes',
  outcome: 'Demonstrate the daily 2-minute district routine',
})

L_hook(pptx, {
  moduleLabel: 'M04 · DISTRICT', pageStr: nextPage(), role: C.rDistrict,
  quote: 'A red number on the dashboard is a phone call you have not made yet.',
  context: 'District supervisors do not capture data. Their job is to make sure data turns into action — every day, twice a day.',
})

L_objectives(pptx, {
  moduleLabel: 'M04 · OBJECTIVES', pageStr: nextPage(), role: C.rDistrict,
  title: 'By the end of Module 4 you can…',
  objectives: [
    { verb: 'CHECK',     text: 'Your district dashboard at start and end of every working day.' },
    { verb: 'ACKNOWLEDGE',text: 'An active alert within 1 hour of seeing it.' },
    { verb: 'TRACE',     text: 'A closed alert through the History tab and explain who did what.' },
    { verb: 'READ',      text: 'The intelligence dashboard\'s 5 KPIs without referring to a glossary.' },
    { verb: 'ACT',       text: 'On an amber or red KPI by calling the right POE within the same day.' },
    { verb: 'DEFEND',    text: 'A monthly response-time report to the District Health Director using these screens.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M04 · DASHBOARD', pageStr: nextPage(), role: C.rDistrict,
  eyebrow: 'YOUR DAILY DASHBOARD',
  title: 'A 2-minute morning routine',
  image: fnFull('06_route_home.png'),
  lede: 'Numbers are scoped to your district only — not the whole country.',
  annotations: [
    { x: 0.5, y: 0.13, text: 'Status strip — should read Operating normally.' },
    { x: 0.5, y: 0.35, text: 'Three coloured cards: Open Referrals, Open Investigations, Health Alerts.' },
    { x: 0.5, y: 0.50, text: 'Mini stats — today\'s symptomatic, fever, sent for review, this week.' },
    { x: 0.5, y: 0.70, text: '7-day chart — district screening volume across all POEs.' },
  ],
  notes: 'Glance at this screen at the start and end of every workday. Two minutes, every morning, every evening.',
})

L_annotated(pptx, {
  moduleLabel: 'M04 · ALERTS', pageStr: nextPage(), role: C.rDistrict,
  eyebrow: 'ACTIVE ALERTS',
  title: 'Your daily worklist',
  image: fnFull('29_route_alerts.png'),
  lede: 'Side menu → Alerts → Active. Each row is one alert.',
  annotations: [
    { x: 0.5, y: 0.22, text: 'Each row: title, risk pill, age, originating POE, where it was routed.' },
    { x: 0.5, y: 0.38, text: 'Red glow — critical priority. Action this first.' },
    { x: 0.5, y: 0.50, text: 'Tap the card to open the detail and acknowledge.' },
    { x: 0.5, y: 0.65, text: 'Acknowledging means "I have seen this and will follow up." The clock for response stops here.' },
  ],
  notes: 'The rule: "Acknowledge within 1 hour of seeing it. Don\'t leave it on the screen as a reminder — that defeats the audit trail."',
})

L_split(pptx, {
  moduleLabel: 'M04 · INTELLIGENCE', pageStr: nextPage(), role: C.rDistrict,
  eyebrow: 'ALERT INTELLIGENCE',
  title: 'Are we hitting our targets?',
  image: fnFull('30_route_alerts_intelligence.png'),
  lede: 'Side menu → Alerts → Intelligence. Five numbers tell you whether your district responds fast enough.',
  bullets: [
    { text: 'Within 24h notice — of every 10 alerts, how many were notified within 24 hours? Target ≥ 8 of 10.' },
    { text: 'Within 7d response — of every 10 alerts, how many had a response within 7 days? Target ≥ 8 of 10.' },
    { text: 'Top-priority (Tier 1) — number of priority outbreak alerts on file right now. Greater than 0 → engage personally.' },
    { text: 'Targets missed — alerts that breached at least one deadline.' },
    { text: 'Amber means under 80%. Red means a top-priority alert is open OR a deadline was missed.' },
  ],
})

L_doDont(pptx, {
  moduleLabel: 'M04 · HABITS', pageStr: nextPage(), role: C.rDistrict,
  title: 'Habits of an effective District Supervisor',
  do: [
    'Open the dashboard at start AND end of every workday',
    'Acknowledge alerts within 1 hour of seeing them',
    'Use the Alert History tab as monthly meeting prep',
    'Take a screenshot of any red KPI before the conversation with PHEOC',
    'Confirm POE-level fixes by re-checking the dashboard the next day',
    'Treat closed alerts as institutional memory, not files to forget',
  ],
  dont: [
    'Leave acknowledgements until end of week — the clock keeps ticking',
    'Re-open closed alerts — raise a new alert with a clear link instead',
    'Argue with the engine\'s routing — escalate manually if needed and write a note',
    'Discuss alert content with non-authorised colleagues — privacy still applies',
    'Use a screenshot from yesterday in today\'s meeting — pull live numbers',
    'Sign off when KPI cards are red',
  ],
})

L_recap(pptx, {
  moduleLabel: 'M04 · RECAP', pageStr: nextPage(), role: C.rDistrict,
  title: 'Module 4 — For the District Supervisor',
  questions: [
    { q: 'How long should an alert sit unacknowledged?',                       cue: 'Acknowledge within 1 hour of seeing it.' },
    { q: 'What does "Within 24h notice" measure?',                            cue: 'Share of alerts notified within 24h of detection. Target ≥ 8 of 10.' },
    { q: 'When can a closed alert be re-opened?',                             cue: 'Never — raise a new alert with a clear link to the closed one.' },
    { q: 'Where do you compare your district\'s response time vs target?',    cue: 'Alerts → Intelligence → the 5-card KPI strip at the top.' },
  ],
})

// ═══════════════════════════════════════════════════════════════════════════
// MODULE 5 — PHEOC
// ═══════════════════════════════════════════════════════════════════════════
L_moduleCover(pptx, {
  number: '05', role: C.rPheoc,
  title: 'For the PHEOC Officer',
  subtitle: 'Province-wide oversight · response timeliness · escalate to national',
  audience: 'PROVINCIAL PHEOC OFFICERS',
  duration: '75 minutes',
  outcome: 'Lead a weekly coordination call using the dashboards alone',
})

L_hook(pptx, {
  moduleLabel: 'M05 · PHEOC', pageStr: nextPage(), role: C.rPheoc,
  quote: 'You sit between the district and the nation. The two only know what you tell them.',
  context: 'PHEOC officers carry coordination responsibility. Train them to read the trend, not just today\'s number.',
})

L_objectives(pptx, {
  moduleLabel: 'M05 · OBJECTIVES', pageStr: nextPage(), role: C.rPheoc,
  title: 'By the end of Module 5 you can…',
  objectives: [
    { verb: 'INTERPRET',text: 'The province dashboard\'s rolled-up numbers across all districts.' },
    { verb: 'EXPLAIN',  text: 'The 7-1-7 framework in plain language to a non-clinical district health officer.' },
    { verb: 'DIAGNOSE', text: 'Whether the bottleneck is at Detect, Notify, or Respond stage.' },
    { verb: 'ESCALATE', text: 'An alert to national level manually with a defensible note.' },
    { verb: 'CONVENE',  text: 'A weekly coordination call agenda from the dashboard alone.' },
    { verb: 'REPORT',   text: 'Monthly response-time numbers to the Provincial Director of Health.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M05 · PROVINCE VIEW', pageStr: nextPage(), role: C.rPheoc,
  eyebrow: 'PROVINCE-WIDE DASHBOARD',
  title: 'Every district rolls up here',
  image: fnFull('06_route_home.png'),
  lede: 'Numbers are the province total — every district under your PHEOC, every POE within them.',
  annotations: [
    { x: 0.5, y: 0.32, text: 'Triptych shows province-wide totals — referrals, investigations, alerts.' },
    { x: 0.5, y: 0.55, text: 'Activity feed — recent events across all districts.' },
    { x: 0.5, y: 0.72, text: '7-day chart — province\'s screening volume across all POEs.' },
  ],
  notes: 'Drill into any specific district from the Active Alerts list — filter chips at the top of /alerts.',
})

L_split(pptx, {
  moduleLabel: 'M05 · 7-1-7', pageStr: nextPage(), role: C.rPheoc,
  eyebrow: 'OUTBREAK RESPONSE TIMELINESS',
  title: 'The 7-1-7 framework — your daily reading',
  image: fnFull('30_route_alerts_intelligence.png'),
  lede: 'Five KPIs along the top of Alert Intelligence are your weekly meeting agenda.',
  bullets: [
    { text: '7 — Detect within 7 days. From first symptom to alert raised.' },
    { text: '1 — Notify within 1 day (24h). From detection to the right level being told.' },
    { text: '7 — Respond within 7 days. From notification to action taken.' },
    { text: 'Amber bars = below 80% target.' },
    { text: 'Red bars = a top-priority alert is open OR a deadline was missed.' },
    { text: 'If any KPI shows red, that is your conversation for this week\'s coordination call.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M05 · STAGES', pageStr: nextPage(), role: C.rPheoc,
  eyebrow: 'STAGE BREAKDOWN',
  title: 'Detect / Notify / Respond — find the bottleneck',
  image: fnFull('31_action_alerts_intelligence.png'),
  lede: 'Scroll the Intelligence dashboard. Three stage cards explain WHERE the slowness is.',
  annotations: [
    { x: 0.5, y: 0.30, text: 'Detect — was the alert raised within 7 days of first symptom?' },
    { x: 0.5, y: 0.50, text: 'Notify — within 24 hours of detection?' },
    { x: 0.5, y: 0.70, text: 'Respond — was a response action recorded within 7 days of notification?' },
  ],
  notes: 'If Respond is amber but Notify is green, your bottleneck is on the response side, not the alerting side. Use the per-district drill-down to find the slow district.',
})

L_split(pptx, {
  moduleLabel: 'M05 · ESCALATION', pageStr: nextPage(), role: C.rPheoc,
  eyebrow: 'ESCALATING TO NATIONAL',
  title: 'When to escalate without waiting for the engine',
  image: fnFull('29_route_alerts.png'),
  lede: 'PHEOC sits between district and national. Some alerts must go up — sometimes faster than the engine routes them.',
  bullets: [
    { text: 'Tap an alert. The detail shows the current routing destination.' },
    { text: 'Change "routed to" to NATIONAL and re-save. National admins are notified the moment you save.' },
    { text: 'Use the officer-notes field to explain WHY you escalated.' },
    { text: 'When to escalate without waiting:' },
    { text: '   • Any cluster of 3+ related cases at the same POE within 24 hours.' },
    { text: '   • Any single suspected viral haemorrhagic fever (Ebola, Marburg, Lassa, CCHF).' },
    { text: '   • Any traveller from a country with an active WHO declaration.' },
  ],
})

L_recap(pptx, {
  moduleLabel: 'M05 · RECAP', pageStr: nextPage(), role: C.rPheoc,
  title: 'Module 5 — For the PHEOC Officer',
  questions: [
    { q: 'Translate "7-1-7" into plain language.',                                              cue: 'Detect within 7 days · Notify within 1 day (24h) · Respond within 7 days.' },
    { q: 'What does an amber KPI card tell you?',                                               cue: 'Less than 8 in 10 alerts are hitting that deadline — investigate which districts are slow.' },
    { q: 'Where do you read which districts are dragging the response time down?',              cue: 'Alerts → Intelligence → the per-district drill-down below the KPI strip.' },
    { q: 'When should you escalate to national without waiting for the engine?',                cue: 'Any cluster of 3+ related cases at the same POE within 24 hours. Or any suspected VHF.' },
  ],
})

// ═══════════════════════════════════════════════════════════════════════════
// MODULE 6 — National admin
// ═══════════════════════════════════════════════════════════════════════════
L_moduleCover(pptx, {
  number: '06', role: C.rNational,
  title: 'For the National Administrator',
  subtitle: 'POEs · users · disease catalogue · templates · system',
  audience: 'NATIONAL ADMINISTRATORS AT ZNPHI HQ',
  duration: '90 minutes',
  outcome: 'Add a POE, create a user, retire a template — without notes',
})

L_hook(pptx, {
  moduleLabel: 'M06 · NATIONAL', pageStr: nextPage(), role: C.rNational,
  quote: 'Configuration is policy. Be careful what you publish.',
  context: 'Every change a National Admin makes is felt by hundreds of officers. Templates, users, POEs — change them with intention, version them like code.',
})

L_objectives(pptx, {
  moduleLabel: 'M06 · OBJECTIVES', pageStr: nextPage(), role: C.rNational,
  title: 'By the end of Module 6 you can…',
  objectives: [
    { verb: 'ADD',     text: 'A new Point of Entry to the registry, including its assignment hierarchy.' },
    { verb: 'PROVISION',text: 'A new user with the right role and geographic scope.' },
    { verb: 'BUILD',   text: 'A new aggregated reporting template using the 5-step wizard.' },
    { verb: 'BROWSE',  text: 'The disease intelligence catalogue and explain Tier 1 vs Tier 2.' },
    { verb: 'AUDIT',   text: 'POE notification contacts and run a test message quarterly.' },
    { verb: 'EXPLAIN', text: 'Each card in App Settings — what it does, who needs it.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M06 · POE REGISTRY', pageStr: nextPage(), role: C.rNational,
  eyebrow: 'POE REGISTRY',
  title: 'Manage Points of Entry',
  image: fnFull('49_route_POEs.png'),
  lede: 'Side menu → POE Management → Manage POEs.',
  annotations: [
    { x: 0.5, y: 0.20, text: 'Filter by province, district, status (active / inactive).' },
    { x: 0.5, y: 0.42, text: 'Each row: POE name, code, type (airport / border / river crossing), status.' },
    { x: 0.5, y: 0.65, text: 'FAB (+) at the bottom-right opens the create modal.' },
    { x: 0.5, y: 0.50, text: 'Tap a row to edit — change status, reassign district, update on-call hours.' },
  ],
  notes: 'Adding a POE changes the geographic boundary of who can capture data where. Always confirm with the provincial team first.',
})

L_annotated(pptx, {
  moduleLabel: 'M06 · USERS', pageStr: nextPage(), role: C.rNational,
  eyebrow: 'USER MANAGEMENT',
  title: 'Provision and de-provision officers',
  image: fnFull('51_route_Users.png'),
  lede: 'Side menu → User Management.',
  annotations: [
    { x: 0.5, y: 0.18, text: 'Each row: user name, role pill, scope, active state.' },
    { x: 0.5, y: 0.30, text: 'Filter by role to find every screener / district supervisor / etc.' },
    { x: 0.95, y: 0.18, text: '"+ New" — opens the create-user form.' },
    { x: 0.5, y: 0.55, text: 'When deactivating: tap the row → toggle Active off. NEVER delete.' },
  ],
  notes: 'The rule: deactivate, never delete. Records keep their author for the audit trail; deactivating just removes their ability to log in.',
})

L_split(pptx, {
  moduleLabel: 'M06 · USER FORM', pageStr: nextPage(), role: C.rNational,
  eyebrow: 'CREATE-USER FORM',
  title: 'Username conventions matter',
  image: fnFull('52_action_Users.png'),
  lede: 'Tap "+ New" on the Users list. Fill the form.',
  bullets: [
    { text: 'Full name, username (lowercase, no spaces, no special characters), email, phone, password.' },
    { text: 'Pick a role: Screener / District Supervisor / PHEOC Officer / National Admin.' },
    { text: 'Geographic assignment depends on the role: Screener needs province + district + POE; National Admin needs none.' },
    { text: 'Strong-password indicator (must be 8+ chars, mixed case, digit, symbol).' },
    { text: 'Set "Active" on. Save. The user can log in immediately.' },
    { text: 'Convention: usernames are firstname.lastname; phone numbers always with country code (+260…).' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M06 · DISEASES', pageStr: nextPage(), role: C.rNational,
  eyebrow: 'DISEASE INTELLIGENCE',
  title: 'The catalogue powering every secondary screening',
  image: fnFull('45_route_DiseaseInteligence.png'),
  lede: 'Side menu → Disease Management → Tracked Diseases.',
  annotations: [
    { x: 0.5, y: 0.30, text: 'Each disease card: tier, endemic countries, case definition.' },
    { x: 0.5, y: 0.55, text: 'Search by name or filter by tier / syndrome.' },
    { x: 0.5, y: 0.75, text: 'Read-only at the user level — only ZNPHI admins update the catalogue (annual review).' },
  ],
  notes: 'Refer Health Officers here when they want to read up on a suspected disease. Encourage an annual catalogue review with WHO.',
})

L_split(pptx, {
  moduleLabel: 'M06 · TEMPLATES', pageStr: nextPage(), role: C.rNational,
  eyebrow: 'AGGREGATED TEMPLATES',
  title: 'What data officers fill in — and how often',
  image: fnFull('41_route_admin_aggregated-templates.png'),
  lede: 'Side menu → Aggregated Data → Template Settings. Manage every reporting template.',
  bullets: [
    { text: 'Each template defines fields a data officer fills: total screened, symptomatic, gender breakdown, etc.' },
    { text: 'Frequency: Daily / Weekly / Monthly / Quarterly / Ad-hoc / Event-based.' },
    { text: 'Edit, retire, or version-bump. Pending submissions on retired templates remain valid.' },
    { text: 'Tap the FAB to launch the 5-step template wizard for a new template.' },
    { text: 'Versioning rule: when you change a template, BUMP the version. Old submissions stay readable as the version they were filed under.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M06 · TEMPLATE WIZARD', pageStr: nextPage(), role: C.rNational,
  eyebrow: 'TEMPLATE WIZARD',
  title: 'Build a new template — Step 1 of 5',
  image: fnFull('42_route_admin_aggregated-wizard.png'),
  lede: 'Step 1 captures identity. Steps 2-5 build the columns, validation rules, audience, publish.',
  annotations: [
    { x: 0.5, y: 0.30, text: 'Name — short, clear. Officers see this in their dropdown.' },
    { x: 0.5, y: 0.45, text: 'Code — machine-readable. Pick once, never change.' },
    { x: 0.5, y: 0.60, text: 'Frequency — drives the schedule UI.' },
    { x: 0.5, y: 0.78, text: 'Audience role keys — who can file this template.' },
  ],
  notes: 'Don\'t publish a template until at least one trial submission has been filed and reviewed. Bad templates are hard to retract.',
})

L_split(pptx, {
  moduleLabel: 'M06 · CONTACTS', pageStr: nextPage(), role: C.rNational,
  eyebrow: 'POE NOTIFICATION CONTACTS',
  title: 'The escalation roster — keep it fresh',
  image: fnFull('44_route_admin_poe-contacts.png'),
  lede: 'Side menu → Aggregated Data → Notification Contacts.',
  bullets: [
    { text: 'Per POE: contact name, role title, phone, email, escalation order (1 = first to call).' },
    { text: 'When a critical alert fires at a POE, contacts on this list are notified by email/SMS.' },
    { text: 'Always have at least 2 active contacts per POE.' },
    { text: 'Update when staff change. Out-of-date contacts mean alerts go to nobody.' },
    { text: 'Schedule a quarterly review. Send a test message every 3 months to confirm phones still work.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M06 · SETTINGS', pageStr: nextPage(), role: C.rNational,
  eyebrow: 'APP SETTINGS',
  title: 'What each card does',
  image: fnFull('55_route_settings.png'),
  lede: 'Side menu → Account & Settings. Eight scrollable sections.',
  annotations: [
    { x: 0.5, y: 0.30, text: 'Account — name, username, email, role (read-only here).' },
    { x: 0.5, y: 0.55, text: 'Assignment — geographic scope (read-only).' },
    { x: 0.5, y: 0.80, text: 'Sync & Storage — versions, server URL, cached count.' },
  ],
  notes: 'Capabilities & Help, Capture accelerators, Actions, About — covered in Module 8 (Cross-cutting).',
})

L_recap(pptx, {
  moduleLabel: 'M06 · RECAP', pageStr: nextPage(), role: C.rNational,
  title: 'Module 6 — For the National Administrator',
  questions: [
    { q: 'When a screener leaves the job, what do you do in /Users?',         cue: 'Open the row, toggle Active OFF. Never delete.' },
    { q: 'Which roles need a POE assignment?',                                cue: 'Screener, Health Officer, Data Officer, POE Admin. District / PHEOC / National do not.' },
    { q: 'When you change a published template, what is the rule?',           cue: 'Bump the version. Old submissions remain valid as the version they were filed under.' },
    { q: 'How often should POE notification contacts be reviewed?',           cue: 'Quarterly. Send a test message to confirm every phone still works.' },
  ],
})

// ═══════════════════════════════════════════════════════════════════════════
// MODULE 7 — Data Officer
// ═══════════════════════════════════════════════════════════════════════════
L_moduleCover(pptx, {
  number: '07', role: C.rData,
  title: 'For the Data Officer',
  subtitle: 'File daily/weekly aggregated reports without errors',
  audience: 'POE DATA OFFICERS',
  duration: '45 minutes',
  outcome: 'File a 5-day reporting period in under 3 minutes',
})

L_hook(pptx, {
  moduleLabel: 'M07 · DATA OFFICER', pageStr: nextPage(), role: C.rData,
  quote: 'A wrong number on a national report is harder to take back than a missing one.',
  context: 'Aggregated reports flow from the POE up to the Ministry of Health. Data officers carry the credibility of every number.',
})

L_objectives(pptx, {
  moduleLabel: 'M07 · OBJECTIVES', pageStr: nextPage(), role: C.rData,
  title: 'By the end of Module 7 you can…',
  objectives: [
    { verb: 'FIND',      text: 'A published template in the Aggregated Hub.' },
    { verb: 'COMPLETE',  text: 'The Period step (start/end date validation).' },
    { verb: 'COMPLETE',  text: 'The Counts step using the auto-calculate button as a starting point.' },
    { verb: 'VERIFY',    text: 'A submission against the Quality checks panel before sign-off.' },
    { verb: 'TRACE',     text: 'A past submission through the History tab.' },
    { verb: 'CORRECT',   text: 'A wrong submission — open, edit, re-submit (or save draft).' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M07 · HUB', pageStr: nextPage(), role: C.rData,
  eyebrow: 'AGGREGATED HUB',
  title: 'Your inbox — every template you can file',
  image: fnFull('34_route_aggregated-data.png'),
  lede: 'Side menu → Aggregated Data → Reports.',
  annotations: [
    { x: 0.5, y: 0.20, text: 'Templates grouped by frequency: Daily, Weekly, Monthly.' },
    { x: 0.5, y: 0.40, text: 'Each card: template name, period it covers, status of most recent submission.' },
    { x: 0.5, y: 0.60, text: 'Tap "Submit" to start the wizard for that template.' },
    { x: 0.5, y: 0.80, text: 'Templates retire over time — only active ones appear here.' },
  ],
  notes: 'Daily templates due by end-of-shift. Weekly by Monday morning. Monthly by the 5th.',
})

L_annotated(pptx, {
  moduleLabel: 'M07 · WIZARD STEP 1', pageStr: nextPage(), role: C.rData,
  eyebrow: 'WIZARD STEP 1  ·  PERIOD',
  title: 'Tell us what dates this report covers',
  image: fnFull('35_route_aggregated-data_new_1.png'),
  lede: 'Pick start and end date.',
  annotations: [
    { x: 0.5, y: 0.30, text: 'Start date picker.' },
    { x: 0.5, y: 0.45, text: 'End date picker. Must be on/after start, neither in the future.' },
    { x: 0.5, y: 0.60, text: 'For a daily report, both dates are the same.' },
    { x: 0.85, y: 0.92, text: 'Next is greyed out until both dates are valid.' },
  ],
  notes: 'Common mistake: filing today\'s daily report before midnight. The system will reject end-dates in the future.',
})

L_annotated(pptx, {
  moduleLabel: 'M07 · WIZARD STEP 2', pageStr: nextPage(), role: C.rData,
  eyebrow: 'WIZARD STEP 2  ·  COUNTS',
  title: 'Type the numbers — verify auto-calc',
  image: fnFull('36_action_aggregated-data_new_1.png'),
  lede: 'Each field is an integer input — type the number, no commas. Inline hints check the math.',
  annotations: [
    { x: 0.5, y: 0.30, text: 'Fields grouped by category (COUNTS, GENDER, etc.).' },
    { x: 0.5, y: 0.45, text: 'Inline hints: "Total = Symptomatic + Asymptomatic".' },
    { x: 0.5, y: 0.60, text: 'Auto-calculate (top) fills counts from your captured screenings.' },
    { x: 0.5, y: 0.92, text: 'Save Draft to pause; Submit when complete.' },
  ],
  notes: 'Always verify auto-calculated numbers before submitting. Auto-calc uses YOUR records — if you didn\'t capture every traveller, totals are low.',
})

L_annotated(pptx, {
  moduleLabel: 'M07 · QUALITY CHECKS', pageStr: nextPage(), role: C.rData,
  eyebrow: 'QUALITY CHECKS',
  title: 'A green tick means a number adds up',
  image: fnFull('39_action_aggregated-data_history.png'),
  lede: 'Tap any past submission. The detail modal shows period, counts, and a list of quality checks.',
  annotations: [
    { x: 0.5, y: 0.45, text: '"Male + Female adds up to total screened" — should be a green tick.' },
    { x: 0.5, y: 0.55, text: '"With-symptoms + No-symptoms adds up to total" — green tick.' },
    { x: 0.5, y: 0.65, text: '"Reporting period covers N days" — green tick.' },
    { x: 0.5, y: 0.85, text: 'Technical details (server ID, version) hide behind a disclosure.' },
  ],
  notes: 'These checks protect your reputation. Always look for green ticks before signing off.',
})

L_recap(pptx, {
  moduleLabel: 'M07 · RECAP', pageStr: nextPage(), role: C.rData,
  title: 'Module 7 — For the Data Officer',
  questions: [
    { q: 'When is a daily template due?',                                                cue: 'By end-of-shift on the day it covers — never with a future end-date.' },
    { q: 'What does the "Auto-calculate" button do — and what is its risk?',             cue: 'Fills counts from your captured screenings. Risk: only includes YOUR records.' },
    { q: 'Translate "Waiting to upload" badge into plain language.',                     cue: '"Saved on this device but not yet pushed to the server."' },
    { q: 'What does a red ✗ on a Quality check mean?',                                  cue: 'An arithmetic error — fix it and re-submit before signing off.' },
  ],
})

// ═══════════════════════════════════════════════════════════════════════════
// MODULE 8 — CROSS-CUTTING
// ═══════════════════════════════════════════════════════════════════════════
L_moduleCover(pptx, {
  number: '08', role: C.rCross,
  title: 'Cross-cutting features',
  subtitle: 'Sync · Settings · Capabilities & Help · Plain-language reference',
  audience: 'EVERYONE',
  duration: '40 minutes',
  outcome: 'Self-serve any common app problem before calling support',
})

L_hook(pptx, {
  moduleLabel: 'M08 · CROSS', pageStr: nextPage(), role: C.rCross,
  quote: 'Most "the app is broken" calls get fixed by reading two screens.',
  context: 'Plugin Diagnostics + Capabilities & Help are the two screens. Train every officer to open them BEFORE calling support.',
})

L_annotated(pptx, {
  moduleLabel: 'M08 · SYNC', pageStr: nextPage(), role: C.rCross,
  eyebrow: 'SYNC CENTRE',
  title: 'Where captured records meet the server',
  image: fnFull('47_route_sync.png'),
  lede: 'Side menu → Sync Centre. Three tabs: Queue, History, Failed.',
  annotations: [
    { x: 0.5, y: 0.30, text: 'Counts strip — Synced / Pending / Failed across all stores.' },
    { x: 0.5, y: 0.55, text: '"Sync Now" button — runs the upload. Locked while syncing.' },
    { x: 0.5, y: 0.75, text: 'Per-store breakdown — primary, notifications, secondary, alerts, aggregated.' },
  ],
  notes: 'End-of-shift rule: open Sync Centre, tap Sync Now, wait for green ticks before signing out.',
})

L_annotated(pptx, {
  moduleLabel: 'M08 · CAPABILITIES', pageStr: nextPage(), role: C.rCross,
  eyebrow: 'CAPABILITIES & HELP',
  title: 'What this device can actually do',
  image: fnFull('66_route_capabilities-help.png'),
  lede: 'Settings → "Explore capabilities & how-to". Per-feature cards with on/off toggle and try-it button.',
  annotations: [
    { x: 0.5, y: 0.30, text: 'Cards grouped: Security · Capture assists · Communication · Connectivity.' },
    { x: 0.5, y: 0.55, text: 'Each card: plain-language description + status indicator + toggle.' },
    { x: 0.5, y: 0.78, text: '"Try it now" runs a non-destructive probe of the feature.' },
  ],
  notes: 'Excellent self-service support. When a feature seems broken, check here first — is it even on?',
})

L_table(pptx, {
  moduleLabel: 'M08 · BADGES', pageStr: nextPage(), role: C.rCross,
  eyebrow: 'STATUS BADGES — REFERENCE',
  title: 'What every badge in the app means',
  lede: 'Memorise this table. Trainees will see these badges everywhere.',
  rowH: 0.46,
  colW: [3.0, 4.0, 5.0],
  rows: [
    ['Badge', 'Plain meaning', 'Where you see it'],
    ['Uploaded ✓',           'On the server',                                     'Records, submissions'],
    ['Waiting to upload ⟳',  'Saved on the device, will upload at next sync',     'Records, submissions'],
    ['Upload failed',        'Something went wrong; check Sync Centre → Failed',  'Records, submissions'],
    ['Stuck',                'Contact support; held back from automatic retries', 'Sync Centre'],
    ['Routine',              'Normal-priority referral',                          'Notifications'],
    ['Urgent',               'Higher-priority referral — act today',              'Notifications'],
    ['Emergency',            'Highest priority — act NOW',                        'Notifications'],
    ['Waiting',              'Case is open, no one has picked it up yet',         'Cases, alerts'],
    ['Being worked on',      'Case is open, someone is investigating',            'Cases'],
    ['Decision made',        'Case has a disposition (released/quarantined/…)',    'Cases'],
    ['Done',                 'Case or alert is fully closed',                     'Cases, alerts'],
    ['Acknowledged',         'A supervisor has seen this — clock for response runs', 'Alerts'],
  ],
})

L_recap(pptx, {
  moduleLabel: 'M08 · RECAP', pageStr: nextPage(), role: C.rCross,
  title: 'Module 8 — Cross-cutting',
  questions: [
    { q: 'What should you do at end-of-shift?',                                              cue: 'Open Sync Centre → tap Sync Now → wait for green ticks.' },
    { q: 'Where do you check whether your camera/voice/barcode is turned on?',               cue: 'Settings → Explore capabilities & how-to → check the toggle on each card.' },
    { q: 'What does "Waiting to upload" mean?',                                              cue: 'Saved on the device, will upload at next sync.' },
    { q: 'Difference between Routine, Urgent and Emergency?',                                cue: 'Referral priority. Emergency = act now. Urgent = today. Routine = within shift.' },
  ],
})

// ═══════════════════════════════════════════════════════════════════════════
// MODULE 9 — DATA FLOW
// ═══════════════════════════════════════════════════════════════════════════
L_moduleCover(pptx, {
  number: '09', role: C.rFlow,
  title: 'Complete Data Flow',
  subtitle: 'One traveller from arrival to alert closure — the whole system in one story',
  audience: 'EVERYONE',
  duration: '30 minutes',
  outcome: 'Trace one case end-to-end without a script',
})

L_hook(pptx, {
  moduleLabel: 'M09 · FLOW', pageStr: nextPage(), role: C.rFlow,
  quote: 'A system you can\'t trace is a system you can\'t trust.',
  context: 'Module 9 walks one real traveller — Joseph Phiri, arriving from DRC — across all six stages of the system. Every trainee should be able to repeat this story unaided by the end.',
})

L_annotated(pptx, {
  moduleLabel: 'M09 · STAGE 1', pageStr: nextPage(), role: C.rFlow,
  eyebrow: 'STAGE 1 OF 6  ·  ARRIVAL',
  title: '14:33:00  ·  Joseph Phiri arrives at LUSKIA POE',
  image: fnFull('06_route_home.png'),
  lede: 'Joseph is a 34-year-old male trader returning from DRC by road. He approaches the screener\'s kiosk with a slight cough.',
  annotations: [
    { x: 0.5, y: 0.30, text: 'WHO: Mary Banda, Screener on duty.' },
    { x: 0.5, y: 0.50, text: 'WHAT SHE DOES: opens the app on her tablet (already signed in), taps Start Screening.' },
    { x: 0.5, y: 0.70, text: 'WHERE THE DATA GOES: form opens, ready to capture.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M09 · STAGE 2', pageStr: nextPage(), role: C.rFlow,
  eyebrow: 'STAGE 2 OF 6  ·  PRIMARY SCREENING',
  title: '14:33:14  ·  Captured in 14 seconds',
  image: fnFlow('09_PrimaryScreening.png'),
  lede: 'Mary fills the form: Direction = Entry, Sex = Male, Temperature = 38.6°C (red high-fever indicator), Symptoms = Symptomatic. Joseph\'s name is captured.',
  annotations: [
    { x: 0.5, y: 0.85, text: 'The big red button reads "Capture & Refer →".' },
    { x: 0.5, y: 0.92, text: 'One tap commits BOTH the screening AND the referral notification.' },
  ],
  notes: 'Two records written atomically — both succeed or neither does. No half-states.',
})

L_annotated(pptx, {
  moduleLabel: 'M09 · STAGE 3', pageStr: nextPage(), role: C.rFlow,
  eyebrow: 'STAGE 3 OF 6  ·  REFERRAL HANDOFF',
  title: '14:35:02  ·  Card lands in the secondary queue',
  image: fnFull('20_route_NotificationsCenter.png'),
  lede: 'On the Health Officer\'s tablet, a new card appears at the top — Joseph Phiri, ENTRY, HIGH priority.',
  annotations: [
    { x: 0.5, y: 0.30, text: 'WHO: Esther Mwale, Health Officer.' },
    { x: 0.5, y: 0.50, text: 'WHAT SHE DOES: taps the card to open the case.' },
    { x: 0.5, y: 0.70, text: 'NOTHING leaves the device yet — both Mary\'s and Esther\'s tablets work from the same offline copy.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M09 · STAGE 4', pageStr: nextPage(), role: C.rFlow,
  eyebrow: 'STAGE 4 OF 6  ·  INVESTIGATION',
  title: '14:46:00  ·  4-step wizard complete in 11 minutes',
  image: fnFlow('19_secondary-screening_demo01-notif-4444-aaaa-555566667777.png'),
  lede: 'Esther completes Profile → Symptoms → Exposures → Analysis.',
  annotations: [
    { x: 0.5, y: 0.20, text: 'Profile: confirms identity, captures conveyance (BUS-LUS-44), vitals (38.6°C, BP 128/82).' },
    { x: 0.5, y: 0.40, text: 'Symptoms: ticks Cough + Fever. No haemorrhagic signs.' },
    { x: 0.5, y: 0.55, text: 'Exposures: Joseph visited a livestock market in DRC 5 days ago.' },
    { x: 0.5, y: 0.70, text: 'Analysis: engine suggests RESPIRATORY syndrome, HIGH risk, route to PHEOC.' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M09 · STAGE 5', pageStr: nextPage(), role: C.rFlow,
  eyebrow: 'STAGE 5 OF 6  ·  DISPOSITION + ALERT',
  title: '14:46:00  ·  Alert raised + routed',
  image: fnFlow('33_secondary-screening_demo01-notif-4444-aaaa-555566667777.png'),
  lede: 'Esther selects Disposition = "Referred (sent to a clinic)". Adds officer notes. Taps Save & Disposition.',
  annotations: [
    { x: 0.5, y: 0.40, text: 'Case status changes to "Decision made" (DISPOSITIONED).' },
    { x: 0.5, y: 0.55, text: 'Alert raised (HIGH-priority RESPIRATORY) and routed to PHEOC.' },
    { x: 0.5, y: 0.92, text: 'Sync Centre now shows 3 records waiting (screening + case + alert).' },
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M09 · STAGE 6', pageStr: nextPage(), role: C.rFlow,
  eyebrow: 'STAGE 6 OF 6  ·  ACKNOWLEDGE + CLOSE',
  title: '15:14:00  ·  PHEOC acknowledges within target',
  image: fnFull('29_route_alerts.png'),
  lede: 'On next sync, the alert reaches the central server. PHEOC officer is notified by SMS + dashboard.',
  annotations: [
    { x: 0.5, y: 0.20, text: 'WHO: PHEOC Officer for Lusaka province.' },
    { x: 0.5, y: 0.40, text: 'Opens Active Alerts list. Taps the new alert. Acknowledges.' },
    { x: 0.5, y: 0.65, text: 'Once response action recorded ("follow-up call made; traveller stable"), alert CLOSED.' },
    { x: 0.5, y: 0.85, text: 'Full timeline visible to anyone with audit access.' },
  ],
  notes: 'Total elapsed: capture 14s, investigation 11min, alert routed instantly on sync, acknowledged within 24h. The 7-1-7 framework was met.',
})

L_takeaway(pptx, {
  moduleLabel: 'M09 · TAKEAWAY', pageStr: nextPage(), role: C.rFlow,
  title: 'Capture in seconds. Investigate in minutes. Alert in hours. Close within days.',
  body: 'When the system runs the way it was designed, every traveller flows through six stages from arrival to closure in under one working day.',
})

L_recap(pptx, {
  moduleLabel: 'M09 · RECAP', pageStr: nextPage(), role: C.rFlow,
  title: 'Module 9 — Complete Data Flow',
  questions: [
    { q: 'How long is the screener\'s part of the flow?',                                  cue: 'Around 14 seconds — direction → sex → temp → symptoms → name → capture.' },
    { q: 'When does the alert leave the device?',                                          cue: 'When the device next syncs to the server.' },
    { q: 'Who can see the full audit trail?',                                              cue: 'District / PHEOC / National admins. Trail covers capture → ack → close.' },
    { q: 'Did this case meet the 7-1-7 framework? How do you know?',                       cue: 'Yes — alert acknowledged within 24h, response within 24h. PHEOC dashboard shows green.' },
  ],
})

// ═══════════════════════════════════════════════════════════════════════════
// MODULE 10 — DEVELOPERS
// ═══════════════════════════════════════════════════════════════════════════
L_moduleCover(pptx, {
  number: '10', role: C.rDev,
  title: 'For Developers & IT Support',
  subtitle: 'Architecture · diagnostics · model manager · build & deploy',
  audience: 'IT engineers, application support, ZNPHI tech team',
  duration: '90 minutes',
  outcome: 'Triage any field officer report by reading two screens',
})

L_hook(pptx, {
  moduleLabel: 'M10 · DEV', pageStr: nextPage(), role: C.rDev,
  quote: 'The first piece of evidence is the Plugin Diagnostics report.',
  context: 'When a field officer says "X is broken", do not guess. Ask them to open Settings → Plugin Diagnostics → Copy report. 80% of incidents triage themselves.',
})

L_table(pptx, {
  moduleLabel: 'M10 · STACK', pageStr: nextPage(), role: C.rDev,
  eyebrow: 'TECHNOLOGY STACK',
  title: 'What POE Sentinel is built on',
  rowH: 0.45,
  colW: [3.0, 9.0],
  rows: [
    ['Layer', 'Technology'],
    ['Mobile UI',      'Vue 3 (Composition API) + Ionic 8 + Capacitor 8 — single codebase compiles to Android (iOS future)'],
    ['Offline storage','IndexedDB (browser-grade) — 17 stores, every record gets a client_uuid before it sees the server'],
    ['Server API',     'Laravel 11 PHP at api/ — REST endpoints, MySQL backing store, sync_batches for the offline queue'],
    ['Auth',           'Server-issued session, cached SHA-256 hash on device for offline login'],
    ['Native plugins', '14 Capacitor plugins; 1 custom Java plugin (ModuleInstall) for ML Kit downloads'],
    ['On-device ML',   'Google ML Kit — text recognition, face detection, translate, entity extraction (opt-in)'],
    ['Android target', 'targetSdk 36, minSdk 26 (Android 8+), edge-to-edge default, status bar styled to brand navy'],
  ],
})

L_annotated(pptx, {
  moduleLabel: 'M10 · DIAGNOSTICS', pageStr: nextPage(), role: C.rDev,
  eyebrow: 'PLUGIN DIAGNOSTICS',
  title: 'Settings → Plugin diagnostics — your first stop',
  image: fnFull('64_route_settings_diagnostics.png'),
  lede: 'Auto-runs on mount. 13 suites covering every plugin wrapper.',
  annotations: [
    { x: 0.5, y: 0.18, text: 'Summary strip: Pass / Warn / Fail / Skip totals + duration + platform.' },
    { x: 0.5, y: 0.32, text: 'Filter chips — All / Failures / Warnings / Passing / Skipped.' },
    { x: 0.5, y: 0.55, text: 'Per-suite cards — failing suites auto-expand with stack trace + remediation hint.' },
    { x: 0.5, y: 0.92, text: '"Copy report" — JSON dump for support tickets.' },
  ],
  notes: 'When a field officer reports "X is not working", ask them to open Diagnostics, tap Copy report, paste it into your ticket.',
})

L_split(pptx, {
  moduleLabel: 'M10 · SCHEMA', pageStr: nextPage(), role: C.rDev,
  eyebrow: 'KEY INDEXEDDB STORES',
  title: 'The 8 stores you will read most often',
  image: fnFull('63_route_settings_sentinel-models.png'),
  lede: 'Database name: poe_offline_db. Every record key: client_uuid.',
  bullets: [
    { text: 'primary_screenings — every captured traveller. Index: poe_code, sync_status.' },
    { text: 'notifications — referrals + system notifications. Secondary lookup: notification_id index.' },
    { text: 'secondary_screenings — full case investigations. Index: notification_id (links back to a notification).' },
    { text: 'secondary_symptoms / secondary_exposures / secondary_travel_countries — child tables, indexed by secondary_screening_id.' },
    { text: 'alerts — IHR-grade alerts. Index: status, risk_level.' },
    { text: 'aggregated_submissions — data officer submissions. FK: template_id.' },
    { text: 'sync_batches / sync_batch_items — the upload queue. Each batch is one HTTP POST.' },
    { text: 'Every store has sync_status (SYNCED / UNSYNCED / FAILED / QUARANTINED).' },
  ],
})

L_table(pptx, {
  moduleLabel: 'M10 · BUILD', pageStr: nextPage(), role: C.rDev,
  eyebrow: 'BUILD & DEPLOY',
  title: 'Producing a signed Android APK',
  rowH: 0.5,
  colW: [0.8, 4.5, 7.0],
  rows: [
    ['#',  'Step',                                  'Command / file'],
    ['1',  'Install dependencies',                  'npm install'],
    ['2',  'Configure signing',                     'create android/keystore.properties with storeFile + storePassword + keyAlias + keyPassword'],
    ['3',  'Build the JS bundle',                   'npx vite build'],
    ['4',  'Copy bundle into the Android project',  'npx cap sync android'],
    ['5',  'Build the signed APK',                  './build-signed-apk.sh release'],
    ['6',  'Output',                                'apk-details/app-release-signed.apk'],
    ['7',  'Test on a real device',                 'adb install apk-details/app-release-signed.apk'],
  ],
})

L_split(pptx, {
  moduleLabel: 'M10 · LOGS', pageStr: nextPage(), role: C.rDev,
  eyebrow: 'WHEN SOMETHING IS BROKEN',
  title: 'Where to look — in order',
  image: fnFull('64_route_settings_diagnostics.png'),
  lede: 'Triage any incident in the right order. Skipping ahead wastes time.',
  bullets: [
    { text: '1. Plugin Diagnostics → Copy report. Paste into the ticket.' },
    { text: '2. Settings → About — capture app version + schema version. Always include in the ticket.' },
    { text: '3. Sync Centre → Failed tab — per-record error from the last sync attempt.' },
    { text: '4. adb logcat | grep "Capacitor\\|POE\\|Sentinel" — native errors during dev builds.' },
    { text: '5. Browser DevTools (chrome://inspect with device plugged in) — JS console + IndexedDB inspector.' },
    { text: '6. API server logs — api/storage/logs/laravel.log on the server.' },
  ],
})

L_recap(pptx, {
  moduleLabel: 'M10 · RECAP', pageStr: nextPage(), role: C.rDev,
  title: 'Module 10 — For Developers & IT',
  questions: [
    { q: 'Where does the user open the per-plugin self-test?',                              cue: 'Settings → Actions → Plugin diagnostics.' },
    { q: 'What is the IndexedDB key on every record?',                                      cue: 'client_uuid — generated on the device before sync.' },
    { q: 'How do you build a signed APK?',                                                  cue: './build-signed-apk.sh release after vite build + cap sync.' },
    { q: 'What is the FIRST piece of information you ask for in any field bug report?',     cue: 'Plugin Diagnostics → Copy report. Paste the JSON into the ticket.' },
  ],
})

// ═══════════════════════════════════════════════════════════════════════════
// MODULE 11 — CLOSING
// ═══════════════════════════════════════════════════════════════════════════
L_moduleCover(pptx, {
  number: '11', role: C.rEveryone,
  title: 'Closing & Resources',
  subtitle: 'Thank you. Now go and train Zambia.',
  audience: 'EVERYONE',
  duration: '15 minutes',
  outcome: 'Leave with handover material + contacts',
})

L_objectives(pptx, {
  moduleLabel: 'M11 · CLOSING', pageStr: nextPage(), role: C.rEveryone,
  title: 'What every trainer leaves with',
  objectives: [
    { verb: 'KNOW',     text: 'The 6-stage data flow (Module 1 + Module 9) by heart.' },
    { verb: 'DEMONSTRATE', text: 'The Screener\'s capture loop in 20 seconds (Module 2).' },
    { verb: 'DEMONSTRATE', text: 'The Health Officer\'s 4-step wizard end-to-end (Module 3).' },
    { verb: 'EXPLAIN',  text: 'Where each role sees what — district / PHEOC / national.' },
    { verb: 'COMMAND',  text: 'Plain-language vocabulary for officers with high-school English.' },
    { verb: 'DELIVER',  text: 'A repeatable course for other officers in their region.' },
  ],
})

L_split(pptx, {
  moduleLabel: 'M11 · RESOURCES', pageStr: nextPage(), role: C.rEveryone,
  eyebrow: 'WHERE TO GET HELP',
  title: 'Support, escalation, and the trainer\'s toolkit',
  image: fnFull('66_route_capabilities-help.png'),
  bullets: [
    { text: 'In-app help: Settings → Capabilities & Help. Per-feature explainer + status.' },
    { text: 'Technical issues: Settings → Plugin Diagnostics → Copy report → email support.' },
    { text: 'Training resources: this manual + the 102-screenshot gallery in _audit/PRESENTATION/.' },
    { text: 'Policy / clinical questions: ZNPHI surveillance directorate.' },
    { text: 'App bugs / feature requests: ZNPHI digital-health team via the standard ticket channel.' },
    { text: 'Provincial coordination: your PHEOC officer.' },
  ],
})

// Final close slide
{
  const s = pptx.addSlide()
  s.background = { color: C.navyBg }
  s.addText('THANK YOU.', {
    x: G.M, y: 2.4, w: G.W - 2*G.M, h: 1.4,
    fontFace: 'Calibri', fontSize: 96, color: C.white, bold: true, align: 'center', charSpacing: 6,
  })
  s.addText('Now go and train Zambia.', {
    x: G.M, y: 4.0, w: G.W - 2*G.M, h: 0.8,
    fontFace: 'Georgia', fontSize: 28, color: C.teal500, italic: true, align: 'center',
  })
  s.addText('ZNPHI · ECSA-HC · Ministry of Health · Republic of Zambia', {
    x: G.M, y: 5.2, w: G.W - 2*G.M, h: 0.4,
    fontFace: 'Calibri', fontSize: 13, color: C.white, transparency: 25, align: 'center', bold: true, charSpacing: 6,
  })
  zambiaStripe(s)
}

// ── Save ───────────────────────────────────────────────────────────────────
pptx.writeFile({ fileName: OUT_PPTX }).then(name => {
  console.log('Wrote:', name)
}).catch(err => {
  console.error('Failed to write pptx:', err)
  process.exit(2)
})

// ── Outline (markdown) ─────────────────────────────────────────────────────
const outline = `# POE Sentinel — TOT Manual: Module Outline

Generated: ${new Date().toISOString()}
Output: _audit/PRESENTATION/POE_Sentinel_TOT_Manual.pptx

## Design system
- 14 distinct slide layouts (vs 1 repeated in the v1 deck)
- Tonal palette per role (10 role accents)
- Zambia flag accent on covers
- Premium typography (Georgia for editorial quotes; Calibri for body)
- Annotated screenshots with numbered hotspots ON the image
- Mayer's multimedia learning principles applied (signaling, contiguity, segmenting)

## Instructional pattern (every module)
1. **Hook** — quote / why this matters
2. **Objectives** — Bloom-verb learning outcomes (NAME, EXPLAIN, COMPLETE, etc.)
3. **Present** — concept slides with split layouts
4. **Demonstrate** — annotated phone screenshots
5. **Practice** — hands-on activity slide (navy background, contrasting cue)
6. **Assess** — 4-question retrieval-practice recap

## Modules
| # | Title | Audience | Runtime |
|---|---|---|---|
| Front matter | Cover, course overview, roles, how-to | Everyone | 10 min |
| 1 | Welcome & The Big Picture | Everyone | 30 min |
| 2 | For the Screener | Screening Officers | 90 min |
| 3 | For the Health Officer | Secondary Officers | 120 min |
| 4 | For the District Supervisor | District health offices | 60 min |
| 5 | For the PHEOC Officer | Provincial PHEOC | 75 min |
| 6 | For the National Administrator | ZNPHI HQ | 90 min |
| 7 | For the Data Officer | Data Officers | 45 min |
| 8 | Cross-cutting features | Everyone | 40 min |
| 9 | Complete Data Flow | Everyone | 30 min |
| 10 | For Developers & IT | Tech team | 90 min |
| 11 | Closing & Resources | Everyone | 15 min |

**Total: ~12 hours of training material.** Suggested as a 2-day workshop OR as role-specific 90-min sessions.
`
fs.writeFileSync(OUT_OUTLINE, outline)
console.log('Wrote outline:', OUT_OUTLINE)
