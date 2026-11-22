<?php

namespace Tests\Feature\Adaptive;

use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\Question;
use App\Models\Recommendation;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\Quiz\Exceptions\AssessmentUnavailable;
use App\Services\Adaptive\Quiz\Exceptions\InvalidAssessment;
use App\Services\Adaptive\Quiz\RevisionQuizService;
use App\Services\Adaptive\Recommendations\RecommendationActions;
use App\Services\Adaptive\Recommendations\RecommendationQuery;
use App\Services\Adaptive\Quiz\AttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec §10.11–§10.12 listing and ordering, §6.7 revision quizzes; uses the demo data. */
class RecommendationQueryAndRevisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_jamies_active_recommendations_are_ordered_by_priority_then_performance(): void
    {
        $active = app(RecommendationQuery::class)->section($this->user('jamie.lee@student.learnloop.test'), 'active');

        $this->assertSame(
            ['Lists', 'Dictionaries', 'CSS', 'Functions', 'JavaScript logic', 'HTML basics', 'Python basics'],
            array_column(array_column($active, 'topic'), 'name'),
        );
        $this->assertSame(['high', 'high', 'high', 'high', 'medium', 'medium', 'medium'], array_column($active, 'priority'));
    }

    public function test_recommendation_json_explains_itself(): void
    {
        $active = collect(app(RecommendationQuery::class)->section($this->user('jamie.lee@student.learnloop.test'), 'active'))
            ->keyBy(fn ($r) => $r['topic']['name']);

        $functions = $active['Functions'];
        $this->assertSame(['getting_there', 'conflict_sufficient'], $functions['reason_codes']);
        $this->assertSame('conflict_sufficient', $functions['primary_reason']);
        $this->assertStringContainsString('disagree by 70 points', $functions['reasons'][1]);
        $this->assertSame('revision_quiz', $functions['suggested_action']['key']);

        $this->assertStringContainsString('You have scored 5/18 (27.7%) on Lists.', $active['Lists']['reasons'][0]);
    }

    public function test_alex_has_no_recommendations_and_sam_gets_a_low_priority_confirmation(): void
    {
        $query = app(RecommendationQuery::class);

        $this->assertSame([], $query->section($this->user('alex.morgan@student.learnloop.test'), 'active'));

        $sam = collect($query->section($this->user('sam.patel@student.learnloop.test'), 'active'))
            ->keyBy(fn ($r) => $r['topic']['name']);
        $this->assertSame('low', $sam['CSS']['priority']);
        $this->assertSame('strong_unconfirmed', $sam['CSS']['primary_reason']);
    }

    public function test_shortlist_is_limited_and_sections_separate_states(): void
    {
        $jamie = $this->user('jamie.lee@student.learnloop.test');
        $query = app(RecommendationQuery::class);

        $this->assertCount(5, $query->shortlist($jamie));

        $first = $query->section($jamie, 'active')[0]['id'];
        app(RecommendationActions::class)->dismiss($jamie, $first);

        $this->assertSame([$first], array_column($query->section($jamie, 'dismissed'), 'id'));
        $this->assertNotContains($first, array_column($query->section($jamie, 'active'), 'id'));
    }

    public function test_due_reminder_is_processed_when_only_the_shortlist_is_loaded(): void
    {
        $jamie = $this->user('jamie.lee@student.learnloop.test');
        $query = app(RecommendationQuery::class);
        $id = $query->section($jamie, 'active')[0]['id'];

        app(RecommendationActions::class)->postpone($jamie, $id, now()->addDay()->toIso8601String());
        $this->assertNotContains($id, array_column($query->shortlist($jamie), 'id'));

        $this->assertContains($id, array_column($query->shortlist($jamie, now()->addDays(2)), 'id'));
    }

    // ---------------------------------------------------------------- revision quizzes

    public function test_revision_quiz_uses_topic_questions_and_belongs_to_its_owner(): void
    {
        $jamie = $this->user('jamie.lee@student.learnloop.test');
        $lists = Topic::where('name', 'Lists')->firstOrFail();

        $attempt = app(RevisionQuizService::class)->createAndStart($jamie, $lists);

        $this->assertSame('in_progress', $attempt->status->value);
        $this->assertSame('revision', $attempt->assessment->assessment_type->value);
        $this->assertSame(4, AttemptItem::where('attempt_id', $attempt->id)->count());
        $this->assertSame([$lists->id], AttemptItem::where('attempt_id', $attempt->id)->distinct()->pluck('topic_id_snapshot')->all());

        $this->expectException(AssessmentUnavailable::class);
        (new AttemptService())->start($this->user('alex.morgan@student.learnloop.test'), $attempt->assessment);
    }

    public function test_revision_quiz_prefers_questions_not_answered_recently(): void
    {
        $jamie = $this->user('jamie.lee@student.learnloop.test');
        $lists = Topic::where('name', 'Lists')->firstOrFail();
        config(['adaptive.revision_quiz.max_questions' => 2]);
        foreach (range(1, 3) as $i) {
            Question::create([
                'topic_id' => $lists->id, 'question_type' => 'true_false', 'question_text' => "New {$i}",
                'max_marks' => '1.00',
                'answer_data' => ['options' => [['id' => 't', 'text' => 'True'], ['id' => 'f', 'text' => 'False']], 'correct_option_ids' => ['t']],
            ]);
        }

        $attempt = app(RevisionQuizService::class)->createAndStart($jamie, $lists);

        $texts = AttemptItem::where('attempt_id', $attempt->id)->pluck('question_text_snapshot')->all();
        $this->assertCount(2, $texts);
        foreach ($texts as $text) {
            $this->assertStringStartsWith('New ', $text); // Jamie answered the original four in the last 7 days
        }
    }

    public function test_revision_quiz_needs_at_least_three_questions(): void
    {
        $lists = Topic::where('name', 'Lists')->firstOrFail();
        Question::where('topic_id', $lists->id)->limit(2)->get()->each->update(['status' => 'inactive']);

        $this->expectException(InvalidAssessment::class);
        app(RevisionQuizService::class)->createAndStart($this->user('jamie.lee@student.learnloop.test'), $lists);
    }

    public function test_unfinished_revision_attempt_never_counts(): void
    {
        $jamie = $this->user('jamie.lee@student.learnloop.test');
        $before = Recommendation::count();
        $lists = Topic::where('name', 'Lists')->firstOrFail();

        app(RevisionQuizService::class)->createAndStart($jamie, $lists);
        $active = app(RecommendationQuery::class)->section($jamie, 'active');

        $listsRec = collect($active)->firstWhere('topic.name', 'Lists');
        $this->assertSame(27.7, $listsRec['performance']['performance_display']);
        $this->assertSame(1, Attempt::where('status', 'in_progress')->count());
        $this->assertGreaterThanOrEqual($before, Recommendation::count());
    }
}
