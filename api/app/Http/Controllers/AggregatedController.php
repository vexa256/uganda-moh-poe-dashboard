<?php

declare (strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AggregatedController
 * ECSA-HC POE Sentinel — WHO IHR 2005 Aggregated Data Submissions
 *
 * ROUTES (routes/api.php):
 *   use App\Http\Controllers\AggregatedController as AGC;
 *   Route::get  ('/aggregated',         [AGC::class,'index']);
 *   Route::post ('/aggregated',         [AGC::class,'store']);
 *   Route::get  ('/aggregated/{id}',    [AGC::class,'show']);
 *
 * IHR BASIS:
 *   WHO/IHR requires designated POEs to report summary traveller statistics
 *   at least weekly. These aggregated submissions capture both retrospective
 *   manual tallies and digital counts for periods where individual screening
 *   records may not be complete.
 *
 * BUSINESS RULES (from Part 10 of the business spec):
 *   - total_screened MUST equal total_male + total_female + total_other + total_unknown_gender
 *   - total_screened MUST equal total_symptomatic + total_asymptomatic
 *   - Periods longer than 30 days are warned (soft limit)
 *   - Client_uuid prevents duplicate submissions on re-sync
 */
final class AggregatedController extends Controller
{
    private const VALID_PLATFORMS = ['ANDROID', 'IOS', 'WEB'];
    private const MAX_PER_PAGE    = 100;

    // ── GET /aggregated ────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->query('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id query parameter is required.');
        }

        $user       = $this->resolveUser($userId);
        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $user || ! $assignment) {
            return $this->err(403, 'No active user or assignment.');
        }

        try {
            $query = DB::table('aggregated_submissions as ag')
                ->leftJoin('users as u', 'u.id', '=', 'ag.submitted_by_user_id')
                ->whereNull('ag.deleted_at');

            $roleKey = $user->role_key ?? '';
            if (in_array($roleKey, ['POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'POE_ADMIN', 'SCREENER'], true)) {
                $query->where('ag.poe_code', $assignment->poe_code);
            } elseif ($roleKey === 'DISTRICT_SUPERVISOR') {
                $query->where('ag.district_code', $assignment->district_code);
            } elseif ($roleKey === 'PHEOC_OFFICER') {
                $query->where('ag.pheoc_code', $assignment->pheoc_code);
            } else {
                $query->where('ag.country_code', $assignment->country_code);
            }

            if ($request->filled('date_from')) {
                $query->where('ag.period_start', '>=', $request->query('date_from') . ' 00:00:00');
            }
            if ($request->filled('date_to')) {
                $query->where('ag.period_end', '<=', $request->query('date_to') . ' 23:59:59');
            }
            if ($request->filled('updated_after')) {
                $after = $this->safeDatetime($request->query('updated_after'));
                if ($after) {
                    $query->where('ag.updated_at', '>', $after);
                }

            }

            $total   = (clone $query)->count();
            $perPage = min((int) $request->query('per_page', 50), self::MAX_PER_PAGE);
            $page    = max(1, (int) $request->query('page', 1));

            $items = $query
                ->select(['ag.*', 'u.full_name as submitted_by_name'])
                ->orderBy('ag.period_start', 'desc')
                ->skip(($page - 1) * $perPage)
                ->take($perPage)
                ->get();

            return response()->json([
                'success' => true, 'message' => 'Aggregated submissions retrieved.',
                'data'    => [
                    'items'    => $items->map(fn($r) => $this->formatSubmission($r))->values(),
                    'total'    => $total,
                    'per_page' => $perPage,
                    'page'     => $page,
                    'pages'    => (int) ceil($total / max(1, $perPage)),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'aggregated index');
        }
    }

    // ── POST /aggregated ───────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        $userId = (int) $request->input('submitted_by_user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'submitted_by_user_id is required.');
        }

        $user = $this->resolveUser($userId);
        if (! $user || ! (bool) $user->is_active) {
            return $this->err(403, 'User not found or inactive.');
        }

        // Permission check — POE-level data officers/admins plus any
        // supervisor-level role (DISTRICT, PHEOC, NATIONAL) attached to this
        // POE's jurisdiction. NATIONAL_ADMIN has unconditional access.
        $roleKey = $user->role_key ?? '';
        $permittedRoles = [
            'POE_DATA_OFFICER', 'POE_ADMIN',
            'DISTRICT_SUPERVISOR', 'PHEOC_OFFICER', 'NATIONAL_ADMIN',
        ];
        if (! in_array($roleKey, $permittedRoles, true)) {
            return $this->err(403, 'Your role does not have permission to submit aggregated data.', [
                'your_role'       => $roleKey,
                'permitted_roles' => $permittedRoles,
            ]);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active geographic assignment.');
        }

        $clientUuid = (string) $request->input('client_uuid', '');
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clientUuid)) {
            return $this->err(422, 'client_uuid must be a valid UUID v4.');
        }

        // Parse and validate period
        $periodStart = $this->safeDatetime($request->input('period_start'));
        $periodEnd   = $this->safeDatetime($request->input('period_end'));
        if (! $periodStart || ! $periodEnd) {
            return $this->err(422, 'period_start and period_end are required (format: YYYY-MM-DD HH:MM:SS).');
        }
        if (strtotime($periodStart) >= strtotime($periodEnd)) {
            return $this->err(422, 'period_end must be after period_start.', [
                'period_start' => $periodStart, 'period_end' => $periodEnd,
            ]);
        }
        if (strtotime($periodStart) > time()) {
            return $this->err(422, 'period_start cannot be in the future.');
        }
        $periodDays = (strtotime($periodEnd) - strtotime($periodStart)) / 86400;
        $warnings   = [];
        if ($periodDays > 30) {
            $warnings[] = "Reporting period is {$periodDays} days. Periods longer than 30 days are unusual — verify this is correct.";
        }

        // Parse counts
        $totalScreened     = max(0, (int) $request->input('total_screened', 0));
        $totalMale         = max(0, (int) $request->input('total_male', 0));
        $totalFemale       = max(0, (int) $request->input('total_female', 0));
        $totalOther        = max(0, (int) $request->input('total_other', 0));
        $totalUnknown      = max(0, (int) $request->input('total_unknown_gender', 0));
        $totalSymptomatic  = max(0, (int) $request->input('total_symptomatic', 0));
        $totalAsymptomatic = max(0, (int) $request->input('total_asymptomatic', 0));

        // Business rule validation
        $genderSum = $totalMale + $totalFemale + $totalOther + $totalUnknown;
        if ($genderSum !== $totalScreened) {
            $warnings[] = "Gender counts ({$genderSum}) do not add up to total_screened ({$totalScreened}). Submission accepted — verify figures.";
        }
        $symptomSum = $totalSymptomatic + $totalAsymptomatic;
        if ($symptomSum !== $totalScreened) {
            $warnings[] = "Symptomatic + Asymptomatic ({$symptomSum}) do not equal total_screened ({$totalScreened}). Submission accepted — verify figures.";
        }

        try {
            // Idempotency check
            $existing = DB::table('aggregated_submissions')->where('client_uuid', $clientUuid)->first();
            if ($existing) {
                return $this->ok(
                    $this->formatSubmission($existing),
                    'Aggregated submission already exists (idempotent resubmit).',
                    ['idempotent' => true, 'server_id' => $existing->id, 'warnings' => $warnings]
                );
            }

            $now = now()->format('Y-m-d H:i:s');

            // Template context — links submission back to the template that
            // produced it. Required for the multi-published templates era so
            // dashboards can roll up per-template stats and the force-delete
            // flow (AggregatedTemplatesController::destroy) can count
            // submissions per template. Best-effort: if the template does
            // not exist we still accept the submission but drop the linkage.
            $tplId       = $request->input('template_id');
            $tplCode     = $request->input('template_code');
            $tplVersion  = $request->input('template_version');
            if ($tplId) {
                $exists = DB::table('aggregated_templates')->where('id', $tplId)->whereNull('deleted_at')->exists();
                if (! $exists) {
                    $warnings[] = "template_id {$tplId} not found — submission stored without template linkage.";
                    $tplId = null;
                }
            }

            $subId = DB::table('aggregated_submissions')->insertGetId([
                'client_uuid'            => $clientUuid,
                'idempotency_key'        => null,
                'reference_data_version' => $request->input('reference_data_version', 'rda-2026-02-01'),
                'server_received_at'     => $now,
                'country_code'           => $assignment->country_code,
                'province_code'          => $assignment->province_code,
                'pheoc_code'             => $assignment->pheoc_code,
                'district_code'          => $assignment->district_code,
                'poe_code'               => $assignment->poe_code,
                'submitted_by_user_id'   => $userId,
                'period_start'           => $periodStart,
                'period_end'             => $periodEnd,
                'total_screened'         => $totalScreened,
                'total_male'             => $totalMale,
                'total_female'           => $totalFemale,
                'total_other'            => $totalOther,
                'total_unknown_gender'   => $totalUnknown,
                'total_symptomatic'      => $totalSymptomatic,
                'total_asymptomatic'     => $totalAsymptomatic,
                'notes'                  => $request->input('notes') ? substr($request->input('notes'), 0, 255) : null,
                'template_id'            => $tplId,
                'template_code'          => $tplCode ? substr((string) $tplCode, 0, 60) : null,
                'template_version'       => $tplVersion !== null ? (int) $tplVersion : null,
                'device_id'              => $request->input('device_id', 'unknown'),
                'app_version'            => $request->input('app_version'),
                'platform'               => in_array(strtoupper((string) $request->input('platform', 'ANDROID')), self::VALID_PLATFORMS, true)
                    ? strtoupper($request->input('platform')) : 'ANDROID',
                'record_version'         => (int) $request->input('record_version', 1),
                'deleted_at'             => null,
                'sync_status'            => 'SYNCED',
                'synced_at'              => $now,
                'sync_attempt_count'     => 0,
                'last_sync_error'        => null,
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);

            // Persist the dynamic template_values array (if provided). Each
            // entry becomes a row in aggregated_submission_values. Idempotent
            // via (submission_id, column_key) unique index — upsert shape.
            $tplValues = $request->input('template_values');
            if ($tplId && is_array($tplValues)) {
                foreach ($tplValues as $v) {
                    if (! is_array($v) || empty($v['column_key'])) continue;
                    $colRow = DB::table('aggregated_template_columns')
                        ->where('template_id', $tplId)
                        ->where('column_key', (string) $v['column_key'])
                        ->whereNull('deleted_at')->first();
                    if (! $colRow) continue; // silently skip unknown keys

                    // Mobile encodes BOOLEAN columns as `value_boolean: true|false|null`
                    // (see src/views/AggregatedData.vue line ~490). The schema has no
                    // value_boolean column — the engine and admin views read booleans
                    // back from value_numeric (0/1). Coerce here so the data lands
                    // somewhere instead of being silently dropped. Only applied when
                    // the payload did not already supply a numeric value, so existing
                    // callers and the documented input contract are unchanged.
                    $valueNumeric = isset($v['value_numeric']) && is_numeric($v['value_numeric']) ? $v['value_numeric'] : null;
                    if ($valueNumeric === null && array_key_exists('value_boolean', $v) && is_bool($v['value_boolean'])) {
                        $valueNumeric = $v['value_boolean'] ? 1 : 0;
                    }

                    DB::table('aggregated_submission_values')->insertOrIgnore([
                        'submission_id'      => $subId,
                        'template_id'        => $tplId,
                        'template_column_id' => $colRow->id,
                        'column_key'         => substr((string) $v['column_key'], 0, 60),
                        'value_numeric'      => $valueNumeric,
                        'value_text'         => isset($v['value_text']) ? substr((string) $v['value_text'], 0, 500) : null,
                        'value_json'         => isset($v['value_json']) ? json_encode($v['value_json']) : null,
                        'created_at'         => $now,
                        'updated_at'         => $now,
                    ]);
                }
            }

            $submission = DB::table('aggregated_submissions')->where('id', $subId)->first();

            Log::info('[Aggregated][store] Submission created', [
                'sub_id'         => $subId,
                'poe_code'       => $assignment->poe_code,
                'period_start'   => $periodStart,
                'period_end'     => $periodEnd,
                'total_screened' => $totalScreened,
            ]);

            return $this->ok(
                $this->formatSubmission($submission),
                'Aggregated submission received and stored.',
                ['server_id' => $subId, 'idempotent' => false, 'warnings' => $warnings]
            );
        } catch (Throwable $e) {
            return $this->serverError($e, 'aggregated store');
        }
    }

    // ── GET /aggregated/{id} ───────────────────────────────────────────────────

    public function show(Request $request, int $id): JsonResponse
    {
        $userId     = (int) $request->query('user_id', 0);
        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment.');
        }

        try {
            $sub = DB::table('aggregated_submissions')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $sub) {
                return $this->err(404, 'Aggregated submission not found.', ['id' => $id]);
            }
            return $this->ok($this->formatSubmission($sub), 'Submission retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'aggregated show');
        }
    }

    // ── PRIVATE HELPERS ────────────────────────────────────────────────────────

    private function resolveUser(int $id): ?object
    {
        return DB::table('users')->where('id', $id)->first() ?: null;
    }

    private function resolvePrimaryAssignment(int $userId): ?object
    {
        return DB::table('user_assignments')
            ->where('user_id', $userId)->where('is_primary', 1)->where('is_active', 1)
            ->where(fn($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->first() ?: null;
    }

    private function formatSubmission(object $sub): array
    {
        return [
            'id'                     => (int) $sub->id,
            'client_uuid'            => $sub->client_uuid,
            'reference_data_version' => $sub->reference_data_version,
            'server_received_at'     => $sub->server_received_at,
            'country_code'           => $sub->country_code,
            'province_code'          => $sub->province_code,
            'pheoc_code'             => $sub->pheoc_code,
            'district_code'          => $sub->district_code,
            'poe_code'               => $sub->poe_code,
            'submitted_by_user_id'   => (int) $sub->submitted_by_user_id,
            'submitted_by_name'      => $sub->submitted_by_name ?? null,
            'period_start'           => $sub->period_start,
            'period_end'             => $sub->period_end,
            'total_screened'         => (int) $sub->total_screened,
            'total_male'             => (int) $sub->total_male,
            'total_female'           => (int) $sub->total_female,
            'total_other'            => (int) $sub->total_other,
            'total_unknown_gender'   => (int) $sub->total_unknown_gender,
            'total_symptomatic'      => (int) $sub->total_symptomatic,
            'total_asymptomatic'     => (int) $sub->total_asymptomatic,
            'notes'                  => $sub->notes,
            'device_id'              => $sub->device_id,
            'app_version'            => $sub->app_version,
            'platform'               => $sub->platform,
            'record_version'         => (int) $sub->record_version,
            'sync_status'            => $sub->sync_status ?? 'SYNCED',
            'synced_at'              => $sub->synced_at,
            'created_at'             => $sub->created_at,
            'updated_at'             => $sub->updated_at,
        ];
    }

    private function safeDatetime(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => $data];
        if (! empty($meta)) {
            $body['meta'] = $meta;
        }

        return response()->json($body, 200);
    }

    private function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error' => $detail], $status);
    }

    private function serverError(Throwable $e, string $ctx): JsonResponse
    {
        Log::error("[Aggregated][ERROR] {$ctx}", ['exception' => get_class($e), 'message' => $e->getMessage()]);
        return response()->json(['success' => false, 'message' => "Server error during: {$ctx}", 'error' => ['message' => $e->getMessage()]], 500);
    }
}
