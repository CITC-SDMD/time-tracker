<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

// POST /auth/forgot-password and /auth/reset-password (docs/DEVELOPMENT_PLAN.md §9.2). Public
// routes next to /auth/login; the dashboard's own pages call them, for every role — individual
// contributors never use the dashboard otherwise, but they set and reset passwords here too.
class PasswordResetController extends Controller
{
    /** Brokers whose links this endpoint accepts: the hour-long reset link and the 3-day welcome link. */
    private const BROKERS = ['users', 'invites'];

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        // Only active accounts get a link, and the answer is the same for everyone, so this
        // cannot be used to find out which emails have an account. A mail failure is hidden
        // the same way: it is logged, not shown.
        try {
            Password::broker('users')->sendResetLink(['email' => $data['email'], 'status' => 'active']);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['message' => 'If that email has an account, we sent a link to it.']);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(10)],
        ]);

        $user = User::where('email', $data['email'])->where('status', 'active')->first();
        $broker = $user ? $this->brokerHolding($user, $data['token']) : null;

        if ($broker === null) {
            return $this->invalidLink();
        }

        $status = Password::broker($broker)->reset(
            ['email' => $data['email'], 'token' => $data['token'], 'password' => $data['password'], 'status' => 'active'],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                // Everything signed in with the old password is signed out: the dashboard and
                // any desktop app. The desktop keeps its unsent data and asks to log in again.
                $user->tokens()->delete();
                AuditLog::record($user, 'password.reset', $user);
            },
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Your password has been set.'])
            : $this->invalidLink();
    }

    private function brokerHolding(User $user, string $token): ?string
    {
        foreach (self::BROKERS as $name) {
            if (Password::broker($name)->tokenExists($user, $token)) {
                return $name;
            }
        }

        return null;
    }

    private function invalidLink(): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'INVALID_TOKEN', 'message' => 'This link is invalid or has expired. Ask for a new one.'],
        ], 422);
    }
}
