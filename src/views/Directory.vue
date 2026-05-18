<template>
  <IonPage>
    <IonHeader class="dr-hdr" translucent>
      <div class="dr-hdr-bg">
        <div class="dr-hdr-top">
          <IonButtons slot="start"><IonMenuButton menu="app-menu" class="dr-menu"/></IonButtons>
          <div class="dr-hdr-title">
            <span class="dr-eye">COORDINATION</span>
            <span class="dr-h1">Staff Directory</span>
          </div>
          <IonButtons slot="end"><IonButton fill="clear" @click="replay" aria-label="Replay tour"><IonIcon :icon="helpCircleOutline"/></IonButton></IonButtons>
        </div>
        <div class="dr-search" id="tour-anchor-directory-search">
          <IonIcon :icon="searchOutline" class="dr-search-ico" aria-hidden="true"/>
          <input
            v-model="q" type="search" placeholder="Search name, district, POE, role…"
            class="dr-search-input" aria-label="Search directory" @input="debouncedSearch"/>
        </div>
        <div class="dr-chips">
          <button v-for="r in ROLE_CHIPS" :key="r.value"
            :class="['dr-chip', roleFilter===r.value && 'dr-chip--on']"
            type="button" @click="selectRole(r.value)">
            {{ r.label }}
          </button>
        </div>
      </div>
    </IonHeader>

    <IonContent class="dr-content" :fullscreen="true">
      <IonRefresher slot="fixed" @ionRefresh="onRefresh($event)"><IonRefresherContent/></IonRefresher>

      <div class="dr-body">
        <div v-if="loading && items.length === 0" class="dr-empty">
          <div class="dr-sk" v-for="i in 6" :key="i">
            <div class="dr-sk-av"/>
            <div class="dr-sk-lines"><div/><div/></div>
          </div>
        </div>

        <div v-else-if="items.length === 0" class="dr-empty dr-empty--none">
          <IonIcon :icon="peopleOutline" class="dr-empty-ico"/>
          <div class="dr-empty-t">No contacts</div>
          <div class="dr-empty-d">Try a different filter or search term.</div>
        </div>

        <div v-else class="dr-list">
          <button v-for="u in items" :key="u.id" type="button"
            :class="['dr-card', 'dr-card--' + roleClass(u.role_key)]"
            @click="open(u)">
            <div class="dr-av" aria-hidden="true">{{ initials(u.full_name || u.name || u.username) }}</div>
            <div class="dr-meta">
              <div class="dr-row1">
                <span class="dr-name">{{ u.full_name || u.name || u.username || '—' }}</span>
                <span class="dr-role">{{ ROLE_LABELS[u.role_key] || u.role_key || '—' }}</span>
              </div>
              <div class="dr-row2">
                <span class="dr-scope">{{ scopeLabel(u) }}</span>
                <span v-if="u.is_active === 0 || u.is_active === false" class="dr-inactive">Inactive</span>
              </div>
              <div class="dr-row3">
                <span v-if="u.phone" class="dr-phone">{{ formatPhone(u.phone) }}</span>
                <span v-if="u.email" class="dr-email">{{ u.email }}</span>
              </div>
            </div>
            <div class="dr-actions">
              <a v-if="u.phone" :href="'tel:' + sanitizePhone(u.phone)" class="dr-act dr-act--call" :aria-label="'Call ' + (u.full_name || u.username)" @click.stop>
                <IonIcon :icon="callOutline"/>
              </a>
              <a v-if="u.email" :href="'mailto:' + u.email" class="dr-act dr-act--mail" :aria-label="'Email ' + (u.full_name || u.username)" @click.stop>
                <IonIcon :icon="mailOutline"/>
              </a>
            </div>
          </button>
        </div>

        <div v-if="hasMore && !loading" class="dr-more">
          <button type="button" class="dr-more-btn" @click="loadMore">Load more</button>
        </div>
        <div v-if="loading && items.length > 0" class="dr-loading">Loading…</div>
        <div style="height:32px"/>
      </div>
    </IonContent>

    <IonModal :is-open="!!detail" :breakpoints="[0,1]" :initial-breakpoint="1" @ionModalDidDismiss="detail=null">
      <IonHeader :translucent="false">
        <IonToolbar style="--background:linear-gradient(180deg,#0B2545,#13315C);--color:#fff;--border-width:0;">
          <IonButtons slot="end"><IonButton fill="clear" style="--color:#fff;" @click="detail=null"><IonIcon :icon="closeOutline"/></IonButton></IonButtons>
        </IonToolbar>
      </IonHeader>
      <IonContent style="--background:#F0F4FA;">
        <div v-if="detail" class="dr-detail">
          <div class="dr-detail-hero">
            <div class="dr-av dr-av--big" aria-hidden="true">{{ initials(detail.full_name || detail.name || detail.username) }}</div>
            <div class="dr-detail-name">{{ detail.full_name || detail.name || detail.username }}</div>
            <div class="dr-detail-role">{{ ROLE_LABELS[detail.role_key] || detail.role_key }}</div>
          </div>
          <div class="dr-detail-cards">
            <div class="dr-dc">
              <div class="dr-dc-h">Scope</div>
              <div class="dr-dc-r"><span>Country</span><span>{{ detail.country_code || '—' }}</span></div>
              <div class="dr-dc-r"><span>Province</span><span>{{ detail.province_code || '—' }}</span></div>
              <div class="dr-dc-r"><span>PHEOC</span><span>{{ detail.pheoc_code || '—' }}</span></div>
              <div class="dr-dc-r"><span>District</span><span>{{ detail.district_code || '—' }}</span></div>
              <div class="dr-dc-r"><span>POE</span><span>{{ detail.poe_code || '—' }}</span></div>
            </div>
            <div class="dr-dc">
              <div class="dr-dc-h">Contact</div>
              <div class="dr-dc-r"><span>Phone</span><span>{{ detail.phone || '—' }}</span></div>
              <div class="dr-dc-r"><span>Email</span><span>{{ detail.email || '—' }}</span></div>
              <div class="dr-dc-r"><span>Username</span><span class="dr-dc-mono">{{ detail.username || '—' }}</span></div>
              <div class="dr-dc-r"><span>Status</span><span>{{ detail.is_active ? 'Active' : 'Inactive' }}</span></div>
            </div>
          </div>
          <div class="dr-detail-actions">
            <a v-if="detail.phone" :href="'tel:' + sanitizePhone(detail.phone)" class="dr-detail-btn dr-detail-btn--call">
              <IonIcon :icon="callOutline"/><span>Call</span>
            </a>
            <a v-if="detail.email" :href="'mailto:' + detail.email" class="dr-detail-btn dr-detail-btn--mail">
              <IonIcon :icon="mailOutline"/><span>Email</span>
            </a>
            <button v-if="detail.phone" type="button" class="dr-detail-btn dr-detail-btn--copy" @click="copyNumber">
              <IonIcon :icon="copyOutline"/><span>Copy number</span>
            </button>
          </div>
        </div>
      </IonContent>
    </IonModal>

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="1800" position="top" @didDismiss="toast.show=false"/>
  </IonPage>
</template>

<script setup>
/**
 * Directory.vue — premium read-only staff directory.
 *
 * ZERO backend changes: consumes the existing GET /users endpoint with role
 * filtering and search. `tel:` and `mailto:` use native platform intents.
 *
 * Respects the capability toggle `cap.directory.enabled`; if OFF, the sidebar
 * entry is hidden (gated in App.vue menu). Navigating here directly still
 * works because no network or native API is blocked by the toggle.
 */
import { ref, reactive, onMounted, onUnmounted } from 'vue'
import { IonPage, IonHeader, IonContent, IonButtons, IonMenuButton, IonButton, IonIcon, IonModal, IonToolbar, IonToast, IonRefresher, IonRefresherContent } from '@ionic/vue'
import { searchOutline, peopleOutline, callOutline, mailOutline, closeOutline, copyOutline, helpCircleOutline } from 'ionicons/icons'
import { coachmark, runSteps } from '@/services/tour'
import { hapticLight } from '@/services/haptics'

const ROLE_LABELS = {
  NATIONAL_ADMIN: 'National Admin',
  PHEOC_OFFICER: 'PHEOC Officer',
  DISTRICT_SUPERVISOR: 'District Supervisor',
  POE_ADMIN: 'POE Admin',
  POE_OFFICER: 'POE Officer',
  DATA_OFFICER: 'Data Officer',
}
const ROLE_CHIPS = [
  { value: null, label: 'Everyone' },
  { value: 'NATIONAL_ADMIN', label: 'National' },
  { value: 'PHEOC_OFFICER', label: 'PHEOC' },
  { value: 'DISTRICT_SUPERVISOR', label: 'District' },
  { value: 'POE_ADMIN', label: 'POE Admin' },
  { value: 'POE_OFFICER', label: 'POE Officer' },
]

const items = ref([])
const loading = ref(false)
const q = ref('')
const roleFilter = ref(null)
const page = ref(1)
const perPage = 30
const hasMore = ref(false)
const detail = ref(null)
const toast = reactive({ show: false, msg: '', color: 'success' })

let searchTimer = null
let activeAbort = null // AbortController for the in-flight /users fetch

async function load(reset = false) {
  if (reset) { page.value = 1; items.value = []; hasMore.value = false }
  loading.value = true
  // Cancel any previous in-flight request — prevents stale results
  // overwriting the newest user input (classic debounce race).
  if (activeAbort) { try { activeAbort.abort() } catch {} }
  activeAbort = new AbortController()
  const mySignal = activeAbort.signal
  try {
    const base = (window.SERVER_URL || '').replace(/\/$/, '')
    const params = new URLSearchParams({ per_page: String(perPage), page: String(page.value) })
    // Pass caller id so the server can apply role-based scope (NATIONAL sees
    // all; PHEOC → province; DISTRICT → district; POE_* → own POE). Absent
    // this, /users returns every user in the country.
    const auth = (() => { try { return JSON.parse(sessionStorage.getItem('AUTH_DATA') || 'null') } catch { return null } })()
    if (auth && auth.id) params.set('user_id', String(auth.id))
    if (roleFilter.value) params.set('role_key', roleFilter.value)
    if (q.value.trim()) params.set('search', q.value.trim())
    const res = await fetch(`${base}/users?` + params.toString(), { headers: { Accept: 'application/json' }, signal: mySignal })
    if (!res.ok) throw new Error('HTTP ' + res.status)
    const body = await res.json()
    const list = body?.data?.items ?? body?.data ?? []
    const total = body?.data?.total ?? list.length
    // Guard: if another request aborted this one, do not mutate state.
    if (mySignal.aborted) return
    items.value = reset ? list : [...items.value, ...list]
    hasMore.value = items.value.length < total
  } catch (err) {
    if (err?.name === 'AbortError') return // expected — newer request took over

    toast.msg = 'Could not load directory: ' + (err?.message || 'unknown')
    toast.color = 'danger'
    toast.show = true
  } finally {
    loading.value = false
  }
}

function debouncedSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => load(true), 280)
}

function selectRole(r) {
  if (roleFilter.value === r) return
  roleFilter.value = r
  try { hapticLight() } catch {}
  load(true)
}

async function loadMore() {
  page.value++
  await load(false)
}

async function onRefresh(ev) {
  await load(true)
  try { ev?.target?.complete?.() } catch {}
}

function open(u) {
  detail.value = u
  try { hapticLight() } catch {}
}

function initials(name) {
  const s = String(name || '').trim()
  if (!s) return '?'
  const parts = s.split(/\s+/).slice(0, 2)
  return parts.map(p => p.charAt(0).toUpperCase()).join('') || '?'
}

function scopeLabel(u) {
  return [u.poe_code, u.district_code, u.pheoc_code, u.province_code]
    .filter(Boolean).slice(0, 2).join(' · ') || u.country_code || '—'
}

function sanitizePhone(p) { return String(p || '').replace(/[^+0-9]/g, '') }
function formatPhone(p) {
  const s = sanitizePhone(p)
  if (!s) return ''
  if (s.startsWith('+250') && s.length === 13) return `+250 ${s.slice(4,6)} ${s.slice(6,9)} ${s.slice(9)}`
  return s
}

function roleClass(r) {
  if (r === 'NATIONAL_ADMIN') return 'natl'
  if (r === 'PHEOC_OFFICER') return 'pheoc'
  if (r === 'DISTRICT_SUPERVISOR') return 'dist'
  if (r === 'POE_ADMIN') return 'poea'
  if (r === 'POE_OFFICER') return 'poeo'
  return 'gen'
}

async function copyNumber() {
  try {
    await navigator.clipboard.writeText(sanitizePhone(detail.value?.phone || ''))
    toast.msg = 'Number copied'; toast.color = 'success'; toast.show = true
  } catch { toast.msg = 'Copy failed'; toast.color = 'warning'; toast.show = true }
}

function replay() {
  runSteps([{
    elementId: 'tour-anchor-directory-search',
    title: 'Search anyone',
    body: 'Type a name, district, POE code, or role. Tap a card to view details, or tap the phone/mail icon to contact directly.',
    icon: 'call', ctaLabel: 'Got it',
  }])
}

onMounted(() => {
  load(true)
  coachmark('directory.first-open', {
    elementId: 'tour-anchor-directory-search',
    title: 'Staff directory',
    body: 'Phone icons use your device dialler — nothing calls silently. The list respects your role scope.',
    icon: 'call', ctaLabel: 'Got it',
  })
})
onUnmounted(() => {
  clearTimeout(searchTimer)
  if (activeAbort) { try { activeAbort.abort() } catch {} }
})
</script>

<style scoped>
*{box-sizing:border-box}
.dr-hdr{--background:transparent;border:none}
.dr-hdr-bg{background:linear-gradient(135deg,#0B2545,#13315C,#003F88);padding:8px 0 12px}
.dr-hdr-top{display:flex;align-items:center;gap:4px;padding:0 8px}
.dr-menu{--color:rgba(255,255,255,.7)}
.dr-hdr-title{flex:1;display:flex;flex-direction:column;min-width:0}
.dr-eye{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:rgba(255,255,255,.45)}
.dr-h1{font-size:17px;font-weight:800;color:#fff}
.dr-search{display:flex;align-items:center;gap:8px;padding:6px 12px 2px;margin:6px 8px 0;background:rgba(255,255,255,.1);border-radius:10px}
.dr-search-ico{color:rgba(255,255,255,.6);font-size:16px}
.dr-search-input{flex:1;background:transparent;color:#fff;border:none;outline:none;font-size:13px;height:34px}
.dr-search-input::placeholder{color:rgba(255,255,255,.45)}
.dr-chips{display:flex;gap:6px;overflow-x:auto;padding:8px 10px 0;scrollbar-width:none}
.dr-chips::-webkit-scrollbar{display:none}
.dr-chip{flex-shrink:0;font-size:11px;font-weight:700;color:rgba(255,255,255,.75);background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:5px 12px;cursor:pointer;transition:all .15s}
.dr-chip--on{background:#00B4A6;color:#fff;border-color:#00B4A6;box-shadow:0 2px 6px rgba(0,180,166,.3)}

.dr-content{--background:#F0F4FA}
.dr-body{padding:10px 12px 0;max-width:560px;margin:0 auto}

.dr-list{display:flex;flex-direction:column;gap:8px}
.dr-card{
  display:flex;gap:12px;align-items:flex-start;text-align:left;
  background:#fff;border:1px solid #E6ECF5;border-left:4px solid #94A3B8;
  border-radius:12px;padding:12px;cursor:pointer;
  box-shadow:0 1px 2px rgba(0,0,0,.03);transition:transform .08s, box-shadow .15s;
  width:100%;
}
.dr-card:active{transform:translateY(1px);box-shadow:0 2px 8px rgba(0,0,0,.06)}
.dr-card--natl{border-left-color:#7C3AED}
.dr-card--pheoc{border-left-color:#0891B2}
.dr-card--dist{border-left-color:#059669}
.dr-card--poea{border-left-color:#D97706}
.dr-card--poeo{border-left-color:#2563EB}

.dr-av{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#0B2545,#13315C);color:#fff;font-size:14px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.dr-av--big{width:72px;height:72px;font-size:22px;margin:0 auto 12px}
.dr-meta{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.dr-row1{display:flex;gap:8px;align-items:baseline;justify-content:space-between}
.dr-name{font-size:14px;font-weight:800;color:#0B2545;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.dr-role{font-size:10px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.6px;flex-shrink:0}
.dr-row2{display:flex;gap:8px;align-items:center}
.dr-scope{font-size:11px;color:#475569;font-weight:600}
.dr-inactive{font-size:9px;font-weight:800;color:#C62828;background:#FEE2E2;padding:1px 6px;border-radius:10px;letter-spacing:.5px}
.dr-row3{display:flex;gap:10px;font-size:11px;color:#64748B;margin-top:2px;flex-wrap:wrap}
.dr-phone{font-family:monospace;color:#059669;font-weight:700}
.dr-email{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:180px}

.dr-actions{display:flex;gap:6px;flex-shrink:0;align-items:center}
.dr-act{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;text-decoration:none;font-size:18px;transition:transform .1s}
.dr-act:active{transform:scale(.92)}
.dr-act--call{background:#D1FAE5;color:#059669}
.dr-act--mail{background:#DBEAFE;color:#2563EB}

.dr-empty{padding:32px 16px;text-align:center;display:flex;flex-direction:column;gap:12px}
.dr-empty--none{color:#64748B}
.dr-empty-ico{font-size:56px;color:#CBD5E1;margin:0 auto}
.dr-empty-t{font-size:15px;font-weight:700;color:#334155}
.dr-empty-d{font-size:12px}

.dr-sk{display:flex;gap:12px;background:#fff;border:1px solid #E6ECF5;border-radius:12px;padding:12px;align-items:center}
.dr-sk-av{width:40px;height:40px;border-radius:50%;background:#E6ECF5;animation:drpulse 1.4s ease-in-out infinite}
.dr-sk-lines{flex:1;display:flex;flex-direction:column;gap:6px}
.dr-sk-lines div{height:10px;background:#E6ECF5;border-radius:6px;animation:drpulse 1.4s ease-in-out infinite}
.dr-sk-lines div:nth-child(2){width:60%}
@keyframes drpulse{0%,100%{opacity:.5}50%{opacity:1}}

.dr-more{padding:14px 0;text-align:center}
.dr-more-btn{background:#fff;border:1px solid #CBD5E1;border-radius:10px;padding:8px 20px;font-size:12px;font-weight:700;color:#0B2545;cursor:pointer}
.dr-loading{text-align:center;color:#64748B;padding:14px;font-size:12px}

.dr-detail{padding:18px}
.dr-detail-hero{text-align:center;padding:12px 0 24px}
.dr-detail-name{font-size:17px;font-weight:800;color:#0B2545;margin-top:8px}
.dr-detail-role{font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:.8px;margin-top:4px}
.dr-detail-cards{display:flex;flex-direction:column;gap:10px;margin-bottom:16px}
.dr-dc{background:#fff;border:1px solid #E6ECF5;border-radius:10px;overflow:hidden}
.dr-dc-h{padding:10px 14px;background:#F7FAFF;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:#0B2545;border-bottom:1px solid #E6ECF5}
.dr-dc-r{display:flex;justify-content:space-between;padding:10px 14px;border-top:1px solid #F0F4FA;font-size:13px}
.dr-dc-r:first-child{border-top:none}
.dr-dc-r span:first-child{color:#64748B;font-weight:600}
.dr-dc-r span:last-child{color:#0B2545;font-weight:700;text-align:right}
.dr-dc-mono{font-family:monospace;font-size:12px}
.dr-detail-actions{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px}
.dr-detail-btn{display:flex;align-items:center;justify-content:center;gap:6px;padding:12px;border-radius:12px;font-size:13px;font-weight:700;text-decoration:none;cursor:pointer;border:none}
.dr-detail-btn--call{background:#059669;color:#fff}
.dr-detail-btn--mail{background:#2563EB;color:#fff}
.dr-detail-btn--copy{background:#F1F5F9;color:#0B2545}
</style>
