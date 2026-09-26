<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// GET/PUT /api/v1/platform/settings (docs/DEVELOPMENT_PLAN.md §9.4): what only the platform decides for every
// organization. Needs `platform.settings`.
class PlatformSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['minAgentVersion' => PlatformSetting::current()->min_agent_version]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            // a version like 1.2.3: anything else would lock every desktop app out of syncing
            'minAgentVersion' => ['required', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+$/'],
        ]);

        $settings = PlatformSetting::current();
        $before = $settings->min_agent_version;
        $settings->min_agent_version = $data['minAgentVersion'];
        $settings->save();

        AuditLog::recordPlatform($request->user(), 'platform.settings_updated', null, ['minAgentVersionFrom' => $before, 'minAgentVersionTo' => $settings->min_agent_version]);

        return response()->json(['minAgentVersion' => $settings->min_agent_version]);
    }
}
