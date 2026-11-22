<?php

/*
|--------------------------------------------------------------------------
| Adaptive Engine — provisional values
|--------------------------------------------------------------------------
|
| Only values the team has NOT confirmed live here, so they can be tuned with
| sample data (spec v0.7 §17.2). Confirmed rules (T1–T4, C13) are fixed in
| App\Services\Adaptive\Calculation\Thresholds and must not be configured.
|
*/

return [

    // D2 — difficulty classification thresholds, in percent (spec §7.2–§7.3).
    'difficulty' => [
        'basic_below_percent' => 25,
        'challenging_from_percent' => 50,
        'challenging_hard_from_percent' => 15,
    ],

    // Revision / practice quizzes (spec §6.7).
    'revision_quiz' => [
        'max_questions' => 10,
        'min_available_questions' => 3,
        'recently_answered_days' => 7,
    ],

    // Recommendations (spec §10.10–§10.12).
    'recommendations' => [
        'reminder_max_days' => 60,
        'dashboard_shortlist' => 5,
    ],

    // Canvas sync (v0.8 amendment §3.6).
    'canvas' => [
        'sync_after_minutes' => 15,
        // fixture = sample data in database/fixtures/canvas (v0.8 §3.1 Tier 3).
        'adapter' => env('CANVAS_ADAPTER', 'fixture'),
        'fixture_path' => env('CANVAS_FIXTURE_PATH', database_path('fixtures/canvas')),
    ],

];
