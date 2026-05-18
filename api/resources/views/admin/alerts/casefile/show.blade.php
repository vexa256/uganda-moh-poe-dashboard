@extends('admin.layout')

@section('crumb', 'Case file')
@section('title', $alert['human']['traveller_name'] ?? 'Case file')

@section('content')
<div
    x-data="caseFile({
        endpoints: {
            data:     '{{ route('admin.alerts.case-file.data', ['id' => $alertId]) }}',
            wizard:   '{{ route('admin.alerts.wizard.show',     ['id' => $alertId]) }}',
        },
    })"
    x-init="boot()"
    class="w-full max-w-full overflow-x-hidden space-y-4"
>

    {{-- ───────────────────── HEADER ─────────────────────────────────────────── --}}
    <section class="rounded-xl border bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-4 sm:px-5 sm:py-5">
            <div class="flex items-center gap-2 text-[11px] uppercase tracking-wider text-slate-500 font-semibold">
                <span @class([
                    'inline-block w-2 h-2 rounded-full shrink-0',
                    'bg-red-500'   => $alert['human']['disease']['tier']['dot'] === 'red',
                    'bg-amber-500' => $alert['human']['disease']['tier']['dot'] === 'amber',
                    'bg-slate-400' => $alert['human']['disease']['tier']['dot'] === 'grey',
                ])></span>
                <span class="truncate">{{ $alert['human']['disease']['tier']['short'] }}</span>
                <span aria-hidden="true">·</span>
                <span class="truncate">{{ $alert['human']['routed_to'] }}</span>

                @if(($alert['status'] ?? '') === 'CLOSED')
                    <span aria-hidden="true">·</span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-900 text-white px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider">
                        Closed
                    </span>
                @endif
            </div>

            <h1 class="mt-1 text-base sm:text-lg font-bold leading-snug break-words">
                {{ $alert['human']['traveller_name'] }}
                <span class="font-normal text-slate-500">({{ $alert['human']['classification'] }})</span>
            </h1>

            <p class="mt-1 text-sm text-slate-600 break-words">
                At <strong class="text-slate-900">{{ $alert['poe_code'] }}</strong>,
                in <strong class="text-slate-900">{{ $alert['district_code'] }}</strong>.
                Came in <strong class="text-slate-900">{{ $alert['human']['created_human'] }}</strong>.
            </p>

            <div class="mt-3 flex flex-wrap gap-2">
                @if(($alert['status'] ?? '') !== 'CLOSED')
                    <a href="{{ route('admin.alerts.wizard.show', ['id' => $alertId]) }}"
                       class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-[13px] font-medium text-white hover:bg-blue-700 transition">
                        Walk me through closing this case
                    </a>
                @else
                    <button type="button" @click="open = 'CLOSURE'"
                            class="inline-flex items-center gap-1.5 rounded-md bg-slate-900 px-3 py-1.5 text-[13px] font-medium text-white hover:bg-slate-800 transition">
                        See what was done to close this case
                    </button>
                @endif
                <a href="{{ route('admin.alerts.index') }}"
                   class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-[13px] font-medium text-slate-700 hover:bg-slate-50 transition">
                    Back to alerts
                </a>
            </div>
        </div>
    </section>

    {{-- ───────────────────── CLOSED CASE BANNER ─────────────────────────────── --}}
    <template x-if="data.closure?.is_closed">
        <section class="rounded-xl border-2 border-slate-900 bg-slate-50 shadow-sm overflow-hidden">
            <div class="px-4 py-4 sm:px-5 sm:py-5 space-y-3 min-w-0">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="inline-flex items-center gap-1 rounded-full bg-slate-900 text-white px-2 py-0.5 text-[10.5px] font-bold uppercase tracking-wider shrink-0">Closed</span>
                    <p class="text-sm font-semibold text-slate-900 break-words" x-text="`Closed ${data.closure.closed_at_human || ''} by ${data.closure.closed_by_name || 'an admin'}`"></p>
                </div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <div class="min-w-0">
                        <dt class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Reason</dt>
                        <dd class="text-slate-900 break-words" x-text="data.closure.close_category?.label || '—'"></dd>
                    </div>
                    <div class="min-w-0" x-show="data.closure.closed_by_role">
                        <dt class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Closed by role</dt>
                        <dd class="text-slate-900 break-words" x-text="data.closure.closed_by_role"></dd>
                    </div>
                    <div class="sm:col-span-2 min-w-0" x-show="data.closure.close_note">
                        <dt class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Closing note</dt>
                        <dd class="text-slate-900 break-words whitespace-pre-line" x-text="data.closure.close_note"></dd>
                    </div>
                    <div class="sm:col-span-2 min-w-0" x-show="data.closure.override_reason">
                        <dt class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Why this was closed on behalf of the team</dt>
                        <dd class="text-slate-900 break-words whitespace-pre-line" x-text="data.closure.override_reason"></dd>
                    </div>
                    <div class="min-w-0" x-show="data.closure.merged_into_alert_id">
                        <dt class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Merged into</dt>
                        <dd class="text-slate-900 break-words" x-text="`Alert #${data.closure.merged_into_alert_id}`"></dd>
                    </div>
                </dl>
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="open = 'CLOSURE'"
                            class="inline-flex items-center gap-1.5 rounded-md bg-slate-900 px-3 py-1.5 text-[13px] font-medium text-white hover:bg-slate-800 transition">
                        See every action and answer
                    </button>
                </div>
            </div>
        </section>
    </template>

    {{-- ───────────────────── PROMPT ─────────────────────────────────────────── --}}
    <section class="rounded-xl border bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-4 sm:px-5 sm:py-5 space-y-2 min-w-0">
            <p class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">A few questions</p>
            <h2 class="text-base sm:text-lg font-bold text-slate-900 leading-snug break-words">What would you like to see about this case?</h2>
            <p class="text-sm text-slate-600 break-words">Tap a box. Each one says what it covers and whether the information is on file or still missing.</p>
            <p class="text-[12px] text-slate-500 break-words" x-show="loading">Loading the case file…</p>
        </div>
    </section>

    {{-- ───────────────────── SECTION GRID ─────────────────────────────────── --}}
    <section x-show="!loading" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 min-w-0">
        <template x-for="s in sections" :key="s.code">
            <button type="button"
                    @click="openSection(s.code)"
                    class="rounded-xl border bg-white text-left transition shadow-sm hover:shadow-md hover:border-slate-300 active:scale-[0.99] min-w-0 overflow-hidden">
                <div class="p-4 sm:p-5 space-y-2 min-w-0">
                    <div class="flex items-start justify-between gap-2 min-w-0">
                        <p class="text-[13.5px] font-semibold text-slate-900 leading-snug break-words min-w-0 flex-1" x-text="s.label"></p>
                        <span :class="{
                            'inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap shrink-0': true,
                            'bg-emerald-100 text-emerald-700': s.status === 'available',
                            'bg-amber-100 text-amber-700':     s.status === 'partial',
                            'bg-slate-100 text-slate-500':     s.status === 'missing',
                        }" x-text="s.status_label"></span>
                    </div>
                    <p class="text-[12.5px] text-slate-500 break-words" x-text="s.hint"></p>
                    <p class="text-[11px] text-slate-400 tabular-nums" x-text="s.count_label"></p>
                </div>
            </button>
        </template>
    </section>

    {{-- ─────────────────────────── MODAL SHEET ──────────────────────────────── --}}
    <div x-show="open !== null" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/50"
         @keydown.escape.window="open = null">

        <div class="w-full sm:max-w-2xl bg-white shadow-2xl rounded-t-2xl sm:rounded-2xl flex flex-col max-h-[90vh] sm:max-h-[85vh] overflow-hidden"
             @click.away="open = null">

            {{-- HEADER --}}
            <header class="px-4 py-3 sm:px-5 sm:py-4 border-b border-slate-200 flex items-start justify-between gap-3 shrink-0 bg-white">
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] uppercase tracking-wider text-slate-500 font-bold">Selected</p>
                    <h3 class="text-base sm:text-lg font-bold text-slate-900 leading-snug break-words" x-text="currentSection.label"></h3>
                    <p class="mt-0.5 text-[12.5px] text-slate-500 break-words" x-text="currentSection.hint"></p>
                </div>
                <button type="button"
                        class="inline-flex items-center justify-center w-9 h-9 rounded-full text-slate-500 hover:bg-slate-100 shrink-0"
                        @click="open = null" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </header>

            {{-- BODY (scrolls inside the modal) --}}
            <div class="overflow-y-auto p-4 sm:p-5 min-w-0">

                {{-- CLOSURE SUMMARY (WHO outcome panel intentionally NOT rendered;
                     it is captured server-side for analytics and the closure
                     summary email, but never surfaced as a verdict in the UI). --}}
                <template x-if="open === 'CLOSURE'">
                    <div class="space-y-4 min-w-0">
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <div class="min-w-0">
                                <dt class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Reason</dt>
                                <dd class="text-slate-900 break-words" x-text="data.closure?.close_category?.label || '—'"></dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Closed</dt>
                                <dd class="text-slate-900 break-words" x-text="data.closure?.closed_at_human || ''"></dd>
                            </div>
                            <div class="min-w-0">
                                <dt class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Closed by</dt>
                                <dd class="text-slate-900 break-words" x-text="(data.closure?.closed_by_name || '') + (data.closure?.closed_by_role ? ' · ' + data.closure.closed_by_role : '')"></dd>
                            </div>
                            <div class="sm:col-span-2 min-w-0" x-show="data.closure?.close_note">
                                <dt class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Closing note</dt>
                                <dd class="text-slate-900 break-words whitespace-pre-line" x-text="data.closure?.close_note"></dd>
                            </div>
                            <div class="sm:col-span-2 min-w-0" x-show="data.closure?.override_reason">
                                <dt class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Override reason</dt>
                                <dd class="text-slate-900 break-words whitespace-pre-line" x-text="data.closure?.override_reason"></dd>
                            </div>
                        </dl>

                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div class="rounded-md bg-emerald-50 px-3 py-2">
                                <p class="text-lg font-bold text-emerald-700 tabular-nums" x-text="data.closure?.followup_summary?.completed_count ?? 0"></p>
                                <p class="text-[10.5px] uppercase tracking-wider text-emerald-700 font-semibold">Done</p>
                            </div>
                            <div class="rounded-md bg-slate-100 px-3 py-2">
                                <p class="text-lg font-bold text-slate-600 tabular-nums" x-text="data.closure?.followup_summary?.not_applicable_count ?? 0"></p>
                                <p class="text-[10.5px] uppercase tracking-wider text-slate-600 font-semibold">Skipped</p>
                            </div>
                            <div class="rounded-md bg-blue-50 px-3 py-2">
                                <p class="text-lg font-bold text-blue-700 tabular-nums" x-text="data.closure?.followup_summary?.total_count ?? 0"></p>
                                <p class="text-[10.5px] uppercase tracking-wider text-blue-700 font-semibold">Total steps</p>
                            </div>
                        </div>

                        <div>
                            <p class="text-[11px] uppercase tracking-wider text-slate-500 font-bold mb-2">Every step and answer</p>
                            <ol class="space-y-2">
                                <template x-for="(d, i) in (data.closure?.wizard_decisions || [])" :key="i">
                                    <li class="rounded-md border border-slate-200 bg-white px-3 py-2 min-w-0">
                                        <p class="text-[12px] text-slate-500 break-words" x-text="d.when_human || d.when || ''"></p>
                                        <p class="text-sm font-medium text-slate-900 break-words" x-text="d.step_title"></p>
                                        <p class="text-[12.5px] text-slate-700 break-words" x-text="d.option_label"></p>
                                        <p class="mt-1 text-[12px] text-slate-500 break-words italic" x-show="d.reason" x-text="`Reason: ${d.reason}`"></p>
                                        <p class="text-[12px] text-slate-500 break-words" x-show="d.note" x-text="`Note: ${d.note}`"></p>
                                        <p class="text-[12px] text-slate-500 break-words" x-show="d.evidence" x-text="`Evidence: ${d.evidence}`"></p>
                                    </li>
                                </template>
                                <li class="text-[12.5px] italic text-slate-400" x-show="(data.closure?.wizard_decisions || []).length === 0">No wizard decisions were recorded for this case.</li>
                            </ol>
                        </div>
                    </div>
                </template>

                {{-- PATIENT --}}
                <template x-if="open === 'PATIENT'">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <template x-for="row in [
                            { label: 'Full name',          value: data.screening?.traveler_full_name },
                            { label: 'Initials only',      value: data.screening?.traveler_initials },
                            { label: 'Anonymous code',     value: data.screening?.traveler_anonymous_code },
                            { label: 'Age',                value: data.screening?.traveler_age_years ? data.screening.traveler_age_years + ' years' : null },
                            { label: 'Date of birth',      value: data.screening?.traveler_dob },
                            { label: 'Gender',             value: data.screening?.traveler_gender },
                            { label: 'Nationality',        value: data.screening?.traveler_nationality_country_code },
                            { label: 'Country of residence', value: data.screening?.residence_country_code },
                            { label: 'Occupation',         value: data.screening?.traveler_occupation },
                            { label: 'Where they live',    value: data.screening?.residence_address_text },
                            { label: 'Phone',              value: data.screening?.phone_number },
                            { label: 'Other phone',        value: data.screening?.alternative_phone },
                            { label: 'Email',              value: data.screening?.email },
                            { label: 'Travel document',    value: joinNonEmpty([data.screening?.travel_document_type, data.screening?.travel_document_number]) },
                            { label: 'Going to (district)',value: data.screening?.destination_district_code },
                            { label: 'Going to (address)', value: data.screening?.destination_address_text },
                            { label: 'Emergency contact',  value: joinNonEmpty([data.screening?.emergency_contact_name, data.screening?.emergency_contact_phone]) },
                        ]" :key="row.label">
                            <div class="border-b border-slate-100 py-1.5 min-w-0">
                                <dt class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold" x-text="row.label"></dt>
                                <dd class="text-[14px] text-slate-900 break-words" x-text="row.value || ''"></dd>
                                <dd class="text-[12px] text-slate-400 italic" x-show="!row.value">Not yet recorded</dd>
                            </div>
                        </template>
                    </dl>
                </template>

                {{-- TRAVEL --}}
                <template x-if="open === 'TRAVEL'">
                    <div class="space-y-4 min-w-0">
                        <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2 text-sm">
                            <template x-for="row in [
                                { label: 'Direction of travel', value: data.screening?.travel_direction },
                                { label: 'Reason for travelling', value: data.screening?.purpose_of_travel },
                                { label: 'Planned length of stay', value: data.screening?.planned_length_of_stay_days ? data.screening.planned_length_of_stay_days + ' day(s)' : (data.screening?.length_of_stay || null) },
                                { label: 'Vehicle type',        value: data.screening?.conveyance_type },
                                { label: 'Vehicle ID (flight, vessel, plate)', value: data.screening?.conveyance_identifier || data.screening?.conveyance_id },
                                { label: 'Seat number',         value: data.screening?.seat_number },
                                { label: 'Where they boarded',  value: data.screening?.embarkation_port_city || data.screening?.embarkation_port },
                                { label: 'Where the journey began', value: data.screening?.journey_start_country_code },
                                { label: 'Arrived at the border',value: data.screening?.arrival_datetime || data.screening?.arrived_at },
                                { label: 'Departed (if any)',   value: data.screening?.departure_datetime },
                            ]" :key="row.label">
                                <div class="border-b border-slate-100 py-1.5 min-w-0">
                                    <dt class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold" x-text="row.label"></dt>
                                    <dd class="text-[14px] text-slate-900 break-words" x-text="row.value || ''"></dd>
                                    <dd class="text-[12px] text-slate-400 italic" x-show="!row.value">Not yet recorded</dd>
                                </div>
                            </template>
                        </dl>
                        <div>
                            <p class="text-[11px] uppercase tracking-wider text-slate-500 font-bold mb-1.5">Countries traversed</p>
                            <ul class="flex flex-wrap gap-1.5">
                                <template x-for="t in (data.travel_countries || [])" :key="(t.country_code || '') + '-' + (t.travel_role || '') + '-' + (t.id || '')">
                                    <li class="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-0.5 text-[12px] font-medium text-slate-700">
                                        <span x-text="t.country_code"></span>
                                        <span class="text-slate-400" x-show="t.travel_role" x-text="`· ${t.travel_role}`"></span>
                                    </li>
                                </template>
                                <li class="text-[12.5px] italic text-slate-400" x-show="(data.travel_countries || []).length === 0">No country list yet.</li>
                            </ul>
                        </div>
                    </div>
                </template>

                {{-- CLINICAL --}}
                <template x-if="open === 'CLINICAL'">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <template x-for="row in [
                            { label: 'Triage category',    value: data.screening?.triage_category, hint: 'How urgent the screener thought this case was.' },
                            { label: 'General appearance', value: data.screening?.general_appearance, hint: 'How the patient looked at first sight.' },
                            { label: 'Emergency signs',    value: data.screening?.emergency_signs_present == 1 ? 'Yes — present' : (data.screening?.emergency_signs_present === 0 || data.screening?.emergency_signs_present === '0' ? 'No — none seen' : null), hint: 'Was anything urgent obvious?' },
                            { label: 'Syndrome',           value: data.screening?.syndrome_classification, hint: 'A grouping of symptoms that points at a kind of illness.' },
                            { label: 'Risk classification',value: data.screening?.risk_level, hint: 'How serious the case looks overall.' },
                            { label: 'Temperature',        value: data.screening?.temperature_value ? data.screening.temperature_value + ' ' + (data.screening.temperature_unit || 'C') : null, hint: 'Body temperature.' },
                            { label: 'Heart rate',         value: data.screening?.pulse_rate ? data.screening.pulse_rate + ' beats per minute' : null, hint: 'How fast the heart is beating.' },
                            { label: 'Breathing rate',     value: data.screening?.respiratory_rate ? data.screening.respiratory_rate + ' breaths per minute' : null, hint: 'How fast the patient is breathing.' },
                            { label: 'Oxygen in blood',    value: data.screening?.oxygen_saturation ? data.screening.oxygen_saturation + '%' : null, hint: 'How much oxygen is in their blood.' },
                            { label: 'Blood pressure',     value: data.screening?.bp_systolic ? (data.screening.bp_systolic + '/' + (data.screening.bp_diastolic || '?') + ' mmHg') : null, hint: 'The pressure in their blood vessels.' },
                            { label: 'Officer notes',      value: data.screening?.officer_notes, hint: 'What the officer wrote down.' },
                            { label: 'Final disposition',  value: data.screening?.final_disposition, hint: 'How the officer decided to handle the case.' },
                            { label: 'Disposition details',value: data.screening?.disposition_details, hint: 'Extra detail on the decision.' },
                            { label: 'Outcome',            value: data.screening?.screening_outcome, hint: 'How the screening ended.' },
                            { label: 'Follow-up needed?',  value: data.screening?.followup_required == 1 ? ('Yes' + (data.screening?.followup_assigned_level ? ' — ' + data.screening.followup_assigned_level : '')) : (data.screening?.followup_required === 0 || data.screening?.followup_required === '0' ? 'No' : null), hint: 'Does someone need to follow up?' },
                        ]" :key="row.label">
                            <div class="border-b border-slate-100 py-1.5 min-w-0">
                                <dt class="text-[11px] uppercase tracking-wider text-slate-500 font-semibold" x-text="row.label"></dt>
                                <dd class="text-[14px] text-slate-900 break-words whitespace-pre-line" x-text="row.value || ''"></dd>
                                <dd class="text-[12px] text-slate-400 italic" x-show="!row.value">Not yet recorded</dd>
                                <dd class="text-[11px] text-slate-400 break-words" x-show="row.hint && row.value" x-text="row.hint"></dd>
                            </div>
                        </template>
                    </dl>
                </template>

                {{-- SYMPTOMS --}}
                <template x-if="open === 'SYMPTOMS'">
                    <ul class="divide-y divide-slate-100">
                        <template x-for="s in (data.symptoms || [])" :key="s.id">
                            <li class="py-2.5 min-w-0">
                                <p class="text-sm font-medium text-slate-900 break-words" x-text="s.symptom_label || s.symptom_code"></p>
                                <p class="text-[12px] text-slate-500 break-words" x-text="s.is_present == 1 ? 'Recorded as present' : (s.is_present === 0 || s.is_present === '0' ? 'Recorded as absent' : '')"></p>
                                <p class="text-[12px] text-slate-500 break-words" x-text="s.onset_date ? ('Started: ' + s.onset_date) : ''"></p>
                                <p class="text-[12px] text-slate-500 break-words" x-text="s.details" x-show="s.details"></p>
                            </li>
                        </template>
                        <li class="py-4 text-[13px] italic text-slate-400" x-show="(data.symptoms || []).length === 0">No symptoms recorded yet. They are typically captured during the secondary screening.</li>
                    </ul>
                </template>

                {{-- EXPOSURES --}}
                <template x-if="open === 'EXPOSURES'">
                    <ul class="divide-y divide-slate-100">
                        <template x-for="e in (data.exposures || [])" :key="e.id">
                            <li class="py-2.5 min-w-0">
                                <p class="text-sm font-medium text-slate-900 break-words" x-text="e.exposure_label || e.exposure_code"></p>
                                <p class="text-[12px] text-slate-500 break-words" x-text="e.response ? ('Answer: ' + e.response) : ''"></p>
                                <p class="text-[12px] text-slate-500 break-words" x-text="e.details" x-show="e.details"></p>
                            </li>
                        </template>
                        <li class="py-4 text-[13px] italic text-slate-400" x-show="(data.exposures || []).length === 0">No exposures recorded yet. The screener notes these when relevant.</li>
                    </ul>
                </template>

                {{-- SUSPECTED --}}
                <template x-if="open === 'SUSPECTED'">
                    <ul class="divide-y divide-slate-100">
                        <template x-for="d in (data.suspected_diseases || [])" :key="d.id">
                            <li class="py-2.5 min-w-0">
                                <div class="flex items-start gap-2 flex-wrap min-w-0">
                                    <p class="text-sm font-semibold text-slate-900 break-words min-w-0 flex-1" x-text="d.headline || d.name || d.disease_code"></p>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap shrink-0"
                                          :class="{
                                              'bg-rose-100 text-rose-700':   d.tier?.dot === 'red',
                                              'bg-amber-100 text-amber-700': d.tier?.dot === 'amber',
                                              'bg-slate-100 text-slate-500': d.tier?.dot === 'grey' || !d.tier,
                                          }" x-text="d.tier?.short || ''"></span>
                                </div>
                                <p class="text-[12px] text-slate-500 break-words" x-text="d.confidence ? ('Confidence: ' + (d.confidence > 1 ? d.confidence : Math.round(d.confidence * 100)) + '%') : ''"></p>
                                <p class="text-[12px] text-slate-500 break-words" x-text="d.reasoning" x-show="d.reasoning"></p>
                            </li>
                        </template>
                        <li class="py-4 text-[13px] italic text-slate-400" x-show="(data.suspected_diseases || []).length === 0">No suspected illnesses listed yet. The screening will normally pick the top 1–3 matches.</li>
                    </ul>
                </template>

                {{-- SAMPLES --}}
                <template x-if="open === 'SAMPLES'">
                    <ul class="divide-y divide-slate-100">
                        <template x-for="s in (data.samples || [])" :key="s.id">
                            <li class="py-2.5 min-w-0">
                                <p class="text-sm font-medium text-slate-900 break-words" x-text="(s.sample_type || s.specimen_type || 'Sample') + (s.sample_identifier ? ' · ' + s.sample_identifier : '')"></p>
                                <p class="text-[12px] text-slate-500 break-words" x-text="s.lab_destination ? ('Sent to: ' + s.lab_destination) : ''"></p>
                                <p class="text-[12px] text-slate-500 break-words" x-text="s.collected_at ? ('Collected: ' + s.collected_at) : ''"></p>
                                <p class="text-[12px] text-slate-500 break-words" x-text="s.transport_status ? ('Status: ' + s.transport_status) : ''"></p>
                            </li>
                        </template>
                        <li class="py-4 text-[13px] italic text-slate-400" x-show="(data.samples || []).length === 0">No samples recorded yet. Samples are added once they are collected and sent.</li>
                    </ul>
                </template>

                {{-- STEPS — grouped: Still to do · Done · Skipped --}}
                <template x-if="open === 'STEPS'">
                    <div class="space-y-4 min-w-0">

                        {{-- Still to do --}}
                        <section x-show="stepsActive().length > 0" class="min-w-0">
                            <p class="text-[10.5px] uppercase tracking-[0.12em] text-amber-700 font-bold mb-2">Still to do · <span x-text="stepsActive().length"></span></p>
                            <ul class="divide-y divide-slate-100">
                                <template x-for="f in stepsActive()" :key="f.id">
                                    <li class="py-2 flex items-start gap-3 min-w-0">
                                        <span :class="{
                                            'mt-0.5 inline-flex items-center justify-center w-5 h-5 rounded-full text-[10px] font-bold flex-shrink-0': true,
                                            'bg-red-100 text-red-700':     f.human?.status_tone === 'urgent',
                                            'bg-amber-100 text-amber-700': f.human?.status_tone === 'watch',
                                        }">!</span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[13.5px] font-medium text-slate-900 break-words leading-snug" x-text="f.human?.title || f.action_label"></p>
                                            <p class="text-[11.5px] text-slate-500 break-words" x-text="f.human?.due_human || ''"></p>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </section>

                        {{-- Done --}}
                        <section x-show="stepsDone().length > 0" class="min-w-0">
                            <p class="text-[10.5px] uppercase tracking-[0.12em] text-emerald-700 font-bold mb-2">Already done · <span x-text="stepsDone().length"></span></p>
                            <ul class="divide-y divide-slate-100">
                                <template x-for="f in stepsDone()" :key="f.id">
                                    <li class="py-2 flex items-start gap-3 min-w-0">
                                        <span class="mt-0.5 inline-flex items-center justify-center w-5 h-5 rounded-full text-[10px] font-bold flex-shrink-0 bg-emerald-100 text-emerald-700">✓</span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[13.5px] font-medium text-slate-900 break-words leading-snug" x-text="f.human?.title || f.action_label"></p>
                                            <p class="text-[11.5px] text-emerald-700 break-words" x-text="completedLine(f)"></p>
                                            <p class="text-[11px] text-slate-500 break-words italic" x-show="f.notes" x-text="f.notes"></p>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </section>

                        {{-- Skipped --}}
                        <section x-show="stepsSkipped().length > 0" class="min-w-0">
                            <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-500 font-bold mb-2">Skipped — does not apply here · <span x-text="stepsSkipped().length"></span></p>
                            <ul class="divide-y divide-slate-100">
                                <template x-for="f in stepsSkipped()" :key="f.id">
                                    <li class="py-2 flex items-start gap-3 min-w-0">
                                        <span class="mt-0.5 inline-flex items-center justify-center w-5 h-5 rounded-full text-[10px] font-bold flex-shrink-0 bg-slate-100 text-slate-500">−</span>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[13.5px] font-medium text-slate-900 break-words leading-snug" x-text="f.human?.title || f.action_label"></p>
                                            <p class="text-[11.5px] text-slate-500 break-words italic" x-text="f.notes || 'Marked not applicable.'"></p>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </section>

                        <p class="py-4 text-[12.5px] italic text-slate-400" x-show="(data.followups || []).length === 0">No steps yet.</p>
                    </div>
                </template>

                {{-- PEOPLE --}}
                <template x-if="open === 'PEOPLE'">
                    <div class="space-y-4 min-w-0">
                        <div>
                            <p class="text-[11px] uppercase tracking-wider text-slate-500 font-bold mb-1.5">Teammates on this case</p>
                            <ul class="divide-y divide-slate-100">
                                <template x-for="c in (data.collaborators || [])" :key="c.id">
                                    <li class="py-2 text-sm min-w-0">
                                        <p class="font-medium text-slate-900 break-words" x-text="c.full_name || c.email"></p>
                                        <p class="text-[12px] text-slate-500 break-words" x-text="c.role_code || c.role_key || ''"></p>
                                    </li>
                                </template>
                                <li class="py-3 text-[13px] italic text-slate-400" x-show="(data.collaborators || []).length === 0">No teammates added yet.</li>
                            </ul>
                        </div>
                        <div>
                            <p class="text-[11px] uppercase tracking-wider text-slate-500 font-bold mb-1.5">Handovers</p>
                            <ul class="divide-y divide-slate-100">
                                <template x-for="h in (data.handoffs || [])" :key="h.id">
                                    <li class="py-2 text-sm min-w-0">
                                        <p class="text-slate-900 break-words"><span x-text="h.from_name || '—'"></span> → <span x-text="h.to_name || '—'"></span></p>
                                        <p class="text-[12px] text-slate-500 break-words" x-text="h.status"></p>
                                    </li>
                                </template>
                                <li class="py-3 text-[13px] italic text-slate-400" x-show="(data.handoffs || []).length === 0">No handovers yet.</li>
                            </ul>
                        </div>
                    </div>
                </template>

                {{-- NOTIFICATIONS --}}
                <template x-if="open === 'NOTIFICATIONS'">
                    <ul class="divide-y divide-slate-100">
                        <template x-for="d in (data.dispatch_receipt || [])" :key="d.id">
                            <li class="py-2.5 text-sm min-w-0">
                                <p class="font-medium text-slate-900 break-words" x-text="d.to_email"></p>
                                <p class="text-[12px] text-slate-500 break-words" x-text="(d.template_code || '') + ' · ' + (d.status || '')"></p>
                                <p class="text-[12px] text-slate-500 break-words" x-text="d.sent_at ? ('Sent ' + d.sent_at) : (d.failed_at ? ('Failed ' + d.failed_at) : '')"></p>
                            </li>
                        </template>
                        <li class="py-4 text-[13px] italic text-slate-400" x-show="(data.dispatch_receipt || []).length === 0">No emails sent yet.</li>
                    </ul>
                </template>

                {{-- NOTES --}}
                <template x-if="open === 'NOTES'">
                    <ul class="divide-y divide-slate-100">
                        <template x-for="c in (data.comments || [])" :key="c.id">
                            <li class="py-2.5 min-w-0">
                                <p class="text-[12px] text-slate-500 break-words" x-text="(c.author_name || 'Someone') + ' · ' + (c.created_at || '')"></p>
                                <p class="mt-0.5 text-sm text-slate-900 break-words whitespace-pre-line" x-text="c.body"></p>
                            </li>
                        </template>
                        <li class="py-4 text-[13px] italic text-slate-400" x-show="(data.comments || []).length === 0">No notes written yet.</li>
                    </ul>
                </template>

                {{-- TIMELINE --}}
                <template x-if="open === 'TIMELINE'">
                    <ol class="space-y-2">
                        <template x-for="t in (data.timeline || [])" :key="t.id">
                            <li class="rounded-md border border-slate-100 px-3 py-2 min-w-0">
                                <p class="text-[12px] text-slate-500 break-words" x-text="t.human?.when_human || t.created_at"></p>
                                <p class="mt-0.5 text-sm text-slate-900 break-words" x-text="t.summary || t.event_code"></p>
                                <p class="text-[12px] text-slate-500 break-words" x-text="t.actor_name ? ('by ' + t.actor_name) : ''"></p>
                            </li>
                        </template>
                        <li class="py-4 text-[13px] italic text-slate-400" x-show="(data.timeline || []).length === 0">Nothing has been recorded yet.</li>
                    </ol>
                </template>
            </div>

            {{-- FOOTER --}}
            <footer class="px-4 py-3 sm:px-5 sm:py-3 border-t border-slate-200 flex items-center justify-end gap-2 shrink-0 bg-white">
                <button type="button"
                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        @click="open = null">Done</button>
            </footer>

        </div>
    </div>

    {{-- TOAST --}}
    <div x-show="toast.show" x-cloak x-transition
         :class="{
            'fixed bottom-4 inset-x-4 sm:inset-x-auto sm:right-6 z-50 rounded-lg shadow-lg px-4 py-3 text-sm sm:max-w-sm break-words': true,
            'bg-rose-600 text-white':    toast.tone === 'error',
            'bg-emerald-600 text-white': toast.tone === 'ok',
         }"
         x-text="toast.message"></div>
</div>

<script>
function caseFile(opts) {
    return {
        endpoints: opts.endpoints,
        sections: [],
        data:     {},
        open:     null,
        loading:  true,
        toast:    { show: false, message: '', tone: 'ok', timer: null },

        get currentSection() {
            if (this.open === 'CLOSURE') {
                return { code: 'CLOSURE', label: 'Every step that closed this case', hint: 'Each decision the team made, in order — with reasons, notes, and proof.' };
            }
            return this.sections.find(s => s.code === this.open) || { label: '', hint: '' };
        },

        async boot() { await this.load(); },
        csrfToken() { return document.querySelector('meta[name="csrf-token"]').content; },
        showToast(message, tone='ok', ms=2500) {
            clearTimeout(this.toast.timer);
            this.toast.message = message; this.toast.tone = tone; this.toast.show = true;
            this.toast.timer = setTimeout(() => this.toast.show = false, ms);
        },

        async load() {
            this.loading = true;
            try {
                const res  = await fetch(this.endpoints.data, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrfToken() } });
                const body = await res.json().catch(() => ({}));
                if (!res.ok || body.success === false) {
                    this.showToast(body?.error?.human || body?.message || 'Could not load the case file.', 'error');
                    this.loading = false; return;
                }
                this.data     = body.data || {};
                this.sections = this.data.sections || [];
            } catch { this.showToast('Lost connection. Try again.', 'error'); }
            this.loading = false;
        },

        openSection(code) { this.open = code; },
        joinNonEmpty(parts) { return parts.filter(p => !!p).join(' '); },

        // Group followups for the STEPS panel by status tone.
        stepsActive()  { return (this.data.followups || []).filter(f => ['urgent','watch','info'].includes(f.human?.status_tone)); },
        stepsDone()    { return (this.data.followups || []).filter(f => f.human?.status_tone === 'done'); },
        stepsSkipped() { return (this.data.followups || []).filter(f => f.human?.status_tone === 'skipped'); },

        // For Done items, replace the misleading "overdue" line with a clean
        // "Marked done · X ago" using completed_at.
        completedLine(f) {
            if (!f.completed_at) return 'Marked done';
            const diff = Math.max(0, (Date.now() - Date.parse(f.completed_at)) / 1000);
            return 'Marked done · ' + this.relAgo(diff);
        },

        relAgo(seconds) {
            if (seconds < 60)    return 'just now';
            if (seconds < 3600)  { const m = Math.round(seconds / 60);   return m === 1 ? '1 minute ago' : (m + ' minutes ago'); }
            if (seconds < 86400) { const h = Math.round(seconds / 3600); return h === 1 ? '1 hour ago'   : (h + ' hours ago');   }
            const d = Math.round(seconds / 86400); return d === 1 ? '1 day ago' : (d + ' days ago');
        },
    };
}
</script>
@endsection
