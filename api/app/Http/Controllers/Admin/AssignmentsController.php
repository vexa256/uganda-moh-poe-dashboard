<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PheocScope;
use App\Services\SsotRegistry;
use App\Support\EnumTranslator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin · Assignments & Contact Roster (sidebar #12 · Phase B).
 *
 *   GET /admin/assignments
 *
 * Tabs: Jurisdiction · Escalation ladder · Contact roster · Geography.
 * Reads user_assignments, poe_notification_contacts, SsotRegistry
 * (countries, POEs, districts).
 */
final class AssignmentsController extends Controller
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

        return view('admin.assignments.index', [
            'scope'          => $scope,
            'jurisdictions'  => $this->jurisdictions($country),
            'ladder'         => $this->escalationLadder($country),
            'contacts'       => $this->contactRoster($country),
            'geography'      => $this->geography($country),
        ]);
    }

    protected function jurisdictions(string $country): array
    {
        try {
            return DB::table('user_assignments as ua')
                ->leftJoin('users as u', 'u.id', '=', 'ua.user_id')
                ->where('ua.country_code', $country)
                ->where('ua.is_active', 1)
                ->orderByDesc('ua.is_primary')
                ->orderBy('u.full_name')
                ->limit(100)
                ->get(['ua.*', 'u.full_name', 'u.email', 'u.role_key'])
                ->map(fn ($r) => [
                    'id'            => (int) $r->id,
                    'user_id'       => (int) $r->user_id,
                    'full_name'     => (string) ($r->full_name ?? '—'),
                    'email'         => (string) ($r->email ?? ''),
                    'role_key'      => (string) ($r->role_key ?? ''),
                    'role_label'    => $this->enum->roleKey((string) ($r->role_key ?? '')),
                    'province'      => (string) ($r->province_code ?? ''),
                    'district'      => (string) ($r->district_code ?? ''),
                    'poe'           => (string) ($r->poe_code ?? ''),
                    'pheoc'         => (string) ($r->pheoc_code ?? ''),
                    'is_primary'    => (bool) $r->is_primary,
                    'starts_at'     => $r->starts_at,
                    'ends_at'       => $r->ends_at,
                ])
                ->all();
        } catch (\Throwable) { return []; }
    }

    protected function escalationLadder(string $country): array
    {
        try {
            return DB::table('poe_notification_contacts')
                ->where('country_code', $country)
                ->whereNull('deleted_at')
                ->where('is_active', 1)
                ->orderBy('level')
                ->orderBy('priority_order')
                ->limit(120)
                ->get()
                ->map(fn ($r) => [
                    'id'          => (int) $r->id,
                    'level'       => (string) $r->level,
                    'priority'    => (int) ($r->priority_order ?? 0),
                    'full_name'   => (string) $r->full_name,
                    'position'    => (string) ($r->position ?? ''),
                    'organisation'=> (string) ($r->organisation ?? ''),
                    'phone'       => (string) ($r->phone ?? ''),
                    'email'       => (string) ($r->email ?? ''),
                    'district'    => (string) ($r->district_code ?? ''),
                    'poe'         => (string) ($r->poe_code ?? ''),
                    'channel'     => (string) $r->preferred_channel,
                    'flags'       => array_filter([
                        $r->receives_critical ? 'Critical' : null,
                        $r->receives_high ? 'High' : null,
                        $r->receives_breach_alerts ? 'Breach' : null,
                        $r->receives_followup_reminders ? 'Follow-ups' : null,
                        $r->receives_daily_report ? 'Daily' : null,
                        $r->receives_weekly_report ? 'Weekly' : null,
                    ]),
                ])
                ->groupBy('level')
                ->all();
        } catch (\Throwable) { return []; }
    }

    protected function contactRoster(string $country): array
    {
        try {
            return DB::table('poe_notification_contacts')
                ->where('country_code', $country)
                ->whereNull('deleted_at')
                ->orderBy('level')
                ->orderBy('district_code')
                ->orderBy('priority_order')
                ->limit(200)
                ->get()
                ->map(fn ($r) => [
                    'level'       => (string) $r->level,
                    'full_name'   => (string) $r->full_name,
                    'position'    => (string) ($r->position ?? ''),
                    'email'       => (string) ($r->email ?? ''),
                    'phone'       => (string) ($r->phone ?? ''),
                    'district'    => (string) ($r->district_code ?? ''),
                    'poe'         => (string) ($r->poe_code ?? ''),
                    'active'      => (bool) $r->is_active,
                ])
                ->all();
        } catch (\Throwable) { return []; }
    }

    protected function geography(string $country): array
    {
        try {
            $poes = SsotRegistry::poes();
            $pheoc = SsotRegistry::pheocDistricts();
            return [
                'country_code'    => $country,
                'poes_count'      => is_array($poes) ? count($poes) : 0,
                'districts_count' => is_array($pheoc) ? count($pheoc) : 0,
                'poes'            => is_array($poes) ? array_slice($poes, 0, 40) : [],
                'districts'       => is_array($pheoc) ? array_slice($pheoc, 0, 40) : [],
            ];
        } catch (\Throwable) {
            return ['country_code' => $country, 'poes_count' => 0, 'districts_count' => 0, 'poes' => [], 'districts' => []];
        }
    }
}
