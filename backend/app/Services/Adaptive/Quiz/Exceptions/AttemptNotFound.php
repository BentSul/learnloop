<?php

namespace App\Services\Adaptive\Quiz\Exceptions;

/** The attempt does not exist or belongs to another learner (404, spec §14: hidden for privacy). */
class AttemptNotFound extends QuizException
{
    public function __construct()
    {
        parent::__construct('Attempt not found.');
    }

    public function status(): int
    {
        return 404;
    }
}
