<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Folds a newly stored session into daily_summaries (docs/DEVELOPMENT_PLAN.md §10.1).
// Must run inside the caller's transaction, and only for sessions that were actually
// inserted — that is what keeps a re-uploaded batch from being counted twice.
class SummaryService
{
    /** The office-timezone calendar day (Y-m-d) a moment falls on. */
    public function dayOf(CarbonInterface $moment, string $timezone): string
    {
        return $moment->copy()->setTimezone($timezone)->format('Y-m-d');
    }

    /** Lowercase process name, ".exe" removed, anything outside a-z 0-9 _ turned into "_". */
    public function appKey(?string $processName, ?string $appName): string
    {
        $base = strtolower($processName ?: $appName ?: 'unknown');
        $base = preg_replace('/\.exe$/', '', $base);

        return preg_replace('/[^a-z0-9_]/', '_', $base) ?: 'unknown';
    }

    /**
     * Split a session at office-timezone midnights: seconds per day, summing exactly to
     * $durationSeconds (any rounding remainder goes to the last day).
     *
     * @return array<string, int> Y-m-d => seconds
     */
    public function splitByDay(CarbonInterface $startedAt, CarbonInterface $endedAt, int $durationSeconds, string $timezone): array
    {
        $cursor = $startedAt->copy()->setTimezone($timezone);
        $end = $endedAt->copy()->setTimezone($timezone);
        $parts = [];
        $assigned = 0;

        while ($cursor->format('Y-m-d') < $end->format('Y-m-d')) {
            $midnight = $cursor->copy()->addDay()->startOfDay();
            $untilMidnight = (float) $midnight->format('U.u') - (float) $cursor->format('U.u');
            $seconds = max(0, min($durationSeconds - $assigned, (int) round($untilMidnight)));
            $parts[$cursor->format('Y-m-d')] = $seconds;
            $assigned += $seconds;
            $cursor = $midnight;
        }
        $parts[$cursor->format('Y-m-d')] = max(0, $durationSeconds - $assigned);

        return $parts;
    }

    public function add(
        int $userId,
        string $type,
        ?string $appKey,
        ?string $appName,
        CarbonInterface $startedAt,
        CarbonInterface $endedAt,
        int $durationSeconds,
        string $timezone,
    ): void {
        foreach ($this->splitByDay($startedAt, $endedAt, $durationSeconds, $timezone) as $day => $seconds) {
            if ($seconds <= 0) {
                continue;
            }
            $this->addToDay($userId, $day, $type, $appKey, $appName, $seconds, $startedAt, $endedAt);
        }
    }

    /**
     * Puts every session of the organization on its day in $timezone and rebuilds the daily totals from the sessions,
     * one person at a time (each in its own transaction, so a sync of that person waits a moment rather than being
     * lost). The sessions are the source of truth and keep their moments in UTC, so the result is exact.
     */
    public function rebuildForOrganization(int $organizationId, string $timezone): void
    {
        foreach (DB::table('users')->where('organization_id', $organizationId)->pluck('id') as $userId) {
            DB::transaction(function () use ($userId, $timezone) {
                DB::table('daily_summaries')->where('user_id', $userId)->lockForUpdate()->get();
                DB::table('daily_summaries')->where('user_id', $userId)->delete();

                DB::table('sessions')->where('user_id', $userId)->chunkById(500, function ($sessions) use ($userId, $timezone) {
                    foreach ($sessions as $session) {
                        $started = Carbon::parse($session->started_at, 'UTC');
                        $day = $this->dayOf($started, $timezone);
                        if ($session->day !== $day) {
                            DB::table('sessions')->where('id', $session->id)->update(['day' => $day]);
                        }
                        $this->add(
                            $userId, $session->type, $session->app_key, $session->app_name,
                            $started, Carbon::parse($session->ended_at, 'UTC'), (int) $session->duration_seconds, $timezone,
                        );
                    }
                }, 'id');
            });
        }
    }

    private function addToDay(int $userId, string $day, string $type, ?string $appKey, ?string $appName, int $seconds, CarbonInterface $startedAt, CarbonInterface $endedAt): void
    {
        $row = $this->lockedRow($userId, $day);

        if ($row === null) {
            try {
                DB::table('daily_summaries')->insert([
                    'organization_id' => DB::table('users')->where('id', $userId)->value('organization_id'),
                    'user_id' => $userId,
                    'day' => $day,
                    'apps' => '{}',
                    'app_names' => '{}',
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another request created the row first; lock and use theirs.
            }
            $row = $this->lockedRow($userId, $day);
        }

        $apps = json_decode($row->apps ?: '{}', true) ?: [];
        $names = json_decode($row->app_names ?: '{}', true) ?: [];
        if ($type === 'application' && $appKey !== null) {
            $apps[$appKey] = ($apps[$appKey] ?? 0) + $seconds;
            if ($appName !== null) {
                $names[$appKey] = $appName;
            }
        }

        $first = $row->first_activity_at ? Carbon::parse($row->first_activity_at, 'UTC') : null;
        $last = $row->last_activity_at ? Carbon::parse($row->last_activity_at, 'UTC') : null;
        $start = $startedAt->copy()->utc();
        $end = $endedAt->copy()->utc();

        DB::table('daily_summaries')->where('user_id', $userId)->where('day', $day)->update([
            'tracked_seconds' => $row->tracked_seconds + $seconds,
            'active_seconds' => $row->active_seconds + ($type === 'application' ? $seconds : 0),
            'idle_seconds' => $row->idle_seconds + ($type === 'idle' ? $seconds : 0),
            'apps' => json_encode((object) $apps),
            'app_names' => json_encode((object) $names),
            'first_activity_at' => ($first === null || $start->lt($first) ? $start : $first)->format('Y-m-d H:i:s.v'),
            'last_activity_at' => ($last === null || $end->gt($last) ? $end : $last)->format('Y-m-d H:i:s.v'),
        ]);
    }

    private function lockedRow(int $userId, string $day): ?object
    {
        return DB::table('daily_summaries')
            ->where('user_id', $userId)
            ->where('day', $day)
            ->lockForUpdate()
            ->first();
    }
}
