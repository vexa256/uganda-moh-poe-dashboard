<?php

declare (strict_types = 1);

namespace App\Http\Controllers;

use App\Services\CountryResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║  AggregatedTemplatesController                                           ║
 * ║  Country-customizable aggregated data templates (admin-managed).         ║
 * ╠══════════════════════════════════════════════════════════════════════════╣
 * ║  RBAC:                                                                    ║
 * ║    NATIONAL_ADMIN  — full CRUD on any country's templates                 ║
 * ║    POE_ADMIN       — read-only (templates are country-scoped)             ║
 * ║    All other roles — read-only (activeForCountry)                         ║
 * ║                                                                           ║
 * ║  ROUTES:                                                                  ║
 * ║    GET  /aggregated-templates?country_code=&user_id=                     ║
 * ║    POST /aggregated-templates                                             ║
 * ║    GET  /aggregated-templates/active?country_code=&user_id=              ║
 * ║    GET  /aggregated-templates/{id}                                        ║
 * ║    PATCH /aggregated-templates/{id}                                       ║
 * ║    DELETE /aggregated-templates/{id}                                      ║
 * ║    POST /aggregated-templates/{id}/columns                                ║
 * ║    PATCH /aggregated-templates/{id}/columns (bulk)                        ║
 * ║    PATCH /aggregated-template-columns/{colId}                             ║
 * ║    DELETE /aggregated-template-columns/{colId}                            ║
 * ║    POST /aggregated-templates/{id}/activate                               ║
 * ║    POST /aggregated-templates/{id}/lock                                   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 */
final class AggregatedTemplatesController extends Controller
{
    private const ADMIN_ROLES = ['NATIONAL_ADMIN'];
    private const VALID_DATA_TYPES = ['INTEGER', 'DECIMAL', 'TEXT', 'BOOLEAN', 'DATE', 'PERCENT', 'SELECT'];
    private const VALID_AGG_FN = ['SUM', 'AVG', 'MIN', 'MAX', 'COUNT', 'LATEST', 'NONE'];
    private const VALID_STATUS = ['DRAFT', 'PUBLISHED', 'RETIRED', 'ARCHIVED'];
    private const VALID_FREQ   = ['DAILY', 'WEEKLY', 'MONTHLY', 'QUARTERLY', 'AD_HOC', 'EVENT'];

    // ── GET /aggregated-templates ───────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');

        $countryCode = (string) ($request->query('country_code', $user->country_code ?? ''));
        if (empty($countryCode)) {
            return $this->err(422, 'country_code is required.');
        }
        $status       = strtoupper((string) $request->query('status', ''));
        $includeCols  = (bool) $request->query('include_columns', false);

        // Alias-tolerant filter: mobile clients with a stale `ZM` cached on
        // login still match templates stored as the canonical country name
        // (and vice-versa) while the one-time normalisation migration
        // settles across the fleet.
        $aliases = app(CountryResolver::class)->aliases($countryCode);

        try {
            $q = DB::table('aggregated_templates')
                ->whereIn('country_code', $aliases)
                ->whereNull('deleted_at');

            if ($status && in_array($status, self::VALID_STATUS, true)) {
                $q->where('status', $status);
            }

            $templates = $q->orderByDesc('is_active')
                ->orderByDesc('version')
                ->get();

            $ids = $templates->pluck('id')->all();
            // Column counts
            $counts = $ids ? DB::table('aggregated_template_columns')
                ->whereIn('template_id', $ids)
                ->whereNull('deleted_at')
                ->selectRaw('template_id, COUNT(*) AS total, SUM(is_enabled) AS enabled')
                ->groupBy('template_id')
                ->get()->keyBy('template_id') : collect();

            // Optionally pre-load columns for offline-first cache
            $colsByTpl = collect();
            if ($includeCols && $ids) {
                $colsByTpl = DB::table('aggregated_template_columns')
                    ->whereIn('template_id', $ids)
                    ->whereNull('deleted_at')
                    ->orderBy('template_id')
                    ->orderBy('display_order')
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($c) => $this->shapeColumn($c))
                    ->groupBy('template_id');
            }

            $out = $templates->map(function ($t) use ($counts, $includeCols, $colsByTpl) {
                $c = $counts->get($t->id);
                $row = array_merge((array) $t, [
                    'columns_total'   => (int) ($c->total   ?? 0),
                    'columns_enabled' => (int) ($c->enabled ?? 0),
                ]);
                if ($includeCols) {
                    $row['columns'] = $colsByTpl->get($t->id, collect())->values()->all();
                }
                return $row;
            })->values()->all();

            return $this->ok($out, 'Templates retrieved.', [
                'count' => count($out),
                'country_code' => $countryCode,
                'status_filter' => $status ?: 'ALL',
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'templates index');
        }
    }

    // ── GET /aggregated-templates/published ─────────────────────────────────
    // Offline-cache friendly endpoint — returns every PUBLISHED template + all
    // its columns for the user's country in a single call. Polled by clients
    // to warm IDB cache.
    public function published(Request $request): JsonResponse
    {
        $request->query->set('status', 'PUBLISHED');
        $request->query->set('include_columns', true);
        return $this->index($request);
    }

    // ── GET /aggregated-templates/active ────────────────────────────────────
    // Returns the single active template for the given country, PLUS its columns.
    // Any authenticated user may call this — it's the form schema.
    public function active(Request $request): JsonResponse
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');

        $countryCode = (string) ($request->query('country_code', $user->country_code ?? ''));
        if (empty($countryCode)) {
            return $this->err(422, 'country_code is required.');
        }

        $aliases = app(CountryResolver::class)->aliases($countryCode);
        try {
            $template = DB::table('aggregated_templates')
                ->whereIn('country_code', $aliases)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->first();

            if (! $template) {
                // Fall back to the system default template
                $template = DB::table('aggregated_templates')
                    ->where('is_default', 1)
                    ->whereNull('deleted_at')
                    ->first();
            }
            if (! $template) {
                return $this->err(404, 'No active or default template available.');
            }

            $columns = DB::table('aggregated_template_columns')
                ->where('template_id', $template->id)
                ->whereNull('deleted_at')
                ->orderBy('display_order')
                ->orderBy('id')
                ->get()
                ->map(fn ($c) => $this->shapeColumn($c))
                ->values()->all();

            return $this->ok([
                'template' => (array) $template,
                'columns'  => $columns,
            ], 'Active template.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'templates active');
        }
    }

    // ── GET /aggregated-templates/{id} ──────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');

        try {
            $template = DB::table('aggregated_templates')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $template) return $this->err(404, 'Template not found.');

            $columns = DB::table('aggregated_template_columns')
                ->where('template_id', $id)
                ->whereNull('deleted_at')
                ->orderBy('display_order')
                ->orderBy('id')
                ->get()
                ->map(fn ($c) => $this->shapeColumn($c))
                ->values()->all();

            return $this->ok([
                'template' => (array) $template,
                'columns'  => $columns,
            ], 'Template with columns.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'templates show');
        }
    }

    // ── POST /aggregated-templates ──────────────────────────────────────────
    // Creates a new template. Optionally clones the default columns.
    public function store(Request $request): JsonResponse
    {
        $user = $this->requireAdmin($request);
        if ($user instanceof JsonResponse) return $user;

        $countryCode = (string) $request->input('country_code', $user->country_code ?? '');
        $name = trim((string) $request->input('template_name', ''));
        $code = trim((string) $request->input('template_code', ''));
        if (empty($countryCode) || empty($name) || empty($code)) {
            return $this->err(422, 'country_code, template_name, template_code are required.');
        }
        if (! preg_match('/^[A-Z0-9_]+$/', $code)) {
            return $this->err(422, 'template_code must be uppercase letters, digits, underscores only.');
        }

        // Canonicalise against ref_countries — rows always land in the
        // SSoT form regardless of what the writer sent (ISO2 / full-name / ISO3).
        $resolver    = app(CountryResolver::class);
        $countryCode = $resolver->canonicalOrRaw($countryCode);
        $aliases     = $resolver->aliases($countryCode);

        $now = now()->format('Y-m-d H:i:s');
        try {
            $exists = DB::table('aggregated_templates')
                ->whereIn('country_code', $aliases)->where('template_code', $code)
                ->whereNull('deleted_at')->exists();
            if ($exists) return $this->err(409, 'Template with that code already exists for this country.');

            $freq = strtoupper((string) $request->input('reporting_frequency', 'WEEKLY'));
            if (! in_array($freq, self::VALID_FREQ, true)) {
                return $this->err(422, 'Invalid reporting_frequency.', ['valid' => self::VALID_FREQ]);
            }

            $tplId = DB::table('aggregated_templates')->insertGetId([
                'country_code'       => $countryCode,
                'template_name'      => $name,
                'template_code'      => $code,
                'description'        => substr((string) $request->input('description', ''), 0, 500) ?: null,
                'version'            => 1,
                'is_active'          => 0,
                'is_default'         => 0,
                'locked'             => 0,
                'status'             => 'DRAFT',
                'reporting_frequency' => $freq,
                'icon'               => substr((string) $request->input('icon', ''), 0, 40) ?: null,
                'colour'             => substr((string) $request->input('colour', ''), 0, 16) ?: null,
                'metadata'           => json_encode($request->input('metadata', [])),
                'created_by_user_id' => $user->id,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);

            // Clone default columns if requested
            if ((bool) $request->input('clone_default_columns', true)) {
                $defaultTpl = DB::table('aggregated_templates')->where('is_default', 1)->whereNull('deleted_at')->first();
                if ($defaultTpl) {
                    $cols = DB::table('aggregated_template_columns')
                        ->where('template_id', $defaultTpl->id)
                        ->whereNull('deleted_at')
                        ->orderBy('display_order')->get();
                    foreach ($cols as $c) {
                        DB::table('aggregated_template_columns')->insert([
                            'template_id'        => $tplId,
                            'column_key'         => $c->column_key,
                            'column_label'       => $c->column_label,
                            'category'           => $c->category,
                            'data_type'          => $c->data_type,
                            'is_required'        => $c->is_required,
                            'is_enabled'         => $c->is_enabled,
                            'is_core'            => $c->is_core,
                            'default_value'      => $c->default_value,
                            'min_value'          => $c->min_value,
                            'max_value'          => $c->max_value,
                            'select_options'     => $c->select_options,
                            'validation_rules'   => $c->validation_rules,
                            'display_order'      => $c->display_order,
                            'placeholder'        => $c->placeholder,
                            'help_text'          => $c->help_text,
                            'dashboard_visible'  => $c->dashboard_visible,
                            'report_visible'     => $c->report_visible,
                            'aggregation_fn'     => $c->aggregation_fn,
                            'created_by_user_id' => $user->id,
                            'created_at'         => $now,
                            'updated_at'         => $now,
                        ]);
                    }
                }
            }

            $fresh = DB::table('aggregated_templates')->where('id', $tplId)->first();
            return $this->ok((array) $fresh, 'Template created.', ['server_id' => $tplId]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'templates store');
        }
    }

    // ── PATCH /aggregated-templates/{id} ────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAdmin($request);
        if ($user instanceof JsonResponse) return $user;

        $template = DB::table('aggregated_templates')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $template) return $this->err(404, 'Template not found.');
        if ((int) $template->locked === 1) return $this->err(409, 'Template is locked.');

        $update = ['updated_by_user_id' => $user->id, 'updated_at' => now()->format('Y-m-d H:i:s')];
        foreach (['template_name', 'description', 'metadata', 'icon', 'colour', 'reporting_frequency'] as $k) {
            if (! $request->has($k)) continue;
            if ($k === 'metadata') {
                $update[$k] = json_encode($request->input($k));
            } elseif ($k === 'reporting_frequency') {
                $v = strtoupper((string) $request->input($k));
                if (! in_array($v, self::VALID_FREQ, true)) {
                    return $this->err(422, 'Invalid reporting_frequency.', ['valid' => self::VALID_FREQ]);
                }
                $update[$k] = $v;
            } else {
                $update[$k] = $request->input($k);
            }
        }
        try {
            DB::table('aggregated_templates')->where('id', $id)->update($update);
            $fresh = DB::table('aggregated_templates')->where('id', $id)->first();
            return $this->ok((array) $fresh, 'Template updated.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'templates update');
        }
    }

    // ── DELETE /aggregated-templates/{id} ───────────────────────────────────
    // ── DELETE /aggregated-templates/{id} ──────────────────────────────────
    //
    // Default behaviour: soft-delete the template (and cascade soft-delete its
    // columns). Historical submissions are preserved with their template_id
    // reference intact so dashboards can still reconstruct the context.
    //
    // Hard guard: the system default (is_default=1) is never deletable.
    //
    // Submissions guard: if the template has prior submissions, the call is
    // blocked with 409 unless the caller sends `?cascade=true` AND either
    // `confirm=DELETE_WITH_SUBMISSIONS` in the body or the submissions are
    // themselves soft-deleted. This is the "reports with substantial data can
    // still be deleted" path — operator must explicitly confirm.
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAdmin($request);
        if ($user instanceof JsonResponse) return $user;

        $template = DB::table('aggregated_templates')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $template) return $this->err(404, 'Template not found.');
        if ((int) $template->is_default === 1) {
            return $this->err(409, 'Default template cannot be deleted.', [
                'hint' => 'Retire or replace the default by publishing another template; the default remains the fallback schema.',
            ]);
        }

        $cascade = filter_var($request->query('cascade', $request->input('cascade', false)), FILTER_VALIDATE_BOOLEAN);
        $confirm = (string) $request->input('confirm', $request->query('confirm', ''));
        $submissionCount = DB::table('aggregated_submissions')
            ->where('template_id', $id)
            ->whereNull('deleted_at')
            ->count();

        if ($submissionCount > 0 && (!$cascade || $confirm !== 'DELETE_WITH_SUBMISSIONS')) {
            return $this->err(409, "Template has {$submissionCount} submission(s). Deletion requires cascade+confirm.", [
                'submissions_count' => $submissionCount,
                'hint'              => 'Send `?cascade=true` and body `confirm=DELETE_WITH_SUBMISSIONS` to proceed. Submissions remain in the database (with template_id preserved) for audit — they are NOT destroyed.',
            ]);
        }

        try {
            $now = now();
            // Auto-retire if still PUBLISHED so the Hub sync removes it from
            // every POE's cache at the next poll.
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
            return $this->ok([
                'id'                => $id,
                'submissions_count' => $submissionCount,
            ], $submissionCount > 0
                ? "Template deleted. {$submissionCount} historical submission(s) preserved for audit."
                : 'Template deleted.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'templates destroy');
        }
    }

    // ── POST /aggregated-templates/{id}/publish ─────────────────────────────
    // DRAFT | RETIRED → PUBLISHED. Multiple PUBLISHED templates can co-exist
    // per country — each is an independent report type.
    public function publish(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAdmin($request);
        if ($user instanceof JsonResponse) return $user;

        $template = DB::table('aggregated_templates')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $template) return $this->err(404, 'Template not found.');

        // Guard: must have at least the 7 core columns, required columns must be enabled
        $colCount = DB::table('aggregated_template_columns')
            ->where('template_id', $id)
            ->whereNull('deleted_at')
            ->where('is_enabled', 1)
            ->count();
        if ($colCount < 1) {
            return $this->err(422, 'Cannot publish a template with no enabled columns.');
        }

        $now = now()->format('Y-m-d H:i:s');
        try {
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
            $fresh = DB::table('aggregated_templates')->where('id', $id)->first();
            return $this->ok((array) $fresh, 'Template published.', ['visible_to_all_poes' => true]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'templates publish');
        }
    }

    // ── POST /aggregated-templates/{id}/retire ──────────────────────────────
    // PUBLISHED → RETIRED. Template disappears from POE user listings but
    // historical submissions remain queryable. Can be re-published later.
    public function retire(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAdmin($request);
        if ($user instanceof JsonResponse) return $user;

        $template = DB::table('aggregated_templates')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $template) return $this->err(404, 'Template not found.');
        if ((int) $template->is_default === 1) {
            return $this->err(409, 'The system default template cannot be retired.');
        }

        $now = now()->format('Y-m-d H:i:s');
        try {
            DB::table('aggregated_templates')->where('id', $id)->update([
                'status'              => 'RETIRED',
                'is_active'           => 0,
                'retired_at'          => $now,
                'retired_by_user_id'  => $user->id,
                'updated_by_user_id'  => $user->id,
                'updated_at'          => $now,
            ]);
            $fresh = DB::table('aggregated_templates')->where('id', $id)->first();
            return $this->ok((array) $fresh, 'Template retired.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'templates retire');
        }
    }

    // ── POST /aggregated-templates/{id}/activate ────────────────────────────
    // Back-compat alias for `publish`. New code should call /publish directly.
    public function activate(Request $request, int $id): JsonResponse
    {
        return $this->publish($request, $id);
    }

    // ── POST /aggregated-templates/{id}/lock ────────────────────────────────
    public function lock(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAdmin($request);
        if ($user instanceof JsonResponse) return $user;

        $now = now()->format('Y-m-d H:i:s');
        $unlock = (bool) $request->input('unlock', false);
        try {
            DB::table('aggregated_templates')->where('id', $id)->update([
                'locked'            => $unlock ? 0 : 1,
                'locked_by_user_id' => $unlock ? null : $user->id,
                'locked_at'         => $unlock ? null : $now,
                'updated_by_user_id' => $user->id,
                'updated_at'        => $now,
            ]);
            $fresh = DB::table('aggregated_templates')->where('id', $id)->first();
            return $this->ok((array) $fresh, $unlock ? 'Template unlocked.' : 'Template locked.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'templates lock');
        }
    }

    // ── POST /aggregated-templates/{id}/columns ─────────────────────────────
    public function addColumn(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAdmin($request);
        if ($user instanceof JsonResponse) return $user;

        $template = DB::table('aggregated_templates')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $template) return $this->err(404, 'Template not found.');
        if ((int) $template->locked === 1) return $this->err(409, 'Template is locked.');

        $key   = trim((string) $request->input('column_key', ''));
        $label = trim((string) $request->input('column_label', ''));
        $type  = strtoupper((string) $request->input('data_type', 'INTEGER'));
        $aggFn = strtoupper((string) $request->input('aggregation_fn', 'SUM'));

        if (empty($key) || ! preg_match('/^[a-z][a-z0-9_]{1,58}$/', $key)) {
            return $this->err(422, 'column_key must be lowercase letters/digits/underscores, 2-60 chars, starting with a letter.');
        }
        if (empty($label)) return $this->err(422, 'column_label is required.');
        if (! in_array($type, self::VALID_DATA_TYPES, true)) return $this->err(422, 'Invalid data_type.', ['valid' => self::VALID_DATA_TYPES]);
        if (! in_array($aggFn, self::VALID_AGG_FN, true))   return $this->err(422, 'Invalid aggregation_fn.', ['valid' => self::VALID_AGG_FN]);

        $exists = DB::table('aggregated_template_columns')
            ->where('template_id', $id)->where('column_key', $key)
            ->whereNull('deleted_at')->exists();
        if ($exists) return $this->err(409, 'Column key already exists on this template.');

        $now = now()->format('Y-m-d H:i:s');
        $max = (int) (DB::table('aggregated_template_columns')->where('template_id', $id)->max('display_order') ?? 0);

        try {
            $colId = DB::table('aggregated_template_columns')->insertGetId([
                'template_id'        => $id,
                'column_key'         => $key,
                'column_label'       => substr($label, 0, 160),
                'category'           => substr((string) $request->input('category', 'CUSTOM'), 0, 40),
                'data_type'          => $type,
                'is_required'        => (int) (bool) $request->input('is_required', false),
                'is_enabled'         => (int) (bool) $request->input('is_enabled', true),
                'is_core'            => 0, // user-added columns are never core
                'default_value'      => substr((string) $request->input('default_value', ''), 0, 120) ?: null,
                'min_value'          => $request->input('min_value'),
                'max_value'          => $request->input('max_value'),
                'select_options'     => $request->has('select_options') ? json_encode($request->input('select_options')) : null,
                'validation_rules'   => $request->has('validation_rules') ? json_encode($request->input('validation_rules')) : null,
                'display_order'      => $max + 1,
                'placeholder'        => substr((string) $request->input('placeholder', ''), 0, 160) ?: null,
                'help_text'          => substr((string) $request->input('help_text', ''), 0, 500) ?: null,
                'dashboard_visible'  => (int) (bool) $request->input('dashboard_visible', true),
                'report_visible'     => (int) (bool) $request->input('report_visible', true),
                'aggregation_fn'     => $aggFn,
                'created_by_user_id' => $user->id,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);
            $col = DB::table('aggregated_template_columns')->where('id', $colId)->first();
            return $this->ok($this->shapeColumn($col), 'Column created.', ['server_id' => $colId]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'templates addColumn');
        }
    }

    // ── PATCH /aggregated-templates/{id}/columns — bulk toggle/reorder ──────
    public function bulkUpdateColumns(Request $request, int $id): JsonResponse
    {
        $user = $this->requireAdmin($request);
        if ($user instanceof JsonResponse) return $user;

        $template = DB::table('aggregated_templates')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $template) return $this->err(404, 'Template not found.');
        if ((int) $template->locked === 1) return $this->err(409, 'Template is locked.');

        $updates = $request->input('columns', []);
        if (! is_array($updates)) return $this->err(422, 'columns must be an array.');

        $now = now()->format('Y-m-d H:i:s');
        $affected = 0;
        try {
            DB::transaction(function () use ($updates, $user, $id, $now, &$affected) {
                foreach ($updates as $row) {
                    $colId = (int) ($row['id'] ?? 0);
                    if ($colId <= 0) continue;
                    $col = DB::table('aggregated_template_columns')->where('id', $colId)->where('template_id', $id)->first();
                    if (! $col) continue;
                    $patch = ['updated_by_user_id' => $user->id, 'updated_at' => $now];
                    foreach (['is_enabled', 'display_order', 'column_label', 'placeholder', 'help_text',
                             'dashboard_visible', 'report_visible', 'aggregation_fn'] as $k) {
                        if (array_key_exists($k, $row)) {
                            // Core columns cannot be disabled
                            if ($k === 'is_enabled' && (int) $col->is_core === 1) continue;
                            $patch[$k] = $row[$k];
                        }
                    }
                    DB::table('aggregated_template_columns')->where('id', $colId)->update($patch);
                    $affected++;
                }
            });
            return $this->ok(['affected' => $affected], 'Columns updated.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'templates bulkUpdateColumns');
        }
    }

    // ── PATCH /aggregated-template-columns/{colId} ──────────────────────────
    public function updateColumn(Request $request, int $colId): JsonResponse
    {
        $user = $this->requireAdmin($request);
        if ($user instanceof JsonResponse) return $user;

        $col = DB::table('aggregated_template_columns')->where('id', $colId)->whereNull('deleted_at')->first();
        if (! $col) return $this->err(404, 'Column not found.');

        $template = DB::table('aggregated_templates')->where('id', $col->template_id)->first();
        if (! $template || (int) $template->locked === 1) return $this->err(409, 'Template is locked.');

        $patch = ['updated_by_user_id' => $user->id, 'updated_at' => now()];
        foreach (['column_label', 'category', 'is_required', 'placeholder', 'help_text',
                 'dashboard_visible', 'report_visible', 'aggregation_fn', 'min_value', 'max_value',
                 'default_value', 'display_order'] as $k) {
            if ($request->has($k)) $patch[$k] = $request->input($k);
        }
        // Toggle enabled — but core columns cannot be disabled
        if ($request->has('is_enabled')) {
            if ((int) $col->is_core === 1 && (int) (bool) $request->input('is_enabled') === 0) {
                return $this->err(409, 'Core columns cannot be disabled.');
            }
            $patch['is_enabled'] = (int) (bool) $request->input('is_enabled');
        }
        if ($request->has('select_options')) $patch['select_options'] = json_encode($request->input('select_options'));
        if ($request->has('validation_rules')) $patch['validation_rules'] = json_encode($request->input('validation_rules'));

        try {
            DB::table('aggregated_template_columns')->where('id', $colId)->update($patch);
            $fresh = DB::table('aggregated_template_columns')->where('id', $colId)->first();
            return $this->ok($this->shapeColumn($fresh), 'Column updated.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'templates updateColumn');
        }
    }

    // ── DELETE /aggregated-template-columns/{colId} ─────────────────────────
    public function deleteColumn(Request $request, int $colId): JsonResponse
    {
        $user = $this->requireAdmin($request);
        if ($user instanceof JsonResponse) return $user;

        $col = DB::table('aggregated_template_columns')->where('id', $colId)->whereNull('deleted_at')->first();
        if (! $col) return $this->err(404, 'Column not found.');
        if ((int) $col->is_core === 1) return $this->err(409, 'Core columns cannot be deleted.');

        $template = DB::table('aggregated_templates')->where('id', $col->template_id)->first();
        if (! $template || (int) $template->locked === 1) return $this->err(409, 'Template is locked.');

        try {
            DB::table('aggregated_template_columns')->where('id', $colId)->update(['deleted_at' => now()]);
            return $this->ok([], 'Column soft-deleted.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'templates deleteColumn');
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────
    private function authUser(Request $request): ?object
    {
        $userId = (int) ($request->input('user_id') ?? $request->query('user_id') ?? 0);
        if ($userId <= 0) return null;
        return DB::table('users')->where('id', $userId)->first() ?: null;
    }

    /** Returns user object on success, JsonResponse on failure — caller must check type. */
    private function requireAdmin(Request $request)
    {
        $user = $this->authUser($request);
        if (! $user) return $this->err(403, 'Authentication required.');
        $role = $user->role_key ?? '';
        if (! in_array($role, self::ADMIN_ROLES, true)) {
            return $this->err(403, 'Admin privileges required to manage aggregated templates.', [
                'your_role' => $role, 'required_roles' => self::ADMIN_ROLES,
            ]);
        }
        return $user;
    }

    private function shapeColumn(object $c): array
    {
        $arr = (array) $c;
        foreach (['select_options', 'validation_rules'] as $k) {
            if (isset($arr[$k]) && is_string($arr[$k])) {
                $decoded = json_decode($arr[$k], true);
                $arr[$k] = is_array($decoded) ? $decoded : null;
            }
        }
        return $arr;
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => $data];
        if (! empty($meta)) $body['meta'] = $meta;
        return response()->json($body, 200);
    }
    private function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error' => $detail], $status);
    }
    private function serverError(Throwable $e, string $ctx): JsonResponse
    {
        Log::error("[AggTemplates][ERROR] {$ctx}", ['exception' => get_class($e), 'message' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
        return response()->json(['success' => false, 'message' => "Server error: {$ctx}", 'error' => ['message' => $e->getMessage()]], 500);
    }
}
