<?php

use App\Http\Controllers\Api\DashboardAuthController;
use App\Http\Controllers\Api\PasswordResetController;
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

// Dashboard sign-in (docs/DEVELOPMENT_PLAN.md §9.2): Sanctum SPA cookie authentication.
// Under /auth so a static-hosted dashboard page can own /login. /sanctum/csrf-cookie is registered by Sanctum itself.
Route::post('/auth/login', [DashboardAuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/auth/logout', [DashboardAuthController::class, 'logout']);
Route::post('/auth/forgot-password', [PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/auth/reset-password', [PasswordResetController::class, 'reset'])->middleware('throttle:10,1');
