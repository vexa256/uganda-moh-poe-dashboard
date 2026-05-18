<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * alerts:scan-sla-breaches  ──  the 7-1-7 watchdog.
 *
 * Iterates every non-terminal alert and computes phase-elapsed hours per
 * dashboard.txt §B.6.  When a phase exceeds its target AND no OPEN
 * alert_breach_reports row exists for that (alert_id, phase), this command:
 *
 *   1. INSERT alert_breach_reports row { status: OPEN, root_cause_text:
 *      'auto-detected', mitigation_plan: '(pending RCA)' }.
 *   2. emitTimeline → BREACH_ROOT_CAUSE_LOGGED [BREACH][WARN].
 *   3. Dispatch BREACH_717 email via NotificationDispatcher (suppression
 *      window 240 min handles email storms).
 *
 * IDEMPOTENT — running twice in the same minute will not double-insert
 * because a uniqueness check (alert_id, phase, status IN OPEN/IN_PROGRESS)
 * blocks duplicates.  RESOLVED reports do NOT block — a re-emerging breach
 * legitimately deserves a fresh row.
 *
 * NOT auto-escalating — per dashboard.txt §B.8, breach alerts re-page; they
 * do NOT reroute. Escalation remains a human decision.
 *
 * Performance — uses DB::table()->cursor() to stream; no Eloquent hydration.
 *
 *   Schedule (registered in routes/console.php):
 *      Schedule::command('alerts:scan-sla-breaches')
 *              ->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
 */
final class ScanSlaBreaches extends Command
{
    protected $signature   = 'alerts:scan-sla-breaches {--dry-run : compute but do not write} {--limit=2000 : safety cap on alerts scanned}';
    protected $description = 'Scan open + acknowledged alerts for 7-1-7 SLA breaches and file PENDING_RCA reports.';

    /** dashboard.txt §B.6 — risk_level → phase target hours. */
    private const SLA_HOURS = [
        'CRITICAL' => ['DETECT' => 4,  'NOTIFY' => 24, 'RESPOND' => 15 * 24],
        'HIGH'     => ['DETECT' => 24, 'NOTIFY' => 48, 'RESPOND' => 15 * 24],
        'MEDIUM'   => ['DETECT' => 48, 'NOTIFY' => 72, 'RESPOND' => 15 * 24],
        'LOW'      => ['DETECT' => 7 * 24, 'NOTIFY' => 14 * 24, 'RESPOND' => 30 * 24],
    ];

    public function handle(): int
    {
        $started = microtime(true);
        $dryRun  = (bool) $this->option('dry-run');
        $limit   = max(1, (int) $this->option('limit'));

        $now = Carbon::now();
        $scanned = 0; $newBreaches = 0; $skippedExisting = 0; $emailFanouts = 0;
        $byPhase = ['DETECT' => 0, 'NOTIFY' => 0, 'RESPOND' => 0];

        DB::table('alerts')
            ->whereNull('deleted_at')
            ->whereIn('status', ['OPEN', 'ACKNOWLEDGED'])
            ->orderBy('id')
            ->limit($limit)
            ->cursor()
            ->each(function (object $alert) use (&$scanned, &$newBreaches, &$skippedExisting, &$emailFanouts, &$byPhase, $now, $dryRun): void {
                $scanned++;
                $matrix = self::SLA_HOURS[$alert->risk_level] ?? self::SLA_HOURS['MEDIUM'];

                $created = Carbon::parse($alert->created_at);
                $ackAt   = $alert->acknowledged_at ? Carbon::parse($alert->acknowledged_at) : null;

                $detectElapsed  = $ackAt ? $created->diffInMinutes($ackAt) / 60 : $created->diffInMinutes($now) / 60;
                $notifyAnchor   = $ackAt ?? $created;
                $notifyElapsed  = $notifyAnchor->diffInMinutes($now) / 60;
                $respondElapsed = $created->diffInMinutes($now) / 60;

                $phases = [];
                if (! $ackAt && $detectElapsed > $matrix['DETECT']) {
                    $phases[] = ['phase' => 'DETECT', 'elapsed' => $detectElapsed, 'target' => $matrix['DETECT']];
                }
                if ($notifyElapsed > $matrix['NOTIFY']) {
                    $phases[] = ['phase' => 'NOTIFY', 'elapsed' => $notifyElapsed, 'target' => $matrix['NOTIFY']];
                }
                if ($respondElapsed > $matrix['RESPOND']) {
                    $phases[] = ['phase' => 'RESPOND', 'elapsed' => $respondElapsed, 'target' => $matrix['RESPOND']];
                }

                foreach ($phases as $p) {
                    // Idempotency gate — skip if there is already an OPEN/IN_PROGRESS
                    // breach report for the same phase. RESOLVED rows DO NOT block
                    // a fresh report (re-emerging breach is a real signal).
                    $existing = DB::table('alert_breach_reports')
                        ->where('alert_id', $alert->id)
                        ->where('phase', $p['phase'])
                        ->whereIn('status', ['OPEN', 'IN_PROGRESS'])
                        ->exists();
                    if ($existing) { $skippedExisting++; continue; }
                    if ($dryRun)   { $newBreaches++; $byPhase[$p['phase']]++; continue; }

                    try {
                        $breachMinutes = max(0, (int) round(($p['elapsed'] - $p['target']) * 60));

                        $bid = DB::table('alert_breach_reports')->insertGetId([
                            'alert_id'             => (int) $alert->id,
                            'phase'                => $p['phase'],
                            'target_hours'         => (int) $p['target'],
                            'elapsed_hours'        => (float) round($p['elapsed'], 2),
                            'breach_minutes'       => $breachMinutes,
                            'root_cause_category'  => 'AUTO_DETECTED',
                            'root_cause_text'      => sprintf('Auto-detected by SLA scanner at %s. Awaiting RCA from owner.', $now->toDateTimeString()),
                            'contributing_factors' => json_encode(['source' => 'cron:alerts:scan-sla-breaches']),
                            'mitigation_plan'      => '(pending root-cause analysis by owner)',
                            'owner_user_id'        => (int) ($alert->current_owner_user_id ?? $alert->acknowledged_by_user_id ?? 0) ?: 1,
                            'owner_level'          => (string) ($alert->current_owner_level ?? $alert->routed_to_level),
                            'target_resolve_at'    => null,
                            'status'               => 'OPEN',
                            'reported_by_user_id'  => 1,
                            'created_at'           => $now,
                            'updated_at'           => $now,
                        ]);

                        // Append-only audit (the forensic line).
                        DB::table('alert_timeline_events')->insert([
                            'alert_id'            => (int) $alert->id,
                            'event_code'          => 'BREACH_ROOT_CAUSE_LOGGED',
                            'event_category'      => 'BREACH',
                            'actor_user_id'       => null,
                            'actor_name'          => 'SLA scanner',
                            'actor_role'          => 'SYSTEM',
                            'payload_json'        => json_encode([
                                'breach_id'      => $bid, 'phase' => $p['phase'],
                                'elapsed_h'      => round($p['elapsed'], 2), 'target_h' => $p['target'],
                                'breach_minutes' => $breachMinutes,
                                'risk_level'     => $alert->risk_level,
                                'auto_detected'  => true,
                            ]),
                            'summary'             => sprintf('%s breach +%d min on %s alert (%s elapsed > %sh target).',
                                $p['phase'], $breachMinutes, $alert->risk_level,
                                round($p['elapsed'], 1) . 'h', $p['target']),
                            'severity'            => 'WARN',
                            'related_entity_type' => 'BREACH',
                            'related_entity_id'   => $bid,
                            'created_at'          => $now,
                        ]);

                        // Per executive directive (2026-05-17): the scanner DETECTS breaches
                        // and files the alert_breach_reports + alert_timeline_events rows,
                        // but it does NOT email per breach. The notifications:weekly-action-bundle
                        // job (Mondays 08:00 Africa/Kampala) picks up unresolved breaches and
                        // includes them in the single weekly digest, governed by the 14-day
                        // BREACH_717 suppression window.
                        //
                        // Historical: NotificationDispatcher::dispatchBreach717() is still
                        // callable for manual one-off use but no scheduled path invokes it.

                        $newBreaches++; $byPhase[$p['phase']]++;
                    } catch (Throwable $e) {
                        Log::error('[alerts:scan-sla-breaches][insert] '.$e->getMessage(), [
                            'alert_id' => $alert->id, 'phase' => $p['phase'],
                        ]);
                    }
                }
            });

        $elapsedMs = (int) round((microtime(true) - $started) * 1000);
        $msg = sprintf(
            'Scanned %d · new=%d (D:%d N:%d R:%d) · skipped(existing)=%d · email-fanouts=%d · %d ms · dry-run=%s',
            $scanned, $newBreaches, $byPhase['DETECT'], $byPhase['NOTIFY'], $byPhase['RESPOND'],
            $skippedExisting, $emailFanouts, $elapsedMs, $dryRun ? 'yes' : 'no'
        );
        $this->info($msg);
        Log::info('[alerts:scan-sla-breaches] ' . $msg);
        return self::SUCCESS;
    }
}
