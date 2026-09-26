<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OfficeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// PATCH /admin/employees/{id} with `role` (docs/DEVELOPMENT_PLAN.md §9.1, §10): only someone above a
// person may change their role, within the rule that everyone reports to the role one tier above theirs.
//
//   oic
//   |- pm1 -- tl1 (dev1, qa1), tl2 (nobody)
//   |- pm2 -- tl3 (dev3)
class RoleChangeTest extends TestCase
{
    use RefreshDatabase;

    private User $oic;

    private User $pm1;

    private User $pm2;

    private User $tl1;

    private User $tl2;

    private User $tl3;

    private User $dev1;

    private User $qa1;

    private User $dev3;

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
        $this->pm1 = User::factory()->projectManager($this->oic)->create(['name' => 'Pat']);
        $this->pm2 = User::factory()->projectManager($this->oic)->create(['name' => 'Paul']);
        $this->tl1 = User::factory()->teamLeader($this->pm1)->create(['name' => 'Tina']);
        $this->tl2 = User::factory()->teamLeader($this->pm1)->create(['name' => 'Tess']);
        $this->tl3 = User::factory()->teamLeader($this->pm2)->create(['name' => 'Tom']);
        $this->dev1 = User::factory()->individualContributor($this->tl1, 'developer')->create(['name' => 'Dan']);
        $this->qa1 = User::factory()->individualContributor($this->tl1, 'qa')->create(['name' => 'Quin']);
        $this->dev3 = User::factory()->individualContributor($this->tl3, 'developer')->create(['name' => 'Dee']);
    }

    private function change(User $caller, User $target, array $body)
    {
        return $this->actingAs($caller, 'sanctum')->patchJson("/api/v1/admin/employees/{$target->id}", $body);
    }

    private function assertUnchanged(User $person, string $role, ?int $managerId): void
    {
        $fresh = $person->fresh();
        $this->assertSame($role, $fresh->role);
        $this->assertSame($managerId, $fresh->manager_id);
        $this->assertSame(0, AuditLog::where('action', 'employee.role_changed')->count());
    }

    // ---- same tier -------------------------------------------------------------------------------

    public function test_a_team_leader_changes_an_individual_role_and_the_manager_stays(): void
    {
        $this->change($this->tl1, $this->dev1, ['role' => 'system_analyst'])
            ->assertOk()
            ->assertJsonPath('role', 'system_analyst')
            ->assertJsonPath('managerId', (string) $this->tl1->id);

        $fresh = $this->dev1->fresh();
        $this->assertSame('system_analyst', $fresh->role);
        $this->assertSame($this->tl1->id, $fresh->manager_id);
    }

    public function test_someone_further_up_can_do_it_too(): void
    {
        $this->change($this->pm1, $this->dev1, ['role' => 'lead_developer'])->assertOk();
        $this->change($this->oic, $this->qa1, ['role' => 'client_support'])->assertOk();

        $this->assertSame('lead_developer', $this->dev1->fresh()->role);
        $this->assertSame('client_support', $this->qa1->fresh()->role);
    }

    public function test_the_change_is_written_to_the_audit_log(): void
    {
        $this->change($this->tl1, $this->dev1, ['role' => 'qa'])->assertOk();

        $entry = AuditLog::where('action', 'employee.role_changed')->firstOrFail();
        $this->assertSame($this->tl1->id, $entry->actor_user_id);
        $this->assertSame($this->dev1->id, $entry->target_user_id);
        $this->assertSame(['from' => 'developer', 'to' => 'qa', 'managerFrom' => 'Tina', 'managerTo' => 'Tina'], $entry->details);
    }

    public function test_the_same_role_is_a_quiet_no_op(): void
    {
        $this->change($this->tl1, $this->dev1, ['role' => 'developer'])->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'employee.role_changed')->count());
    }

    // ---- who may -----------------------------------------------------------------------------------

    public function test_nobody_changes_their_own_role(): void
    {
        $this->change($this->tl1, $this->tl1, ['role' => 'developer', 'managerId' => $this->tl2->id])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CANNOT_MODIFY_SELF');

        $this->assertUnchanged($this->tl1, 'team_leader', $this->pm1->id);
    }

    public function test_someone_outside_the_hierarchy_is_refused(): void
    {
        $this->change($this->tl3, $this->dev1, ['role' => 'qa'])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->change($this->tl2, $this->dev1, ['role' => 'qa'])->assertStatus(403);
        $this->change($this->pm2, $this->dev1, ['role' => 'qa'])->assertStatus(403);

        $this->assertUnchanged($this->dev1, 'developer', $this->tl1->id);
    }

    public function test_an_individual_contributor_cannot_change_anyones_role(): void
    {
        $this->change($this->dev1, $this->qa1, ['role' => 'developer'])->assertStatus(403);

        $this->assertUnchanged($this->qa1, 'qa', $this->tl1->id);
    }

    public function test_oic_and_superadmin_can_never_be_given_and_nonsense_is_refused(): void
    {
        foreach (['oic', 'superadmin', 'boss', ''] as $role) {
            $this->change($this->oic, $this->dev1, ['role' => $role])->assertStatus(422);
        }

        $this->assertUnchanged($this->dev1, 'developer', $this->tl1->id);
    }

    public function test_an_oics_role_cannot_be_changed_even_by_another_oic(): void
    {
        $other = User::factory()->oic()->create(['name' => 'Otto']);

        $this->change($this->oic, $other, ['role' => 'project_manager', 'managerId' => $this->oic->id])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CANNOT_CHANGE_OIC');

        $this->assertSame('oic', $other->fresh()->role);
    }

    // ---- across tiers: promote -----------------------------------------------------------------------

    public function test_promote_a_developer_to_team_leader_under_a_project_manager(): void
    {
        $this->change($this->pm1, $this->dev1, ['role' => 'team_leader', 'managerId' => $this->pm1->id])
            ->assertOk()
            ->assertJsonPath('role', 'team_leader')
            ->assertJsonPath('managerId', (string) $this->pm1->id)
            ->assertJsonPath('managerName', 'Pat');

        $fresh = $this->dev1->fresh();
        $this->assertSame('team_leader', $fresh->role);
        $this->assertSame($this->pm1->id, $fresh->manager_id);

        $entry = AuditLog::where('action', 'employee.role_changed')->firstOrFail();
        $this->assertSame(['from' => 'developer', 'to' => 'team_leader', 'managerFrom' => 'Tina', 'managerTo' => 'Pat'], $entry->details);
        // one entry for the whole change, not a second "moved" line
        $this->assertSame(0, AuditLog::where('action', 'employee.moved')->count());
    }

    public function test_the_oic_can_promote_someone_under_any_project_manager(): void
    {
        $this->change($this->oic, $this->dev3, ['role' => 'team_leader', 'managerId' => $this->pm1->id])->assertOk();

        $this->assertSame($this->pm1->id, $this->dev3->fresh()->manager_id);
    }

    public function test_the_new_manager_is_required_when_the_tier_changes(): void
    {
        $this->change($this->pm1, $this->dev1, ['role' => 'team_leader'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MANAGER_REQUIRED');
        // naming the current manager does not help: a team leader cannot manage a team leader
        $this->change($this->pm1, $this->dev1, ['role' => 'team_leader', 'managerId' => $this->tl1->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MANAGER_REQUIRED');
        // and neither does another team leader
        $this->change($this->pm1, $this->dev1, ['role' => 'team_leader', 'managerId' => $this->tl2->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WRONG_TIER');

        $this->assertUnchanged($this->dev1, 'developer', $this->tl1->id);
    }

    public function test_a_team_leader_cannot_promote_anyone_because_they_cannot_see_a_project_manager(): void
    {
        $this->change($this->tl1, $this->dev1, ['role' => 'team_leader', 'managerId' => $this->pm1->id])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertUnchanged($this->dev1, 'developer', $this->tl1->id);
    }

    public function test_the_new_manager_must_be_in_the_callers_hierarchy_active_and_the_right_tier(): void
    {
        // pm1 cannot put someone under pm2, and cannot make a second project manager (the OIC is out of sight)
        $this->change($this->pm1, $this->dev1, ['role' => 'team_leader', 'managerId' => $this->pm2->id])->assertStatus(403);
        $this->change($this->pm1, $this->tl2, ['role' => 'project_manager', 'managerId' => $this->oic->id])->assertStatus(403);
        // an OIC cannot manage a team leader
        $this->change($this->oic, $this->dev1, ['role' => 'team_leader', 'managerId' => $this->oic->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'WRONG_TIER');
        // a deactivated project manager cannot take anyone
        $this->pm2->forceFill(['status' => 'inactive'])->save();
        $this->change($this->oic, $this->dev1, ['role' => 'team_leader', 'managerId' => $this->pm2->id])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MANAGER_INACTIVE');

        $this->assertUnchanged($this->dev1, 'developer', $this->tl1->id);
        $this->assertUnchanged($this->tl2, 'team_leader', $this->pm1->id);
    }

    public function test_a_team_leader_without_a_team_can_become_a_project_manager_under_the_oic(): void
    {
        $this->change($this->oic, $this->tl2, ['role' => 'project_manager', 'managerId' => $this->oic->id])->assertOk();

        $fresh = $this->tl2->fresh();
        $this->assertSame('project_manager', $fresh->role);
        $this->assertSame($this->oic->id, $fresh->manager_id);
    }

    public function test_a_team_leader_with_a_team_cannot_become_a_project_manager(): void
    {
        $this->change($this->oic, $this->tl1, ['role' => 'project_manager', 'managerId' => $this->oic->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HAS_REPORTS');

        $this->assertUnchanged($this->tl1, 'team_leader', $this->pm1->id);
    }

    // ---- across tiers: demote -------------------------------------------------------------------------

    public function test_demote_a_team_leader_without_a_team_to_an_individual_role(): void
    {
        $this->change($this->pm1, $this->tl2, ['role' => 'qa', 'managerId' => $this->tl1->id])
            ->assertOk()
            ->assertJsonPath('role', 'qa')
            ->assertJsonPath('managerName', 'Tina');

        $this->assertSame($this->tl1->id, $this->tl2->fresh()->manager_id);
    }

    public function test_a_manager_with_a_team_cannot_be_demoted_until_the_team_moves(): void
    {
        $this->change($this->pm1, $this->tl1, ['role' => 'developer', 'managerId' => $this->tl2->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HAS_REPORTS');

        $this->assertUnchanged($this->tl1, 'team_leader', $this->pm1->id);

        // once the team has moved it goes through
        $this->dev1->forceFill(['manager_id' => $this->tl2->id])->save();
        $this->qa1->forceFill(['manager_id' => $this->tl2->id])->save();
        $this->change($this->pm1, $this->tl1, ['role' => 'developer', 'managerId' => $this->tl2->id])->assertOk();
    }

    public function test_a_project_manager_with_team_leaders_cannot_become_a_team_leader(): void
    {
        $this->change($this->oic, $this->pm1, ['role' => 'team_leader', 'managerId' => $this->pm2->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'HAS_REPORTS');
    }

    // ---- what follows ---------------------------------------------------------------------------------

    public function test_dashboard_access_follows_the_new_role_at_once(): void
    {
        $this->actingAs($this->dev1, 'sanctum')->getJson('/api/v1/employees')->assertStatus(403);

        $this->change($this->pm1, $this->dev1, ['role' => 'team_leader', 'managerId' => $this->pm1->id])->assertOk();
        $this->app['auth']->forgetGuards();

        $this->actingAs($this->dev1->fresh(), 'sanctum')->getJson('/api/v1/employees')->assertOk();
    }

    public function test_a_persons_history_stays_with_them(): void
    {
        $before = $this->dev1->id;

        $this->change($this->pm1, $this->dev1, ['role' => 'team_leader', 'managerId' => $this->pm1->id])->assertOk();

        $this->assertSame($before, $this->dev1->fresh()->id);
        $this->assertSame($this->dev1->email, $this->dev1->fresh()->email);
    }

    public function test_which_roles_can_manage_which(): void
    {
        $this->assertSame(['oic'], User::rolesThatManage('project_manager'));
        $this->assertSame(['project_manager'], User::rolesThatManage('team_leader'));
        $this->assertSame(['team_leader'], User::rolesThatManage('qa'));
        $this->assertSame([], User::rolesThatManage('oic'));
    }
}
