<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

// The activity check (docs/DEVELOPMENT_PLAN.md §16): decides from the counts a desktop app sent whether a day looks like
// input made by software or a jiggler, and says why in plain words. It never changes tracked time: the result is a
// level (none, review, strong) and reasons for a manager to look into. All numbers are in config/integrity.php.
class IntegrityService
{
    /** The input counts a chunk may carry, and the largest value accepted for each (anything else is dropped). */
    public const STAT_LIMITS = [
        'hwKeys' => 1_000_000, 'swKeys' => 1_000_000,
        'hwMouse' => 10_000_000, 'swMouse' => 10_000_000,
        'hwClicks' => 1_000_000, 'swClicks' => 1_000_000,
        'mouseDistancePx' => 1_000_000_000,
        'tinyMoveShare' => 100, 'moveIntervalCv' => 100_000,
        'swOnlySeconds' => 660,
    ];

    /**
     * Keeps only the known counts, as whole numbers inside their limits; null when nothing usable was sent.
     *
     * @return array<string, int>|null
     */
    public function cleanStats(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        $clean = [];
        foreach (self::STAT_LIMITS as $key => $max) {
            $value = $raw[$key] ?? null;
            if (is_int($value) && $value >= 0 && $value <= $max) {
                $clean[$key] = $value;
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Only the names of the known list (as a whole or as a beginning), in the list's own spelling.
     *
     * @return list<string>
     */
    public function knownTools(mixed $reported): array
    {
        if (! is_array($reported)) {
            return [];
        }
        $known = config('integrity.macro_tools');
        $found = [];
        foreach ($reported as $name) {
            if (! is_string($name)) {
                continue;
            }
            $name = strtolower(trim($name));
            foreach ($known as $tool) {
                if ($name === $tool || str_starts_with($name, $tool)) {
                    $found[$tool] = true;
                }
            }
        }

        return array_slice(array_keys($found), 0, 10);
    }

    /**
     * @param  list<array{type: string, duration: int, app_key: ?string, window_title: ?string, stats: ?array<string, int>}>  $chunks
     * @param  list<string>  $macroTools  known macro programs seen running that day
     * @return array{level: string, reasons: list<array{code: string, message: string, minutes: ?int}>}|null
     *                                                                                                       null when there is nothing to judge
     */
    public function evaluate(array $chunks, ?string $environment, array $macroTools): ?array
    {
        $withStats = array_values(array_filter($chunks, fn (array $c) => $c['stats'] !== null));
        $remote = in_array($environment, ['virtual_machine', 'remote_session'], true);
        if ($withStats === [] && $macroTools === [] && ! $remote) {
            return null;
        }

        $cfg = config('integrity');
        $note = $remote ? ' This can also come from remote-control or accessibility software.' : '';
        $strong = [];
        $medium = [];
        $weak = [];

        // software input: chunks where most events were sent by software
        $softwareSeconds = 0;
        foreach ($withStats as $c) {
            $s = $c['stats'];
            $software = ($s['swKeys'] ?? 0) + ($s['swMouse'] ?? 0) + ($s['swClicks'] ?? 0);
            $events = $software + ($s['hwKeys'] ?? 0) + ($s['hwMouse'] ?? 0) + ($s['hwClicks'] ?? 0);
            if ($events >= $cfg['software_input']['min_events'] && $software / $events >= $cfg['software_input']['min_share']) {
                $softwareSeconds += $c['duration'];
            }
        }
        if ($softwareSeconds >= $cfg['software_input']['min_minutes'] * 60) {
            $strong[] = $this->reason('software_input', 'Most mouse and keyboard input during '.$this->minutes($softwareSeconds).' minutes was sent by software, not by a keyboard or mouse.'.$note, $softwareSeconds);
        }

        // the system saw activity but no hardware input at all
        $withoutHardware = array_sum(array_map(fn (array $c) => $c['stats']['swOnlySeconds'] ?? 0, $withStats));
        if ($withoutHardware >= $cfg['input_without_hardware']['min_minutes'] * 60) {
            $strong[] = $this->reason('input_without_hardware', 'The computer registered activity for '.$this->minutes($withoutHardware).' minutes while no input arrived from a keyboard or mouse.'.$note, $withoutHardware);
        }

        if ($macroTools !== []) {
            $medium[] = $this->reason('macro_tool_running', 'A program made to automate input was running while tracking: '.implode(', ', $macroTools).'.', null);
        }

        // tiny, perfectly regular mouse movement with almost no typing or clicking
        $r = $cfg['robotic_pattern'];
        $roboticSeconds = 0;
        foreach ($withStats as $c) {
            $s = $c['stats'];
            $mouse = ($s['hwMouse'] ?? 0) + ($s['swMouse'] ?? 0);
            $keys = ($s['hwKeys'] ?? 0) + ($s['swKeys'] ?? 0);
            $clicks = ($s['hwClicks'] ?? 0) + ($s['swClicks'] ?? 0);
            if ($mouse >= $r['min_mouse_events'] && isset($s['moveIntervalCv'], $s['tinyMoveShare'])
                && $s['moveIntervalCv'] <= $r['max_interval_cv'] && $s['tinyMoveShare'] >= $r['min_tiny_move_share']
                && $keys <= $r['max_keys'] && $clicks <= $r['max_clicks']) {
                $roboticSeconds += $c['duration'];
            }
        }
        if ($roboticSeconds >= $r['min_minutes'] * 60) {
            $medium[] = $this->reason('robotic_pattern', 'For '.$this->minutes($roboticSeconds).' minutes the mouse moved in tiny, perfectly regular steps with almost no clicking or typing, like a jiggler.', $roboticSeconds);
        }

        // hours of mouse movement only, in one unchanging window
        $m = $cfg['mouse_only_hours'];
        $mouseOnlySeconds = 0;
        $windows = [];
        foreach ($withStats as $c) {
            $s = $c['stats'];
            $keys = ($s['hwKeys'] ?? 0) + ($s['swKeys'] ?? 0);
            $clicks = ($s['hwClicks'] ?? 0) + ($s['swClicks'] ?? 0);
            $mouse = ($s['hwMouse'] ?? 0) + ($s['swMouse'] ?? 0);
            if ($c['type'] === 'application' && $mouse > 0 && $keys === 0 && $clicks <= $m['max_clicks_per_chunk']) {
                $mouseOnlySeconds += $c['duration'];
                $windows[($c['app_key'] ?? '').'|'.($c['window_title'] ?? '')] = true;
            }
        }
        if ($mouseOnlySeconds >= $m['min_minutes'] * 60 && count($windows) <= $m['max_distinct_windows']) {
            $weak[] = $this->reason('mouse_only_hours', 'For '.$this->minutes($mouseOnlySeconds).' minutes there was mouse movement only, with no typing and almost no clicks, in one unchanging window.', $mouseOnlySeconds);
        }

        if ($remote) {
            $weak[] = $this->reason('virtual_or_remote', 'The desktop app was running in a '.($environment === 'virtual_machine' ? 'virtual machine' : 'remote session').'.', null);
        }

        $lv = $cfg['levels'];
        $level = 'none';
        if (count($strong) >= $lv['strong_if_strong'] || count($medium) >= $lv['strong_if_medium']) {
            $level = 'strong';
            // input sent by software is what remote-control and accessibility tools do too: alone it is only a "review" there
            if ($remote && count($medium) < $lv['strong_if_medium']) {
                $level = 'review';
            }
        } elseif (count($medium) >= $lv['review_if_medium'] || count($weak) >= $lv['review_if_weak']) {
            $level = 'review';
        }

        return ['level' => $level, 'reasons' => [...$strong, ...$medium, ...$weak]];
    }

    /** Works out the level of one day from its stored chunks and saves it on the day's summary. */
    public function refreshDay(int $userId, string $day): void
    {
        $summary = DB::table('daily_summaries')->where('user_id', $userId)->where('day', $day)->first();
        if ($summary === null) {
            return;
        }

        // whereDate: the day of a session may be stored with a time part on some databases
        $chunks = DB::table('sessions')->where('user_id', $userId)->whereDate('day', $day)
            ->get(['type', 'duration_seconds', 'app_key', 'window_title', 'input_stats'])
            ->map(fn ($row) => [
                'type' => $row->type,
                'duration' => (int) $row->duration_seconds,
                'app_key' => $row->app_key,
                'window_title' => $row->window_title,
                'stats' => $row->input_stats ? json_decode($row->input_stats, true) : null,
            ])->all();

        $tools = $summary->macro_tools ? (json_decode($summary->macro_tools, true) ?: []) : [];
        $result = $this->evaluate($chunks, $summary->environment, $tools);

        DB::table('daily_summaries')->where('user_id', $userId)->where('day', $day)->update([
            'integrity_level' => $result['level'] ?? null,
            'integrity_reasons' => $result === null ? null : json_encode($result['reasons']),
        ]);
    }

    /** @return array{code: string, message: string, minutes: ?int} */
    private function reason(string $code, string $message, ?int $seconds): array
    {
        return ['code' => $code, 'message' => $message, 'minutes' => $seconds === null ? null : $this->minutes($seconds)];
    }

    private function minutes(int $seconds): int
    {
        return (int) round($seconds / 60);
    }
}
