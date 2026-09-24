<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AcceptConsentRequest;
use App\Models\OfficeSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// GET /api/v1/me, POST /api/v1/me/consent (docs/DEVELOPMENT_PLAN.md §10).
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
            'consentVersion' => $user->consent_version,
            'consentRequired' => $consentRequired,
            'officeSettings' => [
                'timezone' => $settings->timezone,
                'idleThresholdSeconds' => $settings->idle_threshold_seconds,
                'windowTitleMode' => $settings->window_title_mode,
                'minAgentVersion' => $settings->min_agent_version,
                'consentVersion' => $settings->consent_version,
            ],
        ];
    }
}
