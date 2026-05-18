<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Governance;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin · Governance · Retention & PII (gov-retention)
 * ---------------------------------------------------------------------------
 * secondary_screenings is the only table carrying named-traveller PII:
 *   traveler_full_name · traveler_initials · date_of_birth ·
 *   passport_number · phone_number · email · emergency_contact_*.
 *
 * This surface:
 *   · retention clock    — age distribution of PII rows vs the configured window
 *   · PII coverage       — % of rows that actually carry each PII field
 *   · export log         — audit rows where traveller PII left the system
 *                          (derived from auth_events EXPORT + ADMIN_UPDATED
 *                          with entity=secondary_screening_export, plus any
 *                          user_audit_log rows with action=EXPORT).
 *   · country distribution — which passports are held
 *
 * Mobile contract: NONE. Read-only, no table is mutated.
 * Gate: NATIONAL_ADMIN only (PII exposure).
 */
final class RetentionController extends BaseGovernanceController
{
    protected function viewKey(): string
    {
        return 'retention';
    }

    /** Default retention policy — WHO case data default 7 years. */
    private const RETENTION_DAYS = 2555;

    /** Extra PII-carrying tables the view surfaces for completeness. */
    private const SECONDARY = 'secondary_screenings';

    public function index(Request $request)
    {
        return view('admin.governance.retention.index', [
            'page_title'    => 'Retention & PII',
            'page_eyebrow'  => 'Governance',
            'page_subtitle' => 'secondary_screenings · the only PII home · retention clock · export log.',
            'retention_days'=> self::RETENTION_DAYS,
            'coach'         => $this->coach(),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $now = now();
            $table = self::SECONDARY;
            if (! Schema::hasTable($table)) {
                return response()->json(['ok' => true, 'data' => $this->emptyPayload($now)]);
            }

            $base = DB::table($table)->whereNull('deleted_at');
            $total   = (int) (clone $base)->count();
            $voided  = Schema::hasColumn($table, 'deleted_at')
                ? (int) DB::table($table)->whereNotNull('deleted_at')->count() : 0;

            // ── Age distribution (days since created_at) ────────────────
            $buckets = [
                ['key' => '0-7d',    'lo' => 0,    'hi' => 7],
                ['key' => '8-30d',   'lo' => 8,    'hi' => 30],
                ['key' => '31-90d',  'lo' => 31,   'hi' => 90],
                ['key' => '91-365d', 'lo' => 91,   'hi' => 365],
                ['key' => '1-3y',    'lo' => 366,  'hi' => 1095],
                ['key' => '3-7y',    'lo' => 1096, 'hi' => 2555],
                ['key' => '7y+',     'lo' => 2556, 'hi' => 99999],
            ];
            foreach ($buckets as &$b) {
                $b['n'] = (int) DB::table($table)
                    ->whereNull('deleted_at')
                    ->whereRaw('DATEDIFF(?, created_at) BETWEEN ? AND ?', [$now, $b['lo'], $b['hi']])
                    ->count();
            }
            unset($b);

            // ── Breached retention (older than threshold, not voided) ──
            $breachedTotal = (int) DB::table($table)
                ->whereNull('deleted_at')
                ->whereRaw('DATEDIFF(?, created_at) > ?', [$now, self::RETENTION_DAYS])->count();
            $approaching = (int) DB::table($table)
                ->whereNull('deleted_at')
                ->whereRaw('DATEDIFF(?, created_at) BETWEEN ? AND ?', [
                    $now, (int) (self::RETENTION_DAYS * 0.9), self::RETENTION_DAYS,
                ])->count();

            // ── PII coverage per column ────────────────────────────────
            $piiCols = [
                'traveler_full_name', 'traveler_initials', 'traveler_anonymous_code',
                'traveler_date_of_birth', 'traveler_passport_number', 'phone_number',
                'alternative_phone', 'email', 'emergency_contact_name', 'emergency_contact_phone',
            ];
            $coverage = [];
            foreach ($piiCols as $col) {
                if (! Schema::hasColumn($table, $col)) continue;
                $n = $total > 0
                    ? (int) DB::table($table)->whereNull('deleted_at')
                        ->whereNotNull($col)->where($col, '<>', '')->count()
                    : 0;
                $coverage[] = [
                    'column' => $col, 'n' => $n,
                    'pct'    => $total > 0 ? round(100 * $n / $total, 1) : 0.0,
                ];
            }

            // ── Nationality distribution (top 10) ───────────────────────
            $natCol = Schema::hasColumn($table, 'traveler_nationality_country_code')
                ? 'traveler_nationality_country_code' : null;
            $byNation = [];
            if ($natCol) {
                $byNation = DB::table($table)
                    ->whereNull('deleted_at')
                    ->whereNotNull($natCol)
                    ->selectRaw("{$natCol} AS country_code, COUNT(*) AS n")
                    ->groupBy('country_code')
                    ->orderByDesc('n')->limit(10)->get()
                    ->map(fn ($r) => ['country_code' => (string) $r->country_code, 'n' => (int) $r->n])
                    ->all();
            }

            // ── Creation trend last 90 days ────────────────────────────
            $trend = [];
            $cursor = (clone $now)->subDays(89)->startOfDay();
            for ($i = 0; $i < 90; $i++) {
                $trend[$cursor->format('Y-m-d')] = ['day' => $cursor->format('Y-m-d'), 'n' => 0];
                $cursor->addDay();
            }
            $rows = DB::table($table)
                ->selectRaw('DATE(created_at) AS d, COUNT(*) AS n')
                ->whereNull('deleted_at')
                ->where('created_at', '>=', (clone $now)->subDays(89)->startOfDay())
                ->groupBy('d')->get();
            foreach ($rows as $r) {
                $k = (string) $r->d;
                if (isset($trend[$k])) $trend[$k]['n'] = (int) $r->n;
            }

            // ── Export log (best-effort over audit trails) ─────────────
            $exportLog = $this->exportLog(200);

            // Audit: summary surfaces aggregate-only data plus the export
            // log itself (which lists who exported what and when —
            // operationally sensitive but contains no traveller names).
            $this->auditView($request, [], ['row_count' => (int) $total]);

            return response()->json(['ok' => true, 'data' => [
                'server_time'    => $now->toIso8601String(),
                'retention_days' => self::RETENTION_DAYS,
                'totals' => [
                    'total'              => $total,
                    'voided'             => $voided,
                    'breached_retention' => $breachedTotal,
                    'approaching'        => $approaching,
                ],
                'age_buckets'  => $buckets,
                'coverage'     => $coverage,
                'by_nation'    => $byNation,
                'trend_90d'    => array_values($trend),
                'export_log'   => $exportLog,
            ]]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'summary');
        }
    }

    public function breached(Request $request): JsonResponse
    {
        try {
            $table = self::SECONDARY;
            if (! Schema::hasTable($table)) {
                return response()->json(['ok' => true, 'data' => ['rows' => []]]);
            }

            $cols = array_values(array_filter(
                ['id', 'client_uuid', 'traveler_anonymous_code', 'traveler_full_name',
                 'traveler_nationality_country_code', 'created_at', 'poe_code', 'district_code'],
                fn ($c) => Schema::hasColumn($table, $c),
            ));

            $rows = DB::table($table)
                ->whereNull('deleted_at')
                ->whereRaw('DATEDIFF(?, created_at) > ?', [now(), self::RETENTION_DAYS])
                ->orderBy('created_at')
                ->limit(200)->get($cols)->map(fn ($r) => (array) $r)->all();

            foreach ($rows as &$row) {
                if (isset($row['created_at'])) {
                    $row['days_old'] = (int) Carbon::parse($row['created_at'])->diffInDays(now());
                }
                // redact the name in list view — detail page would gate-check further.
                if (isset($row['traveler_full_name']) && $row['traveler_full_name']) {
                    $row['traveler_full_name'] = $this->redactName((string) $row['traveler_full_name']);
                }
            }
            unset($row);

            // Audit: breached rows ARE traveller PII (full_name, passport
            // info via client_uuid context). Even with the redact step
            // above, the row set itself names the cohort. Log a PII
            // reveal explicitly.
            $this->auditView($request, [], ['row_count' => count($rows)]);
            if (! empty($rows)) {
                $this->auditPiiReveal(
                    $request, [], count($rows),
                    ['traveler_full_name', 'traveler_anonymous_code', 'traveler_nationality_country_code', 'client_uuid'],
                );
            }

            return response()->json(['ok' => true, 'data' => ['rows' => $rows]]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'breached');
        }
    }

    public function recordExport(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'purpose'    => ['required', 'string', 'min:10', 'max:300'],
                'row_count'  => ['required', 'integer', 'min:1', 'max:1000000'],
                'recipient'  => ['required', 'string', 'min:3', 'max:160'],
                'row_filter' => ['nullable', 'string', 'max:300'],
            ]);

            $user = $request->user();

            // Audit: log the export BEFORE writing to AuthEventLogger so
            // there is no window in which a successful PII export goes
            // unaudited. recordPiiReveal carries the explicit PII
            // columns the file is being generated with so that future
            // auditors can reconstruct exactly what left the system.
            $this->auditPiiReveal(
                $request, $validated, (int) $validated['row_count'],
                ['traveler_full_name', 'date_of_birth', 'passport_number', 'phone_number', 'email', 'emergency_contact'],
            );

            \App\Services\AuthEventLogger::log(
                'ADMIN_UPDATED', (int) $user->id, null, 'WARN',
                [
                    'entity'    => 'secondary_screening_export',
                    'purpose'   => $validated['purpose'],
                    'row_count' => $validated['row_count'],
                    'recipient' => $validated['recipient'],
                    'filter'    => $validated['row_filter'] ?? null,
                ],
                5, $request,
            );

            return response()->json(['ok' => true]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'validation', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return $this->serverError($e, 'recordExport');
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function exportLog(int $limit): array
    {
        $rows = [];
        // auth_events path
        if (Schema::hasTable('auth_events')) {
            $aeRows = DB::table('auth_events')
                ->where('event_type', 'ADMIN_UPDATED')
                ->whereRaw("JSON_EXTRACT(payload_json, '$.entity') = '\"secondary_screening_export\"'")
                ->orderByDesc('id')->limit($limit)->get()
                ->map(function ($r) {
                    $p = is_string($r->payload_json) ? json_decode($r->payload_json, true) : null;
                    return [
                        'source'     => 'auth_events',
                        'id'         => (int) $r->id,
                        'created_at' => (string) $r->created_at,
                        'user_id'    => $r->user_id ? (int) $r->user_id : null,
                        'purpose'    => is_array($p) ? ($p['purpose'] ?? '') : '',
                        'recipient'  => is_array($p) ? ($p['recipient'] ?? '') : '',
                        'row_count'  => is_array($p) ? (int) ($p['row_count'] ?? 0) : 0,
                    ];
                });
            foreach ($aeRows as $r) $rows[] = $r;
        }
        // user_audit_log path (if EXPORT actions are written there)
        if (Schema::hasTable('user_audit_log')) {
            $aRows = DB::table('user_audit_log')
                ->where('action', 'EXPORT')
                ->orderByDesc('id')->limit($limit)->get()
                ->map(fn ($r) => [
                    'source'     => 'user_audit_log',
                    'id'         => (int) $r->id,
                    'created_at' => (string) $r->created_at,
                    'user_id'    => $r->actor_user_id ? (int) $r->actor_user_id : null,
                    'purpose'    => '',
                    'recipient'  => '',
                    'row_count'  => 0,
                ]);
            foreach ($aRows as $r) $rows[] = $r;
        }
        usort($rows, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return array_slice($rows, 0, $limit);
    }

    private function redactName(string $name): string
    {
        $parts = array_filter(preg_split('/\s+/', trim($name)) ?: []);
        $initials = array_map(fn ($p) => strtoupper(mb_substr($p, 0, 1)) . '.', $parts);
        return implode(' ', $initials) ?: '—';
    }

    private function emptyPayload(Carbon $now): array
    {
        return [
            'server_time' => $now->toIso8601String(),
            'retention_days' => self::RETENTION_DAYS,
            'totals' => ['total' => 0, 'voided' => 0, 'breached_retention' => 0, 'approaching' => 0],
            'age_buckets' => [], 'coverage' => [], 'by_nation' => [], 'trend_90d' => [], 'export_log' => [],
        ];
    }
}
