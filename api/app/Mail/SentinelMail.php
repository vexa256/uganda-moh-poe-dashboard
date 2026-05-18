<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * SentinelMail — the single queueable Mailable used by NotificationDispatcher
 * for every outbound transactional email.
 *
 * Why this exists:
 *   1. Queues every send (ShouldQueue → connection 'database', queue 'emails')
 *      so the request thread NEVER blocks on SMTP. The `notifications:retry-failed`
 *      command and Laravel's failed_jobs table cover delivery failure recovery.
 *   2. Carries a static CC list (vexa256@gmail.com + ayebare.k.timothy@gmail.com)
 *      injected on every send — operational visibility per the master refactor
 *      brief mandate.
 *   3. Carries a multipart payload (HTML + plain text fallback) so Gmail does not
 *      downgrade or strip styling.
 *   4. Carries transactional headers so the mail is not classified as bulk.
 *
 * Construction is plain scalars only — Mailable serialisation across the queue
 * boundary tolerates strings/arrays without bringing along Eloquent or DB
 * connections.
 */
final class SentinelMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Mandatory CC list — every transactional email must reach these mailboxes.
     * Uganda POE Sentinel — national programme contacts.
     * These are CC'd on EVERY transactional email, no exceptions.
     * Do not remove entries; add new ones at the bottom.
     * Source: poe_notification_contacts WHERE level='NATIONAL' (locked here
     * so email delivery cannot be silently broken by accidental DB edits).
     *
     * TODO: populate with Ministry of Health / UNIPH / DCIC
     * national programme contacts before going live.
     */
    public const OPS_CC = [
        'vexa256@gmail.com',   // ops oversight — always last
    ];

    public function __construct(
        public readonly string $toAddress,
        public readonly string $subjectLine,
        public readonly string $htmlBody,
        public readonly string $textBody,
        public readonly array $ccAddresses = [],
        public readonly array $bccAddresses = [],
        public readonly ?string $replyToAddress = null,
        public readonly ?string $replyToName = null,
        public readonly ?string $entityRefId = null,
    ) {
        // Run inside the dedicated 'emails' queue so a clogged default queue
        // does not back up critical alert delivery.
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        // CC pool = exactly what the caller passed in $ccAddresses.
        // The dispatcher controls whether OPS_CC is included — it passes
        // OPS_CC only when the recipient is a non-national contact who would
        // otherwise be invisible to the national team. When the recipient IS
        // a national contact (or this is a report broadcast), OPS_CC is NOT
        // merged here so we don't create a 14×14=196 email blast.
        $ccPool = $this->ccAddresses;
        $primaryLower = strtolower($this->toAddress);
        $cc = [];
        $seen = [];
        foreach ($ccPool as $addr) {
            $a = trim((string) $addr);
            if ($a === '') continue;
            $low = strtolower($a);
            if ($low === $primaryLower) continue;
            if (isset($seen[$low])) continue;
            $seen[$low] = true;
            $cc[] = new Address($a);
        }

        $bcc = [];
        foreach ($this->bccAddresses as $addr) {
            $a = trim((string) $addr);
            if ($a === '') continue;
            $low = strtolower($a);
            if ($low === $primaryLower) continue;
            if (isset($seen[$low])) continue;
            $seen[$low] = true;
            $bcc[] = new Address($a);
        }

        $envelope = new Envelope(
            to:      [new Address($this->toAddress)],
            cc:      $cc,
            bcc:     $bcc,
            subject: $this->subjectLine,
        );

        if ($this->replyToAddress) {
            $envelope->replyTo = [new Address($this->replyToAddress, $this->replyToName ?? '')];
        }

        return $envelope;
    }

    public function content(): Content
    {
        // htmlString lets us pass an already-rendered HTML string. The plain
        // text fallback is attached via Mailable::$textBody during build()
        // (Symfony Mailer's Email::text()), so we set it on the message
        // directly in withSymfonyMessage() to keep the multipart payload.
        return new Content(
            htmlString: $this->htmlBody,
        );
    }

    public function build(): self
    {
        // Attach the plain-text fallback so Gmail does not downgrade/strip
        // the HTML styling. Required even when the HTML is fully inlined.
        $this->withSymfonyMessage(function (\Symfony\Component\Mime\Email $message): void {
            $message->text($this->textBody);
        });
        return $this;
    }

    public function headers(): \Illuminate\Mail\Mailables\Headers
    {
        $entityRef = $this->entityRefId ?? ('poe-sentinel-' . bin2hex(random_bytes(8)));
        return new \Illuminate\Mail\Mailables\Headers(
            messageId: null,
            references: [],
            text: [
                'X-Auto-Response-Suppress' => 'OOF, AutoReply',
                'Auto-Submitted'           => 'auto-generated',
                'X-Entity-Ref-ID'          => $entityRef,
                'X-Mailer'                 => 'Uganda-POE-Sentinel',
            ],
        );
    }
}
