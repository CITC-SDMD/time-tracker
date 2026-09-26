<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// The sync route has no `active` middleware (a deactivated person's PC may still hold data to upload), so what it
// checks about the organization is done here: superadmins have no business with the desktop app, and a suspended
// organization is refused, with the app told to sign out. Nothing is deleted, the data comes back when it is reactivated.
class EnsureAgentOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isSuperadmin()) {
            return response()->json(['error' => ['code' => 'FORBIDDEN', 'message' => 'Superadmin accounts do not use the desktop app.']], 403);
        }
        if ($user?->organization?->isSuspended()) {
            return response()->json([
                'error' => ['code' => 'ORG_SUSPENDED', 'message' => 'Your organization is suspended. Ask the platform administrator.'],
                'commands' => ['stopTracking' => false, 'stopReason' => null, 'signOut' => true],
            ], 403);
        }

        return $next($request);
    }
}
