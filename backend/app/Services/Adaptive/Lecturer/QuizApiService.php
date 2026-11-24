<?php

namespace App\Services\Adaptive\Lecturer;

use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\CourseAccess;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Finds ready-made technical questions on QuizAPI.io for lecturers to preview and import.
 * QuizAPI is free for development and non-commercial use with an API key. Imported questions
 * are labelled source "quizapi" and go through the normal question checks.
 *
 * Both QuizAPI response formats are accepted: the current one ({success, data: [{text,
 * answers: [{text, isCorrect}]}]}) and the older one ([{question, answers: {answer_a..},
 * correct_answers: {answer_a_correct: "true"}}]).
 */
class QuizApiService
{
    private const MAX_RESULTS = 20;

    private const LETTERS = ['a', 'b', 'c', 'd', 'e', 'f'];

    public function __construct(
        private readonly CourseAccess $access,
        private readonly QuestionBankService $bank,
    ) {
    }

    public function enabled(): bool
    {
        return filled(config('adaptive.learning_sources.quizapi_key'));
    }

    /**
     * @param  array{tags?: string|null, category?: string|null, difficulty?: string|null, limit?: int|null}  $filters
     * @return list<array<string, mixed>> previews: {external_id, question, type, options, correct, difficulty, explanation, tags, already_in_bank}
     */
    public function search(User $lecturer, Topic $topic, array $filters): array
    {
        $this->assertLecturer($lecturer, $topic);
        if (! $this->enabled()) {
            throw LecturerException::conflict('Online question search is not set up yet: add a QuizAPI key (QUIZAPI_KEY).');
        }

        $difficulty = strtolower((string) ($filters['difficulty'] ?? ''));
        $query = array_filter([
            'tags' => self::cleanList($filters['tags'] ?? null),
            'category' => self::cleanList($filters['category'] ?? null),
            'difficulty' => in_array($difficulty, ['easy', 'medium', 'hard'], true) ? strtoupper($difficulty) : null,
            'type' => 'MULTIPLE_CHOICE,TRUE_FALSE',
            'limit' => min(self::MAX_RESULTS, max(1, (int) ($filters['limit'] ?? 10))),
            'random' => 'true',
        ], fn ($v) => $v !== null && $v !== '');

        $key = (string) config('adaptive.learning_sources.quizapi_key');
        try {
            $response = Http::acceptJson()->withToken($key)
                ->timeout((int) config('adaptive.learning_sources.timeout_seconds', 15))
                ->get((string) config('adaptive.learning_sources.quizapi_url'), $query + ['apiKey' => $key]);
        } catch (ConnectionException) {
            throw LecturerException::unavailable('QuizAPI could not be reached. Try again later.');
        }

        if ($response->status() === 404) {
            return []; // the older API answers 404 when nothing matches
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw LecturerException::unavailable('QuizAPI refused the request: check the QuizAPI key.');
        }
        if (! $response->successful()) {
            throw LecturerException::unavailable("QuizAPI returned an error ({$response->status()}). Try again later.");
        }

        $body = (array) $response->json();
        $items = array_is_list($body) ? $body : (array) ($body['data'] ?? []);
        $existing = Question::where('topic_id', $topic->id)->pluck('question_text')
            ->map(fn ($t) => mb_strtolower(trim($t)))->flip();

        $previews = [];
        foreach ($items as $item) {
            $preview = is_array($item) ? self::normalise($item) : null;
            if ($preview !== null) {
                $preview['already_in_bank'] = $existing->has(mb_strtolower($preview['question']));
                $previews[] = $preview;
            }
        }

        return $previews;
    }

    /**
     * Imports the previews the lecturer selected.
     *
     * @param  list<array<string, mixed>>  $items  previews as returned by search()
     * @return array{imported: list<int>, rejected: list<array{row: int, reason: string}>}
     */
    public function import(User $lecturer, Topic $topic, array $items): array
    {
        $this->assertLecturer($lecturer, $topic);
        $existing = Question::where('topic_id', $topic->id)->pluck('question_text')
            ->map(fn ($t) => mb_strtolower(trim($t)))->flip()->all();

        $imported = [];
        $rejected = [];
        DB::transaction(function () use ($lecturer, $topic, $items, &$existing, &$imported, &$rejected) {
            foreach (array_values($items) as $i => $item) {
                $row = $i + 1;
                $text = trim((string) ($item['question'] ?? ''));
                $options = array_values(array_filter(array_map(fn ($o) => trim((string) $o), (array) ($item['options'] ?? [])), fn ($o) => $o !== ''));
                $correct = array_values(array_unique(array_map('intval', (array) ($item['correct'] ?? []))));

                if (isset($existing[mb_strtolower($text)])) {
                    $rejected[] = ['row' => $row, 'reason' => 'Already in this topic.'];
                    continue;
                }
                if (count($options) < 2 || count($options) > count(self::LETTERS) || $correct === []
                    || array_filter($correct, fn ($c) => $c < 0 || $c >= count($options)) !== []) {
                    $rejected[] = ['row' => $row, 'reason' => 'Needs 2–6 options and at least one correct answer.'];
                    continue;
                }

                $type = ($item['type'] ?? 'mcq') === 'true_false' && count($options) === 2 && count($correct) === 1 ? 'true_false' : 'mcq';
                try {
                    $question = $this->bank->create($lecturer, $topic, [
                        'question_type' => $type,
                        'question_text' => $text,
                        'difficulty' => in_array($item['difficulty'] ?? null, ['easy', 'medium', 'hard'], true) ? $item['difficulty'] : 'medium',
                        'max_marks' => 1,
                        'answer_data' => [
                            'options' => array_map(fn ($t, $k) => ['id' => self::LETTERS[$k], 'text' => $t], $options, array_keys($options)),
                            'correct_option_ids' => array_map(fn ($c) => self::LETTERS[$c], $correct),
                        ],
                        'explanation' => $item['explanation'] ?? null,
                        'source' => 'quizapi',
                    ]);
                } catch (LecturerException $e) {
                    $rejected[] = ['row' => $row, 'reason' => implode(' ', $e->errors) ?: $e->getMessage()];
                    continue;
                }

                $existing[mb_strtolower($text)] = true;
                $imported[] = $question->id;
            }
        });

        return ['imported' => $imported, 'rejected' => $rejected];
    }

    /** @return array<string, mixed>|null one QuizAPI question in LearnLoop's preview shape */
    private static function normalise(array $q): ?array
    {
        $options = [];
        $correct = [];

        if (isset($q['answers']) && is_array($q['answers']) && array_is_list($q['answers'])) {
            // Current format: answers: [{id, text, isCorrect}]
            foreach ($q['answers'] as $answer) {
                $text = trim((string) ($answer['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                if (! empty($answer['isCorrect'])) {
                    $correct[] = count($options);
                }
                $options[] = $text;
            }
            $text = (string) ($q['text'] ?? $q['question'] ?? '');
        } else {
            // Older format: answers: {answer_a: "..."}, correct_answers: {answer_a_correct: "true"}
            foreach ((array) ($q['answers'] ?? []) as $key => $answerText) {
                $answerText = trim((string) $answerText);
                if ($answerText === '') {
                    continue;
                }
                if (($q['correct_answers'][$key.'_correct'] ?? 'false') === 'true') {
                    $correct[] = count($options);
                }
                $options[] = $answerText;
            }
            $text = (string) ($q['question'] ?? '');
        }

        $text = trim($text);
        if ($text === '' || count($options) < 2 || count($options) > count(self::LETTERS) || $correct === []) {
            return null;
        }

        $difficulty = strtolower((string) ($q['difficulty'] ?? 'medium'));
        $tags = array_values(array_filter(array_map(
            fn ($t) => is_array($t) ? (string) ($t['name'] ?? '') : (string) $t,
            (array) ($q['tags'] ?? []),
        )));

        return [
            'external_id' => (string) ($q['id'] ?? ''),
            'question' => $text,
            'type' => strtoupper((string) ($q['type'] ?? '')) === 'TRUE_FALSE' ? 'true_false' : 'mcq',
            'options' => $options,
            'correct' => $correct,
            'difficulty' => match ($difficulty) {
                'easy' => 'easy', 'hard', 'expert' => 'hard', default => 'medium',
            },
            'explanation' => filled($q['explanation'] ?? null) ? trim((string) $q['explanation']) : null,
            'tags' => $tags,
            'category' => $q['category'] ?? null,
        ];
    }

    private static function cleanList(mixed $value): ?string
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $value)), fn ($p) => $p !== '' && preg_match('/^[A-Za-z0-9 .+#_-]{1,40}$/', $p));

        return $parts === [] ? null : implode(',', array_slice($parts, 0, 5));
    }

    private function assertLecturer(User $lecturer, Topic $topic): void
    {
        if (! $this->access->isLecturer($lecturer, $topic->course)) {
            throw LecturerException::notFound();
        }
    }
}
