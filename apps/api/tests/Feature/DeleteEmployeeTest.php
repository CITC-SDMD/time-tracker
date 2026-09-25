<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OfficeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// DELETE /api/v1/admin/employees/{id}: only for accounts that never tracked anything and have no
// reports. Everyone else is deactivated, so the office keeps its data.
class DeleteEmployeeTest extends TestCase
{
    use RefreshDatabase;

    private User $oic;

    private User $pm;

    private User $tl;

    protected function setUp(): void
    {
        parent::setUp();
        OfficeSetting::create([
            'id' => 1, 'timezone' => 'Asia/Manila', 'idle_threshold_seconds' => 300,
            'window_title_mode' => 'full', 'min_agent_version' => '0.1.0', 'consent_version' => 1,
        ]);
        $this->oic = User::factory()->oic()->create(['name' => 'Olive']);
        $this->pm = User::factory()->projectManager($this->oic)->create(['name' => 'Pat']);
        $this->tl = User::factory()->teamLeader($this->pm)->create(['name' => 'Tina']);
    }

    private function remove(User $caller, User $target)
    {
        return $this->actingAs($caller, 'sanctum')->deleteJson("/api/v1/admin/employees/{$target->id}");
    }

    public function test_an_unused_account_can_be_deleted_and_the_audit_log_still_names_it(): void
    {
        $dev = User::factory()->individualContributor($this->tl)->create(['name' => 'Dana']);
        $dev->createToken('agent-pc')->plainTextToken;

        $this->remove($this->tl, $dev)->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $dev->id]);
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $dev->id)->count());
        $entry = AuditLog::where('action', 'employee.deleted')->firstOrFail();
        $this->assertNull($entry->target_user_id);
        $this->assertSame('Dana', $entry->target_name);
        $this->assertSame('Tina', $entry->actor_name);

        $row = $this->actingAs($this->oic, 'sanctum')->getJson('/api/v1/admin/audit')->json('entries.0');
        $this->assertSame('employee.deleted', $row['action']);
        $this->assertSame('Dana', $row['targetName']);
    }

    public function test_deleting_a_person_who_did_things_keeps_their_audit_entries(): void
    {
        $this->actingAs($this->pm, 'sanctum')->postJson('/api/v1/admin/employees', [
            'name' => 'Made By Pat', 'email' => 'made@example.com', 'role' => 'team_leader',
        ])->assertCreated();
        $made = User::where('email', 'made@example.com')->firstOrFail();
        $this->remove($this->pm, $made)->assertNoContent();
        // Now delete Pat's report Tina, then Pat himself.
        $this->remove($this->pm, $this->tl)->assertNoContent();
        $this->remove($this->oic, $this->pm)->assertNoContent();

        $created = AuditLog::where('action', 'employee.created')->firstOrFail();
        $this->assertNull($created->actor_user_id);
        $this->assertSame('Pat', $created->actor_name);
        $this->assertSame('Pat', $this->actingAs($this->oic, 'sanctum')->getJson('/api/v1/admin/audit')->json('entries.2.actorName'));
    }

    public function test_someone_with_tracked_time_is_refused(): void
    {
        $dev = User::factory()->individualContributor($this->tl)->create();
        DB::table('daily_summaries')->insert(['user_id' => $dev->id, 'day' => '2026-09-20', 'tracked_seconds' => 60, 'apps' => '{}', 'app_names' => '{}']);

        $this->remove($this->tl, $dev)->assertStatus(409)->assertJsonPath('error.code', 'HAS_DATA');
        $this->assertDatabaseHas('users', ['id' => $dev->id]);

        $other = User::factory()->individualContributor($this->tl)->create();
        DB::table('sessions')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $other->id, 'device_id' => (string) Str::uuid(),
            'type' => 'application', 'app_name' => 'X', 'started_at' => '2026-09-20 01:00:00', 'ended_at' => '2026-09-20 01:01:00',
            'duration_seconds' => 60, 'day' => '2026-09-20', 'received_at' => now(),
        ]);
        $this->remove($this->tl, $other)->assertStatus(409)->assertJsonPath('error.code', 'HAS_DATA');
    }

    public function test_someone_with_reports_is_refused(): void
    {
        User::factory()->individualContributor($this->tl)->create();

        $this->remove($this->pm, $this->tl)->assertStatus(409)->assertJsonPath('error.code', 'HAS_REPORTS');
        $this->assertDatabaseHas('users', ['id' => $this->tl->id]);
    }

    public function test_you_cannot_delete_yourself_an_oic_or_someone_outside_your_hierarchy(): void
    {
        $otherTl = User::factory()->teamLeader($this->pm)->create();
        $secondOic = User::factory()->oic()->create();

        $this->remove($this->tl, $this->tl)->assertStatus(400)->assertJsonPath('error.code', 'CANNOT_MODIFY_SELF');
        $this->remove($this->oic, $secondOic)->assertStatus(400)->assertJsonPath('error.code', 'CANNOT_DELETE_OIC');
        $this->remove($this->tl, $otherTl)->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $otherTl->id]);
    }

    public function test_an_individual_contributor_cannot_delete_anyone(): void
    {
        $dev = User::factory()->individualContributor($this->tl)->create();
        $other = User::factory()->individualContributor($this->tl)->create();

        $this->remove($dev, $other)->assertForbidden();
    }
}
