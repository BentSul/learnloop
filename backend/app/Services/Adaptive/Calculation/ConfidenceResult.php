<?php

namespace App\Services\Adaptive\Calculation;

use App\Enums\Adaptive\Confidence;

/** Confidence level (null when there is no evidence) and reason codes (spec §7.6). */
final class ConfidenceResult
{
    /**
     * @param  list<string>  $reasons  e.g. low_coverage, mostly_basic, hard_only_losses, conflict_reduced
     */
    public function __construct(
        public readonly ?Confidence $confidence,
        public readonly array $reasons,
    ) {
    }
}
