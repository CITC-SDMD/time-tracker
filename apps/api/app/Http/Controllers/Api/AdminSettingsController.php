<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOfficeSettingsRequest;
use App\Models\AuditLog;
use App\Models\OfficeSetting;
use Illuminate\Http\JsonResponse;

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
        $settings->save();

        AuditLog::record($request->user(), 'settings.updated', null, $request->only([
            'timezone', 'idleThresholdSeconds', 'windowTitleMode', 'minAgentVersion', 'consentVersion',
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
        ];
    }
}
