<?php

namespace App\Services\Adaptive\Quiz\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Base class for practice-quiz rule violations. Laravel calls render() automatically,
 * so controllers can let these propagate and the API returns the status codes in spec §13.3.
 */
abstract class QuizException extends RuntimeException
{
    abstract public function status(): int;

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['message' => $this->getMessage()];
    }

    public function render(): JsonResponse
    {
        return response()->json($this->payload(), $this->status());
    }
}
