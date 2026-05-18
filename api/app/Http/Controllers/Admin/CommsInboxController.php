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
 * CommsInboxController — personal notification inbox (M6 / F1).
 *
 *   GET /admin/comms/inbox
 *
 * Reads notification_log + notification_log_reads for the current user.
 * Mobile consumes /api/inbox/*; this Blade view reads the same tables
 * directly (never calls the mobile controller).
 */
final class CommsInboxController extends Controller
{
    public function __construct(
        protected PheocScope $scope,
        protected EnumTranslator $enum,
    ) {
    }

    public const PAGE_SIZE = 25;

    public function index(Request $request): View
    {
        $user  = $request->user();
        $uid   = $user?->id ?? 0;
        $scope = $user ? $this->scope->forUser($user) : ['country_code' => config('country.code'), 'label' => 'Preview · ' . config('country.legacy_code')];

        $filter = $request->query('filter', 'all'); // all | unread | alerts | auth

        // notification_log is the outbound record of every email dispatched.
        // For the admin user we scope by email; preview mode shows recent rows.
        $base = DB::table('notification_log as n')
            ->leftJoin('notification_log_reads as r', function ($j) use ($uid) {
                $j->on('r.notification_log_id', '=', 'n.id')->where('r.user_id', $uid);
            })
            ->select([
                'n.id', 'n.template_code', 'n.subject', 'n.body_preview', 'n.to_email',
                'n.related_entity_type', 'n.related_entity_id', 'n.status',
                'n.created_at', 'r.read_at',
            ]);

        if ($user && ! empty($user->email)) {
            $base->where('n.to_email', $user->email);
        }

        if ($filter === 'unread') {
            $base->whereNull('r.read_at');
        } elseif ($filter === 'alerts') {
            $base->where('n.related_entity_type', 'ALERT');
        } elseif ($filter === 'auth') {
            $base->where('n.template_code', 'like', 'AUTH\\_%');
        }

        $paginator = $base->orderByDesc('n.created_at')->paginate(self::PAGE_SIZE)->withQueryString();

        $paginator->getCollection()->transform(fn ($r) => [
            'id'            => (int) $r->id,
            'template_code' => (string) $r->template_code,
            'subject'       => (string) ($r->subject ?: $this->enum->notificationTemplate((string) $r->template_code)),
            'preview'       => (string) ($r->body_preview ?? ''),
            'to_email'      => (string) $r->to_email,
            'entity_type'   => (string) ($r->related_entity_type ?? ''),
            'entity_id'     => $r->related_entity_id,
            'status'        => (string) $r->status,
            'status_label'  => $this->enum->notificationStatus((string) $r->status),
            'status_tone'   => $this->enum->notificationStatusTone((string) $r->status),
            'read'          => $r->read_at !== null,
            'created_at'    => $r->created_at,
            'created_rel'   => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
            'deep_link'     => ($r->related_entity_type === 'ALERT' && $r->related_entity_id)
                                ? url('/admin/alerts/' . (int) $r->related_entity_id) : null,
        ]);

        $rows = $paginator->items();

        // Counts are computed per-filter across the full scope (not on the paginated set).
        $countQ = function () use ($uid, $user) {
            $q = DB::table('notification_log as n')
                ->leftJoin('notification_log_reads as r', function ($j) use ($uid) {
                    $j->on('r.notification_log_id', '=', 'n.id')->where('r.user_id', $uid);
                });
            if ($user && ! empty($user->email)) {
                $q->where('n.to_email', $user->email);
            }
            return $q;
        };
        $counts = [
            'total'  => $countQ()->count(),
            'unread' => $countQ()->whereNull('r.read_at')->count(),
            'alerts' => $countQ()->where('n.related_entity_type', 'ALERT')->count(),
        ];

        // Right-rail facet (by template) — capped to what's on this page for simplicity.
        $byTemplate = [];
        foreach ($rows as $r) {
            $byTemplate[$r['template_code']] = ($byTemplate[$r['template_code']] ?? 0) + 1;
        }
        arsort($byTemplate);

        return view('admin.comms.inbox', [
            'scope'       => $scope,
            'filter'      => $filter,
            'rows'        => $rows,
            'paginator'   => $paginator,
            'counts'      => $counts,
            'byTemplate'  => array_slice($byTemplate, 0, 12, true),
        ]);
    }
}
