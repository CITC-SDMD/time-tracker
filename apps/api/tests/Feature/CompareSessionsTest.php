<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Test 7.4 (docs/DEVELOPMENT_PLAN.md): compare the ids a desktop app sent with the server's sessions.
class CompareSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'organization_id' => $user->organization_id, 'user_id' => $user->id, 'device_id' => (string) Str::uuid(),
            'type' => 'application', 'app_name' => 'Editor', 'started_at' => now()->subHour(), 'ended_at' => now(),
            'duration_seconds' => 3600, 'day' => now()->toDateString(), 'received_at' => now(),
        ]);
    }

    private function file(array $ids): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ids');
        file_put_contents($path, implode("\r\n", $ids)."\r\n");

        return $path;
    }

    public function test_it_passes_when_every_id_is_on_the_server_under_that_person(): void
    {
        $user = User::factory()->create();
        $ids = [(string) Str::uuid(), (string) Str::uuid()];
        array_map(fn ($id) => $this->makeSession($user, $id), $ids);

        $this->artisan('tracker:compare-sessions', ['file' => $this->file($ids), 'user' => $user->id])
            ->expectsOutputToContain('0 missing, 0 extra')
            ->assertSuccessful();
    }

    public function test_it_names_missing_ids_ids_of_someone_else_and_ids_listed_twice(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        [$have, $missing, $theirs] = [(string) Str::uuid(), (string) Str::uuid(), (string) Str::uuid()];
        $this->makeSession($user, $have);
        $this->makeSession($other, $theirs);

        $this->artisan('tracker:compare-sessions', ['file' => $this->file([$have, $have, $missing, $theirs]), 'user' => $user->id])
            ->expectsOutputToContain('1 missing on the server')
            ->expectsOutputToContain('1 on the server under another person')
            ->expectsOutputToContain('1 twice in the file')
            ->assertFailed();
    }
}
