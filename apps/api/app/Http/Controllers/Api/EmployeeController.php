<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\HierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// GET /api/v1/employees (docs/DEVELOPMENT_PLAN.md §10, §10.3) — gated by the `manager`
// middleware at the route level. Summary/timeline routes (also listed in §10) are
// deferred to Phase 4/6, once sessions actually have data flowing into them; listing
// people and their (currently always-empty, pre-Phase-3) live status is buildable now.
class EmployeeController extends Controller
{
    public function __construct(private HierarchyService $hierarchy) {}

    public function index(Request $request): JsonResponse
    {
        $visibleIds = $this->hierarchy->allDescendantIds($request->user()->id);

        $employees = User::whereIn('id', $visibleIds)
            ->with(['employeeStatus', 'dailySummaries' => fn ($q) => $q->where('day', today())])
            ->orderBy('name')
            ->get()
            ->map(function (User $employee) {
                $status = $employee->employeeStatus;
                $today = $employee->dailySummaries->first();

                $state = $status?->state ?? 'NOT_TRACKING';
                $isStale = $status && $status->last_seen_at->lt(now()->subMinutes(5));
                if ($isStale && in_array($state, ['ACTIVE', 'IDLE', 'PAUSED'], true)) {
                    $state = 'OFFLINE';
                }

                return [
                    'id' => (string) $employee->id,
                    'name' => $employee->name,
                    'role' => $employee->role,
                    'status' => $state,
                    'trackedSeconds' => $today->tracked_seconds ?? 0,
                    'activeSeconds' => $today->active_seconds ?? 0,
                    'idleSeconds' => $today->idle_seconds ?? 0,
                    'currentApp' => $status?->current_app,
                    'lastActivityAt' => $status?->last_seen_at?->toIso8601String(),
                ];
            });

        return response()->json($employees);
    }
}
