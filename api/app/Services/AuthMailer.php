<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * AuthMailer — sends the auth-family emails via notification_templates.
 *
 * Uses the same multipart send path as NotificationDispatcher::send() so
 * the TEST_MODE whitelist + anti-spam headers + HTML/text rendering stay
 * consistent with everything else the platform emits.
 *
 * Supported templates (all seeded by 10_auth_email_templates.sql):
 *   AUTH_WELCOME · AUTH_VERIFY_EMAIL · AUTH_PASSWORD_RESET
 *   AUTH_PASSWORD_CHANGED · AUTH_TWOFA_ENABLED · AUTH_TWOFA_DISABLED
 *   AUTH_NEW_LOGIN_DEVICE · AUTH_ACCOUNT_LOCKED · AUTH_ACCOUNT_UNLOCKED
 *   AUTH_INVITATION · AUTH_ROLE_CHANGED · AUTH_SUSPENDED
 */
final class AuthMailer
{
    public static function send(string $templateCode, string $toEmail, array $vars): array
    {
        try {
            $tpl = DB::table('notification_templates')
                ->where('template_code', $templateCode)
                ->where('channel', 'EMAIL')
                ->where('is_active', 1)
                ->first();
            if (! $tpl) return ['status' => 'SKIPPED', 'error' => 'Template missing'];

            // TEST_MODE gate REMOVED — production deployment. Auth-related mail
            // (invites, password resets, verification) now always reaches the
            // live recipient + OPS_CC national roster.

            // Inject standard admin-panel URL variables so every template
            // can reference {{ action_url }} / {{ dashboard_url }} / {{ hub_url }}
            // without the caller having to supply them individually.
            $vars = array_merge(AdminLinks::generalVars(), $vars);

            $subject  = self::render((string) $tpl->subject_template,  $vars);
            $htmlBody = self::render((string) $tpl->body_html_template, $vars);
            $textTpl  = (string) ($tpl->body_text_template ?? '');
            $textBody = $textTpl !== '' ? self::render($textTpl, $vars) : self::htmlToText($htmlBody);

            // If the template body doesn't already link to the admin panel,
            // append a standard CTA. The link target depends on the auth flow:
            //   invitation → accept-invite
            //   password reset → reset-password
            //   email verify → verify-email
            //   anything else → /admin/dashboard
            $ctaUrl = $vars['invite_url']
                ?? $vars['reset_url']
                ?? $vars['verify_url']
                ?? (string) ($vars['action_url'] ?? AdminLinks::dashboard());
            $ctaLabel = match ($templateCode) {
                'AUTH_INVITATION'     => 'Accept invitation',
                'AUTH_PASSWORD_RESET' => 'Reset password',
                'AUTH_VERIFY_EMAIL'   => 'Verify email',
                default               => 'Open Command Centre',
            };
            $htmlBody = AdminLinks::ensureCtaAppended($htmlBody, $ctaUrl, $ctaLabel);
            $textBody = $textBody . "\n\n" . $ctaLabel . ': ' . $ctaUrl;

            $replyAddr = env('MAIL_REPLY_TO_ADDRESS');
            $replyName = env('MAIL_REPLY_TO_NAME');

            Mail::send([], [], function ($m) use ($toEmail, $subject, $htmlBody, $textBody, $replyAddr, $replyName) {
                $m->to($toEmail)->subject($subject)->html($htmlBody)->text($textBody);
                if ($replyAddr) $m->replyTo($replyAddr, $replyName ?: null);
                try {
                    $headers = $m->getHeaders();
                    $headers->addTextHeader('X-Auto-Response-Suppress', 'OOF, AutoReply');
                    $headers->addTextHeader('Auto-Submitted', 'auto-generated');
                    $headers->addTextHeader('X-Entity-Ref-ID', 'poe-auth-' . bin2hex(random_bytes(8)));
                } catch (Throwable) { /* best-effort */ }
            });

            // Log to notification_log for inbox + audit visibility
            DB::table('notification_log')->insert([
                'to_email'      => $toEmail,
                'channel'       => 'EMAIL',
                'template_code' => $templateCode,
                'subject'       => mb_substr($subject, 0, 240),
                'body_full'     => $htmlBody,
                'status'        => 'SENT',
                'triggered_by'  => 'AUTH:mailer',
                'related_entity_type' => 'USER',
                'related_entity_id'   => isset($vars['user_id']) ? (int) $vars['user_id'] : null,
                'created_at'    => now(), 'updated_at' => now(),
            ]);

            return ['status' => 'SENT'];
        } catch (Throwable $e) {
            Log::error('[AuthMailer] ' . $e->getMessage());
            return ['status' => 'FAILED', 'error' => $e->getMessage()];
        }
    }

    private static function render(string $tpl, array $vars): string
    {
        $out = preg_replace_callback('/\{\{\{\s*([a-z0-9_]+)\s*\}\}\}/i',
            fn($m) => (string) ($vars[$m[1]] ?? ''), $tpl) ?? $tpl;
        return preg_replace_callback('/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            fn($m) => htmlspecialchars((string) ($vars[$m[1]] ?? ''), ENT_QUOTES, 'UTF-8'),
            $out) ?? $out;
    }

    private static function htmlToText(string $html): string
    {
        $s = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $s = preg_replace('/<br\s*\/?>/i', "\n", $s) ?? $s;
        $s = preg_replace('/<\/(p|div|tr|li|h[1-6])>/i', "\n", $s) ?? $s;
        $s = strip_tags($s);
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = preg_replace("/[ \t]+/", ' ', $s) ?? $s;
        $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
        return trim($s);
    }
}
