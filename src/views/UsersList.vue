<template>
  <IonPage>

    <!-- ══════════════════════════════════════════════════════════════
         DARK ZONE — Command-Centre Header
    ══════════════════════════════════════════════════════════════════ -->
    <IonHeader :translucent="false" class="ul-header">

      <!-- IonToolbar: ONLY the main row — back button, title, action buttons -->
      <IonToolbar class="ul-toolbar">
        <div class="ul-toolbar-texture" aria-hidden="true"/>
        <IonButtons slot="start">
          <IonBackButton default-href="/home" text="" style="--color:#00B4FF;" aria-label="Back"/>
        </IonButtons>
        <IonTitle class="ul-toolbar-title-slot">
          <div class="ul-title-block">
            <span class="ul-eyebrow">ECSA-HC · SENTINEL · IAM</span>
            <span class="ul-title">User Command Centre</span>
          </div>
        </IonTitle>
        <IonButtons slot="end" style="gap:6px;padding-right:8px;">
          <div class="ul-net-dot"
            :class="isOnline ? 'ul-net-dot--on' : 'ul-net-dot--off'"
            aria-hidden="true"/>
          <button
            class="ul-icon-btn"
            :class="pendingCount > 0 && 'ul-icon-btn--warn'"
            :disabled="isSyncingAll"
            @click="syncAll"
            aria-label="Sync all pending">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor"
              stroke-width="1.8" stroke-linecap="round"
              :class="isSyncingAll && 'ul-spin'">
              <polyline points="20 4 20 10 14 10"/>
              <path d="M17.5 15a7.5 7.5 0 11-1.8-7.8L20 10"/>
            </svg>
            <span v-if="pendingCount > 0" class="ul-icon-badge">
              {{ fmtBadge(pendingCount) }}
            </span>
          </button>
          <button class="ul-icon-btn ul-icon-btn--blue" @click="openCreate" aria-label="Add user">
            <svg viewBox="0 0 20 20" fill="none" stroke="currentColor"
              stroke-width="1.8" stroke-linecap="round">
              <path d="M16 17v-1a4 4 0 00-4-4H5a4 4 0 00-4 4v1"/>
              <circle cx="8.5" cy="7" r="4"/>
              <line x1="16" y1="8" x2="16" y2="13"/>
              <line x1="18.5" y1="10.5" x2="13.5" y2="10.5"/>
            </svg>
          </button>
        </IonButtons>
      </IonToolbar>

      <!-- ══════════════════════════════════════════════════════
           Dark band: stats / search / chips — outside IonToolbar
           so Ionic does NOT clip the content
      ══════════════════════════════════════════════════════ -->
      <div class="ul-hdr-band">

        <!-- Animated sync status bar -->
        <div class="ul-sync-bar" :class="syncBarCls" role="status" :aria-label="syncBarText">
          <div class="ul-sync-track">
            <div class="ul-sync-fill" :style="{ width: syncPct + '%' }"/>
          </div>
          <div class="ul-sync-row">
            <div class="ul-sync-dot" :class="syncDotCls"/>
            <span class="ul-sync-text">{{ syncBarText }}</span>
            <span v-if="isSyncingAll" class="ul-sync-counter">
              {{ fmt(syncProg.done) }}/{{ fmt(syncProg.total) }}
            </span>
          </div>
        </div>

        <!-- Stats ribbon — 5 KPIs, bright neon accents, compact scaling -->
        <div class="ul-stats-ribbon">
          <div class="ul-stat" :title="fmtFull(totalCount) + ' total users'" aria-label="Total users">
            <span class="ul-stat-n">{{ fmt(totalCount) }}</span>
            <span class="ul-stat-l">Total</span>
          </div>
          <div class="ul-stat-sep"/>
          <div class="ul-stat" :title="fmtFull(syncedCount) + ' uploaded'" aria-label="Uploaded to server">
            <span class="ul-stat-n ul-n--green">{{ fmt(syncedCount) }}</span>
            <span class="ul-stat-l">Uploaded</span>
          </div>
          <div class="ul-stat-sep"/>
          <div class="ul-stat" :title="fmtFull(pendingCount) + ' pending upload'" aria-label="Pending upload">
            <span class="ul-stat-n ul-n--amber">{{ fmt(pendingCount) }}</span>
            <span class="ul-stat-l">Pending</span>
          </div>
          <div class="ul-stat-sep"/>
          <div class="ul-stat" :title="fmtFull(failedCount) + ' server-rejected'" aria-label="Upload queued">
            <span class="ul-stat-n ul-n--red">{{ fmt(failedCount) }}</span>
            <span class="ul-stat-l">Queued</span>
          </div>
          <div class="ul-stat-sep"/>
          <div class="ul-stat" :title="fmtFull(activeCount) + ' active accounts'" aria-label="Active accounts">
            <span class="ul-stat-n ul-n--cyan">{{ fmt(activeCount) }}</span>
            <span class="ul-stat-l">Active</span>
          </div>
        </div>

        <!-- Search -->
        <div class="ul-search-zone">
          <div class="ul-search-bar" :class="searchQ && 'ul-search-bar--active'">
            <svg class="ul-search-ic" viewBox="0 0 18 18" fill="none"
              stroke="#7E92AB" stroke-width="1.6" stroke-linecap="round">
              <circle cx="7.5" cy="7.5" r="5.5"/>
              <line x1="12" y1="12" x2="16" y2="16"/>
            </svg>
            <input
              v-model="searchQ"
              class="ul-search-input"
              placeholder="Search name, username, role, POE, district…"
              aria-label="Search users"
              autocomplete="off"
              @input="onSearchInput"/>
            <button v-if="searchQ" class="ul-search-clear"
              @click="searchQ = ''" aria-label="Clear">✕</button>
          </div>
        </div>

        <!-- Role filter chips -->
        <div class="ul-chip-row" role="toolbar" aria-label="Filter by role">
          <button v-for="c in roleChips" :key="c.value"
            class="ul-chip" :class="activeRole === c.value && 'ul-chip--on'"
            @click="setRole(c.value)" :aria-pressed="activeRole === c.value">
            <span class="ul-chip-dot" :style="{ background: c.dot }"/>
            {{ c.label }}<em>{{ fmt(c.count) }}</em>
          </button>
        </div>

        <!-- Status + POE filter -->
        <div class="ul-filter-row">
          <button v-for="s in statusOpts" :key="s.value"
            class="ul-sts-btn" :class="activeStatus === s.value && 'ul-sts-btn--on'"
            @click="setStatus(s.value)">
            <span class="ul-sts-dot" :class="'ul-sts-dot--' + s.color"/>
            {{ s.label }}
          </button>
          <select v-if="serverPoes.length" v-model="activePoe"
            class="ul-poe-sel" @change="page = 1" aria-label="Filter by POE">
            <option value="">All POEs</option>
            <option v-for="p in serverPoes" :key="p" :value="p">{{ p }}</option>
          </select>
        </div>

      </div><!-- /ul-hdr-band -->

    </IonHeader>

    <!-- ══════════════════════════════════════════════════════════════
         LIGHT ZONE — Content
    ══════════════════════════════════════════════════════════════════ -->
    <IonContent :fullscreen="true" :scroll-y="true"
      style="--background:linear-gradient(180deg,#EAF0FA 0%,#F2F5FB 40%,#E4EBF7 100%);--color:#0B1A30;">
      <IonRefresher slot="fixed"
        @ionRefresh="e => loadUsers().then(() => e.target.complete())">
        <IonRefresherContent refreshing-spinner="crescent"/>
      </IonRefresher>

      <div class="ul-content-wrap">

        <!-- Scope banner -->
        <div v-if="auth && auth.poe_code" class="ul-scope-banner">
          <div class="ul-scope-shine"/>
          <span class="ul-scope-pin">📍</span>
          <span class="ul-scope-text">
            Showing users at <strong>{{ activePoe || auth.poe_code }}</strong>
            <template v-if="auth.district_code"> · {{ auth.district_code }}</template>
          </span>
          <span class="ul-scope-role-tag">{{ (auth.role_key || '').replace(/_/g, ' ') }}</span>
        </div>

        <!-- ── Visualization card ── -->
        <div v-if="totalCount > 0" class="ul-viz-card">
          <div class="ul-viz-card-shine"/>

          <!-- Sync summary row -->
          <div class="ul-viz-summary">
            <div class="ul-viz-summary-cell ul-viz-summary-cell--green" :title="fmtFull(syncedCount) + ' uploaded'">
              <span class="ul-viz-summary-n">{{ fmt(syncedCount) }}</span>
              <span class="ul-viz-summary-l">Uploaded</span>
            </div>
            <div class="ul-viz-summary-sep"/>
            <div class="ul-viz-summary-cell ul-viz-summary-cell--amber" :title="fmtFull(pendingCount) + ' pending'">
              <span class="ul-viz-summary-n">{{ fmt(pendingCount) }}</span>
              <span class="ul-viz-summary-l">Pending</span>
            </div>
            <div class="ul-viz-summary-sep"/>
            <div class="ul-viz-summary-cell ul-viz-summary-cell--red" :title="fmtFull(failedCount) + ' queued'">
              <span class="ul-viz-summary-n">{{ fmt(failedCount) }}</span>
              <span class="ul-viz-summary-l">Queued</span>
            </div>
            <div class="ul-viz-summary-sep"/>
            <div class="ul-viz-summary-cell">
              <span class="ul-viz-summary-n ul-viz-summary-pct">{{ syncPct }}%</span>
              <span class="ul-viz-summary-l">Synced</span>
            </div>
          </div>

          <!-- Role distribution bars -->
          <div class="ul-viz-bars-label">ROLE DISTRIBUTION</div>
          <div class="ul-viz-bars">
            <div v-for="r in roleDistrib" :key="r.key" class="ul-viz-bar-item">
              <div class="ul-viz-bar-header">
                <div class="ul-viz-bar-dot" :style="{ background: r.color }"/>
                <span class="ul-viz-bar-name">{{ r.label }}</span>
                <span class="ul-viz-bar-count">{{ fmt(r.count) }}</span>
                <span class="ul-viz-bar-pct">{{ r.pct }}%</span>
              </div>
              <div class="ul-viz-bar-track">
                <div class="ul-viz-bar-fill"
                  :style="{ width: r.pct + '%', background: r.grad }"/>
              </div>
            </div>
          </div>
        </div>

        <!-- ── Damaged records alert ── -->
        <div v-if="damagedUsers.length > 0" class="ul-damaged-banner">
          <div class="ul-damaged-banner-shine"/>
          <div class="ul-damaged-hdr">
            <span class="ul-damaged-ic">⚠</span>
            <div class="ul-damaged-hdr-body">
              <div class="ul-damaged-title">
                {{ fmt(damagedUsers.length) }} Damaged Record{{ damagedUsers.length > 1 ? 's' : '' }} Detected
              </div>
              <div class="ul-damaged-sub">
                Server rejected these records. Auto-deleted after 3 failed attempts — repair to retry.
              </div>
            </div>
            <button class="ul-damaged-repair-all" @click="repairAll"
              aria-label="Repair all damaged records">
              Repair All
            </button>
          </div>
          <div class="ul-damaged-list">
            <div v-for="d in damagedUsers" :key="d.client_uuid" class="ul-damaged-row">
              <div class="ul-damaged-av" :class="'ul-av--' + roleKey(d.role_key)">
                {{ initials(d.full_name || d.name) }}
              </div>
              <div class="ul-damaged-body">
                <div class="ul-damaged-name">{{ d.full_name || d.name || d.username }}</div>
                <div class="ul-damaged-err">
                  {{ d.last_sync_error || 'Server rejected — unknown error' }}
                </div>
                <div class="ul-damaged-att">
                  {{ d.sync_attempt_count || 0 }} attempt{{ (d.sync_attempt_count || 0) !== 1 ? 's' : '' }}
                </div>
              </div>
              <button class="ul-damaged-retry" @click="repairOne(d)" aria-label="Retry upload">↻</button>
            </div>
          </div>
        </div>

        <!-- ── Results bar ── -->
        <div class="ul-results-bar">
          <span class="ul-results-count"
            :title="'Showing ' + fmtFull(displayedUsers.length) + ' of ' + fmtFull(filteredUsers.length) + ' users'">
            <strong>{{ fmt(displayedUsers.length) }}</strong>
            <span v-if="filteredUsers.length !== totalCount"> of {{ fmt(filteredUsers.length) }}</span>
            user{{ filteredUsers.length !== 1 ? 's' : '' }}
            <span v-if="showDmgOnly" class="ul-results-dmg-tag">⚠ damaged only</span>
          </span>
          <div class="ul-results-right">
            <button v-if="pendingCount > 0" class="ul-results-sync"
              @click="syncAll" :disabled="isSyncingAll">
              {{ isSyncingAll ? 'Syncing…' : 'Upload ' + fmt(pendingCount) }}
            </button>
            <button v-if="hasActiveFilters" class="ul-results-clear"
              @click="clearFilters">
              Clear
            </button>
          </div>
        </div>

        <!-- ── Loading skeletons ── -->
        <template v-if="loading">
          <div v-for="i in 5" :key="i" class="ul-skel"
            :style="{ animationDelay: i * 80 + 'ms' }">
            <div class="ul-skel-bar"/>
            <div class="ul-skel-body">
              <div class="ul-skel-av"/>
              <div class="ul-skel-lines">
                <div class="ul-skel-line" style="width:58%"/>
                <div class="ul-skel-line" style="width:36%;height:9px;margin-top:5px"/>
                <div style="display:flex;gap:6px;margin-top:8px">
                  <div class="ul-skel-line" style="width:80px;height:20px;border-radius:6px"/>
                  <div class="ul-skel-line" style="width:60px;height:20px;border-radius:6px"/>
                </div>
              </div>
              <div class="ul-skel-pill"/>
            </div>
          </div>
        </template>

        <!-- ── Empty state ── -->
        <div v-else-if="!loading && filteredUsers.length === 0" class="ul-empty">
          <div class="ul-empty-icon" aria-hidden="true">
            <svg viewBox="0 0 64 64" fill="none" stroke="#94A3B8"
              stroke-width="1.5" stroke-linecap="round">
              <path d="M48 56v-4a12 12 0 00-12-12H16a12 12 0 00-12 12v4"/>
              <circle cx="26" cy="20" r="12"/>
              <path d="M56 56v-4a12 12 0 00-8-11.3"/>
              <path d="M40 8a12 12 0 010 23.2"/>
            </svg>
          </div>
          <div class="ul-empty-title">
            {{ searchQ ? 'No matches found' : 'No users in this scope' }}
          </div>
          <div class="ul-empty-sub">
            {{ searchQ
              ? 'Try a different search or clear the filters.'
              : 'Add the first officer for this Point of Entry.' }}
          </div>
          <button class="ul-empty-cta" @click="openCreate" aria-label="Add first user">
            Add User
          </button>
        </div>

        <!-- ══ USER CARDS ══════════════════════════════════════════ -->
        <template v-else>
          <div v-for="(user, idx) in displayedUsers" :key="user.client_uuid"
            class="ul-card"
            :class="[
              'ul-card--' + roleKey(user.role_key),
              !user.is_active && 'ul-card--inactive',
              isExpanded(user) && 'ul-card--expanded',
              isDamaged(user) && 'ul-card--damaged',
            ]"
            :style="{ animationDelay: (idx % 20) * 28 + 'ms' }">

            <!-- Premium effects -->
            <div class="ul-card-shine" aria-hidden="true"/>
            <div class="ul-card-stream" aria-hidden="true"/>
            <div class="ul-card-bar" :class="'ul-card-bar--' + roleKey(user.role_key)"
              aria-hidden="true"/>

            <!-- ── Main row ── -->
            <div class="ul-card-main" @click="toggleExpand(user)"
              role="button" tabindex="0"
              :aria-label="(user.full_name || user.name) + ', ' + roleLabel(user.role_key)"
              @keydown.enter="toggleExpand(user)"
              @keydown.space.prevent="toggleExpand(user)">

              <!-- Avatar with live dot -->
              <div class="ul-av" :class="'ul-av--' + roleKey(user.role_key)">
                <span class="ul-av-initials">{{ initials(user.full_name || user.name) }}</span>
                <div class="ul-av-dot"
                  :class="user.is_active ? 'ul-av-dot--on' : 'ul-av-dot--off'"
                  :aria-label="user.is_active ? 'Active' : 'Inactive'"/>
              </div>

              <!-- Info -->
              <div class="ul-card-info">
                <div class="ul-card-name">
                  {{ user.full_name || user.name || '—' }}
                  <span v-if="!user.is_active" class="ul-tag-inactive">INACTIVE</span>
                  <span v-if="isDamaged(user)" class="ul-tag-damaged">⚠ ERROR</span>
                </div>
                <div class="ul-card-meta">
                  @{{ user.username }}<template v-if="user.id"> · #{{ user.id }}</template>
                </div>
                <div class="ul-card-tags">
                  <span class="ul-role-badge" :class="'ul-rb--' + roleKey(user.role_key)">
                    {{ roleLabel(user.role_key) }}
                  </span>
                  <span v-if="user.poe_code || user.assignment && user.assignment.poe_code"
                    class="ul-poe-tag">
                    📍 {{ user.poe_code || (user.assignment && user.assignment.poe_code) }}
                  </span>
                </div>
              </div>

              <!-- Right: sync pill + menu -->
              <div class="ul-card-right">
                <div class="ul-sync-pill" :class="'ul-sp--' + syncSt(user)">
                  <div class="ul-sp-dot" :class="'ul-sp-dot--' + syncSt(user)"/>
                  {{ SYNC.LABELS[user.sync_status] || user.sync_status }}
                </div>
                <button class="ul-menu-btn" @click.stop="openActionSheet(user)"
                  :aria-label="'Actions for ' + (user.full_name || user.name)">
                  <svg viewBox="0 0 16 16" fill="#94A3B8">
                    <circle cx="8" cy="3" r="1.3"/>
                    <circle cx="8" cy="8" r="1.3"/>
                    <circle cx="8" cy="13" r="1.3"/>
                  </svg>
                </button>
              </div>
            </div>

            <!-- ── Expanded details ── -->
            <transition name="ul-expand">
              <div v-if="isExpanded(user)" class="ul-det">

                <!-- Identity section -->
                <div class="ul-det-section">
                  <div class="ul-det-hdr">IDENTITY</div>
                  <div class="ul-det-grid">
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Server ID</span>
                      <code class="ul-det-v ul-dv--id">{{ user.id || 'Not synced' }}</code>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Username</span>
                      <code class="ul-det-v">{{ user.username || '—' }}</code>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Email</span>
                      <span class="ul-det-v ul-dv--sm">{{ user.email || '—' }}</span>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Phone</span>
                      <span class="ul-det-v">{{ user.phone || '—' }}</span>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Status</span>
                      <span class="ul-det-v"
                        :class="user.is_active ? 'ul-dv--active' : 'ul-dv--inactive'">
                        {{ user.is_active ? 'ACTIVE' : 'INACTIVE' }}
                      </span>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Country</span>
                      <span class="ul-det-v">{{ user.country_code || 'UG' }} 🇺🇬</span>
                    </div>
                  </div>
                </div>

                <!-- Assignment section -->
                <div class="ul-det-section">
                  <div class="ul-det-hdr">GEOGRAPHIC ASSIGNMENT</div>
                  <div class="ul-det-grid">
                    <div class="ul-det-cell">
                      <span class="ul-det-k">PHEOC</span>
                      <span class="ul-det-v">
                        {{ (user.assignment && user.assignment.pheoc_code) || user.pheoc_code || '—' }}
                      </span>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Region</span>
                      <span class="ul-det-v">
                        {{ (user.assignment && user.assignment.province_code) || user.province_code || '—' }}
                      </span>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">District</span>
                      <span class="ul-det-v">
                        {{ (user.assignment && user.assignment.district_code) || user.district_code || '—' }}
                      </span>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">POE</span>
                      <span class="ul-det-v">
                        {{ (user.assignment && user.assignment.poe_code) || user.poe_code || '—' }}
                      </span>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Is Primary</span>
                      <span class="ul-det-v">
                        {{ (user.assignment && user.assignment.is_primary) ? 'Yes' : 'No' }}
                      </span>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Assign Active</span>
                      <span class="ul-det-v">
                        {{ (user.assignment && user.assignment.is_active) ? 'Yes' : 'No' }}
                      </span>
                    </div>
                  </div>
                </div>

                <!-- Audit section -->
                <div class="ul-det-section">
                  <div class="ul-det-hdr">AUDIT & SYNC</div>
                  <div class="ul-det-grid">
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Created</span>
                      <span class="ul-det-v ul-dv--sm">{{ fmtDate(user.created_at) || '—' }}</span>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Updated</span>
                      <span class="ul-det-v ul-dv--sm">{{ fmtDate(user.updated_at) || '—' }}</span>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Last Login</span>
                      <span class="ul-det-v ul-dv--sm">{{ fmtDate(user.last_login_at) || 'Never' }}</span>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Synced At</span>
                      <span class="ul-det-v ul-dv--sm">{{ fmtDate(user.synced_at) || '—' }}</span>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Attempts</span>
                      <span class="ul-det-v"
                        :class="(user.sync_attempt_count || 0) >= MAX_SYNC_ATTEMPTS && 'ul-dv--warn'">
                        {{ user.sync_attempt_count || 0 }}
                      </span>
                    </div>
                    <div class="ul-det-cell">
                      <span class="ul-det-k">Record v</span>
                      <code class="ul-det-v">v{{ user.record_version || 1 }}</code>
                    </div>
                  </div>
                  <div v-if="user.last_sync_error" class="ul-det-error">
                    <span>⚠</span>
                    <span class="ul-det-error-txt">{{ user.last_sync_error }}</span>
                  </div>
                  <div class="ul-det-cell" style="margin-top:6px;padding:0">
                    <span class="ul-det-k">Client UUID</span>
                    <code class="ul-det-v ul-dv--uuid">{{ user.client_uuid }}</code>
                  </div>
                </div>

                <!-- Quick actions -->
                <div class="ul-det-actions">
                  <button class="ul-det-btn ul-det-btn--edit" @click.stop="openEdit(user)"
                    aria-label="Edit user">
                    <svg viewBox="0 0 14 14" fill="none" stroke="currentColor"
                      stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                      <path d="M9.5 2l2.5 2.5L4 13 1 14l1-3z"/>
                    </svg>
                    Edit
                  </button>
                  <button class="ul-det-btn"
                    :class="user.is_active ? 'ul-det-btn--deact' : 'ul-det-btn--act'"
                    @click.stop="toggleStatus(user)"
                    :aria-label="user.is_active ? 'Deactivate user' : 'Activate user'">
                    {{ user.is_active ? 'Deactivate' : 'Activate' }}
                  </button>
                  <button v-if="user.sync_status !== SYNC.SYNCED"
                    class="ul-det-btn ul-det-btn--sync"
                    :class="activeSyncSet.has(user.client_uuid) && 'ul-det-btn--syncing'"
                    @click.stop="syncOne(user)"
                    :disabled="activeSyncSet.has(user.client_uuid)"
                    aria-label="Upload user to server">
                    <svg viewBox="0 0 14 14" fill="none" stroke="currentColor"
                      stroke-width="1.8" stroke-linecap="round"
                      :class="activeSyncSet.has(user.client_uuid) && 'ul-spin'"
                      aria-hidden="true">
                      <polyline points="13 2 13 6 9 6"/>
                      <path d="M11.5 9a5 5 0 11-1.2-4.8L13 6"/>
                    </svg>
                    {{ activeSyncSet.has(user.client_uuid) ? 'Uploading…' : 'Upload' }}
                  </button>
                  <button v-if="isDamaged(user)"
                    class="ul-det-btn ul-det-btn--repair"
                    @click.stop="repairOne(user)"
                    aria-label="Repair damaged record">
                    🔧 Repair
                  </button>
                </div>

              </div>
            </transition>
          </div>

          <!-- Infinite scroll for millions of records -->
          <IonInfiniteScroll
            :disabled="displayedUsers.length >= filteredUsers.length"
            @ionInfinite="loadMore">
            <IonInfiniteScrollContent
              loading-spinner="crescent"
              loading-text="Loading more users…"/>
          </IonInfiniteScroll>

          <div v-if="displayedUsers.length >= filteredUsers.length && filteredUsers.length > 0"
            class="ul-list-end">
            ✓ All {{ fmt(filteredUsers.length) }} users loaded
          </div>
        </template>

        <div style="height:max(env(safe-area-inset-bottom,0px),100px)" aria-hidden="true"/>
      </div>
    </IonContent>

    <!-- ══════════════════════════════════════════════════════════════
         SPEED-DIAL FAB
    ══════════════════════════════════════════════════════════════════ -->
    <div class="ul-fab-wrap">
      <transition name="ul-fab-t">
        <div v-if="fabOpen" class="ul-speed-dial">
          <button class="ul-fab-mini ul-fm--sync"
            @click="syncAll; fabOpen = false"
            :disabled="pendingCount === 0"
            aria-label="Sync all pending">
            <svg viewBox="0 0 14 14" fill="none" stroke="currentColor"
              stroke-width="1.8" stroke-linecap="round">
              <polyline points="13 2 13 6 9 6"/>
              <path d="M11.5 9a5 5 0 11-1.2-4.8L13 6"/>
            </svg>
            Sync ({{ fmt(pendingCount) }})
          </button>
          <button class="ul-fab-mini ul-fm--inactive"
            @click="setStatus('0'); fabOpen = false"
            aria-label="Show inactive users">
            <svg viewBox="0 0 14 14" fill="none" stroke="currentColor"
              stroke-width="1.8" stroke-linecap="round">
              <path d="M2 4h10M4 7h6M6 10h2"/>
            </svg>
            Inactive
          </button>
          <button class="ul-fab-mini ul-fm--damaged"
            @click="showDmgOnly = !showDmgOnly; page = 1; fabOpen = false"
            aria-label="Toggle damaged filter">
            ⚠ Damaged ({{ fmt(damagedUsers.length) }})
          </button>
        </div>
      </transition>
      <button class="ul-fab"
        @click="fabOpen ? openCreate() : (fabOpen = true)"
        aria-label="Add user or expand menu">
        <svg viewBox="0 0 20 20" fill="none" stroke="#fff"
          stroke-width="2.2" stroke-linecap="round"
          :style="{ transform: fabOpen ? 'rotate(135deg)' : 'rotate(0deg)',
                    transition: 'transform .25s cubic-bezier(.16,1,.3,1)' }">
          <line x1="10" y1="4" x2="10" y2="16"/>
          <line x1="4" y1="10" x2="16" y2="10"/>
        </svg>
      </button>
    </div>

    <!-- ══════════════════════════════════════════════════════════════
         CREATE / EDIT MODAL — Full-screen premium
    ══════════════════════════════════════════════════════════════════ -->
    <IonModal :is-open="showForm" :breakpoints="[0,1]" :initial-breakpoint="1"
      @ionModalDidDismiss="closeForm">
      <IonHeader :translucent="false">
        <IonToolbar
          style="--background:linear-gradient(180deg,#070E1B,#0E1A2E);--color:#EDF2FA;--border-width:0;">
          <IonButtons slot="start">
            <IonButton @click="closeForm" style="--color:#00B4FF;" aria-label="Close">
              <IonIcon :icon="closeOutline"/>
            </IonButton>
          </IonButtons>
          <IonTitle>
            <div class="ul-form-title-block">
              <span class="ul-form-eyebrow">
                {{ editingUser ? 'EDIT USER ACCOUNT' : 'NEW USER ACCOUNT' }}
              </span>
              <span class="ul-form-title-name">
                {{ editingUser ? (editingUser.full_name || editingUser.name) : 'Add Officer' }}
              </span>
            </div>
          </IonTitle>
          <div slot="end" v-if="form.role_key"
            class="ul-form-hdr-role" :class="'ul-rb--' + roleKey(form.role_key)">
            {{ roleLabel(form.role_key) }}
          </div>
        </IonToolbar>
      </IonHeader>
      <IonContent :scroll-y="true"
        style="--background:linear-gradient(180deg,#EEF2FA 0%,#FFFFFF 50%,#F4F7FC 100%);--color:#0B1A30;">
        <div class="ul-form-wrap">

          <div v-if="formError" class="ul-form-alert ul-form-alert--err">
            <span>⚠</span> {{ formError }}
          </div>
          <div v-if="formSuccess" class="ul-form-alert ul-form-alert--ok">
            <span>✓</span> {{ formSuccess }}
          </div>

          <!-- ── Section 1: Identity ── -->
          <div class="ul-fsec">
            <div class="ul-fsec-label">
              <div class="ul-fsec-glyph">👤</div>IDENTITY
            </div>
            <div class="ul-fg" :class="errors.full_name && 'ul-fg--err'">
              <label class="ul-fl">Full Name <span class="ul-req">*</span></label>
              <input v-model="form.full_name" class="ul-fi"
                placeholder="AYEBARE TIMOTHY KAMUKAMA" maxlength="150" aria-required="true"/>
              <span v-if="errors.full_name" class="ul-ferr">{{ errors.full_name }}</span>
            </div>
            <div class="ul-fg" :class="errors.username && 'ul-fg--err'">
              <label class="ul-fl">Username <span class="ul-req">*</span></label>
              <div style="display:flex">
                <span class="ul-fi-prefix">@</span>
                <input v-model="form.username" class="ul-fi ul-fi--pfx"
                  placeholder="ayebare.t" maxlength="80" autocomplete="off" aria-required="true"/>
              </div>
              <span v-if="errors.username" class="ul-ferr">{{ errors.username }}</span>
            </div>
            <div class="ul-fg" :class="errors.email && 'ul-fg--err'">
              <label class="ul-fl">Email</label>
              <input v-model="form.email" type="email" class="ul-fi"
                placeholder="officer@moh.go.ug" maxlength="190"/>
              <span v-if="errors.email" class="ul-ferr">{{ errors.email }}</span>
            </div>
            <div class="ul-fg">
              <label class="ul-fl">Phone</label>
              <input v-model="form.phone" class="ul-fi"
                placeholder="25678… (no + prefix)" maxlength="40"/>
            </div>
            <div class="ul-fg" :class="errors.password && 'ul-fg--err'">
              <label class="ul-fl">
                Password
                <span v-if="!editingUser" class="ul-req">*</span>
                <span v-else style="color:#94A3B8;font-weight:400"> (blank to keep)</span>
              </label>
              <div style="position:relative">
                <input v-model="form.password"
                  :type="showPw ? 'text' : 'password'"
                  class="ul-fi" style="padding-right:44px"
                  placeholder="Min 8 characters"
                  autocomplete="new-password"/>
                <button class="ul-pw-toggle" @click="showPw = !showPw"
                  type="button" :aria-label="showPw ? 'Hide password' : 'Show password'">
                  <svg v-if="!showPw" viewBox="0 0 16 16" fill="none"
                    stroke="#94A3B8" stroke-width="1.6" stroke-linecap="round">
                    <path d="M1 8s3-6 7-6 7 6 7 6-3 6-7 6-7-6-7-6z"/>
                    <circle cx="8" cy="8" r="2.5"/>
                  </svg>
                  <svg v-else viewBox="0 0 16 16" fill="none"
                    stroke="#94A3B8" stroke-width="1.6" stroke-linecap="round">
                    <path d="M13 6c.6.8 1 1.5 1 2s-3 6-7 6S1 9 1 8"/>
                    <path d="M13 3L3 13"/>
                  </svg>
                </button>
              </div>
              <span v-if="errors.password" class="ul-ferr">{{ errors.password }}</span>
            </div>
          </div>

          <!-- ── Section 2: Role & Status ── -->
          <div class="ul-fsec">
            <div class="ul-fsec-label">
              <div class="ul-fsec-glyph">🎖</div>ROLE & STATUS
            </div>
            <div class="ul-fg" :class="errors.role_key && 'ul-fg--err'">
              <label class="ul-fl">Role <span class="ul-req">*</span></label>
              <div class="ul-role-grid">
                <button v-for="r in ROLES" :key="r.value"
                  class="ul-role-opt"
                  :class="['ul-ro--' + roleKey(r.value),
                           form.role_key === r.value && 'ul-ro--active']"
                  @click="form.role_key = r.value; form.assignment.poe_code = ''"
                  type="button" :aria-pressed="form.role_key === r.value">
                  <span class="ul-ro-dot" :class="'ul-rod--' + roleKey(r.value)"/>
                  <span class="ul-ro-label">{{ r.label }}</span>
                  <span class="ul-ro-scope">{{ r.scope }}</span>
                </button>
              </div>
              <span v-if="errors.role_key" class="ul-ferr">{{ errors.role_key }}</span>
            </div>
            <div class="ul-fg">
              <label class="ul-fl">Account Status</label>
              <div class="ul-sts-toggle">
                <button class="ul-sts-tbn"
                  :class="form.is_active && 'ul-sts-tbn--active'"
                  @click="form.is_active = true" type="button">
                  <span class="ul-stsdot ul-stsdot--green"/>Active
                </button>
                <button class="ul-sts-tbn"
                  :class="!form.is_active && 'ul-sts-tbn--inactive'"
                  @click="form.is_active = false" type="button">
                  <span class="ul-stsdot ul-stsdot--grey"/>Inactive
                </button>
              </div>
            </div>
          </div>

          <!-- ── Section 3: Geographic Assignment ── -->
          <div class="ul-fsec">
            <div class="ul-fsec-label">
              <div class="ul-fsec-glyph">📍</div>GEOGRAPHIC ASSIGNMENT
            </div>
            <div class="ul-fg" :class="errors['assignment.province_code'] && 'ul-fg--err'">
              <label class="ul-fl">
                Provincial PHEOC
                <span v-if="geoRequired('province_or_pheoc')" class="ul-req">*</span>
              </label>
              <SearchableSelect
                v-model="form.assignment.province_code"
                :options="PHEOC_LIST.map(p => ({ value: p, label: p }))"
                placeholder="— Select PHEOC —"
                search-placeholder="Search PHEOC…"
                aria-label="Select PHEOC"
                select-class="ul-fsel"
                @change="onPheocChange"
              />
              <span v-if="errors['assignment.province_code']" class="ul-ferr">
                {{ errors['assignment.province_code'] }}
              </span>
            </div>
            <div v-if="geoRequired('district_code')" class="ul-fg"
              :class="errors['assignment.district_code'] && 'ul-fg--err'">
              <label class="ul-fl">
                District
                <span class="ul-req">*</span>
              </label>
              <SearchableSelect
                v-model="form.assignment.district_code"
                :options="filteredDistricts.map(d => ({ value: d, label: d }))"
                placeholder="— Select District —"
                search-placeholder="Search districts…"
                aria-label="Select district"
                select-class="ul-fsel"
                @change="onDistrictPickWithRegionBackfill"
              />
              <span v-if="errors['assignment.district_code']" class="ul-ferr">
                {{ errors['assignment.district_code'] }}
              </span>
            </div>
            <div v-if="form.assignment.district_code && geoRequired('poe_code')"
              class="ul-fg" :class="errors['assignment.poe_code'] && 'ul-fg--err'">
              <label class="ul-fl">Point of Entry <span class="ul-req">*</span></label>
              <SearchableSelect
                v-model="form.assignment.poe_code"
                :options="filteredPoes.map(p => ({ value: p, label: p }))"
                placeholder="— Select POE —"
                search-placeholder="Search POEs…"
                aria-label="Select POE"
                select-class="ul-fsel"
              />
              <span v-if="errors['assignment.poe_code']" class="ul-ferr">
                {{ errors['assignment.poe_code'] }}
              </span>
            </div>
            <div class="ul-geo-note" :class="'ul-geo-note--' + roleKey(form.role_key)">
              <span>ℹ</span> {{ geoScopeNote(form.role_key) }}
            </div>
          </div>

          <button class="ul-submit-btn"
            :class="formSubmitting && 'ul-submit-btn--loading'"
            @click="submitForm" :disabled="formSubmitting"
            aria-label="Save user">
            <svg v-if="!formSubmitting" viewBox="0 0 16 16" fill="none"
              stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
              <path d="M8 2v8M4 6l4 4 4-4"/>
              <path d="M2 13h12"/>
            </svg>
            <svg v-else viewBox="0 0 16 16" fill="none"
              stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
              class="ul-spin">
              <polyline points="15 3 15 7 11 7"/>
              <path d="M13 10a5 5 0 11-1.4-5.8L15 7"/>
            </svg>
            {{ formSubmitting ? 'Saving…' : (editingUser ? 'Update User' : 'Create User') }}
          </button>
          <div style="height:32px"/>
        </div>
      </IonContent>
    </IonModal>

    <!-- ══════════════════════════════════════════════════════════════
         ACTION SHEET MODAL
    ══════════════════════════════════════════════════════════════════ -->
    <IonModal :is-open="showActions && !!actionUser"
      :breakpoints="[0,1]" :initial-breakpoint="1"
      @ionModalDidDismiss="showActions = false; actionUser = null">
      <IonContent
        style="--background:linear-gradient(180deg,#EEF2FA,#FFFFFF,#F4F7FC);--color:#0B1A30;"
        v-if="actionUser">
        <div class="ul-as-wrap">
          <div class="ul-as-handle" aria-hidden="true"/>

          <!-- User identity header -->
          <div class="ul-as-user-hdr">
            <div class="ul-av ul-av-lg"
              :class="'ul-av--' + roleKey(actionUser.role_key)">
              {{ initials(actionUser.full_name || actionUser.name) }}
            </div>
            <div class="ul-as-user-info">
              <div class="ul-as-user-name">
                {{ actionUser.full_name || actionUser.name }}
              </div>
              <div class="ul-as-user-meta">
                @{{ actionUser.username }} · {{ roleLabel(actionUser.role_key) }}
              </div>
            </div>
            <div class="ul-sync-pill" :class="'ul-sp--' + syncSt(actionUser)">
              <div class="ul-sp-dot" :class="'ul-sp-dot--' + syncSt(actionUser)"/>
              {{ SYNC.LABELS[actionUser.sync_status] }}
            </div>
          </div>

          <!-- Actions -->
          <div class="ul-as-actions">
            <button class="ul-as-action"
              @click="openEdit(actionUser); showActions = false"
              aria-label="Edit profile">
              <div class="ul-as-ic ul-as-ic--blue">
                <svg viewBox="0 0 16 16" fill="none" stroke="#0070E0"
                  stroke-width="1.8" stroke-linecap="round">
                  <path d="M9.5 2l2.5 2.5L4 13 1 14l1-3z"/>
                </svg>
              </div>
              <div class="ul-as-body">
                <span class="ul-as-lbl">Edit Profile</span>
                <span class="ul-as-sub">Update name, email, phone, role, assignment</span>
              </div>
              <span class="ul-as-arr">›</span>
            </button>

            <button class="ul-as-action"
              @click="toggleStatus(actionUser); showActions = false"
              :aria-label="actionUser.is_active ? 'Deactivate account' : 'Activate account'">
              <div class="ul-as-ic"
                :class="actionUser.is_active ? 'ul-as-ic--red' : 'ul-as-ic--green'">
                <svg viewBox="0 0 16 16" fill="none"
                  :stroke="actionUser.is_active ? '#E02050' : '#00A86B'"
                  stroke-width="1.8" stroke-linecap="round">
                  <template v-if="actionUser.is_active">
                    <path d="M8 1a7 7 0 100 14A7 7 0 008 1z"/>
                    <path d="M5 5l6 6M11 5l-6 6"/>
                  </template>
                  <template v-else>
                    <path d="M5 8l2 2 4-4M14 8A6 6 0 112 8a6 6 0 0112 0z"/>
                  </template>
                </svg>
              </div>
              <div class="ul-as-body">
                <span class="ul-as-lbl"
                  :class="actionUser.is_active ? 'ul-as-lbl--red' : 'ul-as-lbl--green'">
                  {{ actionUser.is_active ? 'Deactivate Account' : 'Activate Account' }}
                </span>
                <span class="ul-as-sub">
                  {{ actionUser.is_active
                    ? 'Disable login access immediately'
                    : 'Restore login access' }}
                </span>
              </div>
              <span class="ul-as-arr">›</span>
            </button>

            <button v-if="actionUser.sync_status !== SYNC.SYNCED"
              class="ul-as-action"
              @click="syncOne(actionUser); showActions = false"
              aria-label="Upload to server">
              <div class="ul-as-ic ul-as-ic--green">
                <svg viewBox="0 0 16 16" fill="none" stroke="#00A86B"
                  stroke-width="1.8" stroke-linecap="round">
                  <polyline points="15 3 15 7 11 7"/>
                  <path d="M13 10a5 5 0 11-1.4-5.8L15 7"/>
                </svg>
              </div>
              <div class="ul-as-body">
                <span class="ul-as-lbl">Upload to Server</span>
                <span class="ul-as-sub">
                  Sync this record now ·
                  {{ actionUser.sync_attempt_count || 0 }} previous attempts
                </span>
              </div>
              <span class="ul-as-arr">›</span>
            </button>

            <button v-if="isDamaged(actionUser)"
              class="ul-as-action"
              @click="repairOne(actionUser); showActions = false"
              aria-label="Repair damaged record">
              <div class="ul-as-ic ul-as-ic--amber">
                <span style="font-size:20px">🔧</span>
              </div>
              <div class="ul-as-body">
                <span class="ul-as-lbl ul-as-lbl--amber">Repair Record</span>
                <span class="ul-as-sub">Reset sync state and retry upload</span>
              </div>
              <span class="ul-as-arr">›</span>
            </button>
          </div>

          <button class="ul-as-cancel" @click="showActions = false" aria-label="Cancel">
            Cancel
          </button>
        </div>
      </IonContent>
    </IonModal>

    <IonToast
      :is-open="toast.show"
      :message="toast.msg"
      :duration="2800"
      :color="toast.color"
      position="top"
      @didDismiss="toast.show = false"/>

  </IonPage>
</template>

<script setup>
/**
 * UsersList.vue — User Management Command Centre
 *
 * ARCHITECTURE LAWS (never violate):
 *   LAW 1 : Navigate & PATCH with server integer id — never client_uuid
 *   LAW 2 : No Authorization header — API is open by design
 *   LAW 3 : Every IDB record carries `id` as server integer
 *   LAW 4 : IDB key for server-confirmed users = srv-{id} (deterministic)
 *   LAW 5 : Increment sync_attempt_count BEFORE fetch (crash-safe)
 *   LAW 6 : 5xx/429 → UNSYNCED (retryable). 4xx → FAILED (human review)
 *   LAW 7 : Damaged = FAILED OR (attempt_count > 3 AND UNSYNCED)
 *   LAW 8 : repairOne resets to UNSYNCED + clears attempt_count + error
 *   LAW 9 : IonInfiniteScroll, PAGE_SIZE = 50 (millions-ready)
 *   LAW 10: activeSyncSet (reactive Set proxy) — concurrent guard per UUID
 */
import { ref, computed, reactive, onMounted, onUnmounted, toRaw } from 'vue'
import SearchableSelect from '../components/SearchableSelect.vue'
import {
  IonPage, IonHeader, IonToolbar, IonTitle, IonContent, IonButtons,
  IonBackButton, IonButton, IonIcon, IonModal, IonRefresher,
  IonRefresherContent, IonToast,
  IonInfiniteScroll, IonInfiniteScrollContent,
  onIonViewDidEnter,
} from '@ionic/vue'
import { closeOutline } from 'ionicons/icons'
import {
  dbPut, dbGet, dbGetAll, safeDbPut, dbDelete,
  genUUID, isoNow,
  STORE, SYNC, APP,
} from '@/services/poeDB'

// ── REFERENCE DATA from window.POE_MAIN (POEs.js loader) ─────────────────
// These dropdown feeds are REACTIVE — they re-compute whenever the loader
// dispatches `poe-main-updated` (which fires after a successful fetch of
// /api/poes/bundle or an admin CRUD in POEs.vue).
const _poeMainBump = ref(0)
function _bumpPoeMain () { _poeMainBump.value++ }
if (typeof window !== 'undefined') {
  window.addEventListener('poe-main-updated', _bumpPoeMain)
}

const POE_DATA = computed(() => {
  // eslint-disable-next-line no-unused-expressions
  _poeMainBump.value    // reactivity anchor
  return window.POE_MAIN || { administrative_groups: [], poes: [] }
})

// Province / PHEOC list — alphabetical, dedup, filtered to Uganda.
const PHEOC_LIST = computed(() => {
  const list = []
  for (const g of (POE_DATA.value.administrative_groups || [])) {
    if (g.country === 'Uganda' && g.admin_level_1 && !list.includes(g.admin_level_1)) {
      list.push(g.admin_level_1)
    }
  }
  return list.sort((a, b) => a.localeCompare(b))
})

// PHEOC → [district_name, …]
const PHEOC_DISTRICT_MAP = computed(() => {
  const map = {}
  for (const g of (POE_DATA.value.administrative_groups || [])) {
    if (g.country === 'Uganda' && g.admin_level_1) {
      map[g.admin_level_1] = g.districts || []
    }
  }
  return map
})

// District → [poe_name, …]
const DISTRICT_POE_MAP = computed(() => {
  const map = {}
  for (const p of (POE_DATA.value.poes || [])) {
    if (p.country !== 'Uganda' || !p.district) continue
    if (!map[p.district]) map[p.district] = []
    map[p.district].push(p.poe_name)
  }
  return map
})

// Server-accepted role_keys — must match UserController::VALID_ROLES exactly.
// If you add a role here, add it on the server too (and vice-versa).
const ROLES = [
  { value: 'SCREENER',            label: 'Screener',             scope: 'POE — primary & secondary screening' },
  { value: 'POE_PRIMARY',         label: 'POE Primary Officer',  scope: 'POE — primary screening lane' },
  { value: 'POE_SECONDARY',       label: 'POE Secondary Officer',scope: 'POE — secondary screening lane' },
  { value: 'POE_DATA_OFFICER',    label: 'POE Data Officer',     scope: 'POE — aggregated reporting' },
  { value: 'POE_ADMIN',           label: 'POE Admin',            scope: 'POE — manages users at this POE' },
  { value: 'DISTRICT_SUPERVISOR', label: 'District Supervisor',  scope: 'District — monitors all POEs in district' },
  { value: 'PHEOC_OFFICER',       label: 'PHEOC Officer',        scope: 'Regional PHEOC — monitors all districts' },
  { value: 'NATIONAL_ADMIN',      label: 'National Admin',       scope: 'National — full system access' },
]

// Geographic requirements per role
const ROLE_GEO = {
  SCREENER:            ['province_or_pheoc', 'district_code', 'poe_code'],
  POE_PRIMARY:         ['province_or_pheoc', 'district_code', 'poe_code'],
  POE_SECONDARY:       ['province_or_pheoc', 'district_code', 'poe_code'],
  POE_DATA_OFFICER:    ['province_or_pheoc', 'district_code', 'poe_code'],
  POE_ADMIN:           ['province_or_pheoc', 'district_code', 'poe_code'],
  DISTRICT_SUPERVISOR: ['province_or_pheoc', 'district_code'],
  PHEOC_OFFICER:       ['province_or_pheoc'],
  NATIONAL_ADMIN:      [],
}

// Tenant country code — sourced from VITE_COUNTRY_CODE so this view works
// unchanged when copied into the UG / RW / ECSA forks.
const TENANT_CC = (typeof window !== 'undefined' && window.COUNTRY_CODE) || 'UG'

const PAGE_SIZE         = 50
const MAX_SYNC_ATTEMPTS = 3   // auto-delete after this many failed server attempts

// ── REACTIVE SYNC SET (concurrent guard, reactive) ────────────────────────
// Using a reactive wrapper so templates can read .has() reactively
const _syncSet = reactive({ keys: new Set() })
const activeSyncSet = {
  has:    (k) => _syncSet.keys.has(k),
  add:    (k) => { _syncSet.keys = new Set(_syncSet.keys); _syncSet.keys.add(k) },
  delete: (k) => { _syncSet.keys = new Set(_syncSet.keys); _syncSet.keys.delete(k) },
}

// ── STATE ─────────────────────────────────────────────────────────────────
const auth          = ref(null)
const allUsers      = ref([])
const loading       = ref(false)
const searchQ       = ref('')
const activeRole    = ref('')
const activeStatus  = ref('')
const activePoe     = ref('')
const serverPoes    = ref([])
const expandedUUID  = ref(null)
const isOnline      = ref(navigator.onLine)
const fabOpen       = ref(false)
const showDmgOnly   = ref(false)
const page          = ref(1)
const isSyncingAll  = ref(false)
const syncProg      = ref({ done: 0, total: 0 })

// 2026-05-07 FIX — in-memory password buffer for sync retries.
// The IDB record never persists plaintext passwords (security). When a
// fresh-user create POST hits a transient failure ("Saved offline"), the
// background syncOne retry uses buildPayload() which has no password →
// server returns 422 "password required" → record auto-deletes after
// MAX_SYNC_ATTEMPTS. This buffer keeps the plaintext password in memory
// (NOT in IDB) keyed by client_uuid, scoped to this session. submitUser
// writes here on initial submit; buildPayload reads from here on retry.
// Cleared on:
//   - successful sync (no longer needed)
//   - page reload (process death — explicit re-entry required)
//   - explicit user logout
const _pendingPasswords = new Map()  // client_uuid → plaintext password

// modals
const showForm    = ref(false)
const showActions = ref(false)
const editingUser = ref(null)
const actionUser  = ref(null)

// form
const FORM_DEF = () => ({
  full_name: '', username: '', email: '', phone: '', password: '',
  role_key: 'SCREENER', is_active: true, country_code: TENANT_CC,
  assignment: {
    country_code:  TENANT_CC,
    province_code: '',
    pheoc_code:    '',   // must match province_code (Provincial PHEOC name)
    district_code: '',
    poe_code:      '',
    is_primary:    true,
    is_active:     true,
  },
})
const form           = ref(FORM_DEF())
const errors         = ref({})
const formError      = ref('')
const formSuccess    = ref('')
const formSubmitting = ref(false)
const showPw         = ref(false)
const toast          = ref({ show: false, msg: '', color: 'success' })

let syncTimer  = null
let searchTimer = null

// ── AUTH ──────────────────────────────────────────────────────────────────
function getAuth() {
  try { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') }
  catch { return null }
}

// ── COMPUTED — counts ─────────────────────────────────────────────────────
const totalCount   = computed(() => allUsers.value.length)
const syncedCount  = computed(() => allUsers.value.filter(u => u.sync_status === SYNC.SYNCED).length)
const pendingCount = computed(() => allUsers.value.filter(u => u.sync_status === SYNC.UNSYNCED).length)
const failedCount  = computed(() => allUsers.value.filter(u => u.sync_status === SYNC.FAILED).length)
const activeCount  = computed(() => allUsers.value.filter(u => u.is_active).length)
const syncPct      = computed(() =>
  totalCount.value ? Math.round((syncedCount.value / totalCount.value) * 100) : 100)

// ── COMPUTED — damaged records (LAW 7) ────────────────────────────────────
const damagedUsers = computed(() =>
  allUsers.value.filter(u =>
    u.sync_status === SYNC.FAILED ||
    ((u.sync_attempt_count || 0) >= MAX_SYNC_ATTEMPTS && u.sync_status === SYNC.UNSYNCED)
  )
)

// ── COMPUTED — filtering (millions-safe, no allItems.filter spam) ─────────
const filteredUsers = computed(() => {
  let list = showDmgOnly.value ? damagedUsers.value : allUsers.value
  const q  = searchQ.value.trim().toLowerCase()
  if (q) list = list.filter(u =>
    (u.full_name   || '').toLowerCase().includes(q) ||
    (u.name        || '').toLowerCase().includes(q) ||
    (u.username    || '').toLowerCase().includes(q) ||
    (u.email       || '').toLowerCase().includes(q) ||
    (u.role_key    || '').toLowerCase().includes(q) ||
    (u.poe_code    || '').toLowerCase().includes(q) ||
    ((u.assignment && u.assignment.poe_code)      || '').toLowerCase().includes(q) ||
    ((u.assignment && u.assignment.district_code) || '').toLowerCase().includes(q)
  )
  if (activeRole.value)       list = list.filter(u => u.role_key === activeRole.value)
  if (activeStatus.value === '1') list = list.filter(u =>  u.is_active)
  if (activeStatus.value === '0') list = list.filter(u => !u.is_active)
  if (activePoe.value)        list = list.filter(u =>
    (u.poe_code || (u.assignment && u.assignment.poe_code)) === activePoe.value)
  return list
})

// Paginated slice for display — LAW 9
const displayedUsers = computed(() => filteredUsers.value.slice(0, page.value * PAGE_SIZE))

const hasActiveFilters = computed(() =>
  !!(activeRole.value || activeStatus.value || activePoe.value ||
     searchQ.value.trim() || showDmgOnly.value)
)

// ── COMPUTED — role chips (with live counts) ──────────────────────────────
const roleChips = computed(() => {
  const d   = allUsers.value
  const cnt = (rk) => d.filter(u => u.role_key === rk).length
  return [
    { value:'',                   label:'All',        dot:'#EDF2FA', count:d.length, countFmt:fmt(d.length) },
    { value:'SCREENER',           label:'Screener',   dot:'#00A86B', count:cnt('SCREENER') },
    { value:'DISTRICT_SUPERVISOR',label:'District',   dot:'#E02050', count:cnt('DISTRICT_SUPERVISOR') },
    { value:'PHEOC_OFFICER',      label:'PHEOC',      dot:'#7B40D8', count:cnt('PHEOC_OFFICER') },
    { value:'NATIONAL_ADMIN',     label:'National',   dot:'#D63384', count:cnt('NATIONAL_ADMIN') },
  ]
})

const statusOpts = [
  { value:'',  label:'All',      color:'all' },
  { value:'1', label:'Active',   color:'active' },
  { value:'0', label:'Inactive', color:'inactive' },
]

// ── COMPUTED — role distribution visualization ────────────────────────────
const roleDistrib = computed(() => {
  const total = totalCount.value || 1
  return [
    { key:'SCREENER',            label:'Screener',            color:'#00A86B', grad:'linear-gradient(90deg,#007A50,#00A86B)' },
    { key:'DISTRICT_SUPERVISOR', label:'District Supervisor', color:'#E02050', grad:'linear-gradient(90deg,#B01840,#E02050)' },
    { key:'PHEOC_OFFICER',       label:'PHEOC Officer',       color:'#7B40D8', grad:'linear-gradient(90deg,#5A20B0,#7B40D8)' },
    { key:'NATIONAL_ADMIN',      label:'National Admin',      color:'#D63384', grad:'linear-gradient(90deg,#A0205E,#D63384)' },
  ]
  .map(r => ({
    ...r,
    count: allUsers.value.filter(u => u.role_key === r.key).length,
    pct:   Math.round((allUsers.value.filter(u => u.role_key === r.key).length / total) * 100),
  }))
  .filter(r => r.count > 0)
})

// ── COMPUTED — sync bar ───────────────────────────────────────────────────
const syncBarCls = computed(() => {
  if (isSyncingAll.value)    return 'ul-sync-bar--syncing'
  if (failedCount.value > 0) return 'ul-sync-bar--failed'
  if (pendingCount.value > 0)return 'ul-sync-bar--pending'
  return 'ul-sync-bar--ok'
})
const syncDotCls = computed(() => {
  if (isSyncingAll.value)    return 'ul-sdot--syncing'
  if (failedCount.value > 0) return 'ul-sdot--failed'
  if (pendingCount.value > 0)return 'ul-sdot--pending'
  return 'ul-sdot--ok'
})
const syncBarText = computed(() => {
  if (loading.value)         return 'Loading users…'
  if (isSyncingAll.value)    return `Uploading… ${syncProg.value.done}/${syncProg.value.total}`
  if (failedCount.value > 0 && pendingCount.value > 0)
    return `${fmtSyncText(pendingCount.value)} pending · ${fmtSyncText(failedCount.value)} server-rejected`
  if (failedCount.value > 0) return `${fmtSyncText(failedCount.value)} record${failedCount.value > 1 ? 's' : ''} rejected — tap ⚠ Repair All`
  if (pendingCount.value > 0)return `${fmtSyncText(pendingCount.value)} record${pendingCount.value > 1 ? 's' : ''} pending upload`
  if (!totalCount.value)     return 'No users in this scope'
  return `All ${fmtSyncText(totalCount.value)} users uploaded · ${syncPct.value}% synced`
})

// ── COMPUTED — form ───────────────────────────────────────────────────────
// Note: PHEOC_DISTRICT_MAP / DISTRICT_POE_MAP are computed refs (reactive
// to poe-main-updated), so we unwrap them with .value inside these getters.
// 2026-05-19 — District field UX hardening. The user complained that the
// District dropdown's search "doesn't work". Root cause: the field was
// hidden entirely until a Region (Province) was first selected, and the
// SearchableSelect z-index bug (fixed separately) made the search input
// invisible even after a region pick. We now allow direct search across
// ALL districts in the tenant country, AND keep the cascade-filter when a
// Region is already chosen. On district pick we back-fill the region so
// the form scope remains consistent.
const allDistrictsFlat = computed(() => {
  const seen = new Set()
  const out = []
  for (const region of Object.keys(PHEOC_DISTRICT_MAP.value)) {
    for (const d of (PHEOC_DISTRICT_MAP.value[region] || [])) {
      if (!d || seen.has(d)) continue
      seen.add(d); out.push(d)
    }
  }
  return out.sort((a, b) => a.localeCompare(b))
})

// district → region lookup, so picking a district can back-fill the region.
const districtToRegionMap = computed(() => {
  const map = {}
  for (const region of Object.keys(PHEOC_DISTRICT_MAP.value)) {
    for (const d of (PHEOC_DISTRICT_MAP.value[region] || [])) {
      if (d && !map[d]) map[d] = region
    }
  }
  return map
})

const filteredDistricts = computed(() => {
  const p = form.value.assignment.province_code
  // When a region is picked, scope to its districts.
  // Otherwise present the full flat list so the user can search any district
  // directly and have the region auto-populated on selection.
  return p ? (PHEOC_DISTRICT_MAP.value[p] || []) : allDistrictsFlat.value
})
const filteredPoes = computed(() => {
  const d = form.value.assignment.district_code
  return d ? (DISTRICT_POE_MAP.value[d] || []) : []
})

// ── LIFECYCLE ─────────────────────────────────────────────────────────────
onMounted(async () => {
  auth.value = getAuth()
  window.addEventListener('online',  onOnline)
  window.addEventListener('offline', onOffline)
  await loadUsers()
  if (pendingCount.value > 0 && isOnline.value) syncAll()
})
onIonViewDidEnter(async () => {
  auth.value = getAuth()
  await loadUsers()
})
onUnmounted(() => {
  clearTimeout(syncTimer)
  clearTimeout(searchTimer)
  window.removeEventListener('online',  onOnline)
  window.removeEventListener('offline', onOffline)
})

function onOnline()  { isOnline.value = true;  if (pendingCount.value > 0) syncAll() }
function onOffline() { isOnline.value = false }

// ── LOAD USERS (offline-first, dedup by server integer id) ───────────────
async function loadUsers() {
  loading.value = true
  auth.value    = getAuth()

  // 1. Fire-and-forget server fetch — cache results to IDB
  if (isOnline.value) {
    try {
      const params = new URLSearchParams()
      // user_id is required for the server-side scope guard — without it,
      // UserController@index falls through to a country-wide listing and
      // PHEOC/DISTRICT/POE_* callers see every user in the country.
      if (auth.value && auth.value.id) params.set('user_id', String(auth.value.id))
      if (auth.value && auth.value.poe_code)
        params.set('poe_code', activePoe.value || auth.value.poe_code)
      if (activeRole.value) params.set('role_key', activeRole.value)

      const ctrl = new AbortController()
      const tid  = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS)
      const res  = await fetch(`${window.SERVER_URL}/users?${params}`, {
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        signal:  ctrl.signal,
      })
      clearTimeout(tid)
      if (res.ok) {
        const body  = await res.json()
        const items = body?.data?.items ?? body?.data ?? []
        await cacheUsersLocally(items)
      }
    } catch (_) { /* offline — fall through to IDB */ }
  }

  // 2. Read IDB → deduplicate by server integer id (LAW 3 + LAW 4)
  const raw  = await dbGetAll(STORE.USERS_LOCAL)
  const byId = new Map()   // server-id → best record (highest record_version)
  const noId = []          // pending local creates (no server id yet)

  for (const r of raw) {
    const sid  = r.id ?? r.server_user_id ?? r.server_id ?? null
    const norm = { ...r, id: sid ? Number(sid) : null }
    if (norm.id && Number.isInteger(norm.id) && norm.id > 0) {
      const prev = byId.get(norm.id)
      if (!prev || (norm.record_version ?? 0) >= (prev.record_version ?? 0))
        byId.set(norm.id, norm)
    } else {
      noId.push(norm)
    }
  }

  const sortDesc = (a, b) => (b.created_at || '').localeCompare(a.created_at || '')
  allUsers.value = [
    ...Array.from(byId.values()).sort(sortDesc),
    ...noId.sort(sortDesc),
  ]

  // Extract unique POEs from server-confirmed records only
  const poeSet = new Set()
  byId.forEach(u => {
    const p = u.poe_code || (u.assignment && u.assignment.poe_code)
    if (p) poeSet.add(p)
  })
  serverPoes.value = Array.from(poeSet).sort()

  page.value    = 1
  loading.value = false
}

// Cache server-returned items to IDB (LAW 4: deterministic key)
async function cacheUsersLocally(items) {
  for (const u of items) {
    if (!u.id) continue
    const idbKey   = `srv-${u.id}`   // LAW 4
    const existing = await dbGet(STORE.USERS_LOCAL, idbKey).catch(() => null)
    await dbPut(STORE.USERS_LOCAL, {
      client_uuid:        idbKey,
      id:                 u.id,       // LAW 3: server integer as id
      role_key:           u.role_key,
      country_code:       u.country_code,
      full_name:          u.full_name,
      name:               u.full_name,
      username:           u.username,
      username_ci:        (u.username || '').toLowerCase(),
      email:              u.email,
      email_ci:           (u.email    || '').toLowerCase(),
      phone:              u.phone,
      is_active:          !!u.is_active,
      last_login_at:      u.last_login_at,
      created_at:         u.created_at,
      updated_at:         u.updated_at,
      poe_code:           u.assignment?.poe_code      ?? null,
      district_code:      u.assignment?.district_code ?? null,
      province_code:      u.assignment?.province_code ?? null,
      pheoc_code:         u.assignment?.pheoc_code    ?? null,
      assignment:         u.assignment ?? null,
      sync_status:        SYNC.SYNCED,
      synced_at:          isoNow(),
      sync_attempt_count: 0,
      last_sync_error:    null,
      record_version:     (existing?.record_version ?? 0) + 1,
    })
  }
}

// ── INFINITE SCROLL — LAW 9 ───────────────────────────────────────────────
function loadMore(event) {
  page.value++
  event.target.complete()
}

// ── SEARCH ────────────────────────────────────────────────────────────────
function onSearchInput() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1 }, 280)
}

// ── FILTERS ───────────────────────────────────────────────────────────────
function setRole(v)   { activeRole.value = v;   page.value = 1 }
function setStatus(v) { activeStatus.value = v; page.value = 1 }
function clearFilters() {
  activeRole.value = ''; activeStatus.value = ''; activePoe.value = ''
  searchQ.value = ''; showDmgOnly.value = false; page.value = 1
}

// ── EXPAND ────────────────────────────────────────────────────────────────
function toggleExpand(user) {
  expandedUUID.value = isExpanded(user) ? null : user.client_uuid
}
function isExpanded(user) { return expandedUUID.value === user.client_uuid }

// ── DAMAGED RECORD DETECTION — LAW 7 ─────────────────────────────────────
function isDamaged(user) {
  return user.sync_status === SYNC.FAILED ||
    ((user.sync_attempt_count || 0) >= MAX_SYNC_ATTEMPTS && user.sync_status === SYNC.UNSYNCED)
}

// ── REPAIR ONE (LAW 8) ────────────────────────────────────────────────────
async function repairOne(user) {
  // Reset to UNSYNCED with 0 attempts — gives the record MAX_SYNC_ATTEMPTS fresh tries
  const repaired = {
    ...user,
    sync_status:        SYNC.UNSYNCED,
    sync_attempt_count: 0,
    last_sync_error:    null,
    record_version:     (user.record_version || 1) + 1,
    updated_at:         isoNow(),
  }
  await safeDbPut(STORE.USERS_LOCAL, repaired)
  await loadUsers()
  showToast(`Record repaired — ${MAX_SYNC_ATTEMPTS} attempts remaining before auto-delete.`, 'warning')
  if (isOnline.value) syncOne(repaired)
}

async function repairAll() {
  const damaged = damagedUsers.value.slice()
  for (const u of damaged) await repairOne(u)
  showToast(`${fmtSyncText(damaged.length)} record${damaged.length > 1 ? 's' : ''} repaired.`, 'success')
}

// ── SYNC ENGINE ───────────────────────────────────────────────────────────
// Per-record concurrent guard (activeSyncSet). Crash-safe increment (LAW 5).
// 5xx/429 → UNSYNCED. 4xx → FAILED. Network error → UNSYNCED (LAW 6).
async function syncOne(user) {
  const uuid = user.client_uuid
  if (activeSyncSet.has(uuid)) return false
  activeSyncSet.add(uuid)

  try {
    const record = await dbGet(STORE.USERS_LOCAL, uuid)
    if (!record || record.sync_status === SYNC.SYNCED) return true

    const isUpdate = Number.isInteger(Number(record.id)) && Number(record.id) > 0

    // FIX 2026-05-07: a NEW-user create requires a password. If the in-memory
    // buffer doesn't have one (page reloaded between submit and retry), don't
    // burn an attempt. Mark the record so the officer can re-enter the
    // password via the edit form, and stop the retry loop.
    if (!isUpdate && !_pendingPasswords.has(uuid)) {
      const cur = await dbGet(STORE.USERS_LOCAL, uuid).catch(() => null)
      if (cur) {
        await safeDbPut(STORE.USERS_LOCAL, {
          ...cur,
          sync_status:     SYNC.FAILED,
          last_sync_error: 'Password not in session memory. Open the user record and re-enter the password to retry upload.',
          record_version:  (cur.record_version || 1) + 1,
          updated_at:      isoNow(),
        })
        await loadUsers()
      }
      return false
    }

    const url    = isUpdate
      ? `${window.SERVER_URL}/users/${record.id}`   // LAW 1: integer id in URL
      : `${window.SERVER_URL}/users`
    const method = isUpdate ? 'PATCH' : 'POST'

    // LAW 5: increment attempt BEFORE fetch
    const working = {
      ...record,
      sync_attempt_count: (record.sync_attempt_count || 0) + 1,
      record_version:     (record.record_version     || 1) + 1,
      updated_at:          isoNow(),
    }
    await safeDbPut(STORE.USERS_LOCAL, working)

    const ctrl = new AbortController()
    const tid  = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS)
    let res
    try {
      res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body:    JSON.stringify(buildPayload(working)),
        signal:  ctrl.signal,
      })
    } finally { clearTimeout(tid) }

    if (res.ok) {
      const body     = await res.json()
      const serverId = body?.data?.id ?? null
      await safeDbPut(STORE.USERS_LOCAL, {
        ...working,
        id:                 serverId ?? working.id,
        sync_status:        SYNC.SYNCED,
        synced_at:          isoNow(),
        last_sync_error:    null,
        record_version:     (working.record_version || 1) + 1,
        updated_at:         isoNow(),
      })
      // FIX 2026-05-07: drop the plaintext password from the in-memory
      // buffer once the record is uploaded — no longer needed.
      _pendingPasswords.delete(uuid)
      await loadUsers()
      showToast('User uploaded successfully.', 'success')
      return true
    }

    // LAW 6: 5xx/429 retryable → UNSYNCED; 4xx non-retryable → FAILED
    const retryable = res.status >= 500 || res.status === 429
    const errBody   = await res.json().catch(() => ({}))
    const errMsg    = errBody?.message ||
      (errBody?.errors
        ? Object.values(errBody.errors).flat().join(' ')
        : `HTTP ${res.status}`)

    const newAttemptCount = working.sync_attempt_count || 1

    // AUTO-DELETE: if server permanently rejected (4xx) AND at/over attempt limit
    if (!retryable && newAttemptCount >= MAX_SYNC_ATTEMPTS) {
      await dbDelete(STORE.USERS_LOCAL, uuid)
      await loadUsers()
      showToast(
        `Record auto-removed after ${MAX_SYNC_ATTEMPTS} failed uploads. Server: ${errMsg}`,
        'danger'
      )
      return false
    }

    await safeDbPut(STORE.USERS_LOCAL, {
      ...working,
      sync_status:     retryable ? SYNC.UNSYNCED : SYNC.FAILED,   // LAW 6
      last_sync_error: errMsg,
      record_version:  (working.record_version || 1) + 1,
    })
    if (retryable) scheduleRetry()
    await loadUsers()
    showToast(retryable
      ? 'Server error — will retry automatically.'
      : `Upload rejected (attempt ${newAttemptCount}/${MAX_SYNC_ATTEMPTS}): ${errMsg}`, 'danger')
    return false

  } catch (_) {
    // Network / AbortError → always UNSYNCED (never FAILED)
    const latest = await dbGet(STORE.USERS_LOCAL, uuid).catch(() => null)
    if (latest) {
      await safeDbPut(STORE.USERS_LOCAL, {
        ...latest,
        sync_status:        SYNC.UNSYNCED,
        sync_attempt_count: (latest.sync_attempt_count || 0) + 1,
        record_version:     (latest.record_version     || 1) + 1,
        updated_at:          isoNow(),
      })
    }
    scheduleRetry()
    return false
  } finally {
    activeSyncSet.delete(uuid)
  }
}

// Exact API payload shape — matches UserController@store/update + assignment
//
// 2026-05-07 FIX: include the plaintext password from the in-memory buffer
// when this is a NEW-user create (no integer id yet). Without it, server
// returns 422 "password required" and the record auto-deletes after
// MAX_SYNC_ATTEMPTS. Buffer is process-life only — never persisted to IDB.
function buildPayload(r) {
  const isNewUser = !(Number.isInteger(Number(r.id)) && Number(r.id) > 0)
  const payload = {
    client_uuid:  r.client_uuid,
    full_name:    r.full_name || r.name,
    username:     r.username,
    email:        r.email        || null,
    phone:        r.phone        || null,
    role_key:     r.role_key,
    country_code: r.country_code || TENANT_CC,
    is_active:    r.is_active ? 1 : 0,
    assignment: {
      country_code:  r.assignment?.country_code  || r.country_code  || TENANT_CC,
      province_code: r.assignment?.province_code || r.province_code || null,
      pheoc_code:    r.assignment?.pheoc_code    || r.pheoc_code    || null,
      district_code: r.assignment?.district_code || r.district_code || null,
      poe_code:      r.assignment?.poe_code      || r.poe_code      || null,
      is_primary:    1,
      is_active:     1,
    },
  }
  if (isNewUser && _pendingPasswords.has(r.client_uuid)) {
    payload.password = _pendingPasswords.get(r.client_uuid)
  }
  return payload
}

async function syncAll() {
  if (isSyncingAll.value) return
  const pending = allUsers.value.filter(u => u.sync_status !== SYNC.SYNCED)
  if (!pending.length) return
  isSyncingAll.value  = true
  syncProg.value      = { done: 0, total: pending.length }
  for (const u of pending) {
    await syncOne(u)
    syncProg.value.done++
  }
  isSyncingAll.value = false
}

function scheduleRetry() {
  clearTimeout(syncTimer)
  syncTimer = setTimeout(() => {
    if (isOnline.value && pendingCount.value > 0) syncAll()
    else if (pendingCount.value > 0) scheduleRetry()
  }, APP.SYNC_RETRY_MS)
}

// ── TOGGLE STATUS ─────────────────────────────────────────────────────────
async function toggleStatus(user) {
  const newActive = !user.is_active
  const updated   = {
    ...user,
    is_active:          newActive,
    updated_at:          isoNow(),
    sync_status:         SYNC.UNSYNCED,
    record_version:     (user.record_version || 1) + 1,
  }
  await safeDbPut(STORE.USERS_LOCAL, updated)
  await loadUsers()

  if (!Number.isInteger(Number(user.id)) || Number(user.id) <= 0) {
    showToast('Status updated offline. Will sync when server available.', 'warning')
    return
  }
  try {
    const res = await fetch(`${window.SERVER_URL}/users/${user.id}/status`, {
      method:  'PATCH',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body:    JSON.stringify({
        is_active:   newActive ? 1 : 0,
        client_uuid: user.client_uuid,
        user_id:     auth.value?.id ?? null,   // for server-side hierarchy guard
      }),
    })
    if (res.ok) {
      await safeDbPut(STORE.USERS_LOCAL, {
        ...updated,
        sync_status:    SYNC.SYNCED,
        synced_at:      isoNow(),
        last_sync_error:null,
        record_version: (updated.record_version || 1) + 1,
      })
      showToast(newActive ? 'User activated.' : 'User deactivated.', 'success')
      await loadUsers()
    } else {
      showToast('Status saved offline — server update failed.', 'warning')
    }
  } catch {
    showToast('Status saved offline.', 'warning')
  }
}

// ── PULL TO REFRESH ───────────────────────────────────────────────────────
async function onRefresh(ev) { await loadUsers(); ev.target.complete() }

// ── MODALS ────────────────────────────────────────────────────────────────
function openCreate() {
  editingUser.value = null; form.value = FORM_DEF()
  errors.value = {}; formError.value = ''; formSuccess.value = ''
  showPw.value = false; showForm.value = true; fabOpen.value = false
}
function openEdit(user) {
  editingUser.value = user
  const assignProvince = (user.assignment && user.assignment.province_code) || user.province_code || ''
  const assignPheoc    = (user.assignment && user.assignment.pheoc_code)    || user.pheoc_code    || assignProvince
  form.value = {
    full_name:    user.full_name || user.name || '',
    username:     user.username  || '',
    email:        user.email     || '',
    phone:        user.phone     || '',
    password:     '',
    role_key:     user.role_key  || 'SCREENER',
    is_active:    !!user.is_active,
    country_code: user.country_code || TENANT_CC,
    assignment: {
      country_code:  (user.assignment && user.assignment.country_code)  || TENANT_CC,
      province_code: assignProvince,
      pheoc_code:    assignPheoc,
      district_code: (user.assignment && user.assignment.district_code) || user.district_code || '',
      poe_code:      (user.assignment && user.assignment.poe_code)      || user.poe_code      || '',
      is_primary:    (user.assignment && user.assignment.is_primary != null) ? user.assignment.is_primary : true,
      is_active:     (user.assignment && user.assignment.is_active  != null) ? user.assignment.is_active  : true,
    },
  }
  errors.value = {}; formError.value = ''; formSuccess.value = ''
  showPw.value = false; showForm.value = true
}
function closeForm() { showForm.value = false; editingUser.value = null }

function openActionSheet(user) { actionUser.value = user; showActions.value = true }

// ── FORM VALIDATION ───────────────────────────────────────────────────────
function geoRequired(field) {
  const reqs = ROLE_GEO[form.value.role_key] || []
  return reqs.includes(field)
}

function validateForm() {
  const e = {}
  if (!form.value.full_name?.trim() || form.value.full_name.trim().length < 2)
    e.full_name = 'Full name required (min 2 characters)'
  if (!form.value.username?.trim() || form.value.username.trim().length < 4)
    e.username = 'Username required (min 4 characters)'
  if (form.value.username && !/^[a-zA-Z0-9._-]+$/.test(form.value.username))
    e.username = 'Only letters, numbers, dots, underscores, hyphens'
  if (!form.value.role_key)
    e.role_key = 'Role is required'
  if (!editingUser.value && (!form.value.password || form.value.password.length < 8))
    e.password = 'Password required (min 8 characters)'
  if (editingUser.value && form.value.password && form.value.password.length < 8)
    e.password = 'Password must be at least 8 characters'
  if (form.value.email && !/^\S+@\S+\.\S+$/.test(form.value.email))
    e.email = 'Invalid email format'
  if (geoRequired('province_or_pheoc') && !form.value.assignment.province_code)
    e['assignment.province_code'] = 'Provincial PHEOC required for this role'
  if (geoRequired('district_code') && !form.value.assignment.district_code)
    e['assignment.district_code'] = 'District required for this role'
  if (geoRequired('poe_code') && !form.value.assignment.poe_code)
    e['assignment.poe_code'] = 'POE required for this role'
  errors.value = e
  return Object.keys(e).length === 0
}

// ── SUBMIT (offline-first) ────────────────────────────────────────────────
async function submitForm() {
  formError.value = ''; formSuccess.value = ''
  if (!validateForm()) { formError.value = 'Please fix the highlighted fields.'; return }

  formSubmitting.value = true
  const uuid  = editingUser.value?.client_uuid || genUUID()

  // province_code and pheoc_code must be the same value (Provincial PHEOC name)
  // server validates pheoc_code against its PHEOC reference list
  const rpheocValue = form.value.assignment.province_code || null

  const payload = {
    client_uuid:  uuid,
    full_name:    form.value.full_name.trim(),
    username:     form.value.username.trim().toLowerCase(),
    email:        form.value.email?.trim()  || null,
    phone:        form.value.phone?.trim()  || null,
    role_key:     form.value.role_key,
    country_code: TENANT_CC,
    is_active:    form.value.is_active ? 1 : 0,
    assignment: {
      country_code:  TENANT_CC,
      province_code: rpheocValue,
      pheoc_code:    form.value.assignment.pheoc_code || rpheocValue || null,
      district_code: form.value.assignment.district_code || null,
      poe_code:      form.value.assignment.poe_code      || null,
      is_primary:    1,
      is_active:     1,
    },
  }
  if (form.value.password) {
    payload.password = form.value.password
    // FIX 2026-05-07: stash plaintext in the in-memory retry buffer so any
    // background syncOne() retry within this session can still authenticate.
    // Buffer is cleared on successful sync (markSynced path).
    _pendingPasswords.set(uuid, form.value.password)
  }

  const localRecord = {
    client_uuid:        uuid,
    id:                 editingUser.value?.id ?? null,   // LAW 3
    role_key:           payload.role_key,
    country_code:       TENANT_CC,
    full_name:          payload.full_name,
    name:               payload.full_name,
    username:           payload.username,
    username_ci:        payload.username.toLowerCase(),
    email:              payload.email,
    email_ci:           (payload.email || '').toLowerCase(),
    phone:              payload.phone,
    is_active:          form.value.is_active,
    created_at:         editingUser.value?.created_at || isoNow(),
    updated_at:         isoNow(),
    last_login_at:      editingUser.value?.last_login_at || null,
    poe_code:           payload.assignment.poe_code,
    district_code:      payload.assignment.district_code,
    province_code:      payload.assignment.province_code,
    pheoc_code:         payload.assignment.pheoc_code,
    assignment:         payload.assignment,
    sync_status:        SYNC.UNSYNCED,
    synced_at:          null,
    sync_attempt_count: 0,
    last_sync_error:    null,
    record_version:     (editingUser.value?.record_version || 0) + 1,
  }

  // Offline-first guarantee
  if (editingUser.value) await safeDbPut(STORE.USERS_LOCAL, localRecord)
  else                    await dbPut(STORE.USERS_LOCAL, localRecord)
  await loadUsers()

  // Attempt server sync
  try {
    const isEdit = !!(editingUser.value && editingUser.value.id)   // LAW 1
    const url    = isEdit
      ? `${window.SERVER_URL}/users/${editingUser.value.id}`
      : `${window.SERVER_URL}/users`
    const method = isEdit ? 'PATCH' : 'POST'

    const ctrl = new AbortController()
    const tid  = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS)
    let res
    try {
      res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body:    JSON.stringify(payload),
        signal:  ctrl.signal,
      })
    } finally { clearTimeout(tid) }

    if (res.ok) {
      const body     = await res.json()
      const serverId = body?.data?.id ?? null
      await safeDbPut(STORE.USERS_LOCAL, {
        ...localRecord,
        id:              serverId,
        sync_status:     SYNC.SYNCED,
        synced_at:       isoNow(),
        last_sync_error: null,
        record_version:  localRecord.record_version + 1,
        updated_at:      isoNow(),
      })
      // FIX 2026-05-07: clear in-memory plaintext password buffer.
      _pendingPasswords.delete(uuid)
      formSuccess.value = isEdit ? 'User updated and uploaded.' : 'User created and uploaded.'
      showToast(formSuccess.value, 'success')
      await loadUsers()
      setTimeout(() => closeForm(), 900)
    } else {
      const errBody = await res.json().catch(() => ({}))
      const errMsg  = errBody?.message ||
        (errBody?.errors
          ? Object.values(errBody.errors).flat().join(' ')
          : `Server error ${res.status}`)
      await safeDbPut(STORE.USERS_LOCAL, {
        ...localRecord,
        sync_status:     res.status >= 500 ? SYNC.UNSYNCED : SYNC.FAILED,
        last_sync_error: errMsg,
        record_version:  localRecord.record_version + 1,
      })
      if (res.status === 422 && errBody?.errors) {
        const e = {}
        Object.entries(errBody.errors).forEach(([k, v]) => {
          e[k] = Array.isArray(v) ? v[0] : v
        })
        errors.value = { ...errors.value, ...e }
        formError.value = 'Please fix the highlighted fields.'
      } else {
        formSuccess.value = 'Saved offline. Will upload when connection improves.'
        showToast(formSuccess.value, 'warning')
        setTimeout(() => closeForm(), 1100)
      }
      await loadUsers()
    }
  } catch {
    formSuccess.value = 'Saved offline. Will upload when online.'
    showToast(formSuccess.value, 'warning')
    await loadUsers()
    setTimeout(() => closeForm(), 1100)
  }
  formSubmitting.value = false
}

// ── FORM HELPERS ──────────────────────────────────────────────────────────
function onPheocChange() {
  form.value.assignment.district_code = ''
  form.value.assignment.poe_code      = ''
  // pheoc_code MUST equal province_code for Uganda — server validates both
  form.value.assignment.pheoc_code    = form.value.assignment.province_code
}
function onDistrictChange() { form.value.assignment.poe_code = '' }
// 2026-05-19 — back-fill the Region (province_code) and PHEOC code when the
// user picks a district directly without first selecting a region. The
// District selector is now searchable across all districts in the tenant
// country; this handler keeps the form's geo scope consistent regardless of
// the order in which the user fills the fields.
function onDistrictPickWithRegionBackfill(picked) {
  // Always clear the dependent POE — same semantics as onDistrictChange.
  form.value.assignment.poe_code = ''
  const dist = String(picked || form.value.assignment.district_code || '')
  if (!dist) return
  const region = districtToRegionMap.value[dist]
  if (region && !form.value.assignment.province_code) {
    form.value.assignment.province_code = region
    // The PHEOC code mirrors the region for Uganda's PHEOC structure
    // (each region has one PHEOC); keep them in sync to satisfy the
    // server's province_or_pheoc requirement for downstream roles.
    if (!form.value.assignment.pheoc_code) {
      form.value.assignment.pheoc_code = region
    }
  }
}

function geoScopeNote(rk) {
  const m = {
    SCREENER:            'POE screener — requires Provincial PHEOC, district, and POE assignment.',
    DISTRICT_SUPERVISOR: 'Monitors all POEs in a district — requires Provincial PHEOC + district only.',
    PHEOC_OFFICER:       'Monitors all districts in Provincial PHEOC — requires Provincial PHEOC only.',
    NATIONAL_ADMIN:      'Full national access — no geographic assignment required.',
  }
  return m[rk] || 'Select a role to see geographic requirements.'
}

// ── DISPLAY HELPERS ───────────────────────────────────────────────────────
function initials(name) {
  if (!name) return '?'
  return name.trim().split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase()
}

function fmtDate(dt) {
  if (!dt) return ''
  try {
    return new Date(dt).toLocaleString('en-UG', {
      day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit',
    })
  } catch { return dt }
}

function syncSt(user) {
  if (user.sync_status === SYNC.SYNCED)  return 'synced'
  if (user.sync_status === SYNC.FAILED)  return 'failed'
  return 'pending'
}

// Map server role_keys to display labels and CSS colour keys
const ROLE_META = {
  SCREENER:            { label:'Screener',            key:'scr'  },
  DISTRICT_SUPERVISOR: { label:'District Supervisor', key:'dist' },
  PHEOC_OFFICER:       { label:'PHEOC Officer',       key:'phoc' },
  NATIONAL_ADMIN:      { label:'National Admin',      key:'nat'  },
}
function roleMeta(rk)  { return ROLE_META[rk] || { label: rk || '—', key: 'all' } }
function roleLabel(rk) { return roleMeta(rk).label }
function roleKey(rk)   { return roleMeta(rk).key }

function showToast(msg, color = 'success') { toast.value = { show: true, msg, color } }

// ── NUMBER FORMATTING — scales to millions without overflow ───────────
// fmt(12453)       → "12.4K"
// fmt(1234567)     → "1.2M"
// fmt(42)          → "42"
// fmtFull(12453)   → "12,453"  (for tooltips/detail views)
function fmt(n) {
  if (n == null || isNaN(n)) return '0'
  n = Number(n)
  if (n >= 1_000_000) return (n / 1_000_000).toFixed(n >= 10_000_000 ? 0 : 1).replace(/\.0$/, '') + 'M'
  if (n >= 100_000)   return Math.round(n / 1_000) + 'K'
  if (n >= 10_000)    return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'K'
  if (n >= 1_000)     return (n / 1_000).toFixed(1).replace(/\.0$/, '') + 'K'
  return String(n)
}
function fmtFull(n) {
  if (n == null || isNaN(n)) return '0'
  return Number(n).toLocaleString('en-US')
}
// Badge cap: anything > 999 → "999+" for icon badges
function fmtBadge(n) {
  if (n > 999) return '999+'
  if (n > 99)  return '99+'
  return String(n)
}
// Sync bar text uses full numbers for clarity in the status message
function fmtSyncText(n) {
  if (n >= 1_000) return fmtFull(n)
  return String(n)
}
</script>


<style scoped>
/* ═══════════════════════════════════════════════════════════════════════
   SENTINEL DUAL-TONE — USER COMMAND CENTRE
   DARK  ZONE : header · toolbar · stats · search · chips (navy gradients)
   LIGHT ZONE : ALL content · cards · modals · forms (luminous gradients)
═══════════════════════════════════════════════════════════════════════ */
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Syne:wght@600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap');

/* ── Keyframes ──────────────────────────────────────────────────────── */
@keyframes slideUp   { from { opacity:0; transform:translateY(12px) } to { opacity:1; transform:translateY(0) } }
@keyframes stream    { 0% { transform:translateX(-100%) } 100% { transform:translateX(350%) } }
@keyframes shimmer   { 0% { background-position:-200% 0 } 100% { background-position:200% 0 } }
@keyframes spin      { to { transform:rotate(360deg) } }
@keyframes dotPulse  { 0%,100% { transform:scale(1);opacity:1 } 50% { transform:scale(1.5);opacity:.6 } }
@keyframes netGlow   { 0%,100% { box-shadow:0 0 5px rgba(0,230,118,.4) } 50% { box-shadow:0 0 14px rgba(0,230,118,.8) } }
@keyframes syncPulse { 0%,100% { opacity:1 } 50% { opacity:.4 } }
@keyframes expandIn  { from { opacity:0; max-height:0 } to { opacity:1; max-height:900px } }
@keyframes fabPop    { 0% { opacity:0; transform:scale(.5) translateY(20px) } 100% { opacity:1; transform:scale(1) translateY(0) } }
@keyframes scan      { 0% { left:-60% } 100% { left:150% } }
@media (prefers-reduced-motion:reduce) { *,*::before,*::after { animation-duration:.01ms!important; transition-duration:.01ms!important } }

/* ═══════════════════════════════════════════════════════════════════
   DARK ZONE
═══════════════════════════════════════════════════════════════════ */
.ul-header { --background: #070E1B; --border-width: 0; }
.ul-toolbar {
  --background: linear-gradient(180deg, #070E1B 0%, #0E1A2E 100%);
  --color: #EDF2FA; --border-width: 0;
  --min-height: 56px;
}
/* Dot grid texture — inside IonToolbar only */
.ul-toolbar-texture {
  position:absolute; inset:0; pointer-events:none; z-index:0;
  background-image:
    linear-gradient(rgba(0,180,255,.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,180,255,.03) 1px, transparent 1px);
  background-size: 32px 32px;
  mask-image: linear-gradient(180deg, black 30%, transparent 100%);
}
/* ul-hdr-band: dark zone band BELOW IonToolbar, still inside IonHeader */
.ul-hdr-band {
  background: linear-gradient(180deg, #0E1A2E 0%, #070E1B 100%);
  border-bottom: 1px solid rgba(255,255,255,.06);
}
/* title slot fix */
.ul-toolbar-title-slot { padding: 0; }

/* ── Top row ──────────────────────────────────────────────────────── */
.ul-hdr-row  { display:flex; align-items:center; padding:10px 14px 8px; position:relative; z-index:2; gap:10px; }
.ul-hdr-left { display:flex; align-items:center; gap:8px; flex:1; min-width:0; }
.ul-hdr-right{ display:flex; align-items:center; gap:8px; flex-shrink:0; }
.ul-title-block { display:flex; flex-direction:column; gap:1px; }
.ul-eyebrow { font-size:7px; font-weight:700; color:#7E92AB; letter-spacing:1.4px; text-transform:uppercase; font-family:'DM Sans',sans-serif; }
.ul-title   { font-size:17px; font-weight:900; color:#EDF2FA; font-family:'Syne',sans-serif; line-height:1.1; letter-spacing:-.3px; }

/* Network dot */
.ul-net-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.ul-net-dot--on  { background:#00E676; animation:netGlow 2.5s infinite; }
.ul-net-dot--off { background:#E02050; }

/* Icon buttons */
.ul-icon-btn {
  width:38px; height:38px; border-radius:11px;
  background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1);
  display:flex; align-items:center; justify-content:center;
  cursor:pointer; position:relative; transition:all .18s;
}
.ul-icon-btn svg { width:18px; height:18px; stroke:#7E92AB; }
.ul-icon-btn--warn { border-color:rgba(255,179,0,.35); background:rgba(255,179,0,.08); }
.ul-icon-btn--warn svg { stroke:#FFB300; }
.ul-icon-btn--blue { background:rgba(0,180,255,.1); border-color:rgba(0,180,255,.25); }
.ul-icon-btn--blue svg { stroke:#00B4FF; }
.ul-icon-btn:disabled { opacity:.5; cursor:not-allowed; }
.ul-icon-badge {
  position:absolute; top:-5px; right:-5px;
  background:#FFB300; color:#000; font-size:8px; font-weight:900;
  min-width:16px; height:16px; border-radius:8px;
  display:flex; align-items:center; justify-content:center;
  padding:0 3px; border:2px solid #070E1B;
}

/* ── Sync status bar ──────────────────────────────────────────────── */
.ul-sync-bar { padding:0 14px; position:relative; z-index:1; }
.ul-sync-track { height:2px; background:rgba(255,255,255,.08); border-radius:2px; margin-bottom:6px; overflow:hidden; }
.ul-sync-fill  { height:100%; border-radius:2px; transition:width 1s cubic-bezier(.16,1,.3,1); }
.ul-sync-bar--ok      .ul-sync-fill { background:linear-gradient(90deg,#00A86B,#00E676); }
.ul-sync-bar--pending .ul-sync-fill { background:linear-gradient(90deg,#CC8800,#FFB300); }
.ul-sync-bar--failed  .ul-sync-fill { background:linear-gradient(90deg,#E02050,#FF3D71); }
.ul-sync-bar--syncing .ul-sync-fill { background:linear-gradient(90deg,#0070E0,#00B4FF); animation:syncPulse 1s infinite; }
.ul-sync-row    { display:flex; align-items:center; gap:6px; margin-bottom:8px; }
.ul-sync-dot    { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.ul-sdot--ok      { background:#00E676; animation:netGlow 2s infinite; }
.ul-sdot--pending { background:#FFB300; animation:dotPulse 1.5s infinite; }
.ul-sdot--failed  { background:#FF3D71; animation:dotPulse 1s infinite; }
.ul-sdot--syncing { background:#00B4FF; animation:dotPulse .8s infinite; }
.ul-sync-text    { font-size:10px; font-weight:600; color:#7E92AB; flex:1; }
.ul-sync-bar--pending .ul-sync-text { color:#FFB300; }
.ul-sync-bar--failed  .ul-sync-text { color:#FF6B8A; }
.ul-sync-bar--syncing .ul-sync-text { color:#64B5F6; }
.ul-sync-counter { font-size:9px; font-weight:800; color:#7E92AB; font-family:'JetBrains Mono',monospace; }

/* ── Stats ribbon ─────────────────────────────────────────────────── */
.ul-stats-ribbon { display:flex; align-items:center; background:linear-gradient(180deg,#0E1A2E,#142640); padding:9px 12px; border-bottom:1px solid rgba(255,255,255,.06); }
.ul-stat   { display:flex; flex-direction:column; align-items:center; flex:1; }
.ul-stat-n { font-size:18px; font-weight:900; color:#EDF2FA; font-family:'Syne',sans-serif; line-height:1; }
.ul-stat-l { font-size:7px; font-weight:700; color:#7E92AB; letter-spacing:.7px; text-transform:uppercase; margin-top:2px; }
.ul-n--green { color:#00E676; text-shadow:0 0 12px rgba(0,230,118,.35); }
.ul-n--amber { color:#FFB300; text-shadow:0 0 12px rgba(255,179,0,.35); }
.ul-n--red   { color:#FF3D71; text-shadow:0 0 12px rgba(255,61,113,.35); }
.ul-n--cyan  { color:#00E5FF; text-shadow:0 0 12px rgba(0,229,255,.35); }
.ul-stat-sep { width:1px; height:28px; background:rgba(255,255,255,.06); margin:0 2px; }

/* ── Search ───────────────────────────────────────────────────────── */
.ul-search-zone { background:linear-gradient(180deg,#0E1A2E,#0A1628); padding:8px 12px 6px; }
.ul-search-bar  {
  display:flex; align-items:center; gap:8px;
  background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1);
  border-radius:12px; padding:0 12px; position:relative; overflow:hidden; transition:all .2s;
}
.ul-search-bar::after {
  content:''; position:absolute; top:0; left:-60%; width:50%; height:100%;
  background:linear-gradient(90deg,transparent,rgba(0,180,255,.04),transparent);
  animation:scan 7s ease-in-out infinite; pointer-events:none;
}
.ul-search-bar--active { border-color:rgba(0,180,255,.3); background:rgba(0,180,255,.05); }
.ul-search-ic    { width:16px; height:16px; flex-shrink:0; }
.ul-search-input {
  flex:1; background:transparent; border:none; color:#EDF2FA;
  font-size:16px; padding:11px 0; outline:none; font-family:'DM Sans',sans-serif;
}
.ul-search-input::placeholder { color:rgba(255,255,255,.3); }
.ul-search-clear {
  background:none; border:none; color:rgba(255,255,255,.4); cursor:pointer;
  font-size:13px; padding:4px; min-width:28px; min-height:28px;
  display:flex; align-items:center; justify-content:center;
}

/* ── Chips ────────────────────────────────────────────────────────── */
.ul-chip-row { display:flex; gap:5px; overflow-x:auto; scrollbar-width:none; padding:5px 12px 4px; background:linear-gradient(180deg,#0A1628,#070E1B); }
.ul-chip-row::-webkit-scrollbar { display:none; }
.ul-chip {
  display:flex; align-items:center; gap:5px; padding:6px 11px;
  border-radius:20px; font-size:10px; font-weight:700; cursor:pointer;
  border:1px solid rgba(255,255,255,.1); white-space:nowrap;
  background:rgba(255,255,255,.06); color:#7E92AB;
  transition:all .18s; min-height:34px; font-family:'DM Sans',sans-serif;
}
.ul-chip em { font-style:normal; background:rgba(255,255,255,.08); border-radius:8px; padding:0 5px; font-size:8px; font-weight:900; font-family:'JetBrains Mono',monospace; }
.ul-chip-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.ul-chip--on { background:rgba(255,255,255,.14); border-color:rgba(255,255,255,.25); color:#EDF2FA; }

/* ── Filter row ───────────────────────────────────────────────────── */
.ul-filter-row { display:flex; align-items:center; gap:6px; flex-wrap:wrap; padding:6px 12px 10px; background:#070E1B; }
.ul-sts-btn { display:flex; align-items:center; gap:5px; padding:5px 11px; border-radius:18px; font-size:10px; font-weight:700; border:1px solid rgba(255,255,255,.08); background:rgba(255,255,255,.05); color:#7E92AB; cursor:pointer; transition:all .18s; min-height:32px; font-family:'DM Sans',sans-serif; }
.ul-sts-btn--on { background:rgba(255,255,255,.12); border-color:rgba(255,255,255,.2); color:#EDF2FA; }
.ul-sts-dot { width:6px; height:6px; border-radius:50%; }
.ul-sts-dot--all      { background:#7E92AB; }
.ul-sts-dot--active   { background:#00E676; animation:netGlow 2s infinite; }
.ul-sts-dot--inactive { background:#E02050; }
.ul-poe-sel { margin-left:auto; padding:5px 10px; border-radius:10px; background:#0E1A2E; border:1px solid rgba(255,255,255,.12); color:#7E92AB; font-size:10px; font-weight:600; outline:none; cursor:pointer; font-family:'DM Sans',sans-serif; }

/* Spin */
.ul-spin { animation:spin .9s linear infinite; }

/* ═══════════════════════════════════════════════════════════════════
   LIGHT ZONE — Content
═══════════════════════════════════════════════════════════════════ */
.ul-content-wrap { padding:10px 12px; display:flex; flex-direction:column; gap:10px; }

/* Scope banner */
.ul-scope-banner {
  display:flex; align-items:center; gap:8px; padding:10px 13px;
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF);
  border:1.5px solid rgba(0,112,224,.18); border-radius:12px;
  font-size:11px; font-weight:600; color:#0B1A30; position:relative; overflow:hidden;
}
.ul-scope-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.8) 50%,transparent 80%); }
.ul-scope-pin   { font-size:14px; }
.ul-scope-text  { flex:1; }
.ul-scope-text strong { color:#0070E0; }
.ul-scope-role-tag { font-size:8px; font-weight:700; color:#0070E0; background:rgba(0,112,224,.12); padding:2px 7px; border-radius:4px; font-family:'JetBrains Mono',monospace; white-space:nowrap; }

/* ── Visualization card ───────────────────────────────────────────── */
.ul-viz-card {
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:14px; padding:14px 14px 12px;
  box-shadow:0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  position:relative; overflow:hidden;
}
.ul-viz-card-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.9) 50%,transparent 80%); pointer-events:none; }

/* Sync summary row — 4 stats in a clean pill row */
.ul-viz-summary { display:flex; align-items:center; background:rgba(0,0,0,.025); border-radius:10px; padding:10px 12px; margin-bottom:14px; }
.ul-viz-summary-cell { display:flex; flex-direction:column; align-items:center; flex:1; }
.ul-viz-summary-n    { font-size:18px; font-weight:900; color:#0B1A30; font-family:'Syne',sans-serif; line-height:1; }
.ul-viz-summary-pct  { font-size:16px; }
.ul-viz-summary-l    { font-size:8px; font-weight:700; color:#94A3B8; text-transform:uppercase; letter-spacing:.6px; margin-top:2px; }
.ul-viz-summary-cell--green .ul-viz-summary-n { color:#00A86B; }
.ul-viz-summary-cell--amber .ul-viz-summary-n { color:#CC8800; }
.ul-viz-summary-cell--red   .ul-viz-summary-n { color:#E02050; }
.ul-viz-summary-sep { width:1px; height:28px; background:rgba(0,0,0,.07); margin:0 4px; }

/* Role distribution bars — clean stacked layout */
.ul-viz-bars-label { font-size:8px; font-weight:700; color:#94A3B8; letter-spacing:1.1px; text-transform:uppercase; margin-bottom:8px; }
.ul-viz-bars { display:flex; flex-direction:column; gap:9px; }
.ul-viz-bar-item { display:flex; flex-direction:column; gap:4px; }
.ul-viz-bar-header { display:flex; align-items:center; gap:7px; }
.ul-viz-bar-dot   { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.ul-viz-bar-name  { font-size:11px; font-weight:600; color:#0B1A30; flex:1; font-family:'DM Sans',sans-serif; }
.ul-viz-bar-count { font-size:11px; font-weight:800; color:#475569; font-family:'JetBrains Mono',monospace; }
.ul-viz-bar-pct   { font-size:10px; font-weight:600; color:#94A3B8; font-family:'JetBrains Mono',monospace; min-width:32px; text-align:right; }
.ul-viz-bar-track { height:7px; background:rgba(0,0,0,.07); border-radius:5px; overflow:hidden; }
.ul-viz-bar-fill  { height:100%; border-radius:5px; transition:width 1s cubic-bezier(.16,1,.3,1); }

/* ── Damaged records banner ───────────────────────────────────────── */
.ul-damaged-banner {
  background:linear-gradient(135deg,#FEF2F2,#FECACA);
  border:1.5px solid rgba(224,32,80,.22); border-radius:14px; padding:14px;
  position:relative; overflow:hidden;
}
.ul-damaged-banner-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.7) 50%,transparent 80%); }
.ul-damaged-hdr      { display:flex; align-items:flex-start; gap:10px; margin-bottom:12px; }
.ul-damaged-ic       { font-size:20px; flex-shrink:0; margin-top:2px; }
.ul-damaged-hdr-body { flex:1; }
.ul-damaged-title    { font-size:12px; font-weight:800; color:#B01840; font-family:'DM Sans',sans-serif; margin-bottom:2px; }
.ul-damaged-sub      { font-size:10px; color:#475569; line-height:1.4; }
.ul-damaged-repair-all {
  margin-left:auto; font-size:10px; font-weight:700; color:#fff;
  background:linear-gradient(135deg,#B01840,#E02050); border:none;
  border-radius:8px; padding:6px 12px; cursor:pointer; flex-shrink:0;
  min-height:32px; font-family:'DM Sans',sans-serif;
  box-shadow:0 2px 8px rgba(224,32,80,.3);
}
.ul-damaged-list { display:flex; flex-direction:column; gap:7px; }
.ul-damaged-row  { display:flex; align-items:center; gap:10px; background:rgba(255,255,255,.5); border-radius:9px; padding:9px 11px; }
.ul-damaged-av   { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:11px; font-weight:800; flex-shrink:0; }
.ul-damaged-body { flex:1; min-width:0; }
.ul-damaged-name { display:block; font-size:11px; font-weight:700; color:#0B1A30; }
.ul-damaged-err  { display:block; font-size:9px; color:#E02050; font-family:'JetBrains Mono',monospace; word-break:break-word; }
.ul-damaged-att  { display:block; font-size:9px; color:#94A3B8; margin-top:1px; }
.ul-damaged-retry {
  width:32px; height:32px; border-radius:8px;
  background:rgba(224,32,80,.12); border:1px solid rgba(224,32,80,.2);
  color:#E02050; font-size:18px; cursor:pointer;
  display:flex; align-items:center; justify-content:center; flex-shrink:0;
}

/* ── Results bar ──────────────────────────────────────────────────── */
.ul-results-bar    { display:flex; align-items:center; justify-content:space-between; padding:2px 2px; }
.ul-results-count  { font-size:11px; color:#94A3B8; font-weight:600; }
.ul-results-count strong { color:#0B1A30; font-weight:800; }
.ul-results-dmg-tag { font-size:8px; color:#E02050; background:rgba(224,32,80,.08); padding:1px 5px; border-radius:3px; margin-left:5px; font-weight:700; font-family:'JetBrains Mono',monospace; }
.ul-results-right  { display:flex; gap:6px; align-items:center; }
.ul-results-sync   { font-size:10px; font-weight:700; color:#fff; background:linear-gradient(135deg,#007A50,#00A86B); border:none; border-radius:7px; padding:5px 12px; cursor:pointer; font-family:'DM Sans',sans-serif; min-height:30px; box-shadow:0 2px 8px rgba(0,168,107,.25); }
.ul-results-sync:disabled { opacity:.6; cursor:not-allowed; }
.ul-results-clear  { font-size:10px; font-weight:700; color:#0070E0; background:linear-gradient(135deg,#E0ECFF,#CCE0FF); border:1px solid rgba(0,112,224,.2); border-radius:7px; padding:5px 10px; cursor:pointer; min-height:30px; }

/* ── Skeleton ─────────────────────────────────────────────────────── */
.ul-skel { display:flex; align-items:center; gap:0; background:linear-gradient(145deg,#FFFFFF,#F4F7FC); border:1.5px solid rgba(0,0,0,.06); border-radius:14px; overflow:hidden; height:82px; animation:slideUp .4s cubic-bezier(.16,1,.3,1) both; }
.ul-skel-bar  { width:4px; flex-shrink:0; height:100%; background:linear-gradient(90deg,#E4EBF7 25%,#F2F5FB 50%,#E4EBF7 75%); background-size:200% 100%; animation:shimmer 1.6s infinite; }
.ul-skel-body { flex:1; display:flex; align-items:center; gap:12px; padding:14px 14px 14px 12px; }
.ul-skel-av   { width:46px; height:46px; border-radius:12px; flex-shrink:0; background:linear-gradient(90deg,#E4EBF7 25%,#F2F5FB 50%,#E4EBF7 75%); background-size:200% 100%; animation:shimmer 1.6s infinite; }
.ul-skel-lines { flex:1; }
.ul-skel-line  { height:12px; border-radius:6px; background:linear-gradient(90deg,#E4EBF7 25%,#F2F5FB 50%,#E4EBF7 75%); background-size:200% 100%; animation:shimmer 1.6s infinite; margin-bottom:0; }
.ul-skel-pill  { width:60px; height:22px; border-radius:8px; background:linear-gradient(90deg,#E4EBF7 25%,#F2F5FB 50%,#E4EBF7 75%); background-size:200% 100%; animation:shimmer 1.6s infinite; flex-shrink:0; }

/* ── Empty state ──────────────────────────────────────────────────── */
.ul-empty      { display:flex; flex-direction:column; align-items:center; padding:70px 24px; gap:12px; }
.ul-empty-icon svg { width:64px; height:64px; opacity:.3; }
.ul-empty-title { font-size:17px; font-weight:700; color:#475569; font-family:'DM Sans',sans-serif; }
.ul-empty-sub   { font-size:12px; color:#94A3B8; text-align:center; line-height:1.5; }
.ul-empty-cta   { padding:12px 24px; border-radius:12px; background:linear-gradient(135deg,#0055CC,#0070E0); color:#fff; border:none; cursor:pointer; font-size:13px; font-weight:700; box-shadow:0 4px 14px rgba(0,112,224,.3); min-height:44px; font-family:'DM Sans',sans-serif; }

/* ═══════════════════════════════════════════════════════════════════
   USER CARDS
═══════════════════════════════════════════════════════════════════ */
.ul-card {
  background:linear-gradient(145deg,#FFFFFF 0%,#F4F7FC 100%);
  border:1.5px solid rgba(0,0,0,.06); border-radius:14px;
  box-shadow:0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  overflow:hidden; position:relative;
  animation:slideUp .4s cubic-bezier(.16,1,.3,1) both;
  transition:transform .22s cubic-bezier(.16,1,.3,1), box-shadow .22s;
}
.ul-card::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 15%,rgba(255,255,255,.9) 50%,transparent 85%); z-index:2; pointer-events:none; }
.ul-card:hover  { transform:translateY(-1px); box-shadow:0 3px 10px rgba(0,0,0,.07), 0 10px 35px rgba(0,30,80,.11); }
.ul-card--inactive { opacity:.72; }
.ul-card--damaged  { border-color:rgba(224,32,80,.22); }
.ul-card--expanded { border-color:rgba(0,112,224,.2); }

/* Premium effects on card */
.ul-card-shine  { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.9) 50%,transparent 80%); z-index:2; pointer-events:none; }
.ul-card-stream { position:absolute; top:0; bottom:0; width:45%; left:0; background:linear-gradient(90deg,transparent,rgba(0,112,224,.02),transparent); animation:stream 10s ease-in-out infinite; pointer-events:none; z-index:1; }

/* Accent bars per role */
.ul-card-bar { width:4px; position:absolute; left:0; top:0; bottom:0; }
.ul-card-bar--scr  { background:#00A86B; box-shadow:0 0 8px rgba(0,168,107,.4); }
.ul-card-bar--dist { background:#E02050; box-shadow:0 0 8px rgba(224,32,80,.35); }
.ul-card-bar--phoc { background:#7B40D8; box-shadow:0 0 8px rgba(123,64,216,.35); }
.ul-card-bar--nat  { background:#D63384; box-shadow:0 0 8px rgba(214,51,132,.35); }
.ul-card-bar--all  { background:#94A3B8; }

/* Card main row */
.ul-card-main { display:flex; align-items:center; gap:12px; padding:13px 14px 13px 18px; cursor:pointer; position:relative; z-index:2; }
.ul-card-info { flex:1; min-width:0; }
.ul-card-name { font-size:14px; font-weight:700; color:#0B1A30; font-family:'DM Sans',sans-serif; line-height:1.25; display:flex; align-items:center; flex-wrap:wrap; gap:5px; }
.ul-card-meta { font-size:10px; color:#94A3B8; font-family:'JetBrains Mono',monospace; margin-top:2px; }
.ul-card-tags { display:flex; gap:5px; flex-wrap:wrap; margin-top:5px; }
.ul-card-right { display:flex; flex-direction:column; align-items:flex-end; gap:6px; flex-shrink:0; }

/* Tags on cards */
.ul-tag-inactive { font-size:7px; font-weight:800; color:#94A3B8; background:rgba(0,0,0,.05); padding:1px 5px; border-radius:3px; letter-spacing:.4px; font-family:'JetBrains Mono',monospace; }
.ul-tag-damaged  { font-size:7px; font-weight:800; color:#E02050; background:rgba(224,32,80,.08); padding:1px 5px; border-radius:3px; border:1px solid rgba(224,32,80,.2); }

/* Avatar */
.ul-av {
  width:46px; height:46px; border-radius:12px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; position:relative;
}
.ul-av-lg { width:48px; height:48px; border-radius:12px; font-size:16px; }
.ul-av-initials { font-size:15px; font-weight:900; font-family:'Syne',sans-serif; line-height:1; }
.ul-av--scr  { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); border:1px solid rgba(0,168,107,.2); color:#007A50; }
.ul-av--dist { background:linear-gradient(135deg,#FEF2F2,#FECACA); border:1px solid rgba(224,32,80,.2); color:#B01840; }
.ul-av--phoc { background:linear-gradient(135deg,#F5F3FF,#EDE9FE); border:1px solid rgba(123,64,216,.2); color:#5A20B0; }
.ul-av--nat  { background:linear-gradient(135deg,#FDF2F8,#FCE7F3); border:1px solid rgba(214,51,132,.2); color:#A0205E; }
.ul-av--all  { background:rgba(0,0,0,.04); border:1px solid rgba(0,0,0,.06); color:#94A3B8; }

.ul-av-dot { position:absolute; bottom:-2px; right:-2px; width:11px; height:11px; border-radius:50%; border:2px solid #FFFFFF; }
.ul-av-dot--on  { background:#00A86B; animation:dotPulse 2.5s infinite; }
.ul-av-dot--off { background:#94A3B8; }

/* Role badges */
.ul-role-badge { font-size:8px; font-weight:700; padding:3px 8px; border-radius:5px; border:1px solid; font-family:'JetBrains Mono',monospace; letter-spacing:.3px; }
.ul-rb--scr  { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); color:#007A50; border-color:rgba(0,168,107,.2); }
.ul-rb--dist { background:linear-gradient(135deg,#FEF2F2,#FECACA); color:#B01840; border-color:rgba(224,32,80,.2); }
.ul-rb--phoc { background:linear-gradient(135deg,#F5F3FF,#EDE9FE); color:#5A20B0; border-color:rgba(123,64,216,.2); }
.ul-rb--nat  { background:linear-gradient(135deg,#FDF2F8,#FCE7F3); color:#A0205E; border-color:rgba(214,51,132,.15); }
.ul-rb--all  { background:rgba(0,0,0,.04); color:#94A3B8; border-color:rgba(0,0,0,.06); }

.ul-poe-tag { font-size:9px; font-weight:600; color:#475569; background:rgba(0,0,0,.05); padding:3px 7px; border-radius:4px; }

/* Sync pills */
.ul-sync-pill { display:flex; align-items:center; gap:4px; font-size:8px; font-weight:700; padding:3px 8px; border-radius:5px; font-family:'JetBrains Mono',monospace; letter-spacing:.3px; border:1px solid; }
.ul-sp--synced  { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); color:#00A86B; border-color:rgba(0,168,107,.2); }
.ul-sp--pending { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); color:#CC8800; border-color:rgba(204,136,0,.2); }
.ul-sp--failed  { background:linear-gradient(135deg,#FEF2F2,#FECACA); color:#E02050; border-color:rgba(224,32,80,.2); }
.ul-sp-dot { width:5px; height:5px; border-radius:50%; flex-shrink:0; }
.ul-sp-dot--synced  { background:#00A86B; }
.ul-sp-dot--pending { background:#CC8800; animation:dotPulse 1.5s infinite; }
.ul-sp-dot--failed  { background:#E02050; animation:dotPulse 1s infinite; }

.ul-menu-btn { width:32px; height:32px; border-radius:8px; background:transparent; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background .12s; }
.ul-menu-btn:hover { background:rgba(0,0,0,.06); }
.ul-menu-btn svg { width:14px; height:14px; }

/* ── Expanded detail ──────────────────────────────────────────────── */
.ul-expand-enter-active { animation:expandIn .28s cubic-bezier(.16,1,.3,1); }
.ul-expand-leave-active { transition:opacity .15s, max-height .2s ease-in; max-height:900px; overflow:hidden; }
.ul-expand-leave-from  { opacity:1; max-height:900px; }
.ul-expand-leave-to    { opacity:0; max-height:0; }

.ul-det { padding:0 18px 14px; border-top:1px solid rgba(0,0,0,.06); position:relative; z-index:2; }
.ul-det-section { margin-bottom:10px; }
.ul-det-hdr  { font-size:8px; font-weight:800; color:#94A3B8; letter-spacing:1px; text-transform:uppercase; padding-top:10px; margin-bottom:7px; font-family:'DM Sans',sans-serif; }
.ul-det-grid { display:grid; grid-template-columns:1fr 1fr; gap:5px; }
.ul-det-cell { display:flex; flex-direction:column; gap:1px; padding:7px 10px; background:rgba(0,0,0,.03); border-radius:7px; }
.ul-det-k    { font-size:8px; font-weight:700; color:#94A3B8; letter-spacing:.5px; text-transform:uppercase; }
.ul-det-v    { font-size:11px; font-weight:600; color:#475569; font-family:'DM Sans',sans-serif; word-break:break-word; }
.ul-dv--id     { font-family:'JetBrains Mono',monospace; color:#0070E0; font-size:10px; }
.ul-dv--uuid   { font-family:'JetBrains Mono',monospace; font-size:8px; color:#94A3B8; word-break:break-all; }
.ul-dv--sm     { font-size:9px; }
.ul-dv--active { color:#00A86B; font-weight:800; }
.ul-dv--inactive{ color:#E02050; font-weight:800; }
.ul-dv--warn   { color:#CC8800; font-weight:800; }

.ul-det-error { display:flex; align-items:flex-start; gap:6px; padding:8px 10px; background:rgba(224,32,80,.06); border:1px solid rgba(224,32,80,.15); border-radius:7px; margin:6px 0; }
.ul-det-error-txt { font-size:9px; color:#E02050; font-family:'JetBrains Mono',monospace; word-break:break-word; flex:1; }

.ul-det-actions { display:flex; gap:7px; flex-wrap:wrap; margin-top:10px; }
.ul-det-btn {
  display:flex; align-items:center; gap:5px; padding:8px 12px;
  border-radius:9px; font-size:11px; font-weight:700; border:1.5px solid;
  cursor:pointer; font-family:'DM Sans',sans-serif; min-height:36px; transition:all .15s;
}
.ul-det-btn svg { width:12px; height:12px; }
.ul-det-btn--edit   { background:linear-gradient(135deg,#E0ECFF,#CCE0FF); color:#0070E0; border-color:rgba(0,112,224,.2); }
.ul-det-btn--deact  { background:linear-gradient(135deg,#FEF2F2,#FECACA); color:#E02050; border-color:rgba(224,32,80,.2); }
.ul-det-btn--act    { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); color:#00A86B; border-color:rgba(0,168,107,.2); }
.ul-det-btn--sync   { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); color:#00A86B; border-color:rgba(0,168,107,.2); }
.ul-det-btn--syncing { opacity:.7; cursor:not-allowed; }
.ul-det-btn--repair { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); color:#CC8800; border-color:rgba(204,136,0,.2); }
.ul-list-end { text-align:center; font-size:10px; color:#94A3B8; padding:8px; }

/* ═══════════════════════════════════════════════════════════════════
   FAB & SPEED DIAL
═══════════════════════════════════════════════════════════════════ */
.ul-fab-wrap { position:fixed; bottom:max(env(safe-area-inset-bottom,0px),24px); right:20px; z-index:999; display:flex; flex-direction:column; align-items:flex-end; gap:10px; }
.ul-fab {
  width:56px; height:56px; border-radius:18px;
  background:linear-gradient(135deg,#0055CC,#0070E0);
  border:none; cursor:pointer;
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 6px 24px rgba(0,112,224,.4), 0 2px 8px rgba(0,0,0,.15);
  transition:transform .22s cubic-bezier(.16,1,.3,1), box-shadow .22s;
}
.ul-fab:hover  { transform:translateY(-2px); box-shadow:0 8px 30px rgba(0,112,224,.5); }
.ul-fab:active { transform:scale(.94); }
.ul-fab svg    { width:22px; height:22px; }
.ul-fab-t-enter-active { animation:fabPop .3s cubic-bezier(.16,1,.3,1); }
.ul-fab-t-leave-active { transition:opacity .15s; }
.ul-fab-t-leave-to     { opacity:0; }
.ul-speed-dial { display:flex; flex-direction:column; gap:8px; align-items:flex-end; }
.ul-fab-mini {
  display:flex; align-items:center; gap:8px; padding:9px 14px;
  border-radius:12px; font-size:12px; font-weight:700; border:none; cursor:pointer;
  font-family:'DM Sans',sans-serif; box-shadow:0 2px 12px rgba(0,0,0,.15);
  transition:all .18s; white-space:nowrap; min-height:40px;
}
.ul-fab-mini svg    { width:14px; height:14px; stroke:currentColor; }
.ul-fm--sync        { background:linear-gradient(135deg,#007A50,#00A86B); color:#fff; }
.ul-fm--inactive    { background:linear-gradient(145deg,#FFFFFF,#F4F7FC); color:#475569; border:1.5px solid rgba(0,0,0,.1); }
.ul-fm--damaged     { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); color:#CC8800; border:1.5px solid rgba(204,136,0,.2); }
.ul-fab-mini:disabled { opacity:.5; cursor:not-allowed; }

/* ═══════════════════════════════════════════════════════════════════
   FORM MODAL
═══════════════════════════════════════════════════════════════════ */
.ul-form-title-block { display:flex; flex-direction:column; gap:2px; }
.ul-form-eyebrow    { font-size:7px; font-weight:700; color:#7E92AB; letter-spacing:1.2px; text-transform:uppercase; }
.ul-form-title-name { font-size:15px; font-weight:800; color:#EDF2FA; font-family:'Syne',sans-serif; line-height:1.2; }
.ul-form-hdr-role   { font-size:8px; font-weight:700; padding:3px 8px; border-radius:5px; font-family:'JetBrains Mono',monospace; }

.ul-form-wrap { padding:16px 14px; display:flex; flex-direction:column; gap:0; }
.ul-form-alert { display:flex; align-items:center; gap:8px; padding:11px 13px; border-radius:10px; margin-bottom:14px; font-size:11px; font-weight:600; border:1.5px solid; }
.ul-form-alert--err { background:linear-gradient(135deg,#FEF2F2,#FECACA); color:#B01840; border-color:rgba(224,32,80,.2); }
.ul-form-alert--ok  { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); color:#007A50; border-color:rgba(0,168,107,.2); }

.ul-fsec { margin-bottom:20px; }
.ul-fsec-label {
  display:flex; align-items:center; gap:8px;
  font-size:9px; font-weight:700; color:#0070E0;
  letter-spacing:1.1px; text-transform:uppercase;
  margin-bottom:12px; padding-bottom:8px;
  border-bottom:1px solid rgba(0,112,224,.12); font-family:'DM Sans',sans-serif;
}
.ul-fsec-glyph {
  width:24px; height:24px; border-radius:6px; flex-shrink:0;
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF);
  border:1px solid rgba(0,112,224,.15);
  display:flex; align-items:center; justify-content:center; font-size:13px;
}

.ul-fg { margin-bottom:12px; }
.ul-fg--err .ul-fi,
.ul-fg--err .ul-fsel { border-color:rgba(224,32,80,.4) !important; }
.ul-fl { display:block; font-size:10px; font-weight:700; color:#475569; margin-bottom:5px; font-family:'DM Sans',sans-serif; }
.ul-req { color:#E02050; }
.ul-ferr { display:block; font-size:10px; color:#E02050; margin-top:4px; font-weight:600; }

.ul-fi {
  width:100%; background:linear-gradient(145deg,#E8EDF7,#F0F3FA);
  border:1.5px solid rgba(0,0,0,.08); border-radius:10px; color:#0B1A30;
  font-size:16px; padding:11px 14px; outline:none; font-family:'DM Sans',sans-serif;
  transition:all .2s; box-sizing:border-box;
}
.ul-fi:focus { border-color:rgba(0,112,224,.35); box-shadow:0 0 0 3px rgba(0,112,224,.08); }
.ul-fi::placeholder { color:#94A3B8; }
.ul-fi--pfx { padding-left:10px; border-radius:0 10px 10px 0; }
.ul-fi-prefix { background:linear-gradient(145deg,#DCE4F3,#E8EEF8); border:1.5px solid rgba(0,0,0,.08); border-right:none; border-radius:10px 0 0 10px; padding:11px 10px 11px 14px; font-size:16px; color:#94A3B8; flex-shrink:0; }

.ul-fsel {
  width:100%; background:linear-gradient(145deg,#E8EDF7,#F0F3FA);
  border:1.5px solid rgba(0,0,0,.08); border-radius:10px; color:#0B1A30;
  font-size:16px; padding:11px 14px; outline:none; font-family:'DM Sans',sans-serif;
  transition:all .2s; cursor:pointer; box-sizing:border-box; appearance:none;
}
.ul-fsel:focus { border-color:rgba(0,112,224,.35); box-shadow:0 0 0 3px rgba(0,112,224,.08); }

.ul-pw-toggle { position:absolute; right:12px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; padding:4px; display:flex; align-items:center; }
.ul-pw-toggle svg { width:16px; height:16px; }

/* Role grid */
.ul-role-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.ul-role-opt {
  display:flex; flex-direction:column; gap:3px; padding:10px 12px;
  border-radius:10px; border:1.5px solid rgba(0,0,0,.08);
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC); cursor:pointer;
  text-align:left; transition:all .18s; min-height:58px;
}
.ul-ro-dot   { width:8px; height:8px; border-radius:50%; margin-bottom:3px; }
.ul-ro-label { font-size:11px; font-weight:700; color:#0B1A30; font-family:'DM Sans',sans-serif; }
.ul-ro-scope { font-size:9px; color:#94A3B8; }
.ul-role-opt.ul-ro--active { border-width:2px; box-shadow:0 2px 10px rgba(0,112,224,.12); }
.ul-ro--scr.ul-ro--active  { border-color:#00A86B; background:linear-gradient(135deg,#ECFDF5,#D1FAE5); }
.ul-ro--dist.ul-ro--active { border-color:#E02050; background:linear-gradient(135deg,#FEF2F2,#FECACA); }
.ul-ro--phoc.ul-ro--active { border-color:#7B40D8; background:linear-gradient(135deg,#F5F3FF,#EDE9FE); }
.ul-ro--nat.ul-ro--active  { border-color:#D63384; background:linear-gradient(135deg,#FDF2F8,#FCE7F3); }
.ul-rod--scr  { background:#00A86B; }
.ul-rod--dist { background:#E02050; }
.ul-rod--phoc { background:#7B40D8; }
.ul-rod--nat  { background:#D63384; }
.ul-rod--all  { background:#94A3B8; }

/* Status toggle */
.ul-sts-toggle     { display:flex; border:1.5px solid rgba(0,0,0,.08); border-radius:10px; overflow:hidden; }
.ul-sts-tbn        { flex:1; display:flex; align-items:center; justify-content:center; gap:7px; padding:11px; font-size:13px; font-weight:700; border:none; background:linear-gradient(145deg,#FFFFFF,#F4F7FC); color:#94A3B8; cursor:pointer; transition:all .18s; min-height:44px; font-family:'DM Sans',sans-serif; }
.ul-sts-tbn--active   { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); color:#00A86B; }
.ul-sts-tbn--inactive { background:linear-gradient(135deg,#FEF2F2,#FECACA); color:#E02050; }
.ul-stsdot       { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.ul-stsdot--green { background:#00A86B; }
.ul-stsdot--grey  { background:#94A3B8; }

/* Geo scope note */
.ul-geo-note {
  display:flex; align-items:flex-start; gap:7px; padding:10px 12px;
  border-radius:10px; margin-top:8px; font-size:10px; line-height:1.5; border:1.5px solid;
}
.ul-geo-note--prim,.ul-geo-note--sec,.ul-geo-note--data,.ul-geo-note--adm { background:linear-gradient(135deg,#E0ECFF,#CCE0FF); color:#0B1A30; border-color:rgba(0,112,224,.15); }
.ul-geo-note--dist { background:linear-gradient(135deg,#FEF2F2,#FECACA); color:#0B1A30; border-color:rgba(224,32,80,.15); }
.ul-geo-note--phoc { background:linear-gradient(135deg,#F5F3FF,#EDE9FE); color:#0B1A30; border-color:rgba(123,64,216,.15); }
.ul-geo-note--nat  { background:linear-gradient(145deg,#FFFFFF,#F4F7FC); color:#475569; border-color:rgba(0,0,0,.06); }
.ul-geo-note--all  { background:linear-gradient(145deg,#FFFFFF,#F4F7FC); color:#475569; border-color:rgba(0,0,0,.06); }

/* Submit button */
.ul-submit-btn {
  width:100%; display:flex; align-items:center; justify-content:center; gap:8px;
  padding:15px; border-radius:12px;
  background:linear-gradient(135deg,#0055CC,#0070E0,#3399FF);
  color:#fff; border:none; font-size:15px; font-weight:800; cursor:pointer;
  box-shadow:0 6px 24px rgba(0,112,224,.35), 0 2px 8px rgba(0,0,0,.1);
  font-family:'DM Sans',sans-serif; letter-spacing:.3px;
  transition:all .22s; min-height:52px; position:relative; overflow:hidden;
}
.ul-submit-btn::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.3) 50%,transparent 80%); }
.ul-submit-btn svg { width:18px; height:18px; }
.ul-submit-btn:hover  { transform:translateY(-1px); box-shadow:0 8px 30px rgba(0,112,224,.45); }
.ul-submit-btn:active { transform:scale(.98); }
.ul-submit-btn:disabled { opacity:.6; cursor:not-allowed; transform:none; }

/* ═══════════════════════════════════════════════════════════════════
   ACTION SHEET
═══════════════════════════════════════════════════════════════════ */
.ul-as-wrap { padding:0 14px 40px; }
.ul-as-handle { width:40px; height:4px; border-radius:2px; background:rgba(0,0,0,.08); margin:12px auto 16px; }
.ul-as-user-hdr {
  display:flex; align-items:center; gap:12px; padding:14px;
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:14px; margin-bottom:16px;
  box-shadow:0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  position:relative; overflow:hidden;
}
.ul-as-user-hdr::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.9) 50%,transparent 80%); }
.ul-as-user-info { flex:1; min-width:0; }
.ul-as-user-name { font-size:14px; font-weight:800; color:#0B1A30; font-family:'DM Sans',sans-serif; }
.ul-as-user-meta { font-size:10px; color:#94A3B8; font-family:'JetBrains Mono',monospace; }

.ul-as-actions { display:flex; flex-direction:column; gap:8px; margin-bottom:14px; }
.ul-as-action {
  display:flex; align-items:center; gap:12px;
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:14px; padding:13px;
  cursor:pointer; text-align:left; transition:all .18s;
  box-shadow:0 1px 3px rgba(0,0,0,.03); position:relative; overflow:hidden;
}
.ul-as-action::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.9) 50%,transparent 80%); }
.ul-as-action:hover { box-shadow:0 3px 10px rgba(0,30,80,.1); }
.ul-as-ic {
  width:42px; height:42px; border-radius:11px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; font-size:20px;
}
.ul-as-ic svg { width:18px; height:18px; }
.ul-as-ic--blue  { background:linear-gradient(135deg,#E0ECFF,#CCE0FF); }
.ul-as-ic--red   { background:linear-gradient(135deg,#FEF2F2,#FECACA); }
.ul-as-ic--green { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); }
.ul-as-ic--amber { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); }
.ul-as-body { flex:1; }
.ul-as-lbl  { display:block; font-size:13px; font-weight:700; color:#0B1A30; margin-bottom:2px; font-family:'DM Sans',sans-serif; }
.ul-as-sub  { display:block; font-size:10px; color:#94A3B8; }
.ul-as-lbl--red   { color:#E02050; }
.ul-as-lbl--green { color:#00A86B; }
.ul-as-lbl--amber { color:#CC8800; }
.ul-as-arr { font-size:18px; color:#94A3B8; font-weight:200; transition:color .15s; }
.ul-as-action:hover .ul-as-arr { color:#0070E0; }
.ul-as-cancel {
  width:100%; padding:14px; border-radius:12px;
  background:rgba(0,0,0,.04); border:1.5px solid rgba(0,0,0,.07);
  color:#94A3B8; font-size:13px; font-weight:700; cursor:pointer;
  font-family:'DM Sans',sans-serif; transition:all .15s;
}
.ul-as-cancel:hover { background:rgba(0,0,0,.07); }

/* IonTitle override — left-align the custom title block */
.ul-toolbar ion-title { --color: #EDF2FA; padding: 0; }
ion-title.ul-toolbar-title-slot { padding: 0 8px; }

/* IonButtons end slot padding */
.ul-icon-btn { margin: 0 2px; }

/* ul-hdr-band inherits dark background from .ul-header */
.ul-hdr-band { overflow: hidden; }
</style>