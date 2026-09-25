<?php

namespace Tests\Feature;

use App\Models\OfficeSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/DEVELOPMENT_PLAN.md §9.2: the dashboard's cookie login (POST /login, /logout).
class DashboardAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        OfficeSetting::create([
            'id' => 1,
            'timezone' => 'Asia/Manila',
            'idle_threshold_seconds' => 300,
            'window_title_mode' => 'FULL',
            'min_agent_version' => '0.1.0',
            'consent_version' => 1,
        ]);
    }

    public function test_a_manager_can_sign_in_and_then_read_their_own_profile_by_cookie(): void
    {
        $oic = User::factory()->oic()->create(['password' => bcrypt('secret-pass')]);

        $this->postJson('/login', ['email' => $oic->email, 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('role', 'OIC');

        $this->getJson('/api/v1/me', ['Origin' => 'http://localhost:3100', 'Referer' => 'http://localhost:3100/'])
            ->assertOk()
            ->assertJsonPath('id', (string) $oic->id);
    }

    public function test_wrong_password_is_refused(): void
    {
        $oic = User::factory()->oic()->create(['password' => bcrypt('secret-pass')]);

        $this->postJson('/login', ['email' => $oic->email, 'password' => 'nope'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'WRONG_PASSWORD');
    }

    public function test_an_individual_contributor_gets_the_managers_only_message(): void
    {
        $dev = User::factory()->create(['password' => bcrypt('secret-pass')]);

        $this->postJson('/login', ['email' => $dev->email, 'password' => 'secret-pass'])
            ->assertForbidden()
            ->assertJsonPath('error.message', 'This dashboard is for managers only.');

        $this->assertGuest('web');
    }

    public function test_a_deactivated_manager_cannot_sign_in(): void
    {
        $oic = User::factory()->oic()->create(['password' => bcrypt('secret-pass')]);
        $pm = User::factory()->projectManager($oic)->deactivated()->create(['password' => bcrypt('secret-pass')]);

        $this->postJson('/login', ['email' => $pm->email, 'password' => 'secret-pass'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_DEACTIVATED');
    }

    public function test_logout_ends_the_session(): void
    {
        $oic = User::factory()->oic()->create();

        $this->actingAs($oic, 'web')->postJson('/logout')->assertNoContent();

        $this->assertGuest('web');
    }
}
