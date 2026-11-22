<?php

namespace Tests\Unit\Adaptive;

use App\Enums\Adaptive\Difficulty;
use App\Services\Adaptive\Calculation\EvidenceItem;
use DateTimeImmutable;

/** Helpers for building evidence in calculation-core tests. */
trait BuildsEvidence
{
    private int $keyCounter = 0;

    private function item(
        int|float|string $earned,
        int|float|string $max,
        Difficulty $difficulty = Difficulty::Medium,
        string $group = 'learnloop',
        string $assessedAt = '2026-11-20T10:00:00Z',
    ): EvidenceItem {
        $this->keyCounter++;

        return EvidenceItem::fromMarks(
            "q:{$this->keyCounter}",
            $group,
            $difficulty,
            $earned,
            $max,
            new DateTimeImmutable($assessedAt),
        );
    }
}
