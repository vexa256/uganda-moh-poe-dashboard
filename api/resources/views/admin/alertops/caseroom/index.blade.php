{{-- Alert Operations · Case Room (alert-caseroom)
    Anchor visual family: 3-PANE WORKSPACE — left = case identity + status,
    centre = conversation as plain-language event entries with icons,
    right = collaborator wall + evidence library + outstanding items.
    Outside a specific case: triaged inbox (cards), NOT a master table.
    Per Paranoid v2 brief §10.3.
--}}
@extends('admin.layout')
@section('crumb', 'Alert Operations')
@section('title', 'Case Room')

@section('content')
<div x-data="alertopsCaseroom()" x-init="boot()" class="space-y-5">

    <header class="relative flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow">Alert Operations · Where the team works the case together</p>
            <h2 class="display-md mt-1">Case Room</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                Choose a case to enter its room — the conversation, the people, the evidence, and the
                outstanding items reorganise around the case as a shared workspace. Outside a case,
                you see a triaged inbox of recent activity across rooms you are part of.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 relative">
            @include('admin.alertops._coach', ['sectionKey' => 'alert-caseroom'])
        </div>
    </header>

    {{-- TRIAGED INBOX (when no specific case selected) --}}
    <template x-if="ready && !selectedAlertId">
        <section data-anchor="triaged-inbox" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <template x-for="card in inboxCards" :key="card.alert_id">
                <article class="card hover:shadow-md cursor-pointer" @click="enterRoom(card.alert_id)">
                    <div class="card-content">
                        <p class="eyebrow truncate" x-text="card.alert_code"></p>
                        <h3 class="text-[14px] font-semibold mt-0.5 truncate" x-text="card.alert_title"></h3>
                        <p class="text-[11px] text-muted-foreground mt-0.5">
                            Last activity <span x-text="card.last_activity_label"></span>
                        </p>
                        <div class="mt-2 grid grid-cols-3 gap-2 text-center text-[10px]">
                            <div><dt class="text-muted-foreground uppercase">Comments</dt><dd class="text-[14px] font-semibold tabular-nums" x-text="card.comments"></dd></div>
                            <div><dt class="text-muted-foreground uppercase">Evidence</dt><dd class="text-[14px] font-semibold tabular-nums" x-text="card.evidence"></dd></div>
                            <div><dt class="text-muted-foreground uppercase">People</dt><dd class="text-[14px] font-semibold tabular-nums" x-text="card.people"></dd></div>
                        </div>
                    </div>
                </article>
            </template>
            <template x-if="inboxCards.length === 0">
                <div class="card md:col-span-2 lg:col-span-3"><div class="card-content py-10 text-center">
                    <p class="text-[14px] font-semibold">No active rooms in your scope</p>
                    <p class="text-[12px] text-muted-foreground mt-1">When a case opens that you are added to, its room will appear here.</p>
                </div></div>
            </template>
        </section>
    </template>

    {{-- 3-PANE WORKSPACE (when a specific case is selected) --}}
    <template x-if="ready && selectedAlertId">
        <section data-anchor="three-pane-workspace" class="grid grid-cols-1 lg:grid-cols-12 gap-3">

            {{-- LEFT PANE — case identity --}}
            <aside class="lg:col-span-3 card">
                <div class="card-content">
                    <button class="text-[11px] text-brand hover:underline mb-2" @click="leaveRoom()">← Back to inbox</button>
                    <p class="eyebrow truncate" x-text="room?.alert?.alert_code"></p>
                    <h3 class="text-[14px] font-semibold mt-0.5" x-text="room?.alert?.alert_title"></h3>
                    <dl class="mt-3 space-y-1.5 text-[12px]">
                        <div><dt class="text-[10px] uppercase text-muted-foreground">Status</dt><dd x-text="room?.alert?.status"></dd></div>
                        <div><dt class="text-[10px] uppercase text-muted-foreground">Risk</dt><dd x-text="room?.alert?.risk"></dd></div>
                        <div><dt class="text-[10px] uppercase text-muted-foreground">Routed to</dt><dd x-text="room?.alert?.level"></dd></div>
                        <div><dt class="text-[10px] uppercase text-muted-foreground">District / PoE</dt><dd x-text="(room?.alert?.district || '—') + ' / ' + (room?.alert?.poe || '—')"></dd></div>
                    </dl>
                    <a class="btn btn-ghost btn-sm w-full mt-4" :href="`{{ url('/admin/alerts') }}/${selectedAlertId}/case-file`">Open full dossier</a>
                </div>
            </aside>

            {{-- CENTRE PANE — conversation --}}
            <main class="lg:col-span-6 card">
                <div class="card-content">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-[13px] font-semibold">Conversation</h3>
                        <span class="text-[11px] text-muted-foreground" x-text="(room?.comments || []).length + ' entries'"></span>
                    </div>
                    <div class="space-y-3 max-h-[460px] overflow-y-auto pr-1">
                        <template x-for="c in (room?.comments || [])" :key="c.id">
                            <div class="flex items-start gap-2 text-[12.5px]" :class="c.is_pinned ? 'rounded-md bg-muted/30 p-2' : ''">
                                <span class="shrink-0 inline-flex h-7 w-7 items-center justify-center rounded-full bg-brand/15 text-brand text-[11px] font-semibold"
                                      x-text="(c.author_name || '?').charAt(0)"></span>
                                <div class="min-w-0">
                                    <p class="text-[11px] text-muted-foreground">
                                        <span class="font-semibold text-foreground" x-text="c.author_name || 'System'"></span>
                                        · <span x-text="c.author_role || ''"></span>
                                        · <span x-text="formatTime(c.created_at)"></span>
                                        <template x-if="c.is_pinned"><span class="badge badge-info text-[9px] ml-1">Pinned</span></template>
                                    </p>
                                    <p class="mt-0.5 whitespace-pre-wrap break-words" x-text="c.body"></p>
                                </div>
                            </div>
                        </template>
                        <template x-if="(room?.comments || []).length === 0">
                            <p class="text-[12px] text-muted-foreground italic text-center py-6">No comments yet. Add one below to start the conversation.</p>
                        </template>
                    </div>
                    <div class="mt-3 border-t border-border pt-3">
                        <textarea x-model="newComment" rows="2" placeholder="Add a comment for the team…"
                                  class="input w-full text-[12.5px]" maxlength="2000"></textarea>
                        <div class="mt-2 flex justify-end gap-2">
                            <button class="btn btn-ghost btn-sm" @click="newComment = ''">Clear</button>
                            <button class="btn btn-primary btn-sm" :disabled="postingComment" @click="postComment()">Post</button>
                        </div>
                    </div>
                </div>
            </main>

            {{-- RIGHT PANE — people + evidence + outstanding --}}
            <aside class="lg:col-span-3 space-y-3">
                <div class="card">
                    <div class="card-content">
                        <h3 class="text-[13px] font-semibold mb-2">People on this case</h3>
                        <ul class="space-y-1">
                            <template x-for="p in (room?.collaborators || [])" :key="p.id">
                                <li class="flex items-center gap-2 text-[12px]">
                                    <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-brand/15 text-brand text-[10px] font-semibold" x-text="(p.full_name || '?').charAt(0)"></span>
                                    <span class="min-w-0">
                                        <span class="font-semibold truncate" x-text="p.full_name"></span>
                                        <span class="text-[10px] text-muted-foreground" x-text="' · ' + (p.role || '—')"></span>
                                    </span>
                                </li>
                            </template>
                            <template x-if="(room?.collaborators || []).length === 0">
                                <li class="text-[11px] text-muted-foreground italic">Just you on this case.</li>
                            </template>
                        </ul>
                    </div>
                </div>
                <div class="card">
                    <div class="card-content">
                        <h3 class="text-[13px] font-semibold mb-2">Evidence library</h3>
                        <ul class="space-y-1.5">
                            <template x-for="e in (room?.evidence || [])" :key="e.id">
                                <li class="text-[12px]">
                                    <p class="font-semibold truncate" x-text="e.title"></p>
                                    <p class="text-[10px] text-muted-foreground" x-text="e.category + ' · ' + (e.uploader_full_name || 'system') + ' · ' + formatTime(e.created_at)"></p>
                                </li>
                            </template>
                            <template x-if="(room?.evidence || []).length === 0">
                                <li class="text-[11px] text-muted-foreground italic">No evidence yet.</li>
                            </template>
                        </ul>
                    </div>
                </div>
                <div class="card border-warning">
                    <div class="card-content">
                        <h3 class="text-[13px] font-semibold mb-2">Outstanding items</h3>
                        <ul class="space-y-1 text-[12px]">
                            <template x-for="h in (room?.handoffs || []).filter(x => x.status === 'SENT')" :key="h.id">
                                <li>Handoff to <span class="font-semibold" x-text="h.to_name || h.to_role"></span> — pending acceptance</li>
                            </template>
                            <li x-show="(room?.counters?.evidence_external || 0) > 0">
                                <span class="font-semibold" x-text="room.counters.evidence_external"></span> external evidence references awaiting review
                            </li>
                            <li x-show="(room?.handoffs || []).filter(x => x.status === 'SENT').length === 0 && (room?.counters?.evidence_external || 0) === 0"
                                class="text-[11px] text-muted-foreground italic">Nothing outstanding.</li>
                        </ul>
                    </div>
                </div>
            </aside>
        </section>
    </template>

    <footer class="card">
        <div class="card-content !p-2 text-[11px] text-muted-foreground">
            <span x-show="ready && !selectedAlertId" x-text="inboxCards.length + ' active rooms in your scope'"></span>
            <span x-show="ready && selectedAlertId">In room <span class="font-semibold" x-text="room?.alert?.alert_code"></span></span>
        </div>
    </footer>
</div>

@push('scripts')
<script>
function alertopsCaseroom() {
    return {
        ready: false,
        selectedAlertId: null,
        inboxCards: [],
        room: null,
        newComment: '',
        postingComment: false,

        async boot() {
            const url = new URL(window.location.href);
            const aid = url.searchParams.get('alert_id');
            if (aid) await this.enterRoom(parseInt(aid, 10));
            else await this.loadInbox();
        },

        async loadInbox() {
            try {
                const r = await fetch('{{ route('admin.alerts.case-room.data') }}', { headers: { Accept: 'application/json' } });
                const j = await r.json();
                // Cross-alert audit shape: top-N recent rooms
                const rooms = j.rooms || j.data?.rooms || [];
                this.inboxCards = rooms.slice(0, 18).map(r => ({
                    alert_id: r.alert_id || r.id,
                    alert_code: r.alert_code,
                    alert_title: r.alert_title,
                    last_activity_label: this.formatTime(r.last_activity_at || r.updated_at),
                    comments: r.comments || 0,
                    evidence: r.evidence || 0,
                    people: r.collaborators || r.people || 0,
                }));
            } catch (e) { console.error(e); }
            this.ready = true;
        },

        async enterRoom(id) {
            this.selectedAlertId = id;
            this.room = null;
            try {
                const r = await fetch(`{{ route('admin.alerts.case-room.data') }}?alert_id=${id}`, { headers: { Accept: 'application/json' } });
                const j = await r.json();
                this.room = j;
            } catch (e) { console.error(e); }
            this.ready = true;
        },

        leaveRoom() { this.selectedAlertId = null; this.room = null; this.loadInbox(); },

        async postComment() {
            if (! this.newComment.trim() || ! this.selectedAlertId) return;
            this.postingComment = true;
            const optimistic = {
                id: 'tmp-' + Date.now(),
                author_name: 'You',
                author_role: '',
                body: this.newComment,
                created_at: new Date().toISOString(),
                is_pinned: false,
                pending: true,
            };
            this.room.comments.unshift(optimistic);
            const body = this.newComment;
            this.newComment = '';
            try {
                const r = await fetch(`{{ url('/admin/alerts/case-room') }}/${this.selectedAlertId}/comments`, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Idempotency-Key': crypto.randomUUID ? crypto.randomUUID() : ('idem-' + Date.now()),
                    },
                    body: JSON.stringify({ body, body_format: 'PLAIN', visibility: 'ALL' }),
                });
                if (! r.ok) throw new Error('rejected');
                await this.enterRoom(this.selectedAlertId);
            } catch (e) {
                this.room.comments = this.room.comments.filter(c => c.id !== optimistic.id);
                this.newComment = body;
                alert('Comment could not be posted: ' + e.message);
            } finally {
                this.postingComment = false;
            }
        },

        formatTime(ts) {
            if (! ts) return '';
            const m = (Date.now() - new Date(ts).getTime()) / 60000;
            if (m < 60) return Math.round(m) + ' min ago';
            if (m < 1440) return Math.round(m / 60) + ' h ago';
            return new Date(ts).toLocaleDateString();
        },
    };
}
</script>
@endpush
@endsection
