<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// PATCH /api/v1/platform/organizations/{organization}/people/{id}/detection (docs/DEVELOPMENT_PLAN.md §16): a superadmin
// with `organizations.detection.manage` switches the virtual machine detection on or off for one person of an
// organization. The middleware has already put the request inside the organization, so another organization's
// person is simply not found. Switching it off also clears what was stored, and the person's agent stops checking on
// its next sync.
class PersonDetectionController extends Controller
{
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $person = User::find($id);
        if ($person === null || $person->isSuperadmin()) {
            return response()->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Not found.']], 404);
        }

        $enabled = (bool) $data['enabled'];
        if ($person->detection_enabled !== $enabled) {
            $person->detection_enabled = $enabled;
            $person->save();

            if (! $enabled) {
                DB::table('employee_statuses')->where('user_id', $person->id)->update(['environment' => null]);
                DB::table('daily_summaries')->where('user_id', $person->id)->update(['environment' => null, 'integrity_level' => null, 'integrity_reasons' => null, 'macro_tools' => null]);
                DB::table('sessions')->where('user_id', $person->id)->whereNotNull('input_stats')->update(['input_stats' => null]);
            }
            AuditLog::record($request->user(), 'detection.toggled', $person, ['enabled' => $enabled]);
        }

        return response()->json(['id' => (string) $person->id, 'detectionEnabled' => $person->detection_enabled]);
    }
}
