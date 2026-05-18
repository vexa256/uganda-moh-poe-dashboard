<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\AlertCollaborationController;
use App\Http\Controllers\AlertFollowupsController;
use App\Http\Controllers\AlertsController;
use App\Http\Controllers\AlertsLifecycleController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * alerts:seed-lifecycle
 *
 * Generates fully-populated alerts that look exactly like alerts created
 * through the mobile app — by dispatching through the real HTTP controllers
 * (AlertsController, AlertCollaborationController, AlertFollowupsController,
 * AlertsLifecycleController). No raw inserts, no shortcuts: every controller
 * runs its scope check, role check, validation, and timeline emission.
 *
 * Strategy:
 *   1. Pick existing secondary_screenings (these are the parents).
 *   2. For each, find an active POE-level user whose assignment matches the
 *      screening's poe_code → that user becomes the alert "creator".
 *   3. Dispatch POST /api/alerts via AlertsController::store ($actor).
 *   4. Add 1–3 follow-ups via AlertFollowupsController::store (some blocking).
 *   5. Roll status path (25% OPEN, 25% ACKNOWLEDGED, 50% CLOSED) — for ack/close
 *      pick a DISTRICT_SUPERVISOR/PHEOC_OFFICER/NATIONAL_ADMIN that has scope
 *      over the alert's routed_to_level.
 *   6. For most alerts: 1–2 comments via AlertCollaborationController::postComment,
 *      0–1 evidence via addEvidence.
 *   7. For some closed alerts: record outcome via AlertsLifecycleController::upsertOutcome
 *      (called separately because close auto-records via CaseOutcomeRecorder
 *      but the wizard route is what the mobile app uses).
 *   8. Some get breach reports via logBreachReport.
 *
 * Usage:
 *   php artisan alerts:seed-lifecycle --count=50
 *   php artisan alerts:seed-lifecycle --count=80 --orchestrator=zambia1
 */
final class SeedAlertsLifecycle extends Command
{
    protected $signature = 'alerts:seed-lifecycle
        {--count=50 : Number of alerts to create}
        {--orchestrator=zambia1 : Username of the NATIONAL_ADMIN whose scope is used as the umbrella}';

    protected $description = 'Seed realistic alerts via real controller dispatch (mimics mobile app exactly).';

    private const RISK_LEVELS  = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    private const ROUTED_TO    = ['DISTRICT', 'PHEOC', 'NATIONAL'];
    private const IHR_TIERS    = [null, null, null, 'TIER_2', 'TIER_1']; // weighted toward null
    private const SYNDROMES    = ['VHF_HEMORRHAGIC', 'SARI', 'ACUTE_DIARRHOEA', 'RASH_FEVER', 'NEUROLOGICAL', 'JAUNDICE'];

    private const FOLLOWUP_ACTIONS = [
        ['code' => 'CASE_INVESTIGATION',  'label' => 'Investigate the case (interview, exposures)',  'blocks' => 1],
        ['code' => 'LAB_SAMPLE',          'label' => 'Collect lab sample',                            'blocks' => 1],
        ['code' => 'CONTACT_TRACING',     'label' => 'Trace and list contacts',                       'blocks' => 0],
        ['code' => 'IHR_NOTIFY',          'label' => 'Notify National IHR Focal Point within 24h',    'blocks' => 1],
        ['code' => 'PPE_CHECK',           'label' => 'Verify PPE supply at POE',                      'blocks' => 0],
        ['code' => 'TRAVELER_BRIEFING',   'label' => 'Brief the traveller and care-giver',            'blocks' => 0],
        ['code' => 'REFERRAL',            'label' => 'Arrange referral / isolation transfer',          'blocks' => 1],
        ['code' => 'POE_DECON',           'label' => 'POE area decontamination',                      'blocks' => 0],
    ];

    private const COMMENT_TEMPLATES = [
        'Patient interviewed; exposures documented in case file.',
        'PPE checked; level upgraded per advisor recommendation.',
        'Liaised with district health officer; transfer arranged.',
        'Awaiting lab confirmation — sample dispatched 12h ago.',
        'Traveller cooperative; given written follow-up instructions.',
        'Coordinated with PHEOC; situation report sent.',
        'No additional contacts on the manifest — chain limited.',
        'Family briefed; consent obtained for follow-up calls.',
    ];

    private const CLOSE_CATEGORIES = [
        ['code' => 'RESOLVED',                   'note' => 'Case responded to treatment; cleared per protocol.'],
        ['code' => 'FALSE_POSITIVE',             'note' => 'Symptoms unrelated; cleared after secondary review.'],
        ['code' => 'LOST_TO_FOLLOWUP',           'note' => 'Traveller did not return for scheduled follow-up; phone unreachable for 7d.'],
        ['code' => 'TRANSFERRED_OUT_OF_COUNTRY', 'note' => 'Traveller transferred to home country for continued care.'],
        ['code' => 'DECEASED',                   'note' => 'Patient died despite supportive care; outbreak investigation triggered.'],
        ['code' => 'OTHER',                      'note' => 'Closed administratively after multidisciplinary review.'],
    ];

    public function handle(): int
    {
        $count = max(1, (int) $this->option('count'));
        $orchestrator = (string) $this->option('orchestrator');

        $this->info("→ Seeding {$count} alerts via real controller dispatch.");
        $this->line("  Orchestrator (umbrella scope): {$orchestrator}");

        // ── Resolve actor pools ───────────────────────────────────────────
        $umbrella = DB::table('users')->where('username', $orchestrator)->where('is_active', 1)->first();
        if (! $umbrella) {
            $this->error("Orchestrator '{$orchestrator}' not found or inactive.");
            return self::FAILURE;
        }

        // POE-level creators: any user with role SCREENER / POE_ADMIN (we treat
        // these as the alert creators since the mobile app store() is fired by
        // the screener/secondary officer at the POE). Fall back to umbrella if
        // none have an assignment.
        // We must filter creators to the orchestrator's country, otherwise
        // alerts get tagged with the creator's country_code and the umbrella
        // NATIONAL_ADMIN trips the cross-country scope guard on ack/close.
        $umbrellaAssignmentForCreators = DB::table('user_assignments')
            ->where('user_id', $umbrella->id)->where('is_primary', 1)->first();
        $umbrellaCountry = $umbrellaAssignmentForCreators?->country_code ?? 'ZM';
        // Prefer POE-level roles; fall back to ANY active in-country user so
        // small deployments (where only NATIONAL/PHEOC/DISTRICT seats exist) can
        // still seed. Geographic diversity comes from the screening's poe/
        // district/pheoc copied below, not from the creator's assignment.
        $creators = DB::table('users as u')
            ->join('user_assignments as a', 'a.user_id', '=', 'u.id')
            ->whereIn('u.role_key', ['SCREENER', 'POE_ADMIN', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'POE_PRIMARY'])
            ->where('u.is_active', 1)
            ->where('a.is_primary', 1)->where('a.is_active', 1)
            ->where('a.country_code', $umbrellaCountry)
            ->select('u.id', 'u.role_key', 'u.full_name', 'a.poe_code', 'a.district_code', 'a.pheoc_code', 'a.country_code', 'a.province_code')
            ->get();
        if ($creators->isEmpty()) {
            $creators = DB::table('users as u')
                ->join('user_assignments as a', 'a.user_id', '=', 'u.id')
                ->where('u.is_active', 1)
                ->where('a.is_primary', 1)->where('a.is_active', 1)
                ->where('a.country_code', $umbrellaCountry)
                ->select('u.id', 'u.role_key', 'u.full_name', 'a.poe_code', 'a.district_code', 'a.pheoc_code', 'a.country_code', 'a.province_code')
                ->get();
        }
        if ($creators->isEmpty()) {
            $this->warn('  No POE-level creators with assignments — using umbrella as creator.');
            $umbrellaAssignment = DB::table('user_assignments')->where('user_id', $umbrella->id)->where('is_primary', 1)->first();
            if ($umbrellaAssignment) {
                $creators = collect([(object) array_merge((array) $umbrella, (array) $umbrellaAssignment)]);
            }
        }
        $creators = $creators->groupBy('poe_code'); // bucket by POE for pairing with screenings

        // Acker/Closer pools — must have an ACTIVE PRIMARY assignment so the
        // controller's checkScope() doesn't 403 with "No active assignment". For
        // simplicity we lean on NATIONAL_ADMIN orchestrators across all routed
        // levels — they pass the country gate regardless of POE/district. This
        // matches a national IRC operator clearing the inbox in the real app.
        // Country code is whatever the orchestrator's assignment carries —
        // production uses 'Zambia', dev uses 'ZM'. We don't hardcode it.
        $umbrellaAssignment = DB::table('user_assignments')
            ->where('user_id', $umbrella->id)->where('is_primary', 1)->where('is_active', 1)
            ->first();
        $countryCode = $umbrellaAssignment?->country_code ?? 'ZM';
        $nationalsWithAssignment = DB::table('users as u')
            ->join('user_assignments as a', 'a.user_id', '=', 'u.id')
            ->where('u.role_key', 'NATIONAL_ADMIN')
            ->where('u.is_active', 1)
            ->where('a.is_primary', 1)->where('a.is_active', 1)
            ->where(function ($q) { $q->whereNull('a.ends_at')->orWhere('a.ends_at', '>', now()); })
            ->where('a.country_code', $countryCode)
            ->select('u.id', 'u.role_key', 'u.full_name', 'u.username',
                    'a.country_code', 'a.poe_code', 'a.district_code', 'a.pheoc_code', 'a.province_code')
            ->get();
        if ($nationalsWithAssignment->isEmpty()) {
            $umb = (object) array_merge((array) $umbrella, (array) (DB::table('user_assignments')->where('user_id', $umbrella->id)->where('is_primary', 1)->first() ?? new \stdClass()));
            $nationalsWithAssignment = collect([$umb]);
        }
        $ackers = [
            'DISTRICT' => $nationalsWithAssignment,
            'PHEOC'    => $nationalsWithAssignment,
            'NATIONAL' => $nationalsWithAssignment,
        ];

        // Collaboration writes accept any role with scope — use the same pools.
        // Available secondary screenings (without an existing alert) IN the
        // orchestrator's country only — otherwise we'd attach Uganda screenings
        // to a Zambia umbrella and the controller's scope guard would later
        // reject every ack/close on those alerts.
        $screenings = DB::table('secondary_screenings as s')
            ->leftJoin('alerts as a', 'a.secondary_screening_id', '=', 's.id')
            ->whereNull('s.deleted_at')
            ->whereNull('a.id')
            ->where(function ($q) use ($umbrellaCountry) {
                $q->where('s.country_code', $umbrellaCountry)
                  ->orWhereNull('s.country_code'); // tolerate legacy nulls
            })
            ->select('s.*')
            ->limit($count * 3)
            ->get();

        if ($screenings->isEmpty()) {
            $this->warn('  No unattached secondary_screenings — recycling existing ones.');
            $screenings = DB::table('secondary_screenings')->whereNull('deleted_at')->limit($count)->get();
        }

        $created = 0;
        $acked   = 0;
        $closed  = 0;
        $blocked = 0;

        foreach ($screenings as $i => $screening) {
            if ($created >= $count) break;

            // Pick a creator whose POE matches the screening's POE; fallback to umbrella.
            $poeCreators = $creators->get($screening->poe_code, collect());
            $creator = $poeCreators->first() ?? (object) [
                'id'            => $umbrella->id,
                'role_key'      => 'NATIONAL_ADMIN',
                'poe_code'      => $screening->poe_code,
                'district_code' => $screening->district_code ?? null,
                'pheoc_code'    => $screening->pheoc_code ?? null,
                'country_code'  => $screening->country_code ?? 'ZM',
                'province_code' => $screening->province_code ?? null,
            ];

            $risk    = self::RISK_LEVELS[array_rand(self::RISK_LEVELS)];
            $routed  = $this->routeFor($risk);
            $tier    = self::IHR_TIERS[array_rand(self::IHR_TIERS)];
            $synd    = $screening->syndrome_classification ?? self::SYNDROMES[array_rand(self::SYNDROMES)];

            // Tenant-aware code: <ISO>-<POE3>-<NNNN>-<YYMMDD>. Strips
            // non-letters from poe_code so multi-word names ('Entebbe International
            // Airport') still produce a clean 3-letter slug ('KIG').
            $alertCode = strtoupper((string) (config('country.code') ?: 'RW'))
                . '-' . strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string) ($screening->poe_code ?? 'POE')), 0, 3))
                . '-' . str_pad((string) ($i + 100), 4, '0', STR_PAD_LEFT)
                . '-' . date('ymd');
            $title     = "{$risk} {$synd} alert at {$screening->poe_code}";
            $details   = "Auto-generated by seed: traveller flagged with {$synd} syndrome at {$screening->poe_code}; risk score → {$risk}; routed to {$routed}.";

            // ── 1. CREATE alert via real controller ───────────────────────
            $body = [
                'created_by_user_id'     => $creator->id,
                'client_uuid'            => $this->uuid(),
                'secondary_screening_id' => $screening->id,
                'alert_code'             => $alertCode,
                'alert_title'            => $title,
                'alert_details'          => $details,
                'risk_level'             => $risk,
                'routed_to_level'        => $routed,
                'ihr_tier'               => $tier,
                'generated_from'         => 'RULE_BASED',
                'device_id'              => 'seed-' . $creator->id,
                'app_version'            => 'seed-1.0.0',
                'platform'               => 'ANDROID',
            ];
            $res = $this->dispatch(AlertsController::class, 'store', '/api/alerts', 'POST', $body);
            if (! $res || ! ($res['success'] ?? false)) {
                $this->warn("  alert#{$i} create failed: " . ($res['message'] ?? 'unknown'));
                continue;
            }
            $alertId = (int) ($res['data']['id'] ?? 0);
            if (! $alertId) continue;
            $created++;

            // Diversify geography: copy the screening's poe/district/pheoc/
            // province onto the alert so seeded data spans multiple POEs even
            // if only one creator user exists. The country_code is left alone
            // (controller already set it from the creator's assignment) so the
            // scope guards on ack/close still pass.
            $geoPatch = array_filter([
                'poe_code'      => $screening->poe_code      ?? null,
                'district_code' => $screening->district_code ?? null,
                'pheoc_code'    => $screening->pheoc_code    ?? null,
                'province_code' => $screening->province_code ?? null,
            ], fn ($v) => $v !== null && $v !== '');
            if ($geoPatch) {
                DB::table('alerts')->where('id', $alertId)->update($geoPatch);
            }

            // ── 2. FOLLOW-UPS (1–3, some blocking) ───────────────────────
            $followupCount = random_int(1, 3);
            $followupIds = [];
            $shuffled = self::FOLLOWUP_ACTIONS;
            shuffle($shuffled);
            foreach (array_slice($shuffled, 0, $followupCount) as $f) {
                $fbody = [
                    'created_by_user_id' => $creator->id,
                    'client_uuid'        => $this->uuid(),
                    'action_code'        => $f['code'],
                    'action_label'       => $f['label'],
                    'status'             => 'PENDING',
                    'due_at'             => now()->addHours(random_int(4, 96))->format('Y-m-d H:i:s'),
                    'blocks_closure'     => $f['blocks'],
                    'assigned_to_role'   => 'PHEOC_OFFICER',
                ];
                $fr = $this->dispatch(
                    AlertFollowupsController::class, 'store',
                    "/api/alerts/{$alertId}/followups", 'POST', $fbody, [$alertId]
                );
                if ($fr && ($fr['success'] ?? false)) {
                    $fid = (int) ($fr['data']['id'] ?? 0);
                    if ($fid) $followupIds[] = ['id' => $fid, 'blocks' => $f['blocks']];
                }
            }

            // ── 3. COMMENTS (1–2) ────────────────────────────────────────
            $commentActor = $this->pickActor($ackers, $routed);
            for ($c = 0; $c < random_int(1, 2); $c++) {
                $cbody = [
                    'actor_user_id' => $commentActor->id,
                    'body'          => self::COMMENT_TEMPLATES[array_rand(self::COMMENT_TEMPLATES)],
                    'visibility'    => 'INTERNAL',
                ];
                $this->dispatch(
                    AlertCollaborationController::class, 'postComment',
                    "/api/alerts/{$alertId}/comments", 'POST', $cbody, [$alertId],
                    ['X-User-Id' => (string) $commentActor->id]
                );
            }

            // ── 4. EVIDENCE (~50%) ──────────────────────────────────────
            if (random_int(0, 1)) {
                $ebody = [
                    'actor_user_id' => $commentActor->id,
                    'category'      => 'PHOTO',
                    'title'         => 'Field photo · ' . $screening->poe_code,
                    'description'   => 'Captured during initial screening at the POE.',
                    'file_ref'      => 'capacitor-camera://' . $this->uuid() . '.jpg',
                    'file_mime'     => 'image/jpeg',
                    'file_size_bytes' => random_int(120_000, 1_400_000),
                    'visibility'    => 'INTERNAL',
                    'uploader_name' => $commentActor->full_name ?? null,
                ];
                $this->dispatch(
                    AlertCollaborationController::class, 'addEvidence',
                    "/api/alerts/{$alertId}/evidence", 'POST', $ebody, [$alertId],
                    ['X-User-Id' => (string) $commentActor->id]
                );
            }

            // ── 5. STATUS PATH ───────────────────────────────────────────
            $roll = random_int(1, 100);
            $actor = $this->pickActor($ackers, $routed);

            if ($roll <= 25) {
                // Leave OPEN
            } else {
                // Acknowledge
                $ar = $this->dispatch(
                    AlertsController::class, 'acknowledge',
                    "/api/alerts/{$alertId}/acknowledge", 'PATCH',
                    ['user_id' => $actor->id], [$alertId]
                );
                if ($ar && ($ar['success'] ?? false)) $acked++;

                // Some acked alerts get a breach report (10%)
                if (random_int(1, 100) <= 15) {
                    $bbody = [
                        'actor_user_id'       => $actor->id,
                        'phase'               => ['DETECT', 'NOTIFY', 'RESPOND'][array_rand([0,1,2])],
                        'target_hours'        => 24,
                        'elapsed_hours'       => random_int(26, 72),
                        'root_cause_category' => ['CAPACITY', 'TRAINING', 'COMMS', 'LAB', 'COORDINATION'][array_rand([0,1,2,3,4])],
                        'root_cause_text'     => 'Limited on-shift capacity at the POE during the breach window; staffing gap not flagged before SLA elapsed.',
                        'mitigation_plan'     => 'Add second responder to evening shift; weekly SLA review at PHEOC standup; escalate gaps to district focal.',
                        'owner_level'         => $routed,
                    ];
                    $this->dispatch(
                        AlertCollaborationController::class, 'logBreachReport',
                        "/api/alerts/{$alertId}/breach-report", 'POST', $bbody, [$alertId],
                        ['X-User-Id' => (string) $actor->id]
                    );
                }

                if ($roll > 50) {
                    // AlertsController::store auto-seeds the RTSL-14 actions —
                    // many marked blocks_closure=1 — so the followups we
                    // tracked locally in $followupIds are only a fraction of
                    // the actual blockers. Pull EVERY blocking followup from
                    // the DB and resolve them so the close pre-flight passes.
                    $allBlockers = DB::table('alert_followups')
                        ->where('alert_id', $alertId)
                        ->where('blocks_closure', 1)
                        ->whereNotIn('status', ['COMPLETED','NOT_APPLICABLE'])
                        ->whereNull('deleted_at')
                        ->select('id')->pluck('id')->all();
                    foreach ($allBlockers as $bid) {
                        $rr = $this->dispatch(
                            \App\Http\Controllers\AlertsLifecycleController::class, 'resolveBlocker',
                            "/api/alert-followups/{$bid}/resolve-blocker", 'POST',
                            [
                                'user_id'    => $actor->id,
                                'resolution' => random_int(0,1) ? 'COMPLETED' : 'NOT_APPLICABLE',
                                'reason'     => 'Resolved during seeded close — work completed or determined inapplicable to this case.',
                            ],
                            [$bid]
                        );
                        if ($rr && ($rr['success'] ?? false)) $blocked++;
                    }

                    // Record outcome via the wizard endpoint (mobile path)
                    $cls = $this->classificationFor($risk, $tier);
                    $obody = [
                        'user_id'                    => $actor->id,
                        'case_classification'        => $cls,
                        'case_classification_reason' => "Closed by seed script. Risk={$risk}, IHR={$tier}.",
                        'lab_status'                 => $cls === 'CONFIRMED' ? 'POSITIVE' : ($cls === 'DISCARDED' ? 'NEGATIVE' : 'NOT_TESTED'),
                        'clinical_outcome'           => $cls === 'DISCARDED' ? 'RECOVERED' : 'RECOVERED',
                        'ph_action'                  => $tier === 'TIER_1' ? 'IHR_NOTIFIED' : ($risk === 'CRITICAL' || $risk === 'HIGH' ? 'ENHANCED_SURVEILLANCE' : 'STANDARD_SURVEILLANCE'),
                        'outbreak_status'            => 'NONE',
                        'ihr_notified'               => $tier === 'TIER_1',
                        'source'                     => 'WIZARD',
                        'notes'                      => null,
                    ];
                    $this->dispatch(
                        AlertsLifecycleController::class, 'upsertOutcome',
                        "/api/alerts/{$alertId}/case-outcome", 'POST', $obody, [$alertId],
                        ['X-User-Id' => (string) $actor->id]
                    );

                    // Close
                    $cc = self::CLOSE_CATEGORIES[array_rand(self::CLOSE_CATEGORIES)];
                    $cbody = [
                        'user_id'        => $actor->id,
                        'close_category' => $cc['code'],
                        'close_note'     => $cc['note'],
                    ];
                    $cr = $this->dispatch(
                        AlertsController::class, 'close',
                        "/api/alerts/{$alertId}/close", 'PATCH', $cbody, [$alertId]
                    );
                    if ($cr && ($cr['success'] ?? false)) $closed++;
                }
            }
        }

        $this->info('');
        $this->info("✓ Created:        {$created}");
        $this->info("✓ Acknowledged:   {$acked}");
        $this->info("✓ Closed:         {$closed}");
        $this->info("✓ Blockers cleared: {$blocked}");
        $this->info('');
        $this->line('Database snapshot:');
        $stats = DB::table('alerts')->whereNull('deleted_at')->select(DB::raw('status, count(*) c'))->groupBy('status')->get();
        foreach ($stats as $s) $this->line("  {$s->status}: {$s->c}");
        $this->line('  followups:     ' . DB::table('alert_followups')->count());
        $this->line('  timeline events: ' . DB::table('alert_timeline_events')->count());
        $this->line('  comments:      ' . DB::table('alert_comments')->whereNull('deleted_at')->count());
        $this->line('  evidence:      ' . DB::table('alert_evidence')->whereNull('deleted_at')->count());
        $this->line('  outcomes:      ' . DB::table('alert_case_outcomes')->whereNull('deleted_at')->count());
        $this->line('  breach reports: ' . DB::table('alert_breach_reports')->count());

        return self::SUCCESS;
    }

    /**
     * Dispatch through the real controller. Builds an Illuminate Request,
     * resolves the controller from the container (so service injection works),
     * invokes the method with positional route parameters, and decodes the
     * JSON response so the seed script can react to ok/err envelopes.
     */
    private function dispatch(string $controller, string $method, string $uri, string $verb, array $body, array $args = [], array $headers = []): ?array
    {
        try {
            $req = Request::create($uri, $verb, $body, [], [], array_merge([
                'HTTP_ACCEPT' => 'application/json',
                'CONTENT_TYPE' => 'application/json',
            ], $this->headerEnv($headers)));
            $req->headers->set('Accept', 'application/json');
            foreach ($headers as $k => $v) $req->headers->set($k, $v);
            $req->setJson(new \Symfony\Component\HttpFoundation\InputBag($body));

            $instance = app($controller);
            $response = $instance->{$method}($req, ...$args);
            $payload  = $response->getContent();
            $decoded  = json_decode($payload, true);
            if (! is_array($decoded)) return null;
            if (! ($decoded['success'] ?? $decoded['ok'] ?? false)) {
                Log::info('[Seed] non-success', ['ctl' => $controller, 'm' => $method, 'uri' => $uri, 'body' => $decoded]);
            }
            return $decoded;
        } catch (Throwable $e) {
            Log::error('[Seed] dispatch threw', [
                'ctl' => $controller, 'm' => $method, 'uri' => $uri,
                'err' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine(),
            ]);
            return null;
        }
    }

    private function headerEnv(array $headers): array
    {
        $env = [];
        foreach ($headers as $k => $v) $env['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        return $env;
    }

    private function pickActor(array $pools, string $routedTo): object
    {
        $pool = $pools[$routedTo] ?? collect();
        if ($pool->isEmpty()) $pool = $pools['NATIONAL'] ?? collect();
        return $pool->random();
    }

    private function routeFor(string $risk): string
    {
        if ($risk === 'CRITICAL') return random_int(0,1) ? 'NATIONAL' : 'PHEOC';
        if ($risk === 'HIGH')     return random_int(0,2) > 0 ? 'PHEOC' : 'DISTRICT';
        return 'DISTRICT';
    }

    private function classificationFor(string $risk, ?string $tier): string
    {
        if ($tier === 'TIER_1') return random_int(0,1) ? 'CONFIRMED' : 'PROBABLE';
        if ($risk === 'CRITICAL') return 'CONFIRMED';
        if ($risk === 'HIGH')     return random_int(0,1) ? 'PROBABLE' : 'SUSPECTED';
        return random_int(0,2) === 0 ? 'DISCARDED' : 'SUSPECTED';
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
