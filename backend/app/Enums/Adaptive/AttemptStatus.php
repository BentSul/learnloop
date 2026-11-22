<?php

namespace App\Enums\Adaptive;

/** Attempt lifecycle (spec §6.1). */
enum AttemptStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case AutoClosed = 'auto_closed';
    case Voided = 'voided';

    /** Finalised attempts whose answers can count as evidence. */
    public static function counting(): array
    {
        return [self::Submitted->value, self::AutoClosed->value];
    }
}
