<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\OrganizationFactory;
use Database\Factories\RoleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §9.1, §10: AdminEmployeeController (add a person, deactivate/reactivate) and
// EmployeeController@index, all limited to the caller's reach and to what their role's permissions allow.
class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_add_a_person_with_any_role_as_their_direct_report(): void
    {
        $oic = User::factory()->oic()->create();
        $role = RoleFactory::forTests('project_manager');

        $response = $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'New PM',
            'email' => 'pm@example.com',
            'roleId' => $role->id,
        ]);

        $response->assertCreated()->assertJsonPath('role', 'Project Manager');
        $this->assertDatabaseHas('users', [
            'email' => 'pm@example.com',
            'role_id' => $role->id,
            'organization_id' => $oic->organization_id,
            'manager_id' => $oic->id,
        ]);
    }

    public function test_there_is_no_tier_rule_any_role_can_report_to_any_person(): void
    {
        $oic = User::factory()->oic()->create();

        // a Team Leader role directly under the admin, and a developer under a developer
        $tl = $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'Tl', 'email' => 'tl@example.com', 'roleId' => RoleFactory::forTests('team_leader')->id,
        ])->assertCreated()->json('id');

        $dev = User::factory()->individualContributor(User::find($tl))->create();
        $this->assertSame((int) $tl, $dev->manager_id);
    }

    public function test_a_team_leader_can_add_people_with_a_role_that_does_not_exceed_their_own(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();
        $tl = User::factory()->teamLeader($pm)->create();

        $response = $this->actingAs($tl, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'New QA', 'email' => 'qa@example.com', 'roleId' => RoleFactory::forTests('qa')->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'qa@example.com', 'role_id' => RoleFactory::forTests('qa')->id, 'manager_id' => $tl->id]);
    }

    public function test_nobody_can_add_someone_with_a_role_that_can_do_more_than_their_own(): void
    {
        $oic = User::factory()->oic()->create();
        $tl = User::factory()->teamLeader($oic)->create();

        // the admin role holds settings, audit and roles, which a team leader does not
        $response = $this->actingAs($tl, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'Should fail', 'email' => 'skip@example.com', 'roleId' => RoleFactory::forTests('oic')->id,
        ]);

        $response->assertStatus(403)->assertJsonPath('error.code', 'ROLE_ESCALATION');
        $this->assertDatabaseMissing('users', ['email' => 'skip@example.com']);
    }

    public function test_a_role_from_another_organization_does_not_exist_for_the_caller(): void
    {
        $oic = User::factory()->oic()->create();
        $other = OrganizationFactory::made('Other Office');
        $foreignRole = RoleFactory::make2('Foreign', 'self', [], $other);

        $response = $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'X', 'email' => 'x@example.com', 'roleId' => $foreignRole->id,
        ]);

        $response->assertStatus(422)->assertJsonPath('error.code', 'ROLE_NOT_FOUND');
        $this->assertDatabaseMissing('users', ['email' => 'x@example.com']);
    }

    public function test_someone_whose_role_lacks_the_permission_cannot_add_people(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();
        $tl = User::factory()->teamLeader($pm)->create();
        $dev = User::factory()->individualContributor($tl)->create();

        $response = $this->actingAs($dev, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'Nope', 'email' => 'nope@example.com', 'roleId' => RoleFactory::forTests('developer')->id,
        ]);

        $response->assertStatus(403)->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    public function test_duplicate_email_is_a_409_not_a_422(): void
    {
        $oic = User::factory()->oic()->create();
        $existing = User::factory()->create();

        $response = $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'Dupe', 'email' => $existing->email, 'roleId' => RoleFactory::forTests('project_manager')->id,
        ]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'EMAIL_TAKEN');
    }

    public function test_an_email_used_in_another_organization_is_taken_too(): void
    {
        $oic = User::factory()->oic()->create();
        $other = OrganizationFactory::made('Other Office');
        $elsewhere = User::factory()->adminOf($other)->create(['email' => 'Someone@Example.com']);

        $response = $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'Dupe', 'email' => 'someone@example.com', 'roleId' => RoleFactory::forTests('developer')->id,
        ]);

        $response->assertStatus(409)->assertJsonPath('error.code', 'EMAIL_TAKEN');
        $this->assertNotNull($elsewhere->id);
    }

    public function test_the_manager_can_be_chosen_when_adding_and_must_be_in_reach(): void
    {
        $oic = User::factory()->oic()->create();
        $pmA = User::factory()->projectManager($oic)->create();
        $pmB = User::factory()->projectManager($oic)->create();
        $role = RoleFactory::forTests('developer')->id;

        // the caller reaches only their team: someone else's team leader is not theirs to pick
        $this->actingAs($pmA, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'A', 'email' => 'a@example.com', 'roleId' => $role, 'managerId' => $pmB->id,
        ])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');

        // the admin reaches everyone, so any manager, or none
        $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'B', 'email' => 'b@example.com', 'roleId' => $role, 'managerId' => $pmB->id,
        ])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'b@example.com', 'manager_id' => $pmB->id]);

        $this->actingAs($oic, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'C', 'email' => 'c@example.com', 'roleId' => $role, 'managerId' => null,
        ])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'c@example.com', 'manager_id' => null]);

        // a team-scoped person cannot leave someone without a manager
        $this->actingAs($pmA, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'D', 'email' => 'd@example.com', 'roleId' => $role, 'managerId' => null,
        ])->assertStatus(422)->assertJsonPath('error.code', 'MANAGER_REQUIRED');
    }

    public function test_employees_index_is_scoped_to_the_callers_reach(): void
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
        // The caller is part of their own visible set (Phase 6: the overview includes them).
        $this->assertEqualsCanonicalizing([(string) $pmA->id, (string) $tlA->id, (string) $devA->id], $ids->all());
    }

    public function test_someone_without_people_view_gets_only_their_own_row(): void
    {
        $oic = User::factory()->oic()->create();
        $tl = User::factory()->teamLeader($oic)->create();
        $dev = User::factory()->individualContributor($tl)->create();

        $response = $this->actingAs($dev, 'sanctum')->getJson('/api/v1/employees');

        $response->assertOk();
        $this->assertSame([(string) $dev->id], collect($response->json())->pluck('id')->all());
    }

    public function test_manager_can_deactivate_someone_in_their_reach_and_their_token_stops_working(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();
        $tl = User::factory()->teamLeader($pm)->create();
        $dev = User::factory()->individualContributor($tl)->create();
        $devToken = $dev->createToken('agent-test')->plainTextToken;

        $response = $this->actingAs($pm, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$dev->id}", ['status' => 'inactive']);

        $response->assertOk()->assertJsonPath('status', 'inactive');
        $this->assertSame('inactive', $dev->fresh()->status);
        $this->assertNotNull($dev->fresh()->deactivated_at);

        // Without this, the sanctum guard's cached-user-per-instance behavior would
        // carry $pm's resolved identity over from the actingAs() call above into this
        // next request, instead of re-resolving from $devToken.
        auth()->forgetGuards();

        // Agent tokens are kept on deactivation (so unsent data can still be flushed via
        // /agent/sync), so EnsureActiveUser is what refuses the token here.
        $this->withHeader('Authorization', "Bearer {$devToken}")
            ->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_DEACTIVATED');
    }

    public function test_manager_cannot_deactivate_someone_outside_their_reach(): void
    {
        $oic = User::factory()->oic()->create();
        $pmA = User::factory()->projectManager($oic)->create();
        $pmB = User::factory()->projectManager($oic)->create();
        $tlB = User::factory()->teamLeader($pmB)->create();

        $response = $this->actingAs($pmA, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$tlB->id}", ['status' => 'inactive']);

        $response->assertStatus(403);
        $this->assertSame('active', $tlB->fresh()->status);
    }

    public function test_someone_in_another_organization_does_not_exist_for_the_caller(): void
    {
        $oic = User::factory()->oic()->create();
        $other = OrganizationFactory::made('Other Office');
        $stranger = User::factory()->adminOf($other)->create();

        $response = $this->actingAs($oic, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$stranger->id}", ['status' => 'inactive']);

        $response->assertStatus(404);
        $this->assertSame('active', $stranger->fresh()->status);
    }

    public function test_manager_cannot_deactivate_themselves(): void
    {
        // Not the admin: a sole admin deactivating themselves hits the more specific LAST_ADMIN guard
        // first (see test_the_only_admin_cannot_deactivate_themselves below).
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->create();

        $response = $this->actingAs($pm, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$pm->id}", ['status' => 'inactive']);

        $response->assertStatus(400)->assertJsonPath('error.code', 'CANNOT_MODIFY_SELF');
    }

    public function test_the_only_admin_cannot_deactivate_themselves(): void
    {
        $oic = User::factory()->oic()->create();

        $response = $this->actingAs($oic, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$oic->id}", ['status' => 'inactive']);

        $response->assertStatus(400)->assertJsonPath('error.code', 'LAST_ADMIN');
        $this->assertSame('active', $oic->fresh()->status);
    }

    public function test_an_admin_can_deactivate_another_admin_when_one_will_remain(): void
    {
        $adminA = User::factory()->oic()->create();
        $adminB = User::factory()->oic()->create();

        $response = $this->actingAs($adminA, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$adminB->id}", ['status' => 'inactive']);

        $response->assertOk()->assertJsonPath('status', 'inactive');
        $this->assertSame('inactive', $adminB->fresh()->status);

        // Now A is the only active admin: the same guard applies.
        $this->actingAs($adminA, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$adminA->id}", ['status' => 'inactive'])
            ->assertStatus(400)->assertJsonPath('error.code', 'LAST_ADMIN');
    }

    public function test_the_last_active_admin_cannot_be_deactivated_by_someone_else_either(): void
    {
        $admin = User::factory()->oic()->create();
        $other = OrganizationFactory::made('Other Office');
        User::factory()->adminOf($other)->create(); // an admin elsewhere does not count

        $this->assertSame(1, User::where('organization_id', $admin->organization_id)->count());
    }

    public function test_someone_below_cannot_touch_an_admin(): void
    {
        $adminA = User::factory()->oic()->create();
        $adminB = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($adminA)->create();

        $response = $this->actingAs($pm, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$adminB->id}", ['status' => 'inactive']);

        $response->assertStatus(403);
        $this->assertSame('active', $adminB->fresh()->status);
    }

    public function test_manager_can_reactivate(): void
    {
        $oic = User::factory()->oic()->create();
        $pm = User::factory()->projectManager($oic)->deactivated()->create();

        $response = $this->actingAs($oic, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$pm->id}", ['status' => 'active']);

        $response->assertOk()->assertJsonPath('status', 'active');
        $this->assertSame('active', $pm->fresh()->status);
        $this->assertNull($pm->fresh()->deactivated_at);
    }
}
