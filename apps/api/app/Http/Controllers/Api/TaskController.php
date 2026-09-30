<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Task;
use App\Models\User;
use App\Services\AccessService;
use App\Services\TaskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

// GET/POST /api/v1/tasks, PATCH /api/v1/tasks/{id} (docs/DEVELOPMENT_PLAN.md). Managing tasks (`tasks.manage`) is
// organization-wide, like roles: a task can be assigned across teams, so it is not something a team-scoped role
// owns part of. Assigning is done here too, as part of create/update — a task is never assigned to someone
// outside the caller's reach (App\Services\TaskService::canAssignTo).
class TaskController extends Controller
{
    public function __construct(private TaskService $tasks, private AccessService $access) {}

    /** GET /api/v1/tasks */
    public function index(): JsonResponse
    {
        $tasks = Task::withCount('assignees')->with('assignees:id,name')
            ->orderByRaw("status = 'archived'")->orderBy('title')->get();

        return response()->json($tasks->map(fn (Task $task) => $this->payload($task))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $caller = $request->user();
        $assigneeIds = array_values(array_unique(array_map('intval', $data['assigneeIds'] ?? [])));
        if (! $this->tasks->canAssignTo($caller, $assigneeIds)) {
            return $this->refuse(403, 'FORBIDDEN', 'One or more of those people are not in your reach.');
        }

        $task = new Task;
        $task->title = trim($data['title']);
        $task->description = $data['description'] ?? null;
        $task->status = $data['status'] ?? 'active';
        $task->due_date = $data['dueDate'] ?? null;
        $task->created_by = $caller->id;
        $task->save();

        $this->syncAssignees($task, $assigneeIds, $caller, previous: []);

        AuditLog::record($caller, 'task.created', null, ['taskId' => $task->id, 'title' => $task->title]);

        return response()->json($this->payload($task->fresh()->loadCount('assignees')->load('assignees:id,name')), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $task = Task::find($id);
        if ($task === null) {
            return $this->refuse(404, 'NOT_FOUND', 'Not found.');
        }
        $caller = $request->user();
        $data = $this->validated($request, partial: true);

        $assigneeIds = null;
        if (array_key_exists('assigneeIds', $data)) {
            $assigneeIds = array_values(array_unique(array_map('intval', $data['assigneeIds'])));
            if (! $this->tasks->canAssignTo($caller, $assigneeIds)) {
                return $this->refuse(403, 'FORBIDDEN', 'One or more of those people are not in your reach.');
            }
        }

        $before = ['title' => $task->title, 'status' => $task->status];
        if (isset($data['title'])) {
            $task->title = trim($data['title']);
        }
        if (array_key_exists('description', $data)) {
            $task->description = $data['description'];
        }
        if (isset($data['status'])) {
            $task->status = $data['status'];
        }
        if (array_key_exists('dueDate', $data)) {
            $task->due_date = $data['dueDate'];
        }
        $task->save();

        if ($before['title'] !== $task->title || $before['status'] !== $task->status) {
            AuditLog::record($caller, 'task.updated', null, [
                'taskId' => $task->id,
                'title' => $task->title,
                ...($before['status'] !== $task->status ? ['statusFrom' => $before['status'], 'statusTo' => $task->status] : []),
            ]);
        }

        if ($assigneeIds !== null) {
            $previous = $task->assignees()->pluck('users.id')->all();
            $this->syncAssignees($task, $assigneeIds, $caller, previous: $previous);
        }

        return response()->json($this->payload($task->fresh()->loadCount('assignees')->load('assignees:id,name')));
    }

    /**
     * PATCH /api/v1/tasks/{id}/assignments/{userId}: a manager marks (or reopens) someone in their reach's
     * assignment of a task -- the same per-person flag the employee sets from the agent (over sync, since a
     * desktop token cannot call this route: see LimitAgentToken).
     */
    public function completeAssignment(Request $request, int $id, int $userId): JsonResponse
    {
        $task = Task::find($id);
        if ($task === null) {
            return $this->refuse(404, 'NOT_FOUND', 'Not found.');
        }
        $employee = User::find($userId);
        $caller = $request->user();
        if ($employee === null || ! $task->assignees()->where('users.id', $userId)->exists()) {
            return $this->refuse(404, 'NOT_FOUND', 'Not found.');
        }
        if (! $this->access->isVisible($caller, $userId)) {
            return $this->refuse(403, 'FORBIDDEN', 'This person is not in your reach.');
        }

        $data = $request->validate(['completed' => ['required', 'boolean']]);
        $changed = $this->tasks->markCompleted($employee, $id, $data['completed']);
        if ($changed) {
            AuditLog::record($caller, $data['completed'] ? 'task.completed' : 'task.reopened', $employee, ['taskId' => $task->id, 'title' => $task->title]);
        }

        return response()->json($this->payload($task->fresh()->loadCount('assignees')->load('assignees:id,name')));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $sometimes = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$sometimes, 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'archived'])],
            'dueDate' => ['sometimes', 'nullable', 'date'],
            'assigneeIds' => ['sometimes', 'array'],
            'assigneeIds.*' => ['integer'],
        ]);
    }

    /** @param list<int> $ids @param list<int> $previous */
    private function syncAssignees(Task $task, array $ids, User $caller, array $previous): void
    {
        $now = Carbon::now();
        $task->assignees()->sync(collect($ids)->mapWithKeys(fn (int $id) => [$id => [
            'organization_id' => $task->organization_id,
            'assigned_by' => $caller->id,
            'assigned_at' => $now,
        ]])->all());

        $added = array_diff($ids, $previous);
        $removed = array_diff($previous, $ids);
        if ($added !== []) {
            foreach (User::whereIn('id', $added)->get() as $user) {
                AuditLog::record($caller, 'task.assigned', $user, ['taskId' => $task->id, 'title' => $task->title]);
            }
        }
        if ($removed !== []) {
            foreach (User::whereIn('id', $removed)->get() as $user) {
                AuditLog::record($caller, 'task.unassigned', $user, ['taskId' => $task->id, 'title' => $task->title]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function payload(Task $task): array
    {
        // 'assignees' loaded with names and the pivot (id,name only) when eager-loaded; falls back to a plain
        // query (ids only, no names) for a caller that never loaded the relation.
        $assignees = $task->relationLoaded('assignees')
            ? $task->assignees
            : $task->assignees()->get(['users.id', 'users.name']);

        return [
            'id' => (string) $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'dueDate' => $task->due_date?->toDateString(),
            'overdue' => $task->status === 'active' && $task->due_date !== null && $task->due_date->lt(Carbon::today()),
            'assigneeCount' => (int) ($task->assignees_count ?? $assignees->count()),
            'completedCount' => $assignees->filter(fn (User $u) => $u->pivot->completed_at !== null)->count(),
            'assigneeIds' => $assignees->pluck('id')->map(fn ($id) => (string) $id)->all(),
            'assignees' => $assignees->map(fn (User $u) => [
                'id' => (string) $u->id,
                'name' => $u->name,
                'completedAt' => $u->pivot->completed_at ? Carbon::parse($u->pivot->completed_at)->utc()->toIso8601ZuluString() : null,
            ])->values()->all(),
        ];
    }

    private function refuse(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
