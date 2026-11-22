<?php

namespace App\Services\Adaptive\Canvas;

use App\Services\Adaptive\Quiz\Exceptions\QuizException;

/** Canvas could not be reached or returned an unexpected error (502). Nothing is changed. */
class CanvasUnavailable extends QuizException
{
    public function status(): int
    {
        return 502;
    }
}
