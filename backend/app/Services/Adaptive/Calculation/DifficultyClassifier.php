<?php

namespace App\Services\Adaptive\Calculation;

use App\Enums\Adaptive\Difficulty;
use App\Enums\Adaptive\DifficultyClass;
use App\Enums\Adaptive\PerformanceStatus;

/**
 * Difficulty class of a topic's evidence (spec §7.2–§7.4). Affects confidence only.
 *
 * Positive statuses use earned marks; Needs help uses lost marks (D2, provisional).
 * Whenever evidence exists the relevant total is above 0 (spec §7.4), so a class always exists.
 */
final class DifficultyClassifier
{
    public function __construct(private readonly Thresholds $thresholds = new Thresholds())
    {
    }

    /**
     * @param  list<EvidenceItem>  $items
     */
    public function classify(PerformanceStatus $status, array $items): ?DifficultyClass
    {
        if ($status === PerformanceStatus::NotEnoughEvidence || $items === []) {
            return null;
        }

        return $status === PerformanceStatus::NeedsHelp
            ? $this->classifyByLostMarks($items)
            : $this->classifyByEarnedMarks($items);
    }

    /** Spec §7.2: share of earned marks from Medium/Hard (and Hard alone). */
    private function classifyByEarnedMarks(array $items): DifficultyClass
    {
        $total = 0;
        $mediumOrHard = 0;
        $hard = 0;

        foreach ($items as $item) {
            $total += $item->earned;
            if ($item->difficulty !== Difficulty::Easy) {
                $mediumOrHard += $item->earned;
            }
            if ($item->difficulty === Difficulty::Hard) {
                $hard += $item->earned;
            }
        }

        $t = $this->thresholds;

        if ($mediumOrHard * 100 < $t->basicBelowPercent * $total) {
            return DifficultyClass::Basic;
        }

        if ($mediumOrHard * 100 >= $t->challengingFromPercent * $total
            && $hard * 100 >= $t->challengingHardFromPercent * $total) {
            return DifficultyClass::Challenging;
        }

        return DifficultyClass::Mixed;
    }

    /** Spec §7.3 (D2): share of lost marks on Easy/Medium questions. */
    private function classifyByLostMarks(array $items): DifficultyClass
    {
        $totalLost = 0;
        $easyOrMediumLost = 0;

        foreach ($items as $item) {
            $totalLost += $item->lost();
            if ($item->difficulty !== Difficulty::Hard) {
                $easyOrMediumLost += $item->lost();
            }
        }

        $t = $this->thresholds;

        if ($easyOrMediumLost * 100 < $t->basicBelowPercent * $totalLost) {
            return DifficultyClass::Basic;
        }

        if ($easyOrMediumLost * 100 < $t->challengingFromPercent * $totalLost) {
            return DifficultyClass::Mixed;
        }

        return DifficultyClass::Challenging;
    }
}
