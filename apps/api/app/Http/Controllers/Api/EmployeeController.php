<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DailySummary;
use App\Models\OrganizationSetting;
use App\Models\User;
use App\Services\AccessService;
use App\Services\TimelineService;
use App\Support\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// GET /api/v1/employees, /employees/{id}/summary and /employees/{id}/timeline
// (docs/DEVELOPMENT_PLAN.md §10, §10.3, §12 Phase 6). The per-person routes are behind `self-or-visible`, so both
// the list and the 403 checks use the same AccessService reach and can never disagree.
class EmployeeController extends Controller
{
    private const TIMELINE_PAGE = 500;

    private const MAX_SUMMARY_DAYS = 31;

    // A manager opening the same person/day again within this long is one "viewed" entry.
    private const VIEW_AUDIT_WINDOW_MINUTES = 10;

    public function __construct(
        private AccessService $access,
        private TimelineService $timelines,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $caller = $request->user();
        // a superadmin outside an office has no people to list (inside one, the office routes are used)
        if ($caller->isSuperadmin() && app(OrganizationContext::class)->id() === null) {
            return response()->json([]);
        }

        $timezone = OrganizationSetting::current()->timezone;
        $today = now($timezone)->format('Y-m-d');

        // Someone without the permission to see people still gets their own row (their own overview).
        $ids = $this->access->can($caller, 'people.view') ? $this->access->visibleUserIds($caller) : [$caller->id];

        // Three eager loads (manager, status, today's summary) on top of the users query: the number of
        // queries does not grow with the number of people (§10.3, Test 6.12).
        $query = User::whereIn('id', $ids)
            ->with(['role:id,name', 'manager:id,name', 'employeeStatus', 'dailySummaries' => fn ($q) => $q->where('day', $today)])
            ->orderBy('name');

        if (! $request->boolean('includeDeactivated')) {
            $query->where('status', 'active');
        }

        $employees = $query->get()->map(function (User $employee) {
            $status = $employee->employeeStatus;
            $today = $employee->dailySummaries->first();

            $state = $status?->state ?? 'not_tracking';
            $isStale = $status && $status->last_seen_at->lt(now()->subMinutes(5));
            if ($isStale && in_array($state, ['active', 'idle', 'paused'], true)) {
                $state = 'offline';
            }

            return [
                'id' => (string) $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
                'role' => $employee->role?->name ?? '',
                'roleId' => $employee->role_id === null ? null : (string) $employee->role_id,
                'accountStatus' => $employee->status,
                'inviteFailed' => $employee->invite_failed_at !== null,
                'managerId' => $employee->manager_id === null ? null : (string) $employee->manager_id,
                'managerName' => $employee->manager?->name,
                'createdAt' => $employee->created_at?->toIso8601String(),
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

    public function summary(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = CarbonImmutable::createFromFormat('Y-m-d', $request->string('from')->toString());
        $to = CarbonImmutable::createFromFormat('Y-m-d', $request->string('to')->toString());
        if ($from->diffInDays($to) + 1 > self::MAX_SUMMARY_DAYS) {
            return response()->json([
                'error' => ['code' => 'RANGE_TOO_LONG', 'message' => 'Ask for at most '.self::MAX_SUMMARY_DAYS.' days at a time.'],
            ], 422);
        }

        $days = DailySummary::where('user_id', $id)
            ->whereBetween('day', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->orderBy('day')
            ->get()
            ->map(fn (DailySummary $row) => [
                'day' => $row->day->format('Y-m-d'),
                'trackedSeconds' => $row->tracked_seconds,
                'activeSeconds' => $row->active_seconds,
                'idleSeconds' => $row->idle_seconds,
                'apps' => (object) ($row->apps ?? []),
                'appNames' => (object) ($row->app_names ?? []),
                'firstActivityAt' => $row->first_activity_at?->toIso8601String(),
                'lastActivityAt' => $row->last_activity_at?->toIso8601String(),
            ]);

        return response()->json($days);
    }

    public function timeline(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'day' => ['required', 'date_format:Y-m-d'],
            'cursor' => ['nullable', 'integer', 'min:0'],
        ]);

        $day = $request->string('day')->toString();
        $timezone = OrganizationSetting::current()->timezone;
        $blocks = $this->timelines->forDay($id, $day, $timezone);

        // Cursor = start of the last block already delivered, in epoch milliseconds. The whole
        // day is merged on every call, so a block that would straddle a page edge is never split.
        $cursor = $request->filled('cursor') ? $request->integer('cursor') : null;
        $remaining = $cursor === null
            ? $blocks
            : array_values(array_filter($blocks, fn (array $b) => $this->millis($b['started']) > $cursor));
        $page = array_slice($remaining, 0, self::TIMELINE_PAGE);
        $hasMore = count($remaining) > self::TIMELINE_PAGE;

        if ($cursor === null) {
            $this->auditView($request->user(), $id, $day);
        }

        return response()->json([
            'day' => $day,
            'firstActivityAt' => $blocks ? $blocks[0]['started']->toIso8601String() : null,
            'lastActivityAt' => $blocks ? end($blocks)['ended']->toIso8601String() : null,
            'segments' => array_map(fn (array $b) => [
                'kind' => $b['kind'],
                'label' => $b['label'],
                'appName' => $b['appName'],
                'idleAppName' => $b['idleAppName'],
                'title' => $b['title'],
                'startedAt' => $b['started']->toIso8601String(),
                'endedAt' => $b['ended']->toIso8601String(),
                'durationSeconds' => $b['durationSeconds'],
            ], $page),
            'nextCursor' => $hasMore ? $this->millis(end($page)['started']) : null,
        ]);
    }

    private function auditView(User $viewer, int $targetId, string $day): void
    {
        // looking at your own day, or a superadmin looking inside an organization, is not logged
        if ($viewer->id === $targetId || $viewer->isSuperadmin()) {
            return;
        }

        $recent = AuditLog::where('actor_user_id', $viewer->id)
            ->where('target_user_id', $targetId)
            ->where('action', 'timeline.viewed')
            ->where('created_at', '>=', now()->subMinutes(self::VIEW_AUDIT_WINDOW_MINUTES))
            ->get()
            ->contains(fn (AuditLog $entry) => ($entry->details['day'] ?? null) === $day);

        if (! $recent) {
            AuditLog::record($viewer, 'timeline.viewed', User::find($targetId), ['day' => $day]);
        }
    }

    private function millis(CarbonImmutable $moment): int
    {
        return (int) $moment->format('Uv');
    }
}
