<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\System;

use App\Support\Governance\DeliveryErrorTranslator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Admin · System Health · Mail Delivery (sys-mail)
 * ---------------------------------------------------------------------------
 * v4 — premium read-only view of outbound mail health.
 *
 * What this controller does:
 *   · index()   — renders the Blade view + injects the coach manifest
 *   · summary() — top-level health pill + sparkline + by-domain + recent
 *                 register, paginated bounce list, send-type breakdown
 *   · sends()   — per-message recent register, paginated, masked PII
 *
 * Discipline (per the System Health brief):
 *   · NO RAW ERRORS — every error_message is run through
 *     DeliveryErrorTranslator. Raw text lives behind a disclosure on
 *     the row-detail sheet only.
 *   · NO SMTP / queue / worker terminology in the user-facing surface.
 *     Transport details are surfaced behind a "Show technical detail"
 *     disclosure on the methodology tab.
 *   · DYNAMIC DISCOVERY — send types are discovered from the actual
 *     template_code values present in notification_log; the screen does
 *     not hardcode a fixed list.
 *   · AUDIT — every read records an audit row; the recent-sends and
 *     bounces tabs additionally record a PII reveal because they
 *     unmask recipient identifiers.
 *
 * Mobile-API impact: NONE. Routes live under /admin/system/mail/*.
 */
final class MailController extends BaseSystemController
{
    protected function viewKey(): string
    {
        return 'mail';
    }

    public function index(Request $request)
    {
        return view('admin.system.mail.index', [
            'page_title'    => 'Mail delivery',
            'page_eyebrow'  => 'System Health',
            'page_subtitle' => 'Did the mail leave the building, did it arrive, and what came back. Read-only.',
            'coach'         => $this->coach(),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $now   = now();
            $hours = max(1, min(720, (int) $request->query('hours', 168)));
            $from  = (clone $now)->subHours($hours);

            $transport = $this->transport();

            if (! Schema::hasTable('notification_log')) {
                $this->auditView($request, ['view' => 'mail.summary', 'hours' => $hours], ['row_count' => 0]);
                return $this->ok([
                    'server_time' => $now->toIso8601String(),
                    'window_hours'=> $hours,
                    'transport'   => $transport,
                    'health'      => ['level' => 'unknown', 'plain' => 'No outbound mail has been recorded yet.'],
                    'totals'      => $this->emptyTotals(),
                    'sparkline'   => [],
                    'send_types'  => [],
                    'domains'     => [],
                    'latest'      => $this->emptyLatest(),
                ]);
            }

            $base = DB::table('notification_log')
                ->where('channel', 'EMAIL')
                ->whereBetween('created_at', [$from, $now]);

            // Status totals
            $byStatus = (clone $base)->selectRaw('status, COUNT(*) AS n')
                ->groupBy('status')->get()
                ->mapWithKeys(fn ($r) => [(string) $r->status => (int) $r->n])->all();
            foreach (['SENT', 'FAILED', 'BOUNCED', 'SKIPPED', 'QUEUED'] as $s) {
                $byStatus[$s] = (int) ($byStatus[$s] ?? 0);
            }
            $attempted = $byStatus['SENT'] + $byStatus['FAILED'] + $byStatus['BOUNCED'];
            $deliveryPct = $attempted > 0 ? round(100 * $byStatus['SENT'] / $attempted, 1) : 0.0;
            $bouncePct   = $attempted > 0 ? round(100 * $byStatus['BOUNCED'] / $attempted, 1) : 0.0;
            $failurePct  = $attempted > 0 ? round(100 * ($byStatus['FAILED'] + $byStatus['BOUNCED']) / $attempted, 1) : 0.0;

            // Health pill
            if ($attempted === 0) {
                $health = ['level' => 'green', 'plain' => 'No outbound mail in the chosen window — nothing to deliver yet.'];
            } elseif ($deliveryPct >= 95.0) {
                $health = ['level' => 'green', 'plain' => 'Mail is reaching its recipients. Delivery rate ' . $deliveryPct . '%.'];
            } elseif ($deliveryPct >= 90.0) {
                $health = ['level' => 'amber', 'plain' => 'Delivery rate is below the healthy threshold. ' . $deliveryPct . '% of attempts succeeded.'];
            } else {
                $health = ['level' => 'red', 'plain' => 'Mail delivery is degraded. Only ' . $deliveryPct . '% of attempts succeeded — investigate now.'];
            }

            // Sparkline — sent / delivered / failed per hour bucket
            $hourlyRows = (clone $base)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS h, "
                    . 'SUM(CASE WHEN status="SENT"    THEN 1 ELSE 0 END) AS sent_n, '
                    . 'SUM(CASE WHEN status="FAILED"  THEN 1 ELSE 0 END) AS failed_n, '
                    . 'SUM(CASE WHEN status="BOUNCED" THEN 1 ELSE 0 END) AS bounced_n, '
                    . 'COUNT(*) AS n')
                ->groupBy('h')->orderBy('h')->get()->keyBy('h');
            $sparkline = [];
            $cursor = (clone $from)->startOfHour();
            while ($cursor <= $now) {
                $k = $cursor->format('Y-m-d H:00:00');
                $row = $hourlyRows[$k] ?? null;
                $sparkline[] = [
                    'hour'      => $cursor->toIso8601String(),
                    'sent'      => $row ? (int) $row->sent_n    : 0,
                    'failed'    => $row ? (int) $row->failed_n  : 0,
                    'bounced'   => $row ? (int) $row->bounced_n : 0,
                    'n'         => $row ? (int) $row->n         : 0,
                ];
                $cursor->addHour();
            }

            // Send-type breakdown — DISCOVERED from the actual template_code
            // values present in the window, not hardcoded. The translator
            // for human-friendly send-type names lives in the view.
            $sendTypes = (clone $base)
                ->selectRaw('template_code, COUNT(*) AS n, '
                    . 'SUM(CASE WHEN status="SENT"    THEN 1 ELSE 0 END) AS sent_n, '
                    . 'SUM(CASE WHEN status="FAILED"  THEN 1 ELSE 0 END) AS failed_n, '
                    . 'SUM(CASE WHEN status="BOUNCED" THEN 1 ELSE 0 END) AS bounced_n')
                ->groupBy('template_code')
                ->orderByDesc('n')
                ->get()
                ->map(fn ($r) => [
                    'template_code' => (string) $r->template_code,
                    'n'             => (int) $r->n,
                    'sent'          => (int) $r->sent_n,
                    'failed'        => (int) $r->failed_n,
                    'bounced'       => (int) $r->bounced_n,
                    'success_pct'   => ($r->sent_n + $r->failed_n + $r->bounced_n) > 0
                        ? round(100 * $r->sent_n / max($r->sent_n + $r->failed_n + $r->bounced_n, 1), 1) : 0.0,
                ])->all();

            // Recipient-domain league
            $domains = (clone $base)
                ->selectRaw("SUBSTRING_INDEX(to_email, '@', -1) AS domain, "
                    . 'SUM(CASE WHEN status="SENT"    THEN 1 ELSE 0 END) AS sent_n, '
                    . 'SUM(CASE WHEN status="FAILED"  THEN 1 ELSE 0 END) AS failed_n, '
                    . 'SUM(CASE WHEN status="BOUNCED" THEN 1 ELSE 0 END) AS bounced_n, '
                    . 'COUNT(*) AS n')
                ->whereNotNull('to_email')
                ->groupBy('domain')
                ->orderByDesc('n')
                ->limit(12)->get()
                ->map(fn ($r) => [
                    'domain'      => (string) $r->domain,
                    'sent'        => (int) $r->sent_n,
                    'failed'      => (int) $r->failed_n,
                    'bounced'     => (int) $r->bounced_n,
                    'n'           => (int) $r->n,
                    'success_pct' => ($r->sent_n + $r->failed_n + $r->bounced_n) > 0
                        ? round(100 * $r->sent_n / max($r->sent_n + $r->failed_n + $r->bounced_n, 1), 1) : 0.0,
                ])->all();

            // Last-X timestamps (no PII — just times)
            $latest = $this->latestTimestamps($now);

            $this->auditView($request, ['view' => 'mail.summary', 'hours' => $hours], ['row_count' => count($sparkline)]);

            return $this->ok([
                'server_time'  => $now->toIso8601String(),
                'window_hours' => $hours,
                'transport'    => $transport,
                'health'       => $health,
                'totals'       => [
                    'sent'         => $byStatus['SENT'],
                    'failed'       => $byStatus['FAILED'],
                    'bounced'      => $byStatus['BOUNCED'],
                    'skipped'      => $byStatus['SKIPPED'],
                    'queued'       => $byStatus['QUEUED'],
                    'attempted'    => $attempted,
                    'delivery_pct' => $deliveryPct,
                    'bounce_pct'   => $bouncePct,
                    'failure_pct'  => $failurePct,
                ],
                'sparkline'    => $sparkline,
                'send_types'   => $sendTypes,
                'domains'      => $domains,
                'latest'       => $latest,
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'summary');
        }
    }

    /**
     * Recent sends — paginated. Recipients are masked by default; the
     * domain is shown plainly because the methodology page explains it
     * and the audit logs the reveal.
     */
    public function sends(Request $request): JsonResponse
    {
        try {
            $hours   = max(1, min(720, (int) $request->query('hours', 168)));
            $page    = max(1, (int) $request->query('page', 1));
            $perPage = max(10, min(100, (int) $request->query('per_page', 50)));
            $statusFilter = (string) $request->query('status', '');
            $templateFilter = (string) $request->query('template', '');
            $from    = now()->subHours($hours);

            if (! Schema::hasTable('notification_log')) {
                $this->auditView($request, ['view' => 'mail.sends', 'hours' => $hours], ['row_count' => 0]);
                return $this->ok([
                    'rows' => [], 'page' => 1, 'pages' => 0, 'total' => 0, 'per_page' => $perPage,
                ]);
            }

            $q = DB::table('notification_log')
                ->where('channel', 'EMAIL')
                ->where('created_at', '>=', $from);
            if ($statusFilter !== '') $q->where('status', $statusFilter);
            if ($templateFilter !== '') $q->where('template_code', $templateFilter);

            $total = (clone $q)->count();

            $rows = $q->orderByDesc('id')
                ->forPage($page, $perPage)
                ->get(['id', 'created_at', 'status', 'template_code', 'triggered_by',
                       'to_email', 'error_message', 'retry_count'])
                ->map(fn ($r) => [
                    'id'             => (int) $r->id,
                    'when'           => (string) $r->created_at,
                    'status'         => (string) $r->status,
                    'template_code'  => (string) $r->template_code,
                    'triggered_by'   => (string) $r->triggered_by,
                    'recipient_mask' => $r->to_email ? $this->maskEmail((string) $r->to_email) : null,
                    'recipient_dom'  => $r->to_email ? $this->domainOf((string) $r->to_email) : null,
                    'plain_reason'   => in_array($r->status, ['FAILED', 'BOUNCED'], true)
                        ? DeliveryErrorTranslator::translate($r->error_message) : null,
                    'technical_raw'  => (string) ($r->error_message ?? ''),
                    'retry_count'    => (int) $r->retry_count,
                ])->all();

            $this->auditView($request, [
                'view' => 'mail.sends', 'hours' => $hours,
                'status' => $statusFilter, 'template' => $templateFilter,
            ], ['row_count' => count($rows)]);
            if (! empty($rows)) {
                // Recipient mask is partial PII; the domain is unmasked.
                $this->auditPiiReveal($request, [
                    'view' => 'mail.sends', 'hours' => $hours,
                ], count($rows), ['recipient_mask', 'recipient_dom']);
            }

            return $this->ok([
                'rows'     => $rows,
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
                'pages'    => (int) ceil(max($total, 1) / $perPage),
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'sends');
        }
    }

    /* ──────────────────────── helpers ──────────────────────── */

    /**
     * @return array<string,mixed>
     */
    private function transport(): array
    {
        $mailer = (string) config('mail.default', 'smtp');
        $cfg    = (array) config('mail.mailers.' . $mailer, []);
        return [
            'mailer'    => $mailer,
            'transport' => (string) ($cfg['transport'] ?? $mailer),
            'host'      => isset($cfg['host']) ? (string) $cfg['host'] : null,
            'port'      => isset($cfg['port']) ? (int) $cfg['port']   : null,
            'scheme'    => isset($cfg['scheme']) ? (string) $cfg['scheme'] : null,
            'from_addr' => (string) config('mail.from.address', ''),
            'from_name' => (string) config('mail.from.name', ''),
            'has_auth'  => ! empty($cfg['username']) && ! empty($cfg['password']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function latestTimestamps(Carbon $now): array
    {
        if (! Schema::hasTable('notification_log')) {
            return $this->emptyLatest();
        }
        $lastSentAt    = DB::table('notification_log')->where('channel', 'EMAIL')->where('status', 'SENT')->max('created_at');
        $lastFailedAt  = DB::table('notification_log')->where('channel', 'EMAIL')->where('status', 'FAILED')->max('created_at');
        $lastBouncedAt = DB::table('notification_log')->where('channel', 'EMAIL')->where('status', 'BOUNCED')->max('created_at');
        return [
            'last_sent_at'        => $lastSentAt,
            'last_failed_at'      => $lastFailedAt,
            'last_bounced_at'     => $lastBouncedAt,
            'minutes_since_sent'  => $lastSentAt    ? (int) Carbon::parse($lastSentAt)->diffInMinutes($now)    : null,
            'minutes_since_failed'=> $lastFailedAt  ? (int) Carbon::parse($lastFailedAt)->diffInMinutes($now)  : null,
            'minutes_since_bounced'=> $lastBouncedAt ? (int) Carbon::parse($lastBouncedAt)->diffInMinutes($now) : null,
        ];
    }

    private function emptyLatest(): array
    {
        return [
            'last_sent_at' => null, 'last_failed_at' => null, 'last_bounced_at' => null,
            'minutes_since_sent' => null, 'minutes_since_failed' => null, 'minutes_since_bounced' => null,
        ];
    }

    private function emptyTotals(): array
    {
        return [
            'sent' => 0, 'failed' => 0, 'bounced' => 0, 'skipped' => 0, 'queued' => 0,
            'attempted' => 0, 'delivery_pct' => 0.0, 'bounce_pct' => 0.0, 'failure_pct' => 0.0,
        ];
    }

    private function domainOf(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false ? '' : substr($email, $at + 1);
    }

    private function maskEmail(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false) return '***';
        $local = substr($email, 0, $at);
        $domain = substr($email, $at);
        $masked = strlen($local) <= 2
            ? '**'
            : (mb_substr($local, 0, 1) . str_repeat('*', max(1, mb_strlen($local) - 2)) . mb_substr($local, -1));
        return $masked . $domain;
    }
}
