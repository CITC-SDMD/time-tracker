<?php

// Mounted under /api by bootstrap/app.php, so every route here is /api/v1/... —
// matching the base URL in docs/DEVELOPMENT_PLAN.md §10.

use App\Http\Controllers\Api\AdminAuditController;
use App\Http\Controllers\Api\AdminEmployeeController;
use App\Http\Controllers\Api\AdminSettingsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::get('/me', [MeController::class, 'show']);
        Route::post('/me/consent', [MeController::class, 'acceptConsent']);

        // §10.3: summary/timeline land in Phase 4/6, once sessions have real data.
        Route::middleware('manager')->group(function () {
            Route::get('/employees', [EmployeeController::class, 'index']);
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
