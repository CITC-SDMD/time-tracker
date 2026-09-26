<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;
use Database\Factories\OrganizationFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// docs/SECURITY_REVIEW.md: the PCs a person is signed in on, signing one out, and the desktop token that lasts a week
// from its last use.
class DeviceTest extends TestCase
{
    use RefreshDatabase;

    private function signIn(User $user, string $deviceId): string
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('X-Device-Id', $deviceId)
            ->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct-password'])->json('token');
    }

    private function device(User $user, string $id, string $name): void
    {
        Device::unguarded(fn () => Device::create([
            'id' => $id, 'organization_id' => $user->organization_id, 'user_id' => $user->id, 'computer_name' => $name,
            'agent_version' => '0.2.0', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]));
    }

    public function test_a_person_sees_only_their_own_pcs_and_can_sign_one_out(): void
    {
        $me = User::factory()->oic()->create(['password' => bcrypt('correct-password')]);
        $other = User::factory()->individualContributor($me)->create(['password' => bcrypt('correct-password')]);
        [$laptop, $desk] = [(string) Str::uuid(), (string) Str::uuid()];
        $this->device($me, $laptop, 'LAPTOP-1');
        $this->signIn($me, $laptop);
        $desktopToken = $this->signIn($me, $desk);
        $this->signIn($other, (string) Str::uuid());

        $this->app['auth']->forgetGuards();
        $list = $this->actingAs($me, 'sanctum')->getJson('/api/v1/me/devices')->assertOk()->json();
        $this->assertCount(2, $list);
        $this->assertContains('LAPTOP-1', array_column($list, 'computerName'));

        $this->actingAs($me, 'sanctum')->deleteJson("/api/v1/me/devices/{$laptop}")->assertOk();
        $this->actingAs($me, 'sanctum')->deleteJson("/api/v1/me/devices/{$laptop}")->assertStatus(404);
        $this->assertCount(1, $this->actingAs($me, 'sanctum')->getJson('/api/v1/me/devices')->json());
        $this->assertTrue(AuditLog::where('action', 'device.signed_out')->where('target_user_id', $me->id)->exists());

        // the other PC still works
        $this->app['auth']->forgetGuards();
        $this->withToken($desktopToken)->getJson('/api/v1/me')->assertOk();
    }

    public function test_the_signed_out_pc_gets_401_on_its_next_call(): void
    {
        $me = User::factory()->create(['password' => bcrypt('correct-password')]);
        $pc = (string) Str::uuid();
        $token = $this->signIn($me, $pc);
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->actingAs($me, 'sanctum')->deleteJson("/api/v1/me/devices/{$pc}")->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_a_manager_signs_out_a_pc_in_reach_but_not_one_outside_it_or_in_another_office(): void
    {
        $admin = User::factory()->oic()->create();
        $inReach = User::factory()->individualContributor($admin)->create(['password' => bcrypt('correct-password')]);
        $foreign = User::factory()->adminOf(OrganizationFactory::made('Office B'))->create(['password' => bcrypt('correct-password')]);
        $pc = (string) Str::uuid();
        $this->signIn($inReach, $pc);
        $foreignPc = (string) Str::uuid();
        $this->signIn($foreign, $foreignPc);

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/employees/{$inReach->id}/devices")->assertOk()->assertJsonCount(1);
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/employees/{$inReach->id}/devices/{$pc}")->assertOk();
        $this->assertSame(0, $inReach->tokens()->count());
        $this->assertTrue(AuditLog::where('action', 'device.signed_out')->where('actor_user_id', $admin->id)->exists());

        // another office's person does not exist for this admin
        $this->actingAs($admin, 'sanctum')->deleteJson("/api/v1/admin/employees/{$foreign->id}/devices/{$foreignPc}")->assertStatus(404);
        $this->assertSame(1, $foreign->tokens()->count());
    }

    public function test_someone_without_the_permission_cannot_sign_out_a_colleague(): void
    {
        $boss = User::factory()->oic()->create();
        $a = User::factory()->individualContributor($boss)->create();
        $b = User::factory()->individualContributor($boss)->create(['password' => bcrypt('correct-password')]);
        $pc = (string) Str::uuid();
        $this->signIn($b, $pc);

        $this->actingAs($a, 'sanctum')->deleteJson("/api/v1/admin/employees/{$b->id}/devices/{$pc}")->assertStatus(403);
        $this->assertSame(1, $b->tokens()->count());
    }

    public function test_a_desktop_token_lasts_a_week_from_its_last_use_not_from_signing_in(): void
    {
        $me = User::factory()->create(['password' => bcrypt('correct-password')]);
        $token = $this->signIn($me, (string) Str::uuid());
        $days = config('sanctum.agent_token_days');
        $this->assertSame(7, $days);

        // used on day 3, so it now runs to day 10: still valid on day 7; then a week without use ends it
        $this->travel(3)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->travel(4)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk(); // day 7, but the use on day 3 moved it forward
        $this->travel(8)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401); // a week without use
    }
}
