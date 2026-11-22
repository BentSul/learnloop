<?php

namespace App\Services\Adaptive\Recommendations;

use App\Services\Adaptive\Quiz\Exceptions\QuizException;

/**
 * Recommendation rule violations; render their own JSON (spec §13.3).
 * 404 not found / not yours, 409 invalid transition, 422 invalid reminder.
 */
class RecommendationException extends QuizException
{
    public function __construct(string $message, private readonly int $httpStatus)
    {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('Recommendation not found.', 404);
    }

    public static function invalidTransition(string $from, string $action): self
    {
        return new self("A {$from} recommendation cannot be {$action}.", 409);
    }

    public static function invalidReminder(string $message): self
    {
        return new self($message, 422);
    }

    public function status(): int
    {
        return $this->httpStatus;
    }
}
