<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * The one place that resolves tasks for the agent sync (docs/DEVELOPMENT_PLAN.md): the active tasks a person is
 * assigned to, and whether a task id the desktop app sent for a session is still one of them. A task that has
 * been unassigned or archived since the app last synced is never a reason to reject a session — the id is just
 * dropped (see AgentController::parseSession).
 */
class TaskService
{
    public function __construct(private AccessService $access) {}

    /** @return list<array{id: string, title: string, completed: bool, dueDate: ?string, overdue: bool}> */
    public function assignedActiveTasksFor(User $user): array
    {
        $today = Carbon::today();

        return Task::query()
            ->where('status', 'active')
            ->whereHas('assignees', fn ($q) => $q->whereKey($user->id))
            ->with(['assignees' => fn ($q) => $q->whereKey($user->id)])
            ->orderBy('title')
            ->get()
            ->map(fn (Task $task) => [
                'id' => (string) $task->id,
                'title' => $task->title,
                'completed' => $task->assignees->first()?->pivot->completed_at !== null,
                'dueDate' => $task->due_date?->toDateString(),
                // every task here is already status=active, so overdue is just "due date has passed"
                'overdue' => $task->due_date !== null && $task->due_date->lt($today),
            ])
            ->all();
    }

    /** The task id the agent sent for a session, or null when it is not usable (missing, archived, not assigned to $user). */
    public function resolveTaskId(User $user, mixed $raw): ?int
    {
        if (! is_int($raw) && ! (is_string($raw) && ctype_digit($raw))) {
            return null;
        }
        $id = (int) $raw;

        $usable = Task::query()
            ->where('status', 'active')
            ->whereKey($id)
            ->whereHas('assignees', fn ($q) => $q->whereKey($user->id))
            ->exists();

        return $usable ? $id : null;
    }

    /** Whether $caller may assign a task to every one of $userIds: nobody is handed a task for someone out of reach. @param list<int> $userIds */
    public function canAssignTo(User $caller, array $userIds): bool
    {
        return array_diff($userIds, $this->access->visibleUserIds($caller)) === [];
    }

    /**
     * Completion is per person: $user marks (or reopens) their own assignment of an active task. Reach and
     * permission (when it is a manager doing this for someone else) are the caller's job, not this method's —
     * this only checks the task is still usable for $user, the same rule `resolveTaskId` applies. Returns
     * whether anything actually changed, so the caller only audits real transitions.
     */
    public function markCompleted(User $user, int $taskId, bool $completed): bool
    {
        $task = Task::query()
            ->where('status', 'active')
            ->whereKey($taskId)
            ->whereHas('assignees', fn ($q) => $q->whereKey($user->id))
            ->first();
        if ($task === null) {
            return false;
        }

        $assignment = $task->assignees()->where('users.id', $user->id)->first();
        $wasCompleted = $assignment?->pivot->completed_at !== null;
        if ($wasCompleted === $completed) {
            return false;
        }

        $task->assignees()->updateExistingPivot($user->id, ['completed_at' => $completed ? now() : null]);

        return true;
    }
}
