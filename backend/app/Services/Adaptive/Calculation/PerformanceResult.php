<?php

namespace App\Services\Adaptive\Calculation;

use App\Enums\Adaptive\CoverageBand;
use App\Enums\Adaptive\PerformanceStatus;

/**
 * Result of PerformanceCalculator. Marks are integer hundredths.
 * For not_enough_evidence, displayPercent and coverageBand are null (no 0 ÷ 0).
 */
final class PerformanceResult
{
    public function __construct(
        public readonly PerformanceStatus $status,
        public readonly int $earned,
        public readonly int $max,
        public readonly ?float $displayPercent,
        public readonly ?CoverageBand $coverageBand,
    ) {
    }

    public function hasEvidence(): bool
    {
        return $this->status !== PerformanceStatus::NotEnoughEvidence;
    }
}
