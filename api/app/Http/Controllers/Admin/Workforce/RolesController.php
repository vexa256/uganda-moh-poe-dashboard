<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Workforce;

use App\Http\Controllers\Controller;
use App\Support\Workforce\CoachManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin · Workforce · Roles.
 *
 * Read catalogue of role_registry plus a capability matrix derived from each
 * role's scope_level. Toggle is_active is the only mutation (NATIONAL_ADMIN
 * only) — role_keys themselves are foundational and not user-defined.
 */
final class RolesController extends Controller
{
    /** Capability grid — semantic, not enforced here. Matches RBAC §S9. */
    private const CAPABILITIES = [
        'reads_national', 'reads_province', 'reads_district', 'reads_poe',
        'writes_users',   'writes_geo',     'writes_alerts',  'writes_aggregated',
        'closes_alerts',  'submits_screenings', 'manages_roster', 'exports_data',
    ];

    private const GRID = [
        'NATIONAL_ADMIN' => ['reads_national','reads_province','reads_district','reads_poe',
                              'writes_users','writes_geo','writes_alerts','writes_aggregated',
                              'closes_alerts','submits_screenings','manages_roster','exports_data'],
        'PHEOC_OFFICER'  => ['reads_province','reads_district','reads_poe',
                              'writes_alerts','closes_alerts','manages_roster','exports_data'],
        'DISTRICT_SUPERVISOR' => ['reads_district','reads_poe','closes_alerts','exports_data'],
        'POE_ADMIN'      => ['reads_poe','manages_roster','submits_screenings'],
        'POE_OFFICER'    => ['reads_poe','submits_screenings'],
        'POE_DATA_OFFICER'=> ['reads_poe','submits_screenings','writes_aggregated'],
        'SCREENER'       => ['reads_poe','submits_screenings'],
        'OBSERVER'       => ['reads_poe'],
        'SERVICE'        => ['reads_national','writes_aggregated'],
    ];

    public function index(Request $r)
    {
        return view('admin.workforce.roles.index', [
            'coach' => CoachManifest::forView('roles'),
        ]);
    }

    public function data(Request $r): JsonResponse
    {
        try {
            $tab = strtolower((string) $r->query('status', 'active'));
            $q   = trim((string) $r->query('q', ''));

            $query = DB::table('role_registry');
            if ($tab === 'active')   { $query->where('is_active', 1); }
            if ($tab === 'inactive') { $query->where('is_active', 0); }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $query->where(function ($w) use ($like) {
                    $w->where('role_key', 'like', $like)
                      ->orWhere('display_name', 'like', $like)
                      ->orWhere('description',  'like', $like);
                });
            }

            $rows = $query->orderBy('display_name')->get();

            // Headcounts — distinct user_ids per role_key.
            $counts = DB::table('users')
                ->select('role_key', DB::raw('COUNT(*) as c'),
                    DB::raw('SUM(CASE WHEN is_active=1 AND suspended_at IS NULL THEN 1 ELSE 0 END) as c_active'))
                ->groupBy('role_key')->pluck('c', 'role_key');
            $countsActive = DB::table('users')
                ->select('role_key', DB::raw('SUM(CASE WHEN is_active=1 AND suspended_at IS NULL THEN 1 ELSE 0 END) as c'))
                ->groupBy('role_key')->pluck('c', 'role_key');

            $tabs = [
                'active'   => DB::table('role_registry')->where('is_active', 1)->count(),
                'inactive' => DB::table('role_registry')->where('is_active', 0)->count(),
                'all'      => DB::table('role_registry')->count(),
            ];

            return $this->ok([
                'rows'         => $rows->map(fn ($r) => $this->castRow($r, (int) ($counts[$r->role_key] ?? 0), (int) ($countsActive[$r->role_key] ?? 0)))->all(),
                'capabilities' => self::CAPABILITIES,
                'total'        => $rows->count(),
            ], 'Roles.', ['tabs' => $tabs]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    public function show(Request $r, string $key): JsonResponse
    {
        try {
            $row = DB::table('role_registry')->where('role_key', $key)->first();
            if (! $row) return $this->err(404, 'Role not found.');

            $count       = DB::table('users')->where('role_key', $key)->count();
            $countActive = DB::table('users')->where('role_key', $key)->where('is_active', 1)->whereNull('suspended_at')->count();

            $sample = DB::table('users')->where('role_key', $key)->orderByDesc('id')
                ->limit(20)->get(['id','full_name','username','email','is_active','suspended_at','last_login_at']);

            return $this->ok([
                'role'    => $this->castRow($row, $count, $countActive),
                'users'   => $sample,
            ], 'Role retrieved.');
        } catch (Throwable $e) { return $this->serverError($e, 'show'); }
    }

    public function update(Request $r, string $key): JsonResponse
    {
        try {
            $row = DB::table('role_registry')->where('role_key', $key)->first();
            if (! $row) return $this->err(404, 'Role not found.');

            $patch = [];
            if ($r->has('is_active'))    { $patch['is_active']    = (int) (bool) $r->input('is_active'); }
            if ($r->has('display_name')) { $patch['display_name'] = trim((string) $r->input('display_name')); }
            if ($r->has('description'))  { $patch['description']  = trim((string) $r->input('description')); }

            if (empty($patch)) return $this->err(422, 'Nothing to update.');

            DB::table('role_registry')->where('role_key', $key)->update($patch);
            $fresh = DB::table('role_registry')->where('role_key', $key)->first();
            $count = DB::table('users')->where('role_key', $key)->count();
            $countActive = DB::table('users')->where('role_key', $key)->where('is_active', 1)->whereNull('suspended_at')->count();
            return $this->ok($this->castRow($fresh, $count, $countActive), 'Role updated.');
        } catch (Throwable $e) { return $this->serverError($e, 'update'); }
    }

    private function castRow(object $r, int $count, int $countActive): array
    {
        $caps = self::GRID[$r->role_key] ?? [];
        $matrix = [];
        foreach (self::CAPABILITIES as $c) { $matrix[$c] = in_array($c, $caps, true); }
        return [
            'role_key'      => (string) $r->role_key,
            'display_name'  => (string) $r->display_name,
            'scope_level'   => (string) $r->scope_level,
            'description'   => (string) ($r->description ?? ''),
            'is_active'     => (bool) $r->is_active,
            'capabilities'  => $matrix,
            'capability_count' => count($caps),
            'users_total'   => $count,
            'users_active'  => $countActive,
            'created_at'    => $r->created_at,
        ];
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if(!empty($meta)){$b['meta']=$meta;} return response()->json($b); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Workforce\\Roles][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
