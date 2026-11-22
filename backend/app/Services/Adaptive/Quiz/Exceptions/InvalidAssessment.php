<?php

namespace App\Services\Adaptive\Quiz\Exceptions;

/** The assessment cannot be attempted as set up, e.g. no usable questions (422). */
class InvalidAssessment extends QuizException
{
    public function status(): int
    {
        return 422;
    }
}
