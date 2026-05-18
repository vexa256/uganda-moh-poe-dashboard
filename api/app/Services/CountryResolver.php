<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * CountryResolver
 * ================================================================
 * One authoritative place to convert between the many forms a country
 * code may appear in across this system. `ref_countries` is the SSoT
 * and carries every alias on a single row:
 *
 *     country_code  — the canonical form ("Uganda")
 *     iso_alpha2    — ISO 3166-1 alpha-2 ("ZM")
 *     iso_alpha3    — ISO 3166-1 alpha-3 ("ZMB")
 *     name          — display name (typically identical to country_code)
 *
 * Historically, writers from different parts of the app (the mobile
 * AggregatedTemplatesController, the legacy screenings pipeline, the
 * admin geo controllers) have each picked a different alias when they
 * inserted rows. Some rows carry "ZM", others carry "Uganda", others
 * carry "ZMB". The result is scope filters with `where('country_code', ...)`
 * silently returning zero rows even though matching data exists.
 *
 * This service is the sustainable fix:
 *   - Writers canonicalize every inbound country_code to the SSoT form
 *     before insert. Consistency moves forward.
 *   - Readers that want to survive the historical drift can call
 *     `aliases()` and use `whereIn(..., aliases)` as a safety net.
 *   - A one-off migration backfills legacy rows to the canonical form
 *     so the safety net eventually stops mattering.
 *
 * Results are memoised per-request — there's only one tenant row, so a
 * single query seeds every lookup for the rest of the request lifecycle.
 *
 * This service does NOT mutate data. It is read-only over ref_countries.
 */
final class CountryResolver
{
    /** @var array<string,string>  alias (upper-cased) → canonical country_code */
    private array $canonicalByAlias = [];

    /** @var array<string,array<int,string>>  canonical → list of every alias */
    private array $aliasesByCanonical = [];

    private bool $loaded = false;

    /**
     * Return the canonical country_code (the SSoT's `country_code` column)
     * for any alias: 'UG' / 'Uganda' / 'UGA' / 'uganda' all return 'Uganda'.
     *
     * Returns null if the alias matches no ref_countries row. Callers should
     * decide whether to accept the raw alias as-is or reject it.
     */
    public function canonical(?string $alias): ?string
    {
        if ($alias === null || $alias === '') return null;
        $this->load();
        return $this->canonicalByAlias[strtoupper($alias)] ?? null;
    }

    /**
     * Return every alias for the country that `$anyForm` resolves to. If
     * `$anyForm` doesn't match any known country, returns an array
     * containing just `$anyForm` so read-side whereIn still matches its
     * own historical writes.
     *
     * @return array<int,string>
     */
    public function aliases(?string $anyForm): array
    {
        if ($anyForm === null || $anyForm === '') return [];
        $this->load();
        $canonical = $this->canonicalByAlias[strtoupper($anyForm)] ?? null;
        if ($canonical === null) {
            return [$anyForm];
        }
        return $this->aliasesByCanonical[$canonical] ?? [$canonical];
    }

    /**
     * Forceful canonicalise — returns canonical if known, else the raw
     * input. Handy for controllers that want to store the best-effort
     * form without rejecting unknown countries.
     */
    public function canonicalOrRaw(?string $alias): ?string
    {
        if ($alias === null || $alias === '') return $alias;
        return $this->canonical($alias) ?? $alias;
    }

    /** Clear the memo — only used by tests/migrations that mutate ref_countries. */
    public function flush(): void
    {
        $this->canonicalByAlias = [];
        $this->aliasesByCanonical = [];
        $this->loaded = false;
    }

    private function load(): void
    {
        if ($this->loaded) return;
        $rows = DB::table('ref_countries')
            ->when(DB::getSchemaBuilder()->hasColumn('ref_countries', 'deleted_at'),
                fn ($q) => $q->whereNull('deleted_at'))
            ->get(['country_code', 'iso_alpha2', 'iso_alpha3', 'name']);
        foreach ($rows as $r) {
            $canonical = (string) $r->country_code;
            if ($canonical === '') continue;
            $aliases = [];
            foreach (['country_code', 'iso_alpha2', 'iso_alpha3', 'name'] as $f) {
                $v = (string) ($r->{$f} ?? '');
                if ($v === '') continue;
                if (! in_array($v, $aliases, true)) $aliases[] = $v;
                $this->canonicalByAlias[strtoupper($v)] = $canonical;
            }
            $this->aliasesByCanonical[$canonical] = $aliases;
        }
        $this->loaded = true;
    }
}
