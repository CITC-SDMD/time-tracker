<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Computes who's visible to whom under the OIC -> Project Manager -> Team Leader ->
 * individual-contributor hierarchy (docs/DEVELOPMENT_PLAN.md §9.1). One office, so
 * the whole `users` table is small — this loads just `id, manager_id` for everyone
 * (one indexed query) and walks the tree in PHP rather than reaching for a MySQL
 * recursive CTE. Simple to read, simple to unit test, and fast enough at this scale;
 * revisit only if the office genuinely outgrows an in-memory walk.
 */
class HierarchyService
{
    /**
     * Every user id visible to $user: themselves, plus — if they hold a manager role
     * — everyone below them in the tree, at any depth.
     *
     * @return list<int>
     */
    public function visibleUserIds(User $user): array
    {
        if (! $user->isManagerRole()) {
            return [$user->id];
        }

        return [$user->id, ...$this->allDescendantIds($user->id)];
    }

    public function isVisible(User $viewer, int $targetUserId): bool
    {
        return in_array($targetUserId, $this->visibleUserIds($viewer), true);
    }

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

    /**
     * @return array<int, list<int>> manager_id => [direct report ids]
     */
    private function childrenByManagerId(): array
    {
        $rows = DB::table('users')->select('id', 'manager_id')->get();

        $byManager = [];
        foreach ($rows as $row) {
            if ($row->manager_id !== null) {
                $byManager[$row->manager_id][] = $row->id;
            }
        }

        return $byManager;
    }
}
