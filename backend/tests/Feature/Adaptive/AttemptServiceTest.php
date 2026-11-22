<?php

namespace Tests\Feature\Adaptive;

use App\Models\Answer;
use App\Models\Assessment;
use App\Models\AssessmentItem;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\MarkVersion;
use App\Models\Question;
use App\Models\Topic;
use App\Services\Adaptive\Evidence\EvidenceAssembler;
use App\Services\Adaptive\Quiz\AttemptService;
use App\Services\Adaptive\Quiz\Exceptions\AssessmentUnavailable;
use App\Services\Adaptive\Quiz\Exceptions\AttemptAlreadyInProgress;
use App\Services\Adaptive\Quiz\Exceptions\AttemptNotFound;
use App\Services\Adaptive\Quiz\Exceptions\AttemptNotInProgress;
use App\Services\Adaptive\Quiz\Exceptions\InvalidAnswer;
use App\Services\Adaptive\Quiz\Exceptions\InvalidAssessment;
use App\Services\Adaptive\Quiz\Exceptions\UnansweredQuestionsNeedConfirmation;
use App\Services\Adaptive\Quiz\LearnerAttemptView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

/** Spec §6.1–§6.5, §16.2 and v0.8 §5: practice quiz lifecycle. */
class AttemptServiceTest extends TestCase
{
    use BuildsAdaptiveData;
    use RefreshDatabase;

    private AttemptService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AttemptService(new Randomizer(new Mt19937(42)));
    }

    private function mcq(Topic $topic, string $correct = 'b', string $max = '2.00', string $difficulty = 'medium'): Question
    {
        return Question::create([
            'topic_id' => $topic->id,
            'question_type' => 'mcq',
            'question_text' => 'Which one?',
            'difficulty' => $difficulty,
            'max_marks' => $max,
            'answer_data' => [
                'options' => [['id' => 'a', 'text' => 'Alpha'], ['id' => 'b', 'text' => 'Beta'], ['id' => 'c', 'text' => 'Gamma']],
                'correct_option_ids' => [$correct],
            ],
            'explanation' => 'Because.',
        ]);
    }

    /** @param list<Question> $questions */
    private function practiceSet(Topic $topic, array $questions, string $type = 'practice', ?int $ownerId = null): Assessment
    {
        $assessment = Assessment::create([
            'course_id' => $topic->course_id,
            'assessment_type' => $type,
            'owner_learner_id' => $ownerId,
            'topic_id' => $type === 'revision' ? $topic->id : null,
            'title' => 'Set',
        ]);
        foreach ($questions as $i => $q) {
            AssessmentItem::create(['assessment_id' => $assessment->id, 'question_id' => $q->id, 'position' => $i + 1]);
        }

        return $assessment->fresh();
    }

    // ---------------------------------------------------------------- start

    public function test_start_freezes_every_question_with_snapshots(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $q1 = $this->mcq($topic, 'a', '2.00', 'easy');
        $q2 = $this->mcq($topic, 'c', '3.00', 'hard');

        $attempt = $this->service->start($learner, $this->practiceSet($topic, [$q1, $q2]));

        $items = AttemptItem::where('attempt_id', $attempt->id)->orderBy('position')->get();
        $this->assertSame('in_progress', $attempt->status->value);
        $this->assertSame(1, $attempt->attempt_number);
        $this->assertSame([$q1->id, $q2->id], $items->pluck('question_id')->all()); // practice keeps order
        $this->assertSame(['easy', 'hard'], $items->pluck('difficulty_snapshot')->all());
        $this->assertSame(['2.00', '3.00'], $items->pluck('max_marks_snapshot')->all());
        $this->assertSame(['c'], $items[1]->marking_snapshot['correct_option_ids']);
        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], $items[0]->option_order);
    }

    public function test_second_in_progress_attempt_is_refused(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $assessment = $this->practiceSet($topic, [$this->mcq($topic)]);
        $first = $this->service->start($learner, $assessment);

        try {
            $this->service->start($learner, $assessment);
            $this->fail('Expected AttemptAlreadyInProgress');
        } catch (AttemptAlreadyInProgress $e) {
            $this->assertSame($first->id, $e->attemptId);
            $this->assertSame(409, $e->status());
        }
    }

    public function test_attempt_number_increases_after_submission(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $q = $this->mcq($topic);
        $assessment = $this->practiceSet($topic, [$q]);

        $first = $this->service->start($learner, $assessment);
        $this->service->saveAnswer($learner, $first, $q->id, ['option_ids' => ['b']]);
        $this->service->submit($learner, $first);

        $this->assertSame(2, $this->service->start($learner, $assessment)->attempt_number);
    }

    public function test_closed_quiz_cannot_be_started(): void
    {
        $topic = $this->topic($this->course());
        $assessment = $this->practiceSet($topic, [$this->mcq($topic)]);
        $assessment->update(['status' => 'closed']);

        $this->expectException(AssessmentUnavailable::class);
        $this->service->start($this->learner(), $assessment);
    }

    public function test_another_learners_revision_quiz_is_hidden(): void
    {
        $owner = $this->learner();
        $topic = $this->topic($this->course());
        $revision = $this->practiceSet($topic, [$this->mcq($topic)], 'revision', $owner->id);

        try {
            $this->service->start($this->learner(), $revision);
            $this->fail('Expected AssessmentUnavailable');
        } catch (AssessmentUnavailable $e) {
            $this->assertSame(404, $e->status());
        }

        $this->assertNotNull($this->service->start($owner, $revision));
    }

    public function test_empty_quiz_and_open_ended_questions_are_rejected(): void
    {
        $topic = $this->topic($this->course());

        $this->expectException(InvalidAssessment::class);
        $this->service->start($this->learner(), $this->practiceSet($topic, []));
    }

    public function test_open_ended_question_cannot_be_in_a_practice_quiz(): void
    {
        $topic = $this->topic($this->course());
        $openEnded = $this->question($topic, '5.00', 'medium', 'open_ended');

        $this->expectException(InvalidAssessment::class);
        $this->service->start($this->learner(), $this->practiceSet($topic, [$openEnded]));
    }

    public function test_later_question_edits_do_not_change_the_frozen_attempt(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);
        $other = $this->topic($course, 'Other');
        $q = $this->mcq($topic, 'b', '2.00', 'easy');
        $attempt = $this->service->start($learner, $this->practiceSet($topic, [$q]));

        $q->update(['question_text' => 'Changed', 'topic_id' => $other->id, 'difficulty' => 'hard']);
        $this->service->saveAnswer($learner, $attempt, $q->id, ['option_ids' => ['b']]);
        $this->service->submit($learner, $attempt);

        $view = LearnerAttemptView::make($attempt->fresh());
        $version = MarkVersion::first();
        $this->assertSame('Which one?', $view['questions'][0]['text']);
        $this->assertSame($topic->id, $version->topic_id);
        $this->assertSame('easy', $version->difficulty);
    }

    // ---------------------------------------------------------------- autosave

    public function test_autosave_upserts_one_answer_per_question(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $q = $this->mcq($topic);
        $attempt = $this->service->start($learner, $this->practiceSet($topic, [$q]));

        $this->service->saveAnswer($learner, $attempt, $q->id, ['option_ids' => ['a']]);
        $this->service->saveAnswer($learner, $attempt, $q->id, ['option_ids' => ['b']]);

        $this->assertSame(1, Answer::where('attempt_id', $attempt->id)->count());
        $this->assertSame(['option_ids' => ['b']], Answer::first()->response);
    }

    public function test_clearing_a_selection_stores_a_blank(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $q = $this->mcq($topic);
        $attempt = $this->service->start($learner, $this->practiceSet($topic, [$q]));

        $this->service->saveAnswer($learner, $attempt, $q->id, ['option_ids' => ['a']]);
        $this->service->saveAnswer($learner, $attempt, $q->id, ['option_ids' => []]);

        $this->assertNull(Answer::first()->response);
    }

    public function test_unknown_option_or_question_is_rejected(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $q = $this->mcq($topic);
        $attempt = $this->service->start($learner, $this->practiceSet($topic, [$q]));

        $this->expectException(InvalidAnswer::class);
        $this->service->saveAnswer($learner, $attempt, $q->id, ['option_ids' => ['z']]);
    }

    public function test_question_outside_the_attempt_is_rejected(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $attempt = $this->service->start($learner, $this->practiceSet($topic, [$this->mcq($topic)]));
        $outside = $this->mcq($topic);

        $this->expectException(InvalidAnswer::class);
        $this->service->saveAnswer($learner, $attempt, $outside->id, ['option_ids' => ['a']]);
    }

    public function test_another_learner_cannot_save_or_submit(): void
    {
        $topic = $this->topic($this->course());
        $q = $this->mcq($topic);
        $attempt = $this->service->start($this->learner(), $this->practiceSet($topic, [$q]));
        $intruder = $this->learner();

        try {
            $this->service->saveAnswer($intruder, $attempt, $q->id, ['option_ids' => ['a']]);
            $this->fail('Expected AttemptNotFound');
        } catch (AttemptNotFound $e) {
            $this->assertSame(404, $e->status());
        }

        $this->expectException(AttemptNotFound::class);
        $this->service->submit($intruder, $attempt, true);
    }

    public function test_find_for_learner_hides_other_learners_attempts(): void
    {
        $owner = $this->learner();
        $topic = $this->topic($this->course());
        $attempt = $this->service->start($owner, $this->practiceSet($topic, [$this->mcq($topic)]));

        $this->assertSame($attempt->id, $this->service->findForLearner($owner, $attempt->id)->id);

        $this->expectException(AttemptNotFound::class);
        $this->service->findForLearner($this->learner(), $attempt->id);
    }

    public function test_saving_after_submission_is_refused(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $q = $this->mcq($topic);
        $attempt = $this->service->start($learner, $this->practiceSet($topic, [$q]));
        $this->service->saveAnswer($learner, $attempt, $q->id, ['option_ids' => ['b']]);
        $this->service->submit($learner, $attempt);

        $this->expectException(AttemptNotInProgress::class);
        $this->service->saveAnswer($learner, $attempt->fresh(), $q->id, ['option_ids' => ['a']]);
    }

    // ---------------------------------------------------------------- submit

    public function test_submit_auto_marks_by_option_id(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $right = $this->mcq($topic, 'b', '2.00');
        $wrong = $this->mcq($topic, 'c', '3.00');
        $attempt = $this->service->start($learner, $this->practiceSet($topic, [$right, $wrong]));

        $this->service->saveAnswer($learner, $attempt, $right->id, ['option_ids' => ['b']]);
        $this->service->saveAnswer($learner, $attempt, $wrong->id, ['option_ids' => ['a']]);
        $result = $this->service->submit($learner, $attempt)->toLearnerArray();

        $this->assertSame('submitted', $result['status']);
        $this->assertSame(2.0, $result['earned_marks']);
        $this->assertSame(5.0, $result['max_marks']);
        $this->assertSame(40.0, $result['performance_display']);
        $this->assertSame([true, false], array_column($result['questions'], 'correct'));
        $this->assertSame('Because.', $result['questions'][0]['explanation']);
    }

    public function test_submit_result_never_reveals_the_answer_key(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $q = $this->mcq($topic, 'b');
        $attempt = $this->service->start($learner, $this->practiceSet($topic, [$q]));
        $this->service->saveAnswer($learner, $attempt, $q->id, ['option_ids' => ['a']]);

        $json = json_encode($this->service->submit($learner, $attempt)->toLearnerArray());

        $this->assertStringNotContainsString('correct_option_ids', $json);
        $this->assertStringNotContainsString('marking_snapshot', $json);
    }

    public function test_blank_answers_need_confirmation_then_count_as_zero(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $answered = $this->mcq($topic, 'b');
        $blank = $this->mcq($topic, 'a');
        $attempt = $this->service->start($learner, $this->practiceSet($topic, [$answered, $blank]));
        $this->service->saveAnswer($learner, $attempt, $answered->id, ['option_ids' => ['b']]);

        try {
            $this->service->submit($learner, $attempt);
            $this->fail('Expected confirmation request');
        } catch (UnansweredQuestionsNeedConfirmation $e) {
            $this->assertSame(422, $e->status());
            $this->assertSame(['unanswered' => 1, 'requires_confirmation' => true], array_intersect_key($e->payload(), ['unanswered' => 0, 'requires_confirmation' => 0]));
        }
        $this->assertSame('in_progress', $attempt->fresh()->status->value);

        $result = $this->service->submit($learner, $attempt, true)->toLearnerArray();

        $this->assertSame(2.0, $result['earned_marks']);
        $this->assertSame(2, Answer::where('attempt_id', $attempt->id)->count()); // blank answer row created
        $this->assertSame(2, MarkVersion::where('status', 'auto')->count());
    }

    public function test_double_submit_is_refused(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $q = $this->mcq($topic);
        $attempt = $this->service->start($learner, $this->practiceSet($topic, [$q]));
        $this->service->saveAnswer($learner, $attempt, $q->id, ['option_ids' => ['b']]);
        $this->service->submit($learner, $attempt);

        try {
            $this->service->submit($learner, $attempt, true);
            $this->fail('Expected AttemptNotInProgress');
        } catch (AttemptNotInProgress) {
            $this->assertSame(1, MarkVersion::count());
        }
    }

    public function test_mark_versions_carry_evidence_snapshots(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $q = $this->mcq($topic, 'b', '2.50', 'hard');
        $attempt = $this->service->start($learner, $this->practiceSet($topic, [$q]));
        $this->service->saveAnswer($learner, $attempt, $q->id, ['option_ids' => ['b']]);
        $now = Carbon::parse('2026-11-22 09:30:00');
        $this->service->submit($learner, $attempt, false, $now);

        $version = MarkVersion::first();
        $this->assertSame(1, $version->version);
        $this->assertSame('auto', $version->status->value);
        $this->assertSame("q:{$q->id}", $version->evidence_key);
        $this->assertSame('learnloop', $version->source_group);
        $this->assertSame('hard', $version->difficulty);
        $this->assertEquals('2.50', $version->earned_marks);
        $this->assertTrue($now->equalTo($version->finalised_at));
    }

    // ---------------------------------------------------------------- learner view

    public function test_learner_view_uses_frozen_order_and_hides_the_key(): void
    {
        $learner = $this->learner();
        $topic = $this->topic($this->course());
        $q = $this->mcq($topic, 'b');
        $attempt = $this->service->start($learner, $this->practiceSet($topic, [$q]));

        $first = LearnerAttemptView::make($attempt);
        $again = LearnerAttemptView::make($attempt->fresh()); // e.g. page refresh

        $order = AttemptItem::first()->option_order;
        $this->assertSame($order, array_column($first['questions'][0]['options'], 'id'));
        $this->assertSame($first, $again);
        $this->assertStringNotContainsString('correct_option_ids', json_encode($first));
    }

    // ---------------------------------------------------------------- end to end

    public function test_submitted_quiz_becomes_evidence_and_retake_replaces_it(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);
        $q = $this->mcq($topic, 'b', '2.00');
        $assessment = $this->practiceSet($topic, [$q]);

        $first = $this->service->start($learner, $assessment);
        $this->service->saveAnswer($learner, $first, $q->id, ['option_ids' => ['a']]);
        $this->service->submit($learner, $first, false, Carbon::parse('2026-11-20 10:00:00'));

        $second = $this->service->start($learner, $assessment);
        $this->service->saveAnswer($learner, $second, $q->id, ['option_ids' => ['b']]);
        $this->service->submit($learner, $second, false, Carbon::parse('2026-11-21 10:00:00'));

        $items = (new EvidenceAssembler())->forLearnerCourse($learner->id, $course->id)->forTopic($topic->id);

        $this->assertCount(1, $items);
        $this->assertSame(200, $items[0]->earned);
    }

    public function test_quiz_exceptions_render_the_documented_json(): void
    {
        $response = (new UnansweredQuestionsNeedConfirmation(3))->render();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(3, $response->getData(true)['unanswered']);
        $this->assertTrue($response->getData(true)['requires_confirmation']);
    }
}
