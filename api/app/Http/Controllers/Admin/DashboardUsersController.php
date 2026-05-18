<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\UserController as MobileUserController;
use App\Services\PheocScope;
use App\Services\PoeGazetteer;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * DashboardUsersController
 * ─────────────────────────────────────────────────────────────────────────
 * Thin controller that renders the /admin/users Blade view. All data is
 * fetched client-side from /api/admin/users/* over the Sanctum-stateful
 * session, so this controller only seeds SSOT reference lists and the
 * actor's scope descriptor into the view.
 */
final class DashboardUsersController extends Controller
{
    public function index(Request $request, PheocScope $scope): View
    {
        $actor = $request->user();

        $viewScope = $actor ? $scope->forUser($actor) : [
            'is_super'    => true,                // preview mode
            'scope_level' => 'NATIONAL',
            'role_key'    => 'NATIONAL_ADMIN',
            'provinces'   => [], 'districts' => [], 'poes' => [],
            'country_code' => config('country.code'), 'countries' => [config('country.code')],
            'label'       => 'Preview · ' . config('country.legacy_code'),
            'primary_poe' => null,
        ];

        $roles = DB::table('role_registry')
            ->select('role_key','display_name','scope_level')
            ->orderBy('scope_level')->orderBy('display_name')
            ->get();

        return view('admin.users.index', [
            'actorScope' => $viewScope,
            'roles'      => $roles,
            'pheocNames'    => MobileUserController::VALID_PHEOC_NAMES,
            'districtNames' => MobileUserController::VALID_DISTRICT_NAMES,
            'poeNames'      => MobileUserController::VALID_POE_NAMES,
            'roleGeoRequirements' => MobileUserController::ROLE_GEO_REQUIREMENTS,
            'pheocDistricts'  => PoeGazetteer::pheocDistricts(),
            'districtPoes'    => PoeGazetteer::districtPoes(),
        ]);
    }

    public function risk(Request $request, PheocScope $scope): View
    {
        $viewScope = $request->user() ? $scope->forUser($request->user()) : [
            'is_super' => true, 'scope_level' => 'NATIONAL', 'role_key' => 'NATIONAL_ADMIN',
            'label' => 'Preview · ' . config('country.legacy_code'), 'country_code' => config('country.code'),
        ];
        $roles = DB::table('role_registry')->select('role_key','display_name','scope_level')->orderBy('display_name')->get();
        return view('admin.users.risk', ['actorScope' => $viewScope, 'roles' => $roles]);
    }

    public function dormant(Request $request, PheocScope $scope): View
    {
        $viewScope = $request->user() ? $scope->forUser($request->user()) : [
            'is_super' => true, 'scope_level' => 'NATIONAL', 'role_key' => 'NATIONAL_ADMIN',
            'label' => 'Preview · ' . config('country.legacy_code'), 'country_code' => config('country.code'),
        ];
        $roles = DB::table('role_registry')->select('role_key','display_name','scope_level')->orderBy('display_name')->get();
        return view('admin.users.dormant', ['actorScope' => $viewScope, 'roles' => $roles]);
    }

    public function assignments(Request $request, PheocScope $scope): View
    {
        $actor = $request->user();
        $viewScope = $actor ? $scope->forUser($actor) : [
            'is_super'    => true, 'scope_level' => 'NATIONAL', 'role_key' => 'NATIONAL_ADMIN',
            'provinces'   => [], 'districts' => [], 'poes' => [],
            'country_code' => config('country.code'), 'countries' => [config('country.code')],
            'label'       => 'Preview · ' . config('country.legacy_code'), 'primary_poe' => null,
        ];

        return view('admin.assignments.index', [
            'actorScope'     => $viewScope,
            'pheocNames'     => MobileUserController::VALID_PHEOC_NAMES,
            'districtNames'  => MobileUserController::VALID_DISTRICT_NAMES,
            'poeNames'       => MobileUserController::VALID_POE_NAMES,
            'pheocDistricts' => PoeGazetteer::pheocDistricts(),
            'districtPoes'   => PoeGazetteer::districtPoes(),
            'roleGeoRequirements' => MobileUserController::ROLE_GEO_REQUIREMENTS,
        ]);
    }
}
