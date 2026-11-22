<?php

use Illuminate\Support\Facades\Route;

// Single-page React app: every browser URL that is not an API/health/asset path
// returns the same view; react-router decides what to render.
Route::view('/{any?}', 'app')
    ->where('any', '^(?!api(/|$)|up$|build/|storage/).*$');
