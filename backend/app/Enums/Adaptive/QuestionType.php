<?php

namespace App\Enums\Adaptive;

/** Question types (spec §3.2). v1 practice quizzes use objective types only (v0.8 D16). */
enum QuestionType: string
{
    case Mcq = 'mcq';
    case TrueFalse = 'true_false';
    case OpenEnded = 'open_ended';

    public function isObjective(): bool
    {
        return $this !== self::OpenEnded;
    }
}
