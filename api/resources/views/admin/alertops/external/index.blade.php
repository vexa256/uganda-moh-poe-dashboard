{{-- Alert Operations · External Requests (alert-external)
    Anchor visual family: STACKED RECIPIENT-TYPE SECTIONS — one section
    per recipient type (Lab / Hospital / Airline / Port / Other), inside
    each section a grid of request cards. UNREAD-REPLIES region above.
    NOT a master table; NOT kanban (per memory feedback_no_kanban).
    Per Paranoid v2 brief §10.4.
--}}
@extends('admin.layout')
@section('crumb', 'Alert Operations')
@section('title', 'External Requests')

@section('content')
<div x-data="alertopsExternal()" x-init="boot()" class="space-y-5">

    <header class="relative flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow">Alert Operations · Conversations outside the platform</p>
            <h2 class="display-md mt-1">External Requests</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                Every information request the platform has sent outside — labs, hospitals, airlines, port operators.
                Cards group by the kind of recipient. Replies that came back but have not been acknowledged sit at the top.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 relative">
            @include('admin.alertops._coach', ['sectionKey' => 'alert-external'])
        </div>
    </header>

    {{-- UNREAD REPLIES region — above the recipient sections --}}
    <template x-if="ready && unreadReplies.length > 0">
        <section class="card border-info" data-anchor="unread-replies">
            <div class="card-content">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-[13px] font-semibold">Replies waiting on you</h3>
                    <span class="badge badge-info text-[10px]" x-text="unreadReplies.length + ' unread'"></span>
                </div>
                <ul class="space-y-2">
                    <template x-for="r in unreadReplies" :key="r.id">
                        <li class="flex items-center justify-between rounded border border-info/40 bg-info/5 p-2 text-[12px]">
                            <div class="min-w-0">
                                <p class="font-semibold truncate" x-text="r.request_subject || r.alert_title"></p>
                                <p class="text-[11px] text-muted-foreground" x-text="r.responder_name + ' (' + r.responder_org + ') · replied ' + formatTime(r.responded_at)"></p>
                            </div>
                            <a class="btn btn-ghost btn-xs shrink-0" :href="`{{ url('/admin/alerts/external') }}/${r.id}`">Read reply</a>
                        </li>
                    </template>
                </ul>
            </div>
        </section>
    </template>

    {{-- ANCHOR — stacked recipient-type sections --}}
    <template x-if="ready">
        <section class="space-y-4" data-anchor="recipient-sections">
            <template x-for="group in groupedRequests" :key="group.type">
                <section class="card">
                    <div class="card-content">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <h3 class="text-[13px] font-semibold" x-text="group.label"></h3>
                                <p class="text-[11px] text-muted-foreground" x-text="group.count + ' requests · ' + group.activeCount + ' active'"></p>
                            </div>
                            @include('admin.alertops._explainer_modal', [
                                'explainerId' => 'alert-external.section',
                                'title' => 'Section: requests grouped by recipient',
                                'how' => 'Each card is one request. Status colour: green = received, blue = sent and waiting, grey = expired or cancelled. Click any card to read the conversation.',
                                'good' => 'Sections with steady activity and few expired cards.',
                                'concerning' => 'A section dominated by expired cards, or many resends without a reply.',
                                'whatToDo' => 'Resend an expired request from its card; cancel one no longer needed; record the reply on alert-caseroom as evidence.',
                                'cantTell' => 'It does not tell you the body of the reply itself — click into the card.',
                            ])
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                            <template x-for="card in group.cards" :key="card.id">
                                <article class="rounded-md border border-border bg-background p-3 hover:shadow-md cursor-pointer transition"
                                         :class="cardBorderTone(card.status)"
                                         @click="openRequest(card.id)">
                                    <div class="flex items-start justify-between gap-2">
                                        <p class="text-[12.5px] font-semibold truncate" x-text="card.request_subject || card.alert_title"></p>
                                        <span class="badge text-[9px] shrink-0" :class="statusBadgeClass(card.status)" x-text="card.status_label"></span>
                                    </div>
                                    <p class="mt-1 text-[11px] text-muted-foreground truncate" x-text="card.responder_name + ' (' + card.responder_org + ')'"></p>
                                    <dl class="mt-2 grid grid-cols-2 gap-1 text-[10px]">
                                        <div><dt class="text-muted-foreground uppercase">Sent</dt><dd x-text="formatTime(card.created_at)"></dd></div>
                                        <div><dt class="text-muted-foreground uppercase">Expires</dt><dd x-text="card.expires_at ? formatTime(card.expires_at) : 'no expiry'"></dd></div>
                                    </dl>
                                    <p class="mt-2 text-[10px] text-muted-foreground" x-show="card.resend_count > 0">
                                        Resent <span class="font-semibold" x-text="card.resend_count"></span> time<span x-show="card.resend_count !== 1">s</span>
                                    </p>
                                </article>
                            </template>
                            <template x-if="group.cards.length === 0">
                                <div class="text-[11px] text-muted-foreground italic text-center py-4 sm:col-span-2 lg:col-span-3">
                                    No requests in this group right now.
                                </div>
                            </template>
                        </div>
                    </div>
                </section>
            </template>
        </section>
    </template>

    <footer class="card">
        <div class="card-content !p-2 text-[11px] text-muted-foreground">
            <span x-show="ready">
                <span class="font-semibold" x-text="totalRequests"></span> requests across
                <span class="font-semibold" x-text="groupedRequests.length"></span> recipient types.
                <span x-show="unreadReplies.length > 0" class="ml-2 text-info">
                    <span class="font-semibold" x-text="unreadReplies.length"></span> reply<span x-show="unreadReplies.length !== 1">ies</span> unread.
                </span>
            </span>
        </div>
    </footer>
</div>

@push('scripts')
<script>
function alertopsExternal() {
    return {
        ready: false,
        rows: [],
        unreadReplies: [],
        groupedRequests: [],
        totalRequests: 0,

        async boot() {
            try {
                const r = await fetch('{{ route('admin.alerts.external.data') }}', { headers: { Accept: 'application/json' } });
                const j = await r.json();
                this.rows = j.rows || [];
                this.totalRequests = this.rows.length;
                this.recompute();
            } catch (e) { console.error(e); }
            this.ready = true;
        },

        recompute() {
            // Unread replies = status RECEIVED + responded_at not yet acknowledged.
            // Acknowledgement marker isn't on the server row — for v1 we use
            // "responded in the past 7 days" as a proxy. Honest fallback.
            const now = Date.now();
            this.unreadReplies = this.rows.filter(r => r.status === 'RECEIVED' && r.responded_at &&
                ((now - new Date(r.responded_at).getTime()) / 86400000) <= 7);

            // Group by responder_type (deterministic plain labels).
            const TYPE_LABELS = {
                'LAB': 'Laboratories', 'LABORATORY': 'Laboratories',
                'HOSPITAL': 'Hospitals', 'CLINIC': 'Hospitals',
                'AIRLINE': 'Airlines', 'CARRIER': 'Airlines',
                'PORT': 'Port Operators', 'PORT_OPERATOR': 'Port Operators',
                'OTHER': 'Other', null: 'Other', undefined: 'Other', '': 'Other',
            };
            const grouped = new Map();
            for (const r of this.rows) {
                const type = (r.responder_type || 'OTHER').toUpperCase();
                const label = TYPE_LABELS[type] || ('Type: ' + type);
                if (! grouped.has(label)) grouped.set(label, { type, label, cards: [], count: 0, activeCount: 0 });
                const g = grouped.get(label);
                g.cards.push({
                    ...r,
                    status_label: this.statusLabel(r.status),
                });
                g.count++;
                if (r.status === 'SENT') g.activeCount++;
            }
            // Sort cards inside each group: SENT first, then RECEIVED, then EXPIRED, then CANCELLED.
            for (const g of grouped.values()) {
                g.cards.sort((a, b) => this.statusRank(a.status) - this.statusRank(b.status));
            }
            this.groupedRequests = Array.from(grouped.values()).sort((a, b) => a.label.localeCompare(b.label));
        },

        statusLabel(s) {
            return ({ 'SENT': 'Waiting', 'RECEIVED': 'Reply received', 'EXPIRED': 'Expired', 'CANCELLED': 'Cancelled' })[s] || s;
        },
        statusRank(s)  { return ({ 'RECEIVED': 0, 'SENT': 1, 'EXPIRED': 2, 'CANCELLED': 3 })[s] ?? 9; },
        statusBadgeClass(s) { return ({ 'SENT': 'badge-info', 'RECEIVED': 'badge-success', 'EXPIRED': 'badge-warning', 'CANCELLED': 'badge-muted' })[s] || 'badge-soft'; },
        cardBorderTone(s) { return s === 'EXPIRED' ? 'border-warning/40' : (s === 'RECEIVED' ? 'border-success/40' : ''); },

        formatTime(ts) {
            if (! ts) return '';
            const m = (Date.now() - new Date(ts).getTime()) / 60000;
            if (m < 60) return Math.round(m) + ' min ago';
            if (m < 1440) return Math.round(m / 60) + ' h ago';
            return new Date(ts).toLocaleDateString();
        },

        openRequest(id) { window.location.href = `{{ url('/admin/alerts/external') }}/${id}`; },
    };
}
</script>
@endpush
@endsection
