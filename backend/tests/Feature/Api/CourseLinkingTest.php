<?php

namespace Tests\Feature\Api;

use App\Models\CanvasConnection;
use App\Models\Course;
use App\Models\CourseLink;
use App\Models\Question;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Lecturers add their Canvas courses from the website; learners see why items were skipped. */
class CourseLinkingTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://canvas.example.edu';

    private function realLecturer(string $canvasId = '7001'): User
    {
        config(['adaptive.canvas.adapter' => 'hybrid', 'adaptive.canvas.base_url' => self::BASE]);
        $user = User::factory()->create();
        CanvasConnection::create(['user_id' => $user->id, 'canvas_user_id' => $canvasId, 'adapter' => 'token',
            'access_token' => str_repeat('k', 40), 'connected_at' => now()]);

        return $user;
    }

    public function test_lecturer_lists_and_adds_courses_they_teach(): void
    {
        $lecturer = $this->realLecturer();
        CourseLink::create(['course_id' => Course::create(['name' => 'Old', 'code' => 'OLD1'])->id, 'canvas_course_id' => '300']);
        Http::fake([
            self::BASE.'/api/v1/courses?*' => Http::response([
                ['id' => 300, 'name' => 'Already added', 'course_code' => 'OLD1'],
                ['id' => 301, 'name' => 'Web Technologies', 'course_code' => 'CS341'],
                ['id' => 302, 'name' => 'Old term', 'access_restricted_by_date' => true],
            ]),
            self::BASE.'/api/v1/courses/301/enrollments*' => Http::response([['type' => 'TeacherEnrollment']]),
            self::BASE.'/api/v1/courses/301' => Http::response(['id' => 301, 'name' => 'Web Technologies', 'course_code' => 'CS341']),
            self::BASE.'/api/v1/courses/399/enrollments*' => Http::response([['type' => 'StudentEnrollment']]),
        ]);
        Sanctum::actingAs($lecturer);

        $this->getJson('/api/canvas/courses')->assertOk()->assertExactJson(['data' => [
            ['canvas_course_id' => '300', 'name' => 'Already added', 'course_code' => 'OLD1', 'learnloop_course_id' => CourseLink::first()->course_id],
            ['canvas_course_id' => '301', 'name' => 'Web Technologies', 'course_code' => 'CS341', 'learnloop_course_id' => null],
        ]]);
        Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'enrollment_type=teacher'));

        $course = $this->postJson('/api/canvas/courses', ['canvas_course_id' => '301'])->assertCreated()
            ->assertJson(['name' => 'Web Technologies', 'code' => 'CS341', 'canvas_course_id' => '301'])->json();

        // The new course is immediately the lecturer's: their Lecturer Tools work for it.
        $this->getJson("/api/courses/{$course['id']}/topics")->assertOk();
        $this->getJson('/api/me/courses')->assertJsonFragment(['id' => $course['id']]);

        $this->postJson('/api/canvas/courses', ['canvas_course_id' => '301'])->assertStatus(409);
        $this->postJson('/api/canvas/courses', ['canvas_course_id' => '399'])->assertUnprocessable()
            ->assertJsonFragment(['message' => 'You can only add Canvas courses you teach.']);
        $this->postJson('/api/canvas/courses', ['canvas_course_id' => 'abc'])->assertUnprocessable();
    }

    public function test_students_without_canvas_cannot_add_courses_and_admins_can_by_id(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/canvas/courses')->assertStatus(409);
        $this->postJson('/api/canvas/courses', ['canvas_course_id' => '500'])->assertStatus(409);

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        Sanctum::actingAs($admin);
        $this->postJson('/api/canvas/courses', ['canvas_course_id' => '500'])->assertUnprocessable(); // no name, no Canvas
        $this->postJson('/api/canvas/courses', ['canvas_course_id' => '500', 'name' => 'Data Structures', 'code' => 'CS213'])
            ->assertCreated()->assertJson(['name' => 'Data Structures', 'code' => 'CS213']);
    }

    public function test_a_taken_course_code_gets_the_canvas_id_added(): void
    {
        Course::create(['name' => 'Demo', 'code' => 'CS341']);
        $this->artisan('learnloop:link-course', ['canvas_course_id' => '48213', '--name' => 'Real', '--code' => 'CS341'])->assertSuccessful();

        $this->assertSame('CS341-48213', Course::where('name', 'Real')->value('code'));
    }

    public function test_fixture_lecturers_see_their_demo_courses(): void
    {
        $this->seed();
        Sanctum::actingAs(User::where('email', 'dr.sarah.chen@learnloop.test')->firstOrFail());

        $courses = $this->getJson('/api/canvas/courses')->assertOk()->json('data');
        $this->assertSame(['1001'], array_column($courses, 'canvas_course_id'));
        $this->assertNotNull($courses[0]['learnloop_course_id']);
    }

    public function test_topics_say_whether_a_revision_quiz_can_be_made(): void
    {
        $this->seed();
        $course = Course::where('code', 'WT101')->firstOrFail();
        $empty = Topic::create(['course_id' => $course->id, 'name' => 'No questions yet']);
        Sanctum::actingAs(User::where('email', 'sam.patel@student.learnloop.test')->firstOrFail());

        $topics = collect($this->getJson("/api/me/courses/{$course->id}/topics")->json('data'))->keyBy('topic_name');
        $this->assertFalse($topics['No questions yet']['revision_quiz_available']);
        $this->assertTrue($topics->first()['revision_quiz_available']);

        // Matches what the revision quiz service actually allows.
        $this->postJson("/api/me/topics/{$empty->id}/revision-quiz")->assertUnprocessable();
        foreach (range(1, 3) as $i) {
            Question::create(['topic_id' => $empty->id, 'question_type' => 'true_false', 'question_text' => "Q{$i}", 'max_marks' => 1,
                'answer_data' => ['options' => [['id' => 'a', 'text' => 'True'], ['id' => 'b', 'text' => 'False']], 'correct_option_ids' => ['a']]]);
        }
        $topics = collect($this->getJson("/api/me/courses/{$course->id}/topics")->json('data'))->keyBy('topic_name');
        $this->assertTrue($topics['No questions yet']['revision_quiz_available']);
        $this->postJson("/api/me/topics/{$empty->id}/revision-quiz")->assertCreated();
    }
}
