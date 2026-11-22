<?php

namespace App\Services\Adaptive\Quiz;

use App\Enums\Adaptive\QuestionType;

/**
 * Marks objective answers by comparing option IDs, never display positions (spec §4.1).
 * All-or-nothing: full marks only when the selected set equals the correct set.
 */
final class AutoMarker
{
    /**
     * @param  list<string>  $selectedOptionIds
     * @param  list<string>  $correctOptionIds
     */
    public static function isCorrect(array $selectedOptionIds, array $correctOptionIds): bool
    {
        if ($selectedOptionIds === [] || $correctOptionIds === []) {
            return false;
        }

        $selected = array_values(array_unique(array_map('strval', $selectedOptionIds)));
        $correct = array_values(array_unique(array_map('strval', $correctOptionIds)));
        sort($selected);
        sort($correct);

        return $selected === $correct;
    }

    /**
     * Blank means no option selected (objective) or empty / whitespace-only text (spec §4.2).
     *
     * @param  array<string, mixed>|null  $response
     */
    public static function isBlank(QuestionType $type, ?array $response): bool
    {
        if ($response === null) {
            return true;
        }

        if ($type->isObjective()) {
            return empty($response['option_ids']);
        }

        return trim((string) ($response['text'] ?? '')) === '';
    }
}
