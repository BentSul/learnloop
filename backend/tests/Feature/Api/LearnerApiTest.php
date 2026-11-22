<?php

namespace Tests\Feature\Api;

use App\Models\Assessment;
use App\Models\Course;
use App\Models\Recommendation;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** AGENTS.md §9–§11 learner endpoints, run against the demo data. */
class LearnerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function as(string $name): User
    {
        $user = User::where('email', "{$name}@student.learnloop.test")->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    private function webTech(): Course
    {
        return Course::where('code', 'WT101')->firstOrFail();
    }

    private function assertNoAnswerKeys(string $json): void
    {
        foreach (['correct_option_ids', 'answer_data', 'marking_snapshot', 'topic_size'] as $secret) {
            $this->assertStringNotContainsString($secret, $json, "Learner response leaked {$secret}");
        }
    }

    public function test_every_route_needs_a_token(): void
    {
        $this->getJson('/api/me/courses')->assertUnauthorized();
        $this->getJson('/api/me/recommendations')->assertUnauthorized();
        $this->getJson("/api/courses/{$this->webTech()->id}/topics")->assertUnauthorized();
    }

    public function test_courses_and_topic_performance(): void
    {
        $this->as('jamie.lee');

        $this->getJson('/api/me/courses')->assertOk()
            ->assertJsonCount(2)->assertJsonStructure([['id', 'name', 'code']]);

        $response = $this->getJson("/api/me/courses/{$this->webTech()->id}/topics")->assertOk()
            ->assertJsonStructure(['data' => [['topic_id', 'topic_name', 'status', 'label', 'performance_display',
                'confidence', 'confidence_reasons', 'pending_count', 'conflict', 'explanation']]]);
        $this->assertNoAnswerKeys($response->getContent());

        $this->postJson("/api/me/courses/{$this->webTech()->id}/sync")->assertOk()
            ->assertJsonStructure(['created', 'unchanged', 'skipped', 'flagged']);
        $this->getJson("/api/me/courses/{$this->webTech()->id}/diagnostic-prompt")->assertOk()->assertJsonStructure(['show']);
    }

    public function test_users_outside_a_course_get_404(): void
    {
        Sanctum::actingAs(User::factory()->create()); // no Canvas connection
        $course = $this->webTech();

        $this->getJson('/api/me/courses')->assertOk()->assertExactJson([]);
        $this->getJson("/api/me/courses/{$course->id}/topics")->assertNotFound();
        $this->getJson("/api/me/courses/{$course->id}/practice")->assertNotFound();
        $this->postJson('/api/me/assessments/'.Assessment::where('course_id', $course->id)->value('id').'/attempts')->assertNotFound();
        $this->postJson('/api/me/topics/'.$course->topics()->value('id').'/goals')->assertNotFound();
    }

    public function test_a_lecturer_cannot_use_learner_routes_for_their_course(): void
    {
        Sanctum::actingAs(User::where('email', 'dr.sarah.chen@learnloop.test')->firstOrFail());

        $this->getJson("/api/me/courses/{$this->webTech()->id}/topics")->assertNotFound();
    }

    public function test_practice_quiz_flow(): void
    {
        $this->as('alex.morgan');
        $practice = $this->getJson("/api/me/courses/{$this->webTech()->id}/practice")->assertOk()
            ->assertJsonStructure([['id', 'title', 'topic' => ['id', 'name']]])->json();

        $start = $this->postJson("/api/me/assessments/{$practice[0]['id']}/attempts")->assertCreated();
        $this->assertNoAnswerKeys($start->getContent());
        $attemptId = $start->json('id') ?? $start->json('attempt_id');
        $this->assertNotNull($attemptId);

        $this->postJson("/api/me/assessments/{$practice[0]['id']}/attempts")->assertStatus(409);

        $show = $this->getJson("/api/me/attempts/{$attemptId}")->assertOk();
        $first = $show->json('items.0') ?? $show->json('questions.0');
        $this->putJson("/api/me/attempts/{$attemptId}/answers/{$first['question_id']}", ['option_ids' => [$first['options'][0]['id']]])
            ->assertOk()->assertExactJson(['saved' => true]);
        $this->putJson("/api/me/attempts/{$attemptId}/answers/{$first['question_id']}", ['option_ids' => 'a'])->assertUnprocessable();

        $this->postJson("/api/me/attempts/{$attemptId}/submit")->assertUnprocessable()
            ->assertJson(['requires_confirmation' => true]);
        $result = $this->postJson("/api/me/attempts/{$attemptId}/submit", ['confirm_unanswered' => true])->assertOk();
        $this->assertNoAnswerKeys($result->getContent());

        $this->postJson("/api/me/attempts/{$attemptId}/submit", ['confirm_unanswered' => true])->assertStatus(409);

        $this->as('jamie.lee');
        $this->getJson("/api/me/attempts/{$attemptId}")->assertNotFound();
    }

    public function test_recommendation_actions(): void
    {
        $jamie = $this->as('jamie.lee');

        $active = $this->getJson('/api/me/recommendations')->assertOk()->json('data');
        $this->assertNotEmpty($active);
        $this->getJson('/api/me/recommendations/shortlist')->assertOk()->assertJsonStructure(['data']);
        $this->getJson('/api/me/recommendations?state=nonsense')->assertUnprocessable();

        $id = $active[0]['id'];
        $this->postJson("/api/me/recommendations/{$id}/postpone", [])->assertUnprocessable();
        $this->postJson("/api/me/recommendations/{$id}/postpone", ['reminder_at' => now()->subDay()->toIso8601String()])->assertUnprocessable();
        $this->postJson("/api/me/recommendations/{$id}/postpone", ['reminder_at' => now()->addDays(3)->toIso8601String()])
            ->assertOk()->assertJson(['state' => 'postponed']);

        $this->postJson("/api/me/recommendations/{$id}/dismiss")->assertOk()->assertJson(['state' => 'dismissed']);
        $this->getJson('/api/me/recommendations?state=dismissed')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->postJson("/api/me/recommendations/{$id}/restore")->assertOk()
            ->assertJsonStructure(['data' => ['id', 'state'], 'restored', 'message'])
            ->assertJson(['restored' => true, 'data' => ['state' => 'active']]);
        $this->postJson("/api/me/recommendations/{$id}/accept")->assertOk()->assertJson(['state' => 'accepted']);

        $this->as('alex.morgan');
        $this->postJson("/api/me/recommendations/{$id}/accept")->assertNotFound();
        $this->assertSame($jamie->id, Recommendation::find($id)->learner_id);
    }

    public function test_goals_help_requests_and_revision_quiz(): void
    {
        $this->as('sam.patel');
        $topic = Topic::where('course_id', $this->webTech()->id)->orderBy('id')->firstOrFail();

        $this->postJson("/api/me/topics/{$topic->id}/goals")->assertCreated()->assertJson(['goal_type' => 'mastery']);
        $this->postJson("/api/me/topics/{$topic->id}/help-requests")->assertCreated()->assertJson(['goal_type' => 'help']);
        $this->getJson('/api/me/goals')->assertOk()->assertJsonCount(2)
            ->assertJsonStructure([['id', 'topic' => ['id', 'name'], 'goal_type']]);
        $this->deleteJson("/api/me/topics/{$topic->id}/goals")->assertNoContent();
        $this->getJson('/api/me/goals')->assertJsonCount(1);

        $revision = $this->postJson("/api/me/topics/{$topic->id}/revision-quiz")->assertCreated();
        $this->assertNoAnswerKeys($revision->getContent());
    }
}
