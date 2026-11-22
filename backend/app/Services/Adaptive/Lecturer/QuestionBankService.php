<?php

namespace App\Services\Adaptive\Lecturer;

use App\Enums\Adaptive\AttemptStatus;
use App\Enums\Adaptive\MarkVersionStatus;
use App\Models\Answer;
use App\Models\AttemptItem;
use App\Models\Course;
use App\Models\MarkVersion;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\CourseAccess;
use App\Services\Adaptive\Quiz\AutoMarker;
use App\Services\Adaptive\Recommendations\RecommendationEngine;
use Illuminate\Support\Facades\DB;

/**
 * Lecturer question bank (spec §3.2, §4.6).
 *
 * Edit rules once a question appears in any attempt (in progress or finalised):
 *  - text, explanation, topic and difficulty may change (only attempts started later see it)
 *  - max_marks and question_type may not change (archive and create a new question)
 *  - options and the correct answer change only through regrade()
 */
final class QuestionBankService
{
    public function __construct(
        private readonly CourseAccess $access,
        private readonly RecommendationEngine $engine = new RecommendationEngine(),
    ) {
    }

    public function create(User $lecturer, Topic $topic, array $data): Question
    {
        $this->assertLecturer($lecturer, $topic->course);
        [$clean, $errors] = QuestionValidator::validate($data);
        if ($errors !== []) {
            throw LecturerException::invalid('The question is not valid.', $errors);
        }

        return Question::create($clean + [
            'topic_id' => $topic->id,
            'source' => $data['source'] ?? 'learnloop',
            'created_by' => $lecturer->id,
        ]);
    }

    public function update(User $lecturer, Question $question, array $data): Question
    {
        $this->assertLecturer($lecturer, $question->topic->course);
        $used = $this->isUsed($question);
        $errors = [];

        if ($used && array_key_exists('max_marks', $data)
            && number_format((float) $data['max_marks'], 2, '.', '') !== (string) $question->max_marks) {
            $errors[] = 'Maximum marks cannot change once the question has been used. Archive it and create a new question.';
        }
        if ($used && array_key_exists('question_type', $data) && $data['question_type'] !== $question->question_type->value) {
            $errors[] = 'The question type cannot change once the question has been used.';
        }
        if ($used && array_key_exists('answer_data', $data)) {
            $errors[] = 'Options and the correct answer can only change through Regrade once the question has been used.';
        }
        if (isset($data['topic_id'])) {
            $newTopic = Topic::find($data['topic_id']);
            if ($newTopic === null || $newTopic->course_id !== $question->topic->course_id) {
                $errors[] = 'The topic must belong to the same course.';
            }
        }
        if ($errors !== []) {
            throw LecturerException::invalid('This change is not allowed.', $errors);
        }

        $merged = array_merge([
            'question_type' => $question->question_type->value,
            'question_text' => $question->question_text,
            'difficulty' => $question->difficulty->value,
            'max_marks' => (string) $question->max_marks,
            'answer_data' => $question->answer_data,
            'explanation' => $question->explanation,
        ], array_intersect_key($data, array_flip([
            'question_type', 'question_text', 'difficulty', 'max_marks', 'answer_data', 'explanation',
        ])));

        [$clean, $validationErrors] = QuestionValidator::validate($merged);
        if ($validationErrors !== []) {
            throw LecturerException::invalid('The question is not valid.', $validationErrors);
        }

        $question->update($clean + (isset($data['topic_id']) ? ['topic_id' => (int) $data['topic_id']] : []));

        return $question->fresh();
    }

    /** Inactive questions leave topic size and new quizzes; their past evidence still counts (§3.2). */
    public function archive(User $lecturer, Question $question): Question
    {
        $this->assertLecturer($lecturer, $question->topic->course);
        $question->update(['status' => 'inactive']);

        return $question;
    }

    /**
     * Corrects an objective question's answer key (spec §4.6).
     * For every finalised, non-voided answer whose mark changes, a new auto version with reason
     * 'regrade' is created; history is kept. In-progress attempts get the corrected key so they
     * are marked correctly on submission. Affected learners' recommendations are reconciled.
     *
     * @return array{regraded: int, unchanged: int}
     */
    public function regrade(User $lecturer, Question $question, array $answerData): array
    {
        $course = $question->topic->course;
        $this->assertLecturer($lecturer, $course);

        if (! $question->question_type->isObjective()) {
            throw LecturerException::invalid('Only multiple-choice and true/false questions can be regraded.');
        }
        [$clean, $errors] = QuestionValidator::validateOptions($question->question_type->value, $answerData);
        $existingIds = array_column($question->answer_data['options'] ?? [], 'id');
        if ($clean !== null && array_column($clean['options'], 'id') !== $existingIds) {
            $errors[] = 'Regrade may change option text and the correct answer, but not add, remove or reorder options.';
        }
        if ($errors !== []) {
            throw LecturerException::invalid('The corrected answer is not valid.', $errors);
        }

        $counts = ['regraded' => 0, 'unchanged' => 0];
        $affectedLearners = [];

        DB::transaction(function () use ($question, $clean, &$counts, &$affectedLearners) {
            $question->update(['answer_data' => $clean]);
            $correct = $clean['correct_option_ids'];

            $answers = Answer::with('attempt')->where('question_id', $question->id)->get();
            foreach ($answers as $answer) {
                $attempt = $answer->attempt;
                $item = AttemptItem::where('attempt_id', $attempt->id)->where('question_id', $question->id)->first();
                if ($item === null) {
                    continue;
                }

                if ($attempt->status === AttemptStatus::InProgress) {
                    $snapshot = $item->marking_snapshot ?? [];
                    $snapshot['correct_option_ids'] = $correct;
                    $item->update(['marking_snapshot' => $snapshot]);
                    continue;
                }
                if (! in_array($attempt->status->value, AttemptStatus::counting(), true)) {
                    continue; // voided
                }

                $current = MarkVersion::where('answer_id', $answer->id)
                    ->whereIn('status', MarkVersionStatus::eligible())->orderByDesc('version')->first();
                if ($current === null) {
                    continue;
                }

                $isCorrect = ! AutoMarker::isBlank($question->question_type, $answer->response)
                    && AutoMarker::isCorrect($answer->response['option_ids'] ?? [], $correct);
                $earned = $isCorrect ? (string) $item->max_marks_snapshot : '0.00';

                if (number_format((float) $current->earned_marks, 2, '.', '') === number_format((float) $earned, 2, '.', '')) {
                    $counts['unchanged']++;
                    continue;
                }

                MarkVersion::create([
                    'answer_id' => $answer->id,
                    'version' => (int) MarkVersion::where('answer_id', $answer->id)->max('version') + 1,
                    'status' => MarkVersionStatus::Auto,
                    'reason' => 'regrade',
                    'earned_marks' => $earned,
                    'max_marks' => $current->max_marks,
                    'topic_id' => $current->topic_id,
                    'difficulty' => $current->difficulty,
                    'source_group' => $current->source_group,
                    'evidence_key' => $current->evidence_key,
                    'finalised_at' => $current->finalised_at,
                ]);
                $counts['regraded']++;
                $affectedLearners[$attempt->learner_id] = true;
            }

            // In-progress attempts that have no answer row yet still need the corrected key.
            AttemptItem::where('question_id', $question->id)
                ->whereHas('attempt', fn ($q) => $q->where('status', AttemptStatus::InProgress->value))
                ->get()
                ->each(function (AttemptItem $item) use ($correct) {
                    $snapshot = $item->marking_snapshot ?? [];
                    $snapshot['correct_option_ids'] = $correct;
                    $item->update(['marking_snapshot' => $snapshot]);
                });
        });

        foreach (array_keys($affectedLearners) as $learnerId) {
            $this->engine->reconcile(User::find($learnerId), $course);
        }

        return $counts;
    }

    /**
     * Topic size and lecturer warnings (spec §3.5–§3.6). Lecturer-facing only.
     *
     * @return array{topic_size: float, active_questions: int, warnings: list<string>}
     */
    public function topicSummary(User $lecturer, Topic $topic): array
    {
        $this->assertLecturer($lecturer, $topic->course);
        $active = $topic->questions()->where('status', 'active')->get();
        $size = (float) $active->sum(fn ($q) => (float) $q->max_marks);
        $objective = $active->filter(fn ($q) => $q->question_type->isObjective())->count();
        $minRevision = (int) config('adaptive.revision_quiz.min_available_questions', 3);

        $warnings = [];
        if ($size < 10) {
            $warnings[] = 'This topic has fewer than 10 available question-bank marks. Canvas evidence may be needed before mastery can be confirmed.';
        }
        if (! $active->contains(fn ($q) => $q->difficulty->value === 'hard')) {
            $warnings[] = 'This topic has no Hard questions. Learners may have limited opportunities to demonstrate performance at the highest difficulty level.';
        }
        if ($objective < $minRevision) {
            $warnings[] = "This topic has fewer than {$minRevision} active objective questions. Revision quizzes cannot be generated.";
        }

        return ['topic_size' => $size, 'active_questions' => $active->count(), 'warnings' => $warnings];
    }

    private function isUsed(Question $question): bool
    {
        return AttemptItem::where('question_id', $question->id)->exists();
    }

    private function assertLecturer(User $lecturer, Course $course): void
    {
        if (! $this->access->isLecturer($lecturer, $course)) {
            throw LecturerException::notFound();
        }
    }
}
