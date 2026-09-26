<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\RoleFactory;
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
            'name' => 'New PM', 'email' => 'pm@example.com', 'roleId' => RoleFactory::forTests('project_manager')->id,
        ])->assertCreated();

        $response = $this->actingAs($oic, 'sanctum')->getJson('/api/v1/admin/audit');

        $response->assertOk()->assertJsonPath('entries.0.action', 'employee.created');
        $this->assertSame('New PM', $response->json('entries.0.targetName'));
    }

    public function test_the_log_can_be_filtered_by_action_person_and_day(): void
    {
        $oic = User::factory()->oic()->create(['name' => 'Olive']);
        $pm = User::factory()->projectManager($oic)->create(['name' => 'Pat Lopez']);
        $tl = User::factory()->teamLeader($pm)->create(['name' => 'Tina Cruz']);

        AuditLog::record($oic, 'employee.created', $pm);
        AuditLog::record($pm, 'employee.created', $tl);
        AuditLog::record($oic, 'employee.deactivated', $tl);
        $old = AuditLog::record($oic, 'settings.updated');
        // 2026-03-10 23:30 in Manila is 15:30 UTC the same day; 2026-03-11 00:30 is 16:30 UTC.
        $old->created_at = '2026-03-10 15:30:00';
        $old->save();
        $next = AuditLog::record($oic, 'settings.updated');
        $next->created_at = '2026-03-10 16:30:00';
        $next->save();

        $call = fn (string $query) => collect($this->actingAs($oic, 'sanctum')->getJson('/api/v1/admin/audit?'.$query)->assertOk()->json('entries'));

        $this->assertCount(1, $call('action=employee.deactivated'));
        $this->assertCount(2, $call('action=settings.updated'));
        // by the name of whoever did it or whoever it was done to, in any letter case, without wildcards
        $this->assertCount(2, $call('q=tina'));
        $this->assertCount(2, $call('q=pat%20lopez&action=employee.created'));
        $this->assertCount(0, $call('q=pat%20lopez&action=employee.deactivated'));
        $this->assertCount(0, $call('q=%25'));
        // office-timezone day boundaries: 15:30 UTC is still 10 March in Manila, 16:30 UTC is already 11 March
        $this->assertSame([$old->id], $call('from=2026-03-10&to=2026-03-10')->pluck('id')->all());
        $this->assertSame([$next->id], $call('from=2026-03-11&to=2026-03-11')->pluck('id')->all());
    }

    public function test_a_bad_filter_is_refused(): void
    {
        $oic = User::factory()->oic()->create();

        $this->actingAs($oic, 'sanctum')->getJson('/api/v1/admin/audit?from=2026-03-10&to=2026-03-01')->assertStatus(422);
        $this->actingAs($oic, 'sanctum')->getJson('/api/v1/admin/audit?from=yesterday')->assertStatus(422);
    }
}
