<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// docs/DEVELOPMENT_PLAN.md §9.3 step 4: checked on every authenticated request, not
// cached — it's one indexed lookup on the same `users` row Sanctum just loaded, so a
// deactivation takes effect on the very next request instead of waiting out a cache TTL.
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Not logged in.'],
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'error' => ['code' => 'ACCOUNT_DEACTIVATED', 'message' => 'Your account is deactivated.'],
            ], 403);
        }

        return $next($request);
    }
}
