<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled notification jobs (Uganda POE Sentinel)
|--------------------------------------------------------------------------
|
| EXECUTIVE EMAIL ENVELOPE (2026-05-17 mandate)
|   Max 2 alert-related emails per recipient per week + 1 executive
|   analytics brief per day at midnight. Real-time alerts (CRITICAL,
|   HIGH, TIER 1, secondary screening) fire immediately and are NOT
|   bundled — they're news, not nags. Breach re-pages are the least
|   frequent of all reminders: every 14 days max per (alert, recipient).
|
|   Channel 1 (weekly):     notifications:weekly-digest         · Mon 07:30
|   Channel 2 (weekly):     notifications:weekly-action-bundle  · Mon 08:00
|   Channel 3 (daily exec): notifications:daily-executive-brief · 00:00
|   Real-time (event-driven): dispatchAlertCreated / dispatchScreeningReferral
|                             / dispatchAlertClosed / dispatchSecondaryScreeningOpened
|                             — fire immediately on state transition
|
| Cron entry on the server:
|     * * * * * cd /var/www/ug-poe/api && php artisan schedule:run >> /dev/null 2>&1
|
| Verify:
|     php artisan schedule:list
|
| Test (dry-run, no send):
|     php artisan notifications:weekly-action-bundle --dry-run
|     php artisan notifications:daily-executive-brief --dry-run
*/

// ── 1) Daily Executive Brief — every day 00:00 Africa/Kampala.
//    Beautiful 24-hour analytics (KPI cards, 7-day trend, top-5 POEs).
//    Audience: 10-person national executive roster (NATIONAL contacts
//    with receives_daily_report=1). Idempotent: skips if already sent
//    for the day_key.
Schedule::command('notifications:daily-executive-brief')
    ->timezone('Africa/Kampala')
    ->dailyAt('00:00')
    ->withoutOverlapping(60)
    ->onOneServer()
    ->description('Daily executive analytics brief (24h KPIs) — sent at midnight.');

// ── 2) Weekly Action Bundle — Monday 08:00 Africa/Kampala.
//    Single per-jurisdiction-clustered email collapsing ALL follow-up
//    reminders + unresolved 7-1-7 breaches into ONE envelope. Replaces
//    the previous daily followup-reminders + 3-day national-digest jobs.
//    Breach entries appear at most every 14 days per (alert, recipient).
Schedule::command('notifications:weekly-action-bundle')
    ->timezone('Africa/Kampala')
    ->weeklyOn(1, '08:00')          // Monday 08:00
    ->withoutOverlapping(120)
    ->onOneServer()
    ->description('Weekly bundled action digest (follow-ups + breaches) to national executive roster.');

// ── 3) Weekly Comprehensive Scorecard — Monday 07:30 Africa/Kampala (existing).
//    14-section 7-day scorecard (screening volumes, disease signals,
//    IHR compliance, POE activity table, officer productivity, etc.)
//    to every contact with receives_weekly_report=1.
Schedule::command('notifications:weekly-digest')
    ->timezone('Africa/Kampala')
    ->weeklyOn(1, '07:30')          // Monday 07:30
    ->withoutOverlapping(120)
    ->onOneServer()
    ->description('Monday 07:30 — comprehensive 7-day scorecard to subscribed national contacts.');

// ── 4) SLA Breach Scanner — every 15 minutes.
//    Detects 7-1-7 phase breaches against dashboard.txt §B.6 matrix
//    and files PENDING_RCA breach reports. As of 2026-05-17 it does
//    NOT email per breach — the weekly action bundler picks up
//    unresolved breaches and includes them in the Monday digest
//    (governed by 14-day suppression per (alert, recipient)).
Schedule::command('alerts:scan-sla-breaches')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->description('Detect 7-1-7 SLA breaches on open/ack alerts and file root-cause reports.');

// ── 5) Retry FAILED notifications — every 15 minutes.
//    Pure sync infrastructure: retries notification_log rows in FAILED
//    state. Not user-facing; subject to suppression windows.
Schedule::command('notifications:retry-failed')
    ->everyFifteenMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->description('Retry FAILED notification_log rows (max 4 attempts).');

// ── 6) Email queue drainer — every minute.
//    Drains the 'emails' queue with --stop-when-empty so the cron call
//    exits cleanly when idle. --tries=3 lets transient SMTP failures
//    retry; permanent failures fall to failed_jobs and are picked up
//    by notifications:retry-failed above.
Schedule::command('queue:work --queue=emails --stop-when-empty --tries=3 --timeout=60')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer()
    ->description('Drain the queued SentinelMail emails (alerts refactor §3.2).');

// ────────────────────────────────────────────────────────────────────────────
// RETIRED schedules (kept here as comments so future devs see the history):
//
//   Schedule::command('notifications:daily-digest')      ->dailyAt('07:00')   // RETIRED — folded into daily-executive-brief
//   Schedule::command('notifications:followup-reminders')->dailyAt('08:00')   // RETIRED — replaced by weekly-action-bundle
//   Schedule::command('notifications:national-digest')   ->cron('0 8 */3 * *')// RETIRED — replaced by weekly-action-bundle
//
// The retired Artisan command classes (NotificationsDailyDigest,
// NotificationsFollowupReminders, NotificationsNationalDigest) are kept on
// disk for backward compat and can be invoked manually with `php artisan ...`
// if a one-off run is needed. They are NOT registered in the scheduler.
// ────────────────────────────────────────────────────────────────────────────
