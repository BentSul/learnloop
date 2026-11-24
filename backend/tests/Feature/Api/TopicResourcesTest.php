<?php

namespace Tests\Feature\Api;

use App\Models\Course;
use App\Models\Topic;
use App\Models\TopicResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Study resources per topic: lecturers curate, students see published ones. */
class TopicResourcesTest extends TestCase
{
    use RefreshDatabase;

    private Topic $topic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->topic = Topic::where('name', 'HTML basics')->firstOrFail();
    }

    private function as(string $email): void
    {
        Sanctum::actingAs(User::where('email', $email)->firstOrFail());
    }

    public function test_demo_topics_come_with_resources(): void
    {
        $this->assertSame(2, $this->topic->publishedResources()->count());
        $this->assertSame(11, TopicResource::count());
        foreach (TopicResource::all() as $r) {
            $this->assertStringStartsWith('https://', $r->url);
        }
    }

    public function test_lecturer_adds_edits_reorders_and_deletes_resources(): void
    {
        $this->as('dr.sarah.chen@learnloop.test');

        $created = $this->postJson("/api/topics/{$this->topic->id}/resources", [
            'kind' => 'practice', 'title' => 'HTML exercises', 'url' => 'https://www.w3schools.com/html/html_exercises.asp', 'note' => 'Short drills',
        ])->assertCreated()->assertJson(['kind' => 'practice', 'status' => 'published', 'position' => 3])->json();

        $this->patchJson("/api/resources/{$created['id']}", ['title' => 'HTML drills', 'position' => 0])
            ->assertOk()->assertJson(['title' => 'HTML drills', 'position' => 0]);
        $this->assertSame('HTML drills', $this->getJson("/api/topics/{$this->topic->id}/resources")->json('data.0.title'));

        $this->deleteJson("/api/resources/{$created['id']}")->assertNoContent();
        $this->assertDatabaseMissing('topic_resources', ['id' => $created['id']]);
    }

    public function test_unsafe_or_invalid_links_are_rejected(): void
    {
        $this->as('dr.sarah.chen@learnloop.test');
        foreach (['javascript:alert(1)', 'data:text/html,hi', '/relative/path', 'ftp://files.example.com/x', 'https://', 'not a url', ''] as $url) {
            $this->postJson("/api/topics/{$this->topic->id}/resources", ['kind' => 'reading', 'title' => 'X', 'url' => $url])
                ->assertUnprocessable();
        }
        $this->postJson("/api/topics/{$this->topic->id}/resources", ['kind' => 'podcast', 'title' => 'X', 'url' => 'https://example.com'])
            ->assertUnprocessable();
        $first = TopicResource::where('topic_id', $this->topic->id)->first();
        $this->patchJson("/api/resources/{$first->id}", ['status' => 'suggested'])->assertUnprocessable();
    }

    public function test_only_the_courses_lecturer_can_manage_resources(): void
    {
        $resource = TopicResource::where('topic_id', $this->topic->id)->first();

        $this->as('prof.james.okafor@learnloop.test');
        $this->getJson("/api/topics/{$this->topic->id}/resources")->assertNotFound();
        $this->patchJson("/api/resources/{$resource->id}", ['title' => 'Hacked'])->assertNotFound();

        $this->as('jamie.lee@student.learnloop.test');
        $this->postJson("/api/topics/{$this->topic->id}/resources", ['kind' => 'video', 'title' => 'X', 'url' => 'https://example.com'])->assertNotFound();
        $this->deleteJson("/api/resources/{$resource->id}")->assertNotFound();
    }

    public function test_students_see_published_resources_on_topics_and_recommendations(): void
    {
        TopicResource::create(['topic_id' => $this->topic->id, 'kind' => 'video', 'title' => 'Waiting for review',
            'url' => 'https://www.youtube.com/watch?v=abcdefghijk', 'status' => 'suggested', 'source' => 'youtube', 'external_id' => 'abcdefghijk']);
        $this->as('jamie.lee@student.learnloop.test');
        $course = Course::where('code', 'WT101')->firstOrFail();

        $html = collect($this->getJson("/api/me/courses/{$course->id}/topics")->json('data'))->firstWhere('topic_name', 'HTML basics');
        $this->assertSame(['video', 'reading'], array_column($html['resources'], 'kind'));
        $this->assertNotContains('Waiting for review', array_column($html['resources'], 'title'));
        $this->assertStringStartsWith('https://www.youtube.com/results?search_query=HTML%20basics', $html['find_videos_url']);
        $this->assertArrayNotHasKey('status', $html['resources'][0], 'lecturer-only fields stay hidden');

        $recs = $this->getJson('/api/me/recommendations')->assertOk()->json('data');
        $this->assertNotEmpty($recs);
        foreach ($recs as $rec) {
            $this->assertArrayHasKey('resources', $rec);
            $this->assertLessThanOrEqual(3, count($rec['resources']));
            $this->assertStringStartsWith('https://www.youtube.com/results?search_query=', $rec['find_videos_url']);
        }
    }
}
