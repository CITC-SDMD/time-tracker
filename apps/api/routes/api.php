<?php

// Mounted under /api by bootstrap/app.php, so every route here is /api/v1/... —
// matching the base URL in docs/DEVELOPMENT_PLAN.md §10.

use App\Http\Controllers\Api\AdminAuditController;
use App\Http\Controllers\Api\AdminEmployeeController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);

    // No `active` here on purpose: a deactivated user's PC may still hold unsent data, and
    // §10.1 step 4.3 has the controller accept what happened before deactivation and tell
    // the app to sign out. Every other route below still refuses deactivated users.
    Route::post('/agent/sync', [AgentController::class, 'sync'])
        ->middleware(['auth:sanctum', 'check-agent-version', 'throttle:agent-sync']);

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::post('/me/consent', [MeController::class, 'acceptConsent']);

        Route::middleware('manager')->group(function () {
            Route::get('/employees', [EmployeeController::class, 'index']);
            Route::middleware('self-or-visible')->group(function () {
                Route::get('/employees/{id}/summary', [EmployeeController::class, 'summary'])->whereNumber('id');
                Route::get('/employees/{id}/timeline', [EmployeeController::class, 'timeline'])->whereNumber('id');
            });
            Route::post('/admin/employees', [AdminEmployeeController::class, 'store']);
            Route::patch('/admin/employees/{id}', [AdminEmployeeController::class, 'update']);
        });

        // Office-wide, not hierarchy-scoped — OIC only, even for other manager roles
        // (§10 Test 2.13).
        Route::middleware('oic')->group(function () {
            Route::get('/admin/settings', [AdminSettingsController::class, 'show']);
            Route::put('/admin/settings', [AdminSettingsController::class, 'update']);
            Route::get('/admin/audit', [AdminAuditController::class, 'index']);
        });
    });
});
