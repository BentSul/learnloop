<?php

namespace App\Enums\Adaptive;

/** Mark version status (spec §4.5). Only auto and approved versions are eligible. */
enum MarkVersionStatus: string
{
    case Auto = 'auto';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public static function eligible(): array
    {
        return [self::Auto->value, self::Approved->value];
    }
}
