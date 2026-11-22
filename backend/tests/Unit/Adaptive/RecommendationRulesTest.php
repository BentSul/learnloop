<?php

namespace Tests\Unit\Adaptive;

use App\Enums\Adaptive\Difficulty;
use App\Enums\Adaptive\Priority;
use App\Services\Adaptive\Calculation\TopicAssessor;
use App\Services\Adaptive\Recommendations\RecommendationRules;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Spec §10.2–§10.4, §10.6 and §16.7: every cell of the priority table that applies. */
class RecommendationRulesTest extends TestCase
{
    use BuildsEvidence;

    private function desired(array $items, bool $help = false, bool $mastery = false, string $now = '2026-11-21T12:00:00Z')
    {
        $assessment = (new TopicAssessor())->assess($items, new DateTimeImmutable($now));

        return (new RecommendationRules())->desired(7, $assessment, $help, $mastery);
    }

    public function test_needs_help_is_high(): void
    {
        $d = $this->desired([$this->item(2, 10)]);

        $this->assertSame(Priority::High, $d->priority);
        $this->assertSame('needs_help', $d->primaryReason);
        $this->assertSame('7|needs_help|needs_help', $d->signature);
    }

    public function test_getting_there_is_medium_and_help_raises_it_to_high(): void
    {
        $this->assertSame(Priority::Medium, $this->desired([$this->item(6, 10)])->priority);

        $d = $this->desired([$this->item(6, 10)], help: true);
        $this->assertSame(Priority::High, $d->priority);
        $this->assertSame('help_requested', $d->primaryReason);
        $this->assertSame(['getting_there', 'help_requested'], $d->reasonCodes);
    }

    public function test_strong_unconfirmed_is_low_and_medium_with_help(): void
    {
        $this->assertSame(Priority::Low, $this->desired([$this->item(8, 9)])->priority);
        $this->assertSame(Priority::Medium, $this->desired([$this->item(8, 9)], help: true)->priority);
    }

    public function test_strong_with_nothing_else_has_no_recommendation(): void
    {
        // D3 default.
        $this->assertNull($this->desired([$this->item(9, 10, Difficulty::Hard)]));
    }

    public function test_strong_with_a_mastery_goal_or_help_request(): void
    {
        $this->assertSame(Priority::Low, $this->desired([$this->item(9, 10)], mastery: true)->priority);
        $this->assertSame(Priority::Medium, $this->desired([$this->item(9, 10)], help: true)->priority);
    }

    public function test_strong_with_outdated_evidence_is_optional(): void
    {
        $d = $this->desired([$this->item(9, 10, assessedAt: '2026-01-01T10:00:00Z')]);

        $this->assertSame(Priority::Optional, $d->priority);
        $this->assertSame('evidence_outdated', $d->primaryReason);
    }

    public function test_not_assessed_only_with_goal_or_help(): void
    {
        $this->assertNull($this->desired([]));
        $this->assertSame(Priority::Low, $this->desired([], mastery: true)->priority);
        $this->assertSame(Priority::Medium, $this->desired([], help: true)->priority);
        $this->assertSame('7|not_enough_evidence|help_requested', $this->desired([], help: true)->signature);
    }

    public function test_needs_help_with_a_qualifying_conflict_is_one_high_recommendation_with_both_reasons(): void
    {
        $d = $this->desired([$this->item(1, 10, group: 'learnloop'), $this->item(5, 5, group: 'canvas')]);

        $this->assertSame(Priority::High, $d->priority);
        $this->assertSame('needs_help', $d->primaryReason);
        $this->assertSame(['needs_help', 'conflict_sufficient'], $d->reasonCodes);
    }

    public function test_small_source_conflict_on_strong_is_medium(): void
    {
        // T1: 10/10 vs 0/1 → conflict but one group < 5 marks.
        $d = $this->desired([$this->item(10, 10, group: 'learnloop'), $this->item(0, 1, group: 'canvas')]);

        $this->assertSame(Priority::Medium, $d->priority);
        $this->assertSame(['conflict_limited'], $d->reasonCodes);
    }

    public function test_signature_ignores_percentage_changes_within_a_status(): void
    {
        $this->assertSame(
            $this->desired([$this->item(1, 10)])->signature,
            $this->desired([$this->item(4, 10)])->signature,
        );
    }
}
