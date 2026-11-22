<?php

namespace App\Services\Adaptive\Quiz\Exceptions;

/** The assessment is not open, or is another learner's revision quiz (409 / 404). */
class AssessmentUnavailable extends QuizException
{
    public function __construct(string $message, private readonly int $httpStatus = 409)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->httpStatus;
    }
}
