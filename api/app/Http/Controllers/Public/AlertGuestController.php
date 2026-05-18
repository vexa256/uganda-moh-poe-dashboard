<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\AlertGuestTokens;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public · alert guest landing (alerts refactor §3.3).
 *
 * Three endpoints — none touch the session, none expose PII in query strings:
 *
 *   GET  /g/alert/{token}/view  → read-only case summary, no actions
 *   GET  /g/alert/{token}/ack   → confirm + one-shot acknowledge form
 *   POST /g/alert/{token}/ack   → consume token + write acknowledgement
 *   GET  /account/dead-end      → friendly page for inactive/suspended users
 *
 * Token lifecycle is single-use: every successful render or POST consumes
 * the token. Re-clicking a delivered link 404s with a clear message to
 * contact the dispatcher.
 *
 * No PII surfaces in the URL: only the opaque hex token plus the scope flag.
 *
 * Hard headers — no indexing, no referrer, no caching, no MIME sniff.
 */
final class AlertGuestController extends Controller
{
    public function view(Request $r, string $token): Response
    {
        $token = trim($token);
        $row = AlertGuestTokens::resolve($token);
        if (! $row) {
            return $this->dead('not_found', 'This guest link is invalid or has already been used.');
        }
        if ($row->scope !== 'view') {
            return $this->dead('scope_mismatch', 'This link is not authorised for read-only viewing.');
        }
        if (AlertGuestTokens::isExpired($row)) {
            return $this->dead('expired', 'This guest link expired. Request a fresh one from the sender.');
        }

        $alert = DB::table('alerts')->where('id', $row->alert_id)->whereNull('deleted_at')->first();
        if (! $alert) {
            return $this->dead('alert_gone', 'The alert this link refers to is no longer available.');
        }

        // Consume the token NOW — single-use semantics. We log the consume
        // before rendering so a render-time exception still leaves an audit row.
        $consumed = AlertGuestTokens::consume(
            $token,
            (string) $r->ip(),
            mb_substr((string) $r->userAgent(), 0, 240),
        );
        if (! $consumed) {
            return $this->dead('consume_failed', 'This guest link could not be consumed. It may have been used already.');
        }

        $screening = $alert->secondary_screening_id
            ? DB::table('secondary_screenings')->where('id', $alert->secondary_screening_id)->first()
            : null;
        $owner = $alert->current_owner_user_id
            ? DB::table('users')->where('id', $alert->current_owner_user_id)->first(['full_name','role_key'])
            : null;

        return response()->view('public.alert.guest_view', [
            'alert'      => $alert,
            'screening'  => $screening,
            'owner'      => $owner,
            'recipient'  => $row->recipient_email,
            'consumed_at' => $row->consumed_at ?? null,
        ])
        ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->header('Referrer-Policy', 'no-referrer')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
        ->header('Pragma', 'no-cache')
        ->header('X-Content-Type-Options', 'nosniff')
        ->header('Permissions-Policy', 'interest-cohort=()');
    }

    public function ackForm(Request $r, string $token): Response
    {
        $token = trim($token);
        $row = AlertGuestTokens::resolve($token);
        if (! $row || $row->scope !== 'ack') {
            return $this->dead('not_found', 'This acknowledgement link is invalid.');
        }
        if (AlertGuestTokens::isExpired($row)) {
            return $this->dead('expired', 'This acknowledgement link expired.');
        }
        if ($row->status !== 'ISSUED') {
            return $this->dead('used', 'This acknowledgement was already recorded.');
        }
        $alert = DB::table('alerts')->where('id', $row->alert_id)->whereNull('deleted_at')->first();
        if (! $alert) return $this->dead('alert_gone', 'The alert this link refers to is no longer available.');

        return response()->view('public.alert.guest_ack', [
            'alert'     => $alert,
            'recipient' => $row->recipient_email,
            'token'     => $token,
        ])
        ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->header('Referrer-Policy', 'no-referrer')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function ackSubmit(Request $r, string $token): Response
    {
        $token = trim($token);
        $row = AlertGuestTokens::resolve($token);
        if (! $row || $row->scope !== 'ack') {
            return $this->dead('not_found', 'This acknowledgement link is invalid.');
        }
        if (AlertGuestTokens::isExpired($row)) {
            return $this->dead('expired', 'This acknowledgement link expired.');
        }
        $alert = DB::table('alerts')->where('id', $row->alert_id)->whereNull('deleted_at')->first();
        if (! $alert) return $this->dead('alert_gone', 'The alert this link refers to is no longer available.');

        $consumed = AlertGuestTokens::consume(
            $token,
            (string) $r->ip(),
            mb_substr((string) $r->userAgent(), 0, 240),
        );
        if (! $consumed) {
            return $this->dead('used', 'This acknowledgement was already recorded.');
        }

        // Append-only timeline event so the audit lineage shows a guest ack.
        try {
            DB::table('alert_timeline_events')->insert([
                'alert_id'            => $alert->id,
                'event_code'          => 'GUEST_ACKNOWLEDGED',
                'event_category'      => 'EMAIL',
                'actor_user_id'       => null,
                'actor_name'          => $row->recipient_email,
                'actor_role'          => 'GUEST',
                'payload_json'        => json_encode([
                    'token_id'        => (int) $row->id,
                    'recipient_email' => $row->recipient_email,
                    'note'            => mb_substr((string) $r->input('note', ''), 0, 500),
                    'ip'              => $r->ip(),
                ]),
                'summary'             => 'Guest acknowledgement by ' . $row->recipient_email,
                'severity'            => 'INFO',
                'related_entity_type' => 'ALERT',
                'related_entity_id'   => $alert->id,
                'created_at'          => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('[Public\\AlertGuest][ack][timeline] ' . $e->getMessage());
        }

        return response()->view('public.alert.guest_ack_thanks', [
            'alert'     => $alert,
            'recipient' => $row->recipient_email,
        ])
        ->header('X-Robots-Tag', 'noindex, nofollow')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /** Friendly dead-end for inactive / suspended users (linked from middleware too). */
    public function deadEnd(Request $r): Response
    {
        return response()->view('public.alert.dead_end')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    private function dead(string $code, string $message): Response
    {
        return response()->view('public.alert.guest_error', [
            'code'    => $code,
            'message' => $message,
        ], 410)
        ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
        ->header('Referrer-Policy', 'no-referrer')
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }
}
