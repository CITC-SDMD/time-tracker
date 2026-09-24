<?php

namespace App\Http\Middleware;

use App\Services\HierarchyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Used on /employees/{id}/summary and /employees/{id}/timeline (docs/DEVELOPMENT_PLAN.md
// §10): the route param is self, or anyone in the caller's hierarchy (§9.1).
// visibleUserIds() already includes the caller's own id, so "self or visible" collapses
// to one check.
class EnsureSelfOrVisible
{
    public function __construct(private HierarchyService $hierarchy) {}

    public function handle(Request $request, Closure $next, string $routeParam = 'id'): Response
    {
        $targetId = (int) $request->route($routeParam);
        $user = $request->user();

        if (! $user || ! in_array($targetId, $this->hierarchy->visibleUserIds($user), true)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'You cannot view this person\'s data.'],
            ], 403);
        }

        return $next($request);
    }
}
