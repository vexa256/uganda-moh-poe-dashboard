<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * TimelineBuilder
 * ---------------------------------------------------------------------------
 * Turns `alert_timeline_events` rows (and parallel human-action streams)
 * into a rendered timeline payload for the Alert War Room Timeline tab
 * and for the Copilot's `narrate()` method.
 *
 * Input:  raw DB rows (array<array> or Collection)
 * Output: shaped payload ready for <x-ui.timeline>
 *
 * Shape of an output item:
 *   [
 *     'id'              => 123,
 *     'at'              => Carbon,
 *     'at_display'      => '14:02 local',
 *     'at_relative'     => '4 hr ago',
 *     'event_code'      => 'ACKNOWLEDGED',
 *     'event_label'     => 'Alert acknowledged',
 *     'category'        => 'HUMAN'|'SYSTEM'|'EMAIL'|'WORKFLOW'|'BREACH'|'CLINICAL',
 *     'category_label'  => 'Action taken',
 *     'actor_label'     => 'Dr. Akello (PHEOC officer)' | 'System' | 'WHO',
 *     'summary'         => '…',
 *     'severity'        => 'INFO|WARN|ERROR|CRITICAL',
 *     'severity_tone'   => 'info|warning|critical|default',
 *     'icon'            => 'check|alert|mail|repeat|clipboard|shield|heart',
 *     'meta'            => [...extra fields...],
 *   ]
 */
final class TimelineBuilder
{
    public function __construct(protected EnumTranslator $enum)
    {
    }

    /**
     * Build a rendered timeline from raw event rows.
     *
     * @param  iterable<array|object> $events
     * @return array<int,array>
     */
    public function build(iterable $events): array
    {
        $rows = collect($events)->map(fn ($row) => (array) $row);

        return $rows->map(function ($row) {
            $at = $this->parseDateTime($row['created_at'] ?? $row['at'] ?? null);

            $code      = strtoupper((string) ($row['event_code'] ?? ''));
            $category  = strtoupper((string) ($row['event_category'] ?? 'SYSTEM'));
            $severity  = strtoupper((string) ($row['severity'] ?? 'INFO'));

            return [
                'id'             => $row['id'] ?? null,
                'at'             => $at,
                'at_display'     => $at ? $at->format('H:i d M') : '—',
                'at_relative'    => $at ? $at->diffForHumans() : '—',
                'event_code'     => $code,
                'event_label'    => $this->enum->timelineEvent($code),
                'category'       => $category,
                'category_label' => $this->categoryLabel($category),
                'actor_label'    => $this->actorLabel($row),
                'summary'        => (string) ($row['summary'] ?? ''),
                'severity'       => $severity,
                'severity_tone'  => $this->enum->severityTone($severity),
                'icon'           => $this->iconFor($code, $category),
                'meta'           => $row['meta'] ?? $row['payload'] ?? [],
            ];
        })->sortByDesc('at')->values()->all();
    }

    /**
     * Group events by day ("Today", "Yesterday", "<date>") for the
     * vertical timeline layout.
     *
     * @return array<string,array<int,array>>
     */
    public function grouped(iterable $events): array
    {
        $items = $this->build($events);
        $grouped = [];

        foreach ($items as $item) {
            $at = $item['at'];
            if (! $at) {
                $grouped['Unknown'][] = $item;
                continue;
            }
            $today = Carbon::today();
            if ($at->isSameDay($today)) {
                $key = 'Today';
            } elseif ($at->isSameDay($today->copy()->subDay())) {
                $key = 'Yesterday';
            } elseif ($at->greaterThan($today->copy()->subDays(7))) {
                $key = $at->format('l, d M');
            } else {
                $key = $at->format('d M Y');
            }
            $grouped[$key][] = $item;
        }

        return $grouped;
    }

    /**
     * One-sentence narrative summary of the most recent N events.
     * Used by PheocCopilot::narrate() as a base layer.
     */
    public function summarise(iterable $events, int $limit = 5): string
    {
        $items = array_slice($this->build($events), 0, $limit);
        if (empty($items)) {
            return 'No activity recorded on this alert yet.';
        }
        $parts = [];
        foreach ($items as $it) {
            $parts[] = "{$it['event_label']} · {$it['at_relative']}";
        }
        return implode(' · ', $parts);
    }

    /* ────────────────────────────────────────────────────────────── */

    protected function parseDateTime($value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) return Carbon::instance($value);
        if (is_string($value) && $value !== '') {
            try { return Carbon::parse($value); } catch (\Throwable) { return null; }
        }
        return null;
    }

    protected function categoryLabel(string $code): string
    {
        return match ($code) {
            'HUMAN'    => 'Action taken',
            'SYSTEM'   => 'System event',
            'EMAIL'    => 'Notification',
            'WORKFLOW' => 'Workflow',
            'BREACH'   => 'Compliance',
            'CLINICAL' => 'Clinical',
            default    => ucwords(strtolower($code)),
        };
    }

    protected function actorLabel(array $row): string
    {
        $actor = $row['actor_full_name'] ?? $row['actor_name'] ?? null;
        $role  = $row['actor_role'] ?? null;
        if ($actor && $role) {
            return $actor . ' (' . $this->enum->roleKey((string) $role) . ')';
        }
        if ($actor) return $actor;
        if ($role)  return $this->enum->roleKey((string) $role);
        $category = strtoupper((string) ($row['event_category'] ?? ''));
        return $category === 'SYSTEM' ? 'System' : '—';
    }

    /** Pick an icon key the <x-ui.timeline> component knows how to render. */
    protected function iconFor(string $code, string $category): string
    {
        if (str_contains($code, 'ACKNOWLEDGE') || $code === 'FOLLOWUP_COMPLETED') return 'check';
        if (str_contains($code, 'CLOSE') || $code === 'ALERT_CLOSED') return 'check-circle';
        if (str_contains($code, 'ESCALATE') || $code === 'PHEIC_DECLARED') return 'alert';
        if (str_contains($code, 'HANDOFF')) return 'repeat';
        if (str_contains($code, 'COMMENT')) return 'message';
        if (str_contains($code, 'EVIDENCE')) return 'clipboard';
        if (str_contains($code, 'FOLLOWUP')) return 'check-square';
        if (str_contains($code, 'BREACH'))   return 'shield';
        if (str_contains($code, 'NOTIFICATION')) return 'mail';
        if (str_contains($code, 'CREATED'))  return 'sparkle';
        if ($category === 'CLINICAL')        return 'heart';
        if ($category === 'EMAIL')           return 'mail';
        return 'circle';
    }
}
