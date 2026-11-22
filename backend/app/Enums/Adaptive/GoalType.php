<?php

namespace App\Enums\Adaptive;

/** Learner preferences per topic (spec §9). Never evidence. */
enum GoalType: string
{
    case Mastery = 'mastery';
    case Help = 'help';
}
