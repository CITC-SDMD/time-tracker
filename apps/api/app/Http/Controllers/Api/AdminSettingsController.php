<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateOrganizationSettingsRequest;
use App\Models\AuditLog;
use App\Models\OrganizationSetting;
use App\Models\Screenshot;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

// GET/PUT /api/v1/admin/settings (docs/DEVELOPMENT_PLAN.md §9.1, §10). Both routes carry
// `permission:settings.manage`; the settings are the caller's own organization's (a superadmin opening an office
// gets that one's), never another's.
class AdminSettingsController extends Controller
{
    public function __construct(private OrganizationContext $context) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload(OrganizationSetting::current()));
    }

    public function update(UpdateOrganizationSettingsRequest $request): JsonResponse
    {
        $settings = OrganizationSetting::current();

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
            'timezone', 'idleThresholdSeconds', 'windowTitleMode', 'consentVersion',
            'screenshotIntervalMinutes', 'screenshotRandom',
        ]));

        return response()->json($this->payload($settings));
    }

    /** @return array<string, mixed> */
    private function payload(OrganizationSetting $settings): array
    {
        return [
            'timezone' => $settings->timezone,
            'idleThresholdSeconds' => $settings->idle_threshold_seconds,
            'windowTitleMode' => $settings->window_title_mode,
            'consentVersion' => $settings->consent_version,
            'screenshotIntervalMinutes' => $settings->screenshot_interval_minutes,
            'screenshotRandom' => $settings->screenshot_random,
            // the space this organization's screenshots take on the storage disk, for the settings page
            'screenshotStorageBytes' => $this->storageBytes((int) $this->context->id()),
        ];
    }

    /** The bytes the screenshots of one organization take (the picture and its thumbnail are both media rows). */
    public static function storageBytes(int $organizationId): int
    {
        return (int) DB::table('media')
            ->join('screenshots', 'screenshots.id', '=', 'media.model_id')
            ->where('media.model_type', Screenshot::class)
            ->where('screenshots.organization_id', $organizationId)
            ->sum('media.size');
    }
}
