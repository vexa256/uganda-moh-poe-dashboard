@extends('admin.layout')

@section('crumb', 'Alert Lifecycle')
@section('title', 'Case room')

@section('content')

@include('admin.alerts._partials.coach', [
    'viewKey'   => 'caseroom',
    'viewTitle' => 'Case room',
    'oneLiner'  => 'The shared workspace for everyone working on a case — comments, evidence files, and the people on the team.',
    'why'       => 'Cases need conversation. Field officers, lab liaisons, and supervisors all see the same updates here, so no one has to repeat what they know.',
    'youDo'     => 'Pick a case to open its room. Post an update so the team sees it. Upload evidence — a sample report, a photo, a CSV. Add or remove people who are working on the case.',
    'connects'  => 'Everything posted here also appears in Case history (timeline). The Case dossier shows the same comments and evidence alongside the rest of the case.',
    'glossary'  => [
        ['term'=>'Collaborator',  'plain'=>'A person working on the case. Can read, comment, and (depending on role) act.', 'technical'=>'Row in alert_collaborators with is_active = 1.'],
        ['term'=>'Evidence',      'plain'=>'A document, photo, or file attached to the case. Once uploaded it stays — you can mark it deleted but the record remains.', 'technical'=>'Row in alert_evidence; soft delete via is_active.'],
        ['term'=>'Comment',       'plain'=>'A short message about the case. INTERNAL is for the response team only; ALL is for everyone with access; EXTERNAL is for an external responder.', 'technical'=>'alert_comments.visibility.'],
        ['term'=>'Pin',           'plain'=>'Stick an important comment to the top so it does not get lost in the thread.', 'technical'=>'alert_comments.is_pinned.'],
        ['term'=>'Handoff',       'plain'=>'Pass the case to a specific person. They have to accept it before they own it.', 'technical'=>'alert_handoffs; awaits HANDOFF_ACCEPTED.'],
    ],
    'wizardOptions' => [
        ['code'=>'COMMENT',     'label'=>'Add a comment to a case',   'help'=>'Post a short update the team will see.',                'glyph'=>'⌨', 'tone'=>'bg-blue-50 text-blue-700'],
        ['code'=>'UPLOAD',      'label'=>'Upload evidence',           'help'=>'Attach a document, photo, or file to a case.',          'glyph'=>'⇪', 'tone'=>'bg-emerald-50 text-emerald-700'],
        ['code'=>'ADD_PERSON',  'label'=>'Add someone to a case',     'help'=>'Bring a colleague onto the case so they can help.',     'glyph'=>'+', 'tone'=>'bg-violet-50 text-violet-700'],
        ['code'=>'REMOVE',      'label'=>'Remove someone from a case','help'=>'Take someone off when they no longer need access.',     'glyph'=>'×', 'tone'=>'bg-rose-50 text-rose-700'],
        ['code'=>'WALK_NEW',    'label'=>'Walk me through a case I am new to','help'=>'Open the case dossier and read it from the top.', 'glyph'=>'⚏', 'tone'=>'bg-slate-100 text-slate-700'],
    ],
    'charts' => [
        [
            'key'        => 'recent_collab',
            'title'      => 'Recent collaboration activity',
            'shows'      => 'Comments, uploads, role changes, and handoffs across cases in your area.',
            'read'       => 'Each row is one event. The pill (e.g. "Comment posted") tells you what happened. The actor column shows who.',
            'good'       => 'Cases get comments and evidence within hours of opening — the team is engaged.',
            'concerning' => 'A case that gets opened but never gets a comment, an upload, or a handoff for days.',
            'do'         => 'Click the case to open its room and post a status comment.',
            'cant'       => 'It cannot tell you whether a comment was useful — only that one was made.',
            'source'     => 'alert_timeline_events where event_code starts with COLLABORATOR_, COMMENT_, EVIDENCE_, or HANDOFF_.',
        ],
    ],
])

<div x-data="caseRoom()" x-init="boot()" x-effect="window.adminLock.set('page', warRoom.open || handoffWiz.open || addCollabWiz.open)" class="space-y-5">

    {{-- Cross-alert audit lens (default) --}}
    <section class="card">
        <div class="card-content !p-0">
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                <div class="flex flex-wrap gap-1.5 items-center">
                    <template x-for="c in codes" :key="c">
                        <button class="chip" :class="filters.codes.includes(c)?'chip-on':'chip-off'" @click="toggleCode(c)">
                            <span x-text="codeLabel(c)" :title="c"></span>
                            <span class="ml-1 opacity-70" x-text="counters[c] ?? 0"></span>
                        </button>
                    </template>
                    <div class="flex-1"></div>
                    <input type="search" class="input w-full sm:w-72 !h-8 text-xs" placeholder="Search summary…" x-model.debounce.300ms="filters.q" @input="loadData()">
                </div>
                <div class="flex flex-wrap gap-2">
                    <select class="select w-auto !h-8 text-xs" x-model="filters.severity" @change="loadData()">
                        <option value="">Any severity</option><option value="INFO">INFO</option><option value="WARN">WARN</option><option value="ERROR">ERROR</option><option value="CRITICAL">CRITICAL</option>
                    </select>
                    <span class="text-[11px] text-muted-foreground self-center">Click a row to open the war-room.</span>
                </div>
            </div>
            <div class="table-wrap !rounded-none !border-0">
                <table class="table">
                    <thead class="table-head"><tr>
                        <th class="table-head-th">Event</th>
                        <th class="table-head-th hidden md:table-cell">Alert</th>
                        <th class="table-head-th hidden lg:table-cell">Actor</th>
                        <th class="table-head-th hidden md:table-cell">Summary</th>
                        <th class="table-head-th text-right">When</th>
                    </tr></thead>
                    <tbody class="table-body">
                        <template x-if="loading"><tr><td colspan="5" class="table-cell text-center py-8 text-muted-foreground text-sm">Loading…</td></tr></template>
                        <template x-if="!loading && rows.length===0"><tr><td colspan="5" class="table-cell"><div class="empty-state"><p class="text-sm">No collaboration events.</p></div></td></tr></template>
                        <template x-for="row in rows" :key="row.id">
                            <tr class="table-row hover:bg-muted/20 cursor-pointer" @click="openWarRoom(row.alert_id)">
                                <td class="table-cell"><span class="badge" :class="codeBadge(row.event_code)" x-text="codeLabel(row.event_code)" :title="row.event_code"></span></td>
                                <td class="table-cell hidden md:table-cell text-[11.5px]">
                                    <div class="font-mono" x-text="row.alert_code"></div>
                                    <div class="text-muted-foreground truncate max-w-[180px]" x-text="row.alert_title"></div>
                                </td>
                                <td class="table-cell hidden lg:table-cell text-[11.5px]">
                                    <div x-text="row.actor_name || 'system'"></div>
                                    <div class="text-muted-foreground" x-text="row.actor_role"></div>
                                </td>
                                <td class="table-cell hidden md:table-cell text-[11.5px] truncate max-w-[280px]" x-text="row.summary"></td>
                                <td class="table-cell text-right text-[11px] text-muted-foreground" x-text="humanTime(row.created_at)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                <div class="p-3 flex justify-center" x-show="nextCursor">
                    <button class="btn btn-outline btn-sm" @click="loadMore()" :disabled="loadingMore">
                        <span x-show="!loadingMore">Load more</span><span x-show="loadingMore">…</span>
                    </button>
                </div>
            </div>
        </div>
    </section>

    {{-- ─── War-room sheet ─── --}}
    <template x-if="warRoom.open">
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="warRoom.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="warRoom.open=false"></div>
            <div class="relative w-full sm:max-w-3xl bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>

                <header class="flex items-center gap-3 px-5 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2v16z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">War Room</p>
                        <h2 class="text-[14px] font-bold truncate"><span class="font-mono text-[11px] text-muted-foreground" x-text="warRoom.alert?.alert_code"></span> · <span x-text="warRoom.alert?.alert_title"></span></h2>
                    </div>
                    <a class="btn btn-outline btn-xs" :href="'/admin/alerts?focus='+warRoom.alert?.id">Open dossier</a>
                    <button class="btn btn-ghost btn-icon-xs" @click="warRoom.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>

                <div class="border-b">
                    <div class="tabs-list w-full overflow-x-auto px-2">
                        <template x-for="t in warTabs" :key="t.key">
                            <button class="tabs-trigger flex-shrink-0" :data-state="warRoom.tab===t.key?'active':'inactive'" @click="warRoom.tab=t.key">
                                <span x-text="t.label"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="warBadge(t.key)" x-show="warBadge(t.key)!==null"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto px-5 py-5 space-y-5">
                    <template x-if="warRoom.loading"><p class="text-sm text-muted-foreground">Loading…</p></template>

                    {{-- ── Roster ── --}}
                    <template x-if="!warRoom.loading && warRoom.tab==='roster'">
                        <div class="space-y-3">
                            <button class="btn btn-brand btn-sm" @click="openAddCollab()">+ Add collaborator</button>
                            <ul class="divide-y rounded-md border bg-card">
                                <template x-if="(warRoom.collaborators||[]).length===0"><li class="px-3 py-3 text-[12px] text-muted-foreground">No collaborators yet.</li></template>
                                <template x-for="c in (warRoom.collaborators||[])" :key="c.id">
                                    <li class="px-3 py-2.5 flex items-center gap-2">
                                        <div class="grid place-items-center h-8 w-8 rounded-full bg-brand-soft text-brand-ink text-[11px] font-bold" x-text="(c.full_name||'?').slice(0,2).toUpperCase()"></div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-[12.5px] font-semibold" x-text="c.full_name || ('user #'+c.user_id)"></p>
                                            <p class="text-[10.5px] text-muted-foreground"><span class="badge badge-outline" x-text="c.role"></span> · <span x-text="c.role_key||''"></span></p>
                                        </div>
                                        <button x-show="c.is_active" class="btn btn-ghost btn-xs text-critical" @click="removeCollab(c)" title="Remove">×</button>
                                        <span x-show="!c.is_active" class="badge badge-outline">removed</span>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    {{-- ── Comments ── --}}
                    <template x-if="!warRoom.loading && warRoom.tab==='comments'">
                        <div class="space-y-3">
                            <div class="card !rounded-md">
                                <div class="card-content !p-3">
                                    <textarea class="input" rows="3" x-model="newComment.body" placeholder="Post an update…  (≤4000 chars)" maxlength="4000"></textarea>
                                    <div class="flex items-center gap-2 mt-2">
                                        <select class="select !h-7 text-xs" x-model="newComment.visibility">
                                            <option value="ALL">ALL</option><option value="INTERNAL">INTERNAL</option><option value="EXTERNAL">EXTERNAL</option>
                                        </select>
                                        <span class="text-[10.5px] text-muted-foreground" x-text="(newComment.body||'').length+'/4000'"></span>
                                        <div class="flex-1"></div>
                                        <button class="btn btn-brand btn-sm" :disabled="!(newComment.body||'').trim() || newComment.submitting" @click="postComment()">
                                            <span x-show="!newComment.submitting">Post</span><span x-show="newComment.submitting">…</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <ul class="divide-y rounded-md border bg-card">
                                <template x-if="(warRoom.comments||[]).length===0"><li class="px-3 py-3 text-[12px] text-muted-foreground">No comments.</li></template>
                                <template x-for="c in (warRoom.comments||[])" :key="c.id">
                                    <li class="px-3 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <span class="text-[11.5px] font-semibold" x-text="c.author_name || '?'"></span>
                                            <span class="badge badge-outline" x-text="c.author_role"></span>
                                            <span class="badge" :class="c.visibility==='ALL'?'badge-info':c.visibility==='INTERNAL'?'badge-warning':'badge-brand'" x-text="c.visibility"></span>
                                            <span x-show="c.is_pinned" class="badge badge-brand">pinned</span>
                                            <span class="ml-auto text-[10px] text-muted-foreground" x-text="humanTime(c.created_at)"></span>
                                        </div>
                                        <p class="text-[12.5px] mt-1 whitespace-pre-wrap" x-text="c.body"></p>
                                        <div class="flex gap-1 mt-1">
                                            <button class="btn btn-ghost btn-xs" @click="togglePin(c)" x-text="c.is_pinned?'Unpin':'Pin'"></button>
                                            <button class="btn btn-ghost btn-xs text-critical" @click="deleteComment(c)">Delete</button>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    {{-- ── Evidence ── --}}
                    <template x-if="!warRoom.loading && warRoom.tab==='evidence'">
                        <div class="space-y-3">
                            <form class="card !rounded-md" enctype="multipart/form-data" @submit.prevent="uploadEvidence($event)">
                                <div class="card-content !p-3 space-y-2">
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        <div>
                                            <label class="label">Title <span class="text-critical">*</span></label>
                                            <input class="input mt-1.5" name="title" required maxlength="200">
                                        </div>
                                        <div>
                                            <label class="label">Category</label>
                                            <select class="select mt-1.5" name="category">
                                                <option value="DOCUMENT">DOCUMENT</option><option value="PHOTO">PHOTO</option><option value="LAB_RESULT">LAB_RESULT</option><option value="CONSENT">CONSENT</option><option value="WHO_FORM">WHO_FORM</option><option value="CONTACT_LIST">CONTACT_LIST</option><option value="SOP_SIGN_OFF">SOP_SIGN_OFF</option><option value="PPE_CHECKLIST">PPE_CHECKLIST</option><option value="OTHER">OTHER</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="label">Description</label>
                                        <textarea class="input mt-1.5" rows="2" name="description" maxlength="1000"></textarea>
                                    </div>
                                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                        <div>
                                            <label class="label">File (≤25 MB · PDF / image / CSV / DOCX / XLSX)</label>
                                            <input class="input mt-1.5" type="file" name="file" accept=".pdf,.png,.jpg,.jpeg,.heic,.webp,.csv,.txt,.xlsx,.docx">
                                        </div>
                                        <div>
                                            <label class="label">…or external URL</label>
                                            <input class="input mt-1.5" name="external_url" placeholder="https://…">
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <select class="select !h-7 text-xs" name="visibility">
                                            <option value="ALL">ALL</option><option value="INTERNAL">INTERNAL</option><option value="EXTERNAL">EXTERNAL</option>
                                        </select>
                                        <div class="flex-1"></div>
                                        <button type="submit" class="btn btn-brand btn-sm" :disabled="evidenceSubmitting">
                                            <span x-show="!evidenceSubmitting">Upload</span><span x-show="evidenceSubmitting">…</span>
                                        </button>
                                    </div>
                                </div>
                            </form>
                            <ul class="divide-y rounded-md border bg-card">
                                <template x-if="(warRoom.evidence||[]).length===0"><li class="px-3 py-3 text-[12px] text-muted-foreground">No evidence attached.</li></template>
                                <template x-for="e in (warRoom.evidence||[])" :key="e.id">
                                    <li class="px-3 py-2.5">
                                        <div class="flex items-center gap-2">
                                            <span class="badge badge-outline" x-text="e.category"></span>
                                            <span class="text-[12.5px] font-semibold" x-text="e.title"></span>
                                            <span class="badge" :class="e.visibility==='ALL'?'badge-info':e.visibility==='INTERNAL'?'badge-warning':'badge-brand'" x-text="e.visibility"></span>
                                            <span x-show="e.is_external" class="badge badge-brand" title="Submitted via external responder portal">external</span>
                                            <span class="ml-auto text-[10px] text-muted-foreground" x-text="humanTime(e.created_at)"></span>
                                        </div>
                                        <p x-show="e.description" class="text-[11.5px] text-muted-foreground mt-1" x-text="e.description"></p>
                                        <div class="text-[10.5px] text-muted-foreground mt-1">
                                            <span x-show="e.file_ref">file · <span x-text="e.file_mime"></span> · <span x-text="(e.file_size_bytes/1024/1024).toFixed(2)+' MB'"></span></span>
                                            <span x-show="e.external_url">url · <a class="text-brand underline" :href="e.external_url" target="_blank" rel="noopener noreferrer" x-text="e.external_url"></a></span>
                                        </div>
                                        <div class="flex gap-1 mt-1">
                                            <button class="btn btn-ghost btn-xs text-critical" @click="deleteEvidence(e)">Delete</button>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>

                    {{-- ── Handoffs ── --}}
                    <template x-if="!warRoom.loading && warRoom.tab==='handoffs'">
                        <div class="space-y-3">
                            <button class="btn btn-brand btn-sm" @click="openHandoff()">+ New handoff</button>
                            <ul class="divide-y rounded-md border bg-card">
                                <template x-if="(warRoom.handoffs||[]).length===0"><li class="px-3 py-3 text-[12px] text-muted-foreground">No handoffs.</li></template>
                                <template x-for="h in (warRoom.handoffs||[])" :key="h.id">
                                    <li class="px-3 py-2.5 text-[12px]">
                                        <div class="flex items-center gap-2">
                                            <span class="badge" :class="handoffBadge(h.status)" x-text="h.status"></span>
                                            <span class="font-semibold" x-text="(h.from_name||'?')+' → '+(h.to_name||'?')"></span>
                                            <span class="ml-auto text-[10.5px] text-muted-foreground" x-text="humanTime(h.created_at)"></span>
                                        </div>
                                        <p class="text-[11.5px] mt-1" x-text="h.reason"></p>
                                        <p x-show="h.handoff_notes" class="text-[11.5px] text-muted-foreground mt-1" x-text="h.handoff_notes"></p>
                                        <div class="flex gap-1 mt-1" x-show="h.status==='SENT' || h.status==='ACKNOWLEDGED'">
                                            <button class="btn btn-outline btn-xs text-success" @click="decideHandoff(h, 'accept')">Accept</button>
                                            <button class="btn btn-outline btn-xs text-critical" @click="decideHandoff(h, 'reject')">Reject</button>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Add collaborator wizard ─── --}}
    <template x-if="addCollabWiz.open">
        <div class="fixed inset-0 z-[55] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="addCollabWiz.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="addCollabWiz.open=false"></div>
            <div class="relative w-full max-w-md card !rounded-xl !shadow-elevation-5 p-5" @click.stop>
                <h3 class="text-[14px] font-bold">Add collaborator</h3>
                <label class="label mt-3">User ID <span class="text-critical">*</span></label>
                <input type="number" min="1" class="input mt-1.5" x-model.number="addCollabWiz.form.user_id" placeholder="users.id">
                <label class="label mt-3">Role <span class="text-critical">*</span></label>
                <select class="select mt-1.5" x-model="addCollabWiz.form.role">
                    <option value="">Select…</option>
                    <option value="INCIDENT_COMMANDER">INCIDENT_COMMANDER</option><option value="CASE_OWNER">CASE_OWNER</option>
                    <option value="CLINICAL_LEAD">CLINICAL_LEAD</option><option value="LAB_LIAISON">LAB_LIAISON</option>
                    <option value="DISTRICT_LIAISON">DISTRICT_LIAISON</option><option value="PHEOC_LIAISON">PHEOC_LIAISON</option>
                    <option value="NATIONAL_LIAISON">NATIONAL_LIAISON</option><option value="WHO_LIAISON">WHO_LIAISON</option>
                    <option value="CONTACT_TRACER">CONTACT_TRACER</option><option value="RISK_COMMS">RISK_COMMS</option>
                    <option value="LOGISTICS">LOGISTICS</option><option value="OBSERVER">OBSERVER</option>
                </select>
                <label class="label mt-3">Notes</label>
                <textarea class="input mt-1.5" rows="2" x-model="addCollabWiz.form.notes" maxlength="500"></textarea>
                <div class="flex justify-end gap-2 mt-5">
                    <button class="btn btn-outline btn-sm" @click="addCollabWiz.open=false">Cancel</button>
                    <button class="btn btn-brand btn-sm" :disabled="!addCollabWiz.form.user_id || !addCollabWiz.form.role || addCollabWiz.submitting" @click="addCollab()">Add</button>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Handoff wizard ─── --}}
    <template x-if="handoffWiz.open">
        <div class="fixed inset-0 z-[55] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="handoffWiz.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="handoffWiz.open=false"></div>
            <div class="relative w-full max-w-md card !rounded-xl !shadow-elevation-5 p-5" @click.stop>
                <h3 class="text-[14px] font-bold">Create handoff</h3>
                <div class="grid grid-cols-2 gap-2 mt-3">
                    <div>
                        <label class="label">From level <span class="text-critical">*</span></label>
                        <select class="select mt-1.5" x-model="handoffWiz.form.from_level">
                            <option value="">Select…</option><option value="DISTRICT">DISTRICT</option><option value="PHEOC">PHEOC</option><option value="NATIONAL">NATIONAL</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">To level <span class="text-critical">*</span></label>
                        <select class="select mt-1.5" x-model="handoffWiz.form.to_level">
                            <option value="">Select…</option><option value="DISTRICT">DISTRICT</option><option value="PHEOC">PHEOC</option><option value="NATIONAL">NATIONAL</option>
                        </select>
                    </div>
                </div>
                <label class="label mt-3">Target user ID (optional)</label>
                <input type="number" min="1" class="input mt-1.5" x-model.number="handoffWiz.form.to_user_id">
                <label class="label mt-3">Reason <span class="text-critical">*</span></label>
                <textarea class="input mt-1.5" rows="2" x-model="handoffWiz.form.reason" maxlength="500"></textarea>
                <label class="label mt-3">Notes (optional)</label>
                <textarea class="input mt-1.5" rows="2" x-model="handoffWiz.form.handoff_notes"></textarea>
                <div class="flex justify-end gap-2 mt-5">
                    <button class="btn btn-outline btn-sm" @click="handoffWiz.open=false">Cancel</button>
                    <button class="btn btn-brand btn-sm" :disabled="!(handoffWiz.form.from_level && handoffWiz.form.to_level && handoffWiz.form.reason) || handoffWiz.submitting" @click="createHandoff()">Send</button>
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
function caseRoom(){
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
    const headersJson=()=>({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf()});
    const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null&&v!==0&&v!=='0'&&!(Array.isArray(v)&&v.length===0)).map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(Array.isArray(v)?v.join(','):v)).join('&');

    return {
        codes:[], counters:{},
        filters:{codes:[], severity:'', q:''},
        rows:[], loading:false, loadingMore:false, nextCursor:null,
        warRoom:{open:false, loading:false, tab:'roster', alert:null, collaborators:[], comments:[], evidence:[], handoffs:[], counters:{}},
        newComment:{body:'', visibility:'ALL', submitting:false},
        evidenceSubmitting:false,
        addCollabWiz:{open:false, submitting:false, form:{user_id:0, role:'', notes:''}},
        handoffWiz:{open:false, submitting:false, form:{from_level:'', to_level:'', to_user_id:0, reason:'', handoff_notes:''}},
        opToast:{open:false,kind:'success',title:'',body:'',t:null},

        get warTabs(){
            return [
                {key:'roster',    label:'Roster'},
                {key:'comments',  label:'Comments'},
                {key:'evidence',  label:'Evidence'},
                {key:'handoffs',  label:'Handoffs'},
            ];
        },
        warBadge(k){
            if(!this.warRoom.alert) return null;
            const c = this.warRoom.counters || {};
            switch(k){
                case 'roster':   return c.collaborators_active ?? null;
                case 'comments': return c.comments ?? null;
                case 'evidence': return c.evidence ?? null;
                case 'handoffs': return c.handoffs_open ?? null;
            }
            return null;
        },

        async boot(){
            await this.loadData();
            const url = new URL(window.location.href);
            const aid = parseInt(url.searchParams.get('alert_id') || '0', 10);
            if(aid > 0) this.openWarRoom(aid);
            window.addEventListener('alert-coach:wizard', e => {
                if (!e?.detail || e.detail.view !== 'caseroom') return;
                this.handleWizard(e.detail.code);
            });
        },

        handleWizard(code){
            // Each option filters the cross-case stream to a relevant slice;
            // the operator clicks a row to open a specific case room.
            switch (code) {
                case 'COMMENT':    this.filters.codes = (this.codes || []).filter(c => c.startsWith('COMMENT_'));      this.loadData(); break;
                case 'UPLOAD':     this.filters.codes = (this.codes || []).filter(c => c.startsWith('EVIDENCE_'));     this.loadData(); break;
                case 'ADD_PERSON':
                case 'REMOVE':     this.filters.codes = (this.codes || []).filter(c => c.startsWith('COLLABORATOR_')); this.loadData(); break;
                case 'WALK_NEW':
                    if (this.rows[0]) window.location.href = '/admin/alerts/' + this.rows[0].alert_id + '/case-file';
                    else this.toast('error','Pick a case','No recent activity to walk through.');
                    break;
            }
        },

        codeLabel(c){
            return ({
                COLLABORATOR_ADDED:   'Someone joined',
                COLLABORATOR_UPDATED: 'Role changed',
                COLLABORATOR_REMOVED: 'Someone left',
                COMMENT_POSTED:       'Comment posted',
                COMMENT_EDITED:       'Comment edited',
                COMMENT_DELETED:      'Comment removed',
                COMMENT_PINNED:       'Comment pinned',
                EVIDENCE_UPLOADED:    'Evidence added',
                EVIDENCE_DELETED:     'Evidence removed',
                HANDOFF_SENT:         'Handoff sent',
                HANDOFF_ACKNOWLEDGED: 'Handoff seen',
                HANDOFF_ACCEPTED:     'Handoff accepted',
                HANDOFF_REJECTED:     'Handoff rejected',
                HANDOFF_RECALLED:     'Handoff withdrawn',
            })[c] || c.replace(/^(COLLABORATOR_|COMMENT_|EVIDENCE_|HANDOFF_)/, '').toLowerCase().replace(/_/g, ' ');
        },

        toggleCode(c){
            if(this.filters.codes.includes(c)){ this.filters.codes = this.filters.codes.filter(x=>x!==c); }
            else { this.filters.codes.push(c); }
            this.loadData();
        },

        async loadData(){
            this.loading=true; this.nextCursor=null;
            try{
                const params = {event_code:this.filters.codes, severity:this.filters.severity, q:this.filters.q};
                const r = await fetch('/admin/alerts/case-room/data?'+qs(params), {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.success){
                    this.rows = j.data.rows;
                    this.nextCursor = j.data.next_cursor;
                    this.codes = j.meta.codes;
                    this.counters = j.meta.counters;
                    if(this.filters.codes.length===0) this.filters.codes = [...this.codes];
                    if(window.Alpine && Alpine.store('pageMeta')){ Alpine.store('pageMeta').rows=j.data.count; Alpine.store('pageMeta').kind='alert-caseroom'; }
                }
            } finally { this.loading=false; }
        },
        async loadMore(){
            if(!this.nextCursor) return;
            this.loadingMore=true;
            try{
                const params = {event_code:this.filters.codes, severity:this.filters.severity, q:this.filters.q, cursor:this.nextCursor};
                const r = await fetch('/admin/alerts/case-room/data?'+qs(params), {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.success){ this.rows = this.rows.concat(j.data.rows); this.nextCursor=j.data.next_cursor; }
            } finally { this.loadingMore=false; }
        },

        async openWarRoom(alertId){
            this.warRoom={open:true, loading:true, tab:'roster', alert:null, collaborators:[], comments:[], evidence:[], handoffs:[], counters:{}};
            try{
                const r = await fetch('/admin/alerts/case-room/data?alert_id='+alertId, {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.success){
                    Object.assign(this.warRoom, {loading:false, alert:j.data.alert, collaborators:j.data.collaborators, comments:j.data.comments, evidence:j.data.evidence, handoffs:j.data.handoffs, counters:j.data.counters});
                } else { this.warRoom.loading=false; this.toast('error','Load failed', j.message); }
            } catch(e){ this.warRoom.loading=false; this.toast('error','Network', e.message); }
        },
        async refreshWar(){ if(this.warRoom.alert) await this.openWarRoom(this.warRoom.alert.id); },

        // ─── Roster ───
        openAddCollab(){ this.addCollabWiz={open:true, submitting:false, form:{user_id:0, role:'', notes:''}}; },
        async addCollab(){
            this.addCollabWiz.submitting=true;
            try{
                const r=await fetch('/admin/alerts/'+this.warRoom.alert.id+'/collaborators',{method:'POST',headers:headersJson(),body:JSON.stringify(this.addCollabWiz.form)});
                const j=await r.json();
                if(j.success){ this.toast('success','Added','Collaborator joined.'); this.addCollabWiz.open=false; await this.refreshWar(); }
                else this.toast('error','Failed', j.message);
            } catch(e){ this.toast('error','Network', e.message); } finally{ this.addCollabWiz.submitting=false; }
        },
        async removeCollab(c){
            if(!confirm('Remove '+(c.full_name||'this collaborator')+'?')) return;
            try{
                const r=await fetch('/admin/alerts/collaborators/'+c.id,{method:'DELETE',headers:headersJson()});
                const j=await r.json();
                if(j.success){ this.toast('success','Removed','Collaborator removed.'); await this.refreshWar(); }
                else this.toast('error','Failed', j.message);
            } catch(e){ this.toast('error','Network', e.message); }
        },

        // ─── Comments ───
        async postComment(){
            if(!(this.newComment.body||'').trim()) return;
            this.newComment.submitting=true;
            try{
                const r=await fetch('/admin/alerts/'+this.warRoom.alert.id+'/comments',{method:'POST',headers:headersJson(),body:JSON.stringify({body:this.newComment.body, visibility:this.newComment.visibility})});
                const j=await r.json();
                if(j.success){ this.newComment.body=''; await this.refreshWar(); }
                else this.toast('error','Failed', j.message);
            } catch(e){ this.toast('error','Network', e.message); } finally{ this.newComment.submitting=false; }
        },
        async togglePin(c){
            try{
                const r=await fetch('/admin/alerts/comments/'+c.id+'/pin',{method:'POST',headers:headersJson()});
                const j=await r.json();
                if(j.success){ await this.refreshWar(); } else this.toast('error','Failed', j.message);
            } catch(e){ this.toast('error','Network', e.message); }
        },
        async deleteComment(c){
            if(!confirm('Delete this comment?')) return;
            try{
                const r=await fetch('/admin/alerts/comments/'+c.id,{method:'DELETE',headers:headersJson()});
                const j=await r.json();
                if(j.success){ await this.refreshWar(); } else this.toast('error','Failed', j.message);
            } catch(e){ this.toast('error','Network', e.message); }
        },

        // ─── Evidence ───
        async uploadEvidence(ev){
            this.evidenceSubmitting=true;
            try{
                const fd = new FormData(ev.target);
                const r = await fetch('/admin/alerts/'+this.warRoom.alert.id+'/evidence',{method:'POST',headers:{'X-CSRF-TOKEN':csrf(),'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},body:fd});
                const j = await r.json();
                if(j.success){ this.toast('success','Uploaded','Evidence attached.'); ev.target.reset(); await this.refreshWar(); }
                else this.toast('error','Failed', j.message);
            } catch(e){ this.toast('error','Network', e.message); } finally{ this.evidenceSubmitting=false; }
        },
        async deleteEvidence(e){
            if(!confirm('Delete "'+e.title+'"?')) return;
            try{
                const r=await fetch('/admin/alerts/evidence/'+e.id,{method:'DELETE',headers:headersJson()});
                const j=await r.json();
                if(j.success){ await this.refreshWar(); } else this.toast('error','Failed', j.message);
            } catch(err){ this.toast('error','Network', err.message); }
        },

        // ─── Handoffs ───
        openHandoff(){ this.handoffWiz={open:true, submitting:false, form:{from_level:'', to_level:'', to_user_id:0, reason:'', handoff_notes:''}}; },
        async createHandoff(){
            this.handoffWiz.submitting=true;
            try{
                const body={...this.handoffWiz.form}; if(!body.to_user_id) delete body.to_user_id;
                const r=await fetch('/admin/alerts/'+this.warRoom.alert.id+'/handoffs',{method:'POST',headers:headersJson(),body:JSON.stringify(body)});
                const j=await r.json();
                if(j.success){ this.toast('success','Handoff sent','Awaiting acknowledgement.'); this.handoffWiz.open=false; await this.refreshWar(); }
                else this.toast('error','Failed', j.message);
            } catch(e){ this.toast('error','Network', e.message); } finally{ this.handoffWiz.submitting=false; }
        },
        async decideHandoff(h, decision){
            const reason = decision==='reject' ? (prompt('Rejection reason (≥5 chars):') || '') : '';
            if(decision==='reject' && reason.length<5){ this.toast('error','Rejection reason required','Min 5 chars.'); return; }
            try{
                const url = '/admin/alerts/handoffs/'+h.id+'/'+decision;
                const body = decision==='reject' ? JSON.stringify({reason}) : '{}';
                const r=await fetch(url,{method:'POST',headers:headersJson(),body});
                const j=await r.json();
                if(j.success){ await this.refreshWar(); } else this.toast('error','Failed', j.message);
            } catch(e){ this.toast('error','Network', e.message); }
        },

        // ─── helpers ───
        codeBadge(c){
            if(c.startsWith('COMMENT_')) return 'badge-info';
            if(c.startsWith('EVIDENCE_')) return 'badge-brand';
            if(c.startsWith('COLLABORATOR_')) return 'badge-outline';
            if(c==='HANDOFF_ACCEPTED') return 'badge-success';
            if(c==='HANDOFF_REJECTED'||c==='HANDOFF_RECALLED') return 'badge-critical';
            return 'badge-warning';
        },
        handoffBadge(s){ return ({SENT:'badge-info',ACKNOWLEDGED:'badge-info',ACCEPTED:'badge-success',REJECTED:'badge-critical',RECALLED:'badge-outline'})[s]||'badge-outline'; },
        humanTime(t){ if(!t) return '—'; try{ return new Date(t).toLocaleString(); } catch(e){ return t; } },
        toast(kind,title,body){ this.opToast={open:true,kind,title,body,t:null}; clearTimeout(this.opToast.t); this.opToast.t=setTimeout(()=>{this.opToast.open=false;},3500); },
    };
}
</script>
@endpush
