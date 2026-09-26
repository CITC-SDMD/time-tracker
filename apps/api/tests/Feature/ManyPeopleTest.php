<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §14: the pages an admin opens must not get slower per person. The number of database queries a
// list needs is the same for 3 people and for 60: a query per person would show here long before it shows on a real server.
class ManyPeopleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{int, mixed} the number of queries the request ran, and the answer */
    private function queriesFor(User $admin, string $url): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($admin, 'sanctum')->getJson($url);
        $response->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$count, $response->json()];
    }

    private function addPeople(User $admin, int $count): void
    {
        $organizationId = $admin->organization_id;
        for ($i = 0; $i < $count; $i++) {
            $person = User::factory()->individualContributor($admin)->create();
            foreach (range(1, 5) as $d) {
                $day = sprintf('2026-09-%02d', 14 + $d);
                DB::table('daily_summaries')->insert([
                    'organization_id' => $organizationId, 'user_id' => $person->id, 'day' => $day,
                    'tracked_seconds' => 25000, 'active_seconds' => 20000, 'idle_seconds' => 5000, 'apps' => '{}', 'app_names' => '{}',
                ]);
            }
            DB::table('employee_statuses')->insert([
                'organization_id' => $organizationId, 'user_id' => $person->id, 'state' => 'active', 'since' => now(), 'last_seen_at' => now(),
            ]);
        }
    }

    public function test_the_lists_run_the_same_number_of_queries_for_a_few_people_and_for_many(): void
    {
        $admin = User::factory()->oic()->create();
        $this->addPeople($admin, 3);

        $urls = [
            '/api/v1/employees',
            '/api/v1/reports/team?from=2026-09-15&to=2026-09-19',
            '/api/v1/reports/daily?from=2026-09-15&to=2026-09-19',
            '/api/v1/reports/apps?from=2026-09-15&to=2026-09-19',
        ];

        $few = [];
        foreach ($urls as $url) {
            [$few[$url]] = $this->queriesFor($admin, $url);
        }

        $this->addPeople($admin, 57);

        foreach ($urls as $url) {
            [$many, $body] = $this->queriesFor($admin, $url);
            // a query or two of difference is noise (a setting read on the first call); one per person would be 57 more
            $this->assertLessThanOrEqual($few[$url] + 2, $many, "{$url} runs more queries as the office grows");
        }

    }
}
