<?php

namespace App\Services\Adaptive\Canvas;

/** Counts from one Canvas sync, returned to the API and stored in sync_runs. */
final class SyncSummary
{
    public int $created = 0;
    public int $unchanged = 0;
    public int $skipped = 0;

    /** Of $skipped: Canvas items not mapped to a LearnLoop topic. */
    public int $skippedUnmapped = 0;

    /** Of $skipped: mapped items with no visible score yet (not submitted, not graded or not released). */
    public int $skippedNoScore = 0;

    /** @var list<string> Canvas assignment IDs that need lecturer attention */
    public array $flagged = [];

    /** @return array{created: int, unchanged: int, skipped: int, skipped_unmapped: int, skipped_no_score: int, flagged: list<string>} */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'unchanged' => $this->unchanged,
            'skipped' => $this->skipped,
            'skipped_unmapped' => $this->skippedUnmapped,
            'skipped_no_score' => $this->skippedNoScore,
            'flagged' => $this->flagged,
        ];
    }
}
