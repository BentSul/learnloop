<?php

namespace App\Enums\Adaptive;

/**
 * Conflict source groups (v0.8 D1-R, pending team confirmation).
 * The calculation core treats group names as plain strings, so changing this
 * list does not require changes to the conflict rules.
 */
enum SourceGroup: string
{
    case LearnLoop = 'learnloop';
    case Canvas = 'canvas';
    case Klenam = 'klenam';
}
