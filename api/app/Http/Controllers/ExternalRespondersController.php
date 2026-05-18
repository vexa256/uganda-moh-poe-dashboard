<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ExternalRespondersController
 * ─────────────────────────────────────────────────────────────────────────
 * CRUD for the registry of external responders — hospitals, laboratories,
 * partner agencies, cross-border authorities that the POE Sentinel can
 * email with information requests tied to a case.
 *
 * Endpoint map
 *   GET    /external-responders                 paginated list + filter
 *   POST   /external-responders                 create
 *   GET    /external-responders/{id}
 *   PATCH  /external-responders/{id}
 *   DELETE /external-responders/{id}            soft delete
 *   GET    /external-responders/stats           aggregate counts by type / country
 *
 * Supported responder_type values
 *   HOSPITAL · LABORATORY · DISTRICT_HEALTH_OFFICE · PHEOC · WHO_COUNTRY_OFFICE
 *   BORDER_AUTHORITY · CLINIC · NGO_PARTNER · MINISTRY_HQ · OTHER
 */
final class ExternalRespondersController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        try {
            $q = DB::table('external_responders')->whereNull('deleted_at');
            if ($cc = $r->query('country_code'))   $q->where('country_code', $cc);
            if ($type = $r->query('responder_type')) $q->where('responder_type', $type);
            if ($dc = $r->query('district_code'))  $q->where('district_code', $dc);
            if ($s = $r->query('search')) {
                $q->where(function ($x) use ($s) {
                    $x->where('name', 'like', "%$s%")
                      ->orWhere('email', 'like', "%$s%")
                      ->orWhere('organisation', 'like', "%$s%");
                });
            }
            $rows = $q->orderBy('name')->limit((int) $r->query('limit', 200))->get();
            return $this->ok(['responders' => $rows, 'count' => $rows->count()]);
        } catch (Throwable $e) {
            return $this->fail($e, 'index');
        }
    }

    public function show(int $id): JsonResponse
    {
        try {
            $row = DB::table('external_responders')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $row) return $this->err('Responder not found', 404);

            $requests = DB::table('responder_info_requests')
                ->where('responder_id', $id)
                ->orderByDesc('created_at')->limit(50)->get();

            return $this->ok(['responder' => $row, 'info_requests' => $requests]);
        } catch (Throwable $e) {
            return $this->fail($e, 'show');
        }
    }

    public function store(Request $r): JsonResponse
    {
        try {
            $data = $r->validate([
                'responder_type' => 'required|in:HOSPITAL,LAB,EMS,LAW_ENFORCEMENT,PARTNER_AGENCY,OTHER',
                'name'           => 'required|string|max:160',
                'organisation'   => 'nullable|string|max:160',
                'position'       => 'nullable|string|max:120',
                'email'          => 'required|email|max:160',
                'phone'          => 'nullable|string|max:40',
                'country_code'   => 'required|string|max:10',
                'district_code'  => 'nullable|string|max:30',
                'notes'          => 'nullable|string|max:500',
                'is_active'      => 'nullable|boolean',
            ]);
            $actor = (int) $r->header('X-User-Id', 0);
            $id = DB::table('external_responders')->insertGetId(array_merge($data, [
                'is_active'       => $data['is_active'] ?? 1,
                'created_by_user_id' => $actor ?: 0,
                'created_at'      => now(), 'updated_at' => now(),
            ]));
            return $this->ok(['id' => $id], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return $this->fail($e, 'store');
        }
    }

    public function update(Request $r, int $id): JsonResponse
    {
        try {
            $row = DB::table('external_responders')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $row) return $this->err('Responder not found', 404);
            $data = $r->validate([
                'responder_type' => 'nullable|in:HOSPITAL,LAB,EMS,LAW_ENFORCEMENT,PARTNER_AGENCY,OTHER',
                'name'           => 'nullable|string|max:160',
                'organisation'   => 'nullable|string|max:160',
                'position'       => 'nullable|string|max:120',
                'email'          => 'nullable|email|max:160',
                'phone'          => 'nullable|string|max:40',
                'country_code'   => 'nullable|string|max:10',
                'district_code'  => 'nullable|string|max:30',
                'notes'          => 'nullable|string|max:500',
                'is_active'      => 'nullable|boolean',
            ]);
            DB::table('external_responders')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
            return $this->ok(['updated' => true]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return $this->fail($e, 'update');
        }
    }

    public function destroy(int $id): JsonResponse
    {
        try {
            DB::table('external_responders')->where('id', $id)->update([
                'deleted_at' => now(), 'updated_at' => now(),
            ]);
            return $this->ok(['deleted' => true]);
        } catch (Throwable $e) {
            return $this->fail($e, 'destroy');
        }
    }

    public function stats(Request $r): JsonResponse
    {
        try {
            $cc = $r->query('country_code');
            $q = DB::table('external_responders')->whereNull('deleted_at');
            if ($cc) $q->where('country_code', $cc);

            $byType = (clone $q)->selectRaw('responder_type, COUNT(*) AS n')->groupBy('responder_type')->get();
            $byCountry = DB::table('external_responders')->whereNull('deleted_at')
                ->selectRaw('country_code, COUNT(*) AS n')->groupBy('country_code')->get();
            $active = (clone $q)->where('is_active', 1)->count();
            $total  = (clone $q)->count();

            return $this->ok([
                'total' => $total, 'active' => $active,
                'by_type' => $byType, 'by_country' => $byCountry,
            ]);
        } catch (Throwable $e) {
            return $this->fail($e, 'stats');
        }
    }

    private function ok(array $d = [], int $c = 200): JsonResponse { return response()->json(['ok' => true, 'data' => $d], $c); }
    private function err(string $m, int $c = 400): JsonResponse { return response()->json(['ok' => false, 'error' => $m], $c); }
    private function fail(Throwable $e, string $ctx): JsonResponse {
        Log::error("[ExternalResponders::{$ctx}] " . $e->getMessage());
        return response()->json(['ok' => false, 'error' => $e->getMessage(), 'ctx' => $ctx], 500);
    }
}
