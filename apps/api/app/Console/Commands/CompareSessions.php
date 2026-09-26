<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// Test 7.4 of the plan: every session id a desktop app marks as sent must be in the server's `sessions` table, under
// that person, exactly once. Export the ids from the PC (docs/PILOT_CHECKLIST.md, section 5), one per line, and compare
// them here. Only reads: nothing is changed.
class CompareSessions extends Command
{
    protected $signature = 'tracker:compare-sessions {file : text file with one session id per line} {user : the person\'s id}';

    protected $description = 'Check that session ids exported from a desktop app are all on the server, under that person';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::INVALID;
        }

        $person = User::withoutGlobalScopes()->find((int) $this->argument('user'));
        if ($person === null) {
            $this->error('There is no such person.');

            return self::INVALID;
        }

        $lines = array_values(array_filter(array_map('trim', file($path, FILE_IGNORE_NEW_LINES) ?: []), fn ($line) => $line !== ''));
        $ids = array_map('strtolower', $lines);
        $inFileTwice = array_keys(array_filter(array_count_values($ids), fn ($n) => $n > 1));
        $unique = array_values(array_unique($ids));

        $onServer = [];
        foreach (array_chunk($unique, 500) as $chunk) {
            foreach (DB::table('sessions')->whereIn('id', $chunk)->get(['id', 'user_id']) as $row) {
                $onServer[strtolower($row->id)] = (int) $row->user_id;
            }
        }

        $missing = array_values(array_filter($unique, fn ($id) => ! isset($onServer[$id])));
        $wrongOwner = array_values(array_filter($unique, fn ($id) => isset($onServer[$id]) && $onServer[$id] !== $person->id));

        $this->line(count($unique).' ids in the file, '.(count($unique) - count($missing)).' found on the server.');
        foreach (['missing on the server' => $missing, 'on the server under another person' => $wrongOwner, 'twice in the file' => $inFileTwice] as $what => $list) {
            if ($list !== []) {
                $this->error(count($list)." {$what}:");
                $this->line('  '.implode("\n  ", array_slice($list, 0, 20)).(count($list) > 20 ? "\n  ..." : ''));
            }
        }

        $clean = $missing === [] && $wrongOwner === [] && $inFileTwice === [];
        if ($clean) {
            $this->info('0 missing, 0 extra: every sent session is on the server exactly once.');
        }

        return $clean ? self::SUCCESS : self::FAILURE;
    }
}
