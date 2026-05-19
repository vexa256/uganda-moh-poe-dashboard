<template>
  <div class="ss-root" ref="rootRef" :class="{ 'ss-root--open': open, 'ss-root--disabled': disabled }">
    <!-- ── Trigger ─────────────────────────────────────────────────────── -->
    <button
      type="button"
      class="ss-trigger"
      :class="[selectClass, { 'ss-trigger--placeholder': !hasValue }]"
      :disabled="disabled"
      :aria-expanded="open"
      :aria-haspopup="'listbox'"
      :aria-label="ariaLabel || placeholder"
      @click="toggle"
    >
      <span class="ss-trigger-text">{{ hasValue ? selectedLabel : placeholder }}</span>
      <svg
        class="ss-chevron"
        :class="{ 'ss-chevron--up': open }"
        viewBox="0 0 10 6" fill="none"
        stroke="currentColor" stroke-width="2"
        stroke-linecap="round" stroke-linejoin="round"
        aria-hidden="true"
      ><path d="M1 1l4 4 4-4"/></svg>
    </button>

    <!-- ── Dropdown — teleported to the nearest <ion-modal> when present,
         else to <body>. Why: Ionic's IonModal installs a focus trap that
         pulls focus back inside the modal whenever any descendant
         input gains focus from "outside". A teleport to <body> places
         the panel's search input *outside* the modal's focus-trap
         root, so the keyboard never opens — the user sees the dropdown
         but cannot type. Teleporting to the modal's element keeps the
         input inside the trap. When not inside a modal we fall back to
         <body> so the panel can still escape ion-content overflow:hidden.
         (2026-05-19) -->
    <Teleport :to="teleportTarget">
      <!-- Mobile-only backdrop. Tap closes; presence stops accidental tap-through. -->
      <Transition name="ss-fade">
        <!-- Mobile-only backdrop. Tap closes; presence stops accidental tap-through.
             2026-05-19 — only `@click` (NO @touchstart). touchstart on the backdrop
             previously fired during the touchend of the trigger button at panel-open
             time on some Android WebViews, racing the dropdown shut milliseconds
             after open. click is enough: the panel sits above the backdrop, so a
             tap on the input never reaches the backdrop. -->
        <div v-if="open && isPhoneViewport" class="ss-backdrop" @click="close" />
      </Transition>
      <Transition name="ss-fade">
        <!-- 2026-05-19 — REMOVED `@mousedown.prevent` from this panel root.
             It cancelled the synthesized mousedown that browsers fire after a
             tap on a child element, which on mobile prevented the search input
             from gaining focus → soft keyboard never opened → user could not
             type. Where preventing-blur matters (option clicks) the .prevent
             is moved directly onto the `.ss-opt` element below. -->
        <div
          v-if="open"
          class="ss-panel"
          :style="panelStyle"
          role="listbox"
        >
          <!-- Search bar -->
          <div class="ss-search-bar">
            <svg class="ss-search-icon" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
              <circle cx="6.5" cy="6.5" r="4"/>
              <line x1="10" y1="10" x2="14" y2="14"/>
            </svg>
            <input
              ref="inputRef"
              class="ss-search-input"
              type="text"
              :value="query"
              :placeholder="searchPlaceholder"
              autocomplete="off"
              autocorrect="off"
              autocapitalize="off"
              spellcheck="false"
              tabindex="0"
              @input="query = $event.target.value"
              @click.stop
              @mousedown.stop
              @touchstart.stop
              @keydown.escape.prevent="close"
              @keydown.enter.prevent="selectFirst"
              @keydown.arrow-down.prevent="moveDown"
              @keydown.arrow-up.prevent="moveUp"
            />
            <button v-if="query" type="button" class="ss-clear-btn" @click.stop="query = ''" aria-label="Clear search">
              <svg viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
                <line x1="2" y1="2" x2="8" y2="8"/><line x1="8" y1="2" x2="2" y2="8"/>
              </svg>
            </button>
          </div>

          <!-- Result count hint -->
          <div v-if="query && filtered.length > 0" class="ss-count">{{ filtered.length }} result{{ filtered.length === 1 ? '' : 's' }}</div>

          <!-- Options list -->
          <div class="ss-list" ref="listRef">
            <!-- Clear / empty option -->
            <div
              v-if="placeholder !== null && !query"
              class="ss-opt ss-opt--empty"
              :class="{ 'ss-opt--active': !hasValue }"
              role="option"
              :aria-selected="!hasValue"
              @mousedown.prevent
              @click="pick(emptyValue)"
            >{{ placeholder }}</div>

            <!-- No results -->
            <div v-if="filtered.length === 0" class="ss-empty-state">
              <svg viewBox="0 0 40 40" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round">
                <circle cx="18" cy="18" r="10"/>
                <line x1="26" y1="26" x2="36" y2="36"/>
                <line x1="14" y1="18" x2="22" y2="18"/>
              </svg>
              <span>No results for <strong>"{{ query }}"</strong></span>
            </div>

            <!-- Options -->
            <template v-for="(opt, i) in filtered" :key="String(opt[valueKey])">
              <!-- Separator -->
              <div v-if="opt[valueKey] === '__sep__' || opt.disabled" class="ss-separator" aria-hidden="true">
                <span>{{ opt[labelKey] === '─────────────' ? 'All countries' : opt[labelKey] }}</span>
              </div>
              <!-- Normal option -->
              <div
                v-else
                class="ss-opt"
                :class="{
                  'ss-opt--selected': String(opt[valueKey]) === String(modelValue),
                  'ss-opt--focused': i === focusIdx,
                }"
                role="option"
                :aria-selected="String(opt[valueKey]) === String(modelValue)"
                @mousedown.prevent
                @click="pick(opt[valueKey])"
              >
                <span class="ss-opt-text">{{ opt[labelKey] }}</span>
                <svg
                  v-if="String(opt[valueKey]) === String(modelValue)"
                  class="ss-check-icon"
                  viewBox="0 0 14 14" fill="none"
                  stroke="currentColor" stroke-width="2.5"
                  stroke-linecap="round" stroke-linejoin="round"
                  aria-hidden="true"
                ><polyline points="2 7 5.5 10.5 12 3"/></svg>
              </div>
            </template>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, nextTick, onMounted, onUnmounted } from 'vue'

const props = defineProps({
  modelValue:        { default: '' },
  options:           { type: Array, required: true },
  placeholder:       { default: '— Select —' },
  searchPlaceholder: { type: String, default: 'Search…' },
  ariaLabel:         { type: String, default: '' },
  disabled:          { type: Boolean, default: false },
  valueKey:          { type: String, default: 'value' },
  labelKey:          { type: String, default: 'label' },
  selectClass:       { type: String, default: '' },
  emptyValue:        { default: '' },
})
const emit = defineEmits(['update:modelValue', 'change'])

const rootRef  = ref(null)
const inputRef = ref(null)
const listRef  = ref(null)
const open     = ref(false)
const query    = ref('')
const focusIdx = ref(-1)
const panelStyle = ref({})
const isPhoneViewport = ref(false)
// Dynamic teleport target. Defaults to <body>; flipped to the nearest
// <ion-modal> ancestor on open if one exists.  Recomputed when the
// dropdown opens so it always tracks the *current* modal context (the
// same SearchableSelect component instance can be re-rendered inside a
// modal that opens later, e.g. user-create flow).
const teleportTarget = ref('body')

function _resolveTeleportTarget() {
  // Walk up from rootRef to find a containing <ion-modal>.
  if (typeof window === 'undefined') { teleportTarget.value = 'body'; return }
  const root = rootRef.value
  if (!root) { teleportTarget.value = 'body'; return }
  let el = root.parentElement
  while (el && el !== document.body) {
    // Match ion-modal in any case (Web Components are case-insensitive in HTML).
    const tag = (el.tagName || '').toUpperCase()
    if (tag === 'ION-MODAL') { teleportTarget.value = el; return }
    el = el.parentElement
  }
  teleportTarget.value = 'body'
}

function _updateViewportFlag() {
  if (typeof window === 'undefined') { isPhoneViewport.value = false; return }
  const w = (window.visualViewport && window.visualViewport.width) || window.innerWidth || 0
  isPhoneViewport.value = w > 0 && w <= 520
}

// ── Derived state ───────────────────────────────────────────────────────────
const hasValue = computed(() => {
  const v = props.modelValue
  return v !== '' && v !== null && v !== undefined && String(v) !== String(props.emptyValue)
})

const selectedLabel = computed(() => {
  const found = props.options.find(o => String(o[props.valueKey]) === String(props.modelValue))
  return found ? found[props.labelKey] : ''
})

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase()
  if (!q) return props.options
  return props.options.filter(o => {
    if (o[props.valueKey] === '__sep__' || o.disabled) return false
    const lbl = String(o[props.labelKey] ?? '').toLowerCase()
    const val = String(o[props.valueKey] ?? '').toLowerCase()
    return lbl.includes(q) || val.includes(q)
  })
})

// ── Panel positioning ────────────────────────────────────────────────────────
// 2026-05-07 — Keyboard-aware. Uses fixed positioning so the panel escapes
// any overflow:hidden parent. Computes available vertical space against
// the VISUAL VIEWPORT (window.visualViewport) when present — which
// shrinks when the soft keyboard opens — so the panel never lands under
// the Android keyboard. On Capacitor with Keyboard.resize: 'ionic' the
// visual viewport doesn't shrink; we subscribe to the Keyboard plugin
// events to learn keyboard height and subtract it manually.
const _kbHeight = ref(0)

function _visibleHeight() {
  // Prefer visualViewport if available and meaningfully smaller (= keyboard open).
  if (typeof window !== 'undefined' && window.visualViewport) {
    const vh = window.visualViewport.height
    if (vh > 100 && vh < window.innerHeight - 80) return vh
  }
  // Fallback: subtract Capacitor-reported keyboard height from innerHeight.
  return Math.max(120, (window.innerHeight || 800) - _kbHeight.value)
}

function calcPanelStyle() {
  const el = rootRef.value
  if (!el) return {}
  const rect       = el.getBoundingClientRect()
  const visH       = _visibleHeight()
  const visW       = (typeof window !== 'undefined' && window.visualViewport) ? window.visualViewport.width : (window.innerWidth || 360)
  const maxH       = 280
  const SAFE_PAD   = 12   // gap from input edge / viewport bottom
  const MIN_BELOW  = 140  // need at least this much below to bottom-place
  const spaceBelow = visH - rect.bottom - SAFE_PAD
  const spaceAbove = rect.top - SAFE_PAD
  const openAbove  = spaceBelow < MIN_BELOW && spaceAbove > spaceBelow

  // 2026-05-19 — Mobile hardening. On phone-sized viewports (≤520px) the
  // trigger button is often narrow (inside a 2-col form / chip row) and
  // sits near a screen edge. Anchoring the panel to the button's width
  // and left edge produced a tiny, clipped, sometimes off-screen panel —
  // users perceived it as "search broken" because the input was barely
  // visible. Centre the panel and make it readable instead. The panel is
  // teleported to <body>, so this width is independent of the form layout.
  const isPhone = visW <= 520
  const panelWidth = isPhone ? Math.max(280, Math.min(visW - 24, 460)) : Math.max(rect.width, 280)
  const left = isPhone
    ? Math.max(12, Math.round((visW - panelWidth) / 2))
    : Math.max(8, Math.min(rect.left, visW - panelWidth - 8))

  return {
    position: 'fixed',
    left: left + 'px',
    width: panelWidth + 'px',
    // Defeat any ancestor that might create a stacking context (Ionic's
    // ion-modal uses 20–40; ion-loading uses 30000-ish; ion-toast 60000).
    // Max signed 32-bit int wins everywhere; !important via inline style
    // is not possible, so we rely on the inline value being maximally
    // specific AND on `isolation: isolate` in CSS to force a fresh
    // stacking context for the panel itself.
    zIndex: '2147483647',
    ...(openAbove
      ? {
          // Anchor by `top` (not `bottom`) — visualViewport-relative
          // bottom calc is fragile across browsers / Capacitor.
          top: Math.max(8, rect.top - Math.min(spaceAbove, maxH)) + 'px',
          maxHeight: Math.min(spaceAbove, maxH) + 'px',
        }
      : {
          top: Math.min(rect.bottom, visH - 60) + 'px',
          maxHeight: Math.min(Math.max(spaceBelow, MIN_BELOW), maxH) + 'px',
        }
    ),
  }
}

// Recompute on viewport / keyboard / scroll change while the dropdown
// is open. Throttled via rAF + chases the keyboard animation across
// multiple frames so the panel never lags behind the IME slide-up.
let _repositionPending = false
function _scheduleReposition() {
  if (!open.value || _repositionPending) return
  _repositionPending = true
  requestAnimationFrame(() => {
    _repositionPending = false
    if (!open.value) return
    _updateViewportFlag()
    panelStyle.value = calcPanelStyle()
  })
}

// Aggressive re-position: fire at 0/50/150/300/500ms after a keyboard
// event so the panel keeps up with the IME slide animation. Without this,
// on Android with Keyboard.resize:'ionic', visualViewport sometimes
// reports the SHRUNK height only after the keyboard finished animating;
// the dropdown that opened during the animation can land under the IME.
function _aggressiveReposition() {
  _scheduleReposition()
  setTimeout(_scheduleReposition, 50)
  setTimeout(_scheduleReposition, 150)
  setTimeout(_scheduleReposition, 300)
  setTimeout(_scheduleReposition, 500)
}

// ── Open / close ─────────────────────────────────────────────────────────────
function toggle() {
  if (props.disabled) return
  open.value ? close() : openDropdown()
}

function openDropdown() {
  query.value  = ''
  focusIdx.value = -1
  _updateViewportFlag()
  _resolveTeleportTarget()
  panelStyle.value = calcPanelStyle()
  open.value = true
  nextTick(() => {
    inputRef.value?.focus()
    // Scroll selected option into view
    const sel = listRef.value?.querySelector('.ss-opt--selected')
    sel?.scrollIntoView({ block: 'nearest' })
    // Chase the keyboard animation — focus() above triggers IME on Android.
    _aggressiveReposition()
  })
}

function close() {
  open.value = false
  query.value = ''
  focusIdx.value = -1
}

// ── Selection ────────────────────────────────────────────────────────────────
function pick(val) {
  const sample = props.options.find(o => String(o[props.valueKey]) === String(val))
  const next = sample !== undefined ? sample[props.valueKey] : val
  emit('update:modelValue', next)
  emit('change', next)
  close()
}

function selectFirst() {
  const selectable = filtered.value.filter(o => o[props.valueKey] !== '__sep__' && !o.disabled)
  const target = focusIdx.value >= 0 ? selectable[focusIdx.value] : selectable.length === 1 ? selectable[0] : null
  if (target) pick(target[props.valueKey])
}

// ── Keyboard navigation ───────────────────────────────────────────────────────
function moveDown() {
  const max = filtered.value.filter(o => o[props.valueKey] !== '__sep__' && !o.disabled).length - 1
  focusIdx.value = Math.min(focusIdx.value + 1, max)
  scrollFocused()
}

function moveUp() {
  focusIdx.value = Math.max(focusIdx.value - 1, 0)
  scrollFocused()
}

function scrollFocused() {
  nextTick(() => listRef.value?.querySelector('.ss-opt--focused')?.scrollIntoView({ block: 'nearest' }))
}

// ── Outside click & scroll-to-close ─────────────────────────────────────────
function onDocClick(e) {
  if (!open.value) return
  const panel = document.querySelector('.ss-panel')
  if (rootRef.value?.contains(e.target)) return
  if (panel?.contains(e.target)) return
  close()
}

function onScroll() {
  // 2026-05-07 — Keep the dropdown open across scroll events; reposition
  // instead. Closing-on-scroll was acceptable on desktop but jarring on
  // mobile where the OS auto-scrolls to keep a focused input on screen
  // (which would auto-close the dropdown the user just opened).
  if (open.value) _scheduleReposition()
}

// 2026-05-07 — Keyboard / visual-viewport listeners. Two layers:
//   • Capacitor Keyboard plugin events (Android / iOS native) — give us
//     the keyboard height when Keyboard.resize is 'ionic' (mode used by
//     this app, which keeps the WebView at full size when keyboard is up).
//   • window.visualViewport.resize — covers browser dev + Capacitor
//     'native' resize mode. Auto-shrinks when keyboard opens.
// Both feed the same _scheduleReposition() throttled callback.
let _kbListeners = []
let _vvListeners = []

async function _installKeyboardListeners() {
  // Capacitor Keyboard plugin — best path on real devices.
  try {
    const mod = await import('@capacitor/keyboard')
    const Keyboard = mod && mod.Keyboard
    if (Keyboard && typeof Keyboard.addListener === 'function') {
      const onShow = (info) => {
        _kbHeight.value = (info && info.keyboardHeight) || 0
        _aggressiveReposition()
      }
      const onHide = () => { _kbHeight.value = 0; _aggressiveReposition() }
      // willShow fires before keyboard animates in; useful for pre-positioning.
      _kbListeners.push(await Keyboard.addListener('keyboardWillShow', onShow))
      _kbListeners.push(await Keyboard.addListener('keyboardDidShow',  onShow))
      _kbListeners.push(await Keyboard.addListener('keyboardWillHide', onHide))
      _kbListeners.push(await Keyboard.addListener('keyboardDidHide',  onHide))
    }
  } catch { /* not in Capacitor or plugin unavailable — silently fall back */ }

  // visualViewport — universal browser API. Fires on keyboard open (mobile
  // browsers) and on rotation / browser-toolbar autohide.
  if (typeof window !== 'undefined' && window.visualViewport) {
    const vv = window.visualViewport
    const r = () => _scheduleReposition()
    vv.addEventListener('resize', r)
    vv.addEventListener('scroll', r)
    _vvListeners.push(['resize', r], ['scroll', r])
  }
}

function _removeKeyboardListeners() {
  for (const h of _kbListeners) { try { h && h.remove && h.remove() } catch {} }
  _kbListeners = []
  if (typeof window !== 'undefined' && window.visualViewport) {
    const vv = window.visualViewport
    for (const [evt, fn] of _vvListeners) { try { vv.removeEventListener(evt, fn) } catch {} }
  }
  _vvListeners = []
}

onMounted(() => {
  document.addEventListener('click', onDocClick, { capture: true })
  document.addEventListener('touchstart', onDocClick, { capture: true, passive: true })
  window.addEventListener('scroll', onScroll, { capture: true, passive: true })
  window.addEventListener('resize', _scheduleReposition, { passive: true })
  window.addEventListener('orientationchange', _scheduleReposition, { passive: true })
  _installKeyboardListeners()
})
onUnmounted(() => {
  document.removeEventListener('click', onDocClick, { capture: true })
  document.removeEventListener('touchstart', onDocClick, { capture: true })
  window.removeEventListener('scroll', onScroll, { capture: true })
  window.removeEventListener('resize', _scheduleReposition)
  window.removeEventListener('orientationchange', _scheduleReposition)
  _removeKeyboardListeners()
})
</script>

<style scoped>
/* ── Root wrapper ─────────────────────────────────────────────────────────── */
.ss-root {
  position: relative;
  width: 100%;
  font-family: inherit;
}
.ss-root--disabled { opacity: .55; pointer-events: none; }

/* ── Trigger button ───────────────────────────────────────────────────────── */
.ss-trigger {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  min-height: 48px;
  padding: 0 14px;
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  background: #ffffff;
  font-size: 14px;
  font-family: inherit;
  color: #1e293b;
  text-align: left;
  cursor: pointer;
  gap: 10px;
  box-sizing: border-box;
  transition: border-color .15s ease, box-shadow .15s ease, background .1s ease;
  -webkit-tap-highlight-color: transparent;
}
.ss-trigger:focus-visible {
  outline: none;
  border-color: #1565C0;
  box-shadow: 0 0 0 3px rgba(21,101,192,.12);
}
.ss-trigger:hover:not(:disabled) {
  border-color: #94a3b8;
  background: #fafcff;
}
.ss-root--open .ss-trigger {
  border-color: #1565C0;
  box-shadow: 0 0 0 3px rgba(21,101,192,.12);
  background: #fafcff;
}
.ss-trigger--placeholder .ss-trigger-text { color: #94a3b8; }

.ss-trigger-text {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 14px;
  line-height: 1.4;
}

.ss-chevron {
  flex-shrink: 0;
  width: 11px;
  height: 7px;
  color: #64748b;
  transition: transform .2s ease, color .15s;
}
.ss-root--open .ss-chevron { color: #1565C0; }
.ss-chevron--up { transform: rotate(180deg); }

/* ── Panel — rendered via Teleport at body level ──────────────────────────── */
/* Non-scoped so Teleport'd element can receive the styles */
</style>

<!-- Panel styles are intentionally NOT scoped — the panel is teleported to <body> -->
<style>
.ss-panel {
  background: #ffffff;
  border-radius: 14px;
  box-shadow:
    0 0 0 1px rgba(15,23,42,.06),
    0 4px 6px -2px rgba(15,23,42,.06),
    0 16px 32px -4px rgba(15,23,42,.14),
    0 2px 4px rgba(15,23,42,.04);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  /* 2026-05-19 — Force a fresh stacking context so the panel always paints
     above any Ionic overlay/modal that might otherwise enclose it. `isolation`
     is honoured by every modern WebView (iOS Safari ≥16, Android Chromium
     ≥87). Combined with the inline `z-index: 2147483647` from calcPanelStyle,
     this defeats Ionic's --z-index-overlay (2000), --z-index-loading (30000),
     and even --z-index-toast (60000) variables. */
  isolation: isolate;
  pointer-events: auto;
  /* Don't let any ancestor's `contain: layout` clip us — the panel is
     teleported to <body> so contain wouldn't apply, but guard anyway. */
  contain: none;
}

/* Mobile: pair the panel with a subtle backdrop so it reads as a centred
   modal rather than a free-floating popover. The backdrop also blocks
   accidental taps on form fields behind the panel — eliminating the
   "tap dismissed my dropdown" foot-gun on Android. */
.ss-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(15, 23, 42, 0.32);
  z-index: 2147483646;     /* one less than the panel */
  isolation: isolate;
  -webkit-tap-highlight-color: transparent;
  pointer-events: auto;
}

/* ── Fade/scale transition ───────────────────────────────────────────────── */
.ss-fade-enter-active {
  transition: opacity .14s ease, transform .14s ease;
}
.ss-fade-leave-active {
  transition: opacity .1s ease, transform .1s ease;
}
.ss-fade-enter-from {
  opacity: 0;
  transform: translateY(-6px) scale(.98);
}
.ss-fade-leave-to {
  opacity: 0;
  transform: translateY(-4px) scale(.98);
}

/* ── Search bar ──────────────────────────────────────────────────────────── */
.ss-search-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border-bottom: 1px solid #f1f5f9;
  background: #f8fafc;
  flex-shrink: 0;
}
.ss-search-icon {
  flex-shrink: 0;
  width: 15px;
  height: 15px;
  color: #94a3b8;
}
.ss-search-input {
  flex: 1;
  min-width: 0;
  border: none;
  background: transparent;
  font-size: 14px;
  font-family: inherit;
  color: #1e293b;
  outline: none;
  padding: 2px 0;
  caret-color: #1565C0;
}
.ss-search-input::placeholder { color: #94a3b8; }
.ss-clear-btn {
  flex-shrink: 0;
  width: 20px;
  height: 20px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: #e2e8f0;
  border-radius: 50%;
  cursor: pointer;
  color: #64748b;
  padding: 0;
  transition: background .12s, color .12s;
}
.ss-clear-btn:hover { background: #cbd5e1; color: #334155; }

/* ── Result count ─────────────────────────────────────────────────────────── */
.ss-count {
  padding: 5px 14px 3px;
  font-size: 11px;
  font-weight: 500;
  color: #94a3b8;
  letter-spacing: .03em;
  text-transform: uppercase;
  background: #f8fafc;
  flex-shrink: 0;
  border-bottom: 1px solid #f1f5f9;
}

/* ── Options list ────────────────────────────────────────────────────────── */
.ss-list {
  overflow-y: auto;
  flex: 1;
  overscroll-behavior: contain;
  padding: 4px 0;
  -webkit-overflow-scrolling: touch;
}

/* ── Individual option ───────────────────────────────────────────────────── */
.ss-opt {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 11px 16px;
  min-height: 46px;
  font-size: 14px;
  color: #1e293b;
  cursor: pointer;
  box-sizing: border-box;
  transition: background .08s ease;
  -webkit-tap-highlight-color: transparent;
}
.ss-opt:hover { background: #f1f5f9; }
.ss-opt--focused { background: #eff6ff; }

.ss-opt--selected {
  background: #eff6ff;
  color: #1565C0;
  font-weight: 600;
}
.ss-opt--selected:hover { background: #dbeafe; }

.ss-opt--empty {
  color: #94a3b8;
  font-style: italic;
  font-weight: 400;
}
.ss-opt--active.ss-opt--empty {
  color: #64748b;
  background: #f8fafc;
}

.ss-opt-text {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ss-check-icon {
  flex-shrink: 0;
  width: 14px;
  height: 14px;
  color: #1565C0;
}

/* ── Separator ───────────────────────────────────────────────────────────── */
.ss-separator {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 16px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: #94a3b8;
  background: #f8fafc;
  user-select: none;
}
.ss-separator::before,
.ss-separator::after {
  content: '';
  flex: 1;
  height: 1px;
  background: #e2e8f0;
}
.ss-separator span { white-space: nowrap; }

/* ── Empty state ─────────────────────────────────────────────────────────── */
.ss-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 28px 20px;
  color: #94a3b8;
  font-size: 13px;
  text-align: center;
}
.ss-empty-state svg { width: 36px; height: 36px; opacity: .6; }
.ss-empty-state strong { color: #64748b; }
</style>
