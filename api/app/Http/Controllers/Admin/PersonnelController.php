<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PheocScope;
use App\Support\EnumTranslator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PersonnelController — M7 / H1 · Personnel Directory.
 *
 *   GET /admin/users
 *
 * Admin-panel read view of the `users` table with facets (all / risk /
 * dormant / MFA / invites). Admin CRUD lifecycle lives on the existing
 * /api/v2/admin/users/* endpoints; this Blade view does not duplicate them.
 */
final class PersonnelController extends Controller
{
    public function __construct(
        protected PheocScope $scope,
        protected EnumTranslator $enum,
    ) {
    }

    /** Rows per page for the directory. */
    public const PAGE_SIZE = 25;

    public function index(Request $request): View
    {
        $scope = $request->user() ? $this->scope->forUser($request->user()) : [
            'country_code' => config('country.code'), 'is_super' => true, 'label' => config('country.legacy_code') . ' · National',
        ];
        $country = (string) ($scope['country_code'] ?? config('country.code'));
        $facet   = $request->query('facet', 'all'); // all|risk|dormant|mfa|invites|suspended
        $q       = trim((string) $request->query('q', ''));
        $role    = (string) $request->query('role', '');

        $paginator = $this->fetchUsers($country, $facet, $q, $role, $request);
        $stats = $this->stats($country);

        $roleOptions = $this->roleOptions($country);

        return view('admin.personnel.index', [
            'scope'       => $scope,
            'facet'       => $facet,
            'q'           => $q,
            'role'        => $role,
            'rows'        => $paginator->items(),
            'paginator'   => $paginator,
            'stats'       => $stats,
            'roleOptions' => $roleOptions,
        ]);
    }

    protected function fetchUsers(string $country, string $facet, string $q, string $role, Request $request)
    {
        $query = DB::table('users')
            ->where('country_code', $country)
            ;

        if ($q !== '') {
            $needle = '%' . addcslashes($q, '%_\\') . '%';
            $query->where(function ($w) use ($needle) {
                $w->where('full_name', 'like', $needle)
                  ->orWhere('email', 'like', $needle)
                  ->orWhere('phone', 'like', $needle)
                  ->orWhere('username', 'like', $needle);
            });
        }
        if ($role !== '') {
            $query->where('role_key', $role);
        }

        switch ($facet) {
            case 'risk':
                $query->where('risk_score', '>=', 50);
                break;
            case 'dormant':
                $query->where('is_active', 1)
                      ->where(function ($w) {
                          $w->whereNull('last_login_at')
                            ->orWhere('last_login_at', '<', now()->subDays(14));
                      });
                break;
            case 'mfa':
                $query->whereIn('role_key', ['NATIONAL_ADMIN','PHEOC_OFFICER','DISTRICT_SUPERVISOR','POE_ADMIN'])
                      ->where('is_active', 1)
                      ->whereNull('two_factor_confirmed_at');
                break;
            case 'invites':
                $query->whereNull('last_login_at')->whereNull('invitation_accepted_at');
                break;
            case 'suspended':
                $query->where('is_active', 0);
                break;
            default: break;
        }

        $paginator = $query
            ->orderByDesc('is_active')
            ->orderByDesc('risk_score')
            ->orderBy('full_name')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        $paginator->getCollection()->transform(fn ($r) => [
            'id'            => (int) $r->id,
            'full_name'     => (string) ($r->full_name ?? '—'),
            'email'         => (string) ($r->email ?? ''),
            'phone'         => (string) ($r->phone ?? ''),
            'role_key'      => (string) ($r->role_key ?? ''),
            'role_label'    => $this->enum->roleKey((string) ($r->role_key ?? '')),
            'is_active'     => (bool) $r->is_active,
            'last_login'    => $r->last_login_at,
            'last_login_rel'=> $r->last_login_at ? Carbon::parse((string) $r->last_login_at)->diffForHumans() : 'Never',
            'two_factor'    => $r->two_factor_confirmed_at !== null,
            'risk_score'    => (int) ($r->risk_score ?? 0),
            'invited'       => $r->invitation_accepted_at === null && $r->last_login_at === null,
            'suspended'     => ! (bool) $r->is_active,
        ]);

        return $paginator;
    }

    protected function stats(string $country): array
    {
        try {
            $base = DB::table('users')->where('country_code', $country);
            $active   = (clone $base)->where('is_active', 1)->count();
            $suspended= (clone $base)->where('is_active', 0)->count();
            $dormant  = (clone $base)->where('is_active', 1)
                ->where(function ($w) { $w->whereNull('last_login_at')->orWhere('last_login_at','<',now()->subDays(14)); })
                ->count();
            $admins   = (clone $base)->whereIn('role_key', ['NATIONAL_ADMIN','PHEOC_OFFICER','DISTRICT_SUPERVISOR','POE_ADMIN'])->count();
            $adminsMfa= (clone $base)->whereIn('role_key', ['NATIONAL_ADMIN','PHEOC_OFFICER','DISTRICT_SUPERVISOR','POE_ADMIN'])->whereNotNull('two_factor_confirmed_at')->count();
            $flagged  = (clone $base)->where('risk_score', '>=', 50)->count();

            return [
                'active'     => $active,
                'suspended'  => $suspended,
                'dormant'    => $dormant,
                'admins'     => $admins,
                'admins_mfa' => $adminsMfa,
                'mfa_pct'    => $admins > 0 ? round($adminsMfa / $admins * 100) : 0,
                'flagged'    => $flagged,
            ];
        } catch (\Throwable) {
            return ['active'=>0,'suspended'=>0,'dormant'=>0,'admins'=>0,'admins_mfa'=>0,'mfa_pct'=>0,'flagged'=>0];
        }
    }

    protected function roleOptions(string $country): array
    {
        try {
            return DB::table('users')
                ->selectRaw('role_key, COUNT(*) as n')
                ->where('country_code', $country)
                
                ->whereNotNull('role_key')
                ->groupBy('role_key')
                ->orderByDesc('n')
                ->get()
                ->map(fn ($r) => ['code' => (string) $r->role_key, 'label' => $this->enum->roleKey((string) $r->role_key), 'count' => (int) $r->n])
                ->all();
        } catch (\Throwable) { return []; }
    }
}
