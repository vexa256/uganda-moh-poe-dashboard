<?php

declare(strict_types=1);

namespace App\Services;

/**
 * SsotRegistry
 * ─────────────────────────────────────────────────────────────────────────
 * Parses the hardcoded reference files that the mobile app loads from
 * window globals, and exposes them server-side as PHP arrays. Admin views
 * are seeded from here so the backend renders the same authoritative data
 * the mobile app uses — zero re-entry, zero divergence.
 *
 * Sources (all copied into api/public/ssot/ from ecsa_poe_2026/src/):
 *   · POEs.js                 → window.POE_MAIN         (61 POEs, admin_groups)
 *   · Diseases.js             → window.DISEASES         (42 diseases + engine)
 *   · Diseases_intelligence.js→ window.DISEASES (patch) (clinical intel)
 *   · exposures.js            → window.EXPOSURES        (exposure catalog)
 *
 * Each parser strips the leading `window.X = ` and trailing `;`, then json_decode's
 * the object literal. Cache is file-scoped static — lives for the process.
 */
final class SsotRegistry
{
    /** @var array<string,array> */
    private static array $cache = [];

    // ── POEs ───────────────────────────────────────────────────────────────
    /** @return array{metadata:array,administrative_groups:array,poes:array,traveler_notes:array} */
    public static function poeMain(): array
    {
        if (isset(self::$cache['poe_main'])) return self::$cache['poe_main'];

        // src/POEs.js is now a loader, not a data literal, so the regex-based
        // parser below may return [] against a current checkout.  Prefer the
        // live DB, which is byte-equivalent to the legacy window.POE_MAIN
        // shape via GeoHierarchyController::assembleBundle.
        $fromDb = self::poeMainFromDb();
        if (!empty($fromDb['poes'])) {
            return self::$cache['poe_main'] = $fromDb;
        }

        // Fallback: try parsing the JS literal (works on old checkouts where
        // POEs.js still contained the hardcoded blob).
        $fromJs = self::loadObject(self::path('POEs.js'), 'window.POE_MAIN');
        if (!empty($fromJs['poes'])) {
            return self::$cache['poe_main'] = $fromJs;
        }

        return self::$cache['poe_main'] = $fromDb;      // empty but well-shaped
    }

    /**
     * Reassemble the legacy window.POE_MAIN shape from the normalized
     * ref_* tables.  Key order matches POEs.js for byte-equivalence with
     * the mobile bundle.
     *
     * @return array{metadata:array,traveler_notes:array,administrative_groups:array,poes:array}
     */
    private static function poeMainFromDb(string $country = ''): array
    {
        // Fall back to the per-deployment tenant when caller passed nothing.
        // ref_geo_metadata.country_code stores the FULL name ('Uganda',
        // 'Uganda'), so we use config('country.legacy_code').
        if ($country === '') $country = (string) config('country.legacy_code');
        try {
            $db = \Illuminate\Support\Facades\DB::connection();

            // metadata
            $metaRows = $db->table('ref_geo_metadata')
                ->where('country_code', $country)
                ->orderBy('display_order')->orderBy('id')
                ->get();
            $metadataOrder = [
                'dataset_name', 'schema_version', 'created_from_user_supplied_text_on',
                'countries', 'country_entry_counts', 'primary_filter_fields',
                'cross_country_mapping_note', 'data_quality_notes',
            ];
            $metaMap = [];
            foreach ($metaRows as $mr) {
                $metaMap[$mr->meta_key] = json_decode((string) $mr->meta_value, true);
            }
            $metadata = [];
            foreach ($metadataOrder as $k) if (array_key_exists($k, $metaMap)) $metadata[$k] = $metaMap[$k];
            foreach ($metaMap as $k => $v) if (!array_key_exists($k, $metadata)) $metadata[$k] = $v;

            // traveler_notes
            $noteRows = $db->table('ref_traveler_notes')
                ->where('country_code', $country)->where('is_active', 1)
                ->orderBy('display_order')->orderBy('id')->get();
            $travelerNotes = [];
            foreach ($noteRows as $nr) {
                $recommended = json_decode((string) $nr->recommended_poes_json, true) ?: [];
                $travelerNotes[$nr->note_key] = $nr->note_type === 'MULTI'
                    ? ['recommended_poes' => $recommended, 'note' => (string) $nr->note_text]
                    : ['recommended_poe'  => $recommended[0] ?? '', 'note' => (string) $nr->note_text];
            }

            // administrative_groups
            $provinces = $db->table('ref_provinces')
                ->where('country_code', $country)->whereNull('deleted_at')->where('is_active', 1)
                ->orderBy('display_order')->orderBy('id')->get();
            $adminGroups = [];
            foreach ($provinces as $p) {
                $districts = $db->table('ref_districts')
                    ->where('country_code', $country)->where('province_id', $p->id)
                    ->whereNull('deleted_at')->where('is_active', 1)
                    ->orderBy('display_order')->orderBy('id')->pluck('name')->all();
                $adminGroups[] = [
                    'country'            => $country,
                    'admin_level_1'      => (string) $p->name,
                    'admin_level_1_type' => (string) $p->admin_level_1_type,
                    'districts'          => $districts,
                ];
            }

            // poes — payloads preserved byte-for-byte
            $poeKeyOrder = [
                'id','country','province','admin_level_1','admin_level_1_type',
                'district','district_raw','poe_name','poe_code','poe_type','transport_mode',
                'border_country','is_major_entry','is_recommended_osbp','is_national_level',
                'regional_cluster_or_rpheoc','critical_details','source_province_group',
                'source_url','source_origin',
            ];
            $poeRows = $db->table('ref_poes')
                ->where('country_code', $country)->whereNull('deleted_at')->where('is_active', 1)
                ->orderBy('display_order')->orderBy('id')->get();
            $poes = [];
            foreach ($poeRows as $pr) {
                $payload = $pr->payload ? (json_decode((string) $pr->payload, true) ?: []) : [];
                $ordered = [];
                foreach ($poeKeyOrder as $k) {
                    $ordered[$k] = array_key_exists($k, $payload)
                        ? $payload[$k]
                        : (in_array($k, ['is_major_entry','is_recommended_osbp','is_national_level'], true) ? false : null);
                }
                foreach (['is_major_entry','is_recommended_osbp','is_national_level'] as $b) {
                    $ordered[$b] = (bool) $ordered[$b];
                }
                $poes[] = $ordered;
            }

            return [
                'metadata'              => $metadata,
                'traveler_notes'        => $travelerNotes,
                'administrative_groups' => $adminGroups,
                'poes'                  => $poes,
            ];
        } catch (\Throwable $e) {
            return ['metadata' => [], 'traveler_notes' => [], 'administrative_groups' => [], 'poes' => []];
        }
    }

    /** @return array<string,string[]>  ['Central Region PHEOC' => ['Kampala District', …]] */
    public static function pheocDistricts(): array
    {
        if (isset(self::$cache['pheoc_districts'])) return self::$cache['pheoc_districts'];
        $out = [];
        foreach ((self::poeMain()['administrative_groups'] ?? []) as $g) {
            if (($g['country'] ?? '') !== config('country.legacy_code')) continue;
            $k = (string) ($g['admin_level_1'] ?? '');
            if ($k !== '') $out[$k] = array_values((array) ($g['districts'] ?? []));
        }
        return self::$cache['pheoc_districts'] = $out;
    }

    /** @return array<string,string[]> */
    public static function districtPoes(): array
    {
        if (isset(self::$cache['district_poes'])) return self::$cache['district_poes'];
        $out = [];
        foreach ((self::poeMain()['poes'] ?? []) as $p) {
            if (($p['country'] ?? '') !== config('country.legacy_code')) continue;
            $d  = (string) ($p['district'] ?? '');
            $pn = (string) ($p['poe_name'] ?? '');
            if ($d && $pn) $out[$d][] = $pn;
        }
        foreach ($out as $d => $list) { $out[$d] = array_values(array_unique($list)); sort($out[$d]); }
        return self::$cache['district_poes'] = $out;
    }

    /** Flat list of POE entries with full metadata — one per gazetted POE. */
    public static function poes(): array
    {
        $rows = [];
        foreach ((self::poeMain()['poes'] ?? []) as $p) {
            if (($p['country'] ?? '') !== config('country.legacy_code')) continue;
            $rows[] = $p;
        }
        usort($rows, fn($a, $b) => strcmp((string) $a['poe_name'], (string) $b['poe_name']));
        return $rows;
    }

    // ── DISEASES ───────────────────────────────────────────────────────────
    /** @return array{metadata:array,engine:array,priority_tiers:array,diseases:array,sources:array} */
    public static function diseasesRoot(): array
    {
        return self::$cache['diseases_root'] ??= self::loadObject(self::path('Diseases.js'), 'window.DISEASES');
    }

    /** @return array<int,array> list of disease records (id, name, priority_tier, …) */
    public static function diseases(): array
    {
        return self::diseasesRoot()['diseases'] ?? [];
    }

    /** @return array<string,string> priority tier key → human label */
    public static function diseaseTiers(): array
    {
        return self::diseasesRoot()['priority_tiers'] ?? [];
    }

    // ── EXPOSURES ──────────────────────────────────────────────────────────
    /** @return array{metadata:array,exposures:array} */
    public static function exposuresRoot(): array
    {
        return self::$cache['exposures_root'] ??= self::loadObject(self::path('exposures.js'), 'window.EXPOSURES');
    }

    /** @return array<int,array> */
    public static function exposures(): array
    {
        return self::exposuresRoot()['exposures'] ?? [];
    }

    // ── internals ──────────────────────────────────────────────────────────
    private static function path(string $basename): string
    {
        // Prefer the published copy so ops can treat public/ssot/ as the
        // canonical location, but fall back to the workspace source if the
        // file hasn't been published yet (dev mode).
        $pub = base_path('public/ssot/' . $basename);
        if (is_file($pub)) return $pub;
        return base_path('../src/' . $basename);
    }

    /**
     * Extract the first `=` through the last `}` in a `window.X = {...};` file
     * and json_decode the resulting literal.
     *
     * @return array
     */
    private static function loadObject(string $path, string $needle): array
    {
        if (! is_file($path)) return [];
        $raw = (string) file_get_contents($path);
        // Anchor to the assignment itself — not a stray mention inside a doc
        // comment. Needle expected to be e.g. `window.EXPOSURES`; we search for
        // `window.EXPOSURES\s*=\s*{` and resume from the `{`.
        $pattern = '/' . preg_quote($needle, '/') . '\s*=\s*\{/';
        if (! preg_match($pattern, $raw, $m, PREG_OFFSET_CAPTURE)) return [];
        $start = (int) $m[0][1] + strlen($m[0][0]) - 1; // points to the `{`
        $body  = $raw;

        // Bracket-balance scan so we stop at the matching }, ignoring braces
        // inside strings. Uses chr(92) for the backslash to sidestep PHP
        // single-quote escape ambiguity.
        $depth = 0; $inStr = false; $q = null; $prev = '';
        $end = -1;
        $bs  = chr(92);
        $len = strlen($body);
        for ($i = $start; $i < $len; $i++) {
            $ch = $body[$i];
            if ($inStr) {
                if ($ch === $q && $prev !== $bs) { $inStr = false; $q = null; }
            } else {
                if ($ch === '"' || $ch === "'") { $inStr = true; $q = $ch; }
                elseif ($ch === '{') $depth++;
                elseif ($ch === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
            }
            $prev = $ch;
        }
        if ($end < 0) return [];
        $json = substr($body, $start, $end - $start + 1);

        // JS object literals often use unquoted keys and trailing commas and
        // single-quoted strings. Rather than write a full parser, we try
        // json_decode first (POEs.js happens to be strict JSON), then fall
        // back to a JS→JSON conversion for the other files.
        $data = json_decode($json, true);
        if (is_array($data)) return $data;
        $data = json_decode(self::jsToJson($json), true);
        return is_array($data) ? $data : [];
    }

    /**
     * Lightweight JS-object-literal → JSON converter. Handles the three
     * deviations we actually see in the mobile SSOT files:
     *   · unquoted object keys          foo: 1       → "foo": 1
     *   · single-quoted strings         'abc'        → "abc"
     *   · trailing commas               ,}  or  ,]   → }  or  ]
     * It is tolerant of commented lines too (line and block comments).
     */
    private static function jsToJson(string $src): string
    {
        // Strip JS comments (both /* … */ and //…) in a string-aware pass so
        // we don't mangle URLs like `https://example.com` that live inside
        // single-quoted string literals.
        $src = self::stripComments($src);

        // Remove JS-style method definitions from the literal:
        //     foo: function(x) { body }            (long form)
        //     foo(x) { body }                       (ES6 shorthand)
        // Both are invalid JSON. We walk the source, find each method header
        // and consume its body by brace-matching, replacing the whole block
        // with `"foo": null,` so trailing commas still parse.
        $src = self::stripMethods($src);

        // Walk character-by-character so we don't mangle content inside strings.
        $out = '';
        $len = strlen($src);
        $inStr = false; $q = null; $prev = '';
        $bs  = chr(92);
        for ($i = 0; $i < $len; $i++) {
            $ch = $src[$i];
            if ($inStr) {
                if ($ch === $q && $prev !== $bs) {
                    // Convert closing single-quote to double-quote.
                    if ($q === "'") $out .= '"'; else $out .= $ch;
                    $inStr = false; $q = null;
                } else {
                    // Inside a single-quoted string, translate JS-specific
                    // escapes into JSON-safe ones:
                    //   \'  → '    (invalid in JSON, unescape since we're now
                    //              inside a double-quoted JSON string)
                    //   "   → \"   (needs escaping in a double-quoted string)
                    if ($q === "'" && $prev === $bs && $ch === "'") {
                        // Rewrite the trailing backslash we already emitted.
                        $out = substr($out, 0, -1) . "'";
                    } elseif ($q === "'" && $ch === '"') {
                        $out .= '\\"';
                    } else {
                        $out .= $ch;
                    }
                }
            } else {
                if ($ch === '"' || $ch === "'") {
                    $inStr = true; $q = $ch;
                    $out .= ($q === "'") ? '"' : $ch;
                } else {
                    $out .= $ch;
                }
            }
            $prev = $ch;
        }

        // Quote unquoted keys:  {foo: 1}  →  {"foo": 1}
        // Only match identifier-like keys directly before a colon, preceded by
        // { , or whitespace + comma.
        $out = preg_replace(
            '/([\{\,]\s*)([A-Za-z_][A-Za-z0-9_]*)\s*:/',
            '$1"$2":',
            $out
        ) ?? $out;

        // Kill trailing commas before } or ].
        $out = preg_replace('/,\s*(\}|\])/', '$1', $out) ?? $out;

        return $out;
    }

    /**
     * Strip JS `/* … *\/` and `//…\n` comments — but skip characters that
     * live inside a quoted string so URLs with `//` aren't mistaken for
     * comments.
     */
    private static function stripComments(string $src): string
    {
        $out = '';
        $len = strlen($src);
        $inStr = false; $q = null; $prev = ''; $bs = chr(92);
        $i = 0;
        while ($i < $len) {
            $ch = $src[$i];
            if ($inStr) {
                $out .= $ch;
                if ($ch === $q && $prev !== $bs) { $inStr = false; $q = null; }
                $prev = $ch; $i++;
                continue;
            }
            if ($ch === '/' && $i + 1 < $len) {
                $next = $src[$i + 1];
                if ($next === '*') {
                    // Consume /* … */
                    $close = strpos($src, '*/', $i + 2);
                    $i = $close === false ? $len : $close + 2;
                    $prev = '';
                    continue;
                }
                if ($next === '/') {
                    // Consume // … newline (but PRESERVE the newline so line
                    // numbers stay mostly stable and so arrays with one item
                    // per line don't get concatenated).
                    $nl = strpos($src, "\n", $i + 2);
                    $i = $nl === false ? $len : $nl;
                    $prev = '';
                    continue;
                }
            }
            if ($ch === '"' || $ch === "'") {
                $inStr = true; $q = $ch;
            }
            $out .= $ch; $prev = $ch; $i++;
        }
        return $out;
    }

    /**
     * Remove ES6-shorthand and function-expression method definitions from
     * a JS object literal. We scan left-to-right, spotting the pattern
     *   <identifier> ( <args> ) <space>? {
     * at a place where a property value is expected, then skip over the
     * matching } using bracket-counting (aware of strings and // comments
     * inside the body).
     */
    private static function stripMethods(string $src): string
    {
        $out = '';
        $len = strlen($src);
        $i = 0;
        $bs = chr(92);
        while ($i < $len) {
            // Try to match <key>(args) {   or   <key>: function(args) {
            // Both must be preceded by `{` or `,` (maybe with whitespace) to
            // qualify as a property-value position.
            if (preg_match(
                '/\G([\{,]\s*)([A-Za-z_][A-Za-z0-9_]*)(\s*:\s*function\s*\([^)]*\)|\s*\([^)]*\))\s*\{/A',
                $src, $m, 0, $i
            )) {
                $preamble = $m[1];
                $name     = $m[2];
                $headerEnd = $i + strlen($m[0]);
                // headerEnd points just past the `{` — we are inside the body.
                $depth = 1; $inStr = false; $q = null; $prev = '';
                $j = $headerEnd;
                while ($j < $len && $depth > 0) {
                    $ch = $src[$j];
                    if ($inStr) {
                        if ($ch === $q && $prev !== $bs) { $inStr = false; $q = null; }
                    } else {
                        if ($ch === '"' || $ch === "'") { $inStr = true; $q = $ch; }
                        elseif ($ch === '{') $depth++;
                        elseif ($ch === '}') $depth--;
                    }
                    $prev = $ch; $j++;
                }
                // Emit `<preamble> "<name>": null` and leave the trailing
                // comma (if any) in the source so the NEXT method has a
                // valid preamble to match against. The trailing-comma
                // killer pass will clean up if this was the last property.
                $out .= $preamble . '"' . $name . '": null';
                $i = $j;
                continue;
            }
            $out .= $src[$i++];
        }
        return $out;
    }
}
