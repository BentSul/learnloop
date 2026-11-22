<?php

namespace Tests\Feature\Adaptive;

use App\Enums\Adaptive\GoalType;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\RecommendationEvent;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\Calculation\TopicAssessor;
use App\Services\Adaptive\Evidence\EvidenceAssembler;
use App\Services\Adaptive\GoalService;
use App\Services\Adaptive\Recommendations\RecommendationActions;
use App\Services\Adaptive\Recommendations\RecommendationEngine;
use App\Services\Adaptive\Recommendations\RecommendationException;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Spec §10.7–§10.10, §10.14, §16.7: reconciliation, learner actions and goals. */
class RecommendationEngineTest extends TestCase
{
    use BuildsAdaptiveData;
    use RefreshDatabase;

    private User $learner;
    private Course $course;
    private Topic $topic;
    private Question $question;
    private Assessment $assessment;
    private RecommendationEngine $engine;
    private RecommendationActions $actions;
    private Carbon $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->learner = $this->learner();
        $this->course = $this->course();
        $this->topic = $this->topic($this->course);
        $this->question = $this->question($this->topic, '10.00');
        $this->assessment = $this->assessment($this->course);
        $this->engine = new RecommendationEngine();
        $this->actions = new RecommendationActions($this->engine);
        $this->clock = Carbon::parse('2026-11-21 12:00:00');
    }

    /** Records a new practice result on the single 10-mark question (latest attempt wins). */
    private function score(string $earned): void
    {
        $this->clock = $this->clock->copy()->addHour();
        $this->quizResult($this->learner, $this->assessment, $this->question, $earned, $this->clock->toDateTimeString());
    }

    private function reconcile(): void
    {
        $this->engine->reconcile($this->learner, $this->course, $this->clock);
    }

    /** @return list<array{string, string}> [signature status part, state] oldest first */
    private function history(): array
    {
        return Recommendation::orderBy('id')->get()
            ->map(fn ($r) => [explode('|', $r->signature)[1], $r->state->value])->all();
    }

    public function test_creates_one_active_recommendation_with_an_event(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $this->reconcile();

        $this->assertSame([['needs_help', 'active']], $this->history());
        $rec = Recommendation::sole();
        $this->assertSame('high', $rec->priority->value);
        $this->assertEquals(20.0, $rec->basis['performance_display']); // JSON round trip stores 20.0 as 20
        $this->assertSame([[null, 'active']], RecommendationEvent::get()->map(fn ($e) => [$e->from_state, $e->to_state])->all());
    }

    public function test_new_evidence_with_the_same_status_changes_nothing(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $this->score('3.00');
        $this->reconcile();

        $this->assertSame([['needs_help', 'active']], $this->history());
    }

    public function test_dismissed_recommendation_is_not_resurrected_by_ordinary_new_evidence(): void
    {
        // T3
        $this->score('2.00');
        $this->reconcile();
        $this->actions->dismiss($this->learner, Recommendation::sole()->id, $this->clock);

        $this->score('3.00');
        $this->reconcile();

        $this->assertSame([['needs_help', 'dismissed']], $this->history());
    }

    public function test_status_change_after_dismissal_creates_a_new_recommendation(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $this->actions->dismiss($this->learner, Recommendation::sole()->id, $this->clock);

        $this->score('6.00');
        $this->reconcile();

        $this->assertSame([['needs_help', 'dismissed'], ['getting_there', 'active']], $this->history());
    }

    public function test_flip_back_to_a_previously_dismissed_status_creates_a_new_one(): void
    {
        // D4 default.
        $this->score('2.00');
        $this->reconcile();
        $this->actions->dismiss($this->learner, Recommendation::sole()->id, $this->clock);
        $this->score('6.00');
        $this->reconcile();
        $this->score('2.00');
        $this->reconcile();

        $this->assertSame(
            [['needs_help', 'dismissed'], ['getting_there', 'superseded'], ['needs_help', 'active']],
            $this->history(),
        );
    }

    public function test_open_recommendation_is_superseded_when_the_status_changes(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $this->score('6.00');
        $this->reconcile();

        [$old, $new] = Recommendation::orderBy('id')->get()->all();
        $this->assertSame('superseded', $old->state->value);
        $this->assertSame($new->id, $old->superseded_by_id);
        $this->assertSame('active', $new->state->value);
    }

    public function test_open_recommendation_becomes_obsolete_when_no_longer_needed(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $this->score('10.00'); // strong, nothing else → no recommendation
        $this->reconcile();

        $this->assertSame([['needs_help', 'obsolete']], $this->history());
    }

    public function test_accepted_then_qualifying_practice_is_completed_not_superseded(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $this->actions->accept($this->learner, Recommendation::sole()->id, $this->clock);

        $this->score('6.00'); // practice after accepting, which also changes the status
        $this->reconcile();

        $this->assertSame([['needs_help', 'completed'], ['getting_there', 'active']], $this->history());
    }

    public function test_completed_recommendation_does_not_block_the_same_need(): void
    {
        // Proposed D18: still needing help after completing the activity gives a new recommendation.
        $this->score('2.00');
        $this->reconcile();
        $this->actions->accept($this->learner, Recommendation::sole()->id, $this->clock);
        $this->score('3.00');
        $this->reconcile();

        $this->assertSame([['needs_help', 'completed'], ['needs_help', 'active']], $this->history());
    }

    public function test_accepted_without_activity_stays_accepted(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $this->actions->accept($this->learner, Recommendation::sole()->id, $this->clock);
        $this->reconcile();

        $this->assertSame([['needs_help', 'accepted']], $this->history());
    }

    public function test_due_reminder_reactivates_if_still_applicable(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $rec = Recommendation::sole();
        $this->actions->postpone($this->learner, $rec->id, $this->clock->copy()->addDay()->toIso8601String(), $this->clock);

        $this->reconcile();
        $this->assertSame('postponed', $rec->fresh()->state->value);

        $this->clock = $this->clock->copy()->addDays(2);
        $this->reconcile();
        $this->assertSame('active', $rec->fresh()->state->value);
    }

    public function test_superseded_postponed_recommendation_carries_its_reminder(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $reminder = $this->clock->copy()->addDays(5);
        $this->actions->postpone($this->learner, Recommendation::sole()->id, $reminder->toIso8601String(), $this->clock);

        $this->score('6.00');
        $this->reconcile();

        $new = Recommendation::orderByDesc('id')->first();
        $this->assertSame('postponed', $new->state->value);
        $this->assertTrue($reminder->equalTo($new->reminder_at));
    }

    public function test_archived_topic_makes_open_recommendations_obsolete(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $this->topic->update(['status' => 'archived']);
        $this->reconcile();

        $this->assertSame([['needs_help', 'obsolete']], $this->history());
    }

    public function test_database_refuses_two_open_recommendations_for_one_topic(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $copy = Recommendation::sole()->replicate(['open_key']);

        $this->expectException(QueryException::class);
        $copy->save();
    }

    // ---------------------------------------------------------------- actions

    public function test_accept_is_idempotent_and_invalid_transitions_are_409(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $id = Recommendation::sole()->id;

        $this->actions->accept($this->learner, $id, $this->clock);
        $this->actions->accept($this->learner, $id, $this->clock);
        $this->assertSame(2, RecommendationEvent::count()); // created + accepted only

        $this->actions->dismiss($this->learner, $id, $this->clock);
        try {
            $this->actions->accept($this->learner, $id, $this->clock);
            $this->fail('Expected 409');
        } catch (RecommendationException $e) {
            $this->assertSame(409, $e->status());
        }
    }

    public function test_reminder_must_be_future_and_within_60_days(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $id = Recommendation::sole()->id;

        foreach ([$this->clock->copy()->subHour(), $this->clock->copy()->addDays(61)] as $bad) {
            try {
                $this->actions->postpone($this->learner, $id, $bad->toIso8601String(), $this->clock);
                $this->fail('Expected 422');
            } catch (RecommendationException $e) {
                $this->assertSame(422, $e->status());
            }
        }

        $rec = $this->actions->postpone($this->learner, $id, '2026-11-25T09:00:00+02:00', $this->clock);
        $this->assertSame('2026-11-25 07:00:00', $rec->reminder_at->utc()->toDateTimeString()); // stored in UTC
    }

    public function test_dismissal_never_changes_performance(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $before = $this->assessTopic();
        $this->actions->dismiss($this->learner, Recommendation::sole()->id, $this->clock);

        $this->assertEquals($before->performance, $this->assessTopic()->performance);
    }

    public function test_restore_when_still_applicable_and_when_not(): void
    {
        $this->score('2.00');
        $this->reconcile();
        $id = Recommendation::sole()->id;
        $this->actions->dismiss($this->learner, $id, $this->clock);

        $result = $this->actions->restore($this->learner, $id, $this->clock);
        $this->assertTrue($result['restored']);
        $this->assertSame('active', Recommendation::find($id)->state->value);

        $this->actions->dismiss($this->learner, $id, $this->clock);
        $this->score('6.00');
        $this->reconcile(); // creates a getting_there recommendation

        $result = $this->actions->restore($this->learner, $id, $this->clock);
        $this->assertFalse($result['restored']);
        $this->assertSame('dismissed', Recommendation::find($id)->state->value);
        $this->assertSame(1, Recommendation::where('state', 'active')->count());
    }

    public function test_another_learner_gets_404(): void
    {
        $this->score('2.00');
        $this->reconcile();

        $this->expectException(RecommendationException::class);
        $this->actions->accept($this->learner(), Recommendation::sole()->id, $this->clock);
    }

    // ---------------------------------------------------------------- goals

    public function test_help_request_raises_priority_and_goals_never_change_performance(): void
    {
        $this->score('6.00');
        $this->reconcile();
        $before = $this->assessTopic();
        $this->assertSame('medium', Recommendation::sole()->priority->value);

        $goals = new GoalService($this->engine);
        $goals->add($this->learner, $this->topic, GoalType::Help);
        $goals->add($this->learner, $this->topic, GoalType::Help); // idempotent

        $this->assertSame(1, $goals->forLearner($this->learner)->count());
        $this->assertSame('high', Recommendation::where('state', 'active')->sole()->priority->value);
        $this->assertEquals($before->performance, $this->assessTopic()->performance);

        $goals->remove($this->learner, $this->topic, GoalType::Help);
        $this->assertSame(0, $goals->forLearner($this->learner)->count());
        $this->assertSame('medium', Recommendation::where('state', 'active')->sole()->priority->value);
    }

    public function test_diagnostic_prompt_for_unassessed_topic_without_goals(): void
    {
        $this->assertTrue($this->engine->showDiagnosticPrompt($this->learner, $this->course, $this->clock));

        $this->score('6.00');
        $this->assertFalse($this->engine->showDiagnosticPrompt($this->learner, $this->course, $this->clock));
    }

    private function assessTopic()
    {
        $items = (new EvidenceAssembler())->forLearnerCourse($this->learner->id, $this->course->id)->forTopic($this->topic->id);

        return (new TopicAssessor())->assess($items, new DateTimeImmutable('2026-11-21 12:00:00'));
    }
}
