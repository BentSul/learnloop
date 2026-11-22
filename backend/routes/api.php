<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Learner\AttemptController;
use App\Http\Controllers\Api\Learner\CanvasConnectionController;
use App\Http\Controllers\Api\Learner\CourseController;
use App\Http\Controllers\Api\Learner\GoalController;
use App\Http\Controllers\Api\Learner\RecommendationController;
use App\Http\Controllers\Api\Lecturer\CanvasMappingController;
use App\Http\Controllers\Api\Lecturer\ClassOverviewController;
use App\Http\Controllers\Api\Lecturer\QuestionBankController;
use App\Http\Controllers\Api\Lecturer\TopicController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (prefixed with /api)
|--------------------------------------------------------------------------
| Authentication: Sanctum personal access tokens (Bearer).
| Role checks:    ->middleware('role:lecturer,admin')  (alias in bootstrap/app.php)
|
| Adaptive-engine routes follow the contracts in AGENTS.md §9–§12. Course access
| comes from Canvas roles (CourseAccess), not users.role, so these routes do not
| use the role middleware; controllers return 404 for courses the user cannot see.
*/

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    // Canvas account link (any signed-in user)
    Route::get('me/canvas', [CanvasConnectionController::class, 'show']);
    Route::post('me/canvas', [CanvasConnectionController::class, 'store'])->middleware('throttle:10,1');
    Route::delete('me/canvas', [CanvasConnectionController::class, 'destroy']);

    // Learner (§9–§11)
    Route::prefix('me')->group(function () {
        Route::get('courses', [CourseController::class, 'index']);
        Route::get('courses/{course}/topics', [CourseController::class, 'topics']);
        Route::post('courses/{course}/sync', [CourseController::class, 'sync']);
        Route::get('courses/{course}/practice', [CourseController::class, 'practice']);
        Route::get('courses/{course}/diagnostic-prompt', [CourseController::class, 'diagnosticPrompt']);

        Route::post('assessments/{assessment}/attempts', [AttemptController::class, 'start']);
        Route::get('attempts/{attempt}', [AttemptController::class, 'show'])->whereNumber('attempt');
        Route::put('attempts/{attempt}/answers/{question}', [AttemptController::class, 'saveAnswer'])->whereNumber(['attempt', 'question']);
        Route::post('attempts/{attempt}/submit', [AttemptController::class, 'submit'])->whereNumber('attempt');

        Route::get('recommendations', [RecommendationController::class, 'index']);
        Route::get('recommendations/shortlist', [RecommendationController::class, 'shortlist']);
        Route::post('recommendations/{recommendation}/accept', [RecommendationController::class, 'accept'])->whereNumber('recommendation');
        Route::post('recommendations/{recommendation}/postpone', [RecommendationController::class, 'postpone'])->whereNumber('recommendation');
        Route::post('recommendations/{recommendation}/dismiss', [RecommendationController::class, 'dismiss'])->whereNumber('recommendation');
        Route::post('recommendations/{recommendation}/restore', [RecommendationController::class, 'restore'])->whereNumber('recommendation');

        Route::get('goals', [GoalController::class, 'index']);
        Route::post('topics/{topic}/goals', [GoalController::class, 'addGoal']);
        Route::delete('topics/{topic}/goals', [GoalController::class, 'removeGoal']);
        Route::post('topics/{topic}/help-requests', [GoalController::class, 'addHelp']);
        Route::delete('topics/{topic}/help-requests', [GoalController::class, 'removeHelp']);
        Route::post('topics/{topic}/revision-quiz', [GoalController::class, 'revisionQuiz']);
    });

    // Lecturer (§12) — the services check the Canvas teacher role per course
    Route::get('courses/{course}/topics', [QuestionBankController::class, 'topics']);
    Route::post('courses/{course}/topics', [TopicController::class, 'store']);
    Route::patch('topics/{topic}', [TopicController::class, 'update']);
    Route::get('topics/{topic}/questions', [QuestionBankController::class, 'index']);
    Route::post('topics/{topic}/questions', [QuestionBankController::class, 'store']);
    Route::post('topics/{topic}/questions/import', [QuestionBankController::class, 'import']);
    Route::patch('questions/{question}', [QuestionBankController::class, 'update']);
    Route::post('questions/{question}/archive', [QuestionBankController::class, 'archive']);
    Route::post('questions/{question}/regrade', [QuestionBankController::class, 'regrade']);

    Route::get('courses/{course}/canvas-items', [CanvasMappingController::class, 'items']);
    Route::put('courses/{course}/canvas-mappings', [CanvasMappingController::class, 'save']);

    Route::get('courses/{course}/overview', [ClassOverviewController::class, 'show']);
    Route::get('courses/{course}/learners/{learner}', [ClassOverviewController::class, 'learner'])->whereNumber('learner');
});
