<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Database\Factories\RoleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §9.1: giving a person another role (PATCH /admin/employees/{id} with roleId). Needs
// people.assign_role, a person inside the caller's reach, and never more than the caller has themselves.
class RoleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pm;

    private User $tl;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->oic()->create(['name' => 'Olive']);
        $this->pm = User::factory()->projectManager($this->admin)->create(['name' => 'Pat']);
        $this->tl = User::factory()->teamLeader($this->pm)->create(['name' => 'Tina']);
        $this->dev = User::factory()->individualContributor($this->tl)->create(['name' => 'Dan']);
    }

    private function give(User $caller, User $person, int $roleId, array $more = [])
    {
        return $this->actingAs($caller, 'sanctum')->patchJson("/api/v1/admin/employees/{$person->id}", ['roleId' => $roleId, ...$more]);
    }

    public function test_someone_above_can_give_a_person_another_role_and_it_is_audited(): void
    {
        $qa = RoleFactory::forTests('qa');

        $this->give($this->tl, $this->dev, $qa->id)->assertOk()->assertJsonPath('role', 'QA')->assertJsonPath('roleId', (string) $qa->id);

        $this->assertSame($qa->id, $this->dev->fresh()->role_id);
        $entry = AuditLog::where('action', 'employee.role_changed')->firstOrFail();
        $this->assertSame($this->tl->id, $entry->actor_user_id);
        $this->assertSame($this->dev->id, $entry->target_user_id);
        $this->assertSame(['from' => 'Developer', 'to' => 'QA'], $entry->details);
    }

    public function test_nothing_changes_or_is_logged_when_the_person_already_has_the_role(): void
    {
        $this->give($this->tl, $this->dev, $this->dev->role_id)->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'employee.role_changed')->count());
    }

    public function test_the_role_and_the_manager_can_change_together_in_one_entry(): void
    {
        $other = User::factory()->teamLeader($this->pm)->create(['name' => 'Tom']);

        $this->give($this->pm, $this->dev, RoleFactory::forTests('qa')->id, ['managerId' => $other->id])->assertOk();

        $this->assertSame($other->id, $this->dev->fresh()->manager_id);
        $entry = AuditLog::where('action', 'employee.role_changed')->firstOrFail();
        $this->assertSame(['from' => 'Developer', 'to' => 'QA', 'managerFrom' => 'Tina', 'managerTo' => 'Tom'], $entry->details);
        $this->assertSame(0, AuditLog::where('action', 'employee.moved')->count());
    }

    public function test_a_role_can_go_up_or_down_freely_as_long_as_the_caller_holds_at_least_as_much(): void
    {
        // promote a developer to the team leader role (same permissions as the caller's), then demote
        $this->give($this->pm, $this->dev, RoleFactory::forTests('team_leader')->id)->assertOk();
        $this->give($this->pm, $this->dev, RoleFactory::forTests('developer')->id)->assertOk();
    }

    public function test_nobody_can_give_a_role_with_more_than_they_have(): void
    {
        // the admin role holds settings, audit and roles; a team leader does not
        $this->give($this->tl, $this->dev, RoleFactory::forTests('oic')->id)
            ->assertStatus(403)->assertJsonPath('error.code', 'ROLE_ESCALATION');
        $this->assertSame(RoleFactory::forTests('developer')->id, $this->dev->fresh()->role_id);
    }

    public function test_nobody_changes_the_role_of_someone_who_holds_more_than_they_do(): void
    {
        $peerAdmin = User::factory()->oic()->create();

        $this->give($this->pm, $peerAdmin, RoleFactory::forTests('developer')->id)
            ->assertStatus(403); // outside the reach of a team scope anyway
        $registrar = User::factory()->withRole(RoleFactory::make2('Registrar', 'organization', ['people.view', 'people.assign_role']))->create();
        $this->give($registrar, $peerAdmin, RoleFactory::forTests('developer')->id)
            ->assertStatus(403)->assertJsonPath('error.code', 'ROLE_ESCALATION');
    }

    public function test_the_role_of_someone_outside_the_reach_or_the_caller_themselves_cannot_be_changed(): void
    {
        $outsider = User::factory()->teamLeader($this->admin)->create();
        $qa = RoleFactory::forTests('qa')->id;

        $this->give($this->tl, $outsider, $qa)->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->give($this->tl, $this->tl, $qa)->assertStatus(400)->assertJsonPath('error.code', 'CANNOT_MODIFY_SELF');
    }

    public function test_it_needs_the_permission_to_assign_roles(): void
    {
        // can manage people but not roles
        $keeper = User::factory()->withRole(RoleFactory::make2('People Keeper', 'team', ['people.view', 'people.update']), $this->pm)->create();
        $mate = User::factory()->individualContributor($keeper)->create();

        $this->give($keeper, $mate, RoleFactory::forTests('qa')->id)->assertForbidden()->assertJsonPath('error.code', 'PERMISSION_DENIED');
        // a name change is fine for the same person
        $this->actingAs($keeper, 'sanctum')->patchJson("/api/v1/admin/employees/{$mate->id}", ['name' => 'Renamed'])->assertOk();
    }

    public function test_a_role_of_another_organization_is_not_found(): void
    {
        $foreign = RoleFactory::make2('Foreign', 'self', [], OrganizationFactory::made('Other Office'));

        $this->give($this->admin, $this->dev, $foreign->id)->assertStatus(422)->assertJsonPath('error.code', 'ROLE_NOT_FOUND');
        $this->assertSame(RoleFactory::forTests('developer')->id, $this->dev->fresh()->role_id);
    }

    public function test_the_last_active_admin_cannot_lose_the_admin_role(): void
    {
        $registrar = User::factory()->withRole(RoleFactory::make2('Registrar', 'organization', RoleFactory::allPermissions()))->create();
        // holds every permission, so escalation does not stop it: only the last-admin rule does

        $this->give($registrar, $this->admin, RoleFactory::forTests('developer')->id)
            ->assertStatus(400)->assertJsonPath('error.code', 'LAST_ADMIN');

        $second = User::factory()->oic()->create();
        $this->give($registrar, $this->admin, RoleFactory::forTests('developer')->id)->assertOk();
        $this->assertSame($second->role_id, $second->fresh()->role_id);
    }
}
