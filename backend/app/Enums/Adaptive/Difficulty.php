<?php

namespace App\Enums\Adaptive;

/** Question / evidence difficulty (spec §3.4). D10: missing difficulty defaults to medium. */
enum Difficulty: string
{
    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';

    public static function fromNullable(?string $value): self
    {
        return $value === null || $value === '' ? self::Medium : self::from($value);
    }
}
