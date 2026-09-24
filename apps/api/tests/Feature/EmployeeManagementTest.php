<?php

namespace Tests\Feature;

use App\Models\OfficeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §9.1, §10: AdminEmployeeController (create a direct report,
// deactivate/reactivate) and EmployeeController@index, all hierarchy-scoped.
class EmployeeManagementTest extends TestCase
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

    public function test_oic_can_create_a_project_manager_as_their_direct_report(): void
    {
        $oic = User::factory()->oic()->create();

        $response = $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'New PM',
            'email' => 'pm@example.com',
            'role' => 'PROJECT_MANAGER',
        ]);

        $response->assertCreated()->assertJsonPath('role', 'PROJECT_MANAGER');
        $this->assertDatabaseHas('users', [
            'email' => 'pm@example.com',
            'role' => 'PROJECT_MANAGER',
            'manager_id' => $oic->id,
        ]);
    }

    public function test_team_leader_can_create_any_individual_contributor_role(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();
        $tl = User::factory()->teamLeader($pm)->create();

        $response = $this->actingAs($tl, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'New QA',
            'email' => 'qa@example.com',
            'role' => 'QA',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'qa@example.com', 'role' => 'QA', 'manager_id' => $tl->id]);
    }

    public function test_cannot_create_a_role_more_than_one_tier_below(): void
    {
        $oic = User::factory()->oic()->create();

        $response = $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'Should fail',
            'email' => 'skip@example.com',
            'role' => 'TEAM_LEADER', // two tiers below OIC
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'skip@example.com']);
    }

    public function test_individual_contributor_cannot_create_accounts(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();
        $tl = User::factory()->teamLeader($pm)->create();
        $dev = User::factory()->individualContributor($tl)->create();

        $response = $this->actingAs($dev, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'Nope', 'email' => 'nope@example.com', 'role' => 'DEVELOPER',
        ]);

        $response->assertStatus(403);
    }

    public function test_duplicate_email_is_a_409_not_a_422(): void
    {
        $oic = User::factory()->oic()->create();
        $existing = User::factory()->create();

        $response = $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'Dupe', 'email' => $existing->email, 'role' => 'PROJECT_MANAGER',
        ]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'EMAIL_TAKEN');
    }

    public function test_employees_index_is_scoped_to_the_callers_hierarchy(): void
    {
        $oic = User::factory()->oic()->create();
        $pmA = User::factory()->projectManager($oic)->create();
        $tlA = User::factory()->teamLeader($pmA)->create();
        $devA = User::factory()->individualContributor($tlA)->create();
        $pmB = User::factory()->projectManager($oic)->create();
        User::factory()->individualContributor($pmB)->create();

        $response = $this->actingAs($pmA, 'sanctum')->getJson('/api/v1/employees');

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertEqualsCanonicalizing([(string) $tlA->id, (string) $devA->id], $ids->all());
    }

    public function test_manager_can_deactivate_someone_in_their_hierarchy_and_their_token_stops_working(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();
        $tl = User::factory()->teamLeader($pm)->create();
        $dev = User::factory()->individualContributor($tl)->create();
        $devToken = $dev->createToken('agent-test')->plainTextToken;

        $response = $this->actingAs($pm, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$dev->id}", ['status' => 'DEACTIVATED']);

        $response->assertOk()->assertJsonPath('status', 'DEACTIVATED');
        $this->assertSame('DEACTIVATED', $dev->fresh()->status);
        $this->assertNotNull($dev->fresh()->deactivated_at);

        // Without this, the sanctum guard's cached-user-per-instance behavior would
        // carry $pm's resolved identity over from the actingAs() call above into this
        // next request, instead of re-resolving from $devToken.
        auth()->forgetGuards();

        // 401, not 403: the controller revokes the token outright on deactivation
        // ($employee->tokens()->delete()), so Sanctum's own auth:sanctum middleware
        // rejects it before EnsureActiveUser ever runs (unlike AuthTest's scenario,
        // which deactivates without revoking and so gets 403 from EnsureActiveUser).
        $this->withHeader('Authorization', "Bearer {$devToken}")
            ->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_manager_cannot_deactivate_someone_outside_their_hierarchy(): void
    {
        $oic = User::factory()->oic()->create();
        $pmA = User::factory()->projectManager($oic)->create();
        $pmB = User::factory()->projectManager($oic)->create();
        $tlB = User::factory()->teamLeader($pmB)->create();

        $response = $this->actingAs($pmA, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$tlB->id}", ['status' => 'DEACTIVATED']);

        $response->assertStatus(403);
        $this->assertSame('ACTIVE', $tlB->fresh()->status);
    }

    public function test_manager_cannot_deactivate_themselves(): void
    {
        // Not OIC — a sole OIC self-targeting hits the more specific LAST_OIC guard
        // first (see test_sole_oic_cannot_deactivate_themselves below). This covers
        // the generic "not in scope for this endpoint" self-block that applies to
        // every other role.
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();

        $response = $this->actingAs($pm, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$pm->id}", ['status' => 'DEACTIVATED']);

        $response->assertStatus(400)->assertJsonPath('error.code', 'CANNOT_MODIFY_SELF');
    }

    public function test_sole_oic_cannot_deactivate_themselves(): void
    {
        $oic = User::factory()->oic()->create();

        // This is the realistic way the guard triggers: with only one OIC, the
        // LAST_OIC check fires ahead of the generic self-modification block, since
        // deactivating the sole OIC would leave zero active OICs.
        $response = $this->actingAs($oic, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$oic->id}", ['status' => 'DEACTIVATED']);

        $response->assertStatus(400)->assertJsonPath('error.code', 'LAST_OIC');
        $this->assertSame('ACTIVE', $oic->fresh()->status);
    }

    public function test_peer_oic_can_deactivate_another_oic_when_one_will_remain(): void
    {
        $oicA = User::factory()->oic()->create();
        $oicB = User::factory()->oic()->create();

        $response = $this->actingAs($oicA, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$oicB->id}", ['status' => 'DEACTIVATED']);

        $response->assertOk()->assertJsonPath('status', 'DEACTIVATED');
        $this->assertSame('DEACTIVATED', $oicB->fresh()->status);

        // Now A is the sole active OIC — the same self-deactivation guard applies.
        $selfAttempt = $this->actingAs($oicA, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$oicA->id}", ['status' => 'DEACTIVATED']);

        $selfAttempt->assertStatus(400)->assertJsonPath('error.code', 'LAST_OIC');
    }

    public function test_non_oic_manager_cannot_touch_an_oic_they_dont_manage(): void
    {
        $oicA = User::factory()->oic()->create();
        $oicB = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oicA)->create();

        $response = $this->actingAs($pm, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$oicB->id}", ['status' => 'DEACTIVATED']);

        $response->assertStatus(403);
        $this->assertSame('ACTIVE', $oicB->fresh()->status);
    }

    public function test_manager_can_reactivate(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->deactivated()->create();

        $response = $this->actingAs($oic, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$pm->id}", ['status' => 'ACTIVE']);

        $response->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->assertSame('ACTIVE', $pm->fresh()->status);
        $this->assertNull($pm->fresh()->deactivated_at);
    }
}
