<template>
  <IonPage class="ps-page">

    <!-- ═══════════════════════════════════════════════════════════
         HEADER — DARK ZONE — compact command strip
    ═══════════════════════════════════════════════════════════════ -->
    <IonHeader :translucent="false" class="ps-hdr">
      <IonToolbar class="ps-toolbar">
        <IonButtons slot="start">
          <IonBackButton default-href="/home" text="" style="--color:rgba(255,255,255,.8);" aria-label="Back"/>
        </IonButtons>
        <IonTitle class="ps-toolbar-title">
          <div class="ps-title-line">
            <span class="ps-title-poe">{{ auth?.poe_code || '—' }}</span>
            <span class="ps-title-sep">·</span>
            <span class="ps-title-label">Primary Screening</span>
          </div>
        </IonTitle>
        <IonButtons slot="end" style="padding-right:10px;gap:6px;">
          <div class="ps-net" :class="isOnline ? 'ps-net--on' : 'ps-net--off'"/>
          <button class="ps-hbtn" :class="openReferrals > 0 && 'ps-hbtn--alert'"
            @click="setTab('queue')" aria-label="Queue">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
              <rect x="2" y="3" width="12" height="10" rx="1.5"/>
              <line x1="5" y1="7" x2="11" y2="7"/><line x1="5" y1="10" x2="8.5" y2="10"/>
            </svg>
            <span v-if="openReferrals > 0" class="ps-hbadge">{{ openReferrals > 9 ? '9+' : openReferrals }}</span>
          </button>
          <button class="ps-hbtn" :class="syncing && 'ps-hbtn--spin'"
            :disabled="syncing" @click="manualSync" aria-label="Sync">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"
              stroke-linecap="round" :class="syncing && 'ps-spin'">
              <path d="M13 7A6 6 0 1 1 7 2"/><polyline points="13 2 13 7 8 7"/>
            </svg>
            <span v-if="pendingCount > 0 && !syncing" class="ps-hbadge ps-hbadge--amber">{{ pendingCount }}</span>
          </button>
        </IonButtons>
      </IonToolbar>

      <!-- Stats strip + tabs inside IonHeader, outside IonToolbar -->
      <div class="ps-below-toolbar">
        <!-- Compact 5-stat strip -->
        <div class="ps-stats">
          <div class="ps-s" title="Screened today">
            <span class="ps-s-n">{{ todayCount }}</span>
            <span class="ps-s-l">Today</span>
          </div>
          <div class="ps-sdiv"/>
          <div class="ps-s">
            <span class="ps-s-n ps-s-n--red">{{ symptomCount }}</span>
            <span class="ps-s-l">Sympt.</span>
          </div>
          <div class="ps-sdiv"/>
          <div class="ps-s">
            <span class="ps-s-n ps-s-n--green">{{ syncedCount }}</span>
            <span class="ps-s-l">Synced</span>
          </div>
          <div class="ps-sdiv"/>
          <div class="ps-s">
            <span class="ps-s-n" :class="pendingCount > 0 ? 'ps-s-n--amber' : 'ps-s-n--green'">{{ pendingCount }}</span>
            <span class="ps-s-l">Pending</span>
          </div>
          <div class="ps-sdiv"/>
          <div class="ps-s">
            <span class="ps-s-n" :class="openReferrals > 0 ? 'ps-s-n--red' : 'ps-s-n--green'">{{ openReferrals }}</span>
            <span class="ps-s-l">Queue</span>
          </div>
        </div>
        <!-- Tabs -->
        <div class="ps-tabs">
          <button class="ps-tab" :class="tab==='capture' && 'ps-tab--on'" @click="setTab('capture')">Capture</button>
          <button class="ps-tab" :class="tab==='records' && 'ps-tab--on'" @click="setTab('records')">Records</button>
          <button class="ps-tab" :class="tab==='queue'   && 'ps-tab--on'" @click="setTab('queue')">
            Referral Queue<span v-if="openReferrals > 0" class="ps-tab-dot"/>
          </button>
        </div>
      </div>
    </IonHeader>

    <!-- ═══════════════════════════════════════════════════════════
         LIGHT ZONE — Content
    ═══════════════════════════════════════════════════════════════ -->
    <IonContent :fullscreen="true" :scroll-y="true" class="ps-content">
      <IonRefresher slot="fixed" @ionRefresh="e => loadStats().then(() => e.target.complete())">
        <IonRefresherContent refreshing-spinner="crescent"/>
      </IonRefresher>

      <!-- Permission guard — diagnoses the precise failure so users + admins
           can self-resolve instead of seeing a generic "Access Restricted". -->
      <div v-if="!canScreen" class="ps-guard" role="alert">
        <span class="ps-guard-ic">🛡</span>
        <div>
          <div class="ps-guard-title">Cannot start primary screening</div>
          <div class="ps-guard-sub" v-if="!auth?.id">
            Not signed in. Tap the menu and sign in to continue.
          </div>
          <div class="ps-guard-sub" v-else-if="!auth?.is_active">
            Your account is inactive. Ask an admin to reactivate it.
          </div>
          <div class="ps-guard-sub" v-else-if="!auth?._permissions?.can_do_primary_screening">
            Role {{ auth?.role_key }} is not permitted to perform primary screening.
          </div>
          <div class="ps-guard-sub" v-else-if="!auth?.poe_code">
            Your account has no Point of Entry assigned. Ask an admin to set
            <code>poe_code</code> on your <code>user_assignments</code> row.
          </div>
        </div>
      </div>

      <!-- ════════════════════════════════════════════════════════
           TAB: CAPTURE — zero-scroll single viewport
      ════════════════════════════════════════════════════════ -->
      <div v-show="tab === 'capture'" class="ps-capture">

        <!-- Sync error dismissible -->
        <div v-if="syncError" class="ps-sync-err" role="alert">
          <span>{{ syncError }}</span>
          <button @click="syncError = ''" aria-label="Dismiss">✕</button>
        </div>

        <!-- 2026-05-07: Voice fill removed app-wide. Passport scan remains
             the sole capture accelerator — see modal below. -->

        <!-- ── PASSPORT SCAN — always visible, fills gender + name silently ── -->
        <!-- Scanning passport fills ALL available fields. For asymptomatic
             travellers only gender is written to the form; for symptomatic
             travellers name + nationality + DOB are also captured. -->
        <button
          type="button"
          class="ps-passport-btn"
          :disabled="scanBusy"
          aria-label="Scan passport to auto-fill traveler details"
          @click="scanForName"
        >
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none"
            stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
            stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="4" width="18" height="16" rx="2"/>
            <circle cx="12" cy="11" r="3"/>
            <path d="M7 18h10M7 20.5h6"/>
          </svg>
          <span>{{ scanBusy ? t('Opening camera…') : t('Scan Passport / ID') }}</span>
        </button>

        <!-- ── DIRECTION ─────────────────────────────────────── -->
        <div class="ps-field-row">
          <span class="ps-field-lbl">{{ t('Direction') }}</span>
          <div class="ps-dir-pills">
            <button v-for="d in DIRS" :key="d.v"
              class="ps-dir-pill"
              :class="form.direction === d.v && ('ps-dir-pill--' + d.k)"
              @click="form.direction = d.v; clearE('direction')"
              :aria-pressed="form.direction === d.v">
              {{ d.label }}
            </button>
          </div>
          <span v-if="errors.direction" class="ps-ferr">{{ errors.direction }}</span>
        </div>

        <!-- ── SEX ──────────────────────────────────────────── -->
        <div class="ps-field-row">
          <span class="ps-field-lbl">{{ t('Sex') }}</span>
          <div class="ps-sex-pills">
            <button
              class="ps-sex-btn ps-sex-btn--m"
              :class="form.gender === 'MALE' && 'ps-sex-btn--active ps-sex-btn--m-active'"
              @click="form.gender = 'MALE'; clearE('gender')"
              :aria-pressed="form.gender === 'MALE'">
              ♂ {{ t('Male') }}
            </button>
            <button
              class="ps-sex-btn ps-sex-btn--f"
              :class="form.gender === 'FEMALE' && 'ps-sex-btn--active ps-sex-btn--f-active'"
              @click="form.gender = 'FEMALE'; clearE('gender')"
              :aria-pressed="form.gender === 'FEMALE'">
              ♀ {{ t('Female') }}
            </button>
          </div>
          <span v-if="errors.gender" class="ps-ferr">{{ errors.gender }}</span>
        </div>

        <!-- ── TEMPERATURE — SMART INPUT (premium, native-feel layout) ───── -->
        <!-- Quick chips on row 1 (36.0 / 36.5 / 37.0), manual stepper on
             row 2. Anything >= 37.5C is abnormal and must be entered via
             the manual stepper so the screener verifies the elevated value.
             Range guard: 30-43C clinical bounds. -->
        <div class="ps-temp-card">
          <div class="ps-temp-head">
            <span class="ps-temp-title">
              {{ t('Temperature') }}
              <span class="ps-temp-optional">{{ t('Optional') }}</span>
            </span>
            <span class="ps-temp-hint">&ge; 37.5&deg;C &rarr; enter manually</span>
          </div>

          <!-- Quick-pick chips hidden 2026-05-06 by mandate. Manual entry only. -->
          <div v-if="false" class="ps-temp-quick" role="group" aria-label="Quick temperature">
            <button
              v-for="qv in [36.0, 36.5, 37.0]" :key="qv"
              type="button"
              class="ps-temp-chip"
              :class="{ 'ps-temp-chip--on': Number(form.temp) === qv }"
              :aria-pressed="Number(form.temp) === qv"
              @click="setTempQuick(qv)"
            >
              <span class="ps-temp-chip-num">{{ qv.toFixed(1) }}</span>
              <span class="ps-temp-chip-unit">&deg;C</span>
            </button>
          </div>

          <!-- Manual stepper row -->
          <div class="ps-temp-manual"
            :class="[focusTemp && 'ps-temp-manual--focus', tempLevel === 'crit' && 'ps-temp-manual--crit', tempLevel === 'warn' && 'ps-temp-manual--warn']">
            <input
              v-model="form.temp"
              type="number" step="0.1" min="30" max="43"
              class="ps-temp-manual-input"
              placeholder="Manual entry e.g. 38.2"
              inputmode="decimal"
              aria-label="Temperature value (manual)"
              @focus="focusTemp=true"
              @blur="focusTemp=false; validateTemp()"
              @input="validateTempSoft()"
            />
            <span class="ps-temp-manual-unit">&deg;C</span>
            <div v-if="form.temp" class="ps-temp-badge" :class="'ps-temp-badge--' + (tempLevel || 'normal')">
              {{ tempLevel === 'crit' ? 'High fever' : tempLevel === 'warn' ? 'Elevated' : 'Normal' }}
            </div>
            <button v-if="form.temp" type="button" class="ps-temp-clear" aria-label="Clear temperature"
              @click="form.temp=''; clearE('temp')">&times;</button>
          </div>

          <div v-if="errors.temp" class="ps-ferr ps-ferr--temp">{{ errors.temp }}</div>
          <div v-else-if="errors.tempWarn" class="ps-fwarn ps-fwarn--temp">{{ errors.tempWarn }}</div>
        </div>

        <!-- ── FEVER AUTO-GUARD BANNER ──────────────────────── -->
        <transition name="ps-reveal">
          <div v-if="feverAutoGuard" class="ps-fever-guard" role="alert">
            <svg viewBox="0 0 16 16" fill="none" stroke="#CC8800" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
              <path d="M8 1L1 14h14L8 1z"/><line x1="8" y1="5.5" x2="8" y2="9.5"/><circle cx="8" cy="11.5" r=".7" fill="#CC8800"/>
            </svg>
            <div class="ps-fever-guard-body">
              <div class="ps-fever-guard-title">Clinical Intelligence Alert</div>
              <div class="ps-fever-guard-text">{{ feverAutoGuard }}</div>
              <div class="ps-fever-guard-actions">
                <button class="ps-fever-guard-btn ps-fever-guard-btn--sym" type="button"
                  @click="form.symptoms = 1; clearE('symptoms')">
                  Mark Symptomatic
                </button>
                <button class="ps-fever-guard-btn ps-fever-guard-btn--clear" type="button"
                  @click="form.temp = ''; clearE('temp')">
                  Clear Temperature
                </button>
              </div>
            </div>
          </div>
        </transition>

        <!-- ── WHO SYMPTOMS — categorized, compact panel ────── -->
        <div class="ps-who-panel">
          <button class="ps-who-toggle" @click="whoOpen=!whoOpen" :aria-expanded="whoOpen" type="button">
            <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
              <circle cx="7" cy="7" r="5.5"/>
              <line x1="7" y1="5" x2="7" y2="8.5"/>
              <circle cx="7" cy="10.5" r=".6" fill="currentColor"/>
            </svg>
            IHR Surveillance Symptoms (WHO reference)
            <svg class="ps-who-chev" :class="whoOpen && 'ps-who-chev--open'"
              viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
              <polyline points="2 4 5 7 8 4"/>
            </svg>
          </button>
          <transition name="ps-acc">
            <div v-if="whoOpen" class="ps-who-cats">
              <div v-for="cat in WHO_CATS" :key="cat.name" class="ps-who-cat">
                <span class="ps-who-cat-label">{{ cat.name }}</span>
                <div class="ps-who-chips">
                  <span v-for="s in cat.items" :key="s" class="ps-who-chip">{{ s }}</span>
                </div>
              </div>
            </div>
          </transition>
        </div>

        <!-- ── SYMPTOMS — hero YES/NO ──────────────────────── -->
        <div class="ps-sym-section">
          <div class="ps-sym-label">
            <span class="ps-field-lbl">{{ t('Symptoms present?') }}</span>
            <span class="ps-req-badge">{{ t('IHR Required') }}</span>
          </div>
          <div class="ps-sym-row">
            <button
              class="ps-sym-no"
              :class="form.symptoms === 0 && 'ps-sym-no--active'"
              @click="form.symptoms = 0; clearE('symptoms')"
              :aria-pressed="form.symptoms === 0"
              aria-label="No symptoms">
              <div class="ps-sym-ic" aria-hidden="true">
                <svg viewBox="0 0 28 28" fill="none" stroke-width="2.4" stroke-linecap="round"
                  :stroke="form.symptoms===0 ? '#fff' : '#94A3B8'">
                  <polyline points="4 14 11 21 24 8"/>
                </svg>
              </div>
              <span class="ps-sym-main">{{ t('Clear') }}</span>
              <span class="ps-sym-sub">{{ t('No symptoms') }}</span>
            </button>

            <button
              class="ps-sym-yes"
              :class="form.symptoms === 1 && 'ps-sym-yes--active'"
              @click="form.symptoms = 1; clearE('symptoms')"
              :aria-pressed="form.symptoms === 1"
              aria-label="Symptoms present — referral will be created">
              <div class="ps-sym-ic" aria-hidden="true">
                <svg viewBox="0 0 28 28" fill="none" stroke-width="2.4" stroke-linecap="round"
                  :stroke="form.symptoms===1 ? '#fff' : '#94A3B8'">
                  <circle cx="14" cy="14" r="10"/>
                  <line x1="14" y1="9" x2="14" y2="16"/>
                  <circle cx="14" cy="20" r="1.2" :fill="form.symptoms===1 ? '#fff' : '#94A3B8'"/>
                </svg>
              </div>
              <span class="ps-sym-main">{{ t('Symptomatic') }}</span>
              <span class="ps-sym-sub">{{ t('Referral created') }}</span>
            </button>
          </div>
          <span v-if="errors.symptoms" class="ps-ferr">{{ errors.symptoms }}</span>
        </div>

        <!-- ── NAME — progressive reveal on symptomatic ─────── -->
        <!-- ── QUICK SYMPTOM CHIPS — shown when symptomatic ─────────── -->
        <transition name="ps-reveal">
          <div v-if="form.symptoms === 1" class="ps-qsym-section">
            <div class="ps-qsym-head">
              <span class="ps-qsym-title">{{ t('Symptom categories') }}</span>
              <span class="ps-qsym-hint">{{ t('Tap one or more') }}</span>
            </div>
            <div class="ps-qsym-grid">
              <button
                v-for="s in QUICK_SYMPTOMS" :key="s.code"
                type="button"
                class="ps-qsym-card"
                :class="{ 'ps-qsym-card--on': quickSymptoms.includes(s.code) }"
                :aria-pressed="quickSymptoms.includes(s.code)"
                @click="quickSymptoms.includes(s.code)
                  ? quickSymptoms.splice(quickSymptoms.indexOf(s.code), 1)
                  : quickSymptoms.push(s.code)">
                <span class="ps-qsym-check" aria-hidden="true">
                  <svg v-if="quickSymptoms.includes(s.code)" viewBox="0 0 12 12" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round">
                    <polyline points="2 6 5 9 10 3"/>
                  </svg>
                </span>
                <span class="ps-qsym-label">{{ s.label }}</span>
              </button>
            </div>
          </div>
        </transition>

        <transition name="ps-reveal">
          <div v-if="form.symptoms === 1" class="ps-name-section">
            <div class="ps-name-row">
              <span class="ps-field-lbl">{{ t('Traveller Name') }} <span class="ps-req">*</span></span>
              <div class="ps-name-field" :class="[focusName && 'ps-name-field--focus', errors.name && 'ps-name-field--err']">
                <input
                  ref="nameRef"
                  v-model="form.name"
                  class="ps-name-input"
                  type="text"
                  placeholder="Full name as on travel document"
                  maxlength="150"
                  autocomplete="off"
                  autocapitalize="characters"
                  aria-label="Traveler full name"
                  @focus="focusName=true"
                  @blur="focusName=false"
                  @input="clearE('name')"
                />
                <button v-if="form.name" class="ps-name-clear" @click="form.name=''" aria-label="Clear" type="button">✕</button>
              </div>
              <span v-if="errors.name" class="ps-ferr">{{ errors.name }}</span>
              <span v-if="scanHint" class="ps-fwarn">{{ scanHint }}</span>
            </div>
            <!-- Priority preview inline -->
            <div class="ps-priority-strip" :class="'ps-priority-strip--' + priority.toLowerCase()">
              <div class="ps-pd" :class="'ps-pd--' + priority.toLowerCase()"/>
              <span class="ps-pl">{{ priority }} PRIORITY</span>
              <span class="ps-pr">{{ priorityReason }}</span>
            </div>
          </div>
        </transition>

        <!-- ── CAPTURE BUTTON ──────────────────────────────── -->
        <div class="ps-capture-footer">
          <button
            class="ps-cap-btn"
            :class="[
              canCapture && form.symptoms === 1 && 'ps-cap-btn--referral',
              canCapture && form.symptoms === 0 && 'ps-cap-btn--clear',
              (!canCapture || capturing) && 'ps-cap-btn--disabled',
              capturing && 'ps-cap-btn--busy',
            ]"
            :disabled="!canCapture || capturing"
            @click="captureScreening"
            aria-label="Capture record">
            <div class="ps-cap-btn-inner">
              <svg v-if="!capturing" viewBox="0 0 20 20" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="10" cy="10" r="8"/>
                <polyline points="6 10 9 13 14 7"/>
              </svg>
              <svg v-else viewBox="0 0 20 20" fill="none" stroke="currentColor"
                stroke-width="2" stroke-linecap="round" class="ps-spin" aria-hidden="true">
                <path d="M18 10A8 8 0 1 1 10 2"/>
              </svg>
              <span>{{ capturing ? 'Saving…' : (form.symptoms === 1 ? 'Capture & Refer →' : 'Capture & Save →') }}</span>
            </div>
          </button>
          <div v-if="!canCapture && !capturing" class="ps-cap-hint">{{ captureHint }}</div>
        </div>

        <!-- ── RESULT PANEL ────────────────────────────────── -->
        <transition name="ps-result">
          <div v-if="lastResult" class="ps-result"
            :class="lastResult.symptoms_present === 1 ? 'ps-result--ref' : 'ps-result--ok'">
            <div class="ps-result-hdr">
              <div class="ps-result-icon" aria-hidden="true">
                {{ lastResult.symptoms_present === 1 ? '🔔' : '✓' }}
              </div>
              <div class="ps-result-title">
                {{ lastResult.symptoms_present === 1 ? 'Saved · Referral Created' : 'Saved · Traveler Cleared' }}
              </div>
              <div class="ps-result-count">
                <span class="ps-result-n">{{ todayCount }}</span>
                <span class="ps-result-l">today</span>
              </div>
            </div>
            <div class="ps-result-chips">
              <span class="ps-rc">{{ lastResult.traveler_direction }}</span>
              <span class="ps-rc">{{ lastResult.gender }}</span>
              <span v-if="lastResult.traveler_full_name" class="ps-rc">{{ lastResult.traveler_full_name }}</span>
              <span v-if="lastResult.temperature_value" class="ps-rc ps-rc--temp">
                {{ lastResult.temperature_value.toFixed(1) }}°{{ lastResult.temperature_unit }}
              </span>
              <span v-if="lastResult.symptoms_present === 1" class="ps-rc ps-rc--priority"
                :class="'ps-rc--' + (lastResult.notification?.priority || 'NORMAL').toLowerCase()">
                {{ lastResult.notification?.priority || 'NORMAL' }}
              </span>
              <span class="ps-rc ps-rc--sync">{{ SYNC.LABELS[lastResult.sync_status] || lastResult.sync_status }}</span>
            </div>
            <div class="ps-result-actions">
              <button class="ps-result-void" @click="promptVoid" type="button">Void Record</button>
              <button class="ps-result-next" @click="resetForm" type="button">Next Traveler →</button>
            </div>
          </div>
        </transition>

      </div><!-- /capture -->

      <!-- ════════════════════════════════════════════════════════
           TAB: RECORDS
      ════════════════════════════════════════════════════════ -->
      <div v-show="tab === 'records'" class="ps-records-tab">
        <div class="ps-tab-bar">
          <button class="ps-filter-btn" @click="filterOpen=true" type="button" aria-label="Filter">
            <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
              <path d="M1 2h12M3 6h8M5 10h4"/>
            </svg>
            {{ filterLabel }}
          </button>
          <span class="ps-total-lbl">{{ recordsTotal }} record{{ recordsTotal !== 1 ? 's' : '' }}</span>
          <button class="ps-icon-btn" @click="loadRecords()" :disabled="recLoading" type="button" aria-label="Refresh">
            <svg :class="recLoading && 'ps-spin'" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
              <path d="M12 7A5 5 0 1 1 7 2"/><polyline points="12 2 12 7 7 7"/>
            </svg>
          </button>
        </div>
        <div class="ps-filter-row">
          <button class="ps-fc" :class="fSym==='ALL' && 'ps-fc--on'" @click="fSym='ALL'; loadRecords()" type="button">All</button>
          <button class="ps-fc ps-fc--sym" :class="fSym==='YES' && 'ps-fc--on'" @click="fSym='YES'; loadRecords()" type="button">Symptomatic</button>
          <button class="ps-fc ps-fc--ok"  :class="fSym==='NO'  && 'ps-fc--on'" @click="fSym='NO';  loadRecords()" type="button">Clear</button>
          <button class="ps-fc ps-fc--pending" :class="fSync==='UNSYNCED' && 'ps-fc--on'"
            @click="fSync = fSync==='UNSYNCED' ? 'ALL' : 'UNSYNCED'; loadRecords()" type="button">Pending</button>
          <button class="ps-fc" :class="fDir!=='ALL' && 'ps-fc--on ps-fc--dir'"
            @click="cycleDir" type="button">{{ fDir === 'ALL' ? 'All Dirs' : fDir }}</button>
        </div>
        <div v-if="recLoading" class="ps-loading">
          <div class="ps-dots"><div/><div/><div/></div>
        </div>
        <div v-else-if="records.length === 0" class="ps-empty">
          <svg viewBox="0 0 40 40" fill="none" stroke="#CBD5E1" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
            <rect x="6" y="4" width="28" height="32" rx="2.5"/>
            <line x1="12" y1="13" x2="28" y2="13"/>
            <line x1="12" y1="19" x2="28" y2="19"/>
            <line x1="12" y1="25" x2="21" y2="25"/>
          </svg>
          <p>No records for {{ filterLabel }}</p>
        </div>
        <div v-else class="ps-rec-list">
          <div v-for="r in records" :key="r.client_uuid"
            class="ps-rec-card"
            :class="[r.symptoms_present===1 && 'ps-rec-card--sym', r.record_status==='VOIDED' && 'ps-rec-card--void']"
            @click="selRecord=r"
            tabindex="0" role="button" :aria-label="(r.traveler_full_name || r.gender) + ' · ' + fmtTime(r.captured_at)">
            <div class="ps-rec-bar" :class="r.symptoms_present===1 ? 'ps-rec-bar--sym' : 'ps-rec-bar--ok'"/>
            <div class="ps-rec-body">
              <div class="ps-rec-top">
                <span class="ps-rec-name">{{ r.traveler_full_name || r.gender }}</span>
                <span class="ps-rec-time">{{ fmtTime(r.captured_at) }}</span>
              </div>
              <div class="ps-rec-mid">
                <span>{{ r.gender }}</span>
                <span class="ps-rec-dot">·</span>
                <span>{{ r.traveler_direction }}</span>
                <template v-if="r.temperature_value">
                  <span class="ps-rec-dot">·</span>
                  <span>{{ r.temperature_value.toFixed(1) }}°{{ r.temperature_unit }}</span>
                </template>
              </div>
              <div class="ps-rec-bot">
                <span class="ps-rbadge" :class="r.symptoms_present===1 ? 'ps-rbadge--sym' : 'ps-rbadge--ok'">
                  {{ r.symptoms_present===1 ? 'Symptomatic' : 'Clear' }}
                </span>
                <span v-if="r.record_status==='VOIDED'" class="ps-rbadge ps-rbadge--void">VOIDED</span>
                <span class="ps-rsync" :class="'ps-rsync--' + syncCls(r.sync_status)">
                  {{ SYNC.LABELS[r.sync_status] || r.sync_status }}
                </span>
              </div>
            </div>
            <span class="ps-rec-arrow">›</span>
          </div>
          <div v-if="recordsTotal > PAGE_SIZE" class="ps-pages">
            <button class="ps-pg-btn" :disabled="page===0" @click="page--;loadRecords(false)">← Prev</button>
            <span class="ps-pg-info">{{ page+1 }} / {{ Math.ceil(recordsTotal/PAGE_SIZE) }}</span>
            <button class="ps-pg-btn" :disabled="(page+1)*PAGE_SIZE>=recordsTotal" @click="page++;loadRecords(false)">Next →</button>
          </div>
        </div>
      </div><!-- /records -->

      <!-- ════════════════════════════════════════════════════════
           TAB: QUEUE — referral queue
      ════════════════════════════════════════════════════════ -->
      <div v-show="tab === 'queue'" class="ps-queue-tab">
        <div class="ps-tab-bar">
          <span class="ps-total-lbl" style="font-weight:700;color:#0B1A30;">Referral Queue</span>
          <button class="ps-fc" :class="qFilter==='OPEN' && 'ps-fc--on'" @click="qFilter='OPEN'; loadQueue()" type="button">Open</button>
          <button class="ps-fc" :class="qFilter==='ALL'  && 'ps-fc--on'" @click="qFilter='ALL';  loadQueue()" type="button">All</button>
          <button class="ps-icon-btn" @click="loadQueue()" :disabled="qLoading" type="button" aria-label="Refresh">
            <svg :class="qLoading && 'ps-spin'" viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
              <path d="M12 7A5 5 0 1 1 7 2"/><polyline points="12 2 12 7 7 7"/>
            </svg>
          </button>
        </div>
        <div v-if="qLoading" class="ps-loading"><div class="ps-dots"><div/><div/><div/></div></div>
        <div v-else-if="queue.length===0" class="ps-empty">
          <svg viewBox="0 0 40 40" fill="none" stroke="#CBD5E1" stroke-width="1.5" stroke-linecap="round" aria-hidden="true">
            <path d="M7 10a4 4 0 018 0M25 10a4 4 0 018 0M3 35c0-7.7 7-14 17-14s17 6.3 17 14"/>
            <circle cx="11" cy="10" r="4"/><circle cx="29" cy="10" r="4"/><circle cx="20" cy="16" r="5"/>
          </svg>
          <p>No referrals in queue</p>
        </div>
        <div v-else class="ps-q-list">
          <div v-for="q in queue" :key="q.client_uuid"
            class="ps-q-card"
            :class="['ps-q-card--' + (q.priority||'NORMAL').toLowerCase(), q.status==='CLOSED' && 'ps-q-card--closed']"
            @click="selQueue=q" tabindex="0" role="button">
            <div class="ps-q-top-bar" :class="'ps-qtb--' + (q.priority||'NORMAL').toLowerCase()"/>
            <div class="ps-q-body">
              <div class="ps-q-row1">
                <span class="ps-q-pri" :class="'ps-q-pri--' + (q.priority||'NORMAL').toLowerCase()">{{ q.priority || 'NORMAL' }}</span>
                <span class="ps-q-sts">{{ q.status }}</span>
                <span class="ps-q-time">{{ fmtTime(q.created_at) }}</span>
              </div>
              <div class="ps-q-name">{{ q.primary?.traveler_full_name || q.primary?.gender || 'Traveler' }}</div>
              <div class="ps-q-chips">
                <span v-if="q.primary?.traveler_direction" class="ps-qc">{{ q.primary.traveler_direction }}</span>
                <span v-if="q.primary?.gender" class="ps-qc">{{ q.primary.gender }}</span>
                <span v-if="q.primary?.temperature_value" class="ps-qc ps-qc--temp">
                  {{ q.primary.temperature_value.toFixed(1) }}°C
                </span>
                <template v-if="q.primary?.quick_symptoms_json">
                  <span v-for="sym in (JSON.parse(q.primary.quick_symptoms_json || '[]')).slice(0,4)"
                    :key="sym" class="ps-qc ps-qc--sym">
                    {{ sym.replace(/_/g,' ') }}
                  </span>
                  <span v-if="(JSON.parse(q.primary.quick_symptoms_json || '[]')).length > 4" class="ps-qc ps-qc--more">
                    +{{ (JSON.parse(q.primary.quick_symptoms_json || '[]')).length - 4 }}
                  </span>
                </template>
              </div>
            </div>
          </div>
        </div>
      </div><!-- /queue -->

    </IonContent><!-- /IonContent -->

    <!-- ═══════════════════════════════════════════════════════
         VOID MODAL
    ═══════════════════════════════════════════════════════════ -->
    <IonModal :is-open="showVoid" :breakpoints="[0,1]" :initial-breakpoint="1"
      @ionModalDidDismiss="showVoid=false; voidReason=''">
      <IonHeader :translucent="false">
        <IonToolbar style="--background:linear-gradient(180deg,#070E1B,#0E1A2E);--color:#EDF2FA;--border-width:0;">
          <IonButtons slot="start"><IonButton @click="showVoid=false" style="--color:rgba(255,255,255,.8);" aria-label="Cancel"><IonIcon :icon="closeOutline"/></IonButton></IonButtons>
          <IonTitle style="font-family:system-ui,sans-serif;font-weight:700;color:#EDF2FA;">Void Record</IonTitle>
        </IonToolbar>
      </IonHeader>
      <IonContent :scroll-y="true" style="--background:#F8FAFC;--color:#0B1A30;">
        <div class="ps-void-wrap">
          <div class="ps-void-warn">
            <svg viewBox="0 0 16 16" fill="none" stroke="#B45309" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><path d="M8 2L1 14h14L8 2z"/><line x1="8" y1="7" x2="8" y2="10"/><circle cx="8" cy="12.5" r=".6" fill="#B45309"/></svg>
            <div><strong>This cannot be undone.</strong> The record will be permanently voided and linked referrals closed.</div>
          </div>
          <label class="ps-void-lbl">Reason <span style="color:#E02050">*</span></label>
          <textarea v-model="voidReason" class="ps-void-ta" rows="4"
            placeholder="Minimum 10 characters — describe why this record is voided…"
            maxlength="500" aria-label="Void reason" aria-required="true"/>
          <div class="ps-void-meta" :class="voidReason.length > 0 && voidReason.length < 10 && 'ps-void-meta--err'">
            {{ voidReason.length }}/500 · min 10 chars
          </div>
          <button class="ps-void-submit" :disabled="voidReason.trim().length < 10 || voiding" @click="confirmVoid" type="button">
            {{ voiding ? 'Voiding…' : 'Confirm Void' }}
          </button>
        </div>
      </IonContent>
    </IonModal>

    <!-- ═══════════════════════════════════════════════════════
         RECORD DETAIL MODAL
    ═══════════════════════════════════════════════════════════ -->
    <IonModal :is-open="!!selRecord" :breakpoints="[0,1]" :initial-breakpoint="1"
      @ionModalDidDismiss="selRecord=null">
      <IonHeader :translucent="false" v-if="selRecord">
        <IonToolbar style="--background:linear-gradient(180deg,#070E1B,#0E1A2E);--color:#EDF2FA;--border-width:0;">
          <IonButtons slot="start"><IonButton @click="selRecord=null" style="--color:rgba(255,255,255,.8);" aria-label="Close"><IonIcon :icon="closeOutline"/></IonButton></IonButtons>
          <IonTitle style="font-family:system-ui,sans-serif;font-weight:700;color:#EDF2FA;">Screening Record</IonTitle>
        </IonToolbar>
      </IonHeader>
      <IonContent :scroll-y="true" v-if="selRecord" style="--background:#F8FAFC;--color:#0B1A30;">
        <div class="ps-det-wrap">
          <div class="ps-det-status" :class="selRecord.symptoms_present===1 ? 'ps-det-status--sym' : 'ps-det-status--ok'">
            <span>{{ selRecord.symptoms_present===1 ? 'Symptomatic — Referral Created' : 'Clear — No Symptoms' }}</span>
            <span v-if="selRecord.record_status==='VOIDED'" class="ps-det-voided">VOIDED</span>
          </div>
          <div class="ps-det-grid">
            <div class="ps-dg-row"><span class="ps-dk">Direction</span><span class="ps-dv">{{ selRecord.traveler_direction || '—' }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">Sex</span><span class="ps-dv">{{ selRecord.gender }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">Full Name</span><span class="ps-dv">{{ selRecord.traveler_full_name || '—' }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">Temperature</span><span class="ps-dv">{{ selRecord.temperature_value ? selRecord.temperature_value + '°' + selRecord.temperature_unit : 'Not recorded' }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">Symptoms</span><span class="ps-dv" :class="selRecord.symptoms_present===1 ? 'ps-dv--red' : 'ps-dv--green'">{{ selRecord.symptoms_present===1 ? 'Present' : 'None detected' }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">POE</span><span class="ps-dv">{{ selRecord.poe_code }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">Captured</span><span class="ps-dv">{{ fmtDateTime(selRecord.captured_at) }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">Sync</span><span class="ps-dv">{{ SYNC.LABELS[selRecord.sync_status] || selRecord.sync_status }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">Server ID</span><span class="ps-dv ps-dv--mono">{{ selRecord.id || 'Not synced' }}</span></div>
            <div v-if="selRecord.void_reason" class="ps-dg-row"><span class="ps-dk">Void Reason</span><span class="ps-dv" style="color:#B45309">{{ selRecord.void_reason }}</span></div>
          </div>
          <div v-if="selRecord.record_status !== 'VOIDED'" style="padding:16px 16px 32px;">
            <button class="ps-det-void-btn" @click="selRecord=null; promptVoidRecord(selRecord)" type="button">
              Void This Record
            </button>
          </div>
        </div>
      </IonContent>
    </IonModal>

    <!-- ═══════════════════════════════════════════════════════
         QUEUE DETAIL MODAL
    ═══════════════════════════════════════════════════════════ -->
    <IonModal :is-open="!!selQueue" :breakpoints="[0,1]" :initial-breakpoint="1"
      @ionModalDidDismiss="selQueue=null">
      <IonHeader :translucent="false" v-if="selQueue">
        <IonToolbar style="--background:linear-gradient(180deg,#070E1B,#0E1A2E);--color:#EDF2FA;--border-width:0;">
          <IonButtons slot="start"><IonButton @click="selQueue=null" style="--color:rgba(255,255,255,.8);" aria-label="Close"><IonIcon :icon="closeOutline"/></IonButton></IonButtons>
          <IonTitle style="font-family:system-ui,sans-serif;font-weight:700;color:#EDF2FA;">Referral Details</IonTitle>
        </IonToolbar>
      </IonHeader>
      <IonContent :scroll-y="true" v-if="selQueue" style="--background:#F8FAFC;--color:#0B1A30;">
        <div class="ps-det-wrap">
          <div class="ps-det-status" :class="'ps-det-status--' + (selQueue.priority||'NORMAL').toLowerCase()">
            {{ selQueue.priority || 'NORMAL' }} PRIORITY · {{ selQueue.status }}
          </div>
          <div class="ps-det-grid">
            <div class="ps-dg-row"><span class="ps-dk">Traveler</span><span class="ps-dv">{{ selQueue.primary?.traveler_full_name || '—' }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">Sex</span><span class="ps-dv">{{ selQueue.primary?.gender || '—' }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">Direction</span><span class="ps-dv">{{ selQueue.primary?.traveler_direction || '—' }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">Temperature</span><span class="ps-dv">{{ selQueue.primary?.temperature_value ? selQueue.primary.temperature_value + '°' + selQueue.primary.temperature_unit : '—' }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">Reason</span><span class="ps-dv">{{ selQueue.reason_text || '—' }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">Created</span><span class="ps-dv">{{ fmtDateTime(selQueue.created_at) }}</span></div>
            <div class="ps-dg-row"><span class="ps-dk">Notif ID</span><span class="ps-dv ps-dv--mono">{{ selQueue.id || 'Pending sync' }}</span></div>
          </div>
        </div>
      </IonContent>
    </IonModal>

    <!-- Date filter modal -->
    <IonModal :is-open="filterOpen" :breakpoints="[0,1]" :initial-breakpoint="1"
      @ionModalDidDismiss="filterOpen=false">
      <IonHeader :translucent="false">
        <IonToolbar style="--background:linear-gradient(180deg,#070E1B,#0E1A2E);--color:#EDF2FA;--border-width:0;">
          <IonButtons slot="start"><IonButton @click="filterOpen=false" style="--color:rgba(255,255,255,.8);" aria-label="Close"><IonIcon :icon="closeOutline"/></IonButton></IonButtons>
          <IonTitle style="font-family:system-ui,sans-serif;font-weight:700;color:#EDF2FA;">Filter Records</IonTitle>
        </IonToolbar>
      </IonHeader>
      <IonContent :scroll-y="true" style="--background:#F8FAFC;--color:#0B1A30;">
        <div style="padding:16px;display:flex;flex-direction:column;gap:8px;">
          <button v-for="p in DATE_PRESETS" :key="p.label"
            class="ps-date-opt" :class="filterLabel===p.label && 'ps-date-opt--on'"
            @click="applyDate(p.value, p.label)" type="button">{{ p.label }}</button>
          <div style="margin-top:8px;">
            <label style="font-size:11px;font-weight:600;color:#94A3B8;display:block;margin-bottom:6px;text-transform:uppercase;letter-spacing:.6px;">Custom date</label>
            <input type="date" v-model="customDate" class="ps-date-input"
              :max="todayISO" @change="applyDate(customDate, customDate)" aria-label="Pick date"/>
          </div>
        </div>
      </IonContent>
    </IonModal>

    <!-- ── PASSPORT / QR ENTRY MODAL ────────────────────────────────── -->
    <IonModal :is-open="passportOpen"
      class="pp-modal"
      @ionModalDidDismiss="closePassportModal">
      <IonHeader :translucent="false">
        <IonToolbar style="--background:linear-gradient(135deg,#0F3460,#0B2545);--color:#EDF2FA;--border-width:0;--min-height:60px;">
          <IonButtons slot="start">
            <IonButton @click="closePassportModal" style="--color:rgba(255,255,255,.92);font-size:18px;" aria-label="Close"><IonIcon :icon="closeOutline"/></IonButton>
          </IonButtons>
          <IonTitle style="font-family:system-ui,sans-serif;font-weight:800;color:#EDF2FA;font-size:17px;letter-spacing:-.1px;">Passport / ID Entry</IonTitle>
        </IonToolbar>
      </IonHeader>
      <IonContent :scroll-y="true" style="--background:#F8FAFC;--color:#0B2545;">
        <div class="pp-body">
          <!-- ── MENU ── -->
          <div v-if="passportMode === 'menu'">
            <div class="pp-lead">
              <div class="pp-lead-t">Scan a passport or national ID</div>
              <div class="pp-lead-d">On-device OCR with 6-rotation × 5-variant retry. Works fully offline. No data leaves the device.</div>
            </div>

            <!-- ── Premium how-to / do & don't / limitations card ── -->
            <details class="pp-guide" :open="!_hideGuide" @toggle="_onGuideToggle($event)">
              <summary class="pp-guide-sum">
                <span class="pp-guide-sum-icon" aria-hidden="true">
                  <svg viewBox="0 0 20 20" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="10" cy="10" r="8"/><path d="M10 13v-3"/><circle cx="10" cy="6.5" r=".7" fill="currentColor"/>
                  </svg>
                </span>
                <span class="pp-guide-sum-t">How to get a perfect scan</span>
                <span class="pp-guide-sum-chev" aria-hidden="true">
                  <svg viewBox="0 0 12 12" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <polyline points="3 5 6 8 9 5"/>
                  </svg>
                </span>
              </summary>

              <!-- DO -->
              <div class="pp-guide-block pp-guide-block--do">
                <div class="pp-guide-block-h">
                  <span class="pp-guide-tag pp-guide-tag--do">DO</span>
                  <span class="pp-guide-block-t">Best results</span>
                </div>
                <ul class="pp-guide-list">
                  <li>Open the passport flat to the photo page (data page). Lay it on a desk if you can.</li>
                  <li>Frame the entire bottom <strong>MRZ strip</strong> — the two lines with <code>&lt;&lt;&lt;</code> at the bottom — fully in view.</li>
                  <li>Bright, even light. Daylight or a single overhead lamp is best.</li>
                  <li>Hold the phone <strong>15–25 cm</strong> away. Tap the screen to focus before shooting.</li>
                  <li>Keep the phone parallel to the page (no tilt). Hold steady for one full second.</li>
                  <li>For national IDs (TD1 format), photograph the <strong>back of the card</strong> where the MRZ lives.</li>
                </ul>
              </div>

              <!-- DON'T -->
              <div class="pp-guide-block pp-guide-block--dont">
                <div class="pp-guide-block-h">
                  <span class="pp-guide-tag pp-guide-tag--dont">DON'T</span>
                  <span class="pp-guide-block-t">What breaks the scan</span>
                </div>
                <ul class="pp-guide-list">
                  <li>Don't use direct flash on a glossy passport — it creates glare on the holographic overlay.</li>
                  <li>Don't tilt the document. The MRZ characters (OCR-B font) only read cleanly head-on.</li>
                  <li>Don't crop the bottom of the passport out of frame. Even half a missing line will fail.</li>
                  <li>Don't photograph through plastic sleeves, laminate covers, or wet pages.</li>
                  <li>Don't move the phone during capture — motion blur defeats the OCR.</li>
                  <li>Don't scan damaged / heavily worn passports — paste the MRZ manually instead.</li>
                </ul>
              </div>

              <!-- HOW IT WORKS -->
              <div class="pp-guide-block pp-guide-block--how">
                <div class="pp-guide-block-h">
                  <span class="pp-guide-tag pp-guide-tag--how">HOW</span>
                  <span class="pp-guide-block-t">What the scanner does in the background</span>
                </div>
                <ol class="pp-guide-list pp-guide-list--ol">
                  <li>Takes one high-quality photo (2400 px wide, JPEG q90).</li>
                  <li>Runs Google ML Kit Latin OCR at <strong>6 rotations</strong> (0°, 90°, 180°, 270°, 45°, 315°).</li>
                  <li>If raw OCR fails, generates 4 enhanced image variants per photo:
                    <em>MRZ-only crop · contrast-boost · grayscale · adaptive binarize</em> — each retried at every rotation.</li>
                  <li>Validates every candidate against ICAO 9303 check digits — no silent corruption.</li>
                  <li>Up to 4 fresh photo retries on failure, with on-screen guidance between each.</li>
                </ol>
              </div>

              <!-- LIMITATIONS -->
              <div class="pp-guide-block pp-guide-block--limit">
                <div class="pp-guide-block-h">
                  <span class="pp-guide-tag pp-guide-tag--limit">LIMITS</span>
                  <span class="pp-guide-block-t">Honest limitations</span>
                </div>
                <ul class="pp-guide-list">
                  <li>OCR accuracy is <strong>~85–94 %</strong> on first photo with good lighting; the multi-variant sweep raises this further but no system reaches 100 %.</li>
                  <li>If OCR can't read the MRZ at all, paste the two MRZ lines or type the name. The form still saves.</li>
                  <li>This build does <strong>not</strong> read the NFC chip yet — only the printed MRZ (so no signed chip data).</li>
                  <li>Damaged, faded, or pre-2005 passports without an MRZ cannot be auto-scanned.</li>
                  <li>The scanner never sends data anywhere — all OCR runs on this device.</li>
                </ul>
              </div>

              <!-- IF IT FAILS -->
              <div class="pp-guide-block pp-guide-block--fail">
                <div class="pp-guide-block-h">
                  <span class="pp-guide-tag pp-guide-tag--fail">IF IT FAILS</span>
                  <span class="pp-guide-block-t">Fallback paths (every screening can still be saved)</span>
                </div>
                <ul class="pp-guide-list">
                  <li><strong>Pick from gallery</strong> — use a sharper photo someone else took.</li>
                  <li><strong>Paste MRZ</strong> — copy the two MRZ lines from any reader app, NFC tool, or another phone.</li>
                  <li><strong>Type the name</strong> — close this panel and type into the name field directly.</li>
                </ul>
              </div>
            </details>

            <div class="pp-lead pp-lead--cta"><div class="pp-lead-t">Choose a capture method</div></div>

            <button class="pp-opt pp-opt--primary" type="button" :disabled="scanBusy" @click="runPassportScan">
              <span class="pp-opt-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="4" y="3" width="16" height="18" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M7 17h10M7 19.5h6"/>
                </svg>
              </span>
              <span class="pp-opt-body">
                <span class="pp-opt-t">{{ scanBusy ? 'Opening camera…' : 'Passport' }}</span>
                <span class="pp-opt-d">Photograph the passport data page — we read the MRZ at the bottom</span>
              </span>
            </button>

            <button class="pp-opt pp-opt--primary" type="button" :disabled="scanBusy" @click="runPassportScan">
              <span class="pp-opt-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="12" r="2.2"/><path d="M13 10h6M13 13h6M13 16h4"/>
                </svg>
              </span>
              <span class="pp-opt-body">
                <span class="pp-opt-t">{{ scanBusy ? 'Opening camera…' : 'National ID' }}</span>
                <span class="pp-opt-d">Photograph the back of the ID card — we read the machine-readable zone</span>
              </span>
            </button>

            <button class="pp-opt" type="button" :disabled="scanBusy" @click="runPassportScanFromGallery">
              <span class="pp-opt-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="2"/><path d="M3 18l6-6 5 5 3-3 4 4"/>
                </svg>
              </span>
              <span class="pp-opt-body">
                <span class="pp-opt-t">Pick a photo from gallery</span>
                <span class="pp-opt-d">Use a photo you already have — we'll OCR every rotation and crop until the MRZ reads</span>
              </span>
            </button>

            <button class="pp-opt" type="button" @click="passportMode = 'paste'; passportError=''">
              <span class="pp-opt-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="6" y="4" width="12" height="16" rx="2"/><path d="M9 4h6v3H9z"/><path d="M9 12h6M9 16h4"/>
                </svg>
              </span>
              <span class="pp-opt-body">
                <span class="pp-opt-t">Paste MRZ text or QR payload</span>
                <span class="pp-opt-d">Use this when you copy the two MRZ lines from an NFC reader or another app</span>
              </span>
            </button>

            <button class="pp-opt" type="button" @click="closePassportModal">
              <span class="pp-opt-ico" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>
                </svg>
              </span>
              <span class="pp-opt-body">
                <span class="pp-opt-t">Type the name manually</span>
                <span class="pp-opt-d">Close this panel and type directly into the name field</span>
              </span>
            </button>

            <div v-if="scanProgress" class="pp-progress" role="status" aria-live="polite">
              <span class="pp-progress-spinner" aria-hidden="true"></span>
              <span>{{ scanProgress }}</span>
            </div>
            <div v-if="scanHint" class="pp-hint">{{ scanHint }}</div>
            <div v-if="passportError" class="pp-err">{{ passportError }}</div>
          </div>

          <!-- ── PASTE ── -->
          <div v-else-if="passportMode === 'paste'">
            <div class="pp-lead">
              <div class="pp-lead-t">Paste MRZ, QR payload, or full name</div>
              <div class="pp-lead-d">Examples accepted: the two 44-character passport MRZ lines, a QR payload with <code>name=...</code>, JSON with <code>"name"</code>, or a plain printable name.</div>
            </div>
            <textarea
              v-model="passportPaste"
              rows="6"
              class="pp-paste"
              placeholder="P<ZMBBANDA<<MULENGA<<<<<<<<<<<<<<<<<<<<<<<<&#10;ZN12345678ZMB9001011M3001014<<<<<<<<<<<<<<06"
              autocapitalize="characters"
              spellcheck="false"
            />
            <div v-if="passportError" class="pp-err">{{ passportError }}</div>
            <div class="pp-row">
              <button class="pp-btn pp-btn--ghost" type="button" @click="passportMode='menu'; passportError=''">← Back</button>
              <button class="pp-btn pp-btn--primary" type="button" @click="parsePastedPassport">Parse</button>
            </div>
          </div>

          <!-- ── RESULT PREVIEW ── -->
          <div v-else-if="passportMode === 'result' && passportResult">
            <div class="pp-lead">
              <div class="pp-lead-t">Parsed from {{ passportResult.format }}</div>
              <div class="pp-lead-d">Review before applying. Only fields with values will be filled; manual edits still win.</div>
            </div>
            <div class="pp-card">
              <div class="pp-kv"><span class="pp-k">Name</span><span class="pp-v pp-v--strong">{{ passportResult.name || '—' }}</span></div>
              <div v-if="passportResult.sex" class="pp-kv"><span class="pp-k">Sex</span><span class="pp-v">{{ passportResult.sex }}</span></div>
              <div v-if="passportResult.dob_iso" class="pp-kv"><span class="pp-k">DOB</span><span class="pp-v">{{ passportResult.dob_iso }}</span></div>
              <div v-if="passportResult.nationality_iso3" class="pp-kv"><span class="pp-k">Nationality</span><span class="pp-v">{{ passportResult.nationality_iso3 }}</span></div>
              <div v-if="passportResult.passport_no" class="pp-kv"><span class="pp-k">Passport No.</span><span class="pp-v">{{ passportResult.passport_no }}</span></div>
              <div v-if="passportResult.expiry_iso" class="pp-kv"><span class="pp-k">Expiry</span><span class="pp-v">{{ passportResult.expiry_iso }}</span></div>
            </div>
            <div class="pp-row">
              <button class="pp-btn pp-btn--ghost" type="button" @click="passportMode='menu'; passportError=''">← Back</button>
              <button class="pp-btn pp-btn--primary" type="button" @click="applyPassportResult">Apply to form</button>
            </div>
          </div>
        </div>
      </IonContent>
    </IonModal>

  </IonPage>
</template>


<script setup>
/**
 * PrimaryScreening.vue — Rapid Primary Triage · IHR 2005 Art. 23
 *
 * CAPTURE RULES:
 *   Direction (ENTRY/EXIT/TRANSIT)   → required always
 *   Sex (MALE/FEMALE)                → required always
 *   Temperature                      → optional; unit required if given
 *   Symptoms YES/NO                  → required always (IHR triage decision)
 *   Traveler Full Name               → required ONLY when symptomatic
 *
 * OFFLINE-FIRST:
 *   All writes → IDB first, network fire-and-forget
 *   Asymptomatic: dbPut (single store)
 *   Symptomatic:  dbAtomicWrite([primary, notification]) — never split
 *   Priority:     CRITICAL ≥38.5°C, HIGH ≥37.5°C, NORMAL otherwise
 */
import { ref, computed, nextTick, onMounted, onUnmounted, watch } from 'vue'
import {
  IonPage, IonHeader, IonToolbar, IonTitle, IonContent, IonButtons,
  IonBackButton, IonButton, IonIcon, IonModal, IonRefresher,
  IonRefresherContent, onIonViewDidEnter,
} from '@ionic/vue'
// Shared i18n composable — every view in the app shares the same reactive
// currentLang and t() function so language switches propagate instantly.
import { useI18n } from '@/i18n'
const { t } = useI18n()
import { closeOutline, scanOutline } from 'ionicons/icons'
import {
  dbPut, dbGet, dbGetAll, safeDbPut, dbGetByIndex, dbAtomicWrite,
  genUUID, isoNow, getDeviceId, getPlatform, createRecordBase,
  STORE, SYNC, APP,
} from '@/services/poeDB'
import { hapticError, hapticWarning, hapticCritical, hapticSuccess } from '@/services/haptics'
import * as barcode from '@/services/plugins/barcode'
import { parseTravelerDoc } from '@/services/passport'
import { coachmark } from '@/services/tour'
import { CAPABILITY_KEYS, SENTINEL_KEYS, isSentinelFeatureOn, subscribe as capSubscribe } from '@/services/capabilities'
import { applySentinelFields } from '@/services/sentinelFormWrite'
import { sentinelSuccess, sentinelError } from '@/services/sentinelToast'

// ── CONSTANTS ─────────────────────────────────────────────────────────────
const PAGE_SIZE = 50
const todayISO  = new Date().toISOString().slice(0, 10)

// ── Passport data utilities ────────────────────────────────────────────────

/**
 * Convert ISO 3166-1 alpha-3 → alpha-2 using window.COUNTRIES.
 * Returns '' when the lookup fails. Never throws.
 */
function iso3ToIso2(iso3) {
  try {
    if (!iso3 || typeof iso3 !== 'string') return ''
    const upper = iso3.toUpperCase().trim()
    const list  = Array.isArray(window.COUNTRIES?.[0])
      ? window.COUNTRIES[0]
      : (Array.isArray(window?.COUNTRIES) ? window.COUNTRIES : [])
    const match = list.find(c => (c.code3 || '').toUpperCase() === upper)
    return match?.code2 || ''
  } catch { return '' }
}

/**
 * Calculate age in completed years from an ISO date string (YYYY-MM-DD).
 * Returns null when the date is missing or invalid.
 */
function calcAgeFromDob(dobIso) {
  try {
    if (!dobIso || typeof dobIso !== 'string') return null
    const dob  = new Date(dobIso)
    if (isNaN(dob.getTime())) return null
    const now  = new Date()
    let age    = now.getFullYear() - dob.getFullYear()
    const m    = now.getMonth() - dob.getMonth()
    if (m < 0 || (m === 0 && now.getDate() < dob.getDate())) age--
    return age >= 0 ? age : null
  } catch { return null }
}

// Transit is only valid at airports (IATA port of entry).
// Land borders and lake ports do not use transit — removing it
// reduces cognitive load and prevents data-entry errors.
const ALL_DIRS = [
  { v:'ENTRY',   k:'entry',   label:'→ Entry' },
  { v:'EXIT',    k:'exit',    label:'← Exit'  },
  { v:'TRANSIT', k:'transit', label:'⇌ Transit'},
]
const DIRS = computed(() => {
  const poeType = (auth.value?.poe_type ?? '').toLowerCase()
  // Show Transit only when poe_type is explicitly airport,
  // OR when poe_type is unknown (backward compat with older auth payloads)
  const isAirport = !poeType || poeType.includes('airport')
  return isAirport ? ALL_DIRS : ALL_DIRS.filter(d => d.v !== 'TRANSIT')
})

// ── QUICK-SELECT SYMPTOMS ─────────────────────────────────────────────────
// Shown as chip buttons when symptomatic=1. Officer can tap to pre-select
// the most commonly observed WHO IHR symptoms at triage without waiting
// for the secondary screening step. Selections are stored with the primary
// record and displayed on the queue card.
// Broad symptom categories (Mandate 2026-05-06 — Priority 2). Primary
// screening captures CATEGORIES, not specific symptoms — clinical detail
// belongs in secondary. Each category maps to one or more specific
// secondary symptom codes via PRIMARY_TO_SECONDARY_SYMPTOM in
// SecondaryScreening.vue, and is auto-applied when a case opens.
const QUICK_SYMPTOMS = [
  { code:'fever',            label:'Fever' },
  { code:'respiratory',      label:'Respiratory' },
  { code:'gastrointestinal', label:'Gastrointestinal' },
  { code:'skin_rash',        label:'Skin / Rash' },
  { code:'bleeding',         label:'Bleeding' },
  { code:'weakness_malaise', label:'Weakness / Malaise' },
  { code:'general_weakness', label:'General Body Weakness' },
  { code:'pain',             label:'Pain' },
  { code:'other',            label:'Other' },
]
const quickSymptoms = ref([])

// ── IHR/WHO OBSERVABLE SYMPTOM CATEGORIES ────────────────────────────────
// Aligned to IHR 2005 Annex 2 + WHO Integrated Disease Surveillance.
// These are symptoms observable during rapid visual triage at a POE —
// no lab equipment, no examination table. Officers confirm YES/NO only.
// Coverage: VHF, Cholera, Plague, SARI, ILI, AWD, Meningitis, Mpox,
//           Yellow Fever, Rift Valley Fever, Marburg, Ebola, CCHF,
//           Lassa, Polio, Measles, Rubella, Diphtheria, Anthrax.
const WHO_CATS = [
  { name:'Respiratory (ILI/SARI)',  items:['Fever ≥38°C','Cough','Difficulty breathing','Sore throat','Runny nose'] },
  { name:'Gastrointestinal (AWD)',  items:['Vomiting','Watery diarrhoea','Bloody diarrhoea','Abdominal pain','Dehydration signs'] },
  { name:'Haemorrhagic (VHF)',      items:['Unexplained bleeding','Bloody stool','Bleeding gums','Blood in vomit','Bruising/petechiae'] },
  { name:'Neurological',            items:['Altered consciousness','Seizures','Neck stiffness','Paralysis/weakness','Severe headache'] },
  { name:'Skin / Mucosal',          items:['Rash','Jaundice (yellow eyes)','Skin lesions/vesicles','Swollen lymph nodes','Conjunctivitis (red eyes)'] },
  { name:'Systemic / General',      items:['Severe fatigue/prostration','Night sweats','Muscle/joint pain','Significant weight loss','Swelling (face/limbs)'] },
]

const DATE_PRESETS = [
  { label:'Today',     value: todayISO },
  { label:'Yesterday', value: new Date(Date.now()-86400000).toISOString().slice(0,10) },
  { label:'Last 7 days', value: new Date(Date.now()-7*86400000).toISOString().slice(0,10) },
]

// ── STATE ─────────────────────────────────────────────────────────────────
const auth      = ref(null)
const tab       = ref('capture')
const isOnline  = ref(navigator.onLine)
const nameRef   = ref(null)
const focusTemp = ref(false)
const focusName = ref(false)
const whoOpen   = ref(false)
const tempUnit  = ref('C')
const capturing = ref(false)
const lastResult = ref(null)
const syncing   = ref(false)
const syncError = ref('')

// ── Passport / code scan wiring ───────────────────────────────────────────
// Tapping the scan icon opens the "Passport Entry" modal, which offers three
// paths to get traveller identity into the form:
//   1. Camera scan (MLKit barcode/QR, on-device, offline)
//   2. Paste MRZ / QR text (for NFC readers, clipboard, copy from another app)
//   3. Manual entry (the existing name field is always usable)
//
// The parser (services/passport.js) understands TD1/TD2/TD3 MRZ plus
// JSON/URL/key=value QR payloads. It returns name + sex + dob when
// available, so we auto-fill gender too. Every path fails silently and
// never blocks capture — officers can always continue by typing the
// name directly.
const scanBusy    = ref(false)
const scanHint    = ref('')
const scanProgress = ref('')        // live status string while OCR runs
const passportOpen = ref(false)
const passportMode = ref('menu')    // 'menu' | 'paste' | 'result'
const passportError = ref('')
const passportPaste = ref('')
const passportResult = ref(null)    // parsed traveller struct preview

// Guide panel — open by default on first visit, then remembers the user's
// preference via localStorage so power users aren't pestered after onboarding.
const _GUIDE_KEY = 'pp_guide_collapsed_v1'
const _hideGuide = ref(false)
try { _hideGuide.value = localStorage.getItem(_GUIDE_KEY) === '1' } catch {}
function _onGuideToggle(ev) {
  try {
    const collapsed = !ev?.target?.open
    _hideGuide.value = collapsed
    localStorage.setItem(_GUIDE_KEY, collapsed ? '1' : '0')
  } catch {}
}

function openPassportModal() {
  passportPaste.value  = ''
  passportError.value  = ''
  passportResult.value = null
  passportMode.value   = 'menu'
  passportOpen.value   = true
  scanHint.value       = ''
}
function closePassportModal() { passportOpen.value = false }

async function runCameraScan() {
  if (scanBusy.value) return
  scanBusy.value      = true
  passportError.value = ''
  scanHint.value      = ''
  try {
    if (!(await barcode.isAvailable())) {
      passportError.value = 'Scanner not available on this device — paste the MRZ or enter the name manually.'
      return
    }
    // First-launch case: Google Code Scanner module needs to download from
    // Play Services. scanOnce streams progress events; we surface them as a
    // "Downloading scanner — N%" hint so the officer knows to wait instead
    // of seeing a blank camera view.
    const onInstallProgress = ({ state, progress }) => {
      const pct = Number.isFinite(progress) ? ` ${Math.round(progress)}%` : ''
      if (state === 2 || state === 'DOWNLOADING') scanHint.value = `Downloading scanner${pct} — please wait…`
      else if (state === 1 || state === 'PENDING') scanHint.value = 'Preparing scanner…'
      else if (state === 6 || state === 'INSTALLING') scanHint.value = 'Installing scanner…'
      else if (state === 7 || state === 'DOWNLOAD_PAUSED') scanHint.value = 'Download paused — check your network'
      else if (state === 4 || state === 'COMPLETED') scanHint.value = ''
    }
    const r = await barcode.scanOnce({ onInstallProgress })
    scanHint.value = ''
    if (!r || !r.value) {
      // Distinguish "module not installed" from "user cancelled" — both
      // surface as null today, but the message points to the most likely
      // recoverable cause. Pasting the MRZ always works.
      passportError.value = 'Nothing scanned. If your scanner just installed, try once more — otherwise paste the MRZ or type the name.'
      return
    }
    const doc = parseTravelerDoc(r.value)
    if (doc && doc.name) {
      passportResult.value = doc
      passportMode.value   = 'result'
    } else {
      // Not an MRZ / structured code — surface the raw payload so the
      // officer can decide whether it's useful.
      passportError.value = `Scanned, but no name could be parsed. Code: ${String(r.value).slice(0,80)}`
    }
  } finally {
    scanBusy.value = false
  }
}

// Offline-first passport scan: take a still photo of the data page, OCR
// the MRZ with the BUNDLED ML Kit text-recognition lib, parse via the
// existing passport.js parser. No network required at any step.
//
// Hardened so that every failure path resolves with a friendly hint —
// passportScan.js itself returns hint strings, we just surface them.
async function _runPassportScanCore({ source = 'camera' } = {}) {
  if (scanBusy.value) return
  scanBusy.value      = true
  passportError.value = ''
  scanHint.value      = source === 'gallery' ? 'Opening gallery…' : 'Opening camera…'
  scanProgress.value  = ''
  try {
    const { scanPassport }        = await import('@/services/plugins/passportScan')
    const { isRealPluginFailure, pluginAlert } = await import('@/services/pluginAlert')

    const res = await scanPassport({
      source,
      onProgress(step, detail) {
        if (step === 'permission-check' && detail?.phase === 'requesting') {
          scanProgress.value = source === 'gallery' ? 'Requesting gallery access…' : 'Requesting camera permission…'
        } else if (step === 'photo-start') {
          scanProgress.value = detail.attempt > 1
            ? `Attempt ${detail.attempt} — reposition and shoot again`
            : (source === 'gallery' ? 'Pick the passport photo…' : 'Photograph the data page — keep the MRZ in frame')
        } else if (step === 'ocr-start') {
          if (detail.rotation === 0)        scanProgress.value = 'Reading the document…'
          else                               scanProgress.value = `Retrying at ${detail.rotation}° rotation…`
        } else if (step === 'ocr-pass') {
          scanProgress.value = `Pass ${detail.passNum}/${detail.totalPasses} — ${detail.label || 'analysing'}…`
        } else if (step === 'retry-guidance') {
          scanProgress.value = detail.guidance || 'Adjust and try again.'
        } else if (step === 'photo-done') {
          scanProgress.value = 'Analysing image…'
        } else if (step === 'ocr-done') {
          // keep the running indicator so the user sees we're chewing through rotations
        }
      },
    })

    scanHint.value = ''
    scanProgress.value = ''

    if (!res || res.ok !== true) {
      if (res?.reason === 'photo-cancelled') return   // silent
      if (res?.reason === 'permission-permanent' && res?.settingsUrl) {
        passportError.value = 'Camera access is blocked. Open Settings → App → Permissions → Camera, then try again.'
        return
      }
      if (isRealPluginFailure(res?.reason)) {
        pluginAlert('Passport Scanner', res.reason, res?.hint)
      }
      passportError.value = res?.hint || 'Scan failed. Try a brighter photo, pick from gallery, or paste the MRZ.'
      return
    }

    passportResult.value = res.doc
    passportMode.value   = 'result'
  } catch (err) {
    console.debug('[passport-scan] outer-throw:', err?.message)
    passportError.value = 'Passport scanner failed. Pick from gallery, paste the MRZ, or type the name manually.'
    try {
      const { pluginAlert } = await import('@/services/pluginAlert')
      pluginAlert('Passport Scanner', 'orchestrator-error:' + (err?.message || 'unknown'), null)
    } catch {}
  } finally {
    scanBusy.value = false
    scanProgress.value = ''
    if (passportMode.value !== 'result') scanHint.value = ''
  }
}

async function runPassportScan() { return _runPassportScanCore({ source: 'camera' }) }
async function runPassportScanFromGallery() { return _runPassportScanCore({ source: 'gallery' }) }

// Boarding-pass scan removed (2026-05-05). National ID + Passport both
// flow through runPassportScan — the MRZ scanner already supports TD1/TD2
// national-ID card formats. Function intentionally left as a no-op stub
// so any cached compiled chunk that still references it does not crash.
async function runBoardingPassScan() {
  return runPassportScan()
}

function parsePastedPassport() {
  passportError.value = ''
  const txt = (passportPaste.value || '').trim()
  if (!txt) { passportError.value = 'Paste an MRZ line, QR payload, or a full name first.'; return }
  const doc = parseTravelerDoc(txt)
  if (!doc || !doc.name) {
    passportError.value = 'Could not find a name in that text. Paste either the two MRZ lines from a passport, a QR payload, or just the traveller name.'
    return
  }
  passportResult.value = doc
  passportMode.value   = 'result'
}

function applyPassportResult() {
  const doc = passportResult.value
  if (!doc) return

  // ── Always-fill: visible form fields ──────────────────────────────────
  if (doc.name) form.value.name = doc.name.slice(0, 150)

  // Gender: only fill if the officer hasn't already selected one. MALE/FEMALE
  // only — OTHER requires the officer's policy call.
  if ((doc.sex === 'MALE' || doc.sex === 'FEMALE') && form.value.gender == null) {
    form.value.gender = doc.sex
  }

  // ── Silent metadata capture ────────────────────────────────────────────
  // These fields are NOT shown in the primary form UI. They're stored in
  // the IDB record so secondary screening can pre-fill without re-scanning.

  // Nationality: convert ISO3 → ISO2 for the country dropdown
  if (doc.nationality_iso3) {
    const iso2 = iso3ToIso2(doc.nationality_iso3)
    if (iso2) form.value._passport_nationality = iso2
  }

  // Document number and type
  if (doc.passport_no) {
    form.value._passport_doc_number = doc.passport_no.slice(0, 60)
  }
  // 2026-05-07: format mapping covers MRZ + non-MRZ regional IDs.
  //   TD1 (3×30 MRZ on card back)              → NATIONAL_ID
  //   TD2 (2×36 MRZ — older travel docs)       → PASSPORT
  //   TD3 (2×44 MRZ — passports)               → PASSPORT
  //   INDANGAMUNTU / CNI_BURUNDI / NIN / NIDA /
  //   FAYDA_FIN / HUDUMA / HUDUMA_NAMBA /
  //   CARTE_DIDENTITE_DRC / SS_NIN / SD_NN     → NATIONAL_ID
  //   anything else (JSON/URL/text)            → PASSPORT (safer default)
  const NATIONAL_ID_FORMATS = new Set([
    'TD1', 'INDANGAMUNTU', 'CNI_BURUNDI', 'NIN', 'NIDA',
    'FAYDA_FIN', 'HUDUMA', 'HUDUMA_NAMBA',
    'CARTE_DIDENTITE_DRC', 'SS_NIN', 'SD_NN',
  ])
  form.value._passport_doc_type = NATIONAL_ID_FORMATS.has(doc.format) ? 'NATIONAL_ID' : 'PASSPORT'

  // Date of birth → calculate age
  if (doc.dob_iso) {
    form.value._passport_dob_iso = doc.dob_iso
    const age = calcAgeFromDob(doc.dob_iso)
    if (age !== null) form.value._passport_age_years = age
  }

  // ── Boarding-pass flow ─────────────────────────────────────────────────
  if (doc._bcbp && doc._firstLeg) {
    const leg    = doc._firstLeg
    const origin = doc._origin ? ` (${doc._origin})` : ''
    import('@/services/sentinelToast').then(({ sentinelInfo }) => {
      try { sentinelInfo(`From ${leg.from_iata}${origin} on ${leg.carrier}${leg.flight_number}`) } catch {}
    }).catch(() => {})
  }

  hapticSuccess()
  passportOpen.value = false
  scanHint.value = ''
}

function scanForName() { openPassportModal() }

// Stats (computed from IDB — dbCountIndex not available, use indexed query)
const todayCount   = ref(0)
const symptomCount = ref(0)
const syncedCount  = ref(0)
const pendingCount = ref(0)
const openReferrals = ref(0)

// Records tab
const records      = ref([])
const recLoading   = ref(false)
const recordsTotal = ref(0)
const page         = ref(0)
const fSym         = ref('ALL')
const fSync        = ref('ALL')
const fDir         = ref('ALL')
const filterDate   = ref(todayISO)
const filterLabel  = ref('Today')
const filterOpen   = ref(false)
const customDate   = ref('')

// Queue tab
const queue    = ref([])
const qLoading = ref(false)
const qFilter  = ref('OPEN')

// Modals
const showVoid   = ref(false)
const voiding    = ref(false)
const voidReason = ref('')
const voidTarget = ref(null)
const selRecord  = ref(null)
const selQueue   = ref(null)

// Form
const mkForm = () => ({
  direction:  null,
  gender:     null,
  temp:       '',
  symptoms:   null,
  name:       '',
  // Passport metadata — filled silently by scan, not shown in the primary
  // form UI. Stored in IDB so secondary screening can pre-fill without
  // requiring the officer to re-scan or re-type.
  _passport_nationality: '',   // ISO-2 (e.g. 'RW') from iso3 conversion
  _passport_doc_number:  '',   // document number (e.g. 'RW123456')
  _passport_doc_type:    '',   // 'PASSPORT' | 'NATIONAL_ID'
  _passport_dob_iso:     '',   // date of birth YYYY-MM-DD
  _passport_age_years:   null, // calculated integer age
})
const form   = ref(mkForm())
const errors = ref({})

// ── Sentinel productivity — feature registry state ────────────────────────
// One reactive bucket tracks which Sentinel features are on. Each feature's
// button uses `sentinelOn[key]` in its v-if so toggling Settings hides the
// button live (no reload). See docs/sentinel-plan/ARCHITECTURE.md §4.
const sentinelOn = ref({})
function refreshSentinel() {
  const next = {}
  for (const k of SENTINEL_KEYS) next[k] = isSentinelFeatureOn(k)
  sentinelOn.value = next
}
refreshSentinel()
const _sentinelCapHandler = () => refreshSentinel()
// Subscribe to master + frozen + every feature flag so toggles propagate.
const _sentinelUnsubs = [
  capSubscribe(CAPABILITY_KEYS.SENTINEL_MASTER, _sentinelCapHandler),
  capSubscribe(CAPABILITY_KEYS.SENTINEL_FROZEN, _sentinelCapHandler),
  ...SENTINEL_KEYS.map(k => capSubscribe(k, _sentinelCapHandler)),
]
// DOM-event fallback (capabilities.js dispatches `capability-changed` too)
window.addEventListener('capability-changed', _sentinelCapHandler)

const hasSentinelActions = computed(() =>
  SENTINEL_KEYS.some(k => sentinelOn.value[k])
)

/**
 * Feature agents call this from their scan/voice/BLE result handler.
 * Delegates to the shared helper so the overwrite-guard, event, and
 * haptic-on-success behaviour are identical across features.
 */
function applySentinel(partial) {
  const filled = applySentinelFields(form, partial)
  if (filled.length) {
    hapticSuccess()
    sentinelSuccess(`Filled ${filled.length} field${filled.length === 1 ? '' : 's'}`)
  }
  return filled
}

// 2026-05-07: Voice wizard removed app-wide. Stub kept for any legacy
// references; calling it is a no-op. The mic plugin is no longer imported.
function isSentinelMockMode() {
  try { return new URLSearchParams(location.search).get('sentinel-mock') === '1' }
  catch { return false }
}

// Boarding-pass sentinel removed (2026-05-05). National ID + Passport scan
// are exposed via the unified runPassportScan flow above.
const bcbpBusy = ref(false)
async function onScanBoardingPass() { /* deprecated — preserved as no-op */ }

// Sync
const activeSyncKeys = new Set()
let   syncTimer      = null

// ── COMPUTED ──────────────────────────────────────────────────────────────
const canScreen = computed(() => {
  const a = auth.value
  return !!(a?.id && a?.is_active && a?._permissions?.can_do_primary_screening && a?.poe_code)
})

// Temperature is always Celsius (Request 2 — °F removed).
const tempC = computed(() => {
  const v = parseFloat(form.value.temp)
  return isNaN(v) ? null : v
})

const tempLevel = computed(() => {
  const c = tempC.value
  if (c === null) return null
  if (c >= 38.5) return 'crit'
  if (c >= 37.5) return 'warn'
  return 'normal'
})

const priority = computed(() => {
  if (form.value.symptoms !== 1) return 'NORMAL'
  const c = tempC.value
  if (c !== null && c >= 38.5) return 'CRITICAL'
  if (c !== null && c >= 37.5) return 'HIGH'
  return 'NORMAL'
})

const priorityReason = computed(() => {
  if (priority.value === 'CRITICAL') return `≥38.5°C with symptoms detected`
  if (priority.value === 'HIGH')     return `≥37.5°C with symptoms detected`
  return 'Symptoms present — no elevated temperature'
})

const canCapture = computed(() => {
  if (!canScreen.value) return false
  if (!form.value.direction) return false
  if (!form.value.gender) return false
  if (form.value.symptoms === null) return false
  if (form.value.symptoms === 1 && form.value.name.trim().length < 2) return false
  if (errors.value.temp) return false
  // Clinical guard: block fever + clear contradiction
  const c = tempC.value
  if (c !== null && c >= 38.0 && form.value.symptoms === 0) return false
  if (captureLocked.value) return false
  return true
})

const captureHint = computed(() => {
  if (!canScreen.value) return 'No permission to screen at this POE'
  if (!form.value.direction) return 'Select traveler direction'
  if (!form.value.gender) return 'Select sex'
  if (form.value.symptoms === null) return 'Confirm symptoms decision'
  // Clinical guard hint
  const c = tempC.value
  if (c !== null && c >= 38.0 && form.value.symptoms === 0)
    return 'Fever detected — mark Symptomatic or clear temperature'
  if (form.value.symptoms === 1 && form.value.name.trim().length < 2) return 'Enter traveler name for referral traceability'
  if (errors.value.temp) return errors.value.temp
  if (captureLocked.value) return 'Record saved — please wait…'
  return ''
})

// ── LIFECYCLE ─────────────────────────────────────────────────────────────
onMounted(async () => {
  auth.value = getAuth()
  window.addEventListener('online',  onOnline)
  window.addEventListener('offline', onOffline)
  await loadStats()
  if (pendingCount.value > 0 && isOnline.value) void manualSync()
})
// Coachmark for the passport scan button — fires once per device.
function _maybeScanCoachmark() {
  try {
    coachmark('primary.scan-intro', {
      selector: '.ps-passport-btn',
      title: 'Scan to auto-fill',
      body: 'Tap "Scan Passport / ID" to read the MRZ from a passport or national ID and auto-fill traveller details.',
      icon: 'sparkles', ctaLabel: 'Got it',
    })
  } catch (_) {}
}
onIonViewDidEnter(async () => {
  auth.value = getAuth()
  await nextTick()
  await loadStats()
  setTimeout(_maybeScanCoachmark, 800)
})
onUnmounted(() => {
  clearTimeout(syncTimer)
  clearTimeout(captureDebounceTimer)
  if (resultDismissTimer) clearTimeout(resultDismissTimer)
  window.removeEventListener('online',  onOnline)
  window.removeEventListener('offline', onOffline)
  // Sentinel cleanup
  for (const off of _sentinelUnsubs) { try { off?.() } catch {} }
  window.removeEventListener('capability-changed', _sentinelCapHandler)
})

function getAuth() {
  try { return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') }
  catch { return null }
}
function onOnline()  { isOnline.value = true;  if (pendingCount.value > 0) void manualSync() }
function onOffline() { isOnline.value = false }

function setTab(t) {
  tab.value = t
  if (t === 'records') loadRecords()
  if (t === 'queue')   loadQueue()
}

// ── STATS ─────────────────────────────────────────────────────────────────
async function loadStats() {
  try {
    const a = getAuth()
    if (!a?.poe_code) return
    const all = await dbGetByIndex(STORE.PRIMARY_SCREENINGS, 'poe_code', a.poe_code)
    const today = all.filter(r => r.record_status !== 'VOIDED' && (r.captured_at||'').startsWith(todayISO))
    todayCount.value   = today.length
    symptomCount.value = today.filter(r => r.symptoms_present === 1).length
    syncedCount.value  = today.filter(r => r.sync_status === SYNC.SYNCED).length
    pendingCount.value = all.filter(r => r.sync_status === SYNC.UNSYNCED || r.sync_status === SYNC.FAILED).length
    const notifs = await dbGetByIndex(STORE.NOTIFICATIONS, 'poe_code', a.poe_code)
    openReferrals.value = notifs.filter(n => n.status === 'OPEN').length
  } catch (e) { console.warn('[PS] loadStats', e) }
}

// ── VALIDATION ────────────────────────────────────────────────────────────
function clearE(f) {
  const e = { ...errors.value }
  delete e[f]
  errors.value = e
}

// Smart temperature quick-pick — single tap captures a clinically normal
// reading. Values ≥ 37.5°C are clinically abnormal and the chip is rejected
// here as a safety net (the UI also hides chips for those values), forcing
// the screener to enter the abnormal value manually via the stepper.
function setTempQuick(v) {
  const n = Number(v)
  if (isNaN(n) || n < 30 || n > 43) return
  if (n >= 37.5) return  // abnormal — must be entered manually
  form.value.temp = n.toFixed(1)
  validateTempSoft()
  hapticSuccess?.()
}

// ── HUMAN BODY TEMPERATURE LIMITS ─────────────────────────────────────────
const TEMP_LIMITS = { C: { min: 30.0, max: 43.0 } }

// SOFT — runs on every keystroke. Never wipes. Only shows warnings.
// Lets the user type freely (e.g. "3" → "38" → "38.5").
function validateTempSoft() {
  const raw = form.value.temp
  const e = { ...errors.value }
  delete e.temp; delete e.tempWarn
  if (!raw && raw !== 0) { errors.value = e; return }

  const v = parseFloat(raw)
  if (isNaN(v)) { errors.value = e; return }

  // Only show advisory once a plausible complete value is entered (2+ digits)
  if (String(raw).replace(/[^0-9]/g, '').length >= 2) {
    const c = v
    if (c >= 38.5 && form.value.symptoms === 0) {
      e.tempWarn = 'Fever ≥38.5°C IS a clinical symptom. Mark "Symptomatic" or clear temperature.'
    } else if (c < 35 && c >= 30) {
      e.tempWarn = 'Hypothermia (<35°C) — unusual for a walking traveler. Verify reading.'
    }
  }
  errors.value = e
}

// HARD — runs on blur (user finished typing). Wipes impossible values.
function validateTemp() {
  const raw = form.value.temp
  const e = { ...errors.value }
  delete e.temp; delete e.tempWarn
  if (!raw && raw !== 0) { errors.value = e; return true }

  const v = parseFloat(raw)
  if (isNaN(v)) {
    form.value.temp = ''
    e.temp = 'Not a valid number — cleared.'
    errors.value = e; return false
  }

  const lim = TEMP_LIMITS[tempUnit.value]

  // ── HARD BLOCK: outside survivable clinical range → wipe ────────────────
  if (v < lim.min) {
    form.value.temp = ''
    e.temp = `${v}°${tempUnit.value} is below survivable body temp (min ${lim.min}°${tempUnit.value}). Cleared.`
    errors.value = e; return false
  }
  if (v > lim.max) {
    form.value.temp = ''
    e.temp = `${v}°${tempUnit.value} exceeds survivable limit (max ${lim.max}°${tempUnit.value}). Cleared.`
    errors.value = e; return false
  }

  // ── CLINICAL ADVISORY (non-blocking) ───────────────────────────────────
  const c = v
  if (c >= 38.5 && form.value.symptoms === 0) {
    e.tempWarn = 'Fever ≥38.5°C IS a clinical symptom. Mark "Symptomatic" or clear temperature.'
  } else if (c < 35) {
    e.tempWarn = 'Hypothermia (<35°C) — unusual for a walking traveler. Verify reading.'
  }

  errors.value = e; return true
}

// ── FEVER AUTO-REFERRAL GUARD ─────────────────────────────────────────────
// If temperature ≥ 38.0°C is entered, fever is clinically a symptom by definition.
// The officer MUST acknowledge this is a symptomatic case or explicitly clear the temp.
// This prevents data corruption where fever cases slip through as "clear".
const feverAutoGuard = computed(() => {
  const c = tempC.value
  if (c === null) return null
  if (c >= 38.0 && form.value.symptoms === 0) {
    return 'Fever ≥38°C detected. Fever is a clinical symptom — this traveler should be marked Symptomatic or temperature should be cleared.'
  }
  return null
})

// ── FEVER AUTO-DERIVE (Task 1, executive policy 2026-05-05) ───────────────
// Wire form.temp ↔ 'fever' chip:
//   temp ≥ 37.5 → push 'fever' into quickSymptoms (if not present)
//   temp <  37.5 → remove 'fever' from quickSymptoms
//   temp empty   → remove 'fever' (officer may still tap manually)
// The chip itself remains tappable — this watch only synchronises on
// temperature change, not on every render.
watch(
  () => tempC.value,
  (c) => {
    const has = quickSymptoms.value.includes('fever')
    if (c === null) {
      if (has) quickSymptoms.value.splice(quickSymptoms.value.indexOf('fever'), 1)
      return
    }
    if (c >= 37.5) {
      if (!has) quickSymptoms.value.push('fever')
    } else {
      if (has) quickSymptoms.value.splice(quickSymptoms.value.indexOf('fever'), 1)
    }
  },
  { immediate: false }
)

function validateForm() {
  const e = {}
  if (!form.value.direction) e.direction = 'Required'
  if (!form.value.gender) e.gender = 'Required'
  if (form.value.symptoms === null) e.symptoms = 'Required — IHR triage decision'
  if (form.value.symptoms === 1 && form.value.name.trim().length < 2)
    e.name = 'Full name required for referral traceability (WHO IHR Art. 23)'

  // ── CLINICAL GUARD: fever + "no symptoms" is a data integrity violation ──
  // A fever reading IS a symptom. Block saving until officer resolves contradiction.
  const c = tempC.value
  if (c !== null && c >= 38.0 && form.value.symptoms === 0) {
    e.symptoms = 'Fever ≥38°C detected — fever is a symptom. Mark "Symptomatic" or clear the temperature.'
  }

  // ── CLINICAL GUARD: hypothermia + "no symptoms" advisory ──────────────
  if (c !== null && c < 35.0 && c >= 30 && form.value.symptoms === 0) {
    e.tempWarn = 'Hypothermia (<35°C) in a walking traveler is unusual. Consider rechecking.'
  }

  if (!validateTemp() && errors.value.temp) e.temp = errors.value.temp
  errors.value = { ...errors.value, ...e }
  // Only block on hard errors (not tempWarn which is advisory)
  return !e.direction && !e.gender && !e.symptoms && !e.name && !e.temp
}

// switchUnit kept as a no-op stub — no UI calls it (°F button removed, R2).
// Body gutted to prevent accidental F/C mutation if called by dead code paths.
function switchUnit(_u) { /* Celsius-only — function intentionally inert */ }

// ── ANTI-CORRUPTION: Double-capture debounce ─────────────────────────────
// Prevents rapid double-taps from creating duplicate records.
// Once a capture starts, the button is locked for 2 seconds AFTER completion.
let captureDebounceTimer = null
const captureLocked = ref(false)

// ── CAPTURE ───────────────────────────────────────────────────────────────
async function captureScreening() {
  if (capturing.value || captureLocked.value || !canCapture.value) return
  if (!validateForm()) {
    hapticError()
    // Focus first error field
    if (errors.value.name) nameRef.value?.focus()
    return
  }
  // Temperature above IHR fever threshold (≥38°C) — warn via haptic
  const _tRaw = form.value.temp
  const _tVal = _tRaw !== '' ? parseFloat(_tRaw) : null
  if (_tVal !== null) {
    const _tC = _tVal
    if (_tC >= 38) hapticWarning()
  }

  const freshAuth = getAuth()
  if (!freshAuth?.id || !freshAuth?.is_active) {
    syncError.value = 'Session expired — log in again'
    return
  }
  if (!freshAuth?._permissions?.can_do_primary_screening || !freshAuth?.poe_code) {
    syncError.value = 'No permission to screen at this POE'
    return
  }

  capturing.value = true
  try {
    const now         = isoNow()
    const symptomatic = form.value.symptoms === 1
    const tempVal     = form.value.temp !== '' ? parseFloat(form.value.temp) : null
    const tempUnitVal = tempVal !== null ? tempUnit.value : null
    const tname       = form.value.name.trim() || null

    const screeningUuid = genUUID()
    const screening = createRecordBase(freshAuth, {
      traveler_direction:  form.value.direction,
      gender:              form.value.gender,
      traveler_full_name:  tname,
      temperature_value:   tempVal,
      temperature_unit:    tempUnitVal,
      symptoms_present:    symptomatic ? 1 : 0,
      quick_symptoms_json: quickSymptoms.value.length ? JSON.stringify(quickSymptoms.value) : null,
      captured_at:         now,
      captured_timezone:   Intl.DateTimeFormat().resolvedOptions().timeZone,
      referral_created:    0,
      record_status:       'COMPLETED',
      void_reason:         null,
      // Passport metadata — stored IDB-only (not in primary_screenings server schema).
      // Secondary screening reads these when opening the case so the officer
      // never has to re-enter data already captured at the primary lane.
      traveler_nationality_country_code: form.value._passport_nationality || null,
      travel_document_type:              form.value._passport_doc_type    || null,
      travel_document_number:            form.value._passport_doc_number  || null,
      traveler_age_years:                form.value._passport_age_years   ?? null,
      traveler_dob:                      form.value._passport_dob_iso     || null,
    })
    screening.client_uuid = screeningUuid

    if (!symptomatic) {
      await dbPut(STORE.PRIMARY_SCREENINGS, screening)
      incrementDay()
      lastResult.value = { ...screening }
      resetFormKeepUnit()
      // Audit SCR-1/SCR-2: tactile confirmation. Today the screener got
      // zero haptic on a clean capture and had to read the on-screen panel
      // to know the save committed. Single tap = single buzz.
      hapticSuccess()
      // Auto-dismiss the result panel so the next capture begins clean
      // without an extra "Next Traveler" tap. 5s gives enough time for the
      // OCD screener to glance the count and chips, but not so long it
      // blocks them. resetForm() (rather than just lastResult=null) is
      // intentional — keeps temp unit, clears any stale errors.
      armResultAutoDismiss()
      await loadStats()
      void attemptSync(screeningUuid)
      // Belt-and-braces: even if attemptSync above hit a transient failure,
      // the unified syncEngine will pick it up. Kicking it here forces an
      // immediate drain instead of waiting for the periodic poll.
      try { window.__SYNC_NOW__ && window.__SYNC_NOW__('after-primary-asymptomatic') } catch {}
      return
    }

    // SYMPTOMATIC — atomic write
    const pri   = priority.value
    const tsum  = tempVal !== null ? `${tempVal.toFixed(1)}°${tempUnitVal}` : 'Not measured'
    const rtext = [
      `Symptoms present.`,
      `Direction: ${form.value.direction}.`,
      `Sex: ${form.value.gender}.`,
      `Temp: ${tsum}.`,
      `Priority: ${pri}.`,
      `Traveler: ${tname || '—'}.`,
      `POE: ${freshAuth.poe_code}.`,
      `Officer: ${freshAuth.full_name || freshAuth.username || 'Officer'}.`,
    ].join(' ')

    const notifUuid = genUUID()
    const notif = {
      client_uuid:            notifUuid,
      reference_data_version: APP.REFERENCE_DATA_VER,
      country_code:           freshAuth.country_code  || null,
      province_code:          freshAuth.province_code || null,
      pheoc_code:             freshAuth.pheoc_code    || null,
      district_code:          freshAuth.district_code || null,
      poe_code:               freshAuth.poe_code      || null,
      primary_screening_id:   screeningUuid,
      primary_uuid:           screeningUuid,
      created_by_user_id:     freshAuth.id,
      notification_type:      'SECONDARY_REFERRAL',
      status:                 'OPEN',
      priority:               pri,
      reason_code:            'PRIMARY_SYMPTOMS_DETECTED',
      reason_text:            rtext,
      // SECONDARY_REFERRAL is for the secondary lane — server overrides this
      // value to POE_SECONDARY on insert. Stamp it locally too so any UI rule
      // that reads from IDB before the next sync agrees with the server.
      assigned_role_key:      'POE_SECONDARY',
      assigned_user_id:       null,
      opened_at:              null,
      closed_at:              null,
      device_id:              getDeviceId(),
      app_version:            APP.VERSION,
      platform:               getPlatform(),
      record_version:         1,
      sync_status:            SYNC.UNSYNCED,
      synced_at:              null,
      sync_attempt_count:     0,
      last_sync_error:        null,
      created_at:             now,
      updated_at:             now,
      // ── Enrichment fields — travel + clinical data for NotificationsCenter ──
      // Without these, the referral queue shows "Anonymous" and has no temp context.
      gender:                 form.value.gender,
      traveler_full_name:     tname,
      traveler_direction:     form.value.direction,
      temperature_value:      tempVal,
      temperature_unit:       tempUnitVal,
      captured_at:            now,
      screener_name:          freshAuth.full_name || freshAuth.username || null,
    }

    const screeningWithRef = { ...screening, referral_created: 1 }

    // dbAtomicWrite — both or neither
    await dbAtomicWrite([
      { store: STORE.PRIMARY_SCREENINGS, record: screeningWithRef },
      { store: STORE.NOTIFICATIONS,      record: notif },
    ])

    incrementDay()
    lastResult.value = { ...screeningWithRef, notification: { ...notif } }
    resetFormKeepUnit()
    // Audit SCR-2: symptomatic capture creates a SECONDARY_REFERRAL — the
    // screener needs an unmistakable signal so they can call security and
    // move on without re-checking the panel. Critical haptic + a danger
    // toast naming the priority and pointing at the queue.
    hapticCritical()
    sentinelError(`Referral filed (${pri}) — open the queue.`).catch(() => {})
    armResultAutoDismiss()
    await loadStats()
    void attemptSyncPair(screeningUuid, notifUuid)
    // Same as the asymptomatic branch: let the unified engine drain on top
    // of the inline pair-sync so any transient failure is immediately retried.
    try { window.__SYNC_NOW__ && window.__SYNC_NOW__('after-primary-symptomatic') } catch {}

  } catch (err) {
    console.error('[PS] captureScreening', err)
    syncError.value = `Save failed: ${err?.message || 'Unknown'}. NOT saved — retry.`
  } finally {
    capturing.value = false
    // Anti-corruption: lock button for 1.5s after capture to prevent double-tap
    captureLocked.value = true
    clearTimeout(captureDebounceTimer)
    captureDebounceTimer = setTimeout(() => { captureLocked.value = false }, 1500)
  }
}

function resetFormKeepUnit() {
  form.value   = mkForm()
  errors.value = {}
  focusTemp.value = focusName.value = whoOpen.value = false
  quickSymptoms.value = []
}
function resetForm() {
  resetFormKeepUnit()
  lastResult.value = null
  if (resultDismissTimer) { clearTimeout(resultDismissTimer); resultDismissTimer = null }
}

// Audit SCR-1: auto-dismiss the result panel so the next traveler can be
// captured without an extra "Next Traveler" tap. 5 s is enough for the
// screener to glance the count + chips. Cleared on manual reset/void to
// avoid double-dismiss races.
let resultDismissTimer = null
function armResultAutoDismiss() {
  if (resultDismissTimer) clearTimeout(resultDismissTimer)
  resultDismissTimer = setTimeout(() => {
    lastResult.value = null
    resultDismissTimer = null
  }, 5000)
}

function incrementDay() {
  const d = todayISO
  if (localStorage.getItem(APP.DAY_COUNT_DAY_KEY) !== d) {
    localStorage.setItem(APP.DAY_COUNT_DAY_KEY, d)
    localStorage.setItem(APP.DAY_COUNT_CNT_KEY, '0')
  }
  const c = parseInt(localStorage.getItem(APP.DAY_COUNT_CNT_KEY)||'0') + 1
  localStorage.setItem(APP.DAY_COUNT_CNT_KEY, String(c))
}

// ── SYNC ENGINE ───────────────────────────────────────────────────────────
async function attemptSync(uuid) {
  if (activeSyncKeys.has(uuid)) return
  activeSyncKeys.add(uuid)
  try {
    await _syncOne(STORE.PRIMARY_SCREENINGS, uuid, `${window.SERVER_URL}/primary-screenings`)
  } finally {
    activeSyncKeys.delete(uuid)
    await loadStats()
  }
}

async function attemptSyncPair(sUuid, nUuid) {
  if (activeSyncKeys.has(sUuid)) return
  activeSyncKeys.add(sUuid)
  activeSyncKeys.add(nUuid)
  try {
    const res = await _syncOne(STORE.PRIMARY_SCREENINGS, sUuid, `${window.SERVER_URL}/primary-screenings`)
    if (res?.success && res?.data?.id) {
      const stored = await dbGet(STORE.PRIMARY_SCREENINGS, sUuid)
      if (stored) {
        await safeDbPut(STORE.PRIMARY_SCREENINGS, {
          ...stored,
          id:          res.data.id,  // LAW 3
          sync_status: SYNC.SYNCED,
          synced_at:   isoNow(),
          record_version: (stored.record_version||1) + 1,
          updated_at:  isoNow(),
        })
      }
      if (lastResult.value?.client_uuid === sUuid) {
        lastResult.value = { ...lastResult.value, id: res.data.id, sync_status: SYNC.SYNCED }
      }
      if (res.data.notification?.id) {
        const ns = await dbGet(STORE.NOTIFICATIONS, nUuid)
        if (ns) {
          await safeDbPut(STORE.NOTIFICATIONS, {
            ...ns,
            id:          res.data.notification.id,
            sync_status: SYNC.SYNCED,
            synced_at:   isoNow(),
            record_version: (ns.record_version||1) + 1,
            updated_at:  isoNow(),
          })
        }
      }
    }
  } finally {
    activeSyncKeys.delete(sUuid)
    activeSyncKeys.delete(nUuid)
    await loadStats()
  }
}

async function _syncOne(store, uuid, url) {
  const record = await dbGet(store, uuid)
  if (!record || record.sync_status === SYNC.SYNCED) return null

  // LAW 5: increment BEFORE fetch
  const working = {
    ...record,
    sync_attempt_count: (record.sync_attempt_count||0) + 1,
    record_version:     (record.record_version||1) + 1,
    updated_at:          isoNow(),
  }
  await safeDbPut(store, working)

  const ctrl = new AbortController()
  const tid  = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS)
  let res, body
  try {
    res  = await fetch(url, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body:    JSON.stringify(buildPayload(working)),
      signal:  ctrl.signal,
    })
    body = await res.json().catch(() => ({}))
  } catch (e) {
    await safeDbPut(store, {
      ...working,
      sync_status:     SYNC.UNSYNCED,
      last_sync_error: e?.name === 'AbortError' ? 'Request timed out' : (e?.message||'Network error'),
      record_version:  (working.record_version||1) + 1,
      updated_at:      isoNow(),
    })
    scheduleRetry()
    return null
  } finally { clearTimeout(tid) }

  if (res.ok && body?.success) {
    await safeDbPut(store, {
      ...working,
      id:              body.data?.id ?? null,
      sync_status:     SYNC.SYNCED,
      synced_at:       isoNow(),
      last_sync_error: null,
      record_version:  (working.record_version||1) + 1,
      updated_at:      isoNow(),
    })
    return body
  }

  const retryable = res.status >= 500 || res.status === 429
  await safeDbPut(store, {
    ...working,
    sync_status:     retryable ? SYNC.UNSYNCED : SYNC.FAILED,
    last_sync_error: body?.message || `HTTP ${res.status}`,
    record_version:  (working.record_version||1) + 1,
    updated_at:      isoNow(),
  })
  if (retryable) scheduleRetry()
  else syncError.value = `Server rejected record: ${body?.message || res.status}`
  return null
}

// Kept in lockstep with services/syncEngine.js → buildPrimaryPayload(). When
// these two diverge, the foreground retry path (this function) and the
// background sync path produce slightly-different bodies for the same record
// — which is exactly how a record can pass one server validation and fail
// the other depending on which path it took. Defaults below match the
// syncEngine version line-for-line.
function buildPayload(r) {
  return {
    client_uuid:            r.client_uuid,
    reference_data_version: r.reference_data_version || APP.REFERENCE_DATA_VER,
    // Fall back to captured_by_user_id in case a legacy IDB record has it
    // under the canonical name rather than the local created_by_user_id helper.
    captured_by_user_id:    r.created_by_user_id || r.captured_by_user_id,
    traveler_direction:     r.traveler_direction || null,
    gender:                 r.gender,
    traveler_full_name:     r.traveler_full_name || null,
    // ?? not || — a literal 0.0 reading must not collapse to null.
    temperature_value:      r.temperature_value  ?? null,
    temperature_unit:       r.temperature_unit   ?? null,
    symptoms_present:       r.symptoms_present   ?? 0,
    captured_at:            r.captured_at,
    captured_timezone:      r.captured_timezone  || null,
    device_id:              r.device_id || 'unknown',
    app_version:            r.app_version || APP.VERSION,
    platform:               r.platform    || 'ANDROID',
    record_version:         r.record_version || 1,
    country_code:           r.country_code,
    province_code:          r.province_code,
    pheoc_code:             r.pheoc_code,
    district_code:          r.district_code,
    poe_code:               r.poe_code,
  }
}

function scheduleRetry() {
  clearTimeout(syncTimer)
  syncTimer = setTimeout(async () => {
    if (isOnline.value && pendingCount.value > 0) await manualSync()
    else if (pendingCount.value > 0) scheduleRetry()
  }, APP.SYNC_RETRY_MS)
}

async function manualSync() {
  if (syncing.value) return
  syncing.value = true; syncError.value = ''
  try {
    const a = getAuth()
    if (!a?.poe_code) return
    const unsynced = await dbGetByIndex(STORE.PRIMARY_SCREENINGS, 'sync_status', SYNC.UNSYNCED)
    for (const rec of unsynced.filter(r => r.poe_code === a.poe_code)) {
      if (activeSyncKeys.has(rec.client_uuid)) continue
      activeSyncKeys.add(rec.client_uuid)
      try {
        const result = await _syncOne(STORE.PRIMARY_SCREENINGS, rec.client_uuid, `${window.SERVER_URL}/primary-screenings`)
        if (result?.success && result?.data?.id && rec.referral_created === 1) {
          const notifs = await dbGetByIndex(STORE.NOTIFICATIONS, 'primary_screening_id', rec.client_uuid)
          for (const n of notifs) {
            if (n.sync_status === SYNC.UNSYNCED && result.data.notification?.id) {
              await safeDbPut(STORE.NOTIFICATIONS, {
                ...n, id: result.data.notification.id,
                sync_status: SYNC.SYNCED, synced_at: isoNow(),
                record_version: (n.record_version||1) + 1, updated_at: isoNow(),
              })
            }
          }
        }
      } finally { activeSyncKeys.delete(rec.client_uuid) }
    }
    await loadStats()
  } catch (e) {
    syncError.value = `Sync error: ${e?.message||'Unknown'}`
    console.error('[PS] manualSync', e)
  } finally { syncing.value = false }
}

// ── RECORDS ───────────────────────────────────────────────────────────────
async function loadRecords(resetPage = true) {
  recLoading.value = true
  if (resetPage) page.value = 0
  try {
    const a = getAuth()
    if (!a?.poe_code) return
    let all = await dbGetByIndex(STORE.PRIMARY_SCREENINGS, 'poe_code', a.poe_code)
    all = all.filter(r => (r.captured_at||'').startsWith(filterDate.value))
    if (fSym.value === 'YES') all = all.filter(r => r.symptoms_present === 1)
    if (fSym.value === 'NO')  all = all.filter(r => r.symptoms_present === 0)
    if (fSync.value !== 'ALL') all = all.filter(r => r.sync_status === fSync.value)
    if (fDir.value !== 'ALL') all = all.filter(r => r.traveler_direction === fDir.value)
    all.sort((a,b) => (b.captured_at||'').localeCompare(a.captured_at||''))
    recordsTotal.value = all.length
    const s = page.value * PAGE_SIZE
    records.value = all.slice(s, s + PAGE_SIZE).map(r => ({ ...r, id: r.id ?? r.server_id ?? null }))
  } catch (e) { console.error('[PS] loadRecords', e) }
  finally { recLoading.value = false }
}

function cycleDir() {
  const opts = ['ALL','ENTRY','EXIT','TRANSIT']
  fDir.value = opts[(opts.indexOf(fDir.value)+1) % opts.length]
  loadRecords()
}
function applyDate(v, l) { filterDate.value = v; filterLabel.value = l; filterOpen.value = false; loadRecords(true) }

// ── QUEUE ─────────────────────────────────────────────────────────────────
async function loadQueue() {
  qLoading.value = true
  try {
    const a = getAuth()
    if (!a?.poe_code) return
    const all = await dbGetByIndex(STORE.NOTIFICATIONS, 'poe_code', a.poe_code)
    const filtered = qFilter.value === 'OPEN' ? all.filter(n => n.status === 'OPEN') : all
    const enriched = []
    for (const n of filtered) {
      let primary = null
      try {
        primary = await dbGet(STORE.PRIMARY_SCREENINGS, n.primary_screening_id)
        if (!primary) {
          const ps = await dbGetByIndex(STORE.PRIMARY_SCREENINGS, 'poe_code', a.poe_code)
          primary = ps.find(r => r.id === n.primary_screening_id || r.client_uuid === n.primary_screening_id) || null
        }
      } catch {}
      enriched.push({ ...n, id: n.id ?? n.server_id ?? null, primary })
    }
    enriched.sort((a,b) => {
      const o = { CRITICAL:0, HIGH:1, NORMAL:2 }
      return (o[a.priority]??3) - (o[b.priority]??3) || (b.created_at||'').localeCompare(a.created_at||'')
    })
    queue.value = enriched
  } catch (e) { console.error('[PS] loadQueue', e) }
  finally { qLoading.value = false }
}

// ── VOID ──────────────────────────────────────────────────────────────────
function promptVoid() { voidTarget.value = lastResult.value; voidReason.value = ''; showVoid.value = true }
function promptVoidRecord(rec) { voidTarget.value = rec; voidReason.value = ''; showVoid.value = true }

async function confirmVoid() {
  if (voidReason.value.trim().length < 10 || voiding.value || !voidTarget.value) return
  voiding.value = true
  try {
    const rec    = voidTarget.value
    const stored = await dbGet(STORE.PRIMARY_SCREENINGS, rec.client_uuid)
    if (!stored) return
    await safeDbPut(STORE.PRIMARY_SCREENINGS, {
      ...stored, record_status: 'VOIDED', void_reason: voidReason.value.trim(),
      sync_status: SYNC.UNSYNCED, record_version: (stored.record_version||1)+1, updated_at: isoNow(),
    })
    if (stored.referral_created === 1) {
      const ns = await dbGetByIndex(STORE.NOTIFICATIONS, 'primary_screening_id', rec.client_uuid)
      for (const n of ns) {
        if (n.status !== 'CLOSED') {
          await safeDbPut(STORE.NOTIFICATIONS, {
            ...n, status: 'CLOSED', closed_at: isoNow(), sync_status: SYNC.UNSYNCED,
            record_version: (n.record_version||1)+1, updated_at: isoNow(),
          })
        }
      }
    }
    showVoid.value = false; voidTarget.value = null; voidReason.value = ''
    if (lastResult.value?.client_uuid === rec.client_uuid)
      lastResult.value = { ...lastResult.value, record_status: 'VOIDED' }
    await loadStats()
    void manualSync()
  } catch (e) { console.error('[PS] confirmVoid', e) }
  finally { voiding.value = false }
}

// ── HELPERS ───────────────────────────────────────────────────────────────
function syncCls(s) { return s === SYNC.SYNCED ? 'synced' : s === SYNC.FAILED ? 'failed' : 'pending' }

function fmtTime(dt) {
  if (!dt) return '—'
  try { return new Date(dt).toLocaleTimeString('en-UG', { hour:'2-digit', minute:'2-digit' }) }
  catch { return dt?.slice(11,16) || '—' }
}
function fmtDateTime(dt) {
  if (!dt) return '—'
  try { return new Date(dt).toLocaleString('en-UG', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' }) }
  catch { return dt }
}
</script>


<style scoped>
/*
  PRIMARY SCREENING — RAPID DATA ENTRY
  Font:  -apple-system, BlinkMacSystemFont, 'Segoe UI', Ubuntu, sans-serif
  Theme: Dual-tone (dark header / light content)
  Goal:  Zero scroll on capture, pixel-perfect, no clutter
*/

/* ── Keyframes ──────────────────────────────────────────────── */
@keyframes spin     { to { transform: rotate(360deg) } }
@keyframes revealIn { from { opacity:0; transform:translateY(-8px) } to { opacity:1; transform:translateY(0) } }
@keyframes resultIn { from { opacity:0; transform:translateY(10px) scale(.97) } to { opacity:1; transform:translateY(0) scale(1) } }
@keyframes slideUp  { from { opacity:0; transform:translateY(12px) } to { opacity:1; transform:translateY(0) } }
@keyframes dotPulse { 0%,100%{ box-shadow:0 0 6px rgba(224,32,80,.3) } 50%{ box-shadow:0 0 14px rgba(224,32,80,.6) } }
@keyframes netGlow  { 0%,100%{ box-shadow:0 0 4px rgba(0,230,118,.4) } 50%{ box-shadow:0 0 10px rgba(0,230,118,.8) } }
@keyframes dataStream { 0%{transform:translateX(-100%)} 100%{transform:translateX(350%)} }
@media (prefers-reduced-motion:reduce){ *,*::before,*::after{ animation-duration:.01ms!important; transition-duration:.01ms!important } }

/* ── Font stack — system native ─────────────────────────────── */
:host, * {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Ubuntu, 'Helvetica Neue', Arial, sans-serif;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  text-rendering: optimizeLegibility;
  box-sizing: border-box;
}

/* ═══════════════════════════════════════════════════════════════
   DARK ZONE — Header (--hdr-1 / --hdr-2 / --hdr-3)
═══════════════════════════════════════════════════════════════ */
.ps-hdr    { --background: #070E1B; --border-width: 0; }
.ps-toolbar {
  --background: linear-gradient(180deg, #070E1B 0%, #0E1A2E 100%);
  --color: #EDF2FA; --border-width: 0; --min-height: 48px;
}
.ps-toolbar-title {
  padding: 0;
}
.ps-title-line { display:flex; align-items:center; gap:6px; }
.ps-title-poe  { font-size:13px; font-weight:500; color:#00B4FF; letter-spacing:.3px; text-shadow:0 0 20px rgba(0,180,255,.25); }
.ps-title-sep  { color:rgba(255,255,255,.2); font-size:12px; }
.ps-title-label { font-size:12px; font-weight:500; color:#7E92AB; }

.ps-net { width:7px; height:7px; border-radius:50%; margin:0 2px; flex-shrink:0; }
.ps-net--on  { background:#00E676; animation:netGlow 2.5s ease-in-out infinite; box-shadow:0 0 8px rgba(0,230,118,.4); }
.ps-net--off { background:#FF3D71; box-shadow:0 0 6px rgba(255,61,113,.3); }

.ps-hbtn {
  width:44px; height:44px; border-radius:10px; position:relative;
  background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.08);
  display:flex; align-items:center; justify-content:center; cursor:pointer;
  transition:all .15s cubic-bezier(.16,1,.3,1);
  -webkit-tap-highlight-color:transparent;
}
.ps-hbtn:active { transform:scale(.93); }
.ps-hbtn svg { width:16px; height:16px; stroke:rgba(255,255,255,.65); }
.ps-hbtn--alert { border-color:rgba(224,32,80,.3); background:rgba(224,32,80,.1); }
.ps-hbtn--alert svg { stroke:#FF3D71; }
.ps-hbadge {
  position:absolute; top:-4px; right:-4px;
  background:linear-gradient(135deg,#B01840,#E02050); color:#fff; font-size:8px; font-weight:800;
  min-width:14px; height:14px; border-radius:7px;
  display:flex; align-items:center; justify-content:center;
  padding:0 3px; border:1.5px solid #070E1B;
  box-shadow:0 0 8px rgba(224,32,80,.35);
}
.ps-hbadge--amber { background:linear-gradient(135deg,#CC8800,#E6A000); }

/* Below toolbar — stats + tabs */
.ps-below-toolbar { background:linear-gradient(180deg,#0E1A2E 0%,#142640 100%); }

/* Stats strip */
.ps-stats   { display:flex; align-items:center; padding:8px 16px; border-bottom:1px solid rgba(255,255,255,.06); }
.ps-s       { display:flex; flex-direction:column; align-items:center; flex:1; }
.ps-s-n     { font-size:18px; font-weight:800; color:#EDF2FA; line-height:1; letter-spacing:-1px; }
.ps-s-l     { font-size:7px; font-weight:700; color:#7E92AB; text-transform:uppercase; letter-spacing:1.2px; margin-top:2px; }
.ps-s-n--green { color:#00E676; text-shadow:0 0 20px rgba(0,230,118,.25); }
.ps-s-n--amber { color:#FFB300; text-shadow:0 0 20px rgba(255,179,0,.25); }
.ps-s-n--red   { color:#FF3D71; text-shadow:0 0 20px rgba(255,61,113,.25); }
.ps-sdiv { width:1px; height:22px; background:rgba(255,255,255,.06); margin:0 2px; }

/* Tabs */
.ps-tabs { display:flex; }
.ps-tab  {
  flex:1; padding:10px 0; font-size:11px; font-weight:600; color:#7E92AB;
  border:none; background:transparent; cursor:pointer; position:relative;
  letter-spacing:.3px; transition:color .2s;
  -webkit-tap-highlight-color:transparent;
}
.ps-tab--on { color:#EDF2FA; }
.ps-tab--on::after {
  content:''; position:absolute; bottom:0; left:20%; right:20%;
  height:2px; background:#00B4FF; border-radius:1px;
  box-shadow:0 0 8px rgba(0,180,255,.4);
}
.ps-tab-dot {
  display:inline-block; width:5px; height:5px; border-radius:50%;
  background:#FF3D71; margin-left:4px; vertical-align:middle;
  animation:dotPulse 1.5s ease-in-out infinite;
}

/* ═══════════════════════════════════════════════════════════════
   LIGHT ZONE — Content (--page-1/2/3)
═══════════════════════════════════════════════════════════════ */
.ps-content {
  --background: linear-gradient(180deg, #EAF0FA 0%, #F2F5FB 40%, #E4EBF7 100%);
  --color: #0B1A30;
  overflow-x: hidden;
}
.ps-spin    { animation: spin .85s linear infinite; }

/* Guard */
.ps-guard {
  display:flex; align-items:flex-start; gap:12px; margin:16px;
  padding:14px; background:#FEF2F2; border:1px solid rgba(224,32,80,.2);
  border-radius:10px;
}
.ps-guard-ic    { font-size:20px; flex-shrink:0; }
.ps-guard-title { font-size:13px; font-weight:700; color:#B01840; margin-bottom:2px; }
.ps-guard-sub   { font-size:11px; color:#64748B; line-height:1.4; }

/* ═══════════════════════════════════════════════════════════════
   CAPTURE — single-viewport layout
═══════════════════════════════════════════════════════════════ */
.ps-capture {
  display:flex;
  flex-direction:column;
  gap:0;
  padding:0;
  /* `min-height:100%` keeps the sticky footer trick (margin-top:auto on
     .ps-capture-footer) working when the form fits in the viewport,
     and lets the column grow naturally when the symptomatic branch
     reveals more fields. */
  min-height:100%;
  max-width:100%;
  /* IMPORTANT: do NOT set any `overflow-*:hidden|auto|scroll` here.
     Setting one axis to `hidden` makes browsers implicitly treat the
     other axis as `auto`, which turns this div into a touch-scroll
     container. When the form overflows, touch-drags land here, the
     container has nothing to scroll (scrollH == clientH), and the
     drag dead-ends — never reaching the parent IonContent whose
     native scroll is the actual scroller. Leaving overflow:visible
     lets touch events bubble to ion-content. Horizontal overflow is
     clipped by ion-content's own inner-scroll (overflow-x:hidden) and
     by `max-width:100%` on children. */
  /* Respect the iOS home-indicator safe area so the Capture button is
     never hidden behind the gesture bar on notched devices. */
  padding-bottom:env(safe-area-inset-bottom, 0);
}

/* Sync error */
.ps-sync-err {
  display:flex; align-items:center; justify-content:space-between;
  padding:8px 14px; background:#FEF2F2; border-bottom:1px solid rgba(224,32,80,.15);
  font-size:11px; font-weight:600; color:#B01840;
}
.ps-sync-err button { background:none; border:none; color:#B01840; cursor:pointer; font-size:15px; padding:2px 4px; }

/* ── Passport scan CTA — always-visible, above the form fields ── */
.ps-passport-btn {
  display: flex;
  align-items: center;
  gap: 8px;
  width: calc(100% - 32px);
  margin: 10px 16px 4px;
  padding: 11px 16px;
  border: 1.5px solid #2563EB;
  border-radius: 12px;
  background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);
  color: #1D4ED8;
  font-size: 14px;
  font-weight: 700;
  cursor: pointer;
  text-align: left;
  transition: background .12s, transform .1s;
  -webkit-tap-highlight-color: transparent;
}
.ps-passport-btn:active  { transform: scale(.98); background: #DBEAFE; }
.ps-passport-btn:disabled { opacity: .5; cursor: not-allowed; }

/* ── Field row — premium card surface ── */
.ps-field-row {
  padding:10px 16px 9px;
  border-bottom:1px solid rgba(0,0,0,.04);
  background:linear-gradient(145deg, #FFFFFF 0%, #F4F7FC 100%);
  display:flex; align-items:center; gap:10px; flex-wrap:wrap;
  flex-shrink:0;
  animation:slideUp .5s cubic-bezier(.16,1,.3,1) both;
}

/* Capture accelerator anchor — BCBP + Voice buttons live here.
   Hidden via v-if whenever master is off or both per-feature flags are off. */
.ps-sentinel-actions {
  display:flex; flex-wrap:wrap; gap:10px;
  padding:12px 16px; margin:0;
  background:linear-gradient(145deg, rgba(var(--ion-color-primary-rgb, 11,37,69), .06) 0%, rgba(var(--ion-color-primary-rgb, 11,37,69), .10) 100%);
  border-bottom:1px solid rgba(0,0,0,.06);
}
.ps-sentinel-actions:empty { display:none; }
/* Audit C-F4: voice button hint, only visible when Voice fill is enabled. */
.ps-voice-hint{margin:6px 4px 0;font-size:11px;color:#64748B;line-height:1.4}
.ps-voice-hint em{font-style:normal;font-weight:700;color:#1A3A5C;background:#F1F5F9;padding:1px 6px;border-radius:4px}
.sentinel-action {
  /* WCAG 2.5.5 AAA target size (44×44 css px). Bumped to 48 for elderly +
     gloved-hand tolerance; matches .ps-dir-pill sibling. */
  min-height:48px;
  min-width:48px;
  padding:10px 14px;
  display:inline-flex; align-items:center; gap:8px;
  font-size:15px; font-weight:600; line-height:1.2;
  color:var(--ion-color-primary, #0B2545);
  background:#fff;
  border:1px solid rgba(var(--ion-color-primary-rgb, 11,37,69), .18);
  border-radius:10px;
  cursor:pointer;
  transition:background-color .15s ease, border-color .15s ease, transform .05s ease;
}
.sentinel-action ion-icon {
  font-size:20px; flex-shrink:0;
}
.sentinel-action:hover:not(:disabled) {
  background:rgba(var(--ion-color-primary-rgb, 11,37,69), .04);
  border-color:rgba(var(--ion-color-primary-rgb, 11,37,69), .32);
}
.sentinel-action:active:not(:disabled) { transform:scale(.98); }
.sentinel-action:disabled {
  opacity:.55; cursor:not-allowed;
}
.sentinel-action[aria-busy="true"] {
  animation:sentinelPulse 1.2s ease-in-out infinite;
}
@keyframes sentinelPulse {
  0%,100% { opacity:1; }
  50%     { opacity:.6; }
}
.ps-field-row--temp-wrap {
  flex-direction:column; align-items:stretch; gap:4px; flex-wrap:nowrap;
}
.ps-temp-top {
  display:flex; align-items:center; gap:8px; min-width:0;
}
.ps-field-lbl {
   font-size:9px; font-weight:700; color:#94A3B8;
  text-transform:uppercase; letter-spacing:1.2px;
  white-space:nowrap; min-width:58px;
}
.ps-lbl-opt { font-size:9px; font-weight:500; color:#94A3B8; text-transform:none; letter-spacing:0; }
.ps-ferr    { font-size:10px; font-weight:600; color:#E02050; padding:4px 0 0; line-height:1.3; word-wrap:break-word; overflow-wrap:break-word; }
.ps-fwarn   { font-size:10px; font-weight:600; color:#CC8800; padding:4px 0 0; line-height:1.3; word-wrap:break-word; overflow-wrap:break-word; }

/* ── DIRECTION pills ── */
.ps-dir-pills { display:flex; gap:6px; flex:1; }
.ps-dir-pill {
  flex:1; padding:8px 0; border-radius:10px;  font-size:12px; font-weight:600;
  border:1.5px solid rgba(0,0,0,.06); background:linear-gradient(145deg,#FFFFFF,#F4F7FC); color:#475569;
  cursor:pointer; text-align:center; transition:all .15s cubic-bezier(.16,1,.3,1);
  min-height:44px; -webkit-tap-highlight-color:transparent;
  box-shadow:0 1px 3px rgba(0,0,0,.03);
}
.ps-dir-pill:active { transform:scale(.96); }
.ps-dir-pill--entry   { border-color:rgba(0,112,224,.3); background:linear-gradient(135deg,#E0ECFF,#CCE0FF); color:#0070E0; box-shadow:0 2px 8px rgba(0,112,224,.1); }
.ps-dir-pill--exit    { border-color:rgba(204,136,0,.2); background:linear-gradient(135deg,#FFFBEB,#FEF3C7); color:#CC8800; box-shadow:0 2px 8px rgba(204,136,0,.1); }
.ps-dir-pill--transit { border-color:rgba(123,64,216,.2); background:linear-gradient(135deg,#F5F3FF,#EDE9FE); color:#7B40D8; box-shadow:0 2px 8px rgba(123,64,216,.1); }

/* ── SEX pills ── */
.ps-sex-pills { display:flex; gap:8px; flex:1; }
.ps-sex-btn {
  flex:1; padding:8px 0; border-radius:10px;  font-size:13px; font-weight:600;
  border:1.5px solid rgba(0,0,0,.06); background:linear-gradient(145deg,#FFFFFF,#F4F7FC); color:#475569;
  cursor:pointer; text-align:center; transition:all .15s cubic-bezier(.16,1,.3,1);
  min-height:44px; -webkit-tap-highlight-color:transparent;
  box-shadow:0 1px 3px rgba(0,0,0,.03);
}
.ps-sex-btn:active { transform:scale(.96); }
.ps-sex-btn--m-active { border-color:rgba(0,112,224,.3); background:linear-gradient(135deg,#E0ECFF,#CCE0FF); color:#0070E0; box-shadow:0 4px 16px rgba(0,112,224,.15); }
.ps-sex-btn--f-active { border-color:rgba(214,51,132,.2); background:linear-gradient(135deg,#FDF2F8,#FCE7F3); color:#D63384; box-shadow:0 4px 16px rgba(214,51,132,.15); }

/* ───────────────────────────────────────────────────────────────────────
   TEMPERATURE — premium native-feel block
   2026-05-06 redesign: equal-width chips in a CSS grid (never overflow),
   clean stepper row, strong selected state (green-600 + white check), clear
   abnormal-threshold hint, compact level badge.
─────────────────────────────────────────────────────────────────────── */
.ps-temp-card{
  margin-top:10px;padding:14px;border-radius:14px;
  background:#FFFFFF;border:1px solid #E2E8F0;
  box-shadow:0 1px 2px rgba(15,23,42,.04);
}
.ps-temp-head{
  display:flex;align-items:center;flex-wrap:wrap;gap:8px;
  margin-bottom:10px;
}
.ps-temp-title{
  font-size:13px;font-weight:800;color:#0F172A;letter-spacing:-.01em;
  display:inline-flex;align-items:center;gap:8px;flex:1;min-width:0;
}
.ps-temp-optional{
  font-size:10px;font-weight:700;color:#64748B;
  background:#F1F5F9;padding:2px 7px;border-radius:99px;letter-spacing:.02em;
}
.ps-temp-hint{
  font-size:10.5px;font-weight:700;color:#92400E;
  background:rgba(217,119,6,.10);padding:3px 8px;border-radius:6px;
  white-space:nowrap;
}

/* Quick chips — CSS grid forces equal columns that resize cleanly */
.ps-temp-quick{
  display:grid;grid-template-columns:repeat(3,1fr);
  gap:8px;margin-bottom:10px;
}
.ps-temp-chip{
  display:flex;align-items:center;justify-content:center;gap:2px;
  height:48px;padding:0 6px;border-radius:12px;
  border:1.5px solid #CBD5E1;background:#FFFFFF;color:#0F172A;
  font-family:inherit;cursor:pointer;
  transition:transform .08s ease,background .15s ease,border-color .15s ease,box-shadow .15s ease;
  -webkit-tap-highlight-color:transparent;
  box-shadow:0 1px 1px rgba(15,23,42,.03);
  min-width:0;       /* allow shrinking inside grid cell */
}
.ps-temp-chip:active{transform:scale(.97)}
.ps-temp-chip:hover{background:#F8FAFC;border-color:#94A3B8}
.ps-temp-chip-num{font-size:17px;font-weight:800;letter-spacing:-.02em;color:inherit}
.ps-temp-chip-unit{font-size:11px;font-weight:700;color:#64748B;margin-left:2px}
.ps-temp-chip--on{
  background:linear-gradient(180deg,#10B981 0%,#059669 100%);
  border-color:#047857;
  box-shadow:0 4px 14px rgba(16,185,129,.32),inset 0 -1px 0 rgba(0,0,0,.08);
}
.ps-temp-chip--on .ps-temp-chip-num,
.ps-temp-chip--on .ps-temp-chip-unit{color:#FFFFFF}

/* Manual stepper row */
.ps-temp-manual{
  display:flex;align-items:center;gap:10px;padding:8px 12px;
  border-radius:12px;background:#F8FAFC;border:1.5px solid #E2E8F0;
  transition:border-color .2s ease,background .2s ease,box-shadow .2s ease;
}
.ps-temp-manual--focus{border-color:#0070E0;background:#FFFFFF;box-shadow:0 0 0 3px rgba(0,112,224,.10)}
.ps-temp-manual--warn {border-color:#D97706;background:linear-gradient(135deg,#FFFBEB,#FEF3C7)}
.ps-temp-manual--crit {border-color:#DC2626;background:linear-gradient(135deg,#FEF2F2,#FECACA)}
.ps-temp-manual-input{
  flex:1;min-width:0;height:36px;border:none;background:transparent;
  font-family:inherit;font-size:16px;font-weight:700;color:#0F172A;
  outline:none;
  font-variant-numeric:tabular-nums;
}
.ps-temp-manual-input::placeholder{color:#94A3B8;font-weight:500}
.ps-temp-manual-input::-webkit-outer-spin-button,
.ps-temp-manual-input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.ps-temp-manual-unit{
  font-size:13px;font-weight:800;color:#64748B;flex-shrink:0;
}
.ps-temp-badge{
  flex-shrink:0;font-size:10px;font-weight:800;letter-spacing:.04em;
  padding:4px 9px;border-radius:99px;text-transform:uppercase;
  background:#DCFCE7;color:#065F46;
}
.ps-temp-badge--warn{background:#FEF3C7;color:#92400E}
.ps-temp-badge--crit{background:#FEE2E2;color:#991B1B}
.ps-temp-clear{
  flex-shrink:0;width:26px;height:26px;border-radius:50%;
  border:1px solid #CBD5E1;background:#FFFFFF;color:#64748B;
  font-size:14px;font-weight:700;line-height:1;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
}
.ps-temp-clear:hover{background:#F1F5F9;color:#0F172A}
.ps-ferr--temp,.ps-fwarn--temp{margin-top:8px}
.ps-temp-row { display:flex; align-items:center; gap:6px; flex:1; min-width:0; flex-wrap:wrap; }
.ps-temp-input-wrap {
  display:flex; align-items:center; gap:4px;
  background:linear-gradient(145deg,#E8EDF7,#F0F3FA); border:1.5px solid rgba(0,0,0,.08);
  border-radius:10px; padding:0 10px; min-width:0; max-width:120px; flex-shrink:0;
  transition:all .25s cubic-bezier(.16,1,.3,1);
}
.ps-temp-input-wrap--focus { border-color:rgba(0,112,224,.35); background:#fff; box-shadow:0 0 0 3px rgba(0,112,224,.08); }
.ps-temp-input-wrap--warn  { border-color:rgba(204,136,0,.4); background:linear-gradient(135deg,#FFFBEB,#FEF3C7); }
.ps-temp-input-wrap--crit  { border-color:rgba(224,32,80,.4); background:linear-gradient(135deg,#FEF2F2,#FECACA); }
.ps-temp-input {
  width:56px; background:transparent; border:none; outline:none;
  font-size:18px; font-weight:800; color:#0B1A30; padding:7px 0;
  -moz-appearance:textfield; min-width:0;
}
.ps-temp-input::-webkit-outer-spin-button,
.ps-temp-input::-webkit-inner-spin-button { -webkit-appearance:none; }
.ps-temp-input::placeholder { font-size:16px; color:#94A3B8; font-weight:400; }
.ps-temp-unit-lbl { font-size:12px; font-weight:600; color:#94A3B8; flex-shrink:0; }
.ps-unit-toggle { display:flex; border:1.5px solid rgba(0,0,0,.08); border-radius:8px; overflow:hidden; flex-shrink:0; }
.ps-unit-btn {
  padding:6px 10px; background:transparent; border:none;  font-size:11px; font-weight:700; color:#94A3B8; cursor:pointer;
  transition:all .15s; min-height:36px;
}
.ps-unit-btn--on { background:linear-gradient(135deg,#070E1B,#0E1A2E); color:#EDF2FA; }
.ps-temp-level { display:flex; align-items:center; gap:3px; font-size:9px; font-weight:600; color:#475569; white-space:nowrap; flex-shrink:0; }
.ps-temp-level--warn { color:#CC8800; }
.ps-temp-level--crit { color:#E02050; }
.ps-temp-clear { background:none; border:none; color:#94A3B8; cursor:pointer; font-size:13px; padding:2px 4px; flex-shrink:0; }

/* ── Fever auto-guard banner ── */
.ps-fever-guard {
  display:flex; gap:8px; align-items:flex-start;
  margin:0; padding:8px 12px;
  background:linear-gradient(135deg,#FFFBEB 0%,#FEF3C7 100%);
  border-bottom:1px solid rgba(204,136,0,.15);
  flex-shrink:0; max-width:100%; box-sizing:border-box;
  animation:revealIn .3s cubic-bezier(.16,1,.3,1);
}
.ps-fever-guard svg { width:16px; height:16px; flex-shrink:0; margin-top:1px; }
.ps-fever-guard-body { flex:1; min-width:0; }
.ps-fever-guard-title { font-size:10px; font-weight:700; color:#CC8800; margin-bottom:2px; }
.ps-fever-guard-text { font-size:10px; color:#475569; line-height:1.35; margin-bottom:5px; }
.ps-fever-guard-actions { display:flex; gap:5px; flex-wrap:wrap; }
.ps-fever-guard-btn {
  padding:5px 10px; border-radius:6px; font-size:10px; font-weight:600; cursor:pointer; min-height:32px;
  transition:all .15s; -webkit-tap-highlight-color:transparent;
}
.ps-fever-guard-btn--sym {
  background:linear-gradient(135deg,#B01840,#E02050); color:#fff; border:none;
}
.ps-fever-guard-btn--clear {
  background:#fff; color:#475569; border:1px solid rgba(0,0,0,.1);
}

/* ── WHO panel ── */
.ps-who-panel {
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border-bottom:1px solid rgba(0,0,0,.04);
  flex-shrink:0;
}
.ps-who-toggle {
  display:flex; align-items:center; gap:7px; width:100%;
  padding:8px 16px; border:none; background:transparent; cursor:pointer;
  font-size:11px; font-weight:600; color:#0070E0; text-align:left;
  -webkit-tap-highlight-color:transparent;
}
.ps-who-toggle svg { width:13px; height:13px; flex-shrink:0; stroke:#0070E0; }
.ps-who-chev { transition:transform .25s cubic-bezier(.16,1,.3,1); flex-shrink:0; }
.ps-who-chev--open { transform:rotate(180deg); }
.ps-acc-enter-active { transition:all .25s cubic-bezier(.16,1,.3,1); }
.ps-acc-leave-active { transition:all .15s ease-in; }
.ps-acc-enter-from,.ps-acc-leave-to { opacity:0; transform:translateY(-6px); max-height:0; overflow:hidden; }
.ps-who-cats { padding:4px 16px 12px; display:flex; flex-direction:column; gap:8px; }
.ps-who-cat  { display:flex; align-items:flex-start; gap:8px; }
.ps-who-cat-label {
  font-size:8px; font-weight:700; text-transform:uppercase;
  letter-spacing:1.2px; color:#94A3B8; min-width:92px; padding-top:4px; flex-shrink:0;
}
.ps-who-chips { display:flex; flex-wrap:wrap; gap:4px; }
.ps-who-chip {
  font-size:10px; font-weight:500; color:#475569;
  background:linear-gradient(135deg,#E0ECFF,#CCE0FF); border:1px solid rgba(0,112,224,.1);
  padding:3px 9px; border-radius:5px;
}

/* ── Symptoms section ── */
.ps-sym-section {
  padding:10px 16px 10px;
  border-bottom:1px solid rgba(0,0,0,.04);
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC); flex-shrink:0;
}
.ps-sym-label { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
.ps-req-badge {
  font-size:9px; font-weight:700;
  text-transform:uppercase; letter-spacing:.6px;
  color:#E02050; background:linear-gradient(135deg,#FEF2F2,#FECACA); padding:3px 9px; border-radius:5px;
  border:1px solid rgba(224,32,80,.1);
}
.ps-sym-row { display:flex; gap:8px; }
.ps-sym-no, .ps-sym-yes {
  flex:1; display:flex; flex-direction:column; align-items:center; gap:5px;
  padding:14px 8px 12px; border-radius:14px; border:2px solid rgba(0,0,0,.06);
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC); cursor:pointer; min-height:100px;
  transition:all .2s cubic-bezier(.16,1,.3,1); position:relative; overflow:hidden;
  box-shadow:0 1px 3px rgba(0,0,0,.04),0 4px 20px rgba(0,30,80,.06);
  -webkit-tap-highlight-color:transparent;
}
.ps-sym-no::before, .ps-sym-yes::before {
  content:''; position:absolute; top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.8) 50%,transparent 80%);
}
.ps-sym-ic  { width:36px; height:36px; display:flex; align-items:center; justify-content:center; }
.ps-sym-ic svg { width:32px; height:32px; }
.ps-sym-main { font-size:14px; font-weight:700; color:#0B1A30; line-height:1.2; }
.ps-sym-sub  { font-size:10px; color:#94A3B8; }
.ps-sym-no:active, .ps-sym-yes:active { transform:scale(.97); }
.ps-sym-no--active {
  border-color:rgba(0,168,107,.3); background:linear-gradient(135deg,#ECFDF5,#D1FAE5);
  box-shadow:0 4px 16px rgba(0,168,107,.2);
}
.ps-sym-no--active .ps-sym-main { color:#007A50; }
.ps-sym-no--active .ps-sym-sub  { color:#00A86B; }
.ps-sym-yes--active {
  border-color:rgba(224,32,80,.3); background:linear-gradient(135deg,#FEF2F2,#FECACA);
  box-shadow:0 4px 16px rgba(224,32,80,.2);
}
.ps-sym-yes--active .ps-sym-main { color:#B01840; }
.ps-sym-yes--active .ps-sym-sub  { color:#E02050; }

/* ── Name section — reveals only when symptomatic ── */
.ps-name-section {
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC); border-bottom:1px solid rgba(0,0,0,.04);
  padding:10px 16px 10px; flex-shrink:0;
}
.ps-reveal-enter-active { animation:revealIn .25s cubic-bezier(.16,1,.3,1); }
.ps-reveal-leave-active { transition:opacity .15s, transform .15s; }
.ps-reveal-leave-to     { opacity:0; transform:translateY(-6px); }
.ps-name-row { display:flex; flex-direction:column; gap:5px; }
.ps-name-field {
  display:flex; align-items:center; gap:8px;
  background:linear-gradient(145deg,#E8EDF7,#F0F3FA); border:1.5px solid rgba(0,0,0,.08);
  border-radius:10px; padding:0 14px;
  transition:all .25s cubic-bezier(.16,1,.3,1);
}
.ps-name-field--focus { border-color:rgba(0,112,224,.35); background:#fff; box-shadow:0 0 0 3px rgba(0,112,224,.08); }
.ps-name-field--err   { border-color:rgba(224,32,80,.35); background:linear-gradient(135deg,#FEF2F2,#FECACA); }
.ps-name-input {
  flex:1; background:transparent; border:none; outline:none;
  font-size:16px; color:#0B1A30; padding:12px 0;
  min-height:48px;
}
.ps-name-input::placeholder { color:#94A3B8; font-size:14px; }
.ps-name-clear { background:none; border:none; color:#94A3B8; cursor:pointer; font-size:14px; padding:4px; min-width:36px; min-height:36px; display:flex; align-items:center; justify-content:center; }
.ps-name-scan {
  background:linear-gradient(135deg,#0B2545,#13315C); border:none; color:#00E6D6; cursor:pointer;
  width:36px; height:36px; border-radius:10px; margin-right:4px;
  display:flex; align-items:center; justify-content:center;
  box-shadow:0 2px 6px rgba(11,37,69,.25); transition:transform .1s, box-shadow .15s;
}
.ps-name-scan:active:not(:disabled) { transform:scale(.94); }
.ps-name-scan:disabled { opacity:.4; cursor:not-allowed; }
.ps-req { color:#E02050; }

/* Priority strip */
.ps-priority-strip {
  display:flex; align-items:center; gap:8px; margin-top:8px;
  padding:8px 12px; border-radius:10px; border:1.5px solid;
}
.ps-priority-strip--normal   { background:linear-gradient(135deg,#E0ECFF,#CCE0FF); border-color:rgba(0,112,224,.15); }
.ps-priority-strip--high     { background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border-color:rgba(204,136,0,.15); }
.ps-priority-strip--critical { background:linear-gradient(135deg,#FEF2F2,#FECACA); border-color:rgba(224,32,80,.15); }
.ps-pd { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.ps-pd--normal   { background:#0070E0; box-shadow:0 0 8px rgba(0,112,224,.4); }
.ps-pd--high     { background:#CC8800; box-shadow:0 0 8px rgba(204,136,0,.4); }
.ps-pd--critical { background:#E02050; box-shadow:0 0 8px rgba(224,32,80,.4); animation:dotPulse 1.5s ease-in-out infinite; }
.ps-pl { font-size:10px; font-weight:700; letter-spacing:.6px; }
.ps-priority-strip--normal   .ps-pl { color:#0070E0; }
.ps-priority-strip--high     .ps-pl { color:#CC8800; }
.ps-priority-strip--critical .ps-pl { color:#E02050; }
.ps-pr { font-size:10px; color:#475569; margin-left:auto; text-align:right; }

/* ── Capture button + footer ── */
.ps-capture-footer {
  padding:12px 16px;
  background:linear-gradient(180deg,#F4F7FC 0%,#FFFFFF 100%);
  border-top:1px solid rgba(0,0,0,.06);
  box-shadow:0 -2px 12px rgba(0,30,80,.04);
  flex-shrink:0;
  margin-top:auto;
}
.ps-cap-btn {
  width:100%; border-radius:12px; border:none; cursor:pointer;
  transition:all .2s cubic-bezier(.16,1,.3,1); min-height:52px;
  position:relative; overflow:hidden;
  -webkit-tap-highlight-color:transparent;
}
.ps-cap-btn::before {
  content:''; position:absolute; top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.25) 50%,transparent 80%);
}
.ps-cap-btn-inner { display:flex; align-items:center; justify-content:center; gap:8px; padding:14px 20px; }
.ps-cap-btn-inner svg { width:20px; height:20px; }
.ps-cap-btn-inner span { font-size:14px; font-weight:600; letter-spacing:.5px; }
.ps-cap-btn--clear    {
  background:linear-gradient(135deg,#007A50,#00A86B);
  box-shadow:0 4px 16px rgba(0,168,107,.25);
}
.ps-cap-btn--clear .ps-cap-btn-inner { color:#fff; }
.ps-cap-btn--clear:active { transform:scale(.98); }
.ps-cap-btn--referral {
  background:linear-gradient(135deg,#B01840,#E02050);
  box-shadow:0 4px 16px rgba(224,32,80,.25);
}
.ps-cap-btn--referral .ps-cap-btn-inner { color:#fff; }
.ps-cap-btn--referral:active { transform:scale(.98); }
.ps-cap-btn--disabled {
  background:linear-gradient(145deg,#F8FAFC,#F1F5F9); box-shadow:none; cursor:not-allowed;
  border:1px solid rgba(0,0,0,.06);
}
.ps-cap-btn--disabled .ps-cap-btn-inner { color:#94A3B8; }
.ps-cap-hint { font-size:11px; font-weight:500; color:#94A3B8; text-align:center; margin-top:6px; }

/* ── Result panel ── */
.ps-result-enter-active { animation:resultIn .35s cubic-bezier(.16,1,.3,1); }
.ps-result-leave-active { transition:opacity .15s; }
.ps-result-leave-to     { opacity:0; }
.ps-result {
  margin:8px 12px 10px; border-radius:12px; padding:12px; border:1.5px solid;
  animation:resultIn .35s cubic-bezier(.16,1,.3,1);
  box-shadow:0 1px 3px rgba(0,0,0,.04),0 4px 20px rgba(0,30,80,.06);
  position:relative; overflow:hidden;
  max-width:100%; box-sizing:border-box;
}
.ps-result::before {
  content:''; position:absolute; top:0; left:0; right:0; height:1px;
  background:linear-gradient(90deg,transparent 20%,rgba(255,255,255,.8) 50%,transparent 80%);
}
.ps-result--ok  { background:linear-gradient(135deg,#ECFDF5,#D1FAE5); border-color:rgba(0,168,107,.12); }
.ps-result--ref { background:linear-gradient(135deg,#FEF2F2,#FECACA); border-color:rgba(224,32,80,.12); }
.ps-result-hdr  { display:flex; align-items:center; gap:8px; margin-bottom:6px; }
.ps-result-icon { font-size:18px; width:32px; height:32px; border-radius:8px; background:rgba(0,0,0,.05); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.ps-result-title { flex:1; font-size:12px; font-weight:700; color:#0B1A30; min-width:0; }
.ps-result-count { display:flex; flex-direction:column; align-items:center; flex-shrink:0; }
.ps-result-n { font-size:18px; font-weight:800; color:#0B1A30; line-height:1; }
.ps-result-l { font-size:7px; font-weight:700; color:#94A3B8; text-transform:uppercase; letter-spacing:.8px; }
.ps-result-chips { display:flex; flex-wrap:wrap; gap:4px; margin-bottom:8px; overflow:hidden; }
.ps-rc { font-size:9px; font-weight:600; color:#475569; background:linear-gradient(135deg,#F8FAFC,#F1F5F9); padding:2px 7px; border-radius:4px; border:1px solid rgba(0,0,0,.06); max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ps-rc--temp     { color:#CC8800; background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border-color:rgba(204,136,0,.12); }
.ps-rc--critical { color:#E02050; background:linear-gradient(135deg,#FEF2F2,#FECACA); border-color:rgba(224,32,80,.1); }
.ps-rc--high     { color:#CC8800; background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border-color:rgba(204,136,0,.12); }
.ps-rc--normal   { color:#00A86B; background:linear-gradient(135deg,#ECFDF5,#D1FAE5); border-color:rgba(0,168,107,.12); }
.ps-rc--synced   { color:#00A86B; background:linear-gradient(135deg,#ECFDF5,#D1FAE5); border-color:rgba(0,168,107,.12); }
.ps-rc--pending  { color:#CC8800; background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border-color:rgba(204,136,0,.12); }
.ps-rc--sync     { font-size:9px; font-weight:500; }
.ps-result-actions { display:flex; gap:6px; }
.ps-result-void {
  flex:1; padding:9px 8px; border-radius:8px;
  background:linear-gradient(145deg,#FFFFFF,#F4F7FC);
  border:1.5px solid rgba(0,0,0,.08); color:#475569;
  font-size:11px; font-weight:600; cursor:pointer; min-height:40px;
  text-align:center;
  -webkit-tap-highlight-color:transparent;
}
.ps-result-next {
  flex:2; padding:9px 8px; border-radius:8px;
  background:linear-gradient(135deg,#0055CC,#0070E0,#3399FF);
  border:none; color:#fff;
  font-size:11px; font-weight:600; cursor:pointer; min-height:40px;
  box-shadow:0 4px 16px rgba(0,112,224,.25);
  text-align:center;
  -webkit-tap-highlight-color:transparent;
}

/* ═══════════════════════════════════════════════════════════════
   RECORDS TAB
═══════════════════════════════════════════════════════════════ */
.ps-records-tab { display:flex; flex-direction:column; height:100%; }
.ps-tab-bar {
  display:flex; align-items:center; gap:7px; padding:9px 14px;
  background:#fff; border-bottom:1px solid rgba(0,0,0,.06); flex-shrink:0;
}
.ps-filter-btn {
  display:flex; align-items:center; gap:5px; padding:6px 10px;
  border-radius:7px; background:#EFF6FF; border:1.5px solid rgba(59,130,246,.2);
  color:#1D4ED8; font-size:11px; font-weight:700; cursor:pointer;
}
.ps-filter-btn svg { width:11px; height:11px; stroke:currentColor; }
.ps-total-lbl { flex:1; font-size:11px; font-weight:500; color:#94A3B8; }
.ps-icon-btn  { width:32px; height:32px; border-radius:7px; background:#F1F5F9; border:1.5px solid rgba(0,0,0,.08); display:flex; align-items:center; justify-content:center; cursor:pointer; }
.ps-icon-btn svg { width:13px; height:13px; stroke:#64748B; }
.ps-icon-btn:disabled { opacity:.5; cursor:not-allowed; }

.ps-filter-row { display:flex; gap:5px; overflow-x:auto; scrollbar-width:none; padding:7px 14px; background:#fff; border-bottom:1px solid rgba(0,0,0,.05); flex-shrink:0; }
.ps-filter-row::-webkit-scrollbar { display:none; }
.ps-fc { padding:5px 11px; border-radius:14px; font-size:10px; font-weight:700; border:1.5px solid rgba(0,0,0,.08); background:#F8FAFC; color:#64748B; cursor:pointer; white-space:nowrap; transition:all .12s; min-height:30px; }
.ps-fc--on     { background:#0F172A; border-color:#0F172A; color:#fff; }
.ps-fc--sym.ps-fc--on  { background:#DC2626; border-color:#DC2626; color:#fff; }
.ps-fc--ok.ps-fc--on   { background:#16A34A; border-color:#16A34A; color:#fff; }
.ps-fc--pending.ps-fc--on { background:#D97706; border-color:#D97706; color:#fff; }
.ps-fc--dir.ps-fc--on  { background:#7C3AED; border-color:#7C3AED; color:#fff; }

.ps-loading { display:flex; justify-content:center; padding:40px; }
.ps-dots { display:flex; gap:5px; }
.ps-dots div { width:7px; height:7px; border-radius:50%; background:#94A3B8; animation:dotPulse 1.2s infinite; }
.ps-dots div:nth-child(2) { animation-delay:.2s; }
.ps-dots div:nth-child(3) { animation-delay:.4s; }
.ps-empty { display:flex; flex-direction:column; align-items:center; gap:8px; padding:50px 24px; color:#94A3B8; }
.ps-empty svg { opacity:.4; }
.ps-empty p { font-size:13px; font-weight:500; }

.ps-rec-list { display:flex; flex-direction:column; gap:1px; background:rgba(0,0,0,.04); overflow-y:auto; }
.ps-rec-card {
  display:flex; align-items:stretch; background:#fff; cursor:pointer;
  transition:background .1s;
}
.ps-rec-card:hover { background:#F8FAFC; }
.ps-rec-card--void { opacity:.6; }
.ps-rec-bar { width:3px; flex-shrink:0; }
.ps-rec-bar--sym { background:#DC2626; }
.ps-rec-bar--ok  { background:#16A34A; }
.ps-rec-body { flex:1; padding:10px 12px; min-width:0; }
.ps-rec-top  { display:flex; align-items:baseline; justify-content:space-between; margin-bottom:3px; }
.ps-rec-name { font-size:13px; font-weight:700; color:#0F172A; }
.ps-rec-time { font-size:10px; color:#94A3B8; font-variant-numeric:tabular-nums; }
.ps-rec-mid  { display:flex; align-items:center; gap:5px; font-size:11px; color:#64748B; margin-bottom:5px; }
.ps-rec-dot  { color:#CBD5E1; }
.ps-rec-bot  { display:flex; align-items:center; gap:5px; flex-wrap:wrap; }
.ps-rbadge   { font-size:9px; font-weight:700; padding:2px 7px; border-radius:4px; border:1px solid; }
.ps-rbadge--sym  { background:#FFF1F2; color:#991B1B; border-color:rgba(153,27,27,.2); }
.ps-rbadge--ok   { background:#F0FDF4; color:#166534; border-color:rgba(22,101,52,.2); }
.ps-rbadge--void { background:#F1F5F9; color:#64748B; border-color:rgba(0,0,0,.1); }
.ps-rsync { font-size:9px; font-weight:700; padding:2px 7px; border-radius:4px; }
.ps-rsync--synced  { background:#F0FDF4; color:#166534; }
.ps-rsync--pending { background:#FFFBEB; color:#92400E; }
.ps-rsync--failed  { background:#FFF1F2; color:#991B1B; }
.ps-rec-arrow { display:flex; align-items:center; padding:0 12px; color:#CBD5E1; font-size:18px; font-weight:200; }

.ps-pages { display:flex; align-items:center; justify-content:center; gap:10px; padding:12px; background:#fff; border-top:1px solid rgba(0,0,0,.05); }
.ps-pg-btn { padding:7px 14px; border-radius:7px; background:#F1F5F9; border:1.5px solid rgba(0,0,0,.08); color:#0F172A; font-size:11px; font-weight:700; cursor:pointer; min-height:34px; }
.ps-pg-btn:disabled { opacity:.4; cursor:not-allowed; }
.ps-pg-info { font-size:11px; font-weight:500; color:#64748B; font-variant-numeric:tabular-nums; }

/* ═══════════════════════════════════════════════════════════════
   QUEUE TAB
═══════════════════════════════════════════════════════════════ */
.ps-queue-tab { display:flex; flex-direction:column; height:100%; }
.ps-q-list    { display:flex; flex-direction:column; gap:1px; background:rgba(0,0,0,.04); overflow-y:auto; }
.ps-q-card    { background:#fff; cursor:pointer; transition:background .1s; }
.ps-q-card:hover { background:#F8FAFC; }
.ps-q-card--closed { opacity:.6; }
.ps-q-top-bar { height:3px; }
.ps-qtb--critical { background:linear-gradient(90deg,#B91C1C,#DC2626); }
.ps-qtb--high     { background:linear-gradient(90deg,#D97706,#F59E0B); }
.ps-qtb--normal   { background:linear-gradient(90deg,#15803D,#16A34A); }
.ps-q-body { padding:10px 14px; }
.ps-q-row1 { display:flex; align-items:center; gap:7px; margin-bottom:4px; }
.ps-q-pri  { font-size:9px; font-weight:800; padding:2px 8px; border-radius:4px; border:1px solid; letter-spacing:.3px; }
.ps-q-pri--critical { background:#FFF1F2; color:#991B1B; border-color:rgba(153,27,27,.2); }
.ps-q-pri--high     { background:#FFFBEB; color:#92400E; border-color:rgba(146,64,14,.2); }
.ps-q-pri--normal   { background:#F0FDF4; color:#166534; border-color:rgba(22,101,52,.2); }
.ps-q-sts  { font-size:9px; font-weight:700; padding:2px 7px; border-radius:4px; background:#F1F5F9; color:#64748B; }
.ps-q-time { margin-left:auto; font-size:10px; color:#94A3B8; font-variant-numeric:tabular-nums; }
.ps-q-name { font-size:14px; font-weight:700; color:#0F172A; margin-bottom:5px; }
.ps-q-chips { display:flex; gap:4px; flex-wrap:wrap; }
.ps-qc { font-size:10px; font-weight:600; color:#64748B; background:#F1F5F9; padding:2px 8px; border-radius:10px; }
.ps-qc--temp { color:#92400E; background:#FFFBEB; }
.ps-qc--sym { color:#7C3AED; background:#F5F3FF; text-transform:capitalize; }
.ps-qc--more { color:#94A3B8; background:#F8FAFC; }
/* ───────────────────────────────────────────────────────────────────────
   QUICK SYMPTOMS — premium responsive grid
   2 cols on phone (<480), 3 cols on tablet, 4 cols on desktop. Cards never
   overflow because they live in CSS grid columns sized via minmax(0, 1fr).
   Selected state uses indigo accent + white check + soft shadow.
─────────────────────────────────────────────────────────────────────── */
.ps-qsym-section{
  margin-top:14px;padding:14px;border-radius:14px;
  background:#FFFFFF;border:1px solid #E2E8F0;
  box-shadow:0 1px 2px rgba(15,23,42,.04);
}
.ps-qsym-head{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:10px;gap:8px}
.ps-qsym-title{font-size:13px;font-weight:800;color:#0F172A;letter-spacing:-.01em}
.ps-qsym-hint{font-size:10.5px;font-weight:700;color:#64748B}
.ps-qsym-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:8px;
}
@media (min-width:520px){ .ps-qsym-grid{ grid-template-columns:repeat(3,minmax(0,1fr)); } }
@media (min-width:820px){ .ps-qsym-grid{ grid-template-columns:repeat(4,minmax(0,1fr)); } }

.ps-qsym-card{
  display:flex;align-items:center;gap:8px;
  padding:10px 12px;min-height:48px;
  border:1.5px solid #E2E8F0;border-radius:12px;
  background:#FFFFFF;color:#475569;
  font-family:inherit;font-size:13px;font-weight:700;
  text-align:left;cursor:pointer;
  transition:transform .08s ease,background .15s ease,border-color .15s ease,box-shadow .15s ease;
  -webkit-tap-highlight-color:transparent;
  min-width:0;
}
.ps-qsym-card:active{transform:scale(.97)}
.ps-qsym-card:hover{background:#F8FAFC;border-color:#94A3B8}
.ps-qsym-check{
  flex-shrink:0;width:20px;height:20px;border-radius:6px;
  border:1.5px solid #CBD5E1;background:#FFFFFF;
  display:flex;align-items:center;justify-content:center;
  transition:all .15s ease;
}
.ps-qsym-check svg{width:12px;height:12px}
.ps-qsym-label{
  flex:1;min-width:0;line-height:1.25;
  /* Allow up to two lines for long labels like "General Body Weakness" */
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
}
.ps-qsym-card--on{
  border-color:#5B21B6;
  background:linear-gradient(135deg,#F5F3FF 0%,#EDE9FE 100%);
  color:#3B0764;
  box-shadow:0 4px 14px rgba(91,33,182,.18);
}
.ps-qsym-card--on .ps-qsym-check{
  background:#5B21B6;border-color:#4C1D95;
}

/* ═══════════════════════════════════════════════════════════════
   MODALS
═══════════════════════════════════════════════════════════════ */
/* Void */
.ps-void-wrap { padding:16px; display:flex; flex-direction:column; gap:12px; }
.ps-void-warn {
  display:flex; align-items:flex-start; gap:10px; padding:12px;
  background:#FFFBEB; border:1px solid rgba(217,119,6,.25); border-radius:9px;
  font-size:11px; color:#78350F; line-height:1.5;
}
.ps-void-warn svg { width:16px; height:16px; flex-shrink:0; margin-top:2px; }
.ps-void-lbl { font-size:10px; font-weight:700; color:#64748B; text-transform:uppercase; letter-spacing:.5px; }
.ps-void-ta {
  width:100%; background:#F8FAFC; border:1.5px solid rgba(0,0,0,.1); border-radius:8px;
  color:#0F172A; font-size:16px; padding:10px 12px; outline:none; font-family:inherit;
  resize:none; transition:border-color .15s;
}
.ps-void-ta:focus { border-color:#3B82F6; background:#EFF6FF; }
.ps-void-meta { font-size:10px; color:#94A3B8; text-align:right; }
.ps-void-meta--err { color:#DC2626; }
.ps-void-submit {
  width:100%; padding:13px; border-radius:9px; border:none;
  background:linear-gradient(135deg,#B91C1C,#DC2626); color:#fff;
  font-size:14px; font-weight:800; cursor:pointer; min-height:48px; font-family:inherit;
}
.ps-void-submit:disabled { opacity:.45; cursor:not-allowed; }

/* Detail */
.ps-det-wrap { display:flex; flex-direction:column; }
.ps-det-status {
  padding:13px 16px; font-size:12px; font-weight:800; display:flex; align-items:center; justify-content:space-between;
}
.ps-det-status--ok       { background:#F0FDF4; color:#166534; border-bottom:1px solid rgba(22,163,74,.15); }
.ps-det-status--sym      { background:#FFF1F2; color:#991B1B; border-bottom:1px solid rgba(220,38,38,.15); }
.ps-det-status--critical { background:#FFF1F2; color:#991B1B; border-bottom:1px solid rgba(220,38,38,.15); }
.ps-det-status--high     { background:#FFFBEB; color:#92400E; border-bottom:1px solid rgba(245,158,11,.2); }
.ps-det-status--normal   { background:#F0FDF4; color:#166534; border-bottom:1px solid rgba(22,163,74,.15); }
.ps-det-voided { font-size:9px; background:rgba(0,0,0,.1); color:#64748B; padding:2px 7px; border-radius:4px; }
.ps-det-grid { display:flex; flex-direction:column; }
.ps-dg-row { display:flex; align-items:baseline; justify-content:space-between; gap:12px; padding:10px 16px; border-bottom:1px solid rgba(0,0,0,.05); }
.ps-dk { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#94A3B8; min-width:90px; flex-shrink:0; }
.ps-dv { font-size:12px; font-weight:600; color:#0F172A; text-align:right; flex:1; }
.ps-dv--red   { color:#DC2626; }
.ps-dv--green { color:#16A34A; }
.ps-dv--mono  { font-variant-numeric:tabular-nums; font-size:11px; word-break:break-all; }
.ps-det-void-btn {
  width:100%; padding:12px; border-radius:9px;
  background:#FFF1F2; border:1.5px solid rgba(220,38,38,.2);
  color:#DC2626; font-size:12px; font-weight:700; cursor:pointer; min-height:44px;
}

/* Date filter */
.ps-date-opt {
  width:100%; padding:13px 14px; border-radius:9px; text-align:left;
  background:#F8FAFC; border:1.5px solid rgba(0,0,0,.08);
  font-size:14px; font-weight:600; color:#0F172A; cursor:pointer; min-height:48px; font-family:inherit;
}
.ps-date-opt--on { background:#EFF6FF; border-color:#3B82F6; color:#1D4ED8; }
.ps-date-input {
  width:100%; box-sizing:border-box; background:#F8FAFC;
  border:1.5px solid rgba(0,0,0,.1); border-radius:8px; color:#0F172A;
  font-size:16px; padding:10px 12px; outline:none; font-family:inherit;
}

/* Modal sizing — full height sheet on mobile, centered card on tablet+ */
.pp-modal{
  --width:100%;
  --max-width:640px;
  --height:100%;
  --max-height:100%;
  --border-radius:0;
  --background:#F8FAFC;
}
@media (min-width:760px){
  .pp-modal{
    --width:640px;
    --height:88vh;
    --max-height:880px;
    --border-radius:18px;
  }
}

/* ─── PASSPORT / QR ENTRY MODAL — premium spacious layout (2026-05-07) ─── */
.pp-body{
  padding:24px 20px 40px;
  display:flex;flex-direction:column;gap:20px;
  max-width:560px;margin:0 auto;
  min-height:100%;
  font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
}
.pp-lead{padding:4px 2px 8px}
.pp-lead-t{
  font-size:18px;font-weight:800;color:#0B2545;
  letter-spacing:-.2px;line-height:1.25;
}
.pp-lead-d{
  font-size:13.5px;color:#475569;
  margin-top:8px;line-height:1.55;
}
.pp-lead-d code{
  background:#EFF4FA;color:#0B2545;
  padding:2px 7px;border-radius:5px;
  font-size:12px;font-family:ui-monospace,Menlo,Consolas,monospace;
}

.pp-opt{
  display:flex;align-items:center;gap:16px;
  padding:18px 18px;
  background:#fff;
  border:1.5px solid #E2E8F0;
  border-radius:16px;
  text-align:left;cursor:pointer;width:100%;
  box-shadow:0 1px 2px rgba(11,37,69,.04), 0 4px 16px rgba(11,37,69,.04);
  transition:background .15s ease, transform .12s ease, box-shadow .2s ease, border-color .15s;
  min-height:84px;
}
.pp-opt:hover:not(:disabled){
  border-color:#00B4A6;
  box-shadow:0 2px 4px rgba(0,180,166,.10), 0 8px 24px rgba(11,37,69,.08);
  transform:translateY(-1px);
}
.pp-opt:active:not(:disabled){transform:scale(.98)}
.pp-opt:disabled{opacity:.55;cursor:not-allowed}
.pp-opt--primary{
  background:linear-gradient(135deg,#0F3460 0%,#0B2545 100%);
  border-color:#0B2545;color:#fff;
  box-shadow:0 2px 6px rgba(11,37,69,.18), 0 12px 28px rgba(11,37,69,.18);
}
.pp-opt--primary:hover:not(:disabled){
  border-color:#00B4A6;
  box-shadow:0 0 0 2px rgba(0,180,166,.45) inset, 0 2px 6px rgba(11,37,69,.22), 0 14px 32px rgba(11,37,69,.22);
}
.pp-opt--primary .pp-opt-d{color:rgba(255,255,255,.78)}
.pp-opt-ico{
  width:48px;height:48px;
  border-radius:12px;
  background:rgba(255,255,255,.10);
  display:flex;align-items:center;justify-content:center;
  color:currentColor;flex-shrink:0;
}
.pp-opt:not(.pp-opt--primary) .pp-opt-ico{
  background:linear-gradient(135deg,#EFF4FA,#DCE7F5);
  color:#0B2545;
}
.pp-opt-body{flex:1;min-width:0;display:flex;flex-direction:column;gap:5px}
.pp-opt-t{
  font-size:15px;font-weight:800;
  letter-spacing:-.15px;line-height:1.25;
}
.pp-opt-d{
  font-size:12.5px;color:#64748B;
  line-height:1.5;
}

.pp-err{
  padding:12px 14px;
  background:#FEF2F2;border:1px solid #FECACA;
  color:#991B1B;border-radius:10px;
  font-size:12.5px;font-weight:600;line-height:1.5;
  margin-top:4px;
}
.pp-hint{
  padding:12px 14px;
  background:#EFF6FF;border:1px solid #BFDBFE;
  color:#1E40AF;border-radius:10px;
  font-size:12.5px;font-weight:600;line-height:1.5;
  margin-top:4px;
}
.pp-progress{
  display:flex;align-items:center;gap:12px;
  padding:14px 16px;
  background:#F0FDF4;border:1px solid #BBF7D0;
  color:#065F46;border-radius:12px;
  font-size:13px;font-weight:600;line-height:1.5;
  margin-top:4px;
}
.pp-progress-spinner{
  width:18px;height:18px;
  border:2.5px solid #BBF7D0;
  border-top-color:#10B981;
  border-radius:50%;
  animation:pp-spin 1s linear infinite;
  flex-shrink:0;
}
@keyframes pp-spin{to{transform:rotate(360deg)}}

.pp-paste{
  width:100%;box-sizing:border-box;
  background:#0B2545;color:#E2E8F0;
  border:1.5px solid #0B2545;border-radius:12px;
  padding:16px;
  font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
  font-size:13px;outline:none;
  letter-spacing:.3px;line-height:1.6;
  min-height:200px;resize:vertical;
  box-shadow:inset 0 2px 4px rgba(0,0,0,.18);
}
.pp-paste::placeholder{color:rgba(226,232,240,.30)}
.pp-paste:focus{border-color:#00B4A6;box-shadow:inset 0 2px 4px rgba(0,0,0,.18), 0 0 0 3px rgba(0,180,166,.18)}

.pp-row{display:flex;gap:12px;margin-top:8px}
.pp-btn{
  flex:1;padding:16px;
  border-radius:12px;border:none;
  font-size:14.5px;font-weight:800;
  cursor:pointer;letter-spacing:.1px;
  font-family:inherit;
  transition:transform .12s, box-shadow .15s, border-color .15s;
  min-height:52px;
}
.pp-btn--primary{
  background:linear-gradient(135deg,#0F3460,#0B2545);
  color:#fff;
  box-shadow:0 2px 6px rgba(11,37,69,.18), 0 8px 22px rgba(11,37,69,.18);
}
.pp-btn--primary:hover{box-shadow:0 0 0 2px rgba(0,180,166,.45) inset, 0 2px 6px rgba(11,37,69,.22), 0 10px 26px rgba(11,37,69,.22)}
.pp-btn--ghost{
  background:#fff;color:#334155;
  border:1.5px solid #E2E8F0;
}
.pp-btn--ghost:hover{border-color:#0B2545;color:#0B2545}
.pp-btn:active{transform:scale(.98)}

.pp-card{
  background:#fff;
  border:1.5px solid #E2E8F0;
  border-radius:14px;
  padding:18px 18px 14px;
  display:flex;flex-direction:column;gap:10px;
  box-shadow:0 1px 2px rgba(11,37,69,.04), 0 6px 18px rgba(11,37,69,.05);
}
.pp-kv{
  display:flex;justify-content:space-between;align-items:baseline;
  gap:14px;padding:8px 2px;
  border-bottom:1px dashed #E2E8F0;
}
.pp-kv:last-child{border-bottom:none;padding-bottom:2px}
.pp-k{
  font-size:11px;font-weight:700;color:#94A3B8;
  text-transform:uppercase;letter-spacing:.7px;
  flex-shrink:0;
}
.pp-v{
  font-size:14.5px;color:#0B2545;
  font-weight:600;text-align:right;
  word-break:break-word;
}
.pp-v--strong{font-weight:800;font-size:15.5px}

@media (min-width:600px){
  .pp-body{padding:32px 32px 48px;gap:24px}
  .pp-opt{padding:20px;min-height:92px}
}

/* ─── Guide panel (do / don't / how / limits) ─── */
.pp-lead--cta{padding-top:8px}
.pp-guide{
  background:linear-gradient(180deg,#fff 0%, #F8FAFC 100%);
  border:1.5px solid #E2E8F0;
  border-radius:16px;
  padding:0;
  box-shadow:0 1px 2px rgba(11,37,69,.04), 0 6px 18px rgba(11,37,69,.04);
  overflow:hidden;
}
.pp-guide-sum{
  list-style:none;
  display:flex;align-items:center;gap:12px;
  padding:16px 18px;
  cursor:pointer;
  user-select:none;
  background:#fff;
  border-bottom:1px solid transparent;
  transition:background .15s, border-color .15s;
}
.pp-guide-sum::-webkit-details-marker{display:none}
.pp-guide[open] .pp-guide-sum{
  background:linear-gradient(135deg,#0F3460 0%, #0B2545 100%);
  color:#fff;
  border-bottom-color:#0B2545;
}
.pp-guide[open] .pp-guide-sum-icon{color:#00B4A6}
.pp-guide-sum-icon{
  width:28px;height:28px;
  border-radius:8px;
  background:linear-gradient(135deg,#EFF4FA,#DCE7F5);
  color:#0B2545;
  display:flex;align-items:center;justify-content:center;
  flex-shrink:0;
  transition:background .15s, color .15s;
}
.pp-guide[open] .pp-guide-sum-icon{
  background:rgba(0,180,166,.18);
}
.pp-guide-sum-t{
  flex:1;
  font-size:14px;font-weight:800;
  letter-spacing:-.1px;
}
.pp-guide-sum-chev{
  width:24px;height:24px;
  border-radius:6px;
  display:flex;align-items:center;justify-content:center;
  color:currentColor;opacity:.8;
  transition:transform .25s ease;
}
.pp-guide[open] .pp-guide-sum-chev{transform:rotate(180deg)}

.pp-guide-block{
  padding:16px 18px;
  border-top:1px solid #F1F5F9;
}
.pp-guide-block:first-of-type{border-top:none}
.pp-guide-block-h{
  display:flex;align-items:center;gap:10px;
  margin-bottom:10px;
}
.pp-guide-tag{
  font-size:10px;font-weight:900;
  letter-spacing:1.2px;
  padding:3px 8px;border-radius:6px;
  flex-shrink:0;
}
.pp-guide-tag--do    { background:#D1FAE5; color:#065F46 }
.pp-guide-tag--dont  { background:#FEE2E2; color:#991B1B }
.pp-guide-tag--how   { background:#DBEAFE; color:#1E3A8A }
.pp-guide-tag--limit { background:#FEF3C7; color:#92400E }
.pp-guide-tag--fail  { background:#EDE9FE; color:#5B21B6 }
.pp-guide-block-t{
  font-size:13px;font-weight:700;
  color:#0B2545;letter-spacing:-.05px;
}
.pp-guide-list{
  margin:0;padding:0 0 0 18px;
  display:flex;flex-direction:column;gap:7px;
  font-size:12.5px;line-height:1.55;
  color:#334155;
}
.pp-guide-list li::marker{color:#94A3B8}
.pp-guide-list--ol{padding-left:22px}
.pp-guide-list strong{color:#0B2545;font-weight:800}
.pp-guide-list em{font-style:normal;color:#0F3460;font-weight:600}
.pp-guide-list code{
  background:#EFF4FA;color:#0B2545;
  padding:1px 6px;border-radius:4px;
  font-size:11.5px;font-family:ui-monospace,Menlo,Consolas,monospace;
}
</style>