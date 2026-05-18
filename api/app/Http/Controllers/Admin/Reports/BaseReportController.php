<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\AccessAuditor;
use App\Services\Reports\ExportWriter;
use App\Services\Reports\InsightThresholds;
use App\Services\Reports\ReportAccess;
use App\Services\Reports\ReportScope;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

abstract class BaseReportController extends Controller
{
    protected string $reportKey = '';
    protected string $reportTitle = '';
    protected string $cacheTtl = '300';
    protected int $thinTtl = 60;

    public function __construct(
        protected ReportScope $scope,
        protected ReportAccess $access,
        protected ExportWriter $writer,
    ) {
    }

    /**
     * Lazily resolve AccessAuditor so existing constructor signatures of the
     * 7 per-view controllers do not change. Audit failure must never break
     * the user-facing read — AccessAuditor itself fail-softs and we also
     * swallow container resolution failures here defensively.
     */
    protected function auditor(): ?AccessAuditor
    {
        try {
            return App::make(AccessAuditor::class);
        } catch (BindingResolutionException $e) {
            return null;
        }
    }

    public function reportKey(): string
    {
        return $this->reportKey;
    }

    public function reportTitle(): string
    {
        return $this->reportTitle;
    }

    /** Validate the filter set and reject unknown keys silently (best-effort). */
    protected function filterRules(): array
    {
        return [
            'poe'        => ['nullable'],
            'sex'        => ['nullable', 'in:MALE,FEMALE,OTHER,UNKNOWN'],
            'gender'     => ['nullable', 'in:MALE,FEMALE,OTHER,UNKNOWN'],
            'year'       => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'quarter'    => ['nullable', 'integer', 'min:1', 'max:4'],
            'month'      => ['nullable', 'integer', 'min:1', 'max:12'],
            'start_date' => ['nullable', 'date'],
            'end_date'   => ['nullable', 'date', 'after_or_equal:start_date'],
            'eoc'        => ['nullable'],
            'classification' => ['nullable'],
            'outcome'    => ['nullable'],
            'days'       => ['nullable', 'integer', 'min:1', 'max:365'],
            'q'          => ['nullable', 'string', 'max:120'],
            'page'       => ['nullable', 'integer', 'min:1', 'max:5000'],
            'per_page'   => ['nullable', 'integer', 'min:5',  'max:200'],
        ];
    }

    protected function readFilters(Request $request): array
    {
        $data = $request->query();
        $v = Validator::make($data, $this->filterRules());
        $clean = $v->valid();
        return array_filter($clean, static fn ($v) => $v !== null && $v !== '');
    }

    protected function ensureAccess(Request $request): array
    {
        $scope = $this->scope->descriptor($request);
        if (! $this->access->canSee($scope, $this->reportKey)) {
            $this->auditor()?->recordDenied($request, $scope, $this->reportKey);
            abort(JsonResponse::HTTP_FORBIDDEN, 'This report is out of scope for your role.');
        }
        $this->auditor()?->recordView(
            $request,
            $scope,
            $this->reportKey,
            $this->readFilters($request),
        );
        return $scope;
    }

    protected function ok(array $data, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'meta'    => $meta,
        ]);
    }

    protected function fail(int $code, string $error): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error'   => $error,
        ], $code);
    }

    /**
     * Memoize a payload computation per (report,user,filterhash) for $this->cacheTtl seconds.
     */
    protected function memoise(int $userId, array $filters, callable $build): array
    {
        $key = sprintf('reports:%s:u%d:%s', $this->reportKey, $userId, $this->scope->filtersHash($filters));
        return Cache::remember($key, (int) $this->cacheTtl, $build);
    }

    /**
     * Clamp a denominator-driven percentage to the rule "n<5 → null".
     */
    protected function safePct(int $num, int $den): ?float
    {
        if ($den < InsightThresholds::MIN_DENOMINATOR) {
            return null;
        }
        return round(($num / max(1, $den)) * 100, 1);
    }

    /**
     * Build a "data notes" footer block consumed by every report's view.
     */
    protected function dataNotes(): array
    {
        return [
            'methodology'  => 'Reports are generated from primary_screenings, secondary_screenings, and alerts. Notifiable cases are detected via the linked alerts.ihr_tier IS NOT NULL OR secondary_screenings.risk_level IN (HIGH, CRITICAL).',
            'thresholds'   => sprintf(
                'Notifiable rate is flagged WARNING ≥ %d%% and CRITICAL ≥ %d%%. Gender disparity warns at > %d percentage points absolute.',
                (int) (InsightThresholds::NOTIFIABLE_MED * 100),
                (int) (InsightThresholds::NOTIFIABLE_HIGH * 100),
                (int) (InsightThresholds::GENDER_DISPARITY * 100),
            ),
            'small_n'      => 'Slices with fewer than ' . InsightThresholds::MIN_DENOMINATOR . ' observations are rendered as "— (n<5)" and AI insights for those slices are suppressed.',
            'definitions'  => 'Suspected case: any IN_PROGRESS / DISPOSITIONED / CLOSED secondary screening with at least one row in secondary_suspected_diseases. Outcome buckets follow docs/reports-build/schema-cache.md.',
            'scope'        => 'Every value is filtered to the user\'s assigned geographic scope. POE-scoped users only see their POE; District-scoped users only see their district; PHEOC and National see everything within their tier.',
        ];
    }
}
