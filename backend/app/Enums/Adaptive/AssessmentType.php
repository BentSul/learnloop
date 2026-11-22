<?php

namespace App\Enums\Adaptive;

/** LearnLoop assessment types (v0.8 §5): lecturer practice sets and learner revision quizzes. */
enum AssessmentType: string
{
    case Practice = 'practice';
    case Revision = 'revision';
}
