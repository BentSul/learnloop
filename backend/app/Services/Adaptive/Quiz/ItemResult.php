<?php

namespace App\Services\Adaptive\Quiz;

use App\Models\AttemptItem;

/** Outcome of marking one frozen item at submission. Marks are decimal strings. */
final class ItemResult
{
    public function __construct(
        public readonly AttemptItem $item,
        public readonly bool $correct,
        public readonly ?string $earnedMarks,
        public readonly string $maxMarks,
        public readonly bool $awaitingMarking,
    ) {
    }
}
