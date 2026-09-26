<?php

namespace Tests\Feature;

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
    }

    public function test_a_manager_can_sign_in_and_then_read_their_own_profile_by_cookie(): void
    {
        $oic = User::factory()->oic()->create(['password' => bcrypt('secret-pass')]);

        $this->postJson('/auth/login', ['email' => $oic->email, 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('role.name', 'Admin');

        $this->getJson('/api/v1/me', ['Origin' => 'http://localhost:3100', 'Referer' => 'http://localhost:3100/'])
            ->assertOk()
            ->assertJsonPath('id', (string) $oic->id);
    }

    public function test_wrong_password_is_refused(): void
    {
        $oic = User::factory()->oic()->create(['password' => bcrypt('secret-pass')]);

        $this->postJson('/auth/login', ['email' => $oic->email, 'password' => 'nope'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'WRONG_PASSWORD');
    }

    public function test_everyone_can_sign_in_and_what_they_see_follows_their_role(): void
    {
        $dev = User::factory()->create(['password' => bcrypt('secret-pass')]);

        $this->postJson('/auth/login', ['email' => $dev->email, 'password' => 'secret-pass'])
            ->assertOk()
            ->assertJsonPath('role.name', 'Developer')
            ->assertJsonPath('permissions', [])
            ->assertJsonPath('scope', 'self');

        $this->assertAuthenticated('web');
    }

    public function test_someone_of_a_suspended_organization_cannot_sign_in(): void
    {
        $dev = User::factory()->create(['password' => bcrypt('secret-pass')]);
        $dev->organization()->update(['status' => 'suspended']);

        $this->postJson('/auth/login', ['email' => $dev->email, 'password' => 'secret-pass'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ORG_SUSPENDED');

        $this->assertGuest('web');
    }

    public function test_a_deactivated_manager_cannot_sign_in(): void
    {
        $oic = User::factory()->oic()->create(['password' => bcrypt('secret-pass')]);
        $pm = User::factory()->projectManager($oic)->deactivated()->create(['password' => bcrypt('secret-pass')]);

        $this->postJson('/auth/login', ['email' => $pm->email, 'password' => 'secret-pass'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_DEACTIVATED');
    }

    public function test_logout_ends_the_session(): void
    {
        $oic = User::factory()->oic()->create();

        $this->actingAs($oic, 'web')->postJson('/auth/logout')->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_a_signed_out_request_that_does_not_ask_for_json_still_gets_401(): void
    {
        // found by the browser tests: it used to fail with a 500 on the missing "login" route
        $this->get('/api/v1/employees')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
