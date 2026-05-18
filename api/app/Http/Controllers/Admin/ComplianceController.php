<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PheocCopilot;
use App\Services\PheocScope;
use App\Support\EnumTranslator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ComplianceController — 7-1-7 Compliance Board (M4).
 *
 *   GET /admin/compliance/717
 *
 * Reads the alerts table + alert_followups + alert_breach_reports in the
 * current country scope. Computes detect / notify / respond %s over a
 * rolling 30-day window.
 */
final class ComplianceController extends Controller
{
    public function __construct(
        protected PheocScope $scope,
        protected EnumTranslator $enum,
        protected PheocCopilot $copilot,
    ) {
    }

    public function index(Request $request): View
    {
        $scope = $request->user() ? $this->scope->forUser($request->user()) : [
            'country_code' => config('country.code'), 'is_super' => true, 'label' => config('country.legacy_code') . ' · National',
        ];
        $country = (string) ($scope['country_code'] ?? config('country.code'));

        $rings = $this->computeRings($country);
        $breaches = $this->recentBreaches($country, 50);
        $breachStats = [
            'total_30d'  => count($breaches),
            'by_phase'   => array_count_values(array_map(fn ($b) => (string) ($b['phase'] ?? 'OTHER'), $breaches)),
            'unresolved' => count(array_filter($breaches, fn ($b) => ($b['status'] ?? '') !== 'RESOLVED')),
        ];
        $followups = $this->recentFollowups($country, 50);
        $brief = $this->copilot->triageBrief(['country_code' => $country]);

        return view('admin.compliance.index', [
            'scope'       => $scope,
            'rings'       => $rings,
            'breaches'    => $breaches,
            'breachStats' => $breachStats,
            'followups'   => $followups,
            'brief'       => $brief,
        ]);
    }

    protected function recentFollowups(string $country, int $limit): array
    {
        try {
            return DB::table('alert_followups as f')
                ->leftJoin('alerts as a', 'a.id', '=', 'f.alert_id')
                ->where('a.country_code', $country)
                ->whereIn('f.status', ['PENDING', 'IN_PROGRESS', 'OVERDUE'])
                ->whereNull('a.deleted_at')
                ->orderByDesc('f.blocks_closure')
                ->orderBy('f.due_at')
                ->limit($limit)
                ->get(['f.*', 'a.alert_code', 'a.risk_level'])
                ->map(fn ($r) => [
                    'id'             => (int) $r->id,
                    'alert_id'       => (int) $r->alert_id,
                    'alert_code'     => (string) ($r->alert_code ?? ('#' . $r->alert_id)),
                    'risk_level'     => (string) ($r->risk_level ?? ''),
                    'action_code'    => (string) ($r->action_code ?? ''),
                    'action_label'   => $this->enum->followupAction((string) ($r->action_code ?? '')),
                    'status'         => (string) ($r->status ?? ''),
                    'status_label'   => $this->enum->followupStatus((string) ($r->status ?? '')),
                    'blocks_closure' => (bool) ($r->blocks_closure ?? false),
                    'due_at'         => $r->due_at,
                    'due_rel'        => $r->due_at ? Carbon::parse((string) $r->due_at)->diffForHumans() : '—',
                    'url'            => url('/admin/alerts/' . (int) $r->alert_id . '?tab=followups'),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    protected function computeRings(string $country): array
    {
        $rings = [
            ['key' => 'detect',  'label' => 'Detect',  'target' => '≤ 7 days', 'window' => 'From exposure or symptom onset to alert creation.', 'percent' => 0.0, 'tone' => 'warning', 'met' => 0, 'total' => 0, 'target_hours' => 168],
            ['key' => 'notify',  'label' => 'Notify',  'target' => '≤ 1 day',  'window' => 'From alert creation to acknowledgement.', 'percent' => 0.0, 'tone' => 'warning', 'met' => 0, 'total' => 0, 'target_hours' => 24],
            ['key' => 'respond', 'label' => 'Respond', 'target' => '≤ 7 days', 'window' => 'From alert creation to close (for closed alerts).', 'percent' => 0.0, 'tone' => 'warning', 'met' => 0, 'total' => 0, 'target_hours' => 168],
        ];

        try {
            $since = now()->subDays(30);

            // Detect: secondary_screenings.opened_at → alerts.created_at, target 168h
            $detAll = DB::table('alerts as a')
                ->leftJoin('secondary_screenings as s', 'a.secondary_screening_id', '=', 's.id')
                ->where('a.country_code', $country)
                ->where('a.created_at', '>=', $since)
                ->whereNull('a.deleted_at')
                ->whereNotNull('s.opened_at')
                ->count();
            $detOk  = DB::table('alerts as a')
                ->leftJoin('secondary_screenings as s', 'a.secondary_screening_id', '=', 's.id')
                ->where('a.country_code', $country)
                ->where('a.created_at', '>=', $since)
                ->whereNull('a.deleted_at')
                ->whereNotNull('s.opened_at')
                ->whereRaw('TIMESTAMPDIFF(HOUR, s.opened_at, a.created_at) <= 168')
                ->count();
            $rings[0]['total'] = $detAll;
            $rings[0]['met']   = $detOk;
            $rings[0]['percent'] = $detAll > 0 ? round(($detOk / $detAll) * 100, 1) : 100.0;

            // Notify: alerts.created_at → alerts.acknowledged_at, target 24h
            $notAll = DB::table('alerts')->where('country_code', $country)
                ->where('created_at', '>=', $since)
                ->whereNotNull('acknowledged_at')
                ->whereNull('deleted_at')
                ->count();
            $notOk  = DB::table('alerts')->where('country_code', $country)
                ->where('created_at', '>=', $since)
                ->whereNotNull('acknowledged_at')
                ->whereNull('deleted_at')
                ->whereRaw('TIMESTAMPDIFF(HOUR, created_at, acknowledged_at) <= 24')
                ->count();
            $rings[1]['total']   = $notAll;
            $rings[1]['met']     = $notOk;
            $rings[1]['percent'] = $notAll > 0 ? round(($notOk / $notAll) * 100, 1) : 100.0;

            // Respond: alerts.created_at → alerts.closed_at (CLOSED only), target 168h
            $respAll = DB::table('alerts')->where('country_code', $country)
                ->where('created_at', '>=', $since)
                ->where('status', 'CLOSED')
                ->whereNotNull('closed_at')
                ->whereNull('deleted_at')
                ->count();
            $respOk  = DB::table('alerts')->where('country_code', $country)
                ->where('created_at', '>=', $since)
                ->where('status', 'CLOSED')
                ->whereNotNull('closed_at')
                ->whereNull('deleted_at')
                ->whereRaw('TIMESTAMPDIFF(HOUR, created_at, closed_at) <= 168')
                ->count();
            $rings[2]['total']   = $respAll;
            $rings[2]['met']     = $respOk;
            $rings[2]['percent'] = $respAll > 0 ? round(($respOk / $respAll) * 100, 1) : 100.0;

            foreach ($rings as &$r) {
                $r['tone'] = $r['percent'] >= 95 ? 'success' : ($r['percent'] >= 80 ? 'warning' : 'critical');
            }
            unset($r);
        } catch (\Throwable $e) {
            // keep defaults
        }

        return $rings;
    }

    protected function recentBreaches(string $country, int $limit = 50): array
    {
        try {
            return DB::table('alert_breach_reports as b')
                ->leftJoin('alerts as a', 'a.id', '=', 'b.alert_id')
                ->where('a.country_code', $country)
                ->where('b.created_at', '>=', now()->subDays(90))
                ->whereNull('a.deleted_at')
                ->orderByDesc('b.created_at')
                ->limit($limit)
                ->get(['b.*', 'a.alert_code', 'a.risk_level'])
                ->map(fn ($r) => [
                    'id'            => (int) $r->id,
                    'alert_id'      => (int) $r->alert_id,
                    'alert_code'    => (string) ($r->alert_code ?? ('#' . $r->alert_id)),
                    'risk_level'    => (string) ($r->risk_level ?? ''),
                    'phase'         => (string) ($r->phase ?? ''),
                    'phase_label'   => $this->enum->breachPhase((string) ($r->phase ?? '')),
                    'status'        => (string) ($r->status ?? ''),
                    'status_label'  => $this->enum->breachStatus((string) ($r->status ?? '')),
                    'root_cause'    => (string) ($r->root_cause ?? ''),
                    'mitigation'    => (string) ($r->mitigation ?? ''),
                    'target_hours'  => (int) ($r->target_hours ?? 0),
                    'elapsed_hours' => (int) ($r->elapsed_hours ?? 0),
                    'created_at'    => $r->created_at,
                    'created_rel'   => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
                    'url'           => url('/admin/alerts/' . (int) $r->alert_id . '?tab=breaches'),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
