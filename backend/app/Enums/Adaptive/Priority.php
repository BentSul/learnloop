<?php

namespace App\Enums\Adaptive;

/** Recommendation priority, highest first (spec §10.3). */
enum Priority: string
{
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';
    case Optional = 'optional';

    /** Larger means more urgent. */
    public function rank(): int
    {
        return match ($this) {
            self::High => 4,
            self::Medium => 3,
            self::Low => 2,
            self::Optional => 1,
        };
    }

    public static function highest(self ...$priorities): ?self
    {
        $best = null;
        foreach ($priorities as $p) {
            if ($best === null || $p->rank() > $best->rank()) {
                $best = $p;
            }
        }

        return $best;
    }
}
