<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PheocCopilot;
use App\Services\PheocScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PheocCopilotController
 * ---------------------------------------------------------------------------
 * Thin JSON endpoints that the admin panel's ⌘J Copilot dock and per-view
 * callouts consume. All responses are deterministic (zero external LLM).
 *
 * Routes (see routes/web.php or routes/api.php — added there):
 *   GET  /api/v2/admin/copilot/recommend              — dock recommendations
 *   GET  /api/v2/admin/copilot/triage-brief           — national narrative
 *   GET  /api/v2/admin/copilot/alerts/{id}/narrate     — alert timeline prose
 *   GET  /api/v2/admin/copilot/alerts/{id}/close-reason— suggested close category
 *   GET  /api/v2/admin/copilot/alerts/{id}/escalation  — pre-filled rationale
 *   GET  /api/v2/admin/copilot/cases/{id}/differentials— ranked differentials
 */
final class PheocCopilotController extends Controller
{
    public function __construct(
        protected PheocCopilot $copilot,
        protected PheocScope $scope,
    ) {
    }

    public function recommend(Request $request): JsonResponse
    {
        $user = $request->user();
        $scope = $user ? $this->scope->forUser($user) : [];

        return response()->json([
            'ok'   => true,
            'data' => [
                'recommendations' => $this->copilot->recommend([
                    'user_id' => $user?->id,
                    'scope'   => $scope,
                    'route'   => $request->query('route'),
                ]),
                'scope_label' => $scope['label'] ?? null,
            ],
        ]);
    }

    public function triageBrief(Request $request): JsonResponse
    {
        return response()->json([
            'ok'   => true,
            'data' => $this->copilot->triageBrief(null),
        ]);
    }

    public function narrate(Request $request, int $id): JsonResponse
    {
        $alert = \Illuminate\Support\Facades\DB::table('alerts')->find($id);
        if (! $alert) return response()->json(['ok' => false, 'error' => 'Alert not found'], 404);

        return response()->json([
            'ok'   => true,
            'data' => $this->copilot->narrate($alert),
        ]);
    }

    public function closeReason(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'ok'   => true,
            'data' => $this->copilot->suggestCloseReason($id),
        ]);
    }

    public function escalationRationale(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'ok'   => true,
            'data' => $this->copilot->escalationRationale($id),
        ]);
    }

    public function differentials(Request $request, int $id): JsonResponse
    {
        return response()->json([
            'ok'   => true,
            'data' => [
                'items' => $this->copilot->rankDifferentials($id),
            ],
        ]);
    }

    /**
     * Deterministic Q&A endpoint — POST body: {question, route?, alert_id?}
     * Returns {reply, sources[], actions[], reasoning[]} per PheocCopilot::ask.
     */
    public function ask(Request $request): JsonResponse
    {
        $user  = $request->user();
        $scope = $user ? $this->scope->forUser($user) : [];

        $data = $this->copilot->ask([
            'question' => (string) $request->input('question', ''),
            'route'    => $request->input('route'),
            'alert_id' => $request->input('alert_id'),
            'scope'    => $scope,
        ]);

        return response()->json(['ok' => true, 'data' => $data]);
    }
}
