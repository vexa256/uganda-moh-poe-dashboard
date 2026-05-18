<?php

declare(strict_types=1);

namespace App\Services\Reports;

/**
 * Deterministic thresholds for the rule-based AI insight engines.
 * Centralised so every report cites the same numbers and the same wording.
 */
final class InsightThresholds
{
    public const MIN_DENOMINATOR = 5;            // Suppress conclusions when n<5.
    public const NOTIFIABLE_HIGH = 0.30;         // ≥30% — CRITICAL band.
    public const NOTIFIABLE_MED  = 0.15;         // ≥15% — WARNING band.
    public const GENDER_DISPARITY = 0.10;        // 10 percentage points absolute.
    public const MORTALITY_WARN   = 0.05;        // 5% mortality / referral share.
    public const RECOVERY_GOOD    = 0.70;        // ≥70% recovery rate.
    public const SPIKE_RATIO      = 1.5;         // current 7d ÷ trailing 30d baseline.
    public const COMPLETENESS_OK  = 0.90;        // ≥90% completeness.
    public const COMPLETENESS_LOW = 0.70;        // <70% triggers critical insight.
    public const HOLDING_QUEUE_SLOW_MIN = 20;    // ≥20 minutes in holding queue is "flagged".
}
