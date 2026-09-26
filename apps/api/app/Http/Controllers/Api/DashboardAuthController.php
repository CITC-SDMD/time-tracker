<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

// POST /auth/login and /auth/logout for the dashboard (docs/DEVELOPMENT_PLAN.md §9.2): Sanctum SPA
// authentication — a session cookie plus CSRF token, not a bearer token. These are Laravel's
// own unprefixed web routes, unlike the agent's token login under /api/v1/auth/login.
// Everyone with an account may sign in: what they see is decided by their role's permissions.
class DashboardAuthController extends Controller
{
    private const CHALLENGE = 'two-factor-challenge:';

    public function __construct(private TwoFactorService $twoFactor) {}

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

        // a second step: nothing is signed in yet, the person gets a short-lived challenge to answer with a code
        if ($this->twoFactor->enabled($user)) {
            $challenge = Str::random(48);
            Cache::put(self::CHALLENGE.$challenge, $user->id, now()->addMinutes(5));

            return response()->json(['twoFactorRequired' => true, 'challenge' => $challenge]);
        }

        return $this->signIn($request, $user);
    }

    /** POST /auth/two-factor  { challenge, code }: the second step of signing in */
    public function twoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string', 'max:100'], 'code' => ['required', 'string', 'max:20']]);

        $userId = Cache::get(self::CHALLENGE.$data['challenge']);
        $user = $userId === null ? null : User::find($userId);
        if ($user === null || $user->status !== 'active' || (! $user->isSuperadmin() && $user->organization?->isSuspended())) {
            return response()->json(['error' => ['code' => 'INVALID_CHALLENGE', 'message' => 'This sign-in has run out. Enter your password again.']], 422);
        }

        // wrong codes count against the person, not the address: five in 15 minutes stop the guessing whatever the password step allows
        $key = TwoFactorController::attemptKey($user);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['error' => ['code' => 'TOO_MANY_ATTEMPTS', 'message' => 'Too many wrong codes. Try again in a few minutes.']], 429);
        }
        if (! $this->twoFactor->verify($user, $data['code'])) {
            RateLimiter::hit($key, 900);

            return response()->json(['error' => ['code' => 'WRONG_CODE', 'message' => 'That code is not right.']], 422);
        }

        RateLimiter::clear($key);
        Cache::forget(self::CHALLENGE.$data['challenge']);

        return $this->signIn($request, $user);
    }

    private function signIn(Request $request, User $user): JsonResponse
    {
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
