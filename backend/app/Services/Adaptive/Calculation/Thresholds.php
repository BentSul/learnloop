<?php

namespace App\Services\Adaptive\Calculation;

/**
 * Numeric rules used by the calculation core.
 *
 * Confirmed values (T1, T4, spec §5.5, §7.1, §8) are fixed defaults.
 * Provisional values (D2 difficulty thresholds) can be overridden from config/adaptive.php.
 * All mark amounts are in integer hundredths.
 */
final class Thresholds
{
    public function __construct(
        // Performance status boundaries, in percent (spec §5.5).
        public readonly int $needsHelpBelowPercent = 50,
        public readonly int $strongFromPercent = 80,
        // T4: minimum evidence for confirmed Strong (10 marks).
        public readonly int $strongMinimumCoverage = 1000,
        // Coverage bands (spec §7.1): Medium from 10 marks, Broad from 20 marks.
        public readonly int $mediumCoverageFrom = 1000,
        public readonly int $broadCoverageFrom = 2000,
        // Difficulty thresholds, provisional (D2, spec §7.2–§7.3), in percent.
        public readonly int $basicBelowPercent = 25,
        public readonly int $challengingFromPercent = 50,
        public readonly int $challengingHardFromPercent = 15,
        // T1: conflict threshold in percentage points and minimum marks per group (5 marks).
        public readonly int $conflictPoints = 30,
        public readonly int $conflictMinimumMarks = 500,
        // Evidence age boundaries in whole days (spec §7.7).
        public readonly int $recentMaxDays = 30,
        public readonly int $olderMaxDays = 90,
    ) {
    }

    /**
     * Builds thresholds from config/adaptive.php values. Only provisional values are read;
     * confirmed rules keep their defaults.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $difficulty = $config['difficulty'] ?? [];

        return new self(
            basicBelowPercent: (int) ($difficulty['basic_below_percent'] ?? 25),
            challengingFromPercent: (int) ($difficulty['challenging_from_percent'] ?? 50),
            challengingHardFromPercent: (int) ($difficulty['challenging_hard_from_percent'] ?? 15),
        );
    }
}
