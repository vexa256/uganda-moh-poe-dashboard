<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AuthEmailTemplatesSeeder
 * ─────────────────────────────────────────────────────────────────────────
 * Ten auth-family notification_templates — each with a distinct hero
 * palette (security-intent colours) and aggressive detail on device /
 * time / action. Idempotent via updateOrInsert on template_code.
 *
 * Run:
 *   php artisan db:seed --class=AuthEmailTemplatesSeeder
 */
class AuthEmailTemplatesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->templates() as $t) {
            DB::table('notification_templates')->updateOrInsert(
                ['template_code' => $t['code'], 'channel' => 'EMAIL'],
                [
                    'subject_template'   => $t['subject'],
                    'body_html_template' => $t['html'],
                    'body_text_template' => $t['text'],
                    'applicable_levels'  => json_encode(['SELF']),
                    'is_ai_enhanced'     => 0,
                    'is_active'          => 1,
                    'updated_at'         => now(),
                    'created_at'         => now(),
                ]
            );
        }
    }

    private const WRAP_OPEN = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#EEF2F7;font-family:Arial,Helvetica,sans-serif;color:#0F172A;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF2F7;padding:24px 12px;"><tr><td align="center"><table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#FFFFFF;border-radius:10px;overflow:hidden;box-shadow:0 4px 16px rgba(15,23,42,0.08);">';
    private const WRAP_CLOSE = '</table></td></tr></table></body></html>';

    private function footer(string $tone): string
    {
        return <<<HTML
<tr><td style="padding:16px 28px;background:#F1F5F9;border-top:1px solid #E2E8F0;">
  <p style="margin:0;font-size:11px;color:#475569;line-height:1.55;">
    <strong>{{app_name}}</strong> · {$tone}<br>
    If this wasn't you, contact your administrator immediately and reset your password.<br>
    Generated {{now}} UTC from {{ip}}.
  </p>
</td></tr>
HTML;
    }

    private function actionButton(string $label, string $urlVar, string $color = '#047857'): string
    {
        return <<<HTML
<tr><td style="padding:18px 28px 10px;">
  <div style="background:{$color};border-radius:8px;padding:14px 16px;text-align:center;">
    <a href="{{{$urlVar}}}" style="color:#FFFFFF;font-size:14px;font-weight:700;text-decoration:none;">{$label} →</a>
  </div>
  <p style="margin:10px 0 0;font-size:11px;color:#64748B;text-align:center;">Or paste this link into your browser:<br><span style="word-break:break-all;">{{{$urlVar}}}</span></p>
</td></tr>
HTML;
    }

    private function deviceCard(): string
    {
        return <<<HTML
<tr><td style="padding:14px 28px 0;">
  <div style="font-size:11px;letter-spacing:0.08em;text-transform:uppercase;color:#475569;font-weight:700;">Device + location</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px;background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;">
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">IP address</td><td style="padding:6px 12px;text-align:right;font-size:12px;font-family:ui-monospace,monospace;">{{ip}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">User agent</td><td style="padding:6px 12px;text-align:right;font-size:12px;">{{user_agent}}</td></tr>
    <tr><td style="padding:6px 12px;font-size:12px;color:#475569;">Time (UTC)</td><td style="padding:6px 12px;text-align:right;font-size:12px;">{{now}}</td></tr>
  </table>
</td></tr>
HTML;
    }

    private function templates(): array
    {
        return [
            $this->welcome(),
            $this->invitation(),
            $this->verifyEmail(),
            $this->passwordReset(),
            $this->passwordChanged(),
            $this->twofaEnabled(),
            $this->twofaDisabled(),
            $this->newLoginDevice(),
            $this->accountLocked(),
            $this->suspended(),
        ];
    }

    // ── templates ──────────────────────────────────────────────────────────
    private function welcome(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#064e3b 0%,#047857 55%,#10b981 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#A7F3D0;font-weight:700;">{{app_name}} · Welcome</div>
  <div style="margin-top:10px;font-size:22px;font-weight:800;color:#fff;">Hello, {{full_name}}</div>
  <div style="margin-top:6px;font-size:13px;color:#D1FAE5;">Your account is now active.</div>
</td></tr>
<tr><td style="padding:22px 28px 6px;font-size:13px;">
  <p style="margin:0 0 12px;">Welcome to {{app_name}}. Your account has been created and is ready to use.</p>
  <ul style="margin:0 0 12px 18px;padding:0;">
    <li>Sign in at <a href="{{console_url}}" style="color:#047857;">{{console_url}}</a></li>
    <li>Turn on Two-Factor Authentication in Settings → Security</li>
    <li>Review your assignments and notification preferences</li>
  </ul>
</td></tr>
HTML . $this->footer('Welcome notification') . self::WRAP_CLOSE;
        return [
            'code' => 'AUTH_WELCOME',
            'subject' => '{{app_name}} · Your account is ready, {{full_name}}',
            'html' => $html,
            'text' => "Hello {{full_name}},\n\nYour {{app_name}} account is active.\nSign in at {{console_url}}\n\nIP {{ip}} · {{now}} UTC",
        ];
    }

    private function invitation(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#0c1b3a 0%,#1e293b 55%,#334155 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#cbd5e1;font-weight:700;">{{app_name}} · Invitation</div>
  <div style="margin-top:10px;font-size:22px;font-weight:800;color:#fff;">You've been invited</div>
  <div style="margin-top:6px;font-size:13px;color:#cbd5e1;">{{inviter}} invited you as <strong style="color:#fff;">{{role_display}}</strong>.</div>
</td></tr>
<tr><td style="padding:22px 28px 0;font-size:13px;">
  <p style="margin:0 0 10px;">Hello {{full_name}},</p>
  <p style="margin:0 0 10px;">Click the button below to set your password and activate your {{app_name}} account. This invitation expires in <strong>{{expires_in}}</strong>.</p>
</td></tr>
HTML . $this->actionButton('Accept invitation', 'invite_url', '#0F172A')
     . $this->deviceCard()
     . $this->footer('Admin invitation') . self::WRAP_CLOSE;
        return [
            'code' => 'AUTH_INVITATION',
            'subject' => 'You have been invited to {{app_name}} as {{role_display}}',
            'html' => $html,
            'text' => "Hello {{full_name}},\n\n{{inviter}} has invited you to join {{app_name}} as {{role_display}}.\nAccept here (expires in {{expires_in}}): {{invite_url}}",
        ];
    }

    private function verifyEmail(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#0369a1 0%,#0284c7 55%,#0ea5e9 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#bae6fd;font-weight:700;">{{app_name}} · Email verification</div>
  <div style="margin-top:10px;font-size:22px;font-weight:800;color:#fff;">Verify your email</div>
</td></tr>
<tr><td style="padding:22px 28px 0;font-size:13px;">
  <p style="margin:0 0 10px;">Hello {{full_name}},</p>
  <p style="margin:0 0 10px;">Confirm that <strong>{{email}}</strong> belongs to you. This link is valid for <strong>{{expires_in}}</strong>.</p>
</td></tr>
HTML . $this->actionButton('Verify email address', 'verify_url', '#0369a1')
     . $this->deviceCard()
     . $this->footer('Email verification') . self::WRAP_CLOSE;
        return [
            'code' => 'AUTH_VERIFY_EMAIL',
            'subject' => 'Verify your {{app_name}} email',
            'html' => $html,
            'text' => "Hello {{full_name}},\nVerify your email: {{verify_url}}\nExpires in {{expires_in}}.",
        ];
    }

    private function passwordReset(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#b45309 0%,#d97706 55%,#f59e0b 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#fef3c7;font-weight:700;">{{app_name}} · Password reset</div>
  <div style="margin-top:10px;font-size:22px;font-weight:800;color:#fff;">Reset your password</div>
</td></tr>
<tr><td style="padding:22px 28px 0;font-size:13px;">
  <p style="margin:0 0 10px;">Hello {{full_name}},</p>
  <p style="margin:0 0 10px;">We received a request to reset the password for <strong>{{email}}</strong>. Click below to pick a new password. This link is valid for <strong>{{expires_in}}</strong>.</p>
  <p style="margin:0 0 10px;color:#92400E;"><strong>Didn't request this?</strong> Ignore this email — your password will not change.</p>
</td></tr>
HTML . $this->actionButton('Reset password', 'reset_url', '#b45309')
     . $this->deviceCard()
     . $this->footer('Password reset request') . self::WRAP_CLOSE;
        return [
            'code' => 'AUTH_PASSWORD_RESET',
            'subject' => 'Reset your {{app_name}} password',
            'html' => $html,
            'text' => "Reset link (expires in {{expires_in}}): {{reset_url}}\nIf you didn't request this, ignore this email.",
        ];
    }

    private function passwordChanged(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#064e3b 0%,#047857 55%,#10b981 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#a7f3d0;font-weight:700;">{{app_name}} · Password changed</div>
  <div style="margin-top:10px;font-size:22px;font-weight:800;color:#fff;">Your password was changed</div>
</td></tr>
<tr><td style="padding:22px 28px 0;font-size:13px;">
  <p style="margin:0 0 10px;">Hello {{full_name}},</p>
  <p style="margin:0 0 10px;">Your {{app_name}} password was changed at <strong>{{now}} UTC</strong>. All existing sessions have been revoked — you'll need to sign in again on every device.</p>
  <p style="margin:0 0 10px;color:#b91c1c;"><strong>Wasn't you?</strong> Reset your password immediately and contact your administrator.</p>
</td></tr>
HTML . $this->deviceCard() . $this->footer('Security notice') . self::WRAP_CLOSE;
        return [
            'code' => 'AUTH_PASSWORD_CHANGED',
            'subject' => '{{app_name}} · Your password was changed',
            'html' => $html,
            'text' => "Your password was changed at {{now}} UTC from {{ip}}.\nIf this wasn't you, reset immediately and contact an administrator.",
        ];
    }

    private function twofaEnabled(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#064e3b 0%,#047857 55%,#10b981 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#a7f3d0;font-weight:700;">{{app_name}} · 2FA enabled</div>
  <div style="margin-top:10px;font-size:22px;font-weight:800;color:#fff;">Two-factor authentication is on</div>
</td></tr>
<tr><td style="padding:22px 28px 0;font-size:13px;">
  <p style="margin:0 0 10px;">Hello {{full_name}},</p>
  <p style="margin:0 0 10px;">2FA (TOTP) is now required whenever you sign in. Keep your authenticator app (Google Authenticator, 1Password, Authy) safe, and <strong>store your recovery codes offline</strong>.</p>
</td></tr>
HTML . $this->deviceCard() . $this->footer('Security event · 2FA') . self::WRAP_CLOSE;
        return [
            'code' => 'AUTH_TWOFA_ENABLED',
            'subject' => '{{app_name}} · 2FA enabled on your account',
            'html' => $html,
            'text' => "Two-factor authentication was enabled on your account at {{now}} UTC.",
        ];
    }

    private function twofaDisabled(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#7f1d1d 0%,#b91c1c 55%,#dc2626 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#fecaca;font-weight:700;">{{app_name}} · 2FA disabled</div>
  <div style="margin-top:10px;font-size:22px;font-weight:800;color:#fff;">Two-factor authentication was disabled</div>
</td></tr>
<tr><td style="padding:22px 28px 0;font-size:13px;">
  <p style="margin:0 0 10px;">Hello {{full_name}},</p>
  <p style="margin:0 0 10px;"><strong>2FA is now OFF</strong> on your {{app_name}} account. Your account is now only protected by your password.</p>
  <p style="margin:0 0 10px;color:#b91c1c;"><strong>Wasn't you?</strong> Reset your password immediately and enable 2FA again.</p>
</td></tr>
HTML . $this->deviceCard() . $this->footer('Security warning · 2FA removed') . self::WRAP_CLOSE;
        return [
            'code' => 'AUTH_TWOFA_DISABLED',
            'subject' => '⚠ {{app_name}} · 2FA was disabled on your account',
            'html' => $html,
            'text' => "Two-factor authentication was DISABLED at {{now}} UTC from {{ip}}.",
        ];
    }

    private function newLoginDevice(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#0369a1 0%,#0284c7 55%,#0ea5e9 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#bae6fd;font-weight:700;">{{app_name}} · New sign-in</div>
  <div style="margin-top:10px;font-size:22px;font-weight:800;color:#fff;">Sign-in from a new device</div>
</td></tr>
<tr><td style="padding:22px 28px 0;font-size:13px;">
  <p style="margin:0 0 10px;">Hello {{full_name}},</p>
  <p style="margin:0 0 10px;">Your account was signed in from a device we haven't seen in the last 30 days.</p>
  <p style="margin:0 0 10px;"><strong>Device:</strong> {{device_label}}</p>
</td></tr>
HTML . $this->deviceCard() . $this->footer('New sign-in notice') . self::WRAP_CLOSE;
        return [
            'code' => 'AUTH_NEW_LOGIN_DEVICE',
            'subject' => '{{app_name}} · New sign-in on {{device_label}}',
            'html' => $html,
            'text' => "New sign-in on {{device_label}} at {{now}} UTC from {{ip}}.\nIf this wasn't you, reset your password.",
        ];
    }

    private function accountLocked(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#7f1d1d 0%,#b91c1c 55%,#dc2626 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#fecaca;font-weight:700;">{{app_name}} · Account locked</div>
  <div style="margin-top:10px;font-size:22px;font-weight:800;color:#fff;">Your account is temporarily locked</div>
</td></tr>
<tr><td style="padding:22px 28px 0;font-size:13px;">
  <p style="margin:0 0 10px;">Hello {{full_name}},</p>
  <p style="margin:0 0 10px;">We detected <strong>{{failed_count}}</strong> failed sign-in attempts on your account. Your account is locked until <strong>{{locked_until}}</strong>.</p>
  <p style="margin:0 0 10px;">If this wasn't you, we recommend resetting your password.</p>
</td></tr>
HTML . $this->deviceCard() . $this->footer('Security event · lockout') . self::WRAP_CLOSE;
        return [
            'code' => 'AUTH_ACCOUNT_LOCKED',
            'subject' => '⚠ {{app_name}} · Account locked after failed sign-in attempts',
            'html' => $html,
            'text' => "Your account is locked until {{locked_until}} after {{failed_count}} failed attempts from {{ip}}.",
        ];
    }

    private function suspended(): array
    {
        $html = self::WRAP_OPEN . <<<'HTML'
<tr><td style="background:linear-gradient(135deg,#7f1d1d 0%,#991b1b 55%,#b91c1c 100%);padding:24px 28px;">
  <div style="font-size:11px;letter-spacing:0.18em;text-transform:uppercase;color:#fecaca;font-weight:700;">{{app_name}} · Access suspended</div>
  <div style="margin-top:10px;font-size:22px;font-weight:800;color:#fff;">Your account has been suspended</div>
</td></tr>
<tr><td style="padding:22px 28px 0;font-size:13px;">
  <p style="margin:0 0 10px;">Hello {{full_name}},</p>
  <p style="margin:0 0 10px;">An administrator has suspended your access to {{app_name}}.</p>
  <p style="margin:0 0 10px;"><strong>Reason:</strong> {{reason}}</p>
  <p style="margin:0 0 10px;">If you believe this is a mistake, contact your administrator.</p>
</td></tr>
HTML . $this->footer('Administrative action') . self::WRAP_CLOSE;
        return [
            'code' => 'AUTH_SUSPENDED',
            'subject' => '{{app_name}} · Account access suspended',
            'html' => $html,
            'text' => "Your access to {{app_name}} has been suspended.\nReason: {{reason}}\nTime: {{now}} UTC",
        ];
    }
}
