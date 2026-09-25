<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OfficeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §12 Phase 11: /reports/daily, /reports/apps and /reports/team, limited to
// the people the caller can see, capped at 92 days, with a CSV download of the same rows.
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private User $oic;

    private User $pm;

    private User $tlA;

    private User $tlB;

    private User $devA1;

    private User $devA2;

    private User $devB1;

    protected function setUp(): void
    {
        parent::setUp();
        OfficeSetting::create([
            'id' => 1,
            'timezone' => 'Asia/Manila',
            'idle_threshold_seconds' => 300,
            'window_title_mode' => 'full',
            'min_agent_version' => '0.1.0',
            'consent_version' => 1,
        ]);

        $this->oic = User::factory()->oic()->create(['name' => 'Olive']);
        $this->pm = User::factory()->projectManager($this->oic)->create(['name' => 'Pat']);
        $this->tlA = User::factory()->teamLeader($this->pm)->create(['name' => 'Tina A']);
        $this->tlB = User::factory()->teamLeader($this->pm)->create(['name' => 'Tom B']);
        $this->devA1 = User::factory()->individualContributor($this->tlA)->create(['name' => 'Dev A1']);
        $this->devA2 = User::factory()->individualContributor($this->tlA)->create(['name' => 'Dev A2']);
        $this->devB1 = User::factory()->individualContributor($this->tlB)->create(['name' => 'Dev B1']);
    }

    private function report(User $viewer, string $path, string $query = 'from=2026-09-01&to=2026-09-07')
    {
        return $this->actingAs($viewer, 'sanctum')->get("/api/v1/reports/{$path}?{$query}", ['Accept' => 'application/json']);
    }

    /** @param array<string, int> $apps @param array<string, string> $names */
    private function summary(User $user, string $day, int $tracked, int $active, int $idle, array $apps = [], array $names = [], ?string $first = null, ?string $last = null): void
    {
        DB::table('daily_summaries')->insert([
            'user_id' => $user->id, 'day' => $day, 'tracked_seconds' => $tracked, 'active_seconds' => $active,
            'idle_seconds' => $idle, 'apps' => json_encode((object) $apps), 'app_names' => json_encode((object) $names),
            'first_activity_at' => $first, 'last_activity_at' => $last,
        ]);
    }

    private function seedWeek(): void
    {
        $this->summary($this->devA1, '2026-09-01', 28800, 25200, 3600, ['code' => 20000, 'chrome' => 5200], ['code' => 'Visual Studio Code', 'chrome' => 'Google Chrome']);
        $this->summary($this->devA1, '2026-09-02', 14400, 14000, 400, ['code' => 14000], ['code' => 'Visual Studio Code']);
        $this->summary($this->devA2, '2026-09-01', 7200, 7000, 200, ['chrome' => 7000], ['chrome' => 'Google Chrome']);
        $this->summary($this->devB1, '2026-09-01', 3600, 3000, 600, ['slack' => 3000], ['slack' => 'Slack']);
        // outside the asked range
        $this->summary($this->devA1, '2026-08-31', 99999, 99999, 0, ['code' => 99999], ['code' => 'Visual Studio Code']);
    }

    public function test_the_daily_report_has_one_row_per_person_per_day_within_the_callers_scope(): void
    {
        $this->seedWeek();

        $office = $this->report($this->oic, 'daily')->assertOk()->json();
        $this->assertSame(
            [['2026-09-01', 'Dev A1'], ['2026-09-01', 'Dev A2'], ['2026-09-01', 'Dev B1'], ['2026-09-02', 'Dev A1']],
            array_map(fn ($r) => [$r['day'], $r['name']], $office),
        );
        $this->assertSame(28800, $office[0]['trackedSeconds']);
        $this->assertSame(25200, $office[0]['activeSeconds']);
        $this->assertSame(3600, $office[0]['idleSeconds']);

        $team = $this->report($this->tlA, 'daily')->assertOk()->json();
        $this->assertSame(['Dev A1', 'Dev A2', 'Dev A1'], array_column($team, 'name'));
    }

    public function test_a_person_outside_the_callers_hierarchy_cannot_be_asked_for(): void
    {
        $this->seedWeek();

        $this->report($this->tlA, 'daily', "from=2026-09-01&to=2026-09-07&uid={$this->devB1->id}")->assertForbidden();
        $this->report($this->tlA, 'apps', "from=2026-09-01&to=2026-09-07&uid={$this->devB1->id}")->assertForbidden();
        $this->report($this->tlA, 'daily', "from=2026-09-01&to=2026-09-07&uid={$this->devA2->id}")->assertOk()->assertJsonCount(1);
        // one's own rows are always allowed
        $this->report($this->tlA, 'daily', "from=2026-09-01&to=2026-09-07&uid={$this->tlA->id}")->assertOk();
    }

    public function test_individual_contributors_get_nothing(): void
    {
        $this->seedWeek();

        foreach (['daily', 'apps', 'team'] as $report) {
            $this->report($this->devA1, $report)->assertForbidden();
        }
    }

    public function test_the_range_is_validated_and_capped_at_92_days(): void
    {
        $this->report($this->oic, 'daily', 'from=2026-01-01&to=2026-04-02')->assertOk(); // exactly 92 days
        $this->report($this->oic, 'daily', 'from=2026-01-01&to=2026-04-03')->assertStatus(422)->assertJsonPath('error.code', 'RANGE_TOO_LONG');
        $this->report($this->oic, 'daily', 'from=2026-09-07&to=2026-09-01')->assertStatus(422);
        $this->report($this->oic, 'daily', 'from=nonsense&to=2026-09-01')->assertStatus(422);
        $this->report($this->oic, 'daily', 'to=2026-09-01')->assertStatus(422);
        $this->report($this->oic, 'daily', 'from=2026-09-01&to=2026-09-07&format=xml')->assertStatus(422);
    }

    public function test_the_app_report_adds_up_active_time_per_app_across_people(): void
    {
        $this->seedWeek();

        $rows = $this->report($this->oic, 'apps')->assertOk()->json();

        $this->assertSame(['Visual Studio Code', 'Google Chrome', 'Slack'], array_column($rows, 'app'));
        $this->assertSame(34000, $rows[0]['seconds']); // 20000 + 14000, and not the day outside the range
        $this->assertSame(1, $rows[0]['people']);
        $this->assertSame(12200, $rows[1]['seconds']); // 5200 + 7000
        $this->assertSame(2, $rows[1]['people']);

        $team = $this->report($this->tlB, 'apps')->assertOk()->json();
        $this->assertSame(['Slack'], array_column($team, 'app'));
    }

    public function test_the_team_report_lists_everyone_in_scope_including_people_with_no_time(): void
    {
        $this->seedWeek();

        $rows = collect($this->report($this->tlA, 'team')->assertOk()->json())->keyBy('name');

        $this->assertEqualsCanonicalizing(['Tina A', 'Dev A1', 'Dev A2'], $rows->keys()->all());
        $this->assertSame(2, $rows['Dev A1']['daysTracked']);
        $this->assertSame(43200, $rows['Dev A1']['trackedSeconds']);
        $this->assertSame(21600, $rows['Dev A1']['averageTrackedSeconds']);
        $this->assertSame('Tina A', $rows['Dev A1']['managerName']);
        $this->assertSame(0, $rows['Tina A']['trackedSeconds']);
        $this->assertSame(0, $rows['Tina A']['daysTracked']);
        $this->assertSame('Pat', $rows['Tina A']['managerName']);
    }

    public function test_the_query_count_does_not_grow_with_the_number_of_people(): void
    {
        $this->seedWeek();
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->report($this->oic, 'team')->assertOk();

            return count(DB::getQueryLog());
        };
        $small = $count();

        foreach (range(1, 10) as $i) {
            $person = User::factory()->individualContributor($this->tlB)->create();
            $this->summary($person, '2026-09-03', 3600, 3000, 600);
        }

        $this->assertSame($small, $count());
    }

    public function test_the_csv_download_has_hours_and_seconds_office_time_and_is_recorded(): void
    {
        $this->summary($this->devA1, '2026-09-01', 28800, 25200, 3600, ['code' => 25200], ['code' => 'Visual Studio Code'], '2026-09-01 00:30:00', '2026-09-01 09:45:00');

        $response = $this->report($this->oic, 'daily', 'from=2026-09-01&to=2026-09-07&format=csv');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('daily-report-2026-09-01-to-2026-09-07.csv', $response->headers->get('Content-Disposition'));

        $body = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $lines = array_map('str_getcsv', explode("\n", trim(substr($body, 3))));
        $this->assertSame('Tracked (HH:MM)', $lines[0][4]);
        // 00:30 UTC is 08:30 in Manila, 09:45 UTC is 17:45
        $this->assertSame(['2026-09-01', 'Dev A1', $this->devA1->email, 'developer', '08:00', '28800', '07:00', '25200', '01:00', '3600', '08:30', '17:45'], $lines[1]);

        $entry = AuditLog::where('action', 'report.exported')->firstOrFail();
        $this->assertSame($this->oic->id, $entry->actor_user_id);
        $this->assertSame(['report' => 'daily', 'from' => '2026-09-01', 'to' => '2026-09-07'], $entry->details);
    }

    public function test_the_json_view_is_not_written_to_the_audit_log(): void
    {
        $this->report($this->oic, 'daily')->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'report.exported')->count());
    }

    public function test_csv_cells_that_would_run_as_formulas_are_neutralised(): void
    {
        $this->devA1->forceFill(['name' => '=HYPERLINK("http://evil.example","click")'])->save();
        $this->summary($this->devA1, '2026-09-01', 3600, 3000, 600, ['x' => 3000], ['x' => '@SUM(A1)']);

        $daily = $this->report($this->oic, 'daily', 'from=2026-09-01&to=2026-09-07&format=csv')->streamedContent();
        $apps = $this->report($this->oic, 'apps', 'from=2026-09-01&to=2026-09-07&format=csv')->streamedContent();

        $this->assertStringContainsString("\"'=HYPERLINK", $daily);
        $this->assertStringNotContainsString('"=HYPERLINK', $daily);
        $this->assertStringContainsString("'@SUM(A1)", $apps);
    }

    public function test_the_team_and_app_csv_files_have_their_own_columns(): void
    {
        $this->seedWeek();

        $team = str_getcsv(explode("\n", $this->report($this->oic, 'team', 'from=2026-09-01&to=2026-09-07&format=csv')->streamedContent())[0]);
        $apps = str_getcsv(explode("\n", $this->report($this->oic, 'apps', 'from=2026-09-01&to=2026-09-07&format=csv')->streamedContent())[0]);

        $this->assertSame("\xEF\xBB\xBFName", $team[0]);
        $this->assertContains('Days tracked', $team);
        $this->assertSame("\xEF\xBB\xBFApplication", $apps[0]);
        $this->assertSame(2, AuditLog::where('action', 'report.exported')->count());
    }
}
