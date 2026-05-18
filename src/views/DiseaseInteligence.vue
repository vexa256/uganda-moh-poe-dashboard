<template>
  <IonPage>

    <!-- ══════════════════════════════════════════════════════════════
         DARK ZONE — Header + Toolbar + Stats Strip + Tabs
         Per spec: linear-gradient(180deg, #070E1B, #0E1A2E)
    ══════════════════════════════════════════════════════════════════ -->
    <IonHeader :translucent="false" class="di-header">
      <IonToolbar style="--background:linear-gradient(180deg,#070E1B,#0E1A2E);--color:#EDF2FA;--border-width:0;">
        <IonButtons slot="start">
          <IonBackButton default-href="/home" text="" style="--color:#00B4FF;" aria-label="Go back" />
        </IonButtons>
        <IonTitle>
          <div class="di-title-wrap">
            <span class="di-eyebrow">WHO / IHR 2005</span>
            <span class="di-title-main">Disease Intelligence</span>
          </div>
        </IonTitle>
        <div slot="end" class="di-ver-badge">v3.0</div>
      </IonToolbar>

      <!-- Stats strip — dark zone, bright accent colors -->
      <div class="di-stats-strip">
        <div class="di-stat-item">
          <span class="di-stat-n">{{ diseases.length }}</span>
          <span class="di-stat-l">Diseases</span>
        </div>
        <div class="di-stat-sep"/>
        <div class="di-stat-item">
          <span class="di-stat-n" style="color:#FF3D71;">{{ tier1Count }}</span>
          <span class="di-stat-l">IHR Tier 1</span>
        </div>
        <div class="di-stat-sep"/>
        <div class="di-stat-item">
          <span class="di-stat-n" style="color:#FFB300;">{{ exposures.length }}</span>
          <span class="di-stat-l">Exposures</span>
        </div>
        <div class="di-stat-sep"/>
        <div class="di-stat-item">
          <span class="di-stat-n" style="color:#00E5FF;">{{ symptomCount }}</span>
          <span class="di-stat-l">Symptoms</span>
        </div>
        <div class="di-stat-sep"/>
        <div class="di-stat-item">
          <span class="di-stat-n" style="color:#00E676;">{{ overrideRules.length }}</span>
          <span class="di-stat-l">Override Rules</span>
        </div>
      </div>

      <!-- Tabs — dark zone -->
      <div class="di-tab-strip">
        <button
          v-for="tab in tabs" :key="tab.id"
          class="di-tab"
          :class="activeTab === tab.id && 'di-tab--on'"
          @click="activeTab = tab.id"
          :aria-label="tab.label + ' tab'"
        >
          {{ tab.icon }} {{ tab.label }}
          <span class="di-tab-cnt">{{ tab.count }}</span>
        </button>
      </div>
    </IonHeader>

    <!-- ══════════════════════════════════════════════════════════════
         LIGHT ZONE — IonContent (ALL content must be light)
         Per spec Part B: --background: linear-gradient(180deg, #EAF0FA 0%, #F2F5FB 40%, #E4EBF7 100%)
    ══════════════════════════════════════════════════════════════════ -->
    <IonContent
      :fullscreen="true"
      :scroll-y="true"
      style="--background:linear-gradient(180deg,#EAF0FA 0%,#F2F5FB 40%,#E4EBF7 100%);--color:#0B1A30;"
    >

      <!-- ── SEARCH + FILTER CHIPS ────────────────────────────────── -->
      <div v-if="activeTab==='diseases'" class="di-search-zone">
        <div class="di-search-bar">
          <svg viewBox="0 0 18 18" fill="none" stroke="#94A3B8" stroke-width="1.5" stroke-linecap="round" aria-hidden="true" style="width:16px;height:16px;flex-shrink:0;">
            <circle cx="7.5" cy="7.5" r="5"/><line x1="11.5" y1="11.5" x2="16" y2="16"/>
          </svg>
          <input
            class="di-search-input"
            v-model="searchQuery"
            placeholder="Search diseases, syndromes, symptoms…"
            aria-label="Search diseases"
          />
          <button v-if="searchQuery" class="di-search-clear" @click="searchQuery=''" aria-label="Clear">✕</button>
        </div>
        <div class="di-chips-row">
          <button
            v-for="f in filters" :key="f.id"
            class="di-chip"
            :class="activeFilter===f.id && 'di-chip--on'"
            @click="activeFilter = activeFilter===f.id ? null : f.id"
          >{{ f.label }} <em>{{ f.count }}</em></button>
        </div>
      </div>

      <!-- ── DISEASES TAB ──────────────────────────────────────────── -->
      <div v-if="activeTab==='diseases'" class="di-list">
        <div
          v-for="(d, i) in filteredDiseases" :key="d.id"
          class="di-card"
          :class="'di-card--' + tierKey(d)"
          :style="{ animationDelay: i*28+'ms' }"
          @click="openDisease(d)"
          role="button" tabindex="0" :aria-label="'Open '+d.name"
        >
          <!-- Per spec E.2: card shimmer highlight -->
          <div class="di-card-shimmer"/>
          <!-- Left accent bar -->
          <div class="di-card-accent" :class="'di-card-accent--' + tierKey(d)"/>

          <div class="di-card-body">
            <div class="di-card-top">
              <span class="di-tier-pill" :class="'di-tier-pill--' + tierKey(d)">{{ tierLabel(d) }}</span>
              <span class="di-alert-pill" :class="'di-alert-pill--' + (d.alert_level_if_top_ranked||'medium')">
                {{ (d.alert_level_if_top_ranked||'medium').toUpperCase() }}
              </span>
            </div>

            <div class="di-card-name">{{ d.name }}</div>

            <div class="di-card-syns">
              <span v-for="s in (d.syndromes||[]).slice(0,2)" :key="s" class="di-syn-tag">{{ s.replace(/_/g,' ') }}</span>
            </div>

            <div class="di-card-meta">
              <span class="di-meta-item">
                <span class="di-meta-k">CFR</span>
                <span class="di-meta-v" :class="cfrColor(d.case_fatality_rate_pct)">{{ d.case_fatality_rate_pct }}%</span>
              </span>
              <span class="di-meta-item">
                <span class="di-meta-k">Incubation</span>
                <span class="di-meta-v">{{ d.incubation_days?.min }}–{{ d.incubation_days?.max }}d</span>
              </span>
              <span class="di-meta-item">
                <span class="di-meta-k">Severity</span>
                <span class="di-meta-v">
                  <span v-for="n in 5" :key="n" class="di-sev-dot" :class="n<=(d.severity||1)&&'di-sev-dot--on'" />
                </span>
              </span>
            </div>

            <div v-if="overridesFor(d.id).length" class="di-override-tag">
              ⚡ {{ overridesFor(d.id).length }} triage override{{ overridesFor(d.id).length>1?'s':'' }}
            </div>
            <div v-if="d.idsr_source_ref" class="di-idsr-ref" :title="'Uganda IDSR Annex 1A anchor ' + d.idsr_source_ref">
              IDSR · Annex 1A · <code>{{ d.idsr_source_ref }}</code>
            </div>
          </div>
          <span class="di-card-chevron" aria-hidden="true">›</span>
        </div>

        <div v-if="filteredDiseases.length===0" class="di-empty">
          <div class="di-empty-icon">🔬</div>
          <div class="di-empty-title">No matches</div>
          <div class="di-empty-body">Try a different search term or clear the filter.</div>
        </div>
      </div>

      <!-- ── EXPOSURES TAB ─────────────────────────────────────────── -->
      <div v-if="activeTab==='exposures'" class="di-list">
        <div
          v-for="(exp, i) in exposures" :key="exp.code||exp.id"
          class="di-card"
          :style="{ animationDelay: i*22+'ms' }"
          @click="openExposure(exp)"
          role="button" tabindex="0"
        >
          <div class="di-card-shimmer"/>
          <div class="di-card-accent di-card-accent--exp"/>
          <div class="di-card-body">
            <div class="di-card-top">
              <span class="di-risk-pill" :class="'di-risk-pill--' + (exp.risk_level||'MODERATE').toLowerCase()">
                {{ exp.risk_level||'MODERATE' }}
              </span>
            </div>
            <div class="di-card-name">{{ exp.label }}</div>
            <div class="di-exp-code">{{ exp.code }}</div>
            <div class="di-exp-diseases">
              <span class="di-meta-k">Activates for:</span>
              <span v-for="pd in (exp.priority_diseases||[]).slice(0,3)" :key="pd" class="di-exp-dis-tag">{{ pd.replace(/_/g,' ') }}</span>
            </div>
            <div v-if="exp.engine_codes?.length" class="di-exp-codes">
              <span class="di-meta-k">Engine codes:</span>
              <code v-for="ec in exp.engine_codes" :key="ec" class="di-ec">{{ ec }}</code>
            </div>
          </div>
          <span class="di-card-chevron" aria-hidden="true">›</span>
        </div>
      </div>

      <!-- ── TRIAGE RULES TAB ──────────────────────────────────────── -->
      <div v-if="activeTab==='overrides'" class="di-list">
        <div
          v-for="(rule, i) in overrideRules" :key="rule.rule_id"
          class="di-card"
          :style="{ animationDelay: i*35+'ms' }"
        >
          <div class="di-card-shimmer"/>
          <div class="di-card-accent" :class="rule.effect?.force_alert_level==='critical'?'di-card-accent--t1':'di-card-accent--t2'"/>
          <div class="di-card-body" style="flex:1;">
            <div class="di-card-top">
              <span class="di-rule-num">RULE {{ rule.priority||i+1 }}</span>
              <span class="di-alert-pill" :class="'di-alert-pill--' + (rule.effect?.force_alert_level||'high')">
                {{ (rule.effect?.force_alert_level||'HIGH').toUpperCase() }}
              </span>
            </div>
            <div class="di-card-name" style="font-size:13px;">
              {{ rule.rule_id.replace(/override_/,'').replace(/_/g,' ').toUpperCase() }}
            </div>
            <div class="di-rule-desc">{{ rule.description }}</div>

            <div v-if="rule.when_all||rule.when_any" class="di-triggers-block">
              <div v-if="rule.when_all" class="di-trigger-row">
                <span class="di-trigger-k di-trigger-k--all">ALL:</span>
                <span v-for="s in rule.when_all" :key="s" class="di-trigger-sym">{{ symLabel(s) }}</span>
              </div>
              <div v-if="rule.when_any" class="di-trigger-row">
                <span class="di-trigger-k di-trigger-k--any">ANY:</span>
                <span v-for="s in rule.when_any" :key="s" class="di-trigger-sym">{{ symLabel(s) }}</span>
              </div>
              <div v-if="rule.and_any" class="di-trigger-row">
                <span class="di-trigger-k di-trigger-k--and">AND:</span>
                <span v-for="s in rule.and_any.slice(0,4)" :key="s" class="di-trigger-sym">{{ symLabel(s) }}</span>
                <span v-if="rule.and_any.length>4" class="di-trigger-more">+{{ rule.and_any.length-4 }}</span>
              </div>
            </div>

            <div v-if="rule.effect?.boost_diseases" class="di-boosts-block">
              <div class="di-boosts-title">Disease boosts:</div>
              <div v-for="(boost,did) in rule.effect.boost_diseases" :key="did" class="di-boost-row">
                <span class="di-boost-name">{{ diseaseName(did) }}</span>
                <span class="di-boost-val">+{{ boost }}</span>
                <div class="di-boost-track"><div class="di-boost-fill" :style="{width:Math.min(100,boost*1.8)+'%'}"/></div>
              </div>
            </div>

            <div v-if="rule.effect?.mandatory_actions?.length" class="di-actions-block">
              <div v-for="a in rule.effect.mandatory_actions" :key="a" class="di-or-action">
                🔴 {{ a.replace(/_/g,' ') }}
              </div>
            </div>
          </div>
        </div>
      </div>

      <div style="height:env(safe-area-inset-bottom,24px);"/>
    </IonContent>

    <!-- ══════════════════════════════════════════════════════════════
         DISEASE DETAIL MODAL — Full-screen, premium, fully scrollable
    ══════════════════════════════════════════════════════════════════ -->
    <IonModal
      :is-open="!!selectedDisease"
      :can-dismiss="true"
      @ionModalDidDismiss="selectedDisease=null; detailTab='overview'"
    >
      <IonHeader :translucent="false" v-if="selectedDisease" class="dm-header">
        <IonToolbar style="--background:linear-gradient(180deg,#070E1B,#0E1A2E);--color:#EDF2FA;--border-width:0;">
          <IonButtons slot="start">
            <IonButton @click="selectedDisease=null" aria-label="Close modal" class="dm-close-btn">
              <IonIcon :icon="closeOutline"/>
            </IonButton>
          </IonButtons>
          <IonTitle>
            <div class="dm-toolbar-title">
              <span class="dm-toolbar-eyebrow">DISEASE INTELLIGENCE — SENTINEL</span>
              <span class="dm-toolbar-name">{{ selectedDisease.name }}</span>
            </div>
          </IonTitle>
          <div slot="end" class="dm-header-tier" :class="'dm-tier--' + tierKey(selectedDisease)">
            {{ tierLabel(selectedDisease) }}
          </div>
        </IonToolbar>

        <!-- Detail tab strip — dark zone -->
        <div class="dm-tabs">
          <button
            v-for="dt in detailTabs" :key="dt.id"
            class="dm-tab"
            :class="detailTab===dt.id && 'dm-tab--on'"
            @click="detailTab=dt.id"
            :aria-label="dt.label + ' tab'"
          >
            <span class="dm-tab-icon">{{ dt.icon }}</span>
            {{ dt.label }}
          </button>
        </div>
      </IonHeader>

      <IonContent
        :scroll-y="true"
        v-if="selectedDisease"
        style="--background:linear-gradient(180deg,#EEF2FA 0%,#FFFFFF 50%,#F4F7FC 100%);--color:#0B1A30;"
      >

        <!-- ════════════════════════════════════════════════════════════
             CINEMATIC HERO — spans full width above the tab content
        ════════════════════════════════════════════════════════════════ -->
        <div class="dm-hero" :class="'dm-hero--' + tierKey(selectedDisease)">
          <!-- Grid texture overlay -->
          <div class="dm-hero-grid"/>
          <!-- Ambient glow orb -->
          <div class="dm-hero-orb" :class="'dm-hero-orb--' + tierKey(selectedDisease)"/>

          <div class="dm-hero-body">
            <div class="dm-hero-left">
              <div class="dm-hero-tier-badge" :class="'dm-tier--' + tierKey(selectedDisease)">
                {{ tierLabel(selectedDisease) }}
              </div>
              <h1 class="dm-hero-name">{{ selectedDisease.name }}</h1>
              <div class="dm-hero-category">{{ selectedDisease.who_category?.replace(/_/g,' ') }}</div>

              <!-- WHO syndrome tags -->
              <div class="dm-hero-syndromes">
                <span v-for="s in (selectedDisease.syndromes||[]).slice(0,3)" :key="s" class="dm-hero-syn">
                  {{ s.replace(/_/g,' ') }}
                </span>
              </div>
            </div>

            <!-- CFR ring display -->
            <div class="dm-hero-right">
              <div class="dm-cfr-ring" :class="'dm-cfr-ring--' + tierKey(selectedDisease)">
                <svg class="dm-cfr-svg" viewBox="0 0 72 72">
                  <circle cx="36" cy="36" r="30" class="dm-cfr-track"/>
                  <circle
                    cx="36" cy="36" r="30"
                    class="dm-cfr-arc"
                    :class="'dm-cfr-arc--' + tierKey(selectedDisease)"
                    :style="{strokeDashoffset: 188.5 - (Math.min(selectedDisease.case_fatality_rate_pct, 100)/100 * 188.5)}"
                  />
                </svg>
                <div class="dm-cfr-inner">
                  <span class="dm-cfr-n" :class="cfrColor(selectedDisease.case_fatality_rate_pct)">
                    {{ selectedDisease.case_fatality_rate_pct }}%
                  </span>
                  <span class="dm-cfr-l">CFR</span>
                </div>
              </div>
              <div class="dm-sev-strip">
                <span class="dm-sev-label">Severity</span>
                <div class="dm-sev-dots">
                  <span v-for="n in 5" :key="n" class="dm-sev-dot" :class="n<=(selectedDisease.severity||1) && 'dm-sev-dot--on'"/>
                </div>
              </div>
            </div>
          </div>

          <!-- POE Alert level bar -->
          <div class="dm-hero-alert" :class="'dm-hero-alert--' + (selectedDisease.alert_level_if_top_ranked||'medium')">
            <span class="dm-hero-alert-label">POE ALERT LEVEL WHEN RANKED #1</span>
            <span class="dm-hero-alert-val">{{ (selectedDisease.alert_level_if_top_ranked||'medium').toUpperCase() }}</span>
          </div>
        </div>

        <!-- ══ OVERVIEW TAB ══════════════════════════════════════════ -->
        <div v-if="detailTab==='overview'" class="dm-body">

          <!-- KPI row — 4 metric cards -->
          <div class="dm-kpi-row">
            <div class="dm-kpi" style="--kpi-glow:#E02050;">
              <div class="dm-kpi-blob"/>
              <div class="dm-kpi-shine"/>
              <div class="dm-kpi-val" :class="cfrColor(selectedDisease.case_fatality_rate_pct)">{{ selectedDisease.case_fatality_rate_pct }}%</div>
              <div class="dm-kpi-key">Case Fatality</div>
              <div class="dm-kpi-sub">Without treatment</div>
            </div>
            <div class="dm-kpi" style="--kpi-glow:#0070E0;">
              <div class="dm-kpi-blob"/>
              <div class="dm-kpi-shine"/>
              <div class="dm-kpi-val" style="color:#0070E0;">{{ selectedDisease.incubation_days?.min }}–{{ selectedDisease.incubation_days?.max }}d</div>
              <div class="dm-kpi-key">Incubation</div>
              <div class="dm-kpi-sub">Typical {{ selectedDisease.incubation_days?.typical }}d</div>
            </div>
            <div class="dm-kpi" style="--kpi-glow:#CC8800;">
              <div class="dm-kpi-blob"/>
              <div class="dm-kpi-shine"/>
              <div class="dm-kpi-val">
                <span v-for="n in 5" :key="n" class="dm-sev-sm" :class="n<=(selectedDisease.severity||1) && 'dm-sev-sm--on'"/>
              </div>
              <div class="dm-kpi-key">Severity</div>
              <div class="dm-kpi-sub">{{ severityLabel(selectedDisease.severity) }}</div>
            </div>
            <div class="dm-kpi" style="--kpi-glow:#00A86B;">
              <div class="dm-kpi-blob"/>
              <div class="dm-kpi-shine"/>
              <div class="dm-kpi-val" style="color:#00A86B;">+{{ selectedDisease.outbreak_bonus||15 }}</div>
              <div class="dm-kpi-key">Outbreak Bonus</div>
              <div class="dm-kpi-sub">Endemic country visit</div>
            </div>
          </div>

          <!-- IHR Classification block -->
          <div class="dm-section">
            <div class="dm-section-label">
              <div class="dm-section-glyph">🏛</div>
              IHR / WHO CLASSIFICATION
            </div>
            <div class="dm-ihr-card" :class="'dm-ihr-card--' + tierKey(selectedDisease)">
              <div class="dm-ihr-shine"/>
              <div class="dm-ihr-left">
                <div class="dm-ihr-tier-tag" :class="'dm-tier--' + tierKey(selectedDisease)">{{ tierLabel(selectedDisease) }}</div>
                <div class="dm-ihr-category">{{ selectedDisease.who_category?.replace(/_/g,' ') }}</div>
              </div>
              <div class="dm-ihr-right">
                <div class="dm-ihr-desc">{{ tierDescription(selectedDisease.priority_tier) }}</div>
                <div class="dm-ihr-basis">{{ selectedDisease.who_basis }}</div>
              </div>
            </div>
          </div>

          <!-- Uganda IDSR thresholds -->
          <div v-if="selectedDisease.idsr_category || selectedDisease.alert_threshold || selectedDisease.epidemic_threshold" class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">📐</div>RWANDA IDSR — ANNEX 1A THRESHOLDS</div>
            <div class="dm-idsr-card">
              <div class="dm-idsr-head">
                <span v-if="selectedDisease.idsr_category" class="dm-idsr-cat" :class="'dm-idsr-cat--' + selectedDisease.idsr_category">
                  {{ idsrCategoryLabel(selectedDisease.idsr_category) }}
                </span>
                <span v-if="selectedDisease.idsr_source_ref" class="dm-idsr-ref" :title="'Anchor ' + selectedDisease.idsr_source_ref">
                  Annex 1A · <code>{{ selectedDisease.idsr_source_ref }}</code>
                </span>
              </div>
              <div v-if="selectedDisease.alert_threshold" class="dm-idsr-row">
                <span class="dm-idsr-tag dm-idsr-tag--alert">ALERT</span>
                <span class="dm-idsr-text">{{ selectedDisease.alert_threshold }}</span>
              </div>
              <div v-if="selectedDisease.epidemic_threshold" class="dm-idsr-row">
                <span class="dm-idsr-tag dm-idsr-tag--epi">EPIDEMIC</span>
                <span class="dm-idsr-text">{{ selectedDisease.epidemic_threshold }}</span>
              </div>
              <div v-if="selectedDisease.case_definition?.suspected" class="dm-idsr-row">
                <span class="dm-idsr-tag dm-idsr-tag--def">CASE DEF</span>
                <span class="dm-idsr-text">{{ selectedDisease.case_definition.suspected }}</span>
              </div>
            </div>
          </div>

          <!-- WHO Syndrome Classification -->
          <div class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">🔬</div>WHO SYNDROME CLASSIFICATION</div>
            <div class="dm-syn-grid">
              <div v-for="s in (selectedDisease.syndromes||[])" :key="s" class="dm-syn-card">
                <div class="dm-syn-card-name">{{ s.replace(/_/g,' ') }}</div>
                <div class="dm-syn-card-sub">+{{ engineFormula?.syndrome_bonus_match||8 }}pts when matched</div>
              </div>
            </div>
            <div class="dm-info-note">ENGINE_TO_WHO_SYNDROME maps these to WHO output codes (VHF, SARI, AWD…). A match grants this disease an additional syndrome_bonus on every score calculation.</div>
          </div>

          <!-- WHO Case Definition — vertical timeline -->
          <div v-if="selectedDisease.who_case_definition" class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">📋</div>WHO CASE DEFINITION — AFRO IDSR 2021</div>
            <div class="dm-casedef-timeline">
              <div v-if="selectedDisease.who_case_definition.suspected" class="dm-casedef-step dm-casedef-step--suspected">
                <div class="dm-casedef-node">
                  <div class="dm-casedef-dot dm-casedef-dot--suspected"/>
                  <div class="dm-casedef-line"/>
                </div>
                <div class="dm-casedef-content">
                  <div class="dm-casedef-tier">SUSPECTED</div>
                  <div class="dm-casedef-text">{{ selectedDisease.who_case_definition.suspected }}</div>
                </div>
              </div>
              <div v-if="selectedDisease.who_case_definition.probable" class="dm-casedef-step dm-casedef-step--probable">
                <div class="dm-casedef-node">
                  <div class="dm-casedef-dot dm-casedef-dot--probable"/>
                  <div class="dm-casedef-line"/>
                </div>
                <div class="dm-casedef-content">
                  <div class="dm-casedef-tier">PROBABLE</div>
                  <div class="dm-casedef-text">{{ selectedDisease.who_case_definition.probable }}</div>
                </div>
              </div>
              <div v-if="selectedDisease.who_case_definition.confirmed" class="dm-casedef-step dm-casedef-step--confirmed">
                <div class="dm-casedef-node">
                  <div class="dm-casedef-dot dm-casedef-dot--confirmed"/>
                  <div class="dm-casedef-line"/>
                </div>
                <div class="dm-casedef-content">
                  <div class="dm-casedef-tier">CONFIRMED</div>
                  <div class="dm-casedef-text">{{ selectedDisease.who_case_definition.confirmed }}</div>
                </div>
              </div>
              <div v-if="selectedDisease.who_case_definition.ihr_category" class="dm-casedef-step dm-casedef-step--ihr">
                <div class="dm-casedef-node">
                  <div class="dm-casedef-dot dm-casedef-dot--ihr"/>
                </div>
                <div class="dm-casedef-content">
                  <div class="dm-casedef-tier">IHR FRAMEWORK</div>
                  <div class="dm-casedef-text">{{ selectedDisease.who_case_definition.ihr_category }}</div>
                </div>
              </div>
            </div>
            <div v-if="selectedDisease.who_case_definition.poe_action" class="dm-poe-action-block">
              <div class="dm-poe-action-shine"/>
              <div class="dm-poe-action-hdr">⚠ MANDATORY POE ACTION</div>
              <div class="dm-poe-action-text">{{ selectedDisease.who_case_definition.poe_action }}</div>
            </div>
          </div>

          <!-- Key Clinical Distinguishers -->
          <div v-if="selectedDisease.key_distinguishers?.length" class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">💡</div>KEY CLINICAL DISTINGUISHERS</div>
            <div class="dm-dist-list">
              <div v-for="(kd, i) in selectedDisease.key_distinguishers" :key="i" class="dm-dist-row">
                <div class="dm-dist-num">{{ i+1 }}</div>
                <div class="dm-dist-text">{{ kd }}</div>
              </div>
            </div>
          </div>

          <!-- Endemic Countries -->
          <div v-if="selectedDisease.endemic_countries?.length" class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">🌍</div>ENDEMIC COUNTRIES — OUTBREAK BONUS ORACLE</div>
            <div class="dm-endemic-banner">
              <div class="dm-endemic-banner-shine"/>
              <div class="dm-endemic-stat">
                <span class="dm-endemic-n">{{ selectedDisease.endemic_countries.length }}</span>
                <span class="dm-endemic-l">countries</span>
              </div>
              <div class="dm-endemic-sep"/>
              <div class="dm-endemic-stat">
                <span class="dm-endemic-n" style="color:#00A86B;">+{{ selectedDisease.outbreak_bonus||15 }}</span>
                <span class="dm-endemic-l">pts bonus</span>
              </div>
              <div class="dm-endemic-sep"/>
              <div class="dm-endemic-desc">Triggers via buildOutbreakContext() when traveller visited any of these countries</div>
            </div>
            <div class="dm-country-grid">
              <span v-for="cc in selectedDisease.endemic_countries" :key="cc" class="dm-country-chip">{{ cc }}</span>
            </div>
          </div>
        </div>

        <!-- ══ GATES & SCORING TAB ═══════════════════════════════════ -->
        <div v-if="detailTab==='scoring'" class="dm-body">

          <!-- Formula strip -->
          <div class="dm-formula-strip">
            <div class="dm-formula-shine"/>
            <div class="dm-formula-eyebrow">ENGINE SCORING FORMULA</div>
            <div class="dm-formula-eq">
              <span class="dm-formula-token dm-formula-token--gate">gate</span>
              <span class="dm-formula-op">+</span>
              <span class="dm-formula-token dm-formula-token--sym">symptoms</span>
              <span class="dm-formula-op">+</span>
              <span class="dm-formula-token dm-formula-token--exp">exposures</span>
              <span class="dm-formula-op">+</span>
              <span class="dm-formula-token dm-formula-token--syn">syndrome_bonus</span>
              <span class="dm-formula-op">+</span>
              <span class="dm-formula-token dm-formula-token--ob">outbreak_bonus</span>
              <span class="dm-formula-op">+</span>
              <span class="dm-formula-token dm-formula-token--vax">vaccination</span>
              <span class="dm-formula-op">+</span>
              <span class="dm-formula-token dm-formula-token--ons">onset</span>
              <span class="dm-formula-op">+</span>
              <span class="dm-formula-token dm-formula-token--neg">penalties</span>
              <span class="dm-formula-op">+</span>
              <span class="dm-formula-token dm-formula-token--ov">override</span>
            </div>
          </div>

          <!-- Gate rules — visual gate diagram -->
          <div class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">🚪</div>GATE RULES — MOST DETERMINISTIC LAYER</div>
            <div class="dm-gate-explain">Gates fire before symptom weights. A hard fail (−60 → clamped 0) eliminates this disease regardless of all other evidence. The gate swing is ±72 points — the most decisive single factor in the engine.</div>

            <div class="dm-gate-visual">
              <!-- Gate outcome chips at top -->
              <div class="dm-gate-outcomes-row">
                <div class="dm-gate-outcome dm-gate-outcome--pass">
                  <span class="dm-gate-outcome-val">+{{ engineFormula?.gate_pass_bonus||12 }}</span>
                  <span class="dm-gate-outcome-lbl">GATE PASS</span>
                </div>
                <div class="dm-gate-outcome dm-gate-outcome--soft">
                  <span class="dm-gate-outcome-val">{{ engineFormula?.gate_soft_fail_penalty||-18 }}</span>
                  <span class="dm-gate-outcome-lbl">SOFT FAIL</span>
                </div>
                <div class="dm-gate-outcome dm-gate-outcome--hard">
                  <span class="dm-gate-outcome-val">−60→0</span>
                  <span class="dm-gate-outcome-lbl">HARD FAIL</span>
                </div>
              </div>

              <!-- Required ALL gate -->
              <div v-if="selectedDisease.gates?.required_all?.length" class="dm-gate-block dm-gate-block--all">
                <div class="dm-gate-block-shine"/>
                <div class="dm-gate-block-header">
                  <div class="dm-gate-block-icon dm-gate-block-icon--all">ALL</div>
                  <div class="dm-gate-block-title">ALL REQUIRED</div>
                  <div class="dm-gate-block-penalty">soft-fail −18 if any missing</div>
                </div>
                <div class="dm-gate-sym-grid">
                  <div v-for="s in selectedDisease.gates.required_all" :key="s" class="dm-gate-sym dm-gate-sym--all">
                    <span class="dm-gate-sym-dot"/>{{ symLabel(s) }}
                  </div>
                </div>
              </div>

              <!-- Required ANY gate -->
              <div v-if="selectedDisease.gates?.required_any?.length" class="dm-gate-block dm-gate-block--any">
                <div class="dm-gate-block-shine"/>
                <div class="dm-gate-block-header">
                  <div class="dm-gate-block-icon dm-gate-block-icon--any">ANY</div>
                  <div class="dm-gate-block-title">ANY REQUIRED</div>
                  <div class="dm-gate-block-penalty">soft-fail −18 if none present</div>
                </div>
                <div class="dm-gate-sym-grid">
                  <div v-for="s in selectedDisease.gates.required_any" :key="s" class="dm-gate-sym dm-gate-sym--any">
                    <span class="dm-gate-sym-dot"/>{{ symLabel(s) }}
                  </div>
                </div>
              </div>

              <!-- Hard fail gate -->
              <div v-if="selectedDisease.gates?.hard_fail_if_absent?.length" class="dm-gate-block dm-gate-block--hard">
                <div class="dm-gate-block-shine"/>
                <div class="dm-gate-block-header">
                  <div class="dm-gate-block-icon dm-gate-block-icon--hard">0</div>
                  <div class="dm-gate-block-title">HARD FAIL IF ABSENT</div>
                  <div class="dm-gate-block-penalty">confirmed absent → score = 0</div>
                </div>
                <div class="dm-gate-sym-grid">
                  <div v-for="s in selectedDisease.gates.hard_fail_if_absent" :key="s" class="dm-gate-sym dm-gate-sym--hard">
                    <span class="dm-gate-sym-dot"/>{{ symLabel(s) }}
                  </div>
                </div>
              </div>

              <div v-if="!selectedDisease.gates?.required_all?.length && !selectedDisease.gates?.required_any?.length && !selectedDisease.gates?.hard_fail_if_absent?.length" class="dm-gate-none">
                No hard gate rules for this disease. All symptomatic presentations are evaluated via weighted scoring only.
              </div>
            </div>
          </div>

          <!-- Symptom weights — spectrum bars -->
          <div class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">⚖</div>SYMPTOM WEIGHTS — MANDELL 9TH ED. LR-CALIBRATED</div>
            <div class="dm-weight-legend">
              <span class="dm-wl-item dm-wl-item--path">≥35 Pathognomonic</span>
              <span class="dm-wl-item dm-wl-item--strong">20+ Very Strong</span>
              <span class="dm-wl-item dm-wl-item--mod">14+ Strong</span>
              <span class="dm-wl-item dm-wl-item--weak">8+ Moderate</span>
            </div>
            <div class="dm-sym-bars">
              <div
                v-for="([sym, wt], i) in sortedWeights(selectedDisease)"
                :key="sym"
                class="dm-sym-bar-row"
                :class="isHallmark(selectedDisease,sym) && 'dm-sym-bar-row--hm'"
                :style="{animationDelay: i*40+'ms'}"
              >
                <div class="dm-sym-bar-label">
                  <div class="dm-sym-bar-name">{{ symLabel(sym) }}</div>
                  <div v-if="isHallmark(selectedDisease,sym)" class="dm-hm-badge">HALLMARK</div>
                </div>
                <div class="dm-sym-bar-track">
                  <div
                    class="dm-sym-bar-fill"
                    :style="{width:(wt/maxSW(selectedDisease)*100)+'%', background:barColor(wt)}"
                  />
                  <div class="dm-sym-bar-glow" :style="{background:barColor(wt)}"/>
                </div>
                <div class="dm-sym-bar-val">+{{ wt }}</div>
              </div>
            </div>
          </div>

          <!-- Absent hallmark penalties -->
          <div v-if="Object.keys(selectedDisease.absent_hallmark_penalties||{}).length" class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">⬇</div>ABSENT HALLMARK PENALTIES</div>
            <div class="dm-penalty-banner">
              When officer confirms these symptoms ABSENT, the engine subtracts these specific penalties — in addition to the standard −{{ engineFormula?.absent_mandatory_hallmark_penalty||12 }}pt rule applied to any hallmark with weight ≥14.
            </div>
            <div class="dm-penalty-list">
              <div v-for="[sym, pen] in Object.entries(selectedDisease.absent_hallmark_penalties)" :key="sym" class="dm-penalty-row">
                <span class="dm-penalty-sym">{{ symLabel(sym) }}</span>
                <div class="dm-penalty-bar-wrap">
                  <div class="dm-penalty-bar" :style="{width: (Math.abs(pen)/20*100)+'%'}"/>
                </div>
                <span class="dm-penalty-val">{{ pen }}</span>
              </div>
            </div>
          </div>

          <!-- Contradiction penalties -->
          <div v-if="Object.keys(selectedDisease.negative_weights||{}).length" class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">✗</div>CONTRADICTION PENALTIES</div>
            <div class="dm-contra-banner">
              When PRESENT: score reduced by these amounts.<br/>
              When confirmed ABSENT: engine adds half the penalty as a positive signal (negative evidence reward = absence of contradicting evidence).
            </div>
            <div class="dm-contra-list">
              <div v-for="[sym, wt] in sortedNegWeights(selectedDisease)" :key="sym" class="dm-contra-row">
                <div class="dm-contra-sym">{{ symLabel(sym) }}</div>
                <div class="dm-contra-track">
                  <div class="dm-contra-fill" :style="{width: (Math.abs(wt)/20*100)+'%'}"/>
                </div>
                <div class="dm-contra-val">{{ wt }}</div>
              </div>
            </div>
          </div>

          <!-- Triage overrides -->
          <div v-if="overridesFor(selectedDisease.id).length" class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">⚡</div>TRIAGE OVERRIDE RULES AFFECTING THIS DISEASE</div>
            <div class="dm-override-intro">These deterministic rules fire BEFORE scoring and add a boost ON TOP of the symptom score. They implement WHO mandatory protocol triggers and cannot be overridden by any other engine logic.</div>
            <div class="dm-override-cards">
              <div v-for="rule in overridesFor(selectedDisease.id)" :key="rule.rule_id" class="dm-override-card">
                <div class="dm-override-card-shine"/>
                <div class="dm-override-card-top">
                  <div class="dm-override-card-id">{{ rule.rule_id.replace(/override_/,'').replace(/_/g,' ').toUpperCase() }}</div>
                  <div class="dm-override-card-boost">+{{ rule.effect?.boost_diseases?.[selectedDisease.id] }} pts</div>
                </div>
                <div class="dm-override-card-desc">{{ rule.description }}</div>
                <div class="dm-override-card-triggers">
                  <span class="dm-override-trigger-k" v-if="rule.when_all?.length">ALL:</span>
                  <span v-for="s in (rule.when_all||[])" :key="s" class="dm-override-sym dm-override-sym--all">{{ symLabel(s) }}</span>
                  <span class="dm-override-trigger-k" v-if="rule.when_any?.length">ANY:</span>
                  <span v-for="s in (rule.when_any||[]).slice(0,4)" :key="s" class="dm-override-sym dm-override-sym--any">{{ symLabel(s) }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Confidence bands — score spectrum -->
          <div class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">📊</div>CONFIDENCE BANDS & POE RESPONSE</div>
            <div class="dm-bands">
              <div v-for="b in confidenceBands" :key="b.band" class="dm-band">
                <div class="dm-band-color" :style="{background: b.color || '#94A3B8'}"/>
                <div class="dm-band-body">
                  <div class="dm-band-name">{{ (b.band||'').replace(/_/g,' ').toUpperCase() }}</div>
                  <div class="dm-band-score">Score ≥ {{ b.min_score }}</div>
                  <div class="dm-band-action">{{ (b.poe_action||'').replace(/_/g,' ') }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ══ EXPOSURES TAB ══════════════════════════════════════════ -->
        <div v-if="detailTab==='exposures'" class="dm-body">

          <div class="dm-exp-intro-card">
            <div class="dm-exp-intro-shine"/>
            <div class="dm-exp-intro-title">Epidemiological Context Layer</div>
            <div class="dm-exp-intro-text">Exposure signals are added AFTER symptom scoring. A single high-weight exposure (14–22pts) is equivalent to several moderate symptoms combined. The engine uses exposures to model the epidemiological context that the officer cannot directly observe during a 60-second primary screening.</div>
          </div>

          <div class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">🔍</div>EXPOSURE WEIGHTS</div>
            <div class="dm-sym-bars">
              <div
                v-for="([expId, wt], i) in sortedExpWeights(selectedDisease)"
                :key="expId"
                class="dm-sym-bar-row"
                :style="{animationDelay: i*40+'ms'}"
              >
                <div class="dm-sym-bar-label">
                  <div class="dm-sym-bar-name">{{ expLabel(expId) }}</div>
                </div>
                <div class="dm-sym-bar-track">
                  <div class="dm-sym-bar-fill dm-sym-bar-fill--exp" :style="{width:(wt/25*100)+'%'}"/>
                  <div class="dm-sym-bar-glow dm-sym-bar-glow--exp"/>
                </div>
                <div class="dm-sym-bar-val">+{{ wt }}</div>
              </div>
            </div>
          </div>

          <div v-if="Object.keys(selectedDisease.vaccination_modifiers||{}).length" class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">💉</div>VACCINATION SCORE MODIFIERS</div>
            <div class="dm-vax-note">Valid vaccination documentation reduces the score, modelling the protective effect of immunity. Applied after symptom + exposure scoring is complete.</div>
            <div class="dm-vax-cards">
              <div v-for="[vaccine, states] in Object.entries(selectedDisease.vaccination_modifiers)" :key="vaccine" class="dm-vax-card">
                <div class="dm-vax-card-shine"/>
                <div class="dm-vax-name">{{ vaccine.replace(/_/g,' ').toUpperCase() }} VACCINE</div>
                <div class="dm-vax-states">
                  <div v-for="[status, mod] in Object.entries(states)" :key="status" class="dm-vax-state">
                    <span class="dm-vax-status">{{ status.replace(/_/g,' ') }}</span>
                    <span class="dm-vax-mod" :class="mod<0?'dm-vax-mod--good':'dm-vax-mod--bad'">{{ mod>0?'+':''}}{{ mod }} pts</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div v-if="Object.keys(selectedDisease.onset_modifiers||{}).length" class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">⏱</div>ONSET TIME MODIFIERS</div>
            <div class="dm-onset-note">When days_since_onset falls within the disease's expected incubation window, the engine adds these points — exploiting the temporal relationship between exposure and symptom onset to sharpen the differential.</div>
            <div class="dm-onset-cards">
              <div v-for="[period, mod] in Object.entries(selectedDisease.onset_modifiers)" :key="period" class="dm-onset-card">
                <div class="dm-onset-period">{{ period.replace(/days_since_onset_/,'').replace(/_to_/,'–').replace(/_or_more/,'+ days').replace(/_/g,' ') }}</div>
                <div class="dm-onset-mod">+{{ mod }} pts</div>
              </div>
            </div>
          </div>
        </div>

        <!-- ══ ACTIONS TAB ════════════════════════════════════════════ -->
        <div v-if="detailTab==='actions'" class="dm-body">

          <!-- Immediate actions — numbered list -->
          <div class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">🚨</div>IMMEDIATE POE ACTIONS</div>
            <div class="dm-actions-list">
              <div v-for="(a, i) in (selectedDisease.immediate_actions||[])" :key="i"
                class="dm-action-card"
                :style="{animationDelay: i*60+'ms'}"
              >
                <div class="dm-action-card-shine"/>
                <div class="dm-action-num">{{ i+1 }}</div>
                <div class="dm-action-text">{{ a }}</div>
              </div>
            </div>
          </div>

          <!-- Lab tests -->
          <div class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">🧪</div>RECOMMENDED LABORATORY TESTS</div>
            <div class="dm-lab-notice">
              <div class="dm-lab-notice-shine"/>
              <strong>WHO AFRO IDSR Three-Tier System:</strong> This engine operates at the SUSPECTED tier only. Laboratory confirmation is required to advance to PROBABLE or CONFIRMED classification. Results do not affect the engine's output — they confirm or rule out the suspicion post-screening.
            </div>
            <div class="dm-test-list">
              <div v-for="(t, i) in (selectedDisease.recommended_tests||[])" :key="i" class="dm-test-card" :style="{animationDelay: i*50+'ms'}">
                <div class="dm-test-ic">🔬</div>
                <div class="dm-test-text">{{ t }}</div>
              </div>
            </div>
          </div>

          <!-- System behaviour -->
          <div class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">⚙</div>SYSTEM BEHAVIOUR WHEN THIS DISEASE RANKS #1</div>
            <div class="dm-sys-cards">
              <div class="dm-sys-card dm-sys-card--alert">
                <div class="dm-sys-card-key">Alert Level Forced To</div>
                <div class="dm-sys-card-val" :class="'dm-sys-alert--' + (selectedDisease.alert_level_if_top_ranked||'medium')">
                  {{ (selectedDisease.alert_level_if_top_ranked||'medium').toUpperCase() }}
                </div>
              </div>
              <div class="dm-sys-card">
                <div class="dm-sys-card-key">IHR Routing</div>
                <div class="dm-sys-card-val">{{ tierRoutingLabel(selectedDisease.priority_tier) }}</div>
              </div>
              <div class="dm-sys-card">
                <div class="dm-sys-card-key">IHR Notification Obligation</div>
                <div class="dm-sys-card-val">{{ ihrText(selectedDisease.priority_tier) }}</div>
              </div>
              <div class="dm-sys-card">
                <div class="dm-sys-card-key">Notification Score Threshold</div>
                <div class="dm-sys-card-val">Score ≥25 → NEEDS_IHR_NOTIFICATION flag fires</div>
              </div>
              <div v-if="overridesFor(selectedDisease.id).length" class="dm-sys-card">
                <div class="dm-sys-card-key">Active Override Rules</div>
                <div class="dm-sys-card-val">{{ overridesFor(selectedDisease.id).map(r=>r.rule_id.replace(/override_/,'').replace(/_/g,' ')).join(' · ') }}</div>
              </div>
            </div>
          </div>

          <!-- WHO basis -->
          <div class="dm-section">
            <div class="dm-section-label"><div class="dm-section-glyph">📖</div>WHO / IHR LEGAL BASIS</div>
            <div class="dm-basis-card">
              <div class="dm-basis-card-shine"/>
              <div class="dm-basis-text">{{ selectedDisease.who_basis }}</div>
            </div>
          </div>
        </div>

        <div style="height:max(env(safe-area-inset-bottom,0px),32px);"/>
      </IonContent>
    </IonModal>

    <!-- EXPOSURE DETAIL MODAL -->
    <IonModal :is-open="!!selectedExposure" :can-dismiss="true" @ionModalDidDismiss="selectedExposure=null">
      <IonHeader :translucent="false" v-if="selectedExposure">
        <IonToolbar style="--background:linear-gradient(180deg,#070E1B,#0E1A2E);--color:#EDF2FA;--border-width:0;">
          <IonButtons slot="start">
            <IonButton @click="selectedExposure=null" aria-label="Close">
              <IonIcon :icon="closeOutline" style="color:#00B4FF;"/>
            </IonButton>
          </IonButtons>
          <IonTitle>
            <div class="di-modal-title-wrap">
              <span class="di-modal-title-main">{{ selectedExposure.label }}</span>
              <span class="di-eyebrow">Exposure Signal</span>
            </div>
          </IonTitle>
        </IonToolbar>
      </IonHeader>
      <IonContent :scroll-y="true" style="--background:linear-gradient(180deg,#EEF2FA 0%,#FFFFFF 50%,#F4F7FC 100%);--color:#0B1A30;" v-if="selectedExposure">
        <div class="di-tab-body">
          <div class="di-alert-banner" :class="'di-alert-banner--' + riskBanner(selectedExposure.risk_level)">
            <span class="di-alert-k">RISK LEVEL</span>
            <span class="di-alert-v">{{ selectedExposure.risk_level||'MODERATE' }}</span>
          </div>

          <div class="di-section">
            <div class="di-section-hdr"><div class="di-section-glyph">ℹ</div><span class="di-section-title">Catalog Details</span></div>
            <div class="di-sys-table">
              <div class="di-sys-row"><span class="di-sys-k">Code</span><code class="di-code-val">{{ selectedExposure.code }}</code></div>
              <div class="di-sys-row"><span class="di-sys-k">Lookback window</span><span class="di-sys-v">{{ selectedExposure.lookback_days||'—' }} days</span></div>
              <div class="di-sys-row"><span class="di-sys-k">Category</span><span class="di-sys-v">{{ (selectedExposure.category||'—').replace(/_/g,' ') }}</span></div>
            </div>
          </div>

          <div v-if="selectedExposure.description" class="di-section">
            <div class="di-section-hdr"><div class="di-section-glyph">📝</div><span class="di-section-title">Clinical Context</span></div>
            <div class="di-who-basis">{{ selectedExposure.description }}</div>
          </div>

          <div v-if="selectedExposure.engine_codes?.length" class="di-section">
            <div class="di-section-hdr"><div class="di-section-glyph">⚙</div><span class="di-section-title">Engine Codes Activated</span></div>
            <div class="di-note-box">When this exposure is answered YES, these engine signal codes are passed to scoreDiseases(). Each maps to exposure_weights entries in the disease definitions.</div>
            <div class="di-ec-wrap">
              <code v-for="ec in selectedExposure.engine_codes" :key="ec" class="di-ec-large">{{ ec }}</code>
            </div>
          </div>

          <div v-if="selectedExposure.priority_diseases?.length" class="di-section">
            <div class="di-section-hdr"><div class="di-section-glyph">🦠</div><span class="di-section-title">Most Relevant Diseases</span></div>
            <div v-for="dId in selectedExposure.priority_diseases" :key="dId" class="di-exp-dis-row" @click="openDiseaseById(dId)" role="button" tabindex="0">
              <span class="di-exp-dis-name">{{ diseaseName(dId) }}</span>
              <span class="di-exp-dis-arrow">›</span>
            </div>
          </div>

          <div v-if="selectedExposure.critical_flag&&selectedExposure.critical_message" class="di-section">
            <div class="di-poe-action">
              <div class="di-poe-action-hdr">⚠ CRITICAL FLAG</div>
              <div class="di-poe-action-text">{{ selectedExposure.critical_message }}</div>
            </div>
          </div>

          <div v-if="selectedExposure.screening_questions?.length" class="di-section">
            <div class="di-section-hdr"><div class="di-section-glyph">❓</div><span class="di-section-title">Screening Questions</span></div>
            <div v-for="(q,i) in selectedExposure.screening_questions" :key="i" class="di-sq-row">
              <span class="di-sq-num">Q{{ i+1 }}</span><span class="di-sq-text">{{ q }}</span>
            </div>
          </div>
        </div>
        <div style="height:env(safe-area-inset-bottom,24px);"/>
      </IonContent>
    </IonModal>

  </IonPage>
</template>

<script setup>
/**
 * DiseaseIntelligence.vue
 * Route: /disease-intelligence
 *
 * READ-ONLY reference view for all 42 diseases + 26 exposures + 10 triage rules.
 * Data source: window.DISEASES (Diseases.js + Diseases_intelligence.js)
 *              window.EXPOSURES (exposures.js)
 *
 * Zero network calls. Zero poeDB. Zero auth required.
 * Fully offline — reference data lives on device.
 */
import { ref, computed, onMounted, nextTick } from 'vue'
import {
  IonPage, IonHeader, IonToolbar, IonTitle, IonContent,
  IonButtons, IonButton, IonBackButton, IonIcon, IonModal,
} from '@ionic/vue'
import { onIonViewDidEnter } from '@ionic/vue'
import { closeOutline } from 'ionicons/icons'

const diseases      = ref([])
const exposures     = ref([])
const overrideRules = ref([])
const symptomCount  = ref(0)

const activeTab      = ref('diseases')
const searchQuery    = ref('')
const activeFilter   = ref(null)
const selectedDisease  = ref(null)
const selectedExposure = ref(null)
const detailTab      = ref('overview')

const detailTabs = [
  { id:'overview',  icon:'🦠', label:'Overview'        },
  { id:'scoring',   icon:'⚖',  label:'Gates & Scoring' },
  { id:'exposures', icon:'🔍', label:'Exposures'       },
  { id:'actions',   icon:'🚨', label:'Actions'         },
]

function loadData() {
  const D = window.DISEASES
  if (D) {
    diseases.value     = D.diseases || []
    overrideRules.value= (D.engine?.triage_overrides||[]).filter(r=>!r.applies_to_tiers)
    symptomCount.value = (D.symptom_catalog||[]).length
  }
  const E = window.EXPOSURES
  if (E?.getAll) {
    exposures.value = E.getAll()
  } else if (window.DISEASES?.exposure_catalog) {
    exposures.value = window.DISEASES.exposure_catalog.map(e=>({
      code:e.id, label:e.label, risk_level:'MODERATE', engine_codes:[], priority_diseases:[],
    }))
  }
}
onMounted(async () => { await nextTick(); loadData() })
onIonViewDidEnter(()  => { loadData() })

const tabs = computed(() => [
  { id:'diseases',  icon:'🦠', label:'Diseases',     count: diseases.value.length     },
  { id:'exposures', icon:'🔍', label:'Exposures',    count: exposures.value.length    },
  { id:'overrides', icon:'⚡', label:'Triage Rules', count: overrideRules.value.length},
])

const tier1Count = computed(() =>
  diseases.value.filter(x => x.priority_tier === 'tier_1_ihr_critical').length
)

const filters = computed(() => {
  const d = diseases.value
  return [
    { id:'tier1',    label:'IHR Tier 1',    count: tier1Count.value },
    { id:'tier2',    label:'IHR Tier 2',    count: d.filter(x=>x.priority_tier==='tier_2_ihr_annex2').length        },
    { id:'critical', label:'Critical Alert', count: d.filter(x=>x.alert_level_if_top_ranked==='critical').length    },
    { id:'vhf',      label:'VHF',           count: d.filter(x=>(x.syndromes||[]).includes('vhf')).length           },
    { id:'neuro',    label:'Neurological',  count: d.filter(x=>(x.syndromes||[]).some(s=>s.includes('neuro')||s.includes('encephal'))).length },
    { id:'idsr_pheic',       label:'IDSR · PHEIC',       count: d.filter(x=>x.idsr_category==='pheic').length },
    { id:'idsr_epidemic',    label:'IDSR · Epidemic-prone', count: d.filter(x=>x.idsr_category==='epidemic_prone').length },
    { id:'idsr_eradication', label:'IDSR · Eradication',  count: d.filter(x=>x.idsr_category==='eradication_elimination').length },
    { id:'idsr_other',       label:'IDSR · Other major',  count: d.filter(x=>x.idsr_category==='other_major_public_health').length },
  ]
})

const filteredDiseases = computed(() => {
  let list = diseases.value
  if (activeFilter.value) {
    const f = activeFilter.value
    if (f==='tier1')    list=list.filter(d=>d.priority_tier==='tier_1_ihr_critical')
    if (f==='tier2')    list=list.filter(d=>d.priority_tier==='tier_2_ihr_annex2')
    if (f==='critical') list=list.filter(d=>d.alert_level_if_top_ranked==='critical')
    if (f==='vhf')      list=list.filter(d=>(d.syndromes||[]).includes('vhf'))
    if (f==='neuro')    list=list.filter(d=>(d.syndromes||[]).some(s=>s.includes('neuro')||s.includes('encephal')))
    if (f==='idsr_pheic')       list=list.filter(d=>d.idsr_category==='pheic')
    if (f==='idsr_epidemic')    list=list.filter(d=>d.idsr_category==='epidemic_prone')
    if (f==='idsr_eradication') list=list.filter(d=>d.idsr_category==='eradication_elimination')
    if (f==='idsr_other')       list=list.filter(d=>d.idsr_category==='other_major_public_health')
  }
  if (searchQuery.value.trim()) {
    // Allow searches with spaces — symptom keys are snake_case in the catalog
    // (e.g. "sore_throat") so a user typing "sore throat" must match.
    const q     = searchQuery.value.toLowerCase().trim()
    const qSnak = q.replace(/\s+/g, '_')
    const norm  = (s) => String(s || '').toLowerCase().replace(/_/g, ' ')
    list = list.filter(d =>
      norm(d.name).includes(q) || norm(d.id).includes(q) ||
      (d.syndromes||[]).some(s => norm(s).includes(q)) ||
      (d.key_distinguishers||[]).some(k => norm(k).includes(q)) ||
      Object.keys(d.symptom_weights||{}).some(s => s.includes(qSnak) || norm(s).includes(q))
    )
  }
  return list
})

const engineFormula   = computed(()=>window.DISEASES?.engine?.formula||{})
const confidenceBands = computed(()=>window.DISEASES?.engine?.normalization?.confidence_bands||[])

// ── Helpers ───────────────────────────────────────────────────────────────
function tierKey(d) {
  if (!d) return 'tier4'
  const map = { 'tier_1_ihr_critical':'tier1','tier_2_ihr_annex2':'tier2','tier_2_ihr_equivalent':'tier2','tier_3_who_notifiable':'tier3' }
  return map[d.priority_tier]||'tier4'
}
function tierLabel(d) {
  const map = { 'tier_1_ihr_critical':'IHR TIER 1','tier_2_ihr_annex2':'IHR TIER 2','tier_2_ihr_equivalent':'WHO PRIORITY','tier_3_who_notifiable':'WHO NOTIFIABLE','tier_4_syndromic':'SYNDROMIC' }
  return map[d?.priority_tier]||'SYNDROMIC'
}
function tierDescription(tier) {
  const map = {
    'tier_1_ihr_critical':  'Single confirmed case = PHEIC. WHO notification within 24h — legally required under IHR 2005.',
    'tier_2_ihr_annex2':    'IHR Annex 2 epidemic-prone disease. Apply four-question decision instrument. Notify National IHR Focal Point if ≥2 YES answers.',
    'tier_2_ihr_equivalent':'WHO AFRO Priority 1 disease. Equivalent IHR obligations apply.',
    'tier_3_who_notifiable':'National reporting required. Document in IDSR system within 48h.',
    'tier_4_syndromic':     'Important travel-medicine differential. Standard POE precautions.',
  }
  return map[tier]||tier
}
function cfrColor(cfr) {
  if (cfr>=30)  return 'di-cfr--extreme'
  if (cfr>=10)  return 'di-cfr--high'
  if (cfr>=1)   return 'di-cfr--medium'
  return 'di-cfr--low'
}
function cfrGlow(cfr) {
  if (cfr>=30)  return '#E02050'
  if (cfr>=10)  return '#CC8800'
  if (cfr>=1)   return '#0070E0'
  return '#00A86B'
}
function severityLabel(s) {
  return ({1:'Mild',2:'Moderate',3:'Significant',4:'Severe',5:'Extreme / life-threatening'})[s]||'Unknown'
}
function symLabel(id) {
  const c = window.DISEASES?.symptom_catalog||[]
  return c.find(x=>x.id===id)?.label||id?.replace(/_/g,' ')||'?'
}
function expLabel(id) {
  const c = window.DISEASES?.exposure_catalog||[]
  return c.find(x=>x.id===id)?.label||id?.replace(/_/g,' ')||'?'
}
function diseaseName(id) {
  return diseases.value.find(x=>x.id===id)?.name||id?.replace(/_/g,' ')||'?'
}
function idsrCategoryLabel(cat) {
  return ({
    pheic: 'PHEIC',
    epidemic_prone: 'Epidemic-prone',
    eradication_elimination: 'Eradication / Elimination',
    other_major_public_health: 'Other major public health',
  })[cat] || cat
}
function isHallmark(d,sym) { return (d.hallmarks||[]).includes(sym) }
function maxSW(d) { return Math.max(...Object.values(d.symptom_weights||{}),1) }
function barColor(wt) {
  if (wt>=35) return 'linear-gradient(90deg,#B01840,#E02050)'
  if (wt>=20) return 'linear-gradient(90deg,#CC8800,#FFB300)'
  if (wt>=14) return 'linear-gradient(90deg,#0055CC,#0070E0)'
  if (wt>=8)  return 'linear-gradient(90deg,#007A50,#00A86B)'
  return 'linear-gradient(90deg,#475569,#64748B)'
}
function sortedWeights(d)    { return Object.entries(d.symptom_weights||{}).sort((a,b)=>b[1]-a[1]) }
function sortedNegWeights(d) { return Object.entries(d.negative_weights||{}).sort((a,b)=>a[1]-b[1]) }
function sortedExpWeights(d) { return Object.entries(d.exposure_weights||{}).sort((a,b)=>b[1]-a[1]) }
function overridesFor(id)    { return overrideRules.value.filter(r=>r.effect?.boost_diseases?.[id]) }
function tierRoutingLabel(t) {
  if (t==='tier_1_ihr_critical') return 'NATIONAL → WHO (24h mandatory)'
  if (t==='tier_2_ihr_annex2')   return 'PHEOC or NATIONAL (Annex 2 instrument)'
  return 'DISTRICT → PHEOC (standard IDSR)'
}
function ihrText(t) {
  if (t==='tier_1_ihr_critical') return 'Mandatory — any confirmed case = PHEIC. Notify WHO ≤24h.'
  if (t==='tier_2_ihr_annex2')   return 'Required if four-question Annex 2 test returns ≥2 YES.'
  return 'Report through national IDSR channel within 48h.'
}
function riskBanner(level) {
  if (level==='VERY_HIGH'||level==='HIGH') return 'critical'
  if (level==='MODERATE') return 'high'
  return 'medium'
}

function openDisease(d)  { selectedDisease.value=d; detailTab.value='overview' }
function openExposure(e) { selectedExposure.value=e }
function openDiseaseById(id) {
  const d = diseases.value.find(x=>x.id===id)
  if (d) { selectedExposure.value=null; setTimeout(()=>openDisease(d),150) }
}
</script>

<style scoped>

/* ═══════════════════════════════════════════════════════════════════════
   ECSA-HC SENTINEL — DISEASE INTELLIGENCE
   UI/UX MASTER SPEC v5.0 — FULL DUAL-TONE COMPLIANCE
   DARK ZONE  : headers · toolbars · tab bars · stats strip
   LIGHT ZONE : all content · cards · modals · forms
   EFFECTS    : shimmer · data-stream · pulse · stagger · scan · glow
═══════════════════════════════════════════════════════════════════════ */

/* ── Keyframes ──────────────────────────────────────────────────────── */
@keyframes slideUp   { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
@keyframes shimmer   { 0%{background-position:-200% 0} 100%{background-position:200% 0} }
@keyframes stream    { 0%{transform:translateX(-100%)} 100%{transform:translateX(350%)} }
@keyframes scan      { 0%{left:-60%} 100%{left:150%} }
@keyframes pulse-red { 0%,100%{box-shadow:0 0 6px rgba(224,32,80,.35)} 50%{box-shadow:0 0 16px rgba(224,32,80,.7)} }
@keyframes pulse-amb { 0%,100%{box-shadow:0 0 5px rgba(204,136,0,.3)} 50%{box-shadow:0 0 13px rgba(204,136,0,.6)} }
@keyframes pulse-blu { 0%,100%{box-shadow:0 0 5px rgba(0,112,224,.3)} 50%{box-shadow:0 0 13px rgba(0,112,224,.6)} }
@keyframes fadeIn    { from{opacity:0} to{opacity:1} }
@keyframes barsGrow  { from{width:0} to{width:var(--bar-w)} }
@keyframes dotGlow   { 0%,100%{opacity:.5} 50%{opacity:1} }
@media (prefers-reduced-motion:reduce) { *,*::before,*::after{ animation-duration:.01ms!important; transition-duration:.01ms!important } }

/* ── DARK ZONE: IonHeader shell ─────────────────────────────────────── */
.di-header { --background: linear-gradient(180deg,#070E1B,#0E1A2E); }

/* ── DARK ZONE: Stats ribbon ─────────────────────────────────────────── */
.di-stats-strip {
  display: flex; align-items: center;
  background: linear-gradient(180deg, #0E1A2E 0%, #142640 100%);
  padding: 10px 16px;
  border-bottom: 1px solid rgba(255,255,255,.05);
  position: relative; overflow: hidden;
}
/* Grid texture on dark zone */
.di-stats-strip::before {
  content:''; position:absolute; inset:0; pointer-events:none;
  background-image:
    linear-gradient(rgba(0,180,255,.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,180,255,.03) 1px, transparent 1px);
  background-size: 32px 32px;
  mask-image: linear-gradient(180deg, black 50%, transparent 100%);
}
.di-stat-item { display:flex; flex-direction:column; align-items:center; flex:1; gap:2px; position:relative; z-index:1; }
.di-stat-n    { font-size:20px; font-weight:900; color:#EDF2FA; line-height:1; font-family:'Syne',sans-serif; }
.di-stat-l    { font-size:8px; font-weight:700; color:#7E92AB; letter-spacing:.8px; text-transform:uppercase; font-family:'DM Sans',sans-serif; }
.di-stat-sep  { width:1px; height:30px; background:rgba(255,255,255,.06); margin:0 4px; }

/* ── DARK ZONE: Tab strip ────────────────────────────────────────────── */
.di-tab-strip {
  display:flex; overflow-x:auto; scrollbar-width:none;
  background: linear-gradient(0deg, #070E1B 0%, #0E1A2E 100%);
  border-bottom: 1px solid rgba(255,255,255,.06);
}
.di-tab-strip::-webkit-scrollbar { display:none; }
.di-tab {
  flex:1; display:flex; align-items:center; justify-content:center; gap:5px;
  padding:11px 8px; border:none; background:transparent;
  color:#4A5874; font-size:10px; font-weight:700; letter-spacing:.5px;
  cursor:pointer; border-bottom:2px solid transparent;
  transition:color .18s, border-color .18s, background .18s;
  white-space:nowrap; font-family:'DM Sans',sans-serif; min-width:44px; min-height:44px;
}
.di-tab--on { color:#00B4FF; border-bottom-color:#00B4FF; background:rgba(0,180,255,.06); }
.di-tab-cnt {
  background:rgba(255,255,255,.08); border-radius:10px;
  padding:1px 6px; font-size:8px; font-weight:900;
  font-family:'JetBrains Mono',monospace;
}
.di-tab--on .di-tab-cnt { background:rgba(0,180,255,.18); color:#00B4FF; }

/* ── DARK ZONE: Title ───────────────────────────────────────────────── */
.di-title-wrap { display:flex; flex-direction:column; align-items:flex-start; gap:1px; }
.di-eyebrow    { font-size:8px; font-weight:700; letter-spacing:1.4px; color:#7E92AB; text-transform:uppercase; font-family:'DM Sans',sans-serif; }
.di-title-main { font-size:17px; font-weight:800; color:#EDF2FA; font-family:'Syne',sans-serif; line-height:1.1; }
.di-ver-badge  { font-size:9px; font-weight:700; padding:3px 8px; border-radius:5px; background:rgba(0,180,255,.1); color:#00B4FF; border:1px solid rgba(0,180,255,.2); font-family:'JetBrains Mono',monospace; margin-right:4px; }

/* ═══════════════════════════════════════════════════════════════════════
   LIGHT ZONE — All content surfaces
═══════════════════════════════════════════════════════════════════════ */

/* ── Search zone ─────────────────────────────────────────────────────── */
.di-search-zone { padding:14px 14px 4px; }

.di-search-bar {
  display:flex; align-items:center; gap:10px;
  background: linear-gradient(145deg, #FFFFFF 0%, #F4F7FC 100%);
  border:1.5px solid rgba(0,0,0,.06);
  border-radius:14px; padding:0 14px;
  box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  margin-bottom:10px; position:relative; overflow:hidden;
  transition:border-color .2s, box-shadow .2s;
}
/* Scan-line animation on search */
.di-search-bar::after {
  content:''; position:absolute; top:0; left:-60%; width:50%; height:100%;
  background:linear-gradient(90deg,transparent,rgba(0,112,224,.04),transparent);
  animation:scan 5s ease-in-out infinite; pointer-events:none;
}
.di-search-bar:focus-within {
  border-color:rgba(0,112,224,.35);
  box-shadow:0 0 0 3px rgba(0,112,224,.08), 0 4px 20px rgba(0,30,80,.08);
}
/* Top shimmer highlight on search bar */
.di-search-bar::before {
  content:''; position:absolute; top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.9) 50%,transparent 80%);
  pointer-events:none;
}
.di-search-input {
  flex:1; background:transparent; border:none; color:#0B1A30;
  font-size:16px; padding:12px 0; outline:none;
  font-family:'DM Sans',sans-serif;
}
.di-search-input::placeholder { color:#94A3B8; }
.di-search-clear {
  background:none; border:none; color:#94A3B8; cursor:pointer;
  font-size:13px; padding:4px; min-width:28px; min-height:28px;
  display:flex; align-items:center; justify-content:center;
  border-radius:50%; transition:background .15s;
}
.di-search-clear:hover { background:rgba(0,0,0,.06); }

/* Filter chips */
.di-chips-row { display:flex; gap:6px; overflow-x:auto; scrollbar-width:none; padding-bottom:2px; }
.di-chips-row::-webkit-scrollbar { display:none; }
.di-chip {
  display:flex; align-items:center; gap:5px;
  padding:7px 13px; border-radius:22px;
  background:linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border:1.5px solid rgba(0,0,0,.06);
  color:#475569; font-size:11px; font-weight:600;
  cursor:pointer; white-space:nowrap;
  box-shadow:0 1px 3px rgba(0,0,0,.03);
  transition:all .18s cubic-bezier(.16,1,.3,1);
  font-family:'DM Sans',sans-serif; min-height:36px;
}
.di-chip em { font-style:normal; background:rgba(0,0,0,.07); border-radius:8px; padding:0 6px; font-size:9px; font-weight:700; font-family:'JetBrains Mono',monospace; }
.di-chip--on {
  background:linear-gradient(135deg, #E0ECFF, #D0E0FF);
  border-color:rgba(0,112,224,.3); color:#0070E0;
  box-shadow:0 2px 10px rgba(0,112,224,.15);
}
.di-chip--on em { background:rgba(0,112,224,.15); color:#0070E0; }

/* ── LIGHT ZONE: Disease / Exposure / Override cards ─────────────────── */
.di-list { padding:10px 12px 48px; display:flex; flex-direction:column; gap:9px; }

.di-card {
  display:flex; align-items:stretch;
  background:linear-gradient(145deg, #FFFFFF 0%, #F4F7FC 100%);
  border:1.5px solid rgba(0,0,0,.06);
  border-radius:14px;
  box-shadow:0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  overflow:hidden; cursor:pointer; position:relative;
  animation:slideUp .45s cubic-bezier(.16,1,.3,1) both;
  transition:transform .22s cubic-bezier(.16,1,.3,1), box-shadow .22s;
}
/* Top shimmer line — spec E.2 */
.di-card::before {
  content:''; position:absolute; top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg,transparent 15%,rgba(255,255,255,.9) 50%,transparent 85%);
  pointer-events:none; z-index:2;
}
.di-card:hover  { box-shadow:0 2px 8px rgba(0,0,0,.07), 0 8px 32px rgba(0,30,80,.11); transform:translateY(-1px); }
.di-card:active { transform:scale(.985); box-shadow:0 1px 4px rgba(0,0,0,.05); }

/* Data stream sweep on each card — spec E.2 */
.di-card-shimmer {
  position:absolute; top:0; bottom:0; width:45%; left:0;
  background:linear-gradient(90deg,transparent,rgba(0,112,224,.025),transparent);
  animation:stream 7s ease-in-out infinite;
  pointer-events:none; z-index:1;
}

/* Tier-specific border accent */
.di-card--tier1 { border-color:rgba(224,32,80,.2); }
.di-card--tier2 { border-color:rgba(204,136,0,.16); }
.di-card--tier3 { border-color:rgba(0,112,224,.12); }

/* Left accent bar — Part B.14 */
.di-card-accent {
  width:4px; flex-shrink:0; border-radius:0 0 0 0; position:relative; z-index:2;
}
.di-card-accent--tier1 { background:#E02050; box-shadow:0 0 10px rgba(224,32,80,.4); }
.di-card-accent--tier2 { background:#CC8800; box-shadow:0 0 10px rgba(204,136,0,.35); animation:pulse-amb 2s ease-in-out infinite; }
.di-card-accent--tier3 { background:#0070E0; box-shadow:0 0 8px rgba(0,112,224,.3);  animation:pulse-blu 2.5s ease-in-out infinite; }
.di-card-accent--tier4 { background:#94A3B8; }
.di-card-accent--exp   { background:linear-gradient(180deg,#7B40D8,#B388FF); box-shadow:0 0 8px rgba(123,64,216,.3); }

/* Card body */
.di-card-body   { flex:1; padding:13px 14px 13px 12px; position:relative; z-index:2; }
.di-card-top    { display:flex; align-items:center; gap:6px; margin-bottom:7px; }
.di-card-name   { font-size:14px; font-weight:700; color:#0B1A30; font-family:'DM Sans',sans-serif; line-height:1.3; margin-bottom:5px; }
.di-card-syns   { display:flex; gap:4px; flex-wrap:wrap; margin-bottom:7px; }
.di-card-meta   { display:flex; gap:14px; flex-wrap:wrap; align-items:center; }
.di-card-chevron {
  display:flex; align-items:center; padding:0 14px 0 6px;
  color:#94A3B8; font-size:20px; font-weight:300; position:relative; z-index:2;
  transition:color .15s, transform .15s;
}
.di-card:hover .di-card-chevron { color:#0070E0; transform:translateX(2px); }

/* IHR Tier pills — surface gradients per spec B.6 */
.di-tier-pill {
  font-size:8px; font-weight:700; padding:3px 8px; border-radius:5px;
  font-family:'JetBrains Mono',monospace; letter-spacing:.4px; border:1px solid;
}
.di-tier-pill--tier1 { background:linear-gradient(135deg,#FEF2F2,#FECACA); color:#E02050; border-color:rgba(224,32,80,.18); }
.di-tier-pill--tier2 { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); color:#CC8800; border-color:rgba(204,136,0,.18); }
.di-tier-pill--tier3 { background:linear-gradient(135deg,#E0ECFF,#CCE0FF);  color:#0070E0; border-color:rgba(0,112,224,.15); }
.di-tier-pill--tier4 { background:rgba(0,0,0,.04); color:#94A3B8; border-color:rgba(0,0,0,.06); }

/* Alert level pills */
.di-alert-pill {
  font-size:8px; font-weight:700; padding:3px 8px; border-radius:5px;
  font-family:'JetBrains Mono',monospace; margin-left:auto; border:1px solid;
}
.di-alert-pill--critical { background:linear-gradient(135deg,#FEF2F2,#FECACA); color:#E02050; border-color:rgba(224,32,80,.18); }
.di-alert-pill--high     { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); color:#CC8800; border-color:rgba(204,136,0,.15); }
.di-alert-pill--medium   { background:linear-gradient(135deg,#E0ECFF,#CCE0FF); color:#0070E0; border-color:rgba(0,112,224,.13); }

/* Risk level pills (exposures) */
.di-risk-pill { font-size:8px; font-weight:700; padding:3px 8px; border-radius:5px; font-family:'JetBrains Mono',monospace; border:1px solid; }
.di-risk-pill--very_high { background:linear-gradient(135deg,#FEF2F2,#FECACA); color:#E02050; border-color:rgba(224,32,80,.18); }
.di-risk-pill--high      { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); color:#CC8800; border-color:rgba(204,136,0,.15); }
.di-risk-pill--moderate  { background:linear-gradient(135deg,#E0ECFF,#CCE0FF); color:#0070E0; border-color:rgba(0,112,224,.13); }
.di-risk-pill--low       { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); color:#00A86B; border-color:rgba(0,168,107,.15); }

/* Meta row */
.di-meta-item { display:flex; align-items:center; gap:4px; }
.di-meta-k    { font-size:9px; font-weight:700; color:#94A3B8; text-transform:uppercase; letter-spacing:.4px; font-family:'DM Sans',sans-serif; }
.di-meta-v    { font-size:10px; font-weight:700; color:#475569; font-family:'JetBrains Mono',monospace; }

/* CFR colors — neon accents for light zone */
.di-cfr--extreme { color:#E02050; font-weight:800; }
.di-cfr--high    { color:#CC8800; font-weight:800; }
.di-cfr--medium  { color:#0070E0; }
.di-cfr--low     { color:#00A86B; }

/* Severity dots */
.di-sev-dot { display:inline-block; width:7px; height:7px; border-radius:50%; background:rgba(0,0,0,.08); margin-right:2px; transition:background .15s; }
.di-sev-dot--on { background:#CC8800; box-shadow:0 0 5px rgba(204,136,0,.35); }

/* Syndrome tags */
.di-syn-tag { font-size:9px; font-weight:600; color:#475569; background:rgba(0,0,0,.05); padding:2px 7px; border-radius:4px; }

/* Override tag on card */
.di-override-tag {
  margin-top:8px; display:inline-flex; align-items:center; gap:4px;
  font-size:9px; font-weight:700; color:#CC8800;
  background:linear-gradient(135deg,#FFFBEB,#FEF3C7);
  padding:3px 9px; border-radius:5px; border:1px solid rgba(204,136,0,.2);
}
.di-idsr-ref {
  margin-top:6px; display:inline-flex; align-items:center; gap:5px;
  font-size:9px; font-weight:700; color:#475569;
  background:rgba(0,0,0,.04); padding:3px 8px; border-radius:5px;
  border:1px solid rgba(0,0,0,.06); letter-spacing:.2px;
}
.di-idsr-ref code { font-family:'JetBrains Mono',monospace; font-size:9px; color:#1A3A5C; font-weight:800; }

/* Exposure card extra fields */
.di-exp-code    { font-size:9px; color:#94A3B8; font-family:'JetBrains Mono',monospace; margin-bottom:7px; }
.di-exp-diseases { display:flex; align-items:center; gap:5px; flex-wrap:wrap; margin-bottom:5px; }
.di-exp-codes   { display:flex; align-items:center; gap:4px; flex-wrap:wrap; }
.di-exp-dis-tag { font-size:9px; font-weight:600; color:#0070E0; background:linear-gradient(135deg,#E0ECFF,#CCE0FF); padding:2px 7px; border-radius:4px; border:1px solid rgba(0,112,224,.15); }
.di-ec          { font-size:8px; color:#475569; background:rgba(0,0,0,.05); padding:2px 6px; border-radius:3px; font-family:'JetBrains Mono',monospace; }

/* Override card extras */
.di-rule-num    { font-size:8px; font-weight:700; color:#94A3B8; font-family:'JetBrains Mono',monospace; }
.di-rule-desc   { font-size:11px; color:#475569; line-height:1.5; margin:4px 0 10px; }
.di-triggers-block { background:rgba(0,0,0,.03); border-radius:8px; padding:10px; margin-bottom:10px; border:1px solid rgba(0,0,0,.04); }
.di-trigger-row { display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin-bottom:4px; }
.di-trigger-k   { font-size:8px; font-weight:800; padding:2px 7px; border-radius:4px; font-family:'JetBrains Mono',monospace; flex-shrink:0; }
.di-trigger-k--all { background:linear-gradient(135deg,#FEF2F2,#FECACA); color:#E02050; }
.di-trigger-k--any { background:linear-gradient(135deg,#E0ECFF,#CCE0FF);  color:#0070E0; }
.di-trigger-k--and { background:linear-gradient(135deg,#F5F3FF,#EDE9FE); color:#7B40D8; }
.di-trigger-sym  { font-size:9px; font-weight:600; color:#475569; background:rgba(0,0,0,.04); padding:2px 7px; border-radius:4px; }
.di-trigger-more { font-size:9px; font-weight:700; color:#94A3B8; }

.di-boosts-block  { margin-bottom:10px; }
.di-boosts-title  { font-size:8px; font-weight:700; color:#94A3B8; text-transform:uppercase; letter-spacing:.8px; margin-bottom:7px; }
.di-boost-row     { display:flex; align-items:center; gap:8px; margin-bottom:5px; }
.di-boost-name    { font-size:10px; font-weight:600; color:#475569; flex:1; min-width:0; }
.di-boost-val     { font-size:10px; font-weight:800; color:#00A86B; font-family:'JetBrains Mono',monospace; flex-shrink:0; }
.di-boost-track   { flex:1.5; height:5px; background:rgba(0,0,0,.06); border-radius:3px; overflow:hidden; }
.di-boost-fill    { height:100%; background:linear-gradient(90deg,#007A50,#00A86B); border-radius:3px; transition:width .6s cubic-bezier(.16,1,.3,1); }
.di-actions-block { margin-top:8px; }
.di-or-action     { font-size:10px; font-weight:700; color:#E02050; margin-bottom:3px; }

/* Empty state */
.di-empty { display:flex; flex-direction:column; align-items:center; padding:60px 24px; gap:10px; }
.di-empty-icon  { font-size:40px; opacity:.4; }
.di-empty-title { font-size:15px; font-weight:700; color:#475569; font-family:'DM Sans',sans-serif; }
.di-empty-body  { font-size:12px; color:#94A3B8; text-align:center; }

/* ═══════════════════════════════════════════════════════════════════════
   MODALS — Full-screen, dark header + light content
═══════════════════════════════════════════════════════════════════════ */

/* Modal header dark zone */
.di-modal-title-wrap { display:flex; flex-direction:column; gap:2px; }
.di-modal-title-main { font-size:15px; font-weight:800; color:#EDF2FA; font-family:'Syne',sans-serif; line-height:1.2; }

/* Modal detail tabs — dark zone */
.di-detail-tabs {
  display:flex; overflow-x:auto; scrollbar-width:none;
  background:linear-gradient(0deg,#080E1C,#0E1A2E);
  border-bottom:1px solid rgba(255,255,255,.06);
}
.di-detail-tabs::-webkit-scrollbar { display:none; }
.di-detail-tab {
  flex:1; padding:10px 8px; border:none; background:transparent;
  color:#4A5874; font-size:10px; font-weight:700; cursor:pointer;
  border-bottom:2px solid transparent; transition:all .15s;
  white-space:nowrap; font-family:'DM Sans',sans-serif; min-height:44px;
}
.di-detail-tab--on { color:#00B4FF; border-bottom-color:#00B4FF; background:rgba(0,180,255,.05); }

/* ── LIGHT ZONE: Modal body ──────────────────────────────────────────── */
.di-tab-body { padding:14px 14px 56px; }

/* Alert banner */
.di-alert-banner {
  display:flex; align-items:center; justify-content:space-between;
  padding:13px 16px; border-radius:12px; margin-bottom:16px;
  border:1.5px solid; position:relative; overflow:hidden;
}
.di-alert-banner::before {
  content:''; position:absolute; top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg,transparent 15%,rgba(255,255,255,.6) 50%,transparent 85%);
}
.di-alert-banner--critical { background:linear-gradient(135deg,#FEF2F2,#FECACA); border-color:rgba(224,32,80,.22); }
.di-alert-banner--high     { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border-color:rgba(204,136,0,.22); }
.di-alert-banner--medium   { background:linear-gradient(135deg,#E0ECFF,#CCE0FF); border-color:rgba(0,112,224,.22); }
.di-alert-k { font-size:8px; font-weight:700; color:rgba(0,0,0,.4); letter-spacing:1.2px; text-transform:uppercase; }
.di-alert-v { font-size:15px; font-weight:900; font-family:'JetBrains Mono',monospace; }
.di-alert-banner--critical .di-alert-v { color:#E02050; }
.di-alert-banner--high     .di-alert-v { color:#CC8800; }
.di-alert-banner--medium   .di-alert-v { color:#0070E0; }

/* KPI metrics grid */
.di-kpi-grid { display:grid; grid-template-columns:1fr 1fr; gap:9px; margin-bottom:18px; }
.di-kpi {
  background:linear-gradient(145deg, #FFFFFF 0%, #F4F7FC 100%);
  border:1.5px solid rgba(0,0,0,.06);
  border-radius:14px; padding:14px 12px;
  box-shadow:0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  position:relative; overflow:hidden;
}
/* Glow blob per KPI */
.di-kpi::before {
  content:''; position:absolute; top:-8px; right:-8px; width:55px; height:55px;
  border-radius:50%; filter:blur(20px); opacity:.2;
  background:var(--kpi-glow, #0070E0);
}
.di-kpi::after {
  content:''; position:absolute; top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.85) 50%,transparent 80%);
}
.di-kpi-n    { font-size:24px; font-weight:900; font-family:'Syne',sans-serif; color:#0B1A30; line-height:1; margin-bottom:5px; position:relative; z-index:1; }
.di-kpi-l    { font-size:8px; font-weight:700; color:#94A3B8; text-transform:uppercase; letter-spacing:.7px; margin-bottom:2px; position:relative; z-index:1; }
.di-kpi-hint { font-size:9px; color:#94A3B8; line-height:1.3; position:relative; z-index:1; }

/* Section headers */
.di-section      { margin-bottom:20px; }
.di-section-hdr  { display:flex; align-items:center; gap:8px; margin-bottom:10px; }
.di-section-glyph {
  width:26px; height:26px; border-radius:7px; flex-shrink:0;
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF);
  border:1px solid rgba(0,112,224,.15);
  display:flex; align-items:center; justify-content:center; font-size:13px;
}
.di-section-title {
  font-size:9px; font-weight:700; color:#0070E0;
  letter-spacing:1.1px; text-transform:uppercase; font-family:'DM Sans',sans-serif;
}

/* IHR classification block */
.di-ihr-block {
  border-radius:12px; padding:14px;
  position:relative; overflow:hidden;
}
.di-ihr-block::before {
  content:''; position:absolute; top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.6) 50%,transparent 80%);
}
.di-ihr-block--tier1 { background:linear-gradient(135deg,#FEF2F2,#FECACA); border:1.5px solid rgba(224,32,80,.2); }
.di-ihr-block--tier2 { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border:1.5px solid rgba(204,136,0,.18); }
.di-ihr-block--tier3 { background:linear-gradient(135deg,#E0ECFF,#CCE0FF); border:1.5px solid rgba(0,112,224,.15); }
.di-ihr-block--tier4 { background:linear-gradient(145deg,#FFFFFF,#F4F7FC); border:1.5px solid rgba(0,0,0,.06); }
.di-ihr-tier     { font-size:11px; font-weight:800; font-family:'JetBrains Mono',monospace; color:#0070E0; margin-bottom:5px; }
.di-ihr-desc     { font-size:12px; font-weight:700; color:#0B1A30; margin-bottom:7px; line-height:1.4; }
.di-ihr-cat      { font-size:9px; font-weight:600; color:#475569; font-family:'JetBrains Mono',monospace; margin-bottom:4px; }
.di-ihr-basis-text { font-size:10px; color:#475569; font-style:italic; line-height:1.4; }

/* Syndrome chips */
.di-syn-chips { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:9px; }
.di-syn-chip  {
  font-size:9px; font-weight:600; padding:4px 10px; border-radius:5px;
  background:linear-gradient(135deg,#ECFEFF,#CFFAFE);
  color:#008F7A; border:1px solid rgba(0,143,122,.15);
}
.di-syn-sub { font-size:10px; color:#475569; line-height:1.5; }

/* WHO case definition */
.di-case-defs   { display:flex; flex-direction:column; gap:8px; }
.di-case-level  {
  border-radius:10px; padding:12px;
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06);
}
.di-case-hdr    { font-size:8px; font-weight:800; letter-spacing:1px; margin-bottom:5px; font-family:'JetBrains Mono',monospace; }
.di-case-hdr--s { color:#CC8800; }
.di-case-hdr--p { color:#0070E0; }
.di-case-hdr--c { color:#00A86B; }
.di-case-hdr--i { color:#7B40D8; }
.di-case-text   { font-size:11px; color:#475569; line-height:1.5; }
.di-case-poe    {
  border-radius:10px; padding:12px;
  background:linear-gradient(135deg,#FEF2F2,#FECACA);
  border:1.5px solid rgba(224,32,80,.2);
}
.di-case-poe-hdr  { font-size:9px; font-weight:800; color:#E02050; letter-spacing:.8px; margin-bottom:5px; }
.di-case-poe-text { font-size:11px; color:#B01840; font-weight:700; line-height:1.4; }

/* Key distinguishers */
.di-dist-list { display:flex; flex-direction:column; gap:7px; }
.di-dist-item { display:flex; gap:8px; font-size:11px; color:#475569; line-height:1.5; align-items:flex-start; }
.di-dist-bullet { color:#0070E0; flex-shrink:0; font-size:13px; line-height:1.4; }

/* Endemic countries */
.di-endemic-note  { font-size:10px; color:#475569; margin-bottom:8px; line-height:1.4; padding:9px 11px; background:linear-gradient(135deg,#E0ECFF,#CCE0FF); border-radius:8px; border:1px solid rgba(0,112,224,.12); }
.di-country-chips { display:flex; flex-wrap:wrap; gap:4px; }
.di-cc-chip {
  font-size:9px; font-weight:700; padding:3px 8px; border-radius:4px;
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF);
  color:#0070E0; border:1px solid rgba(0,112,224,.15);
  font-family:'JetBrains Mono',monospace;
}

/* Formula box */
.di-formula-box {
  background:linear-gradient(135deg,#EEF2FA,#E4EBF7);
  border:1.5px solid rgba(0,112,224,.15); border-radius:12px; padding:13px;
  margin-bottom:18px; position:relative; overflow:hidden;
}
.di-formula-box::before {
  content:''; position:absolute; top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.7) 50%,transparent 80%);
}
.di-formula-title { font-size:8px; font-weight:700; color:#0070E0; letter-spacing:1px; text-transform:uppercase; margin-bottom:7px; font-family:'DM Sans',sans-serif; }
.di-formula-text  { font-size:10px; color:#0B1A30; font-family:'JetBrains Mono',monospace; line-height:1.7; font-weight:500; word-break:break-word; }

/* Gate blocks */
.di-gate-note    { font-size:10px; color:#475569; line-height:1.5; margin-bottom:12px; padding:10px 12px; background:linear-gradient(145deg,#FFFFFF,#F4F7FC); border-radius:10px; border:1.5px solid rgba(0,0,0,.06); }
.di-gate-blocks  { display:flex; flex-direction:column; gap:8px; margin-bottom:12px; }
.di-gate-block   { border-radius:10px; padding:12px; position:relative; overflow:hidden; }
.di-gate-block::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.7) 50%,transparent 80%); }
.di-gate-block--all  { background:linear-gradient(135deg,#E0ECFF,#CCE0FF); border:1.5px solid rgba(0,112,224,.15); }
.di-gate-block--any  { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); border:1.5px solid rgba(0,168,107,.15); }
.di-gate-block--hard { background:linear-gradient(135deg,#FEF2F2,#FECACA); border:1.5px solid rgba(224,32,80,.2); }
.di-gate-hdr    { font-size:8px; font-weight:700; color:rgba(0,0,0,.45); letter-spacing:.7px; margin-bottom:8px; line-height:1.4; }
.di-gate-syms   { display:flex; flex-wrap:wrap; gap:5px; }
.di-gate-sym    { display:flex; align-items:center; gap:5px; font-size:10px; font-weight:600; color:#0B1A30; }
.di-gate-dot    { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.di-gate-dot--all  { background:#0070E0; box-shadow:0 0 5px rgba(0,112,224,.4); }
.di-gate-dot--any  { background:#00A86B; box-shadow:0 0 5px rgba(0,168,107,.4); }
.di-gate-dot--hard { background:#E02050; box-shadow:0 0 5px rgba(224,32,80,.5); animation:pulse-red 1.5s infinite; }

/* Gate outcome table */
.di-gate-outcome {
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:10px; padding:11px; margin-top:10px;
}
.di-gate-outcome-row { display:flex; justify-content:space-between; align-items:center; padding:4px 0; border-bottom:1px solid rgba(0,0,0,.04); font-size:10px; }
.di-gate-outcome-row:last-child { border-bottom:none; }
.di-got-k { color:#94A3B8; font-weight:600; }
.di-got-v { font-family:'JetBrains Mono',monospace; font-weight:800; }
.di-got-v--pass { color:#00A86B; }
.di-got-v--soft { color:#CC8800; }
.di-got-v--hard { color:#E02050; }

/* Symptom weight bars */
.di-sym-note { font-size:10px; color:#475569; line-height:1.5; margin-bottom:10px; }
.di-sym-list { display:flex; flex-direction:column; gap:4px; }
.di-sym-row  {
  display:grid; grid-template-columns:1fr 90px 34px;
  align-items:center; gap:8px; padding:6px 8px; border-radius:7px;
  transition:background .12s;
}
.di-sym-row--hm  {
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF);
  border:1px solid rgba(0,112,224,.12);
}
.di-sym-row:not(.di-sym-row--hm):hover { background:rgba(0,0,0,.03); }
.di-sym-info  { display:flex; align-items:center; gap:6px; min-width:0; }
.di-sym-name  { font-size:11px; font-weight:600; color:#475569; line-height:1.3; }
.di-sym-hm-tag {
  font-size:7px; font-weight:700; color:#0070E0;
  background:rgba(0,112,224,.1); padding:1px 5px; border-radius:3px;
  flex-shrink:0; letter-spacing:.4px; white-space:nowrap;
}
.di-sym-bar-wrap { height:5px; background:rgba(0,0,0,.07); border-radius:3px; overflow:hidden; }
.di-sym-bar      { height:100%; border-radius:3px; transition:width .6s cubic-bezier(.16,1,.3,1); }
.di-sym-bar--exp { background:linear-gradient(90deg,#7B40D8,#B388FF) !important; }
.di-sym-wt       { font-size:10px; font-weight:700; color:#475569; font-family:'JetBrains Mono',monospace; text-align:right; }

/* Absent hallmark penalties */
.di-penalty-note { font-size:10px; color:#475569; line-height:1.5; margin-bottom:10px; padding:9px 12px; background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border-radius:8px; border:1px solid rgba(204,136,0,.15); }
.di-penalty-list { display:flex; flex-direction:column; gap:0; }
.di-penalty-row  { display:flex; justify-content:space-between; align-items:center; padding:7px 0; border-bottom:1px solid rgba(0,0,0,.04); }
.di-penalty-sym  { font-size:10px; font-weight:600; color:#475569; }
.di-penalty-val  { font-size:10px; font-weight:800; color:#E02050; font-family:'JetBrains Mono',monospace; }

/* Negative weights */
.di-contra-note  { font-size:10px; color:#475569; line-height:1.5; margin-bottom:10px; padding:9px 12px; background:linear-gradient(135deg,#FEF2F2,#FECACA); border-radius:8px; border:1px solid rgba(224,32,80,.15); }
.di-contra-list  { display:flex; flex-direction:column; gap:4px; }
.di-contra-row   { display:grid; grid-template-columns:1fr 70px 34px; align-items:center; gap:8px; padding:5px 0; border-bottom:1px solid rgba(0,0,0,.04); }
.di-contra-sym   { font-size:10px; font-weight:600; color:#475569; }
.di-contra-track { height:4px; background:rgba(0,0,0,.07); border-radius:2px; overflow:hidden; }
.di-contra-fill  { height:100%; background:linear-gradient(90deg,#B01840,#E02050); border-radius:2px; }
.di-contra-val   { font-size:10px; font-weight:800; color:#E02050; font-family:'JetBrains Mono',monospace; text-align:right; }

/* Override mini-cards (in scoring tab) */
.di-override-note { font-size:10px; color:#475569; line-height:1.5; margin-bottom:10px; }
.di-or-mini {
  background:linear-gradient(135deg,#FFFBEB,#FEF3C7);
  border:1.5px solid rgba(204,136,0,.2); border-radius:10px; padding:11px; margin-bottom:8px;
  position:relative; overflow:hidden;
}
.di-or-mini::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.8) 50%,transparent 80%); }
.di-or-mini-hdr   { display:flex; justify-content:space-between; align-items:center; margin-bottom:5px; }
.di-or-mini-id    { font-size:9px; font-weight:800; color:#CC8800; text-transform:uppercase; letter-spacing:.5px; }
.di-or-mini-boost { font-size:10px; font-weight:800; color:#00A86B; font-family:'JetBrains Mono',monospace; }
.di-or-mini-desc  { font-size:10px; color:#475569; line-height:1.4; margin-bottom:7px; }
.di-or-mini-syms  { display:flex; gap:4px; flex-wrap:wrap; }
.di-or-mini-sym   { font-size:9px; font-weight:600; color:#475569; background:rgba(0,0,0,.05); padding:2px 6px; border-radius:3px; }

/* Confidence bands */
.di-bands-list { display:flex; flex-direction:column; gap:0; }
.di-band-row   { display:grid; grid-template-columns:12px 85px 44px 1fr; align-items:center; gap:8px; padding:8px 0; border-bottom:1px solid rgba(0,0,0,.05); }
.di-band-dot   { width:10px; height:10px; border-radius:50%; }
.di-band-name  { font-size:9px; font-weight:700; color:#475569; font-family:'JetBrains Mono',monospace; }
.di-band-score { font-size:9px; font-weight:700; color:#94A3B8; font-family:'JetBrains Mono',monospace; }
.di-band-action { font-size:9px; color:#94A3B8; line-height:1.3; }

/* Exposure section */
.di-exp-engine-note { font-size:10px; color:#475569; line-height:1.5; margin-bottom:12px; padding:10px 12px; background:linear-gradient(135deg,#F5F3FF,#EDE9FE); border-radius:10px; border:1.5px solid rgba(123,64,216,.12); }

/* Vaccination modifiers */
.di-vax-note   { font-size:10px; color:#475569; line-height:1.5; margin-bottom:10px; }
.di-vax-block  { background:linear-gradient(145deg,#FFFFFF,#F4F7FC); border:1.5px solid rgba(0,0,0,.06); border-radius:10px; padding:12px; margin-bottom:8px; }
.di-vax-name   { font-size:9px; font-weight:700; color:#94A3B8; letter-spacing:.8px; text-transform:uppercase; margin-bottom:8px; font-family:'DM Sans',sans-serif; }
.di-vax-row    { display:flex; justify-content:space-between; align-items:center; padding:5px 0; border-bottom:1px solid rgba(0,0,0,.04); font-size:10px; }
.di-vax-row:last-child { border-bottom:none; }
.di-vax-status { color:#475569; font-weight:600; }
.di-vax-mod--good { color:#00A86B; font-weight:800; font-family:'JetBrains Mono',monospace; }
.di-vax-mod--bad  { color:#E02050; font-weight:800; font-family:'JetBrains Mono',monospace; }

/* Onset modifiers */
.di-onset-row   { display:flex; justify-content:space-between; align-items:center; padding:7px 0; border-bottom:1px solid rgba(0,0,0,.04); }
.di-onset-period { font-size:10px; font-weight:600; color:#475569; font-family:'JetBrains Mono',monospace; }
.di-onset-mod   { font-size:10px; font-weight:800; color:#0070E0; font-family:'JetBrains Mono',monospace; }

/* Actions tab */
.di-action-row  { display:flex; align-items:flex-start; gap:10px; padding:9px 0; border-bottom:1px solid rgba(0,0,0,.04); }
.di-action-num  {
  width:24px; height:24px; border-radius:50%; flex-shrink:0;
  background:linear-gradient(135deg,#0055CC,#0070E0);
  color:#fff; font-size:10px; font-weight:800;
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 2px 8px rgba(0,112,224,.3);
}
.di-action-text { font-size:11px; font-weight:600; color:#0B1A30; line-height:1.5; padding-top:3px; }
.di-note-box    {
  font-size:10px; color:#475569; line-height:1.5; margin-bottom:10px;
  padding:10px 12px; background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:10px;
}
.di-test-row    { display:flex; align-items:flex-start; gap:8px; padding:7px 0; border-bottom:1px solid rgba(0,0,0,.04); }
.di-test-ic     { font-size:14px; flex-shrink:0; }
.di-test-text   { font-size:11px; color:#475569; line-height:1.4; }

/* System behaviour table */
.di-sys-table { display:flex; flex-direction:column; background:linear-gradient(145deg,#FFFFFF,#F4F7FC); border:1.5px solid rgba(0,0,0,.06); border-radius:12px; overflow:hidden; }
.di-sys-row   { display:flex; gap:8px; padding:9px 12px; border-bottom:1px solid rgba(0,0,0,.04); flex-wrap:wrap; }
.di-sys-row:last-child { border-bottom:none; }
.di-sys-k     { font-size:10px; font-weight:600; color:#94A3B8; flex-shrink:0; min-width:130px; }
.di-sys-v     { font-size:10px; font-weight:600; color:#475569; }
.di-sys-v--critical { color:#E02050; font-weight:800; font-family:'JetBrains Mono',monospace; }
.di-sys-v--high     { color:#CC8800; font-weight:800; font-family:'JetBrains Mono',monospace; }
.di-sys-v--medium   { color:#0070E0; font-weight:800; font-family:'JetBrains Mono',monospace; }
.di-code-val  { font-size:10px; font-family:'JetBrains Mono',monospace; color:#0070E0; background:linear-gradient(135deg,#E0ECFF,#CCE0FF); padding:2px 7px; border-radius:4px; }

/* WHO basis */
.di-who-basis {
  font-size:11px; color:#475569; line-height:1.6; font-style:italic;
  padding:12px 14px; background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:10px;
}

/* Critical exposure banner */
.di-crit-banner {
  background:linear-gradient(135deg,#FEF2F2,#FECACA);
  border:1.5px solid rgba(224,32,80,.22); border-radius:10px; padding:13px;
}
.di-crit-hdr  { font-size:9px; font-weight:800; color:#E02050; letter-spacing:.8px; margin-bottom:5px; }
.di-crit-text { font-size:11px; color:#B01840; font-weight:700; line-height:1.4; }

/* Exposure disease list */
.di-exp-dis-list { display:flex; flex-direction:column; gap:6px; }
.di-exp-dis-row  {
  display:flex; align-items:center; gap:8px; padding:9px 12px;
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:10px;
  cursor:pointer; transition:all .18s;
}
.di-exp-dis-row:hover { box-shadow:0 2px 8px rgba(0,30,80,.09); transform:translateX(2px); }
.di-exp-dis-name  { flex:1; font-size:11px; font-weight:600; color:#0B1A30; }
.di-exp-dis-wt    { font-size:10px; font-weight:800; color:#00A86B; font-family:'JetBrains Mono',monospace; }
.di-exp-dis-arr   { color:#94A3B8; font-size:14px; transition:color .15s; }
.di-exp-dis-row:hover .di-exp-dis-arr { color:#0070E0; }

/* Engine codes chips */
.di-ec-wrap  { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.di-ec-large {
  font-size:10px; font-family:'JetBrains Mono',monospace; color:#0070E0;
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF);
  padding:5px 12px; border-radius:6px; border:1px solid rgba(0,112,224,.15);
}

/* Screening questions */
.di-sq-row  { display:flex; gap:8px; padding:8px 0; border-bottom:1px solid rgba(0,0,0,.04); align-items:flex-start; }
.di-sq-num  { font-size:9px; font-weight:700; color:#0070E0; font-family:'JetBrains Mono',monospace; flex-shrink:0; margin-top:2px; }
.di-sq-text { font-size:11px; color:#475569; line-height:1.4; }



/* ═══════════════════════════════════════════════════════════════════════
   DISEASE MODAL — PREMIUM CSS
   All content in light zone. Header/hero dark zone.
═══════════════════════════════════════════════════════════════════════ */

/* Modal detail tab icons */
.detailTabs { }

/* ── Modal detail tabs array — add icons ──────────────────────────── */

/* ── Dark zone: header ──────────────────────────────────────────────── */
.dm-header { }
.dm-close-btn { --color: #00B4FF; }
.dm-toolbar-title { display:flex; flex-direction:column; gap:1px; }
.dm-toolbar-eyebrow { font-size:7px; font-weight:700; color:#7E92AB; letter-spacing:1.4px; text-transform:uppercase; font-family:'DM Sans',sans-serif; }
.dm-toolbar-name    { font-size:15px; font-weight:800; color:#EDF2FA; font-family:'Syne',sans-serif; line-height:1.15; }
.dm-header-tier { font-size:8px; font-weight:700; padding:3px 9px; border-radius:5px; margin-right:6px; font-family:'JetBrains Mono',monospace; letter-spacing:.4px; }

/* Tier badge colors */
.dm-tier--tier1 { background:rgba(224,32,80,.18); color:#FF3D71; border:1px solid rgba(255,61,113,.25); }
.dm-tier--tier2 { background:rgba(255,179,0,.15);  color:#FFB300; border:1px solid rgba(255,179,0,.25); }
.dm-tier--tier3 { background:rgba(0,180,255,.12);  color:#00B4FF; border:1px solid rgba(0,180,255,.2); }
.dm-tier--tier4 { background:rgba(255,255,255,.07); color:#7E92AB; border:1px solid rgba(255,255,255,.1); }

/* ── Dark zone: detail tabs ──────────────────────────────────────────── */
.dm-tabs {
  display:flex; overflow-x:auto; scrollbar-width:none;
  background:linear-gradient(180deg, #0D1625 0%, #070E1B 100%);
  border-bottom:1px solid rgba(255,255,255,.06);
}
.dm-tabs::-webkit-scrollbar { display:none; }
.dm-tab {
  flex:1; display:flex; align-items:center; justify-content:center; gap:4px;
  padding:10px 8px; border:none; background:transparent;
  color:#4A5874; font-size:10px; font-weight:700; letter-spacing:.3px;
  cursor:pointer; border-bottom:2px solid transparent;
  transition:all .18s; white-space:nowrap;
  font-family:'DM Sans',sans-serif; min-height:44px;
}
.dm-tab--on { color:#00B4FF; border-bottom-color:#00B4FF; background:rgba(0,180,255,.05); }
.dm-tab-icon { font-size:13px; }

/* ── CINEMATIC HERO ─────────────────────────────────────────────────── */
.dm-hero {
  position:relative; overflow:hidden;
  padding:20px 16px 0;
  background:linear-gradient(180deg, #0E1A2E 0%, #142640 70%, #1C3054 100%);
}
.dm-hero--tier1 { background:linear-gradient(180deg, #1A0510 0%, #2D0B1A 60%, #3D1020 100%); }
.dm-hero--tier2 { background:linear-gradient(180deg, #1A1000 0%, #2D1E00 60%, #3A2800 100%); }
.dm-hero--tier3 { background:linear-gradient(180deg, #030D1E 0%, #071428 60%, #0A1C38 100%); }

/* Grid texture overlay on hero */
.dm-hero-grid {
  position:absolute; inset:0; pointer-events:none;
  background-image:
    linear-gradient(rgba(0,180,255,.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,180,255,.03) 1px, transparent 1px);
  background-size:28px 28px;
  mask-image:linear-gradient(180deg, black 40%, transparent 100%);
}
.dm-hero--tier1 .dm-hero-grid {
  background-image:
    linear-gradient(rgba(255,61,113,.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,61,113,.04) 1px, transparent 1px);
}
.dm-hero--tier2 .dm-hero-grid {
  background-image:
    linear-gradient(rgba(255,179,0,.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(255,179,0,.04) 1px, transparent 1px);
}

/* Ambient orb */
.dm-hero-orb {
  position:absolute; top:-60px; right:-60px;
  width:200px; height:200px; border-radius:50%;
  filter:blur(70px); opacity:.25; pointer-events:none;
  background:#0070E0;
}
.dm-hero-orb--tier1 { background:#E02050; }
.dm-hero-orb--tier2 { background:#CC8800; }
.dm-hero-orb--tier3 { background:#0070E0; }

.dm-hero-body { display:flex; align-items:flex-start; gap:12px; margin-bottom:16px; position:relative; z-index:2; }
.dm-hero-left { flex:1; min-width:0; }
.dm-hero-right { flex-shrink:0; display:flex; flex-direction:column; align-items:center; gap:8px; }

.dm-hero-tier-badge {
  font-size:8px; font-weight:700; padding:3px 9px; border-radius:5px;
  display:inline-flex; margin-bottom:8px; font-family:'JetBrains Mono',monospace; letter-spacing:.5px;
}
.dm-hero-name {
  font-size:26px; font-weight:900; color:#EDF2FA;
  font-family:'Syne',sans-serif; line-height:1.1;
  margin:0 0 5px; letter-spacing:-.5px;
}
.dm-hero-category { font-size:10px; font-weight:600; color:#7E92AB; margin-bottom:10px; letter-spacing:.3px; text-transform:uppercase; font-family:'DM Sans',sans-serif; }
.dm-hero-syndromes { display:flex; gap:5px; flex-wrap:wrap; }
.dm-hero-syn {
  font-size:9px; font-weight:600; padding:3px 9px; border-radius:4px;
  background:rgba(255,255,255,.07); color:#7E92AB; border:1px solid rgba(255,255,255,.08);
}

/* CFR ring SVG */
.dm-cfr-ring { position:relative; display:flex; align-items:center; justify-content:center; }
.dm-cfr-svg  { width:72px; height:72px; transform:rotate(-90deg); }
.dm-cfr-track { fill:none; stroke:rgba(255,255,255,.08); stroke-width:5; }
.dm-cfr-arc   { fill:none; stroke-width:5; stroke-linecap:round; stroke-dasharray:188.5; transition:stroke-dashoffset .8s cubic-bezier(.16,1,.3,1); }
.dm-cfr-arc--tier1 { stroke:#FF3D71; filter:drop-shadow(0 0 5px rgba(255,61,113,.5)); }
.dm-cfr-arc--tier2 { stroke:#FFB300; filter:drop-shadow(0 0 5px rgba(255,179,0,.5)); }
.dm-cfr-arc--tier3 { stroke:#00B4FF; filter:drop-shadow(0 0 5px rgba(0,180,255,.5)); }
.dm-cfr-arc--tier4 { stroke:#94A3B8; }
.dm-cfr-inner {
  position:absolute; display:flex; flex-direction:column; align-items:center; gap:0;
}
.dm-cfr-n { font-size:13px; font-weight:900; font-family:'Syne',sans-serif; line-height:1; }
.dm-cfr-l  { font-size:7px; font-weight:700; color:#7E92AB; letter-spacing:.8px; text-transform:uppercase; }

.dm-sev-strip  { display:flex; flex-direction:column; align-items:center; gap:3px; }
.dm-sev-label  { font-size:7px; font-weight:700; color:#7E92AB; letter-spacing:.8px; text-transform:uppercase; }
.dm-sev-dots   { display:flex; gap:3px; }
.dm-sev-dot    { width:8px; height:8px; border-radius:50%; background:rgba(255,255,255,.12); }
.dm-sev-dot--on { background:#FFB300; box-shadow:0 0 6px rgba(255,179,0,.5); }

/* Alert bar at hero bottom */
.dm-hero-alert {
  display:flex; align-items:center; justify-content:space-between;
  padding:10px 16px; margin:0 -16px;
  position:relative; z-index:2;
}
.dm-hero-alert--critical { background:rgba(224,32,80,.2); border-top:1px solid rgba(224,32,80,.25); }
.dm-hero-alert--high     { background:rgba(204,136,0,.18); border-top:1px solid rgba(204,136,0,.22); }
.dm-hero-alert--medium   { background:rgba(0,112,224,.15); border-top:1px solid rgba(0,112,224,.2); }
.dm-hero-alert-label { font-size:8px; font-weight:700; color:rgba(255,255,255,.4); letter-spacing:1px; text-transform:uppercase; font-family:'DM Sans',sans-serif; }
.dm-hero-alert-val   { font-size:14px; font-weight:900; font-family:'JetBrains Mono',monospace; }
.dm-hero-alert--critical .dm-hero-alert-val { color:#FF3D71; text-shadow:0 0 16px rgba(255,61,113,.4); }
.dm-hero-alert--high     .dm-hero-alert-val { color:#FFB300; text-shadow:0 0 16px rgba(255,179,0,.4); }
.dm-hero-alert--medium   .dm-hero-alert-val { color:#00B4FF; text-shadow:0 0 16px rgba(0,180,255,.4); }

/* ── LIGHT ZONE: Modal body ──────────────────────────────────────────── */
.dm-body { padding:16px 14px 0; }

/* KPI row */
.dm-kpi-row { display:grid; grid-template-columns:1fr 1fr; gap:9px; margin-bottom:20px; }
.dm-kpi {
  background:linear-gradient(145deg, #FFFFFF 0%, #F4F7FC 100%);
  border:1.5px solid rgba(0,0,0,.06);
  border-radius:16px; padding:15px 13px;
  box-shadow:0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  position:relative; overflow:hidden;
  animation:slideUp .4s cubic-bezier(.16,1,.3,1) both;
}
.dm-kpi-blob {
  position:absolute; top:-10px; right:-10px; width:60px; height:60px;
  border-radius:50%; filter:blur(22px); opacity:.2; pointer-events:none;
  background:var(--kpi-glow, #0070E0);
}
.dm-kpi-shine {
  position:absolute; top:0; left:0; right:0; height:1px; pointer-events:none;
  background:linear-gradient(90deg, transparent 20%, rgba(255,255,255,.85) 50%, transparent 80%);
}
.dm-kpi-val   { font-size:22px; font-weight:900; font-family:'Syne',sans-serif; color:#0B1A30; line-height:1; margin-bottom:5px; }
.dm-kpi-key   { font-size:8px; font-weight:700; color:#94A3B8; text-transform:uppercase; letter-spacing:.7px; margin-bottom:2px; }
.dm-kpi-sub   { font-size:9px; color:#94A3B8; line-height:1.3; }
.dm-sev-sm    { display:inline-block; width:8px; height:8px; border-radius:50%; background:rgba(0,0,0,.09); margin-right:2px; }
.dm-sev-sm--on { background:#CC8800; box-shadow:0 0 5px rgba(204,136,0,.4); }

/* Section label */
.dm-section { margin-bottom:22px; }
.dm-section-label {
  display:flex; align-items:center; gap:8px;
  font-size:9px; font-weight:700; color:#0070E0;
  letter-spacing:1.1px; text-transform:uppercase;
  margin-bottom:12px; padding-bottom:8px;
  border-bottom:1px solid rgba(0,112,224,.12);
  font-family:'DM Sans',sans-serif;
}
.dm-section-glyph {
  width:24px; height:24px; border-radius:6px; flex-shrink:0;
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF);
  border:1px solid rgba(0,112,224,.15);
  display:flex; align-items:center; justify-content:center; font-size:13px;
}

/* IHR card — dual-column layout */
.dm-ihr-card {
  display:flex; gap:0; border-radius:14px; overflow:hidden;
  border:1.5px solid rgba(0,0,0,.06);
  box-shadow:0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  position:relative;
}
.dm-ihr-shine {
  position:absolute; top:0; left:0; right:0; height:1px; pointer-events:none; z-index:2;
  background:linear-gradient(90deg, transparent 20%, rgba(255,255,255,.8) 50%, transparent 80%);
}
.dm-ihr-card--tier1 { border-color:rgba(224,32,80,.2); }
.dm-ihr-card--tier2 { border-color:rgba(204,136,0,.18); }
.dm-ihr-card--tier3 { border-color:rgba(0,112,224,.15); }

.dm-ihr-left {
  width:90px; flex-shrink:0; padding:14px 12px;
  display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px;
  text-align:center;
}
.dm-ihr-card--tier1 .dm-ihr-left { background:linear-gradient(180deg,#FEF2F2,#FECACA); }
.dm-ihr-card--tier2 .dm-ihr-left { background:linear-gradient(180deg,#FFFBEB,#FEF3C7); }
.dm-ihr-card--tier3 .dm-ihr-left { background:linear-gradient(180deg,#E0ECFF,#CCE0FF); }
.dm-ihr-card--tier4 .dm-ihr-left { background:linear-gradient(180deg,#F4F7FC,#EAF0FA); }

.dm-ihr-tier-tag {
  font-size:7px; font-weight:700; padding:3px 6px; border-radius:4px;
  font-family:'JetBrains Mono',monospace; letter-spacing:.4px; text-align:center;
}
.dm-ihr-category { font-size:8px; font-weight:600; color:rgba(0,0,0,.45); text-align:center; line-height:1.3; }

.dm-ihr-right { flex:1; padding:14px; background:linear-gradient(145deg,#FFFFFF,#F4F7FC); }
.dm-ihr-desc  { font-size:11px; font-weight:700; color:#0B1A30; line-height:1.5; margin-bottom:7px; }
.dm-ihr-basis { font-size:10px; color:#475569; line-height:1.4; font-style:italic; }

/* IDSR thresholds card */
.dm-idsr-card { background:#fff; border:1px solid #E8EDF5; border-radius:12px; padding:12px 14px; display:flex; flex-direction:column; gap:8px; }
.dm-idsr-head { display:flex; align-items:center; gap:8px; flex-wrap:wrap; padding-bottom:6px; border-bottom:1px dashed #E8EDF5; }
.dm-idsr-cat  { font-size:9px; font-weight:900; padding:3px 8px; border-radius:4px; letter-spacing:.4px; font-family:'JetBrains Mono',monospace; }
.dm-idsr-cat--pheic                     { background:rgba(220,38,38,.1);  color:#B91C1C; border:1px solid rgba(220,38,38,.2); }
.dm-idsr-cat--epidemic_prone            { background:rgba(202,138,4,.12); color:#B45309; border:1px solid rgba(202,138,4,.2); }
.dm-idsr-cat--eradication_elimination   { background:rgba(5,150,105,.1);  color:#047857; border:1px solid rgba(5,150,105,.18); }
.dm-idsr-cat--other_major_public_health { background:rgba(0,112,224,.08); color:#0070E0; border:1px solid rgba(0,112,224,.15); }
.dm-idsr-ref  { font-size:9.5px; color:#475569; font-weight:600; }
.dm-idsr-ref code { font-family:'JetBrains Mono',monospace; font-size:9.5px; color:#1A3A5C; font-weight:800; }
.dm-idsr-row  { display:flex; align-items:flex-start; gap:9px; font-size:11.5px; line-height:1.5; color:#334155; }
.dm-idsr-tag  { flex-shrink:0; font-size:8.5px; font-weight:900; padding:3px 7px; border-radius:4px; font-family:'JetBrains Mono',monospace; margin-top:1px; letter-spacing:.4px; }
.dm-idsr-tag--alert { background:rgba(202,138,4,.12); color:#B45309; border:1px solid rgba(202,138,4,.2); }
.dm-idsr-tag--epi   { background:rgba(220,38,38,.1);  color:#B91C1C; border:1px solid rgba(220,38,38,.18); }
.dm-idsr-tag--def   { background:rgba(0,112,224,.08); color:#0070E0; border:1px solid rgba(0,112,224,.15); }
.dm-idsr-text { flex:1; min-width:0; }

/* Syndrome grid */
.dm-syn-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:7px; margin-bottom:10px; }
.dm-syn-card {
  background:linear-gradient(135deg,#ECFEFF,#CFFAFE);
  border:1.5px solid rgba(0,143,122,.15);
  border-radius:10px; padding:10px 12px;
  position:relative; overflow:hidden;
}
.dm-syn-card::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.8) 50%,transparent 80%); }
.dm-syn-card-name { font-size:11px; font-weight:700; color:#008F7A; line-height:1.3; margin-bottom:3px; text-transform:capitalize; }
.dm-syn-card-sub  { font-size:9px; color:rgba(0,143,122,.6); font-weight:600; }
.dm-info-note { font-size:10px; color:#475569; line-height:1.5; padding:9px 12px; background:linear-gradient(135deg,#ECFEFF,#CFFAFE); border-radius:8px; border:1px solid rgba(0,143,122,.12); }

/* Case definition timeline */
.dm-casedef-timeline { display:flex; flex-direction:column; gap:0; margin-bottom:12px; }
.dm-casedef-step     { display:flex; gap:0; }
.dm-casedef-node     { display:flex; flex-direction:column; align-items:center; width:28px; flex-shrink:0; }
.dm-casedef-dot      { width:14px; height:14px; border-radius:50%; flex-shrink:0; border:2px solid white; box-shadow:0 0 8px rgba(0,0,0,.15); }
.dm-casedef-dot--suspected { background:#CC8800; box-shadow:0 0 8px rgba(204,136,0,.4); }
.dm-casedef-dot--probable  { background:#0070E0; box-shadow:0 0 8px rgba(0,112,224,.4); }
.dm-casedef-dot--confirmed { background:#00A86B; box-shadow:0 0 8px rgba(0,168,107,.4); }
.dm-casedef-dot--ihr       { background:#7B40D8; box-shadow:0 0 8px rgba(123,64,216,.4); }
.dm-casedef-line { flex:1; width:2px; background:rgba(0,0,0,.08); margin:3px 0; min-height:12px; }
.dm-casedef-content {
  flex:1; padding:0 0 16px 10px;
}
.dm-casedef-tier  { font-size:8px; font-weight:800; letter-spacing:1px; margin-bottom:5px; font-family:'JetBrains Mono',monospace; }
.dm-casedef-step--suspected .dm-casedef-tier { color:#CC8800; }
.dm-casedef-step--probable  .dm-casedef-tier { color:#0070E0; }
.dm-casedef-step--confirmed .dm-casedef-tier { color:#00A86B; }
.dm-casedef-step--ihr       .dm-casedef-tier { color:#7B40D8; }
.dm-casedef-text  { font-size:11px; color:#475569; line-height:1.5; }

.dm-casedef-content-card {
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:10px; padding:12px;
}

/* POE action block */
.dm-poe-action-block {
  background:linear-gradient(135deg,#FEF2F2,#FECACA);
  border:1.5px solid rgba(224,32,80,.22);
  border-radius:12px; padding:14px; position:relative; overflow:hidden;
}
.dm-poe-action-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.7) 50%,transparent 80%); }
.dm-poe-action-hdr  { font-size:9px; font-weight:800; color:#E02050; letter-spacing:.8px; margin-bottom:6px; font-family:'DM Sans',sans-serif; }
.dm-poe-action-text { font-size:12px; color:#B01840; font-weight:700; line-height:1.5; }

/* Key distinguishers */
.dm-dist-list { display:flex; flex-direction:column; gap:6px; }
.dm-dist-row  {
  display:flex; align-items:flex-start; gap:10px; padding:10px 12px;
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:10px;
  box-shadow:0 1px 3px rgba(0,0,0,.03);
  animation:slideUp .35s cubic-bezier(.16,1,.3,1) both;
}
.dm-dist-row::before { display:none; }
.dm-dist-num  {
  width:22px; height:22px; border-radius:7px; flex-shrink:0;
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF);
  color:#0070E0; font-size:9px; font-weight:900;
  display:flex; align-items:center; justify-content:center;
  border:1px solid rgba(0,112,224,.18);
}
.dm-dist-text { font-size:11px; font-weight:600; color:#0B1A30; line-height:1.5; padding-top:2px; }

/* Endemic countries */
.dm-endemic-banner {
  display:flex; align-items:center; gap:12px;
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF);
  border:1.5px solid rgba(0,112,224,.2); border-radius:12px; padding:13px 14px;
  margin-bottom:12px; position:relative; overflow:hidden;
  flex-wrap:wrap;
}
.dm-endemic-banner-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.7) 50%,transparent 80%); }
.dm-endemic-stat  { display:flex; flex-direction:column; align-items:center; }
.dm-endemic-n     { font-size:22px; font-weight:900; font-family:'Syne',sans-serif; color:#0B1A30; line-height:1; }
.dm-endemic-l     { font-size:8px; font-weight:700; color:#475569; letter-spacing:.6px; text-transform:uppercase; }
.dm-endemic-sep   { width:1px; height:32px; background:rgba(0,112,224,.18); }
.dm-endemic-desc  { flex:1; font-size:10px; color:#475569; line-height:1.4; min-width:120px; }

.dm-country-grid  { display:flex; flex-wrap:wrap; gap:5px; }
.dm-country-chip  {
  font-size:9px; font-weight:700; padding:4px 9px; border-radius:5px;
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF);
  color:#0070E0; border:1px solid rgba(0,112,224,.15);
  font-family:'JetBrains Mono',monospace;
  transition:all .15s;
}
.dm-country-chip:hover { background:linear-gradient(135deg,#0055CC,#0070E0); color:#fff; box-shadow:0 2px 8px rgba(0,112,224,.25); }

/* ── FORMULA STRIP ────────────────────────────────────────────────────── */
.dm-formula-strip {
  background:linear-gradient(135deg,#0A1628 0%,#0E1A2E 100%);
  border:1px solid rgba(0,180,255,.12); border-radius:14px;
  padding:16px 14px; margin-bottom:20px; position:relative; overflow:hidden;
}
.dm-formula-strip::before {
  content:''; position:absolute; top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg,transparent 15%,rgba(0,180,255,.3) 50%,transparent 85%);
}
.dm-formula-eyebrow { font-size:8px; font-weight:700; color:#7E92AB; letter-spacing:1.2px; text-transform:uppercase; margin-bottom:10px; font-family:'DM Sans',sans-serif; }
.dm-formula-eq { display:flex; flex-wrap:wrap; align-items:center; gap:5px; }
.dm-formula-token {
  font-size:9px; font-weight:700; padding:4px 8px; border-radius:5px;
  font-family:'JetBrains Mono',monospace; letter-spacing:.3px;
}
.dm-formula-token--gate { background:rgba(0,180,255,.12); color:#00B4FF; border:1px solid rgba(0,180,255,.2); }
.dm-formula-token--sym  { background:rgba(255,61,113,.1);  color:#FF3D71; border:1px solid rgba(255,61,113,.18); }
.dm-formula-token--exp  { background:rgba(179,136,255,.12); color:#B388FF; border:1px solid rgba(179,136,255,.2); }
.dm-formula-token--syn  { background:rgba(0,229,255,.08);  color:#00E5FF; border:1px solid rgba(0,229,255,.15); }
.dm-formula-token--ob   { background:rgba(0,230,118,.1);   color:#00E676; border:1px solid rgba(0,230,118,.18); }
.dm-formula-token--vax  { background:rgba(255,179,0,.1);   color:#FFB300; border:1px solid rgba(255,179,0,.18); }
.dm-formula-token--ons  { background:rgba(0,229,255,.08);  color:#00E5FF; border:1px solid rgba(0,229,255,.15); }
.dm-formula-token--neg  { background:rgba(255,61,113,.08); color:#FF6B93; border:1px solid rgba(255,61,113,.15); }
.dm-formula-token--ov   { background:rgba(255,179,0,.12);  color:#FFB300; border:1px solid rgba(255,179,0,.2); }
.dm-formula-op { font-size:12px; color:#4A5874; font-weight:700; }

/* ── GATE VISUAL ─────────────────────────────────────────────────────── */
.dm-gate-explain { font-size:10px; color:#475569; line-height:1.6; margin-bottom:14px; padding:10px 13px; background:linear-gradient(145deg,#FFFFFF,#F4F7FC); border:1.5px solid rgba(0,0,0,.06); border-radius:10px; }
.dm-gate-visual  { display:flex; flex-direction:column; gap:10px; }

.dm-gate-outcomes-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; margin-bottom:4px; }
.dm-gate-outcome { border-radius:10px; padding:12px 8px; text-align:center; position:relative; overflow:hidden; }
.dm-gate-outcome::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.7) 50%,transparent 80%); }
.dm-gate-outcome--pass { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); border:1.5px solid rgba(0,168,107,.18); }
.dm-gate-outcome--soft { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border:1.5px solid rgba(204,136,0,.18); }
.dm-gate-outcome--hard { background:linear-gradient(135deg,#FEF2F2,#FECACA); border:1.5px solid rgba(224,32,80,.2); }
.dm-gate-outcome-val { display:block; font-size:16px; font-weight:900; font-family:'Syne',sans-serif; line-height:1; margin-bottom:3px; }
.dm-gate-outcome--pass .dm-gate-outcome-val { color:#00A86B; }
.dm-gate-outcome--soft .dm-gate-outcome-val { color:#CC8800; }
.dm-gate-outcome--hard .dm-gate-outcome-val { color:#E02050; }
.dm-gate-outcome-lbl { font-size:7px; font-weight:800; letter-spacing:.8px; color:rgba(0,0,0,.4); font-family:'JetBrains Mono',monospace; }

.dm-gate-block { border-radius:12px; overflow:hidden; position:relative; }
.dm-gate-block-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.7) 50%,transparent 80%); }
.dm-gate-block--all  { background:linear-gradient(135deg,#EFF8FF,#DBEAFE); border:1.5px solid rgba(0,112,224,.15); }
.dm-gate-block--any  { background:linear-gradient(135deg,#F0FDF4,#DCFCE7); border:1.5px solid rgba(0,168,107,.15); }
.dm-gate-block--hard { background:linear-gradient(135deg,#FFF1F2,#FFE4E6); border:1.5px solid rgba(224,32,80,.2); }
.dm-gate-block-header { display:flex; align-items:center; gap:8px; padding:11px 13px 0; }
.dm-gate-block-icon  { width:28px; height:28px; border-radius:8px; font-size:11px; font-weight:900; font-family:'JetBrains Mono',monospace; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.dm-gate-block-icon--all  { background:#0070E0; color:#fff; }
.dm-gate-block-icon--any  { background:#00A86B; color:#fff; }
.dm-gate-block-icon--hard { background:#E02050; color:#fff; }
.dm-gate-block-title   { font-size:10px; font-weight:800; color:#0B1A30; flex:1; }
.dm-gate-block-penalty { font-size:9px; font-weight:600; color:#94A3B8; white-space:nowrap; font-family:'JetBrains Mono',monospace; }
.dm-gate-sym-grid { display:flex; flex-wrap:wrap; gap:5px; padding:10px 13px 13px; }
.dm-gate-sym      { display:flex; align-items:center; gap:5px; font-size:10px; font-weight:600; color:#0B1A30; padding:4px 8px; border-radius:6px; }
.dm-gate-sym--all  { background:rgba(0,112,224,.08); }
.dm-gate-sym--any  { background:rgba(0,168,107,.08); }
.dm-gate-sym--hard { background:rgba(224,32,80,.08); color:#B01840; }
.dm-gate-sym-dot  { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.dm-gate-sym--all  .dm-gate-sym-dot { background:#0070E0; box-shadow:0 0 4px rgba(0,112,224,.5); }
.dm-gate-sym--any  .dm-gate-sym-dot { background:#00A86B; box-shadow:0 0 4px rgba(0,168,107,.5); }
.dm-gate-sym--hard .dm-gate-sym-dot { background:#E02050; box-shadow:0 0 4px rgba(224,32,80,.6); animation:pulse-red 1.5s infinite; }
.dm-gate-none { font-size:11px; color:#94A3B8; text-align:center; padding:20px; font-style:italic; }

/* ── SYMPTOM BARS ─────────────────────────────────────────────────────── */
.dm-weight-legend { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
.dm-wl-item { font-size:8px; font-weight:700; padding:3px 8px; border-radius:4px; letter-spacing:.3px; }
.dm-wl-item--path   { background:rgba(176,24,64,.1); color:#B01840; border:1px solid rgba(176,24,64,.15); }
.dm-wl-item--strong { background:rgba(204,136,0,.1); color:#CC8800; border:1px solid rgba(204,136,0,.15); }
.dm-wl-item--mod    { background:rgba(0,85,204,.1);  color:#0055CC; border:1px solid rgba(0,85,204,.15); }
.dm-wl-item--weak   { background:rgba(0,122,80,.1);  color:#007A50; border:1px solid rgba(0,122,80,.15); }

.dm-sym-bars { display:flex; flex-direction:column; gap:4px; }
.dm-sym-bar-row {
  display:grid; grid-template-columns:1fr 100px 32px;
  align-items:center; gap:10px;
  padding:7px 10px; border-radius:8px;
  animation:slideUp .4s cubic-bezier(.16,1,.3,1) both;
  transition:background .12s;
}
.dm-sym-bar-row:hover { background:rgba(0,0,0,.03); }
.dm-sym-bar-row--hm  {
  background:linear-gradient(145deg,#EFF8FF,#DBEAFE);
  border:1px solid rgba(0,112,224,.12);
  box-shadow:0 1px 4px rgba(0,112,224,.06);
}
.dm-sym-bar-label { display:flex; align-items:center; gap:7px; min-width:0; }
.dm-sym-bar-name  { font-size:11px; font-weight:600; color:#475569; line-height:1.3; }
.dm-hm-badge      { font-size:7px; font-weight:800; color:#0070E0; background:rgba(0,112,224,.1); padding:2px 5px; border-radius:3px; flex-shrink:0; letter-spacing:.4px; white-space:nowrap; }
.dm-sym-bar-track { height:6px; background:rgba(0,0,0,.07); border-radius:4px; overflow:hidden; position:relative; }
.dm-sym-bar-fill  { height:100%; border-radius:4px; transition:width .7s cubic-bezier(.16,1,.3,1); }
.dm-sym-bar-glow  { position:absolute; top:0; right:0; height:100%; width:30%; background:inherit; filter:blur(4px); opacity:.6; pointer-events:none; }
.dm-sym-bar-fill--exp { background:linear-gradient(90deg,#7B40D8,#B388FF); }
.dm-sym-bar-glow--exp { background:linear-gradient(90deg,#7B40D8,#B388FF); }
.dm-sym-bar-val   { font-size:10px; font-weight:700; color:#475569; font-family:'JetBrains Mono',monospace; text-align:right; }

/* ── PENALTIES ────────────────────────────────────────────────────────── */
.dm-penalty-banner { font-size:10px; color:#0B1A30; line-height:1.5; margin-bottom:10px; padding:10px 13px; background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border-radius:10px; border:1.5px solid rgba(204,136,0,.18); font-weight:500; }
.dm-penalty-list   { display:flex; flex-direction:column; gap:0; }
.dm-penalty-row    { display:grid; grid-template-columns:1fr 70px 36px; align-items:center; gap:8px; padding:7px 0; border-bottom:1px solid rgba(0,0,0,.05); }
.dm-penalty-sym    { font-size:11px; font-weight:600; color:#475569; }
.dm-penalty-bar-wrap { height:5px; background:rgba(0,0,0,.07); border-radius:3px; overflow:hidden; }
.dm-penalty-bar    { height:100%; background:linear-gradient(90deg,#B01840,#E02050); border-radius:3px; }
.dm-penalty-val    { font-size:10px; font-weight:800; color:#E02050; font-family:'JetBrains Mono',monospace; text-align:right; }

/* ── CONTRADICTIONS ───────────────────────────────────────────────────── */
.dm-contra-banner { font-size:10px; color:#0B1A30; line-height:1.5; margin-bottom:10px; padding:10px 13px; background:linear-gradient(135deg,#FFF1F2,#FFE4E6); border-radius:10px; border:1.5px solid rgba(224,32,80,.18); }
.dm-contra-list   { display:flex; flex-direction:column; gap:4px; }
.dm-contra-row    { display:grid; grid-template-columns:1fr 70px 34px; align-items:center; gap:8px; padding:6px 0; border-bottom:1px solid rgba(0,0,0,.05); }
.dm-contra-sym    { font-size:11px; font-weight:600; color:#475569; }
.dm-contra-track  { height:5px; background:rgba(0,0,0,.07); border-radius:3px; overflow:hidden; }
.dm-contra-fill   { height:100%; background:linear-gradient(90deg,#B01840,#E02050); border-radius:3px; }
.dm-contra-val    { font-size:10px; font-weight:800; color:#E02050; font-family:'JetBrains Mono',monospace; text-align:right; }

/* ── OVERRIDE CARDS ───────────────────────────────────────────────────── */
.dm-override-intro { font-size:10px; color:#475569; line-height:1.5; margin-bottom:12px; }
.dm-override-cards { display:flex; flex-direction:column; gap:9px; }
.dm-override-card  {
  background:linear-gradient(135deg,#FFFBEB,#FEF3C7);
  border:1.5px solid rgba(204,136,0,.2); border-radius:12px; padding:13px;
  position:relative; overflow:hidden;
  animation:slideUp .4s cubic-bezier(.16,1,.3,1) both;
}
.dm-override-card-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.8) 50%,transparent 80%); }
.dm-override-card-top   { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
.dm-override-card-id    { font-size:9px; font-weight:800; color:#CC8800; text-transform:uppercase; letter-spacing:.5px; font-family:'DM Sans',sans-serif; }
.dm-override-card-boost { font-size:13px; font-weight:900; color:#00A86B; font-family:'Syne',sans-serif; }
.dm-override-card-desc  { font-size:11px; color:#0B1A30; line-height:1.5; margin-bottom:8px; font-weight:500; }
.dm-override-card-triggers { display:flex; align-items:center; gap:5px; flex-wrap:wrap; }
.dm-override-trigger-k  { font-size:8px; font-weight:800; padding:2px 6px; border-radius:3px; font-family:'JetBrains Mono',monospace; flex-shrink:0; }
.dm-override-trigger-k  { background:rgba(0,0,0,.06); color:#94A3B8; }
.dm-override-sym        { font-size:9px; font-weight:600; padding:3px 7px; border-radius:4px; }
.dm-override-sym--all   { background:rgba(0,112,224,.08); color:#0055CC; }
.dm-override-sym--any   { background:rgba(0,168,107,.08); color:#007A50; }

/* ── CONFIDENCE BANDS ─────────────────────────────────────────────────── */
.dm-bands { display:flex; flex-direction:column; gap:7px; }
.dm-band  {
  display:flex; gap:0; border-radius:10px; overflow:hidden;
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06);
  box-shadow:0 1px 3px rgba(0,0,0,.03);
}
.dm-band-color { width:5px; flex-shrink:0; }
.dm-band-body  { flex:1; padding:10px 13px; display:grid; grid-template-columns:1fr auto; grid-template-rows:auto auto; gap:3px 12px; }
.dm-band-name  { font-size:10px; font-weight:800; color:#0B1A30; font-family:'JetBrains Mono',monospace; }
.dm-band-score { font-size:10px; font-weight:700; color:#94A3B8; font-family:'JetBrains Mono',monospace; text-align:right; }
.dm-band-action { font-size:10px; color:#475569; grid-column:1/-1; }

/* ── EXPOSURE INTRO CARD ──────────────────────────────────────────────── */
.dm-exp-intro-card {
  background:linear-gradient(135deg,#F5F3FF,#EDE9FE);
  border:1.5px solid rgba(123,64,216,.15); border-radius:14px; padding:15px;
  margin-bottom:20px; position:relative; overflow:hidden;
}
.dm-exp-intro-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.8) 50%,transparent 80%); }
.dm-exp-intro-title { font-size:11px; font-weight:800; color:#7B40D8; margin-bottom:6px; font-family:'DM Sans',sans-serif; }
.dm-exp-intro-text  { font-size:10px; color:#475569; line-height:1.6; }

/* Vaccination cards */
.dm-vax-note  { font-size:10px; color:#475569; line-height:1.5; margin-bottom:10px; }
.dm-vax-cards { display:flex; flex-direction:column; gap:8px; }
.dm-vax-card  {
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:12px; padding:13px;
  position:relative; overflow:hidden;
}
.dm-vax-card-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.85) 50%,transparent 80%); }
.dm-vax-name   { font-size:8px; font-weight:800; color:#94A3B8; letter-spacing:.8px; text-transform:uppercase; margin-bottom:9px; font-family:'DM Sans',sans-serif; }
.dm-vax-states { display:flex; flex-direction:column; gap:0; }
.dm-vax-state  { display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-bottom:1px solid rgba(0,0,0,.05); }
.dm-vax-state:last-child { border-bottom:none; }
.dm-vax-status  { font-size:11px; font-weight:600; color:#475569; }
.dm-vax-mod--good { font-size:11px; font-weight:800; color:#00A86B; font-family:'JetBrains Mono',monospace; }
.dm-vax-mod--bad  { font-size:11px; font-weight:800; color:#E02050; font-family:'JetBrains Mono',monospace; }

/* Onset cards */
.dm-onset-note  { font-size:10px; color:#475569; line-height:1.5; margin-bottom:10px; }
.dm-onset-cards { display:grid; grid-template-columns:1fr 1fr; gap:7px; }
.dm-onset-card  {
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,112,224,.1); border-radius:10px; padding:11px 12px;
  display:flex; flex-direction:column; gap:4px; align-items:flex-start;
}
.dm-onset-period { font-size:9px; font-weight:700; color:#475569; font-family:'JetBrains Mono',monospace; }
.dm-onset-mod    { font-size:15px; font-weight:900; color:#0070E0; font-family:'Syne',sans-serif; }

/* ── ACTION CARDS ─────────────────────────────────────────────────────── */
.dm-actions-list { display:flex; flex-direction:column; gap:7px; }
.dm-action-card  {
  display:flex; align-items:flex-start; gap:12px;
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:12px; padding:13px;
  box-shadow:0 1px 3px rgba(0,0,0,.03);
  position:relative; overflow:hidden;
  animation:slideUp .4s cubic-bezier(.16,1,.3,1) both;
}
.dm-action-card-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.85) 50%,transparent 80%); }
.dm-action-num  {
  width:28px; height:28px; border-radius:9px; flex-shrink:0;
  background:linear-gradient(135deg,#0055CC,#0070E0);
  color:#fff; font-size:11px; font-weight:900;
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 3px 10px rgba(0,112,224,.3); font-family:'Syne',sans-serif;
}
.dm-action-text { font-size:12px; font-weight:600; color:#0B1A30; line-height:1.5; padding-top:4px; }

/* Lab tests */
.dm-lab-notice  {
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF);
  border:1.5px solid rgba(0,112,224,.18); border-radius:12px; padding:13px;
  margin-bottom:12px; font-size:11px; color:#0B1A30; line-height:1.5;
  position:relative; overflow:hidden;
}
.dm-lab-notice-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.7) 50%,transparent 80%); }
.dm-test-list { display:flex; flex-direction:column; gap:6px; }
.dm-test-card {
  display:flex; align-items:flex-start; gap:10px;
  padding:10px 12px;
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:10px;
  animation:slideUp .35s cubic-bezier(.16,1,.3,1) both;
}
.dm-test-ic   { font-size:15px; flex-shrink:0; }
.dm-test-text { font-size:11px; color:#475569; line-height:1.4; }

/* System behaviour cards */
.dm-sys-cards { display:flex; flex-direction:column; gap:7px; }
.dm-sys-card  {
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:12px; padding:13px;
  position:relative; overflow:hidden;
}
.dm-sys-card::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.85) 50%,transparent 80%); }
.dm-sys-card--alert { border-color:rgba(0,112,224,.15); }
.dm-sys-card-key { font-size:8px; font-weight:700; color:#94A3B8; letter-spacing:.8px; text-transform:uppercase; margin-bottom:5px; font-family:'DM Sans',sans-serif; }
.dm-sys-card-val { font-size:12px; font-weight:700; color:#0B1A30; line-height:1.4; }
.dm-sys-alert--critical { color:#E02050; font-family:'JetBrains Mono',monospace; font-size:14px; font-weight:900; }
.dm-sys-alert--high     { color:#CC8800; font-family:'JetBrains Mono',monospace; font-size:14px; font-weight:900; }
.dm-sys-alert--medium   { color:#0070E0; font-family:'JetBrains Mono',monospace; font-size:14px; font-weight:900; }

/* Basis card */
.dm-basis-card {
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:12px; padding:14px;
  position:relative; overflow:hidden;
}
.dm-basis-card-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.85) 50%,transparent 80%); }
.dm-basis-text { font-size:12px; color:#475569; line-height:1.7; font-style:italic; }

</style>