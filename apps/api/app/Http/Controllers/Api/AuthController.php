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

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
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

        $deviceId = $request->header('X-Device-Id', 'unknown');
        $token = $user->createToken("agent-{$deviceId}", ['agent'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'me' => MeController::payload($user),
        ]);
    }
}
