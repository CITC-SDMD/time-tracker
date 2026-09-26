<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

// Virtual machine detection (docs/DEVELOPMENT_PLAN.md §16): the agent reports where it runs, a superadmin with
// `organizations.detection.manage` switches it off or on for a person, and only superadmins and the organization's
// admins see the flags.
class DetectionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $lead;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->oic()->create(['name' => 'Olive']);
        $this->lead = User::factory()->teamLeader($this->admin)->create(['name' => 'Tina']);
        $this->dev = User::factory()->individualContributor($this->lead)->create(['name' => 'Dan']);
    }

    private function sync(User $user, ?string $environment, string $device = '11111111-1111-4111-8111-111111111111')
    {
        auth()->forgetGuards();

        return $this->actingAs($user, 'sanctum')
            ->withHeaders(['X-Agent-Version' => '0.1.0', 'X-Device-Id' => $device])
            ->postJson('/api/v1/agent/sync', [
                'clientTime' => Carbon::now('UTC')->toIso8601ZuluString('millisecond'),
                'status' => [
                    'state' => 'active',
                    'currentApp' => 'Code',
                    'since' => Carbon::now('UTC')->subMinutes(5)->toIso8601ZuluString('millisecond'),
                    'trackingStartedAt' => Carbon::now('UTC')->subMinutes(5)->toIso8601ZuluString('millisecond'),
                    ...($environment === null ? [] : ['environment' => $environment]),
                ],
                'sessions' => [[
                    'id' => (string) Str::uuid(),
                    'type' => 'application',
                    'appName' => 'Code',
                    'processName' => 'Code.exe',
                    'windowTitle' => 'main.rs',
                    'idleAppName' => null,
                    'startedAt' => Carbon::now('UTC')->subMinutes(6)->toIso8601ZuluString('millisecond'),
                    'endedAt' => Carbon::now('UTC')->subMinute()->toIso8601ZuluString('millisecond'),
                    'durationSeconds' => 300,
                    'clockChanged' => false,
                ]],
            ]);
    }

    private function toggle(User $caller, User $person, bool $enabled)
    {
        auth()->forgetGuards();

        return $this->actingAs($caller, 'sanctum')
            ->patchJson("/api/v1/platform/organizations/{$person->organization_id}/people/{$person->id}/detection", ['enabled' => $enabled]);
    }

    public function test_the_environment_is_stored_and_the_day_keeps_the_strongest_flag(): void
    {
        $this->sync($this->dev, 'remote_session')->assertOk()->assertJsonPath('commands.detectionEnabled', true);
        $this->assertSame('remote_session', DB::table('employee_statuses')->where('user_id', $this->dev->id)->value('environment'));
        $this->assertSame('remote_session', DB::table('daily_summaries')->where('user_id', $this->dev->id)->value('environment'));

        $this->sync($this->dev, 'virtual_machine')->assertOk();
        $this->sync($this->dev, 'physical')->assertOk();

        // the live value follows the agent, the day keeps the strongest one it saw
        $this->assertSame('physical', DB::table('employee_statuses')->where('user_id', $this->dev->id)->value('environment'));
        $this->assertSame('virtual_machine', DB::table('daily_summaries')->where('user_id', $this->dev->id)->value('environment'));
    }

    public function test_an_unknown_environment_is_refused_and_a_missing_one_is_fine(): void
    {
        $this->sync($this->dev, 'a_toaster')->assertUnprocessable();
        $this->sync($this->dev, null)->assertOk();
        $this->assertNull(DB::table('employee_statuses')->where('user_id', $this->dev->id)->value('environment'));
    }

    public function test_nothing_is_kept_and_the_agent_is_told_when_detection_is_off_for_the_person(): void
    {
        $this->dev->forceFill(['detection_enabled' => false])->save();

        $this->sync($this->dev, 'virtual_machine')->assertOk()->assertJsonPath('commands.detectionEnabled', false);

        $this->assertNull(DB::table('employee_statuses')->where('user_id', $this->dev->id)->value('environment'));
        $this->assertNull(DB::table('daily_summaries')->where('user_id', $this->dev->id)->value('environment'));
    }

    public function test_me_tells_the_agent_whether_detection_is_on(): void
    {
        $this->actingAs($this->dev, 'sanctum')->getJson('/api/v1/me')->assertOk()->assertJsonPath('detectionEnabled', true);
        $this->dev->forceFill(['detection_enabled' => false])->save();
        auth()->forgetGuards();
        $this->actingAs($this->dev, 'sanctum')->getJson('/api/v1/me')->assertJsonPath('detectionEnabled', false);
    }

    public function test_only_the_organizations_admins_and_superadmins_see_the_flags(): void
    {
        $this->sync($this->dev, 'virtual_machine')->assertOk();
        $today = now()->format('Y-m-d');

        $this->actingAs($this->admin, 'sanctum');
        $row = collect($this->getJson('/api/v1/employees')->assertOk()->json())->firstWhere('id', (string) $this->dev->id);
        $this->assertSame('virtual_machine', $row['environment']);
        $this->assertTrue($row['detectionEnabled']);
        $this->getJson("/api/v1/employees/{$this->dev->id}/summary?from={$today}&to={$today}")->assertJsonPath('0.environment', 'virtual_machine');

        // a team leader who may see the person's day still does not see the flags
        auth()->forgetGuards();
        $this->actingAs($this->lead, 'sanctum');
        $row = collect($this->getJson('/api/v1/employees')->assertOk()->json())->firstWhere('id', (string) $this->dev->id);
        $this->assertArrayNotHasKey('environment', $row);
        $this->assertArrayNotHasKey('detectionEnabled', $row);
        $day = $this->getJson("/api/v1/employees/{$this->dev->id}/summary?from={$today}&to={$today}")->assertOk()->json();
        $this->assertArrayNotHasKey('environment', $day[0]);

        // a superadmin who may look inside the office
        auth()->forgetGuards();
        $viewer = User::factory()->superadmin(['organizations.data.view'])->create();
        $this->actingAs($viewer, 'sanctum');
        $office = "/api/v1/platform/organizations/{$this->admin->organization_id}/office/employees";
        $row = collect($this->getJson($office)->assertOk()->json())->firstWhere('id', (string) $this->dev->id);
        $this->assertSame('virtual_machine', $row['environment']);
    }

    public function test_a_superadmin_with_the_permission_switches_it_and_it_is_audited_and_cleared(): void
    {
        $this->sync($this->dev, 'virtual_machine')->assertOk();
        $staff = User::factory()->superadmin(['organizations.detection.manage'])->create();

        $this->toggle($staff, $this->dev, false)->assertOk()->assertJsonPath('detectionEnabled', false);

        $this->assertFalse($this->dev->fresh()->detection_enabled);
        $this->assertNull(DB::table('employee_statuses')->where('user_id', $this->dev->id)->value('environment'));
        $this->assertNull(DB::table('daily_summaries')->where('user_id', $this->dev->id)->value('environment'));
        $entry = AuditLog::withoutGlobalScopes()->where('action', 'detection.toggled')->firstOrFail();
        $this->assertSame($this->dev->id, $entry->target_user_id);
        $this->assertSame($this->admin->organization_id, $entry->organization_id);
        $this->assertFalse($entry->details['enabled']);

        $this->toggle($staff, $this->dev, true)->assertOk()->assertJsonPath('detectionEnabled', true);
        $this->assertTrue($this->dev->fresh()->detection_enabled);
    }

    public function test_the_switch_needs_its_own_permission_and_is_closed_to_organization_people(): void
    {
        $viewOnly = User::factory()->superadmin(['organizations.data.view', 'organizations.data.manage'])->create();
        $this->toggle($viewOnly, $this->dev, false)->assertForbidden();

        $this->toggle($this->admin, $this->dev, false)->assertForbidden();
        $this->assertTrue($this->dev->fresh()->detection_enabled);
    }

    public function test_another_organizations_person_and_superadmins_are_not_found(): void
    {
        $other = OrganizationFactory::made('Other Office');
        $stranger = User::factory()->adminOf($other)->create();
        $staff = User::factory()->superadmin(['organizations.detection.manage'])->create();

        // the person is in the other organization, but the URL names this one
        auth()->forgetGuards();
        $this->actingAs($staff, 'sanctum')
            ->patchJson("/api/v1/platform/organizations/{$this->admin->organization_id}/people/{$stranger->id}/detection", ['enabled' => false])
            ->assertNotFound();
        $this->assertTrue($stranger->fresh()->detection_enabled);

        $owner = User::factory()->superadmin(null, true)->create();
        auth()->forgetGuards();
        $this->actingAs($staff, 'sanctum')
            ->patchJson("/api/v1/platform/organizations/{$this->admin->organization_id}/people/{$owner->id}/detection", ['enabled' => false])
            ->assertNotFound();
    }
}
