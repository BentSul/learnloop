<?php

namespace App\Services\Adaptive\Canvas;

/** Counts from one Canvas sync, returned to the API and stored in sync_runs. */
final class SyncSummary
{
    public int $created = 0;
    public int $unchanged = 0;
    public int $skipped = 0;

    /** @var list<string> Canvas assignment IDs that need lecturer attention */
    public array $flagged = [];

    /** @return array{created: int, unchanged: int, skipped: int, flagged: list<string>} */
    public function toArray(): array
    {
        return [
            'created' => $this->created,
            'unchanged' => $this->unchanged,
            'skipped' => $this->skipped,
            'flagged' => $this->flagged,
        ];
    }
}
