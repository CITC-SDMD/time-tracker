<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

// POST /auth/login and /auth/logout for the dashboard (docs/DEVELOPMENT_PLAN.md §9.2): Sanctum SPA
// authentication — a session cookie plus CSRF token, not a bearer token. These are Laravel's
// own unprefixed web routes, unlike the agent's token login under /api/v1/auth/login.
// Everyone with an account may sign in: what they see is decided by their role's permissions.
class DashboardAuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        // an address with no account is checked against a fixed hash, so the answer takes as long either way
        $passwordOk = Hash::check($request->string('password'), $user?->password ?? '$2y$12$vESU3Td1QPkVvY48Q2MMr.9dQS9wWAAVZv0zIyipi.LLPlWH4R8nK');

        if (! $user || ! $passwordOk) {
            return response()->json([
                'error' => ['code' => 'WRONG_PASSWORD', 'message' => 'Incorrect email or password.'],
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'error' => ['code' => 'ACCOUNT_DEACTIVATED', 'message' => 'Your account is deactivated.'],
            ], 403);
        }

        if (! $user->isSuperadmin() && $user->organization?->isSuspended()) {
            return response()->json([
                'error' => ['code' => 'ORG_SUSPENDED', 'message' => 'Your organization is suspended. Ask the platform administrator.'],
            ], 403);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(MeController::payload($user));
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
