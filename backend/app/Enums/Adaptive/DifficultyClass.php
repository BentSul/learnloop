<?php

namespace App\Enums\Adaptive;

/** Difficulty classification of a topic's evidence (spec §7.2–§7.3). */
enum DifficultyClass: string
{
    case Basic = 'basic';
    case Mixed = 'mixed';
    case Challenging = 'challenging';
}
