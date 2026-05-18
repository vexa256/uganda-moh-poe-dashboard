<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PheocScope;
use App\Services\SsotRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Renders /admin/settings/poe-contacts — the admin surface for the routing
 * table that drives every emergency notification. Data is loaded client-side
 * from /api/poe-contacts/* with the legacy user_id query-param contract used
 * by PoeContactsController.
 */
final class DashboardPoeContactsController extends Controller
{
    public function index(Request $r, PheocScope $scope): View
    {
        $viewScope = $r->user() ? $scope->forUser($r->user()) : [
            'is_super' => true, 'scope_level' => 'NATIONAL', 'role_key' => 'NATIONAL_ADMIN',
            'label' => 'Preview · ' . config('country.legacy_code'), 'country_code' => config('country.code'),
        ];

        return view('admin.settings.poe-contacts', [
            'actorScope'     => $viewScope,
            'pheocDistricts' => SsotRegistry::pheocDistricts(),
            'districtPoes'   => SsotRegistry::districtPoes(),
        ]);
    }
}
