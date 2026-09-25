<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\OfficeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// PATCH /me and PUT /me/password (docs/DEVELOPMENT_PLAN.md §10): a signed-in person edits their own
// name and changes their own password. Factory users all have the password "password".
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $oic;

    private User $pm;

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
        $this->pm = User::factory()->projectManager($this->oic)->create(['name' => 'Pat']);
    }

    public function test_me_carries_the_name_of_the_manager(): void
    {
        $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/me')->assertOk()->assertJsonPath('managerName', 'Olive');
        $this->actingAs($this->oic, 'sanctum')->getJson('/api/v1/me')->assertOk()->assertJsonPath('managerName', null);
    }

    public function test_a_person_can_change_their_own_name_but_nothing_else(): void
    {
        $this->actingAs($this->pm, 'sanctum')
            ->patchJson('/api/v1/me', ['name' => '  Patricia  ', 'email' => 'other@example.com', 'role' => 'oic', 'managerId' => $this->pm->id])
            ->assertOk()
            ->assertJsonPath('name', 'Patricia')
            ->assertJsonPath('role', 'project_manager');

        $fresh = $this->pm->fresh();
        $this->assertSame('Patricia', $fresh->name);
        $this->assertSame('project_manager', $fresh->role);
        $this->assertSame($this->oic->id, $fresh->manager_id);
        $this->assertNotSame('other@example.com', $fresh->email);
    }

    public function test_a_name_is_required(): void
    {
        $this->actingAs($this->pm, 'sanctum')->patchJson('/api/v1/me', ['name' => ''])->assertStatus(422);
        $this->actingAs($this->pm, 'sanctum')->patchJson('/api/v1/me', ['name' => str_repeat('a', 256)])->assertStatus(422);
    }

    public function test_changing_the_password_works_signs_out_the_desktop_and_is_recorded(): void
    {
        $agentToken = $this->pm->createToken('agent-device-1', ['agent']);

        $this->actingAs($this->pm, 'sanctum')->putJson('/api/v1/me/password', [
            'currentPassword' => 'password', 'password' => 'a-brand-new-one-1', 'password_confirmation' => 'a-brand-new-one-1',
        ])->assertOk();

        $this->assertSame(0, $this->pm->tokens()->count());
        $this->assertNotNull($agentToken); // it existed, and is gone now
        $this->postJson('/auth/login', ['email' => $this->pm->email, 'password' => 'password'])->assertStatus(401);
        $this->postJson('/auth/login', ['email' => $this->pm->email, 'password' => 'a-brand-new-one-1'])->assertOk();

        $entry = AuditLog::where('action', 'password.changed')->firstOrFail();
        $this->assertSame($this->pm->id, $entry->actor_user_id);
        $this->assertNull($entry->details);
    }

    public function test_the_current_password_must_be_right(): void
    {
        $this->actingAs($this->pm, 'sanctum')->putJson('/api/v1/me/password', [
            'currentPassword' => 'wrong', 'password' => 'a-brand-new-one-1', 'password_confirmation' => 'a-brand-new-one-1',
        ])->assertStatus(422)->assertJsonPath('error.code', 'WRONG_PASSWORD');

        $this->assertTrue(password_verify('password', $this->pm->fresh()->password));
        $this->assertSame(0, AuditLog::where('action', 'password.changed')->count());
    }

    public function test_a_weak_unconfirmed_or_unchanged_password_is_refused(): void
    {
        $call = fn (array $body) => $this->actingAs($this->pm, 'sanctum')->putJson('/api/v1/me/password', ['currentPassword' => 'password'] + $body);

        $call(['password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $call(['password' => 'a-brand-new-one-1', 'password_confirmation' => 'different-one-22'])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $call(['password' => 'password', 'password_confirmation' => 'password'])->assertStatus(422);
        $call([])->assertStatus(422);
    }

    public function test_repeated_wrong_guesses_are_slowed_down(): void
    {
        $this->actingAs($this->pm, 'sanctum');
        $body = ['currentPassword' => 'wrong', 'password' => 'a-brand-new-one-1', 'password_confirmation' => 'a-brand-new-one-1'];

        foreach (range(1, 5) as $_) {
            $this->putJson('/api/v1/me/password', $body)->assertStatus(422);
        }
        $this->putJson('/api/v1/me/password', $body)->assertStatus(429);
    }

    public function test_it_needs_a_signed_in_person(): void
    {
        $this->patchJson('/api/v1/me', ['name' => 'X'])->assertUnauthorized();
        $this->putJson('/api/v1/me/password', [])->assertUnauthorized();
    }
}
