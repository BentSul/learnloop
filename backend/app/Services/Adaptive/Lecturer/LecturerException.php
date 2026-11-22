<?php

namespace App\Services\Adaptive\Lecturer;

use App\Services\Adaptive\Quiz\Exceptions\QuizException;

/** Lecturer tool errors; render their own JSON (spec §13.3). */
class LecturerException extends QuizException
{
    /** @param list<string> $errors */
    public function __construct(string $message, private readonly int $httpStatus, public readonly array $errors = [])
    {
        parent::__construct($message);
    }

    /** Not a lecturer of this course: hidden as 404 (spec §14). */
    public static function notFound(): self
    {
        return new self('Not found.', 404);
    }

    /** @param list<string> $errors */
    public static function invalid(string $message, array $errors = []): self
    {
        return new self($message, 422, $errors);
    }

    public function status(): int
    {
        return $this->httpStatus;
    }

    public function payload(): array
    {
        return $this->errors === []
            ? ['message' => $this->getMessage()]
            : ['message' => $this->getMessage(), 'errors' => $this->errors];
    }
}
