<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Screenshot;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoHierarchySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// the development office used for the live tests: it must build, be safe to run twice, and
// give the OIC a dashboard with every live state in it.
class DemoHierarchySeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('screenshots'); // the demo pictures must not land in the real folder
    }

    public function test_it_makes_a_few_screenshots_for_the_gallery_and_replaces_them_when_run_again(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoHierarchySeeder::class);
        $this->seed(DemoHierarchySeeder::class);

        $dev1 = User::where('email', 'dev1@test.com')->first();
        $this->assertSame(6, Screenshot::where('user_id', $dev1->id)->count());
        $this->assertSame(12, Screenshot::count());
        $this->assertSame(12, DB::table('media')->count());
    }

    public function test_it_builds_the_office_and_can_be_run_again(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoHierarchySeeder::class);
        $sessions = DB::table('sessions')->count();
        $this->seed(DemoHierarchySeeder::class);

        $demo = Organization::where('slug', 'demo-office')->firstOrFail();
        $inDemo = fn (string $role) => User::withoutGlobalScopes()->where('organization_id', $demo->id)
            ->whereHas('role', fn ($q) => $q->withoutGlobalScopes()->where('name', $role))->count();
        $this->assertSame(1, $inDemo('Admin'));
        $this->assertSame(2, $inDemo('Project Manager'));
        $this->assertSame(3, $inDemo('Team Leader'));
        $this->assertSame(6, $inDemo('Lead Developer') + $inDemo('Developer') + $inDemo('QA') + $inDemo('Client Support') + $inDemo('System Analyst'));
        $this->assertSame($sessions, DB::table('sessions')->count());
        $this->assertSame(0, User::withoutGlobalScopes()->where('organization_id', $demo->id)->whereNull('manager_id')->count() - 1); // only the admin has no manager
        $this->assertGreaterThan(300, DB::table('daily_summaries')->count());
    }

    public function test_the_oic_sees_every_live_state_and_a_team_leader_only_their_team(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoHierarchySeeder::class);

        $office = collect($this->actingAs(User::where('email', 'oic@test.com')->first(), 'sanctum')->getJson('/api/v1/employees')->assertOk()->json());
        $this->assertCount(12, $office);
        $this->assertEqualsCanonicalizing(['active', 'idle', 'paused', 'not_tracking', 'offline'], $office->pluck('status')->unique()->all());

        $team = collect($this->actingAs(User::where('email', 'tl1@test.com')->first(), 'sanctum')->getJson('/api/v1/employees')->assertOk()->json());
        $this->assertEqualsCanonicalizing(['Tina Cruz', 'Dan Ramos', 'Dana Uy'], $team->pluck('name')->all());
    }

    public function test_a_seeded_timeline_has_blocks_for_today(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoHierarchySeeder::class);
        $oic = User::where('email', 'oic@test.com')->first();
        $dev = User::where('email', 'dev1@test.com')->first();
        $today = now('Asia/Manila')->format('Y-m-d');

        $timeline = $this->actingAs($oic, 'sanctum')->getJson("/api/v1/employees/{$dev->id}/timeline?day={$today}")->assertOk()->json();

        $this->assertNotEmpty($timeline['segments']);
    }
}
