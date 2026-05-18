<template>
  <IonPage>

    <!-- ══ HEADER ══════════════════════════════════════════════════════════ -->
    <IonHeader class="al-hdr" :translucent="false">
      <div class="al-hdr-bg">
        <div class="al-hdr-row">
          <IonButtons slot="start">
            <IonMenuButton menu="app-menu" class="al-menu-btn"/>
          </IonButtons>
          <div class="al-hdr-center">
            <span class="al-hdr-eye">{{ scope.label }} · {{ scope.code || '—' }}</span>
            <span class="al-hdr-title">Alerts</span>
          </div>
          <div class="al-hdr-right">
            <div v-if="counts.open > 0" class="al-live-badge">{{ counts.open }}</div>
            <button class="al-hdr-btn" :class="{ 'al-spin': loading }" :disabled="loading" @click="loadAlerts(true)" aria-label="Refresh">
              <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 4v5h5"/><path d="M17 14V9h-5"/><path d="M15.5 6A7 7 0 1 0 16 11"/></svg>
            </button>
          </div>
        </div>

        <!-- Stat pills -->
        <div class="al-stats">
          <div class="al-stat">
            <span class="al-stat-n">{{ counts.total }}</span>
            <span class="al-stat-l">Total</span>
          </div>
          <div class="al-stat al-stat--crit" :class="{ 'al-stat--on': riskFilter === 'CRITICAL' }" @click="toggleRisk('CRITICAL')">
            <span class="al-stat-n">{{ counts.critical }}</span>
            <span class="al-stat-l">Critical</span>
          </div>
          <div class="al-stat al-stat--high" :class="{ 'al-stat--on': riskFilter === 'HIGH' }" @click="toggleRisk('HIGH')">
            <span class="al-stat-n">{{ counts.high }}</span>
            <span class="al-stat-l">High</span>
          </div>
          <div class="al-stat al-stat--breach" :class="{ 'al-stat--on': riskFilter === '__BREACH__' }" @click="toggleRisk('__BREACH__')">
            <span class="al-stat-n">{{ counts.breach }}</span>
            <span class="al-stat-l">Overdue</span>
          </div>
          <div class="al-stat al-stat--closed" :class="{ 'al-stat--on': statusFilter === 'CLOSED' }" @click="toggleStatus('CLOSED')">
            <span class="al-stat-n">{{ counts.closed }}</span>
            <span class="al-stat-l">Closed</span>
          </div>
        </div>

        <!-- Period filter -->
        <div class="al-period-row">
          <button
            v-for="p in PERIOD_CHIPS"
            :key="p.v"
            :class="['al-period', period === p.v && 'al-period--on']"
            @click="setPeriod(p.v)"
          >{{ p.l }}</button>
        </div>

        <!-- Status + risk chips -->
        <div class="al-filters">
          <div class="al-filter-scroll">
            <button
              v-for="f in STATUS_CHIPS"
              :key="String(f.v)"
              :class="['al-chip', statusFilter === f.v && 'al-chip--on']"
              @click="setStatus(f.v)"
            >{{ f.l }}</button>
            <span class="al-filter-div"/>
            <button
              v-for="f in RISK_CHIPS"
              :key="f.v"
              :class="['al-chip', 'al-chip--' + f.v.toLowerCase(), riskFilter === f.v && 'al-chip--on']"
              @click="setRisk(f.v)"
            >{{ f.l }}</button>
          </div>
        </div>
      </div>
    </IonHeader>

    <!-- ══ CONTENT ═════════════════════════════════════════════════════════ -->
    <IonContent class="al-content" :fullscreen="true">
      <IonRefresher slot="fixed" @ionRefresh="onPull($event)"><IonRefresherContent/></IonRefresher>

      <!-- Offline banner -->
      <div v-if="!online" class="al-offline">
        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="1" y1="1" x2="15" y2="15"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 5m-1.17 1.17A6 6 0 0 1 14 9m-2 2a4 4 0 0 1 .17.49M6.53 6.53A4 4 0 0 0 4 9m2 2H2m0-4a7.07 7.07 0 0 1 2-2M8 13v1"/></svg>
        Offline — showing cached alerts
      </div>

      <!-- 7-1-7 compliance strip -->
      <div v-if="alerts.length > 0 && rollup" class="al-compl">
        <div class="al-compl-hdr">
          <span class="al-compl-title">7-1-7 Compliance</span>
          <span class="al-compl-period">{{ PERIOD_CHIPS.find(p => p.v === period)?.l || period }}</span>
        </div>
        <div class="al-compl-bars">
          <div class="al-compl-bar-row">
            <span class="al-compl-lbl">Notify 24h</span>
            <div class="al-compl-track"><div class="al-compl-fill" :class="(rollup.notify?.rate_pct ?? 0) >= 80 ? 'al-compl-fill--ok' : 'al-compl-fill--bad'" :style="{ width: (rollup.notify?.rate_pct ?? 0) + '%' }"/></div>
            <span :class="['al-compl-pct', (rollup.notify?.rate_pct ?? 0) < 80 && 'al-compl-pct--bad']">{{ rollup.notify?.rate_pct == null ? '—' : rollup.notify.rate_pct + '%' }}</span>
          </div>
          <div class="al-compl-bar-row">
            <span class="al-compl-lbl">Respond 7d</span>
            <div class="al-compl-track"><div class="al-compl-fill" :class="(rollup.respond?.rate_pct ?? 0) >= 80 ? 'al-compl-fill--ok' : 'al-compl-fill--bad'" :style="{ width: (rollup.respond?.rate_pct ?? 0) + '%' }"/></div>
            <span :class="['al-compl-pct', (rollup.respond?.rate_pct ?? 0) < 80 && 'al-compl-pct--bad']">{{ rollup.respond?.rate_pct == null ? '—' : rollup.respond.rate_pct + '%' }}</span>
          </div>
        </div>
      </div>

      <!-- Search -->
      <div class="al-search-wrap">
        <div class="al-search-inner">
          <svg class="al-search-ico" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="7" cy="7" r="5"/><line x1="11" y1="11" x2="15" y2="15"/></svg>
          <input
            v-model="searchQuery"
            type="search"
            class="al-search-input"
            placeholder="Search code, title, POE, syndrome…"
          />
          <button v-if="searchQuery" class="al-search-clear" @click="searchQuery = ''" aria-label="Clear search">
            <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="2" y1="2" x2="10" y2="10"/><line x1="10" y1="2" x2="2" y2="10"/></svg>
          </button>
        </div>
      </div>

      <!-- Skeleton -->
      <div v-if="loading && !alerts.length" class="al-skels">
        <div v-for="g in 2" :key="g">
          <div class="al-group-hdr-skel"/>
          <div v-for="i in 2" :key="i" class="al-skel">
            <div class="al-skel-stripe"/>
            <div class="al-skel-body">
              <div class="al-skel-line al-skel-line--wide"/>
              <div class="al-skel-line al-skel-line--mid"/>
              <div class="al-skel-line al-skel-line--short"/>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty -->
      <div v-else-if="!loading && !groupedAlerts.length" class="al-empty">
        <svg viewBox="0 0 56 56" fill="none" stroke="#10B981" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" width="52" height="52">
          <circle cx="28" cy="28" r="24"/>
          <polyline points="18 28 24 34 38 20"/>
        </svg>
        <p class="al-empty-t">{{ searchQuery ? 'No results' : 'All clear' }}</p>
        <p class="al-empty-s">{{ searchQuery ? 'No alerts match "' + searchQuery + '".' : activeFilters ? 'No alerts match the current filters.' : 'No alerts for this period and scope.' }}</p>
        <button v-if="activeFilters || searchQuery" class="al-clear-btn" @click="clearAll">Clear filters</button>
      </div>

      <!-- Date-grouped alert list -->
      <div v-else class="al-list">
        <div v-for="group in groupedAlerts" :key="group.date" class="al-group">
          <div class="al-group-hdr">
            <span class="al-group-date">{{ group.label }}</span>
            <span class="al-group-count">{{ group.items.length }} alert{{ group.items.length !== 1 ? 's' : '' }}</span>
          </div>

          <button
            v-for="a in group.items"
            :key="a.id"
            :class="['al-card', 'al-card--' + a.risk_level.toLowerCase(), a.overdue_24h && a.status !== 'CLOSED' && 'al-card--overdue', a.status === 'CLOSED' && 'al-card--closed']"
            type="button"
            @click="openDetail(a)"
          >
            <div :class="['al-stripe', 'al-stripe--' + a.risk_level.toLowerCase()]"/>

            <div class="al-card-body">
              <div class="al-card-top">
                <span :class="['al-risk', 'al-risk--' + a.risk_level.toLowerCase()]">{{ a.risk_level }}</span>
                <span :class="['al-status', 'al-status--' + a.status.toLowerCase()]">{{ STATUS_LABELS[a.status] || a.status }}</span>
                <span v-if="tierOf(a).tier === 1" class="al-tier al-tier--1">TIER 1</span>
                <span v-if="scOf(a).overall === 'BREACH'" class="al-breach">OVERDUE</span>
                <span class="al-age" :class="a.overdue_24h && a.status !== 'CLOSED' && 'al-age--over'">{{ fmtAge(a.hours_since_creation) }}</span>
              </div>

              <div class="al-card-title">{{ a.alert_title || a.alert_code }}</div>

              <div class="al-card-traveller">
                <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="6" cy="4" r="2"/><path d="M2 10c0-2.2 1.8-4 4-4s4 1.8 4 4"/></svg>
                <span>{{ a.traveler_full_name || a.traveler_name || 'Unknown traveller' }}</span>
                <span v-if="a.traveler_nationality_country_code || a.traveler_nationality" class="al-nat">· {{ a.traveler_nationality_country_code || a.traveler_nationality }}</span>
              </div>

              <div class="al-card-meta">
                <span class="al-chip-sm al-chip-sm--poe">{{ a.poe_code }}</span>
                <span v-if="a.syndrome" class="al-chip-sm">{{ a.syndrome.replace(/_/g, ' ') }}</span>
                <span v-if="a.top_disease_name" class="al-chip-sm al-chip-sm--disease">{{ a.top_disease_name }}</span>
                <span :class="['al-chip-sm', 'al-chip-sm--route--' + (a.routed_to_level || '').toLowerCase()]">→ {{ a.routed_to_level }}</span>
              </div>

              <div v-if="a.acknowledged_by_name" class="al-card-ack">
                <svg viewBox="0 0 12 12" fill="none" stroke="#059669" stroke-width="1.5" stroke-linecap="round"><polyline points="2 6 5 9 10 3"/></svg>
                Ack'd by {{ a.acknowledged_by_name }}
                <span v-if="a.acknowledged_at">· {{ fmtShort(a.acknowledged_at) }}</span>
              </div>

              <div v-if="a.status === 'CLOSED' && a.closed_at" class="al-card-closed">
                <svg viewBox="0 0 12 12" fill="none" stroke="#64748b" stroke-width="1.5" stroke-linecap="round"><polyline points="2 6 5 9 10 3"/></svg>
                Closed {{ fmtShort(a.closed_at) }}
                <span v-if="a.close_category"> · {{ a.close_category.replace(/_/g,' ') }}</span>
              </div>
            </div>

            <div class="al-card-arrow">
              <svg viewBox="0 0 8 14" fill="none" stroke="#CBD5E1" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 1 7 7 1 13"/></svg>
            </div>
          </button>
        </div>

        <div v-if="hasMore" class="al-more">
          <button class="al-more-btn" :disabled="loadingMore" @click="fetchMore">
            {{ loadingMore ? 'Loading…' : 'Load older alerts' }}
          </button>
        </div>
      </div>

      <div style="height: 60px"/>
    </IonContent>

    <!-- ══ FULL-SCREEN DETAIL MODAL ════════════════════════════════════════ -->
    <Teleport to="body">
      <Transition name="al-slide">
        <div v-if="detail" class="al-modal" role="dialog" :aria-label="detail.alert_title || detail.alert_code">

          <div :class="['al-modal-hdr', 'al-modal-hdr--' + detail.risk_level.toLowerCase()]">
            <button class="al-modal-back" @click="closeDetail" aria-label="Close">
              <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 4 6 9 11 14"/></svg>
            </button>
            <div class="al-modal-hdr-body">
              <span class="al-modal-eye">{{ detail.routed_to_level }} · {{ detail.risk_level }}</span>
              <span class="al-modal-h1">{{ detail.alert_title || detail.alert_code }}</span>
              <span class="al-modal-code">{{ detail.alert_code }}</span>
            </div>
            <div class="al-modal-hdr-badges">
              <span :class="['al-modal-status', 'al-modal-status--' + detail.status.toLowerCase()]">{{ STATUS_LABELS[detail.status] }}</span>
              <span v-if="tierOf(detail).tier === 1" class="al-modal-tier1">IHR TIER 1</span>
              <span v-if="detail.overdue_24h && detail.status !== 'CLOSED'" class="al-modal-overdue">OVERDUE</span>
            </div>
          </div>

          <div class="al-modal-body">

            <!-- Lifecycle timeline -->
            <section class="al-sec">
              <h3 class="al-sec-h">Alert Lifecycle</h3>
              <div class="al-timeline">
                <div class="al-tl-item al-tl-item--done">
                  <div class="al-tl-dot al-tl-dot--done">
                    <svg viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="2 5 4.5 7.5 8 2.5"/></svg>
                  </div>
                  <div class="al-tl-body">
                    <span class="al-tl-label">Created</span>
                    <span class="al-tl-meta">{{ fmtFull(detail.created_at) }} · {{ detail.generated_from === 'OFFICER' ? 'By officer' : 'Auto-generated' }}</span>
                    <span class="al-tl-meta">POE {{ detail.poe_code }} · {{ detail.district_code }}</span>
                  </div>
                </div>
                <div class="al-tl-line" :class="detail.status !== 'OPEN' ? 'al-tl-line--done' : 'al-tl-line--pending'"/>

                <div :class="['al-tl-item', detail.acknowledged_at ? 'al-tl-item--done' : detail.status === 'CLOSED' ? 'al-tl-item--skip' : 'al-tl-item--pending']">
                  <div :class="['al-tl-dot', detail.acknowledged_at ? 'al-tl-dot--done' : 'al-tl-dot--pending']">
                    <svg v-if="detail.acknowledged_at" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="2 5 4.5 7.5 8 2.5"/></svg>
                    <svg v-else viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="5" cy="5" r="3"/></svg>
                  </div>
                  <div class="al-tl-body">
                    <span class="al-tl-label">Acknowledged</span>
                    <span v-if="detail.acknowledged_by_name" class="al-tl-meta">{{ detail.acknowledged_by_name }}</span>
                    <span v-if="detail.acknowledged_at" class="al-tl-meta">{{ fmtFull(detail.acknowledged_at) }}</span>
                    <span v-if="!detail.acknowledged_at && detail.status !== 'CLOSED'" class="al-tl-meta al-tl-meta--pending">Awaiting acknowledgement</span>
                    <span v-if="detail.overdue_24h && detail.status === 'OPEN'" class="al-tl-meta al-tl-meta--over">{{ fmtAge(detail.hours_since_creation) }} elapsed — overdue</span>
                  </div>
                </div>
                <div class="al-tl-line" :class="detail.status === 'CLOSED' ? 'al-tl-line--done' : 'al-tl-line--pending'"/>

                <div :class="['al-tl-item', detail.routed_to_level !== 'DISTRICT' ? 'al-tl-item--done' : 'al-tl-item--pending']">
                  <div :class="['al-tl-dot', detail.routed_to_level !== 'DISTRICT' ? 'al-tl-dot--done' : 'al-tl-dot--pending']">
                    <svg v-if="detail.routed_to_level !== 'DISTRICT'" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="2 5 4.5 7.5 8 2.5"/></svg>
                    <svg v-else viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="5" cy="5" r="3"/></svg>
                  </div>
                  <div class="al-tl-body">
                    <span class="al-tl-label">Routed to {{ detail.routed_to_level }}</span>
                    <span class="al-tl-meta">Escalation level: {{ detail.routed_to_level }}</span>
                    <span v-if="nextEscStep(detail).target" class="al-tl-meta al-tl-meta--next">Next: {{ nextEscStep(detail).target }} within {{ nextEscStep(detail).within_hrs }}h</span>
                  </div>
                </div>
                <div class="al-tl-line" :class="detail.status === 'CLOSED' ? 'al-tl-line--done' : 'al-tl-line--pending'"/>

                <div :class="['al-tl-item', detail.status === 'CLOSED' ? 'al-tl-item--done' : 'al-tl-item--pending']">
                  <div :class="['al-tl-dot', detail.status === 'CLOSED' ? 'al-tl-dot--closed' : 'al-tl-dot--pending']">
                    <svg v-if="detail.status === 'CLOSED'" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="2 5 4.5 7.5 8 2.5"/></svg>
                    <svg v-else viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="5" cy="5" r="3"/></svg>
                  </div>
                  <div class="al-tl-body">
                    <span class="al-tl-label">Closed</span>
                    <span v-if="detail.closed_at" class="al-tl-meta">{{ fmtFull(detail.closed_at) }}</span>
                    <span v-if="detail.close_category" class="al-tl-meta">Category: {{ detail.close_category.replace(/_/g,' ') }}</span>
                    <span v-else class="al-tl-meta al-tl-meta--pending">Pending resolution</span>
                  </div>
                </div>
              </div>
            </section>

            <!-- Resolution metrics (only for closed) -->
            <section v-if="detail.status === 'CLOSED'" class="al-sec">
              <h3 class="al-sec-h">Resolution Metrics</h3>
              <div class="al-kv-card">
                <div class="al-kv"><span class="al-k">Time to Ack</span><span class="al-v">{{ fmtMins(diffMin(detail.created_at, detail.acknowledged_at)) }}</span></div>
                <div class="al-kv"><span class="al-k">Time to Close</span><span class="al-v">{{ fmtMins(diffMin(detail.acknowledged_at || detail.created_at, detail.closed_at)) }}</span></div>
                <div class="al-kv"><span class="al-k">Total Lifecycle</span><span class="al-v">{{ fmtMins(diffMin(detail.created_at, detail.closed_at)) }}</span></div>
              </div>
            </section>

            <!-- Traveller -->
            <section class="al-sec">
              <h3 class="al-sec-h">Traveller</h3>
              <div class="al-kv-card">
                <div class="al-kv"><span class="al-k">Name</span><span class="al-v">{{ detail.traveler_full_name || detail.traveler_name || '—' }}</span></div>
                <div class="al-kv"><span class="al-k">Nationality</span><span class="al-v">{{ detail.traveler_nationality_country_code || '—' }}</span></div>
                <div class="al-kv"><span class="al-k">Age</span><span class="al-v">{{ detail.traveler_age_or_dob || (detail.traveler_age_years ? detail.traveler_age_years + ' yrs' : '—') }}</span></div>
                <div class="al-kv"><span class="al-k">Gender</span><span class="al-v">{{ detail.traveler_gender || '—' }}</span></div>
                <div v-if="detail.syndrome" class="al-kv"><span class="al-k">Syndrome</span><span class="al-v al-v--alert">{{ detail.syndrome.replace(/_/g, ' ') }}</span></div>
                <div v-if="detail.top_disease_name" class="al-kv"><span class="al-k">Suspected disease</span><span class="al-v al-v--alert">{{ detail.top_disease_name }}{{ detail.top_disease_confidence ? ' (' + detail.top_disease_confidence + '%)' : '' }}</span></div>
                <div v-if="detail.temperature_value" class="al-kv"><span class="al-k">Temperature</span><span class="al-v" :class="detail.temperature_value >= 38 && 'al-v--fever'">{{ detail.temperature_value }}°{{ detail.temperature_unit || 'C' }}</span></div>
              </div>
            </section>

            <!-- Linked case -->
            <section v-if="detail.secondary_case" class="al-sec">
              <h3 class="al-sec-h">Linked Secondary Screening</h3>
              <div class="al-kv-card">
                <div class="al-kv"><span class="al-k">Case status</span><span class="al-v">{{ detail.secondary_case.case_status?.replace(/_/g,' ') || '—' }}</span></div>
                <div class="al-kv"><span class="al-k">Disposition</span><span class="al-v">{{ detail.secondary_case.final_disposition?.replace(/_/g,' ') || 'Pending' }}</span></div>
                <div class="al-kv"><span class="al-k">Case risk</span><span class="al-v">{{ detail.secondary_case.risk_level || '—' }}</span></div>
              </div>
              <button v-if="detail.secondary_screening_id || detail.secondary_case" class="al-view-record-btn" @click="openRecord(detail)">
                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="2" y="1" width="12" height="14" rx="2"/><line x1="5" y1="5" x2="11" y2="5"/><line x1="5" y1="8" x2="11" y2="8"/><line x1="5" y1="11" x2="8" y2="11"/></svg>
                View full screening record
              </button>
            </section>

            <!-- Responders -->
            <section class="al-sec">
              <h3 class="al-sec-h">Who Is Responding</h3>
              <div class="al-responders">
                <div v-if="detail.acknowledged_by_name" class="al-responder">
                  <div class="al-resp-avatar">{{ initials(detail.acknowledged_by_name) }}</div>
                  <div class="al-resp-body">
                    <span class="al-resp-name">{{ detail.acknowledged_by_name }}</span>
                    <span class="al-resp-role">Acknowledged · {{ fmtShort(detail.acknowledged_at) }}</span>
                  </div>
                  <span class="al-resp-badge al-resp-badge--ack">ACK</span>
                </div>
                <div v-if="!detail.acknowledged_by_name" class="al-no-responder">
                  <svg viewBox="0 0 16 16" fill="none" stroke="#94a3b8" stroke-width="1.5" stroke-linecap="round"><circle cx="8" cy="6" r="3"/><path d="M2 14c0-3.3 2.7-6 6-6s6 2.7 6 6"/></svg>
                  No one has acknowledged this alert yet
                </div>
              </div>
              <div v-if="!canAct(detail) && detail.status !== 'CLOSED'" class="al-rbac-note">
                <svg viewBox="0 0 16 16" fill="none" stroke="#64748b" stroke-width="1.5" stroke-linecap="round"><rect x="4" y="7" width="8" height="7" rx="1.5"/><path d="M6 7V5a2 2 0 1 1 4 0v2"/></svg>
                <span>Your role (<strong>{{ auth.role_key }}</strong>) can monitor but not action this alert.</span>
              </div>
            </section>

            <!-- 7-1-7 scorecard -->
            <section class="al-sec">
              <h3 class="al-sec-h">7-1-7 Response Scorecard</h3>
              <div :class="['al-717', 'al-717--' + scOf(detail).overall.toLowerCase()]">
                <div class="al-717-top">
                  <span class="al-717-label">{{ scOf(detail).overall.replace('_', ' ') }}</span>
                  <span v-if="scOf(detail).bottleneck" class="al-717-bn">{{ scOf(detail).bottleneck }}</span>
                </div>
                <div class="al-717-rows">
                  <div v-for="leg in [
                    { key: 'detect',  label: 'Detect',  target: 168, data: scOf(detail).detect  },
                    { key: 'notify',  label: 'Notify',  target: 24,  data: scOf(detail).notify  },
                    { key: 'respond', label: 'Respond', target: 168, data: scOf(detail).respond },
                  ]" :key="leg.key" class="al-717-row">
                    <span class="al-717-lbl">{{ leg.label }}</span>
                    <div class="al-717-track">
                      <div
                        :class="['al-717-fill', leg.data.on_target === null ? 'al-717-fill--pending' : leg.data.on_target ? 'al-717-fill--ok' : 'al-717-fill--bad']"
                        :style="{ width: pct(leg.data.hrs, leg.target) + '%' }"
                      />
                    </div>
                    <span class="al-717-val">
                      {{ leg.data.on_target === null ? '— open' : fmtAge(leg.data.hrs) + ' / ' + (leg.key === 'notify' ? '24h' : '7d') }}
                    </span>
                  </div>
                </div>
              </div>
            </section>

            <!-- IHR classification -->
            <section v-if="tierOf(detail).tier" class="al-sec">
              <h3 class="al-sec-h">IHR Classification</h3>
              <div :class="['al-ihr', 'al-ihr--t' + tierOf(detail).tier]">
                <div class="al-ihr-top">
                  <span class="al-ihr-tag">TIER {{ tierOf(detail).tier }}</span>
                  <span class="al-ihr-name">{{ tierOf(detail).name }}</span>
                </div>
                <p class="al-ihr-note">{{ tierOf(detail).reason }}</p>
              </div>
            </section>

            <!-- Details -->
            <section v-if="cleanDetails(detail.alert_details)" class="al-sec">
              <h3 class="al-sec-h">Details</h3>
              <div class="al-details-box">{{ cleanDetails(detail.alert_details) }}</div>
            </section>

            <!-- Close reason -->
            <section v-if="closeReason(detail.alert_details)" class="al-sec">
              <h3 class="al-sec-h">Close Reason</h3>
              <div class="al-details-box al-details-box--close">{{ closeReason(detail.alert_details) }}</div>
            </section>

            <!-- Accountability: who should acknowledge, have they, what's left -->
            <section class="al-sec">
              <h3 class="al-sec-h">Accountability & Remaining Actions</h3>
              <div class="al-accountability">
                <!-- Who is responsible -->
                <div class="al-acc-row">
                  <span class="al-acc-lbl">Responsible level</span>
                  <span class="al-acc-val al-acc-val--level">{{ detail.routed_to_level || 'DISTRICT' }}</span>
                </div>
                <!-- Have they acknowledged? -->
                <div class="al-acc-row">
                  <span class="al-acc-lbl">Acknowledgement</span>
                  <span v-if="detail.acknowledged_by_name" class="al-acc-val al-acc-val--done">
                    <svg viewBox="0 0 12 12" fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round"><polyline points="2 6 5 9 10 3"/></svg>
                    {{ detail.acknowledged_by_name }} · {{ fmtShort(detail.acknowledged_at) }}
                  </span>
                  <span v-else class="al-acc-val al-acc-val--pending">
                    <svg viewBox="0 0 12 12" fill="none" stroke="#f59e0b" stroke-width="1.8" stroke-linecap="round"><circle cx="6" cy="6" r="4"/><line x1="6" y1="4" x2="6" y2="6.5"/><circle cx="6" cy="8.5" r="0.5" fill="#f59e0b"/></svg>
                    Not yet acknowledged
                    <span v-if="detail.overdue_24h" class="al-acc-over"> — OVERDUE ({{ fmtAge(detail.hours_since_creation) }})</span>
                  </span>
                </div>
                <!-- Remaining actions -->
                <div class="al-acc-row">
                  <span class="al-acc-lbl">Remaining actions</span>
                  <div class="al-acc-tasks">
                    <span v-if="detail.status === 'OPEN'" class="al-acc-task al-acc-task--todo">Acknowledge alert</span>
                    <span v-if="detail.status === 'OPEN' || detail.status === 'ACKNOWLEDGED'" class="al-acc-task al-acc-task--todo">Complete response &amp; close</span>
                    <span v-if="detail.status === 'CLOSED'" class="al-acc-task al-acc-task--done">All actions complete</span>
                  </div>
                </div>
                <!-- What has been done -->
                <div class="al-acc-row">
                  <span class="al-acc-lbl">Completed actions</span>
                  <div class="al-acc-tasks">
                    <span class="al-acc-task al-acc-task--done">Alert created · {{ fmtShort(detail.created_at) }}</span>
                    <span v-if="detail.acknowledged_at" class="al-acc-task al-acc-task--done">Acknowledged · {{ fmtShort(detail.acknowledged_at) }}</span>
                    <span v-if="detail.status === 'CLOSED'" class="al-acc-task al-acc-task--done">Closed · {{ fmtShort(detail.closed_at) }}</span>
                  </div>
                </div>
                <!-- Alert state -->
                <div class="al-acc-row">
                  <span class="al-acc-lbl">Current state</span>
                  <span :class="['al-acc-val', 'al-modal-status', 'al-modal-status--' + detail.status.toLowerCase()]">{{ STATUS_LABELS[detail.status] }}</span>
                </div>
              </div>
            </section>

            <!-- Notes written about this alert -->
            <section v-if="detail.alert_details || detail.close_reason_text" class="al-sec">
              <h3 class="al-sec-h">Notes &amp; Activity</h3>
              <div class="al-notes-list">
                <div v-if="detail.alert_details && cleanDetails(detail.alert_details)" class="al-note-item">
                  <span class="al-note-tag">Detail</span>
                  <p class="al-note-body">{{ cleanDetails(detail.alert_details) }}</p>
                </div>
                <div v-if="closeReason(detail.alert_details)" class="al-note-item al-note-item--close">
                  <span class="al-note-tag al-note-tag--close">Close reason</span>
                  <p class="al-note-body">{{ closeReason(detail.alert_details) }}</p>
                </div>
                <div v-if="!detail.alert_details && !detail.close_reason_text" class="al-note-empty">
                  No notes have been written about this alert.
                </div>
              </div>
            </section>
            <section v-else class="al-sec">
              <h3 class="al-sec-h">Notes &amp; Activity</h3>
              <div class="al-note-empty">No notes have been written about this alert.</div>
            </section>

            <!-- Read-only notice -->
            <section class="al-sec">
              <div class="al-readonly-notice">
                <svg viewBox="0 0 16 16" fill="none" stroke="#64748b" stroke-width="1.5" stroke-linecap="round"><rect x="4" y="7" width="8" height="7" rx="1.5"/><path d="M6 7V5a2 2 0 1 1 4 0v2"/></svg>
                <span>Alerts are read-only here. Use <strong>My Cases</strong> or the war room to take action.</span>
              </div>
            </section>

            <div style="height: 40px"/>
          </div>
        </div>
      </Transition>
    </Teleport>

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color" :duration="3000" position="top" @didDismiss="toast.show = false"/>
  </IonPage>
</template>

<script setup>
import {
  IonPage, IonHeader, IonButtons, IonMenuButton,
  IonContent, IonToast, IonRefresher, IonRefresherContent,
  onIonViewWillEnter, onIonViewDidEnter,
} from '@ionic/vue'
import { ref, computed, reactive, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { APP, STORE, dbGetAll, dbPut } from '@/services/poeDB'
import engine, {
  classifyIHRTier, evaluate717, nextEscalation, canActOnAlert, userScope, compliance717Summary,
} from '@/composables/useAlertIntelligence'

const router = useRouter()
function getAuth() { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {} }
const auth  = ref(getAuth())
const scope = computed(() => userScope(auth.value))

// ── State ─────────────────────────────────────────────────────────────────
const alerts      = ref([])
const loading     = ref(false)
const loadingMore = ref(false)
const hasMore     = ref(false)
const page        = ref(1)
const online      = ref(navigator.onLine)
const detail      = ref(null)
const toast       = reactive({ show: false, msg: '', color: 'success' })
const searchQuery = ref('')
let pollTimer = null

// ── Filters ───────────────────────────────────────────────────────────────
const PERIOD_CHIPS = [
  { v: '7d',  l: '7 Days'   },
  { v: '30d', l: '30 Days'  },
  { v: '90d', l: '90 Days'  },
  { v: 'all', l: 'All Time' },
]
const STATUS_CHIPS = [
  { v: null,           l: 'All'    },
  { v: 'OPEN',         l: 'Open'   },
  { v: 'ACKNOWLEDGED', l: "Ack'd"  },
  { v: 'CLOSED',       l: 'Closed' },
]
const RISK_CHIPS = [
  { v: 'CRITICAL', l: 'Critical' },
  { v: 'HIGH',     l: 'High'     },
  { v: 'MEDIUM',   l: 'Medium'   },
  { v: 'LOW',      l: 'Low'      },
]
const STATUS_LABELS = { OPEN: 'Open', ACKNOWLEDGED: "Ack'd", CLOSED: 'Closed' }

const period       = ref('30d')
const statusFilter = ref(null)
const riskFilter   = ref(null)
const activeFilters = computed(() => !!(statusFilter.value || riskFilter.value || searchQuery.value))

function setPeriod(v) { period.value = v; loadAlerts(true) }
function setStatus(v) { statusFilter.value = v }
function setRisk(v)   { riskFilter.value = v }
function toggleStatus(v) { statusFilter.value = statusFilter.value === v ? null : v }
function toggleRisk(v) {
  if (v === '__BREACH__') { riskFilter.value = riskFilter.value === '__BREACH__' ? null : '__BREACH__'; return }
  riskFilter.value = riskFilter.value === v ? null : v
}
function clearAll() { statusFilter.value = null; riskFilter.value = null; searchQuery.value = '' }

// ── Computed ──────────────────────────────────────────────────────────────
const filteredAlerts = computed(() => {
  let a = alerts.value
  if (statusFilter.value) a = a.filter(x => x.status === statusFilter.value)
  if (riskFilter.value === '__BREACH__') a = a.filter(x => x.overdue_24h && x.status !== 'CLOSED')
  else if (riskFilter.value) a = a.filter(x => x.risk_level === riskFilter.value)
  if (searchQuery.value.trim()) {
    const q = searchQuery.value.trim().toLowerCase()
    a = a.filter(x =>
      (x.alert_code   || '').toLowerCase().includes(q) ||
      (x.alert_title  || '').toLowerCase().includes(q) ||
      (x.poe_code     || '').toLowerCase().includes(q) ||
      (x.syndrome     || '').toLowerCase().includes(q) ||
      (x.traveler_full_name || x.traveler_name || '').toLowerCase().includes(q)
    )
  }
  return a
})

const groupedAlerts = computed(() => {
  const groups = new Map()
  for (const a of filteredAlerts.value) {
    const d = (a.created_at || '').slice(0, 10)
    if (!d) continue
    if (!groups.has(d)) groups.set(d, [])
    groups.get(d).push(a)
  }
  return Array.from(groups.entries())
    .sort((a, b) => b[0].localeCompare(a[0]))
    .map(([date, items]) => ({ date, items, label: fmtGroupDate(date) }))
})

const counts = computed(() => ({
  total:    alerts.value.length,
  open:     alerts.value.filter(x => x.status === 'OPEN').length,
  critical: alerts.value.filter(x => x.risk_level === 'CRITICAL' && x.status !== 'CLOSED').length,
  high:     alerts.value.filter(x => x.risk_level === 'HIGH' && x.status !== 'CLOSED').length,
  breach:   alerts.value.filter(x => x.overdue_24h && x.status !== 'CLOSED').length,
  closed:   alerts.value.filter(x => x.status === 'CLOSED').length,
}))

const rollup = computed(() => compliance717Summary(alerts.value))

// ── Memoised intelligence ─────────────────────────────────────────────────
const _tierCache = new WeakMap()
function tierOf(a) {
  if (!a) return { tier: null, name: null, reason: null }
  if (_tierCache.has(a)) return _tierCache.get(a)
  const r = classifyIHRTier(a); _tierCache.set(a, r); return r
}
const _scCache = new WeakMap()
function scOf(a) {
  if (!a) return { detect: { hrs: null, on_target: null }, notify: { hrs: null, on_target: null }, respond: { hrs: null, on_target: null }, bottleneck: null, overall: 'ON_TARGET' }
  if (_scCache.has(a)) return _scCache.get(a)
  const r = evaluate717(a); _scCache.set(a, r); return r
}
function nextEscStep(a) { return nextEscalation(a) || {} }
function canAct(a) { return canActOnAlert(auth.value, a) }

// ── Offline-first ─────────────────────────────────────────────────────────
async function loadFromIDB() {
  try {
    const cached = await dbGetAll(STORE.ALERTS)
    if (cached?.length) {
      alerts.value = cached
        .filter(a => !a.deleted_at)
        .sort((a, b) => {
          const rOrder = { CRITICAL: 4, HIGH: 3, MEDIUM: 2, LOW: 1 }
          if (a.status === 'CLOSED' && b.status !== 'CLOSED') return 1
          if (b.status === 'CLOSED' && a.status !== 'CLOSED') return -1
          return (rOrder[b.risk_level] || 0) - (rOrder[a.risk_level] || 0)
        })
    }
  } catch { /* IDB unavailable */ }
}

function apiUrl(path) {
  const uid = auth.value?.id
  const sep = path.includes('?') ? '&' : '?'
  return `${(window.SERVER_URL || '').replace(/\/$/, '')}${path}${sep}user_id=${uid}`
}

function periodDates() {
  if (period.value === 'all') return { from: null, to: null }
  const days = period.value === '7d' ? 7 : period.value === '90d' ? 90 : 30
  const from = new Date(); from.setDate(from.getDate() - days)
  return { from: from.toISOString().slice(0, 10), to: new Date().toISOString().slice(0, 10) }
}

async function fetchAlerts(reset = false) {
  if (!auth.value?.id || loading.value) return
  loading.value = true
  if (reset) { page.value = 1; hasMore.value = false }
  const ctrl = new AbortController()
  const tid  = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS || 15000)
  try {
    const { from, to } = periodDates()
    const params = new URLSearchParams({ per_page: '60', page: String(page.value) })
    if (from) params.set('date_from', from)
    if (to)   params.set('date_to', to)
    const res = await fetch(apiUrl('/alerts?' + params.toString()), {
      headers: { Accept: 'application/json' }, signal: ctrl.signal,
    })
    clearTimeout(tid)
    if (!res.ok) { online.value = false; return }
    const j = await res.json()
    if (!j?.success) return
    online.value = true
    const items = j.data?.items || []
    if (reset) alerts.value = items
    else alerts.value = [...alerts.value, ...items]
    hasMore.value = (j.data?.page ?? 1) < (j.data?.pages ?? 1)
    for (const a of items) { try { await dbPut(STORE.ALERTS, a) } catch {} }
  } catch { online.value = false }
  finally { loading.value = false }
}

async function loadAlerts(reset = false) {
  if (reset) await loadFromIDB()
  await fetchAlerts(reset)
}

async function fetchMore() {
  if (loadingMore.value || !hasMore.value) return
  loadingMore.value = true
  page.value++
  try { await fetchAlerts(false) } finally { loadingMore.value = false }
}

async function onPull(ev) { await loadAlerts(true); ev.target.complete() }


// ── Navigation ────────────────────────────────────────────────────────────
function openDetail(a) { detail.value = { ...a }; document.body.style.overflow = 'hidden' }
function closeDetail()  { detail.value = null; document.body.style.overflow = '' }
function openWarRoom(a) { closeDetail(); router.push({ name: 'AlertWarRoom', params: { id: a.id } }) }

async function openRecord(a) {
  try {
    let uuid = a.secondary_case?.client_uuid || null
    if (!uuid) {
      const r = await fetch(apiUrl(`/alerts/${a.id}`), { headers: { Accept: 'application/json' } })
      const j = await r.json().catch(() => null)
      uuid = j?.data?.secondary_case?.client_uuid || null
    }
    if (uuid) { closeDetail(); router.push({ path: '/secondary-screening/records', query: { open: uuid } }); return }
    showToast('Case record not found locally.', 'warning')
  } catch { showToast('Unable to open record.', 'danger') }
}

// ── Helpers ───────────────────────────────────────────────────────────────
function showToast(msg, color = 'success') { toast.show = true; toast.msg = msg; toast.color = color }
function cleanDetails(d) { return (d || '').replace(/\[CLOSED:[^\]]+\]/g, '').trim() }
function closeReason(d) { const m = (d || '').match(/\[CLOSED:\s*([^\]]+)\]/); return m ? m[1].trim() : null }

function fmtGroupDate(d) {
  if (!d) return ''
  try {
    const today     = new Date().toISOString().slice(0, 10)
    const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10)
    if (d === today) return 'Today'
    if (d === yesterday) return 'Yesterday'
    return new Date(d + 'T00:00:00').toLocaleDateString([], { weekday: 'long', day: '2-digit', month: 'short', year: 'numeric' })
  } catch { return d }
}
function fmtAge(h) {
  if (h == null) return '—'
  if (h < 1)  return Math.round(h * 60) + 'm'
  if (h < 24) return (Math.round(h * 10) / 10) + 'h'
  return (Math.round(h / 24 * 10) / 10) + 'd'
}
function fmtShort(dt) {
  if (!dt) return ''
  try { return new Date(String(dt).replace(' ', 'T')).toLocaleDateString([], { day: '2-digit', month: 'short' }) } catch { return '' }
}
function fmtFull(dt) {
  if (!dt) return '—'
  try { return new Date(String(dt).replace(' ', 'T')).toLocaleString([], { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) } catch { return dt }
}
function diffMin(start, end) {
  if (!start || !end) return null
  try { return Math.round((new Date(String(end).replace(' ', 'T')) - new Date(String(start).replace(' ', 'T'))) / 60000) } catch { return null }
}
function fmtMins(m) {
  if (m == null) return '—'
  if (m < 60) return m + ' min'
  const h = m / 60
  if (h < 24) return h.toFixed(1) + ' h'
  return (h / 24).toFixed(1) + ' d'
}
function pct(val, max) { if (val == null || !max) return 0; return Math.max(0, Math.min(100, Math.round(val / max * 100))) }
function initials(name) { return (name || '').split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2) || '?' }

// ── Lifecycle ─────────────────────────────────────────────────────────────
function onOnline()  { online.value = true;  loadAlerts(true) }
function onOffline() { online.value = false }

onMounted(async () => {
  auth.value = getAuth()
  window.addEventListener('online',  onOnline)
  window.addEventListener('offline', onOffline)
  await loadFromIDB()
  await fetchAlerts(true)
  pollTimer = setInterval(() => { if (online.value && !loading.value) fetchAlerts(true) }, 30_000)
})
onIonViewWillEnter(() => { auth.value = getAuth(); loadAlerts(true) })
onIonViewDidEnter(() => { if (online.value && !loading.value) fetchAlerts(true) })
onUnmounted(() => {
  window.removeEventListener('online',  onOnline)
  window.removeEventListener('offline', onOffline)
  clearInterval(pollTimer)
  if (detail.value) document.body.style.overflow = ''
})
</script>

<style scoped>
* { box-sizing: border-box }

/* ── Header ─────────────────────────────────────────────────────────────── */
.al-hdr { --background: transparent; border: none }
.al-hdr-bg { background: linear-gradient(135deg, #001D3D, #003566, #003F88); padding-bottom: 4px }
.al-hdr-row { display: flex; align-items: center; gap: 4px; padding: 6px 8px 0 }
.al-menu-btn { --color: rgba(255,255,255,.7) }
.al-hdr-center { flex: 1; display: flex; flex-direction: column; min-width: 0 }
.al-hdr-eye { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: rgba(255,255,255,.4) }
.al-hdr-title { font-size: 17px; font-weight: 800; color: #fff }
.al-hdr-right { display: flex; align-items: center; gap: 6px }
.al-live-badge { background: #DC2626; color: #fff; font-size: 11px; font-weight: 800; min-width: 20px; height: 20px; border-radius: 10px; display: flex; align-items: center; justify-content: center; padding: 0 5px }
.al-hdr-btn { width: 32px; height: 32px; border-radius: 50%; border: 1px solid rgba(255,255,255,.1); background: rgba(255,255,255,.05); color: rgba(255,255,255,.7); cursor: pointer; display: flex; align-items: center; justify-content: center }
.al-hdr-btn:disabled { opacity: .4 }
.al-spin { animation: al-spin 1s linear infinite }
@keyframes al-spin { to { transform: rotate(360deg) } }

/* ── Stats ──────────────────────────────────────────────────────────────── */
.al-stats { display: flex; gap: 4px; padding: 8px }
.al-stat { flex: 1; padding: 7px 3px; border-radius: 8px; background: rgba(255,255,255,.06); text-align: center; cursor: pointer; transition: background .15s, transform .1s }
.al-stat:active, .al-stat--on { background: rgba(255,255,255,.18); transform: scale(.97) }
.al-stat-n { display: block; font-size: 18px; font-weight: 900; color: #fff; line-height: 1 }
.al-stat-l { display: block; font-size: 9px; font-weight: 700; text-transform: uppercase; color: rgba(255,255,255,.5); margin-top: 2px }
.al-stat--crit .al-stat-n { color: #FF8A80 }
.al-stat--high .al-stat-n { color: #FFB74D }
.al-stat--breach .al-stat-n { color: #CE93D8 }
.al-stat--closed .al-stat-n { color: #90CAF9 }

/* ── Period row ─────────────────────────────────────────────────────────── */
.al-period-row { display: flex; gap: 4px; padding: 0 8px 4px; overflow-x: auto; scrollbar-width: none }
.al-period-row::-webkit-scrollbar { display: none }
.al-period { padding: 4px 12px; border-radius: 99px; border: 1px solid rgba(255,255,255,.12); background: transparent; color: rgba(255,255,255,.5); font-size: 11px; font-weight: 700; cursor: pointer; white-space: nowrap; flex-shrink: 0 }
.al-period--on { background: rgba(255,255,255,.2); color: #fff; border-color: rgba(255,255,255,.3) }

/* ── Filter chips ───────────────────────────────────────────────────────── */
.al-filters { padding: 0 8px 6px }
.al-filter-scroll { display: flex; gap: 4px; overflow-x: auto; scrollbar-width: none; align-items: center }
.al-filter-scroll::-webkit-scrollbar { display: none }
.al-chip { padding: 4px 10px; border-radius: 99px; border: 1px solid rgba(255,255,255,.12); background: transparent; color: rgba(255,255,255,.55); font-size: 11px; font-weight: 700; cursor: pointer; white-space: nowrap; flex-shrink: 0 }
.al-chip--on { background: rgba(255,255,255,.2); color: #fff; border-color: rgba(255,255,255,.3) }
.al-chip--critical { border-color: rgba(220,38,38,.5); color: #FF8A80 }
.al-chip--critical.al-chip--on { background: rgba(220,38,38,.3) }
.al-chip--high { border-color: rgba(234,88,12,.5); color: #FFB74D }
.al-chip--high.al-chip--on { background: rgba(234,88,12,.3) }
.al-chip--medium { border-color: rgba(202,138,4,.5); color: #FDE68A }
.al-chip--medium.al-chip--on { background: rgba(202,138,4,.3) }
.al-chip--low { border-color: rgba(16,185,129,.5); color: #6EE7B7 }
.al-chip--low.al-chip--on { background: rgba(16,185,129,.25) }
.al-filter-div { width: 1px; height: 16px; background: rgba(255,255,255,.15); flex-shrink: 0; margin: 0 2px }

/* ── Content ────────────────────────────────────────────────────────────── */
.al-content { --background: #F0F4FA }
.al-offline { padding: 8px 12px; background: #FFF3E0; border-bottom: 1px solid #FFB74D; font-size: 11px; color: #BF360C; text-align: center; font-weight: 600; display: flex; align-items: center; justify-content: center; gap: 6px }

/* ── 7-1-7 compliance strip ─────────────────────────────────────────────── */
.al-compl { margin: 8px 10px 0; padding: 10px 12px; background: #fff; border: 1px solid #E8EDF5; border-radius: 10px; box-shadow: 0 1px 2px rgba(0,0,0,.03) }
.al-compl-hdr { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px }
.al-compl-title { font-size: 11px; font-weight: 800; color: #475569; text-transform: uppercase; letter-spacing: .5px }
.al-compl-period { font-size: 10px; font-weight: 700; color: #94A3B8 }
.al-compl-bars { display: flex; flex-direction: column; gap: 6px }
.al-compl-bar-row { display: flex; align-items: center; gap: 8px }
.al-compl-lbl { width: 76px; font-size: 11px; font-weight: 700; color: #1A3A5C; flex-shrink: 0 }
.al-compl-track { flex: 1; height: 6px; background: #F1F5F9; border-radius: 3px; overflow: hidden }
.al-compl-fill { height: 100%; border-radius: 3px; transition: width .4s }
.al-compl-fill--ok  { background: linear-gradient(90deg, #10B981, #047857) }
.al-compl-fill--bad { background: linear-gradient(90deg, #EF4444, #DC2626) }
.al-compl-pct { width: 38px; text-align: right; font-size: 11px; font-weight: 800; color: #047857; font-variant-numeric: tabular-nums }
.al-compl-pct--bad { color: #DC2626 }

/* ── Search ─────────────────────────────────────────────────────────────── */
.al-search-wrap { padding: 8px 10px 4px }
.al-search-inner { display: flex; align-items: center; background: #fff; border: 1px solid #E2E8F0; border-radius: 9px; padding: 0 10px; gap: 6px }
.al-search-ico { width: 14px; height: 14px; color: #94A3B8; flex-shrink: 0 }
.al-search-input { flex: 1; border: none; outline: none; font-size: 13px; color: #1A3A5C; padding: 10px 0; background: transparent }
.al-search-input::placeholder { color: #94A3B8 }
.al-search-clear { padding: 4px; color: #94A3B8; border: none; background: none; cursor: pointer; display: flex; align-items: center }

/* ── Skeleton ───────────────────────────────────────────────────────────── */
.al-skels { padding: 8px 10px }
.al-group-hdr-skel { height: 14px; width: 120px; background: #E2E8F0; border-radius: 4px; margin: 10px 4px 8px }
.al-skel { display: flex; background: #fff; border-radius: 8px; border: 1px solid #E8EDF5; overflow: hidden; margin-bottom: 6px; padding: 12px }
.al-skel-stripe { width: 3px; flex-shrink: 0; background: #E2E8F0; border-radius: 2px; margin-right: 10px }
.al-skel-body { flex: 1; display: flex; flex-direction: column; gap: 6px }
.al-skel-line { height: 10px; background: linear-gradient(90deg,#E2E8F0 25%,#F1F5F9 50%,#E2E8F0 75%); background-size: 200% 100%; animation: al-sh 1.4s linear infinite; border-radius: 4px }
.al-skel-line--wide { width: 75% }
.al-skel-line--mid  { width: 55% }
.al-skel-line--short { width: 35% }
@keyframes al-sh { 0%{background-position:200% 0}100%{background-position:-200% 0} }

/* ── Empty ──────────────────────────────────────────────────────────────── */
.al-empty { display: flex; flex-direction: column; align-items: center; padding: 60px 20px; gap: 10px }
.al-empty-t { font-size: 18px; font-weight: 800; color: #1A3A5C; margin: 0 }
.al-empty-s { font-size: 13px; color: #64748B; text-align: center; max-width: 280px; margin: 0 }
.al-clear-btn { padding: 8px 20px; border-radius: 99px; border: 1px solid #CBD5E1; background: #fff; color: #475569; font-size: 12px; font-weight: 700; cursor: pointer }

/* ── Date groups ────────────────────────────────────────────────────────── */
.al-list { padding: 4px 10px }
.al-group { margin-bottom: 12px }
.al-group-hdr { display: flex; align-items: center; justify-content: space-between; padding: 6px 4px 4px }
.al-group-date { font-size: 11px; font-weight: 800; color: #1A3A5C; text-transform: uppercase; letter-spacing: .5px }
.al-group-count { font-size: 10px; color: #94A3B8; font-weight: 600 }

/* ── Alert cards ────────────────────────────────────────────────────────── */
.al-card { display: flex; align-items: stretch; background: #fff; border-radius: 9px; border: 1px solid #E8EDF5; overflow: hidden; margin-bottom: 6px; cursor: pointer; box-shadow: 0 1px 2px rgba(0,0,0,.04); width: 100%; text-align: left }
.al-card--overdue { border-color: #FECACA; box-shadow: 0 0 0 1px rgba(220,38,38,.12) }
.al-card--closed { opacity: .72 }
.al-stripe { width: 3px; flex-shrink: 0 }
.al-stripe--critical { background: #DC2626 }
.al-stripe--high     { background: #EA580C }
.al-stripe--medium   { background: #CA8A04 }
.al-stripe--low      { background: #10B981 }
.al-card-body { flex: 1; padding: 9px 10px; min-width: 0 }
.al-card-top { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; margin-bottom: 4px }
.al-risk { font-size: 9px; font-weight: 800; padding: 2px 5px; border-radius: 3px; letter-spacing: .3px }
.al-risk--critical { background: #FEE2E2; color: #991B1B }
.al-risk--high      { background: #FFEDD5; color: #9A3412 }
.al-risk--medium    { background: #FEF3C7; color: #854D0E }
.al-risk--low       { background: #D1FAE5; color: #047857 }
.al-status { font-size: 9px; font-weight: 700; padding: 2px 5px; border-radius: 3px }
.al-status--open         { background: #FEE2E2; color: #991B1B }
.al-status--acknowledged { background: #DBEAFE; color: #1E40AF }
.al-status--closed       { background: #F1F5F9; color: #64748B }
.al-tier { font-size: 9px; font-weight: 800; padding: 2px 5px; border-radius: 3px }
.al-tier--1 { background: #F3E8FF; color: #6B21A8; border: 1px solid #D8B4FE }
.al-breach { font-size: 9px; font-weight: 800; padding: 2px 5px; border-radius: 3px; background: #7F1D1D; color: #FEE2E2 }
.al-age { margin-left: auto; font-size: 10px; color: #94A3B8; font-weight: 600 }
.al-age--over { color: #DC2626 }
.al-card-title { font-size: 13px; font-weight: 800; color: #1A3A5C; line-height: 1.3; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis }
.al-card-traveller { display: flex; align-items: center; gap: 4px; font-size: 11px; color: #475569; margin-bottom: 5px }
.al-card-traveller svg { width: 12px; height: 12px; flex-shrink: 0 }
.al-nat { color: #94A3B8 }
.al-card-meta { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 2px }
.al-chip-sm { font-size: 9px; font-weight: 700; padding: 2px 5px; border-radius: 4px; background: #F1F5F9; color: #475569 }
.al-chip-sm--poe { background: #DBEAFE; color: #1E40AF }
.al-chip-sm--disease { background: #FEF3C7; color: #854D0E }
.al-chip-sm--route--national { background: #E0E7FF; color: #3730A3 }
.al-chip-sm--route--pheoc { background: #FCE7F3; color: #9D174D }
.al-chip-sm--route--district { background: #DCFCE7; color: #166534 }
.al-card-ack { display: flex; align-items: center; gap: 4px; font-size: 10px; color: #059669; font-weight: 600; margin-top: 3px }
.al-card-ack svg { width: 12px; height: 12px }
.al-card-closed { display: flex; align-items: center; gap: 4px; font-size: 10px; color: #64748B; font-style: italic; margin-top: 3px }
.al-card-closed svg { width: 12px; height: 12px }
.al-card-arrow { display: flex; align-items: center; padding: 0 10px; flex-shrink: 0 }

.al-more { display: flex; justify-content: center; padding: 10px 0 }
.al-more-btn { padding: 8px 22px; border-radius: 99px; border: 1px solid #CBD5E1; background: #fff; color: #475569; font-size: 12px; font-weight: 700; cursor: pointer }
.al-more-btn:disabled { opacity: .4 }

/* ── Full-screen modal ──────────────────────────────────────────────────── */
.al-modal { position: fixed; inset: 0; z-index: 99999; background: #F8FAFC; overflow-y: auto; -webkit-overflow-scrolling: touch; display: flex; flex-direction: column }
.al-slide-enter-active { transition: transform .28s cubic-bezier(.22,.68,0,1.2) }
.al-slide-leave-active { transition: transform .22s ease-in }
.al-slide-enter-from, .al-slide-leave-to { transform: translateX(100%) }

.al-modal-hdr { display: flex; align-items: flex-start; gap: 10px; padding: 52px 14px 14px }
.al-modal-hdr--critical { background: linear-gradient(135deg,#7F1D1D,#991B1B) }
.al-modal-hdr--high     { background: linear-gradient(135deg,#7C2D12,#9A3412) }
.al-modal-hdr--medium   { background: linear-gradient(135deg,#78350F,#92400E) }
.al-modal-hdr--low      { background: linear-gradient(135deg,#064E3B,#047857) }
.al-modal-back { width: 34px; height: 34px; border-radius: 50%; border: 1px solid rgba(255,255,255,.2); background: rgba(255,255,255,.1); color: #fff; cursor: pointer; flex-shrink: 0; display: flex; align-items: center; justify-content: center; margin-top: 2px }
.al-modal-hdr-body { flex: 1; min-width: 0 }
.al-modal-eye { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .8px; color: rgba(255,255,255,.6); margin-bottom: 4px }
.al-modal-h1 { display: block; font-size: 18px; font-weight: 800; color: #fff; line-height: 1.25 }
.al-modal-code { display: block; font-size: 11px; color: rgba(255,255,255,.5); margin-top: 4px; font-family: monospace }
.al-modal-hdr-badges { display: flex; flex-direction: column; gap: 4px; align-items: flex-end; flex-shrink: 0 }
.al-modal-status { font-size: 10px; font-weight: 800; padding: 3px 7px; border-radius: 4px }
.al-modal-status--open         { background: #FEE2E2; color: #991B1B }
.al-modal-status--acknowledged { background: #DBEAFE; color: #1E40AF }
.al-modal-status--closed       { background: rgba(255,255,255,.15); color: rgba(255,255,255,.8) }
.al-modal-tier1 { font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 4px; background: #F3E8FF; color: #6B21A8 }
.al-modal-overdue { font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 4px; background: #7F1D1D; color: #FEE2E2 }

.al-modal-body { flex: 1; padding: 0 14px }

/* ── Sections ───────────────────────────────────────────────────────────── */
.al-sec { margin: 18px 0 0 }
.al-sec-h { font-size: 12px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px; color: #64748B; margin: 0 0 8px }
.al-sec--actions { display: flex; flex-direction: column; gap: 8px }
.al-sec--warroom { padding-bottom: 0 }

/* ── Timeline ───────────────────────────────────────────────────────────── */
.al-timeline { background: #F8FAFC; border: 1px solid #E8EDF5; border-radius: 10px; padding: 12px 14px }
.al-tl-item { display: flex; gap: 10px; align-items: flex-start }
.al-tl-item--done { opacity: 1 }
.al-tl-item--pending { opacity: .55 }
.al-tl-item--skip { opacity: .3 }
.al-tl-line { width: 2px; height: 12px; background: #E2E8F0; border-radius: 1px; margin: 3px 0 3px 9px }
.al-tl-line--done { background: #10B981 }
.al-tl-line--pending { background: #E2E8F0 }
.al-tl-dot { width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0 }
.al-tl-dot--done   { background: #10B981; color: #fff }
.al-tl-dot--closed { background: #6366F1; color: #fff }
.al-tl-dot--pending { border: 2px solid #CBD5E1; background: #fff }
.al-tl-body { flex: 1; padding-bottom: 2px }
.al-tl-label { display: block; font-size: 12px; font-weight: 700; color: #1A3A5C }
.al-tl-meta { display: block; font-size: 11px; color: #64748B; margin-top: 1px }
.al-tl-meta--pending { color: #F59E0B; font-style: italic }
.al-tl-meta--over { color: #DC2626; font-weight: 700 }
.al-tl-meta--next { color: #6366F1 }

/* ── KV card ────────────────────────────────────────────────────────────── */
.al-kv-card { background: #F8FAFC; border: 1px solid #E8EDF5; border-radius: 10px; overflow: hidden }
.al-kv { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; border-top: 1px solid #E8EDF5 }
.al-kv:first-child { border-top: none }
.al-k { font-size: 11px; color: #64748B; font-weight: 600 }
.al-v { font-size: 12px; font-weight: 700; color: #1A3A5C }
.al-v--alert { color: #9A3412 }
.al-v--fever  { color: #DC2626 }

/* ── Responders ─────────────────────────────────────────────────────────── */
.al-responders { background: #F8FAFC; border: 1px solid #E8EDF5; border-radius: 10px; overflow: hidden }
.al-responder { display: flex; align-items: center; gap: 10px; padding: 10px 12px }
.al-resp-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg,#1E40AF,#3B82F6); color: #fff; font-size: 13px; font-weight: 800; display: flex; align-items: center; justify-content: center; flex-shrink: 0 }
.al-resp-body { flex: 1; min-width: 0 }
.al-resp-name { display: block; font-size: 12px; font-weight: 700; color: #1A3A5C }
.al-resp-role { display: block; font-size: 10px; color: #64748B }
.al-resp-badge { font-size: 9px; font-weight: 800; padding: 3px 6px; border-radius: 4px }
.al-resp-badge--ack { background: #D1FAE5; color: #047857 }
.al-no-responder { display: flex; align-items: center; gap: 8px; padding: 12px; font-size: 12px; color: #94A3B8; font-style: italic }
.al-no-responder svg { width: 20px; height: 20px; flex-shrink: 0 }
.al-rbac-note { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; background: #F8FAFC; border: 1px solid #E8EDF5; border-radius: 8px; margin-top: 8px; font-size: 11px; color: #64748B }
.al-rbac-note svg { width: 14px; height: 14px; flex-shrink: 0; margin-top: 1px }

/* ── 7-1-7 scorecard ────────────────────────────────────────────────────── */
.al-717 { background: #F8FAFC; border: 1px solid #E8EDF5; border-radius: 10px; padding: 12px }
.al-717--breach { border-color: #FECACA; background: #FFF5F5 }
.al-717--on_target { border-color: #BBF7D0 }
.al-717-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px }
.al-717-label { font-size: 12px; font-weight: 800; color: #1A3A5C }
.al-717--breach .al-717-label { color: #DC2626 }
.al-717-bn { font-size: 10px; font-weight: 700; color: #DC2626 }
.al-717-rows { display: flex; flex-direction: column; gap: 8px }
.al-717-row { display: flex; align-items: center; gap: 8px }
.al-717-lbl { width: 52px; font-size: 11px; font-weight: 700; color: #64748B; flex-shrink: 0 }
.al-717-track { flex: 1; height: 6px; background: #E2E8F0; border-radius: 3px; overflow: hidden }
.al-717-fill { height: 100%; border-radius: 3px; transition: width .4s }
.al-717-fill--ok      { background: linear-gradient(90deg, #10B981, #047857) }
.al-717-fill--bad     { background: linear-gradient(90deg, #EF4444, #DC2626) }
.al-717-fill--pending { background: #CBD5E1 }
.al-717-val { width: 80px; font-size: 10px; font-weight: 700; color: #64748B; text-align: right; font-variant-numeric: tabular-nums }

/* ── IHR ────────────────────────────────────────────────────────────────── */
.al-ihr { background: #F8FAFC; border: 1px solid #E8EDF5; border-radius: 10px; padding: 12px }
.al-ihr--t1 { border-color: #FECACA; background: #FFF5F5 }
.al-ihr--t2 { border-color: #FDE68A; background: #FFFBEB }
.al-ihr-top { display: flex; align-items: center; gap: 8px; margin-bottom: 6px }
.al-ihr-tag { font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 4px }
.al-ihr--t1 .al-ihr-tag { background: #FEE2E2; color: #991B1B }
.al-ihr--t2 .al-ihr-tag { background: #FEF3C7; color: #854D0E }
.al-ihr-name { font-size: 12px; font-weight: 700; color: #1A3A5C }
.al-ihr-note { font-size: 11px; color: #64748B; margin: 0; line-height: 1.4 }

/* ── Details ────────────────────────────────────────────────────────────── */
.al-details-box { background: #F8FAFC; border: 1px solid #E8EDF5; border-radius: 10px; padding: 12px; font-size: 12px; color: #475569; line-height: 1.5 }
.al-details-box--close { background: #FEF2F2; border-color: #FECACA; color: #991B1B }

/* ── View record ────────────────────────────────────────────────────────── */
.al-view-record-btn { display: flex; align-items: center; gap: 6px; margin-top: 8px; padding: 8px 14px; border-radius: 8px; border: 1px solid #DBEAFE; background: #EFF6FF; color: #1E40AF; font-size: 12px; font-weight: 700; cursor: pointer; width: 100% }

/* ── Action buttons ─────────────────────────────────────────────────────── */
.al-action { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px; border-radius: 10px; border: none; font-size: 14px; font-weight: 700; cursor: pointer; width: 100% }
.al-action:disabled { opacity: .5 }
.al-action--ack   { background: #10B981; color: #fff }
.al-action--close { background: #F1F5F9; color: #475569; border: 1px solid #E2E8F0 }

/* ── War room ───────────────────────────────────────────────────────────── */
.al-warroom-btn { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 14px; border-radius: 10px; border: 1.5px solid #C7D2FE; background: #EEF2FF; color: #3730A3; font-size: 14px; font-weight: 700; cursor: pointer; width: 100% }

/* ── Close sheet ────────────────────────────────────────────────────────── */
.al-sheet { --background: #fff }
.al-sheet-body { padding: 14px 16px 32px }
.al-sheet-handle { width: 36px; height: 4px; background: #E2E8F0; border-radius: 2px; margin: 0 auto 14px }
.al-sheet-title { font-size: 17px; font-weight: 800; color: #1A3A5C; margin: 0 0 4px }
.al-sheet-sub { font-size: 12px; color: #64748B; margin: 0 0 12px }
.al-sheet-tx { width: 100%; padding: 10px 12px; border: 1px solid #E2E8F0; border-radius: 8px; font-size: 13px; color: #1A3A5C; outline: none; resize: vertical; font-family: inherit; line-height: 1.5 }
.al-sheet-tx:focus { border-color: #1565C0 }
.al-sheet-err { font-size: 12px; color: #DC2626; margin-top: 6px }
/* ── Accountability section ─────────────────────────────────────────────── */
.al-accountability { display: flex; flex-direction: column; gap: 0; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px; overflow: hidden }
.al-acc-row { display: flex; flex-direction: column; gap: 4px; padding: 10px 14px; border-bottom: 1px solid #F1F5F9 }
.al-acc-row:last-child { border-bottom: none }
.al-acc-lbl { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #94A3B8 }
.al-acc-val { font-size: 13px; font-weight: 600; color: #1A3A5C }
.al-acc-val--level { display: inline-flex; align-items: center; background: #EEF2FF; color: #3730A3; border-radius: 4px; padding: 2px 8px; font-size: 11px; font-weight: 800; letter-spacing: .05em }
.al-acc-val--done { display: flex; align-items: center; gap: 5px; color: #059669; font-weight: 600; font-size: 13px }
.al-acc-val--done svg { width: 13px; height: 13px; flex-shrink: 0 }
.al-acc-val--pending { display: flex; align-items: center; gap: 5px; color: #92400E; font-weight: 600; font-size: 13px }
.al-acc-val--pending svg { width: 13px; height: 13px; flex-shrink: 0 }
.al-acc-over { color: #DC2626; font-weight: 700; font-size: 12px }
.al-acc-tasks { display: flex; flex-direction: column; gap: 4px }
.al-acc-task { font-size: 12px; padding: 3px 8px; border-radius: 4px; display: inline-flex; align-items: center; gap: 5px; width: fit-content }
.al-acc-task--todo { background: #FEF3C7; color: #92400E }
.al-acc-task--done { background: #DCFCE7; color: #15803D }

/* ── Notes section ─────────────────────────────────────────────────────── */
.al-notes-list { display: flex; flex-direction: column; gap: 8px }
.al-note-item { padding: 10px 12px; background: #F8FAFC; border: 1px solid #E2E8F0; border-left: 3px solid #CBD5E1; border-radius: 8px }
.al-note-item--close { border-left-color: #94A3B8 }
.al-note-tag { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #64748B; display: block; margin-bottom: 4px }
.al-note-tag--close { color: #475569 }
.al-note-body { font-size: 13px; color: #334155; line-height: 1.5; margin: 0; white-space: pre-wrap }
.al-note-empty { font-size: 13px; color: #94A3B8; padding: 10px 0; font-style: italic }

/* ── Read-only notice ──────────────────────────────────────────────────── */
.al-readonly-notice { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; background: #F1F5F9; border: 1px solid #CBD5E1; border-radius: 8px; font-size: 12px; color: #64748B }
.al-readonly-notice svg { width: 14px; height: 14px; flex-shrink: 0; margin-top: 1px }

@media (min-width: 500px) {
  .al-list, .al-search-wrap, .al-compl { max-width: 480px; margin-left: auto; margin-right: auto }
}
</style>
