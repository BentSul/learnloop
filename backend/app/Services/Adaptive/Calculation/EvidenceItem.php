<?php

namespace App\Services\Adaptive\Calculation;

use App\Enums\Adaptive\Difficulty;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * One item in a learner's current evidence set for a topic (spec §5.2, §6.6).
 *
 * The evidence assembler guarantees that each evidence key appears at most once.
 * Marks are integer hundredths (see Marks).
 */
final class EvidenceItem
{
    public function __construct(
        public readonly string $evidenceKey,
        public readonly string $sourceGroup,
        public readonly Difficulty $difficulty,
        public readonly int $earned,
        public readonly int $max,
        public readonly DateTimeImmutable $assessedAt,
    ) {
        if ($max <= 0) {
            throw new InvalidArgumentException("Evidence '{$evidenceKey}': maximum marks must be greater than 0.");
        }

        if ($earned < 0 || $earned > $max) {
            throw new InvalidArgumentException("Evidence '{$evidenceKey}': earned marks must be between 0 and the maximum.");
        }
    }

    /** Convenience constructor taking marks as decimals or DECIMAL strings. */
    public static function fromMarks(
        string $evidenceKey,
        string $sourceGroup,
        Difficulty $difficulty,
        int|float|string $earned,
        int|float|string $max,
        DateTimeImmutable $assessedAt,
    ): self {
        return new self(
            $evidenceKey,
            $sourceGroup,
            $difficulty,
            Marks::toHundredths($earned),
            Marks::toHundredths($max),
            $assessedAt,
        );
    }

    public function lost(): int
    {
        return $this->max - $this->earned;
    }
}
