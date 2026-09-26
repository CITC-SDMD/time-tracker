<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §10, §12 Phase 6: /employees, /employees/{id}/summary and
// /employees/{id}/timeline, scoped by the caller's place in the hierarchy.
class DashboardEmployeesTest extends TestCase
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

        $this->oic = User::factory()->oic()->create(['name' => 'Olive']);
        $this->pm = User::factory()->projectManager($this->oic)->create(['name' => 'Pat']);
        $this->tlA = User::factory()->teamLeader($this->pm)->create(['name' => 'Tina A']);
        $this->tlB = User::factory()->teamLeader($this->pm)->create(['name' => 'Tom B']);
        $this->devA1 = User::factory()->individualContributor($this->tlA)->create(['name' => 'Dev A1']);
        $this->devA2 = User::factory()->individualContributor($this->tlA)->create(['name' => 'Dev A2']);
        $this->devB1 = User::factory()->individualContributor($this->tlB)->create(['name' => 'Dev B1']);
    }

    private function names(User $viewer, string $query = ''): array
    {
        return collect($this->actingAs($viewer, 'sanctum')->getJson('/api/v1/employees'.$query)->assertOk()->json())
            ->pluck('name')->all();
    }

    public function test_each_tier_sees_only_itself_and_the_people_below(): void
    {
        $this->assertEqualsCanonicalizing(['Tina A', 'Dev A1', 'Dev A2'], $this->names($this->tlA));
        $this->assertEqualsCanonicalizing(['Tom B', 'Dev B1'], $this->names($this->tlB));
        $this->assertCount(6, $this->names($this->pm));
        $this->assertCount(7, $this->names($this->oic));
    }

    public function test_each_person_carries_the_name_of_their_manager(): void
    {
        $rows = collect($this->actingAs($this->oic, 'sanctum')->getJson('/api/v1/employees')->assertOk()->json())->keyBy('name');

        $this->assertNull($rows['Olive']['managerId']);
        $this->assertNull($rows['Olive']['managerName']);
        $this->assertSame('Pat', $rows['Tina A']['managerName']);
        $this->assertSame((string) $this->tlA->id, $rows['Dev A1']['managerId']);
        $this->assertSame('Tina A', $rows['Dev A1']['managerName']);
        $this->assertNotEmpty($rows['Dev A1']['createdAt']);
    }

    public function test_deactivated_people_are_left_out_unless_asked_for(): void
    {
        $this->devA2->forceFill(['status' => 'inactive'])->save();

        $this->assertNotContains('Dev A2', $this->names($this->tlA));
        $this->assertContains('Dev A2', $this->names($this->tlA, '?includeDeactivated=1'));
    }

    public function test_status_is_offline_when_last_seen_is_over_five_minutes_ago(): void
    {
        $this->liveStatus($this->devA1, 'active', now()->subMinutes(2));
        $this->liveStatus($this->devA2, 'active', now()->subMinutes(9));

        $rows = collect($this->actingAs($this->tlA, 'sanctum')->getJson('/api/v1/employees')->json())->keyBy('name');

        $this->assertSame('active', $rows['Dev A1']['status']);
        $this->assertSame('offline', $rows['Dev A2']['status']);
        $this->assertSame('not_tracking', $rows['Tina A']['status']);
    }

    public function test_todays_totals_use_the_office_timezone_day(): void
    {
        $today = now('Asia/Manila')->format('Y-m-d');
        $yesterday = now('Asia/Manila')->subDay()->format('Y-m-d');
        $this->summary($this->devA1, $today, 600, 500, 100);
        $this->summary($this->devA1, $yesterday, 9999, 9999, 0);

        $rows = collect($this->actingAs($this->tlA, 'sanctum')->getJson('/api/v1/employees')->json())->keyBy('name');

        $this->assertSame(600, $rows['Dev A1']['trackedSeconds']);
        $this->assertSame(500, $rows['Dev A1']['activeSeconds']);
        $this->assertSame(100, $rows['Dev A1']['idleSeconds']);
    }

    public function test_query_count_does_not_grow_with_the_number_of_people(): void
    {
        $this->actingAs($this->tlA, 'sanctum');
        // the first request also loads the caller's role and organization; measure the ones after it
        $this->getJson('/api/v1/employees')->assertOk();
        DB::enableQueryLog();
        $this->getJson('/api/v1/employees')->assertOk();
        $small = count(DB::getQueryLog());

        foreach (range(1, 30) as $i) {
            $dev = User::factory()->individualContributor($this->tlA)->create();
            $this->liveStatus($dev, 'active', now());
            $this->summary($dev, now('Asia/Manila')->format('Y-m-d'), 60, 60, 0);
        }
        DB::flushQueryLog();
        $this->getJson('/api/v1/employees')->assertOk();

        $this->assertSame($small, count(DB::getQueryLog()));
    }

    public function test_a_manager_can_read_a_report_summary_but_not_another_teams(): void
    {
        $day = '2026-09-20';
        $this->summary($this->devA1, $day, 300, 200, 100, ['code' => 200], ['code' => 'VS Code']);
        $this->summary($this->devB1, $day, 300, 300, 0);
        $url = "?from=$day&to=$day";

        $this->actingAs($this->tlA, 'sanctum')
            ->getJson("/api/v1/employees/{$this->devA1->id}/summary$url")
            ->assertOk()
            ->assertJsonPath('0.activeSeconds', 200)
            ->assertJsonPath('0.apps.code', 200)
            ->assertJsonPath('0.appNames.code', 'VS Code');

        $this->actingAs($this->tlA, 'sanctum')
            ->getJson("/api/v1/employees/{$this->devB1->id}/summary$url")
            ->assertForbidden();
        $this->actingAs($this->tlA, 'sanctum')
            ->getJson("/api/v1/employees/{$this->devB1->id}/timeline?day=$day")
            ->assertForbidden();

        $this->actingAs($this->pm, 'sanctum')
            ->getJson("/api/v1/employees/{$this->devB1->id}/summary$url")
            ->assertOk();
    }

    public function test_someone_without_permissions_sees_only_their_own_data(): void
    {
        $this->actingAs($this->devA1, 'sanctum');

        // their own row, their own day and timeline: always allowed
        $this->getJson('/api/v1/employees')->assertOk()->assertJsonCount(1);
        $this->getJson("/api/v1/employees/{$this->devA1->id}/summary?from=2026-09-20&to=2026-09-20")->assertOk();
        $this->getJson("/api/v1/employees/{$this->devA1->id}/timeline?day=2026-09-20")->assertOk();

        // anybody else's, even a teammate's, needs the permission and the reach
        $this->getJson("/api/v1/employees/{$this->devA2->id}/summary?from=2026-09-20&to=2026-09-20")->assertForbidden();
        $this->getJson("/api/v1/employees/{$this->devA2->id}/timeline?day=2026-09-20")->assertForbidden();
        $this->getJson('/api/v1/reports/daily?from=2026-09-20&to=2026-09-20')->assertForbidden()->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    public function test_a_deactivated_manager_is_refused(): void
    {
        $this->tlA->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($this->tlA, 'sanctum')
            ->getJson('/api/v1/employees')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_DEACTIVATED');
    }

    public function test_settings_and_audit_need_their_own_permissions(): void
    {
        foreach ([$this->pm, $this->tlA] as $viewer) {
            $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/admin/settings')->assertForbidden()->assertJsonPath('error.code', 'PERMISSION_DENIED');
            $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/admin/audit')->assertForbidden()->assertJsonPath('error.code', 'PERMISSION_DENIED');
        }
        $this->actingAs($this->oic, 'sanctum')->getJson('/api/v1/admin/settings')->assertOk();
    }

    public function test_summary_range_is_limited_to_31_days(): void
    {
        $this->actingAs($this->tlA, 'sanctum')
            ->getJson("/api/v1/employees/{$this->devA1->id}/summary?from=2026-08-01&to=2026-09-05")
            ->assertStatus(422);
        $this->actingAs($this->tlA, 'sanctum')
            ->getJson("/api/v1/employees/{$this->devA1->id}/summary?from=2026-08-01&to=2026-08-31")
            ->assertOk();
    }

    public function test_timeline_merges_chunks_and_uses_the_office_day(): void
    {
        // Manila is UTC+8: 2026-09-20 in Manila runs 2026-09-19 16:00Z to 2026-09-20 16:00Z.
        $this->addSession($this->devA1, 'application', 'VS Code', 'a.ts', '2026-09-20 01:00:00', '2026-09-20 01:10:00');
        $this->addSession($this->devA1, 'application', 'VS Code', 'b.ts', '2026-09-20 01:10:00', '2026-09-20 01:30:00');
        $this->addSession($this->devA1, 'idle', null, null, '2026-09-20 01:30:00', '2026-09-20 01:40:00', 'VS Code');
        $this->addSession($this->devA1, 'application', 'Chrome', 'Docs', '2026-09-20 01:41:00', '2026-09-20 01:50:00');
        // Belongs to the next Manila day.
        $this->addSession($this->devA1, 'application', 'Chrome', 'x', '2026-09-20 17:00:00', '2026-09-20 17:10:00');

        $json = $this->actingAs($this->tlA, 'sanctum')
            ->getJson("/api/v1/employees/{$this->devA1->id}/timeline?day=2026-09-20")
            ->assertOk()->json();

        $this->assertCount(3, $json['segments']);
        $this->assertSame('VS Code', $json['segments'][0]['label']);
        $this->assertSame(1800, $json['segments'][0]['durationSeconds']);
        $this->assertSame('b.ts', $json['segments'][0]['title']); // longest chunk wins
        $this->assertSame('idle', $json['segments'][1]['kind']);
        $this->assertSame('Idle (in VS Code)', $json['segments'][1]['label']);
        $this->assertSame('Chrome', $json['segments'][2]['label']);
        $this->assertNull($json['nextCursor']);
        $this->assertSame('2026-09-20 01:00:00', Carbon::parse($json['firstActivityAt'])->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-20 01:50:00', Carbon::parse($json['lastActivityAt'])->utc()->format('Y-m-d H:i:s'));
    }

    public function test_timeline_cuts_a_session_that_crosses_midnight(): void
    {
        // 23:50 to 00:20 Manila = 15:50Z to 16:20Z.
        $this->addSession($this->devA1, 'application', 'VS Code', 't', '2026-09-20 15:50:00', '2026-09-20 16:20:00');

        $first = $this->actingAs($this->tlA, 'sanctum')
            ->getJson("/api/v1/employees/{$this->devA1->id}/timeline?day=2026-09-20")->json('segments');
        $second = $this->actingAs($this->tlA, 'sanctum')
            ->getJson("/api/v1/employees/{$this->devA1->id}/timeline?day=2026-09-21")->json('segments');

        $this->assertSame(600, $first[0]['durationSeconds']);
        $this->assertSame(1200, $second[0]['durationSeconds']);
    }

    public function test_timeline_pages_by_cursor_without_splitting_blocks(): void
    {
        // 600 alternating blocks (apps differ, so none merge).
        $rows = [];
        $base = strtotime('2026-09-20 00:00:00 UTC');
        foreach (range(0, 599) as $i) {
            $rows[] = $this->sessionRow($this->devA1, 'application', $i % 2 ? 'B' : 'A', null,
                gmdate('Y-m-d H:i:s', $base + $i * 10), gmdate('Y-m-d H:i:s', $base + $i * 10 + 9), null);
        }
        DB::table('sessions')->insert($rows);

        $page1 = $this->actingAs($this->tlA, 'sanctum')
            ->getJson("/api/v1/employees/{$this->devA1->id}/timeline?day=2026-09-20")->json();
        $this->assertCount(500, $page1['segments']);
        $this->assertNotNull($page1['nextCursor']);

        $page2 = $this->actingAs($this->tlA, 'sanctum')
            ->getJson("/api/v1/employees/{$this->devA1->id}/timeline?day=2026-09-20&cursor={$page1['nextCursor']}")->json();
        $this->assertCount(100, $page2['segments']);
        $this->assertNull($page2['nextCursor']);
        $this->assertNotSame($page1['segments'][499]['startedAt'], $page2['segments'][0]['startedAt']);
    }

    public function test_viewing_someone_elses_timeline_is_audited_once_and_your_own_never(): void
    {
        $url = "/api/v1/employees/{$this->devA1->id}/timeline?day=2026-09-20";

        $this->actingAs($this->tlA, 'sanctum')->getJson($url)->assertOk();
        $this->actingAs($this->tlA, 'sanctum')->getJson($url)->assertOk();
        $this->actingAs($this->tlA, 'sanctum')->getJson("/api/v1/employees/{$this->tlA->id}/timeline?day=2026-09-20")->assertOk();

        $entries = AuditLog::where('action', 'timeline.viewed')->get();
        $this->assertCount(1, $entries);
        $this->assertSame($this->tlA->id, $entries[0]->actor_user_id);
        $this->assertSame($this->devA1->id, $entries[0]->target_user_id);
        $this->assertSame('2026-09-20', $entries[0]->details['day']);
    }

    // ---- helpers -------------------------------------------------------------------------

    private function liveStatus(User $user, string $state, $lastSeen): void
    {
        DB::table('employee_statuses')->insert([
            'organization_id' => $user->organization_id, 'user_id' => $user->id, 'state' => $state, 'since' => $lastSeen, 'last_seen_at' => $lastSeen,
        ]);
    }

    /** @param array<string, int> $apps @param array<string, string> $names */
    private function summary(User $user, string $day, int $tracked, int $active, int $idle, array $apps = [], array $names = []): void
    {
        DB::table('daily_summaries')->insert([
            'organization_id' => $user->organization_id, 'user_id' => $user->id, 'day' => $day, 'tracked_seconds' => $tracked, 'active_seconds' => $active,
            'idle_seconds' => $idle, 'apps' => json_encode((object) $apps), 'app_names' => json_encode((object) $names),
        ]);
    }

    private function addSession(User $user, string $type, ?string $app, ?string $title, string $from, string $to, ?string $idleApp = null): void
    {
        DB::table('sessions')->insert($this->sessionRow($user, $type, $app, $title, $from, $to, $idleApp));
    }

    /** @return array<string, mixed> */
    private function sessionRow(User $user, string $type, ?string $app, ?string $title, string $from, string $to, ?string $idleApp): array
    {
        return [
            'id' => (string) Str::uuid(), 'organization_id' => $user->organization_id, 'user_id' => $user->id, 'device_id' => (string) Str::uuid(),
            'type' => $type, 'app_name' => $app, 'app_key' => $app ? strtolower($app) : null,
            'window_title' => $title, 'idle_app_name' => $idleApp,
            'started_at' => $from, 'ended_at' => $to,
            'duration_seconds' => strtotime($to.' UTC') - strtotime($from.' UTC'),
            'day' => substr($from, 0, 10), 'received_at' => now(),
        ];
    }
}
