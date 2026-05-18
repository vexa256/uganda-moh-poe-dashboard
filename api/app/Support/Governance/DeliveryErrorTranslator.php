<?php

declare(strict_types=1);

namespace App\Support\Governance;

/**
 * DeliveryErrorTranslator
 * ---------------------------------------------------------------------------
 * Translates the raw last-error string captured on notification_log rows
 * (and on retry-queue rows in gov-reminders) into a single plain-English
 * line an operator can read without provider knowledge.
 *
 * Discipline:
 *   - Pure value object — no DB, no I/O, no state.
 *   - Deterministic. Same input → same output.
 *   - Defaults to a generic "the provider rejected this — see Technical
 *     Details" line so we never expose a raw error string to the operator
 *     surface; the raw text remains available behind a disclosure.
 *   - The map below is intentionally short — it covers the most common
 *     failure families across SMTP, SMS, and Push. Add to it conservatively;
 *     the goal is to be plain and honest, not exhaustive.
 *
 * Mobile-API impact: NONE. This class is read-only and is never called from
 * a mobile route or a mobile controller.
 */
final class DeliveryErrorTranslator
{
    /**
     * Plain-English translations for the most common error families.
     *
     * Each entry: a needle (case-insensitive substring) → a plain line
     * written for someone who knows what email is and nothing else.
     *
     * Order matters — the first match wins. Put more specific phrases
     * before more general ones.
     */
    private const MAP = [
        // SMTP / email — provider rejection
        '550 5.7.1'                 => 'The recipient mail server refused the message — usually because of a content or sender block.',
        '550 5.1.1'                 => 'The recipient address does not exist on the destination server.',
        '550'                       => 'The recipient mail server permanently refused the message.',
        '552'                       => 'The recipient mailbox refused the message because it was over its size or storage limit.',
        '554'                       => 'The recipient mail server treated the message as spam or otherwise unwanted.',
        '421'                       => 'The recipient mail server was temporarily unavailable. The system will retry.',
        'mailbox unavailable'       => 'The recipient mailbox cannot be reached.',
        'mailbox full'              => 'The recipient mailbox is full and cannot accept new mail.',
        'user unknown'              => 'The recipient address does not exist.',
        'no such user'              => 'The recipient address does not exist.',
        'address rejected'          => 'The recipient address was rejected by the destination mail server.',
        'relay denied'              => 'The destination mail server refused to accept the message from us.',
        'relay access denied'       => 'The destination mail server refused to accept the message from us.',
        'spamhaus'                  => 'The destination mail server rejected the message based on a reputation block.',
        'blocked'                   => 'The destination mail server blocked the message.',
        'blacklisted'               => 'The destination mail server treated us as untrusted at this moment.',
        'rate limit'                => 'The provider asked us to slow down. The system will retry.',
        'too many'                  => 'The provider asked us to slow down. The system will retry.',
        'connection timed out'      => 'We could not reach the provider in time. The system will retry.',
        'connection refused'        => 'The provider refused our connection. The system will retry.',
        'no route to host'          => 'We could not find a path to the provider. The system will retry.',
        'tls'                       => 'A secure-connection negotiation with the provider failed.',
        'authentication failed'     => 'The system failed to authenticate with the provider. Check the configured credentials.',

        // SMS — common
        'invalid number'            => 'The recipient phone number is not in a valid form.',
        'invalid phone'             => 'The recipient phone number is not in a valid form.',
        'unallocated number'        => 'The phone number is not in use on the recipient’s network.',
        'absent subscriber'         => 'The phone is currently switched off or out of coverage.',
        'subscriber absent'         => 'The phone is currently switched off or out of coverage.',
        'unreachable'               => 'The phone could not be reached.',
        'no sim'                    => 'The phone has no SIM or is not provisioned.',
        'sender id'                 => 'The configured sender identity was rejected by the network.',
        'sender unknown'            => 'The configured sender identity was rejected by the network.',

        // Push
        'invalid registration'      => 'The phone’s push token is no longer valid. The user has likely reinstalled the app.',
        'unregistered'              => 'The phone’s push token is no longer valid. The user has likely reinstalled the app.',
        'invalid token'             => 'The phone’s push token is no longer valid. The user has likely reinstalled the app.',
        'mismatch'                  => 'The phone’s push token does not match the configured project.',
        'apns'                      => 'The Apple push service rejected the message.',
        'fcm'                       => 'The Google push service rejected the message.',

        // Soft / network
        'temporary'                 => 'A temporary problem prevented delivery. The system will retry.',
        'try again'                 => 'A temporary problem prevented delivery. The system will retry.',
        'network'                   => 'A network problem prevented delivery. The system will retry.',
        'timeout'                   => 'The provider did not respond in time. The system will retry.',
        'cancelled'                 => 'The send was cancelled before the provider replied.',
    ];

    private const FALLBACK = 'The provider rejected this message. See Technical Details for the original text.';
    private const EMPTY_FALLBACK = 'No error was reported.';

    /**
     * Translate one raw error string into a single plain-English line.
     */
    public static function translate(?string $raw): string
    {
        $raw = trim((string) ($raw ?? ''));
        if ($raw === '') return self::EMPTY_FALLBACK;

        $hay = strtolower($raw);
        foreach (self::MAP as $needle => $human) {
            // PHP auto-coerces purely numeric string keys ('550', '421') into
            // integer keys, so the loop variable can come back as int. Cast.
            $needleStr = (string) $needle;
            if ($needleStr === '') continue;
            if (str_contains($hay, strtolower($needleStr))) {
                return $human;
            }
        }
        return self::FALLBACK;
    }

    /**
     * Convenience — translate an array of raw error strings into a uniqued
     * histogram of plain-English buckets, sorted by count desc.
     *
     * @param  iterable<int,?string> $rawList
     * @return array<int,array{label:string, count:int}>
     */
    public static function histogram(iterable $rawList): array
    {
        $buckets = [];
        foreach ($rawList as $raw) {
            $label = self::translate($raw);
            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }
        arsort($buckets);
        $out = [];
        foreach ($buckets as $label => $count) {
            $out[] = ['label' => $label, 'count' => (int) $count];
        }
        return $out;
    }
}
