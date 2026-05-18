<!--
  ExplainModal.vue — reusable per-chart "Explain" panel.

  Mandate 2026-05-06 (Priority — every graph must be explainable):
  Gives any chart / card a small ⓘ button that opens an Ionic modal with:
    • Plain-English "What this shows" description
    • "How to read it" guidance
    • Tabular dump of the underlying data so anyone can verify the chart
    • "What action to take" practical interpretation
    • Source / data captured-at context

  Usage:
    <ExplainModal
      title="Hourly screening volume"
      what="Bars: total screenings per hour. Red overlay: febrile travellers."
      how="Look for hours with elevated febrile rate (red bar > grey bar)."
      action="If a single hour spikes febrile cases, raise it to your supervisor."
      :rows="hourlyRows"
      :columns="['Hour', 'Total', 'Febrile']"
      source="Local primary_screenings IDB store + server back-fill."
    />

  Or as a custom button (optional default trigger renders a small ⓘ chip):
    <ExplainModal title="…" :rows="…" :columns="…">
      <template #trigger="{ open }">
        <button @click="open">Explain</button>
      </template>
    </ExplainModal>
-->
<script setup>
import { ref, computed } from 'vue'
import {
  IonModal, IonHeader, IonToolbar, IonButtons, IonButton,
  IonContent, IonIcon,
} from '@ionic/vue'
import { closeOutline, informationCircleOutline } from 'ionicons/icons'

const props = defineProps({
  title:   { type: String, required: true },
  what:    { type: String, default: '' },
  how:     { type: String, default: '' },
  action:  { type: String, default: '' },
  rows:    { type: Array,  default: () => [] },
  columns: { type: Array,  default: () => [] },
  source:  { type: String, default: '' },
  capturedAt: { type: String, default: '' },
})

const open = ref(false)
function show() { open.value = true }
function hide() { open.value = false }

const totalRows = computed(() => props.rows.length)
</script>

<template>
  <slot name="trigger" :open="show">
    <button class="em-trigger" type="button" :aria-label="`Explain ${title}`" @click="show">
      <ion-icon :icon="informationCircleOutline" aria-hidden="true"/>
      <span>Explain</span>
    </button>
  </slot>

  <ion-modal :is-open="open" @did-dismiss="hide">
    <ion-header>
      <ion-toolbar class="em-toolbar">
        <ion-buttons slot="end">
          <ion-button @click="hide" aria-label="Close">
            <ion-icon :icon="closeOutline" slot="icon-only"/>
          </ion-button>
        </ion-buttons>
        <div class="em-title">{{ title }}</div>
      </ion-toolbar>
    </ion-header>
    <ion-content :scroll-y="true" class="em-content">
      <div class="em-body">
        <section v-if="what" class="em-sect em-sect--what">
          <div class="em-sect-h">What this shows</div>
          <p>{{ what }}</p>
        </section>
        <section v-if="how" class="em-sect em-sect--how">
          <div class="em-sect-h">How to read it</div>
          <p>{{ how }}</p>
        </section>
        <section v-if="action" class="em-sect em-sect--action">
          <div class="em-sect-h">What action to take</div>
          <p>{{ action }}</p>
        </section>

        <section class="em-sect em-sect--data">
          <div class="em-sect-h">
            Underlying data
            <span class="em-rowcount">{{ totalRows }} row{{ totalRows === 1 ? '' : 's' }}</span>
          </div>
          <div v-if="!totalRows" class="em-empty">No rows in the current filter scope.</div>
          <div v-else class="em-tablewrap">
            <table class="em-table">
              <thead>
                <tr><th v-for="(c, i) in columns" :key="i">{{ c }}</th></tr>
              </thead>
              <tbody>
                <tr v-for="(r, ri) in rows" :key="ri">
                  <td v-for="(_, ci) in columns" :key="ci">
                    {{ Array.isArray(r) ? (r[ci] ?? '—') : (r[columns[ci]] ?? r[ci] ?? '—') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section v-if="source || capturedAt" class="em-sect em-sect--meta">
          <div class="em-meta-row" v-if="source"><span class="em-meta-k">Source</span><span class="em-meta-v">{{ source }}</span></div>
          <div class="em-meta-row" v-if="capturedAt"><span class="em-meta-k">Last refreshed</span><span class="em-meta-v">{{ capturedAt }}</span></div>
        </section>
      </div>
    </ion-content>
  </ion-modal>
</template>

<style scoped>
.em-trigger{
  display:inline-flex;align-items:center;gap:4px;
  padding:4px 10px;border-radius:18px;border:1px solid #DBEAFE;
  background:#EFF6FF;color:#1D4ED8;font-size:11px;font-weight:700;
  cursor:pointer;transition:background .12s;
}
.em-trigger:hover{background:#DBEAFE}
.em-trigger ion-icon{font-size:13px}

.em-toolbar{--background:linear-gradient(135deg,#0B2545,#13315C);--color:#fff;--border-width:0}
.em-title{font-size:15px;font-weight:800;color:#fff;padding:0 10px}
.em-content{--background:#F8FAFC}
.em-body{padding:14px;max-width:900px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}

.em-sect{
  background:#fff;border:1px solid #E2E8F0;border-radius:12px;
  padding:14px 16px;margin-bottom:12px;
  box-shadow:0 1px 2px rgba(15,23,42,.04);
}
.em-sect-h{font-size:11px;font-weight:800;color:#475569;letter-spacing:.06em;text-transform:uppercase;margin-bottom:6px;display:flex;justify-content:space-between;align-items:center;gap:8px}
.em-sect p{margin:0;font-size:13.5px;color:#0F172A;line-height:1.55}
.em-sect--what  .em-sect-h{color:#1D4ED8}
.em-sect--how   .em-sect-h{color:#0F766E}
.em-sect--action .em-sect-h{color:#B45309}
.em-rowcount{font-size:10px;font-weight:700;color:#64748B;background:#F1F5F9;padding:2px 8px;border-radius:99px}

.em-empty{font-size:12px;color:#94A3B8;padding:12px;text-align:center}
.em-tablewrap{max-height:340px;overflow:auto;border:1px solid #E2E8F0;border-radius:8px}
.em-table{width:100%;border-collapse:collapse;font-size:12.5px}
.em-table thead{background:#F1F5F9;position:sticky;top:0;z-index:1}
.em-table th{font-weight:800;color:#0F172A;padding:8px 10px;text-align:left;border-bottom:1px solid #E2E8F0;letter-spacing:.02em}
.em-table tbody tr:nth-child(odd){background:#FAFBFD}
.em-table tbody tr:hover{background:#EFF6FF}
.em-table td{padding:7px 10px;color:#1E293B;border-bottom:1px solid #F1F5F9}

.em-meta-row{display:flex;justify-content:space-between;font-size:11px;color:#64748B;padding:3px 0}
.em-meta-k{font-weight:700}
.em-meta-v{color:#0F172A}
</style>
