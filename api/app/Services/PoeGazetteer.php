<?php

declare(strict_types=1);

namespace App\Services;

/**
 * PoeGazetteer
 * ─────────────────────────────────────────────────────────────────────────
 * Single source of truth for the Provincial PHEOC → District → POE
 * hierarchy that the mobile app loads from POES.js (window.POE_MAIN). The
 * admin panel needs the SAME cascade so users can only pick a district
 * that actually belongs to the selected Provincial PHEOC, and a POE that
 * actually lives in the selected district — matching the mobile
 * UsersList.vue UX.
 *
 * Parses base_path('../src/POEs.js') once per process (file-scoped static
 * cache — no Laravel cache dependency, no DB roundtrip).
 *
 * Method contract:
 *   pheocDistricts()  → ['Central Region PHEOC' => ['Kampala District', …], …]
 *   districtPoes()    → ['Chirundu District' => ['Chirundu', …], …]
 *   pheocList()       → ['Eastern Region PHEOC', …]  (alphabetical)
 *   districtList()    → ['Chadiza District', …]           (alphabetical)
 *   poeList()         → ['Chanida', …]                    (alphabetical, de-duped)
 */
final class PoeGazetteer
{
    /** @var array{pheoc_districts:array<string,string[]>,district_poes:array<string,string[]>}|null */
    private static ?array $cache = null;

    /** @return array<string,string[]> */
    public static function pheocDistricts(): array
    {
        return self::load()['pheoc_districts'];
    }

    /** @return array<string,string[]> */
    public static function districtPoes(): array
    {
        return self::load()['district_poes'];
    }

    /** @return string[] */
    public static function pheocList(): array
    {
        $keys = array_keys(self::pheocDistricts());
        sort($keys);
        return $keys;
    }

    /** @return string[] */
    public static function districtList(): array
    {
        $flat = [];
        foreach (self::pheocDistricts() as $districts) {
            foreach ($districts as $d) $flat[$d] = true;
        }
        $out = array_keys($flat);
        sort($out);
        return $out;
    }

    /** @return string[] */
    public static function poeList(): array
    {
        $flat = [];
        foreach (self::districtPoes() as $poes) {
            foreach ($poes as $p) $flat[$p] = true;
        }
        $out = array_keys($flat);
        sort($out);
        return $out;
    }

    /** @return array{pheoc_districts:array<string,string[]>,district_poes:array<string,string[]>} */
    private static function load(): array
    {
        if (self::$cache !== null) return self::$cache;

        $path = base_path('../src/POEs.js');
        if (! is_file($path)) {
            self::$cache = ['pheoc_districts' => [], 'district_poes' => []];
            return self::$cache;
        }

        $js = (string) file_get_contents($path);
        // window.POE_MAIN = { … };
        $start = strpos($js, '{');
        $end   = strrpos($js, '}');
        if ($start === false || $end === false || $end <= $start) {
            self::$cache = ['pheoc_districts' => [], 'district_poes' => []];
            return self::$cache;
        }
        $json = substr($js, $start, $end - $start + 1);
        $data = json_decode($json, true);
        if (! is_array($data)) {
            self::$cache = ['pheoc_districts' => [], 'district_poes' => []];
            return self::$cache;
        }

        $pheocDistricts = [];
        foreach (($data['administrative_groups'] ?? []) as $g) {
            if (($g['country'] ?? '') !== config('country.legacy_code')) continue;
            $pheoc = (string) ($g['admin_level_1'] ?? '');
            if ($pheoc === '') continue;
            $pheocDistricts[$pheoc] = array_values(array_filter((array) ($g['districts'] ?? [])));
        }

        $districtPoes = [];
        foreach (($data['poes'] ?? []) as $p) {
            if (($p['country'] ?? '') !== config('country.legacy_code')) continue;
            $d  = (string) ($p['district'] ?? '');
            $pn = (string) ($p['poe_name'] ?? '');
            if ($d === '' || $pn === '') continue;
            $districtPoes[$d][] = $pn;
        }
        foreach ($districtPoes as $d => $list) {
            $districtPoes[$d] = array_values(array_unique($list));
            sort($districtPoes[$d]);
        }

        self::$cache = ['pheoc_districts' => $pheocDistricts, 'district_poes' => $districtPoes];
        return self::$cache;
    }
}
