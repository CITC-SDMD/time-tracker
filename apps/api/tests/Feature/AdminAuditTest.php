<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OfficeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §9.1, §10: GET /admin/audit, OIC-only, spans the whole
// hierarchy (§10 Test 2.14), newest first, 50 per page (Test 2.13).
class AdminAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OfficeSetting::create([
            'id' => 1,
            'timezone' => 'Asia/Manila',
            'idle_threshold_seconds' => 300,
            'window_title_mode' => 'FULL',
            'min_agent_version' => '0.1.0',
            'consent_version' => 1,
        ]);
    }

    public function test_oic_sees_audit_entries_from_the_whole_hierarchy_not_just_their_own_actions(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();
        $tl = User::factory()->teamLeader($pm)->create();

        AuditLog::record($oic, 'employee.created', $pm);
        AuditLog::record($pm, 'employee.created', $tl); // an action the OIC didn't take

        $response = $this->actingAs($oic, 'sanctum')->getJson('/api/v1/admin/audit');

        $response->assertOk();
        $actions = collect($response->json('entries'))->pluck('action');
        $this->assertEqualsCanonicalizing(['employee.created', 'employee.created'], $actions->all());
    }

    public function test_entries_are_ordered_newest_first(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();

        $first = AuditLog::record($oic, 'employee.created', $pm);
        $first->created_at = now()->subMinutes(5);
        $first->save();
        $second = AuditLog::record($oic, 'employee.deactivated', $pm);

        $response = $this->actingAs($oic, 'sanctum')->getJson('/api/v1/admin/audit');

        $ids = collect($response->json('entries'))->pluck('id');
        $this->assertSame([$second->id, $first->id], $ids->all());
    }

    public function test_pagination_cursor_returns_the_next_page(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();

        for ($i = 0; $i < 55; $i++) {
            AuditLog::record($oic, "employee.action.{$i}", $pm);
        }

        $firstPage = $this->actingAs($oic, 'sanctum')->getJson('/api/v1/admin/audit');
        $firstPage->assertOk();
        $this->assertCount(50, $firstPage->json('entries'));
        $cursor = $firstPage->json('nextCursor');
        $this->assertNotNull($cursor);

        $secondPage = $this->actingAs($oic, 'sanctum')->getJson("/api/v1/admin/audit?cursor={$cursor}");
        $secondPage->assertOk();
        $this->assertCount(5, $secondPage->json('entries'));
        $this->assertNull($secondPage->json('nextCursor'));

        $firstIds = collect($firstPage->json('entries'))->pluck('id');
        $secondIds = collect($secondPage->json('entries'))->pluck('id');
        $this->assertEmpty($firstIds->intersect($secondIds));
    }

    public function test_a_team_leader_cannot_read_the_audit_log(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();
        $tl = User::factory()->teamLeader($pm)->create();

        $this->actingAs($tl, 'sanctum')->getJson('/api/v1/admin/audit')->assertStatus(403);
    }

    public function test_employee_creation_and_deactivation_are_already_recorded_via_the_existing_controller(): void
    {
        $oic = User::factory()->oic()->create();

        $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'New PM', 'email' => 'pm@example.com', 'role' => 'PROJECT_MANAGER',
        ])->assertCreated();

        $response = $this->actingAs($oic, 'sanctum')->getJson('/api/v1/admin/audit');

        $response->assertOk()->assertJsonPath('entries.0.action', 'employee.created');
        $this->assertSame('New PM', $response->json('entries.0.targetName'));
    }
}
