<?php

declare(strict_types=1);

namespace App\Services;

/**
 * AdminLinks — builds deep links INTO the admin panel for use inside email
 * templates. Replaces the mobile-PWA `#/active-alerts` hash routes that are
 * currently (incorrectly) used as `console_url`.
 *
 *   · Every URL is built from config('app.url') → swap APP_URL in .env and
 *     every future email will carry the new host (no template rewrite).
 *   · Routes here MUST exist in routes/web.php (admin. namespace). When a new
 *     destination is added the matching route must ship at the same time, or
 *     the link will 404.
 *   · Callers should prefer the named builder (e.g. forAlert) so we can add
 *     analytics tokens, deep-link resolver, or SSO exchange in ONE place later.
 */
final class AdminLinks
{
    /** Base URL from APP_URL with trailing slash trimmed. */
    public static function base(): string
    {
        return rtrim((string) config('app.url', 'http://localhost'), '/');
    }

    /** Dashboard landing — general-purpose CTA for digests and auth emails. */
    public static function dashboard(): string
    {
        return self::base() . '/admin/dashboard';
    }

    /** Hub (kanban) for all alerts — filter-friendly. */
    public static function alertsHub(array $query = []): string
    {
        $url = self::base() . '/admin/alerts';
        if (! empty($query)) $url .= '?' . http_build_query($query);
        return $url;
    }

    /** War Room for one alert. */
    public static function alertWarRoom(int $alertId, array $query = []): string
    {
        $url = self::base() . '/admin/alerts/' . $alertId;
        if (! empty($query)) $url .= '?' . http_build_query($query);
        return $url;
    }

    /** Users admin page — optional `focus=<id>` deep-link. */
    public static function userProfile(int $userId): string
    {
        return self::base() . '/admin/users?focus=' . $userId;
    }

    /** Accept-invitation public page (no auth). */
    public static function acceptInvite(string $token, ?string $email = null): string
    {
        $qs = ['token' => $token];
        if ($email) $qs['email'] = $email;
        return self::base() . '/accept-invite?' . http_build_query($qs);
    }

    /** Reset-password public page (no auth). */
    public static function resetPassword(string $token, ?string $email = null): string
    {
        $qs = ['token' => $token];
        if ($email) $qs['email'] = $email;
        return self::base() . '/reset-password?' . http_build_query($qs);
    }

    /** Verify-email public page (no auth). */
    public static function verifyEmail(string $token, ?string $email = null): string
    {
        $qs = ['token' => $token];
        if ($email) $qs['email'] = $email;
        return self::base() . '/verify-email?' . http_build_query($qs);
    }

    /** Root for external responder portal (token-gated public page). */
    public static function responderPortal(string $token): string
    {
        return self::base() . '/respond/' . $token;
    }

    /**
     * Single dict of every CTA a given alert-context template might want.
     * Templates read `{{ action_url }}` as the PRIMARY button (already a
     * dashboard URL for the alert); secondary links come via `{{ hub_url }}`,
     * `{{ dashboard_url }}`, etc.
     *
     * @return array<string,string>
     */
    public static function alertVars(object $alert): array
    {
        $id = (int) ($alert->id ?? 0);
        return [
            'action_url'    => $id ? self::alertWarRoom($id) : self::alertsHub(),
            'warroom_url'   => $id ? self::alertWarRoom($id) : self::alertsHub(),
            'hub_url'       => self::alertsHub(),
            'dashboard_url' => self::dashboard(),
            'app_url'       => self::base(),
        ];
    }

    /**
     * Variables for non-alert-tied templates (digests, auth emails, etc.).
     * @return array<string,string>
     */
    public static function generalVars(): array
    {
        return [
            'action_url'    => self::dashboard(),
            'dashboard_url' => self::dashboard(),
            'hub_url'       => self::alertsHub(),
            'app_url'       => self::base(),
        ];
    }

    /**
     * Render a standardized CTA footer block to append to any email whose
     * template doesn't already link to the admin panel. The caller picks the
     * primary URL (e.g. War Room for an alert, reset-password for an auth
     * email). Label defaults to "Open Command Centre".
     */
    public static function ctaHtml(string $url, string $label = 'Open Command Centre'): string
    {
        $safeUrl   = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        return '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:24px 0 8px 0">'
             . '<tr><td align="center">'
             . '<a href="' . $safeUrl . '" '
             . 'style="display:inline-block;background:#111a33;color:#ffffff;padding:12px 22px;'
             . 'border-radius:8px;font-family:Inter,Arial,Helvetica,sans-serif;font-size:14px;'
             . 'font-weight:600;text-decoration:none;letter-spacing:.02em">'
             . $safeLabel . ' &rarr;</a>'
             . '<div style="margin-top:10px;font-family:Inter,Arial,Helvetica,sans-serif;'
             . 'font-size:11px;color:#6b7a99">'
             . 'Or paste this into your browser: <span style="color:#26365a;word-break:break-all">'
             . $safeUrl . '</span>'
             . '</div>'
             . '</td></tr></table>';
    }

    /**
     * Ensure a rendered email body contains a link to the admin panel. If
     * the body already references `<a href` pointing at our base URL, leave
     * it alone; otherwise inject a CTA block just before `</body>` (or at
     * the end if no body tag). Always returns a string — never throws.
     */
    public static function ensureCtaAppended(string $html, string $url, string $label = 'Open Command Centre'): string
    {
        // Already links to our host? Don't double-up.
        $host = parse_url(self::base(), PHP_URL_HOST);
        if ($host && stripos($html, 'href="' . self::base()) !== false) return $html;
        if ($host && preg_match('/<a\\s+[^>]*href="https?:\\/\\/[^"]*' . preg_quote($host, '/') . '/i', $html)) return $html;

        $cta = self::ctaHtml($url, $label);
        $pos = stripos($html, '</body>');
        if ($pos !== false) return substr($html, 0, $pos) . $cta . substr($html, $pos);
        return $html . $cta;
    }
}
