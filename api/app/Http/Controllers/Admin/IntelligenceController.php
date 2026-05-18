<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\IntelligenceEngine;
use App\Services\PheocCopilot;
use App\Services\PheocScope;
use App\Support\EnumTranslator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin · National Intelligence Brief (sidebar #4 · Phase B).
 *
 *   GET /admin/intelligence
 *
 * Reads the seven WHO-oriented tripwires maintained by
 * App\Services\IntelligenceEngine, decorates them with per-detector
 * drill-down rows, and ships the result as a tabbed briefing page.
 *
 * No mobile-consumed controller is touched. Mobile continues to hit
 * its existing /api/intelligence/* surface (see CONTROLLER_MAP.md).
 */
final class IntelligenceController extends Controller
{
    public function __construct(
        protected PheocScope $scope,
        protected PheocCopilot $copilot,
        protected EnumTranslator $enum,
    ) {
    }

    public function index(Request $request): View
    {
        $scope = $request->user()
            ? $this->scope->forUser($request->user())
            : ['country_code' => config('country.code'), 'is_super' => true, 'label' => config('country.legacy_code') . ' · National (preview)'];
        $country = (string) ($scope['country_code'] ?? config('country.code'));

        $snapshot  = IntelligenceEngine::runFullReport($country);
        $narrative = IntelligenceEngine::narrativeFor($country);

        $cards = [
            [
                'key'   => 'silent_poes',
                'label' => 'Silent points of entry (24 h)',
                'desc'  => 'Active POEs that submitted zero primary screenings in the last 24 hours.',
                'value' => (int) ($snapshot['poe_silent_24h'] ?? 0),
                'tone'  => ($snapshot['poe_silent_24h'] ?? 0) > 0 ? 'warning' : 'success',
            ],
            [
                'key'   => 'unsubmitted',
                'label' => 'Missed reporting cadence',
                'desc'  => 'POEs that did not file an aggregated submission in the last 3 days but did in the prior 14 days.',
                'value' => (int) ($snapshot['poe_no_submission_3d'] ?? 0),
                'tone'  => ($snapshot['poe_no_submission_3d'] ?? 0) > 0 ? 'info' : 'success',
            ],
            [
                'key'   => 'stuck',
                'label' => 'Stalled alerts',
                'desc'  => 'OPEN alerts still unacknowledged more than 24 hours after creation (violates WHO 7-1-7 Notify band).',
                'value' => (int) ($snapshot['stuck_alerts'] ?? 0),
                'tone'  => ($snapshot['stuck_alerts'] ?? 0) > 0 ? 'critical' : 'success',
            ],
            [
                'key'   => 'overdue',
                'label' => 'Overdue RTSL-14 follow-ups',
                'desc'  => 'Follow-up actions past due date and not COMPLETED — blocks WHO 7-1-7 Respond band.',
                'value' => (int) ($snapshot['overdue_followups'] ?? 0),
                'tone'  => ($snapshot['overdue_followups'] ?? 0) > 0 ? 'warning' : 'success',
            ],
            [
                'key'   => 'spikes',
                'label' => 'Case-count anomalies',
                'desc'  => 'POEs where recent 3-day symptomatic-traveller counts are ≥ 2× their prior 7-day baseline.',
                'value' => (int) ($snapshot['spike_count'] ?? 0),
                'tone'  => ($snapshot['spike_count'] ?? 0) > 0 ? 'warning' : 'success',
            ],
            [
                'key'   => 'dormant',
                'label' => 'Dormant accounts',
                'desc'  => 'Active users with no sign-in in the last 14 days — surveillance workforce risk.',
                'value' => (int) ($snapshot['dormant_accounts'] ?? 0),
                'tone'  => ($snapshot['dormant_accounts'] ?? 0) > 0 ? 'info' : 'success',
            ],
        ];

        $drill = [
            'silent_poes' => $this->drillSilentPoes($country),
            'unsubmitted' => $this->drillUnsubmittedPoes($country),
            'stuck'       => $this->drillStuckAlerts($country),
            'overdue'     => $this->drillOverdueFollowups($country),
            'spikes'      => $this->drillCaseSpikes($country),
            'dormant'     => $this->drillDormantAccounts($country),
        ];

        $brief = $this->copilot->triageBrief($snapshot + ['country_code' => $country]);

        return view('admin.intelligence.index', [
            'scope'     => $scope,
            'snapshot'  => $snapshot,
            'narrative' => $narrative,
            'cards'     => $cards,
            'drill'     => $drill,
            'brief'     => $brief,
        ]);
    }

    /* ─── Drill-down rows (per detector) ────────────────────────────── */

    protected function drillSilentPoes(string $country): array
    {
        try {
            $since24h = now()->subDay()->format('Y-m-d H:i:s');
            $since7d  = now()->subDays(7)->format('Y-m-d H:i:s');

            $activeRecent = DB::table('primary_screenings')
                ->where('country_code', $country)
                ->where('captured_at', '>=', $since7d)
                ->whereNull('deleted_at')
                ->distinct()->pluck('poe_code');

            $rows = [];
            foreach ($activeRecent as $poe) {
                $lastScreening = DB::table('primary_screenings')
                    ->where('country_code', $country)
                    ->where('poe_code', $poe)
                    ->whereNull('deleted_at')
                    ->max('captured_at');
                $in24h = $lastScreening && Carbon::parse((string) $lastScreening)->gt(now()->subDay());
                if (! $in24h) {
                    $rows[] = [
                        'poe_code' => (string) $poe,
                        'last_screening' => $lastScreening,
                        'last_rel' => $lastScreening ? Carbon::parse((string) $lastScreening)->diffForHumans() : 'never',
                    ];
                }
            }
            usort($rows, fn ($a, $b) => strcmp((string) ($b['last_screening'] ?? ''), (string) ($a['last_screening'] ?? '')));
            return array_slice($rows, 0, 40);
        } catch (\Throwable) { return []; }
    }

    protected function drillUnsubmittedPoes(string $country): array
    {
        try {
            $since14d = now()->subDays(14)->format('Y-m-d H:i:s');
            $since3d  = now()->subDays(3)->format('Y-m-d H:i:s');

            $regulars = DB::table('aggregated_submissions')
                ->where('country_code', $country)
                ->where('created_at', '>=', $since14d)
                ->whereNull('deleted_at')
                ->distinct()->pluck('poe_code');

            $rows = [];
            foreach ($regulars as $poe) {
                $last = DB::table('aggregated_submissions')
                    ->where('country_code', $country)
                    ->where('poe_code', $poe)
                    ->whereNull('deleted_at')
                    ->max('created_at');
                $recent = $last && Carbon::parse((string) $last)->gt(now()->subDays(3));
                if (! $recent) {
                    $rows[] = [
                        'poe_code' => (string) $poe,
                        'last_submission' => $last,
                        'last_rel' => $last ? Carbon::parse((string) $last)->diffForHumans() : 'never',
                    ];
                }
            }
            usort($rows, fn ($a, $b) => strcmp((string) ($b['last_submission'] ?? ''), (string) ($a['last_submission'] ?? '')));
            return array_slice($rows, 0, 40);
        } catch (\Throwable) { return []; }
    }

    protected function drillStuckAlerts(string $country): array
    {
        try {
            return DB::table('alerts')
                ->where('country_code', $country)
                ->where('status', 'OPEN')
                ->whereRaw('TIMESTAMPDIFF(HOUR, created_at, NOW()) > 24')
                ->whereNull('deleted_at')
                ->orderBy('created_at')
                ->limit(40)
                ->get(['id', 'alert_code', 'alert_title', 'risk_level', 'poe_code', 'district_code', 'created_at', 'routed_to_level'])
                ->map(fn ($r) => [
                    'id'             => (int) $r->id,
                    'alert_code'     => (string) ($r->alert_code ?? ('#' . $r->id)),
                    'alert_title'    => (string) ($r->alert_title ?? ''),
                    'risk_level'     => (string) ($r->risk_level ?? 'MEDIUM'),
                    'poe_code'       => (string) ($r->poe_code ?? ''),
                    'district_code'  => (string) ($r->district_code ?? ''),
                    'hours_open'     => $r->created_at ? Carbon::parse((string) $r->created_at)->diffInHours(now()) : 0,
                    'created_rel'    => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
                    'routed_label'   => $this->enum->routedToLevel((string) ($r->routed_to_level ?? '')),
                    'url'            => url('/admin/alerts/' . (int) $r->id),
                ])
                ->all();
        } catch (\Throwable) { return []; }
    }

    protected function drillOverdueFollowups(string $country): array
    {
        try {
            return DB::table('alert_followups as f')
                ->leftJoin('alerts as a', 'a.id', '=', 'f.alert_id')
                ->where('a.country_code', $country)
                ->whereNotNull('f.due_at')
                ->where('f.due_at', '<', now())
                ->whereNotIn('f.status', ['COMPLETED', 'NOT_APPLICABLE'])
                ->whereNull('f.deleted_at')
                ->orderBy('f.due_at')
                ->limit(40)
                ->get(['f.*', 'a.alert_code', 'a.risk_level'])
                ->map(fn ($r) => [
                    'alert_id'       => (int) $r->alert_id,
                    'alert_code'     => (string) ($r->alert_code ?? ('#' . $r->alert_id)),
                    'risk_level'     => (string) ($r->risk_level ?? ''),
                    'action_code'    => (string) ($r->action_code ?? ''),
                    'action_label'   => $this->enum->followupAction((string) ($r->action_code ?? '')),
                    'status'         => (string) ($r->status ?? ''),
                    'status_label'   => $this->enum->followupStatus((string) ($r->status ?? '')),
                    'blocks_closure' => (bool) ($r->blocks_closure ?? false),
                    'due_at'         => $r->due_at,
                    'hours_overdue'  => $r->due_at ? max(0, now()->diffInHours(Carbon::parse((string) $r->due_at), false) * -1) : 0,
                    'url'            => url('/admin/alerts/' . (int) $r->alert_id . '?tab=followups'),
                ])
                ->all();
        } catch (\Throwable) { return []; }
    }

    protected function drillCaseSpikes(string $country): array
    {
        try {
            $now    = now();
            $w3     = $now->copy()->subDays(3)->format('Y-m-d H:i:s');
            $b1     = $now->copy()->subDays(10)->format('Y-m-d H:i:s');
            $b2     = $now->copy()->subDays(3)->format('Y-m-d H:i:s');

            $recent = DB::table('primary_screenings')
                ->where('country_code', $country)
                ->where('captured_at', '>=', $w3)
                ->where('symptoms_present', 1)
                ->whereNull('deleted_at')
                ->selectRaw('poe_code, COUNT(*) AS n')
                ->groupBy('poe_code')->pluck('n', 'poe_code');

            $rows = [];
            foreach ($recent as $poe => $recentCount) {
                $baselineCount = (int) DB::table('primary_screenings')
                    ->where('country_code', $country)
                    ->where('poe_code', $poe)
                    ->where('captured_at', '>=', $b1)
                    ->where('captured_at', '<', $b2)
                    ->where('symptoms_present', 1)
                    ->whereNull('deleted_at')
                    ->count();
                $expected = max(1.0, $baselineCount * (3.0 / 7.0));
                $ratio = $expected > 0 ? round($recentCount / $expected, 2) : 0;
                if ($recentCount >= 2 * $expected) {
                    $rows[] = [
                        'poe_code'     => (string) $poe,
                        'recent_count' => (int) $recentCount,
                        'baseline_7d'  => $baselineCount,
                        'ratio'        => $ratio,
                    ];
                }
            }
            usort($rows, fn ($a, $b) => $b['ratio'] <=> $a['ratio']);
            return array_slice($rows, 0, 40);
        } catch (\Throwable) { return []; }
    }

    protected function drillDormantAccounts(string $country): array
    {
        try {
            return DB::table('users')
                ->where('country_code', $country)
                ->where('is_active', 1)
                ->where(function ($q) {
                    $q->whereNull('last_login_at')
                      ->orWhere('last_login_at', '<', now()->subDays(14));
                })
                ->orderBy('last_login_at')
                ->limit(40)
                ->get(['id', 'full_name', 'email', 'role_key', 'last_login_at'])
                ->map(fn ($r) => [
                    'id'            => (int) $r->id,
                    'full_name'     => (string) ($r->full_name ?? '—'),
                    'email'         => (string) ($r->email ?? ''),
                    'role_key'      => (string) ($r->role_key ?? ''),
                    'role_label'    => $this->enum->roleKey((string) ($r->role_key ?? '')),
                    'last_login'    => $r->last_login_at,
                    'last_rel'      => $r->last_login_at ? Carbon::parse((string) $r->last_login_at)->diffForHumans() : 'never',
                ])
                ->all();
        } catch (\Throwable) { return []; }
    }
}
