<?php

namespace App\Services\Adaptive\Quiz;

use App\Enums\Adaptive\AssessmentType;
use App\Enums\Adaptive\AttemptStatus;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\Attempt;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\Quiz\Exceptions\InvalidAssessment;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Random\Randomizer;

/**
 * "Revise topic" (spec §6.7, §10.13): builds a personal revision quiz and starts it.
 *
 * - Active objective questions from the topic only (v0.8 D16).
 * - At least config('adaptive.revision_quiz.min_available_questions') (3) must exist, else 422.
 * - Up to max_questions (10), preferring questions the learner has not answered in the last
 *   recently_answered_days (7); question and option order are shuffled by AttemptService.
 * - Visible only to its owner; it never closes, and an unfinished attempt never counts.
 */
final class RevisionQuizService
{
    public function __construct(
        private readonly AttemptService $attempts = new AttemptService(),
        private readonly Randomizer $randomizer = new Randomizer(),
    ) {
    }

    public function createAndStart(User $learner, Topic $topic, ?CarbonInterface $now = null): Attempt
    {
        $now ??= now();
        $config = config('adaptive.revision_quiz');
        $max = (int) ($config['max_questions'] ?? 10);
        $min = (int) ($config['min_available_questions'] ?? 3);
        $recentDays = (int) ($config['recently_answered_days'] ?? 7);

        $questions = Question::where('topic_id', $topic->id)->where('status', 'active')
            ->whereIn('question_type', ['mcq', 'true_false'])->get();

        if ($questions->count() < $min) {
            throw new InvalidAssessment("There are not enough questions on {$topic->name} for a revision quiz yet.");
        }

        $recent = DB::table('attempt_items as ai')
            ->join('attempts as at', 'at.id', '=', 'ai.attempt_id')
            ->where('at.learner_id', $learner->id)
            ->whereIn('at.status', AttemptStatus::counting())
            ->where('at.finalised_at', '>=', $now->copy()->subDays($recentDays))
            ->pluck('ai.question_id')->unique()->all();

        $fresh = $this->randomizer->shuffleArray($questions->whereNotIn('id', $recent)->values()->all());
        $seen = $this->randomizer->shuffleArray($questions->whereIn('id', $recent)->values()->all());
        $chosen = array_slice(array_merge($fresh, $seen), 0, $max);

        $assessment = DB::transaction(function () use ($learner, $topic, $chosen) {
            $assessment = Assessment::create([
                'course_id' => $topic->course_id,
                'assessment_type' => AssessmentType::Revision,
                'owner_learner_id' => $learner->id,
                'topic_id' => $topic->id,
                'title' => "{$topic->name} revision",
            ]);
            foreach ($chosen as $i => $question) {
                AssessmentItem::create(['assessment_id' => $assessment->id, 'question_id' => $question->id, 'position' => $i + 1]);
            }

            return $assessment;
        });

        return $this->attempts->start($learner, $assessment->fresh(), $now);
    }
}
