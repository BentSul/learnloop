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
        // token   = the real Canvas REST API, using each user's own access token (Tier 2).
        'adapter' => env('CANVAS_ADAPTER', 'fixture'),
        'fixture_path' => env('CANVAS_FIXTURE_PATH', database_path('fixtures/canvas')),
        // Your institution's Canvas address, e.g. https://school.instructure.com (no trailing /api).
        'base_url' => env('CANVAS_BASE_URL'),
        'timeout_seconds' => (int) env('CANVAS_TIMEOUT', 15),
        // Course roles are looked up on most requests; cache them to avoid calling Canvas every time.
        'role_cache_minutes' => (int) env('CANVAS_ROLE_CACHE_MINUTES', 10),
        // "Sign in with Canvas" (OAuth2). Needs a Canvas developer key from the institution's Canvas admins;
        // stays off until both values are set. The redirect URI registered on the key must be
        // {APP_URL}/api/canvas/oauth/callback.
        'oauth' => [
            'client_id' => env('CANVAS_OAUTH_CLIENT_ID'),
            'client_secret' => env('CANVAS_OAUTH_CLIENT_SECRET'),
            'redirect_uri' => env('CANVAS_OAUTH_REDIRECT_URI'),
        ],
        // Canvas enrolment types treated as the course's lecturer (v0.8 D12). Add 'TaEnrollment' to include TAs.
        'teacher_enrollment_types' => ['TeacherEnrollment'],
    ],

    // Optional outside sources for lecturers. Each feature switches on when its key is set.
    'learning_sources' => [
        // YouTube Data API v3 key (Google Cloud). Used only when a lecturer asks for video suggestions.
        'youtube_api_key' => env('YOUTUBE_API_KEY'),
        // QuizAPI.io key: technical multiple-choice questions (PHP, MySQL, JavaScript, Linux...).
        'quizapi_key' => env('QUIZAPI_KEY'),
        'quizapi_url' => env('QUIZAPI_URL', 'https://quizapi.io/api/v1/questions'),
        'timeout_seconds' => 15,
    ],

];
