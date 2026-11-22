<?php

namespace Tests\Unit\Adaptive;

use App\Enums\Adaptive\Confidence;
use App\Enums\Adaptive\CoverageBand;
use App\Enums\Adaptive\Difficulty;
use App\Enums\Adaptive\DifficultyClass;
use App\Enums\Adaptive\PerformanceStatus;
use App\Services\Adaptive\Calculation\ConfidenceCalculator;
use App\Services\Adaptive\Calculation\DifficultyClassifier;
use App\Services\Adaptive\Calculation\TopicAssessor;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Spec §16.5 difficulty and confidence. */
class DifficultyAndConfidenceTest extends TestCase
{
    use BuildsEvidence;

    private DifficultyClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new DifficultyClassifier();
    }

    private function assess(array $items)
    {
        return (new TopicAssessor())->assess($items, new DateTimeImmutable('2026-11-21T12:00:00Z'));
    }

    // ---- Positive statuses: earned marks (§7.2) ----

    public function test_medium_hard_earned_below_25_percent_is_basic(): void
    {
        // Earned: 76 easy + 24 medium → 24% Medium/Hard.
        $items = [$this->item(76, 76, Difficulty::Easy), $this->item(24, 24, Difficulty::Medium)];

        $this->assertSame(DifficultyClass::Basic, $this->classifier->classify(PerformanceStatus::Strong, $items));
    }

    public function test_medium_hard_50_percent_with_hard_15_percent_is_challenging(): void
    {
        // Earned: 50 easy, 35 medium, 15 hard → M+H 50%, Hard 15%.
        $items = [
            $this->item(50, 50, Difficulty::Easy),
            $this->item(35, 35, Difficulty::Medium),
            $this->item(15, 15, Difficulty::Hard),
        ];

        $this->assertSame(DifficultyClass::Challenging, $this->classifier->classify(PerformanceStatus::Strong, $items));
    }

    public function test_medium_hard_60_percent_with_hard_10_percent_is_mixed(): void
    {
        $items = [
            $this->item(40, 40, Difficulty::Easy),
            $this->item(50, 50, Difficulty::Medium),
            $this->item(10, 10, Difficulty::Hard),
        ];

        $this->assertSame(DifficultyClass::Mixed, $this->classifier->classify(PerformanceStatus::Strong, $items));
    }

    public function test_success_only_on_medium_and_hard_counts_as_challenging(): void
    {
        // §6.3 of v0.5: success on Easy is not required.
        $items = [$this->item(6, 6, Difficulty::Medium), $this->item(4, 4, Difficulty::Hard)];

        $this->assertSame(DifficultyClass::Challenging, $this->classifier->classify(PerformanceStatus::Strong, $items));
    }

    // ---- Needs help: lost marks (§7.3, D2 provisional) ----

    #[DataProvider('needsHelpCases')]
    public function test_needs_help_uses_lost_marks(int $easyMediumLost, int $hardLost, DifficultyClass $expected): void
    {
        $items = [
            $this->item(0, $easyMediumLost, Difficulty::Easy),
            $this->item(0, $hardLost, Difficulty::Hard),
        ];

        $this->assertSame($expected, $this->classifier->classify(PerformanceStatus::NeedsHelp, $items));
    }

    public static function needsHelpCases(): array
    {
        return [
            'Easy+Medium lost 20% → basic' => [20, 80, DifficultyClass::Basic],
            'Easy+Medium lost 30% → mixed' => [30, 70, DifficultyClass::Mixed],
            'Easy+Medium lost 60% → challenging' => [60, 40, DifficultyClass::Challenging],
        ];
    }

    public function test_difficulty_class_exists_whenever_evidence_exists(): void
    {
        foreach ([[1, 10], [5, 10], [9, 10], [10, 10]] as [$earned, $max]) {
            $this->assertNotNull($this->assess([$this->item($earned, $max)])->difficultyClass);
        }
        $this->assertNull($this->assess([])->difficultyClass);
    }

    // ---- Confidence lookup (§7.5) ----

    public function test_lookup_table(): void
    {
        $this->assertSame(Confidence::Moderate, ConfidenceCalculator::lookup(CoverageBand::Medium, DifficultyClass::Basic));
        $this->assertSame(Confidence::Moderate, ConfidenceCalculator::lookup(CoverageBand::Medium, DifficultyClass::Mixed));
        $this->assertSame(Confidence::High, ConfidenceCalculator::lookup(CoverageBand::Medium, DifficultyClass::Challenging));
        $this->assertSame(Confidence::Moderate, ConfidenceCalculator::lookup(CoverageBand::Broad, DifficultyClass::Basic));
        $this->assertSame(Confidence::High, ConfidenceCalculator::lookup(CoverageBand::Broad, DifficultyClass::Mixed));
        $this->assertSame(Confidence::High, ConfidenceCalculator::lookup(CoverageBand::Broad, DifficultyClass::Challenging));
    }

    public function test_low_coverage_is_always_limited(): void
    {
        $result = $this->assess([$this->item(3, 3, Difficulty::Hard), $this->item(5, 6, Difficulty::Hard)]);

        $this->assertSame(Confidence::Limited, $result->confidence->confidence);
        $this->assertContains('low_coverage', $result->confidence->reasons);
    }

    public function test_strong_unconfirmed_always_has_limited_confidence(): void
    {
        $result = $this->assess([$this->item(8, 9, Difficulty::Hard)]);

        $this->assertSame(PerformanceStatus::StrongUnconfirmed, $result->performance->status);
        $this->assertSame(Confidence::Limited, $result->confidence->confidence);
    }

    public function test_easy_only_success_with_broad_coverage_is_moderate_never_high(): void
    {
        $result = $this->assess([$this->item(12, 12, Difficulty::Easy), $this->item(10, 10, Difficulty::Easy)]);

        $this->assertSame(CoverageBand::Broad, $result->performance->coverageBand);
        $this->assertSame(Confidence::Moderate, $result->confidence->confidence);
        $this->assertContains('mostly_basic', $result->confidence->reasons);
    }

    public function test_needs_help_with_medium_coverage_and_challenging_difficulty_is_high(): void
    {
        // 2/12 overall, lost marks all on Easy/Medium questions.
        $result = $this->assess([$this->item(1, 6, Difficulty::Easy), $this->item(1, 6, Difficulty::Medium)]);

        $this->assertSame(PerformanceStatus::NeedsHelp, $result->performance->status);
        $this->assertSame(DifficultyClass::Challenging, $result->difficultyClass);
        $this->assertSame(Confidence::High, $result->confidence->confidence);
    }

    public function test_no_evidence_means_confidence_unavailable(): void
    {
        $this->assertNull($this->assess([])->confidence->confidence);
    }

    public function test_difficulty_never_changes_the_performance_percentage(): void
    {
        $easy = $this->assess([$this->item(7, 10, Difficulty::Easy)]);
        $hard = $this->assess([$this->item(7, 10, Difficulty::Hard)]);

        $this->assertSame($easy->performance->displayPercent, $hard->performance->displayPercent);
        $this->assertSame($easy->performance->status, $hard->performance->status);
    }
}
