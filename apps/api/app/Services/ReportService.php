<?php

namespace App\Services;

use App\Models\DailySummary;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The three reports of docs/DEVELOPMENT_PLAN.md §12 Phase 11, built from `daily_summaries` only
 * (one row per person per day), so a 92-day range reads about people x days rows and never touches
 * the raw sessions. Who is included is decided by the caller: this class is given the user ids.
 */
class ReportService
{
    /** The longest range one request may ask for. */
    public const MAX_DAYS = 92;

    /**
     * One row per person per day.
     *
     * @param  list<int>  $userIds
     * @return list<array<string, mixed>>
     */
    public function daily(array $userIds, string $from, string $to): array
    {
        $people = $this->people($userIds);

        return $this->summaries($userIds, $from, $to)
            ->sortBy([['day', 'asc'], fn ($a, $b) => strcmp($people[$a->user_id]->name ?? '', $people[$b->user_id]->name ?? '')])
            ->map(fn (DailySummary $row) => [
                'day' => $row->day->format('Y-m-d'),
                'userId' => (string) $row->user_id,
                'name' => $people[$row->user_id]->name ?? '',
                'email' => $people[$row->user_id]->email ?? '',
                'role' => $people[$row->user_id]->role?->name ?? '',
                'trackedSeconds' => $row->tracked_seconds,
                'activeSeconds' => $row->active_seconds,
                'idleSeconds' => $row->idle_seconds,
                'firstActivityAt' => $row->first_activity_at?->toIso8601String(),
                'lastActivityAt' => $row->last_activity_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Active time per application over the range, biggest first, with how many people used it.
     *
     * @param  list<int>  $userIds
     * @return list<array<string, mixed>>
     */
    public function apps(array $userIds, string $from, string $to): array
    {
        $totals = [];
        foreach ($this->summaries($userIds, $from, $to) as $row) {
            foreach ($row->apps ?? [] as $key => $seconds) {
                $totals[$key] ??= ['app' => $row->app_names[$key] ?? $key, 'seconds' => 0, 'people' => []];
                $totals[$key]['seconds'] += $seconds;
                $totals[$key]['people'][$row->user_id] = true;
            }
        }

        $rows = array_map(fn (array $t) => ['app' => $t['app'], 'seconds' => $t['seconds'], 'people' => count($t['people'])], array_values($totals));
        usort($rows, fn (array $a, array $b) => $b['seconds'] <=> $a['seconds'] ?: strcmp($a['app'], $b['app']));

        return $rows;
    }

    /**
     * Everyone's totals over the range, one row per person (people with no tracked day are still
     * listed, with zeros, so an empty week is visible too).
     *
     * @param  list<int>  $userIds
     * @return list<array<string, mixed>>
     */
    public function team(array $userIds, string $from, string $to): array
    {
        $people = $this->people($userIds);
        $managers = User::whereIn('id', $people->pluck('manager_id')->filter()->unique())->pluck('name', 'id');
        $byUser = $this->summaries($userIds, $from, $to)->groupBy('user_id');

        return $people
            ->sortBy('name')
            ->map(function (User $person) use ($byUser, $managers) {
                $days = $byUser->get($person->id, collect());
                $daysTracked = $days->where('tracked_seconds', '>', 0)->count();
                $tracked = (int) $days->sum('tracked_seconds');

                return [
                    'userId' => (string) $person->id,
                    'name' => $person->name,
                    'email' => $person->email,
                    'role' => $person->role?->name ?? '',
                    'managerName' => $managers[$person->manager_id] ?? null,
                    'daysTracked' => $daysTracked,
                    'trackedSeconds' => $tracked,
                    'activeSeconds' => (int) $days->sum('active_seconds'),
                    'idleSeconds' => (int) $days->sum('idle_seconds'),
                    'averageTrackedSeconds' => $daysTracked > 0 ? intdiv($tracked, $daysTracked) : 0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, DailySummary>
     */
    private function summaries(array $userIds, string $from, string $to): Collection
    {
        return DailySummary::whereIn('user_id', $userIds)
            ->whereBetween('day', [$from, $to])
            ->get();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    private function people(array $userIds): Collection
    {
        return User::whereIn('id', $userIds)->with('role:id,name')->get(['id', 'name', 'email', 'role_id', 'manager_id'])->keyBy('id');
    }
}
