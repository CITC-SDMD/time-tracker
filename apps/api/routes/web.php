<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// docs/DEVELOPMENT_PLAN.md §10: plain, unversioned health check for uptime monitoring
// and Test 1.3. Deliberately outside /api/v1 — it's infrastructure, not part of the API.
Route::get('/health', function () {
    return response()->json([
        'ok' => true,
        'version' => config('app.version', 'dev'),
    ]);
});
