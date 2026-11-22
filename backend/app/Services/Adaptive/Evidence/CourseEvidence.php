<?php

namespace App\Services\Adaptive\Evidence;

use App\Services\Adaptive\Calculation\EvidenceItem;

/**
 * A learner's current evidence set for one course, grouped by topic, plus the number of
 * results still awaiting marking or posting per topic (spec §5.8).
 */
final class CourseEvidence
{
    /**
     * @param  array<int, list<EvidenceItem>>  $itemsByTopic
     * @param  array<int, int>  $pendingByTopic
     */
    public function __construct(
        public readonly array $itemsByTopic,
        public readonly array $pendingByTopic,
    ) {
    }

    /** @return list<EvidenceItem> */
    public function forTopic(int $topicId): array
    {
        return $this->itemsByTopic[$topicId] ?? [];
    }

    public function pendingForTopic(int $topicId): int
    {
        return $this->pendingByTopic[$topicId] ?? 0;
    }
}
