<?php

namespace Tests\Feature\Adaptive;

use App\Models\Course;
use App\Models\ExternalResultItem;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\Canvas\CanvasSyncService;
use App\Services\Adaptive\Evidence\EvidenceAssembler;
use App\Services\Adaptive\Lecturer\CanvasMappingService;
use App\Services\Adaptive\Lecturer\LecturerException;
use App\Services\Adaptive\TopicPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Klenam AI quizzes publish scores to Canvas; lecturers mark those items as Klenam (v0.8 D1-R). */
class KlenamViaCanvasTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private User $lecturer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->course = Course::where('code', 'WT101')->firstOrFail();
        $this->lecturer = User::where('email', 'dr.sarah.chen@learnloop.test')->firstOrFail();
    }

    private function jamie(): User
    {
        return User::where('email', 'jamie.lee@student.learnloop.test')->firstOrFail();
    }

    public function test_the_demo_klenam_quiz_counts_as_klenam_evidence(): void
    {
        $sam = User::where('email', 'sam.patel@student.learnloop.test')->firstOrFail();
        $js = Topic::where('course_id', $this->course->id)->where('name', 'like', 'JavaScript%')->firstOrFail();

        $groups = collect((new EvidenceAssembler())->forLearnerCourse($sam->id, $this->course->id)->forTopic($js->id))
            ->map(fn ($item) => $item->sourceGroup)->unique()->values()->all();

        $this->assertContains('klenam', $groups);
        $this->assertNotContains('canvas', $groups, 'the Klenam quiz is the only Canvas item mapped to JavaScript');
    }

    public function test_mapping_screen_shows_and_changes_the_source(): void
    {
        $service = app(CanvasMappingService::class);
        $quiz = collect($service->items($this->lecturer, $this->course))->firstWhere('canvas_assignment_id', '2002');
        $this->assertSame('klenam', $quiz['mapping']['source_group']);
        $this->assertSame(['external_tool'], $quiz['submission_types']);

        $topicId = $quiz['mapping']['topic_id'];
        $service->save($this->lecturer, $this->course, [
            ['canvas_assignment_id' => '2002', 'canvas_criterion_id' => '', 'topic_id' => $topicId, 'difficulty' => 'medium', 'source_group' => 'canvas'],
        ]);

        // The change re-derives evidence on the next sync, now as Canvas.
        app(CanvasSyncService::class)->syncLearnerCourse($this->jamie(), $this->course);
        $latest = ExternalResultItem::where('evidence_key', 'like', 'c:1001:2002%')->latest('id')->firstOrFail();
        $this->assertSame('canvas', $latest->source_group);

        // Leaving source_group out keeps the current value.
        $service->save($this->lecturer, $this->course, [
            ['canvas_assignment_id' => '2002', 'canvas_criterion_id' => '', 'topic_id' => $topicId, 'difficulty' => 'hard'],
        ]);
        $quiz = collect($service->items($this->lecturer, $this->course))->firstWhere('canvas_assignment_id', '2002');
        $this->assertSame('canvas', $quiz['mapping']['source_group']);
    }

    public function test_invalid_and_mixed_sources_are_rejected(): void
    {
        $service = app(CanvasMappingService::class);
        $topics = Topic::where('course_id', $this->course->id)->orderBy('id')->pluck('id');

        try {
            $service->save($this->lecturer, $this->course, [
                ['canvas_assignment_id' => '2002', 'canvas_criterion_id' => '', 'topic_id' => $topics[0], 'source_group' => 'smartmark'],
            ]);
            $this->fail('unknown source accepted');
        } catch (LecturerException $e) {
            $this->assertStringContainsString('source must be canvas or klenam', implode(' ', $e->errors));
        }

        try {
            $service->save($this->lecturer, $this->course, [
                ['canvas_assignment_id' => '2001', 'canvas_criterion_id' => 'crit_html', 'topic_id' => $topics[0], 'source_group' => 'klenam'],
            ]);
            $this->fail('mixed sources for one item accepted');
        } catch (LecturerException $e) {
            $this->assertStringContainsString('same source', implode(' ', $e->errors));
        }
    }

    public function test_conflicts_name_klenam(): void
    {
        $js = Topic::where('course_id', $this->course->id)->where('name', 'like', 'JavaScript%')->firstOrFail();
        // Jamie's LearnLoop practice and Klenam quiz on JavaScript: any conflict pair must use the Klenam label.
        $result = collect(app(TopicPerformanceService::class)->forLearnerCourse($this->jamie(), $this->course))
            ->firstWhere('topic_id', $js->id);

        foreach ($result['conflict']['pairs'] ?? [] as $pair) {
            $this->assertNotContains('canvas', $pair['groups']);
            $this->assertContains('Klenam AI', [$pair['a']['label'], $pair['b']['label']]);
        }
        $this->assertTrue(true);
    }
}
