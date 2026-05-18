<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Workforce;

use App\Http\Controllers\Controller;
use App\Support\Scope\ScopeFilter;
use App\Support\Workforce\CoachManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin · Workforce · Assignments (user_assignments).
 *
 * Maps users to country / province / district / PoE jurisdictions. The active
 * scope of every authenticated request is computed from these rows by
 * PheocScope. ScopeFilter keeps non-super viewers in their lane.
 */
final class AssignmentsController extends Controller
{
    /** Default tenant country (full name; matches ref_poes.country_code). */
    private static function defaultCountry(): string
    {
        return (string) (config('country.legacy_code') ?: 'Uganda');
    }

    public function index(Request $r)
    {
        return view('admin.workforce.assignments.index', [
            'coach' => CoachManifest::forView('assignments'),
        ]);
    }

    public function data(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $tab   = strtolower((string) $r->query('status', 'active'));
            $userId = (int) $r->query('user_id', 0);
            $province = trim((string) $r->query('province_code', ''));
            $district = trim((string) $r->query('district_code', ''));
            $poe      = trim((string) $r->query('poe_code', ''));
            $q        = trim((string) $r->query('q', ''));

            $base = function () use ($scope) {
                return ScopeFilter::applyToUserAssignments(DB::table('user_assignments'), $scope);
            };

            $query = $base();
            $this->applyTab($query, $tab);
            if ($userId > 0)      { $query->where('user_id',       $userId); }
            if ($province !== '') { $query->where('province_code', $province); }
            if ($district !== '') { $query->where('district_code', $district); }
            if ($poe !== '')      { $query->where('poe_code',      $poe); }

            if ($q !== '') {
                $like = '%' . $q . '%';
                $query->whereExists(function ($w) use ($like) {
                    $w->select(DB::raw(1))->from('users')
                      ->whereColumn('users.id', 'user_assignments.user_id')
                      ->where(function ($ww) use ($like) {
                          $ww->where('users.full_name','like',$like)
                             ->orWhere('users.username','like',$like)
                             ->orWhere('users.email','like',$like);
                      });
                });
            }

            $rows = $query
                ->leftJoin('users', 'users.id', '=', 'user_assignments.user_id')
                ->select([
                    'user_assignments.*',
                    'users.full_name','users.username','users.email','users.role_key','users.account_type',
                ])
                ->orderByDesc('user_assignments.is_primary')
                ->orderByDesc('user_assignments.id')
                ->limit(500)
                ->get();

            $tabs = [
                'active'   => (clone $base())->where('is_active', 1)->whereNull('ends_at')->count(),
                'ended'    => (clone $base())->where(function ($w) { $w->where('is_active', 0)->orWhereNotNull('ends_at'); })->count(),
                'all'      => (clone $base())->count(),
            ];

            return $this->ok([
                'rows'  => $rows->map(fn ($r) => $this->castRow($r))->all(),
                'total' => $rows->count(),
            ], 'Assignments.', ['tabs' => $tabs]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    public function show(Request $r, int $id): JsonResponse
    {
        try {
            $row = DB::table('user_assignments as a')
                ->leftJoin('users','users.id','=','a.user_id')
                ->where('a.id', $id)
                ->select(['a.*','users.full_name','users.username','users.email','users.role_key','users.account_type'])
                ->first();
            if (! $row) return $this->err(404, 'Assignment not found.');
            return $this->ok($this->castRow($row), 'Assignment retrieved.');
        } catch (Throwable $e) { return $this->serverError($e, 'show'); }
    }

    public function meta(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $provQ = DB::table('ref_provinces')->whereNull('deleted_at');
            $provQ = ScopeFilter::applyToProvinces($provQ, $scope);
            $provinces = $provQ->orderBy('name')->pluck('name')->all();

            $distQ = DB::table('ref_districts')->whereNull('deleted_at');
            $distQ = ScopeFilter::applyToDistricts($distQ, $scope);
            $districts = $distQ->select('name','province_id')->orderBy('name')->get()->map(fn ($d) => [
                'name' => (string) $d->name, 'province_id' => (int) $d->province_id,
            ])->all();

            $poeQ = DB::table('ref_poes')->where('country_code', self::defaultCountry())->whereNull('deleted_at');
            $poeQ = ScopeFilter::applyToPoes($poeQ, $scope);
            $poes = $poeQ->orderBy('display_order')->orderBy('poe_name')
                ->get(['poe_code','poe_name','admin_level_1','district'])
                ->map(fn ($p) => [
                    'poe_code' => (string) $p->poe_code,
                    'poe_name' => (string) $p->poe_name,
                    'province' => (string) $p->admin_level_1,
                    'district' => (string) $p->district,
                ])->all();

            // Users in scope (for dropdown). Limit to active accounts.
            $usersQ = DB::table('users')->where('is_active', 1)->whereNull('suspended_at');
            $usersQ = ScopeFilter::applyToUsers($usersQ, $scope, 'users');
            $users = $usersQ->orderBy('full_name')->limit(500)
                ->get(['id','full_name','username','email','role_key','account_type']);

            return $this->ok([
                'country'   => self::defaultCountry(),
                'provinces' => $provinces,
                'districts' => $districts,
                'poes'      => $poes,
                'users'     => $users,
            ], 'Meta.');
        } catch (Throwable $e) { return $this->serverError($e, 'meta'); }
    }

    public function store(Request $r): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        $payload = $this->validatePayload($r->all(), false);
        if ($payload instanceof JsonResponse) return $payload;

        try {
            // Scope guard: writer must be able to see the target jurisdiction.
            $scope = ScopeFilter::fromRequest($r);
            if (! $this->canWrite($scope, $payload)) {
                return $this->err(403, 'Target jurisdiction is outside your scope.');
            }

            // user must exist
            if (! DB::table('users')->where('id', $payload['user_id'])->exists()) {
                return $this->err(422, 'Target user does not exist.');
            }

            // If is_primary=1, demote any existing primary for the same user.
            if (! empty($payload['is_primary'])) {
                DB::table('user_assignments')->where('user_id', $payload['user_id'])->update(['is_primary' => 0]);
            }

            $now = Carbon::now();
            $payload['created_at'] = $now;
            $payload['updated_at'] = $now;
            $payload['starts_at']  = $payload['starts_at'] ?? $now;

            $id = DB::table('user_assignments')->insertGetId($payload);
            $this->audit($admin, (int) $payload['user_id'], 'ASSIGN_CREATE', null, $payload, $r);

            $row = DB::table('user_assignments as a')
                ->leftJoin('users','users.id','=','a.user_id')->where('a.id', $id)
                ->select(['a.*','users.full_name','users.username','users.email','users.role_key','users.account_type'])
                ->first();
            return $this->ok($this->castRow($row), 'Assignment created.');
        } catch (Throwable $e) { return $this->serverError($e, 'store'); }
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $before = DB::table('user_assignments')->where('id', $id)->first();
            if (! $before) return $this->err(404, 'Assignment not found.');

            $payload = $this->validatePayload($r->all(), true);
            if ($payload instanceof JsonResponse) return $payload;

            $effective = array_merge((array) $before, $payload);
            $scope = ScopeFilter::fromRequest($r);
            if (! $this->canWrite($scope, $effective)) {
                return $this->err(403, 'Target jurisdiction is outside your scope.');
            }

            if (! empty($payload['is_primary'])) {
                DB::table('user_assignments')->where('user_id', $before->user_id)->where('id', '!=', $id)->update(['is_primary' => 0]);
            }

            $payload['updated_at'] = Carbon::now();
            DB::table('user_assignments')->where('id', $id)->update($payload);
            $this->audit($admin, (int) $before->user_id, 'ASSIGN_UPDATE', (array) $before, $payload, $r);

            $row = DB::table('user_assignments as a')
                ->leftJoin('users','users.id','=','a.user_id')->where('a.id', $id)
                ->select(['a.*','users.full_name','users.username','users.email','users.role_key','users.account_type'])
                ->first();
            return $this->ok($this->castRow($row), 'Assignment updated.');
        } catch (Throwable $e) { return $this->serverError($e, 'update'); }
    }

    /** Soft end — mark inactive with ends_at = now. */
    public function destroy(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $row = DB::table('user_assignments')->where('id', $id)->first();
            if (! $row) return $this->err(404, 'Assignment not found.');
            $now = Carbon::now();
            DB::table('user_assignments')->where('id', $id)->update([
                'is_active'  => 0,
                'ends_at'    => $now,
                'is_primary' => 0,
                'updated_at' => $now,
            ]);
            $this->audit($admin, (int) $row->user_id, 'ASSIGN_END', (array) $row, ['ends_at' => $now], $r);
            return $this->ok(['id' => $id, 'ended' => true], 'Assignment ended.');
        } catch (Throwable $e) { return $this->serverError($e, 'destroy'); }
    }

    public function restore(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $row = DB::table('user_assignments')->where('id', $id)->first();
            if (! $row) return $this->err(404, 'Assignment not found.');
            DB::table('user_assignments')->where('id', $id)->update([
                'is_active'  => 1,
                'ends_at'    => null,
                'updated_at' => Carbon::now(),
            ]);
            $this->audit($admin, (int) $row->user_id, 'ASSIGN_REOPEN', null, ['ends_at' => null], $r);
            return $this->ok(['id' => $id, 'reopened' => true], 'Assignment reopened.');
        } catch (Throwable $e) { return $this->serverError($e, 'restore'); }
    }

    /* ─────────── helpers ─────────── */

    private function applyTab($q, string $tab): void
    {
        switch ($tab) {
            case 'active': $q->where('user_assignments.is_active', 1)->whereNull('user_assignments.ends_at'); break;
            case 'ended':  $q->where(function ($w) { $w->where('user_assignments.is_active', 0)->orWhereNotNull('user_assignments.ends_at'); }); break;
            case 'all':
            default:       break;
        }
    }

    private function canWrite(array $scope, array $payload): bool
    {
        if (ScopeFilter::isSuper($scope)) return true;
        $level = ScopeFilter::level($scope);
        if ($level === 'PHEOC') {
            $allowed = $scope['provinces'] ?? [];
            return in_array($payload['province_code'] ?? null, $allowed, true);
        }
        if ($level === 'DISTRICT') {
            $allowed = $scope['districts'] ?? [];
            return in_array($payload['district_code'] ?? null, $allowed, true);
        }
        if ($level === 'POE') {
            $allowed = $scope['poes'] ?? [];
            return in_array($payload['poe_code'] ?? null, $allowed, true);
        }
        return false;
    }

    private function validatePayload(array $data, bool $partial): array|JsonResponse
    {
        if (! $partial) {
            foreach (['user_id','country_code'] as $f) {
                if (! isset($data[$f]) || trim((string) $data[$f]) === '') {
                    return $this->err(422, "Field '{$f}' is required.");
                }
            }
        }
        if (isset($data['user_id']) && (int) $data['user_id'] <= 0) {
            return $this->err(422, 'Invalid user_id.');
        }

        $payload = [];
        foreach (['country_code','province_code','pheoc_code','district_code','poe_code'] as $f) {
            if (array_key_exists($f, $data)) {
                $payload[$f] = ($data[$f] === '' || $data[$f] === null) ? null : (string) $data[$f];
            }
        }
        if (array_key_exists('user_id', $data))   { $payload['user_id']   = (int) $data['user_id']; }
        if (array_key_exists('is_primary', $data)){ $payload['is_primary']= (int) (bool) $data['is_primary']; }
        if (array_key_exists('is_active', $data)) { $payload['is_active'] = (int) (bool) $data['is_active']; }
        if (array_key_exists('starts_at', $data) && trim((string) $data['starts_at']) !== '') {
            $payload['starts_at'] = Carbon::parse($data['starts_at']);
        }
        if (array_key_exists('ends_at', $data)) {
            $payload['ends_at'] = ($data['ends_at'] === '' || $data['ends_at'] === null)
                ? null : Carbon::parse($data['ends_at']);
        }

        // pheoc_code mirrors province_code if not set explicitly.
        if (! array_key_exists('pheoc_code', $payload) && array_key_exists('province_code', $payload)) {
            $payload['pheoc_code'] = $payload['province_code'];
        }

        // Sensible defaults on create.
        if (! $partial) {
            $payload['is_active']  = $payload['is_active']  ?? 1;
            $payload['is_primary'] = $payload['is_primary'] ?? 0;
        }
        return $payload;
    }

    private function audit(int $admin, int $target, string $action, ?array $before, array $after, Request $r): void
    {
        try {
            DB::table('user_audit_log')->insert([
                'actor_user_id'  => $admin ?: null,
                'target_user_id' => $target,
                'action'         => $action,
                'before_json'    => $before ? json_encode($before) : null,
                'after_json'     => $after  ? json_encode($after)  : null,
                'ip'             => $r->ip(),
                'user_agent'     => substr((string) $r->userAgent(), 0, 500),
                'created_at'     => Carbon::now(),
            ]);
        } catch (Throwable $e) { Log::warning('[Workforce\\Assignments][audit] '.$e->getMessage()); }
    }

    private function castRow(object $r): array
    {
        return [
            'id'            => (int) $r->id,
            'user_id'       => (int) $r->user_id,
            'full_name'     => isset($r->full_name)    ? (string) $r->full_name    : null,
            'username'      => isset($r->username)     ? (string) $r->username     : null,
            'email'         => isset($r->email)        ? (string) $r->email        : null,
            'role_key'      => isset($r->role_key)     ? (string) $r->role_key     : null,
            'account_type'  => isset($r->account_type) ? (string) $r->account_type : null,
            'country_code'  => $r->country_code,
            'province_code' => $r->province_code,
            'pheoc_code'    => $r->pheoc_code,
            'district_code' => $r->district_code,
            'poe_code'      => $r->poe_code,
            'is_primary'    => (bool) $r->is_primary,
            'is_active'     => (bool) $r->is_active,
            'starts_at'     => $r->starts_at,
            'ends_at'       => $r->ends_at,
            'created_at'    => $r->created_at,
            'updated_at'    => $r->updated_at,
        ];
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if(!empty($meta)){$b['meta']=$meta;} return response()->json($b); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Workforce\\Assignments][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
