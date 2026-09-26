<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// the /platform routes are for superadmins only; which of them a superadmin may use is `platform:<permission>`.
class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSuperadmin()) {
            return response()->json([
                'error' => ['code' => 'PERMISSION_DENIED', 'message' => 'This is for platform superadmins only.'],
            ], 403);
        }

        return $next($request);
    }
}
