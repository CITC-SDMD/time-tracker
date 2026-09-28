<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Database\Factories\OrganizationFactory;
use Database\Factories\RoleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §9.1, §10: each organization makes its own roles from the platform's fixed list of
// permissions (RoleController). The rules under test: which pairs of scope and permissions are valid, that nobody gives
// more than they have, and how the built-in admin role is protected.
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->oic()->create(['name' => 'Olive']);
    }

    private function make(User $caller, array $data)
    {
        return $this->actingAs($caller, 'sanctum')->postJson('/api/v1/roles', $data);
    }

    public function test_an_admin_makes_a_role_from_the_fixed_permissions_and_it_is_audited(): void
    {
        $response = $this->make($this->admin, [
            'name' => 'Records Officer', 'description' => 'Keeps the records', 'scope' => 'team',
            'permissions' => ['people.view', 'reports.view'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Records Officer')
            ->assertJsonPath('scope', 'team')
            ->assertJsonPath('permissions', ['people.view', 'reports.view'])
            ->assertJsonPath('isSystem', false)
            ->assertJsonPath('memberCount', 0);
        $this->assertDatabaseHas('roles', ['name' => 'Records Officer', 'organization_id' => $this->admin->organization_id]);

        $entry = AuditLog::where('action', 'role.created')->firstOrFail();
        $this->assertSame($this->admin->id, $entry->actor_user_id);
        $this->assertSame('Records Officer', $entry->details['role']);
    }

    public function test_only_permissions_from_the_platform_list_are_accepted(): void
    {
        $this->make($this->admin, ['name' => 'X', 'scope' => 'team', 'permissions' => ['people.view', 'launch.rockets']])->assertStatus(422);
        $this->make($this->admin, ['name' => 'X', 'scope' => 'galaxy', 'permissions' => []])->assertStatus(422);
        $this->make($this->admin, ['name' => 'X', 'scope' => 'team'])->assertStatus(422);
        $this->assertSame(1, Role::count()); // only the admin role
    }

    public function test_a_role_that_only_reaches_the_person_cannot_hold_permissions_about_others(): void
    {
        $this->make($this->admin, ['name' => 'Odd', 'scope' => 'self', 'permissions' => ['people.view']])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ROLE');
        // no permissions at all is a fine role for a member
        $this->make($this->admin, ['name' => 'Member', 'scope' => 'self', 'permissions' => []])->assertCreated();
    }

    public function test_settings_audit_and_roles_need_the_whole_organization(): void
    {
        foreach (['settings.manage', 'audit.view', 'roles.manage'] as $permission) {
            $this->make($this->admin, ['name' => "A {$permission}", 'scope' => 'team', 'permissions' => [$permission]])
                ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ROLE');
            $this->make($this->admin, ['name' => "B {$permission}", 'scope' => 'organization', 'permissions' => [$permission]])->assertCreated();
        }
    }

    public function test_role_names_are_unique_within_an_organization_but_not_across_organizations(): void
    {
        $this->make($this->admin, ['name' => 'Clerk', 'scope' => 'self', 'permissions' => []])->assertCreated();
        $this->make($this->admin, ['name' => 'clerk', 'scope' => 'self', 'permissions' => []])
            ->assertStatus(409)->assertJsonPath('error.code', 'ROLE_NAME_TAKEN');

        $other = OrganizationFactory::made('Other Office');
        $otherAdmin = User::factory()->adminOf($other)->create();
        $this->make($otherAdmin, ['name' => 'Clerk', 'scope' => 'self', 'permissions' => []])->assertCreated();
    }

    public function test_nobody_can_make_or_give_a_role_that_can_do_more_than_their_own(): void
    {
        // a lead who may manage roles for the whole organization but holds only two other permissions
        $lead = User::factory()->withRole(RoleFactory::make2('Role Keeper', 'organization', ['roles.manage', 'people.view']))->create();

        $this->make($lead, ['name' => 'Too much', 'scope' => 'organization', 'permissions' => ['people.view', 'reports.view']])
            ->assertStatus(403)->assertJsonPath('error.code', 'ROLE_ESCALATION');
        // wider reach than their own is more too
        $narrow = User::factory()->withRole(RoleFactory::make2('Narrow Keeper', 'team', ['roles.manage']))->create();
        $this->assertSame(422, $this->make($narrow, ['name' => 'Wide', 'scope' => 'team', 'permissions' => ['roles.manage']])->status()); // roles.manage needs organization scope
        $this->make($lead, ['name' => 'Same', 'scope' => 'organization', 'permissions' => ['people.view']])->assertCreated();
    }

    public function test_the_list_shows_which_roles_the_caller_may_give(): void
    {
        RoleFactory::forTests('project_manager'); // people.view..reports.export, scope team
        $manager = User::factory()->projectManager($this->admin)->create();

        $rows = collect($this->actingAs($manager, 'sanctum')->getJson('/api/v1/roles')->assertOk()->json())->keyBy('name');

        $this->assertFalse($rows['Admin']['assignable']);
        $this->assertTrue($rows['Project Manager']['assignable']);
        $this->assertSame(1, $rows['Admin']['memberCount']);
    }

    public function test_the_list_needs_a_permission_that_uses_it(): void
    {
        $dev = User::factory()->individualContributor($this->admin)->create();

        $this->actingAs($dev, 'sanctum')->getJson('/api/v1/roles')->assertForbidden()->assertJsonPath('error.code', 'PERMISSION_DENIED');
    }

    public function test_a_role_can_be_edited_and_the_change_is_audited(): void
    {
        $id = $this->make($this->admin, ['name' => 'Clerk', 'scope' => 'team', 'permissions' => ['people.view']])->json('id');

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/roles/{$id}", ['name' => 'Senior Clerk', 'permissions' => ['people.view', 'reports.view']])
            ->assertOk()->assertJsonPath('name', 'Senior Clerk')->assertJsonPath('permissions', ['people.view', 'reports.view']);

        $entry = AuditLog::where('action', 'role.updated')->firstOrFail();
        $this->assertSame('Clerk', $entry->details['renamedFrom']);
        $this->assertSame(['reports.view'], $entry->details['permissionsAdded']);
        $this->assertSame([], $entry->details['permissionsRemoved']);
    }

    public function test_an_edit_that_breaks_the_pairing_is_refused_and_changes_nothing(): void
    {
        $id = $this->make($this->admin, ['name' => 'Clerk', 'scope' => 'team', 'permissions' => ['people.view']])->json('id');

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/roles/{$id}", ['scope' => 'self'])
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ROLE');

        $this->assertSame('team', Role::find($id)->scope);
    }

    public function test_the_built_in_admin_role_keeps_all_permissions_and_cannot_be_deleted(): void
    {
        $adminRole = Role::where('is_system', true)->firstOrFail();
        $second = User::factory()->oic()->create();

        // the people who hold it cannot edit it (nobody edits their own role)
        $this->actingAs($second, 'sanctum')->patchJson("/api/v1/roles/{$adminRole->id}", ['name' => 'Boss'])
            ->assertStatus(403)->assertJsonPath('error.code', 'CANNOT_EDIT_OWN_ROLE');

        // a superadmin inside the office may rename it, but its permissions and reach stay locked and it cannot be deleted
        $superadmin = User::factory()->superadmin()->create();
        $base = "/api/v1/platform/organizations/{$adminRole->organization_id}/office/roles/{$adminRole->id}";
        $this->actingAs($superadmin, 'sanctum')->patchJson($base, ['permissions' => ['people.view']])
            ->assertStatus(409)->assertJsonPath('error.code', 'ROLE_LOCKED');
        $this->actingAs($superadmin, 'sanctum')->patchJson($base, ['name' => 'Head of Office'])
            ->assertOk()->assertJsonPath('name', 'Head of Office');
        $this->actingAs($superadmin, 'sanctum')->deleteJson($base)
            ->assertStatus(409)->assertJsonPath('error.code', 'ROLE_LOCKED');
    }

    public function test_nobody_edits_or_deletes_the_role_they_hold_themselves(): void
    {
        $roleId = RoleFactory::make2('Role Keeper', 'organization', ['roles.manage'])->id;
        $keeper = User::factory()->withRole(Role::find($roleId))->create();

        $this->actingAs($keeper, 'sanctum')->patchJson("/api/v1/roles/{$roleId}", ['permissions' => []])
            ->assertStatus(403)->assertJsonPath('error.code', 'CANNOT_EDIT_OWN_ROLE');
        $this->actingAs($keeper, 'sanctum')->deleteJson("/api/v1/roles/{$roleId}")
            ->assertStatus(403)->assertJsonPath('error.code', 'CANNOT_EDIT_OWN_ROLE');
    }

    public function test_a_role_that_people_hold_cannot_be_deleted_until_they_are_moved(): void
    {
        $role = RoleFactory::make2('Clerk', 'self', []);
        $clerk = User::factory()->withRole($role, $this->admin)->create();

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/roles/{$role->id}")
            ->assertStatus(409)->assertJsonPath('error.code', 'ROLE_IN_USE');
        $this->assertDatabaseHas('roles', ['id' => $role->id]);

        $clerk->forceFill(['role_id' => RoleFactory::forTests('developer')->id])->save();
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/roles/{$role->id}")->assertNoContent();
        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertSame('Clerk', AuditLog::where('action', 'role.deleted')->firstOrFail()->details['role']);
    }

    public function test_a_role_of_another_organization_does_not_exist(): void
    {
        $other = OrganizationFactory::made('Other Office');
        $foreign = RoleFactory::make2('Foreign', 'self', [], $other);

        $this->actingAs($this->admin, 'sanctum')->patchJson("/api/v1/roles/{$foreign->id}", ['name' => 'Mine now'])->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/roles/{$foreign->id}")->assertNotFound();
        $this->assertSame('Foreign', $foreign->fresh()->name);
    }

    public function test_the_catalog_lists_every_permission_with_words(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/permissions')->assertOk();

        $keys = collect($response->json('permissions'))->pluck('key')->all();
        $this->assertEqualsCanonicalizing(Permissions::organizationKeys(), $keys);
        $this->assertNotEmpty($response->json('permissions.0.label'));
        $this->assertSame(Permissions::ORGANIZATION_WIDE, $response->json('organizationWide'));
    }
}
