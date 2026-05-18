@extends('admin.layout')

@section('crumb', 'Alert Lifecycle')
@section('title', 'External requests')

@section('content')

@include('admin.alerts._partials.coach', [
    'viewKey'   => 'external',
    'viewTitle' => 'External requests',
    'oneLiner'  => 'Send a one-time secure link to a lab, hospital, airline, or port operator so they can give us information without having to log in.',
    'why'       => 'Often the people who can confirm a case — a lab, an airline crew chief, a port doctor — do not have accounts on this system. Instead of asking them to sign up, we send them a single-use link that lets them respond, then expires.',
    'youDo'     => 'Pick "New request", choose the recipient, write what you need, send. They will get an email with a link valid for a few days. When they reply, the answer attaches to the case.',
    'connects'  => 'Replies and any uploaded files appear as evidence in Case room and as events in Case history.',
    'glossary'  => [
        ['term'=>'Recipient',  'plain'=>'The lab, hospital, airline, or port operator you are asking. They never log in.', 'technical'=>'responder_info_requests.responder_id → external_responders.'],
        ['term'=>'Sent',       'plain'=>'Link is live. They have not opened it yet.',                   'technical'=>'status = SENT.'],
        ['term'=>'Replied',    'plain'=>'They opened the link and submitted a reply.',                  'technical'=>'status = RECEIVED.'],
        ['term'=>'Expired',    'plain'=>'The link timed out — they did not respond in time. You can resend (which issues a fresh link).', 'technical'=>'status = EXPIRED.'],
        ['term'=>'Cancelled',  'plain'=>'You stopped the request. The link is dead.',                   'technical'=>'status = CANCELLED.'],
        ['term'=>'Resend',     'plain'=>'Send a fresh link. The old one stops working.',               'technical'=>'Rotates the token; resend_count++.'],
        ['term'=>'Token',      'plain'=>'The unique part of the link that proves the recipient is the right person. We use one-time, expiring tokens — never reusable.', 'technical'=>'48-hex; HMAC-derived.'],
    ],
    'wizardOptions' => [
        ['code'=>'TO_LAB',        'label'=>'Send a request to a lab',       'help'=>'Ask for a sample result.',         'glyph'=>'⚗', 'tone'=>'bg-blue-50 text-blue-700'],
        ['code'=>'TO_HOSPITAL',   'label'=>'Send a request to a hospital',  'help'=>'Ask for a clinical update.',       'glyph'=>'+', 'tone'=>'bg-rose-50 text-rose-700'],
        ['code'=>'TO_AIRLINE',    'label'=>'Send a request to an airline',  'help'=>'Ask for a passenger manifest or seat map.', 'glyph'=>'✈', 'tone'=>'bg-sky-50 text-sky-700'],
        ['code'=>'CANCEL',        'label'=>'Cancel a request I sent',       'help'=>'Kill a live link before it expires.','glyph'=>'×', 'tone'=>'bg-amber-50 text-amber-700'],
        ['code'=>'INBOX',         'label'=>'See replies I have not read',   'help'=>'Show responses that came back.',   'glyph'=>'⌂', 'tone'=>'bg-emerald-50 text-emerald-700'],
    ],
    'charts' => [
        [
            'key'        => 'kpi_strip',
            'title'      => 'Request status (KPI strip)',
            'shows'      => 'How many requests are currently in each state — sent, replied, expired, cancelled.',
            'read'       => 'Each tile is a count. The "Sent" tile is the live workload waiting on someone outside.',
            'good'       => 'Most requests get a reply within their window — the Replied tile climbs faster than the Expired tile.',
            'concerning' => 'Many Expired but few Replied — the wrong recipient, or the deadlines are too short.',
            'do'         => 'Open the Expired tab. Resend with a clearer subject, or pick a different responder.',
            'cant'       => 'It cannot tell you whether the reply was useful — only that one came back.',
            'source'     => 'responder_info_requests for cases in your scope, grouped by status.',
        ],
    ],
])

<div x-data="externalRequests()" x-init="boot()" x-effect="window.adminLock.set('page', detail.open || createWiz.open || responderWiz.open)" class="space-y-5">

    <section class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <div class="kpi kpi-glow"><p class="kpi-label">Sent</p><p class="kpi-value tabular-nums" x-text="tabCounts.sent ?? '—'"></p></div>
        <div class="kpi"><p class="kpi-label">Received</p><p class="kpi-value tabular-nums text-success" x-text="tabCounts.received ?? 0"></p></div>
        <div class="kpi"><p class="kpi-label">Expired</p><p class="kpi-value tabular-nums text-warning" x-text="tabCounts.expired ?? 0"></p></div>
        <div class="kpi"><p class="kpi-label">Cancelled</p><p class="kpi-value tabular-nums text-muted-foreground" x-text="tabCounts.cancelled ?? 0"></p></div>
        <div class="kpi"><p class="kpi-label">Total</p><p class="kpi-value tabular-nums" x-text="tabCounts.all ?? 0"></p></div>
    </section>

    <section class="card">
        <div class="card-content !p-0">
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="tabs-list w-full sm:w-auto">
                        <template x-for="t in [{key:'sent',label:'Sent'},{key:'received',label:'Replied'},{key:'expired',label:'Expired'},{key:'cancelled',label:'Cancelled'},{key:'all',label:'All'}]" :key="t.key">
                            <button class="tabs-trigger flex-1 sm:flex-none" :data-state="filters.status===t.key?'active':'inactive'" @click="filters.status=t.key; loadData()">
                                <span x-text="t.label"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="tabCounts[t.key] ?? 0"></span>
                            </button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <input type="search" class="input w-full sm:w-72" placeholder="Search subject / body…" x-model.debounce.300ms="filters.q" @input="loadData()">
                    <button class="btn btn-outline btn-sm" @click="openResponderWiz()" title="Add an external responder to the registry">+ Responder</button>
                    <button class="btn btn-brand btn-sm" @click="openCreateWiz()">+ New Request</button>
                </div>
                <div class="flex flex-wrap gap-2 items-center">
                    <div class="flex flex-wrap gap-1.5">
                        <button type="button" class="chip" :class="!filters.recipient_type ? 'chip-on' : 'chip-off'" @click="filters.recipient_type=''; loadData()">All recipients</button>
                        <template x-for="t in recipientTypes" :key="t.code">
                            <button type="button" class="chip" :class="filters.recipient_type===t.code ? 'chip-on' : 'chip-off'" @click="filters.recipient_type=t.code; loadData()">
                                <span x-text="t.label"></span>
                            </button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <select class="select w-auto !h-8 text-xs" x-model.number="filters.responder_id" @change="loadData()">
                        <option :value="0">Any specific responder</option>
                        <template x-for="r in meta.responders" :key="r.id"><option :value="r.id" x-text="r.name + ' · ' + recipientLabel(r.responder_type)"></option></template>
                    </select>
                </div>
            </div>

            <div class="table-wrap !rounded-none !border-0">
                <table class="table">
                    <thead class="table-head"><tr>
                        <th class="table-head-th">Subject · Alert</th>
                        <th class="table-head-th hidden md:table-cell">Responder</th>
                        <th class="table-head-th hidden lg:table-cell">Sent · Expiry · Views</th>
                        <th class="table-head-th text-right">Status</th>
                    </tr></thead>
                    <tbody class="table-body">
                        <template x-if="loading"><tr><td colspan="4" class="table-cell text-center py-8 text-muted-foreground text-sm">Loading…</td></tr></template>
                        <template x-if="!loading && rows.length===0"><tr><td colspan="4" class="table-cell"><div class="empty-state"><p class="text-sm">No external requests in scope.</p></div></td></tr></template>
                        <template x-for="row in rows" :key="row.id">
                            <tr class="table-row hover:bg-muted/20 cursor-pointer" @click="openDetail(row.id)">
                                <td class="table-cell"><div class="flex flex-col">
                                    <span class="text-[12.5px] font-semibold truncate" x-text="row.request_subject"></span>
                                    <span class="text-[10.5px] text-muted-foreground"><span class="font-mono" x-text="row.alert_code"></span> · <span x-text="row.alert_title"></span></span>
                                </div></td>
                                <td class="table-cell hidden md:table-cell text-[11.5px]">
                                    <div class="font-semibold" x-text="row.responder_name"></div>
                                    <div class="text-muted-foreground"><span class="badge badge-outline" x-text="row.responder_type"></span> · <span x-text="row.responder_email"></span></div>
                                </td>
                                <td class="table-cell hidden lg:table-cell text-[11px]">
                                    <div x-text="humanTime(row.created_at)"></div>
                                    <div class="text-muted-foreground">
                                        <span x-show="row.minutes_to_expiry !== null && row.minutes_to_expiry > 0">expires in <span x-text="formatDelta(row.minutes_to_expiry)"></span></span>
                                        <span x-show="row.minutes_to_expiry !== null && row.minutes_to_expiry <= 0" class="text-critical">expired</span>
                                        · views <span x-text="row.view_count"></span>
                                        <span x-show="row.resend_count>0">· resent <span x-text="row.resend_count"></span>×</span>
                                    </div>
                                </td>
                                <td class="table-cell text-right">
                                    <span class="badge" :class="statusBadge(row.status)" x-text="statusLabel(row.status)" :title="row.status"></span>
                                    <span x-show="row.is_stale && row.status==='SENT'" class="badge badge-critical ml-1">no reply yet</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- ─── Detail sheet ─── --}}
    <template x-if="detail.open">
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="detail.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="detail.open=false"></div>
            <div class="relative w-full sm:max-w-2xl bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-5 py-3 border-b">
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">External request</p>
                        <h2 class="text-[14px] font-bold truncate" x-text="detail.req?.request_subject"></h2>
                    </div>
                    <a class="btn btn-outline btn-xs" :href="'/admin/alerts?focus='+detail.req?.alert_id" target="_blank">Open alert</a>
                    <button class="btn btn-ghost btn-icon-xs" @click="detail.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-5 py-5 space-y-5">
                    <template x-if="detail.loading"><p class="text-sm text-muted-foreground">Loading…</p></template>
                    <template x-if="!detail.loading && detail.req">
                        <div class="space-y-5">
                            {{-- Status & quick actions --}}
                            <section class="flex flex-wrap items-center gap-2">
                                <span class="badge" :class="statusBadge(detail.req.status)" x-text="detail.req.status"></span>
                                <span class="text-[11px] text-muted-foreground">Created <span x-text="humanTime(detail.req.created_at)"></span> · expires <span x-text="humanTime(detail.req.expires_at)"></span></span>
                                <div class="flex-1"></div>
                                <button x-show="['SENT'].includes(detail.req.status)" class="btn btn-outline btn-xs" @click="resend()">Resend (rotate token)</button>
                                <button x-show="['SENT'].includes(detail.req.status)" class="btn btn-outline btn-xs text-critical" @click="cancel()">Cancel</button>
                            </section>

                            {{-- Public link --}}
                            <section x-show="detail.public_url">
                                <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Public link (audit)</h3>
                                <div class="p-2.5 bg-muted rounded-md flex items-center gap-2">
                                    <code class="text-[11.5px] font-mono flex-1 break-all" x-text="detail.public_url"></code>
                                    <button class="btn btn-outline btn-xs" @click="copy(detail.public_url, 'Public URL copied')">Copy</button>
                                </div>
                                <p class="text-[10.5px] text-muted-foreground mt-1">Views: <span x-text="detail.req.view_count ?? 0"></span> · last viewed <span x-text="humanTime(detail.req.last_viewed_at) || 'never'"></span></p>
                            </section>

                            {{-- Responder + alert --}}
                            <section class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-[12px]">
                                <div>
                                    <p class="text-muted-foreground">Responder</p>
                                    <p class="font-semibold" x-text="detail.req.responder_name"></p>
                                    <p class="text-[11px] text-muted-foreground"><span class="badge badge-outline" x-text="detail.req.responder_type"></span> · <span x-text="detail.req.responder_email"></span></p>
                                </div>
                                <div>
                                    <p class="text-muted-foreground">Alert</p>
                                    <p class="font-mono" x-text="detail.req.alert_code"></p>
                                    <p class="text-[11px]" x-text="detail.req.alert_title"></p>
                                </div>
                            </section>

                            {{-- Original message --}}
                            <section>
                                <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Outgoing message</h3>
                                <p class="text-[12.5px] whitespace-pre-wrap" x-text="detail.req.request_body"></p>
                            </section>

                            {{-- Response payload --}}
                            <section x-show="detail.req.response_payload">
                                <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Response</h3>
                                <div class="card !rounded-md">
                                    <div class="card-content !p-3 space-y-2 text-[12px]">
                                        <template x-for="[label, val] in Object.entries(detail.req.response_payload || {})" :key="label">
                                            <div x-show="val" class="border-b last:border-0 py-1">
                                                <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground" x-text="label.replace(/_/g,' ')"></p>
                                                <p class="whitespace-pre-wrap" x-text="String(val)"></p>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <p class="text-[10.5px] text-muted-foreground mt-1">From IP <span x-text="detail.req.responder_ip || '—'"></span> · UA <span x-text="(detail.req.responder_ua||'').slice(0,80)"></span></p>
                            </section>

                            {{-- Linked evidence --}}
                            <section x-show="detail.evidence?.length>0">
                                <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Attached evidence</h3>
                                <ul class="divide-y rounded-md border bg-card">
                                    <template x-for="e in (detail.evidence||[])" :key="e.id">
                                        <li class="px-3 py-2 text-[11.5px]">
                                            <div class="flex items-center gap-2">
                                                <span class="badge badge-outline" x-text="e.category"></span>
                                                <span class="font-semibold" x-text="e.title"></span>
                                                <span class="ml-auto text-[10.5px] text-muted-foreground" x-text="(e.file_size_bytes/1024/1024).toFixed(2)+' MB · '+e.file_mime"></span>
                                            </div>
                                            <p class="text-muted-foreground mt-0.5" x-show="e.file_ref" x-text="e.file_ref"></p>
                                        </li>
                                    </template>
                                </ul>
                            </section>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Create wizard ─── --}}
    <template x-if="createWiz.open">
        <div class="fixed inset-0 z-[55] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="createWiz.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="createWiz.open=false"></div>
            <div class="relative w-full max-w-lg card !rounded-xl !shadow-elevation-5 p-5" @click.stop>
                <h3 class="text-[14px] font-bold">New external request</h3>
                <label class="label mt-3">Alert ID <span class="text-critical">*</span></label>
                <input type="number" min="1" class="input mt-1.5" x-model.number="createWiz.form.alert_id" placeholder="Numeric alert id (open in dossier first)">
                <label class="label mt-3">Responder <span class="text-critical">*</span></label>
                <select class="select mt-1.5" x-model.number="createWiz.form.responder_id">
                    <option :value="0">Select…</option>
                    <template x-for="r in meta.responders" :key="r.id"><option :value="r.id" x-text="r.name + ' · ' + r.organisation + ' · ' + r.responder_type"></option></template>
                </select>
                <label class="label mt-3">Subject (optional)</label>
                <input class="input mt-1.5" maxlength="200" x-model="createWiz.form.subject" placeholder="Defaults to 'POE Sentinel · Information request · …'">
                <label class="label mt-3">Message <span class="text-critical">*</span></label>
                <textarea class="input mt-1.5" rows="5" maxlength="4000" x-model="createWiz.form.message" placeholder="What information do you need? Be specific."></textarea>
                <p class="text-[11px] text-muted-foreground mt-1">A one-time link will be emailed to the responder. They have <span x-text="meta.token_ttl_days || 7"></span> days to reply.</p>
                <div class="flex justify-end gap-2 mt-5">
                    <button class="btn btn-outline btn-sm" @click="createWiz.open=false">Cancel</button>
                    <button class="btn btn-brand btn-sm" :disabled="!(createWiz.form.alert_id && createWiz.form.responder_id && createWiz.form.message) || createWiz.submitting" @click="performCreate()"><span x-show="!createWiz.submitting">Send request</span><span x-show="createWiz.submitting">…</span></button>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Add responder wizard ─── --}}
    <template x-if="responderWiz.open">
        <div class="fixed inset-0 z-[55] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="responderWiz.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="responderWiz.open=false"></div>
            <div class="relative w-full max-w-lg card !rounded-xl !shadow-elevation-5 p-5" @click.stop>
                <h3 class="text-[14px] font-bold">Add external responder</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-3">
                    <div><label class="label">Name <span class="text-critical">*</span></label><input class="input mt-1.5" x-model="responderWiz.form.name"></div>
                    <div><label class="label">Email <span class="text-critical">*</span></label><input type="email" class="input mt-1.5" x-model="responderWiz.form.email"></div>
                    <div><label class="label">Organisation</label><input class="input mt-1.5" x-model="responderWiz.form.organisation"></div>
                    <div>
                        <label class="label">Type <span class="text-critical">*</span></label>
                        <select class="select mt-1.5" x-model="responderWiz.form.responder_type">
                            <template x-for="t in meta.responder_types" :key="t"><option :value="t" x-text="t"></option></template>
                        </select>
                    </div>
                    <div><label class="label">Phone</label><input class="input mt-1.5" x-model="responderWiz.form.phone"></div>
                    <div><label class="label">District</label><input class="input mt-1.5" x-model="responderWiz.form.district_code" placeholder="e.g. Kampala District"></div>
                </div>
                <label class="label mt-3">Notes</label>
                <textarea class="input mt-1.5" rows="2" maxlength="500" x-model="responderWiz.form.notes"></textarea>
                <div class="flex justify-end gap-2 mt-5">
                    <button class="btn btn-outline btn-sm" @click="responderWiz.open=false">Cancel</button>
                    <button class="btn btn-brand btn-sm" :disabled="!(responderWiz.form.name && responderWiz.form.email && responderWiz.form.responder_type) || responderWiz.submitting" @click="addResponder()">Add</button>
                </div>
            </div>
        </div>
    </template>

    <div class="fixed inset-x-0 bottom-6 z-[70] flex justify-center px-3 pointer-events-none" x-show="opToast.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="opToast.kind==='success'?'toast-success':'toast-destructive'">
            <div><p class="toast-title" x-text="opToast.title"></p><p class="toast-description" x-text="opToast.body"></p></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function externalRequests(){
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
    const headersJson=()=>({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf()});
    const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null&&v!==0&&v!=='0').map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');

    return {
        meta:{statuses:[],responder_types:[],responders:[],token_ttl_days:7},
        filters:{status:'sent', responder_id:0, recipient_type:'', q:''},
        recipientTypes:[
            { code:'LABORATORY', label:'Labs' },
            { code:'HOSPITAL',   label:'Hospitals' },
            { code:'AIRLINE',    label:'Airlines' },
            { code:'PORT',       label:'Port operators' },
            { code:'OTHER',      label:'Other' },
        ],
        rows:[], loading:false, tabCounts:{sent:0,received:0,expired:0,cancelled:0,all:0},
        detail:{open:false, loading:false, req:null, evidence:[], public_url:''},
        createWiz:{open:false, submitting:false, form:{alert_id:0, responder_id:0, subject:'', message:''}},
        responderWiz:{open:false, submitting:false, form:{name:'', email:'', organisation:'', responder_type:'HOSPITAL', phone:'', district_code:'', notes:''}},
        opToast:{open:false,kind:'success',title:'',body:'',t:null},

        async boot(){
            await Promise.all([this.loadMeta(), this.loadData()]);
            window.addEventListener('alert-coach:wizard', e => {
                if (!e?.detail || e.detail.view !== 'external') return;
                this.handleWizard(e.detail.code);
            });
        },

        handleWizard(code){
            switch (code) {
                case 'TO_LAB':      this.filters.recipient_type='LABORATORY'; this.openCreateWiz(); break;
                case 'TO_HOSPITAL': this.filters.recipient_type='HOSPITAL';   this.openCreateWiz(); break;
                case 'TO_AIRLINE':  this.filters.recipient_type='AIRLINE';    this.openCreateWiz(); break;
                case 'CANCEL':      this.filters.status='sent';               this.loadData();      break;
                case 'INBOX':       this.filters.status='received';           this.loadData();      break;
            }
        },

        statusLabel(s){
            return ({ SENT:'Sent', RECEIVED:'Replied', EXPIRED:'Expired', CANCELLED:'Cancelled' })[s] || s;
        },
        recipientLabel(t){
            return ({ LABORATORY:'Lab', HOSPITAL:'Hospital', AIRLINE:'Airline', PORT:'Port operator', OTHER:'Other' })[t] || t;
        },
        async loadMeta(){
            try{ const r=await fetch('/admin/alerts/external/meta',{headers:{'Accept':'application/json'}}); const j=await r.json(); if(j.success) this.meta=j.data; }catch(e){}
        },
        async loadData(){
            this.loading=true;
            try{
                // recipient_type is filtered client-side so the existing API stays untouched.
                const sendable = { ...this.filters }; delete sendable.recipient_type;
                const r=await fetch('/admin/alerts/external/data?'+qs(sendable),{headers:{'Accept':'application/json'}});
                const j=await r.json();
                if(j.success){
                    let rows = j.data.rows;
                    if (this.filters.recipient_type) {
                        rows = rows.filter(x => (x.responder_type || '').toUpperCase() === this.filters.recipient_type);
                    }
                    this.rows = rows;
                    this.tabCounts = j.meta?.tabs || this.tabCounts;
                    if(window.Alpine && Alpine.store('pageMeta')){ Alpine.store('pageMeta').rows=this.tabCounts[this.filters.status]??this.tabCounts.all; Alpine.store('pageMeta').kind='alert-external'; }
                } else { this.toast('error','Load failed', j.message); }
            } catch(e){ this.toast('error','Network', e.message); } finally{ this.loading=false; }
        },

        async openDetail(id){
            this.detail={open:true, loading:true, req:null, evidence:[], public_url:''};
            try{
                const r=await fetch('/admin/alerts/external/'+id,{headers:{'Accept':'application/json'}});
                const j=await r.json();
                if(j.success){ this.detail.req=j.data.request; this.detail.evidence=j.data.evidence; this.detail.public_url=j.data.public_url; this.detail.loading=false; }
                else { this.toast('error','Load failed', j.message); this.detail.loading=false; }
            } catch(e){ this.toast('error','Network', e.message); this.detail.loading=false; }
        },

        async resend(){
            if(!confirm('Rotate the token for this request? The previous link will be invalidated and a new email will be sent.')) return;
            try{
                const r=await fetch('/admin/alerts/external/'+this.detail.req.id+'/resend',{method:'POST',headers:headersJson(),body:'{}'});
                const j=await r.json();
                if(j.success){ this.toast('success','Resent','New token issued. Old link disabled.'); this.detail.open=false; await this.loadData(); }
                else this.toast('error','Failed', j.message);
            } catch(e){ this.toast('error','Network', e.message); }
        },
        async cancel(){
            if(!confirm('Cancel this request? Token cannot be used after cancellation.')) return;
            try{
                const r=await fetch('/admin/alerts/external/'+this.detail.req.id+'/cancel',{method:'POST',headers:headersJson(),body:'{}'});
                const j=await r.json();
                if(j.success){ this.toast('success','Cancelled','Request invalidated.'); await this.openDetail(this.detail.req.id); await this.loadData(); }
                else this.toast('error','Failed', j.message);
            } catch(e){ this.toast('error','Network', e.message); }
        },

        openCreateWiz(){ this.createWiz={open:true, submitting:false, form:{alert_id:0, responder_id:0, subject:'', message:''}}; },
        async performCreate(){
            this.createWiz.submitting=true;
            try{
                const f = this.createWiz.form;
                const r=await fetch('/admin/alerts/'+f.alert_id+'/external-requests',{method:'POST',headers:headersJson(),body:JSON.stringify({responder_id:f.responder_id, message:f.message, subject:f.subject||null})});
                const j=await r.json();
                if(j.success){ this.toast('success','Request sent','Email dispatched. Token live for '+(this.meta.token_ttl_days||7)+' days.'); this.createWiz.open=false; await this.loadData(); }
                else this.toast('error','Failed', j.message);
            } catch(e){ this.toast('error','Network', e.message); } finally{ this.createWiz.submitting=false; }
        },

        openResponderWiz(){ this.responderWiz={open:true, submitting:false, form:{name:'', email:'', organisation:'', responder_type:'HOSPITAL', phone:'', district_code:'', notes:''}}; },
        async addResponder(){
            this.responderWiz.submitting=true;
            try{
                const r=await fetch('/admin/alerts/external/responders',{method:'POST',headers:headersJson(),body:JSON.stringify(this.responderWiz.form)});
                const j=await r.json();
                if(j.success){ this.toast('success','Added','Responder added.'); this.responderWiz.open=false; await this.loadMeta(); }
                else this.toast('error','Failed', j.message || JSON.stringify(j.error));
            } catch(e){ this.toast('error','Network', e.message); } finally{ this.responderWiz.submitting=false; }
        },

        async copy(text, label){ try{ await navigator.clipboard.writeText(text||''); this.toast('success','Copied', label||'On clipboard.'); }catch(e){ this.toast('error','Copy failed','Select and copy manually.'); } },
        statusBadge(s){ return ({SENT:'badge-info',RECEIVED:'badge-success',EXPIRED:'badge-warning',CANCELLED:'badge-outline'})[s]||'badge-outline'; },
        humanTime(t){ if(!t) return '—'; try{ return new Date(t).toLocaleString(); }catch(e){ return t; } },
        formatDelta(min){
            if(min===null || min===undefined) return '—';
            const m = Math.abs(min);
            if(m<60) return Math.round(m)+'m';
            if(m<60*24) return Math.round(m/60)+'h';
            return Math.round(m/60/24)+'d';
        },
        toast(kind,title,body){ this.opToast={open:true,kind,title,body,t:null}; clearTimeout(this.opToast.t); this.opToast.t=setTimeout(()=>{this.opToast.open=false;},3500); },
    };
}
</script>
@endpush
