<?php

declare(strict_types=1);

namespace App\Support\Workforce;

use Illuminate\Support\Facades\Lang;

/**
 * Coach Manifest loader for the Workforce admin module.
 *
 * Reads from resources/lang/en/coach_workforce.php and returns a structured
 * array keyed by view id ('users' | 'roles' | 'assignments'). Workforce-wide
 * glossary terms are merged into each view's glossary so the coach panel can
 * always answer "what does this term mean?" without the view duplicating
 * common entries.
 *
 * Strings live in language files. Views never hardcode coach text.
 */
final class CoachManifest
{
    /** Public view ids supported by this manifest. */
    public const VIEWS = ['users', 'roles', 'assignments'];

    /**
     * Return the manifest for one workforce view, ready to hand to a Blade
     * template. Returns an empty shell when the view id is unknown so that
     * views can render defensively without null-checks everywhere.
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

        // Merge workforce-wide glossary in front of view-specific glossary,
        // de-duplicated by lowercase term.
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

    /** Defensive default — keeps Blade `@isset` / `??` chains clean. */
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
     * Whole language-file payload, cached per request.
     *
     * @return array<string,mixed>
     */
    private static function all(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $payload = Lang::get('coach_workforce');
        $cache = is_array($payload) ? $payload : [];
        return $cache;
    }
}
