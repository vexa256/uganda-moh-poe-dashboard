<?php

declare(strict_types=1);

namespace App\Support\Situation;

use Illuminate\Support\Facades\Lang;

/**
 * CoachManifest loader for the Situation Room.
 *
 * Reads from resources/lang/en/coach_situation.php and exposes a single
 * structured array the Blade view consumes. Strings live in language
 * files; views never hardcode coach copy.
 *
 * Mirrors the pattern used by App\Support\Workforce\CoachManifest and
 * App\Support\Governance\CoachManifest. Kept separate so that the
 * Situation Room can evolve at its own cadence without affecting other
 * modules.
 */
final class CoachManifest
{
    /**
     * Load the full Situation Room manifest, ready to hand to a Blade
     * template. Returns a defensive shell on failure so the view does
     * not need null-checks everywhere.
     *
     * @return array<string,mixed>
     */
    public static function load(): array
    {
        $payload = Lang::get('coach_situation');
        $payload = is_array($payload) ? $payload : [];
        return self::ensureShell($payload);
    }

    /** Shorthand for one chart's wizard content, defensively. */
    public static function chart(string $key): array
    {
        $all = self::load();
        $charts = $all['charts'] ?? [];
        return is_array($charts[$key] ?? null) ? $charts[$key] : [];
    }

    /**
     * Return the scope-aware wording for the screen header. The view
     * substitutes the right phrase based on the user's scope_level so
     * the greeting reads "Kampala District" / "your PHEOC" / "Uganda"
     * appropriately.
     */
    public static function scopeWording(?string $scopeLevel, ?string $scopeLabel = null): string
    {
        $all = self::load();
        $map = $all['view']['scope_wording'] ?? [];
        $level = strtoupper((string) ($scopeLevel ?? 'NATIONAL'));
        $phrase = $map[$level] ?? null;
        if ($phrase === null) {
            return $scopeLabel ?: config('country.legacy_code');
        }
        // The map carries the abstract phrase ("your PHEOC", "your district").
        // If a concrete label is provided we prefer it for the national level
        // (so the screen says "Uganda" rather than the abstract).
        if ($level === 'NATIONAL' && $scopeLabel) {
            return $scopeLabel;
        }
        return $phrase;
    }

    /** Pick a salutation keyed by hour-of-day band. */
    public static function greeting(?int $hour = null): string
    {
        $hour = $hour ?? (int) now()->format('G');
        $all = self::load();
        $map = $all['view']['greetings'] ?? [];
        if ($hour < 5)  return $map['night']     ?? 'Good evening';
        if ($hour < 12) return $map['morning']   ?? 'Good morning';
        if ($hour < 17) return $map['afternoon'] ?? 'Good afternoon';
        if ($hour < 22) return $map['evening']   ?? 'Good evening';
        return $map['night'] ?? 'Good evening';
    }

    private static function ensureShell(array $m): array
    {
        return array_merge([
            'view'                => [],
            'master_tour'         => ['steps' => []],
            'charts'              => [],
            'glossary'            => [],
            'comparison_columns'  => ['What it answers', 'When to look', 'Where it leads', 'What it cannot tell you'],
        ], $m);
    }
}
