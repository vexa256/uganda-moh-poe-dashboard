<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\System;

use App\Support\Governance\DeliveryErrorTranslator;
use App\Support\System\CronExpressionTranslator;
use App\Support\System\JobNameTranslator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin · System Health · Scheduled Jobs (sys-cron)
 * ---------------------------------------------------------------------------
 * v4 — premium read-only view of the platform's background machinery.
 *
 * What this controller does:
 *   · index()        — renders the Blade view + injects the coach manifest
 *   · summary()      — returns the master payload consumed by every tab
 *   · runs()         — recent runs across every schedule (paginated-ish)
 *   · failures()     — failed runs only, with plain-language reason
 *   · trigger()      — runs ONE schedule on demand by calling the existing
 *                      artisan command (the same path the timekeeper uses).
 *                      Every well-known schedule is idempotent, so the
 *                      manual trigger inherits that idempotency.
 *
 * Discipline (per the System Health brief):
 *   · DYNAMIC DISCOVERY — every schedule rendered comes from Laravel's
 *     live Schedule registry (`Schedule::events()`). No hardcoded list.
 *     A new schedule registered in routes/console.php appears here on
 *     the next request without code changes. Translations for new
 *     commands fall back to a transparent "we haven't translated this
 *     yet" line; the underlying command is still surfaced.
 *   · NO RAW CRON — every cron expression is translated by the
 *     CronExpressionTranslator at render time. Raw expressions are
 *     available behind a "Show technical detail" disclosure only.
 *   · NO RAW ERRORS — every error_message from notification_log is run
 *     through DeliveryErrorTranslator. The raw string lives behind
 *     a disclosure.
 *   · MANUAL TRIGGER reuses the existing Artisan path. We never bypass
 *     the dispatcher; the artisan command IS the dispatcher entry
 *     point. Only known commands can be triggered (an allow-list
 *     derived from JobNameTranslator) so the page cannot be coerced
 *     into running an arbitrary command.
 *   · AUDIT — every read records a view in reports_access_audit; the
 *     manual-trigger records both a view AND a payload-bearing audit
 *     row naming the schedule that was run.
 *
 * Mobile-API impact: NONE. Routes live under /admin/system/cron/* in
 * routes/web.php and never appear under /api/*.
 *
 * Schema impact: NONE. This controller reads from notification_log and
 * alert_breach_reports (existing tables) and writes nothing.
 *
 * RBAC: National-only. Enforced by the route middleware.
 */
final class CronController extends BaseSystemController
{
    protected function viewKey(): string
    {
        return 'cron';
    }

    /**
     * Evidence registry — for each well-known artisan command, the
     * notification_log filter that proves "this schedule actually
     * produced output". A schedule listed in Schedule::events() but
     * not in this map is still rendered (with last-run = unknown).
     *
     * Mark as "v1 — domain sign-off pending" because the same evidence
     * mapping is duplicated in the previous CronController; we are
     * tracking whether each registered schedule produced output, not
     * inventing evidence.
     */
    private const EVIDENCE_BY_COMMAND = [
        'notifications:daily-digest' => [
            'kind' => 'notification_log',
            'where' => ['template_code' => 'DAILY_REPORT', 'status' => 'SENT'],
            'expected_interval_minutes' => 1440,
        ],
        'notifications:followup-reminders' => [
            'kind' => 'notification_log',
            'where' => ['template_code' => 'FOLLOWUP_DUE', 'status' => 'SENT'],
            'expected_interval_minutes' => 60,
        ],
        'notifications:retry-failed' => [
            'kind' => 'notification_log',
            'where' => ['triggered_by' => 'CRON:retry'],
            'expected_interval_minutes' => 15,
        ],
        'notifications:national-digest' => [
            'kind' => 'notification_log',
            'where' => ['template_code' => 'NATIONAL_INTELLIGENCE', 'status' => 'SENT'],
            'expected_interval_minutes' => 4320,
        ],
        'alerts:scan-sla-breaches' => [
            'kind' => 'alert_breach_reports',
            'where' => [],
            'expected_interval_minutes' => 15,
        ],
        // queue:work has no per-run evidence — its output IS every send,
        // so we don't compute "last run" for it. The schedule still
        // renders (the strip just won't have an evidence-based last-run).
    ];

    /**
     * Manual-trigger allow-list. Adding a schedule to this list lets
     * an operator run it on demand from the UI; the artisan command
     * itself remains the single execution path. Untranslated commands
     * (those not in JobNameTranslator) are intentionally NOT triggerable
     * from the UI — the engineering team must add a translation before
     * exposing the affordance.
     */
    private const TRIGGERABLE = [
        'notifications:daily-digest',
        'notifications:followup-reminders',
        'notifications:retry-failed',
        'notifications:national-digest',
        'alerts:scan-sla-breaches',
    ];

    public function index(Request $request)
    {
        return view('admin.system.cron.index', [
            'page_title'    => 'Scheduled jobs',
            'page_eyebrow'  => 'System Health',
            'page_subtitle' => 'The platform’s background timekeeper. What is running, when it last ran, when it runs next.',
            'coach'         => $this->coach(),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $now    = now();
            $events = $this->scheduleEvents();

            $jobs = [];
            $overdueCount = 0;
            $registeredCount = 0;
            foreach ($events as $command => $event) {
                $registeredCount++;
                $reg = $this->describe($command, $event, $now);
                if ($reg['overdue']) $overdueCount++;
                $jobs[] = $reg;
            }

            // Health pill — a single phrase the operator does not need to interpret.
            if ($registeredCount === 0) {
                $health = ['level' => 'unknown', 'plain' => 'No schedules are registered. The timekeeper appears to be off.'];
            } elseif ($overdueCount === 0) {
                $health = ['level' => 'green', 'plain' => 'All schedules are running on time.'];
            } elseif ($overdueCount === 1) {
                $health = ['level' => 'amber', 'plain' => 'One schedule is delayed.'];
            } else {
                $health = ['level' => 'red', 'plain' => $overdueCount . ' schedules need attention.'];
            }

            // Send-history trend (24h heatstrip per registered job).
            $strips = $this->heartbeatStrips($jobs, $now);

            // Queue snapshot for retry/queued count — still surfaced because
            // it directly answers "is anything still waiting".
            $queueSnapshot = $this->queueSnapshot();

            $this->auditView($request, ['view' => 'cron.summary'], ['row_count' => count($jobs)]);

            return $this->ok([
                'server_time'     => $now->toIso8601String(),
                'health'          => $health,
                'overdue_count'   => $overdueCount,
                'registered_count'=> $registeredCount,
                'jobs'            => $jobs,
                'heartbeat_strips'=> $strips,
                'queue'           => $queueSnapshot,
                'translator_versions' => [
                    'cron_expression' => CronExpressionTranslator::VERSION,
                    'job_name'        => JobNameTranslator::VERSION,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'summary');
        }
    }

    /**
     * Recent runs across every schedule (last 7 days). Each row is
     * grouped to a single per-hour-per-template bucket so the table
     * fits a normal screen.
     */
    public function runs(Request $request): JsonResponse
    {
        try {
            $hours = max(24, min(720, (int) $request->query('hours', 168)));
            $since = now()->subHours($hours);
            $rows  = [];

            if (Schema::hasTable('notification_log')) {
                $hl = DB::table('notification_log')
                    ->whereRaw("triggered_by LIKE 'CRON:%'")
                    ->where('created_at', '>=', $since)
                    ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS h, "
                        . 'triggered_by, template_code, '
                        . 'COUNT(*) AS n, '
                        . 'SUM(CASE WHEN status="SENT"   THEN 1 ELSE 0 END) AS sent_n, '
                        . 'SUM(CASE WHEN status="FAILED" THEN 1 ELSE 0 END) AS failed_n, '
                        . 'SUM(CASE WHEN status="BOUNCED" THEN 1 ELSE 0 END) AS bounced_n')
                    ->groupBy('h', 'triggered_by', 'template_code')
                    ->orderByDesc('h')
                    ->limit(500)
                    ->get();
                foreach ($hl as $r) {
                    $rows[] = [
                        'when'         => (string) $r->h,
                        'triggered_by' => (string) $r->triggered_by,
                        'template'     => (string) $r->template_code,
                        'count'        => (int) $r->n,
                        'sent'         => (int) $r->sent_n,
                        'failed'       => (int) $r->failed_n,
                        'bounced'      => (int) $r->bounced_n,
                        'kind'         => 'notification_log',
                    ];
                }
            }

            if (Schema::hasTable('alert_breach_reports')) {
                $br = DB::table('alert_breach_reports')
                    ->where('created_at', '>=', $since)
                    ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS h, COUNT(*) AS n")
                    ->groupBy('h')
                    ->orderByDesc('h')
                    ->limit(200)->get();
                foreach ($br as $r) {
                    $rows[] = [
                        'when'         => (string) $r->h,
                        'triggered_by' => 'CRON:scan-sla-breaches',
                        'template'     => 'BREACH_REPORT',
                        'count'        => (int) $r->n,
                        'sent'         => 0,
                        'failed'       => 0,
                        'bounced'      => 0,
                        'kind'         => 'alert_breach_reports',
                    ];
                }
            }

            // Sort by when desc.
            usort($rows, fn ($a, $b) => strcmp((string) $b['when'], (string) $a['when']));

            $this->auditView($request, ['view' => 'cron.runs', 'hours' => $hours], ['row_count' => count($rows)]);

            return $this->ok([
                'server_time' => now()->toIso8601String(),
                'window_hours'=> $hours,
                'rows'        => $rows,
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'runs');
        }
    }

    /**
     * Failures only — same window as runs() but the rows are the FAILED
     * notification_log entries themselves with the error reason
     * translated. Each row is one failed message; the operator can read
     * what went wrong without seeing a single raw error string.
     */
    public function failures(Request $request): JsonResponse
    {
        try {
            $hours = max(24, min(720, (int) $request->query('hours', 168)));
            $since = now()->subHours($hours);
            $rows  = [];

            if (Schema::hasTable('notification_log')) {
                $rs = DB::table('notification_log')
                    ->whereIn('status', ['FAILED', 'BOUNCED'])
                    ->whereRaw("triggered_by LIKE 'CRON:%'")
                    ->where('created_at', '>=', $since)
                    ->orderByDesc('created_at')
                    ->limit(150)
                    ->get(['id', 'created_at', 'template_code', 'triggered_by', 'channel',
                           'status', 'error_message', 'retry_count', 'to_email']);
                foreach ($rs as $r) {
                    $rows[] = [
                        'id'             => (int) $r->id,
                        'when'           => (string) $r->created_at,
                        'template'       => (string) $r->template_code,
                        'triggered_by'   => (string) $r->triggered_by,
                        'channel'        => (string) $r->channel,
                        'status'         => (string) $r->status,
                        'plain_reason'   => DeliveryErrorTranslator::translate($r->error_message),
                        'technical_raw'  => (string) ($r->error_message ?? ''),
                        'retry_count'    => (int) $r->retry_count,
                        'recipient_hint' => $r->to_email ? $this->domainOf((string) $r->to_email) : null,
                    ];
                }
            }

            // Audit + PII reveal — recipient domain is not PII alone, but
            // this surface unmasks the recipient_hint for diagnostic use.
            $this->auditView($request, ['view' => 'cron.failures', 'hours' => $hours], ['row_count' => count($rows)]);
            if (! empty($rows)) {
                $this->auditPiiReveal($request, ['view' => 'cron.failures', 'hours' => $hours], count($rows), ['recipient_domain']);
            }

            return $this->ok([
                'server_time' => now()->toIso8601String(),
                'window_hours'=> $hours,
                'rows'        => $rows,
                'translator_version' => 'DeliveryErrorTranslator/v1',
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'failures');
        }
    }

    /**
     * Trigger one well-known schedule on demand. The artisan command
     * IS the existing dispatcher entry point — nothing parallel is
     * being introduced. Idempotency is delegated to the command itself
     * (every well-known schedule respects suppression and idempotency
     * keys at the message level).
     */
    public function trigger(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'command' => ['required', 'string', 'max:120'],
            ]);
            $command = trim((string) $validated['command']);

            if (! in_array($command, self::TRIGGERABLE, true)) {
                $this->auditDenied($request);
                return $this->err(403, 'This schedule cannot be triggered from the UI. The engineering team must add a translation and an allow-list entry first.');
            }

            $events = $this->scheduleEvents();
            if (! isset($events[$command])) {
                return $this->err(404, 'The scheduler does not have this command registered.');
            }

            $idempotencyKey = $this->idempotencyKey($request);

            $startedAt = microtime(true);
            $exit = Artisan::call($command);
            $output = trim((string) Artisan::output());
            $tookMs = (int) round((microtime(true) - $startedAt) * 1000);

            // Audit — record the trigger as a view with the command in the
            // filter set so reviewers can later see exactly what was run.
            $this->auditView($request, [
                'action'         => 'manual_trigger',
                'command'        => $command,
                'idempotency_key'=> $idempotencyKey,
                'exit'           => $exit,
                'duration_ms'    => $tookMs,
            ], ['row_count' => 1]);

            return $this->ok([
                'command'        => $command,
                'exit_code'      => (int) $exit,
                'duration_ms'    => $tookMs,
                'plain_summary'  => $exit === 0
                    ? 'The schedule ran. Refresh the heartbeat strip to see the fresh tick.'
                    : 'The schedule started, but its exit code suggests something did not complete cleanly. Check the Failures tab.',
                'technical_output' => mb_substr($output, 0, 4_000),
                'started_at'     => now()->subMilliseconds($tookMs)->toIso8601String(),
                'finished_at'    => now()->toIso8601String(),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'validation', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return $this->serverError($e, 'trigger');
        }
    }

    /* ──────────────────────── helpers ──────────────────────── */

    /**
     * @return array<string,array<string,mixed>>  keyed by normalised command
     */
    private function scheduleEvents(): array
    {
        try {
            /** @var Schedule $schedule */
            $schedule = app(Schedule::class);
            $events   = $schedule->events();
            $out      = [];
            foreach ($events as $e) {
                $cmd = JobNameTranslator::normalise((string) $e->command);
                if ($cmd === '') continue;
                $expression = (string) ($e->expression ?? '');
                $tz         = $e->timezone ? (string) $e->timezone : null;
                $out[$cmd] = [
                    'expression'          => $expression,
                    'expression_plain'    => CronExpressionTranslator::translate($expression, $tz),
                    'timezone'            => $tz,
                    'description'         => $e->description ? (string) $e->description : null,
                    'without_overlapping' => (bool) $e->withoutOverlapping,
                    'on_one_server'       => (bool) ($e->onOneServer ?? false),
                    'next_due'            => $this->nextDueFromExpression($expression, $tz),
                    'raw_command'         => (string) $e->command,
                ];
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function describe(string $command, array $event, Carbon $now): array
    {
        $name      = JobNameTranslator::resolve($command);
        $evidence  = self::EVIDENCE_BY_COMMAND[$command] ?? null;
        $expInt    = (int) ($evidence['expected_interval_minutes'] ?? 0);
        $lastAt    = $evidence ? $this->lastRunAt($evidence) : null;
        $minutesSince = null;
        $overdue   = false;
        if ($lastAt) {
            $minutesSince = (int) Carbon::parse($lastAt)->diffInMinutes($now);
            if ($expInt > 0) {
                $overdue = $minutesSince > ($expInt * 2);
            }
        } elseif ($expInt > 0) {
            // No evidence ever — only mark overdue if the expected
            // interval is short. Long-running schedules (e.g. 3-day
            // digest) should not flash red on a fresh install.
            $overdue = $expInt <= 60;
        }
        $lastRunPlain = $lastAt
            ? Carbon::parse($lastAt)->diffForHumans($now, ['parts' => 1])
            : 'No run recorded yet.';

        return [
            'command'             => $command,
            'label'               => $name['label'],
            'summary'             => $name['summary'],
            'affects'             => $name['affects'],
            'when_problems'       => $name['when_problems'],
            'what_we_do'          => $name['what_we_do'],
            'untranslated'        => (bool) $name['untranslated'],
            'expression_plain'    => $event['expression_plain'],
            'expression_raw'      => $event['expression'],
            'timezone'            => $event['timezone'],
            'description'         => $event['description'],
            'without_overlapping' => $event['without_overlapping'],
            'on_one_server'       => $event['on_one_server'],
            'next_due_iso'        => $event['next_due'],
            'last_run_iso'        => $lastAt,
            'last_run_plain'      => $lastRunPlain,
            'minutes_since_run'   => $minutesSince,
            'expected_interval_minutes' => $expInt,
            'has_evidence'        => $evidence !== null,
            'overdue'             => $overdue,
            'triggerable'         => in_array($command, self::TRIGGERABLE, true),
        ];
    }

    /**
     * Compute a 24-hour heartbeat strip for each registered command,
     * one cell per hour. Cells:
     *   - 'success'  ≥1 successful run-evidence row in the hour
     *   - 'failed'   the only evidence rows are FAILED
     *   - 'idle'     no evidence row, but the hour is past the cadence
     *   - 'pending'  no evidence row, hour is in the future / cadence not reached
     *
     * @param  array<int,array<string,mixed>> $jobs  output of describe()
     * @return array<int,array<string,mixed>>
     */
    private function heartbeatStrips(array $jobs, Carbon $now): array
    {
        $strips  = [];
        $startAt = (clone $now)->subHours(23)->startOfHour();
        if (! Schema::hasTable('notification_log')) {
            foreach ($jobs as $j) {
                $strips[] = ['command' => $j['command'], 'cells' => []];
            }
            return $strips;
        }

        foreach ($jobs as $j) {
            $command  = (string) $j['command'];
            $evidence = self::EVIDENCE_BY_COMMAND[$command] ?? null;
            $cells    = [];

            if (! $evidence) {
                // Untracked schedule — render an empty strip with a flag.
                for ($i = 0; $i < 24; $i++) {
                    $cells[] = ['hour' => $startAt->copy()->addHours($i)->toIso8601String(), 'state' => 'unknown', 'count' => 0];
                }
                $strips[] = ['command' => $command, 'cells' => $cells, 'has_evidence' => false];
                continue;
            }

            $rows = [];
            if ($evidence['kind'] === 'notification_log') {
                $q = DB::table('notification_log')
                    ->where('created_at', '>=', $startAt)
                    ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS h, "
                        . 'SUM(CASE WHEN status="SENT"   THEN 1 ELSE 0 END) AS ok_n, '
                        . 'SUM(CASE WHEN status IN("FAILED","BOUNCED") THEN 1 ELSE 0 END) AS bad_n, '
                        . 'COUNT(*) AS n');
                foreach ($evidence['where'] as $col => $v) {
                    $q->where($col, $v);
                }
                $rows = $q->groupBy('h')->orderBy('h')->get()->keyBy('h');
            } elseif ($evidence['kind'] === 'alert_breach_reports' && Schema::hasTable('alert_breach_reports')) {
                $rows = DB::table('alert_breach_reports')
                    ->where('created_at', '>=', $startAt)
                    ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS h, COUNT(*) AS n, COUNT(*) AS ok_n, 0 AS bad_n")
                    ->groupBy('h')->orderBy('h')->get()->keyBy('h');
            }

            for ($i = 0; $i < 24; $i++) {
                $cell = $startAt->copy()->addHours($i);
                $key  = $cell->format('Y-m-d H:00:00');
                $row  = $rows[$key] ?? null;
                $okN  = $row ? (int) $row->ok_n  : 0;
                $badN = $row ? (int) $row->bad_n : 0;
                $cnt  = $row ? (int) $row->n     : 0;
                if ($okN > 0) {
                    $state = 'success';
                } elseif ($badN > 0) {
                    $state = 'failed';
                } else {
                    $state = 'idle';
                }
                $cells[] = [
                    'hour'  => $cell->toIso8601String(),
                    'state' => $state,
                    'count' => $cnt,
                ];
            }
            $strips[] = ['command' => $command, 'cells' => $cells, 'has_evidence' => true];
        }
        return $strips;
    }

    /**
     * @return array<string,int>
     */
    private function queueSnapshot(): array
    {
        if (! Schema::hasTable('notification_log')) {
            return ['waiting' => 0, 'retrying' => 0];
        }
        $waiting  = (int) DB::table('notification_log')->where('status', 'QUEUED')->count();
        $retrying = (int) DB::table('notification_log')
            ->where('status', 'FAILED')->where('retry_count', '<', 4)->count();
        return ['waiting' => $waiting, 'retrying' => $retrying];
    }

    private function nextDueFromExpression(string $expr, ?string $tz): ?string
    {
        if ($expr === '') return null;
        try {
            $cron  = new \Cron\CronExpression($expr);
            $tzObj = $tz ? new \DateTimeZone($tz) : null;
            return $cron->getNextRunDate('now', 0, false, $tzObj?->getName())->format('c');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $evidence
     */
    private function lastRunAt(array $evidence): ?string
    {
        try {
            if ($evidence['kind'] === 'notification_log' && Schema::hasTable('notification_log')) {
                $q = DB::table('notification_log');
                foreach (($evidence['where'] ?? []) as $col => $v) {
                    $q->where($col, $v);
                }
                return $q->max('created_at');
            }
            if ($evidence['kind'] === 'alert_breach_reports' && Schema::hasTable('alert_breach_reports')) {
                return DB::table('alert_breach_reports')->max('created_at');
            }
        } catch (Throwable) {}
        return null;
    }

    private function domainOf(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false ? '' : substr($email, $at + 1);
    }
}
