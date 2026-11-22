<?php

namespace Tests\Unit\Adaptive;

use App\Enums\Adaptive\Confidence;
use App\Enums\Adaptive\Difficulty;
use App\Enums\Adaptive\PerformanceStatus;
use App\Services\Adaptive\Calculation\ConflictDetector;
use App\Services\Adaptive\Calculation\TopicAssessor;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Spec §16.6 conflicts (T1, pairwise groups). */
class ConflictDetectorTest extends TestCase
{
    use BuildsEvidence;

    private ConflictDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new ConflictDetector();
    }

    public function test_difference_of_29_point_9_points_is_not_a_conflict(): void
    {
        // 79.9% vs 50%
        $result = $this->detector->detect([
            $this->item('7.99', 10, group: 'learnloop'),
            $this->item(5, 10, group: 'canvas'),
        ]);

        $this->assertFalse($result->conflictDetected());
    }

    public function test_exactly_30_points_is_a_conflict(): void
    {
        $result = $this->detector->detect([
            $this->item(8, 10, group: 'learnloop'),
            $this->item(5, 10, group: 'canvas'),
        ]);

        $this->assertTrue($result->conflictDetected());
        $this->assertSame(30.0, $result->pairs[0]->displayDifference());
    }

    public function test_every_pair_of_three_groups_is_compared(): void
    {
        $result = $this->detector->detect([
            $this->item(9, 10, group: 'learnloop'),
            $this->item(2, 5, group: 'klenam'),
            $this->item(13, 20, group: 'canvas'),
        ]);

        $this->assertCount(3, $result->pairs);
    }

    #[DataProvider('eachPair')]
    public function test_each_pair_detects_a_conflict_at_30_points(string $groupA, string $groupB): void
    {
        $result = $this->detector->detect([
            $this->item(8, 10, group: $groupA),
            $this->item(5, 10, group: $groupB),
        ]);

        $this->assertTrue($result->conflictDetected());
    }

    public static function eachPair(): array
    {
        return [
            'learnloop vs canvas' => ['learnloop', 'canvas'],
            'learnloop vs klenam' => ['learnloop', 'klenam'],
            'canvas vs klenam' => ['canvas', 'klenam'],
        ];
    }

    public function test_spec_example_one_conflicting_pair_reduces_confidence(): void
    {
        // v0.7 §8.3 example, with v0.8 group names: 9/10 (90%), 2/5 (40%), 13/20 (65%).
        $result = $this->detector->detect([
            $this->item(9, 10, group: 'learnloop'),
            $this->item(2, 5, group: 'klenam'),
            $this->item(13, 20, group: 'canvas'),
        ]);

        $conflicting = $result->conflictingPairs();
        $this->assertCount(1, $conflicting);
        $this->assertSame(['klenam', 'learnloop'], [$conflicting[0]->groupA, $conflicting[0]->groupB]);
        $this->assertSame(50.0, $conflicting[0]->displayDifference());
        $this->assertTrue($result->reducesConfidence());
    }

    public function test_small_source_conflict_is_flagged_but_does_not_reduce_confidence(): void
    {
        // T1: 8/10 (80%) vs 0/1 (0%) — conflict, but one group has < 5 marks.
        $result = $this->detector->detect([
            $this->item(8, 10, group: 'learnloop'),
            $this->item(0, 1, group: 'canvas'),
        ]);

        $this->assertTrue($result->conflictDetected());
        $this->assertFalse($result->pairs[0]->bothMeetMinimum);
        $this->assertFalse($result->reducesConfidence());
    }

    public function test_exactly_five_marks_meets_the_minimum(): void
    {
        $result = $this->detector->detect([
            $this->item(5, 5, group: 'learnloop'),
            $this->item(1, 5, group: 'canvas'),
        ]);

        $this->assertTrue($result->reducesConfidence());
    }

    public function test_multiple_qualifying_pairs_reduce_confidence_by_one_level_only(): void
    {
        // learnloop 20/20 Easy+Medium+Hard mix; canvas 2/10; klenam 2/10 → two qualifying pairs.
        $items = [
            $this->item(10, 10, Difficulty::Medium, 'learnloop'),
            $this->item(10, 10, Difficulty::Hard, 'learnloop'),
            $this->item(2, 10, Difficulty::Medium, 'canvas'),
            $this->item(2, 10, Difficulty::Medium, 'klenam'),
        ];

        $assessment = (new TopicAssessor())->assess($items, new DateTimeImmutable('2026-11-21T12:00:00Z'));

        $qualifying = array_filter($assessment->conflicts->pairs, fn ($p) => $p->reducesConfidence);
        $this->assertCount(2, $qualifying);
        // 24/40 = 60% getting there, Broad coverage, Challenging → High, reduced once → Moderate.
        $this->assertSame(PerformanceStatus::GettingThere, $assessment->performance->status);
        $this->assertSame(Confidence::Moderate, $assessment->confidence->confidence);
        $this->assertSame(1, count(array_keys($assessment->confidence->reasons, 'conflict_reduced', true)));
    }

    public function test_combined_performance_stays_mark_weighted_across_groups(): void
    {
        // (9 + 2 + 13) / (10 + 5 + 20) = 24/35 = 68.57% → 68.5%; not the average of 90, 40 and 65.
        $assessment = (new TopicAssessor())->assess([
            $this->item(9, 10, group: 'learnloop'),
            $this->item(2, 5, group: 'klenam'),
            $this->item(13, 20, group: 'canvas'),
        ], new DateTimeImmutable('2026-11-21T12:00:00Z'));

        $this->assertSame(68.5, $assessment->performance->displayPercent);
        $this->assertSame(PerformanceStatus::GettingThere, $assessment->performance->status);
    }

    public function test_a_single_group_has_no_pairs(): void
    {
        $result = $this->detector->detect([$this->item(1, 10), $this->item(10, 10)]);

        $this->assertSame([], $result->pairs);
        $this->assertFalse($result->conflictDetected());
    }
}
