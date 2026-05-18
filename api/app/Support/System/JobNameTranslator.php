<?php

declare(strict_types=1);

namespace App\Support\System;

/**
 * JobNameTranslator
 * ---------------------------------------------------------------------------
 * Maps internal artisan command identifiers (the strings the scheduler
 * registers) to operator-friendly names + one-line plain summaries.
 *
 * The brief is explicit: when a command appears in the scheduler that
 * this translator does not know, the screen falls back to the raw
 * command name with a one-line caption ("We haven't translated this
 * name yet — what runs is …"). It does NOT make up a friendly name.
 *
 * Versioned: v1 — domain sign-off pending. Add entries conservatively.
 */
final class JobNameTranslator
{
    public const VERSION = 'v1';

    /**
     * @var array<string,array{label:string,summary:string,affects:string,when_problems:string,what_we_do:string}>
     */
    private const MAP = [
        'notifications:daily-digest' => [
            'label'        => 'Morning digest',
            'summary'      => 'Sends the daily POE surveillance summary to subscribed contacts every morning.',
            'affects'      => 'National, PHEOC, and District subscribers who expect a 07:00 briefing.',
            'when_problems'=> 'Subscribers may not receive the morning briefing. The system will retry on the next run; persistent failure means the summary is silently skipped that day.',
            'what_we_do'   => 'The system retries failed sends automatically. After three retries, the failure surfaces in the bounce register and the engineering team is alerted.',
        ],
        'notifications:followup-reminders' => [
            'label'        => 'Follow-up reminders',
            'summary'      => 'Sends a reminder to the responsible officer when a 7-1-7 follow-up step is due or overdue.',
            'affects'      => 'Officers who own an open alert with pending follow-up actions.',
            'when_problems'=> 'Reminders may not arrive on time. The 24-hour suppression rule still applies — the operator will not be paged twice for the same step on the same day.',
            'what_we_do'   => 'Reminders are retried on the next hourly run. Persistent failure surfaces the underlying alert in the SLA breach scanner.',
        ],
        'notifications:retry-failed' => [
            'label'        => 'Retry queue',
            'summary'      => 'Looks for messages that did not deliver and retries them, up to four attempts per message.',
            'affects'      => 'Any recipient whose last message attempt did not deliver — alerts, follow-ups, sign-in codes.',
            'when_problems'=> 'A backlog of undelivered messages will grow. After four failed attempts, the system gives up and the failure shows in the bounce register.',
            'what_we_do'   => 'Each retry waits at least fifteen minutes between attempts. After four attempts, the message is left in the bounce register for the operator to review.',
        ],
        'notifications:national-digest' => [
            'label'        => 'National intelligence digest',
            'summary'      => 'Sends a strategic three-day briefing to the top National contacts in each country.',
            'affects'      => 'A small number of National-level recipients per country.',
            'when_problems'=> 'A scheduled briefing may be missed. The cadence is sparse, so a single missed run is visible to the operator.',
            'what_we_do'   => 'The system retries the same way as the morning digest. Repeated failure escalates through the bounce register.',
        ],
        'alerts:scan-sla-breaches' => [
            'label'        => 'SLA breach scanner',
            'summary'      => 'Checks every fifteen minutes whether any open alert has missed its 7-1-7 deadline. Files breach reports and emails the responsible team.',
            'affects'      => 'Officers responsible for 7-1-7 detect-notify-respond timelines; District, PHEOC, and National team members.',
            'when_problems'=> 'A real breach may go unnoticed. The alert itself remains open and visible in the alert console — but the formal breach record will not be filed automatically.',
            'what_we_do'   => 'The scanner is idempotent — running it again after a missed run files the same breach reports without duplicating them. Manually re-running the scanner is safe.',
        ],
        'queue:work --queue=emails --stop-when-empty --tries=3 --timeout=60' => [
            'label'        => 'Outbound email worker',
            'summary'      => 'Drains the outbound email queue every minute. A safety net so queued mail leaves the building when the long-running worker is unavailable.',
            'affects'      => 'Any recipient whose message has been queued (most outbound mail flows through the queue).',
            'when_problems'=> 'Queued mail may sit in the queue waiting for the next minute’s run. If this stops entirely, mail simply waits; nothing is lost.',
            'what_we_do'   => 'Each run exits when the queue is empty. Permanent failures fall to a separate failed-job table that the retry queue picks up.',
        ],
    ];

    /**
     * Resolve a command into operator-friendly metadata. Returns a
     * fallback shape (with `untranslated => true`) when the command is
     * not in the map, so the calling view can surface the honest
     * "we haven't translated this yet" line.
     *
     * @return array<string,mixed>
     */
    public static function resolve(string $command): array
    {
        $command = trim($command);
        $cmd     = self::normalise($command);
        if ($cmd === '') {
            return self::fallback($command);
        }

        // Exact match first
        if (isset(self::MAP[$cmd])) {
            return self::MAP[$cmd] + [
                'command'      => $cmd,
                'untranslated' => false,
            ];
        }

        // Match on artisan-prefixed command (queue:work line carries args)
        foreach (self::MAP as $key => $entry) {
            if (str_starts_with($cmd, explode(' ', $key)[0])) {
                // Same artisan command, different args — soft match
                if (str_contains($key, ' ') && str_contains($cmd, ' ')) {
                    if (explode(' ', $key)[0] === explode(' ', $cmd)[0]) {
                        return $entry + [
                            'command'      => $cmd,
                            'untranslated' => false,
                        ];
                    }
                } elseif ($key === explode(' ', $cmd)[0]) {
                    return $entry + [
                        'command'      => $cmd,
                        'untranslated' => false,
                    ];
                }
            }
        }

        return self::fallback($cmd);
    }

    /**
     * Strip artisan binary noise so 'php' 'artisan' 'foo' becomes 'foo'.
     */
    public static function normalise(string $raw): string
    {
        if (preg_match("/artisan['\"\\s]+([a-zA-Z0-9:\\-]+(?:\\s+--[a-zA-Z0-9=\\-_]+)*)/i", $raw, $m)) {
            return trim($m[1]);
        }
        return trim($raw, " '\"");
    }

    /**
     * @return array<string,mixed>
     */
    private static function fallback(string $command): array
    {
        return [
            'command'      => $command,
            'label'        => $command !== '' ? $command : 'Unnamed schedule',
            'summary'      => "We haven’t translated this schedule yet. What runs is the artisan command shown above.",
            'affects'      => 'Unknown until a translation is added by the engineering team.',
            'when_problems'=> 'Unknown until a translation is added by the engineering team. Check the recent runs to see what changed.',
            'what_we_do'   => 'The scheduler runs this on its expression. Manual triggering is not exposed for untranslated jobs — the engineering team must add a translation first.',
            'untranslated' => true,
        ];
    }
}
