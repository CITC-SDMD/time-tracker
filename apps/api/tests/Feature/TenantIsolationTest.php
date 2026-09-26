<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\OrganizationSetting;
use App\Models\Role;
use App\Models\Screenshot;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Database\Factories\RoleFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §9.4, §15: one database holds many organizations, and nobody may ever reach another one's
// data. Organization A has people, roles, tracked time, a screenshot and an audit entry; the callers are the people of
// organization B, including its admin, who holds every permission. Each route must treat A's ids as if they did not
// exist (404), and every list must hold only B's rows.
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $adminA;

    private User $devA;

    private User $adminB;

    private User $devB;

    private Role $roleA;

    private Screenshot $shotA;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('screenshots');

        $orgA = OrganizationFactory::forTests();
        $orgB = OrganizationFactory::made('Office B');
        $this->settings()->update(['timezone' => 'Pacific/Auckland']); // A's own timezone, unlike B's Asia/Manila

        $this->adminA = User::factory()->oic()->create(['name' => 'Admin A']);
        $this->devA = User::factory()->individualContributor($this->adminA)->create(['name' => 'Dev A']);
        $this->roleA = RoleFactory::forTests('developer');
        $this->adminB = User::factory()->adminOf($orgB)->create(['name' => 'Admin B']);
        $this->devB = User::factory()->withRole(RoleFactory::make2('Staff B', 'self', [], $orgB), $this->adminB)->create(['name' => 'Dev B']);

        DB::table('daily_summaries')->insert([
            'organization_id' => $orgA->id, 'user_id' => $this->devA->id, 'day' => '2026-09-20',
            'tracked_seconds' => 3600, 'active_seconds' => 3000, 'idle_seconds' => 600, 'apps' => '{}', 'app_names' => '{}',
        ]);
        DB::table('sessions')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $orgA->id, 'user_id' => $this->devA->id, 'device_id' => (string) Str::uuid(),
            'type' => 'application', 'app_name' => 'Secret App', 'started_at' => '2026-09-20 01:00:00', 'ended_at' => '2026-09-20 01:10:00',
            'duration_seconds' => 600, 'day' => '2026-09-20', 'received_at' => now(),
        ]);
        $this->shotA = Screenshot::unguarded(fn () => Screenshot::create([
            'id' => (string) Str::uuid(), 'organization_id' => $orgA->id, 'user_id' => $this->devA->id,
            'taken_at' => '2026-09-20 02:00:00', 'width' => 1280, 'height' => 720,
        ]));
        $this->shotA->addMedia(UploadedFile::fake()->image('s.jpg', 1280, 720))->usingFileName($this->shotA->id.'.jpg')->toMediaCollection(Screenshot::COLLECTION);
        AuditLog::record($this->adminA, 'employee.created', $this->devA, ['role' => 'Developer']);
    }

    private function asB(?User $user = null)
    {
        return $this->actingAs($user ?? $this->adminB, 'sanctum');
    }

    public function test_a_persons_days_timelines_and_screenshots_do_not_exist_for_another_organization(): void
    {
        $id = $this->devA->id;
        $range = 'from=2026-09-20&to=2026-09-20';

        $this->asB()->getJson("/api/v1/employees/{$id}/summary?{$range}")->assertNotFound();
        $this->asB()->getJson("/api/v1/employees/{$id}/timeline?day=2026-09-20")->assertNotFound();
        $this->asB()->getJson("/api/v1/employees/{$id}/screenshots?day=2026-09-20")->assertNotFound();
        $this->asB()->getJson("/api/v1/screenshots/{$this->shotA->id}/thumb")->assertNotFound();
        $this->asB()->getJson("/api/v1/screenshots/{$this->shotA->id}/image")->assertNotFound();

        // and the same person's own organization can, so the 404 is about the organization
        $this->actingAs($this->adminA, 'sanctum')->getJson("/api/v1/employees/{$id}/summary?{$range}")->assertOk();
        $this->actingAs($this->adminA, 'sanctum')->getJson("/api/v1/screenshots/{$this->shotA->id}/image")->assertOk();
    }

    public function test_the_people_list_and_the_reports_hold_only_the_callers_organization(): void
    {
        $names = collect($this->asB()->getJson('/api/v1/employees?includeDeactivated=1')->assertOk()->json())->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Admin B', 'Dev B'], $names);

        foreach (['daily', 'apps', 'team'] as $report) {
            $body = $this->asB()->getJson("/api/v1/reports/{$report}?from=2026-09-01&to=2026-09-30")->assertOk()->getContent();
            $this->assertStringNotContainsString('Dev A', $body);
            $this->assertStringNotContainsString('Secret App', $body);
        }
        // asking for a person of another organization by id is "not found", not "forbidden"
        $this->asB()->getJson("/api/v1/reports/daily?from=2026-09-01&to=2026-09-30&uid={$this->devA->id}")->assertNotFound();
    }

    public function test_people_of_another_organization_cannot_be_changed_deleted_or_invited(): void
    {
        $id = $this->devA->id;

        $this->asB()->patchJson("/api/v1/admin/employees/{$id}", ['status' => 'inactive'])->assertNotFound();
        $this->asB()->patchJson("/api/v1/admin/employees/{$id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->asB()->patchJson("/api/v1/admin/employees/{$id}", ['roleId' => Role::withoutGlobalScopes()->where('organization_id', $this->adminB->organization_id)->value('id')])->assertNotFound();
        $this->asB()->postJson("/api/v1/admin/employees/{$id}/resend-invite")->assertNotFound();
        $this->asB()->deleteJson("/api/v1/admin/employees/{$id}")->assertNotFound();

        $fresh = $this->devA->fresh();
        $this->assertSame('active', $fresh->status);
        $this->assertSame('Dev A', $fresh->name);
        $this->assertSame($this->roleA->id, $fresh->role_id);
    }

    public function test_a_person_cannot_be_given_a_manager_or_a_role_from_another_organization(): void
    {
        $this->asB()->patchJson("/api/v1/admin/employees/{$this->devB->id}", ['managerId' => $this->adminA->id])->assertForbidden();
        $this->asB()->patchJson("/api/v1/admin/employees/{$this->devB->id}", ['roleId' => $this->roleA->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'ROLE_NOT_FOUND');
        $this->asB()->postJson('/api/v1/admin/employees', ['name' => 'X', 'email' => 'x@b.test', 'roleId' => $this->roleA->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'ROLE_NOT_FOUND');
        $this->asB()->postJson('/api/v1/admin/employees', ['name' => 'X', 'email' => 'x@b.test', 'roleId' => Role::withoutGlobalScopes()->where('organization_id', $this->adminB->organization_id)->value('id'), 'managerId' => $this->adminA->id])
            ->assertForbidden();

        $this->assertSame($this->adminB->id, $this->devB->fresh()->manager_id);
        $this->assertDatabaseMissing('users', ['email' => 'x@b.test']);
    }

    public function test_roles_are_per_organization(): void
    {
        $names = collect($this->asB()->getJson('/api/v1/roles')->assertOk()->json())->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Admin', 'Staff B'], $names);

        $this->asB()->patchJson("/api/v1/roles/{$this->roleA->id}", ['name' => 'Renamed'])->assertNotFound();
        $this->asB()->deleteJson("/api/v1/roles/{$this->roleA->id}")->assertNotFound();
        $this->assertSame('Developer', $this->roleA->fresh()->name);
    }

    public function test_settings_and_the_audit_log_are_the_callers_own(): void
    {
        $this->asB()->getJson('/api/v1/admin/settings')->assertOk()->assertJsonPath('timezone', 'Asia/Manila');
        $this->asB()->putJson('/api/v1/admin/settings', ['idleThresholdSeconds' => 999])->assertOk();

        $this->assertSame(999, OrganizationSetting::withoutGlobalScopes()->where('organization_id', $this->adminB->organization_id)->value('idle_threshold_seconds'));
        $this->assertSame(300, $this->settings()->idle_threshold_seconds);
        $this->assertSame('Pacific/Auckland', $this->settings()->timezone);

        $entries = $this->asB()->getJson('/api/v1/admin/audit')->assertOk()->json('entries');
        $this->assertSame(['settings.updated'], array_column($entries, 'action')); // not A's employee.created
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('organization_id', $this->adminA->organization_id)->count());
    }

    public function test_what_an_organization_writes_carries_its_own_id(): void
    {
        $this->asB()->postJson('/api/v1/admin/employees', [
            'name' => 'New B', 'email' => 'new@b.test',
            'roleId' => Role::withoutGlobalScopes()->where('organization_id', $this->adminB->organization_id)->where('name', 'Staff B')->value('id'),
        ])->assertCreated();
        $this->asB()->postJson('/api/v1/roles', ['name' => 'Clerk B', 'scope' => 'self', 'permissions' => []])->assertCreated();

        $orgB = $this->adminB->organization_id;
        $this->assertSame($orgB, User::withoutGlobalScopes()->where('email', 'new@b.test')->value('organization_id'));
        $this->assertSame($orgB, Role::withoutGlobalScopes()->where('name', 'Clerk B')->value('organization_id'));
        $this->assertSame($orgB, AuditLog::withoutGlobalScopes()->where('action', 'role.created')->value('organization_id'));
    }

    public function test_the_platform_routes_are_closed_to_every_organization_person_even_the_admin(): void
    {
        $orgId = $this->adminA->organization_id;
        foreach ([
            ['GET', '/api/v1/platform/organizations'],
            ['GET', "/api/v1/platform/organizations/{$orgId}"],
            ['GET', "/api/v1/platform/organizations/{$orgId}/office/employees"],
            ['GET', '/api/v1/platform/settings'],
            ['GET', '/api/v1/platform/superadmins'],
            ['GET', '/api/v1/platform/audit'],
        ] as [$method, $url]) {
            $this->asB()->json($method, $url)->assertForbidden();
        }
    }

    public function test_an_agent_sync_is_stored_in_the_organization_of_the_token(): void
    {
        $id = (string) Str::uuid();
        $started = now()->subMinutes(20);

        $this->asB($this->devB)->withHeaders(['X-Agent-Version' => '0.1.0', 'X-Device-Id' => (string) Str::uuid()])->postJson('/api/v1/agent/sync', [
            'deviceId' => (string) Str::uuid(),
            'clientTime' => now()->toIso8601String(),
            'status' => ['state' => 'active', 'currentApp' => 'Code', 'since' => $started->toIso8601String()],
            'sessions' => [[
                'id' => $id, 'type' => 'application', 'appName' => 'Code', 'processName' => 'code.exe', 'windowTitle' => 't',
                'startedAt' => $started->toIso8601String(), 'endedAt' => $started->copy()->addMinutes(5)->toIso8601String(), 'durationSeconds' => 300,
            ]],
        ])->assertOk()->assertJsonPath('accepted.0', $id);

        $orgB = $this->adminB->organization_id;
        $this->assertSame($orgB, DB::table('sessions')->where('id', $id)->value('organization_id'));
        $this->assertSame($orgB, DB::table('daily_summaries')->where('user_id', $this->devB->id)->value('organization_id'));
        $this->assertSame($orgB, DB::table('employee_statuses')->where('user_id', $this->devB->id)->value('organization_id'));
        $this->assertSame($orgB, DB::table('devices')->value('organization_id'));
    }

    public function test_every_model_with_an_organization_column_is_limited_to_it(): void
    {
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            $model = new $class;
            if (! Schema::hasColumn($model->getTable(), 'organization_id')) {
                continue;
            }
            $this->assertContains(
                BelongsToOrganization::class,
                class_uses_recursive($model),
                "{$class} has an organization_id column, so it must use BelongsToOrganization",
            );
        }
    }
}
