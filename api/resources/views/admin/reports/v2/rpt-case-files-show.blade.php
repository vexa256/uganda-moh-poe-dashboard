{{-- Full-page Case File · replaces the cramped 460 px side-sheet ----------
     URL:  GET /admin/reports/rpt-case-files/{id}
     Data: built by CaseFileRegistryController::buildRecordDetail
     ---------------------------------------------------------------------- --}}
@extends('admin.layout')

@section('crumb', 'Case File')
@section('title', $reportTitle ?? 'Case File')

@section('content')
@php
    /** @var array $data */
    $case      = $data['case']      ?? [];
    $identity  = $data['identity']  ?? [];
    $travel    = $data['travel']    ?? [];
    $clinical  = $data['clinical']  ?? [];
    $symptoms  = $data['symptoms']  ?? [];
    $exposures = $data['exposures'] ?? [];
    $diseases  = $data['diseases']  ?? [];
    $actions   = $data['actions']   ?? [];
    $samples   = $data['samples']   ?? [];
    $alert     = $data['alert']     ?? null;
    $outcome   = $data['outcome']   ?? null;
@endphp

<style>
    .cf-section { background: hsl(var(--background)); border: 1px solid hsl(var(--border)); border-radius: 12px; overflow: hidden; }
    .cf-section-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; background: hsl(var(--muted) / .35); border-bottom: 1px solid hsl(var(--border)); }
    .cf-section-head h2 { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: hsl(var(--muted-foreground)); }
    .cf-section-body { padding: 18px; }
    .cf-grid { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
    .cf-field { min-width: 0; }
    .cf-field-label { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .12em; color: hsl(var(--muted-foreground)); margin-bottom: 2px; }
    .cf-field-value { font-size: 14px; font-weight: 500; color: hsl(var(--foreground)); overflow-wrap: anywhere; }
    .cf-field-value.empty { color: hsl(var(--muted-foreground)); font-style: italic; font-weight: 400; }
    .cf-pill { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 600; }
    .cf-pill-critical { background: hsl(0 84% 95%); color: hsl(0 70% 35%); border: 1px solid hsl(0 70% 80%); }
    .cf-pill-warning  { background: hsl(38 95% 92%); color: hsl(28 80% 30%); border: 1px solid hsl(28 80% 75%); }
    .cf-pill-success  { background: hsl(140 70% 92%); color: hsl(140 60% 25%); border: 1px solid hsl(140 60% 75%); }
    .cf-pill-info     { background: hsl(214 90% 94%); color: hsl(214 70% 32%); border: 1px solid hsl(214 70% 78%); }
    .cf-pill-muted    { background: hsl(var(--muted)); color: hsl(var(--muted-foreground)); }
    .cf-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .cf-table th { text-align: left; padding: 8px 12px; font-size: 10.5px; text-transform: uppercase; letter-spacing: .12em; color: hsl(var(--muted-foreground)); background: hsl(var(--muted) / .3); border-bottom: 1px solid hsl(var(--border)); }
    .cf-table td { padding: 8px 12px; border-top: 1px solid hsl(var(--border) / .6); }
    .cf-empty { padding: 20px; text-align: center; color: hsl(var(--muted-foreground)); font-size: 12.5px; font-style: italic; }
    .cf-redflag { background: hsl(0 84% 96%); }
    .cf-back-link { display: inline-flex; align-items: center; gap: 6px; color: hsl(var(--muted-foreground)); font-size: 13px; text-decoration: none; }
    .cf-back-link:hover { color: hsl(var(--foreground)); }
</style>

<div class="space-y-4">

    {{-- Header strip --}}
    <header class="cf-section">
        <div class="cf-section-body">
            <div class="flex items-start justify-between gap-4 flex-wrap">
                <div class="min-w-0">
                    <a href="{{ url('/admin/reports/rpt-case-files') }}" class="cf-back-link">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                        Back to Case File Registry
                    </a>
                    <h1 class="text-[20px] font-bold tracking-tight mt-1">
                        Case File · #{{ $caseId }}
                    </h1>
                    <p class="text-[12.5px] text-muted-foreground mt-0.5">
                        Opened {{ !empty($case['opened_at']) ? \Carbon\Carbon::parse($case['opened_at'])->format('d M Y · H:i') : '—' }}
                        @if (!empty($case['poe_code']))
                            · POE <span class="font-mono">{{ $case['poe_code'] }}</span>
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-2 flex-wrap">
                    @php
                        $riskClass = match (strtoupper((string) ($clinical['risk_level'] ?? ''))) {
                            'CRITICAL' => 'cf-pill-critical',
                            'HIGH'     => 'cf-pill-warning',
                            'MEDIUM'   => 'cf-pill-info',
                            'LOW'      => 'cf-pill-success',
                            default    => 'cf-pill-muted',
                        };
                        $statusClass = match (strtoupper((string) ($case['case_status'] ?? ''))) {
                            'CLOSED'  => 'cf-pill-success',
                            'OPEN', 'IN_PROGRESS' => 'cf-pill-warning',
                            default   => 'cf-pill-muted',
                        };
                    @endphp
                    @if (!empty($clinical['risk_level']))
                        <span class="cf-pill {{ $riskClass }}">Risk · {{ $clinical['risk_level'] }}</span>
                    @endif
                    @if (!empty($case['case_status']))
                        <span class="cf-pill {{ $statusClass }}">{{ $case['case_status'] }}</span>
                    @endif
                    @if (!empty($outcome['classification']))
                        <span class="cf-pill cf-pill-info">Classification · {{ $outcome['classification'] }}</span>
                    @endif
                </div>
            </div>
        </div>
    </header>

    {{-- Identity --}}
    <section class="cf-section">
        <div class="cf-section-head"><h2>Traveller Identity</h2></div>
        <div class="cf-section-body">
            <div class="cf-grid">
                <div class="cf-field">
                    <p class="cf-field-label">Name</p>
                    <p class="cf-field-value {{ empty($identity['name']) ? 'empty' : '' }}">{{ $identity['name'] ?? 'masked / not on file' }}</p>
                </div>
                <div class="cf-field">
                    <p class="cf-field-label">Document</p>
                    <p class="cf-field-value {{ empty($identity['document_number']) ? 'empty' : '' }}">
                        {{ $identity['document_type'] ?? '' }} {{ $identity['document_number'] ?? '—' }}
                    </p>
                </div>
                <div class="cf-field">
                    <p class="cf-field-label">Anonymous Code</p>
                    <p class="cf-field-value {{ empty($identity['anonymous_code']) ? 'empty' : 'font-mono' }}">{{ $identity['anonymous_code'] ?? '—' }}</p>
                </div>
                <div class="cf-field">
                    <p class="cf-field-label">Gender · Age</p>
                    <p class="cf-field-value">{{ $identity['gender'] ?? '—' }} · {{ $identity['age'] ?? '—' }} yrs</p>
                </div>
                <div class="cf-field">
                    <p class="cf-field-label">Date of Birth</p>
                    <p class="cf-field-value {{ empty($identity['dob']) ? 'empty' : '' }}">{{ $identity['dob'] ?? '—' }}</p>
                </div>
                <div class="cf-field">
                    <p class="cf-field-label">Nationality</p>
                    <p class="cf-field-value {{ empty($identity['nationality']) ? 'empty' : 'font-mono' }}">{{ $identity['nationality'] ?? '—' }}</p>
                </div>
                <div class="cf-field">
                    <p class="cf-field-label">Occupation</p>
                    <p class="cf-field-value {{ empty($identity['occupation']) ? 'empty' : '' }}">{{ $identity['occupation'] ?? '—' }}</p>
                </div>
                <div class="cf-field">
                    <p class="cf-field-label">Residence</p>
                    <p class="cf-field-value {{ empty($identity['residence_address']) ? 'empty' : '' }}">{{ $identity['residence_address'] ?? '—' }}</p>
                </div>
                <div class="cf-field">
                    <p class="cf-field-label">Phone</p>
                    <p class="cf-field-value {{ empty($identity['phone']) ? 'empty' : 'font-mono' }}">{{ $identity['phone'] ?? '—' }}</p>
                </div>
                <div class="cf-field">
                    <p class="cf-field-label">Email</p>
                    <p class="cf-field-value {{ empty($identity['email']) ? 'empty' : '' }}">{{ $identity['email'] ?? '—' }}</p>
                </div>
                <div class="cf-field">
                    <p class="cf-field-label">Destination Address</p>
                    <p class="cf-field-value {{ empty($identity['destination_address']) ? 'empty' : '' }}">{{ $identity['destination_address'] ?? '—' }}</p>
                </div>
                <div class="cf-field">
                    <p class="cf-field-label">Emergency Contact</p>
                    <p class="cf-field-value {{ empty($identity['emergency_contact_name']) ? 'empty' : '' }}">
                        {{ $identity['emergency_contact_name'] ?? '—' }}
                        @if (!empty($identity['emergency_contact_phone'])) · <span class="font-mono">{{ $identity['emergency_contact_phone'] }}</span> @endif
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- Travel --}}
    <section class="cf-section">
        <div class="cf-section-head"><h2>Travel</h2></div>
        <div class="cf-section-body space-y-4">
            <div class="cf-grid">
                <div class="cf-field"><p class="cf-field-label">Origin Country</p><p class="cf-field-value font-mono">{{ $travel['origin_country'] ?? '—' }}</p></div>
                <div class="cf-field"><p class="cf-field-label">Embarkation City</p><p class="cf-field-value">{{ $travel['embarkation_port_city'] ?? '—' }}</p></div>
                <div class="cf-field"><p class="cf-field-label">Conveyance</p><p class="cf-field-value">{{ $travel['conveyance_type'] ?? '—' }} {{ !empty($travel['conveyance_identifier']) ? '· '.$travel['conveyance_identifier'] : '' }}</p></div>
                <div class="cf-field"><p class="cf-field-label">Seat</p><p class="cf-field-value font-mono">{{ $travel['seat_number'] ?? '—' }}</p></div>
                <div class="cf-field"><p class="cf-field-label">Arrived</p><p class="cf-field-value">{{ !empty($travel['arrival_datetime']) ? \Carbon\Carbon::parse($travel['arrival_datetime'])->format('d M Y · H:i') : '—' }}</p></div>
                <div class="cf-field"><p class="cf-field-label">Departed</p><p class="cf-field-value">{{ !empty($travel['departure_datetime']) ? \Carbon\Carbon::parse($travel['departure_datetime'])->format('d M Y · H:i') : '—' }}</p></div>
                <div class="cf-field"><p class="cf-field-label">Purpose</p><p class="cf-field-value">{{ $travel['purpose_of_travel'] ?? '—' }}</p></div>
                <div class="cf-field"><p class="cf-field-label">Planned Stay</p><p class="cf-field-value">{{ !empty($travel['planned_length_of_stay']) ? $travel['planned_length_of_stay'].' days' : '—' }}</p></div>
            </div>

            @if (!empty($travel['countries']) && count((array) $travel['countries']))
                <div>
                    <p class="cf-field-label mb-2">14-day travel history</p>
                    <div class="overflow-hidden rounded-lg border border-border">
                        <table class="cf-table">
                            <thead><tr><th>Country</th><th>Role</th><th>Arrival</th><th>Departure</th></tr></thead>
                            <tbody>
                                @foreach ($travel['countries'] as $c)
                                    <tr>
                                        <td class="font-mono">{{ $c->country_code ?? '—' }}</td>
                                        <td>{{ $c->travel_role ?? '—' }}</td>
                                        <td>{{ $c->arrival_date ?? '—' }}</td>
                                        <td>{{ $c->departure_date ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </section>

    {{-- Clinical --}}
    <section class="cf-section">
        <div class="cf-section-head"><h2>Clinical Findings</h2></div>
        <div class="cf-section-body">
            <div class="cf-grid">
                <div class="cf-field"><p class="cf-field-label">General Appearance</p><p class="cf-field-value">{{ $clinical['general_appearance'] ?? '—' }}</p></div>
                <div class="cf-field"><p class="cf-field-label">Temperature</p><p class="cf-field-value">{{ $clinical['temperature_value'] ?? '—' }} {{ $clinical['temperature_unit'] ?? '' }}</p></div>
                <div class="cf-field"><p class="cf-field-label">Pulse</p><p class="cf-field-value">{{ $clinical['pulse_rate'] ?? '—' }} bpm</p></div>
                <div class="cf-field"><p class="cf-field-label">Respiration</p><p class="cf-field-value">{{ $clinical['respiratory_rate'] ?? '—' }} /min</p></div>
                <div class="cf-field"><p class="cf-field-label">Blood Pressure</p><p class="cf-field-value">{{ $clinical['bp_systolic'] ?? '—' }}/{{ $clinical['bp_diastolic'] ?? '—' }}</p></div>
                <div class="cf-field"><p class="cf-field-label">SpO₂</p><p class="cf-field-value">{{ $clinical['oxygen_saturation'] ?? '—' }}%</p></div>
                <div class="cf-field"><p class="cf-field-label">Syndrome</p><p class="cf-field-value">{{ $clinical['syndrome_classification'] ?? '—' }}</p></div>
                <div class="cf-field"><p class="cf-field-label">Triage</p><p class="cf-field-value">{{ $clinical['triage_category'] ?? '—' }}</p></div>
            </div>

            @if (!empty($clinical['officer_notes']))
                <div class="mt-4 p-3 rounded-md border border-border bg-muted/30">
                    <p class="cf-field-label">Officer Notes</p>
                    <p class="cf-field-value mt-1 whitespace-pre-wrap">{{ $clinical['officer_notes'] }}</p>
                </div>
            @endif
        </div>
    </section>

    {{-- Symptoms --}}
    <section class="cf-section">
        <div class="cf-section-head">
            <h2>Symptoms</h2>
            <span class="text-[11px] font-mono text-muted-foreground">{{ count($symptoms) }} on file</span>
        </div>
        <div class="cf-section-body">
            @if (count($symptoms) === 0)
                <p class="cf-empty">No symptoms recorded for this case.</p>
            @else
                <div class="overflow-hidden rounded-lg border border-border">
                    <table class="cf-table">
                        <thead><tr><th>Symptom</th><th>Status</th><th>Onset</th><th>Notes</th></tr></thead>
                        <tbody>
                            @foreach ($symptoms as $s)
                                <tr class="{{ ($s['is_red_flag'] ?? false) && ($s['is_present'] ?? false) ? 'cf-redflag' : '' }}">
                                    <td>
                                        <span class="font-medium">{{ $s['name'] ?? $s['code'] }}</span>
                                        @if ($s['is_red_flag'] ?? false)
                                            <span class="cf-pill cf-pill-critical ml-2">Red Flag</span>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($s['is_present'] ?? false)
                                            <span class="cf-pill cf-pill-warning">Present</span>
                                        @elseif ($s['explicit_absent'] ?? false)
                                            <span class="cf-pill cf-pill-muted">Explicitly absent</span>
                                        @else
                                            <span class="cf-pill cf-pill-muted">Not assessed</span>
                                        @endif
                                    </td>
                                    <td>{{ $s['onset_date'] ?? '—' }}</td>
                                    <td class="text-muted-foreground">{{ $s['details'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    {{-- Exposures --}}
    <section class="cf-section">
        <div class="cf-section-head">
            <h2>Exposures</h2>
            <span class="text-[11px] font-mono text-muted-foreground">{{ count($exposures) }} on file</span>
        </div>
        <div class="cf-section-body">
            @if (count($exposures) === 0)
                <p class="cf-empty">No exposure data recorded.</p>
            @else
                <div class="overflow-hidden rounded-lg border border-border">
                    <table class="cf-table">
                        <thead><tr><th>Exposure</th><th>Response</th><th>Notes</th></tr></thead>
                        <tbody>
                            @foreach ($exposures as $e)
                                <tr>
                                    <td>
                                        <span class="font-medium">{{ $e['name'] ?? $e['code'] }}</span>
                                        @if ($e['is_high_risk'] ?? false)
                                            <span class="cf-pill cf-pill-warning ml-2">High risk</span>
                                        @endif
                                    </td>
                                    <td><span class="font-mono text-[12px]">{{ $e['response'] ?? '—' }}</span></td>
                                    <td class="text-muted-foreground">{{ $e['details'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    {{-- Suspected Diseases --}}
    <section class="cf-section">
        <div class="cf-section-head">
            <h2>Suspected Diseases</h2>
            <span class="text-[11px] font-mono text-muted-foreground">{{ count($diseases) }} ranked</span>
        </div>
        <div class="cf-section-body">
            @if (count($diseases) === 0)
                <p class="cf-empty">No suspected disease ranking on file.</p>
            @else
                <div class="overflow-hidden rounded-lg border border-border">
                    <table class="cf-table">
                        <thead><tr><th>Rank</th><th>Disease</th><th>Code</th><th>Confidence</th><th>Reasoning</th></tr></thead>
                        <tbody>
                            @foreach ($diseases as $d)
                                <tr>
                                    <td class="font-mono">{{ $d['rank'] ?? '—' }}</td>
                                    <td class="font-medium">{{ $d['name'] ?? $d['code'] }}</td>
                                    <td class="font-mono text-muted-foreground">{{ $d['code'] }}</td>
                                    <td class="font-mono">{{ $d['confidence'] !== null ? number_format($d['confidence'], 2) : '—' }}</td>
                                    <td class="text-muted-foreground">{{ $d['reasoning'] ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    {{-- Actions taken --}}
    <section class="cf-section">
        <div class="cf-section-head">
            <h2>Actions Taken</h2>
            <span class="text-[11px] font-mono text-muted-foreground">{{ collect($actions)->where('is_done', 1)->count() }} of {{ count($actions) }}</span>
        </div>
        <div class="cf-section-body">
            @if (count($actions) === 0)
                <p class="cf-empty">No actions recorded.</p>
            @else
                <ul class="space-y-2">
                    @foreach ($actions as $a)
                        <li class="flex items-start gap-3 p-2 rounded-md {{ ($a->is_done ?? 0) ? 'bg-emerald-50/50' : 'bg-muted/30' }}">
                            <span class="cf-pill {{ ($a->is_done ?? 0) ? 'cf-pill-success' : 'cf-pill-muted' }} mt-0.5">
                                {{ ($a->is_done ?? 0) ? '✓ Done' : 'Pending' }}
                            </span>
                            <div class="flex-1 min-w-0">
                                <p class="text-[13px] font-medium font-mono">{{ $a->action_code ?? '—' }}</p>
                                @if (!empty($a->details))
                                    <p class="text-[12px] text-muted-foreground mt-0.5">{{ $a->details }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    {{-- Samples --}}
    <section class="cf-section">
        <div class="cf-section-head">
            <h2>Samples Collected</h2>
            <span class="text-[11px] font-mono text-muted-foreground">{{ count($samples) }} on file</span>
        </div>
        <div class="cf-section-body">
            @if (count($samples) === 0)
                <p class="cf-empty">No samples collected.</p>
            @else
                <div class="overflow-hidden rounded-lg border border-border">
                    <table class="cf-table">
                        <thead><tr><th>Type</th><th>Collected</th><th>Identifier</th><th>Lab</th><th>Collected at</th></tr></thead>
                        <tbody>
                            @foreach ($samples as $sm)
                                <tr>
                                    <td>{{ $sm->sample_type ?? '—' }}</td>
                                    <td>{{ ($sm->sample_collected ?? 0) ? 'Yes' : 'No' }}</td>
                                    <td class="font-mono">{{ $sm->sample_identifier ?? '—' }}</td>
                                    <td>{{ $sm->lab_destination ?? '—' }}</td>
                                    <td>{{ !empty($sm->collected_at) ? \Carbon\Carbon::parse($sm->collected_at)->format('d M Y H:i') : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    {{-- Linked alert --}}
    @if ($alert)
        <section class="cf-section">
            <div class="cf-section-head"><h2>Linked Alert</h2></div>
            <div class="cf-section-body">
                <div class="cf-grid">
                    <div class="cf-field"><p class="cf-field-label">Code</p><p class="cf-field-value font-mono">{{ $alert['code'] ?? '#'.($alert['id'] ?? '?') }}</p></div>
                    <div class="cf-field"><p class="cf-field-label">Risk</p><p class="cf-field-value">{{ $alert['risk_level'] ?? '—' }}</p></div>
                    <div class="cf-field"><p class="cf-field-label">Status</p><p class="cf-field-value">{{ $alert['status'] ?? '—' }}</p></div>
                    <div class="cf-field"><p class="cf-field-label">Opened</p><p class="cf-field-value">{{ !empty($alert['created_at']) ? \Carbon\Carbon::parse($alert['created_at'])->format('d M Y · H:i') : '—' }}</p></div>
                    <div class="cf-field"><p class="cf-field-label">Closed</p><p class="cf-field-value">{{ !empty($alert['closed_at']) ? \Carbon\Carbon::parse($alert['closed_at'])->format('d M Y · H:i') : 'still open' }}</p></div>
                    <div class="cf-field"><p class="cf-field-label">Closure Reason</p><p class="cf-field-value">{{ $alert['close_category'] ?? '—' }}</p></div>
                    <div class="cf-field"><p class="cf-field-label">Re-opens</p><p class="cf-field-value font-mono">{{ $alert['reopen_count'] ?? 0 }}</p></div>
                </div>
                @if (!empty($alert['title']))
                    <div class="mt-3 p-3 rounded-md border border-border bg-muted/30">
                        <p class="cf-field-label">Alert title</p>
                        <p class="cf-field-value mt-1">{{ $alert['title'] }}</p>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- Outcome / Classification --}}
    @if ($outcome)
        <section class="cf-section">
            <div class="cf-section-head"><h2>Case Outcome</h2></div>
            <div class="cf-section-body">
                <div class="cf-grid">
                    <div class="cf-field"><p class="cf-field-label">Classification</p><p class="cf-field-value">{{ $outcome['classification'] ?? '—' }}</p></div>
                    <div class="cf-field"><p class="cf-field-label">Lab Status</p><p class="cf-field-value">{{ $outcome['lab_status'] ?? '—' }}</p></div>
                    <div class="cf-field"><p class="cf-field-label">Lab Disease</p><p class="cf-field-value font-mono">{{ $outcome['lab_disease_code'] ?? '—' }}</p></div>
                    <div class="cf-field"><p class="cf-field-label">Test Method</p><p class="cf-field-value">{{ $outcome['lab_test_method'] ?? '—' }}</p></div>
                    <div class="cf-field"><p class="cf-field-label">Clinical Outcome</p><p class="cf-field-value">{{ $outcome['clinical_outcome'] ?? '—' }}</p></div>
                    <div class="cf-field"><p class="cf-field-label">PH Action</p><p class="cf-field-value">{{ $outcome['ph_action'] ?? '—' }}</p></div>
                    <div class="cf-field"><p class="cf-field-label">IHR</p><p class="cf-field-value">{{ ($outcome['ihr_notified'] ?? false) ? 'Notified · '.($outcome['ihr_reference'] ?? '—') : 'Not notified' }}</p></div>
                </div>
                @if (!empty($outcome['reason']))
                    <div class="mt-3 p-3 rounded-md border border-border bg-muted/30">
                        <p class="cf-field-label">Classification reason</p>
                        <p class="cf-field-value mt-1 whitespace-pre-wrap">{{ $outcome['reason'] }}</p>
                    </div>
                @endif
            </div>
        </section>
    @endif

    {{-- Related views (links to other reports filtered by this alert/POE) --}}
    @if ($alert)
        <section class="cf-section">
            <div class="cf-section-head"><h2>Related Views</h2></div>
            <div class="cf-section-body">
                <div class="cf-grid">
                    <a href="{{ url('/admin/reports/rpt-alert-intel') }}?alert_id={{ $alert['id'] }}" target="_blank" rel="noopener" class="cf-pill cf-pill-info">🔍 Alert Intelligence</a>
                    <a href="{{ url('/admin/reports/rpt-response-time') }}?alert_id={{ $alert['id'] }}" target="_blank" rel="noopener" class="cf-pill cf-pill-info">⏱️ Response Timing</a>
                    <a href="{{ url('/admin/reports/rpt-resolution-db') }}?alert_id={{ $alert['id'] }}" target="_blank" rel="noopener" class="cf-pill cf-pill-info">✅ Resolution Record</a>
                    @if (!empty($case['poe_code']))
                        <a href="{{ url('/admin/reports/rpt-poe-performance') }}?poe={{ urlencode($case['poe_code']) }}" target="_blank" rel="noopener" class="cf-pill cf-pill-info">📍 POE Performance</a>
                        <a href="{{ url('/admin/reports/rpt-ops-risk') }}?poe={{ urlencode($case['poe_code']) }}" target="_blank" rel="noopener" class="cf-pill cf-pill-info">⚠️ Operational Risk</a>
                    @endif
                </div>
            </div>
        </section>
    @endif

</div>
@endsection
