<?php

namespace Tests\Unit\Adaptive;

use App\Enums\Adaptive\Difficulty;
use App\Services\Adaptive\Calculation\EvidenceItem;
use App\Services\Adaptive\Calculation\Marks;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MarksTest extends TestCase
{
    public function test_decimal_strings_convert_exactly_to_hundredths(): void
    {
        $this->assertSame(799, Marks::toHundredths('7.99'));
        $this->assertSame(1999, Marks::toHundredths('19.99'));
        $this->assertSame(250, Marks::toHundredths('2.5'));
        $this->assertSame(1000, Marks::toHundredths('10.00'));
        $this->assertSame(800, Marks::toHundredths(8));
        $this->assertSame(350, Marks::toHundredths(3.5));
    }

    public function test_more_than_two_decimal_places_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Marks::toHundredths('1.234');
    }

    public function test_zero_maximum_marks_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EvidenceItem::fromMarks('q:1', 'learnloop', Difficulty::Easy, 0, 0, new DateTimeImmutable());
    }

    public function test_earned_above_maximum_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EvidenceItem::fromMarks('q:1', 'learnloop', Difficulty::Easy, 6, 5, new DateTimeImmutable());
    }

    public function test_missing_difficulty_defaults_to_medium(): void
    {
        // D10
        $this->assertSame(Difficulty::Medium, Difficulty::fromNullable(null));
        $this->assertSame(Difficulty::Medium, Difficulty::fromNullable(''));
        $this->assertSame(Difficulty::Hard, Difficulty::fromNullable('hard'));
    }
}
