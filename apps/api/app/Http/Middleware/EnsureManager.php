<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Gates dashboard-area routes generally (docs/DEVELOPMENT_PLAN.md §9.1): OIC,
// PROJECT_MANAGER, TEAM_LEADER. Individual-contributor roles never reach these routes.
class EnsureManager
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isManagerRole()) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'This dashboard is for managers only.'],
            ], 403);
        }

        return $next($request);
    }
}
