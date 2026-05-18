{{--
    Reusable Related-Views footer for V2 drill modals.

    Usage:
        @include('admin.reports.v2._related_views', ['type' => 'alert'])
        @include('admin.reports.v2._related_views', ['type' => 'poe'])
        @include('admin.reports.v2._related_views', ['type' => 'officer'])
        @include('admin.reports.v2._related_views', ['type' => 'country'])

    The links bind via Alpine to the current drill.data object. They open
    in a new tab and pre-seed the destination report with a query-string
    filter (?alert_id=… / ?poe=… / ?user_id=… / ?country=…). Destination
    reports that don't yet read URL params still receive the user on the
    correct report — they can then filter manually.
--}}
@php
    $type = $type ?? 'alert';
@endphp

<style>
    .rpt-related-link {
        display: inline-flex; align-items: center; justify-content: space-between;
        gap: .5rem;
        padding: .55rem .75rem;
        border: 1px solid hsl(var(--border));
        border-radius: .5rem;
        background: hsl(var(--background));
        color: hsl(var(--foreground));
        font-size: 12.5px;
        font-weight: 500;
        text-decoration: none;
        transition: background .15s ease, border-color .15s ease;
    }
    .rpt-related-link:hover {
        background: hsl(var(--accent));
        border-color: hsl(var(--brand) / .5);
    }
    .rpt-related-link svg { color: hsl(var(--muted-foreground)); flex-shrink: 0; }
    .rpt-related-link:hover svg { color: hsl(var(--brand)); }
</style>

<details class="rpt-section" open>
    <summary class="rpt-section-head" style="display:flex;align-items:center;justify-content:space-between;cursor:pointer;padding:.75rem 1rem;border-top:1px solid hsl(var(--border));background:hsl(var(--muted) / .25);">
        <span style="font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:hsl(var(--muted-foreground));">Related Views</span>
        <svg class="h-4 w-4" style="color:hsl(var(--muted-foreground));" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
    </summary>
    <div class="rpt-section-body" style="padding:.75rem 1rem;display:grid;gap:.5rem;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));">

        @if ($type === 'alert')
            {{-- Alert-centric: 5 sibling-report jumps + full case file --}}
            <template x-if="drill.data?.alert?.id">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-case-files') }}/' + drill.data.alert.id"
                   target="_blank" rel="noopener">
                    <span>📄 Full Case File</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
            <template x-if="drill.data?.alert?.id">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-alert-intel') }}?alert_id=' + drill.data.alert.id"
                   target="_blank" rel="noopener">
                    <span>🔍 Alert Intelligence</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
            <template x-if="drill.data?.alert?.id">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-response-time') }}?alert_id=' + drill.data.alert.id"
                   target="_blank" rel="noopener">
                    <span>⏱️ Response Timing</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
            <template x-if="drill.data?.alert?.id">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-resolution-db') }}?alert_id=' + drill.data.alert.id"
                   target="_blank" rel="noopener">
                    <span>✅ Resolution Record</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
            <template x-if="drill.data?.alert?.poe_code">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-poe-performance') }}?poe=' + encodeURIComponent(drill.data.alert.poe_code)"
                   target="_blank" rel="noopener">
                    <span>📍 POE Performance</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
            <template x-if="drill.data?.alert?.poe_code">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-ops-risk') }}?poe=' + encodeURIComponent(drill.data.alert.poe_code)"
                   target="_blank" rel="noopener">
                    <span>⚠️ Operational Risk</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
        @endif

        @if ($type === 'poe')
            {{-- POE-centric (POE Perf, Screening Overview, Gender, Ops Risk for POE rows) --}}
            <template x-if="drill.data?.poe?.code || drill.data?.row?.poe_code || drill.data?.poe_code">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-poe-performance') }}?poe=' + encodeURIComponent(drill.data?.poe?.code || drill.data?.row?.poe_code || drill.data?.poe_code || '')"
                   target="_blank" rel="noopener">
                    <span>📈 POE Performance</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
            <template x-if="drill.data?.poe?.code || drill.data?.row?.poe_code || drill.data?.poe_code">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-screening-overview') }}?poe=' + encodeURIComponent(drill.data?.poe?.code || drill.data?.row?.poe_code || drill.data?.poe_code || '')"
                   target="_blank" rel="noopener">
                    <span>🧪 Screening Overview</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
            <template x-if="drill.data?.poe?.code || drill.data?.row?.poe_code || drill.data?.poe_code">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-ops-risk') }}?poe=' + encodeURIComponent(drill.data?.poe?.code || drill.data?.row?.poe_code || drill.data?.poe_code || '')"
                   target="_blank" rel="noopener">
                    <span>⚠️ Operational Risk</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
            <template x-if="drill.data?.poe?.code || drill.data?.row?.poe_code || drill.data?.poe_code">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-case-files') }}?poe=' + encodeURIComponent(drill.data?.poe?.code || drill.data?.row?.poe_code || drill.data?.poe_code || '')"
                   target="_blank" rel="noopener">
                    <span>📄 Case Files at this POE</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
            <template x-if="drill.data?.poe?.code || drill.data?.row?.poe_code || drill.data?.poe_code">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-alert-intel') }}?poe=' + encodeURIComponent(drill.data?.poe?.code || drill.data?.row?.poe_code || drill.data?.poe_code || '')"
                   target="_blank" rel="noopener">
                    <span>🚨 Alerts at this POE</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
        @endif

        @if ($type === 'officer')
            {{-- User Activity drill — show this officer's responses + resolutions --}}
            <template x-if="drill.data?.officer?.id || drill.data?.user_id">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-resolution-db') }}?user_id=' + (drill.data?.officer?.id || drill.data?.user_id)"
                   target="_blank" rel="noopener">
                    <span>✅ Resolutions by this officer</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
            <template x-if="drill.data?.officer?.id || drill.data?.user_id">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-response-time') }}?user_id=' + (drill.data?.officer?.id || drill.data?.user_id)"
                   target="_blank" rel="noopener">
                    <span>⏱️ Response timing</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
            <template x-if="drill.data?.officer?.poe_code">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-poe-performance') }}?poe=' + encodeURIComponent(drill.data.officer.poe_code)"
                   target="_blank" rel="noopener">
                    <span>📍 Their POE's performance</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
        @endif

        @if ($type === 'country')
            {{-- Country & Travel drill --}}
            <template x-if="drill.data?.country?.code || drill.data?.row?.country_code">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-case-files') }}?country=' + encodeURIComponent(drill.data?.country?.code || drill.data?.row?.country_code || '')"
                   target="_blank" rel="noopener">
                    <span>📄 Case files from this country</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
            <template x-if="drill.data?.country?.code || drill.data?.row?.country_code">
                <a class="rpt-related-link"
                   :href="'{{ url('/admin/reports/rpt-alert-intel') }}?country=' + encodeURIComponent(drill.data?.country?.code || drill.data?.row?.country_code || '')"
                   target="_blank" rel="noopener">
                    <span>🚨 Alerts from this country</span>
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </a>
            </template>
        @endif

    </div>
</details>
