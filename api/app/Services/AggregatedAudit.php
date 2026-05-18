<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * AggregatedAudit
 * ===========================================================================
 * Append-only writer for aggregated_audit.
 *
 * Mirrors the discipline of App\Services\Reports\AccessAuditor:
 *   - Never throws to the caller. Every write is wrapped in try/catch — if
 *     the audit insert fails (table missing during a partial migration,
 *     SQL deadlock, IO error) the foreground admin action MUST succeed.
 *   - Synchronous insert. Audit lives or dies with the request.
 *   - Append-only: no update / delete methods exposed.
 *   - Stateless. Caller hands in the user, the entity reference, and
 *     before / after snapshots; nothing is memoised between calls.
 *   - Cheap on the happy path: one Schema::hasTable check (cached by
 *     Laravel) and one insertOrIgnore. If the table is absent the
 *     writer no-ops so a half-deployed environment cannot break admin.
 *
 * Vocabulary used by the admin controller:
 *
 *   action          entity_type   meaning
 *   ───────────────  ────────────  ─────────────────────────────────────
 *   CREATE          TEMPLATE      studioCreateTemplate
 *   UPDATE          TEMPLATE      studioUpdateTemplate (meta patch)
 *   PUBLISH         TEMPLATE      studioLifecycle action=PUBLISH
 *   RETIRE          TEMPLATE      studioLifecycle action=RETIRE
 *   LOCK            TEMPLATE      studioLifecycle action=LOCK
 *   UNLOCK          TEMPLATE      studioLifecycle action=UNLOCK
 *   BUMP_VERSION    TEMPLATE      studioLifecycle action=BUMP_VERSION
 *   DELETE          TEMPLATE      studioDeleteTemplate (soft delete)
 *   COLUMN_ADD      COLUMN        studioAddColumn
 *   COLUMN_UPDATE   COLUMN        studioUpdateColumn
 *   COLUMN_DELETE   COLUMN        studioDeleteColumn (soft)
 *   COLUMN_BULK     COLUMN        studioBulkColumns (whole batch as one row)
 *   SYNC_RESYNC     SUBMISSION    syncResync (force-sync)
 */
final class AggregatedAudit
{
    /**
     * Record a state change on a template / column / submission.
     *
     * @param  Request               $request
     * @param  array<string,mixed>   $scope    PheocScope descriptor
     * @param  string                $action   See vocabulary in class docblock
     * @param  string                $entityType TEMPLATE | COLUMN | SUBMISSION
     * @param  int                   $entityId   primary key of the row touched
     * @param  array<string,mixed>|null $before  pre-mutation snapshot, or null
     *                                          for create-only events
     * @param  array<string,mixed>|null $after   post-mutation snapshot, or null
     *                                          for delete events
     * @param  int|null              $templateId Denormalised template_id so
     *                                          per-template history is one index
     */
    public function record(
        Request $request,
        array $scope,
        string $action,
        string $entityType,
        int $entityId,
        ?array $before = null,
        ?array $after = null,
        ?int $templateId = null,
    ): void {
        try {
            if (! $this->tableReady()) {
                return;
            }

            DB::table('aggregated_audit')->insert([
                'user_id'      => (int) ($scope['user_id'] ?? $request->user()?->id ?? 0),
                'role_key'     => $this->stringOrNull($scope['role_key'] ?? null, 40),
                'scope_level'  => $this->stringOrNull($scope['scope_level'] ?? null, 20),
                'country_code' => $this->stringOrNull($scope['country_code'] ?? null, 30),
                'action'       => Str::limit($action, 40, ''),
                'entity_type'  => Str::limit(strtoupper($entityType), 20, ''),
                'entity_id'    => $entityId,
                'template_id'  => $templateId,
                'before_json'  => $this->encodeOrNull($before),
                'after_json'   => $this->encodeOrNull($after),
                'ip'           => $this->stringOrNull($request->ip(), 45),
                'user_agent'   => $this->stringOrNull($request->userAgent(), 255),
                'request_id'   => $this->resolveRequestId($request),
                'created_at'   => now(),
            ]);
        } catch (Throwable $e) {
            // Audit must never break the user-facing write. Log and move on.
            Log::warning('aggregated_audit insert failed', [
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pre-flight check, memoised by Laravel's Schema cache. If the table is
     * missing (e.g. fresh sqlite test, half-deployed environment) skip.
     */
    private function tableReady(): bool
    {
        try {
            return Schema::hasTable('aggregated_audit');
        } catch (Throwable) {
            return false;
        }
    }

    private function encodeOrNull(?array $value): ?string
    {
        if ($value === null || $value === []) return null;
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }

    private function stringOrNull(mixed $value, int $max): ?string
    {
        $v = (string) ($value ?? '');
        return $v === '' ? null : Str::limit($v, $max, '');
    }

    /**
     * Per-request UUID stored on the request attribute bag so multi-step
     * operations (e.g. PUBLISH that simultaneously updates the template
     * and audits a state change) share one request_id and an auditor can
     * pull the full chain with `WHERE request_id = ?`.
     */
    private function resolveRequestId(Request $request): string
    {
        $existing = $request->attributes->get('aggregated_audit_rid');
        if (is_string($existing) && $existing !== '') return $existing;
        $rid = (string) Str::uuid();
        $request->attributes->set('aggregated_audit_rid', $rid);
        return $rid;
    }
}
