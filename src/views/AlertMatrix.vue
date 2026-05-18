<template>
  <IonPage>
    <IonHeader class="mx-hdr" translucent>
      <div class="mx-hdr-bg">
        <div class="mx-hdr-top">
          <IonButtons slot="start"><IonBackButton default-href="/alerts" class="mx-back"/></IonButtons>
          <div class="mx-hdr-title">
            <span class="mx-hdr-eye">WHO · IHR 2005 Reference</span>
            <span class="mx-hdr-h1">Alert Matrix</span>
          </div>
        </div>
        <!-- Tab bar -->
        <div class="mx-tabs">
          <button v-for="t in TABS" :key="t.v" :class="['mx-tab', tab === t.v && 'mx-tab--on']" @click="tab = t.v">
            {{ t.l }}
          </button>
        </div>
      </div>
    </IonHeader>

    <IonContent class="mx-content" :fullscreen="true">
      <!-- Tier 1 -->
      <section v-show="tab === 't1'" class="mx-sec">
        <div class="mx-card mx-card--t1">
          <header class="mx-ch">
            <span class="mx-tag mx-tag--t1">TIER 1</span>
            <h2 class="mx-ct">Always Notifiable — single case</h2>
          </header>
          <p class="mx-cb">A single confirmed or probable case of any of the following <strong>automatically triggers mandatory WHO notification within 24 hours</strong> — the Annex 2 4-criteria assessment is bypassed.</p>
          <div class="mx-dgrid">
            <article v-for="(d, i) in TIER1" :key="d.code" class="mx-dis">
              <span class="mx-dis-n">{{ d.name }}</span>
              <span class="mx-dis-c">{{ d.code }}</span>
              <span class="mx-dis-b">{{ d.basis }}</span>
              <div v-if="idsrMeta(d.disease_id)?.alert_threshold || idsrMeta(d.disease_id)?.epidemic_threshold" class="mx-thresh">
                <div v-if="idsrMeta(d.disease_id)?.alert_threshold" class="mx-thresh-row">
                  <span class="mx-thresh-tag mx-thresh-tag--alert">ALERT</span>
                  <span class="mx-thresh-text">{{ idsrMeta(d.disease_id).alert_threshold }}</span>
                </div>
                <div v-if="idsrMeta(d.disease_id)?.epidemic_threshold" class="mx-thresh-row">
                  <span class="mx-thresh-tag mx-thresh-tag--epi">EPIDEMIC</span>
                  <span class="mx-thresh-text">{{ idsrMeta(d.disease_id).epidemic_threshold }}</span>
                </div>
              </div>
            </article>
          </div>
          <div class="mx-cite">IHR 2005 Annex 2 · Events that shall always lead to utilisation of the algorithm · Thresholds from Uganda IDSR Annex 1A</div>
        </div>
      </section>

      <!-- Tier 2 -->
      <section v-show="tab === 't2'" class="mx-sec">
        <div class="mx-card mx-card--t2">
          <header class="mx-ch">
            <span class="mx-tag mx-tag--t2">TIER 2</span>
            <h2 class="mx-ct">Annex 2 Assessment Required</h2>
          </header>
          <p class="mx-cb">Events involving these diseases <strong>always</strong> run through the 4-criteria decision instrument. If <strong>any 2 of 4 are YES</strong>, notify WHO within 24 hours via the National IHR Focal Point.</p>
          <div class="mx-dgrid">
            <article v-for="d in TIER2" :key="d.code" class="mx-dis mx-dis--t2">
              <span class="mx-dis-n">{{ d.name }}</span>
              <span class="mx-dis-c">{{ d.code }}</span>
              <span class="mx-dis-b">{{ d.basis }}</span>
              <div v-if="idsrMeta(d.disease_id)?.alert_threshold || idsrMeta(d.disease_id)?.epidemic_threshold" class="mx-thresh">
                <div v-if="idsrMeta(d.disease_id)?.alert_threshold" class="mx-thresh-row">
                  <span class="mx-thresh-tag mx-thresh-tag--alert">ALERT</span>
                  <span class="mx-thresh-text">{{ idsrMeta(d.disease_id).alert_threshold }}</span>
                </div>
                <div v-if="idsrMeta(d.disease_id)?.epidemic_threshold" class="mx-thresh-row">
                  <span class="mx-thresh-tag mx-thresh-tag--epi">EPIDEMIC</span>
                  <span class="mx-thresh-text">{{ idsrMeta(d.disease_id).epidemic_threshold }}</span>
                </div>
              </div>
            </article>
          </div>
          <div class="mx-cite">IHR 2005 Annex 2 · Decision instrument · middle branch · Thresholds from Uganda IDSR Annex 1A</div>
        </div>
      </section>

      <!-- Annex 2 -->
      <section v-show="tab === 'annex'" class="mx-sec">
        <div class="mx-card mx-card--annex">
          <header class="mx-ch">
            <span class="mx-tag mx-tag--annex">ANNEX 2</span>
            <h2 class="mx-ct">Four-Criteria Decision Instrument</h2>
          </header>
          <p class="mx-cb">A State Party must use this decision instrument when an event of potential public health concern occurs. The rule is absolute: <strong>ANY 2 of 4 criteria YES → notify WHO within 24 hours (IHR Art. 6)</strong>.</p>
          <div class="mx-crit">
            <article v-for="(c, i) in ANNEX2_CRITERIA" :key="c.key" class="mx-crit-row">
              <span class="mx-crit-n">{{ i + 1 }}</span>
              <div class="mx-crit-body">
                <span class="mx-crit-t">{{ c.label }}</span>
                <span class="mx-crit-d">{{ c.desc }}</span>
                <span v-if="c.example" class="mx-crit-ex">Example: {{ c.example }}</span>
              </div>
            </article>
          </div>
          <div class="mx-rule">
            <span class="mx-rule-tag">RULE</span>
            <span class="mx-rule-body">Count the YES responses. <strong>≥ 2 → notify WHO within 24h</strong>. The State Party's assessment is what matters, not consensus; under-reporting violates IHR obligations.</span>
          </div>
          <div class="mx-cite">IHR 2005 Third Edition · Annex 2, pp. 43–46</div>
        </div>
      </section>

      <!-- 7-1-7 -->
      <section v-show="tab === '717'" class="mx-sec">
        <div class="mx-card mx-card--seven">
          <header class="mx-ch">
            <span class="mx-tag mx-tag--seven">7-1-7</span>
            <h2 class="mx-ct">Pandemic Preparedness Target</h2>
          </header>
          <p class="mx-cb">Developed by Resolve to Save Lives with WHO, 7-1-7 is the global performance target for outbreak detection and response. Every outbreak gets a scorecard.</p>
          <div class="mx-seven-blocks">
            <div class="mx-sb mx-sb--d">
              <span class="mx-sb-n">7</span>
              <span class="mx-sb-l">days to detect</span>
              <span class="mx-sb-d">Time from emergence until the public health system identifies the event.</span>
            </div>
            <div class="mx-sb mx-sb--n">
              <span class="mx-sb-n">1</span>
              <span class="mx-sb-l">day to notify</span>
              <span class="mx-sb-d">Time from detection to notification of public health authorities + start of investigation.</span>
            </div>
            <div class="mx-sb mx-sb--r">
              <span class="mx-sb-n">7</span>
              <span class="mx-sb-l">days to respond</span>
              <span class="mx-sb-d">Time from notification to completion of the 14 RTSL early response actions.</span>
            </div>
          </div>
          <h3 class="mx-sh">14 RTSL early response actions</h3>
          <ol class="mx-actions">
            <li v-for="(a, i) in RTSL_14" :key="i">{{ a }}</li>
          </ol>
          <div class="mx-rule mx-rule--seven">
            <span class="mx-rule-tag">BOTTLENECK</span>
            <span class="mx-rule-body">A missed target is a bottleneck. Root cause analysis is required — the failure category (capacity, training, communications, lab, leadership, coordination, legal) must be documented.</span>
          </div>
          <div class="mx-cite">Frieden TR et al. <em>Lancet</em> 2021; 398:638-640 · Resolve to Save Lives / WHO</div>
        </div>
      </section>

      <!-- Escalation -->
      <section v-show="tab === 'esc'" class="mx-sec">
        <div class="mx-card mx-card--esc">
          <header class="mx-ch">
            <span class="mx-tag mx-tag--esc">ESCALATION</span>
            <h2 class="mx-ct">Notification Ladder</h2>
          </header>
          <p class="mx-cb">Uganda IDSR + IHR 2005 escalation timing. The national IHR Focal Point is the single authorised channel for WHO communication.</p>
          <div class="mx-ladder">
            <article v-for="s in LADDER" :key="s.from" class="mx-step">
              <div class="mx-step-flow">
                <span class="mx-step-from">{{ s.from }}</span>
                <span class="mx-step-arr">→</span>
                <span class="mx-step-to">{{ s.to }}</span>
              </div>
              <span class="mx-step-t">{{ s.target }}</span>
              <span class="mx-step-d">{{ s.detail }}</span>
              <span class="mx-step-c">{{ s.cite }}</span>
            </article>
          </div>
        </div>
      </section>

      <!-- PHEIC -->
      <section v-show="tab === 'pheic'" class="mx-sec">
        <div class="mx-card mx-card--pheic">
          <header class="mx-ch">
            <span class="mx-tag mx-tag--pheic">PHEIC</span>
            <h2 class="mx-ct">Article 12 Criteria</h2>
          </header>
          <p class="mx-cb">A Public Health Emergency of International Concern is declared by the WHO Director-General, advised by an Emergency Committee. Three criteria must be considered:</p>
          <div class="mx-pheic">
            <article v-for="(p, i) in PHEIC" :key="p.key" class="mx-pheic-row">
              <span class="mx-pheic-n">{{ i + 1 }}</span>
              <div>
                <span class="mx-pheic-t">{{ p.label }}</span>
                <span class="mx-pheic-d">{{ p.desc }}</span>
              </div>
            </article>
          </div>
          <h3 class="mx-sh">Historical PHEIC declarations</h3>
          <ul class="mx-hist">
            <li v-for="h in PHEIC_HIST" :key="h.year + h.name">
              <span class="mx-hist-y">{{ h.year }}</span>
              <span class="mx-hist-n">{{ h.name }}</span>
            </li>
          </ul>
          <div class="mx-cite">IHR 2005 Article 12 · IHR Emergency Committee procedures</div>
        </div>
      </section>

      <div style="height:64px"/>
    </IonContent>
  </IonPage>
</template>

<script setup>
import {
  IonPage, IonHeader, IonContent, IonButtons, IonBackButton,
} from '@ionic/vue'
import { ref } from 'vue'

const TABS = [
  { v: 't1', l: 'Tier 1' },
  { v: 't2', l: 'Tier 2' },
  { v: 'annex', l: 'Annex 2' },
  { v: '717', l: '7-1-7' },
  { v: 'esc', l: 'Escalation' },
  { v: 'pheic', l: 'PHEIC' },
]
const tab = ref('t1')

const TIER1 = [
  { code: 'SMALLPOX',     disease_id: 'smallpox',                      name: 'Smallpox (Variola)',               basis: 'Eradicated 1980; any re-emergence is extraordinary.' },
  { code: 'WILD_POLIO',   disease_id: 'polio',                         name: 'Poliomyelitis (wild poliovirus)',  basis: 'Global eradication commitment; single case = PHEIC signal.' },
  { code: 'NOVEL_FLU',    disease_id: 'influenza_new_subtype_zoonotic', name: 'Novel influenza A subtype',        basis: 'Any H5, H7, H9 or other new subtype in humans; pandemic risk.' },
  { code: 'SARS',         disease_id: 'sars',                          name: 'Severe Acute Respiratory Syndrome', basis: 'SARS-CoV-1 coronavirus — high case fatality and transmissibility.' },
]

const TIER2 = [
  { code: 'CHOLERA',        disease_id: 'cholera',                  name: 'Cholera',            basis: 'Vibrio cholerae O1/O139; severe dehydrating diarrhoea; outbreak prone.' },
  { code: 'PNEUMONIC_PLAG', disease_id: 'pneumonic_plague',         name: 'Pneumonic plague',   basis: 'Yersinia pestis (pneumonic form); high transmissibility + lethality.' },
  { code: 'YELLOW_FEVER',   disease_id: 'yellow_fever',             name: 'Yellow fever',       basis: 'Aedes-borne flavivirus; jungle/urban cycles; outbreak potential.' },
  { code: 'VHF_EBOLA',      disease_id: 'ebola_virus_disease',      name: 'Ebola (VHF)',        basis: 'Filovirus haemorrhagic fever; high case fatality; nosocomial amplification.' },
  { code: 'VHF_MARBURG',    disease_id: 'marburg_virus_disease',    name: 'Marburg (VHF)',      basis: 'Filovirus haemorrhagic fever; rural outbreak profile.' },
  { code: 'VHF_LASSA',      disease_id: 'lassa_fever',              name: 'Lassa (VHF)',        basis: 'Arenavirus; rodent-borne; West Africa endemic.' },
  { code: 'VHF_CCHF',       disease_id: 'cchf',                     name: 'Crimean-Congo HF',   basis: 'Tick-borne nairovirus; livestock exposure; nosocomial clusters.' },
  { code: 'WEST_NILE',      disease_id: 'west_nile_fever',          name: 'West Nile fever',    basis: 'Mosquito-borne flavivirus; neuroinvasive form reportable.' },
  { code: 'MENINGOCOCC',    disease_id: 'meningococcal_meningitis', name: 'Meningococcal disease', basis: 'Neisseria meningitidis; meningitis belt; outbreak clusters.' },
  { code: 'MERS_COV',       disease_id: 'mers',                     name: 'MERS-CoV',           basis: 'Middle East Respiratory Syndrome coronavirus; camel contact + nosocomial.' },
  { code: 'SARS_COV_2',     disease_id: 'covid_19',                 name: 'SARS-CoV-2 / COVID', basis: 'Novel coronavirus; subject to PHEIC declaration 2020.' },
  { code: 'MPOX_CLADE_I',   disease_id: 'mpox',                     name: 'Mpox (clade I)',     basis: 'Monkeypox virus clade I; PHEIC declared 2024.' },
  { code: 'RVF',            disease_id: 'rift_valley_fever',        name: 'Rift Valley fever',  basis: 'Mosquito-borne phlebovirus; livestock epizootics.' },
  { code: 'DENGUE',         disease_id: 'dengue_severe',            name: 'Dengue (severe)',    basis: 'Aedes-borne flavivirus; outbreak clusters at IHR threshold.' },
]

const idsrMeta = (id) => window.DISEASES?.getDiseaseById?.(id, { includeLegacy: true }) || null

const ANNEX2_CRITERIA = [
  { key: 'serious',  label: 'Serious public health impact?',
    desc: 'Unusually high case counts or death rates, unusually high morbidity, or rapid geographic spread.',
    example: 'Case fatality rate significantly above historical baseline.' },
  { key: 'unusual',  label: 'Unusual or unexpected?',
    desc: 'Event caused by unknown agent, or source/vehicle of transmission is unusual, or presentation atypical.',
    example: 'Novel pathogen identified; disease appearing outside known endemic zone.' },
  { key: 'spread',   label: 'Significant risk of international spread?',
    desc: 'Evidence of epidemiological link to similar events in other States, or cross-border movement at-risk.',
    example: 'Traveler at POE screened positive for highly transmissible agent.' },
  { key: 'trade',    label: 'Significant risk of international travel or trade restrictions?',
    desc: 'Events that other countries may respond to by imposing restrictions — formal or informal.',
    example: 'Media reports triggering precautionary border closures.' },
]

const RTSL_14 = [
  'Case investigation started',
  'Index case isolated / treatment initiated',
  'Close contacts identified and listed',
  'Contact tracing and follow-up operational',
  'Laboratory specimens collected and transported',
  'Laboratory confirmation obtained',
  'Epidemiological line list maintained',
  'Risk communication to the public initiated',
  'Infection prevention & control (IPC) in health facilities',
  'Vector control measures (if applicable)',
  'Cross-border / POE surveillance strengthened',
  'Coordination structure activated (EOC / PHEOC)',
  'Response resources mobilised (people, funding, logistics)',
  'WHO and partners notified per IHR Article 6',
]

const LADDER = [
  { from: 'POE / Health facility', to: 'District Surveillance',
    target: '≤ 2 hours', detail: 'For notifiable diseases, initial verbal report by phone then written within 24h.',
    cite: 'Uganda IDSR Technical Guidelines §2' },
  { from: 'District', to: 'National PHEOC',
    target: '≤ 24 hours', detail: 'Via weekly IDSR line-list or immediate notification for priority events.',
    cite: 'WHO AFRO IDSR 2019' },
  { from: 'National IHR Focal Point', to: 'WHO',
    target: '≤ 24 hours', detail: 'When Tier 1 event occurs OR Annex 2 decision instrument result is 2-of-4 YES.',
    cite: 'IHR 2005 Article 6' },
  { from: 'WHO', to: 'State Party (verification)',
    target: '≤ 24 hours', detail: 'WHO requests verification; State Party must respond within 24 hours.',
    cite: 'IHR 2005 Article 10' },
]

const PHEIC = [
  { key: 'extraordinary',  label: 'Extraordinary event',
    desc: 'An event that is not routine or predictable; its magnitude or nature is beyond normal expectation.' },
  { key: 'spread',         label: 'Risk to other States through international spread',
    desc: 'Evidence, or credible suspicion, of cross-border transmission beyond the originating State Party.' },
  { key: 'coordination',   label: 'Requires coordinated international response',
    desc: 'The response calls for cooperation, solidarity and resources beyond what a single State can provide.' },
]

const PHEIC_HIST = [
  { year: 2009, name: 'H1N1 pandemic influenza' },
  { year: 2014, name: 'Polio international spread' },
  { year: 2014, name: 'Ebola — West Africa' },
  { year: 2016, name: 'Zika virus' },
  { year: 2019, name: 'Ebola — DRC' },
  { year: 2020, name: 'COVID-19 (SARS-CoV-2)' },
  { year: 2022, name: 'Mpox — multi-country outbreak' },
  { year: 2024, name: 'Mpox — clade I' },
]
</script>

<style scoped>
*{box-sizing:border-box}

/* Header */
.mx-hdr{--background:transparent;border:none}
.mx-hdr-bg{background:linear-gradient(135deg,#001D3D,#003566,#003F88);padding:0 0 0}
.mx-hdr-top{display:flex;align-items:center;gap:4px;padding:8px 8px 0}
.mx-back{--color:rgba(255,255,255,.85)}
.mx-hdr-title{flex:1;display:flex;flex-direction:column;min-width:0}
.mx-hdr-eye{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1.4px;color:rgba(255,255,255,.45)}
.mx-hdr-h1{font-size:17px;font-weight:800;color:#fff;letter-spacing:-.2px}

/* Tabs */
.mx-tabs{display:flex;gap:2px;padding:8px 6px 0;overflow-x:auto;scrollbar-width:none}
.mx-tabs::-webkit-scrollbar{display:none}
.mx-tab{flex-shrink:0;padding:8px 12px 10px;background:transparent;border:none;border-bottom:2px solid transparent;color:rgba(255,255,255,.6);font-size:12px;font-weight:700;cursor:pointer;white-space:nowrap}
.mx-tab--on{color:#fff;border-bottom-color:#60A5FA}

/* Content */
.mx-content{--background:#F0F4FA}
.mx-sec{padding:14px 10px 0}

.mx-card{background:#fff;border:1px solid #E8EDF5;border-radius:12px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.04);overflow:hidden}
.mx-card--t1{border-left:4px solid #9333EA}
.mx-card--t2{border-left:4px solid #DC2626}
.mx-card--annex{border-left:4px solid #1E40AF}
.mx-card--seven{border-left:4px solid #059669}
.mx-card--esc{border-left:4px solid #CA8A04}
.mx-card--pheic{border-left:4px solid #0F172A}

.mx-ch{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.mx-tag{font-size:10px;font-weight:900;padding:3px 9px;border-radius:4px;background:#1E40AF;color:#fff;letter-spacing:.4px;flex-shrink:0}
.mx-tag--t1{background:#9333EA}
.mx-tag--t2{background:#DC2626}
.mx-tag--annex{background:#1E40AF}
.mx-tag--seven{background:#059669}
.mx-tag--esc{background:#CA8A04}
.mx-tag--pheic{background:#0F172A}
.mx-ct{font-size:16px;font-weight:800;color:#1A3A5C;margin:0;line-height:1.3}
.mx-cb{font-size:12.5px;color:#475569;line-height:1.55;margin:0 0 14px}
.mx-cb strong{color:#1A3A5C;font-weight:800}

.mx-dgrid{display:flex;flex-direction:column;gap:8px}
.mx-dis{padding:10px 12px;border:1px solid #E8EDF5;border-radius:8px;background:#FAFBFC;display:flex;flex-direction:column;gap:3px}
.mx-dis--t2{background:#FEF2F2;border-color:#FECACA}
.mx-dis-n{font-size:13px;font-weight:800;color:#1A3A5C}
.mx-dis-c{font-size:9.5px;color:#94A3B8;font-family:ui-monospace,Menlo,monospace;letter-spacing:.3px}
.mx-dis-b{font-size:11.5px;color:#475569;line-height:1.4}
.mx-thresh{display:flex;flex-direction:column;gap:4px;margin-top:8px;padding-top:8px;border-top:1px dashed #E8EDF5}
.mx-thresh-row{display:flex;align-items:flex-start;gap:8px;font-size:11px;line-height:1.45;color:#475569}
.mx-thresh-tag{flex-shrink:0;font-size:8.5px;font-weight:900;letter-spacing:.4px;padding:2px 6px;border-radius:3px;font-family:ui-monospace,Menlo,monospace;margin-top:1px}
.mx-thresh-tag--alert{background:rgba(202,138,4,.12);color:#B45309;border:1px solid rgba(202,138,4,.2)}
.mx-thresh-tag--epi{background:rgba(220,38,38,.1);color:#B91C1C;border:1px solid rgba(220,38,38,.18)}
.mx-thresh-text{flex:1;min-width:0}

.mx-crit{display:flex;flex-direction:column;gap:8px;margin-bottom:12px}
.mx-crit-row{display:flex;gap:10px;padding:10px 12px;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:8px;align-items:flex-start}
.mx-crit-n{width:22px;height:22px;border-radius:50%;background:#1E40AF;color:#fff;font-size:11px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
.mx-crit-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:3px}
.mx-crit-t{font-size:13px;font-weight:800;color:#1A3A5C;line-height:1.3}
.mx-crit-d{font-size:11.5px;color:#475569;line-height:1.4}
.mx-crit-ex{font-size:10.5px;color:#1E40AF;font-weight:600;font-style:italic;line-height:1.4}

.mx-rule{display:flex;gap:10px;padding:12px;background:#FEF3C7;border:1px solid #FDE68A;border-radius:8px;align-items:flex-start}
.mx-rule--seven{background:#F0FDF4;border-color:#BBF7D0}
.mx-rule-tag{font-size:9.5px;font-weight:900;padding:3px 8px;border-radius:4px;background:#B45309;color:#fff;flex-shrink:0;letter-spacing:.4px;margin-top:1px}
.mx-rule--seven .mx-rule-tag{background:#059669}
.mx-rule-body{font-size:11.5px;color:#1A3A5C;font-weight:600;line-height:1.5}
.mx-rule-body strong{font-weight:900}

.mx-seven-blocks{display:flex;gap:8px;margin:0 0 14px}
.mx-sb{flex:1;padding:12px 10px;border-radius:10px;display:flex;flex-direction:column;align-items:center;gap:4px;text-align:center;min-width:0}
.mx-sb--d{background:#F3E8FF;border:1px solid #D8B4FE}
.mx-sb--n{background:#FEF3C7;border:1px solid #FDE68A}
.mx-sb--r{background:#D1FAE5;border:1px solid #A7F3D0}
.mx-sb-n{font-size:28px;font-weight:900;line-height:1}
.mx-sb--d .mx-sb-n{color:#6B21A8}
.mx-sb--n .mx-sb-n{color:#B45309}
.mx-sb--r .mx-sb-n{color:#047857}
.mx-sb-l{font-size:10.5px;font-weight:800;color:#1A3A5C;letter-spacing:.3px}
.mx-sb-d{font-size:9.5px;color:#64748B;font-weight:600;line-height:1.4}

.mx-sh{font-size:11px;font-weight:800;color:#475569;text-transform:uppercase;letter-spacing:1px;margin:14px 0 8px}
.mx-actions{font-size:12px;color:#1A3A5C;font-weight:600;padding-left:22px;margin:0 0 14px;line-height:1.7}
.mx-actions li{padding-left:4px}

.mx-ladder{display:flex;flex-direction:column;gap:10px}
.mx-step{padding:12px;background:#FFFBEB;border:1px solid #FDE68A;border-radius:10px;display:flex;flex-direction:column;gap:6px}
.mx-step-flow{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.mx-step-from,.mx-step-to{font-size:11.5px;font-weight:800;color:#1A3A5C;padding:3px 10px;border-radius:5px;background:#fff;border:1px solid #E8EDF5}
.mx-step-arr{color:#CA8A04;font-weight:900;font-size:13px}
.mx-step-t{font-size:13px;font-weight:900;color:#B45309;font-variant-numeric:tabular-nums}
.mx-step-d{font-size:11.5px;color:#475569;line-height:1.5}
.mx-step-c{font-size:10px;color:#94A3B8;font-style:italic}

.mx-pheic{display:flex;flex-direction:column;gap:8px;margin-bottom:14px}
.mx-pheic-row{display:flex;gap:10px;padding:10px 12px;background:#F8FAFC;border:1px solid #E8EDF5;border-radius:8px;align-items:flex-start}
.mx-pheic-n{width:22px;height:22px;border-radius:50%;background:#0F172A;color:#fff;font-size:11px;font-weight:900;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
.mx-pheic-t{display:block;font-size:13px;font-weight:800;color:#1A3A5C;line-height:1.3;margin-bottom:3px}
.mx-pheic-d{display:block;font-size:11.5px;color:#475569;line-height:1.4}

.mx-hist{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:4px}
.mx-hist li{display:flex;gap:12px;padding:6px 10px;background:#F8FAFC;border-radius:6px;font-size:12px}
.mx-hist-y{font-weight:800;color:#0F172A;font-variant-numeric:tabular-nums;width:36px;flex-shrink:0}
.mx-hist-n{color:#475569}

.mx-cite{margin-top:12px;padding-top:8px;border-top:1px dashed #E8EDF5;font-size:10px;color:#94A3B8;font-style:italic}

@media(min-width:500px){.mx-sec{max-width:540px;margin-left:auto;margin-right:auto}}
</style>
