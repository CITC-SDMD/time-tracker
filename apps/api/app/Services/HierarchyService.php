<?php

namespace App\Services;

use App\Models\User;

/**
 * The reporting line of an organization: who reports to whom (docs/DEVELOPMENT_PLAN.md §9.1). It is a free
 * tree, any person may report to any other in the same organization, with no tier rule. Who may SEE whom is
 * decided by AccessService (a role reaches only itself, its team, or the organization); this class only walks
 * the tree. It reads `id, manager_id` of the people of the current organization (User is limited to it by the
 * organization context) in one query and walks the tree in PHP, which is simple to read and test.
 */
class HierarchyService
{
    /**
     * Every descendant of $userId (direct reports, their reports, and so on),
     * NOT including $userId itself.
     *
     * @return list<int>
     */
    public function allDescendantIds(int $userId): array
    {
        $childrenByManager = $this->childrenByManagerId();

        $descendants = [];
        $queue = $childrenByManager[$userId] ?? [];

        while ($queue !== []) {
            $childId = array_shift($queue);
            $descendants[] = $childId;
            foreach ($childrenByManager[$childId] ?? [] as $grandchildId) {
                $queue[] = $grandchildId;
            }
        }

        return $descendants;
    }

    /** Whether making $managerId the manager of $personId would put someone above themselves (a loop). */
    public function wouldCreateLoop(int $personId, int $managerId): bool
    {
        return $managerId === $personId || in_array($managerId, $this->allDescendantIds($personId), true);
    }

    /**
     * @return array<int, list<int>> manager_id => [direct report ids]
     */
    private function childrenByManagerId(): array
    {
        $byManager = [];
        foreach (User::query()->select('id', 'manager_id')->get() as $row) {
            if ($row->manager_id !== null) {
                $byManager[$row->manager_id][] = $row->id;
            }
        }

        return $byManager;
    }
}
