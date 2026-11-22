<?php

namespace App\Enums\Adaptive;

/** Evidence-age label (spec §7.7). Shown next to confidence; never changes it. */
enum EvidenceAge: string
{
    case Recent = 'recent';
    case Older = 'older';
    case Outdated = 'outdated';
}
