<?php

namespace Tests\Unit\Adaptive;

use App\Enums\Adaptive\CoverageBand;
use App\Enums\Adaptive\PerformanceStatus;
use App\Services\Adaptive\Calculation\PerformanceCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Spec §16.1 performance and coverage. */
class PerformanceCalculatorTest extends TestCase
{
    use BuildsEvidence;

    private PerformanceCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new PerformanceCalculator();
    }

    public function test_zero_evidence_is_not_enough_evidence_without_division(): void
    {
        $result = $this->calc->calculate([]);

        $this->assertSame(PerformanceStatus::NotEnoughEvidence, $result->status);
        $this->assertNull($result->displayPercent);
        $this->assertNull($result->coverageBand);
    }

    public function test_mark_weighted_example_from_spec(): void
    {
        // §5.3: 4/5 + 3/5 + 7/10 = 14/20 = 70%, not the average of percentages.
        $result = $this->calc->calculate([$this->item(4, 5), $this->item(3, 5), $this->item(7, 10)]);

        $this->assertSame(70.0, $result->displayPercent);
        $this->assertSame(PerformanceStatus::GettingThere, $result->status);
    }

    public function test_weighting_differs_from_simple_average(): void
    {
        // 9/10 and 1/2: mark-weighted 10/12 = 83.3%; a simple average would give 70%.
        $result = $this->calc->calculate([$this->item(9, 10), $this->item(1, 2)]);

        $this->assertSame(83.3, $result->displayPercent);
    }

    #[DataProvider('statusCases')]
    public function test_status_thresholds(string $earned, string $max, PerformanceStatus $expected): void
    {
        $this->assertSame($expected, $this->calc->calculate([$this->item($earned, $max)])->status);
    }

    public static function statusCases(): array
    {
        return [
            '4/10 needs help' => ['4', '10', PerformanceStatus::NeedsHelp],
            '4.99/10 needs help' => ['4.99', '10', PerformanceStatus::NeedsHelp],
            '5/10 getting there' => ['5', '10', PerformanceStatus::GettingThere],
            '7.99/10 getting there' => ['7.99', '10', PerformanceStatus::GettingThere],
            '8/10 strong' => ['8', '10', PerformanceStatus::Strong],
            '8/9 strong unconfirmed (T4)' => ['8', '9', PerformanceStatus::StrongUnconfirmed],
            '9.99/9.99 strong unconfirmed (T4)' => ['9.99', '9.99', PerformanceStatus::StrongUnconfirmed],
            '19.99/25 getting there' => ['19.99', '25', PerformanceStatus::GettingThere],
            '20/25 strong' => ['20', '25', PerformanceStatus::Strong],
        ];
    }

    public function test_exactly_ten_marks_satisfies_the_strong_requirement(): void
    {
        $this->assertSame(PerformanceStatus::Strong, $this->calc->calculate([$this->item(10, 10)])->status);
        $this->assertSame(PerformanceStatus::StrongUnconfirmed, $this->calc->calculate([$this->item(9, 9)])->status);
    }

    #[DataProvider('displayCases')]
    public function test_display_always_rounds_down(string $earned, string $max, float $expected): void
    {
        // C13
        $this->assertSame($expected, $this->calc->calculate([$this->item($earned, $max)])->displayPercent);
    }

    public static function displayCases(): array
    {
        return [
            '8/9 = 88.88… → 88.8' => ['8', '9', 88.8],
            '19.99/25 = 79.96 → 79.9' => ['19.99', '25', 79.9],
            '7.99/10 → 79.9' => ['7.99', '10', 79.9],
            '2/3 = 66.66… → 66.6' => ['2', '3', 66.6],
            '1/3 = 33.33… → 33.3' => ['1', '3', 33.3],
            '10/10 → 100' => ['10', '10', 100.0],
            '0/10 → 0' => ['0', '10', 0.0],
        ];
    }

    public function test_a_displayed_value_never_reaches_a_threshold_the_exact_value_has_not(): void
    {
        // 79.96% must never display as 80.0% while the status is Getting there.
        $result = $this->calc->calculate([$this->item('19.99', '25')]);

        $this->assertLessThan(80.0, $result->displayPercent);
        $this->assertSame(PerformanceStatus::GettingThere, $result->status);
    }

    #[DataProvider('coverageCases')]
    public function test_coverage_bands(string $max, CoverageBand $expected): void
    {
        $this->assertSame($expected, $this->calc->calculate([$this->item(0, $max)])->coverageBand);
    }

    public static function coverageCases(): array
    {
        return [
            '9 → low' => ['9', CoverageBand::Low],
            '9.99 → low' => ['9.99', CoverageBand::Low],
            '10 → medium' => ['10', CoverageBand::Medium],
            '19 → medium' => ['19', CoverageBand::Medium],
            '19.99 → medium' => ['19.99', CoverageBand::Medium],
            '20 → broad' => ['20', CoverageBand::Broad],
        ];
    }

    public function test_coverage_counts_all_sources_and_can_exceed_any_bank_size(): void
    {
        // T2: coverage is the learner's evidence (here 24 marks), independent of topic size.
        $result = $this->calc->calculate([
            $this->item(4, 4, group: 'learnloop'),
            $this->item(15, 20, group: 'canvas'),
        ]);

        $this->assertSame(2400, $result->max);
        $this->assertSame(CoverageBand::Broad, $result->coverageBand);
    }
}
