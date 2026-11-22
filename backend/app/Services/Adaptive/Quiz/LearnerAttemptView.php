<?php

namespace App\Services\Adaptive\Quiz;

use App\Models\Answer;
use App\Models\Attempt;
use App\Models\AttemptItem;

/**
 * The learner-safe representation of an attempt for the quiz player (spec §13.1, §15.1).
 * Built from frozen snapshots in stored order. Contains no answer key or marking data.
 */
final class LearnerAttemptView
{
    /** @return array<string, mixed> */
    public static function make(Attempt $attempt): array
    {
        $items = AttemptItem::where('attempt_id', $attempt->id)->orderBy('position')->get();
        $answers = Answer::where('attempt_id', $attempt->id)->get()->keyBy('question_id');

        return [
            'attempt_id' => $attempt->id,
            'assessment_id' => $attempt->assessment_id,
            'status' => $attempt->status->value,
            'started_at' => $attempt->started_at?->toIso8601String(),
            'questions' => $items->map(function (AttemptItem $item) use ($answers) {
                $optionsById = collect($item->options_snapshot ?? [])->keyBy('id');

                return [
                    'question_id' => $item->question_id,
                    'position' => $item->position,
                    'type' => $item->question_type_snapshot,
                    'text' => $item->question_text_snapshot,
                    'max_marks' => (float) $item->max_marks_snapshot,
                    // Options in the frozen display order, ids + text only.
                    'options' => array_values(array_map(
                        fn (string $id) => ['id' => $id, 'text' => $optionsById[$id]['text'] ?? ''],
                        $item->option_order ?? [],
                    )),
                    'response' => $answers->get($item->question_id)?->response,
                ];
            })->values()->all(),
        ];
    }
}
