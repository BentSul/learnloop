<?php

namespace App\Services\Adaptive\Lecturer;

use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\CourseAccess;

/**
 * Imports Klenam AI-generated questions into a topic's question bank (v0.8 §4.1).
 *
 * The real Klenam export format is not known yet (D14), so two simple formats are accepted;
 * adjust parseRows() once a sample export is available.
 *
 * JSON: a list of objects
 *   {"question": "...", "type": "mcq|true_false", "options": ["A text", "B text", ...],
 *    "answer": "B" | 2 | "True", "difficulty": "easy|medium|hard", "marks": 2, "explanation": "..."}
 *
 * CSV with a header row:
 *   question,option_a,option_b,option_c,option_d,answer,difficulty,marks,explanation
 *   (true/false rows: leave options empty and put True or False in answer)
 *
 * Valid rows are imported; invalid rows are reported with a reason. Missing difficulty → medium
 * (D10); missing marks → 1. A question with the same text already in the topic is rejected as a
 * duplicate. Only objective questions are imported (v0.8 D16).
 */
final class KlenamImportService
{
    public function __construct(
        private readonly CourseAccess $access,
        private readonly QuestionBankService $bank,
    ) {
    }

    /**
     * @return array{imported: list<int>, rejected: list<array{row: int, reason: string}>}
     */
    public function import(User $lecturer, Topic $topic, string $contents, string $format): array
    {
        if (! $this->access->isLecturer($lecturer, $topic->course)) {
            throw LecturerException::notFound();
        }

        $rows = $this->parseRows($contents, $format);
        $existing = Question::where('topic_id', $topic->id)->pluck('question_text')
            ->map(fn ($t) => mb_strtolower(trim($t)))->all();

        $imported = [];
        $rejected = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 1;
            [$data, $error] = $this->toQuestionData($row);
            if ($error !== null) {
                $rejected[] = ['row' => $rowNumber, 'reason' => $error];
                continue;
            }

            $key = mb_strtolower(trim($data['question_text']));
            if (in_array($key, $existing, true)) {
                $rejected[] = ['row' => $rowNumber, 'reason' => 'A question with the same text already exists in this topic.'];
                continue;
            }

            try {
                $question = $this->bank->create($lecturer, $topic, $data + ['source' => 'klenam']);
            } catch (LecturerException $e) {
                $rejected[] = ['row' => $rowNumber, 'reason' => implode(' ', $e->errors) ?: $e->getMessage()];
                continue;
            }

            $existing[] = $key;
            $imported[] = $question->id;
        }

        return ['imported' => $imported, 'rejected' => $rejected];
    }

    /** @return list<array<string, mixed>> */
    private function parseRows(string $contents, string $format): array
    {
        if ($format === 'json') {
            $data = json_decode($contents, true);
            if (! is_array($data) || ! array_is_list($data)) {
                throw LecturerException::invalid('The file is not a JSON list of questions.');
            }

            return array_map(fn ($r) => is_array($r) ? $r : [], $data);
        }

        if ($format === 'csv') {
            $lines = preg_split('/\r\n|\n|\r/', trim($contents));
            $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv((string) array_shift($lines)));
            if (! in_array('question', $header, true) || ! in_array('answer', $header, true)) {
                throw LecturerException::invalid('The CSV needs a header row with at least "question" and "answer".');
            }

            $rows = [];
            foreach ($lines as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $values = str_getcsv($line);
                $assoc = array_combine($header, array_pad(array_slice($values, 0, count($header)), count($header), ''));
                $options = array_values(array_filter(
                    [$assoc['option_a'] ?? '', $assoc['option_b'] ?? '', $assoc['option_c'] ?? '', $assoc['option_d'] ?? ''],
                    fn ($o) => trim((string) $o) !== '',
                ));
                $rows[] = [
                    'question' => $assoc['question'] ?? '',
                    'type' => $options === [] ? 'true_false' : 'mcq',
                    'options' => $options,
                    'answer' => $assoc['answer'] ?? '',
                    'difficulty' => $assoc['difficulty'] ?? '',
                    'marks' => $assoc['marks'] ?? '',
                    'explanation' => $assoc['explanation'] ?? '',
                ];
            }

            return $rows;
        }

        throw LecturerException::invalid('Format must be json or csv.');
    }

    /** @return array{0: array<string, mixed>, 1: ?string} */
    private function toQuestionData(array $row): array
    {
        $text = trim((string) ($row['question'] ?? ''));
        if ($text === '') {
            return [[], 'The question text is missing.'];
        }

        $type = strtolower(trim((string) ($row['type'] ?? 'mcq'))) ?: 'mcq';
        $type = in_array($type, ['tf', 'true_false', 'truefalse', 'true/false'], true) ? 'true_false' : $type;
        if (! in_array($type, ['mcq', 'true_false'], true)) {
            return [[], 'Only multiple-choice and true/false questions can be imported.'];
        }

        $answer = trim((string) ($row['answer'] ?? ''));

        if ($type === 'true_false') {
            $value = strtolower($answer);
            if (! in_array($value, ['true', 'false', 't', 'f'], true)) {
                return [[], 'A true/false answer must be True or False.'];
            }
            $answerData = [
                'options' => [['id' => 't', 'text' => 'True'], ['id' => 'f', 'text' => 'False']],
                'correct_option_ids' => [str_starts_with($value, 't') ? 't' : 'f'],
            ];
        } else {
            $options = array_values(array_filter(array_map(fn ($o) => trim((string) $o), (array) ($row['options'] ?? [])), fn ($o) => $o !== ''));
            if (count($options) < 2 || count($options) > 6) {
                return [[], 'A multiple-choice question needs between 2 and 6 options.'];
            }
            $ids = array_slice(['a', 'b', 'c', 'd', 'e', 'f'], 0, count($options));

            $correctIndex = match (true) {
                is_numeric($answer) => (int) $answer - 1,                                   // 1-based position
                strlen($answer) === 1 && ctype_alpha($answer) => ord(strtolower($answer)) - ord('a'), // letter
                default => array_search(mb_strtolower($answer), array_map('mb_strtolower', $options), true), // option text
            };
            if ($correctIndex === false || $correctIndex < 0 || $correctIndex >= count($options)) {
                return [[], "The answer '{$answer}' does not match any option."];
            }

            $answerData = [
                'options' => array_map(fn ($id, $t) => ['id' => $id, 'text' => $t], $ids, $options),
                'correct_option_ids' => [$ids[$correctIndex]],
            ];
        }

        $marks = trim((string) ($row['marks'] ?? ''));

        return [[
            'question_type' => $type,
            'question_text' => $text,
            'difficulty' => strtolower(trim((string) ($row['difficulty'] ?? ''))) ?: 'medium',
            'max_marks' => $marks === '' ? '1' : $marks,
            'answer_data' => $answerData,
            'explanation' => $row['explanation'] ?? null,
        ], null];
    }
}
