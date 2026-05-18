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
 * Admin · User-menu surfaces (Phase B · avatar dropdown).
 *   GET /admin/me                     — My Profile
 *   GET /admin/me/security            — Security (2FA, devices, sessions)
 *   GET /admin/me/notifications       — Notification Preferences
 */
final class UserMenuController extends Controller
{
    public function __construct(
        protected PheocScope $scope,
        protected EnumTranslator $enum,
    ) {
    }

    public function profile(Request $request): View
    {
        $user = $this->currentUser($request);
        return view('admin.me.profile', [
            'user'  => $user,
            'scope' => $this->scopeFor($request, $user),
        ]);
    }

    public function security(Request $request): View
    {
        $user = $this->currentUser($request);
        return view('admin.me.security', [
            'user' => $user,
        ]);
    }

    public function notifications(Request $request): View
    {
        $user = $this->currentUser($request);
        return view('admin.me.notifications', [
            'user' => $user,
            'recent' => $this->recentNotifications($user),
        ]);
    }

    protected function currentUser(Request $request): array
    {
        if ($u = $request->user()) {
            $row = DB::table('users')->where('id', $u->id)->first();
            if ($row) return (array) $row;
        }
        // Preview mode: pick the first active national admin.
        $row = DB::table('users')
            ->whereIn('role_key', ['NATIONAL_ADMIN', 'PHEOC_OFFICER'])
            ->where('is_active', 1)
            ->orderBy('id')
            ->first();
        return $row ? (array) $row : [];
    }

    protected function scopeFor(Request $request, array $user): array
    {
        if ($request->user()) {
            return $this->scope->forUser($request->user());
        }
        return [
            'country_code' => (string) ($user['country_code'] ?? config('country.code')),
            'is_super'     => true,
            'label'        => config('country.legacy_code') . ' · National (preview)',
        ];
    }

    protected function recentNotifications(array $user): array
    {
        if (empty($user['email'])) return [];
        try {
            return DB::table('notification_log')
                ->where('to_email', $user['email'])
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn ($r) => [
                    'template_code' => (string) $r->template_code,
                    'template_name' => $this->enum->notificationTemplate((string) $r->template_code),
                    'subject'       => (string) ($r->subject ?? ''),
                    'status'        => (string) $r->status,
                    'status_label'  => $this->enum->notificationStatus((string) $r->status),
                    'status_tone'   => $this->enum->notificationStatusTone((string) $r->status),
                    'created_rel'   => $r->created_at ? Carbon::parse((string) $r->created_at)->diffForHumans() : '—',
                ])
                ->all();
        } catch (\Throwable) { return []; }
    }
}
