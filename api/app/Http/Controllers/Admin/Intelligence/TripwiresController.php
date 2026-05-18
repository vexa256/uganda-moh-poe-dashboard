<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Intelligence;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin · Intelligence · Tripwires (intel-trip)
 * ---------------------------------------------------------------------------
 * Five deterministic tripwires:
 *
 *   1. Stuck Alerts      — OPEN / ACKNOWLEDGED for > 72h
 *   2. Silent PoEs       — zero primary_screenings in the last 7d
 *   3. Dormant Officers  — active users with zero primary_screenings in 14d
 *   4. Case Spikes       — secondary count in last 7d > 2× preceding 7d
 *   5. Unsubmitted       — primary_screenings.sync_status = UNSYNCED > 24h
 *
 * Mobile contract: NONE. Read-only analytics.
 * Gate: NATIONAL_ADMIN.
 */
final class TripwiresController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.intelligence.tripwires.index', [
            'page_title'    => 'Tripwires',
            'page_eyebrow'  => 'Intelligence',
            'page_subtitle' => 'Stuck Alerts · Silent PoEs · Dormant Officers · Case Spikes · Unsubmitted.',
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $now = now();
            $stuck       = $this->stuckAlerts($now);
            $silent      = $this->silentPoes($now);
            $dormant     = $this->dormantOfficers($now);
            $spikes      = $this->caseSpikes($now);
            $unsubmitted = $this->unsubmitted($now);

            $cards = [
                ['key' => 'stuck',       'label' => 'Stuck Alerts',       'n' => count($stuck),
                    'hint' => 'OPEN / ACK older than 72h', 'severity' => 'critical'],
                ['key' => 'silent',      'label' => 'Silent PoEs',        'n' => count($silent),
                    'hint' => 'no primary screenings in 7d', 'severity' => 'warning'],
                ['key' => 'dormant',     'label' => 'Dormant Officers',   'n' => count($dormant),
                    'hint' => 'active officer · 0 screenings in 14d', 'severity' => 'warning'],
                ['key' => 'spikes',      'label' => 'Case Spikes',        'n' => count($spikes),
                    'hint' => 'PoE 7d vs prior 7d · >2× growth', 'severity' => 'critical'],
                ['key' => 'unsubmitted', 'label' => 'Unsubmitted',        'n' => count($unsubmitted),
                    'hint' => 'UNSYNCED > 24h', 'severity' => 'info'],
            ];

            $totalHits   = array_sum(array_column($cards, 'n'));
            $healthScore = max(0, 100 - min(100, $totalHits * 3));

            return response()->json(['ok' => true, 'data' => [
                'server_time' => $now->toIso8601String(),
                'health_score'=> $healthScore,
                'cards'       => $cards,
                'stuck'       => array_slice($stuck,       0, 40),
                'silent'      => array_slice($silent,      0, 40),
                'dormant'     => array_slice($dormant,     0, 40),
                'spikes'      => array_slice($spikes,      0, 40),
                'unsubmitted' => array_slice($unsubmitted, 0, 40),
            ]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    private function stuckAlerts(Carbon $now): array
    {
        if (! Schema::hasTable('alerts')) return [];
        $cutoff = (clone $now)->subHours(72);
        return DB::table('alerts')
            ->whereNull('deleted_at')
            ->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->limit(50)->get()
            ->map(function ($r) use ($now) {
                return [
                    'id'           => (int) $r->id,
                    'alert_code'   => property_exists($r, 'alert_code') ? (string) $r->alert_code : '',
                    'risk_level'   => property_exists($r, 'risk_level') ? (string) $r->risk_level : '',
                    'status'       => (string) $r->status,
                    'poe_code'     => property_exists($r, 'poe_code') ? (string) $r->poe_code : '',
                    'created_at'   => (string) $r->created_at,
                    'hours_open'   => (int) Carbon::parse($r->created_at)->diffInHours($now),
                ];
            })->all();
    }

    private function silentPoes(Carbon $now): array
    {
        if (! Schema::hasTable('ref_poes')) return [];
        $cutoff = (clone $now)->subDays(7);

        $poes = DB::table('ref_poes')->whereNull('deleted_at')
            ->get(['poe_code', 'poe_name', 'admin_level_1', 'district', 'poe_type']);

        $seen = [];
        if (Schema::hasTable('primary_screenings')) {
            $seen = DB::table('primary_screenings')
                ->whereNull('deleted_at')->where('created_at', '>=', $cutoff)
                ->selectRaw('poe_code, COUNT(*) AS n')->groupBy('poe_code')
                ->pluck('n', 'poe_code')->all();
        }

        $out = [];
        foreach ($poes as $p) {
            if (isset($seen[$p->poe_code])) continue;
            $out[] = [
                'poe_code' => (string) $p->poe_code,
                'poe_name' => (string) $p->poe_name,
                'province' => (string) ($p->admin_level_1 ?? ''),
                'district' => (string) ($p->district ?? ''),
                'poe_type' => (string) ($p->poe_type ?? ''),
            ];
        }
        return $out;
    }

    private function dormantOfficers(Carbon $now): array
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('primary_screenings')) return [];
        $cutoff = (clone $now)->subDays(14);

        // Candidate: officers whose role looks operational (POE_*) and is_active.
        $users = DB::table('users')
            ->where('is_active', 1)
            ->where(function ($q): void {
                $q->where('role_key', 'like', 'POE%')->orWhere('account_type', 'like', 'POE%');
            })
            ->get(['id', 'full_name', 'username', 'email', 'role_key']);

        $active = DB::table('primary_screenings')
            ->whereNull('deleted_at')->where('created_at', '>=', $cutoff)
            ->distinct()->pluck('captured_by_user_id')
            ->filter(fn ($v) => $v !== null)->values()->all();
        $activeSet = array_flip(array_map('intval', $active));

        $out = [];
        foreach ($users as $u) {
            if (isset($activeSet[(int) $u->id])) continue;
            $out[] = [
                'id'        => (int) $u->id,
                'full_name' => (string) ($u->full_name ?? $u->username),
                'username'  => (string) ($u->username ?? ''),
                'role_key'  => (string) ($u->role_key ?? ''),
                'email'     => (string) ($u->email ?? ''),
            ];
        }
        return $out;
    }

    private function caseSpikes(Carbon $now): array
    {
        if (! Schema::hasTable('secondary_screenings')) return [];
        $cutoffCurr = (clone $now)->subDays(7);
        $cutoffPrev = (clone $now)->subDays(14);

        $curr = DB::table('secondary_screenings')
            ->whereNull('deleted_at')->where('created_at', '>=', $cutoffCurr)
            ->selectRaw('poe_code, COUNT(*) AS n')->groupBy('poe_code')
            ->pluck('n', 'poe_code')->all();
        $prev = DB::table('secondary_screenings')
            ->whereNull('deleted_at')
            ->where('created_at', '>=', $cutoffPrev)
            ->where('created_at', '<',  $cutoffCurr)
            ->selectRaw('poe_code, COUNT(*) AS n')->groupBy('poe_code')
            ->pluck('n', 'poe_code')->all();

        $out = [];
        foreach ($curr as $poe => $n) {
            $p = (int) ($prev[$poe] ?? 0);
            if ($n < 3) continue; // ignore noise
            if ($p === 0 && $n >= 5) {
                $out[] = ['poe_code' => (string) $poe, 'curr_7d' => (int) $n, 'prev_7d' => 0, 'growth' => null];
            } elseif ($p > 0 && $n >= 2 * $p) {
                $out[] = ['poe_code' => (string) $poe, 'curr_7d' => (int) $n, 'prev_7d' => $p,
                    'growth' => round(($n - $p) / $p * 100, 1)];
            }
        }
        usort($out, fn ($a, $b) => ($b['growth'] ?? 999) <=> ($a['growth'] ?? 999));
        return $out;
    }

    private function unsubmitted(Carbon $now): array
    {
        if (! Schema::hasTable('primary_screenings')) return [];
        $cutoff = (clone $now)->subHours(24);
        return DB::table('primary_screenings')
            ->whereNull('deleted_at')
            ->where('sync_status', 'UNSYNCED')
            ->where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->limit(50)->get(['id', 'client_uuid', 'poe_code', 'device_id', 'app_version', 'created_at', 'sync_attempt_count'])
            ->map(fn ($r) => [
                'id'         => (int) $r->id,
                'client_uuid'=> (string) ($r->client_uuid ?? ''),
                'poe_code'   => (string) ($r->poe_code ?? ''),
                'device_id'  => (string) ($r->device_id ?? ''),
                'app_version'=> (string) ($r->app_version ?? ''),
                'created_at' => (string) $r->created_at,
                'attempts'   => (int) ($r->sync_attempt_count ?? 0),
                'hours_old'  => (int) Carbon::parse($r->created_at)->diffInHours($now),
            ])
            ->all();
    }

    /**
     * Suppressed By Cadence — §2.8 visibility surface.
     *
     * Reads the central suppression-decision store (notification_suppressions)
     * plus the dispatch history (notification_log) and surfaces every reminder
     * dispatch the 24-hour rule has throttled in the last N days. This is NOT
     * an error state — it is the "we're protecting your inbox" view.
     *
     * Source-of-truth: NotificationDispatcher::SUPPRESSION_MINUTES + the
     * notification_suppressions table (last_sent_at per template/entity/contact)
     * plus notification_log rows where status='SKIPPED' and the status_reason
     * begins with 'Suppressed'.
     *
     * The 24-hour rule is enforced in NotificationDispatcher::send() — this
     * endpoint is a read-only window onto its decisions.
     */
    public function suppressedByCadence(Request $request): JsonResponse
    {
        try {
            $days = max(1, min(30, (int) $request->query('days', 7)));
            $since = now()->subDays($days);

            // Reminder template codes (per §2.1) that are subject to the 24-hour rule.
            $reminderTemplates = ['BREACH_717', 'FOLLOWUP_DUE', 'FOLLOWUP_OVERDUE', 'RESPONDER_INFO_REQUEST'];
            $windowMap = method_exists(\App\Services\NotificationDispatcher::class, 'suppressionMinutesMap')
                ? \App\Services\NotificationDispatcher::suppressionMinutesMap() : [];

            $skipped = [];
            if (Schema::hasTable('notification_log')) {
                $skipped = DB::table('notification_log')
                    ->where('status', 'SKIPPED')
                    ->where('error_message', 'like', 'Suppressed%')
                    ->whereIn('template_code', $reminderTemplates)
                    ->where('created_at', '>=', $since)
                    ->orderByDesc('created_at')
                    ->limit(200)
                    ->get(['id', 'template_code', 'related_entity_type', 'related_entity_id', 'contact_id', 'to_email', 'error_message', 'created_at'])
                    ->map(fn ($r) => [
                        'id'                  => (int) $r->id,
                        'template_code'       => (string) $r->template_code,
                        'related_entity_type' => (string) ($r->related_entity_type ?? ''),
                        'related_entity_id'   => (int) ($r->related_entity_id ?? 0),
                        'contact_id'          => (int) ($r->contact_id ?? 0),
                        'recipient'           => (string) ($r->to_email ?? ''),
                        'reason'              => (string) ($r->error_message ?? ''),
                        'when'                => (string) $r->created_at,
                    ])
                    ->all();
            }

            // Per-(template,entity,contact) summary — last successful dispatch + next-eligible time.
            $perCase = [];
            if (Schema::hasTable('notification_suppressions')) {
                $perCase = DB::table('notification_suppressions')
                    ->whereIn('template_code', $reminderTemplates)
                    ->where('last_sent_at', '>=', $since)
                    ->orderByDesc('last_sent_at')
                    ->limit(200)
                    ->get(['template_code', 'related_entity_type', 'related_entity_id', 'contact_id', 'last_sent_at'])
                    ->map(function ($r) use ($windowMap) {
                        $window = (int) ($windowMap[$r->template_code] ?? 1440);
                        $next   = Carbon::parse((string) $r->last_sent_at)->addMinutes($window);
                        return [
                            'template_code'       => (string) $r->template_code,
                            'related_entity_type' => (string) ($r->related_entity_type ?? ''),
                            'related_entity_id'   => (int) ($r->related_entity_id ?? 0),
                            'contact_id'          => (int) ($r->contact_id ?? 0),
                            'last_sent_at'        => (string) $r->last_sent_at,
                            'next_eligible_at'    => $next->toIso8601String(),
                            'window_minutes'      => $window,
                        ];
                    })
                    ->all();
            }

            // Aggregate counts for the dashboard ribbon.
            $byTemplate = [];
            foreach ($skipped as $r) {
                $byTemplate[$r['template_code']] = ($byTemplate[$r['template_code']] ?? 0) + 1;
            }

            return response()->json(['ok' => true, 'data' => [
                'window_days'      => $days,
                'rule'             => '§2.1 — at most one reminder per 24 hours per (recipient, case, reminder-type).',
                'reminder_templates' => $reminderTemplates,
                'window_map'       => array_intersect_key($windowMap, array_flip($reminderTemplates)),
                'totals'           => [
                    'suppressed_dispatches' => count($skipped),
                    'tracked_cases'         => count($perCase),
                    'by_template'           => $byTemplate,
                ],
                'skipped'  => $skipped,
                'per_case' => $perCase,
            ]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
