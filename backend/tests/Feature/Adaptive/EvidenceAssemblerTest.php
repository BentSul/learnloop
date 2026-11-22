<?php

namespace Tests\Feature\Adaptive;

use App\Enums\Adaptive\Confidence;
use App\Enums\Adaptive\PerformanceStatus;
use App\Models\Answer;
use App\Models\Attempt;
use App\Services\Adaptive\Calculation\Marks;
use App\Services\Adaptive\Calculation\TopicAssessor;
use App\Services\Adaptive\Evidence\EvidenceAssembler;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec v0.7 §6.3, §6.6, §16.2–16.3 and v0.8 §3.4–§3.5. */
class EvidenceAssemblerTest extends TestCase
{
    use BuildsAdaptiveData;
    use RefreshDatabase;

    private EvidenceAssembler $assembler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assembler = new EvidenceAssembler();
    }

    private function earnedFor(array $items): array
    {
        return array_map(fn ($i) => [$i->evidenceKey, $i->earned, $i->max], $items);
    }

    public function test_no_evidence_gives_empty_set(): void
    {
        $course = $this->course();
        $topic = $this->topic($course);

        $evidence = $this->assembler->forLearnerCourse($this->learner()->id, $course->id);

        $this->assertSame([], $evidence->forTopic($topic->id));
        $this->assertSame(0, $evidence->pendingForTopic($topic->id));
    }

    public function test_latest_finalised_attempt_counts_once_per_question(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);
        $q = $this->question($topic, '5.00');
        $assessment = $this->assessment($course);

        $this->quizResult($learner, $assessment, $q, '2.00', '2026-11-01 10:00:00');
        $this->quizResult($learner, $assessment, $q, '3.00', '2026-11-05 10:00:00');
        $this->quizResult($learner, $assessment, $q, '5.00', '2026-11-10 10:00:00');

        $items = $this->assembler->forLearnerCourse($learner->id, $course->id)->forTopic($topic->id);

        $this->assertSame([["q:{$q->id}", 500, 500]], $this->earnedFor($items));
    }

    public function test_same_question_in_two_assessments_shares_one_key(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);
        $q = $this->question($topic);

        $this->quizResult($learner, $this->assessment($course), $q, '0.00', '2026-11-01 10:00:00');
        $this->quizResult($learner, $this->assessment($course), $q, '2.00', '2026-11-02 10:00:00');

        $items = $this->assembler->forLearnerCourse($learner->id, $course->id)->forTopic($topic->id);

        $this->assertSame([["q:{$q->id}", 200, 200]], $this->earnedFor($items));
    }

    public function test_remarking_an_older_attempt_does_not_override_a_newer_attempt(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);
        $q = $this->question($topic, '4.00');
        $assessment = $this->assessment($course);

        $old = $this->quizResult($learner, $assessment, $q, '1.00', '2026-11-01 10:00:00');
        $this->quizResult($learner, $assessment, $q, '2.00', '2026-11-05 10:00:00');

        // The older attempt is re-marked later: new approved version 2, created after the newer attempt.
        $oldAnswer = Answer::where('attempt_id', $old->id)->first();
        $this->mark($oldAnswer, '4.00', 'approved', '2026-11-20 10:00:00');

        $items = $this->assembler->forLearnerCourse($learner->id, $course->id)->forTopic($topic->id);

        $this->assertSame([["q:{$q->id}", 200, 400]], $this->earnedFor($items));
    }

    public function test_pending_mark_in_newer_attempt_keeps_previous_result_and_counts_as_pending(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);
        $q = $this->question($topic, '5.00', type: 'open_ended');
        $assessment = $this->assessment($course);

        $first = $this->attempt($learner, $assessment, 'submitted', '2026-11-01 10:00:00');
        $this->mark($this->answer($first, $q), '3.00', 'approved');
        $second = $this->attempt($learner, $assessment, 'submitted', '2026-11-05 10:00:00');
        $this->mark($this->answer($second, $q), '5.00', 'pending');

        $evidence = $this->assembler->forLearnerCourse($learner->id, $course->id);

        $this->assertSame([["q:{$q->id}", 300, 500]], $this->earnedFor($evidence->forTopic($topic->id)));
        $this->assertSame(1, $evidence->pendingForTopic($topic->id));
    }

    public function test_rejected_newest_version_keeps_previous_approved_version(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);
        $q = $this->question($topic, '5.00', type: 'open_ended');
        $attempt = $this->attempt($learner, $this->assessment($course));
        $answer = $this->answer($attempt, $q);

        $this->mark($answer, '2.00', 'approved');
        $this->mark($answer, '4.00', 'rejected');

        $evidence = $this->assembler->forLearnerCourse($learner->id, $course->id);

        $this->assertSame([["q:{$q->id}", 200, 500]], $this->earnedFor($evidence->forTopic($topic->id)));
        $this->assertSame(1, $evidence->pendingForTopic($topic->id));
    }

    public function test_answer_awaiting_marking_counts_as_pending_only(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);
        $q = $this->question($topic, '5.00', type: 'open_ended');
        $this->answer($this->attempt($learner, $this->assessment($course)), $q);

        $evidence = $this->assembler->forLearnerCourse($learner->id, $course->id);

        $this->assertSame([], $evidence->forTopic($topic->id));
        $this->assertSame(1, $evidence->pendingForTopic($topic->id));
    }

    public function test_voided_latest_attempt_restores_previous_result(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);
        $q = $this->question($topic);
        $assessment = $this->assessment($course);

        $this->quizResult($learner, $assessment, $q, '1.00', '2026-11-01 10:00:00');
        $latest = $this->quizResult($learner, $assessment, $q, '2.00', '2026-11-05 10:00:00');
        $latest->update(['status' => 'voided']);

        $items = $this->assembler->forLearnerCourse($learner->id, $course->id)->forTopic($topic->id);

        $this->assertSame([["q:{$q->id}", 100, 200]], $this->earnedFor($items));
    }

    public function test_in_progress_attempt_does_not_count(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);
        $q = $this->question($topic);
        $assessment = $this->assessment($course);

        $this->quizResult($learner, $assessment, $q, '1.00', '2026-11-01 10:00:00');
        $this->answer($this->attempt($learner, $assessment, 'in_progress', null), $q);

        $evidence = $this->assembler->forLearnerCourse($learner->id, $course->id);

        $this->assertSame([["q:{$q->id}", 100, 200]], $this->earnedFor($evidence->forTopic($topic->id)));
        $this->assertSame(0, $evidence->pendingForTopic($topic->id));
    }

    public function test_same_finalised_time_is_broken_by_attempt_id(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);
        $q = $this->question($topic);

        $this->quizResult($learner, $this->assessment($course), $q, '0.00', '2026-11-05 10:00:00');
        $this->quizResult($learner, $this->assessment($course), $q, '2.00', '2026-11-05 10:00:00');

        $items = $this->assembler->forLearnerCourse($learner->id, $course->id)->forTopic($topic->id);

        $this->assertSame([["q:{$q->id}", 200, 200]], $this->earnedFor($items));
    }

    public function test_evidence_is_scoped_to_learner_and_course_and_grouped_by_topic(): void
    {
        $learner = $this->learner();
        $other = $this->learner();
        $course = $this->course();
        $otherCourse = $this->course('Web Tech');
        $lists = $this->topic($course, 'Lists');
        $dicts = $this->topic($course, 'Dictionaries');
        $css = $this->topic($otherCourse, 'CSS');
        $assessment = $this->assessment($course);

        $this->quizResult($learner, $assessment, $this->question($lists), '1.00');
        $this->quizResult($learner, $assessment, $this->question($dicts), '2.00');
        $this->quizResult($other, $assessment, $this->question($lists), '2.00');
        $this->quizResult($learner, $this->assessment($otherCourse), $this->question($css), '2.00');

        $evidence = $this->assembler->forLearnerCourse($learner->id, $course->id);

        $this->assertSame([$lists->id, $dicts->id], array_keys($evidence->itemsByTopic));
        $this->assertCount(1, $evidence->forTopic($lists->id));
        $this->assertCount(1, $evidence->forTopic($dicts->id));
        $this->assertSame(100, $evidence->forTopic($lists->id)[0]->earned);
    }

    public function test_evidence_uses_attempt_time_snapshot_not_current_question(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $lists = $this->topic($course, 'Lists');
        $dicts = $this->topic($course, 'Dictionaries');
        $q = $this->question($lists, '2.00', 'easy');

        $this->quizResult($learner, $this->assessment($course), $q, '2.00');
        $q->update(['topic_id' => $dicts->id, 'difficulty' => 'hard']);

        $evidence = $this->assembler->forLearnerCourse($learner->id, $course->id);

        $this->assertCount(1, $evidence->forTopic($lists->id));
        $this->assertSame([], $evidence->forTopic($dicts->id));
        $this->assertSame('easy', $evidence->forTopic($lists->id)[0]->difficulty->value);
    }

    // ---- Canvas (v0.8 §3.4–§3.5) ----

    public function test_posted_canvas_result_counts_with_source_canvas(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);

        $this->canvasResult($learner, $course, 'A1', 1, true, [['topic' => $topic, 'earned' => '7.50', 'max' => '10.00']]);

        $items = $this->assembler->forLearnerCourse($learner->id, $course->id)->forTopic($topic->id);

        $this->assertSame([["c:{$course->id}:A1", 750, 1000]], $this->earnedFor($items));
        $this->assertSame('canvas', $items[0]->sourceGroup);
    }

    public function test_unposted_newer_grade_keeps_posted_grade_and_counts_as_pending(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);

        $this->canvasResult($learner, $course, 'A1', 1, true, [['topic' => $topic, 'earned' => '5', 'max' => '10']]);
        $this->canvasResult($learner, $course, 'A1', 2, false, [['topic' => $topic, 'earned' => '9', 'max' => '10']]);

        $evidence = $this->assembler->forLearnerCourse($learner->id, $course->id);

        $this->assertSame([["c:{$course->id}:A1", 500, 1000]], $this->earnedFor($evidence->forTopic($topic->id)));
        $this->assertSame(1, $evidence->pendingForTopic($topic->id));
    }

    public function test_never_posted_canvas_grade_is_pending_only(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);

        $this->canvasResult($learner, $course, 'A1', 1, false, [['topic' => $topic, 'earned' => '9', 'max' => '10']]);

        $evidence = $this->assembler->forLearnerCourse($learner->id, $course->id);

        $this->assertSame([], $evidence->forTopic($topic->id));
        $this->assertSame(1, $evidence->pendingForTopic($topic->id));
    }

    public function test_excused_canvas_result_is_excluded(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);

        $this->canvasResult($learner, $course, 'A1', 1, true, [['topic' => $topic, 'earned' => '0', 'max' => '10']], excused: true);

        $evidence = $this->assembler->forLearnerCourse($learner->id, $course->id);

        $this->assertSame([], $evidence->forTopic($topic->id));
        $this->assertSame(0, $evidence->pendingForTopic($topic->id));
    }

    public function test_rubric_criteria_map_to_different_topics(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $lists = $this->topic($course, 'Lists');
        $dicts = $this->topic($course, 'Dictionaries');

        $this->canvasResult($learner, $course, 'A3', 1, true, [
            ['topic' => $lists, 'earned' => '7', 'max' => '10', 'key' => "c:{$course->id}:A3:C1"],
            ['topic' => $dicts, 'earned' => '3', 'max' => '10', 'key' => "c:{$course->id}:A3:C2", 'difficulty' => 'hard'],
        ]);

        $evidence = $this->assembler->forLearnerCourse($learner->id, $course->id);

        $this->assertSame([["c:{$course->id}:A3:C1", 700, 1000]], $this->earnedFor($evidence->forTopic($lists->id)));
        $this->assertSame([["c:{$course->id}:A3:C2", 300, 1000]], $this->earnedFor($evidence->forTopic($dicts->id)));
    }

    public function test_extra_credit_is_capped_at_points_possible(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);

        $this->canvasResult($learner, $course, 'A1', 1, true, [['topic' => $topic, 'earned' => '12', 'max' => '10']]);

        $items = $this->assembler->forLearnerCourse($learner->id, $course->id)->forTopic($topic->id);

        $this->assertSame(1000, $items[0]->earned);
    }

    // ---- End to end with the calculation core ----

    public function test_quiz_and_canvas_evidence_combine_and_conflict(): void
    {
        $learner = $this->learner();
        $course = $this->course();
        $topic = $this->topic($course);
        $assessment = $this->assessment($course);

        // LearnLoop practice: 9/10 across five 2-mark questions.
        foreach (['2.00', '2.00', '2.00', '2.00', '1.00'] as $earned) {
            $this->quizResult($learner, $assessment, $this->question($topic, '2.00', 'hard'), $earned);
        }
        // Canvas: 4/10.
        $this->canvasResult($learner, $course, 'A1', 1, true, [['topic' => $topic, 'earned' => '4', 'max' => '10']]);

        $items = $this->assembler->forLearnerCourse($learner->id, $course->id)->forTopic($topic->id);
        $assessmentResult = (new TopicAssessor())->assess($items, new DateTimeImmutable('2026-11-21 12:00:00'));

        // (9 + 4) / 20 = 65% → getting there; 90% vs 40% → conflict, both ≥ 5 marks → reduced.
        $this->assertSame(65.0, $assessmentResult->performance->displayPercent);
        $this->assertSame(PerformanceStatus::GettingThere, $assessmentResult->performance->status);
        $this->assertTrue($assessmentResult->conflicts->reducesConfidence());
        $this->assertSame(Confidence::Moderate, $assessmentResult->confidence->confidence);
    }

    // ---- Database constraints ----

    public function test_only_one_in_progress_attempt_per_learner_and_assessment(): void
    {
        $learner = $this->learner();
        $assessment = $this->assessment($this->course());

        $this->attempt($learner, $assessment, 'submitted');
        $this->attempt($learner, $assessment, 'submitted');
        $this->attempt($learner, $assessment, 'in_progress', null);

        $this->expectException(QueryException::class);
        $this->attempt($learner, $assessment, 'in_progress', null);
    }

    public function test_finishing_an_attempt_frees_the_in_progress_slot(): void
    {
        $learner = $this->learner();
        $assessment = $this->assessment($this->course());

        $first = $this->attempt($learner, $assessment, 'in_progress', null);
        $first->update(['status' => 'submitted', 'finalised_at' => now()]);
        $this->attempt($learner, $assessment, 'in_progress', null);

        $this->assertSame(1, Attempt::where('status', 'in_progress')->count());
    }

    public function test_one_answer_per_attempt_and_question(): void
    {
        $course = $this->course();
        $q = $this->question($this->topic($course));
        $attempt = $this->attempt($this->learner(), $this->assessment($course), 'in_progress', null);
        $this->answer($attempt, $q);

        $this->expectException(QueryException::class);
        Answer::create(['attempt_id' => $attempt->id, 'question_id' => $q->id]);
    }

    public function test_answer_keys_are_hidden_when_questions_are_serialised(): void
    {
        $q = $this->question($this->topic($this->course()));

        $this->assertArrayNotHasKey('answer_data', $q->toArray());
        $this->assertSame(200, Marks::toHundredths($q->max_marks));
    }
}
