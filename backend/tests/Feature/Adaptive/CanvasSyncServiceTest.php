<?php

namespace Tests\Feature\Adaptive;

use App\Models\CanvasConnection;
use App\Models\CanvasItemMapping;
use App\Models\Course;
use App\Models\CourseLink;
use App\Models\ExternalResult;
use App\Models\SyncRun;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\Canvas\CanvasGateway;
use App\Services\Adaptive\Canvas\CanvasNotConnected;
use App\Services\Adaptive\Canvas\CanvasSyncService;
use App\Services\Adaptive\Canvas\FixtureCanvasGateway;
use App\Services\Adaptive\Evidence\EvidenceAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** v0.8 §3.1–§3.6: Canvas sync through the fixture adapter. */
class CanvasSyncServiceTest extends TestCase
{
    use BuildsAdaptiveData;
    use RefreshDatabase;

    private string $dir;
    private Course $course;
    private Topic $lists;
    private Topic $dicts;
    private User $learner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/canvas-fixture-'.uniqid();
        mkdir($this->dir);

        $this->course = $this->course();
        CourseLink::create(['course_id' => $this->course->id, 'canvas_course_id' => '900']);
        $this->lists = $this->topic($this->course, 'Lists');
        $this->dicts = $this->topic($this->course, 'Dictionaries');
        $this->learner = $this->learner();
        CanvasConnection::create([
            'user_id' => $this->learner->id, 'canvas_user_id' => '77', 'adapter' => 'fixture', 'connected_at' => now(),
        ]);

        $this->map('A1', '', $this->lists, 'easy');
        $this->map('A2', 'c1', $this->lists, 'medium');
        $this->map('A2', 'c2', $this->dicts, 'hard');

        $this->writeFixture([
            ['assignment_id' => 'A1', 'score' => 7, 'posted_at' => '2026-11-10T10:00:00Z'],
            ['assignment_id' => 'A2', 'score' => 13, 'posted_at' => '2026-11-12T10:00:00Z',
                'rubric_assessment' => ['c1' => ['points' => 8], 'c2' => ['points' => 5]]],
        ]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*'));
        rmdir($this->dir);
        parent::tearDown();
    }

    private function map(string $assignment, string $criterion, Topic $topic, string $difficulty): CanvasItemMapping
    {
        return CanvasItemMapping::create([
            'course_id' => $this->course->id, 'canvas_assignment_id' => $assignment,
            'canvas_criterion_id' => $criterion, 'topic_id' => $topic->id, 'difficulty' => $difficulty,
        ]);
    }

    /** @param list<array<string, mixed>> $submissions */
    private function writeFixture(array $submissions, string $role = 'student'): void
    {
        $data = [
            'course' => ['id' => '900', 'name' => 'Test', 'course_code' => 'T1'],
            'enrollments' => [['user_id' => '77', 'role' => $role]],
            'assignments' => [
                ['id' => 'A1', 'name' => 'Quiz', 'points_possible' => 10, 'rubric' => []],
                ['id' => 'A2', 'name' => 'Assignment', 'points_possible' => 20, 'rubric' => [
                    ['id' => 'c1', 'description' => 'Lists', 'points' => 10],
                    ['id' => 'c2', 'description' => 'Dicts', 'points' => 10],
                ]],
                ['id' => 'A3', 'name' => 'Unmapped', 'points_possible' => 5, 'rubric' => []],
            ],
            'submissions' => array_map(fn ($s) => $s + [
                'user_id' => '77', 'submitted_at' => '2026-11-09T10:00:00Z', 'graded_at' => '2026-11-10T10:00:00Z',
            ], $submissions),
        ];
        file_put_contents($this->dir.'/course-900.json', json_encode($data));
        $this->app->instance(CanvasGateway::class, new FixtureCanvasGateway($this->dir));
    }

    private function sync(): array
    {
        return app(CanvasSyncService::class)->syncLearnerCourse($this->learner, $this->course)->toArray();
    }

    private function evidence(Topic $topic): array
    {
        return array_map(
            fn ($i) => [$i->evidenceKey, $i->earned, $i->max, $i->difficulty->value],
            (new EvidenceAssembler())->forLearnerCourse($this->learner->id, $this->course->id)->forTopic($topic->id),
        );
    }

    public function test_whole_item_and_rubric_criteria_become_evidence(): void
    {
        $this->assertSame(['created' => 2, 'unchanged' => 0, 'skipped' => 0, 'flagged' => []], $this->sync());

        $this->assertSame([
            ['c:900:A1', 700, 1000, 'easy'],
            ['c:900:A2:c1', 800, 1000, 'medium'],
        ], $this->evidence($this->lists));
        $this->assertSame([['c:900:A2:c2', 500, 1000, 'hard']], $this->evidence($this->dicts));
    }

    public function test_syncing_again_without_changes_creates_nothing(): void
    {
        $this->sync();

        $this->assertSame(['created' => 0, 'unchanged' => 2, 'skipped' => 0, 'flagged' => []], $this->sync());
        $this->assertSame(2, ExternalResult::count());
    }

    public function test_canvas_regrade_creates_a_new_version_that_counts(): void
    {
        $this->sync();
        $this->writeFixture([
            ['assignment_id' => 'A1', 'score' => 9, 'posted_at' => '2026-11-10T10:00:00Z'],
            ['assignment_id' => 'A2', 'score' => 13, 'posted_at' => '2026-11-12T10:00:00Z',
                'rubric_assessment' => ['c1' => ['points' => 8], 'c2' => ['points' => 5]]],
        ]);

        $this->assertSame(1, $this->sync()['created']);
        $this->assertSame([1, 2], ExternalResult::where('external_item_ref', 'A1')->orderBy('version')->pluck('version')->all());
        $this->assertSame(['c:900:A1', 900, 1000, 'easy'], $this->evidence($this->lists)[0]);
    }

    public function test_mapping_change_rederives_evidence_as_a_new_version(): void
    {
        // D13 (pending, default): re-derive with the new topic and difficulty.
        $this->sync();
        CanvasItemMapping::where('canvas_assignment_id', 'A1')->update(['topic_id' => $this->dicts->id, 'difficulty' => 'hard']);

        $this->assertSame(1, $this->sync()['created']);
        $this->assertSame([['c:900:A2:c1', 800, 1000, 'medium']], $this->evidence($this->lists));
        $this->assertContains(['c:900:A1', 700, 1000, 'hard'], $this->evidence($this->dicts));
    }

    public function test_unposted_grade_is_pending_not_evidence(): void
    {
        $this->writeFixture([['assignment_id' => 'A1', 'score' => 7, 'posted_at' => null]]);
        $this->sync();

        $evidence = (new EvidenceAssembler())->forLearnerCourse($this->learner->id, $this->course->id);
        $this->assertSame([], $evidence->forTopic($this->lists->id));
        $this->assertSame(1, $evidence->pendingForTopic($this->lists->id));
    }

    public function test_unmapped_and_missing_work_are_skipped(): void
    {
        $this->writeFixture([
            ['assignment_id' => 'A3', 'score' => 5, 'posted_at' => '2026-11-10T10:00:00Z'],
            ['assignment_id' => 'A1', 'score' => null, 'posted_at' => null],
        ]);

        $this->assertSame(['created' => 0, 'unchanged' => 0, 'skipped' => 2, 'flagged' => []], $this->sync());
        $this->assertSame(0, ExternalResult::count());
    }

    public function test_criteria_mapped_work_without_rubric_assessment_is_flagged(): void
    {
        $this->writeFixture([['assignment_id' => 'A2', 'score' => 15, 'posted_at' => '2026-11-12T10:00:00Z']]);

        $this->assertSame(['A2'], $this->sync()['flagged']);
        $this->assertSame(0, ExternalResult::count());
    }

    public function test_excused_work_is_stored_but_never_counts(): void
    {
        $this->writeFixture([['assignment_id' => 'A1', 'score' => null, 'excused' => true, 'posted_at' => '2026-11-10T10:00:00Z']]);

        $this->assertSame(1, $this->sync()['created']);
        $this->assertSame([], $this->evidence($this->lists));
    }

    public function test_not_connected_or_unlinked_is_refused(): void
    {
        CanvasConnection::query()->update(['revoked_at' => now()]);

        try {
            $this->sync();
            $this->fail('Expected CanvasNotConnected');
        } catch (CanvasNotConnected $e) {
            $this->assertSame(409, $e->status());
        }

        CanvasConnection::query()->update(['revoked_at' => null]);
        CourseLink::query()->delete();
        $this->expectException(CanvasNotConnected::class);
        app(CanvasSyncService::class)->syncLearnerCourse($this->learner, $this->course->fresh());
    }

    public function test_a_teacher_cannot_sync_as_a_learner_and_the_failure_is_recorded(): void
    {
        $this->writeFixture([['assignment_id' => 'A1', 'score' => 7, 'posted_at' => '2026-11-10T10:00:00Z']], 'teacher');

        try {
            $this->sync();
            $this->fail('Expected CanvasNotConnected');
        } catch (CanvasNotConnected) {
            $run = SyncRun::sole();
            $this->assertSame('failed', $run->status);
            $this->assertSame(0, ExternalResult::count());
        }
    }

    public function test_needs_sync_after_fifteen_minutes(): void
    {
        $service = app(CanvasSyncService::class);
        $this->assertTrue($service->needsSync($this->learner, $this->course));

        $this->sync();
        $this->assertFalse($service->needsSync($this->learner, $this->course, now()->addMinutes(10)));
        $this->assertTrue($service->needsSync($this->learner, $this->course, now()->addMinutes(16)));
    }

    public function test_tokens_are_encrypted_at_rest_and_hidden(): void
    {
        $connection = CanvasConnection::first();
        $connection->update(['access_token' => 'secret-token-123']);

        $raw = DB::table('canvas_connections')->value('access_token');
        $this->assertNotSame('secret-token-123', $raw);
        $this->assertSame('secret-token-123', $connection->fresh()->access_token);
        $this->assertArrayNotHasKey('access_token', $connection->fresh()->toArray());
    }
}
