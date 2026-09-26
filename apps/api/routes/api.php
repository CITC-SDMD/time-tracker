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
use App\Http\Controllers\Api\PermissionCatalogController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ScreenshotController;
use App\Http\Controllers\Platform\OrganizationAdminController;
use App\Http\Controllers\Platform\OrganizationController;
use App\Http\Controllers\Platform\PersonDetectionController;
use App\Http\Controllers\Platform\PlatformAuditController;
use App\Http\Controllers\Platform\PlatformSettingsController;
use App\Http\Controllers\Platform\SuperadminController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    // No `active` here on purpose: a deactivated user's PC may still hold unsent data, and
    // §10.1 step 4.3 has the controller accept what happened before deactivation and tell
    // the app to sign out. Every other route below still refuses deactivated users.
    Route::post('/agent/sync', [AgentController::class, 'sync'])
        ->middleware(['auth:sanctum', 'agent-token', 'org', 'agent-organization', 'check-agent-version', 'throttle:agent-sync']);

    // The desktop app's screenshots (phase 10): one JPEG per request, at most 60 a minute.
    Route::post('/agent/screenshots', [ScreenshotController::class, 'store'])
        ->middleware(['auth:sanctum', 'agent-token', 'active', 'org', 'check-agent-version', 'throttle:60,1']);

    // What happens INSIDE an organization. Its own people use these at /api/v1/...; a superadmin with the right
    // platform permission opens an office at /api/v1/platform/organizations/{organization}/office/... and uses the very
    // same routes there. Every one of them is limited to the request's organization, and which of them a person may
    // use is decided by the permissions of their role.
    $insideAnOrganization = function () {
        Route::get('/permissions', [PermissionCatalogController::class, 'organization']);

        // Screenshots (phase 10). Someone can always see their own; others need screenshots.view and reach.
        Route::get('/employees/{id}/screenshots', [ScreenshotController::class, 'index'])
            ->whereNumber('id')->middleware('self-or-visible:screenshots.view');
        Route::get('/screenshots/{screenshot}/{kind}', [ScreenshotController::class, 'show'])
            ->whereUuid('screenshot')->whereIn('kind', ['thumb', 'image']);

        // Everyone gets their own row; people.view widens it to their reach.
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::middleware('self-or-visible:timeline.view')->group(function () {
            Route::get('/employees/{id}/summary', [EmployeeController::class, 'summary'])->whereNumber('id');
            Route::get('/employees/{id}/timeline', [EmployeeController::class, 'timeline'])->whereNumber('id');
        });

        Route::middleware('permission:reports.view')->group(function () {
            Route::get('/reports/daily', [ReportController::class, 'daily']);
            Route::get('/reports/apps', [ReportController::class, 'apps']);
            Route::get('/reports/team', [ReportController::class, 'team']);
        });

        Route::post('/admin/employees', [AdminEmployeeController::class, 'store'])->middleware('permission:people.create');
        Route::post('/admin/employees/import', [AdminEmployeeController::class, 'import'])
            ->middleware(['permission:people.create', 'throttle:10,1']);
        Route::patch('/admin/employees/{id}', [AdminEmployeeController::class, 'update'])
            ->whereNumber('id')->middleware('permission:people.update,people.assign_role');
        Route::post('/admin/employees/{id}/resend-invite', [AdminEmployeeController::class, 'resendInvite'])
            ->whereNumber('id')->middleware(['permission:people.update', 'throttle:10,1']);
        Route::delete('/admin/employees/{id}', [AdminEmployeeController::class, 'destroy'])
            ->whereNumber('id')->middleware('permission:people.update');

        // Roles: whoever may add people or change roles needs the list to choose from.
        Route::get('/roles', [RoleController::class, 'index'])->middleware('permission:roles.manage,people.create,people.assign_role');
        Route::post('/roles', [RoleController::class, 'store'])->middleware('permission:roles.manage');
        Route::patch('/roles/{id}', [RoleController::class, 'update'])->whereNumber('id')->middleware('permission:roles.manage');
        Route::delete('/roles/{id}', [RoleController::class, 'destroy'])->whereNumber('id')->middleware('permission:roles.manage');

        // Organization-wide, not limited to a reach.
        Route::get('/admin/settings', [AdminSettingsController::class, 'show'])->middleware('permission:settings.manage');
        Route::put('/admin/settings', [AdminSettingsController::class, 'update'])->middleware('permission:settings.manage');
        Route::get('/admin/audit', [AdminAuditController::class, 'index'])->middleware('permission:audit.view');
    };

    Route::middleware(['auth:sanctum', 'agent-token', 'active', 'org'])->group(function () use ($insideAnOrganization) {
        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me', [MeController::class, 'update']);
        Route::put('/me/email', [MeController::class, 'changeEmail'])->middleware('throttle:5,1');
        Route::put('/me/password', [MeController::class, 'changePassword'])->middleware('throttle:5,1');
        Route::post('/me/consent', [MeController::class, 'acceptConsent']);

        $insideAnOrganization();
    });

    // The platform: superadmins only, and each route needs its own platform permission.
    Route::prefix('platform')->middleware(['auth:sanctum', 'agent-token', 'active', 'superadmin'])->group(function () use ($insideAnOrganization) {
        Route::get('/permissions', [PermissionCatalogController::class, 'platform']);

        Route::get('/organizations', [OrganizationController::class, 'index'])->middleware('platform:organizations.view');
        Route::post('/organizations', [OrganizationController::class, 'store'])->middleware('platform:organizations.create');

        // from here on the request works inside the organization named in the URL (the `org` middleware)
        Route::prefix('organizations/{organization}')->whereNumber('organization')->middleware('org')->group(function () use ($insideAnOrganization) {
            // whoever may open an office may also read its name and timezone
            Route::get('/', [OrganizationController::class, 'show'])->middleware('platform:organizations.view,organizations.data.view');
            Route::patch('/', [OrganizationController::class, 'update'])->middleware('platform:organizations.update');

            Route::patch('/people/{id}/detection', [PersonDetectionController::class, 'update'])
                ->whereNumber('id')->middleware('platform:organizations.detection.manage');

            Route::middleware('platform:organizations.admins.manage')->group(function () {
                Route::get('/admins', [OrganizationAdminController::class, 'index']);
                Route::post('/admins', [OrganizationAdminController::class, 'store']);
                Route::patch('/admins/{id}', [OrganizationAdminController::class, 'update'])->whereNumber('id');
                Route::post('/admins/{id}/resend-invite', [OrganizationAdminController::class, 'resendInvite'])
                    ->whereNumber('id')->middleware('throttle:10,1');
            });

            // "open the office": the organization's own routes, what they allow follows the superadmin's platform permissions
            Route::prefix('office')->middleware('platform:organizations.data.view')->group($insideAnOrganization);
        });

        Route::get('/settings', [PlatformSettingsController::class, 'show'])->middleware('platform:platform.settings');
        Route::put('/settings', [PlatformSettingsController::class, 'update'])->middleware('platform:platform.settings');

        Route::get('/superadmins', [SuperadminController::class, 'index'])->middleware('platform:platform.staff.manage');
        Route::post('/superadmins', [SuperadminController::class, 'store'])->middleware('platform:platform.staff.manage');
        Route::patch('/superadmins/{id}', [SuperadminController::class, 'update'])->whereNumber('id')->middleware('platform:platform.staff.manage');

        Route::get('/audit', [PlatformAuditController::class, 'index'])->middleware('platform:platform.audit.view');
    });
});
