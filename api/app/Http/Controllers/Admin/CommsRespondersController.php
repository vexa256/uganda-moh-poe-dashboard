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
 * Admin · External Responders (sidebar #8 · Phase B).
 *   GET /admin/comms/responders
 * Tabs: Registry · Info requests · Portal config (info).
 */
final class CommsRespondersController extends Controller
{
    public function __construct(
        protected PheocScope $scope,
        protected EnumTranslator $enum,
    ) {
    }

    public function index(Request $request): View
    {
        $scope = $request->user()
            ? $this->scope->forUser($request->user())
            : ['country_code' => config('country.code'), 'is_super' => true, 'label' => config('country.legacy_code') . ' · National (preview)'];
        $country = (string) ($scope['country_code'] ?? config('country.code'));

        return view('admin.comms.responders', [
            'scope'         => $scope,
            'registry'      => $this->registry($country),
            'infoRequests'  => $this->infoRequests($country),
            'stats'         => $this->stats($country),
        ]);
    }

    protected function registry(string $country): array
    {
        try {
            return DB::table('external_responders')
                ->where('country_code', $country)
                ->whereNull('deleted_at')
                ->orderBy('responder_type')
                ->orderBy('name')
                ->limit(100)
                ->get()
                ->map(fn ($r) => [
                    'id'         => (int) $r->id,
                    'name'       => (string) $r->name,
                    'type'       => (string) $r->responder_type,
                    'type_label' => $this->enum->responderType((string) $r->responder_type),
                    'org'        => (string) ($r->organisation ?? ''),
                    'position'   => (string) ($r->position ?? ''),
                    'email'      => (string) ($r->email ?? ''),
                    'phone'      => (string) ($r->phone ?? ''),
                    'district'   => (string) ($r->district_code ?? ''),
                    'active'     => (bool) $r->is_active,
                    'notes'      => (string) ($r->notes ?? ''),
                ])
                ->all();
        } catch (\Throwable) { return []; }
    }

    protected function infoRequests(string $country): array
    {
        try {
            return DB::table('responder_info_requests as r')
                ->leftJoin('external_responders as er', 'er.id', '=', 'r.responder_id')
                ->leftJoin('alerts as a', 'a.id', '=', 'r.alert_id')
                ->where(function ($q) use ($country) {
                    $q->where('er.country_code', $country)->orWhere('a.country_code', $country);
                })
                ->orderByDesc('r.created_at')
                ->limit(50)
                ->get([
                    'r.id', 'r.responder_id', 'r.alert_id', 'r.request_subject',
                    'r.status', 'r.created_at', 'r.responded_at', 'r.expires_at',
                    'er.name as responder_name', 'er.responder_type',
                    'a.alert_code', 'a.risk_level',
                ])
                ->map(fn ($r) => [
                    'id'             => (int) $r->id,
                    'responder_id'   => (int) ($r->responder_id ?? 0),
                    'responder_name' => (string) ($r->responder_name ?? '—'),
                    'responder_type' => $this->enum->responderType((string) ($r->responder_type ?? '')),
                    'alert_id'       => $r->alert_id,
                    'alert_code'     => (string) ($r->alert_code ?? ''),
                    'risk_level'     => (string) ($r->risk_level ?? ''),
                    'subject'        => (string) ($r->request_subject ?? ''),
                    'status'         => (string) $r->status,
                    'created_rel'    => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
                    'responded_rel'  => $r->responded_at ? Carbon::parse((string) $r->responded_at)->diffForHumans() : null,
                    'expires_rel'    => $r->expires_at ? Carbon::parse((string) $r->expires_at)->diffForHumans() : null,
                ])
                ->all();
        } catch (\Throwable) { return []; }
    }

    protected function stats(string $country): array
    {
        try {
            $r = DB::table('external_responders')->where('country_code', $country)->whereNull('deleted_at');
            $ir = DB::table('responder_info_requests as r')
                ->leftJoin('external_responders as er', 'er.id', '=', 'r.responder_id')
                ->where('er.country_code', $country);
            return [
                'total_responders' => (clone $r)->count(),
                'active'           => (clone $r)->where('is_active', 1)->count(),
                'hospitals'        => (clone $r)->where('responder_type', 'HOSPITAL')->count(),
                'labs'             => (clone $r)->where('responder_type', 'LAB')->count(),
                'ir_open'          => (clone $ir)->whereIn('r.status', ['SENT'])->count(),
                'ir_received'      => (clone $ir)->where('r.status', 'RECEIVED')->count(),
            ];
        } catch (\Throwable) { return []; }
    }
}
