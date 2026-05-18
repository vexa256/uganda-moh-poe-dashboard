<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SsotRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * ReferenceDataController
 * ─────────────────────────────────────────────────────────────────────────
 * Renders the SSOT reference catalogs — the authoritative disease,
 * exposure, and POE registries that the mobile app consumes from window
 * globals. Parsed server-side by SsotRegistry so the admin panel shows
 * the exact same data without requiring the browser to load 400KB of JS.
 *
 *   GET /admin/settings/poes       — 39 gazetted Uganda POEs
 *   GET /admin/settings/diseases   — 42 diseases (IHR tiers 1–4)
 *   GET /admin/settings/exposures  — 29 WHO POE exposures (12 categories)
 */
final class ReferenceDataController extends Controller
{
    public function poes(Request $r): View
    {
        $poes = SsotRegistry::poes();
        $meta = SsotRegistry::poeMain()['metadata'] ?? [];
        return view('admin.settings.poes', [
            'poes' => $poes,
            'meta' => $meta,
            'pheocDistricts' => SsotRegistry::pheocDistricts(),
            'districtPoes'   => SsotRegistry::districtPoes(),
        ]);
    }

    public function diseases(Request $r): View
    {
        $root = SsotRegistry::diseasesRoot();
        return view('admin.settings.diseases', [
            'diseases' => SsotRegistry::diseases(),
            'tiers'    => SsotRegistry::diseaseTiers(),
            'meta'     => $root['metadata'] ?? [],
            'engine'   => $root['engine']   ?? [],
            'sources'  => $root['sources']  ?? [],
        ]);
    }

    public function exposures(Request $r): View
    {
        $root = SsotRegistry::exposuresRoot();
        return view('admin.settings.exposures', [
            'exposures' => SsotRegistry::exposures(),
            'meta'      => $root['metadata'] ?? [],
        ]);
    }
}
