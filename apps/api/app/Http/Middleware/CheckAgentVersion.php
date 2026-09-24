<?php

namespace App\Http\Middleware;

use App\Models\OfficeSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// docs/DEVELOPMENT_PLAN.md §10.1 step 1: an agent older than office_settings.min_agent_version
// gets 426 so it can show "Please update the app" while it keeps tracking locally.
class CheckAgentVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $version = (string) $request->header('X-Agent-Version', '');
        $minimum = OfficeSetting::current()->min_agent_version;

        if ($version === '' || version_compare($version, $minimum, '<')) {
            return response()->json([
                'error' => ['code' => 'UPGRADE_REQUIRED', 'message' => "Please update the app (version {$minimum} or newer is required)."],
            ], 426);
        }

        return $next($request);
    }
}
