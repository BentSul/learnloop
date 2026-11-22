<?php

namespace App\Enums\Adaptive;

/** Confidence in a topic assessment (spec §7.5). */
enum Confidence: string
{
    case Limited = 'limited';
    case Moderate = 'moderate';
    case High = 'high';

    /** One level lower, never below Limited (spec §8.4). */
    public function reducedByOne(): self
    {
        return match ($this) {
            self::High => self::Moderate,
            self::Moderate, self::Limited => self::Limited,
        };
    }
}
