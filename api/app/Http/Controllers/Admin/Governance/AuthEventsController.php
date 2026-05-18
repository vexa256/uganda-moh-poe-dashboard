<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Governance;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Admin · Governance · Auth Events (gov-auth)
 * ---------------------------------------------------------------------------
 * Read-only forensic window onto the auth_events append-only audit table plus
 * the live user_anomaly_flags feed, lockouts (`users.locked_until > now()`),
 * suspensions (`users.suspended_at NOT NULL`) and 2FA summary.
 *
 * Mobile contract: NONE. auth_events is written by AuthEventLogger from
 * several controllers; nothing here mutates it. Writes limited to the
 * "clear anomaly flag" endpoint, which lives in user_anomaly_flags (not a
 * mobile table).
 *
 * Gate: NATIONAL_ADMIN only. These rows carry PII (IPs, UAs, failed-login
 * emails) and operational security signals.
 *
 * Summary payload is intentionally viz-oriented — every structure the
 * controller emits is consumed directly by an SVG chart in the Blade view
 * (sparkline, hourly heatmap, severity donut, login funnel, event ranking,
 * IP risk bars). No JS chart library is installed; charts are built from
 * the raw numbers below.
 */
final class AuthEventsController extends BaseGovernanceController
{
    protected function viewKey(): string
    {
        return 'auth';
    }

    private const PAGE_MAX      = 200;
    private const WINDOW_HOURS  = 168; // 7 days default window
    private const ALLOWED_SEV   = ['INFO', 'WARN', 'ERROR', 'CRITICAL'];
    private const KNOWN_EVENTS  = [
        'LOGIN_OK', 'LOGIN_FAIL', 'LOGOUT', 'LOCKED', 'UNLOCKED',
        'PASSWORD_CHANGED', 'PASSWORD_RESET_REQUESTED', 'PASSWORD_RESET_USED',
        'EMAIL_VERIFY_SENT', 'EMAIL_VERIFIED', 'EMAIL_CHANGE_REQUESTED', 'EMAIL_CHANGED',
        'TWOFA_ENABLED', 'TWOFA_DISABLED', 'TWOFA_CHALLENGED', 'TWOFA_OK', 'TWOFA_FAIL',
        'TRUSTED_DEVICE_ADDED', 'TRUSTED_DEVICE_REMOVED', 'TRUSTED_DEVICE_USED',
        'WEBAUTHN_REGISTERED', 'WEBAUTHN_REMOVED', 'WEBAUTHN_USED',
        'ADMIN_CREATED', 'ADMIN_UPDATED', 'ADMIN_SUSPENDED', 'ADMIN_REACTIVATED',
        'ROLE_CHANGED', 'ASSIGNMENT_CHANGED',
        'INVITATION_SENT', 'INVITATION_ACCEPTED', 'INVITATION_EXPIRED',
        'SESSION_REVOKED', 'TOKEN_REVOKED',
        'LOGIN_RISK_HIGH', 'ANOMALY_FLAGGED', 'FORBIDDEN',
    ];

    public function index(Request $request)
    {
        return view('admin.governance.auth-events.index', [
            'page_title'    => 'Auth Events',
            'page_eyebrow'  => 'Governance',
            'page_subtitle' => 'Login · MFA · lockouts · suspended users · auth_events feed.',
            'known_events'  => self::KNOWN_EVENTS,
            'coach'         => $this->coach(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        try {
            [$from, $to] = $this->window($request);

            $validated = $request->validate([
                'q'          => ['nullable', 'string', 'max:120'],
                'event_type' => ['nullable', 'string', 'max:60'],
                'severity'   => ['nullable', 'string', 'in:INFO,WARN,ERROR,CRITICAL'],
                'user_id'    => ['nullable', 'integer'],
                'ip'         => ['nullable', 'string', 'max:45'],
                'page'       => ['nullable', 'integer', 'min:1', 'max:10000'],
                'per_page'   => ['nullable', 'integer', 'min:10', 'max:' . self::PAGE_MAX],
            ]);

            $perPage = (int) ($validated['per_page'] ?? 50);
            $page    = (int) ($validated['page']     ?? 1);

            $q = DB::table('auth_events as ae')
                ->leftJoin('users as u', 'u.id', '=', 'ae.user_id')
                ->select([
                    'ae.id', 'ae.user_id', 'ae.email_attempted', 'ae.event_type',
                    'ae.severity', 'ae.ip', 'ae.user_agent', 'ae.city', 'ae.country',
                    'ae.payload_json', 'ae.risk_delta', 'ae.created_at',
                    'u.full_name as user_name', 'u.username as user_username',
                    'u.email as user_email', 'u.role_key as user_role',
                ])
                ->whereBetween('ae.created_at', [$from, $to]);

            if (! empty($validated['event_type']) && in_array($validated['event_type'], self::KNOWN_EVENTS, true)) {
                $q->where('ae.event_type', $validated['event_type']);
            }
            if (! empty($validated['severity'])) {
                $q->where('ae.severity', $validated['severity']);
            }
            if (! empty($validated['user_id'])) {
                $q->where('ae.user_id', (int) $validated['user_id']);
            }
            if (! empty($validated['ip'])) {
                $q->where('ae.ip', $validated['ip']);
            }
            if (! empty($validated['q'])) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $validated['q']) . '%';
                $q->where(function ($w) use ($like): void {
                    $w->where('ae.email_attempted', 'like', $like)
                      ->orWhere('u.full_name',     'like', $like)
                      ->orWhere('u.username',      'like', $like)
                      ->orWhere('u.email',         'like', $like)
                      ->orWhere('ae.ip',           'like', $like)
                      ->orWhere('ae.event_type',   'like', $like);
                });
            }

            $total = (clone $q)->count();
            $rows  = $q->orderByDesc('ae.id')
                ->forPage($page, $perPage)
                ->get()
                ->map(fn ($r) => [
                    'id'              => (int) $r->id,
                    'created_at'      => $r->created_at,
                    'event_type'      => (string) $r->event_type,
                    'severity'        => (string) $r->severity,
                    'risk_delta'      => (int) $r->risk_delta,
                    'user_id'         => $r->user_id ? (int) $r->user_id : null,
                    'user_name'       => $r->user_name,
                    'user_username'   => $r->user_username,
                    'user_email'      => $r->user_email,
                    'user_role'       => $r->user_role,
                    'email_attempted' => $r->email_attempted,
                    'ip'              => $r->ip,
                    'user_agent'      => $r->user_agent,
                    'city'            => $r->city,
                    'country'         => $r->country,
                    'payload'         => $this->decodeJson($r->payload_json),
                ])
                ->all();

            // Audit: rows on this surface include IPs, user-agents, and the
            // attempted-email address, all of which are PII or sensitive
            // operational signals. Log the view AND a PII reveal naming the
            // unmasked columns the operator just saw.
            $this->auditView($request, $validated, ['row_count' => count($rows)]);
            if (! empty($rows)) {
                $this->auditPiiReveal(
                    $request,
                    $validated,
                    count($rows),
                    ['email_attempted', 'user_email', 'ip', 'user_agent', 'city', 'country'],
                );
            }

            return response()->json([
                'ok'   => true,
                'data' => [
                    'rows'     => $rows,
                    'page'     => $page,
                    'per_page' => $perPage,
                    'total'    => $total,
                    'pages'    => (int) ceil(max($total, 1) / $perPage),
                    'window'   => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'validation', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return $this->serverError($e, 'data');
        }
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            [$from, $to] = $this->window($request);
            $now         = now();
            $hours       = (int) $from->diffInHours($to);

            // ── Event-type ranking (horizontal-bar chart data) ──────────
            $byType = DB::table('auth_events')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('event_type, COUNT(*) AS n, SUM(risk_delta) AS risk_sum')
                ->groupBy('event_type')
                ->orderByDesc('n')
                ->get()
                ->map(fn ($r) => [
                    'event_type' => (string) $r->event_type,
                    'n'          => (int) $r->n,
                    'risk_sum'   => (int) $r->risk_sum,
                ])
                ->all();

            // ── Severity donut ──────────────────────────────────────────
            $bySeverity = DB::table('auth_events')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('severity, COUNT(*) AS n')
                ->groupBy('severity')
                ->get()
                ->mapWithKeys(fn ($r) => [(string) $r->severity => (int) $r->n])
                ->all();
            foreach (self::ALLOWED_SEV as $s) {
                $bySeverity[$s] = (int) ($bySeverity[$s] ?? 0);
            }

            // ── Hourly sparkline (ordered buckets) ──────────────────────
            $hourlyRows = DB::table('auth_events')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS h, "
                    . 'COUNT(*) AS n, '
                    . 'SUM(CASE WHEN event_type="LOGIN_OK" THEN 1 ELSE 0 END) AS ok_n, '
                    . 'SUM(CASE WHEN event_type="LOGIN_FAIL" THEN 1 ELSE 0 END) AS fail_n, '
                    . 'SUM(CASE WHEN severity IN ("ERROR","CRITICAL") THEN 1 ELSE 0 END) AS err_n')
                ->groupBy('h')->orderBy('h')->get()
                ->keyBy('h');

            $hourly = [];
            $cursor = (clone $from)->startOfHour();
            while ($cursor <= $to) {
                $key = $cursor->format('Y-m-d H:00:00');
                $row = $hourlyRows[$key] ?? null;
                $hourly[] = [
                    'hour'   => $cursor->toIso8601String(),
                    'n'      => $row ? (int) $row->n      : 0,
                    'ok_n'   => $row ? (int) $row->ok_n   : 0,
                    'fail_n' => $row ? (int) $row->fail_n : 0,
                    'err_n'  => $row ? (int) $row->err_n  : 0,
                ];
                $cursor->addHour();
            }

            // ── Day-of-week × hour-of-day heatmap (7×24) ────────────────
            $heatRows = DB::table('auth_events')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('DAYOFWEEK(created_at) AS dow, HOUR(created_at) AS hod, COUNT(*) AS n')
                ->groupBy('dow', 'hod')->get();
            $heatmap = array_fill(0, 7, array_fill(0, 24, 0));
            foreach ($heatRows as $r) {
                // MySQL DAYOFWEEK: 1=Sun..7=Sat → shift to 0=Mon..6=Sun.
                $d = (int) $r->dow;
                $dow = [1 => 6, 2 => 0, 3 => 1, 4 => 2, 5 => 3, 6 => 4, 7 => 5][$d] ?? 0;
                $hod = max(0, min(23, (int) $r->hod));
                $heatmap[$dow][$hod] = (int) $r->n;
            }

            // ── Login funnel ────────────────────────────────────────────
            $loginOk   = (int) DB::table('auth_events')
                ->whereBetween('created_at', [$from, $to])
                ->where('event_type', 'LOGIN_OK')->count();
            $loginFail = (int) DB::table('auth_events')
                ->whereBetween('created_at', [$from, $to])
                ->where('event_type', 'LOGIN_FAIL')->count();
            $twoFaChallenged = (int) DB::table('auth_events')
                ->whereBetween('created_at', [$from, $to])
                ->where('event_type', 'TWOFA_CHALLENGED')->count();
            $twoFaOk = (int) DB::table('auth_events')
                ->whereBetween('created_at', [$from, $to])
                ->where('event_type', 'TWOFA_OK')->count();
            $twoFaFail = (int) DB::table('auth_events')
                ->whereBetween('created_at', [$from, $to])
                ->where('event_type', 'TWOFA_FAIL')->count();
            $lockedEvt = (int) DB::table('auth_events')
                ->whereBetween('created_at', [$from, $to])
                ->where('event_type', 'LOCKED')->count();

            $loginTotal = $loginOk + $loginFail;
            $successPct = $loginTotal > 0 ? round(100 * $loginOk / $loginTotal, 1) : 0.0;

            // ── IP risk ranking ─────────────────────────────────────────
            $topIps = DB::table('auth_events')
                ->whereBetween('created_at', [$from, $to])
                ->whereNotNull('ip')
                ->selectRaw('ip, COUNT(*) AS n, '
                    . 'SUM(CASE WHEN event_type="LOGIN_FAIL" THEN 1 ELSE 0 END) AS fails, '
                    . 'SUM(CASE WHEN event_type="LOGIN_OK"   THEN 1 ELSE 0 END) AS oks, '
                    . 'COUNT(DISTINCT user_id) AS users_seen')
                ->groupBy('ip')
                ->orderByDesc('fails')
                ->orderByDesc('n')
                ->limit(12)
                ->get()
                ->map(fn ($r) => [
                    'ip'         => (string) $r->ip,
                    'n'          => (int) $r->n,
                    'fails'      => (int) $r->fails,
                    'oks'        => (int) $r->oks,
                    'users_seen' => (int) $r->users_seen,
                ])
                ->all();

            // ── Role breakdown of logins (stacked bar data) ─────────────
            $byRole = DB::table('auth_events as ae')
                ->leftJoin('users as u', 'u.id', '=', 'ae.user_id')
                ->whereBetween('ae.created_at', [$from, $to])
                ->whereIn('ae.event_type', ['LOGIN_OK', 'LOGIN_FAIL'])
                ->selectRaw('COALESCE(u.role_key, "UNKNOWN") AS role_key, ae.event_type, COUNT(*) AS n')
                ->groupBy('role_key', 'ae.event_type')
                ->get();
            $rolesMap = [];
            foreach ($byRole as $r) {
                $k = (string) $r->role_key;
                $rolesMap[$k] ??= ['role_key' => $k, 'ok' => 0, 'fail' => 0];
                if ($r->event_type === 'LOGIN_OK')   { $rolesMap[$k]['ok']   = (int) $r->n; }
                if ($r->event_type === 'LOGIN_FAIL') { $rolesMap[$k]['fail'] = (int) $r->n; }
            }
            $byRoleArr = array_values($rolesMap);
            usort($byRoleArr, fn ($a, $b) => ($b['ok'] + $b['fail']) <=> ($a['ok'] + $a['fail']));

            // ── Operational counters ────────────────────────────────────
            $lockedNow = (int) DB::table('users')
                ->whereNotNull('locked_until')->where('locked_until', '>', $now)->count();
            $suspendedNow = (int) DB::table('users')
                ->whereNotNull('suspended_at')->count();
            $anomalyActive = (int) DB::table('user_anomaly_flags')
                ->whereNull('cleared_at')->count();
            $anomalyCritical = (int) DB::table('user_anomaly_flags')
                ->whereNull('cleared_at')->where('severity', 'CRITICAL')->count();
            $criticalRisk = (int) DB::table('users')
                ->where('risk_score', '>=', 80)->count();
            $mfaEnrolled = (int) DB::table('users')
                ->whereNotNull('two_factor_confirmed_at')->count();
            $mfaTotal = (int) DB::table('users')->count();

            // Audit: summary aggregates do not surface PII directly, but
            // the operator viewed an authenticated Governance read; record
            // the view with the row count taken from the largest array
            // returned (the hourly bucket count) so auditors can later see
            // the volume the surface was working with.
            $this->auditView($request, ['hours' => $hours], ['row_count' => count($hourly)]);

            return response()->json([
                'ok'   => true,
                'data' => [
                    'window' => [
                        'from'  => $from->toIso8601String(),
                        'to'    => $to->toIso8601String(),
                        'hours' => $hours,
                    ],
                    'by_event_type' => $byType,
                    'by_severity'   => $bySeverity,
                    'hourly'        => $hourly,
                    'heatmap'       => $heatmap,
                    'login' => [
                        'ok'          => $loginOk,
                        'fail'        => $loginFail,
                        'total'       => $loginTotal,
                        'success_pct' => $successPct,
                        'locked_evt'  => $lockedEvt,
                    ],
                    'mfa' => [
                        'challenged' => $twoFaChallenged,
                        'ok'         => $twoFaOk,
                        'fail'       => $twoFaFail,
                        'enrolled'   => $mfaEnrolled,
                        'users'      => $mfaTotal,
                        'coverage_pct' => $mfaTotal > 0 ? round(100 * $mfaEnrolled / $mfaTotal, 1) : 0.0,
                    ],
                    'top_ips'     => $topIps,
                    'by_role'     => $byRoleArr,
                    'operational' => [
                        'locked_now'       => $lockedNow,
                        'suspended_now'    => $suspendedNow,
                        'anomaly_active'   => $anomalyActive,
                        'anomaly_critical' => $anomalyCritical,
                        'critical_risk'    => $criticalRisk,
                    ],
                    'server_time' => $now->toIso8601String(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'summary');
        }
    }

    public function lockouts(Request $request): JsonResponse
    {
        try {
            $now = now();

            $locked = DB::table('users')
                ->whereNotNull('locked_until')->where('locked_until', '>', $now)
                ->orderByDesc('locked_until')
                ->limit(100)
                ->get(['id', 'full_name', 'username', 'email', 'role_key',
                       'failed_login_count', 'last_failed_login_at', 'last_login_ip', 'locked_until'])
                ->map(fn ($r) => (array) $r)
                ->all();

            $suspended = DB::table('users')
                ->whereNotNull('suspended_at')
                ->orderByDesc('suspended_at')
                ->limit(100)
                ->get(['id', 'full_name', 'username', 'email', 'role_key',
                       'suspended_at', 'suspension_reason'])
                ->map(fn ($r) => (array) $r)
                ->all();

            // Audit: the surface unmasks user full_name + email + last
            // failed-login IP, all PII. Log the view AND the reveal.
            $totalRows = count($locked) + count($suspended);
            $this->auditView($request, [], ['row_count' => $totalRows]);
            if ($totalRows > 0) {
                $this->auditPiiReveal(
                    $request,
                    [],
                    $totalRows,
                    ['full_name', 'email', 'last_login_ip', 'suspension_reason'],
                );
            }

            return response()->json(['ok' => true, 'data' => [
                'locked'      => $locked,
                'suspended'   => $suspended,
                'server_time' => $now->toIso8601String(),
            ]]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'lockouts');
        }
    }

    public function anomalies(Request $request): JsonResponse
    {
        try {
            $rows = DB::table('user_anomaly_flags as f')
                ->leftJoin('users as u', 'u.id', '=', 'f.user_id')
                ->whereNull('f.cleared_at')
                ->orderByRaw("FIELD(f.severity,'CRITICAL','HIGH','MEDIUM','LOW')")
                ->orderByDesc('f.last_seen_at')
                ->limit(200)
                ->get([
                    'f.id', 'f.user_id', 'f.flag_code', 'f.severity',
                    'f.first_seen_at', 'f.last_seen_at', 'f.evidence_json',
                    'u.full_name as user_name', 'u.username as user_username',
                    'u.role_key as user_role',
                ])
                ->map(fn ($r) => [
                    'id'            => (int) $r->id,
                    'user_id'       => (int) $r->user_id,
                    'flag_code'     => (string) $r->flag_code,
                    'severity'      => (string) $r->severity,
                    'first_seen_at' => $r->first_seen_at,
                    'last_seen_at'  => $r->last_seen_at,
                    'user_name'     => $r->user_name,
                    'user_username' => $r->user_username,
                    'user_role'     => $r->user_role,
                    'evidence'      => $this->decodeJson($r->evidence_json),
                ])
                ->all();

            $byCode = [];
            foreach ($rows as $r) {
                $byCode[$r['flag_code']] = ($byCode[$r['flag_code']] ?? 0) + 1;
            }
            $ranking = [];
            foreach ($byCode as $code => $n) {
                $ranking[] = ['flag_code' => $code, 'n' => $n];
            }
            usort($ranking, fn ($a, $b) => $b['n'] <=> $a['n']);

            // Audit: anomaly rows name the user (full_name + username) and
            // expose the evidence_json blob. Record the view + PII reveal.
            $this->auditView($request, [], ['row_count' => count($rows)]);
            if (! empty($rows)) {
                $this->auditPiiReveal(
                    $request,
                    [],
                    count($rows),
                    ['user_name', 'user_username', 'evidence_json'],
                );
            }

            return response()->json(['ok' => true, 'data' => [
                'rows'    => $rows,
                'ranking' => $ranking,
            ]]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'anomalies');
        }
    }

    public function clearAnomaly(Request $request, int $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'note' => ['nullable', 'string', 'max:300'],
            ]);

            $user = $request->user();
            $updated = DB::transaction(function () use ($id, $user, $validated) {
                $row = DB::table('user_anomaly_flags')->where('id', $id)->first();
                if (! $row) {
                    return 0;
                }
                if ($row->cleared_at !== null) {
                    return -1;
                }
                return DB::table('user_anomaly_flags')
                    ->where('id', $id)
                    ->whereNull('cleared_at')
                    ->update([
                        'cleared_at'         => now(),
                        'cleared_by_user_id' => (int) $user->id,
                        'clearance_note'     => $validated['note'] ?? null,
                    ]);
            });

            if ($updated === 0) {
                return response()->json(['ok' => false, 'error' => 'not_found'], 404);
            }
            if ($updated === -1) {
                return response()->json(['ok' => false, 'error' => 'already_cleared'], 409);
            }

            \App\Services\AuthEventLogger::log(
                'ANOMALY_FLAGGED', (int) $user->id, null, 'INFO',
                ['action' => 'CLEARED', 'flag_id' => $id, 'note' => $validated['note'] ?? null],
                -2, $request,
            );

            return response()->json(['ok' => true]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'validation', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function export(Request $request): Response
    {
        [$from, $to] = $this->window($request);
        $filters = ['hours' => (int) $from->diffInHours($to)];

        // Audit BEFORE the file is generated so no successful download is
        // unaudited. The CSV will reveal email_attempted + ip + user_agent;
        // log the reveal explicitly.
        $rowCount = (int) DB::table('auth_events')->whereBetween('created_at', [$from, $to])->count();
        $this->auditPiiReveal($request, $filters, $rowCount, ['email_attempted', 'user_name', 'ip', 'user_agent']);

        $filename = 'auth-events-' . $from->format('Ymd-Hi') . '-to-' . $to->format('Ymd-Hi') . '.csv';
        $footer = $this->exportFooter($request, $filters);

        $callback = function () use ($from, $to, $footer): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['id', 'created_at', 'event_type', 'severity', 'risk_delta',
                'user_id', 'user_name', 'email_attempted', 'ip', 'user_agent']);

            DB::table('auth_events as ae')
                ->leftJoin('users as u', 'u.id', '=', 'ae.user_id')
                ->whereBetween('ae.created_at', [$from, $to])
                ->orderByDesc('ae.id')
                ->select(['ae.id', 'ae.created_at', 'ae.event_type', 'ae.severity',
                    'ae.risk_delta', 'ae.user_id', 'u.full_name as user_name',
                    'ae.email_attempted', 'ae.ip', 'ae.user_agent'])
                ->chunkById(500, function ($chunk) use ($handle): void {
                    foreach ($chunk as $r) {
                        fputcsv($handle, [
                            (int) $r->id, (string) $r->created_at, (string) $r->event_type,
                            (string) $r->severity, (int) $r->risk_delta,
                            $r->user_id !== null ? (int) $r->user_id : '',
                            (string) ($r->user_name ?? ''), (string) ($r->email_attempted ?? ''),
                            (string) ($r->ip ?? ''), (string) ($r->user_agent ?? ''),
                        ]);
                    }
                }, 'ae.id');

            // Standard scope+filter+timestamp footer so the file is
            // self-describing once it leaves the system.
            foreach ($footer as $line) {
                fputcsv($handle, $line);
            }
            fclose($handle);
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type'           => 'text/csv; charset=UTF-8',
            'Cache-Control'          => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function window(Request $request): array
    {
        $hours = (int) $request->query('hours', (string) self::WINDOW_HOURS);
        $hours = max(1, min(720, $hours));
        $to    = now();
        $from  = (clone $to)->subHours($hours);
        return [$from, $to];
    }

    private function decodeJson(null|string $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
    }
}
