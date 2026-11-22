<?php

namespace App\Enums\Adaptive;

/** Evidence coverage band (spec §7.1). */
enum CoverageBand: string
{
    case Low = 'low';
    case Medium = 'medium';
    case Broad = 'broad';
}
