<?php

namespace App\Services\Adaptive\Calculation;

use App\Enums\Adaptive\CoverageBand;
use App\Enums\Adaptive\PerformanceStatus;

/**
 * Mark-weighted performance, status, coverage and display percentage (spec §5, §7.1).
 */
final class PerformanceCalculator
{
    public function __construct(private readonly Thresholds $thresholds = new Thresholds())
    {
    }

    /**
     * @param  list<EvidenceItem>  $items  current evidence set for one topic
     */
    public function calculate(array $items): PerformanceResult
    {
        if ($items === []) {
            return new PerformanceResult(PerformanceStatus::NotEnoughEvidence, 0, 0, null, null);
        }

        $earned = 0;
        $max = 0;
        foreach ($items as $item) {
            $earned += $item->earned;
            $max += $item->max;
        }

        return new PerformanceResult(
            $this->status($earned, $max),
            $earned,
            $max,
            self::displayPercent($earned, $max),
            $this->coverageBand($max),
        );
    }

    /** Exact integer comparisons; rounding never affects status (C13, spec §5.5). */
    public function status(int $earned, int $max): PerformanceStatus
    {
        if ($max <= 0) {
            return PerformanceStatus::NotEnoughEvidence;
        }

        if ($earned * 100 < $this->thresholds->needsHelpBelowPercent * $max) {
            return PerformanceStatus::NeedsHelp;
        }

        if ($earned * 100 < $this->thresholds->strongFromPercent * $max) {
            return PerformanceStatus::GettingThere;
        }

        // T4: strong performance needs at least 10 marks of current evidence.
        return $max >= $this->thresholds->strongMinimumCoverage
            ? PerformanceStatus::Strong
            : PerformanceStatus::StrongUnconfirmed;
    }

    public function coverageBand(int $max): CoverageBand
    {
        return match (true) {
            $max >= $this->thresholds->broadCoverageFrom => CoverageBand::Broad,
            $max >= $this->thresholds->mediumCoverageFrom => CoverageBand::Medium,
            default => CoverageBand::Low,
        };
    }

    /**
     * C13: always round DOWN to one decimal place.
     * display = floor(earned × 1000 / max) / 10, using integer division (both values are non-negative).
     */
    public static function displayPercent(int $earned, int $max): float
    {
        return intdiv($earned * 1000, $max) / 10;
    }
}
