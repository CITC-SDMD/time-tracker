<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Office settings and the audit log are OIC-only, not just any manager role
// (docs/DEVELOPMENT_PLAN.md §9.1) — they're org-wide, not scoped to a hierarchy branch.
class EnsureOic
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->role !== 'OIC') {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Only the OIC can do this.'],
            ], 403);
        }

        return $next($request);
    }
}
