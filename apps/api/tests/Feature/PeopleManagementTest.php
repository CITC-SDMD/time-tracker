<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

// Moving a person to another manager and re-sending the set-password link
// (docs/DEVELOPMENT_PLAN.md §9.1, §10): AdminEmployeeController@update (managerId) and @resendInvite.
class PeopleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $oic;

    private User $pmA;

    private User $pmB;

    private User $tlA1;

    private User $tlA2;

    private User $tlB1;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oic = User::factory()->oic()->create(['name' => 'Olive']);
        $this->pmA = User::factory()->projectManager($this->oic)->create(['name' => 'Pat A']);
        $this->pmB = User::factory()->projectManager($this->oic)->create(['name' => 'Pam B']);
        $this->tlA1 = User::factory()->teamLeader($this->pmA)->create(['name' => 'Tina A1']);
        $this->tlA2 = User::factory()->teamLeader($this->pmA)->create(['name' => 'Tom A2']);
        $this->tlB1 = User::factory()->teamLeader($this->pmB)->create(['name' => 'Tess B1']);
        $this->dev = User::factory()->individualContributor($this->tlA1)->create(['name' => 'Dev One']);
    }

    private function move(User $caller, User $person, int $managerId)
    {
        return $this->actingAs($caller, 'sanctum')->patchJson("/api/v1/admin/employees/{$person->id}", ['managerId' => $managerId]);
    }

    public function test_the_oic_can_move_a_team_leader_to_another_project_manager(): void
    {
        $this->move($this->oic, $this->tlA1, $this->pmB->id)
            ->assertOk()
            ->assertJsonPath('managerId', (string) $this->pmB->id)
            ->assertJsonPath('managerName', 'Pam B');

        $this->assertSame($this->pmB->id, $this->tlA1->fresh()->manager_id);
        // their own team goes with them
        $this->assertSame($this->tlA1->id, $this->dev->fresh()->manager_id);

        $entry = AuditLog::where('action', 'employee.moved')->firstOrFail();
        $this->assertSame($this->oic->id, $entry->actor_user_id);
        $this->assertSame($this->tlA1->id, $entry->target_user_id);
        $this->assertSame(['from' => 'Pat A', 'to' => 'Pam B'], $entry->details);
    }

    public function test_a_project_manager_can_move_a_member_between_their_own_team_leaders(): void
    {
        $this->move($this->pmA, $this->dev, $this->tlA2->id)->assertOk();

        $this->assertSame($this->tlA2->id, $this->dev->fresh()->manager_id);
    }

    public function test_moving_someone_to_the_manager_they_already_have_changes_nothing(): void
    {
        $this->move($this->oic, $this->dev, $this->tlA1->id)->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'employee.moved')->count());
    }

    public function test_there_is_no_tier_rule_any_active_person_in_reach_can_be_the_manager(): void
    {
        // a member under a project manager, a team leader under a team leader, a team leader under a member
        $this->move($this->oic, $this->dev, $this->pmB->id)->assertOk();
        $this->move($this->oic, $this->tlA1, $this->tlB1->id)->assertOk();
        $this->move($this->oic, $this->tlA2, $this->dev->id)->assertOk();

        $this->assertSame($this->pmB->id, $this->dev->fresh()->manager_id);
        $this->assertSame($this->tlB1->id, $this->tlA1->fresh()->manager_id);
        $this->assertSame($this->dev->id, $this->tlA2->fresh()->manager_id);
    }

    public function test_nobody_can_be_put_under_themselves_or_under_someone_below_them(): void
    {
        // Dev One reports (through Tina A1) to Pat A: Pat A cannot report to Dev One, directly or further down
        $this->move($this->oic, $this->pmA, $this->dev->id)->assertStatus(422)->assertJsonPath('error.code', 'WOULD_CREATE_LOOP');
        $this->move($this->oic, $this->pmA, $this->tlA1->id)->assertStatus(422)->assertJsonPath('error.code', 'WOULD_CREATE_LOOP');

        $this->assertSame($this->oic->id, $this->pmA->fresh()->manager_id);
    }

    public function test_only_someone_reaching_the_whole_organization_can_leave_a_person_without_a_manager(): void
    {
        $this->actingAs($this->pmA, 'sanctum')->patchJson("/api/v1/admin/employees/{$this->dev->id}", ['managerId' => null])
            ->assertStatus(422)->assertJsonPath('error.code', 'MANAGER_REQUIRED');

        $this->actingAs($this->oic, 'sanctum')->patchJson("/api/v1/admin/employees/{$this->dev->id}", ['managerId' => null])
            ->assertOk()->assertJsonPath('managerId', null);
        $this->assertNull($this->dev->fresh()->manager_id);
    }

    public function test_a_manager_cannot_move_people_out_of_or_into_a_branch_that_is_not_theirs(): void
    {
        // into another branch
        $this->move($this->pmA, $this->dev, $this->tlB1->id)->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        // someone from another branch
        $this->move($this->pmB, $this->dev, $this->tlB1->id)->assertForbidden();
        // a manager below them cannot move their own peers
        $this->move($this->tlA1, $this->dev, $this->tlA2->id)->assertForbidden();

        $this->assertSame($this->tlA1->id, $this->dev->fresh()->manager_id);
    }

    public function test_an_unknown_or_deactivated_manager_is_refused(): void
    {
        $this->move($this->oic, $this->dev, 999999)->assertForbidden();

        $this->tlA2->forceFill(['status' => 'inactive'])->save();
        $this->move($this->oic, $this->dev, $this->tlA2->id)->assertStatus(422)->assertJsonPath('error.code', 'MANAGER_INACTIVE');
    }

    public function test_nobody_moves_themselves(): void
    {
        $this->move($this->pmA, $this->pmA, $this->oic->id)->assertStatus(400)->assertJsonPath('error.code', 'CANNOT_MODIFY_SELF');
    }

    public function test_a_refused_move_does_not_apply_the_name_change_sent_with_it(): void
    {
        $this->tlA2->forceFill(['status' => 'inactive'])->save();

        $this->actingAs($this->oic, 'sanctum')
            ->patchJson("/api/v1/admin/employees/{$this->dev->id}", ['name' => 'Renamed', 'managerId' => $this->tlA2->id])
            ->assertStatus(422);

        $this->assertSame('Dev One', $this->dev->fresh()->name);
    }

    public function test_resending_the_link_emails_a_new_welcome_and_is_audited(): void
    {
        Notification::fake();

        $this->actingAs($this->tlA1, 'sanctum')
            ->postJson("/api/v1/admin/employees/{$this->dev->id}/resend-invite")
            ->assertOk()
            ->assertJsonPath('emailSent', true)
            ->assertJsonMissingPath('setPasswordUrl');

        Notification::assertSentToTimes($this->dev, WelcomeNotification::class, 1);
        $this->assertSame(1, AuditLog::where('action', 'employee.invite_resent')->where('target_user_id', $this->dev->id)->count());
    }

    public function test_the_new_link_replaces_the_previous_one(): void
    {
        Notification::fake();
        $this->actingAs($this->oic, 'sanctum');
        foreach ([1, 2] as $_) {
            $this->postJson("/api/v1/admin/employees/{$this->pmA->id}/resend-invite")->assertOk();
        }
        Notification::assertSentToTimes($this->pmA, WelcomeNotification::class, 2);
        $this->assertSame(1, \DB::table('password_invite_tokens')->where('email', $this->pmA->email)->count());
    }

    public function test_resending_is_limited_to_your_own_branch_and_active_accounts(): void
    {
        Notification::fake();

        $this->actingAs($this->pmB, 'sanctum')
            ->postJson("/api/v1/admin/employees/{$this->dev->id}/resend-invite")
            ->assertForbidden();
        $this->actingAs($this->pmA, 'sanctum')
            ->postJson("/api/v1/admin/employees/{$this->pmA->id}/resend-invite")
            ->assertStatus(400)->assertJsonPath('error.code', 'CANNOT_MODIFY_SELF');

        $this->dev->forceFill(['status' => 'inactive'])->save();
        $this->actingAs($this->oic, 'sanctum')
            ->postJson("/api/v1/admin/employees/{$this->dev->id}/resend-invite")
            ->assertStatus(409)->assertJsonPath('error.code', 'ACCOUNT_INACTIVE');

        Notification::assertNothingSent();
    }

    public function test_individual_contributors_can_do_neither(): void
    {
        $other = User::factory()->individualContributor($this->tlA1)->create();

        $this->actingAs($this->dev, 'sanctum')
            ->postJson("/api/v1/admin/employees/{$other->id}/resend-invite")->assertForbidden();
        $this->move($this->dev, $other, $this->tlA2->id)->assertForbidden();
    }

    public function test_a_broken_mail_server_hands_the_link_back_when_resending(): void
    {
        config(['mail.default' => 'failing']);
        config(['mail.mailers.failing' => ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]]);

        $response = $this->actingAs($this->oic, 'sanctum')
            ->postJson("/api/v1/admin/employees/{$this->pmA->id}/resend-invite")
            ->assertOk()->assertJsonPath('emailSent', false);

        $this->assertStringContainsString('/reset-password?link=', $response->json('setPasswordUrl'));
    }
}
