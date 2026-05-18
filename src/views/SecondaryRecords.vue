<template>
  <IonPage>
    <IonHeader class="sr-header" translucent>
      <IonToolbar class="sr-toolbar">
        <IonButtons slot="start">
          <IonMenuButton menu="app-menu" class="sr-menu-btn" />
        </IonButtons>
        <div class="sr-title-block" slot="start">
          <span class="sr-eyebrow">IHR Art.23 · Case Register</span>
          <span class="sr-title">Screening Records</span>
        </div>
        <IonButtons slot="end">
          <button :class="['sr-sync-pill', syncPillClass]" @click="syncAllPending" :disabled="syncing" aria-label="Sync status">
            <span class="sr-sync-dot" />
            <span>{{ syncing ? 'Syncing…' : syncPillLabel }}</span>
          </button>
          <IonButton fill="clear" class="sr-refresh-btn" @click="reload" :disabled="loading" aria-label="Refresh">
            <IonIcon :icon="refreshOutline" slot="icon-only" />
          </IonButton>
        </IonButtons>
      </IonToolbar>

      <!-- ── STATS STRIP ─────────────────────────────────────────────── -->
      <div class="sr-stats-bar">
        <button class="sr-stat" @click="clearAllFilters">
          <span class="sr-stat-num">{{ totalCount }}</span>
          <span class="sr-stat-lbl">Total</span>
        </button>
        <div class="sr-stat-div" />
        <button class="sr-stat sr-stat--critical" @click="quickRisk('CRITICAL')">
          <span class="sr-stat-num">{{ criticalCount }}</span>
          <span class="sr-stat-lbl">Critical</span>
        </button>
        <div class="sr-stat-div" />
        <button class="sr-stat sr-stat--high" @click="quickRisk('HIGH')">
          <span class="sr-stat-num">{{ highCount }}</span>
          <span class="sr-stat-lbl">High</span>
        </button>
        <div class="sr-stat-div" />
        <button class="sr-stat sr-stat--active" @click="quickStatus('IN_PROGRESS')">
          <span class="sr-stat-num">{{ activeCount }}</span>
          <span class="sr-stat-lbl">Active</span>
        </button>
        <div class="sr-stat-div" />
        <button class="sr-stat sr-stat--unsynced" @click="filterUnsyncedOnly" :class="showUnsynced && 'sr-stat--on'">
          <span class="sr-stat-num">{{ unsyncedCount }}</span>
          <span class="sr-stat-lbl">Unsynced</span>
        </button>
        <div v-if="quarantinedRecords.length" class="sr-stat-div" />
        <button v-if="quarantinedRecords.length" class="sr-stat sr-stat--quarantine" @click="showQuarantinePanel = !showQuarantinePanel">
          <span class="sr-stat-num">{{ quarantinedRecords.length }}</span>
          <span class="sr-stat-lbl">Damaged</span>
        </button>
      </div>

      <!-- ── QUARANTINE BANNER ───────────────────────────────────────── -->
      <transition name="sr-slide">
        <div v-if="showQuarantinePanel && quarantinedRecords.length" class="sr-quarantine-banner">
          <div class="sr-qb-header">
            <svg class="sr-qb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            <div class="sr-qb-text">
              <span class="sr-qb-title">{{ quarantinedRecords.length }} Damaged Record{{ quarantinedRecords.length !== 1 ? 's' : '' }} Quarantined</span>
              <span class="sr-qb-sub">These records failed to sync {{ QUARANTINE_MAX_ATTEMPTS }} times and have been isolated to protect data integrity.</span>
            </div>
          </div>
          <div class="sr-qb-list">
            <div v-for="qr in quarantinedRecords" :key="qr.client_uuid" class="sr-qb-item">
              <div class="sr-qb-item-info">
                <span class="sr-qb-item-name">{{ qr.traveler_full_name || 'Anonymous' }}</span>
                <span class="sr-qb-item-err">{{ qr.last_sync_error || 'Unknown error' }}</span>
                <span class="sr-qb-item-meta">{{ qr.sync_attempt_count }} attempts · {{ fmtRelative(qr.updated_at || qr.opened_at) }}</span>
              </div>
              <div class="sr-qb-item-actions">
                <button class="sr-qb-retry" @click="retryQuarantined(qr)" :disabled="!isOnline" title="Retry sync">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>
                </button>
                <button class="sr-qb-delete" @click="deleteQuarantined(qr)" title="Delete damaged record">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" width="14" height="14"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                </button>
              </div>
            </div>
          </div>
          <button class="sr-qb-purge" @click="purgeAllQuarantined">
            Purge All Damaged Records
          </button>
        </div>
      </transition>

      <!-- ── SEARCH + FILTER CONTROLS ────────────────────────────────── -->
      <div class="sr-controls">
        <div class="sr-search-row">
          <div class="sr-search-box">
            <IonIcon :icon="searchOutline" class="sr-search-icon" />
            <input v-model="searchQuery" type="search" class="sr-search-input"
              placeholder="Name, document, syndrome, officer…" aria-label="Search records" />
            <button v-if="searchQuery" class="sr-search-clear" @click="searchQuery=''" aria-label="Clear">
              <IonIcon :icon="closeCircleOutline" />
            </button>
          </div>
          <button :class="['sr-filter-btn', filtersOpen && 'sr-filter-btn--on']"
            @click="filtersOpen = !filtersOpen" aria-label="Filters">
            <IonIcon :icon="optionsOutline" />
            <span v-if="activeFilterCount" class="sr-filter-badge">{{ activeFilterCount }}</span>
          </button>
        </div>

        <!-- Status tabs -->
        <div class="sr-tabs" role="tablist">
          <button v-for="t in STATUS_TABS" :key="t.v"
            :class="['sr-tab', statusFilter === t.v && 'sr-tab--on']"
            role="tab" :aria-selected="statusFilter === t.v" @click="quickStatus(t.v)">
            {{ t.label }}
            <span v-if="tabCount(t.v)" :class="['sr-tab-badge', t.bc]">{{ tabCount(t.v) }}</span>
          </button>
        </div>

        <!-- Expandable filter panel -->
        <transition name="sr-slide">
          <div v-if="filtersOpen" class="sr-filter-panel">
            <div class="sr-fp-row">
              <span class="sr-fp-lbl">Risk</span>
              <div class="sr-fp-pills">
                <button v-for="r in RISK_LEVELS" :key="r.v"
                  :class="['sr-pill', riskFilter===r.v && 'sr-pill--on', 'sr-pill--'+r.v.toLowerCase()]"
                  @click="riskFilter = riskFilter===r.v ? null : r.v">{{ r.label }}</button>
              </div>
            </div>
            <div class="sr-fp-row">
              <span class="sr-fp-lbl">Syndrome</span>
              <div class="sr-fp-pills" style="flex-wrap:wrap;gap:4px">
                <button v-for="s in SYNDROMES" :key="s.c"
                  :class="['sr-pill', synFilter===s.c && 'sr-pill--on sr-pill--syn']"
                  @click="synFilter = synFilter===s.c ? null : s.c">{{ s.l }}</button>
              </div>
            </div>
            <div class="sr-fp-row">
              <span class="sr-fp-lbl">Period</span>
              <div class="sr-fp-pills">
                <button v-for="p in DATE_PRESETS" :key="p.v"
                  :class="['sr-pill', datePreset===p.v && 'sr-pill--on sr-pill--date']"
                  @click="datePreset = p.v; customDateFrom=''; customDateTo=''">{{ p.label }}</button>
              </div>
            </div>
            <!-- Advanced date range -->
            <div class="sr-fp-row sr-fp-row--dates">
              <span class="sr-fp-lbl">Date Range</span>
              <div class="sr-date-inputs">
                <div class="sr-date-field">
                  <label class="sr-date-label">From</label>
                  <input type="date" v-model="customDateFrom" class="sr-date-input"
                    @change="datePreset='custom'" />
                </div>
                <div class="sr-date-field">
                  <label class="sr-date-label">To</label>
                  <input type="date" v-model="customDateTo" class="sr-date-input"
                    @change="datePreset='custom'" />
                </div>
              </div>
            </div>
            <!-- Month / Year quick picks -->
            <div class="sr-fp-row">
              <span class="sr-fp-lbl">Month</span>
              <div class="sr-fp-pills" style="flex-wrap:wrap;gap:4px">
                <button v-for="m in MONTH_OPTIONS" :key="m.v"
                  :class="['sr-pill sr-pill--sm', monthFilter===m.v && 'sr-pill--on sr-pill--date']"
                  @click="monthFilter = monthFilter===m.v ? null : m.v; datePreset='custom'">{{ m.l }}</button>
              </div>
            </div>
            <div class="sr-fp-row">
              <span class="sr-fp-lbl">Year</span>
              <div class="sr-fp-pills">
                <button v-for="y in YEAR_OPTIONS" :key="y"
                  :class="['sr-pill sr-pill--sm', yearFilter===y && 'sr-pill--on sr-pill--date']"
                  @click="yearFilter = yearFilter===y ? null : y; datePreset='custom'">{{ y }}</button>
              </div>
            </div>
            <div class="sr-fp-row">
              <span class="sr-fp-lbl">Disposition</span>
              <div class="sr-fp-pills" style="flex-wrap:wrap;gap:4px">
                <button v-for="d in DISPOSITIONS" :key="d.v"
                  :class="['sr-pill', dispFilter===d.v && 'sr-pill--on sr-pill--disp']"
                  @click="dispFilter = dispFilter===d.v ? null : d.v">{{ d.label }}</button>
              </div>
            </div>
            <button v-if="activeFilterCount" class="sr-clear-all" @click="clearAllFilters">
              Clear all filters
            </button>
          </div>
        </transition>

        <!-- Active filter chips -->
        <div v-if="(activeFilterCount||searchQuery) && !filtersOpen" class="sr-chip-row">
          <span v-if="riskFilter" :class="['sr-chip','sr-chip--'+riskFilter.toLowerCase()]">
            {{ riskFilter }}<button @click="riskFilter=null" class="sr-chip-x">&times;</button>
          </span>
          <span v-if="synFilter" class="sr-chip sr-chip--syn">
            {{ synFilter.replace(/_/g,' ') }}<button @click="synFilter=null" class="sr-chip-x">&times;</button>
          </span>
          <span v-if="dispFilter" class="sr-chip sr-chip--disp">
            {{ dispFilter.replace(/_/g,' ') }}<button @click="dispFilter=null" class="sr-chip-x">&times;</button>
          </span>
          <span v-if="datePreset!=='all'" class="sr-chip sr-chip--date">
            {{ dateChipLabel }}<button @click="datePreset='all';customDateFrom='';customDateTo='';monthFilter=null;yearFilter=null" class="sr-chip-x">&times;</button>
          </span>
          <span v-if="showUnsynced" class="sr-chip sr-chip--unsynced">
            Unsynced only<button @click="showUnsynced=false" class="sr-chip-x">&times;</button>
          </span>
          <span class="sr-chip-count">{{ displayItems.length }} result{{ displayItems.length!==1?'s':'' }}</span>
        </div>
      </div>

      <!-- Bulk sync bar -->
      <div v-if="unsyncedCount > 0 && isOnline && !syncing" class="sr-bulk-bar">
        <IonIcon :icon="cloudUploadOutline" class="sr-bulk-icon" />
        <span>{{ unsyncedCount }} record{{ unsyncedCount!==1?'s':'' }} not yet on server</span>
        <button class="sr-bulk-btn" @click="syncAllPending" :disabled="syncing">
          {{ syncing ? 'Syncing…' : 'Sync All Now' }}
        </button>
      </div>
    </IonHeader>

    <!-- ═══ CONTENT ═══════════════════════════════════════════════════ -->
    <IonContent class="sr-content" :fullscreen="true">
      <IonRefresher slot="fixed" @ionRefresh="onPullRefresh($event)">
        <IonRefresherContent pulling-text="Pull to refresh" refreshing-spinner="crescent" />
      </IonRefresher>

      <!-- Loading -->
      <div v-if="loading && !allItems.length" class="sr-loading">
        <div class="sr-loading-anim">
          <div class="sr-loading-ring" />
          <div class="sr-loading-ring sr-loading-ring--2" />
        </div>
        <p class="sr-loading-text">Loading case register…</p>
      </div>

      <!-- Offline -->
      <div v-if="!isOnline && !loading" class="sr-offline-bar" role="status">
        <IonIcon :icon="cloudOfflineOutline" />
        <span>Offline — {{ allItems.length }} cached record{{ allItems.length!==1?'s':'' }}</span>
      </div>

      <!-- Empty -->
      <div v-else-if="!loading && !displayItems.length" class="sr-empty">
        <div class="sr-empty-graphic">
          <svg viewBox="0 0 64 64" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" class="sr-empty-svg">
            <rect x="8" y="12" width="48" height="40" rx="4" /><line x1="16" y1="24" x2="48" y2="24" /><line x1="16" y1="32" x2="40" y2="32" /><line x1="16" y1="40" x2="32" y2="40" />
          </svg>
        </div>
        <h2 class="sr-empty-title">{{ searchQuery ? 'No Matching Records' : 'No Records Found' }}</h2>
        <p class="sr-empty-sub">{{ searchQuery ? 'Try different search terms or adjust filters.' : 'No cases match the current filters.' }}</p>
        <IonButton v-if="activeFilterCount||searchQuery" fill="outline" size="small" @click="clearAllFilters();searchQuery=''">
          Show all records
        </IonButton>
      </div>

      <!-- ── RECORD LIST ──────────────────────────────────────────────── -->
      <div v-else class="sr-list" role="list">
        <article
          v-for="item in displayItems" :key="item.client_uuid"
          :class="['sr-card', riskCardClass(item.risk_level), item.sync_status==='UNSYNCED'&&'sr-card--unsynced', item.sync_status==='FAILED'&&'sr-card--failed']"
          role="listitem"
          @click="openDetail(item)"
        >
          <!-- Priority stripe -->
          <div class="sr-card-stripe" />

          <div class="sr-card-body">
            <!-- Top row: status + risk + sync + time -->
            <div class="sr-card-top">
              <span :class="['sr-status-pill','sr-status-pill--'+statusKey(item.case_status)]">
                {{ STATUS_LABELS[item.case_status] || item.case_status }}
              </span>
              <span v-if="item.risk_level" :class="['sr-risk-pill','sr-risk-pill--'+item.risk_level.toLowerCase()]">
                {{ item.risk_level }}
              </span>
              <span :class="['sr-sync-dot-sm', 'sr-sync-dot-sm--'+item.sync_status.toLowerCase()]"
                :title="SYNC_LABELS[item.sync_status]" />
              <span v-if="item.emergency_signs_present" class="sr-emergency-badge">EMERGENCY</span>
              <span class="sr-card-time">{{ fmtRelative(item.opened_at) }}</span>
            </div>

            <!-- Traveler row -->
            <div class="sr-card-traveler">
              <div :class="['sr-avatar', 'sr-avatar--'+((item.risk_level||'LOW').toLowerCase())]" aria-hidden="true">
                <span class="sr-avatar-letter">{{ avatarLetter(item) }}</span>
              </div>
              <div class="sr-traveler-info">
                <span class="sr-traveler-name">
                  {{ item.traveler_full_name || `Anonymous · ${GENDER_LABELS[item.traveler_gender]||'Unknown'}` }}
                </span>
                <span class="sr-traveler-sub">
                  {{ GENDER_LABELS[item.traveler_gender]||'—' }}
                  <template v-if="item.traveler_age_years"> · {{ item.traveler_age_years }}y</template>
                  <template v-if="item.traveler_nationality_country_code"> · {{ item.traveler_nationality_country_code }}</template>
                </span>
              </div>
              <div v-if="item.temperature_value!=null" :class="['sr-temp-chip', tempChipClass(item.temperature_value, item.temperature_unit)]">
                {{ item.temperature_value }}°{{ item.temperature_unit||'C' }}
              </div>
            </div>

            <!-- Clinical summary (progressive reveal — show only non-null) -->
            <div class="sr-card-clinical">
              <span v-if="item.syndrome_classification" class="sr-tag sr-tag--syn">
                {{ item.syndrome_classification.replace(/_/g,' ') }}
              </span>
              <span v-if="item.final_disposition" :class="['sr-tag', 'sr-tag--disp-'+item.final_disposition.toLowerCase().replace(/_/g,'-')]">
                {{ item.final_disposition.replace(/_/g,' ') }}
              </span>
              <span v-if="item.top_disease" class="sr-tag sr-tag--disease">
                {{ item.top_disease.disease_code.replace(/_/g,' ') }}
                <template v-if="item.top_disease.confidence"> · {{ item.top_disease.confidence }}%</template>
              </span>
              <span v-if="item.triage_category" class="sr-tag sr-tag--triage">
                {{ item.triage_category.replace(/_/g, ' ') }}
              </span>
            </div>

            <!-- Footer -->
            <div class="sr-card-footer">
              <span class="sr-card-meta">{{ item.opener_name || 'Unknown officer' }}</span>
              <span class="sr-card-meta">{{ item.poe_code }}</span>
              <span class="sr-card-meta sr-card-date">{{ fmtShortDate(item.opened_at) }}</span>
              <button
                v-if="item.sync_status !== 'SYNCED'"
                class="sr-card-sync-btn"
                :class="'sr-card-sync-btn--'+item.sync_status.toLowerCase()"
                @click.stop="syncOneRecord(item)"
                :disabled="syncingUuids.has(item.client_uuid) || !isOnline"
              >
                <IonIcon v-if="!syncingUuids.has(item.client_uuid)" :icon="cloudUploadOutline" />
                <IonSpinner v-else name="crescent" style="width:12px;height:12px" />
                {{ syncingUuids.has(item.client_uuid) ? 'Syncing' : SYNC_LABELS[item.sync_status] }}
              </button>
              <span v-else class="sr-card-synced-badge">
                <IonIcon :icon="checkmarkCircleOutline" /> Synced
              </span>
            </div>
          </div>
        </article>

        <!-- Load more -->
        <div v-if="hasMore" class="sr-load-more">
          <IonButton fill="outline" expand="block" :disabled="loadingMore" @click="loadMore">
            <IonSpinner v-if="loadingMore" name="crescent" style="width:16px;height:16px;margin-right:6px" />
            <span v-else>Load more ({{ Math.max(0, totalOnServer - allItems.length) }} remaining)</span>
          </IonButton>
        </div>
      </div>

      <div style="height:32px" />
    </IonContent>

    <!-- ═══ DETAIL MODAL ════════════════════════════════════════════ -->
    <IonModal
      :is-open="modalOpen"
      :initial-breakpoint="0.92"
      :breakpoints="[0, 0.5, 0.92, 1]"
      handle-behavior="cycle"
      @didDismiss="closeDetail"
      class="sr-modal"
    >
      <IonHeader class="sr-modal-header">
        <IonToolbar class="sr-modal-toolbar">
          <IonButtons slot="start">
            <IonButton fill="clear" @click="dismissModal" class="sr-modal-close" aria-label="Close">
              <IonIcon :icon="closeOutline" slot="icon-only" />
            </IonButton>
          </IonButtons>
          <div class="sr-modal-title-block">
            <span class="sr-modal-eyebrow">Case #{{ detailRecord?.id || '—' }}</span>
            <span class="sr-modal-title">{{ detailRecord?.traveler_full_name || 'Anonymous Case' }}</span>
          </div>
          <IonButtons slot="end">
            <button
              v-if="detailRecord && detailRecord?.sync_status !== 'SYNCED'"
              :class="['sr-modal-sync-btn', syncingUuids.has(detailRecord?.client_uuid) && 'sr-modal-sync-btn--active']"
              @click="syncOneRecord(detailRecord)"
              :disabled="syncingUuids.has(detailRecord?.client_uuid) || !isOnline"
            >
              <IonIcon v-if="!syncingUuids.has(detailRecord?.client_uuid)" :icon="cloudUploadOutline" />
              <IonSpinner v-else name="crescent" style="width:14px;height:14px" />
              {{ syncingUuids.has(detailRecord?.client_uuid) ? 'Syncing…' : 'Sync Now' }}
            </button>
            <span v-else-if="detailRecord?.sync_status === 'SYNCED'" class="sr-modal-synced">
              <IonIcon :icon="checkmarkCircleOutline" /> Synced
            </span>
          </IonButtons>
        </IonToolbar>

        <!-- Modal status bar -->
        <IonToolbar v-if="detailRecord" class="sr-modal-status-toolbar">
          <div class="sr-modal-status-bar">
            <span :class="['sr-status-pill','sr-status-pill--'+statusKey(detailRecord.case_status)]">
              {{ STATUS_LABELS[detailRecord?.case_status] || detailRecord?.case_status }}
            </span>
            <span v-if="detailRecord?.risk_level" :class="['sr-risk-pill','sr-risk-pill--'+detailRecord.risk_level.toLowerCase()]">
              {{ detailRecord?.risk_level }} RISK
            </span>
            <span v-if="detailRecord?.triage_category" class="sr-triage-pill">
              {{ detailRecord?.triage_category?.replace('_',' ') }}
            </span>
            <span :class="['sr-sync-status-badge','sr-sync-status-badge--'+(detailRecord?.sync_status||'unsynced').toLowerCase()]">
              {{ SYNC_LABELS[detailRecord?.sync_status] }}
            </span>
          </div>
        </IonToolbar>

        <!-- Modal tabs -->
        <IonToolbar class="sr-modal-tabs-toolbar">
          <div class="sr-modal-tabs" role="tablist">
            <button v-for="t in MODAL_TABS" :key="t.key"
              :class="['sr-modal-tab', modalTab===t.key&&'sr-modal-tab--on']"
              role="tab" :aria-selected="modalTab===t.key"
              @click="modalTab=t.key">
              {{ t.label }}
              <span v-if="t.count && modalTabCount(t.key)" class="sr-modal-tab-badge">{{ modalTabCount(t.key) }}</span>
            </button>
          </div>
        </IonToolbar>
      </IonHeader>

      <div class="sr-modal-scroll">
        <!-- Loading overlay -->
        <div v-if="detailLoading" class="sr-detail-loading">
          <IonSpinner name="crescent" />
          <span>Loading full record…</span>
        </div>

        <div v-else class="sr-modal-body">

          <!-- ── TAB: OVERVIEW ──────────────────────────────────────── -->
          <div v-show="modalTab==='overview'" class="sr-tab-panel">

            <!-- Sync integrity card -->
            <div class="sr-sync-card" :class="'sr-sync-card--'+(detailRecord?.sync_status||'unsynced').toLowerCase()">
              <div class="sr-sync-card-header">
                <IonIcon :icon="detailRecord?.sync_status==='SYNCED'?checkmarkCircleOutline:cloudUploadOutline" class="sr-sync-card-icon" />
                <span class="sr-sync-card-title">Sync Status</span>
                <span :class="['sr-sync-status-badge','sr-sync-status-badge--'+(detailRecord?.sync_status||'unsynced').toLowerCase()]">
                  {{ SYNC_LABELS[detailRecord?.sync_status] }}
                </span>
              </div>
              <div class="sr-sync-card-body">
                <div class="sr-sync-row">
                  <span class="sr-sync-lbl">Server ID</span>
                  <span class="sr-sync-val">{{ detailRecord.id || 'Not yet assigned' }}</span>
                </div>
                <div class="sr-sync-row">
                  <span class="sr-sync-lbl">Client UUID</span>
                  <span class="sr-sync-val sr-uuid">{{ detailRecord.client_uuid }}</span>
                </div>
                <div class="sr-sync-row">
                  <span class="sr-sync-lbl">Synced at</span>
                  <span class="sr-sync-val">{{ fmtDateTime(detailRecord.synced_at) || 'Never' }}</span>
                </div>
                <div class="sr-sync-row" v-if="detailRecord.sync_attempt_count">
                  <span class="sr-sync-lbl">Attempts</span>
                  <span class="sr-sync-val" :class="detailRecord.sync_attempt_count >= QUARANTINE_MAX_ATTEMPTS && 'sr-sync-error'">
                    {{ detailRecord.sync_attempt_count }}{{ detailRecord.sync_attempt_count >= QUARANTINE_MAX_ATTEMPTS ? ' — QUARANTINED' : '' }}
                  </span>
                </div>
                <div class="sr-sync-row" v-if="detailRecord.last_sync_error">
                  <span class="sr-sync-lbl">Last error</span>
                  <span class="sr-sync-val sr-sync-error">{{ detailRecord.last_sync_error }}</span>
                </div>
                <div class="sr-sync-row">
                  <span class="sr-sync-lbl">Platform</span>
                  <span class="sr-sync-val">{{ detailRecord.platform }} · {{ detailRecord.device_id }}</span>
                </div>
                <div class="sr-sync-row">
                  <span class="sr-sync-lbl">Record version</span>
                  <span class="sr-sync-val">v{{ detailRecord.record_version }}</span>
                </div>
                <div class="sr-sync-row">
                  <span class="sr-sync-lbl">Notification status</span>
                  <span class="sr-sync-val">{{ detailRecord.notification?.status || detailRecord.notification_status || '—' }}</span>
                </div>
              </div>
              <button
                v-if="detailRecord && detailRecord?.sync_status !== 'SYNCED' && isOnline"
                class="sr-sync-card-action"
                @click="syncOneRecord(detailRecord)"
                :disabled="syncingUuids.has(detailRecord?.client_uuid)"
              >
                <IonIcon :icon="cloudUploadOutline" />
                {{ syncingUuids.has(detailRecord?.client_uuid) ? 'Syncing…' : 'Sync This Record Now' }}
              </button>
              <div v-else-if="!isOnline && detailRecord?.sync_status !== 'SYNCED'" class="sr-sync-offline-note">
                Device is offline — connect to sync
              </div>
            </div>

            <!-- Case timeline -->
            <div class="sr-section-hdr"><span class="sr-sec-num">A</span> Case Timeline</div>
            <div class="sr-timeline">
              <div class="sr-tl-item sr-tl-item--open">
                <div class="sr-tl-dot" />
                <div class="sr-tl-body">
                  <span class="sr-tl-label">Case Opened</span>
                  <span class="sr-tl-time">{{ fmtDateTime(detailRecord.opened_at) || '—' }}</span>
                  <span class="sr-tl-sub">by {{ detailRecord.opener_name || '—' }}</span>
                </div>
              </div>
              <div v-if="detailRecord.dispositioned_at" class="sr-tl-item sr-tl-item--dispositioned">
                <div class="sr-tl-dot" />
                <div class="sr-tl-body">
                  <span class="sr-tl-label">Dispositioned</span>
                  <span class="sr-tl-time">{{ fmtDateTime(detailRecord.dispositioned_at) }}</span>
                  <span class="sr-tl-sub">{{ detailRecord.final_disposition?.replace(/_/g,' ') || '—' }}</span>
                </div>
              </div>
              <div v-if="detailRecord.closed_at" class="sr-tl-item sr-tl-item--closed">
                <div class="sr-tl-dot" />
                <div class="sr-tl-body">
                  <span class="sr-tl-label">Case Closed</span>
                  <span class="sr-tl-time">{{ fmtDateTime(detailRecord.closed_at) }}</span>
                  <span v-if="caseDurationMins(detailRecord)" class="sr-tl-sub">Duration: {{ caseDurationMins(detailRecord) }}</span>
                </div>
              </div>
            </div>

            <!-- Clinical decision summary -->
            <div class="sr-section-hdr"><span class="sr-sec-num">B</span> Clinical Assessment</div>
            <div class="sr-kv-grid">
              <div class="sr-kv"><span class="sr-k">Syndrome</span><span class="sr-v">{{ detailRecord.syndrome_classification?.replace(/_/g,' ') || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Risk Level</span><span class="sr-v" :class="detailRecord.risk_level && 'sr-risk-text--'+detailRecord.risk_level.toLowerCase()">{{ detailRecord.risk_level || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Triage</span><span class="sr-v">{{ detailRecord.triage_category?.replace('_',' ') || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Appearance</span><span class="sr-v">{{ detailRecord.general_appearance?.replace(/_/g,' ') || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Emergency Signs</span><span class="sr-v" :class="detailRecord.emergency_signs_present&&'sr-v--danger'">{{ detailRecord.emergency_signs_present ? 'YES' : 'No' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Disposition</span><span class="sr-v">{{ detailRecord.final_disposition?.replace(/_/g,' ') || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Follow-up</span><span class="sr-v">{{ detailRecord.followup_required ? (detailRecord.followup_assigned_level || 'Required') : 'Not required' }}</span></div>
            </div>

            <div v-if="detailRecord.officer_notes" class="sr-notes-box">
              <span class="sr-notes-lbl">Officer Notes</span>
              <p class="sr-notes-text">{{ detailRecord.officer_notes }}</p>
            </div>

            <template v-if="detailRecord?.suspected_diseases?.length">
              <div class="sr-section-hdr"><span class="sr-sec-num">C</span> Suspected Diseases</div>
              <div class="sr-disease-list">
                <div v-for="d in detailRecord.suspected_diseases" :key="d.id" class="sr-disease-row">
                  <span class="sr-disease-rank">#{{ d.rank_order }}</span>
                  <div class="sr-disease-info">
                    <span class="sr-disease-name">{{ d.disease_code.replace(/_/g,' ').toUpperCase() }}</span>
                    <span v-if="d.confidence" class="sr-disease-conf">{{ d.confidence }}% confidence</span>
                    <span v-if="d.reasoning" class="sr-disease-reason">{{ d.reasoning }}</span>
                  </div>
                </div>
              </div>
            </template>
          </div>

          <!-- ── TAB: TRAVELER ──────────────────────────────────────── -->
          <div v-show="modalTab==='traveler'" class="sr-tab-panel">
            <div class="sr-section-hdr"><span class="sr-sec-num">1</span> Identity</div>
            <div class="sr-kv-grid">
              <div class="sr-kv"><span class="sr-k">Full Name</span><span class="sr-v">{{ detailRecord.traveler_full_name || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Gender</span><span class="sr-v">{{ GENDER_LABELS[detailRecord.traveler_gender] || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Age</span><span class="sr-v">{{ detailRecord.traveler_age_years ? detailRecord.traveler_age_years + ' years' : '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Date of Birth</span><span class="sr-v">{{ detailRecord.traveler_dob || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Nationality</span><span class="sr-v">{{ detailRecord.traveler_nationality_country_code || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Occupation</span><span class="sr-v">{{ detailRecord.traveler_occupation || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Document Type</span><span class="sr-v">{{ detailRecord.travel_document_type?.replace('_',' ') || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Document No.</span><span class="sr-v sr-doc-no">{{ detailRecord.travel_document_number || '—' }}</span></div>
            </div>

            <div class="sr-section-hdr"><span class="sr-sec-num">2</span> Contact &amp; Destination</div>
            <div class="sr-kv-grid">
              <div class="sr-kv"><span class="sr-k">Phone</span><span class="sr-v">{{ detailRecord.phone_number || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Alt. Phone</span><span class="sr-v">{{ detailRecord.alternative_phone || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Email</span><span class="sr-v">{{ detailRecord.email || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Residence</span><span class="sr-v">{{ detailRecord.residence_country_code || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Destination District</span><span class="sr-v">{{ detailRecord.destination_district_code || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Emergency Contact</span><span class="sr-v">{{ detailRecord.emergency_contact_name || '—' }}<template v-if="detailRecord.emergency_contact_phone"> · {{ detailRecord.emergency_contact_phone }}</template></span></div>
            </div>

            <div class="sr-section-hdr"><span class="sr-sec-num">3</span> Travel Itinerary</div>
            <div class="sr-kv-grid">
              <div class="sr-kv"><span class="sr-k">Journey Start</span><span class="sr-v">{{ detailRecord.journey_start_country_code || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Embarkation Port</span><span class="sr-v">{{ detailRecord.embarkation_port_city || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Conveyance</span><span class="sr-v">{{ detailRecord.conveyance_type || '—' }}<template v-if="detailRecord.conveyance_identifier"> · {{ detailRecord.conveyance_identifier }}</template></span></div>
              <div class="sr-kv"><span class="sr-k">Seat</span><span class="sr-v">{{ detailRecord.seat_number || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Arrival</span><span class="sr-v">{{ fmtDateTime(detailRecord.arrival_datetime) || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Purpose</span><span class="sr-v">{{ detailRecord.purpose_of_travel?.replace(/_/g,' ') || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Planned Stay</span><span class="sr-v">{{ detailRecord.planned_length_of_stay_days ? detailRecord.planned_length_of_stay_days+' days' : '—' }}</span></div>
            </div>

            <template v-if="detailRecord?.travel_countries?.length">
              <div class="sr-section-hdr"><span class="sr-sec-num">4</span> Countries Visited (Last 21 Days)</div>
              <div class="sr-tc-list">
                <div v-for="tc in detailRecord.travel_countries" :key="tc.id" class="sr-tc-row">
                  <span :class="['sr-tc-role', tc.travel_role==='VISITED'?'sr-tc-role--visited':'sr-tc-role--transit']">
                    {{ tc.travel_role }}
                  </span>
                  <span class="sr-tc-country">{{ tc.country_code }}</span>
                  <span class="sr-tc-dates">
                    {{ tc.arrival_date || '—' }} → {{ tc.departure_date || '—' }}
                  </span>
                </div>
              </div>
            </template>
          </div>

          <!-- ── TAB: CLINICAL ──────────────────────────────────────── -->
          <div v-show="modalTab==='clinical'" class="sr-tab-panel">
            <div class="sr-section-hdr"><span class="sr-sec-num">V</span> Vital Signs</div>
            <div class="sr-vitals-grid">
              <div class="sr-vital-card" :class="tempVitalClass(detailRecord.temperature_value, detailRecord.temperature_unit)">
                <span class="sr-vital-val">{{ detailRecord.temperature_value != null ? detailRecord.temperature_value+'°'+(detailRecord.temperature_unit||'C') : '—' }}</span>
                <span class="sr-vital-lbl">Temperature</span>
                <span v-if="tempWarn(detailRecord.temperature_value, detailRecord.temperature_unit)" class="sr-vital-warn">{{ tempWarn(detailRecord.temperature_value, detailRecord.temperature_unit) }}</span>
              </div>
              <div class="sr-vital-card" :class="pulseVitalClass(detailRecord.pulse_rate)">
                <span class="sr-vital-val">{{ detailRecord.pulse_rate ?? '—' }}</span>
                <span class="sr-vital-lbl">Pulse (bpm)</span>
              </div>
              <div class="sr-vital-card" :class="rrVitalClass(detailRecord.respiratory_rate)">
                <span class="sr-vital-val">{{ detailRecord.respiratory_rate ?? '—' }}</span>
                <span class="sr-vital-lbl">Resp Rate</span>
              </div>
              <div class="sr-vital-card" :class="spo2VitalClass(detailRecord.oxygen_saturation)">
                <span class="sr-vital-val">{{ detailRecord.oxygen_saturation != null ? detailRecord.oxygen_saturation+'%' : '—' }}</span>
                <span class="sr-vital-lbl">SpO2</span>
              </div>
              <div class="sr-vital-card">
                <span class="sr-vital-val">{{ detailRecord.bp_systolic && detailRecord.bp_diastolic ? detailRecord.bp_systolic+'/'+detailRecord.bp_diastolic : '—' }}</span>
                <span class="sr-vital-lbl">BP (mmHg)</span>
              </div>
            </div>

            <div class="sr-section-hdr"><span class="sr-sec-num">S</span> Symptoms</div>
            <div v-if="detailRecord.symptoms?.length" class="sr-symptom-grid">
              <template v-for="sym in (detailRecord?.symptoms||[])" :key="sym.id">
                <div v-if="sym.is_present" class="sr-sym-row sr-sym-row--present">
                  <span class="sr-sym-dot sr-sym-dot--present" />
                  <div class="sr-sym-info">
                    <span class="sr-sym-name">{{ sym.symptom_code.replace(/_/g,' ') }}</span>
                    <span v-if="sym.onset_date" class="sr-sym-onset">Onset: {{ sym.onset_date }}</span>
                    <span v-if="sym.details" class="sr-sym-detail">{{ sym.details }}</span>
                  </div>
                </div>
              </template>
              <div v-if="!detailRecord.symptoms.some(s=>s.is_present)" class="sr-empty-sub">No symptoms recorded as present</div>
            </div>
            <div v-else class="sr-empty-sub">No symptom data</div>

            <div class="sr-section-hdr"><span class="sr-sec-num">A</span> Actions Taken</div>
            <div v-if="detailRecord.actions?.length" class="sr-action-grid">
              <div v-for="a in detailRecord.actions.filter(x=>x.is_done)" :key="a.id" class="sr-action-row">
                <IonIcon :icon="checkmarkCircleOutline" class="sr-action-ico" />
                <span>{{ a.action_code.replace(/_/g,' ') }}</span>
                <span v-if="a.details" class="sr-action-detail">— {{ a.details }}</span>
              </div>
              <div v-if="!detailRecord.actions.some(a=>a.is_done)" class="sr-empty-sub">No actions recorded</div>
            </div>
            <div v-else class="sr-empty-sub">No action data</div>

            <template v-if="detailRecord?.samples?.some(s=>s.sample_collected)">
              <div class="sr-section-hdr"><span class="sr-sec-num">L</span> Lab Samples</div>
              <div class="sr-sample-list">
                <div v-for="s in detailRecord.samples.filter(x=>x.sample_collected)" :key="s.id" class="sr-sample-row">
                  <span class="sr-sample-type">{{ s.sample_type || 'Unknown type' }}</span>
                  <span v-if="s.sample_identifier" class="sr-sample-id">ID: {{ s.sample_identifier }}</span>
                  <span v-if="s.lab_destination" class="sr-sample-lab">→ {{ s.lab_destination }}</span>
                  <span v-if="s.collected_at" class="sr-sample-time">{{ fmtDateTime(s.collected_at) }}</span>
                </div>
              </div>
            </template>
          </div>

          <!-- ── TAB: EXPOSURES ─────────────────────────────────────── -->
          <div v-show="modalTab==='exposures'" class="sr-tab-panel">
            <div class="sr-section-hdr"><span class="sr-sec-num">E</span> Exposure Risk Factors</div>
            <div v-if="detailRecord.exposures?.length" class="sr-exposure-list">
              <div v-for="e in (detailRecord?.exposures||[])" :key="e.id"
                :class="['sr-exp-row', 'sr-exp-row--'+e.response.toLowerCase()]">
                <span :class="['sr-exp-badge','sr-exp-badge--'+e.response.toLowerCase()]">
                  {{ e.response }}
                </span>
                <div class="sr-exp-info">
                  <span class="sr-exp-code">{{ e.exposure_code.replace(/_/g,' ') }}</span>
                  <span v-if="e.details" class="sr-exp-detail">{{ e.details }}</span>
                </div>
              </div>
            </div>
            <div v-else class="sr-empty-sub">No exposure data recorded</div>
          </div>

          <!-- ── TAB: AI ANALYSIS ──────────────────────────────────── -->
          <div v-show="modalTab==='analysis'" class="sr-tab-panel">
            <div class="sr-section-hdr"><span class="sr-sec-num sr-sec-num--ai">AI</span> Risk Intelligence</div>

            <!-- Risk score card -->
            <div class="sr-ai-card">
              <div class="sr-ai-score-ring">
                <svg viewBox="0 0 80 80" class="sr-ai-svg">
                  <circle cx="40" cy="40" r="34" fill="none" stroke="#E8EDF5" stroke-width="6" />
                  <circle cx="40" cy="40" r="34" fill="none"
                    :stroke="aiRiskColor" stroke-width="6"
                    stroke-linecap="round"
                    :stroke-dasharray="aiDashArray"
                    transform="rotate(-90 40 40)" />
                </svg>
                <span class="sr-ai-score-text" :style="{color:aiRiskColor}">{{ aiRiskScore }}</span>
              </div>
              <div class="sr-ai-score-info">
                <span class="sr-ai-score-label">Composite Risk Score</span>
                <span class="sr-ai-score-desc">Based on vitals, symptoms, exposures, travel history, and syndrome classification.</span>
              </div>
            </div>

            <!-- Risk factors breakdown -->
            <div class="sr-section-hdr"><span class="sr-sec-num">F</span> Risk Factor Breakdown</div>
            <div class="sr-ai-factors">
              <div v-for="f in aiRiskFactors" :key="f.label" class="sr-ai-factor">
                <div class="sr-ai-factor-header">
                  <span class="sr-ai-factor-label">{{ f.label }}</span>
                  <span class="sr-ai-factor-score" :style="{color:f.color}">{{ f.score }}/{{ f.max }}</span>
                </div>
                <div class="sr-ai-factor-bar">
                  <div class="sr-ai-factor-fill" :style="{width:f.pct+'%', background:f.color}" />
                </div>
                <span v-if="f.note" class="sr-ai-factor-note">{{ f.note }}</span>
              </div>
            </div>

            <!-- Pattern alerts -->
            <template v-if="aiAlerts.length">
              <div class="sr-section-hdr"><span class="sr-sec-num">!</span> Pattern Alerts</div>
              <div class="sr-ai-alerts">
                <div v-for="(a,i) in aiAlerts" :key="i" :class="['sr-ai-alert','sr-ai-alert--'+a.level]">
                  <span class="sr-ai-alert-icon">{{ a.level==='critical'?'!!':a.level==='high'?'!':a.level==='info'?'OK':'i' }}</span>
                  <div class="sr-ai-alert-body">
                    <span class="sr-ai-alert-title">{{ a.title }}</span>
                    <span class="sr-ai-alert-desc">{{ a.desc }}</span>
                  </div>
                </div>
              </div>
            </template>
          </div>

          <!-- ── TAB: AUDIT ─────────────────────────────────────────── -->
          <div v-show="modalTab==='audit'" class="sr-tab-panel">
            <div class="sr-section-hdr"><span class="sr-sec-num">P</span> Primary Screening Link</div>
            <div v-if="detailRecord.primary_screening" class="sr-kv-grid">
              <div class="sr-kv"><span class="sr-k">Primary ID</span><span class="sr-v">{{ detailRecord.primary_screening.id }}</span></div>
              <div class="sr-kv"><span class="sr-k">Gender (Primary)</span><span class="sr-v">{{ GENDER_LABELS[detailRecord.primary_screening.gender] || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Temp (Primary)</span><span class="sr-v">{{ detailRecord.primary_screening.temperature_value != null ? detailRecord.primary_screening.temperature_value+'°'+(detailRecord.primary_screening.temperature_unit||'C') : '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Screener</span><span class="sr-v">{{ detailRecord.primary_screening.screener_name || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Captured At</span><span class="sr-v">{{ fmtDateTime(detailRecord.primary_screening.captured_at) || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Record Status</span><span class="sr-v">{{ detailRecord.primary_screening.record_status || '—' }}</span></div>
            </div>
            <div v-else class="sr-empty-sub">Primary screening data not available</div>

            <div class="sr-section-hdr"><span class="sr-sec-num">N</span> Notification</div>
            <div v-if="detailRecord.notification" class="sr-kv-grid">
              <div class="sr-kv"><span class="sr-k">Notification ID</span><span class="sr-v">{{ detailRecord.notification.id }}</span></div>
              <div class="sr-kv"><span class="sr-k">Status</span><span class="sr-v">{{ detailRecord.notification.status }}</span></div>
              <div class="sr-kv"><span class="sr-k">Priority</span><span class="sr-v">{{ detailRecord.notification.priority }}</span></div>
              <div class="sr-kv"><span class="sr-k">Opened At</span><span class="sr-v">{{ fmtDateTime(detailRecord.notification.opened_at) || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Closed At</span><span class="sr-v">{{ fmtDateTime(detailRecord.notification.closed_at) || '—' }}</span></div>
            </div>
            <div v-else class="sr-empty-sub">Notification data not available</div>

            <div v-if="detailRecord.alert" class="sr-alert-card">
              <div class="sr-section-hdr"><span class="sr-sec-num">!</span> Alert</div>
              <div class="sr-kv-grid">
                <div class="sr-kv"><span class="sr-k">Alert Code</span><span class="sr-v">{{ detailRecord.alert.alert_code }}</span></div>
                <div class="sr-kv"><span class="sr-k">Status</span><span class="sr-v">{{ detailRecord.alert.status }}</span></div>
                <div class="sr-kv"><span class="sr-k">Routed To</span><span class="sr-v">{{ detailRecord.alert.routed_to_level }}</span></div>
                <div class="sr-kv"><span class="sr-k">Risk Level</span><span class="sr-v" :class="detailRecord.alert.risk_level&&'sr-risk-text--'+detailRecord.alert.risk_level.toLowerCase()">{{ detailRecord.alert.risk_level }}</span></div>
              </div>
            </div>

            <div class="sr-section-hdr"><span class="sr-sec-num">I</span> Record Integrity</div>
            <div class="sr-kv-grid">
              <div class="sr-kv"><span class="sr-k">Server ID</span><span class="sr-v">{{ detailRecord.id || '—' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Client UUID</span><span class="sr-v sr-uuid">{{ detailRecord.client_uuid }}</span></div>
              <div class="sr-kv"><span class="sr-k">Record Version</span><span class="sr-v">v{{ detailRecord.record_version }}</span></div>
              <div class="sr-kv"><span class="sr-k">Sync Status</span><span class="sr-v" :class="'sr-risk-text--'+(detailRecord?.sync_status==='SYNCED'?'low':'critical')">{{ SYNC_LABELS[detailRecord?.sync_status] }}</span></div>
              <div class="sr-kv"><span class="sr-k">POE</span><span class="sr-v">{{ detailRecord.poe_code }}</span></div>
              <div class="sr-kv"><span class="sr-k">District</span><span class="sr-v">{{ detailRecord.district_code }}</span></div>
              <div class="sr-kv"><span class="sr-k">Ref. Data Ver.</span><span class="sr-v">{{ detailRecord.reference_data_version }}</span></div>
              <div class="sr-kv"><span class="sr-k">Received at Server</span><span class="sr-v">{{ fmtDateTime(detailRecord.server_received_at) || 'Never' }}</span></div>
              <div class="sr-kv"><span class="sr-k">Created</span><span class="sr-v">{{ fmtDateTime(detailRecord.created_at) }}</span></div>
              <div class="sr-kv"><span class="sr-k">Last Updated</span><span class="sr-v">{{ fmtDateTime(detailRecord.updated_at) }}</span></div>
            </div>
          </div>

        </div>

        <div class="sr-modal-end-spacer" aria-hidden="true" />
      </div>
    </IonModal>

    <!-- Toast -->
    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="3000" position="top" @didDismiss="toast.show=false" />
  </IonPage>
</template>

<script setup>
// ─────────────────────────────────────────────────────────────────────────────
// SecondaryScreeningRecords.vue — ECSA-HC POE Sentinel
// WHO/IHR 2005 · Secondary Case Register
//
// ══ ARCHITECTURE ════════════════════════════════════════════════════════════
//
//  Three-tier cache (IDB → Memory Window → Server) with:
//  • Quarantine layer: records failing sync ≥ QUARANTINE_MAX_ATTEMPTS are
//    isolated from the main list, surfaced in a quarantine panel, and can be
//    retried or purged. This prevents poison records from blocking bulk sync.
//  • AI Analysis: client-side risk scoring from vitals, symptoms, exposures.
//  • Advanced date filters: specific date range, month, year, ISO week.
//  • Enterprise sync with exponential backoff awareness.
// ─────────────────────────────────────────────────────────────────────────────

import { ref, computed, onMounted, onUnmounted, reactive, toRaw, watch, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import {
  IonPage, IonHeader, IonToolbar, IonButtons, IonMenuButton,
  IonButton, IonContent, IonIcon, IonSpinner,
  IonRefresher, IonRefresherContent,
  IonModal, IonToast,
  onIonViewWillEnter,
} from '@ionic/vue'
import {
  refreshOutline, searchOutline, closeCircleOutline,
  optionsOutline, cloudUploadOutline, cloudOfflineOutline,
  checkmarkCircleOutline, documentTextOutline, closeOutline,
} from 'ionicons/icons'
import {
  dbGet, dbGetByIndex, dbPut, dbDelete, safeDbPut, dbCountIndex, dbGetCount,
  dbReplaceAll,
  isoNow, genUUID as genUuid, STORE, SYNC, APP,
} from '@/services/poeDB'

const router = useRouter()
const route  = useRoute()

// Deep-link: tried-once guard so we don't reopen the modal on every re-enter
let _deepLinkedUuid = null
async function tryDeepLinkOpen() {
  const openUuid = String(route.query?.open || '').trim()
  if (!openUuid || _deepLinkedUuid === openUuid) return
  _deepLinkedUuid = openUuid
  // Wait a tick so `allItems` is populated from the first IDB page
  await nextTick()
  let item = allItems.value.find(i => i.client_uuid === openUuid)
  if (!item) {
    // Fallback to IDB direct
    item = await dbGet(STORE.SECONDARY_SCREENINGS, openUuid).catch(() => null)
  }
  if (item) {
    openDetail(item)
  }
}

// ─── AUTH ────────────────────────────────────────────────────────────────────
function getAuth() { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {} }
const auth = ref(getAuth())

// ─── CONSTANTS ────────────────────────────────────────────────────────────────
const QUARANTINE_MAX_ATTEMPTS = 4

const STATUS_TABS = [
  { v: null,            label: 'All',          bc: 'sr-tb--all'   },
  { v: 'OPEN',          label: 'Open',         bc: 'sr-tb--open'  },
  { v: 'IN_PROGRESS',   label: 'In Progress',  bc: 'sr-tb--ip'    },
  { v: 'DISPOSITIONED', label: 'Dispositioned',bc: 'sr-tb--disp'  },
  { v: 'CLOSED',        label: 'Closed',       bc: 'sr-tb--closed'},
]
const RISK_LEVELS = [
  { v:'CRITICAL', label:'Critical' }, { v:'HIGH',   label:'High'   },
  { v:'MEDIUM',   label:'Medium'   }, { v:'LOW',    label:'Low'    },
]
const SYNDROMES = [
  { c:'ILI',           l:'ILI'          }, { c:'SARI',         l:'SARI'           },
  { c:'AWD',           l:'AWD'          }, { c:'BLOODY_DIARRHEA',l:'Bloody Diarrhoea'},
  { c:'VHF',           l:'VHF'          }, { c:'RASH_FEVER',   l:'Rash/Fever'     },
  { c:'JAUNDICE',      l:'Jaundice'     }, { c:'NEUROLOGICAL', l:'Neurological'   },
  { c:'MENINGITIS',    l:'Meningitis'   }, { c:'OTHER',        l:'Other'          },
]
const DISPOSITIONS = [
  { v:'RELEASED',       label:'Released'       }, { v:'DELAYED',        label:'Delayed'        },
  { v:'QUARANTINED',    label:'Quarantined'    }, { v:'ISOLATED',       label:'Isolated'       },
  { v:'REFERRED',       label:'Referred'       }, { v:'TRANSFERRED',    label:'Transferred'    },
  { v:'DENIED_BOARDING',label:'Denied Boarding'},
]
const DATE_PRESETS = [
  { v:'all',   label:'All time'   }, { v:'today', label:'Today'      },
  { v:'week',  label:'This week'  }, { v:'month', label:'30 days'    },
  { v:'custom', label:'Custom'    },
]
const MONTH_OPTIONS = [
  { v:0,l:'Jan'},{v:1,l:'Feb'},{v:2,l:'Mar'},{v:3,l:'Apr'},
  { v:4,l:'May'},{v:5,l:'Jun'},{v:6,l:'Jul'},{v:7,l:'Aug'},
  { v:8,l:'Sep'},{v:9,l:'Oct'},{v:10,l:'Nov'},{v:11,l:'Dec'},
]
const currentYear = new Date().getFullYear()
const YEAR_OPTIONS = [currentYear, currentYear - 1, currentYear - 2]

const MODAL_TABS = [
  { key:'overview',  label:'Overview',  count:false },
  { key:'traveler',  label:'Traveler',  count:false },
  { key:'clinical',  label:'Clinical',  count:true  },
  { key:'exposures', label:'Exposures', count:true  },
  { key:'analysis',  label:'AI Analysis', count:false },
  { key:'audit',     label:'Audit',     count:false },
]
const STATUS_LABELS = {
  OPEN:'Open', IN_PROGRESS:'In Progress', DISPOSITIONED:'Dispositioned', CLOSED:'Closed',
}
// Maps include legacy values as fallback for existing records, but UI only offers MALE/FEMALE
const GENDER_LABELS  = { MALE:'Male', FEMALE:'Female', OTHER:'—', UNKNOWN:'—' }
const SYNC_LABELS    = { SYNCED:'Synced', UNSYNCED:'Not Synced', FAILED:'Sync Failed' }

// ─── PERFORMANCE TUNING ───────────────────────────────────────────────────────
const MAX_WINDOW       = 300
const IDB_PAGE_SIZE    = 100
const SERVER_PAGE_SIZE = 100
const POLL_INTERVAL_MS = 60_000
const LAST_SYNC_KEY    = 'rw_ssr_last_server_sync' // country-namespaced cursor

// ─── STATE ────────────────────────────────────────────────────────────────────
const allItems           = ref([])
const quarantinedRecords = ref([])
const showQuarantinePanel = ref(false)

const idbTotalCount    = ref(0)
const idbCritCount     = ref(0)
const idbHighCount     = ref(0)
const idbActiveCount   = ref(0)
const idbUnsyncedCount = ref(0)
// Per-status counts from FULL dataset (IDB scan or server stats).
// Used by tab badges so they reflect the real dataset, not the 300-item window.
const idbStatusCounts  = ref({ OPEN: 0, IN_PROGRESS: 0, DISPOSITIONED: 0, CLOSED: 0 })

const idbPageOffset  = ref(0)
const serverPage     = ref(1)
const totalOnServer  = ref(0)
const hasMoreIdb     = ref(true)
const hasMoreServer  = ref(true)
const loading        = ref(true)
const loadingMore    = ref(false)
const isOnline       = ref(navigator.onLine)

// Filter state
const searchQuery   = ref('')
const statusFilter  = ref(null)
const riskFilter    = ref(null)
const synFilter     = ref(null)
const dispFilter    = ref(null)
const datePreset    = ref('all')
const showUnsynced  = ref(false)
const filtersOpen   = ref(false)
const customDateFrom = ref('')
const customDateTo   = ref('')
const monthFilter    = ref(null)
const yearFilter     = ref(null)

// Modal state
const modalOpen     = ref(false)
const detailRecord  = ref(null)
const detailLoading = ref(false)
const modalTab      = ref('overview')

// Sync state
const syncingUuids = ref(new Set())
const syncing      = ref(false)

const toast = reactive({ show:false, msg:'', color:'success' })

let autoRefreshTimer = null
let bgSyncDebounce   = null

// ─── COMPUTED ─────────────────────────────────────────────────────────────────
const totalCount    = computed(() => idbTotalCount.value)
const criticalCount = computed(() => idbCritCount.value)
const highCount     = computed(() => idbHighCount.value)
const activeCount   = computed(() => idbActiveCount.value)
const unsyncedCount = computed(() => idbUnsyncedCount.value)

const hasMore = computed(() =>
  (hasMoreIdb.value && allItems.value.length < idbTotalCount.value) ||
  (hasMoreServer.value && isOnline.value)
)

const syncPillClass = computed(() => {
  if (!isOnline.value)     return 'sr-sync-pill--offline'
  if (syncing.value)       return 'sr-sync-pill--syncing'
  if (idbUnsyncedCount.value) return 'sr-sync-pill--pending'
  return 'sr-sync-pill--ok'
})
const syncPillLabel = computed(() => {
  if (!isOnline.value)     return 'Offline'
  if (idbUnsyncedCount.value) return `${idbUnsyncedCount.value} Unsynced`
  return 'All Synced'
})

const activeFilterCount = computed(() =>
  [riskFilter.value, synFilter.value, dispFilter.value,
   datePreset.value !== 'all' ? 'date' : null,
   monthFilter.value !== null ? 'month' : null,
   yearFilter.value !== null ? 'year' : null,
   showUnsynced.value ? 'u' : null].filter(Boolean).length
)

const dateChipLabel = computed(() => {
  if (customDateFrom.value || customDateTo.value) {
    return `${customDateFrom.value || '…'} → ${customDateTo.value || '…'}`
  }
  if (monthFilter.value !== null) {
    const ml = MONTH_OPTIONS.find(m => m.v === monthFilter.value)?.l || ''
    return yearFilter.value ? `${ml} ${yearFilter.value}` : ml
  }
  if (yearFilter.value) return String(yearFilter.value)
  const preset = DATE_PRESETS.find(p => p.v === datePreset.value)
  return preset?.label || 'Custom'
})

function dateFromPreset() {
  const now = new Date()
  if (datePreset.value === 'today') { const d = new Date(now); d.setHours(0,0,0,0); return { from: d, to: null } }
  if (datePreset.value === 'week')  { const d = new Date(now); d.setDate(d.getDate()-7); return { from: d, to: null } }
  if (datePreset.value === 'month') { const d = new Date(now); d.setDate(d.getDate()-30); return { from: d, to: null } }
  if (datePreset.value === 'custom') {
    // Month+Year filter
    if (monthFilter.value !== null || yearFilter.value !== null) {
      const y = yearFilter.value || currentYear
      const mStart = monthFilter.value !== null ? monthFilter.value : 0
      const mEnd   = monthFilter.value !== null ? monthFilter.value : 11
      return {
        from: new Date(y, mStart, 1),
        to:   new Date(y, mEnd + 1, 0, 23, 59, 59),
      }
    }
    // Custom date range
    if (customDateFrom.value || customDateTo.value) {
      return {
        from: customDateFrom.value ? new Date(customDateFrom.value + 'T00:00:00') : null,
        to:   customDateTo.value   ? new Date(customDateTo.value + 'T23:59:59')   : null,
      }
    }
  }
  return { from: null, to: null }
}

const displayItems = computed(() => {
  let items = allItems.value

  if (!isOnline.value || searchQuery.value) {
    if (statusFilter.value)  items = items.filter(i => i.case_status === statusFilter.value)
    if (riskFilter.value)    items = items.filter(i => i.risk_level === riskFilter.value)
    if (synFilter.value)     items = items.filter(i => i.syndrome_classification === synFilter.value)
    if (dispFilter.value)    items = items.filter(i => i.final_disposition === dispFilter.value)
    if (showUnsynced.value)  items = items.filter(i => i.sync_status !== 'SYNCED')
    const range = dateFromPreset()
    if (range.from || range.to) {
      items = items.filter(i => {
        if (!i.opened_at) return false
        const d = new Date(i.opened_at.replace(' ','T'))
        if (range.from && d < range.from) return false
        if (range.to   && d > range.to)   return false
        return true
      })
    }
    const q = searchQuery.value.trim().toLowerCase()
    if (q) items = items.filter(i =>
      (i.traveler_full_name??'').toLowerCase().includes(q) ||
      (i.opener_name??'').toLowerCase().includes(q) ||
      (i.syndrome_classification??'').toLowerCase().includes(q) ||
      (i.final_disposition??'').toLowerCase().includes(q) ||
      (i.risk_level??'').toLowerCase().includes(q) ||
      (i.poe_code??'').toLowerCase().includes(q) ||
      (i.case_status??'').toLowerCase().includes(q) ||
      (i.client_uuid??'').toLowerCase().startsWith(q) ||
      (i.traveler_nationality_country_code??'').toLowerCase().includes(q)
    )
  }
  return items
})

function tabCount(v) {
  if (!v) return null
  // Use full-dataset counts (IDB scan or server stats), NOT the 300-item window.
  // This ensures tab badges are accurate even when the dataset exceeds MAX_WINDOW.
  const count = idbStatusCounts.value[v] || 0
  return count || null
}
function modalTabCount(key) {
  if (!detailRecord.value) return 0
  if (key === 'clinical')  return (detailRecord.value.symptoms?.filter(s=>s.is_present).length||0) + (detailRecord.value.actions?.filter(a=>a.is_done).length||0)
  if (key === 'exposures') return detailRecord.value.exposures?.filter(e=>e.response==='YES').length||0
  return 0
}

// ─── AI ANALYSIS COMPUTED ────────────────────────────────────────────────────
// ─── SAFE TEMP CONVERSION ─────────────────────────────────────────────────────
// Validates range (32-45°C / 90-113°F). Returns null if invalid/out of range.
function safeTemp(val, unit) {
  if (val == null) return null
  const v = Number(val)
  if (isNaN(v)) return null
  const c = (unit === 'F') ? (v - 32) * 5 / 9 : v
  // Reject physiologically impossible values
  if (c < 25 || c > 45) return null
  return Math.round(c * 100) / 100
}

// Strict boolean check — handles 1, true, "1" but not "0" or other truthy garbage
function isTruePresent(v) { return v === true || v === 1 || v === '1' }

// Exposure weights by IHR risk tier — NOT all exposures are equal
const EXPOSURE_WEIGHTS = {
  KNOWN_CASE_CONTACT: 8,    // IHR Tier 1 — direct contact with confirmed case
  SICK_PERSON_CONTACT: 6,   // direct contact with symptomatic person
  LAB_EXPOSURE: 6,          // laboratory/healthcare setting
  FUNERAL_BURIAL: 5,        // burial/funeral (VHF transmission risk)
  MASS_GATHERING: 2,        // population-level risk, not direct
}
const DEFAULT_EXPOSURE_WEIGHT = 3

// Syndrome severity — IHR Annex 2 risk tiers
const SYNDROME_SCORES = {
  VHF: 15,              // Tier 1 — always notifiable
  MENINGITIS: 10,        // Tier 1 in epidemic belt
  NEUROLOGICAL: 9,       // possible encephalitis/rabies
  SARI: 8,              // WHO SARI case definition
  AWD: 7,               // cholera/acute watery diarrhea
  BLOODY_DIARRHEA: 7,   // possible shigellosis/EHEC
  RASH_FEVER: 5,        // measles/rubella differential
  ILI: 4,               // influenza-like illness
  JAUNDICE: 4,          // possible yellow fever/hepatitis
  OTHER: 2,             // unclassified — still warrants base score
  NONE: 0,
}

// ─── AI RISK SCORE ────────────────────────────────────────────────────────────
// Weighted composite. Max possible = 100 (normalized).
// Avoids double-counting: risk_level is EXCLUDED because it is itself
// derived from symptoms + temperature + syndrome during case intake.
const aiRiskScore = computed(() => {
  const r = detailRecord.value
  if (!r) return 0

  let raw = 0
  const MAX_RAW = 100  // all weights are tuned so max = 100

  // 1. Temperature (max 20)
  const tempC = safeTemp(r.temperature_value, r.temperature_unit)
  if (tempC !== null) {
    if (tempC >= 40.0) raw += 20
    else if (tempC >= 39.0) raw += 16
    else if (tempC >= 38.5) raw += 12
    else if (tempC >= 37.5) raw += 6
    else if (tempC < 35.0) raw += 10  // hypothermia — possible shock
  }

  // 2. Oxygen saturation (max 15)
  const spo2 = r.oxygen_saturation != null ? Number(r.oxygen_saturation) : null
  if (spo2 !== null && !isNaN(spo2)) {
    if (spo2 < 90) raw += 15
    else if (spo2 < 94) raw += 8
  }

  // 3. Pulse (max 8)
  const pulse = r.pulse_rate != null ? Number(r.pulse_rate) : null
  if (pulse !== null && !isNaN(pulse)) {
    if (pulse > 130 || pulse < 40) raw += 8
    else if (pulse > 120 || pulse < 50) raw += 5
    else if (pulse > 100) raw += 2
  }

  // 4. Respiratory rate (max 8) — PREVIOUSLY MISSING
  const rr = r.respiratory_rate != null ? Number(r.respiratory_rate) : null
  if (rr !== null && !isNaN(rr)) {
    if (rr > 30 || rr < 8) raw += 8
    else if (rr > 24 || rr < 12) raw += 4
  }

  // 5. Blood pressure — systolic (max 6) — PREVIOUSLY MISSING
  const bp = r.bp_systolic != null ? Number(r.bp_systolic) : null
  if (bp !== null && !isNaN(bp)) {
    if (bp < 90) raw += 6  // hypotension/shock
    else if (bp < 100) raw += 3
  }

  // 6. Symptoms count (max 12)
  const sympPresent = r.symptoms?.filter(s => isTruePresent(s.is_present)).length || 0
  raw += Math.min(sympPresent * 2, 12)

  // 7. Exposures — weighted by risk tier (max 16)
  // YES = full weight. UNKNOWN = 30% weight (precautionary principle —
  // inability to confirm absence is not the same as confirmed absence).
  // NO = 0 weight.
  let expRaw = 0
  const allExps = r.exposures || []
  const exps = allExps.filter(e => e.response === 'YES')
  const unknownExps = allExps.filter(e => e.response === 'UNKNOWN')
  for (const e of exps) {
    expRaw += EXPOSURE_WEIGHTS[e.exposure_code] || DEFAULT_EXPOSURE_WEIGHT
  }
  for (const e of unknownExps) {
    expRaw += Math.round((EXPOSURE_WEIGHTS[e.exposure_code] || DEFAULT_EXPOSURE_WEIGHT) * 0.3)
  }
  raw += Math.min(expRaw, 16)

  // 8. Emergency signs (max 10)
  if (r.emergency_signs_present) raw += 10

  // 9. Syndrome classification (max 15)
  raw += SYNDROME_SCORES[r.syndrome_classification] || 0

  // NOTE: risk_level is NOT scored — it is derivative of the above factors.
  // Scoring it would double-count temperature + symptoms + syndrome.

  return Math.min(100, Math.round(raw))
})

const aiRiskColor = computed(() => {
  const s = aiRiskScore.value
  if (s >= 70) return '#D32F2F'
  if (s >= 45) return '#F57C00'
  if (s >= 20) return '#F9A825'
  return '#388E3C'
})

const aiDashArray = computed(() => {
  const circumference = 2 * Math.PI * 34
  const fill = (aiRiskScore.value / 100) * circumference
  return `${fill} ${circumference - fill}`
})

// ─── AI RISK FACTORS BREAKDOWN ────────────────────────────────────────────────
const aiRiskFactors = computed(() => {
  const r = detailRecord.value
  if (!r) return []
  const factors = []

  // Temperature
  const tempC = safeTemp(r.temperature_value, r.temperature_unit)
  let tempScore = 0
  const tempMax = 20
  if (tempC !== null) {
    if (tempC >= 40.0) tempScore = 20
    else if (tempC >= 39.0) tempScore = 16
    else if (tempC >= 38.5) tempScore = 12
    else if (tempC >= 37.5) tempScore = 6
    else if (tempC < 35.0) tempScore = 10
  }
  const tempNote = tempC === null ? 'Not recorded' :
    tempC >= 39.0 ? `High fever ${tempC.toFixed(1)} C` :
    tempC >= 38.5 ? `Fever ${tempC.toFixed(1)} C` :
    tempC >= 37.5 ? `Low-grade ${tempC.toFixed(1)} C` :
    tempC < 35.0 ? `Hypothermia ${tempC.toFixed(1)} C` : null
  factors.push({ label: 'Temperature', score: tempScore, max: tempMax,
    pct: Math.round(tempScore / tempMax * 100),
    color: tempScore >= 12 ? '#D32F2F' : tempScore >= 6 ? '#F57C00' : '#388E3C',
    note: tempNote })

  // Vitals (combined: SpO2 + Pulse + RR + BP)
  let vitalScore = 0
  const vMax = 15
  const spo2 = r.oxygen_saturation != null ? Number(r.oxygen_saturation) : null
  if (spo2 !== null && !isNaN(spo2) && spo2 < 94) vitalScore += spo2 < 90 ? 8 : 4
  const pulse = r.pulse_rate != null ? Number(r.pulse_rate) : null
  if (pulse !== null && !isNaN(pulse) && (pulse > 120 || pulse < 50)) vitalScore += 4
  const rr = r.respiratory_rate != null ? Number(r.respiratory_rate) : null
  if (rr !== null && !isNaN(rr) && (rr > 30 || rr < 8)) vitalScore += 4
  const bp = r.bp_systolic != null ? Number(r.bp_systolic) : null
  if (bp !== null && !isNaN(bp) && bp < 90) vitalScore += 4
  vitalScore = Math.min(vitalScore, vMax)
  const vitalParts = []
  if (spo2 !== null && spo2 < 94) vitalParts.push(`SpO2 ${spo2}%`)
  if (pulse !== null && (pulse > 120 || pulse < 50)) vitalParts.push(`Pulse ${pulse}`)
  if (rr !== null && (rr > 30 || rr < 8)) vitalParts.push(`RR ${rr}`)
  if (bp !== null && bp < 90) vitalParts.push(`BP ${bp}`)
  factors.push({ label: 'Vital Signs', score: vitalScore, max: vMax,
    pct: Math.round(vitalScore / vMax * 100),
    color: vitalScore >= 8 ? '#D32F2F' : vitalScore >= 4 ? '#F57C00' : '#388E3C',
    note: vitalParts.length ? vitalParts.join(', ') : null })

  // Symptoms
  const sympPresent = r.symptoms?.filter(s => isTruePresent(s.is_present)).length || 0
  const sympScore = Math.min(sympPresent * 2, 12)
  factors.push({ label: 'Symptoms', score: sympScore, max: 12,
    pct: Math.round(sympScore / 12 * 100),
    color: sympScore >= 8 ? '#D32F2F' : sympScore >= 4 ? '#F57C00' : '#388E3C',
    note: sympPresent > 0 ? `${sympPresent} symptom${sympPresent !== 1 ? 's' : ''} present` : null })

  // Exposures — weighted (YES = full, UNKNOWN = 30% precautionary)
  const allExpsF = r.exposures || []
  const yesExpsF = allExpsF.filter(e => e.response === 'YES')
  const unkExpsF = allExpsF.filter(e => e.response === 'UNKNOWN')
  let expRawF = 0
  for (const e of yesExpsF) expRawF += EXPOSURE_WEIGHTS[e.exposure_code] || DEFAULT_EXPOSURE_WEIGHT
  for (const e of unkExpsF) expRawF += Math.round((EXPOSURE_WEIGHTS[e.exposure_code] || DEFAULT_EXPOSURE_WEIGHT) * 0.3)
  const expScoreF = Math.min(expRawF, 16)
  const highExpF = yesExpsF.filter(e => (EXPOSURE_WEIGHTS[e.exposure_code] || 0) >= 5)
  const noteParts = []
  if (yesExpsF.length) noteParts.push(`${yesExpsF.length} positive${highExpF.length ? ` (${highExpF.length} high-tier)` : ''}`)
  if (unkExpsF.length) noteParts.push(`${unkExpsF.length} unknown (30% weight)`)
  factors.push({ label: 'Exposure Risk', score: expScoreF, max: 16,
    pct: Math.round(expScoreF / 16 * 100),
    color: expScoreF >= 10 ? '#D32F2F' : expScoreF >= 5 ? '#F57C00' : '#388E3C',
    note: noteParts.length ? noteParts.join(', ') : null })

  // Syndrome
  const synS = SYNDROME_SCORES[r.syndrome_classification] || 0
  factors.push({ label: 'Syndrome', score: synS, max: 15,
    pct: Math.round(synS / 15 * 100),
    color: synS >= 10 ? '#D32F2F' : synS >= 5 ? '#F57C00' : '#388E3C',
    note: r.syndrome_classification ? r.syndrome_classification.replace(/_/g, ' ') : null })

  // Emergency signs
  const emScore = r.emergency_signs_present ? 10 : 0
  factors.push({ label: 'Emergency Signs', score: emScore, max: 10,
    pct: emScore ? 100 : 0,
    color: emScore ? '#D32F2F' : '#388E3C',
    note: r.emergency_signs_present ? 'Present - immediate action required' : null })

  return factors
})

// ─── AI CLINICAL INTELLIGENCE ENGINE ──────────────────────────────────────────
// Deep clinical analysis with:
// - Symptom-exposure coherence checking
// - Syndrome-symptom plausibility validation
// - False flag suppression for non-cases
// - Conflicting signal detection
// - WHO case definition pattern matching
// - Data quality warnings

// Symptom groups for coherence checking
const RESP_SYMPTOMS = ['COUGH','SORE_THROAT','RUNNY_NOSE','DIFFICULTY_BREATHING','SHORTNESS_OF_BREATH','CHEST_PAIN']
const GI_SYMPTOMS = ['DIARRHEA','VOMITING','NAUSEA','ABDOMINAL_PAIN','BLOODY_STOOL']
const NEURO_SYMPTOMS = ['HEADACHE','CONFUSION','SEIZURES','NECK_STIFFNESS','PHOTOPHOBIA','ALTERED_CONSCIOUSNESS']
const HEMORRHAGIC_SYMPTOMS = ['BLEEDING','BLOODY_STOOL','PETECHIAE','BRUISING','BLEEDING_GUMS']
const RASH_SYMPTOMS = ['RASH','SKIN_LESIONS','ITCHING']

function getSymptomCodes(r) {
  return (r.symptoms || []).filter(s => isTruePresent(s.is_present)).map(s => s.symptom_code?.toUpperCase() || '')
}
function hasAnySymptomIn(codes, group) { return codes.some(c => group.some(g => c.includes(g))) }

const aiAlerts = computed(() => {
  const r = detailRecord.value
  if (!r) return []
  const alerts = []
  const tempC = safeTemp(r.temperature_value, r.temperature_unit)
  const sympCodes = getSymptomCodes(r)
  const sympCount = sympCodes.length
  const allExpsA = r.exposures || []
  const exps = allExpsA.filter(e => e.response === 'YES')
  const expYes = exps.length
  const expUnk = allExpsA.filter(e => e.response === 'UNKNOWN')
  const expCodes = exps.map(e => e.exposure_code || '')
  const unkCodes = expUnk.map(e => e.exposure_code || '')
  // For critical exposure checks: YES = confirmed, UNKNOWN = cannot rule out
  const hasKnownContact = expCodes.includes('KNOWN_CASE_CONTACT')
  const hasSickContact = expCodes.includes('SICK_PERSON_CONTACT')
  const hasFuneral = expCodes.includes('FUNERAL_BURIAL')
  const hasLabExp = expCodes.includes('LAB_EXPOSURE')
  // UNKNOWN high-tier exposures — cannot rule out, precautionary
  const unkKnownContact = unkCodes.includes('KNOWN_CASE_CONTACT')
  const unkFuneral = unkCodes.includes('FUNERAL_BURIAL')
  const spo2 = r.oxygen_saturation != null ? Number(r.oxygen_saturation) : null
  const rr = r.respiratory_rate != null ? Number(r.respiratory_rate) : null
  const bp = r.bp_systolic != null ? Number(r.bp_systolic) : null
  const pulse = r.pulse_rate != null ? Number(r.pulse_rate) : null
  const hasFever = tempC !== null && tempC >= 38.0
  const hasHighFever = tempC !== null && tempC >= 39.5
  const hasResp = hasAnySymptomIn(sympCodes, RESP_SYMPTOMS)
  const hasGI = hasAnySymptomIn(sympCodes, GI_SYMPTOMS)
  const hasNeuro = hasAnySymptomIn(sympCodes, NEURO_SYMPTOMS)
  const hasHemorrhagic = hasAnySymptomIn(sympCodes, HEMORRHAGIC_SYMPTOMS)
  const hasRash = hasAnySymptomIn(sympCodes, RASH_SYMPTOMS)
  const allVitalsNormal = (tempC === null || (tempC >= 36.0 && tempC < 37.5)) &&
    (spo2 === null || spo2 >= 95) && (pulse === null || (pulse >= 60 && pulse <= 100)) &&
    (rr === null || (rr >= 12 && rr <= 20)) && (bp === null || bp >= 110)
  const syn = r.syndrome_classification || null

  // ═══ LAYER 1: NON-CASE DETECTION — suppress false flags ═══════════

  // If ZERO symptoms + ZERO exposures + normal vitals + no syndrome → NON-CASE
  if (sympCount === 0 && expYes === 0 && allVitalsNormal && !syn && !r.emergency_signs_present) {
    alerts.push({ level: 'info', title: 'Non-Case Assessment',
      desc: 'No symptoms, no exposure risk factors, vital signs within normal limits, and no syndrome classification. This traveler does not meet criteria for a suspected case under WHO IHR 2005 case definitions. Standard clearance appropriate.' })
    // Return early — no further alerts needed for a genuine non-case
    if (r.case_status === 'CLOSED' || r.case_status === 'DISPOSITIONED') return alerts
  }

  // Exposures (YES or UNKNOWN) but ZERO symptoms + normal vitals → contact monitoring
  const anyExposureSignal = expYes > 0 || expUnk.length > 0
  if (sympCount === 0 && anyExposureSignal && allVitalsNormal && !r.emergency_signs_present) {
    const confirmedHigh = hasKnownContact || hasSickContact
    const uncertainHigh = unkKnownContact || unkFuneral
    const tierLabel = confirmedHigh ? 'high-risk' : uncertainHigh ? 'uncertain-risk' : 'low-risk'
    const sevLevel = confirmedHigh ? 'high' : uncertainHigh ? 'medium' : 'medium'
    const expSummary = []
    if (expYes > 0) expSummary.push(`${expYes} confirmed YES`)
    if (expUnk.length > 0) expSummary.push(`${expUnk.length} UNKNOWN`)
    alerts.push({ level: sevLevel,
      title: `Asymptomatic ${tierLabel} Contact`,
      desc: `Exposure assessment: ${expSummary.join(', ')}. Traveler is currently asymptomatic with normal vitals. ` +
        (confirmedHigh ? 'Known case contact requires active monitoring: daily symptom check for disease-specific incubation period. Provide health alert card.' :
         uncertainHigh ? 'Traveler could not confirm or deny high-risk exposure. Apply precautionary monitoring. Provide health alert card and self-reporting instructions.' :
        'Provide health information and self-monitoring instructions. Follow-up only if symptoms develop.') })
  }

  // ═══ LAYER 2: SYNDROME-SYMPTOM COHERENCE ══════════════════════════

  // SARI classified but no respiratory symptoms → possible misclassification
  if (syn === 'SARI' && !hasResp) {
    alerts.push({ level: 'medium', title: 'SARI Without Respiratory Symptoms',
      desc: 'Case classified as SARI (Severe Acute Respiratory Infection) but no respiratory symptoms (cough, difficulty breathing, sore throat) are recorded as present. WHO SARI case definition requires: fever + cough + onset within last 10 days + hospitalization. Verify classification or add missing symptom data.' })
  }

  // ILI classified but no fever recorded
  if (syn === 'ILI' && (tempC === null || tempC < 38.0)) {
    alerts.push({ level: 'medium', title: 'ILI Without Documented Fever',
      desc: `Case classified as ILI (Influenza-Like Illness) but ${tempC === null ? 'temperature was not recorded' : `recorded temperature (${tempC.toFixed(1)} C) is below 38.0 C`}. WHO ILI case definition requires measured fever >= 38.0 C + cough + onset within last 10 days. Review classification.` })
  }

  // AWD classified but no GI symptoms
  if (syn === 'AWD' && !hasGI) {
    alerts.push({ level: 'medium', title: 'AWD Without GI Symptoms',
      desc: 'Case classified as Acute Watery Diarrhea but no gastrointestinal symptoms (diarrhea, vomiting, abdominal pain) are recorded. Verify classification.' })
  }

  // BLOODY_DIARRHEA but no bloody stool or diarrhea symptom recorded
  if (syn === 'BLOODY_DIARRHEA' && !hasGI && !hasHemorrhagic) {
    alerts.push({ level: 'medium', title: 'Bloody Diarrhea Without GI/Hemorrhagic Symptoms',
      desc: 'Classified as Bloody Diarrhea but neither GI nor hemorrhagic symptoms are recorded. This may be a data entry error. Verify.' })
  }

  // MENINGITIS but no neuro symptoms
  if (syn === 'MENINGITIS' && !hasNeuro) {
    alerts.push({ level: 'medium', title: 'Meningitis Without Neurological Symptoms',
      desc: 'Meningitis classification requires at least neck stiffness, headache, or altered consciousness. No neurological symptoms recorded. Verify clinical assessment.' })
  }

  // VHF classified but no hemorrhagic symptoms AND no fever → very suspicious
  if (syn === 'VHF' && !hasHemorrhagic && !hasFever) {
    alerts.push({ level: 'high', title: 'VHF Classification Without Cardinal Signs',
      desc: 'VHF classified but neither hemorrhagic symptoms nor fever are present. VHF case definition requires fever + hemorrhagic manifestations or epidemiological link. This classification may be premature. Verify with senior clinician before initiating VHF protocol to avoid unnecessary resource deployment.' })
  }

  // RASH_FEVER but no rash symptoms
  if (syn === 'RASH_FEVER' && !hasRash && !hasFever) {
    alerts.push({ level: 'medium', title: 'Rash Fever Without Rash or Fever',
      desc: 'Classified as Rash with Fever but neither rash nor fever documented. Verify classification.' })
  }

  // ═══ LAYER 3: CONFLICTING SIGNALS ═════════════════════════════════

  // High exposure risk but RELEASED disposition → potential premature release
  if (expYes >= 2 && (hasKnownContact || hasFuneral) && r.final_disposition === 'RELEASED' && sympCount > 0) {
    alerts.push({ level: 'high', title: 'Symptomatic High-Risk Contact Released',
      desc: `Traveler has ${expYes} exposures (including ${hasKnownContact ? 'known case contact' : 'funeral/burial'}) and ${sympCount} symptom${sympCount !== 1 ? 's' : ''} but was RELEASED. This conflicts with WHO contact management guidelines. Consider recalling for quarantine/monitoring.` })
  }

  // CRITICAL risk but disposition is RELEASED
  if (r.risk_level === 'CRITICAL' && r.final_disposition === 'RELEASED') {
    alerts.push({ level: 'high', title: 'Critical Risk Released',
      desc: 'A CRITICAL-risk case was released. Verify this was an intentional clinical decision and not a data entry error. Critical cases typically require isolation, quarantine, or referral per IHR Annex 1.' })
  }

  // Emergency signs present but case CLOSED without referral
  if (r.emergency_signs_present && r.case_status === 'CLOSED' && r.final_disposition !== 'REFERRED' && r.final_disposition !== 'TRANSFERRED') {
    alerts.push({ level: 'high', title: 'Emergency Signs — No Referral on Closure',
      desc: 'Emergency signs were documented but case was closed without referral or transfer to a health facility. Verify that emergency clinical needs were addressed and document rationale.' })
  }

  // Temperature conflicts with symptom report — fever present but "asymptomatic" primary
  // (This happens when primary screening says no symptoms but secondary finds fever)

  // ═══ LAYER 4: CRITICAL VITAL SIGN ALERTS ══════════════════════════

  if (r.emergency_signs_present) {
    alerts.push({ level: 'critical', title: 'Emergency Signs Present',
      desc: 'Immediate clinical attention required. Activate POE emergency response. Do not delay for secondary assessment.' })
  }

  // Probable VHF — the most dangerous combination
  // UNKNOWN exposure to case/funeral is treated as "cannot rule out" (precautionary)
  const epiLinkConfirmed = hasKnownContact || hasFuneral
  const epiLinkUncertain = !epiLinkConfirmed && (unkKnownContact || unkFuneral)
  if (syn === 'VHF' && hasFever && epiLinkConfirmed && hasHemorrhagic) {
    alerts.push({ level: 'critical', title: 'Probable VHF — Full Criteria Met',
      desc: `Fever + VHF syndrome + hemorrhagic symptoms + ${hasKnownContact ? 'known case contact' : 'funeral exposure'}. ALL criteria for a probable VHF case are met. IMMEDIATE ACTION: Full barrier nursing, dedicated isolation, IHR focal point notification, contact tracing. Do not transfer without biocontainment.` })
  } else if (syn === 'VHF' && hasFever && epiLinkConfirmed) {
    alerts.push({ level: 'critical', title: 'Suspected VHF — Epidemiological Link',
      desc: `Fever + VHF classification + ${hasKnownContact ? 'confirmed case contact' : 'funeral/burial exposure'}. Meets suspected VHF criteria. Initiate isolation and notify IHR focal point while awaiting lab confirmation.` })
  } else if (syn === 'VHF' && hasFever && epiLinkUncertain) {
    // UNKNOWN case contact/funeral with VHF + fever — precautionary escalation
    alerts.push({ level: 'critical', title: 'Suspected VHF — Unconfirmed Epi Link',
      desc: `Fever + VHF classification + UNKNOWN response to ${unkKnownContact ? 'case contact' : 'funeral/burial'} exposure. Traveler could not confirm or deny epidemiological link. Per precautionary principle, treat as suspected VHF until epi link is resolved. Initiate isolation.` })
  }

  // SARI case definition match
  if (hasFever && hasResp && (spo2 !== null && spo2 < 94 || rr !== null && rr > 24)) {
    alerts.push({ level: 'high', title: 'WHO SARI Case Definition Match',
      desc: `Fever + respiratory symptoms + ${spo2 !== null && spo2 < 94 ? `low SpO2 (${spo2}%)` : `elevated RR (${rr}/min)`}. This presentation meets the WHO Severe Acute Respiratory Infection case definition. Initiate respiratory isolation and consider specimen collection for respiratory pathogen testing.` })
  }

  if (hasHighFever) {
    alerts.push({ level: 'critical', title: `High Fever (${tempC.toFixed(1)} C)`,
      desc: `Temperature exceeds 39.5 C. Differential diagnosis depends on symptom pattern: ${hasResp ? 'respiratory symptoms suggest SARI/influenza' : hasGI ? 'GI symptoms suggest enteric fever/cholera' : hasNeuro ? 'neurological symptoms suggest meningitis/encephalitis' : hasHemorrhagic ? 'hemorrhagic signs suggest VHF' : 'undifferentiated fever — consider malaria, typhoid, rickettsia based on travel history'}.` })
  }

  if (tempC !== null && tempC < 35.0) {
    alerts.push({ level: 'high', title: `Hypothermia (${tempC.toFixed(1)} C)`,
      desc: 'Temperature below 35.0 C. In the context of infection, hypothermia is a sign of septic shock (worse prognosis than fever). Assess hemodynamic status, consider IV access and fluid resuscitation.' })
  }

  if (spo2 !== null && spo2 < 90) {
    alerts.push({ level: 'critical', title: `Critical Hypoxemia (SpO2 ${spo2}%)`,
      desc: 'SpO2 below 90%. Initiate supplemental oxygen immediately. Assess for SARI, pneumonia, pulmonary embolism, or cardiac failure. Continuous pulse oximetry required.' })
  }

  if (bp !== null && bp < 90) {
    alerts.push({ level: 'critical', title: `Hypotension (BP ${bp} mmHg)`,
      desc: 'Systolic BP below 90 mmHg suggests shock. Assess volume status. In febrile patients consider septic shock or hemorrhagic shock (VHF). IV fluid resuscitation required.' })
  }

  if (pulse !== null && pulse > 130) {
    alerts.push({ level: 'high', title: `Severe Tachycardia (${pulse} bpm)`,
      desc: `Heart rate ${pulse} bpm with ${hasFever ? 'fever — likely compensatory tachycardia from infection/dehydration' : 'no fever — consider cardiac cause, anxiety, pain, or hemorrhage'}.` })
  }

  if (rr !== null && rr > 30) {
    alerts.push({ level: 'high', title: `Tachypnea (RR ${rr}/min)`,
      desc: `Respiratory rate ${rr}/min. ${hasResp ? 'With respiratory symptoms — likely lower respiratory tract infection.' : 'Without respiratory symptoms — consider metabolic acidosis (DKA, sepsis), pain, or anxiety.'}` })
  }

  // ═══ LAYER 5: EXPOSURE-SYMPTOM COHERENCE ══════════════════════════

  if (hasKnownContact && sympCount >= 2) {
    if (syn !== 'VHF') { // Don't duplicate VHF alert
      alerts.push({ level: 'high', title: 'Symptomatic Known Case Contact',
        desc: `${sympCount} symptoms in a traveler with confirmed case contact. Regardless of syndrome classification, this meets contact tracing criteria for isolation and specimen collection. Determine the disease of the index case to guide differential.` })
    }
  }

  // High-tier exposures without symptoms — still warrants monitoring
  const highTierExps = exps.filter(e => (EXPOSURE_WEIGHTS[e.exposure_code] || 0) >= 5)
  if (highTierExps.length >= 2 && sympCount === 0) {
    alerts.push({ level: 'medium', title: 'Multiple High-Risk Exposures (Asymptomatic)',
      desc: `${highTierExps.length} high-risk exposures (${highTierExps.map(e => e.exposure_code.replace(/_/g, ' ').toLowerCase()).join(', ')}) but currently asymptomatic. This does NOT rule out infection — traveler may be in incubation. Issue health alert card and arrange active monitoring based on disease-specific incubation period.` })
  }

  // Lab exposure + any symptoms → occupational exposure protocol
  if (hasLabExp && sympCount > 0) {
    alerts.push({ level: 'high', title: 'Symptomatic Lab Exposure',
      desc: 'Laboratory/healthcare exposure with symptoms present. Follow occupational exposure protocol: identify specific pathogen exposure, assess PPE breach, collect specimens per biosafety guidelines.' })
  }

  // ═══ LAYER 6: DATA QUALITY ════════════════════════════════════════

  // NOTE: Vital signs (temperature, SpO2, pulse, RR, BP) are all optional.
  // Do NOT flag missing vitals as data quality issues.

  if (sympCount >= 3 && !syn) {
    alerts.push({ level: 'medium', title: 'Multiple Symptoms — No Syndrome Classified',
      desc: `${sympCount} symptoms present but no syndrome classification has been assigned. Review symptom pattern and classify per WHO case definitions: ${hasResp && hasFever ? 'consider ILI/SARI' : hasGI ? 'consider AWD' : hasNeuro ? 'consider MENINGITIS/NEUROLOGICAL' : 'review clinical presentation'}.` })
  }

  // UNKNOWN is a valid clinical response (traveler cannot recall, language barrier).
  // When ALL exposures are UNKNOWN, flag as clinical uncertainty — not a data error.
  const unknownCount = (r.exposures || []).filter(e => e.response === 'UNKNOWN').length
  const totalExposures = (r.exposures || []).length
  if (totalExposures > 0 && unknownCount === totalExposures && sympCount > 0) {
    alerts.push({ level: 'medium', title: 'Full Exposure Uncertainty',
      desc: `All ${totalExposures} exposure questions answered UNKNOWN in a symptomatic traveler. This is clinically valid (language barrier, unable to recall) but limits risk stratification. Consider this an unresolved uncertainty — apply precautionary principle and treat as potential exposure until epidemiological link can be confirmed or excluded.` })
  }

  // ═══ LAYER 7: OPERATIONAL ═════════════════════════════════════════

  if (r.risk_level === 'CRITICAL' && r.case_status === 'OPEN') {
    alerts.push({ level: 'high', title: 'Critical Case Not Dispositioned',
      desc: 'CRITICAL-risk case remains in OPEN status. Immediate disposition decision required. Do not leave critical cases without a clear management plan.' })
  }

  if (r.risk_level === 'CRITICAL' && r.case_status === 'CLOSED' && !r.followup_required) {
    alerts.push({ level: 'medium', title: 'Critical Case Closed — No Follow-up',
      desc: 'A CRITICAL-risk case was closed without flagging for follow-up. Verify IHR reporting obligations are met and public health follow-up is arranged.' })
  }

  // Deduplicate alerts by title (in case coherence checks and vital checks overlap)
  const seen = new Set()
  return alerts.filter(a => {
    if (seen.has(a.title)) return false
    seen.add(a.title)
    return true
  })
})

// ─── toPlain ──────────────────────────────────────────────────────────────────
function toPlain(val) { return JSON.parse(JSON.stringify(toRaw(val))) }

// ─── IDB STATS ────────────────────────────────────────────────────────────────
// BUG FIX: Previously Critical/High/Active used allItems.filter() which only
// counted the in-memory window (max 300 records). Unsynced counted ALL POEs.
// Now: full IDB scan scoped to poe_code for accurate breakdowns.
// When online: also fetches server stats for authoritative totals.
async function refreshIdbStats() {
  const poeCode = auth.value?.poe_code || ''
  if (!poeCode) return
  try {
    // Full IDB scan for this POE — needed for accurate breakdowns.
    // At typical POE volumes (hundreds to low thousands), this is fast.
    // The scan returns lightweight objects; we only read enum fields.
    const allPoeRecords = await dbGetByIndex(STORE.SECONDARY_SCREENINGS, 'poe_code', poeCode)
    const live = allPoeRecords.filter(r => !r.deleted_at && (r.sync_attempt_count || 0) < QUARANTINE_MAX_ATTEMPTS)

    idbTotalCount.value    = live.length
    idbCritCount.value     = live.filter(r => r.risk_level === 'CRITICAL').length
    idbHighCount.value     = live.filter(r => r.risk_level === 'HIGH').length
    idbActiveCount.value   = live.filter(r => r.case_status === 'OPEN' || r.case_status === 'IN_PROGRESS').length
    idbUnsyncedCount.value = live.filter(r => r.sync_status === SYNC.UNSYNCED || r.sync_status === SYNC.FAILED).length

    // Compute per-status counts for tab badges from the FULL dataset
    idbStatusCounts.value = {
      OPEN:          live.filter(r => r.case_status === 'OPEN').length,
      IN_PROGRESS:   live.filter(r => r.case_status === 'IN_PROGRESS').length,
      DISPOSITIONED: live.filter(r => r.case_status === 'DISPOSITIONED').length,
      CLOSED:        live.filter(r => r.case_status === 'CLOSED').length,
    }

    // Consistency guard: stats must never show fewer than what's displayed.
    // This catches edge cases where IDB and allItems diverge (e.g. server
    // merge added records that IDB write-through skipped due to version guard).
    const displayCount = allItems.value.length
    if (idbTotalCount.value < displayCount) {
      idbTotalCount.value = displayCount
      // Recompute breakdowns from allItems since IDB is behind
      idbCritCount.value   = allItems.value.filter(i => i.risk_level === 'CRITICAL').length
      idbHighCount.value   = allItems.value.filter(i => i.risk_level === 'HIGH').length
      idbActiveCount.value = allItems.value.filter(i => i.case_status === 'OPEN' || i.case_status === 'IN_PROGRESS').length
      idbUnsyncedCount.value = allItems.value.filter(i => i.sync_status === SYNC.UNSYNCED || i.sync_status === SYNC.FAILED).length
      idbStatusCounts.value = {
        OPEN:          allItems.value.filter(i => i.case_status === 'OPEN').length,
        IN_PROGRESS:   allItems.value.filter(i => i.case_status === 'IN_PROGRESS').length,
        DISPOSITIONED: allItems.value.filter(i => i.case_status === 'DISPOSITIONED').length,
        CLOSED:        allItems.value.filter(i => i.case_status === 'CLOSED').length,
      }
    }
  } catch (e) {
    console.warn('[SSR] refreshIdbStats error', e?.message)
  }
}

// ─── QUARANTINE ENGINE ────────────────────────────────────────────────────────
// Scans IDB for records with sync_attempt_count >= QUARANTINE_MAX_ATTEMPTS.
// Moves them to the quarantine list and removes from the main view.
// After quarantine, records are available for retry or manual deletion.
async function scanForDamagedRecords() {
  try {
    const poeCode = auth.value?.poe_code || ''
    if (!poeCode) return

    // ── Phase A: Purge records with no traveler name ──────────────────
    // Records without a traveler_full_name are incomplete/corrupt and
    // cannot be meaningfully used. They are deleted from local IDB and,
    // if synced to the server, soft-deleted there too.
    await purgeNoNameRecords(poeCode)

    // ── Phase B: Quarantine records that failed sync ≥ 4 times ───────
    const failedRecords = await dbGetByIndex(STORE.SECONDARY_SCREENINGS, 'sync_status', SYNC.FAILED)
    const damaged = failedRecords.filter(r =>
      (r.sync_attempt_count || 0) >= QUARANTINE_MAX_ATTEMPTS &&
      r.poe_code === poeCode
    )

    quarantinedRecords.value = damaged.map(normaliseIdbRecord)

    // Remove quarantined from main view
    if (damaged.length > 0) {
      const qUuids = new Set(damaged.map(d => d.client_uuid))
      allItems.value = allItems.value.filter(i => !qUuids.has(i.client_uuid))
    }
  } catch (e) {
    console.warn('[SSR] scanForDamagedRecords error', e?.message)
  }
}

// ─── NO-NAME RECORD PURGE ────────────────────────────────────────────────────
// Finds secondary screening records with no traveler_full_name that are
// CLOSED or DISPOSITIONED (i.e. completed but corrupt — name should exist).
//
// IMPORTANT: OPEN and IN_PROGRESS records are EXCLUDED — officers may still
// be filling in the intake form (name is entered in Step 1 of the 4-step
// wizard). Purging active cases would destroy in-progress work.
//
// For eligible records:
//   1. If the record has a server ID and we are online → soft-delete on server
//   2. Delete the record and all its child tables from local IDB
async function purgeNoNameRecords(poeCode) {
  try {
    const allIdb = await dbGetByIndex(STORE.SECONDARY_SCREENINGS, 'poe_code', poeCode)
    // NOTE: Missing opened_by_user_id / opener_name is NOT a damage signal.
    // Some records may legitimately lack an opener (e.g. imported data).
    // Only missing traveler_full_name on COMPLETED cases is considered corrupt.
    const noName = allIdb.filter(r =>
      !r.deleted_at &&
      (!r.traveler_full_name || r.traveler_full_name.trim() === '') &&
      // Only purge CLOSED/DISPOSITIONED records — active cases are still being worked on
      (r.case_status === 'CLOSED' || r.case_status === 'DISPOSITIONED')
    )

    if (!noName.length) return

    let serverDeleted = 0
    let localDeleted  = 0

    for (const rec of noName) {
      const uuid     = rec.client_uuid
      const serverId = rec.id ?? rec.server_id ?? null

      // Step 1: Soft-delete on server if the record was synced
      if (serverId && isOnline.value) {
        try {
          const userId = auth.value?.id
          if (userId) {
            const res = await timedFetch(
              `${window.SERVER_URL}/secondary-screenings/${serverId}?user_id=${userId}`,
              { method: 'DELETE', headers: { Accept: 'application/json' } }
            )
            if (res.ok || res.status === 404) {
              serverDeleted++
            } else {
              console.warn(`[SSR] Server delete failed for ${serverId}: HTTP ${res.status}`)
            }
          }
        } catch (e) {
          console.warn(`[SSR] Server delete error for ${serverId}:`, e?.message)
          // Continue — still delete locally even if server delete fails
        }
      }

      // Step 2: Delete from local IDB (record + all 6 child tables)
      try {
        await Promise.all([
          dbDelete(STORE.SECONDARY_SCREENINGS, uuid),
          dbGetByIndex(STORE.SECONDARY_SYMPTOMS, 'secondary_screening_id', uuid)
            .then(recs => Promise.all(recs.map(r => dbDelete(STORE.SECONDARY_SYMPTOMS, r.client_uuid)))).catch(() => {}),
          dbGetByIndex(STORE.SECONDARY_EXPOSURES, 'secondary_screening_id', uuid)
            .then(recs => Promise.all(recs.map(r => dbDelete(STORE.SECONDARY_EXPOSURES, r.client_uuid)))).catch(() => {}),
          dbGetByIndex(STORE.SECONDARY_ACTIONS, 'secondary_screening_id', uuid)
            .then(recs => Promise.all(recs.map(r => dbDelete(STORE.SECONDARY_ACTIONS, r.client_uuid)))).catch(() => {}),
          dbGetByIndex(STORE.SECONDARY_SAMPLES, 'secondary_screening_id', uuid)
            .then(recs => Promise.all(recs.map(r => dbDelete(STORE.SECONDARY_SAMPLES, r.client_uuid)))).catch(() => {}),
          dbGetByIndex(STORE.SECONDARY_TRAVEL_COUNTRIES, 'secondary_screening_id', uuid)
            .then(recs => Promise.all(recs.map(r => dbDelete(STORE.SECONDARY_TRAVEL_COUNTRIES, r.client_uuid)))).catch(() => {}),
          dbGetByIndex(STORE.SECONDARY_SUSPECTED_DISEASES, 'secondary_screening_id', uuid)
            .then(recs => Promise.all(recs.map(r => dbDelete(STORE.SECONDARY_SUSPECTED_DISEASES, r.client_uuid)))).catch(() => {}),
        ])
        localDeleted++
      } catch (e) {
        console.warn(`[SSR] Local delete error for ${uuid}:`, e?.message)
      }
    }

    // Remove from memory window
    if (localDeleted > 0) {
      const purgedUuids = new Set(noName.map(r => r.client_uuid))
      allItems.value = allItems.value.filter(i => !purgedUuids.has(i.client_uuid))
    }

    if (localDeleted > 0) {
      console.log(`[SSR] Purged ${localDeleted} no-name records locally, ${serverDeleted} from server`)
      showToast(`${localDeleted} incomplete record${localDeleted !== 1 ? 's' : ''} (no traveler name) removed.`, 'warning')
    }
  } catch (e) {
    console.warn('[SSR] purgeNoNameRecords error', e?.message)
  }
}

async function retryQuarantined(qr) {
  if (!isOnline.value) { showToast('Device is offline.', 'warning'); return }
  try {
    // Reset the attempt count so it gets another chance
    const rec = await dbGet(STORE.SECONDARY_SCREENINGS, qr.client_uuid)
    if (!rec) { showToast('Record not found in local store.', 'warning'); return }

    await safeDbPut(STORE.SECONDARY_SCREENINGS, toPlain({
      ...rec,
      sync_status: SYNC.FAILED,
      sync_attempt_count: 0,
      last_sync_error: null,
      updated_at: isoNow(),
    }))

    // Move back to main list
    quarantinedRecords.value = quarantinedRecords.value.filter(q => q.client_uuid !== qr.client_uuid)
    await reload()
    showToast('Record returned to sync queue.', 'success')
  } catch (e) {
    showToast(`Retry error: ${e?.message || 'Unknown'}`, 'danger')
  }
}

async function deleteQuarantined(qr) {
  try {
    // Delete from IDB — the record and all its child tables
    const uuid = qr.client_uuid
    await Promise.all([
      dbDelete(STORE.SECONDARY_SCREENINGS, uuid),
      dbGetByIndex(STORE.SECONDARY_SYMPTOMS, 'secondary_screening_id', uuid)
        .then(recs => Promise.all(recs.map(r => dbDelete(STORE.SECONDARY_SYMPTOMS, r.client_uuid)))).catch(()=>{}),
      dbGetByIndex(STORE.SECONDARY_EXPOSURES, 'secondary_screening_id', uuid)
        .then(recs => Promise.all(recs.map(r => dbDelete(STORE.SECONDARY_EXPOSURES, r.client_uuid)))).catch(()=>{}),
      dbGetByIndex(STORE.SECONDARY_ACTIONS, 'secondary_screening_id', uuid)
        .then(recs => Promise.all(recs.map(r => dbDelete(STORE.SECONDARY_ACTIONS, r.client_uuid)))).catch(()=>{}),
      dbGetByIndex(STORE.SECONDARY_SAMPLES, 'secondary_screening_id', uuid)
        .then(recs => Promise.all(recs.map(r => dbDelete(STORE.SECONDARY_SAMPLES, r.client_uuid)))).catch(()=>{}),
      dbGetByIndex(STORE.SECONDARY_TRAVEL_COUNTRIES, 'secondary_screening_id', uuid)
        .then(recs => Promise.all(recs.map(r => dbDelete(STORE.SECONDARY_TRAVEL_COUNTRIES, r.client_uuid)))).catch(()=>{}),
      dbGetByIndex(STORE.SECONDARY_SUSPECTED_DISEASES, 'secondary_screening_id', uuid)
        .then(recs => Promise.all(recs.map(r => dbDelete(STORE.SECONDARY_SUSPECTED_DISEASES, r.client_uuid)))).catch(()=>{}),
    ])

    quarantinedRecords.value = quarantinedRecords.value.filter(q => q.client_uuid !== uuid)
    await refreshIdbStats()
    showToast('Damaged record permanently deleted.', 'success')
  } catch (e) {
    showToast(`Delete error: ${e?.message || 'Unknown'}`, 'danger')
  }
}

async function purgeAllQuarantined() {
  const count = quarantinedRecords.value.length
  for (const qr of [...quarantinedRecords.value]) {
    await deleteQuarantined(qr)
  }
  showToast(`${count} damaged record${count!==1?'s':''} purged.`, 'success')
  showQuarantinePanel.value = false
}

// ─── IDB PAGE READ ────────────────────────────────────────────────────────────
async function readIdbPage(offset = 0) {
  const poeCode = auth.value?.poe_code || ''
  if (!poeCode) return []
  try {
    const allIdb = await dbGetByIndex(STORE.SECONDARY_SCREENINGS, 'poe_code', poeCode)
    const valid  = allIdb.filter(r => !r.deleted_at && (r.sync_attempt_count || 0) < QUARANTINE_MAX_ATTEMPTS)

    const RISK_ORD = { CRITICAL:0, HIGH:1, MEDIUM:2, LOW:3 }
    valid.sort((a,b) => {
      const rd = (RISK_ORD[a.risk_level]??9) - (RISK_ORD[b.risk_level]??9)
      if (rd !== 0) return rd
      return new Date(b.opened_at||b.created_at||0) - new Date(a.opened_at||a.created_at||0)
    })

    return valid.slice(offset, offset + IDB_PAGE_SIZE).map(normaliseIdbRecord)
  } catch (e) {
    console.warn('[SSR] readIdbPage error', e?.message)
    return []
  }
}

function normaliseIdbRecord(r) {
  return {
    id:                       r.id ?? r.server_id ?? null,
    client_uuid:              r.client_uuid,
    case_status:              r.case_status || 'OPEN',
    risk_level:               r.risk_level || null,
    syndrome_classification:  r.syndrome_classification || null,
    final_disposition:        r.final_disposition || null,
    followup_required:        !!r.followup_required,
    triage_category:          r.triage_category || null,
    emergency_signs_present:  !!r.emergency_signs_present,
    traveler_full_name:       r.traveler_full_name || null,
    traveler_gender:          r.traveler_gender || null,
    traveler_age_years:       r.traveler_age_years ?? null,
    traveler_nationality_country_code: r.traveler_nationality_country_code || null,
    temperature_value:        r.temperature_value ?? null,
    temperature_unit:         r.temperature_unit || null,
    poe_code:                 r.poe_code,
    district_code:            r.district_code || null,
    opened_at:                r.opened_at || r.created_at || null,
    dispositioned_at:         r.dispositioned_at || null,
    closed_at:                r.closed_at || null,
    opener_name:              r.opener_name || null,
    notification_status:      r.notification_status || null,
    notification_priority:    r.notification_priority || null,
    notification_id:          r.notification_id || null,
    primary_screening_id:     r.primary_screening_id || null,
    primary_temp_value:       r.primary_temp_value || null,
    top_disease:              r.top_disease || null,
    actions_done_count:       r.actions_done_count || 0,
    alert:                    r.alert || null,
    sync_status:              r.sync_status || SYNC.UNSYNCED,
    synced_at:                r.synced_at || null,
    sync_attempt_count:       r.sync_attempt_count || 0,
    last_sync_error:          r.last_sync_error || null,
    record_version:           r.record_version || 1,
    server_received_at:       r.server_received_at || null,
    updated_at:               r.updated_at || null,
    _fromCache:               true,
  }
}

// ─── SERVER FETCH ─────────────────────────────────────────────────────────────
async function fetchFromServer(pg = 1, updatedAfter = null) {
  const userId = auth.value?.id
  if (!userId) return null

  const p = new URLSearchParams({ user_id: userId, page: pg, per_page: SERVER_PAGE_SIZE })

  if (statusFilter.value) p.set('case_status', statusFilter.value)
  if (riskFilter.value)   p.set('risk_level',  riskFilter.value)
  if (synFilter.value)    p.set('syndrome',     synFilter.value)
  if (dispFilter.value)   p.set('final_disposition', dispFilter.value)
  if (searchQuery.value)  p.set('search',       searchQuery.value.trim())
  if (showUnsynced.value) p.set('sync_status',  'UNSYNCED')

  const range = dateFromPreset()
  if (range.from) p.set('date_from', range.from.toISOString().slice(0,10))
  if (range.to)   p.set('date_to',   range.to.toISOString().slice(0,10))

  if (updatedAfter) p.set('updated_after', updatedAfter)

  const ctrl = new AbortController()
  const tid  = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS)
  try {
    const res = await fetch(`${window.SERVER_URL}/screening-records?${p}`, {
      headers: { Accept: 'application/json' }, signal: ctrl.signal,
    })
    clearTimeout(tid)
    if (!res.ok) return null
    const j = await res.json()
    return j.success ? j.data : null
  } catch { clearTimeout(tid); return null }
}

// ─── WRITE-THROUGH CACHE ──────────────────────────────────────────────────────
async function writeServerItemsToIdb(serverItems) {
  for (const s of serverItems) {
    if (!s.client_uuid) continue
    // Don't write no-name CLOSED/DISPOSITIONED records back to IDB — they're damaged
    const noName = !s.traveler_full_name || (typeof s.traveler_full_name === 'string' && s.traveler_full_name.trim() === '')
    if (noName && (s.case_status === 'CLOSED' || s.case_status === 'DISPOSITIONED')) continue
    try {
      const existing = await dbGet(STORE.SECONDARY_SCREENINGS, s.client_uuid)
      const incomingVersion = s.record_version ?? 1

      // Don't overwrite quarantined records — the server doesn't know about
      // client-side sync failures. Overwriting would reset sync_attempt_count
      // and bring damaged records back into the live set.
      if (existing && (existing.sync_attempt_count || 0) >= QUARANTINE_MAX_ATTEMPTS) continue

      if (!existing) {
        await dbPut(STORE.SECONDARY_SCREENINGS, toPlain({
          client_uuid:                s.client_uuid,
          id:                         s.id,
          server_id:                  s.id,
          reference_data_version:     s.reference_data_version || APP.REFERENCE_DATA_VER,
          server_received_at:         s.server_received_at || null,
          country_code:               s.country_code || auth.value?.country_code || null,
          province_code:              s.province_code || null,
          pheoc_code:                 s.pheoc_code || null,
          district_code:              s.district_code || null,
          poe_code:                   s.poe_code,
          primary_screening_id:       s.primary_screening_id || null,
          notification_id:            s.notification_id || null,
          opened_by_user_id:          s.opened_by_user_id || null,
          case_status:                s.case_status || 'OPEN',
          traveler_full_name:         s.traveler_full_name || null,
          traveler_gender:            s.traveler_gender || null,
          traveler_age_years:         s.traveler_age_years ?? null,
          traveler_nationality_country_code: s.traveler_nationality_country_code || null,
          temperature_value:          s.temperature_value ?? null,
          temperature_unit:           s.temperature_unit || null,
          risk_level:                 s.risk_level || null,
          syndrome_classification:    s.syndrome_classification || null,
          final_disposition:          s.final_disposition || null,
          followup_required:          s.followup_required ? 1 : 0,
          followup_assigned_level:    s.followup_assigned_level || null,
          triage_category:            s.triage_category || null,
          emergency_signs_present:    s.emergency_signs_present ? 1 : 0,
          officer_notes:              s.officer_notes || null,
          disposition_details:        s.disposition_details || null,
          opened_at:                  s.opened_at || null,
          dispositioned_at:           s.dispositioned_at || null,
          closed_at:                  s.closed_at || null,
          opener_name:                s.opener_name || null,
          opener_role:                s.opener_role || null,
          notification_status:        s.notification_status || null,
          notification_priority:      s.notification_priority || null,
          primary_temp_value:         s.primary_temp_value || null,
          top_disease:                s.top_disease || null,
          actions_done_count:         s.actions_done_count || 0,
          sync_status:                SYNC.SYNCED,
          synced_at:                  isoNow(),
          sync_attempt_count:         s.sync_attempt_count || 0,
          last_sync_error:            null,
          record_version:             incomingVersion,
          device_id:                  s.device_id || 'SERVER',
          app_version:                s.app_version || null,
          platform:                   s.platform || 'WEB',
          opened_timezone:            s.opened_timezone || null,
          created_at:                 s.created_at || isoNow(),
          updated_at:                 s.updated_at || isoNow(),
          opener_username:            s.opener_username || null,
          opener_phone:               s.opener_phone || null,
          opener_email:               s.opener_email || null,
          notification_reason_code:   s.notification_reason_code || null,
          notification_reason_text:   s.notification_reason_text || null,
          notification_opened_at:     s.notification_opened_at || null,
          notification_closed_at:     s.notification_closed_at || null,
          notification_assigned_role: s.notification_assigned_role || null,
          primary_symptoms_present:   s.primary_symptoms_present ?? null,
          primary_referral_created:   s.primary_referral_created ?? null,
          primary_captured_timezone:  s.primary_captured_timezone || null,
          primary_poe_code:           s.primary_poe_code || null,
          primary_sync_status:        s.primary_sync_status || null,
        }))
      } else {
        const storedVersion = existing.record_version ?? 0
        if (incomingVersion > storedVersion) {
          await safeDbPut(STORE.SECONDARY_SCREENINGS, toPlain({
            ...existing,
            id:                      s.id,
            server_id:               s.id,
            server_received_at:      s.server_received_at || existing.server_received_at,
            case_status:             s.case_status,
            risk_level:              s.risk_level,
            syndrome_classification: s.syndrome_classification,
            final_disposition:       s.final_disposition,
            followup_required:       s.followup_required ? 1 : 0,
            followup_assigned_level: s.followup_assigned_level || null,
            triage_category:         s.triage_category || existing.triage_category,
            officer_notes:           s.officer_notes || existing.officer_notes,
            disposition_details:     s.disposition_details || existing.disposition_details,
            opened_at:               s.opened_at || existing.opened_at,
            dispositioned_at:        s.dispositioned_at || existing.dispositioned_at,
            closed_at:               s.closed_at || existing.closed_at,
            opener_name:             s.opener_name || existing.opener_name,
            opener_role:             s.opener_role || existing.opener_role,
            notification_status:     s.notification_status || existing.notification_status,
            notification_priority:   s.notification_priority || existing.notification_priority,
            primary_temp_value:      s.primary_temp_value ?? existing.primary_temp_value,
            top_disease:             s.top_disease || existing.top_disease,
            actions_done_count:      s.actions_done_count ?? existing.actions_done_count,
            sync_status:             existing.sync_status === SYNC.UNSYNCED
                                       ? SYNC.UNSYNCED : SYNC.SYNCED,
            record_version:          incomingVersion,
            updated_at:              s.updated_at || isoNow(),
            opener_username:         s.opener_username || existing.opener_username,
            opener_phone:            s.opener_phone || existing.opener_phone,
            opener_email:            s.opener_email || existing.opener_email,
            opened_timezone:         s.opened_timezone || existing.opened_timezone,
            reference_data_version:  s.reference_data_version || existing.reference_data_version,
            server_received_at:      s.server_received_at || existing.server_received_at,
            app_version:             s.app_version || existing.app_version,
            notification_reason_code: s.notification_reason_code || existing.notification_reason_code,
            notification_reason_text: s.notification_reason_text || existing.notification_reason_text,
            notification_opened_at:  s.notification_opened_at || existing.notification_opened_at,
            notification_closed_at:  s.notification_closed_at || existing.notification_closed_at,
            notification_assigned_role: s.notification_assigned_role || existing.notification_assigned_role,
            primary_symptoms_present: s.primary_symptoms_present ?? existing.primary_symptoms_present,
            primary_referral_created: s.primary_referral_created ?? existing.primary_referral_created,
            primary_captured_timezone: s.primary_captured_timezone || existing.primary_captured_timezone,
            primary_poe_code:        s.primary_poe_code || existing.primary_poe_code,
            primary_sync_status:     s.primary_sync_status || existing.primary_sync_status,
          }))
        }
      }
    } catch (e) {
      console.warn('[SSR] writeServerItemsToIdb error', s.client_uuid, e?.message)
    }
  }
}

// ─── MERGE INTO WINDOW ────────────────────────────────────────────────────────
function mergeIntoWindow(serverItems) {
  const qUuids = new Set(quarantinedRecords.value.map(q => q.client_uuid))
  const byUuid = new Map(allItems.value.map(i => [i.client_uuid, i]))

  for (const s of serverItems) {
    const uuid = s.client_uuid
    if (!uuid || qUuids.has(uuid)) continue
    // Skip no-name CLOSED/DISPOSITIONED records — these are damaged and should be purged
    const noName = !s.traveler_full_name || (typeof s.traveler_full_name === 'string' && s.traveler_full_name.trim() === '')
    if (noName && (s.case_status === 'CLOSED' || s.case_status === 'DISPOSITIONED')) continue
    const existing = byUuid.get(uuid)
    byUuid.set(uuid, {
      id:                      s.id,
      client_uuid:             uuid,
      case_status:             s.case_status,
      risk_level:              s.risk_level,
      syndrome_classification: s.syndrome_classification,
      final_disposition:       s.final_disposition,
      followup_required:       !!s.followup_required,
      triage_category:         s.triage_category,
      emergency_signs_present: !!s.emergency_signs_present,
      traveler_full_name:      s.traveler_full_name,
      traveler_gender:         s.traveler_gender,
      traveler_age_years:      s.traveler_age_years,
      traveler_nationality_country_code: s.traveler_nationality_country_code,
      temperature_value:       s.temperature_value,
      temperature_unit:        s.temperature_unit,
      poe_code:                s.poe_code,
      district_code:           s.district_code,
      opened_at:               s.opened_at,
      dispositioned_at:        s.dispositioned_at,
      closed_at:               s.closed_at,
      opener_name:             s.opener_name,
      notification_status:     s.notification_status,
      notification_priority:   s.notification_priority,
      notification_id:         s.notification_id,
      primary_screening_id:    s.primary_screening_id,
      primary_temp_value:      s.primary_temp_value,
      top_disease:             s.top_disease,
      actions_done_count:      s.actions_done_count || 0,
      alert:                   s.alert,
      sync_status:             existing?.sync_status === SYNC.UNSYNCED ? SYNC.UNSYNCED : SYNC.SYNCED,
      synced_at:               existing?.synced_at || null,
      sync_attempt_count:      existing?.sync_attempt_count || 0,
      last_sync_error:         existing?.last_sync_error || null,
      record_version:          s.record_version || 1,
      server_received_at:      s.server_received_at || null,
      updated_at:              s.updated_at || null,
      _fromCache:              false,
    })
  }

  const RISK_ORD = { CRITICAL:0, HIGH:1, MEDIUM:2, LOW:3 }
  let sorted = Array.from(byUuid.values()).sort((a,b) => {
    const rd = (RISK_ORD[a.risk_level]??9) - (RISK_ORD[b.risk_level]??9)
    if (rd !== 0) return rd
    return new Date(b.opened_at||0) - new Date(a.opened_at||0)
  })

  if (sorted.length > MAX_WINDOW) sorted = sorted.slice(0, MAX_WINDOW)
  allItems.value = sorted
}

// ─── LOAD ─────────────────────────────────────────────────────────────────────
async function load() {
  loading.value       = true
  idbPageOffset.value = 0
  serverPage.value    = 1
  hasMoreIdb.value    = true
  hasMoreServer.value = true

  try {
    // ── Phase 1: Scan for damaged/corrupt records FIRST ──────────────
    // Must run BEFORE reading pages so that purged/quarantined records
    // don't appear in the page read and don't inflate counts.
    await scanForDamagedRecords()

    // ── Phase 2: Read first IDB page (instant, works offline) ────────
    const idbPage = await readIdbPage(0)

    if (idbPage.length > 0) {
      allItems.value      = idbPage
      idbPageOffset.value = IDB_PAGE_SIZE
      hasMoreIdb.value    = idbPage.length === IDB_PAGE_SIZE
      loading.value       = false
    }

    // ── Phase 3: Compute stats from IDB (after purge, after page read)
    await refreshIdbStats()

    // ── Phase 4: Enrich from server (if online) ──────────────────────
    if (isOnline.value) {
      const data = await fetchFromServer(1)
      if (data) {
        totalOnServer.value = data.total || 0
        hasMoreServer.value = (data.page ?? 1) < (data.pages ?? 1)
        serverPage.value    = 2

        // Write server records to IDB (awaited so stats read fresh data)
        await writeServerItemsToIdb(data.items || [])
        mergeIntoWindow(data.items || [])
        localStorage.setItem(LAST_SYNC_KEY, isoNow())

        // Recompute stats AFTER write-through so counts are consistent
        await refreshIdbStats()
      }
    }
  } finally {
    loading.value = false
  }
}

async function loadMore() {
  if (loadingMore.value) return
  loadingMore.value = true
  try {
    if (hasMoreIdb.value) {
      const idbPage = await readIdbPage(idbPageOffset.value)
      if (idbPage.length > 0) {
        const combined = [...allItems.value, ...idbPage]
        const RISK_ORD = { CRITICAL:0, HIGH:1, MEDIUM:2, LOW:3 }
        const sorted = combined
          .filter((item, idx, arr) => arr.findIndex(x => x.client_uuid === item.client_uuid) === idx)
          .sort((a,b) => {
            const rd = (RISK_ORD[a.risk_level]??9) - (RISK_ORD[b.risk_level]??9)
            if (rd !== 0) return rd
            return new Date(b.opened_at||0) - new Date(a.opened_at||0)
          })
          .slice(0, MAX_WINDOW)
        allItems.value      = sorted
        idbPageOffset.value += IDB_PAGE_SIZE
        hasMoreIdb.value     = idbPage.length === IDB_PAGE_SIZE
        return
      } else {
        hasMoreIdb.value = false
      }
    }
    if (hasMoreServer.value && isOnline.value) {
      const data = await fetchFromServer(serverPage.value)
      if (data) {
        totalOnServer.value = data.total || 0
        hasMoreServer.value = (data.page ?? 1) < (data.pages ?? 1)
        serverPage.value++
        writeServerItemsToIdb(data.items || []).catch(() => {})
        mergeIntoWindow(data.items || [])
        refreshIdbStats().catch(() => {})
      }
    }
  } finally {
    loadingMore.value = false
  }
}

async function reload() { await load() }
async function onPullRefresh(ev) { await load(); ev.target.complete() }

// ─── BACKGROUND SYNC ─────────────────────────────────────────────────────────
async function backgroundServerSync(debounceMs = 0) {
  if (!isOnline.value || syncing.value) return
  if (bgSyncDebounce) clearTimeout(bgSyncDebounce)
  bgSyncDebounce = setTimeout(async () => {
    bgSyncDebounce = null
    try {
      const lastSync = localStorage.getItem(LAST_SYNC_KEY) || null
      const data = await fetchFromServer(1, lastSync)
      if (!data) return
      const items = data.items || []
      if (!items.length) return
      await writeServerItemsToIdb(items)
      mergeIntoWindow(items)
      localStorage.setItem(LAST_SYNC_KEY, isoNow())
      // Scan for damaged BEFORE stats so quarantined records are excluded from counts
      await scanForDamagedRecords()
      await refreshIdbStats()
    } catch (e) {
      console.warn('[SSR] backgroundServerSync error', e?.message)
    }
  }, debounceMs)
}

// ─── DETAIL MODAL ─────────────────────────────────────────────────────────────
async function openDetail(item) {
  detailRecord.value = { ...item }
  modalTab.value     = 'overview'
  modalOpen.value    = true
  await loadDetailFull(item)
}

async function loadDetailFull(item) {
  detailLoading.value = true
  try {
    const uuid = item.client_uuid
    const sid  = item.id

    const [symptoms, exposures, actions, samples, travelCountries, diseases] = await Promise.all([
      dbGetByIndex(STORE.SECONDARY_SYMPTOMS,          'secondary_screening_id', uuid).catch(()=>[]),
      dbGetByIndex(STORE.SECONDARY_EXPOSURES,         'secondary_screening_id', uuid).catch(()=>[]),
      dbGetByIndex(STORE.SECONDARY_ACTIONS,           'secondary_screening_id', uuid).catch(()=>[]),
      dbGetByIndex(STORE.SECONDARY_SAMPLES,           'secondary_screening_id', uuid).catch(()=>[]),
      dbGetByIndex(STORE.SECONDARY_TRAVEL_COUNTRIES,  'secondary_screening_id', uuid).catch(()=>[]),
      dbGetByIndex(STORE.SECONDARY_SUSPECTED_DISEASES,'secondary_screening_id', uuid).catch(()=>[]),
    ])

    const notifId = item.notification_id
    const [notif, primarySc, fullCase] = await Promise.all([
      notifId ? dbGet(STORE.NOTIFICATIONS, notifId).catch(()=>null) : Promise.resolve(null),
      item.primary_screening_id
        ? dbGet(STORE.PRIMARY_SCREENINGS, item.primary_screening_id).catch(()=>null)
        : Promise.resolve(null),
      dbGet(STORE.SECONDARY_SCREENINGS, uuid).catch(()=>null),
    ])

    detailRecord.value = {
      ...(fullCase || item), ...item,
      symptoms:           symptoms.map(normaliseChild),
      exposures:          exposures.map(normaliseChild),
      actions:            actions.map(normaliseChild),
      samples:            samples.map(normaliseChild),
      travel_countries:   travelCountries.map(normaliseChild),
      suspected_diseases: diseases.map(d => ({...normaliseChild(d), rank_order: d.rank_order||1}))
                            .sort((a,b) => a.rank_order - b.rank_order),
      notification:       notif || null,
      primary_screening:  primarySc || null,
      alert:              item.alert || null,
      id:                 fullCase?.id ?? fullCase?.server_id ?? item.id ?? null,
    }

    if (isOnline.value && sid) {
      fetchDetailFromServer(sid).then(serverDetail => {
        if (!serverDetail || !detailRecord.value) return
        if (detailRecord.value.client_uuid !== uuid) return

        detailRecord.value = {
          ...detailRecord.value,
          opener_name:       serverDetail.opener_name    || detailRecord.value.opener_name,
          opener_role:       serverDetail.opener_role,
          notification:      serverDetail.notification   || detailRecord.value.notification,
          primary_screening: serverDetail.primary_screening || detailRecord.value.primary_screening,
          alert:             serverDetail.alert          || detailRecord.value.alert,
          server_received_at:serverDetail.server_received_at,
          symptoms:          serverDetail.symptoms?.length     ? serverDetail.symptoms     : detailRecord.value.symptoms,
          exposures:         serverDetail.exposures?.length    ? serverDetail.exposures    : detailRecord.value.exposures,
          actions:           serverDetail.actions?.length      ? serverDetail.actions      : detailRecord.value.actions,
          samples:           serverDetail.samples?.length      ? serverDetail.samples      : detailRecord.value.samples,
          travel_countries:  serverDetail.travel_countries?.length ? serverDetail.travel_countries : detailRecord.value.travel_countries,
          suspected_diseases:serverDetail.suspected_diseases?.length ? serverDetail.suspected_diseases : detailRecord.value.suspected_diseases,
        }

        if (serverDetail) {
          const enriched = toPlain({ ...(detailRecord.value), ...serverDetail, sync_status: SYNC.SYNCED })
          safeDbPut(STORE.SECONDARY_SCREENINGS, enriched).catch(()=>{})

          const childUuid = detailRecord.value?.client_uuid
          if (childUuid) {
            if (serverDetail.symptoms?.length) {
              const recs = serverDetail.symptoms.map(s => ({ ...s, client_uuid: s.id ? `srv-sym-${s.id}` : genUuid(), secondary_screening_id: childUuid, sync_status: SYNC.SYNCED }))
              dbReplaceAll(STORE.SECONDARY_SYMPTOMS, 'secondary_screening_id', childUuid, toPlain(recs)).catch(()=>{})
            }
            if (serverDetail.exposures?.length) {
              const recs = serverDetail.exposures.map(e => ({ ...e, client_uuid: e.id ? `srv-exp-${e.id}` : genUuid(), secondary_screening_id: childUuid, sync_status: SYNC.SYNCED }))
              dbReplaceAll(STORE.SECONDARY_EXPOSURES, 'secondary_screening_id', childUuid, toPlain(recs)).catch(()=>{})
            }
            if (serverDetail.actions?.length) {
              const recs = serverDetail.actions.map(a => ({ ...a, client_uuid: a.id ? `srv-act-${a.id}` : genUuid(), secondary_screening_id: childUuid, sync_status: SYNC.SYNCED }))
              dbReplaceAll(STORE.SECONDARY_ACTIONS, 'secondary_screening_id', childUuid, toPlain(recs)).catch(()=>{})
            }
            if (serverDetail.samples?.length) {
              const recs = serverDetail.samples.map(s => ({ ...s, client_uuid: s.id ? `srv-smp-${s.id}` : genUuid(), secondary_screening_id: childUuid, sync_status: SYNC.SYNCED }))
              dbReplaceAll(STORE.SECONDARY_SAMPLES, 'secondary_screening_id', childUuid, toPlain(recs)).catch(()=>{})
            }
            if (serverDetail.travel_countries?.length) {
              const recs = serverDetail.travel_countries.map(t => ({ ...t, client_uuid: t.id ? `srv-tc-${t.id}` : genUuid(), secondary_screening_id: childUuid, sync_status: SYNC.SYNCED }))
              dbReplaceAll(STORE.SECONDARY_TRAVEL_COUNTRIES, 'secondary_screening_id', childUuid, toPlain(recs)).catch(()=>{})
            }
            if (serverDetail.suspected_diseases?.length) {
              const recs = serverDetail.suspected_diseases.map(d => ({ ...d, client_uuid: d.id ? `srv-sd-${d.id}` : genUuid(), secondary_screening_id: childUuid, sync_status: SYNC.SYNCED }))
              dbReplaceAll(STORE.SECONDARY_SUSPECTED_DISEASES, 'secondary_screening_id', childUuid, toPlain(recs)).catch(()=>{})
            }
          }
        }
      }).catch(()=>{})
    }
  } catch (e) {
    console.error('[SSR] loadDetailFull error', e?.message)
  } finally {
    detailLoading.value = false
  }
}

function normaliseChild(r) { return JSON.parse(JSON.stringify(toRaw(r))) }

async function fetchDetailFromServer(serverId) {
  const userId = auth.value?.id
  if (!userId || !serverId) return null
  const ctrl = new AbortController()
  const tid  = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS)
  try {
    const res = await fetch(`${window.SERVER_URL}/screening-records/${serverId}?user_id=${userId}`,
      { headers: { Accept:'application/json' }, signal: ctrl.signal })
    clearTimeout(tid)
    if (!res.ok) return null
    const j = await res.json()
    return j.success ? j.data : null
  } catch { clearTimeout(tid); return null }
}

function dismissModal() { modalOpen.value = false }
function closeDetail()  { detailRecord.value = null; detailLoading.value = false }

// ─── SYNC ENGINE ──────────────────────────────────────────────────────────────
async function syncOneRecord(item) {
  if (!isOnline.value) { showToast('Device is offline — cannot sync now.', 'warning'); return }
  const uuid = item.client_uuid
  if (!uuid) return

  const next = new Set(syncingUuids.value); next.add(uuid); syncingUuids.value = next

  try {
    const a = auth.value; const userId = a?.id
    if (!userId) throw new Error('No auth user_id')
    const rec = await dbGet(STORE.SECONDARY_SCREENINGS, uuid)
    if (!rec) throw new Error('Record not found in IDB')

    let serverId = rec.id ?? rec.server_id ?? null

    // Phase 1: create on server
    if (!serverId) {
      const r1 = await timedFetch(`${window.SERVER_URL}/secondary-screenings`, {
        method:'POST',
        headers:{'Content-Type':'application/json', Accept:'application/json'},
        body: JSON.stringify(buildPhase1Payload(rec, userId)),
      })
      const b1 = await r1.json().catch(()=>({}))
      if (!r1.ok || !b1.success) {
        await markSyncFailed(uuid, rec, b1?.message || `HTTP ${r1.status}`)
        showToast(`Sync failed (Phase 1): ${b1?.message || r1.status}`, 'danger'); return
      }
      serverId = b1.data?.id
      if (!serverId) { await markSyncFailed(uuid, rec, 'No server id'); showToast('Sync error: no server id.', 'danger'); return }
      await safeDbPut(STORE.SECONDARY_SCREENINGS, toPlain({...rec, id:serverId, server_id:serverId, updated_at:isoNow()}))
    }

    // Phase 1.5: advance status
    const caseStatus = rec.case_status || 'OPEN'
    if (['IN_PROGRESS','DISPOSITIONED','CLOSED'].includes(caseStatus)) {
      const r15 = await timedFetch(`${window.SERVER_URL}/secondary-screenings/${serverId}/sync`, {
        method:'POST', headers:{'Content-Type':'application/json', Accept:'application/json'},
        body: JSON.stringify({ case_status:'IN_PROGRESS', user_id: userId }),
      })
      if (!r15.ok && r15.status !== 409) console.warn('[SSR] Phase 1.5 non-fatal', r15.status)
    }

    // Phase 2: full sync
    const [symptoms, exposures, actions, samples, travelCountries, diseases] = await Promise.all([
      dbGetByIndex(STORE.SECONDARY_SYMPTOMS,          'secondary_screening_id', uuid).catch(()=>[]),
      dbGetByIndex(STORE.SECONDARY_EXPOSURES,         'secondary_screening_id', uuid).catch(()=>[]),
      dbGetByIndex(STORE.SECONDARY_ACTIONS,           'secondary_screening_id', uuid).catch(()=>[]),
      dbGetByIndex(STORE.SECONDARY_SAMPLES,           'secondary_screening_id', uuid).catch(()=>[]),
      dbGetByIndex(STORE.SECONDARY_TRAVEL_COUNTRIES,  'secondary_screening_id', uuid).catch(()=>[]),
      dbGetByIndex(STORE.SECONDARY_SUSPECTED_DISEASES,'secondary_screening_id', uuid).catch(()=>[]),
    ])

    const r2 = await timedFetch(`${window.SERVER_URL}/secondary-screenings/${serverId}/sync`, {
      method:'POST', headers:{'Content-Type':'application/json', Accept:'application/json'},
      body: JSON.stringify(buildPhase2Payload(rec, userId, caseStatus, symptoms, exposures, actions, samples, travelCountries, diseases)),
    })
    const b2 = await r2.json().catch(()=>({}))
    const alreadyClosed = r2.status === 409 && (b2?.message ?? '').toLowerCase().includes('closed')

    if (r2.ok || alreadyClosed) {
      const freshRec = await dbGet(STORE.SECONDARY_SCREENINGS, uuid)
      const synced = toPlain({
        ...(freshRec || rec), id:serverId, server_id:serverId,
        case_status: alreadyClosed ? 'CLOSED' : (b2?.data?.case_status || caseStatus),
        sync_status: SYNC.SYNCED, synced_at: isoNow(), last_sync_error: null,
        sync_attempt_count: 0,
        record_version: ((freshRec || rec).record_version || 1) + 1, updated_at: isoNow(),
      })
      await safeDbPut(STORE.SECONDARY_SCREENINGS, synced)

      const idx = allItems.value.findIndex(i => i.client_uuid === uuid)
      if (idx !== -1) { allItems.value[idx] = {...allItems.value[idx], sync_status:SYNC.SYNCED, id:serverId}; allItems.value = [...allItems.value] }
      if (detailRecord.value?.client_uuid === uuid) detailRecord.value = {...detailRecord.value, sync_status:SYNC.SYNCED, id:serverId, synced_at:isoNow(), last_sync_error:null}

      await refreshIdbStats()
      showToast(alreadyClosed ? 'Reconciled — already closed on server.' : 'Record synced.', 'success')
    } else {
      await markSyncFailed(uuid, rec, b2?.message || `HTTP ${r2.status}`)
      showToast(`Sync failed: ${b2?.message || r2.status}`, 'danger')
    }
  } catch (e) {
    const rec = await dbGet(STORE.SECONDARY_SCREENINGS, uuid).catch(()=>null)
    if (rec) await markSyncFailed(uuid, rec, e?.message || 'Unknown error')
    showToast(`Sync error: ${e?.message || 'Unknown'}`, 'danger')
  } finally {
    const next2 = new Set(syncingUuids.value); next2.delete(uuid); syncingUuids.value = next2
    // Check if the record should now be quarantined
    await scanForDamagedRecords()
  }
}

async function syncAllPending() {
  if (!isOnline.value) { showToast('Device is offline.', 'warning'); return }
  const pending = allItems.value.filter(i => i.sync_status !== SYNC.SYNCED)
  if (!pending.length) { showToast('All records are already synced.', 'success'); return }
  syncing.value = true
  let ok = 0, fail = 0
  for (const item of pending) {
    await syncOneRecord(item)
    const u = allItems.value.find(i => i.client_uuid === item.client_uuid)
    if (u?.sync_status === SYNC.SYNCED) ok++; else fail++
  }
  syncing.value = false
  showToast(`Sync complete — ${ok} synced${fail ? `, ${fail} failed` : ''}.`, fail ? 'warning' : 'success')
}

// ─── PAYLOAD BUILDERS ─────────────────────────────────────────────────────────
function buildPhase1Payload(rec, userId) {
  return {
    client_uuid:            rec.client_uuid,
    idempotency_key:        rec.idempotency_key || rec.client_uuid,
    reference_data_version: rec.reference_data_version || APP.REFERENCE_DATA_VER,
    // SecondaryScreeningController::store reads opened_by_user_id, not user_id.
    // Sending the wrong key caused every retry from this view to 422.
    opened_by_user_id:      userId,
    notification_id:        rec.notification_id,
    primary_screening_id:   rec.primary_screening_id,
    poe_code:               rec.poe_code,
    district_code:          rec.district_code,
    province_code:          rec.province_code || null,
    pheoc_code:             rec.pheoc_code    || null,
    country_code:           rec.country_code  || auth.value?.country_code || '',
    opened_at:              rec.opened_at     || rec.created_at,
    opened_timezone:        rec.opened_timezone || Intl.DateTimeFormat().resolvedOptions().timeZone,
    device_id:              rec.device_id     || 'WEB',
    app_version:            rec.app_version   || null,
    platform:               rec.platform      || 'WEB',
  }
}
function buildPhase2Payload(rec, userId, caseStatus, symptoms, exposures, actions, samples, travelCountries, diseases) {
  return {
    user_id: userId, case_status: caseStatus,
    traveler_full_name: rec.traveler_full_name||null, traveler_initials: rec.traveler_initials||null,
    traveler_anonymous_code: rec.traveler_anonymous_code||null,
    travel_document_type: rec.travel_document_type||null, travel_document_number: rec.travel_document_number||null,
    traveler_gender: rec.traveler_gender, traveler_age_years: rec.traveler_age_years??null,
    traveler_dob: rec.traveler_dob||null, traveler_nationality_country_code: rec.traveler_nationality_country_code||null,
    traveler_occupation: rec.traveler_occupation||null, residence_country_code: rec.residence_country_code||null,
    residence_address_text: rec.residence_address_text||null, phone_number: rec.phone_number||null,
    alternative_phone: rec.alternative_phone||null, email: rec.email||null,
    destination_address_text: rec.destination_address_text||null, destination_district_code: rec.destination_district_code||null,
    emergency_contact_name: rec.emergency_contact_name||null, emergency_contact_phone: rec.emergency_contact_phone||null,
    journey_start_country_code: rec.journey_start_country_code||null, embarkation_port_city: rec.embarkation_port_city||null,
    conveyance_type: rec.conveyance_type||null, conveyance_identifier: rec.conveyance_identifier||null,
    seat_number: rec.seat_number||null, arrival_datetime: rec.arrival_datetime||null,
    departure_datetime: rec.departure_datetime||null, purpose_of_travel: rec.purpose_of_travel||null,
    planned_length_of_stay_days: rec.planned_length_of_stay_days??null,
    triage_category: rec.triage_category||null, emergency_signs_present: rec.emergency_signs_present?1:0,
    general_appearance: rec.general_appearance||null, temperature_value: rec.temperature_value??null,
    temperature_unit: rec.temperature_unit||null, pulse_rate: rec.pulse_rate??null,
    respiratory_rate: rec.respiratory_rate??null, bp_systolic: rec.bp_systolic??null,
    bp_diastolic: rec.bp_diastolic??null, oxygen_saturation: rec.oxygen_saturation??null,
    syndrome_classification: rec.syndrome_classification||null, risk_level: rec.risk_level||null,
    officer_notes: rec.officer_notes||null, final_disposition: rec.final_disposition||null,
    disposition_details: rec.disposition_details||null, followup_required: rec.followup_required?1:0,
    followup_assigned_level: rec.followup_assigned_level||null,
    dispositioned_at: rec.dispositioned_at||null, closed_at: rec.closed_at||null,
    symptoms: symptoms.map(s=>({symptom_code:s.symptom_code, is_present:s.is_present?1:0, onset_date:s.onset_date||null, details:s.details||null})),
    exposures: exposures.map(e=>({exposure_code:e.exposure_code, response:e.response||'UNKNOWN', details:e.details||null})),
    actions: actions.map(a=>({action_code:a.action_code, is_done:a.is_done?1:0, details:a.details||null})),
    samples: samples.filter(s=>s.sample_collected).map(s=>({sample_collected:1, sample_type:s.sample_type||null, sample_identifier:s.sample_identifier||null, lab_destination:s.lab_destination||null, collected_at:s.collected_at||null})),
    travel_countries: travelCountries.map(tc=>({country_code:tc.country_code, travel_role:tc.travel_role||'VISITED', arrival_date:tc.arrival_date||null, departure_date:tc.departure_date||null})),
    suspected_diseases: diseases.map(d=>({disease_code:d.disease_code, rank_order:d.rank_order||1, confidence:d.confidence??null, reasoning:d.reasoning||null})),
  }
}

async function markSyncFailed(uuid, rec, errorMsg) {
  const newAttemptCount = (rec.sync_attempt_count || 0) + 1
  await safeDbPut(STORE.SECONDARY_SCREENINGS, toPlain({
    ...rec, sync_status: SYNC.FAILED, last_sync_error: errorMsg,
    sync_attempt_count: newAttemptCount, updated_at: isoNow(),
  }))
  const idx = allItems.value.findIndex(i => i.client_uuid === uuid)
  if (idx !== -1) { allItems.value[idx] = {...allItems.value[idx], sync_status:SYNC.FAILED, last_sync_error:errorMsg, sync_attempt_count:newAttemptCount}; allItems.value=[...allItems.value] }
  if (detailRecord.value?.client_uuid === uuid) detailRecord.value = {...detailRecord.value, sync_status:SYNC.FAILED, last_sync_error:errorMsg, sync_attempt_count:newAttemptCount}
  await refreshIdbStats()
}

async function timedFetch(url, opts={}) {
  const ctrl = new AbortController()
  const tid  = setTimeout(()=>ctrl.abort(), APP.SYNC_TIMEOUT_MS)
  try { const r = await fetch(url, {...opts, signal:ctrl.signal}); clearTimeout(tid); return r }
  catch (e) { clearTimeout(tid); throw e }
}

// ─── FILTER ACTIONS ────────────────────────────────────────────────────────────
function quickStatus(v) { statusFilter.value = statusFilter.value===v ? null : v; filtersOpen.value=false; reload() }
function quickRisk(v)   { riskFilter.value   = riskFilter.value===v   ? null : v; filtersOpen.value=false; reload() }
function filterUnsyncedOnly() { showUnsynced.value = !showUnsynced.value; statusFilter.value=null; reload() }
function clearAllFilters() {
  statusFilter.value=null; riskFilter.value=null; synFilter.value=null
  dispFilter.value=null; datePreset.value='all'; showUnsynced.value=false
  filtersOpen.value=false; searchQuery.value=''; customDateFrom.value=''
  customDateTo.value=''; monthFilter.value=null; yearFilter.value=null
  reload()
}

let searchDebounce = null
watch(searchQuery, () => { clearTimeout(searchDebounce); searchDebounce = setTimeout(reload, 350) })

// ─── HELPERS ─────────────────────────────────────────────────────────────────
function showToast(msg, color='success') { Object.assign(toast, { show:true, msg, color }) }
function statusKey(s)    { return (s||'open').toLowerCase().replace('_','-') }
function avatarLetter(item) {
  const name = item.traveler_full_name
  if (name) return name.charAt(0).toUpperCase()
  const g = item.traveler_gender
  if (g === 'MALE') return 'M'
  if (g === 'FEMALE') return 'F'
  return '?'
}
function riskCardClass(rl) {
  if (rl==='CRITICAL') return 'sr-card--critical'
  if (rl==='HIGH')     return 'sr-card--high'
  if (rl==='MEDIUM')   return 'sr-card--medium'
  return 'sr-card--low'
}
function tempChipClass(val, unit) {
  const c = unit==='F' ? (val-32)*5/9 : val
  if (c >= 38.5) return 'sr-temp--fever'
  if (c >= 37.5) return 'sr-temp--low'
  return 'sr-temp--normal'
}
function tempWarn(val, unit) {
  if (val==null) return null
  const c = unit==='F' ? (val-32)*5/9 : Number(val)
  if (c >= 39.5) return 'High fever'
  if (c >= 38.5) return 'Fever'
  if (c >= 37.5) return 'Low-grade'
  if (c < 36.0)  return 'Hypothermia'
  return null
}
function tempVitalClass(val, unit) {
  if (val==null) return ''
  const c = unit==='F' ? (val-32)*5/9 : Number(val)
  if (c >= 38.5) return 'sr-vital--danger'
  if (c >= 37.5) return 'sr-vital--warn'
  return 'sr-vital--ok'
}
function pulseVitalClass(p) { if(p==null)return''; if(p>120||p<50)return'sr-vital--danger'; if(p>100||p<60)return'sr-vital--warn'; return'sr-vital--ok' }
function rrVitalClass(r) { if(r==null)return''; if(r>30||r<8)return'sr-vital--danger'; if(r>24||r<12)return'sr-vital--warn'; return'sr-vital--ok' }
function spo2VitalClass(s) { if(s==null)return''; if(s<90)return'sr-vital--danger'; if(s<94)return'sr-vital--warn'; return'sr-vital--ok' }
function fmtRelative(dt) {
  if (!dt) return '—'
  try {
    const diff = Date.now() - new Date(dt.replace(' ','T')).getTime()
    const m = Math.floor(diff/60000)
    if (m < 1)  return 'Just now'
    if (m < 60) return `${m}m ago`
    const h = Math.floor(m/60)
    if (h < 24) return `${h}h ago`
    return `${Math.floor(h/24)}d ago`
  } catch { return '—' }
}
function fmtDateTime(dt) {
  if (!dt) return null
  try {
    return new Date(dt.replace(' ','T')).toLocaleString([],
      { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' })
  } catch { return dt }
}
function fmtShortDate(dt) {
  if (!dt) return ''
  try {
    return new Date(dt.replace(' ','T')).toLocaleDateString([],
      { day:'2-digit', month:'short' })
  } catch { return '' }
}
function caseDurationMins(rec) {
  if (!rec.opened_at||!rec.closed_at) return null
  try {
    const diff = new Date(rec.closed_at.replace(' ','T')) - new Date(rec.opened_at.replace(' ','T'))
    const m = Math.floor(diff/60000)
    if (m < 60) return `${m} min`
    return `${Math.floor(m/60)}h ${m%60}m`
  } catch { return null }
}

// ─── LIFECYCLE ────────────────────────────────────────────────────────────────
function onOnline() { isOnline.value = true; backgroundServerSync(500) }
function onOffline() { isOnline.value = false }

onMounted(() => {
  auth.value = getAuth()
  window.addEventListener('online',  onOnline)
  window.addEventListener('offline', onOffline)
  load().then(() => tryDeepLinkOpen())
  autoRefreshTimer = setInterval(() => {
    if (isOnline.value && !loading.value && !syncing.value) backgroundServerSync()
  }, POLL_INTERVAL_MS)
})

onIonViewWillEnter(() => {
  auth.value = getAuth()
  reload()
  if (isOnline.value) backgroundServerSync(200)
  // Deep-link open — fires every enter so a fresh ?open= query param works
  tryDeepLinkOpen()
})

onUnmounted(() => {
  window.removeEventListener('online',  onOnline)
  window.removeEventListener('offline', onOffline)
  clearInterval(autoRefreshTimer)
  if (bgSyncDebounce) clearTimeout(bgSyncDebounce)
  if (searchDebounce) clearTimeout(searchDebounce)
})
</script>

<style scoped>
/* ═══════════════════════════════════════════════════════════════════════
   SECONDARY SCREENING RECORDS — Premium Light Theme · Namespace: sr-*
   Design: Clinical Intelligence File — WHO/IHR operational palette
   NO dark mode. NO @media prefers-color-scheme: dark.
═══════════════════════════════════════════════════════════════════════ */

/* ── HEADER ──────────────────────────────────────────────────────────── */
.sr-toolbar { --background:linear-gradient(135deg, #001D3D 0%, #003566 50%, #003F88 100%); --color:#fff; --padding-start:8px; --padding-end:8px; --min-height:52px; }
.sr-menu-btn { --color:rgba(255,255,255,.8); }
.sr-title-block { display:flex; flex-direction:column; margin-left:4px; }
.sr-eyebrow { font-size:8.5px; font-weight:700; text-transform:uppercase; letter-spacing:1.6px; color:rgba(255,255,255,.45); line-height:1; }
.sr-title   { font-size:17px; font-weight:800; color:#fff; line-height:1.2; letter-spacing:-.3px; }

.sr-sync-pill { display:flex; align-items:center; gap:5px; padding:4px 10px; border-radius:9999px; font-size:10px; font-weight:700; border:1px solid rgba(255,255,255,.15); margin-right:4px; cursor:pointer; transition:all .2s; backdrop-filter:blur(8px); }
.sr-sync-pill--ok      { background:rgba(40,167,69,.2);  color:#90EE90; }
.sr-sync-pill--pending { background:rgba(255,152,0,.25);  color:#FFD740; }
.sr-sync-pill--syncing { background:rgba(33,150,243,.25); color:#90CAF9; animation:sr-pulse 1.2s ease-in-out infinite; }
.sr-sync-pill--offline { background:rgba(158,158,158,.15); color:rgba(255,255,255,.5); }
@keyframes sr-pulse { 0%,100%{opacity:1}50%{opacity:.5} }
.sr-sync-dot { width:7px; height:7px; border-radius:50%; background:currentColor; }
.sr-refresh-btn { --color:rgba(255,255,255,.8); --padding-start:6px; --padding-end:6px; }

/* ── STATS BAR ───────────────────────────────────────────────────────── */
.sr-stats-bar { display:flex; align-items:stretch; background:linear-gradient(135deg, #001D3D 0%, #002F6C 100%); }
.sr-stat { flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; padding:9px 0; gap:2px; border:none; background:transparent; cursor:pointer; transition:background .15s; position:relative; }
.sr-stat:active, .sr-stat--on { background:rgba(255,255,255,.12); }
.sr-stat-num  { font-size:20px; font-weight:900; line-height:1; color:#fff; font-variant-numeric:tabular-nums; }
.sr-stat-lbl  { font-size:7.5px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:rgba(255,255,255,.5); }
.sr-stat--critical .sr-stat-num { color:#FF6B6B; }
.sr-stat--high     .sr-stat-num { color:#FFD93D; }
.sr-stat--active   .sr-stat-num { color:#63B3ED; }
.sr-stat--unsynced .sr-stat-num { color:#FFA726; }
.sr-stat--quarantine .sr-stat-num { color:#CE93D8; }
.sr-stat-div { width:1px; height:28px; background:rgba(255,255,255,.1); margin:auto 0; }

/* ── QUARANTINE BANNER ───────────────────────────────────────────────── */
.sr-quarantine-banner { background:linear-gradient(135deg, #4A1942 0%, #2D1B3D 100%); padding:12px; border-bottom:2px solid #CE93D8; }
.sr-qb-header { display:flex; align-items:flex-start; gap:10px; margin-bottom:10px; }
.sr-qb-icon { width:20px; height:20px; flex-shrink:0; color:#CE93D8; margin-top:2px; }
.sr-qb-text { flex:1; }
.sr-qb-title { display:block; font-size:13px; font-weight:800; color:#E1BEE7; }
.sr-qb-sub   { display:block; font-size:10px; color:rgba(225,190,231,.6); margin-top:2px; line-height:1.4; }
.sr-qb-list  { display:flex; flex-direction:column; gap:6px; margin-bottom:10px; }
.sr-qb-item  { display:flex; align-items:center; gap:10px; padding:8px 10px; background:rgba(255,255,255,.06); border-radius:8px; border:1px solid rgba(206,147,216,.2); }
.sr-qb-item-info { flex:1; min-width:0; }
.sr-qb-item-name { display:block; font-size:12px; font-weight:700; color:#E1BEE7; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sr-qb-item-err  { display:block; font-size:10px; color:#EF9A9A; margin-top:1px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sr-qb-item-meta { display:block; font-size:9px; color:rgba(225,190,231,.5); margin-top:1px; }
.sr-qb-item-actions { display:flex; gap:6px; flex-shrink:0; }
.sr-qb-retry, .sr-qb-delete { width:30px; height:30px; border-radius:6px; border:1px solid rgba(206,147,216,.3); background:rgba(255,255,255,.08); display:flex; align-items:center; justify-content:center; cursor:pointer; transition:background .15s; }
.sr-qb-retry { color:#81C784; }
.sr-qb-retry:hover { background:rgba(129,199,132,.2); }
.sr-qb-delete { color:#EF9A9A; }
.sr-qb-delete:hover { background:rgba(239,154,154,.2); }
.sr-qb-retry:disabled, .sr-qb-delete:disabled { opacity:.4; cursor:not-allowed; }
.sr-qb-purge { width:100%; padding:8px; border-radius:6px; border:1.5px solid #EF9A9A; background:transparent; color:#EF9A9A; font-size:11px; font-weight:700; cursor:pointer; transition:background .15s; }
.sr-qb-purge:hover { background:rgba(239,154,154,.15); }

/* ── CONTROLS ────────────────────────────────────────────────────────── */
.sr-controls { background:#fff; border-bottom:1px solid #E8EDF5; }
.sr-search-row { display:flex; align-items:center; gap:6px; padding:8px 10px 0; }
.sr-search-box { flex:1; display:flex; align-items:center; gap:6px; padding:0 10px; background:#F5F7FA; border:1.5px solid #DDE3EA; border-radius:10px; transition:border-color .15s; }
.sr-search-box:focus-within { border-color:#0066CC; box-shadow:0 0 0 3px rgba(0,102,204,.1); }
.sr-search-icon { width:14px; height:14px; color:#90A4AE; flex-shrink:0; }
.sr-search-input { flex:1; border:none; outline:none; background:transparent; font-size:13px; color:#263238; padding:9px 0; min-width:0; }
.sr-search-input::placeholder { color:#B0BEC5; }
.sr-search-clear { border:none; background:none; cursor:pointer; color:#90A4AE; padding:4px; display:flex; align-items:center; }
.sr-filter-btn { position:relative; width:40px; height:40px; border-radius:10px; border:1.5px solid #DDE3EA; background:#F5F7FA; cursor:pointer; display:flex; align-items:center; justify-content:center; flex-shrink:0; color:#546E7A; transition:all .15s; }
.sr-filter-btn--on { background:#E3F2FD; border-color:#0066CC; color:#0066CC; }
.sr-filter-badge { position:absolute; top:-5px; right:-5px; background:#DC3545; color:#fff; font-size:8px; font-weight:800; width:16px; height:16px; border-radius:50%; display:flex; align-items:center; justify-content:center; }

.sr-tabs { display:flex; overflow-x:auto; scrollbar-width:none; padding:0 8px; gap:2px; }
.sr-tabs::-webkit-scrollbar { display:none; }
.sr-tab { display:flex; align-items:center; gap:4px; padding:9px 10px; border:none; background:transparent; border-bottom:2.5px solid transparent; font-size:11.5px; font-weight:600; color:var(--ion-color-medium); white-space:nowrap; cursor:pointer; transition:color .12s, border-color .12s; flex-shrink:0; }
.sr-tab--on { color:#0066CC; border-bottom-color:#0066CC; }
.sr-tab-badge { display:inline-flex; align-items:center; justify-content:center; min-width:16px; height:16px; padding:0 4px; border-radius:9999px; font-size:9px; font-weight:800; }
.sr-tb--open   { background:#FFEBEE; color:#C62828; }
.sr-tb--ip     { background:#FFF3E0; color:#E65100; }
.sr-tb--disp   { background:#E8EAF6; color:#3949AB; }
.sr-tb--closed { background:#E8F5E9; color:#2E7D32; }
.sr-tb--all    { background:#E3F2FD; color:#1565C0; }

.sr-filter-panel { padding:10px 12px; border-top:1px solid #EEF1F5; background:#FAFBFD; }
.sr-fp-row { display:flex; align-items:flex-start; gap:8px; margin-bottom:10px; }
.sr-fp-row--dates { flex-direction:column; }
.sr-fp-lbl { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#546E7A; white-space:nowrap; padding-top:4px; min-width:60px; }
.sr-fp-pills { display:flex; flex-wrap:nowrap; gap:4px; overflow-x:auto; flex:1; }
.sr-fp-pills::-webkit-scrollbar { display:none; }
.sr-pill { padding:4px 10px; border-radius:9999px; font-size:10px; font-weight:700; border:1.5px solid; cursor:pointer; transition:all .12s; white-space:nowrap; color:#546E7A; background:#F5F7FA; border-color:#DDE3EA; }
.sr-pill--sm { padding:3px 8px; font-size:9px; }
.sr-pill--on { background:#0066CC; color:#fff; border-color:#0066CC; }
.sr-pill--critical { border-color:rgba(220,53,69,.4); color:#C62828; }
.sr-pill--critical.sr-pill--on { background:#C62828; border-color:#C62828; color:#fff; }
.sr-pill--high { border-color:rgba(230,101,0,.4); color:#E65100; }
.sr-pill--high.sr-pill--on { background:#E65100; border-color:#E65100; color:#fff; }
.sr-pill--medium { border-color:rgba(255,193,7,.5); color:#996000; }
.sr-pill--medium.sr-pill--on { background:#F57F17; border-color:#F57F17; color:#fff; }
.sr-pill--low { border-color:rgba(40,167,69,.4); color:#2E7D32; }
.sr-pill--low.sr-pill--on { background:#2E7D32; border-color:#2E7D32; color:#fff; }
.sr-pill--syn.sr-pill--on, .sr-pill--date.sr-pill--on, .sr-pill--disp.sr-pill--on { background:#0066CC; border-color:#0066CC; color:#fff; }
.sr-clear-all { width:100%; margin-top:4px; padding:7px; border-radius:6px; border:1.5px solid #DC3545; background:transparent; color:#DC3545; font-size:11px; font-weight:700; cursor:pointer; }

/* Date inputs */
.sr-date-inputs { display:flex; gap:8px; flex:1; padding-left:60px; }
.sr-date-field { flex:1; display:flex; flex-direction:column; gap:2px; }
.sr-date-label { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#90A4AE; }
.sr-date-input { padding:6px 8px; border:1.5px solid #DDE3EA; border-radius:6px; font-size:12px; color:#263238; background:#F5F7FA; outline:none; transition:border-color .15s; }
.sr-date-input:focus { border-color:#0066CC; }

/* Active filter chips */
.sr-chip-row { display:flex; align-items:center; gap:6px; padding:4px 12px 8px; flex-wrap:wrap; }
.sr-chip { display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:12px; font-size:10px; font-weight:700; text-transform:uppercase; }
.sr-chip--critical { background:#FFEBEE; color:#C62828; }
.sr-chip--high     { background:#FFF3E0; color:#E65100; }
.sr-chip--medium   { background:#FFF8E1; color:#F57F17; }
.sr-chip--low      { background:#E8F5E9; color:#2E7D32; }
.sr-chip--syn      { background:#E8EAF6; color:#3949AB; text-transform:none; }
.sr-chip--disp     { background:#EDE7F6; color:#4527A0; text-transform:none; }
.sr-chip--date     { background:#E3F2FD; color:#1565C0; text-transform:none; }
.sr-chip--unsynced { background:#FFF3E0; color:#E65100; text-transform:none; }
.sr-chip-x { border:none; background:none; cursor:pointer; font-size:14px; line-height:1; padding:0 2px; opacity:.7; }
.sr-chip-count { font-size:10px; color:#607D8B; margin-left:auto; }

.sr-bulk-bar { display:flex; align-items:center; gap:8px; padding:8px 12px; background:linear-gradient(135deg, #FFF3E0, #FFF8E1); border-top:1px solid #FFB74D; font-size:12px; color:#BF360C; }
.sr-bulk-icon { font-size:16px; color:#E65100; flex-shrink:0; }
.sr-bulk-btn { margin-left:auto; padding:5px 12px; border-radius:6px; border:none; background:#E65100; color:#fff; font-size:11px; font-weight:700; cursor:pointer; white-space:nowrap; }
.sr-bulk-btn:disabled { opacity:.6; cursor:not-allowed; }

.sr-slide-enter-active, .sr-slide-leave-active { transition:max-height .25s ease, opacity .25s ease; overflow:hidden; }
.sr-slide-enter-from, .sr-slide-leave-to { max-height:0; opacity:0; }
.sr-slide-enter-to, .sr-slide-leave-from { max-height:600px; opacity:1; }

/* ── CONTENT ─────────────────────────────────────────────────────────── */
.sr-content { --background:#EFF3FA; }

/* Loading animation */
.sr-loading { display:flex; flex-direction:column; align-items:center; justify-content:center; gap:16px; padding:60px 20px; }
.sr-loading-anim { position:relative; width:48px; height:48px; }
.sr-loading-ring { position:absolute; inset:0; border:3px solid transparent; border-top-color:#0066CC; border-radius:50%; animation:sr-spin .8s linear infinite; }
.sr-loading-ring--2 { inset:6px; border-top-color:#003F88; animation-duration:1.2s; animation-direction:reverse; }
@keyframes sr-spin { to { transform:rotate(360deg) } }
.sr-loading-text { font-size:14px; color:#607D8B; }

.sr-offline-bar { display:flex; align-items:center; gap:8px; padding:10px 14px; background:linear-gradient(135deg, #FFF8E1, #FFF3E0); border-bottom:1px solid #FFD54F; font-size:12px; color:#795548; }

.sr-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:60px 24px; text-align:center; gap:10px; }
.sr-empty-graphic { width:64px; height:64px; color:#B0BEC5; margin-bottom:4px; }
.sr-empty-svg { width:100%; height:100%; }
.sr-empty-title { font-size:18px; font-weight:700; color:#263238; margin:0; }
.sr-empty-sub   { font-size:13px; color:#607D8B; margin:0; max-width:280px; }

/* ── RECORD LIST ─────────────────────────────────────────────────────── */
.sr-list { padding:10px 12px 0; }

.sr-card { display:flex; background:#fff; border-radius:12px; margin-bottom:10px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06),0 0 0 1px rgba(0,0,0,.03); transition:transform .12s, box-shadow .12s; cursor:pointer; }
.sr-card:active { transform:scale(.985); box-shadow:0 0 0 1px rgba(0,0,0,.06); }
.sr-card--unsynced { box-shadow:0 1px 4px rgba(255,152,0,.18),0 0 0 1.5px rgba(255,152,0,.25); }
.sr-card--failed   { box-shadow:0 1px 4px rgba(220,53,69,.18),0 0 0 1.5px rgba(220,53,69,.25); }
.sr-card--critical .sr-card-stripe { background:linear-gradient(180deg, #D32F2F, #B71C1C); }
.sr-card--high     .sr-card-stripe { background:linear-gradient(180deg, #F57C00, #E65100); }
.sr-card--medium   .sr-card-stripe { background:linear-gradient(180deg, #F9A825, #F57F17); }
.sr-card--low      .sr-card-stripe { background:linear-gradient(180deg, #388E3C, #2E7D32); }
.sr-card-stripe { width:4px; flex-shrink:0; background:#B0BEC5; }
.sr-card-body   { flex:1; padding:11px 12px; min-width:0; display:flex; flex-direction:column; gap:7px; }

.sr-card-top { display:flex; align-items:center; gap:5px; flex-wrap:wrap; }
.sr-status-pill { display:inline-flex; align-items:center; padding:2px 8px; border-radius:9999px; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.4px; border:1px solid; }
.sr-status-pill--open          { background:#FFEBEE; color:#C62828; border-color:rgba(220,53,69,.2); }
.sr-status-pill--in-progress   { background:#FFF3E0; color:#E65100; border-color:rgba(230,101,0,.2); }
.sr-status-pill--dispositioned { background:#E8EAF6; color:#3949AB; border-color:rgba(57,73,171,.2); }
.sr-status-pill--closed        { background:#E8F5E9; color:#2E7D32; border-color:rgba(46,125,50,.2); }
.sr-risk-pill { display:inline-flex; padding:2px 7px; border-radius:9999px; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.4px; border:1px solid; }
.sr-risk-pill--critical { background:#FFEBEE; color:#C62828; border-color:rgba(220,53,69,.25); }
.sr-risk-pill--high     { background:#FFF3E0; color:#E65100; border-color:rgba(230,101,0,.25); }
.sr-risk-pill--medium   { background:#FFF8E1; color:#F57F17; border-color:rgba(245,127,23,.25); }
.sr-risk-pill--low      { background:#E8F5E9; color:#2E7D32; border-color:rgba(46,125,50,.25); }
.sr-sync-dot-sm { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.sr-sync-dot-sm--synced   { background:#4CAF50; }
.sr-sync-dot-sm--unsynced { background:#FF9800; animation:sr-pulse 2s ease-in-out infinite; }
.sr-sync-dot-sm--failed   { background:#F44336; }
.sr-emergency-badge { padding:1px 6px; border-radius:3px; font-size:8px; font-weight:900; letter-spacing:.5px; background:#D32F2F; color:#fff; animation:sr-pulse 1.5s ease-in-out infinite; }
.sr-card-time { margin-left:auto; font-size:10px; color:#90A4AE; white-space:nowrap; }

.sr-card-traveler { display:flex; align-items:center; gap:9px; }
.sr-avatar { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-weight:800; }
.sr-avatar--critical { background:linear-gradient(135deg,#FFEBEE,#FFCDD2); color:#C62828; border:1.5px solid rgba(220,53,69,.2); }
.sr-avatar--high     { background:linear-gradient(135deg,#FFF3E0,#FFE0B2); color:#E65100; border:1.5px solid rgba(230,101,0,.2); }
.sr-avatar--medium   { background:linear-gradient(135deg,#FFF8E1,#FFECB3); color:#F57F17; border:1.5px solid rgba(245,127,23,.2); }
.sr-avatar--low      { background:linear-gradient(135deg,#E8F5E9,#C8E6C9); color:#2E7D32; border:1.5px solid rgba(46,125,50,.2); }
.sr-avatar-letter { font-size:14px; }
.sr-traveler-info { flex:1; min-width:0; }
.sr-traveler-name { display:block; font-size:13px; font-weight:700; color:#212121; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.sr-traveler-sub  { display:block; font-size:11px; color:#607D8B; margin-top:1px; }
.sr-temp-chip { padding:2px 8px; border-radius:9999px; font-size:11px; font-weight:800; flex-shrink:0; border:1px solid; }
.sr-temp--fever  { background:#FFEBEE; color:#C62828; border-color:rgba(220,53,69,.2); }
.sr-temp--low    { background:#FFF3E0; color:#E65100; border-color:rgba(230,101,0,.2); }
.sr-temp--normal { background:#E8F5E9; color:#2E7D32; border-color:rgba(46,125,50,.15); }

.sr-card-clinical { display:flex; flex-wrap:wrap; gap:4px; }
.sr-tag { padding:2px 7px; border-radius:4px; font-size:10px; font-weight:600; background:#EEF2FF; color:#3949AB; }
.sr-tag--syn     { background:#E8EAF6; color:#283593; }
.sr-tag--disease { background:#FBE9E7; color:#BF360C; }
.sr-tag--triage  { background:#EDE7F6; color:#4527A0; }

.sr-card-footer { display:flex; align-items:center; gap:8px; padding-top:6px; border-top:1px solid #F0F4FA; flex-wrap:wrap; }
.sr-card-meta { font-size:10px; color:#90A4AE; }
.sr-card-date { font-variant-numeric:tabular-nums; }
.sr-card-sync-btn { display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:6px; font-size:10px; font-weight:700; cursor:pointer; margin-left:auto; border:1.5px solid; transition:background .12s; }
.sr-card-sync-btn--unsynced { background:#FFF3E0; color:#E65100; border-color:rgba(230,101,0,.25); }
.sr-card-sync-btn--failed   { background:#FFEBEE; color:#C62828; border-color:rgba(220,53,69,.25); }
.sr-card-sync-btn:disabled  { opacity:.5; cursor:not-allowed; }
.sr-card-synced-badge { display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; color:#2E7D32; margin-left:auto; }
.sr-card-synced-badge ion-icon { font-size:12px; }

.sr-load-more { padding:8px 0 4px; }

/* ── MODAL ───────────────────────────────────────────────────────────── */
.sr-modal-header { background:#fff; }
.sr-modal-toolbar { --background:linear-gradient(135deg, #001D3D, #003F88); --color:#fff; --min-height:50px; }
.sr-modal-close { --color:rgba(255,255,255,.8); }
.sr-modal-title-block { display:flex; flex-direction:column; }
.sr-modal-eyebrow { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,.45); }
.sr-modal-title  { font-size:15px; font-weight:800; color:#fff; }
.sr-modal-sync-btn { display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border-radius:6px; font-size:11px; font-weight:700; cursor:pointer; border:1.5px solid rgba(255,152,0,.5); background:rgba(255,152,0,.15); color:#FFD740; margin-right:6px; }
.sr-modal-sync-btn--active { animation:sr-pulse 1s ease-in-out infinite; }
.sr-modal-sync-btn:disabled { opacity:.5; cursor:not-allowed; }
.sr-modal-synced { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; color:#90EE90; margin-right:8px; }

.sr-modal-status-bar { display:flex; align-items:center; gap:6px; padding:8px 14px; background:#F8FAFC; border-bottom:1px solid #E8EDF5; flex-wrap:wrap; }
.sr-triage-pill { padding:2px 7px; border-radius:9999px; font-size:9px; font-weight:800; background:#EDE7F6; color:#4527A0; border:1px solid rgba(69,39,160,.15); }
.sr-sync-status-badge { padding:2px 7px; border-radius:9999px; font-size:9px; font-weight:800; border:1px solid; }
.sr-sync-status-badge--synced   { background:#E8F5E9; color:#2E7D32; border-color:rgba(46,125,50,.25); }
.sr-sync-status-badge--unsynced { background:#FFF3E0; color:#E65100; border-color:rgba(230,101,0,.25); }
.sr-sync-status-badge--failed   { background:#FFEBEE; color:#C62828; border-color:rgba(220,53,69,.25); }

.sr-modal-tabs { display:flex; overflow-x:auto; scrollbar-width:none; border-bottom:1.5px solid #E8EDF5; padding:0 10px; }
.sr-modal-tabs::-webkit-scrollbar { display:none; }
.sr-modal-tab { padding:9px 11px; border:none; background:transparent; border-bottom:2.5px solid transparent; font-size:11px; font-weight:600; color:var(--ion-color-medium); cursor:pointer; white-space:nowrap; display:inline-flex; align-items:center; gap:4px; transition:color .12s, border-color .12s; }
.sr-modal-tab--on { color:#0066CC; border-bottom-color:#0066CC; }
.sr-modal-tab-badge { display:inline-flex; align-items:center; justify-content:center; min-width:16px; height:16px; padding:0 4px; border-radius:9999px; background:#FFEBEE; color:#C62828; font-size:9px; font-weight:800; }

.sr-modal::part(content) { display:flex; flex-direction:column; overflow:hidden; height:100%; }
.sr-modal-scroll { flex:1; min-height:0; overflow-y:auto; -webkit-overflow-scrolling:touch; background:#F5F7FA; padding-bottom:max(120px, env(safe-area-inset-bottom, 120px)); }
.sr-modal-body    { padding:0 0 8px; }
.sr-tab-panel     { padding:0; }
.sr-detail-loading { display:flex; align-items:center; gap:10px; padding:24px; color:#607D8B; font-size:13px; }

/* Sync card */
.sr-sync-card { margin:12px; border-radius:10px; overflow:hidden; border:1.5px solid; }
.sr-sync-card--synced   { border-color:rgba(46,125,50,.25); background:#F1F8E9; }
.sr-sync-card--unsynced { border-color:rgba(255,152,0,.35); background:#FFF8E1; }
.sr-sync-card--failed   { border-color:rgba(220,53,69,.35); background:#FFEBEE; }
.sr-sync-card-header { display:flex; align-items:center; gap:8px; padding:10px 14px; border-bottom:1px solid rgba(0,0,0,.05); }
.sr-sync-card-icon  { font-size:18px; }
.sr-sync-card--synced   .sr-sync-card-icon { color:#2E7D32; }
.sr-sync-card--unsynced .sr-sync-card-icon { color:#E65100; }
.sr-sync-card--failed   .sr-sync-card-icon { color:#C62828; }
.sr-sync-card-title { font-size:13px; font-weight:700; color:#263238; }
.sr-sync-card-body  { padding:8px 14px; display:flex; flex-direction:column; gap:6px; }
.sr-sync-row  { display:flex; align-items:flex-start; gap:8px; }
.sr-sync-lbl  { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#607D8B; min-width:110px; flex-shrink:0; padding-top:1px; }
.sr-sync-val  { font-size:12px; color:#263238; word-break:break-all; }
.sr-sync-error { color:#C62828; font-style:italic; }
.sr-uuid { font-family:monospace; font-size:10px; }
.sr-sync-card-action { width:calc(100% - 28px); margin:6px 14px 10px; padding:10px; border-radius:7px; border:none; background:#0066CC; color:#fff; font-size:12px; font-weight:700; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:6px; }
.sr-sync-card-action:disabled { opacity:.6; cursor:not-allowed; }
.sr-sync-offline-note { padding:8px 14px 10px; font-size:11px; color:#607D8B; text-align:center; }

/* Timeline */
.sr-timeline { margin:0 12px 4px; border-radius:8px; background:#fff; border:1px solid #E8EDF5; overflow:hidden; }
.sr-tl-item  { display:flex; align-items:flex-start; gap:12px; padding:11px 14px; border-bottom:1px solid #F0F4FA; }
.sr-tl-item:last-child { border-bottom:none; }
.sr-tl-dot   { width:10px; height:10px; border-radius:50%; flex-shrink:0; margin-top:3px; }
.sr-tl-item--open          .sr-tl-dot { background:#0066CC; }
.sr-tl-item--dispositioned .sr-tl-dot { background:#7E57C2; }
.sr-tl-item--closed        .sr-tl-dot { background:#2E7D32; }
.sr-tl-body  { display:flex; flex-direction:column; gap:2px; }
.sr-tl-label { font-size:12px; font-weight:700; color:#212121; }
.sr-tl-time  { font-size:11px; color:#0066CC; }
.sr-tl-sub   { font-size:11px; color:#607D8B; }

/* Section headers */
.sr-section-hdr { display:flex; align-items:center; gap:7px; padding:14px 14px 6px; font-size:10px; font-weight:800; text-transform:uppercase; letter-spacing:.8px; color:#546E7A; }
.sr-sec-num { display:inline-flex; align-items:center; justify-content:center; width:18px; height:18px; border-radius:50%; background:#003F88; color:#fff; font-size:8px; font-weight:900; flex-shrink:0; }
.sr-sec-num--ai { background:linear-gradient(135deg, #6A1B9A, #0066CC); font-size:7px; }

/* KV grid */
.sr-kv-grid { display:grid; grid-template-columns:1fr 1fr; gap:1px; margin:0 12px 4px; background:#E8EDF5; border-radius:8px; overflow:hidden; }
.sr-kv { display:flex; flex-direction:column; gap:2px; padding:9px 12px; background:#fff; }
.sr-k  { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#90A4AE; }
.sr-v  { font-size:12px; color:#212121; font-weight:500; word-break:break-word; }
.sr-v--danger { color:#C62828; font-weight:700; }
.sr-doc-no { font-family:monospace; font-size:11px; }

.sr-notes-box  { margin:4px 12px; padding:10px 12px; background:#fff; border-radius:8px; border:1px solid #E8EDF5; border-left:3px solid #0066CC; }
.sr-notes-lbl  { display:block; font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#90A4AE; margin-bottom:4px; }
.sr-notes-text { font-size:12px; color:#263238; line-height:1.5; margin:0; }

.sr-disease-list { margin:0 12px 4px; background:#fff; border-radius:8px; border:1px solid #E8EDF5; overflow:hidden; }
.sr-disease-row  { display:flex; align-items:flex-start; gap:10px; padding:10px 12px; border-bottom:1px solid #F0F4FA; }
.sr-disease-row:last-child { border-bottom:none; }
.sr-disease-rank { width:22px; height:22px; border-radius:50%; background:#003F88; color:#fff; font-size:9px; font-weight:900; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.sr-disease-info { display:flex; flex-direction:column; gap:2px; }
.sr-disease-name { font-size:12px; font-weight:700; color:#212121; }
.sr-disease-conf { font-size:11px; color:#2E7D32; }
.sr-disease-reason { font-size:11px; color:#607D8B; }

.sr-risk-text--critical { color:#C62828; font-weight:700; }
.sr-risk-text--high     { color:#E65100; font-weight:700; }
.sr-risk-text--medium   { color:#F57F17; font-weight:700; }
.sr-risk-text--low      { color:#2E7D32; }

/* Vitals */
.sr-vitals-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; margin:0 12px 4px; }
.sr-vital-card  { display:flex; flex-direction:column; align-items:center; padding:10px 8px; border-radius:8px; background:#fff; border:1.5px solid #E8EDF5; gap:3px; }
.sr-vital--ok     { border-color:rgba(46,125,50,.25); background:#F1F8E9; }
.sr-vital--warn   { border-color:rgba(245,127,23,.35); background:#FFF8E1; }
.sr-vital--danger { border-color:rgba(220,53,69,.35); background:#FFEBEE; }
.sr-vital-val  { font-size:16px; font-weight:800; color:#212121; }
.sr-vital-lbl  { font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#607D8B; text-align:center; }
.sr-vital-warn { font-size:9px; color:#E65100; font-weight:700; }

.sr-symptom-grid { margin:0 12px 4px; background:#fff; border-radius:8px; border:1px solid #E8EDF5; overflow:hidden; }
.sr-sym-row  { display:flex; align-items:flex-start; gap:9px; padding:9px 12px; border-bottom:1px solid #F8FAFC; }
.sr-sym-row:last-child { border-bottom:none; }
.sr-sym-dot  { width:8px; height:8px; border-radius:50%; flex-shrink:0; margin-top:3px; }
.sr-sym-dot--present { background:#DC3545; }
.sr-sym-info { display:flex; flex-direction:column; gap:2px; }
.sr-sym-name { font-size:12px; font-weight:600; color:#212121; text-transform:capitalize; }
.sr-sym-onset{ font-size:10px; color:#607D8B; }
.sr-sym-detail { font-size:10px; color:#607D8B; }

.sr-action-grid { margin:0 12px 4px; background:#fff; border-radius:8px; border:1px solid #E8EDF5; overflow:hidden; }
.sr-action-row  { display:flex; align-items:center; gap:8px; padding:9px 12px; border-bottom:1px solid #F8FAFC; font-size:12px; color:#263238; }
.sr-action-row:last-child { border-bottom:none; }
.sr-action-ico  { font-size:14px; color:#2E7D32; flex-shrink:0; }
.sr-action-detail { font-size:10px; color:#607D8B; }

.sr-sample-list { margin:0 12px 4px; background:#fff; border-radius:8px; border:1px solid #E8EDF5; overflow:hidden; }
.sr-sample-row  { display:flex; flex-wrap:wrap; gap:4px 12px; padding:9px 12px; border-bottom:1px solid #F8FAFC; align-items:center; }
.sr-sample-row:last-child { border-bottom:none; }
.sr-sample-type { font-size:12px; font-weight:700; color:#212121; }
.sr-sample-id   { font-size:10px; font-family:monospace; color:#0066CC; }
.sr-sample-lab  { font-size:10px; color:#607D8B; }
.sr-sample-time { font-size:10px; color:#90A4AE; margin-left:auto; }

.sr-tc-list { margin:0 12px 4px; background:#fff; border-radius:8px; border:1px solid #E8EDF5; overflow:hidden; }
.sr-tc-row   { display:flex; align-items:center; gap:8px; padding:9px 12px; border-bottom:1px solid #F8FAFC; }
.sr-tc-row:last-child { border-bottom:none; }
.sr-tc-role  { font-size:9px; font-weight:800; padding:2px 6px; border-radius:4px; }
.sr-tc-role--visited { background:#E8EAF6; color:#3949AB; }
.sr-tc-role--transit { background:#FFF3E0; color:#E65100; }
.sr-tc-country { font-size:12px; font-weight:700; color:#212121; }
.sr-tc-dates   { font-size:10px; color:#607D8B; margin-left:auto; }

.sr-exposure-list { margin:0 12px 4px; background:#fff; border-radius:8px; border:1px solid #E8EDF5; overflow:hidden; }
.sr-exp-row  { display:flex; align-items:flex-start; gap:9px; padding:10px 12px; border-bottom:1px solid #F8FAFC; }
.sr-exp-row:last-child { border-bottom:none; }
.sr-exp-row--yes     { background:#FFF5F5; }
.sr-exp-row--no      { background:#fff; }
.sr-exp-row--unknown { background:#FAFAFA; }
.sr-exp-badge { padding:2px 8px; border-radius:4px; font-size:9px; font-weight:800; text-transform:uppercase; flex-shrink:0; margin-top:1px; }
.sr-exp-badge--yes     { background:#FFEBEE; color:#C62828; }
.sr-exp-badge--no      { background:#E8F5E9; color:#2E7D32; }
.sr-exp-badge--unknown { background:#F5F5F5; color:#607D8B; }
.sr-exp-info  { display:flex; flex-direction:column; gap:2px; }
.sr-exp-code  { font-size:12px; font-weight:600; color:#212121; text-transform:capitalize; }
.sr-exp-detail{ font-size:10px; color:#607D8B; }

/* ── AI ANALYSIS ─────────────────────────────────────────────────────── */
.sr-ai-card { display:flex; align-items:center; gap:16px; margin:8px 12px; padding:16px; background:linear-gradient(135deg, #F5F7FA, #EBF3FF); border-radius:12px; border:1.5px solid rgba(0,102,204,.15); }
.sr-ai-score-ring { position:relative; width:72px; height:72px; flex-shrink:0; }
.sr-ai-svg { width:100%; height:100%; }
.sr-ai-score-text { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; font-size:20px; font-weight:900; }
.sr-ai-score-info { flex:1; }
.sr-ai-score-label { display:block; font-size:13px; font-weight:800; color:#212121; }
.sr-ai-score-desc { display:block; font-size:10px; color:#607D8B; margin-top:3px; line-height:1.4; }

.sr-ai-factors { margin:0 12px 4px; display:flex; flex-direction:column; gap:10px; }
.sr-ai-factor { background:#fff; border-radius:8px; padding:10px 12px; border:1px solid #E8EDF5; }
.sr-ai-factor-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; }
.sr-ai-factor-label { font-size:11px; font-weight:700; color:#263238; }
.sr-ai-factor-score { font-size:11px; font-weight:800; }
.sr-ai-factor-bar { height:6px; background:#E8EDF5; border-radius:3px; overflow:hidden; }
.sr-ai-factor-fill { height:100%; border-radius:3px; transition:width .4s ease; }
.sr-ai-factor-note { display:block; font-size:9px; color:#607D8B; margin-top:4px; }

.sr-ai-alerts { margin:0 12px 4px; display:flex; flex-direction:column; gap:6px; }
.sr-ai-alert { display:flex; align-items:flex-start; gap:10px; padding:10px 12px; border-radius:8px; border:1px solid; }
.sr-ai-alert--critical { background:#FFEBEE; border-color:rgba(220,53,69,.25); }
.sr-ai-alert--high     { background:#FFF3E0; border-color:rgba(230,101,0,.25); }
.sr-ai-alert--medium   { background:#FFF8E1; border-color:rgba(245,127,23,.25); }
.sr-ai-alert--info     { background:#E8F5E9; border-color:rgba(46,125,50,.2); }
.sr-ai-alert-icon { width:22px; height:22px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:900; flex-shrink:0; }
.sr-ai-alert--critical .sr-ai-alert-icon { background:#D32F2F; color:#fff; }
.sr-ai-alert--high     .sr-ai-alert-icon { background:#F57C00; color:#fff; }
.sr-ai-alert--medium   .sr-ai-alert-icon { background:#F9A825; color:#fff; }
.sr-ai-alert--info     .sr-ai-alert-icon { background:#2E7D32; color:#fff; }
.sr-ai-alert-body { flex:1; }
.sr-ai-alert-title { display:block; font-size:12px; font-weight:700; color:#212121; }
.sr-ai-alert-desc  { display:block; font-size:10px; color:#607D8B; margin-top:2px; line-height:1.4; }

/* Alert card */
.sr-alert-card { margin:4px 12px 0; border-radius:8px; background:#FFF8E1; border:1.5px solid #FFB74D; overflow:hidden; }

.sr-empty-sub { font-size:12px; color:#B0BEC5; padding:12px 14px; font-style:italic; }

/* Responsive */
@media (min-width: 600px) {
  .sr-list    { max-width:720px; margin:0 auto; }
  .sr-kv-grid { grid-template-columns:1fr 1fr 1fr; }
  .sr-vitals-grid { grid-template-columns:repeat(5,1fr); }
}

/* Modal toolbar overrides */
.sr-modal-status-toolbar { --background:#F8FAFC; --border-width:0 0 1px 0; --border-color:#E8EDF5; --min-height:0; --padding-start:0; --padding-end:0; --padding-top:0; --padding-bottom:0; contain:content; }
.sr-modal-tabs-toolbar { --background:#ffffff; --border-width:0 0 1.5px 0; --border-color:#E8EDF5; --min-height:0; --padding-start:0; --padding-end:0; --padding-top:0; --padding-bottom:0; contain:content; }
.sr-modal-end-spacer { height:56px; flex-shrink:0; display:block; }
</style>
