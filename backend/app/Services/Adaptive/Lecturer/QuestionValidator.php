<?php

namespace App\Services\Adaptive\Lecturer;

/**
 * Validates and normalises question data for the bank and for Klenam imports (spec §3.2, §4.1).
 * v1 practice quizzes are objective only (v0.8 D16), so open-ended questions are accepted in the
 * bank but cannot be used in practice or revision quizzes.
 */
final class QuestionValidator
{
    private const TYPES = ['mcq', 'true_false', 'open_ended'];
    private const DIFFICULTIES = ['easy', 'medium', 'hard'];

    /**
     * @param  array<string, mixed>  $data  question_type, question_text, difficulty?, max_marks, answer_data?, explanation?
     * @return array{0: array<string, mixed>, 1: list<string>}  [normalised data, errors]
     */
    public static function validate(array $data): array
    {
        $errors = [];
        $type = $data['question_type'] ?? null;
        $text = trim((string) ($data['question_text'] ?? ''));
        $difficulty = ($data['difficulty'] ?? null) ?: 'medium'; // D10 default
        $marks = $data['max_marks'] ?? null;

        if (! in_array($type, self::TYPES, true)) {
            $errors[] = 'question_type must be mcq, true_false or open_ended.';
        }
        if ($text === '') {
            $errors[] = 'question_text is required.';
        }
        if (! in_array($difficulty, self::DIFFICULTIES, true)) {
            $errors[] = 'difficulty must be easy, medium or hard.';
        }
        if (! is_numeric($marks) || (float) $marks <= 0 || (float) $marks > 9999.99
            || round((float) $marks, 2) != (float) $marks) {
            $errors[] = 'max_marks must be a number above 0 with at most two decimal places.';
        }

        $answerData = null;
        if (in_array($type, ['mcq', 'true_false'], true)) {
            [$answerData, $answerErrors] = self::validateOptions($type, $data['answer_data'] ?? null);
            $errors = array_merge($errors, $answerErrors);
        }

        return [[
            'question_type' => $type,
            'question_text' => $text,
            'difficulty' => $difficulty,
            'max_marks' => is_numeric($marks) ? number_format((float) $marks, 2, '.', '') : $marks,
            'answer_data' => $answerData,
            'explanation' => isset($data['explanation']) && trim((string) $data['explanation']) !== ''
                ? trim((string) $data['explanation']) : null,
        ], $errors];
    }

    /** @return array{0: ?array, 1: list<string>} */
    public static function validateOptions(string $type, mixed $answerData): array
    {
        $errors = [];
        $options = is_array($answerData) ? ($answerData['options'] ?? null) : null;
        $correct = is_array($answerData) ? ($answerData['correct_option_ids'] ?? null) : null;

        if (! is_array($options) || count($options) < 2) {
            return [null, ['answer_data.options needs at least two options.']];
        }

        $ids = [];
        $clean = [];
        foreach ($options as $i => $option) {
            $id = trim((string) ($option['id'] ?? ''));
            $text = trim((string) ($option['text'] ?? ''));
            if ($id === '' || $text === '') {
                $errors[] = "Option ".($i + 1)." needs an id and text.";
                continue;
            }
            if (in_array($id, $ids, true)) {
                $errors[] = "Option id '{$id}' is used twice.";
            }
            $ids[] = $id;
            $clean[] = ['id' => $id, 'text' => $text];
        }

        $correct = is_array($correct) ? array_values(array_unique(array_map('strval', $correct))) : [];
        if ($correct === []) {
            $errors[] = 'answer_data.correct_option_ids needs at least one correct option.';
        }
        foreach ($correct as $id) {
            if (! in_array($id, $ids, true)) {
                $errors[] = "Correct option '{$id}' is not one of the options.";
            }
        }
        if ($type === 'true_false' && (count($clean) !== 2 || count($correct) !== 1)) {
            $errors[] = 'A true/false question needs exactly two options and one correct answer.';
        }

        return [['options' => $clean, 'correct_option_ids' => $correct], $errors];
    }
}
