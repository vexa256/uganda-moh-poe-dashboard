<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\IntelligenceEngine;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IntelligenceController
 * ─────────────────────────────────────────────────────────────────────────
 * Read-only intelligence endpoints backing the National Dashboard. Each
 * endpoint is country-scoped via ?country_code=UG (defaults to UG).
 *
 * These feed the PWA charts + tables — silent POEs, stuck alerts, 7-1-7
 * compliance, case spikes, dormant accounts, unsubmitted pipelines.
 *
 * Endpoint map
 *   GET /intelligence/dashboard         combined snapshot for the landing page
 *   GET /intelligence/silent-poes       POEs with no submission in last 24h
 *   GET /intelligence/unsubmitted       POEs offline >3d
 *   GET /intelligence/dormant-accounts  users without login in 14d
 *   GET /intelligence/stuck-alerts      alerts past SLA + open
 *   GET /intelligence/overdue-followups follow-ups past due
 *   GET /intelligence/case-spikes       disease × district anomalies vs baseline
 *   GET /intelligence/kpi/seven-one-seven  7-1-7 compliance %
 *   GET /intelligence/timeline/national    country-wide timeline feed
 *   GET /intelligence/disease-ranking      top suspected diseases (rolling 7d)
 *   GET /intelligence/heatmap/poes         per-POE activity heatmap (7d)
 *   GET /intelligence/map/latest           latest alerts with geography for map pins
 */
final class IntelligenceController extends Controller
{
    public function dashboard(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            $vars = NotificationDispatcher::buildNationalIntelligenceVars($cc);
            $digest = NotificationDispatcher::buildDailyDigestVars($cc);
            return $this->ok([
                'country_code' => $cc,
                'intelligence' => $vars,
                'daily_digest' => $digest,
                'seven_one_seven' => $this->compliance($cc),
                'disease_ranking' => $this->diseaseRanking($cc),
            ]);
        } catch (Throwable $e) { return $this->fail($e, 'dashboard'); }
    }

    public function silentPoes(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            $since24 = now()->subDay()->format('Y-m-d H:i:s');
            $activeRecent = DB::table('primary_screenings')
                ->where('country_code', $cc)
                ->where('captured_at', '>=', now()->subDays(7)->format('Y-m-d H:i:s'))
                ->whereNull('deleted_at')
                ->selectRaw('poe_code, MAX(captured_at) AS last_seen, COUNT(*) AS total_7d')
                ->groupBy('poe_code')->get();
            $silent = [];
            foreach ($activeRecent as $poe) {
                if ($poe->last_seen && strtotime((string) $poe->last_seen) < strtotime($since24)) {
                    $silent[] = [
                        'poe_code' => $poe->poe_code,
                        'last_seen' => $poe->last_seen,
                        'activity_7d' => (int) $poe->total_7d,
                        'silent_hours' => max(0, (int) round((time() - strtotime((string) $poe->last_seen)) / 3600)),
                    ];
                }
            }
            return $this->ok(['silent_poes' => $silent, 'count' => count($silent)]);
        } catch (Throwable $e) { return $this->fail($e, 'silentPoes'); }
    }

    public function unsubmitted(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            $since3d = now()->subDays(3)->format('Y-m-d H:i:s');
            $regulars = DB::table('aggregated_submissions')
                ->where('country_code', $cc)->where('created_at', '>=', now()->subDays(14)->format('Y-m-d H:i:s'))
                ->whereNull('deleted_at')
                ->selectRaw('poe_code, MAX(created_at) AS last_submission, COUNT(*) AS total_14d')
                ->groupBy('poe_code')->get();
            $unsub = [];
            foreach ($regulars as $poe) {
                if ($poe->last_submission && strtotime((string) $poe->last_submission) < strtotime($since3d)) {
                    $unsub[] = [
                        'poe_code' => $poe->poe_code,
                        'last_submission' => $poe->last_submission,
                        'activity_14d' => (int) $poe->total_14d,
                        'days_silent' => max(0, (int) round((time() - strtotime((string) $poe->last_submission)) / 86400)),
                    ];
                }
            }
            return $this->ok(['unsubmitted' => $unsub, 'count' => count($unsub)]);
        } catch (Throwable $e) { return $this->fail($e, 'unsubmitted'); }
    }

    public function dormantAccounts(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            $days = (int) $r->query('days', 14);
            $threshold = now()->subDays($days)->format('Y-m-d H:i:s');
            $rows = DB::table('users')
                ->where('country_code', $cc)->where('is_active', 1)
                ->where(function ($q) use ($threshold) {
                    $q->whereNull('last_login_at')->orWhere('last_login_at', '<', $threshold);
                })
                ->select('id', 'full_name', 'email', 'role_key', 'last_login_at')
                ->orderBy('last_login_at')->get();
            return $this->ok(['dormant' => $rows, 'count' => $rows->count(), 'days' => $days]);
        } catch (Throwable $e) { return $this->fail($e, 'dormantAccounts'); }
    }

    public function stuckAlerts(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            $rows = DB::table('alerts')
                ->where('country_code', $cc)->where('status', 'OPEN')->whereNull('deleted_at')
                ->whereRaw("TIMESTAMPDIFF(HOUR, created_at, NOW()) > (CASE risk_level WHEN 'CRITICAL' THEN 4 WHEN 'HIGH' THEN 24 ELSE 48 END)")
                ->select('id', 'alert_code', 'risk_level', 'routed_to_level', 'alert_title', 'poe_code', 'district_code', 'created_at')
                ->orderBy('created_at')->get();
            return $this->ok(['stuck' => $rows, 'count' => $rows->count()]);
        } catch (Throwable $e) { return $this->fail($e, 'stuckAlerts'); }
    }

    public function overdueFollowups(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            $rows = DB::table('alert_followups as f')
                ->leftJoin('alerts as a', 'a.id', '=', 'f.alert_id')
                ->where('f.country_code', $cc)
                ->whereNull('f.deleted_at')
                ->whereNotIn('f.status', ['COMPLETED', 'NOT_APPLICABLE'])
                ->whereNotNull('f.due_at')->where('f.due_at', '<', now())
                ->select('f.id', 'f.action_code', 'f.action_label', 'f.due_at', 'f.status', 'f.blocks_closure',
                         'a.alert_code', 'a.id as alert_id', 'a.risk_level', 'a.poe_code')
                ->orderBy('f.due_at')->get();
            return $this->ok(['overdue' => $rows, 'count' => $rows->count()]);
        } catch (Throwable $e) { return $this->fail($e, 'overdueFollowups'); }
    }

    public function caseSpikes(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            $rows = DB::select("
                SELECT sd.disease_code, s.district_code, COUNT(*) AS n24,
                       (SELECT COUNT(*) FROM secondary_suspected_diseases sd2
                        INNER JOIN secondary_screenings s2 ON s2.id = sd2.secondary_screening_id
                        WHERE sd2.disease_code = sd.disease_code AND s2.district_code = s.district_code
                          AND s2.country_code = ? AND s2.opened_at >= ? AND s2.opened_at < ?) AS n14
                FROM secondary_suspected_diseases sd
                INNER JOIN secondary_screenings s ON s.id = sd.secondary_screening_id
                WHERE s.country_code = ? AND s.opened_at >= ?
                GROUP BY sd.disease_code, s.district_code
                HAVING n24 >= 2 AND (n14 = 0 OR n24 > (n14 / 14) * 2)
                ORDER BY n24 DESC LIMIT 30
            ", [$cc, now()->subDays(14)->format('Y-m-d H:i:s'), now()->subDay()->format('Y-m-d H:i:s'),
                $cc, now()->subDay()->format('Y-m-d H:i:s')]);
            return $this->ok(['spikes' => $rows, 'count' => count($rows)]);
        } catch (Throwable $e) { return $this->fail($e, 'caseSpikes'); }
    }

    public function sevenOneSeven(Request $r): JsonResponse
    {
        try {
            return $this->ok($this->compliance((string) $r->query('country_code', config('country.code'))));
        } catch (Throwable $e) { return $this->fail($e, 'sevenOneSeven'); }
    }

    public function nationalTimeline(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            $rows = DB::table('alert_timeline_events as t')
                ->join('alerts as a', 'a.id', '=', 't.alert_id')
                ->where('a.country_code', $cc)
                ->select('t.*', 'a.alert_code', 'a.risk_level', 'a.poe_code')
                ->orderByDesc('t.created_at')
                ->limit((int) $r->query('limit', 200))->get();
            return $this->ok(['events' => $rows, 'count' => $rows->count()]);
        } catch (Throwable $e) { return $this->fail($e, 'nationalTimeline'); }
    }

    public function diseaseRankingAction(Request $r): JsonResponse
    {
        try {
            return $this->ok(['ranking' => $this->diseaseRanking((string) $r->query('country_code', config('country.code')))]);
        } catch (Throwable $e) { return $this->fail($e, 'diseaseRanking'); }
    }

    public function heatmapPoes(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            $rows = DB::table('primary_screenings')
                ->where('country_code', $cc)
                ->where('captured_at', '>=', now()->subDays(7))
                ->whereNull('deleted_at')
                ->selectRaw("poe_code, DATE(captured_at) AS d, COUNT(*) AS n, SUM(symptoms_present) AS symp")
                ->groupBy('poe_code', 'd')->orderBy('poe_code')->orderBy('d')->get();
            return $this->ok(['cells' => $rows, 'count' => $rows->count()]);
        } catch (Throwable $e) { return $this->fail($e, 'heatmapPoes'); }
    }

    public function mapLatest(Request $r): JsonResponse
    {
        try {
            $cc = (string) $r->query('country_code', config('country.code'));
            $rows = DB::table('alerts')
                ->where('country_code', $cc)->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->limit((int) $r->query('limit', 100))
                ->select('id', 'alert_code', 'risk_level', 'status', 'poe_code', 'district_code',
                         'alert_title', 'routed_to_level', 'created_at')
                ->get();
            return $this->ok(['alerts' => $rows, 'count' => $rows->count()]);
        } catch (Throwable $e) { return $this->fail($e, 'mapLatest'); }
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /** Returns 7-1-7 compliance % for the last 30 days scoped to country. */
    private function compliance(string $cc): array
    {
        $since = now()->subDays(30)->format('Y-m-d H:i:s');

        // NOTIFY — alert created within 24h of first captured primary screening at same POE
        $notifyTotal = DB::table('alerts')->where('country_code', $cc)
            ->where('created_at', '>=', $since)->whereNull('deleted_at')->count();
        $notifyBreaches = DB::table('alert_breach_reports as br')
            ->join('alerts as a', 'a.id', '=', 'br.alert_id')
            ->where('a.country_code', $cc)->where('br.phase', 'NOTIFY')
            ->where('br.created_at', '>=', $since)->count();

        $detectBreaches = DB::table('alert_breach_reports as br')
            ->join('alerts as a', 'a.id', '=', 'br.alert_id')
            ->where('a.country_code', $cc)->where('br.phase', 'DETECT')
            ->where('br.created_at', '>=', $since)->count();

        $respondBreaches = DB::table('alert_breach_reports as br')
            ->join('alerts as a', 'a.id', '=', 'br.alert_id')
            ->where('a.country_code', $cc)->where('br.phase', 'RESPOND')
            ->where('br.created_at', '>=', $since)->count();

        $pct = fn(int $total, int $b) => $total > 0 ? round(max(0, ($total - $b) / $total) * 100, 1) : 100.0;

        return [
            'window_days' => 30,
            'detect'  => ['breaches' => $detectBreaches,  'total' => $notifyTotal, 'compliance_pct' => $pct($notifyTotal, $detectBreaches)],
            'notify'  => ['breaches' => $notifyBreaches,  'total' => $notifyTotal, 'compliance_pct' => $pct($notifyTotal, $notifyBreaches)],
            'respond' => ['breaches' => $respondBreaches, 'total' => $notifyTotal, 'compliance_pct' => $pct($notifyTotal, $respondBreaches)],
        ];
    }

    private function diseaseRanking(string $cc): array
    {
        return DB::table('secondary_suspected_diseases as sd')
            ->join('secondary_screenings as s', 's.id', '=', 'sd.secondary_screening_id')
            ->where('s.country_code', $cc)
            ->where('s.opened_at', '>=', now()->subDays(7))
            ->whereNull('s.deleted_at')
            ->selectRaw('sd.disease_code, COUNT(*) AS n, AVG(sd.confidence) AS avg_confidence')
            ->groupBy('sd.disease_code')
            ->orderByDesc('n')->limit(10)->get()->toArray();
    }

    private function ok(array $d = [], int $c = 200): JsonResponse { return response()->json(['ok' => true, 'data' => $d], $c); }
    private function fail(Throwable $e, string $ctx): JsonResponse {
        Log::error("[Intelligence::{$ctx}] " . $e->getMessage());
        return response()->json(['ok' => false, 'error' => $e->getMessage(), 'ctx' => $ctx], 500);
    }
}
