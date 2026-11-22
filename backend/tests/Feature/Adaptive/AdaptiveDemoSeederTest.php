<?php

namespace Tests\Feature\Adaptive;

use App\Enums\Adaptive\Confidence;
use App\Enums\Adaptive\PerformanceStatus;
use App\Models\Question;
use App\Models\SyncRun;
use App\Models\Topic;
use App\Models\User;
use App\Services\Adaptive\Calculation\TopicAssessment;
use App\Services\Adaptive\Calculation\TopicAssessor;
use App\Services\Adaptive\Evidence\EvidenceAssembler;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The demo data shows the behaviours it was designed to show. */
class AdaptiveDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function assess(string $email, string $topicName): array
    {
        $learner = User::where('email', $email)->firstOrFail();
        $topic = Topic::where('name', $topicName)->firstOrFail();
        $evidence = (new EvidenceAssembler())->forLearnerCourse($learner->id, $topic->course_id);

        return [
            (new TopicAssessor())->assess($evidence->forTopic($topic->id), new DateTimeImmutable()),
            $evidence->pendingForTopic($topic->id),
        ];
    }

    public function test_question_bank_and_courses_exist(): void
    {
        $this->assertSame(7, Topic::count());
        $this->assertSame(28, Question::count());
    }

    public function test_alex_is_strong_with_high_confidence(): void
    {
        /** @var TopicAssessment $result */
        [$result] = $this->assess('alex.morgan@student.learnloop.test', 'Lists');

        $this->assertSame(PerformanceStatus::Strong, $result->performance->status);
        $this->assertSame(Confidence::High, $result->confidence->confidence);
    }

    public function test_jamie_needs_help_and_has_a_conflict_and_a_pending_grade(): void
    {
        [$lists] = $this->assess('jamie.lee@student.learnloop.test', 'Lists');
        [$functions] = $this->assess('jamie.lee@student.learnloop.test', 'Functions');
        [, $jsPending] = $this->assess('jamie.lee@student.learnloop.test', 'JavaScript logic');

        $this->assertSame(PerformanceStatus::NeedsHelp, $lists->performance->status);
        $this->assertTrue($functions->conflicts->reducesConfidence());
        $this->assertSame(1, $jsPending);
    }

    public function test_sam_has_strong_unconfirmed_and_no_evidence_topics(): void
    {
        [$css] = $this->assess('sam.patel@student.learnloop.test', 'CSS');
        [$html] = $this->assess('sam.patel@student.learnloop.test', 'HTML basics');

        $this->assertSame(PerformanceStatus::StrongUnconfirmed, $css->performance->status);
        $this->assertSame(100.0, $css->performance->displayPercent);
        $this->assertSame(PerformanceStatus::NotEnoughEvidence, $html->performance->status); // excused
    }

    public function test_sams_unrubric_grade_is_flagged(): void
    {
        $flagged = SyncRun::all()->flatMap(fn ($run) => $run->summary['flagged'] ?? [])->all();

        $this->assertSame(['3003'], $flagged);
    }
}
