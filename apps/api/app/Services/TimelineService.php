<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Builds the manager's timeline for one office-timezone day (docs/DEVELOPMENT_PLAN.md
// §12 Phase 6). The desktop app uploads 10-minute chunks, so consecutive chunks of the same
// app (or the same idle stretch) are merged into one block, with the same rule as the desktop's
// own timeline: same kind and app, and at most 5 seconds between them.
class TimelineService
{
    public const int MAX_GAP_SECONDS = 5;

    /**
     * Every merged block of the day, oldest first. A session that crosses midnight is cut at
     * the day's edges, matching how SummaryService splits its seconds between days.
     *
     * @return list<array{kind: string, label: string, appName: ?string, idleAppName: ?string, title: ?string, started: CarbonImmutable, ended: CarbonImmutable, durationSeconds: int}>
     */
    public function forDay(int $userId, string $day, string $timezone): array
    {
        $local = CarbonImmutable::createFromFormat('Y-m-d', $day, $timezone)->startOfDay();
        $start = $local->utc();
        $end = $local->addDay()->startOfDay()->utc();

        $rows = DB::table('sessions')
            ->where('user_id', $userId)
            ->where('started_at', '<', $end->format('Y-m-d H:i:s.v'))
            ->where('ended_at', '>', $start->format('Y-m-d H:i:s.v'))
            ->orderBy('started_at')
            ->orderBy('ended_at')
            ->get(['type', 'app_name', 'window_title', 'idle_app_name', 'started_at', 'ended_at']);

        $chunks = [];
        foreach ($rows as $row) {
            $started = CarbonImmutable::parse($row->started_at, 'UTC');
            $ended = CarbonImmutable::parse($row->ended_at, 'UTC');
            $chunks[] = [
                'type' => $row->type,
                'app_name' => $row->app_name,
                'window_title' => $row->window_title,
                'idle_app_name' => $row->idle_app_name,
                'started' => $started->lt($start) ? $start : $started,
                'ended' => $ended->gt($end) ? $end : $ended,
            ];
        }

        return $this->merge($chunks);
    }

    /**
     * @param  list<array{type: string, app_name: ?string, window_title: ?string, idle_app_name: ?string, started: CarbonImmutable, ended: CarbonImmutable}>  $chunks  oldest first
     * @return list<array{kind: string, label: string, appName: ?string, idleAppName: ?string, title: ?string, started: CarbonImmutable, ended: CarbonImmutable, durationSeconds: int}>
     */
    public function merge(array $chunks): array
    {
        $blocks = [];
        $current = null;
        $bestTitleSeconds = -1.0;

        foreach ($chunks as $chunk) {
            $idle = $chunk['type'] === 'idle';
            $key = $idle ? 'idle|'.($chunk['idle_app_name'] ?? '') : 'active|'.($chunk['app_name'] ?? '');
            $seconds = max(0.0, $this->seconds($chunk['ended']) - $this->seconds($chunk['started']));

            if ($current !== null
                && $current['key'] === $key
                && $this->seconds($chunk['started']) - $this->seconds($current['block']['ended']) <= self::MAX_GAP_SECONDS) {
                if ($chunk['ended']->gt($current['block']['ended'])) {
                    $current['block']['ended'] = $chunk['ended'];
                }
                $current['seconds'] += $seconds;
                if (! $idle && $seconds > $bestTitleSeconds) {
                    $current['block']['title'] = $chunk['window_title'];
                    $bestTitleSeconds = $seconds;
                }

                continue;
            }

            if ($current !== null) {
                $blocks[] = $this->finish($current);
            }

            $bestTitleSeconds = $seconds;
            $current = [
                'key' => $key,
                'seconds' => $seconds,
                'block' => [
                    'kind' => $idle ? 'idle' : 'active',
                    'label' => $idle
                        ? ($chunk['idle_app_name'] ? 'Idle (in '.$chunk['idle_app_name'].')' : 'Idle')
                        : ($chunk['app_name'] ?: 'Unknown'),
                    'appName' => $idle ? null : $chunk['app_name'],
                    'idleAppName' => $idle ? $chunk['idle_app_name'] : null,
                    'title' => $idle ? null : $chunk['window_title'],
                    'started' => $chunk['started'],
                    'ended' => $chunk['ended'],
                ],
            ];
        }

        if ($current !== null) {
            $blocks[] = $this->finish($current);
        }

        return $blocks;
    }

    /**
     * @param  array{seconds: float, block: array<string, mixed>}  $current
     * @return array<string, mixed>
     */
    private function finish(array $current): array
    {
        $current['block']['durationSeconds'] = (int) round($current['seconds']);

        return $current['block'];
    }

    private function seconds(CarbonImmutable $moment): float
    {
        return (float) $moment->format('U.u');
    }
}
