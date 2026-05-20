<template>
  <IonPage>

    <!-- ══════════════════════════════════════════════════════════════════
         HEADER — Case context, progress stepper, sync badge
    ══════════════════════════════════════════════════════════════════ -->
    <IonHeader class="sc-header" :translucent="false">
      <div class="sc-hdr-pattern" aria-hidden="true" />

      <!-- Top bar: back + title + sync badge -->
      <div class="sc-hdr-top">
        <button class="sc-back-btn" type="button" aria-label="Back to referral queue" @click="goBackToQueue">
          <svg viewBox="0 0 18 18" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2.2" stroke-linecap="round">
            <polyline points="11 4 6 9 11 14"/>
          </svg>
        </button>
        <div class="sc-title-block">
          <span class="sc-eyebrow">{{ t('Secondary Screening') }}</span>
          <div class="sc-page-title">{{ caseRecord ? t('Case in Progress') : t('Open Case') }}</div>
        </div>
        <div class="sc-hdr-right">
          <!-- Language toggle — instant switch, persists to localStorage,
               syncs across every view via the shared useI18n() composable. -->
          <LangSwitcher />

          <div class="sc-sync-pill" :class="syncPillClass" aria-live="polite" @click="isAdmin ? _debugTap() : null" style="cursor:default;margin-left:8px">
            <span class="sc-sync-dot" />
            <span class="sc-sync-txt">{{ syncPillLabel }}</span>
          </div>
        </div>
      </div>

      <!-- Case summary strip — only when case is loaded -->
      <div v-if="notification" class="sc-case-strip">
        <div class="sc-case-ic" aria-hidden="true">
          <svg viewBox="0 0 14 14" fill="none" stroke="rgba(255,255,255,.8)" stroke-width="1.5" stroke-linecap="round">
            <circle cx="7" cy="5" r="3"/><path d="M2 13c0-2.8 2.2-5 5-5s5 2.2 5 5"/>
          </svg>
        </div>
        <div class="sc-case-info">
          <div class="sc-case-name">{{ primaryScreening?.traveler_full_name || 'Anonymous Traveler' }}</div>
          <div class="sc-case-meta">
            {{ genderLabel(notification.gender ?? primaryScreening?.gender) }}
            <span v-if="primaryScreening?.temperature_value"> · {{ primaryScreening.temperature_value }}°{{ primaryScreening.temperature_unit || 'C' }}</span>
            · {{ auth.poe_code ?? '—' }}
          </div>
        </div>
        <div v-if="primaryScreening?.traveler_direction" class="sc-dir-badge" :class="'sc-dir--'+(primaryScreening.traveler_direction||'').toLowerCase()">
          {{ primaryScreening.traveler_direction }}
        </div>
        <div class="sc-prio-pill" :class="priorityClass">{{ notification.priority || 'NORMAL' }}</div>
      </div>

      <!-- 4-step progress bar -->
      <div class="sc-stepper" role="progressbar" :aria-valuenow="step" aria-valuemin="1" :aria-valuemax="STEPS.length">
        <div v-for="(s, i) in STEPS" :key="s.key" class="sc-step-wrap">
          <button
            class="sc-step"
            :class="{
              'sc-step--done':   step > i + 1,
              'sc-step--active': step === i + 1,
              'sc-step--future': step < i + 1,
            }"
            type="button"
            :aria-label="'Step ' + (i+1) + ': ' + s.label"
            @click="jumpToStep(i + 1)"
          >
            <span class="sc-step-node">
              <svg v-if="step > i + 1" viewBox="0 0 12 12" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round"><polyline points="2 6 5 9 10 3"/></svg>
              <span v-else class="sc-step-num">{{ i + 1 }}</span>
            </span>
            <span class="sc-step-lbl">{{ t(s.label) }}</span>
          </button>
          <div v-if="i < STEPS.length - 1" class="sc-step-line" :class="{ 'sc-step-line--done': step > i + 1 }" aria-hidden="true" />
        </div>
      </div>
    </IonHeader>

    <!-- ══════════════════════════════════════════════════════════════════
         CONTENT
    ══════════════════════════════════════════════════════════════════ -->
    <IonContent class="sc-content" :scrollY="true">

      <!-- LOADING -->
      <div v-if="loading" class="sc-loading" aria-live="polite" aria-busy="true">
        <div class="sc-spinner" aria-hidden="true" />
        <div class="sc-loading-txt">Loading case…</div>
      </div>

      <!-- NOT FOUND -->
      <div v-else-if="notFound" class="sc-guard sc-guard--warn" role="alert">
        <svg viewBox="0 0 20 20" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round">
          <circle cx="10" cy="10" r="8"/><line x1="10" y1="6" x2="10" y2="11"/><circle cx="10" cy="14" r=".8" fill="#fff"/>
        </svg>
        <div>
          <div class="sc-guard-title">Notification Not Found</div>
          <div class="sc-guard-sub">The referral could not be located in local storage. It may not have synced to this device yet.</div>
        </div>
      </div>

      <!-- ── WIZARD BODY ── -->
      <div v-else class="sc-body">

        <!-- POE mismatch advisory — non-blocking, supervisor override scenario -->
        <div v-if="poeMismatch" class="sc-poe-warn" role="status">
          <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
            <circle cx="7" cy="7" r="5"/><line x1="7" y1="4.5" x2="7" y2="7.5"/><circle cx="7" cy="9.5" r=".5" fill="currentColor"/>
          </svg>
          <span>This referral belongs to <strong>{{ notification?.poe_code }}</strong>. You are logged in at <strong>{{ auth.poe_code }}</strong>. Proceed only if authorised.</span>
        </div>

        <!-- ════════════════════════════════════════════════════
             STEP 1 — TRAVELER PROFILE & TRAVEL
        ════════════════════════════════════════════════════ -->
        <div v-show="step === 1">

          <!-- Section: Traveler Identity -->
          <div class="sc-section-hdr">
            <span class="sc-sec-num sc-sec-num--blue">1</span>
            <span class="sc-sec-title">{{ t('Traveler Identity') }}</span>
            <span class="sc-sec-badge sc-sec-badge--opt">Optional</span>
            <button
              type="button" class="sc-scan-btn"
              @click="openDocScan"
              aria-label="Scan passport or national ID to fill the form"
            >
              <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="3" y="7" width="18" height="13" rx="2"/><circle cx="12" cy="13.5" r="3.5"/><path d="M8 7l1.5-3h5L16 7"/>
              </svg>
              <span>Scan</span>
            </button>
          </div>

          <div class="sc-card">
            <!-- Full name -->
            <div class="sc-field-row">
              <div class="sc-field-ic">
                <svg viewBox="0 0 14 14" fill="none" stroke="#1565C0" stroke-width="1.5" stroke-linecap="round"><circle cx="7" cy="5" r="3"/><path d="M2 13c0-2.8 2.2-5 5-5s5 2.2 5 5"/></svg>
              </div>
              <div class="sc-field-body">
                <label class="sc-field-lbl" for="sc-traveler-name">{{ t('Full Name') }}
                <span class="sc-req-star">*</span></label>
                <input
                  id="sc-traveler-name" class="sc-field-input"
                  :class="{ 'sc-field-input--err': fieldErrors.traveler_full_name }"
                  type="text" maxlength="150" placeholder="Enter traveler name…"
                  v-model.trim="profile.traveler_full_name"
                  autocomplete="off"
                />
                <div v-if="fieldErrors.traveler_full_name" class="sc-field-err" role="alert">{{ fieldErrors.traveler_full_name }}</div>
              </div>
            </div>

            <!-- Gender (pre-filled, editable) -->
            <div class="sc-field-row">
              <div class="sc-field-ic">
                <svg viewBox="0 0 14 14" fill="none" stroke="#1565C0" stroke-width="1.5" stroke-linecap="round"><circle cx="7" cy="7" r="5"/><line x1="7" y1="4" x2="7" y2="10"/><line x1="4" y1="7" x2="10" y2="7"/></svg>
              </div>
              <div class="sc-field-body">
                <label class="sc-field-lbl">{{ t('Gender') }}
                <span class="sc-req-star">*</span></label>
                <div class="sc-gender-row">
                  <button
                    v-for="g in GENDERS" :key="g.value"
                    class="sc-gender-btn"
                    :class="{ 'sc-gender-btn--active': profile.traveler_gender === g.value }"
                    type="button" @click="profile.traveler_gender = g.value"
                  >{{ g.label }}</button>
                </div>
                <div v-if="fieldErrors.traveler_gender" class="sc-field-err" role="alert">{{ fieldErrors.traveler_gender }}</div>
              </div>
            </div>

            <!-- Age / Year of Birth — Request 6 -->
            <div class="sc-field-row">
              <div class="sc-field-ic">
                <svg viewBox="0 0 14 14" fill="none" stroke="#1565C0" stroke-width="1.5" stroke-linecap="round"><rect x="2" y="3" width="10" height="9" rx="2"/><line x1="5" y1="1" x2="5" y2="5"/><line x1="9" y1="1" x2="9" y2="5"/></svg>
              </div>
              <div class="sc-field-body">
                <label class="sc-field-lbl" for="sc-age">{{ t('Age (years) or Year of Birth') }}
                <span class="sc-req-star">*</span></label>
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                  <!-- Adult / older-child input: integer years -->
                  <input
                    v-if="!showInfantMonths"
                    id="sc-age" class="sc-field-input sc-field-input--short"
                    type="number" min="0" max="120" step="1" inputmode="numeric" pattern="[0-9]*"
                    placeholder="Age, e.g. 34"
                    v-model.number="profile.traveler_age_years"
                    @input="_truncIntField('traveler_age_years')"
                    @change="onAgeYearsChange"
                    @keydown="_blockDecimalKeys($event)"
                    style="width:90px"
                  />
                  <!-- Infant input: shows the month count (1–12) directly so
                       the officer never sees a confusing year-fraction
                       like "0.33". Writing here updates _age_months and
                       traveler_age_years via onAgeMonthsChange. -->
                  <template v-else>
                    <input
                      id="sc-age" class="sc-field-input sc-field-input--short"
                      type="number" min="1" max="12" step="1" inputmode="numeric" pattern="[0-9]*"
                      placeholder="Months"
                      :value="profile._age_months ?? ''"
                      @input="(e) => { const n = Number(e.target.value); if (Number.isFinite(n) && n >= 1 && n <= 12) onAgeMonthsChange(n) }"
                      @keydown="_blockDecimalKeys($event)"
                      style="width:90px"
                      aria-label="Age in months"
                    />
                    <span style="font-size:12px;color:#1565C0;font-weight:600;flex-shrink:0">months</span>
                  </template>
                  <span style="font-size:12px;color:#94A3B8;flex-shrink:0">or birth year</span>
                  <input
                    id="sc-yob" class="sc-field-input sc-field-input--short"
                    type="number" min="1900" :max="new Date().getFullYear()" step="1" inputmode="numeric" pattern="[0-9]*"
                    placeholder="e.g. 1990"
                    v-model.number="profile._birth_year"
                    @input="_truncIntField('_birth_year')"
                    @change="onBirthYearChange"
                    @keydown="_blockDecimalKeys($event)"
                    style="width:90px"
                  />
                </div>
                <div v-if="fieldErrors.traveler_age_or_dob" class="sc-field-err" role="alert">{{ fieldErrors.traveler_age_or_dob }}</div>
                <!-- Infant months popup — shown when calculated age < 1 year -->
                <div v-if="showInfantMonths" class="sc-infant-popup">
                  <span class="sc-infant-lbl">
                    Infant — select exact age in months
                    <span v-if="ageDisplayHint" style="color:#1565C0;font-weight:600;">· {{ ageDisplayHint }}</span>
                  </span>
                  <div class="sc-infant-months">
                    <button v-for="m in 12" :key="m"
                      class="sc-infant-mo"
                      :class="profile._age_months === m && 'sc-infant-mo--active'"
                      type="button"
                      :aria-pressed="profile._age_months === m"
                      @click="onAgeMonthsChange(m)">
                      {{ m }} mo
                    </button>
                  </div>
                </div>
                <div v-else-if="ageDisplayHint" class="sc-age-inline-hint">{{ ageDisplayHint }}</div>
              </div>
            </div>

            <!-- Nationality -->
            <div class="sc-field-row">
              <div class="sc-field-ic">
                <svg viewBox="0 0 14 14" fill="none" stroke="#1565C0" stroke-width="1.5" stroke-linecap="round"><circle cx="7" cy="7" r="5"/><path d="M2 7h10M7 2c-1.5 2-2 3.3-2 5s.5 3 2 5M7 2c1.5 2 2 3.3 2 5s-.5 3-2 5"/></svg>
              </div>
              <div class="sc-field-body">
                <label class="sc-field-lbl">{{ t('Nationality') }}
                <span class="sc-req-star">*</span></label>
                <SearchableSelect
                  v-model="profile.traveler_nationality_country_code"
                  :options="COUNTRY_LIST"
                  value-key="code2"
                  label-key="name"
                  placeholder="— Select country —"
                  search-placeholder="Search by name, ISO-2, ISO-3, or alias…"
                  aria-label="Nationality"
                  :select-class="fieldErrors.traveler_nationality_country_code ? 'sc-ss--err' : ''"
                />
                <div v-if="fieldErrors.traveler_nationality_country_code" class="sc-field-err" role="alert">{{ fieldErrors.traveler_nationality_country_code }}</div>
              </div>
            </div>
          </div>

          <!-- Section: Travel Document -->
          <div class="sc-section-hdr" style="margin-top:16px">
            <span class="sc-sec-num sc-sec-num--purple">2</span>
            <span class="sc-sec-title">{{ t('Travel Document') }}</span>
            <span class="sc-sec-badge sc-sec-badge--opt">Optional</span>
          </div>

          <div class="sc-card">
            <!-- Doc type -->
            <div class="sc-field-row">
              <div class="sc-field-ic">
                <svg viewBox="0 0 14 14" fill="none" stroke="#6A1B9A" stroke-width="1.5" stroke-linecap="round"><rect x="2" y="1" width="10" height="12" rx="2"/><line x1="5" y1="5" x2="9" y2="5"/><line x1="5" y1="8" x2="9" y2="8"/></svg>
              </div>
              <div class="sc-field-body">
                <label class="sc-field-lbl">{{ t('Document Type') }}</label>
                <div class="sc-chip-row">
                  <button
                    v-for="dt in DOC_TYPES" :key="dt.value"
                    class="sc-chip-btn"
                    :class="{ 'sc-chip-btn--active': profile.travel_document_type === dt.value }"
                    type="button" @click="profile.travel_document_type = dt.value"
                  >{{ dt.label }}</button>
                </div>
              </div>
            </div>

            <!-- Doc number -->
            <div class="sc-field-row sc-field-row--last">
              <div class="sc-field-ic">
                <svg viewBox="0 0 14 14" fill="none" stroke="#6A1B9A" stroke-width="1.5" stroke-linecap="round"><circle cx="7" cy="7" r="5"/><text x="4" y="10" font-size="5" fill="#6A1B9A">#</text></svg>
              </div>
              <div class="sc-field-body">
                <label class="sc-field-lbl" for="sc-docnum">{{ t('Document Number') }}</label>
                <input
                  id="sc-docnum" class="sc-field-input"
                  type="text" maxlength="60" placeholder="Passport / ID number…"
                  v-model.trim="profile.travel_document_number"
                  autocomplete="off"
                />
              </div>
            </div>
          </div>

          <!-- Section: Travel Details -->
          <div class="sc-section-hdr" style="margin-top:16px">
            <span class="sc-sec-num sc-sec-num--orange">3</span>
            <span class="sc-sec-title">{{ t('Journey Information') }}</span>
            <span class="sc-sec-badge sc-sec-badge--req">Required</span>
          </div>

          <div class="sc-card">
            <!-- Origin country -->
            <div class="sc-field-row">
              <div class="sc-field-ic">
                <svg viewBox="0 0 14 14" fill="none" stroke="#E65100" stroke-width="1.5" stroke-linecap="round"><path d="M2 12L5 2l4 8 3-4 2 6"/></svg>
              </div>
              <div class="sc-field-body">
                <label class="sc-field-lbl">{{ t('Journey Origin Country') }}</label>
                <SearchableSelect
                  v-model="profile.journey_start_country_code"
                  :options="COUNTRY_LIST"
                  value-key="code2"
                  label-key="name"
                  placeholder="— Where did journey begin? —"
                  search-placeholder="Search countries…"
                  aria-label="Journey origin country"
                />
              </div>
            </div>

            <!-- Conveyance type -->
            <div class="sc-field-row">
              <div class="sc-field-ic">
                <svg viewBox="0 0 14 14" fill="none" stroke="#E65100" stroke-width="1.5" stroke-linecap="round"><path d="M1 11h12M3 11V7l4-5 4 5v4"/></svg>
              </div>
              <div class="sc-field-body">
                <label class="sc-field-lbl">{{ t('Mode of Transport') }}</label>
                <div class="sc-chip-row">
                  <button
                    v-for="ct in CONVEYANCE_TYPES" :key="ct.value"
                    class="sc-chip-btn"
                    :class="{ 'sc-chip-btn--active': profile.conveyance_type === ct.value }"
                    type="button" @click="profile.conveyance_type = ct.value"
                  >{{ ct.label }}</button>
                </div>
              </div>
            </div>

            <!-- Flight/vessel ID (only for AIR/SEA) -->
            <div v-if="profile.conveyance_type === 'AIR' || profile.conveyance_type === 'SEA'" class="sc-field-row">
              <div class="sc-field-ic">
                <svg viewBox="0 0 14 14" fill="none" stroke="#E65100" stroke-width="1.5" stroke-linecap="round"><line x1="2" y1="7" x2="12" y2="7"/><line x1="9" y1="4" x2="12" y2="7"/><line x1="9" y1="10" x2="12" y2="7"/></svg>
              </div>
              <div class="sc-field-body">
                <label class="sc-field-lbl" for="sc-convid">
                  {{ profile.conveyance_type === 'AIR' ? 'Flight Number' : 'Vessel Name' }}
                </label>
                <input
                  id="sc-convid" class="sc-field-input"
                  type="text" maxlength="80"
                  :placeholder="profile.conveyance_type === 'AIR' ? 'e.g. KQ101' : 'e.g. MV Victoria'"
                  v-model.trim="profile.conveyance_identifier"
                />
              </div>
            </div>

            <!-- Arrival datetime -->
            <div class="sc-field-row sc-field-row--last">
              <div class="sc-field-ic">
                <svg viewBox="0 0 14 14" fill="none" stroke="#E65100" stroke-width="1.5" stroke-linecap="round"><circle cx="7" cy="7" r="5"/><polyline points="7 4 7 7 9 9"/></svg>
              </div>
              <div class="sc-field-body">
                <label class="sc-field-lbl" for="sc-arrival">{{ t('Arrival Date/Time') }}</label>
                <input
                  id="sc-arrival" class="sc-field-input"
                  type="datetime-local"
                  v-model="profile.arrival_datetime_input"
                />
              </div>
            </div>
          </div>

          <!-- Section: Countries Visited (21-day IHR lookback) -->
          <div class="sc-section-hdr" style="margin-top:16px">
            <span class="sc-sec-num sc-sec-num--green">4</span>
            <span class="sc-sec-title">{{ t('Countries Visited (Last 21 Days)') }}</span>
            <span class="sc-sec-badge sc-sec-badge--req">IHR Lookback</span>
          </div>

          <div v-if="travelCountries.length === 0" class="sc-empty-travel">
            <svg viewBox="0 0 20 20" fill="none" stroke="#B0BEC5" stroke-width="1.4" stroke-linecap="round"><circle cx="10" cy="10" r="8"/><path d="M2 10h16M10 2c-2 2.5-3 5-3 8s1 5.5 3 8M10 2c2 2.5 3 5 3 8s-1 5.5-3 8"/></svg>
            <span>No countries added yet</span>
          </div>

          <div v-for="(tc, idx) in travelCountries" :key="idx" class="sc-tc-row">
            <div class="sc-tc-country">
              <SearchableSelect
                v-model="tc.country_code"
                :options="COUNTRY_LIST"
                value-key="code2"
                label-key="name"
                placeholder="— Country —"
                search-placeholder="Search by name, ISO-2, ISO-3…"
                :aria-label="'Country ' + (idx + 1)"
              />
            </div>
            <select class="sc-tc-role" v-model="tc.travel_role" :aria-label="'Role for country ' + (idx+1)">
              <option value="VISITED">Visited</option>
              <option value="TRANSIT">Transit</option>
            </select>
            <button class="sc-tc-remove" type="button" :aria-label="'Remove country ' + (idx+1)" @click="removeTravelCountry(idx)">
              <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="2" y1="2" x2="10" y2="10"/><line x1="10" y1="2" x2="2" y2="10"/></svg>
            </button>
          </div>

          <button class="sc-add-country-btn" type="button" @click="addTravelCountry">
            <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="7" y1="2" x2="7" y2="12"/><line x1="2" y1="7" x2="12" y2="7"/></svg>
            Add Country
          </button>

          <!-- Contact info -->
          <div class="sc-section-hdr" style="margin-top:16px">
            <span class="sc-sec-num sc-sec-num--blue">5</span>
            <span class="sc-sec-title">{{ t('Contact Information') }}</span>
            <span class="sc-sec-badge sc-sec-badge--opt">Optional</span>
          </div>

          <div class="sc-card">
            <div class="sc-field-row">
              <div class="sc-field-ic">
                <svg viewBox="0 0 14 14" fill="none" stroke="#1565C0" stroke-width="1.5" stroke-linecap="round"><path d="M2 2h3l1.5 3.5L5 7s1 2 4 4l1.5-1.5L14 11v3s-1.2 1-3 0C5 11 2 5 2 2z"/></svg>
              </div>
              <div class="sc-field-body">
                <label class="sc-field-lbl" for="sc-phone">{{ t('Phone Number') }}</label>
                <input
                  id="sc-phone" class="sc-field-input"
                  type="tel" maxlength="40" placeholder="e.g. 25678927376"
                  v-model.trim="profile.phone_number"
                />
              </div>
            </div>
            <div class="sc-field-row sc-field-row--last">
              <div class="sc-field-ic">
                <svg viewBox="0 0 14 14" fill="none" stroke="#1565C0" stroke-width="1.5" stroke-linecap="round"><path d="M7 1C4.2 1 2 3.2 2 6c0 4 5 7 5 7s5-3 5-7c0-2.8-2.2-5-5-5z"/><circle cx="7" cy="6" r="1.5"/></svg>
              </div>
              <div class="sc-field-body">
                <label class="sc-field-lbl">{{ t('Destination District in Uganda') }}</label>
                <!-- Native <select> — reliable across every Android WebView,
                     never gets stuck rendering the panel, and shows ALL 30
                     Uganda districts grouped by PHEOC. SearchableSelect was
                     showing a single option because of a teleport / z-index
                     race; native is the correct fit for a fixed 30-item list. -->
                <select
                  class="sc-field-select"
                  v-model="profile.destination_district_code"
                  aria-label="Destination district in Uganda"
                >
                  <option value="">— Select destination district —</option>
                  <optgroup v-for="grp in DESTINATION_DISTRICT_GROUPS" :key="grp.label" :label="grp.label">
                    <option v-for="d in grp.districts" :key="d" :value="d">{{ d }}</option>
                  </optgroup>
                </select>
                <div v-if="autoDistrictApplied && profile.destination_district_code" class="sc-auto-district-hint">
                  <span>Auto-filled from POE hierarchy.</span>
                  <button type="button" class="sc-auto-district-change" @click="profile.destination_district_code = ''; autoDistrictApplied = false">
                    Change district
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div style="height:8px"/>
        </div>
        <!-- /STEP 1 -->


        <!-- ════════════════════════════════════════════════════
             STEP 2 — SYMPTOMS
        ════════════════════════════════════════════════════ -->
        <div v-show="step === 2">

          <!-- Header + count -->
          <div class="sc-section-hdr">
            <span class="sc-sec-num sc-sec-num--orange">S</span>
            <span class="sc-sec-title">{{ t('Symptom Checklist') }}</span>
            <span class="sc-sym-count" aria-live="polite">
              {{ presentSymptomCount }} present
            </span>
          </div>

          <div class="sc-sym-intro">
            Tap each symptom the traveler is currently experiencing. Unknown or absent symptoms should remain off.
          </div>

          <!-- ── Vital Sign: Temperature (Task 1, executive policy 2026-05-05) ── -->
          <div class="sc-vital-section" role="region" aria-label="Temperature reading">
            <div class="sc-vital-hdr">
              <span class="sc-vital-num">V</span>
              <span class="sc-vital-title">{{ t('Vital Sign — Temperature') }}</span>
              <span class="sc-vital-badge">{{ t('Secondary assessment') }}</span>
            </div>
            <div class="sc-vital-row">
              <label class="sc-vital-lbl" for="sc-temp-secondary">{{ t('Temperature (°C)') }}</label>
              <input
                id="sc-temp-secondary"
                class="sc-vital-input"
                type="number" step="0.1" min="25" max="45"
                placeholder="e.g. 38.2"
                v-model.number="vitals.temperature_value"
              />
            </div>
            <!-- 2026-05-06: TWO locked fever readouts (Fever + High Fever),
                 auto-derived in XOR from temperature. 37.5–38.9 → Fever;
                 ≥39.0 → High Fever. Officer cannot tick manually. -->
            <div class="sc-vital-fever-readout" :class="{ 'sc-vital-fever-readout--on': autoFeverIsPresent === 1, 'sc-vital-fever-readout--off': autoFeverIsPresent === 0, 'sc-vital-fever-readout--unset': autoFeverIsPresent === null }">
              <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
                <rect x="3.5" y="2" width="7" height="9" rx="1.5"/>
                <path d="M5.5 4.5h3M5.5 6.5h3M5.5 8.5h3"/>
              </svg>
              <div class="sc-vital-fever-body">
                <span class="sc-vital-fever-label">{{ t('Fever') }} <span class="sc-vital-fever-band">37.5 – 38.9 °C</span></span>
                <span class="sc-vital-fever-state">
                  {{ autoFeverIsPresent === 1 ? t('YES') : autoFeverIsPresent === 0 ? t('NO') : t('Unset') }}
                </span>
              </div>
              <span class="sc-vital-fever-lock" aria-hidden="true">
                <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                  <rect x="3" y="6" width="6" height="5" rx="1"/>
                  <path d="M4.5 6V4a1.5 1.5 0 013 0v2"/>
                </svg>
                {{ t('Auto-set from temperature') }}
              </span>
            </div>
            <div class="sc-vital-fever-readout" :class="{ 'sc-vital-fever-readout--on': autoHighFeverIsPresent === 1, 'sc-vital-fever-readout--off': autoHighFeverIsPresent === 0, 'sc-vital-fever-readout--unset': autoHighFeverIsPresent === null }">
              <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" aria-hidden="true">
                <rect x="3.5" y="2" width="7" height="9" rx="1.5"/>
                <path d="M5.5 4.5h3M5.5 6.5h3M5.5 8.5h3"/>
                <path d="M2 1l1.5 1.5M12 1l-1.5 1.5"/>
              </svg>
              <div class="sc-vital-fever-body">
                <span class="sc-vital-fever-label">{{ t('High Fever') }} <span class="sc-vital-fever-band">≥ 39.0 °C</span></span>
                <span class="sc-vital-fever-state">
                  {{ autoHighFeverIsPresent === 1 ? t('YES') : autoHighFeverIsPresent === 0 ? t('NO') : t('Unset') }}
                </span>
              </div>
              <span class="sc-vital-fever-lock" aria-hidden="true">
                <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round">
                  <rect x="3" y="6" width="6" height="5" rx="1"/>
                  <path d="M4.5 6V4a1.5 1.5 0 013 0v2"/>
                </svg>
                {{ t('Auto-set from temperature') }}
              </span>
            </div>
          </div>

          <!-- Symptom groups -->
          <div v-for="grp in SYMPTOM_GROUPS" :key="grp.key" class="sc-sym-group">
            <div class="sc-sym-group-hdr">
              <span class="sc-sym-group-dot" :style="{ background: grp.color }" aria-hidden="true" />
              {{ grp.label }}
            </div>
            <div class="sc-sym-grid">
              <div v-for="sym in grp.symptoms.filter(s => s.code !== 'fever')" :key="sym.code" class="sc-sym-card-wrap">
                <button
                  class="sc-sym-card"
                  :class="{ 'sc-sym-card--on': symState(sym.code) === 1, 'sc-sym-card--off': symState(sym.code) === 0 }"
                  type="button"
                  :aria-pressed="symState(sym.code) === 1"
                  :aria-label="sym.label"
                  @click="toggleSymptom(sym.code)"
                >
                  <span class="sc-sym-indicator" aria-hidden="true">
                    <svg v-if="symState(sym.code) === 1" viewBox="0 0 10 10" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round"><polyline points="1 5 4 8 9 2"/></svg>
                  </span>
                  <span class="sc-sym-name" :class="{ 'sc-sym-name--on': symState(sym.code) === 1 }">{{ sym.label }}</span>
                </button>
                <button
                  class="sc-sym-info-btn"
                  type="button"
                  :aria-label="'More information about ' + sym.label + ' (WHO definition)'"
                  @click.stop="openSymptomInfo(sym)"
                >
                  <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
                    <circle cx="7" cy="7" r="5.5"/><line x1="7" y1="6" x2="7" y2="9.5"/><circle cx="7" cy="4.5" r=".5" fill="currentColor"/>
                  </svg>
                </button>
              </div>
            </div>

            <!-- Onset date — OPTIONAL. The input appears for symptoms whose
                 catalog entry sets requiresOnset:true so the officer can
                 record onset if the traveller volunteers it, but no save
                 path validates it; an empty value persists as null. -->
            <div v-for="sym in grp.symptoms.filter(s => s.requiresOnset && symState(s.code) === 1)" :key="'onset-' + sym.code" class="sc-onset-row">
              <div class="sc-onset-ic" aria-hidden="true">
                <svg viewBox="0 0 12 12" fill="none" stroke="#E65100" stroke-width="1.5" stroke-linecap="round"><circle cx="6" cy="6" r="4"/><polyline points="6 3.5 6 6 7.5 7.5"/></svg>
              </div>
              <div class="sc-onset-body">
                <label class="sc-onset-lbl" :for="'onset-' + sym.code">{{ sym.label }} — onset date <span class="sc-onset-opt">(optional)</span></label>
                <input
                  :id="'onset-' + sym.code"
                  class="sc-onset-input"
                  type="date"
                  :max="todayDate"
                  v-model="getSymptomRecord(sym.code).onset_date"
                />
              </div>
            </div>
          </div>

          <!-- ── Optional Clinical Data (toggleable) ── -->
          <div class="sc-vitals-toggle-hdr" @click="showVitals = !showVitals" role="button" :aria-expanded="showVitals">
            <div class="sc-vitals-toggle-left">
              <div class="sc-vitals-toggle-ic" aria-hidden="true">
                <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M2 8h2l2-6 3 10 2-6 1 2h2"/></svg>
              </div>
              <span class="sc-vitals-toggle-lbl">Clinical Vitals &amp; Triage</span>
              <span class="sc-vitals-badge">Optional</span>
            </div>
            <svg class="sc-vitals-chevron" :class="{ 'sc-vitals-chevron--open': showVitals }" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
              <polyline points="2 4 6 8 10 4"/>
            </svg>
          </div>

          <div v-show="showVitals" class="sc-vitals-panel">
            <div class="sc-vitals-note">Complete only if measurement equipment is available at this POE. Temperature is captured at the top of this step (Vital Sign) and feeds the auto-derived Fever signal.</div>

            <!-- Pulse + RR -->
            <div class="sc-vt-pair">
              <div class="sc-vt-row sc-vt-row--half">
                <label class="sc-vt-lbl">Pulse (bpm)</label>
                <input class="sc-vt-num" type="number" min="20" max="250" placeholder="e.g. 88" v-model.number="vitals.pulse_rate" />
                <span v-if="pulseWarning" class="sc-vt-warn sc-vt-warn--sm" role="alert">{{ pulseWarning }}</span>
              </div>
              <div class="sc-vt-row sc-vt-row--half">
                <label class="sc-vt-lbl">Resp. Rate (/min)</label>
                <input class="sc-vt-num" type="number" min="5" max="60" placeholder="e.g. 18" v-model.number="vitals.respiratory_rate" />
                <span v-if="rrWarning" class="sc-vt-warn sc-vt-warn--sm" role="alert">{{ rrWarning }}</span>
              </div>
            </div>

            <!-- BP -->
            <div class="sc-vt-pair">
              <div class="sc-vt-row sc-vt-row--half">
                <label class="sc-vt-lbl">BP Systolic (mmHg)</label>
                <input class="sc-vt-num" type="number" min="40" max="300" placeholder="e.g. 120" v-model.number="vitals.bp_systolic" />
              </div>
              <div class="sc-vt-row sc-vt-row--half">
                <label class="sc-vt-lbl">BP Diastolic</label>
                <input class="sc-vt-num" type="number" min="20" max="200" placeholder="e.g. 80" v-model.number="vitals.bp_diastolic" />
              </div>
            </div>

            <!-- SpO2 -->
            <div class="sc-vt-row">
              <label class="sc-vt-lbl">SpO₂ (%)</label>
              <input class="sc-vt-num sc-vt-num--short" type="number" min="50" max="100" step="0.5" placeholder="e.g. 97.5" v-model.number="vitals.oxygen_saturation" />
              <span v-if="spo2Warning" class="sc-vt-warn" :class="spo2WarnClass" role="alert">{{ spo2Warning }}</span>
            </div>

            <!-- Triage category -->
            <div class="sc-vt-row">
              <label class="sc-vt-lbl">Triage Category</label>
              <div class="sc-triage-row">
                <button
                  v-for="tr in TRIAGE_CATS" :key="tr.value"
                  class="sc-triage-btn"
                  :class="['sc-triage-btn--' + tr.value.toLowerCase(), vitals.triage_category === tr.value && 'sc-triage-btn--active']"
                  type="button"
                  @click="vitals.triage_category = tr.value"
                >
                  <span class="sc-triage-lbl">{{ tr.label }}</span>
                  <span class="sc-triage-sub">{{ tr.sub }}</span>
                </button>
              </div>
            </div>

            <!-- Emergency signs + General appearance -->
            <div class="sc-vt-pair">
              <div class="sc-vt-row sc-vt-row--half">
                <label class="sc-vt-lbl">Emergency Signs</label>
                <div class="sc-bool-row">
                  <button class="sc-bool-btn" :class="{ 'sc-bool-btn--yes': vitals.emergency_signs_present === 1 }" type="button" @click="vitals.emergency_signs_present = vitals.emergency_signs_present === 1 ? 0 : 1">{{ vitals.emergency_signs_present === 1 ? 'YES ✓' : 'No' }}</button>
                </div>
                <span v-if="vitals.emergency_signs_present === 1" class="sc-vt-warn sc-vt-warn--sm sc-vt-warn--crit">Requires EMERGENCY triage</span>
              </div>
              <div class="sc-vt-row sc-vt-row--half">
                <label class="sc-vt-lbl">General Appearance</label>
                <select class="sc-field-select" v-model="vitals.general_appearance" aria-label="General appearance">
                  <option value="">— Select —</option>
                  <option value="WELL">Well</option>
                  <option value="UNWELL">Unwell</option>
                  <option value="SEVERELY_ILL">Severely Ill</option>
                </select>
              </div>
            </div>

          </div>
          <!-- /vitals panel -->

          <div style="height:8px"/>
        </div>
        <!-- /STEP 2 -->


        <!-- ════════════════════════════════════════════════════
             STEP 3 — STRUCTURED EXPOSURE QUESTIONNAIRE
        ════════════════════════════════════════════════════ -->
        <div v-show="step === 3">

          <div class="sc-section-hdr">
            <span class="sc-sec-num sc-sec-num--red">E</span>
            <span class="sc-sec-title">{{ t('Structured Exposure Questionnaire') }}</span>
          </div>

          <div class="sc-exposure-intro">
            Ask the traveler each question. Select the most accurate response.
            <strong>Unknown</strong> is the default — only change to Yes or No when certain.
          </div>

          <!-- HIGH-RISK signal banner -->
          <div v-if="highRiskSignals.length > 0" class="sc-exp-hisig" role="alert" aria-live="polite">
            <svg viewBox="0 0 14 14" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round"><path d="M7 1L1 12h12L7 1z"/><line x1="7" y1="5" x2="7" y2="8.5"/><circle cx="7" cy="10.5" r=".6" fill="#fff"/></svg>
            <div>
              <div class="sc-exp-hisig-title">{{ highRiskSignals.length }} HIGH-RISK Exposure{{ highRiskSignals.length > 1 ? 's' : '' }} Confirmed</div>
              <div v-for="sig in highRiskSignals" :key="sig.code" class="sc-exp-hisig-row">
                <span class="sc-exp-hisig-lbl">{{ sig.label }}</span>
                <span v-if="sig.critical_message" class="sc-exp-hisig-note">{{ sig.critical_message }}</span>
              </div>
            </div>
          </div>

          <!-- Exposures grouped by category from window.EXPOSURES -->
          <div v-for="cat in exposureCategoryKeys" :key="cat" class="sc-exp-cat">
            <div class="sc-exp-cat-hdr">{{ t(EXPOSURE_CATEGORY_LABELS[cat] || cat, EXPOSURE_CATEGORY_LABELS[cat] || cat) }}</div>
            <div v-for="exp in exposuresByCategory[cat]" :key="exp.code" class="sc-exp-card"
              :class="{ 'sc-exp-card--yes': exposuresMap[exp.code]?.response === 'YES', 'sc-exp-card--high': exp.risk_level === 'VERY_HIGH' || exp.risk_level === 'HIGH' }">
              <div class="sc-exp-body">
                <div class="sc-exp-header-row">
                  <p class="sc-exp-question">{{ t(exp.code, exp.label) }}</p>
                  <span v-if="exp.risk_level === 'VERY_HIGH'" class="sc-exp-risk sc-exp-risk--vhigh">VERY HIGH RISK</span>
                  <span v-else-if="exp.risk_level === 'HIGH'" class="sc-exp-risk sc-exp-risk--high">HIGH RISK</span>
                </div>
                <p class="sc-exp-desc">{{ exp.description }}</p>
                <div class="sc-exp-btns" role="group" :aria-label="'Answer for: ' + exp.label">
                  <button class="sc-exp-btn sc-exp-btn--yes" :class="{ 'sc-exp-btn--active': exposuresMap[exp.code]?.response === 'YES' }"
                    type="button" @click="setExposureResponse(exp.code, 'YES')" :aria-pressed="exposuresMap[exp.code]?.response === 'YES'">Yes</button>
                  <button class="sc-exp-btn sc-exp-btn--no"  :class="{ 'sc-exp-btn--active': exposuresMap[exp.code]?.response === 'NO' }"
                    type="button" @click="setExposureResponse(exp.code, 'NO')"  :aria-pressed="exposuresMap[exp.code]?.response === 'NO'">No</button>
                  <button class="sc-exp-btn sc-exp-btn--unk" :class="{ 'sc-exp-btn--active': exposuresMap[exp.code]?.response === 'UNKNOWN' }"
                    type="button" @click="setExposureResponse(exp.code, 'UNKNOWN')">Unknown</button>
                </div>
                <!-- Critical flag warning when YES -->
                <div v-if="exp.critical_flag && exposuresMap[exp.code]?.response === 'YES'" class="sc-exp-critical" role="alert">
                  ⚠ {{ exp.critical_message }}
                </div>
              </div>
            </div>
          </div>

          <!-- Summary -->
          <div v-if="yesExposureCount > 0" class="sc-exp-summary" role="status" aria-live="polite">
            <svg viewBox="0 0 14 14" fill="none" stroke="#E65100" stroke-width="1.5" stroke-linecap="round"><path d="M7 1L1 12h12L7 1z"/><line x1="7" y1="5.5" x2="7" y2="8.5"/><circle cx="7" cy="10.5" r=".6" fill="#E65100"/></svg>
            <span><strong>{{ yesExposureCount }}</strong> exposure{{ yesExposureCount > 1 ? 's' : '' }} confirmed YES — {{ engineExposureCodes.length }} engine signals activated</span>
          </div>

          <!-- ── IDSR Annex 1A "Also" cluster events at this POE today ──
               Hidden by user request 2026-05-19. clusterFlags is still in the
               reactive model with safe-default `false` values, so the wire
               payload (lines ~3552, ~4540) still carries the same keys and
               the DB columns receive their NOT NULL defaults. NEVER remove
               the clusterFlags reactive object — the buildPayload code paths
               depend on its presence. -->
          <ion-card v-if="false" class="sc-card">
            <ion-card-header>
              <ion-card-title class="sc-card-title">Unusual events at this POE today</ion-card-title>
            </ion-card-header>
            <ion-card-content>
              <ion-item lines="full">
                <ion-label class="ion-text-wrap">
                  A cluster of unexplained deaths in the community / animals / birds
                </ion-label>
                <ion-toggle slot="end" v-model="clusterFlags.cluster_deaths_in_community" aria-label="Cluster of deaths" />
              </ion-item>
              <ion-item lines="full">
                <ion-label class="ion-text-wrap">
                  A cluster of unwell people / animals / birds with similar symptoms
                </ion-label>
                <ion-toggle slot="end" v-model="clusterFlags.cluster_similar_illness" aria-label="Cluster of similar symptoms" />
              </ion-item>
              <ion-item lines="none">
                <ion-label class="ion-text-wrap">
                  Any other unusual public health event
                </ion-label>
                <ion-toggle slot="end" v-model="clusterFlags.unusual_event_flag" aria-label="Unusual public health event" />
              </ion-item>
            </ion-card-content>
          </ion-card>

          <div style="height:8px"/>
        </div>
        <!-- /STEP 3 -->


        <!-- ════════════════════════════════════════════════════
             STEP 4 — CLINICAL ANALYSIS & DISPOSITION
        ════════════════════════════════════════════════════ -->
        <div v-show="step === 4">

          <!-- ── Analysis Results ── -->
          <div class="sc-section-hdr">
            <span class="sc-sec-num sc-sec-num--red">A</span>
            <span class="sc-sec-title">{{ t('Disease Intelligence Analysis') }}</span>
            <span class="sc-sec-badge sc-sec-badge--warn">AI-Assisted</span>
          </div>

          <!-- Insufficient data warning -->
          <div v-if="analysisResult && analysisResult.global_flags.includes('INSUFFICIENT_DATA')" class="sc-insuff-warn" role="alert">
            <svg viewBox="0 0 14 14" fill="none" stroke="#E65100" stroke-width="1.5" stroke-linecap="round"><circle cx="7" cy="7" r="5"/><line x1="7" y1="4" x2="7" y2="7.5"/><circle cx="7" cy="9.5" r=".5" fill="#E65100"/></svg>
            <span>Insufficient data — fewer than 2 symptoms confirmed. Analysis confidence is very low. Gather more clinical information.</span>
          </div>

          <!-- ══ SYSTEM RECOMMENDATION — shown first so the officer sees
               guidance BEFORE making any decision (Request — Analysis tab) ══ -->
          <div v-if="analysisResult && !analysisResult.is_non_case" class="sc-sysrec-banner">
            <div class="sc-sysrec-hdr">
              <svg viewBox="0 0 14 14" fill="none" stroke="#1565C0" stroke-width="1.6" stroke-linecap="round" style="width:14px;height:14px;flex-shrink:0"><circle cx="7" cy="7" r="5.5"/><line x1="7" y1="5" x2="7" y2="7.5"/><circle cx="7" cy="9.5" r=".6" fill="#1565C0"/></svg>
              <span class="sc-sysrec-title">System Recommendation (IHR Algorithm)</span>
            </div>
            <div class="sc-sysrec-grid">
              <div v-if="analysisResult.ihr_risk" class="sc-sysrec-item">
                <span class="sc-sysrec-k">Risk level</span>
                <span class="sc-sysrec-v sc-sysrec-v--risk" :data-risk="analysisResult.ihr_risk.risk_level">{{ analysisResult.ihr_risk.risk_level }}</span>
              </div>
              <!-- 2026-05-07 — Primary route is ALWAYS the District health
                   office. PHEOC and National authorities are informed via
                   the standard escalation ladder; the engine's per-disease
                   routing_level is recorded internally for analytics but
                   the operator sees a single deterministic answer. -->
              <div v-if="analysisResult.ihr_risk" class="sc-sysrec-item">
                <span class="sc-sysrec-k">Route to</span>
                <span class="sc-sysrec-v">District <span class="sc-sysrec-aux">· PHEOC + National also informed</span></span>
              </div>
              <div v-if="analysisResult.syndrome?.syndrome" class="sc-sysrec-item">
                <span class="sc-sysrec-k">Syndrome</span>
                <span class="sc-sysrec-v">{{ syndromeNameFromCode(analysisResult.syndrome.syndrome) }}</span>
              </div>
              <div v-if="analysisResult.top_diagnoses?.[0]" class="sc-sysrec-item">
                <span class="sc-sysrec-k">Top suspect</span>
                <span class="sc-sysrec-v">{{ diseaseDisplayName(analysisResult.top_diagnoses[0].disease_id) }}</span>
              </div>
            </div>
            <!-- IHR notification banner removed from suspected-case analysis.
                 Per directive 2026-05-05: IHR notification is only required
                 once a case is CONFIRMED, not while it is SUSPECTED. The
                 confirmed-case path raises this from the war-room/case-file
                 once disposition_outcome=CONFIRMED. -->
          </div>

          <!-- VHF / critical override banners -->
          <div
            v-for="flag in criticalFlags"
            :key="flag"
            class="sc-flag-banner"
            role="alert"
            aria-live="assertive"
          >
            <svg viewBox="0 0 14 14" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round"><path d="M7 1L1 12h12L7 1z"/><line x1="7" y1="5" x2="7" y2="8.5"/><circle cx="7" cy="10.5" r=".7" fill="#fff"/></svg>
            <span class="sc-flag-txt">{{ FLAG_MESSAGES[flag] || flag }}</span>
          </div>

          <!-- NON-CASE VERDICT BANNER -->
          <div v-if="analysisResult?.is_non_case" class="sc-noncase-banner" role="alert">
            <div class="sc-nc-hdr">
              <svg viewBox="0 0 16 16" fill="none" stroke="#00C853" stroke-width="1.8" stroke-linecap="round"><circle cx="8" cy="8" r="6"/><polyline points="5 8 7 10 11 6"/></svg>
              <span>NON-CASE — No Clinical Indicators</span>
            </div>
            <div v-for="reason in analysisResult.non_case.reasons" :key="reason" class="sc-nc-reason">{{ reason }}</div>
            <div class="sc-nc-action">Recommended: Syndrome = NONE · Disposition = RELEASED</div>
            <button class="sc-nc-override-btn" type="button" @click="officerOverride.overrideNonCase = !officerOverride.overrideNonCase">
              {{ officerOverride.overrideNonCase ? '✓ Override Active' : 'Officer Override — I disagree' }}
            </button>
            <div v-if="officerOverride.overrideNonCase" class="sc-nc-override-note">
              <span class="sc-nc-override-lbl">Mandatory: Document clinical justification for overriding the non-case classification:</span>
              <textarea class="sc-override-input" rows="2" v-model="officerOverride.overrideNote" placeholder="e.g. Traveller has rash not captured in symptom list. Clinical assessment suggests infectious illness…" />
            </div>
          </div>

          <!-- ENDEMIC ZONES — compact, deduped (2026-05-07).
               Engine often emits multiple disease IDs that share a single
               display name (ebola/marburg/RVF all "Viral Haemorrhagic
               Fever"). The Set in endemicZonesCompact dedupes by display
               name so the chip list reads cleanly. -->
          <div v-if="endemicZonesCompact.length > 0 && !analysisResult?.is_non_case" class="sc-outbreak-ctx">
            <div class="sc-oc-hdr">
              <svg viewBox="0 0 14 14" fill="none" stroke="#FF6D00" stroke-width="1.5" stroke-linecap="round"><circle cx="7" cy="7" r="5"/><line x1="7" y1="4" x2="7" y2="7.5"/><circle cx="7" cy="9.5" r=".5" fill="#FF6D00"/></svg>
              <span>{{ endemicZonesCompact.length }} endemic zone{{ endemicZonesCompact.length === 1 ? '' : 's' }} matched</span>
            </div>
            <div class="sc-oc-chips">
              <span v-for="z in endemicZonesCompact.slice(0,6)" :key="z" class="sc-oc-chip">{{ z }}</span>
              <span v-if="endemicZonesCompact.length > 6" class="sc-oc-chip sc-oc-chip--more">+{{ endemicZonesCompact.length - 6 }}</span>
            </div>
          </div>

          <!-- IHR RISK ASSESSMENT from engine -->
          <!-- 1-K: "IHR NOTIFICATION REQUIRED" badge removed here — it already
               appears once in the sc-sysrec-alert banner above (line 692).
               Showing it again here was the redundancy the operator reported. -->
          <div v-if="analysisResult?.ihr_risk && !analysisResult?.is_non_case" class="sc-ihr-result" :class="'sc-ihr--'+analysisResult.ihr_risk.risk_level.toLowerCase()">
            <div class="sc-ihr-hdr">
              <span class="sc-ihr-level">{{ analysisResult.ihr_risk.risk_level }}</span>
              <span class="sc-ihr-routing" v-if="analysisResult.ihr_risk">→ District <span class="sc-ihr-routing-aux">(PHEOC + National also informed)</span></span>
              <span v-if="analysisResult.ihr_risk.ihr_tier" class="sc-ihr-tier-badge">{{ analysisResult.ihr_risk.ihr_tier.replace(/_/g,' ') }}</span>
            </div>
            <div v-for="r in filteredIhrReasoning" :key="r" class="sc-ihr-reason">{{ r }}</div>
          </div>

          <!-- Top disease cards. Each card has an ⓘ button that opens
               the full case-definition + score-breakdown modal (court-
               defensible: shows the exact symptom / exposure / hallmark
               weights the engine summed to reach the final score). -->
          <div v-if="analysisResult && analysisResult.top_diagnoses.length > 0 && !analysisResult.is_non_case" class="sc-disease-list">
            <div v-for="(d, idx) in analysisResult.top_diagnoses.slice(0, 5)" :key="d.disease_id"
              class="sc-disease-card" :class="'sc-disease-card--' + (d.confidence_band || 'low')"
              @click="openDiseaseModal(d)" role="button" tabindex="0" style="cursor:pointer;">
              <div class="sc-dc-rank" :class="idx === 0 ? 'sc-dc-rank--top' : ''">{{ idx + 1 }}</div>
              <div class="sc-dc-body">
                <div class="sc-dc-name-row">
                  <span class="sc-dc-name">{{ diseaseDisplayName(d.disease_id) }}</span>
                  <span v-if="d.syndrome_matched" class="sc-dc-syn-match">{{ d.syndrome_matched }} ✓</span>
                </div>
                <div class="sc-dc-meta">
                  <span class="sc-dc-score">{{ d.final_score }} pts</span>
                  <span class="sc-dc-band" :class="'sc-dc-band--' + (d.confidence_band || 'low')">{{ d.confidence_band }}</span>
                  <span v-if="d.cfr_pct" class="sc-dc-cfr">CFR {{ d.cfr_pct }}%</span>
                </div>
                <div v-if="d.matched_hallmarks.length > 0" class="sc-dc-hallmarks">
                  <span v-for="h in d.matched_hallmarks" :key="h" class="sc-dc-htag">{{ h.replace(/_/g,' ') }}</span>
                </div>
              </div><!-- /sc-dc-body -->
              <div class="sc-dc-right">
                <!-- ⓘ — opens the score-breakdown modal. Stops propagation
                     so the wider card click doesn't fire twice. Uses the
                     same modal as the card-tap (it already shows score
                     breakdown + matched symptoms / exposures / hallmarks). -->
                <button class="sc-dc-info" type="button"
                        @click.stop="openDiseaseModal(d)"
                        :aria-label="'How was the ' + diseaseDisplayName(d.disease_id) + ' score calculated?'">
                  <svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.0" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="8" cy="8" r="6.5"/>
                    <line x1="8" y1="11" x2="8" y2="7"/>
                    <line x1="8" y1="5" x2="8" y2="5.01"/>
                  </svg>
                </button>
                <div class="sc-dc-pct" :class="'sc-dc-pct--' + (d.confidence_band || 'low')">
                  {{ d.probability_like_percent != null ? d.probability_like_percent + '%' : '' }}
                </div>
              </div>
            </div>
          </div>

          <!-- Officer override — Suspected Diseases (Change 17, 18) -->
          <div class="sc-override-section">
            <div class="sc-section-hdr" style="margin-top:12px">
              <span class="sc-sec-num sc-sec-num--purple">O</span>
              <span class="sc-sec-title">{{ t('Officer Override — Suspected Disease') }}</span>
              <span class="sc-sec-badge sc-sec-badge--opt">Single</span>
            </div>
            <p class="sc-override-hint">
              The algorithm has suggested the disease above. If you clinically suspect a different disease, search and select it. Diseases that lack the minimum WHO-required evidence for this traveller are disabled with a reason.
              <span v-if="officerOverride.addedDiseases.length" class="sc-override-rerun-note">
                ✓ Algorithm re-run with your declared suspicion — risk, routing, syndrome, and recommended actions updated.
              </span>
            </p>

            <!-- Rejection banner — shown when last attempted override was blocked -->
            <div v-if="analysisResult && analysisResult.officer_override_rejected" class="sc-vhf-banner" role="alert" style="background:linear-gradient(90deg,#7F1D1D,#991B1B);">
              <svg viewBox="0 0 16 16" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round"><path d="M8 1L1 14h14L8 1z"/><line x1="8" y1="5.5" x2="8" y2="9"/><circle cx="8" cy="11.5" r=".7" fill="#fff"/></svg>
              <div>
                <strong>Override rejected — {{ analysisResult.officer_override_rejected.disease_name }}.</strong>
                <span>{{ analysisResult.officer_override_rejected.reason }}</span>
              </div>
            </div>

            <!-- VHF Critical Banner — shown ONLY when officer declares VHF AND bleeding evidence is recorded -->
            <div v-if="officerOverride.addedDiseases.some(id => isVhfFamilyDisease(id)) && analysisResult && analysisResult.officer_override_accepted" class="sc-vhf-banner" role="alert">
              <svg viewBox="0 0 16 16" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round"><path d="M8 1L1 14h14L8 1z"/><line x1="8" y1="5.5" x2="8" y2="9"/><circle cx="8" cy="11.5" r=".7" fill="#fff"/></svg>
              <div>
                <strong>VHF SUSPECTED — CRITICAL alert.</strong>
                <span>Bleeding evidence confirmed. Isolate immediately. Escalate via the District tier — this is an always-notifiable presentation.</span>
              </div>
            </div>

            <!-- Disease search input -->
            <div class="sc-override-search-wrap">
              <svg viewBox="0 0 14 14" fill="none" stroke="#94A3B8" stroke-width="1.6" stroke-linecap="round" style="width:14px;height:14px;flex-shrink:0">
                <circle cx="6" cy="6" r="4"/><line x1="9" y1="9" x2="12" y2="12"/>
              </svg>
              <input
                type="search"
                role="searchbox"
                class="sc-override-search-input"
                placeholder="Search disease by name, IHR tier, or category…"
                v-model="overrideDiseaseSearch"
                aria-label="Search suspected disease"
                aria-controls="sc-override-disease-listbox"
                autocomplete="off"
              />
              <button v-if="overrideDiseaseSearch" type="button" class="sc-override-search-clear" aria-label="Clear search" @click="overrideDiseaseSearch = ''">×</button>
            </div>

            <!-- Max reached message -->
            <div v-if="overrideMaxReachedMessage" class="sc-override-max-msg" role="alert">
              {{ overrideMaxReachedMessage }}
            </div>

            <!-- Disease results list -->
            <div
              v-if="filteredOverrideDiseases.length > 0"
              role="listbox"
              id="sc-override-disease-listbox"
              class="sc-override-results"
            >
              <button
                v-for="d in filteredOverrideDiseases.slice(0, 30)"
                :key="d.id"
                type="button"
                role="option"
                :aria-selected="false"
                class="sc-override-result-row"
                :class="{ 'sc-override-result-row--vhf': isVhfFamilyDisease(d.id), 'sc-override-result-row--blocked': !overrideEligibilityFor(d.id).selectable }"
                @click="selectOverrideDisease(d)"
                :disabled="officerOverride.addedDiseases.length >= 3 || !overrideEligibilityFor(d.id).selectable"
                :title="overrideEligibilityFor(d.id).selectable ? '' : overrideEligibilityFor(d.id).reason"
                :aria-disabled="officerOverride.addedDiseases.length >= 3 || !overrideEligibilityFor(d.id).selectable"
              >
                <span class="sc-override-result-name">{{ d._displayName || d.name }}</span>
                <span class="sc-override-result-meta">
                  <span v-if="d.priority_tier" class="sc-override-tier-tag">{{ d.priority_tier.replace(/_/g,' ').replace('tier ','T') }}</span>
                  <span v-if="!overrideEligibilityFor(d.id).selectable" class="sc-override-evidence-tag">EVIDENCE MISSING</span>
                </span>
              </button>
            </div>
            <div v-else-if="overrideDiseaseSearch" class="sc-override-empty">No diseases match "{{ overrideDiseaseSearch }}".</div>

            <!-- Selected diseases (single chip) -->
            <div v-if="officerOverride.addedDiseases.length > 0" class="sc-override-added">
              <span v-for="(d, i) in officerOverride.addedDiseases" :key="i" class="sc-override-tag" :class="{ 'sc-override-tag--vhf': isVhfFamilyDisease(d) }">
                {{ diseaseDisplayName(d) }}
                <button type="button" class="sc-override-rm" @click="officerOverride.addedDiseases.splice(i,1); rerunAnalysisWithOfficerDiseases()" :aria-label="'Remove ' + diseaseDisplayName(d)">×</button>
              </span>
            </div>

            <!-- Score breakdown — explainable factor-by-factor after a successful override -->
            <div v-if="analysisResult && analysisResult.officer_override_accepted && analysisResult.officer_override_accepted.explanation" class="sc-override-explanation" role="region" aria-label="Officer override score breakdown">
              <div class="sc-override-explanation-hdr">
                <strong>Score breakdown — {{ analysisResult.officer_override_accepted.disease_name }}</strong>
                <span class="sc-override-explanation-final">
                  {{ analysisResult.officer_override_accepted.explanation.final_score }}/100
                  <span class="sc-override-explanation-band">{{ analysisResult.officer_override_accepted.explanation.confidence_band }}</span>
                </span>
              </div>
              <ol class="sc-override-explanation-list">
                <li v-for="(line, idx) in analysisResult.officer_override_accepted.explanation.rationale" :key="idx">{{ line }}</li>
              </ol>
              <div v-if="analysisResult.officer_override_accepted.explanation.symptom_factors.length === 0 && analysisResult.officer_override_accepted.explanation.exposure_factors.length === 0" class="sc-override-explanation-warn">
                ⚠️ No recorded symptoms or exposures contribute to this disease's score. The {{ analysisResult.officer_override_accepted.explanation.override_boost }}-point officer boost is the only contribution. Re-record evidence on Steps 2 / Exposures if available.
              </div>
            </div>
          </div>

          <!-- Recommended Actions Advisor (Change 19) — dynamic, no input -->
          <div v-if="analysisResult && (analysisResult.top_diagnoses || []).length > 0" class="sc-actions-advisor" role="region" aria-label="Recommended actions">
            <div class="sc-aa-hdr" :class="(analysisResult.ihr_risk?.risk_level === 'LOW' || !analysisResult.ihr_risk?.risk_level) ? 'sc-aa-hdr--low' : 'sc-aa-hdr--alert'">
              <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" style="width:13px;height:13px;flex-shrink:0">
                <circle cx="7" cy="7" r="5"/><line x1="7" y1="4.5" x2="7" y2="7.5"/><circle cx="7" cy="9.5" r=".5" fill="currentColor"/>
              </svg>
              <span class="sc-aa-title">Recommended Actions</span>
              <span class="sc-aa-pill" :class="'sc-aa-pill--' + (analysisResult.ihr_risk?.risk_level || 'LOW').toLowerCase()">
                {{ analysisResult.ihr_risk?.risk_level || 'LOW' }}
                <span v-if="analysisResult.ihr_risk?.ihr_tier"> · {{ analysisResult.ihr_risk.ihr_tier.replace(/_/g, ' ') }}</span>
              </span>
            </div>
            <div class="sc-aa-body">
              <div class="sc-aa-headline">
                <strong>{{ (analysisResult.top_diagnoses || []).slice(0, 3).map(d => diseaseDisplayName(d.disease_id)).join(' / ') || 'Acute presentation' }}</strong>
              </div>
              <ul class="sc-aa-list">
                <li v-for="(action, idx) in adviserImmediateActions" :key="idx">{{ action }}</li>
              </ul>
              <!-- IHR mandatory notice removed from suspected-case analysis;
                   shown only after CONFIRMED disposition in the war-room. -->

              <div v-if="adviserNotifyLevels.length" class="sc-aa-who">
                Levels to notify: <strong>{{ adviserNotifyLevels.join(', ') }}</strong>
              </div>
            </div>
          </div>

          <!-- No analysis yet -->
          <div v-if="!analysisResult" class="sc-empty-analysis" role="status">
            <svg viewBox="0 0 20 20" fill="none" stroke="#B0BEC5" stroke-width="1.4" stroke-linecap="round"><circle cx="10" cy="10" r="8"/><path d="M6 10h8M10 6v8"/></svg>
            <span>Analysis not yet run. Go back to step 3 and tap "Analyse →"</span>
          </div>

          <!-- 1-M: CTA to proceed from analysis review to step 5 disposition -->
          <div class="sc-step4-proceed">
            <button
              class="sc-nav-btn sc-nav-btn--primary sc-step4-proceed-btn"
              type="button"
              :disabled="!analysisResult"
              @click="step = 5; if (maxStepReached < 5) maxStepReached = 5"
              aria-label="Proceed to final disposition"
            >
              Review complete — Proceed to Disposition →
            </button>
          </div>

        </div><!-- /step 4 -->

        <!-- ══════════════════════════════════════════════════════════════
             1-M STEP 5: FINAL DISPOSITION
             Syndrome, risk level, clinical actions, followup, notes, submit.
             Separated from step 4 so the analysis page is breathable.
        ══════════════════════════════════════════════════════════════════ -->
        <div v-show="step === 5">

          <!-- ── Syndrome Classification (Change 21 — searchable, WHO-complete) ── -->
          <div class="sc-section-hdr" style="margin-top:16px">
            <span class="sc-sec-num sc-sec-num--blue">1</span>
            <span class="sc-sec-title">{{ t('Syndrome Classification') }}</span>
            <span v-if="autoSyndromeApplied && caseDecision.syndrome_classification" class="sc-sec-badge sc-sec-badge--auto">Auto-set ✓</span>
            <span v-else class="sc-sec-badge sc-sec-badge--req">Required</span>
          </div>

          <!-- Engine syndrome auto-suggestion -->
          <div v-if="analysisResult?.syndrome?.syndrome" class="sc-syn-engine-hint">
            <svg viewBox="0 0 12 12" fill="none" stroke="#1565C0" stroke-width="1.6" stroke-linecap="round" style="width:11px;height:11px;flex-shrink:0"><circle cx="6" cy="6" r="4.5"/><line x1="6" y1="4" x2="6" y2="6.5"/><circle cx="6" cy="8.5" r=".5" fill="#1565C0"/></svg>
            <div style="flex:1">
              <span>Engine suggests: <strong>{{ syndromeNameFromCode(analysisResult.syndrome.syndrome) }}</strong> ({{ analysisResult.syndrome.confidence }})</span>
              <button v-if="syndromeNameFromCode(normalizeSyndromeCode(analysisResult.syndrome.syndrome)) !== syndromeNameFromCode(caseDecision.syndrome_classification)"
                class="sc-risk-apply-btn" style="margin-left:8px" type="button"
                @click="caseDecision.syndrome_classification = normalizeSyndromeCode(analysisResult.syndrome.syndrome); autoSyndromeApplied = true">
                Accept
              </button>
            </div>
          </div>

          <!-- Selected syndrome display -->
          <div v-if="caseDecision.syndrome_classification" class="sc-syn-selected">
            <strong>{{ syndromeNameFromCode(caseDecision.syndrome_classification) }}</strong>
            <button class="sc-syn-clear-btn" type="button" @click="caseDecision.syndrome_classification = ''; officerOverride.syndromeOverridden = false">Change</button>
          </div>

          <!-- Searchable list (only shown when no syndrome selected, or user clicks "Change") -->
          <div v-if="!caseDecision.syndrome_classification" class="sc-syn-search-section">
            <div class="sc-override-search-wrap" role="combobox" aria-expanded="true" aria-haspopup="listbox">
              <svg viewBox="0 0 14 14" fill="none" stroke="#94A3B8" stroke-width="1.6" stroke-linecap="round" style="width:14px;height:14px;flex-shrink:0">
                <circle cx="6" cy="6" r="4"/><line x1="9" y1="9" x2="12" y2="12"/>
              </svg>
              <input
                type="search"
                role="searchbox"
                class="sc-override-search-input"
                placeholder="Search syndrome by name or description…"
                v-model="syndromeSearchQuery"
                aria-label="Search syndromic classification"
                aria-controls="sc-syn-listbox"
                autocomplete="off"
              />
              <button v-if="syndromeSearchQuery" type="button" class="sc-override-search-clear" aria-label="Clear search" @click="syndromeSearchQuery = ''">×</button>
            </div>
            <div role="listbox" id="sc-syn-listbox" class="sc-syn-list">
              <button
                v-for="syn in filteredSyndromes"
                :key="syn.code"
                type="button"
                role="option"
                :aria-selected="caseDecision.syndrome_classification === syn.code"
                class="sc-syn-row"
                :class="{ 'sc-syn-row--danger': syn.danger }"
                @click="caseDecision.syndrome_classification = syn.code; autoSyndromeApplied = false; officerOverride.syndromeOverridden = true"
              >
                <span class="sc-syn-row-name">{{ syn.name }}</span>
                <span class="sc-syn-row-desc">{{ syn.description }}</span>
              </button>
            </div>
          </div>

          <div v-if="fieldErrors.syndrome_classification" class="sc-field-err" role="alert">{{ fieldErrors.syndrome_classification }}</div>

          <!-- ── Risk Level (Change 22 — read-only, system-determined) ── -->
          <div class="sc-section-hdr" style="margin-top:16px">
            <span class="sc-sec-num sc-sec-num--red">2</span>
            <span class="sc-sec-title">{{ t('Risk Level — System Determined') }}</span>
            <span class="sc-sec-badge sc-sec-badge--auto">Auto</span>
          </div>
          <div class="sc-risk-readonly">
            <div class="sc-risk-readonly-row">
              <span class="sc-risk-readonly-label">Computed risk:</span>
              <span class="sc-risk-readonly-badge" :class="'sc-risk-readonly-badge--' + (caseDecision.risk_level || 'PENDING').toLowerCase()">
                {{ caseDecision.risk_level || 'Computing…' }}
              </span>
            </div>
            <div class="sc-risk-readonly-row">
              <span class="sc-risk-readonly-label">Primary route:</span>
              <span class="sc-risk-readonly-route">
                <strong>District health office</strong>
                <span class="sc-risk-readonly-aux">· PHEOC and National also informed via escalation ladder</span>
              </span>
            </div>
            <!-- 2026-05-07: tier label kept (clinical priority indicator)
                 but stripped of "IHR" branding per surveillance-app rule. -->
            <div v-if="analysisResult?.ihr_risk?.ihr_tier" class="sc-risk-readonly-row">
              <span class="sc-risk-readonly-label">Priority tier:</span>
              <span class="sc-risk-readonly-tier">{{ analysisResult.ihr_risk.ihr_tier.replace(/_/g, ' ').replace(/\bihr\b/gi, '').trim() }}</span>
            </div>
            <p class="sc-risk-readonly-note">
              The risk level is computed by the system from the symptoms, vitals, exposures, and any officer-declared suspicion. It cannot be edited directly — modify the clinical inputs above to change it.
            </p>
          </div>

          <!-- ── Alert Preview (shows only when triggered) ── -->
          <div v-if="alertPreview" class="sc-alert-preview" role="alert" aria-live="polite">
            <div class="sc-ap-hdr">
              <svg viewBox="0 0 16 16" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round"><path d="M8 1L1 14h14L8 1z"/><line x1="8" y1="5.5" x2="8" y2="9"/><circle cx="8" cy="11.5" r=".7" fill="#fff"/></svg>
              <span class="sc-ap-title">Alert Auto-Triggered</span>
              <span class="sc-ap-badge">IHR Rule-Based</span>
            </div>
            <div class="sc-ap-body">
              <div class="sc-ap-row"><span class="sc-ap-k">Code</span><span class="sc-ap-v sc-ap-v--warn">{{ alertPreview.alertCode }}</span></div>
              <div class="sc-ap-row"><span class="sc-ap-k">Risk Level</span><span class="sc-ap-v">{{ alertPreview.riskLevel }}</span></div>
              <div class="sc-ap-row">
                <span class="sc-ap-k">Route To</span>
                <span class="sc-ap-v">
                  <span class="sc-ap-target" :class="'sc-ap-target--' + alertPreview.routedTo.toLowerCase()">{{ alertPreview.routedTo }} ★</span>
                </span>
              </div>
            </div>
          </div>

          <!-- ── Recommended actions (decluttered, court-defensible v2) ──
               Computed live by recommendedActions. Officer-override aware,
               per-disease, gap-detected (✓ = officer has already ticked an
               equivalent ACTIONS row). Reference labels are kept tight. -->
          <div v-if="recommendedWhoActions.length" class="sc-rec-panel" role="region" aria-label="Recommended actions">
            <div class="sc-rec-hdr">
              <span class="sc-rec-ic" aria-hidden="true">
                <svg viewBox="0 0 16 16" fill="none" stroke="#1D4ED8" stroke-width="1.6" stroke-linecap="round">
                  <circle cx="8" cy="8" r="6.5"/><path d="M8 4v4l2.5 2.5"/>
                </svg>
              </span>
              <div class="sc-rec-titlewrap">
                <div class="sc-rec-title">Recommended actions</div>
                <div class="sc-rec-sub">
                  For <strong>{{ recommendedDiseaseLabel }}</strong>
                  <span v-if="recommendedRiskLevel" class="sc-rec-tier">· {{ recommendedRiskLevel }} risk</span>
                </div>
              </div>
            </div>
            <ul class="sc-rec-list">
              <li v-for="(action, i) in recommendedWhoActions" :key="action.code || i"
                  :class="['sc-rec-item', action.done && 'sc-rec-item--done']">
                <button
                  type="button"
                  class="sc-rec-item-btn"
                  :title="action.source"
                  :aria-label="(action.done ? 'Done: ' : 'Tap to record: ') + action.text"
                  @click="action.code && toggleAction(action.code)"
                >
                  <span class="sc-rec-bullet" aria-hidden="true">{{ action.done ? '✓' : '•' }}</span>
                  <div class="sc-rec-body">
                    <div class="sc-rec-action-row">
                      <span class="sc-rec-action">{{ action.text }}</span>
                      <span v-if="action.urgency"
                            :class="['sc-rec-urg', 'sc-rec-urg--' + action.urgency]"
                            aria-hidden="true">{{ action.urgency === 'now' ? 'Now' : action.urgency === '24h' ? '24 h' : 'Routine' }}</span>
                    </div>
                    <div v-if="action.rationale" class="sc-rec-src">{{ action.rationale }}</div>
                  </div>
                </button>
              </li>
            </ul>
            <div class="sc-rec-foot">
              Tap any row to record it below. Exact-action recommendations match the buttons in step 3.
            </div>
          </div>

          <!-- ── Actions Taken (Change 19 — WHO/IDSR 5 categories) ── -->
          <div class="sc-section-hdr" style="margin-top:16px">
            <span class="sc-sec-num sc-sec-num--green">3</span>
            <span class="sc-sec-title">{{ t('Actions Taken') }}</span>
            <span class="sc-sec-badge sc-sec-badge--req">At least 1 required</span>
          </div>
          <div v-if="fieldErrors.actions" class="sc-field-err" role="alert">{{ fieldErrors.actions }}</div>
          <div class="sc-actions-grid">
            <button
              v-for="ac in ACTIONS" :key="ac.code"
              class="sc-action-btn"
              :class="{ 'sc-action-btn--active': isActionDone(ac.code) }"
              type="button"
              :aria-pressed="isActionDone(ac.code)"
              @click="toggleAction(ac.code)"
              :title="ac.basis"
            >
              <span class="sc-action-ic" aria-hidden="true">
                <svg v-if="isActionDone(ac.code)" viewBox="0 0 10 10" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round"><polyline points="1 5 4 8 9 2"/></svg>
                <span v-else class="sc-action-dot" />
              </span>
              <span class="sc-action-lbl-wrap">
                <span class="sc-action-lbl-main">{{ ac.label }}</span>
                <span class="sc-action-lbl-sub">{{ ac.basis }}</span>
              </span>
            </button>
          </div>

          <!-- 2026-05-07: HIGH/CRITICAL action-mismatch banner removed per
               operations mandate. Officer judgment governs disposition; the
               recommendation panel above already cites the suggested actions. -->
          <div v-if="false"></div>

          <!-- ── Final Disposition ── -->
          <div class="sc-section-hdr" style="margin-top:16px">
            <span class="sc-sec-num sc-sec-num--green">4</span>
            <span class="sc-sec-title">{{ t('Final Disposition') }}</span>
            <span class="sc-sec-badge sc-sec-badge--req">Required</span>
          </div>
          <div v-if="fieldErrors.final_disposition" class="sc-field-err" role="alert">{{ fieldErrors.final_disposition }}</div>

          <!-- 2026-05-07: impossible-disposition + warning banners removed
               per operations mandate. Officer judgment is final. -->
          <div v-if="false"></div>

          <div class="sc-disp-grid">
            <button
              v-for="dp in DISPOSITIONS" :key="dp.value"
              class="sc-disp-btn"
              :class="{ 'sc-disp-btn--active': caseDecision.final_disposition === dp.value }"
              type="button"
              :aria-pressed="caseDecision.final_disposition === dp.value"
              @click="caseDecision.final_disposition = dp.value"
            >
              <span class="sc-disp-ic" v-html="dp.icon" aria-hidden="true" />
              <span class="sc-disp-lbl">{{ dp.label }}</span>
            </button>
          </div>

          <!-- ── Officer Notes ── -->
          <div class="sc-section-hdr" style="margin-top:16px">
            <span class="sc-sec-num sc-sec-num--blue">5</span>
            <span class="sc-sec-title">{{ t('Officer Notes') }}</span>
            <span class="sc-sec-badge sc-sec-badge--req">Required</span>
          </div>
          <div class="sc-notes-wrap" id="sc-voice-note">
            <textarea
              class="sc-notes-input"
              :class="{ 'sc-notes-input--err': fieldErrors.officer_notes }"
              rows="4"
              maxlength="5000"
              :placeholder="t('Clinical observations, context, differential reasoning… (required)')"
              v-model="caseDecision.officer_notes"
              aria-label="Officer notes"
              :aria-invalid="!!fieldErrors.officer_notes"
              required
            />
            <div v-if="fieldErrors.officer_notes" class="sc-field-err" role="alert">{{ fieldErrors.officer_notes }}</div>
          </div>

          <!-- Follow-up automated per executive directive (2026-05-08). The
               manual toggle is gone. Every secondary case fans out to
               District + PHEOC + National at disposition. -->
          <div class="sc-followup-info">
            <svg viewBox="0 0 12 12" fill="none" stroke="#1565C0" stroke-width="1.6" stroke-linecap="round" style="width:12px;height:12px;flex-shrink:0;margin-top:1px"><circle cx="6" cy="6" r="4.5"/><line x1="6" y1="4" x2="6" y2="6.5"/><circle cx="6" cy="8.5" r=".5" fill="#1565C0"/></svg>
            <span><strong>Follow-up enabled automatically.</strong> All supervisory tiers (District, PHEOC, National) will be notified on disposition.</span>
          </div>

          <!-- Suspected diseases (read-only review) -->
          <div v-if="suspectedDiseases.length > 0" class="sc-section-hdr" style="margin-top:16px">
            <span class="sc-sec-num sc-sec-num--purple">6</span>
            <span class="sc-sec-title">{{ t('Suspected Diseases (to be saved)') }}</span>
            <span class="sc-sec-badge sc-sec-badge--opt">Auto-populated</span>
          </div>
          <div v-if="suspectedDiseases.length > 0" class="sc-sus-list">
            <div v-for="sd in suspectedDiseases" :key="sd.disease_code" class="sc-sus-row">
              <span class="sc-sus-rank">{{ sd.rank_order }}</span>
              <span class="sc-sus-name">{{ diseaseDisplayName(sd.disease_code) }}</span>
              <span v-if="sd.confidence" class="sc-sus-conf">{{ sd.confidence }}%</span>
            </div>
          </div>

          <div style="height:8px"/>
        </div>
        <!-- /STEP 5 -->

        <!-- ════════════════════════════════════════════════════
             NOTIFICATION STATE VERIFICATION PANEL
             Always visible in step 4 or 5. Shows IDB + server state.
        ════════════════════════════════════════════════════ -->
        <div v-if="isAdmin && debugPanelOpen && (step === 4 || step === 5)" class="sc-verify-panel">
          <div class="sc-verify-header">
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" style="width:14px;height:14px;flex-shrink:0">
              <circle cx="8" cy="8" r="6"/><polyline points="5 8 7.5 10.5 11 6"/>
            </svg>
            <span class="sc-verify-title">Notification &amp; Sync Integrity Test</span>
            <button class="sc-verify-btn sc-verify-btn--sync" :disabled="syncStatus.running" @click.prevent="syncCaseToServer(getAuth())">
              {{ syncStatus.running ? '⏳ Syncing…' : '⬆ Force Sync' }}
            </button>
            <button class="sc-verify-btn" :disabled="notifVerify.running" @click.prevent="verifyNotificationState">
              {{ notifVerify.running ? 'Checking…' : notifVerify.ran ? 'Re-verify' : 'Run Test' }}
            </button>
          </div>

          <!-- Sync result rows -->
          <div v-if="syncStatus.lastRunAt" class="sc-sync-result-block">
            <div class="sc-srb-title">Last sync attempt: {{ syncStatus.lastRunAt }}</div>
            <div class="sc-srb-row" :class="'sc-srb-row--' + (syncStatus.phase1 ?? 'pending')">
              <span class="sc-srb-phase">Phase 1 — Create</span>
              <span class="sc-srb-status">{{ syncStatus.phase1 ?? '—' }}</span>
              <span class="sc-srb-msg">{{ syncStatus.phase1Msg }}</span>
            </div>
            <div class="sc-srb-row" :class="'sc-srb-row--' + (syncStatus.phase2 ?? 'pending')">
              <span class="sc-srb-phase">Phase 2 — FullSync</span>
              <span class="sc-srb-status">{{ syncStatus.phase2 ?? '—' }}</span>
              <span class="sc-srb-msg">{{ syncStatus.phase2Msg }}</span>
            </div>
            <div v-if="syncStatus.error" class="sc-srb-error">⚠ {{ syncStatus.error }}</div>
            <details v-if="syncStatus.phase1Resp" class="sc-verify-raw">
              <summary>Phase 1 server response</summary>
              <pre>{{ JSON.stringify(syncStatus.phase1Resp, null, 2) }}</pre>
            </details>
            <details v-if="syncStatus.phase2Resp" class="sc-verify-raw">
              <summary>Phase 2 server response</summary>
              <pre>{{ JSON.stringify(syncStatus.phase2Resp, null, 2) }}</pre>
            </details>
            <details v-if="syncStatus.phase1Payload" class="sc-verify-raw">
              <summary>Phase 1 payload sent</summary>
              <pre>{{ JSON.stringify(syncStatus.phase1Payload, null, 2) }}</pre>
            </details>
          </div>

          <!-- Not yet run -->
          <div v-if="!notifVerify.ran && !notifVerify.running && !notifVerify.error" class="sc-verify-idle">
            Tap "Run Test" to verify notification and sync state end-to-end.
          </div>

          <!-- Error -->
          <div v-if="notifVerify.error" class="sc-verify-error">⚠ {{ notifVerify.error }}</div>

          <!-- Results -->
          <div v-if="notifVerify.ran" class="sc-verify-results">
            <div
              v-for="(chk, i) in notifVerify.checks"
              :key="i"
              class="sc-verify-row"
              :class="chk.pass ? 'sc-verify-row--pass' : 'sc-verify-row--fail'"
            >
              <span class="sc-verify-icon">{{ chk.pass ? '✓' : '✖' }}</span>
              <div class="sc-verify-body">
                <div class="sc-verify-label">{{ chk.label }}</div>
                <div class="sc-verify-detail">{{ chk.detail }}</div>
              </div>
            </div>

            <!-- Summary -->
            <div class="sc-verify-summary" :class="notifVerify.checks.every(c=>c.pass) ? 'sc-verify-summary--ok' : 'sc-verify-summary--warn'">
              {{ notifVerify.checks.filter(c=>c.pass).length }} / {{ notifVerify.checks.length }} checks passed
              <template v-if="!notifVerify.checks.every(c=>c.pass)">
              — {{ isOnline ? 'Tap Re-verify after saving or syncing' : 'Device is offline — IDB checks only' }}
              </template>
            </div>

            <!-- IDB raw dump -->
            <details class="sc-verify-raw" v-if="notifVerify.idb">
              <summary>IDB raw notification record</summary>
              <pre>{{ JSON.stringify(notifVerify.idb, null, 2) }}</pre>
            </details>
            <details class="sc-verify-raw" v-if="notifVerify.server">
              <summary>Server raw notification record</summary>
              <pre>{{ JSON.stringify(notifVerify.server, null, 2) }}</pre>
            </details>
          </div>
        </div>

      </div>
      <!-- /wizard body -->

    </IonContent>

    <!-- ══════════════════════════════════════════════════════════════════
         FOOTER — Navigation buttons
    ══════════════════════════════════════════════════════════════════ -->
    <IonFooter v-if="!loading && !notFound" class="sc-footer">
      <!-- Inline summary of exactly which Step-1 fields are blocking advance.
           Shown only when on step 1 with outstanding problems so the officer
           can see at a glance what to fix instead of guessing. -->
      <div v-if="step === 1 && step1ProblemList.length" class="sc-footer-problems" role="alert" aria-live="polite">
        <div class="sc-footer-problems-hdr">
          <svg viewBox="0 0 14 14" fill="none" stroke="#B71C1C" stroke-width="1.6" stroke-linecap="round"><circle cx="7" cy="7" r="5.5"/><line x1="7" y1="4.5" x2="7" y2="7.5"/><circle cx="7" cy="9.5" r="0.6" fill="#B71C1C"/></svg>
          <span>{{ step1ProblemList.length }} field{{ step1ProblemList.length === 1 ? '' : 's' }} missing — please complete:</span>
        </div>
        <ul class="sc-footer-problems-list">
          <li v-for="(p, i) in step1ProblemList" :key="i">{{ p }}</li>
        </ul>
      </div>
      <div class="sc-footer-inner">
        <!-- Back button (steps 2-4) -->
        <button
          v-if="step > 1"
          class="sc-nav-btn sc-nav-btn--back"
          type="button"
          @click="goBackStep"
          :disabled="saving"
        >
          <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="9 3 5 7 9 11"/></svg>
          Back
        </button>
        <div v-else class="sc-nav-spacer" />

        <!-- Step 1 → 2 -->
        <button
          v-if="step === 1"
          class="sc-nav-btn sc-nav-btn--next"
          type="button"
          @click="saveStep1AndNext"
          :disabled="saving || !step1Valid"
          :title="step1Valid ? 'Continue to symptoms' : ('Missing: ' + step1ProblemList.join(' · '))"
        >
          {{ saving ? 'Saving…' : (step1Valid ? 'Next' : ('Fix ' + step1ProblemList.length + ' field' + (step1ProblemList.length === 1 ? '' : 's') + ' to continue')) }}
          <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="5 3 9 7 5 11"/></svg>
        </button>

        <!-- Step 2 → 3 (symptoms are MANDATORY — at least one assessed) -->
        <button
          v-if="step === 2"
          class="sc-nav-btn sc-nav-btn--next"
          type="button"
          @click="saveStep2AndNext"
          :disabled="saving || !step2Valid"
          :title="step2Valid ? 'Continue to exposures' : 'Mark at least one symptom Yes or No before continuing.'"
        >
          {{ saving ? 'Saving…' : (step2Valid ? 'Next' : 'Assess at least one symptom') }}
          <svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="5 3 9 7 5 11"/></svg>
        </button>

        <!-- Step 3 → 4 (exposures are MANDATORY — at least one answered) -->
        <button
          v-if="step === 3"
          class="sc-nav-btn sc-nav-btn--analyse"
          type="button"
          @click="saveStep3AndAnalyse"
          :disabled="saving || !step3Valid"
          :title="step3Valid ? 'Run intelligence analysis' : 'Answer at least one exposure question before continuing.'"
        >
          {{ saving ? 'Analysing…' : (step3Valid ? 'Analyse →' : 'Answer at least one exposure') }}
        </button>

        <!-- 1-M: Step 4 → proceed to step 5 (also in inline button above, this is footer mirror) -->
        <button
          v-if="step === 4"
          class="sc-nav-btn sc-nav-btn--disposition"
          type="button"
          :disabled="!analysisResult"
          @click="step = 5; if (maxStepReached < 5) maxStepReached = 5"
        >
          Review complete — Disposition →
        </button>

        <!-- Step 5 — Final submission -->
        <button
          v-if="step === 5"
          class="sc-nav-btn sc-nav-btn--disposition"
          type="button"
          @click="dispositionCase"
          :disabled="saving || !canDisposition"
        >
          {{ saving ? 'Saving…' : 'Save & Disposition' }}
        </button>
      </div>
    </IonFooter>



  <!-- ═══════════════════════════════════════════════════════════════
       DISEASE DETAIL MODAL — WHO case definition + intelligence scores
  ═══════════════════════════════════════════════════════════════════ -->
  <IonModal :is-open="!!selectedDiseaseModal"
    :breakpoints="[0, 1]" :initial-breakpoint="1"
    @ionModalDidDismiss="selectedDiseaseModal = null" handle-behavior="cycle">
    <IonHeader :translucent="false" v-if="selectedDiseaseModal">
      <IonToolbar style="--background:linear-gradient(180deg,#070E1B,#0E1A2E);--color:#EDF2FA;--border-width:0;--min-height:48px;">
        <IonButtons slot="start">
          <IonButton @click="selectedDiseaseModal = null"
            style="--color:rgba(255,255,255,.75);" aria-label="Close modal">
            <IonIcon :icon="closeOutline"/>
          </IonButton>
        </IonButtons>
        <IonTitle style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-weight:800;color:#EDF2FA;font-size:16px;">
          Disease Intelligence
        </IonTitle>
        <div slot="end" style="padding-right:12px;">
          <span v-if="selectedDiseaseModal.confidence_band" class="sc-dm-band"
            :class="'sc-dm-band--' + selectedDiseaseModal.confidence_band">
            {{ selectedDiseaseModal.confidence_band.replace(/_/g,' ').toUpperCase() }}
          </span>
        </div>
      </IonToolbar>
    </IonHeader>

    <IonContent :scroll-y="true" v-if="selectedDiseaseModal"
      style="--background:linear-gradient(180deg,#EEF2FA 0%,#FFFFFF 50%,#F4F7FC 100%);--color:#0B1A30;">
      <div class="sc-dm-wrap">

        <!-- Hero — name, ID, scores -->
        <div class="sc-dm-hero">
          <div class="sc-dm-name">{{ selectedDiseaseModal.name }}</div>
          <div class="sc-dm-id">{{ selectedDiseaseModal.disease_id }}</div>
          <div class="sc-dm-metric-row">
            <div v-if="selectedDiseaseModal.final_score != null" class="sc-dm-metric">
              <span class="sc-dm-metric-val"
                :class="selectedDiseaseModal.final_score >= 60 ? 'sc-dm-mv--high' : selectedDiseaseModal.final_score >= 35 ? 'sc-dm-mv--med' : 'sc-dm-mv--low'">
                {{ selectedDiseaseModal.final_score }}
              </span>
              <span class="sc-dm-metric-lbl">Score /100</span>
            </div>
            <div v-if="selectedDiseaseModal.probability_like_percent != null" class="sc-dm-metric">
              <span class="sc-dm-metric-val sc-dm-mv--med">{{ selectedDiseaseModal.probability_like_percent }}%</span>
              <span class="sc-dm-metric-lbl">Probability</span>
            </div>
            <div v-if="selectedDiseaseModal.cfr_pct != null" class="sc-dm-metric">
              <span class="sc-dm-metric-val sc-dm-mv--warn">{{ selectedDiseaseModal.cfr_pct }}%</span>
              <span class="sc-dm-metric-lbl">Case Fatality</span>
            </div>
          </div>
          <div v-if="selectedDiseaseModal.final_score != null" class="sc-dm-bar-track" aria-hidden="true">
            <div class="sc-dm-bar-fill" :style="{ width: Math.min(100, selectedDiseaseModal.final_score) + '%' }"
              :class="selectedDiseaseModal.final_score >= 60 ? 'sc-dm-bar--high'
                    : selectedDiseaseModal.final_score >= 35 ? 'sc-dm-bar--med' : 'sc-dm-bar--low'"/>
          </div>
        </div>

        <!-- IHR classification + syndrome -->
        <div v-if="selectedDiseaseModal.ihr_category || selectedDiseaseModal.syndrome_matched" class="sc-dm-section">
          <div class="sc-dm-section-lbl">CLASSIFICATION</div>
          <div class="sc-dm-chips-row">
            <span v-if="selectedDiseaseModal.ihr_category" class="sc-dm-ihr-chip">
              {{ selectedDiseaseModal.ihr_category }}
            </span>
            <span v-if="selectedDiseaseModal.syndrome_matched" class="sc-dm-syn-chip">
              <svg viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2.2"
                stroke-linecap="round" aria-hidden="true">
                <polyline points="1 5 4 8 9 2"/>
              </svg>
              {{ selectedDiseaseModal.syndrome_matched }} matched
            </span>
          </div>
        </div>

        <!-- Matched hallmarks -->
        <div v-if="selectedDiseaseModal.matched_hallmarks?.length > 0" class="sc-dm-section">
          <div class="sc-dm-section-lbl">MATCHED HALLMARK SYMPTOMS ({{ selectedDiseaseModal.matched_hallmarks.length }})</div>
          <div class="sc-dm-hallmarks">
            <span v-for="h in selectedDiseaseModal.matched_hallmarks" :key="h" class="sc-dm-htag">
              {{ h.replace(/_/g, ' ') }}
            </span>
          </div>
        </div>

        <!-- ────────────────────────────────────────────────────────────
             COURT-DEFENSIBLE SCORE BREAKDOWN (2026-05-07)

             Surfaces every factor the engine summed to reach the
             disease's final_score. Reproduces the math the screener
             can defend if asked: the same numbers stored in IDB +
             synced to the server. Three sections:

               • Matched symptoms (with their per-symptom weight)
               • Matched exposures (with weight)
               • Other modifiers (override boost, syndrome match
                 bonus, negative weights, etc.) — pulled verbatim
                 from score_breakdown.

             Every line is read from the engine's output object — no
             re-derivation here, so what's shown IS what was scored. ─── -->
        <div class="sc-dm-section sc-dm-section--breakdown">
          <div class="sc-dm-section-lbl">SCORE BREAKDOWN — total {{ selectedDiseaseModal.final_score ?? 0 }} pts</div>
          <p class="sc-dm-bd-intro">
            How the engine reached this score. Every factor below was
            actually applied to this specific case — the same numbers are
            persisted on the server with the disease record.
          </p>

          <!-- Matched symptoms (with weights from score_breakdown) -->
          <div v-if="(selectedDiseaseModal.matched_symptoms || []).length > 0" class="sc-dm-bd-group">
            <div class="sc-dm-bd-group-lbl">Matched symptoms ({{ selectedDiseaseModal.matched_symptoms.length }})</div>
            <div class="sc-dm-bd-row" v-for="s in selectedDiseaseModal.matched_symptoms" :key="'sym-'+s">
              <span class="sc-dm-bd-k">{{ String(s).replace(/_/g, ' ') }}</span>
              <span class="sc-dm-bd-v sc-dm-bd-pos">+{{ _weightFor('symptom', s, selectedDiseaseModal) }}</span>
            </div>
          </div>

          <!-- Matched exposures -->
          <div v-if="(selectedDiseaseModal.matched_exposures || []).length > 0" class="sc-dm-bd-group">
            <div class="sc-dm-bd-group-lbl">Matched exposures ({{ selectedDiseaseModal.matched_exposures.length }})</div>
            <div class="sc-dm-bd-row" v-for="e in selectedDiseaseModal.matched_exposures" :key="'exp-'+e">
              <span class="sc-dm-bd-k">{{ String(e).replace(/_/g, ' ') }}</span>
              <span class="sc-dm-bd-v sc-dm-bd-pos">+{{ _weightFor('exposure', e, selectedDiseaseModal) }}</span>
            </div>
          </div>

          <!-- Matched hallmarks (called out separately — these usually
               carry the heaviest weight in the engine). -->
          <div v-if="(selectedDiseaseModal.matched_hallmarks || []).length > 0" class="sc-dm-bd-group">
            <div class="sc-dm-bd-group-lbl">Hallmark signs ({{ selectedDiseaseModal.matched_hallmarks.length }})</div>
            <div class="sc-dm-bd-row" v-for="h in selectedDiseaseModal.matched_hallmarks" :key="'hm-'+h">
              <span class="sc-dm-bd-k">{{ String(h).replace(/_/g, ' ') }}</span>
              <span class="sc-dm-bd-v sc-dm-bd-pos">hallmark</span>
            </div>
          </div>

          <!-- Catch-all: every other key in score_breakdown that isn't
               already covered (override_boost, syndrome_bonus, negative
               weights, threshold deductions, …) -->
          <div v-if="selectedDiseaseModal.score_breakdown && Object.keys(selectedDiseaseModal.score_breakdown).length > 0"
               class="sc-dm-bd-group">
            <div class="sc-dm-bd-group-lbl">Modifiers</div>
            <div v-for="(val, key) in selectedDiseaseModal.score_breakdown" :key="key" class="sc-dm-bd-row">
              <span class="sc-dm-bd-k">{{ String(key).replace(/_/g,' ') }}</span>
              <span class="sc-dm-bd-v"
                :class="Number(val) > 0 ? 'sc-dm-bd-pos' : Number(val) < 0 ? 'sc-dm-bd-neg' : ''">
                {{ Number(val) > 0 ? '+' : '' }}{{ val }}
              </span>
            </div>
          </div>

          <div class="sc-dm-bd-total">
            <span>Total</span>
            <span class="sc-dm-bd-total-v">{{ selectedDiseaseModal.final_score ?? 0 }} / 100 pts</span>
          </div>
        </div>

        <!-- Plain-language reasoning (auto-generated from the breakdown) -->
        <div v-if="selectedDiseaseModal.reasoning_text" class="sc-dm-section sc-dm-section--reasoning">
          <div class="sc-dm-section-lbl">HOW THE ENGINE ARRIVED AT THIS SCORE</div>
          <p class="sc-dm-def-text">{{ selectedDiseaseModal.reasoning_text }}</p>
        </div>

        <!-- IDSR thresholds + incubation (Uganda IDSR Annex 1A) -->
        <div v-if="selectedDiseaseModal.alert_threshold || selectedDiseaseModal.epidemic_threshold || selectedDiseaseModal.incubation_period_days"
             class="sc-dm-section">
          <div class="sc-dm-section-lbl">UGANDA IDSR THRESHOLDS</div>
          <div v-if="selectedDiseaseModal.alert_threshold" class="sc-dm-bd-row">
            <span class="sc-dm-bd-k">Alert threshold</span>
            <span class="sc-dm-bd-v">{{ selectedDiseaseModal.alert_threshold }}</span>
          </div>
          <div v-if="selectedDiseaseModal.epidemic_threshold" class="sc-dm-bd-row">
            <span class="sc-dm-bd-k">Epidemic threshold</span>
            <span class="sc-dm-bd-v">{{ selectedDiseaseModal.epidemic_threshold }}</span>
          </div>
          <div v-if="selectedDiseaseModal.incubation_period_days" class="sc-dm-bd-row">
            <span class="sc-dm-bd-k">Incubation period</span>
            <span class="sc-dm-bd-v">
              {{ selectedDiseaseModal.incubation_period_days.min }}–{{ selectedDiseaseModal.incubation_period_days.max }} days
            </span>
          </div>
        </div>

        <!-- WHO Case Definitions -->
        <template v-if="selectedDiseaseModal.who_def">
          <div class="sc-dm-section sc-dm-section--who">
            <div class="sc-dm-section-lbl">SUSPECTED CASE</div>
            <p class="sc-dm-def-text">{{ selectedDiseaseModal.who_def.suspected }}</p>
          </div>
          <div v-if="selectedDiseaseModal.who_def.probable" class="sc-dm-section sc-dm-section--who">
            <div class="sc-dm-section-lbl">PROBABLE CASE</div>
            <p class="sc-dm-def-text">{{ selectedDiseaseModal.who_def.probable }}</p>
          </div>
          <div v-if="selectedDiseaseModal.who_def.confirmed" class="sc-dm-section sc-dm-section--who">
            <div class="sc-dm-section-lbl">CONFIRMED CASE</div>
            <p class="sc-dm-def-text">{{ selectedDiseaseModal.who_def.confirmed }}</p>
          </div>
          <div v-if="selectedDiseaseModal.who_def.poe_action" class="sc-dm-section">
            <div class="sc-dm-section-lbl">POE ACTION REQUIRED</div>
            <div class="sc-dm-poe-action">{{ selectedDiseaseModal.who_def.poe_action }}</div>
          </div>
          <div v-if="selectedDiseaseModal.who_def.source" class="sc-dm-source">
            Source: {{ selectedDiseaseModal.who_def.source }}<span v-if="selectedDiseaseModal.idsr_source_ref"> · IDSR ref {{ selectedDiseaseModal.idsr_source_ref }}</span>
          </div>
        </template>
        <div v-else class="sc-dm-section">
          <div class="sc-dm-section-lbl">WHO CASE DEFINITION</div>
          <p class="sc-dm-def-text sc-dm-def-text--muted">
            No WHO case definition available in the intelligence layer for this code.
          </p>
        </div>

        <div style="height:48px" aria-hidden="true"/>
      </div>
    </IonContent>
  </IonModal>

  <!-- Reusable doc-scan modal — Passport / National ID -->
  <DocScanModal
    v-model:open="docScanOpen"
    title="Scan passport or national ID"
    @result="onDocScanResult"
  />

  <!-- WHO Symptom Info Modal (Task 2) -->
  <IonModal
    :is-open="!!symptomInfoModal"
    @ionModalDidDismiss="symptomInfoModal = null"
  >
    <div class="sc-syminfo-wrap" role="dialog" aria-modal="true" :aria-labelledby="symptomInfoModal ? 'sc-syminfo-title' : null">
      <div class="sc-syminfo-hdr">
        <h2 id="sc-syminfo-title" class="sc-syminfo-title">{{ symptomInfoModal && symptomInfoModal.label }}</h2>
        <button type="button" class="sc-syminfo-close" aria-label="Close" @click="symptomInfoModal = null">×</button>
      </div>
      <div class="sc-syminfo-body" v-if="symptomInfoModal">
        <div v-if="symptomInfoModal.info">
          <section v-if="symptomInfoModal.info.definition" class="sc-syminfo-section">
            <div class="sc-syminfo-h3">{{ t('Definition') }}</div>
            <p>{{ symptomInfoModal.info.definition }}</p>
          </section>
          <section v-if="symptomInfoModal.info.clinical_signs && symptomInfoModal.info.clinical_signs.length" class="sc-syminfo-section">
            <div class="sc-syminfo-h3">{{ t('Clinical signs') }}</div>
            <ul>
              <li v-for="(line, i) in symptomInfoModal.info.clinical_signs" :key="i">{{ line }}</li>
            </ul>
          </section>
          <section v-if="symptomInfoModal.info.rash_distribution" class="sc-syminfo-section">
            <div class="sc-syminfo-h3">{{ t('Rash distribution') }}</div>
            <p>{{ symptomInfoModal.info.rash_distribution }}</p>
          </section>
          <section v-if="symptomInfoModal.info.progression" class="sc-syminfo-section">
            <div class="sc-syminfo-h3">{{ t('Progression') }}</div>
            <p>{{ symptomInfoModal.info.progression }}</p>
          </section>
          <section v-if="symptomInfoModal.info.differentiation" class="sc-syminfo-section">
            <div class="sc-syminfo-h3">{{ t('Differentiation') }}</div>
            <p>{{ symptomInfoModal.info.differentiation }}</p>
          </section>
          <section v-if="symptomInfoModal.info.who_source" class="sc-syminfo-section sc-syminfo-source">
            <div class="sc-syminfo-h3">{{ t('Source') }}</div>
            <p>{{ symptomInfoModal.info.who_source }}</p>
          </section>
        </div>
        <div v-else class="sc-syminfo-fallback">
          <p>Refer to WHO IDSR Technical Guidelines 2021 (3rd Edition) and the WHO Disease Fact Sheets for the standard case definition.</p>
        </div>
      </div>
      <div class="sc-syminfo-footer">
        <button type="button" class="sc-syminfo-btn-close" @click="symptomInfoModal = null">{{ t('Close') }}</button>
      </div>
    </div>
  </IonModal>

  </IonPage>
</template>


<script setup>
// ═══════════════════════════════════════════════════════════════════════════
// SecondaryScreening.vue
// Route:  /secondary-screening/:notificationUuid
// Roles:  POE_SECONDARY, POE_ADMIN
// Law:    poeDB.js is the ONLY data layer. No Dexie instantiation here.
//         API has NO auth middleware — NO Authorization header ever.
//         Navigate back using server integer id where applicable.
// ═══════════════════════════════════════════════════════════════════════════

import { ref, computed, reactive, watch, nextTick, onMounted, onUnmounted, toRaw } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  IonPage, IonHeader, IonToolbar, IonTitle, IonContent, IonFooter,
  IonModal, IonButtons, IonButton, IonIcon,
  IonCard, IonCardHeader, IonCardTitle, IonCardContent,
  IonItem, IonLabel, IonToggle,
  onIonViewDidEnter, onIonViewWillLeave,
} from '@ionic/vue'
import * as keepAwake from '@/services/plugins/keepAwake'
// 2026-05-07: voice dictation removed app-wide — plugin uninstalled to shrink APK.
import { coachmark } from '@/services/tour'
import { closeOutline } from 'ionicons/icons'

import {
  dbPut, dbGet, safeDbPut,
  dbGetByIndex, dbReplaceAll, dbAtomicWrite,
  genUUID, isoNow, createRecordBase,
  STORE, SYNC, APP,
} from '@/services/poeDB'
import { hapticCritical, hapticWarning, hapticError, hapticSuccess } from '@/services/haptics'

// Reusable Passport / Boarding-pass scan modal. Self-contained: it owns
// its own UI state, calls into the same offline OCR + barcode services
// the Primary screen uses. We only handle the result mapping below.
import DocScanModal from '@/components/DocScanModal.vue'

// Reusable searchable dropdown — replaces every native <select> in this
// view so officers can search 200+ countries / 30 districts by typing,
// with full keyboard nav, ARIA, teleport, and outside-click-close.
import SearchableSelect from '@/components/SearchableSelect.vue'
import LangSwitcher from '@/components/LangSwitcher.vue'

// ── i18n (Change 15) — display-only Kinyarwanda + French toggle ─────────
// Stored values, payload keys, exposure/symptom codes are NEVER translated.
// Use synchronous static imports — Vite tree-shakes unused dicts at build time.
import { useI18n } from '@/i18n'
import { SYMPTOM_INFO_BUNDLE as _SYMPTOM_INFO_BUNDLE } from '@/i18n/symptomInfo.js'
const { t, setLang, currentLang } = useI18n()

// ─── IDB SERIALISATION HELPER ────────────────────────────────────────────
// IDB uses the structured clone algorithm which CANNOT clone Vue Proxy objects.
// Any value that came from ref(), reactive(), or computed() must be stripped
// of its Proxy wrapper before being passed to dbPut / dbReplaceAll / dbAtomicWrite.
// toPlain() handles this in ONE operation: toRaw strips the outer proxy,
// JSON round-trip deep-clones nested reactive objects into plain JS values.
// ALWAYS wrap every record or array passed to an IDB write with toPlain().
function toPlain(val) {
  return JSON.parse(JSON.stringify(toRaw(val)))
}
const route  = useRoute()
const router = useRouter()

// notificationUuid — the IDB primary key (client_uuid) of the notification.
// Read fresh inside _doInitPage(), never captured at setup scope.
const notificationUuid = ref('')
// Primary quick-symptom chips to auto-apply to secondary symptoms tab.
// Set when the primary screening record is loaded; applied after initSymptoms().
const _primaryChips = ref([])

// ─── AUTH ─────────────────────────────────────────────────────────────────
// Auth is read fresh inside every write handler — NEVER cached at module level
function getAuth() {
  return JSON.parse(sessionStorage.getItem('AUTH_DATA') ?? 'null') ?? {}
}

const auth = reactive(getAuth())



// ─── STATE ────────────────────────────────────────────────────────────────
const loading        = ref(true)
const notFound       = ref(false)
const poeMismatch    = ref(false)   // user's POE does not match the notification's POE
const saving         = ref(false)
const step           = ref(1)
// Tracks the highest step the officer has ever advanced past for this case.
// Audit SS-001: lets jumpToStep() allow forward jumps back to a step the
// user already saved, even after they tabbed back to an earlier step.
const maxStepReached = ref(1)

const notification      = ref(null)   // notifications record (IDB)
const primaryScreening  = ref(null)   // primary_screenings record (IDB)
const caseRecord        = ref(null)   // secondary_screenings record (IDB)
const caseUuid          = ref(null)   // client_uuid of the secondary case

// ─── STEP 1: PROFILE & TRAVEL ─────────────────────────────────────────────
const showInfantMonths = ref(false)

// ── DOB ↔ Age bi-directional helpers ─────────────────────────────────────
// Rule: entering either field always updates the other instantly.
// Both fields also write traveler_dob so the record is always in sync.
//
// Birth year → age: called by the birth-year input @change
// Age (years) → birth year: called by the age input @input
//
// Accuracy notes:
//   - When only the YEAR is known (no full DOB), we use Jan 1 as the assumed
//     birthday for the reverse calculation — this is conservative (could be
//     off by up to 1 year) but is the only option when month is unknown.
//   - When profile.traveler_dob already contains a full YYYY-MM-DD (from a
//     passport scan), we use the full date for the age calculation so the
//     birthday month/day is honoured.

function _ageFromFullDob(dobIso) {
  try {
    const d = new Date(dobIso)
    if (isNaN(d.getTime())) return null
    const now = new Date()
    let a = now.getFullYear() - d.getFullYear()
    const m = now.getMonth() - d.getMonth()
    if (m < 0 || (m === 0 && now.getDate() < d.getDate())) a--
    return a >= 0 ? a : null
  } catch { return null }
}

// Compute age in completed months between two dates (cross-year safe).
// Used by both birth-year and full-DOB pathways. Clamped ≥ 0.
function _ageMonthsBetween(birthDate, refDate) {
  if (!(birthDate instanceof Date) || isNaN(birthDate.getTime())) return null
  const r = refDate instanceof Date && !isNaN(refDate.getTime()) ? refDate : new Date()
  let months = (r.getFullYear() * 12 + r.getMonth()) - (birthDate.getFullYear() * 12 + birthDate.getMonth())
  // Subtract one month if the day-of-month hasn't reached the birth day.
  if (r.getDate() < birthDate.getDate()) months -= 1
  return months < 0 ? 0 : months
}

// Called when the birth-year input changes. Cross-year safe (Change 1).
function onBirthYearChange() {
  const year = Number(profile._birth_year)
  if (!year || year < 1900 || year > new Date().getFullYear()) return
  const now = new Date()

  // Prefer full DOB if already set (e.g. from passport scan).
  let months
  if (profile.traveler_dob && String(profile.traveler_dob).slice(0, 4) === String(year)) {
    const dobDate = new Date(profile.traveler_dob)
    months = _ageMonthsBetween(dobDate, now)
    if (months === null) months = (now.getFullYear() - year) * 12
  } else {
    // Year-only — assume January (conservative, may under-count by up to 11 mo).
    const jan1 = new Date(year, 0, 1)
    months = _ageMonthsBetween(jan1, now)
    if (!profile.traveler_dob) profile.traveler_dob = `${year}-01-01`
  }

  // 2026-05-07: integer years only — decimal sub-year precision is held
  // separately in `_age_months` for the infant case.
  const years = Math.max(0, Math.min(130, Math.floor(months / 12)))
  profile.traveler_age_years = years
  showInfantMonths.value = years < 1
  if (!showInfantMonths.value) profile._age_months = null
  else profile._age_months = months
}

// Called when the age input changes (@input fires on every keystroke).
// 2026-05-07: integer-only guards for age + year inputs.
// Block decimal-introducing keys at the keydown layer (mobile soft keyboards
// will offer numeric only when inputmode=numeric, but the period key may
// still be reachable on some IMEs). Truncate any decimal value that slips
// through the @input event to keep the model integer-clean.
const _DECIMAL_KEYS = ['.', ',', 'e', 'E', '+', '-']
function _blockDecimalKeys(ev) {
  if (ev && ev.key && _DECIMAL_KEYS.includes(ev.key)) ev.preventDefault()
}
function _truncIntField(field) {
  const v = profile[field]
  if (v === '' || v == null) { profile[field] = null; return }
  const n = Number(v)
  if (!isFinite(n)) { profile[field] = null; return }
  const t = Math.trunc(n)
  if (profile[field] !== t) profile[field] = t
}

function onAgeYearsChange() {
  // 2026-05-07: clamp to integer years.
  const raw = profile.traveler_age_years
  if (raw === '' || raw == null) return
  const age = Math.trunc(Number(raw))
  if (isNaN(age)) { profile.traveler_age_years = null; return }
  if (age < 0)   { profile.traveler_age_years = 0;    return }
  if (age > 130) { profile.traveler_age_years = 130;  return }
  if (profile.traveler_age_years !== age) profile.traveler_age_years = age

  const estimatedYear = new Date().getFullYear() - age
  profile._birth_year = estimatedYear

  // Only write traveler_dob if not already set from a passport scan.
  const existingYear = profile.traveler_dob
    ? parseInt(String(profile.traveler_dob).slice(0, 4), 10) : null
  if (!existingYear || existingYear !== estimatedYear) {
    profile.traveler_dob = `${estimatedYear}-01-01`
  }

  showInfantMonths.value = age < 1
  if (!showInfantMonths.value) profile._age_months = null
  else profile._age_months = Math.round(age * 12)
}

const profile = reactive({
  traveler_full_name:                '',
  traveler_gender:                   '',
  traveler_age_years:                null,
  _birth_year:                       null,   // UI-only helper — not saved to DB
  _age_months:                       null,   // UI-only helper for infants
  travel_document_type:              '',
  travel_document_number:            '',
  traveler_nationality_country_code: '',
  residence_country_code:            '',
  phone_number:                      '',
  journey_start_country_code:        '',
  conveyance_type:                   '',
  conveyance_identifier:             '',
  arrival_datetime_input:            '',   // datetime-local input → converted on save
  purpose_of_travel:                 '',
  destination_district_code:         '',
})

const travelCountries = ref([])  // array of { client_uuid, country_code, travel_role, arrival_date, departure_date }

// ─── Doc scan (Passport / Boarding-pass) ──────────────────────────────────
// The DocScanModal handles capture + OCR + barcode decoding offline. We
// receive a `result` event with kind='passport' or 'bcbp' and write only
// the fields that are currently EMPTY — never overwrite operator input.
const docScanOpen = ref(false)
function openDocScan() { docScanOpen.value = true }

/**
 * Convert ISO 3166-1 alpha-3 to alpha-2 using window.COUNTRIES (loaded by
 * src/countries.js at app boot). Returns '' when the lookup fails — never
 * throws. We accept both ISO3 (passport MRZ uses alpha-3) and 3-letter
 * IATA region codes (BCBP only gives 3-letter airport codes; we resolve
 * to a country via window.IATA / similar — handled separately by
 * services/bcbp.js → iataToCountry which already returns ISO2).
 */
function iso3ToIso2(iso3) {
  try {
    if (!iso3 || typeof iso3 !== 'string') return ''
    const norm = iso3.toUpperCase()
    const list = (typeof window !== 'undefined' && Array.isArray(window.COUNTRIES?.[0]))
      ? window.COUNTRIES[0]
      : (Array.isArray(window?.COUNTRIES) ? window.COUNTRIES : [])
    for (const c of list) {
      if (c?.code3 === norm) return c.code2 || ''
    }
    return ''
  } catch { return '' }
}

/** Only set the field if it is currently empty/null — never overwrite a
 *  human-typed value. Mirrors the applySentinel rule used elsewhere. */
function fillIfEmpty(key, value) {
  try {
    if (value === undefined || value === null || value === '') return false
    const cur = profile[key]
    if (cur === undefined || cur === null || cur === '' || cur === 0) {
      profile[key] = value
      return true
    }
    return false
  } catch { return false }
}

/**
 * Map the modal's result to profile.* fields.
 *
 * Covers every field the passport parser produces:
 *   name              → traveler_full_name
 *   sex               → traveler_gender
 *   nationality_iso3  → traveler_nationality_country_code (via iso3→iso2)
 *   passport_no       → travel_document_number
 *   format            → travel_document_type (TD1 = NATIONAL_ID, else PASSPORT)
 *   dob_iso           → _birth_year + traveler_age_years (+ showInfantMonths if ≤1 yr)
 *   nationality_iso3  → journey_start_country_code ONLY when it differs from RW
 *                       (proxy: traveller from another country = likely started there)
 *
 * fillIfEmpty() guarantees we never overwrite data the officer already typed.
 * Wrapped in try/catch so a malformed payload can never crash Step 1.
 */
function onDocScanResult(payload) {
  try {
    if (!payload || typeof payload !== 'object') return
    let touched = 0

    if (payload.kind === 'passport' && payload.doc) {
      const d = payload.doc

      // CHANGE 26: validate plausibility BEFORE writing — OCR with no MRZ band
      // returns plain-text fields with garbage values (e.g. random ISO3 from
      // visible text on the page). Only trust fields when they pass sanity
      // checks. Always trust the name (it's the only thing parsePlainName
      // returns when MRZ isn't found).
      const isMrzSource = d.format === 'TD1' || d.format === 'TD2' || d.format === 'TD3' || d.source === 'MRZ'

      // Name — always written if present (most reliable field)
      if (typeof d.name === 'string' && d.name) {
        if (fillIfEmpty('traveler_full_name', d.name.slice(0, 150))) touched++
      }

      // Gender — only trust when MRZ-extracted (parsePlainName never sets sex)
      if (isMrzSource && (d.sex === 'MALE' || d.sex === 'FEMALE')) {
        if (fillIfEmpty('traveler_gender', d.sex)) touched++
      }

      // Nationality — only trust when MRZ-extracted, and validate ISO3 maps cleanly
      const iso2 = isMrzSource ? iso3ToIso2(d.nationality_iso3) : null
      if (iso2 && /^[A-Z]{2}$/.test(iso2)) {
        if (fillIfEmpty('traveler_nationality_country_code', iso2)) touched++
      }

      // Document number + type — only when MRZ-extracted (otherwise garbage)
      // TD1 → National ID, TD2/TD3 → Passport
      if (isMrzSource && typeof d.passport_no === 'string' && d.passport_no &&
          /^[A-Z0-9]{4,15}$/i.test(d.passport_no.replace(/[<\s]/g, ''))) {
        const cleaned = d.passport_no.replace(/[<\s]/g, '').slice(0, 60)
        if (fillIfEmpty('travel_document_number', cleaned)) touched++
      }
      if (isMrzSource) {
        const docType = d.format === 'TD1' ? 'NATIONAL_ID'
                      : d.format === 'TD2' ? 'NATIONAL_ID'
                      : 'PASSPORT'
        if (fillIfEmpty('travel_document_type', docType)) touched++
      }

      // Date of birth → age — only when MRZ-extracted AND date is plausible
      if (isMrzSource && typeof d.dob_iso === 'string' && d.dob_iso) {
        const dobDate = new Date(d.dob_iso)
        const now = new Date()
        const minDob = new Date(now.getFullYear() - 130, now.getMonth(), now.getDate())
        const dobValid = !isNaN(dobDate.getTime()) && dobDate <= now && dobDate >= minDob
        if (dobValid) {
          // Use cross-year-safe age computation (Change 1).
          // Integer years only — matches onBirthYearChange. Sub-year precision
          // (infants) is held in `_age_months`; the input never displays a
          // decimal year. Fixes the year-of-birth → decimal bug.
          const months = _ageMonthsBetween(dobDate, now) ?? 0
          const ageYears = Math.max(0, Math.min(130, Math.floor(months / 12)))
          if (ageYears >= 0 && ageYears <= 130) {
            if (fillIfEmpty('traveler_age_years', ageYears)) touched++
            const year = dobDate.getFullYear()
            if (!profile._birth_year) { profile._birth_year = year; touched++ }
            if (!profile.traveler_dob) { profile.traveler_dob = d.dob_iso; touched++ }
            if (months < 12) {
              showInfantMonths.value = true
              profile._age_months = months
            }
          }
        }
      }

      // Journey start country: use nationality as a proxy ONLY if the traveller
      // is NOT a Ugandan citizen (most Uganda-to-Uganda travel has no "last country").
      if (iso2 && iso2 !== 'UG') {
        if (fillIfEmpty('journey_start_country_code', iso2)) touched++
      }

    } else if (payload.kind === 'bcbp') {
      if (typeof payload.name === 'string' && payload.name) {
        if (fillIfEmpty('traveler_full_name', payload.name.slice(0, 150))) touched++
      }
      // BCBP: origin country (ISO2) + flight number
      if (typeof payload.origin_country === 'string' && payload.origin_country.length === 2) {
        if (fillIfEmpty('journey_start_country_code', payload.origin_country)) touched++
      }
      if (payload.first_leg && typeof payload.first_leg === 'object') {
        const leg = payload.first_leg
        if (fillIfEmpty('conveyance_type', 'AIR')) touched++
        const flight = `${leg.carrier || ''}${leg.flight_number || ''}`.trim().toUpperCase()
        if (flight) {
          if (fillIfEmpty('conveyance_identifier', flight.slice(0, 40))) touched++
        }
      }
    }

    if (touched > 0) { try { hapticSuccess() } catch {} }
  } catch (err) {
    console.debug('[secondary-doc-scan] result mapping failed:', err?.message)
  }
}

// ─── STEP 2: SYMPTOMS ─────────────────────────────────────────────────────
// Full symptom inventory — all toggled YES/NO
// Initialised in initSymptoms() from SYMPTOM_GROUPS
const symptomsMap = reactive({})  // code → { symptom_code, is_present, onset_date, details }

const showVitals = ref(false)

const vitals = reactive({
  temperature_value:      null,
  temperature_unit:       'C',
  pulse_rate:             null,
  respiratory_rate:       null,
  bp_systolic:            null,
  bp_diastolic:           null,
  oxygen_saturation:      null,
  triage_category:        '',
  emergency_signs_present: 0,
  general_appearance:     '',
  syndrome_classification: '',
})

// IDSR Annex 1A "Also" cluster events. Officer-toggled flags routed into
// `clinical_context` and consumed by the event-scoring branch in
// scoreDiseases() (Diseases.js Phase-2). When any flag is on, the matching
// disease entry (cluster_of_deaths / cluster_similar_symptoms /
// public_health_event_unknown) scores 50 and surfaces in top_diagnoses.
const clusterFlags = reactive({
  cluster_deaths_in_community: false,
  cluster_similar_illness:     false,
  unusual_event_flag:          false,
})

// ── FEVER AUTO-DERIVE (mandate 2026-05-06 — strict XOR) ───────────────────
// Temperature drives EXACTLY ONE of {fever, high_fever}. Both are surfaced
// as locked readouts in the UI; there is no manual tick.
//   < 37.5°C        → both NO  (autoFeverIsPresent = 0, autoHighFever = 0)
//   37.5 – 38.9°C   → fever YES, high_fever NO
//   ≥ 39.0°C        → fever NO, high_fever YES   ← XOR boundary
//   temp blank      → both Unset (null)
const autoFeverIsPresent = computed(() => {
  const c = _tempCelsius()
  if (c === null) return null
  return (c >= 37.5 && c < 39.0) ? 1 : 0
})
const autoHighFeverIsPresent = computed(() => {
  const c = _tempCelsius()
  if (c === null) return null
  return c >= 39.0 ? 1 : 0
})

// 2026-05-06 — When fever variants were removed from the UI we still need to
// feed the disease engine differential signals. Auto-derive these from temp:
//   temp ≥ 39.0°C    → high_fever = 1     (else 0 if any temp set, null otherwise)
//   37.5 ≤ temp <38  → low_grade_fever = 1 (else 0 / null as above)
//   any temp         → sudden_onset_fever stays unset (no temporal signal here)
function _tempCelsius() {
  const v = vitals.temperature_value
  if (v === null || v === undefined || v === '') return null
  const num = Number(v)
  if (!Number.isFinite(num)) return null
  const unit = vitals.temperature_unit || 'C'
  return unit === 'F' ? (num - 32) * 5 / 9 : num
}
function _setSymptomGhost(code, isPresent) {
  if (!symptomsMap) return
  if (!symptomsMap[code]) {
    // ROOT-CAUSE FIX 2026-05-07: the lazy-init ghost MUST carry a client_uuid
    // and a secondary_screening_id. Without them, buildSymptomRecords yields
    // records with no IDB keyPath value → dbReplaceAll's obj.put() rejects →
    // the whole transaction aborts → officer sees "Failed to save symptoms".
    // This was the source of the unpredictable failures (only triggered when
    // the temperature watcher created the ghost — i.e. when officer edited
    // °C). All ghost fields now mirror the shape produced by initSymptoms().
    symptomsMap[code] = {
      client_uuid:            genUUID(),
      secondary_screening_id: caseUuid.value,
      symptom_code:           code,
      is_present:             isPresent,
      onset_date:             null,
      details:                null,
      sync_status:            SYNC.UNSYNCED,
    }
  } else {
    symptomsMap[code].is_present = isPresent
    // Defensive heal: if a previous bug left the entry without a UUID, fix.
    if (!symptomsMap[code].client_uuid) symptomsMap[code].client_uuid = genUUID()
    if (!symptomsMap[code].secondary_screening_id) symptomsMap[code].secondary_screening_id = caseUuid.value
  }
}

watch(
  () => [vitals.temperature_value, vitals.temperature_unit],
  () => {
    if (vitals.temperature_value != null && vitals.temperature_value !== '' && !vitals.temperature_unit) {
      vitals.temperature_unit = 'C'
    }
    if (!symptomsMap || !symptomsMap['fever']) return
    // 2026-05-06 strict XOR: only Fever (37.5–38.9) and High Fever (≥39.0)
    // are auto-set. low_grade_fever / sudden_onset_fever / very_high_fever
    // are no longer derived — UI surface limited to Fever / High Fever.
    symptomsMap['fever'].is_present = autoFeverIsPresent.value
    _setSymptomGhost('high_fever', autoHighFeverIsPresent.value)
  },
  { immediate: false }
)

// ─── STEP 3: EXPOSURES ─────────────────────────────────────────────────────
// Driven by window.EXPOSURES catalog (exposures.js). Each exposure entry has:
//   exposure_code — the DB code (PK in secondary_exposures.exposure_code)
//   response      — YES / NO / UNKNOWN
//   details       — free text from officer
// window.EXPOSURES.mapToEngineCodes() translates these to engine codes
// for scoreDiseases(). No mapping logic in this Vue file.
const exposuresMap = reactive({})  // keyed by exposure_code

function initExposuresFromCatalog() {
  // Executive directive 2026-05-05: when the officer enters NOTHING, exposures
  // persist as an EMPTY set, not as a wall of "Unknown" rows. The map starts
  // empty; entries are added only when the officer explicitly clicks
  // Yes / No / Unknown for that exposure. UNKNOWN remains a valid clinical
  // answer, but only when the officer explicitly chose it.
  const catalog = window.EXPOSURES?.getAll() || []
  L.info('initExposuresFromCatalog: catalog ready (' + catalog.length + ' exposures) — map starts empty')
}

function setExposureResponse(code, response) {
  const current = exposuresMap[code]
  if (current && current.response === response) {
    // Toggle off — remove the entry entirely so it never persists as a
    // synthesised "Unknown" the officer didn't actually record.
    delete exposuresMap[code]
    return
  }
  exposuresMap[code] = { exposure_code: code, response: response, details: current?.details ?? null }
}

const allExposures = computed(() => window.EXPOSURES?.getAll() || [])
const exposuresByCategory = computed(() => window.EXPOSURES?.getCategoryGroups() || {})
const exposureCategoryKeys = computed(() => Object.keys(exposuresByCategory.value))
const EXPOSURE_CATEGORY_LABELS = window.EXPOSURES?.CATEGORY_LABELS || {}

const yesExposureCount = computed(() =>
  Object.values(exposuresMap).filter(e => e.response === 'YES').length
)

const engineExposureCodes = computed(() => {
  const records = Object.values(exposuresMap)
  return window.EXPOSURES?.mapToEngineCodes(records) || []
})

const highRiskSignals = computed(() => {
  const records = Object.values(exposuresMap)
  return window.EXPOSURES?.getHighRiskSignals(records) || []
})


// ─── STEP 4: ANALYSIS & DISPOSITION ──────────────────────────────────────
const analysisResult         = ref(null)  // current (may be officer re-run)
const originalAnalysisResult = ref(null)  // RD-002: the pre-override algorithm result; restore when officer clears
const clinicalReport  = ref(null) // structured clinical report
const reportExpanded  = ref(null) // expanded section
const suspectedDiseases = ref([])   // built from top_diagnoses → secondary_suspected_diseases rows

const caseDecision = reactive({
  syndrome_classification:   '',
  risk_level:                '',
  final_disposition:         '',
  officer_notes:             '',
  followup_required:         true,        // ALWAYS ON — see _enforceFollowupOn
  followup_assigned_level:   'DISTRICT',  // server fans out to PHEOC + NATIONAL
})

// Follow-up is ALWAYS ON (2026-05-08). The manual toggle was removed from
// the UI; every secondary case routes to District + PHEOC + National via the
// existing wire contract (followup_assigned_level='DISTRICT' fans out
// server-side). The watcher pins both fields and re-asserts them on any
// model touch so a future edit cannot turn follow-up off.
function _enforceFollowupOn() {
  if (caseDecision.followup_required !== true) caseDecision.followup_required = true
  if (caseDecision.followup_assigned_level !== 'DISTRICT') caseDecision.followup_assigned_level = 'DISTRICT'
}
watch(
  () => [caseDecision.followup_required, caseDecision.followup_assigned_level, suspectedDiseases.value],
  _enforceFollowupOn,
  { deep: true, immediate: true }
)

const actions = ref([])  // array of { client_uuid, secondary_screening_id, action_code, is_done, details }

const fieldErrors = reactive({
  // Step 1 — bio (required for any submission)
  traveler_full_name:                '',
  traveler_gender:                   '',
  traveler_age_or_dob:               '',
  traveler_nationality_country_code: '',
  // Step 4 — disposition
  syndrome_classification: '',
  risk_level:              '',
  final_disposition:       '',
  actions:                 '',
  officer_notes:           '',
})

// Bio gating — every submission requires a real traveller name + minimum bio.
// Used both as a guard inside saveStep1AndNext AND as a computed for the
// "Continue" button's disabled state so the officer can't even attempt to
// proceed with missing identity. Returns an array of human-readable problems
// (empty array means "OK to submit").
function bioProblems() {
  const problems = []
  const name = String(profile.traveler_full_name || '').trim()
  if (name.length < 2) problems.push('Traveller full name is required (minimum 2 characters).')
  if (profile.traveler_gender !== 'MALE' && profile.traveler_gender !== 'FEMALE') {
    problems.push('Traveller gender (Male or Female) is required.')
  }
  const age = profile.traveler_age_years
  const dob = profile._birth_year || profile.traveler_dob
  if (!age && !dob) problems.push('Age or date of birth is required.')
  if (!profile.traveler_nationality_country_code) problems.push('Nationality is required.')
  return problems
}

// Travel gating — added 2026-05-19 per "ALL critical travel information is
// mandatory". The minimum the surveillance officer must capture before
// moving on: arrival datetime, conveyance type, conveyance identifier, and
// journey-start country. Without these the case lacks "WHEN, HOW, WHERE"
// — the international epidemiology bare minimum.
function travelProblems() {
  const problems = []
  if (!profile.arrival_datetime_input)   problems.push('Arrival date & time is required.')
  if (!profile.conveyance_type)          problems.push('Conveyance type (Flight, Vehicle, etc.) is required.')
  // Only AIR/SEA render the identifier input — requiring it for LAND/OTHER
  // trapped officers in a state with no UI control to satisfy the rule.
  if ((profile.conveyance_type === 'AIR' || profile.conveyance_type === 'SEA')
      && (!profile.conveyance_identifier || String(profile.conveyance_identifier).trim().length < 1)) {
    problems.push(profile.conveyance_type === 'AIR' ? 'Flight number is required.' : 'Vessel name is required.')
  }
  if (!profile.journey_start_country_code) problems.push('Journey-start country is required.')
  return problems
}

function step1Problems() { return [...bioProblems(), ...travelProblems()] }
// Reactive list used by the footer panel + Next-button tooltip so the
// officer can see exactly which fields are blocking advance. Wrapping in
// a computed (not just calling step1Problems() from the template) keeps
// it reactive to every profile/* change without manual re-evaluation.
const step1ProblemList = computed(() => step1Problems())
const step1Valid = computed(() => step1ProblemList.value.length === 0)

// Step 2 — symptoms are MANDATORY. The officer must have explicitly
// assessed at least one symptom (YES or NO). Unrecorded (null) does
// not count. This blocks the "skip-through" pattern where an officer
// would advance to exposures without engaging the symptom panel.
function step2Problems() {
  const assessed = Object.values(symptomsMap).filter(s => s && s.is_present !== null && s.is_present !== undefined).length
  return assessed >= 1 ? [] : ['Assess at least one symptom before continuing (mark a symptom Yes or No).']
}
const step2Valid = computed(() => step2Problems().length === 0)

// Step 3 — exposures are MANDATORY. The officer must have explicitly
// answered (YES / NO / UNKNOWN counts as engaged — UNKNOWN is a real
// epidemiological response) at least one exposure question.
function step3Problems() {
  const answered = Object.values(exposuresMap).filter(e => e && (e.response === 'YES' || e.response === 'NO' || e.response === 'UNKNOWN')).length
  return answered >= 1 ? [] : ['Answer at least one exposure question before continuing.']
}
const step3Valid = computed(() => step3Problems().length === 0)

// ─── REFERENCE DATA ───────────────────────────────────────────────────────
// Uganda at the top, then East African Community neighbours, then alphabetical.
// Request 14: prioritise high-frequency selections; add search in template.
// Uganda deployment: host country first, then EAC neighbours in
// geographic-proximity order. Rwanda is kept because cross-border land
// arrivals are common but it's no longer the first item.
const EA_PRIORITY = ['UG','KE','TZ','RW','BI','SS','CD','ET']
const COUNTRY_LIST = computed(() => {
  try {
    const raw = window.COUNTRIES?.[0] ?? window.COUNTRIES ?? []
    const all = raw.map(c => ({ code2: c.code2, name: c.name }))
    const priority = all
      .filter(c => EA_PRIORITY.includes(c.code2))
      .sort((a, b) => EA_PRIORITY.indexOf(a.code2) - EA_PRIORITY.indexOf(b.code2))
    const rest = all
      .filter(c => !EA_PRIORITY.includes(c.code2))
      .sort((a, b) => a.name.localeCompare(b.name))
    return [...priority, { code2: '__sep__', name: '─────────────' }, ...rest]
  } catch {
    return []
  }
})
// Country search is now handled internally by SearchableSelect components.

// ── DESTINATION DISTRICT CATALOG ────────────────────────────────────────
// Built from window.POE_MAIN.administrative_groups — the live POE bundle
// from the tenant's server. Despite the legacy name baggage, this is NOT
// hardcoded Rwanda data: in the Uganda deployment the bundle ships Uganda
// districts; in Rwanda it ships Rwandan districts; etc. Two shapes are
// exposed:
//   • DESTINATION_DISTRICT_GROUPS — array of { label: PHEOC, districts:[…] }
//     used by the native <optgroup>-based <select>.
//   • DESTINATION_DISTRICT_LIST   — flat alphabetised array (kept for any
//     existing consumer; safe to remove later).
// Reactive on `poe-main-updated` so the dropdown self-heals when the
// async server bundle refresh resolves after Step 1 is already mounted.
const _poeBundleVersion = ref(0)
if (typeof window !== 'undefined') {
  window.addEventListener('poe-main-updated', () => { _poeBundleVersion.value++ })
}
function _readPoeRoot() {
  try {
    return (typeof window !== 'undefined' &&
            (window.POE_MAIN || (Array.isArray(window.POEs) ? window.POEs[0] : window.POEs))) || {}
  } catch { return {} }
}
const DESTINATION_DISTRICT_GROUPS = computed(() => {
  void _poeBundleVersion.value
  const groups = _readPoeRoot().administrative_groups || []
  return groups.map(g => ({
    label:     String(g.admin_level_1 || g.country || 'Region'),
    districts: Array.isArray(g.districts) ? g.districts.slice().sort((a, b) => a.localeCompare(b)) : [],
  })).filter(g => g.districts.length > 0)
})
const DESTINATION_DISTRICT_LIST = computed(() => {
  const all = []
  for (const grp of DESTINATION_DISTRICT_GROUPS.value) {
    for (const d of grp.districts) all.push({ code: d, label: d })
  }
  all.sort((a, b) => a.code.localeCompare(b.code))
  return all
})
// Back-compat aliases — kept so any other file referring to the legacy
// names doesn't break before the next build. Remove once
// `grep -rn RWANDA_DISTRICT` is empty across the repo.
const RWANDA_DISTRICT_GROUPS = DESTINATION_DISTRICT_GROUPS
const RWANDA_DISTRICT_LIST   = DESTINATION_DISTRICT_LIST

const autoDistrictApplied = ref(false)

// Auto-populate destination district from the linked POE's district. Never
// overwrites officer-entered values. Silent on failure (officer can pick).
function autoPopulateDestinationDistrict() {
  try {
    if (profile.destination_district_code) return
    const auth = getAuth()
    const userPoeCode = auth?.poe_code || notification.value?.poe_code || primaryScreening.value?.poe_code || null
    if (!userPoeCode) return
    const root = (typeof window !== 'undefined' && (window.POE_MAIN || (Array.isArray(window.POEs) ? window.POEs[0] : window.POEs))) || {}
    const poes = root.poes || []
    const match = poes.find(p =>
      p.poe_code === userPoeCode || p.id === userPoeCode || p.poe_name === userPoeCode
    )
    if (match && match.district) {
      profile.destination_district_code = match.district
      autoDistrictApplied.value = true
    }
  } catch (_) { /* non-blocking */ }
}

// ── AGE DISPLAY HINT (Change 1 — "N months", "1 year N months", "N years") ──
function ageDisplayLabel(years) {
  if (years === null || years === undefined || !Number.isFinite(Number(years)) || Number(years) < 0) return ''
  const y = Number(years)
  const totalMonths = Math.round(y * 12)
  if (totalMonths < 12) return `${totalMonths} month${totalMonths === 1 ? '' : 's'} old`
  if (totalMonths < 24) {
    const m = totalMonths - 12
    if (m === 0) return '1 year old'
    return `1 year ${m} month${m === 1 ? '' : 's'} old`
  }
  return `${Math.floor(y)} year${Math.floor(y) === 1 ? '' : 's'} old`
}
const ageDisplayHint = computed(() => ageDisplayLabel(profile.traveler_age_years))

// Tap-handler used by infant month grid — writes a 1-decimal year value
// (1 mo = 0.1, 2 mo = 0.2, …, 12 mo = 1.0). The 1-decimal granularity is
// what the screener UI surfaces; the underlying conversion is m/12, then
// rounded to 1 decimal place so the input never shows trailing junk like
// "0.083" or "0.12".
function onAgeMonthsChange(m) {
  if (typeof m !== 'number' || m < 0 || m > 12) return
  profile._age_months = m
  // 2 decimals so each month is distinct (1mo=0.08 … 4mo=0.33 … 11mo=0.92).
  // Previously 1-decimal rounding collided 3mo+4mo at 0.3.
  // 12 months → exactly 1 (whole year).
  profile.traveler_age_years = m >= 12 ? 1 : Math.round((m / 12) * 100) / 100
}

// ── DISEASE → SYNDROME MAP (Change 21 — officer override syndrome correctness) ──
// When the officer declares a suspected disease, the syndrome must reflect the
// disease's clinical pattern, not the symptom-only engine derivation. This map
// is applied in rerunAnalysisWithOfficerDiseases() to override the syndrome
// when the officer has named a specific disease.
const DISEASE_TO_SYNDROME_MAP = Object.freeze({
  // VHF family — all map to acute haemorrhagic fever syndrome
  ebola_virus_disease:           'ACUTE_HAEMORRHAGIC_FEVER',
  marburg_virus_disease:         'ACUTE_HAEMORRHAGIC_FEVER',
  lassa_fever:                   'ACUTE_HAEMORRHAGIC_FEVER',
  cchf:                          'ACUTE_HAEMORRHAGIC_FEVER',
  rift_valley_fever:             'ACUTE_HAEMORRHAGIC_FEVER',
  hantavirus:                    'ACUTE_HAEMORRHAGIC_FEVER',
  dengue_severe:                 'ACUTE_HAEMORRHAGIC_FEVER',
  dengue:                        'ACUTE_VECTOR_BORNE',
  yellow_fever:                  'ACUTE_JAUNDICE_SYNDROME',

  // Respiratory
  influenza_new_subtype_zoonotic:'SEVERE_ACUTE_RESPIRATORY_INFECTION',
  influenza_seasonal:            'ACUTE_RESPIRATORY_SYNDROME',
  sars:                          'SEVERE_ACUTE_RESPIRATORY_INFECTION',
  mers:                          'SEVERE_ACUTE_RESPIRATORY_INFECTION',
  covid_19:                      'ACUTE_RESPIRATORY_SYNDROME',
  pneumonic_plague:              'SEVERE_ACUTE_RESPIRATORY_INFECTION',
  tuberculosis:                  'ACUTE_RESPIRATORY_SYNDROME',

  // Diarrhoeal
  cholera:                       'ACUTE_WATERY_DIARRHOEA',
  awd_non_cholera:               'ACUTE_WATERY_DIARRHOEA',
  shigellosis_dysentery:         'ACUTE_BLOODY_DIARRHOEA',
  typhoid_fever:                 'ACUTE_FEBRILE_ILLNESS',

  // Neurological
  meningococcal_meningitis:      'MENINGITIS_ENCEPHALITIS',
  nipah_virus:                   'MENINGITIS_ENCEPHALITIS',
  japanese_encephalitis:         'MENINGITIS_ENCEPHALITIS',
  west_nile_fever:               'MENINGITIS_ENCEPHALITIS',
  polio:                         'ACUTE_FLACCID_PARALYSIS',
  rabies:                        'ACUTE_NEUROLOGICAL_SYNDROME',

  // Rash / vaccine-preventable
  measles:                       'RASH_ILLNESS_FEVER',
  rubella:                       'RASH_ILLNESS_FEVER',
  mpox:                          'RASH_ILLNESS_FEVER',
  smallpox:                      'RASH_ILLNESS_FEVER',
  chickenpox:                    'RASH_ILLNESS_FEVER',

  // Hepatic
  hepatitis_a:                   'ACUTE_JAUNDICE_SYNDROME',
  hepatitis_b:                   'ACUTE_JAUNDICE_SYNDROME',
  hepatitis_c:                   'ACUTE_JAUNDICE_SYNDROME',
  hepatitis_e:                   'ACUTE_JAUNDICE_SYNDROME',

  // Vector-borne
  malaria_uncomplicated:         'ACUTE_VECTOR_BORNE',
  malaria_severe:                'ACUTE_VECTOR_BORNE',
  chikungunya:                   'ACUTE_VECTOR_BORNE',
  zika:                          'ACUTE_VECTOR_BORNE',

  // Zoonotic / animal-related
  brucellosis:                   'ZOONOTIC_EXPOSURE_ILLNESS',
  anthrax_cutaneous:             'ZOONOTIC_EXPOSURE_ILLNESS',
  anthrax_pulmonary:             'SEVERE_ACUTE_RESPIRATORY_INFECTION',
  leptospirosis:                 'ZOONOTIC_EXPOSURE_ILLNESS',
  bubonic_plague:                'ZOONOTIC_EXPOSURE_ILLNESS',
  rickettsia_scrub_typhus:       'ZOONOTIC_EXPOSURE_ILLNESS',
  tularemia:                     'ZOONOTIC_EXPOSURE_ILLNESS',

  // IDSR Phase-2 additions — epidemic-prone
  epidemic_typhus:               'ACUTE_FEBRILE_ILLNESS',
  sari:                          'SEVERE_ACUTE_RESPIRATORY_INFECTION',

  // IDSR Phase-2 additions — eradication / elimination
  dracunculiasis:                'CUTANEOUS_LESION_SYNDROME',
  leprosy:                       'CUTANEOUS_LESION_SYNDROME',
  lymphatic_filariasis:          'HELMINTHIC_INFESTATION',
  neonatal_tetanus:              'ACUTE_NEUROLOGICAL_SYNDROME',
  onchocerciasis:                'CUTANEOUS_LESION_SYNDROME',
  yaws:                          'CUTANEOUS_LESION_SYNDROME',

  // IDSR Phase-2 additions — other major public health
  dog_bite_rabies_exposure:      'ZOONOTIC_EXPOSURE_ILLNESS',
  foodborne_illness:             'FOODBORNE_ILLNESS',
  maternal_death:                'UNEXPLAINED_DEATH',
  perinatal_death:               'UNEXPLAINED_DEATH',
  under_five_death:              'UNEXPLAINED_DEATH',
  schistosomiasis_urinary:       'HELMINTHIC_INFESTATION',
  schistosomiasis_intestinal:    'ACUTE_BLOODY_DIARRHOEA',
  severe_pneumonia_under5:       'SEVERE_ACUTE_RESPIRATORY_INFECTION',
  ascariasis:                    'HELMINTHIC_INFESTATION',
  trichuriasis:                  'ACUTE_BLOODY_DIARRHOEA',
  ancylostomiasis:               'HELMINTHIC_INFESTATION',
  strongyloidiasis:              'HELMINTHIC_INFESTATION',
  trachoma:                      'CUTANEOUS_LESION_SYNDROME',
  trypanosomiasis:               'ACUTE_NEUROLOGICAL_SYNDROME',

  // IDSR Phase-2 additions — cluster events
  cluster_of_deaths:             'UNEXPLAINED_DEATH',
  cluster_similar_symptoms:      'CLUSTER_OUTBREAK_SIGNAL',
  public_health_event_unknown:   'PUBLIC_HEALTH_EVENT_UNUSUAL',
})

function syndromeFromDeclaredDisease(diseaseId) {
  return DISEASE_TO_SYNDROME_MAP[diseaseId] || null
}

// 1-M: 5-step flow — Analysis split into "Analysis Review" + "Disposition"
const STEPS = [
  { key: 'profile',     label: 'Profile' },
  { key: 'symptoms',    label: 'Symptoms' },
  { key: 'exposure',    label: 'Exposures' },
  { key: 'analysis',    label: 'Analysis' },
  { key: 'disposition', label: 'Disposition' },
]

const GENDERS = [
  { value: 'MALE',    label: 'Male' },
  { value: 'FEMALE',  label: 'Female' },
]

const DOC_TYPES = [
  { value: 'PASSPORT',       label: 'Passport' },
  { value: 'NATIONAL_ID',    label: 'National ID' },
  { value: 'LAISSEZ_PASSER', label: 'Laissez-Passer' },
  { value: 'OTHER',          label: 'Other' },
]

const CONVEYANCE_TYPES = [
  { value: 'LAND', label: 'Land' },
  { value: 'AIR',  label: 'Air' },
  { value: 'SEA',  label: 'Sea' },
  { value: 'OTHER', label: 'Other' },
]

const TRIAGE_CATS = [
  { value: 'NON_URGENT', label: 'Non-Urgent', sub: 'Stable' },
  { value: 'URGENT',     label: 'Urgent',     sub: 'Needs care' },
  { value: 'EMERGENCY',  label: 'Emergency',  sub: 'Immediate' },
]

// Syndrome codes — Uganda IDSR full priority syndrome list (revised 2026-05-08).
// Every Uganda IDSR Table 1 priority disease maps to at least one syndrome here,
// so 'No syndromic classification' never needs to be selected.
const SYNDROMES = [
  { code: 'ACUTE_FEBRILE_ILLNESS', name: 'Acute febrile illness', description: 'Fever ≥ 38°C with no immediately obvious localising signs; onset < 2 weeks.', danger: false },
  { code: 'ACUTE_HAEMORRHAGIC_FEVER', name: 'Acute haemorrhagic fever syndrome', description: 'Fever + unexplained haemorrhage from any site; meets IDSR Annex 1A AHF case definition.', danger: true },
  { code: 'ACUTE_RESPIRATORY_SYNDROME', name: 'Acute respiratory syndrome', description: 'Fever + cough or breathing difficulty; includes COVID-19 and influenza-like presentations.', danger: false },
  { code: 'SEVERE_ACUTE_RESPIRATORY_INFECTION', name: 'Severe acute respiratory infection (SARI)', description: 'Acute respiratory infection with fever requiring hospitalisation.', danger: true },
  { code: 'ACUTE_WATERY_DIARRHOEA', name: 'Acute watery diarrhoea', description: '≥ 3 loose/watery stools in 24 hours; includes cholera-like presentations and AWD <5y.', danger: false },
  { code: 'ACUTE_BLOODY_DIARRHOEA', name: 'Acute bloody diarrhoea', description: 'Diarrhoea with visible blood; includes Shigellosis-compatible presentations.', danger: false },
  { code: 'ACUTE_JAUNDICE_SYNDROME', name: 'Acute jaundice syndrome', description: 'Yellow discolouration of skin or sclera with fever; includes Yellow Fever and viral hepatitis.', danger: false },
  { code: 'ACUTE_NEUROLOGICAL_SYNDROME', name: 'Acute neurological syndrome', description: 'Fever with altered consciousness, seizures, meningismus, or focal neurological signs.', danger: true },
  { code: 'ACUTE_FLACCID_PARALYSIS', name: 'Acute flaccid paralysis', description: 'Sudden onset of limb weakness or paralysis; includes polio-compatible presentations.', danger: true },
  { code: 'RASH_ILLNESS_FEVER', name: 'Rash illness with fever', description: 'Fever + generalised rash; includes measles, rubella, smallpox.', danger: false },
  { code: 'RESPIRATORY_ILLNESS_NON_SEVERE', name: 'Non-severe respiratory illness (ILI)', description: 'Influenza-like illness; cough, coryza, sore throat with mild fever.', danger: false },
  { code: 'ACUTE_VECTOR_BORNE', name: 'Acute vector-borne febrile illness', description: 'Fever with history of mosquito/tick/sandfly bite; includes malaria, dengue, chikungunya, Rift Valley Fever, Zika.', danger: false },
  { code: 'FOODBORNE_ILLNESS', name: 'Acute foodborne illness', description: 'Nausea, vomiting, diarrhoea, abdominal cramps within 72 hours of suspect food/water; includes typhoid-compatible.', danger: false },
  { code: 'ZOONOTIC_EXPOSURE_ILLNESS', name: 'Illness following zoonotic exposure', description: 'Fever or wound after direct animal contact, bite, slaughter; includes brucellosis, plague, anthrax, rabies exposure.', danger: false },
  { code: 'MENINGITIS_ENCEPHALITIS', name: 'Meningitis / encephalitis', description: 'Fever + severe headache + neck stiffness and/or altered consciousness; includes bacterial meningitis.', danger: true },
  { code: 'UNEXPLAINED_DEATH', name: 'Unexplained death — public-health concern', description: 'Death of unknown cause meeting IHR reporting threshold; includes maternal, perinatal, and under-five deaths under surveillance.', danger: true },
  { code: 'CUTANEOUS_LESION_SYNDROME', name: 'Cutaneous / mucosal lesion syndrome', description: 'Skin or eye lesion under surveillance — anthrax cutaneous, leprosy, yaws, onchocerciasis nodule, trachoma, dracunculiasis worm emergence.', danger: false },
  { code: 'HELMINTHIC_INFESTATION', name: 'Helminthic infestation', description: 'Parasitic-worm presentation under surveillance — schistosomiasis, soil-transmitted helminths, lymphatic filariasis.', danger: false },
  { code: 'CLUSTER_OUTBREAK_SIGNAL', name: 'Cluster / outbreak signal', description: 'A cluster of similar illness in the community, school, or workplace — early outbreak detection trigger.', danger: true },
  { code: 'PUBLIC_HEALTH_EVENT_UNUSUAL', name: 'Public health event of national/international concern', description: 'IHR-notifiable unusual event — infectious, zoonotic, foodborne, chemical, radio-nuclear, or unknown aetiology.', danger: true },
]

// Legacy syndrome code shims — old engine codes mapped to new canonical codes.
const SYNDROME_LEGACY_SHIMS = {
  ILI: 'RESPIRATORY_ILLNESS_NON_SEVERE',
  SARI: 'SEVERE_ACUTE_RESPIRATORY_INFECTION',
  AWD: 'ACUTE_WATERY_DIARRHOEA',
  BLOODY_DIARRHEA: 'ACUTE_BLOODY_DIARRHOEA',
  VHF: 'ACUTE_HAEMORRHAGIC_FEVER',
  RASH_FEVER: 'RASH_ILLNESS_FEVER',
  JAUNDICE: 'ACUTE_JAUNDICE_SYNDROME',
  NEUROLOGICAL: 'ACUTE_NEUROLOGICAL_SYNDROME',
  MENINGITIS: 'MENINGITIS_ENCEPHALITIS',
  NO_SYNDROME: 'ACUTE_FEBRILE_ILLNESS',
  OTHER: 'ACUTE_FEBRILE_ILLNESS',
  NONE:  'ACUTE_FEBRILE_ILLNESS',
}

function normalizeSyndromeCode(code) {
  if (!code) return ''
  if (Object.prototype.hasOwnProperty.call(SYNDROME_LEGACY_SHIMS, code)) {
    return SYNDROME_LEGACY_SHIMS[code]
  }
  return code
}

const syndromeSearchQuery = ref('')
const filteredSyndromes = computed(() => {
  const q = syndromeSearchQuery.value.trim().toLowerCase()
  if (!q) return SYNDROMES
  return SYNDROMES.filter(s =>
    s.name.toLowerCase().includes(q) ||
    s.code.toLowerCase().includes(q) ||
    s.description.toLowerCase().includes(q)
  )
})

const RISK_LEVELS = [
  { value: 'LOW',      label: 'Low',      sub: 'Monitor' },
  { value: 'MEDIUM',   label: 'Medium',   sub: 'Investigate' },
  { value: 'HIGH',     label: 'High',     sub: 'Isolate' },
  { value: 'CRITICAL', label: 'Critical', sub: 'Emergency' },
]

const DISPOSITIONS = [
  { value: 'RELEASED_NO_CONDITION', label: 'Released — no public-health concern', sub: 'No case definition met', icon: '<svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><polyline points="2 7 5.5 10.5 12 4"/></svg>' },
  { value: 'RELEASED_UNDER_FOLLOWUP', label: 'Released — under public-health follow-up', sub: 'Suspect; insufficient for isolation', icon: '<svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="7" cy="7" r="5"/><polyline points="7 4 7 7 9 9"/></svg>' },
  { value: 'REFERRED_HEALTH_FACILITY', label: 'Referred to health facility', sub: 'Requires further assessment', icon: '<svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M1 7h10M7 3l4 4-4 4"/></svg>' },
  { value: 'ISOLATED_ADMITTED', label: 'Isolated / admitted for case management', sub: 'Confirmed or high-probability suspect', icon: '<svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="7" cy="7" r="5"/><line x1="7" y1="4" x2="7" y2="10"/></svg>' },
  { value: 'RETURN_TO_ORIGIN', label: 'Traveller returning to their country', sub: 'IHR Article 31 — repatriation / refused entry', icon: '<svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M11 7H3M5 4l-3 3 3 3"/></svg>' },
  { value: 'DECEASED_AT_POE', label: 'Deceased at point of entry', sub: 'IHR Article 5 notification', icon: '<svg viewBox="0 0 14 14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="7" cy="7" r="5"/><line x1="4" y1="4" x2="10" y2="10"/></svg>' },
]

// Legacy disposition shims for IDB-stored old codes
const DISPOSITION_LEGACY_SHIMS = {
  RELEASED: 'RELEASED_NO_CONDITION',
  DELAYED: 'RELEASED_UNDER_FOLLOWUP',
  QUARANTINED: 'ISOLATED_ADMITTED',
  ISOLATED: 'ISOLATED_ADMITTED',
  REFERRED: 'REFERRED_HEALTH_FACILITY',
  TRANSFERRED: 'REFERRED_HEALTH_FACILITY',
  DENIED_BOARDING: 'REFERRED_HEALTH_FACILITY',
  OTHER: 'RELEASED_UNDER_FOLLOWUP',
}

// ────────────────────────────────────────────────────────────────────────────
// AI / engine-connected WHO / Africa CDC recommendation map
// ────────────────────────────────────────────────────────────────────────────
//
// Dynamic, computed against the engine's top diagnosis + IHR risk + syndrome.
// The map is curated per IHR 2005 Annex 2 + Africa CDC IDSR Technical Guide.
// Falls back to syndrome-based recommendations when the disease isn't in the
// disease-specific map. Empty array → panel hides itself.

const WHO_DISEASE_RECOMMENDATIONS = {
  // Tier-1 always-notifiable
  EVD:      [['Strict isolation in a designated treatment centre with PPE.', 'WHO VHF clinical care 2023'], ['Notify WHO within 24h via IHR National Focal Point.', 'IHR 2005 Article 6'], ['Initiate contact tracing and ring vaccination assessment.', 'WHO Ebola response framework']],
  MARBURG:  [['Strict isolation with full VHF PPE.', 'WHO VHF clinical care'], ['Sample to WHO collaborating centre with biosafety.', 'IHR Annex 2'], ['Contact tracing 21 days from last exposure.', 'WHO Marburg outbreak management']],
  CCHF:     [['Standard + droplet + contact precautions.', 'WHO CCHF guidance'], ['Ribavirin per national protocol.', 'WHO/Africa CDC IDSR'], ['Notify within 24h.', 'IHR Annex 2']],
  LASSA:    [['Isolation + PPE; ribavirin therapy if eligible.', 'WHO Lassa fever guidance'], ['Contact tracing 21 days.', 'WHO/IHR'], ['Notify NFP within 24h.', 'IHR Annex 2']],
  YELLOW_FEVER: [['Vector control around traveller residence.', 'WHO YF surveillance'], ['Confirm vaccination history; offer post-exposure if unvaccinated.', 'WHO IA2030'], ['Notify within 24h.', 'IHR Annex 2']],
  CHOLERA:  [['Initiate ORS / IV rehydration immediately.', 'WHO Cholera CMP'], ['Stool sample for culture / PCR.', 'WHO/Africa CDC IDSR'], ['Notify WHO within 24h; trigger WASH response.', 'IHR Annex 2']],
  PLAGUE:   [['Isolation with droplet precautions until 48h post-antibiotic.', 'WHO plague response'], ['Streptomycin or doxycycline per protocol.', 'WHO plague treatment'], ['Notify within 24h.', 'IHR Annex 2']],
  SARS:     [['Airborne + contact isolation in a negative-pressure room.', 'WHO IPC SARS-CoV'], ['Notify within 24h via IHR NFP.', 'IHR Annex 2'], ['Contact list & symptom monitoring 14 days.', 'WHO surveillance']],
  MERS:     [['Airborne + contact isolation; aerosol-generating procedure precautions.', 'WHO MERS IPC'], ['Notify within 24h.', 'IHR Annex 2'], ['Contact list 14 days.', 'WHO MERS surveillance']],
  COVID19:  [['Mask + isolation; offer testing per national protocol.', 'WHO COVID-19 IPC'], ['Notify if part of a flagged cluster.', 'IHR / national policy'], ['Symptom monitoring 14 days.', 'WHO COVID-19']],
  POLIO:    [['Stool specimen × 2 (24h apart) for poliovirus isolation.', 'WHO AFP surveillance'], ['Notify within 24h.', 'IHR Annex 2'], ['Vaccination status review; offer OPV if eligible.', 'GPEI']],
  SMALLPOX: [['Strict airborne + contact isolation; ring vaccination.', 'WHO smallpox response'], ['Notify within 24h via IHR NFP.', 'IHR Annex 2 (always-notifiable)']],
  H5N1:     [['Airborne + contact isolation; oseltamivir.', 'WHO HPAI clinical guidance'], ['Notify within 24h.', 'IHR Annex 2'], ['Trace exposure to poultry / animals.', 'WHO/FAO/OIE tripartite']],
  MEASLES:  [['Airborne isolation 4 days post-rash.', 'WHO measles IPC'], ['Confirm vaccination; offer MMR within 72h to contacts.', 'WHO measles outbreak SOP'], ['Notify per national policy.', 'WHO/IDSR']],
  MENINGOCOCCAL: [['Isolation 24h post-antibiotic.', 'WHO meningitis IPC'], ['Empiric ceftriaxone; LP as soon as safe.', 'WHO meningitis guideline'], ['Chemoprophylaxis for close contacts.', 'WHO/IDSR']],
  TYPHOID:  [['Stool / blood culture; ciprofloxacin or ceftriaxone per resistance.', 'WHO typhoid guideline'], ['WASH education; food handler exclusion.', 'WHO/IDSR'], ['Report per national surveillance.', 'IDSR']],
  MALARIA:  [['Rapid diagnostic test + thick film microscopy.', 'WHO malaria diagnosis'], ['Artemisinin-based combination therapy if confirmed.', 'WHO malaria treatment'], ['Vector exposure assessment.', 'WHO/IDSR']],
  TB:       [['Sputum × 2 (smear + GeneXpert) before any antibiotics.', 'WHO TB diagnosis'], ['Mask the patient; airborne isolation if smear-positive.', 'WHO TB IPC'], ['Notify per national TB programme.', 'WHO/IDSR']],
}

// Syndrome-level fallback when a specific disease isn't in the map above.
const WHO_SYNDROME_RECOMMENDATIONS = {
  VHF:                [['Strict isolation with VHF PPE; minimum-touch protocol.', 'WHO VHF clinical care'], ['Sample with biosafety; do NOT field-test.', 'WHO VHF lab'], ['Notify within 24h via IHR National Focal Point.', 'IHR Annex 2']],
  AWD:                [['Begin ORS immediately; IV fluids if Plan C.', 'WHO Cholera CMP'], ['Stool sample for cholera culture / PCR.', 'WHO/Africa CDC IDSR'], ['WASH assessment of conveyance + contacts.', 'WHO Cholera response']],
  BLOODY_DIARRHEA:    [['Stool culture for Shigella / EHEC; do NOT prescribe antimotility.', 'WHO IDSR'], ['ORS; antibiotics only after culture.', 'WHO diarrhoeal disease'], ['WASH + contact tracing.', 'WHO/IDSR']],
  ILI:                [['Mask + droplet precautions until aetiology known.', 'WHO IPC respiratory'], ['Sample for influenza / SARS-CoV-2 PCR.', 'WHO sentinel surveillance'], ['Symptom monitoring 7 days.', 'WHO IDSR']],
  SARI:               [['Airborne + droplet isolation; pulse oximetry; supplemental O₂ if SpO₂ < 94 %.', 'WHO SARI CMP'], ['Sample for influenza / SARS-CoV-2 / RSV.', 'WHO sentinel surveillance'], ['Notify clusters within 24h.', 'IHR / national']],
  RASH_FEVER:         [['Isolate to prevent measles transmission until aetiology known.', 'WHO measles IPC'], ['Serum + throat swab for measles / rubella / VHF as indicated.', 'WHO surveillance'], ['Vaccination history check; MMR for non-immune contacts.', 'WHO measles']],
  JAUNDICE:           [['Bloodborne precautions; suspect viral hepatitis or yellow fever.', 'WHO hepatitis IPC'], ['Sample for hepatitis A/E IgM, yellow fever IgM, leptospirosis if exposure.', 'WHO/IDSR'], ['Travel history for YF endemic zones.', 'CDC Yellow Book']]
,
  NEUROLOGICAL:       [['Empiric ceftriaxone if meningitis suspected, after LP if safe.', 'WHO meningitis guideline'], ['Sample CSF + blood; specific PCR per syndrome.', 'WHO meningitis lab'], ['Mass-vaccination assessment for meningococcal.', 'WHO meningitis SOP']],
  MENINGITIS:         [['Empiric ceftriaxone; LP as soon as safe.', 'WHO meningitis guideline'], ['Droplet isolation 24h post-antibiotic.', 'WHO meningitis IPC'], ['Chemoprophylaxis for close contacts.', 'WHO meningitis']],
  OTHER:              [['Isolate by transmission risk; document signs/symptoms.', 'WHO IPC'], ['Notify supervisor; case-by-case sample plan.', 'WHO/IDSR'], ['Schedule follow-up per IDSR 7-1-7.', 'Africa CDC IDSR']],
}

// Risk-level overlay — appended to disease/syndrome recommendations for
// HIGH/CRITICAL cases. Drives the ladder-of-notification aspect.
const WHO_RISK_OVERLAY = {
  HIGH:     [['Notify district health office and PHEOC within 24 hours.', 'IHR Annex 2 / IDSR']],
  CRITICAL: [['IMMEDIATE notification: District + PHEOC + National + WHO IHR NFP within 24 hours.', 'IHR 2005 Article 6'], ['Activate POE rapid response team and infection prevention SOPs.', 'WHO POE Core Capacities']],
}

// 2026-05-07 — Action-code plans, per disease and per syndrome.
// These are the EXACT codes from the ACTIONS array below. The recommended-
// actions panel renders the matching button labels so the officer reads the
// same wording above (recommendation) and below (record) — no translation,
// no ambiguity, court-defensible.
//
// Order within each list = order recommended for the officer (urgency first).
// Risk-tier overlay below adds escalation codes for HIGH/CRITICAL cases.
const ACTION_PLAN_BY_DISEASE = {
  // Tier-1 always-notifiable VHFs — full bundle (isolation first)
  EVD:           ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'CONTACT_TRACING_INITIATED', 'IHR_NOTIFICATION_INITIATED'],
  MARBURG:       ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'CONTACT_TRACING_INITIATED', 'IHR_NOTIFICATION_INITIATED'],
  CCHF:          ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'CONTACT_TRACING_INITIATED', 'IHR_NOTIFICATION_INITIATED'],
  LASSA:         ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'CONTACT_TRACING_INITIATED', 'IHR_NOTIFICATION_INITIATED'],
  PLAGUE:        ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'CONTACT_TRACING_INITIATED', 'IHR_NOTIFICATION_INITIATED'],
  H5N1:          ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'CONTACT_TRACING_INITIATED', 'IHR_NOTIFICATION_INITIATED'],
  // Always-notifiable, lab-driven, fluid management
  CHOLERA:       ['REFERRAL_HEALTH_FACILITY', 'SPECIMEN_COLLECTION', 'CASE_INVESTIGATION', 'IHR_NOTIFICATION_INITIATED'],
  YELLOW_FEVER:  ['CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'IHR_NOTIFICATION_INITIATED'],
  POLIO:         ['CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'IHR_NOTIFICATION_INITIATED'],
  SMALLPOX:      ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'CONTACT_TRACING_INITIATED', 'IHR_NOTIFICATION_INITIATED'],
  // Respiratory contagion
  SARS:          ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'CONTACT_TRACING_INITIATED', 'IHR_NOTIFICATION_INITIATED'],
  MERS:          ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'CONTACT_TRACING_INITIATED', 'IHR_NOTIFICATION_INITIATED'],
  COVID19:       ['ISOLATION_PRECAUTIONS', 'SPECIMEN_COLLECTION', 'CASE_INVESTIGATION', 'IHR_NOTIFICATION_INITIATED'],
  TB:            ['ISOLATION_PRECAUTIONS', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'IHR_NOTIFICATION_INITIATED'],
  // Vaccine-preventable / outbreak-prone
  MEASLES:       ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'CONTACT_TRACING_INITIATED', 'IHR_NOTIFICATION_INITIATED'],
  MENINGOCOCCAL: ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'CONTACT_TRACING_INITIATED', 'IHR_NOTIFICATION_INITIATED'],
  // Endemic enteric / vector-borne — investigation + lab + referral
  TYPHOID:       ['CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY'],
  MALARIA:       ['SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY'],
}

// Syndrome-level fallback when the engine can't pin a single disease.
const ACTION_PLAN_BY_SYNDROME = {
  VHF:             ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'CONTACT_TRACING_INITIATED', 'IHR_NOTIFICATION_INITIATED'],
  AWD:             ['REFERRAL_HEALTH_FACILITY', 'SPECIMEN_COLLECTION', 'CASE_INVESTIGATION'],
  BLOODY_DIARRHEA: ['CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY'],
  ILI:             ['ISOLATION_PRECAUTIONS', 'SPECIMEN_COLLECTION', 'CASE_INVESTIGATION'],
  SARI:            ['ISOLATION_PRECAUTIONS', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'CASE_INVESTIGATION'],
  RASH_FEVER:      ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'CONTACT_TRACING_INITIATED'],
  JAUNDICE:        ['CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY'],
  NEUROLOGICAL:    ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY'],
  MENINGITIS:      ['ISOLATION_PRECAUTIONS', 'CASE_INVESTIGATION', 'SPECIMEN_COLLECTION', 'REFERRAL_HEALTH_FACILITY', 'CONTACT_TRACING_INITIATED', 'IHR_NOTIFICATION_INITIATED'],
  OTHER:           ['CASE_INVESTIGATION'],
}

// IDSR-priority diseases — escalate to "now" urgency regardless of risk level.
// Lookup at the call site uppercases the disease_id, so values are stored upper.
// Membership = IDSR PHEIC + IDSR Acute Haemorrhagic Fever + cluster events
// + epidemic-prone single-case-threshold conditions. Legacy short codes
// retained so older IDB rows still resolve.
const PRIORITY_NOW_DISEASES = new Set([
  // IDSR PHEIC
  'SMALLPOX', 'SARS', 'INFLUENZA_NEW_SUBTYPE_ZOONOTIC', 'POLIO',
  'COVID_19', 'YELLOW_FEVER', 'ZIKA',
  // IDSR Acute Haemorrhagic Fever (AHF)
  'EBOLA_VIRUS_DISEASE', 'MARBURG_VIRUS_DISEASE', 'LASSA_FEVER', 'CCHF',
  'RIFT_VALLEY_FEVER',
  // Cluster events
  'CLUSTER_OF_DEATHS', 'PUBLIC_HEALTH_EVENT_UNKNOWN',
  // Other epidemic-prone single-case-threshold conditions
  'CHOLERA', 'PNEUMONIC_PLAGUE', 'BUBONIC_PLAGUE',
  'MENINGOCOCCAL_MENINGITIS', 'ANTHRAX_CUTANEOUS', 'ANTHRAX_PULMONARY',
  // Legacy short-code aliases (older IDB records may use these)
  'EVD', 'MARBURG', 'PLAGUE', 'H5N1', 'MEASLES', 'MENINGOCOCCAL',
])

const ACTIONS = [
  { code: 'CASE_INVESTIGATION', label: 'Case investigation initiated', basis: 'IDSR §4 — immediate case investigation for priority conditions' },
  { code: 'ISOLATION_PRECAUTIONS', label: 'Isolation / infection prevention precautions applied', basis: 'WHO IPC guidance; IDSR immediate response' },
  { code: 'SPECIMEN_COLLECTION', label: 'Specimen collected for laboratory confirmation', basis: 'WHO case management; IDSR laboratory protocol' },
  { code: 'REFERRAL_HEALTH_FACILITY', label: 'Referred to health facility for further assessment or care', basis: 'IDSR case management; WHO POE standard' },
  { code: 'CONTACT_TRACING_INITIATED', label: 'Contact tracing initiated', basis: 'IDSR §5; WHO outbreak response' },
  // 2026-05-06: at POE level, the action is "Notify District" — IHR
  // national notification is performed by higher tiers (PHEOC / National)
  // after the District relays. The persisted action code stays the same
  // (IHR_NOTIFICATION_INITIATED) so existing IDB / server records remain
  // valid; only the operator-facing label and basis change.
  { code: 'IHR_NOTIFICATION_INITIATED', label: 'District health office notified', basis: 'POE → District escalation; District relays via PHEOC / National per IHR ladder (Articles 6 / 9)' },
  { code: 'NO_ACTION_REQUIRED', label: 'No public-health action required — traveller released', basis: 'IDSR non-priority presentation' },
]

// Legacy action code shims for IDB-stored old codes
const ACTION_LEGACY_SHIMS = {
  ISOLATED: 'ISOLATION_PRECAUTIONS',
  MASK_GIVEN: 'ISOLATION_PRECAUTIONS',
  PPE_USED: 'ISOLATION_PRECAUTIONS',
  SEPARATE_INTERVIEW_ROOM: 'ISOLATION_PRECAUTIONS',
  REFERRED_CLINIC: 'REFERRAL_HEALTH_FACILITY',
  REFERRED_HOSPITAL: 'REFERRAL_HEALTH_FACILITY',
  QUARANTINE_RECOMMENDED: 'ISOLATION_PRECAUTIONS',
  SAMPLE_COLLECTED: 'SPECIMEN_COLLECTION',
  ALLOWED_CONTINUE: 'NO_ACTION_REQUIRED',
  ALERT_ISSUED: 'IHR_NOTIFICATION_INITIATED',
  FOLLOWUP_SCHEDULED: 'CASE_INVESTIGATION',
}

// ── Primary → Secondary symptom code mapping ────────────────────────────────
// Primary screening captures 10 quick-chip codes. Secondary screening uses
// more granular codes from SYMPTOM_GROUPS. This map converts primary codes
// to the closest secondary equivalents so the officer doesn't re-mark what
// the screener already flagged.
//
// Rules:
//  • A primary code that exists verbatim in SYMPTOM_GROUPS maps 1:1.
//  • A primary code with no exact match maps to the most clinically
//    relevant secondary equivalent (conservative — always pick the
//    most common presentation of that symptom class).
//  • One primary code may expand to MULTIPLE secondary codes when the
//    primary chip covers a broad class (e.g. 'rash' → general rash codes).
// Maps from primary-screening symptom CODE (broad category OR legacy specific
// code) to one or more secondary symptom codes. Includes BOTH the new broad
// categories (Priority 2 — 2026-05-06) and the legacy specific codes so old
// IDB records keep working.
const PRIMARY_TO_SECONDARY_SYMPTOM = Object.freeze({
  // ── Broad categories (current) ─────────────────────────────────────
  'fever':             ['fever'],
  'respiratory':       ['cough', 'difficulty_breathing', 'sore_throat', 'runny_nose'],
  'gastrointestinal':  ['vomiting', 'diarrhea', 'abdominal_pain', 'nausea'],
  'skin_rash':         ['rash_maculopapular'],
  'bleeding':          ['bleeding', 'bloody_diarrhea', 'blood_in_vomit'],
  'weakness_malaise':  ['weakness', 'malaise', 'fatigue'],
  // 'neurological' replaced by 'weakness_malaise' (2026-05-08). Old IDB
  // records carrying 'neurological' continue to resolve via the legacy
  // line below.
  'neurological':      ['headache', 'altered_consciousness', 'neck_stiffness', 'seizures'],
  'general_weakness':  ['fatigue', 'malaise', 'muscle_pain'],
  'pain':              ['abdominal_pain', 'muscle_pain', 'joint_pain', 'headache'],
  'other':             [],   // user-marked "other" — no auto-tick
  // ── Legacy specific codes (older IDB records) ──────────────────────
  'cough':                 ['cough'],
  'vomiting':              ['vomiting'],
  'diarrhea':              ['diarrhea'],
  'rash':                  ['rash_maculopapular'],
  'jaundice':              ['jaundice'],
  'difficulty_breathing':  ['difficulty_breathing'],
  'altered_consciousness': ['altered_consciousness'],
  'severe_headache':       ['headache'],
})

/**
 * Apply primary-screening quick-symptom chips to the secondary symptomsMap.
 * Idempotent — only sets a symptom if it is currently null (not yet assessed).
 * Never overwrites an officer's explicit YES/NO decision.
 *
 * @param {string[]} primaryCodes  — codes from quick_symptoms_json
 * @returns {number} count of symptoms actually seeded
 */
function applyPrimarySymptoms(primaryCodes) {
  if (!Array.isArray(primaryCodes) || primaryCodes.length === 0) return 0
  let seeded = 0
  for (const primaryCode of primaryCodes) {
    const secondaryCodes = PRIMARY_TO_SECONDARY_SYMPTOM[primaryCode] || [primaryCode]
    for (const code of secondaryCodes) {
      const entry = symptomsMap[code]
      if (entry && entry.is_present === null) {
        // Only auto-fill when not yet assessed — never overwrite the officer
        entry.is_present = 1
        seeded++
      }
    }
  }
  return seeded
}

// Symptom groups for Step 2 UI — codes must match Diseases.js symptom IDs
const SYMPTOM_GROUPS = [
  {
    key: 'fever_systemic', label: 'Fever & Systemic', color: '#C62828',
    symptoms: [
      // 2026-05-06 — duplicates removed by mandate. high_fever / low_grade_fever
      // are now AUTO-DERIVED from temperature (see autoFeverDerivedCodes below)
      // to preserve disease-scoring differential. severe_fatigue retired —
      // captured via fatigue + officer narrative.
      { code: 'fever',               label: 'Fever',                requiresOnset: true  },
      { code: 'chills',              label: 'Chills / Rigors',       requiresOnset: false },
      { code: 'fatigue',             label: 'Fatigue',               requiresOnset: false },
      { code: 'weakness',            label: 'Weakness / Malaise',    requiresOnset: false },
    ],
  },
  {
    key: 'respiratory', label: 'Respiratory', color: '#1565C0',
    symptoms: [
      { code: 'cough',               label: 'Cough',                 requiresOnset: true  },
      { code: 'difficulty_breathing',label: 'Difficulty Breathing',  requiresOnset: false },
      { code: 'sore_throat',         label: 'Sore Throat',           requiresOnset: false },
      { code: 'coryza',              label: 'Runny Nose / Coryza',   requiresOnset: false },
    ],
  },
  {
    key: 'gastrointestinal', label: 'Gastrointestinal', color: '#E65100',
    symptoms: [
      // 2026-05-06 — diarrhea variants retired by mandate (general 'diarrhea' kept).
      // Disease engines that previously matched watery/rice-water/bloody specifically
      // (cholera, shigella, EHEC) lose differential signal; mitigation: severe_dehydration
      // and abdominal_pain still surface the worst-case symptoms.
      { code: 'nausea',              label: 'Nausea',                requiresOnset: false },
      { code: 'vomiting',            label: 'Vomiting',              requiresOnset: true  },
      { code: 'diarrhea',            label: 'Diarrhoea',             requiresOnset: true  },
      { code: 'abdominal_pain',      label: 'Abdominal Pain',        requiresOnset: false },
      { code: 'severe_dehydration',  label: 'Severe Dehydration',    requiresOnset: false },
    ],
  },
  {
    key: 'jaundice_hepatic', label: 'Jaundice & Hepatic', color: '#F9A825',
    symptoms: [
      { code: 'jaundice',            label: 'Jaundice (Yellow Eyes/Skin)', requiresOnset: false },
      { code: 'dark_urine',          label: 'Dark / Tea-coloured Urine',   requiresOnset: false },
      { code: 'anorexia',            label: 'Loss of Appetite',            requiresOnset: false },
    ],
  },
  {
    key: 'rash_skin', label: 'Rash & Skin', color: '#6A1B9A',
    symptoms: [
      { code: 'rash_maculopapular',      label: 'Maculopapular Rash',      requiresOnset: true  },
      { code: 'rash_vesicular_pustular', label: 'Vesicular / Pustular Rash', requiresOnset: true  },
      { code: 'rash_face_first',         label: 'Rash Starting on Face',   requiresOnset: true  },
      { code: 'petechial_or_purpuric_rash', label: 'Petechial / Purpuric', requiresOnset: false },
      { code: 'painful_rash',            label: 'Painful Rash',            requiresOnset: false },
      { code: 'skin_eschar',             label: 'Skin Eschar (Black Sore)',  requiresOnset: false },
      { code: 'mucosal_lesions',         label: 'Mouth / Mucosal Lesions',  requiresOnset: false },
    ],
  },
  {
    key: 'hemorrhagic', label: 'Haemorrhagic Signs', color: '#B71C1C',
    symptoms: [
      // 2026-05-06 — bleeding variants retired by mandate. The general 'bleeding'
      // covers all anatomic locations; specific differential captured via free-text
      // notes / officer narrative if needed.
      { code: 'bleeding',              label: 'Bleeding',                requiresOnset: false },
    ],
  },
  {
    key: 'neurological', label: 'Neurological', color: '#1B5E20',
    symptoms: [
      { code: 'headache',               label: 'Headache',                requiresOnset: false },
      { code: 'stiff_neck',             label: 'Stiff Neck',              requiresOnset: false },
      { code: 'altered_consciousness',  label: 'Altered Consciousness',   requiresOnset: false },
      { code: 'paralysis_acute_flaccid',label: 'Sudden Paralysis (AFP)',  requiresOnset: false },
      { code: 'seizures',               label: 'Seizures',                requiresOnset: false },
      { code: 'hydrophobia',            label: 'Hydrophobia (fear of water)', requiresOnset: false },
      { code: 'photophobia',            label: 'Photophobia (fear of light)', requiresOnset: false },
      { code: 'weakness',               label: 'Weakness / Malaise',          requiresOnset: false },
    ],
  },
  {
    key: 'other', label: 'Other Signs', color: '#546E7A',
    symptoms: [
      { code: 'muscle_pain',             label: 'Muscle / Body Pain',      requiresOnset: false },
      { code: 'joint_pain',              label: 'Joint Pain',              requiresOnset: false },
      { code: 'swollen_lymph_nodes',     label: 'Swollen Lymph Nodes',     requiresOnset: false },
      { code: 'retroauricular_lymph_nodes', label: 'Swollen Neck/Ear Nodes', requiresOnset: false },
      { code: 'conjunctivitis',          label: 'Red Eyes / Conjunctivitis', requiresOnset: false },
      { code: 'loss_of_taste_smell',     label: 'Loss of Taste / Smell',   requiresOnset: false },
    ],
  },
]

// ─── FLAG MESSAGES ────────────────────────────────────────────────────────
const FLAG_MESSAGES = {
  NEEDS_IMMEDIATE_ISOLATION:       '⚠ IMMEDIATE ISOLATION REQUIRED',
  // 2026-05-06: at POE level, surface as a District alert — actual IHR
  // notification is initiated by higher tiers per the escalation ladder.
  NEEDS_IHR_NOTIFICATION:          '⚠ DISTRICT NOTIFICATION REQUIRED',
  NEEDS_EMERGENCY_REFERRAL:        '⚠ EMERGENCY REFERRAL — DO NOT DELAY',
  NEEDS_PUBLIC_HEALTH_NOTIFICATION: '⚠ PUBLIC HEALTH NOTIFICATION REQUIRED',
  VHF_PROTOCOL_ACTIVATED:          '🔴 VHF PROTOCOL ACTIVATED — Full PPE & Isolation',
  AFP_SURVEILLANCE_ACTIVATED:      '⚠ AFP SURVEILLANCE ACTIVATED — Stool specimens × 2',
  CHOLERA_PROTOCOL_ACTIVATED:      '⚠ CHOLERA PROTOCOL — Aggressive rehydration',
  RABIES_PROTOCOL_ACTIVATED:       '🔴 RABIES PROTOCOL — Emergency referral immediately',
  BIOTERRORISM_PROTOCOL_ACTIVATED: '🔴 BIOTERRORISM PROTOCOL — Maximum isolation + WHO',
  PREGNANCY_RISK_FLAG:             '⚠ PREGNANCY RISK — Immediate referral if pregnant',
}

// ─── COMPUTED ──────────────────────────────────────────────────────────────
const todayDate = computed(() => new Date().toISOString().slice(0, 10))

const priorityClass = computed(() => {
  const p = notification.value?.priority
  if (p === 'CRITICAL') return 'sc-prio-pill--critical'
  if (p === 'HIGH')     return 'sc-prio-pill--high'
  return 'sc-prio-pill--normal'
})

// Expose navigator.onLine to template
const isOnline = computed(() => navigator.onLine)

// Admin gate — only NATIONAL_ADMIN and POE_ADMIN can open the debug panel
const isAdmin = computed(() => {
  const role = getAuth()?.role_key ?? ''
  return role === 'NATIONAL_ADMIN' || role === 'POE_ADMIN'
})

// Debug panel — hidden by default, unlocked by 5 rapid taps on the sync pill (admin only)
const debugPanelOpen = ref(false)
let _debugTapCount = 0
let _debugTapTimer = null
function _debugTap() {
  _debugTapCount++
  clearTimeout(_debugTapTimer)
  _debugTapTimer = setTimeout(() => { _debugTapCount = 0 }, 2000)
  if (_debugTapCount >= 5) {
    _debugTapCount = 0
    debugPanelOpen.value = !debugPanelOpen.value
    L.info(`Admin debug panel ${debugPanelOpen.value ? 'OPENED' : 'CLOSED'}`)
  }
}

// Track whether auto-syndrome was applied this session (for the "Auto" badge in UI)
const autoSyndromeApplied = ref(false)

// Officer override state — allows officer to disagree with algorithm
const officerOverride = reactive({
  syndromeOverridden: false,   // officer manually changed syndrome
  riskOverridden:     false,   // officer manually changed risk level
  overrideNonCase:    false,   // officer disagrees with non-case verdict
  overrideNote:       '',      // mandatory justification text
  customDiseaseInput: '',      // disease id selected from catalog dropdown
  addedDiseases:      [],      // officer-added suspected diseases
})

// Expanded disease definition panel
const expandedDiseaseId = ref(null)

// Helper to get WHO case definition from intelligence layer
function getDiseaseDefinition(diseaseId) {
  return window.DISEASES?.getWHOCaseDefinition?.(diseaseId) || null
}

// ─── DISEASE NAME RESOLVER ────────────────────────────────────────────────────
// top_diagnoses.name comes from the engine at analysis time.
// For IDB-resumed records (only disease_code stored), look up via DISEASES array.
function diseaseName(id) {
  if (!id) return '—'
  const d = window.DISEASES?.getDiseaseById?.(id, { includeLegacy: true })
    || window.DISEASES?.diseases?.find(x => x.id === id)
  if (d?.name) return d.name
  return id.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
}

// VHF-family disease IDs — displayed as "Viral Haemorrhagic Fever (VHF)"
const VHF_FAMILY_DISEASE_IDS = new Set([
  'ebola_virus_disease',
  'marburg_virus_disease',
  'lassa_fever',
  'cchf',
])
const VHF_FAMILY_HANTAVIRUS = 'hantavirus'
const VHF_FAMILY_RVF = 'rift_valley_fever'
const VHF_FAMILY_DENGUE_SEVERE = 'dengue_severe'
const VHF_FAMILY_YELLOW_FEVER = 'yellow_fever'

/**
 * Return the user-facing display name for a disease.
 * VHF-family diseases are relabeled to "Viral Haemorrhagic Fever (VHF)" to
 * avoid panic and inappropriate action before laboratory confirmation. The
 * underlying disease_id and scoring logic remain pathogen-specific internally.
 */
function diseaseDisplayName(id) {
  if (!id) return '—'
  if (VHF_FAMILY_DISEASE_IDS.has(id)) return 'Viral Haemorrhagic Fever (VHF)'
  if (id === VHF_FAMILY_HANTAVIRUS) return 'Viral Haemorrhagic Fever (VHF) — Hantavirus type'
  if (id === VHF_FAMILY_RVF) return 'Viral Haemorrhagic Fever (VHF) — Rift Valley Fever'
  if (id === VHF_FAMILY_DENGUE_SEVERE) return 'Severe Dengue / VHF-family'
  if (id === VHF_FAMILY_YELLOW_FEVER) return 'Yellow Fever (VHF-family)'
  return diseaseName(id)
}

function isVhfFamilyDisease(id) {
  return VHF_FAMILY_DISEASE_IDS.has(id) ||
    id === VHF_FAMILY_HANTAVIRUS ||
    id === VHF_FAMILY_RVF ||
    id === VHF_FAMILY_DENGUE_SEVERE ||
    id === VHF_FAMILY_YELLOW_FEVER
}

function syndromeNameFromCode(code) {
  if (!code) return '—'
  const normalized = normalizeSyndromeCode(code)
  const entry = SYNDROMES.find(s => s.code === normalized)
  return entry?.name || code.replace(/_/g, ' ')
}

// Watcher: keep caseDecision.risk_level synchronized with engine output.
// Risk level is engine-determined and not user-editable (Change 22).
// Executive policy 2026-05-05: VHF override forces CRITICAL only if the
// engine ACCEPTED the declaration (post-bleed-gate). A rejected VHF
// declaration must not silently elevate risk in the UI.
watch(
  () => [
    analysisResult.value?.ihr_risk?.risk_level,
    officerOverride.addedDiseases.slice(),
    !!analysisResult.value?.officer_override_accepted,
  ],
  () => {
    if (!analysisResult.value) return
    const hasAcceptedVhf =
      officerOverride.addedDiseases.some(id => isVhfFamilyDisease(id)) &&
      !!analysisResult.value.officer_override_accepted
    if (hasAcceptedVhf) {
      caseDecision.risk_level = 'CRITICAL'
      return
    }
    const engineRisk = analysisResult.value?.ihr_risk?.risk_level
    if (engineRisk) {
      caseDecision.risk_level = engineRisk
    }
  },
  { deep: true, immediate: false }
)

// ─── DISEASE DETAIL MODAL ─────────────────────────────────────────────────────
const selectedDiseaseModal = ref(null)

// ─── SYMPTOM INFO MODAL (Task 2 — WHO definitions) ────────────────────────────
const symptomInfoModal = ref(null)

function openSymptomInfo(sym) {
  if (!sym) return
  symptomInfoModal.value = {
    code: sym.code,
    label: sym.label,
    info: _SYMPTOM_INFO_BUNDLE[sym.code] || null,
  }
}

function openDiseaseModal(d) {
  if (!d) return
  const id = d.disease_id || d.disease_code || ''
  const cat = window.DISEASES?.getDiseaseById?.(id, { includeLegacy: true }) || null
  selectedDiseaseModal.value = {
    disease_id:               id,
    name:                     diseaseDisplayName(id),
    final_score:              d.final_score        ?? null,
    confidence_band:          d.confidence_band   || null,
    ihr_category:             d.ihr_category      || null,
    cfr_pct:                  d.cfr_pct           ?? null,
    probability_like_percent: d.probability_like_percent ?? null,
    matched_hallmarks:        d.matched_hallmarks  || [],
    matched_symptoms:         d.matched_symptoms   || [],
    matched_exposures:        d.matched_exposures  || [],
    score_breakdown:          d.score_breakdown    || null,
    syndrome_matched:         d.syndrome_matched   || null,
    who_def:                  getDiseaseDefinition(id),
    // IDSR catalog fields (added 2026-05-08).
    alert_threshold:          cat?.alert_threshold        || null,
    epidemic_threshold:       cat?.epidemic_threshold     || null,
    incubation_period_days:   cat?.incubation_period_days || null,
    idsr_source_ref:          cat?.idsr_source_ref        || null,
    idsr_category:            cat?.idsr_category          || null,
    reasoning_text:           buildDiseaseReasoning(d),
  }
}

// Generates a natural-language paragraph explaining how the engine reached
// the given disease's final_score. Reads only the score_breakdown fields the
// engine itself wrote — no re-derivation, so the prose matches the numbers.
function buildDiseaseReasoning(d) {
  if (!d) return ''
  const sb = d.score_breakdown || {}
  const parts = []
  parts.push(`Final score: ${d.final_score ?? 0}/100${d.probability_like_percent != null ? ` (${d.probability_like_percent}% probability)` : ''}.`)
  if (sb.gate_score === 12)        parts.push('Gate passed (+12).')
  else if (sb.gate_score === -18)  parts.push('Gate soft-failed (-18) — not all required symptoms present.')
  else if (sb.gate_score === -60)  parts.push('Gate hard-failed (-60) — a mandatory symptom was confirmed absent.')
  if (sb.symptom_score)            parts.push(`Matched ${(d.matched_symptoms || []).length} symptom(s) totalling ${sb.symptom_score > 0 ? '+' : ''}${sb.symptom_score} pts.`)
  if (sb.exposure_score)           parts.push(`Matched ${(d.matched_exposures || []).length} exposure(s) totalling ${sb.exposure_score > 0 ? '+' : ''}${sb.exposure_score} pts.`)
  if ((d.matched_hallmarks || []).length) parts.push(`${d.matched_hallmarks.length} hallmark sign(s) recorded.`)
  if (sb.syndrome_bonus)           parts.push(`Syndrome match bonus: +${sb.syndrome_bonus}.`)
  if (sb.outbreak_bonus)           parts.push(`Endemic / outbreak context: +${sb.outbreak_bonus}.`)
  if (sb.vaccination_modifier)    parts.push(`Vaccination modifier: ${sb.vaccination_modifier > 0 ? '+' : ''}${sb.vaccination_modifier}.`)
  if (sb.onset_modifier)           parts.push(`Symptom-onset window modifier: ${sb.onset_modifier > 0 ? '+' : ''}${sb.onset_modifier}.`)
  if (sb.absent_hallmark_penalty) parts.push(`Absent-hallmark penalty: ${sb.absent_hallmark_penalty}.`)
  if (sb.contradiction_penalty)   parts.push(`Contradicting-symptom penalty: ${sb.contradiction_penalty}.`)
  if (sb.override_boost)           parts.push(`Triage override boost: ${sb.override_boost > 0 ? '+' : ''}${sb.override_boost}.`)
  if (sb.vhf_bleeding_gate)       parts.push(`VHF gate demoted score by ${sb.vhf_bleeding_gate} (IDSR Annex 1A — fever AND bleeding both required).`)
  return parts.join(' ')
}

// All diseases from the catalog, excluding those already added by the officer
const availableOverrideDiseases = computed(() => {
  const all = window.DISEASES?.diseases || []
  const added = new Set(officerOverride.addedDiseases)
  return all.filter(d => !added.has(d.id)).sort((a, b) => a.name.localeCompare(b.name))
})

// Officer override search query
const overrideDiseaseSearch = ref('')
const overrideMaxReachedMessage = ref('')

// Filtered list of available diseases for officer override.
// Executive policy 2026-05-05: each disease (including VHF-family individuals)
// is shown directly. Per-row eligibility is computed by overrideEligibilityFor()
// — disabled rows show a tooltip explaining what evidence is missing.
const filteredOverrideDiseases = computed(() => {
  const q = overrideDiseaseSearch.value.trim().toLowerCase()
  const all = window.DISEASES?.diseases || []
  const added = new Set(officerOverride.addedDiseases)

  const sortedDiseases = all
    .filter(d => !added.has(d.id))
    .map(d => ({ ...d, _displayName: diseaseDisplayName(d.id) }))
    .sort((a, b) => {
      const tierOrder = { tier_1_ihr_critical: 0, tier_2_ihr_annex2: 1, tier_2_ihr_equivalent: 2, tier_3_who_notifiable: 3, tier_4_syndromic: 4 }
      const ta = tierOrder[a.priority_tier] ?? 9
      const tb = tierOrder[b.priority_tier] ?? 9
      if (ta !== tb) return ta - tb
      return (a._displayName || a.name).localeCompare(b._displayName || b.name)
    })

  if (!q) return sortedDiseases
  return sortedDiseases.filter(d => {
    const name = (d._displayName || d.name || '').toLowerCase()
    const id = (d.id || '').toLowerCase()
    const cat = (d.who_category || '').toLowerCase()
    const tier = (d.priority_tier || '').toLowerCase()
    return name.includes(q) || id.includes(q) || cat.includes(q) || tier.includes(q)
  })
})

// Live eligibility check for the officer override picker. Re-evaluates whenever
// symptoms, exposures, or vitals change — disabling impossible diseases like
// "Cholera without watery diarrhoea" or "Ebola without bleeding".
const _overrideEligibilityCtx = computed(() => {
  const present = Object.values(symptomsMap).filter(s => s.is_present === 1).map(s => s.symptom_code)
  const exposureRecords = Object.values(exposuresMap).filter(e => e && e.response && e.exposure_code)
  const expCodes = window.EXPOSURES?.mapToEngineCodes ? window.EXPOSURES.mapToEngineCodes(exposureRecords) : []
  return { present, expCodes, vitals: { ...vitals } }
})

function overrideEligibilityFor(diseaseId) {
  const fn = window.DISEASES && window.DISEASES.engine && window.DISEASES.engine.getOfficerOverrideEligibility
  if (!fn) return { selectable: true, reason: '', required_evidence: null }
  const ctx = _overrideEligibilityCtx.value
  return fn(diseaseId, ctx.present, ctx.expCodes, ctx.vitals)
}

function selectOverrideDisease(d) {
  if (!d || !d.id) return
  if (officerOverride.addedDiseases.length >= 3) {
    overrideMaxReachedMessage.value = 'Officer override is limited to three suspected diseases. Remove a current selection to declare a different disease.'
    setTimeout(() => { overrideMaxReachedMessage.value = '' }, 4000)
    return
  }
  const elig = overrideEligibilityFor(d.id)
  if (!elig.selectable) {
    overrideMaxReachedMessage.value = elig.reason
    setTimeout(() => { overrideMaxReachedMessage.value = '' }, 7000)
    return
  }
  if (!officerOverride.addedDiseases.includes(d.id)) {
    officerOverride.addedDiseases.push(d.id)
    rerunAnalysisWithOfficerDiseases()
  }
  overrideDiseaseSearch.value = ''
}

// Officer-added disease — uses disease ID from catalog dropdown (not free text)
function addOfficerSuspectedDisease() {
  const id = officerOverride.customDiseaseInput.trim()
  if (!id) return
  if (officerOverride.addedDiseases.length >= 3) {
    overrideMaxReachedMessage.value = 'Officer override is limited to three suspected diseases. Remove a current selection to declare a different disease.'
    setTimeout(() => { overrideMaxReachedMessage.value = '' }, 4000)
    return
  }
  const exists = !!(window.DISEASES?.getDiseaseById?.(id, { includeLegacy: false })
    || (window.DISEASES?.diseases || []).find(d => d.id === id))
  if (!exists) return
  const elig = overrideEligibilityFor(id)
  if (!elig.selectable) {
    overrideMaxReachedMessage.value = elig.reason
    setTimeout(() => { overrideMaxReachedMessage.value = '' }, 7000)
    return
  }
  if (!officerOverride.addedDiseases.includes(id)) {
    officerOverride.addedDiseases.push(id)
    rerunAnalysisWithOfficerDiseases()
  }
  officerOverride.customDiseaseInput = ''
}

// Recommended actions advisor — dynamic content (Change 19)
const adviserImmediateActions = computed(() => {
  if (!analysisResult.value) return []
  const top = (analysisResult.value.top_diagnoses || []).slice(0, 3)
  const actions = new Set()

  for (const d of top) {
    const catalogEntry = window.DISEASES?.getDiseaseById?.(d.disease_id, { includeLegacy: true })
      || (window.DISEASES?.diseases || []).find(c => c.id === d.disease_id)
    const ia = (catalogEntry?.immediate_actions || d.immediate_actions || [])
    for (const a of ia) actions.add(a)
  }

  // Risk-level-based generic actions
  const rl = analysisResult.value.ihr_risk?.risk_level
  if (rl === 'CRITICAL') {
    actions.add('Initiate immediate isolation with full PPE.')
    actions.add('Notify national IHR focal point without delay.')
  } else if (rl === 'HIGH') {
    actions.add('Apply infection prevention precautions.')
    actions.add('Refer to health facility for further assessment.')
  }

  return [...actions].slice(0, 8)
})

const adviserNotifyLevels = computed(() => {
  if (!analysisResult.value) return []
  const routing = analysisResult.value.ihr_risk?.routing_level
  // ALWAYS all 4 levels for mobile app per Change 23 (no level skipping)
  if (analysisResult.value.ihr_risk?.ihr_alert_required) {
    return ['POE', 'District', 'PHEOC', 'National']
  }
  if (routing === 'NATIONAL') return ['POE', 'District', 'PHEOC', 'National']
  if (routing === 'PHEOC') return ['POE', 'District', 'PHEOC', 'National']
  if (routing === 'DISTRICT') return ['POE', 'District', 'PHEOC', 'National']
  return []
})

// 1-L — Officer override re-run.
// When officer selects diseases, re-run the scoring engine with those diseases
// as officer_declared_suspicion so risk level, routing, IHR tier, and syndrome
// all reflect the officer's clinical judgement. The original algorithm run is
// DISCARDED from the DB — only this re-run's output persists.
function rerunAnalysisWithOfficerDiseases() {
  if (!analysisResult.value && !originalAnalysisResult.value) return
  // Fire a "officer override applied" heads-up the FIRST time the override
  // list grows from empty → non-empty for this case. Subsequent edits don't
  // re-spam (only the transition from empty matters).
  try {
    if (officerOverride.addedDiseases.length === 1 && !officerOverride._notified) {
      officerOverride._notified = true
      const declared = diseaseDisplayName(officerOverride.addedDiseases[0])
      import('@/services/alertNotifier').then(mod => {
        mod.notifyOverrideApplied?.({
          secondary_screening_id: caseRecord.value?.id || caseRecord.value?.client_uuid || caseUuid.value,
          declared_disease:        declared,
          by_user:                 getAuth()?.full_name || getAuth()?.username || null,
        })
      }).catch(() => {})
    }
    if (!officerOverride.addedDiseases.length) officerOverride._notified = false
  } catch { /* notifier is best-effort */ }
  // RD-002 FIX: when officer removes ALL diseases, restore original algorithm output
  if (!officerOverride.addedDiseases.length) {
    if (originalAnalysisResult.value) {
      analysisResult.value = originalAnalysisResult.value
      // Rebuild suspectedDiseases from original algorithm output
      const orig = originalAnalysisResult.value
      suspectedDiseases.value = (orig.top_diagnoses || []).slice(0, 3).map((d, i) => ({
        client_uuid:            genUUID(),
        secondary_screening_id: caseUuid.value,
        disease_code:           d.disease_id,
        display_name:           d.name || diseaseName(d.disease_id),
        rank_order:             i + 1,
        confidence:             d.probability_like_percent ?? null,
        reasoning:              (d.matched_hallmarks || []).slice(0, 3).join(', ') || null,
        sync_status:            'UNSYNCED',
      }))
      // Restore auto-set syndrome/risk from original
      if (!officerOverride.syndromeOverridden && orig.syndrome?.syndrome) {
        caseDecision.syndrome_classification = orig.syndrome.syndrome
      }
      if (!officerOverride.riskOverridden && orig.ihr_risk?.risk_level) {
        caseDecision.risk_level = orig.ihr_risk.risk_level
      }
      L.ok('rerunAnalysisWithOfficerDiseases: officer cleared all overrides — original algorithm output restored')
    }
    return
  }
  if (!window.DISEASES?.getEnhancedScoreResult) return

  try {
    const presentSymptoms = Object.values(symptomsMap).filter(s => s.is_present === 1).map(s => s.symptom_code)
    const absentSymptoms  = Object.values(symptomsMap).filter(s => s.is_present === 0).map(s => s.symptom_code)
    // Officer-answered exposures only — never synthesise UNKNOWN.
    const exposureRecords = Object.values(exposuresMap).filter(e => e && e.response && e.exposure_code)
    const engineExposureCodes = window.EXPOSURES?.mapToEngineCodes(exposureRecords) || []
    const tc = vitals.temperature_value
      ? (vitals.temperature_unit === 'F' ? (vitals.temperature_value - 32) * 5 / 9 : vitals.temperature_value)
      : null
    const vitalsForEng = {
      temperature_c:     tc,
      oxygen_saturation: vitals.oxygen_saturation || undefined,
      pulse_rate:        vitals.pulse_rate         || undefined,
      respiratory_rate:  vitals.respiratory_rate   || undefined,
      bp_systolic:       vitals.bp_systolic         || undefined,
      clinical_context: {
        cluster_deaths_in_community: !!clusterFlags.cluster_deaths_in_community,
        cluster_similar_illness:     !!clusterFlags.cluster_similar_illness,
        unusual_event_flag:          !!clusterFlags.unusual_event_flag,
      },
    }
    const visitedCountries = travelCountries.value.map(t => ({ country_code: t.country_code, travel_role: t.travel_role || 'VISITED' }))

    // Pass the officer's single declared disease as officer_declared_suspicion.
    // Executive policy 2026-05-05: only one declaration is allowed; the engine
    // applies the eligibility gate (bleed-gate + per-disease minimum evidence)
    // and surfaces officer_override_rejected if the gate is closed, or
    // officer_override_accepted with a factor-by-factor explanation if open.
    const context = {
      officer_declared_suspicion: officerOverride.addedDiseases[0],
      officer_override:           true,
    }

    const rerunResult = window.DISEASES.getEnhancedScoreResult(
      presentSymptoms, absentSymptoms, engineExposureCodes, visitedCountries, vitalsForEng, context
    )

    // If the engine REJECTED the override (bleed-gate or insufficient evidence),
    // restore the original analysis and pop the officer's selection. The
    // rejection reason is surfaced to the officer via the analysisResult.officer_override_rejected
    // banner (set by the engine).
    if (rerunResult.officer_override_rejected) {
      analysisResult.value = {
        ...(originalAnalysisResult.value || rerunResult),
        officer_override_rejected: rerunResult.officer_override_rejected,
        global_flags: Array.from(new Set([
          ...((originalAnalysisResult.value || rerunResult).global_flags || []),
          ...(rerunResult.global_flags || []).filter(f => f.startsWith('OFFICER_OVERRIDE_REJECTED_')),
        ])),
      }
      // Pop the rejected selection so the officer can pick a different disease.
      officerOverride.addedDiseases = []
      // Restore engine-driven suspectedDiseases from original.
      if (originalAnalysisResult.value) {
        const orig = originalAnalysisResult.value
        suspectedDiseases.value = (orig.top_diagnoses || []).slice(0, 3).map((d, i) => ({
          client_uuid:            genUUID(),
          secondary_screening_id: caseUuid.value,
          disease_code:           d.disease_id,
          display_name:           d.name || diseaseName(d.disease_id),
          rank_order:             i + 1,
          confidence:             d.probability_like_percent ?? null,
          reasoning:              (d.matched_hallmarks || []).slice(0, 3).join(', ') || null,
          sync_status:            'UNSYNCED',
        }))
      }
      L.warn(`rerunAnalysisWithOfficerDiseases: REJECTED — ${rerunResult.officer_override_rejected.reason}`)
      return
    }

    // Replace analysisResult with the re-run. The original algorithm output is
    // no longer used anywhere downstream — this is the canonical result.
    analysisResult.value = {
      ...rerunResult,
      _officer_override: true,
      _officer_diseases: [...officerOverride.addedDiseases],
    }

    // Engine-derived risk level always wins — officer cannot manually edit it (Change 22)
    // But VHF override forces CRITICAL/NATIONAL even if engine returns lower
    const hasVhfOverride = officerOverride.addedDiseases.some(id => isVhfFamilyDisease(id))

    // Syndrome priority for officer override:
    //   1. Officer-declared disease → look up DISEASE_TO_SYNDROME_MAP (clinically correct)
    //   2. Fallback: engine syndrome (symptom-based) normalized to canonical code
    // Without this, the engine's symptom-only derivation produces wrong syndromes
    // (e.g. officer says Ebola but engine says ACUTE_FEBRILE_ILLNESS with no
    // bleeding symptom present). Auto-mark as auto-set so the officer sees
    // the inferred classification rather than a blank.
    if (!officerOverride.syndromeOverridden) {
      const declaredId = officerOverride.addedDiseases[0]
      const fromDisease = declaredId ? syndromeFromDeclaredDisease(declaredId) : null
      if (fromDisease) {
        caseDecision.syndrome_classification = fromDisease
        autoSyndromeApplied.value = true
      } else if (rerunResult.syndrome?.syndrome) {
        caseDecision.syndrome_classification = normalizeSyndromeCode(rerunResult.syndrome.syndrome)
        autoSyndromeApplied.value = true
      }
    }
    if (hasVhfOverride) {
      caseDecision.risk_level = 'CRITICAL'
    } else if (rerunResult.ihr_risk?.risk_level) {
      caseDecision.risk_level = rerunResult.ihr_risk.risk_level
    }

    // Officer-override cascade (executive directive 2026-05-05):
    // when the officer specifies ANY suspected disease (one is enough), the
    // engine-generated suspected list is DISCARDED. Only the officer's
    // diseases are persisted, and the alert's top-disease/risk/syndrome
    // recompute against the officer pool. This keeps the audit clean — the
    // engine result is preserved in originalAnalysisResult so a full
    // rollback (officer clears every override) restores it.
    const officerRows = officerOverride.addedDiseases.map((diseaseId, i) => {
      const catalogEntry = window.DISEASES?.getDiseaseById?.(diseaseId, { includeLegacy: true })
        || (window.DISEASES?.diseases || []).find(d => d.id === diseaseId)
      const fromRerun = (rerunResult.top_diagnoses || []).find(d => d.disease_id === diseaseId)
      return {
        client_uuid:            genUUID(),
        secondary_screening_id: caseUuid.value,
        disease_code:           diseaseId,
        display_name:           catalogEntry?.name || diseaseId.replace(/_/g, ' '),
        rank_order:             i + 1,
        confidence:             fromRerun?.probability_like_percent ?? null,
        confidence_band:        fromRerun?.confidence_band || 'OFFICER',
        final_score:            fromRerun?.final_score ?? null,
        ihr_category:           fromRerun?.ihr_category || catalogEntry?.ihr_category || null,
        reasoning:              'OFFICER_CLINICAL_OVERRIDE',
        sync_status:            'UNSYNCED',
      }
    })

    suspectedDiseases.value = officerRows.slice(0, 3)
    L.ok(`rerunAnalysisWithOfficerDiseases: ENGINE-GENERATED DISCARDED — persisting ${officerRows.length} officer-declared disease(s) [primary=${officerOverride.addedDiseases[0] || '—'}] | risk: ${rerunResult.ihr_risk?.risk_level} | syndrome: ${rerunResult.syndrome?.syndrome}`)
  } catch (err) {
    L.warn('rerunAnalysisWithOfficerDiseases: threw', err?.message ?? err)
  }
}

const syncPillClass = computed(() => {
  if (!caseRecord.value) return 'sc-sync-pill--offline'
  return caseRecord.value.sync_status === SYNC.SYNCED ? 'sc-sync-pill--ok' : 'sc-sync-pill--pending'
})
const syncPillLabel = computed(() => {
  if (!caseRecord.value) return 'New'
  return SYNC.LABELS[caseRecord.value.sync_status] || caseRecord.value.sync_status
})

const presentSymptomCount = computed(() =>
  Object.values(symptomsMap).filter(s => s.is_present === 1).length
)


const criticalFlags = computed(() => {
  if (!analysisResult.value) return []
  // Build the visible flag set. Two suppression rules apply on the analysis
  // page (suspected-case stage):
  //   1. NEEDS_IHR_NOTIFICATION never appears here — it only matters once
  //      a case is CONFIRMED (war-room/case-file surfaces it then).
  //   2. VHF_PROTOCOL_ACTIVATED only appears if a bleeding symptom is
  //      currently recorded. The engine bleed-gate already blocks VHF from
  //      top_diagnoses, but a stale flag from a prior analysis run could
  //      leak — strip it defensively here so a symptom-edit cycle never
  //      shows VHF labels for a non-bleeding case.
  const allowed = new Set(['VHF_PROTOCOL_ACTIVATED','AFP_SURVEILLANCE_ACTIVATED',
    'CHOLERA_PROTOCOL_ACTIVATED','RABIES_PROTOCOL_ACTIVATED',
    'BIOTERRORISM_PROTOCOL_ACTIVATED','NEEDS_IMMEDIATE_ISOLATION',
    'NEEDS_EMERGENCY_REFERRAL'])
  const hasBleeding = Object.values(symptomsMap).some(s =>
    s && s.is_present === 1 &&
    ['bleeding','petechial_or_purpuric_rash','bloody_diarrhea','hematemesis','melena','epistaxis','gum_bleeding']
      .includes(String(s.symptom_code || '').toLowerCase())
  )
  return (analysisResult.value.global_flags || [])
    .filter(f => allowed.has(f))
    .filter(f => f !== 'VHF_PROTOCOL_ACTIVATED' || hasBleeding)
})

// Defensive view-layer filter — strip any IHR reasoning sentence that
// mentions VHF / haemorrhagic / Hemorrhagic when no bleeding symptom is
// currently recorded for this traveller. The engine bleed-gate already
// removes VHF diseases from top_diagnoses; this is belt-and-braces so a
// stale rule that still includes the word "VHF" never leaks into the UI
// and confuses officers.
const filteredIhrReasoning = computed(() => {
  const list = (analysisResult.value?.ihr_risk?.reasoning || []).slice()
  if (!list.length) return []
  const hasBleeding = Object.values(symptomsMap).some(s =>
    s && s.is_present === 1 &&
    ['bleeding', 'bleeding_gums_or_nose', 'bloody_sputum',
     'haematemesis', 'haematochezia', 'haematuria',
     'haemorrhagic_skin_rash', 'petechial_or_purpuric_rash',
     'bruising_or_ecchymosis', 'melena',
     'bloody_diarrhea', 'blood_in_vomit'].includes(String(s.symptom_code || '').toLowerCase())
  )
  if (hasBleeding) return list.slice(0, 3)
  // Strip any sentence that names a haemorrhagic-fever disease or VHF.
  const VHF_NEEDLES = /\b(vhf|haemorrhag|hemorrhag|ebola|marburg|lassa|cchf|rift\s*valley|hantavirus|nipah)\b/i
  return list.filter(s => !VHF_NEEDLES.test(String(s || ''))).slice(0, 3)
})

// Alert preview — computed reactively from risk_level + syndrome
// AI-style WHO/Africa CDC recommendations — engine-connected, dynamic.
// Reads the live engine result + the screener's current decision and
// produces a list of recommended actions specific to THIS case. Updates
// reactively as the screener edits the risk level / syndrome.
const recommendedDiseaseLabel = computed(() => {
  const top = analysisResult.value?.top_diagnoses?.[0]
  if (top?.disease_id) {
    try { return diseaseDisplayName(top.disease_id) } catch {}
  }
  const syn = caseDecision.syndrome_classification
    || analysisResult.value?.syndrome?.syndrome
  return syn ? syn.replace(/_/g, ' ').toLowerCase().replace(/\b\w/g, c => c.toUpperCase()) : 'this presentation'
})
const recommendedRiskLevel = computed(() => caseDecision.risk_level || analysisResult.value?.ihr_risk?.risk_level || '')

// 2026-05-07 — Court-defensible score-breakdown helper. For a matched
// symptom or exposure on a top-diagnosis card, return the engine weight
// the engine applied. We read from the engine's own per-disease catalog
// (window.DISEASES is the bundled `Diseases.js` table) so the value
// shown is the SAME constant the engine added to the disease score —
// no re-derivation, no approximation.
function _weightFor(kind /* 'symptom'|'exposure' */, code, doc) {
  if (!doc) return ''
  try {
    const id = doc.disease_id
    const cat = (typeof window !== 'undefined' && window.DISEASES) ? window.DISEASES : null
    const entry = cat && (cat.byId ? cat.byId(id) : (cat.find ? cat.find(d => d.id === id) : null))
    const weights = entry?.weights || entry?.symptom_weights || {}
    const expWeights = entry?.exposure_weights || {}
    const w = (kind === 'exposure') ? expWeights[code] : (weights[code] ?? expWeights[code])
    if (Number.isFinite(Number(w))) return String(Number(w))
  } catch {}
  return '·'   // unknown — caller renders as "+·" which signals "matched, weight not in client catalog"
}

// 2026-05-07 — Compact, deduped endemic-zones chip list. The engine emits
// raw disease IDs; multiple IDs (ebola_virus_disease, marburg_virus_disease,
// rift_valley_fever) often share a single display name ("Viral Haemorrhagic
// Fever"). Run them through diseaseDisplayName + a Set so the chip list is
// human-readable and short. Pure derived state — does not touch the engine
// output, which is preserved verbatim for analytics / sync.
const endemicZonesCompact = computed(() => {
  const ids = analysisResult.value?.outbreak_context_used || []
  const seen = new Set()
  const out = []
  for (const id of ids) {
    const name = (typeof diseaseDisplayName === 'function')
      ? diseaseDisplayName(id)
      : String(id || '').replace(/_/g, ' ')
    if (!name) continue
    // Strip parenthetical qualifiers so "Viral Haemorrhagic Fever (VHF)" and
    // "Viral Haemorrhagic Fever" don't both show.
    const norm = name.replace(/\s*\(.*?\)\s*/g, '').trim()
    if (!norm || seen.has(norm)) continue
    seen.add(norm)
    out.push(norm)
  }
  return out
})
// 2026-05-07 (refactor) — Smart, per-disease, court-defensible action engine.
// Resolution chain (each step adds, doesn't replace):
//   1. Disease — officer-override disease wins; else engine's top diagnosis.
//   2. Disease-specific clinical actions from WHO_DISEASE_RECOMMENDATIONS,
//      sanitized: every "Notify WHO/IHR within 24h" is rewritten to the
//      POE-tier language "Notify District health office" (per the
//      escalation ladder mandate). Citations remain in the source field.
//   3. Syndrome-level fallback when the disease isn't in the curated map.
//   4. Risk-tier overlay (HIGH / CRITICAL) — adds escalation steps.
//   5. GAP DETECTION — flags any action the curated library says is
//      mandatory that the officer hasn't yet ticked (rendered with a
//      "still required" hint so the screener notices).
// REMOVED:
//   • "Rule triggered by engine: VHF HIGH CONFIDENCE" lines — these were
//     internal engine state surfaced verbatim. Operators don't need them.
//   • "Notify WHO" / "Notify IHR NFP" wording — those notifications are
//     post-confirmation actions performed by higher tiers, not the POE.
function _sanitizeActionText(text) {
  if (!text) return ''
  return String(text)
    // The POE never directly notifies WHO/IHR — relabel as District tier.
    .replace(/Notify\s+WHO[^.]*?within\s*24\s*h(?:ours?)?\.?/gi, 'Notify District health office within 24 hours.')
    .replace(/Notify\s+within\s*24\s*h(?:ours?)?\s*(?:via\s+IHR\s*[A-Z\s]+)?\.?/gi, 'Notify District health office within 24 hours.')
    .replace(/IMMEDIATE\s+notification:\s*District\s*\+\s*PHEOC\s*\+\s*National\s*\+\s*WHO\s*IHR\s*NFP\s*within\s*24\s*hours\.?/gi,
      'IMMEDIATE notification: relay through the District → PHEOC → National escalation ladder within 24 hours.')
    .replace(/\bIHR\s*NFP\b/gi, 'higher-tier authority')
    .replace(/\bWHO\s+National\s+Focal\s+Point\b/gi, 'higher-tier authority')
    .trim()
}

// 2026-05-07 (rewrite) — Action-code-bound recommendations. Each item
// surfaces the EXACT label of a button in the actions grid below, so the
// officer reads identical wording above (recommendation) and below (record).
// `code` ties the recommendation to the button via toggleAction(code).
// `done` is live-bound to the officer's actual ticked actions.
// `urgency` drives the inline tag (now / 24h / routine).
// `rationale` is a short why-this-action note. Citation in `source`.
const recommendedActions = computed(() => {
  if (!analysisResult.value) return []
  const r = analysisResult.value

  // (1) Subject disease. Officer override wins, else top diagnosis.
  const overrideId = r.officer_override_accepted?.disease_id
  const topDisease = r.top_diagnoses?.[0]
  const subjectId  = overrideId || topDisease?.disease_id || ''
  const key = subjectId.toString().toUpperCase().replace(/[\s-]/g, '_')

  // (2) Resolve the action-code plan: disease → syndrome → empty.
  let codes = ACTION_PLAN_BY_DISEASE[key]
  let resolutionPath = 'disease'
  if (!codes || !codes.length) {
    const syn = (caseDecision.syndrome_classification || r.syndrome?.syndrome || '').toString().toUpperCase()
    codes = ACTION_PLAN_BY_SYNDROME[syn] || []
    resolutionPath = codes.length ? 'syndrome' : 'none'
  }

  // (3) Risk-tier overlay — guarantees notification + investigation chain.
  const rl = (recommendedRiskLevel.value || '').toUpperCase()
  const overlay = []
  if (rl === 'HIGH') {
    overlay.push('IHR_NOTIFICATION_INITIATED', 'CASE_INVESTIGATION')
  } else if (rl === 'CRITICAL') {
    overlay.push('ISOLATION_PRECAUTIONS', 'IHR_NOTIFICATION_INITIATED',
                 'REFERRAL_HEALTH_FACILITY', 'CASE_INVESTIGATION')
  }

  // (4) Empty plan + LOW risk + no priority disease → release path.
  if (!codes.length && !overlay.length) {
    const lowOk = !rl || rl === 'LOW'
    if (lowOk && resolutionPath === 'none') {
      const ac = ACTIONS.find(a => a.code === 'NO_ACTION_REQUIRED')
      return ac ? [{
        code:      ac.code,
        text:      ac.label,
        source:    ac.basis,
        kind:      'release',
        urgency:   'routine',
        rationale: 'No priority condition or risk indicator detected — traveller may be released.',
        done:      isActionDone(ac.code),
      }] : []
    }
    return []
  }

  // (5) Merge disease/syndrome plan + overlay, dedupe, preserve order.
  const merged = []
  const seen = new Set()
  for (const c of [...codes, ...overlay]) {
    if (seen.has(c)) continue
    seen.add(c); merged.push(c)
  }

  // (6) Materialise as full items bound to ACTIONS metadata.
  const isPriorityNow = PRIORITY_NOW_DISEASES.has(key) || rl === 'CRITICAL'
  return merged
    .map(code => {
      const ac = ACTIONS.find(a => a.code === code)
      if (!ac) return null
      const urgency = _urgencyFor(code, isPriorityNow, rl)
      return {
        code:      ac.code,
        text:      ac.label,           // EXACT label of the button below
        source:    ac.basis,           // legal/protocol citation
        kind:      resolutionPath,
        urgency,                       // 'now' | '24h' | 'routine'
        rationale: _rationaleFor(code, key, rl),
        done:      isActionDone(ac.code),
      }
    })
    .filter(Boolean)
})

// Urgency rules — derived, no per-disease tuning needed.
function _urgencyFor(code, isPriorityNow, rl) {
  // Anything during a CRITICAL or priority disease is "now".
  if (isPriorityNow) {
    if (code === 'CONTACT_TRACING_INITIATED') return '24h'   // can begin same day, finalised within 24h
    return 'now'
  }
  if (rl === 'HIGH') {
    if (code === 'ISOLATION_PRECAUTIONS' || code === 'REFERRAL_HEALTH_FACILITY') return 'now'
    return '24h'
  }
  return 'routine'
}

// Short, plain-English rationale — never cites WHO/IHR (citations live in source).
function _rationaleFor(code, diseaseKey, rl) {
  const isCritical = rl === 'CRITICAL'
  const isHigh     = rl === 'HIGH'
  const dz         = diseaseKey || 'this presentation'
  switch (code) {
    case 'ISOLATION_PRECAUTIONS':
      return isCritical ? 'Stop transmission before any further handling.' : 'Limit exposure while diagnosis is confirmed.'
    case 'CASE_INVESTIGATION':
      return 'Document exposures, contacts, travel — needed for any onward action.'
    case 'SPECIMEN_COLLECTION':
      return 'Lab confirmation drives treatment and notification thresholds.'
    case 'REFERRAL_HEALTH_FACILITY':
      return isHigh || isCritical ? 'Higher-level care is required for this risk tier.' : 'Definitive assessment beyond the POE capacity.'
    case 'CONTACT_TRACING_INITIATED':
      return 'Identify travel companions and same-conveyance contacts before they disperse.'
    case 'IHR_NOTIFICATION_INITIATED':
      return 'District relays through the PHEOC → National ladder; start the clock now.'
    case 'NO_ACTION_REQUIRED':
      return 'No priority condition or risk indicator detected.'
    default:
      return ''
  }
}

// Heuristic: has the officer already ticked an ACTIONS row that satisfies
// this clinical recommendation? Used for the "(✓ done)" badge in the panel.
function _isActionLikelyDone(text, _source) {
  if (!text) return false
  const recordedCodes = new Set(
    (actions.value || [])
      .filter(a => a.is_done === 1)
      .map(a => String(a.action_code || ''))
  )
  const t = String(text).toLowerCase()
  if (recordedCodes.has('ISOLATION_PRECAUTIONS') && /isolat|ppe|airborne|droplet|contact precaution/.test(t)) return true
  if (recordedCodes.has('SPECIMEN_COLLECTION')   && /(sample|specimen|stool|culture|pcr|swab|rdt|smear)/.test(t)) return true
  if (recordedCodes.has('REFERRAL_HEALTH_FACILITY') && /(referral|refer|treatment centre|health facility)/.test(t)) return true
  if (recordedCodes.has('CASE_INVESTIGATION') && /(case investigation|symptom monitoring|follow-?up)/.test(t)) return true
  if (recordedCodes.has('CONTACT_TRACING_INITIATED') && /(contact tracing|trace exposure|contact list)/.test(t)) return true
  if (recordedCodes.has('IHR_NOTIFICATION_INITIATED') && /(notif|escalat|relay)/.test(t)) return true
  return false
}

// Backwards-compat alias — the template references this name in places we
// don't want to grep through. Both pointing at the same computed.
const recommendedWhoActions = recommendedActions

const alertPreview = computed(() => {
  const rl  = caseDecision.risk_level
  const syn = caseDecision.syndrome_classification
  if (!rl) return null

  // IDSR Annex 1A — single-case-threshold or otherwise high-priority conditions
  // (PHEIC + AHF + meningococcal + plague + anthrax + cluster events).
  const PRIORITY1 = [
    'cholera','pneumonic_plague','bubonic_plague',
    'ebola_virus_disease','marburg_virus_disease','lassa_fever',
    'cchf','yellow_fever','smallpox','rift_valley_fever',
    'covid_19','sars','influenza_new_subtype_zoonotic','polio','zika',
    'meningococcal_meningitis','anthrax_cutaneous','anthrax_pulmonary',
    'cluster_of_deaths','public_health_event_unknown',
  ]
  // IDSR Tier-1 immediate national notification: PHEIC diseases + AHF + the
  // PHEIC-flagged cluster events. Mirrors window.DISEASES.IDSR_PHEIC_DISEASES
  // and IDSR_AHF_DISEASES (derived from idsr_category in Diseases.js).
  const NATIONAL_DISEASES = [
    'smallpox','sars','influenza_new_subtype_zoonotic','polio',
    'covid_19','yellow_fever','zika',
    'ebola_virus_disease','marburg_virus_disease','lassa_fever','cchf',
    'cluster_of_deaths','public_health_event_unknown',
  ]

  // Use the intelligence layer result when available (FIX — was hardcoded)
  const ihrRisk = analysisResult.value?.ihr_risk
  if (ihrRisk?.ihr_alert_required) {
    const topRule = ihrRisk.triggered_rules?.[0] || null
    return {
      alertCode:  topRule || ('CASE_' + rl),
      routedTo:   ihrRisk.routing_level || 'PHEOC',
      riskLevel:  rl,
      ihrTier:    ihrRisk.ihr_tier,
    }
  }

  // Fallback: legacy hardcoded alert preview when intelligence result not present
  const topDisease  = suspectedDiseases.value[0]?.disease_code ?? null
  const isPriority1 = PRIORITY1.includes(topDisease)
  const isNational  = NATIONAL_DISEASES.includes(topDisease)

  let triggered  = false
  let alertCode  = ''
  let routedTo   = 'DISTRICT'

  if (rl === 'CRITICAL') {
    triggered = true; alertCode = 'CRITICAL_RISK_CASE'; routedTo = 'PHEOC'
  } else if (rl === 'HIGH' && (syn === 'VHF' || syn === 'MENINGITIS')) {
    triggered = true; alertCode = 'HIGH_RISK_' + syn; routedTo = 'PHEOC'
  } else if (isPriority1 && rl === 'HIGH') {
    triggered = true; alertCode = 'PRIORITY1_' + (topDisease || '').toUpperCase(); routedTo = 'PHEOC'
  } else if (rl === 'HIGH') {
    triggered = true; alertCode = 'HIGH_RISK_' + (syn || 'CASE'); routedTo = 'DISTRICT'
  }

  if (isNational && triggered) routedTo = 'NATIONAL'
  if (!triggered) return null

  return { alertCode, routedTo, riskLevel: rl }
})

const highRiskActionDone = computed(() => {
  const rl = caseDecision.risk_level
  if (rl !== 'HIGH' && rl !== 'CRITICAL') return true
  return isActionDone('ISOLATION_PRECAUTIONS') ||
         isActionDone('REFERRAL_HEALTH_FACILITY') ||
         isActionDone('ISOLATED') ||  // legacy
         isActionDone('REFERRED_HOSPITAL')  // legacy
})

const canDisposition = computed(() =>
  !!caseDecision.syndrome_classification &&
  !!caseDecision.risk_level &&
  !!caseDecision.final_disposition &&
  actions.value.filter(a => a.is_done === 1).length > 0 &&
  highRiskActionDone.value
)

// Vital sign warnings
const tempWarning = computed(() => {
  const v = vitals.temperature_value
  const u = vitals.temperature_unit
  if (v == null) return ''
  const c = u === 'F' ? (v - 32) * 5 / 9 : v
  if (c < 35)  return '❄ Hypothermia — verify reading'
  if (c >= 40) return '🔴 Dangerous fever — consider EMERGENCY'
  if (c >= 39) return '⚠ High fever — consider URGENT/EMERGENCY'
  if (c >= 38) return '⚠ Fever'
  if (c >= 37.5) return 'Low-grade fever — document'
  return ''
})
const tempWarnClass = computed(() => {
  const v = vitals.temperature_value
  const u = vitals.temperature_unit
  if (v == null) return ''
  const c = u === 'F' ? (v - 32) * 5 / 9 : v
  if (c >= 39 || c < 35) return 'sc-vt-warn--crit'
  if (c >= 37.5) return 'sc-vt-warn--warn'
  return ''
})
const pulseWarning = computed(() => {
  const p = vitals.pulse_rate
  if (!p) return ''
  if (p < 40) return '🔴 Critically low — verify'
  if (p >= 150) return '🔴 Severe tachycardia — EMERGENCY'
  if (p >= 101) return '⚠ Tachycardia'
  if (p < 60) return '⚠ Bradycardia'
  return ''
})
const rrWarning = computed(() => {
  const r = vitals.respiratory_rate
  if (!r) return ''
  if (r < 10) return '⚠ Abnormally low — verify'
  if (r >= 30) return '🔴 Severe — consider EMERGENCY'
  if (r >= 21) return '⚠ Elevated'
  return ''
})
const spo2Warning = computed(() => {
  const s = vitals.oxygen_saturation
  if (!s) return ''
  if (s < 90) return '🔴 Critically low SpO₂ — EMERGENCY'
  if (s < 95) return '⚠ Low SpO₂ — supplemental oxygen recommended'
  return ''
})
const spo2WarnClass = computed(() => {
  const s = vitals.oxygen_saturation
  if (!s) return ''
  if (s < 90) return 'sc-vt-warn--crit'
  if (s < 95) return 'sc-vt-warn--warn'
  return ''
})

// ─── SYMPTOM HELPERS ──────────────────────────────────────────────────────
function initSymptoms() {
  for (const grp of SYMPTOM_GROUPS) {
    for (const sym of grp.symptoms) {
      if (!symptomsMap[sym.code]) {
        symptomsMap[sym.code] = {
          client_uuid:            genUUID(),
          secondary_screening_id: caseUuid.value,
          symptom_code:           sym.code,
          is_present:             null,   // null = not yet assessed (stored as 0 on save)
          onset_date:             null,
          details:                null,
          sync_status:            SYNC.UNSYNCED,
        }
      }
    }
  }
}

function symState(code) {
  return symptomsMap[code]?.is_present ?? null
}

function toggleSymptom(code) {
  if (!symptomsMap[code]) return
  const current = symptomsMap[code].is_present
  // null → 1 → 0 → null (cycle: unassessed → present → absent → unassessed)
  if (current === null) symptomsMap[code].is_present = 1
  else if (current === 1) symptomsMap[code].is_present = 0
  else symptomsMap[code].is_present = null
  // Invalidate any prior analysis result so VHF / Mpox / Cholera labels
  // from a previous symptom set never leak. The next Analyse step rebuilds
  // them. analysisResult must clear unconditionally — a stale truthy value
  // would render the wrong banner set under the new symptom truth.
  if (analysisResult.value) {
    analysisResult.value = null
    if (typeof originalAnalysisResult !== 'undefined' && originalAnalysisResult) {
      originalAnalysisResult.value = null
    }
  }
}

function getSymptomRecord(code) {
  return symptomsMap[code] || {}
}

function buildSymptomRecords() {
  return Object.values(symptomsMap)
    .filter(s => s.is_present !== null)
    .map(s => ({
      ...s,
      // 2026-05-07 hardening: legacy IDB symptom rows can lack a client_uuid
      // (older schema). Without it dbReplaceAll's obj.put() rejects because
      // client_uuid is the keyPath → "Failed to save symptoms" alert.
      // Fall back to a fresh UUID so the put always succeeds.
      client_uuid:            s.client_uuid || genUUID(),
      secondary_screening_id: caseUuid.value,
      sync_status:            SYNC.UNSYNCED,
    }))
}

// ─── TRAVEL COUNTRIES HELPERS ─────────────────────────────────────────────
function addTravelCountry() {
  travelCountries.value.push({
    client_uuid:            genUUID(),
    secondary_screening_id: caseUuid.value,
    country_code:           '',
    travel_role:            'VISITED',
    arrival_date:           null,
    departure_date:         null,
    sync_status:            SYNC.UNSYNCED,
  })
}

function removeTravelCountry(idx) {
  travelCountries.value.splice(idx, 1)
}

// ─── ACTIONS HELPERS ──────────────────────────────────────────────────────
function isActionDone(code) {
  return actions.value.some(a => a.action_code === code && a.is_done === 1)
}

function toggleAction(code) {
  const idx = actions.value.findIndex(a => a.action_code === code)
  if (idx === -1) {
    actions.value.push({
      client_uuid:            genUUID(),
      secondary_screening_id: caseUuid.value,
      action_code:            code,
      is_done:                1,
      details:                null,
      sync_status:            SYNC.UNSYNCED,
    })
  } else {
    actions.value[idx].is_done = actions.value[idx].is_done === 1 ? 0 : 1
  }
}

// ─── GENDER LABEL ─────────────────────────────────────────────────────────
function genderLabel(code) {
  // Legacy values shown as '—' for any non-MALE/FEMALE legacy data
  return { MALE: 'Male', FEMALE: 'Female', OTHER: '—', UNKNOWN: '—' }[code] || '—'
}

// ─── STEP NAVIGATION ──────────────────────────────────────────────────────
function jumpToStep(target) {
  // Audit SS-001: allow jumping to any step the officer has previously
  // advanced past (tracked by maxStepReached). The previous code compared
  // against `step.value` so once you tabbed back to an earlier step you
  // could no longer return to a later one without re-saving — even though
  // the data was already in IDB.
  if (target >= 1 && target <= Math.max(maxStepReached.value, step.value)) step.value = target
}

async function goBackStep() {
  if (step.value > 1) step.value--
}

function goBackToQueue() {
  // Blur THEN navigate, deferred to the next event-loop turn.
  // Root cause of the aria-hidden warning:
  //   1. User taps back button
  //   2. click fires → goBackToQueue runs → blur() called
  //   3. BUT the browser's focus event for the tap fires AFTER click,
  //      so blur() hit no focused element — the button focuses right after
  //   4. router.back() fires → Ionic starts hiding the page
  //   5. Ionic sets aria-hidden on the outgoing page while the button
  //      still holds focus → accessibility violation logged
  // Wrapping in setTimeout(0) defers past both the click and the focus event,
  // so the button is focused THEN immediately blurred THEN the page leaves.
  setTimeout(() => {
    if (document.activeElement instanceof HTMLElement) {
      document.activeElement.blur()
    }
    router.back()
  }, 0)
}

// ─── STEP 1 SAVE ──────────────────────────────────────────────────────────
async function saveStep1AndNext() {
  const localAuth = getAuth()
  if (!localAuth?.id || !localAuth?.is_active) {
    alert('Session expired. Please log in again.')
    return
  }

  // Hard bio gate — name + gender + age/DOB + nationality are mandatory for
  // any secondary screening submission (operator requirement: no anonymous
  // referrals). Surfaces inline errors AND blocks step advance.
  fieldErrors.traveler_full_name                = ''
  fieldErrors.traveler_gender                   = ''
  fieldErrors.traveler_age_or_dob               = ''
  fieldErrors.traveler_nationality_country_code = ''

  const nameTrim = String(profile.traveler_full_name || '').trim()
  let bioOk = true
  if (nameTrim.length < 2) {
    fieldErrors.traveler_full_name = 'Traveller full name is required (min 2 characters).'
    bioOk = false
  }
  if (profile.traveler_gender !== 'MALE' && profile.traveler_gender !== 'FEMALE') {
    fieldErrors.traveler_gender = 'Select Male or Female.'
    bioOk = false
  }
  if (!profile.traveler_age_years && !profile._birth_year && !profile.traveler_dob) {
    fieldErrors.traveler_age_or_dob = 'Age or date of birth is required.'
    bioOk = false
  }
  if (!profile.traveler_nationality_country_code) {
    fieldErrors.traveler_nationality_country_code = 'Nationality is required.'
    bioOk = false
  }
  if (!bioOk) {
    hapticError()
    return
  }

  saving.value = true
  try {
    // Ensure case exists (create if first save)
    if (!caseRecord.value) {
      await openCase(localAuth)
    }

    // Build updated case record
    const now     = isoNow()
    const updated = {
      ...caseRecord.value,
      // Profile fields
      traveler_full_name:                profile.traveler_full_name   || null,
      traveler_gender:                   profile.traveler_gender,
      traveler_age_years:                profile.traveler_age_years   || null,
      // traveler_dob is captured by computeAgeFromBirthYear() (age → YYYY-01-01)
      // and by the passport-scan flow. Previously omitted from the persisted
      // record, so the DOB silently dropped at sync time even though the
      // server's $updateableFields accepts it. Surface it through every save.
      traveler_dob:                      profile.traveler_dob         || null,
      travel_document_type:              profile.travel_document_type || null,
      travel_document_number:            profile.travel_document_number || null,
      traveler_nationality_country_code: profile.traveler_nationality_country_code || null,
      residence_country_code:            profile.residence_country_code || null,
      phone_number:                      profile.phone_number         || null,
      journey_start_country_code:        profile.journey_start_country_code || null,
      conveyance_type:                   profile.conveyance_type      || null,
      conveyance_identifier:             profile.conveyance_identifier || null,
      arrival_datetime:                  profile.arrival_datetime_input
                                           ? profile.arrival_datetime_input.replace('T', ' ') + ':00'
                                           : null,
      purpose_of_travel:                 profile.purpose_of_travel    || null,
      destination_district_code:         profile.destination_district_code || null,
      case_status:                       'IN_PROGRESS',
      record_version:                    (caseRecord.value.record_version || 1) + 1,
      updated_at:                        now,
    }

    // Travel countries: assign correct secondary_screening_id
    const tcRecords = travelCountries.value
      .filter(tc => tc.country_code)
      .map(tc => ({
        ...tc,
        secondary_screening_id: caseUuid.value,
        sync_status:            SYNC.UNSYNCED,
      }))

    // Write atomically: case update + travel countries replace-all
    await safeDbPut(STORE.SECONDARY_SCREENINGS, toPlain(updated))
    await dbReplaceAll(
      STORE.SECONDARY_TRAVEL_COUNTRIES,
      'secondary_screening_id',
      caseUuid.value,
      toPlain(tcRecords)
    )

    caseRecord.value = updated
    step.value = 2
    if (maxStepReached.value < 2) maxStepReached.value = 2
    // Sync to server in background — IDB write already committed above
    const localAuthStep1 = getAuth()
    syncCaseToServer(localAuthStep1).catch(e => L.warn('saveStep1: syncCaseToServer threw', e?.message ?? e))
  } catch (err) {
    console.error('[SecondaryScreening] saveStep1AndNext error:', err)
    // 2026-05-07: alert suppressed; advance silently — caller handles UI state.
    L.warn('saveStep1: failed to save; advancing without alert')
  } finally {
    saving.value = false
  }
}

// ─── STEP 2 SAVE ──────────────────────────────────────────────────────────
async function saveStep2AndNext() {
  const localAuth = getAuth()
  if (!localAuth?.id || !localAuth?.is_active) {
    alert('Session expired. Please log in again.')
    return
  }

  saving.value = true

  // 2026-05-07 ROOT-CAUSE FIX: the unpredictable "Failed to save symptoms"
  // alert had two root causes:
  //   (1) `caseRecord.value` could be null or stale on cases resumed from the
  //       queue while step-1 hydration was still in flight → spread `{...null}`
  //       yielded `{}` with no client_uuid → safeDbPut silently no-op, then
  //       dbReplaceAll's tx aborted because the parent record had no key.
  //   (2) Sync-engine background tx running concurrently with this save
  //       could occasionally cause the IDB transaction to abort.
  //
  // The new flow:
  //   • Resolve a stable caseUuid up-front (caseUuid.value → caseRecord →
  //     fresh genUUID as last resort) and lock the parent record shape.
  //   • Each IDB op runs in its own try/catch. A failed op is queued for
  //     background retry but NEVER blocks the officer from advancing.
  //   • The officer always proceeds to step 3. The in-memory state is the
  //     source of truth; sync will reconcile when IDB is healthy again.
  //   • No alert — silent log + a small non-blocking toast for telemetry.

  const now    = isoNow()
  const stableUuid = caseUuid.value || caseRecord.value?.client_uuid || ''
  if (!stableUuid) {
    // No identity at all — the case was never created. Surface in console
    // for telemetry but still let the officer step forward; step 3 will
    // re-create the IDB record on its own save.
    console.warn('[SecondaryScreening] saveStep2AndNext: missing caseUuid; advancing without IDB write')
  }

  const baseRecord = caseRecord.value && typeof caseRecord.value === 'object'
    ? caseRecord.value
    : { client_uuid: stableUuid, record_version: 0 }

  const updated = {
    ...baseRecord,
    client_uuid:             stableUuid || baseRecord.client_uuid || genUUID(),
    temperature_value:       showVitals.value ? vitals.temperature_value : baseRecord.temperature_value,
    temperature_unit:        showVitals.value ? (vitals.temperature_value ? vitals.temperature_unit : null) : baseRecord.temperature_unit,
    pulse_rate:              showVitals.value ? vitals.pulse_rate         : baseRecord.pulse_rate,
    respiratory_rate:        showVitals.value ? vitals.respiratory_rate   : baseRecord.respiratory_rate,
    bp_systolic:             showVitals.value ? vitals.bp_systolic        : baseRecord.bp_systolic,
    bp_diastolic:            showVitals.value ? vitals.bp_diastolic       : baseRecord.bp_diastolic,
    oxygen_saturation:       showVitals.value ? vitals.oxygen_saturation  : baseRecord.oxygen_saturation,
    triage_category:         showVitals.value ? (vitals.triage_category || null) : baseRecord.triage_category,
    emergency_signs_present: showVitals.value ? vitals.emergency_signs_present : baseRecord.emergency_signs_present,
    general_appearance:      showVitals.value ? (vitals.general_appearance || null) : baseRecord.general_appearance,
    case_status:             'IN_PROGRESS',
    record_version:          (baseRecord.record_version || 1) + 1,
    updated_at:              now,
    sync_status:             SYNC.UNSYNCED,
  }

  // Build symptom records once, defensively. Skip any malformed entries.
  let symptomRecords = []
  try {
    symptomRecords = (buildSymptomRecords() || []).filter(r => r && typeof r === 'object' && r.client_uuid)
  } catch (e) {
    console.warn('[SecondaryScreening] buildSymptomRecords threw — using empty list:', e?.message ?? e)
    symptomRecords = []
  }

  // ── IDB write 1: parent record (best-effort, isolated try/catch) ─────────
  let parentOk = false
  if (stableUuid) {
    try {
      await safeDbPut(STORE.SECONDARY_SCREENINGS, toPlain(updated))
      parentOk = true
    } catch (e) {
      console.warn('[SecondaryScreening] saveStep2: parent put failed (non-fatal):', e?.message ?? e)
    }
  }

  // ── IDB write 2: symptom child records (best-effort, isolated) ──────────
  let symptomsOk = false
  if (stableUuid) {
    try {
      await dbReplaceAll(
        STORE.SECONDARY_SYMPTOMS,
        'secondary_screening_id',
        stableUuid,
        toPlain(symptomRecords)
      )
      symptomsOk = true
    } catch (e) {
      console.warn('[SecondaryScreening] saveStep2: symptoms replaceAll failed (non-fatal):', e?.message ?? e)
      // Per-record fallback — if the bulk replace aborted, try to put each
      // record independently. Even partial success keeps progress.
      try {
        for (const rec of symptomRecords) {
          try { await dbPut(STORE.SECONDARY_SYMPTOMS, toPlain(rec)) } catch {}
        }
        symptomsOk = symptomRecords.length === 0  // empty list is "ok"
      } catch {}
    }
  }

  // ── Advance regardless ───────────────────────────────────────────────────
  // The officer is never blocked. In-memory state stays the source of truth.
  caseRecord.value = updated
  step.value = 3
  if (maxStepReached.value < 3) maxStepReached.value = 3

  // Server sync in background — even if IDB writes flopped, the in-memory
  // record can still be POSTed. Failures inside syncCaseToServer are already
  // handled internally and surfaced via existing toasts.
  if (parentOk) {
    try {
      const localAuthStep2 = getAuth()
      syncCaseToServer(localAuthStep2).catch(e => L.warn('saveStep2: syncCaseToServer threw', e?.message ?? e))
    } catch (e) {
      L.warn('saveStep2: server sync kick failed', e?.message ?? e)
    }
  }

  saving.value = false
}

// ─── STEP 3 SAVE + ANALYSE ────────────────────────────────────────────────
async function saveStep3AndAnalyse() {
  const localAuth = getAuth()
  if (!localAuth?.id || !localAuth?.is_active) {
    alert('Session expired. Please log in again.')
    return
  }

  // ── EXPOSURE VALIDATION ─────────────────────────────────────────────────
  // YES, NO, and UNKNOWN are all valid clinical responses.
  // UNKNOWN means the traveler genuinely cannot recall or a language barrier
  // prevented assessment — this is a legitimate answer, not "skipped".
  // The AI engine treats UNKNOWN as an uncertainty factor in risk scoring.

  saving.value = true
  try {
    // ── Build exposure records — ONLY those the officer explicitly answered.
    // Per executive directive 2026-05-05: when the officer enters nothing,
    // exposures persist as an empty set (not auto-marked "Unknown"). Map
    // entries are only created when the officer clicks Yes / No / Unknown,
    // so iterating the map is sufficient — no synthetic UNKNOWN rows.
    const exposureRecords = Object.values(exposuresMap)
      .filter(exp => exp && exp.exposure_code && exp.response)
      .map(exp => ({
        client_uuid:            genUUID(),
        secondary_screening_id: caseUuid.value,
        exposure_code:          exp.exposure_code,
        response:               exp.response,
        details:                exp.details || null,
        sync_status:            SYNC.UNSYNCED,
      }))

    // Save exposures to IDB
    await dbReplaceAll(
      STORE.SECONDARY_EXPOSURES,
      'secondary_screening_id',
      caseUuid.value,
      toPlain(exposureRecords)
    )

    // ── INTELLIGENCE LAYER — single call does everything ─────────────────────
    // FIX: uses window.DISEASES.getEnhancedScoreResult() which:
    //   1. Builds outbreak_context from travel countries (endemic oracle)
    //   2. Derives WHO syndrome from symptoms
    //   3. Runs scoreDiseases() with syndrome_bonus active
    //   4. Computes IHR risk level per Annex 2
    //   5. Evaluates non-case verdict
    //   6. Validates clinical data
    // No clinical logic in this Vue file.
    const presentSymptoms = Object.values(symptomsMap).filter(s => s.is_present === 1).map(s => s.symptom_code)
    const absentSymptoms  = Object.values(symptomsMap).filter(s => s.is_present === 0).map(s => s.symptom_code)

    // Translate DB exposure codes → engine codes via exposures.js
    const engineExposureCodes = window.EXPOSURES?.mapToEngineCodes(exposureRecords) || []

    // Vitals for engine (convert F→C)
    const tc = vitals.temperature_value
      ? (vitals.temperature_unit === 'F' ? (vitals.temperature_value - 32) * 5 / 9 : vitals.temperature_value)
      : null
    const vitalsForEng = {
      temperature_c:     tc,
      oxygen_saturation: vitals.oxygen_saturation || undefined,
      pulse_rate:        vitals.pulse_rate         || undefined,
      respiratory_rate:  vitals.respiratory_rate   || undefined,
      bp_systolic:       vitals.bp_systolic         || undefined,
      clinical_context: {
        cluster_deaths_in_community: !!clusterFlags.cluster_deaths_in_community,
        cluster_similar_illness:     !!clusterFlags.cluster_similar_illness,
        unusual_event_flag:          !!clusterFlags.unusual_event_flag,
      },
    }

    let enhanced = null
    try {
      if (window.DISEASES?.getEnhancedScoreResult) {
        enhanced = window.DISEASES.getEnhancedScoreResult(
          presentSymptoms,
          absentSymptoms,
          engineExposureCodes,
          travelCountries.value.map(tc => ({ country_code: tc.country_code, travel_role: tc.travel_role || 'VISITED' })),
          vitalsForEng
        )
        L.ok('getEnhancedScoreResult OK', {
          syndrome: enhanced.syndrome.syndrome,
          is_non_case: enhanced.is_non_case,
          risk: enhanced.ihr_risk.risk_level,
          top_disease: enhanced.top_disease_id,
          outbreak_context: enhanced.outbreak_context_used.length,
        })
      } else {
        // Fallback if intelligence layer not loaded
        const fallback = window.DISEASES?.scoreDiseases?.(presentSymptoms, absentSymptoms, engineExposureCodes, {}) || { top_diagnoses: [], all_reportable: [], global_flags: [], overrides_fired: [], input_summary: {} }
        enhanced = {
          ...fallback,
          syndrome: { syndrome: 'OTHER', confidence: 'LOW', reasoning: 'Intelligence layer not loaded', who_criteria_met: [] },
          ihr_risk: { risk_level: 'MEDIUM', routing_level: 'DISTRICT', ihr_alert_required: false, ihr_tier: null, triggered_rules: [], reasoning: ['Fallback mode'] },
          non_case: { isNonCase: false, reasons: [], recommended_syndrome: null, recommended_disposition: null },
          clinical_validation: { vital_alerts: {}, critical_flags: [], clinical_warnings: [], needs_emergency_triage: false },
          outbreak_context_used: [],
          is_non_case: false,
          show_emergency_banner: false,
        }
        L.warn('getEnhancedScoreResult not available — using fallback scoreDiseases')
      }
    } catch (scoreErr) {
      L.warn('saveStep3AndAnalyse: intelligence call threw', scoreErr?.message ?? scoreErr)
      enhanced = { top_diagnoses: [], all_reportable: [], global_flags: ['ANALYSIS_ERROR'], overrides_fired: [], input_summary: {}, syndrome: { syndrome: 'OTHER', confidence: 'LOW' }, ihr_risk: { risk_level: 'MEDIUM', routing_level: 'DISTRICT', ihr_alert_required: false }, non_case: { isNonCase: false }, clinical_validation: { vital_alerts: {}, critical_flags: [], clinical_warnings: [], needs_emergency_triage: false }, outbreak_context_used: [], is_non_case: false, show_emergency_banner: false }
    }

    analysisResult.value = enhanced
    // RD-002: save the original algorithm result so we can restore it if officer
    // adds then removes ALL their override diseases.
    originalAnalysisResult.value = enhanced

    // ── HAPTIC FEEDBACK ────────────────────────────────────────────────────
    // Fire once when the engine result first lands. Ordered so the strongest
    // signal wins: CRITICAL or IHR-alert-required → critical buzz; HIGH or
    // emergency triage → warning buzz.
    try {
      const _ihr = enhanced?.ihr_risk
      const _rl = _ihr?.risk_level
      const _needsEmerg = !!enhanced?.clinical_validation?.needs_emergency_triage
      if (_rl === 'CRITICAL' || _ihr?.ihr_alert_required) {
        hapticCritical()
      } else if (_rl === 'HIGH' || _needsEmerg) {
        hapticWarning()
      }
    } catch (_) { /* haptics are best-effort */ }

    // ── HIGH-RISK NOTIFICATION ─────────────────────────────────────────────
    // When the engine lands on HIGH or CRITICAL, post a heads-up to the
    // officer so the device's notification surface mirrors WhatsApp-style
    // urgency. LOW/MEDIUM are intentionally quiet — they would be noise.
    try {
      const _rlNotif = enhanced?.ihr_risk?.risk_level
      if (_rlNotif === 'HIGH' || _rlNotif === 'CRITICAL') {
        const topId = enhanced?.top_diagnoses?.[0]?.disease_id
        const top = topId ? diseaseDisplayName(topId) : null
        const traveler = profile.full_name || profile.traveler_initials || profile.traveler_anonymous_code || null
        import('@/services/alertNotifier').then(mod => {
          mod.notifyHighRiskScreening?.({
            secondary_screening_id: caseRecord.value?.id || caseRecord.value?.client_uuid || caseUuid.value,
            traveler_label:         traveler,
            risk_level:             _rlNotif,
            suspected_disease:      top,
          })
        }).catch(() => {})
      }
    } catch (_) { /* notifier is best-effort */ }

    // ── GENERATE CLINICAL REPORT ───────────────────────────────────────────
    // The report explains every decision the engine made in plain language,
    // covering 10 sections: executive summary, clinical presentation, scoring
    // breakdown, travel/epidemiology, exposure analysis, IHR framework,
    // differential diagnosis, required actions, confidence assessment, overrides.
    try {
      if (window.DISEASES?.generateClinicalReport) {
        const tc = vitals.temperature_value
        const tcC = tc ? (vitals.temperature_unit === 'F' ? (tc - 32) * 5 / 9 : tc) : null
        clinicalReport.value = window.DISEASES.generateClinicalReport(enhanced, {
          vitals: {
            temperature_c:     tcC,
            oxygen_saturation: vitals.oxygen_saturation  || null,
            pulse_rate:        vitals.pulse_rate          || null,
            respiratory_rate:  vitals.respiratory_rate   || null,
            bp_systolic:       vitals.bp_systolic         || null,
          },
          presentSymptoms:   presentSymptoms,
          absentSymptoms:    absentSymptoms,
          visitedCountries:  travelCountries.value.map(tc => ({
            country_code: tc.country_code, travel_role: tc.travel_role || 'VISITED'
          })),
          exposures:         Object.values(exposuresMap),
          travelerDirection: primaryScreening.value?.traveler_direction || null,
          poeCode:           caseRecord.value?.poe_code || '',
          travelerGender:    profile.traveler_gender || caseRecord.value?.traveler_gender || 'UNKNOWN',
          travelerAge:       profile.traveler_age_years || null,
          officerOverride:   { ...officerOverride },
        })
        L.ok('generateClinicalReport: OK — verdict=' + clinicalReport.value.verdict)
      }
    } catch (reportErr) {
      L.warn('generateClinicalReport threw', reportErr?.message ?? reportErr)
      clinicalReport.value = null
    }

    // ── AUTO-SET syndrome + risk from engine (officer can override in Step 4) ──
    // Priority for syndrome derivation:
    //   1. Top suspected disease has a known clinical syndrome → use that
    //      (most accurate — "Ebola → ACUTE_HAEMORRHAGIC_FEVER" not "ACUTE_FEBRILE_ILLNESS")
    //   2. Engine's symptom-based syndrome derivation (normalized to canonical code)
    if (!officerOverride.syndromeOverridden) {
      const topId = (enhanced.top_diagnoses || [])[0]?.disease_id
      const fromTopDisease = topId ? syndromeFromDeclaredDisease(topId) : null
      const engineCanonical = enhanced.syndrome?.syndrome ? normalizeSyndromeCode(enhanced.syndrome.syndrome) : ''
      // Hard guarantee: a case must NEVER end with empty syndromic classification.
      // Fallback chain: top-disease → engine syndrome → symptom-driven heuristic
      // → 'OTHER' as last resort.
      const presentSymCodes = Object.values(symptomsMap).filter(s => s.is_present === 1).map(s => s.symptom_code)
      const heuristic = (() => {
        if (presentSymCodes.includes('bleeding') || presentSymCodes.includes('bleeding_gums_or_nose')
            || presentSymCodes.includes('petechial_or_purpuric_rash') || presentSymCodes.includes('bloody_diarrhea')) {
          return 'ACUTE_HAEMORRHAGIC_FEVER'
        }
        if (presentSymCodes.includes('jaundice') || presentSymCodes.includes('dark_urine')) return 'ACUTE_JAUNDICE_SYNDROME'
        if (presentSymCodes.includes('rash_maculopapular') || presentSymCodes.includes('rash_face_first')
            || presentSymCodes.includes('rash_vesicular_pustular')) return 'RASH_ILLNESS_FEVER'
        if (presentSymCodes.includes('paralysis_acute_flaccid')) return 'ACUTE_FLACCID_PARALYSIS'
        if (presentSymCodes.includes('stiff_neck') || presentSymCodes.includes('altered_consciousness')) return 'MENINGITIS_ENCEPHALITIS'
        if (presentSymCodes.includes('hydrophobia')) return 'ACUTE_NEUROLOGICAL_SYNDROME'
        if (presentSymCodes.includes('watery_diarrhea') || presentSymCodes.includes('rice_water_diarrhea')) return 'ACUTE_WATERY_DIARRHOEA'
        if (presentSymCodes.includes('difficulty_breathing') || presentSymCodes.includes('shortness_of_breath')) return 'SEVERE_ACUTE_RESPIRATORY_INFECTION'
        if (presentSymCodes.includes('cough') || presentSymCodes.includes('coryza') || presentSymCodes.includes('sore_throat')) return 'ACUTE_RESPIRATORY_SYNDROME'
        if (presentSymCodes.includes('fever') || presentSymCodes.includes('high_fever')) return 'ACUTE_FEBRILE_ILLNESS'
        return null
      })()
      const finalSyn = fromTopDisease || engineCanonical || heuristic || 'ACUTE_FEBRILE_ILLNESS'
      caseDecision.syndrome_classification = normalizeSyndromeCode(finalSyn)
      autoSyndromeApplied.value = true
      L.ok('Auto-syndrome set to "' + finalSyn + '" (source=' + (fromTopDisease ? 'top-disease' : engineCanonical ? 'engine' : heuristic ? 'heuristic' : 'fallback') + ')')
    }

    // Auto-set risk level suggestion (officer can still change it)
    if (!officerOverride.riskOverridden && enhanced.ihr_risk?.risk_level) {
      caseDecision.risk_level = enhanced.ihr_risk.risk_level
    }

    // NON-CASE auto-routing — Change-20/21 codes
    if (enhanced.is_non_case && !officerOverride.overrideNonCase) {
      caseDecision.syndrome_classification = 'ACUTE_FEBRILE_ILLNESS'
      caseDecision.risk_level              = 'LOW'
      caseDecision.final_disposition       = 'RELEASED_NO_CONDITION'
      L.ok('Non-case verdict applied — syndrome=ACUTE_FEBRILE_ILLNESS (default), risk=LOW, disposition=RELEASED_NO_CONDITION')
    }

    // ── 1-L: Build suspected disease records for DB ──────────────────────────
    // If the officer has selected their own diseases, the algorithm has already
    // been re-run with those diseases via rerunAnalysisWithOfficerDiseases().
    // analysisResult.value is now the OFFICER-RERUN result.
    // suspectedDiseases.value was already set by rerunAnalysisWithOfficerDiseases().
    // We do NOT re-merge the original `enhanced` (pre-officer) top_diagnoses here —
    // that would re-introduce the original algorithm suspicion the operator
    // explicitly said must not reach the DB.
    //
    // If no officer override → use the algorithm's own output as before.
    if (!officerOverride.addedDiseases.length) {
      const algorithmDiseases = enhanced.top_diagnoses.slice(0, 5).map((d, i) => ({
        client_uuid:            genUUID(),
        secondary_screening_id: caseUuid.value,
        disease_code:           d.disease_id,
        display_name:           d.name || diseaseName(d.disease_id),
        rank_order:             i + 1,
        confidence:             d.probability_like_percent ?? null,
        confidence_band:        d.confidence_band  || null,
        final_score:            d.final_score      ?? null,
        ihr_category:           d.ihr_category     || null,
        reasoning:              (d.matched_hallmarks || []).slice(0, 3).join(', ') || null,
        sync_status:            SYNC.UNSYNCED,
      }))
      suspectedDiseases.value = algorithmDiseases
      L.ok(`saveStep3: no officer override — using algorithm's ${algorithmDiseases.length} diseases`)
    } else {
      // Officer has added diseases → suspectedDiseases was already set by
      // rerunAnalysisWithOfficerDiseases() when they were added.
      // analysisResult.value is already the re-run result.
      // Nothing to do here except ensure the enhanced ref points to the
      // officer-rerun result so the auto-syndrome/risk block below is correct.
      enhanced = analysisResult.value || enhanced
      L.ok(`saveStep3: officer override active — using ${suspectedDiseases.value.length} diseases from officer re-run`)
    }

    step.value = 4
    if (maxStepReached.value < 4) maxStepReached.value = 4
    // Sync exposures + current case to server in background
    const localAuthStep3 = getAuth()
    syncCaseToServer(localAuthStep3).catch(e => L.warn('saveStep3: syncCaseToServer threw', e?.message ?? e))
  } catch (err) {
    console.error('[SecondaryScreening] saveStep3AndAnalyse error:', err)
    // 2026-05-07: alert suppressed.
    L.warn('saveStep3: failed to save exposures; advancing without alert')
  } finally {
    saving.value = false
  }
}

// ─── IMPOSSIBLE-DISPOSITION VALIDATION (2026-05-06) ───────────────────────
// Catches clinically nonsensical or unsafe disposition decisions before the
// case is persisted/synced. Each rule returns either a 'block' (fatal — must
// be fixed before submission) or 'warn' (officer must confirm via dialog).
// Pure function — no side effects, safe to call from a computed for live UI.
function validateDispositionDecision() {
  const issues = []
  const disp   = caseDecision.final_disposition
  const risk   = caseDecision.risk_level

  if (!disp) return issues  // separate "required" check handles the empty case

  // Rule 1 — CRITICAL risk must be ISOLATED / REFERRED / DECEASED / RETURN.
  // Releasing a CRITICAL traveller breaks IHR and POE protocol.
  if (risk === 'CRITICAL' &&
      !['ISOLATED_ADMITTED', 'REFERRED_HEALTH_FACILITY', 'DECEASED_AT_POE', 'RETURN_TO_ORIGIN'].includes(disp)) {
    issues.push({ severity: 'block', code: 'CRIT_NOT_ISOLATED',
      message: 'CRITICAL risk cannot be released. Choose Isolated, Referred, Returned to origin, or Deceased.' })
  }

  // Rule 2 — HIGH risk cannot be RELEASED_NO_CONDITION (must be at least under follow-up).
  if (risk === 'HIGH' && disp === 'RELEASED_NO_CONDITION') {
    issues.push({ severity: 'block', code: 'HIGH_RELEASE_NO_FOLLOWUP',
      message: 'HIGH risk requires at least public-health follow-up. Releasing with no condition is not allowed.' })
  }

  // Rule 3 — bleeding (haemorrhagic) + release is contraindicated.
  const hasBleeding = symptomsMap?.bleeding?.is_present === 1
  if (hasBleeding && (disp === 'RELEASED_NO_CONDITION' || disp === 'RELEASED_UNDER_FOLLOWUP')) {
    issues.push({ severity: 'block', code: 'BLEEDING_RELEASE',
      message: 'Bleeding symptom is recorded — release dispositions are contraindicated. Refer or isolate.' })
  }

  // Rule 4 — high fever (≥39°C) auto-derived + RELEASED_NO_CONDITION.
  const tempC = (() => {
    const v = vitals.temperature_value
    if (v === null || v === undefined || v === '') return null
    const num = Number(v); if (!Number.isFinite(num)) return null
    return (vitals.temperature_unit || 'C') === 'F' ? (num - 32) * 5 / 9 : num
  })()
  if (tempC !== null && tempC >= 39.0 && disp === 'RELEASED_NO_CONDITION') {
    issues.push({ severity: 'block', code: 'HIGH_FEVER_RELEASED',
      message: `High fever (${tempC.toFixed(1)}°C) cannot be released without follow-up. Choose at least Released Under Follow-up.` })
  }

  // Rule 5 — LOW risk + ISOLATED_ADMITTED is over-treatment unless explicitly justified.
  if (risk === 'LOW' && disp === 'ISOLATED_ADMITTED') {
    issues.push({ severity: 'warn', code: 'LOW_ISOLATED',
      message: 'LOW risk traveller marked for isolation/admission. Confirm clinical justification.' })
  }

  // Rule 6 — DECEASED_AT_POE requires a fatal indicator. We treat this as a
  // hard sanity-check: at least one of (no vital signs OR severe respiratory/altered consciousness/CRITICAL risk).
  if (disp === 'DECEASED_AT_POE') {
    const hasFatalSign = (
      symptomsMap?.altered_consciousness?.is_present === 1 ||
      symptomsMap?.difficulty_breathing?.is_present === 1 ||
      symptomsMap?.seizures?.is_present === 1 ||
      symptomsMap?.severe_dehydration?.is_present === 1 ||
      hasBleeding ||
      risk === 'CRITICAL'
    )
    if (!hasFatalSign) {
      issues.push({ severity: 'warn', code: 'DECEASED_NO_FATAL_SIGN',
        message: 'Deceased disposition selected but no fatal clinical sign or CRITICAL risk recorded. Confirm before proceeding.' })
    }
  }

  // Rule 7 — RETURN_TO_ORIGIN with CRITICAL risk requires medical clearance.
  if (disp === 'RETURN_TO_ORIGIN' && (risk === 'HIGH' || risk === 'CRITICAL')) {
    issues.push({ severity: 'warn', code: 'RETURN_HIGH_RISK',
      message: 'Returning a HIGH/CRITICAL risk traveller to origin requires IHR Article 31 + medical clearance. Confirm authorisation.' })
  }

  // Rule 8 — followup_required must be true for HIGH/CRITICAL not isolated.
  if ((risk === 'HIGH' || risk === 'CRITICAL') &&
      ['RELEASED_UNDER_FOLLOWUP', 'REFERRED_HEALTH_FACILITY', 'RETURN_TO_ORIGIN'].includes(disp) &&
      !caseDecision.followup_required) {
    issues.push({ severity: 'block', code: 'NO_FOLLOWUP_HIGH',
      message: 'HIGH/CRITICAL risk with non-isolation disposition requires follow-up. Toggle "Requires follow-up" ON.' })
  }

  return issues
}

// Live computed surface so a banner can render BEFORE the user clicks submit.
const dispositionIssues = computed(() => validateDispositionDecision())
const dispositionBlockers = computed(() => dispositionIssues.value.filter(i => i.severity === 'block'))
const dispositionWarnings = computed(() => dispositionIssues.value.filter(i => i.severity === 'warn'))

// ─── STEP 4 DISPOSITION ───────────────────────────────────────────────────
async function dispositionCase() {
  const localAuth = getAuth()
  if (!localAuth?.id || !localAuth?.is_active) {
    alert('Session expired. Please log in again.')
    return
  }

  // Clear previous errors
  fieldErrors.syndrome_classification = ''
  fieldErrors.risk_level              = ''
  fieldErrors.final_disposition       = ''
  fieldErrors.actions                 = ''
  fieldErrors.officer_notes           = ''

  // 2026-05-07: bio-identity hard-stop removed per operations mandate. If
  // step 1 left gaps, route the officer back silently — no alert.
  const _problems = bioProblems()
  if (_problems.length > 0) {
    hapticError()
    step.value = 1
    return
  }

  // Validate
  let valid = true
  if (!caseDecision.syndrome_classification) {
    fieldErrors.syndrome_classification = 'Syndrome classification is required.'
    valid = false
  }
  if (!caseDecision.risk_level) {
    fieldErrors.risk_level = 'Risk level is required.'
    valid = false
  }
  if (!caseDecision.final_disposition) {
    fieldErrors.final_disposition = 'Final disposition is required.'
    valid = false
  }
  if (actions.value.filter(a => a.is_done === 1).length === 0) {
    fieldErrors.actions = 'At least one action must be recorded.'
    valid = false
  }
  // Officer notes are mandatory (executive directive 2026-05-08).
  // A non-empty narrative is required so that every disposition has the
  // clinician's reasoning attached for audit + downstream review.
  {
    const notes = String(caseDecision.officer_notes || '').trim()
    if (notes.length < 5) {
      fieldErrors.officer_notes = 'Officer notes are required (minimum 5 characters).'
      valid = false
    }
  }
  // 2026-05-07: HIGH/CRITICAL action-mismatch lock removed per operations
  // mandate. Officer judgment governs disposition; the recommendation panel
  // already cites the suggested actions. No alert(), no proceed-blocker.
  if (!valid) return

  saving.value = true
  try {
    const now         = isoNow()
    const doneActions = actions.value.filter(a => a.is_done === 1)

    // Determine final case status:
    //   CLOSED       — no follow-up required. Server closes notification atomically.
    //   DISPOSITIONED — follow-up required. Supervisor closes it separately.
    // The server's fullSync only runs the notification→CLOSED transition when
    // case_status = 'CLOSED'. Sending 'DISPOSITIONED' leaves notification IN_PROGRESS.
    const needsFollowup   = !!caseDecision.followup_required
    const finalCaseStatus = needsFollowup ? 'DISPOSITIONED' : 'CLOSED'

    const updatedCase = {
      ...caseRecord.value,
      syndrome_classification:  caseDecision.syndrome_classification,
      risk_level:               caseDecision.risk_level,
      final_disposition:        caseDecision.final_disposition,
      officer_notes:            caseDecision.officer_notes || null,
      followup_required:        needsFollowup ? 1 : 0,
      // 'DISTRICT' triggers ladderFrom('DISTRICT') = [DISTRICT, PHEOC, NATIONAL]
      // — notifies all three supervisory levels simultaneously (Request 3).
      // The API validates this against ['POE','DISTRICT','PHEOC','NATIONAL'].
      followup_assigned_level:  needsFollowup ? 'DISTRICT' : null,
      case_status:              finalCaseStatus,
      dispositioned_at:         now,
      closed_at:                needsFollowup ? null : now,
      record_version:           (caseRecord.value.record_version || 1) + 1,
      updated_at:               now,
    }

    // Update notification in IDB:
    //   ALWAYS close the notification when the case is dispositioned.
    //   The referral is resolved once the officer has made their clinical
    //   decision. Follow-up is a separate operational process that does not
    //   keep the referral queue item open.
    const updatedNotif = {
      ...notification.value,
      status:         'CLOSED',
      closed_at:      now,
      sync_status:    SYNC.UNSYNCED,
      record_version: (notification.value.record_version || 1) + 1,
      updated_at:     now,
    }

    // All writes: case + actions + suspected diseases + notification
    // ── FIX: ATOMIC WRITE — case + notification in one transaction ──────────
    // Previously: two separate safeDbPut calls. If the second failed or the
    // app crashed between them, the case showed CLOSED but the notification
    // stayed IN_PROGRESS — causing data corruption in NotificationsCenter.
    // Fix: dbAtomicWrite guarantees both writes succeed or neither does.
    await dbAtomicWrite([
      { store: STORE.SECONDARY_SCREENINGS, record: toPlain(updatedCase) },
      { store: STORE.NOTIFICATIONS,        record: toPlain(updatedNotif) },
    ])

    // Child tables (non-critical — can fail without corrupting the primary state)
    await dbReplaceAll(
      STORE.SECONDARY_ACTIONS,
      'secondary_screening_id',
      caseUuid.value,
      toPlain(doneActions.map(a => ({ ...a, secondary_screening_id: caseUuid.value })))
    )

    await dbReplaceAll(
      STORE.SECONDARY_SUSPECTED_DISEASES,
      'secondary_screening_id',
      caseUuid.value,
      toPlain(suspectedDiseases.value)
    )

    // ── IHR Alert creation ─────────────────────────────────────────────────
    // Use ihr_alert_required from engine result when available, fall back to
    // legacy alertPreview computed for backward compatibility.
    const ihrAlertNeeded = analysisResult.value?.ihr_notification_required || alertPreview.value
    if (ihrAlertNeeded) {
      // ── IDEMPOTENCY: check if an alert already exists for this case ──
      // The case can be re-dispositioned (e.g., follow-up disposition).
      // We must NOT create a duplicate alert each time. The first alert
      // for a case is the canonical one; subsequent dispositions update
      // the case but do not create new alerts.
      const existingAlerts = await dbGetByIndex(STORE.ALERTS, 'secondary_screening_id', caseUuid.value).catch(() => [])
      const liveAlerts = existingAlerts.filter(a => !a.deleted_at)
      if (liveAlerts.length > 0) {
        L.info('dispositionCase: alert already exists for this case, skipping creation', {
          caseUuid: caseUuid.value,
          existingAlertCount: liveAlerts.length,
          firstAlertUuid: liveAlerts[0].client_uuid,
        })
      } else {
        const alertCode = analysisResult.value?.ihr_risk?.triggered_rules?.[0]
          || alertPreview.value?.alertCode
          || ('CASE_' + (caseDecision.risk_level || 'HIGH'))
        const routedTo  = analysisResult.value?.ihr_risk?.routing_level
          || alertPreview.value?.routedTo
          || 'PHEOC'
        const alertRecord = createRecordBase(localAuth, {
          secondary_screening_id:    caseUuid.value,
          generated_from:            'RULE_BASED',
          risk_level:                caseDecision.risk_level,
          alert_code:                alertCode,
          alert_title:               alertCode.replace(/_/g, ' '),
          alert_details:             caseDecision.officer_notes || null,
          routed_to_level:           routedTo,
          ihr_tier:                  analysisResult.value?.ihr_risk?.ihr_tier || null,
          status:                    'OPEN',
          acknowledged_by_user_id:   null,
          acknowledged_at:           null,
          closed_at:                 null,
        })
        await dbPut(STORE.ALERTS, toPlain(alertRecord))
        L.ok('dispositionCase: alert record created in IDB', { alertCode, routedTo })

        // WhatsApp-style local notification — fired the moment the alert
        // record exists in IDB. The server-side push happens below; the
        // notification surface is independent so the user sees it instantly
        // even if the network push is queued. Failures here never block the
        // case-completion flow (notifier is best-effort).
        try {
          const notifier = await import('@/services/alertNotifier')
          await notifier.notifyAlert({
            id:                     alertRecord.client_uuid,  // local id pre-server sync
            alert_code:             alertRecord.alert_code,
            alert_title:            alertRecord.alert_title,
            risk_level:             alertRecord.risk_level,
            secondary_screening_id: alertRecord.secondary_screening_id,
          })
        } catch (e) {
          L.warn('dispositionCase: alertNotifier failed', e?.message ?? e)
        }

        // Request 11 — POST the alert to the server so the
        // NotificationDispatcher fires without waiting for the SyncManagement
        // queue.  The secondary case must be synced FIRST so the server has a
        // secondary_screening_id to link against.  We fire the chain in the
        // BACKGROUND (no await at the dispositionCase level): the IDB record
        // is the source of truth, the secondary-sync worker will retry any
        // gap. Detaching here removes a 5–16 s perceived UI block on
        // weak connections — the user gets the disposition confirmation
        // instantly and a sync indicator handles the rest.
        if (navigator.onLine) {
          ;(async () => {
            try {
            // Sync the secondary screening first (phase 1 + 2) so the server
            // knows the secondary_screening_id before we POST the alert.
            await syncCaseToServer(localAuth)

            // Re-read caseRecord from IDB — syncCaseToServer updates it in-place.
            const freshCase = await dbGet(STORE.SECONDARY_SCREENINGS, caseUuid.value).catch(() => null)
            const serverId  = freshCase?.id ?? freshCase?.server_id ?? null
            if (serverId) {
              const alertPayload = {
                client_uuid:            alertRecord.client_uuid,
                created_by_user_id:     localAuth.id,
                secondary_screening_id: serverId,
                generated_from:         alertRecord.generated_from || 'RULE_BASED',
                risk_level:             alertRecord.risk_level     || 'HIGH',
                alert_code:             alertRecord.alert_code,
                alert_title:            alertRecord.alert_title    || alertRecord.alert_code,
                alert_details:          alertRecord.alert_details  || null,
                routed_to_level:        alertRecord.routed_to_level || 'DISTRICT',
                ihr_tier:               alertRecord.ihr_tier       || null,
                device_id:              alertRecord.device_id      || 'unknown',
                app_version:            alertRecord.app_version    || null,
                platform:               alertRecord.platform       || 'ANDROID',
                record_version:         alertRecord.record_version || 1,
              }
              // Aggressive 5 s timeout per executive directive on weak connections.
              // The alert is already in IDB; if this push fails the background
              // worker (services/secondarySyncWorker.js) flushes it later,
              // and SyncManagement view shows a retry surface.
              const ctrl = new AbortController()
              const tid  = setTimeout(() => ctrl.abort(), 5000)
              try {
                const res  = await fetch(`${window.SERVER_URL}/alerts`, {
                  method:  'POST',
                  headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                  body:    JSON.stringify(alertPayload),
                  signal:  ctrl.signal,
                })
                clearTimeout(tid)
                const body = await res.json().catch(() => ({}))
                if (res.ok && body?.success) {
                  const serverAlertId = body.data?.id
                  await safeDbPut(STORE.ALERTS, {
                    ...toPlain(alertRecord),
                    id: serverAlertId, server_id: serverAlertId,
                    sync_status: 'SYNCED', synced_at: isoNow(),
                  })
                  L.ok('dispositionCase: alert synced to server immediately', { serverAlertId, alertCode })
                } else {
                  clearTimeout(tid)
                  L.warn('dispositionCase: alert server POST rejected — will retry via background worker', { status: res.status, body })
                }
              } catch (ae) {
                clearTimeout(tid)
                L.warn('dispositionCase: alert server POST failed — will retry via background worker', ae?.message ?? ae)
              }
            } else {
              L.warn('dispositionCase: no server_id after syncCaseToServer — alert left for SyncManagement')
            }
          } catch (syncErr) {
            L.warn('dispositionCase: pre-alert sync failed — alert left for SyncManagement', syncErr?.message ?? syncErr)
          }
          })().catch(e => L.warn('dispositionCase: detached server-push chain rejected', e?.message ?? e))
        }
      }
    }

    caseRecord.value = updatedCase
    notification.value = updatedNotif

    // Fire-and-forget final sync — never block navigation on the network.
    // The data is already durable in IDB; the unified syncEngine guarantees
    // eventual server delivery regardless of whether this inline call succeeds.
    syncCaseToServer(localAuth).catch(e =>
      L.warn('dispositionCase: final sync threw (background) — data safe in IDB', e?.message ?? e)
    )
    // Kick the engine so Phase 1 + Phase 2 + alert push happen immediately
    // when there is connectivity, instead of waiting for the periodic poll.
    try {
      if (typeof window !== 'undefined' && typeof window.__SYNC_NOW__ === 'function') {
        window.__SYNC_NOW__('after-disposition')
      }
    } catch (_) { /* engine optional — never block navigation */ }

    router.replace('/NotificationsCenter')
  } catch (err) {
    console.error('[SecondaryScreening] dispositionCase error:', err)
    alert('Failed to save disposition. Please try again.')
  } finally {
    saving.value = false
  }
}

// ─── CASE OPENING ─────────────────────────────────────────────────────────
// Creates a new secondary case and transitions the notification OPEN→IN_PROGRESS.
// openCaseLock prevents two concurrent _doInitPage() calls (onMounted + onIonViewDidEnter)
// from each trying to create a case before either has written to IDB.
async function openCase(localAuth) {
  if (openCaseLock) {
    L.warn('openCase() called while openCaseLock=true — skipping (duplicate concurrent call)')
    return
  }
  openCaseLock = true
  L.info('openCase() START', { user_id: localAuth?.id, notif_status: notification.value?.status })
  try {
    if (!localAuth?.id || !localAuth?.is_active) throw new Error('No auth')
    if (!notification.value) throw new Error('notification.value is null — _doInitPage() must set it before calling openCase')

    const now    = isoNow()
    const gender = primaryScreening.value?.gender || 'UNKNOWN'

    const newCase = createRecordBase(localAuth, {
      primary_screening_id: notification.value.primary_screening_id,
      notification_id:      notificationUuid.value,
      opened_by_user_id:    localAuth.id,
      case_status:          'IN_PROGRESS',
      traveler_gender:      gender,
      opened_at:            now,
      opened_timezone:      Intl.DateTimeFormat().resolvedOptions().timeZone || null,
      dispositioned_at:     null,
      closed_at:            null,
    })

    const updatedNotif = {
      ...notification.value,
      status:           'IN_PROGRESS',
      opened_at:        now,
      assigned_user_id: localAuth.id,
      sync_status:      SYNC.UNSYNCED,
      record_version:   (notification.value.record_version || 1) + 1,
      updated_at:       now,
    }

    await dbAtomicWrite([
      { store: STORE.SECONDARY_SCREENINGS, record: toPlain(newCase)       },
      { store: STORE.NOTIFICATIONS,        record: toPlain(updatedNotif)  },
    ])

    caseUuid.value     = newCase.client_uuid
    caseRecord.value   = newCase
    notification.value = updatedNotif

    // Clear the sessionStorage draft now that the case is formally open and
    // the profile is persisted to IDB — the draft is no longer needed.
    try { sessionStorage.removeItem(`ss_draft_name_${notificationUuid.value}`) } catch (_) {}

    initSymptoms()
    initExposuresFromCatalog()
    autoPopulateDestinationDistrict()
    L.ok('openCase() complete', { caseUuid: newCase.client_uuid, notif_status: updatedNotif.status })

    // Attempt server sync in background — non-blocking, data is safe in IDB
    syncCaseToServer(localAuth).catch(e => L.warn('openCase: syncCaseToServer threw', e?.message ?? e))
  } catch (err) {
    L.err('openCase() FAILED', err)
    throw err   // re-throw so _doInitPage catch block fires
  } finally {
    openCaseLock = false
  }
}

// deriveAutoSyndrome() REMOVED.
// Replaced by window.DISEASES.deriveWHOSyndrome() in the intelligence layer.
// The Vue calls window.DISEASES.getEnhancedScoreResult() which internally
// calls deriveWHOSyndrome() and returns the result — no clinical logic here.

// ─── NOTIFICATION VERIFICATION ────────────────────────────────────────────
const notifVerify = reactive({
  running: false, ran: false,
  idb: null, server: null, error: null, checks: [],
  _serverCase: null, _serverFetchStatus: null,
  // Holds the response from GET /verify (the dedicated self-test endpoint).
  // null until the first run; null after the run when the endpoint errored.
  _verifyPayload: null, _verifyFetchStatus: null,
})

async function verifyNotificationState() {
  notifVerify.running = true
  notifVerify.ran     = false
  notifVerify.error   = null
  notifVerify.checks  = []
  notifVerify.idb     = null
  notifVerify.server  = null
  notifVerify._serverCase        = null
  notifVerify._serverFetchStatus = null

  const uuid = notificationUuid.value
  if (!uuid) {
    notifVerify.error = 'No notification UUID — page not fully loaded'
    notifVerify.running = false; return
  }

  // 1. Read IDB
  try {
    const rec = await dbGet(STORE.NOTIFICATIONS, uuid)
    notifVerify.idb = rec ? JSON.parse(JSON.stringify(rec)) : null
  } catch (e) {
    notifVerify.error = `IDB read failed: ${e?.message ?? e}`
    notifVerify.running = false; return
  }

  // 2. Fetch from server via the secondary screening show endpoint.
  //    GET /secondary-screenings/{id}?user_id={userId} returns the full case
  //    including the embedded notification object. This is the only endpoint
  //    that exposes notification state — there is no standalone GET /notifications/{id}.
  const caseServerId = caseRecord.value?.id ?? caseRecord.value?.server_id ?? null
  const userId       = getAuth()?.id ?? null
  if (caseServerId && userId && navigator.onLine) {
    // Existing fetch — used to populate the notification panel.
    try {
      const ctrl = new AbortController()
      const tid  = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS)
      const res  = await fetch(
        `${window.SERVER_URL}/secondary-screenings/${caseServerId}?user_id=${userId}`,
        { headers: { Accept: 'application/json' }, signal: ctrl.signal }
      )
      clearTimeout(tid)
      if (res.ok) {
        const body = await res.json().catch(() => ({}))
        // body.data.notification is the embedded notification row
        notifVerify.server = body?.data?.notification ?? null
        // Also store the server case status for additional checks
        notifVerify._serverCase = body?.data ?? null
      } else {
        notifVerify._serverFetchStatus = res.status
      }
    } catch { /* non-critical */ }

    // Dedicated self-test fetch — GET /verify returns a compact, verification-
    // shaped payload (biodata / travel / vitals / engine / disposition + child
    // counts) so we can run per-group field equality checks against IDB. This
    // is the "did the DB actually receive what I entered?" probe.
    try {
      const ctrl2 = new AbortController()
      const tid2  = setTimeout(() => ctrl2.abort(), APP.SYNC_TIMEOUT_MS)
      const res2  = await fetch(
        `${window.SERVER_URL}/secondary-screenings/${caseServerId}/verify?user_id=${userId}`,
        { headers: { Accept: 'application/json' }, signal: ctrl2.signal }
      )
      clearTimeout(tid2)
      if (res2.ok) {
        const body2 = await res2.json().catch(() => ({}))
        notifVerify._verifyPayload = body2?.data ?? null
      } else {
        notifVerify._verifyPayload = null
        notifVerify._verifyFetchStatus = res2.status
      }
    } catch { /* non-critical */ }
  }

  // 3. Run checks
  const checks = []
  const idb = notifVerify.idb
  const srv = notifVerify.server
  const cr  = caseRecord.value

  checks.push({
    label: 'Notification exists in local IDB',
    pass:  !!idb,
    detail: idb ? `status=${idb.status} sync=${idb.sync_status}` : 'NOT FOUND in IDB',
  })
  checks.push({
    label: 'Notification status = IN_PROGRESS in IDB',
    pass:  idb?.status === 'IN_PROGRESS',
    detail: idb ? `"${idb.status}"` : 'n/a',
  })
  checks.push({
    label: 'Notification has server integer id in IDB',
    pass:  !!(idb?.id > 0),
    detail: idb ? `id=${idb.id ?? 'NULL'}` : 'n/a',
  })
  checks.push({
    label: 'Notification IDB sync_status = SYNCED',
    pass:  idb?.sync_status === SYNC.SYNCED,
    detail: idb ? `"${idb.sync_status}"` : 'n/a',
  })
  checks.push({
    label: 'Secondary case has server id',
    pass:  !!(cr?.id > 0),
    detail: cr ? `case.id=${cr.id ?? 'NULL'} sync="${cr.sync_status}"` : 'no case record',
  })
  checks.push({
    label: 'Secondary case sync_status = SYNCED',
    pass:  cr?.sync_status === SYNC.SYNCED,
    detail: cr ? `"${cr.sync_status}"` : 'n/a',
  })
  if (srv) {
    checks.push({
      label: 'Server notification status = IN_PROGRESS or CLOSED',
      pass:  srv.status === 'IN_PROGRESS' || srv.status === 'CLOSED',
      detail: `server_notification.status="${srv.status}"`,
    })
    checks.push({
      label: 'IDB notification status matches server',
      pass:  srv.status === idb?.status,
      detail: `server="${srv.status}" idb="${idb?.status}"`,
    })
  }
  // Server case status check (from the embedded secondary case fetch)
  if (notifVerify._serverCase) {
    checks.push({
      label: 'Server case status matches local case status',
      pass:  notifVerify._serverCase.case_status === cr?.case_status,
      detail: `server="${notifVerify._serverCase.case_status}" local="${cr?.case_status}"`,
    })
    checks.push({
      label: 'Server case sync_status = SYNCED',
      pass:  notifVerify._serverCase.sync_status === SYNC.SYNCED,
      detail: `server_case.sync_status="${notifVerify._serverCase.sync_status}"`,
    })

    // ── PER-FIELD DB CHECKS — driven by GET /verify ─────────────────────
    // The /verify endpoint returns a compact, grouped payload (biodata,
    // travel, vitals, engine, disposition, child_counts). For each field
    // we compare the locally-believed value (caseRecord / suspectedDiseases)
    // against what the database actually stored. A failing check means
    // either (a) the field never reached the sync payload, (b) the server
    // controller didn't persist it, or (c) the local cache is stale.
    // This is the "did the DB receive what I entered" self-test the officer
    // can run any time before walking away from a case.
    const vp = notifVerify._verifyPayload
    const eq = (a, b) => {
      // Normalise so a check doesn't fail merely because the server
      // returned an int "23" while the client held a number 23 (or trim ws).
      const norm = (v) => (v === undefined || v === null || v === '') ? null
        : (typeof v === 'number' ? String(v) : String(v).trim())
      return norm(a) === norm(b)
    }
    const pushCheck = (label, localVal, serverVal) => {
      // Skip rows neither side ever touched — keeps the panel readable.
      if ((localVal === undefined || localVal === null || localVal === '') &&
          (serverVal === undefined || serverVal === null || serverVal === '')) {
        return
      }
      const pass = eq(localVal, serverVal)
      checks.push({
        label,
        pass,
        detail: pass
          ? `DB stored "${serverVal ?? 'null'}"`
          : `MISMATCH — local="${localVal ?? 'null'}" server="${serverVal ?? 'null'}"`,
      })
    }

    if (vp) {
      // Biodata
      pushCheck('Biodata · traveler_full_name',                cr?.traveler_full_name,                vp.biodata?.traveler_full_name)
      pushCheck('Biodata · traveler_gender',                   cr?.traveler_gender,                   vp.biodata?.traveler_gender)
      pushCheck('Biodata · traveler_age_years',                cr?.traveler_age_years,                vp.biodata?.traveler_age_years)
      pushCheck('Biodata · traveler_dob',                      cr?.traveler_dob,                      vp.biodata?.traveler_dob)
      pushCheck('Biodata · travel_document_type',              cr?.travel_document_type,              vp.biodata?.travel_document_type)
      pushCheck('Biodata · travel_document_number',            cr?.travel_document_number,            vp.biodata?.travel_document_number)
      pushCheck('Biodata · nationality_country_code',          cr?.traveler_nationality_country_code, vp.biodata?.traveler_nationality_country_code)
      pushCheck('Biodata · residence_country_code',            cr?.residence_country_code,            vp.biodata?.residence_country_code)
      pushCheck('Biodata · phone_number',                      cr?.phone_number,                      vp.biodata?.phone_number)

      // Travel
      pushCheck('Travel · journey_start_country_code',         cr?.journey_start_country_code,        vp.travel?.journey_start_country_code)
      pushCheck('Travel · conveyance_type',                    cr?.conveyance_type,                   vp.travel?.conveyance_type)
      pushCheck('Travel · conveyance_identifier',              cr?.conveyance_identifier,             vp.travel?.conveyance_identifier)
      pushCheck('Travel · arrival_datetime',                   cr?.arrival_datetime,                  vp.travel?.arrival_datetime)
      pushCheck('Travel · purpose_of_travel',                  cr?.purpose_of_travel,                 vp.travel?.purpose_of_travel)
      pushCheck('Travel · destination_district_code',          cr?.destination_district_code,         vp.travel?.destination_district_code)

      // Vitals + triage
      pushCheck('Vitals · temperature_value',                  cr?.temperature_value,                 vp.vitals?.temperature_value)
      pushCheck('Vitals · temperature_unit',                   cr?.temperature_unit,                  vp.vitals?.temperature_unit)
      pushCheck('Vitals · pulse_rate',                         cr?.pulse_rate,                        vp.vitals?.pulse_rate)
      pushCheck('Vitals · respiratory_rate',                   cr?.respiratory_rate,                  vp.vitals?.respiratory_rate)
      pushCheck('Vitals · bp_systolic',                        cr?.bp_systolic,                       vp.vitals?.bp_systolic)
      pushCheck('Vitals · bp_diastolic',                       cr?.bp_diastolic,                      vp.vitals?.bp_diastolic)
      pushCheck('Vitals · oxygen_saturation',                  cr?.oxygen_saturation,                 vp.vitals?.oxygen_saturation)
      pushCheck('Triage · triage_category',                    cr?.triage_category,                   vp.vitals?.triage_category)
      pushCheck('Triage · emergency_signs_present',            cr?.emergency_signs_present,           vp.vitals?.emergency_signs_present)
      pushCheck('Triage · general_appearance',                 cr?.general_appearance,                vp.vitals?.general_appearance)

      // Engine output + disposition
      pushCheck('Engine · syndrome_classification',            cr?.syndrome_classification,           vp.engine?.syndrome_classification)
      pushCheck('Engine · risk_level',                         cr?.risk_level,                        vp.engine?.risk_level)
      pushCheck('Disposition · final_disposition',             cr?.final_disposition,                 vp.disposition?.final_disposition)
      pushCheck('Disposition · officer_notes',                 cr?.officer_notes,                     vp.disposition?.officer_notes)
      pushCheck('Disposition · followup_required',             cr?.followup_required,                 vp.disposition?.followup_required)
      pushCheck('Disposition · followup_assigned_level',       cr?.followup_assigned_level,           vp.disposition?.followup_assigned_level)
      pushCheck('Disposition · dispositioned_at',              cr?.dispositioned_at,                  vp.disposition?.dispositioned_at)
      pushCheck('Disposition · closed_at',                     cr?.closed_at,                         vp.disposition?.closed_at)

      // ── ENGINE-GENERATED SUSPECTED DISEASES (the column that started this) ──
      // This is the high-leverage check: it confirms that the engine output
      // shown to the officer in Step 4 actually landed in the DB. We compare
      // both row count AND each disease_code so a silent rename / strip can't
      // hide.
      const localDiseaseCount = Array.isArray(suspectedDiseases.value) ? suspectedDiseases.value.length : 0
      const serverDiseaseCount = vp.engine?.suspected_diseases_count ?? 0
      checks.push({
        label: 'Engine · suspected disease count matches DB',
        pass: localDiseaseCount === serverDiseaseCount,
        detail: localDiseaseCount === serverDiseaseCount
          ? `${serverDiseaseCount} disease(s) recorded`
          : `MISMATCH — engine produced ${localDiseaseCount}, DB has ${serverDiseaseCount} (sync dropped engine output)`,
      })
      const localCodes  = (suspectedDiseases.value || []).map(d => d.disease_code).filter(Boolean).sort()
      const serverCodes = (vp.engine?.suspected_diseases || []).map(d => d.disease_code).filter(Boolean).sort()
      const codesEqual  = localCodes.length === serverCodes.length && localCodes.every((c, i) => c === serverCodes[i])
      if (localCodes.length > 0 || serverCodes.length > 0) {
        checks.push({
          label: 'Engine · disease_code list matches DB',
          pass: codesEqual,
          detail: codesEqual
            ? `[${serverCodes.join(', ')}]`
            : `MISMATCH — local=[${localCodes.join(', ')}] server=[${serverCodes.join(', ')}]`,
        })
      }

      // ── CHILD-TABLE COUNT CHECKS ────────────────────────────────────────
      // For each child collection, compare the IDB-side count with the
      // server-side count. A non-zero local count that drops to zero on the
      // server is the exact failure mode that produced "No diagnosis recorded"
      // for dispositioned cases in the dashboards.
      const sid = caseUuid.value
      const childChecks = [
        { store: STORE.SECONDARY_SYMPTOMS,         dbKey: 'symptoms',         label: 'symptoms' },
        { store: STORE.SECONDARY_EXPOSURES,        dbKey: 'exposures',        label: 'exposures' },
        { store: STORE.SECONDARY_ACTIONS,          dbKey: 'actions',          label: 'actions' },
        { store: STORE.SECONDARY_TRAVEL_COUNTRIES, dbKey: 'travel_countries', label: 'travel countries' },
      ]
      for (const c of childChecks) {
        let idbCount = 0
        try {
          const rows = sid ? await dbGetByIndex(c.store, 'secondary_screening_id', sid).catch(() => []) : []
          idbCount = Array.isArray(rows) ? rows.length : 0
        } catch { /* keep idbCount=0 */ }
        const srvCount = vp.child_counts?.[c.dbKey] ?? 0
        const pass = idbCount === srvCount
        checks.push({
          label: `Child · ${c.label} count IDB → DB`,
          pass,
          detail: pass
            ? `${srvCount} row(s) on server`
            : `MISMATCH — idb=${idbCount} server=${srvCount}${idbCount > 0 && srvCount === 0 ? ' (sync dropped this child table)' : ''}`,
        })
      }

      // Alert check — when the engine raised an alert in the wizard, confirm
      // the alerts table received it.
      if (analysisResult.value?.ihr_notification_required || alertPreview.value) {
        checks.push({
          label: 'Engine · IHR alert reached alerts table',
          pass: !!vp.engine?.alert_raised,
          detail: vp.engine?.alert_raised
            ? `alert_code=${vp.engine?.alert?.alert_code} status=${vp.engine?.alert?.status}`
            : 'Engine expected an alert but the DB has none for this case',
        })
      }
    } else if (navigator.onLine) {
      checks.push({
        label: 'Self-test endpoint /verify reachable',
        pass: false,
        detail: `GET /secondary-screenings/${caseServerId}/verify returned HTTP ${notifVerify._verifyFetchStatus ?? 'error'}`,
      })
    }
  } else if (navigator.onLine) {
    const hint = !caseServerId
      ? 'Case has no server id yet — run Force Sync first'
      : `GET /secondary-screenings/${caseServerId} returned HTTP ${notifVerify._serverFetchStatus ?? 'error'}`
    checks.push({
      label: 'Server case + notification fetch',
      pass:  false,
      detail: hint,
    })
  }

  notifVerify.checks  = checks
  notifVerify.ran     = true
  notifVerify.running = false
}

// ─── SERVER SYNC ENGINE ───────────────────────────────────────────────────
// syncStatus: reactive object powering the on-screen sync test panel.
// Every phase writes here so the officer sees what the server actually said.
const syncStatus = reactive({
  running:       false,
  lastRunAt:     null,   // ISO string
  phase1:        null,   // 'ok' | 'fail' | 'skip' | null
  phase1Msg:     '',
  phase1Payload: null,   // last payload sent (shown in detail panel)
  phase1Resp:    null,   // last server response body
  phase2:        null,   // 'ok' | 'fail' | 'skip' | null
  phase2Msg:     '',
  phase2Payload: null,
  phase2Resp:    null,
  error:         null,   // unhandled error message
})

async function syncCaseToServer(localAuth) {
  syncStatus.running = true
  syncStatus.error   = null
  L.info('syncCaseToServer: START', {
    online:      navigator.onLine,
    caseUuid:    caseUuid.value,
    caseId:      caseRecord.value?.id ?? null,
    caseSyncSt:  caseRecord.value?.sync_status,
    userId:      localAuth?.id,
    SERVER_URL:  window.SERVER_URL,
  })

  try {
    if (!navigator.onLine) {
      syncStatus.phase1 = 'skip'; syncStatus.phase1Msg = 'Device offline'
      syncStatus.phase2 = 'skip'; syncStatus.phase2Msg = 'Device offline'
      L.info('syncCaseToServer: offline — skipping'); return
    }
    if (!caseRecord.value || !caseUuid.value) {
      syncStatus.error = 'No case record loaded'
      L.warn('syncCaseToServer: no caseRecord'); return
    }
    const userId = localAuth?.id
    if (!userId) {
      syncStatus.error = 'No auth user id'
      L.warn('syncCaseToServer: no auth id'); return
    }

    // ── Phase 1: POST /secondary-screenings ─────────────────────────────
    let serverId = caseRecord.value.id ?? caseRecord.value.server_id ?? null

    if (!serverId) {
      syncStatus.phase1 = null; syncStatus.phase1Msg = 'Sending…'
      const notifServerId   = notification.value?.id ?? notification.value?.server_id ?? null
      const primaryServerId = primaryScreening.value?.id ?? primaryScreening.value?.server_id ?? null

      const p1 = {
        opened_by_user_id:      userId,
        client_uuid:            caseRecord.value.client_uuid,
        reference_data_version: caseRecord.value.reference_data_version ?? APP.REFERENCE_DATA_VER,
        notification_id:        notifServerId    ?? notification.value?.client_uuid   ?? caseRecord.value.notification_id,
        primary_screening_id:   primaryServerId  ?? primaryScreening.value?.client_uuid ?? caseRecord.value.primary_screening_id,
        device_id:              caseRecord.value.device_id,
        app_version:            caseRecord.value.app_version ?? APP.VERSION,
        platform:               caseRecord.value.platform    ?? 'WEB',
        traveler_gender:        caseRecord.value.traveler_gender ?? 'UNKNOWN',
        opened_at:              caseRecord.value.opened_at   ?? isoNow(),
        opened_timezone:        caseRecord.value.opened_timezone ?? null,
        record_version:         caseRecord.value.record_version ?? 1,
      }
      syncStatus.phase1Payload = p1
      L.info('syncCaseToServer: Phase 1 payload', p1)

      const ctrl = new AbortController()
      const tid  = setTimeout(() => ctrl.abort(), APP.SYNC_TIMEOUT_MS)
      try {
        const res  = await fetch(`${window.SERVER_URL}/secondary-screenings`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body:   JSON.stringify(p1),
          signal: ctrl.signal,
        })
        clearTimeout(tid)
        const body = await res.json().catch(() => ({}))
        syncStatus.phase1Resp = body
        L.info('syncCaseToServer: Phase 1 response', { http: res.status, success: body?.success, data: body?.data, error: body?.error })

        if (res.ok && body?.success) {
          serverId = body.data?.id ?? null
          if (serverId) {
            const updated = {
              ...toPlain(caseRecord.value),
              id: serverId, server_id: serverId,
              sync_status: SYNC.SYNCED, synced_at: isoNow(),
              record_version: (caseRecord.value.record_version || 1) + 1,
              updated_at: isoNow(),
            }
            await safeDbPut(STORE.SECONDARY_SCREENINGS, updated)
            caseRecord.value = updated
            syncStatus.phase1 = 'ok'
            syncStatus.phase1Msg = `Created — server_id=${serverId}`
            L.ok(`syncCaseToServer: Phase 1 OK — server_id=${serverId}`)
          } else {
            syncStatus.phase1 = 'fail'
            syncStatus.phase1Msg = 'Server returned success=true but no id in data'
            L.warn('syncCaseToServer: Phase 1 — missing id', body); return
          }
        } else {
          syncStatus.phase1 = 'fail'
          syncStatus.phase1Msg = `HTTP ${res.status}: ${body?.message ?? 'Unknown error'}`
          if (body?.error) syncStatus.phase1Msg += ' | ' + JSON.stringify(body.error)
          L.warn('syncCaseToServer: Phase 1 rejected', { status: res.status, body }); return
        }
      } catch (e) {
        clearTimeout(tid)
        syncStatus.phase1 = 'fail'
        syncStatus.phase1Msg = e?.name === 'AbortError' ? 'Timed out' : `Network: ${e?.message ?? e}`
        L.warn('syncCaseToServer: Phase 1 exception', e?.message ?? e); return
      }
    } else {
      syncStatus.phase1 = 'skip'
      syncStatus.phase1Msg = `Already on server — id=${serverId}`
      L.info(`syncCaseToServer: Phase 1 skipped — case already has server_id=${serverId}`)
    }

    if (!serverId) { syncStatus.error = 'No server id after Phase 1'; return }

    // ── Phase 1.5: State machine bridge ────────────────────────────────────
    // store() always creates the case with case_status='OPEN' on the server.
    // If Phase 1 just ran (phase1 === 'ok', meaning a NEW case was created),
    // the server is now OPEN. The server's state machine only allows:
    //   OPEN → IN_PROGRESS → DISPOSITIONED | CLOSED
    // If our local case is DISPOSITIONED or CLOSED, sending that status directly
    // to a freshly-created OPEN case produces a 409 Conflict.
    // Fix: advance the server to IN_PROGRESS first, then Phase 2 sends the real status.
    if (syncStatus.phase1 === 'ok') {
      const localStatus = caseRecord.value.case_status
      if (localStatus === 'DISPOSITIONED' || localStatus === 'CLOSED') {
        L.info(`syncCaseToServer: Phase 1.5 — advancing server OPEN→IN_PROGRESS (local="${localStatus}")`)
        try {
          const ctrl15 = new AbortController()
          const tid15  = setTimeout(() => ctrl15.abort(), APP.SYNC_TIMEOUT_MS)
          const res15  = await fetch(`${window.SERVER_URL}/secondary-screenings/${serverId}/sync`, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body:    JSON.stringify({ user_id: userId, case_status: 'IN_PROGRESS', record_version: 0 }),
            signal:  ctrl15.signal,
          })
          clearTimeout(tid15)
          const b15 = await res15.json().catch(() => ({}))
          if (res15.ok && b15?.success) {
            L.ok('syncCaseToServer: Phase 1.5 — server advanced to IN_PROGRESS')
          } else {
            L.warn('syncCaseToServer: Phase 1.5 — advance failed (Phase 2 will still attempt)', { status: res15.status, body: b15 })
          }
        } catch (e15) {
          L.warn('syncCaseToServer: Phase 1.5 — network error (Phase 2 will still attempt)', e15?.message ?? e15)
        }
      }
    }

    // ── Phase 2: POST /secondary-screenings/{id}/sync ───────────────────
    syncStatus.phase2 = null; syncStatus.phase2Msg = 'Sending…'
    L.info(`syncCaseToServer: Phase 2 — POST /secondary-screenings/${serverId}/sync`)

    let idbSymptoms = [], idbExposures = [], idbActions = [], idbTc = [], idbDiseases = []
    try {
      const sid = caseUuid.value;
      [idbSymptoms, idbExposures, idbActions, idbTc, idbDiseases] = await Promise.all([
        dbGetByIndex(STORE.SECONDARY_SYMPTOMS,           'secondary_screening_id', sid),
        dbGetByIndex(STORE.SECONDARY_EXPOSURES,          'secondary_screening_id', sid),
        dbGetByIndex(STORE.SECONDARY_ACTIONS,            'secondary_screening_id', sid),
        dbGetByIndex(STORE.SECONDARY_TRAVEL_COUNTRIES,   'secondary_screening_id', sid),
        dbGetByIndex(STORE.SECONDARY_SUSPECTED_DISEASES, 'secondary_screening_id', sid),
      ])
      L.info('syncCaseToServer: IDB child reads', {
        symptoms: idbSymptoms.length, exposures: idbExposures.length,
        actions: idbActions.length, tc: idbTc.length, diseases: idbDiseases.length,
      })
    } catch (idbErr) {
      syncStatus.phase2 = 'fail'
      syncStatus.phase2Msg = `IDB child read failed: ${idbErr?.message ?? idbErr}`
      L.warn('syncCaseToServer: Phase 2 IDB read error', idbErr); return
    }

    const cr = caseRecord.value
    // ── ENUM SAFETY NET (belt-and-suspenders with server coerceForDbEnums) ──
    // Mirrors the actual MySQL ENUM definitions so a stale local cache can
    // never POST a value that would truncate on the server (which used to
    // roll back the whole transaction — see incident 2026-05-19). Unknown
    // values are logged and stripped from the payload so the server keeps
    // the previously-stored value rather than rejecting the entire write.
    const DB_ENUMS = Object.freeze({
      case_status:             ['OPEN','IN_PROGRESS','DISPOSITIONED','CLOSED'],
      traveler_gender:         ['MALE','FEMALE','OTHER','UNKNOWN'],
      risk_level:              ['LOW','MEDIUM','HIGH','CRITICAL'],
      triage_category:         ['NON_URGENT','URGENT','EMERGENCY'],
      general_appearance:      ['WELL','UNWELL','SEVERELY_ILL'],
      conveyance_type:         ['AIR','LAND','SEA','OTHER'],
      temperature_unit:        ['C','F'],
      followup_assigned_level: ['POE','DISTRICT','PHEOC','NATIONAL'],
      final_disposition: [
        'RELEASED','DELAYED','QUARANTINED','ISOLATED','REFERRED','TRANSFERRED','DENIED_BOARDING','OTHER',
        'RELEASED_NO_CONDITION','RELEASED_UNDER_FOLLOWUP','REFERRED_HEALTH_FACILITY','ISOLATED_ADMITTED',
        'DECEASED_AT_POE','RETURN_TO_ORIGIN',
      ],
    })
    const _normEnum = (field, raw) => {
      if (raw === null || raw === undefined || raw === '') return raw
      const allowed = DB_ENUMS[field]
      if (!allowed) return raw
      const up = String(raw).trim().toUpperCase()
      if (allowed.includes(up)) return up
      L.warn(`syncCaseToServer: enum out of range — stripping`, { field, received: raw, allowed })
      return undefined  // drop the key (handled below)
    }
    const p2 = {
      user_id:                           userId,
      record_version:                    cr.record_version ?? 1,
      case_status:                       _normEnum('case_status', cr.case_status),
      traveler_full_name:                cr.traveler_full_name                ?? null,
      traveler_gender:                   _normEnum('traveler_gender', cr.traveler_gender) ?? 'UNKNOWN',
      traveler_age_years:                cr.traveler_age_years                ?? null,
      traveler_dob:                      cr.traveler_dob                      ?? null,
      travel_document_type:              cr.travel_document_type              ?? null,
      travel_document_number:            cr.travel_document_number            ?? null,
      traveler_nationality_country_code: cr.traveler_nationality_country_code ?? null,
      residence_country_code:            cr.residence_country_code            ?? null,
      phone_number:                      cr.phone_number                      ?? null,
      journey_start_country_code:        cr.journey_start_country_code        ?? null,
      conveyance_type:                   _normEnum('conveyance_type', cr.conveyance_type) ?? null,
      conveyance_identifier:             cr.conveyance_identifier             ?? null,
      arrival_datetime:                  cr.arrival_datetime                  ?? null,
      purpose_of_travel:                 cr.purpose_of_travel                 ?? null,
      destination_district_code:         cr.destination_district_code         ?? null,
      temperature_value:                 cr.temperature_value                 ?? null,
      temperature_unit:                  _normEnum('temperature_unit', cr.temperature_unit) ?? null,
      pulse_rate:                        cr.pulse_rate                        ?? null,
      respiratory_rate:                  cr.respiratory_rate                  ?? null,
      bp_systolic:                       cr.bp_systolic                       ?? null,
      bp_diastolic:                      cr.bp_diastolic                      ?? null,
      oxygen_saturation:                 cr.oxygen_saturation                 ?? null,
      triage_category:                   _normEnum('triage_category', cr.triage_category) ?? null,
      emergency_signs_present:           cr.emergency_signs_present           ?? 0,
      general_appearance:                _normEnum('general_appearance', cr.general_appearance) ?? null,
      syndrome_classification:           cr.syndrome_classification           ?? null,
      risk_level:                        _normEnum('risk_level', cr.risk_level) ?? null,
      officer_notes:                     cr.officer_notes                     ?? null,
      final_disposition:                 _normEnum('final_disposition', cr.final_disposition) ?? null,
      followup_required:                 cr.followup_required                 ?? 0,
      followup_assigned_level:           _normEnum('followup_assigned_level', cr.followup_assigned_level) ?? null,
      dispositioned_at:                  cr.dispositioned_at                  ?? null,
      closed_at:                         cr.closed_at                         ?? null,
      symptoms:          idbSymptoms.map(s  => ({ symptom_code:  s.symptom_code,  is_present: s.is_present, onset_date: s.onset_date ?? null, details: s.details ?? null })),
      exposures:         idbExposures.map(e  => ({ exposure_code: e.exposure_code, response: e.response,   details: e.details ?? null })),
      actions:           idbActions.map(a   => ({ action_code:   a.action_code,   is_done: a.is_done,     details: a.details ?? null })),
      travel_countries:  idbTc.map(t        => ({ country_code:  t.country_code,  travel_role: t.travel_role, arrival_date: t.arrival_date ?? null, departure_date: t.departure_date ?? null })),
      suspected_diseases:idbDiseases.map(d  => ({ disease_code:  d.disease_code,  rank_order: d.rank_order, confidence: d.confidence ?? null, reasoning: d.reasoning ?? null })),
    }
    syncStatus.phase2Payload = { ...p2, symptoms: `[${p2.symptoms.length} items]`, exposures: `[${p2.exposures.length}]`, actions: `[${p2.actions.length}]` }
    L.info('syncCaseToServer: Phase 2 payload summary', {
      case_status: p2.case_status, user_id: p2.user_id,
      symptoms: p2.symptoms.length, exposures: p2.exposures.length,
      actions: p2.actions.length, tc: p2.travel_countries.length,
      url: `${window.SERVER_URL}/secondary-screenings/${serverId}/sync`,
    })

    const ctrl2 = new AbortController()
    const tid2  = setTimeout(() => ctrl2.abort(), APP.SYNC_TIMEOUT_MS)
    try {
      const res2 = await fetch(`${window.SERVER_URL}/secondary-screenings/${serverId}/sync`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body:   JSON.stringify(p2),
        signal: ctrl2.signal,
      })
      clearTimeout(tid2)
      const body2 = await res2.json().catch(() => ({}))
      syncStatus.phase2Resp = body2
      L.info('syncCaseToServer: Phase 2 response', { http: res2.status, success: body2?.success, meta: body2?.meta, error: body2?.error })

      if (res2.ok && body2?.success) {
        const sc          = body2.data ?? {}
        const staleWrite  = body2.meta?.stale_write === true
        const storedVer   = Number(body2.meta?.stored_version ?? sc.record_version ?? caseRecord.value.record_version ?? 1)

        if (staleWrite) {
          // The server SILENTLY skipped our case-field updates because our
          // record_version was not ahead of stored. This used to be the root
          // cause of "I closed the case but it reopens as IN_PROGRESS on the
          // next load" — the close never landed.
          //
          // Resolution: KEEP the user's intent (their CLOSED / DISPOSITIONED /
          // any field they changed) but RE-STAMP record_version one above the
          // server's stored version so the next push is guaranteed to be
          // ahead. Also flip sync_status back to UNSYNCED so the engine
          // retries the push immediately. We do NOT overlay the server's
          // older case data onto local — that would silently destroy the
          // user's close.
          L.warn('syncCaseToServer: Phase 2 stale-write — bumping record_version and re-queueing', {
            stored_version:        storedVer,
            attempted_version:     caseRecord.value.record_version,
            local_case_status:     caseRecord.value.case_status,
            server_case_status:    sc.case_status,
          })
          const reattempted = {
            ...toPlain(caseRecord.value),
            // Keep local case fields (the user's intent). Bump version.
            id: sc.id ?? serverId, server_id: sc.id ?? serverId,
            record_version: storedVer + 1,
            sync_status: SYNC.UNSYNCED,
            updated_at: isoNow(),
          }
          await safeDbPut(STORE.SECONDARY_SCREENINGS, reattempted)
          caseRecord.value = reattempted
          syncStatus.phase2 = 'fail'
          syncStatus.phase2Msg = `Stale write detected — re-queued at v${storedVer + 1} (engine will retry)`
          syncStatus.lastRunAt = isoNow()
          // Kick the unified sync engine so the retry happens immediately.
          try {
            if (typeof window !== 'undefined' && typeof window.__SYNC_NOW__ === 'function') {
              window.__SYNC_NOW__('stale-write-reattempt')
            }
          } catch (_) { /* engine optional */ }
        } else {
          const synced = {
            ...toPlain(caseRecord.value),
            id: sc.id ?? serverId, server_id: sc.id ?? serverId,
            sync_status: SYNC.SYNCED, synced_at: isoNow(),
            // The unified syncEngine recognises a fully-synced secondary by
            // sync_status===SYNCED + case_status terminal + last_synced_record_version
            // catching up to record_version. We set the version stamp here so a
            // re-flush sees no work to do (idempotent no-op).
            last_synced_record_version: (caseRecord.value.record_version || 1) + 1,
            record_version: (caseRecord.value.record_version || 1) + 1,
            updated_at: isoNow(),
          }
          await safeDbPut(STORE.SECONDARY_SCREENINGS, synced)
          caseRecord.value = synced
          syncStatus.phase2 = 'ok'
          syncStatus.phase2Msg = `Synced — status=${sc.case_status} child_tables=${JSON.stringify(body2.meta?.child_tables_sync ?? {})}`
          syncStatus.lastRunAt = isoNow()
          L.ok('syncCaseToServer: Phase 2 OK', body2.meta)
        }
      } else {
        // ── 409 Conflict: server case is in a state the sync can't transition ──
        // Two flavours:
        //   (a) Case already CLOSED — terminal, reconcile to CLOSED/SYNCED.
        //   (b) Case status mismatch (e.g. server has DISPOSITIONED, client
        //       trying to push DISPOSITIONED again, or any other invalid
        //       transition). Pull the canonical server state and overwrite
        //       local IDB so the client stops fighting the server.
        const alreadyClosed = res2.status === 409 &&
          (body2?.message ?? '').toLowerCase().includes('closed')
        const transitionConflict = res2.status === 409 &&
          (body2?.message ?? '').toLowerCase().includes('transition')

        if (alreadyClosed) {
          L.ok('syncCaseToServer: Phase 2 — server case already CLOSED (idempotent). Reconciling local IDB.')
          const reconciled = {
            ...toPlain(caseRecord.value),
            id:           serverId, server_id: serverId,
            case_status:  'CLOSED',
            closed_at:    caseRecord.value.closed_at ?? isoNow(),
            sync_status:  SYNC.SYNCED,
            synced_at:    isoNow(),
            // Server already terminal — record_version on the server matches
            // (or supersedes) ours. Stamp our high-water mark so the engine
            // sees this case as a fully-synced no-op on subsequent flushes.
            last_synced_record_version: (caseRecord.value.record_version || 1) + 1,
            last_sync_error: null,
            record_version: (caseRecord.value.record_version || 1) + 1,
            updated_at:   isoNow(),
          }
          await safeDbPut(STORE.SECONDARY_SCREENINGS, reconciled)
          caseRecord.value = reconciled
          // Also close the notification in IDB if still open
          if (notification.value && notification.value.status !== 'CLOSED') {
            const closedNotif = {
              ...toPlain(notification.value),
              status:    'CLOSED',
              closed_at: reconciled.closed_at,
              record_version: (notification.value.record_version || 1) + 1,
              updated_at: isoNow(),
            }
            await safeDbPut(STORE.NOTIFICATIONS, closedNotif)
            notification.value = closedNotif
          }
          syncStatus.phase2 = 'ok'
          syncStatus.phase2Msg = 'Server case was already CLOSED — local IDB reconciled'
          syncStatus.lastRunAt = isoNow()
        } else if (transitionConflict) {
          // Pull the server's authoritative state and merge into IDB.
          L.info('syncCaseToServer: Phase 2 — transition conflict, fetching server state for reconcile')
          try {
            const stateRes = await fetch(`${window.SERVER_URL}/secondary-screenings/${serverId}`, {
              method: 'GET',
              headers: { 'Accept': 'application/json' },
            })
            const stateBody = await stateRes.json().catch(() => ({}))
            const serverCase = stateBody?.data
            if (stateRes.ok && serverCase) {
              const reconciled = {
                ...toPlain(caseRecord.value),
                id:           serverCase.id ?? serverId,
                server_id:    serverCase.id ?? serverId,
                case_status:  serverCase.case_status,
                final_disposition: serverCase.final_disposition ?? caseRecord.value.final_disposition,
                dispositioned_at:  serverCase.dispositioned_at ?? caseRecord.value.dispositioned_at,
                closed_at:    serverCase.closed_at ?? caseRecord.value.closed_at,
                sync_status:  SYNC.SYNCED,
                synced_at:    isoNow(),
                last_sync_error: null,
                record_version: (caseRecord.value.record_version || 1) + 1,
                updated_at:   isoNow(),
              }
              await safeDbPut(STORE.SECONDARY_SCREENINGS, reconciled)
              caseRecord.value = reconciled
              syncStatus.phase2 = 'ok'
              syncStatus.phase2Msg = `Reconciled to server state=${serverCase.case_status}`
              syncStatus.lastRunAt = isoNow()
              L.ok('syncCaseToServer: Phase 2 — reconciled to server state', { case_status: serverCase.case_status })
            } else {
              throw new Error(`server state fetch failed (HTTP ${stateRes.status})`)
            }
          } catch (recErr) {
            syncStatus.phase2 = 'fail'
            syncStatus.phase2Msg = `409 transition conflict — reconcile failed: ${recErr?.message || recErr}`
            L.warn('syncCaseToServer: Phase 2 — reconcile failed', recErr?.message || recErr)
          }
        } else {
          syncStatus.phase2 = 'fail'
          syncStatus.phase2Msg = `HTTP ${res2.status}: ${body2?.message ?? 'Unknown'}`
          if (body2?.error) syncStatus.phase2Msg += ' | ' + JSON.stringify(body2.error)
          await safeDbPut(STORE.SECONDARY_SCREENINGS, {
            ...toPlain(caseRecord.value),
            sync_status: SYNC.FAILED,
            last_sync_error: body2?.message ?? `HTTP ${res2.status}`,
            record_version: (caseRecord.value.record_version || 1) + 1,
            updated_at: isoNow(),
          })
          L.warn('syncCaseToServer: Phase 2 rejected', { status: res2.status, body: body2 })
        }
      }   // end if (res2.ok) / else
    } catch (e) {
      clearTimeout(tid2)
      syncStatus.phase2 = 'fail'
      syncStatus.phase2Msg = e?.name === 'AbortError' ? 'Timed out' : `Network: ${e?.message ?? e}`
      L.warn('syncCaseToServer: Phase 2 exception', e?.message ?? e)
    }
  } catch (outerErr) {
    syncStatus.error = outerErr?.message ?? String(outerErr)
    L.err('syncCaseToServer: OUTER UNCAUGHT ERROR', outerErr)
  } finally {
    syncStatus.running  = false
    syncStatus.lastRunAt = syncStatus.lastRunAt ?? isoNow()
  }
}


// THE BUG THAT WAS CAUSING "LOADING FOREVER":
//   initPageRunning was a plain JS boolean lock. When onIonViewWillEnter held
//   the lock and onIonViewDidEnter arrived while _doInitPage() was mid-await,
//   onIonViewDidEnter hit `if (initPageRunning) return` and was permanently
//   discarded. If that first run was a no-op empty-uuid early-return, loading
//   was never cleared. Final fix: no global lock, two clean triggers only.
//
// TWO TRIGGERS:
//   onMounted + nextTick  — initial render AND Vite HMR (HMR fully remounts
//                           <script setup>, so onMounted re-fires with fresh state).
//
//   onIonViewDidEnter     — every subsequent Ionic page-enter (back navigation,
//                           re-entry). Fires AFTER page animation — route.params
//                           is GUARANTEED populated at this point.
//                           onMounted does NOT fire on re-entry (keep-alive).
//
// onIonViewWillEnter intentionally REMOVED — fires before Ionic commits the
// route, route.params may still be previous page's params at that moment.
//
// No outer lock. Idempotency check inside _doInitPage() prevents double work
// once data is loaded. openCaseLock below prevents the only real race: two
// concurrent calls creating two IDB case records before either commits.

let openCaseLock = false

// ─── DIAGNOSTIC LOGGER ────────────────────────────────────────────────────
const L = {
  _ts() { return new Date().toISOString().slice(11,23) },
  info(msg,  data) { console.log(   `%c[SS][${L._ts()}] ℹ ${msg}`,  'color:#1565C0;font-weight:700', ...(data!==undefined?[data]:[]) ) },
  ok(  msg,  data) { console.log(   `%c[SS][${L._ts()}] ✓ ${msg}`,  'color:#2E7D32;font-weight:700', ...(data!==undefined?[data]:[]) ) },
  warn(msg,  data) { console.warn(  `%c[SS][${L._ts()}] ⚠ ${msg}`,  'color:#E65100;font-weight:700', ...(data!==undefined?[data]:[]) ) },
  err( msg,  data) { console.error( `%c[SS][${L._ts()}] ✖ ${msg}`,  'color:#C62828;font-weight:700', ...(data!==undefined?[data]:[]) ) },
}

async function _doInitPage(source) {
  // Router param name varies across router file versions — read both and use whichever is set
  const uuid = String(route.params.notificationId || route.params.notificationUuid || '').trim()

  // [debug removed — admin panel available for diagnostics]

  if (!uuid) {
    L.warn(`[${source}] uuid EMPTY — route params not committed yet. Spinner stays until next trigger.`, {
      hint: 'Check that route /secondary-screening/:notificationUuid is matched correctly.',
    })
    return
  }

  if (notificationUuid.value === uuid && caseRecord.value) {
    L.ok(`[${source}] IDEMPOTENT — uuid="${uuid}" already loaded, caseRecord exists. Skipping.`)
    return
  }

  L.info(`[${source}] Starting full load for uuid="${uuid}"`)

  // 2026-05-20 — HARD RESET of all reactive state before loading a new case.
  // Without this, opening case B after case A would inherit case A's profile /
  // vitals / suspected diseases / etc. because Ionic page-caches the view and
  // reactive refs are not auto-cleared. Visible symptom: "I clicked Adam's
  // referral but the form pre-populated with a different traveller's name."
  // Source of truth = the IDB / server-fetched record for THIS uuid.
  Object.assign(profile, {
    traveler_full_name:                '',
    traveler_gender:                   '',
    traveler_age_years:                null,
    _birth_year:                       null,
    _age_months:                       null,
    travel_document_type:              '',
    travel_document_number:            '',
    traveler_nationality_country_code: '',
    residence_country_code:            '',
    phone_number:                      '',
    journey_start_country_code:        '',
    conveyance_type:                   '',
    conveyance_identifier:             '',
    arrival_datetime_input:            '',
    purpose_of_travel:                 '',
    destination_district_code:         '',
  })
  // Drop the runtime-attached fields that aren't in the reactive declaration
  // (traveler_dob etc. set by computeAgeFromBirthYear or passport scan).
  delete profile.traveler_dob
  Object.assign(vitals, {
    temperature_value:       null,
    temperature_unit:        'C',
    pulse_rate:              null,
    respiratory_rate:        null,
    bp_systolic:             null,
    bp_diastolic:            null,
    oxygen_saturation:       null,
    triage_category:         '',
    emergency_signs_present: 0,
    general_appearance:      '',
    syndrome_classification: '',
  })
  showVitals.value = false
  Object.assign(caseDecision, {
    syndrome_classification: '',
    risk_level:              '',
    final_disposition:       '',
    officer_notes:           '',
    followup_required:       true,        // intentional default — see _enforceFollowupOn
    followup_assigned_level: 'DISTRICT',
  })
  Object.assign(clusterFlags, {
    cluster_deaths_in_community: false,
    cluster_similar_illness:     false,
    unusual_event_flag:          false,
  })
  // Per-row child collections — clearing the refs prevents the previous
  // case's suspected diseases / actions / travel countries / engine output
  // from being rendered in the new case's UI.
  suspectedDiseases.value = []
  actions.value           = []
  travelCountries.value   = []
  analysisResult.value    = null
  // Symptom + exposure maps are re-seeded from their catalogs by the load
  // path below, but we wipe any details/onset_date stuck from the previous
  // case so a fresh notification starts truly blank.
  for (const k of Object.keys(symptomsMap)) {
    symptomsMap[k].is_present = null
    symptomsMap[k].onset_date = null
    symptomsMap[k].details    = null
  }
  for (const k of Object.keys(exposuresMap)) {
    exposuresMap[k].response = 'UNKNOWN'
    exposuresMap[k].details  = null
  }
  // Wizard navigation — back to step 1, hide field errors from the
  // previous case so the new case doesn't open with red borders.
  step.value           = 1
  maxStepReached.value = 1
  Object.keys(fieldErrors).forEach(k => { fieldErrors[k] = '' })
  // Officer override state — engine output from previous case must not
  // bleed into the new analysis.
  Object.assign(officerOverride, {
    syndromeOverridden: false,
    riskOverridden:     false,
    overrideNonCase:    false,
    overrideNote:       '',
    customDiseaseInput: '',
    addedDiseases:      [],
  })
  // Local refs that hold the previous case's identity. These get re-set by
  // the load path below — clearing here prevents a brief frame where the
  // template renders the old case while the new one loads.
  caseRecord.value      = null
  caseUuid.value        = null
  notification.value    = null
  notificationUuid.value = uuid
  loading.value          = true
  notFound.value         = false
  poeMismatch.value      = false

  const localAuth = getAuth()
  if (!localAuth?.id || !localAuth?.is_active) {
    L.err(`[${source}] AUTH FAILED — id=${localAuth?.id} is_active=${localAuth?.is_active}. Aborting.`)
    loading.value = false
    return
  }
  L.ok(`[${source}] Auth OK — user id=${localAuth.id} poe=${localAuth.poe_code}`)

  try {
    L.info(`[${source}] IDB: dbGet NOTIFICATIONS "${uuid}"`)
    let notif = await dbGet(STORE.NOTIFICATIONS, uuid)

    // 3-I FIX: If the notification isn't in local IDB (fresh device,
    // cross-account open, or IDB wipe), fall back to the server. The case
    // file must never fail to show as long as the record exists upstream.
    // Mirrors the cross-account fetch pattern used for primary screenings
    // a few lines below.
    if (!notif && navigator.onLine) {
      try {
        L.info(`[${source}] Notification not in IDB — fetching from server (fresh-device / cross-account scenario)`)
        const uid = getAuth()?.id
        const url = `${window.SERVER_URL}/notifications/by-uuid/${encodeURIComponent(uuid)}?user_id=${encodeURIComponent(uid ?? '')}`
        const res = await fetch(url, { headers: { Accept: 'application/json' } })
        if (res.ok) {
          const body = await res.json().catch(() => null)
          const fetchedNotif   = body?.data?.notification ?? null
          const fetchedPrimary = body?.data?.primary_screening ?? null
          if (fetchedNotif) {
            notif = fetchedNotif
            try { await safeDbPut(STORE.NOTIFICATIONS, { ...notif, sync_status: 'SYNCED' }) } catch (_) {}
            if (fetchedPrimary) {
              try { await safeDbPut(STORE.PRIMARY_SCREENINGS, { ...fetchedPrimary, sync_status: 'SYNCED' }) } catch (_) {}
            }
            L.ok(`[${source}] Notification retrieved from server and cached to IDB — uuid="${uuid}"`)
          }
        } else {
          L.warn(`[${source}] Server returned ${res.status} for /notifications/by-uuid/${uuid}`)
        }
      } catch (e) {
        L.warn(`[${source}] Server fetch for notification failed — ${e?.message ?? e}`)
      }
    }

    if (!notif) {
      L.err(`[${source}] NOTIFICATION NOT FOUND for uuid="${uuid}" (IDB miss + server miss/offline)`, {
        hint: 'Primary screening may not have synced its notification yet, or the device is offline.',
        store: STORE.NOTIFICATIONS,
        online: navigator.onLine,
      })
      notFound.value = true
      return
    }
    L.ok(`[${source}] Notification loaded`, { status: notif.status, priority: notif.priority, poe_code: notif.poe_code })
    notification.value = { ...notif, id: notif.id ?? notif.server_id ?? null }

    const userPoe    = auth?.poe_code  ?? ''
    const notifPoe   = notif.poe_code  ?? ''
    const userRole   = String(auth?.role_key ?? '').toUpperCase()
    const isSupervisor = ['NATIONAL_ADMIN', 'PHEOC_OFFICER', 'DISTRICT_SUPERVISOR'].includes(userRole)
    if (!isSupervisor && userPoe && notifPoe && userPoe !== notifPoe) {
      L.warn(`POE mismatch — user:${userPoe} notif:${notifPoe}`)
      poeMismatch.value = true
    }

    if (notif.primary_screening_id) {
      L.info(`[${source}] IDB: dbGet PRIMARY_SCREENINGS "${notif.primary_screening_id}"`)
      let ps = await dbGet(STORE.PRIMARY_SCREENINGS, notif.primary_screening_id)

      // Request 9 cross-account fix: if User B opens a case originally screened by
      // User A, the primary record may not exist in User B's local IDB. Fall back
      // to a server fetch using the notification UUID so the traveler name and
      // direction are always visible regardless of which account did the primary screen.
      if (!ps && navigator.onLine) {
        try {
          L.info(`[${source}] Primary not in IDB — fetching from server (cross-account scenario)`)
          const uid = getAuth()?.id
          const url = `${window.SERVER_URL}/primary-screenings?notification_id=${notif.client_uuid}&user_id=${uid}&per_page=1`
          const res = await fetch(url, { headers: { Accept: 'application/json' } })
          if (res.ok) {
            const body = await res.json().catch(() => null)
            const items = body?.data?.items ?? body?.data ?? []
            if (Array.isArray(items) && items.length > 0) {
              ps = items[0]
              // Cache locally so subsequent cross-account opens are instant
              try { await safeDbPut(STORE.PRIMARY_SCREENINGS, { ...ps, sync_status: 'SYNCED' }) } catch (_) {}
              L.ok(`[${source}] Primary screening retrieved from server — name=${ps.traveler_full_name}`)
            }
          }
        } catch (_) {
          L.warn(`[${source}] Server fetch for primary screening failed — proceeding without it`)
        }
      }

      primaryScreening.value = ps ?? null
      if (ps) {
        L.ok(`[${source}] Primary screening loaded — gender=${ps.gender} temp=${ps.temperature_value}°${ps.temperature_unit || 'C'}`)
        // Prefill the traveler profile from the primary screening.
        // Guard: only fill when the profile field is blank — never overwrite
        // data the officer already entered (e.g. resumed case with edits).
        // Fields include BOTH the standard primary columns AND the passport
        // metadata extras stored by PrimaryScreening.vue's applyPassportResult().
        if (ps.traveler_full_name)                profile.traveler_full_name                = ps.traveler_full_name
        if (ps.gender)                            profile.traveler_gender                   = ps.gender

        // Age / DOB — from passport scan metadata stored in primary IDB record.
        if (ps.traveler_age_years != null && !profile.traveler_age_years) {
          profile.traveler_age_years = ps.traveler_age_years
          if (ps.traveler_age_years <= 1) showInfantMonths.value = true
        }
        if (ps.traveler_dob && !profile._birth_year && !profile.traveler_dob) {
          profile.traveler_dob = ps.traveler_dob
          const yr = parseInt(String(ps.traveler_dob).slice(0, 4), 10)
          if (!isNaN(yr)) profile._birth_year = yr
        }

        // Document
        if (ps.travel_document_type   && !profile.travel_document_type)   profile.travel_document_type   = ps.travel_document_type
        if (ps.travel_document_number && !profile.travel_document_number)  profile.travel_document_number = ps.travel_document_number

        // Nationality
        if (ps.traveler_nationality_country_code && !profile.traveler_nationality_country_code) {
          profile.traveler_nationality_country_code = ps.traveler_nationality_country_code
        }

        // Journey start: use nationality as proxy if not Ugandan (same logic as onDocScanResult)
        if (ps.traveler_nationality_country_code && ps.traveler_nationality_country_code !== 'UG'
            && !profile.journey_start_country_code) {
          profile.journey_start_country_code = ps.traveler_nationality_country_code
        }

        // Request 9 session persistence: overlay with any mid-session draft
        // saved to sessionStorage when the user navigated away before opening
        // the case. Draft overrides primary prefill because it captures the
        // officer's own corrections.
        try {
          const draftRaw = sessionStorage.getItem(`ss_draft_name_${uuid}`)
          if (draftRaw) {
            const draft = JSON.parse(draftRaw)
            if (draft.traveler_full_name)    profile.traveler_full_name    = draft.traveler_full_name
            if (draft.traveler_gender)       profile.traveler_gender       = draft.traveler_gender
            if (draft.traveler_age_years != null) profile.traveler_age_years = draft.traveler_age_years
          }
        } catch (_) {}

        // Vitals: temperature from primary capture. Only seed when a real
        // value exists (null/0 means "not measured", leave the unit at its
        // default so the screener's choice doesn't reset). Vitals card stays
        // collapsed by default (executive directive 2026-05-08) — officer
        // expands it manually when they need to record/check vitals.
        if (ps.temperature_value != null && ps.temperature_value !== '') {
          vitals.temperature_value = Number(ps.temperature_value)
          if (ps.temperature_unit) vitals.temperature_unit = ps.temperature_unit
        }

        // Parse and store primary quick-symptom chips for seeding into secondary.
        // Stored on the component-scoped ref _primaryChips (not a window global
        // which could leak across navigation). Applied after openCase() or when
        // resuming a case that has no symptoms yet assessed.
        const _parsedChips = (() => {
          try {
            const raw = ps.quick_symptoms_json
            if (!raw) return []
            return (typeof raw === 'string' ? JSON.parse(raw) : raw) || []
          } catch { return [] }
        })()
        if (_parsedChips.length > 0) {
          L.ok(`[${source}] Primary quick symptoms to pre-seed: ${_parsedChips.join(', ')}`)
          _primaryChips.value = _parsedChips
        }
      } else {
        L.warn(`[${source}] Primary screening NOT FOUND for id="${notif.primary_screening_id}" — proceeding without it`)
      }
    } else {
      L.warn(`[${source}] Notification has no primary_screening_id — this is unexpected`)
    }

    L.info(`[${source}] IDB: dbGetByIndex SECONDARY_SCREENINGS notification_id="${uuid}"`)
    let existingCases = await dbGetByIndex(
      STORE.SECONDARY_SCREENINGS, 'notification_id', uuid
    )
    L.info(`[${source}] Existing cases found in IDB: ${existingCases.length}`)

    // Cross-device hydrate: if the local IDB record is older than the
    // server's (e.g. another device already filled the form, or this is
    // a fresh install), pull the server copy and merge. Without this
    // step a screener resuming on a second phone sees an empty form
    // because openCase() created a stub locally but the work happened
    // elsewhere.
    if (existingCases.length > 0 && navigator.onLine) {
      try {
        const uid = getAuth()?.id
        const localCase = existingCases[0]
        const localVer = Number(localCase.record_version || 0)
        const serverUrl = `${window.SERVER_URL}/secondary-screenings/by-notification/${encodeURIComponent(uuid)}?user_id=${encodeURIComponent(uid ?? '')}`
        const ctrl = new AbortController()
        const tHandle = setTimeout(() => ctrl.abort(), 5000)
        let sRes
        try {
          sRes = await fetch(serverUrl, { headers: { Accept: 'application/json' }, signal: ctrl.signal })
        } finally {
          clearTimeout(tHandle)
        }
        if (sRes.ok) {
          const sBody = await sRes.json().catch(() => null)
          const srv = sBody?.success ? sBody.data : null
          const remoteVer = Number(srv?.record_version || 0)
          // Server is newer than local → seed the latest data over our stub.
          if (srv && srv.client_uuid && remoteVer > localVer) {
            L.ok(`[${source}] Server case is newer (v${remoteVer} > v${localVer}) — overlaying`)
            try { await safeDbPut(STORE.SECONDARY_SCREENINGS, { ...srv, notification_id: uuid, sync_status: 'SYNCED' }) } catch (_) {}
            const sid = srv.client_uuid
            const seedRecords = async (store, rows) => {
              await Promise.all((rows || []).map(r =>
                safeDbPut(store, {
                  ...r,
                  client_uuid:           r.client_uuid || genUUID(),
                  secondary_screening_id: sid,
                }).catch(() => {})
              ))
            }
            await Promise.all([
              seedRecords(STORE.SECONDARY_SYMPTOMS,           srv.symptoms),
              seedRecords(STORE.SECONDARY_EXPOSURES,          srv.exposures),
              seedRecords(STORE.SECONDARY_ACTIONS,            srv.actions),
              seedRecords(STORE.SECONDARY_TRAVEL_COUNTRIES,   srv.travel_countries),
              seedRecords(STORE.SECONDARY_SUSPECTED_DISEASES, srv.suspected_diseases),
            ])
            // Re-query so existingCases[0] is the freshly-overlaid record.
            existingCases = await dbGetByIndex(STORE.SECONDARY_SCREENINGS, 'notification_id', uuid)
          }
        }
      } catch (e) {
        L.warn(`[${source}] cross-device hydrate skipped: ${e?.message}`)
      }
    }

    // Cross-account / fresh-device fallback: NATIONAL_ADMIN or any supervisor who
    // opens a case not in their local IDB must get the full server copy so the
    // form is pre-populated with ALL entered data rather than opening blank.
    if (existingCases.length === 0 && navigator.onLine) {
      try {
        const uid = getAuth()?.id
        const serverUrl = `${window.SERVER_URL}/secondary-screenings/by-notification/${encodeURIComponent(uuid)}?user_id=${encodeURIComponent(uid ?? '')}`
        L.info(`[${source}] IDB miss — fetching full case from server: ${serverUrl}`)
        const ctrl = new AbortController()
        const tHandle = setTimeout(() => ctrl.abort(), 5000)
        let sRes
        try {
          sRes = await fetch(serverUrl, { headers: { Accept: 'application/json' }, signal: ctrl.signal })
        } finally {
          clearTimeout(tHandle)
        }
        if (sRes.ok) {
          const sBody = await sRes.json().catch(() => null)
          if (sBody?.success && sBody?.data) {
            const srv = sBody.data
            const sid = srv.client_uuid  // secondary screening client_uuid is the IDB key
            if (sid) {
              // Seed the secondary screening itself.
              // Override notification_id with the UUID string — the server returns an integer
              // FK, but IDB indexes by the notification UUID string.
              try { await safeDbPut(STORE.SECONDARY_SCREENINGS, { ...srv, notification_id: uuid, sync_status: 'SYNCED' }) } catch (_) {}

              // Seed sub-records. Server DB stores integer secondary_screening_id;
              // IDB indexes by the secondary screening client_uuid instead. Sub-records
              // also lack client_uuid on the server — generate one per record.
              const seedRecords = async (store, rows, extraFields) => {
                await Promise.all((rows || []).map(r =>
                  safeDbPut(store, {
                    ...r,
                    client_uuid:           r.client_uuid || genUUID(),
                    secondary_screening_id: sid,
                    ...extraFields,
                  }).catch(() => {})
                ))
              }
              await Promise.all([
                seedRecords(STORE.SECONDARY_SYMPTOMS,           srv.symptoms,           {}),
                seedRecords(STORE.SECONDARY_EXPOSURES,          srv.exposures,          {}),
                seedRecords(STORE.SECONDARY_ACTIONS,            srv.actions,            {}),
                seedRecords(STORE.SECONDARY_TRAVEL_COUNTRIES,   srv.travel_countries,   {}),
                seedRecords(STORE.SECONDARY_SUSPECTED_DISEASES, srv.suspected_diseases, {}),
              ])

              // Re-query IDB — it now has the seeded record
              existingCases = await dbGetByIndex(STORE.SECONDARY_SCREENINGS, 'notification_id', uuid)
              L.ok(`[${source}] Server case seeded to IDB — existingCases: ${existingCases.length}`)
            }
          } else {
            L.warn(`[${source}] Server returned ok but no data for by-notification/${uuid} (HTTP ${sRes.status})`)
          }
        } else {
          L.warn(`[${source}] Server fetch for secondary case returned HTTP ${sRes.status}`)
        }
      } catch (sErr) {
        L.warn(`[${source}] Server fallback for secondary case failed: ${sErr?.message}`)
      }
    }

    if (existingCases.length > 0) {
      const existing  = existingCases[0]
      L.ok(`[${source}] RESUMING case — uuid="${existing.client_uuid}" status="${existing.case_status}"`)
      caseUuid.value   = existing.client_uuid
      caseRecord.value = { ...existing, id: existing.id ?? existing.server_id ?? null }

      Object.assign(profile, {
        // Source of truth is the IDB record for THIS case. We deliberately do
        // NOT fall back to `profile.traveler_full_name` here — that "|| profile.X"
        // pattern was the bug that made Adam's name show up on Mary's case when
        // Mary's record had a null name. Reactive state is hard-reset at the
        // top of _doInitPage so falling back to "" is the correct behaviour.
        traveler_full_name:                existing.traveler_full_name                || '',
        traveler_gender:                   existing.traveler_gender                   || '',
        traveler_age_years:                existing.traveler_age_years                || null,
        travel_document_type:              existing.travel_document_type              || '',
        travel_document_number:            existing.travel_document_number            || '',
        traveler_nationality_country_code: existing.traveler_nationality_country_code || '',
        residence_country_code:            existing.residence_country_code            || '',
        phone_number:                      existing.phone_number                      || '',
        journey_start_country_code:        existing.journey_start_country_code        || '',
        conveyance_type:                   existing.conveyance_type                   || '',
        conveyance_identifier:             existing.conveyance_identifier             || '',
        arrival_datetime_input:            existing.arrival_datetime
                                             ? existing.arrival_datetime.slice(0, 16) : '',
        purpose_of_travel:                 existing.purpose_of_travel                || '',
        destination_district_code:         existing.destination_district_code        || '',
      })

      if (existing.temperature_value != null) {
        // Vitals card stays collapsed by default — officer expands manually.
        Object.assign(vitals, {
          temperature_value:       existing.temperature_value,
          temperature_unit:        existing.temperature_unit        || vitals.temperature_unit || 'C',
          pulse_rate:              existing.pulse_rate,
          respiratory_rate:        existing.respiratory_rate,
          bp_systolic:             existing.bp_systolic,
          bp_diastolic:            existing.bp_diastolic,
          oxygen_saturation:       existing.oxygen_saturation,
          triage_category:         existing.triage_category         || '',
          emergency_signs_present: existing.emergency_signs_present || 0,
          general_appearance:      existing.general_appearance       || '',
        })
      }

      Object.assign(caseDecision, {
        syndrome_classification:  normalizeSyndromeCode(existing.syndrome_classification) || '',
        risk_level:               existing.risk_level               || '',
        final_disposition:        DISPOSITION_LEGACY_SHIMS[existing.final_disposition] || existing.final_disposition || '',
        officer_notes:            existing.officer_notes            || '',
        followup_required:        !!existing.followup_required,
        followup_assigned_level:  existing.followup_assigned_level  || '',
      })

      const sid = existing.client_uuid
      travelCountries.value  = await dbGetByIndex(STORE.SECONDARY_TRAVEL_COUNTRIES,   'secondary_screening_id', sid) || []
      initSymptoms()
      initExposuresFromCatalog()
      autoPopulateDestinationDistrict()
      const savedSymptoms    = await dbGetByIndex(STORE.SECONDARY_SYMPTOMS,            'secondary_screening_id', sid)
      for (const s of savedSymptoms) {
        // Legacy shim (Task 1, 2026-05-05): records written before the fever
        // auto-derive refactor stored 'low_grade_fever' as a separate
        // symptom code. Map it onto 'fever' for back-compat — and drop the
        // standalone low_grade_fever entry so it doesn't double-count.
        if (s.symptom_code === 'low_grade_fever' && s.is_present === 1) {
          if (symptomsMap['fever']) symptomsMap['fever'].is_present = 1
          continue
        }
        if (symptomsMap[s.symptom_code]) {
          symptomsMap[s.symptom_code].is_present = s.is_present
          symptomsMap[s.symptom_code].onset_date  = s.onset_date
          symptomsMap[s.symptom_code].details     = s.details
          // 2026-05-07: only override the genUUID-seeded client_uuid when
          // the loaded record actually has one. Older IDB rows from before
          // the client_uuid field was mandatory could be undefined → would
          // wipe the seeded UUID and break the next dbReplaceAll write.
          if (s.client_uuid) symptomsMap[s.symptom_code].client_uuid = s.client_uuid
        }
      }
      const savedExposures   = await dbGetByIndex(STORE.SECONDARY_EXPOSURES,           'secondary_screening_id', sid)
      // FIX BUG: 'exposures' was undefined. Restore directly into exposuresMap.
      for (const se of savedExposures) {
        if (exposuresMap[se.exposure_code] !== undefined) {
          exposuresMap[se.exposure_code].response = se.response
          if (se.details) exposuresMap[se.exposure_code].details = se.details
        } else {
          exposuresMap[se.exposure_code] = {
            exposure_code: se.exposure_code,
            response:      se.response || 'UNKNOWN',
            details:       se.details  || null,
          }
        }
      }
      actions.value           = await dbGetByIndex(STORE.SECONDARY_ACTIONS,            'secondary_screening_id', sid) || []
      // Legacy action code shim — map old codes to new canonical codes
      actions.value = actions.value.map(a => ({
        ...a,
        action_code: ACTION_LEGACY_SHIMS[a.action_code] || a.action_code,
      }))
      suspectedDiseases.value = await dbGetByIndex(STORE.SECONDARY_SUSPECTED_DISEASES, 'secondary_screening_id', sid) || []

      if (['DISPOSITIONED', 'CLOSED'].includes(existing.case_status)) {
        step.value = 4
        maxStepReached.value = 4
      } else if (existing.case_status === 'IN_PROGRESS') {
        // Re-opening a partially saved case — seed max-reached so the
        // officer can tab forward to where they left off (audit SS-001).
        if (existing.symptoms_summary) maxStepReached.value = Math.max(maxStepReached.value, 3)
        else maxStepReached.value = Math.max(maxStepReached.value, 2)
      }

      // ── AUTO-RERUN ANALYSIS ON RESUME ─────────────────────────────────────
      // analysisResult lives only in memory. After a page reload or back-nav,
      // step 4 would show a blank panel. Re-run the engine from saved IDB data
      // so the officer gets the full intelligence view without re-entering exposures.
      const hasEnoughForAnalysis = savedSymptoms.length >= 1 && existing.syndrome_classification
      if (hasEnoughForAnalysis && window.DISEASES?.getEnhancedScoreResult) {
        try {
          const _pSym = savedSymptoms.filter(s => s.is_present === 1).map(s => s.symptom_code)
          const _aSym = savedSymptoms.filter(s => s.is_present === 0).map(s => s.symptom_code)
          const _expRec = savedExposures.map(e => ({ exposure_code: e.exposure_code, response: e.response }))
          const _engCodes = window.EXPOSURES?.mapToEngineCodes(_expRec) || []
          const _rawTemp = existing.temperature_value
          const _tempC   = _rawTemp ? (existing.temperature_unit === 'F' ? (_rawTemp - 32) * 5 / 9 : _rawTemp) : null
          const _vitals  = {
            temperature_c:     _tempC,
            oxygen_saturation: existing.oxygen_saturation || undefined,
            pulse_rate:        existing.pulse_rate        || undefined,
            respiratory_rate:  existing.respiratory_rate  || undefined,
            bp_systolic:       existing.bp_systolic        || undefined,
          }
          const _visited = (travelCountries.value || []).map(t => ({
            country_code: t.country_code, travel_role: t.travel_role || 'VISITED'
          }))
          const _result = window.DISEASES.getEnhancedScoreResult(_pSym, _aSym, _engCodes, _visited, _vitals)
          analysisResult.value = _result
          L.ok(`[${source}] Auto-rerun analysis OK — syndrome=${_result.syndrome?.syndrome} risk=${_result.ihr_risk?.risk_level} non_case=${_result.is_non_case}`)
        } catch (_reErr) {
          L.warn(`[${source}] Auto-rerun analysis non-fatal error`, _reErr?.message)
        }
      }

      // RESUME path: also apply primary chips to any symptoms not yet assessed.
      // This handles the case where the officer opened the case but hadn't yet
      // reached the symptoms tab. applyPrimarySymptoms is idempotent — it only
      // sets null entries and never overwrites the officer's YES/NO decisions.
      if (_primaryChips.value.length > 0) {
        const resumeSeeded = applyPrimarySymptoms(_primaryChips.value)
        if (resumeSeeded > 0) {
          L.ok(`[${source}] Resume: pre-seeded ${resumeSeeded} symptom(s) from primary chips`)
        }
      }

      L.ok(`[${source}] Case resume complete. step=${step.value}`)

    } else {
      L.info(`[${source}] NEW case — notification.status="${notif.status}"`)
      if (notif.status === 'OPEN' || notif.status === 'IN_PROGRESS') {
        L.info(`[${source}] Calling openCase()`)
        await openCase(localAuth)
        L.ok(`[${source}] openCase() complete — caseUuid="${caseUuid.value}"`)

        // Apply primary quick-symptom chips to the secondary symptom map.
        // Uses PRIMARY_TO_SECONDARY_SYMPTOM map to handle code mismatches
        // (e.g. 'rash' → 'rash_maculopapular', 'severe_headache' → 'headache').
        // Only sets symptoms that are currently null (never overwrites officer input).
        if (_primaryChips.value.length > 0) {
          initSymptoms()
          const seeded = applyPrimarySymptoms(_primaryChips.value)
          if (seeded > 0) {
            L.ok(`[${source}] Pre-seeded ${seeded} symptom(s) from primary chips: ${_primaryChips.value.join(', ')}`)
          }
          // Keep _primaryChips set so resume path can also apply if needed
        }
      } else {
        L.warn(`[${source}] Notification status="${notif.status}" is not OPEN/IN_PROGRESS — openCase skipped`)
      }
    }

  } catch (err) {
    L.err(`[${source}] UNHANDLED ERROR in _doInitPage`, err)
    console.error('[SecondaryScreening] Full error:', err)
    notFound.value = true
  } finally {
    L.info(`[${source}] finally — setting loading=false. notFound=${notFound.value} caseRecord=${!!caseRecord.value}`)
    loading.value = false
  }
}

// ── Trigger 1: route-param watcher (PRIMARY — handles every scenario) ────
// { immediate: true } fires synchronously during setup IF params are already
// set. If params are empty (direct URL load, Ionic pre-create), it fires again
// the moment Vue Router commits the route and sets the param. This is the only
// trigger that reliably works on direct browser URL entry / page refresh.
watch(
  () => route.params.notificationId || route.params.notificationUuid,
  (newUuid) => {
    const uuid = String(newUuid || '').trim()
    if (uuid) void _doInitPage('routeParamWatch')
  },
  { immediate: true }
)

// ── Trigger 2: onMounted (Vite HMR recovery + belt-and-suspenders) ────────
// Vue 3 HMR fully remounts <script setup> components, so onMounted re-fires.
// nextTick ensures Vue Router has had one render cycle to commit params.
onMounted(async () => {
  await nextTick()
  void _doInitPage('onMounted')
})

// ── Trigger 3: onIonViewDidEnter (in-app navigation, back-nav) ───────────
// Fires AFTER Ionic page animation — only on navigations within the running app.
// Does NOT fire on direct URL load (which is why the route-param watch is needed).
onIonViewDidEnter(async () => {
  await nextTick()
  void _doInitPage('onIonViewDidEnter')
  try { keepAwake.activate() } catch (_) {}
  // First-time coachmark for the voice mic feature — shows once per device.
  coachmark('secondary.voice-mic-intro', {
    elementId: 'sc-voice-note',
    title: 'Dictate clinical notes',
    body: 'Tap the mic icon inside any free-text field to dictate hands-free. Turn it off in Settings → Capabilities.',
    icon: 'sparkles', ctaLabel: 'Got it',
  })
})

// Release keep-awake AND cancel any in-flight dictation when the user leaves.
// Request 9 (session persistence): always persist the traveler name so it is
// restored when the user navigates away and comes back, even before the case
// has been formally opened (no caseUuid yet).
onIonViewWillLeave(async () => {
  try { keepAwake.deactivate() } catch (_) {}
  try { voice.cancel() } catch (_) {}

  // Persist name draft to sessionStorage keyed by notification UUID so it
  // survives component unmount (the pre-open phase where caseUuid is null).
  try {
    if (notificationUuid.value && profile.traveler_full_name) {
      sessionStorage.setItem(
        `ss_draft_name_${notificationUuid.value}`,
        JSON.stringify({
          traveler_full_name: profile.traveler_full_name,
          traveler_gender:    profile.traveler_gender,
          traveler_age_years: profile.traveler_age_years ?? null,
        })
      )
    }
  } catch (_) {}

  // Autosave: if a case is already open, also persist to IDB.
  try {
    if (caseUuid.value && profile.traveler_full_name) {
      const existing = await dbGet(STORE.SECONDARY_SCREENINGS, caseUuid.value)
      if (existing) {
        await safeDbPut(STORE.SECONDARY_SCREENINGS, {
          ...existing,
          traveler_full_name: profile.traveler_full_name || existing.traveler_full_name,
          traveler_gender:    profile.traveler_gender    || existing.traveler_gender,
          traveler_age_years: profile.traveler_age_years ?? existing.traveler_age_years,
          updated_at:         isoNow(),
        })
      }
    }
  } catch (_) {}
})
onUnmounted(() => {
  try { keepAwake.deactivate() } catch (_) {}
})

// 2026-05-07: voice dictation removed app-wide. Stub left in case any
// template references dictateInto — typing into the field is now the only path.
const voiceBusy = ref(false)
const voiceMsg  = ref('')
async function dictateInto() { /* removed — no-op */ }
// Template bindings reach script-setup variables directly; no defineExpose needed.
</script>


<style scoped>
/* ═══════════════════════════════════════════════════════════════
   SecondaryScreening.vue — Premium Scoped Styles
   Namespace: sc-*
   Theme: Command Centre Dual-Tone (Dark header + Light content)
   Design: ECSA-HC POE SENTINEL v5.0 — Aligned to readme.txt
   Fonts: DM Sans · Syne (display) · JetBrains Mono (codes)
   Grid: 8-point · WCAG AA · 44px touch targets
   NO dark mode. NO @media prefers-color-scheme.
═══════════════════════════════════════════════════════════════ */

/* ── ANIMATIONS ──────────────────────────────────────────────── */
@keyframes sc-slideUp {
  from { opacity: 0; transform: translateY(12px); }
  to   { opacity: 1; transform: translateY(0); }
}
@keyframes sc-dataStream {
  0%   { transform: translateX(-100%); }
  100% { transform: translateX(350%); }
}
@keyframes sc-spin { to { transform: rotate(360deg); } }
@keyframes sc-node-pulse {
  0%,100% { box-shadow: 0 0 0 0 rgba(0,180,255,.4); }
  50%     { box-shadow: 0 0 0 6px rgba(0,180,255,0); }
}
@keyframes sc-dotPulse {
  0%,100% { box-shadow: 0 0 6px rgba(224,32,80,.3); }
  50%     { box-shadow: 0 0 14px rgba(224,32,80,.6); }
}
@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after {
    animation-duration: 0.01ms !important;
    transition-duration: 0.01ms !important;
  }
}

/* ── HEADER — DARK ZONE ──────────────────────────────────────── */
.sc-header {
  --background: transparent;
  background: linear-gradient(180deg, #070E1B 0%, #0E1A2E 100%);
  position: relative;
}
.sc-hdr-pattern {
  position: absolute; inset: 0;
  background-image:
    linear-gradient(rgba(0,180,255,0.03) 1px, transparent 1px),
    linear-gradient(90deg, rgba(0,180,255,0.03) 1px, transparent 1px);
  background-size: 36px 36px;
  mask-image: linear-gradient(180deg, black 60%, transparent 100%);
  -webkit-mask-image: linear-gradient(180deg, black 60%, transparent 100%);
  pointer-events: none;
}

.sc-hdr-top {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 16px 0; position: relative; z-index: 2;
}
.sc-back-btn {
  width: 44px; height: 44px; border-radius: 50%;
  background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
  cursor: pointer; -webkit-tap-highlight-color: transparent;
  transition: background .15s cubic-bezier(.16,1,.3,1);
}
.sc-back-btn:active { background: rgba(255,255,255,.18); }
.sc-back-btn svg { width: 16px; height: 16px; }

.sc-title-block { flex: 1; }
.sc-eyebrow {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Ubuntu, 'Helvetica Neue', Arial, sans-serif;
  font-size: 9px; font-weight: 700; letter-spacing: 1.2px;
  text-transform: uppercase; color: #7E92AB; display: block;
}
.sc-page-title {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Ubuntu, 'Helvetica Neue', Arial, sans-serif;
  font-size: 18px; font-weight: 700; color: #EDF2FA; letter-spacing: -.25px; line-height: 1.3;
}

.sc-hdr-right { flex-shrink: 0; }
.sc-sync-pill {
  display: flex; align-items: center; gap: 5px;
  padding: 4px 10px; border-radius: 10px; border: 1px solid;
  font-size: 10px; font-weight: 700;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Ubuntu, 'Helvetica Neue', Arial, sans-serif;
}
.sc-sync-dot { width: 6px; height: 6px; border-radius: 50%; }
.sc-sync-pill--ok      { background: rgba(0,168,107,.2);  border-color: rgba(0,168,107,.35); color: #00E676; }
.sc-sync-pill--ok .sc-sync-dot      { background: #00E676; box-shadow: 0 0 8px rgba(0,230,118,.4); }
.sc-sync-pill--pending { background: rgba(255,179,0,.15); border-color: rgba(255,179,0,.3);  color: #FFB300; }
.sc-sync-pill--pending .sc-sync-dot { background: #FFB300; box-shadow: 0 0 8px rgba(255,179,0,.4); }
.sc-sync-pill--offline { background: rgba(255,255,255,.06); border-color: rgba(255,255,255,.1); color: rgba(255,255,255,.5); }
.sc-sync-pill--offline .sc-sync-dot { background: rgba(255,255,255,.4); }

/* Case summary strip */
.sc-case-strip {
  display: flex; align-items: center; gap: 10px;
  margin: 8px 14px 0;
  background: linear-gradient(180deg, #0E1A2E 0%, #142640 100%);
  border: 1px solid rgba(255,255,255,.08); border-radius: 14px;
  padding: 10px 12px; position: relative; z-index: 2;
}
.sc-case-ic {
  width: 32px; height: 32px; border-radius: 8px;
  background: rgba(0,180,255,.12); border: 1px solid rgba(0,180,255,.2);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.sc-case-ic svg { width: 14px; height: 14px; }
.sc-case-info { flex: 1; min-width: 0; }
.sc-case-name {
  font-family: inherit;
  font-size: 13px; font-weight: 700; color: #EDF2FA;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.sc-case-meta {
  font-family: inherit;
  font-size: 10px; color: #7E92AB; margin-top: 1px;
}
.sc-prio-pill {
  padding: 3px 9px; border-radius: 5px; font-size: 9px; font-weight: 800;
  letter-spacing: .6px; text-transform: uppercase; flex-shrink: 0;
  font-family: inherit;
}
.sc-prio-pill--critical { background: rgba(224,32,80,.2); color: #FF3D71; border: 1px solid rgba(224,32,80,.35); }
.sc-prio-pill--high     { background: rgba(255,179,0,.18); color: #FFB300; border: 1px solid rgba(255,179,0,.3); }
.sc-prio-pill--normal   { background: rgba(255,255,255,.08); color: #7E92AB; border: 1px solid rgba(255,255,255,.12); }

/* 4-step progress bar */
.sc-stepper {
  display: flex; align-items: center;
  padding: 10px 14px 12px; position: relative; z-index: 2;
}
.sc-step-wrap { display: flex; align-items: center; flex: 1; }
.sc-step-wrap:last-child { flex: none; }
.sc-step {
  display: flex; flex-direction: column; align-items: center; gap: 3px;
  background: none; border: none; cursor: pointer; padding: 4px;
  min-width: 44px; min-height: 44px; flex-shrink: 0;
  -webkit-tap-highlight-color: transparent;
}
.sc-step-node {
  width: 24px; height: 24px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  transition: all .25s cubic-bezier(.16,1,.3,1);
}
.sc-step--done   .sc-step-node { background: #00A86B; border: none; box-shadow: 0 0 8px rgba(0,168,107,.35); }
.sc-step--active .sc-step-node { background: #00B4FF; border: 2px solid #00B4FF; box-shadow: 0 0 12px rgba(0,180,255,.3); }
.sc-step--future .sc-step-node { background: rgba(255,255,255,.06); border: 1.5px solid rgba(255,255,255,.2); }
.sc-step--active .sc-step-node { animation: sc-node-pulse 2s ease-in-out infinite; }
.sc-step-num  { font-size: 10px; font-weight: 800; font-family: inherit; }
.sc-step--done   .sc-step-num   { color: #fff; }
.sc-step--active .sc-step-num   { color: #fff; }
.sc-step--future .sc-step-num   { color: rgba(255,255,255,.4); }
.sc-step-lbl {
  font-family: inherit;
  font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
  white-space: nowrap;
}
.sc-step--done   .sc-step-lbl   { color: #00E676; }
.sc-step--active .sc-step-lbl   { color: #00B4FF; text-shadow: 0 0 20px rgba(0,180,255,.25); }
.sc-step--future .sc-step-lbl   { color: rgba(255,255,255,.3); }
.sc-step-line { flex: 1; height: 1.5px; background: rgba(255,255,255,.1); margin: 0 4px; position: relative; top: -7px; }
.sc-step-line--done { background: rgba(0,168,107,.5); }

/* ── CONTENT — LIGHT ZONE ────────────────────────────────────── */
.sc-content {
  --background: linear-gradient(180deg, #EAF0FA 0%, #F2F5FB 40%, #E4EBF7 100%);
  --color: #0B1A30;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Ubuntu, 'Helvetica Neue', Arial, sans-serif;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  text-rendering: optimizeLegibility;
}
.sc-body {
  padding: 12px 16px 24px;
  display: flex; flex-direction: column; gap: 0;
  animation: sc-slideUp .5s cubic-bezier(.16,1,.3,1) both;
}

/* Guards */
.sc-guard {
  display: flex; align-items: flex-start; gap: 12px;
  margin: 16px; padding: 16px; border-radius: 16px;
  background: linear-gradient(135deg, #B01840, #E02050);
  box-shadow: 0 4px 16px rgba(224,32,80,.2);
}
.sc-guard--warn { background: linear-gradient(135deg, #CC8800 0%, #E6A000 100%); box-shadow: 0 4px 16px rgba(204,136,0,.2); }
.sc-guard svg { width: 22px; height: 22px; flex-shrink: 0; margin-top: 1px; }
.sc-guard-title { font-size: 14px; font-weight: 700; color: #fff; }
.sc-guard-sub   { font-size: 12px; color: rgba(255,255,255,.85); margin-top: 3px; line-height: 1.4; }

/* POE mismatch advisory */
.sc-poe-warn {
  display: flex; align-items: flex-start; gap: 9px;
  padding: 10px 12px; border-radius: 12px; margin-bottom: 8px;
  background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
  border: 1.5px solid rgba(204,136,0,.12);
  font-size: 12px; color: #0B1A30; line-height: 1.4;
}
.sc-poe-warn svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; stroke: #CC8800; }

/* Loading */
.sc-loading { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 200px; gap: 12px; }
.sc-spinner { width: 32px; height: 32px; border: 3px solid rgba(0,112,224,.15); border-top-color: #0070E0; border-radius: 50%; animation: sc-spin .8s linear infinite; }
.sc-loading-txt { font-size: 13px; color: #475569; font-weight: 600; }

/* ── SECTION HEADERS ──────────────────────────────────────────── */
.sc-section-hdr {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 0 4px;
  margin: 14px 0 8px;
}
.sc-sec-num {
  width: 22px; height: 22px; border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 900; color: #fff; flex-shrink: 0;
}
.sc-sec-num--blue   { background: linear-gradient(135deg, #0055CC, #0070E0); }
.sc-sec-num--orange { background: linear-gradient(135deg, #CC8800, #E6A000); }
.sc-sec-num--red    { background: linear-gradient(135deg, #B01840, #E02050); }
.sc-sec-num--green  { background: linear-gradient(135deg, #007A50, #00A86B); }
.sc-sec-num--purple { background: linear-gradient(135deg, #5B20B6, #7B40D8); }
.sc-sec-title {
  font-family: inherit;
  font-size: 14px; font-weight: 600; color: #0B1A30; letter-spacing: -.25px; flex: 1;
  line-height: 1.35;
}
.sc-sec-badge {
  padding: 3px 9px; border-radius: 5px; font-size: 9px;
  font-weight: 700; letter-spacing: .6px; text-transform: uppercase;
  border: 1px solid;
  font-family: inherit;
}
.sc-sec-badge--req  { background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); color: #E02050; border-color: rgba(224,32,80,.1); }
.sc-sec-badge--opt  { background: linear-gradient(135deg, #F8FAFC 0%, #F1F5F9 100%); color: #94A3B8; border-color: rgba(0,0,0,.06); }
.sc-sec-badge--warn { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); color: #CC8800; border-color: rgba(204,136,0,.12); }
.sc-scan-btn{
  display:inline-flex;align-items:center;gap:6px;
  margin-left:auto;
  padding:6px 10px;border-radius:8px;
  border:1px solid #1565C0;background:#EFF6FF;color:#1565C0;
  font-size:11px;font-weight:700;cursor:pointer;line-height:1;
}
.sc-scan-btn:active{transform:translateY(1px)}

/* ── FIELD CARD — sentinel-card pattern ──────────────────────── */
.sc-card {
  background: linear-gradient(145deg, #FFFFFF 0%, #F4F7FC 100%);
  border: 1.5px solid rgba(0,0,0,.06);
  border-radius: 14px;
  box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  overflow: hidden;
  position: relative;
  transition: all .25s cubic-bezier(.16,1,.3,1);
}
.sc-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent 20%, rgba(255,255,255,.8) 50%, transparent 80%);
  z-index: 1;
}
.sc-card::after {
  content: ''; position: absolute; top: 0; bottom: 0; width: 40%; left: 0;
  background: linear-gradient(90deg, transparent, rgba(0,112,224,.02), transparent);
  animation: sc-dataStream 6s ease-in-out infinite; pointer-events: none;
}

.sc-field-row {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 12px 16px;
  border-bottom: 1px solid rgba(0,0,0,.04);
  position: relative; z-index: 1;
}
.sc-field-row--last { border-bottom: none; }
.sc-field-ic {
  width: 32px; height: 32px; border-radius: 8px;
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%);
  border: 1px solid rgba(0,112,224,.15);
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; margin-top: 1px;
}
.sc-field-ic svg { width: 14px; height: 14px; }
.sc-field-body { flex: 1; min-width: 0; }
.sc-field-lbl {
  font-family: inherit;
  font-size: 9px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 1.2px; color: #94A3B8; display: block; margin-bottom: 4px;
}
.sc-field-input {
  width: 100%; border: 1.5px solid rgba(0,0,0,.08);
  border-radius: 10px;
  padding: 10px 12px; font-size: 16px; font-weight: 400; color: #0B1A30;
  background: linear-gradient(145deg, #E8EDF7 0%, #F0F3FA 100%);
  outline: none;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Ubuntu, 'Helvetica Neue', Arial, sans-serif;
  transition: all .25s cubic-bezier(.16,1,.3,1);
  min-height: 48px; box-sizing: border-box;
}
.sc-field-input--short { max-width: 120px; }
.sc-field-input:focus {
  border-color: rgba(0,112,224,.35);
  box-shadow: 0 0 0 3px rgba(0,112,224,.08);
  background: #fff;
}
.sc-field-input::placeholder { color: #94A3B8; }
.sc-field-select {
  width: 100%; border: 1.5px solid rgba(0,0,0,.08);
  border-radius: 10px;
  padding: 10px 12px; font-size: 16px; color: #0B1A30;
  background: linear-gradient(145deg, #E8EDF7 0%, #F0F3FA 100%);
  outline: none;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Ubuntu, 'Helvetica Neue', Arial, sans-serif;
  -webkit-appearance: none;
  min-height: 48px; box-sizing: border-box;
  transition: all .25s;
}
.sc-field-select:focus {
  border-color: rgba(0,112,224,.35);
  box-shadow: 0 0 0 3px rgba(0,112,224,.08);
  background: #fff;
}
.sc-field-err {
  font-size: 11.5px; font-weight: 700; color: #E02050;
  background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%);
  border: 1px solid rgba(224,32,80,.1);
  border-radius: 8px; padding: 8px 12px; margin-bottom: 8px;
}
.sc-field-input--err { border-color: #E02050 !important; box-shadow: 0 0 0 2px rgba(224,32,80,.12) !important; }
.sc-req-star { color: #E02050; font-weight: 800; margin-left: 2px; }

/* Gender buttons */
.sc-gender-row { display: flex; gap: 6px; flex-wrap: wrap; }
.sc-gender-btn {
  padding: 8px 16px; border-radius: 10px;
  border: 1.5px solid rgba(0,0,0,.06);
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  font-size: 13px; font-weight: 600; color: #475569;
  font-family: inherit;
  cursor: pointer; transition: all .15s cubic-bezier(.16,1,.3,1);
  min-height: 44px; box-sizing: border-box;
  -webkit-tap-highlight-color: transparent;
  box-shadow: 0 1px 3px rgba(0,0,0,.03);
}
.sc-gender-btn--active {
  background: linear-gradient(135deg, #0055CC 0%, #0070E0 50%, #3399FF 100%);
  border-color: transparent; color: #fff;
  box-shadow: 0 4px 16px rgba(0,112,224,.25);
}
.sc-gender-btn:active { transform: scale(.96); }

/* Chip buttons (doc type, conveyance) */
.sc-chip-row { display: flex; gap: 6px; flex-wrap: wrap; }
.sc-chip-btn {
  padding: 7px 14px; border-radius: 22px;
  border: 1.5px solid rgba(0,0,0,.06);
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  font-size: 12px; font-weight: 600; color: #475569;
  font-family: inherit;
  cursor: pointer; transition: all .15s cubic-bezier(.16,1,.3,1);
  min-height: 44px; box-sizing: border-box;
  -webkit-tap-highlight-color: transparent;
  box-shadow: 0 1px 3px rgba(0,0,0,.03);
}
.sc-chip-btn--active {
  background: linear-gradient(135deg, #E0ECFF 0%, #D0E0FF 100%);
  border-color: rgba(0,112,224,.3); color: #0070E0;
  box-shadow: 0 2px 8px rgba(0,112,224,.1);
}
.sc-chip-btn:active { transform: scale(.96); }

/* ── TRAVEL COUNTRIES ─────────────────────────────────────────── */
.sc-empty-travel {
  display: flex; align-items: center; gap: 8px;
  padding: 14px 16px;
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px dashed rgba(0,0,0,.1); border-radius: 12px;
  font-size: 12px; color: #94A3B8; font-weight: 600;
  box-shadow: 0 1px 3px rgba(0,0,0,.03);
}
.sc-empty-travel svg { width: 18px; height: 18px; flex-shrink: 0; }
.sc-tc-row {
  display: flex; gap: 6px; align-items: flex-start;
  margin-bottom: 8px;
}
.sc-tc-country { flex: 1; min-width: 0; }
.sc-tc-role {
  width: 96px; border: 1.5px solid rgba(0,0,0,.08); border-radius: 10px;
  padding: 10px 8px; font-size: 12px; color: #475569;
  background: linear-gradient(145deg, #E8EDF7, #F0F3FA);
  outline: none; font-family: inherit;
  -webkit-appearance: none; flex-shrink: 0;
  min-height: 48px; box-sizing: border-box;
}
.sc-tc-remove {
  width: 44px; height: 44px; border-radius: 10px;
  background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%);
  border: 1px solid rgba(224,32,80,.1);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; flex-shrink: 0; color: #E02050;
  -webkit-tap-highlight-color: transparent;
  transition: all .15s;
}
.sc-tc-remove:active { transform: scale(.95); }
.sc-tc-remove svg { width: 12px; height: 12px; }
.sc-add-country-btn {
  display: flex; align-items: center; justify-content: center; gap: 8px;
  width: 100%; padding: 12px 16px; border-radius: 12px;
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%);
  border: 1.5px dashed rgba(0,112,224,.3);
  font-size: 13px; font-weight: 600; color: #0070E0;
  font-family: inherit;
  cursor: pointer; margin-top: 4px;
  min-height: 48px; box-sizing: border-box;
  -webkit-tap-highlight-color: transparent;
  transition: all .15s cubic-bezier(.16,1,.3,1);
}
.sc-add-country-btn svg { width: 14px; height: 14px; }
.sc-add-country-btn:active { transform: scale(.98); background: linear-gradient(135deg, #D0DCEF 0%, #BCD4FF 100%); }

/* ── SYMPTOM CHECKLIST ────────────────────────────────────────── */
.sc-sym-count {
  margin-left: auto; font-size: 11px; font-weight: 700;
  color: #0070E0;
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%);
  border: 1px solid rgba(0,112,224,.12);
  padding: 3px 9px; border-radius: 5px;
  font-family: inherit;
}
.sc-sym-intro {
  font-size: 12px; color: #475569; line-height: 1.5;
  margin-bottom: 10px;
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border-radius: 12px;
  padding: 10px 14px;
  border: 1.5px solid rgba(0,0,0,.06);
  box-shadow: 0 1px 3px rgba(0,0,0,.03);
}
.sc-sym-group { margin-bottom: 12px; }
.sc-sym-group-hdr {
  display: flex; align-items: center; gap: 7px;
  font-family: inherit;
  font-size: 9px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 1.2px; color: #94A3B8; margin-bottom: 8px;
}
.sc-sym-group-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
/* Symptom grid: single column on phones (avoids label squeeze), two
   columns only at >= 480 CSS-px to keep tablet density. */
.sc-sym-grid { display: grid; grid-template-columns: 1fr; gap: 8px; }
@media (min-width: 480px) {
  .sc-sym-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
}
.sc-sym-card {
  display: flex; align-items: center; gap: 10px;
  padding: 12px 14px; border-radius: 12px;
  border: 1.5px solid rgba(0,0,0,.06);
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  cursor: pointer; -webkit-tap-highlight-color: transparent;
  transition: all .15s cubic-bezier(.16,1,.3,1);
  min-height: 52px; box-sizing: border-box;
  box-shadow: 0 1px 3px rgba(0,0,0,.03);
  position: relative; overflow: hidden;
}
.sc-sym-card:active { transform: scale(.96); }
.sc-sym-card--on {
  background: linear-gradient(135deg, #0055CC 0%, #0070E0 50%, #3399FF 100%);
  border-color: transparent;
  box-shadow: 0 4px 16px rgba(0,112,224,.25);
}
.sc-sym-card--off {
  background: linear-gradient(145deg, #F8FAFC, #F1F5F9);
  border-color: rgba(0,0,0,.04);
}
.sc-sym-indicator {
  width: 20px; height: 20px; border-radius: 6px; flex-shrink: 0;
  border: 1.5px solid rgba(0,0,0,.1);
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  display: flex; align-items: center; justify-content: center;
  transition: all .15s;
}
.sc-sym-card--on .sc-sym-indicator { background: rgba(255,255,255,.25); border-color: rgba(255,255,255,.5); }
.sc-sym-indicator svg { width: 11px; height: 11px; }
.sc-sym-name {
  font-family: inherit;
  font-size: 13px; font-weight: 600; color: #334155;
  line-height: 1.3; flex: 1; text-align: left;
  word-break: break-word;
}
.sc-sym-name--on { color: #fff; font-weight: 700; }
.sc-sym-card--off .sc-sym-name { color: #94A3B8; }

/* Onset date inputs */
.sc-onset-row {
  display: flex; align-items: center; gap: 9px;
  padding: 8px 12px; margin-top: 4px;
  background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
  border-radius: 10px; border: 1px solid rgba(204,136,0,.12);
}
.sc-onset-ic { width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sc-onset-ic svg { width: 14px; height: 14px; }
.sc-onset-body { flex: 1; }
.sc-onset-lbl { font-size: 9px; font-weight: 700; color: #CC8800; letter-spacing: .8px; text-transform: uppercase; display: block; margin-bottom: 2px; }
.sc-onset-opt { font-weight: 600; color: #94A3B8; text-transform: none; letter-spacing: 0; margin-left: 4px; font-size: 9px; }
.sc-onset-input {
  border: 1.5px solid rgba(204,136,0,.2); border-radius: 10px;
  padding: 8px 10px; font-size: 16px; color: #0B1A30;
  background: #fff; outline: none;
  font-family: inherit;
  min-height: 44px; box-sizing: border-box;
  transition: all .25s;
}
.sc-onset-input:focus { border-color: rgba(204,136,0,.5); box-shadow: 0 0 0 3px rgba(204,136,0,.08); }

/* ── VITALS TOGGLE ────────────────────────────────────────────── */
.sc-vitals-toggle-hdr {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 16px; margin-top: 10px;
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0,0,0,.06);
  border-radius: 14px;
  box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  cursor: pointer; -webkit-tap-highlight-color: transparent;
  transition: all .2s cubic-bezier(.16,1,.3,1);
  min-height: 48px; box-sizing: border-box;
}
.sc-vitals-toggle-hdr:active { transform: scale(.99); }
.sc-vitals-toggle-left { display: flex; align-items: center; gap: 8px; }
.sc-vitals-toggle-ic {
  width: 32px; height: 32px; border-radius: 8px;
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%);
  border: 1px solid rgba(0,112,224,.15);
  display: flex; align-items: center; justify-content: center;
}
.sc-vitals-toggle-ic svg { width: 14px; height: 14px; stroke: #0070E0; }
.sc-vitals-toggle-lbl { font-size: 14px; font-weight: 600; color: #0B1A30; font-family: inherit; }
.sc-vitals-badge {
  padding: 3px 9px; border-radius: 5px; font-size: 9px; font-weight: 700;
  background: linear-gradient(135deg, #F8FAFC 0%, #F1F5F9 100%);
  color: #94A3B8; border: 1px solid rgba(0,0,0,.06);
  font-family: inherit;
}
.sc-vitals-chevron { width: 16px; height: 16px; stroke: #94A3B8; transition: transform .25s cubic-bezier(.16,1,.3,1); }
.sc-vitals-chevron--open { transform: rotate(180deg); }

.sc-vitals-panel {
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0,0,0,.06);
  border-radius: 14px;
  box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  padding: 16px; margin-top: 6px;
  display: flex; flex-direction: column; gap: 12px;
  position: relative; overflow: hidden;
}
.sc-vitals-panel::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent 20%, rgba(255,255,255,.8) 50%, transparent 80%);
}
.sc-vitals-note {
  font-size: 11px; color: #94A3B8;
  background: linear-gradient(180deg, #E4EBF7 0%, #EAF0FA 100%);
  border-radius: 8px; padding: 8px 12px;
  border: 1px solid rgba(0,0,0,.04);
}

/* Vitals inputs */
.sc-vt-row { display: flex; flex-direction: column; gap: 4px; }
.sc-vt-row--half { flex: 1; }
.sc-vt-pair { display: flex; gap: 10px; }
.sc-vt-lbl {
  font-family: inherit;
  font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: #94A3B8;
}
.sc-vt-inputs { display: flex; gap: 6px; }
.sc-vt-num {
  border: 1.5px solid rgba(0,0,0,.08); border-radius: 10px;
  padding: 10px 12px; font-size: 16px; font-weight: 600; color: #0B1A30;
  background: linear-gradient(145deg, #E8EDF7, #F0F3FA);
  outline: none; font-family: inherit; width: 100%;
  min-height: 48px; box-sizing: border-box;
  transition: all .25s;
}
.sc-vt-num--short { max-width: 100px; }
.sc-vt-num:focus { border-color: rgba(0,112,224,.35); box-shadow: 0 0 0 3px rgba(0,112,224,.08); background: #fff; }
.sc-vt-unit {
  border: 1.5px solid rgba(0,0,0,.08); border-radius: 10px; padding: 10px 8px;
  font-size: 14px; color: #475569;
  background: linear-gradient(145deg, #E8EDF7, #F0F3FA);
  outline: none; font-family: inherit; width: 64px; flex-shrink: 0;
  -webkit-appearance: none; min-height: 48px; box-sizing: border-box;
}
.sc-vt-warn {
  font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 6px;
  font-family: inherit;
}
.sc-vt-warn--sm   { font-size: 10px; }
.sc-vt-warn--warn { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); color: #CC8800; border: 1px solid rgba(204,136,0,.12); }
.sc-vt-warn--crit { background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); color: #E02050; border: 1px solid rgba(224,32,80,.1); }

/* Triage */
.sc-triage-row { display: flex; gap: 6px; }
.sc-triage-btn {
  flex: 1; padding: 10px 6px; border-radius: 12px;
  display: flex; flex-direction: column; align-items: center; gap: 3px;
  border: 1.5px solid; cursor: pointer;
  min-height: 48px; box-sizing: border-box;
  -webkit-tap-highlight-color: transparent;
  transition: all .15s cubic-bezier(.16,1,.3,1);
}
.sc-triage-btn--non_urgent { background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%); border-color: rgba(0,112,224,.15); }
.sc-triage-btn--non_urgent .sc-triage-lbl { color: #0070E0; }
.sc-triage-btn--urgent     { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); border-color: rgba(204,136,0,.15); }
.sc-triage-btn--urgent .sc-triage-lbl     { color: #CC8800; }
.sc-triage-btn--emergency  { background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); border-color: rgba(224,32,80,.15); }
.sc-triage-btn--emergency .sc-triage-lbl  { color: #E02050; }
.sc-triage-btn--active     { box-shadow: 0 0 0 3px rgba(0,112,224,.12); transform: translateY(-1px); }
.sc-triage-lbl { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; font-family: inherit; }
.sc-triage-sub { font-size: 8.5px; color: #94A3B8; }

/* Bool toggle */
.sc-bool-row { display: flex; gap: 6px; }
.sc-bool-btn {
  padding: 8px 14px; border-radius: 10px;
  border: 1.5px solid rgba(0,0,0,.06);
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  font-size: 13px; font-weight: 600; color: #475569;
  font-family: inherit;
  cursor: pointer; transition: all .15s;
  min-height: 44px; box-sizing: border-box;
}
.sc-bool-btn--yes {
  background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%);
  border-color: rgba(224,32,80,.1); color: #E02050;
}

/* ── EXPOSURES ────────────────────────────────────────────────── */
.sc-exposure-intro {
  font-size: 12px; color: #475569; line-height: 1.5;
  background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
  border: 1px solid rgba(204,136,0,.12);
  border-radius: 12px; padding: 12px 14px; margin-bottom: 10px;
  font-family: inherit;
}
.sc-exposure-list { display: flex; flex-direction: column; gap: 10px; }

.sc-exp-card {
  display: flex; gap: 12px; align-items: flex-start;
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0,0,0,.06);
  border-radius: 14px; padding: 14px 16px;
  box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  position: relative; overflow: hidden;
  transition: all .25s cubic-bezier(.16,1,.3,1);
}
.sc-exp-card::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent 20%, rgba(255,255,255,.8) 50%, transparent 80%);
}
.sc-exp-num {
  width: 24px; height: 24px; border-radius: 50%;
  background: linear-gradient(135deg, #0055CC, #0070E0);
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 900; color: #fff; flex-shrink: 0; margin-top: 1px;
}
.sc-exp-body { flex: 1; position: relative; z-index: 1; }
.sc-exp-question {
  font-family: inherit;
  font-size: 13.5px; font-weight: 600; color: #0B1A30;
  line-height: 1.45; margin-bottom: 10px;
}
.sc-exp-btns { display: flex; gap: 8px; }
.sc-exp-btn {
  flex: 1; padding: 10px 6px; border-radius: 10px;
  font-size: 13px; font-weight: 600; border: 1.5px solid;
  cursor: pointer; text-align: center;
  font-family: inherit;
  min-height: 44px; box-sizing: border-box;
  -webkit-tap-highlight-color: transparent;
  transition: all .15s cubic-bezier(.16,1,.3,1);
  position: relative; overflow: hidden;
}
.sc-exp-btn::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent 20%, rgba(255,255,255,.25) 50%, transparent 80%);
}
.sc-exp-btn:active { transform: scale(.95); }

.sc-exp-btn--yes    { background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border-color: rgba(0,168,107,.12); color: #00A86B; }
.sc-exp-btn--yes.sc-exp-btn--active {
  background: linear-gradient(135deg, #007A50 0%, #00A86B 100%);
  border-color: transparent; color: #fff;
  box-shadow: 0 4px 16px rgba(0,168,107,.25);
}

.sc-exp-btn--no     { background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); border-color: rgba(224,32,80,.1); color: #E02050; }
.sc-exp-btn--no.sc-exp-btn--active {
  background: linear-gradient(135deg, #B01840 0%, #E02050 100%);
  border-color: transparent; color: #fff;
  box-shadow: 0 4px 16px rgba(224,32,80,.2);
}

.sc-exp-btn--unk    { background: linear-gradient(145deg, #F8FAFC, #F1F5F9); border-color: rgba(0,0,0,.06); color: #475569; }
.sc-exp-btn--unk.sc-exp-btn--active {
  background: linear-gradient(135deg, #475569, #64748B); border-color: transparent; color: #fff;
}

.sc-exp-summary {
  display: flex; align-items: center; gap: 8px;
  margin-top: 10px; padding: 10px 14px; border-radius: 12px;
  background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
  border: 1px solid rgba(204,136,0,.12);
  font-size: 12px; color: #CC8800;
  font-family: inherit;
}
.sc-exp-summary svg { width: 14px; height: 14px; flex-shrink: 0; }

/* ── ANALYSIS ─────────────────────────────────────────────────── */
.sc-insuff-warn {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; border-radius: 12px;
  background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
  border: 1px solid rgba(204,136,0,.12);
  font-size: 12px; color: #CC8800; margin-bottom: 8px;
}
.sc-insuff-warn svg { width: 14px; height: 14px; flex-shrink: 0; }

.sc-flag-banner {
  display: flex; align-items: center; gap: 8px;
  padding: 12px 16px; border-radius: 12px; margin-bottom: 6px;
  background: linear-gradient(135deg, #B01840 0%, #E02050 100%);
  box-shadow: 0 4px 16px rgba(224,32,80,.2);
}
.sc-flag-banner svg { width: 16px; height: 16px; flex-shrink: 0; }
.sc-flag-txt { font-size: 12px; font-weight: 700; color: #fff; font-family: inherit; }

.sc-empty-analysis {
  display: flex; align-items: center; gap: 10px;
  padding: 16px;
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px dashed rgba(0,0,0,.1); border-radius: 14px;
  font-size: 12px; color: #94A3B8;
  box-shadow: 0 1px 3px rgba(0,0,0,.03);
}
.sc-empty-analysis svg { width: 22px; height: 22px; flex-shrink: 0; }

/* Disease cards */
.sc-disease-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 4px; }
.sc-disease-card {
  display: flex; align-items: center; gap: 10px;
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0,0,0,.06);
  border-radius: 14px; padding: 12px 14px;
  border-left: 4px solid #94A3B8;
  box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  position: relative; overflow: hidden;
  cursor: pointer;
  transition: all .25s cubic-bezier(.16,1,.3,1);
}
.sc-disease-card::before {
  content: ''; position: absolute; top: 0; left: 4px; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent 20%, rgba(255,255,255,.8) 50%, transparent 80%);
}
.sc-disease-card:active { transform: scale(.98); }
.sc-disease-card--very_high { border-left-color: #E02050; box-shadow: 0 0 8px rgba(224,32,80,.15), 0 1px 3px rgba(0,0,0,.04); }
.sc-disease-card--high      { border-left-color: #CC8800; box-shadow: 0 0 8px rgba(204,136,0,.15), 0 1px 3px rgba(0,0,0,.04); }
.sc-disease-card--moderate  { border-left-color: #CC8800; }
.sc-disease-card--low       { border-left-color: #0070E0; }
.sc-disease-card--very_low  { border-left-color: #94A3B8; }

.sc-dc-rank {
  width: 28px; height: 28px; border-radius: 8px; flex-shrink: 0;
  background: linear-gradient(135deg, #F8FAFC, #F1F5F9);
  border: 1px solid rgba(0,0,0,.06);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 900; color: #475569;
  font-family: inherit;
}
.sc-dc-rank--top { background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); border-color: rgba(224,32,80,.1); color: #E02050; }
.sc-dc-body { flex: 1; min-width: 0; }
.sc-dc-name { font-family: inherit; font-size: 13px; font-weight: 700; color: #0B1A30; }
.sc-dc-meta { display: flex; align-items: center; gap: 6px; margin-top: 3px; flex-wrap: wrap; }
.sc-dc-score { font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace; font-size: 10px; font-weight: 500; color: #475569; letter-spacing: .3px; }
.sc-dc-band {
  font-family: inherit;
  font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px;
  padding: 2px 7px; border-radius: 5px; border: 1px solid;
}
.sc-dc-band--very_high { background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); color: #E02050; border-color: rgba(224,32,80,.1); }
.sc-dc-band--high      { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); color: #CC8800; border-color: rgba(204,136,0,.12); }
.sc-dc-band--moderate  { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); color: #CC8800; border-color: rgba(204,136,0,.12); }
.sc-dc-band--low       { background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%); color: #0070E0; border-color: rgba(0,112,224,.12); }
.sc-dc-band--very_low  { background: linear-gradient(135deg, #F8FAFC 0%, #F1F5F9 100%); color: #94A3B8; border-color: rgba(0,0,0,.06); }
.sc-dc-ihr  {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 9px; font-weight: 500; color: #7B40D8;
  background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%);
  border: 1px solid rgba(123,64,216,.12);
  padding: 2px 7px; border-radius: 5px;
}
.sc-dc-hallmarks { margin-top: 4px; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
.sc-dc-hlbl { font-size: 9px; font-weight: 700; color: #94A3B8; }
.sc-dc-htag {
  font-size: 9px;
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%);
  color: #0070E0; padding: 2px 6px; border-radius: 5px; font-weight: 700;
  border: 1px solid rgba(0,112,224,.12);
}
.sc-dc-pct {
  font-family: inherit;
  font-size: 18px; font-weight: 800; flex-shrink: 0; letter-spacing: -1px;
  line-height: 1;
}
.sc-dc-pct--very_high { color: #E02050; }
.sc-dc-pct--high      { color: #CC8800; }
.sc-dc-pct--moderate  { color: #CC8800; }
.sc-dc-pct--low       { color: #0070E0; }
.sc-dc-pct--very_low  { color: #94A3B8; }

/* Disease-card right-rail: ⓘ score-breakdown button + percentage stack */
.sc-dc-right {
  display: flex; flex-direction: column; align-items: flex-end;
  gap: 6px; flex-shrink: 0;
}
.sc-dc-info {
  width: 24px; height: 24px;
  border-radius: 50%;
  border: 1px solid rgba(15,23,42,0.10);
  background: rgba(0,112,224,0.06);
  color: #1D4ED8;
  display: inline-flex; align-items: center; justify-content: center;
  cursor: pointer;
  transition: transform 0.12s ease, background 0.12s ease;
  -webkit-tap-highlight-color: transparent;
}
.sc-dc-info:hover  { background: rgba(0,112,224,0.14); transform: scale(1.08); }
.sc-dc-info:active { transform: scale(0.96); }

/* Syndrome grid */
.sc-syndrome-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
.sc-syn-btn {
  padding: 10px 4px; border-radius: 12px;
  border: 1.5px solid rgba(0,0,0,.06);
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  text-align: center; cursor: pointer;
  min-height: 48px; box-sizing: border-box;
  -webkit-tap-highlight-color: transparent;
  transition: all .15s cubic-bezier(.16,1,.3,1);
  box-shadow: 0 1px 3px rgba(0,0,0,.03);
}
.sc-syn-btn:active { transform: scale(.95); }
.sc-syn-code {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 10px; font-weight: 600; letter-spacing: .3px; display: block; color: #475569;
}
.sc-syn-name { font-family: inherit; font-size: 8.5px; font-weight: 500; display: block; margin-top: 1px; color: #94A3B8; line-height: 1.2; }
.sc-syn-btn--active {
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%);
  border-color: rgba(0,112,224,.3);
  box-shadow: 0 2px 8px rgba(0,112,224,.1);
}
.sc-syn-btn--active .sc-syn-code { color: #0070E0; }
.sc-syn-btn--active .sc-syn-name { color: #0070E0; }
.sc-syn-btn--danger { border-color: rgba(224,32,80,.15); }
.sc-syn-btn--danger .sc-syn-code { color: #E02050; }
.sc-syn-btn--danger.sc-syn-btn--active { background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); border-color: rgba(224,32,80,.2); }

/* Risk level */
.sc-risk-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; }
.sc-risk-btn {
  padding: 10px 4px; border-radius: 12px; border: 1.5px solid;
  text-align: center; cursor: pointer;
  min-height: 48px; box-sizing: border-box;
  -webkit-tap-highlight-color: transparent;
  transition: all .15s cubic-bezier(.16,1,.3,1);
}
.sc-risk-btn:active { transform: scale(.95); }
.sc-risk-lbl { font-family: inherit; font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .3px; display: block; }
.sc-risk-sub { font-size: 8.5px; font-weight: 500; display: block; margin-top: 1px; }
.sc-risk-btn--low      { background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border-color: rgba(0,168,107,.12); }
.sc-risk-btn--low .sc-risk-lbl      { color: #00A86B; }
.sc-risk-btn--low .sc-risk-sub      { color: #00A86B; opacity: .6; }
.sc-risk-btn--medium   { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); border-color: rgba(204,136,0,.12); }
.sc-risk-btn--medium .sc-risk-lbl   { color: #CC8800; }
.sc-risk-btn--medium .sc-risk-sub   { color: #CC8800; opacity: .6; }
.sc-risk-btn--high     { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); border-color: rgba(204,136,0,.15); }
.sc-risk-btn--high .sc-risk-lbl     { color: #CC8800; }
.sc-risk-btn--high .sc-risk-sub     { color: #CC8800; opacity: .6; }
.sc-risk-btn--critical { background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); border-color: rgba(224,32,80,.12); }
.sc-risk-btn--critical .sc-risk-lbl { color: #E02050; }
.sc-risk-btn--critical .sc-risk-sub { color: #E02050; opacity: .6; }
.sc-risk-btn--active   { box-shadow: 0 0 0 3px rgba(0,112,224,.1); transform: translateY(-1px); }

/* Alert preview */
.sc-alert-preview {
  background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
  border: 2px solid rgba(204,136,0,.25); border-radius: 16px; overflow: hidden; margin-top: 8px;
  box-shadow: 0 4px 20px rgba(204,136,0,.1);
}
.sc-ap-hdr {
  background: linear-gradient(135deg, #CC8800 0%, #E6A000 100%);
  padding: 12px 16px; display: flex; align-items: center; gap: 8px;
}
.sc-ap-hdr svg { width: 16px; height: 16px; flex-shrink: 0; }
.sc-ap-title { font-family: inherit; font-size: 12px; font-weight: 700; color: #fff; flex: 1; }

/* ── Notification verification panel ──────────────────────────── */
.sc-sec-badge--auto {
  background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
  color: #00A86B; border-color: rgba(0,168,107,.12);
}
.sc-auto-hint {
  display: flex; align-items: center; gap: 5px;
  font-size: 11px; color: #00A86B;
  background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
  border: 1px solid rgba(0,168,107,.12); border-radius: 8px;
  padding: 8px 12px; margin-bottom: 8px;
}
.sc-verify-panel {
  margin: 16px 0 8px;
  border: 1.5px solid rgba(0,112,224,.3);
  border-radius: 14px;
  overflow: hidden;
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
}
.sc-verify-header {
  display: flex; align-items: center; gap: 8px;
  background: linear-gradient(135deg, #0055CC 0%, #0070E0 100%);
  color: #fff; padding: 10px 14px;
}
.sc-verify-title { font-family: inherit; font-size: 12px; font-weight: 700; flex: 1; }
.sc-verify-btn {
  background: rgba(255,255,255,0.15); color: #fff;
  border: 1px solid rgba(255,255,255,.25); border-radius: 8px;
  padding: 6px 14px; font-size: 11px; font-weight: 600; cursor: pointer;
  font-family: inherit;
  min-height: 36px;
  transition: all .15s;
}
.sc-verify-btn:disabled { opacity: 0.5; cursor: default; }
.sc-verify-btn--sync { background: rgba(255,255,255,0.25); }

/* Sync result block */
.sc-sync-result-block { border-top: 1px solid rgba(0,0,0,.04); padding: 0 0 4px; }
.sc-srb-title { font-family: inherit; font-size: 10px; color: #94A3B8; padding: 6px 14px 2px; }
.sc-srb-row { display: flex; align-items: baseline; gap: 6px; padding: 6px 14px; border-bottom: 1px solid rgba(0,0,0,.04); font-size: 11px; }
.sc-srb-row--ok      { background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 50%); }
.sc-srb-row--fail    { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 50%); }
.sc-srb-row--skip    { background: #F8FAFC; }
.sc-srb-row--pending { background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 50%); }
.sc-srb-phase  { font-weight: 700; color: #0B1A30; min-width: 110px; flex-shrink: 0; font-family: inherit; }
.sc-srb-status { font-weight: 700; min-width: 36px; text-transform: uppercase; font-size: 10px; font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace; }
.sc-srb-row--ok   .sc-srb-status { color: #00A86B; }
.sc-srb-row--fail .sc-srb-status { color: #CC8800; }
.sc-srb-row--skip .sc-srb-status { color: #94A3B8; }
.sc-srb-msg    { font-size: 10.5px; color: #475569; flex: 1; word-break: break-all; }
.sc-srb-error  { padding: 6px 14px; font-size: 11px; color: #E02050; background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); font-weight: 600; }
.sc-verify-idle { padding: 14px; font-size: 12px; color: #94A3B8; text-align: center; }
.sc-verify-error { padding: 10px 14px; font-size: 12px; color: #E02050; background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); }
.sc-verify-results { padding: 8px 0 0; }
.sc-verify-row {
  display: flex; align-items: flex-start; gap: 8px;
  padding: 8px 14px; border-bottom: 1px solid rgba(0,0,0,.04);
}
.sc-verify-row--pass { background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 50%); }
.sc-verify-row--fail { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 50%); }
.sc-verify-icon { font-size: 13px; font-weight: 800; min-width: 14px; margin-top: 1px; }
.sc-verify-row--pass .sc-verify-icon { color: #00A86B; }
.sc-verify-row--fail .sc-verify-icon { color: #CC8800; }
.sc-verify-body { flex: 1; min-width: 0; }
.sc-verify-label { font-family: inherit; font-size: 11.5px; font-weight: 600; color: #0B1A30; line-height: 1.3; }
.sc-verify-detail { font-size: 10.5px; color: #475569; margin-top: 2px; word-break: break-all; }
.sc-verify-summary {
  margin: 8px 14px 10px; padding: 8px 12px; border-radius: 8px;
  font-size: 12px; font-weight: 700; text-align: center;
  font-family: inherit;
}
.sc-verify-summary--ok  { background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); color: #007A50; }
.sc-verify-summary--warn { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); color: #CC8800; }
.sc-verify-raw {
  margin: 4px 14px 10px; border: 1px solid rgba(0,0,0,.06); border-radius: 8px; overflow: hidden;
}
.sc-verify-raw summary {
  padding: 6px 10px; font-size: 11px; font-weight: 600; color: #475569;
  background: linear-gradient(180deg, #E4EBF7 0%, #EAF0FA 100%);
  cursor: pointer; user-select: none;
}
.sc-verify-raw pre {
  margin: 0; padding: 10px;
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 10px; color: #0B1A30;
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  overflow-x: auto; max-height: 200px; overflow-y: auto;
  white-space: pre-wrap; word-break: break-all;
}
.sc-ap-badge {
  padding: 3px 9px; border-radius: 5px; background: rgba(255,255,255,.2);
  border: 1px solid rgba(255,255,255,.3); font-size: 9px; font-weight: 700;
  color: #fff; text-transform: uppercase; letter-spacing: .6px;
  font-family: inherit;
}
.sc-ap-body { padding: 12px 16px; display: flex; flex-direction: column; gap: 8px; }
.sc-ap-row { display: flex; align-items: center; justify-content: space-between; }
.sc-ap-k { font-family: inherit; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px; color: #94A3B8; }
.sc-ap-v { font-family: inherit; font-size: 12px; font-weight: 700; color: #0B1A30; }
.sc-ap-v--warn { color: #CC8800; }
.sc-ap-target {
  padding: 3px 9px; border-radius: 5px; font-size: 10.5px; font-weight: 700; border: 1px solid;
  font-family: inherit;
}
.sc-ap-target--district  { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); color: #CC8800; border-color: rgba(204,136,0,.12); }
.sc-ap-target--pheoc     { background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%); color: #7B40D8; border-color: rgba(123,64,216,.12); }
.sc-ap-target--national  { background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); color: #E02050; border-color: rgba(224,32,80,.1); }

/* AI WHO/Africa CDC recommendations panel */
.sc-rec-panel{
  margin-top:14px;border:1px solid #BFDBFE;background:linear-gradient(145deg,#EFF6FF,#FFFFFF);
  border-radius:14px;padding:12px 14px;box-shadow:0 1px 3px rgba(15,23,42,.04);
}
.sc-rec-hdr{display:flex;gap:10px;align-items:flex-start;margin-bottom:8px}
.sc-rec-ic{flex-shrink:0;width:24px;height:24px;border-radius:50%;background:#DBEAFE;display:flex;align-items:center;justify-content:center}
.sc-rec-titlewrap{flex:1;min-width:0}
.sc-rec-title{font-size:13px;font-weight:800;color:#1E3A8A;letter-spacing:-.01em}
.sc-rec-sub{font-size:11px;color:#475569;margin-top:1px}
.sc-rec-tier{font-weight:700;color:#B91C1C;margin-left:4px}
.sc-rec-list{margin:6px 0 8px;padding:0;list-style:none}
.sc-rec-item{display:flex;gap:8px;padding:5px 0;border-bottom:1px solid rgba(191,219,254,.5)}
.sc-rec-item:last-child{border-bottom:none}
.sc-rec-item--done .sc-rec-bullet { color: #10B981; font-weight: 900; }
.sc-rec-item--done .sc-rec-action { color: rgba(15,23,42,0.55); text-decoration: line-through; text-decoration-color: rgba(16,185,129,0.55); }
.sc-rec-bullet{color:#1D4ED8;font-weight:800;flex-shrink:0;line-height:1.5;width:14px;text-align:center}
.sc-rec-body{flex:1;min-width:0}
.sc-rec-item-btn{
  display:flex;width:100%;gap:8px;padding:0;
  background:transparent;border:none;text-align:left;cursor:pointer;
  border-radius:6px;
  -webkit-tap-highlight-color:transparent;
  transition:background .15s;
  font-family:inherit;color:inherit;
}
.sc-rec-item-btn:hover{background:rgba(29,78,216,.06)}
.sc-rec-item-btn:active{background:rgba(29,78,216,.10)}
.sc-rec-action-row{
  display:flex;align-items:center;gap:8px;
  justify-content:space-between;
}
.sc-rec-action{font-size:12.5px;color:#0F172A;line-height:1.45;font-weight:600;flex:1;min-width:0}
.sc-rec-src{font-size:10px;color:#64748B;margin-top:2px;font-style:italic}
.sc-rec-urg{
  flex-shrink:0;
  font-size:9.5px;font-weight:800;letter-spacing:.6px;
  text-transform:uppercase;
  padding:2px 7px;border-radius:999px;
  font-style:normal;
  line-height:1.2;
}
.sc-rec-urg--now    {background:#FEE2E2;color:#991B1B;border:1px solid #FCA5A5}
.sc-rec-urg--24h    {background:#FEF3C7;color:#92400E;border:1px solid #FDE68A}
.sc-rec-urg--routine{background:#DBEAFE;color:#1E3A8A;border:1px solid #BFDBFE}
.sc-rec-item--done .sc-rec-urg{opacity:.5;filter:grayscale(.4)}
.sc-rec-foot{font-size:10px;color:#475569;border-top:1px solid #BFDBFE;padding-top:6px;margin-top:4px}

/* Actions */
.sc-actions-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.sc-action-btn {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 12px; border-radius: 12px;
  border: 1.5px solid rgba(0,0,0,.06);
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  font-family: inherit;
  font-size: 12px; font-weight: 600; color: #475569;
  cursor: pointer; text-align: left;
  min-height: 44px; box-sizing: border-box;
  -webkit-tap-highlight-color: transparent;
  transition: all .15s cubic-bezier(.16,1,.3,1);
  box-shadow: 0 1px 3px rgba(0,0,0,.03);
}
.sc-action-btn:active { transform: scale(.96); }
.sc-action-btn--active {
  background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
  border-color: rgba(0,168,107,.12); color: #007A50; font-weight: 700;
}
.sc-action-ic {
  width: 20px; height: 20px; border-radius: 6px;
  border: 1.5px solid rgba(0,0,0,.1);
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.sc-action-btn--active .sc-action-ic {
  background: linear-gradient(135deg, #007A50, #00A86B);
  border-color: transparent;
  box-shadow: 0 0 8px rgba(0,168,107,.35);
}
.sc-action-dot { width: 6px; height: 6px; border-radius: 50%; background: rgba(0,0,0,.1); }
.sc-action-ic svg { width: 11px; height: 11px; }

/* Enforce warn */
.sc-enforce-warn {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; border-radius: 12px; margin-top: 8px;
  background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%);
  border: 1.5px solid rgba(224,32,80,.1);
  font-size: 12px; color: #E02050;
  font-family: inherit;
}
.sc-enforce-warn svg { width: 14px; height: 14px; flex-shrink: 0; }

/* Impossible-disposition banner (2026-05-06) */
.sc-disp-impossible {
  margin: 10px 0;
  border-radius: 12px;
  background: linear-gradient(135deg, #C62828 0%, #B71C1C 100%);
  color: #fff;
  padding: 12px 14px;
  box-shadow: 0 6px 18px rgba(198,40,40,0.32);
}
.sc-disp-impossible-hdr {
  display: flex; align-items: center; gap: 8px;
  font-weight: 700; font-size: 13px; letter-spacing: 0.2px;
}
.sc-disp-impossible-hdr svg { width: 16px; height: 16px; flex-shrink: 0; }
.sc-disp-impossible-list {
  margin: 6px 0 0 26px; padding: 0; font-size: 12.5px; line-height: 1.45;
}
.sc-disp-impossible-list li { margin: 2px 0; }

.sc-disp-warn {
  margin: 10px 0;
  border-radius: 12px;
  background: linear-gradient(135deg, #FFF8E1 0%, #FFE082 100%);
  color: #5d3a00;
  padding: 12px 14px;
  border: 1.5px solid rgba(138,75,0,0.18);
}
.sc-disp-warn-hdr {
  display: flex; align-items: center; gap: 8px;
  font-weight: 700; font-size: 13px;
}
.sc-disp-warn-hdr svg { width: 16px; height: 16px; flex-shrink: 0; }
.sc-disp-warn-list {
  margin: 6px 0 0 26px; padding: 0; font-size: 12.5px; line-height: 1.45;
}
.sc-disp-warn-list li { margin: 2px 0; }

/* Disposition */
.sc-disp-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; }
.sc-disp-btn {
  display: flex; flex-direction: column; align-items: center; gap: 5px;
  padding: 12px 8px; border-radius: 14px;
  border: 1.5px solid rgba(0,0,0,.06);
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  cursor: pointer; text-align: center;
  min-height: 56px; box-sizing: border-box;
  -webkit-tap-highlight-color: transparent;
  transition: all .15s cubic-bezier(.16,1,.3,1);
  box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  position: relative; overflow: hidden;
}
.sc-disp-btn::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent 20%, rgba(255,255,255,.8) 50%, transparent 80%);
}
.sc-disp-btn:active { transform: scale(.96); }
.sc-disp-btn--active {
  background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
  border-color: rgba(0,168,107,.2);
  box-shadow: 0 2px 8px rgba(0,168,107,.15);
}
.sc-disp-ic {
  width: 32px; height: 32px; border-radius: 8px;
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%);
  border: 1px solid rgba(0,112,224,.15);
  display: flex; align-items: center; justify-content: center;
}
.sc-disp-ic svg { width: 14px; height: 14px; stroke: #475569; }
.sc-disp-btn--active .sc-disp-ic {
  background: linear-gradient(135deg, #007A50 0%, #00A86B 100%);
  border-color: transparent;
}
.sc-disp-btn--active .sc-disp-ic svg { stroke: #fff; }
.sc-disp-lbl { font-family: inherit; font-size: 11px; font-weight: 600; color: #475569; }
.sc-disp-btn--active .sc-disp-lbl { color: #007A50; font-weight: 700; }

/* Notes */
.sc-notes-wrap {
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0,0,0,.06);
  border-radius: 14px; overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
  position: relative;
}
.sc-notes-wrap::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent 20%, rgba(255,255,255,.8) 50%, transparent 80%);
  z-index: 1;
}
.sc-notes-input {
  width: 100%; padding: 14px 16px; border: none; outline: none;
  font-size: 14px; color: #0B1A30;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Ubuntu, 'Helvetica Neue', Arial, sans-serif;
  line-height: 1.5; resize: vertical; min-height: 100px; background: transparent;
}
.sc-notes-input::placeholder { color: #94A3B8; }
.sc-voice-btn {
  position: absolute; right: 10px; bottom: 10px; width: 36px; height: 36px;
  border-radius: 50%; border: none; cursor: pointer;
  background: linear-gradient(135deg, #0B2545, #13315C); color: #00E6D6;
  box-shadow: 0 3px 8px rgba(11,37,69,.25); z-index: 2;
  display: flex; align-items: center; justify-content: center;
  transition: transform .1s, box-shadow .15s, background .15s;
}
.sc-voice-btn:active { transform: scale(.94); }
.sc-voice-btn--on {
  background: linear-gradient(135deg, #DC2626, #EF4444); color: #fff;
  animation: sc-voice-pulse 1.2s ease-in-out infinite;
}
@keyframes sc-voice-pulse {
  0%,100% { box-shadow: 0 0 0 0 rgba(239,68,68,.55), 0 3px 8px rgba(11,37,69,.25); }
  50%     { box-shadow: 0 0 0 10px rgba(239,68,68,0), 0 3px 8px rgba(11,37,69,.25); }
}
.sc-voice-msg {
  font-size: 11px; font-weight: 600; color: #92400E;
  background: #FEF3C7; border: 1px solid #FDE68A;
  border-radius: 7px; padding: 5px 10px; margin-top: 6px;
  line-height: 1.4;
}

/* Follow-up */
.sc-followup-row { display: flex; align-items: center; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
.sc-followup-toggle {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 16px; border-radius: 12px;
  border: 1.5px solid rgba(0,0,0,.06);
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  font-family: inherit;
  font-size: 13px; font-weight: 600; color: #475569;
  cursor: pointer; -webkit-tap-highlight-color: transparent;
  transition: all .15s cubic-bezier(.16,1,.3,1);
  min-height: 48px; box-sizing: border-box;
  box-shadow: 0 1px 3px rgba(0,0,0,.03);
}
.sc-followup-toggle--on {
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%);
  border-color: rgba(0,112,224,.3); color: #0070E0;
}
.sc-ft-indicator {
  width: 18px; height: 18px; border-radius: 50%;
  border: 2px solid rgba(0,0,0,.1); background: #fff; flex-shrink: 0;
  transition: all .15s;
}
.sc-followup-toggle--on .sc-ft-indicator {
  background: #0070E0; border-color: #0055CC;
  box-shadow: 0 0 8px rgba(0,112,224,.35);
}
.sc-ft-lbl { font-size: 12.5px; }
.sc-followup-level {
  border: 1.5px solid rgba(0,0,0,.08); border-radius: 10px;
  padding: 10px 12px; font-size: 14px; color: #0B1A30;
  background: linear-gradient(145deg, #E8EDF7, #F0F3FA);
  outline: none; font-family: inherit;
  -webkit-appearance: none;
  min-height: 48px; box-sizing: border-box;
}

/* Suspected diseases list */
.sc-sus-list {
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0,0,0,.06);
  border-radius: 14px; overflow: hidden;
  box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
}
.sc-sus-row {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 16px; border-bottom: 1px solid rgba(0,0,0,.04);
}
.sc-sus-row:last-child { border-bottom: none; }
.sc-sus-rank {
  width: 22px; height: 22px; border-radius: 6px;
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%);
  border: 1px solid rgba(0,112,224,.12);
  display: flex; align-items: center; justify-content: center;
  font-family: inherit;
  font-size: 10px; font-weight: 800; color: #0070E0; flex-shrink: 0;
}
.sc-sus-name { flex: 1; font-family: inherit; font-size: 13px; font-weight: 600; color: #0B1A30; text-transform: capitalize; }
.sc-sus-conf {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 12px; font-weight: 500; color: #0070E0;
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%);
  border: 1px solid rgba(0,112,224,.12);
  padding: 2px 8px; border-radius: 5px;
}

/* ── FOOTER — Premium gradient ───────────────────────────────── */
.sc-footer {
  background: linear-gradient(180deg, #F4F7FC 0%, #FFFFFF 100%);
  border-top: 1px solid rgba(0,0,0,.06);
  box-shadow: 0 -2px 12px rgba(0,30,80,.06);
}
/* Step-1 inline missing-fields panel — sits above the Next button so the
   officer can see exactly which fields are blocking advance. */
.sc-footer-problems {
  background: linear-gradient(180deg, #FFF5F5 0%, #FFECEC 100%);
  border-top: 1px solid rgba(183,28,28,.12);
  border-bottom: 1px solid rgba(183,28,28,.08);
  padding: 10px 16px 8px;
  color: #7F1010;
  font-size: 12.5px;
  line-height: 1.4;
}
.sc-footer-problems-hdr {
  display: flex; align-items: center; gap: 6px;
  font-weight: 700; color: #B71C1C; margin-bottom: 4px;
}
.sc-footer-problems-hdr svg { width: 14px; height: 14px; flex-shrink: 0; }
.sc-footer-problems-list {
  margin: 0; padding-left: 22px; list-style: disc;
}
.sc-footer-problems-list li { margin: 1px 0; }
.sc-footer-inner {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 16px 10px; gap: 10px;
  padding-bottom: max(10px, env(safe-area-inset-bottom));
}
.sc-nav-spacer { flex: 1; }

.sc-nav-btn {
  display: flex; align-items: center; gap: 6px;
  height: 48px; border-radius: 12px; font-size: 14px; font-weight: 600;
  font-family: inherit;
  letter-spacing: .5px;
  cursor: pointer; border: none; padding: 0 20px;
  -webkit-tap-highlight-color: transparent;
  transition: all .15s cubic-bezier(.16,1,.3,1);
  position: relative; overflow: hidden;
}
.sc-nav-btn::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent 20%, rgba(255,255,255,.25) 50%, transparent 80%);
}
.sc-nav-btn:active { transform: scale(.97); }
.sc-nav-btn:disabled { opacity: .5; cursor: not-allowed; transform: none; }
.sc-nav-btn svg { width: 14px; height: 14px; flex-shrink: 0; }

.sc-nav-btn--back {
  background: linear-gradient(145deg, #FFFFFF, #F4F7FC);
  border: 1.5px solid rgba(0,112,224,.25);
  color: #0070E0;
  box-shadow: 0 1px 3px rgba(0,0,0,.04), 0 4px 20px rgba(0,30,80,.06);
}
.sc-nav-btn--back svg { stroke: #0070E0; }

.sc-nav-btn--next {
  flex: 1; justify-content: center;
  background: linear-gradient(135deg, #0055CC 0%, #0070E0 50%, #3399FF 100%);
  color: #fff;
  box-shadow: 0 4px 16px rgba(0,112,224,.25);
}
.sc-nav-btn--next svg { stroke: #fff; }

.sc-nav-btn--analyse {
  flex: 1; justify-content: center;
  background: linear-gradient(135deg, #CC8800 0%, #E6A000 100%);
  color: #fff;
  box-shadow: 0 4px 16px rgba(204,136,0,.25);
}

.sc-nav-btn--disposition {
  flex: 1; justify-content: center;
  background: linear-gradient(135deg, #007A50 0%, #00A86B 100%);
  color: #fff;
  box-shadow: 0 4px 16px rgba(0,168,107,.25);
}
.sc-nav-btn--disposition:disabled {
  background: linear-gradient(145deg, #F8FAFC, #F1F5F9);
  color: #94A3B8; box-shadow: none; border: 1px solid rgba(0,0,0,.06);
}

/* 1-M step 4 proceed CTA */
.sc-step4-proceed { padding: 16px 12px 4px; }
.sc-step4-proceed-btn { width: 100%; justify-content: center; }

/* 1-L officer re-run feedback */
.sc-override-rerun-note { font-size:11px; color:#2E7D32; font-weight:600; display:block; margin-top:4px; }
.sc-override-rerun-btn { margin-top:6px; font-size:11px; font-weight:600; color:#1565C0; background:#EFF6FF; border:1px solid #BFDBFE; border-radius:6px; padding:4px 10px; cursor:pointer; }


/* 1-K IHR tier badge (replaces the redundant NOTIFICATION REQUIRED badge) */
.sc-ihr-tier-badge { font-size:9px; font-weight:700; letter-spacing:.5px; background:#E8F5E9; color:#1B5E20; border-radius:4px; padding:2px 6px; }

/* ── TRAVELER DIRECTION BADGE ─────────────────────────────── */
.sc-dir-badge {
  padding: 3px 9px;
  border-radius: 5px;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: .8px;
  text-transform: uppercase;
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  flex-shrink: 0;
  border: 1px solid;
}
.sc-dir--entry   { background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%); color: #0070E0; border-color: rgba(0,112,224,.15); }
.sc-dir--exit    { background: linear-gradient(135deg, #F5F3FF 0%, #EDE9FE 100%); color: #7B40D8; border-color: rgba(123,64,216,.15); }
.sc-dir--transit { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); color: #CC8800; border-color: rgba(204,136,0,.15); }

/* ── NON-CASE BANNER ──────────────────────────────────────── */
.sc-noncase-banner {
  background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
  border: 1.5px solid rgba(0,168,107,.12); border-radius: 14px;
  padding: 14px 16px; margin-bottom: 12px;
  display: flex; flex-direction: column; gap: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,.03);
}
.sc-nc-hdr {
  display: flex; align-items: center; gap: 8px;
  font-family: inherit;
  font-size: 13px; font-weight: 700; color: #007A50;
}
.sc-nc-reason { font-family: inherit; font-size: 11px; color: #00A86B; font-weight: 600; padding-left: 24px; }
.sc-nc-action {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 11px; color: #00A86B; font-weight: 500; padding-left: 24px;
}
.sc-nc-override-btn {
  align-self: flex-start; margin-top: 4px;
  padding: 8px 14px; border-radius: 8px;
  font-size: 11px; font-weight: 600; cursor: pointer;
  font-family: inherit;
  background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
  border: 1px solid rgba(204,136,0,.2); color: #CC8800;
  min-height: 40px; box-sizing: border-box;
  transition: all .15s;
}
.sc-nc-override-note { display: flex; flex-direction: column; gap: 4px; }
.sc-nc-override-lbl { font-family: inherit; font-size: 10px; font-weight: 700; color: #CC8800; letter-spacing: .5px; }
.sc-override-input {
  width: 100%; padding: 10px 12px; border: 1.5px solid rgba(204,136,0,.2); border-radius: 10px;
  font-size: 13px; background: #fff;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Ubuntu, 'Helvetica Neue', Arial, sans-serif;
  color: #0B1A30; resize: vertical; min-height: 56px;
  transition: all .25s;
}
.sc-override-input:focus { border-color: rgba(204,136,0,.5); box-shadow: 0 0 0 3px rgba(204,136,0,.08); }

/* ── OUTBREAK CONTEXT ─────────────────────────────────────── */
.sc-outbreak-ctx {
  background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
  border: 1.5px solid rgba(204,136,0,.12); border-radius: 14px;
  padding: 12px 16px; margin-bottom: 12px;
  display: flex; flex-direction: column; gap: 6px;
  box-shadow: 0 1px 3px rgba(0,0,0,.03);
}
.sc-oc-hdr { display: flex; align-items: center; gap: 8px; font-family: inherit; font-size: 12px; font-weight: 700; color: #CC8800; }
.sc-oc-chips { display: flex; flex-wrap: wrap; gap: 4px; }
.sc-oc-chip {
  padding: 3px 9px;
  background: rgba(204,136,0,.08);
  border: 1px solid rgba(204,136,0,.15);
  border-radius: 5px;
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 9.5px; font-weight: 500; color: #CC8800;
  text-transform: capitalize;
}
.sc-oc-chip--more { background: rgba(0,0,0,.04); color: #94A3B8; border-color: rgba(0,0,0,.08); }

/* ── IHR RISK RESULT ──────────────────────────────────────── */
.sc-ihr-result {
  padding: 12px 16px; border-radius: 14px; margin-bottom: 12px;
  display: flex; flex-direction: column; gap: 5px; border: 1.5px solid;
  box-shadow: 0 1px 3px rgba(0,0,0,.03);
}
.sc-ihr--low      { background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%); border-color: rgba(0,168,107,.12); }
.sc-ihr--medium   { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); border-color: rgba(204,136,0,.12); }
.sc-ihr--high     { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); border-color: rgba(204,136,0,.15); }
.sc-ihr--critical { background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); border-color: rgba(224,32,80,.12); }
.sc-ihr-hdr { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.sc-ihr-level {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 14px; font-weight: 600; letter-spacing: .3px;
}
.sc-ihr--low      .sc-ihr-level { color: #00A86B; }
.sc-ihr--medium   .sc-ihr-level { color: #CC8800; }
.sc-ihr--high     .sc-ihr-level { color: #CC8800; }
.sc-ihr--critical .sc-ihr-level { color: #E02050; }
.sc-ihr-routing {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 10px; font-weight: 500; color: #94A3B8;
}
.sc-ihr-alert-badge {
  padding: 3px 9px; border-radius: 5px; font-size: 9px; font-weight: 700; letter-spacing: .8px;
  background: linear-gradient(135deg, #B01840, #E02050); color: #fff;
  text-transform: uppercase;
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  box-shadow: 0 0 8px rgba(224,32,80,.35);
  animation: sc-dotPulse 1.5s ease-in-out infinite;
}
.sc-ihr-reason { font-family: inherit; font-size: 10px; color: #475569; font-weight: 600; padding-left: 8px; }

/* ── ENGINE SYNDROME HINT ─────────────────────────────────── */
.sc-syn-engine-hint, .sc-risk-engine-suggest {
  display: flex; align-items: flex-start; gap: 6px; padding: 10px 12px;
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%);
  border: 1px solid rgba(0,112,224,.12); border-radius: 10px;
  font-family: inherit;
  font-size: 11px; color: #0070E0; font-weight: 600; margin-bottom: 8px;
}
.sc-risk-apply-btn {
  margin-left: auto; padding: 5px 12px; border-radius: 8px; flex-shrink: 0;
  font-size: 10px; font-weight: 700; cursor: pointer;
  font-family: inherit;
  background: linear-gradient(135deg, #0055CC 0%, #0070E0 100%);
  color: #fff; border: none;
  box-shadow: 0 2px 8px rgba(0,112,224,.2);
  min-height: 32px;
  transition: all .15s;
}

/* ── DISEASE CARD — enhanced ──────────────────────────────── */
.sc-dc-name-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.sc-dc-syn-match {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 9px; font-weight: 500; padding: 2px 7px; border-radius: 5px;
  background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
  color: #00A86B; border: 1px solid rgba(0,168,107,.12);
}
.sc-dc-cfr {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 9px; padding: 2px 7px; border-radius: 5px;
  background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%);
  color: #E02050; font-weight: 500;
  border: 1px solid rgba(224,32,80,.1);
}
.sc-dc-def-toggle {
  font-family: inherit;
  font-size: 10px; color: #0070E0; font-weight: 700; background: none; border: none;
  cursor: pointer; padding: 4px 0; text-decoration: underline; text-align: left;
}
.sc-dc-casedefs {
  background: linear-gradient(180deg, #E4EBF7 0%, #EAF0FA 100%);
  border-radius: 8px; padding: 10px 12px; margin-top: 4px;
  display: flex; flex-direction: column; gap: 6px;
}
.sc-dc-def-row { display: flex; gap: 8px; align-items: flex-start; }
.sc-dc-def-k {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 8.5px; font-weight: 600; letter-spacing: .8px; text-transform: uppercase;
  flex-shrink: 0; width: 60px; padding-top: 1px;
}
.sc-dc-def-v { font-family: inherit; font-size: 10px; color: #475569; font-weight: 600; line-height: 1.4; }
.sc-dc-def--suspected .sc-dc-def-k { color: #CC8800; }
.sc-dc-def--confirmed  .sc-dc-def-k { color: #00A86B; }
.sc-dc-def--ihr        .sc-dc-def-k { color: #0070E0; }
.sc-dc-def--action     .sc-dc-def-k { color: #E02050; }
.sc-dc-def--action { background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); border-radius: 6px; padding: 6px 8px; }

/* ── OFFICER OVERRIDE ─────────────────────────────────────── */
.sc-override-section {}
.sc-override-hint { font-family: inherit; font-size: 11px; color: #475569; font-weight: 600; margin: 0 0 8px; line-height: 1.4; }
.sc-override-add-row { display: flex; gap: 8px; }
.sc-override-disease-select {
  flex: 1; padding: 10px 12px;
  border: 1.5px solid rgba(0,0,0,.08); border-radius: 10px;
  font-size: 14px;
  background: linear-gradient(145deg, #E8EDF7, #F0F3FA);
  color: #0B1A30; min-width: 0;
  font-family: inherit;
  min-height: 48px; box-sizing: border-box;
  transition: all .25s;
  appearance: none;
  -webkit-appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath d='M2 4l4 4 4-4' fill='none' stroke='%23475569' stroke-width='1.5'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  padding-right: 32px;
}
.sc-override-disease-select:focus { border-color: rgba(0,112,224,.35); box-shadow: 0 0 0 3px rgba(0,112,224,.08); background-color: #fff; }
.sc-override-add-btn {
  padding: 10px 16px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer;
  font-family: inherit;
  background: linear-gradient(135deg, #0055CC 0%, #0070E0 100%);
  color: #fff; border: none; flex-shrink: 0;
  box-shadow: 0 4px 16px rgba(0,112,224,.25);
  min-height: 48px; box-sizing: border-box;
  transition: all .15s;
  position: relative; overflow: hidden;
}
.sc-override-add-btn::before {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent 20%, rgba(255,255,255,.25) 50%, transparent 80%);
}
.sc-override-add-btn:disabled { opacity: .4; }
.sc-override-added { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.sc-override-tag {
  display: flex; align-items: center; gap: 4px; padding: 5px 12px;
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%);
  border: 1px solid rgba(0,112,224,.12);
  border-radius: 5px;
  font-family: inherit;
  font-size: 11px; font-weight: 700; color: #0070E0;
}
.sc-override-rm { background: none; border: none; cursor: pointer; font-size: 14px; color: #0070E0; padding: 0 2px; line-height: 1; min-width: 24px; min-height: 24px; display: flex; align-items: center; justify-content: center; }

/* ── NEW EXPOSURE UI ──────────────────────────────────────── */
.sc-exp-cat { margin-bottom: 16px; }
.sc-exp-cat-hdr {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 9.5px; font-weight: 500; letter-spacing: 1.5px; text-transform: uppercase;
  color: #94A3B8; padding: 8px 0 4px;
  border-bottom: 1px solid rgba(0,0,0,.04); margin-bottom: 8px;
}
.sc-exp-header-row { display: flex; align-items: flex-start; gap: 8px; flex-wrap: wrap; }
.sc-exp-risk {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 8.5px; font-weight: 600; padding: 2px 7px; border-radius: 5px; flex-shrink: 0; letter-spacing: .3px;
  border: 1px solid;
}
.sc-exp-risk--vhigh { background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%); color: #E02050; border-color: rgba(224,32,80,.1); }
.sc-exp-risk--high  { background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%); color: #CC8800; border-color: rgba(204,136,0,.12); }
.sc-exp-desc { font-family: inherit; font-size: 10.5px; color: #94A3B8; font-weight: 400; line-height: 1.4; margin: 2px 0 8px; }
.sc-exp-card--yes {
  border-color: rgba(224,32,80,.15);
  background: linear-gradient(135deg, #FEF2F2 0%, #FFF5F5 100%);
}
.sc-exp-card--high { border-left: 3px solid #CC8800; }
.sc-exp-critical {
  font-family: inherit;
  font-size: 10px; font-weight: 700; color: #E02050;
  background: linear-gradient(135deg, #FEF2F2 0%, #FECACA 100%);
  border-radius: 6px; padding: 6px 10px; margin-top: 6px; display: flex; gap: 6px;
}
.sc-exp-hisig {
  display: flex; gap: 10px; align-items: flex-start; padding: 14px 16px;
  background: linear-gradient(135deg, #B01840 0%, #E02050 100%);
  color: #fff; border-radius: 14px; margin-bottom: 12px;
  box-shadow: 0 4px 16px rgba(224,32,80,.2);
}
.sc-exp-hisig-title { font-family: inherit; font-size: 12px; font-weight: 700; margin-bottom: 4px; }
.sc-exp-hisig-row   { font-size: 10px; font-weight: 600; }
.sc-exp-hisig-note  { font-size: 9.5px; color: rgba(255,255,255,0.8); font-weight: 500; }


/* ─── DISEASE DETAIL MODAL ──────────────────────────────────────── */
.sc-dm-wrap {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Ubuntu, 'Helvetica Neue', Arial, sans-serif;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  text-rendering: optimizeLegibility;
  display: flex; flex-direction: column;
}
.sc-dm-hero {
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 60%, #EAF0FA 100%);
  padding: 18px 18px 14px;
  border-bottom: 1px solid rgba(0,112,224,.1);
}
.sc-dm-name {
  font-family: inherit;
  font-size: 20px; font-weight: 700; color: #0B1A30;
  line-height: 1.2; margin-bottom: 3px; letter-spacing: -.5px;
}
.sc-dm-id {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace;
  font-size: 10px; font-weight: 500; color: #94A3B8;
  text-transform: uppercase; letter-spacing: .8px; margin-bottom: 12px;
}
.sc-dm-metric-row {
  display: flex; gap: 16px; margin-bottom: 10px;
}
.sc-dm-metric {
  display: flex; flex-direction: column; gap: 2px;
}
.sc-dm-metric-val {
  font-family: inherit;
  font-size: 30px; font-weight: 800; line-height: 1;
  font-variant-numeric: tabular-nums;
}
.sc-dm-metric-lbl {
  font-family: inherit;
  font-size: 9px; font-weight: 700; color: #94A3B8;
  text-transform: uppercase; letter-spacing: 1.2px;
}
.sc-dm-mv--high { color: #E02050; }
.sc-dm-mv--med  { color: #CC8800; }
.sc-dm-mv--low  { color: #00A86B; }
.sc-dm-mv--warn { color: #E02050; }
.sc-dm-bar-track {
  height: 6px; background: rgba(0,0,0,.06); border-radius: 4px; overflow: hidden;
}
.sc-dm-bar-fill {
  height: 100%; border-radius: 4px;
  transition: width .6s cubic-bezier(.4,0,.2,1);
}
.sc-dm-bar--high { background: linear-gradient(90deg, #B01840, #E02050); }
.sc-dm-bar--med  { background: linear-gradient(90deg, #CC8800, #E6A000); }
.sc-dm-bar--low  { background: linear-gradient(90deg, #007A50, #00A86B); }

.sc-dm-band {
  font-family: inherit;
  font-size: 9px; font-weight: 700; padding: 3px 9px; border-radius: 5px;
  border: 1px solid; letter-spacing: .6px; text-transform: uppercase;
}
.sc-dm-band--very_high { background: linear-gradient(135deg, #FEF2F2, #FECACA); border-color: rgba(224,32,80,.15); color: #E02050; }
.sc-dm-band--high      { background: linear-gradient(135deg, #FEF2F2, #FECACA); border-color: rgba(224,32,80,.1); color: #E02050; }
.sc-dm-band--moderate  { background: linear-gradient(135deg, #FFFBEB, #FEF3C7); border-color: rgba(204,136,0,.15); color: #CC8800; }
.sc-dm-band--low       { background: linear-gradient(135deg, #F8FAFC, #F1F5F9); border-color: rgba(0,0,0,.06); color: #94A3B8; }

.sc-dm-section {
  padding: 12px 18px;
  border-bottom: 1px solid rgba(0,0,0,.04);
}
.sc-dm-section--who {
  background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
  border-left: 3px solid rgba(204,136,0,.25);
}
.sc-dm-section-lbl {
  font-family: inherit;
  font-size: 9px; font-weight: 700; color: #94A3B8;
  text-transform: uppercase; letter-spacing: 1.2px; margin-bottom: 6px;
}
.sc-dm-chips-row { display: flex; flex-wrap: wrap; gap: 6px; }
.sc-dm-ihr-chip {
  display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 5px;
  font-family: inherit;
  font-size: 10px; font-weight: 700; color: #0070E0;
  background: linear-gradient(135deg, #E0ECFF 0%, #CCE0FF 100%);
  border: 1px solid rgba(0,112,224,.12);
}
.sc-dm-syn-chip {
  display: inline-flex; align-items: center; gap: 4px; padding: 4px 10px; border-radius: 5px;
  font-family: inherit;
  font-size: 10px; font-weight: 700; color: #00A86B;
  background: linear-gradient(135deg, #ECFDF5 0%, #D1FAE5 100%);
  border: 1px solid rgba(0,168,107,.12);
}
.sc-dm-syn-chip svg { width: 10px; height: 10px; }
.sc-dm-hallmarks { display: flex; flex-wrap: wrap; gap: 5px; }
.sc-dm-htag {
  padding: 3px 9px; border-radius: 5px; font-size: 10px; font-weight: 700;
  font-family: inherit;
  background: linear-gradient(135deg, #F8FAFC, #F1F5F9);
  color: #475569; border: 1px solid rgba(0,0,0,.06);
}
.sc-dm-breakdown { display: flex; flex-direction: column; }
.sc-dm-bd-row {
  display: flex; justify-content: space-between; align-items: baseline;
  padding: 6px 0; border-bottom: 1px solid rgba(0,0,0,.04);
}
.sc-dm-bd-row:last-child { border-bottom: none; }
.sc-dm-bd-k { font-family: inherit; font-size: 11px; font-weight: 600; color: #475569; text-transform: capitalize; }
.sc-dm-bd-v { font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, Consolas, monospace; font-size: 12px; font-weight: 600; font-variant-numeric: tabular-nums; color: #94A3B8; }
.sc-dm-bd-pos { color: #00A86B; }
.sc-dm-bd-neg { color: #E02050; }

/* Court-defensible score-breakdown additions (2026-05-07) */
.sc-dm-section--breakdown { background: linear-gradient(180deg, #FAFCFF 0%, #F4F8FF 100%); border-radius: 10px; padding: 10px 12px; }
.sc-dm-bd-intro { font-size: 11px; color: #475569; margin: 0 0 8px; line-height: 1.45; }
.sc-dm-bd-group { margin-top: 8px; }
.sc-dm-bd-group:first-of-type { margin-top: 0; }
.sc-dm-bd-group-lbl {
  font-size: 9.5px; font-weight: 800; letter-spacing: 0.6px;
  text-transform: uppercase; color: #1D4ED8;
  padding-bottom: 4px; margin-bottom: 4px;
  border-bottom: 1px dashed rgba(29,78,216,0.20);
}
.sc-dm-bd-total {
  display: flex; justify-content: space-between; align-items: center;
  margin-top: 10px; padding-top: 10px;
  border-top: 2px solid rgba(15,23,42,0.10);
  font-size: 13px; font-weight: 800; color: #0B1A30;
}
.sc-dm-bd-total-v {
  font-family: ui-monospace, SFMono-Regular, 'SF Mono', Menlo, monospace;
  color: #1D4ED8; font-variant-numeric: tabular-nums;
}
.sc-dm-def-text {
  font-family: inherit;
  font-size: 12px; line-height: 1.65; color: #0B1A30; margin: 0;
}
.sc-dm-def-text--muted { color: #94A3B8; font-style: italic; }
.sc-dm-poe-action {
  font-family: inherit;
  font-size: 12px; font-weight: 700; color: #CC8800;
  background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
  border-radius: 10px; padding: 10px 14px;
  border: 1px solid rgba(204,136,0,.12); line-height: 1.5;
}
.sc-dm-source {
  padding: 8px 18px; font-size: 9px; color: #94A3B8;
  font-family: inherit;
  font-style: italic; border-top: 1px solid rgba(0,0,0,.04);
}

/* ── System Recommendation banner ── */
.sc-sysrec-banner { background:#EFF6FF; border:1.5px solid #BFDBFE; border-radius:12px; padding:12px 14px; margin-bottom:12px; }
.sc-sysrec-hdr { display:flex; align-items:center; gap:7px; margin-bottom:10px; }
.sc-sysrec-title { font-size:12px; font-weight:700; color:#1565C0; text-transform:uppercase; letter-spacing:.05em; }
.sc-sysrec-grid { display:flex; flex-wrap:wrap; gap:8px; }
.sc-sysrec-item { background:#fff; border:1px solid #DBEAFE; border-radius:8px; padding:6px 10px; min-width:90px; }
.sc-sysrec-k { display:block; font-size:9px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#93C5FD; margin-bottom:2px; }
.sc-sysrec-v { font-size:13px; font-weight:700; color:#1E3A5F; text-transform:capitalize; }
/* 2026-05-07: secondary "PHEOC + National also informed" companion text */
.sc-sysrec-aux { font-size: 10.5px; font-weight: 500; color: #64748B; text-transform: none; margin-left: 4px; letter-spacing: 0.1px; }
.sc-ihr-routing-aux { font-size: 10.5px; font-weight: 500; opacity: 0.75; margin-left: 2px; }
.sc-risk-readonly-aux { display: block; font-size: 10.5px; font-weight: 500; color: #64748B; margin-top: 2px; line-height: 1.4; letter-spacing: 0.1px; }
.sc-sysrec-v--risk[data-risk="CRITICAL"] { color:#B91C1C; }
.sc-sysrec-v--risk[data-risk="HIGH"]     { color:#C2410C; }
.sc-sysrec-v--risk[data-risk="MEDIUM"]   { color:#B45309; }
.sc-sysrec-alert { margin-top:8px; font-size:11px; font-weight:700; color:#B91C1C; background:rgba(185,28,28,.06); border-radius:6px; padding:5px 10px; }

/* ── Country search ── */
/* Error state for SearchableSelect trigger used in nationality field */
.sc-ss--err :deep(.ss-trigger) { border-color: #dc2626 !important; }

/* ── Follow-up info ── */
.sc-followup-info { display:flex; align-items:flex-start; gap:6px; font-size:11px; color:#1565C0; background:#EFF6FF; border:1px solid #BFDBFE; border-radius:8px; padding:8px 10px; margin-top:6px; line-height:1.5; }

/* ── Infant months picker ── */
.sc-infant-popup { margin-top:8px; background:#FFF7ED; border:1.5px solid #FED7AA; border-radius:10px; padding:10px 12px; }
.sc-infant-lbl { font-size:11px; font-weight:600; color:#C2410C; display:block; margin-bottom:8px; }
.sc-infant-months { display:flex; flex-wrap:wrap; gap:6px; }
.sc-infant-mo {
  font-size:11px; font-weight:600; padding:5px 9px;
  border:1.5px solid #FED7AA; border-radius:16px;
  background:#FFF7ED; color:#92400E; cursor:pointer;
  -webkit-tap-highlight-color:transparent;
  transition:all .12s ease;
}
.sc-infant-mo--active { background:#EA580C; border-color:#EA580C; color:#fff; }

/* ── Officer Override Search (Change 17) ─────────────────────── */
.sc-override-search-wrap {
  display: flex; align-items: center; gap: 8px;
  background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 10px;
  padding: 8px 12px; margin: 10px 0 8px;
  transition: border-color .15s ease, box-shadow .15s ease;
}
.sc-override-search-wrap:focus-within { border-color: #6A1B9A; box-shadow: 0 0 0 2px rgba(106,27,154,.12); }
.sc-override-search-input {
  flex: 1; border: none; outline: none; background: transparent;
  font-size: 13px; color: #0F172A; font-family: inherit;
}
.sc-override-search-clear {
  border: none; background: rgba(0,0,0,.05); border-radius: 50%;
  width: 18px; height: 18px; cursor: pointer; color: #64748B;
  font-size: 14px; line-height: 1; padding: 0;
}
.sc-override-results {
  max-height: 280px; overflow-y: auto;
  border: 1px solid #E2E8F0; border-radius: 10px; background: #FFFFFF;
  margin-bottom: 10px;
}
.sc-override-result-row {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 10px;
  align-items: center;
  width: 100%; padding: 12px 14px;
  border: none; border-bottom: 1px solid #F1F5F9;
  background: transparent; cursor: pointer; text-align: left; font-family: inherit;
  transition: background .12s ease;
  min-height: 52px;
}
.sc-override-result-row:last-child { border-bottom: none; }
.sc-override-result-row:hover, .sc-override-result-row:focus {
  background: rgba(106,27,154,.06); outline: none;
}
.sc-override-result-row:disabled { opacity: .55; cursor: not-allowed; }
.sc-override-result-row--vhf {
  background: linear-gradient(180deg, #FEF2F2 0%, #FEE2E2 100%);
}
.sc-override-result-row--vhf:hover, .sc-override-result-row--vhf:focus {
  background: linear-gradient(180deg, #FEE2E2 0%, #FECACA 100%);
}
.sc-override-result-name {
  font-size: 14px; font-weight: 600; color: #0F172A;
  line-height: 1.3; word-break: break-word;
}
.sc-override-result-meta {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 10.5px; color: #64748B; font-weight: 600; text-transform: uppercase;
  flex-shrink: 0; white-space: nowrap;
}
.sc-override-vhf-tag {
  background: #B91C1C; color: #FFFFFF; padding: 3px 8px; border-radius: 6px;
  font-size: 10px; font-weight: 800; letter-spacing: .4px;
  display: inline-block; line-height: 1.2;
}
.sc-override-tier-tag {
  background: #F1F5F9; color: #475569; padding: 3px 8px; border-radius: 6px;
  font-size: 10px; font-weight: 700; letter-spacing: .3px;
  display: inline-block; line-height: 1.2;
}
.sc-override-evidence-tag {
  background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5;
  padding: 3px 8px; border-radius: 6px;
  font-size: 10px; font-weight: 800; letter-spacing: .4px;
  display: inline-block; line-height: 1.2; white-space: nowrap;
}
.sc-override-empty {
  text-align: center; padding: 16px; color: #94A3B8; font-size: 12px; font-style: italic;
}
.sc-override-max-msg {
  background: #FEF3C7; border: 1px solid #F59E0B; border-radius: 8px;
  padding: 10px 12px; font-size: 12px; color: #78350F; font-weight: 600;
  margin: 8px 0;
}

/* ── VHF Critical Banner (Change 18) ─────────────────────────── */
.sc-vhf-banner {
  display: flex; align-items: center; gap: 12px;
  background: linear-gradient(135deg, #B91C1C 0%, #991B1B 100%); color: #FFFFFF;
  border-radius: 10px; padding: 12px 16px; margin: 8px 0 12px;
  box-shadow: 0 4px 12px rgba(185,28,28,.3);
  animation: sc-vhf-pulse 1.4s ease-in-out infinite;
}
.sc-vhf-banner svg { flex-shrink: 0; width: 20px; height: 20px; }
.sc-vhf-banner strong { display: block; font-size: 13px; font-weight: 800; letter-spacing: .3px; margin-bottom: 2px; }
.sc-vhf-banner span { font-size: 11.5px; opacity: .95; line-height: 1.45; }
@keyframes sc-vhf-pulse {
  0%, 100% { box-shadow: 0 4px 12px rgba(185,28,28,.3); }
  50%      { box-shadow: 0 4px 20px rgba(185,28,28,.55); }
}

/* ── Override added chips ────────────────────────────────────── */
.sc-override-tag--vhf {
  background: #B91C1C !important; color: #FFFFFF !important; border-color: #991B1B !important;
}

/* ── Symptom info button + modal (Task 2) ───────────────────── */
.sc-sym-card-wrap {
  position: relative;
  display: flex; align-items: stretch;
}
.sc-sym-card-wrap .sc-sym-card { flex: 1; padding-right: 40px; }
.sc-sym-info-btn {
  position: absolute; top: 50%; right: 8px; transform: translateY(-50%);
  width: 26px; height: 26px;
  display: inline-flex; align-items: center; justify-content: center;
  background: rgba(255,255,255,.92); border: 1px solid #CBD5E1; border-radius: 999px;
  color: #475569; padding: 0; cursor: pointer; z-index: 2;
  box-shadow: 0 1px 2px rgba(0,0,0,.06);
  transition: background .15s ease, color .15s ease, border-color .15s ease;
}
.sc-sym-info-btn:hover, .sc-sym-info-btn:focus-visible {
  background: #FFFFFF; color: #0F172A; border-color: #94A3B8; outline: none;
}
.sc-sym-card--on + .sc-sym-info-btn,
.sc-sym-card-wrap .sc-sym-card--on ~ .sc-sym-info-btn {
  background: rgba(255,255,255,.92); color: #0F172A; border-color: rgba(255,255,255,.6);
}
.sc-sym-info-btn svg { width: 13px; height: 13px; }

.sc-syminfo-wrap {
  display: flex; flex-direction: column; height: 100%;
  background: #FFFFFF;
}
.sc-syminfo-hdr {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 18px; border-bottom: 1px solid #E2E8F0;
  background: #F8FAFC;
}
.sc-syminfo-title { font-size: 16px; font-weight: 700; margin: 0; color: #0F172A; }
.sc-syminfo-close {
  background: transparent; border: none; font-size: 22px; line-height: 1;
  color: #475569; cursor: pointer; padding: 4px 8px;
}
.sc-syminfo-body {
  flex: 1; overflow-y: auto; padding: 16px 18px;
  font-size: 13px; line-height: 1.55; color: #1F2937;
}
.sc-syminfo-section { margin-bottom: 14px; }
.sc-syminfo-h3 {
  font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: .5px;
  color: #475569; margin-bottom: 4px;
}
.sc-syminfo-section p { margin: 0; }
.sc-syminfo-section ul {
  margin: 0; padding-left: 18px;
}
.sc-syminfo-section li { margin-bottom: 3px; }
.sc-syminfo-source p { font-size: 11px; color: #64748B; font-style: italic; }
.sc-syminfo-fallback p { color: #64748B; font-style: italic; }
.sc-syminfo-footer {
  padding: 12px 18px; border-top: 1px solid #E2E8F0; background: #F8FAFC;
  display: flex; justify-content: flex-end;
}
.sc-syminfo-btn-close {
  background: #1F2937; color: #FFF; border: none; border-radius: 8px;
  padding: 9px 18px; font-size: 13px; font-weight: 600; cursor: pointer;
}
.sc-syminfo-btn-close:hover { background: #0F172A; }

/* ── Vital-Sign Temperature recapture (Task 1) ───────────────── */
.sc-vital-section {
  margin: 0 0 14px;
  background: linear-gradient(180deg,#FFF7ED 0%,#FFFBEB 100%);
  border: 1px solid #F59E0B; border-radius: 10px; padding: 12px 14px;
}
.sc-vital-hdr {
  display: flex; align-items: center; gap: 8px; margin-bottom: 8px;
  font-size: 12px; color: #78350F; font-weight: 700;
}
.sc-vital-num {
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; border-radius: 6px;
  background: #B45309; color: #FFF; font-size: 11px; font-weight: 800;
}
.sc-vital-title { font-size: 12.5px; }
.sc-vital-badge {
  margin-left: auto;
  font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
  background: #FEF3C7; color: #78350F; border: 1px solid #FBBF24;
  padding: 2px 7px; border-radius: 999px;
}
.sc-vital-row {
  display: flex; align-items: center; gap: 10px;
}
.sc-vital-lbl { font-size: 11.5px; color: #1F2937; font-weight: 600; min-width: 130px; }
.sc-vital-input {
  flex: 1;
  border: 1px solid #D97706; border-radius: 8px;
  padding: 8px 10px; font-size: 14px; background: #FFF;
}
.sc-vital-fever-readout {
  display: flex; align-items: center; gap: 10px;
  margin-top: 10px; padding: 8px 10px;
  border-radius: 8px; border: 1px dashed #CBD5E1;
  background: #F8FAFC; pointer-events: none;
}
.sc-vital-fever-readout--on  { background: #FEE2E2; border-color: #B91C1C; color: #7F1D1D; }
.sc-vital-fever-readout--off { background: #DCFCE7; border-color: #15803D; color: #14532D; }
.sc-vital-fever-readout--unset { color: #475569; }
.sc-vital-fever-readout svg { width: 14px; height: 14px; flex-shrink: 0; }
.sc-vital-fever-body { display: flex; gap: 8px; align-items: baseline; flex: 1; }
.sc-vital-fever-label { font-size: 12px; font-weight: 700; }
.sc-vital-fever-band  { font-size: 10px; font-weight: 600; opacity: 0.7; margin-left: 4px; }
.sc-vital-fever-state { font-size: 12px; font-weight: 800; letter-spacing: .4px; text-transform: uppercase; }
.sc-vital-fever-lock {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10px; font-weight: 600; opacity: .85;
}
.sc-vital-fever-lock svg { width: 11px; height: 11px; }

/* ── Eligibility-blocked row (override picker) ───────────────── */
.sc-override-result-row--blocked {
  background: #F8FAFC; opacity: .58; cursor: not-allowed;
  filter: saturate(.4);
}
.sc-override-result-row--blocked:hover,
.sc-override-result-row--blocked:focus { background: #F1F5F9; }

/* ── Officer override score-breakdown card ───────────────────── */
.sc-override-explanation {
  margin-top: 12px;
  background: linear-gradient(180deg,#F8FAFC 0%,#F1F5F9 100%);
  border: 1px solid #CBD5E1; border-radius: 10px; padding: 12px 14px;
}
.sc-override-explanation-hdr {
  display: flex; align-items: center; justify-content: space-between;
  font-size: 12px; color: #0F172A; margin-bottom: 8px;
}
.sc-override-explanation-final {
  display: inline-flex; align-items: baseline; gap: 6px;
  font-size: 14px; font-weight: 800; color: #1E293B;
}
.sc-override-explanation-band {
  font-size: 9.5px; font-weight: 700; text-transform: uppercase;
  background: #E2E8F0; color: #475569;
  padding: 2px 7px; border-radius: 999px; letter-spacing: .4px;
}
.sc-override-explanation-list {
  margin: 0; padding-left: 18px; font-size: 11.5px; color: #334155;
  line-height: 1.55;
}
.sc-override-explanation-list li { margin-bottom: 3px; }
.sc-override-explanation-warn {
  margin-top: 8px; padding: 8px 10px;
  background: #FEF3C7; border: 1px solid #F59E0B; border-radius: 6px;
  font-size: 11px; color: #78350F; font-weight: 600;
}

/* ── Recommended Actions Advisor (Change 19) ─────────────────── */
.sc-actions-advisor {
  margin: 12px 0 16px;
  border-radius: 12px; overflow: hidden;
  border: 1px solid #E2E8F0; background: #FFFFFF;
  box-shadow: 0 1px 3px rgba(15,23,42,.06);
}
.sc-aa-hdr {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 14px; font-size: 12px; font-weight: 700;
  color: #FFFFFF;
}
.sc-aa-hdr--low { background: #475569; }
.sc-aa-hdr--alert { background: linear-gradient(90deg, #C2410C 0%, #B91C1C 100%); }
.sc-aa-title { flex: 1; letter-spacing: .3px; }
.sc-aa-pill {
  background: rgba(255,255,255,.2); padding: 3px 9px; border-radius: 5px;
  font-size: 9px; font-weight: 800; letter-spacing: .5px;
}
.sc-aa-pill--critical { background: rgba(0,0,0,.4); }
.sc-aa-body { padding: 12px 16px; }
.sc-aa-headline { font-size: 13px; color: #0F172A; margin-bottom: 8px; }
.sc-aa-list { margin: 6px 0 8px 18px; padding: 0; }
.sc-aa-list li { font-size: 12px; color: #334155; line-height: 1.55; margin-bottom: 4px; }
.sc-aa-ihr {
  background: #FEF2F2; border-left: 3px solid #B91C1C;
  padding: 8px 12px; border-radius: 0 6px 6px 0;
  font-size: 11.5px; color: #7F1D1D; line-height: 1.5; margin-top: 8px;
}
.sc-aa-who { font-size: 11px; color: #64748B; margin-top: 6px; font-weight: 500; }

/* ── Risk Read-Only (Change 22) ──────────────────────────────── */
.sc-risk-readonly {
  background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 12px;
  padding: 14px 16px; margin: 8px 0;
}
.sc-risk-readonly-row {
  display: flex; align-items: center; gap: 10px; margin-bottom: 8px;
}
.sc-risk-readonly-label {
  font-size: 11px; color: #64748B; font-weight: 600; text-transform: uppercase; letter-spacing: .5px;
  min-width: 110px;
}
.sc-risk-readonly-badge {
  padding: 4px 12px; border-radius: 6px; font-size: 12px; font-weight: 800;
  letter-spacing: .5px; text-transform: uppercase;
}
.sc-risk-readonly-badge--low      { background: #DBEAFE; color: #1E40AF; }
.sc-risk-readonly-badge--medium   { background: #FEF3C7; color: #92400E; }
.sc-risk-readonly-badge--high     { background: #FEE2E2; color: #991B1B; }
.sc-risk-readonly-badge--critical { background: #B91C1C; color: #FFFFFF; }
.sc-risk-readonly-badge--pending  { background: #E2E8F0; color: #475569; }
.sc-risk-readonly-route, .sc-risk-readonly-tier { font-size: 13px; color: #0F172A; font-weight: 600; }
.sc-risk-readonly-note {
  margin: 8px 0 0; font-size: 11px; color: #64748B; line-height: 1.5; font-style: italic;
}

/* ── Syndrome searchable list (Change 21) ────────────────────── */
.sc-syn-search-section { margin: 8px 0; }
.sc-syn-list {
  max-height: 320px; overflow-y: auto;
  border: 1px solid #E2E8F0; border-radius: 10px; background: #FFFFFF;
}
.sc-syn-row {
  display: block; width: 100%; padding: 10px 12px;
  border: none; border-bottom: 1px solid #F1F5F9; background: transparent;
  text-align: left; cursor: pointer; font-family: inherit;
  transition: background .12s ease;
}
.sc-syn-row:last-child { border-bottom: none; }
.sc-syn-row:hover, .sc-syn-row:focus { background: rgba(21,101,192,.06); outline: none; }
.sc-syn-row--danger { background: #FEF2F2; }
.sc-syn-row--danger:hover, .sc-syn-row--danger:focus { background: #FEE2E2; }
.sc-syn-row-name { display: block; font-size: 13px; font-weight: 700; color: #0F172A; }
.sc-syn-row-desc { display: block; font-size: 11px; color: #64748B; margin-top: 2px; line-height: 1.45; }
.sc-syn-selected {
  display: flex; align-items: center; gap: 12px;
  background: #ECFCCB; border: 1px solid #84CC16; border-radius: 10px;
  padding: 10px 14px; margin-bottom: 8px;
}
.sc-syn-selected strong { flex: 1; font-size: 13px; color: #166534; }
.sc-syn-clear-btn {
  border: 1px solid #84CC16; background: #FFFFFF; color: #166534;
  padding: 4px 12px; border-radius: 6px; font-size: 11px; font-weight: 700; cursor: pointer;
}

/* ── Action button label sub-text ────────────────────────────── */
.sc-action-lbl-wrap { display: flex; flex-direction: column; align-items: flex-start; flex: 1; min-width: 0; }
.sc-action-lbl-main { font-weight: 700; }
.sc-action-lbl-sub { font-size: 9px; color: #64748B; font-weight: 500; line-height: 1.2; margin-top: 2px; white-space: normal; }
.sc-action-btn--active .sc-action-lbl-sub { color: rgba(255,255,255,.7); }

/* ── Auto-fill hints (Change 1, Change 3) ────────────────────── */
.sc-auto-district-hint {
  display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap;
  font-size: 11px; color: #1565C0; font-weight: 500;
  margin-top: 6px; font-style: italic;
}
.sc-auto-district-change {
  display: inline-flex; align-items: center; gap: 4px;
  border: 1px solid #93C5FD; background: #EFF6FF; color: #1D4ED8;
  font-style: normal; font-weight: 700; font-size: 11px;
  padding: 4px 10px; border-radius: 6px; cursor: pointer;
  -webkit-tap-highlight-color: transparent;
}
.sc-auto-district-change:hover { background: #DBEAFE; }
.sc-auto-district-change:active { transform: scale(.97); }
.sc-age-inline-hint {
  display: inline-block; margin-top: 6px;
  font-size: 11px; color: #1565C0; font-weight: 600; font-style: italic;
}

/* ── Language toggle (Change 15) ─────────────────────────────── */
.sc-lang-toggle {
  display: inline-flex; align-items: center; gap: 0;
  margin-left: auto; background: rgba(255,255,255,.08);
  border: 1px solid rgba(255,255,255,.14); border-radius: 8px; padding: 2px;
}
.sc-lang-btn {
  border: none; background: transparent; color: rgba(255,255,255,.6);
  font-size: 10px; font-weight: 700; padding: 4px 8px; border-radius: 6px;
  cursor: pointer; letter-spacing: .3px; transition: background .12s, color .12s;
  font-family: inherit;
}
.sc-lang-btn--active { background: rgba(255,255,255,.18); color: #FFFFFF; }
.sc-lang-btn:hover:not(.sc-lang-btn--active) { color: rgba(255,255,255,.85); }
.sc-lang-toggle--inline {
  background: #F1F5F9; border: 1px solid #E2E8F0; margin-left: 8px;
}
.sc-lang-toggle--inline .sc-lang-btn { color: #64748B; }
.sc-lang-toggle--inline .sc-lang-btn--active { background: #1565C0; color: #FFFFFF; }
</style>