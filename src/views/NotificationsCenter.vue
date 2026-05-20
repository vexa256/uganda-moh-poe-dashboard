<template>
  <IonPage class="nq-page">

    <!-- ═══════════════════════════════════════════════════════════════
         DARK ZONE — Header
    ═══════════════════════════════════════════════════════════════════ -->
    <IonHeader :translucent="false" class="nq-hdr">
      <IonToolbar class="nq-toolbar">
        <IonButtons slot="start">
          <IonMenuButton class="nq-menu-btn" menu="app-menu" />
        </IonButtons>
        <IonTitle class="nq-toolbar-title">
          <div class="nq-title-block">
            <span class="nq-eyebrow">IHR ART.23 · {{ auth?.poe_code || 'POE' }}</span>
            <span class="nq-title-text">Referral Queue</span>
          </div>
        </IonTitle>
        <IonButtons slot="end" style="gap:6px;padding-right:10px;">
          <div class="nq-conn" :class="isOnline ? 'nq-conn--on' : 'nq-conn--off'">
            <div class="nq-conn-dot"/>
            <span class="nq-conn-txt">{{ isOnline ? 'Live' : 'Offline' }}</span>
          </div>
          <button class="nq-hbtn" @click="manualRefresh"
            :disabled="loading || syncing" aria-label="Refresh queue">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"
              stroke-linecap="round" :class="(loading || syncing) && 'nq-spin'">
              <path d="M14 7A7 7 0 1 1 7 1"/><polyline points="14 1 14 7 8 7"/>
            </svg>
          </button>
        </IonButtons>
      </IonToolbar>

      <!-- Stats + tabs — in dark band outside IonToolbar -->
      <div class="nq-below-bar">

        <!-- O(1) stat strip — all from IDB index counts, never full scans -->
        <div class="nq-stats">
          <button class="nq-stat" :class="activeTab==='OPEN' && 'nq-stat--sel'"
            @click="setTab('OPEN')" aria-label="Open referrals">
            <span class="nq-sn nq-sn--blue">{{ fmt(idbOpen) }}</span>
            <span class="nq-sl">Open</span>
          </button>
          <div class="nq-sdiv"/>
          <button class="nq-stat" :class="activeTab==='OPEN' && 'nq-stat--sel'"
            @click="setTab('OPEN')" aria-label="Critical priority">
            <span class="nq-sn nq-sn--red">{{ fmt(critCount) }}</span>
            <span class="nq-sl">Critical</span>
          </button>
          <div class="nq-sdiv"/>
          <button class="nq-stat" :class="activeTab==='IN_PROGRESS' && 'nq-stat--sel'"
            @click="setTab('IN_PROGRESS')" aria-label="Cases in progress">
            <span class="nq-sn nq-sn--cyan">{{ fmt(idbInProg) }}</span>
            <span class="nq-sl">In Progress</span>
          </button>
          <div class="nq-sdiv"/>
          <button class="nq-stat" :class="activeTab==='CLOSED' && 'nq-stat--sel'"
            @click="setTab('CLOSED')" aria-label="Closed">
            <span class="nq-sn nq-sn--muted">{{ fmt(idbClosed) }}</span>
            <span class="nq-sl">Closed</span>
          </button>
          <div class="nq-sdiv"/>
          <button class="nq-stat"
            :class="[damaged.length > 0 && 'nq-stat--warn', activeTab==='DAMAGED' && 'nq-stat--sel']"
            @click="setTab('DAMAGED')" aria-label="Damaged records">
            <span class="nq-sn nq-sn--orange">{{ fmt(damaged.length) }}</span>
            <span class="nq-sl">Damaged</span>
          </button>
        </div>

        <!-- Tabs -->
        <nav class="nq-tabs" role="tablist" aria-label="Queue filter tabs">
          <button v-for="t in TABS" :key="t.v"
            class="nq-tab" :class="activeTab === t.v && 'nq-tab--on'"
            role="tab" :aria-selected="activeTab === t.v"
            @click="setTab(t.v)">
            {{ t.label }}
            <span v-if="tabBadge(t.v)" class="nq-tab-badge"
              :class="'nq-tb--' + t.v.toLowerCase().replace(/_/g, '-')">
              {{ tabBadge(t.v) }}
            </span>
          </button>
        </nav>
      </div>
    </IonHeader>

    <!-- ═══════════════════════════════════════════════════════════════
         LIGHT ZONE — Content
    ═══════════════════════════════════════════════════════════════════ -->
    <IonContent :fullscreen="true" :scroll-y="true" class="nq-content">
      <IonRefresher slot="fixed"
        @ionRefresh="ev => manualRefresh().finally(() => ev.target.complete())">
        <IonRefresherContent refreshing-text="Syncing queue…" refreshing-spinner="crescent"/>
      </IonRefresher>

      <!-- ── Offline banner ── -->
      <div v-if="!isOnline" class="nq-offline-bar" role="status">
        <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5"
          stroke-linecap="round" aria-hidden="true">
          <path d="M13 1L1 13M10.5 3.5A5 5 0 0 1 11.5 7M8 2.5A7.5 7.5 0 0 1 13 7M3.5 7A5 5 0 0 1 6 4.5M7 12a1 1 0 1 0 0-2 1 1 0 0 0 0 2z"/>
        </svg>
        Offline — {{ fmt(allItems.length) }} cached records · Syncs on reconnection
      </div>

      <!-- Stale data warning disabled — sync runs every 15s automatically -->
      <!-- <div v-if="isOnline && staleWarning" class="nq-stale-bar" role="status">
        Data may be stale — last synced {{ lastSyncLabel }}
        <button class="nq-stale-btn" @click="manualRefresh" type="button">Refresh now</button>
      </div> -->

      <!-- ── SEARCH + FILTER BAR ── -->
      <div class="nq-search-bar">
        <div class="nq-search-wrap" :class="searchFocused && 'nq-search-wrap--focus'">
          <svg class="nq-search-ic" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
            <circle cx="6.5" cy="6.5" r="4.5"/><line x1="10" y1="10" x2="14" y2="14"/>
          </svg>
          <input
            v-model="searchQuery"
            class="nq-search-input"
            type="text"
            placeholder="Search by name, gender, priority, POE…"
            autocomplete="off"
            aria-label="Search referrals"
            @focus="searchFocused = true"
            @blur="searchFocused = false"
            @input="onSearchInput"
          />
          <button v-if="searchQuery" class="nq-search-clear" @click="searchQuery = ''; appliedQuery = ''; onSearchInput()" type="button" aria-label="Clear search">
            <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="2" y1="2" x2="10" y2="10"/><line x1="10" y1="2" x2="2" y2="10"/></svg>
          </button>
        </div>
        <div class="nq-search-meta" v-if="searchQuery.trim()">
          <span class="nq-search-count">{{ filteredItems.length }} result{{ filteredItems.length !== 1 ? 's' : '' }}</span>
          <span v-if="allItems.length > filteredItems.length" class="nq-search-of">of {{ allItems.length }}</span>
        </div>
      </div>

      <div class="nq-board">

        <!-- ── SKELETON — first load ── -->
        <div v-if="loading && !allItems.length" class="nq-skels">
          <div v-for="i in 5" :key="i" class="nq-sk" :style="{ animationDelay: i * 70 + 'ms' }">
            <div class="nq-sk-bar"/>
            <div class="nq-sk-body">
              <div class="nq-sk-r1">
                <div class="nq-sk-pill"/>
                <div class="nq-sk-pill" style="width:60px"/>
              </div>
              <div class="nq-sk-r2"/>
              <div class="nq-sk-r3">
                <div class="nq-sk-chip"/>
                <div class="nq-sk-chip" style="width:55px"/>
              </div>
            </div>
          </div>
        </div>

        <template v-else>

          <!-- ════════════════════════════════════════════════════════
               DAMAGED TAB — Quarantine Zone
          ════════════════════════════════════════════════════════ -->
          <template v-if="activeTab === 'DAMAGED'">
            <div class="nq-quarantine-header">
              <svg viewBox="0 0 14 14" fill="none" stroke="#B45309" stroke-width="1.6"
                stroke-linecap="round" aria-hidden="true">
                <path d="M7 1L1 13h12L7 1z"/>
                <line x1="7" y1="5.5" x2="7" y2="9"/><circle cx="7" cy="11" r=".6" fill="#B45309"/>
              </svg>
              {{ damaged.length }} Damaged Record{{ damaged.length !== 1 ? 's' : '' }}
              — Server Rejected · Requires Investigation
            </div>

            <div v-if="!damaged.length" class="nq-empty">
              <svg viewBox="0 0 40 40" fill="none" stroke="#94A3B8" stroke-width="1.2"
                stroke-linecap="round" aria-hidden="true">
                <circle cx="20" cy="20" r="16"/>
                <polyline points="12 20 17 25 28 14"/>
              </svg>
              <p class="nq-empty-title">No Damaged Records</p>
              <p class="nq-empty-sub">All referrals are healthy.</p>
            </div>

            <div v-for="d in damaged" :key="d.client_uuid" class="nq-dmg-card">
              <div class="nq-dmg-bar"/>
              <div class="nq-dmg-body">
                <div class="nq-dmg-row1">
                  <span class="nq-pri" :class="'nq-pri--' + (d.priority||'NORMAL').toLowerCase()">
                    {{ d.priority || 'NORMAL' }}
                  </span>
                  <span class="nq-dmg-badge">SYNC FAILURE</span>
                  <span class="nq-dmg-att">{{ d.sync_attempt_count }} attempts</span>
                  <span class="nq-dmg-age">{{ fmtRelative(d.created_at) }}</span>
                </div>
                <div class="nq-dmg-reason">
                  <strong>Error:</strong> {{ d._damageReason || d.last_sync_error || 'Unknown' }}
                </div>
                <code class="nq-dmg-uuid">{{ d.client_uuid }}</code>
                <div class="nq-dmg-meta">{{ d.poe_code }} · Status: {{ d.status }}</div>
                <div class="nq-dmg-actions">
                  <button class="nq-dmg-btn nq-dmg-btn--retry"
                    :disabled="retryingUuid === d.client_uuid"
                    @click="retryDamaged(d)" type="button">
                    <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8"
                      stroke-linecap="round" :class="retryingUuid === d.client_uuid && 'nq-spin'"
                      aria-hidden="true">
                      <path d="M10 6A4 4 0 1 1 6 2"/><polyline points="10 2 10 6 6 6"/>
                    </svg>
                    {{ retryingUuid === d.client_uuid ? 'Queuing…' : 'Retry Sync' }}
                  </button>
                  <button class="nq-dmg-btn nq-dmg-btn--open"
                    @click="openCaseFromDamaged(d)" type="button">
                    Open Case →
                  </button>
                  <button class="nq-dmg-btn nq-dmg-btn--dismiss"
                    @click="dismissDamaged(d)" type="button">
                    Dismiss
                  </button>
                </div>
              </div>
            </div>
          </template>

          <!-- ════════════════════════════════════════════════════════
               MAIN QUEUE — ALL / OPEN / IN_PROGRESS / CLOSED
          ════════════════════════════════════════════════════════ -->
          <template v-else>

            <!-- CRITICAL ALARM ZONE — always top, always visible when crits open -->
            <div v-if="criticalItems.length > 0 && showAlarmZone"
              class="nq-alarm-zone"
              role="alert"
              aria-label="Critical referrals requiring immediate response">
              <div class="nq-alarm-hdr">
                <div class="nq-alarm-pulse" aria-hidden="true"/>
                <span class="nq-alarm-title">
                  {{ criticalItems.length }} CRITICAL Referral{{ criticalItems.length > 1 ? 's' : '' }} — Immediate Response Required
                </span>
                <span class="nq-alarm-oldest" v-if="criticalItems[0]">
                  Oldest: {{ fmtRelative(criticalItems[0].notification_created_at) }}
                </span>
              </div>
              <div v-for="item in criticalItems" :key="item.notification_uuid"
                class="nq-card nq-card--critical"
                tabindex="0" role="button"
                :aria-label="'Critical: ' + (item.traveler_full_name || item.gender)"
                @click="openDetail(item)"
                @keydown.enter="openDetail(item)">
                <div class="nq-card-bar nq-bar--critical" aria-hidden="true"/>
                <div class="nq-card-body">
                  <div class="nq-card-row1">
                    <span class="nq-pri nq-pri--critical">CRITICAL</span>
                    <span class="nq-sts nq-sts--open">OPEN</span>
                    <span class="nq-card-age nq-age--critical">
                      {{ fmtRelative(item.notification_created_at) }}
                    </span>
                  </div>
                  <div class="nq-card-name">
                    {{ item.traveler_full_name || (item.gender ? item.gender + ' · Anonymous traveler' : 'Anonymous traveler') }}
                  </div>
                  <div class="nq-card-chips">
                    <span v-if="item.gender" class="nq-chip">{{ item.gender }}</span>
                    <span v-if="item.traveler_direction" class="nq-chip">{{ item.traveler_direction }}</span>
                    <span v-if="item.temperature_value != null"
                      class="nq-chip nq-chip--temp"
                      :class="tempCls(item.temperature_value, item.temperature_unit)">
                      {{ fmtTemp(item.temperature_value) }}°{{ item.temperature_unit || 'C' }}
                    </span>
                    <span v-if="item.screener_name" class="nq-chip nq-chip--officer">
                      {{ item.screener_name }}
                    </span>
                    <span v-if="item.is_voided_primary" class="nq-chip nq-chip--voided-warn">
                      ⚠ Primary Voided
                    </span>
                  </div>
                </div>
                <button class="nq-open-btn nq-open-btn--critical"
                  @click.stop="openCase(item)"
                  :disabled="openingUuid === item.notification_uuid"
                  type="button"
                  aria-label="Begin secondary screening now">
                  <span>{{ openingUuid === item.notification_uuid ? 'Opening…' : 'Screen Now' }}</span>
                  <svg viewBox="0 0 10 10" fill="none" stroke="currentColor"
                    stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                    <polyline points="3 1 7 5 3 9"/>
                  </svg>
                </button>
              </div>
            </div>

            <!-- HIGH PRIORITY section -->
            <template v-if="highItems.length > 0 && showOpenSection">
              <div class="nq-section-hdr">
                <div class="nq-section-bar nq-section-bar--high" aria-hidden="true"/>
                <span>HIGH PRIORITY</span>
                <span class="nq-section-count">{{ highItems.length }}</span>
              </div>
              <div v-for="item in highItems" :key="item.notification_uuid"
                class="nq-card nq-card--high"
                tabindex="0" role="button"
                :aria-label="'High priority referral for ' + (item.traveler_full_name || item.gender)"
                @click="openDetail(item)"
                @keydown.enter="openDetail(item)">
                <div class="nq-card-bar nq-bar--high" aria-hidden="true"/>
                <div class="nq-card-body">
                  <div class="nq-card-row1">
                    <span class="nq-pri nq-pri--high">HIGH</span>
                    <span class="nq-sts nq-sts--open">OPEN</span>
                    <span class="nq-card-age">{{ fmtRelative(item.notification_created_at) }}</span>
                  </div>
                  <div class="nq-card-name">{{ item.traveler_full_name || (item.gender ? item.gender + ' · Anonymous traveler' : 'Anonymous traveler') }}</div>
                  <div class="nq-card-chips">
                    <span v-if="item.gender" class="nq-chip">{{ item.gender }}</span>
                    <span v-if="item.traveler_direction" class="nq-chip">{{ item.traveler_direction }}</span>
                    <span v-if="item.temperature_value != null"
                      class="nq-chip nq-chip--temp"
                      :class="tempCls(item.temperature_value, item.temperature_unit)">
                      {{ fmtTemp(item.temperature_value) }}°{{ item.temperature_unit || 'C' }}
                    </span>
                    <span v-if="item.screener_name" class="nq-chip nq-chip--officer">
                      {{ item.screener_name }}
                    </span>
                    <span v-if="item.is_voided_primary" class="nq-chip nq-chip--voided-warn">⚠ Primary Voided</span>
                  </div>
                </div>
                <div class="nq-card-cta" @click.stop>
                  <button class="nq-cancel-btn" @click="confirmCancel(item)"
                    :disabled="cancellingId === item.notification_id"
                    type="button" aria-label="Cancel referral">
                    Cancel
                  </button>
                  <button class="nq-open-btn nq-open-btn--high"
                    @click="openCase(item)"
                    :disabled="openingUuid === item.notification_uuid"
                    type="button" aria-label="Begin secondary screening">
                    {{ openingUuid === item.notification_uuid ? 'Opening…' : 'Screen →' }}
                  </button>
                </div>
              </div>
            </template>

            <!-- NORMAL PRIORITY section -->
            <template v-if="normalItems.length > 0 && showOpenSection">
              <div class="nq-section-hdr" :class="highItems.length > 0 && 'nq-section-hdr--sep'">
                <div class="nq-section-bar nq-section-bar--normal" aria-hidden="true"/>
                <span>NORMAL PRIORITY</span>
                <span class="nq-section-count">{{ normalItems.length }}</span>
              </div>
              <div v-for="item in normalItems" :key="item.notification_uuid"
                class="nq-card nq-card--normal"
                tabindex="0" role="button"
                :aria-label="'Normal priority referral for ' + (item.traveler_full_name || item.gender)"
                @click="openDetail(item)"
                @keydown.enter="openDetail(item)">
                <div class="nq-card-bar nq-bar--normal" aria-hidden="true"/>
                <div class="nq-card-body">
                  <div class="nq-card-row1">
                    <span class="nq-pri nq-pri--normal">NORMAL</span>
                    <span class="nq-sts nq-sts--open">OPEN</span>
                    <span class="nq-card-age">{{ fmtRelative(item.notification_created_at) }}</span>
                  </div>
                  <div class="nq-card-name">{{ item.traveler_full_name || (item.gender ? item.gender + ' · Anonymous traveler' : 'Anonymous traveler') }}</div>
                  <div class="nq-card-chips">
                    <span v-if="item.gender" class="nq-chip">{{ item.gender }}</span>
                    <span v-if="item.traveler_direction" class="nq-chip">{{ item.traveler_direction }}</span>
                    <span v-if="item.temperature_value != null"
                      class="nq-chip nq-chip--temp"
                      :class="tempCls(item.temperature_value, item.temperature_unit)">
                      {{ fmtTemp(item.temperature_value) }}°{{ item.temperature_unit || 'C' }}
                    </span>
                    <span v-if="item.screener_name" class="nq-chip nq-chip--officer">
                      {{ item.screener_name }}
                    </span>
                    <span v-if="item.is_voided_primary" class="nq-chip nq-chip--voided-warn">⚠ Primary Voided</span>
                  </div>
                </div>
                <div class="nq-card-cta" @click.stop>
                  <button class="nq-cancel-btn" @click="confirmCancel(item)"
                    :disabled="cancellingId === item.notification_id"
                    type="button" aria-label="Cancel referral">
                    Cancel
                  </button>
                  <button class="nq-open-btn nq-open-btn--normal"
                    @click="openCase(item)"
                    :disabled="openingUuid === item.notification_uuid"
                    type="button" aria-label="Begin secondary screening">
                    {{ openingUuid === item.notification_uuid ? 'Opening…' : 'Screen →' }}
                  </button>
                </div>
              </div>
            </template>

            <!-- IN PROGRESS section -->
            <template v-if="inProgItems.length > 0 && showInProgSection">
              <div class="nq-section-hdr">
                <div class="nq-section-bar nq-section-bar--progress" aria-hidden="true"/>
                <span>IN PROGRESS</span>
                <span class="nq-section-count">{{ inProgItems.length }}</span>
              </div>
              <div v-for="item in inProgItems" :key="item.notification_uuid"
                class="nq-card nq-card--progress"
                tabindex="0" role="button"
                :aria-label="'In progress: ' + (item.traveler_full_name || item.gender)"
                @click="openDetail(item)"
                @keydown.enter="openDetail(item)">
                <div class="nq-card-bar nq-bar--progress" aria-hidden="true"/>
                <div class="nq-card-body">
                  <div class="nq-card-row1">
                    <span class="nq-pri" :class="'nq-pri--' + (item.priority||'NORMAL').toLowerCase()">
                      {{ item.priority || 'NORMAL' }}
                    </span>
                    <span class="nq-sts nq-sts--progress">IN PROGRESS</span>
                    <span class="nq-card-age">{{ fmtRelative(item.notification_created_at) }}</span>
                  </div>
                  <div class="nq-card-name">{{ item.traveler_full_name || (item.gender ? item.gender + ' · Anonymous traveler' : 'Anonymous traveler') }}</div>
                  <div class="nq-card-chips">
                    <span v-if="item.gender" class="nq-chip">{{ item.gender }}</span>
                    <span v-if="item.traveler_direction" class="nq-chip">{{ item.traveler_direction }}</span>
                    <span v-if="item.temperature_value != null"
                      class="nq-chip nq-chip--temp"
                      :class="tempCls(item.temperature_value, item.temperature_unit)">
                      {{ fmtTemp(item.temperature_value) }}°{{ item.temperature_unit || 'C' }}
                    </span>
                    <span v-if="item.screener_name" class="nq-chip nq-chip--officer">
                      👤 {{ item.screener_name }}
                    </span>
                  </div>
                </div>
                <button class="nq-open-btn nq-open-btn--progress"
                  @click.stop="openCase(item)"
                  :disabled="openingUuid === item.notification_uuid"
                  type="button" aria-label="Continue secondary screening">
                  {{ openingUuid === item.notification_uuid ? 'Opening…' : 'Continue →' }}
                </button>
              </div>
            </template>

            <!-- CLOSED section — collapsed by default -->
            <template v-if="closedItems.length > 0 && showClosedSection">
              <button class="nq-closed-toggle" @click="showClosed = !showClosed" type="button">
                <div class="nq-section-bar nq-section-bar--closed" aria-hidden="true"/>
                <span>CLOSED</span>
                <span class="nq-section-count">{{ closedItems.length }}</span>
                <svg class="nq-toggle-chev" :class="showClosed && 'nq-toggle-chev--open'"
                  viewBox="0 0 10 10" fill="none" stroke="currentColor"
                  stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                  <polyline points="2 4 5 7 8 4"/>
                </svg>
              </button>
              <transition name="nq-collapse">
                <div v-if="showClosed" class="nq-closed-list">
                  <div v-for="item in closedItems" :key="item.notification_uuid"
                    class="nq-card nq-card--closed"
                    tabindex="0" role="button"
                    :aria-label="'Closed: ' + (item.traveler_full_name || item.gender)"
                    @click="openDetail(item)"
                    @keydown.enter="openDetail(item)">
                    <div class="nq-card-bar nq-bar--closed" aria-hidden="true"/>
                    <div class="nq-card-body">
                      <div class="nq-card-row1">
                        <span class="nq-pri nq-pri--closed">
                          {{ item.priority || 'NORMAL' }}
                        </span>
                        <span class="nq-sts nq-sts--closed">CLOSED</span>
                        <span class="nq-card-age">{{ fmtRelative(item.notification_created_at) }}</span>
                      </div>
                      <div class="nq-card-name nq-card-name--muted">
                        {{ item.traveler_full_name || (item.gender ? item.gender + ' · Anonymous traveler' : 'Anonymous traveler') }}
                      </div>
                      <div class="nq-card-chips">
                        <span v-if="item.gender" class="nq-chip nq-chip--muted">{{ item.gender }}</span>
                        <span v-if="item.temperature_value != null" class="nq-chip nq-chip--muted">
                          {{ fmtTemp(item.temperature_value) }}°{{ item.temperature_unit || 'C' }}
                        </span>
                      </div>
                    </div>
                    <div class="nq-closed-arrow" aria-hidden="true">›</div>
                  </div>
                </div>
              </transition>
            </template>

            <!-- Empty state -->
            <div v-if="displayItems.length === 0 && !loading" class="nq-empty">
              <svg viewBox="0 0 40 40" fill="none" stroke="#94A3B8" stroke-width="1.2"
                stroke-linecap="round" aria-hidden="true">
                <rect x="6" y="8" width="28" height="24" rx="2.5"/>
                <line x1="12" y1="16" x2="28" y2="16"/>
                <line x1="12" y1="21" x2="24" y2="21"/>
                <polyline points="12 26 16 29 22 23"/>
              </svg>
              <p class="nq-empty-title">{{ emptyTitle }}</p>
              <p class="nq-empty-sub">{{ emptySub }}</p>
              <button v-if="activeTab !== 'ALL'" class="nq-empty-btn"
                @click="setTab('ALL')" type="button">Show All Records</button>
            </div>

            <!-- Load more -->
            <div v-if="hasMore && !loading" class="nq-load-more">
              <button class="nq-load-btn" :disabled="loadingMore" @click="loadMore" type="button">
                <svg v-if="loadingMore" viewBox="0 0 14 14" fill="none" stroke="currentColor"
                  stroke-width="1.8" stroke-linecap="round" class="nq-spin" aria-hidden="true">
                  <path d="M12 7A5 5 0 1 1 7 2"/><polyline points="12 2 12 7 7 7"/>
                </svg>
                {{ loadingMore ? 'Loading…' : `Load More · ${fmt(remainingCount)} remaining` }}
              </button>
            </div>

          </template><!-- /main queue -->

        </template><!-- /loaded -->

        <div style="height:max(env(safe-area-inset-bottom,0px),40px)" aria-hidden="true"/>
      </div><!-- /nq-board -->
    </IonContent>

    <!-- ═══════════════════════════════════════════════════════════════
         DETAIL MODAL — full referral record
    ═══════════════════════════════════════════════════════════════════ -->
    <IonModal :is-open="!!detailItem" :breakpoints="[0,1]" :initial-breakpoint="1"
      @ionModalDidDismiss="detailItem = null">
      <IonHeader :translucent="false" v-if="detailItem">
        <IonToolbar class="nq-modal-toolbar">
          <IonButtons slot="start">
            <IonButton @click="detailItem = null"
              style="--color:rgba(255,255,255,.8);" aria-label="Close">
              <IonIcon :icon="closeOutline"/>
            </IonButton>
          </IonButtons>
          <IonTitle class="nq-modal-title">Referral Detail</IonTitle>
          <div slot="end" class="nq-modal-pri-wrap">
            <span class="nq-pri nq-pri--sm"
              :class="'nq-pri--' + (detailItem.priority||'NORMAL').toLowerCase()">
              {{ detailItem.priority || 'NORMAL' }}
            </span>
          </div>
        </IonToolbar>
      </IonHeader>
      <IonContent :scroll-y="true" v-if="detailItem" class="nq-modal-content">
        <div class="nq-det-wrap">

          <!-- Status banner -->
          <div class="nq-det-banner"
            :class="'nq-det-banner--' + (detailItem.notification_status||'OPEN').toLowerCase().replace('_','-')">
            <div class="nq-det-banner-shine" aria-hidden="true"/>
            <span class="nq-det-banner-sts">{{ detailItem.notification_status }}</span>
            <span class="nq-det-banner-hint">
              {{ detailItem.notification_status === 'OPEN'
                ? 'Awaiting secondary screening'
                : detailItem.notification_status === 'IN_PROGRESS'
                ? 'Secondary screening in progress'
                : 'Case closed' }}
            </span>
          </div>

          <!-- Traveler -->
          <div class="nq-det-section">
            <div class="nq-det-section-lbl">TRAVELER</div>
            <div class="nq-det-grid">
              <div class="nq-drow"><span class="nq-dk">Full Name</span>
                <span class="nq-dv">{{ detailItem.traveler_full_name || '—' }}</span></div>
              <div class="nq-drow"><span class="nq-dk">Sex</span>
                <span class="nq-dv">{{ detailItem.gender || '—' }}</span></div>
              <div class="nq-drow"><span class="nq-dk">Direction</span>
                <span class="nq-dv">{{ detailItem.traveler_direction || '—' }}</span></div>
              <div class="nq-drow"><span class="nq-dk">Temperature</span>
                <span class="nq-dv"
                  :class="detailItem.temperature_value != null && tempTextCls(detailItem.temperature_value, detailItem.temperature_unit)">
                  {{ detailItem.temperature_value != null
                    ? fmtTemp(detailItem.temperature_value) + '°' + (detailItem.temperature_unit||'C')
                    : 'Not recorded' }}
                </span>
              </div>
              <div class="nq-drow"><span class="nq-dk">Captured</span>
                <span class="nq-dv">{{ fmtDateTime(detailItem.captured_at || detailItem.notification_created_at) }}</span></div>
              <div v-if="detailItem.screener_name" class="nq-drow">
                <span class="nq-dk">Screener</span>
                <span class="nq-dv">{{ detailItem.screener_name }}</span>
              </div>
            </div>
          </div>

          <!-- Referral -->
          <div class="nq-det-section">
            <div class="nq-det-section-lbl">REFERRAL</div>
            <div class="nq-det-grid">
              <div class="nq-drow"><span class="nq-dk">Priority</span>
                <span class="nq-dv" :class="'nq-dv--' + (detailItem.priority||'NORMAL').toLowerCase()">
                  {{ detailItem.priority || 'NORMAL' }}
                </span>
              </div>
              <div class="nq-drow"><span class="nq-dk">Status</span>
                <span class="nq-dv">{{ detailItem.notification_status }}</span></div>
              <div class="nq-drow"><span class="nq-dk">Reason</span>
                <span class="nq-dv">{{ detailItem.reason_code || '—' }}</span></div>
              <div class="nq-drow"><span class="nq-dk">POE</span>
                <span class="nq-dv">{{ detailItem.poe_code || '—' }}</span></div>
              <div class="nq-drow"><span class="nq-dk">Created</span>
                <span class="nq-dv">{{ fmtDateTime(detailItem.notification_created_at) }}</span></div>
              <div v-if="detailItem.opened_at" class="nq-drow">
                <span class="nq-dk">Opened</span>
                <span class="nq-dv">{{ fmtDateTime(detailItem.opened_at) }}</span>
              </div>
              <div v-if="detailItem.closed_at" class="nq-drow">
                <span class="nq-dk">Closed</span>
                <span class="nq-dv">{{ fmtDateTime(detailItem.closed_at) }}</span>
              </div>
            </div>
          </div>

          <!-- Reason text -->
          <div v-if="detailItem.reason_text" class="nq-det-section">
            <div class="nq-det-section-lbl">REASON TEXT</div>
            <p class="nq-det-reason">{{ detailItem.reason_text }}</p>
          </div>

          <!-- Voided primary warning -->
          <div v-if="detailItem.is_voided_primary" class="nq-det-void-warn">
            <svg viewBox="0 0 14 14" fill="none" stroke="#B45309" stroke-width="1.5"
              stroke-linecap="round" aria-hidden="true">
              <path d="M7 1L1 13h12L7 1z"/>
              <line x1="7" y1="6" x2="7" y2="9"/><circle cx="7" cy="11" r=".5" fill="#B45309"/>
            </svg>
            The linked primary screening record has been voided.
            This referral should be reviewed and closed if appropriate.
          </div>

          <!-- Sync / IDs -->
          <div class="nq-det-section">
            <div class="nq-det-section-lbl">IDENTIFIERS</div>
            <div class="nq-det-grid">
              <div class="nq-drow"><span class="nq-dk">Sync</span>
                <span class="nq-dv">{{ SYNC.LABELS[detailItem.sync_status] || detailItem.sync_status }}</span></div>
              <div class="nq-drow"><span class="nq-dk">Server ID</span>
                <code class="nq-dv nq-dv--mono">{{ detailItem.notification_id || 'Pending sync' }}</code></div>
              <div class="nq-drow"><span class="nq-dk">Client UUID</span>
                <code class="nq-dv nq-dv--mono nq-dv--xs">{{ detailItem.notification_uuid }}</code></div>
            </div>
          </div>

          <!-- Actions -->
          <div class="nq-det-actions">
            <!-- OPEN or IN_PROGRESS → show screening button -->
            <button
              v-if="detailItem.notification_status === 'OPEN' || detailItem.notification_status === 'IN_PROGRESS'"
              class="nq-det-screen-btn"
              :class="detailItem.notification_status === 'IN_PROGRESS' && 'nq-det-screen-btn--progress'"
              @click="openCase(detailItem); detailItem = null"
              type="button">
              {{ detailItem.notification_status === 'IN_PROGRESS'
                ? 'Continue Secondary Screening →'
                : 'Begin Secondary Screening →' }}
            </button>
            <!-- OPEN only → cancel button -->
            <!-- CRITICAL GUARD: IN_PROGRESS cannot be cancelled here -->
            <button
              v-if="detailItem.notification_status === 'OPEN'"
              class="nq-det-cancel-btn"
              @click="confirmCancel(detailItem); detailItem = null"
              type="button">
              Cancel Referral
            </button>
            <!-- IN_PROGRESS cancel guard notice -->
            <div v-if="detailItem.notification_status === 'IN_PROGRESS'" class="nq-det-notice">
              <svg viewBox="0 0 12 12" fill="none" stroke="#64748B" stroke-width="1.4"
                stroke-linecap="round" aria-hidden="true">
                <circle cx="6" cy="6" r="5"/><line x1="6" y1="4" x2="6" y2="7"/>
                <circle cx="6" cy="9" r=".5" fill="#64748B"/>
              </svg>
              This case is in progress. The secondary officer must close it from within the screening form.
            </div>
          </div>

        </div>
      </IonContent>
    </IonModal>

    <!-- Cancel confirmation -->
    <IonAlert
      :is-open="showCancelAlert"
      header="Cancel This Referral?"
      :sub-header="cancelTarget
        ? (cancelTarget.traveler_full_name || cancelTarget.gender || 'Traveler') + ' · ' + (cancelTarget.priority || 'NORMAL') + ' priority'
        : ''"
      message="This permanently closes the referral notification. The linked primary screening record stays COMPLETED and is preserved in the audit log."
      :buttons="cancelAlertBtns"
      @didDismiss="showCancelAlert = false; cancelTarget = null"/>

    <IonToast :is-open="toast.show" :message="toast.msg" :color="toast.color"
      :duration="3200" position="top" @didDismiss="toast.show = false"/>

  </IonPage>
</template>

<script setup>
/**
 * NotificationsCenter.vue — ECSA-HC POE Sentinel
 * IHR 2005 Article 23 · Referral Command Centre
 *
 * ══ OFFLINE-FIRST ARCHITECTURE ══════════════════════════════════════
 * IDB is the single source of truth. Server is a sync source, never primary.
 *
 * IDB-FIRST LOAD ORDER:
 *   1. Read IDB page (offset 0, 100 records) → render immediately
 *   2. If online: fetch server page 1 → write-through to IDB → merge window
 *   3. Background sync runs every 30s with updated_after cursor
 *   4. On view re-enter: incremental sync + damaged re-scan
 *
 * WRITE-THROUGH CACHE (server → IDB):
 *   Every server item is written to IDB if:
 *     a) record does not exist in IDB, OR
 *     b) incoming record_version > stored record_version
 *   This prevents stale overwrites during concurrent device edits.
 *
 * MEMORY WINDOW:
 *   MAX_WINDOW = 1000 records in JS heap. allItems never exceeds this.
 *   Stats (Open/InProg/Closed/Critical) come from IDB dbCountIndex — O(1).
 *
 * STATUS MACHINE (enforced on both client and server):
 *   OPEN → IN_PROGRESS → CLOSED
 *   Cancel:  OPEN only  (PATCH /referral-queue/{id}/cancel)
 *   IN_PROGRESS cannot be cancelled here — secondary officer must close.
 *
 * NAVIGATION TO SECONDARY SCREENING:
 *   router.push('/secondary-screening/' + notification_uuid)
 *   SecondaryScreening.vue reads params.notificationId → IDB lookup by client_uuid.
 *   Before navigating: guarantee IDB has the full record (openCase writes it if missing).
 *
 * DAMAGED QUARANTINE:
 *   sync_status === FAILED && sync_attempt_count >= DAMAGE_THRESHOLD (3)
 *   Loaded from IDB via sync_status index scan (separate from main window).
 *   Officer can: Retry (reset to UNSYNCED, attempt_count=0) or Dismiss (soft-delete).
 *
 * STALE DETECTION:
 *   localStorage key 'nq_last_sync' stores last successful sync ISO timestamp.
 *   If > 5 minutes old and online → show staleness warning banner.
 *
 * API endpoints:
 *   GET  /referral-queue?user_id=&status=ALL&page=1&per_page=100&updated_after=
 *   PATCH /referral-queue/{notifId}/cancel  (server integer id required)
 */
import { ref, computed, reactive, onMounted, onUnmounted, toRaw, nextTick } from 'vue'
import { useRouter } from 'vue-router'
import {
  IonPage, IonHeader, IonToolbar, IonTitle, IonButtons, IonMenuButton,
  IonButton, IonIcon, IonContent, IonModal, IonAlert, IonToast,
  IonRefresher, IonRefresherContent,
  onIonViewDidEnter, onIonViewWillLeave,
} from '@ionic/vue'
import { closeOutline } from 'ionicons/icons'
import {
  dbGet, dbGetAll, dbGetByIndex, dbPut, safeDbPut, dbCountIndex,
  isoNow, STORE, SYNC, APP,
} from '@/services/poeDB'
// 2026-05-06 — Cross-device sync robustness:
// serverTime gives us a clock that's correct even when the device clock is wrong.
// tenantReconciler hard-purges cross-tenant residue once we've verified the live
// tenant universe.
import {
  noteServerTime, serverIsoNow, parseServerTimestamp,
} from '@/services/serverTime'
import { getTenantSnapshot } from '@/services/tenantSnapshot'
import { reconcileTenant } from '@/services/tenantReconciler'

const router = useRouter()

// ── AUTH ──────────────────────────────────────────────────────────────────
function getAuth() {
  try { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {} }
  catch { return {} }
}
const auth = ref(getAuth())

// ── CONSTANTS ─────────────────────────────────────────────────────────────
const TABS = [
  { v: 'ALL',         label: 'All'         },
  { v: 'OPEN',        label: 'Open'        },
  { v: 'IN_PROGRESS', label: 'In Progress' },
  { v: 'CLOSED',      label: 'Closed'      },
  { v: 'DAMAGED',     label: '⚠ Damaged'  },
]

const PRIORITY_ORDER   = { CRITICAL: 0, HIGH: 1, NORMAL: 2 }
const DAMAGE_THRESHOLD = 3       // failed sync attempts before quarantine
const MAX_WINDOW       = 1000    // max records in JS heap (was 300; raised so closed history fits)
const IDB_PAGE_SIZE    = 200     // IDB reads per page
const SERVER_PAGE_SIZE = 200     // server page size
const POLL_INTERVAL_MS = 8_000   // background sync interval — 8s for aggressive freshness
const STALE_THRESHOLD_MS = 5 * 60_000 // 5 minutes
const LAST_SYNC_KEY    = 'rw_nq_last_sync'
const FULL_FETCH_PAGES = 5       // pull up to 5 pages = 1000 records on first load

// ── Cross-device sync robustness (2026-05-06) ────────────────────────────
// CURSOR_OVERLAP_MS: when sending updated_after, subtract this much from
// the stored cursor. Defends against:
//   1. Residual client/server clock skew (post serverTime correction).
//   2. Out-of-order MySQL writes during high concurrency.
//   3. Replication lag on read replicas (future-proof).
// Re-fetched records are deduped by writeToIdb's absorbing-state ratchet,
// so this is a free correctness win.
const CURSOR_OVERLAP_MS = 5 * 60 * 1000   // 5 minutes

// UI freshness indicator — separate from the sync cursor. This is what
// "Last synced 12s ago" reads from. Wall-clock is fine here because it's
// shown to the user, not used as a SQL filter.
const UI_LAST_SYNC_KEY = 'rw_nq_ui_last_sync'

// ── STATE ─────────────────────────────────────────────────────────────────
const allItems    = ref([])   // memory window — max MAX_WINDOW items
const damaged     = ref([])   // quarantined records

// IDB counts — O(1), no full scans
const idbOpen    = ref(0)
const idbInProg  = ref(0)
const idbClosed  = ref(0)

// Pagination state
const idbPageOffset = ref(0)
const serverPage    = ref(1)
const totalOnServer = ref(0)
const hasMoreIdb    = ref(true)
const hasMoreServer = ref(true)

// UI state
const loading      = ref(true)
const loadingMore  = ref(false)
const syncing      = ref(false)
const isOnline     = ref(navigator.onLine)
const activeTab    = ref('ALL')
const showClosed   = ref(true)   // 2026-05-07: closed alerts panel expanded by default per UX mandate
const detailItem   = ref(null)
const searchQuery  = ref('')
const searchFocused = ref(false)
let searchDebounceTimer = null
const openingUuid  = ref(null)  // currently navigating to secondary screening
const cancellingId = ref(null)  // server integer id being cancelled
const showCancelAlert = ref(false)
const cancelTarget = ref(null)
const retryingUuid = ref(null)
const toast = reactive({ show: false, msg: '', color: 'success' })

let pollTimer  = null
let bgDebounce = null

// ── COMPUTED ──────────────────────────────────────────────────────────────
// BUG FIX: Previously used allItems.filter (max 300 window). Now uses a
// dedicated ref populated from the full IDB scan in refreshIdbStats().
const idbCritCount = ref(0)
const critCount = computed(() => idbCritCount.value)

// ── SEARCH ENGINE — O(n) single-pass, debounced 300ms ────────────────────
// Tokenises the query and matches against all searchable fields in one pass.
// Handles millions of in-memory records efficiently: no regex, no repeated
// string construction, no nested loops. Each item is tested once per token.
function matchesSearch(item, tokens) {
  if (tokens.length === 0) return true
  // Build searchable blob ONCE per item — lowercase, space-separated
  const blob = [
    item.traveler_full_name,
    item.gender,
    item.priority,
    item.notification_status,
    item.poe_code,
    item.reason_code,
    item.screener_name,
    item.traveler_direction,
    item.notification_uuid,
  ].filter(Boolean).join(' ').toLowerCase()
  // ALL tokens must match (AND search)
  return tokens.every(t => blob.includes(t))
}

// `searchQuery` updates on every keystroke (v-model). The debounced version
// is `appliedQuery` — that's what the heavy filter computeds read, so the
// expensive recompute fires only after the user stops typing for 300 ms.
const appliedQuery = ref('')
const searchTokens = computed(() => {
  const q = appliedQuery.value.trim().toLowerCase()
  if (!q) return []
  return q.split(/\s+/).filter(t => t.length > 0)
})

function onSearchInput() {
  clearTimeout(searchDebounceTimer)
  searchDebounceTimer = setTimeout(() => {
    appliedQuery.value = searchQuery.value
  }, 300)
}

const displayItems = computed(() => {
  let items = allItems.value
  // Tab filter
  if (activeTab.value === 'OPEN')        items = items.filter(i => i.notification_status === 'OPEN')
  else if (activeTab.value === 'IN_PROGRESS') items = items.filter(i => i.notification_status === 'IN_PROGRESS')
  else if (activeTab.value === 'CLOSED')      items = items.filter(i => i.notification_status === 'CLOSED')
  // Search filter — applied on top of tab
  const tokens = searchTokens.value
  if (tokens.length > 0) items = items.filter(i => matchesSearch(i, tokens))
  return items
})

// Alias for template search result count
const filteredItems = displayItems

// Alarm zone: CRITICAL + OPEN, sorted oldest-first (most urgent visible first)
// Search applies here too — if searching, only show matching criticals
const criticalItems = computed(() =>
  displayItems.value
    .filter(i => i.notification_status === 'OPEN' && i.priority === 'CRITICAL')
    .sort((a, b) => new Date(a.notification_created_at || 0) - new Date(b.notification_created_at || 0))
)

// OPEN items, alarm zone already handles CRITICAL, so HIGH + NORMAL split below
const highItems = computed(() =>
  displayItems.value.filter(i => i.notification_status === 'OPEN' && i.priority === 'HIGH')
)
const normalItems = computed(() =>
  displayItems.value.filter(i => i.notification_status === 'OPEN' && i.priority === 'NORMAL')
)
const inProgItems = computed(() =>
  displayItems.value.filter(i => i.notification_status === 'IN_PROGRESS')
)
const closedItems = computed(() =>
  displayItems.value
    .filter(i => i.notification_status === 'CLOSED')
    .sort((a, b) => new Date(b.notification_created_at || 0) - new Date(a.notification_created_at || 0))
    .slice(0, 100) // cap closed in memory view at 100
)

// Section visibility guards
const showAlarmZone     = computed(() => activeTab.value === 'ALL' || activeTab.value === 'OPEN')
const showOpenSection   = computed(() => activeTab.value === 'ALL' || activeTab.value === 'OPEN')
const showInProgSection = computed(() => activeTab.value === 'ALL' || activeTab.value === 'IN_PROGRESS')
const showClosedSection = computed(() => activeTab.value === 'ALL' || activeTab.value === 'CLOSED')

const hasMore        = computed(() => (hasMoreIdb.value || (hasMoreServer.value && isOnline.value)))
const remainingCount = computed(() => Math.max(0, (totalOnServer.value || 0) - allItems.value.length))

const emptyTitle = computed(() => {
  if (activeTab.value === 'OPEN')        return 'No Open Referrals'
  if (activeTab.value === 'IN_PROGRESS') return 'No Cases In Progress'
  if (activeTab.value === 'CLOSED')      return 'No Closed Referrals'
  return 'No Records Found'
})
const emptySub = computed(() => {
  if (activeTab.value === 'OPEN') return 'All symptomatic travelers have been attended to.'
  return 'Records appear here when referrals are created by primary screening.'
})

// Staleness detection — uses a reactive ref, NOT raw localStorage
// (Vue computed can't react to localStorage changes).
// The "UI sync at" timestamp is independent of the incremental-sync
// cursor. They were conflated before, which caused the cross-device
// staleness bug: the UI badge re-stamped wall-clock on every poll, and
// the same value was re-used as `updated_after` against server time —
// meaning a device clock running ahead of server clock would silently
// filter out records updated by other devices.
const lastSyncAt = ref(localStorage.getItem(UI_LAST_SYNC_KEY) || null)

/**
 * UI freshness indicator. Uses device wall-clock so the staleness check
 * (Date.now() vs lastSyncAt) is comparing values from the same clock.
 * Display formatting in fmtRelative converts to server-clock for the
 * user-visible "X minutes ago" text.
 */
function markUiSynced() {
  const now = isoNow()
  try { localStorage.setItem(UI_LAST_SYNC_KEY, now) } catch {}
  lastSyncAt.value = now
}

/**
 * Advance the incremental-sync cursor to the most recent server-reported
 * timestamp from the items just received. Falls back to the response-level
 * `server_time` field when the items list is empty. NEVER uses the device
 * wall-clock — that's the bug we're fixing.
 *
 * Returning early on empty input AND null server_time is intentional: if
 * we have nothing authoritative from the server, leave the cursor alone
 * so the next poll re-runs the same query (no missed updates possible).
 */
function advanceCursor(items, serverTimeFromResponse) {
  let maxTs = ''
  if (Array.isArray(items)) {
    for (const it of items) {
      const ts = it.notification_updated_at || it.updated_at || it.notification_created_at
      if (ts && ts > maxTs) maxTs = ts
    }
  }
  let nextCursor = null
  if (maxTs) {
    const d = parseServerTimestamp(maxTs)
    if (d) nextCursor = d.toISOString()
  }
  if (!nextCursor && serverTimeFromResponse) {
    const d = parseServerTimestamp(serverTimeFromResponse)
    if (d) nextCursor = d.toISOString()
  }
  if (!nextCursor) return  // leave cursor untouched
  try { localStorage.setItem(LAST_SYNC_KEY, nextCursor) } catch {}
}

/**
 * Backwards-compat: every legacy call site that did `markSynced()` is now
 * just a UI tick. Cursor advancement happens explicitly via advanceCursor
 * at the call sites that have access to the server response.
 */
function markSynced() { markUiSynced() }

const staleWarning = computed(() => {
  if (!lastSyncAt.value) return false
  return (Date.now() - new Date(lastSyncAt.value).getTime()) > STALE_THRESHOLD_MS
})
const lastSyncLabel = computed(() => {
  if (!lastSyncAt.value) return 'never'
  return fmtRelative(lastSyncAt.value)
})

function tabBadge(v) {
  if (v === 'OPEN')        return idbOpen.value    || null
  if (v === 'IN_PROGRESS') return idbInProg.value  || null
  if (v === 'DAMAGED')     return damaged.value.length || null
  return null
}

// ── HELPERS ───────────────────────────────────────────────────────────────
function toPlain(v)           { return JSON.parse(JSON.stringify(toRaw(v))) }
function showMsg(msg, color = 'success') { Object.assign(toast, { show: true, msg, color }) }
function fmt(n) {
  if (!n) return '0'
  n = Number(n)
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(1).replace(/\.0$/, '') + 'M'
  if (n >= 1_000)     return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'K'
  return String(n)
}
function fmtTemp(v) {
  if (v == null) return '—'
  return typeof v === 'number' ? v.toFixed(1) : String(v)
}
function tempC(val, unit) {
  const v = Number(val)
  return unit === 'F' ? (v - 32) * 5 / 9 : v
}
function tempCls(val, unit) {
  const c = tempC(val, unit)
  if (c >= 38.5) return 'nq-chip--temp-crit'
  if (c >= 37.5) return 'nq-chip--temp-high'
  return 'nq-chip--temp-ok'
}
function tempTextCls(val, unit) {
  const c = tempC(val, unit)
  if (c >= 38.5) return 'nq-dv--critical'
  if (c >= 37.5) return 'nq-dv--high'
  return ''
}
// 2026-05-06: timestamps are parsed as UTC (server convention) and
// compared against server-corrected now. Without these two fixes a device
// with a wrong clock shows "in 2 hours" / "3 days ago" everywhere.
function fmtRelative(dt) {
  if (!dt) return '—'
  try {
    const d = parseServerTimestamp(dt)
    if (!d) return '—'
    const serverMs = Date.now() + (Number(localStorage.getItem('rw_server_skew_ms') || 0) || 0)
    const ms = serverMs - d.getTime()
    const m  = Math.floor(ms / 60_000)
    if (m < 1)  return 'Just now'
    if (m < 60) return `${m}m ago`
    const h = Math.floor(m / 60)
    if (h < 24) return `${h}h ago`
    return `${Math.floor(h / 24)}d ago`
  } catch { return '—' }
}
function fmtDateTime(dt) {
  if (!dt) return '—'
  try {
    const d = parseServerTimestamp(dt)
    if (!d) return String(dt)
    // Force Africa/Kampala display (UTC+2). Without timeZone, the device's
    // (potentially wrong) timezone formats it incorrectly.
    return d.toLocaleString('en-RW', {
      day: '2-digit', month: 'short', year: 'numeric',
      hour: '2-digit', minute: '2-digit',
      timeZone: 'Africa/Kampala',
    })
  } catch { return String(dt) }
}
function detectDamageReason(r) {
  const err = String(r.last_sync_error || '')
  if (err.includes('404'))        return 'Primary screening not found on server'
  if (err.includes('422'))        return 'Invalid data — schema or validation mismatch'
  if (err.includes('403'))        return 'Access denied — geographic scope violation'
  if (err.includes('409'))        return 'Conflict — duplicate record on server'
  if (/timeout|abort/i.test(err)) return 'Persistent network timeout — check connectivity'
  if ((r.sync_attempt_count ?? 0) >= 10) return `Exceeded ${r.sync_attempt_count} retry attempts`
  return err || 'Unknown sync failure'
}

// ── IDB STATS ────────────────────────────────────────────────────────────────
// BUG FIX: Previously used dbCountIndex on status which counts ALL POEs.
// Now scoped to the current user's poe_code via full IDB scan.
// Also computes Critical count from full dataset (not 300-item window).
async function refreshIdbStats() {
  try {
    const poeCode     = auth.value?.poe_code || ''
    const role        = auth.value?.role_key || ''
    const isSupervisor = ['NATIONAL_ADMIN','PHEOC_OFFICER','DISTRICT_SUPERVISOR'].includes(role)

    let allNotifs = []
    if (isSupervisor || !poeCode) {
      // Admin/supervisor: full scan (server already scoped the sync)
      allNotifs = await dbGetAll(STORE.NOTIFICATIONS) || []
    } else {
      // POE-level: all equivalent poe_code names to survive naming mismatches
      const seen = new Set()
      for (const code of resolvePoeCodes(poeCode)) {
        const recs = await dbGetByIndex(STORE.NOTIFICATIONS, 'poe_code', code).catch(() => [])
        for (const r of (recs || [])) {
          if (!seen.has(r.client_uuid)) { seen.add(r.client_uuid); allNotifs.push(r) }
        }
      }
    }
    const live = allNotifs.filter(r => !r.deleted_at)
    idbOpen.value      = live.filter(r => r.status === 'OPEN').length
    idbInProg.value    = live.filter(r => r.status === 'IN_PROGRESS').length
    idbClosed.value    = live.filter(r => r.status === 'CLOSED').length
    idbCritCount.value = live.filter(r => r.status === 'OPEN' && r.priority === 'CRITICAL').length
  } catch (e) { console.warn('[NQ] refreshIdbStats', e?.message) }
}

// ── DAMAGED QUARANTINE — scan FAILED index ────────────────────────────────
async function loadDamaged() {
  try {
    const poeCode = auth.value?.poe_code || ''
    const failed = await dbGetByIndex(STORE.NOTIFICATIONS, 'sync_status', SYNC.FAILED)
    damaged.value = failed
      .filter(r => !r.deleted_at && (r.sync_attempt_count ?? 0) >= DAMAGE_THRESHOLD && (!poeCode || r.poe_code === poeCode))
      .map(r => ({
        client_uuid:        r.client_uuid,
        notification_id:    r.id ?? r.server_id ?? null,
        notification_uuid:  r.client_uuid,
        status:             r.status,
        priority:           r.priority || 'NORMAL',
        poe_code:           r.poe_code,
        sync_attempt_count: r.sync_attempt_count ?? 0,
        last_sync_error:    r.last_sync_error || null,
        created_at:         r.created_at,
        _damageReason:      detectDamageReason(r),
      }))
      .sort((a, b) => (b.sync_attempt_count ?? 0) - (a.sync_attempt_count ?? 0))
  } catch (e) { console.warn('[NQ] loadDamaged', e?.message) }
}

// ── Uganda POE validator — backed by POEs.js reference data ───────────────
// Returns true when poeCode is a known Uganda POE (code or name form).
// Used to distinguish Uganda records without country_code from foreign ones.
// Lazily built on first call so POEs.js has time to load before first IDB read
let _rwPoeCodes = null
function _buildRwPoeCodes() {
  if (_rwPoeCodes) return _rwPoeCodes
  const s = new Set()
  try {
    const raw = window.POEs
    const poes = Array.isArray(raw?.[0]) ? raw[0] : (Array.isArray(raw) ? raw : [])
    for (const p of poes) {
      if (p.poe_code) s.add(p.poe_code)
      if (p.poe_name)  s.add(p.poe_name)
    }
  } catch { /* use empty set */ }
  _rwPoeCodes = s
  return s
}

function isUgandaPoeCode(poeCode) {
  if (!poeCode) return false
  // Old short-code format — Uganda codes always start 'UG-'
  if (typeof poeCode === 'string' && poeCode.startsWith('UG-')) return true
  return _buildRwPoeCodes().has(poeCode)
}

// ── POE code resolver — handles different naming conventions ──────────────
// Some users have 'UG-EBB-001', others have 'Entebbe International Airport'
// for the same physical POE.  This function returns ALL equivalent poe_code
// values so the IDB read catches every record regardless of which naming
// convention was used when it was written.
function resolvePoeCodes(userPoeCode) {
  const codes = new Set()
  if (userPoeCode) codes.add(userPoeCode)
  try {
    const poes = Array.isArray(window.POEs?.[0])
      ? window.POEs[0]
      : (Array.isArray(window?.POEs) ? window.POEs : [])
    // Match by either poe_code (short code) or poe_name (human name)
    const match = poes.find(p =>
      (p.poe_code && p.poe_code === userPoeCode) ||
      (p.poe_name && p.poe_name === userPoeCode) ||
      (p.poe_code && p.poe_code.toLowerCase() === userPoeCode.toLowerCase()) ||
      (p.poe_name && p.poe_name.toLowerCase() === userPoeCode.toLowerCase())
    )
    if (match) {
      if (match.poe_code) codes.add(match.poe_code)
      if (match.poe_name) codes.add(match.poe_name)
    }
  } catch { /* use just the original code */ }
  return [...codes].filter(Boolean)
}

// ── IDB PAGE READ — sorted, filtered, paginated, DEDUPLICATED ─────────────
// Handles:
//   • Different poe_code naming conventions (code 'UG-EBB-001' vs name 'Entebbe
//     International Airport') by resolving all equivalent values via POEs.js
//   • Supervisor/admin roles with no poe_code — full scan, filtered by
//     country_code or jurisdiction scope already pushed down from server sync
async function readIdbPage(offset = 0) {
  const poeCode  = auth.value?.poe_code || ''
  const role     = auth.value?.role_key || ''
  const countryCode = auth.value?.country_code || 'UG'
  const isSupervisor = ['NATIONAL_ADMIN','PHEOC_OFFICER','DISTRICT_SUPERVISOR'].includes(role)

  if (!poeCode && !isSupervisor) return []

  try {
    let all = []

    if (isSupervisor || !poeCode) {
      // Admin / supervisor: full IDB scan, then filter to THIS country only.
      // Critical: IDB may contain residual records from other countries (Uganda/Zambia)
      // from a previous app session on the same device. The country_code filter
      // prevents cross-tenant bleed regardless of what old data is in the IDB.
      const allRaw = await dbGetAll(STORE.NOTIFICATIONS) || []
      all = allRaw.filter(n => {
        // Alias-aware match: 'RW' / 'Uganda' / 'RWANDA' all equivalent.
        if (sameCountry(n.country_code, countryCode)) return true

        // Explicit FOREIGN country_code — definitive reject
        if (n.country_code) return false

        // No country_code: accept if poe_code looks Ugandan.
        // Old short codes ('UG-EBB-001') are always Ugandan.
        // Canonical names are validated against POEs.js IF it has loaded;
        // if POEs.js is not yet loaded, ACCEPT the record (safer than
        // incorrectly deleting Uganda data due to a timing issue).
        if (!n.poe_code) return false   // no poe_code at all — reject orphan
        if (typeof n.poe_code === 'string' && n.poe_code.startsWith('UG-')) return true
        const rwSet = _buildRwPoeCodes()
        return rwSet.size === 0        // POEs.js not loaded yet — accept all
          ? true
          : rwSet.has(n.poe_code)
      })
    } else {
      // POE-level: query by ALL equivalent poe_code values to survive naming mismatches
      const poeCodes = resolvePoeCodes(poeCode)
      const seen = new Set()
      for (const code of poeCodes) {
        const records = await dbGetByIndex(STORE.NOTIFICATIONS, 'poe_code', code).catch(() => [])
        for (const r of (records || [])) {
          if (!seen.has(r.client_uuid)) { seen.add(r.client_uuid); all.push(r) }
        }
      }
    }
    const valid = all.filter(n =>
      n.notification_type === 'SECONDARY_REFERRAL' &&
      !n.deleted_at &&
      n.sync_status !== SYNC.FAILED
    )

    // ── DEDUP by primary_screening_id ──────────────────────────────────────
    // If multiple notification records exist for the same primary screening
    // (e.g. client-created + server-synced ghost), keep only the best one:
    // prefer the one with enrichment data (gender/name), then higher record_version.
    const byPrimary = new Map()
    for (const n of valid) {
      const pk = n.primary_screening_id || n.client_uuid
      const existing = byPrimary.get(pk)
      if (!existing) {
        byPrimary.set(pk, n)
      } else {
        // Score: enriched data wins, then higher version, then newer created_at
        const scoreA = (existing.gender ? 1 : 0) + (existing.traveler_full_name ? 1 : 0)
        const scoreB = (n.gender ? 1 : 0) + (n.traveler_full_name ? 1 : 0)
        if (scoreB > scoreA ||
            (scoreB === scoreA && (n.record_version ?? 0) > (existing.record_version ?? 0))) {
          byPrimary.set(pk, n)
        }
      }
    }
    const deduped = Array.from(byPrimary.values())

    deduped.sort((a, b) => {
      const pd = (PRIORITY_ORDER[a.priority] ?? 99) - (PRIORITY_ORDER[b.priority] ?? 99)
      if (pd !== 0) return pd
      return new Date(b.created_at || 0) - new Date(a.created_at || 0)
    })
    return deduped.slice(offset, offset + IDB_PAGE_SIZE).map(normaliseIdb)
  } catch (e) { console.warn('[NQ] readIdbPage', e?.message); return [] }
}

// Normalise an IDB notification record into the UI item shape.
// EVERY field is explicit — no implicit spread that could carry stale data.
function normaliseIdb(n) {
  return {
    notification_id:         n.id ?? n.server_id ?? null,
    notification_uuid:       n.client_uuid,
    notification_status:     n.status || 'OPEN',
    priority:                n.priority || 'NORMAL',
    reason_code:             n.reason_code || null,
    reason_text:             n.reason_text || null,
    notification_created_at: n.created_at || null,
    opened_at:               n.opened_at || null,
    closed_at:               n.closed_at || null,
    screener_name:           n.screener_name || null,
    primary_screening_id:    n.primary_screening_id || null,
    primary_uuid:            n.primary_uuid || null,
    gender:                  n.gender || null,
    traveler_direction:      n.traveler_direction || null,
    temperature_value:       n.temperature_value ?? null,
    temperature_unit:        n.temperature_unit || null,
    traveler_full_name:      n.traveler_full_name || '',   // empty string so template can use || cleanly
    captured_at:             n.captured_at || null,
    poe_code:                n.poe_code || null,
    district_code:           n.district_code || null,
    country_code:            n.country_code || null,
    province_code:           n.province_code || null,
    pheoc_code:              n.pheoc_code || null,
    sync_status:             n.sync_status || SYNC.SYNCED,
    sync_attempt_count:      n.sync_attempt_count ?? 0,
    last_sync_error:         n.last_sync_error || null,
    is_voided_primary:       !!n.is_voided_primary,
    _fromCache:              true,
  }
}

// ── SERVER FETCH — incremental or full ───────────────────────────────────
async function fetchServer(pg = 1, updatedAfter = null) {
  if (!isOnline.value || !auth.value?.id) return null
  const p = new URLSearchParams({
    user_id:  auth.value.id,
    status:   'ALL',
    page:     pg,
    per_page: SERVER_PAGE_SIZE,
  })
  if (updatedAfter) {
    // Apply 5-min overlap so any record updated near the cursor boundary
    // re-appears in the next poll. The absorbing-state ratchet makes
    // duplicate writes harmless; missed updates are not.
    const d = parseServerTimestamp(updatedAfter)
    if (d) {
      const adjusted = new Date(d.getTime() - CURSOR_OVERLAP_MS).toISOString()
      p.set('updated_after', adjusted)
    } else {
      // Cursor unparseable — drop it instead of sending garbage.
    }
  }

  const ctrl = new AbortController()
  const tid  = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS || 8000)
  try {
    const res = await fetch(`${window.SERVER_URL}/referral-queue?${p}`, {
      headers: { Accept: 'application/json' },
      signal:  ctrl.signal,
    })
    clearTimeout(tid)
    if (!res.ok) return null
    const j = await res.json()
    if (!j.success) return null
    // Update the global server-time skew so cursors and display
    // timestamps stay accurate even when the device clock is wrong.
    if (j.data?.server_time) noteServerTime(j.data.server_time)
    return j.data
  } catch { clearTimeout(tid); return null }
}

// ── WRITE-THROUGH CACHE — server items → IDB ──────────────────────────────
// Version-guarded: only write if incoming record_version > stored version.
// NEVER overwrites a newer local edit with older server data.
// DEDUP GUARD: if a local record already exists for the same primary_screening_id
// under a DIFFERENT client_uuid, skip the server record — it's a ghost duplicate.
async function writeToIdb(serverItems) {
  for (const s of serverItems) {
    if (!s.notification_uuid) continue

    // ── DEDUP: check if another IDB record already covers this primary screening ──
    // The server may return a notification with a server-generated UUID that differs
    // from the client-generated UUID. Both point to the same primary_screening_id.
    // Without this guard, we'd create a ghost duplicate with no enrichment data.
    const primaryId = s.primary_uuid || s.primary_screening_id
    if (primaryId) {
      try {
        const existing = await dbGet(STORE.NOTIFICATIONS, s.notification_uuid)
        if (!existing) {
          // Before inserting a NEW record, check if we already have one for this primary
          const siblings = await dbGetByIndex(STORE.NOTIFICATIONS, 'primary_screening_id', primaryId)
          const localSibling = siblings.find(sib =>
            sib.client_uuid !== s.notification_uuid &&
            sib.notification_type === 'SECONDARY_REFERRAL' &&
            !sib.deleted_at
          )
          if (localSibling) {
            // A local record already exists for this primary screening.
            // Update the existing one's server id if needed, skip creating a duplicate.
            if (s.notification_id && !localSibling.id) {
              await safeDbPut(STORE.NOTIFICATIONS, {
                ...localSibling,
                id: s.notification_id,
                server_id: s.notification_id,
                status: s.notification_status || localSibling.status,
                sync_status: SYNC.SYNCED,
                synced_at: serverIsoNow(),
                record_version: (localSibling.record_version || 1) + 1,
                updated_at: serverIsoNow(),
              })
            }
            continue // skip — don't create a ghost duplicate
          }
        }
      } catch (e) {
        console.warn('[NQ] writeToIdb dedup check', s.notification_uuid, e?.message)
      }
    }
    try {
      const existing   = await dbGet(STORE.NOTIFICATIONS, s.notification_uuid)
      const incomingVer = s.record_version ?? 1

      if (!existing) {
        // New record — write full shape
        await dbPut(STORE.NOTIFICATIONS, toPlain({
          client_uuid:            s.notification_uuid,
          id:                     s.notification_id     ?? null,
          server_id:              s.notification_id     ?? null,
          reference_data_version: APP.REFERENCE_DATA_VER,
          server_received_at:     null,
          country_code:           s.country_code        || '',
          province_code:          s.province_code       || null,
          pheoc_code:             s.pheoc_code          || null,
          district_code:          s.district_code       || '',
          poe_code:               s.poe_code            || auth.value?.poe_code || '',
          primary_screening_id:   s.primary_uuid        || String(s.primary_screening_id || ''),
          created_by_user_id:     null,
          notification_type:      'SECONDARY_REFERRAL',
          status:                 s.notification_status || 'OPEN',
          priority:               s.priority            || 'NORMAL',
          reason_code:            s.reason_code         || 'PRIMARY_SYMPTOMS_DETECTED',
          reason_text:            s.reason_text         || null,
          // Honour the server-assigned role; fall back to POE_SECONDARY (the
          // role secondary referrals route to). 'SCREENER' was wrong for
          // every queue item and broke any UI rule keyed off this value.
          assigned_role_key:      s.assigned_role_key    || 'POE_SECONDARY',
          assigned_user_id:       s.assigned_user_id     ?? null,
          // 2026-05-19 — the /referral-queue payload uses prefixed names
          // (notification_opened_at / notification_closed_at). Reading the
          // bare s.opened_at / s.closed_at left both fields undefined,
          // which meant the cross-device CLOSED status carried over but
          // closed_at stayed null. Some downstream guards key off closed_at
          // (e.g. "show this case as closed in the CLOSED tab list").
          opened_at:              s.notification_opened_at ?? s.opened_at ?? null,
          closed_at:              s.notification_closed_at ?? s.closed_at ?? null,
          device_id:              'SERVER',
          app_version:            null,
          platform:               'WEB',
          record_version:         incomingVer,
          deleted_at:             null,
          sync_status:            SYNC.SYNCED,
          synced_at:              serverIsoNow(),
          sync_attempt_count:     0,
          last_sync_error:        null,
          created_at:             s.notification_created_at || serverIsoNow(),
          updated_at:             serverIsoNow(),
          // Enriched fields (server join)
          gender:                 s.gender              || null,
          traveler_direction:     s.traveler_direction  || null,
          temperature_value:      s.temperature_value   ?? null,
          temperature_unit:       s.temperature_unit    || null,
          traveler_full_name:     s.traveler_full_name  || null,
          captured_at:            s.captured_at         || null,
          screener_name:          s.screener_name       || null,
          primary_uuid:           s.primary_uuid        || null,
          is_voided_primary:      !!s.is_voided_primary,
        }))
        // Brand-new referral landed in IDB — fire a WhatsApp-style heads-up
        // so the assigned secondary screener notices without polling. Best-
        // effort: a missing plugin / denied permission is silently skipped
        // by alertNotifier, the IDB write above is the source of truth.
        try {
          const notifier = await import('@/services/alertNotifier')
          await notifier.notifyReferralReceived({
            notification_id: s.notification_id || s.notification_uuid,
            traveler_label:  s.traveler_full_name || s.gender || 'Traveller',
            poe_label:       s.poe_code || null,
            urgency:         s.priority || (Number(s.temperature_value) >= 38.5 ? 'HIGH' : 'NORMAL'),
          })
        } catch (e) {
          console.debug('[NQ] notifyReferralReceived failed:', e?.message)
        }
      } else {
        // ── ABSORBING-STATE RATCHET ────────────────────────────────────────
        // Terminal status (CLOSED) is one-way: once the server reports a
        // notification CLOSED it is CLOSED forever, regardless of local
        // record_version. The version-guard alone is too defensive — it
        // prevents the cross-device pull from flipping local OPEN to
        // CLOSED when the server bumps closed_at without bumping version
        // (legacy controller code) or when versions happen to match.
        // We always apply: terminal status, server-reported closed_at,
        // and IN_PROGRESS as a one-way ratchet from OPEN.
        // 2026-05-19 — the /referral-queue payload uses notification_closed_at
        // (not closed_at). Falling back to existing.closed_at meant the
        // ratchet would not detect "server has now closed this notification"
        // unless versionAdvanced or serverIsTerminal also fired; under the
        // odd server schema where status flips to CLOSED but record_version
        // doesn't bump, this routine would have missed the closure.
        const serverClosedAt    = s.notification_closed_at ?? s.closed_at ?? null
        const serverOpenedAt    = s.notification_opened_at ?? s.opened_at ?? null
        const serverIsTerminal  = s.notification_status === 'CLOSED'
        const serverHasClosedAt = !!serverClosedAt && !existing.closed_at
        const serverIsInProgress = s.notification_status === 'IN_PROGRESS' && existing.status === 'OPEN'
        const versionAdvanced   = incomingVer > (existing.record_version ?? 0)

        if (versionAdvanced || serverIsTerminal || serverHasClosedAt || serverIsInProgress) {
          await safeDbPut(STORE.NOTIFICATIONS, toPlain({
            ...existing,
            id:                 s.notification_id     ?? existing.id,
            server_id:          s.notification_id     ?? existing.server_id,
            status:             s.notification_status ?? existing.status,
            priority:           s.priority            ?? existing.priority,
            reason_text:        s.reason_text         ?? existing.reason_text,
            opened_at:          serverOpenedAt        ?? existing.opened_at,
            closed_at:          serverClosedAt        ?? existing.closed_at,
            gender:             s.gender              ?? existing.gender,
            traveler_direction: s.traveler_direction  ?? existing.traveler_direction,
            temperature_value:  s.temperature_value   ?? existing.temperature_value,
            temperature_unit:   s.temperature_unit    ?? existing.temperature_unit,
            traveler_full_name: s.traveler_full_name  ?? existing.traveler_full_name,
            screener_name:      s.screener_name       ?? existing.screener_name,
            is_voided_primary:  s.is_voided_primary !== undefined ? !!s.is_voided_primary : existing.is_voided_primary,
            record_version:     Math.max(incomingVer, existing.record_version ?? 0),
            sync_status:        SYNC.SYNCED,
            synced_at:          serverIsoNow(),
            updated_at:         serverIsoNow(),
          }))
        }
        // else: nothing material changed — skip
      }
    } catch (e) {
      console.warn('[NQ] writeToIdb skip', s.notification_uuid, e?.message)
    }
  }
}

// ── IDB-AUTHORITATIVE WINDOW MERGE ────────────────────────────────────────
// After writeToIdb() has applied version-guarded writes, we read each affected
// record BACK from IDB and use that canonical state to update the window.
// This prevents stale server data (e.g., IN_PROGRESS) from overwriting a
// locally-CLOSED notification that hasn't synced to the server yet.
async function mergeWindowIdbAuth(serverItems) {
  const affectedUuids = serverItems.map(s => s.notification_uuid).filter(Boolean)
  // Batch-read from IDB — version-guarded truth
  const idbReads = await Promise.all(
    affectedUuids.map(async uuid => {
      try { return await dbGet(STORE.NOTIFICATIONS, uuid) } catch { return null }
    })
  )
  const byUuid = new Map(allItems.value.map(i => [i.notification_uuid, i]))
  for (const rec of idbReads) {
    if (!rec || rec.deleted_at) continue
    byUuid.set(rec.client_uuid, normaliseIdb(rec))
  }
  let sorted = Array.from(byUuid.values()).sort((a, b) => {
    const pd = (PRIORITY_ORDER[a.priority] ?? 99) - (PRIORITY_ORDER[b.priority] ?? 99)
    if (pd !== 0) return pd
    return new Date(b.notification_created_at || 0) - new Date(a.notification_created_at || 0)
  })
  if (sorted.length > MAX_WINDOW) sorted = sorted.slice(0, MAX_WINDOW)
  allItems.value = sorted
}

// ── GHOST CLEANUP — purge duplicate notification records from IDB ──────────
// ── One-time cross-country IDB purge ─────────────────────────────────────
// Removes notification records from OTHER countries that may have been written
// during a previous app session (e.g. Uganda/Zambia app running on same
// localhost, or a device that was used for multiple deployments).
// Only runs when there is an authenticated user — their country_code is the
// authoritative filter.  Runs silently; any error is non-fatal.
// Country code normaliser — same physical country can be stored as 'RW' (ISO),
// 'Uganda' (full English name), 'RWANDA' (uppercase), with whitespace, etc.
// Treat any of these as equivalent for purge / scope decisions. Without this
// normalisation, the previous purge soft-deleted EVERY server-fetched
// notification (server uses 'RW') because the user's auth carried 'Uganda',
// leaving only the user's own locally-created records visible.
const COUNTRY_ALIASES = {
  RW: ['RW', 'RWA', 'RWANDA'],
  ZM: ['ZM', 'ZMB', 'ZAMBIA'],
  UG: ['UG', 'UGA', 'UGANDA'],
}
function normaliseCountry(v) {
  return String(v || '').toUpperCase().trim()
}
function sameCountry(a, b) {
  const A = normaliseCountry(a), B = normaliseCountry(b)
  if (!A || !B) return false
  if (A === B) return true
  for (const set of Object.values(COUNTRY_ALIASES)) {
    const has = (x) => set.includes(x)
    if (has(A) && has(B)) return true
  }
  return false
}

// ─────────────────────────────────────────────────────────────────────────
//  STALE-DATA PURGE — runs after a COMPLETE server pull.
//
//  Problem solved: after the server cleared records (admin wipe, expiry,
//  manual delete), the mobile IDB kept the orphan rows around forever
//  because no code path actively deleted them. They showed up in the queue
//  list and made cases appear "open" to officers even though the server
//  had already removed them.
//
//  Trigger: only when the device is online AND we've successfully fetched
//  EVERY page from /referral-queue (serverItems.length >= data.total).
//  A partial pull (network blip mid-page) MUST NOT trigger this — we'd
//  delete valid local data we just didn't see.
//
//  What is kept (NEVER purged):
//    • sync_status === UNSYNCED  → user's local-only edit, not yet pushed
//    • synced_at IS NULL         → never been on the server, can't be stale
//    • already soft-deleted      → idempotent skip
//    • client_uuid in server set → server knows this record
//    • id (server integer) in server set → server knows this record
//
//  What is purged (soft-delete cascade):
//    • Notification: deleted_at = now
//    • Linked secondary_screening (by id OR client_uuid): deleted_at = now
//    • Child rows of that case (symptoms / exposures / actions / samples /
//      travel_countries / suspected_diseases): deleted_at = now
//  Soft-delete keeps an audit trail and survives later sync engine queries.
// ─────────────────────────────────────────────────────────────────────────
async function purgeStaleAgainstServer(serverItems, totalOnServer) {
  try {
    if (!Array.isArray(serverItems)) return
    // Guard: we must have fetched the FULL set, not a partial page slice.
    // If totalOnServer is unset (0) and serverItems is empty → server says
    // there are zero records → every IDB row that was previously synced
    // is stale. If totalOnServer > serverItems.length → partial pull → bail.
    if (totalOnServer > serverItems.length) {
      console.log('[NQ] purgeStaleAgainstServer: partial pull (' + serverItems.length + '/' + totalOnServer + ') — skipping stale purge')
      return
    }

    // Build server-known identifier sets.
    const serverUuids = new Set()
    const serverIds   = new Set()
    for (const s of serverItems) {
      if (s?.notification_uuid) serverUuids.add(String(s.notification_uuid))
      if (s?.notification_id)   serverIds.add(Number(s.notification_id))
    }

    const allNotifs = await dbGetAll(STORE.NOTIFICATIONS) || []
    const now = serverIsoNow()
    let purgedNotifs = 0
    let purgedCases  = 0
    let purgedKids   = 0

    for (const n of allNotifs) {
      if (n.deleted_at) continue
      // Never touch local-only edits that haven't been pushed yet.
      if (n.sync_status === SYNC.UNSYNCED) continue
      if (!n.synced_at) continue
      // Identifier match — server still knows this record.
      const localUuid = n.client_uuid ? String(n.client_uuid) : null
      const localId   = n.id ? Number(n.id) : null
      if (localUuid && serverUuids.has(localUuid)) continue
      if (localId   && serverIds.has(localId))     continue

      // Stale — server doesn't know about it any more. Soft-delete the
      // notification, the linked secondary case, and that case's child
      // rows so the queue / case-file pages stop rendering it.
      await safeDbPut(STORE.NOTIFICATIONS, {
        ...n,
        deleted_at: now,
        updated_at: now,
      }).catch(() => {})
      purgedNotifs++

      // Find any linked secondary screening — try every key shape we may
      // have written ('notification_id' indexed by uuid OR server int).
      const lookupKeys = Array.from(new Set([n.client_uuid, n.id, n.server_id].filter(Boolean)))
      const secs = []
      for (const k of lookupKeys) {
        const rows = await dbGetByIndex(STORE.SECONDARY_SCREENINGS, 'notification_id', k).catch(() => [])
        if (rows && rows.length) secs.push(...rows)
      }
      // De-dup by client_uuid in case both id-shapes returned the same row.
      const seen = new Set()
      const uniqueSecs = secs.filter(s => {
        const k = s.client_uuid
        if (!k || seen.has(k)) return false
        seen.add(k); return true
      })
      for (const sec of uniqueSecs) {
        if (sec.deleted_at) continue
        await safeDbPut(STORE.SECONDARY_SCREENINGS, {
          ...sec,
          deleted_at: now,
          updated_at: now,
        }).catch(() => {})
        purgedCases++

        // Cascade to child tables. Keyed by the case's client_uuid in IDB.
        const childStores = [
          STORE.SECONDARY_SYMPTOMS,
          STORE.SECONDARY_EXPOSURES,
          STORE.SECONDARY_ACTIONS,
          STORE.SECONDARY_SAMPLES,
          STORE.SECONDARY_TRAVEL_COUNTRIES,
          STORE.SECONDARY_SUSPECTED_DISEASES,
        ]
        for (const childStore of childStores) {
          const kids = await dbGetByIndex(childStore, 'secondary_screening_id', sec.client_uuid).catch(() => [])
          for (const kid of (kids || [])) {
            if (kid.deleted_at) continue
            await safeDbPut(childStore, {
              ...kid,
              deleted_at: now,
              updated_at: now,
            }).catch(() => {})
            purgedKids++
          }
        }
      }
    }

    if (purgedNotifs > 0 || purgedCases > 0) {
      console.log(`[NQ] purgeStaleAgainstServer: soft-deleted ${purgedNotifs} notification(s), ${purgedCases} secondary case(s), ${purgedKids} child row(s) absent from server snapshot (n=${serverItems.length})`)
    }
  } catch (e) {
    console.warn('[NQ] purgeStaleAgainstServer error', e?.message)
  }
}

async function purgeForeignCountryRecords() {
  // Mandate 2026-05-06 (rwanda1 bug fix): only purge records whose country
  // is UNAMBIGUOUSLY foreign relative to the current user's country.
  // 'RW' and 'Uganda' must be treated as equivalent — previously this
  // function compared string literals only, which soft-deleted every
  // server-fetched record because the server returns 'RW' but local auth
  // had 'Uganda'.
  try {
    const a = getAuth()
    const myCountry = a?.country_code || 'UG'
    const allNotifs = await dbGetAll(STORE.NOTIFICATIONS) || []
    let purged = 0
    for (const n of allNotifs) {
      if (n.deleted_at) continue
      // No country_code at all → keep (legacy records).
      if (!n.country_code) continue
      // Country matches (alias-aware) → keep.
      if (sameCountry(n.country_code, myCountry)) continue
      // Genuinely foreign → soft-delete.
      await safeDbPut(STORE.NOTIFICATIONS, {
        ...n,
        deleted_at: serverIsoNow(),
        updated_at: serverIsoNow(),
      })
      purged++
    }
    if (purged > 0) console.log(`[NQ] purgeForeignCountryRecords: removed ${purged} explicit-foreign record(s)`)
  } catch (e) {
    console.warn('[NQ] purgeForeignCountryRecords', e?.message)
  }
}

// Runs once on load. Finds cases where multiple notification records exist for
// the same primary_screening_id. Keeps the record with the most enrichment data
// (gender, name) and soft-deletes the ghosts. This fixes existing corruption
// from server sync creating duplicates with different UUIDs.
async function purgeGhostDuplicates() {
  try {
    const a = getAuth()
    const role = a?.role_key || ''
    const isSupervisor = ['NATIONAL_ADMIN','PHEOC_OFFICER','DISTRICT_SUPERVISOR'].includes(role)
    // Supervisors: dedup across all records they can see; POE staff: dedup their POE
    let all
    if (isSupervisor || !a?.poe_code) {
      all = await dbGetAll(STORE.NOTIFICATIONS) || []
    } else {
      const records = []
      for (const code of resolvePoeCodes(a.poe_code)) {
        const recs = await dbGetByIndex(STORE.NOTIFICATIONS, 'poe_code', code).catch(() => [])
        records.push(...(recs || []))
      }
      all = records
    }
    const referrals = all.filter(n => n.notification_type === 'SECONDARY_REFERRAL' && !n.deleted_at)

    // Group by primary_screening_id
    const groups = new Map()
    for (const n of referrals) {
      const pk = n.primary_screening_id
      if (!pk) continue
      if (!groups.has(pk)) groups.set(pk, [])
      groups.get(pk).push(n)
    }

    let purged = 0
    for (const [, siblings] of groups) {
      if (siblings.length <= 1) continue

      // Score each: enrichment data + version → keep the best
      siblings.sort((a, b) => {
        const sa = (a.gender ? 2 : 0) + (a.traveler_full_name ? 2 : 0) + (a.record_version ?? 0)
        const sb = (b.gender ? 2 : 0) + (b.traveler_full_name ? 2 : 0) + (b.record_version ?? 0)
        return sb - sa // highest score first
      })

      // Keep first (best), soft-delete the rest
      for (let i = 1; i < siblings.length; i++) {
        await safeDbPut(STORE.NOTIFICATIONS, {
          ...siblings[i],
          deleted_at: serverIsoNow(),
          record_version: (siblings[i].record_version || 1) + 1,
          updated_at: serverIsoNow(),
        })
        purged++
      }
    }
    if (purged > 0) console.log(`[NQ] purgeGhostDuplicates: removed ${purged} ghost duplicate(s)`)
  } catch (e) {
    console.warn('[NQ] purgeGhostDuplicates', e?.message)
  }
}

// ── IDB repair — undo incorrect soft-deletes from buggy purge ─────────────
// A previous build of purgeForeignCountryRecords used isUgandaPoeCode() before
// window.POEs was loaded, which caused legitimate Uganda records with canonical
// poe_names to be incorrectly soft-deleted. This one-time repair undeletes any
// record that was soft-deleted today AND has country_code='RW'.
async function repairIncorrectlySoftDeleted() {
  // Mandate 2026-05-06: ALWAYS run this self-heal on every load (no
  // one-shot lock). It undoes any records soft-deleted by a country-alias
  // mismatch — the previous bug where 'RW' records were treated as
  // foreign because user auth had 'Uganda'. Self-heal is idempotent: if
  // there's nothing to repair, it's a 1ms IDB scan no-op.
  const a = getAuth()
  const myCountry = a?.country_code || 'UG'
  try {
    const all = await dbGetAll(STORE.NOTIFICATIONS) || []
    let repaired = 0
    for (const n of all) {
      if (!n.deleted_at) continue
      // Undelete any record whose country_code matches the user's tenant
      // (alias-aware). Records soft-deleted with explicit foreign country
      // code stay deleted (they ARE foreign).
      if (sameCountry(n.country_code, myCountry)) {
        await safeDbPut(STORE.NOTIFICATIONS, { ...n, deleted_at: null, updated_at: serverIsoNow() })
        repaired++
      }
    }
    if (repaired > 0) console.log(`[NQ] self-heal restored ${repaired} record(s) wrongly soft-deleted by country-alias mismatch`)
  } catch (e) { console.warn('[NQ] repair', e?.message) }
}

// ── INITIAL LOAD — IDB first, then aggressive server pull ─────────────────
// When online, pulls ALL pages (up to FULL_FETCH_PAGES) so the full case
// history — OPEN + IN_PROGRESS + CLOSED — lands locally on first paint.
async function load() {
  loading.value       = true
  idbPageOffset.value = 0
  serverPage.value    = 1
  hasMoreIdb.value    = true
  hasMoreServer.value = true

  try {
    // Repair any records incorrectly soft-deleted by buggy purge (runs once)
    await repairIncorrectlySoftDeleted()
    // Purge records from OTHER countries (Zambia/Uganda bleed) before reading
    await purgeForeignCountryRecords()
    // Clean up any existing ghost duplicates before reading
    await purgeGhostDuplicates()
    // 1. IDB page 0 → render immediately (zero network dependency)
    const idbPage = await readIdbPage(0)
    allItems.value      = idbPage
    idbPageOffset.value = IDB_PAGE_SIZE
    hasMoreIdb.value    = idbPage.length === IDB_PAGE_SIZE

    // 2. Stats in parallel (O(1), non-blocking)
    refreshIdbStats().catch(() => {})
    loadDamaged().catch(() => {})

    // 3. Aggressive server pull — fetch up to FULL_FETCH_PAGES pages so
    //    the user sees their full case history including CLOSED records.
    if (isOnline.value) {
      let pg = 1
      let totalItems = []
      let lastPage   = 1
      // Capture server_time across pages — last successful page wins.
      // This anchors the cursor to server clock even when items is empty.
      let latestServerTime = null
      while (pg <= FULL_FETCH_PAGES) {
        const data = await fetchServer(pg)
        if (!data) break
        totalOnServer.value = data.total || 0
        lastPage = data.pages ?? 1
        if (data.server_time) latestServerTime = data.server_time
        if (Array.isArray(data.items) && data.items.length > 0) {
          totalItems.push(...data.items)
        }
        if ((data.page ?? pg) >= lastPage) break
        pg++
      }
      serverPage.value    = pg + 1
      hasMoreServer.value = serverPage.value <= lastPage
      if (totalItems.length > 0) {
        await writeToIdb(totalItems)
        await mergeWindowIdbAuth(totalItems)
        await refreshIdbStats()
        advanceCursor(totalItems, latestServerTime)
        markUiSynced()
      } else {
        // Empty server response — anchor cursor to server time so future
        // polls only ask for "anything since now (server clock)".
        if (latestServerTime) {
          advanceCursor([], latestServerTime)
        } else {
          try { localStorage.removeItem(LAST_SYNC_KEY) } catch {}
        }
        markUiSynced()
        await refreshIdbStats()
      }

      // ── Stale-data purge against the server snapshot ──────────────────
      // After a complete server pull we have an authoritative list of what
      // the server still knows about. Anything in the local IDB that was
      // previously SYNCED but is no longer in that list is stale (admin
      // wipe, expiry, manual delete) — soft-delete it so the queue stops
      // rendering ghost cases. UNSYNCED local-only edits are preserved.
      // Only fires when totalItems.length >= server's total (full pull).
      await purgeStaleAgainstServer(totalItems, totalOnServer.value)
      // Re-read the visible window from IDB so the freshly-purged ghosts
      // disappear from the queue without waiting for the next poll.
      try {
        const refreshed = await readIdbPage(0)
        allItems.value      = refreshed
        idbPageOffset.value = IDB_PAGE_SIZE
        hasMoreIdb.value    = refreshed.length === IDB_PAGE_SIZE
        await refreshIdbStats()
      } catch (_) { /* non-fatal — next poll will reconcile */ }

      // ── Reconcile against tenant snapshot ───────────────────────────
      // After a full pull we know the server's authoritative view. Use
      // that opportunity to hard-purge cross-tenant residue (Zambia
      // POEs in a Uganda install, references to deleted users, etc.).
      // Default mode is non-dry-run; safety is multi-layered inside
      // reconcileTenant — see tenantReconciler.js.
      try {
        if (auth.value?.id) {
          const report = await reconcileTenant({
            userId: auth.value.id,
            dryRun: false,
            force:  false,
          })
          if (report.deleted > 0) {
            console.log(`[NQ] reconciler hard-purged ${report.deleted} record(s)`, report.purged)
          }
        }
      } catch (e) {
        console.warn('[NQ] reconcileTenant failed', e?.message)
      }
    }
  } catch (e) {
    console.error('[NQ] load error', e)
    // Failed load — drop the cursor so the next attempt does a fresh full
    // pull instead of inheriting a stale cursor from a previous session.
    try { localStorage.removeItem(LAST_SYNC_KEY) } catch {}
    lastSyncAt.value = null
  } finally {
    loading.value = false
  }
}

// ── LOAD MORE — IDB pages first, then server pages ────────────────────────
async function loadMore() {
  if (loadingMore.value) return
  loadingMore.value = true
  try {
    if (hasMoreIdb.value) {
      const idbPage = await readIdbPage(idbPageOffset.value)
      if (idbPage.length > 0) {
        const byUuid = new Map(allItems.value.map(i => [i.notification_uuid, i]))
        idbPage.forEach(i => byUuid.set(i.notification_uuid, i))
        let merged = Array.from(byUuid.values()).sort((a, b) => {
          const pd = (PRIORITY_ORDER[a.priority] ?? 99) - (PRIORITY_ORDER[b.priority] ?? 99)
          if (pd !== 0) return pd
          return new Date(b.notification_created_at || 0) - new Date(a.notification_created_at || 0)
        }).slice(0, MAX_WINDOW)
        allItems.value      = merged
        idbPageOffset.value += IDB_PAGE_SIZE
        hasMoreIdb.value     = idbPage.length === IDB_PAGE_SIZE
        return
      }
      hasMoreIdb.value = false
    }
    if (hasMoreServer.value && isOnline.value) {
      const data = await fetchServer(serverPage.value)
      if (data) {
        totalOnServer.value = data.total || 0
        hasMoreServer.value = (data.page ?? serverPage.value) < (data.pages ?? 1)
        serverPage.value++
        await writeToIdb(data.items || [])
        await mergeWindowIdbAuth(data.items || [])
        await refreshIdbStats()
      }
    }
  } catch (e) {
    console.warn('[NQ] loadMore error', e?.message)
  } finally {
    loadingMore.value = false
  }
}

// ── BACKGROUND INCREMENTAL SYNC ───────────────────────────────────────────
// Uses updated_after cursor — only fetches records changed since last sync.
// Debounced so rapid reconnect/tab-switch events don't cause concurrent calls.
async function backgroundSync(debounceMs = 500) {
  if (!isOnline.value || syncing.value) return
  clearTimeout(bgDebounce)
  bgDebounce = setTimeout(async () => {
    bgDebounce   = null
    syncing.value = true
    try {
      // Validate cursor against SERVER clock (not device clock) — the
      // cursor is set from server-reported timestamps, so comparing it
      // to Date.now() would falsely flag valid cursors as "in the future"
      // on devices whose clock is behind the server. Allow a small
      // forward-skew tolerance (5 min) just in case.
      const rawSync  = localStorage.getItem(LAST_SYNC_KEY) || null
      let lastSync   = null
      if (rawSync) {
        const ts = new Date(rawSync).getTime()
        const serverMs = Date.now() + (Number(localStorage.getItem('rw_server_skew_ms') || 0) || 0)
        const tolerance = 5 * 60_000
        if (!isNaN(ts) && ts <= serverMs + tolerance && ts >= serverMs - 7 * 24 * 3600_000) {
          lastSync = rawSync
        } else {
          // Corrupt or cross-app timestamp — drop cursor, do full fetch
          localStorage.removeItem(LAST_SYNC_KEY)
        }
      }
      const data     = await fetchServer(1, lastSync)
      if (data) {
        if (data.items?.length) {
          await writeToIdb(data.items)
          await mergeWindowIdbAuth(data.items)
        }
        // Advance cursor to server clock — never device clock. This is
        // the fix for cross-device staleness: a CLOSED record on Device A
        // landed in n.updated_at = T_server; Device B's cursor will now
        // also be on server clock so the next poll's WHERE n.updated_at >
        // cursor remains coherent.
        advanceCursor(data.items || [], data.server_time)
      }
      // Reconcile stale notifications — close any whose linked case is done
      await reconcileStaleNotifications()
      await refreshIdbStats()
      await loadDamaged()
    } catch (e) {
      console.warn('[NQ] backgroundSync error', e?.message)
    } finally {
      // UI freshness only; cursor was advanced explicitly above against
      // server time, not wall-clock.
      markUiSynced()
      syncing.value = false
    }
  }, debounceMs)
}

// ── STALE NOTIFICATION RECONCILIATION ─────────────────────────────────────
// Scans OPEN/IN_PROGRESS notifications in IDB. For each, checks if the linked
// secondary screening case is CLOSED or DISPOSITIONED. If so, closes the
// notification locally. This catches status mismatches from:
// - Cases dispositioned/closed on another device
// - Server-side status changes not yet reflected in the notification
// - Legacy data where the notification close was missed
async function reconcileStaleNotifications() {
  try {
    const poeCode = auth.value?.poe_code || ''
    if (!poeCode) return
    const allNotifs = await dbGetByIndex(STORE.NOTIFICATIONS, 'poe_code', poeCode)
    const openNotifs = allNotifs.filter(n => !n.deleted_at && (n.status === 'OPEN' || n.status === 'IN_PROGRESS'))
    let fixed = 0
    for (const notif of openNotifs) {
      const nid = notif.id || notif.server_id
      if (!nid) continue
      // Look up linked secondary screening by notification_id
      const linked = await dbGetByIndex(STORE.SECONDARY_SCREENINGS, 'notification_id', nid).catch(() => [])
      const activeCase = linked.find(s => !s.deleted_at)
      // Mandate 2026-05-06 (collapse fix): only auto-close the notification
      // when the linked case is BOTH terminal AND fully synced to the server.
      // Previously this routine flipped every locally-DISPOSITIONED case's
      // notification to CLOSED on every refresh, zeroing the queue before
      // the offline disposition data had reached the server.
      // The unified syncEngine treats a secondary case as fully synced iff
      // sync_status===SYNCED AND last_synced_record_version has caught up
      // to record_version. This is the correct gate for auto-closing the
      // matching notification.
      const fullySynced = !!activeCase && (
        activeCase.sync_status === SYNC.SYNCED &&
        (activeCase.last_synced_record_version || 0) >= (activeCase.record_version || 0)
      )
      if (activeCase && fullySynced && ['CLOSED', 'DISPOSITIONED'].includes(activeCase.case_status)) {
        // Case is done AND server has the disposition — safe to close locally
        await safeDbPut(STORE.NOTIFICATIONS, {
          ...notif,
          status: 'CLOSED',
          closed_at: activeCase.dispositioned_at || activeCase.closed_at || serverIsoNow(),
          updated_at: serverIsoNow(),
        }).catch(() => {})
        // Update in memory window
        const idx = allItems.value.findIndex(i => i.notification_uuid === notif.client_uuid)
        if (idx !== -1) {
          allItems.value[idx] = { ...allItems.value[idx], notification_status: 'CLOSED' }
        }
        fixed++
      }
    }
    if (fixed > 0) {
      allItems.value = [...allItems.value]
      console.log(`[NQ] Reconciled ${fixed} stale notification${fixed !== 1 ? 's' : ''}`)
    }
  } catch (e) {
    console.warn('[NQ] reconcileStaleNotifications error', e?.message)
  }
}

// ── PULL-TO-REFRESH — STRICTLY ADDITIVE, NEVER DESTRUCTIVE ───────────────
// Mandate 2026-05-06 (collapse fix): pull-to-refresh MUST NEVER soft-delete
// or close any IDB record. It only:
//   1) re-reads paginated server pages and writes them through to IDB
//      (write-through cache is version-guarded so stale writes are blocked)
//   2) re-renders the in-memory window from IDB
//   3) kicks the unified syncEngine to drain any UNSYNCED outgoing records
// The destructive purges + reconcile-stale-notifications run ONLY in the
// initial onMounted load(), never on user-triggered refresh.
async function manualRefresh() {
  auth.value = getAuth()
  loading.value = true
  // Drop the incremental-sync cursor so this refresh ALWAYS does a full
  // server pull. Without this, a stale cursor (set by a previous session
  // that never actually populated IDB) silently caps the queue at 0 new
  // records — the user sees only their local creations and nothing else.
  // The cursor is recomputed via markSynced() at the end of every load.
  try { localStorage.removeItem(LAST_SYNC_KEY) } catch {}
  try {
    // Re-render from current IDB state first — so the user sees instant feedback
    const idbPage = await readIdbPage(0)
    if (idbPage.length > 0) {
      const byUuid = new Map(allItems.value.map(i => [i.notification_uuid, i]))
      for (const it of idbPage) byUuid.set(it.notification_uuid, it)
      let merged = Array.from(byUuid.values()).sort((a, b) => {
        const pd = (PRIORITY_ORDER[a.priority] ?? 99) - (PRIORITY_ORDER[b.priority] ?? 99)
        if (pd !== 0) return pd
        return new Date(b.notification_created_at || 0) - new Date(a.notification_created_at || 0)
      }).slice(0, MAX_WINDOW)
      allItems.value = merged
      idbPageOffset.value = IDB_PAGE_SIZE
      hasMoreIdb.value    = idbPage.length === IDB_PAGE_SIZE
    }
    // Server pull — additive write-through, version-guarded inside writeToIdb.
    if (isOnline.value) {
      let pg = 1
      const totalItems = []
      let lastPage = 1
      let latestServerTime = null
      while (pg <= FULL_FETCH_PAGES) {
        const data = await fetchServer(pg)
        if (!data) break
        totalOnServer.value = data.total || 0
        lastPage = data.pages ?? 1
        if (data.server_time) latestServerTime = data.server_time
        if (Array.isArray(data.items) && data.items.length > 0) totalItems.push(...data.items)
        if ((data.page ?? pg) >= lastPage) break
        pg++
      }
      serverPage.value    = pg + 1
      hasMoreServer.value = serverPage.value <= lastPage
      if (totalItems.length > 0) {
        await writeToIdb(totalItems)
        await mergeWindowIdbAuth(totalItems)
      }
      advanceCursor(totalItems, latestServerTime)
      markUiSynced()

      // Pull-to-refresh is the user's explicit "I want fresh truth" signal.
      // Run the tenant reconciler here too so cross-tenant residue gets
      // hard-purged immediately on demand.
      try {
        if (auth.value?.id) {
          const report = await reconcileTenant({
            userId: auth.value.id,
            dryRun: false,
            force:  true,  // bypass cache so the user gets the freshest snapshot
          })
          if (report.deleted > 0) {
            console.log(`[NQ] manualRefresh reconciler hard-purged ${report.deleted} record(s)`, report.purged)
          }
        }
      } catch (e) {
        console.warn('[NQ] manualRefresh reconcileTenant failed', e?.message)
      }
    }
    refreshIdbStats().catch(() => {})
    loadDamaged().catch(() => {})
    // Redundant kick the unified syncEngine — push any UNSYNCED outbound
    // records right now without waiting for the periodic 10s poll.
    if (typeof window !== 'undefined' && typeof window.__SYNC_NOW__ === 'function') {
      window.__SYNC_NOW__('NotificationsCenter:manualRefresh')
    }
  } catch (e) {
    console.warn('[NQ] manualRefresh error', e?.message)
  } finally {
    loading.value = false
  }
}

// ── OPEN CASE — guaranteed IDB preflight before navigation ────────────────
// Ensures SecondaryScreening.vue can read the full record from IDB.
// Navigation uses client_uuid (IDB primary key), not server integer id.
async function openCase(item) {
  if (!item.notification_uuid) {
    showMsg('Referral ID missing — please refresh.', 'warning')
    return
  }
  openingUuid.value = item.notification_uuid

  try {
    // ── CHECK: Is the linked secondary case already closed? ─────────
    // The notification status may be stale (IN_PROGRESS) while the case
    // was already dispositioned/closed. Check IDB for the actual case status.
    const notifRec = await dbGet(STORE.NOTIFICATIONS, item.notification_uuid).catch(() => null)
    if (notifRec) {
      // Find the secondary screening linked to this notification.
      // Cases stored from offline screens are indexed by notification_id =
      // notification client_uuid; once synced they pick up the server id
      // too — try both so we never miss an in-progress case.
      const lookupKeys = Array.from(new Set([
        notifRec.id, notifRec.server_id, notifRec.client_uuid,
      ].filter(Boolean)))
      const allSec = []
      for (const k of lookupKeys) {
        const rows = await dbGetByIndex(STORE.SECONDARY_SCREENINGS, 'notification_id', k).catch(() => [])
        if (rows && rows.length) allSec.push(...rows)
      }
      const linkedCase = allSec.find(s => !s.deleted_at)
      if (linkedCase && ['CLOSED', 'DISPOSITIONED'].includes(linkedCase.case_status)) {
        // Case is done — update the notification status locally and show message
        await safeDbPut(STORE.NOTIFICATIONS, {
          ...notifRec,
          status: 'CLOSED',
          closed_at: linkedCase.dispositioned_at || linkedCase.closed_at || serverIsoNow(),
          updated_at: serverIsoNow(),
        }).catch(() => {})
        // Remove from active lists
        const idx = allItems.value.findIndex(i => i.notification_uuid === item.notification_uuid)
        if (idx !== -1) {
          allItems.value[idx] = { ...allItems.value[idx], notification_status: 'CLOSED' }
          allItems.value = [...allItems.value]
        }
        await refreshIdbStats()
        showMsg(`This case is already ${linkedCase.case_status.toLowerCase().replace('_', ' ')}. Referral closed.`, 'warning')
        openingUuid.value = null
        return
      }
    }

    // ── SERVER PREFLIGHT: cross-device guard ──────────────────────────
    // The local IDB check above only catches cases that were opened on
    // THIS device. A case finished on another device may not be in our
    // IDB yet (sync hasn't pulled it). Hit /secondary-screenings/by-notification
    // to ask the server canonically. If DISPOSITIONED/CLOSED, refuse.
    // Network failures here are tolerated — we already have the local
    // guard above, and offline use must continue to work.
    //
    // 2026-05-19: the server endpoint requires ?user_id for jurisdiction
    // scope; without it the response was a silent 422 and Screener B could
    // re-open a case Screener A had already closed. Pass auth.id here.
    // Also mirror the linked notification's CLOSED status to IDB so the
    // queue list flips immediately on the next IDB read, without waiting
    // for the next /referral-queue poll.
    if (typeof navigator !== 'undefined' && navigator.onLine && window.SERVER_URL) {
      try {
        const uid = auth.value?.id
        const ctrl = new AbortController()
        const tid = setTimeout(() => ctrl.abort(), 4000)
        const url = `${window.SERVER_URL}/secondary-screenings/by-notification/${encodeURIComponent(item.notification_uuid)}?user_id=${encodeURIComponent(uid ?? '')}`
        const r = await fetch(url, {
          method: 'GET', headers: { 'Accept': 'application/json' }, signal: ctrl.signal,
        })
        clearTimeout(tid)
        if (r.ok) {
          const b = await r.json().catch(() => ({}))
          const remoteCase = b?.data
          if (remoteCase && ['DISPOSITIONED', 'CLOSED'].includes(remoteCase.case_status)) {
            // Mirror server state into IDB so subsequent local guards trip too.
            await safeDbPut(STORE.SECONDARY_SCREENINGS, {
              ...toPlain(remoteCase),
              sync_status: SYNC.SYNCED,
              synced_at: serverIsoNow(),
            }).catch(() => {})
            // ALSO mirror the notification's CLOSED status so the queue
            // updates instantly. The server's auto-close transition runs
            // server-side when case_status becomes DISPOSITIONED/CLOSED,
            // so a closed case implies a closed notification.
            try {
              const closedAt = remoteCase.dispositioned_at || remoteCase.closed_at || serverIsoNow()
              const existingNotif = await dbGet(STORE.NOTIFICATIONS, item.notification_uuid).catch(() => null)
              if (existingNotif && existingNotif.status !== 'CLOSED') {
                await safeDbPut(STORE.NOTIFICATIONS, {
                  ...existingNotif,
                  status:         'CLOSED',
                  closed_at:      existingNotif.closed_at || closedAt,
                  record_version: (existingNotif.record_version || 1) + 1,
                  sync_status:    SYNC.SYNCED,
                  synced_at:      serverIsoNow(),
                  updated_at:     serverIsoNow(),
                })
              }
              // Also flip the in-memory window so the user doesn't have to refresh.
              const idx = allItems.value.findIndex(i => i.notification_uuid === item.notification_uuid)
              if (idx !== -1) {
                allItems.value[idx] = { ...allItems.value[idx], notification_status: 'CLOSED', closed_at: closedAt }
                allItems.value = [...allItems.value]
              }
              refreshIdbStats().catch(() => {})
            } catch (_) { /* mirror is best-effort */ }
            showMsg(`This case was already ${remoteCase.case_status.toLowerCase().replace('_', ' ')} on another device. Cannot resume.`, 'warning')
            openingUuid.value = null
            return
          }
        }
      } catch { /* network failure — fall through to local-IDB-only path */ }
    }

    // PREFLIGHT: ensure full record is in IDB before navigation
    const existing = await dbGet(STORE.NOTIFICATIONS, item.notification_uuid)
    if (!existing) {
      // Write minimal record so SecondaryScreening can load it
      await dbPut(STORE.NOTIFICATIONS, toPlain({
        client_uuid:            item.notification_uuid,
        id:                     item.notification_id     ?? null,
        server_id:              item.notification_id     ?? null,
        reference_data_version: APP.REFERENCE_DATA_VER,
        server_received_at:     null,
        country_code:           item.country_code        || '',
        province_code:          item.province_code       || null,
        pheoc_code:             item.pheoc_code          || null,
        district_code:          item.district_code       || '',
        poe_code:               item.poe_code            || auth.value?.poe_code || '',
        primary_screening_id:   item.primary_uuid        || String(item.primary_screening_id || ''),
        created_by_user_id:     null,
        notification_type:      'SECONDARY_REFERRAL',
        status:                 item.notification_status || 'OPEN',
        priority:               item.priority            || 'NORMAL',
        reason_code:            item.reason_code         || 'PRIMARY_SYMPTOMS_DETECTED',
        reason_text:            item.reason_text         || null,
        assigned_role_key:      item.assigned_role_key   || 'POE_SECONDARY',
        assigned_user_id:       item.assigned_user_id    ?? null,
        opened_at:              item.opened_at           || null,
        closed_at:              item.closed_at           || null,
        device_id:              'SERVER',
        app_version:            null,
        platform:               'WEB',
        record_version:         1,
        deleted_at:             null,
        sync_status:            SYNC.SYNCED,
        synced_at:              serverIsoNow(),
        sync_attempt_count:     0,
        last_sync_error:        null,
        sync_note:              null,
        created_at:             item.notification_created_at || serverIsoNow(),
        updated_at:             serverIsoNow(),
        gender:                 item.gender              || null,
        traveler_direction:     item.traveler_direction  || null,
        temperature_value:      item.temperature_value   ?? null,
        temperature_unit:       item.temperature_unit    || null,
        traveler_full_name:     item.traveler_full_name  || null,
        captured_at:            item.captured_at         || null,
        screener_name:          item.screener_name       || null,
        primary_uuid:           item.primary_uuid        || null,
        is_voided_primary:      !!item.is_voided_primary,
      }))
    }
    // Audit NC-001: confirm the record actually landed in IDB before
    // navigating — without this, a silent IDB write failure would push
    // the user into SecondaryScreening with no record, producing a blank
    // form and lost work.
    const verify = await dbGet(STORE.NOTIFICATIONS, item.notification_uuid).catch(() => null)
    if (!verify) {
      showMsg('Could not stage this case offline. Please retry or check storage.', 'danger')
      openingUuid.value = null
      return
    }
    // Navigate — SecondaryScreening reads IDB by params.notificationId (client_uuid)
    router.push('/secondary-screening/' + item.notification_uuid)
  } catch (e) {
    console.error('[NQ] openCase IDB preflight failed', e)
    // Audit NC-001 fix: do NOT navigate when preflight fails — the
    // SecondaryScreening view requires the record in IDB and the previous
    // fall-through silently produced a blank case form. Surface the error
    // and let the user retry.
    showMsg('Failed to open this case — IDB write error. Try again.', 'danger')
  } finally {
    openingUuid.value = null
  }
}

async function openCaseFromDamaged(d) {
  if (!d.client_uuid) { showMsg('No UUID on this record.', 'warning'); return }
  router.push('/secondary-screening/' + d.client_uuid)
}

function openDetail(item) {
  detailItem.value = item
}

// ── CANCEL REFERRAL ───────────────────────────────────────────────────────
// BUSINESS RULE: cancel only for OPEN records. IN_PROGRESS must be closed
// by the secondary officer from within the screening form.
function confirmCancel(item) {
  if (item.notification_status === 'IN_PROGRESS') {
    showMsg('Case is in progress — secondary officer must close it from their screening view.', 'warning')
    return
  }
  if (item.notification_status === 'CLOSED') {
    showMsg('This referral is already closed.', 'warning')
    return
  }
  cancelTarget.value    = item
  showCancelAlert.value = true
}

const cancelAlertBtns = [
  {
    text:    'Keep Referral',
    role:    'cancel',
    handler: () => { showCancelAlert.value = false; cancelTarget.value = null },
  },
  {
    text:    'Cancel Referral',
    role:    'destructive',
    handler: () => executeCancel(),
  },
]

async function executeCancel() {
  const item = cancelTarget.value
  if (!item) return
  const notifId = item.notification_id
  cancellingId.value    = notifId
  showCancelAlert.value = false

  // Optimistic removal from memory window
  const idx = allItems.value.findIndex(i => i.notification_id === notifId)
  if (idx !== -1) {
    allItems.value = allItems.value.filter((_, i) => i !== idx)
  }

  try {
    if (isOnline.value && Number.isInteger(Number(notifId)) && Number(notifId) > 0) {
      const res = await fetch(`${window.SERVER_URL}/referral-queue/${notifId}/cancel`, {
        method:  'PATCH',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body:    JSON.stringify({
          user_id:       auth.value?.id,
          cancel_reason: 'Cancelled by officer from referral queue.',
        }),
      })
      if (!res.ok) {
        // Rollback optimistic removal
        if (idx !== -1) allItems.value = [...allItems.value.slice(0, idx), item, ...allItems.value.slice(idx)]
        const ej = await res.json().catch(() => ({}))
        showMsg(ej.message || 'Could not cancel referral.', 'danger')
        return
      }
      // Mark closed in IDB
      try {
        const stored = await dbGet(STORE.NOTIFICATIONS, item.notification_uuid)
        if (stored) {
          await safeDbPut(STORE.NOTIFICATIONS, toPlain({
            ...stored,
            status:         'CLOSED',
            closed_at:      serverIsoNow(),
            sync_status:    SYNC.SYNCED,
            record_version: (stored.record_version || 1) + 1,
            updated_at:     serverIsoNow(),
          }))
        }
      } catch {}
      await refreshIdbStats()
      showMsg('Referral cancelled. Primary record preserved.', 'success')
    } else if (!isOnline.value) {
      showMsg('Offline — referral hidden locally. Reappears on reconnection if not cancelled server-side.', 'warning')
    } else {
      showMsg('Referral not yet synced — no server record to cancel.', 'warning')
    }
  } catch {
    if (idx !== -1) allItems.value = [...allItems.value.slice(0, idx), item, ...allItems.value.slice(idx)]
    showMsg('Network error — referral not cancelled.', 'danger')
  } finally {
    cancellingId.value = null
    cancelTarget.value = null
  }
}

// ── DAMAGED: RETRY ────────────────────────────────────────────────────────
async function retryDamaged(d) {
  retryingUuid.value = d.client_uuid
  try {
    const rec = await dbGet(STORE.NOTIFICATIONS, d.client_uuid)
    if (!rec) { showMsg('Record not found in local cache.', 'warning'); return }
    await safeDbPut(STORE.NOTIFICATIONS, toPlain({
      ...rec,
      sync_status:        SYNC.UNSYNCED,
      sync_attempt_count: 0,
      last_sync_error:    null,
      record_version:     (rec.record_version || 1) + 1,
      updated_at:         serverIsoNow(),
    }))
    damaged.value = damaged.value.filter(x => x.client_uuid !== d.client_uuid)
    await refreshIdbStats()
    showMsg('Record reset — queued for retry sync.', 'success')
    backgroundSync(0) // immediate sync attempt
  } catch (e) {
    showMsg(`Retry failed: ${e?.message || 'Unknown error'}`, 'danger')
  } finally {
    retryingUuid.value = null
  }
}

// ── DAMAGED: DISMISS (soft-delete) ────────────────────────────────────────
async function dismissDamaged(d) {
  try {
    const rec = await dbGet(STORE.NOTIFICATIONS, d.client_uuid)
    if (rec) {
      await safeDbPut(STORE.NOTIFICATIONS, toPlain({
        ...rec,
        deleted_at:     serverIsoNow(),
        record_version: (rec.record_version || 1) + 1,
        updated_at:     serverIsoNow(),
      }))
    }
    damaged.value = damaged.value.filter(x => x.client_uuid !== d.client_uuid)
    await refreshIdbStats()
    showMsg('Damaged record dismissed and removed from queue.', 'warning')
  } catch (e) {
    showMsg(`Dismiss failed: ${e?.message}`, 'danger')
  }
}

// ── FILTERS ───────────────────────────────────────────────────────────────
function setTab(v) { activeTab.value = v }

// ── CONNECTIVITY ──────────────────────────────────────────────────────────
// On reconnect, do a FULL load (not just incremental) so any records the
// server received while the device was offline land immediately.
function onOnline()  { isOnline.value = true;  load().catch(() => {}) }
function onOffline() { isOnline.value = false }

// Visibility change — when the tab becomes visible again, force a fresh
// background sync so the user always sees current data without manual refresh.
function onVisibility() {
  if (typeof document !== 'undefined' && !document.hidden && isOnline.value) {
    backgroundSync(150)
  }
}

// ── LIFECYCLE ─────────────────────────────────────────────────────────────
onMounted(() => {
  auth.value = getAuth()
  window.addEventListener('online',  onOnline)
  window.addEventListener('offline', onOffline)
  if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', onVisibility)
  }
  load()
  // Belt-and-braces: tell the unified syncEngine to drain on every view-enter
  // and on the periodic in-view poll. The engine has its own 10s poll +
  // online/visibility/focus/pageshow triggers; this is redundancy.
  if (typeof window !== 'undefined' && typeof window.__SYNC_NOW__ === 'function') {
    window.__SYNC_NOW__('NotificationsCenter:onMounted')
  }
  // Aggressive background poll — every 8 seconds. Cheap because the
  // server now honours updated_after, so each poll is incremental.
  pollTimer = setInterval(() => {
    if (isOnline.value && !loading.value) backgroundSync()
    if (typeof window !== 'undefined' && typeof window.__SYNC_NOW__ === 'function') {
      window.__SYNC_NOW__('NotificationsCenter:poll')
    }
  }, POLL_INTERVAL_MS)
})

// Returning from secondary screening — re-read IDB FIRST (instant), then sync from server.
// IDB is authoritative: SecondaryScreening.dispositionCase() did dbAtomicWrite before navigating.
// Without the IDB reload, the memory window would show stale IN_PROGRESS until the next server poll.
onIonViewDidEnter(() => {
  auth.value = getAuth()
  // Immediate IDB reload — picks up any disposition written by SecondaryScreening
  readIdbPage(0).then(freshPage => {
    if (freshPage.length === 0) return
    const byUuid = new Map(allItems.value.map(i => [i.notification_uuid, i]))
    for (const item of freshPage) byUuid.set(item.notification_uuid, item)
    allItems.value = Array.from(byUuid.values()).sort((a, b) => {
      const pd = (PRIORITY_ORDER[a.priority] ?? 99) - (PRIORITY_ORDER[b.priority] ?? 99)
      return pd !== 0 ? pd : new Date(b.notification_created_at || 0) - new Date(a.notification_created_at || 0)
    }).slice(0, MAX_WINDOW)
    refreshIdbStats().catch(() => {})
  }).catch(() => {})
  // Reconcile stale notifications immediately (catches cases closed in SecondaryScreening)
  reconcileStaleNotifications().catch(() => {})
  // Then incremental server sync in background (200ms debounce)
  backgroundSync(200)
  loadDamaged().catch(() => {})
  // Redundancy: nudge the unified syncEngine on every view-enter so any
  // outbound records get pushed without waiting for the next periodic tick.
  if (typeof window !== 'undefined' && typeof window.__SYNC_NOW__ === 'function') {
    window.__SYNC_NOW__('NotificationsCenter:viewEnter')
  }
})

// Keep timers alive — Ionic caches the page
onIonViewWillLeave(() => { /* intentionally empty */ })

onUnmounted(() => {
  window.removeEventListener('online',  onOnline)
  window.removeEventListener('offline', onOffline)
  if (typeof document !== 'undefined') {
    document.removeEventListener('visibilitychange', onVisibility)
  }
  clearInterval(pollTimer)
  clearTimeout(bgDebounce)
  clearTimeout(searchDebounceTimer)
})
</script>

<style scoped>
/* ═══════════════════════════════════════════════════════════════════════
   REFERRAL QUEUE · nq-* namespace
   Font: system-ui stack — no external requests
   Dual-tone: dark header / light content
   All numbers via tabular-nums for alignment stability
═══════════════════════════════════════════════════════════════════════ */

/* ── Font & base ── */
:host, * {
  font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Segoe UI',
    Ubuntu, 'Helvetica Neue', Arial, sans-serif;
  -webkit-font-smoothing: antialiased;
  box-sizing: border-box;
}

/* ── Keyframes ── */
@keyframes spin     { to { transform: rotate(360deg) } }
@keyframes pulse    { 0%,100% { opacity:1 } 50% { opacity:.25 } }
@keyframes slideUp  { from { opacity:0; transform:translateY(10px) } to { opacity:1; transform:translateY(0) } }
@keyframes shimmer  { 0%,100% { opacity:.4 } 50% { opacity:.8 } }
@media (prefers-reduced-motion:reduce) {
  *, *::before, *::after { animation-duration:.01ms!important; transition-duration:.01ms!important }
}

/* ═══ DARK ZONE — Header ═════════════════════════════════════════════ */
.nq-page    { --background: #0A1628; }
.nq-hdr     { --background: #0A1628; --border-width: 0; }
.nq-toolbar {
  --background: linear-gradient(180deg, #0A1628 0%, #0E1E38 100%);
  --color: #EDF2FA; --border-width: 0; --min-height: 48px;
}
.nq-menu-btn { --color: rgba(255,255,255,.6); }
.nq-toolbar-title { padding: 0; }
.nq-title-block { display:flex; flex-direction:column; }
.nq-eyebrow { font-size:8px; font-weight:700; text-transform:uppercase; letter-spacing:1.4px; color:rgba(0,212,170,.5); }
.nq-title-text { font-size:17px; font-weight:800; color:#EDF2FA; letter-spacing:-.3px; line-height:1.15; }

.nq-conn {
  display:flex; align-items:center; gap:4px; padding:3px 9px;
  border-radius:99px; font-size:9px; font-weight:800;
  text-transform:uppercase; letter-spacing:.4px; border:1px solid;
}
.nq-conn--on  { background:rgba(0,212,170,.1); border-color:rgba(0,212,170,.3); color:#00D4AA; }
.nq-conn--off { background:rgba(148,163,184,.08); border-color:rgba(148,163,184,.2); color:rgba(255,255,255,.4); }
.nq-conn-dot  { width:5px; height:5px; border-radius:50%; background:currentColor; flex-shrink:0; }
.nq-conn--on .nq-conn-dot { animation:pulse 1.6s ease-in-out infinite; }
.nq-conn-txt  { display:none; }
@media (min-width:380px) { .nq-conn-txt { display:block; } }

.nq-hbtn {
  width:32px; height:32px; border-radius:8px;
  background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1);
  display:flex; align-items:center; justify-content:center; cursor:pointer;
}
.nq-hbtn svg { width:14px; height:14px; stroke:rgba(255,255,255,.65); }
.nq-hbtn:disabled { opacity:.4; cursor:not-allowed; }
.nq-spin { animation:spin .85s linear infinite; }

/* Dark band below toolbar */
.nq-below-bar { background:linear-gradient(180deg,#0E1E38 0%,#0A1628 100%); }

/* Stats strip */
.nq-stats { display:flex; align-items:center; padding:7px 12px 5px; border-bottom:1px solid rgba(255,255,255,.07); }
.nq-stat  { flex:1; display:flex; flex-direction:column; align-items:center; gap:1px;
  background:none; border:none; cursor:pointer; border-radius:6px; padding:4px 2px; transition:background .12s; }
.nq-stat:active, .nq-stat--sel { background:rgba(255,255,255,.06); }
.nq-stat--warn { /* damaged warning state */ }
.nq-sdiv  { width:1px; height:24px; background:rgba(255,255,255,.08); flex-shrink:0; }
.nq-sn    { font-size:20px; font-weight:900; color:#EDF2FA; line-height:1; font-variant-numeric:tabular-nums; letter-spacing:-.5px; }
.nq-sl    { font-size:7.5px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:rgba(255,255,255,.3); white-space:nowrap; }
.nq-sn--blue   { color:#38BDF8; }
.nq-sn--red    { color:#FF6B6B; }
.nq-sn--cyan   { color:#00D4AA; }
.nq-sn--muted  { color:rgba(255,255,255,.3); }
.nq-sn--orange { color:#FF8C42; }

/* Tabs */
.nq-tabs { display:flex; overflow-x:auto; scrollbar-width:none; padding:0 8px; gap:2px; border-bottom:1px solid rgba(255,255,255,.07); }
.nq-tabs::-webkit-scrollbar { display:none; }
.nq-tab  {
  flex-shrink:0; display:flex; align-items:center; gap:5px; padding:9px 10px;
  border:none; background:none; border-bottom:2px solid transparent;
  font-size:11px; font-weight:700; color:rgba(255,255,255,.38); white-space:nowrap;
  cursor:pointer; transition:color .12s, border-color .12s;
}
.nq-tab--on { color:#00D4AA; border-bottom-color:#00D4AA; }
.nq-tab-badge { padding:1px 5px; border-radius:99px; font-size:9px; font-weight:900; min-width:17px; text-align:center; }
.nq-tb--open        { background:rgba(56,189,248,.2);  color:#38BDF8; }
.nq-tb--in-progress { background:rgba(0,212,170,.2);   color:#00D4AA; }
.nq-tb--damaged     { background:rgba(255,140,66,.25); color:#FF8C42; }

/* ═══ LIGHT ZONE — Content ═══════════════════════════════════════════ */
.nq-content {
  --background: linear-gradient(180deg, #EAF0FA 0%, #F2F5FB 40%, #E4EBF7 100%);
  --color: #0F172A;
}
.nq-board { padding-bottom: 8px; }

/* ── Search bar ── */
.nq-search-bar {
  padding:8px 14px 6px; background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border-bottom:1px solid rgba(0,0,0,.06); flex-shrink:0;
}
.nq-search-wrap {
  display:flex; align-items:center; gap:8px;
  background:linear-gradient(145deg,#E8EDF7,#F0F3FA);
  border:1.5px solid rgba(0,0,0,.08); border-radius:10px;
  padding:0 12px; transition:all .2s;
}
.nq-search-wrap--focus {
  border-color:rgba(0,112,224,.35); box-shadow:0 0 0 3px rgba(0,112,224,.08); background:#fff;
}
.nq-search-ic { width:14px; height:14px; flex-shrink:0; stroke:#94A3B8; }
.nq-search-wrap--focus .nq-search-ic { stroke:#0070E0; }
.nq-search-input {
  flex:1; background:transparent; border:none; outline:none;
  font-size:14px; color:#0B1A30; padding:10px 0; min-height:40px;
  font-family:inherit;
}
.nq-search-input::placeholder { color:#94A3B8; font-size:13px; }
.nq-search-clear {
  width:24px; height:24px; display:flex; align-items:center; justify-content:center;
  background:rgba(0,0,0,.06); border:none; border-radius:50%; cursor:pointer; flex-shrink:0;
}
.nq-search-clear svg { width:10px; height:10px; stroke:#64748B; }
.nq-search-meta {
  display:flex; align-items:center; gap:4px; padding:4px 2px 0;
  font-size:10px; font-weight:600; color:#94A3B8;
}
.nq-search-count { color:#0070E0; }

/* Offline / stale banners */
.nq-offline-bar {
  display:flex; align-items:center; gap:7px; padding:8px 14px;
  background:rgba(0,0,0,.06); border-bottom:1px solid rgba(0,0,0,.07);
  font-size:11px; font-weight:600; color:#475569;
}
.nq-offline-bar svg { width:13px; height:13px; stroke:#475569; flex-shrink:0; }
.nq-stale-bar {
  display:flex; align-items:center; gap:7px; padding:7px 14px;
  background:#FFFBEB; border-bottom:1px solid rgba(245,158,11,.2);
  font-size:11px; font-weight:600; color:#92400E;
}
.nq-stale-bar svg { width:12px; height:12px; flex-shrink:0; }
.nq-stale-btn {
  margin-left:auto; font-size:10px; font-weight:800; color:#D97706;
  background:none; border:none; cursor:pointer; text-decoration:underline;
}

/* ── Skeleton ── */
.nq-skels { padding:10px 12px; display:flex; flex-direction:column; gap:6px; }
.nq-sk {
  background:#fff; border-radius:10px; overflow:hidden; padding:12px;
  animation:shimmer 1.2s ease-in-out infinite;
}
.nq-sk-bar { width:100%; height:3px; background:#E2E8F0; border-radius:2px; margin-bottom:10px; }
.nq-sk-body { display:flex; flex-direction:column; gap:7px; }
.nq-sk-r1 { display:flex; gap:6px; }
.nq-sk-pill { height:18px; border-radius:5px; background:#E2E8F0; width:80px; }
.nq-sk-r2 { height:14px; border-radius:4px; background:#EEF2F7; width:65%; }
.nq-sk-r3 { display:flex; gap:5px; }
.nq-sk-chip { height:20px; border-radius:12px; background:#E2E8F0; width:70px; }

/* ════════════════════════════════════════════════════════════════════
   ✦ NOTIFICATIONS CENTER — PREMIUM ALERT CARDS (2026-05-07 v2) ✦
   Light theme. Light navy + teal palette matching the rest of the
   app's chrome. Class names preserved so the template is untouched —
   pure visual refactor. Surfaces:
     • CRITICAL alarm zone   — refined ember-rose card with quiet pulse.
     • Section headers       — uppercase navy label + thin underline + clean
                               count pill in teal.
     • Card                  — white surface, 12px radius, 4px coloured
                               left edge, lifted shadow on hover.
     • Priority badges       — flat tinted pills, AAA contrast.
     • Status badges         — outlined pills matching the priority hue.
     • Chips                 — neutral slate pills + accent variants for
                               temperature / officer / voided primary.
     • CTA buttons           — single solid colour per priority, confident
                               type, generous tap target (44px).
     • Closed section        — collapsed by default; when expanded the
                               cards remain perfectly readable (full
                               opacity, slightly cooler palette).
   ══════════════════════════════════════════════════════════════════ */

/* Layout — list spacing under the stats panel */
.nq-card-wrap   { padding: 4px 12px 6px; }
.nq-closed-list { display: flex; flex-direction: column; gap: 8px; padding: 0 12px 4px; }

/* ── CRITICAL ALARM ZONE ────────────────────────────────────────── */
.nq-alarm-zone {
  margin: 12px 12px 10px;
  border-radius: 14px;
  overflow: hidden;
  background: linear-gradient(180deg, #FFF7F7 0%, #FFEEEE 100%);
  border: 1px solid rgba(220, 38, 38, 0.20);
  box-shadow:
    0 0 0 4px rgba(220, 38, 38, 0.04),
    0 8px 24px -8px rgba(220, 38, 38, 0.18);
  animation: slideUp 0.3s ease;
}
.nq-alarm-hdr {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 14px;
  background: linear-gradient(90deg, rgba(220,38,38,0.08), rgba(220,38,38,0.02));
  border-bottom: 1px solid rgba(220, 38, 38, 0.15);
}
.nq-alarm-pulse {
  width: 10px; height: 10px; border-radius: 50%;
  background: #DC2626; flex-shrink: 0;
  box-shadow: 0 0 0 3px rgba(220,38,38,0.18);
  animation: pulse 1.4s ease-in-out infinite;
}
.nq-alarm-title  { font-size: 12px; font-weight: 800; color: #991B1B; flex: 1; letter-spacing: 0.1px; }
.nq-alarm-oldest { font-size: 10px; font-weight: 700; color: #B91C1C; opacity: 0.85; white-space: nowrap; font-variant-numeric: tabular-nums; }

/* Cards inside the alarm zone — bare items separated by subtle dividers */
.nq-alarm-zone .nq-card {
  border: none !important;
  border-bottom: 1px solid rgba(220, 38, 38, 0.12) !important;
  border-radius: 0 !important;
  background: transparent !important;
  box-shadow: none !important;
  margin: 0 !important;
}
.nq-alarm-zone .nq-card:last-of-type { border-bottom: none !important; }

/* ── SECTION HEADERS ────────────────────────────────────────────── */
.nq-section-hdr {
  display: flex; align-items: center; gap: 8px;
  padding: 18px 16px 8px;
  font-size: 10.5px; font-weight: 800;
  color: #0B2545; text-transform: uppercase; letter-spacing: 0.9px;
}
.nq-section-hdr--sep { margin-top: 4px; }
.nq-section-bar {
  width: 3px; height: 14px; border-radius: 2px; flex-shrink: 0;
}
.nq-section-bar--critical { background: #DC2626; box-shadow: 0 0 6px rgba(220,38,38,0.4); }
.nq-section-bar--high     { background: #D97706; box-shadow: 0 0 6px rgba(217,119,6,0.4); }
.nq-section-bar--normal   { background: #00B4A6; box-shadow: 0 0 6px rgba(0,180,166,0.4); }
.nq-section-bar--progress { background: #0B2545; box-shadow: 0 0 6px rgba(11,37,69,0.4); }
.nq-section-bar--closed   { background: #94A3B8; }
.nq-section-count {
  margin-left: auto;
  font-size: 10px; font-weight: 800;
  color: #0B2545;
  background: rgba(0, 180, 166, 0.10);
  border: 1px solid rgba(0, 180, 166, 0.22);
  padding: 2px 9px;
  border-radius: 10px;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.3px;
}

/* ── CLOSED TOGGLE ───────────────────────────────────────────────
   The closed section is a button to expand / collapse. Uses the same
   visual rhythm as section headers, with a chevron on the right and a
   neutral count pill (the records are de-emphasised, not the toggle). */
.nq-closed-toggle {
  width: 100%;
  display: flex; align-items: center; gap: 8px;
  padding: 18px 16px 8px;
  background: none; border: none; cursor: pointer; text-align: left;
  font-size: 10.5px; font-weight: 800;
  color: #475569; text-transform: uppercase; letter-spacing: 0.9px;
  -webkit-tap-highlight-color: transparent;
}
.nq-closed-toggle:hover .nq-section-count { background: rgba(11,37,69,0.08); }
.nq-toggle-chev {
  width: 13px; height: 13px;
  margin-left: 4px;
  transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1);
  color: #94A3B8;
}
.nq-toggle-chev--open { transform: rotate(180deg); }
.nq-collapse-enter-active { transition: opacity 0.24s ease, transform 0.24s ease; }
.nq-collapse-leave-active { transition: opacity 0.18s ease-in,  transform 0.18s ease-in; }
.nq-collapse-enter-from, .nq-collapse-leave-to { opacity: 0; transform: translateY(-6px); }

/* ── CARD ────────────────────────────────────────────────────────
   The atom of this view. White surface, 12px radius, 4px coloured
   edge stripe (gradient by priority), subtle 1px border + double
   shadow for premium depth. Everything inside the card is type +
   spacing rhythm — no decorative chrome.                          */
.nq-card {
  display: flex; align-items: stretch;
  background: #FFFFFF;
  border: 1px solid rgba(11, 37, 69, 0.08);
  border-radius: 12px;
  overflow: hidden;
  cursor: pointer;
  margin-bottom: 8px;
  box-shadow:
    0 1px 2px rgba(11, 37, 69, 0.04),
    0 4px 14px -8px rgba(11, 37, 69, 0.10);
  transition: transform 0.15s ease, box-shadow 0.18s ease, border-color 0.15s ease;
  animation: slideUp 0.28s ease both;
  -webkit-tap-highlight-color: transparent;
}
.nq-card:hover {
  transform: translateY(-1px);
  border-color: rgba(0, 180, 166, 0.30);
  box-shadow:
    0 1px 3px rgba(11, 37, 69, 0.06),
    0 12px 28px -10px rgba(11, 37, 69, 0.18);
}
.nq-card:active  { transform: scale(0.985); }
.nq-card:focus-visible {
  outline: 2px solid #00B4A6;
  outline-offset: 2px;
}

/* 4 px coloured edge stripe (gradient per priority) */
.nq-card-bar { width: 4px; flex-shrink: 0; }
.nq-bar--critical { background: linear-gradient(180deg, #DC2626 0%, #B91C1C 100%); }
.nq-bar--high     { background: linear-gradient(180deg, #F59E0B 0%, #D97706 100%); }
.nq-bar--normal   { background: linear-gradient(180deg, #00B4A6 0%, #0E7C7B 100%); } /* teal — matches app */
.nq-bar--progress { background: linear-gradient(180deg, #1E40AF 0%, #0B2545 100%); } /* navy — matches app */
.nq-bar--closed   { background: linear-gradient(180deg, #CBD5E1 0%, #94A3B8 100%); }

/* Card body: meta row → name → chips */
.nq-card-body {
  flex: 1;
  padding: 14px 16px;
  min-width: 0;
}
.nq-card-row1 {
  display: flex; align-items: center; gap: 8px;
  margin-bottom: 6px;
  flex-wrap: nowrap;
}
.nq-card-name {
  font-size: 15px; font-weight: 700;
  color: #0B2545;
  letter-spacing: -0.2px;
  margin-bottom: 8px;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.nq-card-name--muted { color: #475569; font-weight: 700; }
.nq-card-age {
  font-size: 11px; font-weight: 600;
  color: #94A3B8;
  margin-left: auto;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.1px;
}
.nq-age--critical { color: #DC2626; font-weight: 800; }

/* ── CHIPS ──────────────────────────────────────────────────────── */
.nq-card-chips { display: flex; flex-wrap: wrap; gap: 5px; }
.nq-chip {
  display: inline-flex; align-items: center;
  font-size: 11px; font-weight: 600;
  color: #475569;
  background: #F1F5F9;
  padding: 3px 9px;
  border-radius: 999px;
  border: 1px solid rgba(11, 37, 69, 0.08);
  white-space: nowrap;
  letter-spacing: 0.1px;
}
.nq-chip--temp        { font-weight: 700; font-variant-numeric: tabular-nums; }
.nq-chip--temp-crit   { background: #FEF2F2; color: #991B1B; border-color: rgba(220,38,38,0.25); }
.nq-chip--temp-high   { background: #FFFBEB; color: #92400E; border-color: rgba(217,119,6,0.25); }
.nq-chip--temp-ok     { background: #F0FDFA; color: #0E7C7B; border-color: rgba(0,180,166,0.30); }
.nq-chip--officer     { background: #EFF6FF; color: #0B2545; border-color: rgba(29,78,216,0.20); font-weight: 700; }
.nq-chip--muted       { background: #F8FAFC; color: #64748B; border-color: rgba(11,37,69,0.06); }
.nq-chip--voided-warn { background: #FFFBEB; color: #92400E; border-color: rgba(217,119,6,0.30); font-weight: 700; }

/* ── PRIORITY BADGES (left-most pill in row1) ──────────────────── */
.nq-pri {
  font-size: 9.5px; font-weight: 900;
  padding: 3px 8px;
  border-radius: 5px;
  border: 1px solid;
  letter-spacing: 0.5px;
  white-space: nowrap;
}
.nq-pri--critical { background: #FEF2F2; color: #991B1B; border-color: rgba(220,38,38,0.30); }
.nq-pri--high     { background: #FFFBEB; color: #92400E; border-color: rgba(217,119,6,0.30); }
.nq-pri--normal   { background: #F0FDFA; color: #0E7C7B; border-color: rgba(0,180,166,0.30); }
.nq-pri--closed   { background: #F8FAFC; color: #94A3B8; border-color: rgba(11,37,69,0.10); }
.nq-pri--sm { font-size: 8.5px; padding: 2px 6px; }

/* ── STATUS BADGES (next to priority) ──────────────────────────── */
.nq-sts {
  font-size: 9px; font-weight: 800;
  padding: 3px 8px;
  border-radius: 5px;
  letter-spacing: 0.4px;
  white-space: nowrap;
  border: 1px solid transparent;
}
.nq-sts--open     { background: rgba(11,37,69,0.06);  color: #0B2545; border-color: rgba(11,37,69,0.12); }
.nq-sts--progress { background: rgba(0,180,166,0.10); color: #0E7C7B; border-color: rgba(0,180,166,0.22); }
.nq-sts--closed   { background: rgba(148,163,184,0.16); color: #475569; border-color: rgba(148,163,184,0.30); }

/* ── CTA ROW (Cancel + Open) ───────────────────────────────────── */
.nq-card-cta {
  display: flex; align-items: center; gap: 8px;
  padding: 0 14px 14px;
  flex-shrink: 0;
}
.nq-cancel-btn {
  padding: 9px 14px; border-radius: 9px;
  font-size: 11.5px; font-weight: 700;
  background: #FFFFFF;
  border: 1.5px solid rgba(11, 37, 69, 0.14);
  color: #475569;
  cursor: pointer; white-space: nowrap;
  min-height: 40px;
  transition: background 0.12s ease, border-color 0.12s ease;
}
.nq-cancel-btn:hover    { background: #F8FAFC; border-color: rgba(11,37,69,0.22); }
.nq-cancel-btn:active   { transform: scale(0.98); }
.nq-cancel-btn:disabled { opacity: 0.5; cursor: not-allowed; }

/* Open / Screen / Continue button — confident solid-colour pill. */
.nq-open-btn {
  display: inline-flex; align-items: center; justify-content: center;
  gap: 6px;
  padding: 10px 16px; border-radius: 10px;
  font-size: 12px; font-weight: 800;
  letter-spacing: 0.2px;
  border: none;
  cursor: pointer;
  white-space: nowrap;
  min-height: 40px; min-width: 100px;
  color: #FFFFFF;
  transition: transform 0.12s ease, box-shadow 0.18s ease, filter 0.12s ease;
}
.nq-open-btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none !important; }
.nq-open-btn svg      { width: 10px; height: 10px; flex-shrink: 0; }
.nq-open-btn:hover    { transform: translateY(-1px); filter: brightness(1.05); }
.nq-open-btn:active   { transform: scale(0.97); }

.nq-open-btn--critical {
  background: linear-gradient(135deg, #DC2626 0%, #991B1B 100%);
  margin: 12px 14px;
  box-shadow:
    0 1px 2px rgba(0,0,0,0.05),
    0 6px 18px -6px rgba(220, 38, 38, 0.55);
}
.nq-open-btn--high {
  background: linear-gradient(135deg, #F59E0B 0%, #B45309 100%);
  box-shadow:
    0 1px 2px rgba(0,0,0,0.05),
    0 6px 18px -6px rgba(217, 119, 6, 0.50);
}
.nq-open-btn--normal {
  background: linear-gradient(135deg, #00B4A6 0%, #0E7C7B 100%);
  box-shadow:
    0 1px 2px rgba(0,0,0,0.05),
    0 6px 18px -6px rgba(0, 180, 166, 0.50);
}
.nq-open-btn--progress {
  background: linear-gradient(135deg, #1E40AF 0%, #0B2545 100%);
  box-shadow:
    0 1px 2px rgba(0,0,0,0.05),
    0 6px 18px -6px rgba(11, 37, 69, 0.55);
}

/* ── CLOSED SECTION CARDS ────────────────────────────────────────
   Closed records remain FULLY READABLE — they are de-emphasised by
   palette, not by opacity. The user explicitly flagged that closed
   cases were hard to see; the new palette uses a soft slate stripe
   + cooler card surface instead of fading the type. */
.nq-card--closed {
  background: linear-gradient(180deg, #FFFFFF 0%, #F8FAFC 100%);
  border-color: rgba(11, 37, 69, 0.10);
}
.nq-card--closed:hover { border-color: rgba(0, 180, 166, 0.20); }
.nq-card--closed .nq-card-name        { color: #0B2545; font-weight: 700; opacity: 0.92; }
.nq-card--closed .nq-card-name--muted { color: #0B2545; opacity: 0.86; }
.nq-card--closed .nq-card-age         { color: #94A3B8; }
.nq-closed-arrow {
  display: flex; align-items: center;
  padding: 0 14px;
  color: #CBD5E1;
  font-size: 22px; font-weight: 200;
  transition: color 0.12s ease, transform 0.12s ease;
}
.nq-card--closed:hover .nq-closed-arrow { color: #00B4A6; transform: translateX(2px); }

/* ── DAMAGED ── */
.nq-quarantine-header {
  display:flex; align-items:center; gap:8px; padding:10px 14px;
  background:#FFF7ED; border-bottom:1px solid rgba(217,119,6,.2);
  font-size:12px; font-weight:700; color:#92400E;
}
.nq-quarantine-header svg { width:13px; height:13px; flex-shrink:0; }

.nq-dmg-card {
  display:flex; margin:4px 12px; background:#fff;
  border:1.5px solid rgba(217,119,6,.2); border-radius:10px; overflow:hidden;
  animation:slideUp .25s ease;
}
.nq-dmg-bar { width:4px; background:linear-gradient(180deg,#D97706,#F59E0B); flex-shrink:0; }
.nq-dmg-body { flex:1; padding:11px 12px; }
.nq-dmg-row1 { display:flex; align-items:center; gap:6px; margin-bottom:5px; }
.nq-dmg-badge { font-size:8px; font-weight:800; color:#DC2626; background:#FFF1F2;
  padding:2px 7px; border-radius:4px; border:1px solid rgba(220,38,38,.2); }
.nq-dmg-att  { font-size:9px; font-weight:700; color:#94A3B8; }
.nq-dmg-age  { font-size:9px; color:#94A3B8; margin-left:auto; }
.nq-dmg-reason { font-size:11px; color:#B45309; font-weight:600; margin-bottom:4px; line-height:1.4; }
.nq-dmg-uuid { font-size:9px; color:#94A3B8; font-variant-numeric:tabular-nums; word-break:break-all; margin-bottom:4px; }
.nq-dmg-meta { font-size:10px; color:#94A3B8; margin-bottom:8px; }
.nq-dmg-actions { display:flex; gap:6px; flex-wrap:wrap; }
.nq-dmg-btn { padding:7px 12px; border-radius:7px; font-size:10px; font-weight:700;
  cursor:pointer; min-height:32px; border:1.5px solid; display:flex; align-items:center; gap:4px; }
.nq-dmg-btn svg { width:11px; height:11px; }
.nq-dmg-btn--retry   { background:#EFF6FF; border-color:rgba(29,78,216,.2); color:#1D4ED8; }
.nq-dmg-btn--open    { background:#F0FDF4; border-color:rgba(22,163,74,.2); color:#166534; }
.nq-dmg-btn--dismiss { background:rgba(0,0,0,.04); border-color:rgba(0,0,0,.1); color:#64748B; }
.nq-dmg-btn:disabled { opacity:.5; cursor:not-allowed; }

/* ── Empty state — premium light theme (2026-05-07 v2) ── */
.nq-empty {
  display: flex; flex-direction: column; align-items: center; gap: 12px;
  padding: 64px 24px;
}
.nq-empty svg     { opacity: 0.30; color: #0B2545; }
.nq-empty-title   { font-size: 15px; font-weight: 800; color: #0B2545; letter-spacing: -0.1px; }
.nq-empty-sub     { font-size: 12px; color: #64748B; text-align: center; line-height: 1.55; max-width: 320px; }
.nq-empty-btn {
  margin-top: 6px;
  padding: 10px 20px; border-radius: 10px;
  background: linear-gradient(135deg, #00B4A6 0%, #0E7C7B 100%);
  border: none;
  color: #FFFFFF;
  font-size: 12px; font-weight: 800; letter-spacing: 0.3px;
  cursor: pointer; min-height: 40px;
  box-shadow:
    0 1px 2px rgba(0,0,0,0.05),
    0 6px 18px -6px rgba(0,180,166,0.45);
  transition: transform 0.12s ease, filter 0.12s ease;
}
.nq-empty-btn:hover  { filter: brightness(1.05); transform: translateY(-1px); }
.nq-empty-btn:active { transform: scale(0.98); }

/* ── Load more — premium light theme ── */
.nq-load-more { display: flex; justify-content: center; padding: 18px 14px; }
.nq-load-btn {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 11px 22px; border-radius: 10px;
  background: #FFFFFF;
  border: 1.5px solid rgba(11, 37, 69, 0.12);
  color: #0B2545;
  font-size: 12px; font-weight: 800; letter-spacing: 0.2px;
  cursor: pointer;
  min-height: 42px;
  box-shadow:
    0 1px 2px rgba(11,37,69,0.04),
    0 4px 12px -4px rgba(11,37,69,0.10);
  transition: transform 0.12s ease, border-color 0.12s ease, box-shadow 0.18s ease;
}
.nq-load-btn:hover {
  transform: translateY(-1px);
  border-color: rgba(0, 180, 166, 0.40);
  box-shadow:
    0 1px 2px rgba(11,37,69,0.04),
    0 8px 18px -6px rgba(0,180,166,0.18);
}
.nq-load-btn:active   { transform: scale(0.98); }
.nq-load-btn svg      { width: 13px; height: 13px; color: #00B4A6; }
.nq-load-btn:disabled { opacity: 0.55; cursor: not-allowed; transform: none; }

/* ═══ MODAL ══════════════════════════════════════════════════════════ */
.nq-modal-toolbar {
  --background: linear-gradient(180deg, #0A1628 0%, #0E1E38 100%);
  --color: #EDF2FA; --border-width: 0;
}
.nq-modal-title { font-size:16px; font-weight:700; color:#EDF2FA; }
.nq-modal-pri-wrap { padding-right:10px; }
.nq-modal-content { --background:#F8FAFC; --color:#0F172A; }

.nq-det-wrap { display:flex; flex-direction:column; padding-bottom:40px; }

.nq-det-banner {
  display:flex; align-items:center; gap:10px; padding:12px 16px;
  position:relative; overflow:hidden;
}
.nq-det-banner-shine { position:absolute; top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.7) 50%,transparent 80%);
  pointer-events:none; }
.nq-det-banner--open        { background:linear-gradient(135deg,#EFF6FF,#DBEAFE); }
.nq-det-banner--in-progress { background:linear-gradient(135deg,#E0F2FE,#BAE6FD); }
.nq-det-banner--closed      { background:linear-gradient(135deg,#F1F5F9,#E2E8F0); }
.nq-det-banner-sts  { font-size:12px; font-weight:900; color:#0F172A; letter-spacing:.3px; }
.nq-det-banner-hint { font-size:11px; color:#64748B; margin-left:4px; }

.nq-det-section { padding:0; }
.nq-det-section-lbl {
  padding:10px 16px 5px;
  font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.8px; color:#94A3B8;
  border-top:1px solid rgba(0,0,0,.05);
}
.nq-det-grid { display:flex; flex-direction:column; }
.nq-drow {
  display:flex; align-items:baseline; justify-content:space-between; gap:12px;
  padding:9px 16px; border-bottom:1px solid rgba(0,0,0,.05);
}
.nq-dk { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
  color:#94A3B8; min-width:90px; flex-shrink:0; }
.nq-dv { font-size:12px; font-weight:600; color:#0F172A; text-align:right; flex:1; }
.nq-dv--mono { font-variant-numeric:tabular-nums; font-size:11px; word-break:break-all; }
.nq-dv--xs   { font-size:10px; }
.nq-dv--critical { color:#DC2626; font-weight:800; }
.nq-dv--high     { color:#D97706; font-weight:800; }
.nq-dv--normal   { color:#16A34A; font-weight:800; }

.nq-det-reason {
  margin:0; padding:10px 16px;
  font-size:12px; color:#475569; line-height:1.6;
  border-top:1px solid rgba(0,0,0,.05);
}
.nq-det-void-warn {
  display:flex; align-items:flex-start; gap:8px; padding:12px 16px;
  background:#FFF7ED; border-top:1px solid rgba(217,119,6,.15);
  border-bottom:1px solid rgba(217,119,6,.15);
  font-size:11px; font-weight:600; color:#92400E; line-height:1.5;
}
.nq-det-void-warn svg { width:14px; height:14px; flex-shrink:0; margin-top:2px; }

.nq-det-actions { display:flex; flex-direction:column; gap:8px; padding:16px; }
.nq-det-screen-btn {
  width:100%; padding:14px; border-radius:10px; border:none; cursor:pointer;
  font-size:14px; font-weight:800; color:#fff; min-height:50px;
  background:linear-gradient(135deg,#15803D,#16A34A);
  box-shadow:0 4px 14px rgba(22,163,74,.3);
  transition:transform .12s, box-shadow .12s;
}
.nq-det-screen-btn--progress {
  background:linear-gradient(135deg,#0369A1,#0284C7);
  box-shadow:0 4px 14px rgba(2,132,199,.3);
}
.nq-det-screen-btn:hover { transform:translateY(-1px); }
.nq-det-cancel-btn {
  width:100%; padding:12px; border-radius:10px; cursor:pointer; min-height:46px;
  background:#FFF1F2; border:1.5px solid rgba(220,38,38,.2);
  color:#DC2626; font-size:13px; font-weight:700;
}
.nq-det-notice {
  display:flex; align-items:flex-start; gap:7px; padding:10px 12px;
  background:rgba(0,0,0,.04); border-radius:8px;
  font-size:11px; color:#64748B; line-height:1.5;
}
.nq-det-notice svg { width:12px; height:12px; flex-shrink:0; margin-top:2px; }
</style>