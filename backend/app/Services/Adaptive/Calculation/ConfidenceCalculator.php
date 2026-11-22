<?php

namespace App\Services\Adaptive\Calculation;

use App\Enums\Adaptive\Confidence;
use App\Enums\Adaptive\CoverageBand;
use App\Enums\Adaptive\DifficultyClass;
use App\Enums\Adaptive\PerformanceStatus;

/**
 * Confidence in a topic assessment (spec §7.5–§7.6, §8.4).
 * Describes how reliable the evidence is, not how well the learner did.
 */
final class ConfidenceCalculator
{
    public function calculate(
        PerformanceResult $performance,
        ?DifficultyClass $difficulty,
        ConflictResult $conflicts,
    ): ConfidenceResult {
        if (! $performance->hasEvidence() || $difficulty === null) {
            return new ConfidenceResult(null, []);
        }

        $reasons = [];

        // Firm safeguard: fewer than 10 marks of evidence → Limited (spec §7.5 step 1).
        if ($performance->coverageBand === CoverageBand::Low) {
            $confidence = Confidence::Limited;
            $reasons[] = 'low_coverage';
        } else {
            $confidence = self::lookup($performance->coverageBand, $difficulty);
        }

        if ($difficulty === DifficultyClass::Basic) {
            $reasons[] = $performance->status === PerformanceStatus::NeedsHelp
                ? 'hard_only_losses'
                : 'mostly_basic';
        }

        // T1 / spec §8.4: at most one level overall, however many pairs qualify.
        if ($conflicts->reducesConfidence()) {
            $confidence = $confidence->reducedByOne();
            $reasons[] = 'conflict_reduced';
        }

        return new ConfidenceResult($confidence, $reasons);
    }

    /** Lookup table for Medium and Broad coverage (spec §7.5). */
    public static function lookup(CoverageBand $coverage, DifficultyClass $difficulty): Confidence
    {
        return match ($coverage) {
            CoverageBand::Low => Confidence::Limited,
            CoverageBand::Medium => $difficulty === DifficultyClass::Challenging ? Confidence::High : Confidence::Moderate,
            CoverageBand::Broad => $difficulty === DifficultyClass::Basic ? Confidence::Moderate : Confidence::High,
        };
    }
}
