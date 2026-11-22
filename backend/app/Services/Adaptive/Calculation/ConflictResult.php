<?php

namespace App\Services\Adaptive\Calculation;

/** All compared pairs for one topic (spec §8.5). */
final class ConflictResult
{
    /**
     * @param  list<ConflictPair>  $pairs  every compared pair, conflicting or not
     */
    public function __construct(public readonly array $pairs)
    {
    }

    public function conflictDetected(): bool
    {
        foreach ($this->pairs as $pair) {
            if ($pair->isConflict) {
                return true;
            }
        }

        return false;
    }

    /** True if at least one conflicting pair qualifies under T1. Reduction is at most one level. */
    public function reducesConfidence(): bool
    {
        foreach ($this->pairs as $pair) {
            if ($pair->reducesConfidence) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ConflictPair> */
    public function conflictingPairs(): array
    {
        return array_values(array_filter($this->pairs, fn (ConflictPair $p) => $p->isConflict));
    }
}
