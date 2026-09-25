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
// Individual contributors have the right password but no business in a manager dashboard, so
// they are refused here as well as by every API route.
class DashboardAuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            return response()->json([
                'error' => ['code' => 'WRONG_PASSWORD', 'message' => 'Incorrect email or password.'],
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'error' => ['code' => 'ACCOUNT_DEACTIVATED', 'message' => 'Your account is deactivated.'],
            ], 403);
        }

        if (! $user->isManagerRole()) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'This dashboard is for managers only.'],
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
