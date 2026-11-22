<?php

namespace Tests\Feature\Adaptive;

use App\Models\Course;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\CourseAccess;
use App\Services\Adaptive\TopicPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spec §13.4 response contract and v0.8 D12 course access, using the demo data. */
class TopicPerformanceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function topics(string $email, string $code): array
    {
        $learner = User::where('email', $email)->firstOrFail();
        $course = Course::where('code', $code)->firstOrFail();

        return collect((new TopicPerformanceService())->forLearnerCourse($learner, $course))->keyBy('topic_name')->all();
    }

    public function test_response_has_the_documented_shape(): void
    {
        $lists = $this->topics('alex.morgan@student.learnloop.test', 'PY101')['Lists'];

        $this->assertSame([
            'topic_id', 'topic_name', 'status', 'label', 'earned_marks', 'max_marks', 'performance_display',
            'coverage_band', 'difficulty_class', 'confidence', 'confidence_reasons', 'evidence_age',
            'last_assessed_at', 'pending_count', 'conflict', 'explanation',
        ], array_keys($lists));
        $this->assertSame('strong', $lists['status']);
        $this->assertSame('Strong performance', $lists['label']);
        $this->assertSame(17.0, $lists['earned_marks']);
        $this->assertSame(94.4, $lists['performance_display']);
        $this->assertNull($lists['conflict']);
    }

    public function test_conflict_pairs_show_both_sources(): void
    {
        $functions = $this->topics('jamie.lee@student.learnloop.test', 'PY101')['Functions'];

        $pair = $functions['conflict']['pairs'][0];
        $this->assertTrue($functions['conflict']['confidence_reduced']);
        $this->assertSame('Canvas', $pair['a']['label']);
        $this->assertSame(30.0, $pair['a']['performance_display']);
        $this->assertSame('LearnLoop practice', $pair['b']['label']);
        $this->assertSame(100.0, $pair['b']['performance_display']);
        $this->assertSame(70.0, $pair['difference_display']);
        $this->assertContains(
            ['code' => 'conflict_reduced', 'text' => 'Your results from different sources disagree.'],
            $functions['confidence_reasons'],
        );
    }

    public function test_labels_for_unconfirmed_pending_and_unassessed_topics(): void
    {
        $sam = $this->topics('sam.patel@student.learnloop.test', 'WT101');
        $jamie = $this->topics('jamie.lee@student.learnloop.test', 'WT101');

        $this->assertSame('Strong so far — more evidence needed', $sam['CSS']['label']);
        $this->assertStringContainsString('More assessment evidence is needed', $sam['CSS']['explanation']);
        $this->assertSame('Not assessed', $sam['HTML basics']['label']);
        $this->assertNull($sam['HTML basics']['performance_display']);
        $this->assertSame(1, $jamie['JavaScript logic']['pending_count']);
    }

    public function test_archived_topics_are_hidden(): void
    {
        Topic::where('name', 'Lists')->update(['status' => 'archived']);

        $this->assertArrayNotHasKey('Lists', $this->topics('alex.morgan@student.learnloop.test', 'PY101'));
    }

    public function test_course_access_comes_from_canvas_roles(): void
    {
        $access = app(CourseAccess::class);
        $webTech = Course::where('code', 'WT101')->firstOrFail();
        $python = Course::where('code', 'PY101')->firstOrFail();
        $chen = User::where('email', 'dr.sarah.chen@learnloop.test')->firstOrFail();
        $alex = User::where('email', 'alex.morgan@student.learnloop.test')->firstOrFail();
        $admin = User::where('email', 'admin@learnloop.test')->firstOrFail();
        $stranger = User::factory()->create();

        $this->assertTrue($access->isLecturer($chen, $webTech));
        $this->assertFalse($access->isLecturer($chen, $python));
        $this->assertTrue($access->isStudent($alex, $python));
        $this->assertFalse($access->isLecturer($alex, $python));
        $this->assertTrue($access->isLecturer($admin, $python));
        $this->assertSame(['WT101'], $access->coursesFor($chen)->pluck('code')->all());
        $this->assertSame(['PY101', 'WT101'], $access->coursesFor($alex)->pluck('code')->all());
        $this->assertSame([], $access->coursesFor($stranger)->all());
    }
}
