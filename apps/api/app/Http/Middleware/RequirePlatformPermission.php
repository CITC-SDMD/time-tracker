<?php

namespace App\Http\Middleware;

use App\Services\AccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// `platform:organizations.create`, or several separated by commas (any one is enough): only a superadmin holding a
// platform permission named (the owner holds all).
class RequirePlatformPermission
{
    public function __construct(private AccessService $access) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        foreach ($permissions as $permission) {
            if ($user && $this->access->superadminCan($user, $permission)) {
                return $next($request);
            }
        }

        return response()->json([
            'error' => ['code' => 'PERMISSION_DENIED', 'message' => 'You do not have this platform permission.'],
        ], 403);
    }
}
