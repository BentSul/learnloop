<?php

namespace App\Services\Adaptive\Recommendations;

use App\Enums\Adaptive\Priority;

/** What a topic's recommendation should be right now (spec §10.6). */
final class DesiredRecommendation
{
    /**
     * @param  list<string>  $reasonCodes  in §10.2 order
     */
    public function __construct(
        public readonly string $signature,
        public readonly string $status,
        public readonly array $reasonCodes,
        public readonly string $primaryReason,
        public readonly Priority $priority,
    ) {
    }
}
