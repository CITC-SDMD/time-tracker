<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Task;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Managers assign tasks to people so hours can be reported per task (docs/DEVELOPMENT_PLAN.md). Managing tasks
// (tasks.manage) is organization-wide, like roles; nobody is assigned a task outside the caller's reach.
class TaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_creates_a_task_and_assigns_it_to_people_in_reach(): void
    {
        $admin = User::factory()->oic()->create();
        $dev = User::factory()->individualContributor($admin)->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/tasks', [
            'title' => 'Q3 budget report',
            'description' => 'Draft and review',
            'assigneeIds' => [$dev->id],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('title', 'Q3 budget report');
        $response->assertJsonPath('status', 'active');
        $response->assertJsonPath('assigneeCount', 1);
        $this->assertSame([(string) $dev->id], $response->json('assigneeIds'));
        $this->assertTrue(AuditLog::where('action', 'task.created')->exists());
        $this->assertTrue(AuditLog::where('action', 'task.assigned')->where('target_user_id', $dev->id)->exists());
    }

    public function test_someone_without_tasks_manage_cannot_create_or_edit_a_task(): void
    {
        $boss = User::factory()->oic()->create();
        $viewer = User::factory()->individualContributor($boss)->create();
        $viewer->role->permissions = ['tasks.view'];
        $viewer->role->save();

        $this->actingAs($viewer, 'sanctum')->postJson('/api/v1/tasks', ['title' => 'Nope'])->assertStatus(403);
    }

    public function test_a_task_cannot_be_assigned_to_someone_of_another_organization(): void
    {
        $admin = User::factory()->oic()->create();
        $foreigner = User::factory()->adminOf(OrganizationFactory::made('Office B'))->create();

        $response = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/tasks', [
            'title' => 'Out of reach',
            'assigneeIds' => [$foreigner->id],
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, Task::count());
    }

    public function test_updating_assignees_adds_and_removes_people_and_audits_both(): void
    {
        $admin = User::factory()->oic()->create();
        $a = User::factory()->individualContributor($admin)->create();
        $b = User::factory()->individualContributor($admin)->create();

        $create = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/tasks', [
            'title' => 'Handover', 'assigneeIds' => [$a->id],
        ])->assertCreated();
        $id = $create->json('id');

        $update = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/tasks/{$id}", ['assigneeIds' => [$b->id]]);

        $update->assertOk();
        $this->assertEqualsCanonicalizing([(string) $b->id], $update->json('assigneeIds'));
        $this->assertTrue(AuditLog::where('action', 'task.assigned')->where('target_user_id', $b->id)->exists());
        $this->assertTrue(AuditLog::where('action', 'task.unassigned')->where('target_user_id', $a->id)->exists());
    }

    public function test_archiving_a_task_is_a_status_update_and_is_audited(): void
    {
        $admin = User::factory()->oic()->create();
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/tasks', ['title' => 'Old task'])->json('id');

        $response = $this->actingAs($admin, 'sanctum')->patchJson("/api/v1/tasks/{$id}", ['status' => 'archived']);

        $response->assertOk()->assertJsonPath('status', 'archived');
        $this->assertTrue(AuditLog::where('action', 'task.updated')->where('details->statusTo', 'archived')->exists());
    }

    public function test_tasks_of_another_organization_are_not_found(): void
    {
        $admin = User::factory()->oic()->create();
        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/tasks', ['title' => 'Office A task'])->json('id');

        $otherAdmin = User::factory()->adminOf(OrganizationFactory::made('Office B'))->create();
        $this->actingAs($otherAdmin, 'sanctum')->patchJson("/api/v1/tasks/{$id}", ['title' => 'Hijacked'])->assertStatus(404);
    }

    public function test_the_list_shows_every_task_of_the_organization_with_assignee_counts(): void
    {
        $admin = User::factory()->oic()->create();
        $dev = User::factory()->individualContributor($admin)->create();
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/tasks', ['title' => 'One', 'assigneeIds' => [$dev->id]]);
        $this->actingAs($admin, 'sanctum')->postJson('/api/v1/tasks', ['title' => 'Two']);

        $list = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/tasks')->assertOk()->json();

        $this->assertCount(2, $list);
        $byTitle = collect($list)->keyBy('title');
        $this->assertSame(1, $byTitle['One']['assigneeCount']);
        $this->assertSame(0, $byTitle['Two']['assigneeCount']);
    }
}
