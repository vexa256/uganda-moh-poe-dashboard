/**
 * src/composables/useReportEngine.js — shared engine for all report views.
 *
 * Provides one battle-tested implementation of:
 *   • period-cutoff helper (today / 7d / 30d / 90d / all)
 *   • locale-aware number formatting (1,234)
 *   • count-up animation hook (smoothNumber)
 *   • activeFilterCount(filter, defaults)
 *   • highDpiCanvas(canvasRef, w, h) — backs canvas with devicePixelRatio
 *     so the chart is crisp on retina screens AND PDF embeds at 2× scale
 *   • auto-refresh lifecycle helper
 *   • IDB-first data load with NATIONAL vs POE scoping
 */
import { ref, reactive, computed, onMounted, onUnmounted, watch } from 'vue'
import { onIonViewDidEnter, onIonViewWillLeave } from '@ionic/vue'

const _NF = new Intl.NumberFormat('en-GB')
export function fmtN(n) {
  if (n == null || isNaN(n)) return '—'
  return _NF.format(Number(n))
}

export function periodCutoff(p) {
  const now = Date.now()
  if (p === 'today') return new Date(new Date().toISOString().slice(0, 10)).getTime()
  if (p === '7d')  return now - 7 * 86400_000
  if (p === '30d') return now - 30 * 86400_000
  if (p === '90d') return now - 90 * 86400_000
  return 0
}

/**
 * Smooth count-up animation. Returns a ref<number> that animates toward
 * the latest target whenever you call .set(target).
 *
 *   const n = smoothNumber(0)
 *   watch(() => kpis.value.total, v => n.set(v))
 *   // <span>{{ fmtN(n.value) }}</span>
 */
export function smoothNumber(initial = 0, durationMs = 700) {
  const innerRef = ref(initial)
  let _from = initial, _to = initial, _start = 0, _frame = null
  function tick() {
    const elapsed = performance.now() - _start
    const t = Math.min(1, elapsed / durationMs)
    // ease-out cubic
    const eased = 1 - Math.pow(1 - t, 3)
    innerRef.value = Math.round(_from + (_to - _from) * eased)
    if (t < 1) _frame = requestAnimationFrame(tick)
    else _frame = null
  }
  function set(target) {
    if (target === _to && _frame == null) return
    _from = innerRef.value
    _to = Number(target) || 0
    _start = performance.now()
    if (!_frame) _frame = requestAnimationFrame(tick)
  }
  // Return a reactive proxy. Vue auto-unwraps nested refs in reactive objects,
  // so `kpiTotal.value` in the template evaluates to the live NUMBER instead
  // of the Ref object. (Plain `{ value: ref(0) }` returns the Ref itself,
  // which fmtN() treats as NaN and renders as '—'.)
  return reactive({ value: innerRef, set })
}

/**
 * Resize a canvas element so its drawing buffer is devicePixelRatio×
 * its CSS size. Returns a ready-to-use 2D context, calls ctx.scale(dpr).
 * Drop-in replacement for canvas.getContext('2d').
 */
export function highDpiCanvas(canvas, cssW, cssH) {
  if (!canvas) return null
  const dpr = (typeof window !== 'undefined' && window.devicePixelRatio) || 1
  // Set CSS size via attributes so layout matches
  canvas.style.width  = cssW + 'px'
  canvas.style.height = cssH + 'px'
  canvas.width  = Math.round(cssW * dpr)
  canvas.height = Math.round(cssH * dpr)
  const ctx = canvas.getContext('2d')
  ctx.setTransform(1, 0, 0, 1, 0, 0)
  ctx.scale(dpr, dpr)
  return { ctx, dpr, w: cssW, h: cssH }
}

/**
 * Count of filter keys that differ from their default. Used for the
 * "n active" pill on the filter bar.
 */
export function activeFilterCount(filter, defaults) {
  let n = 0
  for (const k of Object.keys(filter || {})) {
    if (String(filter[k] ?? '') !== String(defaults[k] ?? '')) n++
  }
  return n
}

/**
 * Auto-refresh helper. Wraps setInterval with visibility-gating + clean
 * Ionic page-leave teardown so the timer never fires on hidden tabs and
 * never leaks across navigation.
 */
export function useAutoRefresh(loadFn, intervalMs = 30_000) {
  let timer = null
  function start() {
    stop()
    timer = setInterval(() => {
      if (typeof document !== 'undefined' && document.visibilityState !== 'visible') return
      try { loadFn() } catch {}
    }, intervalMs)
  }
  function stop() { if (timer) { clearInterval(timer); timer = null } }
  onMounted(() => { try { loadFn() } catch {}; start() })
  onUnmounted(stop)
  onIonViewDidEnter(() => { try { loadFn() } catch {}; start() })
  onIonViewWillLeave(stop)
  return { start, stop }
}
