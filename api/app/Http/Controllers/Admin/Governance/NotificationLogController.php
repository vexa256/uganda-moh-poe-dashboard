<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Governance;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Admin · Governance · Delivery Audit (gov-notif-log)
 * ---------------------------------------------------------------------------
 * Read-only forensic view onto `notification_log`. Every row is a per-recipient
 * proof of delivery attempt written by the dispatcher pipeline:
 *
 *   QUEUED → SENT | FAILED | BOUNCED | SKIPPED
 *
 * Mobile contract: NONE. notification_log is written by server-side
 * dispatcher jobs (cron + event listeners); nothing mobile-facing
 * references this table.
 *
 * Gate: NATIONAL_ADMIN only. Rows carry full recipient email/phone plus
 * subject + body_preview, which is PII-heavy.
 *
 * The summary payload is viz-oriented — every structure the controller
 * emits is consumed by an SVG chart in the Blade view (hourly stacked
 * sparkline, status donut, channel split, template ranking, failure
 * reasons bars, 7×24 heatmap).
 */
final class NotificationLogController extends BaseGovernanceController
{
    protected function viewKey(): string
    {
        return 'notif-log';
    }

    private const PAGE_MAX     = 200;
    private const WINDOW_HOURS = 168;
    private const STATUSES     = ['QUEUED', 'SENT', 'FAILED', 'BOUNCED', 'SKIPPED'];
    private const CHANNELS     = ['EMAIL', 'SMS', 'PUSH'];

    public function index(Request $request)
    {
        return view('admin.governance.notification-log.index', [
            'page_title'    => 'Delivery Audit',
            'page_eyebrow'  => 'Governance',
            'page_subtitle' => 'notification_log · SENT / SKIPPED / FAILED · per-recipient delivery proof · last_error capture.',
            'statuses'      => self::STATUSES,
            'channels'      => self::CHANNELS,
            'coach'         => $this->coach(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        try {
            [$from, $to] = $this->window($request);

            $validated = $request->validate([
                'q'             => ['nullable', 'string', 'max:120'],
                'status'        => ['nullable', 'string', 'in:' . implode(',', self::STATUSES)],
                'channel'       => ['nullable', 'string', 'in:' . implode(',', self::CHANNELS)],
                'template_code' => ['nullable', 'string', 'max:60'],
                'poe_code'      => ['nullable', 'string', 'max:40'],
                'district_code' => ['nullable', 'string', 'max:30'],
                'triggered_by'  => ['nullable', 'string', 'max:40'],
                'page'          => ['nullable', 'integer', 'min:1', 'max:10000'],
                'per_page'      => ['nullable', 'integer', 'min:10', 'max:' . self::PAGE_MAX],
            ]);

            $perPage = (int) ($validated['per_page'] ?? 50);
            $page    = (int) ($validated['page']     ?? 1);

            $q = DB::table('notification_log as nl')
                ->leftJoin('poe_notification_contacts as c', 'c.id', '=', 'nl.contact_id')
                ->select([
                    'nl.id', 'nl.contact_id', 'nl.to_email', 'nl.to_phone',
                    'nl.channel', 'nl.template_code', 'nl.subject', 'nl.body_preview',
                    'nl.related_entity_type', 'nl.related_entity_id',
                    'nl.country_code', 'nl.district_code', 'nl.poe_code',
                    'nl.status', 'nl.error_message', 'nl.retry_count',
                    'nl.sent_at', 'nl.delivered_at', 'nl.failed_at',
                    'nl.triggered_by', 'nl.created_at',
                    'c.full_name as contact_name', 'c.level as contact_level',
                ])
                ->whereBetween('nl.created_at', [$from, $to]);

            if (! empty($validated['status']))        { $q->where('nl.status',        $validated['status']); }
            if (! empty($validated['channel']))       { $q->where('nl.channel',       $validated['channel']); }
            if (! empty($validated['template_code'])) { $q->where('nl.template_code', $validated['template_code']); }
            if (! empty($validated['poe_code']))      { $q->where('nl.poe_code',      $validated['poe_code']); }
            if (! empty($validated['district_code'])) { $q->where('nl.district_code', $validated['district_code']); }
            if (! empty($validated['triggered_by']))  { $q->where('nl.triggered_by',  $validated['triggered_by']); }
            if (! empty($validated['q'])) {
                $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $validated['q']) . '%';
                $q->where(function ($w) use ($like): void {
                    $w->where('nl.to_email',      'like', $like)
                      ->orWhere('nl.to_phone',    'like', $like)
                      ->orWhere('nl.subject',     'like', $like)
                      ->orWhere('nl.template_code','like', $like)
                      ->orWhere('nl.error_message','like', $like)
                      ->orWhere('c.full_name',     'like', $like);
                });
            }

            $total = (clone $q)->count();
            $rows  = $q->orderByDesc('nl.id')
                ->forPage($page, $perPage)
                ->get()
                ->map(fn ($r) => [
                    'id'                   => (int) $r->id,
                    'created_at'           => $r->created_at,
                    'channel'              => (string) $r->channel,
                    'template_code'        => (string) $r->template_code,
                    'status'               => (string) $r->status,
                    'to_email'             => $r->to_email,
                    'to_phone'             => $r->to_phone,
                    'subject'              => $r->subject,
                    'body_preview'         => $r->body_preview,
                    'related_entity_type'  => $r->related_entity_type,
                    'related_entity_id'    => $r->related_entity_id ? (int) $r->related_entity_id : null,
                    'poe_code'             => $r->poe_code,
                    'district_code'        => $r->district_code,
                    'country_code'         => $r->country_code,
                    'error_message'        => $r->error_message,
                    'retry_count'          => (int) $r->retry_count,
                    'sent_at'              => $r->sent_at,
                    'delivered_at'         => $r->delivered_at,
                    'failed_at'            => $r->failed_at,
                    'triggered_by'         => (string) $r->triggered_by,
                    'contact_name'         => $r->contact_name,
                    'contact_level'        => $r->contact_level,
                ])
                ->all();

            // Audit: rows on this surface include recipient email + phone +
            // subject preview — all PII. Log the view AND a PII reveal
            // naming the unmasked columns.
            $this->auditView($request, $validated, ['row_count' => count($rows)]);
            if (! empty($rows)) {
                $this->auditPiiReveal(
                    $request,
                    $validated,
                    count($rows),
                    ['to_email', 'to_phone', 'subject', 'contact_name', 'error_message'],
                );
            }

            return response()->json(['ok' => true, 'data' => [
                'rows'     => $rows,
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
                'pages'    => (int) ceil(max($total, 1) / $perPage),
                'window'   => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            ]]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['ok' => false, 'error' => 'validation', 'errors' => $e->errors()], 422);
        } catch (Throwable $e) {
            return $this->serverError($e, 'data');
        }
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            [$from, $to] = $this->window($request);
            $now         = now();
            $hours       = (int) $from->diffInHours($to);

            // ── Status donut ────────────────────────────────────────────
            $byStatus = DB::table('notification_log')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('status, COUNT(*) AS n')
                ->groupBy('status')->get()
                ->mapWithKeys(fn ($r) => [(string) $r->status => (int) $r->n])
                ->all();
            foreach (self::STATUSES as $s) { $byStatus[$s] = (int) ($byStatus[$s] ?? 0); }

            // ── Channel split ───────────────────────────────────────────
            $byChannel = DB::table('notification_log')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('channel, status, COUNT(*) AS n')
                ->groupBy('channel', 'status')->get();
            $channelMap = [];
            foreach ($byChannel as $r) {
                $c = (string) $r->channel;
                $channelMap[$c] ??= ['channel' => $c, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'bounced' => 0, 'queued' => 0, 'total' => 0];
                $channelMap[$c][strtolower((string) $r->status)] = (int) $r->n;
                $channelMap[$c]['total'] += (int) $r->n;
            }
            $byChannelArr = array_values($channelMap);
            usort($byChannelArr, fn ($a, $b) => $b['total'] <=> $a['total']);

            // ── Hourly stacked sparkline ────────────────────────────────
            $hourlyRows = DB::table('notification_log')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS h, "
                    . 'SUM(CASE WHEN status="SENT"     THEN 1 ELSE 0 END) AS sent_n, '
                    . 'SUM(CASE WHEN status="FAILED"   THEN 1 ELSE 0 END) AS failed_n, '
                    . 'SUM(CASE WHEN status="BOUNCED"  THEN 1 ELSE 0 END) AS bounced_n, '
                    . 'SUM(CASE WHEN status="SKIPPED"  THEN 1 ELSE 0 END) AS skipped_n, '
                    . 'SUM(CASE WHEN status="QUEUED"   THEN 1 ELSE 0 END) AS queued_n, '
                    . 'COUNT(*) AS n')
                ->groupBy('h')->orderBy('h')->get()->keyBy('h');

            $hourly = [];
            $cursor = (clone $from)->startOfHour();
            while ($cursor <= $to) {
                $key = $cursor->format('Y-m-d H:00:00');
                $row = $hourlyRows[$key] ?? null;
                $hourly[] = [
                    'hour'      => $cursor->toIso8601String(),
                    'n'         => $row ? (int) $row->n         : 0,
                    'sent_n'    => $row ? (int) $row->sent_n    : 0,
                    'failed_n'  => $row ? (int) $row->failed_n  : 0,
                    'bounced_n' => $row ? (int) $row->bounced_n : 0,
                    'skipped_n' => $row ? (int) $row->skipped_n : 0,
                    'queued_n'  => $row ? (int) $row->queued_n  : 0,
                ];
                $cursor->addHour();
            }

            // ── Day-of-week × hour-of-day heatmap ───────────────────────
            $heatRows = DB::table('notification_log')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('DAYOFWEEK(created_at) AS dow, HOUR(created_at) AS hod, COUNT(*) AS n')
                ->groupBy('dow', 'hod')->get();
            $heatmap = array_fill(0, 7, array_fill(0, 24, 0));
            foreach ($heatRows as $r) {
                $dow = [1 => 6, 2 => 0, 3 => 1, 4 => 2, 5 => 3, 6 => 4, 7 => 5][(int) $r->dow] ?? 0;
                $hod = max(0, min(23, (int) $r->hod));
                $heatmap[$dow][$hod] = (int) $r->n;
            }

            // ── Template-code ranking ───────────────────────────────────
            $byTemplate = DB::table('notification_log')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw('template_code, COUNT(*) AS n, '
                    . 'SUM(CASE WHEN status="SENT"    THEN 1 ELSE 0 END) AS sent_n, '
                    . 'SUM(CASE WHEN status="FAILED"  THEN 1 ELSE 0 END) AS failed_n, '
                    . 'SUM(CASE WHEN status="SKIPPED" THEN 1 ELSE 0 END) AS skipped_n')
                ->groupBy('template_code')
                ->orderByDesc('n')
                ->limit(15)
                ->get()
                ->map(fn ($r) => [
                    'template_code' => (string) $r->template_code,
                    'n'             => (int) $r->n,
                    'sent'          => (int) $r->sent_n,
                    'failed'        => (int) $r->failed_n,
                    'skipped'       => (int) $r->skipped_n,
                ])
                ->all();

            // ── Top failure reasons ─────────────────────────────────────
            $failReasons = DB::table('notification_log')
                ->whereBetween('created_at', [$from, $to])
                ->whereIn('status', ['FAILED', 'BOUNCED'])
                ->whereNotNull('error_message')
                ->selectRaw('SUBSTRING(error_message, 1, 120) AS reason, COUNT(*) AS n')
                ->groupBy('reason')
                ->orderByDesc('n')
                ->limit(8)
                ->get()
                ->map(fn ($r) => ['reason' => (string) $r->reason, 'n' => (int) $r->n])
                ->all();

            // ── Scope split (POE / DISTRICT / NATIONAL) ─────────────────
            $byScope = DB::table('notification_log')
                ->whereBetween('created_at', [$from, $to])
                ->selectRaw("CASE
                    WHEN poe_code IS NOT NULL AND poe_code <> '' THEN 'POE'
                    WHEN district_code IS NOT NULL AND district_code <> '' THEN 'DISTRICT'
                    WHEN country_code IS NOT NULL AND country_code <> '' THEN 'NATIONAL'
                    ELSE 'UNKNOWN'
                END AS scope, COUNT(*) AS n")
                ->groupBy('scope')
                ->get()
                ->mapWithKeys(fn ($r) => [(string) $r->scope => (int) $r->n])
                ->all();

            // ── Recent failure backlog & queue depth ───────────────────
            $failBacklog = (int) DB::table('notification_log')
                ->where('status', 'FAILED')->where('retry_count', '<', 4)->count();
            $queuedDepth = (int) DB::table('notification_log')
                ->where('status', 'QUEUED')->count();
            $lastSentAt = DB::table('notification_log')
                ->where('status', 'SENT')->orderByDesc('created_at')->value('created_at');
            $lastFailedAt = DB::table('notification_log')
                ->where('status', 'FAILED')->orderByDesc('created_at')->value('created_at');

            // ── Ratios ──────────────────────────────────────────────────
            $sent     = $byStatus['SENT'];
            $failed   = $byStatus['FAILED'];
            $bounced  = $byStatus['BOUNCED'];
            $skipped  = $byStatus['SKIPPED'];
            $queued   = $byStatus['QUEUED'];
            $total    = $sent + $failed + $bounced + $skipped + $queued;
            $attempted = $sent + $failed + $bounced;
            $deliveryPct = $attempted > 0 ? round(100 * $sent / $attempted, 1) : 0.0;
            $failurePct  = $attempted > 0 ? round(100 * ($failed + $bounced) / $attempted, 1) : 0.0;

            // Audit: aggregate-only payload, no PII reveal. Record the view
            // with the total event count so auditors see the volume.
            $this->auditView($request, ['hours' => $hours], ['row_count' => (int) $total]);

            return response()->json(['ok' => true, 'data' => [
                'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'hours' => $hours],
                'by_status'   => $byStatus,
                'by_channel'  => $byChannelArr,
                'by_template' => $byTemplate,
                'by_scope'    => $byScope,
                'hourly'      => $hourly,
                'heatmap'     => $heatmap,
                'failures'    => $failReasons,
                'totals' => [
                    'total'        => $total,
                    'attempted'    => $attempted,
                    'sent'         => $sent,
                    'failed'       => $failed,
                    'bounced'      => $bounced,
                    'skipped'      => $skipped,
                    'queued'       => $queued,
                    'delivery_pct' => $deliveryPct,
                    'failure_pct'  => $failurePct,
                ],
                'operational' => [
                    'fail_backlog'    => $failBacklog,
                    'queued_depth'    => $queuedDepth,
                    'last_sent_at'    => $lastSentAt,
                    'last_failed_at'  => $lastFailedAt,
                ],
                'server_time' => $now->toIso8601String(),
            ]]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'summary');
        }
    }

    public function meta(Request $request): JsonResponse
    {
        try {
            $templateCodes = DB::table('notification_log')
                ->select('template_code')->distinct()
                ->orderBy('template_code')->pluck('template_code')->all();
            $triggeredBy = DB::table('notification_log')
                ->select('triggered_by')->distinct()
                ->orderBy('triggered_by')->pluck('triggered_by')->all();
            // Audit: meta is enum-like reference data, no PII reveal.
            $this->auditView($request, [], ['row_count' => count($templateCodes)]);

            return response()->json(['ok' => true, 'data' => [
                'template_codes' => array_values(array_filter($templateCodes, fn ($v) => $v !== null && $v !== '')),
                'triggered_by'   => array_values(array_filter($triggeredBy,   fn ($v) => $v !== null && $v !== '')),
                'statuses'       => self::STATUSES,
                'channels'       => self::CHANNELS,
            ]]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'meta');
        }
    }

    public function export(Request $request): Response
    {
        [$from, $to] = $this->window($request);
        $filters = ['hours' => (int) $from->diffInHours($to)];

        // Audit BEFORE the file is generated so no successful download is
        // unaudited. The CSV reveals to_email + to_phone + subject — log
        // the reveal explicitly before we stream a single byte.
        $rowCount = (int) DB::table('notification_log')->whereBetween('created_at', [$from, $to])->count();
        $this->auditPiiReveal($request, $filters, $rowCount, ['to_email', 'to_phone', 'subject', 'error_message']);

        $filename = 'notification-log-' . $from->format('Ymd-Hi') . '-to-' . $to->format('Ymd-Hi') . '.csv';
        $footer   = $this->exportFooter($request, $filters);

        $callback = function () use ($from, $to, $footer): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'id', 'created_at', 'channel', 'template_code', 'status',
                'to_email', 'to_phone', 'subject', 'poe_code', 'district_code',
                'related_entity_type', 'related_entity_id', 'retry_count',
                'sent_at', 'delivered_at', 'failed_at', 'triggered_by', 'error_message',
            ]);
            DB::table('notification_log')
                ->whereBetween('created_at', [$from, $to])
                ->orderByDesc('id')
                ->chunkById(500, function ($chunk) use ($handle): void {
                    foreach ($chunk as $r) {
                        fputcsv($handle, [
                            (int) $r->id, (string) $r->created_at, (string) $r->channel,
                            (string) $r->template_code, (string) $r->status,
                            (string) ($r->to_email ?? ''), (string) ($r->to_phone ?? ''),
                            (string) ($r->subject ?? ''), (string) ($r->poe_code ?? ''),
                            (string) ($r->district_code ?? ''),
                            (string) ($r->related_entity_type ?? ''),
                            $r->related_entity_id !== null ? (int) $r->related_entity_id : '',
                            (int) $r->retry_count,
                            (string) ($r->sent_at ?? ''), (string) ($r->delivered_at ?? ''),
                            (string) ($r->failed_at ?? ''),
                            (string) $r->triggered_by, (string) ($r->error_message ?? ''),
                        ]);
                    }
                }, 'id');
            // Standard scope+filter+timestamp footer.
            foreach ($footer as $line) {
                fputcsv($handle, $line);
            }
            fclose($handle);
        };

        return response()->streamDownload($callback, $filename, [
            'Content-Type'           => 'text/csv; charset=UTF-8',
            'Cache-Control'          => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function window(Request $request): array
    {
        $hours = (int) $request->query('hours', (string) self::WINDOW_HOURS);
        $hours = max(1, min(720, $hours));
        $to    = now();
        $from  = (clone $to)->subHours($hours);
        return [$from, $to];
    }
}
