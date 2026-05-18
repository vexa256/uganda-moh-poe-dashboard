<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AggregatedAudit;
use App\Services\CountryResolver;
use App\Services\PheocScope;
use App\Services\ReportEngine;
use App\Support\EnumTranslator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║  Admin · IDSR / Aggregated Reports controller                            ║
 * ║  Rebuild 2026-04-24 · 10-item sidebar compressed to 3 surfaces.          ║
 * ╠══════════════════════════════════════════════════════════════════════════╣
 * ║  STUDIO    — template library, 5-step builder wizard, columns editor,    ║
 * ║              versions, lifecycle (publish / retire / lock / delete).     ║
 * ║  INTEL     — submissions browser, rollups, late-reporters, CSV export.   ║
 * ║  SYNC      — UNSYNCED / FAILED submission queue with retry + diagnostics.║
 * ╠══════════════════════════════════════════════════════════════════════════╣
 * ║  CONTRACT: the mobile /aggregated* endpoints are UNTOUCHED. This file    ║
 * ║  owns only /admin/aggregated/* — dedicated JSON + Blade surfaces that    ║
 * ║  talk to the same tables via read-scoped queries and NATIONAL_ADMIN     ║
 * ║  writes. All writes mirror the mobile controller's validation rules.    ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class AggregatedController extends Controller
{
    public const PAGE_SIZE            = 25;
    public const VALID_DATA_TYPES     = ['INTEGER', 'DECIMAL', 'TEXT', 'BOOLEAN', 'DATE', 'PERCENT', 'SELECT'];
    public const VALID_AGG_FN         = ['SUM', 'AVG', 'MIN', 'MAX', 'COUNT', 'LATEST', 'NONE'];
    public const VALID_STATUS         = ['DRAFT', 'PUBLISHED', 'RETIRED', 'ARCHIVED'];
    public const VALID_FREQ           = ['DAILY', 'WEEKLY', 'MONTHLY', 'QUARTERLY', 'AD_HOC', 'EVENT'];
    public const VALID_SYNC_STATUSES  = ['SYNCED', 'UNSYNCED', 'FAILED'];

    public function __construct(
        protected PheocScope $scope,
        protected EnumTranslator $enum,
        protected CountryResolver $countries,
        protected ReportEngine $reports,
        protected AggregatedAudit $audit,
    ) {
    }

    /* ════════════════════════════════════════════════════════════════════
     * ·  STUDIO surface (templates + builder + columns + versions)
     * ════════════════════════════════════════════════════════════════════ */

    /** GET /admin/aggregated/studio — Blade shell. Data comes via /data. */
    public function studio(Request $request): View
    {
        $scope = $this->scopeFor($request);
        return view('admin.aggregated.studio', [
            'scope'      => $scope,
            'canWrite'   => $this->canWrite($request),
            'meta'       => $this->studioMeta(),
        ]);
    }

    /** GET /admin/aggregated/studio/data — paginated template list + column summary. */
    public function studioData(Request $request): JsonResponse
    {
        $scope   = $this->scopeFor($request);
        $country = (string) ($scope['country_code'] ?? config('country.code'));

        $q = DB::table('aggregated_templates')->whereNull('deleted_at');
        $this->applyCountryFilter($q, $scope);

        $status = strtoupper((string) $request->query('status', ''));
        if ($status && in_array($status, self::VALID_STATUS, true)) {
            $q->where('status', $status);
        }
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('template_name', 'like', "%{$search}%")
                  ->orWhere('template_code', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $rows = $q->orderByDesc('is_default')
            ->orderByDesc('is_active')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();

        $ids = $rows->pluck('id')->all();
        $counts = $ids
            ? DB::table('aggregated_template_columns')
                ->whereIn('template_id', $ids)
                ->whereNull('deleted_at')
                ->selectRaw('template_id, COUNT(*) AS total, SUM(is_enabled) AS enabled, SUM(is_required) AS required, SUM(is_core) AS core')
                ->groupBy('template_id')
                ->get()->keyBy('template_id')
            : collect();
        $subs = $ids
            ? DB::table('aggregated_submissions')
                ->whereIn('template_id', $ids)
                ->whereNull('deleted_at')
                ->selectRaw('template_id, COUNT(*) AS total, MAX(period_end) AS latest')
                ->groupBy('template_id')
                ->get()->keyBy('template_id')
            : collect();

        $templates = $rows->map(function ($r) use ($counts, $subs) {
            $c = $counts->get($r->id);
            $s = $subs->get($r->id);
            return [
                'id'              => (int) $r->id,
                'country_code'    => (string) $r->country_code,
                'template_code'   => (string) $r->template_code,
                'template_name'   => (string) $r->template_name,
                'description'     => (string) ($r->description ?? ''),
                'version'         => (int) ($r->version ?? 1),
                'status'          => (string) $r->status,
                'status_label'    => $this->enum->templateStatus((string) $r->status),
                'is_active'       => (bool) $r->is_active,
                'is_default'      => (bool) $r->is_default,
                'locked'          => (bool) $r->locked,
                'reporting_frequency' => (string) ($r->reporting_frequency ?? 'AD_HOC'),
                'icon'            => (string) ($r->icon ?? ''),
                'colour'          => (string) ($r->colour ?? '#10B981'),
                'columns_total'   => (int) ($c->total ?? 0),
                'columns_enabled' => (int) ($c->enabled ?? 0),
                'columns_required'=> (int) ($c->required ?? 0),
                'columns_core'    => (int) ($c->core ?? 0),
                'submissions_total' => (int) ($s->total ?? 0),
                'latest_period'   => $s && $s->latest ? Carbon::parse((string) $s->latest)->format('Y-m-d') : null,
                'latest_period_rel' => $s && $s->latest ? Carbon::parse((string) $s->latest)->diffForHumans() : '—',
                'published_at'    => $r->published_at,
                'published_rel'   => $r->published_at ? Carbon::parse((string) $r->published_at)->diffForHumans() : '—',
                'retired_at'      => $r->retired_at,
                'locked_at'       => $r->locked_at,
                'updated_at'      => $r->updated_at,
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'data'    => ['templates' => $templates, 'count' => count($templates)],
            'meta'    => [
                'country_code' => $country,
                'can_write'    => $this->canWrite($request),
                'scope_label'  => $scope['label'] ?? '—',
            ],
        ]);
    }

    /** GET /admin/aggregated/studio/template/{id} — full template + columns. */
    public function studioTemplate(Request $request, int $id): JsonResponse
    {
        $scope = $this->scopeFor($request);
        $t = DB::table('aggregated_templates')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $t) return $this->err(404, 'Template not found.');
        if (($scope['country_code'] ?? null) && $t->country_code !== $scope['country_code'] && ! ($scope['is_super'] ?? false)) {
            return $this->err(403, 'Template is out of scope.');
        }

        $columns = DB::table('aggregated_template_columns')
            ->where('template_id', $id)
            ->whereNull('deleted_at')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get()
            ->map(fn ($c) => $this->shapeColumn($c))
            ->values()->all();

        $historyBase = DB::table('aggregated_submissions')->where('template_id', $id)->whereNull('deleted_at');
        $submissions_count = (int) (clone $historyBase)->count();
        $submissions_versions = (clone $historyBase)
            ->selectRaw('template_version, COUNT(*) as cnt, MIN(period_end) as first_period, MAX(period_end) as last_period')
            ->groupBy('template_version')
            ->orderByDesc('template_version')
            ->get()
            ->map(fn ($r) => [
                'version'      => (int) ($r->template_version ?? 0),
                'count'        => (int) $r->cnt,
                'first_period' => $r->first_period,
                'last_period'  => $r->last_period,
            ])->all();

        $tpl = (array) $t;
        $tpl['status_label'] = $this->enum->templateStatus((string) $t->status);
        $tpl['is_active']    = (bool) $t->is_active;
        $tpl['is_default']   = (bool) $t->is_default;
        $tpl['locked']       = (bool) $t->locked;
        $tpl['metadata']     = $this->safeJson((string) ($t->metadata ?? ''));

        return response()->json([
            'success' => true,
            'data'    => [
                'template'  => $tpl,
                'columns'   => $columns,
                'stats'     => [
                    'submissions_count'   => $submissions_count,
                    'submissions_versions'=> $submissions_versions,
                ],
            ],
            'meta' => [
                'can_write' => $this->canWrite($request),
            ],
        ]);
    }

    /** POST /admin/aggregated/studio/template — 5-step wizard persistence. */
    public function studioCreateTemplate(Request $request): JsonResponse
    {
        $guard = $this->requireAdmin($request);
        if ($guard instanceof JsonResponse) return $guard;
        /** @var object $user */
        $user = $guard;

        $scope      = $this->scopeFor($request);
        $rawCountry = trim((string) $request->input('country_code', (string) ($scope['country_code'] ?? config('country.code'))));
        // Canonicalise against ref_countries — every new row lands in
        // the SSoT form ("Zambia"), regardless of what the client sent.
        $country    = $this->countries->canonicalOrRaw($rawCountry) ?? $rawCountry;
        $name       = trim((string) $request->input('template_name', ''));
        $code       = strtoupper(trim((string) $request->input('template_code', '')));
        if ($code === '' && $name !== '') {
            $code = strtoupper(Str::slug($name, '_'));
        }

        if ($country === '' || $name === '' || $code === '') {
            return $this->err(422, 'country_code, template_name, template_code are required.');
        }
        if (! preg_match('/^[A-Z0-9_]+$/', $code)) {
            return $this->err(422, 'template_code must be uppercase letters, digits, and underscores only.');
        }
        $freq = strtoupper((string) $request->input('reporting_frequency', 'WEEKLY'));
        if (! in_array($freq, self::VALID_FREQ, true)) {
            return $this->err(422, 'Invalid reporting_frequency.', ['valid' => self::VALID_FREQ]);
        }

        // Dedupe across alias forms of the same country — a template code
        // 'WEEKLY_VHF_V1' under 'ZM' is the same as under 'Zambia'.
        $dupCheck = DB::table('aggregated_templates')
            ->where('template_code', $code)
            ->whereNull('deleted_at');
        $this->applyCountryFilter($dupCheck, array_merge($scope, ['country_code' => $country]));
        if ($dupCheck->exists()) {
            return $this->err(409, 'Template with that code already exists for this country.');
        }

        $startFrom = strtoupper((string) $request->input('start_from', 'DEFAULT'));
        $customColumns = $request->input('columns', []);

        $now = now()->format('Y-m-d H:i:s');
        try {
            $tplId = 0;
            DB::transaction(function () use (&$tplId, $country, $name, $code, $freq, $request, $user, $now, $startFrom, $customColumns) {
                $tplId = (int) DB::table('aggregated_templates')->insertGetId([
                    'country_code'        => $country,
                    'template_name'       => mb_substr($name, 0, 120),
                    'template_code'       => $code,
                    'description'         => ($d = (string) $request->input('description', '')) !== '' ? mb_substr($d, 0, 500) : null,
                    'version'             => 1,
                    'is_active'           => 0,
                    'is_default'          => 0,
                    'locked'              => 0,
                    'status'              => 'DRAFT',
                    'reporting_frequency' => $freq,
                    'icon'                => ($i = (string) $request->input('icon', '')) !== '' ? mb_substr($i, 0, 40) : null,
                    'colour'              => ($c = (string) $request->input('colour', '')) !== '' ? mb_substr($c, 0, 16) : null,
                    'metadata'            => json_encode($request->input('metadata', (object) [])),
                    'created_by_user_id'  => $user->id,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);

                if ($startFrom === 'DEFAULT') {
                    $default = DB::table('aggregated_templates')
                        ->where('is_default', 1)
                        ->whereNull('deleted_at')
                        ->first();
                    if ($default) {
                        $defaultCols = DB::table('aggregated_template_columns')
                            ->where('template_id', $default->id)
                            ->whereNull('deleted_at')
                            ->orderBy('display_order')->get();
                        foreach ($defaultCols as $c) {
                            DB::table('aggregated_template_columns')->insert([
                                'template_id'         => $tplId,
                                'column_key'          => $c->column_key,
                                'column_label'        => $c->column_label,
                                'category'            => $c->category,
                                'data_type'           => $c->data_type,
                                'is_required'         => $c->is_required,
                                'is_enabled'          => $c->is_enabled,
                                'is_core'             => $c->is_core,
                                'default_value'       => $c->default_value,
                                'min_value'           => $c->min_value,
                                'max_value'           => $c->max_value,
                                'select_options'      => $c->select_options,
                                'validation_rules'    => $c->validation_rules,
                                'display_order'       => $c->display_order,
                                'placeholder'         => $c->placeholder,
                                'help_text'           => $c->help_text,
                                'dashboard_visible'   => $c->dashboard_visible,
                                'report_visible'      => $c->report_visible,
                                'aggregation_fn'      => $c->aggregation_fn,
                                'created_by_user_id'  => $user->id,
                                'created_at'          => $now,
                                'updated_at'          => $now,
                            ]);
                        }
                    }
                } elseif ($startFrom === 'CUSTOM' && is_array($customColumns)) {
                    $order = 0;
                    foreach ($customColumns as $col) {
                        $key = trim((string) ($col['column_key'] ?? ''));
                        $label = trim((string) ($col['column_label'] ?? ''));
                        $type  = strtoupper((string) ($col['data_type'] ?? 'INTEGER'));
                        $agg   = strtoupper((string) ($col['aggregation_fn'] ?? 'SUM'));
                        if ($key === '' || $label === '') continue;
                        if (! preg_match('/^[a-z][a-z0-9_]{1,58}$/', $key)) continue;
                        if (! in_array($type, self::VALID_DATA_TYPES, true)) continue;
                        if (! in_array($agg, self::VALID_AGG_FN, true)) continue;
                        DB::table('aggregated_template_columns')->insert([
                            'template_id'        => $tplId,
                            'column_key'         => $key,
                            'column_label'       => mb_substr($label, 0, 160),
                            'category'           => mb_substr((string) ($col['category'] ?? 'CUSTOM'), 0, 40),
                            'data_type'          => $type,
                            'is_required'        => (int) (bool) ($col['is_required'] ?? false),
                            'is_enabled'         => (int) (bool) ($col['is_enabled'] ?? true),
                            'is_core'            => 0,
                            'default_value'      => isset($col['default_value']) ? mb_substr((string) $col['default_value'], 0, 120) : null,
                            'min_value'          => $col['min_value'] ?? null,
                            'max_value'          => $col['max_value'] ?? null,
                            'select_options'     => isset($col['select_options']) ? json_encode($col['select_options']) : null,
                            'validation_rules'   => isset($col['validation_rules']) ? json_encode($col['validation_rules']) : null,
                            'display_order'      => $order++,
                            'placeholder'        => isset($col['placeholder']) ? mb_substr((string) $col['placeholder'], 0, 160) : null,
                            'help_text'          => isset($col['help_text']) ? mb_substr((string) $col['help_text'], 0, 500) : null,
                            'dashboard_visible'  => (int) (bool) ($col['dashboard_visible'] ?? true),
                            'report_visible'     => (int) (bool) ($col['report_visible'] ?? true),
                            'aggregation_fn'     => $agg,
                            'created_by_user_id' => $user->id,
                            'created_at'         => $now,
                            'updated_at'         => $now,
                        ]);
                    }
                }
                // startFrom=EMPTY → no columns cloned; user adds them post-create.

                // Auto-publish if requested and at least one enabled column exists.
                if ((bool) $request->input('publish_now', false)) {
                    $enabled = DB::table('aggregated_template_columns')
                        ->where('template_id', $tplId)
                        ->whereNull('deleted_at')
                        ->where('is_enabled', 1)
                        ->count();
                    if ($enabled > 0) {
                        DB::table('aggregated_templates')->where('id', $tplId)->update([
                            'status'               => 'PUBLISHED',
                            'is_active'            => 1,
                            'published_at'         => $now,
                            'published_by_user_id' => $user->id,
                            'updated_at'           => $now,
                        ]);
                    }
                }
            });

            $fresh = DB::table('aggregated_templates')->where('id', $tplId)->first();
            $this->audit->record($request, $this->scopeFor($request), 'CREATE', 'TEMPLATE', (int) $tplId, null, (array) $fresh, (int) $tplId);
            return $this->ok(['template' => (array) $fresh], 'Template created.', ['server_id' => $tplId]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'studio create template');
        }
    }

    /** PATCH /admin/aggregated/studio/template/{id} — meta patch. */
    public function studioUpdateTemplate(Request $request, int $id): JsonResponse
    {
        $guard = $this->requireAdmin($request);
        if ($guard instanceof JsonResponse) return $guard;
        $user = $guard;

        $t = $this->loadScopedTemplate($id, $this->scopeFor($request));
        if (! $t) return $this->err(404, 'Template not found.');
        if ((int) $t->locked === 1) return $this->err(409, 'Template is locked — unlock before editing meta.');

        $now = now()->format('Y-m-d H:i:s');
        $update = ['updated_by_user_id' => $user->id, 'updated_at' => $now];

        foreach (['template_name', 'description', 'icon', 'colour', 'reporting_frequency'] as $k) {
            if (! $request->has($k)) continue;
            $val = $request->input($k);
            if ($k === 'reporting_frequency') {
                $val = strtoupper((string) $val);
                if (! in_array($val, self::VALID_FREQ, true)) {
                    return $this->err(422, 'Invalid reporting_frequency.', ['valid' => self::VALID_FREQ]);
                }
            }
            if ($k === 'template_name') $val = mb_substr((string) $val, 0, 120);
            if ($k === 'description')   $val = mb_substr((string) $val, 0, 500);
            if ($k === 'icon')          $val = mb_substr((string) $val, 0, 40);
            if ($k === 'colour')        $val = mb_substr((string) $val, 0, 16);
            $update[$k] = $val;
        }
        if ($request->has('metadata')) {
            $update['metadata'] = json_encode($request->input('metadata'));
        }

        try {
            DB::table('aggregated_templates')->where('id', $id)->update($update);
            $fresh = DB::table('aggregated_templates')->where('id', $id)->first();
            $this->audit->record($request, $this->scopeFor($request), 'UPDATE', 'TEMPLATE', $id, (array) $t, (array) $fresh, $id);
            return $this->ok(['template' => (array) $fresh], 'Template updated.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'studio update template');
        }
    }

    /** POST /admin/aggregated/studio/template/{id}/lifecycle — publish / retire / lock / unlock. */
    public function studioLifecycle(Request $request, int $id): JsonResponse
    {
        $guard = $this->requireAdmin($request);
        if ($guard instanceof JsonResponse) return $guard;
        $user = $guard;

        $action = strtoupper((string) $request->input('action', ''));
        if (! in_array($action, ['PUBLISH', 'RETIRE', 'LOCK', 'UNLOCK', 'BUMP_VERSION'], true)) {
            return $this->err(422, 'Invalid action.', ['valid' => ['PUBLISH', 'RETIRE', 'LOCK', 'UNLOCK', 'BUMP_VERSION']]);
        }
        $t = $this->loadScopedTemplate($id, $this->scopeFor($request));
        if (! $t) return $this->err(404, 'Template not found.');

        // Locked templates may only be UNLOCKed; every other lifecycle
        // action is blocked server-side. This is defence-in-depth — the UI
        // already disables these buttons when locked, but a direct POST
        // against the endpoint still has to refuse.
        if ((int) $t->locked === 1 && $action !== 'UNLOCK') {
            return $this->err(409, 'Template is locked — unlock before applying lifecycle actions.');
        }

        $now = now()->format('Y-m-d H:i:s');
        try {
            switch ($action) {
                case 'PUBLISH':
                    $enabled = DB::table('aggregated_template_columns')
                        ->where('template_id', $id)
                        ->whereNull('deleted_at')
                        ->where('is_enabled', 1)
                        ->count();
                    if ($enabled < 1) {
                        return $this->err(422, 'Cannot publish a template with no enabled columns.');
                    }
                    DB::table('aggregated_templates')->where('id', $id)->update([
                        'status'               => 'PUBLISHED',
                        'is_active'            => 1,
                        'published_at'         => $now,
                        'published_by_user_id' => $user->id,
                        'retired_at'           => null,
                        'retired_by_user_id'   => null,
                        'updated_by_user_id'   => $user->id,
                        'updated_at'           => $now,
                    ]);
                    break;
                case 'RETIRE':
                    if ((int) $t->is_default === 1) {
                        return $this->err(409, 'Default template cannot be retired.');
                    }
                    DB::table('aggregated_templates')->where('id', $id)->update([
                        'status'              => 'RETIRED',
                        'is_active'           => 0,
                        'retired_at'          => $now,
                        'retired_by_user_id'  => $user->id,
                        'updated_by_user_id'  => $user->id,
                        'updated_at'          => $now,
                    ]);
                    break;
                case 'LOCK':
                    DB::table('aggregated_templates')->where('id', $id)->update([
                        'locked'              => 1,
                        'locked_by_user_id'   => $user->id,
                        'locked_at'           => $now,
                        'updated_by_user_id'  => $user->id,
                        'updated_at'          => $now,
                    ]);
                    break;
                case 'UNLOCK':
                    DB::table('aggregated_templates')->where('id', $id)->update([
                        'locked'              => 0,
                        'locked_by_user_id'   => null,
                        'locked_at'           => null,
                        'updated_by_user_id'  => $user->id,
                        'updated_at'          => $now,
                    ]);
                    break;
                case 'BUMP_VERSION':
                    DB::table('aggregated_templates')->where('id', $id)->update([
                        'version'             => (int) $t->version + 1,
                        'updated_by_user_id'  => $user->id,
                        'updated_at'          => $now,
                    ]);
                    break;
            }
            $fresh = DB::table('aggregated_templates')->where('id', $id)->first();
            $this->audit->record($request, $this->scopeFor($request), $action, 'TEMPLATE', $id, (array) $t, (array) $fresh, $id);
            return $this->ok(['template' => (array) $fresh], "Template {$action} applied.");
        } catch (Throwable $e) {
            return $this->serverError($e, 'studio lifecycle');
        }
    }

    /** DELETE /admin/aggregated/studio/template/{id} — soft delete with cascade confirm. */
    public function studioDeleteTemplate(Request $request, int $id): JsonResponse
    {
        $guard = $this->requireAdmin($request);
        if ($guard instanceof JsonResponse) return $guard;
        $user = $guard;

        $t = $this->loadScopedTemplate($id, $this->scopeFor($request));
        if (! $t) return $this->err(404, 'Template not found.');
        if ((int) $t->is_default === 1) {
            return $this->err(409, 'Default template cannot be deleted.');
        }
        if ((int) $t->locked === 1) {
            return $this->err(409, 'Template is locked — unlock before deleting.');
        }
        // Live templates cannot be hard-deleted in one step. Mobile clients
        // poll /aggregated-templates/published every ~30s and would lose
        // their active form mid-period. Force the operator to retire first
        // (which removes it from the published cache) and only then permit
        // delete. The cascade/confirm tokens are still required if any
        // submissions exist — but they are no longer enough on their own.
        if ((string) $t->status === 'PUBLISHED') {
            return $this->err(409, 'Published templates cannot be deleted directly. Retire it first, then delete.', [
                'status'         => 'PUBLISHED',
                'next_step'      => 'POST /admin/aggregated/studio/template/' . $id . '/lifecycle with action=RETIRE',
            ]);
        }

        $cascade = filter_var($request->input('cascade', $request->query('cascade', false)), FILTER_VALIDATE_BOOLEAN);
        $confirm = (string) $request->input('confirm', (string) $request->query('confirm', ''));
        $subCount = DB::table('aggregated_submissions')
            ->where('template_id', $id)
            ->whereNull('deleted_at')
            ->count();
        if ($subCount > 0 && (! $cascade || $confirm !== 'DELETE_WITH_SUBMISSIONS')) {
            return $this->err(409, "Template has {$subCount} submission(s). Deletion requires cascade+confirm.", [
                'submissions_count' => $subCount,
                'confirm_token'     => 'DELETE_WITH_SUBMISSIONS',
            ]);
        }
        $now = now();
        try {
            DB::transaction(function () use ($id, $user, $now) {
                DB::table('aggregated_templates')->where('id', $id)->update([
                    'status'              => 'RETIRED',
                    'is_active'           => 0,
                    'retired_at'          => $now,
                    'retired_by_user_id'  => $user->id,
                    'deleted_at'          => $now,
                    'updated_by_user_id'  => $user->id,
                    'updated_at'          => $now,
                ]);
                DB::table('aggregated_template_columns')
                    ->where('template_id', $id)
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);
            });
            $this->audit->record($request, $this->scopeFor($request), 'DELETE', 'TEMPLATE', $id, (array) $t, null, $id);
            return $this->ok(['id' => $id, 'submissions_count' => $subCount], 'Template deleted.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'studio delete template');
        }
    }

    /** POST /admin/aggregated/studio/template/{id}/columns — add column. */
    public function studioAddColumn(Request $request, int $id): JsonResponse
    {
        $guard = $this->requireAdmin($request);
        if ($guard instanceof JsonResponse) return $guard;
        $user = $guard;

        $t = $this->loadScopedTemplate($id, $this->scopeFor($request));
        if (! $t) return $this->err(404, 'Template not found.');
        if ((int) $t->locked === 1) return $this->err(409, 'Template is locked.');

        $key   = trim((string) $request->input('column_key', ''));
        $label = trim((string) $request->input('column_label', ''));
        $type  = strtoupper((string) $request->input('data_type', 'INTEGER'));
        $aggFn = strtoupper((string) $request->input('aggregation_fn', 'SUM'));

        if ($key === '' || ! preg_match('/^[a-z][a-z0-9_]{1,58}$/', $key)) {
            return $this->err(422, 'column_key must be lowercase letters/digits/underscores, 2-60 chars, starting with a letter.');
        }
        if ($label === '') return $this->err(422, 'column_label is required.');
        if (! in_array($type, self::VALID_DATA_TYPES, true)) return $this->err(422, 'Invalid data_type.', ['valid' => self::VALID_DATA_TYPES]);
        if (! in_array($aggFn, self::VALID_AGG_FN, true))    return $this->err(422, 'Invalid aggregation_fn.', ['valid' => self::VALID_AGG_FN]);

        $exists = DB::table('aggregated_template_columns')
            ->where('template_id', $id)
            ->where('column_key', $key)
            ->whereNull('deleted_at')
            ->exists();
        if ($exists) return $this->err(409, 'Column key already exists on this template.');

        $now = now()->format('Y-m-d H:i:s');
        $max = (int) (DB::table('aggregated_template_columns')->where('template_id', $id)->max('display_order') ?? 0);
        try {
            $colId = (int) DB::table('aggregated_template_columns')->insertGetId([
                'template_id'        => $id,
                'column_key'         => $key,
                'column_label'       => mb_substr($label, 0, 160),
                'category'           => mb_substr((string) $request->input('category', 'CUSTOM'), 0, 40),
                'data_type'          => $type,
                'is_required'        => (int) (bool) $request->input('is_required', false),
                'is_enabled'         => (int) (bool) $request->input('is_enabled', true),
                'is_core'            => 0,
                'default_value'      => ($v = (string) $request->input('default_value', '')) !== '' ? mb_substr($v, 0, 120) : null,
                'min_value'          => $request->input('min_value'),
                'max_value'          => $request->input('max_value'),
                'select_options'     => $request->has('select_options') ? json_encode($request->input('select_options')) : null,
                'validation_rules'   => $request->has('validation_rules') ? json_encode($request->input('validation_rules')) : null,
                'display_order'      => $max + 1,
                'placeholder'        => ($v = (string) $request->input('placeholder', '')) !== '' ? mb_substr($v, 0, 160) : null,
                'help_text'          => ($v = (string) $request->input('help_text', '')) !== '' ? mb_substr($v, 0, 500) : null,
                'dashboard_visible'  => (int) (bool) $request->input('dashboard_visible', true),
                'report_visible'     => (int) (bool) $request->input('report_visible', true),
                'aggregation_fn'     => $aggFn,
                'created_by_user_id' => $user->id,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $col = DB::table('aggregated_template_columns')->where('id', $colId)->first();
            $this->audit->record($request, $this->scopeFor($request), 'COLUMN_ADD', 'COLUMN', $colId, null, (array) $col, $id);
            return $this->ok(['column' => $this->shapeColumn($col)], 'Column created.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'studio add column');
        }
    }

    /** PATCH /admin/aggregated/studio/columns/{colId} — update column. */
    public function studioUpdateColumn(Request $request, int $colId): JsonResponse
    {
        $guard = $this->requireAdmin($request);
        if ($guard instanceof JsonResponse) return $guard;
        $user = $guard;

        $col = DB::table('aggregated_template_columns')->where('id', $colId)->whereNull('deleted_at')->first();
        if (! $col) return $this->err(404, 'Column not found.');
        $t = $this->loadScopedTemplate((int) $col->template_id, $this->scopeFor($request));
        if (! $t) return $this->err(404, 'Column not found.');
        if ((int) $t->locked === 1) return $this->err(409, 'Template is locked.');

        $patch = ['updated_by_user_id' => $user->id, 'updated_at' => now()];

        foreach (['column_label', 'category', 'placeholder', 'help_text', 'default_value'] as $k) {
            if ($request->has($k)) $patch[$k] = $request->input($k);
        }
        if ($request->has('is_required'))        $patch['is_required'] = (int) (bool) $request->input('is_required');
        if ($request->has('dashboard_visible'))  $patch['dashboard_visible'] = (int) (bool) $request->input('dashboard_visible');
        if ($request->has('report_visible'))     $patch['report_visible']    = (int) (bool) $request->input('report_visible');
        if ($request->has('display_order'))      $patch['display_order']     = (int) $request->input('display_order');
        if ($request->has('min_value'))          $patch['min_value']         = $request->input('min_value');
        if ($request->has('max_value'))          $patch['max_value']         = $request->input('max_value');

        if ($request->has('aggregation_fn')) {
            $v = strtoupper((string) $request->input('aggregation_fn'));
            if (! in_array($v, self::VALID_AGG_FN, true)) {
                return $this->err(422, 'Invalid aggregation_fn.', ['valid' => self::VALID_AGG_FN]);
            }
            $patch['aggregation_fn'] = $v;
        }
        if ($request->has('is_enabled')) {
            $newVal = (int) (bool) $request->input('is_enabled');
            if ((int) $col->is_core === 1 && $newVal === 0) {
                return $this->err(409, 'Core columns cannot be disabled.');
            }
            $patch['is_enabled'] = $newVal;
        }
        if ($request->has('select_options'))  $patch['select_options']  = json_encode($request->input('select_options'));
        if ($request->has('validation_rules')) $patch['validation_rules'] = json_encode($request->input('validation_rules'));

        try {
            DB::table('aggregated_template_columns')->where('id', $colId)->update($patch);
            $fresh = DB::table('aggregated_template_columns')->where('id', $colId)->first();
            $this->audit->record($request, $this->scopeFor($request), 'COLUMN_UPDATE', 'COLUMN', $colId, (array) $col, (array) $fresh, (int) $col->template_id);
            return $this->ok(['column' => $this->shapeColumn($fresh)], 'Column updated.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'studio update column');
        }
    }

    /** DELETE /admin/aggregated/studio/columns/{colId} — soft delete (non-core). */
    public function studioDeleteColumn(Request $request, int $colId): JsonResponse
    {
        $guard = $this->requireAdmin($request);
        if ($guard instanceof JsonResponse) return $guard;

        $col = DB::table('aggregated_template_columns')->where('id', $colId)->whereNull('deleted_at')->first();
        if (! $col) return $this->err(404, 'Column not found.');
        if ((int) $col->is_core === 1) return $this->err(409, 'Core columns cannot be deleted.');
        $t = $this->loadScopedTemplate((int) $col->template_id, $this->scopeFor($request));
        if (! $t) return $this->err(404, 'Column not found.');
        if ((int) $t->locked === 1) return $this->err(409, 'Template is locked.');

        try {
            DB::table('aggregated_template_columns')->where('id', $colId)->update(['deleted_at' => now()]);
            $this->audit->record($request, $this->scopeFor($request), 'COLUMN_DELETE', 'COLUMN', $colId, (array) $col, null, (int) $col->template_id);
            return $this->ok(['id' => $colId], 'Column deleted.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'studio delete column');
        }
    }

    /** PATCH /admin/aggregated/studio/template/{id}/columns/bulk — bulk toggle/reorder. */
    public function studioBulkColumns(Request $request, int $id): JsonResponse
    {
        $guard = $this->requireAdmin($request);
        if ($guard instanceof JsonResponse) return $guard;
        $user = $guard;

        $t = $this->loadScopedTemplate($id, $this->scopeFor($request));
        if (! $t) return $this->err(404, 'Template not found.');
        if ((int) $t->locked === 1) return $this->err(409, 'Template is locked.');

        $rows = $request->input('columns', []);
        if (! is_array($rows)) return $this->err(422, 'columns must be an array.');

        $now = now()->format('Y-m-d H:i:s');
        $affected = 0;
        try {
            DB::transaction(function () use ($rows, $user, $id, $now, &$affected) {
                foreach ($rows as $row) {
                    $colId = (int) ($row['id'] ?? 0);
                    if ($colId <= 0) continue;
                    $col = DB::table('aggregated_template_columns')->where('id', $colId)->where('template_id', $id)->first();
                    if (! $col) continue;
                    $patch = ['updated_by_user_id' => $user->id, 'updated_at' => $now];
                    foreach (['is_enabled', 'display_order', 'column_label', 'placeholder', 'help_text',
                             'dashboard_visible', 'report_visible', 'aggregation_fn'] as $k) {
                        if (! array_key_exists($k, $row)) continue;
                        if ($k === 'is_enabled' && (int) $col->is_core === 1 && (int) (bool) $row[$k] === 0) continue;
                        $patch[$k] = $row[$k];
                    }
                    DB::table('aggregated_template_columns')->where('id', $colId)->update($patch);
                    $affected++;
                }
            });
            // One audit row for the whole batch — entity_id=0 marks "batch
            // operation" and template_id carries the parent. before/after
            // snapshots are deliberately small (count + payload) since
            // capturing every column twice would bloat the audit table.
            $this->audit->record($request, $this->scopeFor($request), 'COLUMN_BULK', 'COLUMN', 0, ['count' => count($rows)], ['affected' => $affected], $id);
            return $this->ok(['affected' => $affected], 'Columns updated.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'studio bulk columns');
        }
    }

    /* ════════════════════════════════════════════════════════════════════
     * ·  INTELLIGENCE surface (submissions + rollups + late + export)
     * ════════════════════════════════════════════════════════════════════ */

    /** GET /admin/aggregated/submissions — Blade shell. */
    public function submissions(Request $request): View
    {
        $scope = $this->scopeFor($request);
        return view('admin.aggregated.submissions', [
            'scope'  => $scope,
            'meta'   => $this->intelMeta($scope),
        ]);
    }

    /** GET /admin/aggregated/submissions/data — paginated list. */
    public function submissionsData(Request $request): JsonResponse
    {
        $scope   = $this->scopeFor($request);
        $country = (string) ($scope['country_code'] ?? config('country.code'));

        $q = DB::table('aggregated_submissions as ag')
            ->leftJoin('users as u', 'u.id', '=', 'ag.submitted_by_user_id')
            ->leftJoin('aggregated_templates as t', 't.id', '=', 'ag.template_id')
            ->whereNull('ag.deleted_at');

        $this->applyCountryFilter($q, $scope, 'ag.country_code');
        $this->applyScopeFilter($q, $scope, 'ag');

        if ($v = $request->query('date_from')) {
            $q->where('ag.period_start', '>=', substr($v, 0, 10) . ' 00:00:00');
        }
        if ($v = $request->query('date_to')) {
            $q->where('ag.period_end', '<=', substr($v, 0, 10) . ' 23:59:59');
        }
        if ($v = $request->query('poe_code'))       $q->where('ag.poe_code', $v);
        if ($v = $request->query('district_code'))  $q->where('ag.district_code', $v);
        if ($v = $request->query('province_code'))  $q->where('ag.province_code', $v);
        if ($v = $request->query('template_id'))    $q->where('ag.template_id', (int) $v);
        if ($v = strtoupper((string) $request->query('sync_status', ''))) {
            if (in_array($v, self::VALID_SYNC_STATUSES, true)) {
                $q->where('ag.sync_status', $v);
            }
        }
        if ($search = trim((string) $request->query('q', ''))) {
            $q->where(function ($w) use ($search) {
                $w->where('ag.notes', 'like', "%{$search}%")
                  ->orWhere('ag.poe_code', 'like', "%{$search}%")
                  ->orWhere('ag.district_code', 'like', "%{$search}%")
                  ->orWhere('t.template_code', 'like', "%{$search}%");
            });
        }

        $perPage = min(100, max(10, (int) $request->query('per_page', self::PAGE_SIZE)));
        $page    = max(1, (int) $request->query('page', 1));
        $total   = (clone $q)->count();

        $rows = $q->select([
                'ag.id', 'ag.client_uuid', 'ag.country_code', 'ag.province_code', 'ag.district_code', 'ag.pheoc_code', 'ag.poe_code',
                'ag.submitted_by_user_id', 'ag.period_start', 'ag.period_end',
                'ag.total_screened', 'ag.total_male', 'ag.total_female', 'ag.total_symptomatic', 'ag.total_asymptomatic',
                'ag.notes', 'ag.template_id', 'ag.template_code', 'ag.template_version',
                'ag.device_id', 'ag.app_version', 'ag.platform', 'ag.record_version',
                'ag.sync_status', 'ag.synced_at', 'ag.server_received_at', 'ag.sync_attempt_count', 'ag.last_sync_error',
                'ag.created_at', 'ag.updated_at',
                'u.full_name as submitted_by_name',
                't.template_name as template_name',
            ])
            ->orderByDesc('ag.period_end')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get()
            ->map(fn ($r) => $this->shapeSubmission($r))
            ->values()->all();

        return response()->json([
            'success' => true,
            'data' => [
                'items'    => $rows,
                'total'    => $total,
                'per_page' => $perPage,
                'page'     => $page,
                'pages'    => (int) ceil($total / max(1, $perPage)),
            ],
            'meta' => $this->intelMeta($scope),
        ]);
    }

    /** GET /admin/aggregated/submissions/{id} — single submission + values. */
    public function submissionShow(Request $request, int $id): JsonResponse
    {
        $scope = $this->scopeFor($request);
        $sub = DB::table('aggregated_submissions as ag')
            ->leftJoin('users as u', 'u.id', '=', 'ag.submitted_by_user_id')
            ->leftJoin('aggregated_templates as t', 't.id', '=', 'ag.template_id')
            ->where('ag.id', $id)
            ->whereNull('ag.deleted_at')
            ->select('ag.*', 'u.full_name as submitted_by_name', 't.template_name as template_name')
            ->first();
        if (! $sub) return $this->err(404, 'Submission not found.');
        if (! $this->inScope($sub, $scope)) return $this->err(403, 'Submission out of scope.');

        $values = DB::table('aggregated_submission_values as v')
            ->leftJoin('aggregated_template_columns as c', 'c.id', '=', 'v.template_column_id')
            ->where('v.submission_id', $id)
            ->select('v.*', 'c.column_label', 'c.data_type as col_data_type', 'c.category')
            ->orderBy('c.display_order')
            ->get()
            ->map(fn ($v) => [
                'id'                 => (int) $v->id,
                'column_key'         => (string) $v->column_key,
                'column_label'       => (string) ($v->column_label ?? $v->column_key),
                'category'           => (string) ($v->category ?? ''),
                'data_type'          => (string) ($v->col_data_type ?? ''),
                'value_numeric'      => $v->value_numeric,
                'value_text'         => $v->value_text,
                'value_json'         => $v->value_json ? $this->safeJson((string) $v->value_json) : null,
                'template_column_id' => (int) $v->template_column_id,
            ])->values()->all();

        return response()->json([
            'success' => true,
            'data' => [
                'submission' => $this->shapeSubmission($sub),
                'values'     => $values,
            ],
        ]);
    }

    /** GET /admin/aggregated/submissions/rollups — monthly / POE / district / province / national. */
    public function rollups(Request $request): JsonResponse
    {
        $scope   = $this->scopeFor($request);
        $country = (string) ($scope['country_code'] ?? config('country.code'));

        $base = DB::table('aggregated_submissions')->whereNull('deleted_at');
        $this->applyCountryFilter($base, $scope);
        $this->applyScopeFilter($base, $scope);

        if ($v = $request->query('date_from')) $base->where('period_start', '>=', substr($v, 0, 10) . ' 00:00:00');
        if ($v = $request->query('date_to'))   $base->where('period_end',   '<=', substr($v, 0, 10) . ' 23:59:59');
        if ($v = $request->query('template_id')) $base->where('template_id', (int) $v);

        $monthly = (clone $base)
            ->selectRaw("DATE_FORMAT(period_end,'%Y-%m') as month, SUM(total_screened) as screened, SUM(total_symptomatic) as symptomatic, SUM(total_asymptomatic) as asymptomatic, COUNT(*) as submissions")
            ->groupBy('month')->orderBy('month')->limit(24)->get()
            ->map(fn ($r) => [
                'month'        => (string) $r->month,
                'screened'     => (int) $r->screened,
                'symptomatic'  => (int) $r->symptomatic,
                'asymptomatic' => (int) $r->asymptomatic,
                'submissions'  => (int) $r->submissions,
            ])->all();

        $poe = (clone $base)
            ->selectRaw('poe_code, district_code, SUM(total_screened) as screened, SUM(total_symptomatic) as symptomatic, COUNT(*) as submissions, MAX(period_end) as latest')
            ->groupBy('poe_code', 'district_code')
            ->orderByDesc('screened')
            ->limit(50)->get()
            ->map(fn ($r) => [
                'poe'          => (string) $r->poe_code,
                'district'     => (string) $r->district_code,
                'screened'     => (int) $r->screened,
                'symptomatic'  => (int) $r->symptomatic,
                'submissions'  => (int) $r->submissions,
                'latest'       => $r->latest ? Carbon::parse((string) $r->latest)->format('Y-m-d') : null,
                'latest_rel'   => $r->latest ? Carbon::parse((string) $r->latest)->diffForHumans() : '—',
            ])->all();

        $district = (clone $base)
            ->selectRaw('district_code, SUM(total_screened) as screened, SUM(total_symptomatic) as symptomatic, COUNT(DISTINCT poe_code) as poes, COUNT(*) as submissions')
            ->groupBy('district_code')
            ->orderByDesc('screened')
            ->limit(50)->get()
            ->map(fn ($r) => [
                'district'     => (string) $r->district_code,
                'screened'     => (int) $r->screened,
                'symptomatic'  => (int) $r->symptomatic,
                'poes'         => (int) $r->poes,
                'submissions'  => (int) $r->submissions,
            ])->all();

        $province = (clone $base)
            ->selectRaw('province_code, SUM(total_screened) as screened, SUM(total_symptomatic) as symptomatic, COUNT(DISTINCT district_code) as districts, COUNT(*) as submissions')
            ->whereNotNull('province_code')
            ->groupBy('province_code')
            ->orderByDesc('screened')
            ->get()
            ->map(fn ($r) => [
                'province'     => (string) $r->province_code,
                'screened'     => (int) $r->screened,
                'symptomatic'  => (int) $r->symptomatic,
                'districts'    => (int) $r->districts,
                'submissions'  => (int) $r->submissions,
            ])->all();

        $national = (clone $base)
            ->selectRaw('SUM(total_screened) as screened, SUM(total_symptomatic) as symptomatic, SUM(total_asymptomatic) as asymptomatic, SUM(total_male) as male, SUM(total_female) as female, COUNT(*) as submissions, COUNT(DISTINCT poe_code) as poes_reporting, COUNT(DISTINCT district_code) as districts_reporting, COUNT(DISTINCT template_id) as templates_used')
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'monthly'  => $monthly,
                'poe'      => $poe,
                'district' => $district,
                'province' => $province,
                'national' => [
                    'screened'            => (int) ($national->screened ?? 0),
                    'symptomatic'         => (int) ($national->symptomatic ?? 0),
                    'asymptomatic'        => (int) ($national->asymptomatic ?? 0),
                    'male'                => (int) ($national->male ?? 0),
                    'female'              => (int) ($national->female ?? 0),
                    'submissions'         => (int) ($national->submissions ?? 0),
                    'poes_reporting'      => (int) ($national->poes_reporting ?? 0),
                    'districts_reporting' => (int) ($national->districts_reporting ?? 0),
                    'templates_used'      => (int) ($national->templates_used ?? 0),
                    'symptomatic_pct'     => ($national->screened ?? 0) > 0
                        ? round(((int) $national->symptomatic / (int) $national->screened) * 100, 2)
                        : 0,
                ],
            ],
            'meta' => $this->intelMeta($scope),
        ]);
    }

    /** GET /admin/aggregated/submissions/late-reporters — gap detector. */
    public function lateReporters(Request $request): JsonResponse
    {
        $scope   = $this->scopeFor($request);
        $country = (string) ($scope['country_code'] ?? config('country.code'));

        $templateId = (int) $request->query('template_id', 0);
        $template = null;
        if ($templateId > 0) {
            $template = DB::table('aggregated_templates')
                ->where('id', $templateId)
                ->whereNull('deleted_at')
                ->first();
        } else {
            $tq = DB::table('aggregated_templates')
                ->where('status', 'PUBLISHED')
                ->whereNull('deleted_at')
                ->orderByDesc('is_active')
                ->orderByDesc('published_at');
            $this->applyCountryFilter($tq, $scope);
            $template = $tq->first();
        }
        if (! $template) {
            return response()->json([
                'success' => true,
                'data' => ['template' => null, 'period' => null, 'reported' => [], 'missing' => []],
                'meta' => ['message' => 'No published template available for this country.'],
            ]);
        }

        $freq = strtoupper((string) ($template->reporting_frequency ?? 'WEEKLY'));
        [$periodStart, $periodEnd, $periodLabel] = $this->currentPeriod($freq);

        // Hybrid join: ref_poes stores the POE registry; ref_districts provides
        // the canonical district code/name via district_id FK. The submission
        // row itself carries a denormalised district_code string so we map
        // ref_districts.code → district_code here to stay consistent.
        $poeQ = DB::table('ref_poes as p')
            ->leftJoin('ref_districts as d', 'd.id', '=', 'p.district_id')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', 1)
            ->select([
                'p.poe_code',
                'p.poe_name',
                'p.district_id',
                DB::raw('COALESCE(d.code, p.district) AS district_code'),
                DB::raw('COALESCE(d.name, p.district) AS district_name'),
            ])
            ->orderBy('district_name')
            ->orderBy('poe_name');
        $this->applyCountryFilter($poeQ, $scope, 'p.country_code');
        $expectedPoes = $poeQ->get();

        $visiblePoes = $expectedPoes->filter(function ($p) use ($scope) {
            if (! empty($scope['is_super'])) return true;
            if (! empty($scope['poes'])) {
                return in_array($p->poe_code, $scope['poes'], true);
            }
            if (! empty($scope['districts'])) {
                return in_array((string) ($p->district_code ?? ''), $scope['districts'], true);
            }
            return true;
        })->values();

        $reportedQ = DB::table('aggregated_submissions')
            ->where('template_id', $template->id)
            ->whereNull('deleted_at')
            ->where('period_start', '>=', $periodStart)
            ->where('period_end', '<=', $periodEnd);
        $this->applyCountryFilter($reportedQ, $scope);
        $reported = $reportedQ
            ->select('poe_code', DB::raw('COUNT(*) as cnt'), DB::raw('MAX(server_received_at) as last_received'))
            ->groupBy('poe_code')
            ->get()->keyBy('poe_code');

        $missing = [];
        $reportedList = [];
        foreach ($visiblePoes as $p) {
            $info = $reported->get($p->poe_code);
            if ($info) {
                $reportedList[] = [
                    'poe_code'      => (string) $p->poe_code,
                    'poe_name'      => (string) $p->poe_name,
                    'district_code' => (string) ($p->district_code ?? ''),
                    'count'         => (int) $info->cnt,
                    'last_received' => $info->last_received,
                ];
            } else {
                $missing[] = [
                    'poe_code'      => (string) $p->poe_code,
                    'poe_name'      => (string) $p->poe_name,
                    'district_code' => (string) ($p->district_code ?? ''),
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'template' => [
                    'id'                  => (int) $template->id,
                    'template_name'       => (string) $template->template_name,
                    'template_code'       => (string) $template->template_code,
                    'reporting_frequency' => $freq,
                ],
                'period' => [
                    'start' => $periodStart,
                    'end'   => $periodEnd,
                    'label' => $periodLabel,
                ],
                'reported' => $reportedList,
                'missing'  => $missing,
                'coverage_pct' => $visiblePoes->count() > 0
                    ? round((count($reportedList) / $visiblePoes->count()) * 100, 1)
                    : 0,
                'expected_count' => $visiblePoes->count(),
            ],
        ]);
    }

    /** GET /admin/aggregated/submissions/export — CSV stream of in-scope rows. */
    public function export(Request $request): Response
    {
        $scope   = $this->scopeFor($request);
        $country = (string) ($scope['country_code'] ?? config('country.code'));

        $q = DB::table('aggregated_submissions as ag')
            ->leftJoin('users as u', 'u.id', '=', 'ag.submitted_by_user_id')
            ->leftJoin('aggregated_templates as t', 't.id', '=', 'ag.template_id')
            ->whereNull('ag.deleted_at');
        $this->applyCountryFilter($q, $scope, 'ag.country_code');
        $this->applyScopeFilter($q, $scope, 'ag');

        if ($v = $request->query('date_from')) $q->where('ag.period_start', '>=', substr($v, 0, 10) . ' 00:00:00');
        if ($v = $request->query('date_to'))   $q->where('ag.period_end',   '<=', substr($v, 0, 10) . ' 23:59:59');
        if ($v = $request->query('template_id')) $q->where('ag.template_id', (int) $v);
        if ($v = $request->query('poe_code'))    $q->where('ag.poe_code', $v);

        $rows = $q->orderByDesc('ag.period_end')
            ->select([
                'ag.id', 'ag.client_uuid', 'ag.country_code', 'ag.province_code', 'ag.district_code', 'ag.poe_code',
                'ag.period_start', 'ag.period_end',
                'ag.total_screened', 'ag.total_male', 'ag.total_female', 'ag.total_symptomatic', 'ag.total_asymptomatic',
                'ag.notes', 'ag.template_code', 'ag.template_version',
                'ag.platform', 'ag.app_version', 'ag.sync_status', 'ag.synced_at',
                'ag.created_at', 'u.full_name as submitted_by', 't.template_name',
            ])
            ->limit(5000)
            ->get();

        $fh = fopen('php://temp', 'r+');
        $header = [
            'id', 'client_uuid', 'country', 'province', 'district', 'poe',
            'period_start', 'period_end',
            'screened', 'male', 'female', 'symptomatic', 'asymptomatic',
            'notes', 'template_code', 'template_name', 'template_version',
            'platform', 'app_version', 'sync_status', 'synced_at',
            'submitted_by', 'created_at',
        ];
        fputcsv($fh, $header);
        foreach ($rows as $r) {
            fputcsv($fh, [
                $r->id, $r->client_uuid, $r->country_code, $r->province_code, $r->district_code, $r->poe_code,
                $r->period_start, $r->period_end,
                $r->total_screened, $r->total_male, $r->total_female, $r->total_symptomatic, $r->total_asymptomatic,
                $r->notes, $r->template_code, $r->template_name, $r->template_version,
                $r->platform, $r->app_version, $r->sync_status, $r->synced_at,
                $r->submitted_by, $r->created_at,
            ]);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $filename = 'idsr-submissions-' . now()->format('Ymd-His') . '.csv';
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Row-Count'         => (string) $rows->count(),
        ]);
    }

    /* ════════════════════════════════════════════════════════════════════
     * ·  SYNC QUEUE surface
     * ════════════════════════════════════════════════════════════════════ */

    /** GET /admin/aggregated/sync — Blade shell. */
    public function sync(Request $request): View
    {
        $scope = $this->scopeFor($request);
        return view('admin.aggregated.sync', [
            'scope'     => $scope,
            'canWrite'  => $this->canWrite($request),
        ]);
    }

    /** GET /admin/aggregated/sync/data — queue rows. */
    public function syncData(Request $request): JsonResponse
    {
        $scope   = $this->scopeFor($request);
        $country = (string) ($scope['country_code'] ?? config('country.code'));

        $tab = strtoupper((string) $request->query('tab', 'FAILED'));
        if (! in_array($tab, ['FAILED', 'UNSYNCED', 'ALL'], true)) $tab = 'FAILED';

        $q = DB::table('aggregated_submissions as ag')
            ->leftJoin('users as u', 'u.id', '=', 'ag.submitted_by_user_id')
            ->leftJoin('aggregated_templates as t', 't.id', '=', 'ag.template_id')
            ->whereNull('ag.deleted_at');
        $this->applyCountryFilter($q, $scope, 'ag.country_code');
        $this->applyScopeFilter($q, $scope, 'ag');

        if ($tab === 'FAILED')   $q->where('ag.sync_status', 'FAILED');
        if ($tab === 'UNSYNCED') $q->where('ag.sync_status', 'UNSYNCED');

        $perPage = min(100, max(10, (int) $request->query('per_page', self::PAGE_SIZE)));
        $page    = max(1, (int) $request->query('page', 1));
        $total   = (clone $q)->count();

        $rows = $q->select([
                'ag.id', 'ag.client_uuid', 'ag.poe_code', 'ag.district_code', 'ag.province_code',
                'ag.period_start', 'ag.period_end', 'ag.total_screened',
                'ag.sync_status', 'ag.synced_at', 'ag.sync_attempt_count', 'ag.last_sync_error',
                'ag.device_id', 'ag.app_version', 'ag.platform', 'ag.created_at',
                'ag.template_code', 'ag.template_version',
                'u.full_name as submitted_by_name',
                't.template_name',
            ])
            ->orderByDesc('ag.sync_attempt_count')
            ->orderByDesc('ag.updated_at')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get();

        $countsQ = DB::table('aggregated_submissions')->whereNull('deleted_at');
        $this->applyCountryFilter($countsQ, $scope);
        $counts = $countsQ->selectRaw('sync_status, COUNT(*) as cnt')
            ->groupBy('sync_status')
            ->pluck('cnt', 'sync_status');

        $deviceQ = DB::table('aggregated_submissions')->whereNull('deleted_at');
        $this->applyCountryFilter($deviceQ, $scope);
        $deviceMix = $deviceQ->selectRaw('platform, app_version, COUNT(*) as cnt')
            ->groupBy('platform', 'app_version')
            ->orderByDesc('cnt')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'platform'    => (string) $r->platform,
                'app_version' => (string) ($r->app_version ?? '—'),
                'count'       => (int) $r->cnt,
            ])->all();

        return response()->json([
            'success' => true,
            'data' => [
                'items' => $rows->map(fn ($r) => [
                    'id'                 => (int) $r->id,
                    'client_uuid'        => (string) $r->client_uuid,
                    'poe_code'           => (string) $r->poe_code,
                    'district_code'      => (string) $r->district_code,
                    'province_code'      => (string) ($r->province_code ?? ''),
                    'period_start'       => $r->period_start,
                    'period_end'         => $r->period_end,
                    'period_label'       => $r->period_start && $r->period_end
                        ? Carbon::parse((string) $r->period_start)->format('M j') . ' – ' . Carbon::parse((string) $r->period_end)->format('M j, Y')
                        : '—',
                    'total_screened'     => (int) $r->total_screened,
                    'sync_status'        => (string) $r->sync_status,
                    'synced_at'          => $r->synced_at,
                    'sync_attempt_count' => (int) $r->sync_attempt_count,
                    'last_sync_error'    => (string) ($r->last_sync_error ?? ''),
                    'device_id'          => (string) ($r->device_id ?? ''),
                    'app_version'        => (string) ($r->app_version ?? ''),
                    'platform'           => (string) $r->platform,
                    'submitted_by_name'  => (string) ($r->submitted_by_name ?? ''),
                    'template_code'      => (string) ($r->template_code ?? ''),
                    'template_name'      => (string) ($r->template_name ?? ''),
                    'template_version'   => (int) ($r->template_version ?? 0),
                    'created_rel'        => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
                ])->values()->all(),
                'total'     => $total,
                'per_page'  => $perPage,
                'page'      => $page,
                'pages'     => (int) ceil($total / max(1, $perPage)),
                'counts'    => [
                    'SYNCED'   => (int) ($counts['SYNCED'] ?? 0),
                    'UNSYNCED' => (int) ($counts['UNSYNCED'] ?? 0),
                    'FAILED'   => (int) ($counts['FAILED'] ?? 0),
                    'ALL'      => (int) ($counts->sum()),
                ],
                'device_mix' => $deviceMix,
            ],
        ]);
    }

    /** POST /admin/aggregated/sync/{id}/resync — mark as synced; clear error. */
    public function syncResync(Request $request, int $id): JsonResponse
    {
        $guard = $this->requireAdmin($request);
        if ($guard instanceof JsonResponse) return $guard;
        $user = $guard;

        $confirm = (string) $request->input('confirm', '');
        if ($confirm !== 'RESYNC') return $this->err(422, 'Confirmation required.', ['confirm_token' => 'RESYNC']);

        $r = $this->loadScopedSubmission($id, $this->scopeFor($request));
        if (! $r) return $this->err(404, 'Submission not found.');

        try {
            DB::table('aggregated_submissions')->where('id', $id)->update([
                'sync_status'       => 'SYNCED',
                'synced_at'         => now(),
                'server_received_at' => $r->server_received_at ?: now(),
                'last_sync_error'   => null,
                'updated_at'        => now(),
            ]);
            $fresh = DB::table('aggregated_submissions')->where('id', $id)->first();
            $this->audit->record($request, $this->scopeFor($request), 'SYNC_RESYNC', 'SUBMISSION', $id, (array) $r, (array) $fresh, (int) ($r->template_id ?? 0));
            return $this->ok(['id' => $id], 'Submission marked as synced.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'sync resync');
        }
    }

    /* ════════════════════════════════════════════════════════════════════
     * ·  REPORTS surface — dynamic per-template analytics engine
     * ════════════════════════════════════════════════════════════════════ */

    /** GET /admin/aggregated/reports — Blade shell. */
    public function reports(Request $request): View
    {
        return view('admin.aggregated.reports', [
            'scope' => $this->scopeFor($request),
        ]);
    }

    /**
     * GET /admin/aggregated/reports/data
     *
     * Landing payload: every template in scope that has at least one
     * submission, ordered by activity. This is the "gallery" — the
     * reports engine drills into a single template's analytics.
     */
    public function reportsData(Request $request): JsonResponse
    {
        $scope = $this->scopeFor($request);

        // Base template query
        $tplQ = DB::table('aggregated_templates')->whereNull('deleted_at');
        $this->applyCountryFilter($tplQ, $scope);

        $templates = $tplQ
            ->select([
                'id', 'template_code', 'template_name', 'description',
                'status', 'reporting_frequency', 'version', 'is_active',
                'is_default', 'colour', 'published_at',
            ])
            ->orderByDesc('is_active')
            ->orderByDesc('published_at')
            ->orderBy('template_name')
            ->get();

        $ids = $templates->pluck('id')->all();

        // Per-template submission stats in one query
        $statsQ = DB::table('aggregated_submissions')
            ->whereNull('deleted_at')
            ->whereIn('template_id', $ids ?: [0]);
        $this->applyCountryFilter($statsQ, $scope);
        $this->applyScopeFilter($statsQ, $scope);
        $stats = $statsQ->selectRaw('
                template_id,
                COUNT(*) as submissions,
                COUNT(DISTINCT poe_code) as poes,
                COUNT(DISTINCT district_code) as districts,
                SUM(total_screened) as screened,
                SUM(total_symptomatic) as symptomatic,
                MAX(period_end) as latest_period,
                MAX(server_received_at) as latest_received
            ')
            ->groupBy('template_id')
            ->get()
            ->keyBy('template_id');

        // Per-template column count
        $colCounts = DB::table('aggregated_template_columns')
            ->whereIn('template_id', $ids ?: [0])
            ->whereNull('deleted_at')
            ->selectRaw('template_id, COUNT(*) as total, SUM(is_enabled) as enabled')
            ->groupBy('template_id')
            ->get()
            ->keyBy('template_id');

        $payload = $templates->map(function ($t) use ($stats, $colCounts) {
            $s  = $stats->get($t->id);
            $cc = $colCounts->get($t->id);
            return [
                'id'                  => (int) $t->id,
                'template_code'       => (string) $t->template_code,
                'template_name'       => (string) $t->template_name,
                'description'         => (string) ($t->description ?? ''),
                'status'              => (string) $t->status,
                'reporting_frequency' => (string) ($t->reporting_frequency ?? 'AD_HOC'),
                'version'             => (int) $t->version,
                'is_active'           => (bool) $t->is_active,
                'is_default'          => (bool) $t->is_default,
                'colour'              => (string) ($t->colour ?? '#10B981'),
                'published_at'        => $t->published_at,
                'columns_total'       => (int) ($cc->total ?? 0),
                'columns_enabled'     => (int) ($cc->enabled ?? 0),
                'submissions'         => (int) ($s->submissions ?? 0),
                'poes_reporting'      => (int) ($s->poes ?? 0),
                'districts_reporting' => (int) ($s->districts ?? 0),
                'screened'            => (int) ($s->screened ?? 0),
                'symptomatic'         => (int) ($s->symptomatic ?? 0),
                'latest_period'       => $s->latest_period ?? null,
                'latest_received'     => $s->latest_received ?? null,
                'latest_rel'          => $s && $s->latest_received
                    ? Carbon::parse((string) $s->latest_received)->diffForHumans()
                    : '—',
                'has_data'            => (int) (($s->submissions ?? 0) > 0),
            ];
        })->values()->all();

        // National aggregate across all templates in scope
        $nationalQ = DB::table('aggregated_submissions')->whereNull('deleted_at');
        $this->applyCountryFilter($nationalQ, $scope);
        $this->applyScopeFilter($nationalQ, $scope);
        $nat = $nationalQ->selectRaw('
                COUNT(*) as submissions,
                SUM(total_screened) as screened,
                SUM(total_symptomatic) as symptomatic,
                COUNT(DISTINCT poe_code) as poes,
                COUNT(DISTINCT template_id) as templates
            ')->first();

        return response()->json([
            'success' => true,
            'data' => [
                'templates' => $payload,
                'summary' => [
                    'templates_in_scope'   => count($payload),
                    'templates_with_data'  => collect($payload)->filter(fn ($t) => $t['has_data'])->count(),
                    'total_submissions'    => (int) ($nat->submissions ?? 0),
                    'total_screened'       => (int) ($nat->screened ?? 0),
                    'total_symptomatic'    => (int) ($nat->symptomatic ?? 0),
                    'distinct_poes'        => (int) ($nat->poes ?? 0),
                    'distinct_templates'   => (int) ($nat->templates ?? 0),
                    'scope_label'          => (string) ($scope['label'] ?? '—'),
                ],
            ],
        ]);
    }

    /** GET /admin/aggregated/reports/template/{id} — full dynamic analysis. */
    public function reportTemplate(Request $request, int $id): JsonResponse
    {
        $scope = $this->scopeFor($request);
        $filters = [
            'date_from'     => $request->query('date_from'),
            'date_to'       => $request->query('date_to'),
            'poe_code'      => $request->query('poe_code'),
            'district_code' => $request->query('district_code'),
            'province_code' => $request->query('province_code'),
        ];
        $filters = array_filter($filters, fn ($v) => $v !== null && $v !== '');

        $analysis = $this->reports->analyze($id, $scope, $filters);
        if (! empty($analysis['error'])) {
            return $this->err(404, $analysis['error']);
        }
        return response()->json([
            'success' => true,
            'data'    => $analysis,
            'meta'    => [
                'scope_label' => (string) ($scope['label'] ?? '—'),
                'can_write'   => $this->canWrite($request),
            ],
        ]);
    }

    /**
     * GET /admin/aggregated/reports/template/{id}/export
     *
     * Detailed per-template CSV export: one row per submission, with every
     * template_value pivoted into its own column (column_key → value).
     * Core totals included so nothing is lost.
     */
    public function reportExport(Request $request, int $id): Response
    {
        $scope = $this->scopeFor($request);

        $template = DB::table('aggregated_templates')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $template) return response('Template not found', 404);
        if (empty($scope['is_super'])) {
            $aliases = $this->countries->aliases((string) ($scope['country_code'] ?? ''));
            if ($aliases && ! in_array((string) $template->country_code, $aliases, true)) {
                return response('Out of scope', 403);
            }
        }

        $columns = DB::table('aggregated_template_columns')
            ->where('template_id', $id)->whereNull('deleted_at')
            ->orderBy('display_order')->orderBy('id')->get();
        $columnKeys = $columns->pluck('column_key')->all();

        $subQ = DB::table('aggregated_submissions as ag')
            ->leftJoin('users as u', 'u.id', '=', 'ag.submitted_by_user_id')
            ->where('ag.template_id', $id)
            ->whereNull('ag.deleted_at');
        $this->applyCountryFilter($subQ, $scope, 'ag.country_code');
        $this->applyScopeFilter($subQ, $scope, 'ag');
        foreach (['date_from', 'date_to', 'poe_code', 'district_code', 'province_code'] as $f) {
            $v = $request->query($f);
            if (! $v) continue;
            if ($f === 'date_from') $subQ->where('ag.period_start', '>=', substr($v, 0, 10) . ' 00:00:00');
            elseif ($f === 'date_to') $subQ->where('ag.period_end', '<=', substr($v, 0, 10) . ' 23:59:59');
            else $subQ->where('ag.' . $f, $v);
        }
        $submissions = $subQ
            ->orderByDesc('ag.period_end')
            ->select([
                'ag.id', 'ag.client_uuid', 'ag.province_code', 'ag.district_code', 'ag.poe_code',
                'ag.period_start', 'ag.period_end',
                'ag.total_screened', 'ag.total_male', 'ag.total_female', 'ag.total_symptomatic', 'ag.total_asymptomatic',
                'ag.notes', 'ag.template_version', 'ag.platform', 'ag.app_version', 'ag.sync_status',
                'ag.synced_at', 'ag.server_received_at', 'ag.created_at',
                'u.full_name as submitted_by',
            ])
            ->limit(10000)
            ->get();
        $subIds = $submissions->pluck('id')->all();

        // Pivot template_values by submission_id + column_key
        $values = collect();
        if (! empty($subIds)) {
            $values = DB::table('aggregated_submission_values')
                ->whereIn('submission_id', $subIds)
                ->get()
                ->groupBy('submission_id');
        }

        $fh = fopen('php://temp', 'r+');
        $header = [
            'submission_id', 'client_uuid', 'period_start', 'period_end',
            'province', 'district', 'poe',
            'total_screened', 'total_male', 'total_female', 'total_symptomatic', 'total_asymptomatic',
            'submitted_by', 'template_version', 'platform', 'app_version', 'sync_status', 'synced_at', 'notes',
        ];
        foreach ($columnKeys as $k) $header[] = $k;
        fputcsv($fh, $header);

        foreach ($submissions as $s) {
            $row = [
                $s->id, $s->client_uuid, $s->period_start, $s->period_end,
                $s->province_code, $s->district_code, $s->poe_code,
                $s->total_screened, $s->total_male, $s->total_female, $s->total_symptomatic, $s->total_asymptomatic,
                $s->submitted_by, $s->template_version, $s->platform, $s->app_version, $s->sync_status, $s->synced_at, $s->notes,
            ];
            $vs = $values->get($s->id, collect());
            $byKey = $vs->keyBy('column_key');
            foreach ($columnKeys as $k) {
                $v = $byKey->get($k);
                if (! $v) { $row[] = ''; continue; }
                if ($v->value_numeric !== null) $row[] = (string) $v->value_numeric;
                elseif ($v->value_text !== null) $row[] = (string) $v->value_text;
                else $row[] = '';
            }
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $fname = 'report-' . ($template->template_code ?? $id) . '-' . now()->format('Ymd-His') . '.csv';
        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fname}\"",
            'X-Row-Count'         => (string) $submissions->count(),
        ]);
    }

    /* ════════════════════════════════════════════════════════════════════
     * ·  Helpers
     * ════════════════════════════════════════════════════════════════════ */

    protected function scopeFor(Request $request): array
    {
        return $request->user()
            ? $this->scope->forUser($request->user())
            : ['country_code' => config('country.code'), 'is_super' => true, 'scope_level' => 'NATIONAL', 'label' => config('country.legacy_code') . ' · National (preview)', 'poes' => [], 'districts' => [], 'provinces' => []];
    }

    /**
     * Apply country filter with alias support. Delegates to the shared
     * CountryResolver service so mobile writers, admin writers, and
     * read queries all agree on which string values belong to the same
     * country. Safety-net against historical drift — after the
     * normalisation migration runs, every row already carries the
     * canonical form and the whereIn collapses to an equality-equivalent.
     */
    protected function applyCountryFilter($query, array $scope, string $column = 'country_code'): void
    {
        $raw = (string) ($scope['country_code'] ?? '');
        if ($raw === '') return;
        $aliases = $this->countries->aliases($raw);
        if (empty($aliases)) return;
        $query->whereIn($column, $aliases);
    }

    protected function canWrite(Request $request): bool
    {
        $user = $request->user();
        if (! $user) return false;
        $role = strtoupper((string) ($user->role_key ?? $user->account_type ?? ''));
        return in_array($role, ['NATIONAL_ADMIN', 'SUPER_ADMIN', 'SERVICE'], true);
    }

    protected function requireAdmin(Request $request)
    {
        $user = $request->user();
        if (! $user) return $this->err(401, 'Authentication required.');
        if (! $this->canWrite($request)) {
            return $this->err(403, 'Admin privileges required (NATIONAL_ADMIN).');
        }
        return $user;
    }

    /** Apply jurisdictional filter mirroring the mobile AggregatedController logic. */
    protected function applyScopeFilter($query, array $scope, string $alias = ''): void
    {
        if (! empty($scope['is_super'])) return;
        $col = $alias ? "{$alias}." : '';
        $level = (string) ($scope['scope_level'] ?? 'NATIONAL');
        if ($level === 'POE' && ! empty($scope['poes'])) {
            $query->whereIn($col . 'poe_code', $scope['poes']);
            return;
        }
        if ($level === 'DISTRICT' && ! empty($scope['districts'])) {
            $query->whereIn($col . 'district_code', $scope['districts']);
            return;
        }
        if ($level === 'PHEOC' && ! empty($scope['provinces'])) {
            $query->whereIn($col . 'province_code', $scope['provinces']);
            return;
        }
        if (! empty($scope['country_code'])) {
            $query->where($col . 'country_code', $scope['country_code']);
        }
    }

    protected function inScope(object $row, array $scope): bool
    {
        if (! empty($scope['is_super'])) return true;
        $level = (string) ($scope['scope_level'] ?? 'NATIONAL');
        if ($level === 'POE')      return in_array((string) ($row->poe_code ?? ''), $scope['poes'] ?? [], true);
        if ($level === 'DISTRICT') return in_array((string) ($row->district_code ?? ''), $scope['districts'] ?? [], true);
        if ($level === 'PHEOC')    return in_array((string) ($row->province_code ?? ''), $scope['provinces'] ?? [], true);
        return (string) ($row->country_code ?? '') === (string) ($scope['country_code'] ?? '');
    }

    /**
     * Load a template, ensuring it lives inside the caller's scope.
     * NATIONAL_ADMIN sees every country; everyone else is gated by
     * country_code aliases (mirrors mobile AggregatedController logic
     * via CountryResolver). Returns null when the row is missing or
     * out of scope — callers convert to a 404.
     *
     * Templates do not carry POE/DISTRICT/PHEOC codes, so the scope
     * check is country-level only. Locked-state, status, and other
     * lifecycle gates remain the caller's responsibility.
     */
    protected function loadScopedTemplate(int $id, array $scope): ?object
    {
        $q = DB::table('aggregated_templates')->where('id', $id)->whereNull('deleted_at');
        $this->applyCountryFilter($q, $scope);
        return $q->first();
    }

    /**
     * Load a submission, ensuring it lives inside the caller's scope.
     * Applies BOTH country-alias filter and the jurisdictional
     * POE/DISTRICT/PHEOC tier filter. Returns null when missing or out
     * of scope — callers convert to a 404. This is the safety net the
     * sync-resync and submission-show paths now use to prevent a
     * cross-jurisdiction admin from forcing state changes on rows
     * outside their visibility.
     */
    protected function loadScopedSubmission(int $id, array $scope): ?object
    {
        $q = DB::table('aggregated_submissions')->where('id', $id)->whereNull('deleted_at');
        $this->applyCountryFilter($q, $scope);
        $this->applyScopeFilter($q, $scope);
        return $q->first();
    }

    protected function shapeColumn(object $c): array
    {
        $arr = (array) $c;
        foreach (['select_options', 'validation_rules'] as $k) {
            if (isset($arr[$k]) && is_string($arr[$k])) {
                $decoded = json_decode($arr[$k], true);
                $arr[$k] = is_array($decoded) ? $decoded : null;
            }
        }
        $arr['data_type_label']      = $this->enum->columnDataType((string) ($arr['data_type'] ?? ''));
        $arr['aggregation_fn_label'] = $this->enum->aggregationFn((string) ($arr['aggregation_fn'] ?? 'NONE'));
        return $arr;
    }

    protected function shapeSubmission(object $r): array
    {
        return [
            'id'                 => (int) $r->id,
            'client_uuid'        => (string) ($r->client_uuid ?? ''),
            'country_code'       => (string) ($r->country_code ?? ''),
            'province_code'      => (string) ($r->province_code ?? ''),
            'district_code'      => (string) ($r->district_code ?? ''),
            'pheoc_code'         => (string) ($r->pheoc_code ?? ''),
            'poe_code'           => (string) ($r->poe_code ?? ''),
            'period_start'       => $r->period_start ?? null,
            'period_end'         => $r->period_end ?? null,
            'period_label'       => $r->period_start && $r->period_end
                ? Carbon::parse((string) $r->period_start)->format('M j') . ' – ' . Carbon::parse((string) $r->period_end)->format('M j, Y')
                : '—',
            'total_screened'     => (int) ($r->total_screened ?? 0),
            'total_male'         => (int) ($r->total_male ?? 0),
            'total_female'       => (int) ($r->total_female ?? 0),
            'total_symptomatic'  => (int) ($r->total_symptomatic ?? 0),
            'total_asymptomatic' => (int) ($r->total_asymptomatic ?? 0),
            'notes'              => (string) ($r->notes ?? ''),
            'template_id'        => $r->template_id ? (int) $r->template_id : null,
            'template_code'      => (string) ($r->template_code ?? ''),
            'template_name'      => (string) ($r->template_name ?? ''),
            'template_version'   => (int) ($r->template_version ?? 0),
            'device_id'          => (string) ($r->device_id ?? ''),
            'app_version'        => (string) ($r->app_version ?? ''),
            'platform'           => (string) ($r->platform ?? ''),
            'sync_status'        => (string) ($r->sync_status ?? 'SYNCED'),
            'sync_attempt_count' => (int) ($r->sync_attempt_count ?? 0),
            'last_sync_error'    => (string) ($r->last_sync_error ?? ''),
            'synced_at'          => $r->synced_at ?? null,
            'server_received_at' => $r->server_received_at ?? null,
            'submitted_by_user_id' => (int) ($r->submitted_by_user_id ?? 0),
            'submitted_by_name'  => (string) ($r->submitted_by_name ?? ''),
            'created_at'         => $r->created_at ?? null,
            'updated_at'         => $r->updated_at ?? null,
            'created_rel'        => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
        ];
    }

    /** Build "current reporting period" bounds based on frequency. */
    protected function currentPeriod(string $freq): array
    {
        $now = Carbon::now();
        switch ($freq) {
            case 'DAILY':
                return [$now->copy()->startOfDay()->format('Y-m-d H:i:s'),
                        $now->copy()->endOfDay()->format('Y-m-d H:i:s'),
                        $now->format('M j, Y')];
            case 'WEEKLY':
                $start = $now->copy()->startOfWeek();
                $end   = $now->copy()->endOfWeek();
                return [$start->format('Y-m-d H:i:s'),
                        $end->format('Y-m-d H:i:s'),
                        $start->format('M j') . ' – ' . $end->format('M j, Y')];
            case 'MONTHLY':
                $start = $now->copy()->startOfMonth();
                $end   = $now->copy()->endOfMonth();
                return [$start->format('Y-m-d H:i:s'),
                        $end->format('Y-m-d H:i:s'),
                        $start->format('F Y')];
            case 'QUARTERLY':
                $start = $now->copy()->firstOfQuarter();
                $end   = $now->copy()->lastOfQuarter()->endOfDay();
                return [$start->format('Y-m-d H:i:s'),
                        $end->format('Y-m-d H:i:s'),
                        'Q' . $start->quarter . ' ' . $start->year];
            default: // AD_HOC / EVENT — use last 30 days
                $start = $now->copy()->subDays(30);
                return [$start->format('Y-m-d H:i:s'),
                        $now->format('Y-m-d H:i:s'),
                        'Last 30 days'];
        }
    }

    protected function studioMeta(): array
    {
        return [
            'data_types'   => self::VALID_DATA_TYPES,
            'aggregations' => self::VALID_AGG_FN,
            'frequencies'  => self::VALID_FREQ,
            'categories'   => ['CORE', 'GENDER', 'AGE', 'SYMPTOMS', 'DISEASE', 'OUTCOMES', 'TRAVEL', 'CONVEYANCE', 'ENVIRONMENT', 'VACCINE', 'LAB', 'QUALITY', 'CUSTOM'],
            'statuses'     => self::VALID_STATUS,
            'data_type_labels' => [
                'INTEGER' => 'Whole number',
                'DECIMAL' => 'Decimal',
                'TEXT'    => 'Text',
                'BOOLEAN' => 'Yes / no',
                'DATE'    => 'Date',
                'PERCENT' => 'Percentage',
                'SELECT'  => 'Pick list',
            ],
        ];
    }

    protected function intelMeta(array $scope): array
    {
        $tplQ = DB::table('aggregated_templates')->whereNull('deleted_at')->orderBy('template_name');
        $this->applyCountryFilter($tplQ, $scope);
        $templates = $tplQ
            ->get(['id', 'template_code', 'template_name', 'status', 'reporting_frequency'])
            ->map(fn ($t) => [
                'id'            => (int) $t->id,
                'template_code' => (string) $t->template_code,
                'template_name' => (string) $t->template_name,
                'status'        => (string) $t->status,
                'frequency'     => (string) ($t->reporting_frequency ?? 'AD_HOC'),
            ])->all();

        $poeQ = DB::table('ref_poes as p')
            ->leftJoin('ref_districts as d', 'd.id', '=', 'p.district_id')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', 1)
            ->select([
                'p.poe_code',
                'p.poe_name',
                DB::raw('COALESCE(d.code, p.district) AS district_code'),
            ])
            ->orderBy('p.poe_name');
        $this->applyCountryFilter($poeQ, $scope, 'p.country_code');
        $poes = $poeQ->get()->map(fn ($p) => [
                'poe_code'      => (string) $p->poe_code,
                'poe_name'      => (string) $p->poe_name,
                'district_code' => (string) ($p->district_code ?? ''),
            ])->all();

        $distQ = DB::table('ref_districts')
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->orderBy('name');
        $this->applyCountryFilter($distQ, $scope);
        $districts = $distQ
            ->get(['code as district_code', 'name as district_name'])
            ->map(fn ($d) => [
                'district_code' => (string) $d->district_code,
                'district_name' => (string) $d->district_name,
            ])->all();

        return [
            'templates' => $templates,
            'poes'      => $poes,
            'districts' => $districts,
            'scope_label' => $scope['label'] ?? '—',
            'scope_level' => $scope['scope_level'] ?? 'NATIONAL',
            'sync_statuses' => self::VALID_SYNC_STATUSES,
        ];
    }

    protected function safeJson(string $raw): mixed
    {
        if ($raw === '') return null;
        $decoded = json_decode($raw, true);
        return $decoded === null ? null : $decoded;
    }

    /* ─── JSON envelopes ─── */

    protected function ok(array $data, string $message = 'OK', array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => $data];
        if ($meta) $body['meta'] = $meta;
        return response()->json($body, 200);
    }

    protected function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error' => $detail], $status);
    }

    protected function serverError(Throwable $e, string $ctx): JsonResponse
    {
        \Illuminate\Support\Facades\Log::error("[AdminAggregated][ERROR] {$ctx}", [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => basename($e->getFile()),
            'line'      => $e->getLine(),
        ]);
        return response()->json([
            'success' => false,
            'message' => "Server error: {$ctx}",
            'error'   => ['message' => $e->getMessage()],
        ], 500);
    }
}
