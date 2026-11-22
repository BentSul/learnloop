<?php

namespace App\Services\Adaptive\Canvas;

use App\Services\Adaptive\Quiz\Exceptions\QuizException;

/** The user has no active Canvas connection, or the course is not linked to Canvas (409). */
class CanvasNotConnected extends QuizException
{
    public function status(): int
    {
        return 409;
    }
}
