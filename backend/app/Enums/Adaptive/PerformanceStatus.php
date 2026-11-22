<?php

namespace App\Enums\Adaptive;

/** Topic performance status (spec §5.5). */
enum PerformanceStatus: string
{
    case NotEnoughEvidence = 'not_enough_evidence';
    case NeedsHelp = 'needs_help';
    case GettingThere = 'getting_there';
    case StrongUnconfirmed = 'strong_unconfirmed';
    case Strong = 'strong';

    /** Statuses whose difficulty class is based on earned marks (spec §7.2). */
    public function isPositive(): bool
    {
        return in_array($this, [self::GettingThere, self::StrongUnconfirmed, self::Strong], true);
    }
}
