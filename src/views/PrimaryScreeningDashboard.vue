<template>
  <IonPage>
    <!-- ═══════════════════════════════════════════════════════════════
         HEADER — dark navy command bar
    ═══════════════════════════════════════════════════════════════════ -->
    <IonHeader class="psd-hdr" translucent>
      <IonToolbar class="psd-tb">
        <IonButtons slot="start"><IonMenuButton menu="app-menu"/></IonButtons>
        <div class="psd-title-block" slot="start">
          <span class="psd-poe-lbl">{{ auth.poe_code || scopeOf(auth).code || 'POE' }}</span>
          <span class="psd-page-h1">Intelligence</span>
        </div>
        <IonButtons slot="end">
          <div class="psd-role-pill">{{ roleLabel }}</div>
          <div :class="['psd-live', liveData && 'psd-live--on']">
            <span class="psd-live-dot"/>
            <span class="psd-live-n">{{ liveData?.total ?? '--' }}</span>
            <span class="psd-live-l">today</span>
          </div>
          <button class="psd-icon-btn" @click="onPDF" :disabled="pdfBusy"
            :title="pdfBusy ? 'Generating report…' : 'Download 8-page intelligence report'">
            <svg v-if="!pdfBusy" width="17" height="17" viewBox="0 0 24 24" fill="none"
              stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
              <polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>
            </svg>
            <IonSpinner v-else name="crescent" class="psd-spin-sm"/>
          </button>
          <IonButton fill="clear" class="psd-icon-btn-ion" @click="loadAll" :disabled="loading">
            <IonIcon :icon="refreshOutline" slot="icon-only"/>
          </IonButton>
        </IonButtons>
      </IonToolbar>

      <!-- 2026-05-07: RBAC scope banner. Open to every role; data is
           server-scoped to the user's tier. Surfaced PROMINENTLY so the
           viewer always knows what scope they're looking at. -->
      <div class="psd-scope-banner" :data-tier="scopeBanner.tier">
        <span class="psd-scope-stripe" :style="{ background: scopeBanner.dot }"></span>
        <div class="psd-scope-body">
          <div class="psd-scope-row1">
            <span class="psd-scope-tier">{{ scopeBanner.tier }}</span>
            <span class="psd-scope-sep">·</span>
            <span class="psd-scope-name">{{ scopeBanner.scope }}</span>
            <span class="psd-scope-role">{{ scopeBanner.role }}</span>
          </div>
          <div class="psd-scope-row2">{{ scopeBanner.description }}</div>
        </div>
      </div>

      <!-- Period filter pills -->
      <div class="psd-fbar">
        <div class="psd-fscroll">
          <button v-for="p in QUICK_PERIODS" :key="p.v"
            :class="['psd-fpill', activeFilter === p.v && 'psd-fpill--on']"
            @click="setFilter(p.v)">{{ p.l }}</button>
        </div>
        <button :class="['psd-fadv-btn', showAdvFilter && 'psd-fadv-btn--on']"
          @click="showAdvFilter = !showAdvFilter">
          {{ advFilterCount ? advFilterCount + ' ' : '' }}&equiv;
        </button>
      </div>

      <!-- Advanced filter panel -->
      <transition name="psd-slide">
        <div v-if="showAdvFilter" class="psd-adv-panel">
          <div class="psd-adv-row">
            <span class="psd-adv-lbl">Date</span>
            <input type="date" v-model="filterDate" class="psd-adv-input" @change="activeFilter = 'custom'"/>
          </div>
          <div class="psd-adv-row">
            <span class="psd-adv-lbl">Month</span>
            <div class="psd-adv-pills">
              <button v-for="m in MONTHS" :key="m.v"
                :class="['psd-mpill', filterMonth === m.v && 'psd-mpill--on']"
                @click="filterMonth = filterMonth === m.v ? null : m.v; activeFilter = 'custom'">{{ m.l }}</button>
            </div>
          </div>
          <div class="psd-adv-row">
            <span class="psd-adv-lbl">Year</span>
            <div class="psd-adv-pills">
              <button v-for="yr in YEARS" :key="yr"
                :class="['psd-mpill', filterYear === yr && 'psd-mpill--on']"
                @click="filterYear = filterYear === yr ? null : yr; activeFilter = 'custom'">{{ yr }}</button>
            </div>
          </div>
          <div class="psd-adv-row">
            <span class="psd-adv-lbl">Range</span>
            <input type="date" v-model="filterFrom" class="psd-adv-input psd-adv-input--h" @change="activeFilter = 'custom'"/>
            <input type="date" v-model="filterTo" class="psd-adv-input psd-adv-input--h" @change="activeFilter = 'custom'"/>
          </div>
          <div class="psd-adv-actions">
            <button class="psd-btn-apply" @click="loadAll(); showAdvFilter = false">Apply</button>
            <button class="psd-btn-clear" @click="clearFilters">Clear</button>
          </div>
        </div>
      </transition>
    </IonHeader>

    <!-- ═══════════════════════════════════════════════════════════════
         CONTENT
    ═══════════════════════════════════════════════════════════════════ -->
    <IonContent class="psd-content" :fullscreen="true">
      <IonRefresher slot="fixed" @ionRefresh="pullRefresh($event)">
        <IonRefresherContent pulling-text="Pull to refresh" refreshing-spinner="crescent"/>
      </IonRefresher>

      <!-- Offline banner -->
      <div v-if="!isOnline" class="psd-offline-bar">
        <span class="psd-offline-dot"/>
        Offline — cached data{{ lastSyncAt ? ' from ' + fmtRel(lastSyncAt) : '' }}
      </div>

      <!-- ── Skeleton ─────────────────────────────────────────────── -->
      <div v-if="loading && !sum" class="psd-skeletons">
        <div class="psd-skel psd-skel--hero"/>
        <div class="psd-skel-chips"><div class="psd-skel psd-skel--chip" v-for="n in 4" :key="n"/></div>
        <div class="psd-skel psd-skel--section"/>
        <div class="psd-skel psd-skel--section"/>
        <div class="psd-skel psd-skel--section"/>
      </div>

      <!-- ── Main body ───────────────────────────────────────────── -->
      <div v-else-if="sum" class="psd-body">

        <!-- ─ Hero: ring + key counts ─ -->
        <div class="psd-hero" @click="openSheet('summary')">
          <div class="psd-ring-wrap">
            <svg viewBox="0 0 100 100">
              <circle cx="50" cy="50" r="42" fill="none" stroke="#E8EDF5" stroke-width="6"/>
              <circle cx="50" cy="50" r="42" fill="none" :stroke="srColor" stroke-width="6"
                stroke-linecap="round" :stroke-dasharray="srDash" transform="rotate(-90 50 50)"
                class="psd-ring-anim"/>
            </svg>
            <div class="psd-ring-ctr">
              <span class="psd-ring-pct">{{ sr }}<small>%</small></span>
              <span class="psd-ring-lbl">{{ activeFilter === 'today' ? 'today symp.' : filterLabel + ' symp.' }}</span>
            </div>
          </div>
          <div class="psd-hero-stats">
            <div class="psd-alltime-n">{{ sum.all_time?.total ?? '--' }}</div>
            <div class="psd-alltime-l">Total screened · all time <span class="psd-tap-hint">Tap for detail ›</span></div>
            <div class="psd-mini-row">
              <div class="psd-mini"><span class="psd-mini-n">{{ sum.today?.total ?? 0 }}</span><span class="psd-mini-l">Today</span></div>
              <div class="psd-mini" :class="delta >= 0 ? 'psd-mini--up' : 'psd-mini--dn'">
                <span class="psd-mini-n">{{ delta >= 0 ? '+' : '' }}{{ delta }}</span><span class="psd-mini-l">vs yesterday</span>
              </div>
              <div class="psd-mini"><span class="psd-mini-n">{{ sum.this_week?.total ?? 0 }}</span><span class="psd-mini-l">This week</span></div>
              <div class="psd-mini"><span class="psd-mini-n">{{ sum.this_month?.total ?? 0 }}</span><span class="psd-mini-l">This month</span></div>
            </div>
          </div>
        </div>
        <p class="psd-interp">{{ srInterpretation }}</p>
        <p class="psd-legend">Green &lt;10% · Amber 10–20% · Red &gt;20%</p>

        <!-- ─ Screening Analysis — period-filtered (open by default) ─ -->
        <div class="psd-acc psd-acc--analysis">
          <div class="psd-acc-hd" @click="toggleSection('analysis')">
            <span class="psd-acc-ico">📊</span>
            <div class="psd-acc-meta">
              <span class="psd-acc-title">Screening Analysis</span>
              <span class="psd-acc-sub psd-acc-sub--period">{{ filterLabel }}
                <template v-if="periodSum?.period?.from"> · {{ periodSum.period.from }} → {{ periodSum.period.to }}</template>
              </span>
            </div>
            <span v-if="(periodSum?.symptomatic_rate ?? 0) >= 20" class="psd-badge psd-badge--red">{{ periodSum.symptomatic_rate }}%</span>
            <svg :class="['psd-chev', open.analysis && 'psd-chev--open']" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div :class="['psd-acc-bd', open.analysis && 'psd-acc-bd--open']">
            <div v-if="periodSum" class="psd-analysis-grid">
              <div class="psd-an-cell">
                <span class="psd-an-n">{{ periodSum.total ?? 0 }}</span>
                <span class="psd-an-l">Screened</span>
              </div>
              <div class="psd-an-cell" :class="(periodSum.symptomatic_rate ?? 0) >= 20 && 'psd-an-cell--alert'">
                <span class="psd-an-n">{{ periodSum.symptomatic ?? 0 }}</span>
                <span class="psd-an-sub">{{ periodSum.symptomatic_rate ?? 0 }}%</span>
                <span class="psd-an-l">Symptomatic</span>
              </div>
              <div class="psd-an-cell">
                <span class="psd-an-n">{{ periodSum.referrals ?? 0 }}</span>
                <span class="psd-an-l">Referrals</span>
              </div>
              <div class="psd-an-cell" :class="(periodSum.fever_count ?? 0) > 0 && 'psd-an-cell--warn'">
                <span class="psd-an-n">{{ periodSum.fever_count ?? 0 }}</span>
                <span class="psd-an-l">Fever</span>
              </div>
              <div class="psd-an-cell" :class="(periodSum.high_fever_count ?? 0) > 0 && 'psd-an-cell--alert'">
                <span class="psd-an-n">{{ periodSum.high_fever_count ?? 0 }}</span>
                <span class="psd-an-l">High Fever</span>
              </div>
              <div class="psd-an-cell">
                <span class="psd-an-n">{{ periodSum.avg_temp_c != null ? Number(periodSum.avg_temp_c).toFixed(1) + '°' : '--' }}</span>
                <span class="psd-an-l">Avg Temp</span>
              </div>
            </div>
            <div v-if="periodSum" class="psd-analysis-meta">
              <div class="psd-ameta-row">
                <span class="psd-ameta-k">Gender</span>
                <span class="psd-ameta-v">
                  <span class="psd-gender-dot psd-gender-dot--m"/>{{ periodSum.male ?? 0 }} Male &nbsp;·&nbsp;
                  <span class="psd-gender-dot psd-gender-dot--f"/>{{ periodSum.female ?? 0 }} Female
                </span>
              </div>
              <div class="psd-ameta-row" v-if="periodSum.first_at">
                <span class="psd-ameta-k">First record</span>
                <span class="psd-ameta-v">{{ periodSum.first_at?.slice(0, 10) }}</span>
              </div>
              <div class="psd-ameta-row" v-if="periodSum.last_at">
                <span class="psd-ameta-k">Last record</span>
                <span class="psd-ameta-v">{{ periodSum.last_at?.slice(0, 10) }}</span>
              </div>
            </div>
            <div v-else class="psd-analysis-empty">No data for this period</div>
          </div>
        </div>

        <!-- ─ Urgent KPIs 2×2 grid ─ -->
        <div class="psd-urgent-grid">
          <div class="psd-u-card" :class="(sum.today?.fever_count ?? 0) > 0 && 'psd-u-card--amber'">
            <span class="psd-u-n">{{ sum.today?.fever_count ?? 0 }}</span>
            <span class="psd-u-l">Fever today</span>
          </div>
          <div class="psd-u-card" :class="(sum.referral_queue?.open ?? 0) > 0 && 'psd-u-card--amber'"
            @click="toggleSection('pipeline')">
            <span class="psd-u-n">{{ sum.referral_queue?.open ?? 0 }}</span>
            <span class="psd-u-l">Open referrals</span>
          </div>
          <div class="psd-u-card" :class="(sum.alerts?.open ?? 0) > 0 && 'psd-u-card--red'"
            @click="toggleSection('alerts')">
            <span class="psd-u-n">{{ sum.alerts?.open ?? 0 }}</span>
            <span class="psd-u-l">Open alerts</span>
          </div>
          <div class="psd-u-card" :class="(sum.all_time?.unsynced ?? 0) > 0 && 'psd-u-card--amber'">
            <span class="psd-u-n">{{ sum.all_time?.unsynced ?? 0 }}</span>
            <span class="psd-u-l">Not uploaded</span>
          </div>
        </div>

        <!-- ─ Surveillance signals ─ -->
        <template v-if="signals.length">
          <div class="psd-signals-hdr">
            <span class="psd-pulse-dot"/>
            <span class="psd-signals-title">Surveillance Signals</span>
            <span class="psd-badge psd-badge--red">{{ signals.length }}</span>
          </div>
          <div class="psd-signals-list">
            <div v-for="(sig, i) in signals" :key="i"
              :class="['psd-signal', `psd-signal--${sig.level}`]" @click="activeSig = sig">
              <div :class="['psd-sig-ico', `psd-sig-ico--${sig.level}`]">{{ sig.ico }}</div>
              <div class="psd-sig-body">
                <span class="psd-sig-cat">{{ sig.category }}</span>
                <span class="psd-sig-title">{{ sig.title }}</span>
                <span class="psd-sig-desc">{{ sig.desc.length > 95 ? sig.desc.slice(0, 95) + '…' : sig.desc }}</span>
              </div>
              <svg class="psd-sig-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none"
                stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
            </div>
          </div>
        </template>

        <!-- ══ ACCORDION SECTIONS ════════════════════════════════════ -->

        <!-- § Trend & Volume — all roles -->
        <div v-if="trend?.series?.length" class="psd-acc">
          <div class="psd-acc-hd" @click="toggleSection('trend')">
            <span class="psd-acc-ico">📈</span>
            <div class="psd-acc-meta">
              <span class="psd-acc-title">Trend &amp; Volume</span>
              <span class="psd-acc-sub">{{ filterLabel }}</span>
            </div>
            <svg :class="['psd-chev', open.trend && 'psd-chev--open']" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div :class="['psd-acc-bd', open.trend && 'psd-acc-bd--open']">
            <div class="psd-trend-wrap">
              <svg :viewBox="'0 0 ' + TW + ' 80'" class="psd-tsvg" preserveAspectRatio="none">
                <path :d="trendAreaPath('total')" fill="#1565C0" opacity=".08"/>
                <polyline :points="trendLine('total')" fill="none" stroke="#1565C0" stroke-width="2" stroke-linejoin="round"/>
                <polyline :points="trendLine('symptomatic')" fill="none" stroke="#E53935" stroke-width="1.5" stroke-linejoin="round" stroke-dasharray="4,3"/>
              </svg>
              <div class="psd-txlbls"><span v-for="(l, i) in trendLabels" :key="i">{{ l }}</span></div>
              <div class="psd-tleg">
                <span class="psd-tleg-i psd-tleg-i--blue">Total</span>
                <span class="psd-tleg-i psd-tleg-i--red">Symptomatic</span>
              </div>
            </div>
          </div>
        </div>

        <!-- § Referral Pipeline — all roles -->
        <div v-if="fun?.funnel?.length" class="psd-acc">
          <div class="psd-acc-hd" @click="toggleSection('pipeline')">
            <span class="psd-acc-ico">⏳</span>
            <div class="psd-acc-meta">
              <span class="psd-acc-title">Referral Pipeline</span>
              <span class="psd-acc-sub">{{ sum?.referral_queue?.open ?? 0 }} open · {{ sum?.all_time?.referrals ?? 0 }} total</span>
            </div>
            <span v-if="(sum?.referral_queue?.open ?? 0) > 0" class="psd-badge psd-badge--amber">{{ sum.referral_queue.open }}</span>
            <svg :class="['psd-chev', open.pipeline && 'psd-chev--open']" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div :class="['psd-acc-bd', open.pipeline && 'psd-acc-bd--open']">
            <div class="psd-funnel-rows">
              <div v-for="f in fun.funnel" :key="f.stage" class="psd-fn">
                <div class="psd-fn-bg"><div class="psd-fn-fill" :style="{ width: funnelPct(f) + '%' }"/></div>
                <div class="psd-fn-row">
                  <span class="psd-fn-n">{{ f.count }}</span>
                  <span class="psd-fn-l">{{ f.description || f.stage }}</span>
                  <span class="psd-fn-p">{{ f.rate }}%</span>
                </div>
              </div>
            </div>
            <div class="psd-fn-kpis">
              <div class="psd-fn-kpi"><span class="psd-fn-kv">{{ fun.notifications?.avg_pickup_minutes ?? '--' }}</span><span class="psd-fn-kl">min pickup</span></div>
              <div class="psd-fn-kpi"><span class="psd-fn-kv">{{ fun.secondary_cases?.avg_case_duration_minutes ?? '--' }}</span><span class="psd-fn-kl">min/case</span></div>
              <div class="psd-fn-kpi"><span class="psd-fn-kv" :class="(fun.notifications?.open ?? 0) > 0 && 'psd-v--amber'">{{ fun.notifications?.open ?? 0 }}</span><span class="psd-fn-kl">open notif.</span></div>
              <div class="psd-fn-kpi"><span class="psd-fn-kv" :class="(fun.secondary_cases?.risk_critical ?? 0) > 0 && 'psd-v--red'">{{ fun.secondary_cases?.risk_critical ?? 0 }}</span><span class="psd-fn-kl">critical</span></div>
            </div>
          </div>
        </div>

        <!-- § Secondary Cases — all roles (counts), detail sheet for analytics+ -->
        <div v-if="fun?.secondary_cases?.total" class="psd-acc">
          <div class="psd-acc-hd" @click="toggleSection('secondary')">
            <span class="psd-acc-ico">🏥</span>
            <div class="psd-acc-meta">
              <span class="psd-acc-title">Secondary Cases</span>
              <span class="psd-acc-sub">{{ fun.secondary_cases.total }} total</span>
            </div>
            <span v-if="(fun.secondary_cases.risk_critical ?? 0) > 0" class="psd-badge psd-badge--red">{{ fun.secondary_cases.risk_critical }} critical</span>
            <svg :class="['psd-chev', open.secondary && 'psd-chev--open']" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div :class="['psd-acc-bd', open.secondary && 'psd-acc-bd--open']">
            <div class="psd-kpi-grid">
              <div class="psd-kpi-cell"><span class="psd-kpi-n psd-kpi-n--blue">{{ fun.secondary_cases.open ?? 0 }}</span><span class="psd-kpi-l">Waiting</span></div>
              <div class="psd-kpi-cell"><span class="psd-kpi-n psd-kpi-n--amber">{{ fun.secondary_cases.in_progress ?? 0 }}</span><span class="psd-kpi-l">In progress</span></div>
              <div class="psd-kpi-cell"><span class="psd-kpi-n psd-kpi-n--green">{{ fun.secondary_cases.closed ?? 0 }}</span><span class="psd-kpi-l">Closed</span></div>
              <div class="psd-kpi-cell"><span class="psd-kpi-n psd-kpi-n--red">{{ fun.secondary_cases.risk_critical ?? 0 }}</span><span class="psd-kpi-l">Critical</span></div>
              <div class="psd-kpi-cell"><span class="psd-kpi-n psd-kpi-n--amber">{{ fun.secondary_cases.risk_high ?? 0 }}</span><span class="psd-kpi-l">High risk</span></div>
              <div class="psd-kpi-cell"><span class="psd-kpi-n">{{ fun.secondary_cases.quarantined ?? 0 }}</span><span class="psd-kpi-l">Quarantined</span></div>
            </div>
            <button v-if="canSeeAnalytics" class="psd-detail-btn" @click="openSheet('secondary')">
              Full case breakdown ›
            </button>
          </div>
        </div>

        <!-- § IHR Alerts — all roles -->
        <div v-if="alertsData?.totals" class="psd-acc">
          <div class="psd-acc-hd" @click="toggleSection('alerts')">
            <span class="psd-acc-ico">🚨</span>
            <div class="psd-acc-meta">
              <span class="psd-acc-title">IHR Alerts</span>
              <span class="psd-acc-sub">{{ alertsData.totals.total ?? 0 }} total</span>
            </div>
            <span v-if="alertsData.totals.open" class="psd-badge psd-badge--red">{{ alertsData.totals.open }}</span>
            <svg :class="['psd-chev', open.alerts && 'psd-chev--open']" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div :class="['psd-acc-bd', open.alerts && 'psd-acc-bd--open']">
            <div class="psd-kpi-grid">
              <div class="psd-kpi-cell"><span class="psd-kpi-n">{{ alertsData.totals.total ?? 0 }}</span><span class="psd-kpi-l">Total</span></div>
              <div class="psd-kpi-cell"><span class="psd-kpi-n psd-kpi-n--red">{{ alertsData.totals.open ?? 0 }}</span><span class="psd-kpi-l">Open</span></div>
              <div class="psd-kpi-cell"><span class="psd-kpi-n psd-kpi-n--amber">{{ alertsData.totals.acknowledged ?? 0 }}</span><span class="psd-kpi-l">Acknowledged</span></div>
              <div class="psd-kpi-cell"><span class="psd-kpi-n psd-kpi-n--green">{{ alertsData.totals.closed ?? 0 }}</span><span class="psd-kpi-l">Closed</span></div>
              <div class="psd-kpi-cell"><span class="psd-kpi-n psd-kpi-n--red">{{ alertsData.totals.critical ?? 0 }}</span><span class="psd-kpi-l">Critical</span></div>
              <div class="psd-kpi-cell"><span class="psd-kpi-n">{{ alertsData.totals.avg_ack_minutes ?? '--' }}</span><span class="psd-kpi-l">Avg ack min</span></div>
            </div>
          </div>
        </div>

        <!-- § Epidemiology — analytics gate only -->
        <template v-if="canSeeAnalytics">
          <div v-if="epi?.by_gender?.length || epi?.temperature || epi?.syndromes?.length || hmap?.buckets?.length" class="psd-acc">
            <div class="psd-acc-hd" @click="toggleSection('epi')">
              <span class="psd-acc-ico">🔬</span>
              <div class="psd-acc-meta">
                <span class="psd-acc-title">Epidemiology</span>
                <span class="psd-acc-sub">Gender · Temperature · Syndromes</span>
              </div>
              <svg :class="['psd-chev', open.epi && 'psd-chev--open']" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div :class="['psd-acc-bd', open.epi && 'psd-acc-bd--open']">
              <!-- Gender bars -->
              <div v-if="epi.by_gender?.length" class="psd-bars-section">
                <div class="psd-section-lbl">Gender</div>
                <div v-for="g in epi.by_gender" :key="g.gender" class="psd-bar-row">
                  <span class="psd-bar-lbl">{{ GS[g.gender] }}</span>
                  <div class="psd-bar-track"><div :class="['psd-bar-fill', `psd-bar-fill--${g.gender.toLowerCase()}`]" :style="{ width: genderPct(g) + '%' }"/></div>
                  <span class="psd-bar-val">{{ g.total }}</span>
                </div>
              </div>
              <!-- Temperature stack -->
              <div v-if="epi.temperature?.bands" class="psd-bars-section" @click="openSheet('temperature')">
                <div class="psd-section-lbl">Temperature <span class="psd-lbl-avg">avg {{ epi.temperature.avg_c?.toFixed(1) ?? '--' }}°C</span></div>
                <div class="psd-temp-stack">
                  <div v-for="b in tBands" :key="b.k" class="psd-temp-seg" :style="{ flex: b.n || 0.01, background: b.c }"/>
                </div>
                <div class="psd-temp-legend">
                  <div v-for="b in tBands" :key="b.k" class="psd-temp-leg-item">
                    <span class="psd-temp-dot" :style="{ background: b.c }"/>
                    <span>{{ b.l }} {{ b.n }} ({{ b.p }}%)</span>
                  </div>
                </div>
              </div>
              <!-- Syndromes bars -->
              <div v-if="epi.syndromes?.length" class="psd-bars-section">
                <div class="psd-section-lbl">Syndromes</div>
                <div v-for="s in epi.syndromes" :key="s.syndrome" class="psd-bar-row">
                  <span class="psd-bar-lbl psd-bar-lbl--wide">{{ s.syndrome.replace(/_/g, ' ') }}</span>
                  <div class="psd-bar-track"><div class="psd-bar-fill psd-bar-fill--syn" :style="{ width: synPct(s) + '%' }"/></div>
                  <span class="psd-bar-val">{{ s.count }}</span>
                </div>
              </div>
              <!-- Peak hours histogram -->
              <div v-if="hmap?.buckets?.length" class="psd-bars-section">
                <div class="psd-section-lbl">Peak Hours</div>
                <div class="psd-hours">
                  <div v-for="b in hmap.buckets" :key="b.bucket" class="psd-hour-col" :class="b.bucket % 2 !== 0 && 'psd-hour-col--alt'">
                    <div class="psd-hour-bar-wrap">
                      <div class="psd-hour-bar" :style="{ height: hourPct(b) + '%' }" :class="b.bucket === peakH && 'psd-hour-bar--peak'"/>
                    </div>
                    <span class="psd-hour-lbl">{{ b.label }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- § Weekly Report — analytics gate -->
          <div v-if="weekly?.report" class="psd-acc">
            <div class="psd-acc-hd" @click="toggleSection('weekly')">
              <span class="psd-acc-ico">📅</span>
              <div class="psd-acc-meta">
                <span class="psd-acc-title">Weekly Report</span>
                <span class="psd-acc-sub">{{ weekly.week_label }}</span>
              </div>
              <span :class="['psd-badge', (weekly.report.vs_previous_week ?? 0) >= 0 ? 'psd-badge--green' : 'psd-badge--red']">
                {{ (weekly.report.vs_previous_week ?? 0) >= 0 ? '+' : '' }}{{ weekly.report.vs_previous_week ?? 0 }} vs last
              </span>
              <svg :class="['psd-chev', open.weekly && 'psd-chev--open']" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div :class="['psd-acc-bd', open.weekly && 'psd-acc-bd--open']">
              <div class="psd-kpi-grid">
                <div class="psd-kpi-cell"><span class="psd-kpi-n">{{ weekly.report.total_screened ?? 0 }}</span><span class="psd-kpi-l">Screened</span></div>
                <div class="psd-kpi-cell"><span class="psd-kpi-n psd-kpi-n--red">{{ weekly.report.symptomatic_rate ?? 0 }}%</span><span class="psd-kpi-l">Symp. rate</span></div>
                <div class="psd-kpi-cell"><span class="psd-kpi-n">{{ weekly.report.total_referrals ?? 0 }}</span><span class="psd-kpi-l">Referrals</span></div>
                <div class="psd-kpi-cell"><span class="psd-kpi-n psd-kpi-n--amber">{{ weekly.report.fever_count ?? 0 }}</span><span class="psd-kpi-l">Fever</span></div>
                <div class="psd-kpi-cell"><span class="psd-kpi-n">{{ weekly.report.avg_daily ?? 0 }}</span><span class="psd-kpi-l">Daily avg</span></div>
                <div class="psd-kpi-cell"><span class="psd-kpi-n" :class="(weekly.report.vs_previous_week ?? 0) >= 0 ? 'psd-kpi-n--green' : 'psd-kpi-n--red'">{{ (weekly.report.vs_previous_week ?? 0) >= 0 ? '+' : '' }}{{ weekly.report.vs_previous_week ?? 0 }}</span><span class="psd-kpi-l">vs last week</span></div>
              </div>
            </div>
          </div>
        </template>

        <!-- § Officers — management gate only -->
        <template v-if="canSeeOfficers">
          <div v-if="officers?.screeners?.length" class="psd-acc">
            <div class="psd-acc-hd" @click="toggleSection('officers')">
              <span class="psd-acc-ico">👤</span>
              <div class="psd-acc-meta">
                <span class="psd-acc-title">Officers</span>
                <span class="psd-acc-sub">{{ officers.screener_count }} active</span>
              </div>
              <svg :class="['psd-chev', open.officers && 'psd-chev--open']" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div :class="['psd-acc-bd', open.officers && 'psd-acc-bd--open']">
              <div v-for="o in officers.screeners.slice(0, 8)" :key="o.user_id" class="psd-officer-row">
                <span class="psd-off-name">{{ o.full_name || o.username }}</span>
                <div class="psd-off-track"><div class="psd-off-bar" :style="{ width: officerPct(o) + '%' }"/></div>
                <span class="psd-off-val">{{ o.total }}</span>
              </div>
            </div>
          </div>

          <!-- § Devices — management gate -->
          <div v-if="devices?.devices?.length" class="psd-acc">
            <div class="psd-acc-hd" @click="toggleSection('devices')">
              <span class="psd-acc-ico">📱</span>
              <div class="psd-acc-meta">
                <span class="psd-acc-title">Devices</span>
                <span class="psd-acc-sub">{{ devices.device_count }} devices</span>
              </div>
              <span v-if="devices.devices_at_risk" class="psd-badge psd-badge--amber">{{ devices.devices_at_risk }} at risk</span>
              <svg :class="['psd-chev', open.devices && 'psd-chev--open']" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div :class="['psd-acc-bd', open.devices && 'psd-acc-bd--open']">
              <div v-for="d in devices.devices.slice(0, 6)" :key="d.device_id" class="psd-device-row">
                <div class="psd-dev-info">
                  <span class="psd-dev-id">{{ d.device_id }}</span>
                  <span class="psd-dev-meta">{{ d.platform }} · {{ d.total_records }} records</span>
                </div>
                <span :class="['psd-dev-status', `psd-dev-status--${d.status.toLowerCase()}`]">{{ d.status }}</span>
              </div>
            </div>
          </div>
        </template>

        <div class="psd-spacer"/>
      </div>

      <!-- Empty state -->
      <div v-else-if="!loading" class="psd-empty">
        <div class="psd-empty-ico">📊</div>
        <div class="psd-empty-h">No data yet</div>
        <div class="psd-empty-p">Start screening travelers to see intelligence</div>
        <button class="psd-btn-apply" style="margin-top:16px" @click="loadAll">Refresh</button>
      </div>
    </IonContent>

    <!-- ═══════════════════════════════════════════════════════════════
         DETAIL SHEET MODAL
    ═══════════════════════════════════════════════════════════════════ -->
    <IonModal :is-open="!!sheet" @didDismiss="sheet = null"
      :initial-breakpoint="0.85" :breakpoints="[0, 0.5, 0.85, 1]" class="psd-modal">
      <IonHeader><IonToolbar class="psd-modal-tb">
        <div slot="start" class="psd-modal-handle"/>
        <IonTitle class="psd-modal-title">{{ sheetTitle }}</IonTitle>
        <IonButtons slot="end"><IonButton fill="clear" @click="sheet = null">Close</IonButton></IonButtons>
      </IonToolbar></IonHeader>
      <IonContent class="psd-modal-content" v-if="sheet">
        <div class="psd-modal-body">
          <div class="psd-detail-list">
            <div class="psd-detail-row" v-for="r in activeSheetRows" :key="r[0]">
              <span class="psd-detail-key">{{ r[0] }}</span>
              <span :class="['psd-detail-val', r[2] || '']">{{ r[1] }}</span>
            </div>
          </div>
        </div>
      </IonContent>
    </IonModal>

    <!-- Signal detail modal -->
    <IonModal :is-open="!!activeSig" @didDismiss="activeSig = null"
      :initial-breakpoint="0.85" :breakpoints="[0, 0.5, 0.85]" class="psd-modal">
      <IonHeader><IonToolbar class="psd-modal-tb">
        <div slot="start" class="psd-modal-handle"/>
        <IonButtons slot="end"><IonButton fill="clear" @click="activeSig = null">Close</IonButton></IonButtons>
      </IonToolbar></IonHeader>
      <IonContent class="psd-modal-content" v-if="activeSig">
        <div class="psd-modal-body">
          <div :class="['psd-sig-hdr-block', `psd-sig-hdr-block--${activeSig.level}`]">
            <span class="psd-sig-hdr-cat">{{ activeSig.category }}</span>
            <span class="psd-sig-hdr-title">{{ activeSig.title }}</span>
          </div>
          <p class="psd-sig-full-desc">{{ activeSig.desc }}</p>
          <div v-if="activeSig.action" class="psd-sig-action-box">
            <span class="psd-sig-action-lbl">RECOMMENDED ACTION</span>
            <p class="psd-sig-action-txt">{{ activeSig.action }}</p>
          </div>
        </div>
      </IonContent>
    </IonModal>

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color"
      :duration="4000" position="top" @didDismiss="toast.show = false"/>
  </IonPage>
</template>

<script setup>
import {
  IonPage, IonHeader, IonToolbar, IonButtons, IonMenuButton, IonButton, IonTitle,
  IonContent, IonIcon, IonSpinner, IonRefresher, IonRefresherContent, IonToast, IonModal,
} from '@ionic/vue'
import { refreshOutline } from 'ionicons/icons'
import { ref, reactive, computed } from 'vue'

import { useIntelligenceData, QUICK_PERIODS, MONTHS, YEARS, GS, TW } from '@/composables/useIntelligenceData'
import { useIntelligenceAI } from '@/composables/useIntelligenceAI'
import { interpretSymptomaticRate } from '@/services/plainLabels'
import { can, scopeOf, ROLE_TIER, ROLE } from '@/services/rbac'

// ── Composables ──────────────────────────────────────────────────────────────
const {
  auth, isOnline, activeFilter, showAdvFilter, filterDate, filterMonth, filterYear,
  filterFrom, filterTo, advFilterCount, filterLabel, setFilter, clearFilters,
  loading, sum, periodSum, trend, hmap, fun, epi, devices, officers, weekly, alertsData, liveData, lastSyncAt,
  sr, srColor, srDash, delta, peakH, tBands, trendLabels,
  trendLine, trendAreaPath, genderPct, synPct, hourPct, officerPct, funnelPct,
  loadAll, pullRefresh,
} = useIntelligenceData()

const { signals, generatePDFReport } = useIntelligenceAI({
  sum, sr, delta, fun, weekly, epi, alertsData, devices, officers,
})

// ── RBAC ─────────────────────────────────────────────────────────────────────
const roleLabel = computed(() => {
  const r = auth.value?.role_key || ''
  const labels = {
    NATIONAL_ADMIN: 'National Admin', PHEOC_OFFICER: 'PHEOC', DISTRICT_SUPERVISOR: 'District',
    POE_ADMIN: 'POE Admin', POE_PRIMARY: 'Primary', POE_SECONDARY: 'Secondary',
    POE_DATA_OFFICER: 'Data Officer', SCREENER: 'Screener',
  }
  return labels[r] || r
})

// 2026-05-07 — Scope banner. The dashboard is open to every role but the
// data is server-scoped: SCREENER / POE_* see only their POE; DISTRICT
// supervisor sees their district + child POEs; PHEOC sees province
// rollup; NATIONAL sees the whole country. Surface this VISIBLY so the
// user always knows the scope they're looking at.
const scopeBanner = computed(() => {
  const r  = auth.value?.role_key || ''
  const a  = auth.value || {}
  const sc = (typeof scopeOf === 'function') ? scopeOf(a) : null

  // Tier label + bg colour key for the banner's left strip.
  const tierMap = {
    NATIONAL_ADMIN:      { tier: 'NATIONAL',  scope: 'All POEs across the country',                 dot: '#7C3AED' },
    PHEOC_OFFICER:       { tier: 'PHEOC',     scope: a.pheoc_code || a.province_code || sc?.code || 'Region coordination', dot: '#0EA5E9' },
    DISTRICT_SUPERVISOR: { tier: 'DISTRICT',  scope: a.district_code || sc?.code || 'District',     dot: '#10B981' },
    POE_ADMIN:           { tier: 'POE',       scope: a.poe_code || sc?.code || 'Point of Entry',    dot: '#F59E0B' },
    POE_PRIMARY:         { tier: 'POE',       scope: a.poe_code || sc?.code || 'Point of Entry',    dot: '#F59E0B' },
    POE_SECONDARY:       { tier: 'POE',       scope: a.poe_code || sc?.code || 'Point of Entry',    dot: '#F59E0B' },
    POE_DATA_OFFICER:    { tier: 'POE',       scope: a.poe_code || sc?.code || 'Point of Entry',    dot: '#F59E0B' },
    SCREENER:            { tier: 'POE',       scope: a.poe_code || sc?.code || 'Point of Entry',    dot: '#F59E0B' },
  }
  const m = tierMap[r] || { tier: 'POE', scope: 'Limited scope', dot: '#94A3B8' }
  return {
    tier:        m.tier,
    scope:       m.scope,
    dot:         m.dot,
    role:        roleLabel.value,
    description: `All metrics, charts, and records below are filtered to your ${m.tier} scope on the server. You cannot see data outside this scope.`,
  }
})
// Analytics sections: POE_DATA_OFFICER, POE_ADMIN, supervisors, national
const canSeeAnalytics = computed(() => can('aggregated.reports', auth.value))
// Officers/Devices: POE_ADMIN and above
const canSeeOfficers = computed(() => can('admin.users', auth.value))

// ── Accordion state ───────────────────────────────────────────────────────────
const open = reactive({
  analysis: true,   // Screening Analysis — always open by default
  trend: false,
  pipeline: true,   // Referral Pipeline — always open by default
  secondary: false,
  alerts: false,
  epi: false,
  weekly: false,
  officers: false,
  devices: false,
})
function toggleSection(id) {
  if (id in open) open[id] = !open[id]
}

// ── Symptomatic rate interpretation ──────────────────────────────────────────
const srInterpretation = computed(() => interpretSymptomaticRate(sr.value))

// ── Detail sheets ─────────────────────────────────────────────────────────────
const sheet = ref(null)
const activeSig = ref(null)

const SHEET_TITLES = { summary: 'Full Summary', referrals: 'Referral Queue', temperature: 'Temperature Analysis', secondary: 'Secondary Cases' }
const sheetTitle = computed(() => SHEET_TITLES[sheet.value] || '')

function openSheet(s) { sheet.value = s }

const activeSheetRows = computed(() => {
  if (sheet.value === 'summary') return summaryRows.value
  if (sheet.value === 'referrals') return referralRows.value
  if (sheet.value === 'temperature') return tempRows.value
  if (sheet.value === 'secondary') return secRows.value
  return []
})

// ── Toast + PDF ───────────────────────────────────────────────────────────────
const toast = reactive({ show: false, msg: '', color: 'success' })
const pdfBusy = ref(false)

async function onPDF() {
  pdfBusy.value = true
  try {
    const name = await generatePDFReport()
    toast.msg = 'Report saved: ' + name
    toast.color = 'success'
    toast.show = true
  } catch (e) {
    toast.msg = 'PDF error: ' + (e?.message || 'Unknown error')
    toast.color = 'danger'
    toast.show = true
  } finally {
    pdfBusy.value = false
  }
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function fmtRel(dt) {
  if (!dt) return ''
  try {
    const m = Math.floor((Date.now() - new Date(dt).getTime()) / 60000)
    if (m < 1) return 'just now'
    if (m < 60) return m + 'm ago'
    const h = Math.floor(m / 60)
    if (h < 24) return h + 'h ago'
    return Math.floor(h / 24) + 'd ago'
  } catch { return '' }
}

// ── Detail row builders ───────────────────────────────────────────────────────
const summaryRows = computed(() => {
  const s = sum.value; if (!s) return []
  const a = s.all_time || {}; const t = s.today || {}
  return [
    ['Total Screened (All Time)', a.total ?? 0],
    ['Completed', a.completed ?? 0],
    ['Voided', a.voided ?? 0, 'psd-dv--dim'],
    ['Symptomatic', a.symptomatic ?? 0, 'psd-dv--red'],
    ['Asymptomatic', a.asymptomatic ?? 0, 'psd-dv--green'],
    ['Symptomatic Rate', `${a.symptomatic_rate ?? 0}%`, (a.symptomatic_rate ?? 0) >= 20 ? 'psd-dv--red' : ''],
    ['Referrals Created', a.referrals ?? 0],
    ['Male', a.male ?? 0], ['Female', a.female ?? 0],
    ['Today Total', t.total ?? 0],
    ['Today Symptomatic', t.symptomatic ?? 0, 'psd-dv--red'],
    ['Today Rate', `${t.symptomatic_rate ?? 0}%`],
    ['Today Referrals', t.referrals ?? 0],
    ['Fever Today (≥37.5°C)', t.fever_count ?? 0, (t.fever_count ?? 0) > 0 ? 'psd-dv--amber' : ''],
    ['High Fever (≥38.5°C)', t.high_fever_count ?? 0, (t.high_fever_count ?? 0) > 0 ? 'psd-dv--red' : ''],
    ['Avg Temp Today', t.avg_temp_c ? `${Number(t.avg_temp_c).toFixed(1)}°C` : '--'],
    ['vs Yesterday', `${(t.vs_yesterday ?? 0) >= 0 ? '+' : ''}${t.vs_yesterday ?? 0}`, (t.vs_yesterday ?? 0) >= 0 ? 'psd-dv--green' : 'psd-dv--red'],
    ['This Week', s.this_week?.total ?? 0],
    ['This Month', s.this_month?.total ?? 0],
    ['Not Uploaded', a.unsynced ?? 0, (a.unsynced ?? 0) > 0 ? 'psd-dv--amber' : ''],
    ['Sync Failed', a.sync_failed ?? 0, (a.sync_failed ?? 0) > 0 ? 'psd-dv--red' : ''],
  ]
})
const referralRows = computed(() => {
  const rq = sum.value?.referral_queue || {}
  return [
    ['Open', rq.open ?? 0, (rq.open ?? 0) > 0 ? 'psd-dv--amber' : 'psd-dv--green'],
    ['In Progress', rq.in_progress ?? 0],
    ['Closed', rq.closed_total ?? 0, 'psd-dv--green'],
    ['Critical Open', rq.critical_open ?? 0, (rq.critical_open ?? 0) > 0 ? 'psd-dv--red' : ''],
    ['High Open', rq.high_open ?? 0, (rq.high_open ?? 0) > 0 ? 'psd-dv--amber' : ''],
    ['Oldest Open', rq.oldest_open_minutes ? `${rq.oldest_open_minutes} min` : '--', (rq.oldest_open_minutes ?? 0) > 30 ? 'psd-dv--red' : ''],
    ['Queue Critical?', rq.queue_critical ? 'YES — EXCEEDS SLA' : 'No', rq.queue_critical ? 'psd-dv--red' : 'psd-dv--green'],
  ]
})
const tempRows = computed(() => {
  const t = epi.value?.temperature; if (!t) return []
  return [
    ['Recordings', t.count_with_temp ?? 0],
    ['Average', t.avg_c != null ? `${Number(t.avg_c).toFixed(1)}°C` : '--'],
    ['Min', t.min_c != null ? `${t.min_c}°C` : '--'],
    ['Max', t.max_c != null ? `${t.max_c}°C` : '--', (t.max_c ?? 0) >= 38.5 ? 'psd-dv--red' : ''],
    ['High Fever (≥38.5°C)', t.bands?.high_fever ?? 0, (t.bands?.high_fever ?? 0) > 0 ? 'psd-dv--red' : ''],
    ['Low-Grade (37.5–38.5°C)', t.bands?.low_grade_fever ?? 0, (t.bands?.low_grade_fever ?? 0) > 0 ? 'psd-dv--amber' : ''],
    ['Normal (36–37.5°C)', t.bands?.normal ?? 0, 'psd-dv--green'],
    ['Hypothermia (<36°C)', t.bands?.hypothermia ?? 0],
  ]
})
const secRows = computed(() => {
  const sc = fun.value?.secondary_cases; if (!sc) return []
  return [
    ['Total', sc.total ?? 0], ['Open', sc.open ?? 0, (sc.open ?? 0) > 0 ? 'psd-dv--amber' : ''],
    ['In Progress', sc.in_progress ?? 0], ['Dispositioned', sc.dispositioned ?? 0],
    ['Closed', sc.closed ?? 0, 'psd-dv--green'],
    ['Critical Risk', sc.risk_critical ?? 0, (sc.risk_critical ?? 0) > 0 ? 'psd-dv--red' : ''],
    ['High Risk', sc.risk_high ?? 0, (sc.risk_high ?? 0) > 0 ? 'psd-dv--amber' : ''],
    ['Released', sc.released ?? 0], ['Quarantined', sc.quarantined ?? 0, (sc.quarantined ?? 0) > 0 ? 'psd-dv--amber' : ''],
    ['Isolated', sc.isolated ?? 0, (sc.isolated ?? 0) > 0 ? 'psd-dv--red' : ''],
    ['Referred to Facility', sc.referred ?? 0],
    ['Avg Duration', sc.avg_case_duration_minutes != null ? `${sc.avg_case_duration_minutes} min` : '--'],
  ]
})
</script>

<style scoped>
/* ── Reset ──────────────────────────────────────────────────────── */
* { box-sizing: border-box }

/* ── Header / Toolbar ───────────────────────────────────────────── */
.psd-tb {
  --background: linear-gradient(135deg, #001D3D, #003566, #003F88);
  --color: #fff; --padding-start: 6px; --padding-end: 6px; --min-height: 50px;
}
.psd-title-block { display: flex; flex-direction: column; margin-left: 2px }
.psd-poe-lbl { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: rgba(255,255,255,.35) }
.psd-page-h1 { font-size: 17px; font-weight: 800; color: #fff; line-height: 1.1 }

.psd-role-pill {
  padding: 2px 7px; border-radius: 99px; font-size: 9px; font-weight: 800;
  background: rgba(255,255,255,.12); color: rgba(255,255,255,.7);
  text-transform: uppercase; letter-spacing: .4px; margin-right: 4px;
}
.psd-live {
  display: flex; align-items: center; gap: 3px; padding: 3px 7px;
  border-radius: 99px; font-size: 10px; font-weight: 700;
  border: 1px solid rgba(255,255,255,.08); color: rgba(255,255,255,.35); margin-right: 2px;
}
.psd-live--on { color: #90EE90; border-color: rgba(144,238,144,.2) }
.psd-live-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor }
.psd-live-n { font-size: 14px; font-weight: 900 }
.psd-live-l { font-size: 9px }
.psd-icon-btn {
  width: 36px; height: 36px; display: flex; align-items: center; justify-content: center;
  background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12);
  border-radius: 8px; color: #fff; cursor: pointer; margin-right: 2px;
}
.psd-icon-btn:disabled { opacity: .45 }
.psd-icon-btn-ion { --color: rgba(255,255,255,.65); font-size: 18px }
.psd-spin-sm { width: 16px; height: 16px; color: #fff }

/* ── Filter bar ─────────────────────────────────────────────────── */
/* ──────────────────────────────────────────────────────────────────
   RBAC scope banner — sits between toolbar and period-filter bar.
   Always visible. Tier-coloured left stripe + role pill on the right.
   Background reads as a darker shade of the navy header so the banner
   feels welded to the toolbar, not bolted on. ────────────────────── */
.psd-scope-banner {
  display: flex; align-items: stretch; gap: 10px;
  background: linear-gradient(135deg, rgba(11,37,69,0.85) 0%, rgba(15,52,96,0.85) 100%);
  border-bottom: 1px solid rgba(255,255,255,0.10);
  color: #F5F7FA;
  padding: 10px 14px 10px 0;
}
.psd-scope-stripe {
  width: 4px; flex-shrink: 0;
  border-radius: 0 3px 3px 0;
  box-shadow: 0 0 14px currentColor;
}
.psd-scope-body { flex: 1 1 auto; min-width: 0; }
.psd-scope-row1 {
  display: flex; align-items: center; gap: 8px;
  font-size: 12.5px; font-weight: 800; letter-spacing: 0.3px;
  flex-wrap: wrap;
}
.psd-scope-tier {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 6px;
  background: rgba(255,255,255,0.12);
  border: 1px solid rgba(255,255,255,0.18);
  font-size: 10px; font-weight: 900; letter-spacing: 1.2px;
  text-transform: uppercase;
}
.psd-scope-sep   { color: rgba(245,247,250,0.45); }
.psd-scope-name  { color: #99F6E4; font-weight: 700; }
.psd-scope-role  {
  margin-left: auto;
  padding: 2px 9px;
  border-radius: 9999px;
  background: rgba(0,180,166,0.18);
  border: 1px solid rgba(0,180,166,0.35);
  color: #99F6E4;
  font-size: 10.5px; font-weight: 700; letter-spacing: 0.3px;
}
.psd-scope-row2 {
  margin-top: 3px;
  font-size: 11px;
  color: rgba(245,247,250,0.72);
  line-height: 1.4;
}

/* Tier-specific accents on the .psd-scope-tier pill */
.psd-scope-banner[data-tier="NATIONAL"] .psd-scope-tier { background: rgba(124,58,237,0.22); border-color: rgba(124,58,237,0.45); color: #C4B5FD; }
.psd-scope-banner[data-tier="PHEOC"]    .psd-scope-tier { background: rgba(14,165,233,0.22); border-color: rgba(14,165,233,0.45); color: #7DD3FC; }
.psd-scope-banner[data-tier="DISTRICT"] .psd-scope-tier { background: rgba(16,185,129,0.22); border-color: rgba(16,185,129,0.45); color: #6EE7B7; }
.psd-scope-banner[data-tier="POE"]      .psd-scope-tier { background: rgba(245,158,11,0.22); border-color: rgba(245,158,11,0.45); color: #FCD34D; }

.psd-fbar { display: flex; align-items: center; background: #002F6C; padding: 5px 8px; gap: 4px }
.psd-fscroll { flex: 1; display: flex; gap: 3px; overflow-x: auto; scrollbar-width: none; -webkit-overflow-scrolling: touch }
.psd-fscroll::-webkit-scrollbar { display: none }
.psd-fpill {
  padding: 5px 11px; border-radius: 99px; border: none;
  background: rgba(255,255,255,.07); color: rgba(255,255,255,.5);
  font-size: 11px; font-weight: 700; cursor: pointer; white-space: nowrap; flex-shrink: 0;
}
.psd-fpill--on { background: rgba(255,255,255,.22); color: #fff }
.psd-fadv-btn {
  padding: 5px 9px; border-radius: 6px; border: 1px solid rgba(255,255,255,.12);
  background: transparent; color: rgba(255,255,255,.5); font-size: 14px; font-weight: 700;
  cursor: pointer; flex-shrink: 0;
}
.psd-fadv-btn--on { color: #fff; background: rgba(255,255,255,.1) }

/* Advanced filter panel */
.psd-adv-panel { background: #001D3D; padding: 8px 10px 6px }
.psd-adv-row { display: flex; align-items: center; gap: 4px; margin-bottom: 6px; flex-wrap: wrap }
.psd-adv-lbl { font-size: 10px; font-weight: 700; text-transform: uppercase; color: rgba(255,255,255,.35); min-width: 42px; flex-shrink: 0 }
.psd-adv-pills { display: flex; gap: 2px; overflow-x: auto; scrollbar-width: none; flex: 1 }
.psd-adv-pills::-webkit-scrollbar { display: none }
.psd-mpill {
  padding: 4px 9px; border-radius: 99px; border: 1px solid rgba(255,255,255,.1);
  background: transparent; color: rgba(255,255,255,.4); font-size: 10px; font-weight: 700;
  cursor: pointer; white-space: nowrap; flex-shrink: 0;
}
.psd-mpill--on { background: rgba(255,255,255,.18); color: #fff; border-color: rgba(255,255,255,.25) }
.psd-adv-input {
  padding: 4px 7px; border: 1px solid rgba(255,255,255,.12); border-radius: 5px;
  background: rgba(255,255,255,.05); color: #fff; font-size: 11px; outline: none; flex: 1;
}
.psd-adv-input--h { max-width: 46%; flex: none }
.psd-adv-actions { display: flex; gap: 6px; padding-top: 2px }
.psd-btn-apply {
  flex: 1; padding: 7px; border-radius: 6px; border: none;
  background: #1565C0; color: #fff; font-size: 12px; font-weight: 700; cursor: pointer;
}
.psd-btn-clear {
  padding: 7px 12px; border-radius: 6px; border: 1px solid rgba(255,255,255,.15);
  background: transparent; color: rgba(255,255,255,.5); font-size: 12px; font-weight: 700; cursor: pointer;
}
.psd-slide-enter-active, .psd-slide-leave-active { transition: max-height .22s, opacity .18s; overflow: hidden }
.psd-slide-enter-from, .psd-slide-leave-to { max-height: 0; opacity: 0 }
.psd-slide-enter-to, .psd-slide-leave-from { max-height: 280px; opacity: 1 }

/* ── Content ────────────────────────────────────────────────────── */
.psd-content { --background: #F0F4FA }
.psd-offline-bar {
  display: flex; align-items: center; gap: 6px; padding: 7px 14px;
  background: #FFF3E0; border-bottom: 1px solid #FFCC80;
  font-size: 11px; font-weight: 600; color: #BF360C;
}
.psd-offline-dot { width: 7px; height: 7px; border-radius: 50%; background: #E65100; flex-shrink: 0 }

/* Skeletons */
.psd-skeletons { padding: 10px 10px 0 }
.psd-skel { border-radius: 12px; background: linear-gradient(90deg, #E8EDF5 25%, #F5F7FA 50%, #E8EDF5 75%); background-size: 200% 100%; animation: psd-shimmer 1.4s infinite }
.psd-skel--hero { height: 110px; margin-bottom: 8px }
.psd-skel-chips { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 8px }
.psd-skel--chip { height: 68px }
.psd-skel--section { height: 52px; margin-bottom: 6px }
@keyframes psd-shimmer { 0% { background-position: 200% 0 } 100% { background-position: -200% 0 } }

/* Body */
.psd-body { padding: 10px 10px 0 }

/* ── Hero ───────────────────────────────────────────────────────── */
.psd-hero {
  display: flex; align-items: center; gap: 12px; padding: 14px 14px 10px;
  background: #fff; border-radius: 14px; border: 1px solid #E8EDF5;
  box-shadow: 0 1px 3px rgba(0,0,0,.04); cursor: pointer; margin-bottom: 4px;
}
.psd-ring-wrap { position: relative; width: 82px; height: 82px; flex-shrink: 0 }
.psd-ring-wrap svg { width: 100%; height: 100% }
.psd-ring-anim { transition: stroke-dasharray .6s ease }
.psd-ring-ctr { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center }
.psd-ring-pct { font-size: 20px; font-weight: 900; color: #1A3A5C; line-height: 1 }
.psd-ring-pct small { font-size: 11px; color: #90A4AE }
.psd-ring-lbl { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #90A4AE; margin-top: 1px }
.psd-hero-stats { flex: 1; min-width: 0 }
.psd-alltime-n { font-size: 28px; font-weight: 900; color: #1A3A5C; line-height: 1 }
.psd-alltime-l { font-size: 11px; font-weight: 600; color: #78909C; margin-top: 2px; margin-bottom: 8px }
.psd-tap-hint { font-size: 10px; color: #90A4AE; font-weight: 500 }
.psd-mini-row { display: flex; gap: 10px; flex-wrap: wrap }
.psd-mini { display: flex; flex-direction: column }
.psd-mini-n { font-size: 15px; font-weight: 900; color: #1A3A5C; line-height: 1 }
.psd-mini-l { font-size: 9px; font-weight: 600; color: #90A4AE; margin-top: 1px }
.psd-mini--up .psd-mini-n { color: #2E7D32 }
.psd-mini--dn .psd-mini-n { color: #C62828 }
.psd-interp { margin: 6px 2px 2px; font-size: 12px; line-height: 1.45; color: #546E7A }
.psd-legend { margin: 0 2px 12px; font-size: 10px; color: #90A4AE; font-weight: 600 }

/* ── Urgent KPI 2×2 grid ─────────────────────────────────────────── */
.psd-urgent-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; margin-bottom: 14px }
.psd-u-card {
  padding: 12px 14px; background: #fff; border-radius: 12px;
  border: 1.5px solid #E8EDF5; display: flex; flex-direction: column; gap: 4px; cursor: pointer;
  box-shadow: 0 1px 2px rgba(0,0,0,.03);
}
.psd-u-card--amber { border-color: rgba(255,152,0,.25); background: #FFFDE7 }
.psd-u-card--red { border-color: rgba(229,57,53,.2); background: #FFF5F5 }
.psd-u-n { font-size: 24px; font-weight: 900; color: #1A3A5C; line-height: 1 }
.psd-u-card--amber .psd-u-n { color: #E65100 }
.psd-u-card--red .psd-u-n { color: #C62828 }
.psd-u-l { font-size: 10px; font-weight: 700; color: #90A4AE; text-transform: uppercase; letter-spacing: .2px }

/* ── Signals ─────────────────────────────────────────────────────── */
.psd-signals-hdr { display: flex; align-items: center; gap: 7px; padding: 2px 2px 8px }
.psd-pulse-dot {
  width: 8px; height: 8px; border-radius: 50%; background: #E53935;
  box-shadow: 0 0 0 3px rgba(229,57,53,.18); animation: psd-pulse 1.6s infinite;
}
@keyframes psd-pulse { 0%,100% { box-shadow: 0 0 0 2px rgba(229,57,53,.2) } 50% { box-shadow: 0 0 0 5px rgba(229,57,53,.08) } }
.psd-signals-title { font-size: 13px; font-weight: 800; color: #1A3A5C; flex: 1 }
.psd-signals-list { display: flex; flex-direction: column; gap: 5px; margin-bottom: 12px }
.psd-signal {
  display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px;
  border-radius: 10px; border: 1px solid; background: #fff; cursor: pointer;
}
.psd-signal--critical { border-color: rgba(229,57,53,.2); background: #FFF5F5 }
.psd-signal--high { border-color: rgba(255,152,0,.18); background: #FFFDE7 }
.psd-signal--medium { border-color: #E8EDF5 }
.psd-sig-ico {
  width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center;
  justify-content: center; font-size: 9px; font-weight: 900; color: #fff; flex-shrink: 0; margin-top: 1px;
}
.psd-sig-ico--critical { background: #E53935 }
.psd-sig-ico--high { background: #F57C00 }
.psd-sig-ico--medium { background: #BDBDBD }
.psd-sig-body { flex: 1; min-width: 0 }
.psd-sig-cat { display: block; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; color: #90A4AE }
.psd-sig-title { display: block; font-size: 12px; font-weight: 700; color: #1A3A5C; margin-top: 1px }
.psd-sig-desc { display: block; font-size: 10px; color: #546E7A; margin-top: 2px; line-height: 1.4 }
.psd-sig-arrow { color: #B0BEC5; margin-top: 4px; flex-shrink: 0 }

/* ── Badges ──────────────────────────────────────────────────────── */
.psd-badge { padding: 2px 7px; border-radius: 99px; font-size: 9px; font-weight: 800; flex-shrink: 0 }
.psd-badge--red { background: #FFEBEE; color: #C62828 }
.psd-badge--amber { background: #FFF3E0; color: #E65100 }
.psd-badge--green { background: #E8F5E9; color: #2E7D32 }

/* ── Accordion ───────────────────────────────────────────────────── */
.psd-acc {
  background: #fff; border-radius: 12px; border: 1px solid #E8EDF5;
  margin-bottom: 8px; overflow: hidden; box-shadow: 0 1px 2px rgba(0,0,0,.03);
}
.psd-acc-hd {
  display: flex; align-items: center; gap: 8px; padding: 13px 14px;
  cursor: pointer; user-select: none;
}
.psd-acc-hd:active { background: #F7F9FC }
.psd-acc-ico { font-size: 16px; flex-shrink: 0 }
.psd-acc-meta { flex: 1; min-width: 0 }
.psd-acc-title { display: block; font-size: 13px; font-weight: 800; color: #1A3A5C }
.psd-acc-sub { display: block; font-size: 10px; color: #90A4AE; font-weight: 600; margin-top: 1px }
.psd-chev { color: #B0BEC5; flex-shrink: 0; transition: transform .25s ease }
.psd-chev--open { transform: rotate(180deg) }

.psd-acc-bd {
  max-height: 0; overflow: hidden; opacity: 0;
  transition: max-height .35s ease, opacity .25s ease;
  border-top: 0 solid transparent;
}
.psd-acc-bd--open {
  max-height: 900px; opacity: 1;
  border-top: 1px solid #F0F4FA;
}

/* ── Trend chart ─────────────────────────────────────────────────── */
.psd-trend-wrap { padding: 10px 12px 12px }
.psd-tsvg { width: 100%; height: auto; display: block }
.psd-txlbls { display: flex; justify-content: space-between; padding: 3px 2px 0 }
.psd-txlbls span { font-size: 9px; color: #B0BEC5 }
.psd-tleg { display: flex; gap: 12px; padding: 6px 2px 0 }
.psd-tleg-i { font-size: 10px; font-weight: 700; color: #607D8B; display: flex; align-items: center; gap: 4px }
.psd-tleg-i::before { content: ''; width: 10px; height: 2px; border-radius: 1px }
.psd-tleg-i--blue::before { background: #1565C0 }
.psd-tleg-i--red::before { background: #E53935 }

/* ── Funnel ──────────────────────────────────────────────────────── */
.psd-funnel-rows { padding: 10px 12px 4px; display: flex; flex-direction: column; gap: 4px }
.psd-fn { position: relative; height: 36px; border-radius: 7px; overflow: hidden; background: #F5F7FA }
.psd-fn-bg { position: absolute; inset: 0; border-radius: 6px; overflow: hidden }
.psd-fn-fill { height: 100%; background: #E3F2FD; transition: width .5s }
.psd-fn-row { position: relative; display: flex; align-items: center; gap: 6px; padding: 0 10px; height: 100%; z-index: 1 }
.psd-fn-n { font-size: 14px; font-weight: 900; color: #1A3A5C }
.psd-fn-l { font-size: 10px; font-weight: 600; color: #546E7A; flex: 1; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; text-transform: capitalize }
.psd-fn-p { font-size: 10px; font-weight: 800; color: #90A4AE }
.psd-fn-kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1px; margin: 4px 12px 12px; background: #F0F4FA; border-radius: 8px; overflow: hidden }
.psd-fn-kpi { background: #fff; padding: 8px 4px; text-align: center }
.psd-fn-kv { display: block; font-size: 15px; font-weight: 900; color: #1A3A5C }
.psd-fn-kl { display: block; font-size: 8px; font-weight: 700; color: #90A4AE; text-transform: uppercase; margin-top: 1px }
.psd-v--amber { color: #E65100 !important }
.psd-v--red { color: #C62828 !important }

/* ── KPI Grid (secondary, alerts, weekly) ────────────────────────── */
.psd-kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px; margin: 0 12px 10px; background: #E8EDF5; border-radius: 8px; overflow: hidden }
.psd-kpi-cell { background: #fff; display: flex; flex-direction: column; align-items: center; padding: 11px 4px; gap: 3px }
.psd-kpi-n { font-size: 18px; font-weight: 900; color: #1A3A5C; line-height: 1 }
.psd-kpi-n--red { color: #C62828 !important }
.psd-kpi-n--amber { color: #E65100 !important }
.psd-kpi-n--green { color: #2E7D32 !important }
.psd-kpi-n--blue { color: #1565C0 !important }
.psd-kpi-l { font-size: 9px; font-weight: 700; color: #90A4AE; text-transform: uppercase; letter-spacing: .2px; text-align: center }
.psd-detail-btn { width: calc(100% - 24px); margin: 0 12px 12px; padding: 8px; border: 1px solid #E8EDF5; border-radius: 7px; background: #F5F7FA; color: #1565C0; font-size: 12px; font-weight: 700; cursor: pointer }

/* ── Epidemiology sections ───────────────────────────────────────── */
.psd-bars-section { padding: 10px 12px 4px }
.psd-bars-section + .psd-bars-section { border-top: 1px solid #F0F4FA }
.psd-section-lbl { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .4px; color: #90A4AE; margin-bottom: 8px; display: block }
.psd-lbl-avg { font-weight: 600; text-transform: none; letter-spacing: 0; color: #1A3A5C }
.psd-bar-row { display: flex; align-items: center; gap: 6px; margin-bottom: 5px }
.psd-bar-lbl { font-size: 11px; font-weight: 700; color: #546E7A; min-width: 14px; text-align: center }
.psd-bar-lbl--wide { min-width: 60px; font-size: 10px; text-transform: capitalize }
.psd-bar-track { flex: 1; height: 8px; background: #E8EDF5; border-radius: 4px; overflow: hidden }
.psd-bar-fill { height: 100%; border-radius: 3px; transition: width .4s }
.psd-bar-fill--male { background: #1E88E5 }
.psd-bar-fill--female { background: #E91E63 }
.psd-bar-fill--other, .psd-bar-fill--unknown { background: #607D8B }
.psd-bar-fill--syn { background: #7E57C2 }
.psd-bar-val { font-size: 11px; font-weight: 700; color: #546E7A; min-width: 22px; text-align: right }
.psd-temp-stack { display: flex; height: 10px; border-radius: 5px; overflow: hidden; gap: 1px; margin-bottom: 6px; cursor: pointer }
.psd-temp-seg { min-width: 2px; transition: flex .4s }
.psd-temp-legend { display: flex; flex-wrap: wrap; gap: 4px 10px }
.psd-temp-leg-item { display: flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 600; color: #546E7A }
.psd-temp-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0 }
.psd-hours { display: flex; gap: 1px; align-items: flex-end; height: 60px }
.psd-hour-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 2px; min-width: 0 }
.psd-hour-col--alt .psd-hour-lbl { visibility: hidden }
.psd-hour-bar-wrap { width: 100%; height: 44px; display: flex; align-items: flex-end }
.psd-hour-bar { width: 100%; background: #C5CAE9; border-radius: 2px 2px 0 0; transition: height .3s; min-height: 2px }
.psd-hour-bar--peak { background: #1565C0 }
.psd-hour-lbl { font-size: 8px; color: #B0BEC5 }

/* ── Officers ────────────────────────────────────────────────────── */
.psd-officer-row { display: flex; align-items: center; gap: 6px; padding: 6px 14px; border-top: 1px solid #F0F4FA }
.psd-officer-row:first-child { border-top: none }
.psd-off-name { font-size: 11px; font-weight: 600; color: #546E7A; min-width: 70px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
.psd-off-track { flex: 1; height: 8px; background: #E8EDF5; border-radius: 4px; overflow: hidden }
.psd-off-bar { height: 100%; background: #1E88E5; border-radius: 4px; transition: width .4s }
.psd-off-val { font-size: 11px; font-weight: 800; color: #546E7A; min-width: 24px; text-align: right }

/* ── Devices ─────────────────────────────────────────────────────── */
.psd-device-row { display: flex; align-items: center; gap: 8px; padding: 7px 14px; border-top: 1px solid #F0F4FA }
.psd-device-row:first-child { border-top: none }
.psd-dev-info { flex: 1; min-width: 0 }
.psd-dev-id { display: block; font-size: 11px; font-weight: 700; color: #1A3A5C; white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
.psd-dev-meta { display: block; font-size: 10px; color: #90A4AE }
.psd-dev-status { font-size: 9px; font-weight: 800; padding: 2px 7px; border-radius: 4px; text-transform: uppercase; flex-shrink: 0 }
.psd-dev-status--healthy { background: #E8F5E9; color: #2E7D32 }
.psd-dev-status--warning { background: #FFF3E0; color: #E65100 }
.psd-dev-status--critical { background: #FFEBEE; color: #C62828 }
.psd-dev-status--silent, .psd-dev-status--pending, .psd-dev-status--unknown { background: #F5F5F5; color: #607D8B }

/* ── Empty state ─────────────────────────────────────────────────── */
.psd-empty { display: flex; flex-direction: column; align-items: center; padding: 60px 20px; text-align: center }
.psd-empty-ico { font-size: 48px; margin-bottom: 12px }
.psd-empty-h { font-size: 18px; font-weight: 800; color: #1A3A5C; margin-bottom: 6px }
.psd-empty-p { font-size: 13px; color: #78909C }

/* ── Spacer ──────────────────────────────────────────────────────── */
.psd-spacer { height: 40px }

/* ── Modal ───────────────────────────────────────────────────────── */
.psd-modal::part(content) { border-radius: 16px 16px 0 0 }
.psd-modal-tb { --background: #fff; --border-width: 0 0 1px 0; --border-color: #E8EDF5; --min-height: 46px }
.psd-modal-handle { width: 36px; height: 4px; border-radius: 2px; background: #DDE3EA; margin: 0 12px }
.psd-modal-title { font-size: 15px; font-weight: 800; color: #1A3A5C; padding-left: 0 }
.psd-modal-content { --background: #fff }
.psd-modal-body { padding: 16px }
.psd-detail-list { display: flex; flex-direction: column }
.psd-detail-row { display: flex; justify-content: space-between; align-items: center; padding: 9px 0; border-bottom: 1px solid #F0F4FA }
.psd-detail-row:last-child { border-bottom: none }
.psd-detail-key { font-size: 12px; font-weight: 600; color: #546E7A }
.psd-detail-val { font-size: 13px; font-weight: 800; color: #1A3A5C }
.psd-dv--red { color: #C62828 !important }
.psd-dv--amber { color: #E65100 !important }
.psd-dv--green { color: #2E7D32 !important }
.psd-dv--dim { color: #90A4AE !important }

/* Signal detail modal */
.psd-sig-hdr-block { padding: 14px; border-radius: 10px; margin-bottom: 14px }
.psd-sig-hdr-block--critical { background: #FFEBEE }
.psd-sig-hdr-block--high { background: #FFF3E0 }
.psd-sig-hdr-block--medium { background: #F5F5F5 }
.psd-sig-hdr-cat { display: block; font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: #90A4AE; margin-bottom: 5px }
.psd-sig-hdr-title { display: block; font-size: 16px; font-weight: 800; color: #1A3A5C; line-height: 1.3 }
.psd-sig-full-desc { font-size: 13px; color: #37474F; line-height: 1.6; margin: 0 0 14px }
.psd-sig-action-box { background: #E3F2FD; border-radius: 10px; padding: 12px; margin-bottom: 14px }
.psd-sig-action-lbl { display: block; font-size: 8px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; color: #1565C0; margin-bottom: 6px }
.psd-sig-action-txt { font-size: 13px; font-weight: 600; color: #0D47A1; line-height: 1.5; margin: 0 }

/* ── Screening Analysis section ─────────────────────────────────────── */
.psd-acc--analysis { border-color: #DBEAFE; background: #F8FAFF }
.psd-acc--analysis .psd-acc-hd { background: #F0F7FF }
.psd-acc-sub--period { color: #1565C0; font-weight: 700 }

.psd-analysis-grid {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 1px; margin: 0 12px 2px; background: #DBEAFE; border-radius: 8px; overflow: hidden;
}
.psd-an-cell {
  background: #fff; display: flex; flex-direction: column; align-items: center;
  padding: 12px 4px; gap: 2px;
}
.psd-an-cell--alert { background: #FFF5F5 }
.psd-an-cell--warn { background: #FFFDE7 }
.psd-an-n { font-size: 20px; font-weight: 900; color: #1A3A5C; line-height: 1 }
.psd-an-cell--alert .psd-an-n { color: #C62828 }
.psd-an-cell--warn .psd-an-n { color: #E65100 }
.psd-an-sub { font-size: 10px; font-weight: 800; color: #546E7A; line-height: 1 }
.psd-an-l { font-size: 9px; font-weight: 700; color: #90A4AE; text-transform: uppercase; letter-spacing: .2px; text-align: center }

.psd-analysis-meta { padding: 8px 14px 12px; border-top: 1px solid #DBEAFE; display: flex; flex-direction: column; gap: 5px }
.psd-ameta-row { display: flex; align-items: center; gap: 8px }
.psd-ameta-k { font-size: 11px; font-weight: 700; color: #78909C; min-width: 90px }
.psd-ameta-v { font-size: 12px; font-weight: 600; color: #1A3A5C; display: flex; align-items: center; gap: 3px }
.psd-gender-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0 }
.psd-gender-dot--m { background: #1E88E5 }
.psd-gender-dot--f { background: #E91E63 }
.psd-analysis-empty { padding: 14px; text-align: center; font-size: 12px; color: #90A4AE }

@media (min-width: 500px) { .psd-body { max-width: 480px; margin: 0 auto } }
</style>
