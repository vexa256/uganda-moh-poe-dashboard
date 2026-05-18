<?php

declare(strict_types=1);

namespace App\Support\System;

/**
 * CronExpressionTranslator
 * ---------------------------------------------------------------------------
 * Deterministic, hardcoded cron-expression-to-plain-language translator.
 *
 * Operators do not read cron expressions. The brief is explicit: raw cron
 * strings never appear in the user-facing surface — only behind a
 * "Show technical detail" disclosure. This translator covers the patterns
 * the Uganda POE scheduler actually uses today and the most common cron
 * idioms; anything not recognised falls back to a transparent
 * "Custom schedule" line so the operator at least knows the screen is
 * being honest about not having a translation yet.
 *
 * Discipline:
 *   - Pure value object — no DB, no I/O, no state.
 *   - Deterministic — same input → same output.
 *   - When a row carries a Carbon-friendly timezone, the translator names
 *     it ("at 07:00 in Africa/Kampala time") so the operator is not left
 *     wondering whose clock the schedule runs on.
 *   - The translator NEVER invents a cadence — only restates the
 *     expression in plain English or marks it untranslated.
 *
 * Versioned: v1 — domain sign-off pending. New patterns added conservatively.
 */
final class CronExpressionTranslator
{
    public const VERSION = 'v1';

    /**
     * Translate one cron expression into a single plain-English line.
     *
     * @param  string  $expression  Standard 5-field cron expression
     * @param  string|null $timezone  IANA timezone name, optional
     */
    public static function translate(string $expression, ?string $timezone = null): string
    {
        $expr = trim($expression);
        if ($expr === '') {
            return 'No schedule expression recorded.';
        }

        $parts = preg_split('/\s+/', $expr);
        if (! is_array($parts) || count($parts) < 5) {
            return self::fallback($expr);
        }

        // Standard 5-field expression: minute hour dom mon dow
        [$min, $hour, $dom, $mon, $dow] = array_slice($parts, 0, 5);

        $tzSuffix = $timezone ? ' in ' . $timezone . ' time' : '';

        // Every minute
        if ($expr === '* * * * *') {
            return 'Every minute.';
        }

        // Every N minutes (single field)
        if (preg_match('#^\*/(\d+)$#', $min) && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
            $n = (int) trim($min, '*/');
            if ($n === 1)  return 'Every minute.';
            if ($n === 5)  return 'Every five minutes.';
            if ($n === 10) return 'Every ten minutes.';
            if ($n === 15) return 'Every fifteen minutes.';
            if ($n === 30) return 'Every thirty minutes.';
            return "Every {$n} minutes.";
        }

        // Hourly at minute X
        if (ctype_digit($min) && $hour === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
            $m = (int) $min;
            if ($m === 0) return 'Every hour, at the top of the hour' . $tzSuffix . '.';
            return sprintf('Every hour, at minute %02d%s.', $m, $tzSuffix);
        }

        // Daily at HH:MM
        if (ctype_digit($min) && ctype_digit($hour) && $dom === '*' && $mon === '*' && $dow === '*') {
            $hhmm = sprintf('%02d:%02d', (int) $hour, (int) $min);
            return "Every day at {$hhmm}{$tzSuffix}.";
        }

        // Daily at HH:MM on specific weekday
        if (ctype_digit($min) && ctype_digit($hour) && $dom === '*' && $mon === '*' && ctype_digit($dow)) {
            $day  = self::dayName((int) $dow);
            $hhmm = sprintf('%02d:%02d', (int) $hour, (int) $min);
            return "Every {$day} at {$hhmm}{$tzSuffix}.";
        }

        // Every N days at HH:MM (e.g. "0 8 */3 * *" → every 3 days at 08:00)
        if (
            ctype_digit($min) && ctype_digit($hour) &&
            preg_match('#^\*/(\d+)$#', $dom) && $mon === '*' && $dow === '*'
        ) {
            $n    = (int) trim($dom, '*/');
            $hhmm = sprintf('%02d:%02d', (int) $hour, (int) $min);
            if ($n === 1) return "Every day at {$hhmm}{$tzSuffix}.";
            if ($n === 2) return "Every other day at {$hhmm}{$tzSuffix}.";
            return "Every {$n} days at {$hhmm}{$tzSuffix}.";
        }

        // Every N hours at minute Y (e.g. "0 */2 * * *")
        if (
            ctype_digit($min) && preg_match('#^\*/(\d+)$#', $hour) &&
            $dom === '*' && $mon === '*' && $dow === '*'
        ) {
            $n = (int) trim($hour, '*/');
            $m = (int) $min;
            $minLine = $m === 0 ? 'on the hour' : sprintf('at minute %02d', $m);
            if ($n === 1) return "Every hour, {$minLine}{$tzSuffix}.";
            return "Every {$n} hours, {$minLine}{$tzSuffix}.";
        }

        // Every minute within an hour range like "0-59 9-17 * * *"
        return self::fallback($expr, $tzSuffix);
    }

    /**
     * Fallback when none of the recognised patterns match.
     */
    private static function fallback(string $expression, string $tzSuffix = ''): string
    {
        return "Custom schedule — runs on the times set by '{$expression}'{$tzSuffix}. (Plain-language translation not available yet.)";
    }

    private static function dayName(int $dow): string
    {
        // 0 or 7 = Sunday in cron; 1=Mon..6=Sat
        return [
            0 => 'Sunday', 7 => 'Sunday',
            1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
            4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
        ][$dow] ?? 'a specific weekday';
    }
}
