<?php

namespace App\Services\Adaptive\Quiz\Exceptions;

/** Saving or submitting an attempt that is already finalised (409, spec §6.4–§6.5). */
class AttemptNotInProgress extends QuizException
{
    public function __construct()
    {
        parent::__construct('This attempt has already been submitted.');
    }

    public function status(): int
    {
        return 409;
    }
}
