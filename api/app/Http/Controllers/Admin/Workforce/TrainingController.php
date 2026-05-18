<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Workforce;

use App\Http\Controllers\Controller;
use App\Support\Scope\ScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin · Workforce · Training (user_training_records).
 *
 * Records of who trained on what, and when refreshers are due. Status auto-
 * resolved on read: VALID / EXPIRING (within 60 days) / EXPIRED. Manual
 * REVOKED is preserved when set explicitly.
 */
final class TrainingController extends Controller
{
    private const DOMAINS = ['IHR','IDSR','RTSL','EBS','PORT_HEALTH','LAB','RCCE','OTHER'];
    private const STATUSES = ['VALID','EXPIRING','EXPIRED','REVOKED'];

    public function index(Request $r)
    {
        return view('admin.workforce.training.index');
    }

    public function data(Request $r): JsonResponse
    {
        try {
            $scope    = ScopeFilter::fromRequest($r);
            $tab      = strtolower((string) $r->query('status', 'valid'));
            $domain   = trim((string) $r->query('domain', ''));
            $userId   = (int) $r->query('user_id', 0);
            $q        = trim((string) $r->query('q', ''));

            $base = function () use ($scope) {
                return ScopeFilter::applyToTrainings(DB::table('user_training_records'), $scope, 'user_training_records')
                    ->whereNull('user_training_records.deleted_at');
            };

            $query = $base();
            $now = Carbon::now()->toDateString();
            $soon = Carbon::now()->addDays(60)->toDateString();

            switch ($tab) {
                case 'valid':
                    $query->where('user_training_records.status', '!=', 'REVOKED')
                          ->where(function ($w) use ($now, $soon) {
                              $w->whereNull('user_training_records.expires_on')
                                ->orWhere('user_training_records.expires_on', '>', $soon);
                          });
                    break;
                case 'expiring':
                    $query->where('user_training_records.status', '!=', 'REVOKED')
                          ->whereNotNull('user_training_records.expires_on')
                          ->whereBetween('user_training_records.expires_on', [$now, $soon]);
                    break;
                case 'expired':
                    $query->where('user_training_records.status', '!=', 'REVOKED')
                          ->whereNotNull('user_training_records.expires_on')
                          ->where('user_training_records.expires_on', '<', $now);
                    break;
                case 'revoked':
                    $query->where('user_training_records.status', 'REVOKED');
                    break;
                case 'all':
                default: break;
            }
            if ($domain !== '') { $query->where('competency_domain', $domain); }
            if ($userId > 0)    { $query->where('user_id', $userId); }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $query->where(function ($w) use ($like) {
                    $w->where('training_title','like',$like)
                      ->orWhere('training_code','like',$like)
                      ->orWhere('provider','like',$like)
                      ->orWhere('certificate_no','like',$like);
                });
            }

            $rows = $query
                ->leftJoin('users', 'users.id', '=', 'user_training_records.user_id')
                ->select([
                    'user_training_records.*',
                    'users.full_name','users.username','users.email','users.role_key',
                ])
                ->orderByDesc('user_training_records.completed_on')
                ->limit(500)
                ->get();

            $tabs = [
                'valid'    => $this->countTab($scope, 'valid'),
                'expiring' => $this->countTab($scope, 'expiring'),
                'expired'  => $this->countTab($scope, 'expired'),
                'revoked'  => $this->countTab($scope, 'revoked'),
                'all'      => (clone $base())->count(),
            ];

            return $this->ok([
                'rows'  => $rows->map(fn ($r) => $this->castRow($r))->all(),
                'total' => $rows->count(),
            ], 'Training records.', ['tabs' => $tabs]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    public function show(Request $r, int $id): JsonResponse
    {
        try {
            $row = DB::table('user_training_records as t')
                ->leftJoin('users','users.id','=','t.user_id')
                ->where('t.id', $id)->whereNull('t.deleted_at')
                ->select(['t.*','users.full_name','users.username','users.email','users.role_key'])
                ->first();
            if (! $row) return $this->err(404, 'Training record not found.');
            return $this->ok($this->castRow($row), 'Training retrieved.');
        } catch (Throwable $e) { return $this->serverError($e, 'show'); }
    }

    public function meta(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $usersQ = DB::table('users')->where('is_active', 1)->whereNull('suspended_at');
            $usersQ = ScopeFilter::applyToUsers($usersQ, $scope, 'users');
            $users = $usersQ->orderBy('full_name')->limit(500)->get(['id','full_name','username','email','role_key']);
            return $this->ok([
                'domains'  => self::DOMAINS,
                'statuses' => self::STATUSES,
                'users'    => $users,
            ], 'Meta.');
        } catch (Throwable $e) { return $this->serverError($e, 'meta'); }
    }

    public function store(Request $r): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        $payload = $this->validatePayload($r->all(), false);
        if ($payload instanceof JsonResponse) return $payload;

        try {
            if (! DB::table('users')->where('id', $payload['user_id'])->exists()) {
                return $this->err(422, 'Target user does not exist.');
            }
            $now = Carbon::now();
            $payload['recorded_by_user_id'] = $admin ?: null;
            $payload['updated_by_user_id']  = $admin ?: null;
            $payload['created_at'] = $now;
            $payload['updated_at'] = $now;

            $id = DB::table('user_training_records')->insertGetId($payload);

            $row = DB::table('user_training_records as t')
                ->leftJoin('users','users.id','=','t.user_id')->where('t.id', $id)
                ->select(['t.*','users.full_name','users.username','users.email','users.role_key'])->first();
            return $this->ok($this->castRow($row), 'Training recorded.');
        } catch (Throwable $e) { return $this->serverError($e, 'store'); }
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $before = DB::table('user_training_records')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $before) return $this->err(404, 'Training record not found.');

            $payload = $this->validatePayload($r->all(), true);
            if ($payload instanceof JsonResponse) return $payload;

            $payload['updated_by_user_id'] = $admin ?: null;
            $payload['updated_at']         = Carbon::now();

            DB::table('user_training_records')->where('id', $id)->update($payload);

            $row = DB::table('user_training_records as t')
                ->leftJoin('users','users.id','=','t.user_id')->where('t.id', $id)
                ->select(['t.*','users.full_name','users.username','users.email','users.role_key'])->first();
            return $this->ok($this->castRow($row), 'Training updated.');
        } catch (Throwable $e) { return $this->serverError($e, 'update'); }
    }

    /** Soft-delete (revoke if you want it visible; here we hide it). */
    public function destroy(Request $r, int $id): JsonResponse
    {
        try {
            $row = DB::table('user_training_records')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $row) return $this->err(404, 'Training record not found.');
            DB::table('user_training_records')->where('id', $id)->update([
                'deleted_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
            return $this->ok(['id' => $id, 'deleted' => true], 'Training removed.');
        } catch (Throwable $e) { return $this->serverError($e, 'destroy'); }
    }

    /* ─────────── helpers ─────────── */

    private function countTab(array $scope, string $tab): int
    {
        $q = ScopeFilter::applyToTrainings(DB::table('user_training_records'), $scope, 'user_training_records')
            ->whereNull('user_training_records.deleted_at');
        $now = Carbon::now()->toDateString();
        $soon = Carbon::now()->addDays(60)->toDateString();
        switch ($tab) {
            case 'valid':
                return $q->where('status', '!=', 'REVOKED')
                    ->where(function ($w) use ($soon) {
                        $w->whereNull('expires_on')->orWhere('expires_on', '>', $soon);
                    })->count();
            case 'expiring':
                return $q->where('status', '!=', 'REVOKED')
                    ->whereNotNull('expires_on')
                    ->whereBetween('expires_on', [$now, $soon])->count();
            case 'expired':
                return $q->where('status', '!=', 'REVOKED')
                    ->whereNotNull('expires_on')
                    ->where('expires_on', '<', $now)->count();
            case 'revoked':
                return $q->where('status', 'REVOKED')->count();
        }
        return 0;
    }

    private function validatePayload(array $data, bool $partial): array|JsonResponse
    {
        $required = ['user_id','training_code','training_title','competency_domain','completed_on'];
        if (! $partial) {
            foreach ($required as $f) {
                if (! isset($data[$f]) || trim((string) $data[$f]) === '') {
                    return $this->err(422, "Field '{$f}' is required.");
                }
            }
        }
        if (isset($data['competency_domain']) && ! in_array($data['competency_domain'], self::DOMAINS, true)) {
            return $this->err(422, 'Invalid competency_domain.', ['allowed' => self::DOMAINS]);
        }
        if (isset($data['status']) && ! in_array($data['status'], self::STATUSES, true)) {
            return $this->err(422, 'Invalid status.', ['allowed' => self::STATUSES]);
        }
        if (isset($data['score']) && $data['score'] !== '' && $data['score'] !== null) {
            $score = (int) $data['score'];
            if ($score < 0 || $score > 100) return $this->err(422, 'Score must be 0–100.');
        }

        $payload = [];
        foreach (['training_code','training_title','competency_domain','provider','certificate_no','evidence_url','status','notes'] as $f) {
            if (array_key_exists($f, $data)) {
                $payload[$f] = ($data[$f] === '' || $data[$f] === null) ? null : trim((string) $data[$f]);
            }
        }
        if (array_key_exists('user_id', $data))      { $payload['user_id'] = (int) $data['user_id']; }
        if (array_key_exists('completed_on', $data) && trim((string) $data['completed_on']) !== '') {
            $payload['completed_on'] = Carbon::parse($data['completed_on'])->toDateString();
        }
        if (array_key_exists('expires_on', $data)) {
            $payload['expires_on'] = ($data['expires_on'] === '' || $data['expires_on'] === null)
                ? null : Carbon::parse($data['expires_on'])->toDateString();
        }
        if (array_key_exists('score', $data)) {
            $payload['score'] = ($data['score'] === '' || $data['score'] === null) ? null : (int) $data['score'];
        }
        if (! $partial) {
            $payload['status']  = $payload['status']  ?? 'VALID';
        }
        return $payload;
    }

    private function castRow(object $r): array
    {
        $now = Carbon::now()->startOfDay();
        $expires = $r->expires_on ? Carbon::parse($r->expires_on) : null;
        $derived = $r->status;
        if ($derived !== 'REVOKED') {
            if ($expires === null) {
                $derived = 'VALID';
            } elseif ($expires->lt($now)) {
                $derived = 'EXPIRED';
            } elseif ($expires->lte($now->copy()->addDays(60))) {
                $derived = 'EXPIRING';
            } else {
                $derived = 'VALID';
            }
        }
        $daysToExpiry = $expires ? $now->diffInDays($expires, false) : null;

        return [
            'id'                => (int) $r->id,
            'user_id'           => (int) $r->user_id,
            'full_name'         => isset($r->full_name) ? (string) $r->full_name : null,
            'username'          => isset($r->username)  ? (string) $r->username  : null,
            'email'             => isset($r->email)     ? (string) $r->email     : null,
            'role_key'          => isset($r->role_key)  ? (string) $r->role_key  : null,
            'training_code'     => (string) $r->training_code,
            'training_title'    => (string) $r->training_title,
            'competency_domain' => (string) $r->competency_domain,
            'provider'          => $r->provider,
            'completed_on'      => $r->completed_on,
            'expires_on'        => $r->expires_on,
            'days_to_expiry'    => $daysToExpiry,
            'certificate_no'    => $r->certificate_no,
            'evidence_url'      => $r->evidence_url,
            'score'             => $r->score !== null ? (int) $r->score : null,
            'status'            => $derived,
            'status_raw'        => (string) $r->status,
            'notes'             => $r->notes,
            'created_at'        => $r->created_at,
            'updated_at'        => $r->updated_at,
        ];
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if(!empty($meta)){$b['meta']=$meta;} return response()->json($b); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Workforce\\Training][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
