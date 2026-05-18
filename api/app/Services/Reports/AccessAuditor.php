<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * AccessAuditor — append-only writer for reports_access_audit.
 *
 * Mandated by the Paranoid v2 brief §4.4. Three surfaces:
 *
 *   recordView()        — every successful render of a Reports or Governance
 *                         data() / summary() endpoint. Captures user, role,
 *                         scope descriptor, filters, row count, suppressed
 *                         cell count.
 *
 *   recordPiiReveal()   — every render that surfaces PII rows to the user
 *                         (rpt-registry, gov-notif-log recipient lines,
 *                         gov-retention traveller records, etc.) with the
 *                         masked-column list the user actually saw — so the
 *                         audit row tells the story of whether the surface
 *                         was masked, partially masked, or full PII.
 *
 *   recordDenied()      — optional: when an access policy denies the request
 *                         (ReportAccess::canSee() === false, or any other
 *                         per-view gate).
 *
 * The class is signature-agnostic to the source of the read: the report_key
 * column carries the stable view key (e.g. rpt-volume, gov-auth) and that is
 * the only way an auditor distinguishes module-of-origin. This is by design,
 * so the audit table is one place rather than several.
 *
 * Discipline:
 *   - Never throws to the caller. A failure here must NOT block the user's
 *     report or Governance read from rendering. On failure we log to
 *     laravel.log and continue.
 *   - In-transaction with the response (synchronous DB::insert).
 *   - Append-only: no update / delete methods exposed.
 *   - Stateless: no per-request memo. Call once per audit event.
 *   - Reads from the scope descriptor produced by ReportScope / PheocScope so
 *     the same shape goes to the audit table that the controller used to
 *     filter the read.
 */
final class AccessAuditor
{
    /**
     * Record a successful VIEW of a report data() endpoint.
     *
     * @param array<string,mixed>     $scope    PheocScope descriptor
     * @param array<string,mixed>     $filters  Sanitised filter set
     * @param array<string,int|null>  $counts   ['row_count' => int, 'suppressed_count' => int]
     */
    public function recordView(
        Request $request,
        array $scope,
        string $reportKey,
        array $filters = [],
        array $counts = [],
        int $httpStatus = 200,
    ): void {
        $this->writeRow($request, $scope, $reportKey, 'VIEW', [
            'filters_json'         => $this->encodeOrNull($filters),
            'row_count'            => max(0, (int) ($counts['row_count'] ?? 0)),
            'suppressed_count'     => max(0, (int) ($counts['suppressed_count'] ?? 0)),
            'pii_columns_revealed' => null,
            'http_status'          => $httpStatus,
        ]);
    }

    /**
     * Record that PII columns were rendered for a row set.
     *
     * @param array<string,mixed>  $scope
     * @param array<string,mixed>  $filters
     * @param int                  $rowCount Number of registry rows surfaced
     * @param array<int,string>    $piiColumns Columns the user actually saw unmasked
     *                                          (an empty list means everything was masked)
     */
    public function recordPiiReveal(
        Request $request,
        array $scope,
        string $reportKey,
        array $filters,
        int $rowCount,
        array $piiColumns,
    ): void {
        $this->writeRow($request, $scope, $reportKey, 'PII_REVEAL', [
            'filters_json'         => $this->encodeOrNull($filters),
            'row_count'            => max(0, $rowCount),
            'suppressed_count'     => 0,
            'pii_columns_revealed' => $this->encodeOrNull(array_values($piiColumns)),
            'http_status'          => 200,
        ]);
    }

    /**
     * Record that ReportAccess::canSee() denied the request.
     *
     * @param array<string,mixed>  $scope
     */
    public function recordDenied(
        Request $request,
        array $scope,
        string $reportKey,
    ): void {
        $this->writeRow($request, $scope, $reportKey, 'DENIED', [
            'filters_json'         => null,
            'row_count'            => 0,
            'suppressed_count'     => 0,
            'pii_columns_revealed' => null,
            'http_status'          => 403,
        ]);
    }

    /**
     * @param array<string,mixed> $scope
     * @param array<string,mixed> $extra
     */
    private function writeRow(
        Request $request,
        array $scope,
        string $reportKey,
        string $action,
        array $extra,
    ): void {
        try {
            DB::table('reports_access_audit')->insert(array_merge([
                'user_id'      => (int) ($scope['user_id'] ?? 0),
                'role_key'     => $this->stringOrEmpty($scope['role_key'] ?? null, 40),
                'account_type' => $this->stringOrNull($scope['account_type'] ?? null, 40),
                'scope_level'  => $this->stringOrEmpty($scope['scope_level'] ?? null, 20),
                'is_super'     => (bool) ($scope['is_super'] ?? false),
                'scope_json'   => $this->encodeOrNull($this->compactScope($scope)),
                'report_key'   => $this->stringOrEmpty($reportKey, 40),
                'action'       => $action,
                'request_id'   => $this->resolveRequestId($request),
                'created_at'   => now(),
            ], $extra));
        } catch (Throwable $e) {
            // Audit must never break the user-facing read.
            Log::warning('reports_access_audit insert failed', [
                'report_key' => $reportKey,
                'action'     => $action,
                'user_id'    => (int) ($scope['user_id'] ?? 0),
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * Strip the scope descriptor down to the fields auditors care about.
     * Drops large arrays (`assignments`) and the verbose user label, keeps
     * the geographic constraints that determined what the user saw.
     *
     * @param  array<string,mixed> $scope
     * @return array<string,mixed>
     */
    private function compactScope(array $scope): array
    {
        return [
            'country_code' => $scope['country_code'] ?? null,
            'countries'    => array_values((array) ($scope['countries'] ?? [])),
            'provinces'    => array_values((array) ($scope['provinces'] ?? [])),
            'districts'    => array_values((array) ($scope['districts'] ?? [])),
            'poes'         => array_values((array) ($scope['poes'] ?? [])),
            'primary_poe'  => $scope['primary_poe'] ?? null,
        ];
    }

    private function encodeOrNull(mixed $value): ?string
    {
        if ($value === null || $value === [] || $value === '') {
            return null;
        }
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }

    private function stringOrEmpty(mixed $value, int $max): string
    {
        return Str::limit((string) ($value ?? ''), $max, '');
    }

    private function stringOrNull(mixed $value, int $max): ?string
    {
        $v = (string) ($value ?? '');
        return $v === '' ? null : Str::limit($v, $max, '');
    }

    /**
     * Per-request UUID so an auditor can correlate VIEW + PII_REVEAL rows
     * issued during the same HTTP request. Set on the Request attribute bag
     * so the same id is used for every audit row in a single request.
     */
    private function resolveRequestId(Request $request): string
    {
        $existing = $request->attributes->get('reports_audit_rid');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }
        $rid = (string) Str::uuid();
        $request->attributes->set('reports_audit_rid', $rid);
        return $rid;
    }
}
