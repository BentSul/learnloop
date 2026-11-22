<?php

namespace App\Services\Adaptive\Quiz;

use App\Models\Attempt;
use App\Services\Adaptive\Calculation\Marks;
use App\Services\Adaptive\Calculation\PerformanceCalculator;

/**
 * Result of submitting an attempt. toLearnerArray() is the response contract:
 * score per question, correct/incorrect and the explanation — never the answer key
 * (v0.8 §5, D6 simplified; spec §14).
 */
final class SubmitResult
{
    /**
     * @param  list<ItemResult>  $items
     */
    public function __construct(
        public readonly Attempt $attempt,
        public readonly array $items,
    ) {
    }

    /** @return array<string, mixed> */
    public function toLearnerArray(): array
    {
        $earned = 0;
        $max = 0;
        $questions = [];

        foreach ($this->items as $result) {
            $max += Marks::toHundredths($result->maxMarks);
            if ($result->earnedMarks !== null) {
                $earned += Marks::toHundredths($result->earnedMarks);
            }

            $questions[] = [
                'question_id' => $result->item->question_id,
                'position' => $result->item->position,
                'correct' => $result->correct,
                'earned_marks' => $result->earnedMarks === null ? null : Marks::fromHundredths(Marks::toHundredths($result->earnedMarks)),
                'max_marks' => Marks::fromHundredths(Marks::toHundredths($result->maxMarks)),
                'awaiting_marking' => $result->awaitingMarking,
                'explanation' => $result->item->marking_snapshot['explanation'] ?? null,
            ];
        }

        return [
            'attempt_id' => $this->attempt->id,
            'status' => $this->attempt->status->value,
            'finalised_at' => $this->attempt->finalised_at?->toIso8601String(),
            'earned_marks' => Marks::fromHundredths($earned),
            'max_marks' => Marks::fromHundredths($max),
            'performance_display' => $max > 0 ? PerformanceCalculator::displayPercent($earned, $max) : null,
            'questions' => $questions,
        ];
    }
}
