<?php

namespace App\Services\Adaptive\Calculation;

use App\Enums\Adaptive\DifficultyClass;
use App\Enums\Adaptive\EvidenceAge;
use DateTimeImmutable;

/**
 * Everything the calculation core knows about one topic for one learner.
 * The API layer adds labels, pending_count and explanations (spec §13.4).
 */
final class TopicAssessment
{
    public function __construct(
        public readonly PerformanceResult $performance,
        public readonly ?DifficultyClass $difficultyClass,
        public readonly ConfidenceResult $confidence,
        public readonly ConflictResult $conflicts,
        public readonly ?DateTimeImmutable $lastAssessedAt,
        public readonly ?EvidenceAge $evidenceAge,
    ) {
    }
}
