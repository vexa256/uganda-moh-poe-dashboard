<?php

declare(strict_types=1);

namespace App\Support\System;

/**
 * MobileStatusTranslator
 * ---------------------------------------------------------------------------
 * Translates mobile sync_status enums and platform / app-version concepts
 * into operator-friendly language for the sys-mobile surface.
 *
 * The brief is explicit:
 *   · "sync queue depth" → "messages waiting to upload from operators' phones"
 *   · "queue worker" appears nowhere in the user-facing surface
 *   · "Unknown" labels are forbidden where graceful fallbacks exist
 *
 * Versioned: v1 — domain sign-off pending.
 */
final class MobileStatusTranslator
{
    public const VERSION = 'v1';

    /**
     * Plain-language label for a sync_status value.
     */
    public static function syncStatusLabel(?string $status): string
    {
        $s = strtoupper(trim((string) $status));
        return match ($s) {
            'SYNCED'   => 'Uploaded',
            'UNSYNCED' => 'Waiting to upload',
            'FAILED'   => 'Retrying after a problem',
            'PAUSED'   => 'Paused',
            'PENDING'  => 'Waiting to upload',
            ''         => 'Status not recorded',
            default    => 'Other (' . $s . ')',
        };
    }

    /**
     * Bucket a row into one of four operator-readable groups.
     */
    public static function syncBucket(?string $status): string
    {
        $s = strtoupper(trim((string) $status));
        return match ($s) {
            'SYNCED'   => 'uploaded',
            'UNSYNCED', 'PENDING' => 'waiting',
            'FAILED'   => 'retrying',
            'PAUSED'   => 'paused',
            default    => 'other',
        };
    }

    /**
     * Plain-language label for a platform value.
     */
    public static function platformLabel(?string $platform): string
    {
        $p = strtoupper(trim((string) $platform));
        return match ($p) {
            'ANDROID' => 'Android',
            'IOS'     => 'iPhone or iPad',
            'WEB'     => 'Web browser',
            ''        => 'Unknown platform',
            default   => 'Other (' . $p . ')',
        };
    }

    /**
     * Heuristic for "this version is too old to support". Without a
     * server-side minimum-version table, we simply mark anything older
     * than the highest version seen in the field as "older". The brief
     * forbids hardcoding app-version expectations, so we treat the
     * highest version observed as the de-facto current.
     */
    public static function versionStatus(string $thisVersion, string $highestSeen): string
    {
        $thisVersion = trim($thisVersion);
        $highestSeen = trim($highestSeen);
        if ($thisVersion === '' || $thisVersion === '—') {
            return 'Version not recorded.';
        }
        if ($highestSeen === '' || $highestSeen === '—') {
            return 'Currently in use.';
        }
        $cmp = version_compare($thisVersion, $highestSeen);
        if ($cmp >= 0) return 'Latest version seen in the field.';
        return 'Older than the latest version. These devices should update when convenient.';
    }

    /**
     * "Last sync" relative phrase — kept defensive against null.
     */
    public static function lastSyncPhrase(?string $iso, \DateTimeImmutable $now): string
    {
        if ($iso === null || $iso === '') return 'Has not synced yet.';
        try {
            $then = new \DateTimeImmutable($iso);
        } catch (\Throwable) {
            return 'Last sync time not available.';
        }
        $delta = $now->getTimestamp() - $then->getTimestamp();
        if ($delta < 60)        return 'Synced moments ago.';
        if ($delta < 3600)      return 'Synced within the last hour.';
        if ($delta < 86_400)    return 'Synced within the last day.';
        if ($delta < 7 * 86_400)return 'Synced within the last week.';
        $days = (int) floor($delta / 86_400);
        return "Last synced {$days} days ago.";
    }
}
