<?php

declare(strict_types=1);

namespace App\Support\Governance;

use Illuminate\Support\Facades\Lang;

/**
 * Coach Manifest loader for the Governance admin module.
 *
 * Reads from resources/lang/en/coach_governance.php and returns a structured
 * array keyed by view id ('auth' | 'notif-log' | 'reminders' | 'templates'
 * | 'dq' | 'retention'). The Governance-wide glossary is automatically
 * merged into each view's glossary, de-duplicated by lowercase term.
 *
 * Strings live in language files. Views never hardcode coach text.
 *
 * Mirror of App\Support\Workforce\CoachManifest. Kept separate so that the
 * Workforce manifest can evolve at a different cadence to Governance without
 * one wave breaking the other.
 */
final class CoachManifest
{
    /** Public view ids supported by this manifest. */
    public const VIEWS = ['auth', 'notif-log', 'reminders', 'templates', 'dq', 'retention'];

    /**
     * Return the manifest for one Governance view, ready to hand to a Blade
     * template. Returns an empty shell when the view id is unknown so views
     * can render defensively without null-checks everywhere.
     */
    public static function forView(string $viewId): array
    {
        $viewId = strtolower(trim($viewId));
        if (! in_array($viewId, self::VIEWS, true)) {
            return self::emptyShell($viewId);
        }

        $all = self::all();

        $manifest = $all[$viewId] ?? [];
        $manifest = self::ensureShell($manifest);

        // Merge the Governance-wide glossary in front of view-specific
        // glossary, de-duplicated by lowercase term.
        $common  = $all['glossary_common'] ?? [];
        $merged  = array_merge($common, $manifest['glossary'] ?? []);
        $seen    = [];
        $manifest['glossary'] = array_values(array_filter($merged, function ($row) use (&$seen) {
            $k = strtolower((string) ($row['term'] ?? ''));
            if ($k === '' || isset($seen[$k])) return false;
            $seen[$k] = true;
            return true;
        }));

        $manifest['_view_id'] = $viewId;
        return $manifest;
    }

    private static function ensureShell(array $m): array
    {
        return array_merge([
            'view'                => [],
            'actions'             => [],
            'modals'              => [],
            'glossary'            => [],
            'comparison_columns'  => null,
            'pre_confirm'         => null,
            'post_action'         => null,
        ], $m);
    }

    private static function emptyShell(string $viewId): array
    {
        return self::ensureShell(['_view_id' => $viewId]);
    }

    /**
     * @return array<string,mixed>
     */
    private static function all(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $payload = Lang::get('coach_governance');
        $cache = is_array($payload) ? $payload : [];
        return $cache;
    }
}
