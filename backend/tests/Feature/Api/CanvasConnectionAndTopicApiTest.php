<?php

namespace Tests\Feature\Api;

use App\Models\CanvasConnection;
use App\Models\Course;
use App\Models\CourseLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Connecting a real Canvas account, lecturer topic management and linking courses. */
class CanvasConnectionAndTopicApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://canvas.example.edu';

    private const TOKEN = '1234~abcdefghijklmnopqrstuvwxyz0123456789';

    private function tokenMode(): void
    {
        config(['adaptive.canvas.adapter' => 'token', 'adaptive.canvas.base_url' => self::BASE]);
    }

    public function test_connect_checks_the_token_and_never_returns_it(): void
    {
        $this->tokenMode();
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Http::fake([self::BASE.'/api/v1/users/self' => Http::response(['id' => 4242, 'name' => 'Real Student'])]);

        $this->getJson('/api/me/canvas')->assertOk()->assertJson(['connected' => false, 'adapter' => 'token']);

        $response = $this->postJson('/api/me/canvas', ['access_token' => self::TOKEN])->assertOk()
            ->assertJson(['connected' => true, 'canvas_user_id' => '4242']);
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());
        $this->assertStringNotContainsString(self::TOKEN, $this->getJson('/api/me/canvas')->getContent());

        $stored = DB::table('canvas_connections')->where('user_id', $user->id)->value('access_token');
        $this->assertNotSame(self::TOKEN, $stored, 'token is encrypted at rest');
        $this->assertSame(self::TOKEN, CanvasConnection::where('user_id', $user->id)->first()->access_token);

        $this->deleteJson('/api/me/canvas')->assertNoContent();
        $this->getJson('/api/me/canvas')->assertJson(['connected' => false]);
        $this->assertNull(CanvasConnection::where('user_id', $user->id)->first()->access_token);
    }

    public function test_rejected_tokens_and_duplicate_accounts(): void
    {
        $this->tokenMode();
        Http::fake([self::BASE.'/api/v1/users/self' => Http::sequence()
            ->push(['errors' => [['message' => 'Invalid access token.']]], 401)
            ->push(['id' => 4242])]);
        CanvasConnection::create(['user_id' => User::factory()->create()->id, 'canvas_user_id' => '4242',
            'adapter' => 'token', 'access_token' => 'x', 'connected_at' => now()]);

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/me/canvas', ['access_token' => self::TOKEN])->assertUnprocessable()
            ->assertJsonFragment(['message' => 'Canvas did not accept this token. Check that you copied all of it and that it has not expired.']);
        $this->postJson('/api/me/canvas', ['access_token' => self::TOKEN])->assertUnprocessable()
            ->assertJsonFragment(['message' => 'This Canvas account is already linked to another LearnLoop account. Disconnect Canvas in that account first.']);
        $this->postJson('/api/me/canvas', ['access_token' => 'short'])->assertUnprocessable();
    }

    public function test_a_disconnected_canvas_account_can_be_linked_to_another_learnloop_account(): void
    {
        $this->tokenMode();
        Http::fake([self::BASE.'/api/v1/users/self' => Http::response(['id' => 4242])]);
        $first = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($first);
        $this->postJson('/api/me/canvas', ['access_token' => self::TOKEN])->assertOk();

        Sanctum::actingAs($second);
        $this->postJson('/api/me/canvas', ['access_token' => self::TOKEN])->assertUnprocessable();

        Sanctum::actingAs($first);
        $this->deleteJson('/api/me/canvas')->assertNoContent();

        Sanctum::actingAs($second);
        $this->postJson('/api/me/canvas', ['access_token' => self::TOKEN])->assertOk()->assertJson(['connected' => true]);
        $this->assertSame($second->id, CanvasConnection::where('canvas_user_id', '4242')->sole()->user_id);

        Sanctum::actingAs($first);
        $this->getJson('/api/me/canvas')->assertJson(['connected' => false]);
    }

    public function test_connect_is_refused_in_fixture_mode(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Http::fake();

        $this->postJson('/api/me/canvas', ['access_token' => self::TOKEN])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_lecturer_creates_renames_and_archives_topics(): void
    {
        $this->seed();
        $course = Course::where('code', 'WT101')->firstOrFail();
        Sanctum::actingAs(User::where('email', 'dr.sarah.chen@learnloop.test')->firstOrFail());

        $topic = $this->postJson("/api/courses/{$course->id}/topics", [
            'name' => 'Accessibility', 'description' => 'ARIA and alt text', 'learning_outcomes' => ['Write alt text'],
        ])->assertCreated()->assertJson(['name' => 'Accessibility', 'status' => 'active', 'active_questions' => 0])->json();

        $this->postJson("/api/courses/{$course->id}/topics", ['name' => 'accessibility'])->assertUnprocessable();
        $this->postJson("/api/courses/{$course->id}/topics", ['name' => ''])->assertUnprocessable();
        $this->patchJson("/api/topics/{$topic['id']}", ['learning_outcomes' => 'not a list'])->assertUnprocessable();
        $this->patchJson("/api/topics/{$topic['id']}", ['name' => 'Web accessibility'])->assertOk()->assertJson(['name' => 'Web accessibility']);
        $this->patchJson("/api/topics/{$topic['id']}", ['status' => 'archived'])->assertOk()->assertJson(['status' => 'archived']);

        Sanctum::actingAs(User::where('email', 'alex.morgan@student.learnloop.test')->firstOrFail());
        $names = array_column($this->getJson("/api/me/courses/{$course->id}/topics")->json('data'), 'topic_name');
        $this->assertNotContains('Web accessibility', $names, 'archived topics are hidden from learners');
        $this->postJson("/api/courses/{$course->id}/topics", ['name' => 'Sneaky'])->assertNotFound();
        $this->patchJson("/api/topics/{$topic['id']}", ['status' => 'active'])->assertNotFound();
    }

    public function test_topics_without_evidence_can_be_deleted_others_only_archived(): void
    {
        $this->seed();
        $course = Course::where('code', 'WT101')->firstOrFail();
        Sanctum::actingAs(User::where('email', 'dr.sarah.chen@learnloop.test')->firstOrFail());

        $topic = $this->postJson("/api/courses/{$course->id}/topics", ['name' => 'Typo topic'])->assertCreated()->json('id');
        $this->postJson("/api/topics/{$topic}/questions", [
            'question_type' => 'mcq', 'question_text' => 'Unused?', 'difficulty' => 'easy', 'max_marks' => 1,
            'answer_data' => ['options' => [['id' => 'a', 'text' => 'Yes'], ['id' => 'b', 'text' => 'No']], 'correct_option_ids' => ['a']],
        ])->assertCreated();

        $this->deleteJson("/api/topics/{$topic}")->assertNoContent();
        $this->assertDatabaseMissing('topics', ['id' => $topic]);
        $this->assertDatabaseMissing('questions', ['question_text' => 'Unused?']);

        $withEvidence = $course->topics()->orderBy('id')->firstOrFail();
        $this->deleteJson("/api/topics/{$withEvidence->id}")->assertStatus(409)
            ->assertJsonFragment(['message' => 'Students already have results for this topic, so it can only be archived. Archiving hides it and keeps their history.']);
        $this->assertDatabaseHas('topics', ['id' => $withEvidence->id]);

        Sanctum::actingAs(User::where('email', 'jamie.lee@student.learnloop.test')->firstOrFail());
        $this->deleteJson("/api/topics/{$withEvidence->id}")->assertNotFound();
    }

    public function test_link_course_command(): void
    {
        $this->artisan('learnloop:link-course', ['canvas_course_id' => '55501', '--name' => 'Web Technologies', '--code' => 'CS341'])
            ->assertSuccessful();
        $this->assertSame('55501', CourseLink::first()->canvas_course_id);
        $this->assertSame('CS341', CourseLink::first()->course->code);

        $this->artisan('learnloop:link-course', ['canvas_course_id' => '55501', '--name' => 'Again'])->assertFailed();
        $this->artisan('learnloop:link-course', ['canvas_course_id' => 'abc', '--name' => 'Bad'])->assertFailed();
        $this->artisan('learnloop:link-course', ['canvas_course_id' => '55502'])->assertFailed();
    }
}
