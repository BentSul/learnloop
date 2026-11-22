<?php

namespace Tests\Feature\Adaptive;

use App\Models\CanvasConnection;
use App\Models\CanvasItemMapping;
use App\Models\CourseLink;
use App\Models\ExternalResult;
use App\Services\Adaptive\Canvas\CanvasGateway;
use App\Services\Adaptive\Canvas\CanvasNotConnected;
use App\Services\Adaptive\Canvas\CanvasSyncService;
use App\Services\Adaptive\Canvas\CanvasUnavailable;
use App\Services\Adaptive\Canvas\TokenCanvasGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** v0.8 §3.1 Tier 2: the real Canvas adapter, against a faked Canvas API. */
class TokenCanvasGatewayTest extends TestCase
{
    use BuildsAdaptiveData;
    use RefreshDatabase;

    private const BASE = 'https://canvas.example.edu';

    private const TOKEN = 'learner-token-0000000000000000';

    protected function setUp(): void
    {
        parent::setUp();
        config(['adaptive.canvas.adapter' => 'token', 'adaptive.canvas.base_url' => self::BASE]);
        $learner = $this->learner();
        CanvasConnection::create([
            'user_id' => $learner->id, 'canvas_user_id' => '77', 'adapter' => 'token',
            'access_token' => self::TOKEN, 'connected_at' => now(),
        ]);
    }

    private function gateway(): TokenCanvasGateway
    {
        return app(CanvasGateway::class);
    }

    private function fakeEnrollment(string $type): void
    {
        Http::fake([self::BASE.'/api/v1/courses/900/enrollments*' => Http::response([['type' => $type, 'user_id' => 77]])]);
    }

    public function test_the_token_adapter_is_selected_by_config(): void
    {
        $this->assertInstanceOf(TokenCanvasGateway::class, $this->gateway());
    }

    public function test_roles_come_from_the_users_own_enrolment_and_are_cached(): void
    {
        $this->fakeEnrollment('StudentEnrollment');

        $this->assertSame('student', $this->gateway()->courseRole('900', '77'));
        $this->assertSame('student', $this->gateway()->courseRole('900', '77'));
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && str_contains(urldecode($r->url()), 'user_id=self') && str_contains(urldecode($r->url()), 'state[]=active'));
    }

    public function test_teacher_ta_and_unknown_users(): void
    {
        $this->fakeEnrollment('TeacherEnrollment');
        $this->assertSame('teacher', $this->gateway()->courseRole('900', '77'));

        $this->assertNull($this->gateway()->courseRole('900', '12345'), 'no LearnLoop connection → no role, no request');
        Http::assertSentCount(1);
    }

    public function test_a_ta_is_not_a_lecturer_unless_configured(): void
    {
        $this->fakeEnrollment('TaEnrollment');
        $this->assertNull($this->gateway()->courseRole('900', '77'));
    }

    public function test_assignments_follow_pagination_on_the_canvas_host_only(): void
    {
        Http::fake([
            self::BASE.'/api/v1/courses/900/assignments?per_page=100' => Http::response(
                [['id' => 1, 'name' => 'Lab 1', 'points_possible' => 10, 'due_at' => null, 'submission_types' => ['online_upload'],
                    'rubric' => [['id' => '_123', 'description' => 'Lists', 'points' => 6], ['id' => '_456', 'description' => 'Dicts', 'points' => 4]]]],
                200, ['Link' => '<'.self::BASE.'/api/v1/courses/900/assignments?page=2&per_page=100>; rel="next"']),
            self::BASE.'/api/v1/courses/900/assignments?page=2&per_page=100' => Http::response(
                [['id' => 2, 'name' => 'Quiz 1', 'points_possible' => 5]],
                200, ['Link' => '<https://evil.example.com/steal?page=3>; rel="next"']),
            '*' => Http::response([], 500),
        ]);

        $assignments = $this->gateway()->assignments('900', '77');

        $this->assertSame(['1', '2'], array_column($assignments, 'id'));
        $this->assertSame([['id' => '_123', 'description' => 'Lists', 'points' => 6], ['id' => '_456', 'description' => 'Dicts', 'points' => 4]], $assignments[0]['rubric']);
        $this->assertSame([], $assignments[1]['rubric']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'evil.example.com'));
    }

    public function test_submissions_are_requested_for_the_learner_with_rubric_assessments(): void
    {
        Http::fake([self::BASE.'/api/v1/courses/900/students/submissions*' => Http::response([[
            'assignment_id' => 1, 'user_id' => 77, 'score' => 8.5, 'excused' => false, 'workflow_state' => 'graded',
            'submitted_at' => '2026-11-10T10:00:00Z', 'graded_at' => '2026-11-11T10:00:00Z', 'posted_at' => '2026-11-11T10:00:00Z',
            'attempt' => 1, 'rubric_assessment' => ['_123' => ['points' => 5, 'rating_id' => 'x', 'comments' => 'good']],
        ]])]);

        $subs = $this->gateway()->submissionsFor('900', '77');

        $this->assertSame('1', $subs[0]['assignment_id']);
        $this->assertSame(8.5, $subs[0]['score']);
        $this->assertSame(['_123' => ['points' => 5]], $subs[0]['rubric_assessment']);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'student_ids[]=77') && str_contains(urldecode($r->url()), 'include[]=rubric_assessment'));
    }

    public function test_canvas_errors(): void
    {
        Http::fake([self::BASE.'/api/v1/courses/900/assignments*' => Http::response(['errors' => []], 401)]);
        $this->expectException(CanvasNotConnected::class);
        $this->gateway()->assignments('900', '77');
    }

    public function test_a_failed_role_lookup_is_not_cached(): void
    {
        Http::fake([self::BASE.'/api/v1/courses/900/enrollments*' => Http::sequence()
            ->push([], 503)
            ->push([['type' => 'StudentEnrollment']])]);

        $this->assertNull($this->gateway()->courseRole('900', '77'));
        $this->assertSame('student', $this->gateway()->courseRole('900', '77'), 'a new request asks Canvas again');
    }

    public function test_server_errors_and_no_access(): void
    {
        Http::fake([
            self::BASE.'/api/v1/courses/900/assignments*' => Http::response([], 503),
            self::BASE.'/api/v1/courses/901/assignments*' => Http::response([], 403),
        ]);

        $this->assertSame([], $this->gateway()->assignments('901', '77'));
        $this->expectException(CanvasUnavailable::class);
        $this->gateway()->assignments('900', '77');
    }

    public function test_missing_base_url_is_explained(): void
    {
        config(['adaptive.canvas.base_url' => null]);
        Http::fake();
        $this->expectException(CanvasUnavailable::class);
        $this->expectExceptionMessage('CANVAS_BASE_URL');
        $this->gateway()->assignments('900', '77');
    }

    public function test_full_sync_through_the_real_adapter(): void
    {
        $course = $this->course();
        CourseLink::create(['course_id' => $course->id, 'canvas_course_id' => '900']);
        $lists = $this->topic($course, 'Lists');
        CanvasItemMapping::create(['course_id' => $course->id, 'canvas_assignment_id' => '1', 'canvas_criterion_id' => '_123',
            'topic_id' => $lists->id, 'difficulty' => 'medium']);

        Http::fake([
            self::BASE.'/api/v1/courses/900/enrollments*' => Http::response([['type' => 'StudentEnrollment']]),
            self::BASE.'/api/v1/courses/900/assignments*' => Http::response([['id' => 1, 'name' => 'Lab 1', 'points_possible' => 10,
                'rubric' => [['id' => '_123', 'description' => 'Lists', 'points' => 6]]]]),
            self::BASE.'/api/v1/courses/900/students/submissions*' => Http::response([['assignment_id' => 1, 'user_id' => 77,
                'score' => 9, 'workflow_state' => 'graded', 'posted_at' => '2026-11-11T10:00:00Z', 'graded_at' => '2026-11-11T10:00:00Z',
                'rubric_assessment' => ['_123' => ['points' => 5]]]]),
        ]);

        $learner = CanvasConnection::where('canvas_user_id', '77')->first()->user;
        $summary = app(CanvasSyncService::class)->syncLearnerCourse($learner, $course);

        $this->assertSame(1, $summary->created);
        $this->assertSame(1, ExternalResult::where('learner_id', $learner->id)->count());
    }
}
