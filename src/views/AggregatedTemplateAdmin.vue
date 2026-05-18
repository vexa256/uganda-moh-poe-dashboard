<template>
  <IonPage>
    <IonHeader class="tp-hdr" translucent>
      <div class="tp-hdr-bg">
        <div class="tp-hdr-top">
          <IonButtons slot="start"><IonBackButton default-href="/aggregated-data/new" class="tp-back"/></IonButtons>
          <div class="tp-hdr-title">
            <span class="tp-hdr-eye">Admin · Country: {{ countryCode }}</span>
            <span class="tp-hdr-h1">Aggregated Templates</span>
          </div>
          <IonButtons slot="end">
            <button class="tp-ref" :disabled="loading" @click="loadAll"><IonIcon :icon="refreshOutline" slot="icon-only"/></button>
          </IonButtons>
        </div>
        <!-- Template tabs -->
        <div class="tp-tabs">
          <button v-for="t in templates" :key="t.id"
            :class="['tp-tab', activeId === t.id && 'tp-tab--on', t.is_active == 1 && 'tp-tab--live']"
            @click="selectTemplate(t.id)">
            <span class="tp-tab-t">{{ t.template_name }}</span>
            <span class="tp-tab-s">v{{ t.version }} · {{ t.columns_enabled }}/{{ t.columns_total }}</span>
            <span v-if="t.is_active == 1" class="tp-tab-live">LIVE</span>
            <span v-if="t.locked == 1" class="tp-tab-lock">🔒</span>
          </button>
          <button v-if="isAdmin" class="tp-tab tp-tab--add" @click="gotoWizard">+ New</button>
        </div>
      </div>
    </IonHeader>

    <IonContent class="tp-content" :fullscreen="true">
      <div v-if="!isAdmin" class="tp-ro">
        <IonIcon :icon="lockClosedOutline"/>
        <div><strong>Read-only.</strong> You are viewing the active template. Only NATIONAL_ADMIN may edit templates.</div>
      </div>

      <div v-if="!selected" class="tp-empty">
        <div class="tp-empty-t">No template loaded</div>
        <div class="tp-empty-s">Pick a template above or create a new one.</div>
      </div>

      <div v-else>
        <!-- Template meta -->
        <section class="tp-meta">
          <div class="tp-meta-row">
            <span class="tp-meta-k">Template</span>
            <input v-model="selected.template_name" :disabled="!isAdmin || selected.locked == 1"
              class="tp-inp" @blur="saveMeta"/>
          </div>
          <div class="tp-meta-row">
            <span class="tp-meta-k">Code</span>
            <span class="tp-meta-v tp-mono">{{ selected.template_code }}</span>
          </div>
          <div class="tp-meta-row">
            <span class="tp-meta-k">Description</span>
            <textarea v-model="selected.description" :disabled="!isAdmin || selected.locked == 1"
              class="tp-inp tp-inp--ta" rows="2" @blur="saveMeta"/>
          </div>
          <div class="tp-meta-row">
            <span class="tp-meta-k">State</span>
            <div class="tp-meta-ops">
              <span :class="['tp-pill', selected.status === 'PUBLISHED' && 'tp-pill--live', selected.status === 'RETIRED' && 'tp-pill--retired']">{{ selected.status || (selected.is_active == 1 ? 'PUBLISHED' : 'DRAFT') }}</span>
              <span :class="['tp-pill', selected.locked == 1 && 'tp-pill--locked']">{{ selected.locked == 1 ? 'LOCKED' : 'EDITABLE' }}</span>
              <span v-if="selected.reporting_frequency" class="tp-pill tp-pill--freq">{{ selected.reporting_frequency }}</span>
              <button v-if="isAdmin && selected.status !== 'PUBLISHED'" class="tp-btn tp-btn--activate" @click="publish">Publish</button>
              <button v-if="isAdmin && selected.status === 'PUBLISHED' && selected.is_default != 1" class="tp-btn tp-btn--ghost" @click="retire">Retire</button>
              <button v-if="isAdmin" class="tp-btn tp-btn--ghost" @click="toggleLock">
                {{ selected.locked == 1 ? 'Unlock' : 'Lock' }}
              </button>
              <button v-if="isAdmin && selected.is_default != 1" class="tp-btn tp-btn--danger" @click="deleteTpl">Delete</button>
            </div>
          </div>
        </section>

        <!-- LOCKED banner — gives the admin an obvious unlock path so they
             don't hit silent 409s from out-of-band lock state. -->
        <div v-if="selected.locked == 1" class="tp-lock-banner">
          <span class="tp-lock-ic">🔒</span>
          <div class="tp-lock-body">
            <span class="tp-lock-t">Template is locked</span>
            <span class="tp-lock-s">Locked by user #{{ selected.locked_by_user_id || '—' }}{{ selected.locked_at ? ' · ' + selected.locked_at : '' }}. Edits are blocked until you unlock it.</span>
          </div>
          <button v-if="isAdmin" class="tp-lock-btn" @click="toggleLock">Unlock</button>
        </div>

        <!-- Columns -->
        <div class="tp-sh-row">
          <h3 class="tp-sh">Columns ({{ enabledCount }} / {{ columns.length }} enabled)</h3>
          <button v-if="isAdmin && selected.locked != 1" class="tp-add-col" @click="openAddCol">+ Add column</button>
        </div>

        <div class="tp-cats">
          <button v-for="cat in categories" :key="cat"
            :class="['tp-cat', activeCat === cat && 'tp-cat--on']"
            @click="activeCat = cat">{{ cat }}</button>
        </div>

        <div class="tp-cols">
          <article v-for="col in visibleColumns" :key="col.id" :class="['tp-col', col.is_enabled == 1 ? 'tp-col--on' : 'tp-col--off', col.is_core == 1 && 'tp-col--core']">
            <header class="tp-col-h">
              <label class="tp-toggle" v-if="isAdmin && selected.locked != 1 && col.is_core != 1">
                <input type="checkbox" :checked="col.is_enabled == 1" @change="toggleEnabled(col, $event.target.checked)"/>
                <span class="tp-toggle-slider"/>
              </label>
              <span v-else-if="col.is_core == 1" class="tp-core-badge">CORE</span>
              <span v-else class="tp-off-badge" :class="col.is_enabled == 1 ? 'tp-off-badge--on' : ''">{{ col.is_enabled == 1 ? 'ON' : 'OFF' }}</span>
              <div class="tp-col-meta">
                <input v-model="col.column_label" :disabled="!isAdmin || selected.locked == 1"
                  class="tp-col-label" @blur="saveColumn(col)"/>
                <span class="tp-col-sub">
                  <span class="tp-col-k">{{ col.column_key }}</span>
                  <span class="tp-col-t">{{ col.data_type }}</span>
                  <span class="tp-col-c">{{ col.category }}</span>
                </span>
              </div>
              <span class="tp-col-order">#{{ col.display_order }}</span>
            </header>

            <div v-if="isAdmin && selected.locked != 1 && col.is_core != 1" class="tp-col-actions">
              <label class="tp-chk"><input type="checkbox" :checked="col.dashboard_visible == 1" @change="patchCol(col, 'dashboard_visible', $event.target.checked ? 1 : 0)"/> Dashboard</label>
              <label class="tp-chk"><input type="checkbox" :checked="col.report_visible == 1"    @change="patchCol(col, 'report_visible',    $event.target.checked ? 1 : 0)"/> Report</label>
              <select :value="col.aggregation_fn" class="tp-sel" @change="patchCol(col, 'aggregation_fn', $event.target.value)">
                <option v-for="fn in AGG_FNS" :key="fn" :value="fn">{{ fn }}</option>
              </select>
              <button class="tp-del" @click="deleteCol(col)">Delete</button>
            </div>
            <div v-else class="tp-col-meta-ro">
              <span class="tp-tag">{{ col.aggregation_fn }}</span>
              <span v-if="col.dashboard_visible == 1" class="tp-tag">Dashboard</span>
              <span v-if="col.report_visible == 1" class="tp-tag">Report</span>
            </div>
          </article>
        </div>
      </div>

      <div style="height:64px"/>
    </IonContent>

    <!-- Add column modal -->
    <IonModal :is-open="showAddCol" @didDismiss="showAddCol = false" :initial-breakpoint="0.85" :breakpoints="[0, 0.85]">
      <IonContent class="tp-modal">
        <div class="tp-mp">
          <h2 class="tp-mt">Add custom column</h2>
          <p class="tp-ms">This column will be added to the current template. Once data is submitted against it, you cannot change its key.</p>
          <label class="tp-flbl">Key (machine name)</label>
          <input v-model.trim="newCol.column_key" class="tp-inp" placeholder="e.g. suspected_anthrax"/>
          <label class="tp-flbl">Label</label>
          <input v-model.trim="newCol.column_label" class="tp-inp" placeholder="Suspected anthrax"/>
          <div class="tp-frow">
            <div class="tp-fhalf">
              <label class="tp-flbl">Category</label>
              <select v-model="newCol.category" class="tp-inp">
                <option v-for="c in NEW_CATEGORIES" :key="c">{{ c }}</option>
              </select>
            </div>
            <div class="tp-fhalf">
              <label class="tp-flbl">Data type</label>
              <select v-model="newCol.data_type" class="tp-inp">
                <option v-for="d in DATA_TYPES" :key="d">{{ d }}</option>
              </select>
            </div>
          </div>
          <div class="tp-frow">
            <div class="tp-fhalf">
              <label class="tp-flbl">Aggregation</label>
              <select v-model="newCol.aggregation_fn" class="tp-inp">
                <option v-for="fn in AGG_FNS" :key="fn">{{ fn }}</option>
              </select>
            </div>
            <div class="tp-fhalf">
              <label class="tp-flbl">Required?</label>
              <select v-model="newCol.is_required" class="tp-inp">
                <option :value="false">No</option>
                <option :value="true">Yes</option>
              </select>
            </div>
          </div>
          <label class="tp-flbl">Help text (shown under the field)</label>
          <textarea v-model.trim="newCol.help_text" class="tp-inp" rows="2"/>
          <div v-if="newColErr" class="tp-err">{{ newColErr }}</div>
          <div class="tp-mact">
            <button class="tp-btn tp-btn--ghost" @click="showAddCol = false">Cancel</button>
            <button class="tp-btn tp-btn--primary" :disabled="saving" @click="submitNewCol">{{ saving ? 'Adding…' : 'Add column' }}</button>
          </div>
        </div>
      </IonContent>
    </IonModal>

    <!-- Create-new was moved to the AI-assisted wizard at /admin/aggregated-wizard. -->

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="2800" position="top" @didDismiss="toast.show = false"/>
  </IonPage>
</template>

<script setup>
import {
  IonPage, IonHeader, IonContent, IonButtons, IonBackButton,
  IonIcon, IonModal, IonToast,
  onIonViewDidEnter,
} from '@ionic/vue'
import { refreshOutline, lockClosedOutline } from 'ionicons/icons'
import { ref, computed, reactive, onMounted } from 'vue'

const AGG_FNS = ['SUM', 'AVG', 'MIN', 'MAX', 'COUNT', 'LATEST', 'NONE']
const DATA_TYPES = ['INTEGER', 'DECIMAL', 'TEXT', 'BOOLEAN', 'DATE', 'PERCENT', 'SELECT']
const NEW_CATEGORIES = ['CORE', 'GENDER', 'AGE', 'SYMPTOMS', 'DISEASE', 'TRAVEL', 'VACCINE', 'LAB', 'CUSTOM']

function getAuth() { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {} }
const auth = ref(getAuth())
// Tenant country (window.COUNTRY_CODE / VITE_COUNTRY_CODE).
const TENANT_CC = (typeof window !== 'undefined' && window.COUNTRY_CODE) || 'UG'
const isAdmin = computed(() => (auth.value?.role_key || '') === 'NATIONAL_ADMIN')
const countryCode = computed(() => auth.value?.country_code || TENANT_CC)

// Router — used by "+ New" tab button, which routes to the AI-assisted wizard
// rather than opening an inline modal.
import { useRouter } from 'vue-router'
const router = useRouter()
function gotoWizard(ev) {
  // Blur first so the transitioning <ion-page> doesn't end up with focus
  // inside an aria-hidden ancestor.
  try { ev?.currentTarget?.blur?.() } catch (_) {}
  router.push('/admin/aggregated-wizard')
}

const templates = ref([])
const activeId  = ref(null)
const selected  = ref(null)
const columns   = ref([])
const categories = computed(() => ['ALL', ...new Set(columns.value.map(c => c.category))])
const activeCat = ref('ALL')
const visibleColumns = computed(() => activeCat.value === 'ALL' ? columns.value : columns.value.filter(c => c.category === activeCat.value))
const enabledCount = computed(() => columns.value.filter(c => c.is_enabled == 1).length)

const loading = ref(false)
const saving  = ref(false)
const toast   = reactive({ show: false, msg: '', color: 'success' })
const showAddCol = ref(false)

const newCol = reactive({
  column_key: '', column_label: '', category: 'CUSTOM', data_type: 'INTEGER',
  aggregation_fn: 'SUM', is_required: false, help_text: '',
})
const newColErr = ref('')

// ── API ─────────────────────────────────────────────────────────────────────
async function api(path, opts = {}) {
  const uid = auth.value?.id
  if (!uid) return null
  const sep = path.includes('?') ? '&' : '?'
  const url = `${window.SERVER_URL}${path}${sep}user_id=${uid}`
  try {
    const res = await fetch(url, {
      headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
      ...opts,
    })
    const body = await res.json().catch(() => null)
    return { ok: res.ok, status: res.status, body }
  } catch (e) { return { ok: false, status: 0, error: e?.message } }
}

async function loadAll() {
  loading.value = true
  try {
    const res = await api(`/aggregated-templates?country_code=${encodeURIComponent(countryCode.value)}`)
    if (res?.ok && res.body?.success) {
      templates.value = res.body.data || []
      if (templates.value.length > 0 && !activeId.value) {
        // Pick active template by default
        const live = templates.value.find(t => t.is_active == 1)
        await selectTemplate((live || templates.value[0]).id)
      }
    } else {
      showToast(res?.body?.message || 'Failed to load templates', 'danger')
    }
  } finally { loading.value = false }
}

async function selectTemplate(id) {
  activeId.value = id
  const res = await api(`/aggregated-templates/${id}`)
  if (res?.ok && res.body?.success) {
    selected.value = res.body.data.template
    columns.value  = res.body.data.columns || []
    activeCat.value = 'ALL'
  } else {
    showToast('Failed to load template', 'danger')
  }
}

async function saveMeta() {
  if (!isAdmin.value || selected.value?.locked == 1) return
  const res = await api(`/aggregated-templates/${selected.value.id}`, {
    method: 'PATCH',
    body: JSON.stringify({
      user_id: auth.value?.id,
      template_name: selected.value.template_name,
      description:   selected.value.description,
    }),
  })
  if (res?.ok && res.body?.success) showToast('Saved', 'success')
  else showToast(res?.body?.message || 'Save failed', 'danger')
}

async function publish() {
  if (!isAdmin.value) return
  const res = await api(`/aggregated-templates/${selected.value.id}/publish`, {
    method: 'POST',
    body: JSON.stringify({ user_id: auth.value?.id }),
  })
  if (res?.ok && res.body?.success) {
    await loadAll()
    await selectTemplate(selected.value.id)
    showToast('Published to all POEs', 'success')
  } else {
    showToast(res?.body?.message || 'Publish failed', 'danger')
  }
}

async function retire() {
  if (!isAdmin.value) return
  if (!confirm(`Retire "${selected.value.template_name}"? POE users will stop seeing it.\nHistorical submissions are preserved.`)) return
  const res = await api(`/aggregated-templates/${selected.value.id}/retire`, {
    method: 'POST',
    body: JSON.stringify({ user_id: auth.value?.id }),
  })
  if (res?.ok && res.body?.success) {
    await loadAll()
    await selectTemplate(selected.value.id)
    showToast('Template retired', 'success')
  } else {
    showToast(res?.body?.message || 'Retire failed', 'danger')
  }
}

async function deleteTpl() {
  if (!isAdmin.value) return
  const name = selected.value.template_name
  if (selected.value.is_default == 1) {
    showToast('The default template cannot be deleted.', 'danger')
    return
  }
  if (!confirm(`Delete "${name}" permanently?\nThe template disappears from every POE at the next sync. Historical submissions are preserved for audit.`)) return
  // First attempt (no cascade) — server tells us if submissions exist
  let res = await api(`/aggregated-templates/${selected.value.id}`, {
    method: 'DELETE',
    body: JSON.stringify({ user_id: auth.value?.id }),
  })
  if (res?.status === 409 && res.body?.error?.submissions_count) {
    const n = res.body.error.submissions_count
    if (!confirm(`"${name}" has ${n} submission${n === 1 ? '' : 's'} on record.\n\nThe submissions will be kept (template_id is preserved for audit) but the template itself will be permanently deleted.\n\nContinue?`)) return
    res = await api(`/aggregated-templates/${selected.value.id}?cascade=true`, {
      method: 'DELETE',
      body: JSON.stringify({ user_id: auth.value?.id, cascade: true, confirm: 'DELETE_WITH_SUBMISSIONS' }),
    })
  }
  if (res?.ok && res.body?.success) {
    showToast(res.body.message || 'Template deleted', 'success')
    selected.value = null
    columns.value = []
    activeId.value = null
    await loadAll()
  } else {
    showToast(res?.body?.message || 'Delete failed', 'danger')
  }
}

async function toggleLock() {
  if (!isAdmin.value) return
  const unlock = selected.value.locked == 1
  const res = await api(`/aggregated-templates/${selected.value.id}/lock`, {
    method: 'POST',
    body: JSON.stringify({ user_id: auth.value?.id, unlock }),
  })
  if (res?.ok && res.body?.success) {
    selected.value.locked = unlock ? 0 : 1
    if (unlock) {
      selected.value.locked_by_user_id = null
      selected.value.locked_at = null
    }
    showToast(unlock ? 'Template unlocked — edits allowed' : 'Template locked', 'success')
  } else {
    showToast(res?.body?.message || 'Lock toggle failed', 'danger')
  }
}

async function toggleEnabled(col, enabled) {
  col.is_enabled = enabled ? 1 : 0
  await patchCol(col, 'is_enabled', col.is_enabled)
}

async function patchCol(col, key, value) {
  if (!isAdmin.value) {
    showToast('Only NATIONAL_ADMIN can edit columns', 'warning')
    return
  }
  if (selected.value?.locked == 1) {
    showToast('Template is locked — unlock it first', 'warning')
    // Revert local UI state
    await selectTemplate(selected.value.id)
    return
  }
  const body = { user_id: auth.value?.id }
  body[key] = value
  const res = await api(`/aggregated-template-columns/${col.id}`, {
    method: 'PATCH',
    body: JSON.stringify(body),
  })
  if (!(res?.ok && res.body?.success)) {
    const msg = res?.body?.message || `Update failed (HTTP ${res?.status || '?'})`
    showToast(msg, 'danger')
    // Force a reload so the view reflects server truth (handles out-of-band locks)
    await selectTemplate(selected.value.id)
  }
}
async function saveColumn(col) {
  await patchCol(col, 'column_label', col.column_label)
}

function openAddCol() {
  Object.assign(newCol, { column_key: '', column_label: '', category: 'CUSTOM',
    data_type: 'INTEGER', aggregation_fn: 'SUM', is_required: false, help_text: '' })
  newColErr.value = ''
  showAddCol.value = true
}

async function submitNewCol() {
  newColErr.value = ''
  if (!/^[a-z][a-z0-9_]{1,58}$/.test(newCol.column_key)) {
    newColErr.value = 'Key must be lowercase letters/digits/underscores, 2-60 chars, starting with a letter.'
    return
  }
  if (!newCol.column_label) { newColErr.value = 'Label is required.'; return }
  saving.value = true
  try {
    const res = await api(`/aggregated-templates/${selected.value.id}/columns`, {
      method: 'POST',
      body: JSON.stringify({ user_id: auth.value?.id, ...newCol }),
    })
    if (res?.ok && res.body?.success) {
      showAddCol.value = false
      await selectTemplate(selected.value.id)
      showToast('Column added', 'success')
    } else {
      newColErr.value = res?.body?.message || 'Add failed'
    }
  } finally { saving.value = false }
}

async function deleteCol(col) {
  if (!confirm(`Delete column "${col.column_label}"?\nSubmissions already using it will keep their data.`)) return
  const res = await api(`/aggregated-template-columns/${col.id}`, {
    method: 'DELETE',
    body: JSON.stringify({ user_id: auth.value?.id }),
  })
  if (res?.ok && res.body?.success) {
    columns.value = columns.value.filter(c => c.id !== col.id)
    showToast('Column deleted', 'success')
  } else {
    showToast(res?.body?.message || 'Delete failed', 'danger')
  }
}

// Template creation moved to /admin/aggregated-wizard — kept a one-line redirect
// so older bookmarks / external calls still land somewhere sensible.

function showToast(msg, color = 'success') { toast.show = true; toast.msg = msg; toast.color = color }

onMounted(loadAll)
onIonViewDidEnter(() => { auth.value = getAuth(); loadAll() })
</script>

<style scoped>
*{box-sizing:border-box}

.tp-hdr{--background:transparent;border:none}
.tp-hdr-bg{background:linear-gradient(135deg,#001D3D,#003566,#003F88);padding:0 0 0}
.tp-hdr-top{display:flex;align-items:center;gap:4px;padding:8px 8px 0}
.tp-back{--color:rgba(255,255,255,.85)}
.tp-hdr-title{flex:1;display:flex;flex-direction:column;min-width:0}
.tp-hdr-eye{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1.4px;color:rgba(255,255,255,.5)}
.tp-hdr-h1{font-size:17px;font-weight:800;color:#fff}
.tp-ref{width:32px;height:32px;border-radius:50%;border:1px solid rgba(255,255,255,.1);background:rgba(255,255,255,.06);color:rgba(255,255,255,.78);display:flex;align-items:center;justify-content:center}
.tp-tabs{display:flex;gap:4px;padding:10px 8px 0;overflow-x:auto;scrollbar-width:none}
.tp-tabs::-webkit-scrollbar{display:none}
.tp-tab{flex-shrink:0;padding:8px 12px;border-radius:8px 8px 0 0;background:rgba(255,255,255,.06);border:none;color:rgba(255,255,255,.78);cursor:pointer;display:flex;flex-direction:column;gap:2px;min-width:140px;position:relative}
.tp-tab--on{background:#fff;color:#1A3A5C}
.tp-tab--add{background:rgba(255,255,255,.12);justify-content:center;align-items:center;min-width:80px;flex-direction:row;font-weight:800}
.tp-tab-t{font-size:12px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px}
.tp-tab-s{font-size:9px;font-weight:700;opacity:.6}
.tp-tab-live{position:absolute;top:4px;right:6px;font-size:8px;font-weight:900;padding:1px 5px;border-radius:3px;background:#059669;color:#fff}
.tp-tab-lock{position:absolute;top:4px;left:6px;font-size:10px}

.tp-content{--background:#F0F4FA}
.tp-ro{margin:10px;padding:10px 12px;background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;display:flex;gap:8px;font-size:11.5px;color:#854D0E}
.tp-ro ion-icon{flex-shrink:0;font-size:16px;margin-top:1px}
.tp-ro strong{font-weight:800}

.tp-empty{padding:40px 20px;text-align:center;color:#64748B}
.tp-empty-t{font-size:15px;font-weight:800;color:#1A3A5C;margin-bottom:6px}
.tp-empty-s{font-size:12px}

.tp-meta{margin:10px;background:#fff;border:1px solid #E8EDF5;border-radius:10px;padding:12px;display:flex;flex-direction:column;gap:10px}
.tp-meta-row{display:flex;flex-direction:column;gap:4px}
.tp-meta-k{font-size:10px;font-weight:800;color:#64748B;text-transform:uppercase;letter-spacing:.4px}
.tp-meta-v{font-size:12px;color:#1A3A5C;font-weight:700}
.tp-mono{font-family:ui-monospace,Menlo,monospace}
.tp-inp{padding:8px 10px;border:1px solid #E8EDF5;border-radius:6px;font-size:12px;color:#1A3A5C;background:#fff;font-family:inherit;width:100%}
.tp-inp:disabled{background:#F8FAFC;color:#94A3B8;cursor:not-allowed}
.tp-inp--ta{resize:vertical;min-height:50px}
.tp-meta-ops{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.tp-pill{padding:3px 8px;border-radius:4px;font-size:10px;font-weight:800;background:#F1F5F9;color:#475569;letter-spacing:.3px}
.tp-pill--live{background:#D1FAE5;color:#047857}
.tp-pill--locked{background:#FEF2F2;color:#991B1B}
.tp-btn{padding:6px 12px;border-radius:6px;border:none;font-size:11.5px;font-weight:800;cursor:pointer}
.tp-btn--activate{background:#059669;color:#fff}
.tp-btn--ghost{background:#fff;color:#475569;border:1px solid #CBD5E1}
.tp-btn--primary{background:#1E40AF;color:#fff}
.tp-btn:disabled{opacity:.45;cursor:not-allowed}

.tp-sh-row{display:flex;justify-content:space-between;align-items:center;padding:16px 14px 6px}
.tp-sh{font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:1px;margin:0}
.tp-add-col{padding:6px 10px;border:1px solid #1E40AF;background:#fff;color:#1E40AF;border-radius:6px;font-size:11px;font-weight:800;cursor:pointer}

.tp-cats{display:flex;gap:4px;padding:0 10px 8px;overflow-x:auto;scrollbar-width:none}
.tp-cats::-webkit-scrollbar{display:none}
.tp-cat{flex-shrink:0;padding:4px 10px;border-radius:99px;border:1px solid #CBD5E1;background:#fff;color:#475569;font-size:10px;font-weight:700;cursor:pointer;white-space:nowrap}
.tp-cat--on{background:#1E40AF;border-color:#1E40AF;color:#fff}

.tp-cols{padding:0 10px;display:flex;flex-direction:column;gap:6px}
.tp-col{background:#fff;border:1px solid #E8EDF5;border-radius:10px;padding:10px 12px;transition:opacity .2s}
.tp-col--off{opacity:.55;background:#F8FAFC}
.tp-col--core{border-left:3px solid #1E40AF}
.tp-col-h{display:flex;align-items:center;gap:10px}
.tp-col-meta{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.tp-col-label{border:none;background:transparent;font-size:13px;font-weight:800;color:#1A3A5C;padding:2px 0;font-family:inherit;width:100%;outline:none;border-bottom:1px dashed transparent}
.tp-col-label:focus{border-bottom-color:#1E40AF}
.tp-col-label:disabled{color:#1A3A5C;cursor:default}
.tp-col-sub{display:flex;gap:8px;font-size:9.5px;font-weight:600;color:#64748B}
.tp-col-k{font-family:ui-monospace,Menlo,monospace}
.tp-col-t{color:#1E40AF;font-weight:800}
.tp-col-c{color:#6B21A8}
.tp-col-order{font-size:10px;color:#94A3B8;font-family:ui-monospace,Menlo,monospace;flex-shrink:0}

.tp-toggle{position:relative;width:36px;height:20px;flex-shrink:0}
.tp-toggle input{opacity:0;width:0;height:0}
.tp-toggle-slider{position:absolute;inset:0;background:#CBD5E1;border-radius:99px;transition:background .2s;cursor:pointer}
.tp-toggle-slider::before{content:'';position:absolute;left:2px;top:2px;width:16px;height:16px;background:#fff;border-radius:50%;transition:transform .2s}
.tp-toggle input:checked + .tp-toggle-slider{background:#059669}
.tp-toggle input:checked + .tp-toggle-slider::before{transform:translateX(16px)}
.tp-core-badge{font-size:9px;font-weight:900;padding:3px 6px;border-radius:4px;background:#DBEAFE;color:#1E40AF;flex-shrink:0}
.tp-off-badge{font-size:9px;font-weight:800;padding:3px 6px;border-radius:4px;background:#F1F5F9;color:#64748B;flex-shrink:0}
.tp-off-badge--on{background:#D1FAE5;color:#047857}

.tp-col-actions{margin-top:10px;padding-top:8px;border-top:1px solid #F0F4FA;display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:10.5px}
.tp-chk{display:flex;align-items:center;gap:4px;font-weight:600;color:#475569;cursor:pointer}
.tp-chk input{width:14px;height:14px;cursor:pointer}
.tp-sel{padding:4px 6px;border:1px solid #E8EDF5;border-radius:4px;font-size:10.5px;background:#fff;color:#1A3A5C}
.tp-del{margin-left:auto;padding:4px 8px;border:1px solid #FECACA;background:#fff;color:#DC2626;border-radius:4px;font-size:10px;font-weight:700;cursor:pointer}
.tp-col-meta-ro{margin-top:8px;padding-top:8px;border-top:1px solid #F0F4FA;display:flex;gap:6px;flex-wrap:wrap}
.tp-tag{font-size:9.5px;font-weight:700;padding:2px 6px;border-radius:4px;background:#F1F5F9;color:#475569}

/* Modals */
.tp-modal{--background:#fff}
.tp-mp{padding:20px 16px}
.tp-mt{font-size:17px;font-weight:800;color:#1A3A5C;margin:0 0 6px}
.tp-ms{font-size:11.5px;color:#64748B;margin:0 0 14px;line-height:1.45}
.tp-flbl{display:block;font-size:10px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:.3px;margin:10px 0 4px}
.tp-flbl-chk{display:flex;gap:8px;align-items:center;font-size:12px;color:#1A3A5C;margin-top:12px}
.tp-frow{display:flex;gap:10px;align-items:stretch}
.tp-fhalf{flex:1;min-width:0}
.tp-err{color:#DC2626;font-size:11px;font-weight:600;margin-top:10px}
.tp-mact{display:flex;gap:8px;margin-top:16px}
.tp-mact .tp-btn{flex:1;padding:11px}

/* Additions for status + delete + lock banner (2026-04-21 v2) */
.tp-pill--retired{background:#F1F5F9;color:#475569}
.tp-pill--freq{background:#E0E7FF;color:#3730A3}
.tp-btn--danger{background:#fff;color:#DC2626;border:1px solid #FECACA}
.tp-btn--danger:active{background:#FEF2F2}
.tp-lock-banner{display:flex;align-items:center;gap:10px;margin:0 10px 10px;padding:12px 14px;background:linear-gradient(135deg,#FEF2F2,#FEE2E2);border:1px solid #FECACA;border-radius:10px}
.tp-lock-ic{font-size:20px;flex-shrink:0}
.tp-lock-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.tp-lock-t{font-size:12.5px;font-weight:800;color:#991B1B}
.tp-lock-s{font-size:10.5px;color:#7F1D1D;font-weight:600;line-height:1.4}
.tp-lock-btn{padding:6px 14px;border-radius:6px;border:none;background:#991B1B;color:#fff;font-size:11px;font-weight:800;cursor:pointer;flex-shrink:0}

@media(min-width:500px){.tp-meta,.tp-cols,.tp-lock-banner{max-width:540px;margin-left:auto;margin-right:auto}.tp-sh-row{max-width:540px;margin:16px auto 6px}}
</style>
