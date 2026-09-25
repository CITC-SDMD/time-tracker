<?php

namespace Tests\Feature;

use App\Models\OfficeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §9.2, §10: POST /auth/login, GET /me, POST /me/consent.
class AuthTest extends TestCase
{
    use RefreshDatabase;

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
    }

    public function test_login_with_correct_password_returns_a_token_and_me(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'me' => ['id', 'name', 'email', 'role', 'consentRequired', 'officeSettings']]);
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)->assertJsonPath('error.code', 'WRONG_PASSWORD');
    }

    public function test_login_for_unknown_email_gives_the_same_error_as_wrong_password(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(401)->assertJsonPath('error.code', 'WRONG_PASSWORD');
    }

    public function test_login_for_deactivated_user_is_rejected(): void
    {
        $user = User::factory()->deactivated()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_DEACTIVATED');
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/v1/me')->assertStatus(401);
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->oic()->create();

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/v1/me');

        $response->assertOk()
            ->assertJsonPath('id', (string) $user->id)
            ->assertJsonPath('role', 'oic')
            ->assertJsonPath('consentRequired', true); // consent_version starts null
    }

    public function test_accepting_consent_clears_consent_required(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/me/consent', ['consentVersion' => 1]);

        $response->assertOk()->assertJsonPath('consentRequired', false);
        $this->assertSame(1, $user->fresh()->consent_version);
        $this->assertNotNull($user->fresh()->consent_accepted_at);
    }

    public function test_a_token_stops_working_the_moment_the_account_is_deactivated(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('agent-test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')->assertOk();

        $user->status = 'inactive'; // explicit — status isn't Fillable, see User.php
        $user->save();

        // Sanctum's guard caches the resolved user for the lifetime of the guard
        // instance, which this test's two ->getJson() calls otherwise share — without
        // this, the second call would see the pre-deactivation user from the first
        // call's cache instead of re-resolving from the (now-changed) database.
        auth()->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_DEACTIVATED');
    }
}
