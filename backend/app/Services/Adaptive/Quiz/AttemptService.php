<?php

namespace App\Services\Adaptive\Quiz;

use App\Enums\Adaptive\AssessmentType;
use App\Enums\Adaptive\AttemptStatus;
use App\Enums\Adaptive\MarkVersionStatus;
use App\Enums\Adaptive\QuestionType;
use App\Enums\Adaptive\SourceGroup;
use App\Models\Answer;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\MarkVersion;
use App\Models\User;
use App\Services\Adaptive\Quiz\Exceptions\AssessmentUnavailable;
use App\Services\Adaptive\Quiz\Exceptions\AttemptAlreadyInProgress;
use App\Services\Adaptive\Quiz\Exceptions\AttemptNotFound;
use App\Services\Adaptive\Quiz\Exceptions\AttemptNotInProgress;
use App\Services\Adaptive\Quiz\Exceptions\InvalidAnswer;
use App\Services\Adaptive\Quiz\Exceptions\InvalidAssessment;
use App\Services\Adaptive\Quiz\Exceptions\UnansweredQuestionsNeedConfirmation;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Random\Randomizer;

/**
 * Practice quiz lifecycle: start (freeze items), autosave, submit and auto-mark
 * (spec v0.7 §4.1–§4.4, §6.1, §6.4–§6.5; v0.8 §5).
 *
 * Enrolment checks come from Canvas later (v0.8 D12); for now any authenticated learner
 * may attempt an open practice set, and only the owner may attempt a revision quiz.
 */
final class AttemptService
{
    public function __construct(private readonly Randomizer $randomizer = new Randomizer())
    {
    }

    // ------------------------------------------------------------------ start

    public function start(User $learner, Assessment $assessment, ?CarbonInterface $now = null): Attempt
    {
        $now ??= now();
        $this->assertCanAttempt($learner, $assessment);

        $existing = $this->inProgressAttempt($learner, $assessment);
        if ($existing !== null) {
            throw new AttemptAlreadyInProgress($existing->id);
        }

        $items = $assessment->items()->with('question')->get();
        if ($items->isEmpty()) {
            throw new InvalidAssessment('This quiz has no questions.');
        }

        foreach ($items as $item) {
            $question = $item->question;
            if ($question->status !== 'active') {
                throw new InvalidAssessment("Question {$question->id} is not active.");
            }
            if (! $question->question_type->isObjective()) {
                // v0.8 D16: LearnLoop quizzes are objective-only in v1.
                throw new InvalidAssessment("Question {$question->id} is open-ended; practice quizzes are objective only.");
            }
            if (empty($question->answer_data['options']) || empty($question->answer_data['correct_option_ids'])) {
                throw new InvalidAssessment("Question {$question->id} has no options or no correct answer.");
            }
        }

        // Revision quizzes shuffle question order; every quiz shuffles option order (spec §5.7 safeguards).
        $ordered = $items->values()->all();
        if ($assessment->assessment_type === AssessmentType::Revision) {
            $ordered = $this->randomizer->shuffleArray($ordered);
        }

        try {
            return DB::transaction(function () use ($learner, $assessment, $ordered, $now) {
                $attempt = Attempt::create([
                    'learner_id' => $learner->id,
                    'assessment_id' => $assessment->id,
                    'attempt_number' => (int) Attempt::where('learner_id', $learner->id)
                        ->where('assessment_id', $assessment->id)->max('attempt_number') + 1,
                    'status' => AttemptStatus::InProgress,
                    'started_at' => $now,
                ]);

                foreach ($ordered as $position => $item) {
                    $question = $item->question;
                    $options = array_map(
                        fn (array $o) => ['id' => (string) $o['id'], 'text' => (string) $o['text']],
                        $question->answer_data['options'],
                    );

                    // Frozen snapshot (spec §6.5, C19): what the learner sees and what evidence uses.
                    AttemptItem::create([
                        'attempt_id' => $attempt->id,
                        'question_id' => $question->id,
                        'position' => $position + 1,
                        'question_type_snapshot' => $question->question_type->value,
                        'question_text_snapshot' => $question->question_text,
                        'topic_id_snapshot' => $question->topic_id,
                        'difficulty_snapshot' => $question->difficulty->value,
                        'max_marks_snapshot' => $question->max_marks,
                        'options_snapshot' => $options,
                        'option_order' => $this->randomizer->shuffleArray(array_column($options, 'id')),
                        'marking_snapshot' => [
                            'correct_option_ids' => array_map('strval', $question->answer_data['correct_option_ids']),
                            'explanation' => $question->explanation,
                        ],
                    ]);
                }

                return $attempt;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request started an attempt first (active_key unique index, §11.6).
            throw new AttemptAlreadyInProgress($this->inProgressAttempt($learner, $assessment)?->id);
        }
    }

    /**
     * Loads an attempt for its owner only; anyone else gets 404 (spec §14).
     * Controllers must use this instead of route-model binding on attempts.
     */
    public function findForLearner(User $learner, int $attemptId): Attempt
    {
        $attempt = Attempt::whereKey($attemptId)->where('learner_id', $learner->id)->first();
        if ($attempt === null) {
            throw new AttemptNotFound();
        }

        return $attempt;
    }

    // ------------------------------------------------------------------ autosave

    /**
     * Upserts one answer (spec §6.5). An empty selection is stored as blank (null).
     *
     * @param  array<string, mixed>  $response  {"option_ids": ["a"]}
     */
    public function saveAnswer(User $learner, Attempt $attempt, int $questionId, array $response, ?CarbonInterface $now = null): Answer
    {
        $now ??= now();
        $this->assertOwner($learner, $attempt);

        if ($attempt->status !== AttemptStatus::InProgress) {
            throw new AttemptNotInProgress();
        }
        if ($attempt->assessment->status !== 'open') {
            throw new AssessmentUnavailable('This quiz is closed.');
        }

        $item = AttemptItem::where('attempt_id', $attempt->id)->where('question_id', $questionId)->first();
        if ($item === null) {
            throw new InvalidAnswer('This question is not part of the attempt.');
        }

        $type = QuestionType::from($item->question_type_snapshot);
        $normalised = $this->normaliseResponse($type, $item, $response);
        $blank = AutoMarker::isBlank($type, $normalised);

        return Answer::updateOrCreate(
            ['attempt_id' => $attempt->id, 'question_id' => $questionId],
            ['response' => $blank ? null : $normalised, 'answered_at' => $blank ? null : $now],
        );
    }

    // ------------------------------------------------------------------ submit

    /**
     * Finalises the attempt and auto-marks every frozen item (spec §4.4, §6.4).
     * Blank items count as zero; submitting with blanks requires $confirmUnanswered.
     */
    public function submit(User $learner, Attempt $attempt, bool $confirmUnanswered = false, ?CarbonInterface $now = null): SubmitResult
    {
        $now ??= now();
        $this->assertOwner($learner, $attempt);

        return DB::transaction(function () use ($attempt, $confirmUnanswered, $now) {
            // Lock the attempt so a double click cannot finalise it twice (spec §6.4).
            $locked = Attempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== AttemptStatus::InProgress) {
                throw new AttemptNotInProgress();
            }

            $items = AttemptItem::where('attempt_id', $locked->id)->orderBy('position')->get();
            $answers = Answer::where('attempt_id', $locked->id)->get()->keyBy('question_id');

            $unanswered = $items->filter(function (AttemptItem $item) use ($answers) {
                $type = QuestionType::from($item->question_type_snapshot);

                return AutoMarker::isBlank($type, $answers->get($item->question_id)?->response);
            })->count();

            if ($unanswered > 0 && ! $confirmUnanswered) {
                throw new UnansweredQuestionsNeedConfirmation($unanswered);
            }

            $locked->update([
                'status' => AttemptStatus::Submitted,
                'submitted_at' => $now,
                'finalised_at' => $now,
            ]);

            $results = [];
            foreach ($items as $item) {
                $answer = $answers->get($item->question_id)
                    ?? Answer::create(['attempt_id' => $locked->id, 'question_id' => $item->question_id, 'response' => null]);

                $results[] = $this->markItem($item, $answer, $now);
            }

            return new SubmitResult($locked->fresh(), $results);
        });
    }

    // ------------------------------------------------------------------ helpers

    private function markItem(AttemptItem $item, Answer $answer, CarbonInterface $finalisedAt): ItemResult
    {
        $type = QuestionType::from($item->question_type_snapshot);
        $blank = AutoMarker::isBlank($type, $answer->response);
        $max = (string) $item->max_marks_snapshot;

        if ($type->isObjective()) {
            $correct = ! $blank && AutoMarker::isCorrect(
                $answer->response['option_ids'] ?? [],
                $item->marking_snapshot['correct_option_ids'] ?? [],
            );
            $earned = $correct ? $max : '0.00';
        } elseif ($blank) {
            $correct = false;
            $earned = '0.00'; // blank open-ended: zero without approval (spec §4.2)
        } else {
            // Answered open-ended: awaits lecturer marking (not used in v1, D16).
            return new ItemResult($item, false, null, $max, true);
        }

        MarkVersion::create([
            'answer_id' => $answer->id,
            'version' => 1,
            'status' => MarkVersionStatus::Auto,
            'reason' => 'initial',
            'earned_marks' => $earned,
            'max_marks' => $max,
            'topic_id' => $item->topic_id_snapshot,
            'difficulty' => $item->difficulty_snapshot,
            'source_group' => SourceGroup::LearnLoop->value,
            'evidence_key' => 'q:'.$item->question_id,
            'finalised_at' => $finalisedAt,
        ]);

        return new ItemResult($item, $correct, $earned, $max, false);
    }

    /** @return array<string, mixed>|null */
    private function normaliseResponse(QuestionType $type, AttemptItem $item, array $response): ?array
    {
        if (! $type->isObjective()) {
            return ['text' => (string) ($response['text'] ?? '')];
        }

        $selected = $response['option_ids'] ?? [];
        if (! is_array($selected)) {
            throw new InvalidAnswer('option_ids must be a list.');
        }
        $selected = array_values(array_unique(array_map('strval', $selected)));

        $valid = array_column($item->options_snapshot ?? [], 'id');
        foreach ($selected as $id) {
            if (! in_array($id, $valid, true)) {
                throw new InvalidAnswer("Option '{$id}' is not an option for this question.");
            }
        }

        if ($type === QuestionType::TrueFalse && count($selected) > 1) {
            throw new InvalidAnswer('A true/false question takes one answer.');
        }

        return ['option_ids' => $selected];
    }

    private function assertCanAttempt(User $learner, Assessment $assessment): void
    {
        if ($assessment->assessment_type === AssessmentType::Revision
            && (int) $assessment->owner_learner_id !== (int) $learner->id) {
            throw new AssessmentUnavailable('Quiz not found.', 404); // hidden for privacy (§14)
        }

        if ($assessment->status !== 'open') {
            throw new AssessmentUnavailable('This quiz is not open.');
        }
    }

    private function assertOwner(User $learner, Attempt $attempt): void
    {
        if ((int) $attempt->learner_id !== (int) $learner->id) {
            throw new AttemptNotFound();
        }
    }

    private function inProgressAttempt(User $learner, Assessment $assessment): ?Attempt
    {
        return Attempt::where('learner_id', $learner->id)
            ->where('assessment_id', $assessment->id)
            ->where('status', AttemptStatus::InProgress->value)
            ->first();
    }
}
