<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Used on /employees/{id}/summary, /timeline and /screenshots (docs/DEVELOPMENT_PLAN.md §10): the route
// parameter is the caller themselves, or someone in their reach when their role holds the permission named
// here (`self-or-visible:timeline.view`). Someone in the same organization but outside the reach is refused
// with 403; someone in another organization does not exist for the caller (the id resolves to nothing: 404).
class EnsureSelfOrVisible
{
    public function __construct(private AccessService $access) {}

    public function handle(Request $request, Closure $next, string $permission, string $routeParam = 'id'): Response
    {
        $targetId = (int) $request->route($routeParam);
        $user = $request->user();

        if (! $user || ! User::whereKey($targetId)->exists()) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.']], 404);
        }
        if (! $this->access->canSee($user, $targetId, $permission)) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'You cannot view this person\'s data.'],
            ], 403);
        }

        return $next($request);
    }
}
