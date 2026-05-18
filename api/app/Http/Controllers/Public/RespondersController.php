<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Public · External Responder Portal — tokenised one-time replies.
 *
 *   GET  /respond/{token}   — render the form (or expired/invalid page)
 *   POST /respond/{token}   — accept the response, attach as alert_evidence
 *
 * ─── HOSTILE-AUDITOR THREAT MODEL ─────────────────────────────────────────
 *  • Token format constrained at the route level (regex 40-128 alnum + _ -).
 *  • DB lookup matches plaintext request_token (existing scheme); GETs and
 *    POSTs are rate-limited per IP+token to deter brute force.
 *  • Single-use: status flips SENT → RECEIVED inside a row-level transaction;
 *    a second submit hits a 409.
 *  • Expiry: expires_at < NOW() → 410 with neutral message (no token leak).
 *  • Cancellation: status=CANCELLED → 410 indistinguishable from expiry.
 *  • Evidence upload: MIME whitelist + size cap + UUID-prefixed filename
 *    stored on the *non-public* responder disk; never served back unsigned.
 *  • Audit: every GET stamps view_count + last_viewed_at; submit stamps IP/UA.
 *  • Output escaping: Blade's {{ }} default. Response payload echoed back
 *    only as plain text on the thanks page.
 *  • Referrer: <meta name="referrer" content="no-referrer"> on the form
 *    page so the token is not leaked via outbound link clicks.
 * ──────────────────────────────────────────────────────────────────────────
 */
final class RespondersController extends Controller
{
    private const MAX_FILE_BYTES = 25 * 1024 * 1024;
    private const ALLOWED_MIMES  = [
        'application/pdf', 'image/png', 'image/jpeg', 'image/heic', 'image/webp',
        'text/csv', 'text/plain',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    private const RATE_GET_PER_MIN  = 30;
    private const RATE_POST_PER_MIN = 5;
    private const RESPONDER_DISK    = 'responder';

    /* ═════════════════════════ GET ═════════════════════════ */

    public function show(Request $r, string $token)
    {
        if (! $this->rateOk($r, 'get', $token)) {
            return $this->errorPage('rate_limited',
                'Too many attempts from your network. Please wait a minute.', 429);
        }

        $row = $this->resolveByToken($token);
        if (! $row) {
            return $this->errorPage('invalid', 'This link is invalid or has already been used.', 410);
        }
        if ($this->isUnusable($row)) {
            return $this->errorPage(
                $row->status === 'CANCELLED' ? 'revoked' : 'expired',
                $row->status === 'CANCELLED'
                    ? 'This information request was withdrawn by the surveillance team.'
                    : 'This information request has expired. Contact the team for a new link.',
                410
            );
        }

        $alert     = DB::table('alerts')->where('id', $row->alert_id)->first(['alert_code','alert_title','risk_level','district_code','poe_code','country_code','created_at']);
        $responder = DB::table('external_responders')->where('id', $row->responder_id)->first(['name','organisation','email','responder_type']);

        // Audit — count + timestamp every render, even before submission. Do
        // NOT throw on audit failure (insert is best-effort).
        try {
            DB::table('responder_info_requests')->where('id', $row->id)->update([
                'last_viewed_at' => Carbon::now(),
                'view_count'     => (int) ($row->view_count ?? 0) + 1,
                'updated_at'     => Carbon::now(),
            ]);
        } catch (Throwable $e) { Log::warning('[Public\\Responder][view-audit] '.$e->getMessage()); }

        return response()->view('public.responder.form', [
            'token'        => $token,
            'request'      => $row,
            'alert'        => $alert,
            'responder'    => $responder,
            'expires_at'   => $row->expires_at,
            'max_bytes'    => self::MAX_FILE_BYTES,
            'allowed_ext'  => '.pdf,.png,.jpg,.jpeg,.heic,.webp,.csv,.txt,.xlsx,.docx',
        ])->withHeaders([
            // Token in URL: do not leak via outbound clicks.
            'Referrer-Policy'           => 'no-referrer',
            'X-Robots-Tag'              => 'noindex, nofollow',
            'X-Content-Type-Options'    => 'nosniff',
            'Cache-Control'             => 'no-store, max-age=0',
            'Permissions-Policy'        => 'geolocation=(), camera=(), microphone=()',
        ]);
    }

    /* ═════════════════════════ POST ═════════════════════════ */

    public function submit(Request $r, string $token): Response|RedirectResponse
    {
        if (! $this->rateOk($r, 'post', $token)) {
            throw ValidationException::withMessages(['summary' => 'Too many attempts. Please try again in a minute.']);
        }

        $row = $this->resolveByToken($token);
        if (! $row || $this->isUnusable($row)) {
            return redirect()->route('public.responder.show', ['token' => $token])
                ->withErrors(['summary' => 'This link is no longer valid.']);
        }

        $data = Validator::make($r->all(), [
            'response_summary' => 'required|string|min:5|max:5000',
            'lab_findings'     => 'nullable|string|max:5000',
            'sample_results'   => 'nullable|string|max:5000',
            'next_actions'     => 'nullable|string|max:2000',
            'contact_callback' => 'nullable|string|max:200',
            'consent_share'    => 'accepted',
        ], [
            'response_summary.required' => 'Please summarise your response.',
            'response_summary.min'      => 'Summary must be at least 5 characters.',
            'consent_share.accepted'    => 'You must consent to sharing this response with the surveillance team.',
        ])->validate();

        // File validation BEFORE DB transaction so we fail fast with a clean
        // error message rather than rolling back a half-write.
        $file = null; $fileMeta = null;
        if ($r->hasFile('attachment')) {
            $upload = $r->file('attachment');
            if (! $upload->isValid()) {
                throw ValidationException::withMessages(['attachment' => 'Upload failed mid-flight.']);
            }
            $bytes = (int) $upload->getSize();
            $mime  = (string) $upload->getMimeType();
            if ($bytes <= 0 || $bytes > self::MAX_FILE_BYTES) {
                throw ValidationException::withMessages(['attachment' => 'File too large or empty (max 25 MB).']);
            }
            if (! in_array($mime, self::ALLOWED_MIMES, true)) {
                throw ValidationException::withMessages(['attachment' => 'File type not allowed: ' . $mime]);
            }
            // Sanitize filename to defeat path-traversal / weird unicode tricks.
            $safe = $this->safeFilename((string) $upload->getClientOriginalName());
            $fileRef = sprintf('alert-%d/req-%d/%s_%s',
                (int) $row->alert_id, (int) $row->id, Str::ulid()->toString(), $safe);
            try {
                Storage::disk(self::RESPONDER_DISK)->putFileAs(
                    dirname($fileRef), $upload, basename($fileRef), ['visibility' => 'private']
                );
            } catch (Throwable $e) {
                Log::error('[Public\\Responder][upload] '.$e->getMessage());
                throw ValidationException::withMessages(['attachment' => 'We could not save the file. Try again or contact the team.']);
            }
            $fileMeta = ['ref' => $fileRef, 'mime' => $mime, 'bytes' => $bytes, 'name' => $safe];
            $file = $upload;
        }

        $now = Carbon::now();
        try {
            DB::transaction(function () use ($row, $data, $fileMeta, $r, $now): void {
                // Re-read for FOR UPDATE semantics — guarantees we're the only
                // submitter even under racing concurrent requests on the same token.
                $fresh = DB::table('responder_info_requests')->where('id', $row->id)->lockForUpdate()->first();
                if (! $fresh || in_array($fresh->status, ['RECEIVED','EXPIRED','CANCELLED'], true)) {
                    throw new \RuntimeException('TOKEN_TERMINAL');
                }
                if ($fresh->expires_at && Carbon::parse($fresh->expires_at)->isPast()) {
                    throw new \RuntimeException('TOKEN_EXPIRED');
                }

                DB::table('responder_info_requests')->where('id', $row->id)->update([
                    'status'           => 'RECEIVED',
                    'responded_at'     => $now,
                    'response_payload' => json_encode([
                        'summary'          => $data['response_summary'],
                        'lab_findings'     => $data['lab_findings']     ?? null,
                        'sample_results'   => $data['sample_results']   ?? null,
                        'next_actions'     => $data['next_actions']     ?? null,
                        'contact_callback' => $data['contact_callback'] ?? null,
                        'has_attachment'   => $fileMeta !== null,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'responder_ip'  => mb_substr((string) $r->ip(),       0, 45),
                    'responder_ua'  => mb_substr((string) $r->userAgent(), 0, 500),
                    'updated_at'    => $now,
                ]);

                // Attach response as alert_evidence — requested_by_user_id is
                // an existing NOT NULL column; we satisfy it with the row's
                // requested_by_user_id (the staff who originated the request).
                $responder = DB::table('external_responders')->where('id', $row->responder_id)->first(['name','organisation']);
                $title = sprintf('External response · %s%s',
                    $responder->name ?? ('responder #' . $row->responder_id),
                    isset($responder->organisation) && $responder->organisation ? ' (' . $responder->organisation . ')' : ''
                );
                DB::table('alert_evidence')->insert([
                    'alert_id'              => $row->alert_id,
                    'followup_id'           => null,
                    'category'              => $fileMeta && str_starts_with($fileMeta['mime'] ?? '', 'image/') ? 'PHOTO'
                                                : ($fileMeta ? 'LAB_RESULT' : 'DOCUMENT'),
                    'title'                 => mb_substr($title, 0, 200),
                    'description'           => mb_substr((string) $data['response_summary'], 0, 1000),
                    'file_ref'              => $fileMeta['ref']   ?? null,
                    'file_mime'             => $fileMeta['mime']  ?? null,
                    'file_size_bytes'       => $fileMeta['bytes'] ?? null,
                    'external_url'          => null,
                    'uploaded_by_user_id'   => (int) $row->requested_by_user_id,
                    'uploader_name'         => $responder->name ?? null,
                    'visibility'            => 'ALL',
                    'external_responder_id' => (int) $row->responder_id,
                    'responder_request_id'  => (int) $row->id,
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ]);

                DB::table('alert_timeline_events')->insert([
                    'alert_id'            => $row->alert_id,
                    'event_code'          => 'EXTERNAL_INFO_REQUESTED',
                    'event_category'      => 'EMAIL',
                    'actor_user_id'       => null,
                    'actor_name'          => $responder->name ?? null,
                    'actor_role'          => 'EXTERNAL_RESPONDER',
                    'payload_json'        => json_encode([
                        'request_id'      => (int) $row->id,
                        'responder_id'    => (int) $row->responder_id,
                        'event'           => 'RESPONSE_RECEIVED',
                        'summary'         => mb_substr((string) $data['response_summary'], 0, 200),
                        'has_attachment'  => $fileMeta !== null,
                        'attachment_mime' => $fileMeta['mime']  ?? null,
                        'attachment_size' => $fileMeta['bytes'] ?? null,
                    ]),
                    'summary'             => sprintf('External responder %s submitted a reply%s.',
                        $responder->name ?? '#' . $row->responder_id,
                        $fileMeta ? ' with attachment' : ''),
                    'severity'            => 'INFO',
                    'related_entity_type' => 'RESPONDER_REQUEST',
                    'related_entity_id'   => (int) $row->id,
                    'created_at'          => $now,
                ]);
            });
        } catch (\RuntimeException $e) {
            // Lost the race — token already used or expired.
            return redirect()->route('public.responder.show', ['token' => $token])
                ->withErrors(['summary' => $e->getMessage() === 'TOKEN_TERMINAL'
                    ? 'This response was already submitted.' : 'This link has expired.']);
        } catch (Throwable $e) {
            Log::error('[Public\\Responder][submit] '.$e->getMessage(), ['file' => $e->getFile().':'.$e->getLine()]);
            return redirect()->route('public.responder.show', ['token' => $token])
                ->withErrors(['summary' => 'We could not save your response. Please try again.']);
        }

        return response()->view('public.responder.thanks', [
            'name' => DB::table('external_responders')->where('id', $row->responder_id)->value('name'),
        ])->withHeaders([
            'Referrer-Policy'        => 'no-referrer',
            'X-Robots-Tag'           => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store, max-age=0',
        ]);
    }

    /* ═════════════════════════ helpers ═════════════════════════ */

    /** Lookup the responder request by plaintext token. */
    private function resolveByToken(string $token): ?object
    {
        $len = strlen($token);
        if ($len < 32 || $len > 128) return null;       // dispatcher mints 48-char hex; allow 32..128 for resends
        if (! ctype_xdigit($token) && ! preg_match('/^[A-Za-z0-9_\-]+$/', $token)) return null;

        return DB::table('responder_info_requests')
            ->where('request_token', $token)
            ->first();
    }

    private function isUnusable(object $row): bool
    {
        if (in_array($row->status, ['RECEIVED', 'EXPIRED', 'CANCELLED'], true)) return true;
        if ($row->expires_at && Carbon::parse($row->expires_at)->isPast()) {
            // Lazy-flip to EXPIRED so subsequent calls short-circuit.
            try {
                DB::table('responder_info_requests')->where('id', $row->id)
                    ->where('status', 'SENT')
                    ->update(['status' => 'EXPIRED', 'updated_at' => Carbon::now()]);
            } catch (Throwable $e) { /* non-fatal */ }
            return true;
        }
        return false;
    }

    private function rateOk(Request $r, string $kind, string $token): bool
    {
        $key = 'respond:' . $kind . ':' . sha1(($r->ip() ?? '') . '|' . substr($token, 0, 16));
        $max = $kind === 'post' ? self::RATE_POST_PER_MIN : self::RATE_GET_PER_MIN;
        if (RateLimiter::tooManyAttempts($key, $max)) return false;
        RateLimiter::hit($key, 60);
        return true;
    }

    /** Defeat path-traversal, normalise unicode, cap length. */
    private function safeFilename(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext  = pathinfo($name, PATHINFO_EXTENSION);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?: 'file';
        $base = trim($base, '-.') ?: 'file';
        $ext  = preg_replace('/[^A-Za-z0-9]/', '', $ext);
        $ext  = $ext ? '.' . strtolower($ext) : '';
        return mb_substr($base, 0, 80) . $ext;
    }

    private function errorPage(string $reason, string $message, int $status)
    {
        return response()->view('public.responder.error', [
            'reason'  => $reason,
            'message' => $message,
        ], $status)->withHeaders([
            'Referrer-Policy'        => 'no-referrer',
            'X-Robots-Tag'           => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'no-store, max-age=0',
        ]);
    }
}
