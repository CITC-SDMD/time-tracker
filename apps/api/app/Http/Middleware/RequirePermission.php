<?php

namespace App\Http\Middleware;

use App\Services\AccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// `permission:people.view` or, for several, `permission:reports.view,reports.export` (any one of them is enough):
// the person's role (or, for a superadmin inside an organization, their platform permissions) must hold it.
// Whose data it may touch is a separate question, answered by AccessService::visibleUserIds and the controllers.
class RequirePermission
{
    public function __construct(private AccessService $access) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        foreach ($permissions as $permission) {
            if ($user && $this->access->can($user, $permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'error' => ['code' => 'PERMISSION_DENIED', 'message' => 'Your role does not allow this.'],
        ], 403);
    }
}
