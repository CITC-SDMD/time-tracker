<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOfficeSettingsRequest;
use App\Models\AuditLog;
use App\Models\OfficeSetting;
use App\Models\Screenshot;
use Illuminate\Http\JsonResponse;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

// GET/PUT /api/v1/admin/settings (docs/DEVELOPMENT_PLAN.md §9.1, §10). Both routes
// carry the `oic` middleware — office-wide settings aren't scoped to a hierarchy
// branch, so no manager below OIC may read or change them (§10 Test 2.13).
class AdminSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload(OfficeSetting::current()));
    }

    public function update(UpdateOfficeSettingsRequest $request): JsonResponse
    {
        $settings = OfficeSetting::current();

        // Turning screenshots on means everyone must accept a new notice first, so the consent
        // version has to go up in the same change (docs phase 10).
        if ($request->has('screenshotIntervalMinutes')
            && $settings->screenshot_interval_minutes === 0
            && $request->integer('screenshotIntervalMinutes') > 0
            && $request->integer('consentVersion', $settings->consent_version) <= $settings->consent_version) {
            return response()->json(['error' => [
                'code' => 'SCREENSHOTS_NEED_CONSENT',
                'message' => 'Turning screenshots on needs a higher consent version, so everyone accepts the new notice first.',
            ]], 422);
        }

        // Explicit property assignment, not a mass-assignment update([...]) — same
        // reasoning as AdminEmployeeController@store: only touch fields actually sent.
        if ($request->has('timezone')) {
            $settings->timezone = $request->string('timezone');
        }
        if ($request->has('idleThresholdSeconds')) {
            $settings->idle_threshold_seconds = $request->integer('idleThresholdSeconds');
        }
        if ($request->has('windowTitleMode')) {
            $settings->window_title_mode = $request->string('windowTitleMode');
        }
        if ($request->has('minAgentVersion')) {
            $settings->min_agent_version = $request->string('minAgentVersion');
        }
        if ($request->has('consentVersion')) {
            $settings->consent_version = $request->integer('consentVersion');
        }
        if ($request->has('screenshotIntervalMinutes')) {
            $settings->screenshot_interval_minutes = $request->integer('screenshotIntervalMinutes');
        }
        if ($request->has('screenshotRandom')) {
            $settings->screenshot_random = $request->boolean('screenshotRandom');
        }
        $settings->save();

        AuditLog::record($request->user(), 'settings.updated', null, $request->only([
            'timezone', 'idleThresholdSeconds', 'windowTitleMode', 'minAgentVersion', 'consentVersion',
            'screenshotIntervalMinutes', 'screenshotRandom',
        ]));

        return response()->json($this->payload($settings));
    }

    /** @return array<string, mixed> */
    private function payload(OfficeSetting $settings): array
    {
        return [
            'timezone' => $settings->timezone,
            'idleThresholdSeconds' => $settings->idle_threshold_seconds,
            'windowTitleMode' => $settings->window_title_mode,
            'minAgentVersion' => $settings->min_agent_version,
            'consentVersion' => $settings->consent_version,
            'screenshotIntervalMinutes' => $settings->screenshot_interval_minutes,
            'screenshotRandom' => $settings->screenshot_random,
            // the space the screenshots take on the storage disk, for the settings page
            'screenshotStorageBytes' => (int) Media::where('model_type', Screenshot::class)->sum('size'),
        ];
    }
}
