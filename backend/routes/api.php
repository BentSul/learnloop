<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (prefixed with /api)
|--------------------------------------------------------------------------
| Authentication: Sanctum personal access tokens (Bearer).
| Role checks:    ->middleware('role:lecturer,admin')  (alias in bootstrap/app.php)
|
| Course/assignment routes are intentionally NOT registered yet: their models
| do not exist (see setup report). Adaptive-engine routes (v0.8 §8) will be
| added once Claude's response contracts are merged.
*/

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});
