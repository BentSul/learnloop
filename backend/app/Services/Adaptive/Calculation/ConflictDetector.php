<?php

namespace App\Services\Adaptive\Calculation;

/**
 * Pairwise conflict detection between source groups (spec §8, T1, D1/D1-R).
 *
 * Group names come from the evidence items, so the same code works whichever
 * group definition the team confirms (D1-R: learnloop / canvas / klenam).
 */
final class ConflictDetector
{
    public function __construct(private readonly Thresholds $thresholds = new Thresholds())
    {
    }

    /**
     * @param  list<EvidenceItem>  $items  current evidence set for one topic
     */
    public function detect(array $items): ConflictResult
    {
        /** @var array<string, array{earned: int, max: int}> $groups */
        $groups = [];
        foreach ($items as $item) {
            $groups[$item->sourceGroup] ??= ['earned' => 0, 'max' => 0];
            $groups[$item->sourceGroup]['earned'] += $item->earned;
            $groups[$item->sourceGroup]['max'] += $item->max;
        }

        ksort($groups); // deterministic pair order
        $names = array_keys($groups);
        $pairs = [];

        for ($i = 0; $i < count($names); $i++) {
            for ($j = $i + 1; $j < count($names); $j++) {
                $pairs[] = $this->comparePair(
                    $names[$i], $groups[$names[$i]],
                    $names[$j], $groups[$names[$j]],
                );
            }
        }

        return new ConflictResult($pairs);
    }

    /**
     * @param  array{earned: int, max: int}  $a
     * @param  array{earned: int, max: int}  $b
     */
    private function comparePair(string $nameA, array $a, string $nameB, array $b): ConflictPair
    {
        $t = $this->thresholds;

        // Exact test of |pA − pB| ≥ 30 points: |eA·mB − eB·mA| × 100 ≥ 30 × mA × mB (spec §8.1).
        $crossDifference = abs($a['earned'] * $b['max'] - $b['earned'] * $a['max']);
        $isConflict = $crossDifference * 100 >= $t->conflictPoints * $a['max'] * $b['max'];

        // T1: confidence is reduced only if both groups in this pair have at least 5 maximum marks.
        $bothMeetMinimum = $a['max'] >= $t->conflictMinimumMarks && $b['max'] >= $t->conflictMinimumMarks;

        return new ConflictPair(
            groupA: $nameA,
            earnedA: $a['earned'],
            maxA: $a['max'],
            groupB: $nameB,
            earnedB: $b['earned'],
            maxB: $b['max'],
            isConflict: $isConflict,
            bothMeetMinimum: $bothMeetMinimum,
            reducesConfidence: $isConflict && $bothMeetMinimum,
        );
    }
}
