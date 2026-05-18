<template>
  <IonPage>

    <!-- ══════════════════════════════════════════════════════════════
         DARK ZONE — Header, Stats, Search, Filters
    ══════════════════════════════════════════════════════════════════ -->
    <IonHeader :translucent="false" class="ph-header">
      <IonToolbar class="ph-toolbar">
        <IonButtons slot="start">
          <IonBackButton default-href="/home" text="" style="--color:#00B4FF;" aria-label="Back"/>
        </IonButtons>
        <IonTitle>
          <div class="ph-title-block">
            <span class="ph-eyebrow">ECSA-HC · IHR 2005 · ARTICLE 23</span>
            <span class="ph-title">Points of Entry</span>
          </div>
        </IonTitle>
        <div slot="end" class="ph-network-badge">
          <span class="ph-network-dot"/>
          <span class="ph-network-n">{{ allPoes.length }}</span>
          <span class="ph-network-l">POEs</span>
        </div>
      </IonToolbar>

      <!-- Stats ribbon — dark zone, bright accents -->
      <div class="ph-stats-ribbon">
        <div class="ph-stats-texture"/>
        <div class="ph-stat">
          <span class="ph-stat-n">{{ allPoes.length }}</span>
          <span class="ph-stat-l">Total</span>
        </div>
        <div class="ph-stat-sep"/>
        <div class="ph-stat">
          <span class="ph-stat-n ph-stat-n--green">{{ landCount }}</span>
          <span class="ph-stat-l">Land</span>
        </div>
        <div class="ph-stat-sep"/>
        <div class="ph-stat">
          <span class="ph-stat-n ph-stat-n--blue">{{ airCount }}</span>
          <span class="ph-stat-l">Air</span>
        </div>
        <div class="ph-stat-sep"/>
        <div class="ph-stat">
          <span class="ph-stat-n ph-stat-n--teal">{{ waterCount }}</span>
          <span class="ph-stat-l">Water</span>
        </div>
        <div class="ph-stat-sep"/>
        <div class="ph-stat">
          <span class="ph-stat-n ph-stat-n--amber">{{ majorCount }}</span>
          <span class="ph-stat-l">Major</span>
        </div>
        <div class="ph-stat-sep"/>
        <div class="ph-stat">
          <span class="ph-stat-n ph-stat-n--purple">{{ osbpCount }}</span>
          <span class="ph-stat-l">OSBP</span>
        </div>
      </div>

      <!-- Search bar -->
      <div class="ph-search-zone">
        <div class="ph-search-bar" :class="searchQuery && 'ph-search-bar--active'">
          <svg class="ph-search-ic" viewBox="0 0 18 18" fill="none" stroke="#7E92AB" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
            <circle cx="7.5" cy="7.5" r="5.5"/><line x1="12" y1="12" x2="16" y2="16"/>
          </svg>
          <input
            v-model="searchQuery"
            class="ph-search-input"
            placeholder="Search by name, district, PHEOC, code…"
            aria-label="Search points of entry"
            autocomplete="off"
          />
          <button v-if="searchQuery" class="ph-search-clear" @click="searchQuery=''" aria-label="Clear search">✕</button>
        </div>
      </div>

      <!-- Mode filter chips -->
      <div class="ph-chip-row">
        <button
          v-for="m in modeFilters" :key="m.value"
          class="ph-chip"
          :class="['ph-chip--' + m.color, activeMode === m.value && 'ph-chip--active']"
          @click="activeMode = m.value"
          :aria-pressed="activeMode === m.value"
        >
          <!-- SVG per mode -->
          <svg v-if="m.value==='ALL'" class="ph-chip-ic" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
            <circle cx="7" cy="7" r="5.5"/><line x1="1.5" y1="7" x2="12.5" y2="7"/>
            <path d="M7 1.5 C9.5 3 11 5 11 7"/><path d="M7 1.5 C4.5 3 3 5 3 7"/>
          </svg>
          <svg v-else-if="m.value==='land'" class="ph-chip-ic" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
            <rect x="1" y="4" width="12" height="7" rx="1.5"/><path d="M1 7h12"/>
            <circle cx="3.5" cy="11" r="1.4"/><circle cx="10.5" cy="11" r="1.4"/>
          </svg>
          <svg v-else-if="m.value==='air'" class="ph-chip-ic" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
            <path d="M2 9L6 7 3 2 5 2 9 7 12 6.5C13.5 6.5 13.5 7.5 12 7.5L9 7 5.5 12 3.5 12 6 7"/>
          </svg>
          <svg v-else class="ph-chip-ic" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
            <path d="M1 10 C3 8.5 5 8.5 7 10 C9 11.5 11 11.5 13 10"/>
            <path d="M2 4L5 4 5 2 9 2 9 4 12 4 12 8 2 8Z"/>
          </svg>
          {{ m.label }}
          <em>{{ m.count }}</em>
        </button>
      </div>

      <!-- Flag chips -->
      <div class="ph-chip-row ph-chip-row--flags">
        <button
          v-for="f in flagFilters" :key="f.value"
          class="ph-chip ph-chip--flag"
          :class="activeFlag === f.value && 'ph-chip--active'"
          @click="activeFlag = f.value"
        >
          {{ f.label }} <em>{{ f.count }}</em>
        </button>
      </div>
    </IonHeader>

    <!-- ══════════════════════════════════════════════════════════════
         LIGHT ZONE — Content
    ══════════════════════════════════════════════════════════════════ -->
    <IonContent
      :fullscreen="true"
      :scroll-y="true"
      style="--background:linear-gradient(180deg,#EAF0FA 0%,#F2F5FB 40%,#E4EBF7 100%);--color:#0B1A30;"
    >
      <IonRefresher slot="fixed" @ionRefresh="e => setTimeout(() => e.target.complete(), 400)">
        <IonRefresherContent refreshing-spinner="crescent"/>
      </IonRefresher>

      <!-- Empty state -->
      <div v-if="!filteredPoes.length" class="ph-empty">
        <div class="ph-empty-icon">🗺</div>
        <div class="ph-empty-title">No POEs Found</div>
        <div class="ph-empty-body">Adjust your search or clear the filters.</div>
        <button v-if="hasActiveFilters" class="ph-empty-clear" @click="clearFilters" aria-label="Clear all filters">Clear Filters</button>
      </div>

      <!-- Grouped POE list -->
      <div v-else class="ph-list">

        <!-- Results count -->
        <div class="ph-results-bar">
          <span class="ph-results-text">
            {{ filteredPoes.length }} of {{ allPoes.length }} POEs
            <template v-if="hasActiveFilters"> · filtered</template>
          </span>
          <button v-if="hasActiveFilters" class="ph-results-clear" @click="clearFilters">Clear</button>
        </div>

        <template v-for="group in groupedPoes" :key="group.admin_level_1">

          <!-- Group header -->
          <div class="ph-group-hdr">
            <div class="ph-group-dot" :class="group.type === 'PHEOC' ? 'ph-group-dot--pheoc' : 'ph-group-dot--province'"/>
            <span class="ph-group-name">{{ group.admin_level_1 }}</span>
            <span class="ph-group-tag" :class="group.type === 'PHEOC' ? 'ph-group-tag--pheoc' : 'ph-group-tag--province'">
              {{ group.type === 'PHEOC' ? 'PHEOC' : 'Province' }}
            </span>
            <span class="ph-group-count">{{ group.poes.length }}</span>
          </div>

          <!-- POE cards -->
          <div
            v-for="(poe, i) in group.poes" :key="poe.id"
            class="ph-card"
            :style="{ animationDelay: i * 30 + 'ms' }"
            @click="openDetail(poe)"
            role="button"
            tabindex="0"
            :aria-label="poe.poe_name + ', ' + transportLabel(poe.transport_mode)"
          >
            <!-- Shimmer sweep -->
            <div class="ph-card-stream" aria-hidden="true"/>
            <!-- Left accent bar -->
            <div class="ph-card-bar" :class="'ph-card-bar--' + (poe.transport_mode || 'land')" aria-hidden="true"/>

            <div class="ph-card-body">
              <!-- Row 1: icon + name + badges -->
              <div class="ph-card-r1">
                <div class="ph-mode-icon" :class="'ph-mode-icon--' + (poe.transport_mode || 'land')" aria-label="Transport mode">
                  <svg v-if="poe.transport_mode==='land'" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
                    <rect x="1.5" y="5" width="15" height="9" rx="1.5"/><path d="M1.5 9h15"/>
                    <circle cx="4.5" cy="14" r="1.8"/><circle cx="13.5" cy="14" r="1.8"/>
                  </svg>
                  <svg v-else-if="poe.transport_mode==='air'" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
                    <path d="M2 11.5L7.5 9 4 3 6.5 3 12 9 15.5 8.5C17.5 8.5 17.5 9.5 15.5 9.5L12 9 7 15.5 4.5 15.5 7.5 9"/>
                  </svg>
                  <svg v-else viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
                    <path d="M1.5 13 C4.5 11 7 11 9 13 C11 15 13.5 15 16.5 13"/>
                    <path d="M3 5.5L6 5.5 6 3 12 3 12 5.5 15 5.5 15 11 3 11Z"/>
                  </svg>
                </div>
                <div class="ph-name-block">
                  <span class="ph-name">{{ poe.poe_name }}</span>
                  <span class="ph-type">{{ formatPoeType(poe.poe_type) }}</span>
                </div>
                <div class="ph-card-badges">
                  <span v-if="poe.is_major_entry"      class="ph-badge ph-badge--major">MAJOR</span>
                  <span v-if="poe.is_recommended_osbp" class="ph-badge ph-badge--osbp">OSBP</span>
                  <span v-if="poe.is_national_level"   class="ph-badge ph-badge--national">NATIONAL</span>
                </div>
              </div>
              <!-- Row 2: Geography -->
              <div class="ph-card-r2">
                <span class="ph-geo-pin" aria-hidden="true">📍</span>
                <span class="ph-district">{{ poe.district }}</span>
                <span class="ph-sep">·</span>
                <span class="ph-province">{{ poe.province }}</span>
              </div>
              <!-- Row 3: code + border -->
              <div class="ph-card-r3">
                <code v-if="poe.poe_code" class="ph-code">{{ poe.poe_code }}</code>
                <span v-if="poe.poe_code && poe.border_country" class="ph-sep">·</span>
                <span v-if="poe.border_country" class="ph-border">→ {{ poe.border_country }}</span>
                <span v-if="poe.critical_details" class="ph-has-notes">📋 Notes</span>
              </div>
            </div>
            <div class="ph-card-chevron" aria-hidden="true">›</div>
          </div>

        </template>

        <div style="height: max(env(safe-area-inset-bottom, 0px), 40px);" aria-hidden="true"/>
      </div>

      <!-- ══════════════════════════════════════════════════════════════
           ADMIN FAB — NATIONAL_ADMIN only.
           Positioned with safe-area-inset-bottom so Android gesture bars
           and iOS home indicators don't clip it; responsive scaling on
           narrow screens is handled in the stylesheet below.
      ══════════════════════════════════════════════════════════════════ -->
      <IonFab
        v-if="isAdmin" slot="fixed" vertical="bottom" horizontal="end"
        class="ph-fab-wrap" :class="{ 'fab-expanded': fabOpen }"
        :activated="fabOpen">
        <IonFabButton class="ph-fab-main" @click="fabOpen = !fabOpen" aria-label="Add reference data">
          <IonIcon :icon="addOutline"/>
        </IonFabButton>
        <IonFabList side="top" class="ph-fab-list">
          <IonFabButton
            class="ph-fab-action ph-fab-poe"
            data-label="Add POE"
            data-sublabel="Border post · airport · port"
            @click="openCreate('poe')"
            aria-label="Add POE">
            <IonIcon :icon="locationOutline"/>
          </IonFabButton>
          <IonFabButton
            class="ph-fab-action ph-fab-prov"
            data-label="Add Province / PHEOC"
            data-sublabel="Provincial emergency ops centre"
            @click="openCreate('province')"
            aria-label="Add Province / PHEOC">
            <IonIcon :icon="flagOutline"/>
          </IonFabButton>
          <IonFabButton
            class="ph-fab-action ph-fab-dist"
            data-label="Add District"
            data-sublabel="Administrative subdivision"
            @click="openCreate('district')"
            aria-label="Add District">
            <IonIcon :icon="mapOutline"/>
          </IonFabButton>
          <IonFabButton
            class="ph-fab-action ph-fab-hosp"
            data-label="Add Hospital"
            data-sublabel="Referral health facility"
            @click="openCreate('hospital')"
            aria-label="Add Hospital">
            <IonIcon :icon="medkitOutline"/>
          </IonFabButton>
        </IonFabList>
      </IonFab>
    </IonContent>

    <!-- ══════════════════════════════════════════════════════════════
         DETAIL MODAL — Full-screen, premium, every field from POEs.js
    ══════════════════════════════════════════════════════════════════ -->
    <IonModal
      :is-open="!!selectedPoe"
      :can-dismiss="true"
      :initial-breakpoint="1"
      :breakpoints="[0, 1]"
      @ionModalDidDismiss="selectedPoe = null"
    >
      <IonHeader :translucent="false" v-if="selectedPoe" class="pd-header">
        <IonToolbar style="--background:linear-gradient(180deg,#070E1B,#0E1A2E);--color:#EDF2FA;--border-width:0;">
          <IonButtons slot="start">
            <IonButton @click="selectedPoe = null" aria-label="Close" style="--color:#00B4FF;">
              <IonIcon :icon="closeOutline"/>
            </IonButton>
          </IonButtons>
          <IonTitle>
            <div class="pd-toolbar-title">
              <span class="pd-toolbar-eyebrow">🇺🇬 UGANDA · {{ transportLabel(selectedPoe.transport_mode).toUpperCase() }} POE</span>
              <span class="pd-toolbar-name">{{ selectedPoe.poe_name }}</span>
            </div>
          </IonTitle>
          <div v-if="isAdmin" slot="end" class="pd-admin-actions">
            <button class="pd-admin-btn pd-admin-btn--edit" @click="openEditPoe(selectedPoe)" aria-label="Edit POE">
              <IonIcon :icon="createOutline"/>
              <span>Edit</span>
            </button>
            <button class="pd-admin-btn pd-admin-btn--del" @click="openDeletePoe(selectedPoe)" aria-label="Delete POE">
              <IonIcon :icon="trashOutline"/>
            </button>
          </div>
          <div v-else slot="end" class="pd-readonly-tag">READ-ONLY</div>
        </IonToolbar>
      </IonHeader>

      <IonContent
        :scroll-y="true"
        v-if="selectedPoe"
        style="--background:linear-gradient(180deg,#EEF2FA 0%,#FFFFFF 50%,#F4F7FC 100%);--color:#0B1A30;"
      >
        <!-- ══ CINEMATIC HERO ════════════════════════════════════════ -->
        <div class="pd-hero" :class="'pd-hero--' + (selectedPoe.transport_mode || 'land')">
          <div class="pd-hero-texture" aria-hidden="true"/>
          <div class="pd-hero-orb"     aria-hidden="true"/>

          <!-- Large mode icon -->
          <div class="pd-hero-mode-icon" :class="'pd-hero-mode-icon--' + (selectedPoe.transport_mode || 'land')" aria-hidden="true">
            <svg v-if="selectedPoe.transport_mode==='land'" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
              <rect x="4" y="14" width="40" height="24" rx="4"/>
              <path d="M4 24h40"/>
              <circle cx="12" cy="38" r="4.5"/><circle cx="36" cy="38" r="4.5"/>
              <path d="M12 14v10M24 14v10"/>
            </svg>
            <svg v-else-if="selectedPoe.transport_mode==='air'" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
              <path d="M6 30L18 24 10 8 16 8 30 24 40 22.5C44 22.5 44 25.5 40 25.5L30 24 20 40 14 40 18 24"/>
            </svg>
            <svg v-else viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
              <path d="M4 34 C10 29 16 29 24 34 C32 39 38 39 44 34"/>
              <path d="M8 14L16 14 16 8 32 8 32 14 40 14 40 28 8 28Z"/>
            </svg>
          </div>

          <div class="pd-hero-content">
            <div class="pd-hero-top">
              <div class="pd-hero-mode-badge" :class="'pd-mode-badge--' + (selectedPoe.transport_mode || 'land')">
                {{ transportLabel(selectedPoe.transport_mode).toUpperCase() }}
              </div>
              <div v-if="selectedPoe.is_national_level" class="pd-hero-national-badge">🏛 NATIONAL</div>
            </div>

            <h1 class="pd-hero-name">{{ selectedPoe.poe_name }}</h1>
            <div class="pd-hero-type">{{ formatPoeType(selectedPoe.poe_type) }}</div>

            <!-- Classification flag row -->
            <div class="pd-hero-flags">
              <div v-if="selectedPoe.is_major_entry" class="pd-hero-flag pd-hero-flag--major">
                <span>⚑</span> Major Entry Point
              </div>
              <div v-if="selectedPoe.is_recommended_osbp" class="pd-hero-flag pd-hero-flag--osbp">
                <span>✦</span> One-Stop Border Post
              </div>
              <div v-if="selectedPoe.border_country" class="pd-hero-flag pd-hero-flag--border">
                <span>→</span> {{ selectedPoe.border_country }}
              </div>
            </div>
          </div>

          <!-- POE Code badge -->
          <div v-if="selectedPoe.poe_code" class="pd-hero-code-badge">
            <span class="pd-hero-code-label">POE CODE</span>
            <code class="pd-hero-code">{{ selectedPoe.poe_code }}</code>
          </div>
        </div>

        <!-- ══ BODY ═══════════════════════════════════════════════════ -->
        <div class="pd-body">

          <!-- ── 1. GEOGRAPHIC HIERARCHY ────────────────────────── -->
          <div class="pd-section">
            <div class="pd-section-label">
              <div class="pd-section-glyph">🌍</div>
              GEOGRAPHIC HIERARCHY
            </div>

            <!-- Hierarchy tree visualisation -->
            <div class="pd-hierarchy-tree">
              <!-- Country node -->
              <div class="pd-tree-node pd-tree-node--country">
                <div class="pd-tree-node-icon pd-tree-icon--country">🌐</div>
                <div class="pd-tree-node-body">
                  <div class="pd-tree-node-key">Country</div>
                  <div class="pd-tree-node-val">🇺🇬 Uganda</div>
                </div>
              </div>
              <div class="pd-tree-connector"/>

              <!-- PHEOC / Province node -->
              <div class="pd-tree-node pd-tree-node--pheoc">
                <div class="pd-tree-node-icon pd-tree-icon--pheoc">🏥</div>
                <div class="pd-tree-node-body">
                  <div class="pd-tree-node-key">{{ selectedPoe.admin_level_1_type === 'PHEOC' ? 'Provincial PHEOC' : 'Province' }}</div>
                  <div class="pd-tree-node-val">{{ selectedPoe.admin_level_1 || selectedPoe.province }}</div>
                  <div v-if="selectedPoe.admin_level_1_type === 'PHEOC'" class="pd-tree-node-tag">PHEOC</div>
                </div>
              </div>
              <div class="pd-tree-connector"/>

              <!-- District node -->
              <div class="pd-tree-node pd-tree-node--district">
                <div class="pd-tree-node-icon pd-tree-icon--district">📌</div>
                <div class="pd-tree-node-body">
                  <div class="pd-tree-node-key">District</div>
                  <div class="pd-tree-node-val">{{ selectedPoe.district }}</div>
                  <div v-if="selectedPoe.district_raw && selectedPoe.district_raw !== selectedPoe.district" class="pd-tree-node-sub">Raw: {{ selectedPoe.district_raw }}</div>
                </div>
              </div>
              <div class="pd-tree-connector"/>

              <!-- POE node — terminal -->
              <div class="pd-tree-node pd-tree-node--poe" :class="'pd-tree-node--' + (selectedPoe.transport_mode || 'land')">
                <div class="pd-tree-node-icon" :class="'pd-tree-icon--' + (selectedPoe.transport_mode || 'land')">
                  <svg v-if="selectedPoe.transport_mode==='land'" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                    <rect x="1" y="4" width="14" height="9" rx="1.5"/><path d="M1 8h14"/>
                    <circle cx="4" cy="13" r="1.6"/><circle cx="12" cy="13" r="1.6"/>
                  </svg>
                  <svg v-else-if="selectedPoe.transport_mode==='air'" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                    <path d="M2 10L5.5 8 3 2.5 5 2.5 9 8 11.5 7.5C13 7.5 13 8.5 11.5 8.5L9 8 6 13 4 13 5.5 8"/>
                  </svg>
                  <svg v-else viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                    <path d="M1 11.5C3 10 5 10 8 11.5C11 13 13 13 15 11.5"/>
                    <path d="M2.5 5L5 5 5 3 11 3 11 5 13.5 5 13.5 9 2.5 9Z"/>
                  </svg>
                </div>
                <div class="pd-tree-node-body">
                  <div class="pd-tree-node-key">Point of Entry</div>
                  <div class="pd-tree-node-val pd-tree-node-val--poe">{{ selectedPoe.poe_name }}</div>
                  <div v-if="selectedPoe.regional_cluster_or_rpheoc" class="pd-tree-node-sub">
                    Cluster: {{ selectedPoe.regional_cluster_or_rpheoc }}
                  </div>
                </div>
                <div class="pd-tree-terminal-dot"/>
              </div>
            </div>

            <!-- Border country card (if present) -->
            <div v-if="selectedPoe.border_country" class="pd-border-card">
              <div class="pd-border-card-shine"/>
              <div class="pd-border-icon">⇄</div>
              <div class="pd-border-body">
                <div class="pd-border-key">Bordering Country</div>
                <div class="pd-border-val">{{ selectedPoe.border_country }}</div>
              </div>
            </div>
          </div>

          <!-- ── 2. IHR CLASSIFICATION FLAGS ───────────────────── -->
          <div class="pd-section">
            <div class="pd-section-label">
              <div class="pd-section-glyph">🏛</div>
              IHR CLASSIFICATION FLAGS
            </div>
            <div class="pd-flags-grid">
              <div class="pd-flag-card" :class="selectedPoe.is_major_entry ? 'pd-flag-card--on pd-flag-card--major' : 'pd-flag-card--off'">
                <div class="pd-flag-card-shine"/>
                <div class="pd-flag-icon">⚑</div>
                <div class="pd-flag-label">Major Entry Point</div>
                <div class="pd-flag-status">{{ selectedPoe.is_major_entry ? 'DESIGNATED' : 'NOT DESIGNATED' }}</div>
                <div class="pd-flag-desc">{{ selectedPoe.is_major_entry ? 'Full IHR Annex 1B capacity required. Priority screening facilities.' : 'Standard surveillance capacity applies.' }}</div>
              </div>
              <div class="pd-flag-card" :class="selectedPoe.is_recommended_osbp ? 'pd-flag-card--on pd-flag-card--osbp' : 'pd-flag-card--off'">
                <div class="pd-flag-card-shine"/>
                <div class="pd-flag-icon">✦</div>
                <div class="pd-flag-label">OSBP Status</div>
                <div class="pd-flag-status">{{ selectedPoe.is_recommended_osbp ? 'OPERATIONAL' : 'NOT OSBP' }}</div>
                <div class="pd-flag-desc">{{ selectedPoe.is_recommended_osbp ? 'One-Stop Border Post. Joint health processing with neighboring country.' : 'Standard single-country border processing.' }}</div>
              </div>
              <div class="pd-flag-card" :class="selectedPoe.is_national_level ? 'pd-flag-card--on pd-flag-card--national' : 'pd-flag-card--off'">
                <div class="pd-flag-card-shine"/>
                <div class="pd-flag-icon">🏛</div>
                <div class="pd-flag-label">National Level</div>
                <div class="pd-flag-status">{{ selectedPoe.is_national_level ? 'NATIONAL PHEOC' : 'SUB-NATIONAL' }}</div>
                <div class="pd-flag-desc">{{ selectedPoe.is_national_level ? 'Reports directly to National PHEOC. Highest alert routing.' : 'Reports through Provincial PHEOC hierarchy.' }}</div>
              </div>
            </div>
          </div>

          <!-- ── 3. NETWORK POSITION ───────────────────────────── -->
          <div class="pd-section">
            <div class="pd-section-label">
              <div class="pd-section-glyph">📡</div>
              NETWORK POSITION
            </div>
            <div class="pd-network-card">
              <div class="pd-network-card-shine"/>
              <div class="pd-network-stats">
                <div class="pd-net-stat">
                  <div class="pd-net-stat-n">{{ allPoes.length }}</div>
                  <div class="pd-net-stat-l">National POEs</div>
                </div>
                <div class="pd-net-stat-sep"/>
                <div class="pd-net-stat">
                  <div class="pd-net-stat-n">{{ siblingsByRpheoc.length }}</div>
                  <div class="pd-net-stat-l">Same PHEOC</div>
                </div>
                <div class="pd-net-stat-sep"/>
                <div class="pd-net-stat">
                  <div class="pd-net-stat-n">{{ siblingsByDistrict.length }}</div>
                  <div class="pd-net-stat-l">Same District</div>
                </div>
                <div class="pd-net-stat-sep"/>
                <div class="pd-net-stat">
                  <div class="pd-net-stat-n" :class="'pd-net-mode--' + (selectedPoe.transport_mode || 'land')">
                    {{ transportLabel(selectedPoe.transport_mode) }}
                  </div>
                  <div class="pd-net-stat-l">Mode</div>
                </div>
              </div>

              <!-- Reporting chain -->
              <div class="pd-chain">
                <div class="pd-chain-label">IHR REPORTING CHAIN</div>
                <div class="pd-chain-nodes">
                  <div class="pd-chain-node pd-chain-node--poe">
                    <div class="pd-chain-dot pd-chain-dot--active"/>
                    <span>This POE</span>
                  </div>
                  <div class="pd-chain-arrow">→</div>
                  <div class="pd-chain-node">
                    <div class="pd-chain-dot"/>
                    <span>{{ selectedPoe.admin_level_1 || 'PHEOC' }}</span>
                  </div>
                  <div class="pd-chain-arrow">→</div>
                  <div class="pd-chain-node">
                    <div class="pd-chain-dot"/>
                    <span>National PHEOC</span>
                  </div>
                  <div class="pd-chain-arrow">→</div>
                  <div class="pd-chain-node">
                    <div class="pd-chain-dot"/>
                    <span>WHO IHR FP</span>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- ── 4. SIBLING POEs (same PHEOC) ─────────────────── -->
          <div v-if="siblingsByRpheoc.length > 0" class="pd-section">
            <div class="pd-section-label">
              <div class="pd-section-glyph">🔗</div>
              OTHER POEs IN {{ (selectedPoe.admin_level_1 || 'THIS PHEOC').toUpperCase() }}
            </div>
            <div class="pd-siblings">
              <div
                v-for="sib in siblingsByRpheoc.slice(0, 8)"
                :key="sib.id"
                class="pd-sibling-card"
                @click="selectedPoe = sib"
                role="button"
                tabindex="0"
              >
                <div class="pd-sibling-bar" :class="'pd-sib-bar--' + (sib.transport_mode || 'land')"/>
                <div class="pd-sibling-body">
                  <div class="pd-sibling-name">{{ sib.poe_name }}</div>
                  <div class="pd-sibling-meta">{{ sib.district }} · {{ transportLabel(sib.transport_mode) }}</div>
                </div>
                <div class="pd-sibling-badges">
                  <span v-if="sib.is_major_entry" class="pd-sib-badge pd-sib-badge--major">M</span>
                  <span v-if="sib.is_recommended_osbp" class="pd-sib-badge pd-sib-badge--osbp">O</span>
                </div>
              </div>
              <div v-if="siblingsByRpheoc.length > 8" class="pd-siblings-more">
                +{{ siblingsByRpheoc.length - 8 }} more in this PHEOC
              </div>
            </div>
          </div>

          <!-- ── 5. OPERATIONAL INTELLIGENCE ───────────────────── -->
          <div class="pd-section">
            <div class="pd-section-label">
              <div class="pd-section-glyph">⚙</div>
              OPERATIONAL INTELLIGENCE
            </div>

            <div class="pd-ops-grid">
              <div class="pd-ops-card">
                <div class="pd-ops-card-shine"/>
                <div class="pd-ops-label">POE Type</div>
                <div class="pd-ops-value">{{ formatPoeType(selectedPoe.poe_type) }}</div>
                <div class="pd-ops-sub">{{ selectedPoe.poe_type }}</div>
              </div>
              <div class="pd-ops-card">
                <div class="pd-ops-card-shine"/>
                <div class="pd-ops-label">Transport Mode</div>
                <div class="pd-ops-value" :class="'pd-ops-mode--' + (selectedPoe.transport_mode || 'land')">{{ transportLabel(selectedPoe.transport_mode) }}</div>
                <div class="pd-ops-sub">Primary screening mode</div>
              </div>
              <div class="pd-ops-card">
                <div class="pd-ops-card-shine"/>
                <div class="pd-ops-label">Country</div>
                <div class="pd-ops-value">🇺🇬 Uganda</div>
                <div class="pd-ops-sub">Active IHR State Party</div>
              </div>
              <div class="pd-ops-card">
                <div class="pd-ops-card-shine"/>
                <div class="pd-ops-label">Data Origin</div>
                <div class="pd-ops-value" style="font-size:12px;">{{ selectedPoe.source_origin || 'POES.js Reference File' }}</div>
                <div class="pd-ops-sub">Hardcoded — offline capable</div>
              </div>
            </div>

            <!-- Critical / operational notes -->
            <div v-if="selectedPoe.critical_details" class="pd-notes-card">
              <div class="pd-notes-card-shine"/>
              <div class="pd-notes-hdr">
                <span class="pd-notes-ic">📋</span>
                <span class="pd-notes-title">Operational Notes</span>
              </div>
              <div class="pd-notes-text">{{ selectedPoe.critical_details }}</div>
            </div>

            <!-- Traveler tip if this POE matches -->
            <div v-if="travelerTip" class="pd-traveler-tip">
              <div class="pd-traveler-tip-shine"/>
              <div class="pd-traveler-hdr">✈ ROUTE TIP</div>
              <div class="pd-traveler-text">{{ travelerTip }}</div>
            </div>
          </div>

          <!-- ── 6. REFERENCE DATA IDENTIFIERS ──────────────────── -->
          <div class="pd-section">
            <div class="pd-section-label">
              <div class="pd-section-glyph">🔑</div>
              REFERENCE DATA IDENTIFIERS
            </div>

            <div class="pd-ref-table">
              <div class="pd-ref-row">
                <span class="pd-ref-key">Record ID (POES.js)</span>
                <code class="pd-ref-val pd-ref-val--id">{{ selectedPoe.id }}</code>
              </div>
              <div v-if="selectedPoe.poe_code" class="pd-ref-row">
                <span class="pd-ref-key">POE Code (DB primary)</span>
                <code class="pd-ref-val pd-ref-val--code">{{ selectedPoe.poe_code }}</code>
              </div>
              <div v-if="selectedPoe.poe_name" class="pd-ref-row">
                <span class="pd-ref-key">POE Name (DB field)</span>
                <code class="pd-ref-val">{{ selectedPoe.poe_name }}</code>
              </div>
              <div class="pd-ref-row">
                <span class="pd-ref-key">Province Code (record stamp)</span>
                <code class="pd-ref-val">{{ selectedPoe.province }}</code>
              </div>
              <div class="pd-ref-row">
                <span class="pd-ref-key">District Code (record stamp)</span>
                <code class="pd-ref-val">{{ selectedPoe.district }}</code>
              </div>
              <div class="pd-ref-row">
                <span class="pd-ref-key">Admin Level 1</span>
                <code class="pd-ref-val">{{ selectedPoe.admin_level_1 }}</code>
              </div>
              <div class="pd-ref-row">
                <span class="pd-ref-key">Admin Level 1 Type</span>
                <code class="pd-ref-val">{{ selectedPoe.admin_level_1_type }}</code>
              </div>
              <div v-if="selectedPoe.regional_cluster_or_rpheoc" class="pd-ref-row">
                <span class="pd-ref-key">PHEOC Cluster</span>
                <code class="pd-ref-val">{{ selectedPoe.regional_cluster_or_rpheoc }}</code>
              </div>
              <div v-if="selectedPoe.source_province_group" class="pd-ref-row">
                <span class="pd-ref-key">Source Province Group</span>
                <code class="pd-ref-val">{{ selectedPoe.source_province_group }}</code>
              </div>
              <div v-if="selectedPoe.source_url" class="pd-ref-row">
                <span class="pd-ref-key">Source Reference</span>
                <code class="pd-ref-val pd-ref-val--url">{{ selectedPoe.source_url }}</code>
              </div>
            </div>
          </div>

          <!-- ── 7. DATA PROVENANCE ──────────────────────────────── -->
          <div class="pd-section">
            <div class="pd-section-label">
              <div class="pd-section-glyph">📊</div>
              DATASET PROVENANCE
            </div>
            <div class="pd-provenance-card">
              <div class="pd-provenance-card-shine"/>
              <div class="pd-prov-row">
                <span class="pd-prov-key">Dataset</span>
                <span class="pd-prov-val">{{ metadata.dataset_name }}</span>
              </div>
              <div class="pd-prov-row">
                <span class="pd-prov-key">Schema version</span>
                <code class="pd-prov-code">{{ metadata.schema_version }}</code>
              </div>
              <div class="pd-prov-row">
                <span class="pd-prov-key">Created</span>
                <span class="pd-prov-val">{{ metadata.created_from_user_supplied_text_on }}</span>
              </div>
              <div class="pd-prov-row">
                <span class="pd-prov-key">Uganda POEs total</span>
                <span class="pd-prov-val">{{ metadata.country_entry_counts?.Uganda || 35 }}</span>
              </div>
              <div class="pd-prov-row">
                <span class="pd-prov-key">App ref version</span>
                <code class="pd-prov-code">rda-2026-02-01</code>
              </div>
            </div>

            <!-- Data quality notes -->
            <div v-if="metadata.data_quality_notes?.length" class="pd-quality-notes">
              <div class="pd-quality-hdr">DATA QUALITY NOTES</div>
              <div v-for="(note, i) in metadata.data_quality_notes" :key="i" class="pd-quality-row">
                <span class="pd-quality-num">{{ i + 1 }}</span>
                <span class="pd-quality-text">{{ note }}</span>
              </div>
            </div>
          </div>

          <!-- ── 8. IHR COMPLIANCE BLOCK ─────────────────────────── -->
          <div class="pd-section">
            <div class="pd-section-label">
              <div class="pd-section-glyph">🛡</div>
              WHO / IHR 2005 COMPLIANCE
            </div>
            <div class="pd-ihr-block">
              <div class="pd-ihr-block-shine"/>
              <div class="pd-ihr-title">IHR 2005 · Article 23 — Health Measures on Arrival and Departure</div>
              <div class="pd-ihr-text">
                This Point of Entry is part of the Uganda national IHR surveillance network. It operates under IHR 2005 Article 23, which grants States Parties the authority to require health measures for travelers. Primary screening at this POE implements Annex 1B capacity requirements. All records captured here are stamped with this POE's geographic codes and are subject to the national IDSR reporting pathway.
              </div>
              <div class="pd-ihr-articles">
                <div class="pd-ihr-article">
                  <span class="pd-ihr-art-num">Art. 23</span>
                  <span class="pd-ihr-art-text">Health measures on arrival and departure</span>
                </div>
                <div class="pd-ihr-article">
                  <span class="pd-ihr-art-num">Annex 1B</span>
                  <span class="pd-ihr-art-text">Core capacity requirements for designated POEs</span>
                </div>
                <div class="pd-ihr-article">
                  <span class="pd-ihr-art-num">Art. 44</span>
                  <span class="pd-ihr-art-text">Collaborate in detection and response to PHEIC</span>
                </div>
              </div>
              <div class="pd-ihr-offline-notice">
                <span class="pd-ihr-offline-dot"/>
                Reference data is hardcoded in the app and does not require network access. poe_code in all screening records must exactly match this entry's poe_code field.
              </div>
            </div>
          </div>

        </div>

        <div style="height: max(env(safe-area-inset-bottom, 0px), 40px);" aria-hidden="true"/>
      </IonContent>
    </IonModal>

    <!-- ══════════════════════════════════════════════════════════════
         ADMIN EDITOR MODAL — Create / Update for every entity.
         Only the minimum input surface is shown; every other field is
         auto-derived server-side so the bundle shape cannot drift.
    ══════════════════════════════════════════════════════════════════ -->
    <IonModal :is-open="editor.open" :can-dismiss="!editor.saving" @ionModalDidDismiss="editor.open=false">
      <IonHeader :translucent="false" class="pe-header">
        <IonToolbar class="pe-toolbar">
          <IonTitle>
            <div class="pe-title-block">
              <span class="pe-eyebrow">{{ editor.mode === 'create' ? 'NEW' : 'EDIT' }} · ADMIN</span>
              <span class="pe-title">{{ entityLabel(editor.entity) }}</span>
            </div>
          </IonTitle>
          <IonButtons slot="end">
            <IonButton @click="closeEditor" :disabled="editor.saving" style="--color:#00B4FF;" aria-label="Close editor">
              <IonIcon :icon="closeOutline"/>
            </IonButton>
          </IonButtons>
        </IonToolbar>
      </IonHeader>

      <IonContent class="pe-body" :scroll-y="true">
        <!-- ── POE form (native HTML form controls — matches PoeContactsAdmin convention) ── -->
        <div v-if="editor.entity==='poe'" class="pe-form">
          <div class="pe-field">
            <label class="pe-label">Name <span class="pe-req">*</span></label>
            <input v-model.trim="editor.form.poe_name" class="pe-inp" placeholder="e.g. Kazungula Road"/>
            <div v-if="editor.errors.poe_name" class="pe-error">{{ editor.errors.poe_name }}</div>
          </div>
          <div class="pe-field">
            <label class="pe-label">POE Type <span class="pe-req">*</span></label>
            <select v-model="editor.form.poe_type" class="pe-inp">
              <option v-for="t in poeTypeOptions" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
            <div class="pe-hint">Transport mode is auto-derived from POE type.</div>
          </div>
          <div class="pe-field">
            <label class="pe-label">PHEOC / Province <span class="pe-req">*</span></label>
            <SearchableSelect
              v-model="editor.form.province_id"
              :options="provincesList"
              value-key="id"
              label-key="name"
              placeholder="— Select PHEOC —"
              search-placeholder="Search PHEOC / provinces…"
              select-class="pe-inp"
            />
            <div v-if="editor.errors.province_id" class="pe-error">{{ editor.errors.province_id }}</div>
          </div>
          <div class="pe-field">
            <label class="pe-label">District <span class="pe-req">*</span></label>
            <SearchableSelect
              v-model="editor.form.district_id"
              :options="districtsForEditorProvince"
              value-key="id"
              label-key="name"
              placeholder="— Select District —"
              search-placeholder="Search districts…"
              select-class="pe-inp"
              :disabled="!editor.form.province_id"
            />
            <div v-if="editor.errors.district_id" class="pe-error">{{ editor.errors.district_id }}</div>
          </div>
          <div class="pe-field">
            <label class="pe-label">Border Country <span class="pe-optional">(leave blank for internal)</span></label>
            <input v-model.trim="editor.form.border_country" class="pe-inp" placeholder="e.g. Zimbabwe"/>
          </div>
          <div class="pe-field pe-field--toggles">
            <IonItem class="pe-toggle-item" lines="none">
              <IonLabel>Major Entry Point</IonLabel>
              <IonToggle v-model="editor.form.is_major_entry" slot="end"/>
            </IonItem>
            <IonItem class="pe-toggle-item" lines="none">
              <IonLabel>One-Stop Border Post (OSBP)</IonLabel>
              <IonToggle v-model="editor.form.is_recommended_osbp" slot="end"/>
            </IonItem>
            <IonItem class="pe-toggle-item" lines="none">
              <IonLabel>National-Level POE</IonLabel>
              <IonToggle v-model="editor.form.is_national_level" slot="end"/>
            </IonItem>
            <IonItem class="pe-toggle-item" lines="none">
              <IonLabel>Active</IonLabel>
              <IonToggle v-model="editor.form.is_active" slot="end"/>
            </IonItem>
          </div>
          <div class="pe-field">
            <label class="pe-label">Operational Notes <span class="pe-optional">(optional)</span></label>
            <textarea v-model="editor.form.critical_details" rows="4" class="pe-inp pe-inp--area" placeholder="Context, facilities, cross-border arrangements…"/>
          </div>
          <div class="pe-field">
            <label class="pe-label">Source URL <span class="pe-optional">(gazette / policy link)</span></label>
            <input v-model.trim="editor.form.source_url" class="pe-inp" placeholder="https://…"/>
          </div>
          <div class="pe-derived">
            <span class="pe-derived-hdr">🔒 Server-derived fields</span>
            <span class="pe-derived-list">country · province · admin_level_1 · admin_level_1_type · district · district_raw · regional_cluster_or_rpheoc · source_province_group · transport_mode · source_origin · id (external)</span>
          </div>
        </div>

        <!-- ── Province form ──────────────────────────────────── -->
        <div v-else-if="editor.entity==='province'" class="pe-form">
          <div class="pe-field">
            <label class="pe-label">Name <span class="pe-req">*</span></label>
            <input v-model.trim="editor.form.name" class="pe-inp" placeholder="e.g. National PHEOC"/>
            <div v-if="editor.errors.name" class="pe-error">{{ editor.errors.name }}</div>
          </div>
          <div class="pe-field">
            <label class="pe-label">Type</label>
            <select v-model="editor.form.admin_level_1_type" class="pe-inp">
              <option v-for="t in provinceTypeOptions" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </div>
          <div class="pe-field pe-field--toggles">
            <IonItem class="pe-toggle-item" lines="none">
              <IonLabel>Active</IonLabel>
              <IonToggle v-model="editor.form.is_active" slot="end"/>
            </IonItem>
          </div>
          <div class="pe-derived">
            <span class="pe-derived-hdr">🔒 Server-derived fields</span>
            <span class="pe-derived-list">country_code · code (slug) · display_order</span>
          </div>
        </div>

        <!-- ── District form ──────────────────────────────────── -->
        <div v-else-if="editor.entity==='district'" class="pe-form">
          <div class="pe-field">
            <label class="pe-label">Name <span class="pe-req">*</span></label>
            <input v-model.trim="editor.form.name" class="pe-inp" placeholder="e.g. Kalumbila District"/>
            <div v-if="editor.errors.name" class="pe-error">{{ editor.errors.name }}</div>
          </div>
          <div class="pe-field">
            <label class="pe-label">PHEOC / Province <span class="pe-req">*</span></label>
            <SearchableSelect
              v-model="editor.form.province_id"
              :options="provincesList"
              value-key="id"
              label-key="name"
              placeholder="— Select PHEOC —"
              search-placeholder="Search PHEOC / provinces…"
              select-class="pe-inp"
            />
            <div v-if="editor.errors.province_id" class="pe-error">{{ editor.errors.province_id }}</div>
          </div>
          <div class="pe-field pe-field--toggles">
            <IonItem class="pe-toggle-item" lines="none">
              <IonLabel>Active</IonLabel>
              <IonToggle v-model="editor.form.is_active" slot="end"/>
            </IonItem>
          </div>
          <div class="pe-derived">
            <span class="pe-derived-hdr">🔒 Server-derived fields</span>
            <span class="pe-derived-list">country_code · code (slug) · name_raw (District suffix stripped) · display_order</span>
          </div>
        </div>

        <!-- ── Hospital form ──────────────────────────────────── -->
        <div v-else-if="editor.entity==='hospital'" class="pe-form">
          <div class="pe-field">
            <label class="pe-label">Name <span class="pe-req">*</span></label>
            <input v-model.trim="editor.form.name" class="pe-inp" placeholder="e.g. Ndola Teaching Hospital"/>
            <div v-if="editor.errors.name" class="pe-error">{{ editor.errors.name }}</div>
          </div>
          <div class="pe-field">
            <label class="pe-label">Type</label>
            <select v-model="editor.form.hospital_type" class="pe-inp">
              <option v-for="t in hospitalTypeOptions" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </div>
          <div class="pe-field">
            <label class="pe-label">PHEOC / Province <span class="pe-req">*</span></label>
            <select v-model.number="editor.form.province_id" class="pe-inp">
              <option value="">— Select PHEOC —</option>
              <option v-for="p in provincesList" :key="p.id" :value="p.id">{{ p.name }}</option>
            </select>
            <div v-if="editor.errors.province_id" class="pe-error">{{ editor.errors.province_id }}</div>
          </div>
          <div class="pe-field">
            <label class="pe-label">District <span class="pe-optional">(optional)</span></label>
            <select v-model="editor.form.district_id" class="pe-inp" :disabled="!editor.form.province_id">
              <option :value="null">— None —</option>
              <option v-for="d in districtsForEditorProvince" :key="d.id" :value="d.id">{{ d.name }}</option>
            </select>
          </div>
          <div class="pe-field">
            <label class="pe-label">Phone</label>
            <input v-model.trim="editor.form.phone" class="pe-inp" placeholder="+256 …"/>
          </div>
          <div class="pe-field">
            <label class="pe-label">Address</label>
            <textarea v-model="editor.form.address" rows="3" class="pe-inp pe-inp--area"/>
          </div>
          <div class="pe-field pe-field--toggles">
            <IonItem class="pe-toggle-item" lines="none">
              <IonLabel>National-level facility</IonLabel>
              <IonToggle v-model="editor.form.is_national_level" slot="end"/>
            </IonItem>
            <IonItem class="pe-toggle-item" lines="none">
              <IonLabel>Active</IonLabel>
              <IonToggle v-model="editor.form.is_active" slot="end"/>
            </IonItem>
          </div>
        </div>

        <div class="pe-actions">
          <IonButton expand="block" shape="round" :disabled="editor.saving" @click="saveEditor" class="pe-save-btn">
            <IonSpinner v-if="editor.saving" name="crescent" class="pe-spinner"/>
            <IonIcon v-else :icon="saveOutline" slot="start"/>
            <span>{{ editor.saving ? 'Saving…' : (editor.mode === 'create' ? 'Create' : 'Save Changes') }}</span>
          </IonButton>
          <IonButton expand="block" fill="clear" :disabled="editor.saving" @click="closeEditor" class="pe-cancel-btn">Cancel</IonButton>
        </div>
        <div style="height: max(env(safe-area-inset-bottom, 0px), 40px);" aria-hidden="true"/>
      </IonContent>
    </IonModal>

    <!-- ══════════════════════════════════════════════════════════════
         DELETE CONFIRM + TOAST
    ══════════════════════════════════════════════════════════════════ -->
    <IonAlert
      :is-open="confirmDel.open"
      :header="'Delete ' + entityLabel(confirmDel.entity).toLowerCase()"
      :sub-header="confirmDel.label"
      message="This action is permanent. Dependents (districts, POEs) must be removed first. Continue?"
      :buttons="[
        { text: 'Cancel', role: 'cancel', handler: () => { confirmDel.open = false; return true } },
        { text: 'Delete',  role: 'destructive', handler: () => { doDelete(); return false } }
      ]"
      @didDismiss="confirmDel.open = false"
    />

    <IonToast
      :is-open="toast.show"
      :message="toast.msg"
      :color="toast.color"
      :duration="2800"
      position="top"
      @didDismiss="toast.show = false"
    />

  </IonPage>
</template>

<script setup>
/**
 * POEs.vue — Points of Entry Reference View
 * READ-ONLY · No poeDB · No TypeScript · No network calls
 * Data: window.POE_MAIN (POES.js) — hardcoded reference data
 */
import { ref, computed, watch } from 'vue'
import SearchableSelect from '../components/SearchableSelect.vue'
import {
  IonPage, IonHeader, IonToolbar, IonTitle, IonButtons, IonBackButton,
  IonContent, IonRefresher, IonRefresherContent, IonModal, IonButton, IonIcon,
  IonFab, IonFabButton, IonFabList, IonToast, IonAlert,
  IonToggle, IonItem, IonLabel, IonSpinner,
  onIonViewDidEnter,
} from '@ionic/vue'
import {
  closeOutline, addOutline, createOutline, trashOutline,
  saveOutline, locationOutline, flagOutline, mapOutline, medkitOutline,
} from 'ionicons/icons'

// ── State ────────────────────────────────────────────────────────────────
const searchQuery = ref('')
const activeMode  = ref('ALL')
const activeFlag  = ref('ALL')
const selectedPoe = ref(null)

// ── Data from window.POE_MAIN ─────────────────────────────────────────────
const allPoes = computed(() => {
  const D = window.POE_MAIN
  if (!D || !Array.isArray(D.poes)) return []
  return D.poes.filter(p => p.country === 'Uganda')
})

const metadata = computed(() => window.POE_MAIN?.metadata || {})

// ── Counts ────────────────────────────────────────────────────────────────
const landCount     = computed(() => allPoes.value.filter(p => p.transport_mode === 'land').length)
const airCount      = computed(() => allPoes.value.filter(p => p.transport_mode === 'air').length)
const waterCount    = computed(() => allPoes.value.filter(p => p.transport_mode === 'water').length)
const majorCount    = computed(() => allPoes.value.filter(p => p.is_major_entry).length)
const osbpCount     = computed(() => allPoes.value.filter(p => p.is_recommended_osbp).length)
const nationalCount = computed(() => allPoes.value.filter(p => p.is_national_level).length)

// ── Filters ───────────────────────────────────────────────────────────────
const modeFilters = computed(() => [
  { value: 'ALL',   label: 'All',   color: 'all',   count: allPoes.value.length },
  { value: 'land',  label: 'Land',  color: 'land',  count: landCount.value },
  { value: 'air',   label: 'Air',   color: 'air',   count: airCount.value },
  { value: 'water', label: 'Water', color: 'water', count: waterCount.value },
])

const flagFilters = computed(() => [
  { value: 'ALL',      label: 'All Types', count: allPoes.value.length },
  { value: 'major',    label: 'Major',     count: majorCount.value },
  { value: 'osbp',     label: 'OSBP',      count: osbpCount.value },
  { value: 'national', label: 'National',  count: nationalCount.value },
])

const hasActiveFilters = computed(() =>
  activeMode.value !== 'ALL' || activeFlag.value !== 'ALL' || !!searchQuery.value.trim()
)

const filteredPoes = computed(() => {
  let list = allPoes.value
  if (activeMode.value !== 'ALL') list = list.filter(p => p.transport_mode === activeMode.value)
  if (activeFlag.value === 'major')    list = list.filter(p => p.is_major_entry)
  if (activeFlag.value === 'osbp')     list = list.filter(p => p.is_recommended_osbp)
  if (activeFlag.value === 'national') list = list.filter(p => p.is_national_level)
  const q = searchQuery.value.trim().toLowerCase()
  if (q) list = list.filter(p =>
    (p.poe_name || '').toLowerCase().includes(q) ||
    (p.district || '').toLowerCase().includes(q) ||
    (p.province || '').toLowerCase().includes(q) ||
    (p.poe_code || '').toLowerCase().includes(q) ||
    (p.admin_level_1 || '').toLowerCase().includes(q) ||
    (p.border_country || '').toLowerCase().includes(q) ||
    (p.regional_cluster_or_rpheoc || '').toLowerCase().includes(q)
  )
  return list
})

const groupedPoes = computed(() => {
  const groups = {}
  for (const poe of filteredPoes.value) {
    const key = poe.admin_level_1 || poe.province || 'Unknown'
    if (!groups[key]) groups[key] = { admin_level_1: key, type: poe.admin_level_1_type || 'province', poes: [] }
    groups[key].poes.push(poe)
  }
  return Object.values(groups).sort((a, b) => a.admin_level_1.localeCompare(b.admin_level_1))
})

// ── Modal computed ────────────────────────────────────────────────────────
const siblingsByRpheoc = computed(() => {
  if (!selectedPoe.value) return []
  const key = selectedPoe.value.admin_level_1 || selectedPoe.value.province
  return allPoes.value.filter(p => (p.admin_level_1 || p.province) === key && p.id !== selectedPoe.value.id)
})

const siblingsByDistrict = computed(() => {
  if (!selectedPoe.value) return []
  return allPoes.value.filter(p => p.district === selectedPoe.value.district && p.id !== selectedPoe.value.id)
})

const travelerTip = computed(() => {
  if (!selectedPoe.value || !window.POE_MAIN?.traveler_notes) return null
  const notes = window.POE_MAIN.traveler_notes
  for (const key of Object.keys(notes)) {
    if (notes[key].recommended_poe === selectedPoe.value.poe_name) return notes[key].note
  }
  return null
})

// ── Actions ───────────────────────────────────────────────────────────────
function clearFilters() { activeMode.value = 'ALL'; activeFlag.value = 'ALL'; searchQuery.value = '' }
function openDetail(poe) { selectedPoe.value = poe }

// ── Helpers ───────────────────────────────────────────────────────────────
function transportLabel(m) {
  return { land: 'Land', air: 'Air', water: 'Water', sea: 'Sea' }[m] || (m ? m[0].toUpperCase() + m.slice(1) : 'Land')
}
function formatPoeType(t) {
  const map = {
    airport: 'International Airport', airstrip: 'Airstrip',
    land_border: 'Land Border Post', port: 'Lake / River Port',
    island_entry: 'Island Entry Point', sea_port: 'Sea Port',
  }
  if (!t) return 'Border Post'
  return map[t] || t.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

// ═══════════════════════════════════════════════════════════════════════════
// ADMIN CRUD — DB-backed POE / Province / District / Hospital management.
// The server auto-derives every shape field (country, province, admin_level_1,
// admin_level_1_type, district, district_raw, regional_cluster_or_rpheoc,
// source_province_group, transport_mode, source_origin, external_id) so the
// window.POE_MAIN bundle cannot drift from the legacy POEs.js contract.
// Only NATIONAL_ADMIN users see the FAB and the edit/delete controls.
// ═══════════════════════════════════════════════════════════════════════════
function getAuth () {
  try { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') }
  catch { return null }
}
const auth = ref(getAuth())
const isAdmin = computed(() => (auth.value?.role_key || '') === 'NATIONAL_ADMIN')
const apiBase = computed(() => String(window.SERVER_URL || '').replace(/\/+$/, ''))
const apiReady = computed(() => !!apiBase.value)

// Directory caches (populated once admin enters the view)
const provincesList = ref([])
const districtsList = ref([])
const hospitalsList = ref([])

// Editor state
const editor = ref({
  open: false, mode: 'create', entity: 'poe',
  form: {}, errors: {}, saving: false, editingId: null,
})

// Delete confirm state
const confirmDel = ref({ open: false, entity: 'poe', row: null, label: '', busy: false })

// FAB expansion state — drives the label-pill reveal animation
const fabOpen = ref(false)
// Collapse when an editor opens so it doesn't hover over the modal
watch(() => editor.value.open, (v) => { if (v) fabOpen.value = false })

// Toast
const toast = ref({ show: false, msg: '', color: 'success' })
function showToast (msg, color = 'success') { toast.value = { show: true, msg, color } }

const poeTypeOptions = [
  { value: 'land_border',  label: 'Land Border Post' },
  { value: 'airport',      label: 'International Airport' },
  { value: 'airstrip',     label: 'Airstrip' },
  { value: 'port',         label: 'Lake / River Port' },
  { value: 'island_entry', label: 'Island Entry Point' },
  { value: 'rail',         label: 'Rail Border' },
  { value: 'other',        label: 'Other' },
]
const provinceTypeOptions = [
  { value: 'PHEOC',    label: 'PHEOC (Provincial)' },
  { value: 'PROVINCE', label: 'Province' },
  { value: 'REGION',   label: 'Region' },
]
const hospitalTypeOptions = [
  { value: 'TEACHING', label: 'Teaching Hospital' },
  { value: 'GENERAL',  label: 'General Hospital' },
  { value: 'DISTRICT', label: 'District Hospital' },
  { value: 'RURAL',    label: 'Rural Health Centre' },
  { value: 'CLINIC',   label: 'Clinic' },
  { value: 'PRIVATE',  label: 'Private Facility' },
  { value: 'MILITARY', label: 'Military Medical Facility' },
  { value: 'OTHER',    label: 'Other' },
]

function entityLabel (e) {
  return ({ poe: 'POE', province: 'Province / PHEOC', district: 'District', hospital: 'Hospital' })[e] || e
}

// Thin API helper — injects user_id on writes, handles error envelopes.
async function apiCall (method, path, body = null) {
  if (!apiBase.value) throw new Error('Server URL not configured')
  const uid = auth.value?.id
  const headers = { 'Accept': 'application/json' }
  const opts = { method, headers, credentials: 'omit' }
  let url = apiBase.value + path
  if (method === 'DELETE') {
    if (uid) url += (url.includes('?') ? '&' : '?') + 'user_id=' + encodeURIComponent(uid)
  } else if (method !== 'GET') {
    headers['Content-Type'] = 'application/json'
    opts.body = JSON.stringify(Object.assign({ user_id: uid }, body || {}))
  }
  const res = await fetch(url, opts)
  const text = await res.text()
  let json = null
  try { json = text ? JSON.parse(text) : null } catch (_) {}
  if (!res.ok) {
    const msg = (json && json.message) || ('HTTP ' + res.status)
    const err = new Error(msg)
    err.status = res.status
    err.body = json
    throw err
  }
  return json
}

async function loadDirectories () {
  if (!apiReady.value) return
  try {
    const [p, d, h] = await Promise.all([
      apiCall('GET', '/geo/provinces?country=Uganda'),
      apiCall('GET', '/geo/districts?country=Uganda'),
      apiCall('GET', '/geo/hospitals?country=Uganda'),
    ])
    provincesList.value = p?.data || []
    districtsList.value = d?.data || []
    hospitalsList.value = h?.data || []
  } catch (e) { console.warn('[POEs] loadDirectories', e.message) }
}

// Guarantee directories are loaded before opening an editor that depends
// on them — otherwise the PHEOC/District dropdowns would open empty on a
// slow first paint.  Safe to call repeatedly; only refetches if empty.
async function ensureDirectories () {
  if (!apiReady.value) return
  if (provincesList.value.length && districtsList.value.length) return
  await loadDirectories()
}

// Pull the fresh bundle + overwrite window.POE_MAIN + cache so the rest
// of the app sees mutations immediately.  Also dispatches `poe-main-updated`
// so reactive subscribers (UsersList PHEOC/District/POE dropdowns, etc.)
// refresh in-place without a page reload.  Hardcoded fallback stays intact.
async function refreshBundle () {
  if (!apiReady.value) return
  try {
    const res = await fetch(apiBase.value + '/poes/bundle')
    if (!res.ok) return
    const body = await res.json()
    if (!body?.data) return
    window.POE_MAIN = body.data
    try {
      const etag = res.headers.get('etag') || null
      window.localStorage.setItem('poe_main_cache_v1', JSON.stringify({
        data: body.data, etag, version: body.meta?.version || null, fetched_at: Date.now(),
      }))
    } catch (_) { /* quota */ }
    try {
      window.dispatchEvent(new CustomEvent('poe-main-updated', {
        detail: {
          etag: res.headers.get('etag') || null,
          version: body.meta?.version || null,
          counts: body.meta?.counts || null,
          source: 'POEs.vue.refreshBundle',
        },
      }))
    } catch (_) { /* non-DOM env */ }
  } catch (e) { console.warn('[POEs] refreshBundle', e.message) }
}

// Run on enter — refresh auth + prefetch directories for the editor.
onIonViewDidEnter(() => {
  auth.value = getAuth()
  if (isAdmin.value) loadDirectories()
})

// Blank form factory per entity.  Reflects the minimum input surface —
// the server auto-derives every other field.
function blankForm (entity) {
  if (entity === 'poe') return {
    poe_name: '', poe_code: '',
    poe_type: 'land_border',
    province_id: '', district_id: '',
    border_country: '', critical_details: '', source_url: '',
    is_major_entry: false, is_recommended_osbp: false, is_national_level: false,
    is_active: true,
  }
  if (entity === 'province') return { name: '', admin_level_1_type: 'PHEOC', is_active: true }
  if (entity === 'district') return { name: '', province_id: '', is_active: true }
  if (entity === 'hospital') return {
    name: '', hospital_type: 'GENERAL',
    province_id: '', district_id: null,
    is_national_level: false, is_active: true,
    phone: '', address: '',
  }
  return {}
}

async function openCreate (entity) {
  if (!isAdmin.value) return
  await ensureDirectories()
  editor.value = {
    open: true, mode: 'create', entity,
    form: blankForm(entity), errors: {}, saving: false, editingId: null,
  }
}

// Resolve a bundle-shape POE (window.POE_MAIN.poes entry) back to its DB row.
async function resolvePoeRow (bundlePoe) {
  const r = await apiCall('GET', '/geo/poes?country=Uganda')
  const list = r?.data || []
  return list.find(p => p.external_id === bundlePoe.id)
      || list.find(p => p.poe_code === bundlePoe.poe_code && p.poe_name === bundlePoe.poe_name)
      || null
}

async function openEditPoe (poe) {
  if (!isAdmin.value || !poe) return
  try {
    await ensureDirectories()
    const row = await resolvePoeRow(poe)
    if (!row) { showToast('POE record not found in DB.', 'danger'); return }
    editor.value = {
      open: true, mode: 'edit', entity: 'poe', editingId: row.id, saving: false, errors: {},
      form: {
        poe_name: row.poe_name || '',
        poe_code: row.poe_code || '',
        poe_type: row.poe_type || 'land_border',
        province_id: row.province_id ?? '',
        district_id: row.district_id ?? '',
        border_country: row.border_country || '',
        critical_details: row.payload?.critical_details || '',
        source_url: row.payload?.source_url || row.gazette_source || '',
        is_major_entry: !!row.is_major_entry,
        is_recommended_osbp: !!row.is_recommended_osbp,
        is_national_level: !!row.is_national_level,
        is_active: !!row.is_active,
      },
    }
  } catch (e) { showToast('Load failed — ' + e.message, 'danger') }
}

function closeEditor () { editor.value.open = false }

function validateEditor () {
  const e = {}
  const f = editor.value.form
  const ent = editor.value.entity
  const isEmpty = (v) => v === undefined || v === null || v === '' || (typeof v === 'string' && !v.trim())
  const validFk  = (v) => Number.isFinite(Number(v)) && Number(v) > 0

  if (ent === 'poe') {
    if (isEmpty(f.poe_name)) e.poe_name = 'Required'
    else if (f.poe_name.trim().length < 2) e.poe_name = 'At least 2 characters'
    if (!validFk(f.province_id)) e.province_id = 'Required'
    if (!validFk(f.district_id)) e.district_id = 'Required'
    // Cross-check: district must belong to the selected province.
    if (validFk(f.province_id) && validFk(f.district_id)) {
      const d = districtsList.value.find(x => Number(x.id) === Number(f.district_id))
      if (d && Number(d.province_id) !== Number(f.province_id)) {
        e.district_id = 'District does not belong to the selected PHEOC.'
      }
    }
  } else if (ent === 'province') {
    if (isEmpty(f.name)) e.name = 'Required'
    else if (f.name.trim().length < 3) e.name = 'At least 3 characters'
    if (!['PHEOC', 'PROVINCE', 'REGION'].includes(f.admin_level_1_type)) e.admin_level_1_type = 'Invalid type'
  } else if (ent === 'district') {
    if (isEmpty(f.name)) e.name = 'Required'
    else if (f.name.trim().length < 2) e.name = 'At least 2 characters'
    if (!validFk(f.province_id)) e.province_id = 'Required'
  } else if (ent === 'hospital') {
    if (isEmpty(f.name)) e.name = 'Required'
    else if (f.name.trim().length < 3) e.name = 'At least 3 characters'
    if (!validFk(f.province_id)) e.province_id = 'Required'
    // Optional district — but if present, must match the selected PHEOC.
    if (f.district_id !== null && f.district_id !== '' && validFk(f.province_id)) {
      const d = districtsList.value.find(x => Number(x.id) === Number(f.district_id))
      if (d && Number(d.province_id) !== Number(f.province_id)) {
        e.district_id = 'District does not belong to the selected PHEOC.'
      }
    }
  }
  editor.value.errors = e
  return Object.keys(e).length === 0
}

// Coerce + trim the editor form into a clean payload before POST/PATCH.
// Strings → trimmed (null if empty on optional fields); FKs → integers;
// booleans → real booleans.  Prevents whitespace-poisoned names and
// stringy IDs from reaching the API.
function buildSavePayload () {
  const { entity, form } = editor.value
  const s = (v) => (typeof v === 'string' ? v.trim() : v)
  const str = (v) => { const t = s(v); return t === '' ? null : t }
  const bool = (v) => !!v
  const int = (v) => { const n = Number(v); return Number.isFinite(n) && n > 0 ? n : null }

  if (entity === 'poe') {
    return {
      poe_name:           s(form.poe_name) || '',
      poe_code:           str(form.poe_code) || undefined,    // server defaults to poe_name
      poe_type:           form.poe_type,
      province_id:        int(form.province_id),
      district_id:        int(form.district_id),
      border_country:     str(form.border_country),
      // str() already returns null for blank input — do NOT collapse back to ''.
      // Empty strings poison `WHERE gazette_source IS NOT NULL` queries downstream.
      critical_details:   str(form.critical_details),
      source_url:         str(form.source_url),
      is_major_entry:     bool(form.is_major_entry),
      is_recommended_osbp:bool(form.is_recommended_osbp),
      is_national_level:  bool(form.is_national_level),
      is_active:          bool(form.is_active),
    }
  }
  if (entity === 'province') {
    return {
      name:               s(form.name) || '',
      admin_level_1_type: form.admin_level_1_type || 'PHEOC',
      is_active:          bool(form.is_active),
    }
  }
  if (entity === 'district') {
    return {
      name:         s(form.name) || '',
      province_id:  int(form.province_id),
      is_active:    bool(form.is_active),
    }
  }
  if (entity === 'hospital') {
    return {
      name:               s(form.name) || '',
      hospital_type:      form.hospital_type || 'GENERAL',
      province_id:        int(form.province_id),
      district_id:        form.district_id === null || form.district_id === '' ? null : int(form.district_id),
      phone:              str(form.phone),
      address:            str(form.address),
      is_national_level:  bool(form.is_national_level),
      is_active:          bool(form.is_active),
    }
  }
  return {}
}

async function saveEditor () {
  if (!validateEditor()) { showToast('Please complete the required fields.', 'warning'); return }
  const { mode, entity, editingId } = editor.value
  editor.value.saving = true
  try {
    const basePath = '/geo/' + (entity === 'poe' ? 'poes' : entity + 's')
    const path = mode === 'create' ? basePath : (basePath + '/' + editingId)
    const payload = buildSavePayload()
    const r = await apiCall(mode === 'create' ? 'POST' : 'PATCH', path, payload)
    showToast((mode === 'create' ? 'Added' : 'Updated') + ' ' + entityLabel(entity) + '.', 'success')
    // Clear `saving` BEFORE closing so Ionic's :can-dismiss="!editor.saving"
    // doesn't block the programmatic dismiss. Without this the modal stays
    // open until the awaited Promise.all settles ~1s later — and by then
    // the is-open=false reactive change has already been swallowed.
    editor.value.saving = false
    editor.value.open = false
    await Promise.all([loadDirectories(), refreshBundle()])
    // Keep detail modal fresh if we just edited the open POE.
    if (entity === 'poe' && selectedPoe.value) {
      const newExtId = r?.data?.external_id
      const fresh = allPoes.value.find(p => p.id === newExtId)
               || allPoes.value.find(p => p.poe_name === payload.poe_name)
      selectedPoe.value = fresh || null
    }
  } catch (e) {
    showToast('Save failed — ' + e.message, 'danger')
  } finally {
    editor.value.saving = false
  }
}

async function openDeletePoe (poe) {
  if (!isAdmin.value || !poe) return
  try {
    const row = await resolvePoeRow(poe)
    if (!row) { showToast('POE record not found.', 'danger'); return }
    confirmDel.value = { open: true, entity: 'poe', row, label: poe.poe_name, busy: false }
  } catch (e) { showToast(e.message, 'danger') }
}

async function doDelete () {
  const { entity, row } = confirmDel.value
  if (!row) return
  confirmDel.value.busy = true
  try {
    const path = '/geo/' + (entity === 'poe' ? 'poes' : entity + 's') + '/' + row.id
    await apiCall('DELETE', path)
    showToast(entityLabel(entity) + ' deleted.', 'success')
    confirmDel.value = { open: false, entity, row: null, label: '', busy: false }
    await Promise.all([loadDirectories(), refreshBundle()])
    if (entity === 'poe') selectedPoe.value = null
  } catch (e) {
    showToast('Delete failed — ' + e.message, 'danger')
    confirmDel.value.busy = false
  }
}

// District dropdown inside the editor narrows to the selected province.
const districtsForEditorProvince = computed(() => {
  const pid = Number(editor.value.form?.province_id || 0)
  if (!pid) return districtsList.value
  return districtsList.value.filter(d => Number(d.province_id) === pid)
})

// Clear the district selection when the user changes PHEOC mid-edit so a
// stale FK can't be submitted.  Skip the very first tick after the editor
// opens in edit mode (when we prime the form with the current row).
let _editorPrimedAt = 0
watch(() => editor.value.form?.province_id, (next, prev) => {
  if (!editor.value.open) return
  if (Date.now() - _editorPrimedAt < 300) return          // initial hydration
  if (prev === undefined || prev === null || prev === '') return
  if (String(next) === String(prev)) return
  const ent = editor.value.entity
  if (ent === 'poe' || ent === 'district' || ent === 'hospital') {
    editor.value.form.district_id = ent === 'hospital' ? null : ''
  }
})
// Stamp the hydration window whenever the editor opens.
watch(() => editor.value.open, (open) => { if (open) _editorPrimedAt = Date.now() })
</script>

<style scoped>
/* ═══════════════════════════════════════════════════════════════════════
   SENTINEL DUAL-TONE — POE MANAGEMENT
   DARK ZONE  : header · stats · tabs · search (navy gradients)
   LIGHT ZONE : content · cards · modals (luminous gradients)
═══════════════════════════════════════════════════════════════════════ */
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700;800&family=Syne:wght@600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap');

/* ── Keyframes ──────────────────────────────────────────────────────── */
@keyframes slideUp   { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
@keyframes stream    { 0%{transform:translateX(-100%)} 100%{transform:translateX(350%)} }
@keyframes scan      { 0%{left:-60%} 100%{left:150%} }
@keyframes pulse-green { 0%,100%{box-shadow:0 0 6px rgba(0,168,107,.4)} 50%{box-shadow:0 0 16px rgba(0,168,107,.8)} }
@keyframes pulse-blue  { 0%,100%{box-shadow:0 0 6px rgba(0,112,224,.4)} 50%{box-shadow:0 0 16px rgba(0,112,224,.8)} }
@keyframes pulse-teal  { 0%,100%{box-shadow:0 0 6px rgba(0,143,122,.4)} 50%{box-shadow:0 0 16px rgba(0,143,122,.8)} }
@keyframes dotBlink  { 0%,100%{opacity:.5} 50%{opacity:1} }
@keyframes chainFlow { 0%{opacity:.4;transform:scaleX(.8)} 100%{opacity:1;transform:scaleX(1)} }
@media (prefers-reduced-motion: reduce){ *,*::before,*::after{ animation-duration:.01ms!important; transition-duration:.01ms!important } }

/* ═══════════════════════════════════════════════════════════════════
   DARK ZONE — Header
═══════════════════════════════════════════════════════════════════ */
.ph-header  { --background: #070E1B; }
.ph-toolbar { --background: linear-gradient(180deg, #070E1B 0%, #0E1A2E 100%); --color: #EDF2FA; --border-width: 0; }

.ph-title-block  { display:flex; flex-direction:column; gap:1px; }
.ph-eyebrow      { font-size:7px; font-weight:700; color:#7E92AB; letter-spacing:1.2px; text-transform:uppercase; font-family:'DM Sans',sans-serif; }
.ph-title        { font-size:18px; font-weight:800; color:#EDF2FA; font-family:'Syne',sans-serif; line-height:1.1; }

.ph-network-badge { display:flex; flex-direction:column; align-items:center; padding:6px 10px; background:rgba(0,180,255,.08); border-radius:10px; border:1px solid rgba(0,180,255,.15); margin-right:4px; gap:1px; }
.ph-network-dot  { width:6px; height:6px; border-radius:50%; background:#00E676; animation:pulse-green 2s infinite; margin-bottom:2px; }
.ph-network-n    { font-size:15px; font-weight:900; color:#00B4FF; font-family:'Syne',sans-serif; line-height:1; }
.ph-network-l    { font-size:7px; font-weight:700; color:#7E92AB; letter-spacing:.8px; text-transform:uppercase; }

/* Stats ribbon */
.ph-stats-ribbon { display:flex; align-items:center; background:linear-gradient(180deg, #0E1A2E 0%, #142640 100%); padding:9px 12px; border-bottom:1px solid rgba(255,255,255,.05); position:relative; overflow:hidden; }
.ph-stats-texture { position:absolute; inset:0; pointer-events:none; background-image:linear-gradient(rgba(0,180,255,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(0,180,255,.03) 1px,transparent 1px); background-size:28px 28px; mask-image:linear-gradient(180deg,black 50%,transparent 100%); }
.ph-stat         { display:flex; flex-direction:column; align-items:center; flex:1; position:relative; z-index:1; }
.ph-stat-n       { font-size:17px; font-weight:900; color:#EDF2FA; font-family:'Syne',sans-serif; line-height:1; }
.ph-stat-l       { font-size:8px; font-weight:700; color:#7E92AB; letter-spacing:.6px; text-transform:uppercase; margin-top:1px; }
.ph-stat-n--green  { color:#00E676; text-shadow:0 0 12px rgba(0,230,118,.3); }
.ph-stat-n--blue   { color:#00B4FF; text-shadow:0 0 12px rgba(0,180,255,.3); }
.ph-stat-n--teal   { color:#00E5FF; text-shadow:0 0 12px rgba(0,229,255,.3); }
.ph-stat-n--amber  { color:#FFB300; text-shadow:0 0 12px rgba(255,179,0,.3); }
.ph-stat-n--purple { color:#B388FF; text-shadow:0 0 12px rgba(179,136,255,.3); }
.ph-stat-sep     { width:1px; height:28px; background:rgba(255,255,255,.06); margin:0 2px; }

/* Search */
.ph-search-zone { padding:10px 12px 6px; background:linear-gradient(180deg, #142640, #0E1A2E); }
.ph-search-bar  { display:flex; align-items:center; gap:8px; background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1); border-radius:12px; padding:0 12px; position:relative; overflow:hidden; transition:all .2s; }
.ph-search-bar--active { border-color:rgba(0,180,255,.35); background:rgba(0,180,255,.06); }
.ph-search-bar::after { content:''; position:absolute; top:0; left:-60%; width:50%; height:100%; background:linear-gradient(90deg,transparent,rgba(0,180,255,.04),transparent); animation:scan 6s ease-in-out infinite; pointer-events:none; }
.ph-search-ic    { width:16px; height:16px; flex-shrink:0; }
.ph-search-input { flex:1; background:transparent; border:none; color:#EDF2FA; font-size:16px; padding:11px 0; outline:none; font-family:'DM Sans',sans-serif; }
.ph-search-input::placeholder { color:rgba(255,255,255,.3); }
.ph-search-clear { background:none; border:none; color:rgba(255,255,255,.4); cursor:pointer; font-size:13px; padding:4px 2px; min-width:28px; min-height:28px; display:flex; align-items:center; justify-content:center; }

/* Filter chip rows */
.ph-chip-row { display:flex; gap:6px; overflow-x:auto; scrollbar-width:none; padding:6px 12px 4px; background:linear-gradient(180deg, #0E1A2E, #0A1628); }
.ph-chip-row--flags { padding-bottom:8px; border-bottom:1px solid rgba(255,255,255,.06); }
.ph-chip-row::-webkit-scrollbar { display:none; }
.ph-chip { display:flex; align-items:center; gap:5px; padding:7px 12px; border-radius:20px; font-size:10px; font-weight:700; cursor:pointer; border:1px solid transparent; white-space:nowrap; background:rgba(255,255,255,.07); color:#7E92AB; transition:all .18s; min-height:36px; font-family:'DM Sans',sans-serif; }
.ph-chip em { font-style:normal; background:rgba(255,255,255,.08); border-radius:8px; padding:0 5px; font-size:8px; font-weight:900; font-family:'JetBrains Mono',monospace; }
.ph-chip-ic { width:13px; height:13px; flex-shrink:0; }
.ph-chip--active { color:#EDF2FA; border-color:rgba(255,255,255,.15); background:rgba(255,255,255,.12); }
.ph-chip--land.ph-chip--active  { color:#00E676; border-color:rgba(0,230,118,.3); background:rgba(0,230,118,.08); }
.ph-chip--air.ph-chip--active   { color:#00B4FF; border-color:rgba(0,180,255,.3); background:rgba(0,180,255,.08); }
.ph-chip--water.ph-chip--active { color:#00E5FF; border-color:rgba(0,229,255,.3); background:rgba(0,229,255,.08); }

/* ═══════════════════════════════════════════════════════════════════
   LIGHT ZONE — Content
═══════════════════════════════════════════════════════════════════ */

/* Results bar */
.ph-results-bar { display:flex; align-items:center; justify-content:space-between; padding:10px 14px 4px; }
.ph-results-text { font-size:10px; font-weight:600; color:#94A3B8; }
.ph-results-clear { font-size:10px; font-weight:700; color:#0070E0; background:none; border:none; cursor:pointer; padding:4px 8px; border-radius:5px; background:rgba(0,112,224,.08); }

/* Group header */
.ph-group-hdr { display:flex; align-items:center; gap:8px; padding:14px 14px 4px; }
.ph-group-dot  { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.ph-group-dot--pheoc    { background:#7B40D8; box-shadow:0 0 6px rgba(123,64,216,.5); }
.ph-group-dot--province { background:#0070E0; box-shadow:0 0 6px rgba(0,112,224,.5); }
.ph-group-name { font-size:11px; font-weight:700; color:#0B1A30; flex:1; min-width:0; font-family:'DM Sans',sans-serif; }
.ph-group-tag  { font-size:8px; font-weight:700; padding:2px 6px; border-radius:4px; font-family:'JetBrains Mono',monospace; flex-shrink:0; }
.ph-group-tag--pheoc    { background:linear-gradient(135deg,#F5F3FF,#EDE9FE); color:#7B40D8; border:1px solid rgba(123,64,216,.15); }
.ph-group-tag--province { background:linear-gradient(135deg,#E0ECFF,#CCE0FF); color:#0070E0; border:1px solid rgba(0,112,224,.15); }
.ph-group-count { font-size:10px; font-weight:800; color:#94A3B8; font-family:'JetBrains Mono',monospace; min-width:20px; text-align:center; }

/* POE card */
.ph-list { padding:0 10px 0; }
.ph-card {
  display:flex; align-items:stretch;
  background:linear-gradient(145deg, #FFFFFF 0%, #F4F7FC 100%);
  border:1.5px solid rgba(0,0,0,.06); border-radius:14px;
  box-shadow:0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  margin-bottom:8px; overflow:hidden; cursor:pointer; position:relative;
  animation:slideUp .4s cubic-bezier(.16,1,.3,1) both;
  transition:transform .22s cubic-bezier(.16,1,.3,1), box-shadow .22s;
}
.ph-card::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 15%,rgba(255,255,255,.9) 50%,transparent 85%); z-index:2; pointer-events:none; }
.ph-card:hover  { transform:translateY(-1px); box-shadow:0 3px 10px rgba(0,0,0,.07), 0 10px 35px rgba(0,30,80,.11); }
.ph-card:active { transform:scale(.985); }

/* Data stream on card */
.ph-card-stream { position:absolute; top:0; bottom:0; width:45%; left:0; background:linear-gradient(90deg,transparent,rgba(0,112,224,.025),transparent); animation:stream 8s ease-in-out infinite; pointer-events:none; z-index:1; }

/* Left accent bar per mode */
.ph-card-bar { width:4px; flex-shrink:0; }
.ph-card-bar--land  { background:#00A86B; box-shadow:0 0 8px rgba(0,168,107,.35); animation:pulse-green 2.5s infinite; }
.ph-card-bar--air   { background:#0070E0; box-shadow:0 0 8px rgba(0,112,224,.3);  animation:pulse-blue 2.5s infinite; }
.ph-card-bar--water { background:#008F7A; box-shadow:0 0 8px rgba(0,143,122,.3);  animation:pulse-teal 2.5s infinite; }

.ph-card-body { flex:1; padding:11px 12px; position:relative; z-index:2; }
.ph-card-r1 { display:flex; align-items:center; gap:10px; margin-bottom:6px; }
.ph-card-r2 { display:flex; align-items:center; gap:5px; margin-bottom:4px; font-size:11px; color:#475569; }
.ph-card-r3 { display:flex; align-items:center; gap:6px; }

/* Mode icon */
.ph-mode-icon { width:34px; height:34px; border-radius:9px; flex-shrink:0; display:flex; align-items:center; justify-content:center; border:1px solid; }
.ph-mode-icon svg { width:18px; height:18px; }
.ph-mode-icon--land  { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); border-color:rgba(0,168,107,.2); color:#00A86B; }
.ph-mode-icon--air   { background:linear-gradient(135deg,#E0ECFF,#CCE0FF); border-color:rgba(0,112,224,.2); color:#0070E0; }
.ph-mode-icon--water { background:linear-gradient(135deg,#E0F7F4,#CCF0EA); border-color:rgba(0,143,122,.2); color:#008F7A; }

.ph-name-block { flex:1; min-width:0; }
.ph-name  { display:block; font-size:14px; font-weight:700; color:#0B1A30; font-family:'DM Sans',sans-serif; line-height:1.25; }
.ph-type  { display:block; font-size:9px; font-weight:600; color:#94A3B8; margin-top:1px; }
.ph-geo-pin { font-size:10px; }
.ph-district { font-size:11px; font-weight:600; color:#475569; }
.ph-province { font-size:11px; color:#94A3B8; }
.ph-sep      { color:#94A3B8; font-size:10px; }

.ph-card-badges { display:flex; gap:4px; flex-shrink:0; }
.ph-badge { font-size:7px; font-weight:800; padding:2px 5px; border-radius:4px; font-family:'JetBrains Mono',monospace; letter-spacing:.3px; }
.ph-badge--major    { background:linear-gradient(135deg,#FEF2F2,#FECACA); color:#E02050; border:1px solid rgba(224,32,80,.15); }
.ph-badge--osbp     { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); color:#CC8800; border:1px solid rgba(204,136,0,.15); }
.ph-badge--national { background:linear-gradient(135deg,#F5F3FF,#EDE9FE); color:#7B40D8; border:1px solid rgba(123,64,216,.15); }

.ph-code        { font-size:9px; font-family:'JetBrains Mono',monospace; color:#0070E0; background:linear-gradient(135deg,#E0ECFF,#CCE0FF); padding:2px 6px; border-radius:4px; }
.ph-border      { font-size:10px; color:#00A86B; font-weight:600; }
.ph-has-notes   { font-size:9px; color:#94A3B8; }
.ph-card-chevron { display:flex; align-items:center; padding:0 14px 0 6px; color:#94A3B8; font-size:22px; font-weight:200; transition:color .15s, transform .15s; position:relative; z-index:2; }
.ph-card:hover .ph-card-chevron { color:#0070E0; transform:translateX(3px); }

/* Empty state */
.ph-empty { display:flex; flex-direction:column; align-items:center; padding:80px 24px; gap:12px; }
.ph-empty-icon  { font-size:48px; opacity:.35; }
.ph-empty-title { font-size:17px; font-weight:700; color:#475569; font-family:'DM Sans',sans-serif; }
.ph-empty-body  { font-size:12px; color:#94A3B8; text-align:center; line-height:1.5; }
.ph-empty-clear { padding:10px 20px; border-radius:10px; background:linear-gradient(135deg,#0055CC,#0070E0); color:#fff; border:none; cursor:pointer; font-size:12px; font-weight:700; box-shadow:0 4px 14px rgba(0,112,224,.3); font-family:'DM Sans',sans-serif; min-height:44px; }

/* ═══════════════════════════════════════════════════════════════════
   DETAIL MODAL — Dark header + Light content
═══════════════════════════════════════════════════════════════════ */
.pd-header { }
.pd-toolbar-title { display:flex; flex-direction:column; gap:2px; }
.pd-toolbar-eyebrow { font-size:7px; font-weight:700; color:#7E92AB; letter-spacing:1px; text-transform:uppercase; }
.pd-toolbar-name    { font-size:15px; font-weight:800; color:#EDF2FA; font-family:'Syne',sans-serif; line-height:1.2; }
.pd-readonly-tag    { font-size:8px; font-weight:700; color:#7E92AB; background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.1); border-radius:5px; padding:3px 8px; margin-right:6px; font-family:'JetBrains Mono',monospace; }

/* ── CINEMATIC HERO ──────────────────────────────────────────────── */
.pd-hero {
  position:relative; overflow:hidden;
  padding:20px 16px 16px;
}
.pd-hero--land  { background:linear-gradient(160deg, #011B0A 0%, #042E14 50%, #073D1A 100%); }
.pd-hero--air   { background:linear-gradient(160deg, #010D1E 0%, #031526 50%, #061E38 100%); }
.pd-hero--water { background:linear-gradient(160deg, #011517 0%, #032028 50%, #062E36 100%); }

.pd-hero-texture { position:absolute; inset:0; pointer-events:none; background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px); background-size:24px 24px; mask-image:linear-gradient(180deg,black 40%,transparent 100%); }
.pd-hero-orb    { position:absolute; top:-80px; right:-80px; width:260px; height:260px; border-radius:50%; filter:blur(80px); opacity:.18; pointer-events:none; }
.pd-hero--land  .pd-hero-orb { background:#00E676; }
.pd-hero--air   .pd-hero-orb { background:#00B4FF; }
.pd-hero--water .pd-hero-orb { background:#00E5FF; }

/* Large background icon */
.pd-hero-mode-icon { position:absolute; right:-20px; top:0; opacity:.07; pointer-events:none; }
.pd-hero-mode-icon svg { width:160px; height:160px; }
.pd-hero--land  .pd-hero-mode-icon { color:#00E676; }
.pd-hero--air   .pd-hero-mode-icon { color:#00B4FF; }
.pd-hero--water .pd-hero-mode-icon { color:#00E5FF; }

.pd-hero-content { position:relative; z-index:2; }
.pd-hero-top     { display:flex; align-items:center; gap:8px; margin-bottom:12px; }

.pd-hero-mode-badge  { font-size:9px; font-weight:800; padding:4px 10px; border-radius:6px; font-family:'JetBrains Mono',monospace; letter-spacing:.6px; }
.pd-mode-badge--land  { background:rgba(0,230,118,.15); color:#00E676; border:1px solid rgba(0,230,118,.25); }
.pd-mode-badge--air   { background:rgba(0,180,255,.12); color:#00B4FF; border:1px solid rgba(0,180,255,.22); }
.pd-mode-badge--water { background:rgba(0,229,255,.1);  color:#00E5FF; border:1px solid rgba(0,229,255,.2); }

.pd-hero-national-badge { font-size:9px; font-weight:800; padding:4px 10px; border-radius:6px; background:rgba(179,136,255,.15); color:#B388FF; border:1px solid rgba(179,136,255,.25); }

.pd-hero-name { font-size:28px; font-weight:900; color:#EDF2FA; font-family:'Syne',sans-serif; line-height:1.1; margin:0 0 5px; letter-spacing:-.5px; }
.pd-hero-type { font-size:11px; font-weight:600; color:#7E92AB; margin-bottom:13px; text-transform:uppercase; letter-spacing:.5px; }

.pd-hero-flags { display:flex; flex-wrap:wrap; gap:6px; }
.pd-hero-flag  { display:flex; align-items:center; gap:5px; font-size:10px; font-weight:700; padding:5px 10px; border-radius:7px; border:1px solid; font-family:'DM Sans',sans-serif; }
.pd-hero-flag span { font-size:12px; }
.pd-hero-flag--major  { background:rgba(224,32,80,.12);  color:#FF6B8A; border-color:rgba(224,32,80,.22); }
.pd-hero-flag--osbp   { background:rgba(255,179,0,.1);   color:#FFD54F; border-color:rgba(255,179,0,.22); }
.pd-hero-flag--border { background:rgba(255,255,255,.07); color:#7E92AB; border-color:rgba(255,255,255,.1); }

/* POE code badge */
.pd-hero-code-badge { position:absolute; top:16px; right:14px; z-index:3; display:flex; flex-direction:column; align-items:center; background:rgba(255,255,255,.08); border:1px solid rgba(255,255,255,.1); border-radius:10px; padding:6px 10px; backdrop-filter:blur(8px); }
.pd-hero-code-label { font-size:7px; font-weight:700; color:#7E92AB; letter-spacing:1px; text-transform:uppercase; margin-bottom:2px; }
.pd-hero-code       { font-size:11px; font-weight:700; color:#EDF2FA; font-family:'JetBrains Mono',monospace; }

/* ── MODAL BODY — LIGHT ZONE ─────────────────────────────────────── */
.pd-body { padding:16px 14px 0; }

/* Section labels */
.pd-section      { margin-bottom:22px; }
.pd-section-label {
  display:flex; align-items:center; gap:8px;
  font-size:9px; font-weight:700; color:#0070E0;
  letter-spacing:1.1px; text-transform:uppercase;
  margin-bottom:12px; padding-bottom:8px;
  border-bottom:1px solid rgba(0,112,224,.12);
  font-family:'DM Sans',sans-serif;
}
.pd-section-glyph {
  width:24px; height:24px; border-radius:6px; flex-shrink:0;
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF);
  border:1px solid rgba(0,112,224,.15);
  display:flex; align-items:center; justify-content:center; font-size:13px;
}

/* ── HIERARCHY TREE ────────────────────────────────────────────── */
.pd-hierarchy-tree { display:flex; flex-direction:column; margin-bottom:10px; }
.pd-tree-node {
  display:flex; align-items:flex-start; gap:10px; padding:11px 13px;
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:12px;
  position:relative; overflow:hidden;
  animation:slideUp .35s cubic-bezier(.16,1,.3,1) both;
}
.pd-tree-node::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.85) 50%,transparent 80%); }
.pd-tree-node--country  { border-color:rgba(0,0,0,.06); }
.pd-tree-node--pheoc    { border-color:rgba(123,64,216,.15); }
.pd-tree-node--district { border-color:rgba(204,136,0,.12); }
.pd-tree-node--land   { border-color:rgba(0,168,107,.2); }
.pd-tree-node--air    { border-color:rgba(0,112,224,.2); }
.pd-tree-node--water  { border-color:rgba(0,143,122,.2); }

.pd-tree-node-icon { width:34px; height:34px; border-radius:9px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:16px; }
.pd-tree-node-icon svg { width:16px; height:16px; }
.pd-tree-icon--country  { background:linear-gradient(135deg,#E0ECFF,#CCE0FF); border:1px solid rgba(0,112,224,.15); }
.pd-tree-icon--pheoc    { background:linear-gradient(135deg,#F5F3FF,#EDE9FE); border:1px solid rgba(123,64,216,.15); }
.pd-tree-icon--district { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border:1px solid rgba(204,136,0,.15); }
.pd-tree-icon--land     { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); border:1px solid rgba(0,168,107,.15); color:#00A86B; }
.pd-tree-icon--air      { background:linear-gradient(135deg,#E0ECFF,#CCE0FF); border:1px solid rgba(0,112,224,.15); color:#0070E0; }
.pd-tree-icon--water    { background:linear-gradient(135deg,#E0F7F4,#CCF0EA); border:1px solid rgba(0,143,122,.15); color:#008F7A; }

.pd-tree-node-key { font-size:8px; font-weight:700; color:#94A3B8; letter-spacing:.8px; text-transform:uppercase; margin-bottom:3px; font-family:'DM Sans',sans-serif; }
.pd-tree-node-val { font-size:13px; font-weight:700; color:#0B1A30; font-family:'DM Sans',sans-serif; }
.pd-tree-node-val--poe { font-size:14px; font-weight:800; font-family:'Syne',sans-serif; }
.pd-tree-node-sub { font-size:9px; color:#94A3B8; margin-top:2px; font-family:'JetBrains Mono',monospace; }
.pd-tree-node-tag { font-size:7px; font-weight:800; color:#7B40D8; background:rgba(123,64,216,.1); padding:1px 6px; border-radius:3px; margin-top:3px; display:inline-flex; }
.pd-tree-terminal-dot { width:8px; height:8px; border-radius:50%; background:#00A86B; box-shadow:0 0 8px rgba(0,168,107,.5); margin-left:auto; flex-shrink:0; align-self:center; animation:pulse-green 2s infinite; }

.pd-tree-connector { width:1px; height:10px; background:linear-gradient(180deg,rgba(0,112,224,.2),rgba(0,112,224,.08)); margin:0 auto; margin-left:30px; }

/* Border country */
.pd-border-card {
  display:flex; align-items:center; gap:12px; padding:12px 14px;
  background:linear-gradient(135deg,#ECFDF5,#D1FAE5);
  border:1.5px solid rgba(0,168,107,.2); border-radius:12px;
  position:relative; overflow:hidden;
}
.pd-border-card-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.8) 50%,transparent 80%); }
.pd-border-icon { font-size:22px; color:#00A86B; font-weight:300; line-height:1; }
.pd-border-body { flex:1; }
.pd-border-key  { font-size:8px; font-weight:700; color:rgba(0,168,107,.6); letter-spacing:.8px; text-transform:uppercase; margin-bottom:3px; }
.pd-border-val  { font-size:15px; font-weight:800; color:#007A50; font-family:'Syne',sans-serif; }

/* ── IHR CLASSIFICATION FLAGS ────────────────────────────────────── */
.pd-flags-grid { display:flex; flex-direction:column; gap:8px; }
.pd-flag-card  {
  border-radius:14px; padding:14px; position:relative; overflow:hidden;
  border:1.5px solid; animation:slideUp .4s cubic-bezier(.16,1,.3,1) both;
}
.pd-flag-card-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.7) 50%,transparent 80%); }
.pd-flag-card--off    { background:linear-gradient(145deg,#FFFFFF,#F4F7FC); border-color:rgba(0,0,0,.06); }
.pd-flag-card--major  { background:linear-gradient(135deg,#FEF2F2,#FECACA); border-color:rgba(224,32,80,.2); }
.pd-flag-card--osbp   { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border-color:rgba(204,136,0,.2); }
.pd-flag-card--national { background:linear-gradient(135deg,#F5F3FF,#EDE9FE); border-color:rgba(123,64,216,.2); }
.pd-flag-icon   { font-size:22px; margin-bottom:8px; line-height:1; }
.pd-flag-label  { font-size:11px; font-weight:700; color:#0B1A30; margin-bottom:4px; font-family:'DM Sans',sans-serif; }
.pd-flag-card--off .pd-flag-label { color:#94A3B8; }
.pd-flag-status { font-size:9px; font-weight:900; letter-spacing:1px; margin-bottom:6px; font-family:'JetBrains Mono',monospace; }
.pd-flag-card--off .pd-flag-status     { color:#94A3B8; }
.pd-flag-card--major .pd-flag-status   { color:#E02050; }
.pd-flag-card--osbp .pd-flag-status    { color:#CC8800; }
.pd-flag-card--national .pd-flag-status { color:#7B40D8; }
.pd-flag-desc   { font-size:10px; color:#475569; line-height:1.5; }
.pd-flag-card--off .pd-flag-desc { color:#94A3B8; }

/* ── NETWORK CARD ────────────────────────────────────────────────── */
.pd-network-card {
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:14px;
  box-shadow:0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  overflow:hidden; position:relative;
}
.pd-network-card-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.9) 50%,transparent 80%); }
.pd-network-stats { display:flex; align-items:center; padding:14px; border-bottom:1px solid rgba(0,0,0,.06); }
.pd-net-stat     { display:flex; flex-direction:column; align-items:center; flex:1; }
.pd-net-stat-n   { font-size:20px; font-weight:900; color:#0B1A30; font-family:'Syne',sans-serif; line-height:1; }
.pd-net-stat-l   { font-size:8px; font-weight:700; color:#94A3B8; letter-spacing:.5px; text-transform:uppercase; margin-top:2px; }
.pd-net-mode--land  { color:#00A86B; }
.pd-net-mode--air   { color:#0070E0; }
.pd-net-mode--water { color:#008F7A; }
.pd-net-stat-sep { width:1px; height:28px; background:rgba(0,0,0,.06); margin:0 4px; }

/* Reporting chain */
.pd-chain { padding:12px 14px; }
.pd-chain-label { font-size:8px; font-weight:700; color:#94A3B8; letter-spacing:1px; text-transform:uppercase; margin-bottom:10px; }
.pd-chain-nodes { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.pd-chain-node  { display:flex; align-items:center; gap:5px; font-size:10px; font-weight:600; color:#475569; }
.pd-chain-dot   { width:8px; height:8px; border-radius:50%; background:rgba(0,0,0,.12); flex-shrink:0; }
.pd-chain-dot--active { background:#00A86B; animation:pulse-green 2s infinite; }
.pd-chain-arrow { font-size:12px; color:#94A3B8; }
.pd-chain-node--poe { color:#0B1A30; font-weight:800; }

/* ── SIBLING POEs ─────────────────────────────────────────────────── */
.pd-siblings { display:flex; flex-direction:column; gap:7px; }
.pd-sibling-card {
  display:flex; align-items:center; gap:0;
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:12px;
  overflow:hidden; cursor:pointer;
  transition:all .18s; animation:slideUp .35s cubic-bezier(.16,1,.3,1) both;
}
.pd-sibling-card:hover { box-shadow:0 2px 8px rgba(0,30,80,.1); transform:translateX(3px); }
.pd-sibling-bar  { width:4px; flex-shrink:0; }
.pd-sib-bar--land  { background:#00A86B; }
.pd-sib-bar--air   { background:#0070E0; }
.pd-sib-bar--water { background:#008F7A; }
.pd-sibling-body { flex:1; padding:9px 12px; }
.pd-sibling-name { font-size:12px; font-weight:700; color:#0B1A30; font-family:'DM Sans',sans-serif; margin-bottom:2px; }
.pd-sibling-meta { font-size:9px; color:#94A3B8; }
.pd-sibling-badges { display:flex; gap:3px; padding-right:10px; }
.pd-sib-badge { font-size:7px; font-weight:800; width:18px; height:18px; border-radius:5px; display:flex; align-items:center; justify-content:center; }
.pd-sib-badge--major { background:linear-gradient(135deg,#FEF2F2,#FECACA); color:#E02050; }
.pd-sib-badge--osbp  { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); color:#CC8800; }
.pd-siblings-more { text-align:center; font-size:10px; color:#94A3B8; padding:6px; }

/* ── OPERATIONAL CARDS ─────────────────────────────────────────── */
.pd-ops-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:10px; }
.pd-ops-card {
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:12px; padding:12px;
  position:relative; overflow:hidden;
}
.pd-ops-card-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.85) 50%,transparent 80%); }
.pd-ops-label { font-size:8px; font-weight:700; color:#94A3B8; letter-spacing:.7px; text-transform:uppercase; margin-bottom:5px; }
.pd-ops-value { font-size:13px; font-weight:700; color:#0B1A30; font-family:'DM Sans',sans-serif; margin-bottom:3px; line-height:1.3; }
.pd-ops-sub   { font-size:9px; color:#94A3B8; }
.pd-ops-mode--land  { color:#00A86B; }
.pd-ops-mode--air   { color:#0070E0; }
.pd-ops-mode--water { color:#008F7A; }

/* Notes card */
.pd-notes-card {
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF);
  border:1.5px solid rgba(0,112,224,.18); border-radius:12px; padding:14px;
  margin-bottom:8px; position:relative; overflow:hidden;
}
.pd-notes-card-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.7) 50%,transparent 80%); }
.pd-notes-hdr  { display:flex; align-items:center; gap:7px; margin-bottom:8px; }
.pd-notes-ic   { font-size:14px; }
.pd-notes-title { font-size:10px; font-weight:800; color:#0070E0; letter-spacing:.5px; text-transform:uppercase; }
.pd-notes-text  { font-size:12px; color:#0B1A30; line-height:1.6; font-weight:500; }

/* Traveler tip */
.pd-traveler-tip {
  background:linear-gradient(135deg,#ECFDF5,#D1FAE5);
  border:1.5px solid rgba(0,168,107,.2); border-radius:12px; padding:13px;
  position:relative; overflow:hidden;
}
.pd-traveler-tip-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.8) 50%,transparent 80%); }
.pd-traveler-hdr  { font-size:9px; font-weight:800; color:#00A86B; letter-spacing:.8px; margin-bottom:6px; }
.pd-traveler-text { font-size:12px; color:#007A50; line-height:1.5; font-weight:600; }

/* ── REFERENCE DATA TABLE ─────────────────────────────────────── */
.pd-ref-table {
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:14px;
  overflow:hidden; position:relative;
}
.pd-ref-table::before { content:''; position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.9) 50%,transparent 80%); }
.pd-ref-row { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:9px 14px; border-bottom:1px solid rgba(0,0,0,.05); flex-wrap:wrap; }
.pd-ref-row:last-child { border-bottom:none; }
.pd-ref-key { font-size:9px; font-weight:600; color:#94A3B8; flex-shrink:0; padding-top:2px; min-width:140px; }
.pd-ref-val { font-size:10px; font-weight:600; color:#475569; font-family:'JetBrains Mono',monospace; word-break:break-all; text-align:right; flex:1; }
.pd-ref-val--id   { color:#94A3B8; }
.pd-ref-val--code { color:#0070E0; background:linear-gradient(135deg,#E0ECFF,#CCE0FF); padding:2px 7px; border-radius:4px; }
.pd-ref-val--url  { color:#008F7A; font-size:9px; }

/* ── PROVENANCE ──────────────────────────────────────────────────── */
.pd-provenance-card {
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.06); border-radius:14px;
  overflow:hidden; margin-bottom:10px; position:relative;
}
.pd-provenance-card-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.9) 50%,transparent 80%); }
.pd-prov-row  { display:flex; align-items:center; justify-content:space-between; padding:9px 14px; border-bottom:1px solid rgba(0,0,0,.05); gap:8px; }
.pd-prov-row:last-child { border-bottom:none; }
.pd-prov-key  { font-size:9px; font-weight:600; color:#94A3B8; flex-shrink:0; }
.pd-prov-val  { font-size:10px; font-weight:600; color:#475569; text-align:right; flex:1; }
.pd-prov-code { font-size:10px; font-family:'JetBrains Mono',monospace; color:#0070E0; background:linear-gradient(135deg,#E0ECFF,#CCE0FF); padding:2px 7px; border-radius:4px; }

.pd-quality-notes { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border:1.5px solid rgba(204,136,0,.18); border-radius:12px; padding:13px; }
.pd-quality-hdr   { font-size:8px; font-weight:800; color:#CC8800; letter-spacing:.8px; text-transform:uppercase; margin-bottom:8px; }
.pd-quality-row   { display:flex; gap:8px; align-items:flex-start; margin-bottom:6px; }
.pd-quality-row:last-child { margin-bottom:0; }
.pd-quality-num   { font-size:9px; font-weight:800; color:#CC8800; font-family:'JetBrains Mono',monospace; flex-shrink:0; margin-top:1px; min-width:14px; }
.pd-quality-text  { font-size:10px; color:#0B1A30; line-height:1.5; }

/* ── IHR COMPLIANCE BLOCK ─────────────────────────────────────── */
.pd-ihr-block {
  background:linear-gradient(135deg, #030D1E 0%, #071428 100%);
  border:1.5px solid rgba(0,180,255,.15); border-radius:14px; padding:16px;
  position:relative; overflow:hidden;
}
.pd-ihr-block-shine { position:absolute; top:0; left:0; right:0; height:1px; background:linear-gradient(90deg,transparent 15%,rgba(0,180,255,.4) 50%,transparent 85%); }
.pd-ihr-title { font-size:11px; font-weight:800; color:#00B4FF; margin-bottom:10px; font-family:'DM Sans',sans-serif; }
.pd-ihr-text  { font-size:11px; color:rgba(255,255,255,.55); line-height:1.7; margin-bottom:14px; }
.pd-ihr-articles { display:flex; flex-direction:column; gap:7px; margin-bottom:14px; }
.pd-ihr-article  { display:flex; align-items:center; gap:10px; padding:8px 11px; background:rgba(0,180,255,.06); border-radius:8px; border:1px solid rgba(0,180,255,.1); }
.pd-ihr-art-num  { font-size:9px; font-weight:800; color:#00B4FF; font-family:'JetBrains Mono',monospace; flex-shrink:0; min-width:58px; }
.pd-ihr-art-text { font-size:10px; color:rgba(255,255,255,.55); }
.pd-ihr-offline-notice { display:flex; align-items:flex-start; gap:8px; padding:9px 11px; background:rgba(0,230,118,.05); border-radius:8px; border:1px solid rgba(0,230,118,.12); }
.pd-ihr-offline-dot { width:7px; height:7px; border-radius:50%; background:#00E676; flex-shrink:0; margin-top:3px; animation:pulse-green 2s infinite; }
.pd-ihr-offline-notice { font-size:10px; color:rgba(255,255,255,.4); line-height:1.5; }

/* ═══════════════════════════════════════════════════════════════════
   ADMIN CRUD — FAB, detail-modal action buttons, editor form.
   Matches Sentinel Dual-Tone: dark zone for FAB & editor header,
   light zone for the editor body.  Only surfaces when the logged-in
   user has role_key=NATIONAL_ADMIN (v-if="isAdmin" on every node).
═══════════════════════════════════════════════════════════════════ */

/* ── FAB cluster — safe-area aware, responsive, labelled ───── */
/* Ionic defaults `ion-fab[vertical=bottom]` to `bottom:10px`, which on
   devices with a home indicator / gesture bar gets clipped.  We pin the
   bottom to max(16px, safe-area-inset-bottom) and add a small right
   inset that scales up for wider viewports. */
.ph-fab-wrap {
  bottom: calc(18px + env(safe-area-inset-bottom, 0px)) !important;
  right:  max(14px, env(safe-area-inset-right, 0px)) !important;
  z-index: 20;
}
.ph-fab-list { overflow: visible !important; }   /* don't clip labels */
.ph-fab-main {
  --background: linear-gradient(135deg,#0070E0 0%,#00B4FF 100%);
  --color: #fff;
  --box-shadow: 0 10px 24px rgba(0,112,224,.38), 0 2px 6px rgba(7,14,27,.2);
  width: 58px; height: 58px;
}
.ph-fab-main ion-icon { font-size: 28px; }

/* Action buttons — circular icon + floating pill label to the left. */
.ph-fab-action {
  --color: #fff;
  --box-shadow: 0 6px 16px rgba(7,14,27,.28);
  width: 50px; height: 50px;
  margin: 7px 0;
  overflow: visible !important;
  position: relative;
}
.ph-fab-action ion-icon { font-size: 22px; }

/* Label pill — always visible whenever the action button itself is
   visible.  Renders as the host's ::before (pill) + ::after (chevron
   tick).  No hover / expand gating; the labels are a permanent part
   of the FAB's visual language. */
.ph-fab-action::before {
  content: attr(data-label);
  white-space: nowrap;
  position: absolute;
  right: calc(100% + 14px);
  top: 50%;
  transform: translateY(-50%);
  padding: 9px 16px 9px 18px;
  background: linear-gradient(135deg, rgba(7,14,27,.96) 0%, rgba(14,26,46,.96) 100%);
  color: #EDF2FA;
  font: 800 11.5px/1 'DM Sans', sans-serif;
  letter-spacing: .7px;
  text-transform: uppercase;
  border-radius: 12px;
  border: 1px solid rgba(255,255,255,.12);
  box-shadow: 0 14px 32px rgba(7,14,27,.38), 0 2px 6px rgba(0,0,0,.22),
              inset 0 1px 0 rgba(255,255,255,.05);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  pointer-events: none;
  z-index: 1;
}
.ph-fab-action::after {
  content: '';
  position: absolute;
  right: calc(100% + 8px);
  top: 50%;
  width: 9px; height: 9px;
  background: rgba(14,26,46,.96);
  border-top: 1px solid rgba(255,255,255,.12);
  border-right: 1px solid rgba(255,255,255,.12);
  transform: translateY(-50%) rotate(45deg);
  pointer-events: none;
  z-index: 1;
}
/* Per-colour accent strip along the inside-left edge of each pill. */
.ph-fab-poe::before  { box-shadow: 0 14px 32px rgba(0,230,118,.20), 0 2px 6px rgba(0,0,0,.22),
                                   inset 4px 0 0 #00E676, inset 0 1px 0 rgba(255,255,255,.05); }
.ph-fab-prov::before { box-shadow: 0 14px 32px rgba(179,136,255,.22), 0 2px 6px rgba(0,0,0,.22),
                                   inset 4px 0 0 #B388FF, inset 0 1px 0 rgba(255,255,255,.05); }
.ph-fab-dist::before { box-shadow: 0 14px 32px rgba(0,180,255,.22), 0 2px 6px rgba(0,0,0,.22),
                                   inset 4px 0 0 #00B4FF, inset 0 1px 0 rgba(255,255,255,.05); }
.ph-fab-hosp::before { box-shadow: 0 14px 32px rgba(255,82,82,.22), 0 2px 6px rgba(0,0,0,.22),
                                   inset 4px 0 0 #FF5252, inset 0 1px 0 rgba(255,255,255,.05); }

/* Gradients — shared with the earlier version so nothing visual shifts. */
.ph-fab-poe  { --background: linear-gradient(135deg,#00A85E 0%,#00E676 100%); }
.ph-fab-prov { --background: linear-gradient(135deg,#6A2BC7 0%,#B388FF 100%); }
.ph-fab-dist { --background: linear-gradient(135deg,#0070E0 0%,#00B4FF 100%); }
.ph-fab-hosp { --background: linear-gradient(135deg,#C62828 0%,#FF5252 100%); }

/* Tight screens: shrink icons + hide the sublabel line to keep labels
   on a single line even on tiny viewports. */
@media (max-height: 560px) {
  .ph-fab-main   { width: 52px; height: 52px; }
  .ph-fab-action { width: 44px; height: 44px; margin: 6px 0; }
  .ph-fab-main ion-icon { font-size: 25px; }
}
@media (max-width: 420px) {
  .ph-fab-action::before { padding: 7px 12px 7px 14px; font-size: 10.5px; border-radius: 10px; }
}
@media (max-width: 340px) {
  .ph-fab-wrap { right: 10px !important; }
  .ph-fab-action::before { font-size: 10px; padding: 6px 10px 6px 12px; letter-spacing: .5px; }
}

/* ── Admin action buttons in the detail-modal toolbar ──────── */
.pd-admin-actions { display:flex; gap:6px; margin-right:6px; }
.pd-admin-btn {
  display:flex; align-items:center; gap:5px;
  padding:6px 10px; border-radius:10px;
  border:1px solid rgba(255,255,255,.18);
  background:rgba(255,255,255,.08);
  color:#EDF2FA;
  font:800 10px/1 'DM Sans',sans-serif; letter-spacing:.5px; text-transform:uppercase;
  cursor:pointer; transition:all .18s;
}
.pd-admin-btn:hover { background:rgba(255,255,255,.14); transform:translateY(-1px); }
.pd-admin-btn--edit { color:#00E676; border-color:rgba(0,230,118,.35); }
.pd-admin-btn--edit:hover { background:rgba(0,230,118,.12); }
.pd-admin-btn--del  { color:#FF5252; border-color:rgba(255,82,82,.35); padding:6px 9px; }
.pd-admin-btn--del:hover  { background:rgba(255,82,82,.12); }
.pd-admin-btn ion-icon { font-size:14px; }

/* ── Editor modal (dark header, light body) ────────────────── */
.pe-header  { --background:#070E1B; }
.pe-toolbar { --background:linear-gradient(180deg,#070E1B 0%,#0E1A2E 100%); --color:#EDF2FA; --border-width:0; }
.pe-title-block { display:flex; flex-direction:column; gap:1px; }
.pe-eyebrow { font-size:7px; font-weight:700; color:#7E92AB; letter-spacing:1.2px; text-transform:uppercase; font-family:'DM Sans',sans-serif; }
.pe-title   { font-size:16px; font-weight:800; color:#EDF2FA; font-family:'Syne',sans-serif; line-height:1.15; }

.pe-body { --background:linear-gradient(180deg,#EEF2FA 0%,#FFFFFF 50%,#F4F7FC 100%); --color:#0B1A30; }

.pe-form { padding:18px 14px 4px; max-width:720px; margin:0 auto; }
.pe-field { margin-bottom:14px; }
.pe-label {
  display:block; font:800 10px/1.2 'DM Sans',sans-serif; color:#0B1A30;
  letter-spacing:.7px; text-transform:uppercase; margin-bottom:6px;
}
.pe-req      { color:#C62828; }
.pe-optional { color:#94A3B8; font-weight:600; text-transform:none; letter-spacing:0; }

.pe-input {
  --background:#fff; --color:#0B1A30; --border-radius:10px;
  --padding-start:12px; --padding-end:12px;
  background:#fff;
  border:1px solid rgba(11,26,48,.12);
  border-radius:10px;
  min-height:44px;
  font-family:'DM Sans',sans-serif;
}
.pe-input--area { min-height:96px; }
.pe-input:focus-within { border-color:#0070E0; box-shadow:0 0 0 3px rgba(0,112,224,.12); }

/* Native HTML form controls — match PoeContactsAdmin convention so the
   dropdowns work reliably on every browser and Ionic WebView. */
.pe-inp {
  width:100%;
  min-height:44px;
  padding:10px 12px;
  background:#fff;
  color:#0B1A30;
  border:1px solid rgba(11,26,48,.14);
  border-radius:10px;
  font:500 14px/1.25 'DM Sans', sans-serif;
  outline:none;
  transition:border-color .15s, box-shadow .15s;
  appearance:auto;            /* keep native chevron on <select> */
  -webkit-appearance:auto;
  box-sizing:border-box;
}
.pe-inp:focus { border-color:#0070E0; box-shadow:0 0 0 3px rgba(0,112,224,.14); }
.pe-inp:disabled {
  background:#F4F7FC;
  color:#94A3B8;
  border-color:rgba(11,26,48,.08);
  cursor:not-allowed;
}
.pe-inp::placeholder { color:#94A3B8; }
select.pe-inp { padding-right:32px; cursor:pointer; }
textarea.pe-inp { min-height:90px; resize:vertical; font-family:inherit; }
.pe-inp--area { min-height:96px; }

.pe-hint  { font:500 10px/1.4 'DM Sans',sans-serif; color:#6B7C96; margin-top:5px; }
.pe-error { font:800 10px/1.3 'DM Sans',sans-serif; color:#C62828; margin-top:5px; letter-spacing:.4px; text-transform:uppercase; }

.pe-field--toggles { display:flex; flex-direction:column; gap:0; margin-bottom:6px; }
.pe-toggle-item {
  --background:#fff; --inner-padding-end:0;
  --padding-start:12px; --border-radius:10px;
  border:1px solid rgba(11,26,48,.08);
  border-radius:10px; margin-bottom:6px;
  --min-height:46px;
}
.pe-toggle-item ion-label { font:600 13px/1.2 'DM Sans',sans-serif; color:#0B1A30; }

.pe-derived {
  margin:12px 0 24px; padding:11px 13px;
  background:linear-gradient(135deg,#F5F3FF 0%,#EDE9FE 100%);
  border:1px solid rgba(123,64,216,.2); border-radius:10px;
}
.pe-derived-hdr  { display:block; font:900 10px/1.3 'DM Sans',sans-serif; color:#6A2BC7; letter-spacing:.6px; margin-bottom:5px; text-transform:uppercase; }
.pe-derived-list { font:500 10px/1.6 'JetBrains Mono',monospace; color:#4A1E7A; word-break:break-word; }

.pe-actions { padding:4px 14px 26px; max-width:720px; margin:0 auto; }
.pe-save-btn {
  --background:linear-gradient(135deg,#0070E0 0%,#00B4FF 100%);
  --color:#fff; --box-shadow:0 6px 20px rgba(0,112,224,.3);
  font-weight:800; letter-spacing:.5px;
}
.pe-cancel-btn { --color:#6B7C96; font-weight:700; }
.pe-spinner    { margin-right:8px; width:18px; height:18px; }
</style>