<?php

namespace App\Services\Adaptive\Quiz\Exceptions;

/** A learner may have only one in-progress attempt per assessment (409, spec §6.1). */
class AttemptAlreadyInProgress extends QuizException
{
    public function __construct(public readonly ?int $attemptId = null)
    {
        parent::__construct('You already have an attempt in progress for this quiz.');
    }

    public function status(): int
    {
        return 409;
    }

    public function payload(): array
    {
        return ['message' => $this->getMessage(), 'attempt_id' => $this->attemptId];
    }
}
