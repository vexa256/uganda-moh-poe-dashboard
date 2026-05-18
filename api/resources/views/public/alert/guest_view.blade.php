@extends('public.alert._layout', ['title' => 'Case file · ' . ($alert->alert_code ?? 'Alert')])
@section('body')
@php
    $risk = strtoupper((string) ($alert->risk_level ?? 'HIGH'));
    $pill = match ($risk) {
        'CRITICAL' => 'pill-critical',
        'HIGH'     => 'pill-high',
        'MEDIUM'   => 'pill-medium',
        'LOW'      => 'pill-low',
        default    => 'pill-medium',
    };
@endphp

<section class="panel p-6">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div class="min-w-0">
            <div class="text-[11px] text-slate-500 uppercase tracking-wider">Alert</div>
            <h1 class="text-[20px] font-bold text-slate-900 mt-1 break-words">{{ $alert->alert_code }}</h1>
            <p class="text-[14px] text-slate-700 mt-1">{{ $alert->alert_title }}</p>
        </div>
        <div class="flex flex-col items-end gap-1.5">
            <span class="pill {{ $pill }}">{{ $risk }}</span>
            <span class="text-[11px] text-slate-500 uppercase tracking-wider">Routed: <strong class="text-slate-900">{{ $alert->routed_to_level }}</strong></span>
            @if($alert->ihr_tier)
                <span class="text-[11px] text-amber-700 uppercase tracking-wider">IHR: <strong>{{ $alert->ihr_tier }}</strong></span>
            @endif
        </div>
    </div>

    <hr class="my-5 border-slate-200">

    <div class="grid grid-cols-2 gap-x-6 gap-y-4">
        <div>
            <div class="label">Status</div>
            <div class="value mt-0.5 font-semibold">{{ $alert->status }}</div>
        </div>
        <div>
            <div class="label">Owner</div>
            <div class="value mt-0.5">
                @if($owner)
                    {{ $owner->full_name }}
                    <div class="text-[11px] text-slate-500">{{ $owner->role_key }}</div>
                @else
                    <span class="text-slate-400">Unassigned</span>
                @endif
            </div>
        </div>
        <div>
            <div class="label">Point of entry</div>
            <div class="value mt-0.5">{{ $alert->poe_code ?: '—' }}</div>
        </div>
        <div>
            <div class="label">District</div>
            <div class="value mt-0.5">{{ $alert->district_code ?: '—' }}</div>
        </div>
        <div>
            <div class="label">Opened</div>
            <div class="value mt-0.5">{{ $alert->created_at }}</div>
        </div>
        <div>
            <div class="label">Acknowledged</div>
            <div class="value mt-0.5">{{ $alert->acknowledged_at ?: '—' }}</div>
        </div>
    </div>

    @if($alert->alert_details)
        <hr class="my-5 border-slate-200">
        <div class="label">Details</div>
        <p class="value mt-1 leading-relaxed whitespace-pre-line">{{ $alert->alert_details }}</p>
    @endif

    @if($screening)
        <hr class="my-5 border-slate-200">
        <div class="label mb-2">Traveler context</div>
        <div class="grid grid-cols-2 gap-x-6 gap-y-3 text-[13.5px]">
            <div>
                <span class="text-slate-500">Name:</span>
                <span class="font-semibold text-slate-900">{{ $screening->traveler_full_name ?: 'Anonymous' }}</span>
            </div>
            <div>
                <span class="text-slate-500">Gender / Age:</span>
                <span class="text-slate-900">{{ $screening->traveler_gender ?: '—' }} · {{ $screening->traveler_age_years ?? '—' }} yr</span>
            </div>
            <div>
                <span class="text-slate-500">Nationality:</span>
                <span class="text-slate-900">{{ $screening->traveler_nationality_country_code ?: '—' }}</span>
            </div>
            <div>
                <span class="text-slate-500">Conveyance:</span>
                <span class="text-slate-900">{{ $screening->conveyance_type ?: '—' }} {{ $screening->conveyance_identifier ? '· ' . $screening->conveyance_identifier : '' }}</span>
            </div>
            <div>
                <span class="text-slate-500">Syndrome:</span>
                <span class="text-slate-900">{{ $screening->syndrome_classification ?: '—' }}</span>
            </div>
            <div>
                <span class="text-slate-500">Disposition:</span>
                <span class="text-slate-900">{{ $screening->final_disposition ?: '—' }}</span>
            </div>
        </div>
    @endif

    <hr class="my-5 border-slate-200">

    <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-lg p-4 text-[12.5px] leading-relaxed">
        <strong>Read-only guest view.</strong> You're seeing this because the alert dispatcher couldn't find a Uganda POE Sentinel account for {{ $recipient }}.
        To take action — acknowledge, comment, or close — request login credentials from your Uganda POE Sentinel administrator.
        This link cannot be re-used; opening it again will return a "link consumed" page.
    </div>

    <div class="text-[11px] text-slate-500 mt-4">
        Consumed at: {{ $consumed_at ?: now()->format('Y-m-d H:i:s') }} ·
        Recipient on file: {{ $recipient }}
    </div>
</section>
@endsection
