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
 * ════════════════════════════════════════════════════════════════════════════
 * Admin · PoE · Notification Contacts roster (poe_notification_contacts).
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Web admin counterpart to App\Http\Controllers\PoeContactsController (the
 * frozen mobile surface at /poe-contacts). The mobile controller is NOT
 * touched by this file — they read and write the same physical table, and
 * this class mirrors the mobile contract column-for-column so a row created
 * by one path is fully consumable by the other:
 *
 *   • escalates_to_contact_id is persisted (was silently dropped before).
 *   • At least one of email / phone is required, email format validated.
 *   • Self-reference and cycles in the escalation ladder are rejected.
 *   • level / preferred_channel enums match mobile.
 *
 * Bundle / cache: contacts are operational data and are NOT serialized into
 * the geo bundle (window.POE_MAIN). No /poes/bundle invalidation on writes.
 *
 * Smart admin-only endpoints:
 *   GET    /admin/poe/contacts/data
 *   GET    /admin/poe/contacts/meta
 *   GET    /admin/poe/contacts/{id}/chain   — walk the escalation ladder
 *   POST   /admin/poe/contacts/{id}/restore — reactivate a deactivated row
 *
 * Auth + scope enforced by route middleware (web · auth · scope · role).
 * ════════════════════════════════════════════════════════════════════════════
 */
final class PoeContactsController extends Controller
{
    /** Default tenant country (full name; matches ref_poes.country_code). */
    private static function defaultCountry(): string
    {
        return (string) (config('country.legacy_code') ?: 'Uganda');
    }
    private const LEVELS          = ['POE', 'DISTRICT', 'PHEOC', 'NATIONAL', 'WHO'];
    private const CHANNELS        = ['EMAIL', 'SMS', 'BOTH'];
    private const MAX_CHAIN_DEPTH = 5;

    /** Boolean / receives_* flag columns — single source of truth. */
    private const BOOL_FLAGS = [
        'is_active',
        'receives_critical', 'receives_high', 'receives_medium', 'receives_low',
        'receives_tier1', 'receives_tier2', 'receives_breach_alerts',
        'receives_followup_reminders', 'receives_daily_report', 'receives_weekly_report',
    ];

    /** String / nullable scalar columns mapped from request → payload. */
    private const STRING_COLUMNS = [
        'country_code', 'district_code', 'poe_code', 'level',
        'full_name', 'position', 'organisation',
        'phone', 'alternate_phone', 'email', 'alternate_email',
        'preferred_channel', 'notes',
    ];

    /* ════════════════════════════════════════════════════════════════════
       VIEW SHELL · the page itself loads via the JSON endpoints below
       ════════════════════════════════════════════════════════════════════ */

    public function index(Request $r)
    {
        return view('admin.geo.poe-contacts.index');
    }

    /* ════════════════════════════════════════════════════════════════════
       READ · roster grid with tabs / filters / search
       ════════════════════════════════════════════════════════════════════ */

    public function data(Request $r): JsonResponse
    {
        try {
            $scope     = ScopeFilter::fromRequest($r);
            $country   = (string) $r->query('country', self::defaultCountry());
            $level     = trim((string) $r->query('level', ''));
            $district  = trim((string) $r->query('district_code', ''));
            $poe       = trim((string) $r->query('poe_code', ''));
            $channel   = trim((string) $r->query('preferred_channel', ''));
            $tab       = strtolower((string) $r->query('status', 'active'));
            $q         = trim((string) $r->query('q', ''));

            $base = function () use ($country, $scope) {
                $b = DB::table('poe_notification_contacts')
                    ->where('country_code', $country)
                    ->whereNull('deleted_at');
                return ScopeFilter::applyToPoeContacts($b, $scope);
            };

            $query = $base();
            if ($tab === 'active')   { $query->where('is_active', 1); }
            if ($tab === 'inactive') { $query->where('is_active', 0); }
            if ($level !== '' && in_array($level, self::LEVELS, true)) {
                $query->where('level', $level);
            }
            if ($district !== '') { $query->where('district_code', $district); }
            if ($poe !== '')      { $query->where('poe_code', $poe); }
            if ($channel !== '' && in_array($channel, self::CHANNELS, true)) {
                $query->where('preferred_channel', $channel);
            }
            if ($q !== '') {
                $like = '%' . $q . '%';
                $query->where(function ($w) use ($like) {
                    $w->where('full_name', 'like', $like)
                      ->orWhere('email', 'like', $like)
                      ->orWhere('phone', 'like', $like)
                      ->orWhere('organisation', 'like', $like)
                      ->orWhere('position', 'like', $like)
                      ->orWhere('poe_code', 'like', $like);
                });
            }

            $rows = $query
                ->orderByRaw("FIELD(level,'POE','DISTRICT','PHEOC','NATIONAL','WHO')")
                ->orderBy('priority_order')
                ->orderBy('id')
                ->get();

            // Reverse-edge map: who escalates INTO each id.
            $ids = $rows->pluck('id')->all();
            $reverseEdges = $ids
                ? DB::table('poe_notification_contacts')
                    ->whereIn('escalates_to_contact_id', $ids)
                    ->whereNull('deleted_at')
                    ->select('id', 'full_name', 'level', 'escalates_to_contact_id')
                    ->get()
                    ->groupBy('escalates_to_contact_id')
                : collect();

            $rowsOut = $rows->map(function ($row) use ($reverseEdges) {
                $arr = $this->castRow($row);
                $arr['has_chain']         = !empty($row->escalates_to_contact_id);
                $arr['escalated_in_by']   = isset($reverseEdges[$row->id])
                    ? $reverseEdges[$row->id]->map(fn ($e) => [
                        'id'        => (int) $e->id,
                        'full_name' => (string) $e->full_name,
                        'level'     => (string) $e->level,
                    ])->values()->all()
                    : [];
                return $arr;
            })->all();

            $countActive   = (clone $base())->where('is_active', 1)->count();
            $countInactive = (clone $base())->where('is_active', 0)->count();

            // Roster posture ribbon — counts by family/severity coverage.
            $coverage = [
                'critical'           => (clone $base())->where('is_active', 1)->where('receives_critical', 1)->count(),
                'high'               => (clone $base())->where('is_active', 1)->where('receives_high', 1)->count(),
                'tier1'              => (clone $base())->where('is_active', 1)->where('receives_tier1', 1)->count(),
                'breach'             => (clone $base())->where('is_active', 1)->where('receives_breach_alerts', 1)->count(),
                'followup'           => (clone $base())->where('is_active', 1)->where('receives_followup_reminders', 1)->count(),
                'daily_report'       => (clone $base())->where('is_active', 1)->where('receives_daily_report', 1)->count(),
                'weekly_report'      => (clone $base())->where('is_active', 1)->where('receives_weekly_report', 1)->count(),
                'with_chain'         => (clone $base())->where('is_active', 1)->whereNotNull('escalates_to_contact_id')->count(),
                'reachable_email'    => (clone $base())->where('is_active', 1)->whereNotNull('email')->where('email', '!=', '')->count(),
                'reachable_sms'      => (clone $base())->where('is_active', 1)->whereNotNull('phone')->where('phone', '!=', '')->count(),
            ];

            return $this->ok([
                'rows'  => $rowsOut,
                'total' => count($rowsOut),
            ], 'Contacts.', [
                'tabs'     => ['active' => $countActive, 'inactive' => $countInactive, 'all' => $countActive + $countInactive],
                'coverage' => $coverage,
            ]);
        } catch (Throwable $e) { return $this->serverError($e, 'data'); }
    }

    public function show(Request $r, int $id): JsonResponse
    {
        try {
            $row = DB::table('poe_notification_contacts')
                ->where('id', $id)->whereNull('deleted_at')->first();
            if (! $row) { return $this->err(404, 'Contact not found.'); }
            return $this->ok($this->castRow($row), 'Contact retrieved.');
        } catch (Throwable $e) { return $this->serverError($e, 'show'); }
    }

    /* ════════════════════════════════════════════════════════════════════
       META · drop-down sources for the wizard + filter bar
       ════════════════════════════════════════════════════════════════════ */

    public function meta(Request $r): JsonResponse
    {
        try {
            $scope = ScopeFilter::fromRequest($r);
            $country = (string) $r->query('country', self::defaultCountry());

            $poesQ = DB::table('ref_poes')
                ->where('country_code', $country)
                ->whereNull('deleted_at');
            $poesQ = ScopeFilter::applyToPoes($poesQ, $scope);
            $poes  = $poesQ->orderBy('display_order')->orderBy('poe_name')
                ->get(['id', 'poe_code', 'poe_name', 'admin_level_1', 'district', 'is_active'])
                ->map(fn ($p) => [
                    'poe_code'  => (string) $p->poe_code,
                    'poe_name'  => (string) $p->poe_name,
                    'province'  => (string) $p->admin_level_1,
                    'district'  => (string) $p->district,
                    'is_active' => (bool) $p->is_active,
                ])->all();

            $districts = collect($poes)->pluck('district')->filter()->unique()->values()->all();
            $provinces = collect($poes)->pluck('province')->filter()->unique()->values()->all();

            // chainables: every active contact, grouped by poe_code, ordered by
            // ladder level so the wizard can offer "escalate to" candidates that
            // sit at the right level. We deliberately do not filter to only-
            // higher-level contacts here — DISTRICT may legitimately escalate
            // sideways to another DISTRICT in special-case rosters.
            $chainables = ScopeFilter::applyToPoeContacts(
                DB::table('poe_notification_contacts')
                    ->where('country_code', $country)
                    ->whereNull('deleted_at')
                    ->where('is_active', 1),
                $scope
            )
                ->orderByRaw("FIELD(level,'POE','DISTRICT','PHEOC','NATIONAL','WHO')")
                ->orderBy('priority_order')
                ->get(['id', 'poe_code', 'level', 'full_name', 'position', 'organisation'])
                ->map(fn ($c) => [
                    'id'           => (int) $c->id,
                    'poe_code'     => (string) $c->poe_code,
                    'level'        => (string) $c->level,
                    'full_name'    => (string) $c->full_name,
                    'position'     => $c->position,
                    'organisation' => $c->organisation,
                ])->values()->all();

            return $this->ok([
                'levels'     => self::LEVELS,
                'channels'   => self::CHANNELS,
                'poes'       => $poes,
                'districts'  => $districts,
                'provinces'  => $provinces,
                'chainables' => $chainables,
                'max_chain_depth' => self::MAX_CHAIN_DEPTH,
            ], 'Meta.');
        } catch (Throwable $e) { return $this->serverError($e, 'meta'); }
    }

    /* ════════════════════════════════════════════════════════════════════
       CHAIN · walk escalates_to_contact_id from a starting row
       ════════════════════════════════════════════════════════════════════
       Mirrors PoeContactsController::escalationChain (mobile-frozen) but
       starts from a single contact id instead of a (poe, level) tuple. */

    public function chain(Request $r, int $id): JsonResponse
    {
        try {
            $head = DB::table('poe_notification_contacts')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();
            if (! $head) { return $this->err(404, 'Contact not found.'); }

            $chain = [$this->castRow($head)];
            $cursor = $head->escalates_to_contact_id;
            $seen = [(int) $head->id => true];
            $depth = 0;

            while ($cursor && $depth < self::MAX_CHAIN_DEPTH) {
                $cid = (int) $cursor;
                if (isset($seen[$cid])) { break; }
                $next = DB::table('poe_notification_contacts')
                    ->where('id', $cid)
                    ->whereNull('deleted_at')
                    ->first();
                if (! $next) { break; }
                $chain[]   = $this->castRow($next);
                $seen[$cid] = true;
                $cursor    = $next->escalates_to_contact_id;
                $depth++;
            }

            return $this->ok([
                'head_id' => (int) $head->id,
                'chain'   => $chain,
                'depth'   => count($chain),
                'maxed'   => $cursor !== null && $depth >= self::MAX_CHAIN_DEPTH,
            ], 'Escalation chain resolved.');
        } catch (Throwable $e) { return $this->serverError($e, 'chain'); }
    }

    /* ════════════════════════════════════════════════════════════════════
       WRITE · create / update / deactivate / restore
       ════════════════════════════════════════════════════════════════════ */

    public function store(Request $r): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        $data  = $r->all();

        $payload = $this->validatePayload($data, false, null);
        if ($payload instanceof JsonResponse) { return $payload; }

        try {
            $now = Carbon::now();
            $payload['created_by_user_id'] = $admin;
            $payload['updated_by_user_id'] = $admin;
            $payload['created_at']         = $now;
            $payload['updated_at']         = $now;

            $id  = DB::table('poe_notification_contacts')->insertGetId($payload);
            $row = DB::table('poe_notification_contacts')->where('id', $id)->first();
            return $this->ok($this->castRow($row), 'Contact created.');
        } catch (Throwable $e) { return $this->serverError($e, 'store'); }
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $row = DB::table('poe_notification_contacts')
                ->where('id', $id)->whereNull('deleted_at')->first();
            if (! $row) { return $this->err(404, 'Contact not found.'); }

            $data = $r->all();
            $payload = $this->validatePayload($data, true, (int) $row->id);
            if ($payload instanceof JsonResponse) { return $payload; }

            $payload['updated_by_user_id'] = $admin;
            $payload['updated_at']         = Carbon::now();

            DB::table('poe_notification_contacts')->where('id', $id)->update($payload);
            $fresh = DB::table('poe_notification_contacts')->where('id', $id)->first();
            return $this->ok($this->castRow($fresh), 'Contact updated.');
        } catch (Throwable $e) { return $this->serverError($e, 'update'); }
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $row = DB::table('poe_notification_contacts')
                ->where('id', $id)->whereNull('deleted_at')->first();
            if (! $row) { return $this->err(404, 'Contact not found.'); }

            // Roster convention: deactivate (is_active=0). Soft-delete is
            // reserved for hard removal scenarios. Audit trail is preserved.
            DB::table('poe_notification_contacts')->where('id', $id)->update([
                'is_active'          => 0,
                'updated_by_user_id' => $admin,
                'updated_at'         => Carbon::now(),
            ]);

            // Re-route any inbound edges on this row to its own escalates_to.
            // Without this, deactivating a node leaves dangling edges that
            // would route alerts to a deactivated inbox.
            if ($row->escalates_to_contact_id !== null) {
                DB::table('poe_notification_contacts')
                    ->where('escalates_to_contact_id', $id)
                    ->whereNull('deleted_at')
                    ->update([
                        'escalates_to_contact_id' => $row->escalates_to_contact_id,
                        'updated_by_user_id'      => $admin,
                        'updated_at'              => Carbon::now(),
                    ]);
            } else {
                DB::table('poe_notification_contacts')
                    ->where('escalates_to_contact_id', $id)
                    ->whereNull('deleted_at')
                    ->update([
                        'escalates_to_contact_id' => null,
                        'updated_by_user_id'      => $admin,
                        'updated_at'              => Carbon::now(),
                    ]);
            }

            return $this->ok(['id' => $id, 'deactivated' => true], 'Contact deactivated.');
        } catch (Throwable $e) { return $this->serverError($e, 'destroy'); }
    }

    public function restore(Request $r, int $id): JsonResponse
    {
        $admin = (int) (auth()->id() ?? 0);
        try {
            $row = DB::table('poe_notification_contacts')
                ->where('id', $id)->whereNull('deleted_at')->first();
            if (! $row) { return $this->err(404, 'Contact not found.'); }
            if ((int) $row->is_active === 1) {
                return $this->err(409, 'Contact is already active.');
            }
            DB::table('poe_notification_contacts')->where('id', $id)->update([
                'is_active'          => 1,
                'updated_by_user_id' => $admin,
                'updated_at'         => Carbon::now(),
            ]);
            $fresh = DB::table('poe_notification_contacts')->where('id', $id)->first();
            return $this->ok($this->castRow($fresh), 'Contact reactivated.');
        } catch (Throwable $e) { return $this->serverError($e, 'restore'); }
    }

    /* ════════════════════════════════════════════════════════════════════
       VALIDATION · mirrors mobile-frozen rules byte-for-byte
       ════════════════════════════════════════════════════════════════════ */

    /**
     * Build a sanitised payload for insert/update. Returns JsonResponse on
     * any rule failure with field-level human messages.
     *
     * @param  array<string,mixed> $data    raw request body
     * @param  bool                $partial true = update (skip required-on-create)
     * @param  int|null            $rowId   row being updated (for cycle check)
     */
    private function validatePayload(array $data, bool $partial, ?int $rowId): array|JsonResponse
    {
        $required = ['country_code', 'district_code', 'poe_code', 'level', 'full_name'];

        if (! $partial) {
            foreach ($required as $f) {
                if (! isset($data[$f]) || trim((string) $data[$f]) === '') {
                    return $this->err(422, "Field '{$f}' is required.", ['field' => $f]);
                }
            }
        }

        if (isset($data['level']) && ! in_array($data['level'], self::LEVELS, true)) {
            return $this->err(422, 'Invalid level.', ['field' => 'level', 'allowed' => self::LEVELS]);
        }
        if (isset($data['preferred_channel']) && ! in_array($data['preferred_channel'], self::CHANNELS, true)) {
            return $this->err(422, 'Invalid preferred_channel.', ['field' => 'preferred_channel', 'allowed' => self::CHANNELS]);
        }

        // Normalise email/phone for the email-or-phone requirement.
        $email = isset($data['email']) ? trim((string) $data['email']) : null;
        $phone = isset($data['phone']) ? trim((string) $data['phone']) : null;

        if (! $partial) {
            if (($email ?? '') === '' && ($phone ?? '') === '') {
                return $this->err(422, 'Provide at least one of email or phone.', ['field' => 'email_or_phone']);
            }
        } else {
            // On partial update — if BOTH fields are present in the patch and BOTH
            // are empty strings, reject. Lets a caller clear one without clearing both.
            if (array_key_exists('email', $data) && array_key_exists('phone', $data)
                && ($email ?? '') === '' && ($phone ?? '') === '') {
                return $this->err(422, 'A contact must keep at least one of email or phone.', ['field' => 'email_or_phone']);
            }
        }

        if ($email !== null && $email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->err(422, 'Invalid email.', ['field' => 'email']);
        }
        if (isset($data['alternate_email']) && trim((string) $data['alternate_email']) !== ''
            && ! filter_var($data['alternate_email'], FILTER_VALIDATE_EMAIL)) {
            return $this->err(422, 'Invalid alternate email.', ['field' => 'alternate_email']);
        }

        // FK existence + cycle prevention for escalates_to_contact_id.
        if (array_key_exists('escalates_to_contact_id', $data)) {
            $raw = $data['escalates_to_contact_id'];
            $target = ($raw === '' || $raw === null) ? null : (int) $raw;

            if ($target !== null) {
                if ($rowId !== null && $target === $rowId) {
                    return $this->err(422, 'A contact cannot escalate to itself.', ['field' => 'escalates_to_contact_id']);
                }
                $exists = DB::table('poe_notification_contacts')
                    ->where('id', $target)->whereNull('deleted_at')->exists();
                if (! $exists) {
                    return $this->err(422, 'Escalation target not found.', ['field' => 'escalates_to_contact_id']);
                }
                if ($rowId !== null && $this->wouldCreateCycle($rowId, $target)) {
                    return $this->err(422, 'Escalation target would create a cycle.', ['field' => 'escalates_to_contact_id']);
                }
                if ($this->chainTooDeep($target)) {
                    return $this->err(422, 'Escalation chain would exceed depth limit.', [
                        'field' => 'escalates_to_contact_id',
                        'max'   => self::MAX_CHAIN_DEPTH,
                    ]);
                }
            }
        }

        // Project to a sanitised payload — only known columns persist.
        $payload = [];
        foreach (self::STRING_COLUMNS as $f) {
            if (array_key_exists($f, $data)) {
                $v = $data[$f];
                $payload[$f] = ($v === '' || $v === null) ? null : (string) $v;
            }
        }
        // Required NOT NULL columns must never be cast to null on create.
        if (! $partial) {
            foreach (['country_code', 'district_code', 'poe_code', 'level', 'full_name'] as $f) {
                $payload[$f] = (string) ($data[$f] ?? '');
            }
        }

        if (array_key_exists('priority_order', $data)) {
            $payload['priority_order'] = max(1, (int) $data['priority_order']);
        }
        if (array_key_exists('escalates_to_contact_id', $data)) {
            $raw = $data['escalates_to_contact_id'];
            $payload['escalates_to_contact_id'] = ($raw === '' || $raw === null) ? null : (int) $raw;
        }
        foreach (self::BOOL_FLAGS as $f) {
            if (array_key_exists($f, $data)) {
                $payload[$f] = (int) (bool) $data[$f];
            }
        }

        return $payload;
    }

    /**
     * Walk forward from $target; if we ever land on $rowId we have a cycle.
     */
    private function wouldCreateCycle(int $rowId, int $target): bool
    {
        $cursor = $target;
        $seen = [];
        $depth = 0;
        while ($cursor !== null && $depth <= self::MAX_CHAIN_DEPTH + 1) {
            if ($cursor === $rowId) { return true; }
            if (isset($seen[$cursor])) { return true; }
            $seen[$cursor] = true;
            $next = DB::table('poe_notification_contacts')
                ->where('id', $cursor)->whereNull('deleted_at')
                ->value('escalates_to_contact_id');
            $cursor = $next === null ? null : (int) $next;
            $depth++;
        }
        return false;
    }

    /**
     * Walking forward from $target, would the resulting chain exceed
     * MAX_CHAIN_DEPTH hops once a new node is prepended?
     */
    private function chainTooDeep(int $target): bool
    {
        $cursor = $target;
        $seen = [];
        $depth = 1; // counting the new node-to-be plus its target
        while ($cursor !== null && $depth <= self::MAX_CHAIN_DEPTH + 1) {
            if (isset($seen[$cursor])) { return false; }
            $seen[$cursor] = true;
            $next = DB::table('poe_notification_contacts')
                ->where('id', $cursor)->whereNull('deleted_at')
                ->value('escalates_to_contact_id');
            if ($next === null) { return $depth > self::MAX_CHAIN_DEPTH; }
            $cursor = (int) $next;
            $depth++;
        }
        return true;
    }

    /* ════════════════════════════════════════════════════════════════════
       CASTERS · response shape
       ════════════════════════════════════════════════════════════════════ */

    private function castRow(object $r): array
    {
        return [
            'id'                          => (int) $r->id,
            'country_code'                => (string) $r->country_code,
            'district_code'               => (string) $r->district_code,
            'poe_code'                    => (string) $r->poe_code,
            'level'                       => (string) $r->level,
            'full_name'                   => (string) $r->full_name,
            'position'                    => $r->position,
            'organisation'                => $r->organisation,
            'phone'                       => $r->phone,
            'alternate_phone'             => $r->alternate_phone,
            'email'                       => $r->email,
            'alternate_email'             => $r->alternate_email,
            'priority_order'              => (int) $r->priority_order,
            'escalates_to_contact_id'     => $r->escalates_to_contact_id === null ? null : (int) $r->escalates_to_contact_id,
            'is_active'                   => (bool) $r->is_active,
            'receives_critical'           => (bool) $r->receives_critical,
            'receives_high'               => (bool) $r->receives_high,
            'receives_medium'             => (bool) $r->receives_medium,
            'receives_low'                => (bool) $r->receives_low,
            'receives_tier1'              => (bool) $r->receives_tier1,
            'receives_tier2'              => (bool) $r->receives_tier2,
            'receives_breach_alerts'      => (bool) $r->receives_breach_alerts,
            'receives_followup_reminders' => (bool) $r->receives_followup_reminders,
            'receives_daily_report'       => (bool) $r->receives_daily_report,
            'receives_weekly_report'      => (bool) $r->receives_weekly_report,
            'preferred_channel'           => (string) $r->preferred_channel,
            'notes'                       => $r->notes,
            'last_notified_at'            => $r->last_notified_at,
            'created_at'                  => $r->created_at,
            'updated_at'                  => $r->updated_at,
        ];
    }

    /* ════════════════════════════════════════════════════════════════════
       ENVELOPE HELPERS
       ════════════════════════════════════════════════════════════════════ */

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    {
        $b = ['success' => true, 'message' => $message, 'data' => $data];
        if (!empty($meta)) { $b['meta'] = $meta; }
        return response()->json($b);
    }

    private function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error' => $detail], $status);
    }

    private function serverError(Throwable $e, string $ctx): JsonResponse
    {
        Log::error("[Admin\\Geo\\PoeContacts][{$ctx}] " . $e->getMessage(), [
            'exception' => get_class($e),
            'file'      => $e->getFile() . ':' . $e->getLine(),
        ]);
        return response()->json(['success' => false, 'message' => "Server error: {$ctx}"], 500);
    }
}
