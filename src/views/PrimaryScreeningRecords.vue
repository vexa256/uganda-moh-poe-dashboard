<template>
  <IonPage>
    <!-- ═══ HEADER ═══════════════════════════════════════════════════════ -->
    <IonHeader class="pr-header" translucent>
      <IonToolbar class="pr-toolbar">
        <IonButtons slot="start">
          <IonMenuButton menu="app-menu" class="pr-menu-btn" />
        </IonButtons>
        <div class="pr-title-block" slot="start">
          <span class="pr-eyebrow">IHR Art.23 · Primary Screening</span>
          <span class="pr-title">Screening Records</span>
        </div>
        <IonButtons slot="end">
          <div :class="['pr-conn-pill', isOnline ? 'pr-conn--online' : 'pr-conn--offline']">
            <span class="pr-conn-dot" /><span>{{ isOnline ? 'Online' : 'Offline' }}</span>
          </div>
          <button :class="['pr-sync-pill', syncPillClass]" @click="syncAllPending" :disabled="syncing">
            <IonIcon :icon="cloudUploadOutline" />
            <span>{{ syncing ? 'Syncing…' : syncPillLabel }}</span>
          </button>
          <IonButton fill="clear" class="pr-refresh-btn" @click="reload" :disabled="loading">
            <IonIcon :icon="refreshOutline" slot="icon-only" />
          </IonButton>
        </IonButtons>
      </IonToolbar>

      <!-- Stats bar (tap each tile to quick-filter) -->
      <div class="pr-stats-bar">
        <button class="pr-stat" @click="clearAllFilters">
          <span class="pr-stat-num">{{ totalCount }}</span>
          <span class="pr-stat-lbl">Total</span>
        </button>
        <div class="pr-stat-div" />
        <button class="pr-stat pr-stat--sym" @click="quickFilter('symptoms_present','1')">
          <span class="pr-stat-num">{{ symptomaticCount }}</span>
          <span class="pr-stat-lbl">Symptomatic</span>
        </button>
        <div class="pr-stat-div" />
        <button class="pr-stat pr-stat--ref" @click="quickFilter('referral_created','1')">
          <span class="pr-stat-num">{{ referralCount }}</span>
          <span class="pr-stat-lbl">Referred</span>
        </button>
        <div class="pr-stat-div" />
        <button class="pr-stat pr-stat--fever" @click="showFeverOnly = !showFeverOnly" :class="showFeverOnly && 'pr-stat--on'">
          <span class="pr-stat-num">{{ feverCount }}</span>
          <span class="pr-stat-lbl">Fever ≥37.5°</span>
        </button>
        <div class="pr-stat-div" />
        <button class="pr-stat pr-stat--unsynced" @click="quickFilter('sync_status','UNSYNCED')" :class="filters.sync_status==='UNSYNCED'&&'pr-stat--on'">
          <span class="pr-stat-num">{{ unsyncedCount }}</span>
          <span class="pr-stat-lbl">Unsynced</span>
        </button>
        <template v-if="quarantinedRecords.length">
          <div class="pr-stat-div" />
          <button class="pr-stat pr-stat--quarantine" @click="showQuarantinePanel = !showQuarantinePanel">
            <span class="pr-stat-num">{{ quarantinedRecords.length }}</span>
            <span class="pr-stat-lbl">Damaged</span>
          </button>
        </template>
      </div>

      <!-- Quarantine banner -->
      <transition name="pr-slide">
        <div v-if="showQuarantinePanel && quarantinedRecords.length" class="pr-quarantine-banner">
          <div class="pr-qb-header">
            <span class="pr-qb-title">{{ quarantinedRecords.length }} Damaged Record{{ quarantinedRecords.length !== 1 ? 's' : '' }}</span>
            <span class="pr-qb-sub">Failed to sync {{ QUARANTINE_MAX_ATTEMPTS }}+ times. Isolated to protect data integrity.</span>
          </div>
          <div class="pr-qb-list">
            <div v-for="qr in quarantinedRecords" :key="qr.client_uuid" class="pr-qb-item">
              <div class="pr-qb-info">
                <span class="pr-qb-name">{{ qr.traveler_full_name || GENDER_LABELS[qr.gender] || 'Unknown' }}</span>
                <span class="pr-qb-err">{{ qr.last_sync_error || 'Unknown error' }}</span>
              </div>
              <button class="pr-qb-retry" @click="retryQuarantined(qr)" :disabled="!isOnline" title="Retry">↻</button>
              <button class="pr-qb-delete" @click="deleteQuarantined(qr)" title="Delete">✕</button>
            </div>
          </div>
          <button class="pr-qb-purge" @click="purgeAllQuarantined">Purge All Damaged Records</button>
        </div>
      </transition>

      <!-- Charts toggle bar -->
      <div class="pr-charts-toggle" @click="chartsOpen = !chartsOpen">
        <span class="pr-charts-toggle-lbl">
          <IonIcon :icon="chartsOpen ? chevronUpOutline : barChartOutline" />
          {{ chartsOpen ? 'Hide Analytics' : 'Show Analytics' }}
        </span>
        <span class="pr-charts-meta" v-if="serverStats">
          {{ serverStats.windowed?.symptomatic_rate || 0 }}% symptomatic rate
          <template v-if="serverStats.today?.open_referrals"> · {{ serverStats.today.open_referrals }} open referrals</template>
        </span>
      </div>

      <!-- Analytics panel — charts + key metrics -->
      <transition name="pr-slide">
        <div v-if="chartsOpen" class="pr-analytics-panel">

          <!-- Row 1: KPI tiles -->
          <div class="pr-kpi-row">
            <div class="pr-kpi">
              <span class="pr-kpi-num">{{ serverStats?.windowed?.symptomatic_rate ?? '—' }}<span class="pr-kpi-unit">%</span></span>
              <span class="pr-kpi-lbl">Symptomatic Rate</span>
              <div class="pr-kpi-bar-wrap"><div class="pr-kpi-bar pr-kpi-bar--sym" :style="{width:(serverStats?.windowed?.symptomatic_rate||0)+'%'}" /></div>
            </div>
            <div class="pr-kpi">
              <span class="pr-kpi-num">{{ serverStats?.windowed?.referral_pickup_rate ?? '—' }}<span class="pr-kpi-unit">%</span></span>
              <span class="pr-kpi-lbl">Referral Pickup</span>
              <div class="pr-kpi-bar-wrap"><div class="pr-kpi-bar pr-kpi-bar--ref" :style="{width:(serverStats?.windowed?.referral_pickup_rate||0)+'%'}" /></div>
            </div>
            <div class="pr-kpi">
              <span class="pr-kpi-num">{{ serverStats?.today?.total ?? '—' }}</span>
              <span class="pr-kpi-lbl">Today</span>
              <span class="pr-kpi-delta" :class="(serverStats?.today?.vs_yesterday||0) >= 0 ? 'pr-kpi-delta--up' : 'pr-kpi-delta--down'">
                {{ (serverStats?.today?.vs_yesterday||0) >= 0 ? '▲' : '▼' }} {{ Math.abs(serverStats?.today?.vs_yesterday||0) }} vs yesterday
              </span>
            </div>
          </div>

          <!-- Row 2: Gender breakdown + Trend sparkline -->
          <div class="pr-charts-row">
            <!-- Gender bars -->
            <div class="pr-chart-card">
              <span class="pr-chart-title">Gender Breakdown</span>
              <div v-if="serverStats?.by_gender" class="pr-gender-chart">
                <template v-for="(g, key) in [{k:'MALE',l:'Male',c:'#1565C0'},{k:'FEMALE',l:'Female',c:'#C2185B'}]" :key="g.k">
                  <div class="pr-gbar-row">
                    <span class="pr-gbar-lbl">{{ g.l }}</span>
                    <div class="pr-gbar-track">
                      <div class="pr-gbar-fill" :style="{width: genderPct(g.k)+'%', background: g.c}" />
                    </div>
                    <span class="pr-gbar-val">{{ serverStats.by_gender[g.k] || 0 }}</span>
                  </div>
                </template>
              </div>
              <p v-else class="pr-chart-empty">No data</p>
            </div>

            <!-- 7-day sparkline -->
            <div class="pr-chart-card">
              <span class="pr-chart-title">7-Day Trend</span>
              <div v-if="trendSeries.length" class="pr-spark-wrap">
                <svg class="pr-spark" viewBox="0 0 210 60" preserveAspectRatio="none" aria-hidden="true">
                  <!-- Symptomatic area -->
                  <polyline
                    :points="sparkPoints(trendSeries, 'symptomatic')"
                    fill="none" stroke="#DC3545" stroke-width="1.5"
                    stroke-linecap="round" stroke-linejoin="round"
                  />
                  <!-- Total area -->
                  <polyline
                    :points="sparkPoints(trendSeries, 'total')"
                    fill="none" stroke="#1A3A5C" stroke-width="2"
                    stroke-linecap="round" stroke-linejoin="round"
                  />
                  <!-- Dots on total -->
                  <circle v-for="(pt, i) in sparkDots(trendSeries, 'total')" :key="i"
                    :cx="pt.x" :cy="pt.y" r="2.5" fill="#1A3A5C" />
                </svg>
                <div class="pr-spark-labels">
                  <span v-for="(d, i) in trendSeries" :key="i" class="pr-spark-lbl">
                    {{ d.date.slice(5) }}
                  </span>
                </div>
                <div class="pr-spark-legend">
                  <span class="pr-spark-leg pr-spark-leg--total">Total</span>
                  <span class="pr-spark-leg pr-spark-leg--sym">Symptomatic</span>
                </div>
              </div>
              <p v-else class="pr-chart-empty">Loading trend…</p>
            </div>
          </div>

          <!-- Row 3: Sync health bar -->
          <div v-if="serverStats?.sync_health" class="pr-chart-card pr-sync-health-card">
            <span class="pr-chart-title">Sync Health (All Time)</span>
            <div class="pr-sync-health-bar">
              <div class="pr-shb-seg pr-shb--synced"   :style="{flex: serverStats.sync_health.synced}"   :title="'Synced: '+serverStats.sync_health.synced" />
              <div class="pr-shb-seg pr-shb--unsynced" :style="{flex: serverStats.sync_health.unsynced}" :title="'Pending: '+serverStats.sync_health.unsynced" />
              <div class="pr-shb-seg pr-shb--failed"   :style="{flex: serverStats.sync_health.failed}"   :title="'Failed: '+serverStats.sync_health.failed" />
            </div>
            <div class="pr-sync-health-legend">
              <span class="pr-shl pr-shl--synced">Synced: {{ serverStats.sync_health.synced }}</span>
              <span class="pr-shl pr-shl--unsynced">Pending: {{ serverStats.sync_health.unsynced }}</span>
              <span class="pr-shl pr-shl--failed">Failed: {{ serverStats.sync_health.failed }}</span>
            </div>
          </div>

        </div>
      </transition>

      <!-- Search + filter controls -->
      <div class="pr-controls">
        <div class="pr-search-row">
          <div class="pr-search-box">
            <IonIcon :icon="searchOutline" class="pr-search-icon" />
            <input v-model="searchQuery" type="search" class="pr-search-input"
              placeholder="Name, device, screener, UUID…" aria-label="Search records" />
            <button v-if="searchQuery" class="pr-search-clear" @click="searchQuery=''" aria-label="Clear">
              <IonIcon :icon="closeCircleOutline" />
            </button>
          </div>
          <button :class="['pr-filter-btn', filtersOpen && 'pr-filter-btn--on']"
            @click="filtersOpen = !filtersOpen" aria-label="Filters">
            <IonIcon :icon="optionsOutline" />
            <span v-if="activeFilterCount" class="pr-filter-count">{{ activeFilterCount }}</span>
          </button>
        </div>

        <!-- Status tabs -->
        <div class="pr-tabs" role="tablist">
          <button v-for="t in STATUS_TABS" :key="t.v"
            :class="['pr-tab', filters.record_status === t.v && 'pr-tab--on']"
            @click="setStatusFilter(t.v)" role="tab">
            {{ t.label }}
            <span v-if="tabCount(t.v)" :class="['pr-tab-badge', t.bc]">{{ tabCount(t.v) }}</span>
          </button>
        </div>

        <!-- Expandable filter panel -->
        <transition name="pr-slide">
          <div v-if="filtersOpen" class="pr-filter-panel">
            <div class="pr-fp-row">
              <span class="pr-fp-lbl">Gender</span>
              <div class="pr-fp-pills">
                <button v-for="g in GENDERS" :key="g.v"
                  :class="['pr-pill', filters.gender===g.v && 'pr-pill--on', 'pr-pill--gender']"
                  @click="toggleFilter('gender', g.v)">{{ g.label }}</button>
              </div>
            </div>
            <div class="pr-fp-row">
              <span class="pr-fp-lbl">Symptoms</span>
              <div class="pr-fp-pills">
                <button :class="['pr-pill', filters.symptoms_present==='1' && 'pr-pill--on pr-pill--sym']"
                  @click="toggleFilter('symptoms_present','1')">Yes (Symptomatic)</button>
                <button :class="['pr-pill', filters.symptoms_present==='0' && 'pr-pill--on pr-pill--ok']"
                  @click="toggleFilter('symptoms_present','0')">No (Asymptomatic)</button>
              </div>
            </div>
            <div class="pr-fp-row">
              <span class="pr-fp-lbl">Referral</span>
              <div class="pr-fp-pills">
                <button :class="['pr-pill', filters.referral_created==='1' && 'pr-pill--on pr-pill--ref']"
                  @click="toggleFilter('referral_created','1')">Referred</button>
                <button :class="['pr-pill', filters.referral_created==='0' && 'pr-pill--on']"
                  @click="toggleFilter('referral_created','0')">Not Referred</button>
              </div>
            </div>
            <div class="pr-fp-row">
              <span class="pr-fp-lbl">Sync</span>
              <div class="pr-fp-pills">
                <button v-for="s in SYNC_TABS" :key="s.v"
                  :class="['pr-pill', filters.sync_status===s.v && 'pr-pill--on', 'pr-pill--'+s.v.toLowerCase()]"
                  @click="toggleFilter('sync_status', s.v)">{{ s.label }}</button>
              </div>
            </div>
            <div class="pr-fp-row">
              <span class="pr-fp-lbl">Period</span>
              <div class="pr-fp-pills">
                <button v-for="p in DATE_PRESETS" :key="p.v"
                  :class="['pr-pill', datePreset===p.v && 'pr-pill--on pr-pill--date']"
                  @click="datePreset = p.v">{{ p.label }}</button>
                <button :class="['pr-pill', datePreset==='custom' && 'pr-pill--on pr-pill--date']"
                  @click="datePreset = 'custom'">Custom range</button>
              </div>
            </div>
            <!-- Custom date range (shown when preset='custom') -->
            <div v-if="datePreset === 'custom'" class="pr-fp-row pr-fp-row--dates">
              <span class="pr-fp-lbl">From</span>
              <input type="date" v-model="customDateFrom" class="pr-date-input" :max="customDateTo || undefined" />
              <span class="pr-fp-lbl" style="margin-left:4px">To</span>
              <input type="date" v-model="customDateTo"   class="pr-date-input" :min="customDateFrom || undefined" />
              <button v-if="customDateFrom || customDateTo"
                class="pr-pill pr-pill--on" style="margin-left:4px"
                @click="customDateFrom=null; customDateTo=null">Clear</button>
            </div>

            <div class="pr-fp-row">
              <span class="pr-fp-lbl">Temp °C ≥</span>
              <div class="pr-fp-pills">
                <button v-for="t in TEMP_PRESETS" :key="t.v"
                  :class="['pr-pill', tempPreset===t.v && 'pr-pill--on pr-pill--fever']"
                  @click="tempPreset = tempPreset===t.v ? null : t.v">{{ t.label }}</button>
              </div>
            </div>
            <button v-if="activeFilterCount" class="pr-clear-all" @click="clearAllFilters">
              Clear all filters
            </button>
          </div>
        </transition>

        <!-- Active chip row -->
        <div v-if="(activeFilterCount || searchQuery) && !filtersOpen" class="pr-chip-row">
          <span v-if="filters.gender"            class="pr-chip pr-chip--gender">{{ filters.gender }}<button @click="filters.gender=null" class="pr-chip-x">×</button></span>
          <span v-if="filters.symptoms_present==='1'" class="pr-chip pr-chip--sym">Symptomatic<button @click="filters.symptoms_present=null" class="pr-chip-x">×</button></span>
          <span v-if="filters.symptoms_present==='0'" class="pr-chip pr-chip--ok">Asymptomatic<button @click="filters.symptoms_present=null" class="pr-chip-x">×</button></span>
          <span v-if="filters.referral_created==='1'" class="pr-chip pr-chip--ref">Referred<button @click="filters.referral_created=null" class="pr-chip-x">×</button></span>
          <span v-if="filters.sync_status"       class="pr-chip" :class="'pr-chip--'+filters.sync_status.toLowerCase()">{{ SYNC_LABELS[filters.sync_status] }}<button @click="filters.sync_status=null" class="pr-chip-x">×</button></span>
          <span v-if="datePreset!=='all'"         class="pr-chip pr-chip--date">{{ DATE_PRESETS.find(p=>p.v===datePreset)?.label }}<button @click="datePreset='all'" class="pr-chip-x">×</button></span>
          <span v-if="tempPreset"                 class="pr-chip pr-chip--fever">≥{{ tempPreset }}°C<button @click="tempPreset=null" class="pr-chip-x">×</button></span>
          <span v-if="showFeverOnly"              class="pr-chip pr-chip--fever">Fever only<button @click="showFeverOnly=false" class="pr-chip-x">×</button></span>
          <span class="pr-chip-count">{{ displayItems.length }} result{{ displayItems.length!==1?'s':'' }}</span>
        </div>
      </div>

      <!-- Bulk sync bar -->
      <div v-if="unsyncedCount > 0 && isOnline && !syncing" class="pr-bulk-bar">
        <IonIcon :icon="cloudUploadOutline" />
        <span>{{ unsyncedCount }} record{{ unsyncedCount!==1?'s':'' }} pending upload</span>
        <button class="pr-bulk-btn" @click="syncAllPending">Sync All Now</button>
      </div>
    </IonHeader>

    <!-- ═══ CONTENT ══════════════════════════════════════════════════════ -->
    <IonContent class="pr-content" :fullscreen="true">
      <IonRefresher slot="fixed" @ionRefresh="onPullRefresh($event)">
        <IonRefresherContent pulling-text="Pull to refresh" refreshing-spinner="crescent" />
      </IonRefresher>

      <!-- Loading -->
      <div v-if="loading && !allItems.length" class="pr-loading">
        <IonSpinner name="crescent" class="pr-spinner" />
        <p>Loading screening records…</p>
      </div>

      <!-- Offline -->
      <div v-if="!isOnline && !loading" class="pr-offline-bar">
        <IonIcon :icon="cloudOfflineOutline" />
        <span>Offline · {{ allItems.length }} cached records</span>
      </div>

      <!-- Empty -->
      <div v-else-if="!loading && !displayItems.length" class="pr-empty">
        <IonIcon :icon="medkitOutline" class="pr-empty-icon" />
        <h2 class="pr-empty-title">{{ searchQuery ? 'No Matching Records' : 'No Records Found' }}</h2>
        <p class="pr-empty-sub">{{ searchQuery ? 'Try different search terms or clear filters.' : 'No primary screenings match the current filters.' }}</p>
        <IonButton v-if="activeFilterCount || searchQuery" fill="outline" size="small" @click="clearAllFilters(); searchQuery=''">Show all</IonButton>
      </div>

      <!-- Record list -->
      <div v-else class="pr-list" role="list">
        <article
          v-for="item in displayItems" :key="item.client_uuid"
          :class="['pr-card', sympClass(item), item.record_status==='VOIDED'&&'pr-card--voided', item.sync_status!=='SYNCED'&&'pr-card--unsynced']"
          role="listitem" @click="openDetail(item)"
        >
          <!-- Stripe: red=symptomatic, green=asymptomatic, amber=voided -->
          <div class="pr-card-stripe" />

          <div class="pr-card-body">
            <!-- Row 1: badges + sync dot + time -->
            <div class="pr-card-top">
              <span :class="['pr-symp-badge', item.symptoms_present ? 'pr-symp-badge--yes' : 'pr-symp-badge--no']">
                {{ item.symptoms_present ? '⚠ SYMPTOMATIC' : '✓ ASYMPTOMATIC' }}
              </span>
              <span v-if="item.record_status==='VOIDED'" class="pr-voided-badge">VOIDED</span>
              <span v-if="item.referral_created" class="pr-ref-badge">REFERRED</span>
              <span :class="['pr-sync-dot', 'pr-sync-dot--'+item.sync_status.toLowerCase()]"
                :title="SYNC_LABELS[item.sync_status]" />
              <span class="pr-card-time">{{ fmtRelative(item.captured_at) }}</span>
            </div>

            <!-- Row 2: traveler + temperature -->
            <div class="pr-card-traveler">
              <div class="pr-gender-avatar" :class="'pr-gender-avatar--'+(item.gender||'unknown').toLowerCase()" aria-hidden="true">
                {{ GENDER_ICONS[item.gender] || '?' }}
              </div>
              <div class="pr-traveler-info">
                <span class="pr-traveler-name">{{ item.traveler_full_name || GENDER_LABELS[item.gender] || 'Unknown' }}</span>
                <span class="pr-traveler-sub">{{ GENDER_LABELS[item.gender] || '—' }} · {{ fmtTime(item.captured_at) }}</span>
              </div>
              <div v-if="item.temperature_value != null" :class="['pr-temp-chip', tempChipClass(item.temperature_c, item.temperature_flag)]">
                {{ item.temperature_value }}°{{ item.temperature_unit || 'C' }}
              </div>
              <div v-else class="pr-temp-chip pr-temp-chip--none">No temp</div>
            </div>

            <!-- Row 3: notification + secondary case chain -->
            <div v-if="item.referral_created || item.notification_status || item.secondary_case_status" class="pr-card-chain">
              <span v-if="item.notification_status" :class="['pr-chain-badge', 'pr-chain-badge--notif-'+item.notification_status.toLowerCase().replace('_','-')]">
                Referral: {{ item.notification_status }}
              </span>
              <span v-if="item.secondary_case_status" :class="['pr-chain-badge', 'pr-chain-badge--sec-'+item.secondary_case_status.toLowerCase().replace('_','-')]">
                Case: {{ STATUS_LABELS[item.secondary_case_status] || item.secondary_case_status }}
              </span>
              <span v-if="item.secondary_risk_level" :class="['pr-chain-badge', 'pr-chain-badge--risk-'+item.secondary_risk_level.toLowerCase()]">
                {{ item.secondary_risk_level }} RISK
              </span>
            </div>

            <!-- Row 4: footer: screener + POE + sync -->
            <div class="pr-card-footer">
              <span class="pr-card-meta">{{ item.screener_name || 'Unknown officer' }}</span>
              <span class="pr-card-meta">{{ item.poe_code }}</span>
              <button
                v-if="item.sync_status !== 'SYNCED'"
                :class="['pr-card-sync-btn','pr-card-sync-btn--'+item.sync_status.toLowerCase()]"
                @click.stop="syncOneRecord(item)"
                :disabled="syncingUuids.has(item.client_uuid) || !isOnline"
              >
                <IonIcon v-if="!syncingUuids.has(item.client_uuid)" :icon="cloudUploadOutline" />
                <IonSpinner v-else name="crescent" style="width:11px;height:11px" />
                {{ syncingUuids.has(item.client_uuid) ? 'Syncing…' : SYNC_LABELS[item.sync_status] }}
              </button>
              <span v-else class="pr-card-synced">
                <IonIcon :icon="checkmarkCircleOutline" /> Synced
              </span>
            </div>
          </div>
        </article>

        <!-- Load more -->
        <div v-if="hasMore" class="pr-load-more">
          <IonButton fill="outline" expand="block" :disabled="loadingMore" @click="loadMore">
            <IonSpinner v-if="loadingMore" name="crescent" style="width:16px;height:16px;margin-right:6px" />
            <span v-else>Load more ({{ totalOnServer - allItems.length }} remaining)</span>
          </IonButton>
        </div>
      </div>
      <div style="height:32px" />
    </IonContent>

    <!-- ═══ DETAIL MODAL ═════════════════════════════════════════════════ -->
    <IonModal :is-open="modalOpen" :initial-breakpoint="0.92" :breakpoints="[0,0.5,0.92,1]"
      handle-behavior="cycle" @didDismiss="closeModal" class="pr-modal">
      <IonHeader class="pr-modal-header">
        <IonToolbar class="pr-modal-toolbar">
          <IonButtons slot="start">
            <IonButton fill="clear" @click="dismissModal" class="pr-modal-close">
              <IonIcon :icon="closeOutline" slot="icon-only" />
            </IonButton>
          </IonButtons>
          <div class="pr-modal-title-block">
            <span class="pr-modal-eyebrow">Record #{{ detailRecord?.id || '—' }}</span>
            <span class="pr-modal-title">{{ detailRecord?.traveler_full_name || (GENDER_LABELS[detailRecord?.gender] + ' Traveler') || 'Primary Screening' }}</span>
          </div>
          <IonButtons slot="end">
            <button v-if="detailRecord && detailRecord?.sync_status !== 'SYNCED'" class="pr-modal-sync-btn"
              @click="syncOneRecord(detailRecord)" :disabled="syncingUuids.has(detailRecord?.client_uuid)||!isOnline">
              <IonIcon v-if="!syncingUuids.has(detailRecord?.client_uuid)" :icon="cloudUploadOutline" />
              <IonSpinner v-else name="crescent" style="width:13px;height:13px" />
              {{ syncingUuids.has(detailRecord?.client_uuid) ? 'Syncing…' : 'Sync Now' }}
            </button>
            <span v-else-if="detailRecord?.sync_status==='SYNCED'" class="pr-modal-synced">
              <IonIcon :icon="checkmarkCircleOutline" /> Synced
            </span>
          </IonButtons>
        </IonToolbar>

        <!-- Status bar -->
        <IonToolbar v-if="detailRecord" class="pr-modal-status-toolbar">
          <div class="pr-modal-status-bar">
            <span :class="['pr-symp-badge', detailRecord.symptoms_present ? 'pr-symp-badge--yes' : 'pr-symp-badge--no']">
              {{ detailRecord.symptoms_present ? '⚠ SYMPTOMATIC' : '✓ ASYMPTOMATIC' }}
            </span>
            <span v-if="detailRecord.record_status==='VOIDED'" class="pr-voided-badge">VOIDED</span>
            <span v-if="detailRecord.referral_created" class="pr-ref-badge">REFERRED</span>
            <span :class="['pr-sync-status-badge','pr-sync-status-badge--'+(detailRecord?.sync_status||'unsynced').toLowerCase()]">
              {{ SYNC_LABELS[detailRecord?.sync_status] }}
            </span>
          </div>
        </IonToolbar>

        <!-- Modal tabs -->
        <IonToolbar class="pr-modal-tabs-toolbar">
          <div class="pr-modal-tabs" role="tablist">
            <button v-for="t in MODAL_TABS" :key="t.key"
              :class="['pr-modal-tab', modalTab===t.key && 'pr-modal-tab--on']"
              @click="modalTab=t.key" role="tab" :aria-selected="modalTab===t.key">
              {{ t.label }}
            </button>
          </div>
        </IonToolbar>
      </IonHeader>

      <!-- Scrollable content -->
      <div class="pr-modal-scroll">

        <div v-if="detailLoading" class="pr-detail-loading">
          <IonSpinner name="crescent" /><span>Loading full record…</span>
        </div>

        <div v-else-if="detailRecord" class="pr-modal-body">

          <!-- ── TAB: OVERVIEW ──────────────────────────────────────── -->
          <div v-show="modalTab==='overview'">

            <!-- Sync card -->
            <div :class="['pr-sync-card','pr-sync-card--'+(detailRecord?.sync_status||'unsynced').toLowerCase()]">
              <div class="pr-sync-card-hdr">
                <IonIcon :icon="detailRecord?.sync_status==='SYNCED'?checkmarkCircleOutline:cloudUploadOutline" class="pr-sc-icon" />
                <span class="pr-sc-title">Sync Status</span>
                <span :class="['pr-sync-status-badge','pr-sync-status-badge--'+(detailRecord?.sync_status||'unsynced').toLowerCase()]">{{ SYNC_LABELS[detailRecord?.sync_status] }}</span>
              </div>
              <div class="pr-kv-grid pr-sync-grid">
                <div class="pr-kv"><span class="pr-k">Server ID</span><span class="pr-v">{{ detailRecord.id || 'Not assigned' }}</span></div>
                <div class="pr-kv"><span class="pr-k">Synced At</span><span class="pr-v">{{ fmtDT(detailRecord.synced_at) || 'Never' }}</span></div>
                <div class="pr-kv"><span class="pr-k">Attempts</span><span class="pr-v">{{ detailRecord.sync_attempt_count || 0 }}</span></div>
                <div class="pr-kv"><span class="pr-k">Received by Server</span><span class="pr-v">{{ fmtDT(detailRecord.server_received_at) || 'Never' }}</span></div>
                <div v-if="detailRecord.last_sync_error" class="pr-kv pr-kv--full"><span class="pr-k">Last Error</span><span class="pr-v pr-v--error">{{ detailRecord.last_sync_error }}</span></div>
                <div class="pr-kv"><span class="pr-k">UUID</span><span class="pr-v pr-uuid">{{ detailRecord.client_uuid }}</span></div>
                <div class="pr-kv"><span class="pr-k">Record Version</span><span class="pr-v">v{{ detailRecord.record_version }}</span></div>
              </div>
              <button v-if="detailRecord && detailRecord?.sync_status!=='SYNCED' && isOnline" class="pr-sc-action"
                @click="syncOneRecord(detailRecord)" :disabled="syncingUuids.has(detailRecord.client_uuid)">
                <IonIcon :icon="cloudUploadOutline" />
                {{ syncingUuids.has(detailRecord.client_uuid) ? 'Syncing…' : 'Sync This Record Now' }}
              </button>
              <p v-else-if="!isOnline && detailRecord?.sync_status!=='SYNCED'" class="pr-sc-offline">Connect to internet to sync</p>
            </div>

            <!-- Timeline -->
            <div class="pr-section-hdr"><span class="pr-sec-n">T</span> Capture Timeline</div>
            <div class="pr-timeline">
              <div class="pr-tl-row">
                <div class="pr-tl-dot pr-tl-dot--captured" />
                <div><span class="pr-tl-label">Captured</span><span class="pr-tl-val">{{ fmtDT(detailRecord.captured_at) || '—' }}</span><span class="pr-tl-sub">{{ detailRecord.captured_timezone || '' }}</span></div>
              </div>
              <div v-if="detailRecord.server_received_at" class="pr-tl-row">
                <div class="pr-tl-dot pr-tl-dot--synced" />
                <div><span class="pr-tl-label">Received by server</span><span class="pr-tl-val">{{ fmtDT(detailRecord.server_received_at) }}</span></div>
              </div>
              <div v-if="detailRecord.record_status==='VOIDED'" class="pr-tl-row">
                <div class="pr-tl-dot pr-tl-dot--voided" />
                <div><span class="pr-tl-label">Voided</span><span class="pr-tl-val">{{ fmtDT(detailRecord.updated_at) }}</span><span class="pr-tl-sub">{{ detailRecord.void_reason }}</span></div>
              </div>
            </div>

            <!-- Referral chain -->
            <template v-if="detailRecord.notification || detailRecord.secondary_case">
              <div class="pr-section-hdr"><span class="pr-sec-n">R</span> Referral Chain</div>
              <div class="pr-chain-card">
                <div v-if="detailRecord.notification" class="pr-chain-row">
                  <span class="pr-chain-type">Notification</span>
                  <div class="pr-kv-grid">
                    <div class="pr-kv"><span class="pr-k">Status</span><span class="pr-v">{{ detailRecord.notification.status }}</span></div>
                    <div class="pr-kv"><span class="pr-k">Priority</span><span class="pr-v">{{ detailRecord.notification.priority }}</span></div>
                    <div class="pr-kv"><span class="pr-k">Opened</span><span class="pr-v">{{ fmtDT(detailRecord.notification.opened_at) || '—' }}</span></div>
                    <div class="pr-kv"><span class="pr-k">Closed</span><span class="pr-v">{{ fmtDT(detailRecord.notification.closed_at) || '—' }}</span></div>
                  </div>
                </div>
                <div v-if="detailRecord.secondary_case" class="pr-chain-row">
                  <span class="pr-chain-type">Secondary Case</span>
                  <div class="pr-kv-grid">
                    <div class="pr-kv"><span class="pr-k">Status</span><span class="pr-v">{{ STATUS_LABELS[detailRecord.secondary_case.case_status] || detailRecord.secondary_case.case_status }}</span></div>
                    <div class="pr-kv"><span class="pr-k">Risk</span><span class="pr-v" :class="detailRecord.secondary_case.risk_level&&'pr-risk-'+detailRecord.secondary_case.risk_level.toLowerCase()">{{ detailRecord.secondary_case.risk_level || '—' }}</span></div>
                    <div class="pr-kv"><span class="pr-k">Syndrome</span><span class="pr-v">{{ detailRecord.secondary_case.syndrome_classification?.replace(/_/g,' ') || '—' }}</span></div>
                    <div class="pr-kv"><span class="pr-k">Disposition</span><span class="pr-v">{{ detailRecord.secondary_case.final_disposition?.replace(/_/g,' ') || '—' }}</span></div>
                    <div class="pr-kv"><span class="pr-k">Opened</span><span class="pr-v">{{ fmtDT(detailRecord.secondary_case.opened_at) || '—' }}</span></div>
                    <div class="pr-kv"><span class="pr-k">Opened By</span><span class="pr-v">{{ detailRecord.secondary_case.opener_name || '—' }}</span></div>
                  </div>
                </div>
                <div v-if="detailRecord.alert" class="pr-chain-row pr-chain-row--alert">
                  <span class="pr-chain-type">⚠ Alert</span>
                  <div class="pr-kv-grid">
                    <div class="pr-kv"><span class="pr-k">Code</span><span class="pr-v">{{ detailRecord.alert.alert_code }}</span></div>
                    <div class="pr-kv"><span class="pr-k">Status</span><span class="pr-v">{{ detailRecord.alert.status }}</span></div>
                    <div class="pr-kv"><span class="pr-k">Routed To</span><span class="pr-v">{{ detailRecord.alert.routed_to_level }}</span></div>
                  </div>
                </div>
              </div>
            </template>
          </div>

          <!-- ── TAB: CLINICAL ──────────────────────────────────────── -->
          <div v-show="modalTab==='clinical'">
            <div class="pr-section-hdr"><span class="pr-sec-n">C</span> Clinical Data</div>
            <div class="pr-kv-grid">
              <div class="pr-kv"><span class="pr-k">Gender</span><span class="pr-v">{{ GENDER_LABELS[detailRecord.gender] || '—' }}</span></div>
              <div class="pr-kv"><span class="pr-k">Traveler Name</span><span class="pr-v">{{ detailRecord.traveler_full_name || 'Not recorded' }}</span></div>
              <div class="pr-kv"><span class="pr-k">Symptoms Present</span>
                <span :class="['pr-v', detailRecord.symptoms_present ? 'pr-v--danger' : 'pr-v--ok']">
                  {{ detailRecord.symptoms_present ? '⚠ YES — Symptomatic' : '✓ NO — Asymptomatic' }}
                </span>
              </div>
              <div class="pr-kv"><span class="pr-k">Referral Created</span>
                <span :class="['pr-v', detailRecord.referral_created ? 'pr-v--warn' : '']">
                  {{ detailRecord.referral_created ? 'Yes — Referred to Secondary' : 'No' }}
                </span>
              </div>
            </div>

            <!-- Vitals -->
            <div class="pr-section-hdr"><span class="pr-sec-n">V</span> Vital Signs</div>
            <div class="pr-vitals-row">
              <div :class="['pr-vital-card', tempCardClass(detailRecord.temperature_c, detailRecord.temperature_flag)]">
                <span class="pr-vital-val">
                  {{ detailRecord.temperature_value != null ? detailRecord.temperature_value+'°'+(detailRecord.temperature_unit||'C') : '—' }}
                </span>
                <span class="pr-vital-lbl">Temperature</span>
                <span v-if="detailRecord.temperature_flag && detailRecord.temperature_flag!=='NORMAL'" class="pr-vital-flag">
                  {{ detailRecord.temperature_flag === 'CRITICAL' ? '🔴 Critical' : detailRecord.temperature_flag === 'HIGH' ? '🟡 Fever' : '🔵 Low' }}
                </span>
                <span v-if="detailRecord.temperature_c && detailRecord.temperature_unit==='F'" class="pr-vital-sub">
                  ≈ {{ detailRecord.temperature_c }}°C
                </span>
              </div>
            </div>

            <!-- Record status -->
            <div class="pr-section-hdr"><span class="pr-sec-n">S</span> Record Status</div>
            <div class="pr-kv-grid">
              <div class="pr-kv"><span class="pr-k">Status</span>
                <span :class="['pr-v', detailRecord.record_status==='VOIDED'?'pr-v--error':'pr-v--ok']">
                  {{ detailRecord.record_status }}
                </span>
              </div>
              <div v-if="detailRecord.void_reason" class="pr-kv pr-kv--full">
                <span class="pr-k">Void Reason</span>
                <span class="pr-v pr-v--error">{{ detailRecord.void_reason }}</span>
              </div>
            </div>

            <!-- Void action -->
            <div v-if="detailRecord.can_void && detailRecord.record_status!=='VOIDED'" class="pr-void-section">
              <div class="pr-void-warning">
                <IonIcon :icon="warningOutline" class="pr-void-icon" />
                <span>Voiding is permanent. The primary screening record will be marked invalid in the audit trail.</span>
              </div>
              <div v-if="showVoidForm" class="pr-void-form">
                <textarea v-model="voidReason" class="pr-void-textarea" rows="3"
                  placeholder="Void reason (minimum 10 characters, required)…" />
                <div class="pr-void-actions">
                  <button class="pr-void-cancel" @click="showVoidForm=false; voidReason=''">Cancel</button>
                  <button class="pr-void-confirm" @click="submitVoid" :disabled="voidReason.trim().length < 10 || voiding">
                    {{ voiding ? 'Voiding…' : 'Confirm Void' }}
                  </button>
                </div>
              </div>
              <button v-else class="pr-void-btn" @click="showVoidForm=true">
                <IonIcon :icon="trashOutline" /> Void This Record
              </button>
            </div>
          </div>

          <!-- ── TAB: SCREENER ──────────────────────────────────────── -->
          <div v-show="modalTab==='screener'">
            <div class="pr-section-hdr"><span class="pr-sec-n">O</span> Screening Officer</div>
            <div class="pr-kv-grid">
              <div class="pr-kv"><span class="pr-k">Full Name</span><span class="pr-v">{{ detailRecord.screener_name || '—' }}</span></div>
              <div class="pr-kv"><span class="pr-k">Username</span><span class="pr-v">{{ detailRecord.screener_username || '—' }}</span></div>
              <div class="pr-kv"><span class="pr-k">Role</span><span class="pr-v">{{ detailRecord.screener_role || '—' }}</span></div>
              <div class="pr-kv"><span class="pr-k">Phone</span><span class="pr-v">{{ detailRecord.screener_phone || '—' }}</span></div>
            </div>
            <div class="pr-section-hdr"><span class="pr-sec-n">G</span> Geography</div>
            <div class="pr-kv-grid">
              <div class="pr-kv"><span class="pr-k">POE</span><span class="pr-v">{{ detailRecord.poe_code }}</span></div>
              <div class="pr-kv"><span class="pr-k">District</span><span class="pr-v">{{ detailRecord.district_code }}</span></div>
              <div class="pr-kv"><span class="pr-k">PHEOC</span><span class="pr-v">{{ detailRecord.pheoc_code || '—' }}</span></div>
              <div class="pr-kv"><span class="pr-k">Country</span><span class="pr-v">{{ detailRecord.country_code }}</span></div>
              <div class="pr-kv"><span class="pr-k">Region</span><span class="pr-v">{{ detailRecord.province_code || '—' }}</span></div>
            </div>
            <div class="pr-section-hdr"><span class="pr-sec-n">D</span> Device</div>
            <div class="pr-kv-grid">
              <div class="pr-kv"><span class="pr-k">Device ID</span><span class="pr-v pr-uuid">{{ detailRecord.device_id }}</span></div>
              <div class="pr-kv"><span class="pr-k">Platform</span><span class="pr-v">{{ detailRecord.platform }}</span></div>
              <div class="pr-kv"><span class="pr-k">App Version</span><span class="pr-v">{{ detailRecord.app_version || '—' }}</span></div>
            </div>
          </div>

          <!-- ── TAB: AUDIT ─────────────────────────────────────────── -->
          <div v-show="modalTab==='audit'">
            <div class="pr-section-hdr"><span class="pr-sec-n">I</span> Record Integrity</div>
            <div class="pr-kv-grid">
              <div class="pr-kv"><span class="pr-k">Server ID</span><span class="pr-v">{{ detailRecord.id || 'Not yet synced' }}</span></div>
              <div class="pr-kv"><span class="pr-k">Client UUID</span><span class="pr-v pr-uuid">{{ detailRecord.client_uuid }}</span></div>
              <div class="pr-kv"><span class="pr-k">Record Version</span><span class="pr-v">v{{ detailRecord.record_version }}</span></div>
              <div class="pr-kv"><span class="pr-k">Ref. Data Ver.</span><span class="pr-v">{{ detailRecord.reference_data_version }}</span></div>
              <div class="pr-kv"><span class="pr-k">Sync Status</span><span class="pr-v" :class="'pr-sync-text--'+(detailRecord?.sync_status||'unsynced').toLowerCase()">{{ SYNC_LABELS[detailRecord?.sync_status] }}</span></div>
              <div class="pr-kv"><span class="pr-k">Sync Attempts</span><span class="pr-v">{{ detailRecord.sync_attempt_count || 0 }}</span></div>
              <div class="pr-kv"><span class="pr-k">Synced At</span><span class="pr-v">{{ fmtDT(detailRecord.synced_at) || 'Never' }}</span></div>
              <div class="pr-kv"><span class="pr-k">Server Received</span><span class="pr-v">{{ fmtDT(detailRecord.server_received_at) || 'Never' }}</span></div>
              <div class="pr-kv"><span class="pr-k">Created</span><span class="pr-v">{{ fmtDT(detailRecord.created_at) }}</span></div>
              <div class="pr-kv"><span class="pr-k">Last Updated</span><span class="pr-v">{{ fmtDT(detailRecord.updated_at) }}</span></div>
              <div v-if="detailRecord.last_sync_error" class="pr-kv pr-kv--full">
                <span class="pr-k">Last Sync Error</span>
                <span class="pr-v pr-v--error">{{ detailRecord.last_sync_error }}</span>
              </div>
            </div>
          </div>

        </div>

        <!-- Bottom spacer -->
        <div style="height:80px" aria-hidden="true" />
      </div>
    </IonModal>

    <!-- Toast -->
    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="3200"
      position="top" @didDismiss="toast.show=false" />
  </IonPage>
</template>

<script setup>
// ─────────────────────────────────────────────────────────────────────────────
// PrimaryRecords.vue — ECSA-HC POE Sentinel
// WHO/IHR 2005 · Article 23 · Primary Screening Record Register
//
// ══ ARCHITECTURE FOR 1,000,000,000+ RECORDS ══════════════════════════════════
//
//  CORE PROBLEM with previous approach:
//    dbGetByIndex('poe_code', poe).toArray() → loads ENTIRE store into heap.
//    At 1B records × ~500 bytes = 500 GB — impossible. App crash.
//
//  THREE-TIER CACHE ARCHITECTURE:
//
//  TIER 1 — IDB (IndexedDB, persistent across restarts)
//    • Stats: dbCountIndex(poe_code, value) → O(1), reads ZERO record bytes
//    • Pages: dbGetByIndex + JS slice(offset, offset+PAGE) — reads 100 at a time
//    • Write-through: every server response writes ALL fields to IDB immediately
//    • The truth: IDB is the source of truth for offline mode
//
//  TIER 2 — Memory window (capped at MAX_WINDOW = 300 records)
//    • Never grows beyond 300 regardless of total record count
//    • Built from IDB pages on scroll; old pages evicted as new ones arrive
//    • Filters applied server-side when online, JS-side on window when offline
//
//  TIER 3 — Server (primary-records API)
//    • Incremental sync: ?updated_after=<ISO> → only records changed since last sync
//    • First load: fetches page 1 (newest), writes to IDB, populates window
//    • Background sync: fires on 'online' event + every POLL_INTERVAL_MS (60s)
//    • At 1B records, daily sync brings hundreds of records, not billions
//
//  OFFLINE BEHAVIOUR:
//    • Mount → IDB count stats (O(1), instant)
//    • Mount → IDB page 1 (100 records, instant)
//    • Offline: full JS filter on memory window (300 records max)
//    • Detail modal: IDB primary record + linked notification — fully offline
//    • Stats: idbCountIndex for totals; serverStats for windowed symptomatic rate
//
//  ONLINE RECONNECT:
//    • 'online' event → backgroundServerSync(500ms debounce)
//    • backgroundServerSync: ?updated_after=lastSyncCursor → delta only
//    • Result written to IDB + merged into memory window
//    • Stats refreshed from IDB counts (not by rescanning window)
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, reactive, onMounted, onUnmounted, toRaw, watch, nextTick } from 'vue'
import { useRoute } from 'vue-router'
import {
  IonPage, IonHeader, IonToolbar, IonButtons, IonMenuButton,
  IonButton, IonContent, IonIcon, IonSpinner,
  IonRefresher, IonRefresherContent,
  IonModal, IonToast,
  onIonViewWillEnter,
} from '@ionic/vue'
import {
  refreshOutline, searchOutline, closeCircleOutline, optionsOutline,
  cloudUploadOutline, cloudOfflineOutline, checkmarkCircleOutline,
  closeOutline, medkitOutline, warningOutline, trashOutline,
  barChartOutline, chevronUpOutline,
} from 'ionicons/icons'
import {
  dbGet, dbGetByIndex, dbPut, dbDelete, safeDbPut, dbCountIndex,
  isoNow, STORE, SYNC, APP,
} from '@/services/poeDB'

// ─── AUTH ─────────────────────────────────────────────────────────────────────
function getAuth() { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {} }
const auth = ref(getAuth())
const route = useRoute()

let _deepLinkedUuid = null
async function tryDeepLinkOpen() {
  const openUuid = String(route.query?.open || '').trim()
  if (!openUuid || _deepLinkedUuid === openUuid) return
  _deepLinkedUuid = openUuid
  await nextTick()
  let item = allItems.value.find(i => i.client_uuid === openUuid)
  if (!item) item = await dbGet(STORE.PRIMARY_SCREENINGS, openUuid).catch(() => null)
  if (item) openDetail(item)
}

// ─── CONSTANTS ────────────────────────────────────────────────────────────────
const STATUS_TABS = [
  { v: null,        label: 'All',       bc: 'pr-tb--all'  },
  { v: 'COMPLETED', label: 'Completed', bc: 'pr-tb--ok'   },
  { v: 'VOIDED',    label: 'Voided',    bc: 'pr-tb--void' },
]
const GENDERS      = [{ v:'MALE',label:'Male' },{ v:'FEMALE',label:'Female' }]
// Maps include legacy values as fallback for existing records, but UI only offers MALE/FEMALE
const GENDER_LABELS= { MALE:'Male', FEMALE:'Female', OTHER:'—', UNKNOWN:'—' }
const GENDER_ICONS = { MALE:'♂', FEMALE:'♀', OTHER:'?', UNKNOWN:'?' }
const SYNC_TABS    = [{ v:'SYNCED',label:'Synced' },{ v:'UNSYNCED',label:'Pending' },{ v:'FAILED',label:'Failed' }]
const SYNC_LABELS  = { SYNCED:'Synced', UNSYNCED:'Pending', FAILED:'Failed' }
const STATUS_LABELS= { OPEN:'Open', IN_PROGRESS:'In Progress', DISPOSITIONED:'Dispositioned', CLOSED:'Closed' }
const DATE_PRESETS = [
  { v:'all',label:'All time' },{ v:'today',label:'Today' },{ v:'yesterday',label:'Yesterday' },
  { v:'week',label:'7 days' },{ v:'month',label:'30 days' },{ v:'this_month',label:'This month' },
  { v:'last_month',label:'Last month' },{ v:'this_year',label:'This year' },{ v:'last_year',label:'Last year' },
]
const TEMP_PRESETS = [{ v:37.5, label:'≥37.5 (Fever)' },{ v:38.5, label:'≥38.5 (High)' }]
const MODAL_TABS   = [
  { key:'overview', label:'Overview' }, { key:'clinical', label:'Clinical' },
  { key:'screener', label:'Screener' }, { key:'audit',    label:'Audit'    },
]

// Performance constants
const MAX_WINDOW       = 300    // Records kept in JS heap at any time
const IDB_PAGE_SIZE    = 100    // Records per IDB read call
const SERVER_PAGE_SIZE = 100    // Records per server request
const POLL_INTERVAL_MS = 60_000 // Background sync interval
const LAST_SYNC_KEY    = 'ug_pr_last_server_sync' // country-namespaced cursor key for this tenant

// ─── STATE ────────────────────────────────────────────────────────────────────

// Memory window — the currently visible slice. NEVER exceeds MAX_WINDOW.
const allItems       = ref([])
// Quarantined records — sync_attempt_count >= QUARANTINE_MAX_ATTEMPTS
const quarantinedRecords = ref([])
const showQuarantinePanel = ref(false)
const QUARANTINE_MAX_ATTEMPTS = 4

// IDB-derived counts — full scan scoped to poe_code
const idbTotalCount    = ref(0)
const idbUnsyncedCount = ref(0)
// Per-status counts from full IDB scan (not window)
const idbStatusCounts  = ref({ COMPLETED: 0, VOIDED: 0 })
// Per-category counts from full IDB scan
const idbSymptomaticCount = ref(0)
const idbReferralCount    = ref(0)
const idbFeverCount       = ref(0)

// Pagination
const idbPageOffset  = ref(0)
const serverPage     = ref(1)
const totalOnServer  = ref(0)
const hasMoreIdb     = ref(true)
const hasMoreServer  = ref(true)
const loading        = ref(true)
const loadingMore    = ref(false)
const isOnline       = ref(navigator.onLine)

// Filters
const filters = reactive({ record_status:null, gender:null, symptoms_present:null, referral_created:null, sync_status:null })
const searchQuery    = ref('')
const datePreset     = ref('all')
const tempPreset     = ref(null)
const showFeverOnly  = ref(false)
const filtersOpen    = ref(false)
const customDateFrom = ref(null)
const customDateTo   = ref(null)

// Modal
const modalOpen      = ref(false)
const detailRecord   = ref(null)
const detailLoading  = ref(false)
const modalTab       = ref('overview')

// Sync
const syncingUuids   = ref(new Set())
const syncing        = ref(false)

// Void
const showVoidForm   = ref(false)
const voidReason     = ref('')
const voiding        = ref(false)

// Charts (serverStats from /primary-records/stats — authoritative, covers ALL records)
const serverStats    = ref(null)
const trendSeries    = ref([])
const chartsOpen     = ref(false)

const toast = reactive({ show:false, msg:'', color:'success' })

let autoRefreshTimer = null
let bgSyncDebounce   = null
let searchDebounce   = null

// ─── COMPUTED — STATS FROM IDB COUNTS ─────────────────────────────────────────
// idbTotalCount and idbUnsyncedCount come from O(1) IDB index count queries.
// Symptomatic/referral/fever stats come from serverStats (covers all records, not just window).
// Fallback to window counts only when serverStats not yet loaded.
// Stats: use full IDB scan counts (accurate across all records, not just 300-item window)
const totalCount       = computed(() => idbTotalCount.value)
const symptomaticCount = computed(() => idbSymptomaticCount.value)
const referralCount    = computed(() => idbReferralCount.value)
const feverCount       = computed(() => idbFeverCount.value)
const unsyncedCount    = computed(() => idbUnsyncedCount.value)

const syncPillClass = computed(() => {
  if (!isOnline.value)     return 'pr-sync-pill--offline'
  if (syncing.value)       return 'pr-sync-pill--syncing'
  if (idbUnsyncedCount.value) return 'pr-sync-pill--pending'
  return 'pr-sync-pill--ok'
})
const syncPillLabel = computed(() => {
  if (!isOnline.value)     return 'Offline'
  if (idbUnsyncedCount.value) return `${idbUnsyncedCount.value} Pending`
  return 'All Synced'
})

const hasMore = computed(() =>
  (hasMoreIdb.value && allItems.value.length < idbTotalCount.value) ||
  (hasMoreServer.value && isOnline.value)
)

// ─── COMPUTED — FILTER + DISPLAY ──────────────────────────────────────────────
const activeFilterCount = computed(() =>
  [filters.record_status, filters.gender, filters.symptoms_present,
   filters.referral_created, filters.sync_status,
   datePreset.value!=='all'?1:null, tempPreset.value,
   showFeverOnly.value?1:null].filter(Boolean).length
)

function dateFromPreset() {
  const now = new Date()
  if (datePreset.value==='today')      { const d=new Date(now);d.setHours(0,0,0,0);return{from:d,to:null} }
  if (datePreset.value==='yesterday')  { const d=new Date(now);d.setDate(d.getDate()-1);d.setHours(0,0,0,0);const e=new Date(d);e.setHours(23,59,59,999);return{from:d,to:e} }
  if (datePreset.value==='week')       { const d=new Date(now);d.setDate(d.getDate()-7);return{from:d,to:null} }
  if (datePreset.value==='month')      { const d=new Date(now);d.setDate(d.getDate()-30);return{from:d,to:null} }
  if (datePreset.value==='this_month') { const d=new Date(now.getFullYear(),now.getMonth(),1);const e=new Date(now.getFullYear(),now.getMonth()+1,0);return{from:d,to:e} }
  if (datePreset.value==='last_month') { const d=new Date(now.getFullYear(),now.getMonth()-1,1);const e=new Date(now.getFullYear(),now.getMonth(),0);return{from:d,to:e} }
  if (datePreset.value==='this_year')  { const d=new Date(now.getFullYear(),0,1);return{from:d,to:null} }
  if (datePreset.value==='last_year')  { const d=new Date(now.getFullYear()-1,0,1);const e=new Date(now.getFullYear()-1,11,31);return{from:d,to:e} }
  if (datePreset.value==='custom'&&customDateFrom.value) { return{from:new Date(customDateFrom.value),to:customDateTo.value?new Date(customDateTo.value):null} }
  return { from:null, to:null }
}
function isoDate(d) { return d?.toISOString().slice(0,10)||null }

// displayItems: when online, server pre-filters so allItems is already the right subset.
// When offline, JS filter on the memory window (max MAX_WINDOW records — fast even offline).
const displayItems = computed(() => {
  let items = allItems.value

  if (!isOnline.value || searchQuery.value) {
    if (filters.record_status)             items = items.filter(i=>i.record_status===filters.record_status)
    if (filters.gender)                    items = items.filter(i=>i.gender===filters.gender)
    if (filters.symptoms_present!==null)   items = items.filter(i=>String(i.symptoms_present?1:0)===filters.symptoms_present)
    if (filters.referral_created!==null)   items = items.filter(i=>String(i.referral_created?1:0)===filters.referral_created)
    if (filters.sync_status)               items = items.filter(i=>i.sync_status===filters.sync_status)

    const dr = dateFromPreset()
    if (dr.from) items = items.filter(i=>{
      if(!i.captured_at)return false
      const d=new Date(i.captured_at.replace(' ','T'))
      return dr.to ? d>=dr.from&&d<=dr.to : d>=dr.from
    })
    if (tempPreset.value) items = items.filter(i=>{
      const c=i.temperature_c??(i.temperature_unit==='F'?(i.temperature_value-32)*5/9:i.temperature_value)
      return c!=null&&c>=tempPreset.value
    })
    if (showFeverOnly.value) items = items.filter(i=>{
      const c=i.temperature_c??(i.temperature_unit==='F'?(i.temperature_value-32)*5/9:i.temperature_value)
      return c!=null&&c>=37.5
    })
    const q = searchQuery.value.trim().toLowerCase()
    if (q) items = items.filter(i=>
      (i.traveler_full_name??'').toLowerCase().includes(q)||
      (i.screener_name??'').toLowerCase().includes(q)||
      (i.device_id??'').toLowerCase().includes(q)||
      (i.poe_code??'').toLowerCase().includes(q)||
      (i.client_uuid??'').toLowerCase().startsWith(q)||
      (i.gender??'').toLowerCase().includes(q)
    )
  }
  return items
})

function tabCount(v) {
  if (!v) return null
  // Use full-dataset counts, not the 300-item window
  const count = idbStatusCounts.value[v] || 0
  return count || null
}

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function toPlain(v) { return JSON.parse(JSON.stringify(toRaw(v))) }
function showToast(msg,color='success') { Object.assign(toast,{show:true,msg,color}) }
function fmtRelative(dt) {
  if(!dt)return'—';try{const m=Math.floor((Date.now()-new Date(dt.replace(' ','T')))/60000);if(m<1)return'Just now';if(m<60)return`${m}m ago`;const h=Math.floor(m/60);if(h<24)return`${h}h ago`;return`${Math.floor(h/24)}d ago`}catch{return'—'}
}
function fmtTime(dt) {
  if(!dt)return'—';try{return new Date(dt.replace(' ','T')).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'})}catch{return'—'}
}
function fmtDT(dt) {
  if(!dt)return null;try{return new Date(dt.replace(' ','T')).toLocaleString([],{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})}catch{return dt}
}
function sympClass(item) { if(item.record_status==='VOIDED')return'pr-card--voided';return item.symptoms_present?'pr-card--sym':'pr-card--asym' }
function tempChipClass(tempC,flag) { if(flag==='CRITICAL')return'pr-temp-chip--critical';if(flag==='HIGH')return'pr-temp-chip--fever';if(flag==='LOW')return'pr-temp-chip--low';return'pr-temp-chip--normal' }
function tempCardClass(tempC,flag) { if(flag==='CRITICAL')return'pr-vital--critical';if(flag==='HIGH')return'pr-vital--fever';if(flag==='LOW')return'pr-vital--low';if(tempC!==null)return'pr-vital--normal';return'' }
function genderPct(key) { if(!serverStats.value?.by_gender)return 0;const g=serverStats.value.by_gender;const total=(g.MALE||0)+(g.FEMALE||0);return total?Math.round(((g[key]||0)/total)*100):0 }
function sparkPoints(series,field) { if(!series?.length)return'';const vals=series.map(d=>d[field]||0);const max=Math.max(...vals,1);const W=210,H=60,PAD=6;return series.map((d,i)=>{const x=PAD+(i/Math.max(series.length-1,1))*(W-PAD*2);const y=H-PAD-((d[field]||0)/max)*(H-PAD*2);return`${x.toFixed(1)},${y.toFixed(1)}`}).join(' ') }
function sparkDots(series,field) { if(!series?.length)return[];const vals=series.map(d=>d[field]||0);const max=Math.max(...vals,1);const W=210,H=60,PAD=6;return series.map((d,i)=>({x:PAD+(i/Math.max(series.length-1,1))*(W-PAD*2),y:H-PAD-((d[field]||0)/max)*(H-PAD*2)})) }

// ─── IDB STATS — FULL SCAN SCOPED TO POE ─────────────────────────────────────
// BUG FIX: Previously used dbCountIndex(sync_status) which counted ALL POEs.
// Now does a full IDB scan scoped to poe_code for accurate breakdowns.
async function refreshIdbStats() {
  const poeCode = auth.value?.poe_code||''
  if (!poeCode) return
  try {
    const allPoeRecords = await dbGetByIndex(STORE.PRIMARY_SCREENINGS, 'poe_code', poeCode)
    const live = allPoeRecords.filter(r => !r.deleted_at && (r.sync_attempt_count || 0) < QUARANTINE_MAX_ATTEMPTS)

    idbTotalCount.value      = live.length
    idbUnsyncedCount.value   = live.filter(r => r.sync_status === SYNC.UNSYNCED || r.sync_status === SYNC.FAILED).length
    idbSymptomaticCount.value = live.filter(r => r.symptoms_present && r.record_status !== 'VOIDED').length
    idbReferralCount.value   = live.filter(r => r.referral_created && r.record_status !== 'VOIDED').length
    idbFeverCount.value      = live.filter(r => {
      const c = r.temperature_unit === 'F' ? (r.temperature_value - 32) * 5/9 : r.temperature_value
      return c != null && c >= 37.5
    }).length

    idbStatusCounts.value = {
      COMPLETED: live.filter(r => r.record_status === 'COMPLETED').length,
      VOIDED:    live.filter(r => r.record_status === 'VOIDED').length,
    }

    // Consistency guard: stats must never show fewer than what's displayed
    const displayCount = allItems.value.length
    if (idbTotalCount.value < displayCount) {
      idbTotalCount.value = displayCount
      idbSymptomaticCount.value = allItems.value.filter(i => i.symptoms_present && i.record_status !== 'VOIDED').length
      idbReferralCount.value = allItems.value.filter(i => i.referral_created && i.record_status !== 'VOIDED').length
      idbFeverCount.value = allItems.value.filter(i => {
        const c = i.temperature_c ?? (i.temperature_unit === 'F' ? (i.temperature_value - 32) * 5/9 : i.temperature_value)
        return c != null && c >= 37.5
      }).length
      idbUnsyncedCount.value = allItems.value.filter(i => i.sync_status === SYNC.UNSYNCED || i.sync_status === SYNC.FAILED).length
      idbStatusCounts.value = {
        COMPLETED: allItems.value.filter(i => i.record_status === 'COMPLETED').length,
        VOIDED:    allItems.value.filter(i => i.record_status === 'VOIDED').length,
      }
    }
  } catch (e) { console.warn('[PR] refreshIdbStats', e?.message) }
}

// ─── IDB PAGE READ ────────────────────────────────────────────────────────────
// Reads one IDB_PAGE_SIZE chunk. Sorts: newest captured_at first.
// For 1B records: we read the first 300 (MAX_WINDOW) on initial load,
// then serve additional pages from server when scrolling.
async function readIdbPage(offset=0) {
  const poeCode = auth.value?.poe_code||''
  if (!poeCode) return []
  try {
    const all = await dbGetByIndex(STORE.PRIMARY_SCREENINGS, 'poe_code', poeCode)
    const valid = all.filter(r => !r.deleted_at && (r.sync_attempt_count || 0) < QUARANTINE_MAX_ATTEMPTS)
    valid.sort((a,b)=>new Date(b.captured_at||0)-new Date(a.captured_at||0))
    return valid.slice(offset, offset+IDB_PAGE_SIZE).map(normaliseIdbRecord)
  } catch (e) { console.warn('[PR] readIdbPage', e?.message); return [] }
}

function normaliseIdbRecord(r) {
  const tC = r.temperature_unit==='F' ? parseFloat(((r.temperature_value-32)*5/9).toFixed(2))
           : r.temperature_value!=null ? parseFloat(r.temperature_value) : null
  return {
    id:                   r.id??r.server_id??null,
    client_uuid:          r.client_uuid,
    gender:               r.gender||null,
    traveler_full_name:   r.traveler_full_name||null,
    temperature_value:    r.temperature_value??null,
    temperature_unit:     r.temperature_unit||null,
    temperature_c:        tC,
    temperature_flag:     r.temperature_flag||tFlag(tC),
    symptoms_present:     !!r.symptoms_present,
    referral_created:     !!r.referral_created,
    captured_at:          r.captured_at,
    captured_timezone:    r.captured_timezone||null,
    poe_code:             r.poe_code,
    district_code:        r.district_code||null,
    province_code:        r.province_code||null,
    pheoc_code:           r.pheoc_code||null,
    country_code:         r.country_code||null,
    record_status:        r.record_status||'COMPLETED',
    void_reason:          r.void_reason||null,
    sync_status:          r.sync_status||SYNC.UNSYNCED,
    synced_at:            r.synced_at||null,
    sync_attempt_count:   r.sync_attempt_count||0,
    last_sync_error:      r.last_sync_error||null,
    server_received_at:   r.server_received_at||null,
    record_version:       r.record_version||1,
    reference_data_version: r.reference_data_version||null,
    device_id:            r.device_id||null,
    platform:             r.platform||null,
    app_version:          r.app_version||null,
    created_at:           r.created_at||null,
    updated_at:           r.updated_at||null,
    screener_name:        r.screener_name||null,
    screener_role:        r.screener_role||null,
    screener_username:    r.screener_username||null,
    screener_phone:       r.screener_phone||null,
    notification_id:      r.notification_id||null,
    notification_uuid:    r.notification_uuid||null,
    notification_status:  r.notification_status||null,
    notification_priority:r.notification_priority||null,
    secondary_case_id:    r.secondary_case_id||null,
    secondary_case_status:r.secondary_case_status||null,
    secondary_risk_level: r.secondary_risk_level||null,
    secondary_syndrome:   r.secondary_syndrome||null,
    secondary_disposition:r.secondary_disposition||null,
    can_void:             r.can_void??null,
    _fromCache:           true,
  }
}

function tFlag(tempC) {
  if(tempC==null)return null
  if(tempC>=38.5)return'CRITICAL'
  if(tempC>=37.5)return'HIGH'
  if(tempC<36.0) return'LOW'
  return'NORMAL'
}

// ─── QUARANTINE ENGINE ────────────────────────────────────────────────────────
// Scans IDB for records with sync_attempt_count >= QUARANTINE_MAX_ATTEMPTS.
// Quarantined records are excluded from the main list and stats.
// Primary screening does NOT purge records without traveler names — names are optional.
async function scanForDamagedRecords() {
  try {
    const poeCode = auth.value?.poe_code || ''
    if (!poeCode) return
    const failedRecords = await dbGetByIndex(STORE.PRIMARY_SCREENINGS, 'sync_status', SYNC.FAILED)
    const damaged = failedRecords.filter(r =>
      (r.sync_attempt_count || 0) >= QUARANTINE_MAX_ATTEMPTS &&
      r.poe_code === poeCode
    )
    quarantinedRecords.value = damaged.map(normaliseIdbRecord)
    if (damaged.length > 0) {
      const qUuids = new Set(damaged.map(d => d.client_uuid))
      allItems.value = allItems.value.filter(i => !qUuids.has(i.client_uuid))
    }
  } catch (e) {
    console.warn('[PR] scanForDamagedRecords error', e?.message)
  }
}

async function retryQuarantined(qr) {
  if (!isOnline.value) { showToast('Device is offline.', 'warning'); return }
  try {
    const rec = await dbGet(STORE.PRIMARY_SCREENINGS, qr.client_uuid)
    if (!rec) { showToast('Record not found.', 'warning'); return }
    await safeDbPut(STORE.PRIMARY_SCREENINGS, toPlain({
      ...rec, sync_status: SYNC.FAILED, sync_attempt_count: 0,
      last_sync_error: null, updated_at: isoNow(),
    }))
    quarantinedRecords.value = quarantinedRecords.value.filter(q => q.client_uuid !== qr.client_uuid)
    await reload()
    showToast('Record returned to sync queue.', 'success')
  } catch (e) { showToast(`Retry error: ${e?.message}`, 'danger') }
}

async function deleteQuarantined(qr) {
  try {
    await dbDelete(STORE.PRIMARY_SCREENINGS, qr.client_uuid)
    quarantinedRecords.value = quarantinedRecords.value.filter(q => q.client_uuid !== qr.client_uuid)
    await refreshIdbStats()
    showToast('Damaged record deleted.', 'success')
  } catch (e) { showToast(`Delete error: ${e?.message}`, 'danger') }
}

async function purgeAllQuarantined() {
  const count = quarantinedRecords.value.length
  for (const qr of [...quarantinedRecords.value]) { await deleteQuarantined(qr) }
  showToast(`${count} damaged record${count !== 1 ? 's' : ''} purged.`, 'success')
  showQuarantinePanel.value = false
}

// ─── SERVER FETCH ─────────────────────────────────────────────────────────────
async function fetchFromServer(pg=1, updatedAfter=null) {
  const userId=auth.value?.id
  if (!userId) return null
  const p=new URLSearchParams({user_id:userId,page:pg,per_page:SERVER_PAGE_SIZE,sort_by:'captured_at',sort_dir:'desc'})
  if (filters.record_status)            p.set('record_status',       filters.record_status)
  if (filters.gender)                   p.set('gender',              filters.gender)
  if (filters.symptoms_present!==null)  p.set('symptoms_present',    filters.symptoms_present)
  if (filters.referral_created!==null)  p.set('referral_created',    filters.referral_created)
  if (filters.sync_status)              p.set('sync_status',         filters.sync_status)
  if (tempPreset.value)                 p.set('temp_min',            String(tempPreset.value))
  if (searchQuery.value)                p.set('search',              searchQuery.value.trim())
  const dr=dateFromPreset()
  if (dr.from) p.set('date_from', isoDate(dr.from))
  if (dr.to)   p.set('date_to',   isoDate(dr.to))
  if (updatedAfter) p.set('updated_after', updatedAfter)
  const ctrl=new AbortController();const tid=setTimeout(()=>ctrl.abort(),APP.SYNC_TIMEOUT_MS)
  try {
    const res=await fetch(`${window.SERVER_URL}/primary-records?${p}`,{headers:{Accept:'application/json'},signal:ctrl.signal})
    clearTimeout(tid);if(!res.ok)return null
    const j=await res.json();return j.success?j.data:null
  } catch{clearTimeout(tid);return null}
}

// ─── WRITE-THROUGH CACHE — ALL FIELDS ────────────────────────────────────────
// Writes EVERY server field to IDB. Not just status fields — ALL columns.
// Offline users see the complete record next session.
async function writeServerItemsToIdb(serverItems) {
  for (const s of serverItems) {
    if (!s.client_uuid) continue
    try {
      const existing = await dbGet(STORE.PRIMARY_SCREENINGS, s.client_uuid)
      const incomingVer = s.record_version??1
      // Don't overwrite quarantined records — would reset sync_attempt_count
      if (existing && (existing.sync_attempt_count || 0) >= QUARANTINE_MAX_ATTEMPTS) continue
      if (!existing) {
        await dbPut(STORE.PRIMARY_SCREENINGS, toPlain({
          client_uuid:            s.client_uuid,
          id:                     s.id,   server_id: s.id,
          idempotency_key:        s.idempotency_key||null,
          reference_data_version: s.reference_data_version||APP.REFERENCE_DATA_VER,
          server_received_at:     s.server_received_at||null,
          country_code:           s.country_code||null,
          province_code:          s.province_code||null,
          pheoc_code:             s.pheoc_code||null,
          district_code:          s.district_code||null,
          poe_code:               s.poe_code,
          captured_by_user_id:    s.captured_by_user_id||null,
          gender:                 s.gender,
          traveler_full_name:     s.traveler_full_name||null,
          temperature_value:      s.temperature_value??null,
          temperature_unit:       s.temperature_unit||null,
          temperature_c:          s.temperature_c??null,
          temperature_flag:       s.temperature_flag||null,
          symptoms_present:       s.symptoms_present?1:0,
          captured_at:            s.captured_at,
          captured_timezone:      s.captured_timezone||null,
          device_id:              s.device_id||'SERVER',
          app_version:            s.app_version||null,
          platform:               s.platform||'WEB',
          referral_created:       s.referral_created?1:0,
          record_version:         incomingVer,
          record_status:          s.record_status||'COMPLETED',
          void_reason:            s.void_reason||null,
          sync_status:            SYNC.SYNCED,
          synced_at:              isoNow(),
          sync_attempt_count:     0,
          last_sync_error:        null,
          created_at:             s.created_at||isoNow(),
          updated_at:             s.updated_at||isoNow(),
          // Enriched display fields
          screener_name:          s.screener_name||null,
          screener_role:          s.screener_role||null,
          screener_username:      s.screener_username||null,
          screener_phone:         s.screener_phone||null,
          notification_id:        s.notification_id||null,
          notification_uuid:      s.notification_uuid||null,
          notification_status:    s.notification_status||null,
          notification_priority:  s.notification_priority||null,
          secondary_case_id:      s.secondary_case_id||null,
          secondary_case_status:  s.secondary_case_status||null,
          secondary_risk_level:   s.secondary_risk_level||null,
          secondary_syndrome:     s.secondary_syndrome||null,
          secondary_disposition:  s.secondary_disposition||null,
        }))
      } else if (incomingVer > (existing.record_version||0)) {
        await safeDbPut(STORE.PRIMARY_SCREENINGS, toPlain({
          ...existing,
          id:                    s.id, server_id: s.id,
          server_received_at:    s.server_received_at||existing.server_received_at,
          record_status:         s.record_status,
          void_reason:           s.void_reason||existing.void_reason,
          gender:                s.gender||existing.gender,
          traveler_full_name:    s.traveler_full_name||existing.traveler_full_name,
          temperature_value:     s.temperature_value??existing.temperature_value,
          temperature_unit:      s.temperature_unit||existing.temperature_unit,
          temperature_c:         s.temperature_c??existing.temperature_c,
          temperature_flag:      s.temperature_flag||existing.temperature_flag,
          symptoms_present:      s.symptoms_present?1:0,
          referral_created:      s.referral_created?1:0,
          record_version:        incomingVer,
          sync_status:           existing.sync_status===SYNC.UNSYNCED ? SYNC.UNSYNCED : SYNC.SYNCED,
          screener_name:         s.screener_name||existing.screener_name,
          screener_role:         s.screener_role||existing.screener_role,
          screener_username:     s.screener_username||existing.screener_username,
          screener_phone:        s.screener_phone||existing.screener_phone,
          notification_id:       s.notification_id||existing.notification_id,
          notification_uuid:     s.notification_uuid||existing.notification_uuid,
          notification_status:   s.notification_status||existing.notification_status,
          notification_priority: s.notification_priority||existing.notification_priority,
          secondary_case_id:     s.secondary_case_id||existing.secondary_case_id,
          secondary_case_status: s.secondary_case_status||existing.secondary_case_status,
          secondary_risk_level:  s.secondary_risk_level||existing.secondary_risk_level,
          secondary_syndrome:    s.secondary_syndrome||existing.secondary_syndrome,
          secondary_disposition: s.secondary_disposition||existing.secondary_disposition,
          updated_at:            s.updated_at||isoNow(),
        }))
      }
    } catch(e){ console.warn('[PR] writeServerItemsToIdb',s.client_uuid,e?.message) }
  }
}

// ─── MERGE INTO MEMORY WINDOW ─────────────────────────────────────────────────
function mergeIntoWindow(serverItems) {
  const qUuids = new Set(quarantinedRecords.value.map(q => q.client_uuid))
  const byUuid = new Map(allItems.value.map(i=>[i.client_uuid,i]))
  for (const s of serverItems) {
    if (!s.client_uuid || qUuids.has(s.client_uuid)) continue
    const existing = byUuid.get(s.client_uuid)
    const tC = s.temperature_c??(s.temperature_unit==='F'?(s.temperature_value-32)*5/9:s.temperature_value)
    byUuid.set(s.client_uuid, {
      id:s.id, client_uuid:s.client_uuid,
      gender:s.gender, traveler_full_name:s.traveler_full_name,
      temperature_value:s.temperature_value, temperature_unit:s.temperature_unit,
      temperature_c:tC!=null?parseFloat(tC.toFixed?.(2)??tC):null,
      temperature_flag:s.temperature_flag||tFlag(tC),
      symptoms_present:!!s.symptoms_present, referral_created:!!s.referral_created,
      captured_at:s.captured_at, captured_timezone:s.captured_timezone,
      poe_code:s.poe_code, district_code:s.district_code,
      province_code:s.province_code, pheoc_code:s.pheoc_code, country_code:s.country_code,
      record_status:s.record_status, void_reason:s.void_reason,
      sync_status:existing?.sync_status===SYNC.UNSYNCED?SYNC.UNSYNCED:SYNC.SYNCED,
      synced_at:existing?.synced_at||null, sync_attempt_count:existing?.sync_attempt_count||0,
      last_sync_error:existing?.last_sync_error||null,
      server_received_at:s.server_received_at, record_version:s.record_version||1,
      reference_data_version:s.reference_data_version,
      device_id:s.device_id, platform:s.platform, app_version:s.app_version,
      created_at:s.created_at, updated_at:s.updated_at,
      screener_name:s.screener_name, screener_role:s.screener_role,
      screener_username:s.screener_username, screener_phone:s.screener_phone,
      notification_id:s.notification_id, notification_uuid:s.notification_uuid,
      notification_status:s.notification_status, notification_priority:s.notification_priority,
      secondary_case_id:s.secondary_case_id, secondary_case_status:s.secondary_case_status,
      secondary_risk_level:s.secondary_risk_level, secondary_syndrome:s.secondary_syndrome,
      secondary_disposition:s.secondary_disposition, can_void:s.can_void??null,
      _fromCache:false,
    })
  }
  let sorted = Array.from(byUuid.values()).sort((a,b)=>new Date(b.captured_at||0)-new Date(a.captured_at||0))
  if (sorted.length>MAX_WINDOW) sorted = sorted.slice(0,MAX_WINDOW)
  allItems.value = sorted
}

// ─── STATS + TREND FETCH ──────────────────────────────────────────────────────
async function fetchServerStats() {
  const userId=auth.value?.id;if(!userId)return
  const p=new URLSearchParams({user_id:userId})
  const dr=dateFromPreset()
  if(dr.from)p.set('date_from',isoDate(dr.from))
  if(dr.to)  p.set('date_to',  isoDate(dr.to))
  const ctrl=new AbortController();const tid=setTimeout(()=>ctrl.abort(),APP.SYNC_TIMEOUT_MS)
  try {
    const res=await fetch(`${window.SERVER_URL}/primary-records/stats?${p}`,{headers:{Accept:'application/json'},signal:ctrl.signal})
    clearTimeout(tid);if(!res.ok)return
    const j=await res.json();if(j.success)serverStats.value=j.data
  } catch{clearTimeout(tid)}
}
async function fetchServerTrend() {
  const userId=auth.value?.id;if(!userId)return
  const ctrl=new AbortController();const tid=setTimeout(()=>ctrl.abort(),APP.SYNC_TIMEOUT_MS)
  try {
    const res=await fetch(`${window.SERVER_URL}/primary-records/trend?user_id=${userId}&days=7`,{headers:{Accept:'application/json'},signal:ctrl.signal})
    clearTimeout(tid);if(!res.ok)return
    const j=await res.json();if(j.success)trendSeries.value=j.data.series||[]
  } catch{clearTimeout(tid)}
}

// ─── LOAD LIFECYCLE ───────────────────────────────────────────────────────────
async function load() {
  loading.value=true; idbPageOffset.value=0; serverPage.value=1
  hasMoreIdb.value=true; hasMoreServer.value=true

  try {
    // Phase 1: Scan for damaged records FIRST (quarantine at 4+ failed attempts)
    await scanForDamagedRecords()

    // Phase 2: Read first IDB page (instant, works offline)
    const idbPage = await readIdbPage(0)
    if (idbPage.length>0) {
      allItems.value=idbPage; idbPageOffset.value=IDB_PAGE_SIZE
      hasMoreIdb.value=(idbPage.length===IDB_PAGE_SIZE)
      loading.value=false
    }

    // Phase 3: Compute stats from IDB (after quarantine scan, after page read)
    await refreshIdbStats()

    // Phase 4: Server page 1 (if online)
    if (isOnline.value) {
      const data = await fetchFromServer(1)
      if (data) {
        totalOnServer.value=data.total||0
        hasMoreServer.value=(data.page??1)<(data.pages??1)
        serverPage.value=2
        await writeServerItemsToIdb(data.items||[])
        mergeIntoWindow(data.items||[])
        localStorage.setItem(LAST_SYNC_KEY, isoNow())
        // Recompute stats AFTER write-through so counts are consistent
        await refreshIdbStats()
        // Stats + trend in background (non-blocking, for charts only)
        fetchServerStats().catch(()=>{})
        fetchServerTrend().catch(()=>{})
      }
    }
  } finally { loading.value=false }
}

async function loadMore() {
  if (loadingMore.value) return
  loadingMore.value=true
  try {
    if (hasMoreIdb.value) {
      const idbPage = await readIdbPage(idbPageOffset.value)
      if (idbPage.length>0) {
        const combined=[...allItems.value,...idbPage]
        const seen=new Set(); const deduped=combined.filter(i=>{if(seen.has(i.client_uuid))return false;seen.add(i.client_uuid);return true})
        deduped.sort((a,b)=>new Date(b.captured_at||0)-new Date(a.captured_at||0))
        allItems.value=deduped.slice(0,MAX_WINDOW)
        idbPageOffset.value+=IDB_PAGE_SIZE
        hasMoreIdb.value=(idbPage.length===IDB_PAGE_SIZE)
        return
      }
      hasMoreIdb.value=false
    }
    if (hasMoreServer.value && isOnline.value) {
      const data=await fetchFromServer(serverPage.value)
      if (data) {
        totalOnServer.value=data.total||0
        hasMoreServer.value=(data.page??1)<(data.pages??1)
        serverPage.value++
        writeServerItemsToIdb(data.items||[]).catch(()=>{})
        mergeIntoWindow(data.items||[])
        refreshIdbStats().catch(()=>{})
      }
    }
  } finally { loadingMore.value=false }
}

async function reload() { await load() }
async function onPullRefresh(ev) { await load(); ev.target.complete() }

// ─── BACKGROUND INCREMENTAL SYNC ─────────────────────────────────────────────
// Fires on 'online' event and auto-poll timer.
// Sends updated_after= cursor → server returns ONLY changed records since last sync.
// At 1B records: daily sync brings hundreds of new records, not billions.
async function backgroundServerSync(debounceMs=0) {
  if (!isOnline.value||syncing.value) return
  if (bgSyncDebounce) clearTimeout(bgSyncDebounce)
  bgSyncDebounce=setTimeout(async()=>{
    bgSyncDebounce=null
    try {
      const lastSync=localStorage.getItem(LAST_SYNC_KEY)||null
      const data=await fetchFromServer(1, lastSync)
      if (!data?.items?.length) return
      await writeServerItemsToIdb(data.items)
      mergeIntoWindow(data.items)
      localStorage.setItem(LAST_SYNC_KEY, isoNow())
      await scanForDamagedRecords()
      await refreshIdbStats()
      fetchServerStats().catch(()=>{})
      console.log(`[PR] Background sync: ${data.items.length} records updated`)
    } catch(e){ console.warn('[PR] backgroundServerSync',e?.message) }
  }, debounceMs)
}

// ─── DETAIL MODAL ─────────────────────────────────────────────────────────────
async function openDetail(item) {
  detailRecord.value={...item}; modalTab.value='overview'
  showVoidForm.value=false; voidReason.value=''; modalOpen.value=true
  await loadDetailFull(item)
}

async function loadDetailFull(item) {
  detailLoading.value=true
  try {
    const uuid=item.client_uuid; const sid=item.id
    // Always load full IDB record first (offline-capable)
    const full = await dbGet(STORE.PRIMARY_SCREENINGS, uuid).catch(()=>null)
    // Load linked notification from IDB
    const notifUuid = item.notification_uuid||(full?.notification_uuid)
    const notif = notifUuid ? await dbGet(STORE.NOTIFICATIONS, notifUuid).catch(()=>null) : null

    detailRecord.value = {
      ...(full||item), ...item,
      notification:    notif?toPlain(notif):null,
      secondary_case:  null, alert:null,
    }

    // Server enrich — non-blocking (UI already shows IDB data)
    if (isOnline.value && sid) {
      const userId=auth.value?.id
      const ctrl=new AbortController(); const tid=setTimeout(()=>ctrl.abort(),APP.SYNC_TIMEOUT_MS)
      fetch(`${window.SERVER_URL}/primary-records/${sid}?user_id=${userId}`,
        {headers:{Accept:'application/json'},signal:ctrl.signal})
      .then(async res=>{
        clearTimeout(tid); if(!res.ok) return
        const j=await res.json().catch(()=>null)
        if (!j?.success||!j.data||!detailRecord.value) return
        if (detailRecord.value.client_uuid!==uuid) return // modal changed
        const d=j.data
        detailRecord.value={
          ...detailRecord.value, ...d,
          // Preserve local sync fields (server may have stale view)
          sync_status:        detailRecord.value.sync_status,
          synced_at:          detailRecord.value.synced_at,
          sync_attempt_count: detailRecord.value.sync_attempt_count,
          last_sync_error:    detailRecord.value.last_sync_error,
        }
        // Write full server detail back to IDB
        if (full) {
          safeDbPut(STORE.PRIMARY_SCREENINGS, toPlain({
            ...full, ...d,
            sync_status: full.sync_status===SYNC.UNSYNCED?SYNC.UNSYNCED:SYNC.SYNCED,
            record_version: (d.record_version||1),
            updated_at: isoNow(),
          })).catch(()=>{})
        }
        // Write linked notification to IDB if server returned it
        if (d.notification?.client_uuid) {
          safeDbPut(STORE.NOTIFICATIONS, toPlain(d.notification)).catch(()=>{})
        }
      })
      .catch(()=>clearTimeout(tid))
    }
  } catch(e){ console.error('[PR] loadDetailFull',e?.message) }
  finally { detailLoading.value=false }
}

function dismissModal() { modalOpen.value=false }
function closeModal()   { detailRecord.value=null; detailLoading.value=false; showVoidForm.value=false; voidReason.value='' }

// ─── VOID ─────────────────────────────────────────────────────────────────────
async function submitVoid() {
  const reason=voidReason.value.trim()
  if (reason.length<10){ showToast('Void reason must be at least 10 characters.','warning');return }
  if (!isOnline.value)  { showToast('Device is offline. Connect to void this record.','warning');return }
  const sid=detailRecord.value?.id; const userId=auth.value?.id
  if (!sid){ showToast('Record has not synced yet. Sync before voiding.','warning');return }
  voiding.value=true
  try {
    const res=await fetch(`${window.SERVER_URL}/primary-records/${sid}/void`,{
      method:'PATCH', headers:{'Content-Type':'application/json',Accept:'application/json'},
      body:JSON.stringify({user_id:userId,void_reason:reason}),
    })
    const j=await res.json().catch(()=>({}))
    if(!res.ok){showToast(j.message||`Void failed (${res.status})`,'danger');return}
    const idbRec=await dbGet(STORE.PRIMARY_SCREENINGS, detailRecord.value.client_uuid)
    if (idbRec) {
      await safeDbPut(STORE.PRIMARY_SCREENINGS, toPlain({
        ...idbRec, record_status:'VOIDED', void_reason:reason,
        record_version:(idbRec.record_version||1)+1, updated_at:isoNow(),
      }))
    }
    const idx=allItems.value.findIndex(i=>i.client_uuid===detailRecord.value.client_uuid)
    if (idx!==-1){allItems.value[idx]={...allItems.value[idx],record_status:'VOIDED',void_reason:reason};allItems.value=[...allItems.value]}
    detailRecord.value={...detailRecord.value,record_status:'VOIDED',void_reason:reason,can_void:false}
    showVoidForm.value=false; voidReason.value=''
    showToast('Record voided successfully. Linked referral closed.','success')
  } catch(e){ showToast(`Network error: ${e?.message||'Unknown'}`, 'danger') }
  finally { voiding.value=false }
}

// ─── SYNC ENGINE ──────────────────────────────────────────────────────────────
async function syncOneRecord(item) {
  if (!isOnline.value){showToast('Device is offline.','warning');return}
  const uuid=item?.client_uuid; if(!uuid)return
  const next=new Set(syncingUuids.value);next.add(uuid);syncingUuids.value=next
  try {
    const a=auth.value; const userId=a?.id; if(!userId)throw new Error('No auth')
    const rec=await dbGet(STORE.PRIMARY_SCREENINGS,uuid)
    if(!rec)throw new Error('Record not found in IDB')
    let serverId=rec.id??rec.server_id??null
    if (!serverId) {
      const payload={
        client_uuid:rec.client_uuid, reference_data_version:rec.reference_data_version||APP.REFERENCE_DATA_VER,
        captured_by_user_id:userId, gender:rec.gender,
        symptoms_present:rec.symptoms_present?1:0, temperature_value:rec.temperature_value??null,
        temperature_unit:rec.temperature_unit||null, traveler_full_name:rec.traveler_full_name||null,
        traveler_direction:rec.traveler_direction||null,
        captured_at:rec.captured_at, captured_timezone:rec.captured_timezone||Intl.DateTimeFormat().resolvedOptions().timeZone,
        device_id:rec.device_id||'WEB', app_version:rec.app_version||null, platform:rec.platform||'WEB',
        referral_created:rec.referral_created?1:0, record_version:rec.record_version||1,
      }
      const ctrl=new AbortController();const tid=setTimeout(()=>ctrl.abort(),APP.SYNC_TIMEOUT_MS)
      let res; try{res=await fetch(`${window.SERVER_URL}/primary-screenings`,{method:'POST',signal:ctrl.signal,headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(payload)})}finally{clearTimeout(tid)}
      const body=await res.json().catch(()=>({}))
      if(!res.ok&&res.status!==200){await markFailed(uuid,rec,body?.message||`HTTP ${res.status}`);showToast(`Sync failed: ${body?.message||res.status}`,'danger');return}
      serverId=body.data?.id??body.id??null
    }
    const freshRec=await dbGet(STORE.PRIMARY_SCREENINGS,uuid)
    await safeDbPut(STORE.PRIMARY_SCREENINGS,toPlain({
      ...(freshRec||rec), id:serverId, server_id:serverId,
      sync_status:SYNC.SYNCED, synced_at:isoNow(), last_sync_error:null,
      record_version:((freshRec||rec).record_version||1)+1, updated_at:isoNow(),
    }))
    const idx=allItems.value.findIndex(i=>i.client_uuid===uuid)
    if(idx!==-1){allItems.value[idx]={...allItems.value[idx],sync_status:SYNC.SYNCED,id:serverId,last_sync_error:null};allItems.value=[...allItems.value]}
    if(detailRecord.value?.client_uuid===uuid)detailRecord.value={...detailRecord.value,sync_status:SYNC.SYNCED,id:serverId,synced_at:isoNow(),last_sync_error:null}
    await refreshIdbStats()
    showToast('Record synced successfully.','success')
  } catch(e){
    const rec=await dbGet(STORE.PRIMARY_SCREENINGS,uuid).catch(()=>null)
    if(rec)await markFailed(uuid,rec,e?.message||'Unknown')
    showToast(`Sync error: ${e?.message||'Unknown'}`,'danger')
  } finally{const n2=new Set(syncingUuids.value);n2.delete(uuid);syncingUuids.value=n2}
}

async function markFailed(uuid,rec,msg) {
  const newAttemptCount = (rec.sync_attempt_count||0)+1
  await safeDbPut(STORE.PRIMARY_SCREENINGS,toPlain({...rec,sync_status:SYNC.FAILED,last_sync_error:msg,sync_attempt_count:newAttemptCount,updated_at:isoNow()}))
  const idx=allItems.value.findIndex(i=>i.client_uuid===uuid)
  if(idx!==-1){allItems.value[idx]={...allItems.value[idx],sync_status:SYNC.FAILED,last_sync_error:msg,sync_attempt_count:newAttemptCount};allItems.value=[...allItems.value]}
  if(detailRecord.value?.client_uuid===uuid)detailRecord.value={...detailRecord.value,sync_status:SYNC.FAILED,last_sync_error:msg,sync_attempt_count:newAttemptCount}
  await refreshIdbStats()
  // Check if this record should now be quarantined
  await scanForDamagedRecords()
}

async function syncAllPending() {
  if(!isOnline.value){showToast('Device is offline.','warning');return}
  const pending=allItems.value.filter(i=>i.sync_status!==SYNC.SYNCED)
  if(!pending.length){showToast('All records are already synced.','success');return}
  syncing.value=true; let ok=0,fail=0
  for(const item of pending){
    await syncOneRecord(item)
    const u=allItems.value.find(i=>i.client_uuid===item.client_uuid)
    if(u?.sync_status===SYNC.SYNCED)ok++;else fail++
  }
  syncing.value=false
  showToast(`Sync complete — ${ok} synced${fail?`, ${fail} failed`:''}`,fail?'warning':'success')
}

// ─── FILTER ACTIONS ───────────────────────────────────────────────────────────
function setStatusFilter(v) { filters.record_status=v; filtersOpen.value=false; reload() }
function quickFilter(key,val) { filters[key]=filters[key]===val?null:val; filtersOpen.value=false; reload() }
function toggleFilter(key,val) { filters[key]=filters[key]===val?null:val }

function clearAllFilters() {
  Object.assign(filters,{record_status:null,gender:null,symptoms_present:null,referral_created:null,sync_status:null})
  datePreset.value='all'; tempPreset.value=null; showFeverOnly.value=false
  filtersOpen.value=false; searchQuery.value=''; reload()
}

// Debounced search and filter watches — avoid hammering server on every keystroke/toggle
watch(searchQuery,()=>{
  clearTimeout(searchDebounce)
  searchDebounce=setTimeout(reload, 350)
})
watch([()=>filters.gender,()=>filters.symptoms_present,()=>filters.referral_created,
  ()=>filters.sync_status,datePreset,tempPreset,showFeverOnly],
  ()=>{ if(!loading.value) reload() })

// ─── CONNECTIVITY + LIFECYCLE ─────────────────────────────────────────────────
function onOnline() {
  isOnline.value=true
  // Incremental sync on reconnect (debounced 500ms for flappy wifi)
  backgroundServerSync(500)
}
function onOffline() { isOnline.value=false }

onMounted(()=>{
  auth.value=getAuth()
  window.addEventListener('online',  onOnline)
  window.addEventListener('offline', onOffline)
  load().then(() => tryDeepLinkOpen())
  // Background poll: incremental sync every 60s. NOT a full reload.
  autoRefreshTimer=setInterval(()=>{
    if(isOnline.value&&!loading.value&&!syncing.value) backgroundServerSync()
  }, POLL_INTERVAL_MS)
})

onIonViewWillEnter(()=>{
  auth.value=getAuth()
  reload()
  if(isOnline.value) backgroundServerSync(200)
  tryDeepLinkOpen()
})

onUnmounted(()=>{
  window.removeEventListener('online',  onOnline)
  window.removeEventListener('offline', onOffline)
  clearInterval(autoRefreshTimer)
  if(bgSyncDebounce)clearTimeout(bgSyncDebounce)
  if(searchDebounce)clearTimeout(searchDebounce)
})
</script>


<style scoped>
/* ═══════════════════════════════════════════════════════════════════════
   PRIMARY SCREENING RECORDS · Namespace: pr-*
   Clinical precision — navy command header, status-coded card stripes.
   Light theme only. No dark mode. No prefers-color-scheme.
═══════════════════════════════════════════════════════════════════════ */

/* ── HEADER ──────────────────────────────────────────────────────────── */
.pr-toolbar { --background:#1A3A5C; --color:#fff; --padding-start:8px; --padding-end:8px; --min-height:50px; }
.pr-menu-btn { --color:rgba(255,255,255,.8); }
.pr-title-block { display:flex; flex-direction:column; margin-left:4px; }
.pr-eyebrow { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:1.4px; color:rgba(255,255,255,.5); line-height:1; }
.pr-title   { font-size:17px; font-weight:800; color:#fff; line-height:1.2; }

/* Connectivity pill */
.pr-conn-pill { display:flex; align-items:center; gap:5px; padding:3px 8px; border-radius:9999px; font-size:9px; font-weight:700; border:1px solid rgba(255,255,255,.2); margin-right:4px; }
.pr-conn--online  { background:rgba(40,167,69,.25); color:#90EE90; }
.pr-conn--offline { background:rgba(158,158,158,.25); color:rgba(255,255,255,.6); }
.pr-conn-dot { width:6px; height:6px; border-radius:50%; background:currentColor; }
.pr-conn--online .pr-conn-dot { animation:pr-pulse 1.8s ease-in-out infinite; }

/* Sync pill */
.pr-sync-pill { display:inline-flex; align-items:center; gap:5px; padding:4px 9px; border-radius:9999px; font-size:10px; font-weight:700; border:1px solid rgba(255,255,255,.2); margin-right:4px; cursor:pointer; transition:background .15s; }
.pr-sync-pill--ok      { background:rgba(40,167,69,.25); color:#90EE90; }
.pr-sync-pill--pending { background:rgba(255,152,0,.3); color:#FFD740; }
.pr-sync-pill--syncing { background:rgba(33,150,243,.3); color:#90CAF9; animation:pr-pulse 1.2s ease-in-out infinite; }
.pr-sync-pill--offline { background:rgba(158,158,158,.2); color:rgba(255,255,255,.6); }
.pr-sync-pill:disabled { opacity:.6; cursor:not-allowed; }
.pr-refresh-btn { --color:rgba(255,255,255,.8); }
@keyframes pr-pulse { 0%,100%{opacity:1} 50%{opacity:.45} }

/* ── STATS BAR ───────────────────────────────────────────────────────── */
.pr-stats-bar { display:flex; align-items:stretch; background:#12294A; }
.pr-stat { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:8px 2px; gap:2px; border:none; background:transparent; cursor:pointer; transition:background .12s; }
.pr-stat:active, .pr-stat--on { background:rgba(255,255,255,.15); }
.pr-stat-num  { font-size:18px; font-weight:900; line-height:1; color:#fff; }
.pr-stat-lbl  { font-size:8px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:rgba(255,255,255,.5); text-align:center; }
.pr-stat--sym .pr-stat-num  { color:#FF6B6B; }
.pr-stat--ref .pr-stat-num  { color:#FFD93D; }
.pr-stat--fever .pr-stat-num { color:#FFA726; }
.pr-stat--unsynced .pr-stat-num { color:#63B3ED; }
.pr-stat--quarantine .pr-stat-num { color:#CE93D8; }
.pr-stat-div { width:1px; height:28px; background:rgba(255,255,255,.12); margin:auto 0; }

/* ── QUARANTINE BANNER ───────────────────────────────────────────────── */
.pr-quarantine-banner { background:linear-gradient(135deg, #4A1942, #2D1B3D); padding:12px; border-bottom:2px solid #CE93D8; }
.pr-qb-header { margin-bottom:8px; }
.pr-qb-title { display:block; font-size:13px; font-weight:800; color:#E1BEE7; }
.pr-qb-sub   { display:block; font-size:10px; color:rgba(225,190,231,.6); margin-top:2px; }
.pr-qb-list  { display:flex; flex-direction:column; gap:6px; margin-bottom:10px; }
.pr-qb-item  { display:flex; align-items:center; gap:8px; padding:8px 10px; background:rgba(255,255,255,.06); border-radius:8px; border:1px solid rgba(206,147,216,.2); }
.pr-qb-info  { flex:1; min-width:0; }
.pr-qb-name  { display:block; font-size:12px; font-weight:700; color:#E1BEE7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pr-qb-err   { display:block; font-size:10px; color:#EF9A9A; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pr-qb-retry, .pr-qb-delete { width:28px; height:28px; border-radius:6px; border:1px solid rgba(206,147,216,.3); background:rgba(255,255,255,.08); display:flex; align-items:center; justify-content:center; cursor:pointer; font-size:14px; flex-shrink:0; }
.pr-qb-retry { color:#81C784; }
.pr-qb-delete { color:#EF9A9A; }
.pr-qb-retry:disabled, .pr-qb-delete:disabled { opacity:.4; cursor:not-allowed; }
.pr-qb-purge { width:100%; padding:8px; border-radius:6px; border:1.5px solid #EF9A9A; background:transparent; color:#EF9A9A; font-size:11px; font-weight:700; cursor:pointer; }

/* ── CONTROLS ─────────────────────────────────────────────────────────── */
.pr-controls { background:#fff; border-bottom:1.5px solid #E8EDF5; }
.pr-search-row { display:flex; align-items:center; gap:6px; padding:7px 10px 0; }
.pr-search-box { flex:1; display:flex; align-items:center; gap:6px; padding:0 10px; background:#F5F7FA; border:1.5px solid #DDE3EA; border-radius:8px; }
.pr-search-icon { width:14px; height:14px; color:#90A4AE; flex-shrink:0; }
.pr-search-input { flex:1; border:none; outline:none; background:transparent; font-size:13px; color:#263238; padding:8px 0; }
.pr-search-input::placeholder { color:#B0BEC5; }
.pr-search-clear { border:none; background:none; cursor:pointer; color:#90A4AE; padding:4px; display:flex; align-items:center; }
.pr-filter-btn { position:relative; width:40px; height:40px; border-radius:8px; border:1.5px solid #DDE3EA; background:#F5F7FA; cursor:pointer; display:flex; align-items:center; justify-content:center; flex-shrink:0; color:#546E7A; transition:background .12s; }
.pr-filter-btn--on { background:#E3F2FD; border-color:#1A3A5C; color:#1A3A5C; }
.pr-filter-count { position:absolute; top:-5px; right:-5px; background:#DC3545; color:#fff; font-size:8px; font-weight:800; width:16px; height:16px; border-radius:50%; display:flex; align-items:center; justify-content:center; }

/* Status tabs */
.pr-tabs { display:flex; overflow-x:auto; scrollbar-width:none; padding:0 8px; gap:4px; }
.pr-tabs::-webkit-scrollbar { display:none; }
.pr-tab { display:flex; align-items:center; gap:4px; padding:8px 10px; border:none; background:transparent; border-bottom:2.5px solid transparent; font-size:11.5px; font-weight:600; color:var(--ion-color-medium); white-space:nowrap; cursor:pointer; flex-shrink:0; transition:color .12s,border-color .12s; }
.pr-tab--on { color:#1A3A5C; border-bottom-color:#1A3A5C; }
.pr-tab-badge { display:inline-flex; align-items:center; justify-content:center; min-width:16px; height:16px; padding:0 4px; border-radius:9999px; font-size:9px; font-weight:800; }
.pr-tb--ok   { background:#E8F5E9; color:#2E7D32; }
.pr-tb--void { background:#FFEBEE; color:#C62828; }
.pr-tb--all  { background:#E3F2FD; color:#1565C0; }

/* Filter panel */
.pr-filter-panel { padding:10px 12px; border-top:1px solid #EEF1F5; background:#FAFBFD; }
.pr-fp-row { display:flex; align-items:flex-start; gap:8px; margin-bottom:10px; }
.pr-fp-lbl { font-size:10px; font-weight:700; text-transform:uppercase; color:#546E7A; white-space:nowrap; min-width:60px; padding-top:4px; }
.pr-fp-pills { display:flex; flex-wrap:wrap; gap:4px; flex:1; }
.pr-pill { padding:4px 10px; border-radius:9999px; font-size:10px; font-weight:700; border:1.5px solid #DDE3EA; background:#F5F7FA; color:#546E7A; cursor:pointer; white-space:nowrap; transition:all .1s; }
.pr-pill--on { background:#1A3A5C; color:#fff; border-color:#1A3A5C; }
.pr-pill--sym.pr-pill--on { background:#C62828; border-color:#C62828; }
.pr-pill--ok.pr-pill--on  { background:#2E7D32; border-color:#2E7D32; }
.pr-pill--ref.pr-pill--on { background:#E65100; border-color:#E65100; }
.pr-pill--fever.pr-pill--on { background:#F57F17; border-color:#F57F17; }
.pr-pill--date.pr-pill--on  { background:#1565C0; border-color:#1565C0; }
.pr-pill--unsynced.pr-pill--on { background:#E65100; border-color:#E65100; }
.pr-pill--synced.pr-pill--on   { background:#2E7D32; border-color:#2E7D32; }
.pr-pill--failed.pr-pill--on   { background:#C62828; border-color:#C62828; }
.pr-clear-all { width:100%; margin-top:4px; padding:7px; border-radius:6px; border:1.5px solid #DC3545; background:transparent; color:#DC3545; font-size:11px; font-weight:700; cursor:pointer; }

/* Chip row */
.pr-chip-row { display:flex; align-items:center; gap:6px; padding:4px 12px 8px; flex-wrap:wrap; }
.pr-chip { display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:12px; font-size:10px; font-weight:700; text-transform:uppercase; }
.pr-chip--gender  { background:#E3F2FD; color:#1565C0; }
.pr-chip--sym     { background:#FFEBEE; color:#C62828; }
.pr-chip--ok      { background:#E8F5E9; color:#2E7D32; text-transform:none; }
.pr-chip--ref     { background:#FFF3E0; color:#E65100; }
.pr-chip--fever   { background:#FFF8E1; color:#F57F17; text-transform:none; }
.pr-chip--date    { background:#EDE7F6; color:#4527A0; }
.pr-chip--unsynced { background:#FFF3E0; color:#E65100; text-transform:none; }
.pr-chip--synced  { background:#E8F5E9; color:#2E7D32; text-transform:none; }
.pr-chip--failed  { background:#FFEBEE; color:#C62828; text-transform:none; }
.pr-chip-x { border:none; background:none; cursor:pointer; font-size:14px; line-height:1; padding:0 2px; opacity:.7; }
.pr-chip-count { font-size:10px; color:#607D8B; margin-left:auto; }

/* Slide transition */
.pr-slide-enter-active, .pr-slide-leave-active { transition:max-height .2s ease,opacity .2s ease; overflow:hidden; }
.pr-slide-enter-from, .pr-slide-leave-to { max-height:0; opacity:0; }
.pr-slide-enter-to, .pr-slide-leave-from { max-height:500px; opacity:1; }

/* Bulk sync bar */
.pr-bulk-bar { display:flex; align-items:center; gap:8px; padding:7px 12px; background:#FFF3E0; border-top:1px solid #FFB74D; font-size:12px; color:#BF360C; }
.pr-bulk-bar ion-icon { font-size:16px; color:#E65100; }
.pr-bulk-btn { margin-left:auto; padding:5px 12px; border-radius:6px; border:1.5px solid #E65100; background:#E65100; color:#fff; font-size:11px; font-weight:700; cursor:pointer; }

/* ── CONTENT ──────────────────────────────────────────────────────────── */
.pr-content { --background:#EDF1F7; }
.pr-loading  { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:12px; padding:60px 20px; color:#607D8B; font-size:14px; }
.pr-spinner  { color:#1A3A5C; --color:#1A3A5C; }
.pr-offline-bar { display:flex; align-items:center; gap:8px; padding:9px 14px; background:#FFF8E1; border-bottom:1px solid #FFD54F; font-size:12px; color:#795548; }
.pr-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:60px 24px; text-align:center; gap:8px; }
.pr-empty-icon  { font-size:44px; color:#B0BEC5; }
.pr-empty-title { font-size:18px; font-weight:700; color:#263238; margin:0; }
.pr-empty-sub   { font-size:13px; color:#607D8B; margin:0; max-width:260px; }
.pr-list { padding:10px 12px 0; }
.pr-load-more { padding:8px 0 4px; }

/* ── RECORD CARD ──────────────────────────────────────────────────────── */
.pr-card { display:flex; background:#fff; border-radius:12px; margin-bottom:10px; overflow:hidden; box-shadow:0 1px 4px rgba(0,0,0,.07),0 0 0 1px rgba(0,0,0,.04); cursor:pointer; transition:transform .1s,box-shadow .1s; }
.pr-card:active { transform:scale(.99); box-shadow:0 0 0 1px rgba(0,0,0,.06); }
.pr-card-stripe  { width:4px; flex-shrink:0; }
.pr-card--sym    .pr-card-stripe { background:#DC3545; }
.pr-card--asym   .pr-card-stripe { background:#28A745; }
.pr-card--voided .pr-card-stripe { background:#FFC107; }
.pr-card--sym    { box-shadow:0 1px 4px rgba(220,53,69,.15),0 0 0 1.5px rgba(220,53,69,.2); }
.pr-card--unsynced { box-shadow:0 1px 4px rgba(255,152,0,.2),0 0 0 1.5px rgba(255,152,0,.3); }
.pr-card--voided { background:#FAFAFA; opacity:.85; }
.pr-card-body { flex:1; padding:11px 12px; display:flex; flex-direction:column; gap:7px; min-width:0; }

/* Card rows */
.pr-card-top { display:flex; align-items:center; gap:5px; flex-wrap:wrap; }
.pr-symp-badge { display:inline-flex; padding:2px 8px; border-radius:9999px; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.4px; border:1px solid; }
.pr-symp-badge--yes { background:#FFEBEE; color:#C62828; border-color:rgba(220,53,69,.3); }
.pr-symp-badge--no  { background:#E8F5E9; color:#2E7D32; border-color:rgba(46,125,50,.3); }
.pr-voided-badge { display:inline-flex; padding:2px 7px; border-radius:9999px; font-size:9px; font-weight:800; background:#FFF3E0; color:#E65100; border:1px solid rgba(230,101,0,.3); }
.pr-ref-badge    { display:inline-flex; padding:2px 7px; border-radius:9999px; font-size:9px; font-weight:800; background:#E3F2FD; color:#1565C0; border:1px solid rgba(21,101,192,.3); }
.pr-sync-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.pr-sync-dot--synced   { background:#4CAF50; }
.pr-sync-dot--unsynced { background:#FF9800; animation:pr-pulse 2s ease-in-out infinite; }
.pr-sync-dot--failed   { background:#F44336; }
.pr-card-time { margin-left:auto; font-size:10px; color:#90A4AE; white-space:nowrap; }

/* Traveler row */
.pr-card-traveler { display:flex; align-items:center; gap:9px; }
.pr-gender-avatar { width:34px; height:34px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:16px; flex-shrink:0; border:1.5px solid; }
.pr-gender-avatar--male    { background:#E3F2FD; border-color:rgba(21,101,192,.2); color:#1565C0; }
.pr-gender-avatar--female  { background:#FCE4EC; border-color:rgba(194,24,91,.2); color:#C2185B; }
.pr-gender-avatar--other   { background:#F3E5F5; border-color:rgba(106,27,154,.2); color:#6A1B9A; }
.pr-gender-avatar--unknown { background:#F5F5F5; border-color:#DDD; color:#757575; }
.pr-traveler-info { flex:1; min-width:0; }
.pr-traveler-name { display:block; font-size:13px; font-weight:700; color:#212121; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.pr-traveler-sub  { display:block; font-size:11px; color:#607D8B; margin-top:1px; }
.pr-temp-chip { padding:3px 8px; border-radius:6px; font-size:11px; font-weight:800; flex-shrink:0; border:1px solid; }
.pr-temp-chip--critical { background:#FFEBEE; color:#C62828; border-color:rgba(220,53,69,.3); }
.pr-temp-chip--fever    { background:#FFF3E0; color:#E65100; border-color:rgba(230,101,0,.3); }
.pr-temp-chip--low      { background:#E3F2FD; color:#1565C0; border-color:rgba(21,101,192,.3); }
.pr-temp-chip--normal   { background:#E8F5E9; color:#2E7D32; border-color:rgba(46,125,50,.2); }
.pr-temp-chip--none     { background:#F5F5F5; color:#B0BEC5; border-color:#DDD; }

/* Chain row */
.pr-card-chain { display:flex; flex-wrap:wrap; gap:4px; }
.pr-chain-badge { padding:2px 7px; border-radius:4px; font-size:9px; font-weight:700; }
.pr-chain-badge--notif-open        { background:#FFEBEE; color:#C62828; }
.pr-chain-badge--notif-in-progress { background:#FFF3E0; color:#E65100; }
.pr-chain-badge--notif-closed      { background:#E8F5E9; color:#2E7D32; }
.pr-chain-badge--sec-open          { background:#EEF2FF; color:#3949AB; }
.pr-chain-badge--sec-in-progress   { background:#FFF3E0; color:#E65100; }
.pr-chain-badge--sec-dispositioned { background:#E8EAF6; color:#283593; }
.pr-chain-badge--sec-closed        { background:#E8F5E9; color:#2E7D32; }
.pr-chain-badge--risk-critical      { background:#FFEBEE; color:#C62828; }
.pr-chain-badge--risk-high          { background:#FFF3E0; color:#E65100; }
.pr-chain-badge--risk-medium        { background:#FFF8E1; color:#F57F17; }
.pr-chain-badge--risk-low           { background:#E8F5E9; color:#2E7D32; }

/* Card footer */
.pr-card-footer { display:flex; align-items:center; gap:8px; padding-top:5px; border-top:1px solid #F0F4FA; flex-wrap:wrap; }
.pr-card-meta   { font-size:10px; color:#90A4AE; }
.pr-card-sync-btn { display:inline-flex; align-items:center; gap:4px; padding:4px 9px; border-radius:6px; font-size:10px; font-weight:700; cursor:pointer; margin-left:auto; border:1.5px solid; transition:background .1s; }
.pr-card-sync-btn--unsynced { background:#FFF3E0; color:#E65100; border-color:rgba(230,101,0,.3); }
.pr-card-sync-btn--failed   { background:#FFEBEE; color:#C62828; border-color:rgba(220,53,69,.3); }
.pr-card-sync-btn:disabled  { opacity:.5; cursor:not-allowed; }
.pr-card-synced { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; color:#2E7D32; margin-left:auto; }

/* ── MODAL ─────────────────────────────────────────────────────────────── */
.pr-modal-header  { background:#fff; }
.pr-modal-toolbar { --background:#1A3A5C; --color:#fff; --min-height:50px; }
.pr-modal-close   { --color:rgba(255,255,255,.8); }
.pr-modal-title-block { display:flex; flex-direction:column; }
.pr-modal-eyebrow { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,.5); }
.pr-modal-title   { font-size:15px; font-weight:800; color:#fff; }
.pr-modal-sync-btn { display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border-radius:6px; font-size:11px; font-weight:700; cursor:pointer; border:1.5px solid rgba(255,152,0,.6); background:rgba(255,152,0,.2); color:#FFD740; margin-right:6px; }
.pr-modal-sync-btn:disabled { opacity:.5; cursor:not-allowed; }
.pr-modal-synced  { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; color:#90EE90; margin-right:8px; }

.pr-modal-status-toolbar { --background:#F8FAFC; --border-width:0 0 1px 0; --border-color:#E8EDF5; --min-height:0; --padding-start:0; --padding-end:0; --padding-top:0; --padding-bottom:0; contain:content; }
.pr-modal-status-bar { display:flex; align-items:center; gap:6px; padding:8px 14px; flex-wrap:wrap; }
.pr-modal-tabs-toolbar { --background:#fff; --border-width:0 0 1.5px 0; --border-color:#E8EDF5; --min-height:0; --padding-start:0; --padding-end:0; --padding-top:0; --padding-bottom:0; contain:content; }
.pr-modal-tabs { display:flex; overflow-x:auto; scrollbar-width:none; padding:0 10px; }
.pr-modal-tabs::-webkit-scrollbar { display:none; }
.pr-modal-tab { padding:9px 12px; border:none; background:transparent; border-bottom:2.5px solid transparent; font-size:11.5px; font-weight:600; color:var(--ion-color-medium); cursor:pointer; white-space:nowrap; flex-shrink:0; transition:color .12s,border-color .12s; }
.pr-modal-tab--on { color:#1A3A5C; border-bottom-color:#1A3A5C; }

/* Sync status badges */
.pr-sync-status-badge { padding:2px 7px; border-radius:9999px; font-size:9px; font-weight:800; border:1px solid; }
.pr-sync-status-badge--synced   { background:#E8F5E9; color:#2E7D32; border-color:rgba(46,125,50,.3); }
.pr-sync-status-badge--unsynced { background:#FFF3E0; color:#E65100; border-color:rgba(230,101,0,.3); }
.pr-sync-status-badge--failed   { background:#FFEBEE; color:#C62828; border-color:rgba(220,53,69,.3); }
.pr-sync-text--synced   { color:#2E7D32; font-weight:700; }
.pr-sync-text--unsynced { color:#E65100; font-weight:700; }
.pr-sync-text--failed   { color:#C62828; font-weight:700; }

/* Modal scroll area */
.pr-modal { }
.pr-modal::part(content) { display:flex; flex-direction:column; overflow:hidden; height:100%; }
.pr-modal-scroll { flex:1; min-height:0; overflow-y:auto; -webkit-overflow-scrolling:touch; background:#F5F7FA; padding-bottom:max(120px, env(safe-area-inset-bottom, 120px)); }
.pr-modal-body   { padding:0 0 8px; }
.pr-detail-loading { display:flex; align-items:center; gap:10px; padding:24px; color:#607D8B; font-size:13px; }

/* Sync card */
.pr-sync-card { margin:12px; border-radius:10px; overflow:hidden; border:1.5px solid; }
.pr-sync-card--synced   { border-color:rgba(46,125,50,.3); background:#F1F8E9; }
.pr-sync-card--unsynced { border-color:rgba(255,152,0,.4); background:#FFF8E1; }
.pr-sync-card--failed   { border-color:rgba(220,53,69,.4); background:#FFEBEE; }
.pr-sync-card-hdr { display:flex; align-items:center; gap:8px; padding:10px 14px; border-bottom:1px solid rgba(0,0,0,.06); }
.pr-sc-icon  { font-size:18px; }
.pr-sync-card--synced   .pr-sc-icon { color:#2E7D32; }
.pr-sync-card--unsynced .pr-sc-icon { color:#E65100; }
.pr-sync-card--failed   .pr-sc-icon { color:#C62828; }
.pr-sc-title { font-size:13px; font-weight:700; color:#263238; }
.pr-sync-grid { padding:8px 14px; }
.pr-sc-action { width:calc(100% - 28px); margin:6px 14px 10px; padding:10px; border-radius:7px; border:none; background:#1A3A5C; color:#fff; font-size:12px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; }
.pr-sc-action:disabled { opacity:.6; cursor:not-allowed; }
.pr-sc-offline { padding:8px 14px 10px; font-size:11px; color:#607D8B; text-align:center; }

/* Section headers */
.pr-section-hdr { display:flex; align-items:center; gap:7px; padding:14px 14px 6px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.8px; color:#546E7A; }
.pr-sec-n { width:18px; height:18px; border-radius:50%; background:#1A3A5C; color:#fff; font-size:8px; font-weight:900; display:flex; align-items:center; justify-content:center; flex-shrink:0; }

/* KV grid */
.pr-kv-grid { display:grid; grid-template-columns:1fr 1fr; gap:1px; margin:0 12px 4px; background:#E8EDF5; border-radius:8px; overflow:hidden; }
.pr-kv { display:flex; flex-direction:column; gap:2px; padding:9px 12px; background:#fff; }
.pr-kv--full { grid-column:1/-1; }
.pr-k { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#90A4AE; }
.pr-v { font-size:12px; color:#212121; font-weight:500; word-break:break-word; }
.pr-v--ok     { color:#2E7D32; font-weight:700; }
.pr-v--danger { color:#C62828; font-weight:700; }
.pr-v--warn   { color:#E65100; font-weight:700; }
.pr-v--error  { color:#C62828; font-style:italic; }
.pr-uuid { font-family:monospace; font-size:10px; }

/* Timeline */
.pr-timeline { margin:0 12px 4px; background:#fff; border-radius:8px; border:1px solid #E8EDF5; overflow:hidden; }
.pr-tl-row   { display:flex; align-items:flex-start; gap:12px; padding:10px 14px; border-bottom:1px solid #F0F4FA; }
.pr-tl-row:last-child { border-bottom:none; }
.pr-tl-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; margin-top:3px; }
.pr-tl-dot--captured { background:#1A3A5C; }
.pr-tl-dot--synced   { background:#2E7D32; }
.pr-tl-dot--voided   { background:#E65100; }
.pr-tl-label { display:block; font-size:12px; font-weight:700; color:#212121; }
.pr-tl-val   { display:block; font-size:11px; color:#1A3A5C; }
.pr-tl-sub   { display:block; font-size:10px; color:#607D8B; }

/* Chain card */
.pr-chain-card { margin:0 12px 4px; background:#fff; border-radius:8px; border:1px solid #E8EDF5; overflow:hidden; }
.pr-chain-row  { padding:10px 14px; border-bottom:1px solid #F0F4FA; }
.pr-chain-row:last-child { border-bottom:none; }
.pr-chain-row--alert { background:#FFF8E1; }
.pr-chain-type { display:block; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.5px; color:#1A3A5C; margin-bottom:6px; }

/* Vitals */
.pr-vitals-row { display:flex; gap:8px; margin:0 12px 4px; }
.pr-vital-card { flex:1; display:flex; flex-direction:column; align-items:center; padding:12px 8px; border-radius:8px; background:#fff; border:1.5px solid #E8EDF5; gap:3px; }
.pr-vital--critical { border-color:rgba(220,53,69,.4); background:#FFEBEE; }
.pr-vital--fever    { border-color:rgba(230,101,0,.4); background:#FFF3E0; }
.pr-vital--low      { border-color:rgba(21,101,192,.3); background:#E3F2FD; }
.pr-vital--normal   { border-color:rgba(46,125,50,.3); background:#F1F8E9; }
.pr-vital-val  { font-size:20px; font-weight:900; color:#212121; }
.pr-vital-lbl  { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#607D8B; }
.pr-vital-flag { font-size:10px; font-weight:700; }
.pr-vital-sub  { font-size:9px; color:#90A4AE; }

/* Risk text */
.pr-risk-critical { color:#C62828; font-weight:700; }
.pr-risk-high     { color:#E65100; font-weight:700; }
.pr-risk-medium   { color:#F57F17; font-weight:700; }
.pr-risk-low      { color:#2E7D32; }

/* Void section */
.pr-void-section { margin:8px 12px; }
.pr-void-warning { display:flex; align-items:flex-start; gap:8px; padding:10px 12px; background:#FFF8E1; border-radius:8px; border:1px solid #FFB74D; font-size:12px; color:#7F4F00; margin-bottom:8px; }
.pr-void-icon    { font-size:16px; color:#E65100; flex-shrink:0; margin-top:1px; }
.pr-void-btn     { width:100%; padding:10px; border-radius:8px; border:1.5px solid rgba(220,53,69,.4); background:#FFEBEE; color:#C62828; font-size:12px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; }
.pr-void-form    { background:#fff; border-radius:8px; border:1.5px solid #E8EDF5; overflow:hidden; }
.pr-void-textarea { width:100%; border:none; outline:none; padding:12px; font-size:13px; color:#263238; resize:vertical; box-sizing:border-box; }
.pr-void-actions { display:flex; gap:8px; padding:8px 12px 12px; }
.pr-void-cancel  { flex:1; padding:8px; border-radius:6px; border:1.5px solid #DDE3EA; background:#F5F7FA; color:#546E7A; font-size:12px; font-weight:700; cursor:pointer; }
.pr-void-confirm { flex:1; padding:8px; border-radius:6px; border:none; background:#C62828; color:#fff; font-size:12px; font-weight:700; cursor:pointer; }
.pr-void-confirm:disabled { opacity:.5; cursor:not-allowed; }

/* Responsive */
@media (min-width:600px) {
  .pr-list { max-width:720px; margin:0 auto; }
  .pr-kv-grid { grid-template-columns:1fr 1fr 1fr; }
}

/* ── CHARTS TOGGLE BAR ───────────────────────────────────────────────── */
.pr-charts-toggle { display:flex; align-items:center; justify-content:space-between; padding:7px 14px; background:#EEF3FA; border-top:1px solid #DDE5F0; cursor:pointer; }
.pr-charts-toggle:active { background:#E0EAF7; }
.pr-charts-toggle-lbl { display:flex; align-items:center; gap:6px; font-size:11px; font-weight:700; color:#1A3A5C; }
.pr-charts-toggle-lbl ion-icon { font-size:14px; }
.pr-charts-meta { font-size:10px; color:#607D8B; }

/* ── ANALYTICS PANEL ─────────────────────────────────────────────────── */
.pr-analytics-panel { background:#EDF1F7; padding:10px 10px 4px; border-bottom:1.5px solid #DDE5F0; }

/* KPI tiles */
.pr-kpi-row { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; margin-bottom:8px; }
.pr-kpi { background:#fff; border-radius:10px; padding:10px 10px 8px; display:flex; flex-direction:column; gap:3px; border:1px solid #E8EDF5; }
.pr-kpi-num { font-size:22px; font-weight:900; color:#1A3A5C; line-height:1; }
.pr-kpi-unit { font-size:12px; font-weight:600; }
.pr-kpi-lbl  { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#90A4AE; }
.pr-kpi-bar-wrap { height:4px; background:#EEF1F7; border-radius:2px; overflow:hidden; margin-top:2px; }
.pr-kpi-bar { height:100%; border-radius:2px; transition:width .4s ease; }
.pr-kpi-bar--sym { background:#DC3545; }
.pr-kpi-bar--ref { background:#1565C0; }
.pr-kpi-delta { font-size:9px; font-weight:700; margin-top:1px; }
.pr-kpi-delta--up   { color:#2E7D32; }
.pr-kpi-delta--down { color:#C62828; }

/* Chart cards */
.pr-charts-row { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px; }
.pr-chart-card { background:#fff; border-radius:10px; padding:10px; border:1px solid #E8EDF5; }
.pr-chart-title { display:block; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.6px; color:#546E7A; margin-bottom:8px; }
.pr-chart-empty { font-size:11px; color:#B0BEC5; margin:8px 0 0; font-style:italic; }

/* Gender bars */
.pr-gender-chart { display:flex; flex-direction:column; gap:6px; }
.pr-gbar-row  { display:flex; align-items:center; gap:4px; }
.pr-gbar-lbl  { font-size:9px; font-weight:600; color:#546E7A; width:44px; flex-shrink:0; }
.pr-gbar-track{ flex:1; height:8px; background:#EEF1F7; border-radius:4px; overflow:hidden; }
.pr-gbar-fill { height:100%; border-radius:4px; transition:width .4s ease; min-width:2px; }
.pr-gbar-val  { font-size:9px; font-weight:700; color:#263238; width:24px; text-align:right; flex-shrink:0; }

/* Sparkline */
.pr-spark-wrap { display:flex; flex-direction:column; gap:3px; }
.pr-spark { width:100%; height:60px; display:block; overflow:visible; }
.pr-spark-labels { display:flex; justify-content:space-between; padding:0 2px; }
.pr-spark-lbl { font-size:7.5px; color:#90A4AE; }
.pr-spark-legend { display:flex; gap:10px; margin-top:2px; }
.pr-spark-leg { font-size:9px; font-weight:600; padding-left:10px; position:relative; }
.pr-spark-leg::before { content:''; position:absolute; left:0; top:50%; transform:translateY(-50%); width:7px; height:2px; border-radius:1px; }
.pr-spark-leg--total::before { background:#1A3A5C; height:2.5px; }
.pr-spark-leg--sym::before   { background:#DC3545; }

/* Sync health bar */
.pr-sync-health-card { margin-bottom:8px; }
.pr-sync-health-bar { display:flex; height:10px; border-radius:5px; overflow:hidden; gap:1px; margin:4px 0; }
.pr-shb-seg { min-width:2px; transition:flex .4s ease; }
.pr-shb--synced   { background:#4CAF50; }
.pr-shb--unsynced { background:#FF9800; }
.pr-shb--failed   { background:#F44336; }
.pr-sync-health-legend { display:flex; gap:10px; flex-wrap:wrap; }
.pr-shl { font-size:9px; font-weight:700; }
.pr-shl--synced   { color:#2E7D32; }
.pr-shl--unsynced { color:#E65100; }
.pr-shl--failed   { color:#C62828; }

/* Custom date inputs */
.pr-fp-row--dates { align-items:center; gap:4px; flex-wrap:wrap; }
.pr-date-input { border:1.5px solid #DDE3EA; border-radius:6px; padding:5px 8px; font-size:11px; color:#263238; background:#fff; outline:none; flex-shrink:0; }
.pr-date-input:focus { border-color:#1A3A5C; }

/* Responsive analytics */
@media (max-width:380px) {
  .pr-kpi-row   { grid-template-columns:1fr 1fr; }
  .pr-charts-row { grid-template-columns:1fr; }
}
</style>