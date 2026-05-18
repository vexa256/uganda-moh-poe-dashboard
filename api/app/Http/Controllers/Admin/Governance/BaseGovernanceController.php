<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Governance;

use App\Http\Controllers\Controller;
use App\Services\PheocScope;
use App\Services\Reports\AccessAuditor;
use App\Support\Governance\CoachManifest;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BaseGovernanceController
 * ---------------------------------------------------------------------------
 * Shared scaffolding for the six Governance views (gov-auth, gov-notif-log,
 * gov-reminders, gov-templates, gov-dq, gov-retention).
 *
 * What it provides:
 *   · scope() — single descriptor produced via PheocScope, so every Governance
 *               read uses the same scope shape Reports does.
 *   · audit*() — convenience wrappers around AccessAuditor::recordView /
 *                ::recordPiiReveal / ::recordDenied. Audit failure NEVER
 *                breaks the user-facing read (AccessAuditor is fail-soft and
 *                we additionally swallow container-resolution errors here).
 *   · ok() / err() / serverError() — one error envelope shared by all six.
 *   · viewKey() — abstract; each subclass declares its stable view key.
 *   · coach() — convenience for index() to load the right manifest.
 *
 * Discipline:
 *   · Public method signatures of the six existing per-view controllers are
 *     unchanged. This base only adds protected helpers; the controllers'
 *     index(), data(), summary(), etc. continue to return the same payloads.
 *   · No mobile-API surface is touched — Governance routes are NATIONAL_ADMIN
 *     only and live in routes/web.php under /admin/governance/*.
 *   · No schema change. All audit rows go to the existing reports_access_audit
 *     table via AccessAuditor.
 */
abstract class BaseGovernanceController extends Controller
{
    /** Stable view key — must match the lang/coach_governance keys. */
    abstract protected function viewKey(): string;

    /** Routing-friendly slug used as the AccessAuditor reportKey. */
    protected function auditKey(): string
    {
        return 'gov-' . $this->viewKey();
    }

    /** Coach manifest for this view. */
    protected function coach(): array
    {
        return CoachManifest::forView($this->viewKey());
    }

    /**
     * One scope descriptor for every Governance read. Mirrors the shape
     * the Reports module uses, so any cross-view coherence test compares
     * apples to apples.
     *
     * Returns the empty descriptor on error (so callers can rely on the
     * shape) and logs the failure.
     *
     * @return array<string,mixed>
     */
    protected function scope(Request $request): array
    {
        try {
            $pheoc = App::make(PheocScope::class);
            return $pheoc->descriptor($request) ?? [];
        } catch (Throwable $e) {
            Log::warning('Governance scope resolution failed', [
                'view'  => $this->viewKey(),
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /** Lazily resolve AccessAuditor — never throw to the caller. */
    protected function auditor(): ?AccessAuditor
    {
        try {
            return App::make(AccessAuditor::class);
        } catch (BindingResolutionException $e) {
            return null;
        }
    }

    /**
     * Record a successful view of a Governance read endpoint. Failure is
     * fail-soft. Caller passes the row count and the suppressed-cell count
     * (where small-number suppression applies) so auditors can later see
     * exactly what the operator saw.
     *
     * @param array<string,mixed> $filters
     * @param array<string,int>   $counts  ['row_count' => int, 'suppressed_count' => int]
     */
    protected function auditView(Request $request, array $filters = [], array $counts = []): void
    {
        $this->auditor()?->recordView(
            $request,
            $this->scope($request),
            $this->auditKey(),
            $filters,
            $counts,
        );
    }

    /**
     * Record that this read surfaced PII columns. Used wherever a Governance
     * surface unmasks recipient details, traveller details, or any other
     * personally identifiable column.
     *
     * @param array<string,mixed> $filters
     * @param array<int,string>   $piiColumns Columns the user actually saw unmasked
     */
    protected function auditPiiReveal(
        Request $request,
        array $filters,
        int $rowCount,
        array $piiColumns,
    ): void {
        $this->auditor()?->recordPiiReveal(
            $request,
            $this->scope($request),
            $this->auditKey(),
            $filters,
            $rowCount,
            $piiColumns,
        );
    }

    /** Record an authorisation denial. */
    protected function auditDenied(Request $request): void
    {
        $this->auditor()?->recordDenied(
            $request,
            $this->scope($request),
            $this->auditKey(),
        );
    }

    /* ─────────── one error envelope shared by all six controllers ─────────── */

    protected function ok(array $data, string $message = '', array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => $data];
        if (! empty($meta)) {
            $body['meta'] = $meta;
        }
        return response()->json($body);
    }

    protected function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => $detail,
        ], $status);
    }

    protected function serverError(Throwable $e, string $context): JsonResponse
    {
        Log::error("[Admin\\Governance\\{$this->viewKey()}][{$context}] " . $e->getMessage(), [
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Server error.',
            'error'   => ['code' => 'SERVER_ERROR', 'context' => $context],
        ], 500);
    }

    /**
     * Idempotency key extracted from the request header. Returned as a
     * string so callers can use it directly in audit rows / dedupe checks.
     * Empty string when absent.
     */
    protected function idempotencyKey(Request $request): string
    {
        return trim((string) $request->header('Idempotency-Key', ''));
    }

    /**
     * Standard CSV export footer. Every Governance export embeds this so
     * the file carries scope, filters, and timestamp on every row.
     *
     * @param  array<string,mixed> $filters
     * @return array<int,array<int,string>>  Two CSV rows: a blank separator and a single-column footer
     */
    protected function exportFooter(Request $request, array $filters): array
    {
        $scope = $this->scope($request);
        $bits  = [];
        $bits[] = 'View: ' . $this->auditKey();
        $bits[] = 'Scope: ' . ($scope['label'] ?? ($scope['scope_level'] ?? 'NATIONAL'));
        if (! empty($filters)) {
            $kv = [];
            foreach ($filters as $k => $v) {
                if ($v === null || $v === '') continue;
                $kv[] = $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v));
            }
            if ($kv) $bits[] = 'Filters: ' . implode(' · ', $kv);
        }
        $bits[] = 'Generated: ' . now()->toIso8601String();
        return [
            [], // blank row
            ['# ' . implode(' | ', $bits)],
        ];
    }
}
