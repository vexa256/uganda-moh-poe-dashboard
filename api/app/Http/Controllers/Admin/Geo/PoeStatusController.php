<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Geo;

use App\Http\Controllers\Controller;
use App\Support\Scope\ScopeFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Admin · PoE · Open / Closed Status temporal log.
 *
 * Time-series log; the "current" status of a PoE is the latest event with
 * ended_at IS NULL. Opening a PoE that's already open is a no-op; opening
 * a PoE that's currently in another status closes that event (sets
 * ended_at) and inserts a new OPEN event.
 *
 * Auth + scope enforced by route middleware.
 */
final class PoeStatusController extends Controller
{
    /** Default tenant country (full name; matches ref_poes.country_code). */
    private static function defaultCountry(): string
    {
        return (string) (config('country.legacy_code') ?: 'Uganda');
    }
    private const STATUSES = ['OPEN', 'CLOSED', 'REDUCED_HOURS', 'EMERGENCY_CLOSED', 'MAINTENANCE'];

    public function index(Request $r)
    {
        return view('admin.geo.poe-status.index');
    }

    /**
     * Returns current status per visible PoE plus the recent event log.
     * Two responses bundled to keep the round-trip small.
     */
    public function data(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $country = (string) $r->query('country', self::defaultCountry());
            $tab     = strtolower((string) $r->query('status', 'all'));

            // PoEs visible to this user
            $poesQ = DB::table('ref_poes')->where('country_code', $country)->whereNull('deleted_at');
            $poesQ = ScopeFilter::applyToPoes($poesQ, $scope);
            $poes  = $poesQ->orderBy('display_order')->get(['poe_code', 'poe_name', 'admin_level_1', 'district', 'poe_type']);

            // Latest event per visible PoE
            $latest = DB::table('poe_status_events as e1')
                ->select('e1.*')
                ->whereIn('e1.poe_code', $poes->pluck('poe_code'))
                ->whereNull('e1.ended_at')
                ->orderByDesc('e1.started_at')
                ->get()
                ->keyBy('poe_code');

            $current = $poes->map(function ($p) use ($latest) {
                $e = $latest->get($p->poe_code);
                return [
                    'poe_code'  => (string) $p->poe_code,
                    'poe_name'  => (string) $p->poe_name,
                    'province'  => (string) $p->admin_level_1,
                    'district'  => (string) $p->district,
                    'poe_type'  => (string) $p->poe_type,
                    'status'    => $e ? (string) $e->status   : 'OPEN',
                    'reason'    => $e ? $e->reason            : null,
                    'since'     => $e ? $e->started_at        : null,
                    'event_id'  => $e ? (int) $e->id          : null,
                    'days_in_status' => $e && $e->started_at ? (int) Carbon::parse($e->started_at)->diffInDays(Carbon::now()) : null,
                ];
            })->values();

            if ($tab !== 'all') {
                $current = $current->filter(fn ($r) => strtoupper($r['status']) === strtoupper($tab))->values();
            }

            // Tab counts
            $tabCounts = [
                'all'              => $poes->count(),
                'open'             => 0, 'closed' => 0, 'reduced_hours' => 0,
                'emergency_closed' => 0, 'maintenance' => 0,
            ];
            foreach ($poes as $p) {
                $e = $latest->get($p->poe_code);
                $s = strtolower($e ? (string) $e->status : 'open');
                if (! isset($tabCounts[$s])) $tabCounts[$s] = 0;
                $tabCounts[$s]++;
            }

            // Recent log (last 30 events across visible PoEs)
            $logQ = DB::table('poe_status_events')
                ->whereIn('poe_code', $poes->pluck('poe_code'))
                ->orderByDesc('started_at')
                ->limit(30);
            $log = $logQ->get();

            return $this->ok([
                'current' => $current->all(),
                'log'     => $log->map(fn ($e) => $this->castEvent($e))->all(),
            ], 'Status snapshot.', ['tabs' => $tabCounts]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    public function meta(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $poesQ = DB::table('ref_poes')->where('country_code', self::defaultCountry())->whereNull('deleted_at');
            $poesQ = ScopeFilter::applyToPoes($poesQ, $scope);
            $poes  = $poesQ->orderBy('display_order')->get(['poe_code','poe_name','admin_level_1','district'])
                ->map(fn ($p) => [
                    'poe_code' => (string) $p->poe_code,
                    'poe_name' => (string) $p->poe_name,
                    'province' => (string) $p->admin_level_1,
                    'district' => (string) $p->district,
                ])->all();
            return $this->ok(['statuses' => self::STATUSES, 'poes' => $poes], 'Meta.');
        } catch (Throwable $e) { return $this->serverError($e, 'meta'); }
    }

    public function show(Request $r, int $id): JsonResponse
    {
        try {
            $row = DB::table('poe_status_events')->where('id', $id)->first();
            if (! $row) return $this->err(404, 'Event not found.');
            return $this->ok($this->castEvent($row), 'Event retrieved.');
        } catch (Throwable $e) { return $this->serverError($e, 'show'); }
    }

    /**
     * POST a new status event. If the PoE has an active (no-ended_at) event,
     * close it first (set ended_at = now) and start a new one. Idempotent on
     * the trivial case of opening a PoE already OPEN.
     */
    public function store(Request $r): JsonResponse
    {
        $admin = (int) auth()->id();
        $data = $r->all();

        $poeCode = trim((string) ($data['poe_code'] ?? ''));
        $status  = strtoupper((string) ($data['status'] ?? ''));
        if ($poeCode === '' || ! in_array($status, self::STATUSES, true)) {
            return $this->err(422, 'poe_code and a valid status are required.', ['allowed' => self::STATUSES]);
        }
        $reason   = (string) ($data['reason'] ?? '');
        $started  = ! empty($data['started_at']) ? Carbon::parse((string) $data['started_at']) : Carbon::now();
        $hoursJson = isset($data['hours_json']) ? json_encode($data['hours_json']) : null;

        try {
            // Close any current active event for this PoE.
            $current = DB::table('poe_status_events')
                ->where('poe_code', $poeCode)
                ->whereNull('ended_at')
                ->orderByDesc('started_at')
                ->first();

            if ($current) {
                if (strtoupper((string) $current->status) === $status) {
                    return $this->err(409, "PoE already in status {$status} since {$current->started_at}.");
                }
                DB::table('poe_status_events')->where('id', $current->id)->update([
                    'ended_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            }

            $now = Carbon::now();
            $id = DB::table('poe_status_events')->insertGetId([
                'country_code'       => self::defaultCountry(),
                'poe_code'           => $poeCode,
                'status'             => $status,
                'reason'             => $reason ?: null,
                'started_at'         => $started,
                'ended_at'           => null,
                'hours_json'         => $hoursJson,
                'created_by_user_id' => $admin,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $fresh = DB::table('poe_status_events')->where('id', $id)->first();
            return $this->ok($this->castEvent($fresh), 'Status updated.');
        } catch (Throwable $e) { return $this->serverError($e, 'store'); }
    }

    /** PATCH — edit reason / hours / ended_at. Cannot change poe_code or status (immutable history). */
    public function update(Request $r, int $id): JsonResponse
    {
        $admin = (int) auth()->id();
        try {
            $row = DB::table('poe_status_events')->where('id', $id)->first();
            if (! $row) return $this->err(404, 'Event not found.');
            $patch = ['updated_at' => Carbon::now()];
            $data = $r->all();
            if (array_key_exists('reason', $data))     $patch['reason']     = $data['reason'] === '' ? null : (string) $data['reason'];
            if (array_key_exists('hours_json', $data)) $patch['hours_json'] = $data['hours_json'] === null ? null : json_encode($data['hours_json']);
            if (array_key_exists('ended_at', $data))   $patch['ended_at']   = $data['ended_at'] ? Carbon::parse((string) $data['ended_at']) : null;
            DB::table('poe_status_events')->where('id', $id)->update($patch);
            $fresh = DB::table('poe_status_events')->where('id', $id)->first();
            return $this->ok($this->castEvent($fresh), 'Event updated.');
        } catch (Throwable $e) { return $this->serverError($e, 'update'); }
    }

    private function castEvent(object $r): array
    {
        return [
            'id'                 => (int) $r->id,
            'country_code'       => (string) $r->country_code,
            'poe_code'           => (string) $r->poe_code,
            'status'             => (string) $r->status,
            'reason'             => $r->reason,
            'started_at'         => $r->started_at,
            'ended_at'           => $r->ended_at,
            'hours_json'         => $r->hours_json ? json_decode((string) $r->hours_json, true) : null,
            'created_by_user_id' => $r->created_by_user_id !== null ? (int) $r->created_by_user_id : null,
            'created_at'         => $r->created_at,
            'updated_at'         => $r->updated_at,
        ];
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    { $b = ['success'=>true,'message'=>$message,'data'=>$data]; if(!empty($meta)){$b['meta']=$meta;} return response()->json($b); }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    { return response()->json(['success'=>false,'message'=>$message,'error'=>$detail], $status); }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    { Log::error("[Admin\\Geo\\PoeStatus][{$ctx}] " . $e->getMessage(), ['file'=>$e->getFile().':'.$e->getLine()]); return response()->json(['success'=>false,'message'=>"Server error: {$ctx}"], 500); }
}
