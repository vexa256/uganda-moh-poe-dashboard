<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ReportEngine
 * ===========================================================================
 * Dynamic analytics engine for IDSR aggregated templates.
 *
 * Given a template_id and a scope, it:
 *   1. Loads the template + its column definitions
 *   2. Loads every in-scope submission and its per-column values
 *   3. For each column, produces type-appropriate analytics:
 *        INTEGER / DECIMAL / PERCENT → summary (sum/avg/min/max/median/stdev)
 *                                      + time series (bucketed by frequency)
 *                                      + per-POE, per-district breakdown
 *                                      + outlier detection (z-score > 3)
 *                                      + trend slope + classification
 *        BOOLEAN (stored as 0/1 in value_numeric)
 *                                    → yes/no distribution + time series
 *        SELECT                       → option distribution (pie)
 *                                      + per-option time series
 *        DATE                         → histogram + recency stats
 *        TEXT                         → top-N most frequent values
 *   4. Core totals (the fixed aggregated_submissions columns) get the full
 *      numeric treatment too — so every screened/male/female/symptomatic
 *      row is visible, charted, and compared.
 *   5. Coverage analysis — per-period reporting rate, missing POEs,
 *      latest-submission freshness.
 *   6. Anomaly list — submissions whose gender totals don't sum to
 *      total_screened, or whose symptomatic+asymptomatic ≠ total_screened.
 *
 * Scope-aware: every query is gated by the caller's scope via
 * `applyScopeFilter()`, mirroring the mobile AggregatedController's
 * POE / DISTRICT / PHEOC / NATIONAL jurisdictional tiers, and
 * `applyCountryFilter()` via CountryResolver for alias-tolerance.
 *
 * Zero animation directive honoured — the engine returns flat data;
 * the view renders Chart.js with animation:false.
 */
final class ReportEngine
{
    public function __construct(
        protected CountryResolver $countries,
    ) {
    }

    /**
     * Full analysis bundle for a single template in a caller's scope.
     * Shape is documented inline — stable for the admin blade to consume.
     */
    public function analyze(int $templateId, array $scope, array $filters = []): array
    {
        $template = DB::table('aggregated_templates')
            ->where('id', $templateId)
            ->whereNull('deleted_at')
            ->first();
        if (! $template) {
            return ['error' => 'Template not found', 'template' => null];
        }

        // Country + scope gate on template itself
        if (! $this->templateInScope($template, $scope)) {
            return ['error' => 'Template out of scope', 'template' => null];
        }

        $columns = DB::table('aggregated_template_columns')
            ->where('template_id', $templateId)
            ->whereNull('deleted_at')
            ->orderBy('display_order')
            ->orderBy('id')
            ->get();

        // Build submission query
        $subQ = DB::table('aggregated_submissions as ag')
            ->leftJoin('users as u', 'u.id', '=', 'ag.submitted_by_user_id')
            ->where('ag.template_id', $templateId)
            ->whereNull('ag.deleted_at');
        $this->applyCountry($subQ, $scope, 'ag.country_code');
        $this->applyScope($subQ, $scope, 'ag');
        $this->applyFilters($subQ, $filters, 'ag');

        $submissions = $subQ
            ->select([
                'ag.id', 'ag.client_uuid', 'ag.country_code', 'ag.province_code',
                'ag.district_code', 'ag.pheoc_code', 'ag.poe_code',
                'ag.period_start', 'ag.period_end',
                'ag.total_screened', 'ag.total_male', 'ag.total_female',
                'ag.total_other', 'ag.total_unknown_gender',
                'ag.total_symptomatic', 'ag.total_asymptomatic',
                'ag.notes', 'ag.template_version',
                'ag.platform', 'ag.app_version', 'ag.sync_status',
                'ag.synced_at', 'ag.server_received_at',
                'ag.submitted_by_user_id', 'ag.created_at', 'ag.updated_at',
                'u.full_name as submitted_by_name',
            ])
            ->orderBy('ag.period_end', 'desc')
            ->get();

        $submissionIds = $submissions->pluck('id')->all();

        // Load every per-column value for these submissions in one go
        $valuesGrouped = collect();
        if (! empty($submissionIds)) {
            $valuesGrouped = DB::table('aggregated_submission_values')
                ->whereIn('submission_id', $submissionIds)
                ->get()
                ->groupBy('column_key');
        }

        $freq = strtoupper((string) ($template->reporting_frequency ?? 'WEEKLY'));

        // Build per-column analytics
        $columnReports = [];
        foreach ($columns as $col) {
            $values = $valuesGrouped->get($col->column_key, collect());
            $columnReports[] = $this->analyzeColumn($col, $submissions, $values, $freq);
        }

        // Core fixed-column analytics (total_screened + gender + symptomatic)
        $coreReports = $this->analyzeCoreColumns($submissions, $freq);

        $coverage = $this->coverage($submissions, $template, $scope, $freq);
        $anomalies = $this->anomalies($submissions);

        return [
            'template' => [
                'id'                  => (int) $template->id,
                'template_name'       => (string) $template->template_name,
                'template_code'       => (string) $template->template_code,
                'description'         => (string) ($template->description ?? ''),
                'status'              => (string) $template->status,
                'reporting_frequency' => $freq,
                'version'             => (int) $template->version,
                'colour'              => (string) ($template->colour ?? '#10B981'),
                'published_at'        => $template->published_at,
                'country_code'        => (string) $template->country_code,
            ],
            'scope_label' => (string) ($scope['label'] ?? '—'),
            'filters'     => $filters,
            'summary' => [
                'submissions'         => $submissions->count(),
                'poes_reporting'      => $submissions->pluck('poe_code')->unique()->filter()->count(),
                'districts_reporting' => $submissions->pluck('district_code')->unique()->filter()->count(),
                'provinces_reporting' => $submissions->pluck('province_code')->unique()->filter()->count(),
                'earliest_period'     => $submissions->pluck('period_start')->min(),
                'latest_period'       => $submissions->pluck('period_end')->max(),
                'latest_received'     => $submissions->pluck('server_received_at')->filter()->max(),
                'period_span_days'    => $this->periodSpanDays($submissions),
                'template_versions'   => $submissions->pluck('template_version')->unique()->sort()->values()->all(),
                'platform_mix'        => $submissions->groupBy('platform')->map->count()->toArray(),
            ],
            'core_columns' => $coreReports,
            'columns'      => $columnReports,
            'coverage'     => $coverage,
            'anomalies'    => $anomalies,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /* ═════════════════════════════════════════════════════════════════════
     * Per-column analysis dispatcher — branches on data_type
     * ═════════════════════════════════════════════════════════════════════ */
    protected function analyzeColumn(object $col, Collection $submissions, Collection $values, string $freq): array
    {
        $type = strtoupper((string) $col->data_type);
        $agg  = strtoupper((string) ($col->aggregation_fn ?: 'SUM'));

        // Index values by submission_id for quick joins
        $valueBySub = $values->keyBy('submission_id');

        $base = [
            'id'              => (int) $col->id,
            'column_key'      => (string) $col->column_key,
            'column_label'    => (string) $col->column_label,
            'category'        => (string) $col->category,
            'data_type'       => $type,
            'aggregation_fn'  => $agg,
            'is_required'     => (bool) $col->is_required,
            'is_enabled'      => (bool) $col->is_enabled,
            'is_core'         => (bool) $col->is_core,
            'help_text'       => (string) ($col->help_text ?? ''),
            'display_order'   => (int) $col->display_order,
            'response_count'  => $values->count(),
            'fill_rate_pct'   => $submissions->count() > 0
                ? round(($values->count() / $submissions->count()) * 100, 1)
                : 0,
            'select_options'  => $this->decodeSelectOptions($col->select_options ?? null),
        ];

        switch ($type) {
            case 'INTEGER':
            case 'DECIMAL':
            case 'PERCENT':
                return array_merge($base, $this->analyzeNumeric($submissions, $valueBySub, 'numeric', $col->column_key, $agg, $freq));
            case 'BOOLEAN':
                return array_merge($base, $this->analyzeBoolean($submissions, $valueBySub, $freq));
            case 'SELECT':
                return array_merge($base, $this->analyzeSelect($submissions, $valueBySub, $base['select_options'] ?? [], $freq));
            case 'DATE':
                return array_merge($base, $this->analyzeDate($values, $submissions));
            case 'TEXT':
                return array_merge($base, $this->analyzeText($values));
            default:
                return array_merge($base, ['kind' => 'unknown']);
        }
    }

    /* ─── Numeric (INTEGER / DECIMAL / PERCENT) ─── */
    protected function analyzeNumeric(Collection $submissions, Collection $valueBySub, string $source, string $columnKey, string $agg, string $freq): array
    {
        $nums = $valueBySub->map(fn ($v) => $v->value_numeric !== null ? (float) $v->value_numeric : null)
                            ->filter(fn ($x) => $x !== null)
                            ->values();

        $summary = $this->numericStats($nums);

        // Time series bucketed by submission period_end
        $bucketSums = [];
        foreach ($submissions as $s) {
            $v = $valueBySub->get($s->id);
            if (! $v || $v->value_numeric === null) continue;
            $bucket = $this->bucketOf((string) $s->period_end, $freq);
            if (! isset($bucketSums[$bucket])) {
                $bucketSums[$bucket] = ['values' => [], 'count' => 0];
            }
            $bucketSums[$bucket]['values'][] = (float) $v->value_numeric;
            $bucketSums[$bucket]['count']++;
        }
        ksort($bucketSums);
        $timeSeries = [];
        foreach ($bucketSums as $bucket => $data) {
            $timeSeries[] = [
                'bucket'      => $bucket,
                'value'       => $this->applyAgg($data['values'], $agg),
                'submissions' => $data['count'],
            ];
        }

        $byPoe      = $this->groupAgg($submissions, $valueBySub, 'poe_code', $agg);
        $byDistrict = $this->groupAgg($submissions, $valueBySub, 'district_code', $agg);

        $outliers = $this->detectOutliers($submissions, $valueBySub, $summary);

        return [
            'kind'        => 'numeric',
            'summary'     => $summary,
            'time_series' => $timeSeries,
            'by_poe'      => $byPoe,
            'by_district' => $byDistrict,
            'outliers'    => $outliers,
            'trend'       => $this->trend($timeSeries),
        ];
    }

    /* ─── Boolean (stored as 0 / 1 in value_numeric) ─── */
    protected function analyzeBoolean(Collection $submissions, Collection $valueBySub, string $freq): array
    {
        $yes = 0; $no = 0;
        $bucketSeries = []; // bucket => ['yes' => n, 'no' => n]
        foreach ($submissions as $s) {
            $v = $valueBySub->get($s->id);
            if (! $v) continue;
            $bool = null;
            if ($v->value_numeric !== null) $bool = ((float) $v->value_numeric) == 1.0;
            elseif ($v->value_text !== null) $bool = in_array(strtolower((string) $v->value_text), ['1','true','yes','y','t'], true);
            if ($bool === null) continue;
            if ($bool) $yes++; else $no++;
            $bucket = $this->bucketOf((string) $s->period_end, $freq);
            if (! isset($bucketSeries[$bucket])) $bucketSeries[$bucket] = ['bucket' => $bucket, 'yes' => 0, 'no' => 0];
            $bucketSeries[$bucket][$bool ? 'yes' : 'no']++;
        }
        ksort($bucketSeries);
        $total = $yes + $no;
        return [
            'kind'        => 'boolean',
            'yes'         => $yes,
            'no'          => $no,
            'total'       => $total,
            'yes_pct'     => $total > 0 ? round(($yes / $total) * 100, 1) : 0,
            'time_series' => array_values($bucketSeries),
        ];
    }

    /* ─── Select (pick-list distribution) ─── */
    protected function analyzeSelect(Collection $submissions, Collection $valueBySub, array $options, string $freq): array
    {
        $dist = []; // option => count
        $bucketOptionCounts = []; // bucket => option => count
        foreach ($submissions as $s) {
            $v = $valueBySub->get($s->id);
            if (! $v) continue;
            $opt = (string) ($v->value_text ?? '');
            if ($opt === '') continue;
            $dist[$opt] = ($dist[$opt] ?? 0) + 1;
            $bucket = $this->bucketOf((string) $s->period_end, $freq);
            $bucketOptionCounts[$bucket][$opt] = ($bucketOptionCounts[$bucket][$opt] ?? 0) + 1;
        }
        arsort($dist);
        $distribution = [];
        foreach ($dist as $opt => $cnt) {
            $distribution[] = ['label' => $opt, 'count' => $cnt];
        }
        ksort($bucketOptionCounts);
        $timeSeries = [];
        foreach ($bucketOptionCounts as $b => $counts) {
            $timeSeries[] = ['bucket' => $b, 'counts' => $counts];
        }

        $total = array_sum($dist);
        return [
            'kind'         => 'select',
            'distribution' => $distribution,
            'total'        => $total,
            'option_count' => count($dist),
            'time_series'  => $timeSeries,
            'known_options'=> $options,
        ];
    }

    /* ─── Date column ─── */
    protected function analyzeDate(Collection $values, Collection $submissions): array
    {
        $timestamps = $values->map(fn ($v) => $v->value_text)
                             ->filter()
                             ->map(fn ($s) => strtotime((string) $s) ?: null)
                             ->filter()
                             ->values();
        if ($timestamps->count() === 0) {
            return ['kind' => 'date', 'count' => 0, 'histogram' => []];
        }
        // Monthly histogram
        $hist = [];
        foreach ($timestamps as $t) {
            $k = date('Y-m', $t);
            $hist[$k] = ($hist[$k] ?? 0) + 1;
        }
        ksort($hist);
        $histogram = [];
        foreach ($hist as $k => $v) $histogram[] = ['bucket' => $k, 'count' => $v];

        return [
            'kind'      => 'date',
            'count'     => $timestamps->count(),
            'min_date'  => date('Y-m-d', $timestamps->min()),
            'max_date'  => date('Y-m-d', $timestamps->max()),
            'histogram' => $histogram,
        ];
    }

    /* ─── Free-text column ─── */
    protected function analyzeText(Collection $values): array
    {
        $freq = [];
        $unique = 0;
        $lengths = [];
        foreach ($values as $v) {
            $t = trim((string) ($v->value_text ?? ''));
            if ($t === '') continue;
            $lengths[] = mb_strlen($t);
            $key = mb_strtolower(mb_substr($t, 0, 200));
            $freq[$key] = ($freq[$key] ?? 0) + 1;
        }
        arsort($freq);
        $top = [];
        $i = 0;
        foreach ($freq as $k => $c) {
            $top[] = ['value' => $k, 'count' => $c];
            if (++$i >= 15) break;
        }
        $unique = count($freq);
        $total = array_sum($freq);
        return [
            'kind'          => 'text',
            'total'         => $total,
            'unique_values' => $unique,
            'avg_length'    => $lengths ? round(array_sum($lengths) / count($lengths), 1) : 0,
            'top_values'    => $top,
        ];
    }

    /* ═════════════════════════════════════════════════════════════════════
     * Core fixed-column analytics (aggregated_submissions native columns)
     * ═════════════════════════════════════════════════════════════════════ */
    protected function analyzeCoreColumns(Collection $submissions, string $freq): array
    {
        $cols = [
            ['key' => 'total_screened',    'label' => 'Total screened',    'category' => 'CORE',    'agg' => 'SUM'],
            ['key' => 'total_male',        'label' => 'Male',              'category' => 'GENDER',  'agg' => 'SUM'],
            ['key' => 'total_female',      'label' => 'Female',            'category' => 'GENDER',  'agg' => 'SUM'],
            ['key' => 'total_symptomatic', 'label' => 'Symptomatic',       'category' => 'SYMPTOMS','agg' => 'SUM'],
            ['key' => 'total_asymptomatic','label' => 'Asymptomatic',      'category' => 'SYMPTOMS','agg' => 'SUM'],
        ];
        $out = [];
        foreach ($cols as $c) {
            $nums = $submissions->pluck($c['key'])->map(fn ($x) => (float) ($x ?? 0))->values();
            $summary = $this->numericStats($nums);

            // Time series
            $bucketData = [];
            foreach ($submissions as $s) {
                $b = $this->bucketOf((string) $s->period_end, $freq);
                if (! isset($bucketData[$b])) $bucketData[$b] = ['values' => [], 'count' => 0];
                $bucketData[$b]['values'][] = (float) ($s->{$c['key']} ?? 0);
                $bucketData[$b]['count']++;
            }
            ksort($bucketData);
            $ts = [];
            foreach ($bucketData as $b => $d) $ts[] = [
                'bucket' => $b,
                'value'  => $this->applyAgg($d['values'], $c['agg']),
                'submissions' => $d['count'],
            ];

            $byPoe      = $this->groupAggCore($submissions, 'poe_code', $c['key'], $c['agg']);
            $byDistrict = $this->groupAggCore($submissions, 'district_code', $c['key'], $c['agg']);

            $out[] = [
                'column_key'    => $c['key'],
                'column_label'  => $c['label'],
                'category'      => $c['category'],
                'data_type'     => 'INTEGER',
                'aggregation_fn'=> $c['agg'],
                'is_core'       => true,
                'summary'       => $summary,
                'time_series'   => $ts,
                'by_poe'        => $byPoe,
                'by_district'   => $byDistrict,
                'trend'         => $this->trend($ts),
            ];
        }
        return $out;
    }

    /* ═════════════════════════════════════════════════════════════════════
     * Coverage — per-period reporting rate + freshness
     * ═════════════════════════════════════════════════════════════════════ */
    protected function coverage(Collection $submissions, object $template, array $scope, string $freq): array
    {
        // Expected POEs in scope. Must mirror the jurisdictional filter applied
        // to submissions so coverage math compares like-for-like — without this,
        // a DISTRICT or PHEOC user sees "reported" counts from their districts
        // over "expected" counts for the whole country and coverage looks
        // artificially awful.
        $expectedQ = DB::table('ref_poes as p')
            ->leftJoin('ref_districts as d', 'd.id', '=', 'p.district_id')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', 1);
        $this->applyCountry($expectedQ, $scope, 'p.country_code');
        if (! ($scope['is_super'] ?? false)) {
            $lvl = (string) ($scope['scope_level'] ?? 'NATIONAL');
            if ($lvl === 'POE' && ! empty($scope['poes'])) {
                $expectedQ->whereIn('p.poe_code', $scope['poes']);
            } elseif ($lvl === 'DISTRICT' && ! empty($scope['districts'])) {
                $expectedQ->where(function ($w) use ($scope) {
                    $w->whereIn('d.code', $scope['districts'])
                      ->orWhereIn('p.district', $scope['districts']);
                });
            } elseif ($lvl === 'PHEOC' && ! empty($scope['provinces'])) {
                $expectedQ->whereIn('d.province_code', $scope['provinces']);
            }
        }
        $expectedPoes = $expectedQ->pluck('p.poe_code')->unique()->values();
        $expectedCount = max(1, $expectedPoes->count());

        // Bucketize submissions by period, count unique POEs per bucket
        $perBucket = [];
        foreach ($submissions as $s) {
            $b = $this->bucketOf((string) $s->period_end, $freq);
            if (! isset($perBucket[$b])) $perBucket[$b] = ['poes' => []];
            $perBucket[$b]['poes'][$s->poe_code] = true;
        }
        ksort($perBucket);

        $periods = [];
        foreach ($perBucket as $b => $d) {
            $reported = count($d['poes']);
            $periods[] = [
                'bucket'         => $b,
                'reported'       => $reported,
                'expected'       => $expectedCount,
                'coverage_pct'   => round(($reported / $expectedCount) * 100, 1),
                'missing_poes'   => array_values($expectedPoes->diff(array_keys($d['poes']))->all()),
            ];
        }

        // Latest-reported vs expected for the CURRENT period
        $currentReporting = [];
        $latestBucket = end($periods);
        if ($latestBucket !== false) {
            $currentReporting = $latestBucket;
        }

        return [
            'expected_poes' => $expectedPoes->values()->all(),
            'expected_count'=> $expectedCount,
            'periods'       => $periods,
            'latest_period' => $currentReporting,
            'overall_coverage_pct' => count($periods) > 0
                ? round(collect($periods)->avg('coverage_pct'), 1)
                : 0,
        ];
    }

    /* ═════════════════════════════════════════════════════════════════════
     * Anomalies — rows where totals don't add up
     * ═════════════════════════════════════════════════════════════════════ */
    protected function anomalies(Collection $submissions): array
    {
        $items = [];
        foreach ($submissions as $s) {
            $screened   = (int) ($s->total_screened ?? 0);
            $male       = (int) ($s->total_male ?? 0);
            $female     = (int) ($s->total_female ?? 0);
            $other      = (int) ($s->total_other ?? 0);
            $unknown    = (int) ($s->total_unknown_gender ?? 0);
            $symp       = (int) ($s->total_symptomatic ?? 0);
            $asymp      = (int) ($s->total_asymptomatic ?? 0);

            $issues = [];
            $genderSum = $male + $female + $other + $unknown;
            if ($genderSum !== $screened) {
                $issues[] = sprintf('gender totals (%d) ≠ screened (%d)', $genderSum, $screened);
            }
            $sympSum = $symp + $asymp;
            if ($sympSum !== $screened) {
                $issues[] = sprintf('symptomatic+asymptomatic (%d) ≠ screened (%d)', $sympSum, $screened);
            }
            if ($screened < 0 || $male < 0 || $female < 0 || $symp < 0) {
                $issues[] = 'negative value(s)';
            }
            if (empty($issues)) continue;

            $items[] = [
                'submission_id' => (int) $s->id,
                'poe_code'      => (string) $s->poe_code,
                'district_code' => (string) $s->district_code,
                'period_label'  => $s->period_start && $s->period_end
                    ? Carbon::parse((string) $s->period_start)->format('M j') . ' – ' . Carbon::parse((string) $s->period_end)->format('M j, Y')
                    : '—',
                'submitted_by'  => (string) ($s->submitted_by_name ?? ''),
                'issues'        => $issues,
                'screened'      => $screened,
                'gender_sum'    => $genderSum,
                'symp_sum'      => $sympSum,
            ];
        }
        return $items;
    }

    /* ═════════════════════════════════════════════════════════════════════
     * Helpers — stats, buckets, scope, country
     * ═════════════════════════════════════════════════════════════════════ */
    protected function numericStats(Collection $nums): array
    {
        $n = $nums->count();
        if ($n === 0) {
            return ['count' => 0, 'sum' => 0, 'avg' => 0, 'min' => 0, 'max' => 0, 'median' => 0, 'stdev' => 0];
        }
        $sorted = $nums->values()->sort()->values();
        $sum = $sorted->sum();
        $avg = $sum / $n;
        $mid = intdiv($n, 2);
        $median = ($n % 2 === 0)
            ? ($sorted[$mid - 1] + $sorted[$mid]) / 2
            : $sorted[$mid];
        $variance = $nums->reduce(fn ($carry, $v) => $carry + (($v - $avg) ** 2), 0) / $n;
        return [
            'count' => $n,
            'sum'   => round($sum, 2),
            'avg'   => round($avg, 2),
            'min'   => round($sorted->first(), 2),
            'max'   => round($sorted->last(), 2),
            'median'=> round($median, 2),
            'stdev' => round(sqrt($variance), 2),
        ];
    }

    protected function applyAgg(array $values, string $fn): float
    {
        if (empty($values)) return 0;
        return match (strtoupper($fn)) {
            'SUM'    => array_sum($values),
            'AVG'    => array_sum($values) / count($values),
            'MIN'    => min($values),
            'MAX'    => max($values),
            'COUNT'  => count($values),
            'LATEST' => end($values),
            default  => array_sum($values),
        };
    }

    protected function groupAgg(Collection $submissions, Collection $valueBySub, string $groupKey, string $agg): array
    {
        $buckets = [];
        foreach ($submissions as $s) {
            $v = $valueBySub->get($s->id);
            if (! $v || $v->value_numeric === null) continue;
            $k = (string) ($s->$groupKey ?? '—');
            if (! isset($buckets[$k])) $buckets[$k] = ['values' => [], 'count' => 0];
            $buckets[$k]['values'][] = (float) $v->value_numeric;
            $buckets[$k]['count']++;
        }
        $out = [];
        foreach ($buckets as $k => $d) {
            $out[] = [
                'label'       => $k,
                'value'       => round($this->applyAgg($d['values'], $agg), 2),
                'submissions' => $d['count'],
            ];
        }
        usort($out, fn ($a, $b) => $b['value'] <=> $a['value']);
        return array_slice($out, 0, 30);
    }

    protected function groupAggCore(Collection $submissions, string $groupKey, string $valueKey, string $agg): array
    {
        $buckets = [];
        foreach ($submissions as $s) {
            $k = (string) ($s->$groupKey ?? '—');
            $v = (float) ($s->$valueKey ?? 0);
            if (! isset($buckets[$k])) $buckets[$k] = ['values' => [], 'count' => 0];
            $buckets[$k]['values'][] = $v;
            $buckets[$k]['count']++;
        }
        $out = [];
        foreach ($buckets as $k => $d) {
            $out[] = [
                'label'       => $k,
                'value'       => round($this->applyAgg($d['values'], $agg), 2),
                'submissions' => $d['count'],
            ];
        }
        usort($out, fn ($a, $b) => $b['value'] <=> $a['value']);
        return array_slice($out, 0, 30);
    }

    protected function detectOutliers(Collection $submissions, Collection $valueBySub, array $summary): array
    {
        if ($summary['count'] < 4 || $summary['stdev'] == 0) return [];
        $out = [];
        foreach ($submissions as $s) {
            $v = $valueBySub->get($s->id);
            if (! $v || $v->value_numeric === null) continue;
            $x = (float) $v->value_numeric;
            $z = ($x - $summary['avg']) / $summary['stdev'];
            if (abs($z) >= 3) {
                $out[] = [
                    'submission_id' => (int) $s->id,
                    'poe_code'      => (string) ($s->poe_code ?? ''),
                    'district_code' => (string) ($s->district_code ?? ''),
                    'period_label'  => $s->period_start && $s->period_end
                        ? Carbon::parse((string) $s->period_start)->format('M j') . ' – ' . Carbon::parse((string) $s->period_end)->format('M j, Y')
                        : '—',
                    'value'         => round($x, 2),
                    'zscore'        => round($z, 2),
                    'direction'     => $z > 0 ? 'high' : 'low',
                ];
            }
        }
        usort($out, fn ($a, $b) => abs($b['zscore']) <=> abs($a['zscore']));
        return array_slice($out, 0, 15);
    }

    protected function trend(array $timeSeries): array
    {
        $n = count($timeSeries);
        if ($n < 2) return ['direction' => 'insufficient_data', 'slope' => 0, 'change_pct' => 0];

        // Linear regression on index vs value
        $sumX = 0; $sumY = 0; $sumXY = 0; $sumX2 = 0;
        foreach ($timeSeries as $i => $p) {
            $x = $i; $y = (float) $p['value'];
            $sumX += $x; $sumY += $y; $sumXY += $x * $y; $sumX2 += $x * $x;
        }
        $denom = ($n * $sumX2) - ($sumX * $sumX);
        $slope = $denom != 0 ? (($n * $sumXY) - ($sumX * $sumY)) / $denom : 0;

        $first = (float) $timeSeries[0]['value'];
        $last  = (float) $timeSeries[$n - 1]['value'];
        $pct   = $first != 0 ? round((($last - $first) / abs($first)) * 100, 1) : ($last > 0 ? 100 : 0);
        $direction = abs($slope) < 0.001 ? 'stable' : ($slope > 0 ? 'rising' : 'falling');
        return ['direction' => $direction, 'slope' => round($slope, 3), 'change_pct' => $pct];
    }

    protected function bucketOf(string $period, string $freq): string
    {
        if ($period === '') return 'unknown';
        $c = Carbon::parse($period);
        return match (strtoupper($freq)) {
            'DAILY'     => $c->format('Y-m-d'),
            'WEEKLY'    => $c->format('o-\WW'),     // ISO week
            'MONTHLY'   => $c->format('Y-m'),
            'QUARTERLY' => $c->format('Y') . '-Q' . $c->quarter,
            default     => $c->format('Y-m'),
        };
    }

    protected function periodSpanDays(Collection $submissions): int
    {
        if ($submissions->isEmpty()) return 0;
        $min = $submissions->pluck('period_start')->filter()->min();
        $max = $submissions->pluck('period_end')->filter()->max();
        if (! $min || ! $max) return 0;
        return (int) Carbon::parse((string) $min)->diffInDays(Carbon::parse((string) $max));
    }

    protected function decodeSelectOptions($raw): array
    {
        if (! $raw) return [];
        if (is_array($raw)) return $raw;
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function applyCountry($query, array $scope, string $column = 'country_code'): void
    {
        $raw = (string) ($scope['country_code'] ?? '');
        if ($raw === '') return;
        $aliases = $this->countries->aliases($raw);
        if (empty($aliases)) return;
        $query->whereIn($column, $aliases);
    }

    protected function applyScope($query, array $scope, string $alias = ''): void
    {
        if (! empty($scope['is_super'])) return;
        $col = $alias ? "{$alias}." : '';
        $lvl = (string) ($scope['scope_level'] ?? 'NATIONAL');
        if ($lvl === 'POE' && ! empty($scope['poes'])) {
            $query->whereIn($col . 'poe_code', $scope['poes']);
        } elseif ($lvl === 'DISTRICT' && ! empty($scope['districts'])) {
            $query->whereIn($col . 'district_code', $scope['districts']);
        } elseif ($lvl === 'PHEOC' && ! empty($scope['provinces'])) {
            $query->whereIn($col . 'province_code', $scope['provinces']);
        }
    }

    protected function applyFilters($query, array $filters, string $alias): void
    {
        $col = $alias ? "{$alias}." : '';
        if (! empty($filters['date_from'])) $query->where($col . 'period_start', '>=', substr((string) $filters['date_from'], 0, 10) . ' 00:00:00');
        if (! empty($filters['date_to']))   $query->where($col . 'period_end',   '<=', substr((string) $filters['date_to'], 0, 10) . ' 23:59:59');
        if (! empty($filters['poe_code']))      $query->where($col . 'poe_code', $filters['poe_code']);
        if (! empty($filters['district_code'])) $query->where($col . 'district_code', $filters['district_code']);
        if (! empty($filters['province_code'])) $query->where($col . 'province_code', $filters['province_code']);
    }

    protected function templateInScope(object $template, array $scope): bool
    {
        if (! empty($scope['is_super'])) return true;
        $rawScope = (string) ($scope['country_code'] ?? '');
        if ($rawScope === '') return true;
        $aliases = $this->countries->aliases($rawScope);
        return in_array((string) $template->country_code, $aliases, true);
    }
}
