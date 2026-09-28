<?php

namespace App\Services;

use App\Models\Task;
use App\Models\User;

/**
 * The one place that resolves tasks for the agent sync (docs/DEVELOPMENT_PLAN.md): the active tasks a person is
 * assigned to, and whether a task id the desktop app sent for a session is still one of them. A task that has
 * been unassigned or archived since the app last synced is never a reason to reject a session — the id is just
 * dropped (see AgentController::parseSession).
 */
class TaskService
{
    public function __construct(private AccessService $access) {}

    /** @return list<array{id: string, title: string}> */
    public function assignedActiveTasksFor(User $user): array
    {
        return Task::query()
            ->where('status', 'active')
            ->whereHas('assignees', fn ($q) => $q->whereKey($user->id))
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (Task $task) => ['id' => (string) $task->id, 'title' => $task->title])
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
}
