<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use App\Services\PheocScope;
use App\Services\Reports\AccessAuditor;
use App\Support\System\CoachManifest;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * BaseSystemController
 * ---------------------------------------------------------------------------
 * Shared scaffolding for the four System Health views (sys-cron,
 * sys-mail, sys-mobile, sys-who). Mirrors BaseGovernanceController so
 * every System Health read uses the same scope shape, the same audit
 * writer, and the same coach manifest pattern as the rest of the admin.
 *
 * Discipline:
 *   · System Health is read-only by posture. The single permitted
 *     mutation surface — manual cron re-trigger — flows through the
 *     existing artisan command (which is the existing dispatcher path
 *     for these jobs) with a recorded audit row, and the WHO
 *     "notify-me-when-ready" affordance writes one auth_events row.
 *   · System Health is national-only. The route middleware enforces
 *     this ahead of the controller. The base does not re-check.
 *   · Audit failure NEVER breaks a user-facing read (AccessAuditor is
 *     fail-soft and we additionally swallow container-resolution
 *     errors here).
 */
abstract class BaseSystemController extends Controller
{
    /** Stable view key — must match the lang/coach_system keys. */
    abstract protected function viewKey(): string;

    /** Routing-friendly slug used as the AccessAuditor reportKey. */
    protected function auditKey(): string
    {
        return 'sys-' . $this->viewKey();
    }

    /** Coach manifest for this view. */
    protected function coach(): array
    {
        return CoachManifest::forView($this->viewKey());
    }

    /**
     * One scope descriptor for every System Health read. System Health
     * is national-only by route middleware; the descriptor is still
     * captured so the audit row carries the operator's confirmed scope.
     *
     * @return array<string,mixed>
     */
    protected function scope(Request $request): array
    {
        try {
            $user = $request->user();
            if ($user === null) {
                return [];
            }
            $pheoc = App::make(PheocScope::class);
            return $pheoc->forUser($user);
        } catch (Throwable $e) {
            Log::warning('System Health scope resolution failed', [
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
        } catch (BindingResolutionException) {
            return null;
        }
    }

    /**
     * Record a successful view of a System Health read endpoint.
     *
     * @param array<string,mixed> $filters
     * @param array<string,int>   $counts  ['row_count' => int]
     */
    protected function auditView(Request $request, array $filters = [], array $counts = []): void
    {
        try {
            $this->auditor()?->recordView(
                $request,
                $this->scope($request),
                $this->auditKey(),
                $filters,
                $counts,
            );
        } catch (Throwable $e) {
            Log::warning('System Health audit-view fail-soft', [
                'view' => $this->viewKey(), 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record that this read surfaced PII columns. Used on sys-mail
     * (recipient identifiers) and sys-mobile (operator-device
     * identifiers).
     *
     * @param array<string,mixed> $filters
     * @param array<int,string>   $piiColumns
     */
    protected function auditPiiReveal(
        Request $request,
        array $filters,
        int $rowCount,
        array $piiColumns,
    ): void {
        try {
            $this->auditor()?->recordPiiReveal(
                $request,
                $this->scope($request),
                $this->auditKey(),
                $filters,
                $rowCount,
                $piiColumns,
            );
        } catch (Throwable $e) {
            Log::warning('System Health audit-pii fail-soft', [
                'view' => $this->viewKey(), 'error' => $e->getMessage(),
            ]);
        }
    }

    /** Record an authorisation denial. */
    protected function auditDenied(Request $request): void
    {
        try {
            $this->auditor()?->recordDenied(
                $request,
                $this->scope($request),
                $this->auditKey(),
            );
        } catch (Throwable $e) {
            Log::warning('System Health audit-denied fail-soft', [
                'view' => $this->viewKey(), 'error' => $e->getMessage(),
            ]);
        }
    }

    /* ─────────── one error envelope shared by all four controllers ─────────── */

    protected function ok(array $data, string $message = '', array $meta = []): JsonResponse
    {
        $body = ['ok' => true, 'message' => $message, 'data' => $data];
        if (! empty($meta)) {
            $body['meta'] = $meta;
        }
        return response()->json($body);
    }

    protected function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json([
            'ok'      => false,
            'message' => $message,
            'error'   => $detail,
        ], $status);
    }

    protected function serverError(Throwable $e, string $context): JsonResponse
    {
        Log::error("[Admin\\System\\{$this->viewKey()}][{$context}] " . $e->getMessage(), [
            'file' => $e->getFile() . ':' . $e->getLine(),
        ]);
        return response()->json([
            'ok'      => false,
            'message' => 'Server error.',
            'error'   => ['code' => 'SERVER_ERROR', 'context' => $context],
        ], 500);
    }

    /**
     * Idempotency key extracted from the request header. Empty when
     * absent. Used by the cron manual-trigger and the WHO notify-me
     * actions to dedupe accidental double-clicks.
     */
    protected function idempotencyKey(Request $request): string
    {
        return trim((string) $request->header('Idempotency-Key', ''));
    }
}
