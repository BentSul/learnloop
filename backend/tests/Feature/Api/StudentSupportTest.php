<?php

namespace Tests\Feature\Api;

use App\Models\CanvasConnection;
use App\Models\Course;
use App\Models\LearnerTopicGoal;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Help requests for lecturers, progress over time, and a learner's own data. */
class StudentSupportTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->course = Course::where('code', 'WT101')->firstOrFail();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_lecturer_sees_and_acknowledges_help_requests(): void
    {
        $css = Topic::where('name', 'CSS')->firstOrFail();
        Sanctum::actingAs($this->user('jamie.lee@student.learnloop.test'));
        $this->postJson("/api/me/topics/{$css->id}/help-requests")->assertCreated();

        Sanctum::actingAs($this->user('dr.sarah.chen@learnloop.test'));
        $requests = $this->getJson("/api/courses/{$this->course->id}/help-requests")->assertOk()->json('data');
        $this->assertCount(1, $requests);
        $this->assertSame('Jamie Lee', $requests[0]['learner']['name']);
        $this->assertSame('CSS', $requests[0]['topic']['name']);
        $this->assertNotNull($requests[0]['status']);
        $this->assertNull($requests[0]['acknowledged_at']);

        $this->postJson("/api/help-requests/{$requests[0]['id']}/acknowledge")->assertOk()->assertJsonStructure(['acknowledged_at']);

        Sanctum::actingAs($this->user('jamie.lee@student.learnloop.test'));
        $help = collect($this->getJson('/api/me/goals')->json())->firstWhere('goal_type', 'help');
        $this->assertNotNull($help['acknowledged_at'], 'the student can see their request was seen');

        // Other lecturers and students cannot see or acknowledge.
        Sanctum::actingAs($this->user('prof.james.okafor@learnloop.test'));
        $this->getJson("/api/courses/{$this->course->id}/help-requests")->assertNotFound();
        $this->postJson("/api/help-requests/{$requests[0]['id']}/acknowledge")->assertNotFound();
        Sanctum::actingAs($this->user('alex.morgan@student.learnloop.test'));
        $this->getJson("/api/courses/{$this->course->id}/help-requests")->assertNotFound();

        // Mastery goals are not help requests.
        $goal = LearnerTopicGoal::where('goal_type', 'help')->firstOrFail();
        $goal->update(['goal_type' => 'mastery']);
        Sanctum::actingAs($this->user('dr.sarah.chen@learnloop.test'));
        $this->getJson("/api/courses/{$this->course->id}/help-requests")->assertExactJson(['data' => []]);
        $this->postJson("/api/help-requests/{$goal->id}/acknowledge")->assertNotFound();
    }

    public function test_progress_history_builds_up_day_by_day(): void
    {
        Sanctum::actingAs($this->user('jamie.lee@student.learnloop.test'));
        $topic = Topic::where('name', 'HTML basics')->firstOrFail();

        $history = $this->getJson("/api/me/topics/{$topic->id}/history")->assertOk()->json();
        $this->assertSame('HTML basics', $history['topic']['name']);
        $this->assertNotEmpty($history['points']);
        $dates = array_column($history['points'], 'date');
        $this->assertSame($dates, collect($dates)->sort()->values()->all(), 'oldest first');

        $last = end($history['points']);
        $current = collect($this->getJson("/api/me/courses/{$this->course->id}/topics")->json('data'))->firstWhere('topic_name', 'HTML basics');
        $this->assertSame($current['performance_display'], $last['cumulative_display'], 'the last point matches today\'s result');
        $this->assertSame($current['status'], $last['status']);
        $this->assertArrayHasKey('label', $last);

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/me/topics/{$topic->id}/history")->assertNotFound();
    }

    public function test_data_export_has_the_learners_data_and_no_secrets(): void
    {
        $jamie = $this->user('jamie.lee@student.learnloop.test');
        CanvasConnection::where('user_id', $jamie->id)->update(['access_token' => encrypt('super-secret-token')]);
        Sanctum::actingAs($jamie);
        $this->getJson('/api/me/recommendations'); // creates recommendations

        $response = $this->get('/api/me/data-export', ['Accept' => 'application/json'])->assertOk();
        $this->assertStringContainsString('attachment; filename="learnloop-my-data-', $response->headers->get('Content-Disposition'));
        $data = $response->json();

        $this->assertSame('jamie.lee@student.learnloop.test', $data['account']['email']);
        $this->assertNotEmpty($data['quiz_attempts']);
        $this->assertNotEmpty($data['quiz_attempts'][0]['answers']);
        $this->assertNotEmpty($data['canvas_and_klenam_results']);
        $this->assertNotEmpty($data['recommendations']);
        $this->assertNotEmpty($data['current_topic_results']);
        foreach (['super-secret-token', 'access_token', 'refresh_token', 'marking_snapshot', 'correct_option_ids', 'password', 'alex.morgan'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent(), "export leaked {$secret}");
        }
    }

    public function test_account_deletion_removes_everything_about_the_learner(): void
    {
        $jamie = $this->user('jamie.lee@student.learnloop.test');
        Sanctum::actingAs($jamie);
        $this->getJson('/api/me/recommendations');
        $this->postJson('/api/me/topics/'.Topic::where('name', 'CSS')->value('id').'/help-requests');
        $this->postJson('/api/me/topics/'.Topic::where('name', 'HTML basics')->value('id').'/revision-quiz')->assertCreated();
        $this->assertGreaterThan(0, DB::table('attempts')->where('learner_id', $jamie->id)->count());

        $this->deleteJson('/api/me/account', ['confirm' => 'delete'])->assertUnprocessable();
        $this->deleteJson('/api/me/account', ['confirm' => 'DELETE MY ACCOUNT', 'password' => 'wrong'])->assertUnprocessable();
        $this->deleteJson('/api/me/account', ['confirm' => 'DELETE MY ACCOUNT', 'password' => 'password123'])->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $jamie->id]);
        foreach (['attempts' => 'learner_id', 'learner_topic_goals' => 'learner_id', 'recommendations' => 'learner_id',
            'recommendation_events' => 'learner_id', 'external_results' => 'learner_id', 'canvas_connections' => 'user_id',
            'sync_runs' => 'user_id', 'assessments' => 'owner_learner_id', 'personal_access_tokens' => 'tokenable_id'] as $table => $column) {
            $this->assertSame(0, DB::table($table)->where($column, $jamie->id)->count(), "{$table} still has rows");
        }

        // Everyone else is untouched.
        $this->assertGreaterThan(0, DB::table('attempts')->where('learner_id', $this->user('alex.morgan@student.learnloop.test')->id)->count());
    }

    public function test_admins_cannot_delete_themselves_here(): void
    {
        Sanctum::actingAs($this->user('admin@learnloop.test'));
        $this->deleteJson('/api/me/account', ['confirm' => 'DELETE MY ACCOUNT', 'password' => 'password123'])->assertUnprocessable();
        $this->assertDatabaseHas('users', ['email' => 'admin@learnloop.test']);
    }
}
