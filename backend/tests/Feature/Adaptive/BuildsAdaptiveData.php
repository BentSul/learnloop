<?php

namespace Tests\Feature\Adaptive;

use App\Models\Answer;
use App\Models\Assessment;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\Course;
use App\Models\ExternalResult;
use App\Models\ExternalResultItem;
use App\Models\MarkVersion;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Carbon;

/** Test helpers that write adaptive data directly, without going through services. */
trait BuildsAdaptiveData
{
    private function learner(): User
    {
        return User::factory()->create();
    }

    private function course(string $name = 'Python'): Course
    {
        return Course::create(['name' => $name]);
    }

    private function topic(Course $course, string $name = 'Lists'): Topic
    {
        return Topic::create(['course_id' => $course->id, 'name' => $name]);
    }

    private function question(Topic $topic, string $maxMarks = '2.00', string $difficulty = 'medium', string $type = 'mcq'): Question
    {
        return Question::create([
            'topic_id' => $topic->id,
            'question_type' => $type,
            'question_text' => 'Q?',
            'difficulty' => $difficulty,
            'max_marks' => $maxMarks,
            'answer_data' => ['options' => [['id' => 'a', 'text' => 'A'], ['id' => 'b', 'text' => 'B']], 'correct_option_ids' => ['a']],
        ]);
    }

    private function assessment(Course $course): Assessment
    {
        return Assessment::create(['course_id' => $course->id, 'assessment_type' => 'practice', 'title' => 'Practice']);
    }

    private function attempt(User $learner, Assessment $assessment, string $status = 'submitted', ?string $finalisedAt = '2026-11-20 10:00:00'): Attempt
    {
        $number = Attempt::where('learner_id', $learner->id)->where('assessment_id', $assessment->id)->count() + 1;

        return Attempt::create([
            'learner_id' => $learner->id,
            'assessment_id' => $assessment->id,
            'attempt_number' => $number,
            'status' => $status,
            'started_at' => Carbon::parse($finalisedAt ?? '2026-11-20 09:00:00')->subHour(),
            'submitted_at' => $status === 'submitted' ? $finalisedAt : null,
            'finalised_at' => in_array($status, ['submitted', 'auto_closed', 'voided'], true) ? $finalisedAt : null,
        ]);
    }

    /** Freezes the question into the attempt and stores an answer. */
    private function answer(Attempt $attempt, Question $question): Answer
    {
        AttemptItem::create([
            'attempt_id' => $attempt->id,
            'question_id' => $question->id,
            'position' => AttemptItem::where('attempt_id', $attempt->id)->count() + 1,
            'question_type_snapshot' => $question->question_type->value,
            'question_text_snapshot' => $question->question_text,
            'topic_id_snapshot' => $question->topic_id,
            'difficulty_snapshot' => $question->difficulty->value,
            'max_marks_snapshot' => $question->max_marks,
        ]);

        return Answer::create(['attempt_id' => $attempt->id, 'question_id' => $question->id, 'response' => ['option_ids' => ['a']]]);
    }

    private function mark(Answer $answer, string $earned, string $status = 'auto', ?string $createdAt = null): MarkVersion
    {
        $attempt = $answer->attempt;
        $item = AttemptItem::where('attempt_id', $attempt->id)->where('question_id', $answer->question_id)->firstOrFail();

        $version = new MarkVersion([
            'answer_id' => $answer->id,
            'version' => MarkVersion::where('answer_id', $answer->id)->max('version') + 1,
            'status' => $status,
            'earned_marks' => $earned,
            'max_marks' => $item->max_marks_snapshot,
            'topic_id' => $item->topic_id_snapshot,
            'difficulty' => $item->difficulty_snapshot,
            'source_group' => 'learnloop',
            'evidence_key' => 'q:'.$answer->question_id,
            'finalised_at' => $attempt->finalised_at,
        ]);
        $version->created_at = $createdAt ?? now();
        $version->save();

        return $version;
    }

    /** Quick path: one finalised attempt with one auto-marked answer. */
    private function quizResult(User $learner, Assessment $assessment, Question $question, string $earned, string $finalisedAt = '2026-11-20 10:00:00'): Attempt
    {
        $attempt = $this->attempt($learner, $assessment, 'submitted', $finalisedAt);
        $this->mark($this->answer($attempt, $question), $earned);

        return $attempt;
    }

    /**
     * @param  list<array{topic: Topic, earned: string, max: string, key?: string, difficulty?: string}>  $items
     */
    private function canvasResult(User $learner, Course $course, string $itemRef, int $version, bool $posted, array $items, bool $excused = false, string $submittedAt = '2026-11-18 10:00:00'): ExternalResult
    {
        $result = ExternalResult::create([
            'source' => 'canvas',
            'learner_id' => $learner->id,
            'course_id' => $course->id,
            'external_item_ref' => $itemRef,
            'version' => $version,
            'score_hash' => md5($itemRef.$version),
            'posted' => $posted,
            'excused' => $excused,
            'submitted_at' => $submittedAt,
            'synced_at' => now(),
        ]);

        foreach ($items as $item) {
            ExternalResultItem::create([
                'external_result_id' => $result->id,
                'evidence_key' => $item['key'] ?? "c:{$course->id}:{$itemRef}",
                'topic_id' => $item['topic']->id,
                'difficulty' => $item['difficulty'] ?? 'medium',
                'earned_marks' => $item['earned'],
                'max_marks' => $item['max'],
            ]);
        }

        return $result;
    }
}
