<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Intelligence;

use App\Http\Controllers\Controller;
use App\Services\PheocCopilot;
use App\Services\PheocScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin · Intelligence · Copilot (intel-copilot)
 * ---------------------------------------------------------------------------
 * Deterministic next-best-action recommendations + alert narrations backed
 * by the existing App\Services\PheocCopilot service.  No external LLM —
 * every response is composed from rules in ref_engine_config and the
 * operational aggregates already computed by PheocCopilot.
 *
 * The PheocCopilotController already exposes the raw JSON endpoints for
 * the ⌘J dock.  This controller wraps the same service behind a
 * /admin/intelligence/copilot surface (index view + feature-scoped JSON)
 * so the Intelligence sidebar entry lands on a first-class page instead
 * of a floating dock.
 *
 * Mobile contract: NONE.
 * Gate: NATIONAL_ADMIN.
 */
final class CopilotController extends Controller
{
    public function __construct(
        protected PheocCopilot $copilot,
        protected PheocScope $scope,
    ) {
    }

    public function index(Request $request)
    {
        return view('admin.intelligence.copilot.index', [
            'page_title'    => 'Copilot',
            'page_eyebrow'  => 'Intelligence',
            'page_subtitle' => 'Deterministic next-best-action recommendations · alert narrations.',
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $user  = $request->user();
            $scope = $user ? $this->scope->forUser($user) : [];

            $recs   = $this->copilot->recommend([
                'user_id' => $user?->id,
                'scope'   => $scope,
                'route'   => $request->query('route', '/admin/intelligence/copilot'),
            ]);

            $brief = $this->copilot->triageBrief(null);

            // Pre-stage a narrow list of open alerts so the UI can offer a
            // narrate-on-click list without needing a second round trip.
            $alerts = [];
            if (Schema::hasTable('alerts')) {
                $q = DB::table('alerts')
                    ->whereNull('deleted_at')
                    ->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])
                    ->orderByDesc('created_at')->limit(30);
                $cols = array_values(array_filter(
                    ['id', 'alert_code', 'alert_title', 'risk_level', 'ihr_tier',
                     'status', 'routed_to_level', 'poe_code', 'district_code',
                     'country_code', 'top_disease_code', 'created_at'],
                    fn ($c) => Schema::hasColumn('alerts', $c),
                ));
                $alerts = $q->get($cols)->map(fn ($r) => (array) $r)->all();
            }

            // Engine-config peek at copilot-adjacent keys (rules of the brain).
            $rules = [];
            if (Schema::hasTable('ref_engine_config')) {
                $rules = DB::table('ref_engine_config')
                    ->where(function ($q): void {
                        $q->where('config_key', 'like', '%copilot%')
                          ->orWhere('config_key', 'like', '%recommend%')
                          ->orWhere('section', 'like', 'INTEL%');
                    })
                    ->orderBy('config_key')
                    ->limit(40)
                    ->get(['id', 'config_key', 'section', 'description', 'version', 'is_active'])
                    ->map(fn ($r) => (array) $r)->all();
            }

            // Stats for KPI strip
            $totals = [
                'open_alerts'     => 0,
                'tier1_open'      => 0,
                'high_risk_users' => 0,
                'stuck_alerts_72h'=> 0,
            ];
            if (Schema::hasTable('alerts')) {
                $base = DB::table('alerts')->whereNull('deleted_at')
                    ->whereIn('status', ['OPEN', 'ACKNOWLEDGED']);
                $totals['open_alerts'] = (int) (clone $base)->count();
                if (Schema::hasColumn('alerts', 'ihr_tier')) {
                    $totals['tier1_open'] = (int) (clone $base)
                        ->where('ihr_tier', 'like', '%TIER_1%')->count();
                }
                $totals['stuck_alerts_72h'] = (int) (clone $base)
                    ->where('created_at', '<', now()->subHours(72))->count();
            }
            if (Schema::hasTable('users')) {
                $totals['high_risk_users'] = (int) DB::table('users')
                    ->where('risk_score', '>=', 80)->count();
            }

            return response()->json(['ok' => true, 'data' => [
                'server_time'     => now()->toIso8601String(),
                'scope_label'     => $scope['label']      ?? null,
                'scope_level'     => $scope['scope_level'] ?? null,
                'recommendations' => $recs,
                'triage_brief'    => $brief,
                'alerts'          => $alerts,
                'rules'           => $rules,
                'totals'          => $totals,
            ]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function narrate(Request $request, int $id): JsonResponse
    {
        try {
            if (! Schema::hasTable('alerts')) {
                return response()->json(['ok' => false, 'error' => 'alerts_table_missing'], 500);
            }
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) {
                return response()->json(['ok' => false, 'error' => 'not_found'], 404);
            }

            $narrative   = $this->copilot->narrate($alert);
            $differentials = $this->copilot->rankDifferentials($id);
            $closeReason = $this->copilot->suggestCloseReason($id);
            $escalation  = $this->copilot->escalationRationale($id);

            return response()->json(['ok' => true, 'data' => [
                'alert_id'      => $id,
                'narrative'     => $narrative,
                'differentials' => $differentials,
                'close_reason'  => $closeReason,
                'escalation'    => $escalation,
            ]]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function ask(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'question' => ['required', 'string', 'min:3', 'max:500'],
                'route'    => ['nullable', 'string', 'max:200'],
                'alert_id' => ['nullable', 'integer'],
            ]);
            $user  = $request->user();
            $scope = $user ? $this->scope->forUser($user) : [];

            $data = $this->copilot->ask([
                'question' => $validated['question'],
                'route'    => $validated['route'] ?? null,
                'alert_id' => $validated['alert_id'] ?? null,
                'scope'    => $scope,
            ]);

            return response()->json(['ok' => true, 'data' => $data]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'validation', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
