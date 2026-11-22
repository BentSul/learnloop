<?php

namespace App\Services\Adaptive\Calculation;

/** One compared pair of source groups (spec §8.5). Marks are integer hundredths. */
final class ConflictPair
{
    public function __construct(
        public readonly string $groupA,
        public readonly int $earnedA,
        public readonly int $maxA,
        public readonly string $groupB,
        public readonly int $earnedB,
        public readonly int $maxB,
        public readonly bool $isConflict,
        public readonly bool $bothMeetMinimum,
        public readonly bool $reducesConfidence,
    ) {
    }

    public function displayPercentA(): float
    {
        return PerformanceCalculator::displayPercent($this->earnedA, $this->maxA);
    }

    public function displayPercentB(): float
    {
        return PerformanceCalculator::displayPercent($this->earnedB, $this->maxB);
    }

    /**
     * Difference in percentage points, rounded down to one decimal place (display only;
     * the conflict decision itself uses exact integers).
     */
    public function displayDifference(): float
    {
        $numerator = abs($this->earnedA * $this->maxB - $this->earnedB * $this->maxA) * 1000;

        return intdiv($numerator, $this->maxA * $this->maxB) / 10;
    }
}
