<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;

// POST /api/v1/auth/login (docs/DEVELOPMENT_PLAN.md §9.2). Issues a Sanctum personal
// access token for the desktop agent — there is no separate refresh step (§9.2); the
// token itself is the long-lived credential, checked live against `status` on every
// request via EnsureActiveUser.
class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->string('email'))->first();

        // an address with no account is checked against a fixed hash, so the answer takes as long either way
        $passwordOk = Hash::check($request->string('password'), $user?->password ?? '$2y$12$vESU3Td1QPkVvY48Q2MMr.9dQS9wWAAVZv0zIyipi.LLPlWH4R8nK');

        if (! $user || ! $passwordOk) {
            // Deliberately the same response whether the email doesn't exist or the
            // password is wrong — don't help an attacker enumerate accounts.
            return response()->json([
                'error' => ['code' => 'WRONG_PASSWORD', 'message' => 'Incorrect email or password.'],
            ], 401);
        }

        if ($user->status !== 'active') {
            return response()->json([
                'error' => ['code' => 'ACCOUNT_DEACTIVATED', 'message' => 'Your account is deactivated.'],
            ], 403);
        }

        // the desktop app is for people of an organization; superadmins work in the dashboard only
        if ($user->isSuperadmin()) {
            return response()->json([
                'error' => ['code' => 'FORBIDDEN', 'message' => 'Superadmin accounts do not use the desktop app.'],
            ], 403);
        }
        if ($user->organization?->isSuspended()) {
            return response()->json([
                'error' => ['code' => 'ORG_SUSPENDED', 'message' => 'Your organization is suspended. Ask the platform administrator.'],
            ], 403);
        }

        // the header is the app's own id, but it is written by whoever calls: cap it so it fits the column
        $name = 'agent-'.mb_substr((string) $request->header('X-Device-Id', 'unknown'), 0, 64);
        // signing in again on a PC replaces that PC's earlier token instead of leaving one behind for every login
        $user->tokens()->where('name', $name)->delete();
        $token = $user->createToken($name, ['agent'], now()->addDays(config('sanctum.agent_token_days')))->plainTextToken;

        return response()->json([
            'token' => $token,
            'me' => MeController::payload($user),
        ]);
    }
}
