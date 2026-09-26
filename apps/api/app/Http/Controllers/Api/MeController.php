<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptConsentRequest;
use App\Models\AuditLog;
use App\Models\OfficeSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

// GET /api/v1/me, PATCH /api/v1/me, PUT /api/v1/me/password, POST /api/v1/me/consent
// (docs/DEVELOPMENT_PLAN.md §10).
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(self::payload($request->user()));
    }

    public function acceptConsent(AcceptConsentRequest $request): JsonResponse
    {
        $user = $request->user();
        // Explicit assignment — consent_version/consent_accepted_at aren't Fillable
        // (see User.php), so update([...]) would silently no-op.
        $user->consent_version = $request->integer('consentVersion');
        $user->consent_accepted_at = now();
        $user->save();

        return response()->json(self::payload($user));
    }

    /** PATCH /me: a person may change their own display name (email and role are not theirs to change). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $user = $request->user();
        $user->name = trim($data['name']);
        $user->save();

        return response()->json(self::payload($user));
    }

    /**
     * PUT /me/password: needs the current password, so a stolen session cannot lock the owner out.
     * Every token is revoked afterwards (like a reset from the emailed link): a desktop app keeps
     * its unsent data and asks for the new password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(10)],
        ]);

        $user = $request->user();

        if (! Hash::check($data['currentPassword'], $user->password)) {
            return response()->json([
                'error' => ['code' => 'WRONG_PASSWORD', 'message' => 'Your current password is not right.'],
            ], 422);
        }
        if (Hash::check($data['password'], $user->password)) {
            return response()->json([
                'error' => ['code' => 'SAME_PASSWORD', 'message' => 'Choose a password you have not been using.'],
            ], 422);
        }

        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        $user->tokens()->delete();
        AuditLog::record($user, 'password.changed', $user);

        return response()->json(['message' => 'Your password has been changed.']);
    }

    /**
     * Shared by AuthController@login and GET /me so both return the identical `Me`
     * shape (packages/shared/src/api.ts).
     *
     * @return array<string, mixed>
     */
    public static function payload(User $user): array
    {
        $settings = OfficeSetting::current();
        $consentRequired = $user->consent_version === null || $user->consent_version < $settings->consent_version;

        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'status' => $user->status,
            'managerName' => $user->manager?->name,
            'consentVersion' => $user->consent_version,
            'consentRequired' => $consentRequired,
            'officeSettings' => [
                'timezone' => $settings->timezone,
                'idleThresholdSeconds' => $settings->idle_threshold_seconds,
                'windowTitleMode' => $settings->window_title_mode,
                'minAgentVersion' => $settings->min_agent_version,
                'consentVersion' => $settings->consent_version,
                'screenshotIntervalMinutes' => $settings->screenshot_interval_minutes,
                'screenshotRandom' => $settings->screenshot_random,
            ],
        ];
    }
}
