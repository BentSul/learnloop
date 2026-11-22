<?php

namespace App\Enums\Adaptive;

/** Recommendation lifecycle states (spec §10.8). */
enum RecommendationState: string
{
    case Active = 'active';
    case Accepted = 'accepted';
    case Postponed = 'postponed';
    case Dismissed = 'dismissed';
    case Completed = 'completed';
    case Obsolete = 'obsolete';
    case Superseded = 'superseded';

    /** Open states: at most one per learner and topic. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Active, self::Accepted, self::Postponed], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Active->value, self::Accepted->value, self::Postponed->value];
    }
}
