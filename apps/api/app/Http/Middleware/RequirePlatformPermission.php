<?php

namespace App\Http\Middleware;

use App\Services\AccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// `platform:organizations.create`: only a superadmin holding that platform permission (the owner holds all).
class RequirePlatformPermission
{
    public function __construct(private AccessService $access) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (! $user || ! $this->access->superadminCan($user, $permission)) {
            return response()->json([
                'error' => ['code' => 'PERMISSION_DENIED', 'message' => 'You do not have this platform permission.'],
            ], 403);
        }

        return $next($request);
    }
}
