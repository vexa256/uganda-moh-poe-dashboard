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
 * Admin · Outbound Communications (sidebar #7 · Phase B).
 *
 *   GET /admin/comms/outbound
 *
 * Tabs: Delivery log · Templates · Digests · Suppressions · Retry queue.
 * All queries scoped by country. Mobile app does not consume these
 * administrative surfaces.
 */
final class CommsOutboundController extends Controller
{
    public const PAGE_SIZE = 25;

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

        return view('admin.comms.outbound', [
            'scope'         => $scope,
            'logPaginator'  => $this->deliveryLog($country, $request),
            'templates'     => $this->templateLibrary($country),
            'digests'       => $this->digestSummary($country),
            'suppressions'  => $this->recentSuppressions($country),
            'retryQueue'    => $this->retryQueue($country),
            'stats'         => $this->stats($country),
        ]);
    }

    protected function deliveryLog(string $country, Request $request)
    {
        $paginator = DB::table('notification_log')
            ->where('country_code', $country)
            ->orderByDesc('created_at')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        $paginator->getCollection()->transform(fn ($r) => [
            'id'            => (int) $r->id,
            'template_code' => (string) $r->template_code,
            'template_name' => $this->enum->notificationTemplate((string) $r->template_code),
            'channel'       => (string) $r->channel,
            'channel_label' => $this->enum->notificationChannel((string) $r->channel),
            'to_email'      => (string) ($r->to_email ?? ''),
            'subject'       => (string) ($r->subject ?? ''),
            'status'        => (string) $r->status,
            'status_label'  => $this->enum->notificationStatus((string) $r->status),
            'status_tone'   => $this->enum->notificationStatusTone((string) $r->status),
            'retry_count'   => (int) ($r->retry_count ?? 0),
            'sent_at'       => $r->sent_at,
            'created_at'    => $r->created_at,
            'created_rel'   => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
            'error_message' => (string) ($r->error_message ?? ''),
        ]);

        return $paginator;
    }

    protected function templateLibrary(string $country): array
    {
        try {
            return DB::table('notification_templates')
                ->where('is_active', 1)
                ->orderBy('template_code')
                ->limit(60)
                ->get()
                ->map(fn ($r) => [
                    'code'    => (string) $r->template_code,
                    'name'    => $this->enum->notificationTemplate((string) $r->template_code),
                    'channel' => (string) $r->channel,
                    'subject' => (string) ($r->subject_template ?? ''),
                    'ai'      => (bool) $r->is_ai_enhanced,
                    'active'  => (bool) $r->is_active,
                    'preview' => substr(strip_tags((string) ($r->body_text_template ?? '')), 0, 160),
                ])
                ->all();
        } catch (\Throwable) { return []; }
    }

    protected function digestSummary(string $country): array
    {
        try {
            return DB::table('notification_log')
                ->selectRaw("DATE(created_at) as day, template_code, COUNT(*) as n")
                ->where('country_code', $country)
                ->whereIn('template_code', ['DAILY_REPORT', 'WEEKLY_REPORT', 'NATIONAL_INTELLIGENCE'])
                ->where('created_at', '>=', now()->subDays(14))
                ->groupBy('day', 'template_code')
                ->orderByDesc('day')
                ->limit(40)
                ->get()
                ->map(fn ($r) => [
                    'day'      => (string) $r->day,
                    'template' => (string) $r->template_code,
                    'name'     => $this->enum->notificationTemplate((string) $r->template_code),
                    'n'        => (int) $r->n,
                ])
                ->all();
        } catch (\Throwable) { return []; }
    }

    protected function recentSuppressions(string $country): array
    {
        try {
            return DB::table('notification_suppressions')
                ->orderByDesc('last_sent_at')
                ->limit(40)
                ->get()
                ->map(fn ($r) => [
                    'template_code'  => (string) $r->template_code,
                    'template_name'  => $this->enum->notificationTemplate((string) $r->template_code),
                    'entity_type'    => (string) ($r->related_entity_type ?? ''),
                    'entity_id'      => $r->related_entity_id,
                    'send_count'     => (int) ($r->send_count ?? 0),
                    'last_sent_at'   => $r->last_sent_at,
                    'last_sent_rel'  => $r->last_sent_at ? Carbon::parse((string) $r->last_sent_at)->diffForHumans() : '—',
                ])
                ->all();
        } catch (\Throwable) { return []; }
    }

    protected function retryQueue(string $country): array
    {
        try {
            return DB::table('notification_log')
                ->where('country_code', $country)
                ->whereIn('status', ['QUEUED', 'FAILED'])
                ->orderByDesc('created_at')
                ->limit(40)
                ->get(['id', 'template_code', 'channel', 'to_email', 'status', 'retry_count', 'error_message', 'created_at'])
                ->map(fn ($r) => [
                    'id'            => (int) $r->id,
                    'template_code' => (string) $r->template_code,
                    'template_name' => $this->enum->notificationTemplate((string) $r->template_code),
                    'channel_label' => $this->enum->notificationChannel((string) $r->channel),
                    'to_email'      => (string) ($r->to_email ?? ''),
                    'status'        => (string) $r->status,
                    'status_label'  => $this->enum->notificationStatus((string) $r->status),
                    'status_tone'   => $this->enum->notificationStatusTone((string) $r->status),
                    'retry_count'   => (int) ($r->retry_count ?? 0),
                    'error_message' => (string) ($r->error_message ?? ''),
                    'created_rel'   => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
                ])
                ->all();
        } catch (\Throwable) { return []; }
    }

    protected function stats(string $country): array
    {
        try {
            $base = DB::table('notification_log')->where('country_code', $country);
            return [
                'total_24h'  => (clone $base)->where('created_at', '>=', now()->subDay())->count(),
                'sent_24h'   => (clone $base)->where('created_at', '>=', now()->subDay())->where('status', 'SENT')->count(),
                'failed_24h' => (clone $base)->where('created_at', '>=', now()->subDay())->where('status', 'FAILED')->count(),
                'queued'     => (clone $base)->where('status', 'QUEUED')->count(),
            ];
        } catch (\Throwable) {
            return ['total_24h' => 0, 'sent_24h' => 0, 'failed_24h' => 0, 'queued' => 0];
        }
    }
}
