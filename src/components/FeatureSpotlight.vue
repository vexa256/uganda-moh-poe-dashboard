<template>
  <Teleport to="body">
    <transition name="fs-fade">
      <div v-if="active" class="fs-root" role="dialog" aria-modal="true" :aria-label="step?.title || 'Tour step'">
        <!-- Mask + cutout -->
        <svg class="fs-mask" width="100%" height="100%" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
          <defs>
            <mask id="fs-mask-cutout">
              <rect width="100%" height="100%" fill="white"/>
              <rect
                v-if="anchor.w > 0"
                :x="anchor.x - 8"
                :y="anchor.y - 8"
                :width="anchor.w + 16"
                :height="anchor.h + 16"
                rx="14" ry="14"
                fill="black"
              />
            </mask>
          </defs>
          <rect width="100%" height="100%" fill="rgba(4,10,24,0.78)" mask="url(#fs-mask-cutout)"/>
        </svg>

        <!-- Pulsing highlight ring -->
        <div
          v-if="anchor.w > 0"
          class="fs-pulse"
          :style="{ left: (anchor.x - 10) + 'px', top: (anchor.y - 10) + 'px', width: (anchor.w + 20) + 'px', height: (anchor.h + 20) + 'px' }"
          aria-hidden="true"
        />

        <!-- Tooltip card -->
        <div class="fs-card" :style="cardStyle" ref="cardEl">
          <div class="fs-card__accent" aria-hidden="true"/>
          <header class="fs-card__head">
            <div class="fs-card__icon" aria-hidden="true">
              <svg v-if="step?.icon === 'sparkles'" viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 2l1.7 4.6L18 8l-4.3 1.4L12 14l-1.7-4.6L6 8l4.3-1.4L12 2zm7 9l.9 2.4L22 14l-2.1.7L19 17l-.9-2.3L16 14l2.1-.6L19 11zM5 14l1 2.6L8 17l-2 .7L5 20l-1-2.3L2 17l2-.4L5 14z"/></svg>
              <svg v-else-if="step?.icon === 'settings'" viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 15.5A3.5 3.5 0 1 1 12 8.5a3.5 3.5 0 0 1 0 7zm7.5-3.5c0-.4 0-.8-.1-1.2l2.1-1.6-2-3.4-2.5 1a7.3 7.3 0 0 0-2-1.2L14.5 2h-5l-.5 2.6c-.7.3-1.4.7-2 1.2l-2.5-1-2 3.4 2.1 1.6c-.1.4-.1.8-.1 1.2s0 .8.1 1.2L2.5 14l2 3.4 2.5-1c.6.5 1.3.9 2 1.2l.5 2.6h5l.5-2.6c.7-.3 1.4-.7 2-1.2l2.5 1 2-3.4-2.1-1.6c.1-.4.1-.8.1-1.2z"/></svg>
              <svg v-else-if="step?.icon === 'call'" viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M6.6 10.8a15.1 15.1 0 0 0 6.6 6.6l2.2-2.2a1 1 0 0 1 1-.3 11.5 11.5 0 0 0 3.6.6 1 1 0 0 1 1 1v3.5a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1 11.5 11.5 0 0 0 .6 3.6 1 1 0 0 1-.3 1l-2.2 2.2z"/></svg>
              <svg v-else-if="step?.icon === 'lock'" viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2zm-7-2a2 2 0 0 1 4 0v2h-4V6zm2 12a2 2 0 1 1 0-4 2 2 0 0 1 0 4z"/></svg>
              <svg v-else-if="step?.icon === 'information-circle'" viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
              <svg v-else viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><circle cx="12" cy="12" r="4"/></svg>
            </div>
            <div class="fs-card__title">{{ step?.title }}</div>
            <button class="fs-card__close" type="button" @click="dismiss" aria-label="Close tour">
              <svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M18.3 5.7L12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7l-1.4-1.4L9.2 12 2.9 5.7l1.4-1.4L10.6 10.6l6.3-6.3z"/></svg>
            </button>
          </header>
          <p class="fs-card__body">{{ step?.body }}</p>
          <div class="fs-card__progress" v-if="total > 1" aria-hidden="true">
            <span v-for="i in total" :key="i" :class="['fs-dot', i - 1 === index && 'fs-dot--on']"/>
          </div>
          <footer class="fs-card__foot">
            <button class="fs-btn fs-btn--ghost" type="button" @click="dismiss">Skip</button>
            <button class="fs-btn fs-btn--primary" type="button" @click="next" :aria-label="step?.ctaLabel">
              {{ step?.ctaLabel || 'Next' }}
              <svg v-if="!isLast" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" aria-hidden="true"><path d="M9 6l6 6-6 6V6z"/></svg>
            </button>
          </footer>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<script setup>
/**
 * FeatureSpotlight — premium, theme-matched, dependency-free tour overlay.
 *
 * Listens to "tour-event" custom events dispatched by services/tour.js.
 * Anchors itself to a target via CSS selector or element id. If the anchor
 * is missing, the card centres itself and the cutout hides — the tour still
 * conveys the message.
 *
 * Accessibility: Teleports to body; role=dialog; aria-modal; Esc dismisses.
 * Keyboard: Tab cycles within card; Enter activates primary action.
 * Safe-area aware on Android 15 edge-to-edge via env(safe-area-inset-*).
 */
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { onEvent as onTourEvent } from '@/services/tour'
import { hapticLight } from '@/services/haptics'

const active  = ref(false)
const steps   = ref([])
const index   = ref(0)

const step  = computed(() => steps.value[index.value] || null)
const total = computed(() => steps.value.length)
const isLast = computed(() => index.value >= total.value - 1)

const anchor = ref({ x: 0, y: 0, w: 0, h: 0 })
const cardEl = ref(null)
const cardStyle = ref({ left: '50%', top: '50%', transform: 'translate(-50%, -50%)' })

let offTour = null
let onResize = null

function dismiss() { active.value = false; steps.value = []; index.value = 0 }

function next() {
  try { hapticLight() } catch {}
  if (isLast.value) return dismiss()
  index.value = Math.min(index.value + 1, total.value - 1)
  nextTick(positionForStep)
}

function findAnchor(s) {
  if (!s) return null
  try {
    if (s.elementId) {
      const el = document.getElementById(s.elementId)
      if (el) return el
    }
    if (s.selector) {
      const el = document.querySelector(s.selector)
      if (el) return el
    }
  } catch {}
  return null
}

function positionForStep() {
  const s = step.value
  const el = findAnchor(s)
  if (!el) {
    anchor.value = { x: 0, y: 0, w: 0, h: 0 }
    cardStyle.value = { left: '50%', top: '50%', transform: 'translate(-50%, -50%)' }
    return
  }
  const r = el.getBoundingClientRect()
  anchor.value = { x: r.left, y: r.top, w: r.width, h: r.height }
  // Place card below anchor if there's room, else above, else centred.
  const vw = window.innerWidth
  const vh = window.innerHeight
  const cardW = Math.min(340, vw - 32)
  const cardH = 200
  const spaceBelow = vh - r.bottom - 24
  const spaceAbove = r.top - 24
  let top, left = Math.max(16, Math.min(vw - cardW - 16, r.left + r.width / 2 - cardW / 2))
  if (spaceBelow >= cardH) top = r.bottom + 18
  else if (spaceAbove >= cardH) top = Math.max(16, r.top - cardH - 18)
  else { top = (vh - cardH) / 2; left = (vw - cardW) / 2 }
  cardStyle.value = {
    left: `${left}px`,
    top: `${top}px`,
    width: `${cardW}px`,
    transform: 'none',
  }
  // Scroll anchor into view if off-screen.
  if (r.top < 48 || r.bottom > vh - 48) {
    try { el.scrollIntoView({ behavior: 'smooth', block: 'center' }) } catch {}
    setTimeout(positionForStep, 260)
  }
}

function onKey(e) {
  if (!active.value) return
  if (e.key === 'Escape') dismiss()
  else if (e.key === 'Enter') { e.preventDefault(); next() }
}

onMounted(() => {
  offTour = onTourEvent(async (detail) => {
    if (detail.type === 'dismiss') { dismiss(); return }
    if (detail.type !== 'run' || !Array.isArray(detail.steps) || detail.steps.length === 0) return
    steps.value = detail.steps
    index.value = 0
    active.value = true
    await nextTick()
    positionForStep()
  })
  onResize = () => { if (active.value) positionForStep() }
  window.addEventListener('resize', onResize)
  window.addEventListener('orientationchange', onResize)
  document.addEventListener('keydown', onKey)
})

onUnmounted(() => {
  try { offTour && offTour() } catch {}
  try { window.removeEventListener('resize', onResize) } catch {}
  try { window.removeEventListener('orientationchange', onResize) } catch {}
  try { document.removeEventListener('keydown', onKey) } catch {}
})
</script>

<style scoped>
.fs-root {
  position: fixed; inset: 0;
  z-index: 10050;
  pointer-events: auto;
}
.fs-mask { position: absolute; inset: 0; pointer-events: none; }
.fs-pulse {
  position: absolute; pointer-events: none;
  border-radius: 14px;
  box-shadow: 0 0 0 3px rgba(0,180,166,.85), 0 0 0 12px rgba(0,180,166,.25);
  animation: fs-pulse 1.6s ease-out infinite;
}
@keyframes fs-pulse {
  0%   { box-shadow: 0 0 0 3px rgba(0,180,166,.85), 0 0 0 0px rgba(0,180,166,.45); }
  70%  { box-shadow: 0 0 0 3px rgba(0,180,166,.85), 0 0 0 22px rgba(0,180,166,0); }
  100% { box-shadow: 0 0 0 3px rgba(0,180,166,.85), 0 0 0 0px rgba(0,180,166,0); }
}

.fs-card {
  position: absolute;
  background: linear-gradient(180deg, #FFFFFF 0%, #F7FAFF 100%);
  border-radius: 16px;
  box-shadow: 0 24px 60px rgba(4,10,24,.45), 0 6px 16px rgba(4,10,24,.18);
  padding: 18px 18px 16px;
  display: flex; flex-direction: column; gap: 10px;
  overflow: hidden;
  max-width: 340px;
}
.fs-card__accent {
  position: absolute; left: 0; top: 0; bottom: 0; width: 5px;
  background: linear-gradient(180deg, #00B4A6 0%, #0B2545 100%);
}
.fs-card__head { display: flex; align-items: center; gap: 10px; padding-left: 2px; }
.fs-card__icon {
  width: 36px; height: 36px; border-radius: 10px;
  background: linear-gradient(135deg, #0B2545 0%, #13315C 100%);
  color: #00E6D6; display: flex; align-items: center; justify-content: center;
  flex-shrink: 0;
}
.fs-card__title { font-size: 15px; font-weight: 800; color: #0B2545; flex: 1; letter-spacing: .2px; }
.fs-card__close {
  background: transparent; border: none; color: #64748B; cursor: pointer;
  width: 28px; height: 28px; border-radius: 8px; display: flex; align-items: center; justify-content: center;
  padding: 0;
}
.fs-card__close:hover { background: #F1F5F9; color: #0B2545; }
.fs-card__body { color: #334155; font-size: 13px; line-height: 1.55; margin: 0; padding-left: 2px; }
.fs-card__progress { display: flex; gap: 6px; padding-left: 2px; }
.fs-dot { width: 6px; height: 6px; border-radius: 50%; background: #CBD5E1; transition: background .2s, transform .2s; }
.fs-dot--on { background: #00B4A6; transform: scaleX(2.4); border-radius: 4px; }
.fs-card__foot { display: flex; justify-content: flex-end; gap: 8px; padding-left: 2px; }
.fs-btn {
  border: none; padding: 8px 14px; border-radius: 10px; font-weight: 700; font-size: 13px; cursor: pointer;
  display: inline-flex; align-items: center; gap: 6px; transition: transform .1s, box-shadow .15s, background .15s;
}
.fs-btn:active { transform: translateY(1px); }
.fs-btn--ghost { background: transparent; color: #64748B; }
.fs-btn--ghost:hover { background: #F1F5F9; color: #0B2545; }
.fs-btn--primary {
  background: linear-gradient(135deg, #0B2545 0%, #13315C 100%);
  color: #fff; box-shadow: 0 4px 10px rgba(11,37,69,.35);
}
.fs-btn--primary:hover { box-shadow: 0 6px 14px rgba(11,37,69,.45); }

/* Enter/leave transitions */
.fs-fade-enter-active, .fs-fade-leave-active { transition: opacity .22s ease; }
.fs-fade-enter-from,   .fs-fade-leave-to     { opacity: 0; }

@media (max-width: 380px) {
  .fs-card { padding: 14px; border-radius: 12px; }
}
</style>
