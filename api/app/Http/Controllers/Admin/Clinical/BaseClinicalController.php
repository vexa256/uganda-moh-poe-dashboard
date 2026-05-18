<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Clinical;

use App\Http\Controllers\Controller;
use App\Services\Clinical\ClinicalAccess;
use App\Services\Clinical\ClinicalRegistry;
use App\Services\Clinical\ClinicalScoringSimulator;
use App\Services\Clinical\ClinicalTranslator;
use App\Services\PheocScope;
use App\Services\Reports\AccessAuditor;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * BaseClinicalController — shared foundation for the Clinical Library section.
 *
 * Per Paranoid v2 brief §2 and §13 this section is READ-ONLY. The base
 * provides:
 *   - DI of the four foundation services (registry, translator, simulator,
 *     access policy)
 *   - ensureAccess() that gates the request to PHEOC+ via ClinicalAccess and
 *     audits every successful access via the (signature-agnostic) reports
 *     AccessAuditor — keyed by section_key so an auditor can correlate
 *     reads across the section.
 *   - ok() / fail() shapes consistent with the rest of the admin app.
 *   - readOnly() — the canonical 403 returned by every legacy mutation
 *     endpoint that the routes file still references but that this rebuild
 *     does not implement (see brief §2 "edit affordances are out of scope").
 */
abstract class BaseClinicalController extends Controller
{
    protected string $sectionKey = '';

    public function __construct(
        protected ClinicalRegistry $registry,
        protected ClinicalTranslator $translator,
        protected ClinicalScoringSimulator $simulator,
        protected ClinicalAccess $access,
        protected PheocScope $pheoc,
    ) {
    }

    protected function auditor(): ?AccessAuditor
    {
        try {
            return App::make(AccessAuditor::class);
        } catch (BindingResolutionException $e) {
            return null;
        }
    }

    /** Resolve the user scope descriptor (same shape PheocScope produces). */
    protected function scope(Request $request): array
    {
        $existing = $request->attributes->get('scope');
        if (is_array($existing) && isset($existing['scope_level'])) {
            return $existing;
        }
        $user = $request->user();
        if (! $user) {
            return [
                'user_id' => 0, 'role_key' => 'OBSERVER', 'account_type' => 'OBSERVER',
                'scope_level' => 'SELF', 'is_super' => false, 'label' => 'Unauthenticated',
            ];
        }
        return $this->pheoc->forUser($user);
    }

    protected function ensureAccess(Request $request): array
    {
        $scope = $this->scope($request);
        if (! $this->access->canSee($scope, $this->sectionKey)) {
            $this->auditor()?->recordDenied($request, $scope, $this->sectionKey);
            abort(JsonResponse::HTTP_FORBIDDEN, 'This section is out of scope for your role. Clinical Library is PHEOC-level and above.');
        }
        $this->auditor()?->recordView($request, $scope, $this->sectionKey, $request->query());
        return $scope;
    }

    protected function ok(array $data, array $meta = []): JsonResponse
    {
        return response()->json([
            'success'   => true,
            'read_only' => true,
            'data'      => $data,
            'meta'      => $meta,
        ]);
    }

    /**
     * Legacy mutation endpoints (toggle, updateLevel) declared in
     * routes/web.php for the clin-* prefixes are intentionally not
     * implemented in this rebuild. Per Paranoid v2 brief §2:
     *
     *   "The single permitted 'action' affordance in this rebuild is
     *    export … No edits. No publishes. No retires."
     *
     * The route signatures remain so the URL surface does not change
     * silently for any existing consumer. Hits return 403 with a clear
     * explanation rather than mutating reference data.
     */
    protected function readOnly(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $this->auditor()?->recordDenied($request, $scope, $this->sectionKey);
        return response()->json([
            'success' => false,
            'error'   => 'Clinical Library is read-only. Editing reference data is out of scope for this section; clinical changes go through the separate governed workflow.',
            'code'    => 'CLINICAL_READ_ONLY',
        ], 403);
    }
}
