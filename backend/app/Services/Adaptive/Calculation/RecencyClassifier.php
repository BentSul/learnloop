<?php

namespace App\Services\Adaptive\Calculation;

use App\Enums\Adaptive\EvidenceAge;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Evidence age based on the newest item in the current evidence set (spec §7.7).
 * Recency never changes performance, status or confidence. Computed at read time.
 */
final class RecencyClassifier
{
    public function __construct(private readonly Thresholds $thresholds = new Thresholds())
    {
    }

    /**
     * @param  list<EvidenceItem>  $items
     */
    public function lastAssessedAt(array $items): ?DateTimeImmutable
    {
        $newest = null;
        foreach ($items as $item) {
            if ($newest === null || $item->assessedAt > $newest) {
                $newest = $item->assessedAt;
            }
        }

        return $newest;
    }

    public function classify(?DateTimeImmutable $lastAssessedAt, DateTimeImmutable $now): ?EvidenceAge
    {
        if ($lastAssessedAt === null) {
            return null;
        }

        $days = self::wholeDaysBetween($lastAssessedAt, $now);

        return match (true) {
            $days <= $this->thresholds->recentMaxDays => EvidenceAge::Recent,
            $days <= $this->thresholds->olderMaxDays => EvidenceAge::Older,
            default => EvidenceAge::Outdated,
        };
    }

    /** Whole elapsed days in UTC; evidence dated in the future counts as 0 days. */
    public static function wholeDaysBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $utc = new DateTimeZone('UTC');
        $seconds = $to->setTimezone($utc)->getTimestamp() - $from->setTimezone($utc)->getTimestamp();

        return max(0, intdiv($seconds, 86400));
    }
}
