<?php

namespace App\Services\Adaptive\Quiz\Exceptions;

/** The answer does not fit the question (422). */
class InvalidAnswer extends QuizException
{
    public function status(): int
    {
        return 422;
    }
}
